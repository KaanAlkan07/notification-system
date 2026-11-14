const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { performance } = require('node:perf_hooks');
const { setTimeout: wait } = require('node:timers/promises');
const { appendFileSync, writeFileSync } = require('node:fs');
const { channels, modes, batchSize, total, modeFor, itemFor, messageIdFor, outcomes } = require('./model.cjs');

const api = 'http://app:8080';
const provider = 'http://provider:8080';
const runId = randomUUID();
const clients = Number(process.env.BENCH_CLIENTS || 4);
const watermark = Number(process.env.BENCH_WATERMARK || 18000);
const workerCount = Number(process.env.BENCH_WORKERS || 10);
const batchCount = Math.ceil(total / batchSize);
const started = performance.now();
const deadline = started + (total === 1000000 ? 14400000 : 480000);
const batches = [];
let nextBatch = 0, submitted = 0, issued = 0, transportRetries = 0;
let latest, baseline, ingestFinishedAt, retryingObserved = false, idempotentReplays = 0, monitorError;
let monitoring = true, monitorTask;

function log(extra) {
    const record = JSON.stringify({ at:new Date().toISOString(), elapsedSeconds:Math.round((performance.now() - started) / 1000), ...extra });
    console.log(record);
    appendFileSync('/results/progress.jsonl', record + '\n');
}
async function request(url, { status = 200, ...options } = {}) {
    const response = await fetch(url, { signal:AbortSignal.timeout(25000), ...options });
    const body = await response.text();
    assert.equal(response.status, status, url + ': ' + body.slice(0,600));
    return JSON.parse(body);
}
async function submitBatch(batch, replay = false) {
    const count = Math.min(batchSize, total - batch * batchSize);
    const body = JSON.stringify({ notifications: Array.from({length: count}, (_, offset) => itemFor(batch * batchSize + offset)) });
    const start = performance.now();
    for (let attempt = 0; ; attempt++) {
        try {
            const { data } = await request(api + '/api/v1/notification-batches', {
                method:'POST', status:202, body,
                headers:{'Content-Type':'application/json','Idempotency-Key':runId + '-' + batch},
            });
            assert.equal(data.count, count);
            if (!replay) {
                batches[batch] = { batch,id:data.id,ms:performance.now() - start };
                submitted += count;
            }
            return data.id;
        } catch (error) {
            if (attempt >= 3 || error instanceof assert.AssertionError) throw error;
            transportRetries++;
            await wait(500 * (attempt + 1));
        }
    }
}
async function submitLane() {
    while (nextBatch < batchCount) {
        if (monitorError) throw monitorError;
        if (performance.now() > deadline) throw new Error('Ingestion deadline');
        if (issued - latest.unique >= watermark) {
            await wait(200);
            continue;
        }
        const batch = nextBatch++;
        issued += Math.min(batchSize, total - batch * batchSize);
        await submitBatch(batch);
    }
}
async function monitor() {
    let lastLog = started;
    let lastUnique = 0;
    let firstFaultProbe = 0;
    let failedPolls = 0;
    while (monitoring) {
        await wait(2000);
        if (!monitoring) break;
        try {
            latest = await request(provider + '/stats');
            assert.equal(latest.startId, baseline.startId, 'Provider process restarted');
            if (!retryingObserved && latest.faults.length && ++firstFaultProbe <= 40) {
                const keys = latest.faults.filter(f => f.mode === '503_always').slice(0,3);
                for (const fault of keys) {
                    const { data } = await request(api + '/api/v1/notifications/' + fault.key);
                    retryingObserved ||= data.status === 'retrying';
                }
            }
            if (performance.now() - lastLog >= 20000) {
                const now = performance.now();
                log({phase:'progress', submitted, providerUnique:latest.unique, buffered:submitted-latest.unique,
                    perSecond: Math.round((latest.unique-lastUnique)*1000/(now-lastLog)),
                    channels:latest.channels, observedFaults:latest.faults.length,
                    payloadErrors:latest.errors, extraAttempts:latest.extraAttempts,
                });
                lastLog = now;
                lastUnique = latest.unique;
            }
            failedPolls = 0;
        } catch (error) {
            if (error instanceof assert.AssertionError || ++failedPolls > 6) throw error;
        }
    }
}
async function verifyTimings(latest) {
    const response = await fetch(provider + '/timings', {signal:AbortSignal.timeout(60000)});
    assert.equal(response.status,200);
    const bytes = Buffer.from(await response.arrayBuffer());
    writeFileSync('/results/provider-timings.bin', bytes);
    let offset = 12;
    const peaks = {};
    let overLimitWindows = 0;
    for (const [i, channel] of channels.entries()) {
        const count = bytes.readUInt32LE(i * 4);
        assert.equal(count, latest.channels[channel].calls);
        const times = new Float64Array(count);
        for (let j = 0; j < count; j++, offset += 8) times[j] = bytes.readDoubleLE(offset);
        times.sort();
        let left = 0, max = 0, at = null, violations = 0;
        for (let right = 0; right < count; right++) {
            while (times[right] - times[left] >= 1000) left++;
            const size = right - left + 1;
            if (size > max) { max = size; at = times[right]; }
            if (size > 100) violations++;
        }
        peaks[channel] = { calls:count, maxRollingSecond:max, peakWindowEndMs:at, overLimitWindows:violations };
        overLimitWindows += violations;
    }
    assert.equal(offset, bytes.length);
    return { peaks, overLimitWindows };
}
async function verifyFaults(stats, final = false) {
    const checks = [];
    for (const f of stats.faults) {
        assert.equal(f.mode, modeFor(f.index));
        const expected = outcomes[f.mode];
        const { data } = await request(api + '/api/v1/notifications/' + f.key);
        if (!final && ['pending','processing','retrying'].includes(data.status)) return null;
        const duration = f.attempts.length > 1 ? f.attempts[1].time - f.attempts[0].time : null;
        checks.push({ index:f.index, channel:f.channel, mode:f.mode, key:f.key, status:data.status,
            failureCode:data.failure_code, providerMessageId:data.provider_message_id,
            apiAttempts:data.attempts, firstRetryDelayMs:duration, attempts:f.attempts,
        });
        if (final) {
            assert.deepEqual(f.attempts.map(a => a.status), expected.attempts);
            assert.equal(data.channel, f.channel);
            assert.equal(data.status, expected.status);
            assert.equal(data.failure_code, expected.failure);
            assert.equal(data.attempts, expected.attempts.length);
            assert.equal(data.provider_message_id, expected.status === 'accepted' ? messageIdFor(f.index) : null);
            if (f.mode.endsWith('_then_ok')) assert(duration >= 7000, 'Retry-After 7s violated for ' + JSON.stringify(f));
            if (f.mode === '503_always') {
                for (let i = 1; i < f.attempts.length; i++) {
                    assert(f.attempts[i].time - f.attempts[i-1].time >= 1000 * 2**i, 'Retry backoff shorter than minimum');
                }
            }
        }
    }
    return checks;
}
async function main() {
    baseline = latest = await request(provider + '/stats');
    assert.equal(baseline.unique,0);
    assert.equal((await request(api + '/health')).status,'ok');
    log({phase:'start',runId,total,clients,workerCount,watermark,providerProcess:baseline.startId});
    monitorTask = monitor().catch(error => { monitorError = error; });
    await Promise.all(Array.from({length:clients}, submitLane));
    ingestFinishedAt = performance.now();
    assert.equal(submitted,total);
    assert.equal(new Set(batches.map(x=>x.id)).size,batchCount);
    const latencies = batches.map(x=>x.ms).sort((a,b)=>a-b);
    const p = quantile => latencies[Math.ceil(latencies.length * quantile)-1];
    log({phase:'ingestion-complete',batches:batchCount,submitted,providerUnique:latest.unique,
        p50BatchMs:p(.5),p95BatchMs:p(.95),p99BatchMs:p(.99),maxBatchMs:p(1),transportRetries});
    writeFileSync('/results/batches.json', JSON.stringify(batches));
    const replayBatches = [...new Set([0, Math.floor(batchCount/2), batchCount-1])];
    await Promise.all(replayBatches.map(async index => {
        assert.equal(await submitBatch(index,true), batches[index].id);
        idempotentReplays++;
    }));
    let ready = false;
    while (performance.now() < deadline) {
        if (monitorError) throw monitorError;
        if (latest.unique === total && latest.active === 0 && await verifyFaults(latest)) {
            const metrics = await (await fetch(api + '/metrics', {signal:AbortSignal.timeout(20000)})).text();
            if (channels.length === (metrics.match(/^notifications_queue_depth\{priority="[^"]+"\} 0$/gm) ?? []).length) {
                ready = true;
                break;
            }
        }
        await wait(2000);
    }
    monitoring = false;
    await monitorTask;
    assert(!monitorError, monitorError?.stack);
    latest = await request(provider + '/stats');
    writeFileSync('/results/provider.json',JSON.stringify(latest,null,2));
    const rate = await verifyTimings(latest);
    log({phase:'provider-final',...rate, unique:latest.unique, active:latest.active, errors:latest.errors,
        keyErrors:latest.keyErrors, duplicates:latest.duplicates, extraAttempts:latest.extraAttempts });
    assert(ready, 'Queue did not drain');
    assert.equal(latest.unique,total);
    assert.equal(latest.errors,0);
    assert.equal(latest.keyErrors,0);
    assert.equal(latest.duplicates,0);
    assert.equal(latest.extraAttempts,0);
    assert.equal(latest.startId,baseline.startId);
    assert(retryingObserved,'Retrying API status was not observed');
    const expected = Object.fromEntries(channels.map(c => [c,{unique:0,calls:0,providerAccepted:0,accepted:0,failed:0,uncertain:0}]));
    const expectedByMode = Object.fromEntries(modes.map(mode=>[mode,0]));
    for(let index=0; index<total; index++) {
        const row = expected[channels[index % 3]];
        row.unique++;
        const mode = modeFor(index);
        const result = outcomes[mode] || {attempts:[202],status:'accepted'};
        row[result.status]++;
        row.calls += result.attempts.length;
        row.providerAccepted += result.attempts.filter(code=>code===202).length;
        if (mode !== 'ok') expectedByMode[mode]++;
    }
    for (const channel of channels) {
        assert.equal(latest.channels[channel].unique,expected[channel].unique);
        assert.equal(latest.channels[channel].calls,expected[channel].calls);
        assert.equal(latest.channels[channel].accepted,expected[channel].providerAccepted);
    }
    const faults = await verifyFaults(latest, true);
    assert.deepEqual(Object.fromEntries(modes.map(mode => [mode,faults.filter(f=>f.mode===mode).length])),expectedByMode);
    const retryDelays = faults.filter(f=>f.mode.endsWith('_then_ok')).map(f=>f.firstRetryDelayMs);
    const summary = {runId,total,workerCount,clients,watermark,batchCount,idempotentReplays,retryingObserved,
        ingressSeconds:(ingestFinishedAt-started)/1000,elapsedSeconds:(performance.now()-started)/1000,
        providerSeconds:(latest.lastRequestAt-latest.firstRequestAt)/1000,transportRetries,
        expected, expectedByMode, rate, retryDelayMs:{min:Math.min(...retryDelays),max:Math.max(...retryDelays)},
        errors:latest.errors,keyErrors:latest.keyErrors,duplicates:latest.duplicates,extraAttempts:latest.extraAttempts,
    };
    writeFileSync('/results/summary.json',JSON.stringify(summary,null,2));
    writeFileSync('/results/fault-api-evidence.json',JSON.stringify(faults,null,2));
    assert.equal(rate.overLimitWindows,0,'Provider traffic exceeded 100 calls per rolling second');
    log({phase:'passed',...summary});
}
main().catch(error => { monitoring=false; log({phase:'failed',error:error.stack}); process.exitCode=1; });

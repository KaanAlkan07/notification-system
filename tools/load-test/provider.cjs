const { createServer } = require('node:http');
const { performance } = require('node:perf_hooks');
const { randomUUID } = require('node:crypto');
const { channels, total, modeFor, itemFor, messageIdFor } = require('./model.cjs');

const startId = randomUUID();
const arrivals = Object.fromEntries(channels.map(c => [c, []]));
const state = new Uint8Array(total);
const keys = new BigUint64Array(total * 2);
const accepted = new Uint8Array(total);
const tracked = new Map();
const stats = Object.fromEntries(channels.map(c => [c, { unique: 0, accepted: 0 }]));
let unique = 0, errors = 0, keyErrors = 0, duplicates = 0, extraAttempts = 0;
let firstRequestAt = null, lastRequestAt = null, active = 0;

const json = (res, val) => res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify(val));
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/health') return json(res, { status:'ok', startId });
    if (req.method === 'GET' && req.url === '/stats') {
        return json(res, { startId, unique, errors, keyErrors, duplicates, extraAttempts, active, firstRequestAt, lastRequestAt,
            channels: Object.fromEntries(channels.map(c => [c, {...stats[c], calls: arrivals[c].length}])),
            faults: [...tracked.values()],
        });
    }
    if (req.method === 'GET' && req.url === '/timings') {
        const header = Buffer.alloc(12);
        channels.forEach((c, i) => header.writeUInt32LE(arrivals[c].length, i * 4));
        const blocks = channels.map(c => Buffer.from(Float64Array.from(arrivals[c]).buffer));
        return res.writeHead(200, {'Content-Type':'application/octet-stream'}).end(Buffer.concat([header, ...blocks]));
    }
    if (req.method !== 'POST' || req.url !== '/notifications') return res.writeHead(404).end();

    const time = performance.now();
    firstRequestAt ??= time;
    lastRequestAt = time;
    active++;
    let body = '';
    req.setEncoding('utf8');
    req.on('data', chunk => body += chunk);
    req.on('error', () => { active--; errors++; });
    req.on('end', () => {
        active--;
        let payload;
        try { payload = JSON.parse(body); } catch { errors++; return res.writeHead(422).end(); }
        const { channel, to, content } = payload;
        if (Object.hasOwn(arrivals, channel)) arrivals[channel].push(time);
        const index = Number(/^Compose load sample (\d+)$/.exec(content)?.[1]);
        const key = req.headers['idempotency-key'];
        const correlation = req.headers['x-correlation-id'];
        if (!Number.isInteger(index) || index < 0 || index >= total || !uuid.test(key ?? '') || !uuid.test(correlation ?? '') || Object.keys(payload).length !== 3) {
            errors++;
            return res.writeHead(422).end();
        }
        const expected = itemFor(index);
        if (channel !== expected.channel || to !== expected.recipient || content !== expected.content) {
            errors++;
            return res.writeHead(422).end();
        }
        const compact = key.replaceAll('-', '');
        const upper = BigInt('0x' + compact.slice(0,16));
        const lower = BigInt('0x' + compact.slice(16));
        const first = state[index] === 0;
        if (first) {
            keys[index * 2] = upper;
            keys[index * 2 + 1] = lower;
            unique++;
            stats[channel].unique++;
        } else if (keys[index * 2] !== upper || keys[index * 2 + 1] !== lower) {
            keyErrors++;
        }
        const attempt = ++state[index];
        const mode = modeFor(index);
        let status = 202;
        if (mode === '429_then_ok' && first) status = 429;
        if ((mode === '503_then_ok' && first) || mode === '503_always') status = 503;
        if (mode === '422_always') status = 422;
        if (attempt > (mode === '503_always' ? 5 : mode.endsWith('_then_ok') ? 2 : 1)) extraAttempts++;
        if (status === 202) {
            if (accepted[index]++) duplicates++;
            stats[channel].accepted++;
        }
        if (mode !== 'ok') {
            const record = tracked.get(index) ?? { index, channel, key, mode, attempts: [] };
            record.attempts.push({ status, time });
            tracked.set(index, record);
        }
        if (mode === 'lost_reply') return req.socket.destroy();
        if (status !== 202) {
            if (mode.endsWith('_then_ok')) res.setHeader('Retry-After','7');
            return res.writeHead(status).end();
        }
        res.writeHead(202, {'Content-Type':'application/json'});
        res.end(JSON.stringify({ messageId: messageIdFor(index), status:'accepted', timestamp: new Date().toISOString() }));
    });
}).listen(8080, '0.0.0.0');

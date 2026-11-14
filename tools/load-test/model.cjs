const channels = ['sms', 'email', 'push'];
const modes = ['429_then_ok', '503_then_ok', '503_always', '422_always', 'lost_reply'];
const batchSize = 1000;
const total = Number(process.env.BENCH_TOTAL || 1000000);
const period = 16666;

function modeFor(i) {
    const slot = Math.floor(i / channels.length) % period;
    return modes[slot] || 'ok';
}
function itemFor(i) {
    const channel = channels[i % channels.length];
    const recipient = {
        sms: '+1555' + String(i).padStart(7, '0'),
        email: 'compose-load+' + i + '@example.test',
        push: 'compose-load-device-' + i,
    }[channel];
    return {
        channel, recipient, content: 'Compose load sample ' + i,
        priority: modeFor(i) === 'ok' ? ['high','normal','low'][Math.floor(i / 3) % 3] : 'high',
    };
}
function messageIdFor(i) {
    return '8a71a5ed-148a-4630-831c-' + i.toString(16).padStart(12, '0');
}
const outcomes = {
    '429_then_ok': { attempts: [429, 202], status: 'accepted', failure: null },
    '503_then_ok': { attempts: [503, 202], status: 'accepted', failure: null },
    '503_always': { attempts: [503, 503, 503, 503, 503], status: 'failed', failure: 'http_503' },
    '422_always': { attempts: [422], status: 'failed', failure: 'http_422' },
    'lost_reply': { attempts: [202], status: 'uncertain', failure: 'transport_error' },
};
module.exports = { channels, modes, batchSize, total, period, modeFor, itemFor, messageIdFor, outcomes };

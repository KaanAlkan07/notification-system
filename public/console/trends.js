const labels = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const hours = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC' });
const counts = new Intl.NumberFormat('tr-TR');
const percentages = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 1 });
const series = [
    { key: 'sms', name: 'SMS', color: '#146b79', dash: [] },
    { key: 'email', name: 'E-posta', color: '#3a5ca2', dash: [7, 4] },
    { key: 'push', name: 'Anlık bildirim', color: '#8b591a', dash: [2, 4] },
];
const outcomes = ({ accepted, completed }) => completed ? accepted * 100 / completed : null;
const el = id => document.getElementById(id);

function tickLabel(bucket, hourly) {
    const start = new Date(bucket.start);
    return hourly ? hours.format(start) : labels.format(start);
}

function formatBucket(bucket, seconds) {
    const start = new Date(bucket.start);
    const end = new Date(start.getTime() + seconds * 1000);
    const day = labels.format(start);
    return seconds === 3600 ? day + ' ' + hours.format(start) + '–' + hours.format(end) : day;
}

function chart(canvas, buckets, seconds, index, rate) {
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (!width || !height) return;
    const pixels = window.devicePixelRatio || 1;
    canvas.width = Math.round(width * pixels);
    canvas.height = Math.round(height * pixels);
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.scale(pixels, pixels);
    ctx.font = '12px "Segoe UI", sans-serif';
    const largest = Math.max(0, ...buckets.flatMap(bucket => series.map(({ key }) => bucket.channels[key].completed)));
    const step = rate ? 25 : Math.max(1, Math.ceil(largest / 4));
    const max = step * 4;
    const left = Math.ceil(ctx.measureText(rate ? '100%' : counts.format(max)).width) + 10;
    const right = width - 20;
    const top = 14;
    const bottom = height - 27;
    const x = i => left + i * (right - left) / (buckets.length - 1);
    const y = value => bottom - value / max * (bottom - top);
    ctx.fillStyle = '#526167';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    for (let value = 0; value <= max; value += step) {
        ctx.strokeStyle = '#e4ebeb';
        ctx.beginPath();
        ctx.moveTo(left, y(value));
        ctx.lineTo(right, y(value));
        ctx.stroke();
        ctx.fillText(rate ? value + '%' : counts.format(value), left - 6, y(value));
    }
    ctx.textAlign = 'center';
    const ticks = Math.min(width < 360 ? 4 : 7, buckets.length);
    for (let i = 0; i < ticks; i++) {
        const point = Math.round(i * (buckets.length - 1) / (ticks - 1));
        ctx.fillText(tickLabel(buckets[point], seconds === 3600), x(point), bottom + 15);
    }
    ctx.strokeStyle = '#a8b6b8';
    ctx.beginPath();
    ctx.moveTo(x(index), top);
    ctx.lineTo(x(index), bottom);
    ctx.stroke();

    const selectedDots = [];
    for (const { key, color, dash } of series) {
        const values = buckets.map(bucket => rate ? outcomes(bucket.channels[key]) : bucket.channels[key].completed);
        ctx.lineWidth = 2;
        ctx.strokeStyle = color;
        ctx.setLineDash(dash);
        ctx.beginPath();
        values.forEach((value, i) => {
            if (value === null) return;
            if (i === 0 || values[i - 1] === null) ctx.moveTo(x(i), y(value));
            else ctx.lineTo(x(i), y(value));
        });
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = color;
        if (rate) {
            values.forEach((value, i) => {
                if (value === null) return;
                ctx.beginPath();
                ctx.arc(x(i), y(value), 2.5, 0, Math.PI * 2);
                ctx.fill();
            });
        }
        if (values[index] !== null) selectedDots.push({ color, value: values[index] });
    }
    for (const { color, value } of selectedDots) {
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(x(index), y(value), 3.5, 0, Math.PI * 2);
        ctx.fill();
    }
    if (rate && largest === 0) {
        ctx.fillStyle = '#526167';
        ctx.fillText('Bu dönemde sonuçlanan bildirim yok', (left + right) / 2, (top + bottom) / 2, right - left - 10);
    }
}

export function setupTrends(request) {
    const range = el('trend-range');
    const bucketSelect = el('trend-bucket');
    const data = el('trend-data');
    const message = el('trend-message');
    const volume = el('trend-volume');
    const rate = el('trend-rate');
    let result;
    let lastRange;
    let selected = 0;
    let requestVersion = 0;
    let fetchedAt = 0;
    let busy = false;

    el('trend-key').replaceChildren(...series.map(({ name, color }) => {
        const item = document.createElement('li');
        item.style.setProperty('--series-color', color);
        const swatch = document.createElement('i');
        swatch.setAttribute('aria-hidden', 'true');
        item.append(swatch, name);
        return item;
    }));

    function paintCharts() {
        if (!result || data.hidden) return;
        chart(volume, result.buckets, result.bucket_seconds, selected, false);
        chart(rate, result.buckets, result.bucket_seconds, selected, true);
    }

    function showBucket() {
        const bucket = result.buckets[selected];
        el('trend-values').replaceChildren(...series.map(({ key, name }) => {
            const row = document.createElement('tr');
            const label = document.createElement('th');
            label.scope = 'row';
            label.textContent = name;
            row.append(label);
            const { completed, accepted } = bucket.channels[key];
            row.insertCell().textContent = counts.format(completed);
            row.insertCell().textContent = counts.format(accepted);
            const acceptance = outcomes({ accepted, completed });
            row.insertCell().textContent = acceptance === null ? '—' : '%' + percentages.format(acceptance);
            return row;
        }));
        paintCharts();
    }

    async function load(force = false) {
        if (!force && (busy || Date.now() - fetchedAt < 30_000)) return;
        const version = ++requestVersion;
        const requestedRange = range.value;
        busy = true;
        if (!result || requestedRange !== lastRange) {
            data.hidden = true;
            message.hidden = false;
            message.textContent = 'Grafik verileri yükleniyor…';
        }
        try {
            const response = await request('/api/v1/notification-trends?range=' + requestedRange);
            const received = await response.json();
            if (version !== requestVersion) return;
            const previousBucket = lastRange === requestedRange ? result?.buckets[selected]?.start : null;
            result = received;
            lastRange = requestedRange;
            fetchedAt = Date.now();
            selected = result.buckets.findIndex(bucket => bucket.start === previousBucket);
            if (selected === -1) {
                const latestActivity = result.buckets.findLastIndex(bucket => series.some(({ key }) => bucket.channels[key].completed));
                selected = latestActivity === -1 ? result.buckets.length - 1 : latestActivity;
            }
            bucketSelect.replaceChildren(...result.buckets.map((bucket, i) => new Option(formatBucket(bucket, result.bucket_seconds), i)));
            bucketSelect.value = selected;
            message.hidden = true;
            data.hidden = false;
            showBucket();
        } catch {
            if (version !== requestVersion) return;
            message.textContent = 'Grafik verileri alınamadı. Yenile ile tekrar dene.';
            message.hidden = false;
            data.hidden = true;
        } finally {
            if (version === requestVersion) busy = false;
        }
    }

    range.addEventListener('change', () => void load(true));
    bucketSelect.addEventListener('change', () => {
        selected = Number(bucketSelect.value);
        showBucket();
    });
    const observer = new ResizeObserver(paintCharts);
    observer.observe(volume);
    observer.observe(rate);
    return load;
}

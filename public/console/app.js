import { setupTrends } from './trends.js';

const $ = (id) => document.getElementById(id);
const api = '/api/v1/notifications';
const channels = {
    sms: { label: 'SMS', type: 'tel', sample: '+15550102030', hint: 'Ülke koduyla birlikte yaz.', limit: 160 },
    email: { label: 'E-posta', type: 'email', sample: 'demo@example.test', hint: 'Alıcının e-posta adresi.', limit: 10000 },
    push: { label: 'Anlık bildirim', type: 'text', sample: 'demo-cihaz-42', hint: 'Cihazın bildirim anahtarı.', limit: 240 },
};
const states = {
    pending: 'Bekliyor', processing: 'İşleniyor', retrying: 'Tekrar denenecek',
    accepted: 'Kabul edildi', failed: 'Başarısız', uncertain: 'Belirsiz', cancelled: 'İptal edildi',
    awaiting_receipt: 'Teslimat onayı bekleniyor', sent: 'Operatöre iletildi',
    delivered: 'Teslim edildi', undelivered: 'Teslim edilemedi',
};
const priorities = { high: 'Yüksek', normal: 'Normal', low: 'Düşük' };
const channelIcons = {
    sms: 'M4 5h16v11H9l-5 4zM8 9h8M8 12h5',
    email: 'M4 6h16v12H4zM4 6l8 7 8-7',
    push: 'M6 17h12l-2-3v-3a4 4 0 0 0-8 0v3zM10 20h4',
};
const date = new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeStyle: 'short' });
const time = new Intl.DateTimeFormat('tr-TR', { timeStyle: 'medium' });
const numbers = new Intl.NumberFormat('tr-TR');
const compose = $('compose');
const recipient = $('recipient');
const content = $('content');
const dialog = $('detail');
let token = '';
let nextCursor = null;
let cursors = [null];
let filters = new URLSearchParams();
let listRequest = 0;
let detailRequest = 0;
let selected = null;
let retryGeneration = null;
let detailAction = false;
let displayedDetailState = null;
let previousSubmission;
let updating = false;

async function request(path, options = {}) {
    const headers = new Headers({ Accept: 'application/json', ...options.headers });
    if (token) headers.set('Authorization', 'Bearer ' + token);
    const response = await fetch(path, { ...options, headers, cache: 'no-store' });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const error = new Error(body.message || 'İstek tamamlanamadı.');
        error.status = response.status;
        error.fields = body.errors;
        throw error;
    }
    return response;
}

function notice(element, message, success = false) {
    element.textContent = message;
    element.hidden = !message;
    element.dataset.success = String(success);
}

function notificationStatus(state) {
    const badge = document.createElement('span');
    badge.className = 'status';
    badge.dataset.state = state;
    badge.textContent = states[state] ?? state;
    return badge;
}

function formatted(value) {
    return value ? date.format(new Date(value)) : '—';
}

function row(notification) {
    const tr = document.createElement('tr');
    const first = document.createElement('th');
    first.scope = 'row';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'row-button';
    button.dataset.notificationId = notification.id;
    button.textContent = channels[notification.channel]?.label ?? notification.channel;
    const id = document.createElement('span');
    id.className = 'identifier';
    id.textContent = notification.id.slice(0, 13);
    button.append(id);
    button.addEventListener('click', () => openDetail(notification.id));
    first.append(button);
    tr.append(first);
    tr.insertCell().append(notificationStatus(displayStatus(notification)));
    tr.insertCell().textContent = priorities[notification.priority] ?? notification.priority;
    tr.insertCell().textContent = formatted(notification.created_at);
    return tr;
}

function updateRows(container, notifications) {
    const activeId = container.contains(document.activeElement)
        ? document.activeElement.dataset.notificationId : null;
    container.replaceChildren(...notifications.map(row));
    if (activeId) {
        const match = [...container.querySelectorAll('button')].find(button => button.dataset.notificationId === activeId);
        (match ?? container.closest('.table-scroll')).focus({ preventScroll: true });
    }
}

async function loadList() {
    const version = ++listRequest;
    const query = new URLSearchParams(filters);
    query.set('per_page', '12');
    if (cursors.at(-1)) query.set('cursor', cursors.at(-1));
    try {
        const result = await (await request(api + '?' + query)).json();
        if (version !== listRequest) return;
        nextCursor = result.next_cursor;
        updateRows($('rows'), result.data);
        $('empty').hidden = result.data.length > 0;
        $('empty').textContent = filters.size
            ? 'Bu filtrelere uyan bildirim yok.'
            : 'Henüz bildirim yok. Başlamak için yeni bir bildirim oluştur.';
        $('freshness').textContent = 'Son güncelleme ' + time.format(new Date());
        $('result-count').textContent = numbers.format(result.data.length) + ' bildirim bu sayfada';
        $('pager').hidden = !nextCursor && cursors.length === 1;
        $('previous').disabled = cursors.length === 1;
        $('next').disabled = !nextCursor;
        $('page-number').textContent = String(cursors.length);
        notice($('connection-notice'), '');
    } catch (error) {
        if (version !== listRequest) return;
        $('rows').replaceChildren();
        $('empty').hidden = true;
        $('pager').hidden = true;
        $('freshness').textContent = 'Geçmiş güncellenemedi';
        $('result-count').textContent = '';
        if (error.status === 401) $('access').open = true;
        notice($('connection-notice'), error.status === 401
            ? 'Bildirimleri görmek için API anahtarını gir.'
            : 'Bildirimlere erişilemiyor. Bağlantıyı kontrol edip yeniden dene.');
    }
}

async function loadActivity() {
    try {
        const { data } = await (await request(api + '?per_page=5')).json();
        updateRows($('activity-rows'), data);
        $('activity-table').hidden = data.length === 0;
        $('activity-empty').hidden = data.length > 0;
        $('activity-empty').textContent = 'Henüz bildirim yok. Bir bildirim oluşturduğunda burada görünecek.';
    } catch {
        $('activity-table').hidden = true;
        $('activity-empty').hidden = false;
        $('activity-empty').textContent = 'Son bildirimler alınamadı.';
    }
}

function metricMap(text) {
    const metrics = new Map();
    for (const line of text.split('\n')) {
        const match = /^(notifications_[a-z0-9_]+)\{([^}]+)\}\s+(\S+)$/.exec(line);
        if (match) metrics.set(match[1] + ' ' + match[2], Number(match[3]));
    }
    return (name, labels) => {
        const value = metrics.get(name + ' ' + labels);
        return Number.isFinite(value) && value >= 0 ? value : null;
    };
}

function metricValue(value) {
    return value === null ? '—' : numbers.format(value);
}

function metricTotal(values) {
    return values.includes(null) ? null : values.reduce((sum, value) => sum + value, 0);
}

async function loadMetrics() {
    let metrics = '';
    try {
        metrics = await (await request('/metrics')).text();
    } catch {
        // An unavailable series is displayed as a dash.
    }
    const read = metricMap(metrics);
    const channelStats = Object.entries(channels).map(([key, { label }]) => {
        const outcomes = ['accepted', 'failed', 'uncertain'].map(outcome => read('notifications_outcomes_5m', 'channel="' + key + '",outcome="' + outcome + '"'));
        const latency = read('notifications_acceptance_latency_seconds_avg_5m', 'channel="' + key + '"');
        return { label, outcomes, latency };
    });
    $('channel-results').replaceChildren(...channelStats.map(({ label, outcomes, latency }) => {
        const tr = document.createElement('tr');
        const channel = document.createElement('th');
        channel.scope = 'row';
        channel.textContent = label;
        tr.append(channel);
        for (const value of outcomes) tr.insertCell().textContent = metricValue(value);
        tr.insertCell().textContent = latency === null ? '—' : metricValue(latency) + ' sn';
        return tr;
    }));
    const queueStats = Object.entries(priorities).map(([key, label]) => ({
        label, count: read('notifications_queue_depth', 'priority="' + key + '"'),
    }));
    const queueTotal = metricTotal(queueStats.map(({ count }) => count));
    $('queue-results').replaceChildren(...queueStats.map(({ label, count }) => {
        const item = document.createElement('li');
        const heading = document.createElement('span');
        const value = document.createElement('strong');
        heading.textContent = label;
        value.textContent = metricValue(count);
        item.append(heading, value);
        if (queueTotal > 0) {
            const meter = document.createElement('progress');
            meter.max = queueTotal;
            meter.value = count;
            meter.setAttribute('aria-label', label + ' öncelikli işlerin kuyruktaki payı');
            item.append(meter);
        }
        return item;
    }));
    $('queued').textContent = metricValue(queueTotal);
    $('accepted').textContent = metricValue(metricTotal(channelStats.map(({ outcomes }) => outcomes[0])));
    $('failed').textContent = metricValue(metricTotal(channelStats.flatMap(({ outcomes }) => outcomes.slice(1))));
}

async function loadHealth() {
    let healthy = false;
    try {
        healthy = (await (await request('/health')).json()).status === 'ok';
    } catch {
        // An HTTP failure is also an unhealthy connection.
    }
    $('health').dataset.state = healthy ? 'ok' : 'down';
    $('health').lastElementChild.textContent = healthy ? 'Sistem çalışıyor' : 'Bağlantı sorunu';
}

async function refresh(forceTrends = false) {
    if (updating) return;
    updating = true;
    try {
        await Promise.all([
            loadList(), loadActivity(), loadMetrics(), loadHealth(), refreshDetail(),
            $('overview-panel').hidden ? null : loadTrends(forceTrends),
        ]);
    } finally {
        updating = false;
    }
}

function currentChannel() {
    return channels[compose.elements.channel.value];
}

function updateCount() {
    $('count').textContent = Array.from(content.value).length + ' / ' + currentChannel().limit;
    content.setCustomValidity('');
    updatePreview();
}

function updatePreview() {
    const channel = compose.elements.channel.value;
    const scheduled = new Date($('scheduled_at').value);
    const hasMessage = content.value.trim().length > 0;
    $('preview-icon').setAttribute('d', channelIcons[channel]);
    $('preview-channel').textContent = channels[channel].label;
    $('preview-recipient').textContent = recipient.value.trim() || 'Alıcı girilmedi';
    $('preview-content').textContent = hasMessage ? content.value : 'Mesajını yazınca burada görünecek.';
    $('preview-content').dataset.empty = String(!hasMessage);
    $('preview-priority').textContent = priorities[$('priority').value];
    $('preview-schedule').textContent = Number.isNaN(scheduled.getTime()) ? 'Sıradaki uygun zamanda' : date.format(scheduled);
    $('preview-limit').textContent = $('count').textContent;
}

function updateChannel() {
    const channel = currentChannel();
    recipient.type = channel.type;
    recipient.placeholder = channel.sample;
    $('recipient-hint').textContent = channel.hint;
    recipient.removeAttribute('aria-invalid');
    updateCount();
}

function formatSubmitError(error) {
    const translated = {
        recipient: 'Alıcı bilgisi seçili kanal için geçersiz.',
        content: 'Mesaj boş olmamalı ve kanalın karakter sınırını aşmamalı.',
        scheduled_at: 'Geçerli bir gönderim tarihi ve saati gir.',
    };
    const input = Object.keys(error.fields ?? {})[0];
    if (input && compose.elements.namedItem(input) instanceof HTMLElement) {
        const element = compose.elements.namedItem(input);
        element.setAttribute('aria-invalid', 'true');
        element.focus();
    }
    if (error.status === 401) $('access').open = true;
    return translated[input] ?? (error.status === 401
        ? 'Bildirim göndermek için API anahtarını gir.'
        : 'Bildirim oluşturulamadı. Bağlantıyı kontrol edip yeniden dene.');
}

function readDraft() {
    const draft = Object.fromEntries(new FormData(compose));
    draft.recipient = draft.recipient.trim();
    if (draft.scheduled_at) draft.scheduled_at = new Date(draft.scheduled_at).toISOString();
    else delete draft.scheduled_at;
    return JSON.stringify(draft);
}

compose.addEventListener('submit', async (event) => {
    event.preventDefault();
    if ($('submit').disabled) return;
    const limit = currentChannel().limit;
    if (Array.from(content.value).length > limit || !content.value.trim()) {
        content.setCustomValidity('Boş olmayan ve en fazla ' + limit + ' karakterlik bir mesaj yaz.');
        content.reportValidity();
        return;
    }
    $('submit').disabled = true;
    $('submit').lastElementChild.textContent = 'Kuyruğa ekleniyor…';
    notice($('form-message'), '');
    try {
        const body = readDraft();
        if (previousSubmission?.body !== body) previousSubmission = { body, key: crypto.randomUUID() };
        const response = await request(api, {
            method: 'POST', body,
            headers: { 'Content-Type': 'application/json', 'Idempotency-Key': previousSubmission.key },
        });
        const { data } = await response.json();
        previousSubmission = undefined;
        const channel = compose.elements.channel.value;
        compose.reset();
        compose.elements.channel.value = channel;
        $('schedule').open = false;
        updateChannel();
        notice($('form-message'), 'Bildirim oluşturuldu.', true);
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'text-button';
        link.textContent = ' Durumu gör';
        link.addEventListener('click', () => openDetail(data.id));
        $('form-message').append(' ', link);
        await Promise.all([loadList(), loadActivity(), loadMetrics()]);
    } catch (error) {
        notice($('form-message'), formatSubmitError(error));
    } finally {
        $('submit').disabled = false;
        $('submit').lastElementChild.textContent = 'Kuyruğa ekle';
    }
});

function displayStatus(data) {
    return data.status === 'accepted' ? data.delivery_status ?? data.status : data.status;
}

function renderDetail(data) {
    retryGeneration = data.retry_generation;
    const state = displayStatus(data);
    if (state !== displayedDetailState) {
        $('detail-state').replaceChildren(notificationStatus(state));
        if (state === 'accepted') $('detail-state').append(' Sağlayıcı isteği kabul etti.');
        displayedDetailState = state;
    }
    const fields = [
        ['Bildirim kimliği', data.id], ['Kanal', channels[data.channel]?.label ?? data.channel],
        ['Öncelik', priorities[data.priority]], ['Deneme sayısı', data.attempts],
        ['Oluşturulma', formatted(data.created_at)], ['Planlanan zaman', formatted(data.scheduled_at)],
    ];
    if (data.next_attempt_at) fields.push(['Sonraki deneme', formatted(data.next_attempt_at)]);
    if (data.provider_message_id) fields.push(['Sağlayıcı kimliği', data.provider_message_id]);
    if (data.delivery_updated_at) fields.push(['Teslimat durumu güncellemesi', formatted(data.delivery_updated_at)]);
    if (data.delivery_error_code) fields.push(['Teslimat hata kodu', data.delivery_error_code]);
    if (data.failure_code) fields.push(['Hata kodu', data.failure_code]);
    $('detail-fields').replaceChildren(...fields.flatMap(([label, value]) => {
        const term = document.createElement('dt');
        const detail = document.createElement('dd');
        term.textContent = label;
        detail.textContent = value;
        return [term, detail];
    }));
    $('cancel').hidden = !['pending', 'retrying'].includes(data.status);
    $('retry').hidden = data.status !== 'failed';
}

async function refreshDetail() {
    if (!dialog.open || selected === null || detailAction) return;
    const version = ++detailRequest;
    try {
        const { data } = await (await request(api + '/' + selected)).json();
        if (version === detailRequest && dialog.open) renderDetail(data);
    } catch {
        if (version === detailRequest && dialog.open) {
            notice($('detail-message'), 'Bildirim ayrıntıları alınamadı.');
        }
    }
}

function openDetail(id) {
    selected = id;
    retryGeneration = null;
    displayedDetailState = null;
    $('detail-state').textContent = 'Bildirim yükleniyor…';
    $('detail-fields').replaceChildren();
    $('cancel').hidden = true;
    $('retry').hidden = true;
    notice($('detail-message'), '');
    if (!dialog.open) dialog.showModal();
    void refreshDetail();
}

async function changeDelivery(action, { body, success, conflict, failure }) {
    if (detailAction) return;
    const id = selected;
    const button = $(action);
    detailAction = true;
    ++detailRequest;
    button.disabled = true;
    notice($('detail-message'), '');
    try {
        const options = { method: 'POST' };
        if (body) {
            options.headers = { 'Content-Type': 'application/json' };
            options.body = JSON.stringify(body);
        }
        const { data } = await (await request(api + '/' + id + '/' + action, options)).json();
        if (dialog.open && id === selected) {
            renderDetail(data);
            notice($('detail-message'), success, true);
            if (button.hidden) $('detail-close').focus();
        }
        await Promise.all([loadList(), loadActivity(), loadMetrics()]);
    } catch (error) {
        if (dialog.open && id === selected) {
            notice($('detail-message'), error.status === 409 ? conflict : failure);
        }
    } finally {
        detailAction = false;
        button.disabled = false;
        void refreshDetail();
    }
}

$('cancel').addEventListener('click', () => changeDelivery('cancel', {
    success: 'Bildirim iptal edildi.',
    conflict: 'Bu bildirim artık iptal edilemez.',
    failure: 'İptal işlemi tamamlanamadı.',
}));

$('retry').addEventListener('click', () => changeDelivery('retry', {
    body: { retry_generation: retryGeneration },
    success: 'Bildirim yeniden kuyruğa alındı.',
    conflict: 'Bildirim durumu değişti. Güncel durumu kontrol et.',
    failure: 'Bildirim yeniden kuyruğa alınamadı.',
}));

$('filters').addEventListener('submit', (event) => {
    event.preventDefault();
    filters = new URLSearchParams([...new FormData(event.currentTarget)].filter(([, value]) => value));
    $('clear-filters').hidden = !filters.size;
    cursors = [null];
    void loadList();
});
$('clear-filters').addEventListener('click', () => {
    $('filters').reset();
    $('filters').requestSubmit();
    $('filters').querySelector('select').focus();
});
$('previous').addEventListener('click', () => {
    if (cursors.length > 1) {
        cursors.pop();
        void loadList();
    }
});
$('next').addEventListener('click', () => {
    if (nextCursor) {
        cursors.push(nextCursor);
        nextCursor = null;
        $('next').disabled = true;
        void loadList();
    }
});
$('access-form').addEventListener('submit', (event) => {
    event.preventDefault();
    token = $('token').value.trim();
    $('token').value = '';
    $('access').open = false;
    void Promise.all([loadList(), loadActivity(), loadMetrics()]);
});
$('refresh').addEventListener('click', () => void refresh(true));
$('sample').addEventListener('click', () => {
    recipient.value = currentChannel().sample;
    recipient.removeAttribute('aria-invalid');
    content.value = 'Bildirim Merkezi deneme mesajı.';
    content.removeAttribute('aria-invalid');
    updateCount();
    notice($('form-message'), '');
    recipient.focus();
});
compose.addEventListener('change', (event) => {
    if (event.target.name === 'channel') updateChannel();
    else updatePreview();
});
compose.addEventListener('input', (event) => {
    event.target.removeAttribute('aria-invalid');
    if (event.target === content) updateCount();
    else updatePreview();
});
const views = {
    main: { panel: 'overview-panel', description: 'Bildirim kuyruğunu ve son 5 dakikanın sonuçlarını izle.' },
    history: { panel: 'history-panel', description: 'Gönderimleri filtrele ve bir bildirimin ayrıntılarını aç.' },
    composer: { panel: 'composer-panel', description: 'SMS, e-posta veya anlık bildirim oluştur.' },
};
const viewLinks = [...$('workspace-nav').querySelectorAll('[data-view]')];
const loadTrends = setupTrends(request);

function showView(focusHeading = false) {
    const fragment = window.location.hash.slice(1);
    const current = Object.hasOwn(views, fragment) ? fragment : 'main';
    let title;
    for (const link of viewLinks) {
        const active = link.dataset.view === current;
        $(views[link.dataset.view].panel).hidden = !active;
        if (active) {
            link.setAttribute('aria-current', 'page');
            title = link.textContent.trim();
        } else {
            link.removeAttribute('aria-current');
        }
    }
    $('page-title').textContent = title;
    $('page-description').textContent = views[current].description;
    $('current-view').textContent = title;
    $('page-actions').hidden = current === 'composer';
    if (current === 'main') void loadTrends();
    document.title = title + ' · Bildirim Merkezi';
    if (focusHeading) {
        $('page-title').focus({ preventScroll: true });
        window.scrollTo(0, 0);
    }
}

window.addEventListener('hashchange', () => showView(true));
document.querySelector('.skip').addEventListener('click', (event) => {
    event.preventDefault();
    $('page-title').focus();
});
showView();
updateChannel();
$('submit').disabled = false;
$('connect').disabled = false;
void refresh();
setInterval(() => {
    if (!document.hidden) void refresh();
}, 3500);

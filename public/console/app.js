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

async function loadList() {
    const version = ++listRequest;
    const query = new URLSearchParams(filters);
    query.set('per_page', '12');
    if (cursors.at(-1)) query.set('cursor', cursors.at(-1));
    try {
        const result = await (await request(api + '?' + query)).json();
        if (version !== listRequest) return;
        nextCursor = result.next_cursor;
        const focused = $('rows').contains(document.activeElement);
        if (!focused) $('rows').replaceChildren(...result.data.map(row));
        $('empty').hidden = result.data.length > 0;
        $('empty').textContent = filters.size
            ? 'Bu filtrelere uyan bildirim yok.'
            : 'Henüz bildirim yok. Başlamak için yeni bir bildirim oluştur.';
        $('freshness').textContent = 'Son güncelleme ' + time.format(new Date());
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
        if (error.status === 401) $('access').open = true;
        notice($('connection-notice'), error.status === 401
            ? 'Bildirimleri görmek için API anahtarını gir.'
            : 'Bildirimlere erişilemiyor. Bağlantıyı kontrol edip yeniden dene.');
    }
}

async function loadMetrics() {
    let metrics = '';
    try {
        metrics = await (await request('/metrics')).text();
    } catch {
        // An unavailable series is displayed as a dash.
    }
    const values = { queued: [], accepted: [], failed: [] };
    for (const line of metrics.split('\n')) {
        const match = /^(notifications_queue_depth|notifications_outcomes_5m)\{([^}]+)\} (\d+)$/.exec(line);
        if (!match) continue;
        const key = match[1] === 'notifications_queue_depth' ? 'queued'
            : match[2].includes('outcome="accepted"') ? 'accepted' : 'failed';
        values[key].push(Number(match[3]));
    }
    for (const [key, series] of Object.entries(values)) {
        $(key).textContent = series.length ? numbers.format(series.reduce((sum, value) => sum + value, 0)) : '—';
    }
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

async function refresh() {
    if (updating) return;
    updating = true;
    try {
        await Promise.all([loadList(), loadMetrics(), loadHealth(), refreshDetail()]);
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
        await Promise.all([loadList(), loadMetrics()]);
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
        await Promise.all([loadList(), loadMetrics()]);
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
    cursors = [null];
    void loadList();
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
    void Promise.all([loadList(), loadMetrics()]);
});
$('refresh').addEventListener('click', refresh);
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
});
compose.addEventListener('input', (event) => {
    event.target.removeAttribute('aria-invalid');
    if (event.target === content) updateCount();
});
document.querySelector('nav').addEventListener('click', (event) => {
    const link = event.target.closest('a');
    if (!link) return;
    document.querySelector('nav [aria-current]')?.removeAttribute('aria-current');
    link.setAttribute('aria-current', 'location');
});
updateChannel();
$('submit').disabled = false;
$('connect').disabled = false;
void refresh();
setInterval(() => {
    if (!document.hidden) void refresh();
}, 3500);

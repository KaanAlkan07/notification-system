<?php

declare(strict_types=1);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && $path === '/health') {
    echo '{"status":"ok"}';

    return;
}

$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
if ($method === 'GET' && $path === '/status') {
    $find = receipts()->prepare('SELECT response FROM receipts WHERE delivery_key = ?');
    $find->execute([$key]);
    echo $find->fetchColumn() ?: '{"status":"unknown"}';

    return;
}

if ($path !== '/notifications') {
    http_response_code(404);
    echo '{"error":"not_found"}';

    return;
}

if ($method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '{"error":"method_not_allowed"}';

    return;
}

$body = json_decode(file_get_contents('php://input', false, null, 0, 1048576), true);

if (! is_array($body)
    || ! is_string($body['to'] ?? null)
    || $body['to'] === ''
    || ! in_array($body['channel'] ?? null, ['sms', 'email', 'push'], true)
    || ! is_string($body['content'] ?? null)
    || $body['content'] === ''
    || count($body) !== 3
) {
    http_response_code(422);
    echo '{"error":"invalid_notification"}';

    return;
}

$bytes = random_bytes(16);
$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

$response = json_encode([
    'messageId' => $uuid,
    'status' => 'accepted',
    'timestamp' => gmdate(DATE_RFC3339),
], JSON_THROW_ON_ERROR);

if ($key !== '') {
    $db = receipts();
    $digest = hash('sha256', json_encode([$body['to'], $body['channel'], $body['content']], JSON_THROW_ON_ERROR));
    $insert = $db->prepare('INSERT OR IGNORE INTO receipts (delivery_key, digest, response) VALUES (?, ?, ?)');
    $insert->execute([$key, $digest, $response]);
    $find = $db->prepare('SELECT digest, response FROM receipts WHERE delivery_key = ?');
    $find->execute([$key]);
    $receipt = $find->fetch(PDO::FETCH_ASSOC);
    if ($receipt['digest'] !== $digest) {
        http_response_code(409);
        echo '{"error":"idempotency_conflict"}';

        return;
    }
    $response = $receipt['response'];
}

http_response_code(202);
echo $response;

function receipts(): PDO
{
    $db = new PDO('sqlite:'.(getenv('PROVIDER_RECEIPTS_DB') ?: sys_get_temp_dir().'/notification-provider.sqlite'));
    $db->exec('PRAGMA busy_timeout = 3000');
    $db->exec('CREATE TABLE IF NOT EXISTS receipts (delivery_key TEXT PRIMARY KEY, digest TEXT NOT NULL, response TEXT NOT NULL)');

    return $db;
}

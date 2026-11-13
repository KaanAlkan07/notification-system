<?php

declare(strict_types=1);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && $path === '/health') {
    echo '{"status":"ok"}';

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

http_response_code(202);
echo json_encode([
    'messageId' => $uuid,
    'status' => 'accepted',
    'timestamp' => gmdate(DATE_RFC3339),
], JSON_THROW_ON_ERROR);

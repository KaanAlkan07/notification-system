<?php

return [
    'mailers' => [
        'notification_smtp' => [
            'transport' => 'smtp',
            'scheme' => env('NOTIFICATION_SMTP_SCHEME', 'smtp'),
            'host' => env('NOTIFICATION_SMTP_HOST', ''),
            'port' => (int) env('NOTIFICATION_SMTP_PORT', 587),
            'username' => env('NOTIFICATION_SMTP_USERNAME', ''),
            'password' => env('NOTIFICATION_SMTP_PASSWORD', ''),
            'require_tls' => true,
            'timeout' => 5,
        ],
    ],
    'from' => [
        'address' => env('NOTIFICATION_MAIL_FROM', ''),
        'name' => env('NOTIFICATION_MAIL_FROM_NAME', 'Bildirim Merkezi'),
    ],
];

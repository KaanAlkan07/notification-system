<?php

return [
    'api_token' => env('NOTIFICATION_API_TOKEN', ''),
    'provider_url' => env('NOTIFICATION_PROVIDER_URL', 'http://127.0.0.1:8081/notifications'),
    'provider_connect_timeout' => 2,
    'provider_timeout' => 5,
    'rate_limit' => 100,
    'max_attempts' => 5,
    'sms_provider' => env('NOTIFICATION_SMS_PROVIDER', 'webhook'),
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
        'from' => env('TWILIO_FROM', ''),
        'callback_base_url' => env('TWILIO_CALLBACK_BASE_URL', ''),
    ],
];

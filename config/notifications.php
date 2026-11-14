<?php

$providerUrl = env('NOTIFICATION_PROVIDER_URL', 'http://127.0.0.1:8081/notifications');

return [
    'api_token' => env('NOTIFICATION_API_TOKEN', ''),
    'provider_url' => $providerUrl,
    'provider_status_url' => env('NOTIFICATION_PROVIDER_STATUS_URL') ?: ($providerUrl === 'http://provider:8080/notifications'
        ? 'http://provider:8080/status'
        : null),
    'provider_status_token' => env('NOTIFICATION_PROVIDER_STATUS_TOKEN', ''),
    'provider_connect_timeout' => 2,
    'provider_timeout' => 5,
    'rate_limit' => 100,
    'max_attempts' => 5,
    'sms_provider' => env('NOTIFICATION_SMS_PROVIDER', 'webhook'),
    'email_provider' => env('NOTIFICATION_EMAIL_PROVIDER', 'webhook'),
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
        'from' => env('TWILIO_FROM', ''),
        'callback_base_url' => env('TWILIO_CALLBACK_BASE_URL', ''),
    ],
];

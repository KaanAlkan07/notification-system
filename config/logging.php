<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

return [
    'default' => env('LOG_CHANNEL', 'stderr'),
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'formatter' => JsonFormatter::class,
            'with' => ['stream' => 'php://stderr'],
            'level' => env('LOG_LEVEL', 'info'),
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
    ],
];

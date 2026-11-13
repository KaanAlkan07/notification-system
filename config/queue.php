<?php

return [
    'default' => 'database',
    'connections' => [
        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'normal',
            'retry_after' => 60,
            'after_commit' => false,
        ],
    ],
    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];

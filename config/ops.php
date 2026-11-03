<?php

// Production operations (workstream P5): health checks and database backups.
return [
    'health' => [
        // Optional shared secret; when sent as X-Health-Token the response includes numbers.
        'token' => env('OPS_HEALTH_TOKEN'),
        'queue_backlog_max' => (int) env('OPS_HEALTH_QUEUE_BACKLOG_MAX', 500),
        'failed_jobs_max' => (int) env('OPS_HEALTH_FAILED_JOBS_MAX', 50),
        'disk_min_free_mb' => (int) env('OPS_HEALTH_DISK_MIN_FREE_MB', 1024),
        // null = check Redis only when cache/queue/session uses it.
        'redis' => env('OPS_HEALTH_REDIS'),
    ],
    'backup' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        // Passphrase for AES-256-CBC (openssl enc -aes-256-cbc -pbkdf2 -iter 100000). Falls back to APP_KEY.
        'key' => env('BACKUP_KEY'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
        // Optional filesystem disk (e.g. "s3") that receives a copy of each backup.
        'disk' => env('BACKUP_DISK'),
        'disk_prefix' => env('BACKUP_DISK_PREFIX', 'db-backups'),
        // Directory holding pg_dump / psql when they are not on PATH.
        'pg_bin' => env('PG_BIN_PATH'),
        'pbkdf2_iterations' => 100000,
        'schedule_at' => env('BACKUP_SCHEDULE_AT', '01:30'),
    ],
];

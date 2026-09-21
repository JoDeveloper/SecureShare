<?php

return [
    'model' => \Ncpd\SecureShare\Models\SecureShare::class,

    // null = fall back to Media Library's own default disk (your OSS disk)
    'disk' => null,

    'route' => [
        'prefix' => 'secure-share',
        'middleware' => ['web'],
    ],

    'passcode' => ['min_length' => 8],

    'lockout' => ['max_attempts' => 5, 'lockout_minutes' => 15],

    'throttle' => '6,1', // requests,minutes on the public unlock route

    // Seconds a successful unlock keeps the download route usable for that share.
    'unlock_ttl' => 600,

    // Maximum size per attachment, in kilobytes.
    'max_upload_kb' => 10240,

    'purge' => [
        // Auto-schedule `secure-share:purge` hourly. Disable to schedule it yourself.
        'schedule' => true,

        // How long expired/exhausted shares (and their files) linger before deletion.
        'retention_days' => 7,
    ],
];

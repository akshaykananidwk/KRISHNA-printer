<?php
declare(strict_types=1);

return [
    'password' => [
        // Argon2id where the build supports it, bcrypt otherwise. Both are
        // memory- or cost-tuned; neither stores anything reversible.
        'algorithm' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
        'argon_options' => [
            'memory_cost' => 65536,
            'time_cost' => 4,
            // Must stay 1. PHP's Argon2 comes from either libargon2 or
            // libsodium depending on how the binary was built, and the sodium
            // implementation supports only a single thread — it raises
            // "A thread value other than 1 is not supported by this
            // implementation" for anything else. A higher value therefore
            // works on one server and is fatal on the next, and PHP's own
            // default here is 1 regardless.
            'threads' => 1,
        ],
        'bcrypt_options' => ['cost' => 12],
        'min_length' => 10,
        // Rejects the handful of passwords that account for most credential
        // stuffing hits. Full breach-list checking is a deployment concern.
        'blocklist' => [
            'password', 'password1', 'password123', '12345678', '123456789',
            'qwerty123', 'admin123', 'letmein123', 'welcome123', 'printer123',
            'changeme', 'iloveyou', 'administrator',
        ],
    ],

    'login' => [
        'max_attempts' => 5,
        'decay_seconds' => 900,
        'lockout_seconds' => 900,
    ],

    'rate_limit' => [
        'global_max' => 300,
        'global_decay' => 60,
        'upload_max' => 20,
        'upload_decay' => 300,
        'payment_max' => 15,
        'payment_decay' => 300,
        'qr_scan_max' => 60,
        'qr_scan_decay' => 300,
        'api_max' => 600,
        'api_decay' => 60,
    ],

    'api_tokens' => [
        'prefix' => 'kpms_',
        'length' => 40,
        // A rotated token keeps working for this long so an agent mid-poll is
        // not locked out by a rotation.
        'rotation_grace_seconds' => 900,
        'default_lifetime_days' => 365,
    ],

    // Roles, most-privileged first. Each role implicitly holds every
    // permission of the roles below it.
    'roles' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'viewer' => 'Viewer',
    ],

    'permissions' => [
        'super_admin' => ['*'],
        'admin' => [
            'dashboard.view', 'locations.view', 'locations.manage',
            'printers.view', 'printers.manage', 'printers.test',
            'pricing.view', 'pricing.manage',
            'jobs.view', 'jobs.manage', 'jobs.cancel', 'jobs.retry',
            'reports.view', 'reports.export',
            'settings.view', 'settings.manage',
            'backups.view', 'backups.create',
            'audit.view',
        ],
        'operator' => [
            'dashboard.view', 'locations.view',
            'printers.view', 'printers.test',
            'jobs.view', 'jobs.cancel', 'jobs.retry',
            'reports.view',
        ],
        'viewer' => [
            'dashboard.view', 'locations.view', 'printers.view',
            'jobs.view', 'reports.view',
        ],
    ],
];

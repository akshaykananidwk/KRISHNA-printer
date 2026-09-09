<?php
declare(strict_types=1);

/**
 * GitHub one-click update configuration.
 *
 * The repository, branch and token are entered once in the admin panel and
 * stored (token encrypted) in system_settings. This file defines what the
 * updater is allowed to touch and what it must never overwrite.
 */
$base = dirname(__DIR__);

return [
    'api_base' => 'https://api.github.com',
    'user_agent' => 'KrishnaPrinter-Updater',
    'timeout_seconds' => 120,

    /**
     * Paths the updater must NEVER overwrite or delete. These are matched
     * against the repository-relative path of every incoming file and against
     * the live tree during the swap.
     *
     * Customer uploads, generated configuration, backups and logs all live
     * here, which is why a failed update can never destroy production data.
     */
    'preserve' => [
        'config/config.php',
        '.env',
        'uploads',
        'storage',
        'logs',
        'backups',
        'public/qr',
        'storage/installed.lock',
    ],

    /**
     * Files that may exist in the repository but must not be deployed to a
     * live install.
     */
    'exclude_from_package' => [
        '.git',
        '.github',
        'tests',
        '.gitignore',
        '.gitattributes',
        'docs/development',
    ],

    'paths' => [
        'work' => $base . '/storage/updates',
        'staging' => $base . '/storage/updates/staging',
        'download' => $base . '/storage/updates/download',
    ],

    /**
     * A health check must pass after files are swapped and migrations run.
     * If any of these fail the updater rolls the application back and, when
     * migrations had already run, restores the pre-update database backup.
     */
    'health_checks' => [
        'config_readable',
        'database_connects',
        'migrations_current',
        'writable_paths',
        'front_controller_parses',
    ],

    'max_backups_retained' => 10,
    'backup_retention_days' => 30,

    // Refuse to apply an update whose package is implausibly small — that
    // almost always means a truncated download rather than a real release.
    'min_package_bytes' => 10 * 1024,
];

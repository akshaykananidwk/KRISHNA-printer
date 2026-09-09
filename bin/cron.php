#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Scheduled maintenance. One cron entry runs everything:
 *
 *   * * * * * php /path/to/bin/cron.php >> /path/to/logs/cron.log 2>&1
 *
 * Each task decides for itself whether it is due, so a single per-minute tick
 * is enough and there is nothing to keep in sync in crontab.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__);

/** @var App\Core\Application $app */
$app = require $basePath . '/bootstrap/app.php';

use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Repositories\ApiTokenRepository;
use App\Repositories\AuditRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintSessionRepository;
use App\Repositories\PrintFileRepository;
use App\Services\BackupService;
use App\Services\FileService;
use App\Services\PaymentService;
use App\Services\PrinterService;
use App\Services\PrintQueueService;

if (!(bool) Config::get('app.installed', false)) {
    fwrite(STDERR, "The application is not installed yet.\n");
    exit(1);
}

Logger::configure((string) Config::get('app.log_path'), 'cron');

$options = getopt('', ['task::', 'verbose', 'force']);
$onlyTask = isset($options['task']) ? (string) $options['task'] : null;
$verbose = isset($options['verbose']);
$force = isset($options['force']);

$container = Container::instance();

$say = static function (string $message) use ($verbose): void {
    if ($verbose) {
        fwrite(STDOUT, gmdate('H:i:s') . ' ' . $message . PHP_EOL);
    }
};

$minute = (int) gmdate('i');
$hour = (int) gmdate('H');

/**
 * Each task declares when it is due. Keeping the schedule here rather than in
 * crontab means a deployment only ever needs one cron line.
 *
 * @var array<string,array{due:bool,run:callable():string}>
 */
$tasks = [
    // Every minute: the queue must not stall.
    'queue_maintenance' => [
        'due' => true,
        'run' => static function () use ($container): string {
            $queue = $container->get(PrintQueueService::class);
            $result = $queue->performMaintenance();
            return sprintf('requeued %d, failed %d', $result['reclaimed'], $result['expired']);
        },
    ],

    // Every minute: a stale "online" is what lets a customer pay for a job
    // that cannot print.
    'printer_health' => [
        'due' => true,
        'run' => static function () use ($container): string {
            $printerService = $container->get(PrinterService::class);
            $result = $printerService->runScheduledHealthChecks(50);
            return sprintf(
                'checked %d — %d online, %d not',
                $result['checked'],
                $result['online'],
                $result['offline']
            );
        },
    ],

    // Every minute: an agent that stopped polling means its printers are gone.
    'agent_liveness' => [
        'due' => true,
        'run' => static function () use ($container): string {
            $devices = $container->get(DeviceRepository::class);
            $stale = max(60, (int) Config::get('printing.queue.health_stale_after', 180));
            $marked = $devices->markStaleOffline($stale);
            return $marked === 0 ? 'all agents responding' : sprintf('%d agent(s) marked offline', $marked);
        },
    ],

    // Every 2 minutes: ask printers what happened to jobs they took.
    'reconcile_jobs' => [
        'due' => $minute % 2 === 0,
        'run' => static function () use ($container): string {
            $queue = $container->get(PrintQueueService::class);
            $result = $queue->reconcilePrintingJobs(50);
            return sprintf(
                'checked %d — %d completed, %d failed',
                $result['checked'],
                $result['completed'],
                $result['failed']
            );
        },
    ],

    // Every 5 minutes: release the jobs behind abandoned checkouts.
    'expire_payments' => [
        'due' => $minute % 5 === 0,
        'run' => static function () use ($container): string {
            $payments = $container->get(PaymentService::class);
            if (!$payments->isEnabled()) {
                return 'payments disabled; nothing to expire';
            }
            $result = $payments->expireStaleOrders();
            return sprintf(
                '%d order(s) expired, %d job(s) cancelled',
                $result['expired'],
                $result['jobs_cancelled']
            );
        },
    ],

    // Every 10 minutes: delete customer documents past their retention.
    'purge_files' => [
        'due' => $minute % 10 === 0,
        'run' => static function () use ($container): string {
            $files = $container->get(FileService::class);
            $result = $files->purgeExpired(200);
            return sprintf(
                '%d file(s) deleted, %s freed, %d error(s)',
                $result['deleted'],
                number_format($result['bytes'] / 1048576, 1) . ' MB',
                $result['errors']
            );
        },
    ],

    // Nightly: trim history so the database does not grow without bound.
    'prune_history' => [
        'due' => $hour === 3 && $minute === 15,
        'run' => static function () use ($container): string {
            $printers = $container->get(PrinterRepository::class);
            $sessions = $container->get(PrintSessionRepository::class);
            $files = $container->get(PrintFileRepository::class);
            $tokens = $container->get(ApiTokenRepository::class);
            $audit = $container->get(AuditRepository::class);

            $checks = $printers->pruneStatusHistory(30);
            $expiredSessions = $sessions->pruneExpired();
            $tombstones = $files->pruneDeletedRows(90);
            $revokedTokens = $tokens->pruneExpired(30);
            // Audit rows are kept for a year; critical ones are never pruned.
            $auditRows = $audit->prune(365);
            $logs = Logger::prune((int) Config::get('app.log_retention_days', 30));

            return sprintf(
                'health %d, sessions %d, file rows %d, tokens %d, audit %d, log files %d',
                $checks,
                $expiredSessions,
                $tombstones,
                $revokedTokens,
                $auditRows,
                $logs
            );
        },
    ],

    // Nightly: a scheduled database backup, plus cleanup of old ones.
    'nightly_backup' => [
        'due' => $hour === 3 && $minute === 30,
        'run' => static function () use ($container): string {
            $backups = $container->get(BackupService::class);
            $result = $backups->backupDatabase('scheduled', null);

            if (!$result['success']) {
                // A failing backup is a real operational problem, not a note.
                Logger::critical('The scheduled database backup failed', ['error' => $result['error']]);
                return 'FAILED: ' . $result['error'];
            }

            $pruned = $backups->pruneOldBackups();

            return sprintf(
                'backup %s, %d old backup(s) removed',
                $backups->humanBytes((int) $result['size']),
                $pruned['deleted']
            );
        },
    ],
];

$exitCode = 0;

foreach ($tasks as $name => $task) {
    if ($onlyTask !== null && $onlyTask !== $name) {
        continue;
    }
    if (!$force && $onlyTask === null && !$task['due']) {
        continue;
    }

    $started = microtime(true);

    try {
        $summary = ($task['run'])();
        $duration = (int) round((microtime(true) - $started) * 1000);

        $say(sprintf('[%s] %s (%d ms)', $name, $summary, $duration));

        // Only log the tasks that did something, so the log stays readable.
        if (!str_contains($summary, ' 0 ') || str_contains($summary, 'FAILED')) {
            Logger::info('Scheduled task ran', [
                'task' => $name,
                'summary' => $summary,
                'duration_ms' => $duration,
            ]);
        }
    } catch (\Throwable $e) {
        $exitCode = 1;
        $say(sprintf('[%s] ERROR: %s', $name, $e->getMessage()));
        Logger::error('Scheduled task failed', [
            'task' => $name,
            'error' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}

exit($exitCode);

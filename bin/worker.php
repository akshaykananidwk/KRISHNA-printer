#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Print queue worker.
 *
 * Only needed for printers on transports the cloud can reach directly (IPP,
 * RAW, LPD over a private link or VPN). Printers driven by a location agent do
 * not need this — the agent pulls its own work.
 *
 * Run under a supervisor:
 *
 *   * * * * * php /path/to/bin/worker.php --once >> /path/to/logs/worker.log 2>&1
 *
 * or as a long-running service:
 *
 *   php /path/to/bin/worker.php --daemon
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
use App\Repositories\PrinterRepository;
use App\Services\PrintQueueService;

if (!(bool) Config::get('app.installed', false)) {
    fwrite(STDERR, "The application is not installed yet. Visit /install first.\n");
    exit(1);
}

Logger::configure((string) Config::get('app.log_path'), 'worker');

$options = getopt('', ['once', 'daemon', 'sleep::', 'max-jobs::', 'printer::', 'verbose']);
$runOnce = isset($options['once']);
$daemon = isset($options['daemon']);
$sleepSeconds = max(1, (int) ($options['sleep'] ?? 5));
$maxJobs = (int) ($options['max-jobs'] ?? ($runOnce ? 25 : 0));
$verbose = isset($options['verbose']);

$container = Container::instance();
/** @var PrintQueueService $queue */
$queue = $container->get(PrintQueueService::class);
/** @var PrinterRepository $printers */
$printers = $container->get(PrinterRepository::class);

$say = static function (string $message) use ($verbose): void {
    if ($verbose) {
        fwrite(STDOUT, gmdate('H:i:s') . ' ' . $message . PHP_EOL);
    }
};

/**
 * Printers this worker is responsible for: enabled, not agent-driven, and
 * either all of them or the one named on the command line.
 *
 * @return array<int,int>
 */
$resolvePrinterIds = static function () use ($printers, $options): array {
    $ids = [];

    foreach ($printers->all(false) as $printer) {
        // An agent-driven printer pulls its own jobs; a worker claiming them
        // too would race the agent for the same lease.
        $connection = $printers->primaryConnection($printer->id());
        if ($connection === null || (string) $connection['driver'] === 'agent') {
            continue;
        }
        if (isset($options['printer']) && (string) $options['printer'] !== $printer->string('code')) {
            continue;
        }
        $ids[] = $printer->id();
    }

    return $ids;
};

$running = true;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function (int $signal) use (&$running, $say): void {
        $say('Received signal ' . $signal . '; finishing the current job then stopping.');
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$processed = 0;
$startedAt = time();

$say('Worker starting' . ($daemon ? ' (daemon)' : ' (single pass)'));

do {
    // Housekeeping first: return jobs whose worker died, and fail the ones
    // that have exhausted their retries, so neither can sit in limbo.
    $maintenance = $queue->performMaintenance();
    if ($maintenance['reclaimed'] > 0 || $maintenance['expired'] > 0) {
        $say(sprintf(
            'Maintenance: requeued %d, failed %d',
            $maintenance['reclaimed'],
            $maintenance['expired']
        ));
    }

    $printerIds = $resolvePrinterIds();

    if ($printerIds === []) {
        $say('No directly-reachable printers to serve.');
        if ($runOnce) {
            break;
        }
        sleep($sleepSeconds);
        continue;
    }

    $didWork = false;

    while ($running) {
        $result = $queue->dispatchNext($printerIds);

        if ($result === null) {
            break;
        }

        $didWork = true;
        $processed++;

        $say(sprintf(
            '%s %s%s',
            $result['success'] ? '[sent]' : '[fail]',
            $result['job_number'],
            isset($result['message']) ? ' — ' . $result['message'] : (isset($result['error']) ? ' — ' . $result['error'] : '')
        ));

        if ($maxJobs > 0 && $processed >= $maxJobs) {
            $say('Reached the job limit for this pass.');
            break 2;
        }
    }

    // Ask printers what happened to jobs they took but have not reported on.
    $reconciled = $queue->reconcilePrintingJobs();
    if ($reconciled['completed'] > 0 || $reconciled['failed'] > 0) {
        $say(sprintf(
            'Reconciled: %d completed, %d failed of %d checked',
            $reconciled['completed'],
            $reconciled['failed'],
            $reconciled['checked']
        ));
    }

    if ($runOnce) {
        break;
    }

    if (!$didWork) {
        sleep($sleepSeconds);
    }
} while ($running && $daemon);

$say(sprintf(
    'Worker finished: %d job(s) in %d second(s)',
    $processed,
    time() - $startedAt
));

exit(0);

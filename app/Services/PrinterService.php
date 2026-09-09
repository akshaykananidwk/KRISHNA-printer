<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Printer;
use App\Repositories\PrinterRepository;
use App\Services\Printer\Drivers\AgentDriver;
use App\Services\Printer\Drivers\IppDriver;
use App\Services\Printer\Drivers\LpdDriver;
use App\Services\Printer\Drivers\NullDriver;
use App\Services\Printer\Drivers\Raw9100Driver;
use App\Services\Printer\JobStatusResult;
use App\Services\Printer\PrinterCapabilities;
use App\Services\Printer\PrinterDriver;
use App\Services\Printer\PrinterStatus;
use App\Services\Printer\PrinterTarget;
use App\Services\Printer\PrintJobSpec;
use App\Services\Printer\SubmitResult;

/**
 * Facade over the transport layer. Owns driver selection, health checking and
 * the persistence of printer state.
 */
final class PrinterService
{
    /** @var array<string,PrinterDriver> */
    private array $drivers = [];

    public function __construct(
        private Database $db,
        private PrinterRepository $printers,
        private AuditService $audit
    ) {
    }

    public function driver(string $name): PrinterDriver
    {
        return $this->drivers[$name] ??= match ($name) {
            'ipp' => new IppDriver(),
            'raw9100' => new Raw9100Driver(),
            'lpd' => new LpdDriver(),
            'agent' => new AgentDriver($this->db),
            'null' => new NullDriver(),
            default => throw new \InvalidArgumentException('Unknown printer driver: ' . $name),
        };
    }

    /** @return array<string,string> driver name => description */
    public function availableDrivers(): array
    {
        $out = [];
        foreach (['agent', 'ipp', 'raw9100', 'lpd', 'null'] as $name) {
            $out[$name] = $this->driver($name)->description();
        }
        return $out;
    }

    /**
     * Build a target from a printer's primary (or specified) connection.
     */
    public function targetFor(Printer $printer, ?array $connection = null): ?PrinterTarget
    {
        $connection ??= $this->printers->primaryConnection($printer->id());
        if ($connection === null) {
            return null;
        }
        return PrinterTarget::fromConnection($connection, $printer->toArray());
    }

    /**
     * Probe a printer and persist the outcome.
     *
     * Every call performs real I/O. There is no cached-status short-circuit
     * here: the customer gate depends on this answer, and a stale "online"
     * costs a customer money for a document that never prints.
     */
    public function checkHealth(Printer $printer, bool $recordHistory = true): PrinterStatus
    {
        $connections = $this->printers->connections($printer->id());

        if ($connections === []) {
            $status = PrinterStatus::unknown('No connection is configured for this printer.');
            $this->persistStatus($printer, $status, 'none', $recordHistory);
            return $status;
        }

        $lastStatus = null;

        // Connections are tried in priority order, so a printer can have an IPP
        // primary and an agent fallback (or vice versa) and stay usable when
        // one path is down.
        foreach ($connections as $connection) {
            $target = PrinterTarget::fromConnection($connection, $printer->toArray());

            try {
                $status = $this->driver($target->driver)->probe($target);
            } catch (\Throwable $e) {
                Logger::error('Printer probe threw', [
                    'printer_id' => $printer->id(),
                    'driver' => $target->driver,
                    'error' => $e->getMessage(),
                ]);
                $status = PrinterStatus::unknown('Probe error: ' . $e->getMessage(), $target->driver);
            }

            $this->recordConnectionTest($connection, $status);
            $lastStatus = $status;

            if ($status->isOnline()) {
                $this->persistStatus($printer, $status, $target->driver, $recordHistory);
                return $status;
            }
        }

        $status = $lastStatus ?? PrinterStatus::unknown('No connection could be probed.');
        $this->persistStatus($printer, $status, (string) ($status->driver ?? 'unknown'), $recordHistory);
        return $status;
    }

    private function persistStatus(Printer $printer, PrinterStatus $status, string $driver, bool $recordHistory): void
    {
        $previous = $printer->status();

        $update = [
            'status' => $status->status,
            'last_seen_at' => $status->isOnline() ? gmdate('Y-m-d H:i:s') : $printer->get('last_seen_at'),
        ];

        if ($status->isOnline()) {
            $update['consecutive_failures'] = 0;
            // Clear a stale error once the printer is healthy again, but keep a
            // live blocking reason (media-empty, cover-open) visible.
            $update['last_error'] = $status->hasBlockingReason() ? $status->reasonSummary() : null;
        } else {
            $update['consecutive_failures'] = $printer->int('consecutive_failures') + 1;
            $update['last_error'] = substr($status->message, 0, 1000);
            $update['last_error_at'] = gmdate('Y-m-d H:i:s');
        }

        $this->printers->updateById($printer->id(), $update);
        foreach ($update as $key => $value) {
            $printer->set($key, $value);
        }

        if ($recordHistory) {
            $this->printers->recordStatusCheck(
                $printer->id(),
                $status->status,
                $driver,
                $status->latencyMs,
                $status->reasonSummary(),
                $status->message
            );
        }

        // Log only transitions, so the audit trail records events rather than
        // one row per minute per printer.
        if ($previous !== $status->status) {
            $this->audit->log(
                'printer.status_changed',
                sprintf(
                    'Printer "%s" went %s → %s: %s',
                    $printer->string('name'),
                    $previous,
                    $status->status,
                    $status->message
                ),
                'printer',
                (string) $printer->id(),
                $status->isOnline() ? 'info' : 'warning',
                ['driver' => $driver, 'state_reasons' => $status->stateReasons]
            );
        }
    }

    /** @param array<string,mixed> $connection */
    private function recordConnectionTest(array $connection, PrinterStatus $status): void
    {
        $this->db->execute(
            'UPDATE printer_connections
             SET last_tested_at = UTC_TIMESTAMP(), last_test_result = ?, last_test_message = ?
             WHERE id = ?',
            [$status->isOnline() ? 'ok' : 'failed', substr($status->message, 0, 1000), (int) $connection['id']]
        );
    }

    /**
     * THE customer-facing gate.
     *
     * Returns a structured answer used by the QR landing page and re-checked
     * immediately before a job is created. A printer must be enabled, its
     * location active, and its live probe usable — not merely reachable — for
     * an upload to be allowed.
     *
     * @return array{available:bool,status:string,reason:string,printer:?Printer,detail:array<string,mixed>}
     */
    public function availabilityFor(int $printerId, bool $allowCachedProbe = false): array
    {
        $printer = $this->printers->find($printerId);

        if ($printer === null || $printer->get('deleted_at') !== null) {
            return $this->unavailable('not_found', 'This printer is no longer configured at this location.');
        }

        if (!$printer->bool('is_enabled')) {
            return $this->unavailable(
                'disabled',
                'This printer has been taken out of service.',
                $printer
            );
        }

        if ($printer->status() === Printer::STATUS_MAINTENANCE) {
            return $this->unavailable(
                'maintenance',
                'This printer is under maintenance.',
                $printer
            );
        }

        $location = $this->db->selectOne(
            'SELECT status, deleted_at FROM locations WHERE id = ?',
            [$printer->int('location_id')]
        );
        if ($location === null || $location['deleted_at'] !== null || (string) $location['status'] !== 'active') {
            return $this->unavailable(
                'location_inactive',
                'This location is not currently accepting print jobs.',
                $printer
            );
        }

        // Probe the printer for real by default.
        //
        // A cached "online" is only ever safe for a display surface. Any answer
        // that gates taking a customer's file, money or paper must come from the
        // printer as it is now: a machine that ran out of paper ten seconds ago
        // is out of paper, and serving a stale "available" there would accept an
        // order the printer cannot fulfil — the exact case the brief forbids.
        // Callers therefore have to opt in to the cache, and only for display.
        $status = null;

        if ($allowCachedProbe && $printer->status() === Printer::STATUS_ONLINE) {
            $cacheWindow = (int) Config::get('printing.queue.health_check_interval', 60);
            $lastSeen = $printer->string('last_seen_at');
            if ($lastSeen !== '' && (time() - strtotime($lastSeen . ' UTC')) < $cacheWindow) {
                $status = PrinterStatus::online('Recently verified as ready.', null, [], null);
            }
        }

        if ($status === null) {
            $status = $this->checkHealth($printer);
        }

        if (!$status->isUsable()) {
            $reason = $status->hasBlockingReason()
                ? $this->describeBlockingReason($status)
                : 'Printing Service Currently Unavailable. Please try again later.';

            return [
                'available' => false,
                'status' => $status->status,
                'reason' => $reason,
                'printer' => $printer,
                'detail' => $status->toArray(),
            ];
        }

        // A printer whose queue is already saturated is treated as unavailable:
        // accepting more work would mean taking money for a job that will not
        // print for a long time.
        $depth = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM print_jobs
             WHERE printer_id = ? AND status IN ('queued','processing','printing')",
            [$printer->id()]
        );
        $maxDepth = $printer->int('max_queue_depth', 25);
        if ($maxDepth > 0 && $depth >= $maxDepth) {
            return [
                'available' => false,
                'status' => 'busy',
                'reason' => sprintf(
                    'This printer has %d jobs waiting. Please try again in a few minutes.',
                    $depth
                ),
                'printer' => $printer,
                'detail' => ['queue_depth' => $depth, 'max_queue_depth' => $maxDepth],
            ];
        }

        return [
            'available' => true,
            'status' => 'online',
            'reason' => '',
            'printer' => $printer,
            'detail' => $status->toArray() + ['queue_depth' => $depth],
        ];
    }

    /** Turn IPP state reasons into something a customer can act on. */
    private function describeBlockingReason(PrinterStatus $status): string
    {
        $reasons = array_map('strtolower', $status->stateReasons);
        foreach ($reasons as $reason) {
            if (str_starts_with($reason, 'media-empty') || str_starts_with($reason, 'media-needed')) {
                return 'This printer is out of paper. Please ask staff for assistance or try again later.';
            }
            if (str_starts_with($reason, 'media-jam')) {
                return 'This printer has a paper jam. Please ask staff for assistance.';
            }
            if (str_starts_with($reason, 'toner-empty') || str_starts_with($reason, 'marker-supply-empty')) {
                return 'This printer is out of ink. Please ask staff for assistance or try again later.';
            }
            if (str_starts_with($reason, 'cover-open') || str_starts_with($reason, 'door-open')) {
                return 'This printer has a cover open. Please ask staff for assistance.';
            }
        }
        return 'Printing Service Currently Unavailable. Please try again later.';
    }

    /** @return array{available:false,status:string,reason:string,printer:?Printer,detail:array<string,mixed>} */
    private function unavailable(string $status, string $reason, ?Printer $printer = null): array
    {
        return [
            'available' => false,
            'status' => $status,
            'reason' => $reason,
            'printer' => $printer,
            'detail' => [],
        ];
    }

    /** Send a document to a printer through the first connection that will take it. */
    public function submit(Printer $printer, PrintJobSpec $spec, string $documentPath): SubmitResult
    {
        $connections = $this->printers->connections($printer->id());
        if ($connections === []) {
            return SubmitResult::permanentFailure(
                'No connection is configured for this printer.',
                'no_connection'
            );
        }

        $lastResult = null;

        foreach ($connections as $connection) {
            $target = PrinterTarget::fromConnection($connection, $printer->toArray());
            $driver = $this->driver($target->driver);

            try {
                $result = $driver->submit($target, $spec, $documentPath);
            } catch (\Throwable $e) {
                Logger::error('Printer submit threw', [
                    'printer_id' => $printer->id(),
                    'driver' => $target->driver,
                    'job' => $spec->jobNumber,
                    'error' => $e->getMessage(),
                ]);
                $result = SubmitResult::transientFailure(
                    'Unexpected transport error: ' . $e->getMessage(),
                    'transport_exception'
                );
            }

            if ($result->accepted) {
                return $result;
            }

            $lastResult = $result;

            // A permanent failure on this connection (unsupported format, bad
            // credentials) will be permanent on a retry too — but a *different*
            // connection may still work, so keep trying the list.
        }

        return $lastResult ?? SubmitResult::transientFailure('No connection accepted the job.', 'all_connections_failed');
    }

    public function jobStatus(Printer $printer, string $remoteJobId): JobStatusResult
    {
        $target = $this->targetFor($printer);
        if ($target === null) {
            return JobStatusResult::unknown('No connection configured.');
        }
        return $this->driver($target->driver)->jobStatus($target, $remoteJobId);
    }

    public function capabilities(Printer $printer): PrinterCapabilities
    {
        $connections = $this->printers->connections($printer->id());

        // Prefer a transport that can actually answer. IPP and the agent can;
        // RAW and LPD cannot, and asking them first would return "unsupported"
        // even when a working IPP path exists on the same printer.
        usort($connections, static function (array $a, array $b): int {
            $rank = ['ipp' => 0, 'agent' => 1, 'lpd' => 2, 'raw9100' => 3, 'null' => 4];
            return ($rank[$a['driver']] ?? 9) <=> ($rank[$b['driver']] ?? 9);
        });

        $lastMessage = 'No connection could report capabilities.';

        foreach ($connections as $connection) {
            $target = PrinterTarget::fromConnection($connection, $printer->toArray());
            try {
                $capabilities = $this->driver($target->driver)->capabilities($target);
            } catch (\Throwable $e) {
                $lastMessage = $e->getMessage();
                continue;
            }
            if ($capabilities->reported) {
                return $capabilities;
            }
            $lastMessage = $capabilities->message ?? $lastMessage;
        }

        return PrinterCapabilities::unsupported($lastMessage);
    }

    /**
     * Run health checks across the estate. Called by the scheduler.
     *
     * @return array{checked:int,online:int,offline:int}
     */
    public function runScheduledHealthChecks(int $limit = 50): array
    {
        $interval = (int) Config::get('printing.queue.health_check_interval', 60);
        $printers = $this->printers->dueForHealthCheck($interval, $limit);

        $online = 0;
        $offline = 0;

        foreach ($printers as $printer) {
            $status = $this->checkHealth($printer);
            $status->isOnline() ? $online++ : $offline++;
        }

        return ['checked' => count($printers), 'online' => $online, 'offline' => $offline];
    }
}

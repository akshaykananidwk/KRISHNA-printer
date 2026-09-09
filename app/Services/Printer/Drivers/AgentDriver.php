<?php
declare(strict_types=1);

namespace App\Services\Printer\Drivers;

use App\Core\Config;
use App\Core\Database;
use App\Services\Printer\JobStatusResult;
use App\Services\Printer\PrinterCapabilities;
use App\Services\Printer\PrinterDriver;
use App\Services\Printer\PrinterStatus;
use App\Services\Printer\PrinterTarget;
use App\Services\Printer\PrintJobSpec;
use App\Services\Printer\SubmitResult;

/**
 * Location print agent — the recommended transport, and the default.
 *
 * WHY THIS EXISTS
 * ---------------
 * Two hard constraints shape the architecture:
 *
 *  1. Printer ports must never be exposed to the public internet. Port 9100 is
 *     unauthenticated by design: anyone who can reach it can print, read the
 *     queue, or exhaust the consumables. Port-forwarding a printer is not a
 *     configuration choice, it is a vulnerability.
 *
 *  2. Consumer inkjets — including the Canon PIXMA GM4070 targeted here — have
 *     no PCL or PostScript interpreter. They need a host with the vendor (or
 *     IPP Everywhere) driver to rasterise the document first. No amount of
 *     protocol cleverness removes that requirement.
 *
 * The agent resolves both. It is a small always-on Linux box (Raspberry Pi
 * class) on the shop LAN running CUPS. It makes only OUTBOUND HTTPS requests to
 * this application: it polls for work, downloads the document, renders it with
 * the real printer driver, prints it, and reports the outcome back. Nothing
 * inbound is ever opened, so no VPN and no port forwarding is required, and no
 * Windows machine is needed at any location.
 *
 * Because the model is pull-based, "submitting" from the cloud means marking
 * the job claimable. The agent's own report is what advances it to printing and
 * then to completed — this driver never invents a status.
 */
final class AgentDriver implements PrinterDriver
{
    public function __construct(private Database $db)
    {
    }

    public function name(): string
    {
        return 'agent';
    }

    public function description(): string
    {
        return 'Location print agent over outbound HTTPS — renders with the real printer driver, '
            . 'requires no inbound ports, no VPN and no Windows PC on site.';
    }

    /**
     * The agent reports both its own liveness and the printer's state, which it
     * reads from the local CUPS queue. Both must be current for the printer to
     * count as online.
     */
    public function probe(PrinterTarget $target): PrinterStatus
    {
        if ($target->deviceId === null) {
            return PrinterStatus::unknown(
                'No print agent is assigned to this printer. Assign one, or configure a direct '
                . 'IPP connection instead.',
                $this->name()
            );
        }

        $device = $this->db->selectOne(
            'SELECT id, name, status, last_seen_at, poll_interval_secs, agent_version
             FROM devices WHERE id = ?',
            [$target->deviceId]
        );

        if ($device === null) {
            return PrinterStatus::unknown('The assigned print agent no longer exists.', $this->name());
        }
        if ((string) $device['status'] === 'disabled') {
            return PrinterStatus::offline('The print agent for this location is disabled.', null, $this->name());
        }

        $lastSeen = $device['last_seen_at'];
        if ($lastSeen === null) {
            return PrinterStatus::offline(
                'The print agent "' . $device['name'] . '" has never connected.',
                null,
                $this->name()
            );
        }

        $secondsSince = time() - strtotime((string) $lastSeen . ' UTC');
        // Allow three missed polls before declaring the agent gone, so a single
        // slow cycle does not take a whole location offline.
        $tolerance = max(30, ((int) $device['poll_interval_secs']) * 3);

        if ($secondsSince > $tolerance) {
            return PrinterStatus::offline(
                sprintf(
                    'The print agent "%s" has not checked in for %s.',
                    $device['name'],
                    $this->humanDuration($secondsSince)
                ),
                null,
                $this->name()
            );
        }

        // The agent's most recent report for THIS printer.
        $report = $this->db->selectOne(
            'SELECT status, state_reason, message, checked_at, latency_ms
             FROM printer_status_checks
             WHERE printer_id = ? AND driver = ?
             ORDER BY id DESC LIMIT 1',
            [$target->printerId, 'agent']
        );

        if ($report === null) {
            return PrinterStatus::unknown(
                'The print agent is connected but has not yet reported this printer\'s state.',
                $this->name()
            );
        }

        $reportAge = time() - strtotime((string) $report['checked_at'] . ' UTC');
        $staleAfter = (int) Config::get('printing.queue.health_stale_after', 180);

        if ($reportAge > $staleAfter) {
            return PrinterStatus::unknown(
                sprintf('The last printer report from the agent is %s old.', $this->humanDuration($reportAge)),
                $this->name()
            );
        }

        $reasons = [];
        if (is_string($report['state_reason']) && $report['state_reason'] !== '') {
            $reasons = array_map('trim', explode(',', $report['state_reason']));
        }

        $message = (string) ($report['message'] ?? '');

        return match ((string) $report['status']) {
            'online' => PrinterStatus::online(
                $message !== '' ? $message : 'Reported ready by the location agent.',
                $report['latency_ms'] === null ? null : (int) $report['latency_ms'],
                $reasons,
                $this->name()
            ),
            'error' => PrinterStatus::error(
                $message !== '' ? $message : 'The location agent reported a printer error.',
                $reasons,
                $this->name()
            ),
            'offline' => PrinterStatus::offline(
                $message !== '' ? $message : 'The location agent cannot reach this printer.',
                null,
                $this->name()
            ),
            default => PrinterStatus::unknown(
                $message !== '' ? $message : 'The location agent could not determine the printer state.',
                $this->name()
            ),
        };
    }

    /**
     * Capabilities the agent read from the printer itself, via the local CUPS
     * queue's IPP attributes. Stored by the API when the agent reports them.
     */
    public function capabilities(PrinterTarget $target): PrinterCapabilities
    {
        $rows = $this->db->select(
            "SELECT capability, value, is_supported, is_default, evidence
             FROM printer_capabilities
             WHERE printer_id = ? AND source = 'probed'",
            [$target->printerId]
        );

        if ($rows === []) {
            return PrinterCapabilities::unsupported(
                'The location agent has not yet reported this printer\'s capabilities. '
                . 'Run a capability probe from the printer screen.'
            );
        }

        $grouped = ['paper_size' => [], 'color_mode' => [], 'duplex' => [], 'orientation' => []];
        $defaults = [];

        foreach ($rows as $row) {
            $capability = (string) $row['capability'];
            if (!isset($grouped[$capability])) {
                continue;
            }
            if ((int) $row['is_supported'] === 1) {
                $grouped[$capability][] = (string) $row['value'];
            }
            if ((int) $row['is_default'] === 1) {
                $defaults[$capability] = (string) $row['value'];
            }
        }

        return PrinterCapabilities::reported(
            paperSizes: $grouped['paper_size'],
            colorModes: $grouped['color_mode'],
            duplexModes: $grouped['duplex'],
            orientations: $grouped['orientation'] !== [] ? $grouped['orientation'] : ['portrait', 'landscape'],
            documentFormats: [],
            defaults: $defaults,
            raw: [],
            makeAndModel: $target->model
        );
    }

    /**
     * The agent renders every format locally with LibreOffice and the printer
     * driver, so it accepts anything the upload policy allows.
     */
    public function acceptsFormat(PrinterTarget $target, string $extension): bool
    {
        $allowed = array_keys((array) Config::get('uploads.allowed', []));
        return in_array(strtolower($extension), $allowed, true);
    }

    /**
     * Make the job claimable by the agent.
     *
     * There is no outbound connection to make: the agent polls. This returns
     * confirmed:false deliberately — the job has been handed to the queue, not
     * to the printer, and only the agent's report can say it printed.
     */
    public function submit(PrinterTarget $target, PrintJobSpec $spec, string $documentPath): SubmitResult
    {
        if ($target->deviceId === null) {
            return SubmitResult::permanentFailure(
                'No print agent is assigned to this printer, so there is nothing to collect the job.',
                'agent_not_assigned'
            );
        }

        $device = $this->db->selectOne(
            'SELECT id, name, status, last_seen_at, poll_interval_secs FROM devices WHERE id = ?',
            [$target->deviceId]
        );

        if ($device === null || (string) $device['status'] === 'disabled') {
            return SubmitResult::permanentFailure(
                'The print agent for this printer is unavailable.',
                'agent_unavailable'
            );
        }

        if ($device['last_seen_at'] === null) {
            return SubmitResult::transientFailure(
                'The print agent has not connected yet.',
                'agent_never_seen'
            );
        }

        $secondsSince = time() - strtotime((string) $device['last_seen_at'] . ' UTC');
        $tolerance = max(30, ((int) $device['poll_interval_secs']) * 3);
        if ($secondsSince > $tolerance) {
            return SubmitResult::transientFailure(
                sprintf(
                    'The print agent last checked in %s ago; waiting for it to reconnect.',
                    $this->humanDuration($secondsSince)
                ),
                'agent_stale'
            );
        }

        if (!is_file($documentPath)) {
            return SubmitResult::permanentFailure('The document is missing from storage.', 'document_missing');
        }

        $this->db->execute(
            'UPDATE print_jobs SET device_id = ? WHERE job_number = ?',
            [$target->deviceId, $spec->jobNumber]
        );

        return SubmitResult::accepted(
            remoteJobId: null,
            message: sprintf(
                'Queued for the "%s" agent, which polls every %d seconds.',
                $device['name'],
                (int) $device['poll_interval_secs']
            ),
            confirmed: false,
            context: ['device_id' => $target->deviceId, 'poll_interval' => (int) $device['poll_interval_secs']]
        );
    }

    /**
     * Job state comes from what the agent last reported, recorded as job_events
     * by the agent API.
     */
    public function jobStatus(PrinterTarget $target, string $remoteJobId): JobStatusResult
    {
        $job = $this->db->selectOne(
            'SELECT status, error_message, remote_job_id FROM print_jobs WHERE job_number = ? OR remote_job_id = ?',
            [$remoteJobId, $remoteJobId]
        );

        if ($job === null) {
            return JobStatusResult::unknown('No record of this job.');
        }

        return match ((string) $job['status']) {
            'completed' => JobStatusResult::of(JobStatusResult::COMPLETED, 'The agent confirmed the job printed.'),
            'failed' => JobStatusResult::of(
                JobStatusResult::ABORTED,
                (string) ($job['error_message'] ?? 'The agent reported a failure.')
            ),
            'cancelled' => JobStatusResult::of(JobStatusResult::CANCELLED, 'The job was cancelled.'),
            'printing', 'processing' => JobStatusResult::of(JobStatusResult::PROCESSING, 'The agent is printing this job.'),
            default => JobStatusResult::of(JobStatusResult::PENDING, 'Waiting for the agent to collect this job.'),
        };
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            return $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        $days = intdiv($seconds, 86400);
        return $days . ' day' . ($days === 1 ? '' : 's');
    }
}

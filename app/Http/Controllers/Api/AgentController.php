<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Repositories\DeviceRepository;
use App\Repositories\PrinterCapabilityRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintFileRepository;
use App\Repositories\PrintJobRepository;
use App\Services\AuditService;
use App\Services\FileService;
use App\Services\PrintQueueService;

/**
 * The print agent API.
 *
 * Every endpoint here is scoped to the device the bearer token belongs to. An
 * agent can only ever see, claim, download and report on jobs for the printers
 * at its own location — nothing about any other site is reachable, even with a
 * valid token.
 *
 * The flow is entirely agent-initiated (outbound HTTPS), which is what removes
 * the need to expose printer ports or run a VPN:
 *
 *   POST /api/agent/heartbeat   — "I am alive, here is my printers' state"
 *   POST /api/agent/claim       — "give me a job"
 *   GET  /api/agent/jobs/{n}/document — download the file to print
 *   POST /api/agent/jobs/{n}/status   — "printing" / "done" / "failed"
 *   POST /api/agent/capabilities      — "here is what CUPS says this printer does"
 */
final class AgentController extends Controller
{
    public function __construct(
        private Database $db,
        private DeviceRepository $devices,
        private PrinterRepository $printers,
        private PrintJobRepository $jobs,
        private PrintFileRepository $fileRepository,
        private PrinterCapabilityRepository $capabilities,
        private PrintQueueService $queue,
        private FileService $files,
        private AuditService $audit
    ) {
    }

    /**
     * POST /api/agent/heartbeat
     *
     * The agent reports itself and the state of each printer it manages, as
     * read from the local CUPS queue. This is what makes the customer-facing
     * "is the printer online?" answer real rather than assumed.
     */
    public function heartbeat(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        if (!is_int($deviceId)) {
            return $this->fail('This token is not bound to a print agent.', 403);
        }

        $device = $this->devices->find($deviceId);
        if ($device === null) {
            return $this->fail('That print agent no longer exists.', 404);
        }
        if ((string) $device['status'] === 'disabled') {
            return $this->fail('This print agent has been disabled.', 403);
        }

        $this->devices->heartbeat(
            $deviceId,
            $request->ip(),
            $request->string('agent_version') ?: null,
            $request->string('platform') ?: null
        );

        // Printer reports, keyed by the printer's code so the agent never needs
        // to know internal ids.
        $reports = $request->array('printers');
        $applied = 0;
        $unknown = [];

        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $code = (string) ($report['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $printer = $this->printers->findByCode($code);

            // Scope check: the printer must belong to THIS device.
            if ($printer === null || $printer->int('device_id') !== $deviceId) {
                $unknown[] = $code;
                continue;
            }

            $status = (string) ($report['status'] ?? 'unknown');
            if (!in_array($status, ['online', 'offline', 'error', 'unknown'], true)) {
                $status = 'unknown';
            }

            $reasons = array_values(array_filter(array_map(
                static fn ($r): string => substr(trim((string) $r), 0, 60),
                (array) ($report['state_reasons'] ?? [])
            )));

            $message = substr((string) ($report['message'] ?? ''), 0, 500);
            $latency = isset($report['latency_ms']) ? max(0, (int) $report['latency_ms']) : null;

            $this->printers->recordStatusCheck(
                $printer->id(),
                $status,
                'agent',
                $latency,
                $reasons === [] ? null : implode(', ', $reasons),
                $message
            );

            $update = ['status' => $status];
            if ($status === 'online') {
                $update['last_seen_at'] = gmdate('Y-m-d H:i:s');
                $update['consecutive_failures'] = 0;
                $update['last_error'] = $reasons === [] ? null : implode(', ', $reasons);
            } else {
                $update['last_error'] = $message !== '' ? $message : 'Reported ' . $status . ' by the agent.';
                $update['last_error_at'] = gmdate('Y-m-d H:i:s');
            }

            // Never override a deliberate maintenance state with an automated
            // report — an operator put it there on purpose.
            if ($printer->status() !== 'maintenance') {
                $this->printers->updateById($printer->id(), $update);
            }

            $applied++;
        }

        $printerIds = $this->devices->printerIds($deviceId);
        $pending = $printerIds === [] ? 0 : (int) $this->db->scalar(
            "SELECT COUNT(*) FROM print_jobs
             WHERE printer_id IN (" . implode(',', array_fill(0, count($printerIds), '?')) . ")
               AND status IN ('queued','paid')
               AND payment_status IN ('not_required','paid')
               AND available_at <= UTC_TIMESTAMP()",
            $printerIds
        );

        return $this->json([
            'success' => true,
            'device' => [
                'id' => $deviceId,
                'name' => (string) $device['name'],
                'poll_interval' => (int) $device['poll_interval_secs'],
            ],
            'printers_updated' => $applied,
            'unknown_printers' => $unknown,
            'pending_jobs' => $pending,
            'server_time' => gmdate('c'),
        ]);
    }

    /**
     * POST /api/agent/claim
     *
     * Atomically claim the next printable job for one of this device's
     * printers. Returns everything the agent needs to render and print, and
     * nothing it does not.
     */
    public function claim(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        if (!is_int($deviceId)) {
            return $this->fail('This token is not bound to a print agent.', 403);
        }

        $printerIds = $this->devices->printerIds($deviceId);
        if ($printerIds === []) {
            return $this->json(['success' => true, 'job' => null, 'reason' => 'No printers are assigned to this agent.']);
        }

        $leaseSeconds = (int) Config::get('printing.queue.lease_seconds', 180);
        $leaseToken = bin2hex(random_bytes(16));

        $job = $this->jobs->claimNext($printerIds, $leaseToken, $leaseSeconds);

        if ($job === null) {
            return $this->json(['success' => true, 'job' => null]);
        }

        $this->db->execute('UPDATE print_jobs SET device_id = ? WHERE id = ?', [$deviceId, (int) $job['id']]);

        $this->db->insert('job_events', [
            'job_id' => (int) $job['id'],
            'from_status' => PrintJob::STATUS_QUEUED,
            'to_status' => PrintJob::STATUS_PROCESSING,
            'actor_type' => 'agent',
            'actor_id' => (string) $deviceId,
            'message' => 'Claimed by the location print agent.',
        ]);

        $spec = \App\Services\Printer\PrintJobSpec::fromJobRow($job);

        return $this->json([
            'success' => true,
            'job' => [
                'job_number' => (string) $job['job_number'],
                'lease_token' => $leaseToken,
                'lease_expires_in' => $leaseSeconds,
                'printer_code' => (string) $this->printerCode((int) $job['printer_id']),
                'queue_name' => $job['queue_name'] ?? null,
                'document' => [
                    'name' => (string) ($job['original_name'] ?? 'document'),
                    'extension' => (string) ($job['extension'] ?? ''),
                    'mime_type' => (string) ($job['mime_type'] ?? 'application/octet-stream'),
                    'url' => '/api/agent/jobs/' . rawurlencode((string) $job['job_number']) . '/document',
                ],
                'options' => [
                    'copies' => (int) $job['copies'],
                    'color_mode' => (string) $job['color_mode'],
                    'paper_size' => (string) $job['paper_size'],
                    'orientation' => (string) $job['orientation'],
                    'duplex' => (string) $job['duplex'],
                    'page_range' => (string) $job['page_range'],
                    'document_pages' => (int) $job['document_pages'],
                ],
                // Ready-made CUPS options, so the agent does not re-derive them
                // and risk disagreeing with what the customer was charged for.
                'cups_options' => $spec->toCupsOptions(),
                'ipp_attributes' => $spec->toIppAttributes(),
            ],
        ]);
    }

    /**
     * GET /api/agent/jobs/{number}/document
     *
     * Streams the document. Access is scoped to the claiming device AND
     * requires the lease token, so a leaked job number is not enough to pull a
     * customer's file.
     */
    public function document(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        $jobNumber = (string) $request->routeParam('number');
        $leaseToken = (string) ($request->header('X-Lease-Token', '') ?: $request->string('lease_token'));

        $job = $this->jobs->findByNumber($jobNumber);
        if ($job === null) {
            return $this->fail('That job was not found.', 404);
        }

        if ($job->int('device_id') !== $deviceId) {
            $this->audit->security('agent.document_scope_violation', 'An agent requested a document for another location\'s job.', [
                'device_id' => $deviceId,
                'job_number' => $jobNumber,
            ]);
            return $this->fail('That job was not found.', 404);
        }

        if ($leaseToken === '' || !hash_equals($job->string('lease_token'), $leaseToken)) {
            return $this->fail('The lease for this job is not valid. Claim it again.', 409);
        }

        if (!$job->isPrintable()) {
            return $this->fail('This job is not paid for.', 402);
        }

        $file = $this->fileRepository->find($job->int('file_id'));
        if ($file === null || (int) $file['is_deleted'] === 1) {
            return $this->fail('The document is no longer available.', 410);
        }

        $path = $this->files->safePath($file);
        if ($path === null || !is_file($path)) {
            return $this->fail('The document could not be read.', 500);
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $this->fail('The document could not be read.', 500);
        }

        return Response::make($contents, 200, [
            'Content-Type' => (string) $file['mime_type'],
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'attachment; filename="'
                . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['original_name']) . '"',
            'X-Document-SHA256' => (string) $file['sha256'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * POST /api/agent/jobs/{number}/status
     *
     * The agent reports what actually happened. This — not an assumption on
     * the server — is what moves a job to completed or failed.
     */
    public function reportStatus(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        $jobNumber = (string) $request->routeParam('number');

        $job = $this->jobs->findByNumber($jobNumber);
        if ($job === null) {
            return $this->fail('That job was not found.', 404);
        }

        if ($job->int('device_id') !== $deviceId) {
            return $this->fail('That job was not found.', 404);
        }

        $data = Validator::make($request->all(), [
            'status' => 'required|in:printing,completed,failed',
            'message' => 'nullable|string|max:1000',
            'remote_job_id' => 'nullable|string|max:64',
            'error_code' => 'nullable|string|max:60',
            'retryable' => 'nullable|bool',
            'lease_token' => 'nullable|string|max:64',
        ])->validated();

        $leaseToken = (string) ($data['lease_token'] ?? $request->header('X-Lease-Token', ''));
        if ($leaseToken === '' || !hash_equals($job->string('lease_token'), $leaseToken)) {
            return $this->fail('The lease for this job is not valid. Claim it again.', 409);
        }

        $status = (string) $data['status'];
        $message = (string) ($data['message'] ?? '');
        $actorId = (string) $deviceId;

        return match ($status) {
            'printing' => (function () use ($job, $data, $message, $actorId): Response {
                $ok = $this->queue->transition(
                    $job->id(),
                    PrintJob::STATUS_PRINTING,
                    'agent',
                    $actorId,
                    $message !== '' ? $message : 'The agent submitted the job to the printer.',
                    ['remote_job_id' => $data['remote_job_id'] ?? null]
                );
                // Extend the lease: rendering plus printing can outlast the
                // original claim window on a big document.
                $this->extendLease($job->id());
                return $ok
                    ? $this->ok('Recorded as printing.')
                    : $this->fail('That status change is not allowed from the job\'s current state.', 409);
            })(),

            'completed' => (function () use ($job, $message, $actorId): Response {
                $ok = $this->queue->completeJob(
                    $job->id(),
                    $message !== '' ? $message : 'The agent confirmed the job printed.',
                    'agent',
                    $actorId
                );
                return $ok
                    ? $this->ok('Recorded as completed.')
                    : $this->fail('That status change is not allowed from the job\'s current state.', 409);
            })(),

            default => (function () use ($job, $data, $message, $actorId): Response {
                $retryable = (bool) ($data['retryable'] ?? true);
                $reason = $message !== '' ? $message : 'The agent reported a failure.';
                $errorCode = (string) ($data['error_code'] ?? 'agent_reported');

                $this->db->insert('job_events', [
                    'job_id' => $job->id(),
                    'from_status' => $job->status(),
                    'to_status' => $job->status(),
                    'actor_type' => 'agent',
                    'actor_id' => $actorId,
                    'message' => $reason,
                ]);

                $this->queue->failJob($job->id(), $reason, $errorCode, $retryable);

                return $this->ok($retryable ? 'Recorded; the job will be retried.' : 'Recorded as failed.');
            })(),
        };
    }

    /**
     * POST /api/agent/capabilities
     *
     * The agent reports what CUPS says a printer supports. Recorded as
     * 'probed', which is a verified source — these values may be offered to
     * customers.
     */
    public function reportCapabilities(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        $code = $request->string('printer_code');

        $printer = $this->printers->findByCode($code);
        if ($printer === null || $printer->int('device_id') !== $deviceId) {
            return $this->fail('That printer is not managed by this agent.', 404);
        }

        $paperSizes = $this->filterKnown(
            $request->array('paper_sizes'),
            array_keys((array) Config::get('printing.paper_sizes', []))
        );
        $colorModes = $this->filterKnown($request->array('color_modes'), ['bw', 'color']);
        $duplexModes = $this->filterKnown($request->array('duplex_modes'), ['single', 'double']);
        $orientations = $this->filterKnown($request->array('orientations'), ['portrait', 'landscape']);

        if ($paperSizes === [] && $colorModes === []) {
            return $this->fail('The report contained no recognisable capabilities.', 422);
        }

        $evidence = sprintf(
            'Reported by the "%s" print agent from the local CUPS queue at %s.',
            $deviceId,
            gmdate('c')
        );

        $this->capabilities->clearProbed($printer->id());

        $defaults = (array) $request->input('defaults', []);
        $recorded = 0;

        foreach ($paperSizes as $size) {
            $this->capabilities->record(
                $printer->id(),
                'paper_size',
                $size,
                true,
                'probed',
                ($defaults['paper_size'] ?? '') === $size,
                null,
                $evidence
            );
            $recorded++;
        }
        foreach ($colorModes as $mode) {
            $this->capabilities->record(
                $printer->id(),
                'color_mode',
                $mode,
                true,
                'probed',
                ($defaults['color_mode'] ?? '') === $mode,
                null,
                $evidence
            );
            $recorded++;
        }
        foreach ($duplexModes as $mode) {
            $this->capabilities->record(
                $printer->id(),
                'duplex',
                $mode,
                true,
                'probed',
                ($defaults['duplex'] ?? '') === $mode,
                null,
                $evidence
            );
            $recorded++;
        }
        foreach (($orientations !== [] ? $orientations : ['portrait']) as $orientation) {
            $this->capabilities->record(
                $printer->id(),
                'orientation',
                $orientation,
                true,
                'probed',
                $orientation === 'portrait',
                null,
                $evidence
            );
            $recorded++;
        }

        $this->printers->updateById($printer->id(), [
            'capabilities_verified_at' => gmdate('Y-m-d H:i:s'),
            'supports_color' => in_array('color', $colorModes, true) ? 1 : 0,
            'supports_duplex' => in_array('double', $duplexModes, true) ? 1 : 0,
            'model' => $request->string('make_and_model') ?: $printer->string('model'),
        ]);

        $this->audit->log(
            'printer.capabilities_reported',
            sprintf(
                'The agent reported %d verified capabilities for "%s" (colour: %s, duplex: %s).',
                $recorded,
                $printer->string('name'),
                in_array('color', $colorModes, true) ? 'yes' : 'no',
                in_array('double', $duplexModes, true) ? 'yes' : 'no'
            ),
            'printer',
            (string) $printer->id()
        );

        return $this->ok(sprintf('Recorded %d capabilities.', $recorded), ['recorded' => $recorded]);
    }

    /** GET /api/agent/config — what this agent should be managing. */
    public function config(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        if (!is_int($deviceId)) {
            return $this->fail('This token is not bound to a print agent.', 403);
        }

        $device = $this->devices->find($deviceId);
        if ($device === null) {
            return $this->fail('That print agent no longer exists.', 404);
        }

        $printers = $this->db->select(
            'SELECT code, name, queue_name, model, capability_profile, is_enabled
             FROM printers WHERE device_id = ? AND deleted_at IS NULL',
            [$deviceId]
        );

        return $this->json([
            'success' => true,
            'device' => [
                'id' => $deviceId,
                'uid' => (string) $device['device_uid'],
                'name' => (string) $device['name'],
                'poll_interval' => (int) $device['poll_interval_secs'],
                'status' => (string) $device['status'],
            ],
            'printers' => array_map(static fn (array $p): array => [
                'code' => (string) $p['code'],
                'name' => (string) $p['name'],
                'queue_name' => $p['queue_name'],
                'model' => $p['model'],
                'profile' => (string) $p['capability_profile'],
                'enabled' => (bool) (int) $p['is_enabled'],
            ], $printers),
            'server_time' => gmdate('c'),
        ]);
    }

    private function extendLease(int $jobId): void
    {
        $this->db->execute(
            'UPDATE print_jobs
             SET lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)
             WHERE id = ?',
            [(int) Config::get('printing.queue.lease_seconds', 180) * 3, $jobId]
        );
    }

    private function printerCode(int $printerId): string
    {
        return (string) $this->db->scalar('SELECT code FROM printers WHERE id = ?', [$printerId]);
    }

    /**
     * @param array<int,mixed> $values
     * @param array<int,string> $allowed
     * @return array<int,string>
     */
    private function filterKnown(array $values, array $allowed): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if (in_array($value, $allowed, true)) {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }
}

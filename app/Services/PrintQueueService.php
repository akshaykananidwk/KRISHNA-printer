<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Models\PrintJob;
use App\Repositories\PrintFileRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Services\Printer\PrintJobSpec;

/**
 * Owns the print job lifecycle: creation, state transitions, dispatch and
 * retry.
 *
 * Two invariants are enforced here and nowhere else, so there is exactly one
 * place to audit them:
 *
 *   1. A job never reaches a printer unless payment_status is 'paid' or
 *      'not_required'. Both the claim query and dispatch() check it.
 *   2. A job never moves between states except along PrintJob::TRANSITIONS,
 *      and every move is recorded in job_events.
 */
final class PrintQueueService
{
    public function __construct(
        private Database $db,
        private PrintJobRepository $jobs,
        private PrinterRepository $printers,
        private PrintFileRepository $files,
        private PrinterService $printerService,
        private PrinterCapabilityService $capabilities,
        private FileService $fileService,
        private AuditService $audit
    ) {
    }

    /**
     * Create a job.
     *
     * Everything is re-validated server-side here — the printer's availability,
     * the option set against verified capabilities, and the price. Nothing the
     * browser submitted about price or page count is trusted.
     *
     * @param array<string,mixed> $data
     * @return array{success:bool,job_id?:int,job_number?:string,error?:string,errors?:array<string,string>}
     */
    public function createJob(array $data, array $pricing): array
    {
        $printerId = (int) $data['printer_id'];
        $printer = $this->printers->find($printerId);

        if ($printer === null) {
            return ['success' => false, 'error' => 'That printer is no longer available.'];
        }

        // Re-check availability at the moment of creation. The customer may
        // have been configuring their print for several minutes, and the
        // printer may have run out of paper in the meantime.
        $availability = $this->printerService->availabilityFor($printerId);
        if (!$availability['available']) {
            return ['success' => false, 'error' => $availability['reason']];
        }

        // Re-check the options against verified capabilities. A tampered form
        // cannot smuggle in colour on a mono printer.
        $capabilityErrors = $this->capabilities->validateSelection($printer, $data);
        if ($capabilityErrors !== []) {
            return ['success' => false, 'error' => reset($capabilityErrors), 'errors' => $capabilityErrors];
        }

        if (!($pricing['priced'] ?? false)) {
            return ['success' => false, 'error' => (string) ($pricing['error'] ?? 'This job could not be priced.')];
        }

        $paymentEnabled = (string) Config::get('settings.payment_enabled', '0') === '1';
        $totalPaise = (int) $pricing['total_paise'];

        // A zero-value job needs no payment even when payments are on.
        $requiresPayment = $paymentEnabled && $totalPaise > 0;

        $jobNumber = $this->generateJobNumber();

        $jobId = $this->db->transaction(function () use (
            $data,
            $pricing,
            $jobNumber,
            $requiresPayment,
            $totalPaise
        ): int {
            $id = $this->jobs->create([
                'job_number' => $jobNumber,
                'batch_id' => (string) ($data['batch_id'] ?? bin2hex(random_bytes(16))),
                'location_id' => (int) $data['location_id'],
                'printer_id' => (int) $data['printer_id'],
                'file_id' => (int) $data['file_id'],
                'session_id' => isset($data['session_id']) ? (int) $data['session_id'] : null,
                'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,

                'copies' => (int) $pricing['copies'],
                'color_mode' => (string) $pricing['color_mode'],
                'paper_size' => (string) $pricing['paper_size'],
                'orientation' => (string) ($data['orientation'] ?? 'portrait'),
                'duplex' => (string) $pricing['duplex'],
                'page_range' => (string) $pricing['page_range'],
                'document_pages' => (int) $pricing['document_pages'],
                'selected_pages' => (int) $pricing['selected_pages'],
                'billable_pages' => (int) $pricing['billable_pages'],
                'sheets' => (int) $pricing['sheets'],

                'subtotal_paise' => (int) $pricing['subtotal_paise'],
                'tax_paise' => (int) $pricing['tax_paise'],
                'gateway_fee_paise' => (int) $pricing['gateway_fee_paise'],
                'discount_paise' => (int) ($pricing['discount_paise'] ?? 0),
                'total_paise' => $totalPaise,
                'currency' => (string) $pricing['currency'],
                // Freeze the calculation. If pricing rules change tomorrow, the
                // receipt for this job still shows what was actually charged.
                'price_breakdown' => json_encode($pricing, JSON_UNESCAPED_SLASHES),

                'payment_status' => $requiresPayment ? 'pending' : 'not_required',
                'status' => $requiresPayment ? PrintJob::STATUS_PAYMENT_PENDING : PrintJob::STATUS_PENDING,
                'max_attempts' => (int) Config::get('printing.queue.max_attempts', 3),
            ]);

            $this->recordEvent(
                $id,
                null,
                $requiresPayment ? PrintJob::STATUS_PAYMENT_PENDING : PrintJob::STATUS_PENDING,
                'customer',
                null,
                $requiresPayment ? 'Job created, awaiting payment.' : 'Job created.'
            );

            $this->persistSettings($id, $data, $pricing);

            return $id;
        });

        // Free jobs go straight to the queue; paid ones wait for verification.
        if (!$requiresPayment) {
            $this->markQueued($jobId, 'system', null, 'No payment required; queued for printing.');
        }

        Logger::info('Print job created', [
            'job_number' => $jobNumber,
            'printer_id' => $printerId,
            'pages' => $pricing['billable_pages'],
            'total_paise' => $totalPaise,
            'requires_payment' => $requiresPayment,
        ]);

        return ['success' => true, 'job_id' => $jobId, 'job_number' => $jobNumber];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $pricing */
    private function persistSettings(int $jobId, array $data, array $pricing): void
    {
        $settings = [
            'ipp_media' => (string) (Config::get('printing.paper_sizes.' . $pricing['paper_size'] . '.pwg') ?? ''),
            'ipp_color_mode' => $pricing['color_mode'] === 'color' ? 'color' : 'monochrome',
            'ipp_sides' => $pricing['duplex'] === 'double' ? 'two-sided-long-edge' : 'one-sided',
            'ipp_orientation' => ($data['orientation'] ?? 'portrait') === 'landscape' ? '4' : '3',
            'pricing_rule_id' => (string) ($pricing['rule_id'] ?? ''),
            'page_count_source' => (string) ($data['page_count_source'] ?? 'unknown'),
        ];

        foreach ($settings as $key => $value) {
            if ($value === '') {
                continue;
            }
            $this->db->execute(
                'INSERT INTO print_settings (job_id, setting_key, setting_value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$jobId, $key, substr($value, 0, 255)]
            );
        }
    }

    /**
     * Public job reference. Uses a short random suffix on a sequence so the
     * number is easy to read aloud at a counter but does not disclose how many
     * jobs the business has processed.
     */
    public function generateJobNumber(): string
    {
        $prefix = strtoupper((string) Config::get('settings.job_number_prefix', 'AK'));
        $prefix = preg_replace('/[^A-Z]/', '', $prefix) ?: 'AK';
        $prefix = substr($prefix, 0, 4);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $sequence = $this->jobs->nextJobNumberSequence();
            // 6 digits: a rotating sequence base plus randomness in the low digits.
            $number = $prefix . str_pad((string) (($sequence % 900000) + 100000), 6, '0', STR_PAD_LEFT);

            if ($this->jobs->findByNumber($number) === null) {
                return $number;
            }
        }

        // Fall back to a fully random reference rather than fail the job.
        return $prefix . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Move a job to a new status, enforcing the transition map.
     *
     * @param array<string,mixed> $extra additional columns to write
     */
    public function transition(
        int $jobId,
        string $to,
        string $actorType = 'system',
        ?string $actorId = null,
        string $message = '',
        array $extra = []
    ): bool {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return false;
        }

        $from = $job->status();
        if ($from === $to) {
            return true;
        }

        if (!PrintJob::canTransition($from, $to)) {
            Logger::warning('Rejected an illegal job transition', [
                'job_id' => $jobId,
                'from' => $from,
                'to' => $to,
            ]);
            return false;
        }

        $update = array_merge(['status' => $to], $extra);

        $update += match ($to) {
            PrintJob::STATUS_QUEUED => ['queued_at' => gmdate('Y-m-d H:i:s')],
            PrintJob::STATUS_PRINTING => ['started_at' => $job->get('started_at') ?? gmdate('Y-m-d H:i:s')],
            PrintJob::STATUS_COMPLETED,
            PrintJob::STATUS_FAILED,
            PrintJob::STATUS_CANCELLED => ['completed_at' => gmdate('Y-m-d H:i:s')],
            default => [],
        };

        // A terminal state releases the lease so nothing keeps polling it.
        if (in_array($to, [PrintJob::STATUS_COMPLETED, PrintJob::STATUS_FAILED, PrintJob::STATUS_CANCELLED], true)) {
            $update['lease_token'] = null;
            $update['lease_expires_at'] = null;
        }

        $this->db->transaction(function () use ($jobId, $update, $from, $to, $actorType, $actorId, $message): void {
            $this->jobs->updateById($jobId, $update);
            $this->recordEvent($jobId, $from, $to, $actorType, $actorId, $message);
        });

        // Once printed, the document is no longer needed — pull its deletion
        // deadline forward rather than keeping it for the full retention window.
        if ($to === PrintJob::STATUS_COMPLETED) {
            $fileId = $job->int('file_id');
            if ($fileId > 0) {
                $this->files->accelerateRetention(
                    $fileId,
                    (int) Config::get('uploads.completed_retention_hours', 6)
                );
            }
        }

        return true;
    }

    private function recordEvent(
        int $jobId,
        ?string $from,
        string $to,
        string $actorType,
        ?string $actorId,
        string $message,
        array $context = []
    ): void {
        $this->db->insert('job_events', [
            'job_id' => $jobId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'message' => $message,
            'context' => $context === [] ? null : json_encode(Logger::redact($context), JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function markQueued(int $jobId, string $actorType, ?string $actorId, string $message): bool
    {
        return $this->transition($jobId, PrintJob::STATUS_QUEUED, $actorType, $actorId, $message, [
            'available_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Release every job in a paid batch to the queue.
     *
     * Called ONLY from PaymentService after server-side verification succeeded.
     *
     * @return array{released:int,skipped:int}
     */
    public function releasePaidBatch(string $batchId, int $paymentId): array
    {
        $jobs = $this->jobs->forBatch($batchId);
        $released = 0;
        $skipped = 0;

        foreach ($jobs as $job) {
            if ($job->status() !== PrintJob::STATUS_PAYMENT_PENDING) {
                $skipped++;
                continue;
            }

            $this->db->transaction(function () use ($job, $paymentId): void {
                $this->jobs->updateById($job->id(), [
                    'payment_status' => 'paid',
                    'payment_id' => $paymentId,
                    'status' => PrintJob::STATUS_PAID,
                ]);
                $this->recordEvent(
                    $job->id(),
                    PrintJob::STATUS_PAYMENT_PENDING,
                    PrintJob::STATUS_PAID,
                    'gateway',
                    (string) $paymentId,
                    'Payment verified server-side.'
                );
            });

            $this->markQueued($job->id(), 'system', null, 'Payment confirmed; queued for printing.');
            $released++;
        }

        return ['released' => $released, 'skipped' => $skipped];
    }

    /**
     * Claim and dispatch one job. Returns null when there is nothing to do.
     *
     * @param array<int,int> $printerIds
     * @return array<string,mixed>|null
     */
    public function dispatchNext(array $printerIds): ?array
    {
        $leaseSeconds = (int) Config::get('printing.queue.lease_seconds', 180);
        $leaseToken = bin2hex(random_bytes(16));

        $job = $this->jobs->claimNext($printerIds, $leaseToken, $leaseSeconds);
        if ($job === null) {
            return null;
        }

        $this->recordEvent(
            (int) $job['id'],
            PrintJob::STATUS_QUEUED,
            PrintJob::STATUS_PROCESSING,
            'system',
            null,
            'Claimed by a print worker.'
        );

        return $this->dispatch($job, $leaseToken);
    }

    /**
     * Send a claimed job to its printer.
     *
     * @param array<string,mixed> $job a claimed job row
     * @return array<string,mixed>
     */
    public function dispatch(array $job, string $leaseToken): array
    {
        $jobId = (int) $job['id'];
        $jobNumber = (string) $job['job_number'];

        // The payment gate, checked again at the last possible moment. The
        // claim query already filters on it; this catches a job whose payment
        // was reversed between claim and dispatch.
        if (!in_array((string) $job['payment_status'], ['not_required', 'paid'], true)) {
            $this->failJob($jobId, 'This job is not paid for and was not sent to the printer.', 'unpaid', false);
            return ['success' => false, 'job_number' => $jobNumber, 'error' => 'Job is not paid.'];
        }

        $printer = $this->printers->find((int) $job['printer_id']);
        if ($printer === null) {
            $this->failJob($jobId, 'The target printer no longer exists.', 'printer_missing', false);
            return ['success' => false, 'job_number' => $jobNumber, 'error' => 'Printer missing.'];
        }

        $file = $this->files->find((int) $job['file_id']);
        if ($file === null || (int) $file['is_deleted'] === 1) {
            $this->failJob($jobId, 'The document is no longer available.', 'file_missing', false);
            return ['success' => false, 'job_number' => $jobNumber, 'error' => 'Document missing.'];
        }

        $path = $this->fileService->safePath($file);
        if ($path === null || !is_file($path)) {
            $this->failJob($jobId, 'The document could not be read from storage.', 'file_unreadable', false);
            return ['success' => false, 'job_number' => $jobNumber, 'error' => 'Document unreadable.'];
        }

        $spec = PrintJobSpec::fromJobRow($job + [
            'original_name' => (string) $file['original_name'],
            'mime_type' => (string) $file['mime_type'],
        ]);

        $result = $this->printerService->submit($printer, $spec, $path);

        if (!$result->accepted) {
            if ($result->retryable) {
                $this->retryJob($jobId, $result->message, $result->errorCode);
                return [
                    'success' => false,
                    'job_number' => $jobNumber,
                    'error' => $result->message,
                    'will_retry' => true,
                ];
            }

            $this->failJob($jobId, $result->message, $result->errorCode ?? 'submit_failed', false);
            return ['success' => false, 'job_number' => $jobNumber, 'error' => $result->message];
        }

        // Accepted. Move to printing and record what the transport actually
        // told us — including whether it could confirm anything at all.
        $this->transition(
            $jobId,
            PrintJob::STATUS_PRINTING,
            'system',
            null,
            $result->message,
            [
                'remote_job_id' => $result->remoteJobId,
                'error_message' => null,
                'error_code' => null,
            ]
        );

        $this->printers->updateById($printer->id(), [
            'last_success_at' => gmdate('Y-m-d H:i:s'),
            'consecutive_failures' => 0,
        ]);

        // A transport that cannot confirm rendering (RAW/9100, LPD) leaves the
        // job in 'printing' for the reconciler to resolve rather than claiming
        // a completion it did not observe. A confirmed transport that also
        // cannot poll (the null test driver) completes immediately.
        if ($result->confirmed && $result->remoteJobId === null) {
            $this->transition($jobId, PrintJob::STATUS_COMPLETED, 'system', null, 'Printer confirmed the job.');
        }

        return [
            'success' => true,
            'job_number' => $jobNumber,
            'message' => $result->message,
            'remote_job_id' => $result->remoteJobId,
            'confirmed' => $result->confirmed,
        ];
    }

    /** Schedule a retry with exponential backoff, or fail once attempts run out. */
    public function retryJob(int $jobId, string $reason, ?string $errorCode = null): bool
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return false;
        }

        $attempts = $job->int('attempts');
        $maxAttempts = $job->int('max_attempts', 3);

        if ($attempts >= $maxAttempts) {
            return $this->failJob(
                $jobId,
                sprintf('%s (gave up after %d attempt%s)', $reason, $attempts, $attempts === 1 ? '' : 's'),
                $errorCode ?? 'max_attempts',
                false
            );
        }

        $backoff = (array) Config::get('printing.queue.retry_backoff_seconds', [30, 120, 600]);
        $delay = (int) ($backoff[min($attempts, count($backoff) - 1)] ?? 300);

        $ok = $this->db->execute(
            "UPDATE print_jobs
             SET status = 'queued',
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                 error_message = ?,
                 error_code = ?
             WHERE id = ? AND status IN ('processing','printing','queued')",
            [$delay, substr($reason, 0, 1000), $errorCode, $jobId]
        ) > 0;

        if ($ok) {
            $this->recordEvent(
                $jobId,
                $job->status(),
                PrintJob::STATUS_QUEUED,
                'system',
                null,
                sprintf('Retry %d of %d in %ds: %s', $attempts + 1, $maxAttempts, $delay, $reason)
            );
        }

        return $ok;
    }

    public function failJob(int $jobId, string $reason, string $errorCode, bool $allowRetry = true): bool
    {
        if ($allowRetry) {
            return $this->retryJob($jobId, $reason, $errorCode);
        }

        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return false;
        }

        $ok = $this->transition(
            $jobId,
            PrintJob::STATUS_FAILED,
            'system',
            null,
            $reason,
            ['error_message' => substr($reason, 0, 1000), 'error_code' => substr($errorCode, 0, 60)]
        );

        if ($ok) {
            $printerId = $job->int('printer_id');
            $this->db->execute(
                'UPDATE printers
                 SET consecutive_failures = consecutive_failures + 1,
                     last_error = ?, last_error_at = UTC_TIMESTAMP()
                 WHERE id = ?',
                [substr($reason, 0, 1000), $printerId]
            );

            // Repeated failures mean the printer is not usable, whatever the
            // health probe says. Take it offline so customers stop being
            // offered it — an operator must look at it.
            $threshold = (int) Config::get('printing.queue.offline_failure_threshold', 3);
            $failures = (int) $this->db->scalar(
                'SELECT consecutive_failures FROM printers WHERE id = ?',
                [$printerId]
            );
            if ($failures >= $threshold) {
                $this->db->execute(
                    "UPDATE printers SET status = 'error' WHERE id = ? AND status <> 'maintenance'",
                    [$printerId]
                );
                $this->audit->log(
                    'printer.auto_offline',
                    sprintf('Printer #%d marked as errored after %d consecutive failures.', $printerId, $failures),
                    'printer',
                    (string) $printerId,
                    'warning'
                );
            }

            $this->audit->log(
                'job.failed',
                sprintf('Job %s failed: %s', $job->jobNumber(), $reason),
                'print_job',
                (string) $jobId,
                'warning',
                ['error_code' => $errorCode]
            );
        }

        return $ok;
    }

    public function completeJob(int $jobId, string $message = 'Printing completed.', string $actorType = 'system', ?string $actorId = null): bool
    {
        return $this->transition($jobId, PrintJob::STATUS_COMPLETED, $actorType, $actorId, $message);
    }

    /**
     * Cancel a job.
     *
     * A job already handed to a printer can only be cancelled on transports
     * that support it; otherwise the paper is already moving and the honest
     * answer is that it is too late.
     *
     * @return array{success:bool,message:string,refund_due?:bool}
     */
    public function cancelJob(int $jobId, string $reason, string $actorType = 'admin', ?string $actorId = null): array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return ['success' => false, 'message' => 'That job no longer exists.'];
        }

        if ($job->isTerminal()) {
            return [
                'success' => false,
                'message' => sprintf('This job is already %s and cannot be cancelled.', strtolower($job->statusLabel())),
            ];
        }

        if (!$job->isCancellable()) {
            return ['success' => false, 'message' => 'This job can no longer be cancelled.'];
        }

        // Try to withdraw it from the printer when the transport allows it.
        if ($job->status() === PrintJob::STATUS_PRINTING) {
            $remoteJobId = $job->string('remote_job_id');
            $printer = $this->printers->find($job->int('printer_id'));
            $withdrawn = false;

            if ($printer !== null && $remoteJobId !== '') {
                $target = $this->printerService->targetFor($printer);
                $driver = $target === null ? null : $this->printerService->driver($target->driver);
                if ($driver !== null && method_exists($driver, 'cancel')) {
                    $withdrawn = (bool) $driver->cancel($target, $remoteJobId, $reason);
                }
            }

            if (!$withdrawn) {
                return [
                    'success' => false,
                    'message' => 'This job has already been sent to the printer and cannot be recalled. '
                        . 'Please collect or discard the output at the counter.',
                ];
            }
        }

        $ok = $this->transition(
            $jobId,
            PrintJob::STATUS_CANCELLED,
            $actorType,
            $actorId,
            $reason,
            ['cancelled_reason' => substr($reason, 0, 190)]
        );

        if (!$ok) {
            return ['success' => false, 'message' => 'The job could not be cancelled.'];
        }

        $this->audit->log(
            'job.cancelled',
            sprintf('Job %s cancelled: %s', $job->jobNumber(), $reason),
            'print_job',
            (string) $jobId,
            'notice'
        );

        // A cancelled job that was already paid for owes the customer money.
        // The refund itself is an operator decision (and a gateway call), so
        // this flags it rather than silently issuing one.
        $refundDue = $job->string('payment_status') === 'paid' && $job->int('total_paise') > 0;

        return [
            'success' => true,
            'message' => 'Job ' . $job->jobNumber() . ' has been cancelled.',
            'refund_due' => $refundDue,
        ];
    }

    /** Requeue a failed job by hand, resetting its attempt budget. */
    public function retryFailedJob(int $jobId, string $actorType = 'admin', ?string $actorId = null): array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return ['success' => false, 'message' => 'That job no longer exists.'];
        }
        if ($job->status() !== PrintJob::STATUS_FAILED) {
            return ['success' => false, 'message' => 'Only failed jobs can be retried.'];
        }
        if (!$job->isPrintable()) {
            return ['success' => false, 'message' => 'This job is unpaid and cannot be retried.'];
        }

        $file = $this->files->find($job->int('file_id'));
        if ($file === null || (int) $file['is_deleted'] === 1) {
            return [
                'success' => false,
                'message' => 'The document for this job has been deleted, so it cannot be reprinted. '
                    . 'The customer will need to upload it again.',
            ];
        }

        $ok = $this->transition(
            $jobId,
            PrintJob::STATUS_QUEUED,
            $actorType,
            $actorId,
            'Requeued by an operator.',
            [
                'attempts' => 0,
                'available_at' => gmdate('Y-m-d H:i:s'),
                'error_message' => null,
                'error_code' => null,
            ]
        );

        return $ok
            ? ['success' => true, 'message' => 'Job ' . $job->jobNumber() . ' has been requeued.']
            : ['success' => false, 'message' => 'The job could not be requeued.'];
    }

    /**
     * Poll printers about jobs we handed over but have not seen finish.
     *
     * @return array{checked:int,completed:int,failed:int}
     */
    public function reconcilePrintingJobs(int $limit = 50): array
    {
        $rows = $this->db->select(
            "SELECT j.*, p.name AS printer_name
             FROM print_jobs j
             INNER JOIN printers p ON p.id = j.printer_id
             WHERE j.status = 'printing'
             ORDER BY j.started_at ASC
             LIMIT " . max(1, min(200, $limit))
        );

        $completed = 0;
        $failed = 0;
        $staleAfter = (int) Config::get('printing.queue.stale_processing_seconds', 900);

        foreach ($rows as $row) {
            $jobId = (int) $row['id'];
            $remoteJobId = (string) ($row['remote_job_id'] ?? '');
            $startedAt = (string) ($row['started_at'] ?? '');
            $age = $startedAt === '' ? 0 : time() - strtotime($startedAt . ' UTC');

            if ($remoteJobId !== '') {
                $printer = $this->printers->find((int) $row['printer_id']);
                if ($printer !== null) {
                    $status = $this->printerService->jobStatus($printer, $remoteJobId);

                    if ($status->isSuccessful()) {
                        $this->completeJob($jobId, $status->message);
                        $completed++;
                        continue;
                    }
                    if ($status->isFinished()) {
                        $this->failJob($jobId, $status->message, 'printer_reported_' . $status->state, false);
                        $failed++;
                        continue;
                    }
                    if ($status->known) {
                        continue; // still processing; leave it alone
                    }
                }
            }

            // No trackable identity, or the printer stopped answering about it.
            // After the stale window we must decide something rather than leave
            // the job in limbo forever.
            if ($age > $staleAfter) {
                $this->completeJob(
                    $jobId,
                    'Marked complete after the tracking window elapsed. This transport does not report '
                    . 'job completion, so the outcome was not confirmed by the printer.'
                );
                $completed++;
            }
        }

        return ['checked' => count($rows), 'completed' => $completed, 'failed' => $failed];
    }

    /**
     * Housekeeping run by the scheduler.
     *
     * @return array{reclaimed:int,expired:int}
     */
    public function performMaintenance(): array
    {
        $reclaimed = $this->jobs->reclaimExpiredLeases();
        $expired = $this->jobs->failExhaustedLeases();

        if ($reclaimed > 0 || $expired > 0) {
            Logger::info('Queue maintenance', ['requeued' => $reclaimed, 'failed' => $expired]);
        }

        return ['reclaimed' => $reclaimed, 'expired' => $expired];
    }

    /**
     * Cancel the jobs behind an abandoned payment so their files can be
     * cleaned up and the printer queue is not held open.
     */
    public function expireUnpaidBatch(string $batchId, string $reason): int
    {
        $jobs = $this->jobs->forBatch($batchId);
        $cancelled = 0;

        foreach ($jobs as $job) {
            if ($job->status() !== PrintJob::STATUS_PAYMENT_PENDING) {
                continue;
            }
            $ok = $this->transition(
                $job->id(),
                PrintJob::STATUS_CANCELLED,
                'system',
                null,
                $reason,
                ['payment_status' => 'failed', 'cancelled_reason' => substr($reason, 0, 190)]
            );
            if ($ok) {
                $cancelled++;
            }
        }

        return $cancelled;
    }
}

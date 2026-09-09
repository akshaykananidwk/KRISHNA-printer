<?php
declare(strict_types=1);

namespace App\Services\Printer\Drivers;

use App\Core\Logger;
use App\Services\Printer\JobStatusResult;
use App\Services\Printer\PrinterCapabilities;
use App\Services\Printer\PrinterDriver;
use App\Services\Printer\PrinterStatus;
use App\Services\Printer\PrinterTarget;
use App\Services\Printer\PrintJobSpec;
use App\Services\Printer\SubmitResult;

/**
 * Discard transport for staging environments and end-to-end tests.
 *
 * It is honest about what it is: probe() reports a status that says "simulated",
 * and jobs are written to a spool directory instead of a printer, so nobody can
 * mistake a passing test for a working printer. Selecting this driver is an
 * explicit administrative choice, and the printer screen labels any printer
 * using it.
 */
final class NullDriver implements PrinterDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function description(): string
    {
        return 'Simulated printer (test only) — jobs are written to a spool directory, never printed.';
    }

    public function probe(PrinterTarget $target): PrinterStatus
    {
        // A simulated printer can be told to report offline, so the offline
        // path through the whole application is testable end to end.
        if ((bool) $target->option('simulate_offline', false)) {
            return PrinterStatus::offline('Simulated printer is set to report offline.', 1, $this->name());
        }
        if ((bool) $target->option('simulate_error', false)) {
            return PrinterStatus::error(
                'Simulated printer error.',
                (array) $target->option('simulate_reasons', ['media-empty']),
                $this->name()
            );
        }

        return PrinterStatus::online('Simulated printer is ready (no physical device).', 1, [], $this->name());
    }

    public function capabilities(PrinterTarget $target): PrinterCapabilities
    {
        return PrinterCapabilities::reported(
            paperSizes: (array) $target->option('paper_sizes', ['A4', 'A5', 'Letter']),
            colorModes: (array) $target->option('color_modes', ['bw']),
            duplexModes: (array) $target->option('duplex_modes', ['single']),
            orientations: ['portrait', 'landscape'],
            documentFormats: ['application/pdf'],
            defaults: ['paper_size' => 'A4', 'color_mode' => 'bw', 'duplex' => 'single'],
            raw: ['simulated' => true],
            makeAndModel: 'Simulated Printer'
        );
    }

    public function acceptsFormat(PrinterTarget $target, string $extension): bool
    {
        return true;
    }

    public function submit(PrinterTarget $target, PrintJobSpec $spec, string $documentPath): SubmitResult
    {
        if ((bool) $target->option('simulate_submit_failure', false)) {
            return SubmitResult::transientFailure('Simulated submission failure.', 'simulated_failure');
        }

        $spoolDir = (string) $target->option('spool_dir', sys_get_temp_dir() . '/kpms-spool');
        if (!is_dir($spoolDir)) {
            @mkdir($spoolDir, 0750, true);
        }

        $manifest = [
            'job_number' => $spec->jobNumber,
            'printer_id' => $target->printerId,
            'spec' => $spec->toArray(),
            'document' => basename($documentPath),
            'document_bytes' => is_file($documentPath) ? filesize($documentPath) : 0,
            'spooled_at' => gmdate('c'),
        ];

        $path = $spoolDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $spec->jobNumber) . '.json';
        @file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Logger::info('Simulated print job spooled', ['job' => $spec->jobNumber, 'path' => $path]);

        return SubmitResult::accepted(
            remoteJobId: 'sim-' . substr(hash('sha256', $spec->jobNumber), 0, 12),
            message: 'Simulated printer accepted the job (written to the spool directory, not printed).',
            confirmed: true,
            context: ['spool_file' => $path]
        );
    }

    public function jobStatus(PrinterTarget $target, string $remoteJobId): JobStatusResult
    {
        return JobStatusResult::of(JobStatusResult::COMPLETED, 'Simulated job completed.');
    }
}

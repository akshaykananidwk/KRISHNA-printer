<?php
declare(strict_types=1);

namespace App\Services\Printer\Drivers;

use App\Core\Config;
use App\Core\Logger;
use App\Services\Printer\Ipp\IppClient;
use App\Services\Printer\Ipp\IppDecoder;
use App\Services\Printer\Ipp\IppException;
use App\Services\Printer\JobStatusResult;
use App\Services\Printer\PrinterCapabilities;
use App\Services\Printer\PrinterDriver;
use App\Services\Printer\PrinterStatus;
use App\Services\Printer\PrinterTarget;
use App\Services\Printer\PrintJobSpec;
use App\Services\Printer\SubmitResult;

/**
 * IPP/IPPS transport (RFC 8011).
 *
 * This is the only direct transport that can genuinely answer "what do you
 * support?" and "did my job print?", which is why the capability probe and the
 * completion tracking both prefer it.
 */
final class IppDriver implements PrinterDriver
{
    public function name(): string
    {
        return 'ipp';
    }

    public function description(): string
    {
        return 'IPP / IPPS (Internet Printing Protocol) — supports capability discovery and job tracking.';
    }

    public function probe(PrinterTarget $target): PrinterStatus
    {
        $started = microtime(true);
        $client = new IppClient($target);

        try {
            $response = $client->getPrinterAttributes([
                'printer-state',
                'printer-state-reasons',
                'printer-state-message',
                'printer-is-accepting-jobs',
                'printer-make-and-model',
                'queued-job-count',
            ]);
        } catch (IppException $e) {
            $latency = (int) round((microtime(true) - $started) * 1000);
            return PrinterStatus::offline($e->getMessage(), $latency, $this->name());
        } catch (\Throwable $e) {
            Logger::warning('IPP probe threw an unexpected error', [
                'printer_id' => $target->printerId,
                'error' => $e->getMessage(),
            ]);
            return PrinterStatus::unknown('Probe failed: ' . $e->getMessage(), $this->name());
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        if (!$response->isSuccess()) {
            return PrinterStatus::error(
                'Printer rejected the status request: ' . $response->statusMessage(),
                [],
                $this->name()
            );
        }

        // printer-state (RFC 8011 §5.4.11): 3 = idle, 4 = processing, 5 = stopped
        $state = (int) $response->get('printer-state', 0);
        $reasons = array_values(array_filter(array_map(
            static fn ($r): string => (string) $r,
            $response->getList('printer-state-reasons')
        ), static fn (string $r): bool => $r !== ''));

        $accepting = $response->get('printer-is-accepting-jobs');
        $acceptingJobs = !is_bool($accepting) || $accepting;

        $stateMessage = (string) ($response->get('printer-state-message') ?? '');

        return match ($state) {
            3, 4 => PrinterStatus::online(
                $stateMessage !== '' ? $stateMessage : ($state === 4 ? 'Printing.' : 'Ready.'),
                $latency,
                $reasons,
                $this->name(),
                $acceptingJobs
            ),
            5 => PrinterStatus::error(
                $stateMessage !== '' ? $stateMessage : 'Printer is stopped.',
                $reasons,
                $this->name()
            ),
            default => PrinterStatus::unknown(
                'Printer reported an unrecognised state (' . $state . ').',
                $this->name()
            ),
        };
    }

    public function capabilities(PrinterTarget $target): PrinterCapabilities
    {
        $client = new IppClient($target);

        try {
            $response = $client->getPrinterAttributes([
                'media-supported',
                'media-default',
                'media-ready',
                'print-color-mode-supported',
                'print-color-mode-default',
                'color-supported',
                'sides-supported',
                'sides-default',
                'orientation-requested-supported',
                'document-format-supported',
                'document-format-default',
                'printer-make-and-model',
                'printer-resolution-supported',
                'copies-supported',
                'page-ranges-supported',
            ]);
        } catch (\Throwable $e) {
            return PrinterCapabilities::unsupported(
                'Could not read capabilities over IPP: ' . $e->getMessage()
            );
        }

        if (!$response->isSuccess()) {
            return PrinterCapabilities::unsupported(
                'Printer rejected the capability request: ' . $response->statusMessage()
            );
        }

        return $this->translate($response);
    }

    /**
     * Translate raw IPP attributes into the application's vocabulary.
     *
     * Anything the printer does not report is left OUT rather than defaulted
     * in — an absent attribute means "unknown", and guessing here would put an
     * unverified option in front of a paying customer.
     */
    private function translate(IppDecoder $response): PrinterCapabilities
    {
        $sizeMap = [];
        foreach ((array) Config::get('printing.paper_sizes', []) as $key => $meta) {
            if (isset($meta['pwg'])) {
                $sizeMap[strtolower((string) $meta['pwg'])] = $key;
            }
        }

        $paperSizes = [];
        foreach ($response->getList('media-supported') as $media) {
            $media = strtolower(trim((string) $media));
            if ($media === '') {
                continue;
            }
            if (isset($sizeMap[$media])) {
                $paperSizes[] = $sizeMap[$media];
                continue;
            }
            // Some firmware reports bare PWG names without the dimension
            // suffix, e.g. "iso_a4" or "na_letter".
            foreach ($sizeMap as $pwg => $key) {
                if (str_starts_with($pwg, $media) || str_starts_with($media, explode('_', $pwg)[0] . '_' . explode('_', $pwg)[1])) {
                    $paperSizes[] = $key;
                    break;
                }
            }
        }

        $colorModes = [];
        $reportedColorModes = array_map('strval', $response->getList('print-color-mode-supported'));
        foreach ($reportedColorModes as $mode) {
            $mode = strtolower(trim($mode));
            if ($mode === 'color') {
                $colorModes[] = 'color';
            } elseif (in_array($mode, ['monochrome', 'auto-monochrome', 'process-monochrome', 'bi-level', 'process-bi-level'], true)) {
                $colorModes[] = 'bw';
            }
        }
        if ($colorModes === []) {
            // Older printers report only the boolean color-supported.
            $colorSupported = $response->get('color-supported');
            if (is_bool($colorSupported)) {
                $colorModes = $colorSupported ? ['bw', 'color'] : ['bw'];
            }
        }

        $duplexModes = [];
        foreach ($response->getList('sides-supported') as $side) {
            $side = strtolower(trim((string) $side));
            if ($side === 'one-sided') {
                $duplexModes[] = 'single';
            } elseif (str_starts_with($side, 'two-sided')) {
                $duplexModes[] = 'double';
            }
        }

        $orientations = [];
        foreach ($response->getList('orientation-requested-supported') as $orientation) {
            $orientations[] = match ((int) $orientation) {
                3 => 'portrait',
                4 => 'landscape',
                5, 6 => 'landscape', // reverse-landscape / reverse-portrait fold into the two we offer
                default => 'portrait',
            };
        }
        if ($orientations === []) {
            $orientations = ['portrait'];
        }

        $formats = array_values(array_filter(array_map(
            static fn ($f): string => strtolower(trim((string) $f)),
            $response->getList('document-format-supported')
        )));

        $defaults = [];
        $defaultMedia = strtolower((string) ($response->get('media-default') ?? ''));
        if ($defaultMedia !== '' && isset($sizeMap[$defaultMedia])) {
            $defaults['paper_size'] = $sizeMap[$defaultMedia];
        }
        $defaultColor = strtolower((string) ($response->get('print-color-mode-default') ?? ''));
        if ($defaultColor !== '') {
            $defaults['color_mode'] = $defaultColor === 'color' ? 'color' : 'bw';
        }
        $defaultSides = strtolower((string) ($response->get('sides-default') ?? ''));
        if ($defaultSides !== '') {
            $defaults['duplex'] = str_starts_with($defaultSides, 'two-sided') ? 'double' : 'single';
        }

        return PrinterCapabilities::reported(
            paperSizes: array_values(array_unique($paperSizes)),
            colorModes: array_values(array_unique($colorModes)),
            duplexModes: array_values(array_unique($duplexModes)),
            orientations: array_values(array_unique($orientations)),
            documentFormats: $formats,
            defaults: $defaults,
            raw: $response->group('printer'),
            makeAndModel: $response->get('printer-make-and-model') === null
                ? null
                : (string) $response->get('printer-make-and-model')
        );
    }

    public function acceptsFormat(PrinterTarget $target, string $extension): bool
    {
        $capabilities = $this->capabilities($target);
        if (!$capabilities->reported) {
            // Unknown, so refuse to guess. The caller falls back to the agent,
            // which renders locally and always produces something printable.
            return false;
        }
        return $capabilities->acceptsFormat($this->mimeFor($extension));
    }

    public function submit(PrinterTarget $target, PrintJobSpec $spec, string $documentPath): SubmitResult
    {
        $client = new IppClient($target);
        $format = $this->mimeFor(strtolower(pathinfo($documentPath, PATHINFO_EXTENSION)));

        try {
            $response = $client->printJob(
                jobName: $spec->jobNumber . ' — ' . $spec->documentName,
                documentFormat: $format,
                documentPath: $documentPath,
                jobAttributes: $spec->toIppAttributes()
            );
        } catch (IppException $e) {
            return $e->isPermanent()
                ? SubmitResult::permanentFailure($e->getMessage(), 'ipp_permanent')
                : SubmitResult::transientFailure($e->getMessage(), 'ipp_transport');
        } catch (\Throwable $e) {
            return SubmitResult::transientFailure('IPP submission failed: ' . $e->getMessage(), 'ipp_unexpected');
        }

        if (!$response->isSuccess()) {
            $message = 'Printer rejected the job: ' . $response->statusMessage();
            return $response->isPermanentError()
                ? SubmitResult::permanentFailure($message, 'ipp_' . dechex($response->statusCode()))
                : SubmitResult::transientFailure($message, 'ipp_' . dechex($response->statusCode()));
        }

        $jobId = $response->get('job-id');
        $jobState = $response->get('job-state');

        return SubmitResult::accepted(
            remoteJobId: $jobId === null ? null : (string) $jobId,
            message: 'Printer accepted the job' . ($jobId !== null ? ' (IPP job ' . $jobId . ').' : '.'),
            confirmed: true,
            context: [
                'job_state' => $jobState,
                'job_uri' => $response->get('job-uri'),
                'status' => $response->statusMessage(),
            ]
        );
    }

    public function jobStatus(PrinterTarget $target, string $remoteJobId): JobStatusResult
    {
        if (!ctype_digit($remoteJobId)) {
            return JobStatusResult::unknown('No IPP job id was recorded for this job.');
        }

        try {
            $response = (new IppClient($target))->getJobAttributes((int) $remoteJobId);
        } catch (\Throwable $e) {
            return JobStatusResult::unknown('Could not read job state: ' . $e->getMessage());
        }

        if (!$response->isSuccess()) {
            // A finished job may be purged from the printer's job history;
            // that is not evidence of failure, so report it as unknown.
            if ($response->statusCode() === 0x0406) {
                return JobStatusResult::unknown('The printer no longer has a record of this job.');
            }
            return JobStatusResult::unknown('Printer rejected the job query: ' . $response->statusMessage());
        }

        $state = $response->get('job-state');
        if (!is_int($state)) {
            return JobStatusResult::unknown('The printer did not report a job state.');
        }

        $result = JobStatusResult::fromIppState($state);
        $reasons = implode(', ', array_map('strval', $response->getList('job-state-reasons')));
        $message = (string) ($response->get('job-state-message') ?? '');

        $detail = trim($message !== '' ? $message : $reasons);
        return $detail === ''
            ? $result
            : JobStatusResult::of($result->state, $result->message . ' — ' . $detail, $state);
    }

    public function cancel(PrinterTarget $target, string $remoteJobId, string $reason): bool
    {
        if (!ctype_digit($remoteJobId)) {
            return false;
        }
        try {
            return (new IppClient($target))->cancelJob((int) $remoteJobId, $reason)->isSuccess();
        } catch (\Throwable $e) {
            Logger::warning('IPP cancel failed', ['printer_id' => $target->printerId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function mimeFor(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'ps' => 'application/postscript',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pwg' => 'image/pwg-raster',
            'urf' => 'image/urf',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}

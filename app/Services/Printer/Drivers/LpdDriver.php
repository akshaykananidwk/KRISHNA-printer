<?php
declare(strict_types=1);

namespace App\Services\Printer\Drivers;

use App\Core\Config;
use App\Services\Printer\JobStatusResult;
use App\Services\Printer\PrinterCapabilities;
use App\Services\Printer\PrinterDriver;
use App\Services\Printer\PrinterStatus;
use App\Services\Printer\PrinterTarget;
use App\Services\Printer\PrintJobSpec;
use App\Services\Printer\SubmitResult;

/**
 * LPD / LPR (RFC 1179), TCP port 515.
 *
 * LPD is framed and acknowledged — unlike RAW/9100 it tells you whether the
 * daemon accepted each stage — but its control file carries only bookkeeping
 * (owner, host, job name, banner text). It has no job-ticket vocabulary, so
 * copies is the one option it can express, via repeated print-file entries.
 * Everything else is refused rather than silently dropped.
 *
 * RFC 1179 also requires the source port to be in the privileged range
 * 721-731. Most modern daemons accept any source port, and binding a
 * privileged port needs root, so this driver tries the privileged range first
 * and falls back — reporting clearly if the daemon insists.
 */
final class LpdDriver implements PrinterDriver
{
    private const ACK_OK = "\x00";

    public function name(): string
    {
        return 'lpd';
    }

    public function description(): string
    {
        return 'LPD / LPR (TCP 515) — acknowledged transfer and queue listing, '
            . 'but no print-option support beyond copies.';
    }

    public function probe(PrinterTarget $target): PrinterStatus
    {
        $host = (string) $target->host;
        $port = $target->port ?? (int) Config::get('printing.default_ports.lpd', 515);
        $queue = (string) ($target->lpdQueue ?? 'lp');

        if ($host === '') {
            return PrinterStatus::unknown('No host configured for this connection.', $this->name());
        }

        $started = microtime(true);
        $stream = $this->connect($host, $port, max(2, $target->timeoutSeconds), $error);

        if ($stream === null) {
            return PrinterStatus::offline(
                sprintf('No response on %s:%d — %s', $host, $port, $error ?? 'connection refused'),
                (int) round((microtime(true) - $started) * 1000),
                $this->name()
            );
        }

        try {
            // Command 04: send queue state (long form). The daemon replies with
            // free text and closes — this is the only status channel LPD has.
            fwrite($stream, "\x04" . $queue . "\n");
            $report = '';
            $deadline = microtime(true) + max(2, $target->timeoutSeconds);
            while (!feof($stream) && microtime(true) < $deadline) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $report .= $chunk;
                if (strlen($report) > 8192) {
                    break;
                }
            }
        } finally {
            fclose($stream);
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $report = trim($report);

        // The report is free text; look for the phrases daemons conventionally
        // use for a stalled queue.
        $lower = strtolower($report);
        foreach (['printer is down', 'no daemon present', 'queuing is disabled', 'printing is disabled'] as $bad) {
            if (str_contains($lower, $bad)) {
                return PrinterStatus::error(
                    'LPD queue "' . $queue . '" reports: ' . $this->firstLine($report),
                    [],
                    $this->name()
                );
            }
        }

        return PrinterStatus::online(
            $report === ''
                ? 'LPD daemon accepted the connection (it returned no queue report).'
                : 'LPD queue "' . $queue . '": ' . $this->firstLine($report),
            $latency,
            [],
            $this->name()
        );
    }

    public function capabilities(PrinterTarget $target): PrinterCapabilities
    {
        return PrinterCapabilities::unsupported(
            'LPD has no capability-query operation — its only status command returns a free-text '
            . 'queue listing. Probe this printer over IPP, or verify each option with a test print.'
        );
    }

    public function acceptsFormat(PrinterTarget $target, string $extension): bool
    {
        // LPD's 'l' control command means "print raw", so the same interpreter
        // constraint as RAW/9100 applies.
        $profiles = (array) Config::get('printing.profiles', []);
        $profile = (array) ($profiles[$target->capabilityProfile] ?? []);
        if ($profile['requires_rasterisation'] ?? true) {
            return false;
        }
        return in_array(strtolower($extension), ['ps', 'pcl', 'prn', 'txt', 'pdf'], true);
    }

    public function submit(PrinterTarget $target, PrintJobSpec $spec, string $documentPath): SubmitResult
    {
        $extension = strtolower(pathinfo($documentPath, PATHINFO_EXTENSION));

        if (!$this->acceptsFormat($target, $extension)) {
            return SubmitResult::permanentFailure(
                sprintf(
                    'This printer has no interpreter for .%s over LPD, so the job would not render. '
                    . 'Route it through a location agent or use IPP.',
                    $extension
                ),
                'lpd_format_unsupported'
            );
        }

        $unsupported = [];
        if ($spec->duplex === 'double') {
            $unsupported[] = 'double-sided printing';
        }
        if ($spec->colorMode === 'color') {
            $unsupported[] = 'colour mode selection';
        }
        if ($spec->pageRange !== '' && strcasecmp($spec->pageRange, 'all') !== 0) {
            $unsupported[] = 'page ranges';
        }
        if ($unsupported !== []) {
            return SubmitResult::permanentFailure(
                'The LPD control file has no field for: ' . implode(', ', $unsupported)
                . '. Use a location agent or IPP for jobs that need print options.',
                'lpd_options_unsupported'
            );
        }

        $host = (string) $target->host;
        $port = $target->port ?? 515;
        $queue = (string) ($target->lpdQueue ?? 'lp');
        $timeout = max(5, $target->timeoutSeconds);

        $document = @file_get_contents($documentPath);
        if ($document === false) {
            return SubmitResult::permanentFailure('Could not read the document.', 'lpd_document_unreadable');
        }

        $stream = $this->connect($host, $port, $timeout, $error);
        if ($stream === null) {
            return SubmitResult::transientFailure(
                sprintf('Could not open %s:%d — %s', $host, $port, $error ?? 'connection refused'),
                'lpd_connect_failed'
            );
        }

        try {
            // 02: receive a printer job
            if (!$this->command($stream, "\x02" . $queue . "\n", $timeout, $failure)) {
                return SubmitResult::transientFailure(
                    'LPD daemon refused the job for queue "' . $queue . '": ' . $failure,
                    'lpd_job_refused'
                );
            }

            $jobNumber = random_int(100, 999);
            $localHost = substr(gethostname() ?: 'kpms', 0, 31);
            $user = substr($spec->userName, 0, 31);
            $dataFile = sprintf('dfA%03d%s', $jobNumber, $localHost);
            $controlFile = sprintf('cfA%03d%s', $jobNumber, $localHost);

            // Control file (RFC 1179 §7). One 'l' line per copy is the only way
            // LPD expresses multiple copies.
            $control = 'H' . $localHost . "\n"
                . 'P' . $user . "\n"
                . 'J' . substr($spec->jobNumber . ' ' . $spec->documentName, 0, 99) . "\n"
                . 'L' . $user . "\n"
                . 'N' . substr($spec->documentName, 0, 131) . "\n";
            for ($i = 0; $i < $spec->copies; $i++) {
                $control .= 'l' . $dataFile . "\n";
            }
            $control .= 'U' . $dataFile . "\n";

            // 02: receive control file — sub-commands are length-prefixed and
            // each is acknowledged twice (header, then payload).
            if (!$this->command($stream, "\x02" . strlen($control) . ' ' . $controlFile . "\n", $timeout, $failure)) {
                return SubmitResult::transientFailure('LPD rejected the control file header: ' . $failure, 'lpd_control_refused');
            }
            if (!$this->command($stream, $control . self::ACK_OK, $timeout, $failure)) {
                return SubmitResult::transientFailure('LPD rejected the control file: ' . $failure, 'lpd_control_failed');
            }

            // 03: receive data file
            if (!$this->command($stream, "\x03" . strlen($document) . ' ' . $dataFile . "\n", $timeout, $failure)) {
                return SubmitResult::transientFailure('LPD rejected the data file header: ' . $failure, 'lpd_data_refused');
            }
            if (!$this->command($stream, $document . self::ACK_OK, $timeout, $failure)) {
                return SubmitResult::transientFailure('LPD rejected the document: ' . $failure, 'lpd_data_failed');
            }
        } finally {
            fclose($stream);
        }

        // The daemon acknowledged every stage, so it has the job — but LPD
        // never reports what happened to it afterwards.
        return SubmitResult::accepted(
            remoteJobId: (string) $jobNumber,
            message: sprintf(
                'LPD queue "%s" accepted job %d (%s bytes). LPD reports no rendering outcome.',
                $queue,
                $jobNumber,
                number_format(strlen($document))
            ),
            confirmed: false,
            context: ['lpd_job' => $jobNumber, 'bytes' => strlen($document)]
        );
    }

    public function jobStatus(PrinterTarget $target, string $remoteJobId): JobStatusResult
    {
        return JobStatusResult::unknown(
            'LPD exposes only a free-text queue listing and cannot report the outcome of a specific job.'
        );
    }

    /**
     * @param resource $stream
     * @param-out string|null $failure
     */
    private function command($stream, string $payload, int $timeout, ?string &$failure = null): bool
    {
        $failure = null;
        if (@fwrite($stream, $payload) === false) {
            $failure = 'the connection closed while sending';
            return false;
        }

        stream_set_timeout($stream, $timeout);
        $ack = @fread($stream, 1);

        if ($ack === false || $ack === '') {
            $meta = stream_get_meta_data($stream);
            $failure = !empty($meta['timed_out']) ? 'no acknowledgement (timed out)' : 'the daemon closed the connection';
            return false;
        }
        if ($ack !== self::ACK_OK) {
            $failure = 'the daemon returned a negative acknowledgement (0x' . bin2hex($ack) . ')';
            return false;
        }
        return true;
    }

    /**
     * RFC 1179 wants a source port in 721-731. Try that range first (it only
     * succeeds when running as root), then fall back to an ephemeral port,
     * which every daemon in common use accepts.
     *
     * @param-out string|null $error
     * @return resource|null
     */
    private function connect(string $host, int $port, int $timeout, ?string &$error = null)
    {
        $error = null;
        $errno = 0;
        $errstr = '';

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            for ($sourcePort = 721; $sourcePort <= 731; $sourcePort++) {
                $context = stream_context_create([
                    'socket' => ['bindto' => '0.0.0.0:' . $sourcePort],
                ]);
                $stream = @stream_socket_client(
                    sprintf('tcp://%s:%d', $host, $port),
                    $errno,
                    $errstr,
                    (float) $timeout,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
                if ($stream !== false) {
                    stream_set_timeout($stream, $timeout);
                    return $stream;
                }
            }
        }

        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            (float) $timeout
        );

        if ($stream === false) {
            $error = $errstr !== '' ? $errstr : 'connection refused';
            return null;
        }

        stream_set_timeout($stream, $timeout);
        return $stream;
    }

    private function firstLine(string $text): string
    {
        $line = strtok(trim($text), "\n");
        return substr($line === false ? '' : trim($line), 0, 180);
    }
}

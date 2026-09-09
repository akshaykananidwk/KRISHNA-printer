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
 * RAW / JetDirect (AppSocket, TCP 9100).
 *
 * WHAT THIS TRANSPORT ACTUALLY IS
 * -------------------------------
 * Port 9100 is an unframed byte pipe. There is no request format, no
 * acknowledgement, no capability query and no job identifier. Whatever bytes
 * you write, the printer's interpreter attempts to render. That has three
 * consequences this driver does not paper over:
 *
 *  1. It CANNOT report capabilities. capabilities() returns unsupported()
 *     rather than a guess.
 *
 *  2. It CANNOT confirm that anything printed. A successful write means the
 *     socket accepted the bytes, nothing more, so submit() returns
 *     confirmed:false and the queue records the job as sent-but-unverified
 *     instead of claiming success.
 *
 *  3. It CANNOT apply print options. Copies, duplex, colour mode and page
 *     ranges are job-ticket concepts that live in IPP or in a PDL header —
 *     there is nowhere to put them in a raw stream. This driver refuses a job
 *     whose options it cannot honour rather than printing something different
 *     from what the customer paid for.
 *
 * And the constraint that matters most for the initial target hardware: a
 * printer with no PCL/PostScript interpreter — which includes the whole Canon
 * PIXMA GM series — cannot render a PDF sent this way. Sending one produces
 * pages of garbage, not a document. submit() therefore refuses unless the
 * document is already in a format the printer's own interpreter accepts.
 */
final class Raw9100Driver implements PrinterDriver
{
    /**
     * Formats a raw stream can carry, and only when the printer is known to
     * have the matching interpreter.
     */
    private const RAW_SAFE_FORMATS = ['ps', 'pcl', 'prn', 'pwg', 'urf', 'txt'];

    public function name(): string
    {
        return 'raw9100';
    }

    public function description(): string
    {
        return 'RAW / JetDirect (TCP 9100) — byte pipe only: no capability discovery, '
            . 'no job tracking, and no print-option support.';
    }

    public function probe(PrinterTarget $target): PrinterStatus
    {
        $host = (string) $target->host;
        $port = $target->port ?? (int) Config::get('printing.default_ports.raw9100', 9100);

        if ($host === '') {
            return PrinterStatus::unknown('No host configured for this connection.', $this->name());
        }

        $started = microtime(true);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            (float) max(2, $target->timeoutSeconds)
        );
        $latency = (int) round((microtime(true) - $started) * 1000);

        if ($stream === false) {
            return PrinterStatus::offline(
                sprintf('No response on %s:%d — %s', $host, $port, $errstr !== '' ? $errstr : 'connection refused'),
                $latency,
                $this->name()
            );
        }
        fclose($stream);

        // An open socket proves the print service is listening. It does NOT
        // prove the printer has paper, ink, or a closed cover — port 9100
        // exposes no status channel at all. The message says so plainly so an
        // operator is not misled by a green light.
        return PrinterStatus::online(
            'Port 9100 is accepting connections. This transport reports no paper, ink or error state.',
            $latency,
            [],
            $this->name()
        );
    }

    public function capabilities(PrinterTarget $target): PrinterCapabilities
    {
        return PrinterCapabilities::unsupported(
            'RAW/9100 is a one-way byte stream and has no capability-query mechanism. '
            . 'Probe this printer over IPP, or have an operator verify each option with a test print.'
        );
    }

    public function acceptsFormat(PrinterTarget $target, string $extension): bool
    {
        $extension = strtolower($extension);
        if (!in_array($extension, self::RAW_SAFE_FORMATS, true)) {
            return false;
        }
        // Even a "raw-safe" format needs the matching interpreter. A profile
        // flagged as requiring rasterisation has none.
        $profiles = (array) Config::get('printing.profiles', []);
        $profile = (array) ($profiles[$target->capabilityProfile] ?? []);
        return !($profile['requires_rasterisation'] ?? true);
    }

    public function submit(PrinterTarget $target, PrintJobSpec $spec, string $documentPath): SubmitResult
    {
        $extension = strtolower(pathinfo($documentPath, PATHINFO_EXTENSION));

        if (!$this->acceptsFormat($target, $extension)) {
            $profiles = (array) Config::get('printing.profiles', []);
            $profile = (array) ($profiles[$target->capabilityProfile] ?? []);
            $label = (string) ($profile['label'] ?? 'This printer');

            return SubmitResult::permanentFailure(
                sprintf(
                    '%s has no interpreter for .%s sent over RAW/9100, so the bytes would print as '
                    . 'garbage rather than as the document. Route this printer through a location '
                    . 'agent (which rasterises with the printer driver) or use IPP.',
                    $label,
                    $extension
                ),
                'raw_format_unsupported'
            );
        }

        // Refuse options the transport physically cannot carry, rather than
        // dropping them and printing the wrong thing.
        $unsupported = [];
        if ($spec->copies > 1) {
            $unsupported[] = $spec->copies . ' copies';
        }
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
                'RAW/9100 carries no job ticket, so it cannot apply: ' . implode(', ', $unsupported)
                . '. Use a location agent or IPP for jobs that need print options.',
                'raw_options_unsupported'
            );
        }

        $host = (string) $target->host;
        $port = $target->port ?? 9100;
        $timeout = max(5, $target->timeoutSeconds);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            (float) $timeout
        );

        if ($stream === false) {
            return SubmitResult::transientFailure(
                sprintf('Could not open %s:%d — %s', $host, $port, $errstr !== '' ? $errstr : 'connection refused'),
                'raw_connect_failed'
            );
        }

        stream_set_timeout($stream, $timeout);

        $handle = @fopen($documentPath, 'rb');
        if ($handle === false) {
            fclose($stream);
            return SubmitResult::permanentFailure('Could not read the document.', 'raw_document_unreadable');
        }

        $bytesSent = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    return SubmitResult::transientFailure('Failed reading the document.', 'raw_read_failed');
                }
                if ($chunk === '') {
                    continue;
                }
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = @fwrite($stream, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        $meta = stream_get_meta_data($stream);
                        $reason = !empty($meta['timed_out'])
                            ? 'the printer stopped reading (timed out)'
                            : 'the printer closed the connection';
                        return SubmitResult::transientFailure(
                            "Transfer interrupted after $bytesSent bytes — $reason.",
                            'raw_write_failed'
                        );
                    }
                    $offset += $written;
                    $bytesSent += $written;
                }
            }
        } finally {
            fclose($handle);
            fclose($stream);
        }

        // No job id exists, and nothing came back to confirm rendering.
        return SubmitResult::accepted(
            remoteJobId: null,
            message: sprintf(
                'Sent %s bytes to %s:%d. RAW/9100 returns no acknowledgement, so delivery to the '
                . 'printer is confirmed but rendering is not.',
                number_format($bytesSent),
                $host,
                $port
            ),
            confirmed: false,
            context: ['bytes_sent' => $bytesSent]
        );
    }

    public function jobStatus(PrinterTarget $target, string $remoteJobId): JobStatusResult
    {
        return JobStatusResult::unknown(
            'RAW/9100 assigns no job identifier and exposes no job state, so this job cannot be tracked.'
        );
    }
}

<?php
declare(strict_types=1);

namespace App\Services\Printer\Ipp;

use App\Core\Logger;
use App\Services\Printer\PrinterTarget;

/**
 * Transports IPP messages over HTTP(S).
 *
 * Written against raw sockets rather than cURL so we can stream a large
 * document body without buffering it in memory twice, and so the same code
 * path handles both plain IPP (port 631) and IPPS (TLS).
 */
final class IppClient
{
    private static int $requestCounter = 0;

    public function __construct(private PrinterTarget $target)
    {
    }

    private function nextRequestId(): int
    {
        // Request IDs must be non-zero and are matched against the response.
        return (++self::$requestCounter % 0x7FFFFFFF) ?: 1;
    }

    /**
     * Ask the printer to describe itself.
     *
     * @param array<int,string> $requested attribute names, or [] for all
     */
    public function getPrinterAttributes(array $requested = []): IppDecoder
    {
        $requestId = $this->nextRequestId();
        $encoder = new IppEncoder(IppEncoder::OP_GET_PRINTER_ATTRIBUTES, $requestId);
        $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
            ->standardOperationAttributes($this->target->ippUri(), $this->target->username ?? 'kpms');

        if ($requested !== []) {
            $encoder->keywordSet('requested-attributes', $requested);
        } else {
            $encoder->keyword('requested-attributes', 'all');
        }

        $encoder->end();

        return $this->send($encoder->toString());
    }

    /**
     * Submit a document with Print-Job.
     *
     * @param array<string,mixed> $jobAttributes from PrintJobSpec::toIppAttributes()
     */
    public function printJob(
        string $jobName,
        string $documentFormat,
        string $documentPath,
        array $jobAttributes
    ): IppDecoder {
        if (!is_file($documentPath) || !is_readable($documentPath)) {
            throw IppException::permanent('Document is not readable: ' . basename($documentPath));
        }

        $requestId = $this->nextRequestId();
        $encoder = new IppEncoder(IppEncoder::OP_PRINT_JOB, $requestId);

        $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
            ->standardOperationAttributes($this->target->ippUri(), $this->target->username ?? 'kpms')
            ->nameValue('job-name', substr($jobName, 0, 255))
            ->mimeMediaType('document-format', $documentFormat)
            ->boolean('ipp-attribute-fidelity', false);

        $encoder->beginGroup(IppEncoder::TAG_JOB_ATTRIBUTES);
        $this->writeJobAttributes($encoder, $jobAttributes);
        $encoder->end();

        return $this->send($encoder->toString(), $documentPath);
    }

    /**
     * Validate-Job asks the printer whether it WOULD accept these attributes,
     * without printing anything. Used by the connection test so an operator can
     * confirm a configuration without wasting a sheet of paper.
     *
     * @param array<string,mixed> $jobAttributes
     */
    public function validateJob(string $documentFormat, array $jobAttributes): IppDecoder
    {
        $requestId = $this->nextRequestId();
        $encoder = new IppEncoder(IppEncoder::OP_VALIDATE_JOB, $requestId);

        $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
            ->standardOperationAttributes($this->target->ippUri(), $this->target->username ?? 'kpms')
            ->nameValue('job-name', 'capability-validation')
            ->mimeMediaType('document-format', $documentFormat)
            ->boolean('ipp-attribute-fidelity', true); // demand exact support

        $encoder->beginGroup(IppEncoder::TAG_JOB_ATTRIBUTES);
        $this->writeJobAttributes($encoder, $jobAttributes);
        $encoder->end();

        return $this->send($encoder->toString());
    }

    public function getJobAttributes(int $jobId): IppDecoder
    {
        $requestId = $this->nextRequestId();
        $encoder = new IppEncoder(IppEncoder::OP_GET_JOB_ATTRIBUTES, $requestId);
        $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
            ->standardOperationAttributes($this->target->ippUri(), $this->target->username ?? 'kpms')
            ->integer('job-id', $jobId)
            ->keywordSet('requested-attributes', [
                'job-id', 'job-state', 'job-state-reasons', 'job-state-message',
                'job-impressions-completed', 'job-name',
            ])
            ->end();

        return $this->send($encoder->toString());
    }

    public function cancelJob(int $jobId, string $reason = 'Cancelled by operator'): IppDecoder
    {
        $requestId = $this->nextRequestId();
        $encoder = new IppEncoder(IppEncoder::OP_CANCEL_JOB, $requestId);
        $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
            ->standardOperationAttributes($this->target->ippUri(), $this->target->username ?? 'kpms')
            ->integer('job-id', $jobId)
            ->text('message', substr($reason, 0, 127))
            ->end();

        return $this->send($encoder->toString());
    }

    /** @param array<string,mixed> $attributes */
    private function writeJobAttributes(IppEncoder $encoder, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            match (true) {
                $name === 'copies' => $encoder->integer('copies', (int) $value),
                $name === 'orientation-requested' => $encoder->enum('orientation-requested', (int) $value),
                $name === 'page-ranges' => $this->writePageRanges($encoder, (array) $value),
                $name === 'media' => $encoder->keyword('media', (string) $value),
                is_bool($value) => $encoder->boolean($name, $value),
                is_int($value) => $encoder->integer($name, $value),
                default => $encoder->keyword($name, (string) $value),
            };
        }
    }

    /** @param array<int,array{lower:int,upper:int}> $ranges */
    private function writePageRanges(IppEncoder $encoder, array $ranges): void
    {
        $first = true;
        foreach ($ranges as $range) {
            $lower = (int) ($range['lower'] ?? 1);
            $upper = (int) ($range['upper'] ?? $lower);
            if ($first) {
                $encoder->rangeOfInteger('page-ranges', $lower, $upper);
                $first = false;
            } else {
                $encoder->additionalRangeOfInteger($lower, $upper);
            }
        }
    }

    /**
     * POST an IPP message, optionally followed by a document body streamed
     * from disk.
     */
    private function send(string $ippMessage, ?string $documentPath = null): IppDecoder
    {
        $host = (string) $this->target->host;
        if ($host === '') {
            throw IppException::permanent('Printer host is not configured.');
        }

        $port = $this->target->port ?? ($this->target->useTls ? 443 : 631);
        $path = '/' . ltrim($this->target->ippPath ?? '/ipp/print', '/');
        $timeout = max(2, $this->target->timeoutSeconds);

        $documentSize = 0;
        if ($documentPath !== null) {
            $size = filesize($documentPath);
            if ($size === false) {
                throw IppException::permanent('Could not determine document size.');
            }
            $documentSize = (int) $size;
        }
        $contentLength = strlen($ippMessage) + $documentSize;

        $stream = $this->openStream($host, $port, $timeout);

        try {
            $headers = "POST $path HTTP/1.1\r\n"
                . 'Host: ' . $host . ':' . $port . "\r\n"
                . "Content-Type: application/ipp\r\n"
                . 'Content-Length: ' . $contentLength . "\r\n"
                . "Accept: application/ipp\r\n"
                . "Connection: close\r\n"
                . "Expect:\r\n"; // suppress 100-continue negotiation

            if ($this->target->hasCredentials()) {
                $password = $this->target->password() ?? '';
                $headers .= 'Authorization: Basic '
                    . base64_encode($this->target->username . ':' . $password) . "\r\n";
            }

            $headers .= "\r\n";

            $this->writeAll($stream, $headers . $ippMessage);

            if ($documentPath !== null) {
                $handle = fopen($documentPath, 'rb');
                if ($handle === false) {
                    throw IppException::permanent('Could not open document for reading.');
                }
                try {
                    // Stream in chunks: a 25 MB PDF must not be held in memory
                    // a second time just to be written to a socket.
                    while (!feof($handle)) {
                        $chunk = fread($handle, 65536);
                        if ($chunk === false) {
                            throw new IppException('Failed reading the document while sending.');
                        }
                        if ($chunk !== '') {
                            $this->writeAll($stream, $chunk);
                        }
                    }
                } finally {
                    fclose($handle);
                }
            }

            $response = $this->readResponse($stream, $timeout);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        [$httpStatus, $body] = $response;

        if ($httpStatus === 401) {
            throw IppException::permanent('Printer requires authentication (HTTP 401).');
        }
        if ($httpStatus === 426 || $httpStatus === 403) {
            throw IppException::permanent('Printer refused the connection (HTTP ' . $httpStatus . '). It may require IPPS/TLS.');
        }
        if ($httpStatus !== 200) {
            throw new IppException('Printer returned HTTP ' . $httpStatus . ' for the IPP request.');
        }
        if ($body === '') {
            throw new IppException('Printer returned an empty IPP response.');
        }

        return new IppDecoder($body);
    }

    /** @return resource */
    private function openStream(string $host, int $port, int $timeout)
    {
        $contextOptions = [
            'socket' => ['tcp_nodelay' => true],
        ];

        if ($this->target->useTls) {
            $contextOptions['ssl'] = [
                'verify_peer' => $this->target->verifyTls,
                'verify_peer_name' => $this->target->verifyTls,
                // Many printers ship a self-signed certificate. Verification is
                // therefore configurable per connection rather than forced —
                // but it defaults to ON, and turning it off is a deliberate,
                // logged choice made by an administrator.
                'allow_self_signed' => !$this->target->verifyTls,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ];
        }

        $context = stream_context_create($contextOptions);
        $scheme = $this->target->useTls ? 'ssl' : 'tcp';

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $host, $port),
            $errno,
            $errstr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            throw new IppException(sprintf(
                'Could not connect to %s:%d — %s',
                $host,
                $port,
                $errstr !== '' ? $errstr : 'connection refused or timed out'
            ));
        }

        stream_set_timeout($stream, $timeout);
        return $stream;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($stream, substr($data, $written));
            if ($result === false || $result === 0) {
                $meta = stream_get_meta_data($stream);
                if (!empty($meta['timed_out'])) {
                    throw new IppException('Timed out while sending data to the printer.');
                }
                throw new IppException('The printer closed the connection while receiving data.');
            }
            $written += $result;
        }
    }

    /**
     * Read an HTTP response, handling chunked transfer encoding — CUPS and
     * several printer firmwares use it for IPP replies.
     *
     * @param resource $stream
     * @return array{0:int,1:string}
     */
    private function readResponse($stream, int $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $raw = '';

        // Read until the end of the header block.
        while (!str_contains($raw, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                throw new IppException('Timed out waiting for the printer to respond.');
            }
            $chunk = @fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    break;
                }
                usleep(20000);
                continue;
            }
            $raw .= $chunk;
        }

        $separator = strpos($raw, "\r\n\r\n");
        if ($separator === false) {
            throw new IppException('Malformed HTTP response from the printer.');
        }

        $headerBlock = substr($raw, 0, $separator);
        $body = substr($raw, $separator + 4);

        if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $headerBlock, $m) !== 1) {
            throw new IppException('Could not parse the HTTP status line from the printer.');
        }
        $status = (int) $m[1];

        $headers = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        $chunked = strtolower($headers['transfer-encoding'] ?? '') === 'chunked';
        $contentLength = isset($headers['content-length']) ? (int) $headers['content-length'] : null;

        if ($chunked) {
            $body = $this->readChunkedBody($stream, $body, $deadline);
        } elseif ($contentLength !== null) {
            while (strlen($body) < $contentLength) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $chunk = @fread($stream, min(8192, $contentLength - strlen($body)));
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        break;
                    }
                    usleep(20000);
                    continue;
                }
                $body .= $chunk;
            }
        } else {
            // No length and not chunked: read until the peer closes.
            while (!feof($stream)) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $chunk = @fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
        }

        return [$status, $body];
    }

    /** @param resource $stream */
    private function readChunkedBody($stream, string $buffered, float $deadline): string
    {
        $body = '';
        $buffer = $buffered;

        while (true) {
            if (microtime(true) > $deadline) {
                throw new IppException('Timed out reading a chunked response from the printer.');
            }

            // Ensure a full chunk-size line is buffered.
            while (!str_contains($buffer, "\r\n")) {
                $chunk = @fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        return $body;
                    }
                    usleep(20000);
                    continue;
                }
                $buffer .= $chunk;
            }

            $newline = strpos($buffer, "\r\n");
            $sizeLine = substr($buffer, 0, (int) $newline);
            $buffer = substr($buffer, (int) $newline + 2);

            // The chunk-size line may carry extensions after a ';'.
            $size = (int) hexdec(trim(explode(';', $sizeLine)[0]));
            if ($size === 0) {
                return $body;
            }

            while (strlen($buffer) < $size + 2) {
                $chunk = @fread($stream, max(8192, $size + 2 - strlen($buffer)));
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        return $body . substr($buffer, 0, $size);
                    }
                    usleep(20000);
                    continue;
                }
                $buffer .= $chunk;
            }

            $body .= substr($buffer, 0, $size);
            $buffer = substr($buffer, $size + 2); // skip the trailing CRLF
        }
    }

    /** Cheap TCP reachability check used before a full IPP exchange. */
    public function tcpReachable(int $timeout = 3): bool
    {
        $host = (string) $this->target->host;
        $port = $this->target->port ?? 631;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("tcp://$host:$port", $errno, $errstr, (float) $timeout);
        if ($stream === false) {
            Logger::debug('IPP TCP probe failed', ['host' => $host, 'port' => $port, 'error' => $errstr]);
            return false;
        }
        fclose($stream);
        return true;
    }
}

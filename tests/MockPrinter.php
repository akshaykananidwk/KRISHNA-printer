#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * A mock network printer for the end-to-end tests.
 *
 * Speaks real IPP over HTTP and real RAW/9100, so the driver layer is
 * exercised against actual protocol traffic rather than a stub. It can be told
 * to report itself as out of paper or offline, which is how the "printer
 * unavailable blocks the customer" path gets tested for real.
 *
 * Usage:
 *   php tests/MockPrinter.php --ipp-port=6631 --raw-port=19100 [--state=idle|stopped|media-empty]
 */

require __DIR__ . '/../app/Core/Autoloader.php';

$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', dirname(__DIR__) . '/app');
$autoloader->register();

use App\Services\Printer\Ipp\IppDecoder;
use App\Services\Printer\Ipp\IppEncoder;

$options = getopt('', ['ipp-port::', 'raw-port::', 'state::', 'model::', 'color::', 'duplex::', 'spool::']);

$ippPort = (int) ($options['ipp-port'] ?? 6631);
$rawPort = (int) ($options['raw-port'] ?? 19100);
$state = (string) ($options['state'] ?? 'idle');
$model = (string) ($options['model'] ?? 'Canon PIXMA GM4070');
$supportsColor = ($options['color'] ?? 'no') === 'yes';
$supportsDuplex = ($options['duplex'] ?? 'yes') === 'yes';
$spoolDir = (string) ($options['spool'] ?? sys_get_temp_dir() . '/kpms-mock-printer');

@mkdir($spoolDir, 0777, true);

// A state file lets the test suite flip the printer's condition mid-run
// without restarting it — that is how the offline path is tested.
$stateFile = $spoolDir . '/state';
if (!is_file($stateFile)) {
    file_put_contents($stateFile, $state);
}

$jobCounter = 1;

$currentState = static function () use ($stateFile, $state): string {
    $value = @file_get_contents($stateFile);
    return is_string($value) && $value !== '' ? trim($value) : $state;
};

$log = static function (string $message): void {
    fwrite(STDOUT, gmdate('H:i:s') . ' [mock] ' . $message . PHP_EOL);
};

// ---------------------------------------------------------------------------
// Listeners
// ---------------------------------------------------------------------------

$ippSocket = @stream_socket_server("tcp://127.0.0.1:$ippPort", $errno, $errstr);
if ($ippSocket === false) {
    fwrite(STDERR, "Could not bind the IPP port $ippPort: $errstr\n");
    exit(1);
}

$rawSocket = @stream_socket_server("tcp://127.0.0.1:$rawPort", $errno, $errstr);
if ($rawSocket === false) {
    fwrite(STDERR, "Could not bind the RAW port $rawPort: $errstr\n");
    exit(1);
}

stream_set_blocking($ippSocket, false);
stream_set_blocking($rawSocket, false);

$log("IPP on 127.0.0.1:$ippPort, RAW on 127.0.0.1:$rawPort");
$log("Model: $model, colour: " . ($supportsColor ? 'yes' : 'no') . ', duplex: ' . ($supportsDuplex ? 'yes' : 'no'));
$log("State file: $stateFile (currently " . $currentState() . ')');

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

// ---------------------------------------------------------------------------
// IPP response builders
// ---------------------------------------------------------------------------

/**
 * @param array<int,string> $requested
 */
$buildPrinterAttributes = static function (
    int $requestId,
    string $state,
    string $model,
    bool $color,
    bool $duplex
): string {
    // printer-state: 3 = idle, 4 = processing, 5 = stopped
    $stateCode = match ($state) {
        'stopped', 'media-empty', 'media-jam', 'cover-open' => 5,
        'processing' => 4,
        default => 3,
    };

    $reasons = match ($state) {
        'media-empty' => ['media-empty'],
        'media-jam' => ['media-jam'],
        'cover-open' => ['cover-open'],
        'toner-low' => ['marker-supply-low-warning'],
        'stopped' => ['paused'],
        default => ['none'],
    };

    $encoder = new IppEncoder(0x0000, $requestId);
    $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
        ->charset('attributes-charset', 'utf-8')
        ->naturalLanguage('attributes-natural-language', 'en-us');

    $encoder->beginGroup(IppEncoder::TAG_PRINTER_ATTRIBUTES)
        ->attribute(IppEncoder::TAG_ENUM, 'printer-state', pack('N', $stateCode))
        ->attribute(IppEncoder::TAG_TEXT, 'printer-make-and-model', $model)
        ->boolean('printer-is-accepting-jobs', $stateCode !== 5)
        ->integer('queued-job-count', 0);

    // printer-state-reasons, as a 1setOf.
    $encoder->keyword('printer-state-reasons', $reasons[0]);
    foreach (array_slice($reasons, 1) as $reason) {
        $encoder->additionalValue(IppEncoder::TAG_KEYWORD, $reason);
    }

    if ($stateCode === 5) {
        $encoder->text('printer-state-message', 'Printer requires attention: ' . implode(', ', $reasons));
    } else {
        $encoder->text('printer-state-message', 'Ready to print.');
    }

    // media-supported — the GM4070 tops out at 215.9 mm wide, so no A3.
    $encoder->keyword('media-supported', 'iso_a4_210x297mm');
    $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'iso_a5_148x210mm');
    $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'jis_b5_182x257mm');
    $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'na_letter_8.5x11in');
    $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'na_legal_8.5x14in');
    $encoder->keyword('media-default', 'iso_a4_210x297mm');

    $encoder->keyword('print-color-mode-supported', 'monochrome');
    if ($color) {
        $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'color');
    }
    $encoder->keyword('print-color-mode-default', 'monochrome');
    $encoder->boolean('color-supported', $color);

    $encoder->keyword('sides-supported', 'one-sided');
    if ($duplex) {
        $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'two-sided-long-edge');
        $encoder->additionalValue(IppEncoder::TAG_KEYWORD, 'two-sided-short-edge');
    }
    $encoder->keyword('sides-default', 'one-sided');

    $encoder->attribute(IppEncoder::TAG_ENUM, 'orientation-requested-supported', pack('N', 3));
    $encoder->additionalValue(IppEncoder::TAG_ENUM, pack('N', 4));

    $encoder->mimeMediaType('document-format-supported', 'application/pdf');
    $encoder->additionalValue(IppEncoder::TAG_MIME_MEDIA_TYPE, 'image/jpeg');
    $encoder->additionalValue(IppEncoder::TAG_MIME_MEDIA_TYPE, 'image/urf');
    $encoder->additionalValue(IppEncoder::TAG_MIME_MEDIA_TYPE, 'image/pwg-raster');
    $encoder->mimeMediaType('document-format-default', 'application/pdf');

    $encoder->attribute(IppEncoder::TAG_RANGE_OF_INTEGER, 'copies-supported', pack('NN', 1, 99));
    $encoder->boolean('page-ranges-supported', true);
    $encoder->attribute(IppEncoder::TAG_RESOLUTION, 'printer-resolution-supported', pack('NNC', 600, 1200, 3));

    return $encoder->end()->toString();
};

$buildJobResponse = static function (int $requestId, int $jobId, int $statusCode = 0x0000): string {
    $encoder = new IppEncoder($statusCode, $requestId);
    $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
        ->charset('attributes-charset', 'utf-8')
        ->naturalLanguage('attributes-natural-language', 'en-us');

    if ($statusCode === 0x0000) {
        $encoder->beginGroup(IppEncoder::TAG_JOB_ATTRIBUTES)
            ->integer('job-id', $jobId)
            ->uri('job-uri', 'ipp://127.0.0.1/jobs/' . $jobId)
            ->attribute(IppEncoder::TAG_ENUM, 'job-state', pack('N', 9))   // 9 = completed
            ->keyword('job-state-reasons', 'job-completed-successfully')
            ->text('job-state-message', 'Job printed.')
            ->integer('job-impressions-completed', 1);
    } else {
        $encoder->text('status-message', 'The printer is not accepting jobs.');
    }

    return $encoder->end()->toString();
};

// ---------------------------------------------------------------------------
// Main loop
// ---------------------------------------------------------------------------

while ($running) {
    $read = [$ippSocket, $rawSocket];
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 1) < 1) {
        continue;
    }

    foreach ($read as $ready) {
        $client = @stream_socket_accept($ready, 5);
        if ($client === false) {
            continue;
        }

        stream_set_timeout($client, 10);

        // ---- RAW / 9100 ------------------------------------------------
        if ($ready === $rawSocket) {
            $bytes = 0;
            $spool = fopen($spoolDir . '/raw-' . time() . '-' . bin2hex(random_bytes(3)) . '.bin', 'wb');

            while (!feof($client)) {
                $chunk = fread($client, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($spool !== false) {
                    fwrite($spool, $chunk);
                }
            }

            if ($spool !== false) {
                fclose($spool);
            }
            fclose($client);
            $log("RAW received $bytes bytes");
            continue;
        }

        // ---- IPP over HTTP ---------------------------------------------
        $request = '';
        $deadline = microtime(true) + 10;

        // Read the header block.
        while (!str_contains($request, "\r\n\r\n") && microtime(true) < $deadline) {
            $chunk = fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                if (feof($client)) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $request .= $chunk;
        }

        $separator = strpos($request, "\r\n\r\n");
        if ($separator === false) {
            fclose($client);
            continue;
        }

        $headerBlock = substr($request, 0, $separator);
        $body = substr($request, $separator + 4);

        $contentLength = 0;
        if (preg_match('/Content-Length:\s*(\d+)/i', $headerBlock, $m) === 1) {
            $contentLength = (int) $m[1];
        }

        // Read the rest of the body (the IPP message plus any document).
        while (strlen($body) < $contentLength && microtime(true) < $deadline) {
            $chunk = fread($client, min(65536, $contentLength - strlen($body)));
            if ($chunk === false || $chunk === '') {
                if (feof($client)) {
                    break;
                }
                usleep(5000);
                continue;
            }
            $body .= $chunk;
        }

        $responseBody = '';
        $httpStatus = '200 OK';

        try {
            $decoder = new IppDecoder($body);
            // In a request the "status code" slot holds the operation id.
            $operation = $decoder->statusCode();
            $requestId = $decoder->requestId();
            $state = $currentState();

            switch ($operation) {
                case IppEncoder::OP_GET_PRINTER_ATTRIBUTES:
                    $responseBody = $buildPrinterAttributes(
                        $requestId, $state, $model, $supportsColor, $supportsDuplex
                    );
                    $log("Get-Printer-Attributes → state=$state");
                    break;

                case IppEncoder::OP_PRINT_JOB:
                    if (in_array($state, ['stopped', 'media-empty', 'media-jam', 'cover-open'], true)) {
                        // 0x0506 = server-error-not-accepting-jobs
                        $responseBody = $buildJobResponse($requestId, 0, 0x0506);
                        $log("Print-Job REFUSED (state=$state)");
                        break;
                    }

                    $jobId = $jobCounter++;
                    // Spool the whole request so a test can inspect what was
                    // actually sent, including the job attributes.
                    file_put_contents(
                        $spoolDir . '/ipp-job-' . $jobId . '.bin',
                        $body
                    );
                    file_put_contents(
                        $spoolDir . '/ipp-job-' . $jobId . '.json',
                        json_encode([
                            'job_id' => $jobId,
                            'attributes' => array_map(
                                static fn ($v) => is_string($v) && !mb_check_encoding($v, 'UTF-8')
                                    ? base64_encode($v) : $v,
                                $decoder->attributes()
                            ),
                            'total_bytes' => strlen($body),
                            'received_at' => gmdate('c'),
                        ], JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)
                    );

                    $responseBody = $buildJobResponse($requestId, $jobId);
                    $log(sprintf(
                        'Print-Job ACCEPTED as %d (%d bytes, copies=%s, sides=%s, media=%s, colour=%s)',
                        $jobId,
                        strlen($body),
                        (string) ($decoder->get('copies') ?? '?'),
                        (string) ($decoder->get('sides') ?? '?'),
                        (string) ($decoder->get('media') ?? '?'),
                        (string) ($decoder->get('print-color-mode') ?? '?')
                    ));
                    break;

                case IppEncoder::OP_VALIDATE_JOB:
                    $responseBody = $buildJobResponse($requestId, 0);
                    $log('Validate-Job');
                    break;

                case IppEncoder::OP_GET_JOB_ATTRIBUTES:
                    $jobId = (int) ($decoder->get('job-id') ?? 0);
                    $responseBody = $buildJobResponse($requestId, $jobId);
                    $log("Get-Job-Attributes for $jobId");
                    break;

                case IppEncoder::OP_CANCEL_JOB:
                    $encoder = new IppEncoder(0x0000, $requestId);
                    $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
                        ->charset('attributes-charset', 'utf-8')
                        ->naturalLanguage('attributes-natural-language', 'en-us')
                        ->text('status-message', 'Job cancelled.')
                        ->end();
                    $responseBody = $encoder->toString();
                    $log('Cancel-Job');
                    break;

                default:
                    // 0x0501 = server-error-operation-not-supported
                    $encoder = new IppEncoder(0x0501, $requestId);
                    $encoder->beginGroup(IppEncoder::TAG_OPERATION_ATTRIBUTES)
                        ->charset('attributes-charset', 'utf-8')
                        ->naturalLanguage('attributes-natural-language', 'en-us')
                        ->text('status-message', 'Operation not supported by this mock printer.')
                        ->end();
                    $responseBody = $encoder->toString();
                    $log(sprintf('Unsupported operation 0x%04X', $operation));
            }
        } catch (\Throwable $e) {
            $httpStatus = '400 Bad Request';
            $responseBody = '';
            $log('Malformed IPP request: ' . $e->getMessage());
        }

        $response = "HTTP/1.1 $httpStatus\r\n"
            . "Content-Type: application/ipp\r\n"
            . 'Content-Length: ' . strlen($responseBody) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $responseBody;

        fwrite($client, $response);
        fclose($client);
    }
}

fclose($ippSocket);
fclose($rawSocket);
$log('Stopped.');

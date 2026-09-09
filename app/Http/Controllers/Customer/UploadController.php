<?php
declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Repositories\PrintFileRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintSessionRepository;
use App\Services\FileService;
use App\Services\PrinterService;
use App\Services\RateLimiter;

/**
 * File uploads for the customer flow.
 *
 * Uploading is gated on the printer being available, checked again here rather
 * than trusted from the landing page — a customer may sit on the upload screen
 * for a while, and accepting a document for a printer that has since died only
 * wastes their time.
 */
final class UploadController extends Controller
{
    public function __construct(
        private FileService $files,
        private PrintFileRepository $fileRepository,
        private PrintSessionRepository $sessions,
        private PrinterRepository $printers,
        private PrinterService $printerService,
        private RateLimiter $limiter
    ) {
    }

    /** POST /print/upload */
    public function upload(Request $request): Response
    {
        $locationId = (int) Session::get('print_location_id', 0);
        $printerId = (int) Session::get('print_printer_id', 0);
        $sessionId = (int) Session::get('print_session_id', 0);

        if ($locationId === 0 || $sessionId === 0) {
            return $this->fail('Your session has ended. Please scan the QR code again.', 440);
        }

        $limit = $this->limiter->hit(
            'upload:' . $request->ip(),
            (int) Config::get('security.rate_limit.upload_max', 20),
            (int) Config::get('security.rate_limit.upload_decay', 300)
        );
        if (!$limit['allowed']) {
            return $this->fail(
                'You have uploaded a lot of files recently. Please wait a few minutes.',
                429
            );
        }

        // The gate: no upload while the printer cannot print.
        $availability = $this->printerService->availabilityFor($printerId);
        if (!$availability['available']) {
            return $this->fail($availability['reason'], 409, [
                'printer_unavailable' => true,
                'status' => $availability['status'],
            ]);
        }

        $uploads = $request->normalisedFiles('files');
        if ($uploads === []) {
            $single = $request->normalisedFiles('file');
            $uploads = $single;
        }

        if ($uploads === []) {
            return $this->fail('No file was received. Please choose a file and try again.');
        }

        // Batch limits, counted against what is already in the basket.
        $existingCount = $this->fileRepository->countForSession($sessionId);
        $existingBytes = $this->fileRepository->totalBytesForSession($sessionId);

        $maxFiles = (int) Config::get('uploads.max_files_per_batch', 10);
        if ($existingCount + count($uploads) > $maxFiles) {
            return $this->fail(sprintf(
                'You can print up to %d documents in one order. You already have %d.',
                $maxFiles,
                $existingCount
            ), 422);
        }

        $maxBatchBytes = (int) Config::get('uploads.max_batch_bytes', 104857600);
        $incomingBytes = array_sum(array_map(
            static fn (array $u): int => (int) $u['size'],
            $uploads
        ));
        if ($existingBytes + $incomingBytes > $maxBatchBytes) {
            return $this->fail(sprintf(
                'That would take this order over the %s total limit.',
                $this->files->humanBytes($maxBatchBytes)
            ), 422);
        }

        $stored = [];
        $errors = [];
        $warnings = [];

        foreach ($uploads as $upload) {
            if ((int) $upload['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $result = $this->files->store($upload, $sessionId, $locationId);

            if (!$result['success']) {
                $errors[] = (string) $result['error'];
                continue;
            }

            $stored[] = $result['file'];
            foreach ((array) ($result['warnings'] ?? []) as $warning) {
                $warnings[] = $warning;
            }
        }

        if ($stored === []) {
            return $this->fail(
                $errors === [] ? 'No file could be accepted.' : implode(' ', $errors),
                422
            );
        }

        return $this->json([
            'success' => true,
            'files' => $stored,
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'message' => count($stored) === 1
                ? 'File uploaded.'
                : sprintf('%d files uploaded.', count($stored)),
        ]);
    }

    /** DELETE /print/upload/{file} — remove a document from the basket. */
    public function remove(Request $request): Response
    {
        $fileId = (int) $request->routeParam('file');
        $sessionId = (int) Session::get('print_session_id', 0);

        if ($sessionId === 0) {
            return $this->fail('Your session has ended. Please scan the QR code again.', 440);
        }

        $file = $this->fileRepository->findForSession($fileId, $sessionId);
        if ($file === null) {
            return $this->fail('That file was not found.', 404);
        }

        // A file already attached to a live job must not disappear from under
        // the printer.
        $inUse = (int) \App\Core\Database::instance()->scalar(
            "SELECT COUNT(*) FROM print_jobs
             WHERE file_id = ?
               AND status IN ('pending','payment_pending','paid','queued','processing','printing')",
            [$fileId]
        );
        if ($inUse > 0) {
            return $this->fail('This document is part of an order that is already being processed.', 409);
        }

        $path = $this->files->safePath($file);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
        $this->fileRepository->markDeleted($fileId);

        return $this->ok('File removed.');
    }

    /** GET /print/files — the basket's current contents. */
    public function listFiles(Request $request): Response
    {
        $sessionId = (int) Session::get('print_session_id', 0);
        if ($sessionId === 0) {
            return $this->fail('Your session has ended. Please scan the QR code again.', 440);
        }

        $files = array_map(
            static fn (array $f): array => [
                'id' => (int) $f['id'],
                'name' => (string) $f['original_name'],
                'extension' => (string) $f['extension'],
                'size_bytes' => (int) $f['size_bytes'],
                'pages' => (int) $f['page_count'],
                'page_source' => (string) $f['page_count_source'],
            ],
            $this->fileRepository->forSession($sessionId)
        );

        return $this->json(['success' => true, 'files' => $files]);
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\PrintFileRepository;

/**
 * Secure upload handling.
 *
 * Layers, in the order they run — each one is cheap to pass and expensive to
 * defeat, and a file must clear all of them:
 *
 *  1. PHP upload integrity (is_uploaded_file, error code, real size)
 *  2. Extension whitelist
 *  3. Content sniffing with finfo, cross-checked against the extension
 *  4. Magic-byte verification against the declared format
 *  5. Structural inspection (zip member scan, macro and JavaScript detection)
 *  6. Optional ClamAV scan
 *  7. Storage under a random name, outside the web root, mode 0640
 *
 * The original filename is recorded for display only. It is never used to
 * build a path, so a name like "../../public/shell.php" is inert.
 */
final class FileService
{
    /** Leading bytes each accepted format must actually start with. */
    private const MAGIC = [
        'pdf' => ["%PDF-"],
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG\r\n\x1A\n"],
        // OOXML formats are zip archives.
        'docx' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        'xlsx' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        'pptx' => ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"],
        // Legacy Office formats are OLE2 compound documents.
        'doc' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
        'xls' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
        'ppt' => ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"],
    ];

    /** Zip members that indicate an OOXML file carries executable content. */
    private const MACRO_MEMBERS = [
        'vbaProject.bin',
        'vbaData.xml',
        'word/vbaProject.bin',
        'xl/vbaProject.bin',
        'ppt/vbaProject.bin',
    ];

    public function __construct(
        private PrintFileRepository $files,
        private DocumentService $documents,
        private AuditService $audit
    ) {
    }

    public function uploadRoot(): string
    {
        return rtrim((string) Config::get('app.upload_path', ''), '/');
    }

    /**
     * Validate and store one uploaded file.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $upload
     * @return array{success:bool,file_id?:int,file?:array<string,mixed>,error?:string,warnings?:array<int,string>}
     */
    public function store(array $upload, int $sessionId, int $locationId, bool $isRealUpload = true): array
    {
        $error = $this->checkUploadError($upload['error']);
        if ($error !== null) {
            return ['success' => false, 'error' => $error];
        }

        $tmpPath = $upload['tmp_name'];

        // A file must come from PHP's upload machinery. Skipping this check is
        // only permitted for the internal test harness, which passes
        // $isRealUpload = false explicitly.
        if ($isRealUpload && !is_uploaded_file($tmpPath)) {
            $this->audit->security('upload.not_uploaded_file', 'Rejected a file that was not a genuine HTTP upload.');
            return ['success' => false, 'error' => 'The upload could not be verified. Please try again.'];
        }

        if (!is_file($tmpPath)) {
            return ['success' => false, 'error' => 'The uploaded file is missing. Please try again.'];
        }

        // Trust the file on disk, not the reported size.
        $actualSize = (int) (filesize($tmpPath) ?: 0);
        if ($actualSize === 0) {
            return ['success' => false, 'error' => 'The file is empty.'];
        }

        $maxBytes = (int) Config::get('uploads.max_file_bytes', 26214400);
        if ($actualSize > $maxBytes) {
            return [
                'success' => false,
                'error' => sprintf(
                    '"%s" is %s, which exceeds the %s limit.',
                    $this->displayName($upload['name']),
                    $this->humanBytes($actualSize),
                    $this->humanBytes($maxBytes)
                ),
            ];
        }

        // ---- Extension ------------------------------------------------
        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $allowed = (array) Config::get('uploads.allowed', []);

        if ($extension === '' || !isset($allowed[$extension])) {
            return [
                'success' => false,
                'error' => sprintf(
                    '"%s" is not a supported file type. Accepted: %s.',
                    $this->displayName($upload['name']),
                    strtoupper(implode(', ', array_keys($allowed)))
                ),
            ];
        }

        // A name like "invoice.pdf.php" ends in .php and is caught above, but
        // a double extension the other way round ("shell.php.pdf") is only
        // dangerous if something later executes it. Storage outside the web
        // root and a randomised filename both prevent that; the check here
        // simply refuses names carrying a second executable-looking extension.
        if ($this->hasSuspiciousDoubleExtension($upload['name'])) {
            $this->audit->security('upload.suspicious_name', 'Rejected a filename with a double extension.', [
                'name' => substr($upload['name'], 0, 120),
            ]);
            return ['success' => false, 'error' => 'That filename is not accepted. Please rename the file and try again.'];
        }

        // ---- Content type --------------------------------------------
        $detectedMime = $this->detectMime($tmpPath);
        $acceptableMimes = (array) $allowed[$extension];

        if (!in_array($detectedMime, $acceptableMimes, true)) {
            Logger::info('Upload MIME mismatch', [
                'extension' => $extension,
                'detected' => $detectedMime,
                'expected' => $acceptableMimes,
            ]);
            return [
                'success' => false,
                'error' => sprintf(
                    '"%s" does not appear to be a valid %s file (its content is %s).',
                    $this->displayName($upload['name']),
                    strtoupper($extension),
                    $detectedMime
                ),
            ];
        }

        // ---- Magic bytes ---------------------------------------------
        if (!$this->magicBytesMatch($tmpPath, $extension)) {
            $this->audit->security('upload.magic_mismatch', 'Rejected a file whose contents did not match its extension.', [
                'extension' => $extension,
                'detected_mime' => $detectedMime,
            ]);
            return [
                'success' => false,
                'error' => sprintf(
                    '"%s" is not a valid %s file.',
                    $this->displayName($upload['name']),
                    strtoupper($extension)
                ),
            ];
        }

        // ---- Structural inspection -----------------------------------
        $structural = $this->inspectStructure($tmpPath, $extension);
        if (!$structural['safe']) {
            $this->audit->security('upload.structural_reject', $structural['reason'], [
                'extension' => $extension,
            ]);
            return ['success' => false, 'error' => $structural['reason']];
        }
        $warnings = $structural['warnings'];

        // ---- Storage --------------------------------------------------
        $storedName = $this->randomFilename($extension);
        $relativePath = $this->partitionedPath($storedName);
        $absolutePath = $this->uploadRoot() . '/' . $relativePath;

        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            Logger::error('Could not create the upload directory', ['dir' => $directory]);
            return ['success' => false, 'error' => 'Storage is unavailable. Please try again shortly.'];
        }

        $moved = $isRealUpload
            ? @move_uploaded_file($tmpPath, $absolutePath)
            : @copy($tmpPath, $absolutePath);

        if (!$moved) {
            Logger::error('Failed to store an upload', ['target' => $absolutePath]);
            return ['success' => false, 'error' => 'The file could not be saved. Please try again.'];
        }

        // Never executable, never world-readable.
        @chmod($absolutePath, 0640);

        $sha256 = (string) hash_file('sha256', $absolutePath);

        // ---- Malware scan ---------------------------------------------
        $scan = $this->scan($absolutePath);
        if ($scan['status'] === 'infected') {
            @unlink($absolutePath);
            $this->audit->critical('upload.malware_detected', 'Malware detected in an upload; the file was deleted.', [
                'signature' => $scan['message'],
                'sha256' => $sha256,
            ]);
            return ['success' => false, 'error' => 'This file was rejected by the malware scanner.'];
        }
        if ($scan['status'] === 'error' && (bool) Config::get('uploads.scanning.block_on_scanner_error', false)) {
            @unlink($absolutePath);
            return ['success' => false, 'error' => 'The file could not be scanned. Please try again shortly.'];
        }

        // ---- Page count ------------------------------------------------
        $pageInfo = $this->documents->countPages($absolutePath, $extension);
        if ($pageInfo['source'] !== 'parsed' && $pageInfo['detail'] !== '') {
            $warnings[] = $pageInfo['detail'];
        }

        $maxPages = (int) Config::get('uploads.max_pages_per_job', 2000);
        if ($pageInfo['pages'] > $maxPages) {
            @unlink($absolutePath);
            return [
                'success' => false,
                'error' => sprintf(
                    '"%s" has %s pages, which exceeds the %s page limit for a single job.',
                    $this->displayName($upload['name']),
                    number_format($pageInfo['pages']),
                    number_format($maxPages)
                ),
            ];
        }

        if ($pageInfo['pages'] === 0 && $pageInfo['source'] === 'unknown') {
            @unlink($absolutePath);
            return [
                'success' => false,
                'error' => sprintf(
                    '"%s" could not be read. %s',
                    $this->displayName($upload['name']),
                    $pageInfo['detail']
                ),
            ];
        }

        $retentionHours = (int) Config::get('uploads.retention_hours', 24);

        $fileId = $this->files->create([
            'session_id' => $sessionId,
            'location_id' => $locationId,
            'original_name' => $this->displayName($upload['name']),
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'extension' => $extension,
            'mime_type' => $detectedMime,
            'size_bytes' => $actualSize,
            'sha256' => $sha256,
            'page_count' => $pageInfo['pages'],
            'page_count_source' => $pageInfo['source'],
            'scan_status' => $scan['status'],
            'scan_message' => $scan['message'],
            'purge_after' => gmdate('Y-m-d H:i:s', time() + $retentionHours * 3600),
        ]);

        return [
            'success' => true,
            'file_id' => $fileId,
            'file' => [
                'id' => $fileId,
                'name' => $this->displayName($upload['name']),
                'size_bytes' => $actualSize,
                'size_human' => $this->humanBytes($actualSize),
                'extension' => $extension,
                'pages' => $pageInfo['pages'],
                'page_source' => $pageInfo['source'],
                'page_detail' => $pageInfo['detail'],
                'scan_status' => $scan['status'],
            ],
            'warnings' => $warnings,
        ];
    }

    private function checkUploadError(int $code): ?string
    {
        return match ($code) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the file. Please try again shortly.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by the server configuration.',
            default => 'The upload failed. Please try again.',
        };
    }

    public function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        return 'application/octet-stream';
    }

    private function magicBytesMatch(string $path, string $extension): bool
    {
        $signatures = self::MAGIC[$extension] ?? null;
        if ($signatures === null) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = (string) fread($handle, 16);
        fclose($handle);

        foreach ($signatures as $signature) {
            if (str_starts_with($header, $signature)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Look inside the file for active content.
     *
     * @return array{safe:bool,reason:string,warnings:array<int,string>}
     */
    private function inspectStructure(string $path, string $extension): array
    {
        $warnings = [];

        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            return $this->inspectOoxml($path, $extension);
        }

        if ($extension === 'pdf') {
            return $this->inspectPdf($path);
        }

        if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            // Legacy Office formats can carry VBA and there is no reliable way
            // to detect it without an OLE parser. They are accepted (they are
            // in the required format list) but flagged, and the agent renders
            // them with macros disabled.
            $warnings[] = 'Legacy Office files are converted with macros disabled before printing.';
            return ['safe' => true, 'reason' => '', 'warnings' => $warnings];
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            // Verify the image really decodes. A file that passes the magic
            // check but will not decode would fail at the printer instead.
            $info = @getimagesize($path);
            if ($info === false) {
                return ['safe' => false, 'reason' => 'This image file appears to be corrupt.', 'warnings' => []];
            }
            [$width, $height] = $info;
            if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000) {
                return ['safe' => false, 'reason' => 'This image has unusable dimensions.', 'warnings' => []];
            }
        }

        return ['safe' => true, 'reason' => '', 'warnings' => $warnings];
    }

    /** @return array{safe:bool,reason:string,warnings:array<int,string>} */
    private function inspectOoxml(string $path, string $extension): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['safe' => true, 'reason' => '', 'warnings' => ['Office file contents could not be inspected.']];
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['safe' => false, 'reason' => 'This Office file is corrupt or not a valid archive.', 'warnings' => []];
        }

        $warnings = [];
        $rejectMacros = (bool) Config::get('uploads.scanning.reject_office_macros', true);

        try {
            $totalUncompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = (string) $stat['name'];
                $totalUncompressed += (int) $stat['size'];

                // Zip-slip: a member path that escapes the archive root.
                if (str_contains($name, '..') || str_starts_with($name, '/')) {
                    return [
                        'safe' => false,
                        'reason' => 'This Office file contains an unsafe internal path and was rejected.',
                        'warnings' => [],
                    ];
                }

                if ($rejectMacros) {
                    foreach (self::MACRO_MEMBERS as $macroMember) {
                        if (str_ends_with(strtolower($name), strtolower($macroMember))) {
                            return [
                                'safe' => false,
                                'reason' => 'This file contains macros, which are not accepted. '
                                    . 'Please save it as a macro-free document or a PDF and try again.',
                                'warnings' => [],
                            ];
                        }
                    }
                }

                // An OLE object embedded in a document is executable content
                // by another name.
                if (str_contains(strtolower($name), 'embeddings/oleobject')) {
                    $warnings[] = 'This document contains an embedded object, which will not be printed.';
                }
            }

            // Zip bomb: an archive that expands to an implausible multiple of
            // its stored size would exhaust the agent when it renders.
            $compressedSize = (int) (filesize($path) ?: 1);
            if ($totalUncompressed > 0 && $compressedSize > 0) {
                $ratio = $totalUncompressed / $compressedSize;
                if ($ratio > 200 && $totalUncompressed > 200 * 1024 * 1024) {
                    return [
                        'safe' => false,
                        'reason' => 'This file expands to an unreasonable size and was rejected.',
                        'warnings' => [],
                    ];
                }
            }

            // The archive must actually be the format it claims.
            $required = match ($extension) {
                'docx' => 'word/document.xml',
                'xlsx' => 'xl/workbook.xml',
                'pptx' => 'ppt/presentation.xml',
                default => null,
            };
            if ($required !== null && $zip->locateName($required) === false) {
                return [
                    'safe' => false,
                    'reason' => sprintf('This file is not a valid %s document.', strtoupper($extension)),
                    'warnings' => [],
                ];
            }
        } finally {
            $zip->close();
        }

        return ['safe' => true, 'reason' => '', 'warnings' => $warnings];
    }

    /** @return array{safe:bool,reason:string,warnings:array<int,string>} */
    private function inspectPdf(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['safe' => false, 'reason' => 'This PDF could not be read.', 'warnings' => []];
        }

        // Inspecting the first 2 MB catches the document catalog and any
        // /OpenAction, which is where auto-executing content is declared.
        $sample = (string) fread($handle, 2 * 1024 * 1024);
        fclose($handle);

        $warnings = [];

        if ((bool) Config::get('uploads.scanning.reject_pdf_javascript', true)) {
            if (preg_match('/\/JavaScript\b|\/JS\s*[\(<]/', $sample) === 1) {
                return [
                    'safe' => false,
                    'reason' => 'This PDF contains JavaScript, which is not accepted. '
                        . 'Please print it to a plain PDF and upload that instead.',
                    'warnings' => [],
                ];
            }
            if (preg_match('/\/(OpenAction|AA)\s*<<[^>]*\/(JavaScript|JS|Launch)/', $sample) === 1) {
                return [
                    'safe' => false,
                    'reason' => 'This PDF contains an automatic action and was rejected.',
                    'warnings' => [],
                ];
            }
        }

        if (preg_match('/\/Launch\b/', $sample) === 1) {
            return [
                'safe' => false,
                'reason' => 'This PDF contains a launch action and was rejected.',
                'warnings' => [],
            ];
        }

        if ((bool) Config::get('uploads.scanning.reject_pdf_embedded_files', true)
            && preg_match('/\/EmbeddedFiles?\b/', $sample) === 1) {
            $warnings[] = 'This PDF contains attached files; only the pages will be printed.';
        }

        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $sample) === 1) {
            return [
                'safe' => false,
                'reason' => 'This PDF is password-protected and cannot be printed. '
                    . 'Please upload an unprotected copy.',
                'warnings' => [],
            ];
        }

        return ['safe' => true, 'reason' => '', 'warnings' => $warnings];
    }

    /**
     * Scan with ClamAV when a daemon is configured and reachable.
     *
     * @return array{status:string,message:?string}
     */
    public function scan(string $path): array
    {
        if (!(bool) Config::get('uploads.scanning.clamav_enabled', false)) {
            return ['status' => 'skipped', 'message' => 'No malware scanner is configured on this server.'];
        }

        $socketPath = (string) Config::get('uploads.scanning.clamav_socket', '');
        $timeout = (int) Config::get('uploads.scanning.clamav_timeout', 30);

        $stream = false;
        if ($socketPath !== '' && file_exists($socketPath)) {
            $stream = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, (float) $timeout);
        }
        if ($stream === false) {
            $host = (string) Config::get('uploads.scanning.clamav_host', '127.0.0.1');
            $port = (int) Config::get('uploads.scanning.clamav_port', 3310);
            $stream = @stream_socket_client("tcp://$host:$port", $errno, $errstr, (float) $timeout);
        }

        if ($stream === false) {
            Logger::warning('ClamAV is enabled but unreachable', ['error' => $errstr ?? '']);
            return ['status' => 'error', 'message' => 'The malware scanner could not be reached.'];
        }

        try {
            stream_set_timeout($stream, $timeout);

            // INSTREAM: length-prefixed chunks, terminated by a zero-length one.
            fwrite($stream, "zINSTREAM\0");

            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return ['status' => 'error', 'message' => 'The file could not be read for scanning.'];
            }
            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($stream, pack('N', strlen($chunk)) . $chunk);
                }
            } finally {
                fclose($handle);
            }
            fwrite($stream, pack('N', 0));

            $response = trim((string) stream_get_contents($stream));
        } finally {
            fclose($stream);
        }

        if ($response === '') {
            return ['status' => 'error', 'message' => 'The malware scanner returned no result.'];
        }
        if (str_contains($response, 'FOUND')) {
            $signature = trim(str_replace(['stream:', 'FOUND'], '', $response));
            return ['status' => 'infected', 'message' => $signature];
        }
        if (str_contains($response, 'OK')) {
            return ['status' => 'clean', 'message' => null];
        }

        return ['status' => 'error', 'message' => substr($response, 0, 200)];
    }

    /** A random, collision-free filename. The original name never influences it. */
    private function randomFilename(string $extension): string
    {
        return bin2hex(random_bytes(20)) . '.' . preg_replace('/[^a-z0-9]/', '', $extension);
    }

    /**
     * Shard files across two levels of directories. With 50+ locations and a
     * day's retention this keeps any one directory to a few hundred entries,
     * which matters on ext4 and on shared hosting quotas alike.
     */
    private function partitionedPath(string $storedName): string
    {
        return sprintf('%s/%s/%s', substr($storedName, 0, 2), substr($storedName, 2, 2), $storedName);
    }

    public function absolutePath(array $file): string
    {
        return $this->uploadRoot() . '/' . ltrim((string) $file['relative_path'], '/');
    }

    /**
     * Resolve a stored file's path and confirm it is inside the upload root.
     * Belt-and-braces against a corrupted or tampered relative_path column.
     */
    public function safePath(array $file): ?string
    {
        $path = $this->absolutePath($file);
        $real = realpath($path);
        $root = realpath($this->uploadRoot());

        if ($real === false || $root === false) {
            return null;
        }
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            Logger::critical('A stored file path escaped the upload root', ['path' => $path]);
            return null;
        }
        return $real;
    }

    /**
     * Delete files whose retention deadline has passed and which no active job
     * still needs.
     *
     * @return array{deleted:int,bytes:int,errors:int}
     */
    public function purgeExpired(int $limit = 200): array
    {
        $due = $this->files->dueForPurge($limit);
        $deleted = 0;
        $bytes = 0;
        $errors = 0;

        foreach ($due as $file) {
            $path = $this->safePath($file);
            if ($path !== null && is_file($path)) {
                if (@unlink($path)) {
                    $bytes += (int) $file['size_bytes'];
                } else {
                    $errors++;
                    Logger::warning('Could not delete an expired upload', ['file_id' => $file['id']]);
                    continue;
                }
            }
            $this->files->markDeleted((int) $file['id']);
            $deleted++;
        }

        if ($deleted > 0) {
            Logger::info('Purged expired uploads', ['count' => $deleted, 'bytes' => $bytes, 'errors' => $errors]);
        }

        $this->pruneEmptyDirectories();

        return ['deleted' => $deleted, 'bytes' => $bytes, 'errors' => $errors];
    }

    /** Remove the empty shard directories left behind by purging. */
    private function pruneEmptyDirectories(): void
    {
        $root = $this->uploadRoot();
        if (!is_dir($root)) {
            return;
        }
        foreach (glob($root . '/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (($files = @scandir($dir)) !== false && count($files) <= 2) {
                @rmdir($dir);
            }
        }
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (($files = @scandir($dir)) !== false && count($files) <= 2) {
                @rmdir($dir);
            }
        }
    }

    /** Strip directory components and control characters from a display name. */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'document';
        $name = trim($name);
        return $name === '' ? 'document' : mb_substr($name, 0, 255);
    }

    private function hasSuspiciousDoubleExtension(string $name): bool
    {
        $dangerous = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
            'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb',
            'js', 'jar', 'html', 'htm', 'svg', 'htaccess',
        ];
        $parts = array_map('strtolower', explode('.', $name));
        array_shift($parts);          // the base name
        array_pop($parts);            // the real extension, already whitelisted
        foreach ($parts as $part) {
            if (in_array($part, $dangerous, true)) {
                return true;
            }
        }
        return false;
    }

    public function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$index];
    }
}

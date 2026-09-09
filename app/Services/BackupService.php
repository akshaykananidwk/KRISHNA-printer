<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\BackupRepository;

/**
 * Database and application backups.
 *
 * The database dump is written by a pure-PHP exporter rather than shelling out
 * to mysqldump, because shared hosting frequently disables exec() and does not
 * ship the client binaries. When mysqldump IS available it is used, because it
 * is faster and handles routines and triggers; the PHP path covers everything
 * this schema actually uses.
 *
 * Backups are written under /backups, outside the web root, with restrictive
 * permissions. Customer uploads are never included in an application backup —
 * they are large, they are already transient, and copying them would multiply
 * the exposure of customer documents for no recovery benefit.
 */
final class BackupService
{
    public function __construct(
        private Database $db,
        private BackupRepository $backups,
        private AuditService $audit
    ) {
    }

    public function backupPath(): string
    {
        return rtrim((string) Config::get('app.backup_path', ''), '/');
    }

    /**
     * Dump the database to a gzip-compressed .sql file.
     *
     * @return array{success:bool,backup_id?:int,path?:string,size?:int,error?:string}
     */
    public function backupDatabase(string $trigger = 'manual', ?int $adminId = null): array
    {
        $directory = $this->backupPath() . '/database';
        if (!$this->ensureDirectory($directory)) {
            return ['success' => false, 'error' => 'The backup directory is not writable.'];
        }

        $filename = sprintf(
            'db-%s-%s.sql.gz',
            gmdate('Ymd-His'),
            substr(bin2hex(random_bytes(4)), 0, 8)
        );
        $path = $directory . '/' . $filename;

        $backupId = $this->backups->create([
            'backup_type' => 'database',
            'trigger_source' => $trigger,
            'filename' => $filename,
            'relative_path' => 'database/' . $filename,
            'app_version' => (string) Config::get('app.version', ''),
            'commit_sha' => (string) Config::get('settings.current_commit', ''),
            'status' => 'running',
            'created_by' => $adminId,
            'expires_at' => gmdate(
                'Y-m-d H:i:s',
                time() + ((int) Config::get('updates.backup_retention_days', 30) * 86400)
            ),
        ]);

        try {
            $written = $this->dumpWithMysqldump($path) ?? $this->dumpWithPhp($path);

            if ($written === false || !is_file($path)) {
                throw new \RuntimeException('The database dump produced no output.');
            }

            $size = (int) (filesize($path) ?: 0);
            if ($size < 512) {
                throw new \RuntimeException('The database dump is implausibly small (' . $size . ' bytes).');
            }

            @chmod($path, 0600);
            $sha256 = (string) hash_file('sha256', $path);
            $this->backups->markCompleted($backupId, $size, $sha256);

            $this->audit->log(
                'backup.database_created',
                sprintf('Created a database backup (%s, %s).', $filename, $this->humanBytes($size)),
                'backup',
                (string) $backupId,
                'notice',
                ['trigger' => $trigger]
            );

            return ['success' => true, 'backup_id' => $backupId, 'path' => $path, 'size' => $size];
        } catch (\Throwable $e) {
            @unlink($path);
            $this->backups->markFailed($backupId, $e->getMessage());
            Logger::error('Database backup failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return bool|null null when mysqldump is unavailable, so the caller falls back */
    private function dumpWithMysqldump(string $path): ?bool
    {
        if (!$this->canExec() || $this->db->driver() !== 'mysql') {
            return null;
        }

        $binary = null;
        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump'] as $candidate) {
            if (is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === null) {
            return null;
        }

        $config = $this->db->config();

        // The password goes in a temporary defaults file, never on the command
        // line — argv is visible to every process on the box.
        $credentialsFile = tempnam(sys_get_temp_dir(), 'kpmsdb');
        if ($credentialsFile === false) {
            return null;
        }

        try {
            file_put_contents($credentialsFile, sprintf(
                "[client]\nuser=%s\npassword=\"%s\"\nhost=%s\nport=%d\n",
                (string) ($config['username'] ?? ''),
                str_replace('"', '\\"', (string) ($config['password'] ?? '')),
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 3306)
            ));
            chmod($credentialsFile, 0600);

            $command = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --routines --triggers '
                . '--skip-lock-tables --default-character-set=utf8mb4 %s 2>/dev/null | gzip -9 > %s',
                escapeshellarg($binary),
                escapeshellarg($credentialsFile),
                escapeshellarg($this->db->databaseName()),
                escapeshellarg($path)
            );

            $output = [];
            $exitCode = 0;
            @exec($command, $output, $exitCode);

            if ($exitCode !== 0 || !is_file($path) || filesize($path) < 512) {
                @unlink($path);
                return null; // fall back to the PHP exporter
            }

            return true;
        } finally {
            @unlink($credentialsFile);
        }
    }

    /**
     * Pure-PHP dump. Streams row batches straight into a gzip handle so memory
     * stays flat regardless of table size.
     */
    private function dumpWithPhp(string $path): bool
    {
        $handle = gzopen($path, 'wb9');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the backup file for writing.');
        }

        try {
            gzwrite($handle, sprintf(
                "-- Krishna Printer database backup\n-- Generated: %s UTC\n-- Database: %s\n-- Application: %s\n\n",
                gmdate('Y-m-d H:i:s'),
                $this->db->databaseName(),
                (string) Config::get('app.version', '')
            ));

            gzwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = array_map(
                static fn (array $row): string => (string) reset($row),
                $this->db->select('SHOW TABLES')
            );

            foreach ($tables as $table) {
                $quoted = $this->db->quoteIdentifier($table);

                $create = $this->db->selectOne("SHOW CREATE TABLE $quoted");
                if ($create === null) {
                    continue;
                }
                $createSql = (string) ($create['Create Table'] ?? $create['Create View'] ?? '');
                if ($createSql === '') {
                    continue;
                }

                gzwrite($handle, "\n-- ----------------------------\n-- Table: $table\n-- ----------------------------\n");
                gzwrite($handle, "DROP TABLE IF EXISTS $quoted;\n");
                gzwrite($handle, $createSql . ";\n\n");

                $rowCount = (int) $this->db->scalar("SELECT COUNT(*) FROM $quoted");
                if ($rowCount === 0) {
                    continue;
                }

                $offset = 0;
                $batch = 500;

                while ($offset < $rowCount) {
                    $rows = $this->db->select("SELECT * FROM $quoted LIMIT $batch OFFSET $offset");
                    if ($rows === []) {
                        break;
                    }

                    $columns = array_map(
                        fn (string $c): string => $this->db->quoteIdentifier($c),
                        array_keys($rows[0])
                    );

                    $values = [];
                    foreach ($rows as $row) {
                        $encoded = [];
                        foreach ($row as $value) {
                            $encoded[] = $this->quoteValue($value);
                        }
                        $values[] = '(' . implode(',', $encoded) . ')';
                    }

                    gzwrite($handle, sprintf(
                        "INSERT INTO %s (%s) VALUES\n%s;\n",
                        $quoted,
                        implode(',', $columns),
                        implode(",\n", $values)
                    ));

                    $offset += $batch;
                }
            }

            gzwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
        } finally {
            gzclose($handle);
        }

        return true;
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = (string) $value;

        // Binary-safe: anything with control characters or invalid UTF-8 goes
        // out as a hex literal rather than a quoted string.
        if (!mb_check_encoding($string, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $string) === 1) {
            return '0x' . bin2hex($string);
        }

        return $this->db->pdo()->quote($string);
    }

    /**
     * Archive the application's code and configuration.
     *
     * Uploads, backups and logs are deliberately excluded — see the class note.
     *
     * @return array{success:bool,backup_id?:int,path?:string,size?:int,error?:string}
     */
    public function backupApplication(string $trigger = 'manual', ?int $adminId = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['success' => false, 'error' => 'The zip extension is required for application backups.'];
        }

        $directory = $this->backupPath() . '/application';
        if (!$this->ensureDirectory($directory)) {
            return ['success' => false, 'error' => 'The backup directory is not writable.'];
        }

        $filename = sprintf('app-%s-%s.zip', gmdate('Ymd-His'), substr(bin2hex(random_bytes(4)), 0, 8));
        $path = $directory . '/' . $filename;
        $basePath = (string) Config::get('app.base_path', '');

        $backupId = $this->backups->create([
            'backup_type' => 'application',
            'trigger_source' => $trigger,
            'filename' => $filename,
            'relative_path' => 'application/' . $filename,
            'app_version' => (string) Config::get('app.version', ''),
            'commit_sha' => (string) Config::get('settings.current_commit', ''),
            'status' => 'running',
            'created_by' => $adminId,
            'expires_at' => gmdate(
                'Y-m-d H:i:s',
                time() + ((int) Config::get('updates.backup_retention_days', 30) * 86400)
            ),
        ]);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the archive.');
            }

            $excluded = ['uploads', 'backups', 'logs', 'storage/cache', 'storage/sessions', 'storage/tmp', 'storage/updates', '.git', 'node_modules', 'vendor/bin'];
            $fileCount = 0;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file) use ($basePath, $excluded): bool {
                        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($basePath) + 1));
                        foreach ($excluded as $skip) {
                            if ($relative === $skip || str_starts_with($relative, $skip . '/')) {
                                return false;
                            }
                        }
                        return true;
                    }
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($basePath) + 1));
                $zip->addFile($file->getPathname(), $relative);
                $fileCount++;
            }

            // config/config.php holds live credentials. It IS included, because
            // an application backup that cannot restore a working install is
            // not a backup — which is why the archive is written 0600 and lives
            // outside the web root.
            $zip->close();

            if (!is_file($path)) {
                throw new \RuntimeException('The archive was not written.');
            }

            @chmod($path, 0600);
            $size = (int) (filesize($path) ?: 0);
            $sha256 = (string) hash_file('sha256', $path);
            $this->backups->markCompleted($backupId, $size, $sha256);

            $this->audit->log(
                'backup.application_created',
                sprintf('Created an application backup (%d files, %s).', $fileCount, $this->humanBytes($size)),
                'backup',
                (string) $backupId,
                'notice',
                ['trigger' => $trigger]
            );

            return ['success' => true, 'backup_id' => $backupId, 'path' => $path, 'size' => $size];
        } catch (\Throwable $e) {
            @unlink($path);
            $this->backups->markFailed($backupId, $e->getMessage());
            Logger::error('Application backup failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Restore a database backup.
     *
     * A safety backup of the CURRENT state is taken first, so a restore that
     * turns out to be the wrong choice is itself reversible.
     *
     * @return array{success:bool,message:string,statements?:int,safety_backup_id?:int}
     */
    public function restoreDatabase(int $backupId, ?int $adminId = null): array
    {
        $backup = $this->backups->find($backupId);
        if ($backup === null) {
            return ['success' => false, 'message' => 'That backup does not exist.'];
        }
        if ((string) $backup['backup_type'] !== 'database') {
            return ['success' => false, 'message' => 'That backup is not a database backup.'];
        }

        $path = $this->backupPath() . '/' . ltrim((string) $backup['relative_path'], '/');
        if (!is_file($path)) {
            return ['success' => false, 'message' => 'The backup file is missing from disk.'];
        }

        // Integrity: a corrupted archive must not be restored over live data.
        if (!empty($backup['sha256'])) {
            $actual = (string) hash_file('sha256', $path);
            if (!hash_equals((string) $backup['sha256'], $actual)) {
                $this->audit->critical(
                    'backup.checksum_mismatch',
                    'A database backup failed its checksum and was not restored.',
                    ['backup_id' => $backupId]
                );
                return [
                    'success' => false,
                    'message' => 'This backup failed its integrity check and was not restored.',
                ];
            }
        }

        $safety = $this->backupDatabase('pre_rollback', $adminId);
        $safetyId = $safety['success'] ? (int) $safety['backup_id'] : null;

        try {
            $sql = $this->readBackupContents($path);
            $statements = (new \App\Core\Migrator($this->db, ''))->splitStatements($sql);

            $executed = 0;
            $this->db->execute('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($statements as $statement) {
                    if (trim($statement) === '') {
                        continue;
                    }
                    $this->db->execute($statement);
                    $executed++;
                }
            } finally {
                $this->db->execute('SET FOREIGN_KEY_CHECKS=1');
            }

            $this->backups->markRestored($backupId);

            $this->audit->log(
                'backup.database_restored',
                sprintf('Restored the database from backup #%d (%d statements).', $backupId, $executed),
                'backup',
                (string) $backupId,
                'critical'
            );

            return [
                'success' => true,
                'message' => sprintf('Database restored from backup #%d.', $backupId),
                'statements' => $executed,
                'safety_backup_id' => $safetyId,
            ];
        } catch (\Throwable $e) {
            Logger::critical('Database restore failed', ['backup_id' => $backupId, 'error' => $e->getMessage()]);
            $this->audit->critical(
                'backup.restore_failed',
                'A database restore failed part-way through: ' . $e->getMessage(),
                ['backup_id' => $backupId, 'safety_backup_id' => $safetyId]
            );

            return [
                'success' => false,
                'message' => 'The restore failed: ' . $e->getMessage()
                    . ($safetyId !== null ? ' A safety backup of the previous state was taken as #' . $safetyId . '.' : ''),
                'safety_backup_id' => $safetyId,
            ];
        }
    }

    private function readBackupContents(string $path): string
    {
        if (str_ends_with($path, '.gz')) {
            $contents = '';
            $handle = gzopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Could not open the compressed backup.');
            }
            try {
                while (!gzeof($handle)) {
                    $chunk = gzread($handle, 262144);
                    if ($chunk === false) {
                        break;
                    }
                    $contents .= $chunk;
                }
            } finally {
                gzclose($handle);
            }
            return $contents;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the backup file.');
        }
        return $contents;
    }

    /** @return array{success:bool,message:string} */
    public function deleteBackup(int $backupId, ?int $adminId = null): array
    {
        $backup = $this->backups->find($backupId);
        if ($backup === null) {
            return ['success' => false, 'message' => 'That backup does not exist.'];
        }

        $path = $this->backupPath() . '/' . ltrim((string) $backup['relative_path'], '/');
        if (is_file($path) && !@unlink($path)) {
            return ['success' => false, 'message' => 'The backup file could not be deleted.'];
        }

        $this->backups->markDeleted($backupId);
        $this->audit->log(
            'backup.deleted',
            sprintf('Deleted backup #%d (%s).', $backupId, $backup['filename']),
            'backup',
            (string) $backupId,
            'notice'
        );

        return ['success' => true, 'message' => 'Backup deleted.'];
    }

    /** @return array{deleted:int,bytes:int} */
    public function pruneOldBackups(): array
    {
        $keep = (int) Config::get('updates.max_backups_retained', 10);
        $retentionDays = (int) Config::get('updates.backup_retention_days', 30);

        $due = $this->backups->dueForCleanup($keep, $retentionDays);
        $deleted = 0;
        $bytes = 0;

        foreach ($due as $backup) {
            $path = $this->backupPath() . '/' . ltrim((string) $backup['relative_path'], '/');
            if (is_file($path)) {
                $bytes += (int) $backup['size_bytes'];
                @unlink($path);
            }
            $this->backups->markDeleted((int) $backup['id']);
            $deleted++;
        }

        if ($deleted > 0) {
            Logger::info('Pruned old backups', ['count' => $deleted, 'bytes' => $bytes]);
        }

        return ['deleted' => $deleted, 'bytes' => $bytes];
    }

    public function absolutePath(array $backup): string
    {
        return $this->backupPath() . '/' . ltrim((string) $backup['relative_path'], '/');
    }

    private function ensureDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }
        return is_writable($directory);
    }

    public function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
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
        return round($value, 1) . ' ' . $units[$index];
    }
}

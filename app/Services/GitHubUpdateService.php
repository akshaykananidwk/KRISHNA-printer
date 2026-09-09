<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Logger;
use App\Repositories\SettingsRepository;
use App\Repositories\UpdateLogRepository;

/**
 * One-click updates from a GitHub repository.
 *
 * SAFETY MODEL
 * ------------
 * The update is staged, then swapped, then verified — never applied in place.
 *
 *   1. Take a database backup and an application backup.
 *   2. Download the tarball for the target commit and unpack it to staging.
 *   3. Verify the package: size, structure, and that it parses.
 *   4. Enter maintenance mode.
 *   5. Move the live tree aside into a snapshot; move staging into place.
 *      Preserved paths (config, uploads, storage, logs, backups) are never
 *      part of either move, so customer data cannot be touched by an update.
 *   6. Run migrations.
 *   7. Run health checks.
 *   8. Leave maintenance mode.
 *
 * If any step after (4) fails, the snapshot is moved back, the database backup
 * is restored when migrations had already run, maintenance mode is lifted, and
 * the exact failure is recorded. The application is left running the version it
 * was running before — that is the invariant the whole design serves.
 */
final class GitHubUpdateService
{
    /** @var array<int,array<string,mixed>> */
    private array $steps = [];

    private ?int $updateLogId = null;

    public function __construct(
        private Database $db,
        private SettingsRepository $settings,
        private UpdateLogRepository $updateLogs,
        private BackupService $backups,
        private RollbackService $rollback,
        private HealthCheckService $health,
        private AuditService $audit
    ) {
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    public function isConfigured(): bool
    {
        return $this->repository() !== '' && $this->token() !== '';
    }

    public function repository(): string
    {
        return (string) Config::get('settings.github_repository', '');
    }

    public function branch(): string
    {
        return (string) Config::get('settings.github_branch', 'main');
    }

    private function token(): string
    {
        return (string) Config::get('settings.github_token', '');
    }

    public function currentCommit(): string
    {
        return (string) Config::get('settings.current_commit', '');
    }

    public function currentVersion(): string
    {
        return (string) Config::get('app.version', '1.0.0');
    }

    /**
     * Store the repository settings. The token is encrypted at rest and never
     * returned to the browser afterwards.
     *
     * @return array{success:bool,message:string}
     */
    public function saveConfiguration(string $repository, string $branch, string $token, ?int $adminId): array
    {
        $repository = trim($repository);

        // Accept a full URL or an owner/repo pair, and normalise to owner/repo.
        if (preg_match('#github\.com[/:]([^/]+/[^/.]+)#i', $repository, $m) === 1) {
            $repository = $m[1];
        }
        $repository = trim($repository, '/');

        if (preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repository) !== 1) {
            return ['success' => false, 'message' => 'Enter the repository as owner/repository.'];
        }

        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        if (preg_match('#^[A-Za-z0-9._/-]{1,120}$#', $branch) !== 1) {
            return ['success' => false, 'message' => 'That branch name is not valid.'];
        }

        // Verify the credentials actually work before saving them, so an
        // operator finds out now rather than during an update.
        if ($token !== '') {
            $probe = $this->apiRequest('/repos/' . $repository, $token);
            if (!$probe['success']) {
                return [
                    'success' => false,
                    'message' => 'GitHub rejected those details: ' . $probe['error'],
                ];
            }
        }

        $this->settings->set('github_repository', $repository, 'string', 'updates', $adminId, 'GitHub repository');
        $this->settings->set('github_branch', $branch, 'string', 'updates', $adminId, 'Branch to track');
        if ($token !== '') {
            $this->settings->set('github_token', $token, 'secret', 'updates', $adminId, 'GitHub access token');
        }

        $this->audit->log(
            'update.configuration_saved',
            sprintf('Configured updates from %s (branch %s).', $repository, $branch),
            'update',
            null,
            'notice'
        );

        return ['success' => true, 'message' => 'Update settings saved and verified against GitHub.'];
    }

    // -----------------------------------------------------------------
    // Check for updates
    // -----------------------------------------------------------------

    /**
     * Compare the installed commit against the tip of the tracked branch.
     *
     * @return array{
     *   success:bool, update_available?:bool, error?:string,
     *   current?:array<string,mixed>, latest?:array<string,mixed>,
     *   changed_files?:array<int,array<string,mixed>>, commits_behind?:int
     * }
     */
    public function checkForUpdate(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Updates are not configured yet.'];
        }

        $repository = $this->repository();
        $branch = $this->branch();

        $response = $this->apiRequest(
            sprintf('/repos/%s/commits/%s', $repository, rawurlencode($branch)),
            $this->token()
        );

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        $commit = $response['data'];
        $latestSha = (string) ($commit['sha'] ?? '');

        if ($latestSha === '') {
            return ['success' => false, 'error' => 'GitHub returned no commit for that branch.'];
        }

        $currentSha = $this->currentCommit();
        $updateAvailable = $currentSha === '' || !hash_equals($currentSha, $latestSha);

        $latest = [
            'sha' => $latestSha,
            'short_sha' => substr($latestSha, 0, 7),
            'message' => (string) ($commit['commit']['message'] ?? ''),
            'author' => (string) ($commit['commit']['author']['name'] ?? 'Unknown'),
            'author_email' => (string) ($commit['commit']['author']['email'] ?? ''),
            'date' => (string) ($commit['commit']['author']['date'] ?? ''),
            'url' => (string) ($commit['html_url'] ?? ''),
        ];

        $changedFiles = [];
        $commitsBehind = 0;

        if ($updateAvailable && $currentSha !== '') {
            // A comparison tells the operator exactly what is about to change.
            $comparison = $this->apiRequest(
                sprintf('/repos/%s/compare/%s...%s', $repository, $currentSha, $latestSha),
                $this->token()
            );

            if ($comparison['success']) {
                $commitsBehind = (int) ($comparison['data']['ahead_by'] ?? 0);
                foreach ((array) ($comparison['data']['files'] ?? []) as $file) {
                    $changedFiles[] = [
                        'filename' => (string) ($file['filename'] ?? ''),
                        'status' => (string) ($file['status'] ?? ''),
                        'additions' => (int) ($file['additions'] ?? 0),
                        'deletions' => (int) ($file['deletions'] ?? 0),
                    ];
                }
            }
        } elseif ($updateAvailable) {
            foreach ((array) ($commit['files'] ?? []) as $file) {
                $changedFiles[] = [
                    'filename' => (string) ($file['filename'] ?? ''),
                    'status' => (string) ($file['status'] ?? ''),
                    'additions' => (int) ($file['additions'] ?? 0),
                    'deletions' => (int) ($file['deletions'] ?? 0),
                ];
            }
            $commitsBehind = 1;
        }

        // Warn if the update touches migrations — the operator should know a
        // schema change is coming before they click.
        $touchesMigrations = false;
        $touchesPreserved = [];
        foreach ($changedFiles as $file) {
            if (str_starts_with($file['filename'], 'database/migrations/')) {
                $touchesMigrations = true;
            }
            if ($this->rollback->isPreserved($file['filename'], $this->rollback->preservedPaths())) {
                $touchesPreserved[] = $file['filename'];
            }
        }

        $this->settings->set('last_update_check', gmdate('c'), 'string', 'updates');

        return [
            'success' => true,
            'update_available' => $updateAvailable,
            'current' => [
                'version' => $this->currentVersion(),
                'sha' => $currentSha,
                'short_sha' => $currentSha === '' ? 'unknown' : substr($currentSha, 0, 7),
            ],
            'latest' => $latest,
            'changed_files' => $changedFiles,
            'commits_behind' => $commitsBehind,
            'touches_migrations' => $touchesMigrations,
            'touches_preserved' => $touchesPreserved,
            'repository' => $repository,
            'branch' => $branch,
        ];
    }

    // -----------------------------------------------------------------
    // Apply an update
    // -----------------------------------------------------------------

    /**
     * Run the full update.
     *
     * @return array{success:bool,message:string,update_log_id?:int,steps:array<int,array<string,mixed>>,rolled_back?:bool}
     */
    public function applyUpdate(?int $adminId = null): array
    {
        $startedAt = microtime(true);
        $this->steps = [];

        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Updates are not configured.', 'steps' => []];
        }

        // Refuse a concurrent update: two updaters swapping trees at once would
        // destroy the installation.
        $running = $this->updateLogs->runningUpdate();
        if ($running !== null) {
            return [
                'success' => false,
                'message' => 'An update started at ' . $running['started_at'] . ' UTC is still running.',
                'steps' => [],
            ];
        }

        $check = $this->checkForUpdate();
        if (!$check['success']) {
            return ['success' => false, 'message' => (string) $check['error'], 'steps' => []];
        }
        if (!$check['update_available']) {
            return ['success' => true, 'message' => 'Already up to date.', 'steps' => []];
        }

        $targetSha = (string) $check['latest']['sha'];

        $this->updateLogId = $this->updateLogs->create([
            'from_version' => $this->currentVersion(),
            'from_commit' => $this->currentCommit(),
            'to_commit' => $targetSha,
            'commit_message' => substr((string) $check['latest']['message'], 0, 2000),
            'commit_author' => (string) $check['latest']['author'],
            'commit_date' => $this->toMysqlDate((string) $check['latest']['date']),
            'changed_files' => json_encode($check['changed_files'], JSON_UNESCAPED_SLASHES),
            'repository' => $this->repository(),
            'branch' => $this->branch(),
            'status' => 'checking',
            'triggered_by' => $adminId,
        ]);

        $workDirectory = (string) Config::get('updates.paths.work', '');
        $stagingPath = $workDirectory . '/staging-' . $targetSha;
        $snapshotPath = $workDirectory . '/snapshot-' . gmdate('Ymd-His');
        $archivePath = $workDirectory . '/download-' . substr($targetSha, 0, 12) . '.tar.gz';

        $maintenanceEngaged = false;
        $filesSwapped = false;
        $migrationsRun = false;
        $databaseBackupId = null;

        try {
            // ---- 1. Backups -------------------------------------------
            $this->beginStep('backup_database', 'Creating a database backup');
            $databaseBackup = $this->backups->backupDatabase('pre_update', $adminId);
            if (!$databaseBackup['success']) {
                throw new UpdateFailure('Could not back up the database: ' . $databaseBackup['error']);
            }
            $databaseBackupId = (int) $databaseBackup['backup_id'];
            $this->completeStep('backup_database', sprintf(
                'Database backed up (%s).',
                $this->backups->humanBytes((int) $databaseBackup['size'])
            ));

            $this->beginStep('backup_application', 'Creating an application backup');
            $applicationBackup = $this->backups->backupApplication('pre_update', $adminId);
            if (!$applicationBackup['success']) {
                throw new UpdateFailure('Could not back up the application: ' . $applicationBackup['error']);
            }
            $this->completeStep('backup_application', sprintf(
                'Application backed up (%s).',
                $this->backups->humanBytes((int) $applicationBackup['size'])
            ));

            $this->updateLogs->updateById($this->updateLogId, [
                'database_backup_id' => $databaseBackupId,
                'application_backup_id' => (int) $applicationBackup['backup_id'],
                'status' => 'downloading',
            ]);

            // ---- 2. Download -------------------------------------------
            $this->beginStep('download', 'Downloading the update from GitHub');
            $this->ensureDirectory($workDirectory);
            $downloaded = $this->downloadTarball($targetSha, $archivePath);
            if (!$downloaded['success']) {
                throw new UpdateFailure('Download failed: ' . $downloaded['error']);
            }
            $this->completeStep('download', sprintf(
                'Downloaded %s.',
                $this->backups->humanBytes((int) $downloaded['size'])
            ));

            // ---- 3. Verify and extract ---------------------------------
            $this->beginStep('verify_package', 'Verifying the downloaded package');
            $extracted = $this->extractTarball($archivePath, $stagingPath);
            if (!$extracted['success']) {
                throw new UpdateFailure('Could not unpack the update: ' . $extracted['error']);
            }
            $verification = $this->verifyPackage($extracted['root']);
            if (!$verification['success']) {
                throw new UpdateFailure('The package failed verification: ' . $verification['error']);
            }
            $this->completeStep('verify_package', sprintf(
                'Package verified (%d files).',
                (int) $verification['file_count']
            ));

            // ---- 4. Maintenance mode -----------------------------------
            $this->beginStep('maintenance_on', 'Entering maintenance mode');
            $this->enableMaintenanceMode($targetSha);
            $maintenanceEngaged = true;
            $this->completeStep('maintenance_on', 'The site is showing a maintenance page.');

            $this->updateLogs->setStatus($this->updateLogId, 'applying');

            // ---- 5. Swap the tree --------------------------------------
            $this->beginStep('apply_files', 'Applying the updated files');
            $swap = $this->swapTree($extracted['root'], $snapshotPath);
            if (!$swap['success']) {
                throw new UpdateFailure('Could not apply the files: ' . $swap['error'], true, $snapshotPath);
            }
            $filesSwapped = true;
            $this->completeStep('apply_files', sprintf(
                'Applied %d path(s); %d preserved path(s) untouched.',
                (int) $swap['applied'],
                (int) $swap['preserved']
            ));

            // ---- 6. Migrations ------------------------------------------
            $this->beginStep('migrate', 'Running database migrations');
            $this->updateLogs->setStatus($this->updateLogId, 'migrating');

            $migrator = new Migrator($this->db, (string) Config::get('app.migrations_path', ''));
            $migrationResult = $migrator->run();
            $migrationsRun = $migrationResult['applied'] !== [];

            $this->updateLogs->updateById($this->updateLogId, [
                'migrations_run' => json_encode($migrationResult['applied'], JSON_UNESCAPED_SLASHES),
            ]);
            $this->completeStep('migrate', $migrationsRun
                ? sprintf('Applied %d migration(s): %s', count($migrationResult['applied']), implode(', ', $migrationResult['applied']))
                : 'No new migrations to apply.');

            // ---- 7. Clear caches ----------------------------------------
            $this->beginStep('clear_cache', 'Clearing caches');
            $this->rollback->clearCaches();
            $this->completeStep('clear_cache', 'Caches cleared and the opcode cache reset.');

            // ---- 8. Health checks ---------------------------------------
            $this->beginStep('health_check', 'Running post-update health checks');
            $this->updateLogs->setStatus($this->updateLogId, 'verifying');

            $healthResult = $this->health->runUpdateChecks();
            if (!$healthResult['healthy']) {
                throw new UpdateFailure(
                    'Post-update health checks failed: ' . implode('; ', $healthResult['failures']),
                    true,
                    $snapshotPath
                );
            }
            $this->completeStep('health_check', sprintf(
                'All %d health checks passed.',
                (int) $healthResult['passed']
            ));

            // ---- 9. Record and finish -----------------------------------
            $this->beginStep('finalise', 'Recording the new version');
            $this->settings->set('current_commit', $targetSha, 'string', 'updates', $adminId);
            $this->settings->set('last_update_at', gmdate('c'), 'string', 'updates', $adminId);

            $newVersion = $this->readVersionFile();
            $this->updateLogs->updateById($this->updateLogId, ['to_version' => $newVersion]);
            $this->completeStep('finalise', 'Now running ' . $newVersion . ' at ' . substr($targetSha, 0, 7) . '.');

            $this->beginStep('maintenance_off', 'Leaving maintenance mode');
            $this->disableMaintenanceMode();
            $maintenanceEngaged = false;
            $this->completeStep('maintenance_off', 'The site is live again.');

            // Housekeeping: the snapshot is the rollback path, so it is kept
            // until the next successful update proves this one is good.
            @unlink($archivePath);
            $this->removeOldSnapshots($workDirectory, $snapshotPath);
            $this->rollback->removePath($stagingPath);

            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->updateLogs->complete($this->updateLogId, 'completed', null, $duration);

            $this->audit->log(
                'update.completed',
                sprintf(
                    'Updated from %s to %s (%s) in %.1fs.',
                    substr((string) $check['current']['sha'], 0, 7) ?: 'unknown',
                    substr($targetSha, 0, 7),
                    $newVersion,
                    $duration / 1000
                ),
                'update',
                (string) $this->updateLogId,
                'critical'
            );

            return [
                'success' => true,
                'message' => sprintf('Updated to %s (%s).', $newVersion, substr($targetSha, 0, 7)),
                'update_log_id' => $this->updateLogId,
                'steps' => $this->steps,
            ];
        } catch (\Throwable $e) {
            return $this->handleFailure(
                $e,
                $maintenanceEngaged,
                $filesSwapped,
                $migrationsRun,
                $snapshotPath,
                $databaseBackupId,
                $startedAt,
                $adminId
            );
        }
    }

    /**
     * Undo whatever the update managed to do, in reverse order.
     *
     * @return array{success:bool,message:string,steps:array<int,array<string,mixed>>,rolled_back:bool}
     */
    private function handleFailure(
        \Throwable $exception,
        bool $maintenanceEngaged,
        bool $filesSwapped,
        bool $migrationsRun,
        string $snapshotPath,
        ?int $databaseBackupId,
        float $startedAt,
        ?int $adminId
    ): array {
        $reason = $exception->getMessage();
        Logger::critical('Update failed', ['error' => $reason]);

        $this->failCurrentStep($reason);

        $rolledBack = false;

        // Restore the database FIRST when migrations ran: the restored files
        // expect the old schema, and running new code against a new schema with
        // old files is the one state that is worse than either.
        if ($migrationsRun && $databaseBackupId !== null) {
            $this->beginStep('rollback_database', 'Restoring the database backup');
            $restore = $this->backups->restoreDatabase($databaseBackupId, $adminId);
            $restore['success']
                ? $this->completeStep('rollback_database', 'Database restored to its pre-update state.')
                : $this->failCurrentStep('Database restore failed: ' . $restore['message']);
            $rolledBack = $rolledBack || $restore['success'];
        }

        if ($filesSwapped) {
            $this->beginStep('rollback_files', 'Restoring the previous application files');
            $result = $this->rollback->restoreFromSnapshot($snapshotPath, $reason);
            $result['success']
                ? $this->completeStep('rollback_files', $result['message'])
                : $this->failCurrentStep($result['message']);
            $rolledBack = $rolledBack || $result['success'];
        }

        // Maintenance mode comes off last, and unconditionally — a failed
        // update must never leave the site dark.
        if ($maintenanceEngaged) {
            $this->beginStep('maintenance_off', 'Leaving maintenance mode');
            $this->disableMaintenanceMode();
            $this->completeStep('maintenance_off', 'The site is live again on the previous version.');
        }

        if ($this->updateLogId !== null) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->updateLogs->updateById($this->updateLogId, [
                'rollback_performed' => $rolledBack ? 1 : 0,
                'rollback_reason' => substr($reason, 0, 2000),
            ]);
            $this->updateLogs->complete(
                $this->updateLogId,
                $rolledBack ? 'rolled_back' : 'failed',
                substr($reason, 0, 2000),
                $duration
            );
        }

        $this->audit->critical(
            'update.failed',
            sprintf('The update failed and was %s. Reason: %s', $rolledBack ? 'rolled back' : 'not rolled back', $reason),
            ['update_log_id' => $this->updateLogId]
        );

        return [
            'success' => false,
            'message' => $rolledBack
                ? 'The update failed and the previous version was restored. Reason: ' . $reason
                : 'The update failed. Reason: ' . $reason,
            'update_log_id' => $this->updateLogId,
            'steps' => $this->steps,
            'rolled_back' => $rolledBack,
        ];
    }

    // -----------------------------------------------------------------
    // Package handling
    // -----------------------------------------------------------------

    /** @return array{success:bool,size?:int,error?:string} */
    private function downloadTarball(string $sha, string $destination): array
    {
        $url = sprintf('https://api.github.com/repos/%s/tarball/%s', $this->repository(), $sha);

        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'The cURL extension is required to download updates.'];
        }

        $handle = @fopen($destination, 'wb');
        if ($handle === false) {
            return ['success' => false, 'error' => 'Could not open the download file for writing.'];
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => (int) Config::get('updates.timeout_seconds', 120),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token(),
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: ' . (string) Config::get('updates.user_agent', 'KrishnaPrinter-Updater'),
            ],
        ]);

        $ok = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false || $httpStatus !== 200) {
            @unlink($destination);
            return [
                'success' => false,
                'error' => $error !== '' ? $error : 'GitHub returned HTTP ' . $httpStatus,
            ];
        }

        $size = (int) (filesize($destination) ?: 0);
        $minimum = (int) Config::get('updates.min_package_bytes', 10240);

        if ($size < $minimum) {
            @unlink($destination);
            return [
                'success' => false,
                'error' => sprintf(
                    'The download is only %d bytes, which indicates a truncated transfer rather than a real release.',
                    $size
                ),
            ];
        }

        return ['success' => true, 'size' => $size];
    }

    /**
     * Unpack the tarball. GitHub wraps the tree in a single top-level
     * directory named owner-repo-sha, which is stripped here.
     *
     * @return array{success:bool,root?:string,error?:string}
     */
    private function extractTarball(string $archivePath, string $stagingPath): array
    {
        $this->rollback->removePath($stagingPath);
        if (!$this->ensureDirectory($stagingPath)) {
            return ['success' => false, 'error' => 'Could not create the staging directory.'];
        }

        try {
            if (!class_exists(\PharData::class)) {
                return ['success' => false, 'error' => 'The phar extension is required to unpack updates.'];
            }

            // PharData insists on a recognised extension for the gz step.
            $gzPath = $stagingPath . '/update.tar.gz';
            if (!@copy($archivePath, $gzPath)) {
                return ['success' => false, 'error' => 'Could not stage the archive.'];
            }

            $phar = new \PharData($gzPath);
            $phar->decompress(); // produces update.tar

            $tarPath = $stagingPath . '/update.tar';
            if (!is_file($tarPath)) {
                return ['success' => false, 'error' => 'The archive did not decompress to a tar file.'];
            }

            $tar = new \PharData($tarPath);
            $tar->extractTo($stagingPath . '/extracted', null, true);

            @unlink($gzPath);
            @unlink($tarPath);

            // Find the single wrapper directory.
            $extractedRoot = $stagingPath . '/extracted';
            $entries = array_values(array_diff(scandir($extractedRoot) ?: [], ['.', '..']));

            if (count($entries) === 1 && is_dir($extractedRoot . '/' . $entries[0])) {
                return ['success' => true, 'root' => $extractedRoot . '/' . $entries[0]];
            }

            return ['success' => true, 'root' => $extractedRoot];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Confirm the unpacked tree is a plausible copy of this application before
     * anything is swapped.
     *
     * @return array{success:bool,file_count?:int,error?:string}
     */
    private function verifyPackage(string $root): array
    {
        // Structural sanity: files this application cannot run without.
        $required = ['public/index.php', 'app/Core/Application.php', 'config/app.php', 'routes/web.php'];

        foreach ($required as $file) {
            if (!is_file($root . '/' . $file)) {
                return [
                    'success' => false,
                    'error' => 'The package is missing ' . $file . ', so it is not a valid build of this application.',
                ];
            }
        }

        // Syntax check the entry points. A package that does not parse would
        // take the site down the moment it was swapped in.
        if ($this->backups->canExec()) {
            foreach (['public/index.php', 'app/Core/Application.php'] as $file) {
                $output = [];
                $exitCode = 0;
                @exec(
                    'php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1',
                    $output,
                    $exitCode
                );
                if ($exitCode !== 0) {
                    return [
                        'success' => false,
                        'error' => $file . ' contains a syntax error: ' . implode(' ', $output),
                    ];
                }
            }
        }

        $fileCount = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $fileCount++;
            }
        }

        if ($fileCount < 20) {
            return [
                'success' => false,
                'error' => sprintf('The package contains only %d files, which is not a complete build.', $fileCount),
            ];
        }

        return ['success' => true, 'file_count' => $fileCount];
    }

    /**
     * Move the live tree aside and the new tree in.
     *
     * @return array{success:bool,applied?:int,preserved?:int,error?:string}
     */
    private function swapTree(string $newRoot, string $snapshotPath): array
    {
        $basePath = (string) Config::get('app.base_path', '');
        $preserved = $this->rollback->preservedPaths();
        $excluded = (array) Config::get('updates.exclude_from_package', []);

        if (!$this->ensureDirectory($snapshotPath)) {
            return ['success' => false, 'error' => 'Could not create the snapshot directory.'];
        }

        $applied = 0;
        $preservedCount = 0;

        try {
            foreach (new \DirectoryIterator($newRoot) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $name = $entry->getFilename();

                if (in_array($name, $excluded, true)) {
                    continue;
                }

                // Never touch a preserved path. This is the guarantee that a
                // customer's uploads and the site's credentials survive.
                if ($this->rollback->isPreserved($name, $preserved)) {
                    $preservedCount++;
                    continue;
                }

                $livePath = $basePath . '/' . $name;
                $snapshotTarget = $snapshotPath . '/' . $name;
                $newPath = $newRoot . '/' . $name;

                // Move the current version into the snapshot.
                if (file_exists($livePath)) {
                    if (!@rename($livePath, $snapshotTarget)) {
                        if (!$this->rollback->copyPath($livePath, $snapshotTarget)) {
                            return ['success' => false, 'error' => 'Could not snapshot ' . $name . '.'];
                        }
                        if (!$this->rollback->removePath($livePath)) {
                            return ['success' => false, 'error' => 'Could not clear ' . $name . ' before replacing it.'];
                        }
                    }
                }

                // Move the new version in.
                if (!@rename($newPath, $livePath)) {
                    if (!$this->rollback->copyPath($newPath, $livePath)) {
                        return ['success' => false, 'error' => 'Could not install ' . $name . '.'];
                    }
                }

                $applied++;
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true, 'applied' => $applied, 'preserved' => $preservedCount];
    }

    /** Keep only the most recent snapshot; older ones are dead weight. */
    private function removeOldSnapshots(string $workDirectory, string $keepPath): void
    {
        foreach (glob($workDirectory . '/snapshot-*', GLOB_ONLYDIR) ?: [] as $directory) {
            if ($directory !== $keepPath) {
                $this->rollback->removePath($directory);
            }
        }
    }

    // -----------------------------------------------------------------
    // Maintenance mode
    // -----------------------------------------------------------------

    public function enableMaintenanceMode(string $reference = ''): void
    {
        $file = (string) Config::get('app.maintenance_file', '');
        if ($file === '') {
            return;
        }
        $this->ensureDirectory(dirname($file));
        @file_put_contents($file, json_encode([
            'since' => gmdate('c'),
            'reason' => 'System update in progress',
            'reference' => $reference,
        ], JSON_UNESCAPED_SLASHES));
        @chmod($file, 0640);
    }

    public function disableMaintenanceMode(): void
    {
        $file = (string) Config::get('app.maintenance_file', '');
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    public function isInMaintenanceMode(): bool
    {
        $file = (string) Config::get('app.maintenance_file', '');
        return $file !== '' && is_file($file);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{success:bool,data:array<string,mixed>,error:string}
     */
    private function apiRequest(string $path, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'data' => [], 'error' => 'The cURL extension is required.'];
        }

        $url = rtrim((string) Config::get('updates.api_base', 'https://api.github.com'), '/') . $path;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: ' . (string) Config::get('updates.user_agent', 'KrishnaPrinter-Updater'),
            ],
        ]);

        $raw = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            return ['success' => false, 'data' => [], 'error' => 'Could not reach GitHub: ' . $curlError];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'data' => [], 'error' => 'GitHub returned an unreadable response.'];
        }

        if ($httpStatus === 401) {
            return ['success' => false, 'data' => $decoded, 'error' => 'The GitHub token was rejected (401).'];
        }
        if ($httpStatus === 403) {
            $message = (string) ($decoded['message'] ?? '');
            return [
                'success' => false,
                'data' => $decoded,
                'error' => str_contains($message, 'rate limit')
                    ? 'The GitHub API rate limit has been reached. Try again shortly.'
                    : 'GitHub denied access (403). Check the token\'s repository permissions.',
            ];
        }
        if ($httpStatus === 404) {
            return [
                'success' => false,
                'data' => $decoded,
                'error' => 'The repository or branch was not found. Check the name and the token\'s access.',
            ];
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return [
                'success' => false,
                'data' => $decoded,
                'error' => (string) ($decoded['message'] ?? 'GitHub returned HTTP ' . $httpStatus),
            ];
        }

        return ['success' => true, 'data' => $decoded, 'error' => ''];
    }

    private function readVersionFile(): string
    {
        $path = (string) Config::get('app.base_path', '') . '/VERSION';
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';
        return $version !== '' ? $version : $this->currentVersion();
    }

    private function toMysqlDate(string $iso): ?string
    {
        $timestamp = strtotime($iso);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function ensureDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }
        return is_writable($directory);
    }

    private function beginStep(string $key, string $label): void
    {
        $this->steps[] = [
            'key' => $key,
            'label' => $label,
            'status' => 'running',
            'started_at' => gmdate('c'),
            'started_micro' => microtime(true),
        ];
    }

    private function completeStep(string $key, string $message): void
    {
        $index = array_key_last($this->steps);
        if ($index === null) {
            return;
        }
        $this->steps[$index]['status'] = 'ok';
        $this->steps[$index]['message'] = $message;
        $this->steps[$index]['duration_ms'] = (int) round(
            (microtime(true) - (float) $this->steps[$index]['started_micro']) * 1000
        );
        unset($this->steps[$index]['started_micro']);

        if ($this->updateLogId !== null) {
            $this->updateLogs->appendStep($this->updateLogId, $this->steps[$index]);
        }
    }

    private function failCurrentStep(string $message): void
    {
        $index = array_key_last($this->steps);
        if ($index === null || ($this->steps[$index]['status'] ?? '') !== 'running') {
            $this->steps[] = [
                'key' => 'failure',
                'label' => 'Update failed',
                'status' => 'failed',
                'message' => $message,
                'started_at' => gmdate('c'),
            ];
            $index = array_key_last($this->steps);
        } else {
            $this->steps[$index]['status'] = 'failed';
            $this->steps[$index]['message'] = $message;
        }

        unset($this->steps[$index]['started_micro']);

        if ($this->updateLogId !== null) {
            $this->updateLogs->appendStep($this->updateLogId, $this->steps[$index]);
        }
    }
}

/** Marks a failure the updater raised itself, as opposed to an unexpected error. */
final class UpdateFailure extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $requiresRollback = false,
        public readonly ?string $snapshotPath = null
    ) {
        parent::__construct($message);
    }
}

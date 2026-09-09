<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\BackupRepository;
use App\Repositories\UpdateLogRepository;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\GitHubUpdateService;
use App\Services\HealthCheckService;

/**
 * Admin → System: health, updates and backups.
 *
 * The update and restore actions are restricted to super_admin in the route
 * definitions, because both can take the site down and one can overwrite the
 * database.
 */
final class SystemController extends Controller
{
    public function __construct(
        private GitHubUpdateService $updates,
        private BackupService $backups,
        private BackupRepository $backupRepository,
        private UpdateLogRepository $updateLogs,
        private HealthCheckService $health,
        private AuditService $audit
    ) {
    }

    /** GET /admin/system */
    public function index(Request $request): Response
    {
        return $this->view('admin/system/index', [
            'title' => 'System',
            'active' => 'system',
            'status' => $this->health->systemStatus(),
            'updateConfigured' => $this->updates->isConfigured(),
            'repository' => $this->updates->repository(),
            'branch' => $this->updates->branch(),
            'currentCommit' => $this->updates->currentCommit(),
            'currentVersion' => $this->updates->currentVersion(),
            'lastCheck' => (string) Config::get('settings.last_update_check', ''),
            'lastUpdate' => (string) Config::get('settings.last_update_at', ''),
            'updateHistory' => $this->updateLogs->recent(10),
            'backups' => $this->backupRepository->recent(20),
            'backupBytes' => $this->backupRepository->totalBytes(),
            'maintenanceMode' => $this->updates->isInMaintenanceMode(),
        ]);
    }

    /** POST /admin/system/update-config */
    public function saveUpdateConfig(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'repository' => 'required|string|max:190',
            'branch' => 'nullable|string|max:120',
            'token' => 'nullable|string|max:255',
        ], [
            'repository' => 'GitHub repository',
        ])->validated();

        $admin = $request->attribute('admin');

        $result = $this->updates->saveConfiguration(
            (string) $data['repository'],
            (string) ($data['branch'] ?? 'main'),
            (string) ($data['token'] ?? ''),
            $admin?->id()
        );

        return $this->back($request, $result['success'] ? 'success' : 'error', $result['message']);
    }

    /** POST /admin/system/check-update */
    public function checkUpdate(Request $request): Response
    {
        $result = $this->updates->checkForUpdate();

        if (!$result['success']) {
            return $this->fail((string) $result['error'], 502);
        }

        return $this->json([
            'success' => true,
            'update_available' => $result['update_available'],
            'current' => $result['current'],
            'latest' => $result['latest'],
            'changed_files' => array_slice($result['changed_files'], 0, 200),
            'changed_file_count' => count($result['changed_files']),
            'commits_behind' => $result['commits_behind'],
            'touches_migrations' => $result['touches_migrations'],
            'touches_preserved' => $result['touches_preserved'],
            'repository' => $result['repository'],
            'branch' => $result['branch'],
        ]);
    }

    /**
     * POST /admin/system/update
     *
     * Long-running: the front end shows a progress view and waits. The step
     * trace comes back with the response and is also persisted to update_logs,
     * so a dropped connection does not lose the record of what happened.
     */
    public function runUpdate(Request $request): Response
    {
        $admin = $request->attribute('admin');

        if ($admin === null || !$admin->isSuperAdmin()) {
            return $this->fail('Only a super administrator can apply system updates.', 403);
        }

        // Typing the confirmation phrase is a deliberate friction point: this
        // action swaps the running code and can migrate the database.
        if ($request->string('confirm') !== 'UPDATE') {
            return $this->fail('Type UPDATE to confirm.', 422);
        }

        // Updates can take minutes on a slow connection.
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $result = $this->updates->applyUpdate($admin->id());

        return $this->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'steps' => $result['steps'],
            'rolled_back' => $result['rolled_back'] ?? false,
            'update_log_id' => $result['update_log_id'] ?? null,
        ], $result['success'] ? 200 : 500);
    }

    /** GET /admin/system/update/{id} — the step trace for one update. */
    public function updateDetail(Request $request): Response
    {
        $log = $this->updateLogs->find((int) $request->routeParam('id'));
        if ($log === null) {
            return $this->fail('That update record was not found.', 404);
        }

        return $this->view('admin/system/update-detail', [
            'title' => 'Update #' . $log['id'],
            'active' => 'system',
            'log' => $log,
            'steps' => json_decode((string) ($log['steps'] ?? '[]'), true) ?: [],
            'changedFiles' => json_decode((string) ($log['changed_files'] ?? '[]'), true) ?: [],
            'migrations' => json_decode((string) ($log['migrations_run'] ?? '[]'), true) ?: [],
        ]);
    }

    /** POST /admin/system/maintenance */
    public function toggleMaintenance(Request $request): Response
    {
        $admin = $request->attribute('admin');
        if ($admin === null || !$admin->isSuperAdmin()) {
            return $this->fail('Only a super administrator can change maintenance mode.', 403);
        }

        if ($this->updates->isInMaintenanceMode()) {
            $this->updates->disableMaintenanceMode();
            $this->audit->log('system.maintenance_off', 'Maintenance mode turned off.', 'system', null, 'critical');
            return $this->back($request, 'success', 'Maintenance mode is off — the site is live.');
        }

        $this->updates->enableMaintenanceMode('manual');
        $this->audit->log('system.maintenance_on', 'Maintenance mode turned on.', 'system', null, 'critical');

        return $this->back(
            $request,
            'warning',
            'Maintenance mode is on. Customers cannot start new print jobs until it is turned off.'
        );
    }

    /** POST /admin/backups */
    public function createBackup(Request $request): Response
    {
        $type = $request->string('type', 'database');
        $admin = $request->attribute('admin');

        @set_time_limit(300);

        $result = match ($type) {
            'database' => $this->backups->backupDatabase('manual', $admin?->id()),
            'application' => $this->backups->backupApplication('manual', $admin?->id()),
            default => ['success' => false, 'error' => 'Unknown backup type.'],
        };

        if (!$result['success']) {
            return $request->isAjax()
                ? $this->fail((string) $result['error'], 500)
                : $this->back($request, 'error', 'Backup failed: ' . $result['error']);
        }

        $message = sprintf(
            '%s backup created (%s).',
            ucfirst($type),
            $this->backups->humanBytes((int) $result['size'])
        );

        return $request->isAjax()
            ? $this->ok($message, ['backup_id' => $result['backup_id']])
            : $this->back($request, 'success', $message);
    }

    /** GET /admin/backups/{id}/download */
    public function downloadBackup(Request $request): Response
    {
        $admin = $request->attribute('admin');
        if ($admin === null || !$admin->isSuperAdmin()) {
            return $this->fail('Only a super administrator can download backups.', 403);
        }

        $backup = $this->backupRepository->find((int) $request->routeParam('id'));
        if ($backup === null) {
            return $this->fail('That backup was not found.', 404);
        }

        $path = $this->backups->absolutePath($backup);
        $real = realpath($path);
        $root = realpath($this->backups->backupPath());

        // Confirm the file really is inside the backup root before serving it.
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return $this->fail('That backup file is not available.', 404);
        }

        $contents = @file_get_contents($real);
        if ($contents === false) {
            return $this->fail('The backup file could not be read.', 500);
        }

        $this->audit->log(
            'backup.downloaded',
            sprintf('Downloaded backup #%d (%s).', (int) $backup['id'], $backup['filename']),
            'backup',
            (string) $backup['id'],
            'critical'
        );

        return Response::download(
            $contents,
            (string) $backup['filename'],
            str_ends_with((string) $backup['filename'], '.zip') ? 'application/zip' : 'application/gzip'
        );
    }

    /** POST /admin/backups/{id}/restore */
    public function restoreBackup(Request $request): Response
    {
        $admin = $request->attribute('admin');
        if ($admin === null || !$admin->isSuperAdmin()) {
            return $this->fail('Only a super administrator can restore a backup.', 403);
        }

        if ($request->string('confirm') !== 'RESTORE') {
            return $this->fail('Type RESTORE to confirm. This replaces the current database.', 422);
        }

        @set_time_limit(600);

        $result = $this->backups->restoreDatabase((int) $request->routeParam('id'), $admin->id());

        return $request->isAjax()
            ? ($result['success'] ? $this->ok($result['message']) : $this->fail($result['message'], 500))
            : $this->back($request, $result['success'] ? 'success' : 'error', $result['message']);
    }

    /** DELETE /admin/backups/{id} */
    public function deleteBackup(Request $request): Response
    {
        $admin = $request->attribute('admin');
        $result = $this->backups->deleteBackup((int) $request->routeParam('id'), $admin?->id());

        return $this->back($request, $result['success'] ? 'success' : 'error', $result['message']);
    }

    /** GET /admin/system/health — the live health check. */
    public function health(Request $request): Response
    {
        return $this->json([
            'success' => true,
            'status' => $this->health->systemStatus(),
            'update_checks' => $this->health->runUpdateChecks(),
        ]);
    }
}

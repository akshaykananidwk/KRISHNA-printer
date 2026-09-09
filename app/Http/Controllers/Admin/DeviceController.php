<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\ApiTokenRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\LocationRepository;
use App\Services\AuditService;

/**
 * Print agents (devices) and their API tokens.
 *
 * A device is the small Linux box at a location that polls this application
 * over HTTPS, renders documents with CUPS and drives the printer. Registering
 * one issues a bearer token, shown exactly once.
 */
final class DeviceController extends Controller
{
    public function __construct(
        private Database $db,
        private DeviceRepository $devices,
        private LocationRepository $locations,
        private ApiTokenRepository $tokens,
        private AuditService $audit
    ) {
    }

    /** GET /admin/devices */
    public function index(Request $request): Response
    {
        $newToken = Session::pull('new_agent_token');

        return $this->view('admin/devices/index', [
            'title' => 'Print agents',
            'active' => 'devices',
            'devices' => $this->devices->all(),
            'locations' => $this->locations->allActive(),
            'tokens' => $this->tokens->all(),
            'newToken' => is_array($newToken) ? $newToken : null,
            'appUrl' => rtrim((string) Config::get('settings.app_url', Config::get('app.url', '')), '/'),
            'staleAfter' => (int) Config::get('printing.queue.health_stale_after', 180),
        ]);
    }

    /** POST /admin/devices */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'location_id' => 'required|int|min:1',
            'poll_interval_secs' => 'nullable|int|between:5,300',
        ])->validated();

        $location = $this->locations->find((int) $data['location_id']);
        if ($location === null) {
            throw new HttpException(422, 'That location does not exist.');
        }

        $admin = $request->attribute('admin');
        $deviceUid = $this->generateUuid();

        $result = $this->db->transaction(function () use ($data, $deviceUid, $admin): array {
            $deviceId = $this->devices->create([
                'location_id' => (int) $data['location_id'],
                'name' => (string) $data['name'],
                'device_uid' => $deviceUid,
                'poll_interval_secs' => (int) ($data['poll_interval_secs'] ?? 10),
                'status' => 'pending',
            ]);

            $token = $this->tokens->issue(
                'Agent: ' . $data['name'],
                'agent',
                $deviceId,
                (int) $data['location_id'],
                $admin?->id()
            );

            return ['device_id' => $deviceId, 'token' => $token];
        });

        $this->audit->log(
            'device.created',
            sprintf('Registered print agent "%s" at "%s".', $data['name'], $location->string('name')),
            'device',
            (string) $result['device_id'],
            'notice'
        );

        // The plaintext token is shown once, on the next page load, then gone.
        Session::put('new_agent_token', [
            'device_id' => $result['device_id'],
            'device_uid' => $deviceUid,
            'name' => (string) $data['name'],
            'location' => $location->string('name'),
            'token' => $result['token']['token'],
        ]);

        return $this->redirect(
            '/admin/devices',
            'success',
            'Print agent registered. Copy its token now — it is shown only once.'
        );
    }

    /** POST /admin/devices/{id}/rotate-token */
    public function rotateToken(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $device = $this->devices->find($id);
        if ($device === null) {
            throw new HttpException(404, 'That print agent was not found.');
        }

        $existing = $this->db->selectOne(
            'SELECT id FROM api_tokens WHERE device_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1',
            [$id]
        );

        $admin = $request->attribute('admin');

        if ($existing === null) {
            $new = $this->tokens->issue(
                'Agent: ' . $device['name'],
                'agent',
                $id,
                (int) $device['location_id'],
                $admin?->id()
            );
        } else {
            $new = $this->tokens->rotate((int) $existing['id'], $admin?->id());
        }

        $this->audit->log(
            'device.token_rotated',
            sprintf(
                'Rotated the token for agent "%s". The old token keeps working for %d seconds.',
                $device['name'],
                (int) Config::get('security.api_tokens.rotation_grace_seconds', 900)
            ),
            'device',
            (string) $id,
            'critical'
        );

        Session::put('new_agent_token', [
            'device_id' => $id,
            'device_uid' => (string) $device['device_uid'],
            'name' => (string) $device['name'],
            'location' => '',
            'token' => $new['token'],
        ]);

        return $this->redirect(
            '/admin/devices',
            'success',
            sprintf(
                'New token issued. The previous one stays valid for %d minutes so the agent can pick this up.',
                (int) round(((int) Config::get('security.api_tokens.rotation_grace_seconds', 900)) / 60)
            )
        );
    }

    /** POST /admin/devices/{id}/toggle */
    public function toggle(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $device = $this->devices->find($id);
        if ($device === null) {
            throw new HttpException(404, 'That print agent was not found.');
        }

        $disable = (string) $device['status'] !== 'disabled';
        $this->devices->updateById($id, ['status' => $disable ? 'disabled' : 'pending']);

        if ($disable) {
            // Disabling the agent takes its printers offline immediately —
            // there is nothing left to drive them.
            $this->db->execute(
                "UPDATE printers SET status = 'offline', last_error = 'The location print agent was disabled.',
                        last_error_at = UTC_TIMESTAMP()
                 WHERE device_id = ?",
                [$id]
            );
        }

        $this->audit->log(
            'device.toggled',
            sprintf('Print agent "%s" %s.', $device['name'], $disable ? 'disabled' : 're-enabled'),
            'device',
            (string) $id,
            'notice'
        );

        return $this->back(
            $request,
            'success',
            $disable
                ? 'Agent disabled. Its printers are now offline to customers.'
                : 'Agent re-enabled. It will come back online on its next check-in.'
        );
    }

    /** DELETE /admin/devices/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $device = $this->devices->find($id);
        if ($device === null) {
            throw new HttpException(404, 'That print agent was not found.');
        }

        $printerCount = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM printers WHERE device_id = ? AND deleted_at IS NULL',
            [$id]
        );

        if ($printerCount > 0) {
            return $this->back($request, 'error', sprintf(
                'This agent still drives %d printer(s). Reassign or remove them first.',
                $printerCount
            ));
        }

        // Cascades to its tokens via the foreign key.
        $this->devices->deleteById($id);

        $this->audit->log(
            'device.deleted',
            sprintf('Removed print agent "%s" and revoked its token.', $device['name']),
            'device',
            (string) $id,
            'warning'
        );

        return $this->redirect('/admin/devices', 'success', 'Print agent removed.');
    }

    /** POST /admin/tokens/{id}/revoke */
    public function revokeToken(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $this->tokens->revoke($id);

        $this->audit->log(
            'token.revoked',
            sprintf('Revoked API token #%d.', $id),
            'api_token',
            (string) $id,
            'critical'
        );

        return $this->back($request, 'success', 'Token revoked. Any agent using it will stop working immediately.');
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

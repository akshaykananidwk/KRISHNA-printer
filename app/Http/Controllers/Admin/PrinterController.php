<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Encrypter;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\DeviceRepository;
use App\Repositories\LocationRepository;
use App\Repositories\PrinterCapabilityRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Services\AuditService;
use App\Services\PrinterCapabilityService;
use App\Services\PrinterService;

final class PrinterController extends Controller
{
    public function __construct(
        private Database $db,
        private PrinterRepository $printers,
        private LocationRepository $locations,
        private DeviceRepository $devices,
        private PrinterCapabilityRepository $capabilityRepository,
        private PrinterCapabilityService $capabilities,
        private PrinterService $printerService,
        private PrintJobRepository $jobs,
        private AuditService $audit
    ) {
    }

    /** GET /admin/printers */
    public function index(Request $request): Response
    {
        $page = max(1, $request->int('page', 1));
        $locationId = $request->int('location_id', 0);

        $result = $this->printers->paginate(
            $page,
            25,
            $request->string('q'),
            $locationId > 0 ? $locationId : null,
            $request->string('status')
        );

        return $this->view('admin/printers/index', [
            'title' => 'Printers',
            'active' => 'printers',
            'result' => $result,
            'locations' => $this->locations->all(),
            'search' => $request->string('q'),
            'status' => $request->string('status'),
            'locationId' => $locationId,
        ]);
    }

    /** GET /admin/printers/create */
    public function create(Request $request): Response
    {
        return $this->view('admin/printers/form', [
            'title' => 'Add printer',
            'active' => 'printers',
            'printer' => null,
            'connection' => null,
            'locations' => $this->locations->allActive(),
            'devices' => $this->devices->all(),
            'profiles' => (array) Config::get('printing.profiles', []),
            'drivers' => $this->printerService->availableDrivers(),
            'formAction' => '/admin/printers',
        ]);
    }

    /** POST /admin/printers */
    public function store(Request $request): Response
    {
        $data = $this->validatePrinter($request);

        if ($this->printers->codeExists($data['printer']['code'])) {
            return $this->redirect('/admin/printers/create', 'error', 'That printer code is already in use.');
        }

        $printerId = $this->db->transaction(function () use ($data): int {
            $id = $this->printers->create($data['printer']);
            $this->db->insert('printer_connections', $data['connection'] + ['printer_id' => $id]);
            return $id;
        });

        $printer = $this->printers->find($printerId);
        if ($printer !== null) {
            // Seed the declared capability set from the model profile. These
            // are NOT offered to customers until verified.
            $this->capabilities->seedFromProfile($printer);
        }

        $this->audit->log(
            'printer.created',
            sprintf('Added printer "%s" (%s).', $data['printer']['name'], $data['printer']['code']),
            'printer',
            (string) $printerId,
            'notice'
        );

        return $this->redirect(
            '/admin/printers/' . $printerId,
            'success',
            'Printer added. Test the connection, then run a capability probe — no print option is '
            . 'offered to customers until it has been verified on this printer.'
        );
    }

    /** GET /admin/printers/{id} */
    public function show(Request $request): Response
    {
        $printer = $this->printers->find((int) $request->routeParam('id'));
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        $location = $this->locations->find($printer->int('location_id'));

        return $this->view('admin/printers/show', [
            'title' => $printer->string('name'),
            'active' => 'printers',
            'printer' => $printer,
            'location' => $location,
            'connections' => $this->printers->connections($printer->id(), false),
            'capabilityMatrix' => $this->capabilities->adminMatrix($printer),
            'customerOptions' => $this->capabilities->customerOptions($printer),
            'profile' => $printer->profile(),
            'statusHistory' => $this->printers->statusHistory($printer->id(), 20),
            'queue' => $this->jobs->queueForPrinter($printer->id(), 20),
            'recentJobs' => $this->jobs->paginate(['printer_id' => $printer->id()], 1, 15),
            'errorLog' => $this->recentErrors($printer->id()),
            'paperSizes' => (array) Config::get('printing.paper_sizes', []),
        ]);
    }

    /** GET /admin/printers/{id}/edit */
    public function edit(Request $request): Response
    {
        $printer = $this->printers->find((int) $request->routeParam('id'));
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        return $this->view('admin/printers/form', [
            'title' => 'Edit ' . $printer->string('name'),
            'active' => 'printers',
            'printer' => $printer,
            'connection' => $this->printers->primaryConnection($printer->id()),
            'locations' => $this->locations->allActive(),
            'devices' => $this->devices->all(),
            'profiles' => (array) Config::get('printing.profiles', []),
            'drivers' => $this->printerService->availableDrivers(),
            'formAction' => '/admin/printers/' . $printer->id(),
        ]);
    }

    /** PUT /admin/printers/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $printer = $this->printers->find($id);
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        $data = $this->validatePrinter($request);

        if ($this->printers->codeExists($data['printer']['code'], $id)) {
            return $this->redirect('/admin/printers/' . $id . '/edit', 'error', 'That printer code is already in use.');
        }

        $profileChanged = $printer->string('capability_profile') !== $data['printer']['capability_profile'];

        $this->db->transaction(function () use ($id, $data): void {
            $this->printers->updateById($id, $data['printer']);

            $existing = $this->printers->primaryConnection($id);
            if ($existing === null) {
                $this->db->insert('printer_connections', $data['connection'] + ['printer_id' => $id]);
                return;
            }

            $connection = $data['connection'];
            // An empty password field means "keep the stored one", not "clear it".
            if ($connection['password_encrypted'] === null) {
                unset($connection['password_encrypted']);
            }
            $this->db->update('printer_connections', $connection, ['id' => (int) $existing['id']]);
        });

        if ($profileChanged) {
            $updated = $this->printers->find($id);
            if ($updated !== null) {
                $this->capabilities->seedFromProfile($updated);
            }
        }

        $this->audit->logChanges(
            'printer.updated',
            'printer',
            (string) $id,
            $printer->toArray(),
            $data['printer'],
            $data['printer']['name']
        );

        return $this->redirect('/admin/printers/' . $id, 'success', 'Printer updated.');
    }

    /** POST /admin/printers/{id}/test — a live connection test. */
    public function test(Request $request): Response
    {
        $printer = $this->printers->find((int) $request->routeParam('id'));
        if ($printer === null) {
            return $this->fail('That printer was not found.', 404);
        }

        $status = $this->printerService->checkHealth($printer);

        $this->audit->log(
            'printer.tested',
            sprintf('Tested "%s": %s — %s', $printer->string('name'), $status->status, $status->message),
            'printer',
            (string) $printer->id()
        );

        return $this->json([
            'success' => true,
            'status' => $status->status,
            'usable' => $status->isUsable(),
            'message' => $status->message,
            'latency_ms' => $status->latencyMs,
            'state_reasons' => $status->stateReasons,
            'warnings' => $status->warnings(),
            'driver' => $status->driver,
        ]);
    }

    /** POST /admin/printers/{id}/probe — ask the printer what it supports. */
    public function probe(Request $request): Response
    {
        $printer = $this->printers->find((int) $request->routeParam('id'));
        if ($printer === null) {
            return $this->fail('That printer was not found.', 404);
        }

        $admin = $request->attribute('admin');
        $result = $this->capabilities->probe($printer, $admin?->id());

        return $this->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'recorded' => $result['recorded'],
            'capabilities' => $result['capabilities'],
        ], $result['success'] ? 200 : 422);
    }

    /**
     * POST /admin/printers/{id}/verify-capability
     *
     * An operator records the result of a physical test print. This outranks a
     * probe, because a human watched paper come out of the machine.
     */
    public function verifyCapability(Request $request): Response
    {
        $printer = $this->printers->find((int) $request->routeParam('id'));
        if ($printer === null) {
            return $this->fail('That printer was not found.', 404);
        }

        $admin = $request->attribute('admin');
        if ($admin === null) {
            return $this->fail('Authentication is required.', 401);
        }

        $data = Validator::make($request->all(), [
            'capability' => 'required|in:paper_size,color_mode,duplex,orientation',
            'value' => 'required|string|max:60',
            'supported' => 'required|bool',
            'note' => 'required|string|min:3|max:400',
        ])->validated();

        // The value has to be one this application understands, or the
        // capability row would be unusable.
        $allowed = match ($data['capability']) {
            'paper_size' => array_keys((array) Config::get('printing.paper_sizes', [])),
            'color_mode' => ['bw', 'color'],
            'duplex' => ['single', 'double'],
            'orientation' => ['portrait', 'landscape'],
            default => [],
        };

        if (!in_array($data['value'], $allowed, true)) {
            return $this->fail('That value is not one this system recognises.', 422);
        }

        $this->capabilities->recordManualVerification(
            $printer,
            (string) $data['capability'],
            (string) $data['value'],
            (bool) $data['supported'],
            $admin->id(),
            (string) $data['note']
        );

        return $this->ok(sprintf(
            '%s = %s recorded as %s.',
            $data['capability'],
            $data['value'],
            $data['supported'] ? 'supported' : 'not supported'
        ));
    }

    /** POST /admin/printers/{id}/toggle */
    public function toggle(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $printer = $this->printers->find($id);
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        $enable = !$printer->bool('is_enabled');
        $this->printers->updateById($id, [
            'is_enabled' => $enable ? 1 : 0,
            // Re-enabling clears the failure counter so a fixed printer gets a
            // clean start rather than being taken offline again immediately.
            'consecutive_failures' => $enable ? 0 : $printer->int('consecutive_failures'),
            'status' => $enable ? 'unknown' : 'offline',
        ]);

        $this->audit->log(
            'printer.toggled',
            sprintf('Printer "%s" %s.', $printer->string('name'), $enable ? 'enabled' : 'disabled'),
            'printer',
            (string) $id,
            'notice'
        );

        return $this->back($request, 'success', $enable ? 'Printer enabled.' : 'Printer disabled.');
    }

    /** POST /admin/printers/{id}/maintenance */
    public function setMaintenance(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $printer = $this->printers->find($id);
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        $enter = $printer->status() !== 'maintenance';
        $this->printers->updateById($id, ['status' => $enter ? 'maintenance' : 'unknown']);

        $this->audit->log(
            'printer.maintenance',
            sprintf('Printer "%s" %s maintenance mode.', $printer->string('name'), $enter ? 'entered' : 'left'),
            'printer',
            (string) $id,
            'notice'
        );

        return $this->back(
            $request,
            'success',
            $enter
                ? 'Printer put into maintenance. Customers will not be offered it.'
                : 'Printer taken out of maintenance. It will be re-checked shortly.'
        );
    }

    /** POST /admin/printers/{id}/default */
    public function makeDefault(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $printer = $this->printers->find($id);
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        $this->printers->setDefault($id, $printer->int('location_id'));

        return $this->back($request, 'success', 'Set as the default printer for this location.');
    }

    /** DELETE /admin/printers/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $printer = $this->printers->find($id);
        if ($printer === null) {
            throw new HttpException(404, 'That printer was not found.');
        }

        $activeJobs = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM print_jobs
             WHERE printer_id = ? AND status IN ('queued','processing','printing','payment_pending')",
            [$id]
        );

        if ($activeJobs > 0) {
            return $this->back($request, 'error', sprintf(
                'This printer still has %d job(s) in progress. Wait for them to finish or cancel them first.',
                $activeJobs
            ));
        }

        $this->printers->softDelete($id);

        $this->audit->log(
            'printer.deleted',
            sprintf('Removed printer "%s".', $printer->string('name')),
            'printer',
            (string) $id,
            'warning'
        );

        return $this->redirect('/admin/printers', 'success', 'Printer removed.');
    }

    /** @return array<int,array<string,mixed>> */
    private function recentErrors(int $printerId): array
    {
        return $this->db->select(
            "SELECT j.job_number, j.error_message, j.error_code, j.completed_at, j.attempts
             FROM print_jobs j
             WHERE j.printer_id = ? AND j.status = 'failed'
             ORDER BY j.id DESC LIMIT 20",
            [$printerId]
        );
    }

    /**
     * @return array{printer:array<string,mixed>,connection:array<string,mixed>}
     */
    private function validatePrinter(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'code' => 'required|slug|max:40',
            'location_id' => 'required|int|min:1',
            'device_id' => 'nullable|int',
            'manufacturer' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:120',
            'capability_profile' => 'required|string|max:60',
            'queue_name' => 'nullable|string|max:120',
            'max_queue_depth' => 'nullable|int|between:0,500',
            'is_enabled' => 'nullable|bool',
            'notes' => 'nullable|string|max:2000',

            'driver' => 'required|in:agent,ipp,raw9100,lpd,null',
            'host' => 'nullable|host',
            'port' => 'nullable|port',
            'use_tls' => 'nullable|bool',
            'verify_tls' => 'nullable|bool',
            'ipp_path' => 'nullable|string|max:190',
            'lpd_queue' => 'nullable|string|max:120',
            'username' => 'nullable|string|max:120',
            'password' => 'nullable|string|max:190',
            'timeout_seconds' => 'nullable|int|between:2,120',
        ], [
            'code' => 'Printer code',
            'capability_profile' => 'Printer model profile',
        ])->validated();

        // The location must exist and be one we manage.
        if ($this->locations->find((int) $validated['location_id']) === null) {
            throw new HttpException(422, 'That location does not exist.');
        }

        $profiles = (array) Config::get('printing.profiles', []);
        $profileKey = (string) $validated['capability_profile'];
        if (!isset($profiles[$profileKey])) {
            $profileKey = 'generic';
        }

        $driver = (string) $validated['driver'];

        // A network transport needs a host; the agent transport needs a device.
        if (in_array($driver, ['ipp', 'raw9100', 'lpd'], true) && empty($validated['host'])) {
            throw new HttpException(422, 'A hostname or IP address is required for the ' . $driver . ' transport.');
        }
        if ($driver === 'agent' && empty($validated['device_id'])) {
            throw new HttpException(
                422,
                'Choose the print agent that will drive this printer. If there is none yet, add a device first.'
            );
        }

        $deviceId = isset($validated['device_id']) && (int) $validated['device_id'] > 0
            ? (int) $validated['device_id']
            : null;

        // An agent must belong to the same location as the printer it drives.
        if ($deviceId !== null) {
            $device = $this->devices->find($deviceId);
            if ($device === null || (int) $device['location_id'] !== (int) $validated['location_id']) {
                throw new HttpException(422, 'That print agent belongs to a different location.');
            }
        }

        $defaultPort = (int) (Config::get('printing.default_ports.' . $driver) ?? 0);
        $port = isset($validated['port']) && (int) $validated['port'] > 0
            ? (int) $validated['port']
            : ($defaultPort > 0 ? $defaultPort : null);

        $password = (string) ($validated['password'] ?? '');

        return [
            'printer' => [
                'name' => (string) $validated['name'],
                'code' => (string) $validated['code'],
                'location_id' => (int) $validated['location_id'],
                'device_id' => $deviceId,
                'manufacturer' => $validated['manufacturer'] ?? ($profiles[$profileKey]['manufacturer'] ?? null),
                'model' => $validated['model'] ?? ($profiles[$profileKey]['model'] ?? null),
                'capability_profile' => $profileKey,
                'queue_name' => $validated['queue_name'] ?? null,
                'max_queue_depth' => (int) ($validated['max_queue_depth'] ?? 25),
                'is_enabled' => ($validated['is_enabled'] ?? true) ? 1 : 0,
                'notes' => $validated['notes'] ?? null,
            ],
            'connection' => [
                'driver' => $driver,
                'priority' => 1,
                'host' => $validated['host'] ?? null,
                'port' => $port,
                'use_tls' => ($validated['use_tls'] ?? false) ? 1 : 0,
                'verify_tls' => ($validated['verify_tls'] ?? true) ? 1 : 0,
                'ipp_path' => $validated['ipp_path'] ?? ($driver === 'ipp' ? '/ipp/print' : null),
                'lpd_queue' => $validated['lpd_queue'] ?? ($driver === 'lpd' ? 'lp' : null),
                'username' => $validated['username'] ?? null,
                'password_encrypted' => $password !== '' ? Encrypter::encrypt($password) : null,
                'timeout_seconds' => (int) ($validated['timeout_seconds'] ?? 10),
                'is_enabled' => 1,
            ],
        ];
    }
}

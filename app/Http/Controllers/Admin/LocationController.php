<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\LocationRepository;
use App\Repositories\PricingRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Services\AuditService;
use App\Services\QRCodeService;

final class LocationController extends Controller
{
    /**
     * Codes that would collide with a fixed segment of the customer print URLs.
     * Kept in step with the reserved list in routes/web.php.
     */
    private const RESERVED_CODES = [
        'status', 'files', 'upload', 'quote', 'submit',
        'payment', 'pay', 'success', 'job',
    ];

    public function __construct(
        private LocationRepository $locations,
        private PrinterRepository $printers,
        private PricingRepository $pricing,
        private PrintJobRepository $jobs,
        private QRCodeService $qr,
        private AuditService $audit
    ) {
    }

    /** GET /admin/locations */
    public function index(Request $request): Response
    {
        $page = max(1, $request->int('page', 1));
        $search = $request->string('q');
        $status = $request->string('status');

        $result = $this->locations->paginate($page, 25, $search, $status);

        return $this->view('admin/locations/index', [
            'title' => 'Locations',
            'active' => 'locations',
            'result' => $result,
            'search' => $search,
            'status' => $status,
        ]);
    }

    /** GET /admin/locations/create */
    public function create(Request $request): Response
    {
        return $this->view('admin/locations/form', [
            'title' => 'Add location',
            'active' => 'locations',
            'location' => null,
            'formAction' => '/admin/locations',
        ]);
    }

    /** POST /admin/locations */
    public function store(Request $request): Response
    {
        $data = $this->validate($request);

        if ($this->locations->codeExists($data['code'])) {
            return $this->redirect('/admin/locations/create', 'error', 'That location code is already in use.');
        }

        // The token is generated here and shown once; only its hash is stored.
        $token = $this->qr->generateToken();

        $locationId = $this->locations->create($data + [
            'qr_token_hash' => $this->qr->hashToken($token),
            'qr_token_hint' => substr($token, 0, 8),
        ]);

        $this->audit->log(
            'location.created',
            sprintf('Created location "%s" (%s).', $data['name'], $data['code']),
            'location',
            (string) $locationId,
            'notice'
        );

        // Held in the session for exactly one page view, so the operator can
        // download the QR before the plaintext is gone for good.
        Session::put('new_qr_token_' . $locationId, $token);

        return $this->redirect(
            '/admin/locations/' . $locationId,
            'success',
            'Location created. Download its QR code now — the link cannot be shown again.'
        );
    }

    /** GET /admin/locations/{id} */
    public function show(Request $request): Response
    {
        $location = $this->locations->find((int) $request->routeParam('id'));
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        $token = Session::pull('new_qr_token_' . $location->id());

        $today = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', strtotime('-29 days'));

        return $this->view('admin/locations/show', [
            'title' => $location->string('name'),
            'active' => 'locations',
            'location' => $location,
            'printers' => $this->printers->forLocation($location->id(), false),
            'pricingRules' => $this->pricing->forLocation($location->id()),
            'recentJobs' => $this->jobs->paginate(
                ['location_id' => $location->id()],
                1,
                15
            ),
            'plainToken' => is_string($token) ? $token : null,
            'qrSvg' => is_string($token) ? $this->qr->svgFor($location->string('code'), $token, 6) : null,
            'qrUrl' => is_string($token) ? $this->qr->url($location->string('code'), $token) : null,
            'rangeFrom' => $from,
            'rangeTo' => $today,
        ]);
    }

    /** GET /admin/locations/{id}/edit */
    public function edit(Request $request): Response
    {
        $location = $this->locations->find((int) $request->routeParam('id'));
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        return $this->view('admin/locations/form', [
            'title' => 'Edit ' . $location->string('name'),
            'active' => 'locations',
            'location' => $location,
            'formAction' => '/admin/locations/' . $location->id(),
        ]);
    }

    /** PUT /admin/locations/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $location = $this->locations->find($id);
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        $data = $this->validate($request);

        if ($this->locations->codeExists($data['code'], $id)) {
            return $this->redirect('/admin/locations/' . $id . '/edit', 'error', 'That location code is already in use.');
        }

        $this->locations->updateById($id, $data);

        $this->audit->logChanges(
            'location.updated',
            'location',
            (string) $id,
            $location->toArray(),
            $data,
            $data['name']
        );

        return $this->redirect('/admin/locations/' . $id, 'success', 'Location updated.');
    }

    /** POST /admin/locations/{id}/rotate-qr */
    public function rotateQr(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $location = $this->locations->find($id);
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        $admin = $request->attribute('admin');
        $token = $this->qr->rotateToken($id, $admin?->id());

        Session::put('new_qr_token_' . $id, $token);

        return $this->redirect(
            '/admin/locations/' . $id,
            'warning',
            'A new QR code has been issued. Every previously printed code for this location has '
            . 'stopped working — replace them before customers arrive.'
        );
    }

    /**
     * GET /admin/locations/{id}/qr.{format}
     *
     * The plaintext token is not stored, so this only works immediately after
     * creation or rotation, while it is still in the session. That is the
     * cost of not keeping a working QR link in the database — and it is the
     * right trade.
     */
    public function downloadQr(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $format = strtolower((string) $request->routeParam('format'));

        $location = $this->locations->find($id);
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        $token = Session::get('new_qr_token_' . $id);
        if (!is_string($token)) {
            return $this->redirect(
                '/admin/locations/' . $id,
                'error',
                'The QR link is no longer available to download. Rotate the code to issue a new one.'
            );
        }

        $code = $location->string('code');
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $code) ?: 'location';

        return match ($format) {
            'svg' => Response::download(
                $this->qr->svgFor($code, $token, 10),
                'qr-' . $safeName . '.svg',
                'image/svg+xml'
            ),
            'png' => (function () use ($code, $token, $safeName): Response {
                $png = $this->qr->pngFor($code, $token, 12);
                if ($png === null) {
                    throw new HttpException(503, 'PNG export needs the GD extension, which is not installed.');
                }
                return Response::download($png, 'qr-' . $safeName . '.png', 'image/png');
            })(),
            'sheet' => Response::html($this->qr->printableSheet($location, $token)),
            default => throw new HttpException(404, 'Unsupported format.'),
        };
    }

    /** POST /admin/locations/{id}/status */
    public function setStatus(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $location = $this->locations->find($id);
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        $status = $request->string('status');
        if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) {
            return $this->back($request, 'error', 'That status is not valid.');
        }

        $this->locations->updateById($id, ['status' => $status]);

        $this->audit->log(
            'location.status_changed',
            sprintf('Location "%s" set to %s.', $location->string('name'), $status),
            'location',
            (string) $id,
            'notice'
        );

        return $this->back($request, 'success', 'Location status updated.');
    }

    /** DELETE /admin/locations/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $location = $this->locations->find($id);
        if ($location === null) {
            throw new HttpException(404, 'That location was not found.');
        }

        // Jobs reference locations with ON DELETE RESTRICT, deliberately: a
        // location with print history is disabled, never erased, so the
        // financial record stays intact.
        $jobCount = (int) \App\Core\Database::instance()->scalar(
            'SELECT COUNT(*) FROM print_jobs WHERE location_id = ?',
            [$id]
        );

        if ($jobCount > 0) {
            $this->locations->softDelete($id);
            $this->audit->log(
                'location.disabled',
                sprintf(
                    'Location "%s" was disabled (it has %d job(s) of history, so it was not deleted).',
                    $location->string('name'),
                    $jobCount
                ),
                'location',
                (string) $id,
                'notice'
            );
            return $this->redirect(
                '/admin/locations',
                'success',
                sprintf(
                    'Location disabled. It has %s print job(s) of history, so the record was kept for reporting.',
                    number_format($jobCount)
                )
            );
        }

        $this->locations->deleteById($id);
        $this->audit->log(
            'location.deleted',
            sprintf('Deleted location "%s".', $location->string('name')),
            'location',
            (string) $id,
            'warning'
        );

        return $this->redirect('/admin/locations', 'success', 'Location deleted.');
    }

    /** GET /admin/locations/qr-sheet — every active location's QR on one page. */
    public function batchQrSheet(Request $request): Response
    {
        $entries = [];

        foreach ($this->locations->allActive() as $location) {
            $token = Session::get('new_qr_token_' . $location->id());
            if (is_string($token)) {
                $entries[] = ['location' => $location, 'token' => $token];
            }
        }

        if ($entries === []) {
            return $this->redirect(
                '/admin/locations',
                'info',
                'QR links are only downloadable right after a code is created or rotated. '
                . 'Rotate the codes you need, then return here.'
            );
        }

        return Response::html($this->qr->batchSheet($entries));
    }

    /** @return array<string,mixed> */
    private function validate(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'code' => 'required|slug|max:40',
            'address_line1' => 'nullable|string|max:190',
            'address_line2' => 'nullable|string|max:190',
            'city' => 'nullable|string|max:90',
            'state' => 'nullable|string|max:90',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:2',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:32',
            'contact_email' => 'nullable|email',
            'timezone' => 'nullable|string|max:64',
            'status' => 'required|in:active,inactive,maintenance',
            'notes' => 'nullable|string|max:2000',
        ], [
            'code' => 'Location code',
        ])->validated();

        // A location code becomes the first segment of its QR URL, so a code
        // that collides with a fixed customer path would produce a QR that can
        // never resolve. Refuse it here rather than let the operator print
        // hundreds of stickers for a dead link.
        if (in_array(strtolower((string) $validated['code']), self::RESERVED_CODES, true)) {
            throw new HttpException(422, sprintf(
                'The location code "%s" is reserved by the print URLs. Please choose another.',
                (string) $validated['code']
            ));
        }

        $validated['country'] = strtoupper((string) ($validated['country'] ?? 'IN')) ?: 'IN';
        $validated['timezone'] = $this->safeTimezone((string) ($validated['timezone'] ?? ''));

        return $validated;
    }

    private function safeTimezone(string $timezone): string
    {
        $default = (string) Config::get('app.timezone', 'Asia/Kolkata');
        if ($timezone === '') {
            return $default;
        }
        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : $default;
    }
}

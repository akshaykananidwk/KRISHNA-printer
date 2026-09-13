<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\PartnerRepository;
use App\Services\PartnerService;

/**
 * The shop-registration queue.
 *
 * Registrations arrive from the public form and sit here until an operator
 * looks at them. Approving is the only thing that creates a location and a
 * print agent, which is why it lives behind the admin authentication and the
 * role gate rather than happening automatically on the public side.
 */
final class RegistrationController extends Controller
{
    public function __construct(
        private PartnerRepository $partners,
        private PartnerService $service
    ) {
    }

    /** GET /admin/registrations */
    public function index(Request $request): Response
    {
        $status = $request->string('status');
        if (!in_array($status, ['pending', 'approved', 'rejected', 'suspended'], true)) {
            $status = '';
        }

        return $this->view('admin/registrations/index', [
            'title' => 'Shop registrations',
            'active' => 'registrations',
            'registrations' => $this->partners->queue($status),
            'status' => $status,
            'pending' => $this->partners->countPending(),
        ]);
    }

    /** POST /admin/registrations/{id}/approve */
    public function approve(Request $request): Response
    {
        $partner = $this->find($request);

        if ($partner->isApproved()) {
            return $this->redirect('/admin/registrations', 'info', 'That shop is already approved.');
        }

        $admin = $request->attribute('admin');
        $result = $this->service->approve($partner, $admin?->id());

        return $this->redirect(
            '/admin/locations/' . $result['location_id'],
            'success',
            sprintf(
                'Approved "%s". Its location and print agent are ready — print the QR code now, '
                . 'and the shop issues its own agent token when it signs in.',
                $partner->string('shop_name')
            )
        );
    }

    /** POST /admin/registrations/{id}/reject */
    public function reject(Request $request): Response
    {
        $partner = $this->find($request);
        $reason = trim($request->string('reason'));

        if ($reason === '') {
            return $this->redirect(
                '/admin/registrations',
                'error',
                'Give a reason. The shop is shown it, and a refusal with no reason only produces a phone call.'
            );
        }

        $admin = $request->attribute('admin');
        $this->service->reject($partner, $admin?->id(), $reason);

        return $this->redirect('/admin/registrations', 'success', 'Registration declined.');
    }

    /** POST /admin/registrations/{id}/suspend */
    public function suspend(Request $request): Response
    {
        $partner = $this->find($request);
        $reason = trim($request->string('reason'));

        if (!$partner->isApproved()) {
            return $this->redirect('/admin/registrations', 'error', 'That shop is not live.');
        }

        $admin = $request->attribute('admin');
        $this->service->suspend($partner, $admin?->id(), $reason === '' ? 'No reason given.' : $reason);

        return $this->redirect(
            '/admin/registrations',
            'success',
            'Shop suspended. Its location and printers are untouched — disable those separately if you mean to.'
        );
    }

    private function find(Request $request): \App\Models\Partner
    {
        $partner = $this->partners->find((int) $request->routeParam('id'));
        if ($partner === null) {
            throw new HttpException(404, 'That registration was not found.');
        }
        return $partner;
    }
}

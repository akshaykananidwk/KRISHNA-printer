<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Repositories\LocationRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintJobRepository;
use App\Services\AuditService;
use App\Services\PaymentService;
use App\Services\PrintQueueService;

final class JobController extends Controller
{
    public function __construct(
        private PrintJobRepository $jobs,
        private LocationRepository $locations,
        private PrinterRepository $printers,
        private PaymentRepository $payments,
        private PrintQueueService $queue,
        private PaymentService $paymentService,
        private AuditService $audit
    ) {
    }

    /** GET /admin/jobs */
    public function index(Request $request): Response
    {
        $filters = $this->filtersFrom($request);
        $result = $this->jobs->paginate($filters, max(1, $request->int('page', 1)), 30);

        return $this->view('admin/jobs/index', [
            'title' => 'Print jobs',
            'active' => 'jobs',
            'result' => $result,
            'filters' => $filters,
            'locations' => $this->locations->all(),
            'printers' => $this->printers->all(),
            'statuses' => PrintJob::ALL_STATUSES,
        ]);
    }

    /** GET /admin/jobs/{number} */
    public function show(Request $request): Response
    {
        $number = (string) $request->routeParam('number');
        $detail = $this->jobs->detailByNumber($number);

        if ($detail === null) {
            throw new HttpException(404, 'That job was not found.');
        }

        $job = PrintJob::fromRow($detail);
        $breakdown = $job->json('price_breakdown');

        return $this->view('admin/jobs/show', [
            'title' => 'Job ' . $number,
            'active' => 'jobs',
            'job' => $job,
            'detail' => $detail,
            'events' => $this->jobs->events($job->id(), 50),
            'breakdown' => $breakdown,
            'payment' => $job->int('payment_id') > 0
                ? $this->payments->find($job->int('payment_id'))
                : null,
            'batchJobs' => $job->string('batch_id') !== ''
                ? $this->jobs->detailForBatch($job->string('batch_id'))
                : [],
        ]);
    }

    /** POST /admin/jobs/{number}/cancel */
    public function cancel(Request $request): Response
    {
        $job = $this->jobs->findByNumber((string) $request->routeParam('number'));
        if ($job === null) {
            return $this->fail('That job was not found.', 404);
        }

        $admin = $request->attribute('admin');
        $reason = $request->string('reason');
        if ($reason === '') {
            $reason = 'Cancelled by an operator.';
        }

        $result = $this->queue->cancelJob(
            $job->id(),
            $reason,
            'admin',
            $admin === null ? null : (string) $admin->id()
        );

        if (!$result['success']) {
            return $request->isAjax()
                ? $this->fail($result['message'], 409)
                : $this->back($request, 'error', $result['message']);
        }

        $message = $result['message'];
        if ($result['refund_due'] ?? false) {
            $message .= ' This job was paid for, so a refund may be owed — issue it from the payment record.';
        }

        return $request->isAjax()
            ? $this->ok($message, ['refund_due' => $result['refund_due'] ?? false])
            : $this->back($request, 'success', $message);
    }

    /** POST /admin/jobs/{number}/retry */
    public function retry(Request $request): Response
    {
        $job = $this->jobs->findByNumber((string) $request->routeParam('number'));
        if ($job === null) {
            return $this->fail('That job was not found.', 404);
        }

        $admin = $request->attribute('admin');
        $result = $this->queue->retryFailedJob(
            $job->id(),
            'admin',
            $admin === null ? null : (string) $admin->id()
        );

        return $request->isAjax()
            ? ($result['success'] ? $this->ok($result['message']) : $this->fail($result['message'], 409))
            : $this->back($request, $result['success'] ? 'success' : 'error', $result['message']);
    }

    /** POST /admin/jobs/{number}/refund */
    public function refund(Request $request): Response
    {
        $job = $this->jobs->findByNumber((string) $request->routeParam('number'));
        if ($job === null) {
            return $this->fail('That job was not found.', 404);
        }

        $paymentId = $job->int('payment_id');
        if ($paymentId === 0) {
            return $this->back($request, 'error', 'This job has no payment to refund.');
        }

        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:200',
            'amount' => 'nullable|decimal',
        ])->validated();

        $admin = $request->attribute('admin');
        $amountPaise = isset($data['amount']) && $data['amount'] !== null
            ? \App\Support\Money::toPaise((float) $data['amount'])
            : null;

        $result = $this->paymentService->refund(
            $paymentId,
            $amountPaise,
            (string) $data['reason'],
            $admin?->id()
        );

        return $this->back(
            $request,
            $result['success'] ? 'success' : 'error',
            $result['message']
        );
    }

    /** GET /admin/jobs/live — polled by the jobs screen. */
    public function live(Request $request): Response
    {
        $filters = $this->filtersFrom($request);
        $result = $this->jobs->paginate($filters, 1, 30);

        $rows = array_map(static function (array $row): array {
            $job = PrintJob::fromRow($row);
            return [
                'job_number' => $job->jobNumber(),
                'status' => $job->status(),
                'status_label' => $job->statusLabel(),
                'status_tone' => $job->statusTone(),
                'payment_status' => $job->string('payment_status'),
                'attempts' => $job->int('attempts'),
                'error_message' => $job->string('error_message'),
            ];
        }, $result['rows']);

        return $this->json(['success' => true, 'jobs' => $rows, 'total' => $result['total']]);
    }

    /** @return array<string,mixed> */
    private function filtersFrom(Request $request): array
    {
        $filters = [];

        foreach (['location_id', 'printer_id'] as $key) {
            $value = $request->int($key, 0);
            if ($value > 0) {
                $filters[$key] = $value;
            }
        }

        $status = $request->string('status');
        if ($status !== '' && in_array($status, PrintJob::ALL_STATUSES, true)) {
            $filters['status'] = [$status];
        }

        foreach (['payment_status', 'color_mode', 'paper_size', 'duplex', 'search'] as $key) {
            $value = $request->string($key);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        foreach (['date_from', 'date_to'] as $key) {
            $value = $request->string($key);
            if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $filters[$key] = $value;
            }
        }

        $filters['sort'] = $request->string('sort', 'created_at');
        $filters['direction'] = $request->string('direction', 'DESC');

        return $filters;
    }
}

<?php
declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Repositories\LocationRepository;
use App\Repositories\PrinterRepository;
use App\Repositories\PrintFileRepository;
use App\Repositories\PrintJobRepository;
use App\Repositories\PrintSessionRepository;
use App\Services\AuditService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Services\PrinterCapabilityService;
use App\Services\PrinterService;
use App\Services\PrintQueueService;
use App\Services\RateLimiter;
use App\Support\Money;
use App\Support\PageRange;

/**
 * The customer journey:
 *
 *   QR scan → printer check → upload → options → price → pay → print → receipt
 *
 * The printer check is not a formality. Every step that could lead to a
 * payment re-verifies that the printer is genuinely able to print, because the
 * customer may spend several minutes configuring a job during which the
 * printer can run out of paper, be switched off, or be taken for maintenance.
 */
final class PrintController extends Controller
{
    public function __construct(
        private LocationRepository $locations,
        private PrinterRepository $printers,
        private PrintSessionRepository $sessions,
        private PrintFileRepository $files,
        private PrintJobRepository $jobs,
        private PrinterService $printerService,
        private PrinterCapabilityService $capabilities,
        private PricingService $pricing,
        private PrintQueueService $queue,
        private PaymentService $payments,
        private RateLimiter $limiter,
        private AuditService $audit
    ) {
    }

    /**
     * GET /print/{location}/{token}
     *
     * The QR landing page. Resolves the location, picks its printer, and gates
     * everything on that printer being usable right now.
     */
    public function landing(Request $request): Response
    {
        $locationCode = (string) $request->routeParam('location');
        $token = (string) $request->routeParam('token');

        // Throttle scans per IP: a valid location code plus a guessed token
        // should not be brute-forceable.
        $limit = $this->limiter->hit(
            'qr:' . $request->ip(),
            (int) Config::get('security.rate_limit.qr_scan_max', 60),
            (int) Config::get('security.rate_limit.qr_scan_decay', 300)
        );
        if (!$limit['allowed']) {
            throw new HttpException(429, 'Too many requests. Please wait a moment and scan again.');
        }

        $location = $this->locations->findByQrToken($locationCode, $token);

        if ($location === null) {
            $this->audit->log(
                'qr.invalid',
                'A QR link was scanned that did not match any location.',
                'location',
                $locationCode,
                'notice',
                ['ip' => $request->ip()]
            );
            return $this->view('customer/invalid-link', [
                'title' => 'Link not recognised',
            ], 404);
        }

        if (!$location->isActive()) {
            return $this->view('customer/unavailable', [
                'title' => 'Not available',
                'location' => $location,
                'heading' => 'Printing Service Currently Unavailable',
                'message' => $location->string('status') === 'maintenance'
                    ? 'This location is temporarily closed for maintenance. Please try again later.'
                    : 'This location is not currently accepting print jobs.',
            ], 503);
        }

        $printer = $this->printers->defaultForLocation($location->id());

        if ($printer === null) {
            return $this->view('customer/unavailable', [
                'title' => 'Not available',
                'location' => $location,
                'heading' => 'Printing Service Currently Unavailable',
                'message' => 'No printer is set up at this location yet. Please try again later.',
            ], 503);
        }

        // The gate. A live probe, not a cached flag.
        $availability = $this->printerService->availabilityFor($printer->id());

        if (!$availability['available']) {
            $this->audit->log(
                'print.blocked_printer_unavailable',
                sprintf(
                    'A customer at "%s" was blocked from uploading: %s',
                    $location->string('name'),
                    $availability['reason']
                ),
                'printer',
                (string) $printer->id(),
                'notice'
            );

            return $this->view('customer/unavailable', [
                'title' => 'Not available',
                'location' => $location,
                'printer' => $printer,
                'heading' => 'Printing Service Currently Unavailable',
                'message' => $availability['reason'],
                'canRetry' => true,
                'retryUrl' => $request->path(),
            ], 503);
        }

        // The printer is usable — establish the basket and show the uploader.
        $sessionToken = Session::customerSessionId();
        $printSession = $this->sessions->ensure(
            $sessionToken,
            $location->id(),
            $printer->id(),
            hash_hmac('sha256', $request->ip(), (string) Config::get('app.key', 'kpms')),
            $request->userAgent()
        );

        Session::put('print_location_id', $location->id());
        Session::put('print_printer_id', $printer->id());
        Session::put('print_session_id', (int) $printSession['id']);

        $options = $this->capabilities->customerOptions($printer);

        // A printer with no verified capabilities cannot take a job — offering
        // options we have not confirmed would be selling something we cannot
        // deliver.
        if (!$options['verified']) {
            return $this->view('customer/unavailable', [
                'title' => 'Not available',
                'location' => $location,
                'printer' => $printer,
                'heading' => 'Printing Service Currently Unavailable',
                'message' => 'This printer is still being set up. Please ask staff for assistance.',
            ], 503);
        }

        $priceList = $this->pricing->priceList(
            $location->id(),
            $printer->id(),
            array_column($options['paper_sizes'], 'value'),
            array_column($options['color_modes'], 'value')
        );

        $existingFiles = $this->files->forSession((int) $printSession['id']);

        return $this->view('customer/upload', [
            'title' => 'Print at ' . $location->string('name'),
            'location' => $location,
            'printer' => $printer,
            'options' => $options,
            'priceList' => $priceList,
            'existingFiles' => array_map(fn (array $f): array => $this->fileSummary($f), $existingFiles),
            'maxFileBytes' => (int) Config::get('uploads.max_file_bytes', 26214400),
            'maxFiles' => (int) Config::get('uploads.max_files_per_batch', 10),
            'allowedExtensions' => array_keys((array) Config::get('uploads.allowed', [])),
            'copyPresets' => (array) Config::get('printing.copy_presets', [1, 2, 3, 5, 10]),
            'maxCopies' => (int) Config::get('printing.max_copies', 100),
            'paymentEnabled' => $this->payments->isEnabled(),
            'qrPath' => $request->path(),
        ]);
    }

    /**
     * GET /print/status/{printer}
     *
     * Polled by the upload page so a printer that goes offline mid-session
     * blocks the customer before they pay rather than after.
     */
    public function printerStatus(Request $request): Response
    {
        $printerId = (int) $request->routeParam('printer');
        $sessionPrinterId = (int) Session::get('print_printer_id', 0);

        // Scoped to the printer this session actually landed on, so the
        // endpoint cannot be used to enumerate the estate.
        if ($printerId !== $sessionPrinterId) {
            return $this->fail('Unknown printer.', 404);
        }

        $availability = $this->printerService->availabilityFor($printerId);

        return $this->json([
            'success' => true,
            'available' => $availability['available'],
            'status' => $availability['status'],
            'reason' => $availability['reason'],
            'queue_depth' => (int) ($availability['detail']['queue_depth'] ?? 0),
        ]);
    }

    /**
     * POST /print/quote
     *
     * Live price calculation for the options screen. Computes server-side from
     * the stored file, so a tampered page cannot lower the price.
     */
    public function quote(Request $request): Response
    {
        $context = $this->requireSession();
        if ($context instanceof Response) {
            return $context;
        }
        [$location, $printer, $printSession] = $context;

        $items = $request->array('items');
        if ($items === []) {
            return $this->fail('Nothing to price.');
        }
        if (count($items) > (int) Config::get('uploads.max_files_per_batch', 10)) {
            return $this->fail('Too many documents in one order.');
        }

        $specs = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $fileId = (int) ($item['file_id'] ?? 0);
            $file = $this->files->findForSession($fileId, (int) $printSession['id']);

            if ($file === null) {
                $errors[] = 'One of the documents is no longer available. Please upload it again.';
                continue;
            }

            $selection = $this->normaliseSelection($item, $printer);
            $capabilityErrors = $this->capabilities->validateSelection($printer, $selection);

            if ($capabilityErrors !== []) {
                $errors[] = reset($capabilityErrors);
                continue;
            }

            $documentPages = (int) $file['page_count'];
            $pageRange = (string) $selection['page_range'];

            if ($pageRange !== PageRange::ALL) {
                if (!PageRange::isValid($pageRange)) {
                    $errors[] = 'The page range for "' . $file['original_name'] . '" is not valid.';
                    continue;
                }
                if (PageRange::count($pageRange, $documentPages) === 0) {
                    $errors[] = sprintf(
                        'The page range "%s" selects no pages of "%s", which has %d page%s.',
                        $pageRange,
                        $file['original_name'],
                        $documentPages,
                        $documentPages === 1 ? '' : 's'
                    );
                    continue;
                }
            }

            $specs[] = [
                'location_id' => $location->id(),
                'printer_id' => $printer->id(),
                'paper_size' => $selection['paper_size'],
                'color_mode' => $selection['color_mode'],
                'duplex' => $selection['duplex'],
                'orientation' => $selection['orientation'],
                'copies' => $selection['copies'],
                'page_range' => $pageRange,
                'document_pages' => $documentPages,
                'file_id' => $fileId,
                'file_name' => (string) $file['original_name'],
                'page_count_source' => (string) $file['page_count_source'],
            ];
        }

        if ($errors !== []) {
            return $this->fail(implode(' ', array_unique($errors)), 422);
        }
        if ($specs === []) {
            return $this->fail('Nothing to price.');
        }

        $result = $this->pricing->calculateBatch($specs);

        // Attach the file name back onto each line so the summary can show it.
        foreach ($result['items'] as $index => &$item) {
            $item['file_id'] = $specs[$index]['file_id'] ?? null;
            $item['file_name'] = $specs[$index]['file_name'] ?? '';
        }
        unset($item);

        if ((int) $result['totals']['unpriced'] > 0) {
            $firstError = '';
            foreach ($result['items'] as $item) {
                if (!$item['priced']) {
                    $firstError = (string) ($item['error'] ?? '');
                    break;
                }
            }
            return $this->fail($firstError !== '' ? $firstError : 'This order could not be priced.', 422);
        }

        return $this->json([
            'success' => true,
            'items' => $result['items'],
            'totals' => $result['totals'],
            'payment_required' => $this->payments->isEnabled() && (int) $result['totals']['total_paise'] > 0,
        ]);
    }

    /**
     * POST /print/submit
     *
     * Creates the jobs. When payment is enabled the jobs are created in
     * payment_pending and go no further until PaymentService verifies the money
     * server-side.
     */
    public function submit(Request $request): Response
    {
        $context = $this->requireSession();
        if ($context instanceof Response) {
            return $context;
        }
        [$location, $printer, $printSession] = $context;

        // Final gate before anything is charged for.
        $availability = $this->printerService->availabilityFor($printer->id());
        if (!$availability['available']) {
            return $this->fail($availability['reason'], 409, ['printer_unavailable' => true]);
        }

        $items = $request->array('items');
        if ($items === []) {
            return $this->fail('Please choose at least one document.');
        }

        $limit = $this->limiter->hit(
            'submit:' . $request->ip(),
            (int) Config::get('security.rate_limit.payment_max', 15),
            (int) Config::get('security.rate_limit.payment_decay', 300)
        );
        if (!$limit['allowed']) {
            return $this->fail('Too many attempts. Please wait a moment.', 429);
        }

        // Re-price from scratch: the amount charged is derived here, never
        // taken from the request.
        $specs = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fileId = (int) ($item['file_id'] ?? 0);
            $file = $this->files->findForSession($fileId, (int) $printSession['id']);
            if ($file === null) {
                return $this->fail('One of your documents is no longer available. Please upload it again.', 409);
            }

            $selection = $this->normaliseSelection($item, $printer);
            $capabilityErrors = $this->capabilities->validateSelection($printer, $selection);
            if ($capabilityErrors !== []) {
                return $this->fail(reset($capabilityErrors), 422);
            }

            $specs[] = [
                'location_id' => $location->id(),
                'printer_id' => $printer->id(),
                'paper_size' => $selection['paper_size'],
                'color_mode' => $selection['color_mode'],
                'duplex' => $selection['duplex'],
                'orientation' => $selection['orientation'],
                'copies' => $selection['copies'],
                'page_range' => $selection['page_range'],
                'document_pages' => (int) $file['page_count'],
                'file_id' => $fileId,
                'page_count_source' => (string) $file['page_count_source'],
            ];
        }

        if ($specs === []) {
            return $this->fail('Please choose at least one document.');
        }

        $priced = $this->pricing->calculateBatch($specs);
        if ((int) $priced['totals']['unpriced'] > 0) {
            return $this->fail('This order could not be priced. Please ask staff for assistance.', 422);
        }

        $batchId = bin2hex(random_bytes(16));
        $created = [];

        foreach ($priced['items'] as $index => $pricingResult) {
            $spec = $specs[$index];

            $result = $this->queue->createJob([
                'batch_id' => $batchId,
                'location_id' => $location->id(),
                'printer_id' => $printer->id(),
                'file_id' => $spec['file_id'],
                'session_id' => (int) $printSession['id'],
                'orientation' => $spec['orientation'],
                'paper_size' => $spec['paper_size'],
                'color_mode' => $spec['color_mode'],
                'duplex' => $spec['duplex'],
                'page_count_source' => $spec['page_count_source'],
            ], $pricingResult);

            if (!$result['success']) {
                // Roll the whole basket back: a partially-created order the
                // customer then pays for in full would be worse than a clean
                // failure they can retry.
                foreach ($created as $jobId) {
                    $this->queue->cancelJob(
                        $jobId,
                        'The order could not be completed in full.',
                        'system',
                        null
                    );
                }
                return $this->fail((string) $result['error'], 422);
            }

            $created[] = (int) $result['job_id'];
        }

        $totalPaise = (int) $priced['totals']['total_paise'];
        $paymentRequired = $this->payments->isEnabled() && $totalPaise > 0;

        Session::put('current_batch_id', $batchId);

        $this->audit->log(
            'print.submitted',
            sprintf(
                '%d job(s) created at "%s" for %s.',
                count($created),
                $location->string('name'),
                Money::formatWithSymbol($totalPaise)
            ),
            'print_job',
            $batchId,
            'info'
        );

        if (!$paymentRequired) {
            return $this->json([
                'success' => true,
                'payment_required' => false,
                'batch_id' => $batchId,
                'redirect' => '/print/success/' . rawurlencode($batchId),
            ]);
        }

        $order = $this->payments->createOrder($batchId, $location->id(), (int) $printSession['id']);

        if (!$order['success']) {
            foreach ($created as $jobId) {
                $this->queue->cancelJob($jobId, 'Payment could not be started.', 'system', null);
            }
            return $this->fail((string) $order['error'], 502);
        }

        return $this->json([
            'success' => true,
            'payment_required' => true,
            'batch_id' => $batchId,
            'order_id' => $order['order_id'],
            'amount_paise' => $order['amount_paise'],
            'checkout' => $this->payments->checkoutConfig(
                (string) $order['order_id'],
                (int) $order['amount_paise'],
                sprintf('%d document(s) at %s', count($created), $location->string('name'))
            ),
            'redirect' => '/print/pay/' . rawurlencode($batchId),
        ]);
    }

    /** GET /print/pay/{batch} — the checkout page. */
    public function payment(Request $request): Response
    {
        $batchId = (string) $request->routeParam('batch');

        if (!$this->ownsBatch($batchId)) {
            throw new HttpException(404, 'That order was not found.');
        }

        $jobs = $this->jobs->detailForBatch($batchId);
        if ($jobs === []) {
            throw new HttpException(404, 'That order was not found.');
        }

        $payment = $this->payments->isEnabled()
            ? $this->payments->createOrder(
                $batchId,
                (int) $jobs[0]['location_id'],
                $jobs[0]['session_id'] === null ? null : (int) $jobs[0]['session_id']
            )
            : ['success' => false, 'error' => 'Payments are disabled.'];

        // Already paid — send them to the receipt rather than a dead checkout.
        $allPaid = true;
        foreach ($jobs as $job) {
            if ((string) $job['payment_status'] !== 'paid' && (string) $job['payment_status'] !== 'not_required') {
                $allPaid = false;
                break;
            }
        }
        if ($allPaid) {
            return Response::redirect('/print/success/' . rawurlencode($batchId));
        }

        if (!$payment['success']) {
            return $this->view('customer/unavailable', [
                'title' => 'Payment unavailable',
                'heading' => 'Payment is unavailable',
                'message' => (string) $payment['error'],
            ], 503);
        }

        $total = array_sum(array_map(static fn (array $j): int => (int) $j['total_paise'], $jobs));

        return $this->view('customer/payment', [
            'title' => 'Complete payment',
            'batchId' => $batchId,
            'jobs' => $jobs,
            'totalPaise' => $total,
            'totalDisplay' => Money::formatWithSymbol($total),
            'orderId' => (string) $payment['order_id'],
            'checkout' => $this->payments->checkoutConfig(
                (string) $payment['order_id'],
                (int) $payment['amount_paise'],
                sprintf('%d document(s)', count($jobs))
            ),
            'checkoutScript' => (string) Config::get('payment.gateways.razorpay.checkout_script', ''),
            'locationName' => (string) $jobs[0]['location_name'],
        ]);
    }

    /**
     * POST /print/payment/verify
     *
     * Called by the checkout handler. The values it passes are treated as
     * claims to be checked, never as facts.
     */
    public function verifyPayment(Request $request): Response
    {
        $orderId = $request->string('razorpay_order_id');
        $paymentId = $request->string('razorpay_payment_id');
        $signature = $request->string('razorpay_signature');

        if ($orderId === '' || $paymentId === '') {
            return $this->fail('The payment response was incomplete.', 422);
        }

        $limit = $this->limiter->hit(
            'verify:' . $request->ip(),
            (int) Config::get('security.rate_limit.payment_max', 15),
            (int) Config::get('security.rate_limit.payment_decay', 300)
        );
        if (!$limit['allowed']) {
            return $this->fail('Too many attempts. Please wait a moment.', 429);
        }

        $result = $this->payments->verifyAndRelease($orderId, $paymentId, $signature);

        if (!$result['success']) {
            return $this->fail($result['message'], 402);
        }

        return $this->json([
            'success' => true,
            'message' => $result['message'],
            'redirect' => '/print/success/' . rawurlencode((string) $result['batch_id']),
        ]);
    }

    /** POST /print/payment/cancelled — the customer dismissed the checkout. */
    public function paymentCancelled(Request $request): Response
    {
        $batchId = $request->string('batch_id');

        if (!$this->ownsBatch($batchId)) {
            return $this->fail('That order was not found.', 404);
        }

        // The jobs stay in payment_pending; the scheduled sweeper cancels them
        // if payment never arrives. That way a customer who reopens the
        // checkout can still pay for the same order.
        return $this->json([
            'success' => true,
            'message' => 'Payment was not completed. Your order is still open — you can pay for it now or start again.',
            'retry_url' => '/print/pay/' . rawurlencode($batchId),
        ]);
    }

    /** GET /print/success/{batch} — the receipt. */
    public function success(Request $request): Response
    {
        $batchId = (string) $request->routeParam('batch');

        if (!$this->ownsBatch($batchId)) {
            throw new HttpException(404, 'That order was not found.');
        }

        $jobs = $this->jobs->detailForBatch($batchId);
        if ($jobs === []) {
            throw new HttpException(404, 'That order was not found.');
        }

        $total = 0;
        $pages = 0;
        foreach ($jobs as $job) {
            $total += (int) $job['total_paise'];
            $pages += (int) $job['billable_pages'];
        }

        return $this->view('customer/success', [
            'title' => 'Print job created',
            'jobs' => $jobs,
            'batchId' => $batchId,
            'totalPaise' => $total,
            'totalDisplay' => Money::formatWithSymbol($total),
            'totalPages' => $pages,
            'primaryJobNumber' => (string) $jobs[0]['job_number'],
            'locationName' => (string) $jobs[0]['location_name'],
            'printerName' => (string) $jobs[0]['printer_name'],
        ]);
    }

    /**
     * GET /print/job/{number}/status
     *
     * Polled by the receipt page so the customer sees "printing" then
     * "completed" without refreshing.
     */
    public function jobStatus(Request $request): Response
    {
        $jobNumber = (string) $request->routeParam('number');
        $job = $this->jobs->findByNumber($jobNumber);

        if ($job === null) {
            return $this->fail('That job number was not found.', 404);
        }

        // Only the session that created the job may poll it.
        $sessionId = (int) Session::get('print_session_id', 0);
        if ($job->int('session_id') !== $sessionId && !$this->isAdmin($request)) {
            return $this->fail('That job number was not found.', 404);
        }

        return $this->json([
            'success' => true,
            'job_number' => $job->jobNumber(),
            'status' => $job->status(),
            'status_label' => $job->statusLabel(),
            'status_tone' => $job->statusTone(),
            'payment_status' => $job->string('payment_status'),
            'error_message' => $job->string('error_message'),
            'is_terminal' => $job->isTerminal(),
        ]);
    }

    /** POST /print/job/{number}/cancel — a customer withdrawing their own job. */
    public function cancelJob(Request $request): Response
    {
        $jobNumber = (string) $request->routeParam('number');
        $job = $this->jobs->findByNumber($jobNumber);

        if ($job === null) {
            return $this->fail('That job number was not found.', 404);
        }

        $sessionId = (int) Session::get('print_session_id', 0);
        if ($job->int('session_id') !== $sessionId) {
            return $this->fail('That job number was not found.', 404);
        }

        $result = $this->queue->cancelJob(
            $job->id(),
            'Cancelled by the customer.',
            'customer',
            (string) $sessionId
        );

        return $result['success']
            ? $this->ok($result['message'], ['refund_due' => $result['refund_due'] ?? false])
            : $this->fail($result['message'], 409);
    }

    // -----------------------------------------------------------------

    /**
     * Resolve the current customer session, or return an error response.
     *
     * @return array{0:\App\Models\Location,1:\App\Models\Printer,2:array<string,mixed>}|Response
     */
    private function requireSession(): array|Response
    {
        $locationId = (int) Session::get('print_location_id', 0);
        $printerId = (int) Session::get('print_printer_id', 0);
        $sessionId = (int) Session::get('print_session_id', 0);

        if ($locationId === 0 || $printerId === 0 || $sessionId === 0) {
            return $this->fail('Your session has ended. Please scan the QR code again.', 440);
        }

        $location = $this->locations->find($locationId);
        if ($location === null || !$location->isActive()) {
            return $this->fail('This location is not currently accepting print jobs.', 409);
        }

        // The printer must still belong to this location.
        $printer = $this->printers->findForLocation($printerId, $locationId);
        if ($printer === null) {
            return $this->fail('That printer is no longer available. Please scan the QR code again.', 409);
        }

        $printSession = $this->sessions->findRow($sessionId);
        if ($printSession === null || (int) $printSession['location_id'] !== $locationId) {
            return $this->fail('Your session has ended. Please scan the QR code again.', 440);
        }

        return [$location, $printer, $printSession];
    }

    /**
     * Clamp a submitted option set to what is allowed. Anything unrecognised
     * falls back to the printer's verified default rather than being accepted.
     *
     * @param array<string,mixed> $item
     * @return array{paper_size:string,color_mode:string,duplex:string,orientation:string,copies:int,page_range:string}
     */
    private function normaliseSelection(array $item, \App\Models\Printer $printer): array
    {
        $options = $this->capabilities->customerOptions($printer);
        $defaults = $options['defaults'];

        $validSizes = array_column($options['paper_sizes'], 'value');
        $validColors = array_column($options['color_modes'], 'value');
        $validDuplex = array_column($options['duplex_modes'], 'value');
        $validOrientations = array_column($options['orientations'], 'value');

        $paperSize = (string) ($item['paper_size'] ?? '');
        $colorMode = (string) ($item['color_mode'] ?? '');
        $duplex = (string) ($item['duplex'] ?? '');
        $orientation = (string) ($item['orientation'] ?? '');

        $copies = (int) ($item['copies'] ?? 1);
        $maxCopies = (int) Config::get('printing.max_copies', 100);
        $copies = max(1, min($maxCopies, $copies));

        $pageRange = trim((string) ($item['page_range'] ?? PageRange::ALL));
        if ($pageRange === '' || strcasecmp($pageRange, PageRange::ALL) === 0) {
            $pageRange = PageRange::ALL;
        }

        return [
            'paper_size' => in_array($paperSize, $validSizes, true) ? $paperSize : (string) $defaults['paper_size'],
            'color_mode' => in_array($colorMode, $validColors, true) ? $colorMode : (string) $defaults['color_mode'],
            'duplex' => in_array($duplex, $validDuplex, true) ? $duplex : (string) $defaults['duplex'],
            'orientation' => in_array($orientation, $validOrientations, true) ? $orientation : 'portrait',
            'copies' => $copies,
            'page_range' => $pageRange,
        ];
    }

    /** A batch belongs to this visitor when its jobs share their print session. */
    private function ownsBatch(string $batchId): bool
    {
        if ($batchId === '' || preg_match('/^[a-f0-9]{32}$/', $batchId) !== 1) {
            return false;
        }

        $sessionId = (int) Session::get('print_session_id', 0);
        if ($sessionId === 0) {
            return false;
        }

        $jobs = $this->jobs->forBatch($batchId);
        if ($jobs === []) {
            return false;
        }

        foreach ($jobs as $job) {
            if ($job->int('session_id') !== $sessionId) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function fileSummary(array $file): array
    {
        return [
            'id' => (int) $file['id'],
            'name' => (string) $file['original_name'],
            'extension' => (string) $file['extension'],
            'size_bytes' => (int) $file['size_bytes'],
            'pages' => (int) $file['page_count'],
            'page_source' => (string) $file['page_count_source'],
        ];
    }
}

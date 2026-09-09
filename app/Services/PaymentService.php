<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\PaymentRepository;
use App\Repositories\PrintJobRepository;

/**
 * Payment orchestration.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE
 * -------------------------------------
 * Nothing the browser says about a payment is believed. The client-side
 * handler fires on the customer's device; it can be replayed, edited, or
 * fabricated outright by anyone with developer tools open. So a job is
 * released for printing only when BOTH of these hold:
 *
 *   1. The HMAC-SHA256 signature over "order_id|payment_id", computed here
 *      with the secret key, matches what the gateway returned.
 *   2. The gateway's own API, queried server-to-server, reports the payment as
 *      captured for the exact order and the exact amount we created.
 *
 * Check 2 is what makes forgery pointless even if the secret key ever leaked:
 * the money must actually exist in the gateway's ledger.
 *
 * Verification is idempotent — the signature path and the webhook path can
 * both fire for the same payment, and only the first one to win the
 * conditional UPDATE releases the jobs.
 */
final class PaymentService
{
    public function __construct(
        private Database $db,
        private PaymentRepository $payments,
        private PrintJobRepository $jobs,
        private PrintQueueService $queue,
        private AuditService $audit
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) Config::get('settings.payment_enabled', '0') === '1';
    }

    public function gateway(): string
    {
        return (string) Config::get('settings.payment_gateway', Config::get('payment.default', 'razorpay'));
    }

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && $this->keySecret() !== '';
    }

    public function keyId(): string
    {
        return (string) Config::get('settings.razorpay_key_id', '');
    }

    private function keySecret(): string
    {
        return (string) Config::get('settings.razorpay_key_secret', '');
    }

    private function webhookSecret(): string
    {
        return (string) Config::get('settings.razorpay_webhook_secret', '');
    }

    /**
     * Create a gateway order covering every job in a basket.
     *
     * The amount comes from the JOBS in the database, never from the request —
     * the customer's browser has no say in what they are charged.
     *
     * @return array{success:bool,payment_id?:int,order_id?:string,amount_paise?:int,key_id?:string,error?:string}
     */
    public function createOrder(string $batchId, int $locationId, ?int $sessionId = null): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Payments are not enabled.'];
        }
        if (!$this->isConfigured()) {
            Logger::error('Payment requested but the gateway is not configured.');
            return ['success' => false, 'error' => 'Payment is temporarily unavailable. Please ask staff for assistance.'];
        }

        $existing = $this->payments->findByBatch($batchId);
        if ($existing !== null) {
            if ((int) $existing['verified_server_side'] === 1) {
                return ['success' => false, 'error' => 'This order has already been paid.'];
            }
            // Reuse the live order rather than creating a duplicate one: a
            // customer who reloads the payment page must not end up with two
            // orders for the same basket.
            if (in_array((string) $existing['status'], ['created', 'pending'], true)
                && (string) $existing['gateway_order_id'] !== '') {
                return [
                    'success' => true,
                    'payment_id' => (int) $existing['id'],
                    'order_id' => (string) $existing['gateway_order_id'],
                    'amount_paise' => (int) $existing['amount_paise'],
                    'key_id' => $this->keyId(),
                ];
            }
        }

        // Authoritative amount: sum the jobs that are actually awaiting payment.
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(total_paise), 0) AS total, COUNT(*) AS jobs
             FROM print_jobs
             WHERE batch_id = ? AND status = 'payment_pending' AND payment_status = 'pending'",
            [$batchId]
        );

        $amount = (int) ($row['total'] ?? 0);
        $jobCount = (int) ($row['jobs'] ?? 0);

        if ($jobCount === 0) {
            return ['success' => false, 'error' => 'There is nothing to pay for in this order.'];
        }
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'This order has no payable amount.'];
        }
        // Razorpay's minimum charge is ₹1.00.
        if ($amount < 100) {
            return ['success' => false, 'error' => 'The minimum payable amount is ₹1.00.'];
        }

        $receipt = 'kpms_' . substr($batchId, 0, 24);

        $response = $this->apiRequest('POST', '/orders', [
            'amount' => $amount,
            'currency' => (string) Config::get('payment.gateways.razorpay.currency', 'INR'),
            'receipt' => $receipt,
            'notes' => ['batch_id' => $batchId, 'location_id' => (string) $locationId],
        ]);

        if (!$response['success']) {
            Logger::error('Gateway order creation failed', ['error' => $response['error']]);
            return [
                'success' => false,
                'error' => 'Payment could not be started. Please try again in a moment.',
            ];
        }

        $order = $response['data'];
        $orderId = (string) ($order['id'] ?? '');

        if ($orderId === '') {
            return ['success' => false, 'error' => 'The payment gateway returned an invalid order.'];
        }

        $paymentId = $this->db->transaction(function () use (
            $existing,
            $batchId,
            $locationId,
            $sessionId,
            $orderId,
            $amount,
            $order
        ): int {
            $data = [
                'gateway' => 'razorpay',
                'gateway_order_id' => $orderId,
                'amount_paise' => $amount,
                'currency' => (string) ($order['currency'] ?? 'INR'),
                'status' => 'created',
                'gateway_response' => json_encode($this->redactGatewayPayload($order), JSON_UNESCAPED_SLASHES),
            ];

            if ($existing !== null) {
                $this->payments->updateById((int) $existing['id'], $data);
                return (int) $existing['id'];
            }

            return $this->payments->create($data + [
                'batch_id' => $batchId,
                'location_id' => $locationId,
                'session_id' => $sessionId,
            ]);
        });

        $this->db->execute(
            "UPDATE print_jobs SET payment_id = ? WHERE batch_id = ? AND status = 'payment_pending'",
            [$paymentId, $batchId]
        );

        $this->audit->log(
            'payment.order_created',
            sprintf('Created order %s for %d job(s), %d paise.', $orderId, $jobCount, $amount),
            'payment',
            (string) $paymentId
        );

        return [
            'success' => true,
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'amount_paise' => $amount,
            'key_id' => $this->keyId(),
        ];
    }

    /**
     * Verify a payment reported by the browser and, only if it truly checks
     * out, release the jobs.
     *
     * @return array{success:bool,message:string,batch_id?:string,released?:int}
     */
    public function verifyAndRelease(string $orderId, string $gatewayPaymentId, string $signature): array
    {
        if (!$this->isEnabled() || !$this->isConfigured()) {
            return ['success' => false, 'message' => 'Payments are not available.'];
        }

        $payment = $this->payments->findByOrderId('razorpay', $orderId);
        if ($payment === null) {
            $this->audit->security('payment.unknown_order', 'Verification attempted for an unknown order.', [
                'order_id' => $orderId,
            ]);
            return ['success' => false, 'message' => 'This payment could not be matched to an order.'];
        }

        // Already verified — treat as success and re-report, so a customer who
        // refreshes the callback page sees their job, not an error.
        if ((int) $payment['verified_server_side'] === 1) {
            return [
                'success' => true,
                'message' => 'This payment was already confirmed.',
                'batch_id' => (string) $payment['batch_id'],
                'released' => 0,
            ];
        }

        // ---- Check 1: signature ---------------------------------------
        if ((bool) Config::get('payment.verification.require_signature', true)) {
            $expected = hash_hmac('sha256', $orderId . '|' . $gatewayPaymentId, $this->keySecret());

            if (!hash_equals($expected, $signature)) {
                $this->payments->markFailed(
                    (int) $payment['id'],
                    'Signature verification failed.',
                    ['order_id' => $orderId, 'payment_id' => $gatewayPaymentId]
                );
                $this->audit->critical(
                    'payment.signature_mismatch',
                    'A payment callback failed signature verification and was rejected. No job was printed.',
                    ['order_id' => $orderId, 'gateway_payment_id' => $gatewayPaymentId]
                );
                return [
                    'success' => false,
                    'message' => 'This payment could not be verified. If you were charged, please contact staff — '
                        . 'nothing has been printed.',
                ];
            }
        }

        // ---- Check 2: the gateway's own record -------------------------
        $capturedAmount = null;
        $method = '';
        $verificationMethod = 'signature';
        $apiPayload = [];

        if ((bool) Config::get('payment.verification.require_api_fetch', true)) {
            $fetch = $this->apiRequest('GET', '/payments/' . rawurlencode($gatewayPaymentId));

            if (!$fetch['success']) {
                // We could not confirm with the gateway. Do NOT release the job
                // on the signature alone — the webhook or a manual check will
                // resolve it, and holding a job is recoverable in a way that
                // printing an unpaid one is not.
                Logger::error('Could not confirm a payment with the gateway', [
                    'order_id' => $orderId,
                    'error' => $fetch['error'],
                ]);
                return [
                    'success' => false,
                    'message' => 'Your payment is being confirmed. This page will update shortly — '
                        . 'please do not pay again.',
                ];
            }

            $apiPayload = $fetch['data'];
            $status = (string) ($apiPayload['status'] ?? '');
            $capturedAmount = isset($apiPayload['amount']) ? (int) $apiPayload['amount'] : null;
            $paidOrderId = (string) ($apiPayload['order_id'] ?? '');
            $method = (string) ($apiPayload['method'] ?? '');

            if ($status !== 'captured') {
                $this->payments->markFailed(
                    (int) $payment['id'],
                    'Gateway reports status: ' . $status,
                    $this->redactGatewayPayload($apiPayload)
                );
                $this->audit->security('payment.not_captured', 'A payment was not captured; no job was released.', [
                    'order_id' => $orderId,
                    'status' => $status,
                ]);
                return [
                    'success' => false,
                    'message' => $status === 'authorized'
                        ? 'Your payment is authorised but not yet captured. Please wait a moment and refresh.'
                        : 'This payment was not completed. Nothing has been printed.',
                ];
            }

            // The payment must belong to OUR order. Without this, a genuine
            // payment for a ₹2 job elsewhere could be replayed against a ₹500 one.
            if ($paidOrderId !== $orderId) {
                $this->audit->critical(
                    'payment.order_mismatch',
                    'A payment referenced a different order than the one being verified. Rejected.',
                    ['expected_order' => $orderId, 'payment_order' => $paidOrderId]
                );
                return ['success' => false, 'message' => 'This payment does not match the order.'];
            }

            // And it must be for the full amount.
            if ($capturedAmount !== (int) $payment['amount_paise']) {
                $this->payments->markFailed(
                    (int) $payment['id'],
                    sprintf('Amount mismatch: expected %d paise, captured %d.', (int) $payment['amount_paise'], (int) $capturedAmount),
                    $this->redactGatewayPayload($apiPayload)
                );
                $this->audit->critical('payment.amount_mismatch', 'A captured amount did not match the order. Rejected.', [
                    'order_id' => $orderId,
                    'expected_paise' => (int) $payment['amount_paise'],
                    'captured_paise' => $capturedAmount,
                ]);
                return ['success' => false, 'message' => 'The paid amount does not match this order.'];
            }

            $verificationMethod = 'api_fetch';
        }

        return $this->finaliseVerified(
            $payment,
            $gatewayPaymentId,
            $signature,
            $method,
            $verificationMethod,
            $apiPayload
        );
    }

    /**
     * Handle a gateway webhook. Independent of the browser entirely, so it
     * rescues payments where the customer closed the tab before the redirect.
     *
     * @return array{success:bool,message:string,handled?:string}
     */
    public function handleWebhook(string $rawBody, string $signatureHeader): array
    {
        if (!(bool) Config::get('payment.verification.accept_webhook', true)) {
            return ['success' => false, 'message' => 'Webhooks are disabled.'];
        }

        $secret = $this->webhookSecret();
        if ($secret === '') {
            Logger::warning('A webhook arrived but no webhook secret is configured.');
            return ['success' => false, 'message' => 'Webhooks are not configured.'];
        }

        // Verify BEFORE parsing: an unsigned body is not trusted input.
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signatureHeader)) {
            $this->audit->security('payment.webhook_bad_signature', 'A webhook failed signature verification.');
            return ['success' => false, 'message' => 'Invalid webhook signature.'];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Malformed webhook payload.'];
        }

        $event = (string) ($payload['event'] ?? '');
        $entity = $payload['payload']['payment']['entity'] ?? null;

        if (!is_array($entity)) {
            return ['success' => true, 'message' => 'Event ignored.', 'handled' => $event];
        }

        $gatewayPaymentId = (string) ($entity['id'] ?? '');
        $orderId = (string) ($entity['order_id'] ?? '');
        $amount = (int) ($entity['amount'] ?? 0);
        $method = (string) ($entity['method'] ?? '');

        if ($orderId === '') {
            return ['success' => true, 'message' => 'Event carried no order reference.', 'handled' => $event];
        }

        $payment = $this->payments->findByOrderId('razorpay', $orderId);
        if ($payment === null) {
            Logger::warning('Webhook for an unknown order', ['order_id' => $orderId, 'event' => $event]);
            return ['success' => true, 'message' => 'Unknown order.', 'handled' => $event];
        }

        if ($event === 'payment.failed') {
            $this->payments->markFailed(
                (int) $payment['id'],
                (string) ($entity['error_description'] ?? 'Payment failed.'),
                $this->redactGatewayPayload($entity)
            );
            $this->queue->expireUnpaidBatch(
                (string) $payment['batch_id'],
                'Payment failed at the gateway.'
            );
            return ['success' => true, 'message' => 'Recorded the failure.', 'handled' => $event];
        }

        if ($event !== 'payment.captured' && $event !== 'order.paid') {
            return ['success' => true, 'message' => 'Event ignored.', 'handled' => $event];
        }

        if ((int) $payment['verified_server_side'] === 1) {
            return ['success' => true, 'message' => 'Already verified.', 'handled' => $event];
        }

        // A webhook is signed by the gateway, but the amount must still match
        // the order we created.
        if ($amount !== (int) $payment['amount_paise']) {
            $this->audit->critical('payment.webhook_amount_mismatch', 'A webhook reported a mismatched amount.', [
                'order_id' => $orderId,
                'expected_paise' => (int) $payment['amount_paise'],
                'webhook_paise' => $amount,
            ]);
            return ['success' => false, 'message' => 'Amount mismatch.'];
        }

        $result = $this->finaliseVerified(
            $payment,
            $gatewayPaymentId,
            '',
            $method,
            'webhook',
            $entity
        );

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'handled' => $event,
        ];
    }

    /**
     * Flip the payment to verified and release its jobs — exactly once.
     *
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $payload
     * @return array{success:bool,message:string,batch_id?:string,released?:int}
     */
    private function finaliseVerified(
        array $payment,
        string $gatewayPaymentId,
        string $signature,
        string $method,
        string $verificationMethod,
        array $payload
    ): array {
        $paymentId = (int) $payment['id'];
        $batchId = (string) $payment['batch_id'];

        // The conditional UPDATE is the concurrency guard: whichever of the
        // redirect and the webhook gets here first flips the row, and the
        // loser gets false and does not release the jobs a second time.
        $wasFirst = $this->payments->markVerified(
            $paymentId,
            $gatewayPaymentId,
            $signature,
            $method,
            $verificationMethod,
            $this->redactGatewayPayload($payload)
        );

        if (!$wasFirst) {
            return [
                'success' => true,
                'message' => 'This payment was already confirmed.',
                'batch_id' => $batchId,
                'released' => 0,
            ];
        }

        $released = $this->queue->releasePaidBatch($batchId, $paymentId);

        $this->audit->log(
            'payment.verified',
            sprintf(
                'Payment %s verified via %s; released %d job(s) for printing.',
                $gatewayPaymentId,
                $verificationMethod,
                $released['released']
            ),
            'payment',
            (string) $paymentId,
            'notice',
            ['batch_id' => $batchId, 'method' => $method]
        );

        Logger::info('Payment verified and jobs released', [
            'payment_id' => $paymentId,
            'verification' => $verificationMethod,
            'released' => $released['released'],
        ]);

        return [
            'success' => true,
            'message' => 'Payment confirmed.',
            'batch_id' => $batchId,
            'released' => $released['released'],
        ];
    }

    /**
     * Issue a refund. Used when an operator cancels a paid job.
     *
     * @return array{success:bool,message:string,refund_id?:string}
     */
    public function refund(int $paymentId, ?int $amountPaise, string $reason, ?int $adminId = null): array
    {
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            return ['success' => false, 'message' => 'That payment does not exist.'];
        }
        if ((int) $payment['verified_server_side'] !== 1) {
            return ['success' => false, 'message' => 'Only a verified payment can be refunded.'];
        }
        if ((string) $payment['status'] === 'refunded') {
            return ['success' => false, 'message' => 'This payment has already been refunded.'];
        }

        $gatewayPaymentId = (string) $payment['gateway_payment_id'];
        if ($gatewayPaymentId === '') {
            return ['success' => false, 'message' => 'This payment has no gateway reference to refund against.'];
        }

        $refundable = (int) $payment['amount_paise'] - (int) $payment['refunded_paise'];
        $amountPaise ??= $refundable;

        if ($amountPaise <= 0 || $amountPaise > $refundable) {
            return [
                'success' => false,
                'message' => sprintf('The refundable amount is %d paise.', $refundable),
            ];
        }

        $response = $this->apiRequest('POST', '/payments/' . rawurlencode($gatewayPaymentId) . '/refund', [
            'amount' => $amountPaise,
            'notes' => ['reason' => substr($reason, 0, 200)],
        ]);

        if (!$response['success']) {
            return ['success' => false, 'message' => 'The refund could not be processed: ' . $response['error']];
        }

        $refundId = (string) ($response['data']['id'] ?? '');
        $this->payments->markRefunded($paymentId, $refundId, $amountPaise);

        $this->db->execute(
            "UPDATE print_jobs SET payment_status = 'refunded' WHERE payment_id = ?",
            [$paymentId]
        );

        $this->audit->log(
            'payment.refunded',
            sprintf('Refunded %d paise for payment %s: %s', $amountPaise, $gatewayPaymentId, $reason),
            'payment',
            (string) $paymentId,
            'notice'
        );

        return ['success' => true, 'message' => 'Refund issued.', 'refund_id' => $refundId];
    }

    /**
     * Cancel orders that were created but never paid, so their jobs stop
     * holding printer capacity and their files become eligible for cleanup.
     *
     * @return array{expired:int,jobs_cancelled:int}
     */
    public function expireStaleOrders(): array
    {
        $ttl = (int) Config::get('payment.order_ttl_minutes', 30);
        $stale = $this->payments->staleUnpaid($ttl);

        $expired = 0;
        $jobsCancelled = 0;

        foreach ($stale as $payment) {
            // Ask the gateway first: the customer may have paid without the
            // callback reaching us. Cancelling a paid order would be a
            // customer-visible failure.
            if ($this->orderWasPaid((string) $payment['gateway_order_id'])) {
                Logger::info('A stale order turned out to be paid; leaving it for the webhook.', [
                    'order_id' => $payment['gateway_order_id'],
                ]);
                continue;
            }

            $this->payments->updateById((int) $payment['id'], [
                'status' => 'cancelled',
                'failure_reason' => 'The order expired without payment.',
            ]);

            $jobsCancelled += $this->queue->expireUnpaidBatch(
                (string) $payment['batch_id'],
                'Payment was not completed in time.'
            );
            $expired++;
        }

        return ['expired' => $expired, 'jobs_cancelled' => $jobsCancelled];
    }

    private function orderWasPaid(string $orderId): bool
    {
        if ($orderId === '' || !$this->isConfigured()) {
            return false;
        }
        $response = $this->apiRequest('GET', '/orders/' . rawurlencode($orderId));
        if (!$response['success']) {
            // Unreachable gateway: assume nothing, keep the order.
            return true;
        }
        return (string) ($response['data']['status'] ?? '') === 'paid';
    }

    /**
     * Server-to-server gateway call.
     *
     * @param array<string,mixed>|null $body
     * @return array{success:bool,data:array<string,mixed>,error:string,http_status:int}
     */
    private function apiRequest(string $method, string $path, ?array $body = null): array
    {
        $base = (string) Config::get('payment.gateways.razorpay.api_base', 'https://api.razorpay.com/v1');
        $url = $base . $path;
        $timeout = (int) Config::get('payment.verification.api_timeout_seconds', 20);

        if (!function_exists('curl_init')) {
            return ['success' => false, 'data' => [], 'error' => 'The cURL extension is required for payments.', 'http_status' => 0];
        }

        $curl = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->keyId() . ':' . $this->keySecret(),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            // Certificate verification is never disabled: this call is the
            // only thing standing between us and printing an unpaid job.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            return [
                'success' => false,
                'data' => [],
                'error' => 'Could not reach the payment gateway: ' . $curlError,
                'http_status' => 0,
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'data' => [],
                'error' => 'The payment gateway returned an unreadable response.',
                'http_status' => $httpStatus,
            ];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = (string) ($decoded['error']['description'] ?? 'HTTP ' . $httpStatus);
            return ['success' => false, 'data' => $decoded, 'error' => $message, 'http_status' => $httpStatus];
        }

        return ['success' => true, 'data' => $decoded, 'error' => '', 'http_status' => $httpStatus];
    }

    /**
     * Strip customer contact details and card metadata before a gateway payload
     * is persisted. We keep what reconciliation needs and nothing more.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function redactGatewayPayload(array $payload): array
    {
        $keep = [
            'id', 'entity', 'amount', 'currency', 'status', 'order_id', 'method',
            'captured', 'created_at', 'receipt', 'attempts', 'amount_paid',
            'amount_due', 'refund_status', 'error_code', 'error_description',
            'international', 'bank', 'wallet', 'fee', 'tax',
        ];

        $clean = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $payload)) {
                $clean[$key] = $payload[$key];
            }
        }

        // Contact details are hashed, not stored: enough to match a customer
        // enquiry against a payment, not enough to constitute a contact list.
        foreach (['email', 'contact'] as $field) {
            if (!empty($payload[$field])) {
                $clean[$field . '_hash'] = substr(hash('sha256', (string) $payload[$field]), 0, 32);
            }
        }

        return $clean;
    }

    /** Data the checkout widget needs. @return array<string,mixed> */
    public function checkoutConfig(string $orderId, int $amountPaise, string $description): array
    {
        return [
            'key' => $this->keyId(),
            'amount' => $amountPaise,
            'currency' => (string) Config::get('payment.gateways.razorpay.currency', 'INR'),
            'name' => (string) Config::get('settings.business_name', Config::get('app.name', 'Krishna Printer')),
            'description' => $description,
            'order_id' => $orderId,
            'theme' => ['color' => (string) Config::get('settings.brand_color', '#1e6f5c')],
        ];
    }
}

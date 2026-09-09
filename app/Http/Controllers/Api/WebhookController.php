<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\RateLimiter;

/**
 * Gateway webhooks.
 *
 * This endpoint is CSRF-exempt because it carries no session cookie — it
 * authenticates by HMAC over the raw request body instead, which is stronger
 * than a CSRF token would be here.
 *
 * It exists because the browser redirect is not reliable: a customer who pays
 * and immediately closes the tab would otherwise leave a paid job stuck in
 * payment_pending. The webhook releases it regardless.
 */
final class WebhookController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private RateLimiter $limiter
    ) {
    }

    /** POST /api/webhook/razorpay */
    public function razorpay(Request $request): Response
    {
        // Loose per-IP throttle: enough to blunt a flood, generous enough not
        // to drop legitimate bursts during a busy hour.
        $limit = $this->limiter->hit('webhook:' . $request->ip(), 300, 60);
        if (!$limit['allowed']) {
            return $this->fail('Rate limit exceeded.', 429);
        }

        $rawBody = $request->rawBody();
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if ($rawBody === '') {
            return $this->fail('Empty request body.', 400);
        }
        if ($signature === '') {
            return $this->fail('Missing signature header.', 401);
        }

        $result = $this->payments->handleWebhook($rawBody, $signature);

        if (!$result['success']) {
            Logger::warning('Webhook rejected', ['reason' => $result['message']]);
            // A 4xx tells the gateway not to retry a request that will never
            // be accepted; a signature failure is exactly that.
            return $this->fail($result['message'], 400);
        }

        // 200 with a short body: the gateway only needs acknowledgement.
        return $this->json([
            'success' => true,
            'handled' => $result['handled'] ?? null,
        ]);
    }
}

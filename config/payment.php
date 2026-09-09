<?php
declare(strict_types=1);

/**
 * Payment gateway configuration.
 *
 * Keys and secrets are NOT stored here — they live encrypted in
 * system_settings and are read through Config::get('settings.*'). This file
 * holds only non-secret structure.
 */
return [
    'default' => 'razorpay',

    'gateways' => [
        'razorpay' => [
            'label' => 'Razorpay',
            'api_base' => 'https://api.razorpay.com/v1',
            'checkout_script' => 'https://checkout.razorpay.com/v1/checkout.js',
            'currency' => 'INR',
            // Razorpay quotes amounts in paise, which is also our storage unit,
            // so no conversion happens at the boundary.
            'amount_unit' => 'paise',
            'signature_algorithm' => 'sha256',
            'settings' => [
                'key_id' => 'razorpay_key_id',
                'key_secret' => 'razorpay_key_secret',
                'webhook_secret' => 'razorpay_webhook_secret',
            ],
        ],
    ],

    // Extra CSP sources needed by the checkout widget. Only injected when
    // payments are enabled, so a payment-free install keeps the tighter policy.
    'csp_sources' => [
        "script-src 'self' https://checkout.razorpay.com",
        "frame-src https://api.razorpay.com https://checkout.razorpay.com",
        "connect-src 'self' https://api.razorpay.com https://lumberjack.razorpay.com",
        "img-src 'self' data: blob: https://cdn.razorpay.com",
    ],

    // How long a created-but-unpaid order stays claimable before the jobs it
    // covers are expired and their files released.
    'order_ttl_minutes' => 30,

    'verification' => [
        // The client-side handler response is never sufficient on its own.
        // At minimum the HMAC signature is recomputed server-side; when
        // 'api_fetch' is on we additionally re-read the payment from the
        // gateway API and require status=captured.
        'require_signature' => true,
        'require_api_fetch' => true,
        'accept_webhook' => true,
        'api_timeout_seconds' => 20,
    ],
];

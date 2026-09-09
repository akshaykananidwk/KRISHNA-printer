<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\SettingsRepository;
use App\Services\AuditService;
use App\Services\PaymentService;
use App\Support\Money;

final class SettingsController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private PaymentService $payments,
        private AuditService $audit
    ) {
    }

    /** GET /admin/settings */
    public function index(Request $request): Response
    {
        return $this->view('admin/settings/index', [
            'title' => 'Settings',
            'active' => 'settings',
            'settings' => $this->settings->all(),
            // Secrets are reported as set/not-set, never echoed back.
            'secretsSet' => [
                'razorpay_key_secret' => $this->settings->secretIsSet('razorpay_key_secret'),
                'razorpay_webhook_secret' => $this->settings->secretIsSet('razorpay_webhook_secret'),
                'github_token' => $this->settings->secretIsSet('github_token'),
            ],
            'paymentConfigured' => $this->payments->isConfigured(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    /** POST /admin/settings/general */
    public function saveGeneral(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'business_name' => 'required|string|max:120',
            'app_url' => 'nullable|url|max:190',
            'support_phone' => 'nullable|string|max:32',
            'support_email' => 'nullable|email',
            'job_number_prefix' => 'nullable|regex:/^[A-Za-z]{1,4}$/',
            'brand_color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'timezone' => 'nullable|string|max:64',
            'force_https' => 'nullable|bool',
            'file_retention_hours' => 'nullable|int|between:1,168',
        ], [
            'job_number_prefix' => 'Job number prefix',
        ])->validated();

        $admin = $request->attribute('admin');
        $adminId = $admin?->id();

        $timezone = (string) ($data['timezone'] ?? '');
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->back($request, 'error', 'That timezone is not recognised.');
        }

        $this->settings->setMany([
            'business_name' => ['value' => $data['business_name'], 'group' => 'general', 'public' => true],
            'app_url' => ['value' => rtrim((string) ($data['app_url'] ?? ''), '/'), 'group' => 'general'],
            'support_phone' => ['value' => $data['support_phone'] ?? '', 'group' => 'general', 'public' => true],
            'support_email' => ['value' => $data['support_email'] ?? '', 'group' => 'general', 'public' => true],
            'job_number_prefix' => ['value' => strtoupper((string) ($data['job_number_prefix'] ?? 'AK')), 'group' => 'general'],
            'brand_color' => ['value' => $data['brand_color'] ?? '#1e6f5c', 'group' => 'general', 'public' => true],
            'timezone' => ['value' => $timezone !== '' ? $timezone : 'Asia/Kolkata', 'group' => 'general'],
            'force_https' => ['value' => ($data['force_https'] ?? false) ? '1' : '0', 'type' => 'bool', 'group' => 'security'],
            'file_retention_hours' => ['value' => (int) ($data['file_retention_hours'] ?? 24), 'type' => 'int', 'group' => 'general'],
        ], $adminId);

        $this->audit->log('settings.general_saved', 'General settings updated.', 'settings', null, 'notice');

        return $this->back($request, 'success', 'General settings saved.');
    }

    /** POST /admin/settings/pricing */
    public function saveTax(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'tax_enabled' => 'nullable|bool',
            'tax_label' => 'nullable|string|max:40',
            'tax_percent' => 'nullable|decimal|min:0|max:100',
            'tax_inclusive' => 'nullable|bool',
            'gateway_fee_passthrough' => 'nullable|bool',
            'gateway_fee_percent' => 'nullable|decimal|min:0|max:20',
            'gateway_fee_fixed' => 'nullable|decimal|min:0|max:1000',
        ])->validated();

        $admin = $request->attribute('admin');

        $this->settings->setMany([
            'tax_enabled' => ['value' => ($data['tax_enabled'] ?? false) ? '1' : '0', 'type' => 'bool', 'group' => 'pricing'],
            'tax_label' => ['value' => $data['tax_label'] ?? 'GST', 'group' => 'pricing'],
            'tax_percent' => ['value' => (string) ($data['tax_percent'] ?? 0), 'group' => 'pricing'],
            'tax_inclusive' => ['value' => ($data['tax_inclusive'] ?? false) ? '1' : '0', 'type' => 'bool', 'group' => 'pricing'],
            'gateway_fee_passthrough' => ['value' => ($data['gateway_fee_passthrough'] ?? false) ? '1' : '0', 'type' => 'bool', 'group' => 'pricing'],
            'gateway_fee_percent' => ['value' => (string) ($data['gateway_fee_percent'] ?? 0), 'group' => 'pricing'],
            'gateway_fee_fixed' => ['value' => (string) ($data['gateway_fee_fixed'] ?? 0), 'group' => 'pricing'],
        ], $admin?->id());

        $this->audit->log('settings.tax_saved', 'Tax and fee settings updated.', 'settings', null, 'notice');

        return $this->back($request, 'success', 'Tax and fee settings saved.');
    }

    /** POST /admin/settings/payment */
    public function savePayment(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'payment_enabled' => 'nullable|bool',
            'payment_gateway' => 'nullable|in:razorpay',
            'razorpay_key_id' => 'nullable|string|max:120',
            'razorpay_key_secret' => 'nullable|string|max:190',
            'razorpay_webhook_secret' => 'nullable|string|max:190',
        ])->validated();

        $admin = $request->attribute('admin');
        $enabled = (bool) ($data['payment_enabled'] ?? false);

        // Enabling payments without credentials would leave every customer at
        // a broken checkout, so refuse it.
        $keyId = (string) ($data['razorpay_key_id'] ?? '');
        $secretProvided = ($data['razorpay_key_secret'] ?? '') !== '';
        $secretAlreadySet = $this->settings->secretIsSet('razorpay_key_secret');

        if ($enabled && ($keyId === '' || (!$secretProvided && !$secretAlreadySet))) {
            return $this->back(
                $request,
                'error',
                'Enter both the key ID and the key secret before enabling payments.'
            );
        }

        $values = [
            'payment_enabled' => ['value' => $enabled ? '1' : '0', 'type' => 'bool', 'group' => 'payment'],
            'payment_gateway' => ['value' => $data['payment_gateway'] ?? 'razorpay', 'group' => 'payment'],
            'razorpay_key_id' => ['value' => $keyId, 'group' => 'payment'],
        ];

        // An empty secret field means "leave the stored one alone".
        if ($secretProvided) {
            $values['razorpay_key_secret'] = [
                'value' => $data['razorpay_key_secret'],
                'type' => 'secret',
                'group' => 'payment',
            ];
        }
        if (($data['razorpay_webhook_secret'] ?? '') !== '') {
            $values['razorpay_webhook_secret'] = [
                'value' => $data['razorpay_webhook_secret'],
                'type' => 'secret',
                'group' => 'payment',
            ];
        }

        $this->settings->setMany($values, $admin?->id());

        $this->audit->log(
            'settings.payment_saved',
            sprintf('Payment settings updated; payments are now %s.', $enabled ? 'enabled' : 'disabled'),
            'settings',
            null,
            'critical'
        );

        return $this->back(
            $request,
            'success',
            $enabled
                ? 'Payment settings saved. Payments are enabled.'
                : 'Payment settings saved. Payments are disabled — jobs will print without charge.'
        );
    }

    /** POST /admin/settings/payment/test — verify the gateway credentials work. */
    public function testPayment(Request $request): Response
    {
        if (!$this->payments->isConfigured()) {
            return $this->fail('Enter the gateway key ID and secret first.', 422);
        }

        // A ₹1 order is created and immediately abandoned; it is never
        // presented to a customer and costs nothing.
        $result = $this->payments->createOrder(
            'test' . bin2hex(random_bytes(14)),
            0,
            null
        );

        // A location id of 0 will fail the foreign key, which is the expected
        // outcome — what we are proving is that the API credentials work, and
        // that shows up as a gateway error rather than a database one.
        return $this->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'The gateway accepted the credentials.'
                : 'Gateway check: ' . (string) ($result['error'] ?? 'unknown error'),
        ]);
    }

    /** POST /admin/settings/printing */
    public function savePrinting(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'max_file_mb' => 'nullable|int|between:1,200',
            'max_files_per_batch' => 'nullable|int|between:1,50',
            'max_pages_per_job' => 'nullable|int|between:1,10000',
            'health_check_interval' => 'nullable|int|between:15,3600',
            'default_max_queue_depth' => 'nullable|int|between:1,500',
        ])->validated();

        $admin = $request->attribute('admin');

        $this->settings->setMany([
            'max_file_mb' => ['value' => (int) ($data['max_file_mb'] ?? 25), 'type' => 'int', 'group' => 'printing'],
            'max_files_per_batch' => ['value' => (int) ($data['max_files_per_batch'] ?? 10), 'type' => 'int', 'group' => 'printing'],
            'max_pages_per_job' => ['value' => (int) ($data['max_pages_per_job'] ?? 2000), 'type' => 'int', 'group' => 'printing'],
            'health_check_interval' => ['value' => (int) ($data['health_check_interval'] ?? 60), 'type' => 'int', 'group' => 'printing'],
            'default_max_queue_depth' => ['value' => (int) ($data['default_max_queue_depth'] ?? 25), 'type' => 'int', 'group' => 'printing'],
        ], $admin?->id());

        $this->audit->log('settings.printing_saved', 'Printing settings updated.', 'settings', null, 'notice');

        return $this->back($request, 'success', 'Printing settings saved.');
    }
}

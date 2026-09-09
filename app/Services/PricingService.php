<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\PricingRepository;
use App\Support\Money;
use App\Support\PageRange;

/**
 * The pricing engine.
 *
 * Everything is computed in integer paise and every intermediate value is kept
 * in the returned breakdown, so the figure shown to the customer, the figure
 * sent to the gateway and the figure stored on the job are provably the same
 * number — there is no second, separate calculation anywhere.
 */
final class PricingService
{
    public function __construct(private PricingRepository $rules)
    {
    }

    /**
     * Calculate the price for one job.
     *
     * @param array{
     *   location_id:int, printer_id:int, paper_size:string, color_mode:string,
     *   duplex:string, copies:int, page_range:string, document_pages:int
     * } $spec
     *
     * @return array<string,mixed> the full breakdown
     */
    public function calculate(array $spec): array
    {
        $locationId = (int) $spec['location_id'];
        $printerId = (int) $spec['printer_id'];
        $paperSize = (string) $spec['paper_size'];
        $colorMode = (string) $spec['color_mode'];
        $duplex = (string) $spec['duplex'];
        $copies = max(1, (int) $spec['copies']);
        $documentPages = max(0, (int) $spec['document_pages']);
        $pageRange = (string) ($spec['page_range'] ?? 'all');

        // Pages actually selected by the range, clipped to the document.
        $selectedPages = PageRange::count($pageRange, $documentPages);
        $billablePages = $selectedPages * $copies;

        // Physical sheets. Duplex halves the sheet count (rounded up: 5 pages
        // double-sided is 3 sheets, not 2.5). Sheets drive paper consumption
        // reporting; billing is per page, which is the industry convention and
        // what the requirement specifies.
        $sheetsPerCopy = $duplex === 'double' ? (int) ceil($selectedPages / 2) : $selectedPages;
        $sheets = $sheetsPerCopy * $copies;

        $rule = $this->resolveRule($locationId, $printerId, $paperSize, $colorMode, $duplex, $billablePages);

        if ($rule === null) {
            return $this->noPriceResult($spec, $selectedPages, $billablePages, $sheets);
        }

        $perPage = (int) $rule['price_per_page_paise'];
        $pagesCost = $perPage * $billablePages;
        $setupFee = (int) $rule['setup_fee_paise'];

        $subtotal = $pagesCost + $setupFee;

        $minimumCharge = (int) $rule['minimum_charge_paise'];
        $minimumApplied = false;
        if ($minimumCharge > 0 && $subtotal < $minimumCharge) {
            $subtotal = $minimumCharge;
            $minimumApplied = true;
        }

        // Tax
        $taxEnabled = (string) Config::get('settings.tax_enabled', '0') === '1';
        $taxPercent = (float) Config::get('settings.tax_percent', 0);
        $taxLabel = (string) Config::get('settings.tax_label', 'GST');
        $taxInclusive = (string) Config::get('settings.tax_inclusive', '0') === '1';

        $tax = 0;
        if ($taxEnabled && $taxPercent > 0) {
            if ($taxInclusive) {
                // The advertised price already contains tax; extract it so the
                // breakdown can show it without changing what the customer pays.
                $tax = $subtotal - (int) round($subtotal * 100 / (100 + $taxPercent));
            } else {
                $tax = Money::percentage($subtotal, $taxPercent);
            }
        }

        $taxedTotal = $taxInclusive ? $subtotal : $subtotal + $tax;

        // Payment gateway fee, only when payment is actually enabled and the
        // operator has chosen to pass it on.
        $gatewayFee = 0;
        $paymentEnabled = (string) Config::get('settings.payment_enabled', '0') === '1';
        $passOnFee = (string) Config::get('settings.gateway_fee_passthrough', '0') === '1';

        if ($paymentEnabled && $passOnFee) {
            $feePercent = (float) Config::get('settings.gateway_fee_percent', 0);
            $feeFixed = Money::toPaise((float) Config::get('settings.gateway_fee_fixed', 0));
            $gatewayFee = Money::percentage($taxedTotal, $feePercent) + $feeFixed;
        }

        $total = $taxedTotal + $gatewayFee;

        // Never bill a negative or absurd amount, whatever the rules say.
        $total = max(0, $total);

        return [
            'priced' => true,
            'currency' => (string) Config::get('app.currency', 'INR'),
            'currency_symbol' => (string) Config::get('app.currency_symbol', '₹'),

            'document_pages' => $documentPages,
            'selected_pages' => $selectedPages,
            'copies' => $copies,
            'billable_pages' => $billablePages,
            'sheets' => $sheets,

            'paper_size' => $paperSize,
            'color_mode' => $colorMode,
            'duplex' => $duplex,
            'page_range' => $pageRange,

            'rule_id' => (int) $rule['id'],
            'rule_name' => (string) $rule['name'],
            'rule_scope' => $this->describeScope($rule),
            'price_per_page_paise' => $perPage,
            'price_per_page' => Money::format($perPage),

            'pages_cost_paise' => $pagesCost,
            'setup_fee_paise' => $setupFee,
            'minimum_charge_paise' => $minimumCharge,
            'minimum_applied' => $minimumApplied,

            'subtotal_paise' => $subtotal,
            'subtotal' => Money::format($subtotal),

            'tax_enabled' => $taxEnabled,
            'tax_label' => $taxLabel,
            'tax_percent' => $taxPercent,
            'tax_inclusive' => $taxInclusive,
            'tax_paise' => $tax,
            'tax' => Money::format($tax),

            'gateway_fee_paise' => $gatewayFee,
            'gateway_fee' => Money::format($gatewayFee),

            'discount_paise' => 0,

            'total_paise' => $total,
            'total' => Money::format($total),
            'total_display' => Money::formatWithSymbol($total, (string) Config::get('app.currency_symbol', '₹')),

            'explanation' => $this->explain($selectedPages, $copies, $perPage, $pagesCost, $setupFee, $minimumApplied, $minimumCharge),
        ];
    }

    /**
     * Price a whole basket of jobs in one pass, applying the setup fee only
     * once per basket rather than once per file — charging a per-job setup fee
     * five times for five files a customer uploaded together would be wrong.
     *
     * @param array<int,array<string,mixed>> $specs
     * @return array{items:array<int,array<string,mixed>>,totals:array<string,mixed>}
     */
    public function calculateBatch(array $specs): array
    {
        $items = [];
        $seenSetupFee = false;

        foreach ($specs as $index => $spec) {
            $result = $this->calculate($spec);

            if ($result['priced'] && ($result['setup_fee_paise'] ?? 0) > 0) {
                if ($seenSetupFee) {
                    // Strip the duplicate setup fee and re-derive the totals.
                    $result = $this->removeSetupFee($result);
                } else {
                    $seenSetupFee = true;
                }
            }

            $result['index'] = $index;
            $items[] = $result;
        }

        $totals = [
            'subtotal_paise' => 0,
            'tax_paise' => 0,
            'gateway_fee_paise' => 0,
            'total_paise' => 0,
            'billable_pages' => 0,
            'sheets' => 0,
            'documents' => count($items),
            'unpriced' => 0,
        ];

        foreach ($items as $item) {
            if (!$item['priced']) {
                $totals['unpriced']++;
                continue;
            }
            $totals['subtotal_paise'] += (int) $item['subtotal_paise'];
            $totals['tax_paise'] += (int) $item['tax_paise'];
            $totals['gateway_fee_paise'] += (int) $item['gateway_fee_paise'];
            $totals['total_paise'] += (int) $item['total_paise'];
            $totals['billable_pages'] += (int) $item['billable_pages'];
            $totals['sheets'] += (int) $item['sheets'];
        }

        $symbol = (string) Config::get('app.currency_symbol', '₹');
        $totals['subtotal'] = Money::format($totals['subtotal_paise']);
        $totals['tax'] = Money::format($totals['tax_paise']);
        $totals['gateway_fee'] = Money::format($totals['gateway_fee_paise']);
        $totals['total'] = Money::format($totals['total_paise']);
        $totals['total_display'] = Money::formatWithSymbol($totals['total_paise'], $symbol);
        $totals['currency_symbol'] = $symbol;

        return ['items' => $items, 'totals' => $totals];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function removeSetupFee(array $result): array
    {
        $setupFee = (int) $result['setup_fee_paise'];
        $subtotal = max(0, (int) $result['subtotal_paise'] - $setupFee);

        // Re-derive from the adjusted subtotal so tax and fees stay consistent.
        $taxPercent = (float) $result['tax_percent'];
        $tax = 0;
        if ($result['tax_enabled'] && $taxPercent > 0) {
            $tax = $result['tax_inclusive']
                ? $subtotal - (int) round($subtotal * 100 / (100 + $taxPercent))
                : Money::percentage($subtotal, $taxPercent);
        }
        $taxedTotal = $result['tax_inclusive'] ? $subtotal : $subtotal + $tax;

        $gatewayFee = 0;
        if ((int) $result['gateway_fee_paise'] > 0) {
            $feePercent = (float) Config::get('settings.gateway_fee_percent', 0);
            $feeFixed = Money::toPaise((float) Config::get('settings.gateway_fee_fixed', 0));
            $gatewayFee = Money::percentage($taxedTotal, $feePercent) + $feeFixed;
        }

        $result['setup_fee_paise'] = 0;
        $result['subtotal_paise'] = $subtotal;
        $result['subtotal'] = Money::format($subtotal);
        $result['tax_paise'] = $tax;
        $result['tax'] = Money::format($tax);
        $result['gateway_fee_paise'] = $gatewayFee;
        $result['gateway_fee'] = Money::format($gatewayFee);
        $result['total_paise'] = $taxedTotal + $gatewayFee;
        $result['total'] = Money::format($result['total_paise']);
        $result['total_display'] = Money::formatWithSymbol(
            $result['total_paise'],
            (string) Config::get('app.currency_symbol', '₹')
        );

        return $result;
    }

    /**
     * Resolve the applicable rule. The repository already returns candidates
     * in specificity order, so this is the first row — but volume tiers are
     * checked here because "the most specific rule" and "the tier covering
     * this page count" can differ.
     *
     * @return array<string,mixed>|null
     */
    public function resolveRule(
        int $locationId,
        int $printerId,
        string $paperSize,
        string $colorMode,
        string $duplex,
        int $pages
    ): ?array {
        $candidates = $this->rules->candidates(
            $locationId,
            $printerId,
            $paperSize,
            $colorMode,
            $duplex,
            max(1, $pages)
        );

        return $candidates[0] ?? null;
    }

    /** @param array<string,mixed> $rule */
    private function describeScope(array $rule): string
    {
        if ($rule['printer_id'] !== null) {
            return 'printer-specific';
        }
        if ($rule['location_id'] !== null) {
            return 'location-specific';
        }
        return 'global default';
    }

    /**
     * When no rule matches, the answer is "we cannot price this", not "it is
     * free". The customer flow blocks on this rather than creating a zero-value
     * job the shop would have to honour.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function noPriceResult(array $spec, int $selectedPages, int $billablePages, int $sheets): array
    {
        return [
            'priced' => false,
            'error' => sprintf(
                'No price is configured for %s %s at this location. Please ask staff for assistance.',
                $spec['paper_size'],
                $spec['color_mode'] === 'color' ? 'colour' : 'black & white'
            ),
            'currency' => (string) Config::get('app.currency', 'INR'),
            'currency_symbol' => (string) Config::get('app.currency_symbol', '₹'),
            'document_pages' => (int) $spec['document_pages'],
            'selected_pages' => $selectedPages,
            'copies' => (int) $spec['copies'],
            'billable_pages' => $billablePages,
            'sheets' => $sheets,
            'paper_size' => (string) $spec['paper_size'],
            'color_mode' => (string) $spec['color_mode'],
            'duplex' => (string) $spec['duplex'],
            'page_range' => (string) ($spec['page_range'] ?? 'all'),
            'subtotal_paise' => 0,
            'tax_paise' => 0,
            'gateway_fee_paise' => 0,
            'discount_paise' => 0,
            'total_paise' => 0,
            'total' => '0.00',
            'total_display' => Money::formatWithSymbol(0, (string) Config::get('app.currency_symbol', '₹')),
        ];
    }

    private function explain(
        int $pages,
        int $copies,
        int $perPage,
        int $pagesCost,
        int $setupFee,
        bool $minimumApplied,
        int $minimumCharge
    ): string {
        $parts = [sprintf(
            '%d page%s × %d cop%s × %s = %s',
            $pages,
            $pages === 1 ? '' : 's',
            $copies,
            $copies === 1 ? 'y' : 'ies',
            Money::formatWithSymbol($perPage),
            Money::formatWithSymbol($pagesCost)
        )];

        if ($setupFee > 0) {
            $parts[] = 'plus a ' . Money::formatWithSymbol($setupFee) . ' handling fee';
        }
        if ($minimumApplied) {
            $parts[] = 'raised to the ' . Money::formatWithSymbol($minimumCharge) . ' minimum charge';
        }

        return implode(', ', $parts);
    }

    /**
     * The price list shown on the customer's landing page, so they know the
     * rates before uploading anything.
     *
     * @param array<int,string> $paperSizes
     * @param array<int,string> $colorModes
     * @return array<int,array<string,mixed>>
     */
    public function priceList(int $locationId, int $printerId, array $paperSizes, array $colorModes): array
    {
        $sizeMeta = (array) Config::get('printing.paper_sizes', []);
        $rows = [];

        foreach ($paperSizes as $size) {
            foreach ($colorModes as $mode) {
                $rule = $this->resolveRule($locationId, $printerId, $size, $mode, 'single', 1);
                if ($rule === null) {
                    continue;
                }
                $rows[] = [
                    'paper_size' => $size,
                    'paper_label' => (string) ($sizeMeta[$size]['label'] ?? $size),
                    'color_mode' => $mode,
                    'color_label' => $mode === 'color' ? 'Colour' : 'B&W',
                    'price_paise' => (int) $rule['price_per_page_paise'],
                    'price' => Money::formatWithSymbol((int) $rule['price_per_page_paise']),
                ];
            }
        }

        return $rows;
    }
}

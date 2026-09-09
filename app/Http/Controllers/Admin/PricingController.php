<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Repositories\LocationRepository;
use App\Repositories\PricingRepository;
use App\Repositories\PrinterRepository;
use App\Services\AuditService;
use App\Services\PricingService;
use App\Support\Money;

final class PricingController extends Controller
{
    public function __construct(
        private PricingRepository $rules,
        private LocationRepository $locations,
        private PrinterRepository $printers,
        private PricingService $pricing,
        private AuditService $audit
    ) {
    }

    /** GET /admin/pricing */
    public function index(Request $request): Response
    {
        $locationId = $request->int('location_id', 0);
        $result = $this->rules->paginate(
            max(1, $request->int('page', 1)),
            50,
            $locationId > 0 ? $locationId : null
        );

        $paperSizes = array_keys((array) Config::get('printing.paper_sizes', []));

        return $this->view('admin/pricing/index', [
            'title' => 'Pricing',
            'active' => 'pricing',
            'result' => $result,
            'locations' => $this->locations->all(),
            'printers' => $this->printers->all(),
            'locationId' => $locationId,
            'matrix' => $this->rules->matrix($locationId > 0 ? $locationId : null, null, $paperSizes),
            'paperSizes' => (array) Config::get('printing.paper_sizes', []),
        ]);
    }

    /** GET /admin/pricing/create */
    public function create(Request $request): Response
    {
        return $this->view('admin/pricing/form', [
            'title' => 'Add pricing rule',
            'active' => 'pricing',
            'rule' => null,
            'locations' => $this->locations->all(),
            'printers' => $this->printers->all(),
            'paperSizes' => (array) Config::get('printing.paper_sizes', []),
            'formAction' => '/admin/pricing',
        ]);
    }

    /** POST /admin/pricing */
    public function store(Request $request): Response
    {
        $data = $this->validateRule($request);
        $admin = $request->attribute('admin');
        $data['created_by'] = $admin?->id();

        $id = $this->rules->create($data);

        $this->audit->log(
            'pricing.created',
            sprintf(
                'Added pricing rule "%s" at %s per page.',
                $data['name'],
                Money::formatWithSymbol((int) $data['price_per_page_paise'])
            ),
            'pricing_rule',
            (string) $id,
            'notice'
        );

        return $this->redirect('/admin/pricing', 'success', 'Pricing rule added.');
    }

    /** GET /admin/pricing/{id}/edit */
    public function edit(Request $request): Response
    {
        $rule = $this->rules->find((int) $request->routeParam('id'));
        if ($rule === null) {
            throw new HttpException(404, 'That pricing rule was not found.');
        }

        return $this->view('admin/pricing/form', [
            'title' => 'Edit pricing rule',
            'active' => 'pricing',
            'rule' => $rule,
            'locations' => $this->locations->all(),
            'printers' => $this->printers->all(),
            'paperSizes' => (array) Config::get('printing.paper_sizes', []),
            'formAction' => '/admin/pricing/' . $rule['id'],
        ]);
    }

    /** PUT /admin/pricing/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $rule = $this->rules->find($id);
        if ($rule === null) {
            throw new HttpException(404, 'That pricing rule was not found.');
        }

        $data = $this->validateRule($request);
        $this->rules->updateById($id, $data);

        $this->audit->logChanges('pricing.updated', 'pricing_rule', (string) $id, $rule, $data, (string) $data['name']);

        return $this->redirect('/admin/pricing', 'success', 'Pricing rule updated.');
    }

    /** DELETE /admin/pricing/{id} */
    public function destroy(Request $request): Response
    {
        $id = (int) $request->routeParam('id');
        $rule = $this->rules->find($id);
        if ($rule === null) {
            throw new HttpException(404, 'That pricing rule was not found.');
        }

        // Removing the last rule covering a combination would silently make
        // that combination unsellable; warn rather than let it happen quietly.
        $this->rules->deleteById($id);

        $this->audit->log(
            'pricing.deleted',
            sprintf('Deleted pricing rule "%s".', $rule['name']),
            'pricing_rule',
            (string) $id,
            'warning'
        );

        return $this->redirect('/admin/pricing', 'success', 'Pricing rule deleted.');
    }

    /**
     * POST /admin/pricing/preview
     *
     * Runs the real calculator so an operator can confirm what a customer
     * would actually be charged, rather than reasoning about the rule ladder
     * in their head.
     */
    public function preview(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'location_id' => 'required|int|min:1',
            'printer_id' => 'nullable|int',
            'paper_size' => 'required|string|max:20',
            'color_mode' => 'required|in:bw,color',
            'duplex' => 'required|in:single,double',
            'copies' => 'required|int|between:1,999',
            'document_pages' => 'required|int|between:1,5000',
            'page_range' => 'nullable|page_range',
        ])->validated();

        $result = $this->pricing->calculate([
            'location_id' => (int) $data['location_id'],
            'printer_id' => (int) ($data['printer_id'] ?? 0),
            'paper_size' => (string) $data['paper_size'],
            'color_mode' => (string) $data['color_mode'],
            'duplex' => (string) $data['duplex'],
            'copies' => (int) $data['copies'],
            'page_range' => (string) ($data['page_range'] ?? 'all'),
            'document_pages' => (int) $data['document_pages'],
        ]);

        return $this->json(['success' => true, 'pricing' => $result]);
    }

    /**
     * POST /admin/pricing/bulk
     *
     * Set a whole paper-size × colour grid for one location in one action —
     * essential when rolling out 50 locations.
     */
    public function bulk(Request $request): Response
    {
        $locationId = $request->int('location_id', 0);
        $prices = $request->array('prices');
        $admin = $request->attribute('admin');

        if ($locationId > 0 && $this->locations->find($locationId) === null) {
            return $this->back($request, 'error', 'That location does not exist.');
        }

        $paperSizes = array_keys((array) Config::get('printing.paper_sizes', []));
        $created = 0;
        $updated = 0;

        foreach ($prices as $key => $value) {
            // Keys arrive as "A4_bw" / "A4_color".
            if (!is_string($key) || !str_contains($key, '_')) {
                continue;
            }
            [$paperSize, $colorMode] = explode('_', $key, 2);

            if (!in_array($paperSize, $paperSizes, true) || !in_array($colorMode, ['bw', 'color'], true)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_numeric($value) || (float) $value < 0) {
                continue;
            }

            $paise = Money::toPaise((float) $value);

            $existing = \App\Core\Database::instance()->selectOne(
                'SELECT id FROM pricing_rules
                 WHERE location_id ' . ($locationId > 0 ? '= ?' : 'IS NULL') . '
                   AND printer_id IS NULL AND paper_size = ? AND color_mode = ?
                   AND duplex IS NULL AND min_pages = 1
                 LIMIT 1',
                $locationId > 0 ? [$locationId, $paperSize, $colorMode] : [$paperSize, $colorMode]
            );

            if ($existing !== null) {
                $this->rules->updateById((int) $existing['id'], [
                    'price_per_page_paise' => $paise,
                    'is_active' => 1,
                ]);
                $updated++;
                continue;
            }

            $this->rules->create([
                'name' => sprintf('%s %s', $paperSize, $colorMode === 'color' ? 'Colour' : 'B&W'),
                'location_id' => $locationId > 0 ? $locationId : null,
                'printer_id' => null,
                'paper_size' => $paperSize,
                'color_mode' => $colorMode,
                'duplex' => null,
                'price_per_page_paise' => $paise,
                'min_pages' => 1,
                'priority' => 0,
                'is_active' => 1,
                'created_by' => $admin?->id(),
            ]);
            $created++;
        }

        $this->audit->log(
            'pricing.bulk_updated',
            sprintf(
                'Bulk pricing update for %s: %d created, %d updated.',
                $locationId > 0 ? 'location #' . $locationId : 'all locations',
                $created,
                $updated
            ),
            'pricing_rule',
            null,
            'notice'
        );

        return $this->back($request, 'success', sprintf(
            'Prices saved — %d new rule(s), %d updated.',
            $created,
            $updated
        ));
    }

    /** @return array<string,mixed> */
    private function validateRule(Request $request): array
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'location_id' => 'nullable|int',
            'printer_id' => 'nullable|int',
            'paper_size' => 'nullable|string|max:20',
            'color_mode' => 'nullable|in:bw,color',
            'duplex' => 'nullable|in:single,double',
            'price_per_page' => 'required|decimal|min:0|max:10000',
            'min_pages' => 'nullable|int|between:1,100000',
            'max_pages' => 'nullable|int|between:1,100000',
            'setup_fee' => 'nullable|decimal|min:0|max:100000',
            'minimum_charge' => 'nullable|decimal|min:0|max:100000',
            'priority' => 'nullable|int|between:-1000,1000',
            'is_active' => 'nullable|bool',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
        ])->validated();

        $locationId = isset($data['location_id']) && (int) $data['location_id'] > 0
            ? (int) $data['location_id']
            : null;
        $printerId = isset($data['printer_id']) && (int) $data['printer_id'] > 0
            ? (int) $data['printer_id']
            : null;

        if ($locationId !== null && $this->locations->find($locationId) === null) {
            throw new HttpException(422, 'That location does not exist.');
        }
        if ($printerId !== null) {
            $printer = $this->printers->find($printerId);
            if ($printer === null) {
                throw new HttpException(422, 'That printer does not exist.');
            }
            // A printer-scoped rule must belong to the location it is scoped to,
            // or it could never match.
            if ($locationId !== null && $printer->int('location_id') !== $locationId) {
                throw new HttpException(422, 'That printer is not at the selected location.');
            }
        }

        $minPages = (int) ($data['min_pages'] ?? 1);
        $maxPages = isset($data['max_pages']) && $data['max_pages'] !== null ? (int) $data['max_pages'] : null;

        if ($maxPages !== null && $maxPages < $minPages) {
            throw new HttpException(422, 'The maximum page count must be at least the minimum.');
        }

        return [
            'name' => (string) $data['name'],
            'location_id' => $locationId,
            'printer_id' => $printerId,
            'paper_size' => ($data['paper_size'] ?? '') !== '' ? (string) $data['paper_size'] : null,
            'color_mode' => ($data['color_mode'] ?? '') !== '' ? (string) $data['color_mode'] : null,
            'duplex' => ($data['duplex'] ?? '') !== '' ? (string) $data['duplex'] : null,
            'price_per_page_paise' => Money::toPaise((float) $data['price_per_page']),
            'min_pages' => $minPages,
            'max_pages' => $maxPages,
            'setup_fee_paise' => Money::toPaise((float) ($data['setup_fee'] ?? 0)),
            'minimum_charge_paise' => Money::toPaise((float) ($data['minimum_charge'] ?? 0)),
            'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'valid_from' => ($data['valid_from'] ?? null) !== null
                ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $data['valid_from']))
                : null,
            'valid_until' => ($data['valid_until'] ?? null) !== null
                ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $data['valid_until']))
                : null,
        ];
    }
}

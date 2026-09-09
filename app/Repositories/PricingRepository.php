<?php
declare(strict_types=1);

namespace App\Repositories;

final class PricingRepository extends Repository
{
    protected function table(): string
    {
        return 'pricing_rules';
    }

    /**
     * Fetch candidate rules for a print configuration, ordered so the FIRST
     * row is the one that should apply.
     *
     * Specificity ordering is expressed in SQL rather than in PHP so the whole
     * ladder resolves in one indexed query — important when 50 locations each
     * carry their own overrides on top of a global price list.
     *
     * Ordering rationale:
     *   1. printer-specific beats location-specific beats global
     *   2. an exact paper/colour/duplex match beats a NULL (any) match
     *   3. higher explicit priority wins ties
     *   4. newest wins remaining ties, so a re-priced rule takes effect
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates(
        ?int $locationId,
        ?int $printerId,
        string $paperSize,
        string $colorMode,
        string $duplex,
        int $pages
    ): array {
        return $this->db->select(
            'SELECT * FROM pricing_rules
             WHERE is_active = 1
               AND (location_id IS NULL OR location_id = :location_id)
               AND (printer_id IS NULL OR printer_id = :printer_id)
               AND (paper_size IS NULL OR paper_size = :paper_size)
               AND (color_mode IS NULL OR color_mode = :color_mode)
               AND (duplex IS NULL OR duplex = :duplex)
               AND min_pages <= :pages_min
               AND (max_pages IS NULL OR max_pages >= :pages_max)
               AND (valid_from IS NULL OR valid_from <= UTC_TIMESTAMP())
               AND (valid_until IS NULL OR valid_until >= UTC_TIMESTAMP())
             ORDER BY
               (printer_id IS NOT NULL) DESC,
               (location_id IS NOT NULL) DESC,
               (paper_size IS NOT NULL) DESC,
               (color_mode IS NOT NULL) DESC,
               (duplex IS NOT NULL) DESC,
               priority DESC,
               min_pages DESC,
               id DESC',
            [
                'location_id' => $locationId,
                'printer_id' => $printerId,
                'paper_size' => $paperSize,
                'color_mode' => $colorMode,
                'duplex' => $duplex,
                'pages_min' => $pages,
                'pages_max' => $pages,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forLocation(?int $locationId): array
    {
        if ($locationId === null) {
            return $this->db->select(
                'SELECT r.*, l.name AS location_name, p.name AS printer_name
                 FROM pricing_rules r
                 LEFT JOIN locations l ON l.id = r.location_id
                 LEFT JOIN printers p ON p.id = r.printer_id
                 ORDER BY (r.location_id IS NULL) DESC, l.name, r.paper_size, r.color_mode'
            );
        }
        return $this->db->select(
            'SELECT r.*, l.name AS location_name, p.name AS printer_name
             FROM pricing_rules r
             LEFT JOIN locations l ON l.id = r.location_id
             LEFT JOIN printers p ON p.id = r.printer_id
             WHERE r.location_id = ? OR r.location_id IS NULL
             ORDER BY (r.location_id IS NOT NULL) DESC, r.paper_size, r.color_mode',
            [$locationId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->findRow($id);
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(int $page, int $perPage, ?int $locationId = null): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $where = '1 = 1';
        $bindings = [];
        if ($locationId !== null) {
            $where = '(r.location_id = ? OR r.location_id IS NULL)';
            $bindings[] = $locationId;
        }

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM pricing_rules r WHERE $where", $bindings);

        $rows = $this->db->select(
            "SELECT r.*, l.name AS location_name, p.name AS printer_name
             FROM pricing_rules r
             LEFT JOIN locations l ON l.id = r.location_id
             LEFT JOIN printers p ON p.id = r.printer_id
             WHERE $where
             ORDER BY (r.location_id IS NULL) DESC, l.name, r.paper_size, r.color_mode, r.min_pages
             LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $bindings
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => (int) max(1, ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /**
     * The price matrix shown on a location's pricing screen: one cell per
     * paper size × colour mode, resolved through the same ladder the
     * calculator uses so admin and customer never disagree.
     *
     * @param array<int,string> $paperSizes
     * @return array<string,array<string,int|null>>
     */
    public function matrix(?int $locationId, ?int $printerId, array $paperSizes): array
    {
        $matrix = [];
        foreach ($paperSizes as $size) {
            foreach (['bw', 'color'] as $mode) {
                $candidates = $this->candidates($locationId, $printerId, $size, $mode, 'single', 1);
                $matrix[$size][$mode] = $candidates === []
                    ? null
                    : (int) $candidates[0]['price_per_page_paise'];
            }
        }
        return $matrix;
    }

    public function deleteForLocation(int $locationId): int
    {
        return $this->db->delete('pricing_rules', ['location_id' => $locationId]);
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM pricing_rules WHERE is_active = 1');
    }
}

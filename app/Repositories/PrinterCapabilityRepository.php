<?php
declare(strict_types=1);

namespace App\Repositories;

final class PrinterCapabilityRepository extends Repository
{
    protected function table(): string
    {
        return 'printer_capabilities';
    }

    /** @return array<int,array<string,mixed>> */
    public function forPrinter(int $printerId): array
    {
        return $this->db->select(
            'SELECT * FROM printer_capabilities WHERE printer_id = ? ORDER BY capability, value',
            [$printerId]
        );
    }

    /**
     * Capability values that may be OFFERED TO A CUSTOMER.
     *
     * Only rows whose source is 'probed' (the printer itself reported it) or
     * 'manual' (an operator ran a physical test print and confirmed it) count.
     * A 'declared' row — copied from the manufacturer's datasheet — is never
     * enough on its own, because a datasheet claim is not evidence that this
     * particular unit, with this particular cartridge and tray loaded, can
     * actually do it.
     *
     * @return array<int,string>
     */
    public function verifiedValues(int $printerId, string $capability): array
    {
        return array_column(
            $this->db->select(
                "SELECT value FROM printer_capabilities
                 WHERE printer_id = ? AND capability = ? AND is_supported = 1
                   AND source IN ('probed','manual')
                 ORDER BY is_default DESC, value",
                [$printerId, $capability]
            ),
            'value'
        );
    }

    /** Everything on record, verified or not — for the admin capability screen. @return array<int,array<string,mixed>> */
    public function allValues(int $printerId, string $capability): array
    {
        return $this->db->select(
            'SELECT * FROM printer_capabilities
             WHERE printer_id = ? AND capability = ?
             ORDER BY is_default DESC, value',
            [$printerId, $capability]
        );
    }

    public function defaultValue(int $printerId, string $capability): ?string
    {
        $value = $this->db->scalar(
            "SELECT value FROM printer_capabilities
             WHERE printer_id = ? AND capability = ? AND is_default = 1 AND is_supported = 1
               AND source IN ('probed','manual')
             LIMIT 1",
            [$printerId, $capability]
        );
        return $value === null ? null : (string) $value;
    }

    public function supports(int $printerId, string $capability, string $value): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM printer_capabilities
             WHERE printer_id = ? AND capability = ? AND value = ? AND is_supported = 1
               AND source IN ('probed','manual')",
            [$printerId, $capability, $value]
        ) > 0;
    }

    /**
     * Insert or update one capability row.
     *
     * A stronger source may overwrite a weaker one (declared -> probed ->
     * manual) but never the reverse: a fresh datasheet import must not wipe out
     * an operator's hands-on verification.
     */
    public function record(
        int $printerId,
        string $capability,
        string $value,
        bool $isSupported,
        string $source,
        bool $isDefault = false,
        ?int $verifiedBy = null,
        ?string $evidence = null
    ): void {
        $rank = ['declared' => 1, 'probed' => 2, 'manual' => 3];
        $newRank = $rank[$source] ?? 1;

        $existing = $this->db->selectOne(
            'SELECT id, source FROM printer_capabilities WHERE printer_id = ? AND capability = ? AND value = ?',
            [$printerId, $capability, $value]
        );

        $verifiedAt = $source === 'declared' ? null : gmdate('Y-m-d H:i:s');

        if ($existing !== null) {
            $existingRank = $rank[(string) $existing['source']] ?? 1;
            if ($newRank < $existingRank) {
                return; // do not downgrade a stronger verification
            }
            $this->db->update('printer_capabilities', [
                'is_supported' => $isSupported ? 1 : 0,
                'is_default' => $isDefault ? 1 : 0,
                'source' => $source,
                'verified_at' => $verifiedAt,
                'verified_by' => $verifiedBy,
                'evidence' => $evidence,
            ], ['id' => (int) $existing['id']]);
            return;
        }

        $this->db->insert('printer_capabilities', [
            'printer_id' => $printerId,
            'capability' => $capability,
            'value' => $value,
            'is_supported' => $isSupported ? 1 : 0,
            'is_default' => $isDefault ? 1 : 0,
            'source' => $source,
            'verified_at' => $verifiedAt,
            'verified_by' => $verifiedBy,
            'evidence' => $evidence,
        ]);
    }

    /** Exactly one default per capability. */
    public function setDefault(int $printerId, string $capability, string $value): void
    {
        $this->db->transaction(function () use ($printerId, $capability, $value): void {
            $this->db->execute(
                'UPDATE printer_capabilities SET is_default = 0 WHERE printer_id = ? AND capability = ?',
                [$printerId, $capability]
            );
            $this->db->execute(
                'UPDATE printer_capabilities SET is_default = 1 WHERE printer_id = ? AND capability = ? AND value = ?',
                [$printerId, $capability, $value]
            );
        });
    }

    /** Wipe probed rows before re-probing, so a removed capability disappears. */
    public function clearProbed(int $printerId): int
    {
        return $this->db->execute(
            "DELETE FROM printer_capabilities WHERE printer_id = ? AND source = 'probed'",
            [$printerId]
        );
    }

    public function countVerified(int $printerId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM printer_capabilities
             WHERE printer_id = ? AND source IN ('probed','manual') AND is_supported = 1",
            [$printerId]
        );
    }
}

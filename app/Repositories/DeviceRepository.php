<?php
declare(strict_types=1);

namespace App\Repositories;

final class DeviceRepository extends Repository
{
    protected function table(): string
    {
        return 'devices';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->findRow($id);
    }

    public function findByUid(string $uid): ?array
    {
        return $this->db->selectOne('SELECT * FROM devices WHERE device_uid = ?', [$uid]);
    }

    /** @return array<int,array<string,mixed>> */
    public function forLocation(int $locationId): array
    {
        return $this->db->select(
            'SELECT * FROM devices WHERE location_id = ? ORDER BY name',
            [$locationId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT d.*, l.name AS location_name,
                    (SELECT COUNT(*) FROM printers p WHERE p.device_id = d.id AND p.deleted_at IS NULL) AS printer_count
             FROM devices d
             INNER JOIN locations l ON l.id = d.location_id
             ORDER BY l.name, d.name'
        );
    }

    /** Called on every agent poll: keeps the "last seen" heartbeat current. */
    public function heartbeat(int $deviceId, string $ip, ?string $agentVersion, ?string $platform): void
    {
        $this->db->execute(
            "UPDATE devices
             SET status = 'online',
                 last_seen_at = UTC_TIMESTAMP(),
                 last_ip = ?,
                 agent_version = COALESCE(?, agent_version),
                 platform = COALESCE(?, platform)
             WHERE id = ?",
            [$ip, $agentVersion, $platform, $deviceId]
        );
    }

    /**
     * Agents that stopped polling are marked offline, and so are the printers
     * behind them — an unreachable agent means an unreachable printer, and the
     * customer must not be offered it.
     */
    public function markStaleOffline(int $staleSeconds): int
    {
        $affected = $this->db->execute(
            "UPDATE devices
             SET status = 'offline'
             WHERE status = 'online'
               AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND))",
            [$staleSeconds]
        );

        if ($affected > 0) {
            $this->db->execute(
                "UPDATE printers p
                 INNER JOIN devices d ON d.id = p.device_id
                 SET p.status = 'offline',
                     p.last_error = 'Location print agent stopped responding.',
                     p.last_error_at = UTC_TIMESTAMP()
                 WHERE d.status = 'offline' AND p.status = 'online'"
            );
        }

        return $affected;
    }

    public function countOnline(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM devices WHERE status = 'online'");
    }

    public function uidExists(string $uid): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM devices WHERE device_uid = ?', [$uid]) > 0;
    }

    /** @return array<int,int> printer ids this device is responsible for */
    public function printerIds(int $deviceId): array
    {
        return array_map(
            'intval',
            array_column(
                $this->db->select(
                    'SELECT id FROM printers WHERE device_id = ? AND deleted_at IS NULL AND is_enabled = 1',
                    [$deviceId]
                ),
                'id'
            )
        );
    }
}

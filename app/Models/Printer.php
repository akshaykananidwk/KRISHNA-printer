<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;

final class Printer extends Model
{
    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_ERROR = 'error';
    public const STATUS_MAINTENANCE = 'maintenance';

    public function status(): string
    {
        return $this->string('status', self::STATUS_UNKNOWN);
    }

    public function isEnabled(): bool
    {
        return $this->bool('is_enabled') && $this->get('deleted_at') === null;
    }

    /**
     * A printer only counts as available when it is enabled AND its last
     * successful health probe is recent. A stale "online" flag from an hour
     * ago is treated as unknown — the customer must never be sent to a printer
     * we have not heard from.
     */
    public function isAvailable(): bool
    {
        if (!$this->isEnabled() || $this->status() !== self::STATUS_ONLINE) {
            return false;
        }
        $lastSeen = $this->string('last_seen_at');
        if ($lastSeen === '') {
            return false;
        }
        $staleAfter = (int) Config::get('printing.queue.health_stale_after', 180);
        return (time() - strtotime($lastSeen . ' UTC')) <= $staleAfter;
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            self::STATUS_ONLINE => 'Online',
            self::STATUS_OFFLINE => 'Offline',
            self::STATUS_ERROR => 'Error',
            self::STATUS_MAINTENANCE => 'Maintenance',
            default => 'Unknown',
        };
    }

    public function statusTone(): string
    {
        return match ($this->status()) {
            self::STATUS_ONLINE => 'success',
            self::STATUS_OFFLINE, self::STATUS_ERROR => 'danger',
            self::STATUS_MAINTENANCE => 'warning',
            default => 'muted',
        };
    }

    /** @return array<string,mixed> the declared profile from config/printing.php */
    public function profile(): array
    {
        $key = $this->string('capability_profile', 'generic');
        $profiles = (array) Config::get('printing.profiles', []);
        return (array) ($profiles[$key] ?? $profiles['generic'] ?? []);
    }

    /**
     * True when documents must be rasterised by a driver host before the
     * printer can render them (host-based printers with no PDL interpreter,
     * e.g. the Canon PIXMA GM series).
     */
    public function requiresRasterisation(): bool
    {
        return (bool) ($this->profile()['requires_rasterisation'] ?? true);
    }

    public function capabilitiesVerified(): bool
    {
        return $this->get('capabilities_verified_at') !== null;
    }

    public function label(): string
    {
        $model = $this->string('model');
        return $model === '' ? $this->string('name') : $this->string('name') . ' (' . $model . ')';
    }
}

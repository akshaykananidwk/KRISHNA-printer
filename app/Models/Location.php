<?php
declare(strict_types=1);

namespace App\Models;

final class Location extends Model
{
    public function isActive(): bool
    {
        return $this->string('status') === 'active' && $this->get('deleted_at') === null;
    }

    public function statusLabel(): string
    {
        return match ($this->string('status')) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'maintenance' => 'Maintenance',
            default => 'Unknown',
        };
    }

    public function fullAddress(): string
    {
        $parts = array_filter([
            $this->string('address_line1'),
            $this->string('address_line2'),
            $this->string('city'),
            $this->string('state'),
            $this->string('postal_code'),
        ], static fn (string $part): bool => $part !== '');
        return implode(', ', $parts);
    }

    public function shortAddress(): string
    {
        $parts = array_filter([$this->string('city'), $this->string('state')]);
        return implode(', ', $parts);
    }
}

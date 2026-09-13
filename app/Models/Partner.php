<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A shop that signed itself up on the public site.
 *
 * A partner is not an administrator. It owns exactly one location and the
 * print agent at that location, and it can see nothing else in the estate.
 */
final class Partner extends Model
{
    public function status(): string
    {
        return $this->string('status', 'pending');
    }

    public function isApproved(): bool
    {
        return $this->status() === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status() === 'pending';
    }

    public function isLocked(): bool
    {
        $until = $this->string('locked_until');
        return $until !== '' && strtotime($until . ' UTC') > time();
    }

    public function locationId(): ?int
    {
        $id = $this->get('location_id');
        return $id === null ? null : (int) $id;
    }

    public function deviceId(): ?int
    {
        $id = $this->get('device_id');
        return $id === null ? null : (int) $id;
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'suspended' => 'Suspended',
            default => 'Waiting for approval',
        };
    }

    /**
     * What the shop owner is waiting on, in their words rather than ours.
     */
    public function statusExplanation(): string
    {
        return match ($this->status()) {
            'approved' => 'Your shop is live. Install the software and paste the token below.',
            'rejected' => 'Your registration was not approved. The note below says why.',
            'suspended' => 'Your shop has been switched off. Please contact us.',
            default => 'We have your details. Someone will check them and switch your shop on.',
        };
    }
}

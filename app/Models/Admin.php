<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;

final class Admin extends Model
{
    public function role(): string
    {
        return $this->string('role', 'viewer');
    }

    public function isActive(): bool
    {
        return $this->bool('is_active');
    }

    public function isLocked(): bool
    {
        $until = $this->string('locked_until');
        return $until !== '' && strtotime($until . ' UTC') > time();
    }

    public function roleLabel(): string
    {
        $roles = (array) Config::get('security.roles', []);
        return (string) ($roles[$this->role()] ?? ucfirst($this->role()));
    }

    public function can(string $permission): bool
    {
        $matrix = (array) Config::get('security.permissions', []);
        $granted = (array) ($matrix[$this->role()] ?? []);

        if (in_array('*', $granted, true)) {
            return true;
        }
        if (in_array($permission, $granted, true)) {
            return true;
        }
        // A wildcard grant like "jobs.*" covers "jobs.cancel".
        $group = explode('.', $permission)[0];
        return in_array($group . '.*', $granted, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === 'super_admin';
    }

    public function initials(): string
    {
        $name = trim($this->string('name'));
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $initials;
    }
}

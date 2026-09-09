<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Config;
use App\Core\Encrypter;

final class SettingsRepository extends Repository
{
    protected function table(): string
    {
        return 'system_settings';
    }

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /**
     * Read a setting, transparently decrypting stored secrets.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->db->selectOne(
            'SELECT setting_value, value_type, is_encrypted FROM system_settings WHERE setting_key = ?',
            [$key]
        );
        if ($row === null) {
            return $default;
        }
        return $this->castOut($row) ?? $default;
    }

    /** @return array<string,mixed> */
    public function allByGroup(string $group): array
    {
        $rows = $this->db->select(
            'SELECT * FROM system_settings WHERE group_name = ? ORDER BY setting_key',
            [$group]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> key => decoded value */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->db->select('SELECT setting_key, setting_value, value_type, is_encrypted FROM system_settings');
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $this->castOut($row);
        }
        return $this->cache = $out;
    }

    /**
     * Write a setting. Secrets are encrypted before they touch the database,
     * and an empty submitted secret means "leave the stored one alone" rather
     * than "erase it" — that is what makes masked secret fields safe to
     * re-submit from a form.
     */
    public function set(
        string $key,
        mixed $value,
        string $type = 'string',
        string $group = 'general',
        ?int $adminId = null,
        ?string $label = null,
        ?string $description = null,
        bool $isPublic = false
    ): void {
        $encrypt = $type === 'secret';

        if ($encrypt && ($value === null || $value === '')) {
            return;
        }

        if ($type === 'json' && is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        } elseif ($type === 'bool') {
            $value = $value ? '1' : '0';
        } elseif ($value !== null) {
            $value = (string) $value;
        }

        $stored = $encrypt && $value !== null ? Encrypter::encrypt((string) $value) : $value;

        $this->db->execute(
            'INSERT INTO system_settings
                 (setting_key, setting_value, value_type, is_encrypted, group_name, label, description, is_public, updated_by)
             VALUES (:k, :v, :t, :e, :g, :l, :d, :p, :u)
             ON DUPLICATE KEY UPDATE
                 setting_value = VALUES(setting_value),
                 value_type = VALUES(value_type),
                 is_encrypted = VALUES(is_encrypted),
                 group_name = VALUES(group_name),
                 label = COALESCE(VALUES(label), label),
                 description = COALESCE(VALUES(description), description),
                 is_public = VALUES(is_public),
                 updated_by = VALUES(updated_by)',
            [
                'k' => $key,
                'v' => $stored,
                't' => $type,
                'e' => $encrypt ? 1 : 0,
                'g' => $group,
                'l' => $label,
                'd' => $description,
                'p' => $isPublic ? 1 : 0,
                'u' => $adminId,
            ]
        );

        $this->cache = null;
        Config::set('settings.' . $key, $encrypt ? $value : $this->castValue($value, $type));
    }

    /** @param array<string,mixed> $values key => [value, type, group] */
    public function setMany(array $values, ?int $adminId = null): void
    {
        $this->db->transaction(function () use ($values, $adminId): void {
            foreach ($values as $key => $spec) {
                if (is_array($spec)) {
                    $this->set(
                        $key,
                        $spec['value'] ?? null,
                        (string) ($spec['type'] ?? 'string'),
                        (string) ($spec['group'] ?? 'general'),
                        $adminId,
                        $spec['label'] ?? null,
                        $spec['description'] ?? null,
                        (bool) ($spec['public'] ?? false)
                    );
                } else {
                    $this->set($key, $spec, 'string', 'general', $adminId);
                }
            }
        });
    }

    public function forget(string $key): void
    {
        $this->db->delete('system_settings', ['setting_key' => $key]);
        $this->cache = null;
    }

    public function has(string $key): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM system_settings WHERE setting_key = ?',
            [$key]
        ) > 0;
    }

    /** True when a secret is stored, without revealing it. */
    public function secretIsSet(string $key): bool
    {
        $value = $this->db->scalar(
            'SELECT setting_value FROM system_settings WHERE setting_key = ? AND is_encrypted = 1',
            [$key]
        );
        return is_string($value) && $value !== '';
    }

    /** @return array<string,mixed> settings marked safe for the customer front-end */
    public function publicSettings(): array
    {
        $rows = $this->db->select(
            'SELECT setting_key, setting_value, value_type, is_encrypted
             FROM system_settings WHERE is_public = 1 AND is_encrypted = 0'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $this->castOut($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private function castOut(array $row): mixed
    {
        $value = $row['setting_value'];
        if ((int) $row['is_encrypted'] === 1 && is_string($value) && $value !== '') {
            try {
                $value = Encrypter::decrypt($value);
            } catch (\Throwable) {
                return null;
            }
        }
        return $this->castValue($value, (string) $row['value_type']);
    }

    private function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'int' => (int) $value,
            'bool' => in_array((string) $value, ['1', 'true', 'on', 'yes'], true),
            'json' => json_decode((string) $value, true) ?? [],
            default => (string) $value,
        };
    }
}

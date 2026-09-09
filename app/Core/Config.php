<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuration registry. Values come from /config/*.php which in turn read
 * the generated /config/config.php (written by the installer) and the
 * environment. Runtime-editable values live in the system_settings table and
 * are layered on top via Config::hydrateFromDatabase().
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $dbLoaded = false;

    public static function load(string $configDir): void
    {
        foreach (glob(rtrim($configDir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === 'config') {
                continue; // installer-generated credentials file, read by config/database.php
            }
            $values = require $file;
            if (is_array($values)) {
                self::$items[$name] = $values;
            }
        }
    }

    /** Dot-notation getter: Config::get('app.name'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /**
     * Overlay runtime settings stored in system_settings. Called once per
     * request after the database connection is available.
     */
    public static function hydrateFromDatabase(Database $db): void
    {
        if (self::$dbLoaded) {
            return;
        }
        self::$dbLoaded = true;
        try {
            $rows = $db->select('SELECT setting_key, setting_value, is_encrypted FROM system_settings');
        } catch (\Throwable) {
            return; // table not migrated yet (e.g. during install)
        }
        foreach ($rows as $row) {
            $value = $row['setting_value'];
            if ((int) $row['is_encrypted'] === 1 && $value !== null && $value !== '') {
                try {
                    $value = Encrypter::decrypt($value);
                } catch (\Throwable) {
                    $value = null;
                }
            }
            self::set('settings.' . $row['setting_key'], $value);
        }
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items;
    }

    public static function reset(): void
    {
        self::$items = [];
        self::$dbLoaded = false;
    }
}

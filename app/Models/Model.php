<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Base entity. Models are plain data objects hydrated from a database row —
 * they carry domain logic but no persistence code; that lives in the
 * repositories.
 */
abstract class Model
{
    /** @var array<string,mixed> */
    protected array $attributes = [];

    /** @param array<string,mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,static>
     */
    public static function fromRows(array $rows): array
    {
        return array_map(static fn (array $row): static => new static($row), $rows);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function id(): int
    {
        return (int) ($this->attributes['id'] ?? 0);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->attributes[$key] ?? null;
        return $value === null ? $default : (int) $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->attributes[$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->attributes[$key] ?? null;
        return $value === null ? $default : (bool) (int) $value;
    }

    /** @return array<string,mixed>|null */
    public function json(string $key): ?array
    {
        $value = $this->attributes[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return is_array($value) ? $value : null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }
}

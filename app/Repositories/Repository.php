<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

abstract class Repository
{
    public function __construct(protected Database $db)
    {
    }

    abstract protected function table(): string;

    /** @return array<string,mixed>|null */
    public function findRow(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ' . $this->db->quoteIdentifier($this->table()) . ' WHERE id = ?',
            [$id]
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->table(), $data);
    }

    /** @param array<string,mixed> $data */
    public function updateById(int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }
        return $this->db->update($this->table(), $data, ['id' => $id]);
    }

    public function deleteById(int $id): int
    {
        return $this->db->delete($this->table(), ['id' => $id]);
    }

    public function count(string $where = '1', array $bindings = []): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->table()) . ' WHERE ' . $where,
            $bindings
        );
    }

    /**
     * Build a "column IN (?, ?, ...)" fragment with matching bindings.
     *
     * @param array<int,mixed> $values
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function inClause(array $values): array
    {
        if ($values === []) {
            return ['(NULL)', []];
        }
        return ['(' . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values)];
    }

    /** Clamp a caller-supplied page size so a hostile query cannot ask for everything. */
    protected function clampPerPage(int $perPage, int $max = 100, int $default = 25): int
    {
        if ($perPage < 1) {
            return $default;
        }
        return min($perPage, $max);
    }

    /**
     * Whitelist a sort column. Order-by cannot be parameterised, so the only
     * safe approach is to reject anything not explicitly permitted.
     *
     * @param array<int,string> $allowed
     */
    protected function safeOrderBy(string $column, array $allowed, string $fallback): string
    {
        return in_array($column, $allowed, true) ? $column : $fallback;
    }

    protected function safeDirection(string $direction): string
    {
        return strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query in the application goes through here and every
 * one of them uses prepared statements with bound parameters — there is no
 * string-interpolation query path anywhere in the codebase.
 */
final class Database
{
    private static ?Database $instance = null;

    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(Config::get('database', []));
        }
        return self::$instance;
    }

    public static function setInstance(?Database $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->config['driver'] ?? 'mysql';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        if ($driver === 'sqlite') {
            $dsn = 'sqlite:' . ($this->config['database'] ?? ':memory:');
            $this->pdo = new PDO($dsn, null, null, $this->options());
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            return $this->pdo;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 3306);
        $name = $this->config['database'] ?? '';
        $socket = $this->config['socket'] ?? '';

        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $name, $charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $this->pdo = new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            $this->options()
        );

        // Strict mode protects data integrity: silent truncation becomes an error.
        $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone='+00:00'");

        return $this->pdo;
    }

    /** @return array<int,mixed> */
    private function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements — the server never sees interpolated SQL.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    /** @param array<string|int,mixed> $bindings */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            foreach ($bindings as $key => $value) {
                $param = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
                $stmt->bindValue($param, $value, $this->pdoType($value));
            }
            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            Logger::error('Database query failed', [
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * @param array<string|int,mixed> $bindings
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<string|int,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string|int,mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
        );
        $this->statement($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $bindings['set_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdentifier($column) . ' = :where_' . $column;
            $bindings['where_' . $column] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $set),
            implode(' AND ', $conditions)
        );
        return $this->execute($sql, $bindings);
    }

    /** @param array<string,mixed> $where */
    public function delete(string $table, array $where): int
    {
        $conditions = [];
        $bindings = [];
        foreach ($where as $column => $value) {
            $conditions[] = $this->quoteIdentifier($column) . ' = :' . $column;
            $bindings[$column] = $value;
        }
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $conditions)
        );
        return $this->execute($sql, $bindings);
    }

    /**
     * Identifiers never come from user input in this codebase, but they are
     * quoted and validated anyway so a future caller cannot introduce an
     * injection point through a column name.
     */
    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }
        return ($this->config['driver'] ?? 'mysql') === 'sqlite'
            ? '"' . $identifier . '"'
            : '`' . $identifier . '`';
    }

    /** Nested-safe transaction helper using savepoints. */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT sp' . $this->transactionDepth);
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT sp' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT sp' . $this->transactionDepth);
        }
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    public function databaseName(): string
    {
        return (string) ($this->config['database'] ?? '');
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function tableExists(string $table): bool
    {
        if ($this->driver() === 'sqlite') {
            return $this->scalar(
                'SELECT COUNT(*) FROM sqlite_master WHERE type = ? AND name = ?',
                ['table', $table]
            ) > 0;
        }
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) > 0;
    }
}

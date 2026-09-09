<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Runs .sql migrations in filename order, recording each in the `migrations`
 * ledger with a checksum.
 *
 * Statement splitting is deliberately parser-based rather than a naive
 * explode(';'): the schema contains semicolons inside COMMENT strings and
 * inside routine bodies, and splitting on those would produce invalid SQL.
 */
final class Migrator
{
    /** @var array<int,string> */
    private array $log = [];

    public function __construct(
        private Database $db,
        private string $migrationsPath
    ) {
    }

    /** @return array<int,string> */
    public function log(): array
    {
        return $this->log;
    }

    public function ensureLedger(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS migrations (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration   VARCHAR(190) NOT NULL,
                checksum    CHAR(64) NOT NULL,
                batch       INT UNSIGNED NOT NULL,
                applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_ms INT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<int,string> migration names not yet applied */
    public function pending(): array
    {
        $this->ensureLedger();
        $applied = array_column(
            $this->db->select('SELECT migration FROM migrations'),
            'migration'
        );
        $pending = [];
        foreach ($this->files() as $file) {
            $name = basename($file, '.sql');
            if (!in_array($name, $applied, true)) {
                $pending[] = $name;
            }
        }
        return $pending;
    }

    /**
     * Apply all pending migrations.
     *
     * @return array{applied:array<int,string>,batch:int,skipped:array<int,string>}
     */
    public function run(): array
    {
        $this->ensureLedger();

        $applied = array_column($this->db->select('SELECT migration FROM migrations'), 'migration');
        $batch = (int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;

        $ran = [];
        $skipped = [];

        foreach ($this->files() as $file) {
            $name = basename($file, '.sql');
            if (in_array($name, $applied, true)) {
                $skipped[] = $name;
                continue;
            }

            $sql = (string) file_get_contents($file);
            $checksum = hash('sha256', $sql);
            $started = microtime(true);

            $this->log[] = "Applying migration: $name";

            // DDL is not transactional in MySQL, so a failed migration cannot be
            // rolled back statement-by-statement. Instead the updater takes a
            // full database backup first and restores it if this throws.
            foreach ($this->splitStatements($sql) as $statement) {
                $this->db->execute($statement);
            }

            $this->db->insert('migrations', [
                'migration' => $name,
                'checksum' => $checksum,
                'batch' => $batch,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            $ran[] = $name;
        }

        return ['applied' => $ran, 'batch' => $batch, 'skipped' => $skipped];
    }

    /**
     * Report migrations whose file changed after they were applied. An edited
     * migration means two installations can silently diverge, so the updater
     * surfaces this rather than ignoring it.
     *
     * @return array<int,string>
     */
    public function driftedMigrations(): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }
        $rows = $this->db->select('SELECT migration, checksum FROM migrations');
        $drift = [];
        foreach ($rows as $row) {
            $file = $this->migrationsPath . '/' . $row['migration'] . '.sql';
            if (!is_file($file)) {
                continue;
            }
            if (hash('sha256', (string) file_get_contents($file)) !== $row['checksum']) {
                $drift[] = (string) $row['migration'];
            }
        }
        return $drift;
    }

    /** @return array<int,string> */
    public function files(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Split a migration file into executable statements.
     *
     * Tracks string literals, backtick identifiers and both comment styles so
     * a ';' inside a COMMENT '...' clause does not terminate a statement.
     * DELIMITER directives are honoured for trigger/procedure bodies.
     *
     * @return array<int,string>
     */
    public function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $delimiter = ';';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $rest = substr($sql, $i);

            // DELIMITER directive (only meaningful at the start of a line).
            if (($i === 0 || $sql[$i - 1] === "\n") && preg_match('/^DELIMITER[ \t]+(\S+)[ \t]*\r?\n/i', $rest, $m) === 1) {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                    $current = '';
                }
                $delimiter = $m[1];
                $i += strlen($m[0]);
                continue;
            }

            // Line comments.
            if ($char === '-' && substr($rest, 0, 3) === '-- ') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($char === '#') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }

            // Block comments. /*! ... */ is a MySQL conditional-execution hint
            // and must be preserved, not stripped.
            if ($char === '/' && substr($rest, 0, 2) === '/*' && substr($rest, 0, 3) !== '/*!') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            // Quoted strings and quoted identifiers: copy verbatim.
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $c = $sql[$i];
                    if ($c === '\\' && $quote !== '`' && $i + 1 < $length) {
                        $current .= $c . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $current .= $c;
                    $i++;
                    if ($c === $quote) {
                        // A doubled quote is an escaped quote, not a terminator.
                        if ($i < $length && $sql[$i] === $quote) {
                            $current .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                }
                continue;
            }

            // Statement terminator.
            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i += strlen($delimiter);
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}

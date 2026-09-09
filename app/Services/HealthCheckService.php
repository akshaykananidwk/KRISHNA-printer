<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Repositories\PrinterRepository;

/**
 * System health checks.
 *
 * Used in three places, deliberately sharing one implementation so they cannot
 * drift apart: the installer's requirements screen, the admin health page, and
 * the post-update verification that decides whether an update is kept or rolled
 * back.
 */
final class HealthCheckService
{
    public function __construct(
        private Database $db,
        private PrinterRepository $printers
    ) {
    }

    /**
     * Server requirements. Used by the installer and the admin health page.
     *
     * @return array{passed:bool,checks:array<int,array<string,mixed>>}
     */
    public function serverRequirements(): array
    {
        $checks = [];

        $phpVersion = PHP_VERSION;
        $checks[] = $this->check(
            'PHP version',
            version_compare($phpVersion, '8.1.0', '>='),
            $phpVersion,
            'PHP 8.1 or newer',
            true
        );

        foreach (['pdo', 'pdo_mysql', 'curl', 'openssl', 'json', 'mbstring', 'fileinfo', 'zip'] as $extension) {
            $checks[] = $this->check(
                'Extension: ' . $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'loaded' : 'missing',
                'required',
                true
            );
        }

        foreach (['gd', 'sodium', 'phar', 'zlib'] as $extension) {
            $checks[] = $this->check(
                'Extension: ' . $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'loaded' : 'missing',
                $extension === 'gd' ? 'recommended — PNG QR codes' : 'recommended',
                false
            );
        }

        // Prove the configured password parameters work on this build rather
        // than discovering it when an administrator cannot sign in. PHP's
        // Argon2 comes from libargon2 or libsodium depending on how the binary
        // was compiled, and they do not accept the same options — sodium
        // rejects any thread count above 1.
        $algorithm = Config::get('security.password.algorithm')
            ?? (defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        $hashOptions = defined('PASSWORD_ARGON2ID') && $algorithm === PASSWORD_ARGON2ID
            ? (array) Config::get('security.password.argon_options', [])
            : (array) Config::get('security.password.bcrypt_options', ['cost' => 12]);

        $hashError = '';
        try {
            password_hash('requirement-probe', $algorithm, $hashOptions);
        } catch (\Throwable $e) {
            $hashError = $e->getMessage();
        }

        $checks[] = $this->check(
            'Password hashing',
            $hashError === '',
            $hashError === ''
                ? (defined('PASSWORD_ARGON2ID') && $algorithm === PASSWORD_ARGON2ID ? 'Argon2id' : 'bcrypt')
                    . ' with the configured options'
                : $hashError,
            'the configured algorithm and options must work on this server',
            false
        );

        $uploadMax = $this->bytesFromIni((string) ini_get('upload_max_filesize'));
        $postMax = $this->bytesFromIni((string) ini_get('post_max_size'));
        $needed = (int) Config::get('uploads.max_file_bytes', 26214400);

        $checks[] = $this->check(
            'upload_max_filesize',
            $uploadMax >= $needed,
            ini_get('upload_max_filesize') ?: 'unknown',
            'at least ' . round($needed / 1048576) . 'M',
            false
        );
        $checks[] = $this->check(
            'post_max_size',
            $postMax >= $needed,
            ini_get('post_max_size') ?: 'unknown',
            'at least ' . round($needed / 1048576) . 'M',
            false
        );

        $memoryLimit = $this->bytesFromIni((string) ini_get('memory_limit'));
        $checks[] = $this->check(
            'memory_limit',
            $memoryLimit === -1 || $memoryLimit >= 128 * 1024 * 1024,
            ini_get('memory_limit') ?: 'unknown',
            'at least 128M',
            false
        );

        $maxExecution = (int) ini_get('max_execution_time');
        $checks[] = $this->check(
            'max_execution_time',
            $maxExecution === 0 || $maxExecution >= 60,
            $maxExecution === 0 ? 'unlimited' : $maxExecution . 's',
            'at least 60s — updates need time',
            false
        );

        foreach ($this->writablePaths() as $label => $path) {
            $writable = $this->pathIsWritable($path);
            $checks[] = $this->check(
                'Writable: ' . $label,
                $writable,
                $writable ? 'writable' : (is_dir($path) ? 'not writable' : 'missing'),
                $path,
                true
            );
        }

        $required = array_filter($checks, static fn (array $c): bool => $c['required']);
        $passed = array_reduce(
            $required,
            static fn (bool $carry, array $c): bool => $carry && $c['passed'],
            true
        );

        return ['passed' => $passed, 'checks' => $checks];
    }

    /** @return array<string,string> label => path */
    public function writablePaths(): array
    {
        return [
            'uploads' => (string) Config::get('app.upload_path', ''),
            'storage' => (string) Config::get('app.storage_path', ''),
            'storage/cache' => (string) Config::get('app.cache_path', ''),
            'storage/sessions' => (string) Config::get('app.session_path', ''),
            'logs' => (string) Config::get('app.log_path', ''),
            'backups' => (string) Config::get('app.backup_path', ''),
            'config' => (string) Config::get('app.base_path', '') . '/config',
        ];
    }

    private function pathIsWritable(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (!is_dir($path)) {
            // Try to create it — the installer is expected to.
            if (!@mkdir($path, 0750, true) && !is_dir($path)) {
                return false;
            }
        }
        return is_writable($path);
    }

    /**
     * The checks the updater runs after swapping files. A failure here triggers
     * a rollback, so each one must be genuinely decisive rather than advisory.
     *
     * @return array{healthy:bool,passed:int,failed:int,failures:array<int,string>,checks:array<int,array<string,mixed>>}
     */
    public function runUpdateChecks(): array
    {
        $configured = (array) Config::get('updates.health_checks', []);
        $checks = [];
        $failures = [];

        foreach ($configured as $name) {
            $result = match ($name) {
                'config_readable' => $this->checkConfigReadable(),
                'database_connects' => $this->checkDatabaseConnects(),
                'migrations_current' => $this->checkMigrationsCurrent(),
                'writable_paths' => $this->checkWritablePaths(),
                'front_controller_parses' => $this->checkFrontControllerParses(),
                default => ['name' => $name, 'passed' => true, 'message' => 'Unknown check, skipped.'],
            };

            $checks[] = $result;
            if (!$result['passed']) {
                $failures[] = $result['name'] . ': ' . $result['message'];
            }
        }

        return [
            'healthy' => $failures === [],
            'passed' => count($checks) - count($failures),
            'failed' => count($failures),
            'failures' => $failures,
            'checks' => $checks,
        ];
    }

    /** @return array{name:string,passed:bool,message:string} */
    private function checkConfigReadable(): array
    {
        $path = (string) Config::get('app.base_path', '') . '/config/config.php';

        if (!is_file($path)) {
            return ['name' => 'config_readable', 'passed' => false, 'message' => 'config/config.php is missing.'];
        }
        if (!is_readable($path)) {
            return ['name' => 'config_readable', 'passed' => false, 'message' => 'config/config.php is not readable.'];
        }

        $values = @include $path;
        if (!is_array($values) || !isset($values['db_name'])) {
            return [
                'name' => 'config_readable',
                'passed' => false,
                'message' => 'config/config.php does not return a valid configuration array.',
            ];
        }

        return ['name' => 'config_readable', 'passed' => true, 'message' => 'Configuration is intact.'];
    }

    /** @return array{name:string,passed:bool,message:string} */
    private function checkDatabaseConnects(): array
    {
        try {
            $value = $this->db->scalar('SELECT 1');
            if ((int) $value !== 1) {
                return ['name' => 'database_connects', 'passed' => false, 'message' => 'The database returned an unexpected result.'];
            }
        } catch (\Throwable $e) {
            return ['name' => 'database_connects', 'passed' => false, 'message' => $e->getMessage()];
        }

        // The core tables must exist, not merely the connection.
        foreach (['print_jobs', 'printers', 'locations', 'admins', 'system_settings'] as $table) {
            if (!$this->db->tableExists($table)) {
                return [
                    'name' => 'database_connects',
                    'passed' => false,
                    'message' => 'The core table "' . $table . '" is missing.',
                ];
            }
        }

        return ['name' => 'database_connects', 'passed' => true, 'message' => 'The database is reachable and complete.'];
    }

    /** @return array{name:string,passed:bool,message:string} */
    private function checkMigrationsCurrent(): array
    {
        try {
            $migrator = new Migrator($this->db, (string) Config::get('app.migrations_path', ''));
            $pending = $migrator->pending();
        } catch (\Throwable $e) {
            return ['name' => 'migrations_current', 'passed' => false, 'message' => $e->getMessage()];
        }

        if ($pending !== []) {
            return [
                'name' => 'migrations_current',
                'passed' => false,
                'message' => count($pending) . ' migration(s) are still pending: ' . implode(', ', $pending),
            ];
        }

        return ['name' => 'migrations_current', 'passed' => true, 'message' => 'All migrations are applied.'];
    }

    /** @return array{name:string,passed:bool,message:string} */
    private function checkWritablePaths(): array
    {
        $failed = [];
        foreach ($this->writablePaths() as $label => $path) {
            if (!$this->pathIsWritable($path)) {
                $failed[] = $label;
            }
        }

        return $failed === []
            ? ['name' => 'writable_paths', 'passed' => true, 'message' => 'All required paths are writable.']
            : ['name' => 'writable_paths', 'passed' => false, 'message' => 'Not writable: ' . implode(', ', $failed)];
    }

    /** @return array{name:string,passed:bool,message:string} */
    private function checkFrontControllerParses(): array
    {
        $basePath = (string) Config::get('app.base_path', '');
        $files = ['public/index.php', 'app/Core/Application.php', 'routes/web.php'];

        foreach ($files as $file) {
            $path = $basePath . '/' . $file;
            if (!is_file($path)) {
                return [
                    'name' => 'front_controller_parses',
                    'passed' => false,
                    'message' => $file . ' is missing.',
                ];
            }
        }

        if (!function_exists('exec')) {
            // Without exec we cannot lint, but we can at least confirm the
            // files are non-trivial and open with a PHP tag.
            foreach ($files as $file) {
                $head = (string) @file_get_contents($basePath . '/' . $file, false, null, 0, 16);
                if (!str_starts_with(ltrim($head), '<?php')) {
                    return [
                        'name' => 'front_controller_parses',
                        'passed' => false,
                        'message' => $file . ' does not look like a PHP file.',
                    ];
                }
            }
            return [
                'name' => 'front_controller_parses',
                'passed' => true,
                'message' => 'Entry points are present (syntax could not be linted without exec).',
            ];
        }

        foreach ($files as $file) {
            $output = [];
            $exitCode = 0;
            @exec('php -l ' . escapeshellarg($basePath . '/' . $file) . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                return [
                    'name' => 'front_controller_parses',
                    'passed' => false,
                    'message' => $file . ': ' . implode(' ', $output),
                ];
            }
        }

        return ['name' => 'front_controller_parses', 'passed' => true, 'message' => 'Entry points parse cleanly.'];
    }

    /**
     * Operational status for the admin health page.
     *
     * @return array<string,mixed>
     */
    public function systemStatus(): array
    {
        $requirements = $this->serverRequirements();

        $databaseSize = 0;
        try {
            $databaseSize = (int) $this->db->scalar(
                'SELECT COALESCE(SUM(data_length + index_length), 0)
                 FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
        } catch (\Throwable) {
            // information_schema may be restricted on shared hosting.
        }

        $diskFree = @disk_free_space((string) Config::get('app.base_path', '.'));
        $diskTotal = @disk_total_space((string) Config::get('app.base_path', '.'));

        return [
            'requirements' => $requirements,
            'php_version' => PHP_VERSION,
            'app_version' => (string) Config::get('app.version', ''),
            'current_commit' => (string) Config::get('settings.current_commit', ''),
            'database_size_bytes' => $databaseSize,
            'disk_free_bytes' => $diskFree === false ? null : (int) $diskFree,
            'disk_total_bytes' => $diskTotal === false ? null : (int) $diskTotal,
            'printers_online' => $this->printers->countOnline(),
            'printers_offline' => $this->printers->countOffline(),
            'maintenance_mode' => is_file((string) Config::get('app.maintenance_file', '')),
            'install_locked' => is_file((string) Config::get('app.install_lock_file', '')),
            'https_enforced' => (string) Config::get('settings.force_https', '0') === '1',
            'payment_enabled' => (string) Config::get('settings.payment_enabled', '0') === '1',
            'server_time_utc' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function check(string $name, bool $passed, string $actual, string $expected, bool $required): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'actual' => $actual,
            'expected' => $expected,
            'required' => $required,
        ];
    }

    public function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}

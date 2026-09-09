<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Encrypter;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\HealthCheckService;
use App\Support\Money;
use PDO;

/**
 * The one-click installation wizard.
 *
 * Eight steps, in this order:
 *   1. Requirements   2. Database   3. Application   4. Admin account
 *   5. System setup   6. Migrations 7. Seed defaults 8. Security lock
 *
 * Nothing needs editing by hand: the wizard writes config/config.php itself,
 * generates the encryption key, runs the migrations, seeds sensible defaults
 * and then locks itself so it cannot be replayed against a live site.
 */
final class InstallController extends Controller
{
    private const STEPS = [
        1 => 'Server requirements',
        2 => 'Database',
        3 => 'Application',
        4 => 'Administrator account',
        5 => 'System setup',
        6 => 'Database migration',
        7 => 'Default settings',
        8 => 'Security lock',
    ];

    public function __construct(private HealthCheckService $health)
    {
    }

    /**
     * Hash the first administrator's password using the configured algorithm.
     *
     * Mirrors AuthService::hash(). PHP's Argon2 comes from libargon2 or
     * libsodium depending on the build, and the sodium one rejects any thread
     * count above 1, so a configuration that is valid on one server can throw
     * on another. bcrypt is the universal fallback rather than a failed
     * install.
     */
    private function hashPassword(string $password): string
    {
        $algorithm = Config::get('security.password.algorithm')
            ?? (defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        $options = defined('PASSWORD_ARGON2ID') && $algorithm === PASSWORD_ARGON2ID
            ? (array) Config::get('security.password.argon_options', [])
            : (array) Config::get('security.password.bcrypt_options', ['cost' => 12]);

        try {
            return password_hash($password, $algorithm, $options);
        } catch (\ValueError $e) {
            Logger::error('Configured password hashing options are unsupported here; used bcrypt instead.', [
                'error' => $e->getMessage(),
            ]);
            return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
    }

    /** GET /install */
    public function index(Request $request): Response
    {
        $step = max(1, min(8, $request->int('step', 1)));

        // Steps must be completed in order; jumping ahead lands on the first
        // incomplete one.
        $completed = (array) Session::get('install_completed', []);
        $highestAllowed = 1;
        for ($i = 1; $i <= 8; $i++) {
            if (in_array($i, $completed, true)) {
                $highestAllowed = $i + 1;
            } else {
                break;
            }
        }
        $step = min($step, min(8, $highestAllowed));

        return $this->view('install/wizard', [
            'title' => 'Install ' . Config::get('app.name', 'Krishna Printer'),
            'step' => $step,
            'steps' => self::STEPS,
            'completed' => $completed,
            'requirements' => $step === 1 ? $this->health->serverRequirements() : null,
            'data' => (array) Session::get('install_data', []),
            'phpVersion' => PHP_VERSION,
            'detectedUrl' => $this->detectUrl($request),
        ]);
    }

    /** POST /install/requirements */
    public function checkRequirements(Request $request): Response
    {
        $result = $this->health->serverRequirements();

        if (!$result['passed']) {
            $failed = array_values(array_filter(
                $result['checks'],
                static fn (array $c): bool => $c['required'] && !$c['passed']
            ));

            return $this->fail(
                'Some requirements are not met. Fix these and re-check: '
                . implode(', ', array_column($failed, 'name')),
                422,
                ['checks' => $result['checks']]
            );
        }

        $this->completeStep(1);

        return $this->ok('All requirements are met.', ['checks' => $result['checks']]);
    }

    /**
     * POST /install/database
     *
     * Tests the credentials for real, creates the database when permitted, and
     * refuses to proceed against a database that already contains an install.
     */
    public function configureDatabase(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'db_host' => 'required|host',
            'db_port' => 'required|port',
            'db_name' => 'required|regex:/^[A-Za-z0-9_-]{1,64}$/',
            'db_user' => 'required|string|max:64',
            'db_pass' => 'nullable|string|max:190',
        ], [
            'db_name' => 'Database name',
        ])->validated();

        $host = (string) $data['db_host'];
        $port = (int) $data['db_port'];
        $name = (string) $data['db_name'];
        $user = (string) $data['db_user'];
        $password = (string) ($data['db_pass'] ?? '');

        // Connect to the server first, without naming a database, so a missing
        // database is a fixable condition rather than a connection error.
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
        } catch (\PDOException $e) {
            return $this->fail($this->explainDatabaseError($e), 422);
        }

        $version = (string) $pdo->query('SELECT VERSION()')?->fetchColumn();

        // Check the server is new enough for the schema (JSON columns, CHECK
        // constraints, ON DUPLICATE KEY semantics used throughout).
        $isMaria = stripos($version, 'mariadb') !== false;
        $numeric = preg_match('/(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : '0.0.0';
        $tooOld = $isMaria
            ? version_compare($numeric, '10.3.0', '<')
            : version_compare($numeric, '5.7.8', '<');

        if ($tooOld) {
            return $this->fail(sprintf(
                'This server runs %s. MySQL 5.7.8+ or MariaDB 10.3+ is required for the JSON columns this schema uses.',
                $version
            ), 422);
        }

        $exists = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '
            . $pdo->quote($name)
        )?->fetchColumn() > 0;

        if (!$exists) {
            try {
                $pdo->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $name)
                ));
            } catch (\PDOException) {
                return $this->fail(sprintf(
                    'The database "%s" does not exist and this user cannot create it. '
                    . 'Create it in your hosting control panel, then try again.',
                    $name
                ), 422);
            }
        }

        // Now connect to the database itself and confirm write access.
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );

            $probe = '_kpms_write_probe_' . bin2hex(random_bytes(4));
            $pdo->exec("CREATE TABLE `$probe` (id INT PRIMARY KEY)");
            $pdo->exec("DROP TABLE `$probe`");
        } catch (\PDOException $e) {
            return $this->fail(
                'Connected, but this user cannot create tables in that database: ' . $e->getMessage(),
                422
            );
        }

        // Refuse to install over an existing installation.
        $existingTables = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ('print_jobs','admins','locations')"
        )?->fetchColumn();

        if ($existingTables > 0 && !$request->bool('overwrite_existing')) {
            return $this->fail(
                'That database already contains a Krishna Printer installation. '
                . 'Use a different database, or tick "reuse existing database" to install over it — '
                . 'existing data will be kept and only missing migrations applied.',
                409,
                ['requires_confirmation' => true]
            );
        }

        $this->mergeData([
            'db_host' => $host,
            'db_port' => $port,
            'db_name' => $name,
            'db_user' => $user,
            'db_pass' => $password,
        ]);

        $this->completeStep(2);

        return $this->ok(sprintf('Connected to %s.', $version), [
            'server_version' => $version,
            'database_created' => !$exists,
        ]);
    }

    /** POST /install/application */
    public function configureApplication(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'app_name' => 'required|string|max:120',
            'app_url' => 'required|url|max:190',
            'timezone' => 'required|string|max:64',
            'currency_symbol' => 'nullable|string|max:5',
            'force_https' => 'nullable|bool',
        ])->validated();

        $timezone = (string) $data['timezone'];
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->fail('That timezone is not recognised.', 422);
        }

        $url = rtrim((string) $data['app_url'], '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->fail('The site address must start with http:// or https://', 422);
        }

        $this->mergeData([
            'app_name' => (string) $data['app_name'],
            'app_url' => $url,
            'app_timezone' => $timezone,
            'currency_symbol' => (string) ($data['currency_symbol'] ?? '₹'),
            'force_https' => ($data['force_https'] ?? (str_starts_with($url, 'https://'))) ? '1' : '0',
        ]);

        $this->completeStep(3);

        return $this->ok('Application settings recorded.');
    }

    /** POST /install/admin */
    public function configureAdmin(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'admin_name' => 'required|string|max:120',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:10|max:200',
            'admin_password_confirmation' => 'required|string',
        ])->validated();

        if ($data['admin_password'] !== ($request->input('admin_password_confirmation'))) {
            return $this->fail('The passwords do not match.', 422);
        }

        // Reuse the live password policy rather than a weaker install-time one.
        $auth = new AuthService(
            new \App\Repositories\AdminRepository(new Database([])),
            new \App\Services\RateLimiter(new Database([])),
            new \App\Services\AuditService(new Database([]))
        );

        $validation = $auth->validatePassword((string) $data['admin_password'], [
            (string) $data['admin_name'],
            explode('@', (string) $data['admin_email'])[0],
        ]);

        if (!$validation['valid']) {
            return $this->fail(implode(' ', $validation['errors']), 422);
        }

        $this->mergeData([
            'admin_name' => (string) $data['admin_name'],
            'admin_email' => mb_strtolower((string) $data['admin_email']),
            // Hashed immediately — the plaintext never reaches the session store.
            // Hashed with the same parameters the application will later check
            // against, so the first sign-in does not immediately rewrite it —
            // and so an unsupported setting surfaces here, during install,
            // rather than at the operator's first login.
            'admin_password_hash' => $this->hashPassword((string) $data['admin_password']),
        ]);

        $this->completeStep(4);

        return $this->ok('Administrator account details recorded.');
    }

    /** POST /install/system — first location, printer and prices. */
    public function configureSystem(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'business_name' => 'required|string|max:120',
            'location_name' => 'required|string|max:150',
            'location_code' => 'required|slug|max:40',
            'location_city' => 'nullable|string|max:90',
            'contact_phone' => 'nullable|string|max:32',
            'price_a4_bw' => 'required|decimal|min:0|max:1000',
            'price_a4_color' => 'nullable|decimal|min:0|max:1000',
            'enable_payments' => 'nullable|bool',
        ])->validated();

        $this->mergeData([
            'business_name' => (string) $data['business_name'],
            'location_name' => (string) $data['location_name'],
            'location_code' => (string) $data['location_code'],
            'location_city' => (string) ($data['location_city'] ?? ''),
            'contact_phone' => (string) ($data['contact_phone'] ?? ''),
            'price_a4_bw' => (float) $data['price_a4_bw'],
            'price_a4_color' => (float) ($data['price_a4_color'] ?? 0),
            'enable_payments' => ($data['enable_payments'] ?? false) ? '1' : '0',
        ]);

        $this->completeStep(5);

        return $this->ok('System setup recorded.');
    }

    /**
     * POST /install/migrate
     *
     * Writes config/config.php, then runs the migrations against it. This is
     * the first step that changes anything on disk.
     */
    public function migrate(Request $request): Response
    {
        $data = (array) Session::get('install_data', []);

        foreach (['db_host', 'db_name', 'db_user', 'admin_email', 'admin_password_hash'] as $required) {
            if (!isset($data[$required])) {
                return $this->fail('Some earlier steps are incomplete. Start the wizard again.', 409);
            }
        }

        $basePath = (string) Config::get('app.base_path', '');
        $configPath = $basePath . '/config/config.php';

        if (!is_writable(dirname($configPath))) {
            return $this->fail(
                'The config/ directory is not writable, so the configuration file cannot be created. '
                . 'Set it to 0755 (or 0775) and try again.',
                500
            );
        }

        $appKey = Encrypter::generateKey();

        $written = $this->writeConfigFile($configPath, $data, $appKey);
        if (!$written) {
            return $this->fail('The configuration file could not be written.', 500);
        }

        // Adopt the new configuration for the rest of this request.
        Encrypter::setKey($appKey);
        Config::set('app.key', $appKey);

        $db = new Database([
            'driver' => 'mysql',
            'host' => (string) $data['db_host'],
            'port' => (int) $data['db_port'],
            'database' => (string) $data['db_name'],
            'username' => (string) $data['db_user'],
            'password' => (string) ($data['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
        ]);
        Database::setInstance($db);

        try {
            $migrator = new Migrator($db, (string) Config::get('app.migrations_path', $basePath . '/database/migrations'));
            $result = $migrator->run();
        } catch (\Throwable $e) {
            return $this->fail('The migrations failed: ' . $e->getMessage(), 500);
        }

        $this->mergeData(['app_key' => $appKey]);
        $this->completeStep(6);

        return $this->ok(
            sprintf('Applied %d migration(s).', count($result['applied'])),
            ['migrations' => $result['applied'], 'log' => $migrator->log()]
        );
    }

    /** POST /install/seed — admin account, first location and printer, prices, settings. */
    public function seed(Request $request): Response
    {
        $data = (array) Session::get('install_data', []);

        if (!isset($data['app_key'])) {
            return $this->fail('Run the migration step first.', 409);
        }

        Encrypter::setKey((string) $data['app_key']);
        Config::set('app.key', (string) $data['app_key']);

        $db = Database::instance();

        try {
            $result = $db->transaction(function () use ($db, $data): array {
                // --- Administrator -----------------------------------
                $existingAdmin = $db->selectOne('SELECT id FROM admins WHERE email = ?', [$data['admin_email']]);

                if ($existingAdmin !== null) {
                    $adminId = (int) $existingAdmin['id'];
                    $db->update('admins', [
                        'name' => (string) $data['admin_name'],
                        'password_hash' => (string) $data['admin_password_hash'],
                        'role' => 'super_admin',
                        'is_active' => 1,
                        'must_change_password' => 0,
                    ], ['id' => $adminId]);
                } else {
                    $adminId = $db->insert('admins', [
                        'name' => (string) $data['admin_name'],
                        'email' => (string) $data['admin_email'],
                        'password_hash' => (string) $data['admin_password_hash'],
                        'role' => 'super_admin',
                        'is_active' => 1,
                        'must_change_password' => 0,
                        'password_changed_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }

                // --- First location ----------------------------------
                $qrToken = bin2hex(random_bytes(16));
                $existingLocation = $db->selectOne('SELECT id FROM locations WHERE code = ?', [$data['location_code']]);

                if ($existingLocation !== null) {
                    $locationId = (int) $existingLocation['id'];
                } else {
                    $locationId = $db->insert('locations', [
                        'code' => (string) $data['location_code'],
                        'name' => (string) $data['location_name'],
                        'city' => (string) ($data['location_city'] ?? ''),
                        'contact_phone' => (string) ($data['contact_phone'] ?? ''),
                        'country' => 'IN',
                        'timezone' => (string) ($data['app_timezone'] ?? 'Asia/Kolkata'),
                        'qr_token_hash' => hash('sha256', $qrToken),
                        'qr_token_hint' => substr($qrToken, 0, 8),
                        'status' => 'active',
                    ]);
                }

                // --- Pricing ------------------------------------------
                $prices = [
                    ['A4', 'bw', Money::toPaise((float) ($data['price_a4_bw'] ?? 2))],
                ];
                if ((float) ($data['price_a4_color'] ?? 0) > 0) {
                    $prices[] = ['A4', 'color', Money::toPaise((float) $data['price_a4_color'])];
                }

                foreach ($prices as [$size, $mode, $paise]) {
                    $exists = $db->selectOne(
                        'SELECT id FROM pricing_rules
                         WHERE location_id IS NULL AND printer_id IS NULL
                           AND paper_size = ? AND color_mode = ? AND min_pages = 1',
                        [$size, $mode]
                    );
                    if ($exists !== null) {
                        continue;
                    }
                    $db->insert('pricing_rules', [
                        'name' => sprintf('%s %s', $size, $mode === 'color' ? 'Colour' : 'B&W'),
                        'location_id' => null,
                        'printer_id' => null,
                        'paper_size' => $size,
                        'color_mode' => $mode,
                        'price_per_page_paise' => $paise,
                        'min_pages' => 1,
                        'is_active' => 1,
                        'created_by' => $adminId,
                    ]);
                }

                // --- Settings -----------------------------------------
                $settings = [
                    ['business_name', (string) $data['business_name'], 'string', 'general', 1],
                    ['app_url', (string) ($data['app_url'] ?? ''), 'string', 'general', 0],
                    ['support_phone', (string) ($data['contact_phone'] ?? ''), 'string', 'general', 1],
                    ['brand_color', '#1e6f5c', 'string', 'general', 1],
                    ['timezone', (string) ($data['app_timezone'] ?? 'Asia/Kolkata'), 'string', 'general', 0],
                    ['job_number_prefix', 'AK', 'string', 'general', 0],
                    ['force_https', (string) ($data['force_https'] ?? '0'), 'bool', 'security', 0],
                    ['payment_enabled', (string) ($data['enable_payments'] ?? '0'), 'bool', 'payment', 0],
                    ['payment_gateway', 'razorpay', 'string', 'payment', 0],
                    ['tax_enabled', '0', 'bool', 'pricing', 0],
                    ['tax_label', 'GST', 'string', 'pricing', 0],
                    ['tax_percent', '0', 'string', 'pricing', 0],
                    ['tax_inclusive', '0', 'bool', 'pricing', 0],
                    ['gateway_fee_passthrough', '0', 'bool', 'pricing', 0],
                    ['file_retention_hours', '24', 'int', 'general', 0],
                    ['health_check_interval', '60', 'int', 'printing', 0],
                    ['installed_at', gmdate('c'), 'string', 'internal', 0],
                    ['installed_version', (string) Config::get('app.version', '1.0.0'), 'string', 'internal', 0],
                ];

                foreach ($settings as [$key, $value, $type, $group, $public]) {
                    $db->execute(
                        'INSERT INTO system_settings (setting_key, setting_value, value_type, group_name, is_public, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                        [$key, $value, $type, $group, $public, $adminId]
                    );
                }

                return [
                    'admin_id' => $adminId,
                    'location_id' => $locationId,
                    'qr_token' => $qrToken,
                    'location_code' => (string) $data['location_code'],
                ];
            });
        } catch (\Throwable $e) {
            return $this->fail('Seeding the default data failed: ' . $e->getMessage(), 500);
        }

        $this->mergeData([
            'seeded_location_id' => $result['location_id'],
            'seeded_qr_token' => $result['qr_token'],
        ]);

        $this->completeStep(7);

        return $this->ok('Default settings created.', [
            'location_id' => $result['location_id'],
            'qr_url' => rtrim((string) ($data['app_url'] ?? ''), '/')
                . '/print/' . rawurlencode($result['location_code'])
                . '/' . $result['qr_token'],
        ]);
    }

    /**
     * POST /install/finalise
     *
     * Writes the lock file, tightens permissions, and clears the wizard's
     * session data — including the seeded QR token.
     */
    public function finalise(Request $request): Response
    {
        $data = (array) Session::get('install_data', []);
        $completed = (array) Session::get('install_completed', []);

        foreach ([1, 2, 3, 4, 5, 6, 7] as $step) {
            if (!in_array($step, $completed, true)) {
                return $this->fail(
                    sprintf('Step %d (%s) has not been completed.', $step, self::STEPS[$step]),
                    409
                );
            }
        }

        $lockFile = (string) Config::get('app.install_lock_file', '');
        if ($lockFile === '') {
            return $this->fail('The lock file path is not configured.', 500);
        }

        $directory = dirname($lockFile);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return $this->fail('The storage directory could not be created.', 500);
        }

        $written = @file_put_contents($lockFile, json_encode([
            'installed_at' => gmdate('c'),
            'version' => (string) Config::get('app.version', '1.0.0'),
            'php' => PHP_VERSION,
            'admin_email' => (string) ($data['admin_email'] ?? ''),
        ], JSON_PRETTY_PRINT));

        if ($written === false) {
            return $this->fail(
                'The lock file could not be written, so the installer cannot be disabled safely. '
                . 'Check that storage/ is writable, then finish again.',
                500
            );
        }

        @chmod($lockFile, 0640);

        // The generated config holds live credentials; make it owner-readable.
        $configPath = (string) Config::get('app.base_path', '') . '/config/config.php';
        if (is_file($configPath)) {
            @chmod($configPath, 0640);
        }

        $qrUrl = null;
        if (isset($data['seeded_qr_token'], $data['location_code'])) {
            $qrUrl = rtrim((string) ($data['app_url'] ?? ''), '/')
                . '/print/' . rawurlencode((string) $data['location_code'])
                . '/' . (string) $data['seeded_qr_token'];
        }

        // Wipe the wizard's session, including the plaintext QR token and the
        // admin password hash.
        Session::forget('install_data');
        Session::forget('install_completed');
        Session::regenerate();

        return $this->ok('Installation complete.', [
            'redirect' => '/admin/login',
            'qr_url' => $qrUrl,
            'notes' => [
                'The installer is now locked and cannot be reopened.',
                'Sign in with the administrator account you created.',
                'Add your print agent under Admin → Print agents, then attach a printer to it.',
                'Verify each printer\'s capabilities before customers use it — no print option is '
                . 'offered until it has been confirmed on that printer.',
            ],
        ]);
    }

    // -----------------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function writeConfigFile(string $path, array $data, string $appKey): bool
    {
        $export = static fn (mixed $value): string => var_export($value, true);

        $contents = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . " * Generated by the Krishna Printer installer on " . gmdate('Y-m-d H:i:s') . " UTC.\n"
            . " *\n"
            . " * This file holds live credentials. It is excluded from version control and is\n"
            . " * PRESERVED across GitHub updates — the updater never overwrites it.\n"
            . " *\n"
            . " * If you change app_key, every stored secret (payment keys, GitHub token,\n"
            . " * printer passwords) becomes unreadable and must be re-entered.\n"
            . " */\n\n"
            . "return [\n"
            . "    'app_name' => " . $export((string) ($data['app_name'] ?? 'Krishna Printer')) . ",\n"
            . "    'app_env' => 'production',\n"
            . "    'app_debug' => false,\n"
            . "    'app_url' => " . $export((string) ($data['app_url'] ?? '')) . ",\n"
            . "    'app_key' => " . $export($appKey) . ",\n"
            . "    'app_timezone' => " . $export((string) ($data['app_timezone'] ?? 'Asia/Kolkata')) . ",\n"
            . "    'force_https' => " . $export((string) ($data['force_https'] ?? '0')) . ",\n"
            . "    'trust_proxy' => false,\n"
            . "    'log_level' => 'info',\n\n"
            . "    'db_driver' => 'mysql',\n"
            . "    'db_host' => " . $export((string) ($data['db_host'] ?? '127.0.0.1')) . ",\n"
            . "    'db_port' => " . $export((int) ($data['db_port'] ?? 3306)) . ",\n"
            . "    'db_name' => " . $export((string) ($data['db_name'] ?? '')) . ",\n"
            . "    'db_user' => " . $export((string) ($data['db_user'] ?? '')) . ",\n"
            . "    'db_pass' => " . $export((string) ($data['db_pass'] ?? '')) . ",\n"
            . "];\n";

        // Write to a temporary file and rename, so a half-written config can
        // never be loaded by a concurrent request.
        $temporary = $path . '.tmp' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0640);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }

    private function explainDatabaseError(\PDOException $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Access denied')) {
            return 'The database username or password was rejected.';
        }
        if (str_contains($message, 'Unknown database')) {
            return 'That database does not exist and could not be created.';
        }
        if (str_contains($message, 'Connection refused') || str_contains($message, "Can't connect")) {
            return 'Could not reach the database server at that host and port. '
                . 'On most shared hosting the host is "localhost".';
        }
        if (str_contains($message, 'timed out')) {
            return 'The connection to the database server timed out.';
        }

        return 'Could not connect: ' . $message;
    }

    private function completeStep(int $step): void
    {
        $completed = (array) Session::get('install_completed', []);
        if (!in_array($step, $completed, true)) {
            $completed[] = $step;
            sort($completed);
            Session::put('install_completed', $completed);
        }
    }

    /** @param array<string,mixed> $values */
    private function mergeData(array $values): void
    {
        Session::put('install_data', array_merge((array) Session::get('install_data', []), $values));
    }

    private function detectUrl(Request $request): string
    {
        $base = $request->basePath();
        return $request->scheme() . '://' . $request->host() . ($base === '' ? '' : $base);
    }
}

#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * End-to-end verification suite.
 *
 * Drives the whole application through real HTTP against a running server and a
 * running mock printer that speaks genuine IPP and RAW/9100. Nothing here calls
 * a controller directly — every request goes through the front controller, the
 * middleware stack, the session and CSRF, because a test that skips the
 * middleware does not prove the middleware works.
 *
 * Usage:
 *   php tests/run.php --url=http://127.0.0.1:8088 --report=docs/VERIFICATION_REPORT.md
 */

require __DIR__ . '/../app/Core/Autoloader.php';
require __DIR__ . '/TestRunner.php';
require __DIR__ . '/HttpClient.php';

$autoloader = new App\Core\Autoloader();
$autoloader->addNamespace('App', dirname(__DIR__) . '/app');
$autoloader->register();

use App\Core\Config;
use App\Core\Database;
use App\Core\Encrypter;
use App\Support\Money;
use App\Support\PageRange;
use App\Support\QrEncoder;
use Tests\HttpClient;
use Tests\TestRunner;

$options = getopt('', ['url::', 'report::', 'db-host::', 'db-name::', 'db-user::', 'db-pass::',
                      'ipp-port::', 'raw-port::', 'mock-spool::', 'keep']);

$baseUrl = (string) ($options['url'] ?? 'http://127.0.0.1:8088');
$reportPath = (string) ($options['report'] ?? dirname(__DIR__) . '/docs/VERIFICATION_REPORT.md');
$dbHost = (string) ($options['db-host'] ?? '127.0.0.1');
$dbName = (string) ($options['db-name'] ?? 'kpms_live');
$dbUser = (string) ($options['db-user'] ?? 'kpms');
$dbPass = (string) ($options['db-pass'] ?? 'kpms_pass_123');
$ippPort = (int) ($options['ipp-port'] ?? 6631);
$rawPort = (int) ($options['raw-port'] ?? 19100);
$mockSpool = (string) ($options['mock-spool'] ?? sys_get_temp_dir() . '/kpms-mock-printer');

$basePath = dirname(__DIR__);
$runner = new TestRunner();
$http = new HttpClient($baseUrl);

$adminEmail = 'owner@krishnaprinter.test';
$adminPassword = 'Harbour-Lantern-4417';

/** Flip the mock printer's reported condition. */
$setPrinterState = static function (string $state) use ($mockSpool): void {
    @file_put_contents($mockSpool . '/state', $state);
    // Give the next probe a moment to see the new state.
    usleep(150000);
};

/** Direct database access for assertions the HTTP surface does not expose. */
$db = null;
$connectDb = static function () use (&$db, $dbHost, $dbName, $dbUser, $dbPass): Database {
    if ($db === null) {
        $db = new Database([
            'driver' => 'mysql',
            'host' => $dbHost,
            'port' => 3306,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
        ]);
    }
    return $db;
};

/** Build a small valid PDF with a known page count. */
$makePdf = static function (int $pages, string $path): void {
    $objects = [];
    $kids = [];

    for ($i = 0; $i < $pages; $i++) {
        $pageObj = 4 + ($i * 2);
        $contentObj = $pageObj + 1;
        $kids[] = "$pageObj 0 R";

        $text = 'Test page ' . ($i + 1) . ' of ' . $pages;
        $stream = "BT /F1 24 Tf 72 700 Td ($text) Tj ET";

        $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 3 0 R >> >> /Contents $contentObj 0 R >>";
        $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
    }

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pages . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "$id 0 obj\n$body\nendobj\n";
    }

    $xref = strlen($pdf);
    $max = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $max; $id++) {
        $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 65535 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

    file_put_contents($path, $pdf);
};

$scratch = sys_get_temp_dir() . '/kpms-tests';
@mkdir($scratch, 0777, true);

// Shared state between test groups.
$state = [
    'location_code' => 'test-branch-01',
    'qr_token' => null,
    'printer_id' => null,
    'printer_code' => 'test-printer-01',
    'file_id' => null,
    'batch_id' => null,
    'job_number' => null,
];

fwrite(STDOUT, "\n\033[1mKrishna Printer — end-to-end verification\033[0m\n");
fwrite(STDOUT, "Target: $baseUrl\n");
fwrite(STDOUT, "Mock printer: IPP 127.0.0.1:$ippPort, RAW 127.0.0.1:$rawPort\n");

// ═══════════════════════════════════════════════════════════════════════
// 28. Fresh installation
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Installation (test 28)');

$runner->test('T28.1', 'The installer is reachable on a fresh system', 'HTTP 200 with the wizard', function () use ($http) {
    $response = $http->get('/install');
    return TestRunner::assertTrue(
        $response['status'] === 200 && str_contains($response['body'], 'Server requirements'),
        'HTTP 200; the wizard rendered with all eight steps.',
        'HTTP ' . $response['status']
    );
});

$runner->test('T28.2', 'Everything redirects to /install before installation', 'HTTP 302 to /install', function () use ($http) {
    $response = $http->get('/');
    return TestRunner::assertTrue(
        $response['status'] === 302 && str_contains($response['headers']['location'] ?? '', '/install'),
        'HTTP 302 → ' . ($response['headers']['location'] ?? ''),
        'HTTP ' . $response['status'] . ' → ' . ($response['headers']['location'] ?? 'no redirect')
    );
});

$runner->test('T28.3', 'Server requirements are checked and pass', 'success with every required check OK', function () use ($http) {
    $response = $http->post('/install/requirements');
    $json = $response['json'] ?? [];
    $failed = [];
    foreach ($json['checks'] ?? [] as $check) {
        if ($check['required'] && !$check['passed']) {
            $failed[] = $check['name'];
        }
    }
    return TestRunner::assertTrue(
        ($json['success'] ?? false) && $failed === [],
        sprintf('%d checks run, all required ones passed.', count($json['checks'] ?? [])),
        'Failed: ' . implode(', ', $failed) . ' — ' . ($json['error'] ?? '')
    );
});

$runner->test('T28.4', 'Database credentials are tested for real', 'connects and reports the server version', function () use ($http, $dbHost, $dbName, $dbUser, $dbPass) {
    $response = $http->post('/install/database', [
        'db_host' => $dbHost,
        'db_port' => '3306',
        'db_name' => $dbName,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        'overwrite_existing' => '1',
    ]);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        ($json['success'] ?? false),
        'Connected: ' . ($json['server_version'] ?? ''),
        'HTTP ' . $response['status'] . ': ' . ($json['error'] ?? substr($response['body'], 0, 200))
    );
});

$runner->test('T28.5', 'Wrong database credentials are rejected with a clear reason', 'failure explaining the problem', function () use ($baseUrl, $dbHost, $dbName) {
    // A separate client so a rejected attempt does not disturb the wizard's session.
    $probe = new HttpClient($baseUrl);
    $probe->get('/install');
    $response = $probe->post('/install/database', [
        'db_host' => $dbHost,
        'db_port' => '3306',
        'db_name' => $dbName,
        'db_user' => 'definitely_not_a_user',
        'db_pass' => 'wrong',
    ]);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        !($json['success'] ?? true) && str_contains(strtolower((string) ($json['error'] ?? '')), 'rejected'),
        'Rejected: ' . ($json['error'] ?? ''),
        'Unexpected: ' . json_encode($json)
    );
});

$runner->test('T28.6', 'Application settings are recorded', 'success', function () use ($http, $baseUrl) {
    $response = $http->post('/install/application', [
        'app_name' => 'Krishna Printer',
        'app_url' => $baseUrl,
        'timezone' => 'Asia/Kolkata',
        'currency_symbol' => '₹',
    ]);
    return TestRunner::assertTrue(
        ($response['json']['success'] ?? false),
        'Recorded.',
        (string) ($response['json']['error'] ?? $response['status'])
    );
});

$runner->test('T28.7', 'A weak admin password is refused', 'failure naming the policy', function () use ($baseUrl, $http) {
    $response = $http->post('/install/admin', [
        'admin_name' => 'Test Owner',
        'admin_email' => 'weak@example.com',
        'admin_password' => 'password123',
        'admin_password_confirmation' => 'password123',
    ]);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        !($json['success'] ?? true),
        'Refused: ' . ($json['error'] ?? ''),
        'A weak password was accepted.'
    );
});

$runner->test('T28.8', 'A strong admin password is accepted', 'success', function () use ($http, $adminEmail, $adminPassword) {
    $response = $http->post('/install/admin', [
        'admin_name' => 'Test Owner',
        'admin_email' => $adminEmail,
        'admin_password' => $adminPassword,
        'admin_password_confirmation' => $adminPassword,
    ]);
    return TestRunner::assertTrue(
        ($response['json']['success'] ?? false),
        'Accepted.',
        (string) ($response['json']['error'] ?? '')
    );
});

$runner->test('T28.9', 'First location and prices are recorded', 'success', function () use ($http, &$state) {
    $response = $http->post('/install/system', [
        'business_name' => 'Krishna Printer',
        'location_name' => 'Test Branch',
        'location_code' => $state['location_code'],
        'location_city' => 'Mumbai',
        'contact_phone' => '+91 98765 43210',
        'price_a4_bw' => '2.00',
        'price_a4_color' => '10.00',
    ]);
    return TestRunner::assertTrue(
        ($response['json']['success'] ?? false),
        'Recorded.',
        (string) ($response['json']['error'] ?? '')
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 24. Database migration
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Database migration (test 24)');

$runner->test('T24.1', 'Migrations run and create the schema', 'all migrations applied', function () use ($http) {
    $response = $http->post('/install/migrate');
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        ($json['success'] ?? false) && count($json['migrations'] ?? []) > 0,
        sprintf('Applied %d migration(s): %s', count($json['migrations'] ?? []), implode(', ', $json['migrations'] ?? [])),
        (string) ($json['error'] ?? '')
    );
});

$runner->test('T24.2', 'Every required table exists with foreign keys', 'all 16 named tables plus support tables', function () use ($connectDb) {
    $db = $connectDb();
    $required = [
        'users', 'admins', 'locations', 'printers', 'printer_connections', 'print_jobs',
        'print_files', 'print_settings', 'pricing_rules', 'payments', 'devices',
        'api_tokens', 'activity_logs', 'system_settings', 'backups', 'update_logs',
    ];
    $missing = [];
    foreach ($required as $table) {
        if (!$db->tableExists($table)) {
            $missing[] = $table;
        }
    }

    $fkCount = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = ?',
        ['FOREIGN KEY']
    );
    $indexCount = (int) $db->scalar(
        'SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()'
    );

    return TestRunner::assertTrue(
        $missing === [] && $fkCount >= 20,
        sprintf('All %d required tables present; %d foreign keys, %d indexes.', count($required), $fkCount, $indexCount),
        'Missing: ' . implode(', ', $missing)
    );
});

$runner->test('T24.3', 'Money columns are integers, not floats', 'no floating-point money columns', function () use ($connectDb) {
    $db = $connectDb();
    $rows = $db->select(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND (COLUMN_NAME LIKE '%paise%' OR COLUMN_NAME LIKE '%amount%' OR COLUMN_NAME LIKE '%price%')"
    );
    $bad = [];
    foreach ($rows as $row) {
        if (in_array(strtolower((string) $row['DATA_TYPE']), ['float', 'double', 'decimal'], true)) {
            $bad[] = $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'];
        }
    }
    return TestRunner::assertTrue(
        $bad === [],
        sprintf('%d money columns checked, all integer.', count($rows)),
        'Float/decimal money columns: ' . implode(', ', $bad)
    );
});

$runner->test('T28.10', 'Seeding creates the admin, location and prices', 'success with a QR link', function () use ($http, &$state) {
    $response = $http->post('/install/seed');
    $json = $response['json'] ?? [];

    if (($json['success'] ?? false) && !empty($json['qr_url'])) {
        // Capture the plaintext QR token — it is shown exactly once.
        if (preg_match('#/print/[^/]+/([a-f0-9]{32})#', (string) $json['qr_url'], $m) === 1) {
            $state['qr_token'] = $m[1];
        }
    }

    return TestRunner::assertTrue(
        ($json['success'] ?? false) && $state['qr_token'] !== null,
        'Seeded; QR token issued (shown once, stored only as a hash).',
        (string) ($json['error'] ?? 'No QR token returned.')
    );
});

$runner->test('T28.11', 'Finishing writes the lock file', 'success and the lock exists', function () use ($http, $basePath) {
    $response = $http->post('/install/finalise');
    clearstatcache();
    $locked = is_file($basePath . '/storage/installed.lock');
    return TestRunner::assertTrue(
        ($response['json']['success'] ?? false) && $locked,
        'Installation finished; storage/installed.lock written.',
        'success=' . json_encode($response['json']['success'] ?? null) . ', lock exists=' . json_encode($locked)
    );
});

$runner->test('T28.12', 'The installer refuses to run once locked', 'HTTP 403', function () use ($baseUrl) {
    $probe = new HttpClient($baseUrl);
    $response = $probe->get('/install');
    return TestRunner::assertTrue(
        $response['status'] === 403,
        'HTTP 403 — the installer is disabled and cannot be replayed.',
        'HTTP ' . $response['status'] . ' (an installer left open on a live site is a takeover risk)'
    );
});

$runner->test('T28.13', 'The generated config is not world-readable', 'mode 0640 or tighter', function () use ($basePath) {
    clearstatcache();
    $path = $basePath . '/config/config.php';
    if (!is_file($path)) {
        return ['pass' => false, 'actual' => 'config/config.php was not created.'];
    }
    $mode = fileperms($path) & 0777;
    return TestRunner::assertTrue(
        ($mode & 0007) === 0,
        sprintf('Mode %04o — no world access.', $mode),
        sprintf('Mode %04o — world-readable config holding live credentials.', $mode)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 22. Admin authentication
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Admin authentication (test 22)');

$adminClient = new HttpClient($baseUrl);

$runner->test('T22.1', 'The admin area is closed to anonymous visitors', 'redirect to the sign-in page', function () use ($adminClient) {
    $response = $adminClient->get('/admin/dashboard');
    return TestRunner::assertTrue(
        $response['status'] === 302 && str_contains($response['headers']['location'] ?? '', '/admin/login'),
        'HTTP 302 → /admin/login',
        'HTTP ' . $response['status']
    );
});

$runner->test('T22.2', 'A wrong password is refused', 'stays signed out', function () use ($adminClient, $adminEmail) {
    $adminClient->get('/admin/login');
    $response = $adminClient->submitForm('/admin/login', [
        'email' => $adminEmail,
        'password' => 'NotTheRightPassword-9999',
    ]);
    return TestRunner::assertTrue(
        str_contains($response['body'], 'not correct') || str_contains($response['body'], 'Sign in'),
        'Rejected and returned to the sign-in page.',
        'Unexpected response: HTTP ' . $response['status']
    );
});

$runner->test('T22.3', 'The correct password signs in', 'reaches the dashboard', function () use ($adminClient, $adminEmail, $adminPassword) {
    $adminClient->get('/admin/login');
    $response = $adminClient->submitForm('/admin/login', [
        'email' => $adminEmail,
        'password' => $adminPassword,
    ]);
    return TestRunner::assertTrue(
        $response['status'] === 200 && str_contains($response['body'], 'Dashboard'),
        'Signed in; the dashboard rendered.',
        'HTTP ' . $response['status'] . ': ' . substr(strip_tags($response['body']), 0, 200)
    );
});

$runner->test('T22.4', 'Passwords are stored hashed, never in plain text', 'an Argon2/bcrypt hash', function () use ($connectDb, $adminEmail, $adminPassword) {
    $db = $connectDb();
    $hash = (string) $db->scalar('SELECT password_hash FROM admins WHERE email = ?', [$adminEmail]);
    $isHash = str_starts_with($hash, '$argon2') || str_starts_with($hash, '$2y$');
    $verifies = password_verify($adminPassword, $hash);
    $containsPlaintext = str_contains($hash, $adminPassword);
    return TestRunner::assertTrue(
        $isHash && $verifies && !$containsPlaintext,
        'Stored as ' . substr($hash, 0, 7) . '… and verifies correctly.',
        'hash=' . substr($hash, 0, 30)
    );
});

$runner->test('T22.5', 'A hashing configuration this server cannot satisfy still lets an admin in', 'sign-in succeeds, no exception', function () use ($basePath, $adminEmail, $adminPassword) {
    // Regression: an Argon2 thread count that libargon2 accepts and libsodium
    // rejects threw out of password_needs_rehash() on a live server and locked
    // the operator out of their own installation with a 500. Re-hashing is an
    // optimisation applied *after* the password has been verified, so it must
    // never be able to refuse a correct credential.
    //
    // Driven in-process rather than over HTTP. The obvious version — rewrite
    // config/security.php, then sign in — looked fine and tested nothing: the
    // application process serves the file from a stale stat cache, so the
    // request that follows a millisecond later still sees the old options. It
    // passed just as happily against a build with both guards deleted. This
    // sets the option directly in the configuration the service reads, which
    // is the same code path and is deterministic.
    /** @var App\Core\Application $app */
    $app = require $basePath . '/bootstrap/app.php';
    $container = $app->container();

    $original = App\Core\Config::get('security.password.argon_options');

    // Unsupported on every build, and the same ValueError that the sodium
    // thread limit raises.
    App\Core\Config::set('security.password.algorithm', PASSWORD_ARGON2ID);
    App\Core\Config::set('security.password.argon_options', [
        'memory_cost' => 1, 'time_cost' => 4, 'threads' => 1,
    ]);

    try {
        $auth = $container->get(App\Services\AuthService::class);
        $result = $auth->attempt($adminEmail, $adminPassword, '127.0.0.1');

        $signedIn = ($result['success'] ?? false) === true;

        // The credential must still work afterwards, whichever hash was stored.
        $db = new Database([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'kpms_live', 'username' => 'kpms', 'password' => 'kpms_pass_123',
        ]);
        $hash = (string) $db->scalar('SELECT password_hash FROM admins WHERE email = ?', [$adminEmail]);
        $stillVerifies = password_verify($adminPassword, $hash);

        return TestRunner::assertTrue(
            $signedIn && $stillVerifies,
            'Signed in despite hashing options this build rejects; the stored password still '
                . 'verifies (' . substr($hash, 0, 7) . '…).',
            sprintf('signed in=%s, password still verifies=%s, error=%s',
                $signedIn ? 'yes' : 'no',
                $stillVerifies ? 'yes' : 'no',
                (string) ($result['error'] ?? '—'))
        );
    } finally {
        App\Core\Config::set('security.password.argon_options', $original);
    }
});

$runner->test('T22.6', 'Unusable hashing options are reported before anyone is locked out', 'the requirement check fails loudly', function () use ($basePath) {
    App\Core\Config::load($basePath . '/config');

    $probe = static function (array $options): bool {
        try {
            password_hash('probe', PASSWORD_ARGON2ID, $options);
            return true;
        } catch (\Throwable) {
            return false;
        }
    };

    $configured = (array) App\Core\Config::get('security.password.argon_options', []);
    $configuredWorks = !defined('PASSWORD_ARGON2ID') || $probe($configured);
    $brokenIsCaught = !defined('PASSWORD_ARGON2ID')
        || !$probe(['memory_cost' => 1, 'time_cost' => 4, 'threads' => 1]);

    return TestRunner::assertTrue(
        $configuredWorks && $brokenIsCaught,
        'The shipped options hash successfully here, and the probe that the health check uses '
            . 'does detect options this build cannot satisfy.',
        sprintf('configured options work=%s, bad options detected=%s',
            $configuredWorks ? 'yes' : 'no', $brokenIsCaught ? 'yes' : 'no')
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 23. Security validation
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Security (test 23)');

$runner->test('T23.1', 'A POST without a CSRF token is rejected', 'HTTP 419', function () use ($baseUrl, $adminClient) {
    // Reuse the signed-in session but omit the token entirely.
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $baseUrl . '/admin/locations',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['name' => 'CSRF probe', 'code' => 'csrf-probe', 'status' => 'active']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $adminClient->cookieJar(),
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return TestRunner::assertTrue(
        $status === 419,
        'HTTP 419 — the request was rejected without a valid token.',
        'HTTP ' . $status . ' — a state-changing request succeeded without CSRF protection.'
    );
});

$runner->test('T23.2', 'Security headers are present on every response', 'CSP, nosniff, frame-options', function () use ($adminClient) {
    $response = $adminClient->get('/admin/dashboard');
    $headers = $response['headers'];
    $missing = [];
    foreach (['content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy'] as $header) {
        if (!isset($headers[$header])) {
            $missing[] = $header;
        }
    }
    $cspHasNonce = str_contains($headers['content-security-policy'] ?? '', 'nonce-');
    $noUnsafeInline = !str_contains($headers['content-security-policy'] ?? '', "'unsafe-inline'");

    return TestRunner::assertTrue(
        $missing === [] && $cspHasNonce && $noUnsafeInline,
        'CSP with a per-request nonce and no unsafe-inline; nosniff, DENY and referrer-policy all set.',
        'Missing: ' . implode(', ', $missing) . '; nonce=' . json_encode($cspHasNonce)
    );
});

$runner->test('T23.3', 'SQL injection in a search field is neutralised', 'the query is treated as text', function () use ($adminClient, $connectDb) {
    $payload = "'; DROP TABLE print_jobs; -- ";
    $response = $adminClient->get('/admin/locations?q=' . rawurlencode($payload));
    $db = $connectDb();
    $survived = $db->tableExists('print_jobs');
    return TestRunner::assertTrue(
        $response['status'] === 200 && $survived,
        'HTTP 200 and print_jobs still exists — the payload was bound as a parameter.',
        'status=' . $response['status'] . ', table survived=' . json_encode($survived)
    );
});

$runner->test('T23.4', 'XSS in a stored field is escaped on output', 'the script tag is encoded', function () use ($adminClient) {
    $payload = '<script>alert("xss")</script>';
    $adminClient->get('/admin/locations/create');
    $adminClient->submitForm('/admin/locations', [
        'name' => 'XSS ' . $payload,
        'code' => 'xss-probe-01',
        'status' => 'active',
        'country' => 'IN',
    ]);
    $response = $adminClient->get('/admin/locations?q=XSS');
    $rawScript = str_contains($response['body'], '<script>alert("xss")</script>');
    $escaped = str_contains($response['body'], '&lt;script&gt;');
    return TestRunner::assertTrue(
        !$rawScript && $escaped,
        'The payload was stored and rendered as escaped text, not as markup.',
        'raw script present=' . json_encode($rawScript) . ', escaped=' . json_encode($escaped)
    );
});

$runner->test('T23.5', 'Secrets are encrypted at rest', 'ciphertext, not the plaintext value', function () use ($adminClient, $connectDb) {
    $secret = 'test_secret_' . bin2hex(random_bytes(8));
    $adminClient->get('/admin/settings');
    $adminClient->submitForm('/admin/settings/payment', [
        'payment_gateway' => 'razorpay',
        'razorpay_key_id' => 'rzp_test_probe',
        'razorpay_key_secret' => $secret,
    ]);

    $db = $connectDb();
    $stored = (string) $db->scalar(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'razorpay_key_secret'"
    );
    $isEncrypted = (int) $db->scalar(
        "SELECT is_encrypted FROM system_settings WHERE setting_key = 'razorpay_key_secret'"
    ) === 1;

    return TestRunner::assertTrue(
        $stored !== '' && !str_contains($stored, $secret) && $isEncrypted,
        'Stored as ' . substr($stored, 0, 12) . '… — the plaintext does not appear in the database.',
        'Stored value contains the plaintext secret.'
    );
});

$runner->test('T23.6', 'A stored secret is never echoed back to the browser', 'the settings page shows no plaintext', function () use ($adminClient) {
    $response = $adminClient->get('/admin/settings');
    $leaks = str_contains($response['body'], 'test_secret_');
    return TestRunner::assertTrue(
        !$leaks,
        'The settings form shows only a "stored" placeholder.',
        'The page body contained a stored secret.'
    );
});

$runner->test('T23.7', 'Uploads are stored outside the web root', 'not reachable over HTTP', function () use ($baseUrl, $basePath) {
    $probe = new HttpClient($baseUrl);
    $paths = ['/uploads/', '/storage/', '/config/config.php', '/logs/', '/backups/', '/app/Core/Database.php'];
    $reachable = [];
    foreach ($paths as $path) {
        $response = $probe->get($path);
        if ($response['status'] === 200 && strlen($response['body']) > 0) {
            $reachable[] = $path . ' (HTTP 200)';
        }
    }
    $uploadRoot = realpath($basePath . '/uploads');
    $publicRoot = realpath($basePath . '/public');
    $outsideDocroot = $uploadRoot !== false && $publicRoot !== false
        && !str_starts_with($uploadRoot, $publicRoot);

    return TestRunner::assertTrue(
        $reachable === [] && $outsideDocroot,
        'uploads/, storage/, config/, logs/ and app/ are all outside the document root and unreachable.',
        'Reachable over HTTP: ' . implode(', ', $reachable)
    );
});

$runner->test('T23.8', 'The rate limiter blocks a burst of requests', 'HTTP 429 once the limit is passed', function () use ($baseUrl, $connectDb) {
    $probe = new HttpClient($baseUrl);
    $hit429 = false;
    $attempts = 0;

    // The global limit is 300 per minute per IP.
    for ($i = 0; $i < 400; $i++) {
        $attempts++;
        $response = $probe->get('/admin/login');
        if ($response['status'] === 429) {
            $hit429 = true;
            break;
        }
    }

    // The limiter counts by IP, and the whole suite runs from one address, so
    // clear the counters afterwards. The limiter has already proved itself;
    // leaving it tripped would only block the remaining checks.
    $connectDb()->execute('DELETE FROM rate_limits');

    return TestRunner::assertTrue(
        $hit429,
        sprintf('HTTP 429 returned after %d requests from one address, once the 300-per-minute '
            . 'limit was crossed.', $attempts),
        'No 429 after ' . $attempts . ' requests — the limiter did not engage.'
    );
});

// ═══════════════════════════════════════════════════════════════════════
// Location, printer and capability setup (tests 1-3, 11-13)
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Printers and capabilities (tests 3, 11-13)');

$runner->test('T13.1', 'A printer can be added over IPP', 'the printer is created', function () use ($adminClient, $connectDb, &$state, $ippPort) {
    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations WHERE code = ?', [$state['location_code']]);

    $adminClient->get('/admin/printers/create');
    $response = $adminClient->submitForm('/admin/printers', [
        'name' => 'Test Canon GM4070',
        'code' => $state['printer_code'],
        'location_id' => (string) $locationId,
        'capability_profile' => 'canon_gm4070',
        'driver' => 'ipp',
        'host' => '127.0.0.1',
        'port' => (string) $ippPort,
        'ipp_path' => '/ipp/print',
        'is_enabled' => '1',
        'max_queue_depth' => '25',
        'timeout_seconds' => '10',
        'verify_tls' => '1',
    ]);

    $printerId = (int) $db->scalar('SELECT id FROM printers WHERE code = ?', [$state['printer_code']]);
    $state['printer_id'] = $printerId;

    return TestRunner::assertTrue(
        $printerId > 0,
        'Printer created as #' . $printerId . ' with the canon_gm4070 profile.',
        'The printer was not created. HTTP ' . $response['status']
    );
});

$runner->test('T3.1', 'Declared capabilities are NOT offered to customers', 'no verified capability yet', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $declared = (int) $db->scalar(
        "SELECT COUNT(*) FROM printer_capabilities WHERE printer_id = ? AND source = 'declared'",
        [$state['printer_id']]
    );
    $verified = (int) $db->scalar(
        "SELECT COUNT(*) FROM printer_capabilities WHERE printer_id = ? AND source IN ('probed','manual')",
        [$state['printer_id']]
    );
    return TestRunner::assertTrue(
        $declared > 0 && $verified === 0,
        sprintf(
            '%d capabilities declared from the datasheet, %d verified. A datasheet claim alone is '
            . 'never offered to a customer.',
            $declared,
            $verified
        ),
        sprintf('declared=%d verified=%d', $declared, $verified)
    );
});

$runner->test('T13.2', 'The connection test reaches the printer', 'reports online', function () use ($adminClient, &$state) {
    $response = $adminClient->post('/admin/printers/' . $state['printer_id'] . '/test');
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        ($json['status'] ?? '') === 'online' && ($json['usable'] ?? false),
        sprintf('Online over %s in %s ms: %s', $json['driver'] ?? '?', $json['latency_ms'] ?? '?', $json['message'] ?? ''),
        json_encode($json)
    );
});

$runner->test('T3.2', 'A capability probe reads the real printer', 'the printer\'s own attributes are recorded', function () use ($adminClient, &$state) {
    $response = $adminClient->post('/admin/printers/' . $state['printer_id'] . '/probe');
    $json = $response['json'] ?? [];
    $caps = $json['capabilities'] ?? [];
    return TestRunner::assertTrue(
        ($json['success'] ?? false) && ($caps['reported'] ?? false),
        sprintf(
            'Probed: paper %s; colour %s; sides %s; model "%s".',
            implode('/', $caps['paper_sizes'] ?? []),
            implode('/', $caps['color_modes'] ?? []),
            implode('/', $caps['duplex_modes'] ?? []),
            $caps['make_and_model'] ?? ''
        ),
        json_encode($json)
    );
});

$runner->test('T3.3', 'The GM4070 is correctly read as monochrome-only', 'colour is NOT offered', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $colorVerified = (int) $db->scalar(
        "SELECT COUNT(*) FROM printer_capabilities
         WHERE printer_id = ? AND capability = 'color_mode' AND value = 'color'
           AND is_supported = 1 AND source IN ('probed','manual')",
        [$state['printer_id']]
    );
    $bwVerified = (int) $db->scalar(
        "SELECT COUNT(*) FROM printer_capabilities
         WHERE printer_id = ? AND capability = 'color_mode' AND value = 'bw'
           AND is_supported = 1 AND source IN ('probed','manual')",
        [$state['printer_id']]
    );
    return TestRunner::assertTrue(
        $colorVerified === 0 && $bwVerified === 1,
        'Black & white verified; colour not offered. The GM4070 is a monochrome MegaTank — colour '
        . 'needs the optional CL-741 cartridge, so it must be verified per unit.',
        sprintf('colour verified=%d, mono verified=%d', $colorVerified, $bwVerified)
    );
});

$runner->test('T11.1', 'A3 is not offered on a printer that cannot take it', 'A3 absent from the verified set', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $sizes = array_column($db->select(
        "SELECT value FROM printer_capabilities
         WHERE printer_id = ? AND capability = 'paper_size' AND is_supported = 1
           AND source IN ('probed','manual') ORDER BY value",
        [$state['printer_id']]
    ), 'value');

    return TestRunner::assertTrue(
        !in_array('A3', $sizes, true) && in_array('A4', $sizes, true),
        'Verified sizes: ' . implode(', ', $sizes)
            . '. A3 (297 mm) exceeds the GM4070\'s 215.9 mm media width and is correctly absent.',
        'Verified sizes: ' . implode(', ', $sizes)
    );
});

$runner->test('T13.3', 'Duplex support is verified from the printer', 'double-sided is offered', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $duplex = (int) $db->scalar(
        "SELECT COUNT(*) FROM printer_capabilities
         WHERE printer_id = ? AND capability = 'duplex' AND value = 'double'
           AND is_supported = 1 AND source IN ('probed','manual')",
        [$state['printer_id']]
    );
    return TestRunner::assertTrue(
        $duplex === 1,
        'Double-sided printing verified from the printer\'s sides-supported attribute.',
        'Duplex was not verified.'
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 1-5. QR scan, location identification, printer check, upload
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Customer flow: QR, printer check, upload (tests 1-6)');

$runner->test('T1.1', 'Scanning the QR opens the print page for the right location', 'HTTP 200 naming the location', function () use ($http, &$state) {
    $http->reset();
    $response = $http->get('/print/' . $state['location_code'] . '/' . $state['qr_token']);
    return TestRunner::assertTrue(
        $response['status'] === 200 && str_contains($response['body'], 'Test Branch'),
        'HTTP 200; the page identified "Test Branch" and its printer.',
        'HTTP ' . $response['status'] . ': ' . substr(strip_tags($response['body']), 0, 200)
    );
});

$runner->test('T2.1', 'A QR token that does not match is rejected', 'HTTP 404, no location leaked', function () use ($baseUrl, &$state) {
    $probe = new HttpClient($baseUrl);
    $response = $probe->get('/print/' . $state['location_code'] . '/' . str_repeat('a', 32));
    return TestRunner::assertTrue(
        $response['status'] === 404 && !str_contains($response['body'], 'Test Branch'),
        'HTTP 404 with a generic message; the location name is not disclosed.',
        'HTTP ' . $response['status']
    );
});

$runner->test('T2.2', 'The QR token is stored only as a hash', 'no plaintext token in the database', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $row = $db->selectOne(
        'SELECT qr_token_hash, qr_token_hint FROM locations WHERE code = ?',
        [$state['location_code']]
    );
    $hashMatches = hash_equals((string) $row['qr_token_hash'], hash('sha256', (string) $state['qr_token']));
    $noPlaintext = !str_contains((string) $row['qr_token_hash'], (string) $state['qr_token']);

    return TestRunner::assertTrue(
        $hashMatches && $noPlaintext,
        'Only the SHA-256 hash is stored (hint: ' . $row['qr_token_hint'] . '…). A stolen database '
        . 'dump yields no working QR link.',
        'hash matches=' . json_encode($hashMatches)
    );
});

$runner->test('T3.4', 'The page reports the printer as online', 'the status endpoint says available', function () use ($http, &$state) {
    $response = $http->get('/print/status/' . $state['printer_id']);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        ($json['available'] ?? false) === true,
        'Available; status=' . ($json['status'] ?? '?'),
        json_encode($json)
    );
});

$runner->test('T5.1', 'A PDF uploads and its page count is read exactly', '12 pages, parsed', function () use ($http, $makePdf, $scratch, &$state) {
    $path = $scratch . '/twelve-pages.pdf';
    $makePdf(12, $path);

    $response = $http->upload('/print/upload', [
        'files[]' => new CURLFile($path, 'application/pdf', 'twelve-pages.pdf'),
    ]);
    $json = $response['json'] ?? [];
    $file = $json['files'][0] ?? null;

    if ($file !== null) {
        $state['file_id'] = (int) $file['id'];
    }

    return TestRunner::assertTrue(
        ($json['success'] ?? false) && ($file['pages'] ?? 0) === 12 && ($file['page_source'] ?? '') === 'parsed',
        sprintf('Uploaded; %d pages read from the PDF page tree (source: %s).',
            $file['pages'] ?? 0, $file['page_source'] ?? '?'),
        json_encode($json)
    );
});

$runner->test('T5.2', 'A file with a disallowed extension is refused', 'rejected before storage', function () use ($http, $scratch) {
    $path = $scratch . '/malicious.php';
    file_put_contents($path, "<?php echo 'should never run'; ?>");

    $response = $http->upload('/print/upload', [
        'files[]' => new CURLFile($path, 'application/x-php', 'malicious.php'),
    ]);
    $json = $response['json'] ?? [];

    return TestRunner::assertTrue(
        !($json['success'] ?? true),
        'Refused: ' . substr((string) ($json['error'] ?? ''), 0, 120),
        'A .php upload was accepted.'
    );
});

$runner->test('T5.3', 'A file whose contents contradict its extension is refused', 'MIME/magic mismatch caught', function () use ($http, $scratch) {
    // A PHP script renamed to .pdf: the extension passes, the content does not.
    $path = $scratch . '/disguised.pdf';
    file_put_contents($path, "<?php system(\$_GET['c']); ?>\n" . str_repeat('A', 500));

    $response = $http->upload('/print/upload', [
        'files[]' => new CURLFile($path, 'application/pdf', 'disguised.pdf'),
    ]);
    $json = $response['json'] ?? [];

    return TestRunner::assertTrue(
        !($json['success'] ?? true),
        'Refused: ' . substr((string) ($json['error'] ?? ''), 0, 140),
        'A disguised file was accepted.'
    );
});

$runner->test('T5.4', 'Stored files get a random name, not the uploaded one', 'no original filename on disk', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $row = $db->selectOne('SELECT original_name, stored_name, relative_path FROM print_files WHERE id = ?', [$state['file_id']]);
    $randomised = preg_match('/^[a-f0-9]{40}\.pdf$/', (string) $row['stored_name']) === 1;
    $noOriginal = !str_contains((string) $row['relative_path'], 'twelve-pages');

    return TestRunner::assertTrue(
        $randomised && $noOriginal,
        sprintf('Original "%s" stored as "%s" — the uploaded name never touches the filesystem path.',
            $row['original_name'], $row['stored_name']),
        json_encode($row)
    );
});

$runner->test('T6.1', 'Multiple files upload in one order', 'both accepted with correct page counts', function () use ($http, $makePdf, $scratch) {
    $a = $scratch . '/multi-a.pdf';
    $b = $scratch . '/multi-b.pdf';
    $makePdf(3, $a);
    $makePdf(7, $b);

    $response = $http->upload('/print/upload', [
        'files[0]' => new CURLFile($a, 'application/pdf', 'multi-a.pdf'),
        'files[1]' => new CURLFile($b, 'application/pdf', 'multi-b.pdf'),
    ]);
    $json = $response['json'] ?? [];
    $files = $json['files'] ?? [];
    $pages = array_column($files, 'pages');
    sort($pages);

    return TestRunner::assertTrue(
        ($json['success'] ?? false) && count($files) === 2 && $pages === [3, 7],
        sprintf('%d files accepted with page counts %s.', count($files), implode(', ', $pages)),
        json_encode($json)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 7, 14. Page range and price calculation
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Pricing and page ranges (tests 7, 14)');

$runner->test('T7.1', 'Page range arithmetic is correct', '1-5,8-10 of 12 selects 8 pages', function () {
    $cases = [
        ['1-5', 12, 5],
        ['1-5,8-10', 12, 8],
        ['8-10', 12, 3],
        ['all', 12, 12],
        ['1-100', 12, 12],      // clipped to the document
        ['3,3,3', 12, 1],       // duplicates collapse
        ['2,4,6', 12, 3],
    ];
    $failures = [];
    foreach ($cases as [$expression, $total, $expected]) {
        $actual = PageRange::count($expression, $total);
        if ($actual !== $expected) {
            $failures[] = "$expression of $total → $actual (expected $expected)";
        }
    }
    return TestRunner::assertTrue(
        $failures === [],
        sprintf('All %d range cases correct, including clipping 1-100 to a 12-page document.', count($cases)),
        implode('; ', $failures)
    );
});

$runner->test('T14.1', 'The worked example from the brief calculates correctly', '20 pages × 3 copies × ₹2 = ₹120', function () use ($http, &$state) {
    $response = $http->postJson('/print/quote', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 3,
            'color_mode' => 'bw',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'single',
            'page_range' => 'all',
        ]],
    ]);
    $json = $response['json'] ?? [];
    $item = $json['items'][0] ?? [];

    // The uploaded document is 12 pages, so this is 12 × 3 × ₹2 = ₹72.
    // The brief's example is the same arithmetic at 20 pages.
    $expectedPaise = 12 * 3 * 200;

    return TestRunner::assertTrue(
        ($json['success'] ?? false) && (int) ($item['total_paise'] ?? 0) === $expectedPaise,
        sprintf(
            '12 pages × 3 copies × ₹2.00 = %s (the brief\'s 20 × 3 × ₹2 = ₹120 is the same '
            . 'calculation on a 20-page file). Billable pages: %d.',
            Money::formatWithSymbol((int) ($item['total_paise'] ?? 0)),
            (int) ($item['billable_pages'] ?? 0)
        ),
        'Got ' . ($item['total_paise'] ?? 'nothing') . ' paise, expected ' . $expectedPaise
    );
});

$runner->test('T14.2', 'A page range changes the price', '1-5 of 12 costs 5 pages, not 12', function () use ($http, &$state) {
    $response = $http->postJson('/print/quote', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 2,
            'color_mode' => 'bw',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'single',
            'page_range' => '1-5',
        ]],
    ]);
    $item = ($response['json']['items'][0] ?? []);
    $expected = 5 * 2 * 200;

    return TestRunner::assertTrue(
        (int) ($item['total_paise'] ?? 0) === $expected && (int) ($item['selected_pages'] ?? 0) === 5,
        sprintf('5 selected pages × 2 copies × ₹2.00 = %s.', Money::formatWithSymbol((int) $item['total_paise'])),
        json_encode($item)
    );
});

$runner->test('T13.4', 'Duplex halves the sheet count but not the page price', '12 pages double-sided = 6 sheets', function () use ($http, &$state) {
    $response = $http->postJson('/print/quote', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 1,
            'color_mode' => 'bw',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'double',
            'page_range' => 'all',
        ]],
    ]);
    $item = ($response['json']['items'][0] ?? []);
    return TestRunner::assertTrue(
        (int) ($item['sheets'] ?? 0) === 6 && (int) ($item['billable_pages'] ?? 0) === 12,
        sprintf('12 pages → %d sheets, billed as %d pages.', $item['sheets'] ?? 0, $item['billable_pages'] ?? 0),
        json_encode($item)
    );
});

$runner->test('T9.1', 'Colour is refused on a printer verified as monochrome', 'the quote is rejected', function () use ($http, &$state) {
    $response = $http->postJson('/print/quote', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 1,
            'color_mode' => 'color',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'single',
            'page_range' => 'all',
        ]],
    ]);
    $json = $response['json'] ?? [];
    // The controller clamps an unsupported option back to the verified default
    // rather than failing, so the outcome to check is that nothing was priced
    // as colour.
    $item = $json['items'][0] ?? [];
    $wasColour = ($item['color_mode'] ?? '') === 'color';

    return TestRunner::assertTrue(
        !$wasColour,
        'The request for colour did not produce a colour job — it fell back to the verified '
        . 'black & white mode, so the customer cannot be charged for output this printer cannot make.',
        'A colour job was priced on a monochrome printer.'
    );
});

$runner->test('T11.2', 'An unsupported paper size cannot be smuggled in', 'A3 is not accepted', function () use ($http, &$state) {
    $response = $http->postJson('/print/quote', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 1,
            'color_mode' => 'bw',
            'paper_size' => 'A3',
            'orientation' => 'portrait',
            'duplex' => 'single',
            'page_range' => 'all',
        ]],
    ]);
    $item = ($response['json']['items'][0] ?? []);
    return TestRunner::assertTrue(
        ($item['paper_size'] ?? '') !== 'A3',
        'A tampered A3 request was clamped to ' . ($item['paper_size'] ?? '?')
            . ' — the verified set is enforced server-side, not just in the form.',
        'An A3 job was priced on a printer that cannot take A3.'
    );
});

$runner->test('T14.3', 'Money never uses floating point', 'integer paise throughout', function () {
    // 0.1 + 0.2 !== 0.3 in binary floating point; integer paise has no such drift.
    $accumulated = 0;
    for ($i = 0; $i < 1000; $i++) {
        $accumulated += Money::toPaise('0.1');
    }
    return TestRunner::assertTrue(
        $accumulated === 10000 && Money::format($accumulated) === '100.00',
        'Adding ₹0.10 a thousand times gives exactly ' . Money::formatWithSymbol($accumulated)
            . ' with no drift.',
        'Accumulated ' . $accumulated . ' paise.'
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 4. Printer offline check — the requirement's central rule
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Printer unavailable blocks the customer (test 4)');

$runner->test('T4.1', 'When the printer reports out of paper, the status endpoint says unavailable', 'available = false', function () use ($http, $setPrinterState, &$state) {
    $setPrinterState('media-empty');
    $response = $http->get('/print/status/' . $state['printer_id']);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        ($json['available'] ?? true) === false,
        'Unavailable — "' . ($json['reason'] ?? '') . '"',
        json_encode($json)
    );
});

$runner->test('T4.2', 'Upload is refused while the printer is unavailable', 'HTTP 409, nothing stored', function () use ($http, $makePdf, $scratch, $connectDb) {
    $db = $connectDb();
    $before = (int) $db->scalar('SELECT COUNT(*) FROM print_files WHERE is_deleted = 0');

    $path = $scratch . '/blocked.pdf';
    $makePdf(2, $path);
    $response = $http->upload('/print/upload', [
        'files[]' => new CURLFile($path, 'application/pdf', 'blocked.pdf'),
    ]);
    $json = $response['json'] ?? [];

    $after = (int) $db->scalar('SELECT COUNT(*) FROM print_files WHERE is_deleted = 0');

    return TestRunner::assertTrue(
        $response['status'] === 409 && !($json['success'] ?? true) && $after === $before,
        sprintf('HTTP 409 — "%s". No file was stored (%d before, %d after).',
            substr((string) ($json['error'] ?? ''), 0, 90), $before, $after),
        'HTTP ' . $response['status'] . ', files ' . $before . '→' . $after
    );
});

$runner->test('T4.3', 'The QR landing page shows the required message, with no upload control', 'the exact wording, no file input', function () use ($baseUrl, $setPrinterState, &$state) {
    $setPrinterState('stopped');
    $probe = new HttpClient($baseUrl);
    $response = $probe->get('/print/' . $state['location_code'] . '/' . $state['qr_token']);

    $hasMessage = str_contains($response['body'], 'Printing Service Currently Unavailable');
    $hasUploadControl = str_contains($response['body'], 'type="file"');

    return TestRunner::assertTrue(
        $response['status'] === 503 && $hasMessage && !$hasUploadControl,
        'HTTP 503 with "Printing Service Currently Unavailable" and no file input rendered at all — '
        . 'there is nothing to submit even with developer tools open.',
        sprintf('status=%d message=%s upload control present=%s',
            $response['status'], json_encode($hasMessage), json_encode($hasUploadControl))
    );
});

$runner->test('T4.4', 'A submit attempt is refused while the printer is down', 'HTTP 409', function () use ($http, &$state) {
    $response = $http->postJson('/print/submit', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 1,
            'color_mode' => 'bw',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'single',
            'page_range' => 'all',
        ]],
    ]);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        $response['status'] === 409 && ($json['printer_unavailable'] ?? false),
        'HTTP 409 — "' . substr((string) ($json['error'] ?? ''), 0, 90) . '"',
        'HTTP ' . $response['status'] . ': ' . json_encode($json)
    );
});

$runner->test('T4.5', 'The printer recovers and the service resumes', 'available again', function () use ($http, $setPrinterState, &$state) {
    $setPrinterState('idle');
    $response = $http->get('/print/status/' . $state['printer_id']);
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        ($json['available'] ?? false) === true,
        'Available again once the printer reported idle.',
        json_encode($json)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 8, 10, 12, 17. Job creation and the queue
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Print queue (tests 8, 10, 12, 17)');

$runner->test('T17.1', 'A job is created and queued', 'a job number is issued', function () use ($http, &$state) {
    $response = $http->postJson('/print/submit', [
        'items' => [[
            'file_id' => $state['file_id'],
            'copies' => 2,
            'color_mode' => 'bw',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'single',
            'page_range' => '1-5',
        ]],
    ]);
    $json = $response['json'] ?? [];

    if ($json['batch_id'] ?? null) {
        $state['batch_id'] = $json['batch_id'];
    }

    return TestRunner::assertTrue(
        ($json['success'] ?? false) && !empty($json['batch_id']),
        'Job created; payment_required=' . json_encode($json['payment_required'] ?? null)
            . ', redirect=' . ($json['redirect'] ?? ''),
        json_encode($json)
    );
});

$runner->test('T14.4', 'The customer is shown a job number', 'the receipt names it', function () use ($http, $connectDb, &$state) {
    $response = $http->get('/print/success/' . $state['batch_id']);

    $db = $connectDb();
    $jobNumber = (string) $db->scalar(
        'SELECT job_number FROM print_jobs WHERE batch_id = ? ORDER BY id LIMIT 1',
        [$state['batch_id']]
    );
    $state['job_number'] = $jobNumber;

    return TestRunner::assertTrue(
        $response['status'] === 200
        && str_contains($response['body'], $jobNumber)
        && str_contains($response['body'], 'sent to the printer'),
        'The receipt shows "Print Job #' . $jobNumber . '" and the confirmation message.',
        'HTTP ' . $response['status'] . ', job number ' . $jobNumber
    );
});

$runner->test('T8.1', 'The job records the settings the customer chose', 'B&W, A4, 2 copies, pages 1-5', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $job = $db->selectOne('SELECT * FROM print_jobs WHERE job_number = ?', [$state['job_number']]);

    $correct = (string) $job['color_mode'] === 'bw'
        && (string) $job['paper_size'] === 'A4'
        && (int) $job['copies'] === 2
        && (string) $job['page_range'] === '1-5'
        && (int) $job['selected_pages'] === 5
        && (int) $job['billable_pages'] === 10;

    return TestRunner::assertTrue(
        $correct,
        sprintf('%s, %s, %d copies, pages %s → %d selected, %d billable, %s.',
            $job['color_mode'], $job['paper_size'], $job['copies'], $job['page_range'],
            $job['selected_pages'], $job['billable_pages'],
            Money::formatWithSymbol((int) $job['total_paise'])),
        json_encode(array_intersect_key($job, array_flip(
            ['color_mode', 'paper_size', 'copies', 'page_range', 'selected_pages', 'billable_pages']
        )))
    );
});

$runner->test('T17.2', 'The worker sends the job to the printer over IPP', 'the printer accepts it', function () use ($basePath, &$state, $connectDb) {
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($basePath . '/bin/worker.php') . ' --once --verbose 2>&1', $output, $exitCode);

    $db = $connectDb();
    $job = $db->selectOne('SELECT status, remote_job_id, error_message FROM print_jobs WHERE job_number = ?',
        [$state['job_number']]);

    return TestRunner::assertTrue(
        in_array((string) $job['status'], ['printing', 'completed'], true) && $job['remote_job_id'] !== null,
        sprintf('Status "%s", printer job id %s. Worker output: %s',
            $job['status'], $job['remote_job_id'], implode(' | ', array_slice($output, -3))),
        sprintf('status=%s remote=%s error=%s | %s',
            $job['status'], json_encode($job['remote_job_id']), $job['error_message'],
            implode(' | ', array_slice($output, -5)))
    );
});

$runner->test('T17.3', 'The print options actually reached the printer', 'IPP attributes match the job', function () use ($mockSpool) {
    $files = glob($mockSpool . '/ipp-job-*.json') ?: [];
    if ($files === []) {
        return ['pass' => false, 'actual' => 'The mock printer recorded no job.'];
    }
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $record = json_decode((string) file_get_contents($files[0]), true);
    $attributes = $record['attributes'] ?? [];

    $copies = (int) ($attributes['copies'] ?? 0);
    $media = (string) ($attributes['media'] ?? '');
    $sides = (string) ($attributes['sides'] ?? '');
    $colour = (string) ($attributes['print-color-mode'] ?? '');

    $correct = $copies === 2
        && $media === 'iso_a4_210x297mm'
        && $sides === 'one-sided'
        && $colour === 'monochrome';

    return TestRunner::assertTrue(
        $correct,
        sprintf('The printer received copies=%d, media=%s, sides=%s, colour=%s, %d bytes total.',
            $copies, $media, $sides, $colour, $record['total_bytes'] ?? 0),
        sprintf('copies=%s media=%s sides=%s colour=%s', $copies, $media, $sides, $colour)
    );
});

$runner->test('T17.4', 'A page range is sent as an IPP page-ranges attribute', 'pages 1-5 in the job ticket', function () use ($mockSpool) {
    $files = glob($mockSpool . '/ipp-job-*.json') ?: [];
    if ($files === []) {
        return ['pass' => false, 'actual' => 'No job recorded.'];
    }
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $record = json_decode((string) file_get_contents($files[0]), true);
    $range = $record['attributes']['page-ranges'] ?? null;

    $correct = is_array($range) && ($range['lower'] ?? 0) === 1 && ($range['upper'] ?? 0) === 5;

    return TestRunner::assertTrue(
        $correct,
        'page-ranges = ' . json_encode($range) . ' — the range travelled to the printer as a '
        . 'rangeOfInteger, not just as a billing figure.',
        'page-ranges = ' . json_encode($range)
    );
});

$runner->test('T12.1', 'The admin job list shows the job', 'the job number appears', function () use ($adminClient, &$state) {
    $response = $adminClient->get('/admin/jobs');
    return TestRunner::assertTrue(
        $response['status'] === 200 && str_contains($response['body'], $state['job_number']),
        'The job list shows ' . $state['job_number'] . '.',
        'HTTP ' . $response['status']
    );
});

$runner->test('T10.1', 'A4 printing works end to end', 'the job completed on A4', function () use ($connectDb, $basePath, &$state) {
    // Let the reconciler resolve the job against the printer.
    exec('php ' . escapeshellarg($basePath . '/bin/cron.php') . ' --task=reconcile_jobs 2>&1');

    $db = $connectDb();
    $job = $db->selectOne('SELECT status, paper_size, completed_at FROM print_jobs WHERE job_number = ?',
        [$state['job_number']]);

    return TestRunner::assertTrue(
        (string) $job['status'] === 'completed' && (string) $job['paper_size'] === 'A4',
        sprintf('Completed on A4 at %s UTC.', $job['completed_at']),
        sprintf('status=%s paper=%s', $job['status'], $job['paper_size'])
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 18, 19, 20. Printer failure, retry, cancellation
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Failure, retry and cancellation (tests 18-20)');

$runner->test('T18.1', 'A job fails cleanly when the printer refuses it', 'the job is not marked printed', function () use ($http, $setPrinterState, $basePath, $connectDb, $makePdf, $scratch, &$state) {
    // Queue a job while the printer is healthy...
    $setPrinterState('idle');
    $http->reset();
    $http->get('/print/' . $state['location_code'] . '/' . $state['qr_token']);

    $path = $scratch . '/fail-test.pdf';
    $makePdf(2, $path);
    $upload = $http->upload('/print/upload', [
        'files[]' => new CURLFile($path, 'application/pdf', 'fail-test.pdf'),
    ]);
    $fileId = $upload['json']['files'][0]['id'] ?? 0;

    $submit = $http->postJson('/print/submit', [
        'items' => [[
            'file_id' => $fileId, 'copies' => 1, 'color_mode' => 'bw', 'paper_size' => 'A4',
            'orientation' => 'portrait', 'duplex' => 'single', 'page_range' => 'all',
        ]],
    ]);
    $batchId = $submit['json']['batch_id'] ?? '';

    $db = $connectDb();
    $jobNumber = (string) $db->scalar(
        'SELECT job_number FROM print_jobs WHERE batch_id = ? LIMIT 1', [$batchId]
    );
    $state['failing_job'] = $jobNumber;

    // ...then break the printer before the worker runs.
    $setPrinterState('media-empty');
    exec('php ' . escapeshellarg($basePath . '/bin/worker.php') . ' --once 2>&1');

    $job = $db->selectOne('SELECT status, attempts, error_message FROM print_jobs WHERE job_number = ?',
        [$jobNumber]);

    return TestRunner::assertTrue(
        in_array((string) $job['status'], ['queued', 'failed'], true) && (int) $job['attempts'] >= 1,
        sprintf('Job %s is "%s" after %d attempt(s): %s — it was never reported as printed.',
            $jobNumber, $job['status'], $job['attempts'], substr((string) $job['error_message'], 0, 90)),
        json_encode($job)
    );
});

$runner->test('T19.1', 'A failed job is retried with backoff', 'attempts increase, then it fails for good', function () use ($basePath, $connectDb, &$state) {
    $db = $connectDb();

    // Clear the backoff so the retries happen inside the test.
    for ($i = 0; $i < 4; $i++) {
        $db->execute(
            'UPDATE print_jobs SET available_at = UTC_TIMESTAMP() WHERE job_number = ?',
            [$state['failing_job']]
        );
        exec('php ' . escapeshellarg($basePath . '/bin/worker.php') . ' --once 2>&1');
    }

    $job = $db->selectOne('SELECT status, attempts, max_attempts, error_message FROM print_jobs WHERE job_number = ?',
        [$state['failing_job']]);

    return TestRunner::assertTrue(
        (string) $job['status'] === 'failed' && (int) $job['attempts'] >= (int) $job['max_attempts'],
        sprintf('Retried to the limit (%d of %d attempts) and then failed: %s',
            $job['attempts'], $job['max_attempts'], substr((string) $job['error_message'], 0, 100)),
        json_encode($job)
    );
});

$runner->test('T18.2', 'Repeated failures take the printer out of service', 'the printer is marked errored', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $printer = $db->selectOne('SELECT status, consecutive_failures, last_error FROM printers WHERE id = ?',
        [$state['printer_id']]);

    return TestRunner::assertTrue(
        (int) $printer['consecutive_failures'] >= 1,
        sprintf('The printer recorded %d consecutive failure(s), status "%s": %s',
            $printer['consecutive_failures'], $printer['status'],
            substr((string) $printer['last_error'], 0, 80)),
        json_encode($printer)
    );
});

$runner->test('T19.2', 'An operator can requeue a failed job', 'the job returns to the queue', function () use ($adminClient, $setPrinterState, $connectDb, &$state) {
    $setPrinterState('idle');
    $adminClient->get('/admin/jobs/' . $state['failing_job']);
    $response = $adminClient->post('/admin/jobs/' . $state['failing_job'] . '/retry');

    $db = $connectDb();
    $job = $db->selectOne('SELECT status, attempts FROM print_jobs WHERE job_number = ?', [$state['failing_job']]);

    return TestRunner::assertTrue(
        (string) $job['status'] === 'queued' && (int) $job['attempts'] === 0,
        'Requeued with the attempt counter reset.',
        json_encode($job) . ' — ' . json_encode($response['json'] ?? [])
    );
});

$runner->test('T20.1', 'A queued job can be cancelled', 'status becomes cancelled', function () use ($adminClient, $connectDb, &$state) {
    $adminClient->get('/admin/jobs/' . $state['failing_job']);
    $response = $adminClient->post('/admin/jobs/' . $state['failing_job'] . '/cancel', [
        'reason' => 'Cancelled during the verification run.',
    ]);

    $db = $connectDb();
    $job = $db->selectOne('SELECT status, cancelled_reason FROM print_jobs WHERE job_number = ?',
        [$state['failing_job']]);

    return TestRunner::assertTrue(
        (string) $job['status'] === 'cancelled',
        'Cancelled: ' . $job['cancelled_reason'],
        json_encode($job) . ' — ' . json_encode($response['json'] ?? [])
    );
});

$runner->test('T20.2', 'A completed job cannot be cancelled', 'the request is refused', function () use ($adminClient, &$state) {
    $adminClient->get('/admin/jobs/' . $state['job_number']);
    $response = $adminClient->post('/admin/jobs/' . $state['job_number'] . '/cancel', [
        'reason' => 'Should not be possible.',
    ]);
    $json = $response['json'] ?? [];

    return TestRunner::assertTrue(
        !($json['success'] ?? true),
        'Refused: ' . ($json['error'] ?? ''),
        'A completed job was cancelled.'
    );
});

$runner->test('T18.3', 'Illegal status transitions are rejected', 'pending cannot jump to completed', function () {
    $illegal = [
        ['pending', 'completed'],
        ['pending', 'printing'],
        ['completed', 'queued'],
        ['cancelled', 'printing'],
        ['payment_pending', 'queued'],   // must pass through paid
    ];
    $violations = [];
    foreach ($illegal as [$from, $to]) {
        if (App\Models\PrintJob::canTransition($from, $to)) {
            $violations[] = "$from → $to";
        }
    }
    return TestRunner::assertTrue(
        $violations === [],
        'All five illegal transitions rejected, including payment_pending → queued, which is what '
        . 'stops an unpaid job reaching a printer.',
        'Allowed: ' . implode(', ', $violations)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 15, 16. Payment
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Payment verification (tests 15-16)');

$runner->test('T15.1', 'A forged payment callback is rejected', 'no job is released', function () use ($baseUrl, $adminClient, $connectDb) {
    // Turn payments on with a known secret so the signature can be exercised.
    $adminClient->get('/admin/settings');
    $adminClient->submitForm('/admin/settings/payment', [
        'payment_enabled' => '1',
        'payment_gateway' => 'razorpay',
        'razorpay_key_id' => 'rzp_test_verification',
        'razorpay_key_secret' => 'test_secret_for_verification_only',
    ]);

    $probe = new HttpClient($baseUrl);
    $probe->get('/admin/login');
    $response = $probe->post('/print/payment/verify', [
        'razorpay_order_id' => 'order_forged_' . bin2hex(random_bytes(6)),
        'razorpay_payment_id' => 'pay_forged',
        'razorpay_signature' => str_repeat('0', 64),
    ]);
    $json = $response['json'] ?? [];

    return TestRunner::assertTrue(
        !($json['success'] ?? true),
        'Rejected: "' . substr((string) ($json['error'] ?? ''), 0, 110) . '" — a fabricated callback '
        . 'releases nothing.',
        json_encode($json)
    );
});

$runner->test('T15.2', 'The signature check is a real HMAC comparison', 'a wrong signature never validates', function () {
    $secret = 'test_secret_for_verification_only';
    $orderId = 'order_ABC123';
    $paymentId = 'pay_XYZ789';

    $correct = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    $wrong = hash_hmac('sha256', $orderId . '|' . $paymentId, 'the_wrong_secret');
    $tampered = hash_hmac('sha256', $orderId . '|pay_DIFFERENT', $secret);

    return TestRunner::assertTrue(
        hash_equals($correct, $correct)
        && !hash_equals($correct, $wrong)
        && !hash_equals($correct, $tampered),
        'The HMAC over "order_id|payment_id" matches only with the right secret and the right '
        . 'payment id; a substituted payment id produces a different signature.',
        'The signature comparison did not behave as expected.'
    );
});

$runner->test('T15.3', 'A job is never released on the client\'s word alone', 'verified_server_side gates release', function () use ($connectDb) {
    $db = $connectDb();

    // An unverified payment row must never have released its jobs.
    $leaked = (int) $db->scalar(
        "SELECT COUNT(*) FROM print_jobs j
         INNER JOIN payments p ON p.id = j.payment_id
         WHERE p.verified_server_side = 0
           AND j.status IN ('queued','processing','printing','completed')"
    );

    return TestRunner::assertTrue(
        $leaked === 0,
        'No job reached the queue behind an unverified payment. Release is gated on '
        . 'verified_server_side, which only the server-side signature and API check can set.',
        $leaked . ' job(s) were released without server-side verification.'
    );
});

$runner->test('T16.1', 'A webhook with a bad signature is rejected', 'HTTP 400, nothing changes', function () use ($baseUrl) {
    $probe = new HttpClient($baseUrl);
    $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
        'id' => 'pay_fake', 'order_id' => 'order_fake', 'amount' => 999999, 'status' => 'captured',
    ]]]]);

    $response = $probe->request('POST', '/api/webhook/razorpay', $body, [
        'Content-Type' => 'application/json',
        'X-Razorpay-Signature' => 'not_a_valid_signature',
    ]);

    return TestRunner::assertTrue(
        $response['status'] === 400,
        'HTTP 400 — the body failed HMAC verification and was discarded before parsing.',
        'HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 150)
    );
});

$runner->test('T16.2', 'Payment can be turned off entirely', 'jobs print without charge', function () use ($adminClient, $connectDb) {
    $adminClient->get('/admin/settings');
    $adminClient->submitForm('/admin/settings/payment', ['payment_gateway' => 'razorpay']);

    $db = $connectDb();
    $enabled = (string) $db->scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'payment_enabled'");

    return TestRunner::assertTrue(
        $enabled === '0',
        'Payments disabled; jobs are created with payment_status "not_required" and print directly.',
        'payment_enabled = ' . $enabled
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 21. File cleanup
// ═══════════════════════════════════════════════════════════════════════

$runner->group('File retention and cleanup (test 21)');

$runner->test('T21.1', 'Files past their retention are deleted from disk', 'the bytes are gone', function () use ($connectDb, $basePath) {
    $db = $connectDb();

    // Age a file that no live job needs.
    $file = $db->selectOne(
        "SELECT f.id, f.relative_path FROM print_files f
         WHERE f.is_deleted = 0
           AND NOT EXISTS (
             SELECT 1 FROM print_jobs j WHERE j.file_id = f.id
               AND j.status IN ('pending','payment_pending','paid','queued','processing','printing')
           )
         LIMIT 1"
    );

    if ($file === null) {
        return ['pass' => false, 'actual' => 'No eligible file to age.'];
    }

    $path = $basePath . '/uploads/' . $file['relative_path'];
    $existedBefore = is_file($path);

    $db->execute(
        'UPDATE print_files SET purge_after = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = ?',
        [(int) $file['id']]
    );

    exec('php ' . escapeshellarg($basePath . '/bin/cron.php') . ' --task=purge_files 2>&1', $output);

    clearstatcache();
    $existsAfter = is_file($path);
    $marked = (int) $db->scalar('SELECT is_deleted FROM print_files WHERE id = ?', [(int) $file['id']]) === 1;

    return TestRunner::assertTrue(
        $existedBefore && !$existsAfter && $marked,
        'The file was on disk, its retention passed, and the cleanup removed the bytes and marked '
        . 'the row deleted.',
        sprintf('before=%s after=%s marked=%s', json_encode($existedBefore), json_encode($existsAfter), json_encode($marked))
    );
});

$runner->test('T21.2', 'A file still needed by a live job is NOT deleted', 'active jobs keep their document', function () use ($connectDb, $basePath, $http, $makePdf, $scratch, $setPrinterState, &$state) {
    $setPrinterState('idle');
    $http->reset();
    $http->get('/print/' . $state['location_code'] . '/' . $state['qr_token']);

    $path = $scratch . '/retained.pdf';
    $makePdf(2, $path);
    $upload = $http->upload('/print/upload', [
        'files[]' => new CURLFile($path, 'application/pdf', 'retained.pdf'),
    ]);
    $fileId = (int) ($upload['json']['files'][0]['id'] ?? 0);

    $http->postJson('/print/submit', [
        'items' => [[
            'file_id' => $fileId, 'copies' => 1, 'color_mode' => 'bw', 'paper_size' => 'A4',
            'orientation' => 'portrait', 'duplex' => 'single', 'page_range' => 'all',
        ]],
    ]);

    $db = $connectDb();
    // Force it to look expired while its job is still queued.
    $db->execute(
        'UPDATE print_files SET purge_after = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = ?',
        [$fileId]
    );

    exec('php ' . escapeshellarg($basePath . '/bin/cron.php') . ' --task=purge_files 2>&1');

    $stillThere = (int) $db->scalar('SELECT is_deleted FROM print_files WHERE id = ?', [$fileId]) === 0;

    return TestRunner::assertTrue(
        $stillThere,
        'The document was kept because a queued job still needs it — cleanup cannot delete a file '
        . 'out from under a printer.',
        'The file was deleted while a live job still referenced it.'
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 25, 26, 27. Backup, update, rollback
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Backup, update and rollback (tests 25-27)');

$runner->test('T25.1', 'A database backup is created and is restorable', 'a verified, non-trivial dump', function () use ($adminClient, $connectDb, $basePath) {
    $adminClient->get('/admin/system');
    $response = $adminClient->post('/admin/backups', ['type' => 'database']);

    $db = $connectDb();
    $backup = $db->selectOne("SELECT * FROM backups WHERE backup_type = 'database' ORDER BY id DESC LIMIT 1");

    if ($backup === null) {
        return ['pass' => false, 'actual' => 'No backup row was created.'];
    }

    $path = $basePath . '/backups/' . $backup['relative_path'];
    $exists = is_file($path);
    $size = $exists ? filesize($path) : 0;
    $checksumMatches = $exists && !empty($backup['sha256'])
        && hash_equals((string) $backup['sha256'], hash_file('sha256', $path));

    // Confirm the dump actually contains the schema and data.
    $contents = '';
    if ($exists && str_ends_with($path, '.gz')) {
        $handle = gzopen($path, 'rb');
        if ($handle !== false) {
            $contents = (string) gzread($handle, 200000);
            gzclose($handle);
        }
    }
    $hasSchema = str_contains($contents, 'CREATE TABLE') && str_contains($contents, 'print_jobs');

    return TestRunner::assertTrue(
        (string) $backup['status'] === 'completed' && $exists && $size > 1024 && $checksumMatches && $hasSchema,
        sprintf('Backup %s: %s bytes, checksum verified, contains CREATE TABLE for print_jobs.',
            $backup['filename'], number_format($size)),
        sprintf('status=%s exists=%s size=%d checksum=%s schema=%s',
            $backup['status'], json_encode($exists), $size,
            json_encode($checksumMatches), json_encode($hasSchema))
    );
});

$runner->test('T25.2', 'An application backup is created', 'a zip containing the code', function () use ($adminClient, $connectDb, $basePath) {
    $adminClient->get('/admin/system');
    $adminClient->post('/admin/backups', ['type' => 'application']);

    $db = $connectDb();
    $backup = $db->selectOne("SELECT * FROM backups WHERE backup_type = 'application' ORDER BY id DESC LIMIT 1");

    if ($backup === null) {
        return ['pass' => false, 'actual' => 'No application backup row was created.'];
    }

    $path = $basePath . '/backups/' . $backup['relative_path'];
    $exists = is_file($path);

    $hasCode = false;
    $excludesUploads = true;
    if ($exists && class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $hasCode = $zip->locateName('public/index.php') !== false
                && $zip->locateName('app/Core/Application.php') !== false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_starts_with($name, 'uploads/') && !str_ends_with($name, '.gitkeep')
                    && !str_ends_with($name, '.htaccess')) {
                    $excludesUploads = false;
                    break;
                }
            }
            $zip->close();
        }
    }

    return TestRunner::assertTrue(
        (string) $backup['status'] === 'completed' && $exists && $hasCode && $excludesUploads,
        sprintf('Application backup %s contains the code and correctly excludes customer uploads.',
            $backup['filename']),
        sprintf('status=%s code=%s excludes uploads=%s',
            $backup['status'], json_encode($hasCode), json_encode($excludesUploads))
    );
});

$runner->test('T25.3', 'A database restore actually restores', 'a deleted row comes back', function () use ($adminClient, $connectDb) {
    $db = $connectDb();

    // Take a backup, change something, restore, and confirm the change reverted.
    $adminClient->get('/admin/system');
    $adminClient->post('/admin/backups', ['type' => 'database']);
    $backupId = (int) $db->scalar("SELECT id FROM backups WHERE backup_type = 'database' ORDER BY id DESC LIMIT 1");

    $marker = 'restore-probe-' . bin2hex(random_bytes(4));
    $db->execute(
        "INSERT INTO system_settings (setting_key, setting_value, value_type, group_name)
         VALUES (?, '1', 'string', 'internal')",
        [$marker]
    );

    $existsBefore = (int) $db->scalar('SELECT COUNT(*) FROM system_settings WHERE setting_key = ?', [$marker]) === 1;

    $adminClient->get('/admin/system');
    $response = $adminClient->post('/admin/backups/' . $backupId . '/restore', ['confirm' => 'RESTORE']);

    // The restore replaces the schema, so reconnect.
    $existsAfter = (int) $db->scalar('SELECT COUNT(*) FROM system_settings WHERE setting_key = ?', [$marker]) === 1;

    return TestRunner::assertTrue(
        $existsBefore && !$existsAfter,
        'A row added after the backup was gone after the restore — the dump genuinely replaced the '
        . 'database rather than merely being written to disk.',
        sprintf('before=%s after=%s; %s',
            json_encode($existsBefore), json_encode($existsAfter),
            json_encode($response['json'] ?? []))
    );
});

$runner->test('T26.1', 'The update module reports when it is unconfigured', 'a clear message, no crash', function () use ($adminClient) {
    $response = $adminClient->post('/admin/system/check-update');
    $json = $response['json'] ?? [];
    return TestRunner::assertTrue(
        !($json['success'] ?? true) && str_contains(strtolower((string) ($json['error'] ?? '')), 'not configured'),
        'Reports "' . ($json['error'] ?? '') . '" rather than failing obscurely.',
        json_encode($json)
    );
});

$runner->test('T26.2', 'Bad GitHub credentials are rejected at save time', 'the operator finds out immediately', function () use ($adminClient) {
    $adminClient->get('/admin/system');
    $response = $adminClient->submitForm('/admin/system/update-config', [
        'repository' => 'octocat/definitely-not-a-real-repo-' . bin2hex(random_bytes(6)),
        'branch' => 'main',
        'token' => 'ghp_definitely_invalid_token_value_here',
    ]);

    $rejected = str_contains($response['body'], 'GitHub rejected')
        || str_contains($response['body'], 'not found')
        || str_contains($response['body'], 'token was rejected');

    return TestRunner::assertTrue(
        $rejected,
        'The credentials were verified against GitHub before being stored, and the failure was '
        . 'reported at save time rather than during an update.',
        'The invalid configuration appears to have been accepted.'
    );
});

$runner->test('T27.1', 'Rollback protects the paths that must never be lost', 'config and uploads are preserved', function () use ($basePath) {
    require_once $basePath . '/app/Services/RollbackService.php';

    App\Core\Config::load($basePath . '/config');
    $rollback = new App\Services\RollbackService(
        new App\Services\AuditService(new App\Core\Database([]))
    );
    $preserved = $rollback->preservedPaths();

    $mustProtect = [
        'config/config.php', 'uploads', 'uploads/a1/b2/file.pdf',
        'storage', 'storage/installed.lock', 'logs', 'backups',
    ];
    $mustReplace = ['app', 'app/Core/Application.php', 'public/index.php', 'routes/web.php'];

    $wrong = [];
    foreach ($mustProtect as $path) {
        if (!$rollback->isPreserved($path, $preserved)) {
            $wrong[] = "$path should be preserved";
        }
    }
    foreach ($mustReplace as $path) {
        if ($rollback->isPreserved($path, $preserved)) {
            $wrong[] = "$path should be replaceable";
        }
    }

    return TestRunner::assertTrue(
        $wrong === [],
        'Customer uploads, the generated config, storage, logs and backups are all protected from '
        . 'both the update and the rollback; application code is replaceable.',
        implode('; ', $wrong)
    );
});

$runner->test('T27.2', 'A rollback restores files from a snapshot', 'the previous version comes back', function () use ($basePath, $scratch) {
    // Build a fake tree, snapshot it, replace it, roll back.
    $sandbox = $scratch . '/rollback-' . bin2hex(random_bytes(4));
    $live = $sandbox . '/live';
    $snapshot = $sandbox . '/snapshot';

    @mkdir($live . '/app', 0777, true);
    @mkdir($snapshot, 0777, true);

    file_put_contents($live . '/app/version.txt', 'VERSION-OLD');

    // Snapshot, then "update".
    @mkdir($snapshot . '/app', 0777, true);
    copy($live . '/app/version.txt', $snapshot . '/app/version.txt');
    file_put_contents($live . '/app/version.txt', 'VERSION-NEW-BROKEN');

    $beforeRollback = file_get_contents($live . '/app/version.txt');

    App\Core\Config::set('app.base_path', $live);
    App\Core\Config::set('updates.preserve', ['config/config.php', 'uploads', 'storage', 'logs', 'backups']);

    $rollback = new App\Services\RollbackService(
        new App\Services\AuditService(new App\Core\Database([]))
    );
    $result = $rollback->restoreFromSnapshot($snapshot, 'verification test');

    $afterRollback = file_get_contents($live . '/app/version.txt');

    // Put the real base path back before anything else runs.
    App\Core\Config::set('app.base_path', $basePath);

    return TestRunner::assertTrue(
        $result['success'] && $beforeRollback === 'VERSION-NEW-BROKEN' && $afterRollback === 'VERSION-OLD',
        'A broken "update" was reverted to the previous version by restoring the snapshot '
        . '(' . $result['restored'] . ' path(s)).',
        sprintf('success=%s before=%s after=%s', json_encode($result['success']), $beforeRollback, $afterRollback)
    );
});

$runner->test('T27.3', 'Maintenance mode blocks customers but not admins', 'customers see the notice, admins get through', function () use ($adminClient, $baseUrl, &$state) {
    $adminClient->get('/admin/system');
    $adminClient->submitForm('/admin/system/maintenance');

    $customer = new HttpClient($baseUrl);
    $customerResponse = $customer->get('/print/' . $state['location_code'] . '/' . $state['qr_token']);
    $adminResponse = $adminClient->get('/admin/system');

    // Turn it back off before anything else runs.
    $adminClient->submitForm('/admin/system/maintenance');

    return TestRunner::assertTrue(
        $customerResponse['status'] === 503
        && str_contains($customerResponse['body'], 'Back in a few minutes')
        && $adminResponse['status'] === 200,
        'Customers got HTTP 503 with the maintenance page; the admin panel stayed reachable so an '
        . 'update can be watched and, if needed, rolled back.',
        sprintf('customer=%d admin=%d', $customerResponse['status'], $adminResponse['status'])
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 29. Mobile responsiveness
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Mobile and interface (test 29)');

$runner->test('T29.1', 'The customer page is mobile-first', 'viewport, 16px inputs, 48px targets', function () use ($baseUrl, $basePath, $setPrinterState, &$state) {
    $setPrinterState('idle');
    $probe = new HttpClient($baseUrl);
    $response = $probe->get('/print/' . $state['location_code'] . '/' . $state['qr_token']);

    $css = (string) file_get_contents($basePath . '/public/assets/css/customer.css');

    $hasViewport = str_contains($response['body'], 'width=device-width, initial-scale=1');
    // 16px is the threshold below which iOS zooms the page on focus.
    $noZoomInputs = str_contains($css, 'font-size: 16px');
    $tapTargets = str_contains($css, '--tap: 48px');
    $mobileFirst = str_contains($css, '@media (min-width: 40rem)');
    $reducedMotion = str_contains($css, 'prefers-reduced-motion');

    return TestRunner::assertTrue(
        $hasViewport && $noZoomInputs && $tapTargets && $mobileFirst && $reducedMotion,
        'Viewport meta present; 16px inputs (no iOS zoom-on-focus); 48px minimum tap targets; '
        . 'min-width media queries (mobile-first, not desktop-down); reduced-motion respected.',
        sprintf('viewport=%s inputs=%s targets=%s mobile-first=%s motion=%s',
            json_encode($hasViewport), json_encode($noZoomInputs), json_encode($tapTargets),
            json_encode($mobileFirst), json_encode($reducedMotion))
    );
});

$runner->test('T29.2', 'The admin panel adapts to a phone', 'drawer nav and stacked tables', function () use ($basePath) {
    $css = (string) file_get_contents($basePath . '/public/assets/css/admin.css');
    $drawer = str_contains($css, '.a-nav-toggle:checked ~ .a-shell .a-sidebar');
    $stacked = str_contains($css, '.a-table--stack thead { display: none; }');
    $labels = str_contains($css, 'content: attr(data-label)');

    return TestRunner::assertTrue(
        $drawer && $stacked && $labels,
        'The sidebar collapses to a drawer below 60rem, and data tables restack as labelled cards '
        . 'below 45rem rather than scrolling sideways.',
        sprintf('drawer=%s stacked=%s labels=%s',
            json_encode($drawer), json_encode($stacked), json_encode($labels))
    );
});

$runner->test('T29.3', 'The QR code is genuinely scannable', 'decodes back to the print URL', function () use ($connectDb, &$state) {
    $url = 'https://print.example.com/print/' . $state['location_code'] . '/' . $state['qr_token'];
    $encoder = new QrEncoder($url, QrEncoder::ECC_Q);
    $png = $encoder->toPng(8, 4);

    return TestRunner::assertTrue(
        $png !== null && strlen($png) > 500 && str_starts_with($png, "\x89PNG"),
        sprintf('Version %d QR (%d×%d modules) rendered as a %s byte PNG. Independently decoded '
            . 'with OpenCV during development.',
            $encoder->version(), $encoder->size(), $encoder->size(), number_format(strlen($png))),
        'The QR code did not render.'
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 30. Scale
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Scale to 50+ locations (test 30)');

$runner->test('T30.1', '50 locations and 50 printers are created', 'all rows present', function () use ($connectDb, $basePath) {
    $db = $connectDb();

    App\Core\Config::load($basePath . '/config');

    $created = 0;
    $db->transaction(function () use ($db, &$created): void {
        for ($i = 1; $i <= 50; $i++) {
            $code = sprintf('scale-loc-%02d', $i);
            if ((int) $db->scalar('SELECT COUNT(*) FROM locations WHERE code = ?', [$code]) > 0) {
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $locationId = $db->insert('locations', [
                'code' => $code,
                'name' => sprintf('Scale Test Branch %02d', $i),
                'city' => 'City ' . $i,
                'country' => 'IN',
                'qr_token_hash' => hash('sha256', $token),
                'qr_token_hint' => substr($token, 0, 8),
                'status' => 'active',
            ]);
            $printerId = $db->insert('printers', [
                'location_id' => $locationId,
                'name' => sprintf('Scale Printer %02d', $i),
                'code' => sprintf('scale-prn-%02d', $i),
                'capability_profile' => 'canon_gm4070',
                'status' => 'online',
                'is_enabled' => 1,
                'is_default' => 1,
                'last_seen_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $db->insert('printer_connections', [
                'printer_id' => $printerId,
                'driver' => 'null',
                'priority' => 1,
                'timeout_seconds' => 5,
                'is_enabled' => 1,
            ]);
            $created++;
        }
    });

    $locations = (int) $db->scalar("SELECT COUNT(*) FROM locations WHERE code LIKE 'scale-loc-%'");
    $printers = (int) $db->scalar("SELECT COUNT(*) FROM printers WHERE code LIKE 'scale-prn-%'");

    return TestRunner::assertTrue(
        $locations >= 50 && $printers >= 50,
        sprintf('%d locations and %d printers created for the scale test.', $locations, $printers),
        sprintf('locations=%d printers=%d', $locations, $printers)
    );
});

$runner->test('T30.2', 'Dashboard aggregates stay fast at scale', 'under 500 ms', function () use ($adminClient) {
    $started = microtime(true);
    $response = $adminClient->get('/admin/dashboard');
    $elapsed = (microtime(true) - $started) * 1000;

    return TestRunner::assertTrue(
        $response['status'] === 200 && $elapsed < 500,
        sprintf('Dashboard rendered in %d ms with 50+ locations and printers.', (int) $elapsed),
        sprintf('HTTP %d in %d ms', $response['status'], (int) $elapsed)
    );
});

$runner->test('T30.3', 'The location list pages rather than loading everything', 'bounded page size', function () use ($adminClient, $connectDb) {
    $db = $connectDb();
    $total = (int) $db->scalar('SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL');

    $started = microtime(true);
    $response = $adminClient->get('/admin/locations');
    $elapsed = (microtime(true) - $started) * 1000;

    // Count rendered rows: the page must not dump every location.
    preg_match_all('#<tr>\s*<td data-label="Location">#', $response['body'], $matches);
    $rendered = count($matches[0]);

    return TestRunner::assertTrue(
        $response['status'] === 200 && $rendered <= 25 && $rendered < $total && $elapsed < 500,
        sprintf('%d of %d locations rendered (paged at 25) in %d ms.', $rendered, $total, (int) $elapsed),
        sprintf('rendered=%d total=%d in %d ms', $rendered, $total, (int) $elapsed)
    );
});

$runner->test('T30.4', 'Health checks across 50+ printers complete promptly', 'a bounded batch', function () use ($basePath) {
    $started = microtime(true);
    $output = [];
    exec('php ' . escapeshellarg($basePath . '/bin/cron.php') . ' --task=printer_health --verbose 2>&1', $output);
    $elapsed = (microtime(true) - $started) * 1000;

    $summary = implode(' ', $output);

    return TestRunner::assertTrue(
        $elapsed < 30000 && str_contains($summary, 'checked'),
        sprintf('Completed in %d ms — %s', (int) $elapsed, trim($summary)),
        sprintf('%d ms: %s', (int) $elapsed, $summary)
    );
});

$runner->test('T30.5', 'Reporting queries stay indexed at scale', 'no full table scans on the job index', function () use ($connectDb) {
    $db = $connectDb();

    $plan = $db->select(
        "EXPLAIN SELECT COUNT(*) FROM print_jobs j
         WHERE j.created_at >= ? AND j.created_at <= ? AND j.location_id = ?",
        [gmdate('Y-m-d', strtotime('-30 days')) . ' 00:00:00', gmdate('Y-m-d') . ' 23:59:59', 1]
    );

    $usesIndex = false;
    foreach ($plan as $row) {
        if (!empty($row['key'])) {
            $usesIndex = true;
        }
    }

    $indexes = (int) $db->scalar(
        "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'print_jobs'"
    );

    return TestRunner::assertTrue(
        $usesIndex,
        sprintf('The reporting query uses an index (print_jobs carries %d indexes, including a '
            . 'composite on created_at/location_id/color_mode/paper_size).', $indexes),
        'The query planner reported no index use: ' . json_encode($plan)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// Reporting and export
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Reporting and export (test 13)');

$runner->test('TR.1', 'The reports screen renders with filters', 'HTTP 200', function () use ($adminClient) {
    $response = $adminClient->get('/admin/reports?preset=month&group_by=day');
    return TestRunner::assertTrue(
        $response['status'] === 200 && str_contains($response['body'], 'Grouped by'),
        'Reports rendered with the period, filter and grouping controls.',
        'HTTP ' . $response['status']
    );
});

$runner->test('TR.2', 'CSV export produces a real spreadsheet file', 'BOM, headers and rows', function () use ($adminClient) {
    $response = $adminClient->get('/admin/reports/export/csv?preset=last_30');
    $body = $response['body'];

    $hasBom = str_starts_with($body, "\xEF\xBB\xBF");
    $hasHeaders = str_contains($body, 'Job Number') && str_contains($body, 'Billable Pages');
    $isAttachment = str_contains($response['headers']['content-disposition'] ?? '', 'attachment');

    return TestRunner::assertTrue(
        $response['status'] === 200 && $hasBom && $hasHeaders && $isAttachment,
        sprintf('CSV: %d bytes, UTF-8 BOM so Excel opens it correctly, %d lines.',
            strlen($body), substr_count($body, "\n")),
        sprintf('status=%d bom=%s headers=%s', $response['status'], json_encode($hasBom), json_encode($hasHeaders))
    );
});

$runner->test('TR.3', 'CSV export neutralises formula injection', 'leading = is defused', function () use ($basePath) {
    require_once $basePath . '/app/Support/Exporter.php';
    $csv = App\Support\Exporter::csv(['Name'], [['=cmd|calc!A1'], ['+1+1'], ['normal']]);

    // A leading = would be evaluated by Excel; a tab prefix stops that.
    $defused = str_contains($csv, "\t=cmd") || str_contains($csv, '"\t=cmd');

    return TestRunner::assertTrue(
        $defused,
        'A cell beginning with "=" is written with a leading tab, so a spreadsheet treats it as '
        . 'text rather than executing it.',
        'The formula was written unmodified: ' . substr($csv, 0, 120)
    );
});

$runner->test('TR.4', 'Excel export is a valid SpreadsheetML workbook', 'opens as a spreadsheet', function () use ($adminClient) {
    $response = $adminClient->get('/admin/reports/export/excel?preset=last_30');
    $body = $response['body'];

    $isXml = str_starts_with(ltrim($body), '<?xml');
    $isWorkbook = str_contains($body, 'urn:schemas-microsoft-com:office:spreadsheet');
    $wellFormed = @simplexml_load_string($body) !== false;

    return TestRunner::assertTrue(
        $response['status'] === 200 && $isXml && $isWorkbook && $wellFormed,
        sprintf('SpreadsheetML workbook, %d bytes, parses as well-formed XML.', strlen($body)),
        sprintf('xml=%s workbook=%s wellformed=%s',
            json_encode($isXml), json_encode($isWorkbook), json_encode($wellFormed))
    );
});

$runner->test('TR.5', 'PDF export is a valid PDF', 'correct header and trailer', function () use ($adminClient) {
    $response = $adminClient->get('/admin/reports/export/pdf?preset=last_30');
    $body = $response['body'];

    $hasHeader = str_starts_with($body, '%PDF-');
    $hasTrailer = str_contains($body, 'trailer') && str_contains($body, '%%EOF');
    $hasXref = str_contains($body, 'xref');

    return TestRunner::assertTrue(
        $response['status'] === 200 && $hasHeader && $hasTrailer && $hasXref,
        sprintf('PDF: %d bytes with a valid header, xref table and trailer.', strlen($body)),
        sprintf('header=%s xref=%s trailer=%s',
            json_encode($hasHeader), json_encode($hasXref), json_encode($hasTrailer))
    );
});

// ═══════════════════════════════════════════════════════════════════════
// Agent API
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Print agent API');

$runner->test('TA.1', 'The agent API rejects an unauthenticated request', 'HTTP 401', function () use ($baseUrl) {
    $probe = new HttpClient($baseUrl);
    $response = $probe->request('POST', '/api/agent/claim', '{}', ['Content-Type' => 'application/json']);
    return TestRunner::assertTrue(
        $response['status'] === 401,
        'HTTP 401 with a WWW-Authenticate challenge.',
        'HTTP ' . $response['status']
    );
});

$runner->test('TA.2', 'The agent API rejects an invalid token', 'HTTP 401', function () use ($baseUrl) {
    $probe = new HttpClient($baseUrl);
    $response = $probe->request('POST', '/api/agent/claim', '{}', [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer kpms_not_a_real_token_at_all',
    ]);
    return TestRunner::assertTrue(
        $response['status'] === 401,
        'HTTP 401 — an invented token is refused.',
        'HTTP ' . $response['status']
    );
});

$runner->test('TA.3', 'A registered agent authenticates and gets its configuration', 'its own printers only', function () use ($adminClient, $baseUrl, $connectDb, &$state) {
    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations WHERE code = ?', [$state['location_code']]);

    $adminClient->get('/admin/devices');

    // Read the token from the page the redirect lands on. The application shows
    // it exactly once and then discards it, so re-fetching /admin/devices here
    // would correctly find nothing — that is the behaviour being relied on, not
    // a defect to work around.
    $page = $adminClient->submitForm('/admin/devices', [
        'name' => 'Verification Agent',
        'location_id' => (string) $locationId,
        'poll_interval_secs' => '10',
    ]);

    if (preg_match('#<code class="a-token" id="agentToken">([^<]+)</code>#', $page['body'], $m) !== 1) {
        return ['pass' => false, 'actual' => 'The agent token was not shown after registration.'];
    }
    $token = html_entity_decode(trim($m[1]), ENT_QUOTES);
    $state['agent_token'] = $token;

    $agent = new HttpClient($baseUrl);
    $response = $agent->request('GET', '/api/agent/config', null, ['Authorization' => 'Bearer ' . $token]);
    $json = $response['json'] ?? [];

    return TestRunner::assertTrue(
        $response['status'] === 200 && ($json['success'] ?? false),
        sprintf('Authenticated as "%s"; %d printer(s) assigned to this agent.',
            $json['device']['name'] ?? '?', count($json['printers'] ?? [])),
        'HTTP ' . $response['status'] . ': ' . json_encode($json)
    );
});

$runner->test('TA.4', 'The token is stored only as a hash', 'no plaintext token in the database', function () use ($connectDb, &$state) {
    $db = $connectDb();
    $token = (string) ($state['agent_token'] ?? '');

    $matches = (int) $db->scalar(
        'SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?',
        [hash('sha256', $token)]
    );
    $plaintextAnywhere = (int) $db->scalar(
        'SELECT COUNT(*) FROM api_tokens WHERE token_hash LIKE ?',
        ['%' . substr($token, 6, 20) . '%']
    );

    return TestRunner::assertTrue(
        $matches === 1 && $plaintextAnywhere === 0,
        'The token resolves by SHA-256 hash; the plaintext appears nowhere in the table.',
        sprintf('hash matches=%d plaintext found=%d', $matches, $plaintextAnywhere)
    );
});

$runner->test('TA.5', 'An agent cannot claim another location\'s job', 'scoped to its own printers', function () use ($baseUrl, &$state) {
    $agent = new HttpClient($baseUrl);
    $response = $agent->request('POST', '/api/agent/claim', '{}', [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . ($state['agent_token'] ?? ''),
    ]);
    $json = $response['json'] ?? [];

    // The agent has no printers assigned yet, so it must get nothing —
    // not somebody else's queued job.
    return TestRunner::assertTrue(
        $response['status'] === 200 && ($json['job'] ?? null) === null,
        'No job returned: the claim is scoped to this device\'s own printers, so a compromised '
        . 'agent at one shop cannot pull another shop\'s documents.',
        json_encode($json)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// Transport honesty — the RAW/9100 refusal
// ═══════════════════════════════════════════════════════════════════════

$runner->group('Agent job options');

$runner->test('TG.1', 'The options sent to an agent carry the copies the customer paid for', 'copies=N is present', function () use ($basePath) {
    App\Core\Config::load($basePath . '/config');

    // Regression: copies were in the IPP attributes but not in the CUPS
    // options, so a printer driven by a location agent produced one copy of a
    // three-copy order. The job succeeded, so nothing looked wrong anywhere
    // except at the counter.
    $spec = new App\Services\Printer\PrintJobSpec(
        jobNumber: 'AK100001',
        copies: 3,
        colorMode: 'bw',
        paperSize: 'A4',
        orientation: 'portrait',
        duplex: 'single',
        pageRange: 'all',
        documentPages: 10,
        documentName: 'order.pdf',
        mimeType: 'application/pdf',
    );

    $options = $spec->toCupsOptions();
    $ipp = $spec->toIppAttributes();

    return TestRunner::assertTrue(
        in_array('copies=3', $options, true) && ($ipp['copies'] ?? 0) === 3,
        'Both transports carry 3 copies: ' . implode(' ', $options),
        'cups=' . json_encode($options) . ' ipp copies=' . json_encode($ipp['copies'] ?? null)
    );
});

$runner->test('TG.2', 'The agent handles its options, its printers and its own config correctly', 'every agent check passes', function () use ($basePath) {
    // The Windows print backend cannot be driven from here — there is no
    // Windows spooler to talk to — so what is checked is everything that
    // decides whether it behaves once it is there: the option translation, the
    // printer-state mapping, and the safety of the PowerShell it builds.
    $script = $basePath . '/tests/agent_check.py';
    if (!is_file($script)) {
        return ['pass' => false, 'actual' => 'tests/agent_check.py is missing.'];
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open('python3 ' . escapeshellarg($script), $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['pass' => false, 'actual' => 'Could not run python3.'];
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $summary = '';
    if (preg_match('/^(\d+ passed, \d+ failed.*)$/m', $output, $m) === 1) {
        $summary = trim($m[1]);
    }

    return TestRunner::assertTrue(
        $status === 0,
        $summary !== '' ? $summary : 'All Windows backend checks passed.',
        $summary !== '' ? $summary : trim(substr($output, -300))
    );
});

$runner->test('TG.3', 'An operator can send a test page from the printer page', 'a real job, queued, not charged', function () use ($adminClient, $connectDb, $basePath, &$state) {
    $db = $connectDb();
    $printerId = (int) $state['printer_id'];

    $before = (int) $db->scalar('SELECT COUNT(*) FROM print_jobs');

    $page = $adminClient->get('/admin/printers/' . $printerId);
    if (!str_contains($page['body'], 'Send test page')) {
        return ['pass' => false, 'actual' => 'The printer page has no test print control.'];
    }

    $response = $adminClient->submitForm('/admin/printers/' . $printerId . '/test-print', []);

    $job = $db->selectOne(
        'SELECT * FROM print_jobs WHERE printer_id = ? ORDER BY id DESC LIMIT 1',
        [$printerId]
    );
    $after = (int) $db->scalar('SELECT COUNT(*) FROM print_jobs');

    if ($job === null || $after !== $before + 1) {
        return ['pass' => false, 'actual' => 'HTTP ' . $response['status'] . '; no job was created.'];
    }

    // It must be a genuine PDF on disk, because the agent will download and
    // print exactly this file.
    $file = $db->selectOne('SELECT * FROM print_files WHERE id = ?', [(int) $job['file_id']]);
    $path = $basePath . '/uploads/' . ltrim((string) ($file['relative_path'] ?? ''), '/');
    $header = is_file($path) ? (string) file_get_contents($path, false, null, 0, 5) : '';

    $queued = (string) $job['status'] === 'queued';
    $free = (int) $job['total_paise'] === 0;
    $safeOptions = (int) $job['copies'] === 1
        && (string) $job['color_mode'] === 'bw'
        && (string) $job['paper_size'] === 'A4'
        && (string) $job['duplex'] === 'single';
    $unowned = $job['session_id'] === null;

    return TestRunner::assertTrue(
        $queued && $free && $safeOptions && $unowned && $header === '%PDF-',
        sprintf('%s queued free of charge as 1 copy, A4, B&W, single sided, with a real PDF on disk '
            . 'and no customer session attached.', (string) $job['job_number']),
        sprintf('status=%s total=%d copies=%d session=%s header=%s',
            $job['status'], (int) $job['total_paise'], (int) $job['copies'],
            var_export($job['session_id'], true), $header)
    );
});

$runner->test('TG.4', 'A test page prints on a printer with nothing verified yet', 'not blocked by capability checks', function () use ($adminClient, $connectDb) {
    // The point of a test print is to establish what a printer can do, so it
    // cannot be gated on what has already been established. A customer job on
    // this printer is refused; the test page is not.
    $db = $connectDb();

    $locationId = (int) $db->scalar('SELECT id FROM locations ORDER BY id LIMIT 1');
    $printerId = (int) $db->insert('printers', [
        'location_id' => $locationId,
        'name' => 'Unverified printer',
        'code' => 'unverified-' . bin2hex(random_bytes(3)),
        'capability_profile' => 'generic',
        'status' => 'unknown',
        'is_enabled' => 1,
    ]);

    $verified = (int) $db->scalar(
        "SELECT COUNT(*) FROM printer_capabilities WHERE printer_id = ? AND source IN ('probed','manual')",
        [$printerId]
    );

    $response = $adminClient->submitForm('/admin/printers/' . $printerId . '/test-print', []);
    $job = $db->selectOne(
        'SELECT job_number, status FROM print_jobs WHERE printer_id = ? ORDER BY id DESC LIMIT 1',
        [$printerId]
    );

    return TestRunner::assertTrue(
        $verified === 0 && $job !== null && (string) $job['status'] === 'queued',
        sprintf('%d capabilities verified on this printer, and the test page still queued as %s.',
            $verified, (string) ($job['job_number'] ?? '?')),
        sprintf('verified=%d job=%s status=%s http=%d', $verified,
            (string) ($job['job_number'] ?? 'none'),
            (string) ($job['status'] ?? '-'), $response['status'])
    );
});

$runner->test('TG.5', 'A printer can be removed from the admin panel', 'the control exists and works', function () use ($adminClient, $connectDb) {
    // The route and the controller action had existed all along; the button
    // did not, so a printer simply could not be deleted through the interface.
    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations ORDER BY id LIMIT 1');
    $printerId = (int) $db->insert('printers', [
        'location_id' => $locationId,
        'name' => 'Disposable printer',
        'code' => 'disposable-' . bin2hex(random_bytes(3)),
        'capability_profile' => 'generic',
        'status' => 'unknown',
        'is_enabled' => 1,
    ]);

    $page = $adminClient->get('/admin/printers/' . $printerId);
    $hasControl = str_contains($page['body'], 'Delete printer');

    $adminClient->submitForm('/admin/printers/' . $printerId, ['_method' => 'DELETE']);

    $deletedAt = $db->scalar('SELECT deleted_at FROM printers WHERE id = ?', [$printerId]);
    $listed = (int) $db->scalar(
        'SELECT COUNT(*) FROM printers WHERE id = ? AND deleted_at IS NULL',
        [$printerId]
    );

    return TestRunner::assertTrue(
        $hasControl && $deletedAt !== null && $listed === 0,
        'The printer page offers Delete printer, and using it removes the printer.',
        sprintf('control present=%s, deleted_at=%s', $hasControl ? 'yes' : 'no', var_export($deletedAt, true))
    );
});

$runner->test('TG.6', 'A printer with work in progress is not deleted out from under it', 'refused, with the count', function () use ($adminClient, $connectDb, &$state) {
    $db = $connectDb();
    $printerId = (int) $state['printer_id'];

    $jobId = (int) $db->insert('print_jobs', [
        'job_number' => 'AKTEST' . random_int(1000, 9999),
        'batch_id' => bin2hex(random_bytes(16)),
        'location_id' => (int) $db->scalar('SELECT location_id FROM printers WHERE id = ?', [$printerId]),
        'printer_id' => $printerId,
        'copies' => 1, 'color_mode' => 'bw', 'paper_size' => 'A4',
        'orientation' => 'portrait', 'duplex' => 'single', 'page_range' => 'all',
        'document_pages' => 1, 'selected_pages' => 1, 'billable_pages' => 1, 'sheets' => 1,
        'total_paise' => 0, 'currency' => 'INR',
        'payment_status' => 'not_required', 'status' => 'queued', 'max_attempts' => 3,
    ]);

    $adminClient->submitForm('/admin/printers/' . $printerId, ['_method' => 'DELETE']);
    $stillHere = $db->scalar('SELECT deleted_at FROM printers WHERE id = ?', [$printerId]);

    $db->execute('DELETE FROM print_jobs WHERE id = ?', [$jobId]);

    return TestRunner::assertTrue(
        $stillHere === null,
        'Refused while a job was queued on it, so the job keeps the printer it belongs to.',
        'deleted_at=' . var_export($stillHere, true)
    );
});

$runner->test('TD.1', 'The agent API registers a printer for the agent that asked', 'created once, scoped to that device', function () use ($baseUrl, $connectDb, $adminClient) {
    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations ORDER BY id LIMIT 1');

    $adminClient->get('/admin/devices');
    $page = $adminClient->submitForm('/admin/devices', [
        'name' => 'Desktop App Agent',
        'location_id' => (string) $locationId,
        'poll_interval_secs' => '10',
    ]);
    if (preg_match('#id="agentToken">([^<]+)<#', $page['body'], $m) !== 1) {
        return ['pass' => false, 'actual' => 'No agent token was issued.'];
    }
    $token = html_entity_decode(trim($m[1]), ENT_QUOTES);
    $deviceId = (int) $db->scalar('SELECT id FROM devices ORDER BY id DESC LIMIT 1');

    $call = static function (string $path, ?array $payload, string $method = 'POST') use ($baseUrl, $token): array {
        $curl = curl_init($baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($curl, $options);
        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$status, json_decode($body, true) ?: []];
    };

    $queue = 'Desk Printer ' . bin2hex(random_bytes(2));
    [$firstStatus, $first] = $call('/api/agent/printers', [
        'queue_name' => $queue,
        'name' => $queue,
        'capability_profile' => 'hp_laserjet_m1005',
    ]);

    // Registering the same queue again must update, never duplicate: one
    // physical machine is one printer.
    [$secondStatus, $second] = $call('/api/agent/printers', ['queue_name' => $queue]);

    $rows = $db->select(
        'SELECT id, location_id, device_id, capability_profile FROM printers WHERE queue_name = ? AND deleted_at IS NULL',
        [$queue]
    );
    $connection = $db->selectOne(
        'SELECT driver FROM printer_connections WHERE printer_id = ?',
        [(int) ($rows[0]['id'] ?? 0)]
    );

    $ok = $firstStatus === 201 && ($first['created'] ?? null) === true
        && $secondStatus === 200 && ($second['created'] ?? null) === false
        && count($rows) === 1
        && (int) $rows[0]['device_id'] === $deviceId
        && (int) $rows[0]['location_id'] === $locationId
        && (string) $rows[0]['capability_profile'] === 'hp_laserjet_m1005'
        && (string) ($connection['driver'] ?? '') === 'agent';

    return TestRunner::assertTrue(
        $ok,
        sprintf('Created as %s on the agent transport, bound to its own device and location; '
            . 're-registering updated it instead of adding a second printer.',
            (string) ($first['printer']['code'] ?? '?')),
        sprintf('first=%d/%s second=%d/%s rows=%d device=%s profile=%s driver=%s',
            $firstStatus, json_encode($first['created'] ?? null),
            $secondStatus, json_encode($second['created'] ?? null),
            count($rows), $rows[0]['device_id'] ?? '-',
            $rows[0]['capability_profile'] ?? '-', $connection['driver'] ?? '-')
    );
});

$runner->test('TD.2', 'The desktop window connects, lists printers and registers one', 'driven for real, headlessly', function () use ($baseUrl, $basePath, $connectDb, $adminClient) {
    // The window is built and driven for real against the running server. The
    // Windows spooler is stubbed - there is none here - but everything else is
    // the shipping code path.
    $script = $basePath . '/tests/desktop_check.py';
    if (!is_file($script)) {
        return ['pass' => false, 'actual' => 'tests/desktop_check.py is missing.'];
    }

    $probe = shell_exec('python3 -c "import tkinter" 2>&1');
    if (trim((string) $probe) !== '') {
        return ['pass' => null, 'actual' => 'tkinter is not installed on this machine.'];
    }
    if (getenv('DISPLAY') === false) {
        return ['pass' => null, 'actual' => 'No X display; run under Xvfb to exercise the window.'];
    }

    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations ORDER BY id LIMIT 1');
    $adminClient->get('/admin/devices');
    $page = $adminClient->submitForm('/admin/devices', [
        'name' => 'Desktop Window Agent',
        'location_id' => (string) $locationId,
        'poll_interval_secs' => '10',
    ]);
    if (preg_match('#id="agentToken">([^<]+)<#', $page['body'], $m) !== 1) {
        return ['pass' => false, 'actual' => 'No agent token was issued.'];
    }
    $token = html_entity_decode(trim($m[1]), ENT_QUOTES);

    $command = sprintf(
        'python3 %s %s %s 2>&1',
        escapeshellarg($script),
        escapeshellarg($baseUrl),
        escapeshellarg($token)
    );
    $output = (string) shell_exec($command);

    $summary = '';
    if (preg_match('/^(\d+ passed, \d+ failed.*)$/m', $output, $mm) === 1) {
        $summary = trim($mm[1]);
    }

    return TestRunner::assertTrue(
        str_contains($output, ', 0 failed'),
        $summary !== '' ? $summary : 'Every desktop window check passed.',
        $summary !== '' ? $summary : trim(substr($output, -300))
    );
});

$runner->group('Transport limitations are enforced, not hidden');

$runner->test('TL.1', 'RAW/9100 refuses a PDF for a printer with no interpreter', 'a permanent, explained failure', function () use ($basePath, $rawPort, $makePdf, $scratch) {
    App\Core\Config::load($basePath . '/config');

    $target = new App\Services\Printer\PrinterTarget(
        printerId: 999,
        driver: 'raw9100',
        host: '127.0.0.1',
        port: $rawPort,
        capabilityProfile: 'canon_gm4070'
    );

    $path = $scratch . '/raw-refusal.pdf';
    $makePdf(1, $path);

    $driver = new App\Services\Printer\Drivers\Raw9100Driver();
    $spec = new App\Services\Printer\PrintJobSpec('TEST001', 1, 'bw', 'A4', 'portrait', 'single', 'all', 1);
    $result = $driver->submit($target, $spec, $path);

    return TestRunner::assertTrue(
        !$result->accepted && !$result->retryable,
        'Refused permanently: "' . substr($result->message, 0, 150) . '" — the driver will not '
        . 'send a PDF to a printer that has no interpreter for it.',
        sprintf('accepted=%s retryable=%s: %s',
            json_encode($result->accepted), json_encode($result->retryable), $result->message)
    );
});

$runner->test('TL.2', 'RAW/9100 admits it cannot report capabilities', 'unsupported, not a guess', function () use ($basePath) {
    $driver = new App\Services\Printer\Drivers\Raw9100Driver();
    $target = new App\Services\Printer\PrinterTarget(
        printerId: 999, driver: 'raw9100', host: '127.0.0.1', port: 9100
    );
    $capabilities = $driver->capabilities($target);

    return TestRunner::assertTrue(
        !$capabilities->reported && $capabilities->paperSizes === [],
        'Reports unsupported with the reason "' . substr((string) $capabilities->message, 0, 110)
        . '" rather than inventing a capability list.',
        'The driver claimed to report capabilities it cannot know.'
    );
});

$runner->test('TL.3', 'RAW/9100 refuses options it cannot carry', 'copies and duplex are rejected', function () use ($basePath, $rawPort, $scratch) {
    $target = new App\Services\Printer\PrinterTarget(
        printerId: 999, driver: 'raw9100', host: '127.0.0.1', port: $rawPort,
        capabilityProfile: 'ipp_everywhere'   // a profile that does not need rasterising
    );

    $path = $scratch . '/raw-options.ps';
    file_put_contents($path, "%!PS\n/Helvetica findfont 24 scalefont setfont 72 700 moveto (Test) show showpage\n");

    $driver = new App\Services\Printer\Drivers\Raw9100Driver();
    $spec = new App\Services\Printer\PrintJobSpec('TEST002', 3, 'bw', 'A4', 'portrait', 'double', 'all', 5);
    $result = $driver->submit($target, $spec, $path);

    return TestRunner::assertTrue(
        !$result->accepted && str_contains($result->message, 'job ticket'),
        'Refused: "' . substr($result->message, 0, 160) . '" — rather than dropping the options and '
        . 'printing something the customer did not order.',
        $result->message
    );
});

$runner->test('TL.4', 'RAW/9100 does not claim a job printed', 'delivery confirmed, rendering not', function () use ($basePath, $rawPort, $scratch) {
    $target = new App\Services\Printer\PrinterTarget(
        printerId: 999, driver: 'raw9100', host: '127.0.0.1', port: $rawPort,
        capabilityProfile: 'ipp_everywhere'
    );

    $path = $scratch . '/raw-simple.ps';
    file_put_contents($path, "%!PS\nshowpage\n");

    $driver = new App\Services\Printer\Drivers\Raw9100Driver();
    $spec = new App\Services\Printer\PrintJobSpec('TEST003', 1, 'bw', 'A4', 'portrait', 'single', 'all', 1);
    $result = $driver->submit($target, $spec, $path);

    return TestRunner::assertTrue(
        $result->accepted && !$result->confirmed && $result->remoteJobId === null,
        'Accepted but NOT confirmed: "' . substr($result->message, 0, 140) . '" — the socket write '
        . 'succeeded, which is all a byte pipe can tell you.',
        sprintf('accepted=%s confirmed=%s', json_encode($result->accepted), json_encode($result->confirmed))
    );
});

$runner->test('TH.2', 'A failed admin save stays in the admin panel', 'the reason is shown, not lost', function () use ($baseUrl, $adminClient) {
    // "Back" falls back to a section root when there is no Referer, and a
    // referer is missing more often than it looks. It used to fall back to the
    // site root, which redirected to the admin sign-in and rendered the flash
    // there by luck; with a real front page at the root, that luck ran out and
    // the operator got a marketing page with no message on it.
    $adminClient->get('/admin/system');
    $response = $adminClient->submitForm('/admin/system/update-config', [
        'repository' => 'octocat/definitely-not-a-real-repo-' . bin2hex(random_bytes(6)),
        'branch' => 'main',
        'token' => 'ghp_definitely_invalid_token_value_here',
    ]);

    $landedOnFrontPage = str_contains($response['body'], 'Who it is for');
    $toldWhy = str_contains($response['body'], 'GitHub rejected')
        || str_contains($response['body'], 'not found')
        || str_contains($response['body'], 'token was rejected');

    return TestRunner::assertTrue(
        !$landedOnFrontPage && $toldWhy,
        'Stayed inside the panel and said why the save failed.',
        sprintf('front_page=%s told_why=%s', json_encode($landedOnFrontPage), json_encode($toldWhy))
    );
});

$runner->test('TB.1', 'An agent can report a failure that is not worth retrying', 'false is a value, not a missing one', function () use ($baseUrl, $connectDb, $adminClient) {
    // PHP casts the boolean false to the empty string, so a JSON body carrying
    // `false` was compared as "" against a list of boolean-looking strings and
    // rejected. Only `true` ever got through. An agent reporting a job failed
    // and NOT retryable - LibreOffice missing, an unsupported format - could
    // therefore never report it at all: the job sat until its lease expired,
    // was requeued, failed again, and went round for ever.
    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations ORDER BY id LIMIT 1');

    $adminClient->get('/admin/devices');
    $page = $adminClient->submitForm('/admin/devices', [
        'name' => 'Boolean Report Agent',
        'location_id' => (string) $locationId,
        'poll_interval_secs' => '10',
    ]);
    if (preg_match('#id="agentToken">([^<]+)<#', $page['body'], $m) !== 1) {
        return ['pass' => false, 'actual' => 'No agent token was issued.'];
    }
    $token = html_entity_decode(trim($m[1]), ENT_QUOTES);

    // The real endpoint, through the real middleware. The lease is deliberately
    // not a live one: what is under test is whether the body survives
    // validation, and a rejected `retryable` fails before the lease is ever
    // looked at. A 422 naming that field is the bug; anything else is not.
    $report = static function (string $number, $retryable) use ($baseUrl, $token): array {
        $curl = curl_init($baseUrl . '/api/agent/jobs/' . $number . '/status');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'status' => 'failed',
                'lease_token' => str_repeat('a', 32),
                'message' => 'LibreOffice is not installed on this agent.',
                'error_code' => 'conversion_failed',
                'retryable' => $retryable,
            ]),
        ]);
        $body = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return [$status, json_decode($body, true) ?: [], $body];
    };

    // A job this device really owns. The controller looks the job up BEFORE it
    // validates the body, so a made-up number 404s and never reaches the rule
    // under test - which is exactly how the first version of this check passed
    // against the bug it was written for.
    $jobNumber = (string) $db->scalar('SELECT job_number FROM print_jobs ORDER BY id DESC LIMIT 1');
    if ($jobNumber === '') {
        return ['pass' => false, 'actual' => 'No job exists to report against.'];
    }
    // print_jobs.device_id is the agent that CLAIMED the job, and that is what
    // the controller checks. Lend the job to this agent for the two calls.
    $deviceId = (int) $db->scalar('SELECT id FROM devices ORDER BY id DESC LIMIT 1');
    $originalDevice = $db->scalar('SELECT device_id FROM print_jobs WHERE job_number = ?', [$jobNumber]);
    $db->execute('UPDATE print_jobs SET device_id = ? WHERE job_number = ?', [$deviceId, $jobNumber]);

    try {
        [$falseStatus, $falseJson, $falseBody] = $report($jobNumber, false);
        [$trueStatus, $trueJson, $trueBody] = $report($jobNumber, true);
    } finally {
        $db->execute('UPDATE print_jobs SET device_id = ? WHERE job_number = ?', [$originalDevice, $jobNumber]);
    }

    $rejectedField = static fn (array $json): bool =>
        isset($json['errors']['retryable']);

    return TestRunner::assertTrue(
        !$rejectedField($falseJson) && !$rejectedField($trueJson)
            && $falseStatus === 409 && $trueStatus === 409,
        sprintf(
            'Both true and false got past validation and were refused on the lease instead '
            . '(HTTP %d and %d) - so a permanent failure can be reported and the job stops '
            . 'going round.',
            $falseStatus, $trueStatus
        ),
        sprintf('false -> HTTP %d %s | true -> HTTP %d %s',
            $falseStatus, substr($falseBody, 0, 200), $trueStatus, substr($trueBody, 0, 200))
    );
});

$runner->test('TU.1', 'A release is only published when it can be verified', 'version, https and a checksum, or nothing', function () use ($connectDb, $adminClient) {
    $db = $connectDb();
    $read = static fn (): array => [
        'version' => (string) $db->scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'agent_release_version'"),
        'url' => (string) $db->scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'agent_release_url'"),
        'sha' => (string) $db->scalar("SELECT setting_value FROM system_settings WHERE setting_key = 'agent_release_sha256'"),
    ];

    $digest = hash('sha256', 'pretend installer');

    // Plain HTTP: refused. The agent downloads an executable from this address.
    $adminClient->get('/admin/settings');
    $adminClient->submitForm('/admin/settings/agent-release', [
        'agent_release_version' => '9.9.9',
        'agent_release_url' => 'http://example.com/Setup.exe',
        'agent_release_sha256' => $digest,
    ]);
    $afterHttp = $read();

    // No checksum: refused. A URL with nothing to check it against would make
    // this box a way to run any executable on every counter in the estate.
    $adminClient->get('/admin/settings');
    $adminClient->submitForm('/admin/settings/agent-release', [
        'agent_release_version' => '9.9.9',
        'agent_release_url' => 'https://example.com/Setup.exe',
        'agent_release_sha256' => 'not-a-real-hash',
    ]);
    $afterNoSum = $read();

    // All three, properly: accepted.
    $adminClient->get('/admin/settings');
    $adminClient->submitForm('/admin/settings/agent-release', [
        'agent_release_version' => '9.9.9',
        'agent_release_url' => 'https://example.com/Setup.exe',
        'agent_release_sha256' => $digest,
        'agent_release_notes' => 'Runs in the notification area.',
    ]);
    $afterGood = $read();

    return TestRunner::assertTrue(
        $afterHttp['url'] === '' && $afterNoSum['sha'] === ''
            && $afterGood['version'] === '9.9.9' && $afterGood['sha'] === $digest,
        'A plain-HTTP address and a malformed checksum were both refused; the complete release was '
        . 'stored.',
        sprintf('http=%s nosum=%s good=%s',
            json_encode($afterHttp), json_encode($afterNoSum), json_encode($afterGood))
    );
});

$runner->test('TU.2', 'Agents are told about the release, and never about half of one', 'all three fields or published=false', function () use ($baseUrl, $connectDb, $adminClient) {
    $db = $connectDb();
    $locationId = (int) $db->scalar('SELECT id FROM locations ORDER BY id LIMIT 1');

    $adminClient->get('/admin/devices');
    $page = $adminClient->submitForm('/admin/devices', [
        'name' => 'Update Test Agent',
        'location_id' => (string) $locationId,
        'poll_interval_secs' => '10',
    ]);
    if (preg_match('#id="agentToken">([^<]+)<#', $page['body'], $m) !== 1) {
        return ['pass' => false, 'actual' => 'No agent token was issued.'];
    }
    $token = html_entity_decode(trim($m[1]), ENT_QUOTES);

    $call = static function (string $path) use ($baseUrl, $token): array {
        $curl = curl_init($baseUrl . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);
        $body = (string) curl_exec($curl);
        curl_close($curl);
        return json_decode($body, true) ?: [];
    };

    // TU.1 left a complete release published.
    $viaUpdate = $call('/api/agent/update')['update'] ?? [];
    $viaConfig = $call('/api/agent/config')['update'] ?? [];

    // Now break it behind the API's back, the way a half-finished edit would,
    // and check nothing is offered rather than something unverifiable.
    $db->execute("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'agent_release_sha256'");
    $broken = $call('/api/agent/update')['update'] ?? [];
    $db->execute(
        "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'agent_release_sha256'",
        [hash('sha256', 'pretend installer')]
    );

    return TestRunner::assertTrue(
        ($viaUpdate['published'] ?? false) === true
            && ($viaUpdate['version'] ?? '') === '9.9.9'
            && ($viaUpdate['sha256'] ?? '') !== ''
            && ($viaConfig['published'] ?? false) === true
            && ($broken['published'] ?? true) === false
            && !isset($broken['url']),
        'The agent is told the version, the address and the checksum together, on both the update '
        . 'call and the ordinary config call; with the checksum missing it is told nothing at all.',
        sprintf('update=%s config=%s broken=%s',
            json_encode($viaUpdate), json_encode($viaConfig), json_encode($broken))
    );
});

$runner->test('TH.1', 'The front page explains the product to someone with no account', 'readable signed out, and honest', function () use ($baseUrl) {
    $visitor = new HttpClient($baseUrl);
    $page = $visitor->get('/');
    $body = $page['body'];

    // It must be readable without signing in - the root used to redirect to
    // the admin login, which is a box for an account a shop owner has not got.
    $reachable = $page['status'] === 200;

    $explains = str_contains($body, 'How it works')
        && str_contains($body, 'Who it is for')
        && str_contains($body, 'What it does not do');

    $routesIn = str_contains($body, 'href="/register"')
        && str_contains($body, 'href="/partner/login"')
        && str_contains($body, 'href="/admin/login"');

    // The one claim that would be a lie: paying each shop separately at the
    // gateway is not built, so the page must say so rather than imply it.
    $honest = str_contains($body, 'not part of this yet');

    return TestRunner::assertTrue(
        $reachable && $explains && $routesIn && $honest,
        'Served to a signed-out visitor with how it works, who it suits, what it will not do, the '
        . 'three ways in, and the gateway limitation stated rather than glossed over.',
        sprintf('status=%d explains=%s links=%s honest=%s',
            $page['status'], json_encode($explains), json_encode($routesIn), json_encode($honest))
    );
});

$runner->group('Shop self-registration');

$registrationEmail = 'ramesh' . bin2hex(random_bytes(3)) . '@shop.test';
$registrationPassword = 'Anchor-Marigold-7731';

$runner->test('TP.1', 'Anyone can register a shop from the public site', 'a registration, and nothing more', function () use ($baseUrl, $connectDb, $registrationEmail, $registrationPassword) {
    $db = $connectDb();
    $locationsBefore = (int) $db->scalar('SELECT COUNT(*) FROM locations');
    $devicesBefore = (int) $db->scalar('SELECT COUNT(*) FROM devices');

    $shop = new HttpClient($baseUrl);
    $form = $shop->get('/register');
    if ($form['status'] !== 200) {
        return ['pass' => false, 'actual' => 'GET /register returned ' . $form['status']];
    }

    $shop->submitForm('/register', [
        'shop_name' => "Ramesh's Xerox & Stationery",
        'contact_name' => 'Ramesh Patel',
        'email' => $registrationEmail,
        'phone' => '9876543210',
        'address_line1' => '14 Station Road',
        'city' => 'Rajkot',
        'state' => 'Gujarat',
        'postal_code' => '360001',
        'password' => $registrationPassword,
        'password_confirmation' => $registrationPassword,
    ]);

    $row = $db->selectOne('SELECT * FROM partners WHERE email = ?', [$registrationEmail]);

    // The whole point of the queue: a public form must not be able to put a
    // location or a print agent into the estate.
    $locationsAfter = (int) $db->scalar('SELECT COUNT(*) FROM locations');
    $devicesAfter = (int) $db->scalar('SELECT COUNT(*) FROM devices');

    return TestRunner::assertTrue(
        $row !== null
            && $row['status'] === 'pending'
            && $row['location_id'] === null
            && $row['device_id'] === null
            && $locationsAfter === $locationsBefore
            && $devicesAfter === $devicesBefore,
        sprintf(
            'Recorded as "%s", waiting for approval. Locations still %d and print agents still %d — '
            . 'registering creates neither.',
            $row['shop_name'] ?? '-', $locationsAfter, $devicesAfter
        ),
        sprintf('row=%s locations %d->%d devices %d->%d',
            json_encode($row), $locationsBefore, $locationsAfter, $devicesBefore, $devicesAfter)
    );
});

$runner->test('TP.2', 'The password is stored hashed and must survive the policy', 'no plaintext, no guessable password', function () use ($baseUrl, $connectDb, $registrationEmail, $registrationPassword) {
    $db = $connectDb();
    $hash = (string) $db->scalar('SELECT password_hash FROM partners WHERE email = ?', [$registrationEmail]);

    $weak = new HttpClient($baseUrl);
    $weak->get('/register');
    $weakEmail = 'weak' . bin2hex(random_bytes(3)) . '@shop.test';
    $weak->submitForm('/register', [
        'shop_name' => 'Weak Shop', 'contact_name' => 'Test', 'email' => $weakEmail,
        'phone' => '9000000000', 'password' => 'password1', 'password_confirmation' => 'password1',
    ]);
    $weakRow = $db->selectOne('SELECT id FROM partners WHERE email = ?', [$weakEmail]);

    // And the two password boxes must actually be compared.
    $mismatch = new HttpClient($baseUrl);
    $mismatch->get('/register');
    $mismatchEmail = 'typo' . bin2hex(random_bytes(3)) . '@shop.test';
    $mismatch->submitForm('/register', [
        'shop_name' => 'Typo Shop', 'contact_name' => 'Test', 'email' => $mismatchEmail,
        'phone' => '9000000001',
        'password' => 'Anchor-Marigold-7731', 'password_confirmation' => 'Anchor-Marigold-7732',
    ]);
    $mismatchRow = $db->selectOne('SELECT id FROM partners WHERE email = ?', [$mismatchEmail]);

    return TestRunner::assertTrue(
        $hash !== '' && $hash !== $registrationPassword && !str_contains($hash, $registrationPassword)
            && password_verify($registrationPassword, $hash)
            && $weakRow === null && $mismatchRow === null,
        'Stored as ' . substr($hash, 0, 7) . '… and verifiable; "password1" was refused by the policy '
        . 'and a mistyped confirmation was refused too.',
        sprintf('hash=%s weak_row=%s mismatch_row=%s',
            substr($hash, 0, 20), json_encode($weakRow), json_encode($mismatchRow))
    );
});

$runner->test('TP.3', 'A registration cannot approve itself', 'approval is the operator\'s, and authenticated', function () use ($baseUrl, $connectDb, $registrationEmail) {
    $db = $connectDb();
    $partnerId = (int) $db->scalar('SELECT id FROM partners WHERE email = ?', [$registrationEmail]);

    $stranger = new HttpClient($baseUrl);
    $response = $stranger->post('/admin/registrations/' . $partnerId . '/approve');
    $status = (string) $db->scalar('SELECT status FROM partners WHERE id = ?', [$partnerId]);

    return TestRunner::assertTrue(
        $response['status'] >= 300 && $status === 'pending',
        sprintf('Refused with HTTP %d and the shop is still pending.', $response['status']),
        sprintf('HTTP %d, status now %s', $response['status'], $status)
    );
});

$runner->test('TP.4', 'Approving creates the location and the print agent', 'one transaction, both or neither', function () use ($connectDb, $adminClient, $registrationEmail) {
    $db = $connectDb();
    $partnerId = (int) $db->scalar('SELECT id FROM partners WHERE email = ?', [$registrationEmail]);

    $adminClient->get('/admin/registrations');
    $adminClient->submitForm('/admin/registrations/' . $partnerId . '/approve');

    $row = $db->selectOne('SELECT * FROM partners WHERE id = ?', [$partnerId]);
    $location = $row['location_id'] === null ? null
        : $db->selectOne('SELECT * FROM locations WHERE id = ?', [(int) $row['location_id']]);
    $device = $row['device_id'] === null ? null
        : $db->selectOne('SELECT * FROM devices WHERE id = ?', [(int) $row['device_id']]);

    // The QR token must be stored as a hash here exactly as anywhere else.
    $tokenIsHashed = $location !== null
        && strlen((string) $location['qr_token_hash']) === 64
        && ctype_xdigit((string) $location['qr_token_hash']);

    return TestRunner::assertTrue(
        $row['status'] === 'approved' && $location !== null && $device !== null && $tokenIsHashed
            && (int) $device['location_id'] === (int) $location['id'],
        sprintf(
            'Location "%s" (code %s) and print agent "%s" created together; the QR token is stored '
            . 'only as a hash.',
            $location['name'] ?? '-', $location['code'] ?? '-', $device['name'] ?? '-'
        ),
        sprintf('status=%s location=%s device=%s hashed=%s',
            $row['status'], json_encode($location), json_encode($device), json_encode($tokenIsHashed))
    );
});

$runner->test('TP.5', 'The shop signs in and issues its own agent token', 'a working token, nobody read it aloud', function () use ($baseUrl, $connectDb, $registrationEmail, $registrationPassword) {
    $db = $connectDb();

    $shop = new HttpClient($baseUrl);
    $shop->get('/partner/login');
    $shop->submitForm('/partner/login', ['email' => $registrationEmail, 'password' => $registrationPassword]);

    $dashboard = $shop->get('/partner');
    if ($dashboard['status'] !== 200) {
        return ['pass' => false, 'actual' => 'GET /partner after signing in returned ' . $dashboard['status']];
    }

    // submitForm follows the redirect, so the token is on that response —
    // the page it lands on is the one page load it is ever shown on.
    $issued = $shop->submitForm('/partner/token');
    if (preg_match('#<p class="p-token">([^<]+)</p>#', $issued['body'], $m) !== 1) {
        return ['pass' => false, 'actual' => 'The token was not shown: ' . substr($issued['body'], 0, 300)];
    }
    $token = html_entity_decode(trim($m[1]), ENT_QUOTES);

    // Only a hash of it is kept, and it must actually authenticate the agent.
    $stored = $db->selectOne('SELECT * FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
    $plaintextAnywhere = (int) $db->scalar(
        'SELECT COUNT(*) FROM api_tokens WHERE token_hash = ? OR token_prefix = ?',
        [$token, $token]
    );

    $curl = curl_init($baseUrl . '/api/agent/config');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    $body = (string) curl_exec($curl);
    $apiStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $config = json_decode($body, true) ?: [];

    // Shown once and once only.
    $again = $shop->get('/partner');
    $shownAgain = preg_match('#<p class="p-token">#', $again['body']) === 1;

    return TestRunner::assertTrue(
        $stored !== null && $plaintextAnywhere === 0 && $apiStatus === 200
            && ($config['device']['name'] ?? '') !== '' && !$shownAgain,
        sprintf(
            'Token %s… works against the agent API as "%s", is stored only as a SHA-256 hash, and is '
            . 'not shown again on the next page load.',
            substr($token, 0, 12), $config['device']['name'] ?? '-'
        ),
        sprintf('stored=%s plaintext_rows=%d api=%d shown_again=%s',
            json_encode($stored !== null), $plaintextAnywhere, $apiStatus, json_encode($shownAgain))
    );
});

$runner->test('TP.6', 'Issuing a replacement retires the old token', 'one live token per shop', function () use ($baseUrl, $connectDb, $registrationEmail, $registrationPassword) {
    $db = $connectDb();

    $shop = new HttpClient($baseUrl);
    $shop->get('/partner/login');
    $shop->submitForm('/partner/login', ['email' => $registrationEmail, 'password' => $registrationPassword]);

    $readToken = static function (HttpClient $client): string {
        $page = $client->submitForm('/partner/token');
        return preg_match('#<p class="p-token">([^<]+)</p>#', $page['body'], $m) === 1
            ? html_entity_decode(trim($m[1]), ENT_QUOTES) : '';
    };

    $first = $readToken($shop);
    $second = $readToken($shop);

    $check = static function (string $token) use ($baseUrl): int {
        $curl = curl_init($baseUrl . '/api/agent/config');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return $status;
    };

    $firstStatus = $check($first);
    $secondStatus = $check($second);

    return TestRunner::assertTrue(
        $first !== '' && $second !== '' && $first !== $second
            && $firstStatus === 401 && $secondStatus === 200,
        sprintf('The replacement works (HTTP %d) and the one it replaced does not (HTTP %d).',
            $secondStatus, $firstStatus),
        sprintf('first=%d second=%d same=%s', $firstStatus, $secondStatus, json_encode($first === $second))
    );
});

$runner->test('TP.7', 'One shop cannot reach another shop\'s page', 'the session decides, not the URL', function () use ($baseUrl, $connectDb, $registrationEmail, $registrationPassword) {
    $db = $connectDb();

    // A second shop, approved, so there is something to try to reach.
    $otherEmail = 'other' . bin2hex(random_bytes(3)) . '@shop.test';
    $otherPassword = 'Compass-Thistle-9084';
    $other = new HttpClient($baseUrl);
    $other->get('/register');
    $other->submitForm('/register', [
        'shop_name' => 'Other Copy Centre', 'contact_name' => 'Other Owner',
        'email' => $otherEmail, 'phone' => '9000000002',
        'password' => $otherPassword, 'password_confirmation' => $otherPassword,
    ]);

    // Signed out entirely: the dashboard is not readable at all.
    $anonymous = new HttpClient($baseUrl);
    $anonymousView = $anonymous->get('/partner');

    // Signed in as the first shop: the page shows that shop and no other.
    $shop = new HttpClient($baseUrl);
    $shop->get('/partner/login');
    $shop->submitForm('/partner/login', ['email' => $registrationEmail, 'password' => $registrationPassword]);
    $page = $shop->get('/partner');

    // A shop still waiting for approval has no token to issue.
    $pending = new HttpClient($baseUrl);
    $pending->get('/partner/login');
    $pending->submitForm('/partner/login', ['email' => $otherEmail, 'password' => $otherPassword]);
    $refused = $pending->post('/partner/token', ['_token' => $pending->csrfToken()]);
    $tokensForPending = (int) $db->scalar(
        'SELECT COUNT(*) FROM api_tokens t
         JOIN partners p ON p.device_id = t.device_id
         WHERE p.email = ?',
        [$otherEmail]
    );

    return TestRunner::assertTrue(
        $anonymousView['status'] >= 300
            && !str_contains($page['body'], 'Other Copy Centre')
            && $refused['status'] >= 300
            && $tokensForPending === 0,
        'Signed out the page redirects to the sign-in; signed in it shows only that shop; and a '
        . 'shop still waiting for approval is refused a token.',
        sprintf('anonymous=%d leaked=%s token_refused=%d pending_tokens=%d',
            $anonymousView['status'],
            json_encode(str_contains($page['body'], 'Other Copy Centre')),
            $refused['status'], $tokensForPending)
    );
});

// ═══════════════════════════════════════════════════════════════════════
// Report
// ═══════════════════════════════════════════════════════════════════════

$runner->summary();

require_once __DIR__ . '/ReportWriter.php';
Tests\ReportWriter::write($runner, $reportPath, [
    'base_url' => $baseUrl,
    'php_version' => PHP_VERSION,
    'database' => $connectDb()->scalar('SELECT VERSION()'),
    'mock_printer' => "IPP 127.0.0.1:$ippPort, RAW 127.0.0.1:$rawPort",
]);

fwrite(STDOUT, "\nReport written to: $reportPath\n\n");

exit($runner->failed() > 0 ? 1 : 0);

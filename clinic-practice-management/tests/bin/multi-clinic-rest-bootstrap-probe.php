<?php
/**
 * RED 1 — PROCESS-FRESH multi-Clinic REST bootstrap probe (TEST-ONLY).
 *
 * ============================================================================
 * WHAT THIS PROBE EXISTS TO PROVE
 * ============================================================================
 *
 * Accepted defect (infrastructure/architecture):
 *
 *   REST controller registration happens at `rest_api_init`, i.e. BEFORE
 *   `RestClinicContext` can establish a request scope. Several service
 *   factories reachable from `App::boot()`'s `rest_api_init` closure call the
 *   ambient `App::settings()` / `App::scope()` EAGERLY, while the controllers
 *   are only being CONSTRUCTED — long before any request is routed. On an
 *   installation that holds MORE THAN ONE real Clinic and that has no ambient
 *   current Clinic, `App::scope()` → `SystemClinicResolver::resolve()` fails
 *   closed with `CLINIC_SCOPE_REQUIRED`, so `clinic/v1` REST bootstrap aborts
 *   before routing exists.
 *
 *   A long-lived PHPUnit process HIDES this: the REST server and the memoized
 *   service singletons were built earlier in the same process, at a moment
 *   when the installation still held exactly one Clinic (the migration-seeded
 *   one). Therefore this contract CANNOT be proved from inside the PHPUnit
 *   process, and this probe deliberately does not try to.
 *
 * This script is the smallest execution that is genuinely PROCESS-FRESH:
 *
 *   - a brand-new PHP CLI interpreter (no PHPUnit, no previously-built REST
 *     server, no previously-memoized `App::` singletons, no warm
 *     `SystemClinicResolver` cache);
 *   - its OWN database, dropped and re-created on every run, so no fixture of
 *     any other test can leak in and no fixture of this probe can leak out;
 *   - a real WordPress bootstrap through the repository's own WP test suite
 *     (`tests/bin/install-wp-tests.sh` → `WP_TESTS_DIR`), i.e. the same
 *     bootstrap the integration suite uses;
 *   - the plugin loaded exactly as `tests/integration-bootstrap.php` loads it
 *     (`muplugins_loaded`), followed by the same `App::migrations()->migrate()`.
 *
 * ============================================================================
 * WHAT IT ASSERTS
 * ============================================================================
 *
 *   S1  the isolated probe database is created (never the suite's database);
 *   S2  WordPress bootstraps and the plugin's real migrations run to 0020;
 *   S3  two NON-TRIVIAL real Clinics are seeded (dynamic ids, never 1) with an
 *       Organization, Locations, a persisted active clinician in Clinic B and
 *       a free slot in Clinic B;
 *   S4  PRECONDITIONS: clinic count >= 2 · `ScopeContext::tryGet()` is null
 *       (no ambient current Clinic is pre-bound) · `SystemClinicResolver`'s
 *       system-resolution cache is still empty (proof that nothing in this
 *       fresh process has already resolved an ambient Clinic for us);
 *   S5  `rest_api_init` / `rest_get_server()` completes — i.e. REST
 *       registration/bootstrap is SCOPE-NEUTRAL.  ← the expected failure point
 *   S6  `GET /clinic/v1/health` is reachable (200) rather than fatally absent;
 *   S7  anonymous A1 (`GET /clinic/v1/availability`) for the persisted Clinic B
 *       clinician REACHES THE PRODUCT ROUTE (never `rest_no_route`);
 *   S8  anonymous A4 (`POST /clinic/v1/booking/quote`) for Clinic B REACHES A
 *       NORMAL PRODUCT ENVELOPE (never `rest_no_route`).
 *
 * ============================================================================
 * WHAT THIS PROBE DELIBERATELY DOES **NOT** DO
 * ============================================================================
 *
 *   - no "first Clinic" fallback, no `clinic_id = 1`, no synthetic "System"
 *     Clinic, no implicit default (ADR-0031 AD-13);
 *   - no singleton priming: nothing constructs `App::bookingService()`,
 *     `App::otpService()` or the REST server before S5 runs;
 *   - no flushing of `SystemClinicResolver` / `App::$*` statics to mask the
 *     defect: the resolver cache is only READ (by reflection) in S4, never
 *     cleared;
 *   - no product code, no schema, no migration, no settings default is
 *     changed. This file is a probe; it only observes.
 *
 * Exit codes: 0 = every step passed (NO RED — must be reported as such),
 *             1 = at least one step failed (the intended RED),
 *             2 = infrastructure pre-condition missing (INVALID RED class D),
 *             3 = WordPress/DB bootstrap failed before step S1..S4
 *                 (INVALID RED class D — harness, not the product contract).
 *
 * Usage (from the plugin root, after `tests/bin/install-wp-tests.sh`):
 *   php -d memory_limit=1G tests/bin/multi-clinic-rest-bootstrap-probe.php
 *
 * Environment (all optional — CI defaults match `install-wp-tests.sh`):
 *   WP_TESTS_DIR            default /tmp/wordpress-test-lib
 *   WP_CORE_DIR             default /tmp/wordpress
 *   CPMS_RED1_DB_NAME       default cpms_red1_bootstrap
 *   CPMS_RED1_DB_USER       default root
 *   CPMS_RED1_DB_PASSWORD   default root
 *   CPMS_RED1_DB_HOST       default 127.0.0.1
 */

declare(strict_types=1);

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;

// ============================================================================
// 0) Fresh-process evidence + infrastructure pre-conditions
// ============================================================================

$probePid      = getmypid();
$phpBinary     = PHP_BINARY;
$pluginRoot    = dirname(__DIR__, 2);
$pluginFile    = $pluginRoot . '/clinic-practice-management.php';
$testsDir      = rtrim((string) (getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-test-lib'), '/');
$coreDir       = rtrim((string) (getenv('WP_CORE_DIR') ?: '/tmp/wordpress'), '/') . '/';
$dbName        = (string) (getenv('CPMS_RED1_DB_NAME') ?: 'cpms_red1_bootstrap');
$dbUser        = (string) (getenv('CPMS_RED1_DB_USER') ?: 'root');
$dbPass        = (string) (getenv('CPMS_RED1_DB_PASSWORD') ?: 'root');
$dbHost        = (string) (getenv('CPMS_RED1_DB_HOST') ?: '127.0.0.1');

/**
 * Print one line to STDOUT.
 */
$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$out('=== RED 1 — process-fresh multi-Clinic REST bootstrap probe ===');
$out('process: pid=' . $probePid . ' php=' . $phpBinary . ' argv=' . implode(' ', $_SERVER['argv'] ?? []));
$out('plugin : ' . $pluginFile);
$out('wp     : WP_TESTS_DIR=' . $testsDir . ' WP_CORE_DIR=' . $coreDir);
$out('db     : ' . $dbName . '@' . $dbHost . ' (isolated from the PHPUnit suite database)');

if (!is_file($pluginFile)) {
    fwrite(STDERR, 'INVALID RED (class D): plugin main file not found at ' . $pluginFile . PHP_EOL);
    exit(2);
}

$autoload = $pluginRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, 'INVALID RED (class D): composer autoload missing at ' . $autoload . ' — run `composer install`.' . PHP_EOL);
    exit(2);
}
require_once $autoload;

if (!is_dir($testsDir . '/includes')) {
    fwrite(STDERR, 'INVALID RED (class D): WP test library missing at ' . $testsDir . ' — run tests/bin/install-wp-tests.sh.' . PHP_EOL);
    exit(2);
}
if (!is_file($coreDir . 'wp-settings.php')) {
    fwrite(STDERR, 'INVALID RED (class D): WP core missing at ' . $coreDir . PHP_EOL);
    exit(2);
}

// ============================================================================
// 1) Isolated database for this probe only
// ============================================================================

$mysqli = @new mysqli($dbHost, $dbUser, $dbPass);
if ($mysqli->connect_errno !== 0) {
    fwrite(STDERR, 'INVALID RED (class D): mysqli connect failed: ' . $mysqli->connect_error . PHP_EOL);
    exit(2);
}
if (!$mysqli->query('DROP DATABASE IF EXISTS `' . $dbName . '`')) {
    fwrite(STDERR, 'INVALID RED (class D): DROP DATABASE failed: ' . $mysqli->error . PHP_EOL);
    exit(2);
}
if (!$mysqli->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
    fwrite(STDERR, 'INVALID RED (class D): CREATE DATABASE failed: ' . $mysqli->error . PHP_EOL);
    exit(2);
}
$mysqli->close();
$out('[PASS] S1 isolated probe database created (dropped + re-created): ' . $dbName);

// ============================================================================
// 2) Isolated wp-tests-config for this probe only.
//
// The WP test bootstrap honours WP_TESTS_CONFIG_FILE_PATH, so the probe keeps
// the suite's `includes/` directory (WP_TESTS_DIR) while pointing the database
// and ABSPATH at its own values. Nothing in the suite's database is touched.
// ============================================================================

$configDir = sys_get_temp_dir() . '/cpms-red1-probe-' . $probePid;
if (!is_dir($configDir) && !@mkdir($configDir, 0777, true) && !is_dir($configDir)) {
    fwrite(STDERR, 'INVALID RED (class D): cannot create ' . $configDir . PHP_EOL);
    exit(2);
}

$configTemplate = <<<'PHPCONFIG'
<?php
define( 'DB_NAME', '%DB_NAME%' );
define( 'DB_USER', '%DB_USER%' );
define( 'DB_PASSWORD', '%DB_PASSWORD%' );
define( 'DB_HOST', '%DB_HOST%' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'ABSPATH', '%ABSPATH%' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'CPMS RED-1 probe' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WP_DEBUG', true );
PHPCONFIG;

$configBody = str_replace(
    ['%DB_NAME%', '%DB_USER%', '%DB_PASSWORD%', '%DB_HOST%', '%ABSPATH%'],
    [$dbName, $dbUser, $dbPass, $dbHost, $coreDir],
    $configTemplate
);
$configFile = $configDir . '/wp-tests-config.php';
if (file_put_contents($configFile, $configBody) === false) {
    fwrite(STDERR, 'INVALID RED (class D): cannot write ' . $configFile . PHP_EOL);
    exit(2);
}
define('WP_TESTS_CONFIG_FILE_PATH', $configFile);

// ============================================================================
// 3) Real WordPress bootstrap, exactly like tests/integration-bootstrap.php
// ============================================================================

try {
    require_once $testsDir . '/includes/functions.php';

    tests_add_filter('muplugins_loaded', static function () use ($pluginFile): void {
        require $pluginFile;
    });

    require_once $testsDir . '/includes/bootstrap.php';
} catch (Throwable $e) {
    fwrite(STDERR, 'INVALID RED (class D): WordPress/bootstrap failed before the probe could run: '
        . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(3);
}

if (!function_exists('rest_do_request') || !class_exists(WP_REST_Request::class)) {
    fwrite(STDERR, 'INVALID RED (class D): REST API not available after WordPress bootstrap.' . PHP_EOL);
    exit(3);
}

// ============================================================================
// 4) Plugin migrations (same call the integration bootstrap makes)
// ============================================================================

try {
    App::migrations()->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'INVALID RED (class D): migration failed: '
        . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(3);
}

$schemaVersion = App::migrations()->currentVersion();
$out('[PASS] S2 WordPress bootstrapped + plugin migrations applied; schema=' . $schemaVersion);

// ============================================================================
// 5) Step harness (steps S3..S8)
// ============================================================================

$results      = [];
$firstFailure = null;

/**
 * Run one probe step; record PASS/FAIL and never let a step kill the probe.
 *
 * @param callable():string $fn
 */
$run = static function (string $id, string $title, callable $fn) use (&$results, &$firstFailure, $out): bool {
    try {
        $detail = (string) $fn();
        $ok     = true;
    } catch (Throwable $e) {
        $ok     = false;
        $detail = get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $results[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
    if (!$ok && $firstFailure === null) {
        $firstFailure = $id;
    }

    $out('[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $id . ' ' . $title . ' — ' . $detail);

    return $ok;
};

/**
 * Insert one row through $wpdb and return the dynamic id.
 *
 * @param array<string, mixed> $data
 */
$insertRow = static function (string $table, array $data, string $label): int {
    global $wpdb;

    $ok = $wpdb->insert($wpdb->prefix . $table, $data);
    if (!$ok) {
        throw new RuntimeException('fixture insert failed (' . $label . '): ' . $wpdb->last_error);
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) {
        throw new RuntimeException('fixture insert produced no id (' . $label . ')');
    }

    return $id;
};

$nowUtcSql = static function (): string {
    return App::db()->nowUtcSql();
};

// ============================================================================
// S3 — two NON-TRIVIAL real Clinics (never clinic 1, never "first Clinic")
// ============================================================================

$fixture = [
    'orgId'      => 0,
    'clinicAId'  => 0,
    'clinicBId'  => 0,
    'clinicianB' => 0,
    'slotB'      => ['slot_id' => 0, 'date' => '', 'time' => ''],
];

$run('S3', 'seed two non-trivial real Clinics + persisted Clinic B clinician + free slot', static function () use (&$fixture, $insertRow, $nowUtcSql): string {
    $uid = bin2hex(random_bytes(4));
    $now = $nowUtcSql();

    $fixture['orgId'] = $insertRow('cpms_organizations', [
        'name'       => 'Org RED1 ' . $uid,
        'slug'       => 'org-red1-' . $uid,
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], 'organization');

    // Two real Clinics under that Organization. Ids are dynamic (AUTO_INCREMENT)
    // and are asserted > 1 below — the migration-seeded Clinic is id 1 and is
    // never used, assumed, or preferred anywhere in this probe.
    $fixture['clinicAId'] = $insertRow('cpms_clinics', [
        'organization_id' => $fixture['orgId'],
        'name'            => 'Clinic A RED1 ' . $uid,
        'slug'            => 'clinic-a-red1-' . $uid,
        'timezone'        => 'Asia/Tehran',
        'created_at'      => $now,
        'updated_at'      => $now,
    ], 'clinic A');

    $fixture['clinicBId'] = $insertRow('cpms_clinics', [
        'organization_id' => $fixture['orgId'],
        'name'            => 'Clinic B RED1 ' . $uid,
        'slug'            => 'clinic-b-red1-' . $uid,
        'timezone'        => 'Asia/Tehran',
        'created_at'      => $now,
        'updated_at'      => $now,
    ], 'clinic B');

    $locationA = $insertRow('cpms_locations', [
        'clinic_id'  => $fixture['clinicAId'],
        'name'       => 'Loc A RED1 ' . $uid,
        'slug'       => 'loc-a-red1-' . $uid,
        'timezone'   => 'Asia/Tehran',
        'is_primary' => 1,
        'is_active'  => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ], 'location A');

    $locationB = $insertRow('cpms_locations', [
        'clinic_id'  => $fixture['clinicBId'],
        'name'       => 'Loc B RED1 ' . $uid,
        'slug'       => 'loc-b-red1-' . $uid,
        'timezone'   => 'Asia/Tehran',
        'is_primary' => 1,
        'is_active'  => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ], 'location B');

    if ($locationA <= 0 || $locationB <= 0 || $locationA === $locationB) {
        throw new RuntimeException('location fixture invalid: A=' . $locationA . ' B=' . $locationB);
    }

    // The clinician whose Clinic is the trusted, PERSISTED owner of the A1/A4
    // policy that must be applied.
    $fixture['clinicianB'] = $insertRow('cpms_clinicians', [
        'clinic_id'  => $fixture['clinicBId'],
        'full_name'  => 'Dr. ClinicB RED1 ' . $uid,
        'is_active'  => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ], 'clinician B');

    // A genuinely free, genuinely future slot in Clinic B (Location-local
    // wall-clock, 5 days ahead — unambiguous under every policy this
    // repository configures).
    $local = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Tehran'))
        ->add(new DateInterval('P5D'));
    $date  = $local->format('Y-m-d');
    $time  = $local->format('H:i') . ':00';

    $slotId = $insertRow('cpms_schedule_slots', [
        'clinic_id'     => $fixture['clinicBId'],
        'location_id'   => $locationB,
        'clinician_id'  => $fixture['clinicianB'],
        'slot_date'     => $date,
        'slot_time'     => $time,
        'duration_min'  => 20,
        'capacity'      => 1,
        'booked_count'  => 0,
        'held_count'    => 0,
        'is_open'       => 1,
        'generated_from' => 'manual',
        'created_at'    => $now,
        'updated_at'    => $now,
    ], 'slot B');

    $fixture['slotB'] = ['slot_id' => $slotId, 'date' => $date, 'time' => $time];

    return 'org=' . $fixture['orgId']
        . ' clinicA=' . $fixture['clinicAId']
        . ' clinicB=' . $fixture['clinicBId']
        . ' clinicianB=' . $fixture['clinicianB']
        . ' slotB=' . $slotId . ' (' . $date . ' ' . $time . ' Asia/Tehran)';
});

// ============================================================================
// S4 — preconditions: 2+ Clinics, NO ambient scope, NO warm system resolution
// ============================================================================

$run('S4', 'preconditions: 2+ real Clinics, no ambient current Clinic, cold system resolver', static function () use ($fixture): string {
    $db    = App::db();
    $count = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));

    if ($count < 2) {
        throw new RuntimeException('precondition failed: clinic count is ' . $count . ' (need >= 2)');
    }
    if ($fixture['clinicAId'] <= 1 || $fixture['clinicBId'] <= 1) {
        throw new RuntimeException(
            'precondition failed: fixture Clinic ids must be non-trivial (> 1); A='
            . $fixture['clinicAId'] . ' B=' . $fixture['clinicBId']
        );
    }
    if ($fixture['clinicAId'] === $fixture['clinicBId']) {
        throw new RuntimeException('precondition failed: the two fixture Clinics must be distinct');
    }
    if (ScopeContext::tryGet() !== null) {
        throw new RuntimeException('precondition failed: an explicit ClinicScope is already bound (ScopeContext)');
    }
    if (get_current_user_id() !== 0) {
        throw new RuntimeException('precondition failed: current user is ' . get_current_user_id() . ', not anonymous');
    }

    // READ-ONLY proof of process freshness: the system resolver has not yet
    // been asked to guess a Clinic for us. The cache is never flushed here.
    $property = new ReflectionProperty(SystemClinicResolver::class, 'cached');
    $property->setAccessible(true);
    if ($property->getValue() !== null) {
        throw new RuntimeException(
            'precondition failed: SystemClinicResolver already resolved an ambient Clinic in this process'
        );
    }

    return 'clinic_count=' . $count . ' (>1) · ScopeContext=null · current_user=0 · SystemClinicResolver cache=cold';
});

// ============================================================================
// S5 — REST bootstrap must be SCOPE-NEUTRAL (the expected failure point)
// ============================================================================

$run('S5', 'rest_api_init / REST server registration completes with no ambient Clinic', static function (): string {
    App::boot();

    // Nothing before this line has constructed a REST controller, the REST
    // server, or any memoized Clinic-bound service: this is the first — and
    // only — bootstrap the process performs.
    rest_get_server();

    return 'rest_api_init completed; all clinic/v1 controllers registered';
});

// ============================================================================
// S6 — /clinic/v1/health must be reachable
// ============================================================================

$routeRegistered = static function (string $route): void {
    $routes = rest_get_server()->get_routes();
    if (!isset($routes[$route])) {
        throw new RuntimeException(
            'route ' . $route . ' is NOT registered — REST bootstrap aborted before routing (see S5)'
        );
    }
};

$run('S6', 'GET /clinic/v1/health is reachable (HTTP 200)', static function () use ($routeRegistered): string {
    $routeRegistered('/clinic/v1/health');

    $response = rest_do_request(new WP_REST_Request('GET', '/clinic/v1/health'));
    $status   = $response->get_status();
    if ($status !== 200) {
        throw new RuntimeException(
            'health returned HTTP ' . $status . ' — ' . wp_json_encode($response->get_data())
        );
    }

    return 'HTTP 200 · ' . wp_json_encode($response->get_data());
});

// ============================================================================
// S7 — anonymous A1 for the persisted Clinic B clinician must reach the route
// ============================================================================

$run('S7', 'anonymous A1 GET /clinic/v1/availability for the Clinic B clinician reaches the product route', static function () use ($fixture, $routeRegistered): string {
    $routeRegistered('/clinic/v1/availability');

    wp_set_current_user(0);

    $request = new WP_REST_Request('GET', '/clinic/v1/availability');
    $request->set_param('clinician_id', $fixture['clinicianB']);

    $response = rest_do_request($request);
    $status   = $response->get_status();
    $data     = $response->get_data();
    $code     = is_array($data) ? (string) ($data['code'] ?? '') : '';

    if ($status === 404 && $code === 'rest_no_route') {
        throw new RuntimeException(
            'A1 never reached the product route: REST bootstrap aborted before routing (see S5)'
        );
    }

    return 'HTTP ' . $status . ' · code=' . ($code === '' ? '(none — normal envelope)' : $code)
        . ' · body=' . substr((string) wp_json_encode($data), 0, 220);
});

// ============================================================================
// S8 — anonymous A4 for Clinic B must reach a normal product envelope
// ============================================================================

$run('S8', 'anonymous A4 POST /clinic/v1/booking/quote for Clinic B reaches a normal product envelope', static function () use ($fixture, $routeRegistered): string {
    $routeRegistered('/clinic/v1/booking/quote');

    wp_set_current_user(0);

    $request = new WP_REST_Request('POST', '/clinic/v1/booking/quote');
    $request->set_param('clinician_id', $fixture['clinicianB']);
    $request->set_param('slot_id', $fixture['slotB']['slot_id']);
    $request->set_param('slot_date', $fixture['slotB']['date']);
    $request->set_param('slot_time', $fixture['slotB']['time']);

    $response = rest_do_request($request);
    $status   = $response->get_status();
    $data     = $response->get_data();
    $code     = is_array($data) ? (string) ($data['code'] ?? '') : '';

    if ($status === 404 && $code === 'rest_no_route') {
        throw new RuntimeException(
            'A4 never reached the product route: REST bootstrap aborted before routing (see S5)'
        );
    }

    return 'HTTP ' . $status . ' · code=' . ($code === '' ? '(none — normal envelope)' : $code)
        . ' · body=' . substr((string) wp_json_encode($data), 0, 220);
});

// ============================================================================
// 6) Verdict
// ============================================================================

$failed = 0;
foreach ($results as $result) {
    if (!$result['ok']) {
        $failed++;
    }
}

$out('');
$out('=== RED 1 summary ===');
$out('steps: ' . count($results) . ' · passed: ' . (count($results) - $failed) . ' · failed: ' . $failed);
if ($firstFailure !== null) {
    $out('FIRST FAILING STEP: ' . $firstFailure);
}
$out('VERDICT: ' . ($failed === 0
    ? 'NO RED — REST bootstrap was scope-neutral at this head (report honestly, do not claim RED)'
    : 'RED reproduced — process-fresh multi-Clinic REST bootstrap fails at ' . $firstFailure));

exit($failed === 0 ? 0 : 1);

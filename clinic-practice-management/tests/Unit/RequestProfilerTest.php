<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Profiling\RequestProfiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Request-level profiler contract — Phase 17 comparative profiling (measurement only).
 *
 * Contract `cpms.req-profile/1` (frozen for this slice):
 *  - arming is server-side ONLY (`CPMS_PROFILE=1` in `$_SERVER`/environment, as set
 *    by Apache `SetEnv` on the disposable Pilot runner); no request input
 *    (param/header/cookie/body) can arm it;
 *  - four markers per request: boot → api_init → dispatch → respond, monotonic clock;
 *  - the `X-CPMS-Profile` header carries exactly the 18 allowlisted keys, numerics
 *    plus the endpoint token plus the schema constant — no SQL, no values, no PHI,
 *    no paths, no headers/cookies/nonces;
 *  - totals equal segment sums; any incomplete/invalid state yields NO header
 *    (fail closed) and the response is returned unmodified.
 *
 * RED: `RequestProfiler` does not exist yet — every test here must fail until the
 * minimum GREEN implementation lands.
 */
final class RequestProfilerTest extends TestCase
{
    /** @var array{server: mixed, env: mixed} */
    private array $savedEnv = ['server' => null, 'env' => false];

    private bool $hadServerKey = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadServerKey = array_key_exists('CPMS_PROFILE', $_SERVER);
        $this->savedEnv = [
            'server' => $_SERVER['CPMS_PROFILE'] ?? null,
            'env' => getenv('CPMS_PROFILE'),
        ];
        unset($_SERVER['CPMS_PROFILE']);
        putenv('CPMS_PROFILE');
        RequestProfiler::reset();
    }

    protected function tearDown(): void
    {
        if ($this->hadServerKey) {
            $_SERVER['CPMS_PROFILE'] = $this->savedEnv['server'];
        } else {
            unset($_SERVER['CPMS_PROFILE']);
        }
        if ($this->savedEnv['env'] === false) {
            putenv('CPMS_PROFILE');
        } else {
            putenv('CPMS_PROFILE=' . (string) $this->savedEnv['env']);
        }
        unset($_GET['cpms_profile'], $_POST['cpms_profile'], $_COOKIE['cpms_profile'], $_REQUEST['cpms_profile']);
        unset($_SERVER['HTTP_X_CPMS_PROFILE'], $_SERVER['QUERY_STRING']);
        RequestProfiler::reset();
        parent::tearDown();
    }

    public function testDisarmedByDefault(): void
    {
        $this->assertFalse(RequestProfiler::armed());
        $this->assertNull(RequestProfiler::snapshot());
        $this->assertSame('', RequestProfiler::header_value());
        $this->assertSame(0, RequestProfiler::cpms_query_count());
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonArmingFlagProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => ['0'];
        yield 'empty' => [''];
        yield 'true-word' => ['true'];
        yield 'yes-word' => ['yes'];
        yield 'two' => ['2'];
        yield 'one-with-space' => ['1 '];
        yield 'integer-one' => [1];
        yield 'bool-true' => [true];
    }

    #[DataProvider('nonArmingFlagProvider')]
    public function testArmsOnlyViaExactServerSideFlag(mixed $flag): void
    {
        if ($flag !== null) {
            $_SERVER['CPMS_PROFILE'] = $flag;
        }
        RequestProfiler::reset();

        $this->assertFalse(RequestProfiler::armed(), 'flag must not arm: ' . var_export($flag, true));
        $this->assertNull(RequestProfiler::snapshot());
    }

    public function testArmsViaServerFlag(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        $this->assertTrue(RequestProfiler::armed());
    }

    public function testArmsViaEnvironmentFallback(): void
    {
        putenv('CPMS_PROFILE=1');
        RequestProfiler::reset();

        $this->assertTrue(RequestProfiler::armed());
    }

    public function testRequestInputsCannotArm(): void
    {
        $_GET['cpms_profile'] = '1';
        $_POST['cpms_profile'] = '1';
        $_COOKIE['cpms_profile'] = '1';
        $_REQUEST['cpms_profile'] = '1';
        $_SERVER['HTTP_X_CPMS_PROFILE'] = '1';
        $_SERVER['QUERY_STRING'] = 'cpms_profile=1';
        RequestProfiler::reset();

        $this->assertFalse(RequestProfiler::armed());
        $this->assertNull(RequestProfiler::snapshot());
    }

    public function testArmingIsMemoizedUntilReset(): void
    {
        $this->assertFalse(RequestProfiler::armed());

        $_SERVER['CPMS_PROFILE'] = '1';
        $this->assertFalse(RequestProfiler::armed(), 'memoized disarmed state must win until reset()');

        RequestProfiler::reset();
        $this->assertTrue(RequestProfiler::armed());
    }

    public function testEndpointMappingAllowlist(): void
    {
        $this->assertSame('health', RequestProfiler::endpoint_for_route('/clinic/v1/health'));
        $this->assertSame('availability', RequestProfiler::endpoint_for_route('/clinic/v1/availability'));
        $this->assertSame('wp-json-root', RequestProfiler::endpoint_for_route('/'));

        $adversarial = [
            null,
            '',
            '/clinic/v1/health/',
            '/CLINIC/V1/HEALTH',
            '/clinic/v1/health?cpms_profile=1',
            '/clinic/v1/availability ',
            ' /',
            '//',
            '/wp-json/',
            '/clinic/v1/../v1/health',
            "/clinic/v1/health\x00",
            "/clinic/v1/health\nX-Injected: 1",
            '/clinic/v1/queue',
            'SELECT * FROM wp_users',
            str_repeat('a', 5000),
        ];
        foreach ($adversarial as $i => $route) {
            $mapped = RequestProfiler::endpoint_for_route($route);
            $this->assertSame('other', $mapped, "adversarial route #{$i} must map to 'other'");
            $this->assertContains(
                $mapped,
                ['health', 'availability', 'wp-json-root', 'other'],
                "mapped endpoint #{$i} must stay inside the allowlist"
            );
        }
    }

    public function testSnapshotNullWhenDisarmedEvenWithMarkers(): void
    {
        RequestProfiler::mark('boot', 1000, 0, 0, 10);
        RequestProfiler::mark('api_init', 2000, 1, 500, 12);
        RequestProfiler::mark('dispatch', 3000, 2, 900, 14);
        RequestProfiler::mark('respond', 4000, 2, 900, 15);

        $this->assertNull(RequestProfiler::snapshot());
        $this->assertSame('', RequestProfiler::header_value());
    }

    public function testSnapshotNullWhenMarkersIncomplete(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1000, 0, 0, 10);
        RequestProfiler::mark('dispatch', 3000, 2, 900, 14);
        RequestProfiler::mark('respond', 4000, 2, 900, 15);

        $this->assertNull(RequestProfiler::snapshot(), 'missing api_init must refuse the snapshot');
    }

    public function testSnapshotInvariantTotals(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        // t in ns; cpms_q; cpms_db_ns; wp_q.
        RequestProfiler::mark('boot', 1_000_000, 0, 0, 10);
        RequestProfiler::mark('api_init', 6_000_000, 24, 3_000_000, 40);
        RequestProfiler::mark('dispatch', 9_000_000, 24, 3_000_000, 44);
        RequestProfiler::mark('respond', 21_000_000, 30, 7_500_000, 52);

        $snapshot = RequestProfiler::snapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame('cpms.req-profile/1', $snapshot['schema']);
        $this->assertSame('other', $snapshot['endpoint']);

        $this->assertSame(5.0, $snapshot['t_boot_ms']);
        $this->assertSame(3.0, $snapshot['t_init_ms']);
        $this->assertSame(12.0, $snapshot['t_dispatch_ms']);
        $this->assertSame(20.0, $snapshot['t_total_ms']);

        $this->assertSame(24, $snapshot['cpms_q_boot']);
        $this->assertSame(0, $snapshot['cpms_q_init']);
        $this->assertSame(6, $snapshot['cpms_q_dispatch']);
        $this->assertSame(30, $snapshot['cpms_q']);

        $this->assertSame(3.0, $snapshot['cpms_db_ms_boot']);
        $this->assertSame(0.0, $snapshot['cpms_db_ms_init']);
        $this->assertSame(4.5, $snapshot['cpms_db_ms_dispatch']);
        $this->assertSame(7.5, $snapshot['cpms_db_ms']);

        $this->assertSame(30, $snapshot['wp_q_boot']);
        $this->assertSame(4, $snapshot['wp_q_init']);
        $this->assertSame(8, $snapshot['wp_q_dispatch']);
        $this->assertSame(42, $snapshot['wp_q']);
    }

    public function testSnapshotRefusesClockRegression(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1_000_000, 0, 0, 10);
        RequestProfiler::mark('api_init', 6_000_000, 24, 3_000_000, 40);
        RequestProfiler::mark('dispatch', 9_000_000, 24, 3_000_000, 44);
        RequestProfiler::mark('respond', 8_999_999, 30, 7_500_000, 52);

        $this->assertNull(RequestProfiler::snapshot(), 'a backwards clock must refuse the snapshot');
        $this->assertSame('', RequestProfiler::header_value());
    }

    public function testSnapshotRefusesCounterRegression(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1_000_000, 5, 0, 10);
        RequestProfiler::mark('api_init', 6_000_000, 4, 3_000_000, 40);
        RequestProfiler::mark('dispatch', 9_000_000, 4, 3_000_000, 44);
        RequestProfiler::mark('respond', 21_000_000, 4, 3_000_000, 52);

        $this->assertNull(RequestProfiler::snapshot(), 'a regressing query counter must refuse the snapshot');
    }

    public function testSnapshotRefusesInvalidWpSnapshot(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1_000_000, 0, 0, -1);
        RequestProfiler::mark('api_init', 6_000_000, 0, 0, 40);
        RequestProfiler::mark('dispatch', 9_000_000, 0, 0, 44);
        RequestProfiler::mark('respond', 21_000_000, 0, 0, 52);

        $this->assertNull(RequestProfiler::snapshot(), 'an unavailable $wpdb snapshot must refuse, not zero-fill');
    }

    public function testUnknownPhaseIgnored(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('evil', 1_000_000, 0, 0, 10);
        RequestProfiler::mark('boot', 1_000_000, 0, 0, 10);
        RequestProfiler::mark('api_init', 2_000_000, 0, 0, 10);
        RequestProfiler::mark('dispatch', 3_000_000, 0, 0, 10);
        RequestProfiler::mark('respond', 4_000_000, 0, 0, 10);

        $snapshot = RequestProfiler::snapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame(3.0, $snapshot['t_total_ms']);
    }

    public function testHeaderRenderExactAllowlistedFieldSet(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1_000_000, 0, 0, 10);
        RequestProfiler::mark('api_init', 2_000_000, 1, 250_000, 11);
        RequestProfiler::mark('dispatch', 3_000_000, 1, 250_000, 12);
        RequestProfiler::mark('respond', 4_000_000, 2, 500_000, 13);

        $header = RequestProfiler::header_value();
        $this->assertNotSame('', $header);
        $this->assertStringNotContainsString("\n", $header);
        $this->assertStringNotContainsString("\r", $header);
        $this->assertLessThan(2048, strlen($header));

        $decoded = json_decode($header, true);
        $this->assertIsArray($decoded);
        $expectedKeys = [
            'schema', 'endpoint',
            't_total_ms', 't_boot_ms', 't_init_ms', 't_dispatch_ms',
            'cpms_q', 'cpms_q_boot', 'cpms_q_init', 'cpms_q_dispatch',
            'cpms_db_ms', 'cpms_db_ms_boot', 'cpms_db_ms_init', 'cpms_db_ms_dispatch',
            'wp_q', 'wp_q_boot', 'wp_q_init', 'wp_q_dispatch',
        ];
        sort($expectedKeys);
        $actualKeys = array_keys($decoded);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        foreach (
            [
                't_total_ms', 't_boot_ms', 't_init_ms', 't_dispatch_ms',
                'cpms_db_ms', 'cpms_db_ms_boot', 'cpms_db_ms_init', 'cpms_db_ms_dispatch',
            ] as $field
        ) {
            $this->assertIsNumeric($decoded[$field], $field . ' must be numeric');
            $this->assertGreaterThanOrEqual(0, $decoded[$field]);
            $this->assertTrue(is_finite((float) $decoded[$field]), $field . ' must be finite');
        }
        foreach (
            [
                'cpms_q', 'cpms_q_boot', 'cpms_q_init', 'cpms_q_dispatch',
                'wp_q', 'wp_q_boot', 'wp_q_init', 'wp_q_dispatch',
            ] as $field
        ) {
            $this->assertIsInt($decoded[$field], $field . ' must be an integer');
            $this->assertGreaterThanOrEqual(0, $decoded[$field]);
        }

        // NOTE: the allowlisted key names `wp_q*` are asserted exactly above; the
        // leak scan below covers everything else (no SQL/data/secret-shaped content).
        foreach (
            [
                'SELECT', 'select', 'FROM', 'WHERE', 'clinic/v1', 'wp-',
                'Cookie', 'cookie', 'nonce', 'Nonce', 'Bearer', 'password', 'secret',
                'Authorization', '/home', 'C:\\', '<?php', '$_SERVER', 'HTTP_',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $header, 'header must not leak: ' . $forbidden);
        }
    }

    public function testQueryObservationDisarmedIsNoop(): void
    {
        $this->assertNull(RequestProfiler::query_start());
        RequestProfiler::query_end(null);
        RequestProfiler::query_end(123456);
        $this->assertSame(0, RequestProfiler::cpms_query_count());
        $this->assertSame(0, RequestProfiler::cpms_db_ns());
    }

    public function testQueryObservationAccumulatesWhenArmed(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1_000_000, null, null, 10);
        RequestProfiler::mark('api_init', 2_000_000, null, null, 11);
        RequestProfiler::mark('dispatch', 3_000_000, null, null, 12);
        for ($i = 0; $i < 3; $i++) {
            $start = RequestProfiler::query_start();
            $this->assertIsInt($start);
            RequestProfiler::query_end($start);
        }
        $this->assertSame(3, RequestProfiler::cpms_query_count());
        $this->assertGreaterThanOrEqual(0, RequestProfiler::cpms_db_ns());
        RequestProfiler::mark('respond', 4_000_000, null, null, 13);

        $snapshot = RequestProfiler::snapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame(3, $snapshot['cpms_q_dispatch']);
        $this->assertSame(3, $snapshot['cpms_q']);
        $this->assertSame(0, $snapshot['cpms_q_boot']);
        $this->assertSame(0, $snapshot['cpms_q_init']);
    }

    public function testResetClearsEverything(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        RequestProfiler::reset();

        RequestProfiler::mark('boot', 1_000_000, 0, 0, 10);
        $start = RequestProfiler::query_start();
        RequestProfiler::query_end($start);
        $this->assertSame(1, RequestProfiler::cpms_query_count());

        RequestProfiler::reset();

        $this->assertSame(0, RequestProfiler::cpms_query_count());
        $this->assertSame(0, RequestProfiler::cpms_db_ns());
        $this->assertNull(RequestProfiler::snapshot());
        // Re-arming still resolves from the (still set) server flag.
        $this->assertTrue(RequestProfiler::armed());
    }
}

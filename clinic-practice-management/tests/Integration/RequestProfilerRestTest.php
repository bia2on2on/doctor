<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Profiling\RequestProfiler;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * REST wiring of the request-level profiler — Phase 17 comparative profiling.
 *
 * Proves, against real WordPress dispatch:
 *  - the profiler hooks are registered by `App::boot()` and are silent unless armed;
 *  - arming is server-side only (no request param/header can arm it);
 *  - an armed `/health` dispatch emits exactly the `cpms.req-profile/1` header and a
 *    byte-identical body (zero behavior change);
 *  - the WP-baseline `/` dispatch attributes zero CPMS-layer queries to its dispatch
 *    window (layer attribution is honest);
 *  - authorization/error envelopes are byte-identical armed vs disarmed;
 *  - every `CpmsDb` query entry point is counted exactly once when armed.
 *
 * RED: `RequestProfiler` does not exist yet — every test here must fail until the
 * minimum GREEN implementation lands.
 */
final class RequestProfilerRestTest extends WP_UnitTestCase
{
    private const HEADER = 'X-CPMS-Profile';

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
        wp_set_current_user(0);
        App::migrations()->migrate();
        do_action('rest_api_init');
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
        RequestProfiler::reset();
        parent::tearDown();
    }

    /**
     * Simulate a profiled request start exactly as production does: the server-side
     * flag is present before boot, the boot marker restarts the profile, and
     * `rest_api_init` fires before dispatch.
     */
    private function arm(): void
    {
        $_SERVER['CPMS_PROFILE'] = '1';
        putenv('CPMS_PROFILE=1');
        RequestProfiler::reset();
        RequestProfiler::markBoot();
        do_action('rest_api_init');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $params
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        $response = rest_do_request($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);

        return $response;
    }

    public function testProfilerHooksAreRegistered(): void
    {
        $this->assertSame(1, has_filter('rest_api_init', [RequestProfiler::class, 'markApiInit']));
        $this->assertSame(1, has_filter('rest_pre_dispatch', [RequestProfiler::class, 'markDispatch']));
        $this->assertSame(999, has_filter('rest_post_dispatch', [RequestProfiler::class, 'attachProfile']));
    }

    public function testDisarmedDispatchEmitsNoHeader(): void
    {
        $response = $this->dispatch('GET', '/clinic/v1/health');

        $this->assertSame(200, $response->get_status());
        $this->assertArrayNotHasKey(self::HEADER, $response->get_headers());
    }

    public function testArmedHealthDispatchEmitsValidHeaderAndIdenticalBody(): void
    {
        $plain = $this->dispatch('GET', '/clinic/v1/health');
        $this->assertSame(200, $plain->get_status());

        $this->arm();
        $profiled = $this->dispatch('GET', '/clinic/v1/health');
        $this->assertSame(200, $profiled->get_status());

        $plainData = $plain->get_data();
        $profiledData = $profiled->get_data();
        $this->assertIsArray($plainData);
        $this->assertIsArray($profiledData);
        // `time_utc` is second-precision wall time — the only legitimately varying key.
        unset($plainData['data']['time_utc'], $profiledData['data']['time_utc']);
        $this->assertSame($plainData, $profiledData, 'profiling must not change the response body');

        $headers = $profiled->get_headers();
        $this->assertArrayHasKey(self::HEADER, $headers);
        $profile = json_decode((string) $headers[self::HEADER], true);
        $this->assertIsArray($profile);

        $expectedKeys = [
            'schema', 'endpoint',
            't_total_ms', 't_boot_ms', 't_init_ms', 't_dispatch_ms',
            'cpms_q', 'cpms_q_boot', 'cpms_q_init', 'cpms_q_dispatch',
            'cpms_db_ms', 'cpms_db_ms_boot', 'cpms_db_ms_init', 'cpms_db_ms_dispatch',
            'wp_q', 'wp_q_boot', 'wp_q_init', 'wp_q_dispatch',
        ];
        sort($expectedKeys);
        $actualKeys = array_keys($profile);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        $this->assertSame('cpms.req-profile/1', $profile['schema']);
        $this->assertSame('health', $profile['endpoint']);
        $this->assertGreaterThan(0, $profile['cpms_q']);
        $this->assertGreaterThanOrEqual($profile['cpms_q'], $profile['wp_q']);
        $this->assertSame(
            $profile['cpms_q_boot'] + $profile['cpms_q_init'] + $profile['cpms_q_dispatch'],
            $profile['cpms_q']
        );
        $this->assertSame(
            $profile['wp_q_boot'] + $profile['wp_q_init'] + $profile['wp_q_dispatch'],
            $profile['wp_q']
        );
        $this->assertEqualsWithDelta(
            $profile['t_boot_ms'] + $profile['t_init_ms'] + $profile['t_dispatch_ms'],
            $profile['t_total_ms'],
            0.002
        );
        foreach (['t_total_ms', 't_boot_ms', 't_init_ms', 't_dispatch_ms'] as $field) {
            $this->assertGreaterThanOrEqual(0, $profile[$field]);
        }
    }

    public function testIndexDispatchAttributesZeroCpmsDispatchQueries(): void
    {
        $this->arm();
        $response = $this->dispatch('GET', '/');

        $this->assertSame(200, $response->get_status());
        $headers = $response->get_headers();
        $this->assertArrayHasKey(self::HEADER, $headers);
        $profile = json_decode((string) $headers[self::HEADER], true);
        $this->assertIsArray($profile);
        $this->assertSame('wp-json-root', $profile['endpoint']);
        $this->assertSame(0, $profile['cpms_q_dispatch'], 'the WP index controller issues no CPMS-layer queries');
        $this->assertGreaterThan(0, $profile['cpms_q_init'], 'bootstrap work must be attributed to the init segment');
    }

    public function testRequestVectorsCannotArm(): void
    {
        $response = $this->dispatch(
            'GET',
            '/clinic/v1/health',
            ['cpms_profile' => '1'],
            ['X-CPMS-Profile' => '1']
        );

        $this->assertSame(200, $response->get_status());
        $this->assertArrayNotHasKey(
            self::HEADER,
            $response->get_headers(),
            'request params/headers must never arm the profiler'
        );
    }

    public function testErrorEnvelopeUnchangedWhenArmed(): void
    {
        $plain = $this->dispatch('GET', '/clinic/v1/queue');
        $this->assertContains($plain->get_status(), [401, 403]);

        $this->arm();
        $profiled = $this->dispatch('GET', '/clinic/v1/queue');

        $this->assertSame($plain->get_status(), $profiled->get_status());
        $this->assertSame($plain->get_data(), $profiled->get_data());
    }

    public function testCpmsDbCountsEveryQueryMethodExactlyOnce(): void
    {
        $this->arm();
        $db = App::db();
        $table = $db->table('tmp_req_prof');
        $db->query("DROP TABLE IF EXISTS {$table}");
        $db->query("CREATE TABLE {$table} (id INT, name VARCHAR(32))");
        try {
            $this->assertQueryDelta(1, static fn () => $db->fetchAll("SELECT * FROM {$table}"));
            $this->assertQueryDelta(1, static fn () => $db->fetchRow("SELECT * FROM {$table} LIMIT 1"));
            $this->assertQueryDelta(1, static fn () => $db->fetchValue('SELECT 1'));
            $this->assertQueryDelta(1, static fn () => $db->query('SELECT 1'));
            $this->assertQueryDelta(1, static fn () => $db->execute('SELECT 1'));
            $this->assertQueryDelta(1, static fn () => $db->insert('tmp_req_prof', ['id' => '1', 'name' => 'a']));
            $this->assertQueryDelta(1, static fn () => $db->update('tmp_req_prof', ['name' => 'b'], ['id' => '1']));
            $this->assertQueryDelta(1, static fn () => $db->fetchRowForUpdate("SELECT * FROM {$table} LIMIT 1"));
            $this->assertQueryDelta(1, static fn () => $db->fetchAllForUpdate("SELECT * FROM {$table}"));
            $this->assertQueryDelta(1, static fn () => $db->delete('tmp_req_prof', ['id' => '1']));

            // One transaction = START + inner statement + COMMIT.
            $this->assertQueryDelta(3, static function () use ($db) {
                $db->transactional(static fn () => $db->fetchValue('SELECT 1'));
            });

            // Rollback path = START + inner statement + ROLLBACK.
            try {
                $before = RequestProfiler::cpmsQueryCount();
                $db->transactional(static function () use ($db) {
                    $db->fetchValue('SELECT 1');
                    throw new \RuntimeException('rollback probe');
                });
                $this->fail('transactional() must rethrow');
            } catch (\RuntimeException $e) {
                $this->assertSame('rollback probe', $e->getMessage());
                $this->assertSame(3, RequestProfiler::cpmsQueryCount() - $before);
            }
        } finally {
            $db->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertQueryDelta(int $expected, callable $call): void
    {
        $before = RequestProfiler::cpmsQueryCount();
        $call();
        $this->assertSame($expected, RequestProfiler::cpmsQueryCount() - $before);
    }
}

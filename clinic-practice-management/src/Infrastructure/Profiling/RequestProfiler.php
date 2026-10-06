<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Profiling;

/**
 * Request-level bounded profiler — Phase 17 comparative profiling (measurement only).
 *
 * Contract `cpms.req-profile/1` (emitted as the `X-CPMS-Profile` response header):
 *  - Arming is server-side ONLY: `CPMS_PROFILE=1` in `$_SERVER`/environment (set via
 *    Apache `SetEnv` on the disposable Pilot runner during the profiling pass). No
 *    request input — param, header, cookie, body — can arm it, and ordinary
 *    production requests can never observe it.
 *  - Four markers per request: `boot` (plugin load) → `api_init` (`rest_api_init`) →
 *    `dispatch` (`rest_pre_dispatch`) → `respond` (`rest_post_dispatch`). Times use a
 *    monotonic clock where available (`hrtime()`), `microtime()` fallback otherwise.
 *  - Each marker snapshots the CPMS-layer query count + aggregate DB time (observed
 *    by `CpmsDb` at its `$wpdb` choke points) and the `$wpdb->num_queries` total,
 *    so the evidence carries both the CPMS layer and the WordPress total per segment.
 *  - The header carries exactly the allowlisted keys: numerics plus the endpoint
 *    token plus the schema constant. No SQL text, no bind values, no PHI, no
 *    request/response bodies, no headers, no cookies/nonces, no stack traces, no
 *    filesystem paths, no environment dumps.
 *  - Fail closed: incomplete markers, a regressing clock/counter, an unavailable
 *    `$wpdb` snapshot, or a non-`WP_REST_Response` result yields NO header and the
 *    response is returned byte-identical. Authorization, tenancy and envelope
 *    behavior are never touched.
 *
 * Disarmed cost is one memoized static check per hook/query call-site — no timing,
 * no snapshots, no behavior change.
 */
final class RequestProfiler
{
    public const SCHEMA = 'cpms.req-profile/1';

    public const HEADER = 'X-CPMS-Profile';

    public const ENV_FLAG = 'CPMS_PROFILE';

    /** @var list<string> */
    private const PHASES = ['boot', 'api_init', 'dispatch', 'respond'];

    /** Exact route → published endpoint token. Anything else maps to `other` (never echoed). */
    private const ENDPOINTS = [
        '/clinic/v1/health' => 'health',
        '/clinic/v1/availability' => 'availability',
        '/' => 'wp-json-root',
    ];

    private static ?bool $armed = null;

    private static string $endpoint = 'other';

    /** @var array<string, array{t_ns: int, cpms_q: int, cpms_db_ns: int, wp_q: int}> */
    private static array $markers = [];

    private static int $cpmsQueries = 0;

    private static int $cpmsDbNs = 0;

    /**
     * Whether profiling is armed for this request (memoized; `reset()` clears it).
     */
    public static function armed(): bool
    {
        if (self::$armed === null) {
            $flag = $_SERVER[self::ENV_FLAG] ?? getenv(self::ENV_FLAG);
            self::$armed = $flag === '1';
        }

        return self::$armed;
    }

    /**
     * Clear memoized arming, markers, endpoint and counters (request restart / tests).
     */
    public static function reset(): void
    {
        self::$armed = null;
        self::$endpoint = 'other';
        self::$markers = [];
        self::$cpmsQueries = 0;
        self::$cpmsDbNs = 0;
    }

    /**
     * Monotonic nanoseconds where available, wall-clock fallback otherwise.
     */
    public static function nowNs(): int
    {
        if (function_exists('hrtime')) {
            return (int) hrtime(true);
        }

        return (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * Record a phase marker. Explicit sample values are the test seam; production
     * callers pass none and the live clock/counters are sampled. Unknown phases are
     * ignored (the snapshot still requires the four valid markers, so ignoring is
     * fail-closed).
     */
    public static function mark(
        string $phase,
        ?int $tNs = null,
        ?int $cpmsQ = null,
        ?int $cpmsDbNs = null,
        ?int $wpQ = null
    ): void {
        if (!self::armed() || !in_array($phase, self::PHASES, true)) {
            return;
        }
        self::$markers[$phase] = [
            't_ns' => $tNs ?? self::nowNs(),
            'cpms_q' => $cpmsQ ?? self::$cpmsQueries,
            'cpms_db_ns' => $cpmsDbNs ?? self::$cpmsDbNs,
            'wp_q' => $wpQ ?? self::wpQueryCount(),
        ];
    }

    /**
     * Start of a profiled request (called from `App::boot()`): restart the profile
     * and record the boot marker.
     */
    public static function markBoot(): void
    {
        if (!self::armed()) {
            return;
        }
        self::$endpoint = 'other';
        self::$markers = [];
        self::$cpmsQueries = 0;
        self::$cpmsDbNs = 0;
        self::mark('boot');
    }

    /**
     * `rest_api_init` @ priority 1 — before CPMS route registration + migration check.
     */
    public static function markApiInit(): void
    {
        self::mark('api_init');
    }

    /**
     * `rest_pre_dispatch` @ priority 1 — maps the route to the allowlisted endpoint
     * token, records the dispatch marker, and returns the result untouched.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function markDispatch($result, $server, $request)
    {
        if (self::armed() && $request instanceof \WP_REST_Request) {
            $route = $request->get_route();
            self::$endpoint = self::endpointForRoute(is_string($route) ? $route : null);
            self::mark('dispatch');
        }

        return $result;
    }

    /**
     * `rest_post_dispatch` @ priority 999 — records the respond marker and attaches
     * the header to `WP_REST_Response` results only. Anything else (errors, missing
     * data) is returned unmodified.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function attachProfile($result, $server, $request)
    {
        if (!self::armed()) {
            return $result;
        }
        self::mark('respond');
        if (!$result instanceof \WP_REST_Response) {
            return $result;
        }
        $header = self::headerValue();
        if ($header === '') {
            return $result;
        }
        $result->header(self::HEADER, $header);

        return $result;
    }

    /**
     * Map a REST route to the published endpoint token (exact allowlist, no echo).
     */
    public static function endpointForRoute(?string $route): string
    {
        return self::ENDPOINTS[$route ?? ''] ?? 'other';
    }

    /**
     * Begin observing one CPMS-layer query. Returns null unless armed (callers pass
     * the token straight to `queryEnd()`).
     */
    public static function queryStart(): ?int
    {
        return self::armed() ? self::nowNs() : null;
    }

    /**
     * Finish observing one CPMS-layer query (count +1, accumulate wall time).
     */
    public static function queryEnd(?int $startNs): void
    {
        if ($startNs === null || !self::armed()) {
            return;
        }
        self::$cpmsQueries++;
        $elapsed = self::nowNs() - $startNs;
        self::$cpmsDbNs += $elapsed > 0 ? $elapsed : 0;
    }

    public static function cpmsQueryCount(): int
    {
        return self::$cpmsQueries;
    }

    public static function cpmsDbNs(): int
    {
        return self::$cpmsDbNs;
    }

    /**
     * The validated allowlisted snapshot, or null when anything is incomplete or
     * invalid (fail closed — never partial evidence).
     *
     * @return array<string, mixed>|null
     */
    public static function snapshot(): ?array
    {
        if (!self::armed()) {
            return null;
        }
        foreach (self::PHASES as $phase) {
            if (!isset(self::$markers[$phase])) {
                return null;
            }
        }
        $boot = self::$markers['boot'];
        $init = self::$markers['api_init'];
        $dispatch = self::$markers['dispatch'];
        $respond = self::$markers['respond'];
        foreach ([$boot, $init, $dispatch, $respond] as $marker) {
            if ($marker['t_ns'] < 0 || $marker['cpms_q'] < 0 || $marker['cpms_db_ns'] < 0 || $marker['wp_q'] < 0) {
                return null;
            }
        }
        foreach (['t_ns', 'cpms_q', 'cpms_db_ns', 'wp_q'] as $key) {
            if ($init[$key] < $boot[$key] || $dispatch[$key] < $init[$key] || $respond[$key] < $dispatch[$key]) {
                return null;
            }
        }

        $tBoot = self::ms($init['t_ns'] - $boot['t_ns']);
        $tInit = self::ms($dispatch['t_ns'] - $init['t_ns']);
        $tDispatch = self::ms($respond['t_ns'] - $dispatch['t_ns']);
        $dbBoot = self::ms($init['cpms_db_ns'] - $boot['cpms_db_ns']);
        $dbInit = self::ms($dispatch['cpms_db_ns'] - $init['cpms_db_ns']);
        $dbDispatch = self::ms($respond['cpms_db_ns'] - $dispatch['cpms_db_ns']);

        return [
            'schema' => self::SCHEMA,
            'endpoint' => self::$endpoint,
            't_total_ms' => round($tBoot + $tInit + $tDispatch, 3),
            't_boot_ms' => $tBoot,
            't_init_ms' => $tInit,
            't_dispatch_ms' => $tDispatch,
            'cpms_q' => $respond['cpms_q'] - $boot['cpms_q'],
            'cpms_q_boot' => $init['cpms_q'] - $boot['cpms_q'],
            'cpms_q_init' => $dispatch['cpms_q'] - $init['cpms_q'],
            'cpms_q_dispatch' => $respond['cpms_q'] - $dispatch['cpms_q'],
            'cpms_db_ms' => round($dbBoot + $dbInit + $dbDispatch, 3),
            'cpms_db_ms_boot' => $dbBoot,
            'cpms_db_ms_init' => $dbInit,
            'cpms_db_ms_dispatch' => $dbDispatch,
            'wp_q' => $respond['wp_q'] - $boot['wp_q'],
            'wp_q_boot' => $init['wp_q'] - $boot['wp_q'],
            'wp_q_init' => $dispatch['wp_q'] - $init['wp_q'],
            'wp_q_dispatch' => $respond['wp_q'] - $dispatch['wp_q'],
        ];
    }

    /**
     * The single-line header payload, or '' when no valid snapshot exists.
     */
    public static function headerValue(): string
    {
        $snapshot = self::snapshot();
        if ($snapshot === null) {
            return '';
        }
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    private static function ms(int $ns): float
    {
        return round($ns / 1_000_000, 3);
    }

    /**
     * The `$wpdb->num_queries` total, or -1 when unavailable (which refuses the
     * snapshot — an unknown total is never zero-filled).
     */
    private static function wpQueryCount(): int
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!$wpdb instanceof \wpdb) {
            return -1;
        }
        $count = $wpdb->num_queries;

        return is_int($count) ? $count : -1;
    }
}

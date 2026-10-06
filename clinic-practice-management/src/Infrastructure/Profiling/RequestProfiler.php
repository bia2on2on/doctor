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
final class RequestProfiler {
    public const SCHEMA = 'cpms.req-profile/1';

    public const HEADER = 'X-CPMS-Profile';

    public const ENV_FLAG = 'CPMS_PROFILE';

    /** @var list<string> */
    private const PHASES = ['boot', 'api_init', 'dispatch', 'respond'];

    /** Exact route → published endpoint token. Anything else maps to `other` (never echoed). */
    private const ENDPOINTS = [
        '/clinic/v1/health'       => 'health',
        '/clinic/v1/availability' => 'availability',
        '/'                       => 'wp-json-root',
    ];

    private static ?bool $armed = null;

    private static string $endpoint = 'other';

    /** @var array<string, array{t_ns: int, cpms_q: int, cpms_db_ns: int, wp_q: int}> */
    private static array $markers = [];

    private static int $cpms_queries = 0;

    private static int $cpms_db_ns = 0;

    /**
     * Whether profiling is armed for this request (memoized; `reset()` clears it).
     */
    public static function armed(): bool {
        if ( self::$armed === null ) {
            $flag        = $_SERVER[ self::ENV_FLAG ] ?? getenv( self::ENV_FLAG );
            self::$armed = $flag === '1';
        }

        return self::$armed;
    }

    /**
     * Clear memoized arming, markers, endpoint and counters (request restart / tests).
     */
    public static function reset(): void {
        self::$armed        = null;
        self::$endpoint     = 'other';
        self::$markers      = [];
        self::$cpms_queries = 0;
        self::$cpms_db_ns   = 0;
    }

    /**
     * Monotonic nanoseconds where available, wall-clock fallback otherwise.
     */
    public static function now_ns(): int {
        if ( function_exists( 'hrtime' ) ) {
            return (int) hrtime( true );
        }

        return (int) ( microtime( true ) * 1_000_000_000 );
    }

    /**
     * Record a phase marker. Explicit sample values are the test seam; production
     * callers pass none and the live clock/counters are sampled. Unknown phases are
     * ignored (the snapshot still requires the four valid markers, so ignoring is
     * fail-closed).
     */
    public static function mark(
        string $phase,
        ?int $t_ns = null,
        ?int $cpms_q = null,
        ?int $cpms_db_ns = null,
        ?int $wp_q = null
    ): void {
        if ( ! self::armed() || ! in_array( $phase, self::PHASES, true ) ) {
            return;
        }
        self::$markers[ $phase ] = [
            't_ns'       => $t_ns ?? self::now_ns(),
            'cpms_q'     => $cpms_q ?? self::$cpms_queries,
            'cpms_db_ns' => $cpms_db_ns ?? self::$cpms_db_ns,
            'wp_q'       => $wp_q ?? self::wp_query_count(),
        ];
    }

    /**
     * Start of a profiled request (called from `App::boot()`): restart the profile
     * and record the boot marker.
     */
    public static function mark_boot(): void {
        if ( ! self::armed() ) {
            return;
        }
        self::$endpoint     = 'other';
        self::$markers      = [];
        self::$cpms_queries = 0;
        self::$cpms_db_ns   = 0;
        self::mark( 'boot' );
    }

    /**
     * `rest_api_init` @ priority 1 — before CPMS route registration + migration check.
     */
    public static function mark_api_init(): void {
        self::mark( 'api_init' );
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
    public static function mark_dispatch( $result, $server, $request ) {
        if ( self::armed() && $request instanceof \WP_REST_Request ) {
            $route          = $request->get_route();
            self::$endpoint = self::endpoint_for_route( is_string( $route ) ? $route : null );
            self::mark( 'dispatch' );
        }

        return $result;
    }

    /**
     * `rest_post_dispatch` @ priority 999 — records the respond marker and attaches
     * the header to `WP_REST_Response` results only. Anything else (errors, missing
     * data) is returned unmodified.
     *
     * NOTE: core applies this filter only in `serve_request()` (the real-HTTP path);
     * `rest_do_request()` drives `dispatch()` directly and never fires it, so the
     * Integration suite mirrors that exact core step in its dispatch helper.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    // `rest_post_dispatch` always passes ($result, $server, $request); the filter
    // signature is fixed by WordPress — $server/$request are unused by design.
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
    public static function attach_profile( $result, $server, $request ) {
        if ( ! self::armed() ) {
            return $result;
        }
        self::mark( 'respond' );
        if ( ! $result instanceof \WP_REST_Response ) {
            return $result;
        }
        $header = self::header_value();
        if ( $header === '' ) {
            return $result;
        }
        $result->header( self::HEADER, $header );

        return $result;
    }

    /**
     * Map a REST route to the published endpoint token (exact allowlist, no echo).
     */
    public static function endpoint_for_route( ?string $route ): string {
        return self::ENDPOINTS[ $route ?? '' ] ?? 'other';
    }

    /**
     * Begin observing one CPMS-layer query. Returns null unless armed (callers pass
     * the token straight to `query_end()`).
     */
    public static function query_start(): ?int {
        return self::armed() ? self::now_ns() : null;
    }

    /**
     * Finish observing one CPMS-layer query (count +1, accumulate wall time).
     */
    public static function query_end( ?int $start_ns ): void {
        if ( $start_ns === null || ! self::armed() ) {
            return;
        }
        ++self::$cpms_queries;
        $elapsed          = self::now_ns() - $start_ns;
        self::$cpms_db_ns += $elapsed > 0 ? $elapsed : 0;
    }

    public static function cpms_query_count(): int {
        return self::$cpms_queries;
    }

    public static function cpms_db_ns(): int {
        return self::$cpms_db_ns;
    }

    /**
     * The validated allowlisted snapshot, or null when anything is incomplete or
     * invalid (fail closed — never partial evidence).
     *
     * @return array<string, mixed>|null
     */
    public static function snapshot(): ?array {
        if ( ! self::armed() ) {
            return null;
        }
        foreach ( self::PHASES as $phase ) {
            if ( ! isset( self::$markers[ $phase ] ) ) {
                return null;
            }
        }
        $boot     = self::$markers['boot'];
        $init     = self::$markers['api_init'];
        $dispatch = self::$markers['dispatch'];
        $respond  = self::$markers['respond'];
        foreach ( [$boot, $init, $dispatch, $respond] as $marker ) {
            if ( $marker['t_ns'] < 0 || $marker['cpms_q'] < 0 || $marker['cpms_db_ns'] < 0 || $marker['wp_q'] < 0 ) {
                return null;
            }
        }
        foreach ( ['t_ns', 'cpms_q', 'cpms_db_ns', 'wp_q'] as $key ) {
            if ( $init[ $key ] < $boot[ $key ] || $dispatch[ $key ] < $init[ $key ]
                || $respond[ $key ] < $dispatch[ $key ] ) {
                return null;
            }
        }

        $t_boot     = self::ms( $init['t_ns'] - $boot['t_ns'] );
        $t_init     = self::ms( $dispatch['t_ns'] - $init['t_ns'] );
        $t_dispatch = self::ms( $respond['t_ns'] - $dispatch['t_ns'] );

        $db_boot     = self::ms( $init['cpms_db_ns'] - $boot['cpms_db_ns'] );
        $db_init     = self::ms( $dispatch['cpms_db_ns'] - $init['cpms_db_ns'] );
        $db_dispatch = self::ms( $respond['cpms_db_ns'] - $dispatch['cpms_db_ns'] );

        return [
            'schema'              => self::SCHEMA,
            'endpoint'            => self::$endpoint,
            't_total_ms'          => round( $t_boot + $t_init + $t_dispatch, 3 ),
            't_boot_ms'           => $t_boot,
            't_init_ms'           => $t_init,
            't_dispatch_ms'       => $t_dispatch,
            'cpms_q'              => $respond['cpms_q'] - $boot['cpms_q'],
            'cpms_q_boot'         => $init['cpms_q'] - $boot['cpms_q'],
            'cpms_q_init'         => $dispatch['cpms_q'] - $init['cpms_q'],
            'cpms_q_dispatch'     => $respond['cpms_q'] - $dispatch['cpms_q'],
            'cpms_db_ms'          => round( $db_boot + $db_init + $db_dispatch, 3 ),
            'cpms_db_ms_boot'     => $db_boot,
            'cpms_db_ms_init'     => $db_init,
            'cpms_db_ms_dispatch' => $db_dispatch,
            'wp_q'                => $respond['wp_q'] - $boot['wp_q'],
            'wp_q_boot'           => $init['wp_q'] - $boot['wp_q'],
            'wp_q_init'           => $dispatch['wp_q'] - $init['wp_q'],
            'wp_q_dispatch'       => $respond['wp_q'] - $dispatch['wp_q'],
        ];
    }

    /**
     * The single-line header payload, or '' when no valid snapshot exists.
     */
    public static function header_value(): string {
        $snapshot = self::snapshot();
        if ( $snapshot === null ) {
            return '';
        }
        // `json_encode` (not `wp_json_encode`) keeps this class unit-testable without
        // WordPress; the payload is numeric-only allowlisted keys (no UTF-8 repair needed).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $json = json_encode( $snapshot, JSON_UNESCAPED_SLASHES );

        return is_string( $json ) ? $json : '';
    }

    private static function ms( int $ns ): float {
        return round( $ns / 1_000_000, 3 );
    }

    /**
     * The `$wpdb->num_queries` total, or -1 when unavailable (which refuses the
     * snapshot — an unknown total is never zero-filled).
     */
    private static function wp_query_count(): int {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ( ! $wpdb instanceof \wpdb ) {
            return -1;
        }
        $count = $wpdb->num_queries;

        return is_int( $count ) ? $count : -1;
    }
}

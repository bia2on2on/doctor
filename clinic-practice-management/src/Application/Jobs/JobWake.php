<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/**
 * Phase 17 — fast wake for the background job queue (advisory, installation-wide).
 *
 * Problem: the existing trigger for the queue is minute-cadenced, so a freshly
 * enqueued due job can wait up to a minute even though the queue itself can
 * start work immediately.
 *
 * Mechanism (smallest WordPress-native shape):
 *  1. `request()` runs AFTER `JobQueue::enqueue()` persisted the job row.
 *  2. It schedules ONE installation-wide single event `cpms_jobs_wake`
 *     (no args — therefore no job id, no Clinic, no user identity). WordPress
 *     core itself refuses an identical event within ten minutes and this class
 *     also skips scheduling while one is pending, so burst enqueues coalesce.
 *  3. The loopback spawn is ARMED here and executed once per request on
 *     WordPress's own `shutdown` action, through `spawn_cron()` — the supported
 *     non-blocking WordPress API that posts to the site's own `wp-cron.php`.
 *     The target is derived by WordPress from `site_url()`; no caller input, no
 *     payload, and no job data ever travels over it. WordPress's own rules still
 *     apply: `DOING_CRON`, the 60-second `doing_cron` lock, and the requirement
 *     that a due event exists.
 *  4. The wake event handler calls the EXISTING `App::runTick()`, so the MySQL
 *     tick lock and `JobQueue::claim()` remain the only processing authority.
 *  5. The existing `cpms_jobs_tick` (`cpms_minute`) recurring event is NOT
 *     touched: it remains the recovery/fallback trigger, and on hosts where the
 *     loopback spawn cannot run, jobs still process on that minute cadence.
 *
 * Everything here is advisory: a wake-up failure must never fail `enqueue()`,
 * never delete a job, and never throw. No new REST endpoint is registered.
 */
final class JobWake {

    /** Installation-wide wake key — deliberately fixed, never a per-row identity. */
    public const HOOK = 'cpms_jobs_wake';

    /** WordPress's end-of-request action: the response is already flushed there. */
    public const SPAWN_ACTION = 'shutdown';

    /** Late priority so the spawn happens after core's own output flushing. */
    public const SPAWN_PRIORITY = 99;

    /** Armed once per request; keeps a burst to at most one loopback spawn. */
    private static bool $armed = false;

    /**
     * Request the advisory fast wake-up.
     *
     * Called by `JobQueue::enqueue()` after the job row is committed. Never
     * throws and never changes the enqueue result.
     */
    public function request(): void {
        if ( ! self::cron_functions_available() || self::inside_cron() ) {
            return;
        }

        try {
            if ( wp_next_scheduled( self::HOOK ) === false ) {
                wp_schedule_single_event( time(), self::HOOK );
            }

            // Only arm the loopback when a wake event is actually pending: the
            // spawn is a hint for WordPress, never an authority of its own.
            if ( wp_next_scheduled( self::HOOK ) !== false ) {
                self::arm();
            }
        } catch ( \Throwable $unused ) {
            // Advisory only — the persisted job stays queued for the fallback tick.
            unset( $unused );
        }
    }

    /**
     * Runs on the `shutdown` action: performs the once-per-request spawn.
     */
    public static function spawn_if_armed(): void {
        if ( ! self::$armed ) {
            return;
        }

        self::$armed = false;
        self::spawn();
    }

    /**
     * Non-blocking, WordPress-native loopback spawn for the pending wake event.
     *
     * Delegates every policy decision to WordPress (`spawn_cron()`): no arbitrary
     * URL, no caller-controlled target, no request body, no external service.
     */
    public static function spawn(): void {
        if ( ! function_exists( 'spawn_cron' ) || self::inside_cron() ) {
            return;
        }

        try {
            spawn_cron();
        } catch ( \Throwable $unused ) {
            // Advisory only — the recurring minute trigger remains authoritative.
            unset( $unused );
        }
    }

    /**
     * Arms the once-per-request deferred spawn.
     */
    private static function arm(): void {
        if ( self::$armed ) {
            return;
        }

        self::$armed = true;

        if ( function_exists( 'add_action' ) ) {
            add_action( self::SPAWN_ACTION, array( self::class, 'spawn_if_armed' ), self::SPAWN_PRIORITY );
        }
    }

    /**
     * True while WordPress is already processing cron (recursion guard).
     */
    private static function inside_cron(): bool {
        return function_exists( 'wp_doing_cron' ) && wp_doing_cron();
    }

    /**
     * True when the WordPress cron scheduling functions are available.
     */
    private static function cron_functions_available(): bool {
        return function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' );
    }
}

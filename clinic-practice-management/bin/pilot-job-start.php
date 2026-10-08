<?php
/**
 * Phase 17 disposable-Pilot queue start-latency producer.
 *
 * Enqueue 100 non-PHI, system-scoped backup.run samples through the production
 * JobQueue API. Collect only their persisted enqueue/first-claim timestamps and
 * terminal processing states. The random correlation token is never printed or
 * written to the raw evidence file.
 */

declare(strict_types=1);

use ClinicCore\Application\Jobs\JobWake;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;

if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}

/**
 * Stop with a generic CLI refusal message.
 *
 * The optional reason is reduced to a fixed lowercase/underscore allowlist (max
 * 32 chars) so the run-bound diagnostic line can never carry free-form text.
 *
 * @param string $reason Bounded refusal reason code.
 * @return never
 */
function cpms_pilot_job_start_refuse( string $reason = 'unspecified' ): never {
    $reason = substr( (string) preg_replace( '/[^a-z_]/', '', strtolower( $reason ) ), 0, 32 );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only generic refusal; WP_Filesystem is not available here.
    fwrite( STDERR, "JOB_START: precondition or collection refused\n" );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI fixed-key diagnostic; WP_Filesystem is not available here.
    fwrite( STDOUT, 'DIAG exit.refusal_reason=' . ( '' === $reason ? 'unspecified' : $reason ) . "\n" );
    exit( 1 );
}

/**
 * Enqueue or collect queue-start evidence in the disposable Pilot.
 *
 * @param array<int, string> $args Command-line arguments.
 * @return void
 */
function cpms_pilot_job_start_main( array $args ): void {
    $wp_home = getenv( 'WP_HOME' );
    if ( ! is_string( $wp_home ) || '' === trim( $wp_home ) ) {
        cpms_pilot_job_start_refuse();
    }

    $wp_load = rtrim( $wp_home, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wp-load.php';
    if ( ! is_file( $wp_load ) ) {
        cpms_pilot_job_start_refuse();
    }

    require_once $wp_load;
    App::boot();

    $action = $args[1] ?? '';

    if ( 'lock-state' === $action ) {
        // READ-ONLY bounded probe used by the Pilot's bounded wait: prints exactly
        // "1" while WordPress's own `doing_cron` spawn lock is held and "0" while it is
        // free. It never clears, resets or overrides the lock, never trades it for a new
        // one and never ticks the queue — the lock is only observed, then allowed to
        // lapse naturally under WordPress's own WP_CRON_LOCK_TIMEOUT.
        $lock = cpms_pilot_job_start_cron_lock_state();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI fixed-key probe output; WP_Filesystem is not available here.
        fwrite( STDOUT, $lock['present'] ? "1\n" : "0\n" );
        exit( 0 );
    }
    if ( 'quiet' === $action ) {
        // READ-ONLY bounded quiescence probe for the explicit-tick control (exit 0 =
        // quiescent). Observes only; it never enqueues, claims, ticks or clears state.
        exit( cpms_pilot_job_start_quiescence_diag() ? 0 : 1 );
    }
    $token = getenv( 'CPMS_JOB_START_TOKEN' );
    if ( ! is_string( $token ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $token ) ) {
        cpms_pilot_job_start_refuse( 'token' );
    }

    $mode = getenv( 'CPMS_JOB_START_MODE' );
    $mode = is_string( $mode ) && '' !== trim( $mode ) ? trim( $mode ) : 'explicit_tick';
    if ( ! in_array( $mode, [ 'explicit_tick', 'autonomous' ], true ) ) {
        // Unsupported measurement mode — never guess a label.
        cpms_pilot_job_start_refuse();
    }

    // ---- Phase 17 bounded diagnostics (fixed keys; booleans/bounded integers) ----
    //
    // Printed for the run summary only — never written into the raw or published
    // evidence file. Purpose: attribute a blocked autonomous fast-wake path to a
    // concrete cause (pending/due event, WordPress cron lock, loopback dispatch,
    // callback/claim execution) instead of guessing. No URL, filesystem path,
    // token, job id, payload, SQL, header or free-form exception text is emitted:
    // only fixed keys with booleans, small bounded integers or NOT_RETRIEVED.
    cpms_pilot_job_start_diag_reset();

    $sample_count = 100;
    $job_type     = 'backup.run';

    try {
        $db = App::db();

        // This is a SYSTEM-scope job. The one seeded synthetic Clinic check is
        // only a fail-closed Pilot bootstrap precondition; no Clinic id is
        // selected, stored in a payload, or used as job authority.
        $clinic_count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinics' )
        );
        if ( 1 !== $clinic_count ) {
            cpms_pilot_job_start_refuse( 'clinic_count' );
        }

        // BackupRunHandler is a no-op while backups are disabled. Never let
        // this measurement create backup files or invoke a configured provider.
        if ( false !== App::installationSettings()->getBackupEnabled() ) {
            cpms_pilot_job_start_refuse( 'backup_enabled' );
        }

        $priority = App::RECURRING_JOBS[ $job_type ] ?? null;
        if ( ! is_int( $priority ) || 1 !== $priority ) {
            cpms_pilot_job_start_refuse();
        }

        $table = $db->table( 'cpms_jobs' );

        if ( 'enqueue' === $action ) {
            $wake_before = cpms_pilot_job_start_wake_state();
            cpms_pilot_job_start_diag( 'enqueue.wake_event_pending_before', $wake_before['pending'] ? 'true' : 'false' );
            cpms_pilot_job_start_diag( 'enqueue.wake_event_due_delta_ms_before', (string) $wake_before['due_delta_ms'] );

            // Explicit control: the SAME production JobQueue class without the optional
            // advisory JobWake collaborator (its constructor parameter is nullable by
            // design). This fixture therefore cannot schedule `cpms_jobs_wake` or a
            // loopback spawn, so it cannot be drained by autonomous wake before the
            // explicit CLI tick. The autonomous pass keeps the production instance.
            $queue = 'autonomous' === $mode ? App::jobs() : new JobQueue( $db, App::op() );
            cpms_pilot_job_start_diag( 'enqueue.wake_collaborator', 'autonomous' === $mode ? 'production' : 'absent' );

            // Pure observer (never alters the response): records whether WordPress
            // actually started the site-local cron loopback in this process's
            // deferred shutdown spawn.
            add_filter( 'pre_http_request', 'cpms_pilot_job_start_observe_loopback', 10, 3 );

            // Runs after WordPress's own shutdown hook (where the wake spawn is
            // deferred), so the observed lock/wake state is the post-spawn state.
            register_shutdown_function( 'cpms_pilot_job_start_diag_enqueue_shutdown' );

            $existing = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table .
                ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                "'$.pilot_measurement_correlation')) = %s",
                [ $job_type, $token ]
            );
            if ( 0 !== $existing ) {
                cpms_pilot_job_start_refuse( 'enqueue_existing' );
            }

            for ( $index = 0; $index < $sample_count; $index++ ) {
                $id = $queue->enqueue(
                    $job_type,
                    [ 'pilot_measurement_correlation' => $token ],
                    priority: $priority,
                    maxAttempts: 1
                );
                if ( $id < 1 ) {
                    cpms_pilot_job_start_refuse( 'enqueue_insert' );
                }
            }

            if ( 'autonomous' === $mode ) {
                // Run-bound testimony of the production fast-wake request: this
                // producer never calls a tick, and because the loopback spawn is
                // deferred to the end of this request, the wake event scheduled by
                // the ordinary enqueue path must still be pending here.
                if ( false === wp_next_scheduled( JobWake::HOOK ) ) {
                    cpms_pilot_job_start_refuse( 'enqueue_wake_not_pending' );
                }
            }
            exit( 0 );
        }

        if ( 'precheck' === $action ) {
            // Explicit control, immediately BEFORE the explicit tick: the exact 100-row
            // batch must still be queued and unstarted, and the environment quiescent.
            // Any batch row already started means it is NOT explicit processing → refuse.
            $batch_rows   = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table .
                ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                "'$.pilot_measurement_correlation')) = %s",
                [ $job_type, $token ]
            );
            $batch_queued = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table .
                ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                "'$.pilot_measurement_correlation')) = %s AND status = %s AND started_at IS NULL AND attempts = 0",
                [ $job_type, $token, JobQueue::QUEUED ]
            );
            cpms_pilot_job_start_diag( 'precheck.batch_rows', (string) min( $batch_rows, 100000 ) );
            cpms_pilot_job_start_diag( 'precheck.batch_queued_unstarted', (string) min( $batch_queued, 100000 ) );
            $quiescent = cpms_pilot_job_start_quiescence_diag();
            if ( $sample_count !== $batch_rows || $sample_count !== $batch_queued || ! $quiescent ) {
                cpms_pilot_job_start_refuse( 'precheck' );
            }
            exit( 0 );
        }

        if ( 'collect' !== $action ) {
            cpms_pilot_job_start_refuse();
        }

        if ( 'autonomous' === $mode ) {
            // Autonomous mode: bounded wait for first-start evidence. Nothing here
            // triggers the queue — only the production fast-wake path requested by
            // the enqueue itself may start these rows. A bounded wait keeps the
            // observation honest instead of inventing a start.
            $deadline            = microtime( true ) + 20.0;
            $started_before_wait = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table .
                ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                "'$.pilot_measurement_correlation')) = %s AND started_at IS NOT NULL",
                [ $job_type, $token ]
            );
            do {
                $waiting = (int) $db->fetchValue(
                    'SELECT COUNT(*) FROM ' . $table .
                    ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                    "'$.pilot_measurement_correlation')) = %s AND started_at IS NULL",
                    [ $job_type, $token ]
                );
                if ( 0 === $waiting ) {
                    break;
                }
                usleep( 500000 );
            } while ( microtime( true ) < $deadline );
        }

        if ( 'autonomous' === $mode ) {
            $started_after_wait = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table .
                ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                "'$.pilot_measurement_correlation')) = %s AND started_at IS NOT NULL",
                [ $job_type, $token ]
            );
            $wake_after         = cpms_pilot_job_start_wake_state();
            $lock_after         = cpms_pilot_job_start_cron_lock_state();
            $tick_age           = 'NOT_RETRIEVED';
            try {
                // Queue's own persisted tick marker (bounded age, no timestamp echo):
                // distinguishes "wake callback ran" from "nothing ran at all".
                $tick_at = (int) ( App::queueHealth()['last_tick_at'] ?? 0 );
                if ( $tick_at > 0 ) {
                    $tick_age = (string) max( -600000, min( 600000, (int) round( ( microtime( true ) - $tick_at ) * 1000 ) ) );
                }
            } catch ( \Throwable ) {
                $tick_age = 'NOT_RETRIEVED';
            }
            cpms_pilot_job_start_diag( 'collect.started_count_before_wait', (string) $started_before_wait );
            cpms_pilot_job_start_diag( 'collect.started_count_after_wait', (string) $started_after_wait );
            cpms_pilot_job_start_diag( 'collect.wake_event_pending_after_wait', $wake_after['pending'] ? 'true' : 'false' );
            cpms_pilot_job_start_diag( 'collect.wake_event_due_delta_ms_after_wait', (string) $wake_after['due_delta_ms'] );
            cpms_pilot_job_start_diag( 'collect.doing_cron_lock_present', $lock_after['present'] ? 'true' : 'false' );
            cpms_pilot_job_start_diag( 'collect.doing_cron_lock_age_ms', (string) $lock_after['age_ms'] );
            cpms_pilot_job_start_diag( 'collect.tick_age_ms', $tick_age );
        }

        $rows = $db->fetchAll(
            'SELECT status, attempts, max_attempts, created_at, started_at FROM ' . $table .
            ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
            "'$.pilot_measurement_correlation')) = %s ORDER BY id ASC",
            [ $job_type, $token ]
        );
        if ( $sample_count !== count( $rows ) ) {
            cpms_pilot_job_start_diag( 'collect.rows_found', (string) count( $rows ) );
            cpms_pilot_job_start_refuse( 'collect_row_count' );
        }

        // Bounded status counts only (fixed keys, integers): lets a failed explicit
        // control say whether the batch was unstarted, in-flight, or terminal.
        $status_counts = [ 'queued' => 0, 'processing' => 0, 'success' => 0, 'failed' => 0, 'other' => 0 ];
        foreach ( $rows as $row ) {
            $status_key = (string) ( $row['status'] ?? '' );
            ++$status_counts[ isset( $status_counts[ $status_key ] ) ? $status_key : 'other' ];
        }
        foreach ( $status_counts as $status_key => $status_count ) {
            cpms_pilot_job_start_diag( 'collect.status_' . $status_key, (string) $status_count );
        }

        $samples = [];
        foreach ( $rows as $row ) {
            $samples[] = [
                'created_at'   => (string) ( $row['created_at'] ?? '' ),
                'started_at'   => null === $row['started_at'] ? null : (string) $row['started_at'],
                'status'       => (string) ( $row['status'] ?? '' ),
                'attempts'     => (int) ( $row['attempts'] ?? -1 ),
                'max_attempts' => (int) ( $row['max_attempts'] ?? -1 ),
            ];
        }

        $raw_path = getenv( 'CPMS_JOB_START_RAW_PATH' );
        if ( ! is_string( $raw_path ) || '' === $raw_path || file_exists( $raw_path ) ) {
            cpms_pilot_job_start_refuse();
        }
        $temporary_root = realpath( sys_get_temp_dir() );
        $raw_directory  = realpath( dirname( $raw_path ) );
        if ( false === $temporary_root || false === $raw_directory ) {
            cpms_pilot_job_start_refuse();
        }
        $temporary_prefix = $temporary_root . DIRECTORY_SEPARATOR . 'cpms-job-start.';
        if ( ! str_starts_with( $raw_directory, $temporary_prefix ) ) {
            cpms_pilot_job_start_refuse();
        }

        $raw     = [
            'schema'           => 'cpms.pilot-job-start-raw/1',
            'status'           => 'ok',
            'measurement_mode' => $mode,
            'job_type'         => $job_type,
            'sample_count'     => $sample_count,
            'samples'          => $samples,
        ];
        $encoded = wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        if ( ! is_string( $encoded ) ) {
            cpms_pilot_job_start_refuse();
        }
        $json = $encoded . "\n";
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_umask -- Private temporary raw evidence must be owner-only.
        $previous_umask = umask( 0077 );
        try {
            // Keep raw rows in the private, disposable Pilot temp directory only.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private temporary raw evidence; WordPress filesystem is not used for this CLI scratch file.
            $written = file_put_contents( $raw_path, $json, LOCK_EX );
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_umask -- Restore the process umask after the private scratch write.
            umask( $previous_umask );
        }
        // Enforce owner-only access on this private, disposable-run evidence file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private temporary raw evidence must be owner-only.
        if ( ! is_int( $written ) || $written !== strlen( $json ) || ! chmod( $raw_path, 0600 ) ) {
            cpms_pilot_job_start_refuse();
        }
    } catch ( \Throwable ) {
        // Never echo DB errors, queue payloads, IDs, or arbitrary exception text.
        cpms_pilot_job_start_refuse();
    }
}

/** Loopback observer state for the enqueue phase (fixed-key diagnostics only). */
function cpms_pilot_job_start_loopback_started(): bool {
    return true === ( $GLOBALS['cpms_pilot_job_start_loopback'] ?? false );
}

/**
 * Marks that WordPress started the site-local cron loopback request.
 *
 * @return void
 */
function cpms_pilot_job_start_note_loopback(): void {
    $GLOBALS['cpms_pilot_job_start_loopback'] = true;
}

/**
 * Pure pre_http_request observer: never alters the response (false = continue).
 *
 * @param mixed $pre Short-circuit value.
 * @return mixed
 */
function cpms_pilot_job_start_observe_loopback( $pre ) {
    $args = func_get_args();
    $url  = $args[2] ?? '';
    if ( is_string( $url ) && '/wp-cron.php' === substr( (string) wp_parse_url( $url, PHP_URL_PATH ), -11 ) ) {
        cpms_pilot_job_start_note_loopback();
    }

    return $pre;
}

/**
 * Resets the per-process bounded diagnostic state.
 *
 * @return void
 */
function cpms_pilot_job_start_diag_reset(): void {
    $GLOBALS['cpms_pilot_job_start_loopback'] = false;
}

/**
 * Emits one fixed-key diagnostic line (booleans/bounded integers only).
 *
 * @param string $key   Fixed diagnostic key.
 * @param string $value Already-formatted boolean/bounded integer value.
 * @return void
 */
function cpms_pilot_job_start_diag( string $key, string $value ): void {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI fixed-key diagnostic; WP_Filesystem is not available here.
    fwrite( STDOUT, 'DIAG ' . $key . '=' . $value . "\n" );
}

/**
 * Pending wake event and its bounded due delta in ms (±10 minutes).
 *
 * @return array{pending: bool, due_delta_ms: string|int}
 */
function cpms_pilot_job_start_wake_state(): array {
    if ( ! function_exists( 'wp_next_scheduled' ) ) {
        return [ 'pending' => false, 'due_delta_ms' => 'NOT_RETRIEVED' ];
    }
    $timestamp = wp_next_scheduled( JobWake::HOOK );
    if ( false === $timestamp ) {
        return [ 'pending' => false, 'due_delta_ms' => 'NOT_RETRIEVED' ];
    }
    $delta = (int) round( ( (int) $timestamp - microtime( true ) ) * 1000 );

    return [ 'pending' => true, 'due_delta_ms' => max( -600000, min( 600000, $delta ) ) ];
}

/**
 * WordPress cron spawn lock (READ-ONLY): never cleared, reset or overridden.
 *
 * @return array{present: bool, age_ms: string|int}
 */
function cpms_pilot_job_start_cron_lock_state(): array {
    try {
        $lock = get_transient( 'doing_cron' );
    } catch ( \Throwable ) {
        return [ 'present' => false, 'age_ms' => 'NOT_RETRIEVED' ];
    }
    if ( ! is_numeric( $lock ) || (float) $lock <= 0 ) {
        return [ 'present' => false, 'age_ms' => 'NOT_RETRIEVED' ];
    }
    $age = (int) round( ( microtime( true ) - (float) $lock ) * 1000 );

    return [ 'present' => true, 'age_ms' => max( -600000, min( 600000, $age ) ) ];
}

/**
 * Read-only quiescence probe (fixed-key diagnostics; never mutates state).
 *
 * Quiescent = no WordPress cron spawn in flight (`doing_cron` transient absent) AND
 * no runner currently holds the production tick lock (`App::TICK_LOCK`). A pending
 * wake event is reported but does not block: in this step nothing can spawn cron
 * (DISABLE_WP_CRON; the explicit enqueue requests no wake; the explicit tick's own
 * shutdown spawn runs only after its batch has been processed).
 *
 * @return bool
 */
function cpms_pilot_job_start_quiescence_diag(): bool {
    $lock = cpms_pilot_job_start_cron_lock_state();
    $wake = cpms_pilot_job_start_wake_state();
    try {
        $free      = App::db()->fetchValue( 'SELECT IS_FREE_LOCK(%s)', [ App::TICK_LOCK ] );
        $tick_free = null !== $free && 1 === (int) $free;
    } catch ( \Throwable ) {
        $tick_free = false;
    }
    cpms_pilot_job_start_diag( 'quiet.doing_cron_lock_present', $lock['present'] ? 'true' : 'false' );
    cpms_pilot_job_start_diag( 'quiet.wake_event_pending', $wake['pending'] ? 'true' : 'false' );
    cpms_pilot_job_start_diag( 'quiet.tick_lock_free', $tick_free ? 'true' : 'false' );

    return ! $lock['present'] && $tick_free;
}

/**
 * Post-spawn diagnostics for the enqueue phase (runs after WordPress shutdown).
 *
 * @return void
 */
function cpms_pilot_job_start_diag_enqueue_shutdown(): void {
    $wake = cpms_pilot_job_start_wake_state();
    $lock = cpms_pilot_job_start_cron_lock_state();
    cpms_pilot_job_start_diag( 'enqueue.loopback_request_started', cpms_pilot_job_start_loopback_started() ? 'true' : 'false' );
    cpms_pilot_job_start_diag( 'enqueue.wake_event_pending_after', $wake['pending'] ? 'true' : 'false' );
    cpms_pilot_job_start_diag( 'enqueue.doing_cron_lock_present_after', $lock['present'] ? 'true' : 'false' );
    cpms_pilot_job_start_diag( 'enqueue.doing_cron_lock_age_ms_after', (string) $lock['age_ms'] );
}

cpms_pilot_job_start_main( $argv );

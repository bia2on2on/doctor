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

use ClinicCore\Bootstrap\App;

if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}

/**
 * Stop with a generic CLI refusal message.
 *
 * @return never
 */
function cpms_pilot_job_start_refuse(): never {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI-only generic refusal; WP_Filesystem is not available here.
    fwrite( STDERR, "JOB_START: precondition or collection refused\n" );
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
        cpms_pilot_job_start_refuse( );
    }

    $wp_load = rtrim( $wp_home, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wp-load.php';
    if ( ! is_file( $wp_load ) ) {
        cpms_pilot_job_start_refuse( );
    }

    require_once $wp_load;
    App::boot( );

    $action = $args[1] ?? '';
    $token  = getenv( 'CPMS_JOB_START_TOKEN' );
    if ( ! is_string( $token ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $token ) ) {
        cpms_pilot_job_start_refuse( );
    }

    $sample_count = 100;
    $job_type     = 'backup.run';

    try {
        $db = App::db( );

        // This is a SYSTEM-scope job. The one seeded synthetic Clinic check is
        // only a fail-closed Pilot bootstrap precondition; no Clinic id is
        // selected, stored in a payload, or used as job authority.
        $clinic_count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinics' )
        );
        if ( 1 !== $clinic_count ) {
            cpms_pilot_job_start_refuse( );
        }

        // BackupRunHandler is a no-op while backups are disabled. Never let
        // this measurement create backup files or invoke a configured provider.
        if ( false !== App::installationSettings( )->getBackupEnabled( ) ) {
            cpms_pilot_job_start_refuse( );
        }

        $priority = App::RECURRING_JOBS[ $job_type ] ?? null;
        if ( ! is_int( $priority ) || 1 !== $priority ) {
            cpms_pilot_job_start_refuse( );
        }

        $table = $db->table( 'cpms_jobs' );

        if ( 'enqueue' === $action ) {
            $existing = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $table .
                ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
                "'$.pilot_measurement_correlation')) = %s",
                [ $job_type, $token ]
            );
            if ( 0 !== $existing ) {
                cpms_pilot_job_start_refuse( );
            }

            for ( $index = 0; $index < $sample_count; $index++ ) {
                $id = App::jobs( )->enqueue(
                    $job_type,
                    [ 'pilot_measurement_correlation' => $token ],
                    priority: $priority,
                    maxAttempts: 1
                );
                if ( $id < 1 ) {
                    cpms_pilot_job_start_refuse( );
                }
            }
            exit( 0 );
        }

        if ( 'collect' !== $action ) {
            cpms_pilot_job_start_refuse( );
        }

        $rows = $db->fetchAll(
            'SELECT status, attempts, max_attempts, created_at, started_at FROM ' . $table .
            ' WHERE type = %s AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, ' .
            "'$.pilot_measurement_correlation')) = %s ORDER BY id ASC",
            [ $job_type, $token ]
        );
        if ( $sample_count !== count( $rows ) ) {
            cpms_pilot_job_start_refuse( );
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
            cpms_pilot_job_start_refuse( );
        }
        $temporary_root = realpath( sys_get_temp_dir( ) );
        $raw_directory  = realpath( dirname( $raw_path ) );
        if ( false === $temporary_root || false === $raw_directory ) {
            cpms_pilot_job_start_refuse( );
        }
        $temporary_prefix = $temporary_root . DIRECTORY_SEPARATOR . 'cpms-job-start.';
        if ( ! str_starts_with( $raw_directory, $temporary_prefix ) ) {
            cpms_pilot_job_start_refuse( );
        }

        $raw     = [
            'schema'       => 'cpms.pilot-job-start-raw/1',
            'status'       => 'ok',
            'job_type'     => $job_type,
            'sample_count' => $sample_count,
            'samples'      => $samples,
        ];
        $encoded = wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        if ( ! is_string( $encoded ) ) {
            cpms_pilot_job_start_refuse( );
        }
        $json           = $encoded . "\n";
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
            cpms_pilot_job_start_refuse( );
        }
    } catch ( \Throwable ) {
        // Never echo DB errors, queue payloads, IDs, or arbitrary exception text.
        cpms_pilot_job_start_refuse( );
    }
}

cpms_pilot_job_start_main( $argv );

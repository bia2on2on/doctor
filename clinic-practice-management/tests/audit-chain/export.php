<?php
/**
 * Read-only WP-CLI export for verify.py. Run with `wp eval-file`, on a
 * disposable test site or under an approved audit-data handling procedure.
 * The output contains identifiers: keep it private and do not commit it.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    throw new RuntimeException( 'Run only via WP-CLI.' );
}
global $wpdb;
$table = $wpdb->prefix . 'cpms_audit_logs';
if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
    throw new RuntimeException( 'Invalid table name.' );
}
$exec = static function ( string $sql ) use ( $wpdb ): void {
    if ( false === $wpdb->query( $sql ) ) {
        throw new RuntimeException( 'Database query failed.' );
    }
};
$emit = static function ( array $value ): void {
    $json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    if ( false === $json ) {
        throw new RuntimeException( 'JSON encoding failed.' );
    }
    echo $json . "\n";
};
$exec( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
$exec( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
try {
    $summary = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS max_id FROM `$table`", ARRAY_A );
    if ( null === $summary || '' !== $wpdb->last_error ) {
        throw new RuntimeException( 'Could not read snapshot summary.' );
    }
    $emit( [ 'type' => 'header', 'version' => 1, 'count' => (int) $summary['n'], 'max_id' => (int) $summary['max_id'] ] );
    $last = 0;
    $count = 0;
    do {
        $sql = $wpdb->prepare(
            "SELECT id, clinic_id, actor_wp_user_id, actor_role, action, resource_type, resource_id, patient_id, ip_hash, session_id, request_id, created_at, prev_hash, row_hash FROM `$table` WHERE id > %d ORDER BY id ASC LIMIT 1000",
            $last
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
            throw new RuntimeException( 'Could not read snapshot rows.' );
        }
        foreach ( $rows as $row ) {
            $emit( [ 'type' => 'row', 'data' => $row ] );
            $last = (int) $row['id'];
            ++$count;
        }
    } while ( count( $rows ) === 1000 );
    if ( $count !== (int) $summary['n'] || $last !== (int) $summary['max_id'] ) {
        throw new RuntimeException( 'Snapshot count mismatch.' );
    }
    $exec( 'COMMIT' );
    $emit( [ 'type' => 'end', 'count' => $count, 'max_id' => $last ] );
} catch ( Throwable $e ) {
    $wpdb->query( 'ROLLBACK' );
    throw $e;
}

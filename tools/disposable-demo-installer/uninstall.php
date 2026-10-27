<?php
/**
 * Uninstall handler for CPMS Disposable Demo Installer.
 *
 * Security rule (S-1): Uninstalling the companion plugin NEVER runs an automatic
 * destructive teardown and NEVER discards active or incomplete fixture ownership
 * metadata. If either mode has a synthetic demo manifest, an in-flight lock, or
 * synthetic `cpdd-*`/`cpsd-*` persona accounts, uninstall preserves all
 * ownership/recovery metadata and records a non-secret recovery marker
 * (`cpdd_uninstall_blocked`) so the operator can reinstall the companion plugin
 * and run the verified teardown workflow.
 *
 * @package CpmsDisposableDemo
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Mixed strict/scoped recovery variables are grouped by purpose.

$cpms_demo_manifest       = get_option( 'cpdd_manifest', null );
$cpms_demo_lock           = get_option( 'cpdd_lock_state', null );
$cpms_demo_scoped_manifest = get_option( 'cpsd_manifest', null );
$cpms_demo_scoped_lock     = get_option( 'cpsd_lock_state', null );
$cpms_demo_users           = function_exists( 'get_users' )
	? get_users(
		array(
			'search'         => 'cpdd-*',
			'search_columns' => array( 'user_login' ),
			'fields'         => 'ID',
			'number'         => 1,
		)
	)
	: array();
$cpms_demo_scoped_candidates = function_exists( 'get_users' )
	? get_users(
		array(
			'search'         => 'cpsd-*',
			'search_columns' => array( 'user_login' ),
			'fields'         => 'user_login',
			'number'         => 1,
		)
	)
	: array();
$cpms_demo_scoped_users      = array();
foreach ( (array) $cpms_demo_scoped_candidates as $cpms_demo_candidate ) {
	$cpms_demo_login = is_array( $cpms_demo_candidate )
		? (string) ( $cpms_demo_candidate['user_login'] ?? $cpms_demo_candidate['login'] ?? '' )
		: (string) $cpms_demo_candidate;
	if ( 0 === strpos( $cpms_demo_login, 'cpsd-' ) ) {
		$cpms_demo_scoped_users[] = $cpms_demo_login;
		break;
	}
}

$cpms_demo_active_manifest = is_array( $cpms_demo_manifest ) ? $cpms_demo_manifest : ( is_array( $cpms_demo_scoped_manifest ) ? $cpms_demo_scoped_manifest : null );

if ( ( null !== $cpms_demo_manifest && false !== $cpms_demo_manifest )
	|| ( null !== $cpms_demo_lock && false !== $cpms_demo_lock )
	|| ( null !== $cpms_demo_scoped_manifest && false !== $cpms_demo_scoped_manifest )
	|| ( null !== $cpms_demo_scoped_lock && false !== $cpms_demo_scoped_lock )
	|| ! empty( $cpms_demo_users )
	|| ! empty( $cpms_demo_scoped_users )
) {
	// Active, partial, failed, or interrupted fixture still exists: preserve
	// cpdd_manifest and all recovery metadata so a subsequent reinstall can
	// diagnose and tear down the synthetic records safely.
	if ( function_exists( 'update_option' ) ) {
		update_option(
			'cpdd_uninstall_blocked',
			array(
				'blocked_at' => gmdate( 'c' ),
				'status'     => is_array( $cpms_demo_active_manifest ) ? (string) ( $cpms_demo_active_manifest['status'] ?? 'unknown' ) : 'untracked_or_corrupt',
				'fixture_id' => is_array( $cpms_demo_active_manifest ) ? (string) ( $cpms_demo_active_manifest['fixture_id'] ?? '' ) : '',
			),
			false
		);
	}

	return;
}

delete_option( 'cpdd_tombstone' );
delete_option( 'cpdd_had_clean_teardown' );
delete_option( 'cpdd_uninstall_blocked' );
delete_option( 'cpdd_lock_state' );
delete_option( 'cpsd_tombstone' );
delete_option( 'cpsd_had_clean_teardown' );
delete_option( 'cpsd_mode' );
delete_option( 'cpsd_lock_state' );

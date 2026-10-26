<?php
/**
 * Uninstall handler for CPMS Disposable Demo Installer.
 *
 * Security rule (S-1): Uninstalling the companion plugin NEVER runs an automatic
 * destructive teardown and NEVER discards active or incomplete fixture ownership
 * metadata. If a synthetic demo manifest (`cpdd_manifest`), an in-flight lock
 * (`cpdd_lock_state`), or any synthetic `cpdd-*` persona account is still
 * present, uninstall preserves all ownership/recovery metadata and records a
 * non-secret recovery marker (`cpdd_uninstall_blocked`) so the operator can
 * reinstall the companion plugin and run the verified teardown workflow.
 *
 * @package CpmsDisposableDemo
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cpms_demo_manifest = get_option( 'cpdd_manifest', null );
$cpms_demo_lock     = get_option( 'cpdd_lock_state', null );
$cpms_demo_users    = function_exists( 'get_users' )
	? get_users(
		array(
			'search'         => 'cpdd-*',
			'search_columns' => array( 'user_login' ),
			'fields'         => 'ID',
			'number'         => 1,
		)
	)
	: array();

if ( ( null !== $cpms_demo_manifest && false !== $cpms_demo_manifest )
	|| ( null !== $cpms_demo_lock && false !== $cpms_demo_lock )
	|| ! empty( $cpms_demo_users )
) {
	// Active, partial, failed, or interrupted fixture still exists: preserve
	// cpdd_manifest and all recovery metadata so a subsequent reinstall can
	// diagnose and tear down the synthetic records safely.
	if ( function_exists( 'update_option' ) ) {
		update_option(
			'cpdd_uninstall_blocked',
			array(
				'blocked_at' => gmdate( 'c' ),
				'status'     => is_array( $cpms_demo_manifest ) ? (string) ( $cpms_demo_manifest['status'] ?? 'unknown' ) : 'untracked_or_corrupt',
				'fixture_id' => is_array( $cpms_demo_manifest ) ? (string) ( $cpms_demo_manifest['fixture_id'] ?? '' ) : '',
			),
			false
		);
	}

	return;
}

delete_option( 'cpdd_tombstone' );
delete_option( 'cpdd_uninstall_blocked' );
delete_option( 'cpdd_lock_state' );

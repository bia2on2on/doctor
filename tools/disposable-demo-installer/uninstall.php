<?php
/**
 * Uninstall: remove only this companion plugin's own records.
 *
 * Demo data is NEVER deleted here. If a demo still exists, the manifest is kept
 * so the operator can run the ownership-proofed teardown; silently deleting
 * clinical/financial rows on uninstall would be exactly the destructive
 * behaviour this plugin is designed to avoid.
 *
 * @package CpmsDisposableDemo
 */

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cpms_demo_manifest = get_option( 'cpdd_manifest', false );
if ( is_array( $cpms_demo_manifest ) ) {
	// Keep both records: the demo still exists and must be torn down explicitly.
	return;
}

delete_option( 'cpdd_tombstone' );

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

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cpdd_manifest = get_option( 'cpdd_manifest', false );
if ( is_array( $cpdd_manifest ) ) {
	// Keep both records: the demo still exists and must be torn down explicitly.
	return;
}

delete_option( 'cpdd_tombstone' );

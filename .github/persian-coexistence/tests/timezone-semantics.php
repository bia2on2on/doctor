<?php
// Focused core-API reproduction, not a MySQL/plugin compatibility test.
// Supply an unmodified WordPress 7.1.3 source directory as argv[1].
define( 'ABSPATH', rtrim( $argv[1], '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
require ABSPATH . WPINC . '/version.php';
if ( $wp_version !== '7.1.3' ) {
    throw new RuntimeException( 'Reproduction requires WordPress 7.1.3' );
}
require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/default-constants.php';
wp_initial_constants();
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/cache.php';
wp_cache_init();
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/default-filters.php';

// A cache-backed two-option fixture; no DB shim, plugins or option filters added.
wp_cache_set( 'alloptions', [ 'timezone_string' => '', 'gmt_offset' => '0' ], 'options' );
$initial = get_option( 'gmt_offset' );
wp_cache_set( 'alloptions', [ 'timezone_string' => 'Asia/Tehran', 'gmt_offset' => '0' ], 'options' );
$derived = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Tehran' ) ) )->getOffset() / 3600;
// WP-CLI Option_Command::update uses these two real core calls for its no-op test.
$unchanged = sanitize_option( 'gmt_offset', (string) $derived ) === sanitize_option( 'gmt_offset', get_option( 'gmt_offset' ) );
echo wp_json_encode(
    [
        'initial_effective' => $initial,
        'timezone_string' => get_option( 'timezone_string' ),
        'raw_gmt_offset' => wp_cache_get( 'alloptions', 'options' )['gmt_offset'],
        'effective_gmt_offset' => get_option( 'gmt_offset' ),
        'php_derived_offset' => $derived,
        'wp_cli_update_is_noop' => $unchanged,
    ]
);

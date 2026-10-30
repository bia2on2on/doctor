<?php
/**
 * Real-WordPress installer drill for the standalone demo package.
 *
 * This file is executed INSIDE a real WordPress runtime (wp eval-file). It
 * drives the exact code path behind wp-admin → Plugins → Add New → Upload
 * Plugin → Install Now → Activate Plugin:
 *
 *   Plugin_Upgrader::install( $zip )          (unzip + copy, real WP_Filesystem)
 *   Plugin_Upgrader::plugin_info()            (what the "Activate Plugin" link targets)
 *   get_plugins( '/cpms-disposable-demo' )    (what plugin_info() ranks)
 *   validate_plugin( $plugin_file )           (what activate_plugin() checks first)
 *   activate_plugin( $plugin_file )           (optional, GREEN only)
 *
 * It is deliberately runnable in two directions:
 *
 *   RED    a package with the pre-fix shape (two plugin headers) MUST make
 *          plugin_info() return the nested production bootstrap and
 *          validate_plugin() fail with `no_plugin_header` — the exact
 *          "The plugin does not have a valid header" the owner saw. The drill
 *          fails if the defect is NOT observed, so it proves it can see it.
 *   GREEN  the shipped package MUST resolve to cpms-disposable-demo/
 *          cpms-disposable-demo.php, be the only plugin in its folder, pass
 *          validate_plugin(), and (when asked) activate without PHP warnings,
 *          booting the embedded production core.
 *
 * Usage (positional, after the file):
 *   wp eval-file tests/scoped-demo/wp-admin-install-drill.php snapshot <out.json>
 *   wp eval-file tests/scoped-demo/wp-admin-install-drill.php install <zip> <red|green> <inspect|activate> <out.json>
 *   wp eval-file tests/scoped-demo/wp-admin-install-drill.php verify <out.json> [snapshot.json]
 *
 * `install … inspect` removes the installed directory again (unless
 * CPSA_DRILL_KEEP=1), so the browser upload afterwards starts from a clean
 * plugins directory. Nothing here reads credentials and nothing is printed
 * except PASS/FAIL lines and one final JSON line.
 *
 * @package CpmsDisposableDemo\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "run this file through `wp eval-file` inside WordPress\n" );
	exit( 2 );
}

$cpsa_drill_args = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
if ( array() === $cpsa_drill_args && isset( $argv ) && is_array( $argv ) ) {
	$cpsa_drill_args = array_slice( $argv, 1 );
}
if ( isset( $GLOBALS['cpsa_drill_argv'] ) && is_array( $GLOBALS['cpsa_drill_argv'] ) ) {
	$cpsa_drill_args = array_values( $GLOBALS['cpsa_drill_argv'] );
}

$cpsa_checks   = array();
$cpsa_evidence = array();

$cpsa_check = static function ( bool $ok, string $name, string $detail = '' ) use ( &$cpsa_checks ): void {
	$cpsa_checks[] = array( 'name' => $name, 'ok' => $ok, 'detail' => $detail );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
};

$cpsa_finish = static function ( string $mode, string $out ) use ( &$cpsa_checks, &$cpsa_evidence ): void {
	$ok     = array() !== $cpsa_checks;
	foreach ( $cpsa_checks as $check ) {
		if ( ! $check['ok'] ) {
			$ok = false;
		}
	}
	$result = array(
		'ok'       => $ok,
		'mode'     => $mode,
		'checks'   => $cpsa_checks,
		'evidence' => $cpsa_evidence,
	);
	$json   = (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( '' !== $out && false === file_put_contents( $out, $json ) ) {
		echo "FAIL cannot write evidence file: {$out}\n";
		$ok = false;
	}
	echo $json . "\n";
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		\WP_CLI::halt( $ok ? 0 : 1 );
	}
	exit( $ok ? 0 : 1 );
};

/**
 * Site content that must survive an installation untouched.
 *
 * @return array<string,int|string>
 */
$cpsa_snapshot = static function (): array {
	global $wpdb;
	$plugins = (array) get_option( 'active_plugins', array() );
	sort( $plugins );

	return array(
		'users'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'posts'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'comments'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ),
		'options'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'" ),
		'content_sha256' => hash( 'sha256', (string) wp_json_encode( $wpdb->get_results( "SELECT ID, post_title, post_status, post_type, post_modified_gmt FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) ) ),
		'users_sha256'   => hash( 'sha256', (string) wp_json_encode( $wpdb->get_results( "SELECT ID, user_login, user_email, user_registered FROM {$wpdb->users} ORDER BY ID", ARRAY_A ) ) ),
		'active_plugins' => implode( ',', $plugins ),
		'blogname'       => (string) get_option( 'blogname' ),
	);
};

$cpsa_debug_log = static function (): string {
	if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
		return WP_DEBUG_LOG;
	}

	return WP_CONTENT_DIR . '/debug.log';
};

$cpsa_log_size = static function () use ( $cpsa_debug_log ): int {
	$path = $cpsa_debug_log();

	return is_file( $path ) ? (int) filesize( $path ) : 0;
};

/**
 * PHP problems logged since an offset that mention the demo package.
 *
 * @return list<string>
 */
$cpsa_log_problems = static function ( int $offset ) use ( $cpsa_debug_log ): array {
	$path = $cpsa_debug_log();
	if ( ! is_file( $path ) ) {
		return array();
	}
	$handle = fopen( $path, 'rb' );
	if ( false === $handle ) {
		return array();
	}
	if ( $offset > 0 ) {
		fseek( $handle, $offset );
	}
	$tail = (string) stream_get_contents( $handle );
	fclose( $handle );
	$problems = array();
	foreach ( preg_split( '/\r?\n/', $tail ) ?: array() as $line ) {
		if ( 1 === preg_match( '/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)|Uncaught/', $line ) ) {
			$problems[] = mb_substr( $line, 0, 300 );
		}
	}

	return $problems;
};

$cpsa_mode = (string) ( $cpsa_drill_args[0] ?? '' );
wp_set_current_user( 1 );

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

$cpsa_plugin_dir_name = 'cpms-disposable-demo';
$cpsa_expected_file   = $cpsa_plugin_dir_name . '/' . $cpsa_plugin_dir_name . '.php';
$cpsa_nested_file     = $cpsa_plugin_dir_name . '/core/clinic-practice-management.php';

if ( 'snapshot' === $cpsa_mode ) {
	$out = (string) ( $cpsa_drill_args[1] ?? '' );
	$cpsa_evidence['snapshot']         = $cpsa_snapshot();
	$cpsa_evidence['debug_log_offset'] = $cpsa_log_size();
	$cpsa_evidence['plugins_root']     = array_keys( get_plugins() );
	$cpsa_check( ! is_dir( WP_PLUGIN_DIR . '/' . $cpsa_plugin_dir_name ), 'snapshot: the demo package is not installed yet' );
	$cpsa_check( ! is_dir( WP_PLUGIN_DIR . '/clinic-practice-management' ), 'snapshot: the production plugin is absent' );
	$cpsa_check( $cpsa_evidence['snapshot']['posts'] > 0 && $cpsa_evidence['snapshot']['users'] > 0, 'snapshot: the site is not empty', wp_json_encode( $cpsa_evidence['snapshot'] ) );
	$cpsa_finish( $cpsa_mode, $out );
}

if ( 'install' === $cpsa_mode ) {
	$zip    = (string) ( $cpsa_drill_args[1] ?? '' );
	$expect = (string) ( $cpsa_drill_args[2] ?? '' );
	$action = (string) ( $cpsa_drill_args[3] ?? 'inspect' );
	$out    = (string) ( $cpsa_drill_args[4] ?? '' );
	if ( ! in_array( $expect, array( 'red', 'green' ), true ) || ! in_array( $action, array( 'inspect', 'activate' ), true ) ) {
		echo "FAIL usage: install <zip> <red|green> <inspect|activate> <out.json>\n";
		$cpsa_finish( $cpsa_mode, $out );
	}
	$cpsa_evidence['zip']        = basename( $zip );
	$cpsa_evidence['zip_sha256'] = is_file( $zip ) ? hash_file( 'sha256', $zip ) : '';
	$cpsa_evidence['expect']     = $expect;
	$cpsa_evidence['action']     = $action;
	$cpsa_check( is_file( $zip ), 'install: the package file exists', $zip );

	$before     = $cpsa_snapshot();
	$log_offset = $cpsa_log_size();
	$cpsa_check( ! is_dir( WP_PLUGIN_DIR . '/' . $cpsa_plugin_dir_name ), 'install: plugins directory is clean before the upload', WP_PLUGIN_DIR . '/' . $cpsa_plugin_dir_name );

	WP_Filesystem();
	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	ob_start();
	$install_result = $upgrader->install( $zip );
	$install_output = (string) ob_get_clean();
	$cpsa_evidence['installer_messages'] = array_values( array_map( 'strval', (array) $skin->get_upgrade_messages() ) );
	$cpsa_check( true === $install_result, 'install: Plugin_Upgrader::install() succeeded (real unzip through WP_Filesystem)', is_wp_error( $install_result ) ? $install_result->get_error_message() : wp_json_encode( $install_result ) );
	$cpsa_check( '' === trim( $install_output ), 'install: the installer produced no stray output', mb_substr( $install_output, 0, 200 ) );

	$destination = is_array( $upgrader->result ) ? (string) ( $upgrader->result['destination_name'] ?? '' ) : '';
	$plugin_file = $upgrader->plugin_info();
	$in_folder   = array_keys( get_plugins( '/' . $cpsa_plugin_dir_name ) );
	$root_list   = array_keys( get_plugins() );
	$validate    = is_string( $plugin_file ) ? validate_plugin( $plugin_file ) : new WP_Error( 'plugin_info_false', 'plugin_info() returned false' );

	$cpsa_evidence['destination_name'] = $destination;
	$cpsa_evidence['plugin_info']      = $plugin_file;
	$cpsa_evidence['get_plugins_in_folder'] = $in_folder;
	$cpsa_evidence['get_plugins_root_demo_entries'] = array_values( array_filter( $root_list, static fn ( string $f ): bool => 0 === strpos( $f, $cpsa_plugin_dir_name . '/' ) ) );
	$cpsa_evidence['validate_plugin']  = is_wp_error( $validate ) ? array( 'code' => $validate->get_error_code(), 'message' => $validate->get_error_message() ) : $validate;

	$cpsa_check( $cpsa_plugin_dir_name === $destination, 'install: the package unpacked to the expected plugin root', $destination );

	if ( 'red' === $expect ) {
		// The defect must be OBSERVED, otherwise this control proves nothing.
		$cpsa_check( $cpsa_nested_file === $plugin_file, 'RED control: plugin_info() picks the nested production bootstrap (the defect)', (string) $plugin_file );
		$cpsa_check( 2 === count( $in_folder ), 'RED control: get_plugins(/cpms-disposable-demo) discovers TWO plugins', implode( ',', $in_folder ) );
		$cpsa_check( is_wp_error( $validate ) && 'no_plugin_header' === $validate->get_error_code(), 'RED control: validate_plugin() fails with no_plugin_header ("The plugin does not have a valid header")', is_wp_error( $validate ) ? $validate->get_error_code() : 'ok' );
		$cpsa_check( ! is_plugin_active( $cpsa_expected_file ) && ! is_plugin_active( $cpsa_nested_file ), 'RED control: nothing was activated' );
	} else {
		$cpsa_check( $cpsa_expected_file === $plugin_file, 'GREEN: plugin_info() returns cpms-disposable-demo/cpms-disposable-demo.php', (string) $plugin_file );
		$cpsa_check( array( $cpsa_plugin_dir_name . '.php' ) === $in_folder, 'GREEN: get_plugins(/cpms-disposable-demo) discovers ONLY the standalone plugin', implode( ',', $in_folder ) );
		$cpsa_check( 0 === $validate, 'GREEN: validate_plugin() succeeds for the plugin_info() file', is_wp_error( $validate ) ? $validate->get_error_code() : 'ok' );
		$cpsa_check( is_file( WP_PLUGIN_DIR . '/' . $cpsa_nested_file ), 'GREEN: the embedded production bootstrap is shipped under core/' );
		$cpsa_check( array( $cpsa_expected_file ) === $cpsa_evidence['get_plugins_root_demo_entries'], 'GREEN: the plugins screen (get_plugins()) lists exactly one demo entry', implode( ',', $cpsa_evidence['get_plugins_root_demo_entries'] ) );
		$nested = (string) file_get_contents( WP_PLUGIN_DIR . '/' . $cpsa_nested_file );
		$cpsa_check( false === strpos( $nested, 'Plugin Name:' ) && false !== strpos( $nested, "define('CPMS_PLUGIN_FILE', __FILE__);" ), 'GREEN: the embedded bootstrap has no plugin header but keeps its executable code' );

		if ( 'activate' === $action && 0 === $validate ) {
			$warnings = array();
			set_error_handler(
				static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$warnings ): bool {
					$warnings[] = $errno . ': ' . $errstr . ' @ ' . basename( $errfile ) . ':' . $errline;
					return false;
				}
			);
			ob_start();
			$activation = activate_plugin( $plugin_file, '', false, false );
			$activation_output = (string) ob_get_clean();
			restore_error_handler();
			$cpsa_evidence['activation_warnings'] = $warnings;
			$cpsa_check( null === $activation, 'GREEN: activate_plugin() returned no error', is_wp_error( $activation ) ? $activation->get_error_code() . ': ' . $activation->get_error_message() : 'null' );
			$cpsa_check( '' === trim( $activation_output ), 'GREEN: activation produced no unexpected output', mb_substr( $activation_output, 0, 200 ) );
			$cpsa_check( array() === $warnings, 'GREEN: activation raised no PHP warnings/notices in-process', implode( ' | ', array_slice( $warnings, 0, 3 ) ) );
			$cpsa_check( is_plugin_active( $cpsa_expected_file ), 'GREEN: the standalone plugin is active' );
			$cpsa_check( class_exists( '\\ClinicCore\\Bootstrap\\App', false ) && defined( 'CPMS_VERSION' ), 'GREEN: the embedded production core booted in this process (ClinicCore\\Bootstrap\\App, CPMS_VERSION)', defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : 'undefined' );
			$cpsa_check( defined( 'CPMS_DEMO_STANDALONE' ) && CPMS_DEMO_STANDALONE, 'GREEN: the package runs in standalone (embedded core) mode' );
			$cpsa_check( ! is_dir( WP_PLUGIN_DIR . '/clinic-practice-management' ), 'GREEN: no separate production plugin was needed or installed' );
		}
	}

	$after = $cpsa_snapshot();
	foreach ( array( 'users', 'posts', 'comments', 'content_sha256', 'users_sha256', 'blogname' ) as $key ) {
		$cpsa_check( $before[ $key ] === $after[ $key ], 'existing site data unchanged: ' . $key, (string) $before[ $key ] . ' -> ' . (string) $after[ $key ] );
	}
	$cpsa_evidence['log_problems_during_step'] = $cpsa_log_problems( $log_offset );
	$cpsa_check( array() === $cpsa_evidence['log_problems_during_step'], 'no PHP warnings/notices/fatals were logged during this step', implode( ' | ', array_slice( $cpsa_evidence['log_problems_during_step'], 0, 3 ) ) );

	if ( 'inspect' === $action && '1' !== (string) getenv( 'CPSA_DRILL_KEEP' ) ) {
		global $wp_filesystem;
		$removed = $wp_filesystem->delete( WP_PLUGIN_DIR . '/' . $cpsa_plugin_dir_name, true );
		wp_clean_plugins_cache( false );
		$cpsa_check( $removed && ! is_dir( WP_PLUGIN_DIR . '/' . $cpsa_plugin_dir_name ), 'install: the inspected package was removed again (clean slate for the browser upload)' );
	}
	$cpsa_finish( $cpsa_mode, $out );
}

if ( 'verify' === $cpsa_mode ) {
	$out      = (string) ( $cpsa_drill_args[1] ?? '' );
	$snapshot = (string) ( $cpsa_drill_args[2] ?? '' );
	$in_folder = array_keys( get_plugins( '/' . $cpsa_plugin_dir_name ) );
	$validate  = validate_plugin( $cpsa_expected_file );
	$cpsa_evidence['get_plugins_in_folder'] = $in_folder;
	$cpsa_evidence['validate_plugin']       = is_wp_error( $validate ) ? $validate->get_error_code() : $validate;
	$cpsa_evidence['active_plugins']        = (array) get_option( 'active_plugins', array() );
	$cpsa_check( is_dir( WP_PLUGIN_DIR . '/' . $cpsa_plugin_dir_name ), 'verify: the package is installed' );
	$cpsa_check( array( $cpsa_plugin_dir_name . '.php' ) === $in_folder, 'verify: get_plugins(/cpms-disposable-demo) discovers only the standalone plugin', implode( ',', $in_folder ) );
	$cpsa_check( 0 === $validate, 'verify: validate_plugin(cpms-disposable-demo/cpms-disposable-demo.php) succeeds' );
	$cpsa_check( is_plugin_active( $cpsa_expected_file ), 'verify: the plugin is active after the wp-admin activation' );
	$cpsa_check( ! in_array( $cpsa_nested_file, $cpsa_evidence['active_plugins'], true ), 'verify: the nested bootstrap is not (and cannot be) an active plugin' );
	$cpsa_check( class_exists( '\\ClinicCore\\Bootstrap\\App', false ) && defined( 'CPMS_VERSION' ), 'verify: the embedded production core is booted', defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : 'undefined' );
	$cpsa_check( defined( 'CPMS_DEMO_STANDALONE' ) && CPMS_DEMO_STANDALONE, 'verify: standalone (embedded core) mode' );
	$cpsa_check( ! is_dir( WP_PLUGIN_DIR . '/clinic-practice-management' ), 'verify: the production plugin is still absent' );
	if ( class_exists( '\\ClinicCore\\Bootstrap\\App', false ) ) {
		try {
			$schema = (string) \ClinicCore\Bootstrap\App::migrations()->currentVersion();
			$cpsa_evidence['schema_head'] = $schema;
			$cpsa_check( '' !== $schema, 'verify: the embedded core installed its schema', $schema );
		} catch ( \Throwable $e ) {
			$cpsa_check( false, 'verify: the embedded core installed its schema', $e->getMessage() );
		}
	}
	if ( '' !== $snapshot && is_file( $snapshot ) ) {
		$baseline = json_decode( (string) file_get_contents( $snapshot ), true );
		$before   = (array) ( $baseline['evidence']['snapshot'] ?? array() );
		$offset   = (int) ( $baseline['evidence']['debug_log_offset'] ?? 0 );
		$after    = $cpsa_snapshot();
		foreach ( array( 'users', 'posts', 'comments', 'content_sha256', 'users_sha256', 'blogname' ) as $key ) {
			$cpsa_check( isset( $before[ $key ] ) && $before[ $key ] === $after[ $key ], 'existing site data unchanged across upload + activation: ' . $key, (string) ( $before[ $key ] ?? '?' ) . ' -> ' . (string) $after[ $key ] );
		}
		$problems = $cpsa_log_problems( $offset );
		$cpsa_evidence['log_problems_since_snapshot'] = $problems;
		$cpsa_check( array() === $problems, 'verify: no PHP warnings/notices/fatals logged since the snapshot (upload, install, activation, first admin pages)', implode( ' | ', array_slice( $problems, 0, 3 ) ) );
	}
	$cpsa_finish( $cpsa_mode, $out );
}

echo "FAIL unknown mode '{$cpsa_mode}' (snapshot|install|verify)\n";
$cpsa_finish( $cpsa_mode, '' );

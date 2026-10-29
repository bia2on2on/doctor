<?php
/**
 * Unit/security tests for the separate scoped demo mode.
 *
 * Run: php tools/disposable-demo-installer/tests/scoped-run-tests.php
 * These are pure in-memory tests; they are not real WordPress/database/browser
 * acceptance evidence.
 *
 * @package CpmsDisposableDemo
 */

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Audit {
	if ( ! class_exists( 'ClinicCore\\Infrastructure\\Audit\\HashChain' ) ) {
		final class HashChain {
			public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
			public static function computeRowHash( string $prevHash, array $fields ): string {
				ksort( $fields );
				return hash( 'sha256', $prevHash . ( json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '' ) );
			}
			public static function fieldsFor( array $row ): array {
				$int = static fn ( $v ) => ( null === $v || '' === $v ) ? null : (int) $v;
				$str = static fn ( $v ) => ( null === $v || '' === $v ) ? null : (string) $v;
				return array(
					'clinic_id'        => $int( $row['clinic_id'] ?? null ),
					'actor_wp_user_id' => $int( $row['actor_wp_user_id'] ?? null ),
					'actor_role'       => $str( $row['actor_role'] ?? null ),
					'action'           => $str( $row['action'] ?? null ),
					'resource_type'    => $str( $row['resource_type'] ?? null ),
					'resource_id'      => $int( $row['resource_id'] ?? null ),
					'patient_id'       => $int( $row['patient_id'] ?? null ),
					'ip_hash'          => $str( $row['ip_hash'] ?? null ),
					'session_id'       => $str( $row['session_id'] ?? null ),
					'request_id'       => $str( $row['request_id'] ?? null ),
					'created_at'       => $str( $row['created_at'] ?? null ),
				);
			}
		}
	}
}

namespace {
	use ClinicCore\Infrastructure\Audit\HashChain;
	use CpmsDemo\ScopedAuditGuard;
	use CpmsDemo\ScopedBaseline;
	use CpmsDemo\ScopedManifest;
	use CpmsDemo\ScopedPreflight;
	use CpmsDemo\ScopedRequest;

	require_once __DIR__ . '/bootstrap.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-manifest.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-audit-guard.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-baseline.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-preflight.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-request.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-otp.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-provisioner.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-teardown.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-admin.php';

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ) { return true; }
	}
	if ( ! function_exists( 'add_submenu_page' ) ) {
		function add_submenu_page( ...$args ) { return 'cpms_page_cpsd'; }
	}

	// The evaluation console must remain reachable for a legitimate site
	// administrator even when the production clinical menu hides/reorders rows.
	$GLOBALS['cpdd_test_management_pages'] = array();
	$GLOBALS['cpdd_test_hooks']           = array();
	CpmsDemo\ScopedAdmin::register_menu();
	$scoped_page = $GLOBALS['cpdd_test_management_pages'][0] ?? array();
	Cpdd_Test::ok(
		'cpms-scoped-demo' === ( $scoped_page['menu_slug'] ?? '' )
		&& 'manage_options' === ( $scoped_page['capability'] ?? '' )
		&& array( CpmsDemo\ScopedAdmin::class, 'render' ) === ( $scoped_page['callback'] ?? null )
		&& in_array( 'load-tools_page_cpms-scoped-demo', array_column( $GLOBALS['cpdd_test_hooks'], 'hook' ), true ),
		'menu: scoped demo is a manage_options Tools page with its dispatch hook, not a clinical-menu child'
	);
	Cpdd_Test::ok(
		isset( ScopedRequest::actions()[ ScopedRequest::ACTION_ROTATE ] )
		&& 'ROTATE_SCOPED_DEMO_CREDENTIALS' === ScopedRequest::actions()[ ScopedRequest::ACTION_ROTATE ]['phrase'],
		'credentials: scoped rotation has its own nonce/confirmation gate'
	);

	$rotate_env = new Cpdd_Fake_Env();
	$rotate_env->seed_clean_site();
	$rotate_ids = array();
	foreach ( CpmsDemo\Credentials::PERSONA_ORDER as $rotate_persona ) {
		$rotate_ids[ $rotate_persona ] = $rotate_env->create_user( array(
			'user_login' => 'cpsd-' . $rotate_persona . '-abcdef0123456789',
			'user_pass'  => 'old-' . $rotate_persona,
			'user_email' => $rotate_persona . '@demo.invalid',
			'role'       => 'subscriber',
		) );
	}
	$rotate_manifest = array( 'status' => 'active', 'fixture_id' => 'abcdef0123456789', 'admin_id' => 1, 'ids' => array( 'user_ids' => $rotate_ids ) );
	$rotated = ( new CpmsDemo\Credentials( $rotate_env ) )->rotate( $rotate_manifest, 'cpsd-' );
	Cpdd_Test::ok( 8 === count( $rotated ) && count( array_unique( $rotated ) ) === 8, 'credentials: scoped cpsd- accounts rotate to eight independent fresh passwords' );
	Cpdd_Test::refuses( static fn () => ( new CpmsDemo\Credentials( $rotate_env ) )->rotate( $rotate_manifest, 'cpdd-' ), 'ROTATE_SCOPE', 'credentials: strict cpdd- prefix cannot rotate scoped cpsd- accounts' );

	final class Cpsd_Fake_Audit_Platform extends Cpdd_Fake_Platform {
		public array $audit_rows = array();
		public function table( string $short ): string { return $short; }
		public function fetch_all( string $sql, array $params = array() ): array {
			$after = (int) ( $params[0] ?? 0 );
			$out   = array();
			foreach ( $this->audit_rows as $row ) {
				if ( (int) $row['id'] > $after ) {
					$out[] = $row;
				}
			}
			usort( $out, static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] );
			return array_slice( $out, 0, (int) ( $params[1] ?? 1000 ) );
		}
	}

	function cpsd_fixture(): array {
		$env = new Cpdd_Fake_Env();
		$env->seed_clean_site();
		return array( 'env' => $env, 'manifest' => new ScopedManifest( $env ) );
	}

	function cpsd_manifest_data(): array {
		return array(
			'fixture_id' => str_repeat( 'a', 32 ),
			'status'     => 'active',
			'admin_id'   => 1,
			'ids'        => array( 'clinic_id' => 2, 'organization_id' => 2, 'user_ids' => array( 'patient' => 101 ) ),
		);
	}

	function cpsd_post( array $ctx, string $action, array $overrides = array() ): array {
		$request = new ScopedRequest( $ctx['env'], $ctx['manifest'] );
		return array_merge(
			array(
				'cpdd_nonce'   => $request->nonce( $action ),
				'cpdd_confirm' => $request->phrase( $action ),
				'cpdd_site'    => $ctx['manifest']->site_fingerprint(),
			),
			$overrides
		);
	}

	// 1. Scoped manifest uses a different option, format, fixture ID and HMAC.
	$ctx = cpsd_fixture();
	$data = cpsd_manifest_data();
	$sealed = $ctx['manifest']->save( $data );
	Cpdd_Test::equals( 2, (int) $sealed['format'], 'scoped manifest format is 2' );
	Cpdd_Test::equals( 'scoped', (string) $sealed['mode'], 'scoped mode marker is stored' );
	Cpdd_Test::equals( $sealed['fixture_id'], (string) $ctx['manifest']->load()['fixture_id'], 'sealed scoped manifest reloads' );
	Cpdd_Test::equals( false, is_array( get_option( 'cpdd_manifest', false ) ), 'strict manifest is not written by scoped mode' );
	Cpdd_Test::ok( 1 === preg_match( '/^[a-f0-9]{32}$/D', (string) $ctx['manifest']->fixture_id() ), 'scoped fixture ID is 128-bit hex' );

	$tampered = $sealed;
	$tampered['mac'] = str_repeat( '0', 64 );
	update_option( 'cpsd_manifest', $tampered );
	Cpdd_Test::refuses( static fn () => $ctx['manifest']->load(), 'MANIFEST_TAMPERED', 'tampered scoped manifest is refused' );

	// 2. Mode activation is explicit, HMAC-sealed and site-bound.
	$ctx = cpsd_fixture();
	Cpdd_Test::equals( false, $ctx['manifest']->mode_enabled(), 'scoped mode is disabled by default' );
	$ctx['manifest']->enable_mode( 1 );
	Cpdd_Test::equals( true, $ctx['manifest']->mode_enabled(), 'explicit mode activation is accepted' );
	$mode = get_option( 'cpsd_mode', array() );
	$mode['mac'] = str_repeat( '1', 64 );
	update_option( 'cpsd_mode', $mode );
	Cpdd_Test::equals( false, $ctx['manifest']->mode_enabled(), 'tampered mode marker is refused' );
	$ctx['manifest']->disable_mode();
	Cpdd_Test::equals( false, $ctx['manifest']->mode_enabled(), 'mode can be disabled' );

	// 3. Scoped request actions are distinct and enforce every gate.
	$ctx = cpsd_fixture();
	$request = new ScopedRequest( $ctx['env'], $ctx['manifest'] );
	Cpdd_Test::equals( 'I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE', $request->phrase( ScopedRequest::ACTION_ENABLE ), 'distinct enable phrase' );
	Cpdd_Test::equals( 'REMOVE_ONLY_SCOPED_DEMO_DATA', $request->phrase( ScopedRequest::ACTION_TEARDOWN ), 'distinct teardown phrase' );
	$request->authorize( ScopedRequest::ACTION_ENABLE, cpsd_post( $ctx, ScopedRequest::ACTION_ENABLE ) );
	Cpdd_Test::ok( true, 'valid scoped request is accepted' );
	Cpdd_Test::refuses( static fn () => $request->authorize( ScopedRequest::ACTION_ENABLE, cpsd_post( $ctx, ScopedRequest::ACTION_ENABLE, array( 'cpdd_confirm' => 'WRONG' ) ) ), 'CONFIRMATION', 'wrong phrase is refused' );
	Cpdd_Test::refuses( static fn () => $request->authorize( ScopedRequest::ACTION_ENABLE, cpsd_post( $ctx, ScopedRequest::ACTION_ENABLE, array( 'cpdd_nonce' => 'bad' ) ) ), 'NONCE', 'wrong nonce is refused' );
	Cpdd_Test::refuses( static fn () => $request->authorize( ScopedRequest::ACTION_ENABLE, cpsd_post( $ctx, ScopedRequest::ACTION_ENABLE, array( 'cpdd_site' => 'other-site' ) ) ), 'SITE_IDENTITY', 'cross-site fingerprint is refused' );

	// 4. Full audit chain verification uses the production HashChain contract.
	$base = array(
		'clinic_id'        => 1,
		'actor_wp_user_id' => 1,
		'actor_role'       => 'administrator',
		'action'           => 'BASELINE',
		'resource_type'    => 'clinic',
		'resource_id'      => 1,
		'patient_id'       => null,
		'ip_hash'          => null,
		'session_id'       => null,
		'request_id'       => null,
		'created_at'       => '2026-10-27 00:00:00.000',
		'before_json'      => null,
		'after_json'       => null,
		'meta_json'        => null,
	);
	$row1 = array( 'id' => 1, 'prev_hash' => HashChain::GENESIS ) + $base;
	$row1['row_hash'] = HashChain::computeRowHash( HashChain::GENESIS, HashChain::fieldsFor( $row1 ) );
	$row2 = array( 'id' => 2, 'prev_hash' => $row1['row_hash'], 'action' => 'SECOND' ) + $base;
	$row2['row_hash'] = HashChain::computeRowHash( $row2['prev_hash'], HashChain::fieldsFor( $row2 ) );
	$platform = new Cpsd_Fake_Audit_Platform();
	$platform->audit_rows = array( $row1, $row2 );
	$guard = new ScopedAuditGuard( $platform );
	$chain = $guard->verify_chain();
	Cpdd_Test::equals( true, (bool) $chain['ok'], 'valid full audit chain passes' );
	Cpdd_Test::equals( 2, (int) $chain['checked'], 'full audit chain checks both rows' );
	Cpdd_Test::equals( $row2['row_hash'], (string) $chain['tail'], 'audit chain tail is correct' );
	$broken = $row2;
	$broken['row_hash'] = str_repeat( '0', 64 );
	$platform->audit_rows = array( $row1, $broken );
	Cpdd_Test::equals( false, (bool) $guard->verify_chain()['ok'], 'broken audit chain is refused' );

	// 5. Uninstall preserves active scoped ownership metadata and removes clean tombstones.
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', true );
	}
	$uninstall_ctx = cpsd_fixture();
	$uninstall_manifest = $uninstall_ctx['manifest']->save( cpsd_manifest_data() );
	unset( $uninstall_manifest );
	$uninstall_ctx['env']->option_set( 'cpsd_tombstone', array( 'clean' => true ) );
	require CPMS_DEMO_DIR . 'uninstall.php';
	Cpdd_Test::ok( is_array( get_option( 'cpsd_manifest', false ) ), 'uninstall preserves active scoped manifest' );
	Cpdd_Test::ok( is_array( get_option( 'cpdd_uninstall_blocked', false ) ), 'uninstall records recovery marker for active scoped fixture' );

	$clean_ctx = cpsd_fixture();
	$clean_ctx['env']->option_set( 'cpsd_tombstone', array( 'clean' => true ) );
	require CPMS_DEMO_DIR . 'uninstall.php';
	Cpdd_Test::ok( false === get_option( 'cpsd_tombstone', false ), 'clean uninstall removes scoped tombstone' );
	Cpdd_Test::ok( false === get_option( 'cpsd_mode', false ), 'clean uninstall removes scoped mode marker' );

	// 7. Fail-closed host implicit-scope gate.
	//
	// The audit foreign key forces the three synthetic parent rows to stay behind
	// as suspended/inactive anchors, so `COUNT(*)` on cpms_clinics is
	// permanently one higher after a scoped demo. Production turns that count into
	// the implicit-Clinic decision (`=== 1` means "use the only Clinic"), so the
	// preflight must refuse a host whose count is 0 or 1 and accept only an
	// already multi-Clinic host, where adding one more row changes no decision.
	Cpdd_Test::equals( 'fail', ScopedPreflight::host_scope_decision( 0 )['status'], 'zero-Clinic host is refused by the implicit-scope gate' );
	Cpdd_Test::equals( 'fail', ScopedPreflight::host_scope_decision( 1 )['status'], 'single-Clinic host is refused by the implicit-scope gate' );
	Cpdd_Test::equals( 'pass', ScopedPreflight::host_scope_decision( 2 )['status'], 'multi-Clinic host passes the implicit-scope gate' );
	Cpdd_Test::equals( 'pass', ScopedPreflight::host_scope_decision( 7 )['status'], 'larger multi-Clinic host passes the implicit-scope gate' );

	// 8. Interrupted provisioning keeps a sealed pre-provisioning baseline so the
	// recovery teardown can still prove what pre-existed.
	$recovery_ctx = cpsd_fixture();
	$recovery_manifest = $recovery_ctx['manifest'];
	$recovery_manifest->save_pending_baseline( str_repeat( 'b', 32 ), array( 'cpms' => array( 'cpms_clinics' => array( 'max_id' => 2 ) ), 'wp' => array() ), array( 'clinic_count' => 2 ) );
	$pending = $recovery_manifest->load_pending_baseline( str_repeat( 'b', 32 ) );
	Cpdd_Test::ok( is_array( $pending ) && 2 === (int) $pending['host_before']['clinic_count'], 'sealed pending baseline is readable for its own fixture' );
	Cpdd_Test::equals( null, $recovery_manifest->load_pending_baseline( str_repeat( 'c', 32 ) ), 'pending baseline is refused for another fixture' );
	$pending_raw = get_option( 'cpsd_baseline_pending', array() );
	$pending_raw['host_before']['clinic_count'] = 1;
	update_option( 'cpsd_baseline_pending', $pending_raw );
	Cpdd_Test::equals( null, $recovery_manifest->load_pending_baseline( str_repeat( 'b', 32 ) ), 'tampered pending baseline is refused' );
	$recovery_manifest->clear_pending_baseline();
	Cpdd_Test::equals( false, $recovery_manifest->has_pending_baseline(), 'pending baseline is removed after a clean seal' );

	// 6. Static safety markers in the shipped scoped sources.
	$sources = glob( CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-*.php' ) ?: array();
	$all = '';
	foreach ( $sources as $file ) {
		$all .= (string) file_get_contents( $file );
	}
	Cpdd_Test::ok( false !== strpos( $all, 'never deletes' ) || 1 === preg_match( '/never deletes|Never deletes|عدم حذف/', $all ), 'audit preservation is documented in scoped sources' );
	Cpdd_Test::ok( 1 !== preg_match( '/DELETE FROM [^;]*cpms_audit_logs|TRUNCATE TABLE|CPMS_DEV_OTP_ECHO[^;]*define/i', $all ), 'no audit delete, truncate or dev OTP define in scoped sources' );
	Cpdd_Test::ok( 1 !== preg_match( '/DELETE FROM [^;]*WHERE\s+(created_at|request_id|ip_hash)/i', $all ), 'no broad time/request/IP deletion predicate' );

	exit( Cpdd_Test::summary() );
}

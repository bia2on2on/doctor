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
	use CpmsDemo\ScopedResidue;

	require_once __DIR__ . '/bootstrap.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-manifest.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-audit-guard.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-baseline.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-preflight.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-request.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-otp.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-provisioner.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-teardown.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-residue.php';
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
	$rotate_fixture = str_repeat( 'abcdef0123456789', 2 );
	foreach ( CpmsDemo\Credentials::PERSONA_ORDER as $rotate_persona ) {
		$rotate_ids[ $rotate_persona ] = $rotate_env->create_user( array(
			'user_login' => 'cpsd-' . $rotate_persona . '-' . $rotate_fixture,
			'user_pass'  => 'old-' . $rotate_persona,
			'user_email' => $rotate_persona . '@demo.invalid',
			'role'       => 'subscriber',
		) );
	}
	$rotate_manifest = array( 'status' => 'active', 'fixture_id' => $rotate_fixture, 'admin_id' => 1, 'ids' => array( 'user_ids' => $rotate_ids ) );
	$rotated = ( new CpmsDemo\Credentials( $rotate_env ) )->rotate( $rotate_manifest, 'cpsd-' );
	Cpdd_Test::ok( 8 === count( $rotated ) && count( array_unique( $rotated ) ) === 8, 'credentials: scoped cpsd- accounts rotate to eight independent fresh passwords' );
	Cpdd_Test::refuses( static fn () => ( new CpmsDemo\Credentials( $rotate_env ) )->rotate( $rotate_manifest, 'cpdd-' ), 'ROTATE_SCOPE', 'credentials: strict cpdd- prefix cannot rotate scoped cpsd- accounts' );
	$short_fixture_manifest = array_replace( $rotate_manifest, array( 'fixture_id' => 'abcdef0123456789' ) );
	Cpdd_Test::refuses( static fn () => ( new CpmsDemo\Credentials( $rotate_env ) )->rotate( $short_fixture_manifest, 'cpsd-' ), 'ROTATE_SCOPE', 'credentials: scoped rotation refuses a strict 64-bit fixture identity' );

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

	// 9. Explicit recovery of foreign-mobile OTP residue (Mission B / Task 4).
	//
	// A visitor requested a code on the demo booking page with a mobile that is
	// not the synthetic one. The production OTP flow left a challenge and a
	// log-provider SMS row inside the demo clinic; neither can be attributed to
	// a fixture artifact, so the teardown is (correctly) TEARDOWN_BLOCKED.
	final class Cpsd_Fake_Residue_Platform extends Cpdd_Fake_Platform {
		public array $audit_rows = array();
		public function fetch_all( string $sql, array $params = array() ): array {
			if ( str_contains( $sql, 'cpms_audit_logs' ) ) {
				$after = (int) ( $params[0] ?? 0 );
				$out   = array();
				foreach ( $this->audit_rows as $row ) {
					if ( (int) $row['id'] > $after ) {
						$out[] = $row;
					}
				}
				return array_slice( $out, 0, (int) ( $params[1] ?? 1000 ) );
			}
			return parent::fetch_all( $sql, $params );
		}
		public function audit( string $action, int $actor_id, int $clinic_id, array $meta ): void {
			$this->calls[] = 'audit:' . $action;
			$prev = array() === $this->audit_rows ? HashChain::GENESIS : (string) end( $this->audit_rows )['row_hash'];
			$row  = array(
				'id'               => count( $this->audit_rows ) + 1,
				'prev_hash'        => $prev,
				'clinic_id'        => $clinic_id,
				'actor_wp_user_id' => $actor_id,
				'actor_role'       => 'wordpress_administrator',
				'action'           => $action,
				'resource_type'    => 'clinic',
				'resource_id'      => $clinic_id,
				'patient_id'       => null,
				'ip_hash'          => null,
				'session_id'       => null,
				'request_id'       => null,
				'created_at'       => '2026-10-30 00:00:00.000',
				'before_json'      => null,
				'after_json'       => null,
				'meta_json'        => (string) json_encode( $meta ),
			);
			$row['row_hash']    = HashChain::computeRowHash( $prev, HashChain::fieldsFor( $row ) );
			$this->audit_rows[] = $row;
		}
	}

	function cpsd_residue_world( string $foreign = '09121234567', string $expires = '2000-01-01 00:00:00', ?string $consumed = null, string $provider = 'log', string $sms_status = 'SENT' ): array {
		$env = new Cpdd_Fake_Env();
		$env->seed_clean_site();
		$platform = new Cpsd_Fake_Residue_Platform();
		$platform->seed_clean_install();
		$platform->audit( 'BASELINE', 1, 1, array() );
		$manifest = new ScopedManifest( $env );
		$data = cpsd_manifest_data();
		$data['patient_mobile'] = '09001234567';
		$data['baseline'] = array( 'cpms' => array( 'cpms_otp_tokens' => array( 'max_id' => 10 ), 'cpms_sms_messages' => array( 'max_id' => 20 ) ), 'wp' => array() );
		$sealed = $manifest->save( $data );
		// Baseline (pre-existing, foreign clinic) rows must never be touched.
		$platform->tables_data['cpms_otp_tokens'] = array(
			array( 'id' => 5, 'clinic_id' => 1, 'mobile' => $foreign, 'purpose' => 'login', 'expires_at' => '2000-01-01 00:00:00', 'consumed_at' => null, 'attempts' => 0, 'created_at' => '1999-12-31 23:55:00' ),
			// Synthetic patient challenge inside the demo clinic: owned by the teardown, not by residue recovery.
			array( 'id' => 11, 'clinic_id' => 2, 'mobile' => '09001234567', 'purpose' => 'login', 'expires_at' => '2000-01-01 00:00:00', 'consumed_at' => null, 'attempts' => 0, 'created_at' => '1999-12-31 23:55:00' ),
			// The foreign-mobile challenge of the demo clinic.
			array( 'id' => 12, 'clinic_id' => 2, 'mobile' => $foreign, 'purpose' => 'login', 'expires_at' => $expires, 'consumed_at' => $consumed, 'attempts' => 0, 'created_at' => '1999-12-31 23:55:00' ),
		);
		$platform->tables_data['cpms_sms_messages'] = array(
			array( 'id' => 15, 'clinic_id' => 1, 'recipient' => $foreign, 'event' => 'otp', 'provider' => 'log', 'status' => 'SENT', 'context_type' => 'otp_token', 'context_id' => 5, 'created_at' => '1999-12-31 23:55:00' ),
			array( 'id' => 21, 'clinic_id' => 2, 'recipient' => '09001234567', 'event' => 'otp', 'provider' => 'log', 'status' => 'SENT', 'context_type' => 'otp_token', 'context_id' => 11, 'created_at' => '1999-12-31 23:55:00' ),
			array( 'id' => 22, 'clinic_id' => 2, 'recipient' => $foreign, 'event' => 'otp', 'provider' => $provider, 'status' => $sms_status, 'context_type' => 'otp_token', 'context_id' => 12, 'created_at' => '1999-12-31 23:55:00' ),
		);
		$platform->tables_data['cpms_patients']           = array();
		$platform->tables_data['cpms_patient_identities'] = array();
		$platform->tables_data['cpms_slot_holds']         = array();
		$guard   = new ScopedAuditGuard( $platform );
		$residue = new ScopedResidue( $env, $platform, $manifest, $guard );
		return array( 'env' => $env, 'platform' => $platform, 'manifest' => $manifest, 'sealed' => $sealed, 'residue' => $residue, 'guard' => $guard );
	}

	$w = cpsd_residue_world();
	$preview = $w['residue']->preview( $w['sealed'] );
	Cpdd_Test::equals( true, (bool) $preview['ok'], 'residue: expired foreign challenge + its log SMS echo are recoverable' );
	Cpdd_Test::equals( array( 12 ), $preview['otp_token_ids'], 'residue: only the post-baseline foreign-mobile challenge of the demo clinic is planned' );
	Cpdd_Test::equals( array( 22 ), $preview['sms_message_ids'], 'residue: only its log-provider SMS echo is planned' );
	Cpdd_Test::equals( array( '0912****567' ), $preview['mobiles_masked'], 'residue: the mobile is reported masked only' );
	Cpdd_Test::equals( 3, count( $w['platform']->tables_data['cpms_otp_tokens'] ), 'residue: preview deletes nothing' );
	$result = $w['residue']->execute( $w['sealed'], 1 );
	Cpdd_Test::equals( array( 'cpms_sms_messages' => 1, 'cpms_otp_tokens' => 1 ), $result['deleted'], 'residue: execute removes exactly the two planned rows' );
	$left_tokens = array_map( static fn ( array $r ): int => (int) $r['id'], $w['platform']->tables_data['cpms_otp_tokens'] );
	$left_sms    = array_map( static fn ( array $r ): int => (int) $r['id'], $w['platform']->tables_data['cpms_sms_messages'] );
	Cpdd_Test::equals( array( 5, 11 ), $left_tokens, 'residue: baseline and synthetic challenges survive' );
	Cpdd_Test::equals( array( 15, 21 ), $left_sms, 'residue: baseline and synthetic SMS rows survive' );
	Cpdd_Test::ok( in_array( 'audit:' . ScopedResidue::AUDIT_ACTION, $w['platform']->calls, true ), 'residue: the recovery is audited through the production audit service' );
	Cpdd_Test::equals( 2, count( $w['platform']->audit_rows ), 'residue: exactly one audit row was APPENDED (none edited, none deleted)' );
	Cpdd_Test::equals( true, (bool) $w['guard']->verify_chain()['ok'], 'residue: the append-only audit hash chain still verifies' );
	Cpdd_Test::ok( false === strpos( (string) $w['platform']->audit_rows[1]['meta_json'], '09121234567' ), 'residue: the audit row never carries the full foreign mobile' );
	Cpdd_Test::refuses( static fn () => $w['residue']->execute( $w['sealed'], 1 ), 'RESIDUE_NONE', 'residue: a second run finds nothing and deletes nothing' );

	$w = cpsd_residue_world( '09121234567', '2999-01-01 00:00:00' );
	$preview = $w['residue']->preview( $w['sealed'] );
	Cpdd_Test::equals( false, (bool) $preview['ok'], 'residue: a LIVE foreign challenge blocks recovery' );
	Cpdd_Test::ok( str_starts_with( (string) ( $preview['problems'][0] ?? '' ), 'RESIDUE_LIVE_CHALLENGE' ), 'residue: the live challenge is named as the reason' );
	Cpdd_Test::refuses( static fn () => $w['residue']->execute( $w['sealed'], 1 ), 'RESIDUE_LIVE_CHALLENGE', 'residue: execute refuses while the challenge is live' );
	Cpdd_Test::equals( 3, count( $w['platform']->tables_data['cpms_sms_messages'] ), 'residue: nothing deleted on refusal' );

	$w = cpsd_residue_world( '09121234567', '2000-01-01 00:00:00', null, 'kavenegar' );
	$preview = $w['residue']->preview( $w['sealed'] );
	Cpdd_Test::equals( false, (bool) $preview['ok'], 'residue: an SMS row that is not a log-provider OTP echo is out of scope' );
	Cpdd_Test::ok( str_starts_with( (string) ( $preview['problems'][0] ?? '' ), 'RESIDUE_OUT_OF_SCOPE' ), 'residue: out-of-scope SMS row is reported, not deleted' );

	$w = cpsd_residue_world( '09121234567', '2000-01-01 00:00:00', null, 'log', 'QUEUED' );
	Cpdd_Test::equals( false, (bool) $w['residue']->preview( $w['sealed'] )['ok'], 'residue: an in-flight SMS row is never removed' );

	$w = cpsd_residue_world();
	$w['platform']->tables_data['cpms_patients'][] = array( 'id' => 900, 'clinic_id' => 1, 'mobile' => '09121234567', 'first_name' => 'واقعی', 'last_name' => 'واقعی' );
	$preview = $w['residue']->preview( $w['sealed'] );
	Cpdd_Test::equals( false, (bool) $preview['ok'], 'residue: a mobile known to a patient record is refused' );
	Cpdd_Test::ok( str_starts_with( (string) ( $preview['problems'][0] ?? '' ), 'RESIDUE_MOBILE_KNOWN: 0912****567 -> cpms_patients.mobile' ), 'residue: the refusal names the holder, masked' );
	Cpdd_Test::refuses( static fn () => $w['residue']->execute( $w['sealed'], 1 ), 'RESIDUE_MOBILE_KNOWN', 'residue: execute refuses a known mobile' );
	Cpdd_Test::equals( 3, count( $w['platform']->tables_data['cpms_otp_tokens'] ), 'residue: a known mobile keeps every row' );

	$w = cpsd_residue_world( '989121234567' );
	$w['platform']->tables_data['cpms_patient_identities'][] = array( 'id' => 901, 'organization_id' => 1, 'normalized_mobile' => '09121234567' );
	Cpdd_Test::equals( false, (bool) $w['residue']->preview( $w['sealed'] )['ok'], 'residue: mobile normalisation (98-prefix) still finds the identity holder' );

	$w = cpsd_residue_world();
	$tampered = $w['sealed'];
	$tampered['mac'] = str_repeat( '0', 64 );
	Cpdd_Test::equals( array( 'MANIFEST_TAMPERED' ), $w['residue']->preview( $tampered )['problems'], 'residue: a tampered manifest is refused' );
	$no_baseline = $w['sealed'];
	unset( $no_baseline['baseline'] );
	$no_baseline = $w['manifest']->save( $no_baseline );
	Cpdd_Test::ok( str_starts_with( (string) ( $w['residue']->preview( $no_baseline )['problems'][0] ?? '' ), 'BASELINE_MISSING' ), 'residue: without a sealed baseline nothing is bounded, nothing is deleted' );

	// The request layer: distinct phrases, distinct nonces, same gates.
	$ctx = cpsd_fixture();
	$request = new ScopedRequest( $ctx['env'], $ctx['manifest'] );
	Cpdd_Test::equals( 'REMOVE_EXPIRED_DEMO_OTP_RESIDUE', $request->phrase( ScopedRequest::ACTION_RESIDUE ), 'residue: distinct destructive phrase' );
	Cpdd_Test::equals( 'PREVIEW_EXPIRED_DEMO_OTP_RESIDUE', $request->phrase( ScopedRequest::ACTION_RESIDUE_PREVIEW ), 'residue: distinct preview phrase' );
	Cpdd_Test::refuses( static fn () => $request->authorize( ScopedRequest::ACTION_RESIDUE, cpsd_post( $ctx, ScopedRequest::ACTION_RESIDUE, array( 'cpdd_confirm' => 'REMOVE_ONLY_SCOPED_DEMO_DATA' ) ) ), 'CONFIRMATION', 'residue: the teardown phrase does not authorize residue recovery' );

	// 7. The legacy Tools URL redirect covers the scoped page under its real slug.
	Cpdd_Test::equals( \CpmsDemo\ScopedAdmin::PAGE, \CpmsDemo\Admin::SCOPED_PAGE, 'the scoped page slug mirrored in Admin never drifts from ScopedAdmin::PAGE' );
	Cpdd_Test::ok( in_array( \CpmsDemo\ScopedAdmin::PAGE, \CpmsDemo\Admin::legacy_redirect_pages(), true ), 'tools.php?page=cpms-scoped-demo is redirected to admin.php instead of a WordPress 403' );

	exit( Cpdd_Test::summary() );
}

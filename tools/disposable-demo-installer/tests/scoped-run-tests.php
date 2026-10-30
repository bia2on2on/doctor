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
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-fixture-namespace.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-manifest.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-audit-guard.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-baseline.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-preflight.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-request.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-otp.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-provisioner.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-ownership.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-recovery.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-teardown.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-admin.php';
	require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-dashboard.php';

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ) { return true; }
	}
	if ( ! function_exists( 'add_submenu_page' ) ) {
		function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, $callback ): string {
			$GLOBALS['cpsd_test_submenu_pages'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
			return $parent_slug . '_page_' . $menu_slug;
		}
	}
	// A top-level menu is FORBIDDEN for this companion: CPMS owns exactly one
	// («مدیریت مطب», registered by the production plugin). The stub records any
	// call so a regression fails a test instead of only splitting the menu.
	if ( ! function_exists( 'add_menu_page' ) ) {
		function add_menu_page( ...$args ) {
			$GLOBALS['cpsd_test_forbidden_top_level'][] = $args;
			return 'toplevel_page_' . (string) ( $args[3] ?? '' );
		}
	}
	if ( ! function_exists( 'wp_register_script' ) ) {
		function wp_register_script( ...$args ) { return true; }
	}
	if ( ! function_exists( 'wp_enqueue_script' ) ) {
		function wp_enqueue_script( ...$args ) { return true; }
	}
	if ( ! function_exists( 'wp_add_inline_script' ) ) {
		function wp_add_inline_script( ...$args ) { return true; }
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
		&& 'cpsd_rotate_credentials' === ScopedRequest::actions()[ ScopedRequest::ACTION_ROTATE ]['nonce']
		&& '' === ScopedRequest::actions()[ ScopedRequest::ACTION_ROTATE ]['phrase'],
		'credentials: scoped rotation keeps its own nonce and needs no typed phrase'
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
	Cpdd_Test::equals( '', $request->phrase( ScopedRequest::ACTION_ENABLE ), 'enable needs no typed phrase' );
	Cpdd_Test::equals( false, $request->phrase_required( ScopedRequest::ACTION_ENABLE ), 'enable is phrase-free but nonce/site/capability gated' );
	Cpdd_Test::equals( false, $request->phrase_required( ScopedRequest::ACTION_PREPARE ), 'one-click prepare is phrase-free' );
	Cpdd_Test::equals( false, $request->phrase_required( ScopedRequest::ACTION_RESUME ), 'safe resume is phrase-free' );
	Cpdd_Test::equals( 'REMOVE_ONLY_SCOPED_DEMO_DATA', $request->phrase( ScopedRequest::ACTION_TEARDOWN ), 'distinct teardown phrase' );
	Cpdd_Test::equals( true, $request->phrase_required( ScopedRequest::ACTION_TEARDOWN ), 'teardown keeps its typed phrase' );
	$request->authorize( ScopedRequest::ACTION_ENABLE, cpsd_post( $ctx, ScopedRequest::ACTION_ENABLE ) );
	Cpdd_Test::ok( true, 'valid scoped request is accepted' );
	// A phrase-free action must IGNORE any cpdd_confirm value; the gates that
	// actually protect it (nonce, capability, site identity, HTTPS) must not.
	$request->authorize( ScopedRequest::ACTION_ENABLE, cpsd_post( $ctx, ScopedRequest::ACTION_ENABLE, array( 'cpdd_confirm' => 'WRONG' ) ) );
	Cpdd_Test::ok( true, 'a phrase-free action cannot be unlocked with a phrase and does not depend on one' );
	Cpdd_Test::refuses( static fn () => $request->authorize( ScopedRequest::ACTION_TEARDOWN, cpsd_post( $ctx, ScopedRequest::ACTION_TEARDOWN, array( 'cpdd_confirm' => 'WRONG' ) ) ), 'CONFIRMATION', 'the destructive teardown still refuses a wrong phrase' );
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

	// 7b. The real-host case: a PRIVATE TEST SITE that has exactly one Clinic.
	//
	// The Owner's own install is this shape, and refusing it outright made the
	// demo unusable there. The consequence is real, not cosmetic, so it is not
	// waived: the administrator must state, in a sealed record naming this site
	// and its host, that the site is a private test site and that the permanent
	// implicit-scope change is acceptable.
	$single_no_consent = ScopedPreflight::host_scope_decision( 1 );
	Cpdd_Test::equals( 'fail', $single_no_consent['status'], 'single-Clinic host is still refused without the explicit private-site acceptance' );
	Cpdd_Test::ok(
		false !== strpos( $single_no_consent['detail'], 'آزمایشی خصوصی' ),
		'the refusal tells the operator that a private test site can be accepted instead of dead-ending'
	);
	$single_consent = ScopedPreflight::host_scope_decision( 1, true, 'begoobehesh.ir' );
	Cpdd_Test::equals( 'pass', $single_consent['status'], 'single-Clinic host passes once the private-site acceptance is recorded' );
	Cpdd_Test::ok(
		false !== strpos( $single_consent['detail'], 'begoobehesh.ir' ) && false !== strpos( $single_consent['detail'], 'بایگانی' ),
		'the accepted state still states the host and the permanent archive consequence'
	);
	Cpdd_Test::equals( 'pass', ScopedPreflight::host_scope_decision( 2, false )['status'], 'the acceptance is not needed on an already multi-Clinic host' );
	Cpdd_Test::equals( 'fail', ScopedPreflight::host_scope_decision( 0, true )['status'], 'a zero-Clinic host cannot be accepted into scoped mode' );

	// The acceptance is a sealed, site- and host-bound record: it cannot be
	// hand-written, cannot be replayed from another site, and cannot be granted
	// by a request payload.
	$consent_ctx = cpsd_fixture();
	$consent_manifest = $consent_ctx['manifest'];
	$consent_host     = ScopedRequest::private_host_phrase( $consent_ctx['env'] );
	Cpdd_Test::equals( null, $consent_manifest->private_host_consent(), 'no private-site acceptance exists by default' );
	$consent_manifest->enable_private_host( 1, $consent_host );
	$stored = $consent_manifest->private_host_consent();
	Cpdd_Test::ok( is_array( $stored ) && $consent_host === (string) $stored['host'], 'the private-site acceptance is stored and verified for this host' );
	Cpdd_Test::ok( '' !== (string) ( $stored['at'] ?? '' ) && 1 === (int) ( $stored['actor_id'] ?? 0 ), 'the acceptance records who accepted it and when' );

	$raw = $consent_ctx['env']->option_get( ScopedManifest::PRIVATE_HOST_OPTION, false );
	Cpdd_Test::ok( is_array( $raw ) && isset( $raw['mac'] ), 'the acceptance lives in its own separately sealed option' );
	$raw['host'] = 'evil.example';
	$consent_ctx['env']->option_set( ScopedManifest::PRIVATE_HOST_OPTION, $raw );
	Cpdd_Test::equals( null, $consent_manifest->private_host_consent(), 'a hand-edited acceptance fails its HMAC and is ignored' );
	$consent_manifest->enable_private_host( 1, $consent_host );
	Cpdd_Test::ok( is_array( $consent_manifest->private_host_consent() ), 're-recording the acceptance restores it' );

	$wrong_host_ctx = cpsd_fixture();
	$wrong_host_code = '';
	try {
		$wrong_host_ctx['manifest']->enable_private_host( 1, 'someone-else.example' );
	} catch ( CpmsDemo\Exception $e ) {
		$wrong_host_code = $e->demo_code();
	}
	Cpdd_Test::equals( 'PRIVATE_HOST_MISMATCH', $wrong_host_code, 'recording an acceptance for another host is refused at the write' );
	Cpdd_Test::equals( null, $wrong_host_ctx['manifest']->private_host_consent(), 'and nothing sealed is left behind, so it cannot be replayed across sites' );

	$foreign_site_ctx = cpsd_fixture();
	$foreign_site_ctx['manifest']->enable_private_host( 1, ScopedRequest::private_host_phrase( $foreign_site_ctx['env'] ) );
	$foreign_raw       = $foreign_site_ctx['env']->option_get( ScopedManifest::PRIVATE_HOST_OPTION, false );
	$foreign_raw['site'] = str_repeat( 'f', 32 );
	$foreign_site_ctx['env']->option_set( ScopedManifest::PRIVATE_HOST_OPTION, $foreign_raw );
	Cpdd_Test::equals( null, $foreign_site_ctx['manifest']->private_host_consent(), 'an acceptance sealed for another site fingerprint is refused' );

	$consent_manifest->disable_private_host();
	Cpdd_Test::equals( null, $consent_manifest->private_host_consent(), 'the acceptance can be withdrawn' );

	// The card the Owner actually sees on a single-Clinic host. Rendered for real
	// (not pattern-matched), because this is the one path CI's multi-Clinic host
	// never reaches: a broken card here would reach the operator first.
	$render_ctx    = cpsd_fixture();
	$render_request = new ScopedRequest( $render_ctx['env'], $render_ctx['manifest'] );
	$render_consent = new ReflectionMethod( CpmsDemo\ScopedAdmin::class, 'render_private_host_consent' );
	ob_start();
	$render_consent->invoke( null, $render_ctx['env'], $render_ctx['manifest'], $render_request, 1 );
	$pending_html = (string) ob_get_clean();
	Cpdd_Test::ok(
		false !== strpos( $pending_html, 'data-cpsd-private-host="pending"' )
		&& false !== strpos( $pending_html, 'data-cpsd-form="private_host"' )
		&& false !== strpos( $pending_html, 'data-cpdd-copy-value="' . ScopedRequest::private_host_phrase( $render_ctx['env'] ) . '"' ),
		'a single-Clinic host renders a plain-language acceptance form whose phrase is its own host'
	);
	Cpdd_Test::ok(
		false === strpos( $pending_html, 'password' ) && false === strpos( $pending_html, 'cpms_patients' ),
		'the acceptance card exposes no credential and no clinical table name'
	);
	$render_ctx['manifest']->enable_private_host( 1, ScopedRequest::private_host_phrase( $render_ctx['env'] ) );
	ob_start();
	$render_consent->invoke( null, $render_ctx['env'], $render_ctx['manifest'], $render_request, 1 );
	$accepted_html = (string) ob_get_clean();
	Cpdd_Test::ok(
		false !== strpos( $accepted_html, 'data-cpsd-private-host="accepted"' )
		&& false === strpos( $accepted_html, 'data-cpsd-form="private_host"' ),
		'once accepted, the card reports the recorded acceptance instead of asking again'
	);
	ob_start();
	$render_consent->invoke( null, $render_ctx['env'], $render_ctx['manifest'], $render_request, 2 );
	Cpdd_Test::equals( '', (string) ob_get_clean(), 'a multi-Clinic host is never asked to accept anything' );

	// An unreadable host must never collapse into "empty phrase matches empty
	// field": the request layer refuses rather than authorising nothing.
	$empty_host_ctx  = cpsd_fixture();
	$empty_host_ctx['env']->home = '';
	$empty_request   = new ScopedRequest( $empty_host_ctx['env'], $empty_host_ctx['manifest'] );
	$empty_code     = '';
	try {
		$empty_request->phrase( ScopedRequest::ACTION_PRIVATE_HOST );
	} catch ( CpmsDemo\Exception $e ) {
		$empty_code = $e->demo_code();
	}
	Cpdd_Test::equals( 'PRIVATE_HOST_MISMATCH', $empty_code, 'a host that cannot be read never yields an empty confirmation phrase' );
	ob_start();
	$render_consent->invoke( null, $empty_host_ctx['env'], $empty_host_ctx['manifest'], $empty_request, 1 );
	Cpdd_Test::equals( '', (string) ob_get_clean(), 'and the card is not rendered at all in that case' );

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

	// 9. A populated site whose earlier demo left `cpsd-` rows behind is NOT a dead end.
	//
	// The reported owner situation: eight `cpsd-…` accounts from an earlier run
	// whose sealed manifest is gone. The old rule failed the preflight on the
	// prefix alone, so the page permanently refused the only site the mode exists
	// for. The prefix is not the question - an exact collision is - and the exact
	// collision check lives in ScopedProvisioner::check_collisions(), before the
	// first synthetic row. What the preflight must do is REPORT, and never adopt.
	$names_ctx = cpsd_fixture();
	$names_fixture = str_repeat( '0123456789abcdef', 2 );
	foreach ( CpmsDemo\Credentials::PERSONA_ORDER as $names_persona ) {
		$names_ctx['env']->create_user(
			array(
				'user_login'   => 'cpsd-' . $names_persona . '-' . $names_fixture,
				'user_pass'    => 'x',
				'user_email'   => 'patient' === $names_persona ? '09001234567@otp.cpms.local' : 'cpsd-' . $names_persona . '-' . $names_fixture . '@demo.invalid',
				'display_name' => $names_persona,
				'role'         => 'subscriber',
			)
		);
	}
	// One account that merely shares the prefix and nothing else: it must not be
	// described as this package's, and it must never be treated as ours.
	$names_ctx['env']->create_user(
		array(
			'user_login'   => 'cpsd-office-manager',
			'user_pass'    => 'x',
			'user_email'   => 'manager@clinic.example',
			'display_name' => 'office manager',
			'role'         => 'editor',
		)
	);
	$names_platform = new Cpdd_Fake_Platform();
	$names_platform->seed_clean_install();
	$names_platform->tables_data['cpms_organizations'][] = array( 'id' => 40, 'slug' => 'cpsd-' . $names_fixture, 'status' => 'active' );
	$names_platform->tables_data['cpms_clinics'][]       = array( 'id' => 41, 'organization_id' => 40, 'slug' => 'cpsd-' . $names_fixture . '-clinic', 'status' => 'suspended' );
	$names_platform->tables_data['cpms_locations'][]     = array( 'id' => 42, 'clinic_id' => 41, 'slug' => 'cpsd-' . $names_fixture . '-main', 'is_primary' => 1, 'is_active' => 0 );
	$names_org_count = count( $names_platform->tables_data['cpms_organizations'] );

	$names_survey = CpmsDemo\FixtureNamespace::survey( $names_ctx['env'], $names_platform );
	Cpdd_Test::equals( 9, count( $names_survey['users'] ), 'namespace: every pre-existing cpsd- account is reported (8 personas + 1 other)' );
	Cpdd_Test::equals( 8, count( $names_survey['signature_users'] ), 'namespace: only the eight persona-shaped accounts carry the package signature' );
	Cpdd_Test::equals( array( 'cpsd-office-manager' ), $names_survey['other_users'], 'namespace: an unrelated cpsd- account is never labelled as this package\'s' );
	Cpdd_Test::equals( 1, (int) $names_survey['tenant_tables']['cpms_organizations'], 'namespace: an ACTIVE cpsd- Organization is counted (the seeded default is not)' );
	Cpdd_Test::equals( 0, (int) $names_survey['tenant_tables']['cpms_clinics'], 'namespace: a suspended cpsd- Clinic (teardown anchor) is not counted as live' );
	Cpdd_Test::equals( 0, (int) $names_survey['tenant_tables']['cpms_locations'], 'namespace: an inactive cpsd- Location is not counted as live' );
	Cpdd_Test::equals( 1 + 9, (int) $names_survey['total'], 'namespace: the total combines accounts, pages and live tenant slugs' );

	$names_row = ScopedPreflight::namespace_result( $names_survey );
	Cpdd_Test::equals( 'pass', (string) $names_row['status'], 'namespace: pre-existing cpsd- residue NEVER blocks the scoped preflight' );
	Cpdd_Test::ok(
		str_contains( (string) $names_row['detail'], 'حساب کاربری: 9' )
			&& str_contains( (string) $names_row['detail'], 'حذف' )
			&& str_contains( (string) $names_row['detail'], 'متصل' ),
		'namespace: the row states the count AND that nothing is deleted or adopted'
	);
	Cpdd_Test::ok(
		str_contains( (string) $names_row['detail'], 'تضاد نام دقیق' ),
		'namespace: the row explains that the exact-collision check still runs at provisioning time'
	);

	// Nothing was touched by the survey: same users, same rows.
	Cpdd_Test::equals( 10, count( $names_ctx['env']->users ), 'namespace: the survey is read-only (no account created or removed)' );
	Cpdd_Test::equals( $names_org_count, count( $names_platform->tables_data['cpms_organizations'] ), 'namespace: the survey is read-only (no tenant row created or removed)' );

	$empty_row = ScopedPreflight::namespace_result( CpmsDemo\FixtureNamespace::survey( cpsd_fixture()['env'], new Cpdd_Fake_Platform() ) );
	Cpdd_Test::equals( 'pass', (string) $empty_row['status'], 'namespace: an empty namespace also passes' );
	Cpdd_Test::ok( str_contains( (string) $empty_row['detail'], 'وجود ندارد' ), 'namespace: an empty namespace says so plainly' );

	// The signature is login shape AND e-mail domain; a name alone proves nothing.
	Cpdd_Test::equals( true, CpmsDemo\FixtureNamespace::persona_signature( 'cpsd-doctor-' . str_repeat( 'a', 32 ), 'cpsd-doctor-' . str_repeat( 'a', 32 ) . '@demo.invalid' ), 'namespace: a persona-shaped login with the package e-mail domain is labelled' );
	Cpdd_Test::equals( false, CpmsDemo\FixtureNamespace::persona_signature( 'cpsd-doctor-' . str_repeat( 'a', 32 ), 'real.doctor@clinic.example' ), 'namespace: the same login with a real domain is NOT labelled as this package\'s' );
	Cpdd_Test::equals( false, CpmsDemo\FixtureNamespace::persona_signature( 'cpsd-doctor-' . str_repeat( 'a', 32 ), '' ), 'namespace: a missing e-mail is never a signature' );
	Cpdd_Test::equals( false, CpmsDemo\FixtureNamespace::persona_signature( 'cpsd-unknown-' . str_repeat( 'a', 32 ), 'x@demo.invalid' ), 'namespace: an unknown role is not a persona login' );
	Cpdd_Test::equals( false, CpmsDemo\FixtureNamespace::persona_signature( 'cpsd-doctor-' . str_repeat( 'a', 31 ), 'x@demo.invalid' ), 'namespace: a short fixture id is not a persona login' );

	// 10. The scoped page tells the operator what it will NOT do with those rows.
	$preserved_render = new ReflectionMethod( CpmsDemo\ScopedAdmin::class, 'render_preserved_namespace' );
	ob_start();
	$preserved_render->invoke( null, $names_ctx['env'], $names_platform );
	$preserved_html = (string) ob_get_clean();
	Cpdd_Test::ok( str_contains( $preserved_html, 'data-cpsd-preserved-namespace="10"' ), 'preserved card: rendered with the exact pre-existing total' );
	Cpdd_Test::ok( str_contains( $preserved_html, 'data-cpsd-preserved-user="cpsd-office-manager"' ), 'preserved card: every pre-existing account is listed by name' );
	Cpdd_Test::ok( str_contains( $preserved_html, 'حذف نمی‌شوند' ) && str_contains( $preserved_html, 'دکمه‌ای برای آن ندارد' ), 'preserved card: states that nothing is deleted and that no button can claim it' );
	Cpdd_Test::ok( str_contains( $preserved_html, 'اثبات مالکیت' ), 'preserved card: an apparent signature is explicitly not proof of ownership' );
	Cpdd_Test::ok( ! str_contains( $preserved_html, 'data-cpsd-form=' ), 'preserved card: exposes no action of any kind' );

	ob_start();
	$preserved_render->invoke( null, cpsd_fixture()['env'], new Cpdd_Fake_Platform() );
	Cpdd_Test::equals( '', (string) ob_get_clean(), 'preserved card: not rendered at all when the namespace is empty' );


	// -----------------------------------------------------------------------
	// 11. Recovery assessment: resume is proven, never guessed.
	// -----------------------------------------------------------------------
	/**
	 * A complete synthetic fixture, as a real `after_provision()` would leave it.
	 *
	 * Every id below is written into the sealed manifest AND into the fake
	 * database, so the assessment can compare the two. `$seal` adds exactly what
	 * the seal stage adds (ledger, owned map, audit-chain state), which is what
	 * distinguishes "active" from "interrupted after the last write".
	 *
	 * @param string $status Manifest status.
	 * @param string $stage  Recorded failed stage ('' = none).
	 * @param bool   $seal   Whether the seal evidence exists.
	 * @return array<string,mixed>
	 */
	function cpsd_full_demo( string $status = 'active', string $stage = '', bool $seal = true ): array {
		$env      = new Cpdd_Fake_Env();
		$env->seed_clean_site();
		$platform = new Cpdd_Fake_Platform();
		$platform->seed_clean_install();
		$platform->set_env( $env );
		$fixture  = str_repeat( 'ab', 16 );
		$users    = array();
		foreach ( CpmsDemo\Credentials::PERSONA_ORDER as $persona ) {
			$users[ $persona ] = $env->create_user(
				array(
					'user_login' => 'cpsd-' . $persona . '-' . $fixture,
					'user_pass'  => 'pw-' . $persona,
					'user_email' => $persona . '@demo.invalid',
					'role'       => 'patient' === $persona ? 'cpms_patient' : 'subscriber',
				)
			);
		}
		$platform->tables_data['cpms_organizations'][] = array( 'id' => 2, 'slug' => 'cpsd-' . $fixture, 'status' => 'active' );
		$platform->tables_data['cpms_clinics'][]       = array( 'id' => 2, 'organization_id' => 2, 'slug' => 'cpsd-' . $fixture . '-clinic', 'status' => 'active' );
		$platform->tables_data['cpms_locations'][]     = array( 'id' => 2, 'clinic_id' => 2, 'slug' => 'cpsd-' . $fixture . '-main', 'is_primary' => 1, 'is_active' => 1 );
		$platform->tables_data['cpms_locations'][]     = array( 'id' => 3, 'clinic_id' => 2, 'slug' => 'cpsd-' . $fixture . '-branch', 'is_primary' => 0, 'is_active' => 1 );
		$platform->tables_data['cpms_patients'][]      = array( 'id' => 4, 'clinic_id' => 2, 'mobile' => '09001234567' );
		$platform->tables_data['cpms_visits'][]        = array( 'id' => 5, 'clinic_id' => 2, 'patient_id' => 4 );
		$platform->tables_data['cpms_appointments'][]  = array( 'id' => 6, 'clinic_id' => 2, 'patient_id' => 4 );
		$platform->tables_data['cpms_invoices'][]      = array( 'id' => 7, 'clinic_id' => 2, 'visit_id' => 5 );
		$platform->tables_data['cpms_payments'][]      = array( 'id' => 8, 'clinic_id' => 2, 'invoice_id' => 7 );
		$page = $env->insert_page(
			array(
				'title'   => 'booking',
				'slug'    => 'cpsd-' . $fixture . '-booking',
				'content' => '[cpms_public_booking clinic_id="2"]',
				'author'  => 1,
			)
		);
		$ids = array(
			'user_ids'            => $users,
			'organization_id'     => 2,
			'clinic_id'           => 2,
			'location_ids'        => array( 2, 3 ),
			'clinician_id'        => 9,
			'clinician_id_second' => 10,
			'patient_id'          => 4,
			'appointment_id'      => 6,
			'visit_id'            => 5,
			'invoice_id'          => 7,
			'payment_id'          => 8,
			'booking_page_id'     => $page,
			'slot_ids'            => array( 11, 12, 13 ),
		);
		$manifest = new CpmsDemo\ScopedManifest( $env );
		$payload  = array(
			'fixture_id'     => $fixture,
			'status'         => $status,
			'admin_id'       => 1,
			'schema_version' => CPMS_DEMO_SCHEMA_VERSION,
			'ids'            => $ids,
			'slugs'          => array(
				'organization'       => 'cpsd-' . $fixture,
				'clinic'             => 'cpsd-' . $fixture . '-clinic',
				'location_primary'   => 'cpsd-' . $fixture . '-main',
				'location_secondary' => 'cpsd-' . $fixture . '-branch',
				'page'               => 'cpsd-' . $fixture . '-booking',
			),
			'links'          => array( 'booking' => 'https://demo.test/?page_id=' . $page ),
			'counts'         => array( 'future_slots' => 3 ),
		);
		if ( '' !== $stage ) {
			$payload['failed_stage'] = $stage;
		}
		if ( $seal ) {
			$payload['baseline']            = array( 'tables' => array( 'cpms_clinics' => array( 'count' => 1 ) ) );
			$payload['host_before']         = array( 'clinic_count' => 1 );
			$payload['owned']               = array( 'tables' => array( 'cpms_invoices' => array( 7 ) ) );
			$payload['audit_chain_at_seal'] = 'ok';
		}
		$manifest->save( $payload );
		$manifest->save_pending_baseline( $fixture, array( 'tables' => array( 'cpms_clinics' => array( 'count' => 1 ) ) ), array( 'clinic_count' => 1 ) );
		if ( $seal ) {
			( new CpmsDemo\ScopedOwnership( $env, $manifest ) )->open( $fixture, 2, 2 );
		}

		return array(
			'env'      => $env,
			'platform' => $platform,
			'manifest' => $manifest,
			'fixture'  => $fixture,
			'ids'      => $ids,
			'page'     => $page,
		);
	}

	$recovery = static function ( array $ctx ): array {
		return ( new CpmsDemo\ScopedRecovery( $ctx['env'], $ctx['platform'], $ctx['manifest'] ) )->assess( $ctx['manifest']->load() );
	};

	$active_ctx = cpsd_full_demo( 'active', '', true );
	$active     = $recovery( $active_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_ACTIVE, (string) $active['verdict'], 'recovery: a sealed active fixture is recognised as ready' );
	Cpdd_Test::equals( '', (string) $active['code'], 'recovery: the ready verdict carries no refusal code' );
	$active_check_ids = array_column( (array) $active['checks'], 'id' );
	foreach ( array( 'status', 'fixture', 'tenant', 'accounts', 'booking_page', 'baseline', 'ledger', 'completion', 'seal' ) as $expected_check ) {
		Cpdd_Test::ok( in_array( $expected_check, $active_check_ids, true ), 'recovery: evidence row present: ' . $expected_check );
	}

	// Interrupted inside the seal: every row exists, only the seal is missing.
	$seal_ctx  = cpsd_full_demo( 'failed', 'seal', false );
	$seal_eval = $recovery( $seal_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_RESUMABLE, (string) $seal_eval['verdict'], 'recovery: an interruption at the seal stage is safely resumable' );
	Cpdd_Test::equals( false, (bool) $seal_eval['sealed'], 'recovery: the resumable verdict reports that the seal is incomplete' );

	// Killed between the last save and the error handler: no failed stage.
	$killed_ctx = cpsd_full_demo( 'provisioning', '', false );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_RESUMABLE, (string) $recovery( $killed_ctx )['verdict'], 'recovery: a killed run with complete evidence is resumable even without a recorded stage' );

	// Status active, seal never completed (process died inside after_provision).
	$half_ctx = cpsd_full_demo( 'active', '', false );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_RESUMABLE, (string) $recovery( $half_ctx )['verdict'], 'recovery: active-but-unsealed is finished, not torn down' );

	// Interrupted earlier: production services are not idempotent, so refuse.
	$early_ctx = cpsd_full_demo( 'failed', 'clinical', false );
	$early     = $recovery( $early_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $early['verdict'], 'recovery: an interruption before the seal is refused' );
	Cpdd_Test::equals( 'RECOVERY_NOT_SAFE', (string) $early['code'], 'recovery: the refusal names a stable code' );
	Cpdd_Test::ok( str_contains( (string) $early['reason'], 'clinical' ), 'recovery: the refusal names the stage that stopped the run' );

	// A missing synthetic account: nothing is adopted, nothing is resumed.
	$missing_ctx = cpsd_full_demo( 'failed', 'seal', false );
	unset( $missing_ctx['env']->users[ $missing_ctx['ids']['user_ids']['doctor'] ] );
	$missing = $recovery( $missing_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $missing['verdict'], 'recovery: a missing sealed account blocks recovery' );
	Cpdd_Test::equals( 'DEMO_EVIDENCE_MISSING', (string) $missing['code'], 'recovery: the missing-account refusal is a missing-evidence refusal' );

	// Same id, different login: an account that only LOOKS like ours is refused.
	$renamed_ctx = cpsd_full_demo( 'failed', 'seal', false );
	$renamed_ctx['env']->users[ $renamed_ctx['ids']['user_ids']['doctor'] ]['login'] = 'cpsd-doctor-' . str_repeat( 'cd', 16 );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $recovery( $renamed_ctx )['verdict'], 'recovery: a renamed account (same id, other login) is not adopted' );

	// A persona that holds manage_options is refused.
	$admin_ctx = cpsd_full_demo( 'failed', 'seal', false );
	$admin_ctx['env']->caps[ $admin_ctx['ids']['user_ids']['accountant'] ]['manage_options'] = true;
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $recovery( $admin_ctx )['verdict'], 'recovery: a demo account with administrator authority blocks recovery' );

	// The demo tenant row must still be the sealed row.
	$tenant_ctx = cpsd_full_demo( 'failed', 'seal', false );
	$tenant_ctx['platform']->tables_data['cpms_clinics'] = array_values(
		array_filter(
			$tenant_ctx['platform']->tables_data['cpms_clinics'],
			static fn ( array $row ): bool => 2 !== (int) $row['id']
		)
	);
	$tenant = $recovery( $tenant_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $tenant['verdict'], 'recovery: a missing demo Clinic blocks recovery' );

	// A tampered ownership ledger is never silently rebuilt.
	$ledger_ctx = cpsd_full_demo( 'active', '', true );
	$ledger_raw = $ledger_ctx['env']->option_get( 'cpsd_ownership_ledger', array() );
	$ledger_raw['entries'][0]['payload']['clinic_id'] = 999;
	$ledger_ctx['env']->option_set( 'cpsd_ownership_ledger', $ledger_raw );
	$ledger = $recovery( $ledger_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $ledger['verdict'], 'recovery: a tampered ledger blocks everything' );
	Cpdd_Test::equals( 'SCOPED_OWNERSHIP_LEDGER_INVALID', (string) $ledger['code'], 'recovery: the tampered ledger names its stable code' );

	// No baseline anywhere: neither resume nor teardown can be proven.
	$no_base_ctx = cpsd_full_demo( 'failed', 'seal', false );
	$no_base_ctx['manifest']->clear_pending_baseline();
	$no_base = $recovery( $no_base_ctx );
	Cpdd_Test::equals( CpmsDemo\ScopedRecovery::VERDICT_BLOCKED, (string) $no_base['verdict'], 'recovery: a missing baseline blocks recovery' );

	// `finish()` refuses anything that is not provably resumable.
	$finish_ctx = cpsd_full_demo( 'active', '', true );
	$finish_provisioner = new CpmsDemo\ScopedProvisioner(
		$finish_ctx['env'],
		$finish_ctx['platform'],
		$finish_ctx['manifest'],
		new CpmsDemo\ScopedPreflight( $finish_ctx['env'], $finish_ctx['platform'], $finish_ctx['manifest'], new CpmsDemo\ScopedAuditGuard( $finish_ctx['platform'] ), new CpmsDemo\ScopedBaseline( $finish_ctx['env'], $finish_ctx['platform'] ) ),
		new CpmsDemo\Credentials( $finish_ctx['env'] ),
		new CpmsDemo\ScopedBaseline( $finish_ctx['env'], $finish_ctx['platform'] ),
		new CpmsDemo\ScopedAuditGuard( $finish_ctx['platform'] )
	);
	Cpdd_Test::refuses( static fn () => $finish_provisioner->finish( 1 ), 'ALREADY_PROVISIONED', 'resume: finishing an already-sealed demo is refused (no second seal)' );
	$early_finish = cpsd_full_demo( 'failed', 'finance', false );
	$early_provisioner = new CpmsDemo\ScopedProvisioner(
		$early_finish['env'],
		$early_finish['platform'],
		$early_finish['manifest'],
		new CpmsDemo\ScopedPreflight( $early_finish['env'], $early_finish['platform'], $early_finish['manifest'], new CpmsDemo\ScopedAuditGuard( $early_finish['platform'] ), new CpmsDemo\ScopedBaseline( $early_finish['env'], $early_finish['platform'] ) ),
		new CpmsDemo\Credentials( $early_finish['env'] ),
		new CpmsDemo\ScopedBaseline( $early_finish['env'], $early_finish['platform'] ),
		new CpmsDemo\ScopedAuditGuard( $early_finish['platform'] )
	);
	Cpdd_Test::refuses( static fn () => $early_provisioner->finish( 1 ), 'RECOVERY_NOT_SAFE', 'resume: finishing an earlier interruption is refused' );
	Cpdd_Test::equals( 'failed', (string) $early_finish['manifest']->load()['status'], 'resume: a refused finish leaves the manifest exactly as it was' );

	// -----------------------------------------------------------------------
	// 12. The dashboard: one page, one obvious action, no new authority.
	// -----------------------------------------------------------------------
	Cpdd_Test::equals( 'cpms-demo', CpmsDemo\Dashboard::PAGE, 'dashboard: canonical slug' );
	Cpdd_Test::equals( CpmsDemo\Dashboard::PAGE, CpmsDemo\Admin::DASHBOARD_PAGE, 'dashboard: the landing page and the menu agree on the slug' );
	// CPMS owns exactly ONE top-level menu; the companion must never add another.
	// Without the production menu in this request the dashboard is a Tools page.
	$GLOBALS['cpdd_test_management_pages']   = array();
	$GLOBALS['cpsd_test_submenu_pages']      = array();
	$GLOBALS['cpsd_test_forbidden_top_level'] = array();
	$GLOBALS['cpdd_test_hooks']              = array();
	CpmsDemo\Dashboard::register_menu();
	$tools_page = (array) ( $GLOBALS['cpdd_test_management_pages'][0] ?? array() );
	Cpdd_Test::equals( 'cpms-demo', (string) ( $tools_page['menu_slug'] ?? '' ), 'dashboard: registered under its own slug' );
	Cpdd_Test::equals( 'manage_options', (string) ( $tools_page['capability'] ?? '' ), 'dashboard: requires manage_options and nothing clinical' );
	Cpdd_Test::ok( array() === $GLOBALS['cpsd_test_forbidden_top_level'], 'dashboard: no top-level menu is created when the CPMS menu is absent' );
	Cpdd_Test::equals( array(), $GLOBALS['cpsd_test_submenu_pages'], 'dashboard: no submenu is invented for an absent parent' );
	Cpdd_Test::ok( in_array( 'load-tools_page_cpms-demo', array_column( $GLOBALS['cpdd_test_hooks'], 'hook' ), true ), 'dashboard: dispatch runs on the page load hook' );
	Cpdd_Test::ok( str_ends_with( CpmsDemo\Dashboard::page_url(), '/wp-admin/tools.php?page=cpms-demo' ), 'dashboard: its URL follows the menu it was actually registered under (' . CpmsDemo\Dashboard::page_url() . ')' );

	// With the production menu present it becomes a CHILD of that menu - the same
	// rule the strict console already follows - never a second top-level page.
	if ( ! class_exists( 'ClinicCore\\Admin\\CpmsAdminMenu' ) ) {
		eval( 'namespace ClinicCore\\Admin; class CpmsAdminMenu { public static function parent_slug(): string { return \'cpms-dashboard\'; } }' );
	}
	$GLOBALS['cpdd_test_management_pages']    = array();
	$GLOBALS['cpsd_test_submenu_pages']       = array();
	$GLOBALS['cpsd_test_forbidden_top_level'] = array();
	$GLOBALS['cpdd_test_hooks']               = array();
	CpmsDemo\Dashboard::register_menu();
	$submenu = (array) ( $GLOBALS['cpsd_test_submenu_pages'][0] ?? array() );
	Cpdd_Test::equals( 'cpms-dashboard', (string) ( $submenu['parent_slug'] ?? '' ), 'dashboard: hangs off the CPMS unified menu, not a new top-level one' );
	Cpdd_Test::equals( 'cpms-demo', (string) ( $submenu['menu_slug'] ?? '' ), 'dashboard: keeps its own slug as a CPMS child' );
	Cpdd_Test::equals( 'manage_options', (string) ( $submenu['capability'] ?? '' ), 'dashboard: the CPMS child needs manage_options only' );
	Cpdd_Test::ok( array() === $GLOBALS['cpsd_test_forbidden_top_level'], 'dashboard: no top-level menu is created even when a parent exists' );
	Cpdd_Test::equals( array(), $GLOBALS['cpdd_test_management_pages'], 'dashboard: it does not register twice' );
	Cpdd_Test::ok( in_array( 'load-cpms-dashboard_page_cpms-demo', array_column( $GLOBALS['cpdd_test_hooks'], 'hook' ), true ), 'dashboard: dispatch follows the CPMS child hook' );
	Cpdd_Test::ok( str_ends_with( CpmsDemo\Dashboard::page_url(), '/wp-admin/admin.php?page=cpms-demo' ), 'dashboard: its URL follows the CPMS parent when that menu exists (' . CpmsDemo\Dashboard::page_url() . ')' );

	$render_dashboard = static function ( ?array $ctx = null ): string {
		CpmsDemo\Dashboard::set_test_services( $ctx['env'] ?? null, $ctx['platform'] ?? null );
		CpmsDemo\Admin::set_test_services( $ctx['env'] ?? null, $ctx['platform'] ?? null );
		CpmsDemo\Dashboard::reset_state();
		ob_start();
		CpmsDemo\Dashboard::render();
		return (string) ob_get_clean();
	};

	// (a) A populated site with no demo: one primary action, no typed phrase.
	$dash_ctx = cpsd_full_demo( 'active', '', true );
	$dash_ctx['manifest']->delete();
	$dash_ctx['manifest']->disable_mode();
	$dash_html = $render_dashboard( $dash_ctx );
	Cpdd_Test::ok( str_contains( $dash_html, 'data-cpms-demo-state="prepare"' ), 'dashboard: a site with no demo offers the prepare state' );
	Cpdd_Test::ok( str_contains( $dash_html, 'data-cpsd-form="prepare"' ), 'dashboard: the primary action is the one-click prepare form' );
	Cpdd_Test::ok( ! str_contains( $dash_html, 'name="cpdd_confirm"' ), 'dashboard: no typed phrase is asked for to create the demo' );
	Cpdd_Test::ok( str_contains( $dash_html, 'data-cpsd-no-phrase="1"' ) || str_contains( $dash_html, 'button-hero' ), 'dashboard: the primary action is a hero button' );
	Cpdd_Test::ok( str_contains( $dash_html, 'data-cpms-demo-console="1"' ), 'dashboard: the advanced console is linked, not required' );

	// (b) A ready demo: enter-the-demo action, eight portals, one-click rotation.
	$ready_ctx = cpsd_full_demo( 'active', '', true );
	$ready_html = $render_dashboard( $ready_ctx );
	Cpdd_Test::ok( str_contains( $ready_html, 'data-cpms-demo-state="ready"' ), 'dashboard: a sealed demo renders the ready state' );
	Cpdd_Test::ok( str_contains( $ready_html, 'ورود به دموی آماده' ), 'dashboard: the primary action is the enter-the-demo button' );
	Cpdd_Test::ok( str_contains( $ready_html, 'id="cpms-demo-portals"' ) && str_contains( $ready_html, 'data-cpsd-persona-access="1"' ), 'dashboard: the eight portal rows are on the page' );
	$portal_rows = substr_count( $ready_html, 'data-cpsd-persona-portal=' );
	Cpdd_Test::equals( count( CpmsDemo\Credentials::PERSONA_ORDER ), $portal_rows, 'dashboard: exactly one row per persona' );
	Cpdd_Test::ok( str_contains( $ready_html, 'data-cpsd-form="rotate_credentials"' ), 'dashboard: credential rotation is offered in one click' );
	Cpdd_Test::ok( ! str_contains( $ready_html, 'name="cpdd_confirm"' ), 'dashboard: rotation asks for no typed phrase' );
	Cpdd_Test::ok( str_contains( $ready_html, 'data-cpms-demo-evidence=' ), 'dashboard: the ownership evidence is available, collapsed' );
	Cpdd_Test::ok( str_contains( $ready_html, 'data-cpms-demo-console="1"' ), 'dashboard: teardown is reachable, but only through the console' );
	Cpdd_Test::ok( ! str_contains( $ready_html, 'data-cpsd-form="teardown"' ), 'dashboard: teardown is never a one-click form' );

	// (c) A resumable interruption: the resume action, additively.
	$resume_ctx = cpsd_full_demo( 'failed', 'seal', false );
	$resume_html = $render_dashboard( $resume_ctx );
	Cpdd_Test::ok( str_contains( $resume_html, 'data-cpms-demo-state="resumable"' ), 'dashboard: a resumable interruption renders the resume state' );
	Cpdd_Test::ok( str_contains( $resume_html, 'data-cpsd-form="resume"' ), 'dashboard: the primary action is the resume form' );
	Cpdd_Test::ok( ! str_contains( $resume_html, 'name="cpdd_confirm"' ), 'dashboard: resume asks for no typed phrase' );

	// (d) An unsafe interruption: explain, never adopt, never auto-destroy.
	$blocked_ctx = cpsd_full_demo( 'failed', 'finance', false );
	$blocked_html = $render_dashboard( $blocked_ctx );
	Cpdd_Test::ok( str_contains( $blocked_html, 'data-cpms-demo-state="blocked"' ), 'dashboard: an unsafe interruption renders the blocked state' );
	Cpdd_Test::ok( ! str_contains( $blocked_html, 'data-cpsd-form="resume"' ), 'dashboard: blocked means no resume button' );
	Cpdd_Test::ok( ! str_contains( $blocked_html, 'data-cpsd-form="teardown"' ), 'dashboard: destructive cleanup is never on the one-click page' );
	Cpdd_Test::ok( str_contains( $blocked_html, 'data-cpms-demo-console="1"' ), 'dashboard: the blocked state points at the authorized console' );

	// (e) A manifest that does not verify: fail closed, no action at all.
	$tamper_ctx = cpsd_full_demo( 'active', '', true );
	$tampered   = $tamper_ctx['manifest']->load();
	$tampered['mac'] = str_repeat( '0', 64 );
	$tamper_ctx['env']->option_set( 'cpsd_manifest', $tampered );
	$tamper_html = $render_dashboard( $tamper_ctx );
	Cpdd_Test::ok( str_contains( $tamper_html, 'data-cpms-demo-state="manifest-invalid"' ), 'dashboard: an unverifiable manifest is refused in plain Persian' );
	Cpdd_Test::ok( ! str_contains( $tamper_html, 'data-cpsd-form=' ), 'dashboard: an unverifiable manifest renders zero action forms' );

	// (e2) No state is a dead end, and none links the console twice.
	$state_html = array(
		'prepare'          => $dash_html,
		'ready'            => $ready_html,
		'resumable'        => $resume_html,
		'blocked'          => $blocked_html,
		'manifest-invalid' => $tamper_html,
	);
	foreach ( $state_html as $state_name => $html ) {
		Cpdd_Test::equals( 1, substr_count( $html, 'data-cpms-demo-console="1"' ), 'dashboard: the ' . $state_name . ' state links the authorized console exactly once' );
	}
	Cpdd_Test::equals( 0, substr_count( $blocked_html, 'data-cpms-demo-console-hero="1"' ) - 1, 'dashboard: the blocked hero keeps its own marker, so no attribute is duplicated' );

	// (f) The dashboard never adds impersonation, credential storage or a bypass.
	$dashboard_source = (string) file_get_contents( CPMS_DEMO_DIR . 'includes/class-cpms-demo-dashboard.php' );
	foreach ( array( 'set_current_user(', 'wp_set_auth_cookie(', 'switch_to_user' ) as $forbidden ) {
		Cpdd_Test::ok( ! str_contains( $dashboard_source, $forbidden ), 'dashboard: no impersonation primitive: ' . $forbidden );
	}
	Cpdd_Test::ok( str_contains( $dashboard_source, 'phrase_required' ), 'dashboard: the rendered form follows the server-side phrase policy' );
	Cpdd_Test::ok( str_contains( $dashboard_source, '$request->authorize( $action, $post );' ), 'dashboard: every action goes through the shared authorization chain' );

	// (g) Static proof that every class reference in the demo's PHP resolves.
	// The `wp eval-file` drills are never executed by this harness, which is how a
	// bare `ScopedRecovery::class` in scoped-integration.php reached CI twice: it
	// resolved to the global `\ScopedRecovery`, `php -l` was satisfied and every
	// local suite stayed green. See class-refs.php.
	require_once __DIR__ . '/class-refs.php';
	$cpsd_ref_files = array( CPMS_DEMO_DIR . 'cpms-disposable-demo.php' );
	foreach ( array( 'includes', 'tests' ) as $cpsd_ref_dir ) {
		foreach ( (array) glob( CPMS_DEMO_DIR . $cpsd_ref_dir . '/*.php' ) as $cpsd_ref_file ) {
			$cpsd_ref_files[] = $cpsd_ref_file;
		}
	}
	Cpdd_Test::ok( count( $cpsd_ref_files ) > 30, 'class refs: the scan covers the entry file, includes and drills (' . count( $cpsd_ref_files ) . ' files)' );
	$cpsd_ref_problems = cpsd_class_ref_problems( $cpsd_ref_files );
	Cpdd_Test::equals( array(), $cpsd_ref_problems, 'class refs: every Name:: and new Name resolves (found: ' . implode( ' | ', $cpsd_ref_problems ) . ')' );

	// Positive control: without it this section could be a green no-op.
	$cpsd_control_file = (string) tempnam( sys_get_temp_dir(), 'cpsdrefs' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in the system temp dir.
	file_put_contents(
		$cpsd_control_file,
		"<?php\nnamespace CpmsDemo;\nuse CpmsDemo\\Env;\nfunction cpsd_control() { return array( Env::class, MissingThing::class ); }\n"
	);
	$cpsd_control = cpsd_class_ref_problems( array( $cpsd_control_file ) );
	unlink( $cpsd_control_file );
	Cpdd_Test::equals( 1, count( $cpsd_control ), 'class refs: the checker catches an un-imported class (control)' );
	Cpdd_Test::ok( str_contains( (string) ( $cpsd_control[0] ?? '' ), 'MissingThing' ), 'class refs: the control names the offending symbol' );

	exit( Cpdd_Test::summary() );
}

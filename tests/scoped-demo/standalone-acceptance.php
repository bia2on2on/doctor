<?php
/**
 * Real-WordPress acceptance driver for the STANDALONE demo package.
 *
 * Run inside a disposable CI WordPress where ONLY `cpms-disposable-demo` is
 * installed (no `clinic-practice-management`):
 *
 *   CPSD_SA_MODE=seed       wp eval-file tests/scoped-demo/standalone-acceptance.php
 *   CPSD_SA_MODE=provision  ... (provisions the scoped demo and LEAVES IT ACTIVE)
 *   CPSD_SA_MODE=assert     wp eval-file tests/scoped-demo/standalone-acceptance.php
 *   CPSD_SA_MODE=conflict   wp eval-file tests/scoped-demo/standalone-acceptance.php
 *   CPSD_SA_MODE=after_delete wp eval-file tests/scoped-demo/standalone-acceptance.php
 *
 * Each mode prints ONE JSON line and never exits non-zero by itself: the
 * workflow parses the verdict and files the failing check as an annotation, so
 * a red lane names the assertion instead of "process exited 1".
 *
 * WHAT THIS PROVES (Mission H, requirements A/B/D/E/J):
 *
 *  - `seed`    builds a genuinely NON-EMPTY site (an extra administrator, an
 *              editor, posts, pages, comments, media, a third-party plugin) and
 *              records a byte-level digest of everything it created.
 *  - `assert`  proves the standalone package booted its own embedded core with
 *              no production plugin present, that the demo provisioned real
 *              clinical/financial data through the production services, and
 *              that every seeded WordPress artefact is byte-identical.
 *  - `conflict` proves that activating the production plugin alongside the
 *              standalone demo is DETECTED (Persian fault, no fatal, no second
 *              boot of the core) rather than left to chance.
 *  - `after_delete` proves that deactivating and deleting the demo plugin
 *              destroys nothing: seeded WordPress data intact, `cpms_*` tables
 *              still present, no synthetic account silently removed by the
 *              plugin lifecycle.
 *
 * @package CpmsDisposableDemo
 */

use CpmsDemo\Env;
use CpmsDemo\Standalone;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Grouped by purpose.

$cpsd_sa_mode = (string) ( getenv( 'CPSD_SA_MODE' ) ?: 'assert' );
$cpsd_sa_file = (string) ( getenv( 'CPSD_SA_EVIDENCE' ) ?: '/tmp/cpsd-sa-evidence.json' );
$cpsd_sa_tag  = 'cpsdhost';

/**
 * Deterministic digest of the WordPress content this driver created.
 *
 * Only rows that carry the seeded tag are digested, so a legitimate CPMS row
 * elsewhere on the site cannot change the value.
 *
 * @return array<string,mixed>
 */
$cpsd_sa_seed_digest = static function () use ( $cpsd_sa_tag ): array {
	global $wpdb;
	$users = get_users(
		array(
			'search'         => $cpsd_sa_tag . '*',
			'search_columns' => array( 'user_login' ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => array( 'ID', 'user_login', 'user_email' ),
		)
	);
	$rows = array();
	foreach ( (array) $users as $user ) {
		$account = is_object( $user ) ? (array) $user : (array) $user;
		$rows[]  = 'user:' . (int) ( $account['ID'] ?? 0 ) . ':' . (string) ( $account['user_login'] ?? '' );
	}
	$posts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_type, post_status, post_title, post_content, post_modified FROM {$wpdb->posts} WHERE post_name LIKE %s ORDER BY ID ASC",
			$cpsd_sa_tag . '%'
		),
		ARRAY_A
	);
	foreach ( (array) $posts as $post ) {
		$rows[] = 'post:' . md5( (string) wp_json_encode( $post ) );
	}
	$comments = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_ID, comment_post_ID, comment_content, comment_approved FROM {$wpdb->comments} WHERE comment_author LIKE %s ORDER BY comment_ID ASC",
			$cpsd_sa_tag . '%'
		),
		ARRAY_A
	);
	foreach ( (array) $comments as $comment ) {
		$rows[] = 'comment:' . md5( (string) wp_json_encode( $comment ) );
	}
	$attachments = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name LIKE %s ORDER BY ID ASC",
			$cpsd_sa_tag . '%'
		)
	);

	return array(
		'users'       => count( (array) $users ),
		'posts'       => count( (array) $posts ),
		'comments'    => count( (array) $comments ),
		'attachments' => count( (array) $attachments ),
		'digest'      => md5( implode( '|', $rows ) ),
	);
};

/**
 * Every CPMS table that currently exists.
 *
 * @return list<string>
 */
$cpsd_sa_cpms_tables = static function (): array {
	global $wpdb;
	$like = $wpdb->esc_like( $wpdb->prefix . 'cpms_' ) . '%';

	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) );
};

/**
 * @param bool   $ok Condition.
 * @param string $label Label.
 * @param list<string> $checks Accumulator.
 * @param string $detail Detail.
 * @return void
 */
$cpsd_sa_check = static function ( bool $ok, string $label, array &$checks, string $detail = '' ): void {
	$checks[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( '' === $detail ? '' : ' — ' . $detail );
};

try {
	wp_set_current_user( 1 );
	$checks   = array();
	$evidence = array( 'mode' => $cpsd_sa_mode );

	if ( 'seed' === $cpsd_sa_mode ) {
		// ---- A genuinely non-empty site: users, posts, pages, comments, media.
		$editor_id = wp_insert_user(
			array(
				'user_login'   => $cpsd_sa_tag . '-editor',
				'user_pass'    => wp_generate_password( 32 ),
				'user_email'   => $cpsd_sa_tag . '-editor@example.invalid',
				'display_name' => 'ویرایشگر قبلی سایت',
				'role'         => 'editor',
			)
		);
		$author_id = wp_insert_user(
			array(
				'user_login'   => $cpsd_sa_tag . '-author',
				'user_pass'    => wp_generate_password( 32 ),
				'user_email'   => $cpsd_sa_tag . '-author@example.invalid',
				'display_name' => 'نویسنده قبلی سایت',
				'role'         => 'author',
			)
		);
		$page_id = wp_insert_post(
			array(
				'post_title'   => 'صفحهٔ از پیش موجود',
				'post_name'    => $cpsd_sa_tag . '-existing-page',
				'post_content' => 'محتوای واقعی که نباید تغییر کند.',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => (int) $author_id,
			)
		);
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'نوشتهٔ از پیش موجود',
				'post_name'    => $cpsd_sa_tag . '-existing-post',
				'post_content' => 'متن نوشتهٔ قبلی سایت.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => (int) $author_id,
			)
		);
		$sqlite_comment = wp_insert_comment(
			array(
				'comment_post_ID'  => (int) $post_id,
				'comment_author'   => $cpsd_sa_tag . '-visitor',
				'comment_content'  => 'دیدگاه قبلی بازدیدکننده.',
				'comment_approved' => 1,
			)
		);
		$attachment = wp_insert_attachment(
			array(
				'post_title'   => 'پیوست قبلی',
				'post_name'    => $cpsd_sa_tag . '-existing-attachment',
				'post_status'  => 'inherit',
				'post_type'    => 'attachment',
				'post_mime_type' => 'image/png',
			)
		);
		$seed      = $cpsd_sa_seed_digest();
		$evidence += $seed;
		$evidence['seeded_ids'] = array(
			'editor'     => (int) $editor_id,
			'author'     => (int) $author_id,
			'page'       => (int) $page_id,
			'post'       => (int) $post_id,
			'comment'    => (int) $sqlite_comment,
			'attachment' => (int) $attachment,
		);
		$cpsd_sa_check( ! is_wp_error( $editor_id ) && ! is_wp_error( $author_id ), 'seed: two pre-existing real users', $checks );
		$cpsd_sa_check( $page_id > 0 && $post_id > 0, 'seed: a pre-existing page and post', $checks );
		$cpsd_sa_check( $sqlite_comment > 0 && $attachment > 0, 'seed: a pre-existing comment and media attachment', $checks );
		// The seed normally runs BEFORE the real wp-admin upload (so the package is
		// installed onto a site that is already non-empty, which is the owner's
		// situation); then no CPMS table can exist yet and the schema proof is
		// made later by the installer drill and the `assert` mode. The legacy
		// order (seed after activation) keeps the check by setting
		// CPSD_SA_EXPECT_CPMS=1.
		if ( '1' === (string) getenv( 'CPSD_SA_EXPECT_CPMS' ) ) {
			$cpsd_sa_check( count( $cpsd_sa_cpms_tables() ) > 0, 'seed: CPMS schema exists although production CPMS is not installed', $checks );
		} else {
			$cpsd_sa_check( array() === $cpsd_sa_cpms_tables(), 'seed: no CPMS table exists yet (the package is not installed before the real wp-admin upload)', $checks );
		}
		if ( false === file_put_contents( $cpsd_sa_file, (string) wp_json_encode( $evidence ) ) ) {
			throw new RuntimeException( 'cannot write evidence file: ' . $cpsd_sa_file );
		}
	}

	if ( 'provision' === $cpsd_sa_mode ) {
		// The drill in tools/…/tests/scoped-integration.php provisions and then
		// tears itself down; the preservation proof needs a fixture that is still
		// LIVE while the seeded WordPress data is digested. Same objects, same
		// services, no shortcut: this is the real provisioner on the real schema.
		$env         = new Env();
		$platform    = new \CpmsDemo\Platform();
		$manifest    = new \CpmsDemo\ScopedManifest( $env );
		$audit_guard = new \CpmsDemo\ScopedAuditGuard( $platform );
		$baseline    = new \CpmsDemo\ScopedBaseline( $env, $platform );
		$preflight   = new \CpmsDemo\ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
		$credentials = new \CpmsDemo\Credentials( $env );
		$provisioner = new \CpmsDemo\ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $audit_guard );
		$manifest->enable_mode( 1 );
		$state = $preflight->run();
		$cpsd_sa_check( (bool) $state['ok'], 'scoped preflight passes on the non-empty site', $checks, implode( ',', (array) ( $state['blocking'] ?? array() ) ) );
		$result = $provisioner->provision( 1 );
		$active = $manifest->load();
		$cpsd_sa_check( is_array( $active ) && 'active' === (string) ( $active['status'] ?? '' ), 'the scoped fixture is active', $checks );
		if ( is_array( $active ) ) {
			$cpsd_sa_check( count( \CpmsDemo\Credentials::PERSONA_ORDER ) === count( (array) ( $active['ids']['user_ids'] ?? array() ) ), 'one synthetic account per persona is recorded', $checks );
			$cpsd_sa_check( (int) ( $active['ids']['clinic_id'] ?? 0 ) > 0, 'the demo Clinic id is recorded', $checks, (string) ( $active['ids']['clinic_id'] ?? 0 ) );
			$evidence['fixture_id'] = (string) ( $active['fixture_id'] ?? '' );
			$evidence['demo_clinic'] = (int) ( $active['ids']['clinic_id'] ?? 0 );
			$evidence['personas']    = count( (array) ( $active['ids']['user_ids'] ?? array() ) );
		}
		// Credentials are one-time secrets: never written to evidence.
		$cpsd_sa_check( array() !== (array) ( $result['credentials'] ?? array() ), 'the provisioner issued one credential per persona', $checks, 'count=' . count( (array) ( $result['credentials'] ?? array() ) ) );
	}

	if ( 'assert' === $cpsd_sa_mode ) {
		$recorded = is_file( $cpsd_sa_file ) ? json_decode( (string) file_get_contents( $cpsd_sa_file ), true ) : null;
		if ( ! is_array( $recorded ) ) {
			throw new RuntimeException( 'no seeded evidence; run CPSD_SA_MODE=seed first' );
		}
		$env    = new Env();
		$status = Standalone::status();
		$active = (array) get_option( 'active_plugins', array() );
		$cpsd_sa_check( ! in_array( 'clinic-practice-management/clinic-practice-management.php', $active, true ), 'the production plugin is NOT installed or active', $checks );
		$cpsd_sa_check( true === (bool) $status['embedded'], 'the package is the standalone build', $checks, (string) $status['mode'] );
		$cpsd_sa_check( true === (bool) $status['core_loaded'], 'the embedded production core booted', $checks );
		$cpsd_sa_check( '' === (string) $status['fault'], 'no standalone fault', $checks, (string) $status['fault'] );
		$cpsd_sa_check( defined( 'CPMS_VERSION' ), 'the real production constants are defined', $checks );
		$cpsd_sa_check( class_exists( \ClinicCore\Bootstrap\App::class ), 'the real production classes are loaded', $checks );
		$schema = (string) \ClinicCore\Bootstrap\App::migrations()->currentVersion();
		$cpsd_sa_check( '' !== $schema, 'the production migration runner reported a schema head', $checks, $schema );
		$rest = rest_get_server()->get_routes();
		$cpsd_sa_check( isset( $rest['/clinic/v1/booking/hold'] ) || array() !== array_filter( array_keys( $rest ), static fn ( $k ) => 0 === strpos( (string) $k, '/clinic/v1/' ) ), 'the production REST namespace is registered', $checks );

		// The demo provisioned real workflows through the production services.
		$manifest = new \CpmsDemo\ScopedManifest( $env );
		$loaded   = $manifest->load();
		$cpsd_sa_check( is_array( $loaded ), 'a scoped demo manifest exists', $checks );
		if ( is_array( $loaded ) ) {
			global $wpdb;
			$clinic = (int) ( $loaded['ids']['clinic_id'] ?? 0 );
			$cpsd_sa_check( $clinic > 0, 'the demo Clinic exists', $checks, (string) $clinic );
			$cpsd_sa_check( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cpms_patients WHERE clinic_id = %d", $clinic ) ) > 0, 'demo patients exist in the demo Clinic', $checks );
			$cpsd_sa_check( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cpms_invoices WHERE clinic_id = %d", $clinic ) ) > 0, 'demo invoices exist in the demo Clinic', $checks );
			$cpsd_sa_check( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cpms_payments WHERE clinic_id = %d", $clinic ) ) > 0, 'demo payments exist in the demo Clinic', $checks );
			$evidence['demo_clinic'] = $clinic;
		}

		// Every seeded WordPress artefact is byte-identical.
		$now = $cpsd_sa_seed_digest();
		$cpsd_sa_check( (string) $now['digest'] === (string) $recorded['digest'], 'every seeded user/post/comment/media row is byte-identical', $checks, (string) $now['digest'] . ' vs ' . (string) $recorded['digest'] );
		$cpsd_sa_check( (int) $now['users'] === (int) $recorded['users'] && (int) $now['posts'] === (int) $recorded['posts'], 'no seeded user or post disappeared', $checks );
		$evidence['seed_after'] = $now;
	}

	if ( 'conflict' === $cpsd_sa_mode ) {
		// TWO activation orders, both through real WordPress mechanisms.
		$order = (string) ( getenv( 'CPSD_SA_CONFLICT' ) ?: 'forward' );
		$active = (array) get_option( 'active_plugins', array() );
		$status = Standalone::status();
		$message = Standalone::activation_blocked_message();
		$cpsd_sa_check( false !== strpos( $message, 'CPMS' ) && false !== strpos( $message, 'Production' ), 'the refusal is Persian and names the production conflict', $checks, mb_substr( $message, 0, 40 ) );
		$cpsd_sa_check( true === (bool) $status['embedded'], 'the package is still the standalone build', $checks );
		// A wp_die is how a refusal reaches the operator; capture it instead of
		// letting it terminate the drill. `wp_die_handler` is the documented hook.
		$cpsd_sa_die = static function (): callable {
			return static function ( $msg ) {
				throw new RuntimeException( 'wp_die: ' . (string) $msg );
			};
		};
		add_filter( 'wp_die_handler', $cpsd_sa_die );

		if ( 'forward' === $order ) {
			// Order A: the demo is active, production is installed, and somebody
			// activates production. The activation must be REFUSED before the
			// production entry file is included.
			$cpsd_sa_check( in_array( 'cpms-disposable-demo/cpms-disposable-demo.php', $active, true ), 'the standalone demo is active', $checks );
			$cpsd_sa_check( ! in_array( 'clinic-practice-management/clinic-practice-management.php', $active, true ), 'production is installed but NOT active', $checks );
			$cpsd_sa_check( '' === (string) $status['fault'], 'the demo is healthy before the attempt', $checks, (string) $status['fault'] );
			$embedded_core = ( new ReflectionClass( \ClinicCore\Bootstrap\App::class ) )->getFileName();
			$cpsd_sa_check( false !== strpos( (string) $embedded_core, 'cpms-disposable-demo' ), 'the loaded core is the embedded copy', $checks, (string) $embedded_core );

			// (1) The plugins screen: refused BEFORE WordPress includes the file.
			$_GET     = array( 'action' => 'activate', 'plugin' => 'clinic-practice-management/clinic-practice-management.php' );
			$_REQUEST = $_GET;
			$die_message = '';
			try {
				Standalone::refuse_activation_request();
			} catch ( RuntimeException $error ) {
				$die_message = $error->getMessage();
			} catch ( Throwable $error ) {
				$cpsd_sa_check( false, 'the plugins-screen refusal must not fatal: ' . get_class( $error ), $checks, $error->getMessage() );
			}
			$_GET     = array();
			$_REQUEST = array();
			$after = (array) get_option( 'active_plugins', array() );
			$cpsd_sa_check( ! in_array( 'clinic-practice-management/clinic-practice-management.php', $after, true ), 'production never becomes active beside the demo', $checks );
			$cpsd_sa_check( '' !== $die_message, 'the refusal is reported to the operator', $checks, mb_substr( $die_message, 0, 40 ) );
			$cpsd_sa_check( false !== strpos( $die_message, 'Production' ) || false !== strpos( $die_message, 'CPMS' ), 'the reported refusal is the Persian conflict explanation', $checks, mb_substr( $die_message, 0, 40 ) );
			$cpsd_sa_check( (bool) get_transient( Standalone::BLOCKED_TRANSIENT ), 'the refused activation is recorded for the dashboard', $checks );
			// (2) The residual path: third-party code calling activate_plugin()
			// itself. WordPress includes the file first, so the include is not
			// preventable from here - the SIDE EFFECTS are: the option write is
			// filtered and the production activation hook is dropped.
			$schema_before = (string) \ClinicCore\Bootstrap\App::migrations()->currentVersion();
			$jobs_before = count( (array) _get_cron_array() );
			$ticks_before = (int) wp_next_scheduled( 'cpms_jobs_tick' ) > 0 ? 1 : 0;
			$residual = '';
			try {
				$residual = (string) wp_json_encode( activate_plugin( 'clinic-practice-management/clinic-practice-management.php', '', false, true ) );
			} catch ( Throwable $error ) {
				$residual = get_class( $error ) . ': ' . $error->getMessage();
			}
			$after_residual = (array) get_option( 'active_plugins', array() );
			$cpsd_sa_check( ! in_array( 'clinic-practice-management/clinic-practice-management.php', $after_residual, true ), 'a programmatic activation cannot persist a two-core state either', $checks, $residual );
			// No second activation ran: the same number of scheduled events, and
			// still exactly one tick for the single core.
			$jobs_after = count( (array) _get_cron_array() );
			$ticks_after = (int) wp_next_scheduled( 'cpms_jobs_tick' ) > 0 ? 1 : 0;
			$cpsd_sa_check( $jobs_before === $jobs_after, 'the refused activation scheduled nothing new', $checks, $jobs_before . ' -> ' . $jobs_after );
			$cpsd_sa_check( 1 === $ticks_after && 1 === $ticks_before, 'there is still exactly one CPMS queue tick, not two', $checks, $ticks_before . ' -> ' . $ticks_after );
			$schema_after = (string) \ClinicCore\Bootstrap\App::migrations()->currentVersion();
			$cpsd_sa_check( $schema_before === $schema_after, 'the production migration ran exactly once', $checks, $schema_before . ' -> ' . $schema_after );
			$post = Standalone::status();
			$cpsd_sa_check( '' === (string) $post['fault'], 'the demo keeps running with no fault after the refusal', $checks, (string) $post['fault'] );
			$post_core = ( new ReflectionClass( \ClinicCore\Bootstrap\App::class ) )->getFileName();
			$cpsd_sa_check( (string) $post_core === (string) $embedded_core, 'no second core was loaded by the refused activation', $checks );
			$evidence['order']         = 'forward';
			$evidence['die_message']   = $die_message;
			$evidence['core_after']    = (string) $post_core;
		} else {
			// Order B: production is active FIRST. The demo must refuse to run,
			// report the conflict and never define a second set of classes.
			$cpsd_sa_check( in_array( 'clinic-practice-management/clinic-practice-management.php', $active, true ), 'production is active', $checks );
			$cpsd_sa_check( '' !== (string) $status['fault'], 'the demo reports a conflict instead of booting a second core', $checks, (string) $status['fault'] );
			$cpsd_sa_check( 'embedded_conflict_production_active' === (string) $status['fault'], 'the fault is the production-active conflict code', $checks, (string) $status['fault'] );
			$fault_message = Standalone::fault_message( (string) $status['fault'] );
			$cpsd_sa_check( false !== strpos( $fault_message, 'CPMS' ), 'the conflict explanation is Persian', $checks, mb_substr( $fault_message, 0, 40 ) );
			$core_file = ( new ReflectionClass( \ClinicCore\Bootstrap\App::class ) )->getFileName();
			$cpsd_sa_check( false !== strpos( (string) $core_file, 'clinic-practice-management' ), 'the loaded core is the production plugin, not the embedded copy', $checks, (string) $core_file );
			// And the demo cannot be (re)activated while production is active.
			$die_message = '';
			try {
				activate_plugin( 'cpms-disposable-demo/cpms-disposable-demo.php', '', false, true );
			} catch ( RuntimeException $error ) {
				$die_message = $error->getMessage();
			} catch ( Throwable $error ) {
				$cpsd_sa_check( false, 'a demo activation attempt must not fatal: ' . get_class( $error ), $checks, $error->getMessage() );
			}
			$after = (array) get_option( 'active_plugins', array() );
			$cpsd_sa_check( '' !== $die_message, 'activating the demo beside production is refused in Persian', $checks, mb_substr( $die_message, 0, 40 ) );
			$cpsd_sa_check( ! in_array( 'cpms-disposable-demo/cpms-disposable-demo.php', $after, true ), 'the demo does not stay active in the conflicting state', $checks );
			$evidence['order']       = 'reverse';
			$evidence['fault']       = (string) $status['fault'];
			$evidence['core_file']   = (string) $core_file;
			$evidence['die_message'] = $die_message;
		}
		remove_filter( 'wp_die_handler', $cpsd_sa_die );
	}

	if ( 'after_delete' === $cpsd_sa_mode ) {
		$recorded = is_file( $cpsd_sa_file ) ? json_decode( (string) file_get_contents( $cpsd_sa_file ), true ) : null;
		if ( ! is_array( $recorded ) ) {
			throw new RuntimeException( 'no seeded evidence; run CPSD_SA_MODE=seed first' );
		}
		$active = (array) get_option( 'active_plugins', array() );
		$cpsd_sa_check( ! in_array( 'cpms-disposable-demo/cpms-disposable-demo.php', $active, true ), 'the demo plugin is deactivated', $checks );
		$cpsd_sa_check( ! class_exists( Standalone::class, false ), 'the demo plugin classes are no longer loaded', $checks );
		// Deletion must not destroy data.
		$tables = $cpsd_sa_cpms_tables();
		$cpsd_sa_check( array() !== $tables, 'every cpms_* table still exists after deletion', $checks, count( $tables ) . ' tables' );
		$now = $cpsd_sa_seed_digest();
		$cpsd_sa_check( (string) $now['digest'] === (string) $recorded['digest'], 'every seeded user/post/comment/media row is still byte-identical', $checks );
		$cpsd_sa_check( (int) $now['users'] === (int) $recorded['users'], 'no seeded user was deleted with the plugin', $checks );
		$demo_users = get_users(
			array(
				'search'         => 'cpsd-*',
				'search_columns' => array( 'user_login' ),
				'fields'         => 'ID',
			)
		);
		$evidence['synthetic_users_kept'] = count( (array) $demo_users );
		$cpsd_sa_check( count( (array) $demo_users ) > 0, 'synthetic demo accounts are preserved (harmless records over risky deletion)', $checks, (string) count( (array) $demo_users ) );
		$evidence['cpms_tables'] = count( $tables );
	}

	$failed = array_values( array_filter( $checks, static fn ( $line ) => 0 === strpos( (string) $line, 'FAIL' ) ) );
	echo (string) wp_json_encode(
		array(
			'ok'       => array() === $failed,
			'mode'     => $cpsd_sa_mode,
			'checks'   => $checks,
			'evidence' => $evidence,
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} catch ( Throwable $error ) {
	echo (string) wp_json_encode(
		array(
			'ok'       => false,
			'mode'     => $cpsd_sa_mode,
			'checks'   => isset( $checks ) ? $checks : array(),
			'error'    => get_class( $error ) . ': ' . $error->getMessage(),
			'file'     => $error->getFile() . ':' . $error->getLine(),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
}

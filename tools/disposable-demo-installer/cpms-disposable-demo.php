<?php
/**
 * Plugin Name:       CPMS Classic — Disposable Demo Installer
 * Plugin URI:        https://github.com/bia2on2on/doctor
 * Description:       دموی مستقل CPMS Classic V1: هستهٔ واقعی محصول همراه بسته است؛ روی سایت آزمایشی غیرخالی و بدون نیاز به افزونهٔ Production نصب می‌شود. فقط از wp-admin؛ بدون Backdoor/Credential دائمی.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            CPMS
 * License:           proprietary
 * Text Domain:       cpms
 *
 * @package CpmsDisposableDemo
 *
 * TWO BUILDS, ONE SOURCE TREE.
 *  - Companion build (`bin/build-demo-installer.sh --companion`): no embedded
 *    core, production CPMS must be installed and active.
 *  - Standalone build (default): the production source is assembled into
 *    `core/` at build time by the same script, straight from
 *    `clinic-practice-management/`. Nothing is copied into the repository.
 *    `CpmsDemo\Standalone` boots it once, refuses a conflicting installation
 *    instead of fatalling, and keeps the production update channel away from it.
 *
 * SECURITY CONTRACT (see tools/disposable-demo-installer/README.md):
 *  - This plugin never touches the production plugin's source, options schema,
 *    authorization, OTP policy, booking concurrency, payment gates or license
 *    behaviour. In standalone mode the embedded core IS that plugin, at the
 *    same repository revision, booted through its own executable entry file
 *    (only the staged docblock's discoverable Plugin Name key is neutralized).
 *  - It exposes no REST route, no AJAX action and no frontend surface. All work
 *    happens on isolated wp-admin pages (the scoped evaluation console is a
 *    Tools page), behind capability + nonce +
 *    confirmation-phrase + site-identity checks.
 *  - The original mode refuses anything that is not a provably empty,
 *    single-site disposable installation. The separate scoped mode is disabled
 *    by default and requires its own explicit administrator authorization.
 */

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

define( 'CPMS_DEMO_VERSION', '1.0.0' );
define( 'CPMS_DEMO_FILE', __FILE__ );
define( 'CPMS_DEMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPMS_DEMO_URL', plugin_dir_url( __FILE__ ) );
define( 'CPMS_DEMO_CORE_PLUGIN', 'clinic-practice-management/clinic-practice-management.php' );
// Standalone builds carry the assembled production core under `core/`; companion
// builds do not. Both are built from the same source tree by one script.
define( 'CPMS_DEMO_STANDALONE', is_file( __DIR__ . '/core/clinic-practice-management.php' ) );

/**
 * Schema version this installer was written and verified against.
 * Any other version fails closed instead of guessing table/column contracts.
 */
define( 'CPMS_DEMO_SCHEMA_VERSION', '2026_10_27_0030' );

require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-exception.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-standalone.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-env.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-platform.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-manifest.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-fixture-namespace.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-preflight.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-credentials.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-otp.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-provisioner.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-teardown.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-request.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-admin.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-manifest.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-audit-guard.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-baseline.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-preflight.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-request.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-otp.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-ownership.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-guard.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-clinics.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-provisioner.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-teardown.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-admin.php';

/**
 * Boot the embedded production core (standalone builds) before anything else.
 *
 * The core registers its own REST routes, cron schedules, capabilities and
 * admin surfaces through its own entry file — unchanged. Booting it here, once,
 * is the whole difference between the companion and the standalone build.
 */
$cpms_demo_fault = CpmsDemo\Standalone::evaluate();
if ( '' === $cpms_demo_fault ) {
	CpmsDemo\Standalone::boot_core();
	$cpms_demo_fault = CpmsDemo\Standalone::fault();
} else {
	CpmsDemo\Standalone::set_fault( $cpms_demo_fault );
}
unset( $cpms_demo_fault );
CpmsDemo\Standalone::register_update_guards();
// The reverse order of the activation guard below: the demo is already active
// and somebody activates the production plugin. Refused before the production
// entry file is ever included, so no constant or class is defined twice.
CpmsDemo\Standalone::register_activation_barriers();

/**
 * Activation guard: single-site only, and never two CPMS cores.
 *
 * In standalone mode the embedded core is activated here — its own activation
 * hook belongs to its own (unregistered) entry file, so this is the single,
 * explicit call. A conflicting installation fails closed with a Persian
 * explanation instead of a fatal class redeclaration.
 */
function cpms_demo_activate(): void {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( CPMS_DEMO_FILE ) );
		wp_die(
			esc_html__( 'این افزونه فقط روی نصب تک‌سایتی (non-multisite) کار می‌کند و غیرفعال شد.', 'cpms' ),
			esc_html__( 'CPMS Demo: نصب چندسایتی پشتیبانی نمی‌شود', 'cpms' ),
			array( 'back_link' => true )
		);
	}

	$fault = CpmsDemo\Standalone::evaluate();
	if ( '' !== $fault ) {
		deactivate_plugins( plugin_basename( CPMS_DEMO_FILE ) );
		wp_die(
			esc_html( CpmsDemo\Standalone::fault_message( $fault ) ),
			esc_html__( 'CPMS Demo: نصب انجام نشد', 'cpms' ),
			array( 'back_link' => true )
		);
	}

	// Remember, for the next wp-admin request only, that this administrator should
	// be shown the console that fits this site: the strict page on a provably
	// empty install, the scoped console on everything else. Without this the
	// operator lands on the empty-site page, sees a red preflight, and is told to
	// build another WordPress - which is exactly the dead end this avoids.
	CpmsDemo\Admin::mark_landing( get_current_user_id() );

	if ( CpmsDemo\Standalone::is_embedded() ) {
		// Exactly one activation of the embedded core, in the same request.
		if ( ! CpmsDemo\Standalone::boot_core() ) {
			deactivate_plugins( plugin_basename( CPMS_DEMO_FILE ) );
			wp_die(
				esc_html( CpmsDemo\Standalone::fault_message( CpmsDemo\Standalone::fault() ) ),
				esc_html__( 'CPMS Demo: نصب انجام نشد', 'cpms' ),
				array( 'back_link' => true )
			);
		}
		ClinicCore\Bootstrap\App::activate( false );
	}
}
register_activation_hook( __FILE__, 'cpms_demo_activate' );

/**
 * Deactivation never destroys data: it only unschedules the core's queue tick.
 *
 * Dropping `cpms_*` tables, users, invoices or clinical records on deactivation
 * (or on deletion) is explicitly forbidden; cleanup is a separate, authorized,
 * preview-then-confirm operation on the demo page.
 */
function cpms_demo_deactivate(): void {
	if ( CpmsDemo\Standalone::is_embedded() && class_exists( 'ClinicCore\\Bootstrap\\App' ) ) {
		ClinicCore\Bootstrap\App::deactivate();
	}
}
register_deactivation_hook( __FILE__, 'cpms_demo_deactivate' );

add_action( 'admin_notices', array( 'CpmsDemo\\Admin', 'render_activation_conflict_notice' ) );
add_action( 'admin_notices', array( 'CpmsDemo\\Admin', 'render_recorder_error_notice' ) );
add_action( 'admin_menu', array( 'CpmsDemo\\Admin', 'redirect_legacy_tools_url' ), 0 );
add_action( 'admin_menu', array( 'CpmsDemo\\Admin', 'register_menu' ) );
// One-shot landing after activation (see Admin::maybe_land_after_activation()). It
// runs inside wp-admin only, for one GET request, for the administrator who
// activated, and re-decides the target from live state - no authority is stored.
add_action( 'admin_init', array( 'CpmsDemo\\Admin', 'maybe_land_after_activation' ), 1 );
add_action( 'admin_enqueue_scripts', array( 'CpmsDemo\\Admin', 'enqueue_assets' ) );
add_action( 'admin_menu', array( 'CpmsDemo\\ScopedAdmin', 'register_menu' ), 11 );
add_action( 'admin_enqueue_scripts', array( 'CpmsDemo\\ScopedAdmin', 'enqueue_assets' ) );
add_filter(
	'plugin_action_links_' . plugin_basename( CPMS_DEMO_FILE ),
	static function ( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'tools.php?page=' . CpmsDemo\ScopedAdmin::PAGE ) ) . '">' . esc_html__( 'دموی محدودشده', 'cpms' ) . '</a>'
		);
		return $links;
	}
);
// Server-side refusal of portal Clinic creation while the scoped demo mode is
// enabled. Priority 0 puts it ahead of the portal's own `template_redirect`
// handler, so the request never reaches the production dispatcher. It returns
// immediately when scoped mode is off, leaving production behaviour untouched.
add_action( 'template_redirect', array( 'CpmsDemo\\ScopedAdmin', 'guard_portal_clinic_creation' ), 0 );
// The tenant boundary around the demo Clinic/Organization: it records what the
// demo's own synthetic accounts create (so the teardown never has to guess) and
// refuses writes into the demo tenant by real users, on the server. Every hook
// returns immediately unless a scoped fixture is active.
CpmsDemo\ScopedTenantGuard::register();

<?php
/**
 * Plugin Name:       CPMS Classic — Disposable Demo Installer
 * Plugin URI:        https://github.com/bia2on2on/doctor
 * Description:       دو حالت جداگانه دمو: سایت یکبارمصرف خالی و حالت محدودشده صریح برای سایت آزمایشی غیرخالی؛ فقط از wp-admin. افزونه Production تغییر نمیکند و هیچ Demo/Backdoor/Credential دائمی ندارد.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  clinic-practice-management
 * Author:            CPMS
 * License:           proprietary
 * Text Domain:       cpms
 *
 * @package CpmsDisposableDemo
 *
 * SECURITY CONTRACT (see tools/disposable-demo-installer/README.md):
 *  - This plugin is a temporary companion. It never touches the production
 *    plugin's source, options schema, authorization, OTP policy, booking
 *    concurrency, payment gates or license behaviour.
 *  - It exposes no REST route, no AJAX action and no frontend surface. All work
 *    happens on two isolated wp-admin pages under the CPMS unified menu when
 *    available (Tools fallback otherwise), behind capability + nonce +
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

/**
 * Schema version this installer was written and verified against.
 * Any other version fails closed instead of guessing table/column contracts.
 */
define( 'CPMS_DEMO_SCHEMA_VERSION', '2026_10_27_0030' );

require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-exception.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-env.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-platform.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-manifest.php';
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
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-provisioner.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-teardown.php';
require_once CPMS_DEMO_DIR . 'includes/class-cpms-demo-scoped-admin.php';

/**
 * Activation guard: the companion is useless — and unsafe to leave active —
 * without an active CPMS Classic on a single-site installation.
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

	if ( ! class_exists( 'ClinicCore\\Bootstrap\\App' ) ) {
		deactivate_plugins( plugin_basename( CPMS_DEMO_FILE ) );
		wp_die(
			esc_html__( 'ابتدا افزونهٔ Production «Clinic Practice Management (CPMS)» را نصب و فعال کنید.', 'cpms' ),
			esc_html__( 'CPMS Demo: افزونهٔ اصلی فعال نیست', 'cpms' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'cpms_demo_activate' );

add_action( 'admin_menu', array( 'CpmsDemo\\Admin', 'redirect_legacy_tools_url' ), 0 );
add_action( 'admin_menu', array( 'CpmsDemo\\Admin', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'CpmsDemo\\Admin', 'enqueue_assets' ) );
add_action( 'admin_menu', array( 'CpmsDemo\\ScopedAdmin', 'register_menu' ), 11 );
add_action( 'admin_enqueue_scripts', array( 'CpmsDemo\\ScopedAdmin', 'enqueue_assets' ) );

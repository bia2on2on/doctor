<?php
/**
 * Plugin Name:       CPMS Classic — Disposable Demo Installer
 * Plugin URI:        https://github.com/bia2on2on/doctor
 * Description:       سازندهٔ دموی یک‌بارمصرف CPMS Classic برای سایت آزمایشیِ خالی؛ فقط از wp-admin. افزونهٔ Production را تغییر نمی‌دهد و هیچ Demo/Backdoor/Credential دائمی ندارد.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  clinic-practice-management
 * Author:            CPMS
 * License:           proprietary
 * Text Domain:       cpms-disposable-demo
 *
 * @package CpmsDisposableDemo
 *
 * SECURITY CONTRACT (see tools/disposable-demo-installer/README.md):
 *  - This plugin is a temporary companion. It never touches the production
 *    plugin's source, options schema, authorization, OTP policy, booking
 *    concurrency, payment gates or license behaviour.
 *  - It exposes no REST route, no AJAX action and no frontend surface. All work
 *    happens on one wp-admin page under Tools, behind capability + nonce +
 *    confirmation-phrase + site-identity checks.
 *  - It refuses to run on anything that is not provably a single-site,
 *    empty, disposable WordPress installation.
 */

defined( 'ABSPATH' ) || exit;

define( 'CPDD_VERSION', '1.0.0' );
define( 'CPDD_FILE', __FILE__ );
define( 'CPDD_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPDD_URL', plugin_dir_url( __FILE__ ) );
define( 'CPDD_CPMS_PLUGIN', 'clinic-practice-management/clinic-practice-management.php' );

/**
 * Schema version this installer was written and verified against.
 * Any other version fails closed instead of guessing table/column contracts.
 */
define( 'CPDD_SCHEMA_VERSION', '2026_10_18_0029' );

require_once CPDD_DIR . 'includes/class-cpms-demo-exception.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-env.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-platform.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-manifest.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-preflight.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-credentials.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-otp.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-provisioner.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-teardown.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-request.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-admin.php';

/**
 * Activation guard: the companion is useless — and unsafe to leave active —
 * without an active CPMS Classic on a single-site installation.
 */
function cpdd_activate(): void {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( CPDD_FILE ) );
		wp_die(
			esc_html__( 'این افزونه فقط روی نصب تک‌سایتی (non-multisite) کار می‌کند و غیرفعال شد.', 'cpms-disposable-demo' ),
			esc_html__( 'CPMS Demo: نصب چندسایتی پشتیبانی نمی‌شود', 'cpms-disposable-demo' ),
			array( 'back_link' => true )
		);
	}

	if ( ! class_exists( 'ClinicCore\\Bootstrap\\App' ) ) {
		deactivate_plugins( plugin_basename( CPDD_FILE ) );
		wp_die(
			esc_html__( 'ابتدا افزونهٔ Production «Clinic Practice Management (CPMS)» را نصب و فعال کنید.', 'cpms-disposable-demo' ),
			esc_html__( 'CPMS Demo: افزونهٔ اصلی فعال نیست', 'cpms-disposable-demo' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'cpdd_activate' );

add_action( 'admin_menu', array( 'CpmsDemo\\Admin', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'CpmsDemo\\Admin', 'enqueue_assets' ) );

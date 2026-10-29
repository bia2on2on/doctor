<?php
/**
 * Standalone packaging mode: the demo carries the real CPMS Classic V1 core.
 *
 * WHY THIS EXISTS
 * ---------------
 * The demo used to be a companion: it *required* the production plugin to be
 * installed and active (`Requires Plugins:` header plus an activation guard).
 * The Product Owner installs the demo on a test site to evaluate the product
 * and removes it afterwards, and does not want to install production CPMS for
 * that. The standalone build therefore embeds the production source — assembled
 * at build time from `clinic-practice-management/` by
 * `bin/build-demo-installer.sh`, never copied into the repository — under
 * `core/`, and this class is the only place that boots it.
 *
 * FOUR HARD RULES, all of them server-side:
 *
 *  1. ONE CORE, NEVER TWO. The embedded core boots only when no other CPMS is
 *     loaded or active. A site that also has production CPMS installed is a
 *     conflict: booting both would redeclare `ClinicCore\*` classes, register
 *     the cron events and the REST routes twice, and make every ownership
 *     decision ambiguous. The demo refuses in Persian instead.
 *  2. NO FATAL. Every failure path returns a status string. A conflicting
 *     installation must never produce a white screen on the owner's test site.
 *  3. NO DUPLICATE ACTIVATION. `core/` is two levels below `wp-content/plugins`,
 *     so WordPress' plugin scanner never sees it as a plugin. The activation
 *     hook below is the only one that runs, and it calls `App::activate()`
 *     exactly once.
 *  4. THE EMBEDDED BUILD IS NOT UPDATED BY THE PRODUCTION CHANNEL. The core's
 *     `WpUpdateBridge` advertises updates for the slug
 *     `clinic-practice-management`; left alone, WordPress would install a
 *     SECOND copy next to the embedded one. In embedded mode the production
 *     entry is stripped from the update transient, `plugins_api` refuses it and
 *     automatic updates for it are disabled.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these maps.

/**
 * Embedded-core bootstrap, conflict detection and update suppression.
 */
final class Standalone {

	/** Directory (relative to the plugin root) holding the assembled core. */
	public const CORE_DIR = 'core';

	/** The production main file, relative to the core directory. */
	public const CORE_MAIN = 'clinic-practice-management.php';

	/** Plugin basename of a separate production installation. */
	public const PRODUCTION_BASENAME = 'clinic-practice-management/clinic-practice-management.php';

	/** Directory slug of a separate production installation. */
	public const PRODUCTION_SLUG = 'clinic-practice-management';

	/** @var bool True once the embedded core was required in this request. */
	private static bool $booted = false;

	/** @var string Why the demo is not operational ('' = fully operational). */
	private static string $fault = '';

	/**
	 * Absolute path of the embedded core main file.
	 *
	 * @return string
	 */
	public static function core_main(): string {
		return CPMS_DEMO_DIR . self::CORE_DIR . '/' . self::CORE_MAIN;
	}

	/**
	 * Is this a standalone build (does it carry the assembled core)?
	 *
	 * @return bool
	 */
	public static function is_embedded(): bool {
		return is_file( self::core_main() );
	}

	/**
	 * Is a *separate* production installation active?
	 *
	 * Read from the option rather than from `is_plugin_active()`: this runs while
	 * plugins are still loading, when `wp-admin/includes/plugin.php` is not
	 * necessarily loaded yet.
	 *
	 * @return bool
	 */
	public static function production_is_active(): bool {
		$active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
		if ( ! is_array( $active ) ) {
			return false;
		}

		return in_array( self::PRODUCTION_BASENAME, $active, true );
	}

	/**
	 * Another CPMS already owns this request (classes or constants present).
	 *
	 * `ClinicCore\Bootstrap\App` is checked without the autoloader so that a
	 * merely *installable* class cannot be mistaken for a loaded one.
	 *
	 * @return bool
	 */
	public static function another_core_is_loaded(): bool {
		if ( ! defined( 'CPMS_PLUGIN_FILE' ) && ! class_exists( 'ClinicCore\\Bootstrap\\App', false ) ) {
			return false;
		}

		// A core that came from THIS package's `core/` directory is not another
		// core: it is our own, reached again in the same PHP process (WordPress
		// includes the plugin file once for the request and again inside
		// activate_plugin(), and WP-CLI keeps one process across the whole
		// activation). Treating that as a conflict refused every activation.
		$loaded   = self::loaded_core_file();
		$embedded = realpath( self::core_main() );
		if ( '' !== $loaded && false !== $embedded ) {
			return realpath( $loaded ) !== $embedded;
		}

		return true;
	}

	/**
	 * Where the loaded CPMS core classes actually came from.
	 *
	 * @return string Absolute path, or '' when no file can be resolved.
	 */
	private static function loaded_core_file(): string {
		if ( class_exists( 'ClinicCore\\Bootstrap\\App', false ) ) {
			$file = ( new \ReflectionClass( 'ClinicCore\\Bootstrap\\App' ) )->getFileName();
			if ( is_string( $file ) && '' !== $file ) {
				return $file;
			}
		}

		return defined( 'CPMS_PLUGIN_FILE' ) ? (string) CPMS_PLUGIN_FILE : '';
	}

	/**
	 * Decide whether the demo may run in this request.
	 *
	 * @return string '' when operational, otherwise a fault code.
	 */
	public static function evaluate(): string {
		if ( self::$booted ) {
			// This request already booted the embedded core: we are the owner.
			return '';
		}
		if ( ! self::is_embedded() ) {
			// Companion build: the production plugin must provide the core.
			if ( ! class_exists( 'ClinicCore\\Bootstrap\\App' ) ) {
				return 'companion_missing_production';
			}

			return '';
		}
		if ( self::production_is_active() ) {
			return 'embedded_conflict_production_active';
		}
		if ( self::another_core_is_loaded() ) {
			return 'embedded_conflict_core_loaded';
		}
		if ( ! is_readable( self::core_main() ) ) {
			return 'embedded_core_unreadable';
		}

		return '';
	}

	/**
	 * Persian explanation for a fault code.
	 *
	 * @param string $code Fault code.
	 * @return string
	 */
	public static function fault_message( string $code ): string {
		switch ( $code ) {
			case 'embedded_conflict_production_active':
				return 'این بستهٔ «دموی مستقل» هستهٔ CPMS را همراه خود دارد، ولی افزونهٔ Production «Clinic Practice Management» هم روی این سایت فعال است. '
					. 'اجرای هم‌زمان هر دو باعث بارگذاری دوبارهٔ کلاس‌ها، ثبت دوبارهٔ زمان‌بندی و مسیرهای REST و ابهام در مالکیت داده می‌شود؛ برای همین دمو غیرفعال ماند. '
					. 'یکی را انتخاب کنید: افزونهٔ Production را غیرفعال کنید و دموی مستقل را دوباره فعال کنید، یا بستهٔ «همراه» را بدون هستهٔ توکار نصب کنید.';
			case 'embedded_conflict_core_loaded':
				return 'هستهٔ CPMS پیش از این بسته در همین درخواست بارگذاری شده است (احتمالاً افزونهٔ Production یا یک نسخهٔ دیگر از همین دمو). '
					. 'برای جلوگیری از تعریف دوبارهٔ کلاس‌ها و ثبت دوبارهٔ زمان‌بندی، دموی مستقل بارگذاری نشد. فقط یک نسخه از CPMS باید فعال باشد.';
			case 'embedded_core_unreadable':
				return 'هستهٔ CPMS که همراه این بسته ارائه شده است خواندنی نیست. بستهٔ نصب را دوباره بسازید یا دوباره بارگذاری کنید؛ هیچ تغییری روی داده‌های سایت انجام نشد.';
			case 'companion_missing_production':
			default:
				return 'این بستهٔ «همراه» هستهٔ CPMS را همراه ندارد و افزونهٔ Production «Clinic Practice Management (CPMS)» روی سایت فعال نیست. '
					. 'یا بستهٔ «دموی مستقل» را نصب کنید، یا ابتدا افزونهٔ Production را نصب و فعال کنید.';
		}
	}

	/**
	 * Boot the embedded core exactly once, and only when it is safe.
	 *
	 * @return bool
	 */
	public static function boot_core(): bool {
		if ( self::$booted ) {
			return true;
		}
		if ( '' !== self::$fault ) {
			return false;
		}
		if ( ! self::is_embedded() ) {
			// Companion mode: production already booted itself.
			return class_exists( 'ClinicCore\\Bootstrap\\App' );
		}
		if ( ! self::production_is_active() && ! self::another_core_is_loaded() ) {
			// The embedded core may already be loaded without this class having
			// recorded it (re-entry after a second include of the plugin file).
			if ( class_exists( 'ClinicCore\\Bootstrap\\App', false ) ) {
				self::$booted = true;

				return true;
			}
		}
		if ( self::production_is_active() || self::another_core_is_loaded() ) {
			self::$fault = self::production_is_active()
				? 'embedded_conflict_production_active'
				: 'embedded_conflict_core_loaded';

			return false;
		}
		require_once self::core_main();
		self::$booted = class_exists( 'ClinicCore\\Bootstrap\\App', false );
		if ( ! self::$booted ) {
			self::$fault = 'embedded_core_unreadable';
		}

		return self::$booted;
	}

	/**
	 * Record the fault decided by `evaluate()` so later hooks stay silent.
	 *
	 * @param string $fault Fault code.
	 * @return void
	 */
	public static function set_fault( string $fault ): void {
		self::$fault = $fault;
	}

	/**
	 * @return string
	 */
	public static function fault(): string {
		return self::$fault;
	}

	/**
	 * @return bool
	 */
	public static function booted_core(): bool {
		return self::$booted;
	}

	/**
	 * Everything the admin page and the drills need to describe the mode.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$embedded = self::is_embedded();

		return array(
			'mode'                 => $embedded ? 'standalone' : 'companion',
			'embedded'             => $embedded,
			'core_present'         => $embedded,
			'core_loaded'          => self::booted_core() || class_exists( 'ClinicCore\\Bootstrap\\App', false ),
			'production_active'    => self::production_is_active(),
			'fault'                => self::$fault,
			'update_guarded'       => $embedded,
		);
	}

	/**
	 * Persian status card + fault banner for the demo admin pages.
	 *
	 * A fault is shown in words a clinician/administrator can act on. Nothing
	 * here echoes an exception message, a file path or a stack trace.
	 *
	 * @return void
	 */
	public static function render_banner(): void {
		$status = self::status();
		$fault  = (string) $status['fault'];
		if ( '' !== $fault ) {
			echo '<div class="notice notice-error inline" data-cpsd-standalone="fault"><p><strong>'
				. esc_html__( 'دمو فعال نیست.', 'cpms' ) . '</strong> '
				. esc_html( self::fault_message( $fault ) )
				. '</p><p><code dir="ltr">' . esc_html( $fault ) . '</code></p></div>';

			return;
		}
		$label = $status['embedded']
			? __( 'بستهٔ مستقل — هستهٔ واقعی CPMS همراه این افزونه است و بدون افزونهٔ Production کار می‌کند.', 'cpms' )
			: __( 'بستهٔ همراه — هستهٔ CPMS از افزونهٔ Production فعال روی سایت خوانده می‌شود.', 'cpms' );
		echo '<div class="notice notice-info inline" data-cpsd-standalone="' . esc_attr( (string) $status['mode'] ) . '"><p>'
			. esc_html( $label )
			. ' <code dir="ltr">CPMS ' . esc_html( defined( 'CPMS_VERSION' ) ? (string) CPMS_VERSION : '-' )
			. '</code></p></div>';
	}

	/**
	 * Is the CPMS core available to this build (embedded or production)?
	 *
	 * @return bool
	 */
	public static function core_available( ?array $active_plugins = null ): bool {
		if ( self::is_embedded() ) {
			return self::booted_core() || class_exists( 'ClinicCore\\Bootstrap\\App', false );
		}
		if ( null === $active_plugins ) {
			$active_plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
		}

		return is_array( $active_plugins ) && in_array( self::PRODUCTION_BASENAME, $active_plugins, true );
	}

	/**
	 * Keep the production update channel away from the embedded build.
	 *
	 * @return void
	 */
	public static function register_update_guards(): void {
		if ( ! self::is_embedded() ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'strip_production_update' ), PHP_INT_MAX );
		add_filter( 'plugins_api', array( __CLASS__, 'refuse_production_info' ), PHP_INT_MAX, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'disable_production_auto_update' ), PHP_INT_MAX, 2 );
		// An update that arrives OUTSIDE the transient (a manual "upload plugin",
		// a WP-CLI install, an auto-update that already passed the filters) would
		// still unpack `clinic-practice-management/` next to the embedded core.
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'refuse_production_package' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Transient that records a refused production activation.
	 *
	 * WordPress has no single choke point for "activate this plugin", so the
	 * refusal is enforced at the two places the decision is actually made (the
	 * plugins screen and WP-CLI) and again at the point the decision would become
	 * PERMANENT (the `active_plugins` option write). A refusal that only happened
	 * in the option filter would be silent, so it is recorded for the dashboard
	 * notice.
	 */
	public const BLOCKED_TRANSIENT = 'cpms_demo_production_activation_blocked';

	/**
	 * Refuse to run two CPMS cores at once, in either activation order.
	 *
	 * Order A (this one) is: the standalone demo is active, and an administrator
	 * activates the production plugin. WordPress would then include
	 * `clinic-practice-management/clinic-practice-management.php` into a request
	 * where the embedded copy of the SAME file is already loaded: `CPMS_VERSION`,
	 * `CPMS_PLUGIN_FILE` and friends are redefined (PHP warnings), the production
	 * autoloader is registered a second time, `App::boot()` runs twice and
	 * `App::activate()` migrates and schedules a second time. Nothing about that
	 * is recoverable from here, so the activation is refused before the include.
	 *
	 * Order B (production already active, demo activated) is handled by the demo's
	 * own activation hook, which deactivates itself and explains why.
	 *
	 * @return void
	 */
	public static function register_activation_barriers(): void {
		if ( ! self::is_embedded() ) {
			// Companion build: the production plugin IS the core. Nothing to bar.
			return;
		}
		add_action( 'load-plugins.php', array( __CLASS__, 'refuse_activation_request' ), 0 );
		// Defence in depth for every path that reaches WordPress without the
		// plugins screen (another plugin calling `activate_plugin()`, a custom
		// admin page, a script): the option write is where a two-core state
		// becomes permanent, so it is filtered too.
		add_filter( 'pre_update_option_active_plugins', array( __CLASS__, 'keep_single_core' ), PHP_INT_MAX, 2 );
		// The residual path: third-party code calling `activate_plugin()` directly.
		// WordPress includes the plugin file BEFORE it fires `activate_{plugin}`,
		// so the include cannot be stopped from here - but the side effects can:
		// this runs between the include and the activation hook, and drops it.
		add_action( 'activate_plugin', array( __CLASS__, 'discard_production_activation_hooks' ), 0, 2 );
		if ( class_exists( '\\WP_CLI' ) ) {
			// WP-CLI loads this plugin (it is active) before running the command,
			// so `wp plugin activate clinic-practice-management` would hit exactly
			// the same double include. `before_invoke` is the documented hook that
			// runs first in that process.
			\WP_CLI::add_hook( 'before_invoke:plugin activate', array( __CLASS__, 'refuse_cli_activation' ) );
		}
	}

	/**
	 * Refuse a plugins-screen activation of the production build.
	 *
	 * `load-plugins.php` runs inside `wp-admin/admin.php`, i.e. BEFORE
	 * `wp-admin/plugins.php` dispatches `action=activate`, so the production plugin
	 * file is never included. Covers single, bulk and network-admin activation.
	 *
	 * @return void
	 */
	public static function refuse_activation_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing: this only classifies the request, and WordPress verifies the activation nonce itself.
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
		if ( 'activate' !== $action && 'activate-selected' !== $action ) {
			return;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			// WordPress refuses this itself, with its own message.
			return;
		}
		$requested = array();
		if ( 'activate' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is matched against a fixed basename, never used as a path.
			$single = isset( $_GET['plugin'] ) ? (string) wp_unslash( $_GET['plugin'] ) : '';
			if ( '' !== $single ) {
				$requested[] = $single;
			}
		} elseif ( isset( $_REQUEST['checked'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every element is matched against a fixed basename below, never used as a path.
			$requested = (array) wp_unslash( $_REQUEST['checked'] );
		}
		foreach ( $requested as $plugin ) {
			if ( ! is_string( $plugin ) || ! self::is_production_reference( $plugin ) ) {
				continue;
			}
			self::record_blocked_activation();

			wp_die(
				esc_html( self::activation_blocked_message() ),
				esc_html__( 'CPMS Demo: فعال‌سازی افزونهٔ Production مسدود شد', 'cpms' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Refuse `wp plugin activate` for the production build.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,mixed>  $assoc_args Associative arguments.
	 * @return void
	 */
	public static function refuse_cli_activation( $args = array(), $assoc_args = array() ): void {
		$args  = is_array( $args ) ? array_values( array_filter( $args, 'is_string' ) ) : array();
		$all   = is_array( $assoc_args ) && ( ! empty( $assoc_args['all'] ) );
		$found = false;
		foreach ( $args as $plugin ) {
			if ( self::is_production_reference( $plugin ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found && $all && defined( 'WP_PLUGIN_DIR' ) && is_file( (string) WP_PLUGIN_DIR . '/' . self::PRODUCTION_BASENAME ) ) {
			// `--all` would activate it as well.
			$found = true;
		}
		if ( ! $found ) {
			return;
		}
		self::record_blocked_activation();
		\WP_CLI::error( self::activation_blocked_message() );
	}

	/**
	 * Drop a production activation hook that reached `activate_plugin()` anyway.
	 *
	 * Fires after WordPress included the production entry file and before it runs
	 * `activate_{$plugin}`, so `App::activate()` - migrations, cron scheduling,
	 * capability registration - never runs a second time. The embedded core is
	 * already activated; nothing about the site is changed by dropping this.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Network activation.
	 * @return void
	 */
	public static function discard_production_activation_hooks( $plugin, $network_wide = false ): void {
		unset( $network_wide );
		if ( ! is_string( $plugin ) || ! self::is_production_reference( $plugin ) ) {
			return;
		}
		remove_all_actions( 'activate_' . $plugin );
		self::record_blocked_activation();
	}

	/**
	 * Keep `active_plugins` free of the production build while embedded.
	 *
	 * @param mixed $value     New option value.
	 * @param mixed $old_value Previous option value.
	 * @return mixed
	 */
	public static function keep_single_core( $value, $old_value = array() ) {
		unset( $old_value );
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$kept      = array();
		$had_match = false;
		foreach ( $value as $plugin ) {
			if ( is_string( $plugin ) && self::is_production_reference( $plugin ) ) {
				$had_match = true;
				continue;
			}
			$kept[] = $plugin;
		}
		if ( $had_match ) {
			self::record_blocked_activation();
		}

		return $kept;
	}

	/**
	 * Persian explanation for a refused production activation.
	 *
	 * @return string
	 */
	public static function activation_blocked_message(): string {
		return 'این بستهٔ «دموی مستقل» هستهٔ CPMS را همراه خود دارد و همین حالا فعال است. '
			. 'فعال‌سازی افزونهٔ Production «Clinic Practice Management» کنار آن، دو هستهٔ CPMS روی یک نصب می‌سازد '
			. '(تعریف دوبارهٔ کلاس‌ها و ثابت‌ها، ثبت دوبارهٔ زمان‌بندی و مسیرهای REST، و ابهام در مالکیت داده)؛ '
			. 'برای همین فعال‌سازی انجام نشد و هیچ تغییری روی سایت اعمال نشد. '
			. 'اگر می‌خواهید افزونهٔ Production را فعال کنید، نخست این بستهٔ دمو را غیرفعال کنید.';
	}

	/**
	 * Record a refused activation so the dashboard can explain it.
	 *
	 * @return void
	 */
	private static function record_blocked_activation(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::BLOCKED_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Consume the recorded refusal.
	 *
	 * @return bool
	 */
	public static function take_blocked_activation(): bool {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}
		$blocked = (bool) get_transient( self::BLOCKED_TRANSIENT );
		if ( $blocked ) {
			delete_transient( self::BLOCKED_TRANSIENT );
		}

		return $blocked;
	}

	/**
	 * Is this plugin reference the production build of CPMS?
	 *
	 * WordPress addresses a plugin by BASENAME (`clinic-practice-management/…php`)
	 * in some places and by SLUG (`clinic-practice-management`) in others, and the
	 * original guard only recognised the slug - so an update transient keyed by
	 * basename, which is the normal shape of `$transient->response`, was left
	 * untouched. Both, plus the individual item, are recognised here.
	 *
	 * @param string $reference Basename, slug or absolute path.
	 * @return bool
	 */
	public static function is_production_reference( string $reference ): bool {
		$reference = trim( str_replace( '\\', '/', $reference ), '/' );
		if ( '' === $reference ) {
			return false;
		}
		$basename = self::PRODUCTION_BASENAME;
		$slug     = self::PRODUCTION_SLUG;

		return $reference === $basename
			|| $reference === $slug
			|| ( 0 === strpos( $reference, $slug . '/' ) )
			|| ( substr( $reference, -strlen( '/' . $basename ) ) === '/' . $basename );
	}

	/**
	 * Is this update item the production build?
	 *
	 * @param mixed $item Update item.
	 * @return bool
	 */
	private static function is_production_item( $item ): bool {
		if ( ! is_object( $item ) ) {
			return false;
		}
		foreach ( array( 'plugin', 'slug' ) as $key ) {
			if ( isset( $item->{$key} ) && is_string( $item->{$key} ) && self::is_production_reference( $item->{$key} ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove the production plugin from the update list for an embedded build.
	 *
	 * WordPress would install `clinic-practice-management/` next to `core/`,
	 * which is exactly the two-core state this class exists to prevent.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function strip_production_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// EVERY bucket, not a hard-coded list: the production copy must not survive
		// in `response`, `no_update`, `checked`, `upgrade_notice` or any bucket a
		// future WordPress version adds. `translations` is a list of objects and is
		// handled below so that a production language pack is dropped too.
		foreach ( array_keys( get_object_vars( $transient ) ) as $bucket ) {
			if ( 'translations' === $bucket || ! is_array( $transient->{$bucket} ) ) {
				continue;
			}
			foreach ( array_keys( $transient->{$bucket} ) as $key ) {
				if ( self::is_production_reference( (string) $key ) || self::is_production_item( $transient->{$bucket}[ $key ] ) ) {
					unset( $transient->{$bucket}[ $key ] );
				}
			}
		}
		// Translations are updates too: a language pack for a plugin that is not
		// installed is noise, and it is a channel we do not need to keep open.
		if ( isset( $transient->translations ) && is_array( $transient->translations ) ) {
			$kept = array();
			foreach ( $transient->translations as $translation ) {
				$slug = is_object( $translation ) ? (string) ( $translation->slug ?? '' ) : '';
				if ( self::is_production_reference( $slug ) ) {
					continue;
				}
				$kept[] = $translation;
			}
			$transient->translations = $kept;
		}

		return $transient;
	}

	/**
	 * Refuse the production plugin-information request for an embedded build.
	 *
	 * @param mixed  $result Result.
	 * @param string $action API action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public static function refuse_production_info( $result, $action, $args ) {
		if ( 'plugin_information' !== (string) $action || ! is_object( $args ) ) {
			return $result;
		}
		if ( ! self::is_production_reference( (string) ( $args->slug ?? '' ) ) ) {
			return $result;
		}

		return new \WP_Error(
			'CPMS_DEMO_EMBEDDED_CORE',
			__( 'هستهٔ CPMS همراه همین بستهٔ دمو نصب شده است و از کانال به‌روزرسانی افزونهٔ Production به‌روزرسانی نمی‌شود.', 'cpms' )
		);
	}

	/**
	 * Disable automatic updates for the production slug in an embedded build.
	 *
	 * @param mixed $update Whether to update.
	 * @param mixed $item   Plugin item.
	 * @return mixed
	 */
	public static function disable_production_auto_update( $update, $item ) {
		if ( self::is_production_item( $item ) ) {
			return false;
		}

		return $update;
	}

	/**
	 * Refuse to unpack the production plugin next to the embedded core.
	 *
	 * This is the last channel: `upgrader_pre_install` runs for every install and
	 * update source WordPress has (upload, WP-CLI, auto-update, direct download),
	 * and refusing here means the embedded core can never be shadowed by a second
	 * copy - which is the state every other guard in this class exists to prevent.
	 * Unrelated plugins are untouched.
	 *
	 * @param mixed $response Short-circuit value (true or WP_Error).
	 * @param mixed $hook_extra Extra arguments.
	 * @return mixed
	 */
	public static function refuse_production_package( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! is_array( $hook_extra ) ) {
			return $response;
		}
		foreach ( array( 'plugin', 'slug', 'package' ) as $key ) {
			if ( ! isset( $hook_extra[ $key ] ) || ! is_string( $hook_extra[ $key ] ) ) {
				continue;
			}
			$value = (string) $hook_extra[ $key ];
			if ( 'package' === $key ) {
				// A package URL names the ARTEFACT, not the plugin, so the file name
				// decides. The slug followed only by a version suffix and `.zip` is
				// the production build; `clinic-practice-management-pro.zip` and
				// every other unrelated artefact keep installing.
				$path = (string) ( wp_parse_url( $value, PHP_URL_PATH ) ?? $value );
				$name = basename( '' === $path ? $value : $path );
				if ( 1 !== preg_match( '#^' . preg_quote( self::PRODUCTION_SLUG, '#' ) . '(?:[.\-]\d[^/]*)?\.zip$#i', $name ) ) {
					continue;
				}
			} elseif ( ! self::is_production_reference( $value ) ) {
				continue;
			}

			return new \WP_Error(
				'CPMS_DEMO_EMBEDDED_CORE',
				__( 'هستهٔ CPMS همراه همین بستهٔ دمو نصب است؛ نصب یا به‌روزرسانی افزونهٔ Production کنار آن دو هستهٔ هم‌زمان می‌سازد و مسدود شد.', 'cpms' )
			);
		}

		return $response;
	}
}

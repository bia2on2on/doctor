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
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( isset( $transient->{$bucket} ) && is_array( $transient->{$bucket} ) ) {
				unset( $transient->{$bucket}[ self::PRODUCTION_SLUG ] );
			}
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
		if ( (string) ( $args->slug ?? '' ) !== self::PRODUCTION_SLUG ) {
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
		if ( is_object( $item ) && (string) ( $item->plugin ?? '' ) === self::PRODUCTION_BASENAME ) {
			return false;
		}

		return $update;
	}
}

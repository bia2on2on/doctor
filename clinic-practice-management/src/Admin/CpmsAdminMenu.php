<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * منوی واحد «مدیریت مطب» — تنها ورودیِ wp-admin برای همهٔ صفحات CPMS.
 *
 * قواعد (تأییدشدهٔ مالک محصول):
 *  - **دقیقاً یک** منوی Top-Level با عنوان «مدیریت مطب» وجود دارد؛ هیچ صفحهٔ CPMS
 *    دیگر منوی مستقل، یا ورودی در «تنظیمات»/«ابزارها» نمی‌سازد.
 *  - زیرمنو کوتاه و بخش‌محور است. صفحات جزئی‌تر (ثبت‌شده اما پنهان از زیرمنو) از
 *    طریق «ناوبری بخش‌ها» بالای هر صفحهٔ CPMS و همچنین URL مستقیم در دسترس‌اند.
 *
 * قواعد امنیتی (تغییرناپذیر):
 *  - capability منو **هرگز** مجوز نمی‌دهد: هر صفحه capability خودش را دارد و
 *    `render()` هر صفحه مستقلاً بررسی می‌کند. WordPress برای هر آیتم زیرمنو
 *    capability همان آیتم را می‌سنجد، نه والد را.
 *  - ظرف منو با `read` ثبت می‌شود تا صفحات نقش‌محور (Clinic-scoped) زیر آن دیده
 *    شوند، اما برای کاربری که به هیچ صفحهٔ CPMS دسترسی ندارد حذف می‌شود.
 */
final class CpmsAdminMenu {

	/** شناسهٔ منوی واحد (برای سازگاری با کد/مستندات موجود). */
	public const MENU_SLUG = 'cpms';

	/** اسلاگِ صفحهٔ داشبورد = اسلاگِ منوی واحد (landing صفحهٔ Top-Level). */
	public const PAGE_SLUG = CpmsDashboard::PAGE_SLUG;

	/**
	 * Capability **فقط** برای ظرف منو.
	 *
	 * `read` عمداً انتخاب شده تا صفحاتی که با `read` محافظت می‌شوند (سازمان‌ها،
	 * نقش‌های سفارشی، واگذاری مالی) زیر همین منو دیده شوند بدون آنکه یک
	 * capability گسترده به کاربران داده شود. مجوز واقعی هر صفحه مستقل است.
	 */
	public const CONTAINER_CAPABILITY = 'read';

	/**
	 * بخش‌ها: کلید => عنوان فارسی. ترتیب همین آرایه، ترتیب نمایش است.
	 *
	 * @var array<string, string>
	 */
	public const SECTIONS = array(
		'home'       => 'داشبورد',
		'operations' => 'عملیات مطب',
		'patients'   => 'بیماران',
		'finance'    => 'مالی و تسویه',
		'org'        => 'سازمان و درمانگاه',
		'staff'      => 'کاربران و دسترسی',
		'tools'      => 'پیامک و ابزارها',
		'system'     => 'سیستم و راه‌اندازی',
	);

	/**
	 * رَجیستری کاملِ صفحات CPMS — منبعِ واحد برای ناوبریِ بخش‌ها و ترتیب.
	 *
	 * هر ردیف: slug => [برچسب, بخش, capability, نمایش در زیرمنو].
	 * capability این‌جا فقط برای فیلترِ ناوبری است؛ مجوزِ واقعی همان capabilityِ
	 * ثبتِ خودِ صفحه است (پاریتی در `AdminUnifiedMenuTest` سنجیده می‌شود).
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3?: bool}>
	 */
	public const PAGES = array(
		'cpms-dashboard'                      => array( 'داشبورد', 'home', RolesAndCapabilities::CONFIG, true ),
		'cpms-queue'                          => array( 'صف امروز', 'operations', RolesAndCapabilities::QUEUE_READ, true ),
		'cpms-doctor'                         => array( 'امروز پزشک', 'operations', RolesAndCapabilities::QUEUE_READ, true ),
		'cpms-handwriting'                    => array( 'دست‌خط', 'operations', RolesAndCapabilities::NOTE_CREATE, false ),
		'cpms-prescription-print'             => array( 'چاپ نسخه', 'operations', RolesAndCapabilities::RX_READ, false ),
		'cpms-patients'                       => array( 'بیماران', 'patients', RolesAndCapabilities::PATIENT_READ, true ),
		'cpms-finance'                        => array( 'مالی و تسویه', 'finance', RolesAndCapabilities::FINANCE_READ, true ),
		'cpms-finance-delegation'             => array( 'واگذاری مالی حساس', 'finance', 'read', false ),
		'cpms-organizations'                  => array( 'سازمان‌ها', 'org', 'read', true ),
		'cpms-clinic-management'              => array( 'مدیریت درمانگاه‌ها', 'org', 'read', false ),
		'cpms-organization-managers'          => array( 'مدیران سازمان', 'org', 'read', false ),
		'cpms-organization-manager-bootstrap' => array( 'فعال‌سازی مدیر اولیهٔ سازمان', 'org', 'manage_options', false ),
		'cpms-facility-setup'                 => array( 'راه‌اندازی مرکز چنددرمانگاهی', 'org', 'read', false ),
		'cpms-locations'                      => array( 'شعبه‌ها و محل‌ها', 'org', RolesAndCapabilities::CONFIG, true ),
		'cpms-staff'                          => array( 'کاربران و دسترسی‌ها', 'staff', RolesAndCapabilities::CONFIG, true ),
		'cpms-clinicians'                     => array( 'پزشکان و برنامه کاری', 'staff', RolesAndCapabilities::CONFIG, true ),
		'cpms-roles'                          => array( 'مجوزهای پیشرفته', 'staff', 'manage_options', true ),
		'cpms-custom-roles'                   => array( 'نقش‌های سفارشی', 'staff', 'read', false ),
		'cpms-sms'                            => array( 'پیامک و اعلان‌ها', 'tools', RolesAndCapabilities::SMS_CONFIG, true ),
		'cpms-shortcodes'                     => array( 'کدهای کوتاه و نمایش در سایت', 'tools', 'manage_options', false ),
		'cpms-system'                         => array( 'سلامت سیستم', 'system', RolesAndCapabilities::CONFIG, true ),
		'cpms-settings'                       => array( 'فنی و لاگ', 'system', RolesAndCapabilities::CONFIG, true ),
		'cpms-wizard'                         => array( 'راه‌اندازی', 'system', RolesAndCapabilities::CONFIG, true ),
		'cpms-first-practice-setup'           => array( 'راه‌اندازی مطب من', 'system', 'manage_options', false ),
	);

	/**
	 * صفحات legacy که قبلاً در Tools/Settings بودند و به منوی واحد هدایت می‌شوند.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_REHOMED = array(
		'cpms-system',
		'cpms-settings',
		'cpms-clinicians',
		'cpms-roles',
		'cpms-sms',
		'cpms-shortcodes',
		'cpms-organization-manager-bootstrap',
	);

	/**
	 * ردیف‌های ثبت‌شده اما پنهان از زیرمنو (slug => row).
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private static array $hidden_rows = array();

	/**
	 * اسلاگ‌هایی که باید در نمایش پنهان شوند (ثبت‌شده باقی می‌مانند).
	 *
	 * @var array<int, string>
	 */
	private static array $hidden_slugs = array();

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 1 );
		// ترتیب/پنهان‌سازی زیرمنو پس از ثبت همهٔ صفحات (اولویت پیش‌فرض 10).
		add_action( 'admin_menu', array( self::class, 'order_submenu' ), 50 );
		// پنهان‌سازیِ نمایشی باید **بعد** از بررسیِ دسترسیِ هسته انجام شود:
		// `user_can_access_admin_page()` (در انتهای menu.php) والدِ صفحه را با
		// پیمایشِ `$submenu` پیدا می‌کند؛ اگر ردیف پیش از آن حذف شود، WordPress
		// صفحه را «ثبت‌نشده» می‌بیند و URL مستقیم 403 می‌شود (رگرسیونِ واقعیِ
		// observable: `admin.cpms-first-practice-setup.http200` → 403).
		add_action( 'admin_head', array( self::class, 'prune_hidden_submenu_rows' ), 1 );
		add_action( 'admin_menu', array( self::class, 'hide_when_unreachable' ), 999 );
		// Legacy redirect باید در admin_menu اجرا شود نه admin_init: چون wp-admin/menu.php
		// در انتهای خود user_can_access_admin_page() را اجرا می‌کند و اگر صفحه اصلاً در
		// menu ثبت نشده باشد wp_die(403) می‌دهد — این کار قبل از admin_init اتفاق می‌افتد.
		add_action( 'admin_menu', array( self::class, 'redirect_legacy' ), 5 );
		add_action( 'in_admin_header', array( self::class, 'render_section_nav' ), 20 );
		add_filter( 'plugin_action_links_' . self::basename(), array( self::class, 'action_links' ) );
		add_action( 'admin_notices', array( self::class, 'onboarding_notice' ) );
	}

	public static function basename(): string {
		return plugin_basename( defined( 'CPMS_PLUGIN_FILE' ) ? CPMS_PLUGIN_FILE : __FILE__ );
	}

	/**
	 * والد مشترک همهٔ صفحات CPMS در wp-admin (اسلاگِ منوی واحد).
	 */
	public static function parent_slug(): string {
		return CpmsDashboard::PAGE_SLUG;
	}

	/**
	 * ثبت منوی واحد: **یک** Top-Level. زیرمنوها را هر صفحهٔ CPMS خودش زیر
	 * `parent_slug()` ثبت می‌کند (capability و callback نزدِ صاحبِ صفحه می‌ماند).
	 */
	public static function menu(): void {
		add_menu_page(
			'مدیریت مطب',
			'مدیریت مطب',
			self::CONTAINER_CAPABILITY,
			self::parent_slug(),
			array( CpmsDashboard::class, 'render' ),
			'dashicons-building',
			3
		);

		// آیتمِ هم‌اسلاگ: کلیک روی آیتم Top-Level به داشبورد می‌رود و WordPress
		// آیتم تکراری نمی‌سازد.
		add_submenu_page(
			self::parent_slug(),
			'داشبورد',
			'داشبورد',
			RolesAndCapabilities::CONFIG,
			self::PAGE_SLUG,
			array( CpmsDashboard::class, 'render' )
		);
	}

	/**
	 * یک صفحهٔ «جزئی» را ثبت‌شده اما نامرئی در زیرمنو می‌کند.
	 *
	 * چرا: صفحات جزئی باید زیر «مدیریت مطب» ثبت شوند تا WordPress مجوز و
	 * hookname درست را بسازد، اما فهرست زیرمنو نباید بیست‌وچند آیتم تخت شود.
	 * کشفِ آن‌ها از طریق «ناوبری بخش‌ها» و URL مستقیم است.
	 *
	 * نکتهٔ امنیتی: حذف از آرایهٔ زیرمنو فقط نمایش است؛ مجوز صفحه همان capability
	 * ثبت‌شدهٔ خودش است و `$_registered_pages` دست‌نخورده می‌ماند.
	 * نکتهٔ فنی (رگرسیونِ واقعی): حذف **همین‌جا** مجاز نیست — هسته پس از `admin_menu`
	 * والدِ صفحه را از `$submenu` می‌یابد؛ حذفِ زودهنگام یعنی 403 برای URL مستقیم.
	 */
	public static function hide_from_submenu( string $slug ): void {
		if ( in_array( $slug, self::$hidden_slugs, true ) ) {
			return;
		}

		self::$hidden_slugs[] = $slug;

		// ثبتِ ردیف در همین لحظهٔ ثبتِ صفحه (نه در گامِ پنهان‌سازی): فیلترِ
		// هم‌زیستیِ WooCommerce روی `admin_init` اجرا می‌شود، یعنی **پیش از**
		// `admin_head`؛ اگر ردیف را دیر ثبت کنیم، آن فیلتر صفحهٔ ثبت‌شده را
		// «ثبت‌نشده» می‌بیند و مدیرِ Clinic-scoped دوباره ریدایرکت می‌شود.
		$parent = self::parent_slug();
		if ( isset( $GLOBALS['submenu'][ $parent ] ) && is_array( $GLOBALS['submenu'][ $parent ] ) ) {
			foreach ( $GLOBALS['submenu'][ $parent ] as $row ) {
				if ( is_array( $row ) && $slug === ( $row[2] ?? '' ) ) {
					self::$hidden_rows[ $slug ] = $row;
				}
			}
		}

		// اگر register() هنوز اجرا نشده باشد (مثلاً در تست)، همان قابلیتِ خودترمیم.
		if ( false === has_action( 'admin_head', array( self::class, 'prune_hidden_submenu_rows' ) ) ) {
			add_action( 'admin_head', array( self::class, 'prune_hidden_submenu_rows' ), 1 );
		}
	}

	/**
	 * حذفِ **نمایشیِ** ردیف‌های پنهان از زیرمنو — روی `admin_head`، یعنی پس از
	 * بررسیِ دسترسیِ هسته و پیش از رندرِ منو.
	 *
	 * چرا اینجا: `user_can_access_admin_page()` والدِ صفحه را از `$submenu` پیدا
	 * می‌کند و hookname را از همان والد می‌سازد؛ حذفِ زودهنگام یعنی 403 برای URL
	 * مستقیم. پس «پنهان از منو» فقط نمایش است و مجوز/hookname دست‌نخورده می‌ماند.
	 */
	public static function prune_hidden_submenu_rows(): void {
		if ( array() === self::$hidden_slugs ) {
			return;
		}

		foreach ( self::$hidden_slugs as $slug ) {
			$parent = self::parent_slug();
			if ( ! isset( $GLOBALS['submenu'][ $parent ] ) || ! is_array( $GLOBALS['submenu'][ $parent ] ) ) {
				continue;
			}
			foreach ( $GLOBALS['submenu'][ $parent ] as $row ) {
				if ( is_array( $row ) && $slug === ( $row[2] ?? '' ) ) {
					// نگه‌داشتنِ ردیف برای بازرسی/تست: «پنهان از زیرمنو» ≠ «ثبت‌نشده».
					self::$hidden_rows[ $slug ] = $row;
				}
			}
			// API خودِ هسته؛ هیچ نوشتنِ مستقیمی روی `$GLOBALS['submenu']` لازم نیست.
			remove_submenu_page( $parent, $slug );
		}
	}

	/**
	 * ردیف‌های ثبت‌شده‌ای که عمداً از زیرمنو پنهان شده‌اند (slug => row).
	 *
	 * فقط برای بازرسیِ تست/ابزار؛ هیچ مسیر تصمیم‌گیری محصولی به آن وابسته نیست.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function hidden_submenu_rows(): array {
		return self::$hidden_rows;
	}

	/** پاک‌سازیِ ردیف‌های پنهان (تست‌ها بین سناریوها آلوده نشوند). */
	public static function reset_hidden_rows(): void {
		self::$hidden_rows  = array();
		self::$hidden_slugs = array();
	}

	/**
	 * مرتب‌سازی زیرمنو بر اساس بخش‌ها (فقط مرتب‌سازی؛ هیچ ردیفی حذف نمی‌شود).
	 *
	 * آخرین مرحلهٔ admin_menu تا همهٔ ثبت‌کننده‌ها (اولویت پیش‌فرض 10) اجرا شده
	 * باشند. ثبت‌های ناشناخته (افزونهٔ سوم) حفظ می‌شوند و انتهای فهرست می‌آیند.
	 */
	public static function order_submenu(): void {
		$parent = self::parent_slug();
		if ( ! isset( $GLOBALS['submenu'][ $parent ] ) || ! is_array( $GLOBALS['submenu'][ $parent ] ) ) {
			return;
		}

		$rows = array();
		foreach ( $GLOBALS['submenu'][ $parent ] as $row ) {
			if ( is_array( $row ) && isset( $row[2] ) ) {
				$rows[ (string) $row[2] ] = $row;
			}
		}

		// ترتیبِ رَجیستری برای همهٔ صفحاتِ ثبت‌شده — از جمله صفحاتِ «نامرئی».
		// حذفِ آن‌ها اینجا ممنوع است: `user_can_access_admin_page()` پس از این هوک و
		// پیش از `admin_head` اجرا می‌شود و والدِ صفحه را از همین آرایه می‌یابد؛
		// حذفِ زودهنگام = 403 برای URL مستقیم. حذفِ نمایشی در `admin_head` است.
		$ordered = array();
		foreach ( array_keys( self::PAGES ) as $slug ) {
			if ( ! isset( $rows[ $slug ] ) ) {
				continue;
			}
			$ordered[] = $rows[ $slug ];
			unset( $rows[ $slug ] );
		}
		foreach ( $rows as $row ) {
			$ordered[] = $row;
		}

		$GLOBALS['submenu'][ $parent ] = $ordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- ordering a core menu registry has no core API; display order only, no capability change.
	}

	/**
	 * اسلاگ‌های نمایش‌داده‌شده در زیرمنو (به ترتیب بخش‌ها) — برای تست/مستندسازی.
	 *
	 * @return list<string>
	 */
	public static function visible_slugs(): array {
		$slugs = array();
		foreach ( self::SECTIONS as $section => $label ) {
			foreach ( self::PAGES as $slug => $page ) {
				if ( $section === $page[1] && ! empty( $page[3] ) ) {
					$slugs[] = $slug;
				}
			}
		}

		return $slugs;
	}

	/**
	 * ظرف منو را از کاربری که به هیچ صفحهٔ CPMS دسترسی ندارد پنهان می‌کند.
	 *
	 * بدون این، capability ظرف (`read`) منوی «مدیریت مطب» را به هر کاربرِ
	 * واردشده نشان می‌داد. این تابع **فقط نمایش** را تغییر می‌دهد؛ حذف منو هیچ
	 * capability‌ای اضافه/کم نمی‌کند و بررسی مجوز هر صفحه سرجای خود است.
	 */
	public static function hide_when_unreachable(): void {
		if ( self::has_any_accessible_page() ) {
			return;
		}

		remove_menu_page( self::parent_slug() );
	}

	/** آیا کاربر جاری حداقل به یک صفحهٔ CPMS دسترسی دارد؟ */
	public static function has_any_accessible_page(): bool {
		foreach ( self::PAGES as $page ) {
			if ( current_user_can( (string) $page[2] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * صفحات قابل‌دسترسِ کاربر جاری، گروه‌بندی‌شده بر اساس بخش.
	 *
	 * @return array<string, array<string, string>> section => slug => label
	 */
	public static function accessible_sections(): array {
		$groups = array();
		foreach ( self::SECTIONS as $section => $section_label ) {
			foreach ( self::PAGES as $slug => $page ) {
				if ( $section !== $page[1] || ! current_user_can( (string) $page[2] ) ) {
					continue;
				}
				$groups[ $section ][ $slug ] = (string) $page[0];
			}
		}

		return $groups;
	}

	/**
	 * ناوبری بخش‌ها — بالای هر صفحهٔ CPMS رندر می‌شود.
	 *
	 * چرا اینجا و نه در هر صفحه: صفحات CPMS بیست‌وچهارتاست و تکرار یک فراخوانی در
	 * همهٔ آن‌ها ناسازگاری می‌سازد؛ یک هوک، یک رفتار.
	 */
	public static function render_section_nav(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط تشخیص صفحهٔ نمایشی
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		// معیار «صفحهٔ CPMS» رَجیستریِ همین کلاس است، نه allowlistِ assets:
		// دو صفحهٔ مدیریت سازمان عمداً از آن allowlist بیرون‌اند (قرارداد
		// IranGeographyAddressFormsTest) اما باید ناوبری بخش‌ها را هم داشته باشند.
		if ( '' === $current || ! isset( self::PAGES[ $current ] ) ) {
			return;
		}

		$groups = self::accessible_sections();
		if ( array() === $groups ) {
			return;
		}

		echo '<nav class="cpms-sectionnav" dir="rtl" aria-label="' . esc_attr( 'ناوبری بخش‌های مدیریت مطب' ) . '">';
		foreach ( $groups as $section => $pages ) {
			echo '<div class="cpms-sectionnav__group">';
			echo '<span class="cpms-sectionnav__title">' . esc_html( (string) ( self::SECTIONS[ $section ] ?? $section ) ) . '</span>';
			echo '<span class="cpms-sectionnav__links">';
			foreach ( $pages as $slug => $label ) {
				$is_current = $slug === $current;
				echo '<a class="cpms-sectionnav__link' . ( $is_current ? ' is-current' : '' ) . '"'
					. ( $is_current ? ' aria-current="page"' : '' )
					. ' href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">'
					. esc_html( $label ) . '</a>';
			}
			echo '</span></div>';
		}
		echo '</nav>';
	}

	/**
	 * Redirect قدیمی (backward-compat): اگر کسی به tools.php?page=cpms-* یا
	 * options-general.php?page=cpms-* برود، به صفحهٔ جدیدِ تحت «مدیریت مطب» هدایت می‌شود.
	 */
	public static function redirect_legacy(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط تشخیص مسیر قدیمی
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط تشخیص مسیر قدیمی
		$page    = sanitize_key( wp_unslash( $_GET['page'] ) );
		$allowed = array_merge( self::LEGACY_REHOMED, array( CpmsDashboard::PAGE_SLUG ) );
		if ( ! in_array( $page, $allowed, true ) ) {
			return;
		}
		global $pagenow;
		if ( 'tools.php' === ( $pagenow ?? '' ) || 'options-general.php' === ( $pagenow ?? '' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
			exit;
		}
	}

	/**
	 * True when the current user is a WordPress administrator (`manage_options`)
	 * and the installation has a single eligible, unclaimed initial Clinic
	 * awaiting the one-shot first-practice bootstrap.
	 */
	public static function is_first_practice_setup_pending(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		try {
			return null !== App::first_practice_setup_service()->first_setup_candidate();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * setup.completed for admin chrome. null means Clinic scope is missing or
	 * ambiguous — callers must not guess a Clinic and must not throw.
	 */
	public static function setup_completed_flag(): ?bool {
		try {
			return (bool) App::settings()->get( 'setup.completed', false );
		} catch ( ScopeRequiredException ) {
			return null;
		}
	}

	public static function ambiguous_clinic_guidance(): string {
		return 'امکان تعیین مطب فعال وجود ندارد. نصب چندمطب است و انتخاب صریح مطب لازم است. راه‌اندازی اولیه در این حالت در دسترس نیست.';
	}

	/**
	 * Canonical onboarding/setup URL: routes eligible first-run WordPress
	 * administrators to the dedicated first-practice bootstrap before the
	 * established Clinic-management wizard.
	 */
	public static function setup_url(): string {
		// Single visible onboarding entry point: the CPMS wizard. The
		// wizard embeds the first-practice bootstrap/recovery panel (via
		// FirstPracticeSetupService) when the installation is eligible.
		$slug = CpmsSetupWizard::PAGE_SLUG;

		return admin_url( 'admin.php?page=' . $slug );
	}

	/**
	 * Plugin action links — فقط لینک‌های مجاز (capability-driven).
	 *
	 * @param array<int, string> $links
	 *
	 * @return array<int, string>
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( RolesAndCapabilities::CONFIG ) ) {
			$setup_flag    = self::setup_completed_flag();
			$setup_pending = false === $setup_flag;
			$primary_label = $setup_pending ? 'راه‌اندازی' : 'داشبورد CPMS';
			$primary_url   = $setup_pending
				? self::setup_url()
				: admin_url( 'admin.php?page=' . CpmsDashboard::PAGE_SLUG );
			array_unshift( $links, '<a href="' . esc_url( $primary_url ) . '">' . esc_html( $primary_label ) . '</a>' );
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=cpms-settings' ) ) . '">تنظیمات</a>';
		}

		return $links;
	}

	/**
	 * Notification نصب — برای مدیرِ مجاز، فقط تا وقتی setup کامل نشده (بدون spam).
	 */
	public static function onboarding_notice(): void {
		if ( ! current_user_can( RolesAndCapabilities::CONFIG ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && 'plugins' === $screen->id ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-slug check, not a trusted input value.
		if ( isset( $_GET['page'] ) && sanitize_key( wp_unslash( $_GET['page'] ) ) === CpmsSetupWizard::PAGE_SLUG ) {
			// Never render (and never consume the one-shot flag) ON the setup
			// wizard itself: a "install complete, start setup" banner is
			// redundant while already standing on that exact page, and the
			// automatic post-activation redirect (CpmsActivationRedirect)
			// lands the admin here directly -- without this guard, that very
			// landing would silently burn the one-shot `cpms_onboarding_seen`
			// transient and the notice would never show again on the
			// dashboard the admin actually sees afterward.
			return;
		}
		$setup_flag = self::setup_completed_flag();
		if ( true === $setup_flag ) {
			return;
		}
		if ( get_transient( 'cpms_onboarding_seen' ) ) {
			return;
		}
		set_transient( 'cpms_onboarding_seen', 1, DAY_IN_SECONDS );
		if ( null === $setup_flag ) {
			printf(
				'<div class="notice notice-warning is-dismissible" data-cpms-scope-required="1"><p>%s</p></div>',
				esc_html( self::ambiguous_clinic_guidance() )
			);
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>CPMS نصب شد</strong> — برای شروع، راه‌اندازی اولیه را تکمیل کنید. <a class="button button-primary" href="%s">شروع راه‌اندازی</a> <a class="button" href="%s">سلامت سیستم</a> <a class="button" href="%s">راهنما</a></p></div>',
			esc_url( self::setup_url() ),
			esc_url( admin_url( 'admin.php?page=cpms-system' ) ),
			esc_url( admin_url( 'admin.php?page=cpms-system' ) )
		);
	}
}

<?php
/**
 * Separate wp-admin page for the scoped demo on existing non-empty test sites.
 *
 * Strict disposable mode remains on its original page and is untouched. This
 * page is disabled by default, requires its own administrator confirmation,
 * and never exposes a REST, AJAX, CLI or frontend surface.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Scoped-mode admin UI and dispatch.
 */
class ScopedAdmin {

	public const PAGE   = 'cpms-scoped-demo';
	public const CAP    = 'manage_options';
	public const ACTION = 'cpsd_action';

	/** @var string */
	private static string $hook = '';

	/** @var array{type:string,text:string}|null */
	private static $notice = null;

	/** @var array<string,string>|null */
	private static $credentials = null;

	/** @var array<string,mixed>|null */
	private static $otp = null;

	/** @var array<string,mixed>|null */
	private static $diagnostic = null;

	/** @var array<string,mixed>|null */
	private static $teardown = null;

	/** @var array{manifest:array<string,mixed>,credentials:array<string,string>}|null */
	private static $provisioned = null;

	public static function reset_state(): void {
		self::$notice      = null;
		self::$credentials = null;
		self::$otp         = null;
		self::$diagnostic  = null;
		self::$teardown    = null;
		self::$provisioned = null;
	}

	/**
	 * Register a second page under the existing CPMS unified menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$parent = self::cpms_parent_slug();
		if ( null !== $parent ) {
			$hook = add_submenu_page(
				$parent,
				'دموی محدودشده CPMS — سایت غیرخالی',
				'دموی محدودشده CPMS',
				self::CAP,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
		} else {
			$hook = add_management_page(
				'دموی محدودشده CPMS — سایت غیرخالی',
				'دموی محدودشده CPMS',
				self::CAP,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
		}
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
			add_action( 'load-' . $hook, array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * @return string|null
	 */
	private static function cpms_parent_slug(): ?string {
		$class = '\\ClinicCore\\Admin\\CpmsAdminMenu';
		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'parent_slug' ) ) ) {
			return null;
		}
		$parent = (string) call_user_func( array( $class, 'parent_slug' ) );

		return '' !== $parent ? $parent : null;
	}

	/**
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' !== self::$hook && $hook_suffix !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'cpsd-admin', CPMS_DEMO_URL . 'assets/admin.css', array(), CPMS_DEMO_VERSION );
	}

	/**
	 * Handle one POST action.
	 *
	 * @return void
	 */
	public static function dispatch(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		$action = isset( $_POST[ self::ACTION ] ) ? sanitize_key( (string) wp_unslash( $_POST[ self::ACTION ] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		self::harden_headers();
		$env          = new Env();
		$platform     = new Platform();
		$manifest     = new ScopedManifest( $env );
		$audit_guard  = new ScopedAuditGuard( $platform );
		$baseline     = new ScopedBaseline( $env, $platform );
		$preflight    = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
		$request      = new ScopedRequest( $env, $manifest );
		$credentials  = new Credentials( $env );
		$provisioner  = new ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $audit_guard );
		$teardown     = new ScopedTeardown( $env, $platform, $manifest, $audit_guard, $baseline );
		$otp_helper   = new ScopedOtp( $env, $platform, $manifest );
		$post         = (array) wp_unslash( $_POST );
		try {
			$request->authorize( $action, $post );
			$admin_id = $env->current_user_id();
			switch ( $action ) {
				case ScopedRequest::ACTION_ENABLE:
					$manifest->enable_mode( $admin_id );
					self::$notice = array( 'type' => 'success', 'text' => 'حالت محدودشده فعال شد. ساخت دمو همچنان نیاز به تأیید جداگانه دارد.' );
					break;
				case ScopedRequest::ACTION_PROVISION:
					self::$provisioned = $provisioner->provision( $admin_id );
					self::$credentials = self::$provisioned['credentials'];
					self::$notice      = array( 'type' => 'success', 'text' => 'دمو ساخته شد. رمزها فقط در همین پاسخ نمایش داده میشوند.' );
					break;
				case ScopedRequest::ACTION_OTP:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد.' );
					}
					self::$otp    = $otp_helper->issue( $loaded );
					self::$notice = array( 'type' => 'success', 'text' => 'کد OTP نمایشی فقط در همین پاسخ نشان داده میشود.' );
					break;
				case ScopedRequest::ACTION_DIAGNOSE:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی ثبتشدهای وجود ندارد.' );
					}
					self::$diagnostic = $teardown->preview( $loaded );
					self::$notice      = array( 'type' => 'info', 'text' => 'پیشنمایش فقطخواندنی اجرا شد؛ هیچ ردیفی حذف نشد.' );
					break;
				case ScopedRequest::ACTION_TEARDOWN:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی ثبتشدهای وجود ندارد.' );
					}
					self::$teardown = $teardown->execute( $loaded, $admin_id );
					self::$notice   = array( 'type' => 'success', 'text' => 'پاکسازی انجام و تأیید شد؛ دادههای قبلی و زنجیره حسابرسی حفظ شدند.' );
					break;
				default:
					throw new Exception( 'ACTION_UNKNOWN', 'عملیات ناشناخته است.' );
			}
		} catch ( Exception $e ) {
			self::$notice = array( 'type' => 'error', 'text' => $e->getMessage() . ' (کد: ' . $e->demo_code() . ')' );
		} catch ( \Throwable $e ) {
			self::$notice = array( 'type' => 'error', 'text' => 'خطای غیرمنتظره: ' . $e->getMessage() );
		}
	}

	private static function harden_headers(): void {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی لازم را ندارید.', 'cpms' ) );
		}
		$env         = new Env();
		$platform    = new Platform();
		$manifest    = new ScopedManifest( $env );
		$audit_guard = new ScopedAuditGuard( $platform );
		$baseline    = new ScopedBaseline( $env, $platform );
		$preflight   = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
		$request     = new ScopedRequest( $env, $manifest );
		$loaded      = null;
		$manifest_error = '';
		try {
			$loaded = $manifest->load();
		} catch ( Exception $e ) {
			$manifest_error = $e->getMessage() . ' (' . $e->demo_code() . ')';
		}
		$state = $preflight->run();
		$tombstone = $manifest->tombstone();
		echo '<div class="wrap cpdd" dir="rtl" data-cpsd-page="1">';
		echo '<h1>دموی محدودشده CPMS — سایت آزمایشی غیرخالی</h1>';
		self::render_notice();
		self::render_identity( $env, $manifest, $manifest->mode_enabled(), $loaded, $manifest_error );
		self::render_preflight( $state );
		self::render_credentials( $loaded );
		self::render_otp();
		if ( '' !== $manifest_error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $manifest_error ) . '</p></div>';
		} elseif ( ! $manifest->mode_enabled() ) {
			self::render_form( $request, ScopedRequest::ACTION_ENABLE, 'فعالسازی حالت محدودشده', 'I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE', 'این دکمه فقط یک نشانگر HMACشده مینویسد و هیچ کاربر یا رکورد CPMS نمیسازد.' );
		} elseif ( null === $loaded ) {
			self::render_form( $request, ScopedRequest::ACTION_PROVISION, 'ساخت دموی محدودشده', 'CREATE_SCOPED_DEMO_ON_TEST_SITE', 'سازمان/درمانگاه/شعبهها و شش حساب کاملاً ساختگی ایجاد میشوند؛ هیچ رکورد قبلی تغییر نمیکند.' );
		} else {
			$status = (string) ( $loaded['status'] ?? 'unknown' );
			if ( 'active' === $status ) {
				self::render_links( $loaded );
				self::render_form( $request, ScopedRequest::ACTION_OTP, 'ساخت کد OTP نمایشی', 'SHOW_SCOPED_DEMO_OTP', 'پس از درخواست کد از رابط واقعی بیمار، کد همان چالش زنده را نشان میدهد.' );
			} else {
				echo '<div class="notice notice-warning inline"><p>وضعیت دمو ناتمام است: <code dir="ltr">' . esc_html( $status ) . '</code>. ساخت دمو تازهای مجاز نیست؛ از پیشنمایش و پاکسازی ایمن استفاده کنید.</p></div>';
			}
			self::render_form( $request, ScopedRequest::ACTION_DIAGNOSE, 'پیشنمایش پاکسازی', 'RUN_READ_ONLY_SCOPED_DIAGNOSTIC', 'هیچ ردیفی حذف نمیشود؛ مالکیت دقیق و اقلام حفظشده نشان داده میشود.' );
			self::render_diagnostic();
			self::render_form( $request, ScopedRequest::ACTION_TEARDOWN, 'پاکسازی فقط دادههای دمو', 'REMOVE_ONLY_SCOPED_DEMO_DATA', 'در صورت باقی ماندن هر ردیف بالینی یا مالی دمو، موفقیت اعلام نمیشود.' );
		}
		self::render_teardown_result();
		if ( is_array( $tombstone ) ) {
			self::render_tombstone( $tombstone );
		}
		self::render_instructions();
		self::render_limitations();
		echo '</div>';
	}

	private static function render_notice(): void {
		if ( ! is_array( self::$notice ) ) {
			return;
		}
		$type  = (string) ( self::$notice['type'] ?? 'info' );
		$class = 'error' === $type ? 'notice-error' : ( 'warning' === $type ? 'notice-warning' : ( 'success' === $type ? 'notice-success' : 'notice-info' ) );
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( (string) ( self::$notice['text'] ?? '' ) ) . '</p></div>';
	}

	private static function render_identity( Env $env, ScopedManifest $manifest, bool $mode, ?array $loaded, string $error ): void {
		echo '<div class="cpdd-card"><h2>شناسه سایت و وضعیت حالت</h2><table class="widefat striped cpdd-kv"><tbody>';
		$rows = array(
			'آدرس سایت'      => $env->home_url(),
			'نام دیتابیس'    => $env->db_name(),
			'پیشوند جداول'   => $env->table_prefix(),
			'اثر انگشت سایت' => $manifest->site_fingerprint(),
			'PHP / WordPress' => $env->php_version() . ' / ' . $env->wp_version(),
			'حالت محدودشده'  => $mode ? 'فعال' : 'غیرفعال (پیشفرض)',
		);
		if ( is_array( $loaded ) ) {
			$rows['شناسه دمو'] = (string) ( $loaded['fixture_id'] ?? '' );
			$rows['وضعیت دمو'] = (string) ( $loaded['status'] ?? '' );
		}
		if ( '' !== $error ) {
			$rows['خطای مالکیت'] = $error;
		}
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td dir="ltr">' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_preflight( array $state ): void {
		$ok = ! empty( $state['ok'] );
		echo '<div class="cpdd-card" data-cpsd-preflight="' . ( $ok ? 'pass' : 'fail' ) . '"><h2>پیشبررسی سازگاری و ایمنی</h2>';
		echo '<p>وضعیت کلی: ' . ( $ok ? '<strong class="cpdd-pass">PASS</strong>' : '<strong class="cpdd-fail">FAIL — ساخت دمو مسدود است</strong>' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( (array) ( $state['checks'] ?? array() ) as $check ) {
			$pass = 'pass' === (string) ( $check['status'] ?? '' );
			echo '<tr><td>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</td><td class="' . ( $pass ? 'cpdd-pass' : 'cpdd-fail' ) . '">' . ( $pass ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( (string) ( $check['detail'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_form( ScopedRequest $request, string $action, string $title, string $phrase, string $hint ): void {
		echo '<form method="post" class="cpdd-card" data-cpsd-form="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="cpdd_nonce" value="' . esc_attr( $request->nonce( $action ) ) . '" />';
		echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $hint ) . '</p>';
		echo '<p><label>عبارت تأیید: <code dir="ltr">' . esc_html( $phrase ) . '</code></label></p>';
		echo '<p><input type="text" name="cpdd_confirm" class="regular-text" dir="ltr" autocomplete="off" required /></p>';
		echo '<p><button type="submit" class="button ' . ( ScopedRequest::ACTION_TEARDOWN === $action ? 'button-link-delete' : 'button-primary' ) . '">' . esc_html( $title ) . '</button></p>';
		echo '</form>';
	}

	private static function render_credentials( ?array $loaded ): void {
		if ( ! is_array( self::$credentials ) ) {
			return;
		}
		$fixture = is_array( $loaded ) ? (string) ( $loaded['fixture_id'] ?? '' ) : (string) ( self::$provisioned['manifest']['fixture_id'] ?? '' );
		$prefix  = 'cpsd-';
		echo '<div class="cpdd-card cpdd-secret" data-cpsd-secret="credentials"><h2>رمزهای یکبارمصرف</h2>';
		echo '<p class="cpdd-warn">این رمزها ذخیره نمیشوند. همین حالا در یک مدیر رمز امن کپی کنید.</p>';
		echo '<table class="widefat striped"><thead><tr><th>نقش</th><th>نام کاربری</th><th>رمز</th></tr></thead><tbody>';
		foreach ( Credentials::personas() as $key => $persona ) {
			$login = $prefix . $key . '-' . $fixture;
			echo '<tr data-cpsd-persona="' . esc_attr( $key ) . '"><td>' . esc_html( $persona['label'] ) . '</td><td dir="ltr">' . esc_html( $login ) . '</td><td><textarea readonly rows="1" dir="ltr" class="cpdd-pass-field">' . esc_textarea( (string) ( self::$credentials[ $key ] ?? '' ) ) . '</textarea></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_links( array $manifest ): void {
		$links = (array) ( $manifest['links'] ?? array() );
		echo '<div class="cpdd-card"><h2>پیوندهای درگاهها</h2><ul>';
		foreach ( $links as $key => $url ) {
			echo '<li><a href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener">' . esc_html( (string) $key ) . '</a></li>';
		}
		echo '</ul><p>Shortcode رزرو: <code dir="ltr">' . esc_html( (string) ( $manifest['shortcode'] ?? '' ) ) . '</code></p></div>';
	}

	private static function render_otp(): void {
		if ( ! is_array( self::$otp ) ) {
			return;
		}
		echo '<div class="cpdd-card cpdd-secret" data-cpsd-secret="otp"><h2>کد OTP نمایشی</h2>';
		echo '<p class="cpdd-warn">این کد فقط در همین پاسخ نمایش داده میشود.</p>';
		echo '<p><code dir="ltr" data-cpsd-otp-code="1">' . esc_html( (string) ( self::$otp['code'] ?? '' ) ) . '</code></p>';
		echo '<p>انقضا: <span dir="ltr">' . esc_html( (string) ( self::$otp['expires_at'] ?? '' ) ) . '</span></p></div>';
	}

	private static function render_diagnostic(): void {
		if ( ! is_array( self::$diagnostic ) ) {
			return;
		}
		$ok = ! empty( self::$diagnostic['ok'] );
		echo '<div class="cpdd-card" data-cpsd-diagnostic="' . ( $ok ? 'pass' : 'fail' ) . '"><h2>پیشنمایش مالکیت</h2>';
		if ( array() !== (array) ( self::$diagnostic['problems'] ?? array() ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( implode( ' | ', (array) self::$diagnostic['problems'] ) ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr><th>جدول</th><th>ردیفهای قابل حذف</th></tr></thead><tbody>';
		foreach ( (array) ( self::$diagnostic['plan'] ?? array() ) as $row ) {
			echo '<tr><td>' . esc_html( (string) ( $row['table'] ?? '' ) ) . '</td><td>' . (int) ( $row['rows'] ?? 0 ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_teardown_result(): void {
		if ( ! is_array( self::$teardown ) ) {
			return;
		}
		echo '<div class="cpdd-card" data-cpsd-teardown="clean"><h2>نتیجه پاکسازی</h2>';
		echo '<p>حذف انجام و تأیید شد. ردیفهای حسابرسی عمداً حفظ شدند.</p>';
		echo '<p>تعداد ردیفهای حسابرسی حفظشده: ' . (int) ( self::$teardown['tombstone']['preserved_audit'] ?? 0 ) . '</p></div>';
	}

	private static function render_tombstone( array $tombstone ): void {
		echo '<div class="cpdd-card" data-cpsd-tombstone="clean"><h2>سابقه پاکسازی موفق</h2>';
		echo '<p>شناسه دمو: <code dir="ltr">' . esc_html( (string) ( $tombstone['fixture_id'] ?? '' ) ) . '</code></p>';
		echo '<p>زنجیره حسابرسی و دادههای قبلی بررسی و حفظ شدند.</p></div>';
	}

	private static function render_instructions(): void {
		echo '<div class="cpdd-card"><h2>دستورالعمل فارسی</h2><ol>';
		echo '<li>ابتدا یک پشتیبان کامل و قابل بازیابی از سایت آزمایشی بگیرید.</li>';
		echo '<li>دو ZIP جداگانه CPMS Classic و companion را از wp-admin نصب و فعال کنید.</li>';
		echo '<li>حالت محدودشده را با عبارت <code dir="ltr">I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE</code> فعال کنید.</li>';
		echo '<li>پیشبررسی را سبز کنید و سپس دمو را بسازید؛ رمزهای شش شخصیت را همان لحظه ذخیره کنید.</li>';
		echo '<li>هر شخصیت را در یک پنجره خصوصی جداگانه وارد کرده و جریانهای رزرو، پذیرش، بالینی و مالی را انجام دهید.</li>';
		echo '<li>پیشنمایش پاکسازی را بررسی کنید، سپس با عبارت <code dir="ltr">REMOVE_ONLY_SCOPED_DEMO_DATA</code> فقط دادههای دمو را حذف کنید.</li>';
		echo '<li>نتیجه حفظ baseline و زنجیره حسابرسی را بررسی کنید؛ در صورت هشدار ناقص، دوباره پاکسازی را اجرا کنید.</li>';
		echo '</ol></div>';
	}

	private static function render_limitations(): void {
		echo '<div class="cpdd-card"><h2>محدودیتها</h2><ul>';
		echo '<li>این حالت فقط برای سایت آزمایشی غیرتولیدی و جداگانه است.</li>';
		echo '<li>گزارشهای حسابرسی برای حفظ زنجیره هش جهانی حذف نمیشوند.</li>';
		echo '<li>شمارندههای جهانی محدودیت نرخ و کارهای صف نامرتبط حفظ میشوند.</li>';
		echo '<li>بدون اجرای واقعی MySQL 8، MariaDB 11.4.13 و Chromium، ادعای سازگاری سراسری مطرح نمیشود.</li>';
		echo '<li>هیچ پیامک یا پرداخت آنلاین واقعی انجام نمیشود.</li>';
		echo '</ul></div>';
	}
}

<?php
/**
 * The single wp-admin surface: CPMS → دموی یک‌بارمصرف CPMS when CPMS Classic is active; Tools is the safe fallback.
 *
 * Rendering only. Every decision (capability, nonce, phrase, site identity,
 * preflight, ownership) happens in the classes this page calls, so those rules
 * are covered by the automated tests instead of living in HTML.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

defined( 'ABSPATH' ) || exit;

/**
 * Admin page + form dispatch.
 */
class Admin {

	public const PAGE   = 'cpms-disposable-demo';
	public const CAP    = 'manage_options';
	public const ACTION = 'cpdd_action';

	/**
	 * @var string Hook suffix of the active companion page.
	 */
	private static string $hook = '';

	/**
	 * @var array{type:string,text:string}|null
	 */
	private static $notice = null;

	/**
	 * @var array<string,string>|null
	 */
	private static $credentials = null;

	/**
	 * @var array<string,mixed>|null
	 */
	private static $otp = null;

	/**
	 * @var array<string,mixed>|null
	 */
	private static $diagnostic = null;

	/**
	 * @var array<string,mixed>|null
	 */
	private static $teardown = null;

	/**
	 * @var array{manifest:array<string,mixed>,credentials:array<string,string>}|null
	 */
	private static $provisioned = null;

	/**
	 * Optional test environment override.
	 *
	 * @var Env|null
	 */
	private static ?Env $test_env = null;

	/**
	 * Optional test platform override.
	 *
	 * @var Platform|null
	 */
	private static ?Platform $test_platform = null;

	/**
	 * Reset transient per-request rendering state (used by test harness).
	 *
	 * @return void
	 */
	public static function reset_state(): void {
		self::$notice      = null;
		self::$credentials = null;
		self::$otp         = null;
		self::$diagnostic  = null;
		self::$teardown    = null;
		self::$provisioned = null;
	}

	/**
	 * Configure test service overrides (used by unit test harness).
	 *
	 * @param Env|null      $env      Test environment.
	 * @param Platform|null $platform Test platform.
	 * @return void
	 */
	public static function set_test_services( ?Env $env, ?Platform $platform ): void {
		self::$test_env      = $env;
		self::$test_platform = $platform;
	}

	/**
	 * Return the existing CPMS unified parent slug, or null when that menu is absent.
	 *
	 * @return string|null
	 */
	private static function cpms_parent_slug(): ?string {
		$cpms_menu = '\\ClinicCore\\Admin\\CpmsAdminMenu';
		if ( ! class_exists( $cpms_menu ) || ! is_callable( array( $cpms_menu, 'parent_slug' ) ) ) {
			return null;
		}
		$parent = (string) call_user_func( array( $cpms_menu, 'parent_slug' ) );

		return '' !== $parent ? $parent : null;
	}

	/**
	 * Register the page under CPMS Classic when its unified menu is available;
	 * otherwise retain a standalone Tools fallback without creating a top-level
	 * menu. The old Tools URL is intercepted before WordPress's admin-menu
	 * authorization check and redirected when CPMS is active.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$parent = self::cpms_parent_slug();
		if ( null !== $parent ) {
			$hook = add_submenu_page(
				$parent,
				'دموی یک‌بارمصرف CPMS',
				'دموی یک‌بارمصرف CPMS',
				self::CAP,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
			if ( is_string( $hook ) ) {
				self::$hook = $hook;
				add_action( 'load-' . $hook, array( __CLASS__, 'dispatch' ) );
			}

			return;
		}

		// CPMS is not loaded: keep the page usable, but never create a second
		// CPMS-like top-level menu or pretend it belongs to the absent parent.
		$hook = add_management_page(
			'دموی یک‌بارمصرف CPMS',
			'دموی یک‌بارمصرف CPMS',
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
		if ( is_string( $hook ) ) {
			self::$hook = $hook;
			add_action( 'load-' . $hook, array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * Redirect the legacy Tools URL before WordPress validates menu registration.
	 *
	 * This runs on `admin_menu` before the core direct-page access check. The
	 * legacy slug is deliberately not registered a second time: WordPress keys
	 * plugin-page callbacks by slug, so duplicate parent registrations can route
	 * the canonical page back to the redirect handler.
	 *
	 * @return void
	 */
	public static function redirect_legacy_tools_url(): void {
		if ( null === self::cpms_parent_slug() ) {
			return;
		}
		global $pagenow;
		if ( ! is_admin() || 'tools.php' !== ( $pagenow ?? '' ) ) {
			return;
		}

		// This is a read-only route selector; the exact slug is checked below and no state changes here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only; no state mutation or privileged action.
		$query = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
		$raw_page = $query['page'] ?? '';
		if ( ! is_string( $raw_page ) || self::PAGE !== sanitize_key( $raw_page ) || self::PAGE !== $raw_page ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'cpms' ), 403 );
		}

		// Forward only bounded scalar arguments with conventional keys. Never forward
		// credentials, nonce values, nested arrays, control characters or redirect targets.
		$args = array();
		foreach ( $query as $key => $value ) {
			if ( 'page' === $key || ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $key ) ) {
				continue;
			}
			$value = (string) $value;
			if ( strlen( $value ) > 256 || 1 === preg_match( '/[\\x00-\\x1F\\x7F]/', $value )
				|| 1 === preg_match( '/(?:nonce|token|secret|password|auth|redirect|url)/i', $key ) ) {
				continue;
			}
			$safe_key = sanitize_key( $key );
			if ( '' === $safe_key || isset( $args[ $safe_key ] ) ) {
				continue;
			}
			$args[ $safe_key ] = sanitize_text_field( $value );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * @param string $hook_suffix Hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::$hook !== '' && $hook_suffix !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'cpdd-admin', CPMS_DEMO_URL . 'assets/admin.css', array(), CPMS_DEMO_VERSION );
	}

	/**
	 * @return Env
	 */
	private static function env(): Env {
		return self::$test_env ?? new Env();
	}

	/**
	 * @return Platform
	 */
	private static function platform(): Platform {
		return self::$test_platform ?? new Platform();
	}

	/**
	 * @param Env      $env Environment.
	 * @param Platform $platform Platform.
	 * @return Manifest
	 */
	private static function manifest( Env $env ): Manifest {
		return new Manifest( $env );
	}

	/**
	 * Never let a credential-bearing page be cached, framed or leaked via referrer.
	 *
	 * @return void
	 */
	private static function harden_headers(): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: DENY' );
		}
	}

	/**
	 * Handle a submitted action before any output is produced.
	 *
	 * @return void
	 */
	public static function dispatch(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in Request::authorize().
		$action = isset( $_POST[ self::ACTION ] ) ? sanitize_key( (string) $_POST[ self::ACTION ] ) : '';
		if ( '' === $action ) {
			return;
		}

		self::harden_headers();

		$env      = self::env();
		$platform = self::platform();
		$manifest = self::manifest( $env );
		$request  = new Request( $env, $manifest );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize() verifies the nonce.
		$post = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		try {
			$request->authorize( $action, (array) $post );
			if ( ! $platform->available() ) {
				throw new Exception( 'CPMS_INACTIVE', 'CPMS Classic فعال نیست یا نسخهٔ سازگار در دسترس نیست؛ هیچ عملیات دمو اجرا نشد.' );
			}
			$admin_id = $env->current_user_id();

			switch ( $action ) {
				case Request::ACTION_PROVISION:
					$credentials    = new Credentials( $env );
					$preflight      = new Preflight( $env, $platform, $manifest );
					$provisioner    = new Provisioner( $env, $platform, $manifest, $preflight, $credentials );
					$result         = $provisioner->provision( $admin_id );
					self::$credentials = $result['credentials'];
					self::$provisioned = $result;
					self::$notice   = array(
						'type' => 'success',
						'text' => 'دمو ساخته شد. رمزها فقط در همین پاسخ نمایش داده می‌شوند — همین حالا کپی کنید.',
					);
					break;

				case Request::ACTION_ROTATE:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد.' );
					}
					$credentials       = new Credentials( $env );
					self::$credentials = $credentials->rotate( $loaded );
					self::$notice      = array(
						'type' => 'success',
						'text' => 'رمزهای همهٔ حساب‌های دمو چرخانده شد و نشست‌های قبلی آن‌ها باطل شد.',
					);
					break;

				case Request::ACTION_OTP:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد.' );
					}
					$otp        = new Otp( $env, $platform );
					self::$otp  = $otp->issue( $loaded );
					self::$notice = array(
						'type' => 'success',
						'text' => 'کد نمایشی ساخته شد. اعتبار، سقف تلاش و مصرف یک‌بارهٔ OTP تولیدی دست‌نخورده است.',
					);
					break;

				case Request::ACTION_TEARDOWN:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد.' );
					}
					$teardown       = new Teardown( $env, $platform, $manifest );
					self::$teardown = $teardown->execute( $loaded, $admin_id );
					self::$notice   = array(
						'type' => self::$teardown['ok'] ? 'success' : 'warning',
						'text' => self::$teardown['ok']
							? 'پاک‌سازی انجام و تأیید شد؛ هیچ اثر باقی‌مانده‌ای از دمو یافت نشد.'
							: 'پاک‌سازی ناقص ماند؛ فهرست آثار باقی‌مانده در پایین آمده است.',
					);
					break;

				case Request::ACTION_DIAGNOSE:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد.' );
					}
					$teardown          = new Teardown( $env, $platform, $manifest );
					self::$diagnostic  = $teardown->diagnose( $loaded, $admin_id );
					self::$notice      = array(
						'type' => 'info',
						'text' => 'تشخیص فقط‌خواندنی اجرا شد؛ هیچ ردیفی حذف نشد.',
					);
					break;

				case Request::ACTION_DISMISS:
					$env->with_lock(
						'dismiss_tombstone',
						static function () use ( $manifest ): void {
							$tombstone = $manifest->tombstone();
							if ( null !== $tombstone && true !== ( $tombstone['ok'] ?? $tombstone['clean'] ?? false ) ) {
								throw new Exception( 'TEARDOWN_INCOMPLETE', 'سابقهٔ پاک‌سازی ناقص را نمی‌توان پاک کرد؛ ابتدا آثار باقی‌مانده را بررسی کنید.' );
							}
							$manifest->clear_tombstone();
						}
					);
					self::$notice = array(
						'type' => 'success',
						'text' => 'سابقهٔ پاک‌سازی حذف شد.',
					);
					break;

				default:
					throw new Exception( 'ACTION_UNKNOWN', 'عملیات ناشناخته است.' );
			}
		} catch ( Exception $e ) {
			self::$notice = array(
				'type' => 'error',
				'text' => $e->getMessage() . ' (کد: ' . $e->demo_code() . ')',
			);
		} catch ( \Throwable $e ) {
			unset( $e );
			self::$notice = array(
				'type' => 'error',
				'text' => 'خطای غیرمنتظره رخ داد؛ هیچ تغییری خارج از مسیر گزارش‌شده انجام نشد.',
			);
		}
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی لازم را ندارید.', 'cpms' ) );
		}
		if ( self::$notice === null || 'error' === ( self::$notice['type'] ?? '' ) ) {
			self::harden_headers();
		}

		$env      = self::env();
		$platform = self::platform();
		$manifest = self::manifest( $env );
		$request  = new Request( $env, $manifest );
		$report        = new Preflight( $env, $platform, $manifest );
		$state         = $report->run();
		$cpms_available = $platform->available();

		$loaded       = null;
		$manifest_err = '';
		try {
			$loaded = $manifest->load();
		} catch ( Exception $e ) {
			$manifest_err = $e->getMessage();
		}
		$tombstone         = $manifest->tombstone();
		$uninstall_blocked = $env->option_get( 'cpdd_uninstall_blocked', false );

		echo '<div class="wrap cpdd" dir="rtl">';
		echo '<h1>دموی یک‌بارمصرف CPMS Classic</h1>';
		echo '<p class="cpdd-lead">این صفحه فقط برای <strong>سایت آزمایشی خالی</strong> است. هیچ دادهٔ واقعی بیمار، پیامک واقعی یا پرداخت واقعی ساخته نمی‌شود و افزونهٔ Production تغییر نمی‌کند.</p>';

		self::render_notice();
		if ( is_array( $uninstall_blocked ) ) {
			echo '<div class="notice notice-warning inline" data-cpdd-uninstall-blocked="1"><p>تلاش قبلی برای حذف افزونهٔ همراه متوقف شد زیرا دادهٔ دمو هنوز در پایگاه‌داده وجود داشت. فرادادهٔ مالکیت حفظ شده است؛ اکنون می‌توانید تشخیص و پاک‌سازی ایمن را اجرا کنید.</p></div>';
		}
		self::render_identity( $env, $manifest, $loaded );
		if ( '' !== $manifest_err ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $manifest_err ) . '</p></div>';
		}
		self::render_preflight( $state );
		self::render_credentials( $loaded );
		self::render_otp();

		if ( null === $loaded ) {
			self::render_form( $request, Request::ACTION_PROVISION, 'ساخت دمو', 'I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE', 'ساخت دمو فقط زمانی انجام می‌شود که همهٔ بررسی‌های بالا PASS باشند.' );
		} elseif ( ! $cpms_available ) {
			echo '<div class="notice notice-warning inline" data-cpdd-cpms-inactive="1"><p>CPMS Classic غیرفعال است یا نسخهٔ سازگار آن در دسترس نیست. فراداده و داده‌های دمو دست‌نخورده می‌مانند؛ برای مشاهده، تشخیص یا پاک‌سازی ایمن، ابتدا CPMS Classic سازگار را دوباره فعال کنید. در این وضعیت هیچ عملیات دمو اجرا نمی‌شود.</p></div>';
		} else {
			if ( 'active' === (string) ( $loaded['status'] ?? '' ) ) {
				self::render_links( $platform, $loaded );
				self::render_form( $request, Request::ACTION_OTP, 'ساخت کد OTP نمایشی', 'SHOW_DEMO_OTP', 'پس از زدن «درخواست کد» در برگهٔ رزرو عمومی بیمار، این دکمه کد یک‌بارمصرف همان چالش را فقط در همین پاسخ نمایش می‌دهد.' );
				self::render_form( $request, Request::ACTION_ROTATE, 'چرخش رمزهای همهٔ حساب‌های دمو', 'ROTATE_DEMO_PASSWORDS', 'برای وقتی که رمزهای اولیه گم شده‌اند. رمزهای قبلی بی‌درنگ بی‌اعتبار می‌شوند.' );
			} else {
				self::render_recovery_card( $loaded );
			}
			self::render_form( $request, Request::ACTION_DIAGNOSE, 'تشخیص فقط‌خواندنی مالکیت', 'RUN_READ_ONLY_DIAGNOSTIC', 'هیچ چیزی حذف نمی‌شود؛ فقط اثبات مالکیت و برنامهٔ حذف را نشان می‌دهد.' );
			self::render_diagnostic();
			self::render_form( $request, Request::ACTION_TEARDOWN, 'پاک‌سازی انتخاب‌شدهٔ دمو', 'DESTROY_DEMO_DATA_ONLY', 'اگر مالکیت کامل اثبات نشود، پاک‌سازی مسدود می‌شود و هیچ ردیفی حذف نمی‌شود.' );
		}

		self::render_teardown_result();

		if ( is_array( $tombstone ) ) {
			self::render_tombstone( $request, $tombstone );
		}

		self::render_instructions( null !== self::cpms_parent_slug() );
		self::render_limitations();
		echo '</div>';
	}

	/**
	 * Render recovery details when provisioning or teardown was interrupted (S-4).
	 *
	 * @param array<string,mixed> $loaded Loaded manifest.
	 * @return void
	 */
	private static function render_recovery_card( array $loaded ): void {
		$status = (string) ( $loaded['status'] ?? 'unknown' );
		$stage  = (string) ( $loaded['failed_stage'] ?? '—' );
		$code   = (string) ( $loaded['failure_code'] ?? '—' );
		echo '<div class="cpdd-card" data-cpdd-recovery="' . esc_attr( $status ) . '">';
		echo '<h2>بازیابی ایمن عملیات ناتمام</h2>';
		echo '<p class="cpdd-warn">این دمو در وضعیت ناتمام (<code dir="ltr">' . esc_html( $status ) . '</code> — مرحلهٔ <code dir="ltr">' . esc_html( $stage ) . '</code> — کد <code dir="ltr">' . esc_html( $code ) . '</code>) قرار دارد. چرخش رمز و صدور OTP غیرفعال است؛ از «تشخیص فقط‌خواندنی» و «پاک‌سازی انتخاب‌شدهٔ دمو» برای حذف ایمن رکوردهای باقی‌مانده استفاده کنید.</p>';
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_notice(): void {
		if ( ! is_array( self::$notice ) ) {
			return;
		}
		$class = 'notice-' . ( 'error' === self::$notice['type'] ? 'error' : ( 'warning' === self::$notice['type'] ? 'warning' : ( 'success' === self::$notice['type'] ? 'success' : 'info' ) ) );
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( self::$notice['text'] ) . '</p></div>';
	}

	/**
	 * @param Env                       $env Environment.
	 * @param Manifest                  $manifest Manifest store.
	 * @param array<string,mixed>|null  $loaded Manifest.
	 * @return void
	 */
	private static function render_identity( Env $env, Manifest $manifest, ?array $loaded ): void {
		echo '<div class="cpdd-card"><h2>شناسهٔ سایت (پیش از هر تغییر دوباره بررسی می‌شود)</h2><table class="widefat striped cpdd-kv"><tbody>';
		$rows = array(
			'آدرس سایت'       => $env->home_url(),
			'نام دیتابیس'     => $env->db_name(),
			'پیشوند جداول'    => $env->table_prefix(),
			'اثر انگشت سایت'  => $manifest->site_fingerprint(),
			'PHP'             => $env->php_version(),
			'وردپرس'          => $env->wp_version(),
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td dir="ltr">' . esc_html( (string) $value ) . '</td></tr>';
		}
		if ( is_array( $loaded ) ) {
			echo '<tr><th>شناسهٔ دمو</th><td dir="ltr" data-cpdd-fixture-id="' . esc_attr( (string) $loaded['fixture_id'] ) . '">' . esc_html( (string) $loaded['fixture_id'] ) . '</td></tr>';
			echo '<tr><th>وضعیت دمو</th><td data-cpdd-status="' . esc_attr( (string) $loaded['status'] ) . '">' . esc_html( (string) $loaded['status'] ) . '</td></tr>';
			echo '<tr><th>Shortcode رزرو</th><td dir="ltr">' . esc_html( (string) ( $loaded['shortcode'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array{ok:bool,checks:list<array{id:string,label:string,status:string,detail:string}>} $state State.
	 * @return void
	 */
	private static function render_preflight( array $state ): void {
		echo '<div class="cpdd-card" data-cpdd-preflight="' . ( $state['ok'] ? 'pass' : 'fail' ) . '"><h2>پیش‌بررسی امنیتی</h2><p>وضعیت کلی: ';
		echo $state['ok'] ? '<strong class="cpdd-pass">PASS</strong>' : '<strong class="cpdd-fail">FAIL — ایجاد دمو مسدود است</strong>';
		echo '</p><table class="widefat striped"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( $state['checks'] as $check ) {
			$pass = 'pass' === $check['status'];
			echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td class="' . ( $pass ? 'cpdd-pass' : 'cpdd-fail' ) . '">' . ( $pass ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( $check['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Render one-time credentials for provisioning or password rotation (S-6).
	 *
	 * @param array<string,mixed>|null $loaded Active manifest.
	 * @return void
	 */
	private static function render_credentials( ?array $loaded = null ): void {
		if ( ! is_array( self::$credentials ) ) {
			return;
		}
		$personas = Credentials::personas();
		$fixture  = '';
		if ( is_array( $loaded ) && isset( $loaded['fixture_id'] ) ) {
			$fixture = (string) $loaded['fixture_id'];
		} elseif ( is_array( self::$provisioned ) && isset( self::$provisioned['manifest']['fixture_id'] ) ) {
			$fixture = (string) self::$provisioned['manifest']['fixture_id'];
		}
		echo '<div class="cpdd-card cpdd-secret" data-cpdd-secret="credentials"><h2>رمزهای یک‌بارمصرف (فقط همین پاسخ)</h2>';
		echo '<p class="cpdd-warn">این رمزها ذخیره نمی‌شوند و با بازخوانی صفحه از بین می‌روند. هر رمز مستقل و تصادفی است. آن‌ها را فقط در یک مدیر رمز امن نگه دارید و در هیچ Issue/Log/تصویر منتشر نکنید.</p>';
		echo '<table class="widefat striped"><thead><tr><th>نقش</th><th>نام کاربری</th><th>رمز</th></tr></thead><tbody>';
		foreach ( $personas as $key => $persona ) {
			$login = 'cpdd-' . $key . '-' . $fixture;
			echo '<tr data-cpdd-persona="' . esc_attr( $key ) . '"><td>' . esc_html( $persona['label'] ) . '</td><td dir="ltr" data-cpdd-login="' . esc_attr( $login ) . '">' . esc_html( $login ) . '</td><td><textarea readonly rows="1" dir="ltr" class="cpdd-pass-field">' . esc_textarea( (string) ( self::$credentials[ $key ] ?? '' ) ) . '</textarea></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @return void
	 */
	private static function render_otp(): void {
		if ( ! is_array( self::$otp ) ) {
			return;
		}
		echo '<div class="cpdd-card cpdd-secret" data-cpdd-secret="otp"><h2>کد OTP نمایشی</h2>';
		echo '<p>کد: <code dir="ltr" class="cpdd-otp">' . esc_html( (string) self::$otp['code'] ) . '</code></p>';
		echo '<p>انقضا (UTC): <span dir="ltr">' . esc_html( (string) self::$otp['expires_at'] ) . '</span></p>';
		echo '<p class="cpdd-warn">این کد فقط یک بار و از مسیر عادی Verify قابل استفاده است. سقف تلاش، قفل موقت و مصرف یک‌باره تغییر نکرده‌اند.</p></div>';
	}

	/**
	 * @param Request $request Request helper.
	 * @param string  $action Action key.
	 * @param string  $title Button label.
	 * @param string  $phrase Required phrase.
	 * @param string  $hint Hint.
	 * @return void
	 */
	private static function render_form( Request $request, string $action, string $title, string $phrase, string $hint ): void {
		$label = Request::actions()[ $action ]['label'] ?? $action;
		echo '<div class="cpdd-card"><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( $hint ) . '</p>';
		echo '<form method="post" autocomplete="off" data-cpdd-form="' . esc_attr( $action ) . '">';
		wp_nonce_field( $request->nonce_action( $action ), 'cpdd_nonce' );
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
		echo '<p><label for="cpdd-phrase-' . esc_attr( $action ) . '">عبارت تأیید را دقیقاً وارد کنید: <code dir="ltr">' . esc_html( $phrase ) . '</code></label><br />';
		echo '<input class="regular-text" dir="ltr" id="cpdd-phrase-' . esc_attr( $action ) . '" name="cpdd_confirm" value="" /></p>';
		echo '<p><button type="submit" class="button button-' . ( Request::ACTION_TEARDOWN === $action ? 'link-delete' : 'primary' ) . '">' . esc_html( $title ) . '</button></p>';
		echo '</form></div>';
	}

	/**
	 * @param Platform             $platform CPMS adapter.
	 * @param array<string,mixed>  $manifest Manifest.
	 * @return void
	 */
	private static function render_links( Platform $platform, array $manifest ): void {
		$fixture = (string) $manifest['fixture_id'];
		$links   = (array) ( $manifest['links'] ?? array() );
		$clinic  = (int) $manifest['ids']['clinic_id'];
		$org     = (int) $manifest['ids']['organization_id'];
		$pages   = array(
			'organization_manager' => 'Management Portal — انتخاب سازمان ساختگی',
			'clinic_manager'       => 'Management Portal — انتخاب درمانگاه ساختگی',
			'doctor'               => 'Staff Portal — ماژول doctor (مسیر كانونیکال)',
			'doctor_second'        => 'Staff Portal — ماژول pricing (تعرفهٔ پزشک دوم)',
			'receptionist'         => 'Staff Portal — ماژول reception',
			'receptionist_second'  => 'Staff Portal — ماژول messages (پذیرش دوم)',
			'accountant'           => 'Staff Portal — ماژول finance',
			'patient'              => 'Patient Portal',
		);
		echo '<div class="cpdd-card" data-cpdd-links="card"><h2>پیوندها و نقشهٔ نقش ← صفحه</h2>';
		echo '<p>ورود هر Persona را در یک <strong>Private/Incognito</strong> جدا انجام دهید تا نشست‌ها قاطی نشوند.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Persona</th><th>صفحه (مسیر واقعی ثبت‌شده)</th><th>پیوند</th><th>نام کاربری</th></tr></thead><tbody>';
		foreach ( $pages as $persona => $page ) {
			$url = '';
			if ( 'patient' === $persona ) {
				$url = (string) ( $links['patient'] ?? '' );
			} elseif ( 'organization_manager' === $persona ) {
				$url = (string) ( $links['management'] ?? '' );
				$url = $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'cpms-org=' . $org;
			} elseif ( 'clinic_manager' === $persona ) {
				$url = (string) ( $links['management'] ?? '' );
				$url = $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'cpms-org=' . $org . '&cpms-clinic=' . $clinic;
			} elseif ( 'receptionist' === $persona ) {
				$url = (string) ( $links['receptionist'] ?? $links['reception'] ?? '' );
			} elseif ( 'receptionist_second' === $persona ) {
				$url = (string) ( $links['messages'] ?? '' );
			} elseif ( 'accountant' === $persona ) {
				$url = (string) ( $links['accountant'] ?? $links['finance'] ?? '' );
			} elseif ( 'doctor' === $persona ) {
				$url = (string) ( $links['doctor'] ?? '' );
			} elseif ( 'doctor_second' === $persona ) {
				$url = (string) ( $links['pricing'] ?? '' );
			}
			echo '<tr><td>' . esc_html( Credentials::personas()[ $persona ]['label'] ) . '</td><td>' . esc_html( $page ) . '</td><td>';
			if ( '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '" data-cpdd-link="' . esc_attr( $persona ) . '" target="_blank" rel="noopener noreferrer">باز کردن</a> <code dir="ltr">' . esc_html( $url ) . '</code>';
			} else {
				echo '—';
			}
			echo '</td><td dir="ltr">' . esc_html( 'cpdd-' . $persona . '-' . $fixture ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><strong>رزرو عمومی:</strong> <a href="' . esc_url( (string) ( $links['booking'] ?? '' ) ) . '" data-cpdd-link="booking" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $links['booking'] ?? '' ) ) . '</a></p>';
		echo '<p>Shortcode واقعی برگه: <code dir="ltr">' . esc_html( $platform->booking_shortcode( $clinic ) ) . '</code></p>';
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_diagnostic(): void {
		if ( ! is_array( self::$diagnostic ) ) {
			return;
		}
		echo '<div class="cpdd-card" data-cpdd-diagnostic="' . ( self::$diagnostic['ok'] ? 'pass' : 'blocked' ) . '"><h2>نتیجهٔ تشخیص</h2>';
		echo '<p>اثبات مالکیت: ' . ( self::$diagnostic['ok'] ? '<strong class="cpdd-pass">PASS</strong>' : '<strong class="cpdd-fail">BLOCKED</strong>' ) . '</p>';
		if ( array() !== (array) self::$diagnostic['problems'] ) {
			echo '<ul>';
			foreach ( (array) self::$diagnostic['problems'] as $problem ) {
				echo '<li>' . esc_html( (string) $problem ) . '</li>';
			}
			echo '</ul>';
		}
		if ( array() !== (array) self::$diagnostic['plan'] ) {
			echo '<table class="widefat striped"><thead><tr><th>جدول</th><th>معیار مالکیت</th><th>تعداد ردیف</th></tr></thead><tbody>';
			foreach ( (array) self::$diagnostic['plan'] as $row ) {
				echo '<tr><td dir="ltr">' . esc_html( (string) $row['table'] ) . '</td><td dir="ltr">' . esc_html( (string) $row['predicate'] ) . '</td><td>' . esc_html( (string) $row['rows'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_teardown_result(): void {
		if ( ! is_array( self::$teardown ) ) {
			return;
		}
		echo '<div class="cpdd-card" data-cpdd-teardown="' . ( self::$teardown['ok'] ? 'clean' : 'incomplete' ) . '"><h2>نتیجهٔ پاک‌سازی</h2>';
		echo '<p>وضعیت: ' . ( self::$teardown['ok'] ? '<strong class="cpdd-pass">CLEAN</strong>' : '<strong class="cpdd-fail">INCOMPLETE</strong>' ) . '</p>';
		echo '<p>ردیف‌های حذف‌شده:</p><ul>';
		foreach ( (array) self::$teardown['deleted'] as $table => $count ) {
			echo '<li dir="ltr">' . esc_html( (string) $table ) . ': ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';
		if ( array() !== (array) self::$teardown['remaining'] ) {
			echo '<p class="cpdd-fail">آثار باقی‌مانده:</p><ul>';
			foreach ( (array) self::$teardown['remaining'] as $item ) {
				echo '<li dir="ltr">' . esc_html( (string) $item ) . '</li>';
			}
			echo '</ul><p>در این حالت، امن‌ترین راه، نابودی کامل همین سایت/دیتابیس یک‌بارمصرف است (بخش دستورالعمل).</p>';
		}
		echo '</div>';
	}

	/**
	 * @param Request              $request Request helper.
	 * @param array<string,mixed>  $tombstone Tombstone.
	 * @return void
	 */
	private static function render_tombstone( Request $request, array $tombstone ): void {
		$is_ok = true === ( $tombstone['ok'] ?? $tombstone['clean'] ?? false );
		$when  = (string) ( $tombstone['at'] ?? $tombstone['removed_at'] ?? '' );
		echo '<div class="cpdd-card" data-cpdd-tombstone="' . ( $is_ok ? 'clean' : 'incomplete' ) . '"><h2>سابقهٔ آخرین پاک‌سازی</h2>';
		echo '<p>شناسهٔ دمو: <span dir="ltr">' . esc_html( (string) ( $tombstone['fixture_id'] ?? '' ) ) . '</span> — زمان: <span dir="ltr">' . esc_html( $when ) . '</span></p>';
		echo '<p>نتیجه: ' . ( $is_ok ? 'کامل' : 'ناقص — بررسی لازم است' ) . '</p>';
		if ( array() !== (array) ( $tombstone['remaining'] ?? array() ) ) {
			echo '<ul>';
			foreach ( (array) $tombstone['remaining'] as $item ) {
				echo '<li dir="ltr">' . esc_html( (string) $item ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $is_ok ) {
			echo '<form method="post" autocomplete="off" data-cpdd-form="' . esc_attr( Request::ACTION_DISMISS ) . '">';
			wp_nonce_field( $request->nonce_action( Request::ACTION_DISMISS ), 'cpdd_nonce' );
			echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( Request::ACTION_DISMISS ) . '" />';
			echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
			echo '<p><label>عبارت تأیید: <code dir="ltr">CLEAR_TEARDOWN_RECORD</code></label> <input dir="ltr" name="cpdd_confirm" value="" /></p>';
			echo '<p><button type="submit" class="button">پاک‌کردن سابقه</button></p></form>';
		}
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_instructions( bool $cpms_menu_available ): void {
		echo '<div class="cpdd-card"><h2>دستورالعمل فارسی</h2><ol class="cpdd-steps">';
		$menu_step = $cpms_menu_available
			? 'به «مدیریت مطب» → «دموی یک‌بارمصرف CPMS» بروید و همهٔ بررسی‌ها را PASS ببینید. اگر FAIL بود، دمو ساخته نمی‌شود.'
			: 'تا وقتی CPMS Classic غیرفعال است، صفحه از مسیر Tools → «دموی یک‌بارمصرف CPMS» در دسترس می‌ماند؛ بررسی CPMS باید FAIL باشد و ساخت دمو ممکن نیست.';
		$steps = array(
			'یک WordPress تازه و خالی (تک‌سایتی، HTTPS) بسازید و افزونهٔ Production «Clinic Practice Management» را نصب و فعال کنید.',
			'این افزونهٔ همراه را از Plugins → Add New → Upload Plugin نصب و فعال کنید. اگر افزونهٔ اصلی فعال نباشد، فعال‌سازی متوقف می‌شود.',
			$menu_step,
			'عبارت تأیید را تایپ کنید و «ساخت دمو» را بزنید. رمزها فقط در همان پاسخ نمایش داده می‌شوند؛ همان‌جا کپی کنید.',
			'برای هر Persona یک پنجرهٔ Private/Incognito جدا باز کنید و از پیوند همان نقش وارد شوید.',
			'رزرو عمومی را از پیوند برگهٔ رزرو آزمایش کنید: یک اسلات آزاد انتخاب، شمارهٔ ساختگی وارد و «درخواست کد» بزنید.',
			'در همین صفحه دکمهٔ «ساخت کد OTP نمایشی» را بزنید، کد را در مرورگر بیمار وارد کنید و رزرو را تأیید کنید.',
			'در پایان، «تشخیص فقط‌خواندنی» را اجرا کنید؛ اگر PASS بود «پاک‌سازی انتخاب‌شدهٔ دمو» را بزنید. اگر پاک‌سازی کامل نشد، کل سایت/دیتابیس یک‌بارمصرف را نابود کنید.',
		);
		foreach ( $steps as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol></div>';
	}

	/**
	 * @return void
	 */
	private static function render_limitations(): void {
		echo '<div class="cpdd-card"><h2>محدودیت‌ها (صادقانه)</h2><ul class="cpdd-steps">';
		$items = array(
			'این ابزار دموی نمایشی می‌سازد؛ Production، مجوز (License)، مجوزدهی، سقف OTP، هم‌زمانی رزرو و دروازه‌های پرداخت تغییر نمی‌کنند.',
			'کد OTP نمایشی فقط Hash یک چالش زندهٔ همین بیمار ساختگی را بازنویسی می‌کند؛ تمدید اعتبار، بازنشانی تلاش‌ها یا دورزدن Verify انجام نمی‌شود.',
			'پرداخت‌ها فقط آفلاین با روش other ثبت می‌شوند و هیچ درگاه یا پیامک واقعی فعال نمی‌شود.',
			'هر حساب دمو رمز مستقل دارد و هیچ حساب Administrator ساخته نمی‌شود؛ حساب مدیر شما هرگز در محدودهٔ حذف قرار نمی‌گیرد.',
			'پاک‌سازی فقط وقتی انجام می‌شود که مالکیت همهٔ ردیف‌ها اثبات شود؛ در غیر این صورت مسدود می‌شود و گزارش تشخیص نشان داده می‌شود.',
		);
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul></div>';
	}
}

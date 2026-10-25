<?php
/**
 * The single wp-admin surface: Tools → دموی یک‌بارمصرف CPMS.
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
	 * @var string Hook suffix of the Tools page.
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
	 * Register the page under Tools and hook the pre-render dispatcher.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
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
		return new Env();
	}

	/**
	 * @return Platform
	 */
	private static function platform(): Platform {
		return new Platform();
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
						'text' => 'رمزهای هر شش حساب چرخانده شد و نشست‌های قبلی آن‌ها باطل شد.',
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
					$tombstone = $manifest->tombstone();
					if ( null !== $tombstone && ( $tombstone['ok'] ?? false ) !== true ) {
						throw new Exception( 'TEARDOWN_INCOMPLETE', 'سابقهٔ پاک‌سازی ناقص را نمی‌توان پاک کرد؛ ابتدا آثار باقی‌مانده را بررسی کنید.' );
					}
					$env->option_delete( Manifest::TOMBSTONE );
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
		$report   = new Preflight( $env, $platform, $manifest );
		$state    = $report->run();

		$loaded       = null;
		$manifest_err = '';
		try {
			$loaded = $manifest->load();
		} catch ( Exception $e ) {
			$manifest_err = $e->getMessage();
		}
		$tombstone = $manifest->tombstone();

		echo '<div class="wrap cpdd" dir="rtl">';
		echo '<h1>دموی یک‌بارمصرف CPMS Classic</h1>';
		echo '<p class="cpdd-lead">این صفحه فقط برای <strong>سایت آزمایشی خالی</strong> است. هیچ دادهٔ واقعی بیمار، پیامک واقعی یا پرداخت واقعی ساخته نمی‌شود و افزونهٔ Production تغییر نمی‌کند.</p>';

		self::render_notice();
		self::render_identity( $env, $manifest, $loaded );
		if ( '' !== $manifest_err ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $manifest_err ) . '</p></div>';
		}
		self::render_preflight( $state );
		self::render_credentials();
		self::render_otp();

		if ( null === $loaded ) {
			self::render_form( $request, Request::ACTION_PROVISION, 'ساخت دمو', 'I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE', 'ساخت دمو فقط زمانی انجام می‌شود که همهٔ بررسی‌های بالا PASS باشند.' );
		} else {
			self::render_links( $platform, $loaded );
			self::render_form( $request, Request::ACTION_ROTATE, 'چرخش رمزهای شش حساب', 'ROTATE_DEMO_PASSWORDS', 'برای وقتی که رمزهای اولیه گم شده‌اند. رمزهای قبلی بی‌درنگ بی‌اعتبار می‌شوند.' );
			self::render_form( $request, Request::ACTION_DIAGNOSE, 'تشخیص فقط‌خواندنی مالکیت', 'RUN_READ_ONLY_DIAGNOSTIC', 'هیچ چیزی حذف نمی‌شود؛ فقط اثبات مالکیت و برنامهٔ حذف را نشان می‌دهد.' );
			self::render_diagnostic();
			self::render_form( $request, Request::ACTION_TEARDOWN, 'پاک‌سازی انتخاب‌شدهٔ دمو', 'DESTROY_DEMO_DATA_ONLY', 'اگر مالکیت کامل اثبات نشود، پاک‌سازی مسدود می‌شود و هیچ ردیفی حذف نمی‌شود.' );
			self::render_teardown_result();
		}

		if ( is_array( $tombstone ) ) {
			self::render_tombstone( $request, $tombstone );
		}

		self::render_instructions();
		self::render_limitations();
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
			echo '<tr><th>شناسهٔ دمو</th><td dir="ltr">' . esc_html( (string) $loaded['fixture_id'] ) . '</td></tr>';
			echo '<tr><th>وضعیت دمو</th><td>' . esc_html( (string) $loaded['status'] ) . '</td></tr>';
			echo '<tr><th>Shortcode رزرو</th><td dir="ltr">' . esc_html( (string) ( $loaded['shortcode'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array{ok:bool,checks:list<array{id:string,label:string,status:string,detail:string}>} $state State.
	 * @return void
	 */
	private static function render_preflight( array $state ): void {
		echo '<div class="cpdd-card"><h2>پیش‌بررسی امنیتی</h2><p>وضعیت کلی: ';
		echo $state['ok'] ? '<strong class="cpdd-pass">PASS</strong>' : '<strong class="cpdd-fail">FAIL — ایجاد دمو مسدود است</strong>';
		echo '</p><table class="widefat striped"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( $state['checks'] as $check ) {
			$pass = 'pass' === $check['status'];
			echo '<tr><td>' . esc_html( $check['label'] ) . '</td><td class="' . ( $pass ? 'cpdd-pass' : 'cpdd-fail' ) . '">' . ( $pass ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( $check['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @return void
	 */
	private static function render_credentials(): void {
		if ( ! is_array( self::$credentials ) ) {
			return;
		}
		$personas = Credentials::personas();
		$fixture  = is_array( self::$provisioned ) ? (string) self::$provisioned['manifest']['fixture_id'] : '';
		echo '<div class="cpdd-card cpdd-secret"><h2>رمزهای یک‌بارمصرف (فقط همین پاسخ)</h2>';
		echo '<p class="cpdd-warn">این رمزها ذخیره نمی‌شوند و با بازخوانی صفحه از بین می‌روند. هر رمز مستقل و تصادفی است. آن‌ها را فقط در یک مدیر رمز امن نگه دارید و در هیچ Issue/Log/تصویر منتشر نکنید.</p>';
		echo '<table class="widefat striped"><thead><tr><th>نقش</th><th>نام کاربری</th><th>رمز</th></tr></thead><tbody>';
		foreach ( $personas as $key => $persona ) {
			$login = 'cpdd-' . $key . '-' . $fixture;
			echo '<tr><td>' . esc_html( $persona['label'] ) . '</td><td dir="ltr">' . esc_html( $login ) . '</td><td><textarea readonly rows="1" dir="ltr" class="cpdd-pass-field">' . esc_textarea( (string) ( self::$credentials[ $key ] ?? '' ) ) . '</textarea></td></tr>';
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
		echo '<div class="cpdd-card cpdd-secret"><h2>کد OTP نمایشی</h2>';
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
		echo '<form method="post" autocomplete="off">';
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
			'receptionist'         => 'Staff Portal — ماژول reception',
			'accountant'           => 'Staff Portal — ماژول finance',
			'patient'              => 'Patient Portal',
		);
		echo '<div class="cpdd-card"><h2>پیوندها و نقشهٔ نقش ← صفحه</h2>';
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
			} elseif ( in_array( $persona, array( 'doctor', 'receptionist', 'accountant' ), true ) ) {
				$url = (string) ( $links[ $persona ] ?? '' );
			}
			echo '<tr><td>' . esc_html( Credentials::personas()[ $persona ]['label'] ) . '</td><td>' . esc_html( $page ) . '</td><td>' . ( $url !== '' ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">باز کردن</a>' : '—' ) . '</td><td dir="ltr">' . esc_html( 'cpdd-' . $persona . '-' . $fixture ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><strong>رزرو عمومی:</strong> <a href="' . esc_url( (string) ( $links['booking'] ?? '' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $links['booking'] ?? '' ) ) . '</a></p>';
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
		echo '<div class="cpdd-card"><h2>نتیجهٔ تشخیص</h2>';
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
		echo '<div class="cpdd-card"><h2>نتیجهٔ پاک‌سازی</h2>';
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
		echo '<div class="cpdd-card"><h2>سابقهٔ آخرین پاک‌سازی</h2>';
		echo '<p>شناسهٔ دمو: <span dir="ltr">' . esc_html( (string) ( $tombstone['fixture_id'] ?? '' ) ) . '</span> — زمان: <span dir="ltr">' . esc_html( (string) ( $tombstone['at'] ?? '' ) ) . '</span></p>';
		echo '<p>نتیجه: ' . ( ( $tombstone['ok'] ?? false ) ? 'کامل' : 'ناقص — بررسی لازم است' ) . '</p>';
		if ( array() !== (array) ( $tombstone['remaining'] ?? array() ) ) {
			echo '<ul>';
			foreach ( (array) $tombstone['remaining'] as $item ) {
				echo '<li dir="ltr">' . esc_html( (string) $item ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ( $tombstone['ok'] ?? false ) === true ) {
			echo '<form method="post" autocomplete="off">';
			wp_nonce_field( $request->nonce_action( Request::ACTION_DISMISS ), 'cpdd_nonce' );
			echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( Request::ACTION_DISMISS ) . '" />';
			echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
			echo '<p><label>عبارت تأیید: <code dir="ltr">CLEAR_TEARDOWN_RECORD</code></label> <input dir="ltr" name="cpdd_confirm" value="" /></p>';
			echo '<p><button class="button">پاک‌کردن سابقه</button></p></form>';
		}
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_instructions(): void {
		echo '<div class="cpdd-card"><h2>دستورالعمل فارسی</h2><ol class="cpdd-steps">';
		$steps = array(
			'یک WordPress تازه و خالی (تک‌سایتی، HTTPS) بسازید و افزونهٔ Production «Clinic Practice Management» را نصب و فعال کنید.',
			'این افزونهٔ همراه را از Plugins → Add New → Upload Plugin نصب و فعال کنید. اگر افزونهٔ اصلی فعال نباشد، فعال‌سازی متوقف می‌شود.',
			'به Tools → «دموی یک‌بارمصرف CPMS» بروید و همهٔ بررسی‌ها را PASS ببینید. اگر FAIL بود، دمو ساخته نمی‌شود.',
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
			'همهٔ شش حساب رمز مستقل دارند و هیچ حساب Administrator ساخته نمی‌شود؛ حساب مدیر شما هرگز در محدودهٔ حذف قرار نمی‌گیرد.',
			'پاک‌سازی فقط وقتی انجام می‌شود که مالکیت همهٔ ردیف‌ها اثبات شود؛ در غیر این صورت مسدود می‌شود و گزارش تشخیص نشان داده می‌شود.',
		);
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul></div>';
	}
}

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

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

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

	/** @var array<string,mixed>|null Result of an additional-Clinic operation. */
	private static $extra = null;

	public static function reset_state(): void {
		self::$notice      = null;
		self::$credentials = null;
		self::$otp         = null;
		self::$diagnostic  = null;
		self::$teardown    = null;
		self::$provisioned = null;
		self::$extra       = null;
	}

	/**
	 * Register the administrator-only demo page under Tools.
	 *
	 * It deliberately does NOT depend on the production CPMS clinical menu.
	 * That menu can hide/reorder its own rows and third-party coexistence
	 * allowlists know only production slugs; coupling an evaluation console to
	 * it made navigation fragile for legitimate administrators. Tools is a
	 * stable WordPress location for `manage_options` and grants no clinical,
	 * financial or audit capability.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$hook = add_management_page(
			'دموی محدودشده CPMS — سایت غیرخالی',
			'دموی محدودشده CPMS',
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
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' !== self::$hook && $hook_suffix !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'cpsd-admin', CPMS_DEMO_URL . 'assets/admin.css', array(), CPMS_DEMO_VERSION );
		// The confirmation phrases are deliberately long and exact; the operator
		// should not have to retype them by hand. This only copies text into the
		// input that is already on the page - it never submits the form, and the
		// server-side phrase check is unchanged.
		// A handle with no file: the behaviour is four lines and must not add a
		// second asset to the package for a clipboard convenience.
		wp_register_script( 'cpsd-admin-copy', false, array(), CPMS_DEMO_VERSION, true );
		wp_enqueue_script( 'cpsd-admin-copy' );
		wp_add_inline_script(
			'cpsd-admin-copy',
			"( function () {\n"
			. "\tdocument.addEventListener( 'click', function ( event ) {\n"
			. "\t\tvar button = event.target.closest( '[data-cpdd-copy]' );\n"
			. "\t\tif ( ! button ) { return; }\n"
			. "\t\tevent.preventDefault();\n"
			. "\t\tvar field = document.getElementById( button.getAttribute( 'data-cpdd-copy' ) );\n"
			. "\t\tif ( ! field ) { return; }\n"
			. "\t\tfield.value = button.getAttribute( 'data-cpdd-copy-value' ) || '';\n"
			. "\t\tfield.focus();\n"
			. "\t} );\n"
			. "}() );\n"
		);
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
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ScopedRequest::authorize() verifies the action-specific nonce below.
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
		$ownership    = new ScopedOwnership( $env, $manifest );
		$clinics      = new ScopedClinics( $env, $platform, $manifest, $ownership );
		$post         = (array) wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		try {
			$request->authorize( $action, $post );
			$admin_id = $env->current_user_id();
			switch ( $action ) {
				case ScopedRequest::ACTION_PRIVATE_HOST:
					// The consequence is accepted for THIS host only; the phrase was
					// this site's own host name, and the sealed record names it too.
					$manifest->enable_private_host( $admin_id, ScopedRequest::private_host_phrase( $env ) );
					self::$notice = array(
						'type' => 'success',
						'text' => 'تأیید شد که این سایت یک سایت آزمایشی خصوصی است. پیش‌بررسی اکنون اجازهٔ ساخت دمو را می‌دهد؛ توجه کنید که پس از پاک‌سازی، شمار درمانگاه‌های این سایت یکی بیشتر از قبل می‌ماند.',
					);
					break;
				case ScopedRequest::ACTION_ENABLE:
					$manifest->enable_mode( $admin_id );
					self::$notice = array( 'type' => 'success', 'text' => 'حالت محدودشده فعال شد. ساخت دمو همچنان نیاز به تأیید جداگانه دارد.' );
					break;
				case ScopedRequest::ACTION_PROVISION:
					self::$provisioned = $provisioner->provision( $admin_id );
					self::$credentials = self::$provisioned['credentials'];
					self::$notice      = array( 'type' => 'success', 'text' => 'دمو ساخته شد. رمزها فقط در همین پاسخ نمایش داده میشوند.' );
					break;
				case ScopedRequest::ACTION_RESUME:
					// Additive recovery only: ScopedRecovery must prove that every
					// synthetic row already exists before the seal is completed, and
					// the provisioner re-checks that inside the concurrency lock.
					self::$provisioned = $provisioner->finish( $admin_id );
					self::$notice      = array( 'type' => 'success', 'text' => 'دموی ناتمام با همان شواهد مهرشده کامل شد. رمزهای نقش‌ها در این پاسخ نیستند؛ اگر آن‌ها را ندارید، «چرخش رمزهای نمایشی» را اجرا کنید.' );
					break;
				case ScopedRequest::ACTION_ROTATE:
					$loaded = $manifest->load();
					if ( null === $loaded || 'active' !== (string) ( $loaded['status'] ?? '' ) ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی برای چرخش رمز وجود ندارد.' );
					}
					self::$credentials = $credentials->rotate( $loaded, 'cpsd-' );
					self::$notice      = array( 'type' => 'success', 'text' => 'رمزهای نمایشی چرخیدند و نشست‌های قبلی باطل شدند؛ رمزهای تازه فقط در همین پاسخ نمایش داده می‌شوند.' );
					break;
				case ScopedRequest::ACTION_OTP:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی وجود ندارد.' );
					}
					self::$otp    = $otp_helper->issue( $loaded );
					self::$notice = array( 'type' => 'success', 'text' => 'کد OTP نمایشی فقط در همین پاسخ نشان داده میشود.' );
					break;
				case ScopedRequest::ACTION_LAUNCH:
					// The same single action the Owner dashboard offers: prove the
					// fixture from sealed evidence, finish only the additive part and
					// rotate the demo passwords. No data row is created or deleted.
					$launch            = $provisioner->launch( $admin_id );
					self::$credentials = (array) $launch['credentials'];
					self::$notice      = array(
						'type' => 'success',
						'text' => 'دمو آماده شد و رمز تازهٔ هشت نقش فقط در همین پاسخ نمایش داده می‌شود. هیچ ردیفی ساخته یا حذف نشد.',
					);
					break;
				case ScopedRequest::ACTION_FRESH_DEMO:
					// Park the old fixture (its whole sealed evidence is archived and
					// only live pointers are dropped; no row is touched) and provision
					// a fresh, isolated demo with its own namespace.
					$fresh             = $provisioner->preserve_and_reprovision( $admin_id );
					self::$credentials = (array) $fresh['credentials'];
					self::$notice      = array(
						'type' => 'success',
						'text' => 'دموی قبلی با همهٔ شواهد مهرشده‌اش بایگانی شد و هیچ ردیفی از آن حذف نشد؛ دموی تازه ساخته شد.',
					);
					break;
				case ScopedRequest::ACTION_DIAGNOSE:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی ثبتشدهای وجود ندارد.' );
					}
					self::$diagnostic = $teardown->preview( $loaded );
					self::$notice      = array( 'type' => 'info', 'text' => 'پیشنمایش فقطخواندنی اجرا شد؛ هیچ ردیفی حذف نشد.' );
					break;
				case ScopedRequest::ACTION_ADD_CLINIC:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی ثبت‌شده‌ای وجود ندارد.' );
					}
					self::$extra  = $clinics->create( $loaded, $admin_id );
					self::$notice = array(
						'type' => 'success',
						'text' => 'درمانگاه نمایشی دوم ساخته و مالکیت آن در دفتر مهرشدهٔ دمو ثبت شد (شناسه: ' . (int) self::$extra['clinic_id'] . ').',
					);
					break;
				case ScopedRequest::ACTION_RESOLVE_CLINIC:
					$loaded = $manifest->load();
					if ( null === $loaded ) {
						throw new Exception( 'NO_DEMO', 'دموی ثبت‌شده‌ای وجود ندارد.' );
					}
					$token   = isset( $post['cpdd_token'] ) ? sanitize_text_field( (string) $post['cpdd_token'] ) : '';
					$claim   = isset( $post['cpdd_decision'] ) && 'claim' === sanitize_key( (string) $post['cpdd_decision'] );
					self::$extra  = $clinics->resolve( $loaded, $token, $claim );
					self::$notice = array(
						'type' => 'success',
						'text' => 'registered' === (string) ( self::$extra['decision'] ?? '' )
							? 'درمانگاه ناتمام در دفتر مالکیت دمو ثبت شد.'
							: 'قصد ناتمام لغو شد؛ هیچ درمانگاهی ثبت نشد.',
					);
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
			self::$notice = array( 'type' => 'error', 'code' => $e->demo_code(), 'text' => $e->getMessage() . ' (کد: ' . $e->demo_code() . ')' );
		} catch ( \Throwable $e ) {
			// A raw exception message is not operator-facing Persian, and it can
			// carry a class name or a file path. The strict page already refuses
			// to echo it; this one no longer does either.
			unset( $e );
			self::$notice = array( 'type' => 'error', 'code' => 'UNEXPECTED', 'text' => 'خطای غیرمنتظره رخ داد؛ هیچ تغییری خارج از مسیر گزارش‌شده انجام نشد.' );
		}
	}

	public static function harden_headers(): void {
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
		// Read the same counter the single-Clinic gate reads, but never let a
		// counting failure turn into a page error: 0 renders no consent card at
		// all. It is read BEFORE the summary card so that card can point at the
		// confirmation instead of leaving "preflight must turn green" unexplained.
		$clinic_count = 0;
		try {
			$clinic_count = (int) $platform->count_all( 'cpms_clinics' );
		} catch ( \Throwable $e ) {
			unset( $e );
			$clinic_count = 0;
		}
		echo '<div class="wrap cpdd" dir="rtl" data-cpsd-page="1">';
		echo '<h1>دموی محدودشده CPMS — سایت آزمایشی غیرخالی</h1>';
		\CpmsDemo\Standalone::render_banner();
		self::render_notice();
		self::render_setup_summary( $manifest, $state, $loaded, $manifest_error, $clinic_count );
		self::render_identity( $env, $manifest, $manifest->mode_enabled(), $loaded, $manifest_error );
		self::render_preflight( $state );
		self::render_preserved_namespace( $env, $platform );
		self::render_private_host_consent( $env, $manifest, $request, $clinic_count );
		self::render_credentials( $loaded );
		self::render_otp();
		if ( '' !== $manifest_error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $manifest_error ) . '</p></div>';
		} elseif ( ! $manifest->mode_enabled() ) {
			self::render_form( $request, ScopedRequest::ACTION_ENABLE, 'فعالسازی حالت محدودشده', '', 'این دکمه فقط یک نشانگر HMACشده مینویسد و هیچ کاربر یا رکورد CPMS نمیسازد.' );
		} elseif ( null === $loaded ) {
			self::render_form( $request, ScopedRequest::ACTION_PROVISION, 'ساخت دموی محدودشده', '', 'سازمان/درمانگاه/شعبهها و حساب‌های کاملاً ساختگی دمو ایجاد میشوند؛ هیچ رکورد قبلی تغییر نمیکند.' );
		} else {
			$status = (string) ( $loaded['status'] ?? 'unknown' );
			self::render_ownership( $env, $manifest, $request, $loaded );
			if ( 'active' === $status ) {
				self::render_persona_access( $loaded );
				self::render_form( $request, ScopedRequest::ACTION_ROTATE, 'چرخش رمزهای نمایشی', '', 'رمزهای تازه فقط در همین پاسخ نشان داده می‌شوند و همه نشست‌های قبلی نقش‌های نمایشی باطل می‌شوند.' );
				self::render_form( $request, ScopedRequest::ACTION_OTP, 'نمایش کد OTP نمایشی', '', 'ابتدا در رزرو عمومی با شماره ساختگی بالا کد بخواهید؛ سپس همین فرم کد همان چالش زنده را نشان می‌دهد. پیامک واقعی ارسال نمی‌شود.' );
			} else {
				$recovery = ( new ScopedRecovery( $env, $platform, $manifest ) )->assess( $loaded );
				echo '<div class="notice notice-warning inline" data-cpsd-recovery="' . esc_attr( (string) $recovery['verdict'] ) . '" data-cpsd-recovery-code="' . esc_attr( (string) $recovery['code'] ) . '"><p>وضعیت دمو ناتمام است: <code dir="ltr">' . esc_html( $status ) . '</code>. ' . esc_html( (string) $recovery['reason'] ) . '</p></div>';
				if ( ScopedRecovery::VERDICT_RESUMABLE === $recovery['verdict'] ) {
					self::render_form( $request, ScopedRequest::ACTION_RESUME, 'ادامهٔ ساخت دموی ناتمام', '', 'همهٔ ردیف‌های ساختگی ساخته شده‌اند و فقط مهر نهایی ناقص است. ادامه هیچ ردیفی نمی‌سازد و هیچ ردیفی را حذف نمی‌کند؛ فقط مالکیت را مهر و دمو را فعال می‌کند.' );
				}
				if ( ScopedRecovery::VERDICT_ACTIVE !== $recovery['verdict'] ) {
					// The one obvious action, plus the park-and-reprovision path that
					// never deletes, adopts or modifies an ambiguous row.
					self::render_form( $request, ScopedRequest::ACTION_LAUNCH, 'آماده‌سازی و ورود به هشت درگاه', '', 'فعال‌سازی افزودنی است: هیچ ردیفی ساخته یا حذف نمی‌شود. رمز تازهٔ هشت نقش فقط در همین پاسخ نمایش داده می‌شود.' );
					self::render_form( $request, ScopedRequest::ACTION_FRESH_DEMO, 'نگه‌داشتن دموی قبلی و ساخت دموی تازه', '', 'دموی قبلی با سند مهرشده و دفتر مالکیتش بایگانی می‌شود و حذف نمی‌شود؛ سپس یک دموی تازه و جدا ساخته می‌شود. برای این کار هیچ SQL، حذف کاربر یا غیرفعال‌کردن افزونه‌ای لازم نیست.' );
				}
			}
			self::render_form( $request, ScopedRequest::ACTION_DIAGNOSE, 'پیشنمایش پاکسازی', '', 'هیچ ردیفی حذف نمیشود؛ مالکیت دقیق و اقلام حفظشده نشان داده میشود.' );
			self::render_diagnostic();
			self::render_form( $request, ScopedRequest::ACTION_TEARDOWN, 'پاک‌سازی فقط داده‌های دمو', 'REMOVE_ONLY_SCOPED_DEMO_DATA', 'در صورت باقی ماندن هر ردیف بالینی یا مالی دمو، موفقیت اعلام نمی‌شود. اگر پیام BASELINE_MISSING دیدید، پاک‌سازی تا بازگردانی «پایهٔ مرجع پیش از ساخت» مسدود است؛ در آن حالت از «آماده‌سازی و ورود به هشت درگاه» (استفادهٔ افزودنی، بدون تغییر هیچ ردیفی) یا «نگه‌داشتن دموی قبلی و ساخت دموی تازه» استفاده کنید.' );
		}
		self::render_teardown_result();
		self::render_preserved( $manifest );
		if ( is_array( $tombstone ) ) {
			self::render_tombstone( $tombstone );
		}
		self::render_instructions();
		self::render_limitations();
		echo '</div>';
	}

	/**
	 * One card that answers the only two questions a non-technical operator has:
	 * "is it ready?" and "what exactly is the next click?".
	 *
	 * The detailed tables stay below it, unchanged, for anyone who wants the
	 * evidence. This card never grants anything: it only reads the same state the
	 * gates read.
	 *
	 * @param ScopedManifest      $manifest Manifest store.
	 * @param array<string,mixed> $state    Preflight state.
	 * @param array<string,mixed>|null $loaded Verified manifest.
	 * @param string              $error    Manifest verification error.
	 * @param int                 $clinics  Current `COUNT(*)` on `cpms_clinics`.
	 * @return void
	 */
	private static function render_setup_summary( ScopedManifest $manifest, array $state, ?array $loaded, string $error, int $clinics = 0 ): void {
		$ready  = ! empty( $state['ok'] );
		$mode   = $manifest->mode_enabled();
		$active = is_array( $loaded ) && 'active' === (string) ( $loaded['status'] ?? '' );

		// The single-Clinic gate is the one blocker an operator cannot fix by
		// reading the table: it needs a typed acceptance of THIS domain. Naming it
		// here keeps "the preflight must turn green" from being a dead end.
		$blocking    = array();
		$blocking_id = array();
		foreach ( (array) ( $state['checks'] ?? array() ) as $check ) {
			if ( 'pass' !== (string) ( $check['status'] ?? '' ) ) {
				$blocking[]    = (string) ( $check['label'] ?? '' );
				$blocking_id[] = (string) ( $check['id'] ?? '' );
			}
		}
		$needs_private_host = 1 === $clinics && ! $active && '' === $error && null === $manifest->private_host_consent();

		if ( '' !== $error ) {
			$headline = 'وضعیت مالکیت دمو معتبر نیست و هر تغییری متوقف است.';
			$tone     = 'fail';
		} elseif ( $active ) {
			$headline = 'دمو آماده است.';
			$tone     = 'pass';
		} elseif ( ! $mode ) {
			$headline = 'یک کار باقی مانده: حالت دمو را فعال کنید.';
			$tone     = 'todo';
		} elseif ( $needs_private_host && array( 'host_scope' ) === $blocking_id ) {
			$headline = 'یک کار باقی مانده: تأیید «سایت آزمایشی خصوصی» برای همین دامنه.';
			$tone     = 'todo';
		} elseif ( ! $ready ) {
			$headline = 'یک کار باقی مانده: پیش‌بررسی باید سبز شود.';
			$tone     = 'fail';
		} else {
			$headline = 'یک کار باقی مانده: دمو را بسازید.';
			$tone     = 'todo';
		}

		echo '<div class="cpdd-card" data-cpsd-summary="' . esc_attr( $tone ) . '">';
		echo '<h2>از کجا شروع کنم؟</h2>';
		echo '<p class="cpdd-summary-headline"><strong>' . esc_html( $headline ) . '</strong></p>';
		echo '<ol class="cpdd-steps">';
		echo '<li data-cpsd-step="1" class="' . ( $mode ? 'is-done' : 'is-next' ) . '">فعال‌سازی حالت محدودشده <span class="cpdd-step-state">' . ( $mode ? 'انجام شد' : 'نوبت شما' ) . '</span></li>';
		echo '<li data-cpsd-step="2" class="' . ( $active ? 'is-done' : ( $mode && $ready ? 'is-next' : '' ) ) . '">ساخت دمو <span class="cpdd-step-state">' . ( $active ? 'انجام شد' : ( $mode && $ready ? 'نوبت شما' : 'پس از فعال‌سازی' ) ) . '</span></li>';
		echo '<li data-cpsd-step="3" class="' . ( $active ? 'is-next' : '' ) . '">ورود با نقش‌های نمایشی <span class="cpdd-step-state">' . ( $active ? 'نوبت شما' : 'پس از ساخت دمو' ) . '</span></li>';
		echo '</ol>';
		if ( $active ) {
			echo '<p><a class="button button-primary" href="#cpsd-personas">رفتن به نقش‌های نمایشی</a></p>';
		}
		if ( $needs_private_host ) {
			echo '<p data-cpsd-private-host-hint="1">این سایت تک‌درمانگاهی است. برای گام دوم یک تأیید کوتاه لازم است و انجام آن آسان است: در همین صفحه، پایین‌تر، در کادر «یک تأیید لازم است»، عبارت نام همین دامنه را بنویسید (دکمهٔ «کپی عبارت» آن را در حافظه می‌گذارد) و «تأیید سایت آزمایشی خصوصی» را بزنید. این تأیید فقط برای همین دامنه معتبر است.</p>';
		}
		if ( ! $ready && '' === $error && array() !== $blocking ) {
			echo '<p>برای ادامه فقط این موارد مانده‌اند: <strong>' . esc_html( implode( ' · ', $blocking ) ) . '</strong></p>';
		}
		echo '<p class="description">هر عملیات با یک عبارت تأیید انجام می‌شود. عبارت را لازم نیست از حفظ بنویسید: کنار هر فرم دکمهٔ «کپی عبارت» هست.</p>';
		echo '</div>';
	}

	/**
	 * The single-Clinic gate, explained in one short paragraph and resolved by an
	 * explicit typed acceptance of THIS host.
	 *
	 * The gate itself stays exactly where it was: the sealed record this form
	 * writes is read by ScopedPreflight, and without it provisioning is still
	 * refused with the same fail-closed code. The form only makes an existing,
	 * deliberate decision reachable for an operator who does not read PHP.
	 *
	 * @param Env             $env      Environment.
	 * @param ScopedManifest  $manifest Manifest store.
	 * @param ScopedRequest   $request  Request gates.
	 * @param int             $clinics  Current `COUNT(*)` on `cpms_clinics`.
	 * @return void
	 */
	private static function render_private_host_consent( Env $env, ScopedManifest $manifest, ScopedRequest $request, int $clinics ): void {
		$consent = $manifest->private_host_consent();
		if ( 1 !== $clinics ) {
			// Multi-Clinic: nothing to accept, nothing to explain.
			return;
		}
		$host = ScopedRequest::private_host_phrase( $env );
		if ( '' === $host ) {
			// Without a readable host name there is no site-bound phrase to type.
			return;
		}
		if ( is_array( $consent ) ) {
			echo '<div class="cpdd-card" data-cpsd-private-host="accepted"><h2>سایت آزمایشی خصوصی: تأییدشده</h2>';
			echo '<p>تأیید شما ثبت شده است: این سایت یک سایت آزمایشی خصوصی است و می‌پذیرید که پس از پاک‌سازی دمو، شمار درمانگاه‌های سایت یکی بیشتر از قبل بماند.</p>';
			echo '<p>دامنهٔ تأییدشده: <code dir="ltr">' . esc_html( (string) ( $consent['host'] ?? '' ) ) . '</code> — زمان ثبت: <span dir="ltr">' . esc_html( (string) ( $consent['at'] ?? '' ) ) . '</span></p>';
			echo '</div>';

			return;
		}

		echo '<div class="cpdd-card cpdd-attention" data-cpsd-private-host="pending"><h2>یک تأیید لازم است: این سایت تک‌درمانگاهی است</h2>';
		echo '<p>ساخت دمو یک «درمانگاه لنگر» ساختگی اضافه می‌کند. پس از پاک‌سازی، این ردیف بایگانی می‌شود و حذف نمی‌شود (چون ردیف‌های حسابرسی حذف نمی‌شوند)، پس شمار درمانگاه‌های سایت یکی بیشتر از قبل می‌ماند.</p>';
		echo '<p>در یک سایت با یک درمانگاه، همین یک عدد تعیین می‌کند که سیستم «تک‌درمانگاهی» کار کند یا از کاربر بخواهد درمانگاه را انتخاب کند. پس این تغییر برای همیشه در رفتار سایت می‌ماند.</p>';
		echo '<p>اگر این سایت یک <strong>سایت آزمایشی خصوصی</strong> است و این تغییر را می‌پذیرید، عبارت زیر را عیناً بنویسید. عبارت، نام دامنهٔ همین سایت است؛ با آن، تأیید شما به همین نصب گره می‌خورد.</p>';
		self::render_form( $request, ScopedRequest::ACTION_PRIVATE_HOST, 'تأیید سایت آزمایشی خصوصی', $host, 'این تأیید برای همان دامنه ثبت و مهر می‌شود؛ روی سایت دیگری قابل استفاده نیست.' );
		echo '</div>';
	}

	private static function render_notice(): void {
		if ( ! is_array( self::$notice ) ) {
			return;
		}
		$type  = (string) ( self::$notice['type'] ?? 'info' );
		$class = 'error' === $type ? 'notice-error' : ( 'warning' === $type ? 'notice-warning' : ( 'success' === $type ? 'notice-success' : 'notice-info' ) );
		$code  = (string) ( self::$notice['code'] ?? '' );

		// Same rule as the strict admin page: the outcome notice is addressable
		// and a refusal names its stable code, so a drill (or an operator's
		// support report) can identify it without matching Persian prose.
		echo '<div class="notice ' . esc_attr( $class ) . '" data-cpdd-notice="' . esc_attr( $type ) . '"'
			. ( '' === $code ? '' : ' data-cpdd-code="' . esc_attr( $code ) . '"' )
			. '><p>' . esc_html( (string) ( self::$notice['text'] ?? '' ) ) . '</p></div>';
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

	/**
	 * What pre-existing `cpsd-` rows mean, and what this page will (not) do
	 * about them.
	 *
	 * A populated test site usually already has synthetic leftovers from an
	 * earlier run whose sealed evidence is gone. They are shown here, in the
	 * operator's language, with the two facts that decide the safe answer:
	 * nothing in this package proves they are ours, and no row is ever deleted
	 * unless a sealed record proves it. The names are shown so a human can judge;
	 * the package itself never promotes a name to ownership.
	 *
	 * @param Env      $env      Environment.
	 * @param Platform $platform CPMS adapter.
	 * @return void
	 */
	private static function render_preserved_namespace( Env $env, Platform $platform ): void {
		$survey = FixtureNamespace::survey( $env, $platform );
		if ( (int) $survey['total'] <= 0 ) {
			return;
		}
		echo '<div class="cpdd-card cpdd-attention" data-cpsd-preserved-namespace="' . esc_attr( (string) $survey['total'] ) . '"><h2>بقایای قبلی با پیشوند cpsd-: گزارش، بدون حذف</h2>';
		echo '<p>روی این سایت ' . esc_html( FixtureNamespace::counts_fa( $survey ) ) . ' از قبل وجود دارد. این موارد ساختهٔ <strong>همین</strong> دمو نیستند: هیچ سند مهرشده‌ای آن‌ها را به این اجرا نسبت نمی‌دهد. بنابراین:</p><ul>';
		echo '<li>حذف نمی‌شوند، تغییر نمی‌کنند و رمزشان دست‌کاری نمی‌شود؛</li>';
		echo '<li>به نقش‌های این دمو متصل یا وارد نمی‌شوند و دسترسی تازه‌ای نمی‌گیرند؛</li>';
		echo '<li>دموی جدید نام‌های خودش را با شناسهٔ تصادفی تازه می‌سازد و پاک‌سازی هم فقط ردیف‌های مهرشدهٔ خودش را حذف می‌کند.</li>';
		echo '</ul><p>دلیل سخت‌گیری: نام کاربری و شکل نام، <em>مالکیت</em> را اثبات نمی‌کند؛ اگر این بسته بر پایهٔ نام حذف می‌کرد، ممکن بود حساب واقعی سایت شما را پاک کند. اگر این موارد را می‌شناسید و می‌خواهید پاک شوند، این کار را با پشتیبان کامل و به‌صورت دستی و آگاهانه در پیشخوان وردپرس انجام دهید؛ این صفحه هیچ دکمه‌ای برای آن ندارد.</p>';
		if ( array() !== (array) $survey['users'] ) {
			echo '<table class="widefat striped"><thead><tr><th>نام کاربری</th><th>نقش‌ها</th><th>دامنهٔ ایمیل</th><th>تاریخ ثبت (UTC)</th><th>الگوی این بسته</th></tr></thead><tbody>';
			foreach ( (array) $survey['users'] as $user ) {
				echo '<tr data-cpsd-preserved-user="' . esc_attr( (string) $user['login'] ) . '">';
				echo '<td dir="ltr">' . esc_html( (string) $user['login'] ) . '</td>';
				echo '<td dir="ltr">' . esc_html( implode( ', ', (array) $user['roles'] ) ) . '</td>';
				echo '<td dir="ltr">' . esc_html( (string) $user['email_domain'] ) . '</td>';
				echo '<td dir="ltr">' . esc_html( (string) $user['registered'] ) . '</td>';
				echo '<td>' . ( $user['signature'] ? 'نام و ایمیل با الگوی حساب‌های ساختگی هم‌خوان است' : 'هم‌خوان نیست' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">ستون آخر فقط یک نشانهٔ ظاهری است، نه اثبات مالکیت؛ حتی برای ردیف‌های «هم‌خوان» هم هیچ عملی خودکار انجام نمی‌شود.</p>';
		}
		echo '</div>';
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

	/**
	 * Render one authorized action form.
	 *
	 * The typed phrase is rendered ONLY when the action requires one. Since an
	 * empty phrase means "no phrase" (see Request::phrase_required()), showing the
	 * field for an action that does not enforce it would be a lie in the UI, and
	 * hiding it for an action that does enforce it would be a broken form.
	 *
	 * @param ScopedRequest       $request Request gates.
	 * @param string              $action  Action key.
	 * @param string              $title   Button/heading label.
	 * @param string              $phrase  Confirmation phrase ('' when none).
	 * @param string              $hint    Plain-Persian explanation.
	 * @param array<string,mixed> $fields  Extra hidden fields.
	 * @return void
	 */
	private static function render_form( ScopedRequest $request, string $action, string $title, string $phrase, string $hint, array $fields = array() ): void {
		echo '<form method="post" class="cpdd-card" data-cpsd-form="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="cpdd_nonce" value="' . esc_attr( $request->nonce( $action ) ) . '" />';
		echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $hint ) . '</p>';
		if ( $request->phrase_required( $action ) ) {
			$field_id = 'cpdd-phrase-' . preg_replace( '/[^a-z0-9_]/', '', $action );
			echo '<p><label for="' . esc_attr( $field_id ) . '">عبارت تأیید: <code dir="ltr">' . esc_html( $phrase ) . '</code></label></p>';
			echo '<p><input type="text" id="' . esc_attr( $field_id ) . '" name="cpdd_confirm" class="regular-text" dir="ltr" autocomplete="off" required />'
				. ' <button type="button" class="button" data-cpdd-copy="' . esc_attr( $field_id ) . '" data-cpdd-copy-value="' . esc_attr( $phrase ) . '">کپی عبارت</button></p>';
		} else {
			echo '<p class="description" data-cpsd-no-phrase="1">این دکمه بدون عبارت تأیید کار می‌کند: درخواست با مجوز مدیرکل، nonce مخصوص همین عملیات و شناسهٔ همین سایت بررسی می‌شود.</p>';
		}
		echo '<p><button type="submit" class="button ' . ( ScopedRequest::ACTION_TEARDOWN === $action ? 'button-link-delete' : 'button-primary' ) . '">' . esc_html( $title ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Persian, server-enforced explanation of the scoped-mode Clinic policy, the
	 * sealed ownership state, and the explicit registration path.
	 *
	 * The restriction is enforced twice on the server: the synthetic Organization
	 * manager carries an explicit DENY for `cpms_org_clinic_create` (so the
	 * production resolver refuses, and the portal does not even render the
	 * create form), and any portal POST of `org_create_clinic` is refused before
	 * dispatch while scoped mode is enabled. This card explains that, and offers
	 * the only path that produces a provably demo-owned additional Clinic.
	 *
	 * @param Env                 $env      Environment.
	 * @param ScopedManifest      $manifest Manifest.
	 * @param ScopedRequest       $request  Request gates.
	 * @param array<string,mixed> $loaded   Verified manifest.
	 * @return void
	 */
	private static function render_ownership( Env $env, ScopedManifest $manifest, ScopedRequest $request, array $loaded ): void {
		$summary = ( new ScopedOwnership( $env, $manifest ) )->summary( (string) ( $loaded['fixture_id'] ?? '' ) );
		$status  = (string) ( $loaded['status'] ?? 'unknown' );
		echo '<div class="cpdd-card" data-cpsd-ownership="1"><h2>مالکیت درمانگاه‌ها در حالت دموی محدودشده</h2>';
		if ( ! $summary['ok'] ) {
			echo '<p class="cpdd-warn">دفتر مالکیت دمو معتبر نیست؛ پاک‌سازی تا تعیین تکلیف متوقف می‌ماند. ' . esc_html( (string) $summary['detail'] ) . '</p>';
		}
		echo '<p>ساخت درمانگاه تازه از «درگاه مدیریت» در این حالت <strong>مسدود است</strong> و این محدودیت در سرور اعمال می‌شود: '
			. 'مجوز <code dir="ltr">cpms_org_clinic_create</code> برای مدیر سازمان نمایشی به‌صورت DENY ثبت شده و هر درخواست ساخت از درگاه '
			. 'پیش از اجرا رد می‌شود. دلیل: درمانگاهی که پس از مهر پایهٔ مرجع زیر سازمان دمو ساخته شود از یک درمانگاه واقعی قابل تشخیص نیست '
			. 'و پاک‌سازی خودکار ممکن است دادهٔ واقعی را حذف کند. برای همین، هر درمانگاه اضافی فقط از همین صفحه و با ثبت مالکیت در '
			. 'دفتر مهرشدهٔ دمو ساخته می‌شود.</p>';
		echo '<p>درمانگاه‌های ثبت‌شده در دفتر مالکیت: <code dir="ltr">'
			. esc_html( implode( ', ', array_map( 'strval', (array) $summary['clinic_ids'] ) ) )
			. '</code></p>';
		if ( array() !== (array) $summary['pending'] ) {
			echo '<p class="cpdd-warn">یک ثبت ناتمام از اجرای قبلی باقی مانده است. تا تعیین تکلیف آن، پاک‌سازی مخرب اجرا نمی‌شود.</p>';
			foreach ( (array) $summary['pending'] as $intent ) {
				$token = (string) ( $intent['token'] ?? '' );
				$slug  = (string) ( $intent['slug'] ?? '' );
				echo '<p>قصد ناتمام — شناسهٔ یکتا: <code dir="ltr">' . esc_html( $slug ) . '</code></p>';
				self::render_form( $request, ScopedRequest::ACTION_RESOLVE_CLINIC, 'ثبت مالکیت درمانگاه متناظر', '', 'فقط ردیفی ثبت می‌شود که دقیقاً با شناسهٔ یکتای مهرشده و سازمان دمو هم‌خوان باشد.', array( 'cpdd_token' => $token, 'cpdd_decision' => 'claim' ) );
				self::render_form( $request, ScopedRequest::ACTION_RESOLVE_CLINIC, 'لغو قصد ناتمام', '', 'فقط وقتی مجاز است که هیچ ردیفی با شناسهٔ یکتای مهرشده وجود نداشته باشد.', array( 'cpdd_token' => $token, 'cpdd_decision' => 'abandon' ) );
			}
		} elseif ( 'active' === $status ) {
			self::render_form( $request, ScopedRequest::ACTION_ADD_CLINIC, 'ساخت درمانگاه نمایشی دوم', '', 'مالکیت این درمانگاه پیش از ساخت در دفتر مهرشده ثبت می‌شود؛ ساخت آن با سرویس واقعی production انجام می‌گیرد.' );
		}
		if ( is_array( self::$extra ) ) {
			echo '<p data-cpsd-extra="1">درمانگاه نمایشی: <code dir="ltr">' . esc_html( (string) ( self::$extra['slug'] ?? '' ) ) . '</code> — شناسه: <code dir="ltr">' . esc_html( (string) ( self::$extra['clinic_id'] ?? 0 ) ) . '</code></p>';
		}
		echo '</div>';
	}

	/**
	 * Refuse portal Clinic creation for the SYNTHETIC DEMO MANAGER only.
	 *
	 * Scope of the refusal, all three conditions required:
	 *
	 *   1. scoped demo mode is enabled AND a verified manifest for this site is
	 *      active (an interrupted/aborted fixture does not restrict anything);
	 *   2. the request names the demo Organization;
	 *   3. the actor is the synthetic Organization manager of that fixture.
	 *
	 * Everything else - most importantly a legitimate Organization manager of a
	 * FOREIGN Organization creating a Clinic while a demo is active - is left
	 * completely alone. The refusal is server-side (it happens before the portal
	 * dispatcher runs) and the message is Persian.
	 *
	 * @return void
	 */
	public static function guard_portal_clinic_creation(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		// A refusal is not a state change: it writes nothing, so a nonce cannot make
		// it legitimate, and demanding one would let a crafted POST slip past this
		// boundary into the production controller. The values are only compared
		// against the sealed fixture identity below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only refusal guard; nothing is written or changed.
		$posted_action     = isset( $_POST['cpms_action'] ) ? (string) wp_unslash( $_POST['cpms_action'] ) : '';
		$posted_org        = isset( $_POST['organization_id'] ) ? (int) wp_unslash( $_POST['organization_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( $posted_action );
		if ( 'org_create_clinic' !== $action ) {
			return;
		}
		$manifest = new ScopedManifest( new Env() );
		if ( ! $manifest->mode_enabled() ) {
			return;
		}
		try {
			$loaded = $manifest->load();
		} catch ( \Throwable $error ) {
			// A manifest that does not verify identifies no fixture, so no actor
			// can be shown to be the demo's synthetic manager. Refusing here
			// would either hit the wrong person or (worse) fatal on a form POST
			// that has nothing to do with the demo; standing down keeps
			// production behaviour intact. Nothing becomes silently deletable:
			// the teardown refuses to run at all while the manifest is
			// unverifiable, so a Clinic created in that window stays unproven
			// and needs an explicit human decision.
			return;
		}
		if ( ! is_array( $loaded ) || 'active' !== (string) ( $loaded['status'] ?? '' ) ) {
			// No live fixture: this request cannot belong to a demo session, so a
			// leftover mode marker must not restrict anybody.
			return;
		}
		$demo_org = (int) ( $loaded['ids']['organization_id'] ?? 0 );
		$manager  = (int) ( $loaded['ids']['user_ids']['organization_manager'] ?? 0 );
		if ( $demo_org <= 1 || $manager <= 1 ) {
			return;
		}
		if ( get_current_user_id() !== $manager ) {
			// A real Organization manager (of any Organization, including one that
			// happens to post into the demo Organization) is NOT the demo persona.
			return;
		}
		if ( $posted_org > 0 && $posted_org !== $demo_org ) {
			return;
		}
		wp_die(
			esc_html(
				'در حالت «دموی محدودشده» حساب نمایشی مدیر سازمان نمی‌تواند از درگاه مدیریت درمانگاه تازه بسازد و این درخواست در سرور رد شد. '
				. 'دلیل: درمانگاهی که پس از مهر پایهٔ مرجع زیر سازمان دمو ساخته شود از یک درمانگاه واقعی قابل تشخیص نیست و '
				. 'پاک‌سازی خودکار ممکن است دادهٔ واقعی را حذف کند. اگر برای آزمون به درمانگاه دوم نیاز دارید، آن را از صفحهٔ '
				. '«دموی محدودشده CPMS» در پیشخوان بسازید؛ مالکیت آن پیش از ساخت در دفتر مهرشدهٔ دمو ثبت می‌شود.'
			),
			esc_html( 'ساخت درمانگاه برای حساب نمایشی مسدود است' ),
			array( 'response' => 403 )
		);
	}

	public static function render_credentials( ?array $loaded ): void {
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

	/**
	 * Which portal does each persona actually use?
	 *
	 * The key is the manifest link the persona is entitled to open; the label says
	 * what to do there. Nothing here grants access - each portal enforces its own
	 * authorization - and no password is repeated here.
	 *
	 * @return array<string,array{link:string,portal:string,hint:string}>
	 */
	public static function persona_portals(): array {
		return array(
			'organization_manager' => array(
				'link'   => 'management',
				'portal' => 'درگاه مدیریت — سطح سازمان',
				'hint'   => 'چرخهٔ عمر درمانگاه‌ها و تعیین مدیر درمانگاه',
			),
			'clinic_manager' => array(
				'link'   => 'management',
				'portal' => 'درگاه مدیریت — سطح درمانگاه',
				'hint'   => 'شعبه، پزشک، برنامهٔ هفتگی، سیاست رزرو و آمادگی درمانگاه',
			),
			'doctor' => array(
				'link'   => 'doctor',
				'portal' => 'درگاه کارکنان — ماژول پزشک',
				'hint'   => 'ویزیت، شرح حال، نسخه، پیام خصوصی و تعرفهٔ ویزیت',
			),
			'doctor_second' => array(
				'link'   => 'doctor',
				'portal' => 'درگاه کارکنان — ماژول پزشک (پزشک دوم)',
				'hint'   => 'برای دیدن جدایی گفتگوی خصوصی و تعرفهٔ هر پزشک',
			),
			'receptionist' => array(
				'link'   => 'receptionist',
				'portal' => 'درگاه کارکنان — ماژول پذیرش',
				'hint'   => 'پذیرش بیمار، نوبت‌دهی، صف ورود و دریافت پرداخت',
			),
			'receptionist_second' => array(
				'link'   => 'receptionist',
				'portal' => 'درگاه کارکنان — ماژول پذیرش (پذیرش دوم)',
				'hint'   => 'همکار مجاز ولی غیرعضو گفتگوی خصوصی',
			),
			'accountant' => array(
				'link'   => 'accountant',
				'portal' => 'درگاه کارکنان — ماژول مالی',
				'hint'   => 'صورتحساب، پرداخت جزئی و کامل، اقلام درمان و نهایی‌سازی',
			),
			'patient' => array(
				'link'   => 'patient',
				'portal' => 'درگاه بیمار',
				'hint'   => 'ورود با OTP پیامکی و رزرو نوبت عمومی',
			),
		);
	}

	/**
	 * One row per synthetic persona: who it is, where it goes, and how to get in.
	 *
	 * This is the Product Owner's map of the demo. It deliberately shows NO
	 * password: passwords exist only in the one-time card rendered by the
	 * provisioning request (`render_credentials()`), which is the demo's existing
	 * secure mechanism. There is no impersonation control, no shared token and no
	 * parallel login - every row links to the real portal, and the production
	 * authorization decides what that account may do.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return void
	 */
	/**
	 * The Owner's primary surface: eight big buttons, one per persona.
	 *
	 * WHAT EACH BUTTON DOES
	 * ---------------------
	 * It starts a real session hand-off: a POST (nonce + site fingerprint +
	 * `manage_options` + `install_plugins` + HTTPS) that answers with a redirect to a
	 * single-use, five-minute ticket. The ticket opens THAT persona's own WordPress
	 * session - its own capabilities, its own production portal - and nothing else.
	 * The administrator's account is never given the persona's authority and the
	 * persona is never given the administrator's.
	 *
	 * WHAT IT HONESTLY CANNOT DO
	 * --------------------------
	 * WordPress authentication is one cookie per site. Two tabs of the same browser
	 * share it, so a persona session opened in the same browser replaces the
	 * administrator session there. The page therefore says so, and points at the one
	 * arrangement that really does keep both: a private window (its own cookie jar)
	 * for the persona. No tab-level isolation is claimed anywhere, because none is
	 * implemented.
	 *
	 * @param ScopedRequest       $request Request gates (nonces, phrase policy).
	 * @param array<string,mixed> $manifest Verified sealed manifest.
	 * @param array<string,mixed> $matrix   Read-only authority matrix (ScopedPreview).
	 * @return void
	 */
	public static function render_persona_launcher( ScopedRequest $request, array $manifest, array $matrix ): void {
		$links    = (array) ( $manifest['links'] ?? array() );
		$fixture  = (string) ( $manifest['fixture_id'] ?? '' );
		$portals  = self::persona_portals();
		$personas = Credentials::personas();
		$allowed  = array();
		foreach ( (array) ( $matrix['personas'] ?? array() ) as $row ) {
			$allowed[ (string) $row['persona'] ] = (array) $row['authority'];
		}

		echo '<div class="cpdd-card" id="cpsd-persona-launcher" data-cpsd-persona-launcher="1">';
		echo '<h2>هشت درگاه واقعی — با یک کلیک، بدون رمز</h2>';
		echo '<p>هر دکمه یک نشست واقعی برای همان نقش نمایشی می‌سازد (همان حساب ساختگی مهرشده در سند دمو) و درگاه واقعی محصول را برایتان باز می‌کند. شما با حساب مدیرکل خود وارد شده‌اید و رمزی وارد نمی‌کنید.</p>';
		echo '<p class="cpdd-warn" data-cpsd-cookie-note="1">کوکی ورود وردپرس بین پنجره‌های <em>یک</em> مرورگر مشترک است؛ پس برای اینکه نشست مدیرکل شما هم باز بماند، پنجرهٔ تازه را در حالت <strong>ناشناس/پروفایل دیگر</strong> باز کنید. اگر همان مرورگر را ادامه دهید، نشست مدیرکل در آن مرورگر جای خود را به نشست نقش می‌دهد و با دکمهٔ «پایان نشست نمایشی» به صفحهٔ ورود برمی‌گردید. هیچ‌جا ادعای جداسازی در سطح «زبانه» نکرده‌ایم؛ جداسازی واقعی از مرورگر می‌آید، نه از این افزونه.</p>';
		echo '<div class="cpdd-persona-grid" data-cpsd-persona-grid="1">';
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$map     = (array) ( $portals[ $persona ] ?? array() );
			$url      = (string) ( $links[ (string) ( $map['link'] ?? '' ) ] ?? '' );
			$label    = (string) ( $personas[ $persona ]['label'] ?? $persona );
			$chips    = array();
			foreach ( ScopedPreview::DOMAINS as $domain => $spec ) {
				$chips[] = '<span data-cpsd-chip="' . esc_attr( $domain ) . '" data-cpsd-allow="'
					. ( ! empty( $allowed[ $persona ][ $domain ] ) ? '1' : '0' ) . '">'
					. esc_html( $spec['label'] ) . ': ' . ( ! empty( $allowed[ $persona ][ $domain ] ) ? 'دارد' : 'ندارد' )
					. '</span>';
			}
			echo '<div class="cpdd-persona-tile" data-cpsd-persona-tile="' . esc_attr( $persona ) . '">';
			echo '<h3>' . esc_html( $label ) . '</h3>';
			echo '<p>' . esc_html( (string) ( $map['portal'] ?? '' ) ) . '<br /><span class="description">' . esc_html( (string) ( $map['hint'] ?? '' ) ) . '</span></p>';
			echo '<p class="cpdd-authority-chips">' . implode( ' ', $chips ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each chip is escaped above.
			echo '<form method="post" target="_blank" rel="noopener" data-cpsd-form="' . esc_attr( ScopedRequest::ACTION_PERSONA_ENTRY ) . '" data-cpsd-persona-form="' . esc_attr( $persona ) . '">';
			echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( ScopedRequest::ACTION_PERSONA_ENTRY ) . '" />';
			echo '<input type="hidden" name="cpdd_nonce" value="' . esc_attr( $request->nonce( ScopedRequest::ACTION_PERSONA_ENTRY ) ) . '" />';
			echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
			echo '<input type="hidden" name="cpsd_persona" value="' . esc_attr( $persona ) . '" />';
			echo '<button type="submit" class="button button-primary button-hero" data-cpsd-open-persona="' . esc_attr( $persona ) . '">ورود به درگاه ' . esc_html( $label ) . '</button>';
			echo '</form>';
			if ( '' !== $url ) {
				echo '<p class="description"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" data-cpsd-readonly-preview="' . esc_attr( $persona ) . '">پیش‌نمایش فقط‌خواندنی همین درگاه با حساب مدیرکل</a> — این پیوند نشست را عوض نمی‌کند و چون حساب شما اختیار بالینی/مالی ندارد، محتوای محدودشده را نشان می‌دهد. برای کارکرد واقعی نقش، از دکمهٔ بالا استفاده کنید.</p>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '<p class="description">شناسهٔ دمو: <code dir="ltr">' . esc_html( $fixture ) . '</code> — نام کاربری هر نقش <code dir="ltr">cpsd-&lt;role&gt;-' . esc_html( $fixture ) . '</code> است. رمزها ذخیره نمی‌شوند؛ اگر خواستید بدون این دکمه‌ها وارد شوید، از «چرخش رمز» رمز تازه بگیرید.</p>';
		echo '</div>';

		ScopedPreview::render( $matrix );
	}

	public static function render_persona_access( array $manifest ): void {
		$links    = (array) ( $manifest['links'] ?? array() );
		$fixture  = (string) ( $manifest['fixture_id'] ?? '' );
		$portals  = self::persona_portals();
		$personas = Credentials::personas();
		echo '<div class="cpdd-card" id="cpsd-personas" data-cpsd-persona-access="1"><h2>دسترسی به نقش‌های نمایشی</h2>';
		echo '<p>برای هر نقش یک حساب ساختگی ساخته شده است. با همان حساب در درگاه خودش وارد شوید تا همان چیزی را ببینید که یک کاربر واقعی با آن نقش می‌بیند. این صفحه هیچ سازوکار «ورود به‌جای دیگری» ندارد و دسترسی هر نقش را خود درگاه تولیدی تعیین می‌کند.</p>';
		echo '<table class="widefat striped cpdd-personas"><thead><tr><th>نقش</th><th>درگاه</th><th>برای چه کاری</th><th>ورود</th></tr></thead><tbody>';
		foreach ( Credentials::PERSONA_ORDER as $key ) {
			$persona = (array) ( $personas[ $key ] ?? array() );
			$map     = (array) ( $portals[ $key ] ?? array() );
			$url     = (string) ( $links[ $map['link'] ?? '' ] ?? '' );
			echo '<tr data-cpsd-persona-portal="' . esc_attr( $key ) . '" data-cpsd-portal-link="' . esc_attr( (string) ( $map['link'] ?? '' ) ) . '">';
			echo '<td>' . esc_html( (string) ( $persona['label'] ?? $key ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $map['portal'] ?? '-' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $map['hint'] ?? '' ) ) . '</td>';
			if ( '' === $url ) {
				echo '<td>-</td>';
			} else {
				echo '<td><a class="button button-secondary" dir="ltr" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'بازکردن درگاه', 'cpms' ) . '</a></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p>نام کاربری هر نقش به شکل <code dir="ltr">cpsd-&lt;role&gt;-' . esc_html( $fixture ) . '</code> است. رمز هر نقش فقط یک‌بار و در کارت «رمزهای یک‌بارمصرف» همان درخواستی که دمو را می‌سازد نشان داده می‌شود و ذخیره نمی‌شود؛ همان لحظه آن را در مدیر رمز نگه دارید. اگر رمزها را از دست دادید، از فرم «چرخش رمزهای نمایشی» در همین صفحه رمز تازه بگیرید؛ چرخش همه نشست‌های قبلی آن نقش‌ها را باطل می‌کند و نیازی به پاک‌سازی داده ندارد.</p>';
		$mobile = (string) ( $manifest['patient_mobile'] ?? '' );
		echo '<div class="cpdd-card" data-cpsd-owner-guide="1"><h2>راهنمای مالک محصول: رزرو عمومی و OTP بدون دسترسی به دیتابیس</h2>';
		echo '<ol>'
			. '<li>پیوند «رزرو عمومی» را باز کنید، پزشک و یکی از نوبت‌های ساعت ۰۹ یا ۱۴ را انتخاب کنید و ادامه دهید.</li>'
			. '<li>در فرم ورود، فقط شماره ساختگی <code dir="ltr" data-cpsd-patient-mobile="1">' . esc_html( $mobile ) . '</code> را وارد کنید و «دریافت کد» را بزنید.</li>'
			. '<li>پیامک واقعی ارسال نمی‌شود: ارائه‌دهنده این درمانگاه ساختگی <code dir="ltr">log</code> است. به همین صفحه مدیریت برگردید و فرم «نمایش کد OTP نمایشی» را اجرا کنید تا کد همان چالش زنده نشان داده شود.</li>'
			. '<li>کد را در فرم رزرو وارد و تأیید کنید، سپس نام/نام‌خانوادگی ساختگی را کامل و رزرو را نهایی کنید. کد یک‌بارمصرف است و محدودیت‌های درخواست/تلاش همان مسیر تولیدی باقی می‌مانند.</li>'
			. '</ol>';
		echo '<p class="cpdd-warn">شماره دیگری وارد نکنید. اگر اشتباهی شماره دیگری درخواست شد، پیش از تأیید کد با «تغییر شماره» به شماره ساختگی بالا برگردید. اگر با شماره دیگری رزرو را نهایی کردید، ردیف‌های عمومی ناشناس ممکن است مالکیت دقیق نداشته باشند؛ پاک‌سازی خودکار آن‌ها را حذف نمی‌کند و پیام TEARDOWN_BLOCKED را نشان می‌دهد. هیچ دکمه‌ای برای «ادعای مالکیت» یا حذف دستی داده ناشناس وجود ندارد؛ از پشتیبان قابل بازیابی نگه‌داری کنید و موضوع را برای بررسی انسانی ثبت کنید. تاریخچه حسابرسی هرگز حذف یا دست‌کاری نمی‌شود.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( (string) ( $links['booking'] ?? '' ) ) . '" target="_blank" rel="noopener">بازکردن رزرو عمومی</a></p></div>';
		$extra = array();
		foreach ( array( 'messages' => 'پیام‌های خصوصی کارکنان', 'pricing' => 'تعرفه‌ها', 'reports' => 'گزارش‌ها' ) as $key => $label ) {
			if ( ! empty( $links[ $key ] ) ) {
				$extra[] = '<a href="' . esc_url( (string) $links[ $key ] ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
			}
		}
		if ( array() !== $extra ) {
			echo '<p>میان‌برها: ' . wp_kses_post( implode( ' · ', $extra ) ) . '</p>';
		}
		echo '<p>Shortcode رزرو: <code dir="ltr">' . esc_html( (string) ( $manifest['shortcode'] ?? '' ) ) . '</code></p></div>';
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

	/**
	 * Read-only list of PARKED fixtures.
	 *
	 * A parked fixture is deliberately not actionable: it has no live manifest
	 * and no live ledger, and its rows may only be resolved by a human holding
	 * the whole sealed payload. The card exists so the operator can see that
	 * nothing was deleted and that the evidence survives, and so a later review
	 * knows exactly which fixture to look at.
	 *
	 * @param ScopedManifest $manifest Manifest store.
	 * @return void
	 */
	private static function render_preserved( ScopedManifest $manifest ): void {
		try {
			$records = $manifest->preserved();
		} catch ( Exception $error ) {
			echo '<div class="cpdd-card cpdd-attention" data-cpsd-preserved="invalid"><h2>بایگانی دموهای کنارگذاشته‌شده</h2>';
			echo '<p class="cpdd-fail">' . esc_html( $error->getMessage() ) . ' (' . esc_html( $error->demo_code() ) . ')</p>';
			echo '<p>هیچ ردیفی حذف یا تغییر نکرد. تا بررسی انسانی، دموی تازه‌ای بر پایهٔ این بایگانی ساخته نمی‌شود.</p></div>';

			return;
		}
		if ( array() === $records ) {
			return;
		}
		echo '<div class="cpdd-card" data-cpsd-preserved="' . esc_attr( (string) count( $records ) ) . '"><h2>بایگانی دموهای کنارگذاشته‌شده (بدون حذف)</h2>';
		echo '<p>این دموها عمداً کنار گذاشته شده‌اند و هیچ ردیفی از آن‌ها حذف، تغییر یا به دموی تازه نسبت داده نشده است. سند مهرشده و دفتر مالکیت هرکدام در همین بایگانی نگه داشته می‌شود تا بعداً به‌صورت انسانی بررسی شود.</p>';
		echo '<table class="widefat striped"><thead><tr><th>شناسهٔ دمو</th><th>دلیل</th><th>زمان</th><th>پایهٔ مرجع در سند</th></tr></thead><tbody>';
		foreach ( $records as $record ) {
			$parked = (array) ( $record['manifest'] ?? array() );
			echo '<tr data-cpsd-preserved-fixture="' . esc_attr( (string) ( $record['fixture_id'] ?? '' ) ) . '">';
			echo '<td dir="ltr">' . esc_html( (string) ( $record['fixture_id'] ?? '' ) ) . '</td>';
			echo '<td dir="ltr">' . esc_html( (string) ( $record['reason_code'] ?? '' ) ) . '</td>';
			echo '<td dir="ltr">' . esc_html( (string) ( $record['preserved_at'] ?? '' ) ) . '</td>';
			echo '<td>' . ( array() !== (array) ( $parked['baseline'] ?? array() ) ? 'دارد' : 'ندارد' ) . '</td>';
			echo '</tr>';
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
		echo '<div class="cpdd-card" data-cpsd-instructions="1"><h2>سه گام، به زبان ساده</h2><ol>';
			echo '<li><strong>حالت دمو را فعال کنید.</strong> دکمهٔ «فعال‌سازی حالت محدودشده» را بزنید. این کار هیچ کاربر و هیچ رکورد درمانگاهی نمی‌سازد.</li>';
			echo '<li><strong>دمو را بسازید.</strong> وقتی همهٔ ردیف‌های پیش‌بررسی سبز شدند، دکمهٔ «ساخت دموی محدودشده» را بزنید. رمز هشت نقش فقط یک‌بار و در همان پاسخ نشان داده می‌شود؛ همان لحظه آن را در مدیر رمز نگه دارید. اگر از دست رفت، «چرخش رمزهای نمایشی» رمز تازه می‌دهد.</li>';
		echo '<li><strong>با نقش‌ها کار کنید.</strong> از کارت «دسترسی به نقش‌های نمایشی» هر نقش را در یک پنجرهٔ خصوصی جدا باز کنید و جریان‌های نوبت، پذیرش، بالینی و مالی را ببینید. برای رزرو عمومی و کد نمایشی، راهنمای همان کارت را دنبال کنید.</li>';
		echo '</ol>';
		echo '<p>در پایان، اول «پیش‌نمایش پاک‌سازی» را ببینید و بعد «پاک‌سازی فقط داده‌های دمو» را اجرا کنید. دادهٔ قبلی سایت و تاریخچهٔ حسابرسی حفظ می‌شوند.</p></div>';
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

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
		\CpmsDemo\Standalone::render_banner();
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
			self::render_form( $request, ScopedRequest::ACTION_PROVISION, 'ساخت دموی محدودشده', 'CREATE_SCOPED_DEMO_ON_TEST_SITE', 'سازمان/درمانگاه/شعبهها و حساب‌های کاملاً ساختگی دمو ایجاد میشوند؛ هیچ رکورد قبلی تغییر نمیکند.' );
		} else {
			$status = (string) ( $loaded['status'] ?? 'unknown' );
			self::render_ownership( $env, $manifest, $request, $loaded );
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

	private static function render_form( ScopedRequest $request, string $action, string $title, string $phrase, string $hint, array $fields = array() ): void {
		echo '<form method="post" class="cpdd-card" data-cpsd-form="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="cpdd_nonce" value="' . esc_attr( $request->nonce( $action ) ) . '" />';
		echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $hint ) . '</p>';
		echo '<p><label>عبارت تأیید: <code dir="ltr">' . esc_html( $phrase ) . '</code></label></p>';
		echo '<p><input type="text" name="cpdd_confirm" class="regular-text" dir="ltr" autocomplete="off" required /></p>';
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
				self::render_form( $request, ScopedRequest::ACTION_RESOLVE_CLINIC, 'ثبت مالکیت درمانگاه متناظر', 'RESOLVE_INTERRUPTED_DEMO_CLINIC', 'فقط ردیفی ثبت می‌شود که دقیقاً با شناسهٔ یکتای مهرشده و سازمان دمو هم‌خوان باشد.', array( 'cpdd_token' => $token, 'cpdd_decision' => 'claim' ) );
				self::render_form( $request, ScopedRequest::ACTION_RESOLVE_CLINIC, 'لغو قصد ناتمام', 'RESOLVE_INTERRUPTED_DEMO_CLINIC', 'فقط وقتی مجاز است که هیچ ردیفی با شناسهٔ یکتای مهرشده وجود نداشته باشد.', array( 'cpdd_token' => $token, 'cpdd_decision' => 'abandon' ) );
			}
		} elseif ( 'active' === $status ) {
			self::render_form( $request, ScopedRequest::ACTION_ADD_CLINIC, 'ساخت درمانگاه نمایشی دوم', 'REGISTER_ADDITIONAL_DEMO_CLINIC', 'مالکیت این درمانگاه پیش از ساخت در دفتر مهرشده ثبت می‌شود؛ ساخت آن با سرویس واقعی production انجام می‌گیرد.' );
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
		echo '<li>پیشبررسی را سبز کنید و سپس دمو را بسازید؛ رمزهای همهٔ شخصیت‌ها را همان لحظه ذخیره کنید.</li>';
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

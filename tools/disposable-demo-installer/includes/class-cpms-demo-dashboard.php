<?php
/**
 * The Owner-facing dashboard: one Persian page, one primary action.
 *
 * WHY THIS PAGE EXISTS
 * --------------------
 * The scoped console (Tools → دموی محدودشده CPMS) is an evidence console: it
 * renders the preflight, ownership, ledger, Clinic policy, OTP helper and the
 * teardown preview. Every one of those facts is necessary, and together they are
 * the wrong first screen for a Product Owner whose question is only "can I look
 * at the eight portals now?".
 *
 * This page answers exactly that, in the operator's language, from live state:
 *
 *   - a verified, sealed, active fixture  -> «ورود به دموی آماده» + the eight
 *     real portal links + a one-click credential rotation;
 *   - an interrupted run that ScopedRecovery proves is confined to the seal
 *     -> «ادامهٔ ساخت دمو», additively;
 *   - an interrupted run that is NOT provably safe to finish -> plain Persian,
 *     and a pointer to the authorized cleanup, never an automatic adoption;
 *   - no fixture at all -> «آماده‌سازی دمو», one click.
 *
 * SECURITY CONTRACT (unchanged, re-used - not re-implemented)
 *   - Every request goes through ScopedRequest::authorize(): POST, an
 *     authenticated administrator with manage_options AND install_plugins, an
 *     action-specific nonce, this site's identity fingerprint, and HTTPS (or
 *     loopback). The typed phrase is not required for setup actions because it
 *     was never an authorization control; it is still required for the
 *     destructive teardown, which stays on the console.
 *   - Nothing is ever adopted because a name, an e-mail domain or a date looks
 *     familiar. Ownership comes only from the HMAC-sealed manifest, the sealed
 *     baseline and the sealed ownership ledger.
 *   - The page renders no credential except in the response of the request that
 *     created it, with no-store headers.
 *   - It adds no REST route, AJAX action, frontend surface, shortcode or cron.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these blocks.

defined( 'ABSPATH' ) || exit;

/**
 * One-click demo dashboard.
 */
class Dashboard {

	public const PAGE = 'cpms-demo';
	public const CAP  = 'manage_options';
	public const ACTION = 'cpsd_action';

	/** @var list<string> Hook suffixes on which a POST may arrive. */
	private static array $hooks = array();

	/** @var array{type:string,text:string,code?:string}|null */
	private static $notice = null;

	/** @var array<string,string>|null One-time passwords of THIS response only. */
	private static $credentials = null;

	/** @var array<string,mixed>|null Result of a resume, for the notice. */
	private static $recovered = null;

	/** @var bool */
	private static bool $dispatched = false;

	/**
	 * Optional test environment override.
	 *
	 * Mirrors Admin::set_test_services(): the page always builds its own services
	 * in production, and the unit harness needs to render it against in-memory
	 * doubles without a WordPress installation.
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

	public static function reset_state(): void {
		self::$notice      = null;
		self::$credentials = null;
		self::$recovered   = null;
		self::$dispatched  = false;
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
	 * Absolute admin URL of this page, for the request it is rendered in.
	 *
	 * The page is a submenu of the CPMS unified menu when that menu exists and a
	 * Tools page otherwise, so its own address differs by site. Resolving it here
	 * (instead of hard-coding `admin.php?page=…` at every call site) is what stops
	 * the activation landing from redirecting to a URL WordPress does not serve.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		$base = null !== Admin::cpms_parent_slug() ? 'admin.php?page=' : 'tools.php?page=';

		return admin_url( $base . self::PAGE );
	}

	/**
	 * Register the demo dashboard inside the EXISTING wp-admin menu architecture.
	 *
	 * CPMS deliberately owns exactly ONE top-level menu («مدیریت مطب», registered
	 * by the production `CpmsAdminMenu`), and every CPMS page hangs beneath it. A
	 * companion plugin must not add a second CPMS-branded top-level menu: that
	 * would split the product's navigation, duplicate an identity the production
	 * plugin owns, and bypass the coexistence contracts that know only the
	 * production menu slugs.
	 *
	 * So the dashboard follows the same rule the strict console already follows:
	 * a submenu of the CPMS parent when that menu is present, and a Tools page when
	 * it is not (a site whose CPMS core is unreadable still needs a page that says
	 * so). The dashboard's discoverability does not depend on either: activation
	 * lands here and the plugin row links here, and both use `page_url()`.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		self::$hooks   = array();
		$parent        = Admin::cpms_parent_slug();
		$page_title    = 'دموی CPMS Classic V1';
		$menu_title    = 'دموی CPMS';

		if ( null !== $parent ) {
			$hook = add_submenu_page(
				$parent,
				$page_title,
				$menu_title,
				self::CAP,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
		} else {
			// No CPMS menu in this request: stay reachable without inventing a
			// second top-level menu that would outlive the absent product menu.
			$hook = add_management_page(
				$page_title,
				$menu_title,
				self::CAP,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
		}

		if ( is_string( $hook ) ) {
			self::$hooks[] = $hook;
			add_action( 'load-' . $hook, array( __CLASS__, 'dispatch' ) );
		}
	}

	/**
	 * @param string $hook_suffix Hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::$hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'cpsd-admin', CPMS_DEMO_URL . 'assets/admin.css', array(), CPMS_DEMO_VERSION );
	}

	/**
	 * Handle one POST action. Never partially acts: a refusal changes nothing.
	 *
	 * @return void
	 */
	public static function dispatch(): void {
		if ( self::$dispatched ) {
			return;
		}
		self::$dispatched = true;
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ScopedRequest::authorize() verifies the action nonce below.
		$action = isset( $_POST[ self::ACTION ] ) ? sanitize_key( (string) wp_unslash( $_POST[ self::ACTION ] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		$allowed = array(
			ScopedRequest::ACTION_PREPARE,
			ScopedRequest::ACTION_RESUME,
			ScopedRequest::ACTION_ROTATE,
			ScopedRequest::ACTION_PRIVATE_HOST,
		);
		if ( ! in_array( $action, $allowed, true ) ) {
			return;
		}
		ScopedAdmin::harden_headers();
		// Same accessors as render(): the page and its dispatch can never disagree
		// about which services they are looking at.
		$env         = self::env();
		$platform    = self::platform();
		$manifest    = new ScopedManifest( $env );
		$audit_guard = new ScopedAuditGuard( $platform );
		$baseline    = new ScopedBaseline( $env, $platform );
		$preflight   = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
		$request     = new ScopedRequest( $env, $manifest );
		$credentials = new Credentials( $env );
		$provisioner = new ScopedProvisioner( $env, $platform, $manifest, $preflight, $credentials, $baseline, $audit_guard );
		$post        = (array) wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		try {
			$request->authorize( $action, $post );
			$admin_id = $env->current_user_id();
			switch ( $action ) {
				case ScopedRequest::ACTION_PRIVATE_HOST:
					// Same sealed, site- and host-bound record the console writes.
					$manifest->enable_private_host( $admin_id, ScopedRequest::private_host_phrase( $env ) );
					self::$notice = array(
						'type' => 'success',
						'text' => 'تأیید شما ثبت شد. حالا دکمهٔ «آماده‌سازی دمو» را بزنید.',
					);
					break;
				case ScopedRequest::ACTION_PREPARE:
					$result            = self::prepare( $env, $platform, $manifest, $preflight, $provisioner, $admin_id );
					self::$credentials = (array) $result['credentials'];
					self::$notice      = array(
						'type' => 'success',
						'text' => 'دمو ساخته شد. رمز هشت نقش فقط در همین پاسخ نمایش داده می‌شود؛ همان لحظه آن را در مدیر رمز نگه دارید.',
					);
					break;
				case ScopedRequest::ACTION_RESUME:
					$result          = $provisioner->finish( $admin_id );
					self::$recovered = (array) $result['manifest'];
					self::$notice    = array(
						'type' => 'success',
						'text' => 'دموی ناتمام با همان شواهد مهرشده کامل شد. اگر رمز نقش‌ها را ندارید، «دریافت رمز تازه» را بزنید.',
					);
					break;
				case ScopedRequest::ACTION_ROTATE:
					$loaded = $manifest->load();
					if ( null === $loaded || 'active' !== (string) ( $loaded['status'] ?? '' ) ) {
						throw new Exception( 'NO_DEMO', 'دموی فعالی برای چرخش رمز وجود ندارد.' );
					}
					self::$credentials = $credentials->rotate( $loaded, 'cpsd-' );
					self::$notice      = array(
						'type' => 'success',
						'text' => 'رمزهای تازه ساخته شدند و نشست‌های قبلی نقش‌های نمایشی باطل شدند. رمزها فقط در همین پاسخ نمایش داده می‌شوند.',
					);
					break;
			}
		} catch ( Exception $error ) {
			self::$notice = array(
				'type' => 'error',
				'code' => $error->demo_code(),
				'text' => $error->getMessage() . ' (کد: ' . $error->demo_code() . ')',
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			self::$notice = array(
				'type' => 'error',
				'code' => 'UNEXPECTED',
				'text' => 'خطای غیرمنتظره رخ داد؛ هیچ تغییری خارج از مسیر گزارش‌شده انجام نشد.',
			);
		}
	}

	/**
	 * The one-click setup: enable the mode, prove the preflight, provision.
	 *
	 * This is exactly the two documented steps, run in one request, with no
	 * phrase to retype. It adds no authority: `enable_mode()` writes a sealed
	 * marker only, and `provision()` still runs the full scoped preflight inside
	 * its own lock and refuses on any non-pass check.
	 *
	 * @param Env               $env         Environment.
	 * @param Platform          $platform    CPMS adapter.
	 * @param ScopedManifest    $manifest    Manifest store.
	 * @param ScopedPreflight   $preflight   Preflight.
	 * @param ScopedProvisioner $provisioner Provisioner.
	 * @param int               $admin_id    Acting administrator.
	 * @return array{manifest:array<string,mixed>,credentials:array<string,string>}
	 * @throws Exception On any refusal.
	 */
	private static function prepare( Env $env, Platform $platform, ScopedManifest $manifest, ScopedPreflight $preflight, ScopedProvisioner $provisioner, int $admin_id ): array {
		if ( $manifest->exists() ) {
			throw new Exception( 'ALREADY_PROVISIONED', 'یک دمو از قبل ثبت شده است. از همین صفحه آن را باز کنید یا برای ساخت دموی تازه ابتدا با «پاک‌سازی» آن را بردارید.' );
		}
		if ( ! $manifest->mode_enabled() ) {
			$manifest->enable_mode( $admin_id );
		}
		$state = $preflight->run();
		if ( empty( $state['ok'] ) ) {
			$labels = array();
			$ids    = array();
			foreach ( (array) ( $state['checks'] ?? array() ) as $check ) {
				if ( 'pass' !== (string) ( $check['status'] ?? '' ) ) {
					$labels[] = (string) ( $check['label'] ?? '' );
					$ids[]    = (string) ( $check['id'] ?? '' );
				}
			}
			$clinics = 0;
			try {
				$clinics = (int) $platform->count_all( 'cpms_clinics' );
			} catch ( \Throwable $error ) {
				unset( $error );
			}
			// The single-Clinic consequence is the one decision that needs the
			// administrator's own words, and it stays a typed, site-bound record.
			if ( array( 'host_scope' ) === $ids && 1 === $clinics && null === $manifest->private_host_consent() ) {
				throw new Exception( 'PRIVATE_HOST_REQUIRED', 'این سایت تک‌درمانگاهی است و ساخت دمو یک درمانگاه لنگر اضافه می‌کند. برای ادامه، تأیید «سایت آزمایشی خصوصی» در همین صفحه لازم است.' );
			}
			throw new Exception( 'PREFLIGHT', 'پیش‌بررسی اجازهٔ ساخت دمو نمی‌دهد: ' . implode( ' · ', array_slice( $labels, 0, 4 ) ) );
		}

		return $provisioner->provision( $admin_id );
	}

	/**
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی لازم را ندارید.', 'cpms' ) );
		}
		$env         = self::env();
		$platform    = self::platform();
		$manifest    = new ScopedManifest( $env );
		$audit_guard = new ScopedAuditGuard( $platform );
		$baseline    = new ScopedBaseline( $env, $platform );
		$preflight   = new ScopedPreflight( $env, $platform, $manifest, $audit_guard, $baseline );
		$request     = new ScopedRequest( $env, $manifest );

		$loaded         = null;
		$manifest_error = '';
		try {
			$loaded = $manifest->load();
		} catch ( Exception $error ) {
			$manifest_error = $error->getMessage() . ' (' . $error->demo_code() . ')';
		}

		$state = null;
		try {
			$state = $preflight->run();
		} catch ( \Throwable $error ) {
			unset( $error );
			$state = null;
		}
		$clinics = 0;
		try {
			$clinics = (int) $platform->count_all( 'cpms_clinics' );
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		echo '<div class="wrap cpdd" dir="rtl" data-cpms-demo-page="1">';
		echo '<h1>دموی CPMS Classic V1</h1>';
		\CpmsDemo\Standalone::render_banner();
		self::render_notice();
		self::render_credentials( $loaded );

		if ( '' !== $manifest_error ) {
			self::render_manifest_error( $manifest_error );
		} elseif ( is_array( $loaded ) ) {
			$assessment = ( new ScopedRecovery( $env, $platform, $manifest ) )->assess( $loaded );
			if ( ScopedRecovery::VERDICT_ACTIVE === $assessment['verdict'] ) {
				self::render_ready( $request, $loaded, $assessment );
			} elseif ( ScopedRecovery::VERDICT_RESUMABLE === $assessment['verdict'] ) {
				self::render_resumable( $request, $loaded, $assessment );
			} else {
				self::render_incomplete( $loaded, $assessment );
			}
		} elseif ( ! $platform->available() ) {
			self::render_core_unavailable();
		} elseif ( self::site_is_provably_empty() ) {
			self::render_empty_site();
		} else {
			self::render_prepare( $env, $manifest, $request, $state, $clinics );
		}

		self::render_footer();
		echo '</div>';
	}

	/**
	 * Is this installation provably empty (the strict disposable case)?
	 *
	 * Reuses the strict preflight through Admin::setup_target() so the dashboard and
	 * the two consoles can never disagree about which page fits this site.
	 *
	 * @return bool
	 */
	private static function site_is_provably_empty(): bool {
		try {
			$target = Admin::setup_target();

			return ! (bool) ( $target['occupancy']['occupied'] ?? false );
		} catch ( \Throwable $error ) {
			// An unreadable state is "cannot prove empty", which is the non-empty
			// path - the same fail-closed direction Admin::setup_target() takes.
			unset( $error );
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $assessment Recovery assessment.
	 * @return void
	 */
	private static function render_ready( ScopedRequest $request, array $manifest, array $assessment ): void {
		$fixture = (string) ( $manifest['fixture_id'] ?? '' );
		$status  = (string) ( $manifest['status'] ?? '' );
		echo '<div class="cpdd-card cpdd-hero" data-cpms-demo-state="ready">';
		echo '<h2>دموی CPMS آماده است</h2>';
		echo '<p class="cpdd-hero-lead">هشت حساب ساختگی و همهٔ درگاه‌های واقعی محصول ساخته شده‌اند. برای بازدید، پایین همین صفحه پیوند ورود هر نقش آمده است.</p>';
		echo '<p><a class="button button-primary button-hero" href="#cpms-demo-portals" data-cpms-demo-enter="1">ورود به دموی آماده</a></p>';
		echo '<p class="description">شناسهٔ دمو: <code dir="ltr">' . esc_html( $fixture ) . '</code> — وضعیت: <code dir="ltr">' . esc_html( $status ) . '</code> — مالکیت با سند مهرشده و دفتر مالکیت تأیید شد.</p>';
		echo '</div>';

		// Passwords are never stored, so "the demo is ready" must always be paired
		// with a way to obtain a fresh set. Rotation invalidates only the demo
		// personas' sessions and never deletes data.
		self::render_form(
			$request,
			ScopedRequest::ACTION_ROTATE,
			'دریافت رمز تازه برای هشت نقش',
			'اگر رمزهای یک‌بارمصرف را ندارید، این دکمه رمز تازه می‌سازد. رمزها فقط در همین پاسخ نمایش داده می‌شوند و نشست‌های قبلی همین نقش‌های نمایشی باطل می‌شوند؛ هیچ داده‌ای پاک نمی‌شود.'
		);

		echo '<div id="cpms-demo-portals" data-cpms-demo-portals="1">';
		ScopedAdmin::render_persona_access( $manifest );
		echo '</div>';
		// Cleanup is deliberately NOT one click away from the ready hero: it is
		// irreversible, so it lives on the console that asks for its typed phrase.
		// The Owner still needs a way there, or the demo could never be removed -
		// `render_evidence()` ends with exactly that link.
		self::render_evidence( $assessment );
	}

	/**
	 * @param array<string,mixed> $manifest   Verified manifest.
	 * @param array<string,mixed> $assessment Recovery assessment.
	 * @return void
	 */
	private static function render_resumable( ScopedRequest $request, array $manifest, array $assessment ): void {
		unset( $manifest );
		echo '<div class="cpdd-card cpdd-hero cpdd-attention" data-cpms-demo-state="resumable">';
		echo '<h2>دموی نیمه‌ساخته پیدا شد و ادامهٔ آن ایمن است</h2>';
		echo '<p class="cpdd-hero-lead">' . esc_html( (string) $assessment['reason'] ) . '</p>';
		echo '<p>ادامه هیچ ردیف تازه‌ای نمی‌سازد و هیچ ردیفی را حذف نمی‌کند: فقط مالکیت را مهر و دمو را فعال می‌کند. اگر رمز نقش‌ها را ندارید، بعد از ادامه «دریافت رمز تازه» را بزنید.</p>';
		self::render_form(
			$request,
			ScopedRequest::ACTION_RESUME,
			'ادامهٔ ساخت دمو',
			'همهٔ ردیف‌های ساختگی ساخته شده‌اند و فقط مهر نهایی ناقص است. این دکمه همان مهر را با شواهد مهرشده کامل می‌کند.'
		);
		echo '</div>';
		self::render_evidence( $assessment );
	}

	/**
	 * @param array<string,mixed> $manifest   Verified manifest.
	 * @param array<string,mixed> $assessment Recovery assessment.
	 * @return void
	 */
	private static function render_incomplete( array $manifest, array $assessment ): void {
		echo '<div class="cpdd-card cpdd-hero cpdd-attention" data-cpms-demo-state="blocked" data-cpms-demo-code="' . esc_attr( (string) $assessment['code'] ) . '">';
		echo '<h2>دموی ناتمام پیدا شد، اما ادامهٔ خودکار آن امن نیست</h2>';
		echo '<p class="cpdd-hero-lead">' . esc_html( (string) $assessment['reason'] ) . '</p>';
		echo '<p>دلیل سخت‌گیری: ادامهٔ خودکار فقط وقتی مجاز است که همهٔ ردیف‌های ساختگی پیش از مهر ساخته شده باشند. در غیر این صورت ادامه می‌تواند ردیف تکراری بسازد یا ردیفی را به دمو نسبت دهد که مالکیتش اثبات نشده است.</p>';
		echo '<p>مسیر درست، پاک‌سازی مجاز همان دموی ناتمام و سپس ساخت دوباره از همین صفحه است. پاک‌سازی فقط ردیف‌های اثبات‌شدهٔ همین دمو را برمی‌دارد؛ کاربران، نوشته‌ها، دیدگاه‌ها و داده‌های بالینی/مالی قبلی سایت دست‌نخورده می‌مانند و تاریخچهٔ حسابرسی هرگز حذف نمی‌شود.</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=' . ScopedAdmin::PAGE ) ) . '" data-cpms-demo-console-hero="1">رفتن به صفحهٔ پاک‌سازی و ابزارهای پیشرفته</a></p>';
		echo '<p class="description">شناسهٔ دمو: <code dir="ltr">' . esc_html( (string) ( $manifest['fixture_id'] ?? '' ) ) . '</code></p>';
		echo '</div>';
		self::render_evidence( $assessment );
	}

	/**
	 * @param string $error Manifest verification error.
	 * @return void
	 */
	private static function render_manifest_error( string $error ): void {
		echo '<div class="cpdd-card cpdd-hero" data-cpms-demo-state="manifest-invalid">';
		echo '<h2>سند مالکیت دمو معتبر نیست</h2>';
		echo '<p class="cpdd-hero-lead">سند مهرشدهٔ دمو با این سایت یا با محتوای خودش هم‌خوان نیست. تا تعیین تکلیف آن، هیچ عملیاتی انجام نمی‌شود: نه ساخت، نه ادامه و نه پاک‌سازی.</p>';
		echo '<p>هر تغییری در این سند، آن را از کار می‌اندازد. این رفتار عمدی است: دمو فقط با شواهد مهرشده قابل مدیریت است و هیچ ردیفی بر پایهٔ نام یا حدس به دمو نسبت داده نمی‌شود.</p>';
		echo '<p class="description" dir="ltr">' . esc_html( $error ) . '</p>';
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_core_unavailable(): void {
		echo '<div class="cpdd-card cpdd-hero" data-cpms-demo-state="core-unavailable">';
		echo '<h2>هستهٔ CPMS Classic در دسترس نیست</h2>';
		echo '<p class="cpdd-hero-lead">این بسته دمو می‌تواند هستهٔ محصول را همراه خود داشته باشد (بستهٔ مستقل) یا از افزونهٔ نصب‌شدهٔ CPMS استفاده کند (بستهٔ همراه). در حال حاضر هیچ هسته‌ای فعال نیست.</p>';
		echo '<p>اگر بستهٔ مستقل را نصب کرده‌اید، افزونه را فعال کنید. اگر بستهٔ همراه است، ابتدا افزونهٔ CPMS Classic را نصب و فعال کنید.</p>';
		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function render_empty_site(): void {
		echo '<div class="cpdd-card cpdd-hero" data-cpms-demo-state="empty-site">';
		echo '<h2>این سایت خالی است</h2>';
		echo '<p class="cpdd-hero-lead">دموی یک‌بارمصرف برای یک وردپرس تازه است و این سایت خالی به‌نظر می‌رسد. صفحهٔ مخصوص همان حالت را باز کنید تا دمو را با پشتیبان‌گیری کامل بسازید.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE ) ) . '" data-cpms-demo-strict="1">رفتن به دموی یک‌بارمصرف</a></p>';
		echo '</div>';
	}

	/**
	 * The one primary action for a site that has no demo yet.
	 *
	 * @param Env                 $env      Environment.
	 * @param ScopedManifest      $manifest Manifest store.
	 * @param ScopedRequest       $request  Request gates.
	 * @param array<string,mixed>|null $state Preflight state (null when unreadable).
	 * @param int                 $clinics  Current `COUNT(*)` on `cpms_clinics`.
	 * @return void
	 */
	private static function render_prepare( Env $env, ScopedManifest $manifest, ScopedRequest $request, ?array $state, int $clinics ): void {
		$ok = is_array( $state ) && ! empty( $state['ok'] );

		$blocking    = array();
		$blocking_id = array();
		foreach ( (array) ( is_array( $state ) ? ( $state['checks'] ?? array() ) : array() ) as $check ) {
			if ( 'pass' !== (string) ( $check['status'] ?? '' ) ) {
				$blocking[]    = (string) ( $check['label'] ?? '' );
				$blocking_id[] = (string) ( $check['id'] ?? '' );
			}
		}

		$needs_consent = 1 === $clinics
			&& null === $manifest->private_host_consent()
			&& in_array( 'host_scope', $blocking_id, true );

		if ( $needs_consent ) {
			$host = ScopedRequest::private_host_phrase( $env );
			echo '<div class="cpdd-card cpdd-hero cpdd-attention" data-cpms-demo-state="needs-consent">';
			echo '<h2>یک تأیید کوتاه لازم است</h2>';
			echo '<p class="cpdd-hero-lead">این سایت دقیقاً یک درمانگاه دارد. ساخت دمو یک «درمانگاه لنگر» ساختگی اضافه می‌کند که پس از پاک‌سازی هم بایگانی می‌ماند (ردیف‌های حسابرسی حذف نمی‌شوند)، پس شمار درمانگاه‌های سایت برای همیشه یکی بیشتر می‌ماند و رفتار تک‌درمانگاهی سایت تغییر می‌کند.</p>';
			echo '<p>اگر این سایت فقط یک سایت آزمایشی خصوصی برای ارزیابی است و این تغییر را می‌پذیرید، عبارت زیر — که نام دامنهٔ همین سایت است — را بنویسید. تأیید به همین دامنه مهر می‌شود و روی سایت دیگری کار نمی‌کند.</p>';
			if ( '' === $host ) {
				echo '<p class="cpdd-warn">نام دامنهٔ این سایت خوانده نشد؛ این تأیید ممکن نیست.</p>';
			} else {
				self::render_form( $request, ScopedRequest::ACTION_PRIVATE_HOST, 'تأیید سایت آزمایشی خصوصی', $host, 'پس از این تأیید، دکمهٔ «آماده‌سازی دمو» ظاهر می‌شود.' );
			}
			echo '</div>';
		} elseif ( ! $ok && array() !== $blocking ) {
			echo '<div class="cpdd-card cpdd-hero cpdd-attention" data-cpms-demo-state="blocked" data-cpms-demo-code="PREFLIGHT">';
			echo '<h2>آماده‌سازی فعلاً ممکن نیست</h2>';
			echo '<p class="cpdd-hero-lead">پیش‌بررسی امنیتی اجازهٔ ساخت دمو نمی‌دهد. هیچ کاری انجام نشد و هیچ داده‌ای تغییر نکرد.</p>';
			echo '<p>موارد بازمانده: <strong>' . esc_html( implode( ' · ', array_slice( $blocking, 0, 6 ) ) ) . '</strong></p>';
			echo '<p>جزئیات هر مورد، با دلیل و راه رفع، در همین صفحه پایین‌تر و در صفحهٔ پیشرفته آمده است.</p>';
			echo '</div>';
		} else {
			echo '<div class="cpdd-card cpdd-hero" data-cpms-demo-state="prepare">';
			echo '<h2>آماده‌سازی دمو</h2>';
			echo '<p class="cpdd-hero-lead">یک کلیک: حالت دمو فعال می‌شود، پیش‌بررسی اجرا می‌شود و یک سازمان، یک درمانگاه، دو شعبه و هشت حساب کاملاً ساختگی ساخته می‌شود.</p>';
			echo '<p>کاربران، نوشته‌ها، دیدگاه‌ها، افزونه‌ها و داده‌های بالینی/مالی فعلی این سایت دست‌نخورده می‌مانند. پیامک واقعی ارسال نمی‌شود و پرداخت فقط آفلاین/غیرواقعی است.</p>';
			self::render_form( $request, ScopedRequest::ACTION_PREPARE, 'آماده‌سازی دمو', 'ساخت دمو یک عملیات افزودنی است؛ هیچ رکورد قبلی تغییر نمی‌کند.' );
			echo '<p class="description">رمز هشت نقش فقط یک‌بار و در همان پاسخ ساخته می‌شود. اگر بعداً لازم شد، «دریافت رمز تازه» در همین صفحه رمز تازه می‌سازد.</p>';
			echo '</div>';
		}

		if ( is_array( $state ) ) {
			self::render_preflight_summary( $state );
		}
	}

	/**
	 * A short, honest list of what the preflight actually checked.
	 *
	 * @param array<string,mixed> $state Preflight state.
	 * @return void
	 */
	private static function render_preflight_summary( array $state ): void {
		$ok = ! empty( $state['ok'] );
		echo '<div class="cpdd-card" data-cpms-demo-preflight="' . ( $ok ? 'pass' : 'fail' ) . '"><h2>پیش‌بررسی همین لحظه</h2>';
		echo '<p>وضعیت کلی: ' . ( $ok ? '<strong class="cpdd-pass">PASS</strong>' : '<strong class="cpdd-fail">FAIL</strong>' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( (array) ( $state['checks'] ?? array() ) as $check ) {
			$pass = 'pass' === (string) ( $check['status'] ?? '' );
			echo '<tr><td>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</td><td class="' . ( $pass ? 'cpdd-pass' : 'cpdd-fail' ) . '">' . ( $pass ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( (string) ( $check['detail'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string,mixed> $assessment Recovery assessment.
	 * @return void
	 */
	private static function render_evidence( array $assessment ): void {
		echo '<details class="cpdd-card" data-cpms-demo-evidence="' . esc_attr( (string) $assessment['verdict'] ) . '"><summary>شواهد مالکیت و کامل‌بودن دمو (برای بررسی فنی)</summary>';
		echo '<table class="widefat striped"><thead><tr><th>بررسی</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
		foreach ( (array) ( $assessment['checks'] ?? array() ) as $check ) {
			$pass = 'pass' === (string) ( $check['status'] ?? '' );
			echo '<tr><td>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</td><td class="' . ( $pass ? 'cpdd-pass' : 'cpdd-fail' ) . '">' . ( $pass ? 'PASS' : 'FAIL' ) . '</td><td>' . esc_html( (string) ( $check['detail'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>مالکیت هرگز از نام کاربری، دامنهٔ ایمیل یا تاریخ ثبت نتیجه نمی‌شود؛ فقط سند مهرشده، پایهٔ مرجع مهرشده و دفتر مالکیت مهرشده.</p>';
		echo '</details>';
	}

	/**
	 * @return void
	 */
	private static function render_advanced_link(): void {
		echo '<p class="cpdd-advanced"><a href="' . esc_url( admin_url( 'tools.php?page=' . ScopedAdmin::PAGE ) ) . '" data-cpms-demo-console="1">ابزارهای پیشرفته: کد نمایشی OTP، مالکیت درمانگاه‌ها، پیش‌نمایش و پاک‌سازی مجاز</a></p>';
	}

	/**
	 * @return void
	 */
	private static function render_footer(): void {
		// Rendered in every state, so no state is a dead end: an operator who
		// lands on "resumable" or "manifest-invalid" can still reach the authorized
		// console (OTP preview, ownership, teardown) without guessing a URL.
		self::render_advanced_link();
		echo '<div class="cpdd-card"><h2>در این دمو چه چیزی واقعی است؟</h2><ul>';
		echo '<li>هشت درگاه، همان درگاه‌های واقعی محصول هستند و دسترسی هر نقش را سرور تعیین می‌کند. هیچ «ورود به‌جای دیگری» و هیچ دور زدن احراز هویتی وجود ندارد.</li>';
		echo '<li>همهٔ داده‌های دمو ساختگی و با شناسهٔ تصادفی مهر شده‌اند؛ داده‌های قبلی سایت و زنجیرهٔ حسابرسی حفظ می‌شود.</li>';
		echo '<li>پیامک واقعی ارسال نمی‌شود (ارائه‌دهندهٔ درمانگاه ساختگی <code dir="ltr">log</code> است) و پرداخت فقط آفلاین/غیرواقعی است.</li>';
		echo '<li>این دمو برای ارزیابی خصوصی مالک محصول است، نه برای سایت درمانی واقعی و نه برای استقرار تولیدی.</li>';
		echo '</ul></div>';
	}

	/**
	 * One authorized action form.
	 *
	 * The typed phrase is rendered only when the action enforces one, so a form
	 * can never ask for something the server ignores (or hide something it
	 * requires).
	 *
	 * @param ScopedRequest $request Request gates.
	 * @param string        $action  Action key.
	 * @param string        $title   Button/heading label.
	 * @param string        $hint    Plain-Persian explanation.
	 * @return void
	 */
	private static function render_form( ScopedRequest $request, string $action, string $title, string $hint ): void {
		$phrase = $request->phrase( $action );
		echo '<form method="post" class="cpdd-card cpdd-action" data-cpsd-form="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="cpdd_nonce" value="' . esc_attr( $request->nonce( $action ) ) . '" />';
		echo '<input type="hidden" name="cpdd_site" value="' . esc_attr( $request->site_fingerprint_field() ) . '" />';
		echo '<h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $hint ) . '</p>';
		if ( $request->phrase_required( $action ) ) {
			$field_id = 'cpms-demo-phrase-' . preg_replace( '/[^a-z0-9_]/', '', $action );
			echo '<p><label for="' . esc_attr( $field_id ) . '">عبارت تأیید: <code dir="ltr">' . esc_html( $phrase ) . '</code></label></p>';
			echo '<p><input type="text" id="' . esc_attr( $field_id ) . '" name="cpdd_confirm" class="regular-text" dir="ltr" autocomplete="off" required /></p>';
		}
		echo '<p><button type="submit" class="button button-primary button-hero">' . esc_html( $title ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array<string,mixed>|null $loaded Verified manifest.
	 * @return void
	 */
	private static function render_credentials( ?array $loaded ): void {
		if ( ! is_array( self::$credentials ) ) {
			return;
		}
		$fixture = is_array( $loaded ) ? (string) ( $loaded['fixture_id'] ?? '' ) : '';
		echo '<div class="cpdd-card cpdd-secret" data-cpms-demo-secret="credentials"><h2>رمزهای یک‌بارمصرف هشت نقش</h2>';
		echo '<p class="cpdd-warn">این رمزها ذخیره نمی‌شوند و بعداً قابل بازیابی نیستند. همین حالا در مدیر رمز امن کپی کنید.</p>';
		echo '<table class="widefat striped"><thead><tr><th>نقش</th><th>نام کاربری</th><th>رمز</th></tr></thead><tbody>';
		foreach ( Credentials::personas() as $key => $persona ) {
			$login = '' === $fixture ? '' : 'cpsd-' . $key . '-' . $fixture;
			echo '<tr data-cpsd-persona="' . esc_attr( $key ) . '"><td>' . esc_html( (string) $persona['label'] ) . '</td><td dir="ltr">' . esc_html( $login ) . '</td><td><textarea readonly rows="1" dir="ltr" class="cpdd-pass-field">' . esc_textarea( (string) ( self::$credentials[ $key ] ?? '' ) ) . '</textarea></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @return void
	 */
	private static function render_notice(): void {
		if ( ! is_array( self::$notice ) ) {
			return;
		}
		$type  = (string) ( self::$notice['type'] ?? 'info' );
		$class = 'error' === $type ? 'notice-error' : ( 'success' === $type ? 'notice-success' : 'notice-info' );
		$code  = (string) ( self::$notice['code'] ?? '' );
		echo '<div class="notice ' . esc_attr( $class ) . '" data-cpdd-notice="' . esc_attr( $type ) . '"'
			. ( '' === $code ? '' : ' data-cpdd-code="' . esc_attr( $code ) . '"' )
			. '><p>' . esc_html( (string) ( self::$notice['text'] ?? '' ) ) . '</p></div>';
	}
}

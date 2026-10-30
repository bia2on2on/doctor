<?php
/**
 * Fail-closed compatibility and safety preflight for the separate scoped mode.
 *
 * This is not a bypass of the strict disposable preflight. It is a different
 * contract that explicitly allows existing users/content/CPMS data, while
 * proving compatibility, an intact global audit chain, a free queue-runner lock,
 * a reported (never silently adopted) fixture namespace, and a readable baseline
 * snapshot.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Scoped-mode preflight.
 */
class ScopedPreflight extends Preflight {

	/** @var ScopedAuditGuard */
	private ScopedAuditGuard $audit_guard;

	/** @var ScopedBaseline */
	private ScopedBaseline $baseline;

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedAuditGuard $audit_guard, ScopedBaseline $baseline ) {
		parent::__construct( $env, $platform, $manifest );
		$this->audit_guard = $audit_guard;
		$this->baseline    = $baseline;
	}

	/**
	 * @return array{ok:bool,checks:list<array{id:string,label:string,status:string,detail:string}>,blocking:list<string>}
	 */
	public function run(): array {
		$checks = array(
			$this->check_site_type(),
			$this->check_transport(),
			$this->check_capabilities(),
			$this->check_runtime(),
			$this->check_object_cache(),
			$this->check_cpms(),
			$this->check_schema_tables(),
			$this->check_unknown_tables(),
			$this->check_host_scope(),
			$this->check_mode(),
			$this->check_other_manifest(),
			$this->check_fixture_namespace(),
			$this->check_queue_lock(),
			$this->check_audit_chain(),
			$this->check_baseline(),
		);
		$blocking = array();
		foreach ( $checks as $check ) {
			if ( 'pass' !== $check['status'] ) {
				$blocking[] = $check['id'];
			}
		}

		return array(
			'ok'       => array() === $blocking,
			'checks'   => $checks,
			'blocking' => $blocking,
		);
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_site_type(): array {
		return $this->env->is_multisite()
			? $this->result( 'single_site', 'نصب تکسایتی', 'fail', 'حالت محدودشده فقط روی نصب تکسایتی مجاز است.' )
			: $this->result( 'single_site', 'نصب تکسایتی', 'pass', 'نصب تکسایتی تأیید شد.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_transport(): array {
		$host  = (string) wp_parse_url( $this->env->home_url(), PHP_URL_HOST );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( $this->env->is_ssl() || $local ) {
			return $this->result( 'https', 'اتصال امن', 'pass', 'HTTPS یا loopback ایزوله تأیید شد.' );
		}

		return $this->result( 'https', 'اتصال امن', 'fail', 'نمایش رمزهای یکبارمصرف فقط روی HTTPS مجاز است.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_capabilities(): array {
		$user = $this->env->current_user_id();
		if ( ! $this->env->is_user_logged_in() || $user <= 0 ) {
			return $this->result( 'administrator', 'مدیر کل', 'fail', 'نشست مدیر لازم است.' );
		}
		if ( ! $this->env->user_can( $user, 'manage_options' ) || ! $this->env->user_can( $user, 'install_plugins' ) ) {
			return $this->result( 'administrator', 'مدیر کل', 'fail', 'هر دو اختیار manage_options و install_plugins لازم است.' );
		}
		$account = $this->env->user( $user );
		if ( null === $account || ! in_array( 'administrator', (array) $account['roles'], true ) ) {
			return $this->result( 'administrator', 'مدیر کل', 'fail', 'حساب جاری نقش administrator ندارد.' );
		}

		return $this->result( 'administrator', 'مدیر کل', 'pass', 'مدیر کل با اختیارهای لازم تأیید شد.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_runtime(): array {
		if ( version_compare( $this->env->php_version(), '8.1', '<' ) ) {
			return $this->result( 'runtime', 'PHP/WordPress', 'fail', 'PHP 8.1 یا بالاتر لازم است.' );
		}
		if ( version_compare( $this->env->wp_version(), '6.9', '<' ) ) {
			return $this->result( 'runtime', 'PHP/WordPress', 'fail', 'WordPress 6.9 یا بالاتر لازم است.' );
		}
		foreach ( array( 'json', 'mbstring', 'pcre', 'hash' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				return $this->result( 'runtime', 'PHP/WordPress', 'fail', 'افزونه PHP ' . $extension . ' فعال نیست.' );
			}
		}

		return $this->result( 'runtime', 'PHP/WordPress', 'pass', 'PHP ' . $this->env->php_version() . ' / WordPress ' . $this->env->wp_version() );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_object_cache(): array {
		return $this->env->object_cache_enabled()
			? $this->result( 'object_cache', 'بدون Object Cache پایدار', 'fail', 'کش شیء پایدار بازبینی بلافاصله پیش از تغییر را غیرقابلاعتماد میکند.' )
			: $this->result( 'object_cache', 'بدون Object Cache پایدار', 'pass', 'کش شیء پایدار غیرفعال است.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_cpms(): array {
		if ( ! $this->platform->available() || ! \CpmsDemo\Standalone::core_available( $this->env->active_plugins() ) ) {
			return $this->result( 'cpms', 'CPMS Classic', 'fail', 'افزونه CPMS Classic فعال نیست.' );
		}
		if ( CPMS_DEMO_SCHEMA_VERSION !== $this->platform->schema_version() ) {
			return $this->result( 'cpms', 'CPMS Classic', 'fail', 'نسخه اسکیمای CPMS با نسخه پشتیبانی شده همخوان نیست.' );
		}
		if ( $this->platform->otp_echo_defined() || $this->env->constant_defined( 'CPMS_DEV_OTP_ECHO' ) ) {
			return $this->result( 'cpms', 'CPMS Classic', 'fail', 'ثابت CPMS_DEV_OTP_ECHO تعریف شده است.' );
		}

		return $this->result( 'cpms', 'CPMS Classic', 'pass', 'CPMS ' . $this->platform->plugin_version() . ' / schema ' . (string) $this->platform->schema_version() );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_schema_tables(): array {
		$missing = array();
		foreach ( self::KNOWN_TABLES as $short ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				$missing[] = $short;
			}
		}
		if ( array() !== $missing ) {
			return $this->result( 'schema_tables', 'جداول CPMS', 'fail', 'جدولهای ناقص: ' . implode( ', ', array_slice( $missing, 0, 5 ) ) );
		}

		return $this->result( 'schema_tables', 'جداول CPMS', 'pass', count( self::KNOWN_TABLES ) . ' جدول شناخته شده وجود دارد.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_unknown_tables(): array {
		$unknown = array();
		foreach ( $this->platform->table_names() as $short ) {
			if ( ! in_array( $short, self::KNOWN_TABLES, true ) && $this->platform->count_all( $short ) > 0 ) {
				$unknown[] = $short;
			}
		}
		if ( array() !== $unknown ) {
			return $this->result( 'unknown_tables', 'جداول ناشناخته', 'fail', 'جدول ناشناخته دارای داده: ' . implode( ', ', array_slice( $unknown, 0, 5 ) ) );
		}

		return $this->result( 'unknown_tables', 'جداول ناشناخته', 'pass', 'هیچ جدول ناشناختهای دارای داده نیست.' );
	}

	/**
	 * Fail-closed guard for the host site's implicit-Clinic scope semantics.
	 *
	 * The audit log has a production foreign key to `cpms_clinics`, so the three
	 * synthetic parent rows are archived in place at teardown instead of being
	 * deleted (audit rows must never be removed). That means `COUNT(*)` on
	 * `cpms_clinics` stays one higher than it was before provisioning, for ever.
	 *
	 * Production decides "single-Clinic install" from exactly that count — not
	 * from active status — in `SystemClinicResolver::resolve()` (the implicit
	 * scope fallback behind `App::scope()`), in `FirstPracticeSetupService`
	 * (first-practice setup and recovery), and in `single_clinic_display_row()`.
	 * Leaving an inert anchor on a one-Clinic site would therefore permanently
	 * convert a working implicit-scope install into "explicit scope required",
	 * which is a silent, durable change to pre-existing site behaviour.
	 *
	 * This mode is therefore offered without further ceremony only where the host
	 * site is already multi-Clinic: adding one more Clinic to a count that is
	 * already >= 2 cannot change any `=== 1` or `=== 0` decision. A zero-Clinic
	 * site must use the strict disposable mode instead.
	 *
	 * A host with exactly ONE Clinic — a real private test site, which is exactly
	 * the case the Owner hit on a real domain — is not silently refused and not
	 * silently allowed either: the administrator may state, in a sealed record
	 * that names this site and its host, that the site is a private test site and
	 * that they accept the permanent implicit-scope change. Without that record
	 * the gate still fails closed, because it is read from the sealed option and
	 * never from the request.
	 *
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_host_scope(): array {
		$consent  = $this->manifest instanceof ScopedManifest ? $this->manifest->private_host_consent() : null;
		$host     = is_array( $consent ) ? (string) ( $consent['host'] ?? '' ) : '';
		$decision = self::host_scope_decision( $this->platform->count_all( 'cpms_clinics' ), is_array( $consent ), $host );

		return $this->result( 'host_scope', 'دامنهٔ ضمنی درمانگاه میزبان', (string) $decision['status'], (string) $decision['detail'] );
	}

	/**
	 * Whether the administrator has already accepted the single-Clinic
	 * consequence on this exact site.
	 *
	 * @return bool
	 */
	public function private_host_consented(): bool {
		return $this->manifest instanceof ScopedManifest && null !== $this->manifest->private_host_consent();
	}

	/**
	 * Pure decision table for the host implicit-scope gate.
	 *
	 * @param int    $clinic_count       Current `COUNT(*)` on `cpms_clinics`.
	 * @param bool   $private_host       Sealed private-test-site acceptance exists for this site.
	 * @param string $host              This site's host, for the Persian explanation.
	 * @return array{status:string,detail:string}
	 */
	public static function host_scope_decision( int $clinic_count, bool $private_host = false, string $host = '' ): array {
		if ( $clinic_count < 1 ) {
			return array(
				'status' => 'fail',
				'detail' => 'این سایت هیچ درمانگاهی ندارد؛ برای سایت خالی از حالت یک‌بارمصرف استفاده کنید.',
			);
		}
		if ( 1 === $clinic_count ) {
			if ( ! $private_host ) {
				return array(
					'status' => 'fail',
					'detail' => 'این سایت تک‌درمانگاهی است. ساخت دمو یک «درمانگاه لنگر» اضافه می‌کند که پس از پاک‌سازی هم باقی می‌ماند و در نتیجه دامنهٔ ضمنی تک‌درمانگاهی این سایت برای همیشه به «نیازمند انتخاب درمانگاه» تغییر می‌کند. '
						. 'اگر این سایت یک سایت آزمایشی خصوصی است و این تغییر را می‌پذیرید، همین پایین با نوشتن نام دامنه تأیید کنید؛ در غیر این صورت ابتدا یک درمانگاه واقعی دیگر بسازید یا از حالت یک‌بارمصرف استفاده کنید.',
				);
			}

			return array(
				'status' => 'pass',
				'detail' => 'تک‌درمانگاهی است، اما شما صریحاً تأیید کرده‌اید که این سایت یک سایت آزمایشی خصوصی است. '
					. 'با ساخت دمو، درمانگاه لنگر پس از پاک‌سازی بایگانی می‌شود و شمار درمانگاه‌ها یکی بیشتر از قبل می‌ماند؛ یعنی این سایت از حالت «تک‌درمانگاهی خودکار» خارج می‌شود. '
					. 'برای بازگشت به حالت قبل باید ردیف لنگر بایگانی‌شده به‌صورت دستی و با پشتیبان بررسی شود؛ این کار عمداً خودکار نیست چون ردیف‌های حسابرسی هرگز حذف نمی‌شوند.'
					. ( '' === $host ? '' : ' (دامنهٔ تأییدشده: ' . $host . ')' ),
			);
		}

		return array(
			'status' => 'pass',
			'detail' => 'سایت از قبل چند‌درمانگاهی است (clinics=' . $clinic_count . ')؛ افزودن یک لنگر غیرفعال هیچ تصمیم COUNT(*)==1 را تغییر نمی‌دهد.',
		);
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_mode(): array {
		/** @var ScopedManifest $manifest */
		$manifest = $this->manifest;

		return $manifest->mode_enabled()
			? $this->result( 'mode', 'فعالسازی صریح حالت محدودشده', 'pass', 'حالت محدودشده توسط مدیر کل فعال شده است.' )
			: $this->result( 'mode', 'فعالسازی صریح حالت محدودشده', 'fail', 'ابتدا حالت محدودشده را با عبارت تأیید فعال کنید.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_other_manifest(): array {
		// `cpdd_lock_state` is the shared Env mutex written by *both* modes while
		// a mutation is in progress; it is not evidence of a strict fixture.
		if ( is_array( $this->env->option_get( 'cpdd_manifest', false ) ) ) {
			return $this->result( 'other_manifest', 'عدم تداخل با دموی یکبارمصرف', 'fail', 'یک دموی یکبارمصرف فعال وجود دارد.' );
		}
		/** @var ScopedManifest $manifest */
		$manifest = $this->manifest;
		if ( $manifest->exists() ) {
			return $this->result( 'other_manifest', 'عدم تداخل با دموی محدودشده', 'fail', 'یک دموی محدودشده فعال یا ناتمام وجود دارد.' );
		}

		return $this->result( 'other_manifest', 'عدم تداخل manifest', 'pass', 'هیچ manifest فعالی وجود ندارد.' );
	}

	/**
	 * Report the `cpsd-` namespace instead of blocking on its existence.
	 *
	 * The old rule refused to run while ANY account whose login started with
	 * `cpsd-` existed. On the very site this mode exists for - a real test site
	 * that has already been used, and whose leftover synthetic accounts nobody
	 * has sealed proof for - that turned a harmless, already-present row into a
	 * permanent dead end, and it said nothing about what those accounts are.
	 *
	 * The blocking question is not "does the prefix appear" but "would this run
	 * collide", and that is decided where the identity actually exists:
	 * `ScopedProvisioner::check_collisions()` builds the exact logins, e-mails,
	 * page slug, tenant slugs, synthetic mobile, idempotency keys and rate-limit
	 * keys of THIS fixture id and refuses with `COLLISION` BEFORE the first
	 * synthetic row is written. Nothing is adopted, renamed or deleted here.
	 *
	 * So this check is informational and always non-blocking, and its detail is
	 * produced by the same single survey the page renders (FixtureNamespace), so
	 * the table and the card cannot disagree.
	 *
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_fixture_namespace(): array {
		return self::namespace_result( FixtureNamespace::survey( $this->env, $this->platform ) );
	}

	/**
	 * The namespace row is informational by design: it reports what already
	 * exists, and it never blocks. Blocking is done by the exact collision check
	 * at provisioning time, where the fixture identity actually exists.
	 *
	 * Kept as a pure function so the "never blocks" property is testable without
	 * a database.
	 *
	 * @param array<string,mixed> $survey Result of FixtureNamespace::survey().
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	public static function namespace_result( array $survey ): array {
		return array(
			'id'     => 'fixture_namespace',
			'label'  => 'فضای نام دمو (بدون تداخل)',
			'status' => 'pass',
			'detail' => FixtureNamespace::summary_fa( $survey ),
		);
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_queue_lock(): array {
		global $wpdb;
		$free = $wpdb->get_var( "SELECT IS_FREE_LOCK('cpms_jobs_tick')" );
		if ( '1' !== (string) $free && 1 !== $free ) {
			return $this->result( 'queue_lock', 'قفل اجراکننده صف', 'fail', 'یک اجراکننده صف CPMS در حال کار است؛ بعداً دوباره تلاش کنید.' );
		}

		return $this->result( 'queue_lock', 'قفل اجراکننده صف', 'pass', 'قفل cpms_jobs_tick آزاد است.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_audit_chain(): array {
		$result = $this->audit_guard->verify_chain();
		if ( ! $result['ok'] ) {
			return $this->result( 'audit_chain', 'زنجیره حسابرسی', 'fail', $result['error'] . ' at row ' . (string) ( $result['broken_at'] ?? '' ) );
		}

		return $this->result( 'audit_chain', 'زنجیره حسابرسی', 'pass', 'checked=' . (int) $result['checked'] . ' tail=' . substr( (string) $result['tail'], 0, 12 ) );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_baseline(): array {
		try {
			$snapshot = $this->baseline->snapshot();
		} catch ( \Throwable $e ) {
			return $this->result( 'baseline', 'خواندن baseline', 'fail', 'خطای خواندن baseline: ' . $e->getMessage() );
		}
		$count = count( (array) ( $snapshot['cpms'] ?? array() ) ) + count( (array) ( $snapshot['wp'] ?? array() ) );
		if ( $count < 40 ) {
			return $this->result( 'baseline', 'خواندن baseline', 'fail', 'تعداد جدولهای baseline ناکافی است.' );
		}

		return $this->result( 'baseline', 'خواندن baseline', 'pass', 'checked_tables=' . $count );
	}
}

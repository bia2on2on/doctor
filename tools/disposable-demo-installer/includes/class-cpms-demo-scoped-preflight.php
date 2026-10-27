<?php
/**
 * Fail-closed compatibility and safety preflight for the separate scoped mode.
 *
 * This is not a bypass of the strict disposable preflight. It is a different
 * contract that explicitly allows existing users/content/CPMS data, while
 * proving compatibility, an intact global audit chain, a free queue-runner lock,
 * no colliding fixture namespace, and a readable baseline snapshot.
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
		if ( ! $this->platform->available() || ! in_array( CPMS_DEMO_CORE_PLUGIN, $this->env->active_plugins(), true ) ) {
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
		if ( is_array( $this->env->option_get( 'cpdd_manifest', false ) ) || false !== $this->env->option_get( 'cpdd_lock_state', false ) ) {
			return $this->result( 'other_manifest', 'عدم تداخل با دموی یکبارمصرف', 'fail', 'یک دموی یکبارمصرف فعال یا در حال اجرا وجود دارد.' );
		}
		/** @var ScopedManifest $manifest */
		$manifest = $this->manifest;
		if ( $manifest->exists() || false !== $this->env->option_get( ScopedManifest::LOCK_OPTION, false ) ) {
			return $this->result( 'other_manifest', 'عدم تداخل با دموی محدودشده', 'fail', 'یک دموی محدودشده فعال یا ناتمام وجود دارد.' );
		}

		return $this->result( 'other_manifest', 'عدم تداخل manifest', 'pass', 'هیچ manifest فعالی وجود ندارد.' );
	}

	/** @return array{id:string,label:string,status:string,detail:string} */
	private function check_fixture_namespace(): array {
		foreach ( $this->env->all_users() as $user ) {
			if ( 0 === strpos( (string) ( $user['login'] ?? '' ), 'cpsd-' ) ) {
				return $this->result( 'fixture_namespace', 'عدم برخورد پیشوند دمو', 'fail', 'یک حساب با پیشوند cpsd- از قبل وجود دارد.' );
			}
		}
		foreach ( $this->env->posts_summary() as $post ) {
			if ( 0 === strpos( (string) ( $post['slug'] ?? '' ), 'cpsd-' ) ) {
				return $this->result( 'fixture_namespace', 'عدم برخورد پیشوند دمو', 'fail', 'یک نوشته/برگه با پیشوند cpsd- از قبل وجود دارد.' );
			}
		}
		foreach ( array( 'cpms_organizations', 'cpms_clinics', 'cpms_locations' ) as $short ) {
			$rows = $this->platform->fetch_all( 'SELECT slug FROM `' . $this->platform->table( $short ) . '` WHERE slug LIKE %s LIMIT 1', array( 'cpsd-%' ) );
			if ( array() !== $rows ) {
				return $this->result( 'fixture_namespace', 'عدم برخورد پیشوند دمو', 'fail', 'یک slug با پیشوند cpsd- در ' . $short . ' وجود دارد.' );
			}
		}

		return $this->result( 'fixture_namespace', 'عدم برخورد پیشوند دمو', 'pass', 'هیچ اثر قدیمی با پیشوند cpsd- یافت نشد.' );
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

<?php
/**
 * Fail-closed disposable-site preflight.
 *
 * A checked box is never evidence. Every check below is derived from live
 * WordPress/CPMS state, and any check that cannot be *proven* passes blocks the
 * operation. The same object is re-evaluated immediately before every mutation.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

/**
 * Environment/data safety verification.
 */
class Preflight {

	/** Operational CPMS tables that must be completely empty. */
	public const OPERATIONAL_TABLES = array(
		'cpms_clinicians',
		'cpms_clinic_memberships',
		'cpms_organization_memberships',
		'cpms_organization_membership_capabilities',
		'cpms_membership_capabilities',
		'cpms_membership_locations',
		'cpms_clinician_locations',
		'cpms_schedule',
		'cpms_schedule_exceptions',
		'cpms_schedule_slots',
		'cpms_slot_holds',
		'cpms_patients',
		'cpms_patient_identities',
		'cpms_patient_user_links',
		'cpms_patient_merges',
		'cpms_appointments',
		'cpms_visits',
		'cpms_visit_status_history',
		'cpms_clinical_notes',
		'cpms_clinical_note_versions',
		'cpms_prescriptions',
		'cpms_prescription_items',
		'cpms_recommendations',
		'cpms_follow_ups',
		'cpms_medical_attachments',
		'cpms_handwriting_documents',
		'cpms_handwriting_pages',
		'cpms_handwriting_page_versions',
		'cpms_ocr_jobs',
		'cpms_services',
		'cpms_invoices',
		'cpms_invoice_items',
		'cpms_payments',
		'cpms_payment_adjustments',
		'cpms_sms_messages',
		'cpms_otp_tokens',
		'cpms_rate_limits',
		'cpms_idempotency_keys',
		'cpms_notifications',
	);

	/** Tables whose rows may legitimately exist on a freshly activated install. */
	public const SYSTEM_TABLES = array(
		'cpms_organizations',
		'cpms_clinics',
		'cpms_locations',
		'cpms_settings',
		'cpms_schema_migrations',
		'cpms_jobs',
		'cpms_operational_logs',
		'cpms_audit_logs',
		'cpms_drug_reference',
		'cpms_license_install',
		'cpms_license_state',
	);

	/** Every table the pinned schema version may contain. */
	public const KNOWN_TABLES = array(
		'cpms_appointments',
		'cpms_audit_logs',
		'cpms_clinic_memberships',
		'cpms_clinical_note_versions',
		'cpms_clinical_notes',
		'cpms_clinician_locations',
		'cpms_clinicians',
		'cpms_clinics',
		'cpms_drug_reference',
		'cpms_follow_ups',
		'cpms_handwriting_documents',
		'cpms_handwriting_page_versions',
		'cpms_handwriting_pages',
		'cpms_idempotency_keys',
		'cpms_invoice_items',
		'cpms_invoices',
		'cpms_jobs',
		'cpms_license_install',
		'cpms_license_state',
		'cpms_locations',
		'cpms_medical_attachments',
		'cpms_membership_capabilities',
		'cpms_membership_locations',
		'cpms_notifications',
		'cpms_ocr_jobs',
		'cpms_operational_logs',
		'cpms_organizations',
		'cpms_organization_memberships',
		'cpms_organization_membership_capabilities',
		'cpms_otp_tokens',
		'cpms_patient_identities',
		'cpms_patient_merges',
		'cpms_patient_user_links',
		'cpms_patients',
		'cpms_payment_adjustments',
		'cpms_payments',
		'cpms_prescription_items',
		'cpms_prescriptions',
		'cpms_rate_limits',
		'cpms_recommendations',
		'cpms_schedule',
		'cpms_schedule_exceptions',
		'cpms_schedule_slots',
		'cpms_schema_migrations',
		'cpms_services',
		'cpms_settings',
		'cpms_slot_holds',
		'cpms_sms_messages',
		'cpms_visit_status_history',
		'cpms_visits',
	);

	/**
	 * @var Env
	 */
	private Env $env;

	/**
	 * @var Platform
	 */
	private Platform $platform;

	/**
	 * @var Manifest
	 */
	private Manifest $manifest;

	/**
	 * @param Env      $env Environment.
	 * @param Platform $platform CPMS adapter.
	 * @param Manifest $manifest Manifest store.
	 */
	public function __construct( Env $env, Platform $platform, Manifest $manifest ) {
		$this->env      = $env;
		$this->platform = $platform;
		$this->manifest = $manifest;
	}

	/**
	 * Run every check.
	 *
	 * @return array{ok:bool,checks:list<array{id:string,label:string,status:string,detail:string}>,blocking:list<string>}
	 */
	public function run(): array {
		$checks = array(
			$this->check_site_type(),
			$this->check_transport(),
			$this->check_capabilities(),
			$this->check_runtime(),
			$this->check_object_cache(),
			$this->check_plugins(),
			$this->check_cpms(),
			$this->check_users(),
			$this->check_content(),
			$this->check_cpms_data(),
			$this->check_manifest_state(),
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

	/**
	 * @param string $id Id.
	 * @param string $label Persian label.
	 * @param string $status pass|fail.
	 * @param string $detail Persian detail.
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function result( string $id, string $label, string $status, string $detail ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		);
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_site_type(): array {
		$id    = 'single_site';
		$label = 'نصب تک‌سایتی (نه Multisite)';
		if ( $this->env->is_multisite() ) {
			return $this->result( $id, $label, 'fail', 'این نصب Multisite است؛ نصب تک‌سایتی لازم است.' );
		}

		return $this->result( $id, $label, 'pass', 'نصب تک‌سایتی تأیید شد.' );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_transport(): array {
		$id    = 'https';
		$label = 'اتصال HTTPS برای نمایش رمزها';
		$host  = (string) wp_parse_url( $this->env->home_url(), PHP_URL_HOST );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( $this->env->is_ssl() || $local ) {
			return $this->result( $id, $label, 'pass', 'اتصال امن (یا loopback محلی) تأیید شد.' );
		}

		return $this->result( $id, $label, 'fail', 'برای نمایش رمز یک‌بارمصرف، HTTPS لازم است.' );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_capabilities(): array {
		$id     = 'administrator';
		$label  = 'مدیر کل با manage_options و install_plugins';
		$user   = $this->env->current_user_id();
		$detail = 'کاربر فعلی Administrator با هر دو اختیار تأیید شد.';
		if ( $user <= 0 || ! $this->env->is_user_logged_in() ) {
			return $this->result( $id, $label, 'fail', 'نشست مدیر لازم است.' );
		}
		if ( ! $this->env->user_can( $user, 'manage_options' ) || ! $this->env->user_can( $user, 'install_plugins' ) ) {
			return $this->result( $id, $label, 'fail', 'این حساب هر دو اختیار manage_options و install_plugins را ندارد.' );
		}
		$account = $this->env->user( $user );
		if ( null === $account || ! in_array( 'administrator', $account['roles'], true ) ) {
			return $this->result( $id, $label, 'fail', 'حساب فعلی نقش administrator ندارد.' );
		}

		return $this->result( $id, $label, 'pass', $detail );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_runtime(): array {
		$id    = 'runtime';
		$label = 'PHP/WordPress و افزونه‌های لازم';
		if ( version_compare( $this->env->php_version(), '8.1', '<' ) ) {
			return $this->result( $id, $label, 'fail', 'PHP 8.1 یا بالاتر لازم است.' );
		}
		if ( version_compare( $this->env->wp_version(), '6.9', '<' ) ) {
			return $this->result( $id, $label, 'fail', 'WordPress 6.9 یا بالاتر لازم است.' );
		}
		foreach ( array( 'json', 'mbstring', 'pcre', 'hash' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				return $this->result( $id, $label, 'fail', 'افزونهٔ PHP «' . $extension . '» فعال نیست.' );
			}
		}

		return $this->result( $id, $label, 'pass', 'PHP ' . $this->env->php_version() . ' / WordPress ' . $this->env->wp_version() );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_object_cache(): array {
		$id    = 'object_cache';
		$label = 'بدون Object Cache پایدار';
		if ( $this->env->object_cache_enabled() ) {
			return $this->result( $id, $label, 'fail', 'Object Cache پایدار فعال است و بازبینی «بلافاصله پیش از تغییر» را غیرقابل‌اعتماد می‌کند.' );
		}

		return $this->result( $id, $label, 'pass', 'Object Cache پایدار غیرفعال است.' );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_plugins(): array {
		$id      = 'plugins';
		$label   = 'بدون افزونهٔ فعال دیگر (Side effect)';
		$self    = plugin_basename( CPDD_FILE );
		$foreign = array();
		foreach ( $this->env->active_plugins() as $plugin ) {
			// Allowed: the production plugin and this companion itself (matched by
			// its own computed basename, so a renamed install directory still
			// recognises itself instead of blocking on its own presence).
			if ( CPDD_CPMS_PLUGIN === $plugin || $self === $plugin || basename( CPDD_FILE ) === basename( $plugin ) ) {
				continue;
			}
			$foreign[] = $plugin;
		}
		if ( array() !== $foreign ) {
			return $this->result( $id, $label, 'fail', 'افزونهٔ فعال دیگر: ' . implode( ', ', $foreign ) );
		}

		return $this->result( $id, $label, 'pass', 'فقط CPMS Classic و این افزونهٔ همراه فعال‌اند.' );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_cpms(): array {
		$id    = 'cpms';
		$label = 'CPMS Classic فعال با Migration کامل';
		if ( ! $this->platform->available() ) {
			return $this->result( $id, $label, 'fail', 'افزونهٔ CPMS Classic فعال نیست.' );
		}
		if ( ! in_array( CPDD_CPMS_PLUGIN, $this->env->active_plugins(), true ) ) {
			return $this->result( $id, $label, 'fail', 'CPMS Classic در فهرست افزونه‌های فعال نیست.' );
		}
		$schema = $this->platform->schema_version();
		if ( CPDD_SCHEMA_VERSION !== $schema ) {
			return $this->result( $id, $label, 'fail', 'Schema فعلی «' . (string) $schema . '» است؛ نسخهٔ پشتیبانی‌شده ' . CPDD_SCHEMA_VERSION . '.' );
		}
		if ( $this->platform->otp_echo_defined() ) {
			return $this->result( $id, $label, 'fail', 'ثابت CPMS_DEV_OTP_ECHO تعریف شده است؛ این محیط امن نیست.' );
		}

		return $this->result( $id, $label, 'pass', 'CPMS ' . $this->platform->plugin_version() . ' با Schema ' . $schema );
	}

	/**
	 * The disposable site must contain exactly one account: the acting
	 * Administrator. Any other user — staff, patient, leftover — blocks.
	 *
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_users(): array {
		$id      = 'users';
		$label   = 'تنها یک حساب کاربری (مدیر)';
		$current = $this->env->current_user_id();
		$users   = $this->env->all_users();
		if ( count( $users ) !== 1 ) {
			$logins = array();
			foreach ( $users as $user ) {
				if ( (int) $user['id'] !== $current ) {
					$logins[] = $user['login'];
				}
			}

			return $this->result( $id, $label, 'fail', 'تعداد حساب‌ها ' . count( $users ) . ' است' . ( array() !== $logins ? ' (دیگران: ' . implode( ', ', $logins ) . ')' : '' ) . '؛ سایت یک‌بارمصرف خالی نیست.' );
		}
		if ( (int) $users[0]['id'] !== $current ) {
			return $this->result( $id, $label, 'fail', 'حساب موجود همان مدیر جاری نیست.' );
		}

		return $this->result( $id, $label, 'pass', 'تنها حساب موجود، مدیر جاری است.' );
	}

	/**
	 * WordPress must still hold only its default starter content.
	 *
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_content(): array {
		$id           = 'content';
		$label        = 'محتوای پیش‌فرض وردپرس (بدون برگه/نوشته دیگر)';
		$allowed      = array(
			'post:hello-world',
			'page:sample-page',
			'page:privacy-policy',
		);
		$unexpected   = array();
		foreach ( $this->env->posts_summary() as $post ) {
			if ( 'attachment' === $post['type'] || ! in_array( $post['type'], array( 'post', 'page' ), true ) ) {
				$unexpected[] = $post['type'] . '#' . $post['id'];
				continue;
			}
			if ( ! in_array( $post['type'] . ':' . $post['slug'], $allowed, true ) ) {
				$unexpected[] = $post['type'] . ':' . $post['slug'];
			}
		}
		if ( $this->env->comment_count() > 0 ) {
			return $this->result( $id, $label, 'fail', 'دیدگاه (Comment) در سایت وجود دارد.' );
		}
		if ( array() !== $unexpected ) {
			return $this->result( $id, $label, 'fail', 'محتوای غیرپیش‌فرض: ' . implode( ', ', $unexpected ) );
		}

		return $this->result( $id, $label, 'pass', 'تنها محتوای پیش‌فرض وردپرس موجود است.' );
	}

	/**
	 * CPMS must be in its untouched bootstrap state.
	 *
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_cpms_data(): array {
		$id    = 'cpms_data';
		$label = 'دادهٔ بالینی/مالی/نوبت خالی و Bootstrap دست‌نخورده';

		foreach ( self::OPERATIONAL_TABLES as $short ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				return $this->result( $id, $label, 'fail', 'جدول ' . $short . ' وجود ندارد؛ Migration کامل نیست.' );
			}
			$count = $this->platform->count_all( $short );
			if ( $count > 0 ) {
				return $this->result( $id, $label, 'fail', $short . ' شامل ' . $count . ' ردیف است؛ سایت یک‌بارمصرف خالی نیست.' );
			}
		}

		$unknown = array();
		foreach ( $this->platform->table_names() as $short ) {
			if ( ! in_array( $short, self::KNOWN_TABLES, true ) && $this->platform->count_all( $short ) > 0 ) {
				$unknown[] = $short;
			}
		}
		if ( array() !== $unknown ) {
			return $this->result( $id, $label, 'fail', 'جدول ناشناختهٔ دارای داده: ' . implode( ', ', $unknown ) );
		}

		$bootstrap = $this->platform->bootstrap_state();
		if ( ! is_array( $bootstrap ) || ! ( $bootstrap['ok'] ?? false ) ) {
			return $this->result( $id, $label, 'fail', (string) ( $bootstrap['reason'] ?? 'وضعیت Bootstrap نامعتبر است.' ) );
		}

		return $this->result( $id, $label, 'pass', 'Bootstrap پیش‌فرض Organization/Clinic/Location دست‌نخورده و جداول عملیاتی خالی‌اند.' );
	}

	/**
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private function check_manifest_state(): array {
		$id    = 'manifest';
		$label = 'بدون دموی فعال یا باقی‌ماندهٔ قبلی';
		try {
			if ( $this->manifest->exists() ) {
				$this->manifest->load();

				return $this->result( $id, $label, 'fail', 'یک دمو از قبل ثبت شده است؛ ابتدا آن را پاک‌سازی کنید.' );
			}
		} catch ( Exception $e ) {
			return $this->result( $id, $label, 'fail', 'فایل مالکیت موجود معتبر نیست: ' . $e->getMessage() );
		}

		return $this->result( $id, $label, 'pass', 'اثر دموی قبلی وجود ندارد.' );
	}
}

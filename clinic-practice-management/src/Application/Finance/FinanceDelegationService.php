<?php

declare(strict_types=1);

namespace ClinicCore\Application\Finance;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Membership\MembershipService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * 2026-10-15 — واگذاری صریح و محدود مجوزهای مالی حساس توسط مدیر کلینیک.
 *
 * سند حاکم: docs/decisions/2026-10-15-financial-authorization-policy.md (بند ۴)
 * و docs/permissions/permission-matrix.md §۷.
 *
 * قرارداد:
 *  1. اختیار واگذاری = `cpms_config` مؤثر و Clinic-scoped روی عضویت فعال همان
 *     Clinic از مسیر AuthorizationService (هرگز نقش سراسری WordPress، هرگز
 *     manage_options، هرگز clinic از payload).
 *  2. مجموعهٔ واگذارشدنی «محدود» است: فقط چهار Capability مالیِ حساس موضوع سیاست.
 *     Capabilityهای بالینی/پرونده/یادداشت خصوصی/Export/Audit/تنظیمات و هر رشتهٔ
 *     ناشناخته واگذارشدنی نیست (هیچ دسترسی پزشکی پیش‌فرض ساخته نمی‌شود).
 *  3. هدف باید عضویت فعالِ همان Clinic باشد؛ عضویت بیگانه/ناموجود/معلق رد می‌شود.
 *  4. ذخیره‌سازی = همان `cpms_membership_capabilities` موجود از مسیر
 *     MembershipService — بدون مهاجرت و بدون موتور موازی.
 *  5. تقدم موجود حفظ می‌شود: اگر برای هدف Deny صریح وجود داشته باشد، واگذاری رد
 *     می‌شود (fail-closed) تا هرگز «Grant بی‌اثر» ثبت نشود.
 *  6. هر تغییر با Audit زنجیره‌ای در همان تراکنش ثبت می‌شود؛ شکست Audit ⇒ rollback.
 *  7. بازگردانی = حذف همان یک ردیف؛ بازگشت فوری به پیش‌فرض/DENY.
 *  8. واگذاری هرگز شرط مالکیت تراکنش برای نقش پزشک را برنمی‌دارد: مالکیت در
 *     AuthorizationService بر پایهٔ نقشِ عضویتِ پزشک الزامی می‌ماند (اصلاح امنیتی
 *     2026-10-15) — واگذاری فقط «مجوز مؤثر» می‌دهد، نه مالکیت نامرتبط.
 */
final class FinanceDelegationService {

	public const AUDIT_DELEGATED = 'FINANCE_DELEGATION_GRANTED';
	public const AUDIT_REVOKED   = 'FINANCE_DELEGATION_REVOKED';

	/**
	 * مجموعهٔ محدود و صریح Capabilityهای مالیِ حساس قابل واگذاری.
	 */
	private const DELEGABLE = [
		RolesAndCapabilities::INVOICE_ADJUST,
		RolesAndCapabilities::INVOICE_VOID,
		RolesAndCapabilities::PAYMENT_REFUND,
		RolesAndCapabilities::PAYMENT_VOID,
	];

	public function __construct(
		private readonly CpmsDb $db,
		private readonly MembershipRepository $memberships,
		private readonly MembershipService $membership_service,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * @return list<string>
	 */
	public static function delegable_capabilities(): array {
		$caps = self::DELEGABLE;
		sort( $caps );

		return $caps;
	}

	public static function is_delegable( string $capability ): bool {
		return in_array( $capability, self::DELEGABLE, true );
	}

	/**
	 * واگذاری صریح یک Capability مالیِ حساس به عضویت هدف.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws FinanceException
	 */
	public function delegate( int $actor_user_id, int $clinic_id, int $target_membership_id, string $capability ): array {
		$this->assert_bounded( $capability );
		$membership = $this->authorized_target( $actor_user_id, $clinic_id, $target_membership_id );

		$before = $this->explicit_effect( $target_membership_id, $capability );
		if ( $before === 'deny' ) {
			throw FinanceException::of(
				'CLINIC_DELEGATION_DENY_PRECEDENCE',
				'برای این عضو Deny صریح ثبت شده است؛ واگذاری نمی‌تواند آن را نقض کند.',
				409,
				[ 'capability' => $capability, 'membership_id' => $target_membership_id ]
			);
		}

		$target_user_id = (int) ( $membership['wp_user_id'] ?? 0 );
		$this->db->transactional(
			function () use ( $actor_user_id, $clinic_id, $target_membership_id, $capability, $before, $target_user_id ): void {
				$this->membership_service->set_capability( $target_membership_id, $capability, 'grant' );

				$meta = [ 'clinic_id' => $clinic_id, 'target_wp_user_id' => $target_user_id ];

				$this->audit->log(
					self::AUDIT_DELEGATED,
					$this->actor( $actor_user_id ),
					'membership_capability',
					$target_membership_id,
					null,
					[ 'capability' => $capability, 'effect' => $before ],
					[ 'capability' => $capability, 'effect' => 'grant' ],
					$meta,
					$clinic_id
				);
			}
		);

		$result = [ 'capability' => $capability, 'effect' => 'grant', 'clinic_id' => $clinic_id, 'membership_id' => $target_membership_id, 'target_wp_user_id' => $target_user_id ];

		return $result;
	}

	/**
	 * بازگردانی واگذاری (حذف همان یک ردیف).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws FinanceException
	 */
	public function revoke( int $actor_user_id, int $clinic_id, int $target_membership_id, string $capability ): array {
		$this->assert_bounded( $capability );
		$membership = $this->authorized_target( $actor_user_id, $clinic_id, $target_membership_id );

		$current = $this->explicit_effect( $target_membership_id, $capability );
		if ( $current === null ) {
			throw FinanceException::of(
				'CLINIC_DELEGATION_NOT_FOUND',
				'واگذاری فعالی برای این Capability وجود ندارد.',
				404,
				[ 'capability' => $capability, 'membership_id' => $target_membership_id ]
			);
		}

		$target_user_id = (int) ( $membership['wp_user_id'] ?? 0 );
		$this->db->transactional(
			function () use ( $actor_user_id, $clinic_id, $target_membership_id, $capability, $current, $target_user_id ): void {
				$this->membership_service->remove_capability( $target_membership_id, $capability );

				$meta = [ 'clinic_id' => $clinic_id, 'target_wp_user_id' => $target_user_id ];

				$this->audit->log(
					self::AUDIT_REVOKED,
					$this->actor( $actor_user_id ),
					'membership_capability',
					$target_membership_id,
					null,
					[ 'capability' => $capability, 'effect' => $current ],
					[ 'capability' => $capability, 'effect' => 'removed' ],
					$meta,
					$clinic_id
				);
			}
		);

		$result = [ 'capability' => $capability, 'effect' => 'removed', 'clinic_id' => $clinic_id, 'membership_id' => $target_membership_id, 'target_wp_user_id' => $target_user_id ];

		return $result;
	}

	/**
	 * توزیع مؤثر فعلی هدف برای Capability (grant/deny/null) — مستقیماً از همان
	 * ذخیره‌سازی Override موجود.
	 */
	public function explicit_effect( int $membership_id, string $capability ): ?string {
		foreach ( $this->memberships->capabilities_for( $membership_id ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (string) ( $row['capability'] ?? '' ) === $capability ) {
				return (string) ( $row['effect'] ?? '' );
			}
		}

		return null;
	}

	private function assert_bounded( string $capability ): void {
		if ( self::is_delegable( $capability ) ) {
			return;
		}

		throw FinanceException::of(
			'CLINIC_DELEGATION_OUT_OF_BOUNDS',
			'این Capability خارج از مجموعهٔ محدود و قابل واگذاری است.',
			422,
			[ 'capability' => $capability, 'delegable' => self::delegable_capabilities() ]
		);
	}

	/**
	 * اختیار واگذاری (cpms_config مؤثر در همان Clinic) + هدف در همان Clinic.
	 *
	 * @return array<string, mixed>
	 */
	private function authorized_target( int $actor_user_id, int $clinic_id, int $target_membership_id ): array {
		if ( $actor_user_id <= 0 || $clinic_id <= 0 || $target_membership_id <= 0 ) {
			throw FinanceException::of( 'CLINIC_VALIDATION_FAILED', 'ورودی واگذاری نامعتبر است', 400 );
		}

		try {
			App::authorization_service()->authorize( $actor_user_id, $clinic_id, RolesAndCapabilities::CONFIG );
		} catch ( AuthorizationException $e ) {
			throw FinanceException::of(
				'CLINIC_DELEGATION_DENIED',
				'واگذاری مجوز مالی حساس فقط با اختیار مدیریتی (cpms_config) همین Clinic مجاز است.',
				403,
				[ 'reason' => $e->getErrorCode(), 'clinic_id' => $clinic_id ]
			);
		}

		$membership = $this->memberships->find_by_id( $target_membership_id );
		if ( $membership === null ) {
			throw FinanceException::of( 'CLINIC_DELEGATION_NOT_FOUND', 'عضویت هدف یافت نشد.', 404 );
		}
		if ( (int) ( $membership['clinic_id'] ?? 0 ) !== $clinic_id ) {
			throw FinanceException::of(
				'CLINIC_DELEGATION_DENIED',
				'واگذاری فقط روی عضویت همین Clinic مجاز است.',
				403,
				[ 'clinic_id' => $clinic_id ]
			);
		}
		if ( (string) ( $membership['status'] ?? '' ) !== 'active' ) {
			throw FinanceException::of(
				'CLINIC_DELEGATION_DENIED',
				'واگذاری فقط روی عضویت فعال مجاز است.',
				409,
				[ 'status' => (string) ( $membership['status'] ?? '' ) ]
			);
		}

		return $membership;
	}

	/**
	 * @return array{wp_user_id: int|null, role: string}
	 */
	private function actor( int $wp_user_id ): array {
		$user = get_userdata( $wp_user_id );

		return [ 'wp_user_id' => $wp_user_id, 'role' => $user !== false ? (string) ( $user->roles[0] ?? 'unknown' ) : 'unknown' ];
	}
}

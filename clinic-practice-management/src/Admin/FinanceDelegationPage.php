<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Finance\FinanceDelegationService;
use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * 2026-10-15 — کوچک‌ترین سطح UI لازم برای «واگذاری صریح و محدود مجوزهای مالی
 * حساس» به هر عضو (تصمیم مالک: docs/decisions/2026-10-15-financial-authorization-policy.md بند ۴).
 *
 *  - Surface سرور-محور و بدون JS/SPA؛ فقط فرم‌های Nonceدار.
 *  - هر خواندن/نوشتن مجوز را همین‌جا تصمیم نمی‌گیرد: اختیار واقعی را
 *    FinanceDelegationService + AuthorizationService روی عضویت پایدار همان
 *    Clinic می‌سنجند؛ WordPress-global role/`manage_options` مجوز ایجاد نمی‌کند.
 *  - فقط چهار Capability مالیِ حساس نمایش داده می‌شود (مجموعهٔ محدود).
 *  - هر تغییر در Audit زنجیره‌ای ثبت می‌شود و فوراً قابل بازگردانی است.
 */
final class FinanceDelegationPage {
	public const PAGE_SLUG = 'cpms-finance-delegation';

	private const NONCE = 'cpms_finance_delegation';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_cpms_finance_delegation', [ self::class, 'handle' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			CpmsAdminMenu::parentSlug(),
			'واگذاری مالی حساس',
			'واگذاری مالی حساس',
			'read',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید', 'cpms' ), 403 );
		}

		$actor = (int) get_current_user_id();

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . esc_html__( 'واگذاری مالی حساس', 'cpms' ) . '</h1>';

		$notice = get_transient( 'cpms_finance_delegation_notice_' . $actor );
		if ( is_string( $notice ) && $notice !== '' ) {
			delete_transient( 'cpms_finance_delegation_notice_' . $actor );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html__( 'ابطال/بازپرداخت پرداخت به‌صورت پیش‌فرض رد است. واگذاری زیر فقط برای چهار عملیات مالی حساس، محدود به همین Clinic، فقط به عضو فعال، قابل بازگردانی و ثبت‌شده در Audit است.', 'cpms' );
		echo '</p></div>';

		$choices = self::manageable_clinics( $actor );
		if ( [] === $choices ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'برای واگذاری باید عضویت فعالِ یک Clinic با مجوز مدیریتی (cpms_config) داشته باشید. وضعیت Administrator وردپرس به‌تنهایی مجوز Clinic ایجاد نمی‌کند.', 'cpms' );
			echo '</p></div></div>';

			return;
		}

		foreach ( $choices as $clinic_id => $label ) {
			self::render_clinic( $actor, (int) $clinic_id, (string) $label );
		}

		echo '</div>';
	}

	public static function handle(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید', 'cpms' ), 403 );
		}
		check_admin_referer( self::NONCE, '_cpms_nonce' );

		$actor       = (int) get_current_user_id();
		$clinic_id   = isset( $_POST['clinic_id'] ) ? (int) $_POST['clinic_id'] : 0;
		$membership  = isset( $_POST['membership_id'] ) ? (int) $_POST['membership_id'] : 0;
		$capability  = isset( $_POST['capability'] ) ? sanitize_key( wp_unslash( (string) $_POST['capability'] ) ) : '';
		$action_type = isset( $_POST['delegation_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['delegation_action'] ) ) : '';

		$message = '';
		try {
			if ( $action_type === 'revoke' ) {
				App::finance_delegation_service()->revoke( $actor, $clinic_id, $membership, $capability );
				$message = sprintf( 'واگذاری %s بازگردانی شد.', $capability );
			} else {
				App::finance_delegation_service()->delegate( $actor, $clinic_id, $membership, $capability );
				$message = sprintf( 'واگذاری %s ثبت شد (قابل بازگردانی و ممیزی‌شده).', $capability );
			}
		} catch ( FinanceException $e ) {
			$message = sprintf( 'واگذاری انجام نشد: %s', $e->getMessage() );
		} catch ( ScopeRequiredException | AuthorizationException $e ) {
			$message = 'واگذاری انجام نشد: دسترسی معتبر نیست.';
		}

		set_transient( 'cpms_finance_delegation_notice_' . $actor, $message, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Clinicهایی که این کاربر در آن‌ها مجوز مدیریتی مؤثر دارد (Clinic-scoped).
	 *
	 * @return array<int, string>
	 */
	private static function manageable_clinics( int $actor ): array {
		$clinics = [];
		foreach ( App::membership_service()->active_memberships_for_user( $actor ) as $membership ) {
			$clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
			if ( $clinic_id <= 0 || isset( $clinics[ $clinic_id ] ) ) {
				continue;
			}
			if ( App::authorization_service()->can( $actor, $clinic_id, RolesAndCapabilities::CONFIG ) ) {
				$clinics[ $clinic_id ] = (string) ( $membership['clinic_name'] ?? ( 'Clinic #' . $clinic_id ) );
			}
		}

		return $clinics;
	}

	private static function render_clinic( int $actor, int $clinic_id, string $label ): void {
		echo '<h2>' . esc_html( $label ) . ' <span class="description">(#' . (int) $clinic_id . ')</span></h2>';
		echo '<p class="description">' . esc_html__( 'مجموعهٔ محدود قابل واگذاری:', 'cpms' ) . ' ';
		echo esc_html( implode( '، ', FinanceDelegationService::delegable_capabilities() ) ) . '</p>';

		$members = ( new MembershipRepository( App::db() ) )->active_member_user_ids_for_clinic( $clinic_id );
		if ( [] === $members ) {
			echo '<p>' . esc_html__( 'عضو فعالی در این Clinic وجود ندارد.', 'cpms' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'عضو', 'cpms' ) . '</th>';
		echo '<th>' . esc_html__( 'نقش عضویت', 'cpms' ) . '</th>';
		foreach ( FinanceDelegationService::delegable_capabilities() as $capability ) {
			echo '<th>' . esc_html( $capability ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $members as $wp_user_id ) {
			$membership = App::membership_service()->active_membership_for( $clinic_id, (int) $wp_user_id );
			if ( $membership === null ) {
				continue;
			}
			$membership_id = (int) $membership['id'];
			$user          = get_userdata( (int) $wp_user_id );

			echo '<tr>';
			echo '<td>' . esc_html( $user !== false ? $user->user_login : ( '#' . (int) $wp_user_id ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $membership['role_key'] ?? '' ) ) . '</td>';

			foreach ( FinanceDelegationService::delegable_capabilities() as $capability ) {
				$effect = App::finance_delegation_service()->explicit_effect( $membership_id, $capability );
				echo '<td>';
				if ( $effect === 'deny' ) {
					echo '<span class="description">' . esc_html__( 'DENY صریح — واگذاری مجاز نیست', 'cpms' ) . '</span>';
				} elseif ( $effect === 'grant' ) {
					self::render_form( $clinic_id, $membership_id, $capability, 'revoke', 'بازگردانی' );
				} else {
					self::render_form( $clinic_id, $membership_id, $capability, 'delegate', 'واگذاری' );
				}
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_form( int $clinic_id, int $membership_id, string $capability, string $action_type, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( self::NONCE, '_cpms_nonce' );
		echo '<input type="hidden" name="action" value="cpms_finance_delegation">';
		echo '<input type="hidden" name="clinic_id" value="' . (int) $clinic_id . '">';
		echo '<input type="hidden" name="membership_id" value="' . (int) $membership_id . '">';
		echo '<input type="hidden" name="capability" value="' . esc_attr( $capability ) . '">';
		echo '<input type="hidden" name="delegation_action" value="' . esc_attr( $action_type ) . '">';
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}
}

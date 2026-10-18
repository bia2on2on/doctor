<?php
/**
 * Shortcode admin helper for CPMS public booking.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\PublicBookingShortcode;

/**
 * Read-only helper for CPMS shortcodes and their safe, Clinic-scoped examples.
 */
final class PublicShortcodeAdminPage {

	public const PAGE_SLUG = 'cpms-shortcodes';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_management_page(
			'کدهای کوتاه و نمایش در سایت',
			'کدهای کوتاه CPMS',
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید', '', array( 'response' => 403 ) );
		}

		$actor_user_id  = (int) get_current_user_id();
		$clinic_choices = self::authorized_clinic_choices( $actor_user_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; never authority
		$requested_clinic_id = isset( $_GET['clinic_id'] ) && is_scalar( $_GET['clinic_id'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector; never authority
			? absint( wp_unslash( (string) $_GET['clinic_id'] ) )
			: 0;
		$clinic_id = 0;
		foreach ( $clinic_choices as $choice ) {
			if ( (int) $choice['clinic_id'] === $requested_clinic_id ) {
				$clinic_id = $requested_clinic_id;
				break;
			}
		}
		$registered_tags = self::registered_cpms_shortcodes();
		?>
		<div class="wrap" dir="rtl">
			<h1>کدهای کوتاه و نمایش در سایت</h1>
			<p class="description">کد کوتاه را در محتوای برگه یا نوشتهٔ وردپرس، یا در بلوک «کد کوتاه»، قرار دهید. این ابزار برگه‌ای به‌صورت خودکار ایجاد نمی‌کند؛ محل نمایش را خودتان انتخاب کنید.</p>

			<?php if ( $clinic_choices !== array() ) : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<label for="cpms_shortcode_clinic">Clinic مجاز</label>
					<select id="cpms_shortcode_clinic" name="clinic_id" required>
						<option value="">انتخاب Clinic</option>
						<?php foreach ( $clinic_choices as $choice ) : ?>
							<option value="<?php echo (int) $choice['clinic_id']; ?>" <?php selected( $clinic_id, (int) $choice['clinic_id'] ); ?>><?php echo esc_html( (string) $choice['clinic_name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button">نمایش کد</button>
					<p class="description">فهرست فقط Clinicهایی را نشان می‌دهد که عضویت فعال و مجوز محلیِ پیکربندی برای آن‌ها دارید. شناسهٔ واردشده از URL به‌تنهایی دسترسی ایجاد نمی‌کند.</p>
				</form>
			<?php else : ?>
				<div class="notice notice-warning"><p>برای نمایش کد وابسته به Clinic، علاوه بر دسترسی مدیریت وردپرس، عضویت فعال و مجوز پیکربندی همان Clinic لازم است.</p></div>
			<?php endif; ?>

			<?php if ( $clinic_choices !== array() && $clinic_id <= 0 ) : ?>
				<div class="notice notice-info"><p>برای نمایش کد، Clinic را صریحاً انتخاب کنید؛ هیچ Clinic پیش‌فرضی حدس زده نمی‌شود.</p></div>
			<?php endif; ?>

			<?php if ( $clinic_id > 0 && $registered_tags !== array() ) : ?>
				<h2>کدهای کوتاه ثبت‌شده</h2>
				<?php foreach ( $registered_tags as $tag ) : ?>
					<section class="card" style="max-width: 760px; padding: 16px;">
						<h3><?php echo esc_html( $tag ); ?></h3>
						<p>این نمونه فقط Clinic انتخاب‌شده را به سطح عمومی رزرو پیوند می‌دهد؛ برای Clinic دیگری، از فهرست بالا انتخاب کنید.</p>
						<label for="cpms-shortcode-<?php echo esc_attr( $tag ); ?>">کد کوتاه</label>
						<textarea id="cpms-shortcode-<?php echo esc_attr( $tag ); ?>" class="large-text code" rows="2" readonly dir="ltr" data-cpms-shortcode><?php echo esc_textarea( sprintf( '[%s clinic_id="%d"]', $tag, $clinic_id ) ); ?></textarea>
						<p><button type="button" class="button button-primary" data-cpms-copy>کپی کد</button> <span role="status" aria-live="polite" data-cpms-copy-status></span></p>
						<p class="description">برای چیدمان، به محل درج کد در برگه/نوشته بروید. نام پزشک و نوبت‌های آزاد از Clinic انتخاب‌شده نمایش داده می‌شود؛ رزرو همچنان با اعتبارسنجی سمت سرور انجام می‌گیرد.</p>
					</section>
				<?php endforeach; ?>
			<?php elseif ( $clinic_id > 0 ) : ?>
				<div class="notice notice-info"><p>در این نسخه کد کوتاه CPMS ثبت‌شده‌ای برای نمایش وجود ندارد.</p></div>
			<?php endif; ?>
		</div>
		<script>
		(function () {
			var buttons = document.querySelectorAll('[data-cpms-copy]');
			for (var i = 0; i < buttons.length; i++) {
				buttons[i].addEventListener('click', function () {
					var card = this.closest('.card');
					var field = card ? card.querySelector('[data-cpms-shortcode]') : null;
					var status = card ? card.querySelector('[data-cpms-copy-status]') : null;
					if (!field || !status) { return; }
					field.focus();
					field.select();
					var copied = false;
					if (navigator.clipboard && window.isSecureContext) {
						navigator.clipboard.writeText(field.value).then(function () {
							status.textContent = 'کد کپی شد.';
						}, function () {
							status.textContent = 'کد انتخاب شده است؛ برای کپی از صفحه‌کلید استفاده کنید.';
						});
						return;
					}
					try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
					status.textContent = copied ? 'کد کپی شد.' : 'کد انتخاب شده است؛ برای کپی از صفحه‌کلید استفاده کنید.';
				});
			}
		})();
		</script>
		<?php
	}

	/** @return list<array{clinic_id:int, clinic_name:string}> */
	private static function authorized_clinic_choices( int $actor_user_id ): array {
		if ( $actor_user_id <= 0 ) {
			return array();
		}
		$choices = array();
		foreach ( App::membership_service()->active_memberships_for_user( $actor_user_id ) as $membership ) {
			$clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
			if ( $clinic_id <= 0 || ! App::authorization_service()->can( $actor_user_id, $clinic_id, RolesAndCapabilities::CONFIG ) ) {
				continue;
			}
			$choices[] = array(
				'clinic_id'   => $clinic_id,
				'clinic_name' => (string) ( $membership['clinic_name'] ?? ( 'Clinic #' . $clinic_id ) ),
			);
		}
		return $choices;
	}

	/** @return list<string> */
	private static function registered_cpms_shortcodes(): array {
		$known_cpms_tags = array( PublicBookingShortcode::TAG );
		$registered      = isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] )
			? array_keys( $GLOBALS['shortcode_tags'] )
			: array();
		return array_values( array_intersect( $known_cpms_tags, $registered ) );
	}
}

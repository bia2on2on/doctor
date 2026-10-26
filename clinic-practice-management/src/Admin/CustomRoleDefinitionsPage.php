<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;

/**
 * «نقش‌های سفارشی» — Clinic-local custom-role definition management
 * (Custom Role & Permission Management, Slice 2).
 *
 * امنیت (Product Decision 2026-10-10 + Slices 2/4A):
 *  - نقطهٔ ورود wp-admin فقط با capability عمومی `read` ثبت می‌شود تا مدیران
 *    Clinic-local بدون CONFIG وردپرس صفحه را بیابند؛ مشاهده و هر تغییر همچنان
 *    از TrustedClinicEstablisher + AuthorizationService همان Clinic عبور می‌کند.
 *  - Administrator وردپرس بدون عضویت فعال در آن Clinic → هیچ دسترسی‌ای ندارد
 *    (هیچ fallback «همهٔ Clinic» یا «Clinic اول» وجود ندارد).
 *  - انتخاب Clinic فقط selector است؛ مقدار ارسالی هرگز مدرک اعتماد نیست.
 *  - چک‌باکس‌ها فقط Capabilityهای «قابل‌تفویض برای همین کاربر» را نشان می‌دهند؛
 *    Backend مستقل از UI همان مجموعه را دوباره اعتبارسنجی می‌کند (all-or-nothing).
 *  - Nonce (CSRF) روی هر action، Sanitize روی هر ورودی، خروجی Escape‌شده.
 *  - کلید فنی نقش immutable است: فرم rename فقط نام نمایشی را تغییر می‌دهد.
 *  - هیچ Assign/Delete در این صفحه نیست؛ بایگانی پایانی از غیرفعال‌سازی
 *    برگشت‌پذیر جداست و فقط بدون ارجاع عضویت انجام می‌شود. هیچ نقش WordPress-global ثبت نمی‌شود.
 *    نام‌های نمایشی Unicode-عمومی‌اند (بدون قاعدهٔ
 *    فارسی‌محور) و متن‌ها با توابع ترجمهٔ وردپرس رندر می‌شوند.
 */
final class CustomRoleDefinitionsPage {

	public const PAGE_SLUG = 'cpms-custom-roles';

	private const NONCE_CREATE     = 'cpms_custom_role_create';
	private const NONCE_RENAME     = 'cpms_custom_role_rename';
	private const NONCE_CAPS       = 'cpms_custom_role_caps';
	private const NONCE_DEACTIVATE = 'cpms_custom_role_deactivate';
	private const NONCE_REACTIVATE = 'cpms_custom_role_reactivate';
	private const NONCE_ARCHIVE    = 'cpms_custom_role_archive';
	private const NOTICE_KEY       = 'cpms_custom_roles_notice';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_cpms_custom_role_create', [ self::class, 'handle_create' ] );
		add_action( 'admin_post_cpms_custom_role_rename', [ self::class, 'handle_rename' ] );
		add_action( 'admin_post_cpms_custom_role_caps', [ self::class, 'handle_replace_capabilities' ] );
		add_action( 'admin_post_cpms_custom_role_deactivate', [ self::class, 'handle_deactivate' ] );
		add_action( 'admin_post_cpms_custom_role_reactivate', [ self::class, 'handle_reactivate' ] );
		add_action( 'admin_post_cpms_custom_role_archive', [ self::class, 'handle_archive' ] );
	}

	public static function menu(): void {
		// B-1: منوی واحد. این صفحه با `read` ثبت می‌شود تا مدیرِ Clinic-scoped
		// (بدونِ cpms_config) به آن برسد؛ اما دیگر یک Top-Level مستقل نمی‌سازد.
		// مجوز واقعی همان `render()` + سرویس‌های Clinic-scoped است.
		add_submenu_page(
			CpmsAdminMenu::parent_slug(),
			__( 'نقش‌های سفارشی', 'cpms' ),
			__( 'نقش‌های سفارشی', 'cpms' ),
			'read',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
		// صفحهٔ مدیریتیِ پیشرفته: از ناوبری بخش‌ها و URL مستقیم در دسترس است.
		CpmsAdminMenu::hide_from_submenu( self::PAGE_SLUG );
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید', 'cpms' ), 403 );
		}

		$actor  = (int) get_current_user_id();
		$notice = self::pull_notice();

		// Selector is display context only; every read/write re-establishes the
		// trusted Clinic inside the service.
		$choices   = self::manageable_clinics( $actor );
		$clinic_id = self::selected_clinic_id( $choices );

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . esc_html__( 'نقش‌های سفارشی', 'cpms' ) . '</h1>';

		self::render_notice( $notice );

		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'نقش سفارشی مخصوص همین Clinic است؛ کلید فنی نقش پس از ساخت هرگز تغییر نمی‌کند و فقط نام نمایشی قابل ویرایش است.', 'cpms' ) . ' ';
		echo esc_html__( 'شما فقط می‌توانید مجوزهایی را بدهید که خودتان در همین Clinic دارید؛ مجوزهای حساس قابل واگذاری نیستند.', 'cpms' );
		echo '</p></div>';

		if ( [] === $choices ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'برای مدیریت نقش سفارشی، ابتدا باید عضویت فعالِ همین Clinic با مجوز پیکربندی (cpms_config) داشته باشید. وضعیت Administrator وردپرس به‌تنهایی مجوز Clinic ایجاد نمی‌کند.', 'cpms' );
			echo '</p></div></div>';

			return;
		}

		self::render_clinic_selector( $choices, $clinic_id );

		if ( $clinic_id <= 0 ) {
			echo '<p>' . esc_html__( 'Clinic معتبری انتخاب نشده است.', 'cpms' ) . '</p></div>';

			return;
		}

		try {
			$listing   = App::custom_role_management_service()->list_definitions( $actor, $clinic_id );
			$delegable = App::custom_role_management_service()->delegable_capabilities( $actor, $clinic_id );
		} catch ( ScopeRequiredException | AuthorizationException | CustomRoleManagementException $e ) {
			echo '<div class="notice notice-error inline"><p>';
			echo esc_html__( 'دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms' );
			echo '</p></div></div>';

			return;
		}

		self::render_list( $listing['definitions'], $clinic_id );

		if ( [] === $delegable ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'در حال حاضر هیچ مجوز قابل واگذاری ندارید؛ بنابراین ساخت یا ویرایش نقش سفارشی ممکن نیست.', 'cpms' );
			echo '</p></div>';
		} else {
			self::render_create_form( $clinic_id, $delegable );
			foreach ( $listing['definitions'] as $definition ) {
				if ( ! CustomRoleRepository::is_mutable_status( (string) $definition['status'] ) ) {
					continue;
				}
				self::render_edit_forms( $clinic_id, $definition, $delegable );
			}
		}

		echo '</div>';
	}

	public static function handle_create(): void {
		self::assert_entry();
		check_admin_referer( self::NONCE_CREATE );

		$clinic_id    = absint( wp_unslash( $_POST['cpms_clinic_id'] ?? 0 ) );
		$role_key     = sanitize_key( wp_unslash( (string) ( $_POST['role_key'] ?? '' ) ) );
		$display_name = sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) );
		$capabilities = self::sanitize_capability_input( wp_unslash( $_POST['capabilities'] ?? [] ) );

		try {
			$created = App::custom_role_management_service()->create(
				(int) get_current_user_id(),
				$clinic_id,
				$role_key,
				$display_name,
				$capabilities
			);
			self::set_notice(
				'success',
				sprintf(
					/* translators: 1: immutable technical role key, 2: custom role display name. */
					__( 'نقش سفارشی «%2$s» با کلید فنی %1$s ساخته شد.', 'cpms' ),
					$created['role_key'],
					$created['display_name']
				)
			);
		} catch ( CustomRoleManagementException $e ) {
			self::set_notice( 'error', self::describe_failure( $e ) );
		} catch ( ScopeRequiredException | AuthorizationException $e ) {
			self::set_notice( 'error', __( 'دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms' ) );
		} catch ( \RuntimeException $e ) {
			self::report_infrastructure_failure( $e, __FUNCTION__ );
		}

		self::redirect_back( $clinic_id );
	}

	public static function handle_rename(): void {
		self::assert_entry();
		check_admin_referer( self::NONCE_RENAME );

		$clinic_id        = absint( wp_unslash( $_POST['cpms_clinic_id'] ?? 0 ) );
		$role_key         = sanitize_key( wp_unslash( (string) ( $_POST['role_key'] ?? '' ) ) );
		$display_name     = sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) );
		$expected_version = absint( wp_unslash( $_POST['version'] ?? 0 ) );

		try {
			$renamed = App::custom_role_management_service()->rename(
				(int) get_current_user_id(),
				$clinic_id,
				$role_key,
				$display_name,
				$expected_version
			);
			self::set_notice(
				'success',
				sprintf(
					/* translators: 1: immutable technical role key, 2: new custom role display name. */
					__( 'نام نمایشی نقش %1$s به «%2$s» تغییر کرد (کلید فنی بدون تغییر).', 'cpms' ),
					$renamed['role_key'],
					$renamed['display_name']
				)
			);
		} catch ( CustomRoleManagementException $e ) {
			self::set_notice( 'error', self::describe_failure( $e ) );
		} catch ( ScopeRequiredException | AuthorizationException $e ) {
			self::set_notice( 'error', __( 'دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms' ) );
		} catch ( \RuntimeException $e ) {
			self::report_infrastructure_failure( $e, __FUNCTION__ );
		}

		self::redirect_back( $clinic_id );
	}

	public static function handle_replace_capabilities(): void {
		self::assert_entry();
		check_admin_referer( self::NONCE_CAPS );

		$clinic_id        = absint( wp_unslash( $_POST['cpms_clinic_id'] ?? 0 ) );
		$role_key         = sanitize_key( wp_unslash( (string) ( $_POST['role_key'] ?? '' ) ) );
		$capabilities     = self::sanitize_capability_input( wp_unslash( $_POST['capabilities'] ?? [] ) );
		$expected_version = absint( wp_unslash( $_POST['version'] ?? 0 ) );

		try {
			$updated = App::custom_role_management_service()->replace_capabilities(
				(int) get_current_user_id(),
				$clinic_id,
				$role_key,
				$capabilities,
				$expected_version
			);
			self::set_notice(
				'success',
				sprintf(
					/* translators: 1: immutable technical role key, 2: durable revision number. */
					__( 'مجوزهای نقش %1$s ذخیره شد (نسخه %2$d).', 'cpms' ),
					$updated['role_key'],
					$updated['version']
				)
			);
		} catch ( CustomRoleManagementException $e ) {
			self::set_notice( 'error', self::describe_failure( $e ) );
		} catch ( ScopeRequiredException | AuthorizationException $e ) {
			self::set_notice( 'error', __( 'دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms' ) );
		} catch ( \RuntimeException $e ) {
			self::report_infrastructure_failure( $e, __FUNCTION__ );
		}

		self::redirect_back( $clinic_id );
	}

	public static function handle_deactivate(): void {
		self::handle_status_change( false );
	}

	public static function handle_reactivate(): void {
		self::handle_status_change( true );
	}

	public static function handle_archive(): void {
		self::assert_entry();
		check_admin_referer( self::NONCE_ARCHIVE );

		$clinic_id        = isset( $_POST['cpms_clinic_id'] ) && is_scalar( $_POST['cpms_clinic_id'] )
			? absint( wp_unslash( (string) $_POST['cpms_clinic_id'] ) ) : 0;
		$role_key         = isset( $_POST['role_key'] ) && is_string( $_POST['role_key'] )
			? sanitize_key( wp_unslash( $_POST['role_key'] ) ) : '';
		$version          = isset( $_POST['version'] ) && is_string( $_POST['version'] )
			? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$expected_version = ctype_digit( $version ) ? (int) $version : 0;
		$confirmed        = isset( $_POST['confirm_archive'] ) && is_string( $_POST['confirm_archive'] )
			&& sanitize_text_field( wp_unslash( $_POST['confirm_archive'] ) ) === '1';

		if ( ! $confirmed ) {
			self::set_notice( 'error', __( 'بایگانی برگشت‌پذیر نیست؛ ابتدا پیامدهای آن را تأیید کنید. هیچ تغییری ثبت نشد.', 'cpms' ) );
			self::redirect_back( $clinic_id );
		}

		try {
			$result = App::custom_role_management_service()->archive( (int) get_current_user_id(), $clinic_id, $role_key, $expected_version );
			self::set_notice(
				'success',
				$result['changed']
					? __( 'نقش برای همیشه بایگانی شد؛ تعریف و کلید محفوظ‌اند و هیچ عضوی جابه‌جا یا فعال نشده است.', 'cpms' )
					: __( 'نقش از قبل بایگانی بود؛ تغییر یا رویداد Audit دیگری ثبت نشد.', 'cpms' )
			);
		} catch ( CustomRoleManagementException $e ) {
			self::set_notice( 'error', self::describe_failure( $e ) );
		} catch ( ScopeRequiredException | AuthorizationException $e ) {
			self::set_notice( 'error', __( 'بایگانی نقش در این Clinic برای شما مجاز نیست.', 'cpms' ) );
		} catch ( \RuntimeException $e ) {
			self::report_infrastructure_failure( $e, __FUNCTION__ );
		}

		self::redirect_back( $clinic_id );
	}

	private static function handle_status_change( bool $is_reactivate ): void {
		self::assert_entry();
		$nonce_action = $is_reactivate ? self::NONCE_REACTIVATE : self::NONCE_DEACTIVATE;
		check_admin_referer( $nonce_action );

		$clinic_id        = absint( wp_unslash( $_POST['cpms_clinic_id'] ?? 0 ) );
		$role_key         = sanitize_key( wp_unslash( (string) ( $_POST['role_key'] ?? '' ) ) );
		$expected_version = absint( wp_unslash( $_POST['version'] ?? 0 ) );

		try {
			$service = App::custom_role_management_service();
			$result  = $is_reactivate
				? $service->reactivate( (int) get_current_user_id(), $clinic_id, $role_key, $expected_version )
				: $service->deactivate( (int) get_current_user_id(), $clinic_id, $role_key, $expected_version );
			if ( $result['changed'] ) {
				$message = $is_reactivate
					? __( 'نقش دوباره فعال شد؛ اختیار و حدود واگذاری فعلی بررسی شد و تخصیص‌های موجود حفظ شده‌اند.', 'cpms' )
					: __( 'نقش غیرفعال شد؛ تعریف و تخصیص‌های موجود و مجوزهای GRANT/DENY عضویت حفظ شده‌اند.', 'cpms' );
				self::set_notice(
					'success',
					$message . ' ' . sprintf(
						/* translators: %d: new custom-role definition version. */
						__( 'نسخه: %d.', 'cpms' ),
						$result['version']
					)
				);
			} else {
				self::set_notice( 'success', __( 'وضعیت نقش از قبل همین بود؛ تغییری ثبت نشد.', 'cpms' ) );
			}
		} catch ( CustomRoleManagementException $e ) {
			self::set_notice( 'error', self::describe_failure( $e ) );
		} catch ( ScopeRequiredException | AuthorizationException $e ) {
			self::set_notice( 'error', __( 'دسترسی مدیریت نقش سفارشی برای این Clinic مجاز نیست.', 'cpms' ) );
		} catch ( \RuntimeException $e ) {
			self::report_infrastructure_failure( $e, __FUNCTION__ );
		}

		self::redirect_back( $clinic_id );
	}

	// ================= view helpers =================

	/**
	 * @param list<array{clinic_id:int, clinic_name:string}> $choices
	 */
	private static function render_clinic_selector( array $choices, int $clinic_id ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<label for="cpms-custom-role-clinic">' . esc_html__( 'Clinic', 'cpms' ) . '</label> ';
		echo '<select name="cpms_clinic_id" id="cpms-custom-role-clinic" required>';
		echo '<option value="" ' . selected( 0, $clinic_id, false ) . '>' . esc_html__( '— انتخاب Clinic —', 'cpms' ) . '</option>';

		foreach ( $choices as $choice ) {
			echo '<option value="' . esc_attr( (string) $choice['clinic_id'] ) . '" ' . selected( $clinic_id, $choice['clinic_id'], false ) . '>';
			echo esc_html( $choice['clinic_name'] . ' (#' . $choice['clinic_id'] . ')' );
			echo '</option>';
		}

		echo '</select> <button type="submit" class="button">' . esc_html__( 'نمایش', 'cpms' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param list<array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, updated_at:string, capabilities:list<string>}> $definitions
	 */
	private static function render_list( array $definitions, int $clinic_id ): void {
		echo '<h2 class="title">' . esc_html__( 'نقش‌های سفارشی این Clinic', 'cpms' ) . '</h2>';

		if ( [] === $definitions ) {
			echo '<p>' . esc_html__( 'هنوز نقش سفارشی‌ای برای این Clinic تعریف نشده است.', 'cpms' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'نام نمایشی', 'cpms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'کلید فنی (تغییرناپذیر)', 'cpms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'وضعیت', 'cpms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'نسخه', 'cpms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'مجوزها', 'cpms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'وضعیت نقش', 'cpms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $definitions as $definition ) {
			$label = ( null !== $definition['display_name'] && '' !== $definition['display_name'] )
				? $definition['display_name']
				: $definition['role_key'];

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td><code dir="ltr">' . esc_html( $definition['role_key'] ) . '</code></td>';
			$status_label = match ( $definition['status'] ) {
				CustomRoleRepository::STATUS_ACTIVE   => __( 'فعال', 'cpms' ),
				CustomRoleRepository::STATUS_INACTIVE => __( 'غیرفعال', 'cpms' ),
				CustomRoleRepository::STATUS_ARCHIVED => __( 'بایگانی‌شده (پایانی)', 'cpms' ),
				default                               => __( 'وضعیت ناشناخته — فقط خواندنی', 'cpms' ),
			};
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '<td>' . esc_html( (string) $definition['version'] ) . '</td>';
			echo '<td>';
			echo ( [] === $definition['capabilities'] )
				? esc_html__( 'بدون مجوز', 'cpms' )
				: esc_html( implode( '، ', $definition['capabilities'] ) );
			echo '</td>';
			echo '<td>';
			self::render_lifecycle_form( $clinic_id, $definition );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, capabilities:list<string>} $definition
	 */
	private static function render_lifecycle_form( int $clinic_id, array $definition ): void {
		if ( ! CustomRoleRepository::is_mutable_status( (string) $definition['status'] ) ) {
			echo '<p class="description">' . esc_html__( 'فقط خواندنی؛ کلید محفوظ است و این تعریف قابل انتساب، ویرایش یا فعال‌سازی نیست.', 'cpms' ) . '</p>';

			return;
		}
		$is_active    = 'active' === $definition['status'];
		$action       = $is_active ? 'cpms_custom_role_deactivate' : 'cpms_custom_role_reactivate';
		$nonce        = $is_active ? self::NONCE_DEACTIVATE : self::NONCE_REACTIVATE;
		$explanation  = $is_active
			? __( 'با غیرفعال‌سازی، این تعریف و تخصیص‌های موجود حفظ می‌شوند اما نقش دیگر هیچ اختیار مشتق‌شده‌ای نمی‌دهد؛ GRANT/DENY عضویت تغییر نمی‌کند.', 'cpms' )
			: __( 'با فعال‌سازی دوباره، اختیار و حدود واگذاری فعلی بررسی می‌شود؛ تخصیص‌ها و GRANT/DENY عضویت حفظ شده‌اند.', 'cpms' );
		$confirmation = $is_active
			? __( 'این نقش غیرفعال می‌شود؛ تعریف، تخصیص‌ها و GRANT/DENY عضویت حذف نمی‌شوند. ادامه می‌دهید؟', 'cpms' )
			: __( 'این نقش دوباره فعال می‌شود و مجوزهای آن می‌توانند به اعضای فعلی بازگردند. ادامه می‌دهید؟', 'cpms' );
		$button_label = $is_active ? __( 'غیرفعال‌سازی', 'cpms' ) : __( 'فعال‌سازی دوباره', 'cpms' );

		echo '<p class="description">' . esc_html( $explanation ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="cpms_clinic_id" value="' . esc_attr( (string) $clinic_id ) . '">';
		echo '<input type="hidden" name="role_key" value="' . esc_attr( $definition['role_key'] ) . '">';
		echo '<input type="hidden" name="version" value="' . esc_attr( (string) $definition['version'] ) . '">';
		wp_nonce_field( $nonce );
		$onclick = 'return confirm(' . wp_json_encode( $confirmation ) . ');';
		echo '<button type="submit" class="button" onclick="' . esc_attr( $onclick ) . '">' . esc_html( $button_label ) . '</button>';
		echo '</form>';
		self::render_archive_form( $clinic_id, $definition );
	}

	/** @param array{role_key:string,version:int} $definition */
	private static function render_archive_form( int $clinic_id, array $definition ): void {
		$explanation = __( 'بایگانی پایانی و برگشت‌ناپذیر است؛ تعریف و کلید محفوظ می‌مانند اما قابل ویرایش، انتساب یا فعال‌سازی نیستند. ابتدا همهٔ اعضای این Clinic، حتی اعضای معلق، باید صریحاً نقش دیگری بگیرند.', 'cpms' );
		echo '<p class="description">' . esc_html( $explanation ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cpms_custom_role_archive">';
		echo '<input type="hidden" name="cpms_clinic_id" value="' . esc_attr( (string) $clinic_id ) . '">';
		echo '<input type="hidden" name="role_key" value="' . esc_attr( $definition['role_key'] ) . '">';
		echo '<input type="hidden" name="version" value="' . esc_attr( (string) $definition['version'] ) . '">';
		wp_nonce_field( self::NONCE_ARCHIVE );
		echo '<label><input type="checkbox" name="confirm_archive" value="1" required> ' . esc_html__( 'پیامدهای برگشت‌ناپذیر بایگانی را می‌پذیرم.', 'cpms' ) . '</label> ';
		$onclick = 'return confirm(' . wp_json_encode( $explanation ) . ');';
		echo '<button type="submit" class="button" onclick="' . esc_attr( $onclick ) . '">' . esc_html__( 'بایگانی دائمی', 'cpms' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param list<string> $delegable
	 */
	private static function render_create_form( int $clinic_id, array $delegable ): void {
		echo '<h2 class="title">' . esc_html__( 'تعریف نقش سفارشی جدید', 'cpms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cpms_custom_role_create">';
		echo '<input type="hidden" name="cpms_clinic_id" value="' . esc_attr( (string) $clinic_id ) . '">';
		wp_nonce_field( self::NONCE_CREATE );

		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th scope="row"><label for="cpms-custom-role-key">' . esc_html__( 'کلید فنی نقش', 'cpms' ) . '</label></th>';
		echo '<td><input name="role_key" id="cpms-custom-role-key" type="text" dir="ltr" required pattern="[a-z][a-z0-9_]{1,63}" value="" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'حروف کوچک لاتین، رقم و زیرخط؛ با حرف شروع می‌شود (۲ تا ۶۴ کاراکتر). این کلید پس از ساخت تغییر نمی‌کند و با نقش‌های داخلی CPMS تداخل ندارد.', 'cpms' ) . '</p>';
		echo '</td></tr><tr>';
		echo '<th scope="row"><label for="cpms-custom-role-name">' . esc_html__( 'نام نمایشی', 'cpms' ) . '</label></th>';
		echo '<td><input name="display_name" id="cpms-custom-role-name" type="text" required maxlength="' . esc_attr( (string) CustomRolePolicy::DISPLAY_NAME_MAX_CODE_POINTS ) . '" value="" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'هر زبانی مجاز است (فارسی، لاتین، عربی، CJK و …). حداکثر ۱۲۰ کاراکتر.', 'cpms' ) . '</p>';
		echo '</td></tr></table>';

		self::render_capability_checkboxes( 'capabilities', [], $delegable );

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'ساخت نقش سفارشی', 'cpms' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, capabilities:list<string>} $definition
	 * @param list<string> $delegable
	 */
	private static function render_edit_forms( int $clinic_id, array $definition, array $delegable ): void {
		$role_key = $definition['role_key'];
		$label    = ( null !== $definition['display_name'] && '' !== $definition['display_name'] )
			? $definition['display_name']
			: $role_key;

		echo '<h2 class="title">' . esc_html( $label ) . ' <code dir="ltr">' . esc_html( $role_key ) . '</code></h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:10px">';
		echo '<input type="hidden" name="action" value="cpms_custom_role_rename">';
		echo '<input type="hidden" name="cpms_clinic_id" value="' . esc_attr( (string) $clinic_id ) . '">';
		echo '<input type="hidden" name="role_key" value="' . esc_attr( $role_key ) . '">';
		echo '<input type="hidden" name="version" value="' . esc_attr( (string) $definition['version'] ) . '">';
		wp_nonce_field( self::NONCE_RENAME );
		echo '<label for="cpms-rename-' . esc_attr( $role_key ) . '">' . esc_html__( 'ویرایش نام نمایشی', 'cpms' ) . '</label> ';
		echo '<input name="display_name" id="cpms-rename-' . esc_attr( $role_key ) . '" type="text" required maxlength="' . esc_attr( (string) CustomRolePolicy::DISPLAY_NAME_MAX_CODE_POINTS ) . '" value="' . esc_attr( (string) ( $definition['display_name'] ?? '' ) ) . '" class="regular-text"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'ذخیره نام', 'cpms' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cpms_custom_role_caps">';
		echo '<input type="hidden" name="cpms_clinic_id" value="' . esc_attr( (string) $clinic_id ) . '">';
		echo '<input type="hidden" name="role_key" value="' . esc_attr( $role_key ) . '">';
		echo '<input type="hidden" name="version" value="' . esc_attr( (string) $definition['version'] ) . '">';
		wp_nonce_field( self::NONCE_CAPS );

		self::render_capability_checkboxes( 'capabilities', $definition['capabilities'], $delegable );

		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'ذخیره مجوزهای مجاز', 'cpms' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Renders ONLY the capabilities the current actor may delegate in this
	 * Clinic. The backend re-validates the same set independently, so tampering
	 * with the form cannot widen it.
	 *
	 * @param list<string> $selected
	 * @param list<string> $delegable
	 */
	private static function render_capability_checkboxes( string $field_name, array $selected, array $delegable ): void {
		echo '<fieldset>';
		echo '<legend>' . esc_html__( 'مجوزهای قابل واگذاری (بر پایه اختیار مؤثر شما در این Clinic)', 'cpms' ) . '</legend>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 16px">';

		foreach ( $delegable as $capability ) {
			echo '<label>';
			echo '<input type="checkbox" name="' . esc_attr( $field_name ) . '[]" value="' . esc_attr( $capability ) . '" ' . checked( in_array( $capability, $selected, true ), true, false ) . '> ';
			echo '<code dir="ltr">' . esc_html( $capability ) . '</code>';
			echo '</label>';
		}

		echo '</div></fieldset>';
	}

	/**
	 * Non-verbose failure description. Domain reason codes are mapped to
	 * translated messages; raw request values are never echoed back.
	 */
	private static function describe_failure( CustomRoleManagementException $e ): string {
		switch ( $e->reason_code() ) {
			case CustomRoleManagementException::CODE_INVALID_ROLE_KEY:
				return __( 'کلید فنی نقش معتبر نیست (حروف کوچک لاتین، شروع با حرف، ۲ تا ۶۴ کاراکتر، بدون تداخل با نقش‌های داخلی).', 'cpms' );
			case CustomRoleManagementException::CODE_INVALID_DISPLAY_NAME:
				return __( 'نام نمایشی معتبر نیست: نباید خالی باشد، باید حداکثر ۱۲۰ کاراکتر باشد و حداقل یک حرف یا رقم داشته باشد.', 'cpms' );
			case CustomRoleManagementException::CODE_CAPABILITY_NOT_ALLOWED:
				return __( 'یکی از مجوزهای انتخابی در فهرست مجاز نیست (مجوز حساس، وردپرس، ووکامرس یا ناشناخته حذف شد و ذخیره انجام نشد).', 'cpms' );
			case CustomRoleManagementException::CODE_CAPABILITY_NOT_DELEGABLE:
				return __( 'یکی از مجوزهای انتخابی خارج از اختیار مؤثر شما در این Clinic است؛ هیچ تغییری ذخیره نشد.', 'cpms' );
			case CustomRoleManagementException::CODE_KEY_CONFLICT:
				return __( 'این کلید فنی در همین Clinic قبلاً تعریف شده است.', 'cpms' );
			case CustomRoleManagementException::CODE_MEMBERSHIP_REFERENCES:
				return __( 'بایگانی انجام نشد: هنوز عضویت‌هایی در همین Clinic این نقش را دارند، حتی اگر معلق باشند. ابتدا نقش همه را صریحاً تغییر دهید؛ هیچ جابه‌جایی خودکاری انجام نمی‌شود.', 'cpms' );
			case CustomRoleManagementException::CODE_TERMINAL_STATE:
				return __( 'این نقش بایگانی شده و پایانی است؛ ویرایش یا فعال‌سازی آن مجاز نیست.', 'cpms' );
			case CustomRoleManagementException::CODE_INVALID_STATE:
				return __( 'وضعیت ذخیره‌شدهٔ نقش شناخته‌شده نیست؛ هیچ تغییری مجاز نیست.', 'cpms' );
			case CustomRoleManagementException::CODE_STALE_VERSION:
				return __( 'این نقش هم‌زمان تغییر کرده است؛ صفحه را تازه کنید و دوباره تلاش کنید.', 'cpms' );
			default:
				return __( 'نقش سفارشی موردنظر در این Clinic یافت نشد.', 'cpms' );
		}
	}

	// ================= request helpers =================

	private static function assert_entry(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید', 'cpms' ), 403 );
		}
	}

	/**
	 * @return list<array{clinic_id:int, clinic_name:string}>
	 */
	private static function manageable_clinics( int $actor_wp_user_id ): array {
		if ( $actor_wp_user_id <= 0 ) {
			return [];
		}

		$service = App::custom_role_management_service();
		$choices = [];
		foreach ( App::membership_service()->active_memberships_for_user( $actor_wp_user_id ) as $membership ) {
			$clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
			if ( $clinic_id <= 0 || ! $service->can_manage( $actor_wp_user_id, $clinic_id ) ) {
				continue;
			}
			$choices[] = [
				'clinic_id'   => $clinic_id,
				'clinic_name' => (string) ( $membership['clinic_name'] ?? ( 'Clinic #' . $clinic_id ) ),
			];
		}

		return $choices;
	}

	/**
	 * Display-only selector value: restricted to Clinics the actor may actually
	 * manage, so an arbitrary value cannot even be selected in the UI. An empty,
	 * missing, or unauthorized selector stays empty; no sole-membership/first-row
	 * fallback is allowed. The service still re-validates it against persistence on every use.
	 *
	 * @param list<array{clinic_id:int, clinic_name:string}> $choices
	 */
	private static function selected_clinic_id( array $choices ): int {
		if ( [] === $choices ) {
			return 0;
		}

		$requested = self::requested_clinic_id();
		foreach ( $choices as $choice ) {
			if ( $choice['clinic_id'] === $requested ) {
				return $requested;
			}
		}

		return 0;
	}

	private static function requested_clinic_id(): int {
		// Read-only display filter: no state change, therefore no nonce applies.
		if ( ! isset( $_GET['cpms_clinic_id'] ) || ! is_scalar( $_GET['cpms_clinic_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}

		return absint( wp_unslash( (string) $_GET['cpms_clinic_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Normalizes the submitted capability checkboxes. Receives the already
	 * unslashed request value from a handler that verified the nonce and the
	 * capability gate first; every element is sanitized here and the full set is
	 * strictly allowlist-validated by the management service afterwards.
	 *
	 * @param mixed $submitted
	 *
	 * @return list<string>
	 */
	private static function sanitize_capability_input( mixed $submitted ): array {
		if ( ! is_array( $submitted ) ) {
			return [];
		}

		$clean = [];
		foreach ( $submitted as $candidate ) {
			if ( is_string( $candidate ) ) {
				$clean[] = sanitize_key( $candidate );
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private static function redirect_back( int $clinic_id ): void {
		$args = [ 'page' => self::PAGE_SLUG ];
		if ( $clinic_id > 0 ) {
			$args['cpms_clinic_id'] = $clinic_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function set_notice( string $type, string $message ): void {
		set_transient( self::NOTICE_KEY . '_' . (int) get_current_user_id(), [ 'type' => $type, 'message' => $message ], 60 );
	}

	/**
	 * بن‌بستِ زیرساختی (مثلاً ثبت‌نشدن رویداد Audit): هیچ تغییری commit نشده و
	 * پیام موفقیت نباید نمایش داده شود. رویداد برای Ops لاگ می‌شود (بدون دادهٔ
	 * درخواست/PHI) و کاربر پیام قطعی می‌بیند — نه یک خطای مرگبار نیمه‌کاره.
	 */
	private static function report_infrastructure_failure( \RuntimeException $failure, string $handler ): void {
		App::op()->error(
			'CUSTOM_ROLE_MUTATION_FAILED',
			[
				'handler'   => $handler,
				'exception' => get_class( $failure ),
			]
		);
		self::set_notice( 'error', __( 'عملیات انجام نشد و هیچ تغییری ثبت نگردید. لطفاً دوباره تلاش کنید.', 'cpms' ) );
	}

	/**
	 * @return array{type:string, message:string}|null
	 */
	private static function pull_notice(): ?array {
		$key    = self::NOTICE_KEY . '_' . (int) get_current_user_id();
		$notice = get_transient( $key );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['message'] ) || ! is_string( $notice['message'] ) ) {
			return null;
		}

		return [
			'type'    => ( isset( $notice['type'] ) && 'success' === $notice['type'] ) ? 'success' : 'error',
			'message' => $notice['message'],
		];
	}

	/**
	 * @param array{type:string, message:string}|null $notice
	 */
	private static function render_notice( ?array $notice ): void {
		if ( null === $notice ) {
			return;
		}
		$class = ( 'success' === $notice['type'] ) ? 'notice notice-success is-dismissible' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}

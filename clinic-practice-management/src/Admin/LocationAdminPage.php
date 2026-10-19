<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Location\LocationException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * مدیریت محلیِ Location: ایجاد، ویرایش profile و lifecycle، با context صریح Clinic.
 *
 * طراحی امن (هم‌راستا با StaffManagementPage / ClinicProfileService):
 *  - `cpms_config` فقط گیت نمایش/ورود wp-admin است؛ جایگزین مجوز Clinic نیست.
 *    هر write از TrustedClinicEstablisher + AuthorizationService (CONFIG scoped)
 *    عبور می‌کند و Clinic هدف از persistence احراز می‌شود — clinic_id ارسالی
 *    فقط selector است و هرگز authority نیست.
 *  - Nonce (CSRF) + authenticated actor روی هر action مستقل از بقیه گیت‌هاست.
 *  - timezone شعبه حقیقت عملیاتی است: فقط شناسهٔ معتبر IANA پذیرفته می‌شود،
 *    بدون fallback و بدون sync با cpms_clinics.timezone. تغییر آن فقط
 *    forward-looking است و هیچ ردیف تاریخی را بازنویسی نمی‌کند.
 *  - Location اصلی (is_primary) هرگز غیرفعال/حذف/تبدیل نمی‌شود؛ تغییر
 *    وضعیت فقط برای Location غیراصلی و با نگه‌داشتن ردیف‌های تاریخی است.
 *  - Clinic با Location فرق دارد: این صفحه فقط یک Location دیگر را داخل Clinic موجود می‌سازد؛ ساخت Clinic زیر Organization مسیر جداگانه دارد.
 *  - انتخاب Clinic باید صریح باشد؛ وقتی selector خالی/ناموجود است هیچ Clinicای حدس زده نمی‌شود.
 *  - UPDATE فقط name/address/phone/timezone را تغییر می‌دهد؛ slug/مالک/وضعیت/created_at ثابت می‌مانند.
 *  - Audit هش‌زنجیره‌ای و writeها در یک تراکنش‌اند؛ شکست Audit همهٔ تغییرات را rollback می‌کند.
 *  - Location ناموجود و خارجی پیام یکسان می‌گیرند (enumeration parity).
 */
final class LocationAdminPage
{
    public const PAGE_SLUG = 'cpms-locations';

    private const NONCE_ACTION = 'cpms_location_save';
    private const LIFECYCLE_NONCE_ACTION = 'cpms_location_lifecycle';
    private const NOTICE_KEY = 'cpms_location_notice';

    public static function register(): void
    {
        add_action( 'admin_menu', [self::class, 'menu'] );
        add_action( 'admin_post_cpms_location_save', [self::class, 'save'] );
        add_action( 'admin_post_cpms_location_lifecycle', [self::class, 'save_lifecycle'] );
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'شعبه‌ها و محل‌ها',
            'شعبه‌ها',
            RolesAndCapabilities::CONFIG,
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if ( ! current_user_can( RolesAndCapabilities::CONFIG ) ) {
            wp_die( 'دسترسی ندارید', 403 );
        }

        $notice = get_transient( self::NOTICE_KEY );
        if ( $notice !== false ) {
            delete_transient( self::NOTICE_KEY );
        }

        $clinic_id      = self::formClinicId();
        $rows           = $clinic_id > 0 ? App::locationRepository()->listForClinic( $clinic_id ) : [];
        $edit           = self::edit_target( $clinic_id );
        $clinic_choices = self::managed_clinic_choices( (int) get_current_user_id() );
        ?>
        <div class="wrap" dir="rtl">
            <h1>شعبه‌ها و محل‌ها</h1>
            <p class="description">این صفحه فقط برای افزودن و ویرایش یک <strong>Location دیگر داخل Clinic انتخاب‌شده</strong> است؛ ساخت Clinic جدید زیر Organization از <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-clinic-management' ) ); ?>">مدیریت Clinicها</a> انجام می‌شود. Locationهای غیراصلی را می‌توان بدون حذف سابقه غیرفعال/فعال کرد؛ شعبهٔ اصلی همواره فعال می‌ماند. منطقهٔ زمانی فقط روی آینده اثر دارد و نوبت‌ها، ویزیت‌ها، پرونده‌ها و مالی تاریخی را بازنویسی نمی‌کند.</p>
            <?php if ( $clinic_id > 0 ) : ?>
                <p class="description">نقش‌ها را در <a href="<?php echo esc_url( admin_url( 'admin.php?page=cpms-staff&clinic_id=' . $clinic_id . '#u_role' ) ); ?>">مدیریت کاربران و دسترسی‌های همین Clinic</a> تعیین کنید. نقش عضویت و GRANT/DENY Clinic-local است؛ این صفحه مجوز یا دسترسی پیش‌فرضِ Location/Clinical/Financial/PHI ایجاد نمی‌کند.</p>
            <?php else : ?>
                <div class="notice notice-info"><p>برای ادامه، Clinic را صریحاً از فهرست انتخاب کنید؛ هیچ Clinic پیش‌فرضی حدس زده نمی‌شود.</p></div>
            <?php endif; ?>

            <?php if ( $clinic_choices !== [] ) : ?>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="cpms-autofocus">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                    <label for="cpms_location_clinic">Clinic هدف</label>
                    <select id="cpms_location_clinic" name="clinic_id" required>
                        <option value="">انتخاب Clinic</option>
                        <?php foreach ( $clinic_choices as $choice ) : ?>
                            <option value="<?php echo (int) $choice['clinic_id']; ?>" <?php selected( $clinic_id, (int) $choice['clinic_id'] ); ?>><?php echo esc_html( (string) $choice['clinic_name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">اعمال Clinic</button>
                    <p class="description">انتخاب فقط context است؛ سرور در هر write عضویت فعال و مجوز Clinic-scoped را دوباره بررسی می‌کند.</p>
                </form>
            <?php else : ?>
                <div class="notice notice-error"><p>برای مدیریت شعبه‌ها، عضویت فعال و مجوز Clinic-scoped در یک Clinic لازم است.</p></div>
            <?php endif; ?>

            <?php if ( is_string( $notice ) && $notice !== '' ) : ?>
                <div class="notice <?php echo str_starts_with( $notice, 'خطا' ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <?php if ( $rows !== [] ) : ?>
                <h2>فهرست شعبه‌ها</h2>
                <table class="widefat striped cpms-table-responsive" role="presentation">
                    <thead>
                        <tr><th>نام</th><th>شناسه (slug)</th><th>آدرس</th><th>تلفن</th><th>منطقهٔ زمانی</th><th>اصلی</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td data-label="نام"><?php echo esc_html( (string) $r['name'] ); ?></td>
                            <td data-label="شناسه"><span class="ltr"><?php echo esc_html( (string) $r['slug'] ); ?></span></td>
                            <td data-label="آدرس"><?php echo esc_html( '' !== (string) ( $r['address'] ?? '' ) ? (string) $r['address'] : '—' ); ?></td>
                            <td data-label="تلفن"><span class="ltr"><?php echo esc_html( '' !== (string) ( $r['phone'] ?? '' ) ? (string) $r['phone'] : '—' ); ?></span></td>
                            <td data-label="منطقهٔ زمانی"><span class="ltr"><?php echo esc_html( (string) $r['timezone'] ); ?></span></td>
                            <td data-label="اصلی"><?php echo (int) $r['is_primary'] === 1 ? '<span class="cpms-badge cpms-ok">اصلی</span>' : '—'; ?></td>
                            <td data-label="وضعیت"><?php echo (int) $r['is_active'] === 1 ? '<span class="cpms-badge cpms-ok">فعال</span>' : '<span class="cpms-badge cpms-danger">غیرفعال</span>'; ?></td>
                            <td class="cpms-actions-cell" data-label="عملیات">
                                <?php if ( (int) $r['is_primary'] !== 1 ) : ?>
                                    <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&edit=' . (int) $r['id'] . '&clinic_id=' . $clinic_id ) ); ?>">ویرایش Location</a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cpms-inline-form">
                                        <?php wp_nonce_field( self::LIFECYCLE_NONCE_ACTION ); ?>
                                        <input type="hidden" name="action" value="cpms_location_lifecycle">
                                        <input type="hidden" name="clinic_id" value="<?php echo (int) $clinic_id; ?>">
                                        <input type="hidden" name="location_id" value="<?php echo (int) $r['id']; ?>">
                                        <input type="hidden" name="active" value="<?php echo (int) $r['is_active'] === 1 ? '0' : '1'; ?>">
                                        <button type="submit" class="button button-small"><?php echo (int) $r['is_active'] === 1 ? 'غیرفعال‌سازی' : 'فعال‌سازی'; ?></button>
                                    </form>
                                <?php else : ?>
                                    <span>شعبهٔ اصلی همواره فعال است.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ( $clinic_id > 0 ) : ?>
                <?php echo wp_kses_post( CpmsUi::emptyState( '🏢', 'فقط شعبهٔ اصلی وجود دارد', 'شعبه‌های این Clinic این‌جا فهرست می‌شوند. شعبهٔ اصلی توسط راه‌اندازی ساخته شده است و از این صفحه قابل ویرایش نیست.', 'افزودن شعبه', admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id ) ) ); ?>
            <?php endif; ?>

            <?php if ( $clinic_id > 0 ) : ?>
                <h2>افزودن شعبهٔ جدید</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                    <input type="hidden" name="action" value="cpms_location_save">
                    <input type="hidden" name="mode" value="create">
                    <input type="hidden" name="clinic_id" value="<?php echo (int) $clinic_id; ?>">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="loc_name">نام شعبه</label></th><td><input type="text" id="loc_name" name="name" class="regular-text" maxlength="190" required value=""></td></tr>
                        <tr><th><label for="loc_address">آدرس</label></th><td><?php AddressSelectorField::render( '', 'loc_address', 'address_province', 'address_city', 'loc_address' ); ?><input type="text" id="loc_address" name="address" class="regular-text" maxlength="255" value=""></td></tr>
                        <tr><th><label for="loc_phone">تلفن</label></th><td><input type="text" id="loc_phone" name="phone" class="regular-text ltr" maxlength="32" value=""></td></tr>
                        <tr><th><label for="loc_slug">شناسه (slug)</label></th>
                            <td>
                                <input type="text" id="loc_slug" name="slug" class="regular-text" required value="">
                                <p class="description">حروف انگلیسی کوچک، عدد، خط تیره و زیرخط — یکتا در همین Clinic.</p>
                            </td>
                        </tr>
                        <tr><th><label for="loc_tz">منطقهٔ زمانی</label></th>
                            <td>
                                <input type="text" id="loc_tz" name="timezone" class="regular-text ltr" required value="" placeholder="Asia/Tehran">
                                <p class="description">شناسهٔ معتبر IANA (مثل Asia/Tehran یا Europe/Berlin). مقدار نامعتبر رد می‌شود و مقدار پیش‌فرضی جایگزین نمی‌شود.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button-primary">افزودن شعبه</button></p>
                </form>
            <?php endif; ?>

            <?php if ( $edit !== null ) : ?>
                <h2>ویرایش شعبه</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                    <input type="hidden" name="action" value="cpms_location_save">
                    <input type="hidden" name="mode" value="update">
                    <input type="hidden" name="clinic_id" value="<?php echo (int) $clinic_id; ?>">
                    <input type="hidden" name="location_id" value="<?php echo (int) $edit['id']; ?>">
                    <table class="form-table" role="presentation">
                        <tr><th>شناسه (slug)</th><td><span class="ltr"><?php echo esc_html( (string) $edit['slug'] ); ?></span> <code>(ثابت)</code></td></tr>
                        <tr><th>مالک (Clinic)</th><td><code>(ثابت — #<?php echo (int) $edit['clinic_id']; ?>)</code></td></tr>
                        <tr><th><label for="loc_edit_name">نام شعبه</label></th><td><input type="text" id="loc_edit_name" name="name" class="regular-text" maxlength="190" required value="<?php echo esc_attr( (string) $edit['name'] ); ?>"></td></tr>
                        <tr><th><label for="loc_edit_address">آدرس</label></th><td><?php AddressSelectorField::render( (string) ( $edit['address'] ?? '' ), 'loc_edit_address', 'address_province', 'address_city', 'loc_edit_address' ); ?><input type="text" id="loc_edit_address" name="address" class="regular-text" maxlength="255" value="<?php echo esc_attr( (string) ( $edit['address'] ?? '' ) ); ?>"></td></tr>
                        <tr><th><label for="loc_edit_phone">تلفن</label></th><td><input type="text" id="loc_edit_phone" name="phone" class="regular-text ltr" maxlength="32" value="<?php echo esc_attr( (string) ( $edit['phone'] ?? '' ) ); ?>"></td></tr>
                        <tr><th><label for="loc_edit_tz">منطقهٔ زمانی</label></th>
                            <td>
                                <input type="text" id="loc_edit_tz" name="timezone" class="regular-text ltr" required value="<?php echo esc_attr( (string) $edit['timezone'] ); ?>">
                                <p class="description">فقط نگاه به آینده دارد: ردیف‌های نوبت/وقت/مراجعهٔ موجود بازنویسی یا تبدیل نمی‌شوند.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button-primary">ذخیرهٔ تغییرات</button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id ) ); ?>">انصراف</a></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // ================= Save / actions =================

    public static function save(): void
    {
        if ( ! is_user_logged_in() ) {
            wp_die( 'دسترسی ندارید', 403 );
        }
        // current_user_can() جایگزین مجوز Clinic نیست؛ گارد scoped در upsertLocation است.
        check_admin_referer( self::NONCE_ACTION );

        $in = [
            'mode'        => isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'create',
            'clinic_id'   => isset( $_POST['clinic_id'] ) && is_scalar( $_POST['clinic_id'] ) ? absint( wp_unslash( (string) $_POST['clinic_id'] ) ) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'location_id' => isset( $_POST['location_id'] ) ? wp_unslash( $_POST['location_id'] ) : 0,
            'name'        => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
            'slug'        => isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '',
            'timezone'    => isset( $_POST['timezone'] ) ? wp_unslash( $_POST['timezone'] ) : '',
        ];
        // Preserve existing optional profile values for older/partial clients
        // that omit the field. An explicitly submitted empty string clears it.
        foreach ( [ 'address', 'address_province', 'address_city', 'phone' ] as $optional_field ) {
            if ( array_key_exists( $optional_field, $_POST ) ) {
                $in[ $optional_field ] = wp_unslash( $_POST[ $optional_field ] );
            }
        }

        $result = self::upsertLocation( $in, (int) get_current_user_id() );
        $notice = $result['error'] !== ''
            ? 'خطا: ' . $result['error']
            : ($result['noop'] ? 'تغییری وجود نداشت؛ چیزی ذخیره نشد.' : 'ذخیره شد.');
        set_transient( self::NOTICE_KEY, $notice, 60 );
        $redirect_clinic_id = (int) ( $result['clinic_id'] ?? ( $in['clinic_id'] ?? 0 ) );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . max( 0, $redirect_clinic_id ) ) );
        exit;
    }

    /** Handle a nonce-protected status transition from the native admin list. */
    public static function save_lifecycle(): void {

        if ( ! is_user_logged_in() ) {
            wp_die( 'دسترسی ندارید', 403 );
        }
        check_admin_referer( self::LIFECYCLE_NONCE_ACTION );

        $actor_user_id       = (int) get_current_user_id();
        $raw_clinic_id       = isset( $_POST['clinic_id'] ) && is_scalar( $_POST['clinic_id'] )
            ? wp_unslash( (string) $_POST['clinic_id'] )
            : null;
        $requested_clinic_id = $raw_clinic_id !== null ? absint( $raw_clinic_id ) : null;

        $notice        = 'خطا: دسترسی مدیریت شعبه‌ها برای این Clinic مجاز نیست.';
        $authorization = self::authorize_location_write( $actor_user_id, $requested_clinic_id );
        if ( $authorization['error'] === '' ) {
            $raw_location_id = isset( $_POST['location_id'] ) ? wp_unslash( $_POST['location_id'] ) : 0;
            $location_id     = is_scalar( $raw_location_id ) ? absint( (string) $raw_location_id ) : 0;
            $raw_active      = isset( $_POST['active'] ) ? wp_unslash( $_POST['active'] ) : '';
            $active_value    = self::sanitize_text_input( $raw_active );

            if ( $active_value === '0' || $active_value === '1' ) {
                try {
                    App::locationService()->set_active(
                        $actor_user_id,
                        $authorization['clinic_id'],
                        $location_id,
                        $active_value === '1'
                    );
                    $notice = 'وضعیت شعبه با موفقیت تغییر کرد.';
                } catch ( LocationException $exception ) {
                    $notice = 'خطا: ' . self::map_error( $exception );
                }
            } else {
                $notice = 'خطا: درخواست نامعتبر است.';
            }
        }

        set_transient( self::NOTICE_KEY, $notice, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $authorization['clinic_id'] ) );
        exit;
    }

    /**
     * مرز خالصِ write مسیر واقعی admin — پس از nonce/auth در save().
     *
     * Clinic مورداعتماد را از persistence با TrustedClinicEstablisher احراز می‌کند
     * (clinic_id درخواستی فقط selector است)، مجوز CONFIG scoped را در همان Clinic
     * بررسی می‌کند و سپس به LocationService با trustedClinicId واگذار می‌کند.
     * بدون redirect/exit — قابل اجرای مستقیم در تست‌های Integration.
     *
     * @param array<string,mixed> $in
     *
     * @return array{error:string, location_id:int, clinic_id:int, noop:bool}
     */
    public static function upsertLocation( array $in, int $updated_by ): array // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy public method name: renaming it is a cross-file API change to code outside this PR, so it stays as published.
    {
        if ( $updated_by <= 0 || ! is_user_logged_in() || (int) get_current_user_id() !== $updated_by ) {
            return ['error' => 'کاربر احراز هویت‌شده برای این عملیات معتبر نیست.', 'location_id' => 0, 'clinic_id' => 0, 'noop' => false];
        }

        $raw_clinic_id       = array_key_exists( 'clinic_id', $in ) ? $in['clinic_id'] : null;
        $requested_clinic_id = $raw_clinic_id !== null && is_scalar( $raw_clinic_id )
            ? absint( (string) $raw_clinic_id )
            : null;

        $authorization = self::authorize_location_write( $updated_by, $requested_clinic_id );
        if ( $authorization['error'] !== '' ) {
            return ['error' => $authorization['error'], 'location_id' => 0, 'clinic_id' => 0, 'noop' => false];
        }

        // Sanitize and validate caller-controlled profile values only after the
        // requested Clinic has passed its durable authorization check.
        $mode_raw         = $in['mode'] ?? 'create';
        $mode             = is_string( $mode_raw ) && sanitize_key( $mode_raw ) === 'update' ? 'update' : 'create';
        $name             = self::sanitize_text_input( $in['name'] ?? '' );
        $slug             = self::sanitize_text_input( $in['slug'] ?? '' );
        $timezone         = self::sanitize_text_input( $in['timezone'] ?? '' );
        $location_raw_id  = $in['location_id'] ?? 0;
        $location_id      = is_scalar( $location_raw_id ) ? absint( (string) $location_raw_id ) : 0;
        $address_province = array_key_exists( 'address_province', $in ) ? (string) ( self::sanitize_text_input( $in['address_province'] ) ?? '' ) : '';
        $address_city     = array_key_exists( 'address_city', $in ) ? (string) ( self::sanitize_text_input( $in['address_city'] ) ?? '' ) : '';

        try {
            $resolved_address = null;
            $has_address      = array_key_exists( 'address', $in );
            if ( $has_address ) {
                $resolved_address = self::sanitize_text_input( $in['address'] );
            }
            if ( $address_province !== '' || $address_city !== '' ) {
                $raw_addr_str     = is_string( $resolved_address ) ? $resolved_address : '';
                $resolved_address = IranProvinceCityCatalog::resolve_submitted_address( $raw_addr_str, $address_province, $address_city );
                $has_address      = true;
            }
            if ( $mode === 'update' ) {
                $update_input = [
                    'name'     => $name,
                    'timezone' => $timezone,
                ];
                if ( $has_address ) {
                    $update_input['address'] = $resolved_address;
                }
                if ( array_key_exists( 'phone', $in ) ) {
                    $update_input['phone'] = self::sanitize_text_input( $in['phone'] );
                }
                $result = App::locationService()->updateLocation(
                    $updated_by,
                    $authorization['clinic_id'],
                    $location_id,
                    $update_input
                );
            } else {
                $result = App::locationService()->createLocation(
                    $updated_by,
                    $authorization['clinic_id'],
                    [
                        'name'     => $name,
                        'slug'     => $slug,
                        'address'  => $has_address ? $resolved_address : null,
                        'phone'    => array_key_exists( 'phone', $in ) ? self::sanitize_text_input( $in['phone'] ) : null,
                        'timezone' => $timezone,
                    ]
                );
            }
        } catch ( \InvalidArgumentException $e ) {
            return ['error' => $e->getMessage(), 'location_id' => 0, 'clinic_id' => $authorization['clinic_id'], 'noop' => false];
        } catch ( LocationException $e ) {
            return ['error' => self::map_error( $e ), 'location_id' => 0, 'clinic_id' => $authorization['clinic_id'], 'noop' => false];
        }

        return ['error' => '', 'location_id' => (int) $result['location_id'], 'clinic_id' => (int) $result['clinic_id'], 'noop' => (bool) $result['noop']];
    }

    /**
     * نگاشت خطای دامنه به پیام مرز admin — پیام NOT_FOUND ثابت است تا
     * Location ناموجود و خارجی از بیرون قابل تمایز نباشند.
     */
    private static function map_error( LocationException $e ): string
    {
        $code = $e->getErrorCode();

        if ( $code === LocationException::VALIDATION ) {
            $fields = $e->getData()['fields'] ?? [];
            if ( is_array( $fields ) && $fields !== [] ) {
                $first = reset( $fields );

                return is_string( $first ) ? $first : 'اعتبارسنجی ناموفق';
            }

            return 'اعتبارسنجی ناموفق';
        }
        if ( $code === LocationException::CONFLICT ) {
            return 'شناسهٔ (slug) این شعبه در همین Clinic قبلاً استفاده شده است.';
        }
        if ( $code === LocationException::NOT_FOUND ) {
            return 'محل موردنظر یافت نشد.';
        }
        if ( $code === LocationException::LIFECYCLE_CONFLICT ) {
            return $e->getMessage();
        }
        if ( $code === LocationException::PERMISSION_DENIED ) {
            return 'دسترسی لازم را ندارید.';
        }
        if ( $code === LocationException::AUTH_REQUIRED ) {
            return 'احراز هویت لازم است.';
        }
        if ( $code === LocationException::SCOPE_REQUIRED ) {
            return 'زمینه کلینیک معتبر لازم است.';
        }

        return 'خطای پایگاه داده.';
    }

    /** Sanitize a string only; preserve invalid shapes for fail-closed domain validation. */
    private static function sanitize_text_input( mixed $value ): mixed {
        return is_string( $value ) ? sanitize_text_field( $value ) : $value;
    }

    /**
     * احراز Clinic مورداعتماد + مجوز CONFIG scoped برای write شعبه.
     *
     * @return array{error:string, clinic_id:int}
     */
    private static function authorize_location_write( int $actor_user_id, ?int $requested_clinic_id ): array
    {
        if ( $actor_user_id <= 0 || $requested_clinic_id === null || $requested_clinic_id <= 0 ) {
            return ['error' => 'Clinic را صریحاً انتخاب کنید.', 'clinic_id' => 0];
        }

        try {
            $scope = ( new TrustedClinicEstablisher( App::db(), new MembershipRepository( App::db() ) ) )
                ->establish( $actor_user_id, $requested_clinic_id );
            App::authorization_service()->authorize( $actor_user_id, $scope->clinicId, RolesAndCapabilities::CONFIG ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.

            return ['error' => '', 'clinic_id' => (int) $scope->clinicId];
        } catch ( ScopeRequiredException | AuthorizationException $e ) {
            return ['error' => 'دسترسی مدیریت شعبه‌ها برای این Clinic مجاز نیست.', 'clinic_id' => 0];
        }
    }

    /**
     * @return list<array{clinic_id:int, clinic_name:string}>
     */
    private static function managed_clinic_choices( int $actor_user_id ): array
    {
        if ( $actor_user_id <= 0 ) {
            return [];
        }

        $choices = [];
        foreach ( App::membership_service()->active_memberships_for_user( $actor_user_id ) as $membership ) {
            $clinic_id = (int) ( $membership['clinic_id'] ?? 0 );
            if ( $clinic_id <= 0 || ! App::authorization_service()->can( $actor_user_id, $clinic_id, RolesAndCapabilities::CONFIG ) ) {
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
     * Clinic selector فرم رندر — فقط context نمایشی؛ هر write دوباره از
     * authorizeLocationWrite عبور می‌کند.
     */
    private static function formClinicId(): int
    {
        if ( ! isset( $_GET['clinic_id'] ) || ! is_scalar( $_GET['clinic_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only Clinic selector; write authorization is rechecked
            return 0;
        }
        $requested = absint( wp_unslash( (string) $_GET['clinic_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
        if ( $requested <= 0 ) {
            return 0;
        }

        $authorization = self::authorize_location_write( (int) get_current_user_id(), $requested );
        return $authorization['clinic_id'];
    }

    /**
     * هدف ویرایش فقط اگر واقعاً متعلق به Clinic احرازشده باشد لود می‌شود؛
     * در غیر این صورت پارامتر edit بی‌صدا نادیده گرفته می‌شود (بدون افشا).
     *
     * @return array<string,mixed>|null
     */
    private static function edit_target( int $clinic_id ): ?array
    {
        if ( $clinic_id <= 0 || ! isset( $_GET['edit'] ) || ! is_scalar( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
            return null;
        }

        $location_id = absint( wp_unslash( (string) $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
        if ( $location_id <= 0 ) {
            return null;
        }

        $row = App::locationRepository()->find( $location_id );
        if ( $row === null || (int) ( $row['clinic_id'] ?? 0 ) !== $clinic_id || (int) ( $row['is_primary'] ?? 0 ) === 1 ) {
            return null;
        }

        return $row;
    }
}

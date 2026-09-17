<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Location\LocationException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * «شعبه‌ها» (Location master data) — Phase 4: فقط CREATE + UPDATE name/timezone.
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
 *  - این صفحه Location اصلی (is_primary) را نمی‌سازد/تغییر نمی‌دهد/غیرفعال
 *    نمی‌کند؛ activate/deactivate/delete/change-primary عمداً جزو این slice نیست.
 *  - UPDATE فقط name/timezone است؛ slug/مالک/وضعیت/created_at فقط نمایش
 *    داده می‌شوند (ثابت).
 *  - Location ناموجود و خارجی پیام یکسان می‌گیرند (enumeration parity).
 */
final class LocationAdminPage
{
    public const PAGE_SLUG = 'cpms-locations';

    private const NONCE_ACTION = 'cpms_location_save';
    private const NOTICE_KEY = 'cpms_location_notice';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_location_save', [self::class, 'save']);
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
        if (!current_user_can(RolesAndCapabilities::CONFIG)) {
            wp_die('دسترسی ندارید', 403);
        }

        $notice = get_transient(self::NOTICE_KEY);
        if ($notice !== false) {
            delete_transient(self::NOTICE_KEY);
        }

        $clinicId = self::formClinicId();
        $rows = $clinicId > 0 ? App::locationRepository()->listForClinic($clinicId) : [];
        $edit = self::editTarget($clinicId);
        $clinicChoices = self::managedClinicChoices((int) get_current_user_id());
        ?>
        <div class="wrap" dir="rtl">
            <h1>شعبه‌ها و محل‌ها</h1>
            <p class="description">افزودن شعبهٔ جدید و ویرایش نام/منطقهٔ زمانی شعبه‌های همین Clinic. منطقهٔ زمانی هر شعبه «حقیقت عملیاتی» همان شعبه است و شناسهٔ معتبر IANA می‌گیرد (مثل Asia/Tehran یا Europe/Berlin)؛ با منطقهٔ زمانی Clinic هم‌گام‌سازی نمی‌شود. شعبهٔ اصلی و وضعیت فعال/غیرفعال از این صفحه قابل تغییر نیست.</p>

            <?php if ($clinicChoices !== []) : ?>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="cpms-autofocus">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <label for="cpms_location_clinic">Clinic هدف</label>
                    <select id="cpms_location_clinic" name="clinic_id" required>
                        <option value="">انتخاب Clinic</option>
                        <?php foreach ($clinicChoices as $choice) : ?>
                            <option value="<?php echo (int) $choice['clinic_id']; ?>" <?php selected($clinicId, (int) $choice['clinic_id']); ?>><?php echo esc_html((string) $choice['clinic_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">اعمال Clinic</button>
                    <p class="description">انتخاب فقط context است؛ سرور در هر write عضویت فعال و مجوز Clinic-scoped را دوباره بررسی می‌کند.</p>
                </form>
            <?php else : ?>
                <div class="notice notice-error"><p>برای مدیریت شعبه‌ها، عضویت فعال و مجوز Clinic-scoped در یک Clinic لازم است.</p></div>
            <?php endif; ?>

            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($rows !== []) : ?>
                <h2>فهرست شعبه‌ها</h2>
                <table class="widefat striped cpms-table-responsive" role="presentation">
                    <thead>
                        <tr><th>نام</th><th>شناسه (slug)</th><th>منطقهٔ زمانی</th><th>اصلی</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r) : ?>
                        <tr>
                            <td data-label="نام"><?php echo esc_html((string) $r['name']); ?></td>
                            <td data-label="شناسه"><span class="ltr"><?php echo esc_html((string) $r['slug']); ?></span></td>
                            <td data-label="منطقهٔ زمانی"><span class="ltr"><?php echo esc_html((string) $r['timezone']); ?></span></td>
                            <td data-label="اصلی"><?php echo (int) $r['is_primary'] === 1 ? '<span class="cpms-badge cpms-ok">اصلی</span>' : '—'; ?></td>
                            <td data-label="وضعیت"><?php echo (int) $r['is_active'] === 1 ? '<span class="cpms-badge cpms-ok">فعال</span>' : '<span class="cpms-badge cpms-danger">غیرفعال</span>'; ?></td>
                            <td class="cpms-actions-cell" data-label="عملیات">
                                <?php if ((int) $r['is_primary'] !== 1) : ?>
                                    <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&edit=' . (int) $r['id'] . '&clinic_id=' . $clinicId)); ?>">ویرایش نام/منطقهٔ زمانی</a>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <?php echo CpmsUi::emptyState('🏢', 'فقط شعبهٔ اصلی وجود دارد', 'شعبه‌های این Clinic این‌جا فهرست می‌شوند. شعبهٔ اصلی توسط راه‌اندازی ساخته شده است و از این صفحه قابل ویرایش نیست.', 'افزودن شعبه', admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>
            <?php endif; ?>

            <?php if ($clinicId > 0) : ?>
                <h2>افزودن شعبهٔ جدید</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="cpms_location_save">
                    <input type="hidden" name="mode" value="create">
                    <input type="hidden" name="clinic_id" value="<?php echo (int) $clinicId; ?>">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="loc_name">نام شعبه</label></th><td><input type="text" id="loc_name" name="name" class="regular-text" required value=""></td></tr>
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

            <?php if ($edit !== null) : ?>
                <h2>ویرایش شعبه</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="cpms_location_save">
                    <input type="hidden" name="mode" value="update">
                    <input type="hidden" name="clinic_id" value="<?php echo (int) $clinicId; ?>">
                    <input type="hidden" name="location_id" value="<?php echo (int) $edit['id']; ?>">
                    <table class="form-table" role="presentation">
                        <tr><th>شناسه (slug)</th><td><span class="ltr"><?php echo esc_html((string) $edit['slug']); ?></span> <code>(ثابت)</code></td></tr>
                        <tr><th>مالک (Clinic)</th><td><code>(ثابت — #<?php echo (int) $edit['clinic_id']; ?>)</code></td></tr>
                        <tr><th><label for="loc_edit_name">نام شعبه</label></th><td><input type="text" id="loc_edit_name" name="name" class="regular-text" required value="<?php echo esc_attr((string) $edit['name']); ?>"></td></tr>
                        <tr><th><label for="loc_edit_tz">منطقهٔ زمانی</label></th>
                            <td>
                                <input type="text" id="loc_edit_tz" name="timezone" class="regular-text ltr" required value="<?php echo esc_attr((string) $edit['timezone']); ?>">
                                <p class="description">فقط نگاه به آینده دارد: ردیف‌های نوبت/وقت/مراجعهٔ موجود بازنویسی یا تبدیل نمی‌شوند.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button-primary">ذخیرهٔ تغییرات</button>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinicId)); ?>">انصراف</a></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // ================= Save / actions =================

    public static function save(): void
    {
        if (!is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        // current_user_can() جایگزین مجوز Clinic نیست؛ گارد scoped در upsertLocation است.
        check_admin_referer(self::NONCE_ACTION);

        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'create')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $in = [
            'mode' => $mode === 'update' ? 'update' : 'create',
            'clinic_id' => array_key_exists('clinic_id', $_POST) ? absint($_POST['clinic_id']) : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'location_id' => isset($_POST['location_id']) ? absint($_POST['location_id']) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'slug' => isset($_POST['slug']) ? sanitize_text_field(wp_unslash($_POST['slug'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'timezone' => isset($_POST['timezone']) ? sanitize_text_field(wp_unslash($_POST['timezone'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ];

        $result = self::upsertLocation($in, (int) get_current_user_id());
        $notice = $result['error'] !== ''
            ? 'خطا: ' . $result['error']
            : ($result['noop'] ? 'تغییری وجود نداشت؛ چیزی ذخیره نشد.' : 'ذخیره شد.');
        set_transient(self::NOTICE_KEY, $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
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
     * @return array{error:string, location_id:int, noop:bool}
     */
    public static function upsertLocation(array $in, int $updatedBy): array
    {
        if ($updatedBy <= 0 || !is_user_logged_in() || (int) get_current_user_id() !== $updatedBy) {
            return ['error' => 'کاربر احراز هویت‌شده برای این عملیات معتبر نیست.', 'location_id' => 0, 'noop' => false];
        }

        $requestedClinicId = array_key_exists('clinic_id', $in) && $in['clinic_id'] !== null
            ? (int) $in['clinic_id']
            : null;

        $authorization = self::authorizeLocationWrite($updatedBy, $requestedClinicId);
        if ($authorization['error'] !== '') {
            return ['error' => $authorization['error'], 'location_id' => 0, 'noop' => false];
        }

        $mode = ($in['mode'] ?? 'create') === 'update' ? 'update' : 'create';

        try {
            if ($mode === 'update') {
                $result = App::locationService()->updateLocation(
                    $updatedBy,
                    $authorization['clinic_id'],
                    (int) ($in['location_id'] ?? 0),
                    [
                        'name' => (string) ($in['name'] ?? ''),
                        'timezone' => (string) ($in['timezone'] ?? ''),
                    ]
                );
            } else {
                $result = App::locationService()->createLocation(
                    $updatedBy,
                    $authorization['clinic_id'],
                    [
                        'name' => (string) ($in['name'] ?? ''),
                        'slug' => (string) ($in['slug'] ?? ''),
                        'timezone' => (string) ($in['timezone'] ?? ''),
                    ]
                );
            }
        } catch (LocationException $e) {
            return ['error' => self::mapError($e), 'location_id' => 0, 'noop' => false];
        }

        return ['error' => '', 'location_id' => (int) $result['location_id'], 'noop' => (bool) $result['noop']];
    }

    /**
     * نگاشت خطای دامنه به پیام مرز admin — پیام NOT_FOUND ثابت است تا
     * Location ناموجود و خارجی از بیرون قابل تمایز نباشند.
     */
    private static function mapError(LocationException $e): string
    {
        $code = $e->getErrorCode();

        if ($code === LocationException::VALIDATION) {
            $fields = $e->getData()['fields'] ?? [];
            if (is_array($fields) && $fields !== []) {
                $first = reset($fields);

                return is_string($first) ? $first : 'اعتبارسنجی ناموفق';
            }

            return 'اعتبارسنجی ناموفق';
        }
        if ($code === LocationException::CONFLICT) {
            return 'شناسهٔ (slug) این شعبه در همین Clinic قبلاً استفاده شده است.';
        }
        if ($code === LocationException::NOT_FOUND) {
            return 'محل موردنظر یافت نشد.';
        }
        if ($code === LocationException::PERMISSION_DENIED) {
            return 'دسترسی لازم را ندارید.';
        }
        if ($code === LocationException::AUTH_REQUIRED) {
            return 'احراز هویت لازم است.';
        }
        if ($code === LocationException::SCOPE_REQUIRED) {
            return 'زمینه کلینیک معتبر لازم است.';
        }

        return 'خطای پایگاه داده.';
    }

    /**
     * احراز Clinic مورداعتماد + مجوز CONFIG scoped برای write شعبه.
     *
     * @return array{error:string, clinic_id:int}
     */
    private static function authorizeLocationWrite(int $actorUserId, ?int $requestedClinicId): array
    {
        try {
            $scope = (new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db())))
                ->establish($actorUserId, $requestedClinicId);
            App::authorization_service()->authorize($actorUserId, $scope->clinicId, RolesAndCapabilities::CONFIG);

            return ['error' => '', 'clinic_id' => (int) $scope->clinicId];
        } catch (ScopeRequiredException|AuthorizationException $e) {
            return ['error' => 'دسترسی مدیریت شعبه‌ها برای این Clinic مجاز نیست.', 'clinic_id' => 0];
        }
    }

    /**
     * @return list<array{clinic_id:int, clinic_name:string}>
     */
    private static function managedClinicChoices(int $actorUserId): array
    {
        if ($actorUserId <= 0) {
            return [];
        }

        $choices = [];
        foreach (App::membership_service()->active_memberships_for_user($actorUserId) as $membership) {
            $clinicId = (int) ($membership['clinic_id'] ?? 0);
            if ($clinicId <= 0 || !App::authorization_service()->can($actorUserId, $clinicId, RolesAndCapabilities::CONFIG)) {
                continue;
            }
            $choices[] = [
                'clinic_id' => $clinicId,
                'clinic_name' => (string) ($membership['clinic_name'] ?? ('Clinic #' . $clinicId)),
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
        $requested = array_key_exists('clinic_id', $_GET) ? absint($_GET['clinic_id']) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
        $authorization = self::authorizeLocationWrite((int) get_current_user_id(), $requested);

        return $authorization['clinic_id'];
    }

    /**
     * هدف ویرایش فقط اگر واقعاً متعلق به Clinic احرازشده باشد لود می‌شود؛
     * در غیر این صورت پارامتر edit بی‌صدا نادیده گرفته می‌شود (بدون افشا).
     *
     * @return array<string,mixed>|null
     */
    private static function editTarget(int $clinicId): ?array
    {
        if ($clinicId <= 0 || !isset($_GET['edit'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
            return null;
        }

        $locationId = absint($_GET['edit']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
        if ($locationId <= 0) {
            return null;
        }

        $row = App::locationRepository()->find($locationId);
        if ($row === null || (int) ($row['clinic_id'] ?? 0) !== $clinicId || (int) ($row['is_primary'] ?? 0) === 1) {
            return null;
        }

        return $row;
    }
}

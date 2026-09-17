<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * «کاربران و دسترسی‌ها» (Staff/User Management) — Chunk C.
 *
 * طراحی امن:
 *  - `cpms_config` همچنان گیت منو/درخواست wp-admin است، اما جایگزین مجوز Clinic نیست.
 *    هر write همچنین از TrustedClinicEstablisher + AuthorizationService عبور می‌کند.
 *  - زمینهٔ هدف از عضویت فعالِ durable actor می‌آید: Clinic ارسالی فقط selector است و
 *    توسط TrustedClinicEstablisher علیه persistence اعتبارسنجی می‌شود؛ هرگز مدرک اعتماد نیست.
 *  - عملیات روی کاربر موجود، مالکیت Clinic را از رکورد durable عضویت همان کاربر می‌خواند
 *    و با authorizeForObject تطبیق می‌دهد؛ برابری دو شناسه به‌تنهایی اعتماد نمی‌سازد.
 *  - این صفحه فقط نقش‌های CPMS را مدیریت می‌کند؛ `administrator` وردپرس نه قابل انتساب
 *    است نه قابل ویرایش/غیرفعال‌سازی از این‌جا.
 *  - رمز عبور هرگز plaintext ذخیره/نمایش داده نمی‌شود؛ WP فقط hash ذخیره می‌کند.
 *    در صورت عدم تعیین رمز، یک رمز قوی تصادفی ساخته و «یک‌بار» در اعلان نمایش داده می‌شود.
 *  - Nonce (CSRF) + authenticated actor + Clinic authorization + Sanitize روی هر action مستقل‌اند؛
 *    Capability وردپرس فقط برای نمایش/ورود ادمین است و به‌تنهایی write را مجاز نمی‌کند.
 *  - هر تغییر (ایجاد/ویرایش/تغییر نقش/فعال/غیرفعال/بازنشانی رمز) در Audit ثبت می‌شود.
 *  - غیرفعال‌سازی علاوه بر سازگاری نقش WP، وضعیت Membership هدف در همان Clinic را
 *    suspended می‌کند؛ فعال‌سازی همان Membership را برمی‌گرداند.
 */
final class StaffManagementPage
{
    public const PAGE_SLUG = 'cpms-staff';

    private const NONCE_ACTION = 'cpms_staff_save';
    private const NOTICE_KEY = 'cpms_staff_notice';
    private const META_PREV_ROLE = 'cpms_previous_role';

    /** نقش‌های قابل‌مدیریت (قابل‌انتساب/ویرایش/فعال/غیرفعال). */
    private const MANAGEABLE_ROLES = [
        RolesAndCapabilities::ROLE_DOCTOR,
        RolesAndCapabilities::ROLE_SECRETARY,
        RolesAndCapabilities::ROLE_PATIENT,
        RolesAndCapabilities::ROLE_ACCOUNTANT,
        RolesAndCapabilities::ROLE_MANAGER,
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_staff_save', [self::class, 'save']);
        add_action('admin_post_cpms_staff_toggle', [self::class, 'toggle']);
        add_action('admin_post_cpms_staff_password', [self::class, 'sendPasswordReset']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            CpmsAdminMenu::parentSlug(),
            'کاربران و دسترسی‌ها',
            'کاربران و دسترسی‌ها',
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
        $rows = self::listUsers($clinicId);
        $roles = self::roleLabels();
        $clinicChoices = self::managedClinicChoices((int) get_current_user_id());
        ?>
        <div class="wrap" dir="rtl">
            <h1>کاربران و دسترسی‌ها</h1>
            <p class="description">افزودن و مدیریت پرسنل کلینیک (پزشک، منشی، حسابدار، مدیر کلینیک، بیمار). این صفحه فقط نقش‌های CPMS را
                مدیریت می‌کند؛ مدیر وردپرس (administrator) از این‌جا قابل تغییر نیست — برای امنیت، نقش‌های فنی/امنیتی و ویرایش
                ماتریس دسترسی از صفحهٔ «دسترسی‌ها» (فقط مالک فنی) انجام می‌شود.</p>

            <?php if ($clinicChoices !== []) : ?>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="cpms-autofocus">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <label for="cpms_staff_clinic">Clinic هدف</label>
                    <select id="cpms_staff_clinic" name="clinic_id" required>
                        <option value="">انتخاب Clinic</option>
                        <?php foreach ($clinicChoices as $choice) : ?>
                            <option value="<?php echo (int) $choice['clinic_id']; ?>" <?php selected($clinicId, (int) $choice['clinic_id']); ?>><?php echo esc_html((string) $choice['clinic_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">اعمال Clinic</button>
                    <p class="description">انتخاب فقط context است؛ سرور در هر write عضویت فعال و `cpms_config` را دوباره بررسی می‌کند.</p>
                </form>
            <?php else : ?>
                <div class="notice notice-error"><p>برای مدیریت پرسنل، عضویت فعال و مجوز Clinic-scoped در یک Clinic لازم است.</p></div>
            <?php endif; ?>

            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($rows !== []) : ?>
                <h2>فهرست پرسنل</h2>
                <table class="widefat striped cpms-table-responsive" role="presentation">
                    <thead>
                        <tr><th>نام</th><th>ورود</th><th>ایمیل</th><th>نقش</th><th>پزشک مرتبط</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r) : ?>
                        <tr>
                            <td data-label="نام"><?php echo esc_html((string) $r['display_name']); ?></td>
                            <td data-label="ورود"><span class="ltr"><?php echo esc_html((string) $r['login']); ?></span></td>
                            <td data-label="ایمیل"><span class="ltr"><?php echo esc_html((string) $r['email']); ?></span></td>
                            <td data-label="نقش"><?php echo esc_html((string) $r['role_label']); ?></td>
                            <td data-label="پزشک مرتبط"><?php echo $r['clinician'] !== '' ? esc_html($r['clinician']) : '—'; ?></td>
                            <td data-label="وضعیت"><?php echo $r['active'] ? '<span class="cpms-badge cpms-ok">فعال</span>' : '<span class="cpms-badge cpms-danger">غیرفعال</span>'; ?></td>
                            <td class="cpms-actions-cell" data-label="عملیات">
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&edit=' . (int) $r['id'] . '&clinic_id=' . $clinicId)); ?>">ویرایش</a>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_staff_password&user_id=' . (int) $r['id'] . '&clinic_id=' . $clinicId), 'cpms_staff_password_' . (int) $r['id'])); ?>">لینک بازیابی رمز</a>
                                <?php if ($r['active']) : ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_staff_toggle&user_id=' . (int) $r['id'] . '&state=deactivate&clinic_id=' . $clinicId), 'cpms_staff_toggle_' . (int) $r['id'])); ?>" data-cpms-confirm="غیرفعال‌سازی: این کاربر به‌طور موقت از نقش CPMS حذف می‌شود (تاریخچه حذف نمی‌شود). ادامه می‌دهید؟">غیرفعال</a>
                                <?php else : ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_staff_toggle&user_id=' . (int) $r['id'] . '&state=activate&clinic_id=' . $clinicId), 'cpms_staff_toggle_' . (int) $r['id'])); ?>">فعال‌سازی مجدد</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <?php echo CpmsUi::emptyState('👥', 'هنوز پرسنلی ثبت نشده', 'کاربران کلینیک (پزشک، منشی، حسابدار، مدیر کلینیک، بیمار) را با فرم پایین اضافه کنید. فقط نقش‌های CPMS از این‌جا قابل مدیریت‌اند؛ administrator از این‌جا قابل تغییر نیست.', 'افزودن کاربر', admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>
            <?php endif; ?>

            <?php $edit = self::editTarget($clinicId); ?>
            <h2><?php echo $edit !== null ? 'ویرایش کاربر' : 'افزودن کاربر'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="cpms_staff_save">
                <input type="hidden" name="mode" value="<?php echo $edit !== null ? 'update' : 'create'; ?>">
                <input type="hidden" name="user_id" value="<?php echo $edit !== null ? (int) $edit['id'] : 0; ?>">
                <input type="hidden" name="clinic_id" value="<?php echo $clinicId; ?>">

                <table class="form-table" role="presentation">
                    <?php if ($edit === null) : ?>
                        <tr><th><label for="u_login">نام کاربری (ورود)</label></th><td><input type="text" id="u_login" name="username" class="regular-text" required value=""></td></tr>
                    <?php else : ?>
                        <tr><th>نام کاربری</th><td><?php echo esc_html((string) $edit['login']); ?> <code>(ثابت)</code></td></tr>
                    <?php endif; ?>
                    <tr><th><label for="u_disp">نام نمایشی</label></th><td><input type="text" id="u_disp" name="display_name" class="regular-text" required value="<?php echo esc_attr($edit !== null ? (string) $edit['display_name'] : ''); ?>"></td></tr>
                    <tr><th><label for="u_email">ایمیل</label></th><td><input type="email" id="u_email" name="email" class="regular-text" required value="<?php echo esc_attr($edit !== null ? (string) $edit['email'] : ''); ?>"></td></tr>
                    <tr><th><label for="u_role">نقش CPMS</label></th>
                        <td><select id="u_role" name="role">
                            <?php foreach ($roles as $slug => $label) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($edit === null ? '' : (string) $edit['role'], $slug); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                    </tr>
                    <tr><th><label for="u_password">رمز عبور</label></th>
                        <td>
                            <input type="password" id="u_password" name="password" class="regular-text" autocomplete="new-password" value="">
                            <p class="description">برای «افزودن» خالی بگذارید تا رمز قوی تصادفی ساخته و یک‌بار نمایش داده شود؛ یا رمز دلخواه (حداقل ۱۰ کاراکتر شامل حرف و عدد) وارد کنید. رمز هرگز به‌صورت plaintext ذخیره نمی‌شود.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php echo $edit !== null ? 'ذخیره تغییرات' : 'افزودن کاربر'; ?></button>
                <?php if ($edit !== null) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">انصراف</a><?php endif; ?>
                </p>
            </form>

            <?php if ($clinicId > 0) : ?>
                <h2>افزودن کاربر موجود به این Clinic</h2>
                <p class="description">شناسهٔ عددی WordPress حساب موجود را وارد کنید. این عملیات حساب WordPress یا پروفایل حرفه‌ای جدید نمی‌سازد؛ فقط عضویت همین کاربر را در Clinic انتخاب‌شده ایجاد می‌کند.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="cpms_staff_save">
                    <input type="hidden" name="mode" value="attach_existing">
                    <input type="hidden" name="clinic_id" value="<?php echo $clinicId; ?>">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="cpms_existing_user">شناسهٔ حساب WordPress موجود</label></th>
                            <td>
                                <input type="number" id="cpms_existing_user" name="existing_user_id" min="1" step="1" required>
                                <p class="description">شناسهٔ پایدار کاربر را از حساب موجود بردارید؛ جست‌وجوی موبایل یا merge خودکار انجام نمی‌شود.</p>
                            </td>
                        </tr>
                        <tr><th><label for="cpms_existing_role">نقش عضویت در این Clinic</label></th>
                            <td>
                                <select id="cpms_existing_role" name="role" required>
                                    <?php foreach ($roles as $slug => $label) : ?>
                                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">نقش این عضویت به‌صورت Clinic-scoped اعمال می‌شود؛ Membership با مجوز یکی نیست.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary">افزودن کاربر موجود</button></p>
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
        // current_user_can() is not Clinic authorization; the scoped write guard below is authoritative.
        check_admin_referer(self::NONCE_ACTION);

        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'create')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $in = [
            'mode' => in_array($mode, ['update', 'attach_existing'], true) ? $mode : 'create',
            'user_id' => isset($_POST['user_id']) ? absint($_POST['user_id']) : 0,
            'existing_user_id' => isset($_POST['existing_user_id']) ? absint($_POST['existing_user_id']) : 0,
            'clinic_id' => array_key_exists('clinic_id', $_POST) ? absint($_POST['clinic_id']) : null,
            'username' => isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username']), true) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'display_name' => isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'email' => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'role' => isset($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'password' => (string) wp_unslash($_POST['password'] ?? ''), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        ];

        $result = self::upsertUser($in, (int) get_current_user_id());
        $notice = $result['error'] !== ''
            ? 'خطا: ' . $result['error']
            : ($result['generated'] !== '' ? 'کاربر ثبت شد. رمز یک‌باره: ' . $result['generated'] . ' (این رمز فقط همین حالا نمایش داده می‌شود — آن را به کاربر بدهید.)' : 'ذخیره شد.');
        set_transient(self::NOTICE_KEY, $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function toggle(): void
    {
        $userId = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (!is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer('cpms_staff_toggle_' . $userId);

        $state = (string) ($_GET['state'] ?? '');
        $clinicId = array_key_exists('clinic_id', $_GET) ? absint($_GET['clinic_id']) : null;
        $result = self::toggleUser($userId, $state, (int) get_current_user_id(), $clinicId);
        set_transient(self::NOTICE_KEY, $result['error'] !== '' ? 'خطا: ' . $result['error'] : 'وضعیت کاربر به‌روزرسانی شد.', 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * ارسال لینک بازنشانی/تنظیم رمز عبور از طریق WordPress Authentication API.
     *  - Nonce/Capability در این transport boundary باقی می‌مانند.
     *  - Clinic authorization و مالکیت durable هدف در initiatePasswordReset انجام می‌شود.
     *  - رمز فعلی/توکن هرگز در DB/Audit/REST/HTML ما ثبت نمی‌شود؛ فقط ارسال ایمیل WP انجام می‌شود.
     */
    public static function sendPasswordReset(): void
    {
        $userId = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        if (!is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer('cpms_staff_password_' . $userId);

        $clinicId = array_key_exists('clinic_id', $_GET) ? absint($_GET['clinic_id']) : null;
        $result = self::initiatePasswordReset($userId, (int) get_current_user_id(), $clinicId);
        if ($result['error'] !== '') {
            set_transient(self::NOTICE_KEY, 'خطا: ' . $result['error'], 60);
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
            exit;
        }

        set_transient(self::NOTICE_KEY, 'لینک بازنشانی/تنظیم رمز برای «' . $result['display_name'] . '» ایمیل شد.', 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /**
     * Initiate a password reset after the same Clinic-scoped checks as other staff writes.
     *
     * The admin-post handler owns CSRF protection; this pure boundary owns authorization
     * and is executable by integration tests without terminating the PHPUnit process.
     *
     * @return array{error:string, display_name:string}
     */
    public static function initiatePasswordReset(int $userId, int $updatedBy, ?int $clinicId = null): array
    {
        $user = $userId > 0 ? get_userdata($userId) : false;
        if ($user === false || !self::isManageable($userId)) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.', 'display_name' => ''];
        }

        $authorization = self::authorizeStaffWrite($updatedBy, $clinicId, $userId);
        if ($authorization['error'] !== '') {
            return ['error' => $authorization['error'], 'display_name' => ''];
        }

        // retrieve_password از مسیر امن وردپرس: صحت کاربر را می‌سنجد، توکن تولید و ایمیل
        // «بازیابی/تنظیم رمز» می‌فرستد. توکن فقط در ایمیل و DB موقت وردپرس است.
        $res = retrieve_password((string) $user->user_login);
        if (is_wp_error($res)) {
            return ['error' => $res->get_error_message(), 'display_name' => ''];
        }

        App::audit()->log(
            'STAFF_PASSWORD_RESET_INITIATED',
            ['wp_user_id' => $updatedBy],
            'user',
            $userId,
            null,
            null,
            [
                'clinic_id' => $authorization['clinic_id'],
                'login' => (string) $user->user_login,
            ]
        );

        return ['error' => '', 'display_name' => (string) $user->display_name];
    }

    /**
     * ایجاد/ویرایش کاربر — خالص و قابل تست (بدون exit/die).
     *
     * @param array<string, mixed> $in
     *
     * @return array{error:string, generated:string, user_id:int}
     */
    public static function upsertUser(array $in, int $updatedBy): array
    {
        if (($in['mode'] ?? 'create') === 'attach_existing') {
            return self::attachExistingUser($in, $updatedBy);
        }

        $mode = ($in['mode'] ?? 'create') === 'update' ? 'update' : 'create';
        $userId = (int) ($in['user_id'] ?? 0);
        $username = trim((string) ($in['username'] ?? ''));
        $displayName = trim((string) ($in['display_name'] ?? ''));
        $email = (string) ($in['email'] ?? '');
        $role = trim((string) ($in['role'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $requestedClinicId = array_key_exists('clinic_id', $in) && $in['clinic_id'] !== null
            ? (int) $in['clinic_id']
            : null;

        if ($mode === 'update' && ($userId <= 0 || !self::isManageable($userId))) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.', 'generated' => '', 'user_id' => 0];
        }

        $authorization = self::authorizeStaffWrite(
            $updatedBy,
            $requestedClinicId,
            $mode === 'update' ? $userId : null
        );
        if ($authorization['error'] !== '') {
            return ['error' => $authorization['error'], 'generated' => '', 'user_id' => 0];
        }
        $clinicId = $authorization['clinic_id'];
        $targetMembership = $authorization['target_membership'];

        if ($role === '' || !in_array($role, self::MANAGEABLE_ROLES, true)) {
            return ['error' => 'نقش غیرمجاز است (فقط نقش‌های CPMS قابل انتساب‌اند؛ از انتساب administrator جلوگیری شد).', 'generated' => '', 'user_id' => 0];
        }
        if ($displayName === '') {
            return ['error' => 'نام نمایشی الزامی است.', 'generated' => '', 'user_id' => 0];
        }
        if ($email === '' || !is_email($email)) {
            return ['error' => 'ایمیل معتبر الزامی است.', 'generated' => '', 'user_id' => 0];
        }

        $generated = '';
        if ($mode === 'create' && ($username === '' || !validate_username($username))) {
            return ['error' => 'نام کاربری معتبر الزامی است (حروف، اعداد، _ و -).', 'generated' => '', 'user_id' => 0];
        }

        // رمز: WP `user_pass` را خودش hash می‌کند؛ بنابراین plaintext (تعیین‌شده یا تولیدی) را
        // می‌دهیم و هرگز آن را در DB به‌صورت رمز در دسترس ذخیره نمی‌کنیم.
        if ($password === '') {
            $generated = self::generatePassword();
        } elseif (!self::validatePassword($password)) {
            return ['error' => 'رمز عبور باید حداقل ۱۰ کاراکتر شامل حرف و عدد باشد.', 'generated' => '', 'user_id' => 0];
        }
        $plainPassword = $password !== '' ? $password : $generated;

        if ($mode === 'create') {
            $userId = wp_insert_user([
                'user_login' => $username,
                'user_pass' => $plainPassword,
                'user_email' => $email,
                'display_name' => $displayName,
                'role' => $role,
            ]);
            if (is_wp_error($userId)) {
                return ['error' => $userId->get_error_message(), 'generated' => '', 'user_id' => 0];
            }
            $userId = (int) $userId;
            try {
                App::membership_service()->create_membership(
                    $clinicId,
                    $userId,
                    $role,
                    'clinic',
                    $updatedBy
                );
            } catch (\Throwable $e) {
                wp_delete_user($userId);

                return ['error' => 'عضویت کاربر در Clinic ایجاد نشد.', 'generated' => '', 'user_id' => 0];
            }
            App::audit()->log(
                'STAFF_USER_CREATED',
                ['wp_user_id' => $updatedBy],
                'user',
                $userId,
                null,
                null,
                ['clinic_id' => $clinicId, 'role' => $role, 'login' => $username]
            );
        } else {
            $args = ['ID' => $userId, 'display_name' => $displayName, 'user_email' => $email, 'role' => $role];
            if ($password !== '') {
                $args['user_pass'] = $plainPassword;
            }
            $res = wp_update_user($args);
            if (is_wp_error($res)) {
                return ['error' => $res->get_error_message(), 'generated' => '', 'user_id' => $userId];
            }
            if ($targetMembership !== null) {
                App::membership_service()->set_role_key((int) $targetMembership['id'], $role);
            }
            // اگر کاربر قبلاً غیرفعال بود (subscriber + usermeta)، اکنون نقش واقعی گرفت → پاک کردن flag.
            if (get_user_meta($userId, self::META_PREV_ROLE, true) !== '') {
                delete_user_meta($userId, self::META_PREV_ROLE);
            }
            App::audit()->log(
                'STAFF_USER_UPDATED',
                ['wp_user_id' => $updatedBy],
                'user',
                $userId,
                null,
                null,
                ['clinic_id' => $clinicId, 'role' => $role, 'password_reset' => $password !== '']
            );
        }

        return ['error' => '', 'generated' => $generated, 'user_id' => $userId];
    }

    /**
     * افزودن حساب WordPress موجود به Clinic انتخاب‌شده، بدون ساخت حساب یا Clinician جدید.
     *
     * این عملیات فقط از شناسهٔ پایدار WordPress استفاده می‌کند؛ شمارهٔ موبایل
     * هیچ‌گاه کلید merge خودکار نیست. Clinic و مجوز operator در همین مرز دوباره
     * از persistence احراز می‌شوند و ساخت عضویت از MembershipService عبور می‌کند.
     *
     * @param array<string, mixed> $in
     *
     * @return array{error:string, generated:string, user_id:int}
     */
    public static function attachExistingUser(array $in, int $updatedBy): array
    {
        if ($updatedBy <= 0 || !is_user_logged_in() || (int) get_current_user_id() !== $updatedBy) {
            return ['error' => 'کاربر احراز هویت‌شده برای این عملیات معتبر نیست.', 'generated' => '', 'user_id' => 0];
        }

        $requestedClinicId = array_key_exists('clinic_id', $in) && $in['clinic_id'] !== null
            ? (int) $in['clinic_id']
            : null;
        $authorization = self::authorizeStaffWrite($updatedBy, $requestedClinicId);
        if ($authorization['error'] !== '') {
            return ['error' => $authorization['error'], 'generated' => '', 'user_id' => 0];
        }

        $role = trim((string) ($in['role'] ?? ''));
        if ($role === '' || !in_array($role, self::MANAGEABLE_ROLES, true)) {
            return ['error' => 'نقش غیرمجاز است (فقط نقش‌های CPMS قابل انتساب‌اند؛ از انتساب administrator جلوگیری شد).', 'generated' => '', 'user_id' => 0];
        }

        $existingUserId = (int) ($in['existing_user_id'] ?? 0);
        $existingUser = $existingUserId > 0 ? get_userdata($existingUserId) : false;
        if ($existingUser === false || in_array('administrator', (array) $existingUser->roles, true)) {
            return ['error' => 'حساب انتخاب‌شده برای افزودن به این کلینیک قابل استفاده نیست.', 'generated' => '', 'user_id' => 0];
        }

        try {
            App::membership_service()->create_membership(
                $authorization['clinic_id'],
                $existingUserId,
                $role,
                'clinic',
                $updatedBy
            );
        } catch (MembershipException $e) {
            // create_membership() is the single membership write path. In
            // particular, an existing active or suspended row is a deterministic
            // conflict; this action never silently reactivates or duplicates it.
            return ['error' => $e->getMessage(), 'generated' => '', 'user_id' => 0];
        } catch (\Throwable $e) {
            return ['error' => 'عضویت کاربر در Clinic ایجاد نشد.', 'generated' => '', 'user_id' => 0];
        }

        App::audit()->log(
            'STAFF_USER_ATTACHED',
            ['wp_user_id' => $updatedBy],
            'user',
            $existingUserId,
            null,
            null,
            [
                'clinic_id' => $authorization['clinic_id'],
                'role' => $role,
                'existing_user' => true,
            ]
        );

        return ['error' => '', 'generated' => '', 'user_id' => $existingUserId];
    }

    /**
     * فعال/غیرفعال‌سازی — نقش CPMS را موقتاً به subscriber تغییر می‌دهد (بدون حذف تاریخچه).
     *
     * @return array{error:string}
     */
    public static function toggleUser(int $userId, string $state, int $updatedBy, ?int $clinicId = null): array
    {
        if ($userId <= 0 || !self::isManageable($userId)) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.'];
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return ['error' => 'کاربر یافت نشد.'];
        }

        $authorization = self::authorizeStaffWrite($updatedBy, $clinicId, $userId);
        if ($authorization['error'] !== '') {
            return ['error' => $authorization['error']];
        }
        $targetMembership = $authorization['target_membership'];
        if ($targetMembership === null) {
            return ['error' => 'عضویت هدف برای این Clinic یافت نشد.'];
        }

        // جلوگیری از قفل‌کردن خود: عاملِ غیرفعال‌سازی نمی‌تواند حساب خودش را غیرفعال کند.
        if ($state === 'deactivate' && $userId === $updatedBy) {
            return ['error' => 'نمی‌توانید حساب خودتان را غیرفعال کنید (جلوگیری از قفل‌شدن).'];
        }

        if (!in_array($state, ['deactivate', 'activate'], true)) {
            return ['error' => 'وضعیت کاربر نامعتبر است.'];
        }

        if ($state === 'deactivate') {
            $prevRole = (string) ($user->roles[0] ?? '');
            if (!in_array($prevRole, self::MANAGEABLE_ROLES, true)) {
                return ['error' => 'این کاربر نقش قابل غیرفعال‌سازی ندارد.'];
            }
            if ((string) ($targetMembership['status'] ?? '') !== 'active') {
                return ['error' => 'عضویت هدف از قبل غیرفعال است.'];
            }
            App::membership_service()->suspend_membership((int) $targetMembership['id']);
            update_user_meta($userId, self::META_PREV_ROLE, $prevRole);
            $updated = wp_update_user(['ID' => $userId, 'role' => 'subscriber']);
            if (is_wp_error($updated)) {
                App::membership_service()->reactivate_membership((int) $targetMembership['id']);

                return ['error' => $updated->get_error_message()];
            }
            App::audit()->log(
                'STAFF_USER_DEACTIVATED',
                ['wp_user_id' => $updatedBy],
                'user',
                $userId,
                null,
                ['clinic_id' => $authorization['clinic_id'], 'role' => $prevRole, 'membership_status' => 'active'],
                ['clinic_id' => $authorization['clinic_id'], 'role' => 'subscriber', 'membership_status' => 'suspended']
            );
        } else {
            $prevRole = (string) get_user_meta($userId, self::META_PREV_ROLE, true);
            if ($prevRole === '' || !in_array($prevRole, self::MANAGEABLE_ROLES, true)) {
                return ['error' => 'نقش قبلی برای فعال‌سازی یافت نشد.'];
            }
            if ((string) ($targetMembership['status'] ?? '') !== 'suspended') {
                return ['error' => 'عضویت هدف فعال است.'];
            }
            App::membership_service()->reactivate_membership((int) $targetMembership['id']);
            delete_user_meta($userId, self::META_PREV_ROLE);
            $updated = wp_update_user(['ID' => $userId, 'role' => $prevRole]);
            if (is_wp_error($updated)) {
                App::membership_service()->suspend_membership((int) $targetMembership['id']);

                return ['error' => $updated->get_error_message()];
            }
            App::audit()->log(
                'STAFF_USER_ACTIVATED',
                ['wp_user_id' => $updatedBy],
                'user',
                $userId,
                null,
                ['clinic_id' => $authorization['clinic_id'], 'role' => 'subscriber', 'membership_status' => 'suspended'],
                ['clinic_id' => $authorization['clinic_id'], 'role' => $prevRole, 'membership_status' => 'active']
            );
        }

        return ['error' => ''];
    }

    // ================= helpers =================

    /**
     * Resolve and authorize the Clinic write boundary for staff management.
     *
     * `$requestedClinicId` is only a caller-selected context. The establisher
     * validates it against the actor's durable active membership; AuthorizationService
     * then evaluates the scoped permission. For an existing target, its Clinic owner
     * is independently retrieved from the durable membership row before the object
     * authorization check.
     *
     * @return array{error:string, clinic_id:int, target_membership:array<string,mixed>|null}
     */
    private static function authorizeStaffWrite(int $actorUserId, ?int $requestedClinicId, ?int $targetUserId = null): array
    {
        try {
            $scope = (new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db())))
                ->establish($actorUserId, $requestedClinicId);
            $authorization = App::authorization_service();
            $authorization->authorize($actorUserId, $scope->clinicId, RolesAndCapabilities::CONFIG);

            $targetMembership = null;
            if ($targetUserId !== null) {
                $targetMembership = (new MembershipRepository(App::db()))->find($scope->clinicId, $targetUserId);
                if ($targetMembership === null) {
                    throw new AuthorizationException('AUTH_NO_MEMBERSHIP', 'AUTH_NO_MEMBERSHIP');
                }

                // This owner value came from the durable membership row above, not
                // from the request. Equality is checked by the service, but the
                // source-of-truth retrieval remains at this boundary.
                $targetClinicId = (int) ($targetMembership['clinic_id'] ?? 0);
                $authorization->authorizeForObject(
                    $actorUserId,
                    $scope->clinicId,
                    RolesAndCapabilities::CONFIG,
                    $targetClinicId
                );
            }

            return [
                'error' => '',
                'clinic_id' => $scope->clinicId,
                'target_membership' => $targetMembership,
            ];
        } catch (ScopeRequiredException|AuthorizationException $e) {
            return [
                'error' => 'دسترسی مدیریت پرسنل برای این Clinic مجاز نیست.',
                'clinic_id' => 0,
                'target_membership' => null,
            ];
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
     * Clinic selector for the rendered form. It is display context only; every
     * write validates the value again through authorizeStaffWrite().
     */
    private static function formClinicId(): int
    {
        $requested = array_key_exists('clinic_id', $_GET) ? absint($_GET['clinic_id']) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
        $authorization = self::authorizeStaffWrite((int) get_current_user_id(), $requested);

        return $authorization['clinic_id'];
    }

    /** آیا این کاربر قابل‌مدیریت است؟ (نقش قابل‌مدیریت یا غیرفعالِ دارای usermeta نقش قبلی) */
    private static function isManageable(int $userId): bool
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return false;
        }
        foreach ($user->roles as $role) {
            if (in_array($role, self::MANAGEABLE_ROLES, true)) {
                return true;
            }
        }
        // کاربر غیرفعال (subscriber + usermeta) هم قابل مدیریت است تا بتوان فعال/re-activate کرد.
        return get_user_meta($userId, self::META_PREV_ROLE, true) !== '';
    }

    /** @return array<int, array<string,mixed>> */
    private static function listUsers(int $clinicId): array
    {
        // No trusted/authorized Clinic means no Clinic-sensitive read. In
        // particular, clinic_id=0 must never fall through to installation-wide
        // get_users(); a global WP capability is not a Clinic context.
        if ($clinicId <= 0) {
            return [];
        }

        $membershipRows = App::db()->fetchAll(
            'SELECT wp_user_id FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d ORDER BY wp_user_id LIMIT 500',
            [$clinicId]
        );
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['wp_user_id'] ?? 0),
            is_array($membershipRows) ? $membershipRows : []
        ), static fn (int $userId): bool => $userId > 0)));
        if ($userIds === []) {
            return [];
        }

        $manageableQuery = [
            'role__in' => self::MANAGEABLE_ROLES,
            'fields' => 'all',
            'number' => 500,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];
        $inactiveQuery = [
            'meta_key' => self::META_PREV_ROLE,
            'fields' => 'all',
            'number' => 500,
        ];
        $manageableQuery['include'] = $userIds;
        $inactiveQuery['include'] = $userIds;

        $users = get_users($manageableQuery);
        // کاربران غیرفعال (دارای usermeta cpms_previous_role) نیز نمایش داده شوند.
        $users = array_merge($users, get_users($inactiveQuery));

        $rows = [];
        $seen = [];
        $labels = self::roleLabels();
        $clinicianByUser = self::clinicianLinkMap($clinicId);
        foreach ($users as $u) {
            $id = (int) $u->ID;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $prevRole = (string) get_user_meta($id, self::META_PREV_ROLE, true);
            $active = $prevRole === '';
            $role = $active ? ($u->roles[0] ?? '') : $prevRole;
            $rows[] = [
                'id' => $id,
                'login' => (string) $u->user_login,
                'email' => (string) $u->user_email,
                'display_name' => (string) $u->display_name,
                'role' => (string) $role,
                'role_label' => $labels[$role] ?? $role,
                'active' => $active,
                'clinician' => $clinicianByUser[$id] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * نقشه wp_user_id → نام پزشک، فقط برای Clinic مورد اعتمادِ همین فهرست.
     *
     * @return array<int, string>
     */
    private static function clinicianLinkMap(int $clinicId): array
    {
        if ($clinicId <= 0) {
            return [];
        }

        $rows = App::db()->fetchAll(
            'SELECT wp_user_id, full_name FROM ' . App::db()->table('cpms_clinicians') .
            ' WHERE clinic_id = %d AND wp_user_id IS NOT NULL AND is_active = 1',
            [$clinicId]
        );
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $uid = (int) ($r['wp_user_id'] ?? 0);
            if ($uid > 0) {
                $map[$uid] = (string) ($r['full_name'] ?? '');
            }
        }

        return $map;
    }

    /** @return array<string,string> */
    private static function roleLabels(): array
    {
        return [
            RolesAndCapabilities::ROLE_DOCTOR => 'پزشک (cpms_doctor)',
            RolesAndCapabilities::ROLE_SECRETARY => 'منشی مطب (cpms_secretary)',
            RolesAndCapabilities::ROLE_PATIENT => 'بیمار (cpms_patient)',
            RolesAndCapabilities::ROLE_ACCOUNTANT => 'حسابدار (cpms_accountant)',
            RolesAndCapabilities::ROLE_MANAGER => 'مدیر کلینیک (cpms_manager)',
        ];
    }

    /** @return array<string,mixed>|null */
    private static function editTarget(int $clinicId): ?array
    {
        $id = isset($_GET['edit']) ? absint($_GET['edit']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ($id <= 0 || $clinicId <= 0) {
            return null;
        }
        foreach (self::listUsers($clinicId) as $r) {
            if ((int) $r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    /** تولید رمز قوی (حداکثر امنیت، بدون کاراکترهای مبهم). */
    private static function generatePassword(): string
    {
        // هرگز از rand استفاده نمی‌شود؛ wp_generate_password (CSPRNG) است.
        return wp_generate_password(20, true, false);
    }

    private static function validatePassword(string $password): bool
    {
        if (mb_strlen($password) < 10) {
            return false;
        }

        return preg_match('/[A-Za-z]/', $password) === 1 && preg_match('/[0-9]/', $password) === 1;
    }
}

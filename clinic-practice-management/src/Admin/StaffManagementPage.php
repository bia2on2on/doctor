<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * «کاربران و دسترسی‌ها» (Staff/User Management) — Chunk C.
 *
 * طراحی امن:
 *  - فقط دارندگان `cpms_config` (مدیر کلینیک) دسترسی دارند (hidden menu ≠ authorization).
 *  - این صفحه فقط نقش‌های CPMS (پزشک/منشی/بیمار) را مدیریت می‌کند؛ «administrator» وردپرس
 *    نه قابل انتساب است نه قابل ویرایش/غیرفعال‌سازی از این‌جا → جلوگیری از privilege escalation
 *    و دست‌کاری مدیر کلینیک.
 *  - رمز عبور هرگز plaintext ذخیره/نمایش داده نمی‌شود؛ WP فقط hash ذخیره می‌کند.
 *    در صورت عدم تعیین رمز، یک رمز قوی تصادفی ساخته و «یک‌بار» در اعلان نمایش داده می‌شود
 *    (تحویل اعتبار) و به هیچ‌وجه در DB ذخیرهٔ رمز در دسترس نیست.
 *  - Nonce (CSRF) + Capability + Sanitize + bounded روی هر action.
 *  - هر تغییر (ایجاد/ویرایش/تغییر نقش/فعال/غیرفعال/بازنشانی رمز) در Audit ثبت می‌شود.
 *  - غیرفعال‌سازی: نقش CPMS به‌طور موقت به subscriber تغییر می‌کند و نقش اصلی در
 *    usermeta ذخیره می‌شود؛ تاریخچه و پیوندها حذف نمی‌شوند؛ فعال‌سازی نقش را بازمی‌گرداند.
 */
final class StaffManagementPage
{
    public const PAGE_SLUG = 'cpms-staff';

    private const NONCE_ACTION = 'cpms_staff_save';
    private const NOTICE_KEY = 'cpms_staff_notice';
    private const META_PREV_ROLE = 'cpms_previous_role';

    /** نقش‌های قابل‌مدیریت (بدون administrator — جلوگیری از escalation). */
    private const MANAGEABLE_ROLES = [
        RolesAndCapabilities::ROLE_DOCTOR,
        RolesAndCapabilities::ROLE_SECRETARY,
        RolesAndCapabilities::ROLE_PATIENT,
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_staff_save', [self::class, 'save']);
        add_action('admin_post_cpms_staff_toggle', [self::class, 'toggle']);
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

        $rows = self::listUsers();
        $roles = self::roleLabels();
        ?>
        <div class="wrap" dir="rtl">
            <h1>کاربران و دسترسی‌ها</h1>
            <p class="description">افزودن و مدیریت پرسنل کلینیک (پزشک، منشی، بیمار). این صفحه فقط نقش‌های CPMS را
                مدیریت می‌کند؛ مدیر وردپرس (administrator) از این‌جا قابل تغییر نیست.</p>

            <?php if (is_string($notice) && $notice !== '') : ?>
                <div class="notice <?php echo str_starts_with($notice, 'خطا') ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if ($rows !== []) : ?>
                <h2>فهرست پرسنل</h2>
                <table class="widefat striped" role="presentation">
                    <thead>
                        <tr><th>نام</th><th>ورود</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $r['display_name']); ?></td>
                            <td><?php echo esc_html((string) $r['login']); ?></td>
                            <td><?php echo esc_html((string) $r['email']); ?></td>
                            <td><?php echo esc_html((string) $r['role_label']); ?></td>
                            <td><?php echo $r['active'] ? '<span style="color:#00a32a;">فعال</span>' : '<span style="color:#d63638;">غیرفعال</span>'; ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&edit=' . (int) $r['id'])); ?>">ویرایش</a>
                                <?php if ($r['active']) : ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_staff_toggle&user_id=' . (int) $r['id'] . '&state=deactivate'), 'cpms_staff_toggle_' . (int) $r['id'])); ?>" onclick="return confirm('غیرفعال‌سازی: این کاربر به‌طور موقت از نقش CPMS حذف می‌شود (تاریخچه حذف نمی‌شود). ادامه می‌دهید؟');">غیرفعال</a>
                                <?php else : ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_staff_toggle&user_id=' . (int) $r['id'] . '&state=activate'), 'cpms_staff_toggle_' . (int) $r['id'])); ?>">فعال‌سازی مجدد</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <?php echo CpmsUi::emptyState('👥', 'هنوز پرسنلی ثبت نشده', 'کاربران کلینیک (پزشک، منشی، بیمار) را با فرم پایین اضافه کنید. فقط نقش‌های CPMS از این‌جا قابل مدیریت‌اند؛ administrator از این‌جا قابل تغییر نیست.', 'افزودن کاربر', admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>
            <?php endif; ?>

            <?php $edit = self::editTarget(); ?>
            <h2><?php echo $edit !== null ? 'ویرایش کاربر' : 'افزودن کاربر'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="cpms_staff_save">
                <input type="hidden" name="mode" value="<?php echo $edit !== null ? 'update' : 'create'; ?>">
                <input type="hidden" name="user_id" value="<?php echo $edit !== null ? (int) $edit['id'] : 0; ?>">

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
        </div>
        <?php
    }

    // ================= Save / actions =================

    public static function save(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG) || !is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer(self::NONCE_ACTION);

        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'create')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $in = [
            'mode' => $mode === 'update' ? 'update' : 'create',
            'user_id' => isset($_POST['user_id']) ? absint($_POST['user_id']) : 0,
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
        if (!current_user_can(RolesAndCapabilities::CONFIG) || !is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer('cpms_staff_toggle_' . $userId);

        $state = (string) ($_GET['state'] ?? '');
        $result = self::toggleUser($userId, $state, (int) get_current_user_id());
        set_transient(self::NOTICE_KEY, $result['error'] !== '' ? 'خطا: ' . $result['error'] : 'وضعیت کاربر به‌روزرسانی شد.', 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
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
        $mode = ($in['mode'] ?? 'create') === 'update' ? 'update' : 'create';
        $userId = (int) ($in['user_id'] ?? 0);
        $username = trim((string) ($in['username'] ?? ''));
        $displayName = trim((string) ($in['display_name'] ?? ''));
        $email = (string) ($in['email'] ?? '');
        $role = trim((string) ($in['role'] ?? ''));
        $password = (string) ($in['password'] ?? '');

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
            App::audit()->log('STAFF_USER_CREATED', ['wp_user_id' => $updatedBy], 'user', $userId, null, null, ['role' => $role, 'login' => $username]);
        } else {
            if ($userId <= 0 || !self::isManageable($userId)) {
                return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.', 'generated' => '', 'user_id' => 0];
            }
            $args = ['ID' => $userId, 'display_name' => $displayName, 'user_email' => $email, 'role' => $role];
            if ($password !== '') {
                $args['user_pass'] = $plainPassword;
            }
            $res = wp_update_user($args);
            if (is_wp_error($res)) {
                return ['error' => $res->get_error_message(), 'generated' => '', 'user_id' => $userId];
            }
            // اگر کاربر قبلاً غیرفعال بود (subscriber + usermeta)، اکنون نقش واقعی گرفت → پاک کردن flag.
            if (get_user_meta($userId, self::META_PREV_ROLE, true) !== '') {
                delete_user_meta($userId, self::META_PREV_ROLE);
            }
            App::audit()->log('STAFF_USER_UPDATED', ['wp_user_id' => $updatedBy], 'user', $userId, null, null, ['role' => $role, 'password_reset' => $password !== '']);
        }

        return ['error' => '', 'generated' => $generated, 'user_id' => $userId];
    }

    /**
     * فعال/غیرفعال‌سازی — نقش CPMS را موقتاً به subscriber تغییر می‌دهد (بدون حذف تاریخچه).
     *
     * @return array{error:string}
     */
    public static function toggleUser(int $userId, string $state, int $updatedBy): array
    {
        if ($userId <= 0 || !self::isManageable($userId)) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.'];
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return ['error' => 'کاربر یافت نشد.'];
        }

        if ($state === 'deactivate') {
            $prevRole = (string) ($user->roles[0] ?? '');
            if (!in_array($prevRole, self::MANAGEABLE_ROLES, true)) {
                return ['error' => 'این کاربر نقش قابل غیرفعال‌سازی ندارد.'];
            }
            update_user_meta($userId, self::META_PREV_ROLE, $prevRole);
            wp_update_user(['ID' => $userId, 'role' => 'subscriber']);
            App::audit()->log('STAFF_USER_DEACTIVATED', ['wp_user_id' => $updatedBy], 'user', $userId, null, ['role' => $prevRole], ['role' => 'subscriber']);
        } else {
            $prevRole = (string) get_user_meta($userId, self::META_PREV_ROLE, true);
            if ($prevRole === '' || !in_array($prevRole, self::MANAGEABLE_ROLES, true)) {
                return ['error' => 'نقش قبلی برای فعال‌سازی یافت نشد.'];
            }
            delete_user_meta($userId, self::META_PREV_ROLE);
            wp_update_user(['ID' => $userId, 'role' => $prevRole]);
            App::audit()->log('STAFF_USER_ACTIVATED', ['wp_user_id' => $updatedBy], 'user', $userId, null, ['role' => 'subscriber'], ['role' => $prevRole]);
        }

        return ['error' => ''];
    }

    // ================= helpers =================

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
    private static function listUsers(): array
    {
        $rows = [];
        $users = get_users([
            'role__in' => self::MANAGEABLE_ROLES,
            'fields' => 'all',
            'number' => 500,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        // کاربران غیرفعال (دارای usermeta cpms_previous_role) نیز نمایش داده شوند.
        $users = array_merge($users, get_users([
            'meta_key' => self::META_PREV_ROLE,
            'fields' => 'all',
            'number' => 500,
        ]));

        $seen = [];
        $labels = self::roleLabels();
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
            ];
        }

        return $rows;
    }

    /** @return array<string,string> */
    private static function roleLabels(): array
    {
        return [
            RolesAndCapabilities::ROLE_DOCTOR => 'پزشک (cpms_doctor)',
            RolesAndCapabilities::ROLE_SECRETARY => 'منشی مطب (cpms_secretary)',
            RolesAndCapabilities::ROLE_PATIENT => 'بیمار (cpms_patient)',
        ];
    }

    /** @return array<string,mixed>|null */
    private static function editTarget(): ?array
    {
        $id = isset($_GET['edit']) ? absint($_GET['edit']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ($id <= 0) {
            return null;
        }
        foreach (self::listUsers() as $r) {
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

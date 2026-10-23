<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleAssignmentException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Infrastructure\UserCacheRollback;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
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

    private const NONCE_ACTION             = 'cpms_staff_save';
    private const NONCE_ASSIGN             = 'cpms_staff_assign_custom_role';
    private const NONCE_REASSIGN_SUSPENDED = 'cpms_staff_reassign_suspended_custom_role';
    private const NOTICE_KEY               = 'cpms_staff_notice';
    private const META_PREV_ROLE           = 'cpms_previous_role';

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
        add_action( 'admin_post_cpms_staff_assign_custom_role', [self::class, 'assign_custom_role'] );
        add_action( 'admin_post_cpms_staff_reassign_suspended_custom_role', [self::class, 'reassign_suspended_custom_role'] );
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

        $clinic_id      = self::formClinicId();
        $rows           = self::list_users( $clinic_id );
        $roles = self::roleLabels();
        $clinic_choices = self::managed_clinic_choices( (int) get_current_user_id() );
        $assignable     = self::assignment_options( $clinic_id, $rows );
        ?>
        <div class="wrap" dir="rtl">
            <h1>کاربران و دسترسی‌ها</h1>
            <p class="description">افزودن و مدیریت عضویت‌ها و نقش‌های CPMS در Clinic انتخاب‌شده. انتخاب Clinic باید صریح باشد؛ هیچ Clinic پیش‌فرضی حدس زده نمی‌شود. نقش‌ها و GRANT/DENY برای عضویت Clinic-local هستند، نه Organization یا Location. مدیر WordPress (administrator) از این‌جا قابل تغییر نیست و صرفاً administrator بودن هیچ دسترسی بالینی/مالی/پرونده‌ای ایجاد نمی‌کند.</p>

            <?php if ( $clinic_choices !== [] ) : ?>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="cpms-autofocus">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <label for="cpms_staff_clinic">Clinic هدف</label>
                    <select id="cpms_staff_clinic" name="clinic_id" required>
                        <option value="">انتخاب Clinic</option>
                        <?php foreach ( $clinic_choices as $choice ) : ?>
                            <option value="<?php echo (int) $choice['clinic_id']; ?>" <?php selected( $clinic_id, (int) $choice['clinic_id'] ); ?>><?php echo esc_html( (string) $choice['clinic_name'] ); ?></option>
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
                                <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&edit=' . (int) $r['id'] . '&clinic_id=' . $clinic_id ) ); ?>">ویرایش</a>
                                <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cpms_staff_password&user_id=' . (int) $r['id'] . '&clinic_id=' . $clinic_id ), 'cpms_staff_password_' . (int) $r['id'] ) ); ?>">لینک بازیابی رمز</a>
                                <?php if ($r['active']) : ?>
                                    <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cpms_staff_toggle&user_id=' . (int) $r['id'] . '&state=deactivate&clinic_id=' . $clinic_id ), 'cpms_staff_toggle_' . (int) $r['id'] ) ); ?>" data-cpms-confirm="غیرفعال‌سازی: این کاربر به‌طور موقت از نقش CPMS حذف می‌شود (تاریخچه حذف نمی‌شود). ادامه می‌دهید؟">غیرفعال</a>
                                <?php else : ?>
                                    <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cpms_staff_toggle&user_id=' . (int) $r['id'] . '&state=activate&clinic_id=' . $clinic_id ), 'cpms_staff_toggle_' . (int) $r['id'] ) ); ?>">فعال‌سازی مجدد</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ( $clinic_id > 0 ) : ?>
                <?php echo wp_kses_post( CpmsUi::emptyState( '👥', 'هنوز پرسنلی ثبت نشده', 'کاربران و عضویت‌های Clinic انتخاب‌شده را از فرم پایین اضافه کنید. administrator از این‌جا قابل تغییر نیست.', 'افزودن کاربر', admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id ) ) ); ?>
            <?php endif; ?>

            <?php if ( $clinic_id > 0 ) : ?>
                <?php $edit = self::edit_target( $clinic_id ); ?>
                <h2><?php echo $edit !== null ? 'ویرایش کاربر' : 'افزودن کاربر'; ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="cpms_staff_save">
                    <input type="hidden" name="mode" value="<?php echo $edit !== null ? 'update' : 'create'; ?>">
                    <input type="hidden" name="user_id" value="<?php echo $edit !== null ? (int) $edit['id'] : 0; ?>">
                    <input type="hidden" name="clinic_id" value="<?php echo (int) $clinic_id; ?>">

                    <table class="form-table" role="presentation">
                        <?php if ( $edit === null ) : ?>
                            <tr><th><label for="u_login">نام کاربری (ورود)</label></th><td><input type="text" id="u_login" name="username" class="regular-text" required value=""></td></tr>
                        <?php else : ?>
                            <tr><th>نام کاربری</th><td><?php echo esc_html( (string) $edit['login'] ); ?> <code>(ثابت)</code></td></tr>
                        <?php endif; ?>
                        <tr><th><label for="u_disp">نام نمایشی</label></th><td><input type="text" id="u_disp" name="display_name" class="regular-text" required value="<?php echo esc_attr( $edit !== null ? (string) $edit['display_name'] : '' ); ?>"></td></tr>
                        <tr><th><label for="u_email">ایمیل</label></th><td><input type="email" id="u_email" name="email" class="regular-text" required value="<?php echo esc_attr( $edit !== null ? (string) $edit['email'] : '' ); ?>"></td></tr>
                        <tr><th><label for="u_role">نقش CPMS</label></th>
                            <td><select id="u_role" name="role">
                                <?php if ( $edit !== null && '' !== (string) ( $edit['membership_role'] ?? '' ) && ! in_array( (string) $edit['membership_role'], array_keys( $roles ), true ) ) : ?>
                                    <option value="" selected><?php echo esc_html( '— حفظ نقش سفارشی فعلی: ' . (string) $edit['role_label'] . ' (بدون تغییر عضویت و بدون تغییر نقش WordPress) —' ); ?></option>
                                <?php endif; ?>
                                <?php foreach ( $roles as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $edit === null ? '' : (string) $edit['role'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                                <?php if ( $edit !== null && '' !== (string) ( $edit['membership_role'] ?? '' ) && ! in_array( (string) $edit['membership_role'], array_keys( $roles ), true ) ) : ?>
                                    <p class="description">این کارمند در این Clinic یک نقش سفارشی دارد. با گزینهٔ بالا فقط نام/ایمیل/رمز تغییر می‌کند؛ اگر یکی از نقش‌های CPMS را انتخاب کنید، انتساب سفارشی با همان نقش داخلی جایگزین می‌شود.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th><label for="u_password">رمز عبور</label></th>
                            <td>
                                <input type="password" id="u_password" name="password" class="regular-text" autocomplete="new-password" value="">
                                <p class="description">برای «افزودن» خالی بگذارید تا رمز قوی تصادفی ساخته و یک‌بار نمایش داده شود؛ یا رمز دلخواه (حداقل ۱۰ کاراکتر شامل حرف و عدد) وارد کنید. رمز هرگز به‌صورت plaintext ذخیره نمی‌شود.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><button type="submit" class="button button-primary"><?php echo $edit !== null ? 'ذخیره تغییرات' : 'افزودن کاربر'; ?></button>
                    <?php if ( $edit !== null ) : ?>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . $clinic_id ) ); ?>">انصراف</a>
                    <?php endif; ?>
                    </p>
                </form>

                <?php if ( $clinic_id > 0 ) : ?>
                    <h2>افزودن کاربر موجود به این Clinic</h2>
                    <p class="description">شناسهٔ عددی WordPress حساب موجود را وارد کنید. این عملیات حساب WordPress یا پروفایل حرفه‌ای جدید نمی‌سازد؛ فقط عضویت همین کاربر را در Clinic انتخاب‌شده ایجاد می‌کند.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        <input type="hidden" name="action" value="cpms_staff_save">
                        <input type="hidden" name="mode" value="attach_existing">
                        <input type="hidden" name="clinic_id" value="<?php echo (int) $clinic_id; ?>">
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
                                        <?php foreach ( $roles as $slug => $label ) : ?>
                                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">نقش این عضویت به‌صورت Clinic-scoped اعمال می‌شود؛ Membership با مجوز یکی نیست.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit"><button type="submit" class="button button-primary">افزودن کاربر موجود</button></p>
                    </form>
                <?php endif; ?>

                <?php if ( [] !== $assignable['definitions'] && [] !== $assignable['members'] ) : ?>
                    <h2 id="cpms-staff-role-assignment">انتساب نقش سفارشی Clinic</h2>
                    <p class="description">
                        این انتساب فقط روی «عضویت همین Clinic» اثر می‌گذارد: نقش سراسری WordPress تغییر نمی‌کند، پروفایل حرفه‌ای (پزشک) دست‌نخورده
                        می‌ماند و ردیف‌های GRANT/DENY عضویت جابه‌جا نمی‌شوند. فقط نقش‌های سفارشیِ <strong>فعالِ همین Clinic</strong> و فقط تا سقف
                        اختیار مؤثر شما قابل انتساب‌اند. بازگردانی به نقش داخلی از همان فرم «ویرایش» این صفحه (انتخاب یکی از نقش‌های CPMS) انجام می‌شود.
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( self::NONCE_ASSIGN ); ?>
                        <input type="hidden" name="action" value="cpms_staff_assign_custom_role">
                        <input type="hidden" name="clinic_id" value="<?php echo (int) $clinic_id; ?>">
                        <table class="form-table" role="presentation">
                            <tr><th><label for="cpms_assign_target">کارمند موجود (عضویت فعال)</label></th>
                                <td>
                                    <select id="cpms_assign_target" name="target_user_id" required>
                                        <?php foreach ( $assignable['members'] as $member ) : ?>
                                            <option value="<?php echo (int) $member['id']; ?>"><?php echo esc_html( (string) $member['label'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">فقط عضویت‌های فعال همین Clinic؛ این عمل هیچ عضویت، حساب WordPress یا پروفایل حرفه‌ای تازه‌ای نمی‌سازد.</p>
                                </td>
                            </tr>
                            <tr><th><label for="cpms_assign_role">نقش سفارشی</label></th>
                                <td>
                                    <select id="cpms_assign_role" name="custom_role_key" required>
                                        <?php foreach ( $assignable['definitions'] as $definition ) : ?>
                                            <?php
                                            $cpms_definition_label  = ( null !== $definition['display_name'] && '' !== $definition['display_name'] )
                                                ? (string) $definition['display_name']
                                                : (string) $definition['role_key'];
                                            $cpms_definition_effect = $definition['empty']
                                                ? 'بدون هیچ مجوزی — فقط برچسب عضویت؛ هیچ دسترسی‌ای ایجاد نمی‌کند'
                                                : 'مجوزها: ' . implode( ', ', $definition['capabilities'] );
                                            ?>
                                            <option value="<?php echo esc_attr( (string) $definition['role_key'] ); ?>"><?php echo esc_html( $cpms_definition_label . ' — ' . $cpms_definition_effect ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">نقش‌هایی که مجوزی خارج از اختیار مؤثر شما در این Clinic دارند در این فهرست نمایش داده نمی‌شوند و انتساب آن‌ها رد می‌شود.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit"><button type="submit" class="button button-primary">انتساب نقش سفارشی</button></p>
                    </form>
                <?php endif; ?>
                <?php self::render_suspended_reassignment( $clinic_id, $assignable ); ?>
            <?php else : ?>
                <div class="notice notice-info"><p>برای ادامه، Clinic را صریحاً از فهرست بالا انتخاب کنید. فرم‌های افزودن و انتساب نقش تا انتخاب صریح غیرفعال می‌مانند.</p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array{definitions:list<array{role_key:string,display_name:string|null,capabilities:list<string>,empty:bool}>,members:list<array{id:int,label:string}>,suspended_members:list<array{id:int,label:string}>} $options
     */
    private static function render_suspended_reassignment( int $clinic_id, array $options ): void {
        if ( $options['definitions'] === [] || $options['suspended_members'] === [] ) {
            return;
        }
        echo '<h2>' . esc_html__( 'تغییر نقش عضویت معلق (بدون فعال‌سازی)', 'cpms' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'برای رفع ارجاع نقش پیش از بایگانی: فقط نقش عضویت همین Clinic عوض می‌شود؛ تعلیق، GRANT/DENY، اطلاعات بازگردانی، نقش WordPress، هویت حرفه‌ای و محل‌های تخصیص‌یافته حفظ می‌شوند.', 'cpms' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::NONCE_REASSIGN_SUSPENDED );
        echo '<input type="hidden" name="action" value="cpms_staff_reassign_suspended_custom_role">';
        echo '<input type="hidden" name="clinic_id" value="' . esc_attr( (string) $clinic_id ) . '">';
        echo '<label for="cpms-suspended-target">' . esc_html__( 'عضویت معلق', 'cpms' ) . '</label> ';
        echo '<select id="cpms-suspended-target" name="target_user_id" required>';
        foreach ( $options['suspended_members'] as $member ) {
            echo '<option value="' . esc_attr( (string) $member['id'] ) . '">' . esc_html( $member['label'] ) . '</option>';
        }
        echo '</select> ';
        echo '<label for="cpms-suspended-role">' . esc_html__( 'نقش سفارشی فعال', 'cpms' ) . '</label> ';
        echo '<select id="cpms-suspended-role" name="custom_role_key" required>';
        foreach ( $options['definitions'] as $definition ) {
            $label = $definition['display_name'] ?? $definition['role_key'];
            echo '<option value="' . esc_attr( $definition['role_key'] ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button">' . esc_html__( 'تغییر نقش و حفظ تعلیق', 'cpms' ) . '</button>';
        echo '</form>';
    }

    // ================= Save / actions =================

    public static function save(): void
    {
        if (!is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        // current_user_can() is not Clinic authorization; the scoped write guard below is authoritative.
        check_admin_referer(self::NONCE_ACTION);

        $raw_mode         = isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'create';
        $mode             = is_string( $raw_mode ) ? sanitize_key( $raw_mode ) : 'create';
        $mode             = in_array( $mode, [ 'update', 'attach_existing' ], true ) ? $mode : 'create';
        $raw_clinic_id    = isset( $_POST['clinic_id'] ) && is_scalar( $_POST['clinic_id'] )
            ? absint( wp_unslash( (string) $_POST['clinic_id'] ) )
            : null;
        $raw_user_id      = isset( $_POST['user_id'] ) && is_scalar( $_POST['user_id'] )
            ? absint( wp_unslash( (string) $_POST['user_id'] ) )
            : 0;
        $actor_user_id    = (int) get_current_user_id();
        $preauthorization = self::authorize_staff_write(
            $actor_user_id,
            $raw_clinic_id,
            $mode === 'update' ? $raw_user_id : null
        );

        if ( $preauthorization['error'] !== '' ) {
            $result = [ 'error' => $preauthorization['error'], 'generated' => '', 'user_id' => 0 ];
        } else {
            // Sanitize user-controlled profile values only after the requested
            // Clinic (and durable target membership for updates) is authorized.
            $in     = [
                'mode'             => $mode,
                'user_id'          => $raw_user_id,
                'existing_user_id' => isset( $_POST['existing_user_id'] ) && is_scalar( $_POST['existing_user_id'] ) ? absint( wp_unslash( (string) $_POST['existing_user_id'] ) ) : 0,
                'clinic_id'        => $raw_clinic_id,
                'username'         => isset( $_POST['username'] ) && is_string( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                'display_name'     => isset( $_POST['display_name'] ) && is_string( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                'email'            => isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                'role'             => isset( $_POST['role'] ) && is_string( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                'password'         => isset( $_POST['password'] ) && is_scalar( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            ];
            $result = self::upsertUser( $in, $actor_user_id );
        }
        $notice = $result['error'] !== ''
            ? 'خطا: ' . $result['error']
            : ($result['generated'] !== '' ? 'کاربر ثبت شد. رمز یک‌باره: ' . $result['generated'] . ' (این رمز فقط همین حالا نمایش داده می‌شود — آن را به کاربر بدهید.)' : 'ذخیره شد.');
        set_transient(self::NOTICE_KEY, $notice, 60);
        $redirect_clinic_id = (int) ( $raw_clinic_id ?? 0 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . max( 0, $redirect_clinic_id ) ) );
        exit;
    }

    public static function toggle(): void
    {
        $raw_user_id = isset( $_GET['user_id'] ) && is_scalar( $_GET['user_id'] ) ? wp_unslash( (string) $_GET['user_id'] ) : '0';
        $user_id     = absint( $raw_user_id );
        if (!is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer( 'cpms_staff_toggle_' . $user_id );

        $raw_state     = isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '';
        $state         = is_string( $raw_state ) ? $raw_state : '';
        $raw_clinic_id = isset( $_GET['clinic_id'] ) && is_scalar( $_GET['clinic_id'] ) ? wp_unslash( (string) $_GET['clinic_id'] ) : null;
        $clinic_id     = $raw_clinic_id !== null ? absint( $raw_clinic_id ) : null;
        $result        = self::toggleUser( $user_id, $state, (int) get_current_user_id(), $clinic_id );
        set_transient(self::NOTICE_KEY, $result['error'] !== '' ? 'خطا: ' . $result['error'] : 'وضعیت کاربر به‌روزرسانی شد.', 60);
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . max( 0, (int) $clinic_id ) ) );
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
        $raw_user_id = isset( $_GET['user_id'] ) && is_scalar( $_GET['user_id'] ) ? wp_unslash( (string) $_GET['user_id'] ) : '0';
        $user_id     = absint( $raw_user_id );
        if (!is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer( 'cpms_staff_password_' . $user_id );

        $raw_clinic_id = isset( $_GET['clinic_id'] ) && is_scalar( $_GET['clinic_id'] ) ? wp_unslash( (string) $_GET['clinic_id'] ) : null;
        $clinic_id     = $raw_clinic_id !== null ? absint( $raw_clinic_id ) : null;
        $result        = self::initiatePasswordReset( $user_id, (int) get_current_user_id(), $clinic_id );
        if ($result['error'] !== '') {
            set_transient(self::NOTICE_KEY, 'خطا: ' . $result['error'], 60);
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . max( 0, (int) $clinic_id ) ) );
            exit;
        }

        set_transient(self::NOTICE_KEY, 'لینک بازنشانی/تنظیم رمز برای «' . $result['display_name'] . '» ایمیل شد.', 60);
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . max( 0, (int) $clinic_id ) ) );
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
    public static function initiatePasswordReset( int $user_id, int $updated_by, ?int $clinic_id = null ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy method name: renaming it is a cross-file API change outside Slice 3B.
        $authorization = self::authorize_staff_write( $updated_by, $clinic_id, $user_id );
        if ( $authorization['error'] !== '' ) {
            return ['error' => $authorization['error'], 'display_name' => ''];
        }

        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        if ( $user === false || ! self::is_manageable( $user_id ) ) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.', 'display_name' => ''];
        }

        // D6/D7 ordering: the reset-key metadata (a user-meta write) and the
        // mandatory audit event share one boundary. The notification email is a
        // non-transactional side effect, so it is dispatched ONLY after the
        // durable commit succeeds — a rolled-back key never reaches an inbox and
        // a failed audit cannot leave a working un-audited reset link behind.
        $reset_key = '';
        try {
            App::db()->transactional(
                function () use ( $user, $user_id, $updated_by, $authorization, &$reset_key ) {
                    // Core key writer WITHOUT the email side effect that
                    // retrieve_password() bundles in.
                    $key = get_password_reset_key( $user );
                    if ( is_wp_error( $key ) ) {
                        throw new \RuntimeException( $key->get_error_message() );
                    }
                    $reset_key = (string) $key;

                    App::audit()->log(
                        'STAFF_PASSWORD_RESET_INITIATED',
                        [ 'wp_user_id' => $updated_by ],
                        'user',
                        $user_id,
                        null,
                        null,
                        [
                            'clinic_id' => $authorization['clinic_id'],
                            'login'     => (string) $user->user_login,
                        ],
                        [],
                        $authorization['clinic_id']
                    );
                }
            );
        } catch ( \Throwable $e ) {
            // The boundary rolled the reset-key metadata back; no email was sent.
            UserCacheRollback::invalidate( [ [ 'id' => $user_id ] ] );
            return ['error' => $e->getMessage(), 'display_name' => ''];
        }

        // Post-commit side effect: the exact core notification, built from the
        // same pluggable helper WordPress itself uses (filters preserved).
        if ( ! self::sendPasswordResetEmail( $user, $reset_key ) ) {
            // Key and audit are already durable; report the delivery failure
            // honestly instead of a false success. The single-use key expires on
            // WordPress' normal reset-window schedule.
            return [
                'error'        => 'ایمیل بازنشانی رمز ارسال نشد؛ لطفاً دوباره تلاش کنید.',
                'display_name' => '',
            ];
        }

        return ['error' => '', 'display_name' => (string) $user->display_name];
    }

    /**
     * Build and dispatch the core WordPress password-reset notification for an
     * already-persisted reset key. Runs strictly outside any transaction.
     */
    private static function sendPasswordResetEmail( \WP_User $user, string $reset_key ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors WordPress core naming.
        $switched_locale = switch_to_user_locale( $user->ID );
        try {
            // Inline equivalent of core's retrieve_password_notification_email()
            // (WP 6.0+, wp-includes/user.php): same default subject/message and
            // the same two pluggable filters, kept local because the pinned
            // php-stubs release shipped to PHPStan does not declare that helper.
            $blog_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
            $subject   = sprintf( '[%s] Password Reset', $blog_name );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- canonical WordPress core filter name; renaming would break the core password-reset extension contract.
            $subject   = (string) apply_filters( 'retrieve_password_title', $subject, $user->user_login, $user );
            $message   = 'Someone has requested a password reset for the following account:' . "\r\n\r\n";
            $message  .= sprintf( 'Site Name: %s', $blog_name ) . "\r\n\r\n";
            $message  .= sprintf( 'Username: %s', (string) $user->user_login ) . "\r\n\r\n";
            $message  .= 'If this was a mistake, ignore this email and nothing will happen.' . "\r\n\r\n";
            $message  .= 'To reset your password, visit the following address:' . "\r\n\r\n";
            $message  .= network_site_url( 'wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode( (string) $user->user_login ), 'login' ) . "\r\n";
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- canonical WordPress core filter name; renaming would break the core password-reset extension contract.
            $message   = (string) apply_filters( 'retrieve_password_message', $message, $reset_key, $user->user_login, $user );
            if ( '' === $message ) {
                return false;
            }
            $sent = wp_mail( $user->user_email, wp_specialchars_decode( $subject ), $message, '' );
        } finally {
            if ( $switched_locale ) {
                restore_previous_locale();
            }
        }

        return (bool) $sent;
    }

    /**
     * ایجاد/ویرایش کاربر — خالص و قابل تست (بدون exit/die).
     *
     * @param array<string, mixed> $in
     *
     * @return array{error:string, generated:string, user_id:int}
     */
    public static function upsertUser( array $in, int $updated_by ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy method name: renaming it is a cross-file API change outside Slice 3B.
        if ( $updated_by <= 0 || ! is_user_logged_in() || (int) get_current_user_id() !== $updated_by ) {
            return ['error' => 'کاربر احراز هویت‌شده برای این عملیات معتبر نیست.', 'generated' => '', 'user_id' => 0];
        }

        $mode_raw = $in['mode'] ?? 'create';
        if ( $mode_raw === 'attach_existing' ) {
            return self::attachExistingUser( $in, $updated_by );
        }
        $mode                = $mode_raw === 'update' ? 'update' : 'create';
        $raw_user_id         = $in['user_id'] ?? 0;
        $user_id             = is_scalar( $raw_user_id ) ? absint( (string) $raw_user_id ) : 0;
        $raw_clinic_id       = array_key_exists( 'clinic_id', $in ) ? $in['clinic_id'] : null;
        $requested_clinic_id = $raw_clinic_id !== null && is_scalar( $raw_clinic_id )
            ? absint( (string) $raw_clinic_id )
            : null;

        $authorization = self::authorize_staff_write(
            $updated_by,
            $requested_clinic_id,
            $mode === 'update' ? $user_id : null
        );
        if ( $authorization['error'] !== '' ) {
            return ['error' => $authorization['error'], 'generated' => '', 'user_id' => 0];
        }
        if ( $mode === 'update' && ( $user_id <= 0 || ! self::is_manageable( $user_id ) ) ) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.', 'generated' => '', 'user_id' => 0];
        }

        // Convert/validate caller-controlled profile fields only after the actor
        // has durable authority for the explicitly selected Clinic and target.
        $username     = is_string( $in['username'] ?? null ) ? trim( $in['username'] ) : '';
        $display_name = is_string( $in['display_name'] ?? null ) ? trim( $in['display_name'] ) : '';
        $email        = is_string( $in['email'] ?? null ) ? $in['email'] : '';
        $role         = is_string( $in['role'] ?? null ) ? trim( $in['role'] ) : '';
        $password     = is_string( $in['password'] ?? null ) ? $in['password'] : '';

        $clinic_id         = $authorization['clinic_id'];
        $target_membership = $authorization['target_membership'];

        // حفظ نقش سفارشیِ عضویت (Custom Role Slice 3B): فقط در ویرایشِ کارمندی که
        // همین حالا یک نقش سفارشیِ Clinic-local دارد و فقط وقتی مدیر هیچ نقش داخلی
        // انتخاب نکرده باشد. در این حالت نه نقش سراسری WordPress و نه role_key
        // عضویت تغییر نمی‌کند — هیچ انتساب/بازگردانیِ ضمنی رخ نمی‌دهد.
        $preserve_membership_role = false;
        if ( $mode === 'update' && $role === '' && $target_membership !== null ) {
            $membership_role_key      = (string) ( $target_membership['role_key'] ?? '' );
            $preserve_membership_role = ! in_array( $membership_role_key, self::MANAGEABLE_ROLES, true )
                && CustomRolePolicy::is_definable_role_key( $membership_role_key );
        }

        if ( ! $preserve_membership_role && ( $role === '' || ! in_array( $role, self::MANAGEABLE_ROLES, true ) ) ) {
            return ['error' => 'نقش غیرمجاز است (فقط نقش‌های CPMS قابل انتساب‌اند؛ از انتساب administrator جلوگیری شد).', 'generated' => '', 'user_id' => 0];
        }
        if ( $display_name === '' ) {
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
        $plain_password = $password !== '' ? $password : $generated;

        if ( $mode === 'create' ) {
            // D6 audit atomicity: the WordPress user, its Clinic membership and
            // the mandatory audit event share one transaction boundary. A failed
            // membership write or audit INSERT rolls the freshly created
            // WordPress user back too (the old manual wp_delete_user()
            // compensation is replaced by the rollback itself).
            $create_result = null;
            try {
                $create_result = App::db()->transactional(
                    function () use (
                        $username,
                        $plain_password,
                        $email,
                        $display_name,
                        $role,
                        $clinic_id,
                        $updated_by,
                        &$user_id
                    ) {
                        $inserted = wp_insert_user(
                            [
                                'user_login'   => $username,
                                'user_pass'    => $plain_password,
                                'user_email'   => $email,
                                'display_name' => $display_name,
                                'role'         => $role,
                            ]
                        );
                        if ( is_wp_error( $inserted ) ) {
                            // No row was written; pass the original WP error through.
                            return [ 'error' => $inserted->get_error_message(), 'generated' => '', 'user_id' => 0 ];
                        }
                        $user_id = (int) $inserted;
                        App::membership_service()->create_membership(
                            $clinic_id,
                            $user_id,
                            $role,
                            'clinic',
                            $updated_by
                        );
                        App::audit()->log(
                            'STAFF_USER_CREATED',
                            [ 'wp_user_id' => $updated_by ],
                            'user',
                            $user_id,
                            null,
                            null,
                            [ 'clinic_id' => $clinic_id, 'role' => $role, 'login' => $username ],
                            [],
                            $clinic_id
                        );

                        return null;
                    }
                );
            } catch ( \Throwable $e ) {
                // The boundary already rolled the WordPress user and membership
                // back (to the SAVEPOINT when nested). WordPress' object cache —
                // including persistent Redis/Memcached backends that survive the
                // request — may still hold the ghost identity. Evict exactly the
                // WP-owned keys for this descriptor after the rollback, never a
                // global wp_cache_flush(); tolerate a vanished id.
                if ( $user_id > 0 ) {
                    UserCacheRollback::invalidate(
                        [
                            [
                                'id'    => $user_id,
                                'login' => $username,
                                'email' => $email,
                            ],
                        ]
                    );
                }
                return [ 'error' => 'عضویت کاربر در Clinic ایجاد نشد.', 'generated' => '', 'user_id' => 0 ];
            }
            if ( $create_result !== null ) {
                return $create_result;
            }
        } else {
            // D6 audit atomicity: the WordPress profile update, membership role
            // write, meta cleanup and mandatory audit event commit or roll back
            // together — no un-audited staff update and no false success.
            $update_result = null;
            try {
                $update_result = App::db()->transactional(
                    function () use (
                        $user_id,
                        $display_name,
                        $email,
                        $preserve_membership_role,
                        $role,
                        $password,
                        $plain_password,
                        $target_membership,
                        $clinic_id,
                        $updated_by
                    ) {
                        $args = [ 'ID' => $user_id, 'display_name' => $display_name, 'user_email' => $email ];
                        if ( ! $preserve_membership_role ) {
                            $args['role'] = $role;
                        }
                        if ( $password !== '' ) {
                            $args['user_pass'] = $plain_password;
                        }
                        $res = wp_update_user( $args );
                        if ( is_wp_error( $res ) ) {
                            // Nothing was written; pass the original WP error through.
                            return [ 'error' => $res->get_error_message(), 'generated' => '', 'user_id' => $user_id ];
                        }
                        if ( ! $preserve_membership_role && $target_membership !== null ) {
                            App::membership_service()->set_role_key( (int) $target_membership['id'], $role );
                        }
                        // اگر کاربر قبلاً غیرفعال بود (subscriber + usermeta)، اکنون نقش واقعی گرفت → پاک کردن flag.
                        if ( get_user_meta( $user_id, self::META_PREV_ROLE, true ) !== '' ) {
                            delete_user_meta( $user_id, self::META_PREV_ROLE );
                        }
                        $effective_role = $preserve_membership_role
                            ? (string) ( $target_membership['role_key'] ?? '' )
                            : $role;
                        App::audit()->log(
                            'STAFF_USER_UPDATED',
                            [ 'wp_user_id' => $updated_by ],
                            'user',
                            $user_id,
                            null,
                            null,
                            [
                                'clinic_id'      => $clinic_id,
                                'role'           => $effective_role,
                                'role_scope'     => $preserve_membership_role ? 'clinic_local_membership' : 'builtin_cpms_role',
                                'password_reset' => $password !== '',
                            ],
                            [],
                            $clinic_id
                        );

                        return null;
                    }
                );
            } catch ( \Throwable $e ) {
                // Rollback restored the pre-update row; the cache may still hold
                // the new email key and rewritten users/user_meta entries, so
                // evict the WP-owned keys (login is unchanged on this path).
                UserCacheRollback::invalidate(
                    [
                        [
                            'id'    => $user_id,
                            'email' => $email,
                        ],
                    ]
                );
                return [
                    'error'     => 'عملیات به‌روزرسانی کاربر کامل نشد؛ هیچ تغییری ثبت نشد.',
                    'generated' => '',
                    'user_id'   => $user_id,
                ];
            }
            if ( $update_result !== null ) {
                return $update_result;
            }
        }

        return ['error' => '', 'generated' => $generated, 'user_id' => $user_id];
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
    public static function attachExistingUser( array $in, int $updated_by ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy method name: renaming it is a cross-file API change outside Slice 3B.
        if ( $updated_by <= 0 || ! is_user_logged_in() || (int) get_current_user_id() !== $updated_by ) {
            return ['error' => 'کاربر احراز هویت‌شده برای این عملیات معتبر نیست.', 'generated' => '', 'user_id' => 0];
        }

        $raw_clinic_id       = array_key_exists( 'clinic_id', $in ) ? $in['clinic_id'] : null;
        $requested_clinic_id = $raw_clinic_id !== null && is_scalar( $raw_clinic_id )
            ? absint( (string) $raw_clinic_id )
            : null;
        $authorization       = self::authorize_staff_write( $updated_by, $requested_clinic_id );
        if ($authorization['error'] !== '') {
            return ['error' => $authorization['error'], 'generated' => '', 'user_id' => 0];
        }

        $role = trim((string) ($in['role'] ?? ''));
        if ($role === '' || !in_array($role, self::MANAGEABLE_ROLES, true)) {
            return ['error' => 'نقش غیرمجاز است (فقط نقش‌های CPMS قابل انتساب‌اند؛ از انتساب administrator جلوگیری شد).', 'generated' => '', 'user_id' => 0];
        }

        $existing_user_id = (int) ( $in['existing_user_id'] ?? 0 );
        $existing_user    = $existing_user_id > 0 ? get_userdata( $existing_user_id ) : false;
        if ( $existing_user === false || in_array( 'administrator', (array) $existing_user->roles, true ) ) {
            return ['error' => 'حساب انتخاب‌شده برای افزودن به این کلینیک قابل استفاده نیست.', 'generated' => '', 'user_id' => 0];
        }

        try {
            // D6 audit atomicity: membership creation and its mandatory audit
            // event share one transaction boundary. A failed audit INSERT rolls
            // the membership back, so no attach can survive without its audit
            // row and no false success is ever returned.
            App::db()->transactional(
                function () use ( $authorization, $existing_user_id, $role, $updated_by ) {
                    App::membership_service()->create_membership(
                        $authorization['clinic_id'],
                        $existing_user_id,
                        $role,
                        'clinic',
                        $updated_by
                    );

                    App::audit()->log(
                        'STAFF_USER_ATTACHED',
                        [ 'wp_user_id' => $updated_by ],
                        'user',
                        $existing_user_id,
                        null,
                        null,
                        [
                            'clinic_id'     => $authorization['clinic_id'],
                            'role'          => $role,
                            'existing_user' => true,
                        ],
                        [],
                        $authorization['clinic_id']
                    );
                }
            );
        } catch (MembershipException $e) {
            // create_membership() is the single membership write path. In
            // particular, an existing active or suspended row is a deterministic
            // conflict; this action never silently reactivates or duplicates it.
            return ['error' => $e->getMessage(), 'generated' => '', 'user_id' => 0];
        } catch (\Throwable $e) {
            return ['error' => 'عضویت کاربر در Clinic ایجاد نشد.', 'generated' => '', 'user_id' => 0];
        }

        return [ 'error' => '', 'generated' => '', 'user_id' => $existing_user_id ];
    }

    /**
     * فعال/غیرفعال‌سازی — نقش CPMS را موقتاً به subscriber تغییر می‌دهد (بدون حذف تاریخچه).
     *
     * @return array{error:string}
     */
    public static function toggleUser( int $user_id, string $state, int $updated_by, ?int $clinic_id = null ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy method name: renaming it is a cross-file API change outside Slice 3B.
        $authorization = self::authorize_staff_write( $updated_by, $clinic_id, $user_id );
        if ( $authorization['error'] !== '' ) {
            return ['error' => $authorization['error']];
        }

        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        if ( $user === false || ! self::is_manageable( $user_id ) ) {
            return ['error' => 'کاربر یافت نشد یا قابل مدیریت نیست.'];
        }
        $target_membership = $authorization['target_membership'];
        if ( $target_membership === null ) {
            return ['error' => 'عضویت هدف برای این Clinic یافت نشد.'];
        }

        // جلوگیری از قفل‌کردن خود: عاملِ غیرفعال‌سازی نمی‌تواند حساب خودش را غیرفعال کند.
        if ( $state === 'deactivate' && $user_id === $updated_by ) {
            return ['error' => 'نمی‌توانید حساب خودتان را غیرفعال کنید (جلوگیری از قفل‌شدن).'];
        }

        if (!in_array($state, ['deactivate', 'activate'], true)) {
            return ['error' => 'وضعیت کاربر نامعتبر است.'];
        }

        if ($state === 'deactivate') {
            // Durable Clinic membership is the staff criterion, NOT the WP-global
            // role (Custom Role Slice 3B): a member carrying a custom or
            // membership-only role must stay suspendable/manageable. The
            // WP-global role is parked to `subscriber` only when it is one of the
            // CPMS built-in roles that CPMS itself assigned; a WordPress-global
            // role CPMS never assigned is left untouched.
            $wp_role      = (string) ( $user->roles[0] ?? '' );
            $park_wp_role = in_array( $wp_role, self::MANAGEABLE_ROLES, true );
            $prev_role    = $park_wp_role ? $wp_role : (string) ( $target_membership['role_key'] ?? '' );
            if ( $prev_role === '' || ( ! in_array( $prev_role, self::MANAGEABLE_ROLES, true ) && ! CustomRolePolicy::is_definable_role_key( $prev_role ) ) ) {
                return ['error' => 'این کاربر نقش قابل غیرفعال‌سازی ندارد.'];
            }
            if ( (string) ( $target_membership['status'] ?? '' ) !== 'active' ) {
                return ['error' => 'عضویت هدف از قبل غیرفعال است.'];
            }
            // D6 audit atomicity: suspension, role parking/meta and the
            // mandatory audit event share one transaction boundary. The old
            // manual reactivation compensation on a wp_update_user() failure is
            // replaced by the rollback itself, which restores the membership,
            // the meta and the WordPress role together. A failed audit likewise
            // rolls everything back — no un-audited suspension, no false success.
            try {
                App::db()->transactional(
                    function () use ( $target_membership, $user_id, $prev_role, $park_wp_role, $updated_by, $authorization ) {
                        App::membership_service()->suspend_membership( (int) $target_membership['id'] );
                        update_user_meta( $user_id, self::META_PREV_ROLE, $prev_role );
                        if ( $park_wp_role ) {
                            $updated = wp_update_user( [ 'ID' => $user_id, 'role' => 'subscriber' ] );
                            if ( is_wp_error( $updated ) ) {
                                // Throwing inside the boundary rolls every write above back.
                                throw new \RuntimeException( $updated->get_error_message() );
                            }
                        }
                        App::audit()->log(
                            'STAFF_USER_DEACTIVATED',
                            [ 'wp_user_id' => $updated_by ],
                            'user',
                            $user_id,
                            null,
                            [
                                'clinic_id'         => $authorization['clinic_id'],
                                'role'              => $prev_role,
                                'membership_status' => 'active',
                            ],
                            [
                                'clinic_id'         => $authorization['clinic_id'],
                                'role'              => $park_wp_role ? 'subscriber' : $prev_role,
                                'membership_status' => 'suspended',
                                'wp_global_role'    => $park_wp_role ? 'subscriber' : 'unchanged',
                            ],
                            [],
                            $authorization['clinic_id']
                        );
                    }
                );
            } catch ( \Throwable $e ) {
                // Rollback restored membership/meta/role; evict the WP caches
                // that wp_update_user() and update_user_meta() rewrote so no
                // stale subscriber privilege can survive in a persistent cache.
                UserCacheRollback::invalidate( [ [ 'id' => $user_id ] ] );
                return ['error' => $e->getMessage()];
            }
        } else {
            $prev_role       = (string) get_user_meta( $user_id, self::META_PREV_ROLE, true );
            $restore_wp_role = in_array( $prev_role, self::MANAGEABLE_ROLES, true );
            if ( $prev_role === '' || ( ! $restore_wp_role && ! CustomRolePolicy::is_definable_role_key( $prev_role ) ) ) {
                return ['error' => 'نقش قبلی برای فعال‌سازی یافت نشد.'];
            }
            if ( (string) ( $target_membership['status'] ?? '' ) !== 'suspended' ) {
                return ['error' => 'عضویت هدف فعال است.'];
            }
            // Suspension preserves role_key on the membership row itself, so
            // reactivation restores a custom assignment without any extra write.
            //
            // D6 audit atomicity: reactivation, meta cleanup, WordPress role
            // restoration and the mandatory audit event share one boundary. The
            // lifecycle MembershipException propagates after rollback (nothing
            // was written); the old manual re-suspension compensation for a
            // wp_update_user() failure is replaced by the rollback itself.
            try {
                App::db()->transactional(
                    function () use ( $target_membership, $user_id, $prev_role, $restore_wp_role, $updated_by, $authorization ) {
                        App::membership_service()->reactivate_membership( (int) $target_membership['id'] );
                        delete_user_meta( $user_id, self::META_PREV_ROLE );
                        if ( $restore_wp_role ) {
                            $updated = wp_update_user( [ 'ID' => $user_id, 'role' => $prev_role ] );
                            if ( is_wp_error( $updated ) ) {
                                // Throwing inside the boundary rolls every write above back.
                                throw new \RuntimeException( $updated->get_error_message() );
                            }
                        }
                        App::audit()->log(
                            'STAFF_USER_ACTIVATED',
                            [ 'wp_user_id' => $updated_by ],
                            'user',
                            $user_id,
                            null,
                            [
                                'clinic_id'         => $authorization['clinic_id'],
                                'role'              => $prev_role,
                                'membership_status' => 'suspended',
                                'wp_global_role'    => $restore_wp_role ? 'subscriber' : 'unchanged',
                            ],
                            [
                                'clinic_id'         => $authorization['clinic_id'],
                                'role'              => $prev_role,
                                'membership_status' => 'active',
                            ],
                            [],
                            $authorization['clinic_id']
                        );
                    }
                );
            } catch ( \ClinicCore\Domain\Membership\MembershipException $e ) {
                // Central lifecycle policy: reactivation is NEW participation, so a
                // suspended operational Clinic (or unverified lifecycle evidence)
                // refuses it. The boundary rolled back; nothing was written.
                UserCacheRollback::invalidate( [ [ 'id' => $user_id ] ] );
                return ['error' => $e->getMessage()];
            } catch ( \Throwable $e ) {
                // Rollback restored the suspended state; evict the rewritten
                // users/user_meta cache keys so no stale restored role survives.
                UserCacheRollback::invalidate( [ [ 'id' => $user_id ] ] );
                return ['error' => $e->getMessage()];
            }
        }

        return ['error' => ''];
    }

    /**
     * انتساب نقش سفارشی Clinic (admin-post handler).
     *
     * Nonce/CSRF و transport در این مرز است؛ مجوز Clinic-scoped و مالکیت اشیای
     * durable داخل CustomRoleAssignmentService احراز می‌شود. این handler هیچ
     * نقش WordPress را تغییر نمی‌دهد.
     */
    public static function assign_custom_role(): void {
        self::handle_custom_role_assignment( false );
    }

    public static function reassign_suspended_custom_role(): void {
        self::handle_custom_role_assignment( true );
    }

    private static function handle_custom_role_assignment( bool $suspended_only ): void {
        if ( ! is_user_logged_in() ) {
            wp_die( 'دسترسی ندارید', 403 );
        }
        check_admin_referer( $suspended_only ? self::NONCE_REASSIGN_SUSPENDED : self::NONCE_ASSIGN );

        $raw_target_user_id = isset( $_POST['target_user_id'] ) && is_scalar( $_POST['target_user_id'] )
            ? absint( wp_unslash( (string) $_POST['target_user_id'] ) )
            : 0;
        $raw_clinic_id      = isset( $_POST['clinic_id'] ) && is_scalar( $_POST['clinic_id'] )
            ? absint( wp_unslash( (string) $_POST['clinic_id'] ) )
            : null;
        $raw_role_key       = isset( $_POST['custom_role_key'] ) ? wp_unslash( $_POST['custom_role_key'] ) : '';
        $role_key           = is_string( $raw_role_key ) ? sanitize_key( $raw_role_key ) : '';

        $result = self::write_custom_role_assignment( $raw_target_user_id, $role_key, (int) get_current_user_id(), $raw_clinic_id, $suspended_only );
        set_transient( self::NOTICE_KEY, $result['error'] !== '' ? 'خطا: ' . $result['error'] : $result['notice'], 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&clinic_id=' . max( 0, (int) ( $raw_clinic_id ?? 0 ) ) ) );
        exit;
    }

    /**
     * مرز آزمون‌پذیرِ انتساب نقش سفارشی (بدون nonce/redirect).
     *
     * @return array{error:string, notice:string, clinic_id:int}
     */
    public static function assign_custom_role_to( int $target_user_id, string $role_key, int $updated_by, ?int $clinic_id = null ): array {
        return self::write_custom_role_assignment( $target_user_id, $role_key, $updated_by, $clinic_id, false );
    }

    /** @return array{error:string,notice:string,clinic_id:int} */
    public static function reassign_suspended_custom_role_to( int $target_user_id, string $role_key, int $updated_by, ?int $clinic_id = null ): array {
        return self::write_custom_role_assignment( $target_user_id, $role_key, $updated_by, $clinic_id, true );
    }

    /** @return array{error:string,notice:string,clinic_id:int} */
    private static function write_custom_role_assignment( int $target_user_id, string $role_key, int $updated_by, ?int $clinic_id, bool $suspended_only ): array {
        if ( $updated_by <= 0 ) {
            return ['error' => 'درخواست انتساب نامعتبر است.', 'notice' => '', 'clinic_id' => 0];
        }

        try {
            $service  = App::custom_role_assignment_service();
            $assigned = $suspended_only
                ? $service->reassign_suspended( $updated_by, $clinic_id, $target_user_id, $role_key )
                : $service->assign( $updated_by, $clinic_id, $target_user_id, $role_key );
        } catch ( ScopeRequiredException | AuthorizationException $e ) {
            return ['error' => 'انتساب نقش سفارشی برای این Clinic مجاز نیست.', 'notice' => '', 'clinic_id' => 0];
        } catch ( CustomRoleAssignmentException $e ) {
            return ['error' => self::assignment_error_message( $e ), 'notice' => '', 'clinic_id' => 0];
        } catch ( \Throwable $e ) {
            return ['error' => 'انتساب نقش سفارشی انجام نشد.', 'notice' => '', 'clinic_id' => 0];
        }

        $label = ( $assigned['display_name'] !== null && $assigned['display_name'] !== '' )
            ? (string) $assigned['display_name']
            : (string) $assigned['role_key'];

        return [
            'error'     => '',
            'notice'    => $suspended_only && $assigned['changed']
                ? __( 'نقش عضویت معلق تغییر کرد؛ تعلیق و اطلاعات بازگردانی حفظ شدند و هیچ فعال‌سازی انجام نشد.', 'cpms' )
                : ( $assigned['changed']
                ? 'نقش سفارشی «' . $label . '» به عضویت این کارمند در همین Clinic منتسب شد؛ نقش WordPress تغییر نکرد.'
                : 'این کارمند از قبل همین نقش سفارشی را داشت؛ هیچ تغییری ثبت نشد.' ),
            'clinic_id' => (int) $assigned['clinic_id'],
        ];
    }

    /** پیام فارسیِ محدود برای کدهای دلیلِ انتساب (هرگز مقدار خامِ درخواست). */
    private static function assignment_error_message( CustomRoleAssignmentException $e ): string {
        return match ( $e->reason_code() ) {
            CustomRoleAssignmentException::CODE_INVALID_ROLE_KEY => 'فقط نقش‌های سفارشیِ همین Clinic قابل انتساب‌اند (نقش داخلی/ناشناخته از این مسیر رد می‌شود).',
            CustomRoleAssignmentException::CODE_TARGET_MEMBERSHIP_REQUIRED => 'عضویت کارمند در همین Clinic با وضعیت لازم برای این عملیات مطابقت ندارد.',
            CustomRoleAssignmentException::CODE_DEFINITION_NOT_ACTIVE => 'این نقش سفارشی در این Clinic فعال نیست و قابل انتساب نیست.',
            CustomRoleAssignmentException::CODE_CAPABILITY_NOT_DELEGABLE => 'این نقش سفارشی مجوزی بیرون از اختیار مؤثر شما دارد و قابل انتساب نیست.',
            CustomRoleAssignmentException::CODE_MEMBERSHIP_UPDATE_FAILED => 'ثبت عضویت انجام نشد؛ هیچ انتساب نیمه‌کاری باقی نمانده است.',
            default => 'نقش سفارشی موردنظر در این Clinic یافت نشد.',
        };
    }

    /**
     * گزینه‌های فرمِ انتساب: نقش‌های سفارشیِ فعالِ قابل‌واگذاری (سقف اختیار مؤثر
     * عامل) و کارکنانِ دارای عضویت فعال در همین Clinic.
     *
     * @param array<int, array<string,mixed>> $rows
     *
     * @return array{definitions:list<array{role_key:string, display_name:string|null, capabilities:list<string>, empty:bool}>, members:list<array{id:int, label:string}>, suspended_members:list<array{id:int, label:string}>}
     */
    private static function assignment_options( int $clinic_id, array $rows ): array {
        $empty = ['definitions' => [], 'members' => [], 'suspended_members' => []];
        if ( $clinic_id <= 0 || $rows === [] ) {
            return $empty;
        }

        try {
            $assignable = App::custom_role_assignment_service()->assignable_definitions( (int) get_current_user_id(), $clinic_id );
        } catch ( \Throwable $e ) {
            // Fail closed: بدون مجوز نمایشی، هیچ گزینه‌ای ساخته نمی‌شود.
            return $empty;
        }

        $members           = [];
        $suspended_members = [];
        foreach ( $rows as $row ) {
            if ( ( $row['active'] ?? false ) !== true && ( $row['membership_status'] ?? '' ) !== 'suspended' ) {
                continue;
            }
            $member = [
                'id'    => (int) $row['id'],
                'label' => (string) $row['display_name'] . ' (' . (string) $row['login'] . ') — ' . (string) $row['role_label'],
            ];
            if ( ( $row['active'] ?? false ) === true ) {
                $members[] = $member;
            } else {
                $suspended_members[] = $member;
            }
        }

        return [
            'definitions'       => $assignable['definitions'],
            'members'           => $members,
            'suspended_members' => $suspended_members,
        ];
    }

    // ================= helpers =================

    /**
     * Resolve and authorize the Clinic write boundary for staff management.
     *
     * `$requested_clinic_id` is only a caller-selected context. The establisher
     * validates it against the actor's durable active membership; AuthorizationService
     * then evaluates the scoped permission. For an existing target, its Clinic owner
     * is independently retrieved from the durable membership row before the object
     * authorization check.
     *
     * @return array{error:string, clinic_id:int, target_membership:array<string,mixed>|null}
     */
    private static function authorize_staff_write( int $actor_user_id, ?int $requested_clinic_id, ?int $target_user_id = null ): array {
        if ( $actor_user_id <= 0 || $requested_clinic_id === null || $requested_clinic_id <= 0 ) {
            return [
                'error'             => 'Clinic را برای مدیریت پرسنل و نقش‌ها صریحاً انتخاب کنید.',
                'clinic_id'         => 0,
                'target_membership' => null,
            ];
        }

        try {
            $scope = (new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db())))
                ->establish( $actor_user_id, $requested_clinic_id );
            $authorization = App::authorization_service();
            $authorization->authorize( $actor_user_id, $scope->clinicId, RolesAndCapabilities::CONFIG ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.

            $target_membership = null;
            if ( $target_user_id !== null ) {
                $target_membership = ( new MembershipRepository( App::db() ) )->find( $scope->clinicId, $target_user_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.
                if ( $target_membership === null ) {
                    throw new AuthorizationException('AUTH_NO_MEMBERSHIP', 'AUTH_NO_MEMBERSHIP');
                }

                // This owner value came from the durable membership row above, not
                // from the request. Equality is checked by the service, but the
                // source-of-truth retrieval remains at this boundary.
                $target_clinic_id = (int) ( $target_membership['clinic_id'] ?? 0 );
                $authorization->authorizeForObject(
                    $actor_user_id,
                    $scope->clinicId,
                    RolesAndCapabilities::CONFIG,
                    $target_clinic_id
                );
            }

            return [
                'error' => '',
                'clinic_id' => $scope->clinicId,
                'target_membership' => $target_membership,
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
    private static function managed_clinic_choices( int $actor_user_id ): array {
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
     * Clinic selector for the rendered form. It is display context only; every
     * write validates the value again through authorize_staff_write().
     */
    private static function formClinicId(): int
    {
        // No implicit/unique-membership context for a management page: the user
        // must choose the Clinic in the selector before any staff/role forms appear.
        if ( ! isset( $_GET['clinic_id'] ) || ! is_scalar( $_GET['clinic_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only Clinic selector; write authorization is rechecked
            return 0;
        }
        $requested = absint( wp_unslash( (string) $_GET['clinic_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display selector only
        if ( $requested <= 0 ) {
            return 0;
        }
        $authorization = self::authorize_staff_write( (int) get_current_user_id(), $requested );
        return $authorization['clinic_id'];
    }

    /**
     * آیا این کاربر قابل‌مدیریت است؟
     *
     * معیار «سه‌گانه» است و نقش سراسری WordPress تنها معیار نیست: نقش داخلی CPMS،
     * usermeta غیرفعال‌سازی، یا وجود عضویتِ Clinic (شامل عضویت با نقش سفارشی که
     * نقش WP آن داخلی نیست — Custom Role Slice 3B). این تابع فقط «قابل‌مدیریت بودن»
     * را می‌گوید؛ مجوز هر write همچنان Clinic-scoped و در authorize_staff_write است.
     */
    private static function is_manageable( int $user_id ): bool {
        $user = get_userdata( $user_id );
        if ($user === false) {
            return false;
        }
        // WordPress administrator حسابِ CPMS نیست: نه قابل ویرایش/غیرفعال‌سازی است و
        // نه عضویت به‌تنهایی آن را قابل‌مدیریت می‌کند (حفظِ مرزِ موجود — P-3).
        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return false;
        }
        foreach ($user->roles as $role) {
            if (in_array($role, self::MANAGEABLE_ROLES, true)) {
                return true;
            }
        }
        // کاربر غیرفعال (subscriber + usermeta) هم قابل مدیریت است تا بتوان فعال/re-activate کرد.
        if ( get_user_meta( $user_id, self::META_PREV_ROLE, true ) !== '' ) {
            return true;
        }

        // عضویت Clinic معیار پایدار است، نه نقش سراسری WordPress: کارمندی که فقط
        // از طریق عضویت (نقش داخلی یا سفارشی) به Clinic وصل است باید قابل مدیریت بماند.
        return self::has_any_membership( $user_id );
    }

    /** آیا این کاربر هر عضویتی در هر Clinic دارد؟ (معیار قابلیت‌مدیریت، نه مجوز) */
    private static function has_any_membership( int $user_id ): bool {
        if ( $user_id <= 0 ) {
            return false;
        }

        return App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinic_memberships' ) . ' WHERE wp_user_id = %d LIMIT 1',
            [$user_id]
        ) !== null;
    }

    /**
     * فهرست پرسنل Clinic مورد اعتماد.
     *
     * معیارِ فهرست، «عضویت پایدار در همین Clinic» است — نه نقش سراسری WordPress
     * (Custom Role Slice 3B). بنابراین کارمندی که نقش عضویت آن یک نقش سفارشیِ
     * Clinic-local است (و نقش WP آن داخلی نیست) همچنان دیده می‌شود و قابل مدیریت
     * می‌ماند. دامنهٔ پرس‌وجو همیشه شناسه‌های عضویت همین Clinic است، پس هیچ کاربرِ
     * کلینیک دیگر (یا بدون عضویت) نمی‌تواند ظاهر شود.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function list_users( int $clinic_id ): array {
        // No trusted/authorized Clinic means no Clinic-sensitive read. In
        // particular, clinic_id=0 must never fall through to installation-wide
        // get_users(); a global WP capability is not a Clinic context.
        if ( $clinic_id <= 0 ) {
            return [];
        }

        $memberships = self::membership_rows_by_user( $clinic_id );
        if ( $memberships === [] ) {
            return [];
        }
        $user_ids = array_keys( $memberships );

        $users = get_users(
            [
                'include' => $user_ids,
                'fields'  => 'all',
                'number'  => 500,
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ]
        );

        $rows = [];
        $seen = [];
        $labels = self::roleLabels();
        $custom_labels     = self::custom_role_labels( $clinic_id );
        $clinician_by_user = self::clinician_link_map( $clinic_id );
        foreach ($users as $u) {
            $id = (int) $u->ID;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $membership        = $memberships[ $id ] ?? null;
            $membership_role   = $membership === null ? '' : (string) ( $membership['role_key'] ?? '' );
            $membership_status = $membership === null ? 'suspended' : (string) ( $membership['status'] ?? 'suspended' );
            $wp_role           = (string) ( $u->roles[0] ?? '' );
            // Clinic-local membership role_key is authoritative for the Clinic;
            // the WP-global role is only a fallback for rows without a membership
            // role (which the membership-based listing no longer produces).
            $role   = $membership_role !== '' ? $membership_role : $wp_role;
            $custom = array_key_exists( $role, $custom_labels );
            $label  = $labels[ $role ] ?? '';

            if ( $label === '' ) {
                $custom_name = $custom ? $custom_labels[ $role ] : '';
                $label       = ( $custom_name !== null && $custom_name !== '' ? $custom_name : $role ) . ( $custom ? ' (نقش سفارشی)' : '' );
            }

            $rows[] = [
                'id'                => $id,
                'login'             => (string) $u->user_login,
                'email'             => (string) $u->user_email,
                'display_name'      => (string) $u->display_name,
                'role'              => $role,
                'role_label'        => $label,
                'active'            => $membership_status === 'active',
                'membership_role'   => $membership_role,
                'membership_status' => $membership_status,
                'custom_role'       => $custom,
                'clinician'         => $clinician_by_user[ $id ] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * عضویت‌های همین Clinic، کلید‌شده با wp_user_id (منبعِ نمایش و انتساب).
     *
     * @return array<int, array<string,mixed>>
     */
    private static function membership_rows_by_user( int $clinic_id ): array {
        if ( $clinic_id <= 0 ) {
            return [];
        }

        $rows = App::db()->fetchAll(
            'SELECT id, wp_user_id, role_key, status FROM ' . App::db()->table( 'cpms_clinic_memberships' ) .
            ' WHERE clinic_id = %d ORDER BY wp_user_id LIMIT 500',
            [$clinic_id]
        );

        $map = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $user_id = (int) ( $row['wp_user_id'] ?? 0 );
            if ( $user_id > 0 ) {
                $map[ $user_id ] = $row;
            }
        }

        return $map;
    }

    /**
     * نقشه role_key سفارشی → نام نمایشی، فقط برای تعریف‌های همین Clinic.
     * یک مقدار نامعتبر/ناموجود در UI به کلید فنی برمی‌گردد (هرگز برچسب گمراه‌کننده).
     *
     * @return array<string, string|null>
     */
    private static function custom_role_labels( int $clinic_id ): array {
        if ( $clinic_id <= 0 ) {
            return [];
        }

        $labels = [];
        foreach ( ( new CustomRoleRepository( App::db() ) )->list_definitions( $clinic_id ) as $definition ) {
            $key = (string) ( $definition['role_key'] ?? '' );
            if ( $key !== '' ) {
                $labels[ $key ] = $definition['display_name'];
            }
        }

        return $labels;
    }

    /**
     * نقشه wp_user_id → نام پزشک، فقط برای Clinic مورد اعتمادِ همین فهرست.
     *
     * @return array<int, string>
     */
    private static function clinician_link_map( int $clinic_id ): array {
        if ( $clinic_id <= 0 ) {
            return [];
        }

        $rows = App::db()->fetchAll(
            'SELECT wp_user_id, full_name FROM ' . App::db()->table('cpms_clinicians') .
            ' WHERE clinic_id = %d AND wp_user_id IS NOT NULL AND is_active = 1',
            [$clinic_id]
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
    private static function edit_target( int $clinic_id ): ?array {
        $id = isset($_GET['edit']) ? absint($_GET['edit']) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( $id <= 0 || $clinic_id <= 0 ) {
            return null;
        }
        foreach ( self::list_users( $clinic_id ) as $r ) {
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

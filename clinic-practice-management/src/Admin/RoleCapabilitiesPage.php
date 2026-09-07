<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * صفحه «CPMS (دسترسی‌ها)» — مدیریت Capabilities نقش‌های CPMS (ADR-0030 / Part 1).
 *
 *  - فقط Administrator فنی با `cpms_config` (P-3) — عملیات حساس با Audit کامل.
 *  - ماتریس Role × Capability فقط برای نقش‌های تمپلیت (منشی/پزشک)؛ نقش بیمار
 *    همیشه بدون Cap است (P-5 — Ownership) و نمایش صرفاً اطلاعی دارد.
 *  - ذخیره از مسیر Override عمدی (`RolesAndCapabilities::OPTION_OVERRIDE`) است؛
 *    Self-healing به Override احترام می‌گذارد و دیگر تعمدِ ادمین را پاک نمی‌کند.
 *  - Capهای حساس (P-11) با نشانگر جدا نمایش داده می‌شوند.
 *  - هر تغییر → Audit `ROLE_PERMISSION_CHANGED` با before/after.
 */
final class RoleCapabilitiesPage
{
    private const NOTICE_KEY = 'cpms_roles_notice';
    private const NONCE_ACTION = 'cpms_save_role_caps';

    /** نشانگر Cap حساس (P-11) — در UI با ⚠️ مشخص می‌شود. */
    private const SENSITIVE = [
        RolesAndCapabilities::PRIVATE_NOTE_READ, RolesAndCapabilities::PRIVATE_NOTE_CREATE, RolesAndCapabilities::PRIVATE_NOTE_UPDATE,
        RolesAndCapabilities::EXPORT, RolesAndCapabilities::AUDIT_READ,
        RolesAndCapabilities::PAYMENT_VOID, RolesAndCapabilities::PAYMENT_REFUND,
        RolesAndCapabilities::INVOICE_VOID, RolesAndCapabilities::INVOICE_ADJUST,
        RolesAndCapabilities::PATIENT_ARCHIVE, RolesAndCapabilities::PATIENT_MERGE,
        RolesAndCapabilities::CONSULT_REOPEN,
    ];

    /** برچسب فارسی هر Capability (منبع: permission-matrix §2). */
    private const LABELS = [
        'cpms_patient_read' => 'مشاهده پرونده بیمار',
        'cpms_patient_create' => 'ثبت بیمار جدید',
        'cpms_patient_update' => 'ویرایش اطلاعات بیمار',
        'cpms_patient_archive' => 'آرشیو بیمار (حساس)',
        'cpms_patient_merge' => 'ادغام پرونده تکراری (حساس)',
        'cpms_appt_read' => 'مشاهده نوبت‌ها',
        'cpms_appt_create' => 'ثبت نوبت',
        'cpms_appt_confirm' => 'تأیید نوبت',
        'cpms_appt_cancel' => 'لغو نوبت',
        'cpms_appt_reschedule' => 'جابه‌جایی نوبت',
        'cpms_appt_no_show' => 'ثبت عدم حضور',
        'cpms_visit_read' => 'مشاهده ویزیت‌ها',
        'cpms_queue_read' => 'مشاهده صف',
        'cpms_queue_checkin' => 'چک‌این / پذیرش',
        'cpms_queue_advance' => 'پیشبرد صف (به‌صف/لغو/صورتحساب)',
        'cpms_queue_call' => 'فراخوان بیمار',
        'cpms_queue_checkout' => 'خروج بیمار',
        'cpms_consult_start' => 'شروع مشاوره',
        'cpms_consult_complete' => 'تکمیل ویزیت',
        'cpms_consult_reopen' => 'بازگشایی ویزیت تکمیل‌شده (حساس)',
        'cpms_medical_read' => 'مشاهده محتوای بالینی',
        'cpms_note_create' => 'ثبت یادداشت بالینی',
        'cpms_note_update' => 'ویرایش یادداشت بالینی',
        'cpms_rec_create' => 'ثبت توصیه پزشکی',
        'cpms_private_note_read' => 'مشاهده یادداشت خصوصی پزشک (فوق حساس)',
        'cpms_private_note_create' => 'ثبت یادداشت خصوصی',
        'cpms_private_note_update' => 'ویرایش یادداشت خصوصی',
        'cpms_rx_read' => 'مشاهده نسخه‌ها',
        'cpms_rx_create' => 'ثبت نسخه',
        'cpms_rx_void' => 'ابطال نسخه',
        'cpms_file_upload' => 'بارگذاری فایل پزشکی',
        'cpms_file_read' => 'دریافت/مشاهده فایل پزشکی',
        'cpms_invoice_read' => 'مشاهده فاکتور',
        'cpms_invoice_create' => 'صدور فاکتور',
        'cpms_invoice_adjust' => 'اصلاح مبلغ فاکتور (حساس)',
        'cpms_invoice_void' => 'ابطال فاکتور (حساس)',
        'cpms_payment_create' => 'ثبت پرداخت',
        'cpms_payment_void' => 'ابطال پرداخت (حساس)',
        'cpms_payment_refund' => 'بازپرداخت (حساس)',
        'cpms_finance_read' => 'مشاهده خلاصه مالی',
        'cpms_report_read' => 'مشاهده گزارش‌ها',
        'cpms_export' => 'خروجی CSV (حساس)',
        'cpms_audit_read' => 'مشاهده Audit (حساس)',
        'cpms_search' => 'جستجوی بیمار',
        'cpms_config' => 'پیکربندی سیستم (فنی)',
        'cpms_sms_config' => 'تنظیمات پیامک (فنی)',
    ];

    /** گروه‌بندی نمایشی. */
    private const GROUPS = [
        'بیمار / پرونده' => [
            RolesAndCapabilities::PATIENT_READ, RolesAndCapabilities::PATIENT_CREATE, RolesAndCapabilities::PATIENT_UPDATE,
            RolesAndCapabilities::PATIENT_ARCHIVE, RolesAndCapabilities::PATIENT_MERGE,
        ],
        'نوبت' => [
            RolesAndCapabilities::APPT_READ, RolesAndCapabilities::APPT_CREATE, RolesAndCapabilities::APPT_CONFIRM,
            RolesAndCapabilities::APPT_CANCEL, RolesAndCapabilities::APPT_RESCHEDULE, RolesAndCapabilities::APPT_NO_SHOW,
        ],
        'صف / ویزیت' => [
            RolesAndCapabilities::VISIT_READ, RolesAndCapabilities::QUEUE_READ, RolesAndCapabilities::QUEUE_CHECKIN,
            RolesAndCapabilities::QUEUE_ADVANCE, RolesAndCapabilities::QUEUE_CALL, RolesAndCapabilities::QUEUE_CHECKOUT,
            RolesAndCapabilities::CONSULT_START, RolesAndCapabilities::CONSULT_COMPLETE, RolesAndCapabilities::CONSULT_REOPEN,
        ],
        'بالینی' => [
            RolesAndCapabilities::MEDICAL_READ, RolesAndCapabilities::NOTE_CREATE, RolesAndCapabilities::NOTE_UPDATE,
            RolesAndCapabilities::REC_CREATE, RolesAndCapabilities::RX_READ, RolesAndCapabilities::RX_CREATE, RolesAndCapabilities::RX_VOID,
        ],
        'یادداشت خصوصی پزشک' => [
            RolesAndCapabilities::PRIVATE_NOTE_READ, RolesAndCapabilities::PRIVATE_NOTE_CREATE, RolesAndCapabilities::PRIVATE_NOTE_UPDATE,
        ],
        'فایل پزشکی' => [
            RolesAndCapabilities::FILE_UPLOAD, RolesAndCapabilities::FILE_READ,
        ],
        'مالی' => [
            RolesAndCapabilities::INVOICE_READ, RolesAndCapabilities::INVOICE_CREATE, RolesAndCapabilities::INVOICE_ADJUST,
            RolesAndCapabilities::INVOICE_VOID, RolesAndCapabilities::PAYMENT_CREATE, RolesAndCapabilities::PAYMENT_VOID,
            RolesAndCapabilities::PAYMENT_REFUND, RolesAndCapabilities::FINANCE_READ,
        ],
        'گزارش / جستجو / سایر' => [
            RolesAndCapabilities::REPORT_READ, RolesAndCapabilities::EXPORT, RolesAndCapabilities::SEARCH,
            RolesAndCapabilities::AUDIT_READ, RolesAndCapabilities::CONFIG, RolesAndCapabilities::SMS_CONFIG,
        ],
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_cpms_save_role_caps', [self::class, 'save']);
        add_action('admin_post_cpms_reset_role_caps', [self::class, 'reset']);
    }

    public static function menu(): void
    {
        add_management_page(
            'CPMS (دسترسی‌ها)',
            'CPMS (دسترسی‌ها)',
            RolesAndCapabilities::CONFIG,
            'cpms-roles',
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
        $overrides = RolesAndCapabilities::overrides();
        ?>
<div class="wrap" dir="rtl">
    <h1>CPMS — دسترسی نقش‌ها</h1>

    <?php if (is_string($notice) && $notice !== '') : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <div class="notice notice-info inline"><p>
        تغییرات این صفحه <strong>عمدی و ماندگار</strong> ثبت می‌شود (Override — ADR-0030) و توسط
        Self-healing افزونه حذف نمی‌شود. تیک‌برداشتن یک Capability، آن را <strong>فوراً</strong>
        از نقش می‌گیرد. هر تغییر در Audit با ذکر کاربر، زمان و تفاضل ثبت می‌شود.
        ⚠️ = Capability حساس (P-11).
    </p></div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="cpms_save_role_caps">
        <?php wp_nonce_field(self::NONCE_ACTION); ?>

        <!-- نقش بیمار: فقط اطلاعی -->
        <h2 class="title">بیمار (cpms_patient)</h2>
        <p class="description">
            بدون هیچ Capability — دسترسی فقط با مالکیت (فقط داده‌های خودش از طریق موبایل/OTP).
            این نقش عمداً قابل ویرایش نیست (P-5).
        </p>

        <?php foreach ([RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::ROLE_DOCTOR] as $role) : ?>
            <?php
            $label = $role === RolesAndCapabilities::ROLE_SECRETARY ? 'منشی مطب (cpms_secretary)' : 'پزشک (cpms_doctor)';
            $effective = RolesAndCapabilities::capsMap($role); // array<string,bool>
            $isOverridden = isset($overrides[$role]);
            ?>
            <h2 class="title"><?php echo esc_html($label); ?>
                <?php if ($isOverridden) : ?>
                    <span class="description">— تغییر یافته نسبت به پیش‌فرض
                        (<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpms_reset_role_caps&role=' . $role), 'cpms_reset_role_caps_' . $role)); ?>"
                            onclick="return confirm('بازگشت این نقش به Template پیش‌فرض افزونه؟');">بازگشت به پیش‌فرض</a>)
                    </span>
                <?php endif; ?>
            </h2>
            <table class="widefat striped" style="max-width:900px">
                <?php foreach (self::GROUPS as $groupTitle => $capsInGroup) : ?>
                    <tr>
                        <td style="width:180px"><strong><?php echo esc_html($groupTitle); ?></strong></td>
                        <td>
                            <?php foreach ($capsInGroup as $cap) : ?>
                                <?php $sensitive = in_array($cap, self::SENSITIVE, true); ?>
                                <label style="display:inline-block;min-width:240px;margin:2px 0">
                                    <input type="checkbox" name="role_caps[<?php echo esc_attr($role); ?>][]"
                                            value="<?php echo esc_attr($cap); ?>"
                                            <?php checked(!empty($effective[$cap])); ?>>
                                    <?php echo esc_html(self::labelFor($cap)); ?>
                                    <?php echo $sensitive ? ' ⚠️' : ''; ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>

        <p style="margin-top:14px">
            <button type="submit" class="button button-primary button-hero">ذخیره دسترسی‌ها</button>
        </p>
    </form>
</div>
        <?php
    }

    /**
     * ذخیره ماتریس — Authorization کامل: cpms_config + Nonce + Audit.
     */
    public static function save(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG) || !is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer(self::NONCE_ACTION);

        $user = wp_get_current_user();
        $input = isset($_POST['role_caps']) && is_array($_POST['role_caps']) ? wp_unslash($_POST['role_caps']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize در sanitizeCapList (whitelist سخت)

        $report = [];
        foreach ([RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::ROLE_DOCTOR] as $role) {
            $requested = isset($input[$role]) && is_array($input[$role]) ? $input[$role] : [];
            $before = array_keys(array_filter(RolesAndCapabilities::capsMap($role)));
            if (!RolesAndCapabilities::setRoleCaps($role, $requested)) {
                continue;
            }
            $after = array_keys(array_filter(RolesAndCapabilities::capsMap($role)));
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added !== [] || $removed !== []) {
                App::audit()->log(
                    'ROLE_PERMISSION_CHANGED',
                    ['wp_user_id' => (int) $user->ID, 'role' => $user->roles[0] ?? 'administrator'],
                    'role',
                    null,
                    null,
                    ['role' => $role, 'caps' => $before],
                    ['role' => $role, 'caps' => $after],
                    ['added' => $added, 'removed' => $removed]
                );
                $report[] = $role . ': +' . count($added) . '/-' . count($removed);
            }
        }

        set_transient(
            self::NOTICE_KEY,
            $report === [] ? 'بدون تغییر — دسترسی‌ها از قبل همین بود.' : 'ذخیره شد (' . implode('، ', $report) . ') — در Audit ثبت شد.',
            60
        );

        wp_safe_redirect(admin_url('tools.php?page=cpms-roles'));
        exit;
    }

    /**
     * بازگشت نقش به Template پیش‌فرض کلاس (حذف Override) — لینک از همان صفحه.
     */
    public static function reset(): void
    {
        if (!current_user_can(RolesAndCapabilities::CONFIG) || !is_user_logged_in()) {
            wp_die('دسترسی ندارید', 403);
        }
        check_admin_referer('cpms_reset_role_caps_' . (string) ($_GET['role'] ?? ''));

        $role = (string) ($_GET['role'] ?? '');
        if (in_array($role, [RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::ROLE_DOCTOR], true)) {
            $before = array_keys(array_filter(RolesAndCapabilities::capsMap($role)));
            RolesAndCapabilities::setRoleCaps($role, match ($role) {
                RolesAndCapabilities::ROLE_DOCTOR => RolesAndCapabilities::DOCTOR_CAPS,
                default => RolesAndCapabilities::SECRETARY_CAPS,
            });
            $user = wp_get_current_user();
            App::audit()->log(
                'ROLE_PERMISSION_RESET',
                ['wp_user_id' => (int) $user->ID, 'role' => $user->roles[0] ?? 'administrator'],
                'role',
                null,
                null,
                ['role' => $role, 'caps' => $before],
                ['role' => $role, 'caps' => array_keys(array_filter(RolesAndCapabilities::capsMap($role)))],
                []
            );
        }

        set_transient(self::NOTICE_KEY, 'نقش به Template پیش‌فرض بازگشت — در Audit ثبت شد.', 60);
        wp_safe_redirect(admin_url('tools.php?page=cpms-roles'));
        exit;
    }

    private static function labelFor(string $cap): string
    {
        return self::LABELS[$cap] ?? $cap;
    }
}

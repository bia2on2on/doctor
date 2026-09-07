<?php

declare(strict_types=1);

namespace ClinicCore\Auth;

/**
 * نقش‌ها و قابلیت‌ها — فهرست نهایی (تأییدشده 2026-09-05).
 * مستند: docs/permissions/permission-matrix.md v1.1
 *
 * اصول:
 *  - Naming: cpms_{resource}_{action} — Capability کلی (مثل manage_clinic) ممنوع (P-4).
 *  - Administrator وردپرس: فقط cpms_config (فنی). Medical/Audit/Export = Explicit (P-3).
 *  - Patient: بدون Capability — فقط Ownership (P-5).
 *  - Authorization نهایی در Backend: Capability + Data-Access + Field/Row Filter (P-1, P-8).
 */
final class RolesAndCapabilities
{
    public const ROLE_PATIENT = 'cpms_patient';
    public const ROLE_SECRETARY = 'cpms_secretary';
    public const ROLE_DOCTOR = 'cpms_doctor';

    // ===== Patient / Profile =====
    public const PATIENT_READ = 'cpms_patient_read';
    public const PATIENT_CREATE = 'cpms_patient_create';
    public const PATIENT_UPDATE = 'cpms_patient_update';
    public const PATIENT_ARCHIVE = 'cpms_patient_archive';
    public const PATIENT_MERGE = 'cpms_patient_merge';

    // ===== Appointment =====
    public const APPT_READ = 'cpms_appt_read';
    public const APPT_CREATE = 'cpms_appt_create';
    public const APPT_CONFIRM = 'cpms_appt_confirm';
    public const APPT_CANCEL = 'cpms_appt_cancel';
    public const APPT_RESCHEDULE = 'cpms_appt_reschedule';
    public const APPT_NO_SHOW = 'cpms_appt_no_show';

    // ===== Visit / Queue =====
    public const VISIT_READ = 'cpms_visit_read';
    public const QUEUE_READ = 'cpms_queue_read';
    public const QUEUE_CHECKIN = 'cpms_queue_checkin';
    public const QUEUE_ADVANCE = 'cpms_queue_advance';
    public const QUEUE_CALL = 'cpms_queue_call';
    public const QUEUE_CHECKOUT = 'cpms_queue_checkout';

    // ===== Consultation =====
    public const CONSULT_START = 'cpms_consult_start';
    public const CONSULT_COMPLETE = 'cpms_consult_complete';
    public const CONSULT_REOPEN = 'cpms_consult_reopen';

    // ===== Clinical Record =====
    public const MEDICAL_READ = 'cpms_medical_read';
    public const NOTE_CREATE = 'cpms_note_create';
    public const NOTE_UPDATE = 'cpms_note_update';
    public const REC_CREATE = 'cpms_rec_create';

    // ===== Private Clinical Note =====
    public const PRIVATE_NOTE_READ = 'cpms_private_note_read';
    public const PRIVATE_NOTE_CREATE = 'cpms_private_note_create';
    public const PRIVATE_NOTE_UPDATE = 'cpms_private_note_update';

    // ===== Prescription =====
    public const RX_READ = 'cpms_rx_read';
    public const RX_CREATE = 'cpms_rx_create';
    public const RX_VOID = 'cpms_rx_void';

    // ===== Medical Attachment =====
    public const FILE_UPLOAD = 'cpms_file_upload';
    public const FILE_READ = 'cpms_file_read';

    // ===== Invoice =====
    public const INVOICE_READ = 'cpms_invoice_read';
    public const INVOICE_CREATE = 'cpms_invoice_create';
    public const INVOICE_ADJUST = 'cpms_invoice_adjust';
    public const INVOICE_VOID = 'cpms_invoice_void';

    // ===== Payment (ثبت عادی جدا از عملیات حساس) =====
    public const PAYMENT_CREATE = 'cpms_payment_create';
    public const PAYMENT_VOID = 'cpms_payment_void';
    public const PAYMENT_REFUND = 'cpms_payment_refund';

    // ===== Finance / Reports / Export / Audit / Search / Settings =====
    public const FINANCE_READ = 'cpms_finance_read';
    public const REPORT_READ = 'cpms_report_read';
    public const EXPORT = 'cpms_export';
    public const AUDIT_READ = 'cpms_audit_read';
    public const SEARCH = 'cpms_search';
    public const CONFIG = 'cpms_config';

    // ===== SMS (فنی — ADR-0025) =====
    public const SMS_CONFIG = 'cpms_sms_config';

    /**
     * ADR-0030 — Override عمدیِ مدیریتی روی Capهای نقش (Option).
     * ساختار: array<string(role), list<string(caps)>> — فقط نقش‌های CPMS و فقط
     * زیرمجموعه ALL_CAPS. وقتی برای نقشی Override ثبت شده باشد، Self-healing
     * به‌جای Template پیش‌فرض کلاس از آن پیروی می‌کند (ادغام تعمدی ادمین =
     * «دستکاری مخرب» نیست و بی‌صدا پاک نمی‌شود).
     */
    public const OPTION_OVERRIDE = 'cpms_role_caps_override';

    /** فهرست کامل (مرجع خودکار برای تست TP-10). */
    public const ALL_CAPS = [
        self::PATIENT_READ, self::PATIENT_CREATE, self::PATIENT_UPDATE, self::PATIENT_ARCHIVE, self::PATIENT_MERGE,
        self::APPT_READ, self::APPT_CREATE, self::APPT_CONFIRM, self::APPT_CANCEL, self::APPT_RESCHEDULE, self::APPT_NO_SHOW,
        self::VISIT_READ, self::QUEUE_READ, self::QUEUE_CHECKIN, self::QUEUE_ADVANCE, self::QUEUE_CALL, self::QUEUE_CHECKOUT,
        self::CONSULT_START, self::CONSULT_COMPLETE, self::CONSULT_REOPEN,
        self::MEDICAL_READ, self::NOTE_CREATE, self::NOTE_UPDATE, self::REC_CREATE,
        self::PRIVATE_NOTE_READ, self::PRIVATE_NOTE_CREATE, self::PRIVATE_NOTE_UPDATE,
        self::RX_READ, self::RX_CREATE, self::RX_VOID,
        self::FILE_UPLOAD, self::FILE_READ,
        self::INVOICE_READ, self::INVOICE_CREATE, self::INVOICE_ADJUST, self::INVOICE_VOID,
        self::PAYMENT_CREATE, self::PAYMENT_VOID, self::PAYMENT_REFUND,
        self::FINANCE_READ, self::REPORT_READ, self::EXPORT, self::AUDIT_READ,
        self::SEARCH, self::CONFIG,
        self::SMS_CONFIG,
    ];

    public const SECRETARY_CAPS = [
        self::PATIENT_READ, self::PATIENT_CREATE, self::PATIENT_UPDATE,
        self::APPT_READ, self::APPT_CREATE, self::APPT_CONFIRM, self::APPT_CANCEL, self::APPT_RESCHEDULE, self::APPT_NO_SHOW,
        self::VISIT_READ, self::QUEUE_READ, self::QUEUE_CHECKIN, self::QUEUE_ADVANCE, self::QUEUE_CHECKOUT,
        self::FILE_UPLOAD, self::FILE_READ,
        self::INVOICE_READ, self::INVOICE_CREATE, self::INVOICE_ADJUST, self::INVOICE_VOID,
        self::PAYMENT_CREATE, self::PAYMENT_VOID, self::PAYMENT_REFUND,
        self::FINANCE_READ,
        self::SEARCH,
    ];

    public const DOCTOR_CAPS = [
        self::PATIENT_READ,
        self::PATIENT_UPDATE,
        self::APPT_READ, self::APPT_CREATE, self::APPT_CONFIRM, self::APPT_CANCEL, self::APPT_RESCHEDULE, self::APPT_NO_SHOW,
        self::VISIT_READ, self::QUEUE_READ, self::QUEUE_CALL,
        self::CONSULT_START, self::CONSULT_COMPLETE, self::CONSULT_REOPEN,
        self::MEDICAL_READ, self::NOTE_CREATE, self::NOTE_UPDATE, self::REC_CREATE,
        self::PRIVATE_NOTE_READ, self::PRIVATE_NOTE_CREATE, self::PRIVATE_NOTE_UPDATE,
        self::RX_READ, self::RX_CREATE, self::RX_VOID,
        self::FILE_UPLOAD, self::FILE_READ,
        self::INVOICE_READ, self::INVOICE_CREATE,
        self::PAYMENT_VOID, self::PAYMENT_REFUND,
        self::FINANCE_READ, self::REPORT_READ,
        self::SEARCH,
    ];

    /**
     * ساخت نقش‌ها (idempotent) + به‌روزرسانی Capabilities (در صورت تغییر نسخه).
     */
    public static function register(): void
    {
        if (!function_exists('add_role')) {
            return;
        }

        self::registerRole(self::ROLE_PATIENT, 'بیمار', ['read' => true]);
        self::registerRole(self::ROLE_SECRETARY, 'منشی مطب', self::capsMap(self::ROLE_SECRETARY));
        self::registerRole(self::ROLE_DOCTOR, 'پزشک', self::capsMap(self::ROLE_DOCTOR));

        // Administrator وردپرس: فقط فنی — بدون Medical/Audit/Export (P-3)
        $admin = get_role('administrator');
        if ($admin !== null) {
            if (!$admin->has_cap(self::CONFIG)) {
                $admin->add_cap(self::CONFIG);
            }
            if (!$admin->has_cap(self::SMS_CONFIG)) {
                $admin->add_cap(self::SMS_CONFIG);
            }
        }
    }

    /**
     * @param array<string, bool> $caps
     */
    private static function registerRole(string $role, string $label, array $caps): void
    {
        $existing = get_role($role);
        if ($existing === null) {
            add_role($role, $label, $caps);

            return;
        }
        // به‌روزرسانی: Capabilityهای جدید افزوده، Capabilityهای حذف‌شده برداشته می‌شوند
        foreach (self::ALL_CAPS as $cap) {
            if (array_key_exists($cap, $caps) && !$existing->has_cap($cap)) {
                $existing->add_cap($cap);
            }
            if (!array_key_exists($cap, $caps) && $existing->has_cap($cap)) {
                $existing->remove_cap($cap);
            }
        }
        // Self-healing کامل (TP-10): هر Capability با پیشوند cpms_ خارج از فهرست
        // مجازِ این نقش (Template پیش‌فرض یا Override عمدی ادمین — ADR-0030)
        // هم پاک می‌شود — Least Privilege.
        foreach (array_keys($existing->capabilities) as $cap) {
            if (is_string($cap) && str_starts_with($cap, 'cpms_') && !array_key_exists($cap, $caps)) {
                $existing->remove_cap($cap);
            }
        }
    }

    // ================= ADR-0030 — Override مدیریتی Capها =================

    /**
     * نقشه موثر Capهای یک نقش: Override ذخیره‌شده مقدم بر Template کلاس است.
     *
     * @return array<string, bool>
     */
    public static function capsMap(string $role): array
    {
        $defaults = match ($role) {
            self::ROLE_DOCTOR => self::DOCTOR_CAPS,
            self::ROLE_SECRETARY => self::SECRETARY_CAPS,
            default => [],
        };
        $overrides = self::overrides();
        if (isset($overrides[$role]) && is_array($overrides[$role])) {
            $clean = self::sanitizeCapList($overrides[$role]);

            return array_fill_keys($clean, true);
        }

        return array_fill_keys($defaults, true);
    }

    /**
     * فهرست Overrideهای فعلی (role => caps) — بدون وابستگی به WP در Unit.
     *
     * @return array<string, list<string>>
     */
    public static function overrides(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }
        $option = get_option(self::OPTION_OVERRIDE, []);

        return is_array($option) ? $option : [];
    }

    /**
     * ثبت/به‌روزرسانی Override عمدی یک نقش توسط مدیریتی با `cpms_config`.
     *
     *  - فقط نقش‌های CPMS؛ فقط زیرمجموعه ALL_CAPS (باقی بدون خطا فیلتر می‌شود).
     *  - نقش بیمار همیشه بدون Cap باقی می‌ماند (P-5 — Ownership فقط).
     *  - اگر فهرست ارسالی == Template پیش‌فرض باشد، Override حذف می‌شود
     *    (Semantics: «بازگشت به پیش‌فرض») تا Option رشد بی‌مورد نکند.
     *
     * @param list<string> $caps
     */
    public static function setRoleCaps(string $role, array $caps): bool
    {
        if (!in_array($role, [self::ROLE_SECRETARY, self::ROLE_DOCTOR], true)) {
            return false; // Patient: بدون Cap (P-5) — قابل ویرایش نیست
        }

        $clean = self::sanitizeCapList($caps);
        $overrides = self::overrides();
        // مقایسه به‌صورت «مجموعه» (هر دو سمت نرمال/مرتب) — ترتیب تعریف ثابت‌ها
        // اهمیتی ندارد؛ وگرنه «بازگشت به پیش‌فرض» هرگز تشخیص داده نمی‌شد.
        $defaults = match ($role) {
            self::ROLE_DOCTOR => self::sanitizeCapList(self::DOCTOR_CAPS),
            default => self::sanitizeCapList(self::SECRETARY_CAPS),
        };

        if ($clean === $defaults) {
            unset($overrides[$role]);
        } else {
            $overrides[$role] = $clean;
        }

        if (function_exists('update_option')) {
            update_option(self::OPTION_OVERRIDE, $overrides, true);
        }
        self::register();

        return true;
    }

    /**
     * پاک‌سازی و نرمال‌سازی فهرست Cap: فقط اعضای ALL_CAPS، یکتا، مرتب.
     *
     * @param array<array-key, mixed> $caps
     * @return list<string>
     */
    public static function sanitizeCapList(array $caps): array
    {
        $clean = [];
        foreach ($caps as $cap) {
            if (is_string($cap) && in_array($cap, self::ALL_CAPS, true)) {
                $clean[$cap] = true;
            }
        }
        $list = array_keys($clean);
        sort($list);

        return $list;
    }
}

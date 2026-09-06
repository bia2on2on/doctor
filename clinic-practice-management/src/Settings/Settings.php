<?php

declare(strict_types=1);

namespace ClinicCore\Settings;

use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * تنظیمات کلینیک (جدول cpms_settings) — با پیش‌فرض‌های سراسری.
 *
 * مستند کامل (واحد + semantics): docs/settings-reference.md
 *
 * قواعد:
 *  - همه مقادیر زیر Seed/Default هستند و قابل تغییر از Settings (کاربر cpms_config) — Hard-Code نیستند (تصمیم F1-D3).
 *  - Secret/API Key در این جدول ذخیره نمی‌شود — فقط wp-config/Env.
 *  - Timezone: ذخیره UTC، نمایش timezone کلینیک + Jalali (Presentation فقط).
 */
final class Settings
{
    /**
     * پیش‌فرض‌ها (تأییدشده 2026-09-05).
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        // UI
        'ui.calendar' => 'jalali', // enum: jalali|gregorian — فقط Presentation
        // OTP (تصمیم F1-D3: TTL 2 دقیقه)
        'otp.ttl_sec' => 120,
        'otp.max_attempts' => 5,
        'otp.cooldown_sec' => 60,
        'otp.daily_max' => 3,
        'otp.lockout_sec' => 900,
        'otp.hourly_max' => 10,
        // رزرو (تصمیم F1-D3 + D4: مدت قابل تنظیم، Snapshot در Booking)
        'booking.duration_default_min' => 20, // پیش‌فرض کلینیک (لایه 1 ADR-0017)
        'booking.slot_capacity_default' => 1,
        'booking.min_lead_hours' => 2,
        'booking.max_future_days' => 60,
        'booking.cancel_deadline_hours' => 24, // SRS FR-4.9 (پیش‌فرض 24 ساعت)
        'booking.reschedule_deadline_hours' => 24, // SRS FR-4.10 (همان قوانین)
        'booking.hold_ttl_sec' => 600,
        'booking.buffer_pre_default_min' => 0, // V2
        'booking.buffer_post_default_min' => 0, // V2
        // یادآوری
        'appt.reminder_before_hours' => 24,
        // صف
        'queue.no_show_grace_minutes' => 30,
        'queue.auto_enqueue' => true,
        'queue.max_recalls' => 3,
        // Jobها (تصمیم F1-D3: 3 آزمون)
        'jobs.default_max_attempts' => 3,
        // بیمار
        'patient.profile_invoices_visible' => false,
        // دست‌خط
        'hw.local_retain' => 'off', // off|last|always
        'hw.autosave_sec' => 5,
        'hw.version_keep' => 10, // ADR-0009 — حداقل نسخه‌های نگهداری‌شده هر صفحه (GC)
        'hw.version_max_age_days' => 30, // ADR-0009 — حذف نسخه‌های قدیمی‌تر (handwriting.gc)
        // فایل (تصمیم F1-D3: 10MB)
        'files.max_upload_bytes' => 10485760,
        'files.encrypt_at_rest' => false,
        // Retention (تصمیم نهایی: D7)
        'retention.audit_years' => 10,
        'retention.record_years' => 15,
        // SMS — Provider-Agnostic (ADR-0025). Secret در این جدول ذخیره نمی‌شود (Vault).
        'sms.provider' => '', // '' = log (Dev/Staging)؛ 'generic_api' یا id Adapter
        'sms.auth_method' => '', // api_key | bearer | username_password
        'sms.sender' => '',
        'sms.advanced' => [
            'timeout_sec' => 5,
            'retry_count' => 3,
        ],
        'sms.templates' => [], // per event: ['template_id' => '...', 'updated_at' => ...]
        'sms.generic' => [
            'endpoint' => '',
            'http_method' => 'POST',
            'auth_header' => 'Authorization',
            'auth_format' => 'Bearer {key}',
            'request_json' => '{"to": "{mobile}", "message": "{message}"}',
            'response' => [
                'success_field' => 'status',
                'success_values' => ['sent', '1', 'true', 'ok'],
                'id_field' => 'message_id',
                'error_field' => 'error',
            ],
            'extra_headers' => [],
        ],
        'sms.last_test' => [],
        // Real-time (ADR-0007)
        'rt.poll_sec_secretary' => 3,
        'rt.poll_sec_doctor' => 5,
        // اعلان (F8 — notifications.md §5)
        'notif.quiet_hours_start' => '08:00', // SMS غیرتعاملی فقط در این بازه (OTP مستثنا)
        'notif.quiet_hours_end' => '21:00',
        'notif.archive_days' => 90, // Retention اعلان‌های Internal
        // گزارش (F8 — FR-19.3)
        'reports.max_range_days' => 366, // سقف بازه گزارش/Export (bounded)
        'reports.export_retention_days' => 7, // نگهداری فایل Export قبل از حذف
        'reports.export_max_rows' => 10000, // سقف ردیف‌های Export
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public function __construct(private readonly CpmsDb $db, private readonly int $clinicId = 1)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }

        return $default;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s',
            [$this->clinicId, $key]
        );
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($row === null) {
            $this->db->insert('cpms_settings', [
                'clinic_id' => $this->clinicId,
                'key' => $key,
                'value_json' => $json,
                'updated_by_wp_user_id' => $updatedBy,
                'updated_at' => $this->db->nowUtcSql(),
            ]);
        } else {
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_settings') .
                ' SET value_json = %s, updated_by_wp_user_id = %s, updated_at = %s
                 WHERE clinic_id = %d AND `key` = %s',
                [$json, $updatedBy, $this->db->nowUtcSql(), $this->clinicId, $key]
            );
        }
        self::$cache = null;
    }

    public function otpPolicy(): OtpPolicy
    {
        return new OtpPolicy(
            (int) $this->get('otp.ttl_sec'),
            (int) $this->get('otp.max_attempts'),
            (int) $this->get('otp.cooldown_sec'),
            (int) $this->get('otp.daily_max'),
            (int) $this->get('otp.lockout_sec')
        );
    }

    public function jobMaxAttempts(): int
    {
        return (int) $this->get('jobs.default_max_attempts');
    }

    public function clinicTimezone(): string
    {
        $row = $this->db->fetchRow(
            'SELECT timezone FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinicId]
        );

        return $row['timezone'] ?? 'Asia/Tehran';
    }

    private function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $rows = $this->db->fetchAll(
            'SELECT `key`, value_json FROM ' . $this->db->table('cpms_settings') . ' WHERE clinic_id = %d',
            [$this->clinicId]
        );
        foreach ($rows as $row) {
            self::$cache[(string) $row['key']] = json_decode((string) $row['value_json'], true);
        }
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }
}

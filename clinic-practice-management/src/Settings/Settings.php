<?php

declare(strict_types=1);

namespace ClinicCore\Settings;

use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Infrastructure\Audit\AuditLogger;
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
        // سقف درخواستِ OTP از یک IP در هر ساعت (شمارندهٔ 'otp-ip:') — پیش‌فرض
        // همان رفتارِ قبلی (۱۰)؛ خواننده: OtpService::clamp_ip_hourly_max.
        'otp.ip_hourly_max'                 => 10,
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
        // Public clinic discovery (Phase 8 Slice 4) — FAIL-CLOSED opt-in.
        // A Clinic is advertised in the public directory ONLY when its own
        // settings row sets this key to a true value AND the Clinic/lifecycle,
        // Organization and Location gates pass. Absent row ⇒ false ⇒ never
        // listed. There is intentionally no "list every active Clinic" default.
        'public.listing_opt_in'             => false,
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
        // F1-5 — Retention لاگ عملیاتی (جدول hot؛ Audit جدا و ۱۰ساله است).
        // M-2: منبعِ مؤثرِ runtime دیگر این‌جا نیست — `InstallationSettings::
        // getOplogRetentionDays()` (سطح نصب، Option `cpms_retention_oplog_days`).
        // این مقدار فقط پیش‌فرضِ مستند/هم‌تراز است و خواننده ندارد.
        'retention.oplog_days' => 90,
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
        // F10 — لایسنس (ADR-0023). Secret هرگز اینجا نیست (Vault/env).
        'license.server_url' => '', // https://… — بدون مقدار = activation دستی/آفلاین
        // F10 — بکاپ (spec §22–§24). پیش‌فرض خاموش: فعال‌سازی آگاهانه در Admin/CLI.
        'backup.enabled' => false,
        'backup.interval_hours' => 24,
        'backup.keep_count' => 14, // Retention: N نسخه‌ی آخر (V1 — بدون Tiering)
        'backup.storage_path' => '', // '' = ریشهٔ خصوصی بیرون DocumentRoot (OD-7/OD-9؛ داخل webroot = Fail-Closed)
        'backup.last_run_at' => 0, // ts آخرین بکاپ موفق (Job)
        // F10 — به‌روزرسانی امن (ADR-0029)
        'update.check_interval_hours' => 24,
        'update.channel' => 'stable', // stable|beta
    ];

    /**
     * Cache تنظیمات — per-clinic (C6، bug 1 census): استاتیکِ ساده بین
     * instanceهای clinic مختلف مشترک بود و تنظیمات یک کلینیک به دیگری
     * leak می‌کرد. کلید = clinic_idِ instance.
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * Clinics whose latest bulk Settings read failed. While a Clinic is in
     * this set, get() keeps returning the documented defaults instead of
     * retrying mid-operation: one consumer therefore never combines
     * unverified defaults for some keys with freshly loaded stored values
     * for others in the same snapshot. The latch is cleared by a verified
     * load (require_verified_snapshot()), by flushCache(), or by a
     * successful set().
     *
     * @var array<int, true>
     */
    private static array $load_failed = [];

    /**
     * کلیدهای Telemetry عملیاتی (نه Config کاربر) — Audit نمی‌شوند (F1-4).
     *
     * این کلیدها توسط سیستم و به‌تکرار نوشته می‌شوند (Tick/بکاپ/تست SMS) و جدا
     * در Operational Log ثبت می‌شوند؛ ورودشان به Audit جدول ۱۰ساله = سیل رکورد.
     * کلید Config جدیدِ نوشته‌شده از مسیر سیستمی باید به این لیست اضافه نشود.
     *
     * @var list<string>
     */
    private const RUNTIME_KEYS = ['jobs.last_tick_at', 'backup.last_run_at', 'sms.last_test'];

    /**
     * کلیدهای Credential-دار — رویدادِ تغییر Audit می‌شود اما مقدار هرگز
     * (حتی Ciphertext — سیاست Vault: Secret به Audit تعلق ندارد؛
     * settings-reference «Secrets»). جایگزین: placeholder ثابت.
     *
     * @var list<string>
     */
    private const REDACTED_VALUE_KEYS = ['sms.auth'];

    private const REDACTED_PLACEHOLDER = '[redacted:credentials]';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly int $clinicId,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * شناسهٔ Clinic فعالِ این نمونهٔ Settings (مقدار پیکربندی‌شده؛ پیش‌فرض =
     * Clinic پیش‌فرضِ seed شده). الگوی مجاز برای پارامتری‌کردن Queryها به‌جای
     * literal `1` (AD-13) — همان الگوی داخلیِ خودِ این کلاس
     * (`clinic_id = %d`).
     */
    public function clinicId(): int
    {
        return $this->clinicId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ( ! $this->load() ) {
            return array_key_exists( $key, self::DEFAULTS ) ? self::DEFAULTS[ $key ] : $default;
        }
        if (array_key_exists($key, self::$cache[$this->clinicId])) {
            return self::$cache[$this->clinicId][$key];
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }

        return $default;
    }

    /**
     * Whether this Clinic has a persisted row for the key, independent of
     * defaults. If the bulk settings cache is unavailable, query strictly so
     * a read failure cannot masquerade as a missing legacy setting.
     */
    public function has_stored( string $key ): bool {
        $clinic_id = $this->clinicId();
        if ( array_key_exists( $clinic_id, self::$cache ) ) {
            return array_key_exists( $key, self::$cache[ $clinic_id ] );
        }

        return $this->db->fetch_all_strict(
            'SELECT `key` FROM ' . $this->db->table( 'cpms_settings' ) . ' WHERE clinic_id = %d AND `key` = %s LIMIT 1',
            [ $clinic_id, $key ]
        ) !== [];
    }

    public function set( string $key, mixed $value, ?int $updated_by = null ): void
    {
        // The before-value drives the effective-change comparison and the
        // mandatory audit payload, so it must come from a verified read:
        // if this Clinic's settings cannot be loaded, refuse the write
        // (no write, no audit) instead of comparing or auditing against
        // an unverified default that may differ from the stored value.
        $this->require_verified_snapshot();

        // F1-4 — before (مقدار مؤثر: ردیف یا Default) برای Audit تغییر مؤثر
        $before      = $this->get( $key );
        $before_json = wp_json_encode( $before, JSON_UNESCAPED_UNICODE );
        $after_json  = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- published API property.
        $clinic_id = $this->clinicId;

        // The point lookup decides insert-vs-update, so it must distinguish
        // a failed read from genuine absence: a soft fetchRow returns null
        // for both, which could route a failed read into an insert and let
        // the audit below record a mutation that never persisted.
        try {
            $lookup = $this->db->fetch_all_strict(
                'SELECT id FROM ' . $this->db->table( 'cpms_settings' ) . ' WHERE clinic_id = %d AND `key` = %s',
                [ $clinic_id, $key ]
            );
        } catch ( \RuntimeException $e ) {
            throw new SettingsUnavailableException( 'Settings lookup failed.', 0, $e );
        }
        $row = $lookup[0] ?? null;
        // D6 audit atomicity: the setting write and its mandatory SETTING_UPDATE
        // audit share one boundary (nested SAVEPOINT when a caller already opened
        // one). A failed audit rolls the setting change back; the in-memory cache is
        // invalidated only after a durable commit, so a rolled-back read keeps the
        // previous value instead of ever exposing an un-audited setting.
        $this->db->transactional(
            function () use ( $key, $value, $updated_by, $before, $after_json, $before_json, $row, $clinic_id ) {
                // A failed mutation must abort the boundary (rolling back
                // any audit written alongside it) instead of letting
                // auditChange record a change that never persisted.
                if ( $row === null ) {
                    $written = $this->db->insert(
                        'cpms_settings',
                        [
                            'clinic_id'             => $clinic_id,
                            'key'                   => $key,
                            'value_json'            => $after_json,
                            'updated_by_wp_user_id' => $updated_by,
                            'updated_at'            => $this->db->nowUtcSql(),
                        ]
                    );
                } else {
                    $written = $this->db->query(
                        'UPDATE ' . $this->db->table( 'cpms_settings' ) .
                        ' SET value_json = %s, updated_by_wp_user_id = %s, updated_at = %s
                         WHERE clinic_id = %d AND `key` = %s',
                        [ $after_json, $updated_by, $this->db->nowUtcSql(), $clinic_id, $key ]
                    );
                }
                if ( ! $written ) {
                    throw new \RuntimeException( 'Settings write failed.' );
                }

                $this->auditChange( $key, $before, $value, $before_json, $after_json, $updated_by );
            }
        );
        unset( self::$cache[ $clinic_id ], self::$load_failed[ $clinic_id ] );
    }

    /**
     * Write several settings as ONE atomic transition: every value and its
     * SETTING_UPDATE audit commits, or none of them do. Callers performing
     * a single logical transition across keys (facility selection plus
     * acknowledgment reset, wizard restart, booking policy) must use this
     * instead of sequential set() calls, whose per-call transactions could
     * leave a partial state behind (for example a new facility selection
     * with the previous selection's acknowledgment still valid). Nested
     * per-set transactions become savepoints under this boundary.
     *
     * @param array<string, mixed> $values
     */
    public function set_many( array $values, ?int $updated_by = null ): void {
        $this->db->transactional(
            function () use ( $values, $updated_by ) {
                foreach ( $values as $key => $value ) {
                    $this->set( (string) $key, $value, $updated_by );
                }
            }
        );
    }

    /**
     * Audit تغییر Setting (F1-4) — action مرجع `SETTING_UPDATE` (audit-strategy §2).
     *
     * - فقط تغییرِ مؤثر (مقدار قدیم ≠ جدید) ثبت می‌شود؛ no-op نه.
     * - before/after به شکل `{setting, value}` — ساختار ثابت، نه کلیدِ خودِ Setting
     *   (کلیدهایی مثل otp.ttl_sec در زمان F1-4 توسط Sanitizeِ substring حذف می‌شدند —
     *   از F1-8 تطبیق دقیق است؛ این ساختار مستقل از آن درست می‌ماند).
     * - کلیدهای Runtime (telemetry) ثبت نمی‌شوند — RUNTIME_KEYS.
     * - بدون کاربر (سیستم) → actor null → AuditLogger نقش «system» می‌گذارد.
     */
    private function auditChange(
        string $key,
        mixed $before,
        mixed $after,
        string|false $before_json,
        string|false $after_json,
        ?int $updated_by
    ): void {
        if ( $this->audit === null ) {
            return;
        }
        if ( in_array( $key, self::RUNTIME_KEYS, true ) ) {
            return;
        }
        if ( $before_json === $after_json ) {
            return; // تغییر مؤثر نیست — فقط refresh ردیف/updated_by
        }
        if ( in_array( $key, self::REDACTED_VALUE_KEYS, true ) ) {
            // Credentials: فقط رویداد + actor — مقدار (حتی sealed) کپی نمی‌شود
            $before = self::REDACTED_PLACEHOLDER;
            $after  = self::REDACTED_PLACEHOLDER;
        }
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- published API property.
        $clinic_id = $this->clinicId;
        $this->audit->log(
            'SETTING_UPDATE',
            $updated_by !== null ? [ 'wp_user_id' => $updated_by, 'role' => $this->roleOf( $updated_by ) ] : null,
            'setting',
            null,
            null,
            [ 'setting' => $key, 'value' => $before ],
            [ 'setting' => $key, 'value' => $after ],
            [ 'op' => 'config_update', 'clinic_id' => $clinic_id ],
            $clinic_id
        );
    }

    private function roleOf(int $userId): string
    {
        if (!function_exists('get_userdata')) {
            return 'unknown'; // Context بدون WP (Unit/CLI)
        }
        $user = get_userdata($userId);

        return $user !== false ? ($user->roles[0] ?? 'unknown') : 'unknown';
    }

    public function otpPolicy(): OtpPolicy
    {
        // OTP limits are rate-limiting controls: the policy must be built
        // entirely from verified stored settings (or genuine defaults when
        // no rows exist), never from a mix of unverified defaults and
        // later-loaded values. An unreadable settings table fails closed.
        $this->require_verified_snapshot();

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

    private function load(): bool
    {
        if ( array_key_exists( $this->clinicId(), self::$cache ) ) {
            return true;
        }
        if ( $this->db->is_strict() ) {
            // CpmsDb strict mode (e.g. migrations): a failed SQL read must
            // propagate, exactly as the soft reader did before. Strict mode
            // is checked before the soft-failure latch so that enabling
            // strict mode later in the same request can never have a strict
            // contract error masked by an earlier soft failure.
            $this->load_strict();

            return true;
        }
        if ( isset( self::$load_failed[ $this->clinicId() ] ) ) {
            // Consistent snapshot: after a failed read, get() keeps
            // returning defaults until a verified-load boundary
            // (require_verified_snapshot(), flushCache(), or set()).
            return false;
        }

        try {
            $this->load_strict();
        } catch ( \RuntimeException ) {
            // Preserve Settings' soft-read behavior for ordinary consumers,
            // but never cache the failed read as an empty (legacy)
            // configuration, and do not retry within this snapshot.
            self::$load_failed[ $this->clinicId() ] = true;

            return false;
        }

        return true;
    }

    /**
     * Load this Clinic's settings with a strict read and cache the verified
     * result. Throws on any database read failure.
     */
    private function load_strict(): void {
        try {
            $rows = $this->db->fetch_all_strict(
                'SELECT `key`, value_json FROM ' . $this->db->table( 'cpms_settings' ) . ' WHERE clinic_id = %d',
                [ $this->clinicId() ]
            );
        } catch ( \RuntimeException $e ) {
            throw new SettingsUnavailableException( 'Settings are temporarily unavailable.', 0, $e );
        }

        $clinic_cache = [];
        foreach ( $rows as $row ) {
            $clinic_cache[ (string) $row['key'] ] = json_decode( (string) $row['value_json'], true );
        }
        self::$cache[ $this->clinicId() ] = $clinic_cache;
        unset( self::$load_failed[ $this->clinicId() ] );
    }

    /**
     * Require a verified settings snapshot for operations whose decisions
     * must not rest on unverified defaults (settings writes and their
     * audit comparison, OTP rate-limit policy). Returns immediately when
     * a verified snapshot is already cached; otherwise performs a fresh
     * strict load even if an earlier read in this snapshot failed, and
     * throws a RuntimeException when the settings cannot be verified.
     */
    public function require_verified_snapshot(): void {
        if ( array_key_exists( $this->clinicId(), self::$cache ) ) {
            return;
        }

        $this->load_strict();
    }

    public static function flushCache(): void
    {
        self::$cache       = [];
        self::$load_failed = [];
    }
}

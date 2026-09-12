<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/**
 * منبعِ حقیقتِ طبقهٔ scope برای **همهٔ** انواعِ Jobِ ثبت‌شده — Phase 2 (§A-3).
 *
 * چرا یک registry واحد:
 *  - تعریفِ طبقه فقط **یک‌جا** است؛ `App::dispatcher()` همان‌جا آن را مصرف و
 *    enforce می‌کند (نه یک آرایهٔ موازی در تست و نه حدس در زمانِ اجرا).
 *  - مستقیماً و بدون Reflection قابل تست است (RT-12).
 *
 * قاعدهٔ سازگاری (§A-3 / A-1.12):
 *  - `T` ⇒ `clinic_id` غیرتهی و اعتبارسنجی‌شده (از payload یا مالکیت ردیف)؛
 *  - `S` ⇒ `clinic_id` باید NULL باشد (سطح نصب)؛
 *  - `W` ⇒ `clinic_id` باید NULL باشد و انتسابِ هر ردیف از آبجکت مرجعِ همان
 *    ردیف مشتق شود.
 *
 * ⛔ نوعِ بدونِ طبقه ⇒ **reject** (`JobScopeUnknownException`)، هرگز حدس.
 * ⛔ `NULL` به‌طور خودکار «system» نیست — معنا فقط از طبقهٔ ثبت‌شدهٔ همان نوع
 *    می‌آید (§۳-B-2).
 *
 * ⚠ این registry **فقط طبقه‌بندی** را ثبت می‌کند. هیچ ستونِ `clinic_id`‌ای به
 * `cpms_jobs` اضافه نشده و هیچ دادهٔ scope‌ای persist نمی‌شود (§۸-۴: schema
 * مجاز نیست). source of truthِ **زمان‌بندی** همچنان `App::RECURRING_JOBS` و
 * source of truthِ **handlerها** همچنان `App::dispatcher()` است؛ سازگاریِ این
 * سه منبع توسط تست enforce می‌شود (RT-12 drift guard).
 */
final class JobScopeRegistry
{
    /**
     * طبقهٔ scope هر نوعِ Jobِ ثبت‌شده — دقیقاً ۱۵ نوعِ واقعیِ `App::dispatcher()`.
     *
     * @var array<string, JobScopeClass::*>
     */
    private const CLASSES = [
        // ---- T: tenant-scoped (context از منبع durable خودِ Job) ----
        // payload {message_id} ⇒ مالکیت از cpms_sms_messages.clinic_id
        'sms.send' => JobScopeClass::TENANT,
        // payload {clinic_id} ⇒ الگوی کاریِ موجودِ ExportService (بدون تغییر)
        'report.export' => JobScopeClass::TENANT,

        // ---- S: installation-scoped (جدول/پیکربندی بدون بُعد tenant) ----
        'cleanup.otp' => JobScopeClass::SYSTEM,
        'cleanup.rate_limits' => JobScopeClass::SYSTEM,
        'cleanup.idem' => JobScopeClass::SYSTEM,
        // §۸-۲ RESOLVED: retention.oplog_days = نصب‌گسترده
        'cleanup.oplog' => JobScopeClass::SYSTEM,
        'handwriting.gc' => JobScopeClass::SYSTEM,
        // §۸-۱ مشروط: کنترل‌پلینِ نصب؛ امروز پیکربندی از سطر Clinic خوانده می‌شود
        'license.refresh' => JobScopeClass::SYSTEM,
        'backup.run' => JobScopeClass::SYSTEM,

        // ---- W: installation-wide sweep با semantics پر-ردیف ----
        'holds.expire' => JobScopeClass::SWEEP,
        'slots.generate' => JobScopeClass::SWEEP,
        'visits.no_show' => JobScopeClass::SWEEP,
        'notif.dispatch' => JobScopeClass::SWEEP,
        'appt.reminder' => JobScopeClass::SWEEP,
        'fu.reminder' => JobScopeClass::SWEEP,
    ];

    /** غیرقابل instantiate — registry ایستا و فقط‌خواندنی. */
    private function __construct()
    {
    }

    /**
     * همهٔ انواعِ دارای طبقه — مرتب‌شده برای مقایسهٔ قطعی با registry زمانِ اجرا.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        $types = array_keys(self::CLASSES);
        sort($types);

        return $types;
    }

    public static function isRegistered(string $type): bool
    {
        return isset(self::CLASSES[$type]);
    }

    /**
     * طبقهٔ scope یک نوع — Fail-Closed.
     *
     * @return JobScopeClass::*
     *
     * @throws JobScopeUnknownException اگر نوع ثبت نشده باشد (هرگز حدس نمی‌زند)
     */
    public static function classFor(string $type): string
    {
        $class = self::CLASSES[$type] ?? null;
        if ($class === null) {
            throw new JobScopeUnknownException(
                'JOB_SCOPE_UNCLASSIFIED',
                'نوعِ Job «' . $type . '» طبقهٔ scope ثبت‌شده ندارد و اجرا نمی‌شود '
                    . '(نه system فرض می‌شود، نه Clinic حدس زده می‌شود).',
                ['job_type' => $type]
            );
        }

        return JobScopeClass::assertValid($class);
    }

    /**
     * نسخهٔ بدونِ استثنا — فقط برای پرس‌وجو/گزارش، هرگز برای تصمیمِ اجرا.
     *
     * @return JobScopeClass::*|null
     */
    public static function tryClassFor(string $type): ?string
    {
        $class = self::CLASSES[$type] ?? null;

        return $class === null ? null : JobScopeClass::assertValid($class);
    }

    /**
     * آیا اجرای این نوع به یک Clinic معتبر و اعتبارسنجی‌شده نیاز دارد؟
     *
     * @throws JobScopeUnknownException
     */
    public static function requiresClinicContext(string $type): bool
    {
        return self::classFor($type) === JobScopeClass::TENANT;
    }

    /**
     * آیا `clinic_id = NULL` برای این نوع **معنای ثبت‌شده** دارد؟
     *
     * فقط `S` و `W` — و فقط چون طبقهٔ همان نوع صریحاً ثبت شده است.
     *
     * @throws JobScopeUnknownException
     */
    public static function permitsNullClinic(string $type): bool
    {
        return self::classFor($type) !== JobScopeClass::TENANT;
    }

    /**
     * تعدادِ هر طبقه — برای گارْدِ drift (۲/۷/۶ در §A-3).
     *
     * @return array<string, int>
     */
    public static function countsByClass(): array
    {
        $counts = [JobScopeClass::TENANT => 0, JobScopeClass::SYSTEM => 0, JobScopeClass::SWEEP => 0];
        foreach (self::CLASSES as $class) {
            ++$counts[JobScopeClass::assertValid($class)];
        }

        return $counts;
    }
}

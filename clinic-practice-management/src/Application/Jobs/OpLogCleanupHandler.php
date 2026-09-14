<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Settings\InstallationSettings;

/**
 * پاک‌سازی لاگ‌های عملیاتی قدیمی (Job: cleanup.oplog — F1-5 / M-2).
 *
 * ریشه: `cpms_operational_logs` جدول hot-path است (Request/Job/Error ردیف
 * می‌نویسد) اما هیچ Retention‌ای نداشت → رشد بی‌کران (همان کلاس مشکلی که
 * `cleanup.idem` برای Idempotency بست). برخلاف Audit (۱۰ سال — سند حقوقی)،
 * OpLog فنی/غیرحقوقی است — پیش‌فرض ۹۰ روز.
 *
 * منبعِ پیکربندی (M-2): **سطحِ نصب** — `InstallationSettings::
 * getOplogRetentionDays()` روی wp_options (autoload=no). این Job در
 * `JobScopeRegistry` طبقهٔ **S (installation-scoped)** ثبت شده است؛ پس هنگام
 * ساخت/اجرا **نباید** `Settings`ِ Clinic، `App::scope()`، کاربرِ جاری،
 * `ScopeContext` یا payloadِ Clinic را لمس کند (نه `clinic_id = 0`، نه Clinic
 * مصنوعی، نه Clinic اول). پیش‌فرض مؤثر فعلی بدون تغییر: **۹۰ روز** و
 * مقدارِ خراب/کمتر از ۱ در خودِ `InstallationSettings` امن به همان ۹۰ برمی‌گردد
 * (هرگز حذفِ تهاجمی‌تر نمی‌شود).
 *
 * کرانِ حذف (M-2 — blockerِ بی‌کرانی): هر اجرا حداکثر `DELETE_BATCH_SIZE` ردیفِ
 * واجدِ شرایط را حذف می‌کند — همراستا با قراردادِ موجود مخزن در
 * `NotificationRepository::purgeArchived($days, $limit = 500)`
 * (`ORDER BY id ASC LIMIT %d`). جدول append-only است، پس ترتیبِ صعودیِ `id`
 * همان «قدیمی‌ترین‌ها اول» و **قطعی** است؛ حذف بدونِ `OFFSET`/cursor انجام
 * می‌شود و اجراهای تکرارشوندهٔ همان Job (هر tick) پاک‌سازی را ادامه می‌دهند.
 *
 * صداقتِ ادعا: کرانِ **«تعداد ردیف‌های حذف‌شده»** با تست اثبات شده است.
 * «تعداد ردیف‌های بررسی‌شده» اندازه‌گیری نشده و ادعا نمی‌شود؛ ایندکسِ فعلی
 * (`idx_oplog_level (level, created_at)`) برای predicateِ فقط-`created_at`
 * کفایتِ چنین ادعایی را ندارد (leftmost-prefix) و در این تسک ایندکس/مهاجرتِ
 * جدیدی اضافه نشده است.
 */
final class OpLogCleanupHandler
{
    /**
     * سقفِ حذف در هر اجرا — قراردادِ موجودِ مخزن برای purgeهای Retention
     * (`NotificationRepository::purgeArchived`: `$limit = 500`).
     *
     * این عدد «تعداد ردیف‌های حذف‌شده در هر فراخوانی» را کران‌دار می‌کند؛
     * چون Job در `RECURRING_JOBS` هر tick زمان‌بندی می‌شود، پاک‌سازیِ حجمِ
     * بزرگ به‌صورت طبیعی روی چند اجرا تقسیم می‌شود و هر اجرا Idempotent است.
     */
    public const DELETE_BATCH_SIZE = 500;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly InstallationSettings $installationSettings
    ) {
    }

    /**
     * @return int تعداد ردیف‌های حذف‌شده در همین اجرا (<= DELETE_BATCH_SIZE)
     */
    public function __invoke(array $payload): int
    {
        $days = $this->installationSettings->getOplogRetentionDays();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400) . '.000';

        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('cpms_operational_logs') .
            ' WHERE created_at < %s ORDER BY id ASC LIMIT %d',
            [$cutoff, self::DELETE_BATCH_SIZE]
        );
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Settings;

use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * کارخانهٔ `Settings` **صریحاً per-Clinic** — Phase 2 (§5-D-2).
 *
 * هدفِ طراحیِ سند کانونی: «یک abstraction تمیزِ سطح اپلیکیشن … به‌جای
 * ساخت‌وپراکندۀ ad-hoc `Settings` در لایه‌های مختلف». این کلاس همان نقطهٔ
 * یکتاست: هر لایه‌ای که به پیکربندیِ یک Clinic نیاز دارد، Clinic را **صریح**
 * می‌دهد و همان نمونه را می‌گیرد.
 *
 * قواعد:
 *  - **کلیدِ کش = `clinicId`** (§5-D-2: «اگر کش می‌شود، حتماً per-Clinic کلید
 *    بخورد»). هیچ کشِ مشترکِ بین‌کلینیکی وجود ندارد، پس `A → B → A` هر بار
 *    پیکربندیِ همان Clinic را می‌دهد و state گامِ قبل نشت نمی‌کند (RT-6).
 *  - **Fail-Closed**: `clinicId <= 0` هرگز «system» یا «پیش‌فرض» تلقی نمی‌شود
 *    (AD-13: نه `clinic_id = 1`، نه `clinic_id = 0`).
 *  - این کلاس **scope نمی‌دهد**؛ تصمیمِ «کدام Clinic» با caller است (از scope
 *    درخواست، از payload جاب، یا از مالکیت ردیف). همین جدایی باعث می‌شود
 *    سرویس‌هایی که این کارخانه را نگه می‌دارند **scope-neutral** بمانند و
 *    singletonِ سطح process نتواند Clinicِ bootstrap را میخ کند (RT-6).
 */
final class SettingsFactory
{
    /** @var array<int, Settings> کلید = clinicId */
    private array $instances = [];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * `Settings` یک Clinic **مشخص و اعتبارسنجی‌شده**.
     *
     * @throws ScopeRequiredException اگر clinicId معتبر نباشد (Fail-Closed)
     */
    public function forClinic(int $clinicId): Settings
    {
        if ($clinicId <= 0) {
            throw new ScopeRequiredException(
                'CLINIC_SCOPE_REQUIRED',
                'پیکربندی Clinic بدون شناسهٔ Clinic معتبر حل نمی‌شود (clinic_id = ' . $clinicId
                    . '). نه Clinic پیش‌فرض وجود دارد، نه Clinic حدسی.',
                ['clinic_id' => $clinicId]
            );
        }

        return $this->instances[$clinicId] ??= new Settings($this->db, $clinicId, $this->audit);
    }

    /**
     * باطل‌سازی کش نمونه‌ها (تست‌ها / تغییر ساختار دادهٔ Clinic در طول فرآیند).
     *
     * توجه: کشِ **دادهٔ** تنظیمات در `Settings::$cache` است و با
     * `Settings::flushCache()` خالی می‌شود؛ این متد فقط هویتِ نمونه‌ها را
     * بازمی‌سازد.
     */
    public function reset(): void
    {
        $this->instances = [];
    }
}

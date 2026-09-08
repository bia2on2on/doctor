<?php

declare(strict_types=1);

namespace ClinicCore\Application\Scope;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Resolution سیستمی Clinic فعال برای جریان‌های admin/background/public — Phase 2.
 *
 * قاعدهٔ واحد (Fail-Closed، بدون حدس):
 *  - دقیقاً **یک** Clinic در نصب وجود دارد ⇒ همان Clinic (deterministic —
 *    «تنها Clinic»، نه «اولین Clinic»؛ مطب تک‌پزشکی روی همان مدل Core کار
 *    می‌کند — AD-04/DoD-2).
 *  - صفر یا بیش از یک Clinic ⇒ `CLINIC_SCOPE_REQUIRED` — Scope صریح لازم
 *    است؛ هیچ fallback implicit وجود ندارد (P2-B).
 *
 * این کلاس سیاست مجوز نمی‌دهد (Phase 3)؛ فقط محدودهٔ داده را صریح می‌کند.
 */
final class SystemClinicResolver
{
    private static ?ClinicScope $cached = null;

    /** غیرقابل instantiate. */
    private function __construct()
    {
    }

    /**
     * @throws ScopeRequiredException وقتی تعداد Clinicها ≠ 1 است
     */
    public static function resolve(CpmsDb $db): ClinicScope
    {
        if (self::$cached instanceof ClinicScope) {
            return self::$cached;
        }

        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_clinics')
        );
        if ($count !== 1) {
            throw new ScopeRequiredException(
                'CLINIC_SCOPE_REQUIRED',
                'امکان تعیین Clinic فعال به‌صورت ضمنی وجود ندارد — تعداد Clinicهای نصب: '
                    . $count . '. Scope صریح لازم است (پارامتر درخواست، Membership کاربر، یا پیکربندی جریان).',
                ['clinic_count' => $count]
            );
        }

        $clinicId = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_clinics') . ' LIMIT 1'
        );

        return self::$cached = ClinicScope::forClinic($clinicId, null, ClinicScope::SOURCE_SYSTEM_SINGLE);
    }

    /**
     * باطل‌سازی کش (پایان درخواست / تست‌ها / تغییر دادهٔ Clinic).
     */
    public static function flush(): void
    {
        self::$cached = null;
    }
}

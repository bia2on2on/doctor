<?php

declare(strict_types=1);

namespace ClinicCore\Application\Scope;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * رزولوشن Location اصلی یک Clinic — Phase 2 (AD-15/P2-D2).
 *
 * هر Clinic حداقل یک Location دارد و دقیقاً یکی «اصلی» است
 * (`is_primary = 1`). این کلاس آن ردیف واقعی را برمی‌گرداند — مقدار همیشه
 * از DB می‌آید، نه از ثابت (AD-13).
 *
 * Fail-Closed: Clinic بدون Location اصلی = خطای صریح (نباید مسیر موازی
 * «بدون Location» ساخته شود — AD-15).
 */
final class PrimaryLocationResolver
{
    /** غیرقابل instantiate. */
    private function __construct()
    {
    }

    /**
     * شناسهٔ Location اصلی Clinic — یا null اگر هنوز هیچ Locationی نیست
     * (مثلاً پیش از Migration فاز ۲).
     */
    public static function tryResolve(CpmsDb $db, int $clinicId): ?int
    {
        $id = $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_locations') .
            ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id LIMIT 1',
            [$clinicId]
        );

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * Location اصلی Clinic — یا خطای صریح اگر وجود ندارد (Fail-Closed، AD-15).
     */
    public static function resolve(CpmsDb $db, int $clinicId): int
    {
        $id = self::tryResolve($db, $clinicId);
        if ($id === null) {
            throw new RuntimeException(
                'Clinic ' . $clinicId . ' هیچ Location اصلی ندارد — هر Clinic باید حداقل یک Location داشته باشد (AD-15).'
            );
        }

        return $id;
    }
}

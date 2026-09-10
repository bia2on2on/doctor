<?php

declare(strict_types=1);

namespace ClinicCore\Application\Scope;

/**
 * نگه‌دارندهٔ Scope صریحِ درخواست جاری — Phase 2 (ADR-0031).
 *
 * فقط یک مکانیزم: هر actorی که Scope را **می‌داند** (Middleware/Controller از
 * request، Job از payload، تست از fixture) آن را اینجا set می‌کند. اگر کسی
 * set نکرده باشد، Resolution به SystemClinicResolver سقوط می‌کند که در حالت
 * مبهم Fail-Closed خطا می‌دهد — هرگز «اولین Clinic» یا ثابت پیش‌فرض نه.
 *
 * عمداً per-request است (فرآیند PHP)، نه cache بین‌درخواستی.
 */
final class ScopeContext
{
    private static ?ClinicScope $explicit = null;

    /** غیرقابل instantiate — فقط holder ایستا. */
    private function __construct()
    {
    }

    public static function set(ClinicScope $scope): void
    {
        self::$explicit = $scope;
    }

    /**
     * Scope صریحِ درخواست — null یعنی «این درخواست Scope صریح ندارد».
     */
    public static function tryGet(): ?ClinicScope
    {
        return self::$explicit;
    }

    public static function clear(): void
    {
        self::$explicit = null;
    }
}

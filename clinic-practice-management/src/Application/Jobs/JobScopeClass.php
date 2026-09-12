<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/**
 * واژگانِ طبقهٔ scope یک نوع Job — Phase 2 (§A-3 سند کانونی).
 *
 * سه طبقه و **فقط** سه طبقه:
 *  - `T` tenant-scoped: اجرای Job به context یک Clinic معتبر نیاز دارد و آن را
 *    از منبع durable/authoritative خودش می‌گیرد (payload یا مالکیت ردیف).
 *  - `S` installation-scoped: واقعاً سطح نصب است (جدول/پیکربندی بدون بُعد
 *    tenant) و هیچ Clinic‌ای برایش معنا ندارد.
 *  - `W` installation-wide sweep: جاروی سراسری که **خودِ جارو** سطح نصب است
 *    ولی semantics هر ردیفِ حساسِ tenant از آبجکت مرجعِ همان ردیف مشتق می‌شود.
 *
 * ⛔ `NULL` هرگز به‌طور خودکار «system» نیست (§۳-B-2): اعتبارِ NULL فقط از
 * طبقهٔ **ثبت‌شدهٔ همان نوع** می‌آید — `JobScopeRegistry::permitsNullClinic()`.
 */
final class JobScopeClass
{
    /** Tenant-scoped — به Clinic معتبر و اعتبارسنجی‌شده نیاز دارد. */
    public const TENANT = 'T';

    /** Installation-scoped — واقعاً سطح نصب. */
    public const SYSTEM = 'S';

    /** Installation-wide sweep با semantics پر-ردیفِ tenant. */
    public const SWEEP = 'W';

    /** @var list<string> */
    public const ALL = [self::TENANT, self::SYSTEM, self::SWEEP];

    /** غیرقابل instantiate — فقط واژگان. */
    private function __construct()
    {
    }

    public static function isValid(string $class): bool
    {
        return in_array($class, self::ALL, true);
    }

    /**
     * اعتبارسنجی Fail-Closed — مقدار نامعتبر هرگز حدس زده نمی‌شود.
     *
     * @throws JobScopeUnknownException
     */
    public static function assertValid(string $class): string
    {
        if (!self::isValid($class)) {
            throw new JobScopeUnknownException(
                'JOB_SCOPE_CLASS_INVALID',
                'طبقهٔ scope نامعتبر: «' . $class . '». فقط T/S/W مجاز است.',
                ['scope_class' => $class]
            );
        }

        return $class;
    }
}

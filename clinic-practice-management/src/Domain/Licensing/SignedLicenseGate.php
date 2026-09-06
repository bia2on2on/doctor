<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * پیاده‌سازی واقعی F10 LicenseGate (ADR-0023) — بدون هیچ I/O شبکه.
 *
 * وضعیت از Provider محلی (کشِ امضاشده) خوانده می‌شود؛ refresh توسط Job
 * جداگانه انجام می‌شود (هرگز در مسیر درخواست).
 *
 * سیاست RESTRICTED (spec §16):
 *  - مسدود: فعالیت مستقلِ جدید (ساخت بیمار جدید، رزرو/جابه‌جایی نوبت،
 *    ورود ویزیتِ جدیدِ بدون نوبت/چک‌اینِ ویزیتِ جدید، صدور فاکتورِ جدید).
 *  - مجاز: لغو نوبت (بهداشت صف/بیمار)، به‌روزرسانی بیمار موجود، خواندن/
 *    تاریخچه/Export، و تمام گردش‌کارِ بالینیِ ویزیتِ در جریان (این مسیرها
 *    اصلاً assert نمی‌کنند — الگوی F4: transitions در Read-Only مجاز).
 *
 * این فایل در Domain است ولی به Provider تزئینی وابسته است؛ خود Gate خالص
 * (بدون WP/DB/شبکه) و با FakeProvider واحدتست می‌شود.
 */
final class SignedLicenseGate implements LicenseGate
{
    /**
     * عملیات‌هایی که در حالت محدود (RESTRICTED و بدتر) مسدود می‌شوند.
     */
    public const BLOCKED_UNDER_RESTRICTION = [
        LicenseGate::OP_PATIENT_CREATE,
        LicenseGate::OP_APPOINTMENT_BOOK,
        LicenseGate::OP_APPOINTMENT_RESCHEDULE,
        LicenseGate::OP_VISIT_CHECKIN,
        LicenseGate::OP_INVOICE_CREATE,
    ];

    public function __construct(private readonly LicenseStateProvider $provider)
    {
    }

    public function assert(string $operation, array $context = []): LicenseDecision
    {
        $state = $this->provider->currentState();
        $status = $state['status'];

        if (LicenseStatus::allowsNewBusiness($status)) {
            return LicenseDecision::allow();
        }
        if (!in_array($operation, self::BLOCKED_UNDER_RESTRICTION, true)) {
            // لغو/به‌روزرسانی/بهداشت/تکمیل — همیشه مجاز
            return LicenseDecision::allow();
        }

        $reason = match ($status) {
            LicenseStatus::GRACE => 'license:' . LicenseStatus::GRACE,
            LicenseStatus::RESTRICTED => 'license:' . LicenseStatus::RESTRICTED,
            LicenseStatus::SUSPENDED => 'license:' . LicenseStatus::SUSPENDED,
            LicenseStatus::REVOKED => 'license:' . LicenseStatus::REVOKED,
            LicenseStatus::INVALID => 'license:' . LicenseStatus::INVALID,
            default => 'license:unreachable',
        };

        return LicenseDecision::deny($reason);
    }

    public function state(): string
    {
        return $this->provider->currentState()['status'];
    }

    public function isReadOnly(): bool
    {
        return LicenseStatus::isRestricted($this->state());
    }
}

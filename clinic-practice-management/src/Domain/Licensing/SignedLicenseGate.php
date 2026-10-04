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
 * استثنای Phase 16 Slice 1 (جهت تجاری: واحد = Organization؛ پایه = Annual
 * License + Version Rights): «انقضای عادی تجاری» — یعنی صرفاً پایانِ سالانهٔ
 * مجوزِ معتبر — فعالیت بالینیِ جدید را مسدود نمی‌کند. این استثنا **علت‌محور**
 * و باریک است (status=RESTRICTED + reason=expired)؛ پایان پنجرهٔ فعال‌سازی،
 * vendor-unreachable/stale، suspension، revocation، سند نامعتبر و علتِ
 * ناشناخته/غایب همگی همان رفتار قبلی را دارند (fail-closed). نمایش وضعیت و
 * نیاز به تمدید (state/isReadOnly/statusMeta) هیچ تغییری نمی‌کند.
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

    /**
     * تنها `reason` به‌رسمیت‌شناخته‌شده برای «انقضای عادی تجاری» در وضعیت
     * RESTRICTED — همان خروجیِ `LicenseStateMachine::compute()` برای سندِ
     * verifiedِ منقضیِ خارج از expiry_grace.
     *
     * تطبیق **دقیق** است: همسایه‌های معنایی مثل `expired_unreachable`
     * (vendor-unreachable/stale) یا `activation_window_expired` /
     * `migration_grace_expired` (پایان پنجرهٔ فعال‌سازی) صریحاً بیرون از این
     * استثنا هستند و مسدود می‌مانند.
     */
    private const REASON_ORDINARY_EXPIRATION = 'expired';

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
        // Phase 16 Slice 1 — تنها علتِ به‌رسمیت‌شناخته‌شدهٔ «انقضای عادی تجاری»
        // (پایانِ صرفِ سالانهٔ مجوزِ معتبر): status=RESTRICTED با reason دقیقاً
        // 'expired'. fail-closed: نبودِ کلید reason، مقدارِ خالی/ناشناخته/
        // غیررشته‌ای، یا هر status دیگر ⇒ همان رفتارِ قبلیِ مسدود.
        $restrictedReason = $state['reason'] ?? null;
        if ( $status === LicenseStatus::RESTRICTED && $restrictedReason === self::REASON_ORDINARY_EXPIRATION ) {
            // فعالیت بالینیِ جدید آزاد؛ نمایش وضعیت/تمدید عمداً دست‌نخورده
            // می‌ماند (state/isReadOnly/statusMeta).
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

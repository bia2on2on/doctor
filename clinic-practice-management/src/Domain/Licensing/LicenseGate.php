<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * LicenseGate — Seam متمرکز بین Booking/Business Logic و Licensing (ADR-0023, C3).
 *
 * قواعد (تصمیم کارفرما F3):
 *  - Business Services فقط با همین Interface کار می‌کنند — **بدون coupling مستقیم
 *    به License Server یا جدول لایسنس**.
 *  - **Network Call مستقیم به License Server در مسیر Booking ممنوع است.**
 *    (Sync لایسنس در F10 توسط Job/Worker جداگانه انجام می‌شود و نتیجه را
 *    سرور-لokal نگه می‌دارد؛ این Gate فقط آن نتیجه را می‌خواند.)
 *  - Centralized: منطق License در Booking Service پراکنده نمی‌شود.
 *
 * پیاده‌سازی فعلی (F3): `ActiveLicenseGate` (همیشه ACTIVE — سیستم هنوز بدون
 * Licensing است). در F10 با Gate واقعی جایگزین می‌شود **بدون Refactor Booking**.
 */
interface LicenseGate
{
    // عملیات‌های Protected (کد — نه جمله). F10 مجموعه را کامل می‌کند.
    public const OP_PATIENT_CREATE = 'patient.create';
    public const OP_PATIENT_UPDATE = 'patient.update';
    public const OP_APPOINTMENT_BOOK = 'appointment.book';
    public const OP_APPOINTMENT_CANCEL = 'appointment.cancel';
    public const OP_APPOINTMENT_RESCHEDULE = 'appointment.reschedule';
    public const OP_VISIT_CHECKIN = 'visit.checkin';
    public const OP_INVOICE_CREATE = 'invoice.create';

    /**
     * بررسی مجوز عملیات. **باید** خالص و هم‌زمان باشد (بدون I/O شبکه).
     *
     * @param array<string, mixed> $context اطلاعات Log (بدون PHI در reason)
     */
    public function assert(string $operation, array $context = []): LicenseDecision;

    /**
     * وضعیت فعلی لایسنس (LicenseState::*) — برای Diagnostic/UI.
     */
    public function state(): string;

    /**
     * آیا سیستم Read-Only است؟ (Read/Export آزاد؛ فعالیت کسب‌وکار جدید مسدود).
     */
    public function isReadOnly(): bool;
}

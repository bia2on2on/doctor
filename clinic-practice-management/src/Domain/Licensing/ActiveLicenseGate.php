<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * پیاده‌سازی پیش‌فرض F3 — سیستم هنوز بدون Licensing است: همیشه ACTIVE.
 *
 * این کلاس خالص (Pure) است: بدون WP، بدون DB، بدون شبکه.
 * در F10 با Gate واقعی (خواندن وضعیت لایسنس همگام‌شده‌ی local) جایگزین می‌شود —
 * فقط `App::licenseGate()` تغییر می‌کند، نه Business Services.
 */
final class ActiveLicenseGate implements LicenseGate
{
    public function assert(string $operation, array $context = []): LicenseDecision
    {
        return LicenseDecision::allow();
    }

    public function state(): string
    {
        return LicenseState::ACTIVE;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}

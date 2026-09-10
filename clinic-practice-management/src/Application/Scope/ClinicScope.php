<?php

declare(strict_types=1);

namespace ClinicCore\Application\Scope;

/**
 * محدودهٔ فعالِ یک عملیات — Phase 2 (ADR-0031).
 *
 * Clinic الزاماً از یک منبع صریح می‌آید (ScopeContext صریح، Membership کاربر،
 * یا Resolution سیستمیِ «دقیقاً یک Clinic») — هرگز از ثابتِ پیش‌فرض
 * (`clinic_id = 1`) یا «اولین Clinic» (AD-13 / P2-B).
 *
 * Immutable Value Object: هر تغییر، نمونهٔ جدید می‌سازد.
 */
final class ClinicScope
{
    /** Scope به‌صورت صریح توسط caller تعیین شده (request param / job payload / تست). */
    public const SOURCE_EXPLICIT = 'explicit';

    /** Resolution سیستمی برای جریان‌های admin/background در نصبِ تک‌کلینیکی (دقیقاً یک Clinic). */
    public const SOURCE_SYSTEM_SINGLE = 'system-single';

    /**
     * @param int $clinicId Clinic فعال — همیشه از منبع واقعی، نه ثابت
     * @param int|null $organizationId Organization مالک Clinic (پس از Migration فاز ۲ پر می‌شود)
     * @param int|null $locationId Location مؤثر در صورت عملیات مکان‌محور
     * @param string $source منبع تعیین Scope (برای Audit/Debug)
     */
    private function __construct(
        public readonly int $clinicId,
        public readonly ?int $organizationId,
        public readonly ?int $locationId,
        public readonly string $source
    ) {
    }

    /**
     * نمونهٔ صریح (پیش‌فرض source=explicit — caller می‌گوید کدام Clinic).
     */
    public static function forClinic(int $clinicId, ?int $locationId = null, string $source = self::SOURCE_EXPLICIT): self
    {
        return new self($clinicId, null, $locationId, $source);
    }

    /**
     * نمونهٔ مشتق: همان Clinic، با Organization مشخص (پس از رزولوشن فاز ۲).
     */
    public function withOrganization(int $organizationId): self
    {
        return new self($this->clinicId, $organizationId, $this->locationId, $this->source);
    }

    /**
     * نمونهٔ مشتق: همان Clinic، با Location مؤثر مشخص.
     */
    public function withLocation(int $locationId): self
    {
        return new self($this->clinicId, $this->organizationId, $locationId, $this->source);
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Update;

/**
 * Phase 16 Slice 6B — مرزِ امضاشدهٔ «حقوقِ نسخه» در صفحهٔ به‌روزرسانی (ADR-0029 §4).
 *
 * تنها ورودی‌های تصمیم: عددِ صحیحِ `update_rights_until` از سندِ مجوزِ
 * تأییدشدهٔ ذخیره‌شده، و `signed_at`ِ عددِ صحیحِ مانیفستِ امضاشدهٔ انتشار.
 * هیچ ساعتِ محلی (time/DB/تاریخِ فایل) تصمیم نمی‌گیرد؛ مقایسه فقط عددِ امضاشده است.
 *
 * - ادعای غایب/نامعتبر ⇒ «بدون‌مرز»: رفتارِ فعلیِ به‌روزرسانی دست‌نخورده (سازگاری عقب‌رو).
 * - انتشارِ عادی با `signed_at` بزرگ‌تر از مرز ⇒ `update_rights_expired`.
 * - انتشارِ `release_kind=security` (داخلِ payloadِ امضاشده) از مرز عبور می‌کند —
 *   فقط و فقط در همین تصمیم. هرگز امضای نامعتبر، ساختارِ خراب، channel،
 *   applicability یا entitlementِ پایهٔ `updates` را جبران نمی‌کند (ترتیبِ اعتماد
 *   در `UpdateService`: امضا → ساختار → channel → applicability → entitlement → مرز).
 * - مرزِ مشخص با `signed_at`ِ غیرِقابل‌استفاده ⇒ fail-closed با همان دلیلِ
 *   ساختاریِ موجود (`invalid_manifest`).
 *
 * خالص است: بدون WP/DB/شبکه/زمان — و هیچ‌گاه ذخیره‌سازی/ابردادهٔ تازه نمی‌سازد.
 */
final class UpdateRightsBoundary {

    /**
     * Manifest unusable for a bounded decision (structure or signed_at) — same bounded
     * code the structural check in `UpdateService::evaluateManifest()` returns.
     */
    public const REASON_INVALID_MANIFEST = 'invalid_manifest';

    /**
     * Ordinary release published after the signed `update_rights_until` boundary.
     */
    public const REASON_RIGHTS_EXPIRED = 'update_rights_expired';

    private function __construct( private readonly ?int $until ) {
    }

    /**
     * بدونِ مرز — سندِ بدونِ ادعا/legacy/نامعتبر.
     */
    public static function unbounded(): self {
        return new self( null );
    }

    /**
     * ادعای اختیاریِ v2: فقط عددِ صحیحِ مثبت مرز می‌سازد؛ هر چیزِ دیگر «بدون‌مرز»
     * است (fail-open عمدی برای سازگاری، چون سندِ نامعتبرِ حاویِ ادعا هرگز ذخیره
     * نمی‌شود: `LicenseSignature` آن را رد می‌کند).
     */
    public static function from_claim( mixed $claim ): self {
        return is_int( $claim ) && $claim > 0 ? new self( $claim ) : new self( null );
    }

    /**
     * اثرِ مرز در کلیدِ کشِ تصمیم — نتیجهٔ کش‌شده متعلق به یک مرزِ مشخص است تا
     * تصمیمِ مسدودِ قدیمی پس از تمدید باقی نماند؛ فقط مکانیزمِ transientِ موجود،
     * بدون migration/جدول/ستون/reconciliation.
     */
    public function fingerprint(): string {
        return $this->until === null ? 'none' : 'until-' . $this->until;
    }

    /**
     * آیا این مانیفستِ امضاشده برای این مرز قابلِ عرضه است؟
     *
     * ورودی: مانیفستی که امضا/ساختار/channel/applicability آن قبلاً تأیید شده است.
     *
     * @param array<string, mixed> $manifest
     *
     * @return string|null null = مجاز؛ در غیر این صورت دلیلِ محدود
     */
    public function denial_reason( array $manifest ): ?string {
        if ( $this->until === null ) {
            return null;
        }
        $signed_at = ReleaseManifest::signed_at( $manifest );
        if ( $signed_at === null ) {
            return self::REASON_INVALID_MANIFEST;
        }
        if ( $signed_at <= $this->until ) {
            return null;
        }
        // استثنای امنیتی فقط از داخلِ payloadِ امضاشده خوانده می‌شود؛ نامِ ناشناخته
        // (مثلاً `SECURITY`) هرگز security نیست ⇒ مسدود می‌ماند (fail-closed).
        if ( ReleaseManifest::is_security_release( $manifest ) ) {
            return null;
        }

        return self::REASON_RIGHTS_EXPIRED;
    }
}

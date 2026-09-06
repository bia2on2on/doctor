<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

use RuntimeException;

/**
 * ماشین محاسبه‌ی وضعیت محلی مجوز (خالص — ADR-0023 §3).
 *
 * ورودی: سند امضاشده‌ی محلیِ آخر (در صورت وجود) + «اعتبار» آن
 * (verified | signature_invalid | unreachable) + policy + now.
 * خروجی: وضعیت قطعی + reason + expiresAt + needsRenewal (+ renewal_in_sec).
 *
 * پیشروی (spec §16): ACTIVE → EXPIRING → GRACE → RESTRICTED
 *   ACTIVE    : معتبر، دور از انقضا
 *   EXPIRING  : کمتر از expiry_grace تا انقضا — هشدار، کسب‌وکار جدید مجاز
 *   GRACE     : منقضی ولی داخل expiry_grace — هشدار برجسته، تمدید لازم،
 *               تکمیل گردش‌کار جاری/تاریخچه/Export مجاز
 *   RESTRICTED: خارج از expiry_grace — فعالیت مستقل جدید مسدود؛ تاریخچه/
 *               Export/تکمیل ویزیت جاری مجاز (قواعد LicenseGate)
 *
 * قواعد (spec §15):
 *   - قطع شبکه ≠ invalid. مادامی که آخرین سند معتبرِ کش‌شده داخل پنجره‌ی
 *     unreachable_grace باشد، وضعیت از روی همان سند ادامه می‌یابد.
 *   - REVOKED/SUSPENDED فقط از سندِ معتبرِ امضاشده می‌آیند — هرگز از قطع شبکه.
 *   - شکست امضا = INVALID (جدا از UNREACHABLE).
 *   - Server clock هرگز مبنای زمان پزشکی نیست؛ فقط انقضای مجوز (wall-clock
 *     محلی با تلورانس skew).
 */
final class LicenseStateMachine
{
    public const VERIFIED = 'verified';
    public const SIGNATURE_INVALID = 'signature_invalid';
    public const UNREACHABLE = 'unreachable';

    /**
     * @param array{issued_at?:int, expires_at?:int, revoked?:bool, suspended?:bool, reason?:string}|null $doc
     *
     * @return array{status:string, reason:string, expires_at:int|null, needs_renewal:bool, renewal_in_sec:int|null}
     */
    public static function compute(?array $doc, string $verdict, int $nowTs, LicensePolicy $policy = new LicensePolicy()): array
    {
        $graceSec = $policy->expiryGraceSeconds();
        $unreachableSec = $policy->unreachableGraceSeconds();

        // ---------- امضای نامعتبر ----------
        if ($verdict === self::SIGNATURE_INVALID) {
            return self::outcome(LicenseStatus::INVALID, 'signature_invalid', null, false);
        }

        $expiresAt = isset($doc['expires_at']) ? (int) $doc['expires_at'] : 0;

        // ---------- هیچ سند معتبر قبلی نداریم ----------
        if ($doc === null || $expiresAt <= 0) {
            if ($verdict === self::VERIFIED) {
                return self::outcome(LicenseStatus::INVALID, 'missing_expiry', null, true);
            }

            return self::outcome(LicenseStatus::UNREACHABLE, 'no_cached_state', null, true);
        }

        $revoked = isset($doc['revoked']) && (bool) $doc['revoked'];
        $suspended = isset($doc['suspended']) && (bool) $doc['suspended'];

        // ---------- قطع شبکه: فقط تا پایان پنجره‌ی unreachable_grace از
        // کشِ معتبر ادامه می‌دهیم؛ بعد از آن UNREACHABLE صریح ----------
        if ($verdict === self::UNREACHABLE && $nowTs > $expiresAt + $unreachableSec) {
            return self::outcome(LicenseStatus::UNREACHABLE, 'cached_state_stale', $expiresAt, true);
        }

        // ---------- سند معتبر (verified، یا unreachable ولی داخل پنجره) ----------
        if ($revoked) {
            return self::outcome(LicenseStatus::REVOKED, (string) ($doc['reason'] ?? 'revoked'), $expiresAt, false);
        }
        if ($suspended) {
            return self::outcome(LicenseStatus::SUSPENDED, (string) ($doc['reason'] ?? 'suspended'), $expiresAt, false);
        }

        if ($nowTs > $expiresAt + $graceSec + $policy->maxClockSkewSeconds) {
            return self::outcome(LicenseStatus::RESTRICTED, $verdict === self::UNREACHABLE ? 'expired_unreachable' : 'expired', $expiresAt, true);
        }
        if ($nowTs > $expiresAt + $policy->maxClockSkewSeconds) {
            // داخل expiry_grace
            return self::outcome(LicenseStatus::GRACE, $verdict === self::UNREACHABLE ? 'unreachable_grace' : 'grace', $expiresAt, true);
        }

        $remaining = $expiresAt - $nowTs;
        if ($remaining <= $graceSec) {
            // کمتر از expiry_grace تا انقضا — EXPIRING (هشدار)
            return self::outcome(LicenseStatus::EXPIRING, 'near_expiry', $expiresAt, true);
        }

        return self::outcome(LicenseStatus::ACTIVE, '', $expiresAt, $remaining <= $policy->renewIntervalSeconds());
    }

    /**
     * @return array{status:string, reason:string, expires_at:int|null, needs_renewal:bool, renewal_in_sec:int|null}
     */
    private static function outcome(string $status, string $reason, ?int $expiresAt, bool $needsRenewal): array
    {
        if (!in_array($status, LicenseStatus::VALID, true)) {
            throw new RuntimeException('Invalid license status: ' . $status);
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'needs_renewal' => $needsRenewal,
            'renewal_in_sec' => null,
        ];
    }
}

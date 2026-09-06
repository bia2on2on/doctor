<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Update;

/**
 * کلید عمومی تأیید امضای مانیفست‌های انتشار (ADR-0029 §1) — جدا از کلید
 * مجوز (ADR-0023) تا مصالحه‌ی یکی، دیگری را جعل نکند (spec §41).
 *
 * مقدار PRODUCTION اینجا Placeholder نامعتبر است (fail-closed)؛ جایگزینی =
 * گام Release. Filter `cpms_release_public_key` برای تست/استیجینگ/چرخش.
 */
final class ReleaseKeys
{
    public const PRODUCTION_PUBLIC_B64 = 'REPLACE_AT_RELEASE_WITH_RELEASE_ED25519_PUBLIC_B64';

    public static function publicKey(): string
    {
        if (function_exists('apply_filters')) {
            $filtered = (string) apply_filters('cpms_release_public_key', self::PRODUCTION_PUBLIC_B64);
            if ($filtered !== '') {
                return $filtered;
            }
        }

        return self::PRODUCTION_PUBLIC_B64;
    }
}

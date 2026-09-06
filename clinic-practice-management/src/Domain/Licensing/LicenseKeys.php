<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * کلید عمومی تأیید امضای اسناد مجوز (ADR-0023 §2 / spec §19/§32).
 *
 * - کلید خصوصی هرگز در افزونه نیست (فقط زیرساخت انتشار فروشنده).
 * - مقدار PRODUCTION اینجا عمداً **Placeholder نامعتبر** است: تا وقتی
 *   کلید رسمی انتشار جایگزین نشود، هیچ سندی فعال نمی‌شود (fail-closed).
 *   جایگزینی = گام Release (مستند در docs/release) — هرگز کلید آزمایشی
 *   واقعی در افزونه‌ی عمومی شipped نمی‌شود.
 * - Filter `cpms_license_public_key` برای تست/استیجینگ (کلید تستی) و
 *   چرخش کلید (rotation) در دسترس است.
 */
final class LicenseKeys
{
    /**
     * Placeholder — base64 نامعتبر عمدی. جایگزین با کلید رسمی در Release.
     */
    public const PRODUCTION_PUBLIC_B64 = 'REPLACE_AT_RELEASE_WITH_ED25519_PUBLIC_B64';

    public static function publicKey(): string
    {
        $key = self::PRODUCTION_PUBLIC_B64;
        if (function_exists('apply_filters')) {
            $filtered = (string) apply_filters('cpms_license_public_key', $key);
            if ($filtered !== '') {
                return $filtered;
            }
        }

        return $key;
    }
}

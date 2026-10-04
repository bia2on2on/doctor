<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * Phase 16 Slice 2 — تنها seamِ canonicalizationِ دامنه.
 *
 * همان قاعده برای هر دو طرفِ مقایسه به‌کار می‌رود:
 *  - دامنهٔ محلیِ مشتق از `home_url()` (سمتِ سایت)،
 *  - ادعای `domain` در سندِ امضاشده (سمتِ vendor).
 *
 * قاعده: lower-case + حذفِ `www.` پیشرو. بدونِ سیاستِ IDN/punycode/path/port
 * و بدونِ هیچ اتکایی به HTTP_HOST/request-host.
 *
 * مقدارِ غیرقابلِ استفاده (غیررشته) ⇒ '' ؛ در مقایسهٔ سند این حالت fail-closed
 * است و «unbound» محسوب نمی‌شود.
 */
final class LicenseDomain {
    /**
     * @param mixed $value ادعای دامنه یا hostِ مشتق از home_url().
     */
    public static function canonicalize( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $stripped = preg_replace( '/^www\./', '', strtolower( $value ) );

        return $stripped ?? '';
    }
}

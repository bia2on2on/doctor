<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Db;

/**
 * دروازهٔ **واحدِ** سازگاریِ سرورِ دیتابیس — تنها منبعِ حقیقت.
 *
 * چرا این کلاس وجود دارد (نقصِ پذیرش — D):
 *  - مهاجرت `0027` فقط `MariaDB 10.11.x` و `MariaDB 11.4.13` را می‌پذیرفت و
 *    هر چیز دیگر را **fail-closed** رد می‌کرد (فعال‌سازی/Migration شکست
 *    می‌خورد).
 *  - اما `SystemHealthService` همان نسخه‌های رد‌شده را `WARNING`
 *    («پشتیبانی‌شده با هشدار») گزارش می‌کرد و مدرکِ نامشخصِ MariaDB را با یک
 *    استثنای پنهان حتی از فهرستِ مسدودکننده‌ها بیرون می‌برد.
 *
 * نتیجه: صفحهٔ سلامت می‌گفت «آماده/نیازمند تنظیم» در حالی که دروازهٔ واقعی
 * همان نسخه را رد می‌کرد. پیش از این دو regex در دو جای مختلف تکرار می‌شدند و
 * هیچ تستی نمی‌توانست هم‌خوانیِ آن دو را ثابت کند.
 *
 * اکنون **هر دو مسیر** (مهاجرت و سلامت) از همین کلاس استفاده می‌کنند؛ پس
 * هم‌خوانیِ آن‌ها ساختاری است، نه ادعایی. مجموعهٔ مجاز عمداً گسترده نشده است:
 * هیچ نسخهٔ آزموده‌نشده‌ای برای «سبزکردنِ هشدار» پذیرفته نمی‌شود.
 */
final class DatabaseCompatibilityGate {

    /**
     * نسخه‌های MariaDB که واقعاً پشتیبانی می‌شوند:
     * سریِ `10.11.x` (مرزِ مستند و آزمودهٔ هفتگی) و `11.4.13` (نسخهٔ آزمودهٔ CI).
     */
    public const MARIADB_VERIFIED_PATTERN = '/\A(?:10\.11\.\d+|11\.4\.13)(?:-MariaDB)?(?:[-+][a-zA-Z0-9._-]+)?\z/i';

    /** MySQL 8.x — همان کفِ اعلام‌شدهٔ محصول. */
    public const MYSQL_VERIFIED_PATTERN = '/\A8\.\d+\.\d+(?:[-+][a-zA-Z0-9._-]+)?\z/';

    public const ENGINE_MARIADB = 'mariadb';
    public const ENGINE_MYSQL   = 'mysql';

    /**
     * موتورِ سرور بر پایهٔ شواهدِ `VERSION()`/`@@version_comment`.
     *
     * `null` یعنی شواهد برای تصمیم کافی نیست (نه MySQL، نه MariaDBِ تأییدشده).
     * ترتیب مهم است: MariaDB اول سنجیده می‌شود، چون رشتهٔ نسخهٔ آن می‌تواند
     * شبیه به MySQL باشد.
     */
    public static function engine( string $version, string $comment ): ?string {
        $version       = self::normalize( $version );
        $is_maria_hint = false !== stripos( $version, 'MariaDB' ) || false !== stripos( $comment, 'MariaDB' );
        if ( $is_maria_hint && 1 === preg_match( self::MARIADB_VERIFIED_PATTERN, $version ) ) {
            return self::ENGINE_MARIADB;
        }

        if ( false !== stripos( $comment, 'MySQL' ) && 1 === preg_match( self::MYSQL_VERIFIED_PATTERN, $version ) ) {
            return self::ENGINE_MYSQL;
        }

        return null;
    }

    /**
     * آیا این رشتهٔ نسخهٔ MariaDB در مجموعهٔ **آزموده/مجاز** است؟
     */
    public static function accepts_mariadb_version( string $version ): bool {
        return 1 === preg_match( self::MARIADB_VERIFIED_PATTERN, self::normalize( $version ) );
    }

    /**
     * حذفِ پیشوندِ سازگاریِ کلاینت.
     *
     * MariaDB (و libmysqlclientِ قدیمی) گاهی `5.5.5-` را پیش از نسخهٔ واقعیِ
     * سرور برمی‌گرداند؛ بدونِ این نرمال‌سازی، یک سرورِ سالمِ `10.11.2` به شکلِ
     * `5.5.5-10.11.2-MariaDB-log` گزارش می‌شود و دروازه آن را (به‌اشتباه)
     * fail-closed رد می‌کند — یعنی فعال‌سازی روی سرورِ مجاز شکست می‌خورد، در
     * حالی که گزارشِ سلامت همان نسخه را درست تفسیر می‌کرد.
     */
    private static function normalize( string $version ): string {
        $version = trim( $version );

        if ( 1 === preg_match( '/\A5\.5\.5-(.+)\z/', $version, $matches ) ) {
            return trim( (string) $matches[1] );
        }

        return $version;
    }
}

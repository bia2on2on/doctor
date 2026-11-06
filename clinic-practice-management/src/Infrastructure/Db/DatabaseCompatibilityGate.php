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

    /**
     * عبارتِ واحدِ «مجموعهٔ پذیرفته‌شده» — عیناً همان واژگانی که دروازهٔ
     * مهاجرت 0027 در پیامِ ردِ خود به کار می‌برد. پیش‌بررسیِ فعال‌سازی
     * (N1) باید همان مجموعه را با همین واژگان اعلام کند تا اپراتور دو
     * روایتِ متفاوت از یک سیاست نخواند. تستِ هم‌خوانی، متنِ فایل مهاجرت را
     * با همین ثابت می‌سنجد؛ تغییرِ هر یک بدونِ دیگری، تست را می‌شکند.
     */
    public const ACCEPTED_SET_WORDING = 'expected MySQL 8, MariaDB 10.11.x, or MariaDB 11.4.13';

    /** موتورِ شناسایی‌شده وقتی شواهد نه MySQL و نه MariaDB را نشان می‌دهد. */
    public const ENGINE_UNKNOWN = 'unknown';

    public const ENGINE_MARIADB = 'mariadb';
    public const ENGINE_MYSQL   = 'mysql';

    /** شواهدِ نسخه در زمانِ اجرا تأیید می‌کند سرور در مجموعهٔ مجاز است. */
    public const RUNTIME_EVIDENCE_SUPPORTED = 'supported';

    /** شواهدِ نسخه خواناست و موتور/نسخه خارج از مجموعهٔ مجاز است. */
    public const RUNTIME_EVIDENCE_UNSUPPORTED = 'positively_unsupported';

    /** شواهدِ نسخه غایب، خالی یا مخدوش است (در فعال‌سازی fail-closed؛ در زمانِ اجرا بدون مسدودسازی). */
    public const RUNTIME_EVIDENCE_UNREADABLE = 'unreadable';

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
     * پیش‌بررسیِ ساختاریافتهٔ فعال‌سازی (N1) — همان تصمیمِ دروازه، به‌همراهِ
     * شواهدِ قابلِ گزارش.
     *
     * تصمیمِ `supported` عیناً از {@see engine()} می‌آید — یعنی همان منطقِ
     * دفاع‌عمقیِ مهاجرت 0027 — پس این متد **هرگز** نمی‌تواند مجموعهٔ مجاز را
     * بازتر یا بسته‌تر از دروازهٔ واقعی اعلام کند. فیلدهای `engine`/`version`
     * فقط گزارشِ شواهدِ شناسایی‌شده‌اند (مثلاً MariaDB 11.8 با موتورِ
     * `mariadb` و پرچمِ `supported=false`) و هیچ اثری روی تصمیم ندارند.
     *
     * @param string $version رشتهٔ خامِ `VERSION()` — همان شواهدی که مهاجرت 0027 می‌خواند.
     * @param string $comment رشتهٔ خامِ `@@version_comment`.
     *
     * @return array{supported: bool, engine: string, version: string, reason: string, runtime_evidence: string}
     */
    public static function check( string $version, string $comment ): array {
        $engine           = self::engine( $version, $comment );
        $runtime_evidence = self::classify_runtime_evidence( $version, $comment );

        if ( $engine !== null ) {
            return [
                'supported'        => true,
                'engine'           => $engine,
                'version'          => trim( $version ),
                'reason'           => 'verified database server',
                'runtime_evidence' => $runtime_evidence,
            ];
        }

        return [
            'supported'        => false,
            'engine'           => self::detected_engine_family( $version, $comment ),
            'version'          => trim( $version ),
            'reason'           => 'unsupported database server; ' . self::ACCEPTED_SET_WORDING,
            'runtime_evidence' => $runtime_evidence,
        ];
    }

    /**
     * تفکیکِ شواهدِ زمانِ اجرا برای سایتِ از-پیش-فعال:
     *  - `supported`: دقیقاً همان مجموعهٔ مجازِ {@see engine()}؛
     *  - `positively_unsupported`: خانوادهٔ MariaDB/MySQL با نسخهٔ عددیِ خوانا خارج از
     *    مجموعهٔ مجاز، یا موتورِ غیرِ MySQL/MariaDB که صریحاً خودش را معرفی کرده است؛
     *  - `unreadable`: شواهدِ خالی، ناقص یا مخدوش (در فعال‌سازی همچنان fail-closed
     *    رد می‌شود، ولی سایتِ فعال را صرفاً به‌خاطرِ مخدوش‌بودنِ رشته قفل نمی‌کند).
     */
    public static function classify_runtime_evidence( string $version, string $comment ): string {
        if ( null !== self::engine( $version, $comment ) ) {
            return self::RUNTIME_EVIDENCE_SUPPORTED;
        }

        $normalized = self::normalize( $version );
        if ( '' === $normalized ) {
            return self::RUNTIME_EVIDENCE_UNREADABLE;
        }

        $is_maria = false !== stripos( $normalized, 'MariaDB' ) || false !== stripos( $comment, 'MariaDB' );
        if ( $is_maria ) {
            return 1 === preg_match( '/\A\d+\.\d+\.\d+/', $normalized )
                ? self::RUNTIME_EVIDENCE_UNSUPPORTED
                : self::RUNTIME_EVIDENCE_UNREADABLE;
        }

        if ( false !== stripos( $comment, 'MySQL' ) ) {
            return 1 === preg_match( '/\A\d+\.\d+\.\d+/', $normalized )
                ? self::RUNTIME_EVIDENCE_UNSUPPORTED
                : self::RUNTIME_EVIDENCE_UNREADABLE;
        }

        if ( 1 === preg_match( '/\b(?:SQLite|PostgreSQL|CockroachDB|DuckDB)\b/i', $normalized . ' ' . $comment ) ) {
            return self::RUNTIME_EVIDENCE_UNSUPPORTED;
        }

        return self::RUNTIME_EVIDENCE_UNREADABLE;
    }

    /**
     * نتیجهٔ ساختاریافته برای وقتی که اصلاً نمی‌توان شواهدِ سرور را خواند
     * (کوئری ناموفق یا فیلدهای غیررشته‌ای) — همان موضعِ fail-closed مهاجرت
     * 0027: «هویت در دسترس نیست؛ از تغییرِ schema خودداری می‌شود».
     *
     * @return array{supported: bool, engine: string, version: string, reason: string, runtime_evidence: string}
     */
    public static function unavailable_evidence(): array {
        return [
            'supported'        => false,
            'engine'           => self::ENGINE_UNKNOWN,
            'version'          => '',
            'reason'           => 'database server identity is unavailable; refusing schema changes',
            'runtime_evidence' => self::RUNTIME_EVIDENCE_UNREADABLE,
        ];
    }

    /**
     * متنِ قابل‌خواندنِ ردِ فعال‌سازی (N1) — نسخهٔ plain؛ لایهٔ WordPress
     * (App) آن را escape و با back link نمایش می‌دهد.
     *
     * محتوا: موتور/نسخهٔ شناسایی‌شده + مجموعهٔ پذیرفته‌شده با واژگانِ عینِ
     * مهاجرت 0027 + عبارتِ صریحِ «هیچ‌چیز نصب نشده» تا اپراتور بداند باقی‌مانده‌ای
     * روی سایت نیست.
     *
     * @param array<string, mixed> $result خروجیِ check() یا unavailable_evidence().
     */
    public static function activation_refusal_message( array $result ): string {
        $engine  = is_string( $result['engine'] ?? null ) ? $result['engine'] : self::ENGINE_UNKNOWN;
        $version = is_string( $result['version'] ?? null ) ? $result['version'] : '';

        if ( $version === '' ) {
            $detected = 'Detected database server: ' . $engine . ' (version string unavailable — '
                . 'database server identity is unavailable; refusing schema changes)';
        } else {
            $detected = 'Detected database server: ' . $version . ' (engine: ' . $engine . ')';
        }

        return "CPMS cannot be activated on this database server.\n\n"
            . $detected . "\n"
            . 'This plugin requires a supported database server: ' . self::ACCEPTED_SET_WORDING . ".\n\n"
            . 'Activation was stopped before anything was installed: no CPMS roles, database tables, '
            . 'options or scheduled events have been created. Nothing was written.';
    }

    /**
     * خانوادهٔ موتورِ «گزارش‌شده» از روی شواهد — بدونِ هیچ اثری روی تصمیمِ
     * مجاز/رد. برای پیامِ رد لازم است تا اپراتور بداند سرورش چی بوده
     * (مثلاً MariaDB 11.8) حتی وقتی رد شده است.
     */
    private static function detected_engine_family( string $version, string $comment ): string {
        $version = self::normalize( $version );

        if ( false !== stripos( $version, 'MariaDB' ) || false !== stripos( $comment, 'MariaDB' ) ) {
            return self::ENGINE_MARIADB;
        }

        if ( false !== stripos( $comment, 'MySQL' ) ) {
            return self::ENGINE_MYSQL;
        }

        return self::ENGINE_UNKNOWN;
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

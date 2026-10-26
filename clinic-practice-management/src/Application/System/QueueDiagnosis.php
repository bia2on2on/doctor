<?php

declare( strict_types=1 );

namespace ClinicCore\Application\System;

/**
 * CPMS Classic Admin UX (E) — تشخیصِ صادقانهٔ وضعیتِ Queue/WP-Cron.
 *
 * این کلاس **خالص** است (بدون WP/DB/Clock): تنها شواهدِ جمع‌آوری‌شده را
 * دسته‌بندی می‌کند، پس بدون WordPress هم واحدتست می‌شود. جمع‌آوریِ شواهد در
 * `App::queue_diagnosis()` انجام می‌شود.
 *
 * ریشهٔ نقص‌های پیشین که اینجا اصلاح می‌شود:
 *  ۱. «تازه نصب‌شده» و «متوقف‌شده» یکسان گزارش می‌شدند؛ در نصبِ تازه heartbeat
 *     صفر است و صفر به‌صورتِ خودکار «stale/critical» تفسیر می‌شد.
 *  ۲. نبودِ رویدادِ WP-Cron (`wp_next_scheduled() === false`) اصلاً تفکیک
 *     نمی‌شد؛ همان وضعیتِ «متوقف» گزارش می‌شد.
 *  ۳. WP-Cron سالمِ متکی به ترافیک به‌عنوانِ «خطای بحرانیِ پیکربندی» برچسب
 *     می‌خورد.
 *  ۴. Jobهای failed در همان سبدِ «توقف» ریخته می‌شدند.
 *
 * WP-Cron بدون زمان‌بندِ خارجی فقط با ترافیکِ سایت اجرا می‌شود؛ این یک محدودیتِ
 * واقعیِ میزبان است، نه نقصِ پیکربندی. بنابراین وضعیتِ سالم با توضیحِ صریحِ این
 * وابستگی گزارش می‌شود و به‌عنوانِ خطا برچسب نمی‌خورد.
 */
final class QueueDiagnosis {
    /** سالم — heartbeat تازه و رویداد زمان‌بندی موجود است. */
    public const STATE_HEALTHY = 'healthy';

    /** نصبِ تازه: هنوز هیچ اجرایی ثبت نشده و کارِ در انتظاری هم نیست. */
    public const STATE_NEVER_RUN = 'never_run';

    /** رویدادِ زمان‌بندیِ CPMS در WP-Cron ثبت نشده است. */
    public const STATE_MISSING_SCHEDULE = 'missing_schedule';

    /** heartbeat ثبت شده اما از پنجرهٔ مجاز کهنه‌تر است (اجرا متوقف شده). */
    public const STATE_STALE = 'stale';

    /** صف اجرا می‌شود، اما Jobهای شکست‌خورده‌ای وجود دارند. */
    public const STATE_FAILED = 'failed';

    /** شواهدِ کافی برای هیچ‌یک از حالت‌ها وجود ندارد. */
    public const STATE_UNKNOWN = 'unknown';

    public const SEVERITY_OK      = 'ok';
    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_PROBLEM = 'problem';

    /**
     * دسته‌بندیِ کامل بر پایهٔ شواهد.
     *
     * ورودی (همه اجباری‌اند تا هیچ «شاهدِ فرضی» ساخته نشود):
     *  - `last_tick_at`            heartbeat مؤثر (0 = هرگز)
     *  - `heartbeat_source`        `installation` | `legacy_clinic` | `none`
     *  - `now`                     زمان جاری (تزریق‌پذیر برای تست)
     *  - `stale_after_sec`         پنجرهٔ مجاز
     *  - `failed`                  تعداد Jobهای failed
     *  - `queued`                  تعداد Jobهای queued
     *  - `has_activity`            آیا تا کنون Jobی ساخته شده است
     *  - `schedule_scheduled`      نتیجهٔ wp_next_scheduled(); null = قابل‌سنجش نیست
     *  - `next_scheduled_at`       timestampِ اجرای بعدی یا null
     *  - `wp_cron_disabled`        DISABLE_WP_CRON فعال است؟
     *
     * @param array<string, mixed> $evidence
     *
     * @return array{
     *     state:string,
     *     severity:string,
     *     status:string,
     *     label:string,
     *     detail:string,
     *     guidance:string,
     *     repairable:bool
     * }
     */
    public static function classify( array $evidence ): array {
        $last_tick_at = (int) ( $evidence['last_tick_at'] ?? 0 );
        $now          = (int) ( $evidence['now'] ?? 0 );
        $stale_after  = (int) ( $evidence['stale_after_sec'] ?? 300 );
        $failed       = (int) ( $evidence['failed'] ?? 0 );
        $queued       = (int) ( $evidence['queued'] ?? 0 );
        $has_activity = (bool) ( $evidence['has_activity'] ?? false );
        $scheduled    = $evidence['schedule_scheduled'] ?? null;
        $next_run     = $evidence['next_scheduled_at'] ?? null;
        $wp_cron_off  = (bool) ( $evidence['wp_cron_disabled'] ?? false );
        $source       = (string) ( $evidence['heartbeat_source'] ?? 'none' );

        $age      = $last_tick_at > 0 && $now > 0 ? max( 0, $now - $last_tick_at ) : null;
        $is_stale = null !== $age && $stale_after > 0 && $age > $stale_after;

        $scheduler_note = self::scheduler_note( $wp_cron_off, $scheduled, $next_run );

        // ۱. رویدادِ زمان‌بندی غایب — تنها حالتی که با یک اقدامِ ایمن و بدونِ
        //    اجرای فوریِ صف قابلِ ترمیم است.
        if ( false === $scheduled ) {
            return self::result(
                self::STATE_MISSING_SCHEDULE,
                self::SEVERITY_PROBLEM,
                SystemHealthService::FAIL,
                'زمان‌بندیِ WP-Cron برای صف ثبت نشده است',
                'رویدادِ `cpms_jobs_tick` در WP-Cron وجود ندارد؛ صف تا زمانِ ترمیم اجرا نمی‌شود.'
                    . ' | heartbeat: ' . self::heartbeat_text( $last_tick_at, $age, $source )
                    . ' | failed=' . $failed . ' | queued=' . $queued,
                'از دکمهٔ «ترمیم زمان‌بندی صف» در همین صفحه استفاده کنید (امن، idempotent، بدونِ اجرای فوریِ Jobها). '
                    . 'اگر پس از ترمیم تکرار شد، WP-Cron روی این میزبان غیرفعال است؛ طبق ADR-0016 یک System Cron با `DISABLE_WP_CRON` تنظیم کنید.',
                true
            );
        }

        // ۲. اجرا متوقف شده است (heartbeat دارد ولی کهنه).
        if ( $is_stale ) {
            $pending = $queued > 0 || $has_activity;
            return self::result(
                self::STATE_STALE,
                $pending ? self::SEVERITY_PROBLEM : self::SEVERITY_WARNING,
                $pending ? SystemHealthService::FAIL : SystemHealthService::WARNING,
                'آخرین اجرای صف کهنه است',
                'آخرین tick: ' . self::heartbeat_text( $last_tick_at, $age, $source )
                    . ' (پنجرهٔ مجاز: ' . self::duration_text( $stale_after ) . ')'
                    . ' | failed=' . $failed . ' | queued=' . $queued
                    . ' | ' . $scheduler_note,
                'اگر با System Cron کار می‌کنید، اجرای `bin/cpms jobs tick` را در زمان‌بندِ میزبان بررسی کنید. '
                    . 'اگر فقط WP-Cron دارید، بدونِ ترافیکِ سایت هیچ اجرایی رخ نمی‌دهد؛ یک بازدید از پیشخوان معمولاً آن را راه می‌اندازد. '
                    . 'این صفحه خودش صف را اجرا نمی‌کند.',
                false
            );
        }

        // ۳. همه‌چیز تازه است، اما Job شکست‌خورده وجود دارد.
        if ( $failed > 0 ) {
            return self::result(
                self::STATE_FAILED,
                self::SEVERITY_WARNING,
                SystemHealthService::WARNING,
                'Jobهای شکست‌خورده در صف',
                'failed=' . $failed . ' | queued=' . $queued
                    . ' | آخرین tick: ' . self::heartbeat_text( $last_tick_at, $age, $source ),
                'ردیف‌های failed را در «فنی و لاگ» ببینید؛ برای هر Job علتِ آخرین خطا ثبت شده است. '
                    . 'صف در حال اجراست و این وضعیت به‌تنهایی نشانهٔ توقف نیست.',
                false
            );
        }

        // ۴. نصبِ تازه: هیچ اجرایی ثبت نشده و کاری هم در انتظار نیست.
        if ( 0 === $last_tick_at && ! $has_activity ) {
            return self::result(
                self::STATE_NEVER_RUN,
                self::SEVERITY_INFO,
                SystemHealthService::NOT_CONFIGURED,
                'صف هنوز اجرا نشده است',
                'هیچ tickی ثبت نشده و هیچ Jobی در صف نیست؛ این وضعیت برای نصبِ تازه طبیعی است.'
                    . ' | ' . $scheduler_note,
                'کاری لازم نیست. اولین اجرای WP-Cron (با اولین ترافیک یا بازدیدِ پیشخوان) heartbeat را ثبت می‌کند. '
                    . 'برای اجرای قطعی و مستقل از ترافیک، System Cron با `DISABLE_WP_CRON` تنظیم کنید (ADR-0016).',
                false
            );
        }

        // ۵. شواهدِ ناکافی: heartbeat نداریم ولی صف/فعالیت وجود دارد.
        if ( 0 === $last_tick_at ) {
            return self::result(
                self::STATE_UNKNOWN,
                self::SEVERITY_WARNING,
                SystemHealthService::WARNING,
                'وضعیتِ اجرای صف نامشخص است',
                'Jobهای موجودند (queued=' . $queued . ' | failed=' . $failed . ') اما هیچ heartbeat ثبت نشده است.'
                    . ' | ' . $scheduler_note,
                'یک بار صف را با System Cron یا اولین ترافیکِ سایت اجرا کنید؛ پس از آن heartbeat ثبت می‌شود و وضعیت قطعی گزارش می‌شود.',
                false
            );
        }

        // ۶. سالم.
        return self::result(
            self::STATE_HEALTHY,
            self::SEVERITY_OK,
            SystemHealthService::PASS,
            'صف سالم است',
            'آخرین tick: ' . self::heartbeat_text( $last_tick_at, $age, $source )
                . ' | queued=' . $queued . ' | failed=0 | ' . $scheduler_note,
            $wp_cron_off
                ? 'System Cron فعال است؛ نیازی به اقدام نیست.'
                : 'WP-Cron فعال است و با ترافیکِ سایت اجرا می‌شود. برای اجرای قطعی و مستقل از ترافیک، System Cron با `DISABLE_WP_CRON` تنظیم کنید (ADR-0016).',
            false
        );
    }

    /**
     * متنِ توضیحِ روشِ زمان‌بندی — صادقانه دربارهٔ وابستگی به ترافیک.
     *
     * @param mixed $scheduled
     * @param mixed $next_run
     */
    private static function scheduler_note( bool $wp_cron_disabled, $scheduled, $next_run ): string {
        if ( $wp_cron_disabled ) {
            return 'زمان‌بند: System Cron (DISABLE_WP_CRON فعال است)';
        }

        $next = is_int( $next_run ) && $next_run > 0 ? gmdate( 'Y-m-d H:i', $next_run ) . ' UTC' : 'نامشخص';
        if ( false === $scheduled ) {
            return 'زمان‌بند: WP-Cron (رویداد ثبت نشده)';
        }

        return 'زمان‌بند: WP-Cron — اجرای بعدی ' . $next
            . ' (WP-Cron فقط با ترافیکِ سایت اجرا می‌شود مگر System Cron تنظیم شده باشد)';
    }

    private static function heartbeat_text( int $last_tick_at, ?int $age, string $source ): string {
        if ( $last_tick_at <= 0 ) {
            return 'هرگز';
        }
        $origin = match ( $source ) {
            'installation'  => 'سطح نصب',
            'legacy_clinic' => 'داده‌های قبلیِ Clinic (سازگاری)',
            default         => 'منبع نامشخص',
        };

        return gmdate( 'Y-m-d H:i', $last_tick_at ) . ' UTC'
            . ( null !== $age ? ' (' . self::duration_text( $age ) . ' پیش)' : '' )
            . ' — منبع: ' . $origin;
    }

    private static function duration_text( int $seconds ): string {
        $seconds = max( 0, $seconds );
        if ( $seconds < 120 ) {
            return $seconds . ' ثانیه';
        }
        if ( $seconds < 7200 ) {
            return (int) round( $seconds / 60 ) . ' دقیقه';
        }
        if ( $seconds < 172800 ) {
            return (int) round( $seconds / 3600 ) . ' ساعت';
        }

        return (int) round( $seconds / 86400 ) . ' روز';
    }

    /**
     * @return array{
     *     state:string,
     *     severity:string,
     *     status:string,
     *     label:string,
     *     detail:string,
     *     guidance:string,
     *     repairable:bool
     * }
     */
    private static function result(
        string $state,
        string $severity,
        string $status,
        string $label,
        string $detail,
        string $guidance,
        bool $repairable
    ): array {
        return [
            'state'      => $state,
            'severity'   => $severity,
            'status'     => $status,
            'label'      => $label,
            'detail'     => $detail,
            'guidance'   => $guidance,
            'repairable' => $repairable,
        ];
    }

    /**
     * آیا این شدت «مسدودکننده» است؟
     *
     * WP-Cronِ سالمِ متکی به ترافیک همیشه `SEVERITY_OK` است و بنابراین هرگز
     * مسدودکننده نیست.
     */
    public static function severity_is_blocking( string $severity ): bool {
        return self::SEVERITY_PROBLEM === $severity;
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;
use RuntimeException;

/**
 * آزادسازی Holdهای منقضی (Job: holds.expire — هر دقیقه).
 * Idempotent: وضعیت‌گذاری شرطی + کاهش شمارنده با شرط held_count > 0.
 *
 * B2: آزادسازی ظرفیت فقط وقتی انجام می‌شود که همان UPDATE وضعیت Hold واقعاً
 * یک ردیف را تغییر داده باشد. `$wpdb->query()` در حالت `false` و در حالت
 * «صفر ردیفِ اثرگرفته» هر دو `false`/`0` می‌دهند، پس استفادهٔ بولی از نتیجه
 * («انجام شد؟») قابل اتکا نیست؛ اینجا با `execute()` تعداد ردیف‌های اثرگرفته
 * خوانده می‌شود و کاهش شمارنده به آن گره می‌خورد. نتیجه: اجرای دوبارهٔ handler
 * روی همان Hold یا روی Holdی که در این فاصله convert/release شده، ظرفیت را
 * دوباره آزاد نمی‌کند. کف صفر (`GREATEST`) و شرط `held_count > 0` حفظ شده‌اند
 * تا شمارنده هرگز منفی نشود.
 */
final class HoldsExpireHandler
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return int تعداد Holdهایی که در این اجرا واقعاً منقضی و آزاد شدند
     */
    public function __invoke(array $payload): int
    {
        $now = $this->db->nowUtcSql();

        $expired = $this->db->fetchAll(
            'SELECT id, slot_id FROM ' . $this->db->table('cpms_slot_holds') .
            ' WHERE status = %s AND expires_at <= %s LIMIT 500',
            ['active', $now]
        );

        $freed = 0;
        foreach ($expired as $hold) {
            $this->db->transactional(function () use ($hold, $now, &$freed) {
                // وضعیت‌گذاری شرطی (اگر در این لحظه convert شده باشد، اثر نمی‌کند).
                // B2: تعداد ردیف‌های اثرگرفته مرجع است، نه bool نتیجهٔ query.
                $changed = $this->guardedWrite(
                    'UPDATE ' . $this->db->table('cpms_slot_holds') .
                    ' SET status = %s WHERE id = %d AND status = %s AND expires_at <= %s',
                    ['expired', (int) $hold['id'], 'active', $now]
                );
                if ( $changed < 1 ) {
                    // هیچ ردیفی تغییر نکرد ⇒ ظرفیت قبلاً آزاد شده؛ دوباره کم نکن.
                    return;
                }
                // آزادسازی ظرفیت (کاهش اتمیک با کف صفر). صفرِ موجه اینجا یعنی
                // slot از قبل روی کف صفر بوده؛ Hold باز هم منقضی می‌شود.
                $this->guardedWrite(
                    'UPDATE ' . $this->db->table('cpms_schedule_slots') .
                    ' SET held_count = GREATEST(held_count - 1, 0), updated_at = %s
                     WHERE id = %d AND held_count > 0',
                    [$now, (int) $hold['slot_id']]
                );
                $freed++;
            });
        }

        return $freed;
    }

    /**
     * نوشتنِ محافظت‌شده: تعداد ردیف‌های اثرگرفته را برمی‌گرداند، اما یک «خطای
     * واقعی DB» را با «صفر ردیفِ اثرگرفته» اشتباه نمی‌گیرد.
     *
     * چرا لازم است: `CpmsDb::execute()` در حالت soft هر خطای SQL را به `0`
     * تبدیل می‌کند (فقط در Strict mode Exception می‌دهد). صفرِ ناشی از خطا و
     * صفرِ «هیچ ردیفی مطابقت نکرد» دو واقعیتِ متفاوت‌اند، پس اینجا هیچ‌کدام از
     * دو سیگنال به‌تنهایی کافی نیست:
     *
     *   1) مقدار خامِ `$wpdb->query()`: `false` یعنی نوشتن شکست خورد و باید
     *      خطا شمرده شود، حتی اگر درایور هیچ پیامی ثبت نکرده باشد؛ عدد صحیح
     *      یعنی تعداد ردیف‌های اثرگرفته.
     *   2) `$wpdb->last_error`: اگر درایور خطا ثبت کرده باشد، غیرخالی است.
     *
     * چرا دور زدنِ `CpmsDb::execute()` اینجا بی‌خطر است و به رفتار سایر
     * فراخوان‌ها دست نمی‌زند: `execute()` روی همین `wpdb` فقط سه کار می‌کند —
     * `RequestProfiler::query_start()`، آماده‌سازی با `prepare()`
     * (`CpmsDb.php:72-79`)، و `query()` سپس profiler/`ensureNoSqlError`. اینجا
     * `prepare()` خودِ `CpmsDb` استفاده می‌شود و شمارش profiler برای ردیف‌های
     * خطادار (که `query()` هم آن‌ها را می‌شمارد) از دست می‌رود؛ در بدترین حالت
     * profiler یک کوئریِ *ناموفق* را کمتر می‌شمارد. هیچ تغییری در `CpmsDb`
     * یا در معنای `execute()`/`query()` برای فراخوان‌های دیگر انجام نشده است.
     *
     * نتیجه در تراکنشِ همان Hold: خطا → Exception → `transactional()` همان
     * Hold را ROLLBACK می‌کند (شامل وضعیت‌گذاری‌ای که پیش‌تر در همان تراکنش
     * موفق شده بود)، `$freed` افزایش نمی‌یابد و Hold در وضعیت active می‌ماند
     * تا اجرای بعدی دوباره تلاش کند. صفرِ موجه (اسلات از قبل روی کف صفر) عدد
     * صحیح است، پس Hold منقضی می‌شود.
     *
     * @param array<int, mixed> $params
     */
    private function guardedWrite( string $sql, array $params ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase matches the PSR-4 class convention used across this file.
        // Two independent signals, both required. `$wpdb->query()` returns
        // `false` on a failed write and an int (affected rows) on success, so
        // `false` is an error on its own — even when the driver recorded no
        // message. `last_error` catches the case where wpdb reports an error and
        // returns `false` without the count changing either.
        $prepared = $this->db->prepare( $sql, $params );
        $result   = $this->db->wpdb()->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared immediately above.
        $error    = $this->db->wpdb()->last_error;

        if ( $result === false ) {
            throw new RuntimeException(
                'holds.expire write failed: ' . ( $error === '' ? 'wpdb returned false without an error message' : $error )
            );
        }
        if ( ! is_int( $result ) ) {
            throw new RuntimeException( 'holds.expire write returned a non-int result for a write statement' );
        }
        if ( $error !== '' ) {
            throw new RuntimeException( 'holds.expire write failed: ' . $error );
        }

        return $result;
    }
}

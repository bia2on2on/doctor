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
     * تبدیل می‌کند (فقط در Strict mode Exception می‌دهد). اگر خطا را همان
     * «صفر ردیف» ببینیم، یک Hold دست‌نخورده «منقضی» شمرده نمی‌شود ولی
     * آزادسازیِ ظرفیت و شمارشِ `$freed` ممکن است بی‌صدا جلو برود یا شکست
     * پنهان بماند. اینجا خطا → Exception → `transactional()` همان Hold را
     * ROLLBACK می‌کند، `$freed` افزایش نمی‌یابد و Hold در وضعیت active
     * می‌ماند تا اجرای بعدی دوباره تلاش کند.
     *
     * چطور خطا از صفر-ردیف تشخیص داده می‌شود، بدون تغییر معنایی برای سایر
     * فراخوان‌های `CpmsDb`: قرارداد مستند `wpdb` می‌گوید «موفقیت خطای قبلی را
     * پاک می‌کند» و خودِ `wpdb::query()` پیش از اجرا `flush()` می‌زند که
     * `last_error` را خالی می‌کند. پس بعد از هر فراخوانی، `last_error` اگر
     * غیرخالی باشد متعلق به همین عبارت است. تنها مورد مرزی: کوئری‌ای که
     * `wpdb` پیش از `flush()` رد می‌کند (کوئریِ خالی، یا دادهٔ نامعتبرِ
     * غیرقابل‌پاک‌سازی) و `last_error` را دست نمی‌زند؛ آن حالت باقی‌ماندهٔ
     * قدیمی است و محافظه‌کارانه خطا فرض می‌شود (Hold فعال می‌ماند، هرگز
     * ظرفیتِ اضافه آزاد نمی‌شود). برای هر کوئریِ واقعاً اجراشده رفتار
     * `execute()` دست‌نخورده است.
     *
     * @param array<int, mixed> $params
     */
    private function guardedWrite( string $sql, array $params ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- camelCase matches the PSR-4 class convention used across this file.
        $wpdb    = $this->db->wpdb();
        $before  = $wpdb->last_error;
        $changed = $this->db->execute( $sql, $params );
        $error   = $wpdb->last_error;
        if ( $error === '' || $error === $before ) {
            return $changed;
        }

        throw new RuntimeException( 'holds.expire write failed: ' . $error );
    }
}

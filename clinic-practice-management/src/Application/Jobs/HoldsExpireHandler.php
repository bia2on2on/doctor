<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;

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
                $changed = $this->db->execute(
                    'UPDATE ' . $this->db->table('cpms_slot_holds') .
                    ' SET status = %s WHERE id = %d AND status = %s AND expires_at <= %s',
                    ['expired', (int) $hold['id'], 'active', $now]
                );
                if ( $changed < 1 ) {
                    // هیچ ردیفی تغییر نکرد ⇒ ظرفیت قبلاً آزاد شده؛ دوباره کم نکن.
                    return;
                }
                // آزادسازی ظرفیت (کاهش اتمیک با کف صفر)
                $this->db->execute(
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
}

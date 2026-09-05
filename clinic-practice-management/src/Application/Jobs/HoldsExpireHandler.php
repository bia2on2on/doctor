<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * آزادسازی Holdهای منقضی (Job: holds.expire — هر دقیقه).
 * Idempotent: وضعیت‌گذاری شرطی + کاهش شمارنده با شرط held_count > 0.
 */
final class HoldsExpireHandler
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

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
                // وضعیت‌گذاری شرطی (اگر در این لحظه convert شده باشد، اثر نمی‌کند)
                $updated = $this->db->query(
                    'UPDATE ' . $this->db->table('cpms_slot_holds') .
                    ' SET status = %s WHERE id = %d AND status = %s AND expires_at <= %s',
                    ['expired', (int) $hold['id'], 'active', $now]
                );
                if (!$updated) {
                    return;
                }
                // آزادسازی ظرفیت (کاهش اتمیک با کف صفر)
                $this->db->query(
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

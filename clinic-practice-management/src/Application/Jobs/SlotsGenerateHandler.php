<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\Settings;
use ClinicCore\Domain\Slots\SlotGenerator;
use DomainException;

/**
 * تولید Slotهای آینده (Job: slots.generate — روزانه + lazy).
 * Idempotent: UNIQUE (clinician_id, slot_date, slot_time) + INSERT IGNORE.
 */
final class SlotsGenerateHandler
{
    public function __construct(
        private readonly CpmsDb $db,
        private readonly Settings $settings,
        private readonly OpLogger $op
    ) {
    }

    public function __invoke(array $payload): int
    {
        $horizon = (int) ($payload['horizon_days'] ?? $this->settings->get('booking.max_future_days', 30));
        $today = gmdate('Y-m-d');

        $clinicians = $this->db->fetchAll(
            'SELECT c.id AS clinician_id, c.clinic_id, s.day_of_week, s.start_time, s.end_time,
                    s.break_start, s.break_end, s.appointment_duration_min, s.slot_capacity
             FROM ' . $this->db->table('cpms_clinicians') . ' c
             JOIN ' . $this->db->table('cpms_schedule') . ' s ON s.clinician_id = c.id AND s.is_active = 1
             WHERE c.is_active = 1'
        );

        $generated = 0;
        foreach ($clinicians as $clinician) {
            for ($day = 1; $day <= $horizon; $day++) {
                $date = gmdate('Y-m-d', strtotime($today . ' +' . $day . ' days'));
                try {
                    $slots = $this->generateDaySlots($clinician, $date, $day);
                } catch (DomainException $e) {
                    $this->op->warning('SLOTS_GEN_SKIP', ['clinician_id' => $clinician['clinician_id'], 'date' => $date, 'error' => $e->getMessage()]);
                    continue;
                }
                foreach ($slots as $time) {
                    $this->db->query(
                        'INSERT IGNORE INTO ' . $this->db->table('cpms_schedule_slots') . '
                             (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity,
                              is_open, generated_from, created_at, updated_at)
                         VALUES (%d, %d, %s, %s, %d, %d, 1, %s, %s, %s)',
                        [
                            $clinician['clinic_id'],
                            $clinician['clinician_id'],
                            $date,
                            $time,
                            (int) $clinician['appointment_duration_min'],
                            (int) $clinician['slot_capacity'],
                            $payload['source'] ?? 'cron',
                            $this->db->nowUtcSql(),
                            $this->db->nowUtcSql(),
                        ]
                    );
                    $generated++;
                }
            }
        }

        return $generated;
    }

    /**
     * تبدیل day-of-week گرگوری (0=Sunday..6=Saturday) به شماره روز هفته ایرانی (0=شنبه..6=جمعه).
     */
    private static function toIranianDow(int $w): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];

        return $map[$w];
    }

    /**
     * @param array<string, mixed> $clinician
     *
     * @return list<string>
     */
    private function generateDaySlots(array $clinician, string $date, int $dayOffset): array
    {
        // day_of_week: 0=شنبه ... 6=جمعه (هفته ایرانی) — تبدیل از gmdate('w'): 0=یک‌شنبه ... 6=یکشنبه
        $dow = self::toIranianDow((int) gmdate('w', strtotime($date)));
        if ($dow !== (int) $clinician['day_of_week']) {
            return [];
        }

        $exceptions = $this->db->fetchAll(
            'SELECT type, start_time, end_time FROM ' . $this->db->table('cpms_schedule_exceptions') .
            ' WHERE clinician_id = %d AND date = %s',
            [$clinician['clinician_id'], $date]
        );

        return SlotGenerator::generateDay(
            [
                'start' => substr((string) $clinician['start_time'], 0, 5),
                'end' => substr((string) $clinician['end_time'], 0, 5),
                'break_start' => $clinician['break_start'] !== null ? substr((string) $clinician['break_start'], 0, 5) : null,
                'break_end' => $clinician['break_end'] !== null ? substr((string) $clinician['break_end'], 0, 5) : null,
            ],
            (int) $clinician['appointment_duration_min'],
            array_map(static fn ($e) => [
                'type' => (string) $e['type'],
                'start' => $e['start_time'] !== null ? substr((string) $e['start_time'], 0, 5) : null,
                'end' => $e['end_time'] !== null ? substr((string) $e['end_time'], 0, 5) : null,
            ], $exceptions)
        );
    }
}

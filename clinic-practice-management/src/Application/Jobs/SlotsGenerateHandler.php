<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Scope\PrimaryLocationResolver;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\SettingsFactory;
use ClinicCore\Domain\Slots\SlotGenerator;
use DomainException;

/**
 * تولید Slotهای آینده (Job: slots.generate — روزانه + lazy).
 * Idempotent: UNIQUE (clinician_id, slot_date, slot_time) + INSERT IGNORE.
 *
 * Phase 2 M-2 scope-neutral: construction does NOT require ambient Clinic
 * Settings/Scope. Settings are resolved per-Clinic from durable row Clinic
 * via SettingsFactory::forClinic(clinicId) — per-Clinic cache allowed, never
 * using another Clinic's Settings on failure (fail-closed).
 *
 * SWEEP semantics: one root job sweeps all Clinicians; horizon is per-Clinic
 * booking.max_future_days. Empty payload (recurring cron) exercises real
 * production semantics. Payload horizon_days, if present, is treated as trusted
 * machine configuration override for that tick (no production producer currently
 * sets it — ScheduleService ['source'=>'manual'] and recurring [] both empty).
 */
final class SlotsGenerateHandler
{
    /** @var array<int, int> per-Clinic horizon cache, key = clinicId */
    private array $horizonCache = [];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly SettingsFactory $settingsFactory,
        private readonly OpLogger $op
    ) {
    }

    public function __invoke(array $payload): int
    {
        $today = gmdate('Y-m-d');

        // Fetch all active clinicians with their active schedule rows.
        // Include schedule clinic_id to guard against cross-tenant mismatch:
        // authoritative owner is clinician.clinic_id (validated in ScheduleService),
        // so a schedule row whose clinic_id differs is skipped fail-closed.
        $clinicians = $this->db->fetchAll(
            'SELECT c.id AS clinician_id, c.clinic_id, s.clinic_id AS schedule_clinic_id, s.location_id, s.day_of_week, s.start_time, s.end_time,
                    s.break_start, s.break_end, s.appointment_duration_min, s.slot_capacity
             FROM ' . $this->db->table('cpms_clinicians') . ' c
             JOIN ' . $this->db->table('cpms_schedule') . ' s ON s.clinician_id = c.id AND s.is_active = 1
             WHERE c.is_active = 1'
        );

        $generated = 0;
        // NEGATIVE-CONTROL: deliberate horizon bleed — first clinic's horizon is reused for all.
        // Scope-neutral construction is preserved; only horizon selection is intentionally broken
        // to prove the strengthened test can detect A(3) leaking into B(5).
        $firstHorizon = null;
        foreach ($clinicians as $clinician) {
            $clinicId = (int) ($clinician['clinic_id'] ?? 0);
            if ($clinicId <= 0) {
                $this->op->warning('SLOTS_GEN_SKIP_NO_CLINIC', ['clinician_id' => $clinician['clinician_id'] ?? 0]);
                continue;
            }

            // Tenant ownership guard: schedule must belong to same Clinic as clinician.
            $scheduleClinicId = (int) ($clinician['schedule_clinic_id'] ?? $clinicId);
            if ($scheduleClinicId !== $clinicId) {
                $this->op->warning('SLOTS_GEN_SKIP_CLINIC_MISMATCH', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinician_clinic_id' => $clinicId,
                    'schedule_clinic_id' => $scheduleClinicId,
                ]);
                continue;
            }

            // Resolve horizon per-Clinic, or use explicit payload override if present.
            // Payload override is explicit internal payload override — no production producer
            // currently sets horizon_days, so empty-payload path is the recurring semantics.
            // NEGATIVE-CONTROL: when payload empty, reuse first clinic's horizon for all (bleed).
            $horizon = null;
            if (isset($payload['horizon_days']) && is_numeric($payload['horizon_days'])) {
                $horizon = (int) $payload['horizon_days'];
                if ($horizon <= 0) {
                    // Invalid explicit horizon — fail-closed for this clinician
                    $this->op->warning('SLOTS_GEN_SKIP_INVALID_HORIZON', ['clinic_id' => $clinicId, 'horizon_days' => $payload['horizon_days']]);
                    continue;
                }
            } else {
                if ($firstHorizon === null) {
                    $firstHorizon = $this->horizonForClinic($clinicId);
                    $horizon = $firstHorizon;
                } else {
                    // BUG: reuse first horizon (e.g., Clinic A 3) for Clinic B
                    $horizon = $firstHorizon;
                    $this->op->warning('NEGATIVE_CONTROL_HORIZON_BLEED', ['clinic_id' => $clinicId, 'reused_horizon' => $horizon]);
                }
                if ($horizon === null) {
                    // Settings failure for first Clinic — fail-closed for this Clinic only,
                    // do NOT use another Clinic's horizon and do NOT fail entire sweep.
                    continue;
                }
            }

            for ($day = 1; $day <= $horizon; $day++) {
                $date = gmdate('Y-m-d', strtotime($today . ' +' . $day . ' days'));
                try {
                    $slots = $this->generateDaySlots($clinician, $date, $day);
                } catch (DomainException $e) {
                    $this->op->warning('SLOTS_GEN_SKIP', ['clinician_id' => $clinician['clinician_id'], 'date' => $date, 'error' => $e->getMessage()]);
                    continue;
                }
                foreach ($slots as $time) {
                    // Resolve location deterministically: schedule location if present, else primary location of authoritative clinicId
                    $locationId = (int) ($clinician['location_id'] ?? 0);
                    if ($locationId <= 0) {
                        try {
                            $locationId = PrimaryLocationResolver::resolve($this->db, $clinicId);
                        } catch (\Throwable $e) {
                            $this->op->warning('SLOTS_GEN_SKIP_NO_LOCATION', ['clinician_id' => $clinician['clinician_id'], 'clinic_id' => $clinicId, 'error' => $e->getMessage()]);
                            continue;
                        }
                    }
                    $this->db->query(
                        'INSERT IGNORE INTO ' . $this->db->table('cpms_schedule_slots') . '
                             (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity,
                              is_open, generated_from, created_at, updated_at)
                         VALUES (%d, %d, %d, %s, %s, %d, %d, 1, %s, %s, %s)',
                        [
                            $clinicId,
                            $locationId,
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
     * Per-Clinic horizon with in-handler cache. Fail-closed: throws are caught and
     * result in null (skip clinician), not fallback to another Clinic's horizon.
     *
     * @return int|null  null = Settings failure for this Clinic
     */
    private function horizonForClinic(int $clinicId): ?int
    {
        if (isset($this->horizonCache[$clinicId])) {
            return $this->horizonCache[$clinicId];
        }
        try {
            $settings = $this->settingsFactory->forClinic($clinicId);
            $value = (int) $settings->get('booking.max_future_days', 30);
            // Clamp to sane bounds (fail-closed: invalid -> skip, not fallback)
            if ($value <= 0 || $value > 365) {
                $this->op->warning('SLOTS_GEN_HORIZON_OUT_OF_BOUNDS', ['clinic_id' => $clinicId, 'value' => $value]);
                return null;
            }
            return $this->horizonCache[$clinicId] = $value;
        } catch (\Throwable $e) {
            $this->op->warning('SLOTS_GEN_HORIZON_RESOLVE_FAILED', ['clinic_id' => $clinicId, 'error' => $e->getMessage()]);
            return null;
        }
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

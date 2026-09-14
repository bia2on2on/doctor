<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\SettingsFactory;
use ClinicCore\Domain\Slots\SlotGenerator;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
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
 * production semantics. Payload horizon_days, if present, is an explicit
 * internal payload override for that tick (no production producer currently
 * sets it — ScheduleService ['source'=>'manual'] and recurring [] both empty).
 * Trust/authorization semantics of future horizon_days producers remain OPEN / NOT VERIFIED.
 *
 * Calendar frame (M2 contract): candidate generation dates are anchored to the
 * **UTC calendar date of the single frame-free reference instant** — one clinical
 * calendar axis for the whole sweep:
 *
 *   reference UTC instant -> UTC calendar date ("today") -> offsets {1..horizon}
 *   -> schedule weekday (of that pure calendar date) -> persisted slot_date
 *
 * A pure calendar date's weekday is frame-independent, and all date math is done
 * on explicit UTC `DateTimeImmutable` objects — never `strtotime()` on a
 * date-only string, never `gmdate()` on an ambient timestamp — so the generated
 * dates do NOT depend on the ambient PHP timezone, the WordPress timezone or the
 * Clinic timezone.
 *
 * The authoritative Location's validated IANA timezone keeps its remaining
 * contractual roles: the fail-closed row gate (missing/empty/non-IANA -> skip
 * row, never substituted by Clinic/WordPress/PHP timezone) and `slot_time`,
 * which remains the Schedule's Location-local wall-clock time (existing schema
 * semantics: DATE + TIME are local to the Location).
 *
 * Why not the Location-local "today" (the C-9 experiment): anchoring each
 * Location's window to its own local calendar date shifted the whole window by
 * one day whenever the Location-local date differs from the UTC date (e.g.
 * Europe/Berlin 22:00–23:59 UTC). The contracted within-horizon date (UTC
 * today+1) then became the Location's own "today" — structurally excluded by
 * "today itself is never generated" — while the window simultaneously
 * overshot the horizon by one UTC day. The UTC anchor restores the contracted
 * window {today+1 .. today+horizon} relative to the single reference instant.
 *
 * The horizon loop semantics are unchanged: offsets {1 .. horizon} INCLUSIVE
 * relative to the reference instant's UTC "today" (today itself is never
 * generated).
 *
 * Location attribution arrives with each sweep row via a JOIN (no per-row
 * Location query, no N+1). A row whose Location is missing, inactive, owned by a
 * different Clinic, or carries an empty/non-IANA timezone fails closed for that
 * row only — no fallback to another Location or to a coarser timezone.
 */
final class SlotsGenerateHandler
{
    /** @var array<int, int> per-Clinic horizon cache, key = clinicId */
    private array $horizonCache = [];

    /** @var array<string, bool> validated IANA identifiers, key = timezone string */
    private array $timezoneValid = [];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly SettingsFactory $settingsFactory,
        private readonly OpLogger $op
    ) {
    }

    public function __invoke(array $payload): int
    {
        // Single frame-free reference instant for the whole sweep. Candidate
        // generation dates are anchored to its UTC calendar date (one clinical
        // calendar axis for the sweep — no per-Location frame, no ambient
        // PHP/WordPress/Clinic timezone).
        $referenceInstant = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // UTC calendar "today" of the reference instant. The +N day arithmetic
        // below is pure Gregorian date math on explicit UTC objects (no
        // strtotime on a date-only string, no gmdate on an ambient timestamp —
        // no ambient PHP timezone dependence, no invented DST policy).
        $anchor = new DateTimeImmutable($referenceInstant->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));

        // Fetch all active clinicians with their active schedule rows.
        // Include schedule clinic_id to guard against cross-tenant mismatch:
        // authoritative owner is clinician.clinic_id (validated in ScheduleService),
        // so a schedule row whose clinic_id differs is skipped fail-closed.
        //
        // LEFT JOIN on the Location so timezone + ownership arrive WITH each row
        // (no per-row Location lookup / no N+1). LEFT (not INNER) so a broken
        // Location reference is observable and can fail closed with a log, rather
        // than silently vanishing from the sweep.
        $clinicians = $this->db->fetchAll(
            'SELECT c.id AS clinician_id, c.clinic_id, s.clinic_id AS schedule_clinic_id, s.location_id, s.day_of_week, s.start_time, s.end_time,
                    s.break_start, s.break_end, s.appointment_duration_min, s.slot_capacity,
                    l.id AS loc_id, l.clinic_id AS location_clinic_id, l.timezone AS location_timezone
             FROM ' . $this->db->table('cpms_clinicians') . ' c
             JOIN ' . $this->db->table('cpms_schedule') . ' s ON s.clinician_id = c.id AND s.is_active = 1
             LEFT JOIN ' . $this->db->table('cpms_locations') . ' l ON l.id = s.location_id
             WHERE c.is_active = 1'
        );

        $generated = 0;
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

            // ---- Authoritative Location resolution (Phase 2 temporal, C-9) ----
            // Schedule.location_id is durable/NOT NULL (migration 0013). An explicit
            // persisted Location is NEVER overridden or silently replaced by the
            // primary Location: a broken reference fails closed for this row.
            $scheduleLocationId = (int) ($clinician['location_id'] ?? 0);
            $joinedLocationId = (int) ($clinician['loc_id'] ?? 0);
            if ($scheduleLocationId <= 0 || $joinedLocationId !== $scheduleLocationId) {
                $this->op->warning('SLOTS_GEN_SKIP_LOCATION_INVALID', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinic_id' => $clinicId,
                    'schedule_location_id' => $scheduleLocationId,
                ]);
                continue;
            }

            // Location must belong to the SAME authoritative Clinic as the clinician.
            $locationClinicId = (int) ($clinician['location_clinic_id'] ?? 0);
            if ($locationClinicId !== $clinicId) {
                $this->op->warning('SLOTS_GEN_SKIP_LOCATION_CLINIC_MISMATCH', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinician_clinic_id' => $clinicId,
                    'location_id' => $scheduleLocationId,
                    'location_clinic_id' => $locationClinicId,
                ]);
                continue;
            }

            // NOTE: `location.is_active` deliberately does NOT gate generation here.
            // Whether deactivating a Location must stop generation for existing
            // Schedules is a separate product policy with no verified canonical
            // contract, and it is not required by this timezone correction.

            // Validated IANA timezone — no fallback to Clinic/WordPress/PHP timezone.
            $locationTz = $this->locationTimezone((string) ($clinician['location_timezone'] ?? ''));
            if ($locationTz === null) {
                $this->op->warning('SLOTS_GEN_SKIP_LOCATION_TZ_INVALID', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinic_id' => $clinicId,
                    'location_id' => $scheduleLocationId,
                    'timezone' => (string) ($clinician['location_timezone'] ?? ''),
                ]);
                continue;
            }

            $locationId = $scheduleLocationId;

            // Candidate dates are anchored to the reference instant's UTC calendar
            // date ($anchor, computed once above) — the Location's IANA zone does
            // NOT shift the calendar frame (it keeps its gate + wall-clock roles).
            // Horizon semantics preserved exactly: offsets {1 .. horizon} inclusive
            // from that UTC "today"; today itself is never generated.

            // Resolve horizon per-Clinic, or use explicit payload override if present.
            // Payload horizon_days is an explicit internal payload override; no production
            // producer currently sets horizon_days, so empty-payload path is the recurring semantics.
            $horizon = null;
            if (isset($payload['horizon_days']) && is_numeric($payload['horizon_days'])) {
                $horizon = (int) $payload['horizon_days'];
                if ($horizon <= 0) {
                    // Invalid explicit horizon — fail-closed for this clinician
                    $this->op->warning('SLOTS_GEN_SKIP_INVALID_HORIZON', ['clinic_id' => $clinicId, 'horizon_days' => $payload['horizon_days']]);
                    continue;
                }
            } else {
                $horizon = $this->horizonForClinic($clinicId);
                if ($horizon === null) {
                    // Settings failure for this Clinic — fail-closed for this Clinic only,
                    // do NOT use another Clinic's horizon and do NOT fail entire sweep.
                    continue;
                }
            }

            // Horizon semantics preserved exactly: offsets {1 .. horizon} inclusive
            // from the reference instant's UTC "today" ($anchor); today itself is
            // never generated.
            for ($day = 1; $day <= $horizon; $day++) {
                $dateObj = $anchor->add(new DateInterval('P' . $day . 'D'));
                $date = $dateObj->format('Y-m-d');
                try {
                    $slots = $this->generateDaySlots($clinician, $date, $dateObj);
                } catch (DomainException $e) {
                    $this->op->warning('SLOTS_GEN_SKIP', ['clinician_id' => $clinician['clinician_id'], 'date' => $date, 'error' => $e->getMessage()]);
                    continue;
                }
                foreach ($slots as $time) {
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
     * Validated IANA timezone for a Location, or null when the identifier is
     * empty or not a real IANA zone (fail-closed — never substituted).
     */
    private function locationTimezone(string $tz): ?DateTimeZone
    {
        $tz = trim($tz);
        if ($tz === '') {
            return null;
        }
        if (!isset($this->timezoneValid[$tz])) {
            $this->timezoneValid[$tz] = in_array($tz, timezone_identifiers_list(), true);
        }
        if ($this->timezoneValid[$tz] === false) {
            return null;
        }

        try {
            return new DateTimeZone($tz);
        } catch (\Throwable $e) {
            $this->timezoneValid[$tz] = false;

            return null;
        }
    }

    /**
     * @param array<string, mixed> $clinician
     *
     * @return list<string>
     */
    private function generateDaySlots(array $clinician, string $date, DateTimeImmutable $dateObj): array
    {
        // day_of_week: 0=شنبه ... 6=جمعه (هفته ایرانی) — تبدیل از 'w': 0=یک‌شنبه ... 6=شنبه.
        // Weekday is read off the UTC-anchored calendar date object; a pure date's
        // weekday is frame-independent and the object is built in explicit UTC, so
        // it never depends on the ambient PHP timezone (no strtotime on a date-only string).
        $dow = self::toIranianDow((int) $dateObj->format('w'));
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

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Domain\Booking\BookingWindow;
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
 * Idempotent: u_slot UNIQUE (location_id, clinician_id, slot_date, slot_time) + INSERT IGNORE.
 *
 * Phase 2 M-2 scope-neutral: construction does NOT require ambient Clinic
 * Settings/Scope. Settings are resolved per-Clinic from durable row Clinic
 * via SettingsFactory::forClinic(clinicId) — per-Clinic cache allowed, never
 * using another Clinic's Settings on failure (fail-closed).
 *
 * SWEEP semantics: one root job sweeps all Clinicians; horizon is per-Clinic
 * booking.max_future_days. Empty payload (recurring cron) exercises real
 * production semantics. Payload horizon_days, if present, is an explicit
 * internal payload override for that tick; the real producer is
 * `bin/cpms slots generate --days=N` (ScheduleService ['source'=>'manual'] and
 * recurring [] stay empty). Valid override range = 1..365 — the same bound the
 * settings path enforces; <= 0 and > 365 are skipped fail-closed per sweep item
 * with SLOTS_GEN_SKIP_INVALID_HORIZON (job continues, succeeds).
 *
 * Phase 2 temporal slice (C-9): the generation calendar date and the weekday are
 * computed in the **authoritative Location's** validated IANA timezone, never in
 * UTC, the Clinic timezone, the WordPress timezone or the ambient PHP timezone.
 *
 *   reference UTC instant -> Location timezone -> Location-local calendar date
 *   -> schedule weekday -> persisted slot_date
 *
 * The horizon loop semantics are unchanged: offsets {1 .. horizon} INCLUSIVE
 * relative to that Location's local "today" (today itself is never generated).
 * `slot_time` remains the Schedule's Location-local wall-clock time, exactly as
 * before (existing schema semantics: DATE + TIME are local to the Location).
 *
 * Location attribution arrives with each sweep row via a JOIN (no per-row
 * Location query, no N+1). A row whose Location is missing, inactive, owned by a
 * different Clinic, or carries an empty/non-IANA timezone fails closed for that
 * row only — no fallback to another Location or to a coarser timezone.
 *
 * Phase 4 Slice 1 (professional multi-Clinic participation): the tenant of a
 * sweep row is the SCHEDULE's `clinic_id`. It is served when it equals the
 * profile's home Clinic (compat, unchanged) or when an ACTIVE durable
 * membership links the profile's WP user to that Clinic (SoT). Otherwise the
 * row is skipped fail-closed, and the Clinic's own horizon/Location/exceptions
 * govern generation — never another Clinic's.
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
        // Single reference instant for the whole sweep. Each row is then projected
        // into its own Location's calendar frame — the instant itself is frame-free.
        $reference_instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Fetch all active clinicians with their active schedule rows.
        //
        // Phase 4 Slice 1 — the authoritative tenant of a row is the SCHEDULE's
        // clinic_id (durable owner of the row). A professional may serve that
        // Clinic when it is the profile's HOME Clinic (compat path, no behavior
        // removal) or when an ACTIVE durable membership links the profile's WP
        // user to that Clinic — the SoT for multi-Clinic participation (target
        // model د-۶-۳: `clinicians.clinic_id` is never the participation border).
        // The membership arrives per row via LEFT JOIN (no N+1) and the guard
        // below fails closed for rows without durable participation.
        //
        // LEFT JOIN on the Location so timezone + ownership arrive WITH each row
        // (no per-row Location lookup / no N+1). LEFT (not INNER) so a broken
        // Location reference is observable and can fail closed with a log, rather
        // than silently vanishing from the sweep.
        $clinicians = $this->db->fetchAll(
            'SELECT c.id AS clinician_id, c.clinic_id, c.wp_user_id, s.id AS schedule_id, s.clinic_id AS schedule_clinic_id,
                    s.location_id, s.day_of_week, s.start_time, s.end_time, s.break_start, s.break_end,
                    s.appointment_duration_min, s.slot_capacity, s.is_active AS schedule_is_active,
                    cl.organization_id AS schedule_organization_id,
                    l.id AS loc_id, l.clinic_id AS location_clinic_id, l.timezone AS location_timezone,
                    l.is_active AS location_is_active, m.id AS schedule_membership_id
             FROM ' . $this->db->table('cpms_clinicians') . ' c
             JOIN ' . $this->db->table('cpms_schedule') . ' s ON s.clinician_id = c.id AND s.is_active = 1
             LEFT JOIN ' . $this->db->table('cpms_locations') . ' l ON l.id = s.location_id
             LEFT JOIN ' . $this->db->table('cpms_clinic_memberships') . ' m
                    ON m.wp_user_id = c.wp_user_id AND m.clinic_id = s.clinic_id AND m.status = \'active\'
             JOIN ' . $this->db->table( 'cpms_clinics' ) . ' cl ON cl.id = s.clinic_id AND cl.status = \'active\'
             JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = cl.organization_id AND o.status = \'active\'
             WHERE c.is_active = 1'
        );

        $generated = 0;
        foreach ($clinicians as $clinician) {
            $home_clinic_id = (int) ($clinician['clinic_id'] ?? 0);
            if ($home_clinic_id <= 0) {
                $this->op->warning('SLOTS_GEN_SKIP_NO_CLINIC', ['clinician_id' => $clinician['clinician_id'] ?? 0]);
                continue;
            }

            // Phase 4 Slice 1 — participation guard: the row's Clinic is
            // authoritative and is served only through the profile's home Clinic
            // (compat) or a durable ACTIVE membership. Anything else fails closed
            // for this row only — no fallback to the home Clinic, no guessing.
            $schedule_clinic_id = (int) ($clinician['schedule_clinic_id'] ?? 0);
            $has_membership = ($clinician['schedule_membership_id'] ?? null) !== null;
            if ($schedule_clinic_id <= 0 || ($schedule_clinic_id !== $home_clinic_id && !$has_membership)) {
                $this->op->warning('SLOTS_GEN_SKIP_CLINIC_MISMATCH', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinician_clinic_id' => $home_clinic_id,
                    'schedule_clinic_id' => $schedule_clinic_id,
                ]);
                continue;
            }

            // From here down the ROW's Clinic is the authoritative tenant:
            // Location ownership, per-Clinic horizon, exception scoping and the
            // persisted slot rows all use it (never the home Clinic).
            $clinic_id = $schedule_clinic_id;

            // ---- Authoritative Location resolution (Phase 2 temporal, C-9) ----
            // Schedule.location_id is durable/NOT NULL (migration 0013). An explicit
            // persisted Location is NEVER overridden or silently replaced by the
            // primary Location: a broken reference fails closed for this row.
            $schedule_location_id = (int) ($clinician['location_id'] ?? 0);
            $joined_location_id = (int) ($clinician['loc_id'] ?? 0);
            if ($schedule_location_id <= 0 || $joined_location_id !== $schedule_location_id) {
                $this->op->warning('SLOTS_GEN_SKIP_LOCATION_INVALID', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinic_id' => $clinic_id,
                    'schedule_location_id' => $schedule_location_id,
                ]);
                continue;
            }

            // Location must belong to the SAME authoritative Clinic as the row.
            $location_clinic_id = (int) ($clinician['location_clinic_id'] ?? 0);
            if ($location_clinic_id !== $clinic_id) {
                $this->op->warning('SLOTS_GEN_SKIP_LOCATION_CLINIC_MISMATCH', [
                    'clinician_id' => $clinician['clinician_id'],
                    'schedule_clinic_id' => $clinic_id,
                    'location_id' => $schedule_location_id,
                    'location_clinic_id' => $location_clinic_id,
                ]);
                continue;
            }

            if ( (int) ( $clinician['location_is_active'] ?? 0 ) !== 1 ) {
                $this->op->warning(
                    'SLOTS_GEN_SKIP_LOCATION_INACTIVE',
                    [
                        'schedule_id' => (int) ( $clinician['schedule_id'] ?? 0 ),
                        'clinic_id'   => $clinic_id,
                        'location_id' => $schedule_location_id,
                    ]
                );
                continue;
            }

            // Validated IANA timezone — no fallback to Clinic/WordPress/PHP timezone.
            $location_tz = $this->locationTimezone((string) ($clinician['location_timezone'] ?? ''));
            if ($location_tz === null) {
                $this->op->warning('SLOTS_GEN_SKIP_LOCATION_TZ_INVALID', [
                    'clinician_id' => $clinician['clinician_id'],
                    'clinic_id' => $clinic_id,
                    'location_id' => $schedule_location_id,
                    'timezone' => (string) ($clinician['location_timezone'] ?? ''),
                ]);
                continue;
            }

            $location_id = $schedule_location_id;

            // Location-local calendar "today" for this reference instant. Anchored in
            // UTC afterwards so the +N day arithmetic is pure Gregorian date math and
            // never depends on the ambient PHP timezone (and invents no DST policy).
            $local_today = $reference_instant->setTimezone($location_tz)->format('Y-m-d');
            $anchor = new DateTimeImmutable($local_today . ' 00:00:00', new DateTimeZone('UTC'));

            // Resolve horizon per-Clinic, or use explicit payload override if present.
            // Payload horizon_days is an explicit internal payload override; absent/malformed
            // (non-numeric) values keep the recurring empty-payload semantics (settings path).
            $horizon = null;
            if (isset($payload['horizon_days']) && is_numeric($payload['horizon_days'])) {
                $horizon = (int) $payload['horizon_days'];
                if ($horizon <= 0 || $horizon > 365) {
                    // Invalid explicit horizon — fail-closed for this clinician (skip this
                    // sweep item; the sweep continues and the job stays successful). C7
                    // hardening: the override now shares the settings path's 1..365 bound
                    // (> 365 previously produced unbounded horizons). Numeric non-integer
                    // input (e.g. "90.7") keeps its existing (int) cast semantics — no new
                    // policy. Warning context intentionally stays clinic_id + horizon_days only.
                    $this->op->warning('SLOTS_GEN_SKIP_INVALID_HORIZON', ['clinic_id' => $clinic_id, 'horizon_days' => $payload['horizon_days']]);
                    continue;
                }
            } else {
                $horizon = $this->horizonForClinic($clinic_id);
                if ($horizon === null) {
                    // Settings failure for this Clinic — fail-closed for this Clinic only,
                    // do NOT use another Clinic's horizon and do NOT fail entire sweep.
                    continue;
                }
            }

            // Horizon semantics preserved exactly: offsets {1 .. horizon} inclusive
            // from the Location-local "today"; today itself is never generated.
            // Candidate computation does not hold lifecycle locks. Immediately
            // before persistence, persist_schedule_slots() rechecks Organization
            // → Clinic → Clinician → Schedule → Location in the shared lock order.
            $slot_candidates = [];
            for ($day = 1; $day <= $horizon; $day++) {
                $date_obj = $anchor->add(new DateInterval('P' . $day . 'D'));
                $date = $date_obj->format('Y-m-d');
                try {
                    $slots = $this->generateDaySlots($clinician, $clinic_id, $date, $date_obj, $location_id);
                } catch (DomainException $e) {
                    $this->op->warning('SLOTS_GEN_SKIP', ['clinician_id' => $clinician['clinician_id'], 'date' => $date, 'error' => $e->getMessage()]);
                    continue;
                }
                if ( $slots !== [] ) {
                    $slot_candidates[ $date ] = $slots;
                }
            }
            if ( $slot_candidates !== [] ) {
                $generated += $this->persist_schedule_slots(
                    $clinician,
                    $clinic_id,
                    $location_id,
                    (string) ( $clinician['location_timezone'] ?? '' ),
                    $slot_candidates,
                    $payload
                );
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
     * Persist precomputed slots only if the lifecycle and schedule inputs are
     * still current. Candidate generation is deliberately outside this critical
     * section; the final re-read closes the race with Location deactivation and
     * Schedule edits without holding lifecycle locks during calendar work.
     *
     * Lock order: Organization → Clinic → Clinician → Schedule → Location.
     * The lifecycle prefix matches LocationService; the Clinician → Schedule
     * pair matches ScheduleService. Locking Location only after the Schedule
     * row avoids inverting ScheduleService's foreign-key check. Booking paths
     * acquire the same Organization -> Clinic prefix, then Location before
     * Slot/capacity rows.
     *
     * @param array<string, mixed>       $candidate      Candidate sweep row.
     * @param array<string, list<string>> $slot_candidates date => local wall times.
     * @param array<string, mixed>       $payload        Original job payload.
     */
    private function persist_schedule_slots(
        array $candidate,
        int $clinic_id,
        int $location_id,
        string $candidate_timezone,
        array $slot_candidates,
        array $payload
    ): int {
        $schedule_id     = (int) ( $candidate['schedule_id'] ?? 0 );
        $organization_id = (int) ( $candidate['schedule_organization_id'] ?? 0 );
        $clinician_id    = (int) ( $candidate['clinician_id'] ?? 0 );
        $home_clinic_id  = (int) ( $candidate['clinic_id'] ?? 0 );

        if ( $schedule_id <= 0 || $organization_id <= 0 || $clinic_id <= 0 || $location_id <= 0 || $clinician_id <= 0 ) {
            $this->op->warning(
                'SLOTS_GEN_SKIP_SCHEDULE_INVALID',
                [
                    'schedule_id' => $schedule_id,
                    'clinic_id'   => $clinic_id,
                    'location_id' => $location_id,
                ]
            );
            return 0;
        }

        // Lifecycle validation runs under Organization -> Clinic -> Clinician ->
        // Schedule -> Location. The Slot rows themselves are written AFTER that
        // transaction commits: booking holds a Slot row and then waits for the
        // Location, so an INSERT IGNORE (which takes a shared lock on a duplicate
        // key) while holding Location would invert the order and could deadlock.
        $plan     = null;
        $validated = $this->db->transactional(
            function () use (
                $candidate,
                $clinic_id,
                $location_id,
                $candidate_timezone,
                $slot_candidates,
                $payload,
                $schedule_id,
                $organization_id,
                $clinician_id,
                $home_clinic_id,
                &$plan
            ): int {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [ $organization_id ]
                );
                if ( $organization === null || (int) $organization['id'] !== $organization_id || (string) $organization['status'] !== 'active' ) {
                    $this->op->warning( 'SLOTS_GEN_SKIP_ORGANIZATION_INACTIVE', [ 'schedule_id' => $schedule_id, 'clinic_id' => $clinic_id ] );
                    return 0;
                }

                $clinic = $this->db->fetchRowForUpdate(
                    'SELECT id, organization_id, status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [ $clinic_id ]
                );
                if (
                    $clinic === null
                    || (int) $clinic['id'] !== $clinic_id
                    || (int) $clinic['organization_id'] !== $organization_id
                    || (string) $clinic['status'] !== 'active'
                ) {
                    $this->op->warning( 'SLOTS_GEN_SKIP_CLINIC_INACTIVE', [ 'schedule_id' => $schedule_id, 'clinic_id' => $clinic_id ] );
                    return 0;
                }

                // Match ScheduleService's Clinician → Schedule write order
                // before locking Location. Schedule creation inserts a row with
                // a Location foreign key; holding Location first here would
                // invert that order and permit an InnoDB lock cycle.
                $clinician = $this->db->fetchRowForUpdate(
                    'SELECT id, clinic_id, wp_user_id, is_active FROM ' . $this->db->table( 'cpms_clinicians' ) . ' WHERE id = %d LIMIT 1',
                    [ $clinician_id ]
                );
                if (
                    $clinician === null
                    || (int) $clinician['clinic_id'] !== $home_clinic_id
                    || (int) $clinician['is_active'] !== 1
                    || (int) ( $clinician['wp_user_id'] ?? 0 ) !== (int) ( $candidate['wp_user_id'] ?? 0 )
                ) {
                    $this->op->warning(
                        'SLOTS_GEN_SKIP_CLINICIAN_CHANGED',
                        [ 'schedule_id' => $schedule_id, 'clinic_id' => $clinic_id, 'clinician_id' => $clinician_id ]
                    );
                    return 0;
                }

                if ( $clinic_id !== $home_clinic_id ) {
                    $membership_id = (int) ( $candidate['schedule_membership_id'] ?? 0 );
                    $membership   = $membership_id > 0
                        ? $this->db->fetchRowForUpdate(
                            'SELECT id FROM ' . $this->db->table( 'cpms_clinic_memberships' )
                            . ' WHERE id = %d AND wp_user_id = %d AND clinic_id = %d AND status = %s LIMIT 1',
                            [ $membership_id, (int) ( $clinician['wp_user_id'] ?? 0 ), $clinic_id, 'active' ]
                        )
                        : null;
                    if ( $membership === null ) {
                        $this->op->warning(
                            'SLOTS_GEN_SKIP_MEMBERSHIP_CHANGED',
                            [ 'schedule_id' => $schedule_id, 'clinic_id' => $clinic_id, 'clinician_id' => $clinician_id ]
                        );
                        return 0;
                    }
                }

                $schedule = $this->db->fetchRowForUpdate(
                    'SELECT id, clinic_id, location_id, clinician_id, day_of_week, start_time, end_time,
                            break_start, break_end, appointment_duration_min, slot_capacity, is_active
                     FROM ' . $this->db->table( 'cpms_schedule' ) . ' WHERE id = %d LIMIT 1',
                    [ $schedule_id ]
                );
                if ( $schedule === null || ! $this->schedule_snapshot_matches( $candidate, $schedule, $clinic_id, $location_id, $clinician_id ) ) {
                    $this->op->warning(
                        'SLOTS_GEN_SKIP_SCHEDULE_CHANGED',
                        [
                            'schedule_id'  => $schedule_id,
                            'clinic_id'    => $clinic_id,
                            'location_id'  => $location_id,
                            'clinician_id' => $clinician_id,
                        ]
                    );
                    return 0;
                }

                // Location lifecycle writers lock Organization → Clinic →
                // Location. We hold the first two rows, so after locking the
                // Clinician/Schedule pair this final Location lock is serialized
                // against deactivation without reversing ScheduleService's order.
                $location = $this->db->fetchRowForUpdate(
                    'SELECT id, clinic_id, timezone, is_active FROM ' . $this->db->table( 'cpms_locations' ) . ' WHERE id = %d LIMIT 1',
                    [ $location_id ]
                );
                if ( $location === null || (int) $location['clinic_id'] !== $clinic_id ) {
                    $this->op->warning(
                        'SLOTS_GEN_SKIP_LOCATION_CLINIC_MISMATCH',
                        [
                            'schedule_id'        => $schedule_id,
                            'clinic_id'          => $clinic_id,
                            'location_id'        => $location_id,
                            'location_clinic_id' => (int) ( $location['clinic_id'] ?? 0 ),
                        ]
                    );
                    return 0;
                }
                if ( (int) $location['is_active'] !== 1 ) {
                    $this->op->warning(
                        'SLOTS_GEN_SKIP_LOCATION_INACTIVE',
                        [
                            'schedule_id' => $schedule_id,
                            'clinic_id'   => $clinic_id,
                            'location_id' => $location_id,
                        ]
                    );
                    return 0;
                }
                if ( trim( (string) $location['timezone'] ) !== trim( $candidate_timezone ) ) {
                    $this->op->warning(
                        'SLOTS_GEN_SKIP_LOCATION_CHANGED',
                        [
                            'schedule_id' => $schedule_id,
                            'clinic_id'   => $clinic_id,
                            'location_id' => $location_id,
                        ]
                    );
                    return 0;
                }

                $plan = [
                    'duration_min' => (int) $schedule['appointment_duration_min'],
                    'capacity'     => (int) $schedule['slot_capacity'],
                ];

                return 1;
            }
        );
        if ( 1 !== $validated || ! is_array( $plan ) ) {
            return 0;
        }

        // Best-effort unlocked re-check narrows the deactivation window now that
        // no lifecycle lock is held. A deactivation that still wins the race only
        // leaves idempotent, unbookable rows behind: every booking path re-reads
        // the Location under its own lock and refuses an inactive one.
        $still_active = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_locations' ) . ' WHERE id = %d AND clinic_id = %d AND is_active = 1 LIMIT 1',
            [ $location_id, $clinic_id ]
        );
        if ( null === $still_active ) {
            $this->op->warning(
                'SLOTS_GEN_SKIP_LOCATION_INACTIVE',
                [
                    'schedule_id' => $schedule_id,
                    'clinic_id'   => $clinic_id,
                    'location_id' => $location_id,
                ]
            );
            return 0;
        }

        $generated = 0;
        foreach ( $slot_candidates as $date => $times ) {
            foreach ( $times as $time ) {
                $this->db->query(
                    'INSERT IGNORE INTO ' . $this->db->table( 'cpms_schedule_slots' ) . '
                         (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity,
                          is_open, generated_from, created_at, updated_at)
                     VALUES (%d, %d, %d, %s, %s, %d, %d, 1, %s, %s, %s)',
                    [
                        $clinic_id,
                        $location_id,
                        $clinician_id,
                        $date,
                        $time,
                        (int) $plan['duration_min'],
                        (int) $plan['capacity'],
                        $payload['source'] ?? 'cron',
                        $this->db->nowUtcSql(),
                        $this->db->nowUtcSql(),
                    ]
                );
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    private function schedule_snapshot_matches( array $candidate, array $current, int $clinic_id, int $location_id, int $clinician_id ): bool {
        if (
            (int) ( $current['clinic_id'] ?? 0 ) !== $clinic_id
            || (int) ( $current['location_id'] ?? 0 ) !== $location_id
            || (int) ( $current['clinician_id'] ?? 0 ) !== $clinician_id
            || (int) ( $current['is_active'] ?? 0 ) !== 1
            || (int) ( $candidate['schedule_is_active'] ?? 0 ) !== 1
            || (int) ( $candidate['schedule_clinic_id'] ?? 0 ) !== $clinic_id
            || (int) ( $candidate['location_id'] ?? 0 ) !== $location_id
        ) {
            return false;
        }

        foreach ( [ 'day_of_week', 'appointment_duration_min', 'slot_capacity' ] as $field ) {
            if ( (int) ( $candidate[ $field ] ?? 0 ) !== (int) ( $current[ $field ] ?? 0 ) ) {
                return false;
            }
        }
        foreach ( [ 'start_time', 'end_time', 'break_start', 'break_end' ] as $field ) {
            if ( self::normalized_time( $candidate[ $field ] ?? null ) !== self::normalized_time( $current[ $field ] ?? null ) ) {
                return false;
            }
        }

        return true;
    }

    private static function normalized_time( mixed $value ): ?string {
        if ( $value === null || (string) $value === '' ) {
            return null;
        }
        return substr( (string) $value, 0, 8 );
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
     * @param int                  $clinicId   Clinicِ ردیف برنامه (tenant معتبر همان ردیف)
     * @param int                  $locationId Locationِ همان ردیف (مبنای دامنهٔ استثناها)
     *
     * @return list<string>
     */
    private function generateDaySlots(array $clinician, int $clinicId, string $date, DateTimeImmutable $dateObj, int $locationId): array
    {
        // day_of_week: 0=شنبه ... 6=جمعه (هفته ایرانی) — تبدیل از 'w': 0=یک‌شنبه ... 6=شنبه.
        // Weekday is read off the Location-local calendar date object, so it never
        // depends on the ambient PHP timezone (no strtotime on a date-only string).
        $dow = self::toIranianDow((int) $dateObj->format('w'));
        if ($dow !== (int) $clinician['day_of_week']) {
            return [];
        }

        /*
         * Phase 4 Slice 1 — exceptions are scoped to the ROW's Clinic: a holiday
         * in one Clinic must not close the same professional's day in another
         * Clinic where participation is legitimate.
         *
         * Phase 6 Slice 6 — exceptions are additionally scoped to the ROW's
         * Location, using the contract Migration 0015 already documents:
         * `location_id IS NULL` (all Locations of THIS Clinic — the shape of
         * every historical row) OR exactly this row's `location_id`. An
         * exception pinned to Location X is therefore never handed to the
         * generator for Location Y; the day-level semantics of SlotGenerator
         * are untouched — only the SET of exceptions becomes Location-correct.
         *
         * Index note: `idx_sched_exc (clinician_id, date, type)` still serves the
         * `(clinician_id, date)` prefix (both equality predicates); `clinic_id`
         * was already a residual filter, and the new `(location_id IS NULL OR
         * location_id = %d)` predicate is evaluated on that same already-narrowed
         * row set — so the access path is unchanged and no new index is needed.
         */
        $exceptions = $this->db->fetchAll(
            'SELECT type, start_time, end_time, location_id FROM ' . $this->db->table('cpms_schedule_exceptions') .
            ' WHERE clinician_id = %d AND clinic_id = %d AND date = %s AND (location_id IS NULL OR location_id = %d)',
            [$clinician['clinician_id'], $clinicId, $date, $locationId]
        );

        $location_tz = $this->locationTimezone( (string) ( $clinician['location_timezone'] ?? '' ) );
        if ( null === $location_tz ) {
            throw new DomainException( 'Location timezone is invalid.' );
        }

        $slots = SlotGenerator::generateDay(
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

        return array_values(
            array_filter(
                $slots,
                static fn ( string $time ): bool => null !== BookingWindow::slotUtcInstant( $date, $time, $location_tz )
            )
        );
    }
}

<?php

/**
 * T3 — Reminder Location day-boundary (deterministic RED → GREEN evidence).
 *
 * Contract (ADR-0013 + phase2-tenant-context-remediation-design):
 *  appointment.location_id → persisted Location → Location timezone is the
 *  authoritative calendar context for `slot_date`; the Clinic timezone must NOT
 *  decide whether an appointment is "today/tomorrow" for reminder purposes.
 *
 * Why this class exists (D-class repair of the previous RED):
 *  The previous fixture (tests/Integration/Phase2MultiLocationTemporalRedTest.php,
 *  `testReminderDayBoundaryMultiLocation`) was NOT deterministic:
 *    (a) it used Asia/Tehran + Europe/Berlin, whose local calendar dates differ
 *        only inside a ~1.5h UTC window per day; outside that window the test
 *        "forced" a historical UTC instant for the fixture dates while the
 *        product handler kept using the real current time — so the fixture and
 *        the product disagreed about "today" at almost every execution time; and
 *    (b) its notification counts queried a non-existent column
 *        (`cpms_notifications.patient_id`; the real column is
 *        `recipient_patient_id`), so both counts were always 0 and the test could
 *        never distinguish "Location B missed" from "instrumentation broken".
 *  This class replaces it with a provably deterministic fixture and a working
 *  observation query. The assertion contract is unchanged and NOT weakened.
 *
 * Determinism argument (runtime-verified in test T1 below):
 *  Location A = Pacific/Kiritimati (UTC+14, no DST) and
 *  Location B = Pacific/Niue (UTC-11, no DST) differ by 25h, i.e. more than one
 *  whole day, so their local calendar dates can NEVER be equal at any UTC
 *  instant. The Clinic timezone is pinned to Location A's zone, therefore the
 *  Location-B calendar day is provably OUTSIDE the Clinic-level
 *  {today, tomorrow} window at every UTC instant — the pre-fix, Clinic-level
 *  implementation must therefore miss the Location-B appointment every single
 *  run (valid, deterministic RED), while a Location-authoritative implementation
 *  must remind both.
 *
 * Classification:
 *  A = regression by current work · B = pre-existing product defect
 *  C = infrastructure/environment   · D = test/test-infrastructure defect
 *
 * Real SMS is never sent: the recording provider fixture is registered and no
 * external network/credentials are used.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\ApptReminderHandler;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';
require_once __DIR__ . '/Fixtures/RecordingSmsProvider.php';

use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use ClinicCore\Tests\Integration\Fixtures\RecordingSmsProvider;

final class ReminderLocationDayBoundaryTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    /** UTC+14, no DST — real IANA zone. */
    private const TZ_A = 'Pacific/Kiritimati';

    /** UTC-11, no DST — real IANA zone (25h from TZ_A). */
    private const TZ_B = 'Pacific/Niue';

    /** Minimum guaranteed offset distance (seconds) for a deterministic date split. */
    private const MIN_OFFSET_DISTANCE = 86400;

    private RecordingSmsProvider $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->buildMultiLocationTemporalFixture();
        $this->recorder = new RecordingSmsProvider();
        App::providers()->register($this->recorder);

        // Determinism: pin the explicit Clinic scope (2 clinics exist once the
        // fixture is built) and pin the quiet-hours window CLOSED for this
        // Clinic. T3 asserts internal reminder publication only; quiet-hours
        // semantics are explicitly out of scope and are never asserted here.
        App::replaceExplicitScope(ClinicScope::forClinic(self::FX_T_CLINIC_ID));
        $settings = new Settings(App::db(), self::FX_T_CLINIC_ID, App::audit());
        $settings->set('notif.quiet_hours_start', '00:00');
        $settings->set('notif.quiet_hours_end', '00:00');
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        $this->purgeMultiLocationTemporalFixture();
        App::replaceExplicitScope(null);
        parent::tearDown();
    }

    // =================================================================
    // T3-T1 — fixture determinism preconditions (no product assertion)
    // =================================================================

    public function testFixtureTimezonePairIsDeterministicallyOffDateBoundary(): void
    {
        $topology = $this->pinDeterministicTopology();

        self::assertSame(
            self::TZ_A,
            $topology['clinicTz'],
            'fixture: persisted Clinic timezone must be ' . self::TZ_A
        );
        self::assertSame(
            self::TZ_A,
            $this->locationTimezone((int) $topology['locA']),
            'fixture: persisted Location A timezone must be ' . self::TZ_A
        );
        self::assertSame(
            self::TZ_B,
            $this->locationTimezone((int) $topology['locB']),
            'fixture: persisted Location B timezone must be ' . self::TZ_B
        );

        $utc = new DateTimeImmutable($topology['utc'], new DateTimeZone('UTC'));
        $tzA = new DateTimeZone(self::TZ_A);
        $tzB = new DateTimeZone(self::TZ_B);

        // Real-runtime acceptance of both IANA identifiers + the hard determinism bound.
        $distance = abs($tzA->getOffset($utc) - $tzB->getOffset($utc));
        self::assertGreaterThanOrEqual(
            self::MIN_OFFSET_DISTANCE,
            $distance,
            'fixture: |offset(' . self::TZ_A . ') - offset(' . self::TZ_B . ')| must be >= '
            . self::MIN_OFFSET_DISTANCE . 's so Location-local dates can never be equal (actual: ' . $distance . 's)'
        );

        // Sweep every UTC hour: the two Location-local calendar dates must differ at all 24 instants.
        $probe = $utc;
        for ($hour = 0; $hour < 24; $hour++) {
            $probe = $probe->setTime($hour, 30, 0);
            $dateA = $probe->setTimezone($tzA)->format('Y-m-d');
            $dateB = $probe->setTimezone($tzB)->format('Y-m-d');
            self::assertNotSame(
                $dateA,
                $dateB,
                'fixture: Location-local dates must differ at UTC hour ' . $hour . ' (' . $dateA . ' vs ' . $dateB . ')'
            );
        }

        // Deterministic RED precondition: Location B is outside the Clinic-level window, A is inside it.
        self::assertNotSame(
            $topology['todayA'],
            $topology['todayB'],
            'fixture: Location A/B local dates differ at the current UTC instant'
        );
        self::assertContains(
            $topology['todayA'],
            [$topology['clinicToday'], $topology['clinicTomorrow']],
            'fixture: Location A local date must be inside the Clinic-level {today, tomorrow} window'
        );
        self::assertNotContains(
            $topology['todayB'],
            [$topology['clinicToday'], $topology['clinicTomorrow']],
            'fixture: Location B local date must be OUTSIDE the Clinic-level {today, tomorrow} window'
        );
    }

    // =================================================================
    // T3-T2 — the contract itself (expected RED before the product fix)
    // =================================================================

    public function testLocationDayBoundaryMultiLocationReminder(): void
    {
        $topology = $this->pinDeterministicTopology();

        $patientA = $this->fxTInsertPatient();
        $patientB = $this->fxTInsertPatient();

        $apptA = $this->fxTInsertAppointment(
            (int) $topology['locA'],
            (string) $topology['todayA'],
            '10:00:00',
            'confirmed',
            null,
            $patientA
        );
        $apptB = $this->fxTInsertAppointment(
            (int) $topology['locB'],
            (string) $topology['todayB'],
            '10:00:00',
            'confirmed',
            null,
            $patientB
        );

        self::assertGreaterThan(0, $apptA, 'fixture: Location A appointment persisted');
        self::assertGreaterThan(0, $apptB, 'fixture: Location B appointment persisted');

        $reminded = $this->runReminderJob();
        $notifA = $this->reminderNotificationCount($apptA, $patientA);
        $notifB = $this->reminderNotificationCount($apptB, $patientB);

        if ($notifA !== 1 || $notifB !== 1) {
            self::fail($this->t3Diagnostics($topology, $apptA, $apptB, $patientA, $patientB, $notifA, $notifB, $reminded));
        }

        self::assertSame(
            1,
            $notifA,
            'Location A appointment (Location-local today) must be reminded exactly once'
        );
        self::assertSame(
            1,
            $notifB,
            'Location B appointment (Location-local today per its own Location timezone) must be reminded exactly once'
        );
    }

    // =================================================================
    // T3-T3 — positive eligible control
    // =================================================================

    public function testEligibleLocationAppointmentIsReminded(): void
    {
        $topology = $this->pinDeterministicTopology();

        $patient = $this->fxTInsertPatient();
        $appt = $this->fxTInsertAppointment(
            (int) $topology['locA'],
            (string) $topology['todayA'],
            '09:00:00',
            'confirmed',
            null,
            $patient
        );
        self::assertGreaterThan(0, $appt, 'fixture: appointment persisted');

        $this->runReminderJob();

        self::assertSame(
            1,
            $this->reminderNotificationCount($appt, $patient),
            'positive control: an appointment dated Location-local today must be reminded once'
        );
    }

    // =================================================================
    // T3-T4 — negative calendar-day control (outside the day set)
    // =================================================================

    public function testOutOfDaySetAppointmentIsNotReminded(): void
    {
        $topology = $this->pinDeterministicTopology();

        $tzA = new DateTimeZone(self::TZ_A);
        $outOfSet = (new DateTimeImmutable((string) $topology['todayA'], $tzA))
            ->modify('+5 days')
            ->format('Y-m-d');

        $patient = $this->fxTInsertPatient();
        $appt = $this->fxTInsertAppointment(
            (int) $topology['locA'],
            $outOfSet,
            '09:00:00',
            'confirmed',
            null,
            $patient
        );
        self::assertGreaterThan(0, $appt, 'fixture: appointment persisted');

        $this->runReminderJob();

        self::assertSame(
            0,
            $this->reminderNotificationCount($appt, $patient),
            'negative control: appointment dated ' . $outOfSet . ' is not in any Location day set and must not be reminded'
        );
    }

    // =================================================================
    // T3-T5 — single-Clinic / single-Location compatibility
    // =================================================================

    public function testSingleLocationClinicRemainsFunctional(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        // Dedicated Clinic with exactly ONE Location; Clinic tz == Location tz.
        $clinicId = random_int(73000, 73999);
        $this->purgeDetachedClinic($clinicId);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)'
            . ' VALUES (%d, %d, %s, %s, %s, %s, %s)',
            $clinicId,
            self::FX_T_ORG_ID,
            'T3 Single Location Clinic',
            't3-single-' . bin2hex(random_bytes(3)),
            self::TZ_A,
            $now,
            $now
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)'
            . ' VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $clinicId,
            'T3 Single Location',
            't3-single-loc-' . bin2hex(random_bytes(3)),
            self::TZ_A,
            $now,
            $now
        ));
        $locationId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at)'
            . ' VALUES (%d, %s, 1, %s, %s)',
            $clinicId,
            'Dr T3 Single',
            $now,
            $now
        ));
        $clinicianId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
            . ' VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            'MR-T3-' . bin2hex(random_bytes(3)),
            'T3',
            'Single',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $patientId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $locationId, 'fixture: single Location persisted');
        self::assertGreaterThan(0, $clinicianId, 'fixture: clinician persisted');
        self::assertGreaterThan(0, $patientId, 'fixture: patient persisted');

        $todayLocal = (new DateTimeImmutable('now', new DateTimeZone(self::TZ_A)))->format('Y-m-d');

        $appt = $this->insertAppointmentInClinic($clinicId, $locationId, $clinicianId, $patientId, $todayLocal, '10:00:00');
        self::assertGreaterThan(0, $appt, 'fixture: appointment persisted');

        $this->runReminderJob();

        self::assertSame(
            1,
            $this->reminderNotificationCount($appt, $patientId),
            'compatibility control: single-Clinic/single-Location appointment dated its own Location-local today must be reminded once'
        );

        $this->purgeDetachedClinic($clinicId);
    }

    // =================================================================
    // T3-T6 — ambient PHP default timezone independence
    // =================================================================

    public function testAmbientPhpTimezoneDoesNotChangeLocationEligibility(): void
    {
        $topology = $this->pinDeterministicTopology();

        $patient = $this->fxTInsertPatient();
        $appt = $this->fxTInsertAppointment(
            (int) $topology['locA'],
            (string) $topology['todayA'],
            '12:00:00',
            'confirmed',
            null,
            $patient
        );
        self::assertGreaterThan(0, $appt, 'fixture: appointment persisted');

        $originalTz = date_default_timezone_get();
        try {
            foreach (['Pacific/Kiritimati', 'America/New_York', 'UTC'] as $ambient) {
                date_default_timezone_set($ambient);
                $this->runReminderJob();
                self::assertSame(
                    1,
                    $this->reminderNotificationCount($appt, $patient),
                    'ambient PHP default timezone (' . $ambient . ') must not change Location-local day eligibility'
                );
            }
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Pin the Clinic/Location calendar context to the deterministic pair and
     * report the resulting topology (all values computed at runtime — no
     * hard-coded offsets or dates).
     *
     * @return array{utc:string,tzA:string,tzB:string,locA:int,locB:int,todayA:string,todayB:string,clinicToday:string,clinicTomorrow:string,clinicTz:string}
     */
    private function pinDeterministicTopology(): array
    {
        $db = App::db();
        $now = $db->nowUtcSql();

        $this->setClinicTimezone(self::FX_T_CLINIC_ID, self::TZ_A, $now);
        $this->setLocationTimezone(self::FX_T_LOC_A_ID, self::TZ_A, $now);
        $this->setLocationTimezone(self::FX_T_LOC_B_ID, self::TZ_B, $now);
        Settings::flushCache();

        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tzA = new DateTimeZone(self::TZ_A);

        return [
            'utc' => $utc->format('Y-m-d H:i:s'),
            'tzA' => self::TZ_A,
            'tzB' => self::TZ_B,
            'locA' => self::FX_T_LOC_A_ID,
            'locB' => self::FX_T_LOC_B_ID,
            'todayA' => $utc->setTimezone($tzA)->format('Y-m-d'),
            'todayB' => $utc->setTimezone(new DateTimeZone(self::TZ_B))->format('Y-m-d'),
            'clinicToday' => $utc->setTimezone($tzA)->format('Y-m-d'),
            'clinicTomorrow' => $utc->setTimezone($tzA)->modify('+1 day')->format('Y-m-d'),
            'clinicTz' => $this->clinicTimezone(self::FX_T_CLINIC_ID),
        ];
    }

    private function setClinicTimezone(int $clinicId, string $timezone, string $now): void
    {
        global $wpdb;
        $db = App::db();

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $db->table('cpms_clinics') . ' SET timezone = %s, updated_at = %s WHERE id = %d',
            $timezone,
            $now,
            $clinicId
        ));
    }

    private function setLocationTimezone(int $locationId, string $timezone, string $now): void
    {
        global $wpdb;
        $db = App::db();

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $db->table('cpms_locations') . ' SET timezone = %s, updated_at = %s WHERE id = %d',
            $timezone,
            $now,
            $locationId
        ));
    }

    private function clinicTimezone(int $clinicId): string
    {
        return (new Settings(App::db(), $clinicId, App::audit()))->clinicTimezone();
    }

    private function locationTimezone(int $locationId): string
    {
        global $wpdb;
        $db = App::db();

        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT timezone FROM ' . $db->table('cpms_locations') . ' WHERE id = %d',
            $locationId
        ));
    }

    /**
     * Invoke the real production handler with the real production dependency
     * graph and a recording SMS transport.
     *
     * The NotificationService is constructed with an explicit, Clinic-scoped
     * Settings instance — exactly the wiring App::notificationService() uses —
     * so quiet-hours resolution cannot leak in from another test's singleton.
     */
    private function runReminderJob(): int
    {
        $db = App::db();

        $handler = new ApptReminderHandler(
            $db,
            new Settings($db, self::FX_T_CLINIC_ID, App::audit()),
            App::smsService(),
            new NotificationService(
                $db,
                new NotificationRepository($db),
                new MembershipRepository($db),
                new Settings($db, self::FX_T_CLINIC_ID, App::audit()),
                App::op()
            ),
            App::op()
        );

        return $handler([]);
    }

    private function reminderNotificationCount(int $appointmentId, int $patientId): int
    {
        global $wpdb;
        $db = App::db();

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_notifications')
            . ' WHERE clinic_id = %d AND recipient_patient_id = %d AND dedupe_key LIKE %s',
            self::FX_T_CLINIC_ID,
            $patientId,
            '%apt:' . $appointmentId . ':remind:%'
        ));
    }

    private function insertAppointmentInClinic(
        int $clinicId,
        int $locationId,
        int $clinicianId,
        int $patientId,
        string $date,
        string $time
    ): int {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots'
            . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $date,
            $time,
            $now,
            $now
        ));
        $slotId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments'
            . ' (clinic_id, location_id, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, status, reference_code, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            $slotId,
            $date,
            $time,
            'confirmed',
            'T3-' . bin2hex(random_bytes(6)),
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * FK-safe removal of the detached single-Location Clinic used by T3-T5.
     */
    private function purgeDetachedClinic(int $clinicId): void
    {
        global $wpdb;
        $db = App::db();

        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_idempotency_keys') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patient_user_links') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $clinicId));

        Settings::flushCache();
    }

    /**
     * Self-identifying T3 failure evidence (synthetic diagnostics only — no
     * patient data, no secrets).
     *
     * @param array<string, mixed> $topology
     */
    private function t3Diagnostics(
        array $topology,
        int $apptA,
        int $apptB,
        int $patientA,
        int $patientB,
        int $notifA,
        int $notifB,
        int $reminded
    ): string {
        return implode(' | ', [
            'T3 REMINDER LOCATION DAY-BOUNDARY (appointment.location_id → Location timezone)',
            'utc_now=' . (string) $topology['utc'],
            'clinic_id=' . self::FX_T_CLINIC_ID . ' clinic_tz=' . (string) $topology['clinicTz'],
            'clinic_day_window=' . (string) $topology['clinicToday'] . '..' . (string) $topology['clinicTomorrow'],
            'location_A_id=' . (int) $topology['locA'] . ' tz=' . (string) $topology['tzA'] . ' local_today=' . (string) $topology['todayA'],
            'location_B_id=' . (int) $topology['locB'] . ' tz=' . (string) $topology['tzB'] . ' local_today=' . (string) $topology['todayB'],
            'appt_A_id=' . $apptA . ' date=' . (string) $topology['todayA'] . ' patient=' . $patientA . ' reminders=' . $notifA,
            'appt_B_id=' . $apptB . ' date=' . (string) $topology['todayB'] . ' patient=' . $patientB . ' reminders=' . $notifB,
            'expected_reminders=A:1,B:1 actual_reminders=A:' . $notifA . ',B:' . $notifB,
            'handler_returned=' . $reminded,
            'location_B_local_today_is_outside_clinic_day_window=true',
        ]);
    }
}

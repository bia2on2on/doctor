<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use DateTimeImmutable;
use DateInterval;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * Regression tests for Package B corrective fixes:
 *
 *  - A scheduled (staff-booked) appointment must NEVER be marked walk-in
 *    express: `is_walkin_express` stays 0 even when the slot is today. Only
 *    the explicit walk-in path (VisitService::walkIn) may set the flag.
 *  - Visit presentation (check-in / transition / getVisit) must carry
 *    non-empty `patient_name` and `clinician_name` via a separate NON-locking
 *    query scoped by `clinic_id`: tenancy holds (never a name from another
 *    clinic) and it fails closed (empty name) when the patient/clinician is
 *    not in the visit's clinic.
 */
final class PkgBWalkinAndVisitNamesRegressionTest extends WP_UnitTestCase
{
    private const CLINIC_ID = 1;

    private int $clinicianId;
    private int $foreignClinicianId;
    private int $foreignClinicId;
    private int $doctorUserId;
    private int $patientId;
    private int $foreignPatientId;
    private int $secretaryUserId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.no_show_grace_minutes', 30);

        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));

        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Primary Location of Clinic 1 (seeded by migration, Asia/Tehran).
        $this->locationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations
             WHERE clinic_id = 1 AND is_primary = 1 AND is_active = 1 LIMIT 1'
        );
        self::assertGreaterThan(0, $this->locationId);

        // Physician in Clinic 1.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)',
                self::CLINIC_ID,
                'Dr Reza Test',
                0,
                $now,
                $now
            )
        );
        $this->clinicianId = (int) $wpdb->insert_id;

        // Foreign clinic (2) sharing the default organization, for tenancy tests.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations
                     (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, "active", %s, %s)',
                'pkgb-foreign-org-' . bin2hex(random_bytes(2)),
                'pkgb-foreign-org-' . bin2hex(random_bytes(2)),
                $now,
                $now
            )
        );
        $this->foreignClinicId = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                     (organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %s, %s, "Asia/Tehran", %s, %s)',
                $this->foreignClinicId,
                'Foreign Clinic',
                'pkgb-foreign-clinic-' . bin2hex(random_bytes(2)),
                $now,
                $now
            )
        );
        $foreignClinicId = (int) $wpdb->insert_id;
        $this->foreignClinicId = $foreignClinicId;

        // Foreign physician in the foreign clinic.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)',
                $foreignClinicId,
                'Dr Foreign',
                0,
                $now,
                $now
            )
        );
        $this->foreignClinicianId = (int) $wpdb->insert_id;

        // Doctor user linked to the Clinic-1 physician (doctor-owned transitions).
        $this->doctorUserId = (int) wp_create_user('pkgb_doctor_' . bin2hex(random_bytes(3)), 'pass-12345', 'pkgb-doc@test.local');
        $doctor = get_userdata($this->doctorUserId);
        $doctor->set_role('cpms_doctor');
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d',
                $this->doctorUserId,
                $this->clinicianId
            )
        );

        // Patient in Clinic 1 (real name).
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, "Farhad", "Hosseini", %s, "active", %s, %s)',
                self::CLINIC_ID,
                'MR-PKB-0001',
                '09120000001',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        // Foreign patient in the foreign clinic.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, "Foreign", "Patient", %s, "active", %s, %s)',
                $foreignClinicId,
                'MR-PKB-F001',
                '09120000002',
                $now,
                $now
            )
        );
        $this->foreignPatientId = (int) $wpdb->insert_id;

        // Secretary (WP role path — used without explicit scope for check-in).
        $this->secretaryUserId = (int) wp_create_user('pkgb_secretary_' . bin2hex(random_bytes(3)), 'pass-12345', 'pkgb-sec@test.local');
        $secretary = get_userdata($this->secretaryUserId);
        $secretary->set_role('cpms_secretary');
    }

    protected function tearDown(): void
    {
        VisitService::setTestNowUtc(null);
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ---------- Helpers ----------

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function makeSlot(string $slotDate, string $slotTime): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                  VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)',
                self::CLINIC_ID,
                $this->locationId,
                $this->clinicianId,
                $slotDate,
                $slotTime,
                $now,
                $now
            )
        );

        return ['id' => (int) $wpdb->insert_id, 'date' => $slotDate, 'time' => $slotTime];
    }

    /**
     * Confirmed appointment in Clinic 1, slot date/time in Asia/Tehran.
     *
     * @return int appointment id
     */
    private function makeAppointment(int $patientId, int $clinicianId, string $slotDate, string $slotTime): int
    {
        $slot = $this->makeSlot($slotDate, $slotTime);
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $tz = new DateTimeZone('Asia/Tehran');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                  VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)',
                self::CLINIC_ID,
                $this->locationId,
                'appt-' . bin2hex(random_bytes(4)),
                $patientId,
                $clinicianId,
                (int) $slot['id'],
                $slotDate,
                $slotTime,
                (new DateTimeImmutable($slotDate . ' ' . $slotTime, $tz))->add(new DateInterval('PT20M'))->format('H:i:s'),
                $now,
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    // ================= Defect 1 — scheduled appointment is not walk-in =================

    public function testStaffBookedSameDayAppointmentIsNotWalkInExpress(): void
    {
        // Clinic-local today with a future slot time (staff: min lead 0).
        $tz = new DateTimeZone('Asia/Tehran');
        $nowLocal = new DateTimeImmutable('now', $tz);
        $slotDate = $nowLocal->format('Y-m-d');
        $slotTime = $nowLocal->modify('+3 hours')->format('H:i:s');

        $slot = $this->makeSlot($slotDate, $slotTime);

        $view = App::bookingService()->createByStaff(
            $this->secretaryUserId,
            $this->patientId,
            $this->clinicianId,
            $slotDate,
            $slotTime,
            'checkup reason',
            (int) $slot['id']
        );

        $this->assertSame('confirmed', $view['status']);
        // (a) — scheduled same-day appointment must NOT be walk-in express;
        //     no express badge flag in the presented row.
        $this->assertSame(0, (int) ($view['is_walkin_express'] ?? 1));
        $this->assertFalse((bool) ($view['is_walkin_express'] ?? true), 'no express badge on a scheduled appointment');

        // Persisted value agrees.
        global $wpdb;
        $persisted = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT is_walkin_express FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', (int) $view['appointment_id'])
        );
        $this->assertSame(0, $persisted);
    }

    public function testTrueWalkInExpressFlagStillReadableAsOne(): void
    {
        $tz = new DateTimeZone('Asia/Tehran');
        $nowLocal = new DateTimeImmutable('now', $tz);
        $slotDate = $nowLocal->format('Y-m-d');
        $slotTime = $nowLocal->modify('+3 hours')->format('H:i:s');

        $slot = $this->makeSlot($slotDate, $slotTime);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                      duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
                  VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 1, %s, %s, %s)',
                self::CLINIC_ID,
                $this->locationId,
                'wi-' . bin2hex(random_bytes(4)),
                $this->patientId,
                $this->clinicianId,
                (int) $slot['id'],
                $slotDate,
                $slotTime,
                (new DateTimeImmutable($slotDate . ' ' . $slotTime, $tz))->add(new DateInterval('PT20M'))->format('H:i:s'),
                $now,
                $now,
                $now
            )
        );
        $apptId = (int) $wpdb->insert_id;

        $this->assertSame(1, (int) $wpdb->get_var(
            $wpdb->prepare('SELECT is_walkin_express FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', $apptId)
        ));

        // (b) — a genuine walk-in/express appointment still gets 1: the flag is
        //     preserved and presented as express = true.
        $repo = new AppointmentRepository(App::db());
        $rows = $repo->listForDoctorOperationalDay(
            self::CLINIC_ID,
            $this->locationId,
            $this->clinicianId,
            $slotDate
        );
        $found = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $apptId) {
                $found = $row;
                break;
            }
        }
        self::assertNotNull($found, 'express appointment must appear in the presented day list');
        $this->assertSame(true, $found['express']);
    }

    // ================= Defect 2 — visit presentation carries names =================

    public function testCheckInAndTransitionResponsesCarryNames(): void
    {
        // Fixed clock: 2026-11-01 10:00 UTC = 2026-11-01 13:30 Asia/Tehran.
        VisitService::setTestNowUtc(new DateTimeImmutable('2026-11-01 10:00:00', new DateTimeZone('UTC')));
        try {
            $apptId = $this->makeAppointment($this->patientId, $this->clinicianId, '2026-11-01', '15:00:00');

            // V1 check-in (secretary) — no explicit scope: legacy WP-role path.
            App::resetScope();
            $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $apptId);
            $this->assertSame('scheduled', $visit['source']);
            $this->assertSame('waiting', $visit['status']); // FR-6.1 auto-enqueue

            // (c) — check-in response carries non-empty names after enrichment.
            $this->assertSame('Farhad Hosseini', $visit['patient_name']);
            $this->assertSame('Dr Reza Test', $visit['clinician_name']);

            // V4 call (doctor) — transition response also enriched.
            $called = App::visitService()->transition($this->doctorUserId, (int) $visit['id'], 'call', ['room' => '3']);
            $this->assertSame('called', $called['status']);
            $this->assertSame('Farhad Hosseini', $called['patient_name']);
            $this->assertSame('Dr Reza Test', $called['clinician_name']);
        } finally {
            VisitService::setTestNowUtc(null);
            App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));
        }
    }

    public function testCrossClinicIdYieldsNoForeignName(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_ID));

        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Visit in Clinic 1 whose patient AND clinician belong to the foreign clinic.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, active, created_at, updated_at)
                  VALUES (%d, %d, %d, NULL, "walk_in", "checked_in", %s, %s, 1, %s, %s)',
                self::CLINIC_ID,
                $this->foreignClinicianId,
                $this->foreignPatientId,
                gmdate('Y-m-d'),
                $now,
                $now,
                $now
            )
        );
        $visitId = (int) $wpdb->insert_id;

        // Transition (cancel, secretary) — presented row must not leak a foreign name.
        $visit = App::visitService()->transition($this->secretaryUserId, $visitId, 'cancel', ['reason' => 'ثبت اشتباه']);
        $this->assertSame('cancelled', $visit['status']);

        // (d) — cross-clinic id yields no foreign name (fail closed).
        $this->assertSame('', $visit['patient_name']);
        $this->assertNull($visit['clinician_name']);
    }
}

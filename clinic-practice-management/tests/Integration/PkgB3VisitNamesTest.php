<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;
use WP_UnitTestCase;

/**
 * Package B3 — defect 4: patient/clinician display names in API responses.
 *
 * The repository reads behind VisitService::presentVisit() (VisitRepository::find()
 * and ::findForUpdate()) are plain `SELECT * FROM cpms_visits`, so a row carries no
 * patient_first_name / patient_last_name / clinician_name keys at all;
 * presentVisit() then presents patient_name as '' and clinician_name as null in
 * every response built from those rows (check-in, walk-in, transition, getVisit).
 *
 * This test states the required end behaviour only:
 *  - the presented row of check-in / walk-in / transition / getVisit carries the
 *    Clinic-scoped patient and clinician names;
 *  - a Visit whose patient_id / clinician_id point at ANOTHER Clinic's rows
 *    yields '' / null (fail-closed) in every one of those paths and in
 *    VisitRepository::queueFor(), and never a foreign name;
 *  - the Clinic-scoped queue still contains that Visit (a mismatch must be
 *    blanked, never dropped from the queue).
 *
 * Determinism: no wall-clock dependence — the Visit clock is fixed through the
 * established VisitService::setTestNowUtc() seam and every queue read uses the
 * PERSISTED visit_date of the fixture row.
 *
 * Deliberately NOT asserted here: BookingService::appointmentView() staff
 * responses. Two existing green contracts freeze those exact key sets
 * (Phase11ReceptionAppointmentCreateRedTest::APPT_VIEW_KEYS and
 * Phase11ReceptionAppointmentRescheduleRedTest::RESCHEDULE_APPT_KEYS), so adding
 * name keys there is an owner decision, reported instead of forced.
 */
final class PkgB3VisitNamesTest extends WP_UnitTestCase
{
    private const CLINIC_ID = 1;

    /** 2026-11-01 10:00 UTC ⇒ 2026-11-01 13:30 Asia/Tehran (Clinic 1 primary Location). */
    private const FIXED_UTC = '2026-11-01 10:00:00';
    private const TODAY     = '2026-11-01';
    private const TZ        = 'Asia/Tehran';

    private const CLINIC_PATIENT_FIRST = 'Farhad';
    private const CLINIC_PATIENT_LAST  = 'Hosseini';
    private const CLINIC_CLINICIAN     = 'Dr Nazanin Karimi';
    private const FOREIGN_PATIENT_FIRST = 'Leakfirst';
    private const FOREIGN_PATIENT_LAST  = 'Leaklast';
    private const FOREIGN_CLINICIAN     = 'Dr Foreign Leak';

    private int $clinicianId;
    private int $patientId;
    private int $doctorUserId;
    private int $secretaryUserId;
    private int $locationId;
    private int $foreignClinicId;
    private int $foreignClinicianId;
    private int $foreignPatientId;

    private $filterCb = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        Settings::flushCache();
        App::migrations()->migrate();

        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('queue.no_show_grace_minutes', 30);
        App::settings()->set('queue.max_recalls', 2);

        $fixed = new DateTimeImmutable(self::FIXED_UTC, new DateTimeZone('UTC'));
        VisitService::setTestNowUtc($fixed);
        $this->filterCb = static function () use ($fixed): DateTimeImmutable {
            return $fixed;
        };
        add_filter('cpms_visit_now_utc', $this->filterCb);

        global $wpdb;

        // Clinic 1 primary Location (seeded by the migrations, Asia/Tehran).
        $this->locationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations'
            . ' WHERE clinic_id = 1 AND is_primary = 1 AND is_active = 1 LIMIT 1'
        );
        self::assertGreaterThan(0, $this->locationId, 'fixture: Clinic 1 primary Location');

        $this->clinicianId = $this->insertClinician(self::CLINIC_ID, self::CLINIC_CLINICIAN, null);
        $this->patientId   = $this->insertPatient(self::CLINIC_ID, self::CLINIC_PATIENT_FIRST, self::CLINIC_PATIENT_LAST);

        // Doctor user linked to the Clinic 1 clinician (call/start/complete ownership).
        $this->doctorUserId = $this->makeUser('pkgb3_doctor', 'cpms_doctor');
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->doctorUserId,
                $this->clinicianId
            )
        );
        self::assertSame(1, $wpdb->rows_affected);

        $this->secretaryUserId = $this->makeUser('pkgb3_secretary', 'cpms_secretary');

        // Second Clinic (same Organization) + its own clinician and patient: the
        // visit rows of the cross-Clinic cases point at THESE ids while their own
        // clinic_id stays 1. The schema allows it: fk_visit_patient and
        // fk_visit_clinician reference `id` only, with no clinic_id in the key.
        $this->foreignClinicId = $this->insertSecondClinic();
        $this->foreignClinicianId = $this->insertClinician($this->foreignClinicId, self::FOREIGN_CLINICIAN, null);
        $this->foreignPatientId   = $this->insertPatient($this->foreignClinicId, self::FOREIGN_PATIENT_FIRST, self::FOREIGN_PATIENT_LAST);
        self::assertSame(
            $this->foreignClinicId,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $this->foreignPatientId
                )
            ),
            'fixture: the foreign patient really belongs to the second Clinic'
        );

    }

    protected function tearDown(): void
    {
        VisitService::setTestNowUtc(null);
        if ($this->filterCb !== null) {
            remove_filter('cpms_visit_now_utc', $this->filterCb);
            $this->filterCb = null;
        }
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        Settings::flushCache();
        parent::tearDown();
    }

    // ================= 1 — check-in =================

    public function testCheckInResponseCarriesNames(): void
    {
        $appointmentId = $this->makeAppointment($this->patientId, $this->clinicianId, self::TODAY, '15:00:00');

        $visit = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $appointmentId);

        self::assertSame('scheduled', (string) $visit['source'], 'precondition: a scheduled check-in');
        self::assertSame(self::CLINIC_PATIENT_FIRST . ' ' . self::CLINIC_PATIENT_LAST, (string) $visit['patient_name']);
        self::assertSame(self::CLINIC_CLINICIAN, (string) $visit['clinician_name']);
    }

    // ================= 2 — transition (call / start / complete / invoice_ready / waive) =================

    public function testTransitionResponsesCarryNamesThroughCheckOut(): void
    {
        $appointmentId = $this->makeAppointment($this->patientId, $this->clinicianId, self::TODAY, '15:00:00');
        $created       = App::visitService()->checkIn($this->secretaryUserId, $this->patientId, $appointmentId);
        $visitId       = (int) $created['id'];
        self::assertGreaterThan(0, $visitId);

        $steps = [
            ['doctor', 'call', ['room' => '3'], 'called'],
            ['doctor', 'start', [], 'in_consultation'],
            ['doctor', 'complete', [], 'consultation_completed'],
            ['secretary', 'invoice_ready', [], 'awaiting_payment'],
            ['secretary', 'waive', ['reason' => 'ویزیت رایگان'], 'checked_out'],
        ];

        foreach ($steps as [$actor, $event, $meta, $expectedStatus]) {
            $actorId = 'doctor' === $actor ? $this->doctorUserId : $this->secretaryUserId;
            $visit   = App::visitService()->transition($actorId, $visitId, $event, $meta);

            self::assertSame($expectedStatus, (string) $visit['status'], 'transition ' . $event);
            self::assertSame(
                self::CLINIC_PATIENT_FIRST . ' ' . self::CLINIC_PATIENT_LAST,
                (string) $visit['patient_name'],
                'transition response must carry the patient name: ' . $event
            );
            self::assertSame(
                self::CLINIC_CLINICIAN,
                (string) $visit['clinician_name'],
                'transition response must carry the clinician name: ' . $event
            );
        }
    }

    // ================= 3 — getVisit =================

    public function testGetVisitCarriesNames(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $read  = App::visitService()->getVisit($this->secretaryUserId, (int) $visit['id']);

        self::assertSame(self::CLINIC_PATIENT_FIRST . ' ' . self::CLINIC_PATIENT_LAST, (string) $read['patient_name']);
        self::assertSame(self::CLINIC_CLINICIAN, (string) $read['clinician_name']);
    }

    // ================= 4 — walk-in =================

    public function testWalkInResponseCarriesNames(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);

        self::assertGreaterThan(0, (int) $visit['id']);
        self::assertSame(self::CLINIC_PATIENT_FIRST . ' ' . self::CLINIC_PATIENT_LAST, (string) $visit['patient_name']);
        self::assertSame(self::CLINIC_CLINICIAN, (string) $visit['clinician_name']);
    }

    // ================= 5 + 6 — cross-Clinic: no foreign name, no dropped queue row =================

    public function testCrossClinicIdsYieldNoForeignName(): void
    {
        $visitId = $this->insertVisitWithForeignNames();
        $visitDate = $this->persistedVisitDate($visitId);

        // (a) getVisit — read path.
        $read = App::visitService()->getVisit($this->secretaryUserId, $visitId);
        self::assertSame('', (string) $read['patient_name'], 'cross-Clinic patient must present an empty name');
        self::assertNull($read['clinician_name'], 'cross-Clinic clinician must present null');
        self::assertStringNotContainsString('Leak', (string) json_encode($read), 'no foreign name may leak');

        // (b) transition — write path (cancel from checked_in is a secretary event).
        $cancelled = App::visitService()->transition($this->secretaryUserId, $visitId, 'cancel', ['reason' => 'ثبت اشتباه']);
        self::assertSame('cancelled', (string) $cancelled['status']);
        self::assertSame('', (string) $cancelled['patient_name'], 'cross-Clinic patient must present an empty name after a transition');
        self::assertNull($cancelled['clinician_name'], 'cross-Clinic clinician must present null after a transition');
        self::assertStringNotContainsString('Leak', (string) json_encode($cancelled), 'no foreign name may leak');

        // (c) queueFor — the Clinic-scoped queue read. The row must stay in the
        // queue (blanked names), never disappear and never carry a foreign name.
        $queueId = $this->insertVisitWithForeignNames('checked_in');
        $queueDate = $this->persistedVisitDate($queueId);

        $queue = (new VisitRepository(App::db()))->queueFor(self::CLINIC_ID, null, ['checked_in'], $queueDate, $this->locationId);
        self::assertIsArray($queue);

        $found = null;
        foreach ($queue as $row) {
            if ((int) $row['id'] === $queueId) {
                $found = $row;
                break;
            }
        }
        self::assertNotNull($found, 'a cross-Clinic Visit must stay in the Clinic-scoped queue');
        self::assertNull($found['patient_first_name'], 'queueFor must blank a foreign patient first name');
        self::assertNull($found['patient_last_name'], 'queueFor must blank a foreign patient last name');
        self::assertNull($found['clinician_name'], 'queueFor must blank a foreign clinician name');
        self::assertStringNotContainsString('Leak', (string) json_encode($queue), 'the queue must not carry a foreign name');

        // Control: the same row read on its own visit_date is not the queue of
        // another day — the fixture date is the persisted one, not the wall clock.
        self::assertSame(self::TODAY, $queueDate);
        self::assertSame(self::TODAY, $visitDate);
    }

    public function testQueueForStillCarriesInClinicNames(): void
    {
        $visit = App::visitService()->walkIn($this->secretaryUserId, $this->patientId, $this->clinicianId);
        $queueDate = $this->persistedVisitDate((int) $visit['id']);

        $queue = (new VisitRepository(App::db()))->queueFor(self::CLINIC_ID, null, ['waiting'], $queueDate, $this->locationId);
        $found = null;
        foreach ($queue as $row) {
            if ((int) $row['id'] === (int) $visit['id']) {
                $found = $row;
                break;
            }
        }

        self::assertNotNull($found, 'the in-Clinic Visit must be in the queue');
        self::assertSame(self::CLINIC_PATIENT_FIRST, (string) $found['patient_first_name']);
        self::assertSame(self::CLINIC_PATIENT_LAST, (string) $found['patient_last_name']);
        self::assertSame(self::CLINIC_CLINICIAN, (string) $found['clinician_name']);
    }

    // ================= fixtures / helpers =================

    private function makeUser(string $prefix, string $role): int
    {
        $userId = (int) wp_create_user($prefix . '_' . bin2hex(random_bytes(3)), 'pass-12345', $prefix . '@test.local');
        self::assertGreaterThan(0, $userId, 'fixture: WP user');

        $user = get_userdata($userId);
        $user->set_role($role);

        return $userId;
    }

    private function insertClinician(int $clinicId, string $fullName, ?int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians'
                . ' (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)'
                . ' VALUES (%d, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $fullName,
                null === $wpUserId ? null : $wpUserId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: clinician row (wp_user_id never 0 — u_clinician_user)');

        return $id;
    }

    private function insertPatient(int $clinicId, string $firstName, string $lastName): int
    {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $suffix = bin2hex(random_bytes(3));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients'
                . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
                . ' VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'MR-B3-' . $suffix,
                $firstName,
                $lastName,
                '0912' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT) . substr($suffix, 0, 1),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: patient row');

        return $id;
    }

    private function insertSecondClinic(): int
    {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $suffix = bin2hex(random_bytes(4));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics'
                . ' (organization_id, name, slug, timezone, created_at, updated_at)'
                . ' VALUES ((SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id LIMIT 1), %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'B3 Foreign Clinic',
                'b3-foreign-clinic-' . $suffix,
                self::TZ,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: second Clinic row');

        return $id;
    }

    /**
     * Visit in Clinic 1 whose patient_id/clinician_id point at the SECOND
     * Clinic's rows (allowed: the visit FKs reference `id` only).
     */
    private function insertVisitWithForeignNames(string $status = 'checked_in'): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits'
                . ' (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status,'
                . ' visit_date, check_in_at, waiting_since, active, created_at, updated_at)'
                . ' VALUES (%d, %d, %d, %d, NULL, "walk_in", %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_ID,
                $this->locationId,
                $this->foreignClinicianId,
                $this->foreignPatientId,
                $status,
                self::TODAY,
                $now,
                $now,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: Visit in Clinic 1 with foreign patient/clinician ids');

        return $id;
    }

    private function persistedVisitDate(int $visitId): string
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT visit_date FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
        self::assertIsString($value, 'fixture: persisted visit_date must be readable');

        return (string) $value;
    }

    private function makeAppointment(int $patientId, int $clinicianId, string $slotDate, string $slotTime): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots'
                . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min,'
                . ' capacity, booked_count, held_count, is_open, created_at, updated_at)'
                . ' VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_ID,
                $this->locationId,
                $clinicianId,
                $slotDate,
                $slotTime,
                $now,
                $now
            )
        );
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'fixture: schedule slot');

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments'
                . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,'
                . ' duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)'
                . ' VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::CLINIC_ID,
                $this->locationId,
                'B3-' . bin2hex(random_bytes(6)),
                $patientId,
                $clinicianId,
                $slotId,
                $slotDate,
                $slotTime,
                (new DateTimeImmutable($slotDate . ' ' . $slotTime, new DateTimeZone(self::TZ)))
                    ->add(new DateInterval('PT20M'))
                    ->format('H:i:s'),
                $now,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: appointment row');

        return $id;
    }
}

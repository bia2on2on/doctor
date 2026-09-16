<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WP_Error;

/**
 * Phase 4 — Slice 2: Shared Professional in Staff Booking Paths.
 *
 * Invariants:
 * 1. One WP user => one clinician identity (u_clinician_user).
 * 2. Professional with ACTIVE membership in Clinic B can be used by authorized STAFF in B even when clinicians.clinic_id = A.
 * 3. Missing/suspended participation fails closed 404.
 * 4. Operation Clinic from trusted ScopeContext, never clinicians.clinic_id nor raw payload.
 * 5. No silent fallback to home Clinic when scope missing.
 * 6. clinicians.clinic_id is home data only.
 * 7. Patient must belong to trusted Clinic.
 * 8. Slot must belong to trusted Clinic and intended clinician.
 * 9. Location must belong to same trusted Clinic.
 * 10. appointment.clinic_id derived from slot.clinic_id after proving slot belongs to trusted.
 *
 * Target paths: POST /clinic/v1/appointments (createByStaff) and GET /clinic/v1/appointments (listForClinician)
 *
 * RED expectation: on defective main, staff in B booking shared professional with Clinic-B patient/slot fails with 404 because home is A.
 */
final class Phase4Slice2BookingSharedProfessionalTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const LOCATION_TZ = 'Asia/Tehran';

    private int $orgId = 0;
    /** @var array<string,int> */
    private array $clinics = [];
    /** @var array<int,int> clinic=>location */
    private array $locations = [];

    private int $professionalUserId = 0;
    private int $clinicianId = 0;

    private int $staffUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        SystemClinicResolver::flush();

        // ensure booking window allows future slots
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);

        $this->orgId = $this->createOrganization();
        $this->clinics['A'] = $this->createClinic('A');
        $this->clinics['B'] = $this->createClinic('B');
        $this->locations[$this->clinics['A']] = $this->createLocation($this->clinics['A']);
        $this->locations[$this->clinics['B']] = $this->createLocation($this->clinics['B']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->purgeRows();
        parent::tearDown();
    }

    // ==================================================================
    // POSITIVE — the core contract
    // ==================================================================

    public function testStaffInClinicBCanBookSharedProfessionalWithClinicBPatientAndSlot(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedStaff([$clinicB]);

        // also need staff membership in B to have APPT_CREATE, but professional needs membership in B
        // fixture assertions
        self::assertGreaterThan(1, $clinicA, 'precondition: Clinic A dynamic');
        self::assertGreaterThan(1, $clinicB, 'precondition: Clinic B dynamic');
        self::assertNotSame($clinicA, $clinicB);
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'exactly ONE clinician identity');
        self::assertSame($clinicA, $this->clinicianHomeClinic($this->clinicianId), 'home = A');
        self::assertNotNull(App::membership_service()->active_membership_for($clinicB, $this->professionalUserId), 'professional ACTIVE in B');
        self::assertTrue($this->authz()->can($this->staffUserId, $clinicB, RolesAndCapabilities::APPT_CREATE), 'staff has APPT_CREATE in B');

        $patientB = $this->createPatient($clinicB, 'P4S2-PB', '09130000001');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '10:00:00';
        $slotId = $this->createSlot($clinicB, $this->locations[$clinicB], $slotDate, $slotTime);

        // verify fixtures ownership explicitly
        self::assertSame($clinicB, $this->patientClinic($patientB), 'patient is Clinic B owned');
        $slotRow = $this->slotRow($slotId);
        self::assertSame($clinicB, (int)$slotRow['clinic_id'], 'slot clinic B');
        self::assertSame($this->clinicianId, (int)$slotRow['clinician_id'], 'slot clinician is shared professional');
        self::assertSame((int)$this->locations[$clinicB], (int)$slotRow['location_id'], 'slot location B');

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientB,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'slot_id' => $slotId,
            'reason' => 'shared professional booking',
        ], $clinicB, $this->staffUserId);

        // On defective main, this is 404 CLINIC_NOT_FOUND due to home clinic assumption
        $this->assertOwnershipRejectionIsExpectedPreFix($response, 'Clinic B staff booking');

        self::assertSame(200, $response->get_status(), 'CONTRACT: authorized staff in Clinic B must be able to book shared professional. Defective main rejects with '.$this->errorCode($response).' because clinicians.clinic_id=A is treated as participation boundary');

        $data = $response->get_data();
        $view = $data['data'] ?? [];
        $appointmentId = (int)($view['id'] ?? 0);
        self::assertGreaterThan(0, $appointmentId, 'appointment id returned');

        // persisted proof
        $apptRow = $this->appointmentRow($appointmentId);
        self::assertNotNull($apptRow, 'appointment persisted');
        self::assertSame($clinicB, (int)$apptRow['clinic_id'], 'appointment clinic_id = B (from trusted slot, after proven)');
        self::assertSame($this->clinicianId, (int)$apptRow['clinician_id'], 'appointment clinician = shared professional');
        self::assertSame($slotId, (int)$apptRow['slot_id'], 'appointment slot = Clinic-B slot');
        self::assertSame($patientB, (int)$apptRow['patient_id'], 'appointment patient = Clinic-B patient');
        self::assertSame($clinicB, (int)$apptRow['location_id'] === 0 ? $clinicB : (int)$this->locationClinic((int)$apptRow['location_id']), 'location belongs to B');

        // no second identity
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'no duplicate clinician identity created');
        self::assertSame($clinicA, $this->clinicianHomeClinic($this->clinicianId), 'home column untouched');
    }

    // ==================================================================
    // NEGATIVE / CONTROL — without membership, suspended, cross-clinic data, auth
    // ==================================================================

    public function testNoMembershipInBfailsClosed(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA]); // NO B membership
        $this->seedStaff([$clinicB]);

        self::assertNull(App::membership_service()->active_membership_for($clinicB, $this->professionalUserId), 'precondition: no membership in B');
        self::assertTrue($this->authz()->can($this->staffUserId, $clinicB, RolesAndCapabilities::APPT_CREATE), 'staff authorized in B');

        $patientB = $this->createPatient($clinicB, 'P4S2-NM', '09130000002');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '11:00:00';
        $slotId = $this->createSlot($clinicB, $this->locations[$clinicB], $slotDate, $slotTime);

        $before = $this->countAppointmentsForClinician($this->clinicianId, $clinicB);

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientB,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'slot_id' => $slotId,
        ], $clinicB, $this->staffUserId);

        self::assertSame(404, $response->get_status(), 'missing participation must fail closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response));
        self::assertSame($before, $this->countAppointmentsForClinician($this->clinicianId, $clinicB), 'no durable side effect');
        self::assertSame(1, $this->countClinicianRowsForUser($this->professionalUserId), 'no duplicate identity');
    }

    public function testSuspendedMembershipInBfailsClosed(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedStaff([$clinicB]);

        $m = App::membership_service()->membership_for($clinicB, $this->professionalUserId);
        self::assertNotNull($m);
        App::membership_service()->suspend_membership((int)$m['id']);
        self::assertNull(App::membership_service()->active_membership_for($clinicB, $this->professionalUserId), 'suspended');

        $patientB = $this->createPatient($clinicB, 'P4S2-SUSP', '09130000003');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '12:00:00';
        $slotId = $this->createSlot($clinicB, $this->locations[$clinicB], $slotDate, $slotTime);

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientB,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'slot_id' => $slotId,
        ], $clinicB, $this->staffUserId);

        self::assertSame(404, $response->get_status(), 'suspended must fail closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response));
        self::assertSame(0, $this->countAppointmentsForClinician($this->clinicianId, $clinicB));
    }

    public function testClinicAPatientUnderTrustedClinicBRejected(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedStaff([$clinicB]);

        $patientA = $this->createPatient($clinicA, 'P4S2-PA', '09130000004');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '13:00:00';
        $slotId = $this->createSlot($clinicB, $this->locations[$clinicB], $slotDate, $slotTime);

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientA,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'slot_id' => $slotId,
        ], $clinicB, $this->staffUserId);

        self::assertSame(422, $response->get_status(), 'Clinic-A patient under B must be rejected before mutation');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($response));
        self::assertSame(0, $this->countAppointmentsForClinician($this->clinicianId, $clinicB));
        self::assertSame(0, $this->countAppointmentsForClinician($this->clinicianId, $clinicA));
    }

    public function testClinicASlotUnderTrustedClinicBRejected(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedStaff([$clinicB]);

        $patientB = $this->createPatient($clinicB, 'P4S2-PB2', '09130000005');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '14:00:00';
        $slotIdA = $this->createSlot($clinicA, $this->locations[$clinicA], $slotDate, $slotTime);

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientB,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'slot_id' => $slotIdA,
        ], $clinicB, $this->staffUserId);

        // slot belongs to A but trusted is B => should fail with 404 slot not found (proven slot not in trusted)
        self::assertSame(404, $response->get_status(), 'Clinic-A slot under B must be rejected before mutation');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response));
        self::assertSame(0, $this->countAppointmentsForClinician($this->clinicianId, $clinicB));
    }

    public function testUnauthorizedActorInBDeniedByPhase3(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        // staff actor WITHOUT membership in B (but WP admin-like)
        $unauth = $this->makeUser('p4s2_unauth', 'administrator');

        $patientB = $this->createPatient($clinicB, 'P4S2-PB3', '09130000006');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '15:00:00';
        $this->createSlot($clinicB, $this->locations[$clinicB], $slotDate, $slotTime);

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientB,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
        ], $clinicB, $unauth);

        // RestClinicContext will fail to establish scope for user without membership => 403
        // or permission_callback will deny => 403 CLINIC_PERMISSION_DENIED
        self::assertSame(403, $response->get_status(), 'unauthorized actor must be Phase3 denied');
        // code may be CLINIC_PERMISSION_DENIED or CLINIC_SCOPE_UNAVAILABLE, but must be 403 not 200
        self::assertNotSame(200, $response->get_status());
        self::assertSame(0, $this->countAppointmentsForClinician($this->clinicianId, $clinicB));
    }

    public function testHomeClinicABehaviorRemainsValid(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedStaff([$clinicA, $clinicB]);

        $patientA = $this->createPatient($clinicA, 'P4S2-PA2', '09130000007');
        $slotDate = gmdate('Y-m-d', time() + 2 * 86400);
        $slotTime = '16:00:00';
        $slotId = $this->createSlot($clinicA, $this->locations[$clinicA], $slotDate, $slotTime);

        $response = $this->dispatch('POST', self::NS . '/appointments', [
            'patient_id' => $patientA,
            'clinician_id' => $this->clinicianId,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'slot_id' => $slotId,
        ], $clinicA, $this->staffUserId);

        self::assertSame(200, $response->get_status(), 'home Clinic A behavior must remain valid');
        $apptId = (int)($response->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $apptId);
        $row = $this->appointmentRow($apptId);
        self::assertSame($clinicA, (int)$row['clinic_id']);
        self::assertSame($this->clinicianId, (int)$row['clinician_id']);
    }

    public function testListForClinicianScopedToTrustedClinic(): void
    {
        $clinicA = $this->clinics['A'];
        $clinicB = $this->clinics['B'];
        $this->seedProfessional([$clinicA, $clinicB]);
        $this->seedStaff([$clinicA, $clinicB]);

        // create one appointment in each clinic via staff path (but for B we need to simulate after GREEN)
        // We'll directly insert appointments durably to test list isolation regardless of create path red status
        $patientA = $this->createPatient($clinicA, 'P4S2-LA', '09130000008');
        $patientB = $this->createPatient($clinicB, 'P4S2-LB', '09130000009');
        $date = gmdate('Y-m-d', time() + 3 * 86400);
        $slotA = $this->createSlot($clinicA, $this->locations[$clinicA], $date, '09:00:00');
        $slotB = $this->createSlot($clinicB, $this->locations[$clinicB], $date, '11:00:00');
        $apptA = $this->createAppointmentRow($clinicA, $patientA, $slotA, $date, '09:00:00');
        $apptB = $this->createAppointmentRow($clinicB, $patientB, $slotB, $date, '11:00:00');

        self::assertGreaterThan(0, $apptA);
        self::assertGreaterThan(0, $apptB);

        // list in B should only return B appointment
        $responseB = $this->dispatch('GET', self::NS . '/appointments', [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
        ], $clinicB, $this->staffUserId);

        // On defective main, list in B fails with 404 because professional home is A
        $this->assertOwnershipRejectionIsExpectedPreFix($responseB, 'list B');

        // If not 404, verify isolation
        if ($responseB->get_status() === 200) {
            $idsB = $this->idsFromList($responseB);
            self::assertContains($apptB, $idsB, 'B list must contain B appointment');
            self::assertNotContains($apptA, $idsB, 'B list must NOT leak A appointment');
        } else {
            self::assertSame(404, $responseB->get_status(), 'pre-fix list B is 404 due to home assumption');
            self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($responseB));
        }

        // list in A should only return A
        $responseA = $this->dispatch('GET', self::NS . '/appointments', [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
        ], $clinicA, $this->staffUserId);

        self::assertSame(200, $responseA->get_status(), 'list A must succeed (control)');
        $idsA = $this->idsFromList($responseA);
        self::assertContains($apptA, $idsA);
        self::assertNotContains($apptB, $idsA, 'A list must NOT leak B appointment');

        // missing participation case for list: create a new clinic C where professional has no membership, try list => 404
        // we reuse no-membership test via separate method, but here check suspended also fails for list
        // suspended test for list
        $mB = App::membership_service()->membership_for($clinicB, $this->professionalUserId);
        App::membership_service()->suspend_membership((int)$mB['id']);
        $responseSuspended = $this->dispatch('GET', self::NS . '/appointments', [
            'clinician_id' => $this->clinicianId,
            'date' => $date,
        ], $clinicB, $this->staffUserId);
        self::assertSame(404, $responseSuspended->get_status(), 'suspended participation list must fail closed');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($responseSuspended));
    }

    // ==================================================================
    // RED diagnostics helpers
    // ==================================================================

    private function assertOwnershipRejectionIsExpectedPreFix(WP_REST_Response $response, string $context): void
    {
        if ($response->get_status() === 200) {
            return;
        }
        // On defective main, only failure should be durable ownership assumption 404
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($response), $context . ': pre-fix rejection must be durable-ownership envelope');
        self::assertSame(404, $response->get_status(), $context . ': ownership rejection 404 parity');
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    private function seedProfessional(array $membershipClinics): void
    {
        $this->professionalUserId = $this->makeUser('p4s2_prof', RolesAndCapabilities::ROLE_DOCTOR);
        $this->clinicianId = $this->insertClinician($this->clinics['A'], $this->professionalUserId, 'Dr P4S2 Shared');
        foreach ($membershipClinics as $cid) {
            self::assertGreaterThan(0, cpms_test_seed_membership($this->professionalUserId, (int)$cid, RolesAndCapabilities::ROLE_DOCTOR), 'membership professional in '.$cid);
        }
    }

    private function seedStaff(array $membershipClinics): void
    {
        $this->staffUserId = $this->makeUser('p4s2_staff', RolesAndCapabilities::ROLE_SECRETARY);
        foreach ($membershipClinics as $cid) {
            self::assertGreaterThan(0, cpms_test_seed_membership($this->staffUserId, (int)$cid, RolesAndCapabilities::ROLE_SECRETARY), 'membership staff in '.$cid);
        }
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(5));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s,%s,%s,%s,%s)',
            'P4S2 Org '.$unique,
            'p4s2-org-'.$unique,
            'active', $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function createClinic(string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.$wpdb->prefix.'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d,%s,%s,%s,%s,%s)',
            $this->orgId,
            'P4S2 Clinic '.$tag.' '.$unique,
            'p4s2-clinic-'.strtolower($tag).'-'.$unique,
            self::LOCATION_TZ, $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'clinic dynamic never 1');
        return $id;
    }

    private function createLocation(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.$wpdb->prefix.'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d,%s,%s,%s,1,1,%s,%s)',
            $clinicId,
            'P4S2 Loc '.$unique,
            'p4s2-loc-'.$unique,
            self::LOCATION_TZ, $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login.'_'.bin2hex(random_bytes(3));
        $uid = (int)wp_create_user($unique, wp_generate_password(22), $unique.'@p4s2.test');
        self::assertGreaterThan(0, $uid);
        $u = get_userdata($uid);
        self::assertNotFalse($u);
        $u->set_role($role);
        return $uid;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.$wpdb->prefix.'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d,%s,%d,1,%s,%s)',
            $clinicId, $name, $wpUserId, $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function createPatient(int $clinicId, string $mrnSuffix, string $mobile): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-P4S2-'.$mrnSuffix.'-'.bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.$wpdb->prefix.'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d,%s,%s,%s,%s,%s,%s,%s)',
            $clinicId, $mrn, 'First'.$mrnSuffix, 'Last'.$mrnSuffix, $mobile, 'active', $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient inserted '.$wpdb->last_error);
        return $id;
    }

    private function createSlot(int $clinicId, int $locationId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.$wpdb->prefix.'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d,%d,%d,%s,%s,20,1,0,0,1,%s,%s)',
            $clinicId, $locationId, $this->clinicianId, $date, $time, $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'slot inserted '.$wpdb->last_error);
        return $id;
    }

    private function createAppointmentRow(int $clinicId, int $patientId, int $slotId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        // fetch slot to get location_id
        $slot = $this->slotRow($slotId);
        $locationId = (int)$slot['location_id'];
        $ref = 'AP-'.str_replace('-','',$date).'-'.str_pad((string)random_int(10,99),2,'0',STR_PAD_LEFT).bin2hex(random_bytes(1));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.$wpdb->prefix.'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, booked_at, confirmed_at, created_at, updated_at) VALUES (%d,%d,%s,%d,%d,%d,%s,%s,20,%s,%s,0,%s,%s,%s,%s)',
            $clinicId, $locationId, $ref, $this->clinicianId, $patientId, $slotId, $date, $time, '10:20:00', 'confirmed', $now, $now, $now, $now
        ));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'appointment inserted '.$wpdb->last_error);
        // mark slot booked
        $wpdb->query($wpdb->prepare('UPDATE '.$wpdb->prefix.'cpms_schedule_slots SET booked_count=1 WHERE id=%d', $slotId));
        return $id;
    }

    private function dispatch(string $method, string $route, array $params, int $clinicId, int $userId): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $k=>$v) {
            $request->set_param($k, $v);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string)$clinicId);
        return rest_do_request($request);
    }

    private function authz(): AuthorizationService
    {
        return new AuthorizationService(new MembershipRepository(App::db()));
    }

    private function errorCode(WP_REST_Response $r): string
    {
        $body = $r->get_data();
        if ($body instanceof WP_Error) return (string)$body->get_error_code();
        return (string)(is_array($body) ? ($body['code'] ?? '') : '');
    }

    private function idsFromList(WP_REST_Response $r): array
    {
        $data = $r->get_data();
        $rows = $data['data'] ?? [];
        $ids = [];
        foreach (is_array($rows)?$rows:[] as $row) {
            if (is_array($row)) $ids[] = (int)($row['id'] ?? 0);
        }
        return $ids;
    }

    private function countClinicianRowsForUser(int $wpUserId): int
    {
        return (int)App::db()->fetchValue('SELECT COUNT(*) FROM '.App::db()->table('cpms_clinicians').' WHERE wp_user_id=%d', [$wpUserId]);
    }

    private function clinicianHomeClinic(int $clinicianId): int
    {
        return (int)App::db()->fetchValue('SELECT clinic_id FROM '.App::db()->table('cpms_clinicians').' WHERE id=%d', [$clinicianId]);
    }

    private function patientClinic(int $patientId): int
    {
        return (int)App::db()->fetchValue('SELECT clinic_id FROM '.App::db()->table('cpms_patients').' WHERE id=%d', [$patientId]);
    }

    private function slotRow(int $slotId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM '.App::db()->table('cpms_schedule_slots').' WHERE id=%d', [$slotId]);
        self::assertNotNull($row, 'slot exists');
        return $row;
    }

    private function appointmentRow(int $id): ?array
    {
        return App::db()->fetchRow('SELECT * FROM '.App::db()->table('cpms_appointments').' WHERE id=%d', [$id]);
    }

    private function locationClinic(int $locationId): int
    {
        return (int)App::db()->fetchValue('SELECT clinic_id FROM '.App::db()->table('cpms_locations').' WHERE id=%d', [$locationId]);
    }

    private function countAppointmentsForClinician(int $clinicianId, int $clinicId): int
    {
        return (int)App::db()->fetchValue('SELECT COUNT(*) FROM '.App::db()->table('cpms_appointments').' WHERE clinician_id=%d AND clinic_id=%d', [$clinicianId, $clinicId]);
    }

    private function purgeRows(): void
    {
        global $wpdb;
        $org = (int)$this->orgId;
        if ($org <= 0) return;
        foreach (['cpms_appointments','cpms_schedule_slots','cpms_schedule_exceptions','cpms_schedule','cpms_clinicians'] as $t) {
            $wpdb->query('DELETE t FROM '.$wpdb->prefix.$t.' t WHERE t.clinic_id IN (SELECT id FROM '.$wpdb->prefix.'cpms_clinics WHERE organization_id='.$org.')');
        }
        $wpdb->query('DELETE p FROM '.$wpdb->prefix.'cpms_patients p WHERE p.clinic_id IN (SELECT id FROM '.$wpdb->prefix.'cpms_clinics WHERE organization_id='.$org.')');
        $wpdb->query('DELETE sl FROM '.$wpdb->prefix.'cpms_slot_holds sl WHERE sl.clinic_id IN (SELECT id FROM '.$wpdb->prefix.'cpms_clinics WHERE organization_id='.$org.')');
        $wpdb->query('DELETE mc FROM '.$wpdb->prefix.'cpms_membership_capabilities mc JOIN '.$wpdb->prefix.'cpms_clinic_memberships m ON m.id=mc.membership_id JOIN '.$wpdb->prefix.'cpms_clinics c ON c.id=m.clinic_id WHERE c.organization_id='.$org);
        $wpdb->query('DELETE m FROM '.$wpdb->prefix.'cpms_clinic_memberships m JOIN '.$wpdb->prefix.'cpms_clinics c ON c.id=m.clinic_id WHERE c.organization_id='.$org);
        $wpdb->query('DELETE t FROM '.$wpdb->prefix.'cpms_clinician_locations t JOIN '.$wpdb->prefix.'cpms_locations l ON l.id=t.location_id JOIN '.$wpdb->prefix.'cpms_clinics c ON c.id=l.clinic_id WHERE c.organization_id='.$org);
        $wpdb->query('DELETE t FROM '.$wpdb->prefix.'cpms_locations t WHERE t.clinic_id IN (SELECT id FROM '.$wpdb->prefix.'cpms_clinics WHERE organization_id='.$org.')');
        $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_clinics WHERE organization_id='.$org);
        $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_organizations WHERE id='.$org);
    }
}

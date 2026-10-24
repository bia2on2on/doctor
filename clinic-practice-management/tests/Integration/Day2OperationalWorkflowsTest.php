<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\FollowUpRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Day-2 Operational Frontend & Scoped REST Workflows Integration Suite.
 *
 * Covers the four Day-2 operational priorities without touching PR #262's
 * security-boundary files:
 *  - Priority A: Patient self-service reschedule slot lookup
 *    (`GET /clinic/v1/appointments/{id}/reschedule-options`) — pure patient
 *    ownership, active link + active patient check, same-Location filtering,
 *    current-slot exclusion, active-visit / cancelled refusal, zero authority
 *    or PHI leakage.
 *  - Priority B: Receptionist Staff Portal no-show action
 *    (`POST /clinic/v1/staff/portal/reception/appointments/{id}/no-show`) —
 *    `APPT_NO_SHOW` capability + `DENY > GRANT`, required reason validation,
 *    trusted Clinic/Location isolation, and `BookingService::markNoShowByStaff`
 *    delegation.
 *  - Priority C: Doctor Visit Workspace relevant medical history
 *    (`GET /clinic/v1/doctor/portal/visits/{id}/medical-history`) — same-Clinic
 *    prior visits, `patient_visible` vs own `doctor_private` note privacy
 *    (`SRS A-5 + ADR-0027 §5 + ADR-0026 D-7`), `RX_READ` prescription gating,
 *    and `MEDICAL_RECORD_VIEWED` audit trail.
 *  - Priority D: Accountant Staff Portal daily reconciliation
 *    (`GET /clinic/v1/staff/portal/finance/reconciliation`) — WP `subscriber`
 *    with `cpms_accountant` membership (`FINANCE_READ` + `INVOICE_READ`, no
 *    `wp-admin` or `QUEUE_CHECKOUT` inflation), Location-local date window,
 *    captured vs voided aggregation, foreign-Location exclusion, and PHI-free
 *    ledger rows.
 *  - JS & Template Behavioral Gate: runs `tests/js/day2-portal-workflows.spec.js`
 *    against the shipped JS and template files via Node.
 */
final class Day2OperationalWorkflowsTest extends WP_UnitTestCase
{
    private const TZ = 'Asia/Tehran';

    protected function tearDown(): void
    {
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testDay2PortalJsAndTemplateSpecSuitePasses(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('node is not available in this environment.');
        }

        $script = \dirname(__DIR__) . '/js/day2-portal-workflows.spec.js';
        self::assertFileExists($script);

        $output   = [];
        $exitCode = 0;
        exec($node . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            "day2-portal-workflows.spec.js failed:\n" . implode("\n", $output)
        );
        self::assertStringContainsString('OK:', implode("\n", $output));
    }

    public function testPatientRescheduleOptionsEnforcesOwnershipLocationAndZeroAuthorityLeak(): void
    {
        $org     = $this->insertOrg('D2A Org');
        $clinicA = $this->insertClinic('D2A Clinic A', $org);
        $clinicF = $this->insertClinic('D2A Clinic F', $org);
        $locA    = $this->insertLocation($clinicA, 'D2A Branch A', 1);
        $locB    = $this->insertLocation($clinicA, 'D2A Branch B', 0);
        $locF    = $this->insertLocation($clinicF, 'D2A Foreign Branch', 1);

        $docUser   = $this->makeUser('d2a_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('دکتر رضایی', $clinicA, 1, $docUser);
        cpms_test_seed_membership($docUser, $clinicA, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($clinician, [$locA, $locB], $locA);

        $patientUser  = $this->makeUser('d2a_pt1', RolesAndCapabilities::ROLE_PATIENT);
        $otherPtUser  = $this->makeUser('d2a_pt2', RolesAndCapabilities::ROLE_PATIENT);
        $patientA     = $this->insertPatient($clinicA, 'PA');
        $otherPatient = $this->insertPatient($clinicA, 'PB');
        $patientLink  = $this->linkPatient($clinicA, $patientA, $patientUser, '09121112233');
        $this->linkPatient($clinicA, $otherPatient, $otherPtUser, '09124445566');

        $tomorrow   = $this->localDate(self::TZ, '+1 day');
        $dayAfter   = $this->localDate(self::TZ, '+2 days');
        $curSlot    = $this->insertSlot($clinicA, $locA, $clinician, $tomorrow, '09:00:00', 1, ['booked' => 1]);
        $altSlotLocA = $this->insertSlot($clinicA, $locA, $clinician, $dayAfter, '10:30:00', 2, ['booked' => 0]);
        $slotLocB   = $this->insertSlot($clinicA, $locB, $clinician, $dayAfter, '11:00:00', 2, ['booked' => 0]);
        $this->insertSlot($clinicF, $locF, $clinician, $dayAfter, '12:00:00', 2, ['booked' => 0]);

        $apptId = $this->insertAppointment($clinicA, $locA, $patientA, $clinician, $curSlot, $tomorrow, '09:00:00', 'confirmed');

        // 1. Foreign patient denied with 403 + FORBIDDEN_ACCESS_ATTEMPT audit.
        wp_set_current_user($otherPtUser);
        $denied = $this->dispatch('GET', '/clinic/v1/appointments/' . $apptId . '/reschedule-options');
        self::assertSame(403, $denied->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($denied));
        self::assertGreaterThan(0, $this->auditCount('FORBIDDEN_ACCESS_ATTEMPT', $apptId));
        $foreignCancel = $this->dispatch('POST', '/clinic/v1/appointments/' . $apptId . '/cancel');
        self::assertSame(403, $foreignCancel->get_status(), 'Direct REST cancellation of another patient appointment must be denied.');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($foreignCancel));

        // 2. Owning patient receives a bounded appointment projection with clear,
        // server-resolved display details and same-Location reschedule choices.
        wp_set_current_user($patientUser);
        $mine = $this->dispatch('GET', '/clinic/v1/appointments/mine', [
            'from' => $tomorrow,
            'to'   => $dayAfter,
        ]);
        self::assertSame(200, $mine->get_status());
        $mineRows = $this->payload($mine);
        self::assertCount(1, $mineRows);
        self::assertSame($apptId, (int) $mineRows[0]['appointment_id']);
        self::assertSame('D2A Clinic A', $mineRows[0]['clinic_name']);
        self::assertSame('D2A Branch A', $mineRows[0]['location_name']);
        self::assertSame('دکتر رضایی', $mineRows[0]['clinician_name']);
        self::assertSame('بیمار آزمایشی PA', $mineRows[0]['patient_name']);

        $ok = $this->dispatch('GET', '/clinic/v1/appointments/' . $apptId . '/reschedule-options');
        self::assertSame(200, $ok->get_status());
        $data = $this->payload($ok);
        self::assertSame($apptId, $data['appointment_id']);
        self::assertSame('دکتر رضایی', $data['clinician_name']);

        $returnedSlotIds = [];
        foreach ((array) ($data['days'] ?? []) as $day) {
            foreach ((array) ($day['slots'] ?? []) as $slot) {
                $returnedSlotIds[] = (int) $slot['slot_id'];
            }
        }
        self::assertContains($altSlotLocA, $returnedSlotIds, 'same-Location open future slot must be offered');
        self::assertNotContains($curSlot, $returnedSlotIds, 'current slot must be excluded');
        self::assertNotContains($slotLocB, $returnedSlotIds, 'other-Location slot must never leak into same-Location reschedule options');

        $encoded = (string) wp_json_encode($data);
        foreach (['clinic_id', 'location_id', 'patient_id', 'organization_id', 'mrn', 'mobile', 'national_id'] as $forbiddenKey) {
            self::assertStringNotContainsString('"' . $forbiddenKey . '"', $encoded);
        }

        // A stale/malformed link is not authority: Clinic consistency and active
        // Patient state are enforced identically by list, options and mutations.
        self::assertSame(1, App::db()->update('cpms_patient_user_links', ['clinic_id' => $clinicF], ['id' => $patientLink]));
        $mismatchedMine = $this->dispatch('GET', '/clinic/v1/appointments/mine', [
            'from' => $tomorrow,
            'to'   => $dayAfter,
        ]);
        self::assertSame(200, $mismatchedMine->get_status());
        self::assertSame([], $this->payload($mismatchedMine), 'A Clinic-mismatched link must not list the appointment.');
        $mismatchedOptions = $this->dispatch('GET', '/clinic/v1/appointments/' . $apptId . '/reschedule-options');
        self::assertSame(403, $mismatchedOptions->get_status());
        $mismatchedReschedule = $this->dispatch(
            'POST',
            '/clinic/v1/appointments/' . $apptId . '/reschedule',
            [
                'clinician_id' => $clinician,
                'slot_id'      => $altSlotLocA,
                'slot_date'    => $dayAfter,
                'slot_time'    => '10:30:00',
            ],
            ['Idempotency-Key' => '123e4567-e89b-42d3-a456-4266141740a1']
        );
        self::assertSame(403, $mismatchedReschedule->get_status(), 'Malformed ownership must deny the direct REST mutation too.');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($mismatchedReschedule));
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_idempotency_keys') . ' WHERE `key` = %s',
            ['123e4567-e89b-42d3-a456-4266141740a1']
        ), 'A denied reschedule selector must not claim idempotency state.');
        $mismatchedCancel = $this->dispatch('POST', '/clinic/v1/appointments/' . $apptId . '/cancel');
        self::assertSame(403, $mismatchedCancel->get_status());
        self::assertSame('confirmed', (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        ));
        self::assertSame(1, App::db()->update('cpms_patient_user_links', ['clinic_id' => $clinicA], ['id' => $patientLink]));

        self::assertSame(1, App::db()->update('cpms_patients', ['status' => 'archived'], ['id' => $patientA]));
        $archivedMine = $this->dispatch('GET', '/clinic/v1/appointments/mine', [
            'from' => $tomorrow,
            'to'   => $dayAfter,
        ]);
        self::assertSame([], $this->payload($archivedMine), 'An archived Patient must not retain portal appointment authority.');
        self::assertSame(403, $this->dispatch('POST', '/clinic/v1/appointments/' . $apptId . '/cancel')->get_status());
        self::assertSame(1, App::db()->update('cpms_patients', ['status' => 'active'], ['id' => $patientA]));

        // 3. Appointment with active Visit cannot load reschedule options (409 HAS_ACTIVE_VISIT).
        $this->insertLiveVisit($clinicA, $locA, $clinician, $patientA, $apptId, $tomorrow);
        $withVisit = $this->dispatch('GET', '/clinic/v1/appointments/' . $apptId . '/reschedule-options');
        self::assertSame(409, $withVisit->get_status());
        self::assertSame('HAS_ACTIVE_VISIT', $this->errCode($withVisit));
    }

    public function testReceptionPortalNoShowEnforcesScopeReasonAndDenyOverride(): void
    {
        $org     = $this->insertOrg('D2B Org');
        $clinicA = $this->insertClinic('D2B Clinic A', $org);
        $clinicF = $this->insertClinic('D2B Clinic F', $org);
        $locA    = $this->insertLocation($clinicA, 'D2B Loc A', 1);
        $locB    = $this->insertLocation($clinicA, 'D2B Loc B', 0);
        $locF    = $this->insertLocation($clinicF, 'D2B Loc F', 1);

        $secretary = $this->makeUser('d2b_sec', 'subscriber');
        $memId     = cpms_test_seed_membership($secretary, $clinicA, 'cpms_secretary');

        $docUser   = $this->makeUser('d2b_doc', 'subscriber');
        $clinician = $this->insertClinician('دکتر حسینی', $clinicA, 1, $docUser);
        cpms_test_seed_membership($docUser, $clinicA, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($clinician, [$locA, $locB], $locA);

        $today   = $this->localDate(self::TZ);
        $patient = $this->insertPatient($clinicA, 'NS1');
        $slotA   = $this->insertSlot($clinicA, $locA, $clinician, $today, '10:00:00', 1, ['booked' => 1]);
        $slotB   = $this->insertSlot($clinicA, $locB, $clinician, $today, '11:00:00', 1, ['booked' => 1]);
        $apptA   = $this->insertAppointment($clinicA, $locA, $patient, $clinician, $slotA, $today, '10:00:00', 'confirmed', 'درد قفسه سینه');
        $apptB   = $this->insertAppointment($clinicA, $locB, $patient, $clinician, $slotB, $today, '11:00:00', 'confirmed');

        $foreignPt   = $this->insertPatient($clinicF, 'NSF');
        $foreignSlot = $this->insertSlot($clinicF, $locF, $clinician, $today, '12:00:00', 1, ['booked' => 1]);
        $apptF       = $this->insertAppointment($clinicF, $locF, $foreignPt, $clinician, $foreignSlot, $today, '12:00:00', 'confirmed');

        wp_set_current_user($secretary);
        $headersLocA = ['X-CPMS-Clinic-Id' => (string) $clinicA, 'X-CPMS-Location-Id' => (string) $locA];

        // 1. Blank reason rejected with 422.
        $blank = $this->dispatch('POST', '/clinic/v1/staff/portal/reception/appointments/' . $apptA . '/no-show', ['reason' => '   '], $headersLocA);
        self::assertSame(422, $blank->get_status());

        // 2. Other-Location and foreign-Clinic appointments return non-enumerating 404.
        $wrongLoc = $this->dispatch('POST', '/clinic/v1/staff/portal/reception/appointments/' . $apptB . '/no-show', ['reason' => 'عدم مراجعه'], $headersLocA);
        self::assertSame(404, $wrongLoc->get_status());

        $wrongClinic = $this->dispatch('POST', '/clinic/v1/staff/portal/reception/appointments/' . $apptF . '/no-show', ['reason' => 'عدم مراجعه'], $headersLocA);
        self::assertSame(404, $wrongClinic->get_status());

        // 3. Valid no-show transitions status, writes audit, preserves patient booking reason and slot booked_count.
        $ok = $this->dispatch('POST', '/clinic/v1/staff/portal/reception/appointments/' . $apptA . '/no-show', ['reason' => 'عدم حضور در ساعت مقرر'], $headersLocA);
        self::assertSame(200, $ok->get_status());
        $okAppointment = (array) ($this->payload($ok)['appointment'] ?? []);
        self::assertSame('no_show', $okAppointment['status'] ?? '');

        $persisted = App::db()->fetchRow('SELECT status, reason FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$apptA]);
        self::assertIsArray($persisted);
        self::assertSame('no_show', (string) $persisted['status']);
        self::assertSame('درد قفسه سینه', (string) $persisted['reason']);
        self::assertGreaterThan(0, $this->auditCount('APPOINTMENT_NO_SHOW', $apptA));

        // 4. DENY > GRANT: denying APPT_NO_SHOW blocks even an active secretary membership.
        App::membership_service()->set_capability($memId, RolesAndCapabilities::APPT_NO_SHOW, 'deny');
        $headersLocB = ['X-CPMS-Clinic-Id' => (string) $clinicA, 'X-CPMS-Location-Id' => (string) $locB];
        $denied = $this->dispatch('POST', '/clinic/v1/staff/portal/reception/appointments/' . $apptB . '/no-show', ['reason' => 'عدم مراجعه'], $headersLocB);
        self::assertSame(403, $denied->get_status());
    }

    public function testDoctorMedicalHistoryEnforcesNotePrivacyRxGateAndClinicIsolation(): void
    {
        $org     = $this->insertOrg('D2C Org');
        $clinicA = $this->insertClinic('D2C Clinic A', $org);
        $locA    = $this->insertLocation($clinicA, 'D2C Loc A', 1);

        $doc1User = $this->makeUser('d2c_doc1', 'subscriber');
        $doc2User = $this->makeUser('d2c_doc2', 'subscriber');
        $c1       = $this->insertClinician('دکتر اول', $clinicA, 1, $doc1User);
        $c2       = $this->insertClinician('دکتر دوم', $clinicA, 1, $doc2User);
        $mem1     = cpms_test_seed_membership($doc1User, $clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doc2User, $clinicA, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($c1, [$locA], $locA);
        App::membership_service()->assign_clinician_locations($c2, [$locA], $locA);

        $patient    = $this->insertPatient($clinicA, 'MH1');
        $pastDate1  = $this->localDate(self::TZ, '-10 days');
        $pastDate2  = $this->localDate(self::TZ, '-5 days');
        $today      = $this->localDate(self::TZ);

        $priorVisitC1 = $this->insertVisitWithStatus($clinicA, $locA, $patient, $c1, $pastDate1, 'consultation_completed', 0);
        $priorVisitC2 = $this->insertVisitWithStatus($clinicA, $locA, $patient, $c2, $pastDate2, 'consultation_completed', 0);
        $currentVisit = $this->insertVisitWithStatus($clinicA, $locA, $patient, $c1, $today, 'in_consultation', 1);

        $notesRepo = new ClinicalNoteRepository(App::db());
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC1,
            'patient_id'            => $patient,
            'clinician_id'          => $c1,
            'category'              => 'diagnosis',
            'visibility'            => 'doctor_private',
            'content_text'          => 'یادداشت خصوصی دکتر اول',
            'created_by_wp_user_id' => $doc1User,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC2,
            'patient_id'            => $patient,
            'clinician_id'          => $c2,
            'category'              => 'diagnosis',
            'visibility'            => 'patient_visible',
            'content_text'          => 'تشخیص قابل مشاهده دکتر دوم',
            'created_by_wp_user_id' => $doc2User,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC2,
            'patient_id'            => $patient,
            'clinician_id'          => $c2,
            'category'              => 'clinical_note',
            'visibility'            => 'doctor_private',
            'content_text'          => 'یادداشت محرمانه دکتر دوم — نباید افشا شود',
            'created_by_wp_user_id' => $doc2User,
        ]);

        $fuRepo = new FollowUpRepository(App::db());
        $fuRepo->insert($clinicA, [
            'visit_id'       => $priorVisitC2,
            'patient_id'     => $patient,
            'clinician_id'   => $c2,
            'is_needed'      => 1,
            'suggested_date' => $today,
            'reason'         => 'کنترل فشار خون',
        ]);

        $this->insertFinalizedPrescription($clinicA, $priorVisitC2, $patient, $c2, 'Losartan');

        $headers = ['X-CPMS-Clinic-Id' => (string) $clinicA, 'X-CPMS-Location-Id' => (string) $locA];

        // 1. Unassigned doctor (c2) cannot read medical history through c1's Visit.
        wp_set_current_user($doc2User);
        $unassigned = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $currentVisit . '/medical-history', [], $headers);
        self::assertContains($unassigned->get_status(), [403, 404]);

        // 2. Assigned doctor (c1) sees own private note + c2's patient_visible note + prescription + follow-up,
        //    and NEVER sees c2's doctor_private note!
        wp_set_current_user($doc1User);
        $ok = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $currentVisit . '/medical-history', [], $headers);
        self::assertSame(200, $ok->get_status());
        $payload = $this->payload($ok);
        $rawJson = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE);

        self::assertStringContainsString('یادداشت خصوصی دکتر اول', $rawJson);
        self::assertStringContainsString('تشخیص قابل مشاهده دکتر دوم', $rawJson);
        self::assertStringNotContainsString('یادداشت محرمانه دکتر دوم', $rawJson, 'another clinician doctor_private note must never leak');
        self::assertStringContainsString('Losartan', $rawJson);
        self::assertStringContainsString('کنترل فشار خون', $rawJson);
        self::assertGreaterThan(0, $this->auditCount('MEDICAL_RECORD_VIEWED', $currentVisit));

        // 3. Denying RX_READ hides prescriptions while preserving notes/follow-ups.
        App::membership_service()->set_capability($mem1, RolesAndCapabilities::RX_READ, 'deny');
        $noRx = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $currentVisit . '/medical-history', [], $headers);
        self::assertSame(200, $noRx->get_status());
        self::assertStringNotContainsString('Losartan', (string) wp_json_encode($this->payload($noRx), JSON_UNESCAPED_UNICODE));
    }

    public function testFinanceReconciliationEnforcesSubscriberAccountantLocationScopeAndZeroPhi(): void
    {
        $org     = $this->insertOrg('D2D Org');
        $clinicA = $this->insertClinic('D2D Clinic A', $org);
        $locA    = $this->insertLocation($clinicA, 'D2D Loc A', 1);
        $locB    = $this->insertLocation($clinicA, 'D2D Loc B', 0);

        $accountant = $this->makeUser('d2d_acc', 'subscriber');
        $memId      = cpms_test_seed_membership($accountant, $clinicA, 'cpms_accountant');

        $docUser   = $this->makeUser('d2d_doc', 'subscriber');
        $clinician = $this->insertClinician('دکتر مالی', $clinicA, 1, $docUser);
        $patient   = $this->insertPatient($clinicA, 'FIN1');
        $today     = $this->localDate(self::TZ);
        $nowUtc    = App::db()->nowUtcSql();

        $visitA = $this->insertVisitWithStatus($clinicA, $locA, $patient, $clinician, $today, 'consultation_completed', 0);
        $visitB = $this->insertVisitWithStatus($clinicA, $locB, $patient, $clinician, $today, 'consultation_completed', 0);

        $invA = $this->insertInvoice($clinicA, $locA, $patient, $visitA, $docUser, 'INV-D2A-1', '500000.00', '350000.00', '150000.00', 'partial');
        $invB = $this->insertInvoice($clinicA, $locB, $patient, $visitB, $docUser, 'INV-D2B-1', '900000.00', '900000.00', '0.00', 'paid');

        $payRepo = new PaymentRepository(App::db());
        $payRepo->insert($clinicA, [
            'location_id'            => $locA,
            'payment_number'         => 'PAY-D2A-01',
            'invoice_id'             => $invA,
            'patient_id'             => $patient,
            'amount'                 => '200000.00',
            'method'                 => 'cash',
            'status'                 => 'captured',
            'idempotency_key'        => 'idem-d2a-1',
            'received_by_wp_user_id' => $docUser,
            'paid_at'                => $nowUtc,
        ]);
        $payRepo->insert($clinicA, [
            'location_id'            => $locA,
            'payment_number'         => 'PAY-D2A-02',
            'invoice_id'             => $invA,
            'patient_id'             => $patient,
            'amount'                 => '150000.00',
            'method'                 => 'card_pos',
            'status'                 => 'captured',
            'idempotency_key'        => 'idem-d2a-2',
            'received_by_wp_user_id' => $docUser,
            'paid_at'                => $nowUtc,
        ]);
        $payRepo->insert($clinicA, [
            'location_id'            => $locA,
            'payment_number'         => 'PAY-D2A-03',
            'invoice_id'             => $invA,
            'patient_id'             => $patient,
            'amount'                 => '50000.00',
            'method'                 => 'cash',
            'status'                 => 'voided',
            'voided_at'              => $nowUtc,
            'void_reason'            => 'خطای ثبت',
            'idempotency_key'        => 'idem-d2a-3',
            'received_by_wp_user_id' => $docUser,
            'paid_at'                => $nowUtc,
        ]);
        $payRepo->insert($clinicA, [
            'location_id'            => $locB,
            'payment_number'         => 'PAY-D2B-01',
            'invoice_id'             => $invB,
            'patient_id'             => $patient,
            'amount'                 => '900000.00',
            'method'                 => 'card_pos',
            'status'                 => 'captured',
            'idempotency_key'        => 'idem-d2b-1',
            'received_by_wp_user_id' => $docUser,
            'paid_at'                => $nowUtc,
        ]);

        wp_set_current_user($accountant);
        // Live-browser bootstrap parity: Subscriber Accountant calling finance/context without X-CPMS-Clinic-Id resolves unique membership.
        $ctxUnscoped = $this->dispatch('GET', '/clinic/v1/staff/portal/finance/context');
        self::assertSame(200, $ctxUnscoped->get_status(), 'Subscriber Accountant unique-Clinic finance/context bootstrap must succeed without X-CPMS-Clinic-Id.');
        self::assertSame($clinicA, (int) ($this->payload($ctxUnscoped)['clinic_id'] ?? 0));

        $headersLocA = ['X-CPMS-Clinic-Id' => (string) $clinicA, 'X-CPMS-Location-Id' => (string) $locA];

        // 1. Range > 93 days rejected with 422.
        $tooWide = $this->dispatch('GET', '/clinic/v1/staff/portal/finance/reconciliation', ['from' => '2026-01-01', 'to' => '2026-06-01'], $headersLocA);
        self::assertSame(422, $tooWide->get_status());

        // 2. Scoped reconciliation for Location A excludes Location B and voided amounts from net totals, with zero PHI.
        $ok = $this->dispatch('GET', '/clinic/v1/staff/portal/finance/reconciliation', ['from' => $today, 'to' => $today], $headersLocA);
        self::assertSame(200, $ok->get_status());
        $data   = $this->payload($ok);
        $totals = (array) ($data['totals'] ?? []);

        self::assertSame($locA, $data['location_id'] ?? 0);
        self::assertSame(2, $totals['captured_count']);
        self::assertSame('200000.00', $totals['cash_total']);
        self::assertSame('150000.00', $totals['card_pos_total']);
        self::assertSame('350000.00', $totals['captured_total']);
        self::assertSame('150000.00', $totals['outstanding_balance']);

        $rawJson = (string) wp_json_encode($data);
        self::assertStringNotContainsString('PAY-D2B-01', $rawJson, 'Location B payment must never appear in Location A reconciliation');
        self::assertStringNotContainsString('INV-D2B-1', $rawJson, 'Location B invoice must never appear in Location A reconciliation');
        self::assertStringNotContainsString('PAY-D2A-03', $rawJson, 'Voided payment must never appear in captured reconciliation ledger');
        foreach (['patient_id', 'clinic_id', 'mrn', 'mobile', 'national_id', 'first_name', 'last_name'] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden . '"', $rawJson);
        }

        // 3. DENY > GRANT on FINANCE_READ blocks reconciliation immediately.
        App::membership_service()->set_capability($memId, RolesAndCapabilities::FINANCE_READ, 'deny');
        $denied = $this->dispatch('GET', '/clinic/v1/staff/portal/finance/reconciliation', ['from' => $today, 'to' => $today], $headersLocA);
        self::assertSame(403, $denied->get_status());
    }

    public function testSubscriberDoctorMedicalHistoryEnforcesScopedGrantsDenyOverridesSecondClinicianAndCrossClinic(): void
    {
        $org     = $this->insertOrg('P1 SubDoc Org');
        $clinicA = $this->insertClinic('P1 Clinic A', $org);
        $clinicB = $this->insertClinic('P1 Clinic B', $org);
        $locA    = $this->insertLocation($clinicA, 'P1 Loc A', 1);
        $locB    = $this->insertLocation($clinicB, 'P1 Loc B', 1);

        // Real WordPress Subscribers — ZERO global clinical capabilities.
        $subDoc1      = $this->makeUser('p1_sub_doc1', 'subscriber');
        $subDoc2      = $this->makeUser('p1_sub_doc2', 'subscriber');
        $visitingDoc  = $this->makeUser('p1_sub_visiting', 'subscriber');
        $foreignDoc   = $this->makeUser('p1_sub_foreign', 'subscriber');
        $wpAdminUser  = $this->makeUser('p1_wp_admin', 'administrator');

        foreach ([$subDoc1, $subDoc2, $visitingDoc, $foreignDoc] as $uid) {
            $u = get_userdata($uid);
            self::assertNotFalse($u);
            self::assertSame(['subscriber'], array_values($u->roles));
            self::assertFalse(user_can($uid, RolesAndCapabilities::MEDICAL_READ));
            self::assertFalse(user_can($uid, RolesAndCapabilities::PRIVATE_NOTE_READ));
            self::assertFalse(user_can($uid, RolesAndCapabilities::RX_READ));
        }

        $c1       = $this->insertClinician('دکتر مشترک یک', $clinicA, 1, $subDoc1);
        $c2       = $this->insertClinician('دکتر مشترک دو', $clinicA, 1, $subDoc2);
        $cVisit   = $this->insertClinician('دکتر مهمان', $clinicA, 1, $visitingDoc);
        $cForeign = $this->insertClinician('دکتر کلینیک ب', $clinicB, 1, $foreignDoc);

        $mem1       = cpms_test_seed_membership($subDoc1, $clinicA, 'cpms_doctor');
        cpms_test_seed_membership($subDoc2, $clinicA, 'cpms_doctor');
        cpms_test_seed_membership($foreignDoc, $clinicB, 'cpms_doctor');

        // Custom role in Clinic A with ONLY MEDICAL_READ (no PRIVATE_NOTE_READ or RX_READ preset)
        // so explicit scoped capability GRANT and DENY > GRANT are proven independently of presets.
        (new CustomRoleRepository(App::db()))->define(
            $clinicA,
            'visiting_physician',
            [RolesAndCapabilities::MEDICAL_READ],
            'پزشک مهمان'
        );
        $visitingMem = cpms_test_seed_membership($visitingDoc, $clinicA, 'visiting_physician');

        App::membership_service()->assign_clinician_locations($c1, [$locA], $locA);
        App::membership_service()->assign_clinician_locations($c2, [$locA], $locA);
        App::membership_service()->assign_clinician_locations($cVisit, [$locA], $locA);
        App::membership_service()->assign_clinician_locations($cForeign, [$locB], $locB);

        $patientA = $this->insertPatient($clinicA, 'P1A');
        $patientB = $this->insertPatient($clinicB, 'P1B');

        $past1        = $this->localDate(self::TZ, '-12 days');
        $past2        = $this->localDate(self::TZ, '-7 days');
        $past3        = $this->localDate(self::TZ, '-3 days');
        $today        = $this->localDate(self::TZ);
        $priorVisitC1 = $this->insertVisitWithStatus($clinicA, $locA, $patientA, $c1, $past1, 'consultation_completed', 0);
        $priorVisitC2 = $this->insertVisitWithStatus($clinicA, $locA, $patientA, $c2, $past2, 'consultation_completed', 0);
        $priorVisitCV = $this->insertVisitWithStatus($clinicA, $locA, $patientA, $cVisit, $past3, 'consultation_completed', 0);
        $activeVisit1 = $this->insertVisitWithStatus($clinicA, $locA, $patientA, $c1, $today, 'in_consultation', 1);
        $activeVisitV = $this->insertVisitWithStatus($clinicA, $locA, $patientA, $cVisit, $today, 'in_consultation', 1);
        $foreignVisit = $this->insertVisitWithStatus($clinicB, $locB, $patientB, $cForeign, $past1, 'consultation_completed', 0);

        $notesRepo = new ClinicalNoteRepository(App::db());
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC1,
            'patient_id'            => $patientA,
            'clinician_id'          => $c1,
            'category'              => 'diagnosis',
            'visibility'            => 'doctor_private',
            'content_text'          => 'یادداشت خصوصی پزشک مشترک ۱',
            'created_by_wp_user_id' => $subDoc1,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC2,
            'patient_id'            => $patientA,
            'clinician_id'          => $c2,
            'category'              => 'diagnosis',
            'visibility'            => 'patient_visible',
            'content_text'          => 'یادداشت عمومی پزشک مشترک ۲',
            'created_by_wp_user_id' => $subDoc2,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC2,
            'patient_id'            => $patientA,
            'clinician_id'          => $c2,
            'category'              => 'clinical_note',
            'visibility'            => 'doctor_private',
            'content_text'          => 'یادداشت محرمانه پزشک مشترک ۲',
            'created_by_wp_user_id' => $subDoc2,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitCV,
            'patient_id'            => $patientA,
            'clinician_id'          => $cVisit,
            'category'              => 'diagnosis',
            'visibility'            => 'doctor_private',
            'content_text'          => 'یادداشت خصوصی پزشک مهمان',
            'created_by_wp_user_id' => $visitingDoc,
        ]);
        $notesRepo->insert($clinicB, [
            'visit_id'              => $foreignVisit,
            'patient_id'            => $patientB,
            'clinician_id'          => $cForeign,
            'category'              => 'diagnosis',
            'visibility'            => 'patient_visible',
            'content_text'          => 'یادداشت کلینیک بیگانه ب',
            'created_by_wp_user_id' => $foreignDoc,
        ]);

        $this->insertFinalizedPrescription($clinicA, $priorVisitC2, $patientA, $c2, 'Atorvastatin');
        $this->insertFinalizedPrescription($clinicB, $foreignVisit, $patientB, $cForeign, 'Warfarin');

        $headersA = ['X-CPMS-Clinic-Id' => (string) $clinicA, 'X-CPMS-Location-Id' => (string) $locA];
        $headersB = ['X-CPMS-Clinic-Id' => (string) $clinicB, 'X-CPMS-Location-Id' => (string) $locB];

        // 1. WP Subscriber doctor with active cpms_doctor membership reads own doctor_private note +
        //    colleague's patient_visible note + prescription, while colleague's doctor_private note
        //    and foreign-Clinic records stay completely invisible.
        wp_set_current_user($subDoc1);
        $res1 = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisit1 . '/medical-history', [], $headersA);
        self::assertSame(200, $res1->get_status());
        $json1 = (string) wp_json_encode($this->payload($res1), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('یادداشت خصوصی پزشک مشترک ۱', $json1);
        self::assertStringContainsString('یادداشت عمومی پزشک مشترک ۲', $json1);
        self::assertStringContainsString('Atorvastatin', $json1);
        self::assertStringNotContainsString('یادداشت محرمانه پزشک مشترک ۲', $json1);
        self::assertStringNotContainsString('یادداشت خصوصی پزشک مهمان', $json1);
        self::assertStringNotContainsString('یادداشت کلینیک بیگانه ب', $json1);
        self::assertStringNotContainsString('Warfarin', $json1);

        // 2. Explicit DENY on PRIVATE_NOTE_READ hides own doctor_private note on cpms_doctor preset.
        App::membership_service()->set_capability($mem1, RolesAndCapabilities::PRIVATE_NOTE_READ, 'deny');
        $resNoPriv = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisit1 . '/medical-history', [], $headersA);
        self::assertSame(200, $resNoPriv->get_status());
        $jsonNoPriv = (string) wp_json_encode($this->payload($resNoPriv), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('یادداشت خصوصی پزشک مشترک ۱', $jsonNoPriv);
        self::assertStringContainsString('یادداشت عمومی پزشک مشترک ۲', $jsonNoPriv);
        self::assertStringContainsString('Atorvastatin', $jsonNoPriv);

        // 3. Explicit scoped GRANTs on custom role (`visiting_physician`) enable own doctor_private + RX_READ,
        //    and subsequent explicit DENY overrides GRANT (`DENY > GRANT`).
        wp_set_current_user($visitingDoc);
        $baseVisitRes = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisitV . '/medical-history', [], $headersA);
        self::assertSame(200, $baseVisitRes->get_status());
        $baseJson = (string) wp_json_encode($this->payload($baseVisitRes), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('یادداشت عمومی پزشک مشترک ۲', $baseJson);
        self::assertStringNotContainsString('یادداشت خصوصی پزشک مهمان', $baseJson);
        self::assertStringNotContainsString('Atorvastatin', $baseJson);

        App::membership_service()->set_capability($visitingMem, RolesAndCapabilities::PRIVATE_NOTE_READ, 'grant');
        App::membership_service()->set_capability($visitingMem, RolesAndCapabilities::RX_READ, 'grant');
        $grantedRes = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisitV . '/medical-history', [], $headersA);
        self::assertSame(200, $grantedRes->get_status());
        $grantedJson = (string) wp_json_encode($this->payload($grantedRes), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('یادداشت خصوصی پزشک مهمان', $grantedJson);
        self::assertStringContainsString('Atorvastatin', $grantedJson);
        self::assertStringNotContainsString('یادداشت خصوصی پزشک مشترک ۱', $grantedJson);

        App::membership_service()->set_capability($visitingMem, RolesAndCapabilities::PRIVATE_NOTE_READ, 'deny');
        App::membership_service()->set_capability($visitingMem, RolesAndCapabilities::RX_READ, 'deny');
        $deniedOverrideRes = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisitV . '/medical-history', [], $headersA);
        self::assertSame(200, $deniedOverrideRes->get_status());
        $deniedOverrideJson = (string) wp_json_encode($this->payload($deniedOverrideRes), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('یادداشت خصوصی پزشک مهمان', $deniedOverrideJson);
        self::assertStringNotContainsString('Atorvastatin', $deniedOverrideJson);

        App::membership_service()->set_capability($visitingMem, RolesAndCapabilities::MEDICAL_READ, 'deny');
        $deniedMedical = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisitV . '/medical-history', [], $headersA);
        self::assertSame(403, $deniedMedical->get_status());

        // 4. Second clinician in same Clinic, doctor from Clinic B, and WP Administrator without clinical ownership are denied.
        wp_set_current_user($subDoc2);
        $otherClinicianRes = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisit1 . '/medical-history', [], $headersA);
        self::assertSame(404, $otherClinicianRes->get_status());

        wp_set_current_user($foreignDoc);
        $crossClinicWithA = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisit1 . '/medical-history', [], $headersA);
        self::assertSame(403, $crossClinicWithA->get_status());
        $crossClinicWithB = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisit1 . '/medical-history', [], $headersB);
        self::assertSame(404, $crossClinicWithB->get_status());

        wp_set_current_user($wpAdminUser);
        $adminRes = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $activeVisit1 . '/medical-history', [], $headersA);
        self::assertSame(403, $adminRes->get_status());
    }

    public function testDoctorMedicalHistoryAbortsWithInternalErrorAndLeaksNothingWhenAuditWriteFails(): void
    {
        $org     = $this->insertOrg('P2B Org');
        $clinicA = $this->insertClinic('P2B Clinic A', $org);
        $locA    = $this->insertLocation($clinicA, 'P2B Loc A', 1);

        $subDoc    = $this->makeUser('p2b_sub_doc', 'subscriber');
        $clinician = $this->insertClinician('دکتر ممیزی', $clinicA, 1, $subDoc);
        cpms_test_seed_membership($subDoc, $clinicA, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($clinician, [$locA], $locA);

        $patient      = $this->insertPatient($clinicA, 'P2B');
        $pastDate     = $this->localDate(self::TZ, '-4 days');
        $today        = $this->localDate(self::TZ);
        $priorVisit   = $this->insertVisitWithStatus($clinicA, $locA, $patient, $clinician, $pastDate, 'consultation_completed', 0);
        $currentVisit = $this->insertVisitWithStatus($clinicA, $locA, $patient, $clinician, $today, 'in_consultation', 1);

        (new ClinicalNoteRepository(App::db()))->insert($clinicA, [
            'visit_id'              => $priorVisit,
            'patient_id'            => $patient,
            'clinician_id'          => $clinician,
            'category'              => 'diagnosis',
            'visibility'            => 'doctor_private',
            'content_text'          => 'SECRET_AUDIT_FAILURE_NOTE_DO_NOT_RETURN',
            'created_by_wp_user_id' => $subDoc,
        ]);
        $this->insertFinalizedPrescription($clinicA, $priorVisit, $patient, $clinician, 'MetoprololSecret');

        $auditTable = App::db()->table('cpms_audit_logs');
        $fault      = static function (string $sql) use ($auditTable): string {
            if (preg_match('/^\s*INSERT\s+INTO\s+\S*cpms_audit_logs\b/i', $sql) === 1 && str_contains($sql, 'MEDICAL_RECORD_VIEWED')) {
                return 'INSERT INTO ' . $auditTable . ' (cpms_fault_injection_no_such_column) VALUES (1)';
            }

            return $sql;
        };

        wp_set_current_user($subDoc);
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinicA, 'X-CPMS-Location-Id' => (string) $locA];

        global $wpdb;
        $prevSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $fault, 10, 1);
        try {
            $res = $this->dispatch('GET', '/clinic/v1/doctor/portal/visits/' . $currentVisit . '/medical-history', [], $headers);
        } finally {
            remove_filter('query', $fault, 10);
            $wpdb->suppress_errors($prevSuppress);
        }

        self::assertSame(500, $res->get_status(), 'Mandatory MEDICAL_RECORD_VIEWED audit failure must abort medical-history response with 500.');
        self::assertSame('CLINIC_INTERNAL_ERROR', $this->errCode($res));
        self::assertSame(0, $this->auditCount('MEDICAL_RECORD_VIEWED', $currentVisit));

        $raw = (string) wp_json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
        foreach ([
            'SECRET_AUDIT_FAILURE_NOTE_DO_NOT_RETURN',
            'MetoprololSecret',
            'prior_visits',
            'patient_summary',
            'cpms_fault_injection_no_such_column',
            'Unknown column',
            'cpms_audit_logs',
            'RuntimeException',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function testPatientRescheduleOptionsRefusesSilentlySwallowingForbiddenAuditFailure(): void
    {
        $org     = $this->insertOrg('P2A Org');
        $clinicA = $this->insertClinic('P2A Clinic A', $org);
        $locA    = $this->insertLocation($clinicA, 'P2A Loc A', 1);

        $subDoc    = $this->makeUser('p2a_sub_doc', 'subscriber');
        $clinician = $this->insertClinician('دکتر نوبت', $clinicA, 1, $subDoc);
        cpms_test_seed_membership($subDoc, $clinicA, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($clinician, [$locA], $locA);

        $ownerUser   = $this->makeUser('p2a_owner', RolesAndCapabilities::ROLE_PATIENT);
        $foreignUser = $this->makeUser('p2a_foreign', RolesAndCapabilities::ROLE_PATIENT);
        $ownerPt     = $this->insertPatient($clinicA, 'P2AO');
        $foreignPt   = $this->insertPatient($clinicA, 'P2AF');
        $this->linkPatient($clinicA, $ownerPt, $ownerUser, '09123334455');
        $this->linkPatient($clinicA, $foreignPt, $foreignUser, '09126667788');

        $tomorrow = $this->localDate(self::TZ, '+1 day');
        $slotId   = $this->insertSlot($clinicA, $locA, $clinician, $tomorrow, '09:30:00', 1, ['booked' => 1]);
        $apptId   = $this->insertAppointment($clinicA, $locA, $ownerPt, $clinician, $slotId, $tomorrow, '09:30:00', 'confirmed');

        $opBefore = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs') . " WHERE message = 'booking.unexpected_error'"
        );

        $auditTable = App::db()->table('cpms_audit_logs');
        $fault      = static function (string $sql) use ($auditTable): string {
            if (preg_match('/^\s*INSERT\s+INTO\s+\S*cpms_audit_logs\b/i', $sql) === 1 && str_contains($sql, 'FORBIDDEN_ACCESS_ATTEMPT')) {
                return 'INSERT INTO ' . $auditTable . ' (cpms_fault_injection_no_such_column) VALUES (1)';
            }

            return $sql;
        };

        wp_set_current_user($foreignUser);
        global $wpdb;
        $prevSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $fault, 10, 1);
        try {
            $res = $this->dispatch('GET', '/clinic/v1/appointments/' . $apptId . '/reschedule-options');
        } finally {
            remove_filter('query', $fault, 10);
            $wpdb->suppress_errors($prevSuppress);
        }

        self::assertSame(500, $res->get_status(), 'FORBIDDEN_ACCESS_ATTEMPT security audit failure must not be silently swallowed.');
        self::assertSame('CLINIC_INTERNAL_ERROR', $this->errCode($res));
        self::assertSame(0, $this->auditCount('FORBIDDEN_ACCESS_ATTEMPT', $apptId));

        $opAfter = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs') . " WHERE message = 'booking.unexpected_error'"
        );
        self::assertGreaterThan($opBefore, $opAfter, 'Audit write failure must record booking.unexpected_error in operational logs.');

        $raw = (string) wp_json_encode($res->get_data(), JSON_UNESCAPED_UNICODE);
        foreach (['days', 'reference_code', 'cpms_fault_injection_no_such_column', 'Unknown column', 'cpms_audit_logs', 'RuntimeException'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function testRescheduleOptionsHonorsClinicMaxFutureDaysAndLocationTimezoneBoundaries(): void
    {
        $org           = $this->insertOrg('P3 Window Org');
        $ambientClinic = $this->insertClinic('P3 Ambient Clinic', $org);
        $clinicA       = $this->insertClinic('P3 Target Clinic', $org);
        $locA          = $this->insertLocation($clinicA, 'P3 Tehran Loc', 1);

        // Ambient Clinic has max_future_days = 60 while Target Clinic varies 1 / 7 / 30.
        $ambientSettings = App::settingsFactory()->forClinic($ambientClinic);
        $ambientSettings->set('booking.max_future_days', 60);
        $ambientSettings->set('booking.min_lead_hours', 0);

        $targetSettings = App::settingsFactory()->forClinic($clinicA);
        $targetSettings->set('booking.min_lead_hours', 2);

        $subDoc    = $this->makeUser('p3_sub_doc', 'subscriber');
        $clinician = $this->insertClinician('دکتر پنجره زمانی', $clinicA, 1, $subDoc);
        cpms_test_seed_membership($subDoc, $clinicA, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($clinician, [$locA], $locA);

        $patientUser = $this->makeUser('p3_patient', RolesAndCapabilities::ROLE_PATIENT);
        $patientId   = $this->insertPatient($clinicA, 'P3PT');
        $this->linkPatient($clinicA, $patientId, $patientUser, '09127778899');

        // Reference clock 1: 2026-11-10 06:30:00 UTC === 2026-11-10 10:00:00 Asia/Tehran (+03:30).
        $nowDaytimeUtc = new \DateTimeImmutable('2026-11-10 06:30:00', new \DateTimeZone('UTC'));

        $curSlot      = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-12', '09:00:00', 1, ['booked' => 1]);
        $slotTooSoon  = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-10', '11:00:00', 2); // +1h (< min_lead_hours=2)
        $slotSameDay  = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-10', '13:00:00', 2); // +3h
        $slotWithin1d = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-11', '09:30:00', 2); // +23.5h (next day, inside 1d)
        $slotPast1d   = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-11', '10:30:00', 2); // +24.5h (outside 1d, inside 7d)
        $slotWithin7d = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-17', '09:30:00', 2); // +6d 23.5h (inside 7d)
        $slotPast7d   = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-17', '11:00:00', 2); // +7d 1h (outside 7d, inside 30d)
        $slotWithin30 = $this->insertSlot($clinicA, $locA, $clinician, '2026-12-10', '09:30:00', 2); // +29d 23.5h (inside 30d)
        $slotPast30   = $this->insertSlot($clinicA, $locA, $clinician, '2026-12-10', '11:00:00', 2); // +30d 1h (outside 30d)

        $apptId = $this->insertAppointment($clinicA, $locA, $patientId, $clinician, $curSlot, '2026-11-12', '09:00:00', 'confirmed');

        $extractIds = static function (array $result): array {
            $ids = [];
            foreach ((array) ($result['days'] ?? []) as $day) {
                foreach ((array) ($day['slots'] ?? []) as $slot) {
                    $ids[] = (int) $slot['slot_id'];
                }
            }
            return $ids;
        };

        // Case A: max_future_days = 1
        $targetSettings->set('booking.max_future_days', 1);
        Settings::flushCache();
        $ids1 = $extractIds(App::bookingService()->reschedule_options($patientUser, $apptId, $nowDaytimeUtc));
        self::assertSame([$slotSameDay, $slotWithin1d], $ids1, 'max_future_days=1 must include +3h and +23.5h (next day) while excluding +1h and +24.5h.');
        self::assertNotContains($slotTooSoon, $ids1);
        self::assertNotContains($slotPast1d, $ids1);

        // Case B: max_future_days = 7
        $targetSettings->set('booking.max_future_days', 7);
        Settings::flushCache();
        $ids7 = $extractIds(App::bookingService()->reschedule_options($patientUser, $apptId, $nowDaytimeUtc));
        self::assertSame([$slotSameDay, $slotWithin1d, $slotPast1d, $slotWithin7d], $ids7, 'max_future_days=7 must cap strictly at +7d in Location timezone.');
        self::assertNotContains($slotPast7d, $ids7);

        // Case C: max_future_days = 30
        $targetSettings->set('booking.max_future_days', 30);
        Settings::flushCache();
        $ids30 = $extractIds(App::bookingService()->reschedule_options($patientUser, $apptId, $nowDaytimeUtc));
        self::assertSame(
            [$slotSameDay, $slotWithin1d, $slotPast1d, $slotWithin7d, $slotPast7d, $slotWithin30],
            $ids30,
            'max_future_days=30 must include up to +29d 23.5h and exclude +30d 1h.'
        );
        self::assertNotContains($slotPast30, $ids30);

        // Case D: Cross-midnight UTC vs Location timezone (Asia/Tehran +03:30) boundary.
        // At 2026-11-10 21:30:00 UTC, UTC date is still 2026-11-10, while Tehran local time is 2026-11-11 01:00:00!
        $nowCrossMidnightUtc = new \DateTimeImmutable('2026-11-10 21:30:00', new \DateTimeZone('UTC'));
        $slotTehranWithin1d  = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-12', '00:30:00', 2); // +23.5h from 01:00 Nov 11 Tehran
        $slotTehranPast1d    = $this->insertSlot($clinicA, $locA, $clinician, '2026-11-12', '01:30:00', 2); // +24.5h from 01:00 Nov 11 Tehran

        $targetSettings->set('booking.max_future_days', 1);
        Settings::flushCache();
        $idsCrossMidnight = $extractIds(App::bookingService()->reschedule_options($patientUser, $apptId, $nowCrossMidnightUtc));
        self::assertContains($slotTehranWithin1d, $idsCrossMidnight, 'Cross-midnight Tehran local date (Nov 12 00:30, +23.5h) must be included when UTC is still Nov 10.');
        self::assertNotContains($slotTehranPast1d, $idsCrossMidnight, 'Slot at +24.5h in Tehran local time must be excluded under max_future_days=1.');

        // Case E: Real REST endpoint under small Clinic window (max_future_days = 7, previously broken by hardcoded +29 days).
        $liveCurDate  = $this->localDate(self::TZ, '+2 days');
        $liveNearDate = $this->localDate(self::TZ, '+3 days');
        $liveFarDate  = $this->localDate(self::TZ, '+10 days');
        $liveCurSlot  = $this->insertSlot($clinicA, $locA, $clinician, $liveCurDate, '09:00:00', 1, ['booked' => 1]);
        $liveNearSlot = $this->insertSlot($clinicA, $locA, $clinician, $liveNearDate, '10:00:00', 2);
        $liveFarSlot  = $this->insertSlot($clinicA, $locA, $clinician, $liveFarDate, '10:00:00', 2);
        $liveAppt     = $this->insertAppointment($clinicA, $locA, $patientId, $clinician, $liveCurSlot, $liveCurDate, '09:00:00', 'confirmed');

        $targetSettings->set('booking.max_future_days', 7);
        Settings::flushCache();
        wp_set_current_user($patientUser);
        $restRes = $this->dispatch('GET', '/clinic/v1/appointments/' . $liveAppt . '/reschedule-options');
        self::assertSame(200, $restRes->get_status(), 'REST reschedule-options must succeed when booking.max_future_days=7 (< 28 days).');
        $restIds = $extractIds($this->payload($restRes));
        self::assertContains($liveNearSlot, $restIds);
        self::assertNotContains($liveFarSlot, $restIds);
    }

    // ================= helpers =================

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        ScopeContext::clear();
        App::resetScope();
        $req = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $k => $v) {
            $req->set_header($k, $v);
        }

        return rest_do_request($req);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return is_array($body) ? $body : [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return is_array($body) ? (string) ($body['code'] ?? '') : '';
    }

    private function localDate(string $tz, string $modify = ''): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz));
        if ($modify !== '') {
            $now = $now->modify($modify);
        }

        return $now->format('Y-m-d');
    }

    private function auditCount(string $action, int $resourceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d',
            [$action, $resourceId]
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $u  = $login . '_' . bin2hex(random_bytes(3));
        $id = (int) wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $id;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_organizations', [
            'name'       => $name,
            'slug'       => 'org-' . bin2hex(random_bytes(3)),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function insertClinic(string $name, int $orgId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_clinics', [
            'organization_id' => $orgId,
            'name'            => $name,
            'slug'            => 'cl-' . bin2hex(random_bytes(3)),
            'timezone'        => self::TZ,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $id = (int) $wpdb->insert_id;
        App::resetScope();

        return $id;
    }

    private function insertLocation(int $clinicId, string $name, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_locations', [
            'clinic_id'  => $clinicId,
            'name'       => $name,
            'slug'       => 'loc-' . bin2hex(random_bytes(3)),
            'timezone'   => self::TZ,
            'is_primary' => $primary,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_clinicians', [
            'clinic_id'  => $clinicId,
            'full_name'  => $name,
            'wp_user_id' => $wpUserId,
            'is_active'  => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id'  => $clinicId,
            'mrn'        => 'MR-D2-' . $tag . '-' . bin2hex(random_bytes(2)),
            'first_name' => 'بیمار',
            'last_name'  => 'آزمایشی ' . $tag,
            'mobile'     => '0912' . sprintf('%07d', random_int(1000000, 9999999)),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function linkPatient(int $clinicId, int $patientId, int $wpUserId, string $mobile): int
    {
        App::db()->insert('cpms_patient_user_links', [
            'clinic_id'      => $clinicId,
            'patient_id'     => $patientId,
            'wp_user_id'     => $wpUserId,
            'mobile_at_link' => $mobile,
            'is_primary'     => 1,
            'linked_at'      => App::db()->nowUtcSql(),
        ]);

        return App::db()->wpdb_last_insert_id();
    }

    /**
     * @param array{booked?: int, held?: int, is_open?: int, duration?: int} $opts
     */
    private function insertSlot(int $clinicId, int $locId, int $clinicianId, string $date, string $time, int $capacity = 1, array $opts = []): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_schedule_slots', [
            'clinic_id'      => $clinicId,
            'location_id'    => $locId,
            'clinician_id'   => $clinicianId,
            'slot_date'      => $date,
            'slot_time'      => $time,
            'duration_min'   => (int) ($opts['duration'] ?? 20),
            'capacity'       => $capacity,
            'booked_count'   => (int) ($opts['booked'] ?? 0),
            'held_count'     => (int) ($opts['held'] ?? 0),
            'is_open'        => (int) ($opts['is_open'] ?? 1),
            'generated_from' => 'manual',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function insertAppointment(
        int $clinicId,
        int $locId,
        int $patientId,
        int $clinicianId,
        int $slotId,
        string $date,
        string $time,
        string $status = 'confirmed',
        ?string $reason = null
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_appointments', [
            'clinic_id'         => $clinicId,
            'location_id'       => $locId,
            'reference_code'    => 'd2-' . bin2hex(random_bytes(3)),
            'patient_id'        => $patientId,
            'clinician_id'      => $clinicianId,
            'slot_id'           => $slotId,
            'wp_user_id'        => 0,
            'slot_date'         => $date,
            'slot_time'         => $time,
            'duration_min'      => 20,
            'slot_end_time'     => $time,
            'status'            => $status,
            'reason'            => $reason,
            'is_walkin_express' => 0,
            'confirmed_at'      => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function insertLiveVisit(int $clinicId, int $locId, int $clinicianId, int $patientId, int $appointmentId, string $date): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id'      => $clinicId,
            'location_id'    => $locId,
            'clinician_id'   => $clinicianId,
            'patient_id'     => $patientId,
            'appointment_id' => $appointmentId,
            'source'         => 'scheduled',
            'status'         => 'waiting',
            'visit_date'     => $date,
            'check_in_at'    => $now,
            'active'         => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $visitId = (int) $wpdb->insert_id;
        $wpdb->update($wpdb->prefix . 'cpms_appointments', ['active_visit_id' => $visitId], ['id' => $appointmentId]);

        return $visitId;
    }

    private function insertVisitWithStatus(int $clinicId, int $locId, int $patientId, int $clinicianId, string $date, string $status, int $active): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id'      => $clinicId,
            'location_id'    => $locId,
            'clinician_id'   => $clinicianId,
            'patient_id'     => $patientId,
            'appointment_id' => null,
            'source'         => 'walk_in',
            'status'         => $status,
            'visit_date'     => $date,
            'check_in_at'    => $now,
            'active'         => $active,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return (int) $wpdb->insert_id;
    }

    private function insertFinalizedPrescription(int $clinicId, int $visitId, int $patientId, int $clinicianId, string $drugName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_prescriptions', [
            'clinic_id'           => $clinicId,
            'prescription_number' => 'RX-D2-' . bin2hex(random_bytes(3)),
            'visit_id'            => $visitId,
            'patient_id'          => $patientId,
            'clinician_id'        => $clinicianId,
            'status'              => 'finalized',
            'is_patient_visible'  => 1,
            'finalized_at'        => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $rxId = (int) $wpdb->insert_id;

        $wpdb->insert($wpdb->prefix . 'cpms_prescription_items', [
            'prescription_id' => $rxId,
            'generic_name'    => $drugName,
            'strength'        => '50mg',
            'form'            => 'tablet',
            'dose'            => '1 قرص',
            'frequency'       => 'روزانه',
            'route'           => 'oral',
            'duration_days'   => 30,
            'source'          => 'manual',
            'sort_order'      => 0,
            'created_at'      => $now,
        ]);

        return $rxId;
    }

    private function insertInvoice(
        int $clinicId,
        int $locId,
        int $patientId,
        int $visitId,
        int $actorId,
        string $number,
        string $total,
        string $paid,
        string $balance,
        string $status
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id'            => $clinicId,
            'location_id'          => $locId,
            'invoice_number'       => $number,
            'patient_id'           => $patientId,
            'visit_id'             => $visitId,
            'status'               => $status,
            'subtotal'             => $total,
            'total'                => $total,
            'currency'             => 'IRR',
            'paid_amount'          => $paid,
            'balance'              => $balance,
            'issued_by_wp_user_id' => $actorId,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        return (int) $wpdb->insert_id;
    }
}

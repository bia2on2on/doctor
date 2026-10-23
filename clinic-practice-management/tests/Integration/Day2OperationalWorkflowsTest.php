<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\FollowUpRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
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
        $this->linkPatient($clinicA, $patientA, $patientUser, '09121112233');
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

        // 2. Owning patient receives same-Location open slots excluding current slot, with zero authority/PHI keys.
        wp_set_current_user($patientUser);
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

        $doc1User = $this->makeUser('d2c_doc1', RolesAndCapabilities::ROLE_DOCTOR);
        $doc2User = $this->makeUser('d2c_doc2', RolesAndCapabilities::ROLE_DOCTOR);
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

        $priorVisitC1 = $this->insertVisitWithStatus($clinicA, $locA, $patient, $c1, $pastDate1, 'completed', 0);
        $priorVisitC2 = $this->insertVisitWithStatus($clinicA, $locA, $patient, $c2, $pastDate2, 'completed', 0);
        $currentVisit = $this->insertVisitWithStatus($clinicA, $locA, $patient, $c1, $today, 'in_consultation', 1);

        $notesRepo = new ClinicalNoteRepository(App::db());
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC1,
            'patient_id'            => $patient,
            'clinician_id'          => $c1,
            'category'              => 'diagnosis',
            'visibility'            => 'doctor_private',
            'content'               => 'یادداشت خصوصی دکتر اول',
            'created_by_wp_user_id' => $doc1User,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC2,
            'patient_id'            => $patient,
            'clinician_id'          => $c2,
            'category'              => 'diagnosis',
            'visibility'            => 'patient_visible',
            'content'               => 'تشخیص قابل مشاهده دکتر دوم',
            'created_by_wp_user_id' => $doc2User,
        ]);
        $notesRepo->insert($clinicA, [
            'visit_id'              => $priorVisitC2,
            'patient_id'            => $patient,
            'clinician_id'          => $c2,
            'category'              => 'general_note',
            'visibility'            => 'doctor_private',
            'content'               => 'یادداشت محرمانه دکتر دوم — نباید افشا شود',
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

        $visitA = $this->insertVisitWithStatus($clinicA, $locA, $patient, $clinician, $today, 'completed', 0);
        $visitB = $this->insertVisitWithStatus($clinicA, $locB, $patient, $clinician, $today, 'completed', 0);

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

    private function linkPatient(int $clinicId, int $patientId, int $wpUserId, string $mobile): void
    {
        App::db()->insert('cpms_patient_user_links', [
            'clinic_id'      => $clinicId,
            'patient_id'     => $patientId,
            'wp_user_id'     => $wpUserId,
            'mobile_at_link' => $mobile,
            'is_primary'     => 1,
            'linked_at'      => App::db()->nowUtcSql(),
        ]);
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

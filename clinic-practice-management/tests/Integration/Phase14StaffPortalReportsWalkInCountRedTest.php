<?php
/**
 * Phase 14 Slice 3 — TEST-ONLY RED (no production implementation in this change).
 *
 * Future capability under contract (aggregate-only "Walk-in visits recorded" count
 * for ONE explicit recorded visit date):
 *   COUNT of Visit records whose persisted `source` is exactly `walk_in` AND whose
 *   persisted `visit_date` is the selected date — inside the trusted Clinic only.
 *
 * It is explicitly NOT:
 *   - unique patients (one synthetic patient backs every seeded row on purpose);
 *   - an appointment count;
 *   - a completed-visit count (incomplete / cancelled walk-in rows still count);
 *   - Reception-created-only walk-ins (no creator/appointment filter exists);
 *   - a claim about one universal Location-local Clinic day (the metric is the
 *     already-recorded `visits.visit_date`, not a timezone-derived day).
 *
 * Security / scope contract preserved:
 *   1. the existing ReportsController / ReportService architecture is reused;
 *   2. authority stays the existing `cpms_report_read` — `cpms_patient_read` is NOT
 *      added merely because the existing walk-in LIST report carries patient rows;
 *   3. the trusted Clinic 0/1/N selector behaviour delivered in Staff Reports is
 *      unchanged — a Clinic id is a selector only, the REST boundary revalidates;
 *   4. own-vs-Clinic clinician scope stays server-derived;
 *   5. the response is aggregate-only (no patient/visit rows, no PHI);
 *   6. the aggregate counts ALL qualifying rows and does not inherit the list
 *      report's 500-row cap / `has_more` semantics;
 *   7. the future UI sends ONE explicit valid YYYY-MM-DD date as `from` and `to`.
 *
 * ROUTING SAFETY: the new type is intended for normal GET/read reporting ONLY.
 * Being accepted for GET must never authorize `/print` or `/export`. Because
 * ReportsController shares one `TYPE_PATTERN` across the read, print and export
 * routes, this suite pins the intended safe endpoint behaviour: the aggregate type
 * answers on the GET route and stays unreachable (404) on print and export — even
 * for an actor that holds `cpms_export`. The existing print/export contracts are
 * asserted unchanged in the same test so the guard cannot pass by weakening them.
 *
 * INTENDED PRODUCT RED: the `walk_ins_recorded` read type / route / query does not
 * exist yet, so `GET /clinic/v1/reports/walk_ins_recorded` answers
 * 404 `rest_no_route`. Everything these tests build on (bootstrap, migrations,
 * schema, fixtures, `cpms_report_read` authorization, Clinic 0/1/N selector,
 * own-vs-Clinic scope, the existing walk-in LIST report with its 500-row cap, the
 * existing aggregate, print and export contracts) is already delivered and is
 * asserted FIRST in every test, so those parts stay GREEN. The new type is written
 * as a plain string constant rather than a new class/constant reference on purpose,
 * so the failure is an assertion, never an undefined-constant fatal.
 *
 * Local evidence: this sandbox has no PHP CLI, Composer vendor tree or WordPress
 * test library, so the Integration CI run is the evidence source for the RED.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase14StaffPortalReportsWalkInCountRedTest extends WP_UnitTestCase
{
    /** The intended aggregate-only read type (a string literal, not a new class constant). */
    private const TYPE = 'walk_ins_recorded';

    private const ROUTE = '/clinic/v1/reports/walk_ins_recorded';
    private const LIST_ROUTE = '/clinic/v1/reports/walk_ins';
    private const AGGREGATE_ROUTE = '/clinic/v1/reports/visit_duration';
    private const PRINT_ROUTE = '/clinic/v1/reports/visit_duration/print';
    private const EXPORT_ROUTE = '/clinic/v1/reports/visit_duration/export';

    /** Fixed business dates (never "today"). */
    private const DATE = '2026-03-14';
    private const OTHER_DATE = '2026-03-15';
    private const EMPTY_DATE = '2026-03-20';

    /** Fixture scale for the selected Clinic A / selected date. */
    private const BULK_COMPLETE_ROWS = 520;
    private const INCOMPLETE_ROWS = 3;
    private const CANCELLED_ROWS = 2;
    private const OTHER_DATE_ROWS = 2;
    private const SCHEDULED_ROWS = 2;
    private const CLINIC_A_WALK_INS = 525;
    private const CLINIC_A_OTHER_DATE = 2;
    private const CLINIC_B_WALK_INS = 7;

    /** Fixture scale for the own-vs-Clinic scope test. */
    private const OTHER_CLINICIAN_ROWS = 4;
    private const OWN_CLINICIAN_ROWS = 6;
    private const OWN_SCOPE_CLINIC_ROWS = 10;

    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $clinicForeign = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        App::migrations()->migrate();

        $db = App::db();
        $this->clinicA = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $this->clinicA, 'fixture: seeded Clinic A after migrations');
        $organizationId = (int) $db->fetchValue(
            'SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d',
            [$this->clinicA]
        );
        self::assertGreaterThan(0, $organizationId, 'fixture: Clinic A belongs to an Organization');

        $this->locationA = $this->primaryLocation($this->clinicA);
        $this->clinicB = $this->insertClinic($organizationId, 'Walk In Count Clinic Beta');
        $this->locationB = $this->primaryLocation($this->clinicB);
        // Nobody in this suite is a member of the foreign Clinic.
        $this->clinicForeign = $this->insertClinic($organizationId, 'Walk In Count Clinic Foreign');
        $this->clinicianA = $this->insertClinician($this->clinicA, null, 'Walk In Count Doctor Alpha');
        $this->clinicianB = $this->insertClinician($this->clinicB, null, 'Walk In Count Doctor Beta');

        // Fixture arithmetic guard — a wrong expectation must never masquerade as the RED.
        self::assertSame(
            self::CLINIC_A_WALK_INS,
            self::BULK_COMPLETE_ROWS + self::INCOMPLETE_ROWS + self::CANCELLED_ROWS,
            'fixture arithmetic: Clinic A walk-in rows on the selected date'
        );
        self::assertSame(
            self::OWN_SCOPE_CLINIC_ROWS,
            self::OTHER_CLINICIAN_ROWS + self::OWN_CLINICIAN_ROWS,
            'fixture arithmetic: Clinic aggregate rows for the own-scope test'
        );

        SystemClinicResolver::flush();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        parent::tearDown();
    }

    // ============ CONTROL — the existing LIST report keeps its 500-row cap and its patient_read requirement (GREEN) ============

    public function testControlExistingWalkInListReportKeepsItsFiveHundredRowCapAndPatientReadRequirement(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        $this->seedClinicAWalkIns($this->insertPatient($this->clinicA));
        $this->seedClinicBWalkIns($this->insertPatient($this->clinicB));

        // Delivered preconditions — must stay GREEN before anything else.
        self::assertTrue(get_userdata($reporter)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: global cpms_report_read');
        self::assertTrue(get_userdata($reporter)->has_cap(RolesAndCapabilities::PATIENT_READ), 'precondition: the manager also holds cpms_patient_read');
        self::assertTrue(App::authorization_service()->can($reporter, $this->clinicA, RolesAndCapabilities::REPORT_READ), 'precondition: Clinic-scoped cpms_report_read');
        self::assertSame(self::CLINIC_A_WALK_INS, $this->walkInVisitsInDb($this->clinicA, self::DATE), 'fixture scale: Clinic A really holds more than 500 walk-in rows on the selected date');
        self::assertSame(self::CLINIC_B_WALK_INS, $this->walkInVisitsInDb($this->clinicB, self::DATE), 'fixture scale: Clinic B walk-in rows on the selected date');

        wp_set_current_user($reporter);
        $range = ['from' => self::DATE, 'to' => self::DATE];

        $list = $this->dispatch('GET', self::LIST_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(200, $list->get_status(), 'existing walk_ins LIST report stays authorized — ' . $this->errorCode($list));
        $data = $this->payload($list);
        self::assertSame('walk_ins', $data['type'] ?? null);
        self::assertSame('clinic', $data['scope'] ?? null);
        self::assertSame(self::DATE, $data['from'] ?? null);
        self::assertSame(self::DATE, $data['to'] ?? null);
        self::assertSame(500, $data['summary']['count'] ?? null, 'the EXISTING list report is capped at 500 visible rows');
        self::assertCount(500, $data['rows'] ?? [], 'the EXISTING list report returns 500 patient rows');
        self::assertTrue($data['has_more'] ?? false, 'the EXISTING list report reports has_more');

        // The existing LIST report needs cpms_patient_read because it carries patient rows.
        // The aggregate count must NOT inherit that requirement (ReportService caps model).
        $accountant = $this->makeUser(RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $this->clinicA, 'cpms_accountant');
        self::assertTrue(get_userdata($accountant)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: the accountant holds cpms_report_read');
        self::assertFalse(get_userdata($accountant)->has_cap(RolesAndCapabilities::PATIENT_READ), 'precondition: the accountant holds no cpms_patient_read');
        wp_set_current_user($accountant);
        $deniedList = $this->dispatch('GET', self::LIST_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(403, $deniedList->get_status(), 'the existing LIST report still requires cpms_patient_read');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($deniedList));
    }

    // ============ CONTROL — existing aggregate / print / export contracts stay unchanged (GREEN) ============

    public function testControlExistingAggregatePrintAndExportContractsRemainUnchanged(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        $range = ['from' => self::DATE, 'to' => self::DATE];

        wp_set_current_user($reporter);
        $aggregate = $this->dispatch('GET', self::AGGREGATE_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(200, $aggregate->get_status(), 'existing aggregate route stays authorized — ' . $this->errorCode($aggregate));
        $data = $this->payload($aggregate);
        self::assertSame('visit_duration', $data['type'] ?? null);
        self::assertSame('clinic', $data['scope'] ?? null);
        self::assertSame(self::DATE, $data['from'] ?? null);
        self::assertSame(self::DATE, $data['to'] ?? null);
        self::assertSame([], $data['rows'] ?? null, 'existing aggregate stays aggregate-only when no sample qualifies');

        $print = $this->dispatch('GET', self::PRINT_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(200, $print->get_status(), 'existing print route still serves an authorized reader — ' . $this->errorCode($print));
        self::assertStringContainsString('watermark', $this->bodyText($print), 'existing print still renders the watermark surface');

        $exportDenied = $this->dispatch('POST', self::EXPORT_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(403, $exportDenied->get_status(), 'cpms_export is still required for an existing type — ' . $this->errorCode($exportDenied));
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($exportDenied));

        $accountant = $this->makeUser(RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $this->clinicA, 'cpms_accountant');
        self::assertTrue(get_userdata($accountant)->has_cap(RolesAndCapabilities::EXPORT), 'precondition: the accountant holds cpms_export');
        self::assertSame(0, $this->reportExportJobCount($this->clinicA, $accountant), 'precondition: no export job yet');
        wp_set_current_user($accountant);
        $exportAllowed = $this->dispatch('POST', self::EXPORT_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(202, $exportAllowed->get_status(), 'the existing export contract still accepts a cpms_export holder — ' . $this->errorCode($exportAllowed));
        self::assertSame(1, $this->reportExportJobCount($this->clinicA, $accountant), 'the existing export contract still enqueues exactly one report.export job');
    }

    // ============ INTENDED RED — the aggregate-only "Walk-in visits recorded" count contract ============

    public function testWalkInVisitsRecordedAggregateCountsEveryWalkInVisitOnOneExplicitDate(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        $membershipA = cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        $membershipB = cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        $patientA = $this->insertPatient($this->clinicA);
        $patientB = $this->insertPatient($this->clinicB);
        $this->seedClinicAWalkIns($patientA);
        $this->seedClinicBWalkIns($patientB);

        // Delivered preconditions — must stay GREEN before the product assertion.
        self::assertGreaterThan(0, $membershipA, 'fixture: durable ACTIVE membership in Clinic A');
        self::assertGreaterThan(0, $membershipB, 'fixture: durable ACTIVE membership in Clinic B');
        self::assertTrue(get_userdata($reporter)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: existing global cpms_report_read');
        self::assertTrue(App::authorization_service()->can($reporter, $this->clinicA, RolesAndCapabilities::REPORT_READ), 'precondition: Clinic-scoped cpms_report_read in A');
        self::assertTrue(App::authorization_service()->can($reporter, $this->clinicB, RolesAndCapabilities::REPORT_READ), 'precondition: Clinic-scoped cpms_report_read in B');
        self::assertSame(self::CLINIC_A_WALK_INS, $this->walkInVisitsInDb($this->clinicA, self::DATE), 'fixture scale: 525 walk-in rows in Clinic A on the selected date');
        self::assertSame(self::CLINIC_A_OTHER_DATE, $this->walkInVisitsInDb($this->clinicA, self::OTHER_DATE), 'fixture scale: 2 walk-in rows in Clinic A on another date');
        self::assertSame(self::SCHEDULED_ROWS, $this->scheduledVisitsInDb($this->clinicA, self::DATE), 'fixture scale: 2 non-walk-in rows in Clinic A on the selected date');
        self::assertSame(self::CLINIC_B_WALK_INS, $this->walkInVisitsInDb($this->clinicB, self::DATE), 'fixture scale: 7 walk-in rows in Clinic B on the selected date');

        wp_set_current_user($reporter);
        $range = ['from' => self::DATE, 'to' => self::DATE];
        $auditBefore = $this->reportReadAuditCount();

        // ---------- INTENDED PRODUCT RED ----------
        $response = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA);
        self::assertSame(
            200,
            $response->get_status(),
            'INTENDED RED: the aggregate-only "Walk-in visits recorded" report type/route/query does not exist — '
                . 'GET ' . self::ROUTE . ' answered ' . $response->get_status() . ' ' . $this->errorCode($response)
        );
        $data = $this->payload($response);
        self::assertSame(self::TYPE, $data['type'] ?? null, 'the aggregate read type is walk_ins_recorded');
        self::assertSame('clinic', $data['scope'] ?? null, 'scope is derived by the existing server boundary');
        self::assertSame(self::DATE, $data['from'] ?? null);
        self::assertSame(self::DATE, $data['to'] ?? null, 'one explicit YYYY-MM-DD date is sent as from and to');
        self::assertSame(['count'], array_keys($data['summary'] ?? []), 'aggregate summary is the count only');
        self::assertSame(
            self::CLINIC_A_WALK_INS,
            $data['summary']['count'] ?? null,
            'ALL qualifying walk_in rows on the selected visit_date — not the 500-row list cap, not unique patients, not completed-only'
        );
        self::assertSame([], $data['rows'] ?? null, 'aggregate-only projection: no visit or patient rows');
        self::assertFalse($data['has_more'] ?? true, 'the aggregate does not inherit the list report has_more semantics');
        self::assertSame($auditBefore + 1, $this->reportReadAuditCount(), 'the existing REPORT_READ audit path is reached');

        // Aggregate-only / PHI-free: one synthetic patient backs every seeded row.
        $json = (string) wp_json_encode($data);
        self::assertStringNotContainsString($patientA['name'], $json, 'no synthetic patient name in the aggregate projection');
        self::assertStringNotContainsString($patientA['mrn'], $json, 'no synthetic MRN in the aggregate projection');
        self::assertStringNotContainsString($patientB['name'], $json, 'no foreign-Clinic patient name in the aggregate projection');
        self::assertStringNotContainsString($patientB['mrn'], $json, 'no foreign-Clinic MRN in the aggregate projection');
        self::assertDoesNotMatchRegularExpression(
            '/patient_name|patient_id|\bmrn\b|first_name|last_name|clinician_name|check_in_at|consultation_started_at/i',
            $json,
            'aggregate projection carries no patient, clinician or clinical-detail field'
        );

        // Another Clinic is isolated — the trusted Clinic id is a selector only.
        $clinicB = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicB);
        self::assertSame(200, $clinicB->get_status(), 'the same actor is authorized in Clinic B — ' . $this->errorCode($clinicB));
        $dataB = $this->payload($clinicB);
        self::assertSame('clinic', $dataB['scope'] ?? null);
        self::assertSame(self::CLINIC_B_WALK_INS, $dataB['summary']['count'] ?? null, 'Clinic B is counted separately and never blended with Clinic A');
        self::assertSame([], $dataB['rows'] ?? null);

        // Other dates are excluded from the selected date, and zero is a valid count.
        $otherDate = $this->dispatch('GET', self::ROUTE, ['from' => self::OTHER_DATE, 'to' => self::OTHER_DATE], (string) $this->clinicA);
        self::assertSame(200, $otherDate->get_status(), 'another explicit date is a valid request — ' . $this->errorCode($otherDate));
        self::assertSame(
            self::CLINIC_A_OTHER_DATE,
            $this->payload($otherDate)['summary']['count'] ?? null,
            'only the walk-in rows recorded on the selected visit_date are counted'
        );
        $empty = $this->dispatch('GET', self::ROUTE, ['from' => self::EMPTY_DATE, 'to' => self::EMPTY_DATE], (string) $this->clinicA);
        self::assertSame(200, $empty->get_status(), 'a date with no walk-in rows is a valid request — ' . $this->errorCode($empty));
        self::assertSame(0, $this->payload($empty)['summary']['count'] ?? null, 'zero is a valid aggregate count');
        self::assertSame([], $this->payload($empty)['rows'] ?? null);

        // One explicit, valid YYYY-MM-DD date is required.
        $invalid = $this->dispatch('GET', self::ROUTE, ['from' => 'not-a-date', 'to' => 'not-a-date'], (string) $this->clinicA);
        self::assertSame(422, $invalid->get_status(), 'an invalid date is rejected — ' . $this->errorCode($invalid));
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errorCode($invalid));

        // A cpms_patient_read holder is NOT required for the aggregate count.
        $accountant = $this->makeUser(RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $this->clinicA, 'cpms_accountant');
        self::assertFalse(get_userdata($accountant)->has_cap(RolesAndCapabilities::PATIENT_READ), 'precondition: the accountant holds no cpms_patient_read');
        wp_set_current_user($accountant);
        $noPatientRead = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA);
        self::assertSame(200, $noPatientRead->get_status(), 'the aggregate count must not require cpms_patient_read — ' . $this->errorCode($noPatientRead));
        self::assertSame(self::CLINIC_A_WALK_INS, $this->payload($noPatientRead)['summary']['count'] ?? null);

        // Fail closed: revoked / foreign / unauthorized Clinic selection.
        wp_set_current_user($reporter);
        self::assertSame(200, $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicB)->get_status(), 'precondition: Clinic B valid before revocation');
        App::membership_service()->suspend_membership($membershipB);
        $revoked = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicB);
        self::assertSame(403, $revoked->get_status(), 'a revoked Clinic selection fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($revoked));
        self::assertSame(
            200,
            $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA)->get_status(),
            'the still-authorized Clinic remains available'
        );

        $foreign = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicForeign);
        self::assertSame(403, $foreign->get_status(), 'a foreign Clinic id creates no authority');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($foreign));
        self::assertStringNotContainsString('"count"', $this->bodyText($foreign), 'no aggregate payload leaks on a denied request');

        $secretary = $this->makeUser(RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $this->clinicA, 'cpms_secretary');
        self::assertFalse(get_userdata($secretary)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: the secretary holds no cpms_report_read');
        wp_set_current_user($secretary);
        $noCap = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA);
        self::assertSame(403, $noCap->get_status(), 'an actor without cpms_report_read fails closed');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($noCap));
    }

    // ============ INTENDED RED — own-vs-Clinic clinician scope stays server-derived ============

    public function testOwnClinicianAggregateRemainsServerDerivedAndDiffersFromClinicAggregate(): void
    {
        $doctor = $this->makeUser(RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($doctor, $this->clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $this->clinicB, 'cpms_doctor');
        $ownClinician = $this->insertClinician($this->clinicA, $doctor, 'Walk In Count Own Doctor');
        $patientA = $this->insertPatient($this->clinicA);
        $patientB = $this->insertPatient($this->clinicB);

        // Another clinician in Clinic A plus the linked doctor's own rows.
        for ($i = 0; $i < self::OTHER_CLINICIAN_ROWS; $i++) {
            $this->insertVisit($this->clinicA, $this->locationA, $this->clinicianA, $patientA, 'walk_in', 'consultation_completed', self::DATE);
        }
        for ($i = 0; $i < self::OWN_CLINICIAN_ROWS; $i++) {
            $status = $i % 2 === 0 ? 'consultation_completed' : 'waiting';
            $this->insertVisit($this->clinicA, $this->locationA, $ownClinician, $patientA, 'walk_in', $status, self::DATE);
        }
        // Clinic B rows must never widen the linked doctor's own scope.
        for ($i = 0; $i < self::CLINIC_B_WALK_INS; $i++) {
            $this->insertVisit($this->clinicB, $this->locationB, $this->clinicianB, $patientB, 'walk_in', 'consultation_completed', self::DATE);
        }

        self::assertTrue(get_userdata($doctor)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: global cpms_report_read');
        self::assertTrue(App::authorization_service()->can($doctor, $this->clinicA, RolesAndCapabilities::REPORT_READ), 'precondition: Clinic-scoped cpms_report_read');
        self::assertSame(self::OWN_SCOPE_CLINIC_ROWS, $this->walkInVisitsInDb($this->clinicA, self::DATE), 'fixture scale: Clinic A rows for the scope test');

        wp_set_current_user($doctor);
        $range = ['from' => self::DATE, 'to' => self::DATE];

        // ---------- INTENDED PRODUCT RED ----------
        $own = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA);
        self::assertSame(
            200,
            $own->get_status(),
            'INTENDED RED: the aggregate-only "Walk-in visits recorded" report type/route/query does not exist — '
                . 'GET ' . self::ROUTE . ' answered ' . $own->get_status() . ' ' . $this->errorCode($own)
        );
        $ownData = $this->payload($own);
        self::assertSame('own', $ownData['scope'] ?? null, 'own-vs-Clinic scope is derived by the server from the Clinician link');
        self::assertSame(self::OWN_CLINICIAN_ROWS, $ownData['summary']['count'] ?? null, 'the own-clinician count differs from the Clinic aggregate');
        self::assertNotSame(self::OWN_SCOPE_CLINIC_ROWS, $ownData['summary']['count'] ?? null, 'the Clinic selector cannot widen a linked doctor to Clinic scope');
        self::assertSame([], $ownData['rows'] ?? null, 'aggregate-only projection in own scope');

        $ownB = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicB);
        self::assertSame(200, $ownB->get_status(), 'the linked doctor is authorized in Clinic B — ' . $this->errorCode($ownB));
        $ownBData = $this->payload($ownB);
        self::assertSame('own', $ownBData['scope'] ?? null);
        self::assertSame(0, $ownBData['summary']['count'] ?? null, 'own scope never widens to another Clinic; zero is a valid count');

        // The unlinked actor receives the Clinic aggregate for the same data.
        $manager = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($manager, $this->clinicA, 'cpms_manager');
        wp_set_current_user($manager);
        $clinic = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA);
        self::assertSame(200, $clinic->get_status(), 'the unlinked actor is authorized — ' . $this->errorCode($clinic));
        $clinicData = $this->payload($clinic);
        self::assertSame('clinic', $clinicData['scope'] ?? null, 'no Clinician link keeps Clinic scope');
        self::assertSame(self::OWN_SCOPE_CLINIC_ROWS, $clinicData['summary']['count'] ?? null, 'the Clinic aggregate counts every qualifying row, not only the linked doctor\'s');
    }

    // ============ ROUTING SAFETY — the new read type must not become printable/exportable ============

    public function testNewAggregateReadTypeIsNotReachableThroughPrintOrExport(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        $range = ['from' => self::DATE, 'to' => self::DATE];

        // Delivered control: the existing print/export contracts are unchanged.
        wp_set_current_user($reporter);
        $print = $this->dispatch('GET', self::PRINT_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(200, $print->get_status(), 'existing print route still serves an authorized reader — ' . $this->errorCode($print));
        self::assertStringContainsString('watermark', $this->bodyText($print), 'existing print still renders the watermark surface');

        $exportDenied = $this->dispatch('POST', self::EXPORT_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(403, $exportDenied->get_status(), 'cpms_export is still required for an existing type — ' . $this->errorCode($exportDenied));
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCode($exportDenied));

        $accountant = $this->makeUser(RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $this->clinicA, 'cpms_accountant');
        self::assertTrue(get_userdata($accountant)->has_cap(RolesAndCapabilities::EXPORT), 'precondition: the accountant holds cpms_export');
        self::assertTrue(get_userdata($accountant)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: the accountant holds cpms_report_read');
        wp_set_current_user($accountant);
        $exportAllowed = $this->dispatch('POST', self::EXPORT_ROUTE, $range, (string) $this->clinicA);
        self::assertSame(202, $exportAllowed->get_status(), 'the existing export contract still accepts a cpms_export holder — ' . $this->errorCode($exportAllowed));
        self::assertSame(1, $this->reportExportJobCount($this->clinicA, $accountant), 'the existing export contract still enqueues exactly one report.export job');

        // ---------- ROUTING SAFETY (must hold in RED and stay true after GREEN) ----------
        // Being accepted for the normal GET read route must never authorize print.
        $newPrint = $this->dispatch('GET', self::ROUTE . '/print', $range, (string) $this->clinicA);
        self::assertSame(404, $newPrint->get_status(), 'the aggregate read type must not be authorized for print — ' . $this->errorCode($newPrint));
        self::assertStringNotContainsString('watermark', $this->bodyText($newPrint), 'no printable HTML surface for the aggregate read type');
        self::assertSame(0, $this->reportExportJobCount($this->clinicA, $accountant, self::TYPE), 'print never enqueues an export job');

        // ...and must never authorize CSV/export, not even for a cpms_export holder.
        $newExport = $this->dispatch('POST', self::ROUTE . '/export', $range, (string) $this->clinicA);
        self::assertSame(404, $newExport->get_status(), 'the aggregate read type must not be authorized for CSV export — ' . $this->errorCode($newExport));
        self::assertSame(0, $this->reportExportJobCount($this->clinicA, $accountant, self::TYPE), 'no report.export job is enqueued for the aggregate read type');
        self::assertSame(1, $this->reportExportJobCount($this->clinicA, $accountant), 'the existing export contract is untouched by the aggregate read type');

        // A read-only actor without cpms_export is equally unable to reach it.
        wp_set_current_user($reporter);
        $readerExport = $this->dispatch('POST', self::ROUTE . '/export', $range, (string) $this->clinicA);
        self::assertSame(404, $readerExport->get_status(), 'the aggregate read type is not reachable through export for a read-only actor — ' . $this->errorCode($readerExport));
        self::assertSame(0, $this->reportExportJobCount($this->clinicA, $accountant, self::TYPE), 'still no export job for the aggregate read type');
    }

    // ============ fail-closed guard that holds in RED and after GREEN ============

    public function testUnauthorizedRevokedAndForeignClinicAccessFailsClosed(): void
    {
        $reporter = $this->makeUser(RolesAndCapabilities::ROLE_MANAGER);
        $membershipB = cpms_test_seed_membership($reporter, $this->clinicB, 'cpms_manager');
        cpms_test_seed_membership($reporter, $this->clinicA, 'cpms_manager');
        $range = ['from' => self::DATE, 'to' => self::DATE];

        // Delivered control: the SAME boundary fails closed on an existing aggregate route.
        wp_set_current_user($reporter);
        $existingForeign = $this->dispatch('GET', self::AGGREGATE_ROUTE, $range, (string) $this->clinicForeign);
        self::assertSame(403, $existingForeign->get_status(), 'the existing REST boundary already fails closed for a foreign Clinic');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($existingForeign));
        self::assertSame(200, $this->dispatch('GET', self::AGGREGATE_ROUTE, $range, (string) $this->clinicA)->get_status(), 'the existing boundary still serves the authorized Clinic');

        App::membership_service()->suspend_membership($membershipB);
        $existingRevoked = $this->dispatch('GET', self::AGGREGATE_ROUTE, $range, (string) $this->clinicB);
        self::assertSame(403, $existingRevoked->get_status(), 'the existing REST boundary already fails closed for a revoked Clinic');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errorCode($existingRevoked));

        // The new aggregate route must fail closed too — never answer 200 for these actors.
        foreach ([[$this->clinicForeign, 'foreign'], [$this->clinicB, 'revoked']] as [$clinicId, $label]) {
            $denied = $this->dispatch('GET', self::ROUTE, $range, (string) $clinicId);
            self::assertNotSame(200, $denied->get_status(), 'the aggregate route must fail closed for a ' . $label . ' Clinic selection');
            self::assertStringNotContainsString('"count"', $this->bodyText($denied), 'no aggregate payload leaks for a ' . $label . ' Clinic selection');
        }

        $secretary = $this->makeUser(RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $this->clinicA, 'cpms_secretary');
        self::assertFalse(get_userdata($secretary)->has_cap(RolesAndCapabilities::REPORT_READ), 'precondition: the secretary holds no cpms_report_read');
        wp_set_current_user($secretary);
        $noCap = $this->dispatch('GET', self::ROUTE, $range, (string) $this->clinicA);
        self::assertNotSame(200, $noCap->get_status(), 'the aggregate route must fail closed for an actor without cpms_report_read');
        self::assertStringNotContainsString('"count"', $this->bodyText($noCap), 'no aggregate payload leaks for an unauthorized actor');
    }

    // ============ fixtures / helpers ============

    /** @param array<string, mixed> $params */
    private function dispatch(string $method, string $route, array $params, ?string $clinicHeader): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if ($clinicHeader !== null) {
            $request->set_header('X-CPMS-Clinic-Id', $clinicHeader);
        }

        return rest_do_request($request);
    }

    /**
     * 525 walk-in rows in Clinic A on the selected date (520 complete + 3 incomplete
     * + 2 cancelled), plus deliberately excluded rows on another date and a
     * deliberately excluded non-walk-in source.
     *
     * @param array{id:int,name:string,mrn:string} $patient
     */
    private function seedClinicAWalkIns(array $patient): void
    {
        for ($i = 0; $i < self::BULK_COMPLETE_ROWS; $i++) {
            $this->insertVisit($this->clinicA, $this->locationA, $this->clinicianA, $patient, 'walk_in', 'consultation_completed', self::DATE);
        }
        for ($i = 0; $i < self::INCOMPLETE_ROWS; $i++) {
            $this->insertVisit($this->clinicA, $this->locationA, $this->clinicianA, $patient, 'walk_in', 'waiting', self::DATE);
        }
        for ($i = 0; $i < self::CANCELLED_ROWS; $i++) {
            $this->insertVisit($this->clinicA, $this->locationA, $this->clinicianA, $patient, 'walk_in', 'cancelled', self::DATE);
        }
        for ($i = 0; $i < self::OTHER_DATE_ROWS; $i++) {
            $this->insertVisit($this->clinicA, $this->locationA, $this->clinicianA, $patient, 'walk_in', 'consultation_completed', self::OTHER_DATE);
        }
        for ($i = 0; $i < self::SCHEDULED_ROWS; $i++) {
            $this->insertVisit($this->clinicA, $this->locationA, $this->clinicianA, $patient, 'scheduled', 'consultation_completed', self::DATE);
        }
    }

    private function seedClinicBWalkIns(array $patient): void
    {
        for ($i = 0; $i < self::CLINIC_B_WALK_INS; $i++) {
            $status = $i % 2 === 0 ? 'consultation_completed' : 'waiting';
            $this->insertVisit($this->clinicB, $this->locationB, $this->clinicianB, $patient, 'walk_in', $status, self::DATE);
        }
    }

    /**
     * @param array{id:int,name:string,mrn:string} $patient
     */
    private function insertVisit(
        int $clinicId,
        int $locationId,
        int $clinicianId,
        array $patient,
        string $source,
        string $status,
        string $visitDate
    ): void {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'patient_id' => $patient['id'],
            'source' => $source,
            'status' => $status,
            'visit_date' => $visitDate,
            'check_in_at' => $visitDate . ' 08:00:00.000',
            'active' => $status === 'cancelled' ? 0 : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'visit fixture');
    }

    /** @return array{id:int,name:string,mrn:string} */
    private function insertPatient(int $clinicId): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $marker = 'P14WC-' . bin2hex(random_bytes(5));
        $mrn = 'MRN-' . $marker;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => $mrn,
            'first_name' => $marker,
            'last_name' => 'Synthetic',
            'mobile' => '0919' . random_int(1000000, 9999999),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'patient fixture');

        return ['id' => (int) $wpdb->insert_id, 'name' => $marker, 'mrn' => $mrn];
    }

    private function insertClinic(int $organizationId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $slug = 'p14wc-' . bin2hex(random_bytes(5));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinics', [
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'Asia/Tehran',
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'Clinic fixture');
        $clinicId = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name . ' Location',
            'slug' => $slug . '-location',
            'timezone' => 'America/Los_Angeles',
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]), 'Location fixture');

        return $clinicId;
    }

    private function primaryLocation(int $clinicId): int
    {
        $id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1',
            [$clinicId]
        );
        self::assertGreaterThan(0, $id, 'fixture: primary Location for Clinic ' . $clinicId);

        return $id;
    }

    private function insertClinician(int $clinicId, ?int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $row = ['clinic_id' => $clinicId, 'full_name' => $name, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now];
        if ($wpUserId !== null) {
            $row['wp_user_id'] = $wpUserId;
        }
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', $row), 'clinician fixture');

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $role): int
    {
        $suffix = bin2hex(random_bytes(5));
        $id = (int) wp_create_user('p14wc_' . $suffix, 'test-password-123', 'p14wc_' . $suffix . '@test.local');
        self::assertGreaterThan(0, $id, 'user fixture');
        get_userdata($id)->set_role($role);

        return $id;
    }

    private function walkInVisitsInDb(int $clinicId, string $visitDate): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits')
                . ' WHERE clinic_id = %d AND source = %s AND visit_date = %s',
            [$clinicId, 'walk_in', $visitDate]
        );
    }

    private function scheduledVisitsInDb(int $clinicId, string $visitDate): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_visits')
                . ' WHERE clinic_id = %d AND source = %s AND visit_date = %s',
            [$clinicId, 'scheduled', $visitDate]
        );
    }

    private function reportReadAuditCount(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            ['REPORT_READ']
        );
    }

    /**
     * Durable `report.export` jobs for one trusted Clinic (optionally one actor and
     * one report type). `cpms_jobs` has no clinic_id column — the tenant scope of a
     * job lives in its durable payload.
     */
    private function reportExportJobCount(int $clinicId, int $actorUserId = 0, ?string $type = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs')
            . " WHERE type = %s AND CAST(JSON_EXTRACT(payload_json, '$.clinic_id') AS UNSIGNED) = %d";
        $params = ['report.export', $clinicId];
        if ($actorUserId > 0) {
            $sql .= " AND CAST(JSON_EXTRACT(payload_json, '$.actor_id') AS UNSIGNED) = %d";
            $params[] = $actorUserId;
        }
        if ($type !== null) {
            $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.type')) = %s";
            $params[] = $type;
        }

        return (int) App::db()->fetchValue($sql, $params);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Response $response): array
    {
        $data = $response->get_data();
        if (is_array($data) && array_key_exists('data', $data)) {
            return is_array($data['data']) ? $data['data'] : [];
        }

        return is_array($data) ? $data : [];
    }

    private function errorCode(WP_REST_Response $response): string
    {
        $data = $response->get_data();

        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }

    /** Response body as searchable text (never casts an array to string). */
    private function bodyText(WP_REST_Response $response): string
    {
        $data = $response->get_data();
        if (is_string($data)) {
            return $data;
        }

        return is_array($data) ? (string) wp_json_encode($data) : '';
    }
}

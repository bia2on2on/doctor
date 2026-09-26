<?php
/**
 * Phase 10 — Staff Portal Doctor Module: Visit Complete — TEST-ONLY RED.
 *
 * Owner-approved slice contract:
 *   Inside the EXISTING Visit Workspace of the doctor clinical module, which is
 *   mounted in the shared Staff Portal (canonical `cpms-staff-portal`; the
 *   legacy `cpms-doctor-portal` URL stays a single 302 entry redirect for an
 *   eligible doctor), the authoring doctor completes the currently authorized
 *   Visit. The action is HYBRID: a REST call with no routine full-page reload.
 *   Reopen is OUT and stays unexposed in the Staff Portal.
 *
 * Intended portal boundary is a small adapter/guard in front of the ALREADY
 * MERGED shared E14 service. It follows the merged Visit Workspace pattern
 * (record/notes/prescriptions/recommendations/follow-ups):
 *   POST /clinic/v1/doctor/portal/visits/{id}/complete
 * The shared staff/admin E14 route (POST /clinic/v1/visits/{id}/complete) is
 * NOT globally tightened, and there is no new Complete backend/state machine.
 *
 * Authority (selector headers are validated against persisted authority and
 * never trusted by themselves; clinician_id is NEVER client authority):
 *   authenticated WP user + REST nonce + doctor role + cpms_consult_complete
 *   + server-derived active clinician identity + active trusted Clinic
 *   + trusted operational Location (0 => fail closed, 1 => auto, N>1 =>
 *     explicit REQUIRED, foreign/inactive/unassigned => fail closed)
 *   + Visit owned by that clinician at that Clinic/Location.
 *   Cross-doctor, foreign-Clinic and foreign-Location Visit selectors fail
 *   without revealing the Visit (404 CLINIC_NOT_FOUND). The portal guard runs
 *   BEFORE the Chief Complaint check, so a foreign Visit never leaks a 422.
 *
 * Live shared Complete contract, verified pre-write on main
 * aa42888c7ab641c86d1c45c200dfb887861e6ff1:
 *   ClinicalService::completeConsultation — requireRole doctor → requireCap
 *   CONSULT_COMPLETE → requireVisit → authorizeScoped; then, when the per-Clinic
 *   setting `clinical.require_chief_complaint` (default TRUE, not in
 *   Settings::DEFAULTS) is true and the Visit has no non-archived
 *   `chief_complaint` note (visibility-agnostic), the result is
 *   422 CLINIC_VALIDATION_FAILED with data missing=chief_complaint. Otherwise
 *   the service calls VisitService::transition('complete') (V10:
 *   in_consultation → consultation_completed, doctor only, ownership guarded,
 *   row lock). That writes ONE status-history row, consultation_completed_at,
 *   and audit VISIT_COMPLETE, followed by audit CONSULTATION_COMPLETED. A repeat
 *   or wrong-state attempt returns 409 CLINIC_INVALID_TRANSITION with
 *   data {from, event}. Complete creates no invoice, payment or notification:
 *   invoice_ready (→ awaiting_payment) is system/secretary only and runs
 *   through FinanceService.
 *
 * Chief Complaint: the established `chief_complaint` note category already
 * exists, and the merged portal notes route accepts it. The portal composer
 * currently hardcodes `clinical_note`, however, so a doctor cannot satisfy the
 * policy inside the Visit Workspace today. This RED stays technique-neutral:
 * a category option in the existing composer OR a dedicated field both
 * satisfy it, provided the UI uses the established `chief_complaint` category
 * (no new category/schema).
 *
 * INTENDED PRODUCT RED (missing behavior, verified pre-write):
 *   - the portal Complete route does not exist (REST dispatch returns
 *     404 rest_no_route) — Groups 1..6 anchor here;
 *   - the module template/JS has no Complete control, no Chief Complaint
 *     authoring and no hybrid wiring — Group 8 anchors here, together with the
 *     harness contract that the GREEN browser journey must live in the
 *     EXISTING bin/pilot-doctor-portal.py driven by the pilot gate.
 * Group 7 and the marked controls in the other groups are GREEN today and MUST
 * stay GREEN.
 *
 * Naming note for GREEN: the merged Phase 10 suites forbid the substrings
 * 'workspace-complete', 'visit-complete', 'workspace-reopen' and
 * 'visit-reopen' in the module UI, plus any `data-action=` near
 * 'in_consultation'. The markers below (workspace-consult-complete-*) are
 * chosen so that they do not collide with those guards.
 *
 * Test-only: no product PHP/template/CSS/JS, no migration (latest remains
 * 2026_09_20_0022), no workflow change, no docs closure.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Machine\VisitMachine;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10StaffPortalVisitCompleteRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const PORTAL_COMPLETE = 'clinic/v1/doctor/portal/visits/%d/complete';
    private const PORTAL_NOTES = 'clinic/v1/doctor/portal/visits/%d/notes';
    private const SHARED_COMPLETE = 'clinic/v1/visits/%d/complete';
    private const DOCTOR_TODAY = 'clinic/v1/doctor/today';
    private const FIXED_UTC = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const TZ_TEHRAN = 'Asia/Tehran';
    private const LATEST_MIGRATION = '2026_09_26_0023';

    /** Established clinical note enums; Complete must not add a category or visibility state. */
    private const NOTE_CATEGORY_ENUM = "enum('chief_complaint','history','examination','diagnosis','clinical_note','recommendation_text','private_note','other')";
    private const NOTE_VISIBILITY_ENUM = "enum('patient_visible','doctor_private')";

    /** Accepted shared Staff Portal shell root markers (same set as the merged shell suite). */
    private const STAFF_SHELL_ROOT_PATTERNS = [
        'data-cpms-staff-portal-shell',
        'data-cpms-staff-shell',
        'cpms-staff-portal-shell',
        'cpms-staff-shell',
        'data-cpms-portal="staff"',
    ];

    private $filterCb = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();
        do_action('rest_api_init');

        $fixed = new \DateTimeImmutable(self::FIXED_UTC, new \DateTimeZone('UTC'));
        VisitService::setTestNowUtc($fixed);
        $this->filterCb = static function () use ($fixed): \DateTimeImmutable {
            return $fixed;
        };
        add_filter('cpms_visit_now_utc', $this->filterCb);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        VisitService::setTestNowUtc(null);
        if ($this->filterCb !== null) {
            remove_filter('cpms_visit_now_utc', $this->filterCb);
            $this->filterCb = null;
        }
        parent::tearDown();
    }

    // ============ Group 1 — PORTAL COMPLETE ENTRY (intended RED; shared backend control GREEN) ============

    public function testGroup1_PortalCompleteEntryDelegatesToSharedService(): void
    {
        $fx = $this->makePortalStage('g1');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientPortal = $this->insertPatient($fx['clinic'], 'g1_portal');
        $visitPortal = $this->insertVisit($patientPortal, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitPortal, $patientPortal, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        $patientShared = $this->insertPatient($fx['clinic'], 'g1_shared');
        $visitShared = $this->insertVisit($patientShared, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitShared, $patientShared, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);

        $this->assertClinicianBinding($fx['clinician'], $fx['clinic'], $fx['doctor']);
        $this->assertVisitBinding($visitPortal, $fx['clinic'], $fx['location'], $fx['clinician'], $patientPortal);
        $this->assertVisitBinding($visitShared, $fx['clinic'], $fx['location'], $fx['clinician'], $patientShared);
        self::assertSame('in_consultation', $this->visitStatus($visitPortal), 'G1.fixture: portal Visit starts in_consultation');
        self::assertSame(1, $this->chiefComplaintCount($visitPortal), 'G1.fixture: Chief Complaint really persisted on the portal Visit');

        // ---- GREEN controls: shared E14 backend + merged workspace routes exist ----
        self::assertTrue($this->routeRegistered('/' . self::REST_NS . '/visits/(?P<id>\d+)/complete'), 'G1 control: shared E14 Complete route registered');
        self::assertTrue(method_exists(\ClinicCore\Application\Clinical\ClinicalService::class, 'completeConsultation'), 'G1 control: shared completeConsultation service reused');
        self::assertTrue($this->portalRouteRegistered('/notes'), 'G1 control: merged portal notes route registered');
        self::assertTrue($this->portalRouteRegistered('/record'), 'G1 control: merged portal record route registered');

        wp_set_current_user($fx['doctor']);
        $rShared = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitShared), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(200, $rShared, 'G1 control: shared E14 Complete still succeeds for the owning doctor');
        $sharedPayload = $this->payload($rShared);
        self::assertSame('consultation_completed', $sharedPayload['status'] ?? null, 'G1 control: shared E14 response status');

        // ---- INTENDED PRODUCT RED: the portal Complete boundary is missing ----
        $rPortal = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitPortal), [], $headers);
        $this->assertReachedPortalComplete($rPortal, 'G1.A');
        self::assertTrue($this->portalRouteRegistered('/complete'), 'G1.A: portal Complete route registered under /doctor/portal/visits/{id}/complete');
        $this->gate(200, $rPortal, 'G1.B: own authorized in_consultation Visit with Chief Complaint completes at the portal boundary');
        $portalPayload = $this->payload($rPortal);
        self::assertSame('consultation_completed', $portalPayload['status'] ?? null, 'G1.B: portal response carries the established Visit status');
        self::assertSame($this->sortedKeys($sharedPayload), $this->sortedKeys($portalPayload),
            'G1.C: portal response reuses the established Visit presenter (same key set as the shared E14 response, no parallel backend)');
        self::assertSame('consultation_completed', $this->visitStatus($visitPortal), 'G1.D: persisted Visit status is consultation_completed');
        self::assertNotNull($this->visitRow($visitPortal)['consultation_completed_at'], 'G1.D: consultation_completed_at persisted by the shared transition');
    }

    // ============ Group 2 — SERVER AUTHORITY / IDOR (intended RED) ============

    public function testGroup2_PortalCompleteAuthorityAndIsolation(): void
    {
        $fx = $this->makePortalStage('g2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientOwn = $this->insertPatient($fx['clinic'], 'g2_own');
        $visitOwn = $this->insertVisit($patientOwn, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitOwn, $patientOwn, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);

        // Cross-doctor Visit in the SAME Clinic/Location — distinct WP user (u_clinician_user).
        $doctorB = $this->makeUser('g2_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G2 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $patientOther = $this->insertPatient($fx['clinic'], 'g2_other');
        $visitOther = $this->insertVisit($patientOther, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitOther, $patientOther, $clinicianB, $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $doctorB);
        // Cross-doctor Visit WITHOUT Chief Complaint: the portal guard must win over the 422 check.
        $patientOtherNoCc = $this->insertPatient($fx['clinic'], 'g2_other_nocc');
        $visitOtherNoCc = $this->insertVisit($patientOtherNoCc, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        // Foreign Clinic Visit — distinct WP user + persisted bindings.
        $foreignUser = $this->makeUser('g2_foreign_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $foreignOrg = $this->insertOrg('G2 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G2 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G2 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianForeign = $this->insertClinician('Dr G2 Foreign', $clinicForeign, 1, $foreignUser);
        cpms_test_seed_membership($foreignUser, $clinicForeign, RolesAndCapabilities::ROLE_DOCTOR);
        $patientForeign = $this->insertPatient($clinicForeign, 'g2_foreign');
        $visitForeign = $this->insertVisit($patientForeign, $clinicianForeign, $clinicForeign, $locForeign, 'in_consultation');
        $this->insertNote($visitForeign, $patientForeign, $clinicianForeign, $clinicForeign, 'chief_complaint', 'patient_visible', 0, $foreignUser);

        // Same Clinic, other Location — own doctor, but the Visit is bound to another Location.
        $locOther = $this->insertLocation($fx['clinic'], 'G2 Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g2_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');
        $this->insertNote($visitOtherLoc, $patientOtherLoc, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);

        // Staff without Complete authority / without clinician identity.
        $secretary = $this->makeUser('g2_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        $noIdentity = $this->makeUser('g2_no_identity', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($noIdentity, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $inactiveIdentity = $this->makeUser('g2_inactive_identity', RolesAndCapabilities::ROLE_DOCTOR);
        $inactiveClinician = $this->insertClinician('Dr G2 Inactive', $fx['clinic'], 0, $inactiveIdentity);
        cpms_test_seed_membership($inactiveIdentity, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);

        // ---- FIXTURE INTEGRITY ----
        $users = [$fx['doctor'], $doctorB, $foreignUser, $secretary, $noIdentity, $inactiveIdentity];
        self::assertSame(count($users), count(array_unique($users)), 'G2.fixture: WP users must be distinct');
        $this->assertClinicianBinding($clinicianB, $fx['clinic'], $doctorB);
        $this->assertClinicianBinding($clinicianForeign, $clinicForeign, $foreignUser);
        $inactiveRow = App::db()->fetchRow('SELECT is_active, wp_user_id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d', [$inactiveClinician]);
        self::assertIsArray($inactiveRow, 'G2.fixture: inactive clinician row persisted');
        self::assertSame(0, (int) $inactiveRow['is_active'], 'G2.fixture: clinician really inactive');
        self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d', [$noIdentity]),
            'G2.fixture: no-identity doctor really has no clinician row');
        $this->assertVisitBinding($visitOwn, $fx['clinic'], $fx['location'], $fx['clinician'], $patientOwn);
        $this->assertVisitBinding($visitOther, $fx['clinic'], $fx['location'], $clinicianB, $patientOther);
        $this->assertVisitBinding($visitOtherNoCc, $fx['clinic'], $fx['location'], $clinicianB, $patientOtherNoCc);
        $this->assertVisitBinding($visitOtherLoc, $fx['clinic'], $locOther, $fx['clinician'], $patientOtherLoc);
        $this->assertVisitBinding($visitForeign, $clinicForeign, $locForeign, $clinicianForeign, $patientForeign);
        foreach ([$visitOwn, $visitOther, $visitOtherLoc, $visitForeign] as $ccVisit) {
            self::assertSame(1, $this->chiefComplaintCount($ccVisit), 'G2.fixture: Chief Complaint persisted on Visit ' . $ccVisit);
        }
        self::assertSame(0, $this->chiefComplaintCount($visitOtherNoCc), 'G2.fixture: no Chief Complaint on the cross-doctor no-CC Visit');

        $guarded = [$visitOwn, $visitOther, $visitOtherNoCc, $visitOtherLoc, $visitForeign];
        $before = [];
        foreach ($guarded as $v) {
            $before[$v] = $this->snapshot($v);
        }

        // A. Cross-doctor Visit selector => non-enumerating 404.
        wp_set_current_user($fx['doctor']);
        $rCross = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOther), [], $headers);
        $this->assertReachedPortalComplete($rCross, 'G2.A');
        $this->gate(404, $rCross, 'G2.A: cross-doctor portal Complete denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCross), 'G2.A: non-enumerating 404 contract');

        // A2. Cross-doctor Visit without Chief Complaint => still 404 (guard before 422; no validation leak).
        $rCrossNoCc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOtherNoCc), [], $headers);
        $this->gate(404, $rCrossNoCc, 'G2.A2: portal guard runs before Chief Complaint validation (no 422 for a foreign Visit)');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCrossNoCc), 'G2.A2: non-enumerating 404 contract');

        // B. Foreign-Clinic Visit selector with own trusted headers => non-enumerating 404.
        $rForeignVisit = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitForeign), [], $headers);
        $this->gate(404, $rForeignVisit, 'G2.B: foreign-Clinic Visit selector denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignVisit), 'G2.B: non-enumerating 404 contract');

        // B2. Foreign Clinic selector header (no membership) => trusted-Clinic boundary.
        $rForeignHeader = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitForeign), [], $this->scopeHeaders($clinicForeign, $locForeign));
        $this->gate(403, $rForeignHeader, 'G2.B2: foreign Clinic selector header fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rForeignHeader), 'G2.B2: established scope denial code');

        // C. Foreign-Location Visit selector (own Clinic, trusted Location L1) => non-enumerating 404.
        $rForeignLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOtherLoc), [], $headers);
        $this->gate(404, $rForeignLoc, 'G2.C: foreign-Location Visit selector denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignLoc), 'G2.C: non-enumerating 404 contract');

        // D. Client clinician_id / patient_id are NOT authority.
        $rClientAuthority = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOther), ['clinician_id' => $clinicianB, 'patient_id' => $patientOther], $headers);
        $this->gate(404, $rClientAuthority, 'G2.D: client clinician_id must not create authority for a cross-doctor Visit');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rClientAuthority), 'G2.D: non-enumerating 404 contract');

        // E. Secretary (non-doctor staff, no cpms_consult_complete) => permission boundary.
        wp_set_current_user($secretary);
        $rSecretary = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), [], $headers);
        $this->gate(403, $rSecretary, 'G2.E: secretary denied at the portal Complete boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSecretary), 'G2.E: permission boundary code');

        // F. Doctor without / with inactive clinician identity => identity boundary.
        wp_set_current_user($noIdentity);
        $rNoIdentity = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), ['clinician_id' => $fx['clinician']], $headers);
        $this->gate(403, $rNoIdentity, 'G2.F: doctor without clinician identity denied even with a client clinician_id');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rNoIdentity), 'G2.F: identity boundary code');
        wp_set_current_user($inactiveIdentity);
        $rInactive = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), [], $headers);
        $this->gate(403, $rInactive, 'G2.F2: doctor with an inactive clinician identity denied');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rInactive), 'G2.F2: identity boundary code');

        // G. Missing / invalid nonce => established nonce boundary before any write.
        wp_set_current_user($fx['doctor']);
        $rNoNonce = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), [], $headers, 'none');
        $this->gate(403, $rNoNonce, 'G2.G: missing REST nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($rNoNonce), 'G2.G: established nonce code');
        $rBadNonce = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), [], $headers, 'invalid');
        $this->gate(403, $rBadNonce, 'G2.G2: invalid REST nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($rBadNonce), 'G2.G2: established nonce code');

        // H. Unauthenticated => rejected.
        wp_set_current_user(0);
        $rAnon = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), [], $headers);
        $this->gateIn([401, 403], $rAnon, 'G2.H: unauthenticated portal Complete rejected');

        // Denied requests changed NOTHING (status, history, Complete audit).
        foreach ($guarded as $v) {
            self::assertSame($before[$v], $this->snapshot($v), 'G2: denied requests left Visit ' . $v . ' status/history/audit unchanged');
        }

        // I. Own Visit succeeds; a foreign client clinician_id is ignored (server-derived identity).
        wp_set_current_user($fx['doctor']);
        $rOwn = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOwn), ['clinician_id' => $clinicianForeign], $headers);
        $this->gate(200, $rOwn, 'G2.I: own authorized Visit completes even when a foreign client clinician_id is supplied');
        self::assertSame('consultation_completed', $this->visitStatus($visitOwn), 'G2.I: own Visit persisted consultation_completed');
        self::assertSame($fx['clinician'], (int) $this->visitRow($visitOwn)['clinician_id'], 'G2.I: Visit ownership unchanged by client input');
        $history = $this->historyRows($visitOwn);
        self::assertCount(1, $history, 'G2.I: exactly one status-history row');
        self::assertSame($fx['doctor'], (int) $history[0]['actor_wp_user_id'], 'G2.I: history actor is the authenticated doctor');
        foreach ([$visitOther, $visitOtherNoCc, $visitOtherLoc, $visitForeign] as $v) {
            self::assertSame($before[$v], $this->snapshot($v), 'G2.I: other Visits remain unchanged after the own Complete (' . $v . ')');
        }
    }

    // ============ Group 3 — LOCATION POLICY 0/1/N (intended RED) ============

    public function testGroup3_PortalCompleteLocationPolicy(): void
    {
        global $wpdb;

        // A. N>1 eligible Locations without an explicit trusted Location => REQUIRED (never guess the first/primary).
        $fxMulti = $this->makePortalStage('g3m');
        $locSecond = $this->insertLocation($fxMulti['clinic'], 'G3 Second Loc', self::TZ_TEHRAN, 0);
        $patientMulti = $this->insertPatient($fxMulti['clinic'], 'g3m_patient');
        // The Visit sits at the PRIMARY Location, so a primary/first fallback would wrongly succeed.
        $visitMulti = $this->insertVisit($patientMulti, $fxMulti['clinician'], $fxMulti['clinic'], $fxMulti['location'], 'in_consultation');
        $this->insertNote($visitMulti, $patientMulti, $fxMulti['clinician'], $fxMulti['clinic'], 'chief_complaint', 'patient_visible', 0, $fxMulti['doctor']);
        self::assertSame(1, (int) $this->locationRow($fxMulti['location'])['is_primary'], 'G3.fixture: Visit Location is the primary Location');
        self::assertSame(2, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1', [$fxMulti['clinic']]),
            'G3.fixture: exactly two active Locations persisted');
        self::assertGreaterThan(0, $locSecond, 'G3.fixture: second Location persisted');
        $beforeMulti = $this->snapshot($visitMulti);

        wp_set_current_user($fxMulti['doctor']);
        $rNoLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitMulti), [], $this->scopeHeaders($fxMulti['clinic'], null));
        $this->assertReachedPortalComplete($rNoLoc, 'G3.A');
        $this->gate(400, $rNoLoc, 'G3.A: N>1 eligible Locations requires an explicit trusted Location');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rNoLoc), 'G3.A: location-required code');
        $rawNoLoc = $this->rawErrorData($rNoLoc);
        self::assertSame('location_id', $rawNoLoc['field'] ?? null, 'G3.A: field=location_id');
        self::assertSame('location_required', $rawNoLoc['reason'] ?? null, 'G3.A: reason=location_required');
        foreach (array_keys($rawNoLoc) as $key) {
            self::assertStringNotContainsString('eligible', (string) $key, 'G3.A: eligible Location IDs must not leak');
            self::assertNotContains((string) $key, ['location_ids', 'locations'], 'G3.A: Location list must not leak');
        }
        self::assertSame($beforeMulti, $this->snapshot($visitMulti), 'G3.A: no transition without a trusted Location');

        // B. N>1 with the explicit trusted Location => succeeds.
        $rWithLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitMulti), [], $this->scopeHeaders($fxMulti['clinic'], $fxMulti['location']));
        $this->gate(200, $rWithLoc, 'G3.B: explicit trusted Location Complete succeeds');
        self::assertSame('consultation_completed', $this->visitStatus($visitMulti), 'G3.B: persisted with the trusted Location');

        // C. Exactly 1 eligible Location => auto-resolution (no Location header).
        $fxSingle = $this->makePortalStage('g3s');
        $patientSingle = $this->insertPatient($fxSingle['clinic'], 'g3s_patient');
        $visitSingle = $this->insertVisit($patientSingle, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        $this->insertNote($visitSingle, $patientSingle, $fxSingle['clinician'], $fxSingle['clinic'], 'chief_complaint', 'patient_visible', 0, $fxSingle['doctor']);
        $patientSingle2 = $this->insertPatient($fxSingle['clinic'], 'g3s_patient2');
        $visitSingle2 = $this->insertVisit($patientSingle2, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        $this->insertNote($visitSingle2, $patientSingle2, $fxSingle['clinician'], $fxSingle['clinic'], 'chief_complaint', 'patient_visible', 0, $fxSingle['doctor']);
        wp_set_current_user($fxSingle['doctor']);
        $rSingle = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitSingle), [], $this->scopeHeaders($fxSingle['clinic'], null));
        $this->gate(200, $rSingle, 'G3.C: single eligible Location auto-resolution succeeds');

        // D. Explicit Location of a FOREIGN Clinic => fail closed.
        $foreignOrg = $this->insertOrg('G3 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G3 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G3 Foreign Loc', self::TZ_TEHRAN, 1);
        $beforeSingle2 = $this->snapshot($visitSingle2);
        $rForeignLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitSingle2), [], $this->scopeHeaders($fxSingle['clinic'], $locForeign));
        $this->gate(403, $rForeignLoc, 'G3.D: foreign explicit Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rForeignLoc), 'G3.D: established scope denial code');

        // E. Inactive explicit Location (own Clinic) => fail closed.
        $locInactive = $this->insertLocation($fxSingle['clinic'], 'G3 Inactive Loc', self::TZ_TEHRAN, 0);
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE id = %d', $locInactive));
        self::assertSame(0, (int) $this->locationRow($locInactive)['is_active'], 'G3.fixture: inactive Location really persisted inactive');
        $rInactive = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitSingle2), [], $this->scopeHeaders($fxSingle['clinic'], $locInactive));
        $this->gate(403, $rInactive, 'G3.E: inactive explicit Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rInactive), 'G3.E: established scope denial code');
        self::assertSame($beforeSingle2, $this->snapshot($visitSingle2), 'G3.D/E: denied Locations left the Visit unchanged');

        // F. Location-scoped membership, explicit Location NOT assigned to the doctor => fail closed.
        $fxUnassigned = $this->makePortalStage('g3u');
        $locUnassigned = $this->insertLocation($fxUnassigned['clinic'], 'G3 Unassigned Loc', self::TZ_TEHRAN, 0);
        $service = App::membership_service();
        $membership = $service->membership_for($fxUnassigned['clinic'], $fxUnassigned['doctor']);
        self::assertIsArray($membership, 'G3.fixture: doctor membership really persisted');
        $service->set_scope_mode((int) $membership['id'], 'location', [(int) $fxUnassigned['location']]);
        self::assertSame([(int) $fxUnassigned['location']], $service->membership_location_ids((int) $membership['id']),
            'G3.fixture: membership assignment is exactly one Location');
        $patientUnassigned = $this->insertPatient($fxUnassigned['clinic'], 'g3u_patient');
        $visitUnassigned = $this->insertVisit($patientUnassigned, $fxUnassigned['clinician'], $fxUnassigned['clinic'], $locUnassigned, 'in_consultation');
        $this->insertNote($visitUnassigned, $patientUnassigned, $fxUnassigned['clinician'], $fxUnassigned['clinic'], 'chief_complaint', 'patient_visible', 0, $fxUnassigned['doctor']);
        $beforeUnassigned = $this->snapshot($visitUnassigned);
        wp_set_current_user($fxUnassigned['doctor']);
        $rUnassigned = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitUnassigned), [], $this->scopeHeaders($fxUnassigned['clinic'], $locUnassigned));
        $this->gate(403, $rUnassigned, 'G3.F: explicit unassigned Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rUnassigned), 'G3.F: established scope denial code');
        self::assertSame($beforeUnassigned, $this->snapshot($visitUnassigned), 'G3.F: unassigned Location left the Visit unchanged');

        // G. 0 eligible Locations => fail closed.
        $fxZero = $this->makePortalStage('g3z');
        $patientZero = $this->insertPatient($fxZero['clinic'], 'g3z_patient');
        $visitZero = $this->insertVisit($patientZero, $fxZero['clinician'], $fxZero['clinic'], $fxZero['location'], 'in_consultation');
        $this->insertNote($visitZero, $patientZero, $fxZero['clinician'], $fxZero['clinic'], 'chief_complaint', 'patient_visible', 0, $fxZero['doctor']);
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE clinic_id = %d', $fxZero['clinic']));
        self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1', [$fxZero['clinic']]),
            'G3.fixture: zero eligible Locations really persisted');
        $beforeZero = $this->snapshot($visitZero);
        wp_set_current_user($fxZero['doctor']);
        $rZero = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitZero), [], $this->scopeHeaders($fxZero['clinic'], $fxZero['location']));
        $this->gateIn([403, 404], $rZero, 'G3.G: zero eligible Locations fails closed');
        self::assertNotSame('rest_no_route', $this->errCode($rZero), 'G3.G: denial comes from the portal boundary, not a missing route');
        self::assertSame($beforeZero, $this->snapshot($visitZero), 'G3.G: zero eligible Locations left the Visit unchanged');
    }

    // ============ Group 4 — CHIEF COMPLAINT POLICY (intended RED; domain controls GREEN) ============

    public function testGroup4_PortalCompleteChiefComplaintPolicy(): void
    {
        $fx = $this->makePortalStage('g4');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        // GREEN controls: established enums unchanged (no new category / visibility state).
        $notesTable = App::db()->table('cpms_clinical_notes');
        self::assertSame(self::NOTE_CATEGORY_ENUM, strtolower((string) $this->columnType($notesTable, 'category')), 'G4 control: note category enum unchanged');
        self::assertSame(self::NOTE_VISIBILITY_ENUM, strtolower((string) $this->columnType($notesTable, 'visibility')), 'G4 control: note visibility enum unchanged');
        self::assertNull(App::db()->fetchValue('SELECT value_json FROM ' . App::db()->table('cpms_settings') . ' WHERE clinic_id = %d AND `key` = %s', [$fx['clinic'], 'clinical.require_chief_complaint']),
            'G4.fixture: no per-Clinic override, so the established default (required) applies');

        // A. Required (default) + no Chief Complaint (other notes present) => established 422, no transition.
        $patientA = $this->insertPatient($fx['clinic'], 'g4_a');
        $visitA = $this->insertVisit($patientA, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitA, $patientA, $fx['clinician'], $fx['clinic'], 'clinical_note', 'patient_visible', 0, $fx['doctor']);
        $this->insertNote($visitA, $patientA, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 1, $fx['doctor']);
        self::assertSame(0, $this->chiefComplaintCount($visitA), 'G4.fixture: only an ARCHIVED Chief Complaint exists on Visit A');
        $beforeA = $this->snapshot($visitA);

        wp_set_current_user($fx['doctor']);
        $rMissing = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitA), [], $headers);
        $this->assertReachedPortalComplete($rMissing, 'G4.A');
        $this->gate(422, $rMissing, 'G4.A: Chief Complaint required and missing => established validation error');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rMissing), 'G4.A: established validation code');
        self::assertSame('chief_complaint', $this->rawErrorData($rMissing)['missing'] ?? null, 'G4.A: established missing=chief_complaint detail');
        self::assertSame($beforeA, $this->snapshot($visitA), 'G4.A: rejected Complete left status/history/audit unchanged');

        // B. Authoring the established chief_complaint category inside the Visit Workspace enables Complete.
        $rNote = $this->dispatch('POST', '/' . sprintf(self::PORTAL_NOTES, $visitA), [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'سردرد و تب از دو روز پیش',
        ], $headers);
        $this->gate(200, $rNote, 'G4.B control: merged portal notes route accepts the established chief_complaint category');
        self::assertSame(1, $this->chiefComplaintCount($visitA), 'G4.B control: Chief Complaint really persisted through the portal');
        $rAfterNote = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitA), [], $headers);
        $this->gate(200, $rAfterNote, 'G4.B: Complete succeeds once the Chief Complaint was authored in the workspace');
        self::assertSame('consultation_completed', $this->visitStatus($visitA), 'G4.B: persisted consultation_completed');

        // C. A doctor-private Chief Complaint satisfies the policy (backend check is visibility-agnostic).
        $patientC = $this->insertPatient($fx['clinic'], 'g4_c');
        $visitC = $this->insertVisit($patientC, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitC, $patientC, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'doctor_private', 0, $fx['doctor']);
        self::assertSame(1, $this->chiefComplaintCount($visitC), 'G4.fixture: private Chief Complaint persisted');
        $rPrivate = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitC), [], $headers);
        $this->gate(200, $rPrivate, 'G4.C: private Chief Complaint satisfies the established policy');

        // D. Per-Clinic policy disabled => Complete follows the backend without a Chief Complaint.
        $fxOff = $this->makePortalStage('g4off');
        App::settingsFactory()->forClinic($fxOff['clinic'])->set('clinical.require_chief_complaint', false);
        Settings::flushCache();
        self::assertFalse((bool) App::settingsFactory()->forClinic($fxOff['clinic'])->get('clinical.require_chief_complaint', true),
            'G4.fixture: per-Clinic require_chief_complaint=false really persisted');
        self::assertTrue((bool) App::settingsFactory()->forClinic($fx['clinic'])->get('clinical.require_chief_complaint', true),
            'G4.fixture: the other Clinic keeps the default policy (per-Clinic, not global)');
        $patientOff = $this->insertPatient($fxOff['clinic'], 'g4_off');
        $visitOff = $this->insertVisit($patientOff, $fxOff['clinician'], $fxOff['clinic'], $fxOff['location'], 'in_consultation');
        self::assertSame(0, $this->chiefComplaintCount($visitOff), 'G4.fixture: no Chief Complaint on the policy-off Visit');
        wp_set_current_user($fxOff['doctor']);
        $rOff = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitOff), [], $this->scopeHeaders($fxOff['clinic'], $fxOff['location']));
        $this->gate(200, $rOff, 'G4.D: policy disabled for this Clinic => Complete succeeds without Chief Complaint');
        self::assertSame('consultation_completed', $this->visitStatus($visitOff), 'G4.D: persisted consultation_completed');
    }

    // ============ Group 5 — STATE MACHINE / HISTORY / AUDIT (intended RED) ============

    public function testGroup5_PortalCompleteStateMachineHistoryAndAudit(): void
    {
        $fx = $this->makePortalStage('g5');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        // GREEN control: live machine names (V10) verified, no new state.
        self::assertSame('consultation_completed', VisitMachine::create()->machine()->assert('in_consultation', 'complete', 'doctor'), 'G5 control: V10 in_consultation --complete--> consultation_completed');

        $patient = $this->insertPatient($fx['clinic'], 'g5_main');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visit, $patient, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        $patientWaiting = $this->insertPatient($fx['clinic'], 'g5_waiting');
        $visitWaiting = $this->insertVisit($patientWaiting, $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');
        $this->insertNote($visitWaiting, $patientWaiting, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        $patientCalled = $this->insertPatient($fx['clinic'], 'g5_called');
        $visitCalled = $this->insertVisit($patientCalled, $fx['clinician'], $fx['clinic'], $fx['location'], 'called');
        $this->insertNote($visitCalled, $patientCalled, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        $this->assertVisitBinding($visit, $fx['clinic'], $fx['location'], $fx['clinician'], $patient);
        self::assertSame([], $this->historyRows($visit), 'G5.fixture: no prior status history');
        self::assertSame('waiting', $this->visitStatus($visitWaiting), 'G5.fixture: waiting Visit persisted');
        self::assertSame('called', $this->visitStatus($visitCalled), 'G5.fixture: called Visit persisted');

        wp_set_current_user($fx['doctor']);
        $r = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visit), [], $headers);
        $this->assertReachedPortalComplete($r, 'G5.A');
        $this->gate(200, $r, 'G5.A: portal Complete succeeds for the owned in_consultation Visit');

        // Exact history: ONE row in_consultation → consultation_completed by the doctor.
        $history = $this->historyRows($visit);
        self::assertCount(1, $history, 'G5.B: exactly one status-history row');
        self::assertSame('in_consultation', $history[0]['from_status'], 'G5.B: history from_status');
        self::assertSame('consultation_completed', $history[0]['to_status'], 'G5.B: history to_status');
        self::assertSame($fx['doctor'], (int) $history[0]['actor_wp_user_id'], 'G5.B: history actor');
        self::assertSame('doctor', $history[0]['actor_role'], 'G5.B: history actor role');

        // Exact audit: one VISIT_COMPLETE (transition) + one CONSULTATION_COMPLETED (clinical validation).
        self::assertSame(1, $this->auditCount($visit, 'VISIT_COMPLETE'), 'G5.C: exactly one VISIT_COMPLETE audit');
        self::assertSame(1, $this->auditCount($visit, 'CONSULTATION_COMPLETED'), 'G5.C: exactly one CONSULTATION_COMPLETED audit');
        $audit = App::db()->fetchRow(
            'SELECT clinic_id, actor_wp_user_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE resource_type = %s AND resource_id = %d AND action = %s ORDER BY id DESC LIMIT 1',
            ['visit', $visit, 'VISIT_COMPLETE']
        );
        self::assertIsArray($audit, 'G5.C: VISIT_COMPLETE audit row readable');
        self::assertSame($fx['clinic'], (int) $audit['clinic_id'], 'G5.C: audit bound to the Visit Clinic');
        self::assertSame($fx['doctor'], (int) $audit['actor_wp_user_id'], 'G5.C: audit actor is the doctor');

        // Repeat => established 409, nothing duplicated.
        $rRepeat = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visit), [], $headers);
        $this->gate(409, $rRepeat, 'G5.D: repeated Complete is rejected by the shared state machine');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rRepeat), 'G5.D: established transition code');
        $rawRepeat = $this->rawErrorData($rRepeat);
        self::assertSame('consultation_completed', $rawRepeat['from'] ?? null, 'G5.D: established from detail');
        self::assertSame('complete', $rawRepeat['event'] ?? null, 'G5.D: established event detail');
        self::assertCount(1, $this->historyRows($visit), 'G5.D: repeat wrote no history');
        self::assertSame(1, $this->auditCount($visit, 'CONSULTATION_COMPLETED'), 'G5.D: repeat wrote no Complete audit');

        // Wrong state (waiting / called) => established 409, unchanged.
        foreach (['waiting' => $visitWaiting, 'called' => $visitCalled] as $from => $v) {
            $before = $this->snapshot($v);
            $rWrong = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $v), [], $headers);
            $this->gate(409, $rWrong, 'G5.E: Complete from ' . $from . ' rejected by the shared state machine');
            self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rWrong), 'G5.E: established transition code (' . $from . ')');
            self::assertSame($from, $this->rawErrorData($rWrong)['from'] ?? null, 'G5.E: established from detail (' . $from . ')');
            self::assertSame($before, $this->snapshot($v), 'G5.E: wrong-state Visit unchanged (' . $from . ')');
        }

        // Completed Visit leaves the Live Queue served to the Staff Portal doctor module.
        $patientQ = $this->insertPatient($fx['clinic'], 'g5_queue');
        $visitQ = $this->insertVisit($patientQ, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitQ, $patientQ, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        $rQueueBefore = $this->dispatch('GET', '/' . self::DOCTOR_TODAY, [], $headers);
        $this->gate(200, $rQueueBefore, 'G5.F control: doctor today/queue readable');
        self::assertContains($visitQ, $this->queueIds($rQueueBefore), 'G5.F control: in_consultation Visit is in the Live Queue before Complete');
        $rQ = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visitQ), [], $headers);
        $this->gate(200, $rQ, 'G5.F: portal Complete of the queued Visit succeeds');
        $rQueueAfter = $this->dispatch('GET', '/' . self::DOCTOR_TODAY, [], $headers);
        $this->gate(200, $rQueueAfter, 'G5.F: doctor today/queue still readable');
        self::assertNotContains($visitQ, $this->queueIds($rQueueAfter), 'G5.F: completed Visit leaves the Live Queue');
    }

    // ============ Group 6 — NO FINANCE SIDE EFFECTS (intended RED; machine control GREEN) ============

    public function testGroup6_PortalCompleteHasNoFinanceSideEffects(): void
    {
        $fx = $this->makePortalStage('g6');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        // GREEN control: the doctor cannot drive the finance transition; it is system/secretary only.
        $threw = false;
        try {
            VisitMachine::create()->machine()->assert('consultation_completed', 'invoice_ready', 'doctor');
        } catch (\Throwable $e) {
            $threw = true;
            unset($e);
        }
        self::assertTrue($threw, 'G6 control: invoice_ready is not a doctor transition');
        self::assertSame('awaiting_payment', VisitMachine::create()->machine()->assert('consultation_completed', 'invoice_ready', 'secretary'),
            'G6 control: finance transition stays with the established secretary/system path');

        $patient = $this->insertPatient($fx['clinic'], 'g6_main');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visit, $patient, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        self::assertSame(0, $this->invoiceCount($visit), 'G6.fixture: no invoice before Complete');
        $notificationsBefore = $this->notificationCountForClinic($fx['clinic']);

        wp_set_current_user($fx['doctor']);
        $r = $this->dispatch('POST', '/' . sprintf(self::PORTAL_COMPLETE, $visit), [], $headers);
        $this->assertReachedPortalComplete($r, 'G6.A');
        $this->gate(200, $r, 'G6.A: portal Complete succeeds');

        $row = $this->visitRow($visit);
        self::assertSame('consultation_completed', (string) $row['status'], 'G6.B: Visit stops at consultation_completed (no awaiting_payment/paid/checked_out)');
        self::assertSame(1, (int) $row['active'], 'G6.B: Visit stays active (no checkout)');
        self::assertNull($row['checked_out_at'], 'G6.B: no checkout timestamp');
        self::assertSame(['consultation_completed'], array_values(array_unique(array_map(static fn (array $h): string => (string) $h['to_status'], $this->historyRows($visit)))),
            'G6.B: no finance status reached in history');
        self::assertSame(0, $this->invoiceCount($visit), 'G6.C: no invoice created by Complete');
        self::assertSame(0, $this->paymentCountForVisit($visit), 'G6.C: no payment created by Complete');
        self::assertSame($notificationsBefore, $this->notificationCountForClinic($fx['clinic']), 'G6.D: no ready-for-payment or other notification created by Complete');
    }

    // ============ Group 7 — SHARED CONTRACT REGRESSION (GREEN control) ============

    public function testGroup7_SharedCompleteReopenAndSchemaUnchanged(): void
    {
        $fx = $this->makePortalStage('g7');
        $clinicHeaders = $this->scopeHeaders($fx['clinic'], null);

        self::assertTrue($this->routeRegistered('/' . self::REST_NS . '/visits/(?P<id>\d+)/complete'), 'G7.A: shared E14 Complete route unchanged');
        self::assertTrue($this->routeRegistered('/' . self::REST_NS . '/visits/(?P<id>\d+)/reopen'), 'G7.A: shared E15 Reopen route unchanged');
        self::assertFalse($this->portalRouteRegistered('/reopen'), 'G7.A: Reopen is NOT exposed at the portal boundary');
        self::assertTrue(method_exists(\ClinicCore\Application\Clinical\ClinicalService::class, 'reopenConsultation'), 'G7.A: shared Reopen service unchanged');

        $patientMissing = $this->insertPatient($fx['clinic'], 'g7_missing');
        $visitMissing = $this->insertVisit($patientMissing, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $patientOk = $this->insertPatient($fx['clinic'], 'g7_ok');
        $visitOk = $this->insertVisit($patientOk, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitOk, $patientOk, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);

        $doctorB = $this->makeUser('g7_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G7 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $patientB = $this->insertPatient($fx['clinic'], 'g7_b');
        $visitB = $this->insertVisit($patientB, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitB, $patientB, $clinicianB, $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $doctorB);
        $secretary = $this->makeUser('g7_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        $this->assertClinicianBinding($clinicianB, $fx['clinic'], $doctorB);
        $this->assertVisitBinding($visitB, $fx['clinic'], $fx['location'], $clinicianB, $patientB);

        // B. Established validation: missing Chief Complaint => 422, then success.
        wp_set_current_user($fx['doctor']);
        $rMissing = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitMissing), [], $clinicHeaders);
        $this->gate(422, $rMissing, 'G7.B: shared E14 still enforces the Chief Complaint policy');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rMissing), 'G7.B: established validation code');
        self::assertSame('chief_complaint', $this->rawErrorData($rMissing)['missing'] ?? null, 'G7.B: established missing detail');
        $rOk = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitOk), [], $clinicHeaders);
        $this->gate(200, $rOk, 'G7.B: shared E14 success unchanged');
        self::assertSame('consultation_completed', $this->payload($rOk)['status'] ?? null, 'G7.B: shared E14 status unchanged');

        // C. Established repeat => 409.
        $rRepeat = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitOk), [], $clinicHeaders);
        $this->gate(409, $rRepeat, 'G7.C: shared E14 repeat rejected');
        self::assertSame('CLINIC_INVALID_TRANSITION', $this->errCode($rRepeat), 'G7.C: established transition code');

        // D. Established role/ownership denials unchanged (no global tightening or loosening).
        $beforeB = $this->snapshot($visitB);
        $rCross = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitB), [], $clinicHeaders);
        $this->gate(403, $rCross, 'G7.D: shared E14 cross-doctor Complete still denied');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rCross), 'G7.D: established ownership denial code');
        wp_set_current_user($secretary);
        $rSecretary = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitB), [], $clinicHeaders);
        $this->gate(403, $rSecretary, 'G7.D: secretary still cannot Complete');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSecretary), 'G7.D: established permission code');
        self::assertSame($beforeB, $this->snapshot($visitB), 'G7.D: denied shared requests left the Visit unchanged');

        // E. Shared E14 is not tightened by the portal Location policy (N>1, Clinic header only).
        $this->insertLocation($fx['clinic'], 'G7 Second Loc', self::TZ_TEHRAN, 0);
        $patientN = $this->insertPatient($fx['clinic'], 'g7_n');
        $visitN = $this->insertVisit($patientN, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $this->insertNote($visitN, $patientN, $fx['clinician'], $fx['clinic'], 'chief_complaint', 'patient_visible', 0, $fx['doctor']);
        wp_set_current_user($fx['doctor']);
        $rN = $this->dispatch('POST', '/' . sprintf(self::SHARED_COMPLETE, $visitN), [], $clinicHeaders);
        $this->gate(200, $rN, 'G7.E: shared E14 not globally tightened by the portal Location policy');

        // F. No migration/schema change.
        $latestMigration = (string) App::db()->fetchValue(
            'SELECT version FROM ' . App::db()->table('cpms_schema_migrations') . ' ORDER BY version DESC LIMIT 1'
        );
        self::assertSame(self::LATEST_MIGRATION, $latestMigration, 'G7.F: no migration/schema change in this slice');
    }

    // ============ Group 8 — STAFF PORTAL HYBRID UI / BROWSER CONTRACT (intended RED) ============

    public function testGroup8_StaffPortalHybridCompleteUiContract(): void
    {
        $fx = $this->makePortalStage('g8');
        $secretary = $this->makeUser('g8_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);

        // ---- GREEN controls: shared Staff Portal architecture is authoritative ----
        self::assertTrue(StaffPortalShell::doctor_module_eligible($fx['doctor']), 'G8 control: doctor module eligible for the doctor');
        self::assertFalse(StaffPortalShell::doctor_module_eligible($secretary), 'G8 control: doctor module (and so Complete) not eligible for the secretary');
        $staffUrl = StaffPortalShell::portal_url();
        self::assertStringNotContainsString('/wp-admin/', $staffUrl, 'G8 control: canonical Staff Portal is a frontend URL');

        // Legacy doctor URL => one redirect to the canonical Staff Portal for the eligible doctor only.
        wp_set_current_user($fx['doctor']);
        $this->go_to($this->requestFor(DoctorPortalShell::portal_url()));
        self::assertSame(untrailingslashit($staffUrl), untrailingslashit(DoctorPortalShell::legacy_redirect_target($fx['doctor'])),
            'G8 control: legacy doctor entry redirects to the canonical Staff Portal');
        wp_set_current_user($secretary);
        self::assertSame('', DoctorPortalShell::legacy_redirect_target($secretary), 'G8 control: no legacy redirect for a non-eligible user');

        $html = $this->renderCanonicalAs($fx['doctor'], $staffUrl);
        $this->assertStaffShellRoot($html, 'G8 control');
        self::assertStringContainsString('data-role="workspace-section"', $html, 'G8 control: Visit Workspace mounted in the canonical Staff Portal');

        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/templates/doctor-portal-shell.php';
        $jsPath = $root . '/assets/js/cpms-doctor-portal.js';
        self::assertFileExists($templatePath, 'G8: doctor module template exists');
        self::assertFileExists($jsPath, 'G8: doctor module JS exists');
        $ui = (string) file_get_contents($templatePath) . "\n" . (string) file_get_contents($jsPath);

        // ---- GREEN guards: merged workspace kept, no Reopen, no routine reload ----
        foreach (['workspace-note-form', 'workspace-note-submit', 'workspace-rx-section', 'workspace-rec-section', 'workspace-fu-section'] as $keep) {
            self::assertStringContainsString($keep, $ui, 'G8 guard: merged Visit Workspace wiring preserved (' . $keep . ')');
        }
        foreach ([
            '/reopen',
            'workspace-reopen',
            'visit-reopen',
            'consult-reopen',
            'workspace-complete',
            'visit-complete',
            'data-action="complete"',
            'location.reload(',
            'location.assign(',
            'location.replace(',
            'location.href =',
            'location.href=',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $ui, 'G8 guard: forbidden in the module UI (' . $forbidden . ')');
        }

        // ---- INTENDED PRODUCT RED: hybrid Complete + Chief Complaint wiring missing ----
        $missing = [];
        foreach ([
            'workspace-consult-complete-section',
            'workspace-consult-complete-submit',
            'workspace-consult-complete-busy',
            'workspace-consult-complete-error',
            'workspace-consult-complete-success',
        ] as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'Complete marker (' . $marker . ')';
            }
            if (!str_contains($html, 'data-role="' . $marker . '"')) {
                $missing[] = 'Complete marker rendered in the canonical Staff Portal (' . $marker . ')';
            }
        }
        if (!str_contains($ui, 'chief_complaint')) {
            $missing[] = 'Chief Complaint authoring inside the Visit Workspace (established chief_complaint category)';
        }
        $pos = strpos($ui, '/complete');
        if ($pos === false || !str_contains($ui, '/doctor/portal/visits/')) {
            $missing[] = 'REST POST wiring to /doctor/portal/visits/{id}/complete';
        } else {
            $window = substr($ui, max(0, $pos - 1500), 4500);
            if (!str_contains($window, 'confirm(') && !str_contains($ui, 'workspace-consult-complete-confirm')) {
                $missing[] = 'explicit confirmation before Complete';
            }
            if (!str_contains($window, 'submittedVisitId')) {
                $missing[] = 'stale Visit-context guard for Complete (submitted-vs-live Visit id)';
            }
            if (!str_contains($window, 'disabled')) {
                $missing[] = 'double-submit guard (control disabled while busy)';
            }
            if (!str_contains($window, '422') && !str_contains($window, 'CLINIC_VALIDATION_FAILED')) {
                $missing[] = 'clear 422 Chief Complaint handling';
            }
            if (!str_contains($window, '409') && !str_contains($window, 'CLINIC_INVALID_TRANSITION')) {
                $missing[] = 'clear 409 wrong-state/repeat handling';
            }
            if (!str_contains($window, 'loadTodayAndQueue')) {
                $missing[] = 'Today/Live Queue refresh after Complete (no page reload)';
            }
            if (str_contains($window, 'clinician_id')) {
                $missing[] = 'Complete request must not send client clinician_id';
            }
        }
        if ($missing !== []) {
            $this->annotate('red-G8', 'G8: Staff Portal hybrid Visit Complete UI wiring missing', implode('; ', $missing));
        }
        self::assertSame([], $missing, 'G8: Staff Portal hybrid Visit Complete UI wiring missing: ' . implode('; ', $missing));
    }

    /**
     * Group 8 (browser half) — the future GREEN browser journey must extend the
     * EXISTING bin/pilot-doctor-portal.py driven by the pilot gate (canonical
     * Staff Portal, 390/768/1366, zero product reload), with a real 422
     * prerequisite, busy/double-submit, success, 409 repeat, stale guard and
     * queue-leave proof.
     */
    public function testGroup8b_CompleteBrowserJourneyStaysInExistingPilot(): void
    {
        $root = dirname(__DIR__, 2);
        $pilotPath = $root . '/bin/pilot-doctor-portal.py';
        $pilotGatePath = $root . '/../.github/workflows/pilot-gate.yml';
        self::assertFileExists($pilotPath, 'G8b: existing Doctor Portal pilot harness exists');
        $pilot = (string) file_get_contents($pilotPath);

        foreach (['assert_no_product_reload', 'prove_legacy', 'prove_workspace_recfu', 'mobile-390', 'tablet-768', 'desktop-1366'] as $keep) {
            self::assertStringContainsString($keep, $pilot, 'G8b guard: established pilot proof retained (' . $keep . ')');
        }
        if (is_file($pilotGatePath)) {
            self::assertStringContainsString('pilot-doctor-portal.py', (string) file_get_contents($pilotGatePath),
                'G8b guard: the pilot gate keeps driving the existing pilot');
        }
        self::assertStringNotContainsString('/reopen', $pilot, 'G8b guard: no Reopen journey in the Staff Portal pilot');

        $pilotMissing = [];
        foreach ([
            'prove_workspace_complete',
            '/complete',
            'chief_complaint',
            'workspace-consult-complete-submit',
            'workspace-consult-complete-busy',
            'workspace-consult-complete-error',
            'workspace-consult-complete-success',
            'complete_prereq_422',
            'complete_double_submit',
            'complete_repeat_409',
            'complete_stale_guard',
            'complete_queue_left',
        ] as $token) {
            if (!str_contains($pilot, $token)) {
                $pilotMissing[] = 'pilot journey token (' . $token . ')';
            }
        }
        if ($pilotMissing !== []) {
            $this->annotate('red-G8b', 'G8b: Visit Complete browser journey missing from the existing pilot', implode('; ', $pilotMissing));
        }
        self::assertSame([], $pilotMissing,
            'G8b: the future GREEN browser journey for Visit Complete must live in the existing bin/pilot-doctor-portal.py: '
            . implode('; ', $pilotMissing));
    }

    // ================= helpers =================

    /**
     * @return array{clinic: int, location: int, clinician: int, doctor: int}
     */
    private function makePortalStage(string $tag): array
    {
        $org = $this->insertOrg('Complete Stage ' . $tag);
        $clinic = $this->insertClinicInOrg('Complete Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $loc = $this->insertLocation($clinic, 'Complete Loc ' . $tag, self::TZ_TEHRAN, 1);
        $doctor = $this->makeUser('vc_' . $tag . '_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr VC ' . $tag, $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        return ['clinic' => $clinic, 'location' => $loc, 'clinician' => $clinician, 'doctor' => $doctor];
    }

    /**
     * @return array<string, string>
     */
    private function scopeHeaders(int $clinic, ?int $location = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinic];
        if ($location !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }
        return $headers;
    }

    /**
     * The intended boundary must be REACHED: a missing portal route answers
     * 404 rest_no_route, which is the intended product RED, never a fixture error.
     */
    private function assertReachedPortalComplete(WP_REST_Response $r, string $label): void
    {
        if ($this->errCode($r) === 'rest_no_route') {
            $this->annotate('red-route', $label . ': portal Complete boundary missing', 'POST /clinic/v1/doctor/portal/visits/{id}/complete => ' . $r->get_status() . ' rest_no_route');
        }
        self::assertNotSame('rest_no_route', $this->errCode($r),
            $label . ': portal Complete boundary missing — POST /clinic/v1/doctor/portal/visits/{id}/complete returned '
            . $r->get_status() . ' rest_no_route');
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_map('strval', array_keys($payload));
        sort($keys);
        return $keys;
    }

    private function gate(int $wanted, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if ($got !== $wanted) {
            $this->annotate('status', $message, 'expected=' . $wanted . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertSame($wanted, $got, $message . ' (got ' . $got . ' ' . $this->errCode($r) . ')');
    }

    /**
     * @param list<int> $wantedSet
     */
    private function gateIn(array $wantedSet, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if (!in_array($got, $wantedSet, true)) {
            $this->annotate('status-set', $message, 'expected=' . implode('|', $wantedSet) . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertContains($got, $wantedSet, $message . ' (got ' . $got . ' ' . $this->errCode($r) . ')');
    }

    private function annotate(string $kind, string $message, string $detail): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $message . ' :: ' . $detail));
        $esc = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', ' -', ';'], $text);
        $tokens = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '', strstr($message, ':', true) ?: substr($message, 0, 12)));
        fwrite(STDERR, '::error title=CPMS-Phase10VisitComplete-' . $kind . '-' . $tokens . '::' . $esc . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     * @param string $nonce 'valid' | 'none' | 'invalid'
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], string $nonce = 'valid'): WP_REST_Response
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($nonce === 'valid') {
            $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        } elseif ($nonce === 'invalid') {
            $r->set_header('X-WP-Nonce', 'invalid-nonce-0000');
        }
        foreach ($headers as $k => $v) {
            $r->set_header($k, $v);
        }
        return rest_do_request($r);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return is_array($b) ? $b : [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }
        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawErrorData(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            $d = $b->get_error_data();
            return is_array($d) ? $d : [];
        }
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return is_array($b) ? $b : [];
    }

    /**
     * @return list<int>
     */
    private function queueIds(WP_REST_Response $res): array
    {
        $queue = $this->payload($res)['queue'] ?? [];
        $ids = [];
        foreach ((array) $queue as $row) {
            if (is_array($row)) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
        }
        return $ids;
    }

    private function routeRegistered(string $route): bool
    {
        return array_key_exists($route, rest_get_server()->get_routes());
    }

    private function portalRouteRegistered(string $suffix): bool
    {
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            if (str_contains((string) $route, '/doctor/portal/visits/') && str_ends_with((string) $route, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Status + history + Complete audit fingerprint of one Visit.
     *
     * @return array{status: string, history: int, visit_complete: int, consultation_completed: int}
     */
    private function snapshot(int $visitId): array
    {
        return [
            'status' => $this->visitStatus($visitId),
            'history' => count($this->historyRows($visitId)),
            'visit_complete' => $this->auditCount($visitId, 'VISIT_COMPLETE'),
            'consultation_completed' => $this->auditCount($visitId, 'CONSULTATION_COMPLETED'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visitRow(int $visitId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [$visitId]);
        self::assertIsArray($row, 'visit row readable');
        return $row;
    }

    private function visitStatus(int $visitId): string
    {
        return (string) $this->visitRow($visitId)['status'];
    }

    /**
     * @return array<string, mixed>
     */
    private function locationRow(int $locationId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d', [$locationId]);
        self::assertIsArray($row, 'location row readable');
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyRows(int $visitId): array
    {
        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table('cpms_visit_status_history') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        ) ?: [];
    }

    private function auditCount(int $visitId, string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE resource_type = %s AND resource_id = %d AND action = %s',
            ['visit', $visitId, $action]
        );
    }

    private function chiefComplaintCount(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinical_notes') . ' WHERE visit_id = %d AND category = %s AND is_archived = 0',
            [$visitId, 'chief_complaint']
        );
    }

    private function invoiceCount(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_invoices') . ' WHERE visit_id = %d',
            [$visitId]
        );
    }

    private function paymentCountForVisit(int $visitId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_payments') . ' p INNER JOIN ' . App::db()->table('cpms_invoices') . ' i ON i.id = p.invoice_id WHERE i.visit_id = %d',
            [$visitId]
        );
    }

    private function notificationCountForClinic(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function columnType(string $table, string $column): string
    {
        $row = App::db()->fetchRow(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        );
        self::assertIsArray($row, 'schema column readable: ' . $table . '.' . $column);
        return (string) ($row['COLUMN_TYPE'] ?? $row['column_type'] ?? '');
    }

    private function assertClinicianBinding(int $clinicianId, int $clinicId, int $wpUserId): void
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, wp_user_id, is_active FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        );
        self::assertIsArray($row, 'fixture: clinician row really persisted');
        self::assertSame($clinicId, (int) $row['clinic_id'], 'fixture: clinician bound to the claimed Clinic');
        self::assertSame($wpUserId, (int) $row['wp_user_id'], 'fixture: clinician bound to the claimed WP user');
        self::assertSame(1, (int) $row['is_active'], 'fixture: clinician is active');
    }

    private function assertVisitBinding(int $visitId, int $clinicId, int $locationId, int $clinicianId, int $patientId): void
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, location_id, clinician_id, patient_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        self::assertIsArray($row, 'fixture: visit row really persisted');
        self::assertSame($clinicId, (int) $row['clinic_id'], 'fixture: visit bound to the claimed Clinic');
        self::assertSame($locationId, (int) $row['location_id'], 'fixture: visit bound to the claimed Location');
        self::assertSame($clinicianId, (int) $row['clinician_id'], 'fixture: visit bound to the claimed clinician');
        self::assertSame($patientId, (int) $row['patient_id'], 'fixture: visit bound to the claimed patient');
    }

    private function makeUser(string $login, string $role): int
    {
        $u = $login . '_' . bin2hex(random_bytes(3));
        $created = wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertFalse(is_wp_error($created), 'fixture: WP user creation must not fail (' . $login . ')');
        $id = (int) $created;
        self::assertGreaterThan(0, $id, 'fixture: WP user persisted');
        $user = get_userdata($id);
        self::assertNotFalse($user, 'fixture: WP user readable');
        $user->set_role($role);
        return $id;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', $name, 'org-' . bin2hex(random_bytes(3)), 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization fixture row must persist');
        return $id;
    }

    private function insertClinicInOrg(string $name, int $orgId, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), $tz, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinic fixture row must persist');
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $tz, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), $tz, $primary, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location fixture row must persist');
        return $id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinician fixture row must persist (wp_user_id must be distinct)');
        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-VC-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture row must persist');
        return $id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, self::FIXED_UTC_DATE, self::FIXED_UTC_DATE . ' 10:00:00', self::FIXED_UTC_DATE . ' 10:00:00', 1, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'visit fixture row must persist (clinician/location/patient FKs must be valid)');
        return $id;
    }

    private function insertNote(int $visitId, int $patientId, int $clinicianId, int $clinicId, string $category, string $visibility, int $archived, int $authorWpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinical_notes (clinic_id, visit_id, patient_id, clinician_id, category, visibility, content_text, version, is_archived, created_by_wp_user_id, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, 1, %d, %d, %s, %s)', $clinicId, $visitId, $patientId, $clinicianId, $category, $visibility, 'یادداشت آزمون ' . $category, $archived, $authorWpUserId, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinical note fixture row must persist (visit/patient/clinician FKs must be valid)');
        return $id;
    }

    private function requestFor(string $url): string
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $req = $path . ('' !== $query ? '?' . $query : '');
        return '' === $req ? '/' : $req;
    }

    private function renderCanonicalAs(int $userId, string $url): string
    {
        wp_set_current_user($userId);
        $this->go_to($this->requestFor($url));
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        if (!is_readable($baseline)) {
            $baseline = '/active-theme/page.php';
        }
        $template = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $template, 'G8 control: template_include intercepts the canonical Staff Portal page');
        self::assertFileExists($template, 'G8 control: plugin-owned Staff Portal template exists');
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }

    private function assertStaffShellRoot(string $html, string $label): void
    {
        $found = false;
        foreach (self::STAFF_SHELL_ROOT_PATTERNS as $token) {
            if (str_contains($html, $token)) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, $label . ': canonical document carries the shared Staff Portal shell root marker');
    }
}

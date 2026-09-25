<?php
/**
 * Phase 10 — Doctor Portal Visit Workspace: Recommendation + Follow-Up Authoring — TEST-ONLY RED.
 *
 * Approved slice contract (owner-approved, no further decision required):
 *   Inside the EXISTING Doctor Portal Visit Workspace the authoring doctor
 *   records (A) recommendations and (B) the follow-up for the currently
 *   authorized Visit, while (C) recommendations/follow-ups already on the
 *   Visit remain readable/rendered. Existing domain/service/repository/
 *   schema/audit contracts are reused (D). Portal-specific doctor/Clinic/
 *   Location/Visit authority is enforced server-side (E). Complete/Reopen,
 *   prescription void/print, handwriting, files, patient search, finance,
 *   schedule/Location management, MRN and notification architecture are OUT.
 *
 * Intended portal boundary — a direct, minimal adapter of the ALREADY-MERGED
 * Doctor Portal architecture (same pattern as the merged Visit Workspace
 * record/notes and the merged prescription write boundary). The shared E12/E13
 * staff/admin behavior is NOT tightened by this slice:
 *   POST /clinic/v1/doctor/portal/visits/{id}/recommendations   (E12 authoring)
 *   POST /clinic/v1/doctor/portal/visits/{id}/follow-ups        (E13 authoring)
 *
 * Authority for both portal writes (selector headers are validated against
 * persisted authority, never trusted by themselves; clinician_id is NEVER
 * client authority):
 *   authenticated WP user + doctor role + cpms_rec_create
 *   + server-derived active clinician identity
 *   + active trusted Clinic
 *   + trusted operational Location (0 eligible => fail closed, 1 => auto
 *     resolution, N>1 => explicit trusted Location REQUIRED, foreign/inactive/
 *     unassigned => fail closed; never guess the first Location)
 *   + Visit owned by that clinician at that Clinic/Location.
 * Cross-doctor / foreign-Clinic / foreign-Location Visit selectors fail
 * non-enumerating (404 CLINIC_NOT_FOUND); a foreign Clinic selector header
 * fails at the shared trusted-Clinic boundary (403 CLINIC_SCOPE_UNAVAILABLE).
 *
 * Live domain contract (verified live against ClinicalService on main
 * 4656a66096d8ec5287d67d5304161ce36138a7f9 — re-verified pre-write):
 *   E12 addRecommendations (ClinicalService:560+) — items non-empty; per item
 *   type ∈ REC_TYPES ['diet','rest','activity','care','lab','followup','other']
 *   (constant at :54); trimmed text 1..1000; is_patient_visible taken from the
 *   item with the established !empty() semantics (absent => 0/private);
 *   visit_id/patient_id/clinician_id are SERVER-derived from the Visit;
 *   audit action RECOMMENDATIONS_CREATED (resource_type 'visit', actor doctor,
 *   after_json {"count":N}).
 *   Response: {created, recommendations[]} with established presenter keys
 *   (id, visit_id, type, text, is_patient_visible, created_at).
 *   E13 addFollowUp (ClinicalService:625+) — is_needed; when needed, at least
 *   one of suggested_date (valid Y-m-d) / interval_days (1..3650); reason
 *   truncated at 255; row status 'pending'; audit action FOLLOW_UP_CREATED
 *   (resource_type 'follow_up'); existing reminder path stays
 *   FollowUpReminderHandler (jobs layer) — authoring itself creates no
 *   notification/provider state.
 *   Response: established presenter keys (id, visit_id, is_needed,
 *   suggested_date, interval_days, reason, status, created_at) — no new field,
 *   no new visibility state, no appointment creation.
 *
 * INTENDED PRODUCT RED (missing portal behavior, verified pre-write):
 *   - neither portal write route above exists on live main (REST dispatch
 *     returns 404 rest_no_route) — Groups 1..5 anchor here;
 *   - the portal shell template/inline JS has NO recommendation/follow-up
 *     composer/list/read wiring — Group 8 anchors here (plus the harness
 *     contract that the GREEN browser journey must live in the EXISTING
 *     bin/pilot-doctor-portal.py used by the pilot gate).
 * Groups 6 and 7 are MATERIAL CONTROLS that are already GREEN and MUST stay
 * GREEN (existing read/patient-visibility contract preserved; shared E12/E13
 * regression control). Group 1's read assertions are GREEN controls too.
 *
 * Test-only: no product PHP/template/CSS/JS, no migration (latest remains
 * 2026_09_20_0022), no workflow change, no docs closure.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10DoctorPortalRecommendationFollowUpWriteRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const PORTAL_RECORD = 'clinic/v1/doctor/portal/visits/%d/record';
    private const PORTAL_REC_CREATE = 'clinic/v1/doctor/portal/visits/%d/recommendations';
    private const PORTAL_FU_CREATE = 'clinic/v1/doctor/portal/visits/%d/follow-ups';
    private const SHARED_REC_CREATE = 'clinic/v1/visits/%d/recommendations';
    private const SHARED_FU_CREATE = 'clinic/v1/visits/%d/follow-ups';
    private const PATIENT_VISIT_DETAIL = 'clinic/v1/visits/%d';
    private const FIXED_UTC = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const TZ_TEHRAN = 'Asia/Tehran';

    /** Current established recommendation type enum (ClinicalService::REC_TYPES). */
    private const REC_TYPES = ['diet', 'rest', 'activity', 'care', 'lab', 'followup', 'other'];

    /** Established presenter key sets — a parallel backend with new fields must fail. */
    private const REC_KEYS = ['id', 'visit_id', 'type', 'text', 'is_patient_visible', 'created_at'];
    private const FU_KEYS = ['id', 'visit_id', 'is_needed', 'suggested_date', 'interval_days', 'reason', 'status', 'created_at'];

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

    // ============ Group 1 — PORTAL WRITE ENTRY (read = GREEN control; write = RED anchor) ============

    public function testGroup1_PortalWorkspaceWriteEntryAndReadParity(): void
    {
        $fx = $this->makePortalStage('g1');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g1_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // ---- GREEN material control: the existing portal read payload stays available ----
        $rRecord = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        $this->gate(200, $rRecord,
            'G1.control: authorized portal record reaches the existing workspace architecture, got '
            . $rRecord->get_status() . '/' . $this->errCode($rRecord));
        $record = $this->payload($rRecord);
        self::assertArrayHasKey('recommendations', $record, 'G1.control: Visit record retains recommendations list');
        self::assertArrayHasKey('follow_ups', $record, 'G1.control: Visit record retains follow_ups list');
        self::assertIsArray($record['recommendations'], 'G1.control: recommendations list is an array');
        self::assertIsArray($record['follow_ups'], 'G1.control: follow_ups list is an array');

        // ---- INTENDED PRODUCT RED: the portal authoring boundary must exist and be reachable ----
        if (!$this->portalRouteRegistered('/recommendations')) {
            $this->annotate('missing-route', 'G1.A: portal recommendation write boundary is not registered', '/doctor/portal/visits/{id}/recommendations');
        }
        self::assertTrue($this->portalRouteRegistered('/recommendations'),
            'G1.A: Doctor Portal recommendation authoring boundary registered under the established portal naming pattern');
        if (!$this->portalRouteRegistered('/follow-ups')) {
            $this->annotate('missing-route', 'G1.B: portal follow-up write boundary is not registered', '/doctor/portal/visits/{id}/follow-ups');
        }
        self::assertTrue($this->portalRouteRegistered('/follow-ups'),
            'G1.B: Doctor Portal follow-up authoring boundary registered under the established portal naming pattern');

        $recText = 'توصیه پایلوت G1 — استراحت';
        $rRec = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visit), [
            'items' => [['type' => 'rest', 'text' => $recText, 'is_patient_visible' => true]],
        ], $headers);
        $this->gate(200, $rRec,
            'G1.A: authorized portal recommendation write must succeed at the intended portal boundary, got '
            . $rRec->get_status() . '/' . $this->errCode($rRec));

        $recPayload = $this->payload($rRec);
        self::assertSame(['created', 'recommendations'], $this->sortedKeys($recPayload),
            'G1.A: portal recommendation response reuses the established E12 payload (no parallel backend shape)');
        self::assertSame(1, (int) ($recPayload['created'] ?? -1), 'G1.A: created count is reported');
        $recEntry = $recPayload['recommendations'][0] ?? null;
        self::assertIsArray($recEntry, 'G1.A: created recommendation is present in the response');
        self::assertKeySet(self::REC_KEYS, $recEntry,
            'G1.A: recommendation entry uses the established presenter keys only');
        self::assertSame('rest', $recEntry['type'], 'G1.A: established type round-trip');
        self::assertSame($recText, $recEntry['text'], 'G1.A: text round-trip');
        self::assertTrue($recEntry['is_patient_visible'], 'G1.A: visibility round-trip');
        foreach (['clinician_id', 'patient_id', 'clinic_id', 'organization_id'] as $leak) {
            self::assertArrayNotHasKey($leak, $recEntry,
                'G1.A: server-derived ownership field must not leak into the response (' . $leak . ')');
        }

        $rFu = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FU_CREATE, $visit), [
            'is_needed' => true,
            'interval_days' => 30,
            'reason' => 'کنترل فشار خون',
        ], $headers);
        $this->gate(200, $rFu,
            'G1.B: authorized portal follow-up write must succeed at the intended portal boundary, got '
            . $rFu->get_status() . '/' . $this->errCode($rFu));

        $fuPayload = $this->payload($rFu);
        self::assertKeySet(self::FU_KEYS, $fuPayload,
            'G1.B: portal follow-up response reuses the established E13 presenter (no parallel backend shape)');
        self::assertTrue($fuPayload['is_needed'], 'G1.B: is_needed round-trip');
        self::assertSame(30, (int) $fuPayload['interval_days'], 'G1.B: interval_days round-trip');
        self::assertSame('pending', (string) $fuPayload['status'], 'G1.B: established status semantics');

        // The new rows are the SAME established rows the Visit record reads back.
        $rRecord2 = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        $this->gate(200, $rRecord2, 'G1.B: portal record re-read after authoring');
        $record2 = $this->payload($rRecord2);
        self::assertSame($recText, (string) ($record2['recommendations'][0]['text'] ?? ''), 'G1.B: record reads the new recommendation');
        self::assertSame(30, (int) ($record2['follow_ups'][0]['interval_days'] ?? 0), 'G1.B: record reads the new follow-up');
    }

    // ============ Group 2 — DOCTOR / VISIT / TENANT AUTHORITY (intended RED) ============

    public function testGroup2_PortalRecommendationFollowUpAuthorityAndIsolation(): void
    {
        $fx = $this->makePortalStage('g2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patientOwn = $this->insertPatient($fx['clinic'], 'g2_own');
        $visitOwn = $this->insertVisit($patientOwn, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // Cross-doctor Visit in the SAME clinic — distinct WP user (u_clinician_user).
        $doctorB = $this->makeUser('g2_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G2 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], 'cpms_doctor');
        $patientOther = $this->insertPatient($fx['clinic'], 'g2_other');
        $visitOther = $this->insertVisit($patientOther, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        // Foreign Clinic/Location/clinician — distinct WP user + persisted bindings.
        $foreignUser = $this->makeUser('g2_foreign_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $foreignOrg = $this->insertOrg('G2 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G2 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G2 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianForeign = $this->insertClinician('Dr G2 Foreign', $clinicForeign, 1, $foreignUser);
        cpms_test_seed_membership($foreignUser, $clinicForeign, 'cpms_doctor');
        $patientForeign = $this->insertPatient($clinicForeign, 'g2_foreign');
        $visitForeign = $this->insertVisit($patientForeign, $clinicianForeign, $clinicForeign, $locForeign, 'in_consultation');

        // Same clinic, other Location — own doctor but a Visit bound to another Location.
        $locOther = $this->insertLocation($fx['clinic'], 'G2 Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g2_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');

        // ---- FIXTURE INTEGRITY (material fixtures must be proven persisted) ----
        $users = [$fx['doctor'], $doctorB, $foreignUser];
        self::assertSame(count($users), count(array_unique($users)), 'G2.fixture: doctor WP users must be distinct');
        $this->assertClinicianBinding($clinicianB, $fx['clinic'], $doctorB);
        $this->assertClinicianBinding($clinicianForeign, $clinicForeign, $foreignUser);
        $this->assertVisitBinding($visitOwn, $fx['clinic'], $fx['location'], $fx['clinician'], $patientOwn);
        $this->assertVisitBinding($visitOther, $fx['clinic'], $fx['location'], $clinicianB, $patientOther);
        $this->assertVisitBinding($visitOtherLoc, $fx['clinic'], $locOther, $fx['clinician'], $patientOtherLoc);
        $this->assertVisitBinding($visitForeign, $clinicForeign, $locForeign, $clinicianForeign, $patientForeign);

        $recBody = static fn(): array => ['items' => [['type' => 'care', 'text' => 'توصیه G2', 'is_patient_visible' => false]]];
        $fuBody = ['is_needed' => true, 'interval_days' => 14, 'reason' => 'پیگیری G2'];

        // A. Own doctor + trusted Clinic/Location + own Visit => success (INTENDED PRODUCT RED).
        $rOwn = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOwn), $recBody(), $headers);
        $this->gate(200, $rOwn,
            'G2.A: own authorized Visit succeeds at the portal recommendation boundary, got '
            . $rOwn->get_status() . '/' . $this->errCode($rOwn));
        $rOwnFu = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FU_CREATE, $visitOwn), $fuBody, $headers);
        $this->gate(200, $rOwnFu,
            'G2.A: own authorized Visit succeeds at the portal follow-up boundary, got '
            . $rOwnFu->get_status() . '/' . $this->errCode($rOwnFu));

        // B. Cross-doctor Visit selector => non-enumerating 404.
        $rCross = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOther), $recBody(), $headers);
        $this->gate(404, $rCross, 'G2.B: cross-doctor portal recommendation write denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCross), 'G2.B: non-enumerating 404 contract');
        $rCrossFu = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FU_CREATE, $visitOther), $fuBody, $headers);
        $this->gate(404, $rCrossFu, 'G2.B: cross-doctor portal follow-up write denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCrossFu), 'G2.B: non-enumerating 404 contract');

        // C. Foreign-Clinic Visit selector => non-enumerating 404.
        $rForeignVisit = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitForeign), $recBody(), $headers);
        $this->gate(404, $rForeignVisit, 'G2.C: foreign-clinic Visit selector denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignVisit), 'G2.C: non-enumerating 404 contract');

        // C2. Foreign-Clinic selector header (no membership) => fail closed at the trusted-Clinic boundary.
        $rForeignHeader = $this->dispatch(
            'POST',
            '/' . sprintf(self::PORTAL_FU_CREATE, $visitOwn),
            $fuBody,
            $this->scopeHeaders($clinicForeign, $locForeign)
        );
        $this->gate(403, $rForeignHeader, 'G2.C2: foreign Clinic selector header fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rForeignHeader), 'G2.C2: established scope denial code');

        // D. Foreign-Location Visit selector (own Clinic headers) => non-enumerating 404.
        $rForeignLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOtherLoc), $recBody(), $headers);
        $this->gate(404, $rForeignLoc, 'G2.D: foreign-Location Visit selector denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignLoc), 'G2.D: non-enumerating 404 contract');

        // E. Secretary (staff, non-doctor) => denied at the portal boundary.
        $secretary = $this->makeUser('g2_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($secretary);
        $rSecretary = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOwn), $recBody(), $headers);
        $this->gate(403, $rSecretary, 'G2.E: secretary denied at the portal authoring boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSecretary), 'G2.E: permission boundary code');

        // E2. Doctor without an ACTIVE clinician identity => denied (server-derived identity required).
        $noIdentity = $this->makeUser('g2_no_identity', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($noIdentity, $fx['clinic'], 'cpms_doctor');
        wp_set_current_user($noIdentity);
        $rNoIdentity = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FU_CREATE, $visitOwn), $fuBody, $headers);
        $this->gate(403, $rNoIdentity, 'G2.E2: doctor without active clinician identity denied');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rNoIdentity), 'G2.E2: identity boundary code');

        // F. Missing nonce => rejected before any write.
        $noNonce = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOwn), $recBody(), $headers, false);
        $this->gateIn([401, 403], $noNonce, 'G2.F: portal authoring requires the portal nonce');

        // G. Client clinician_id is NOT authority: correct-looking client value still fails cross-doctor,
        //    and a foreign client value on the OWN Visit cannot override the server-derived identity.
        wp_set_current_user($fx['doctor']);
        $withClientAuthority = $recBody();
        $withClientAuthority['clinician_id'] = $fx['clinician'];
        $rClientAuthority = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOther), $withClientAuthority, $headers);
        $this->gate(404, $rClientAuthority,
            'G2.G: client clinician_id must not create authority for a cross-doctor Visit');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rClientAuthority), 'G2.G: non-enumerating 404 contract');

        $withForeignClinician = $recBody();
        $withForeignClinician['clinician_id'] = $clinicianForeign;
        $rOverride = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitOwn), $withForeignClinician, $headers);
        $this->gate(200, $rOverride, 'G2.G: own Visit authoring still succeeds when a client clinician_id is supplied');
        $ownRows = $this->recommendationRowsForVisit($visitOwn);
        self::assertNotEmpty($ownRows, 'G2.G: own-Visit recommendation really persisted');
        foreach ($ownRows as $row) {
            self::assertSame($fx['clinician'], (int) $row['clinician_id'],
                'G2.G: ownership is server-derived, the client clinician_id is ignored');
        }

        // Denied selectors must have written NOTHING.
        self::assertSame([], $this->recommendationRowsForVisit($visitOther), 'G2: no row written for the cross-doctor Visit');
        self::assertSame([], $this->followUpRowsForVisit($visitOther), 'G2: no follow-up written for the cross-doctor Visit');
        self::assertSame([], $this->recommendationRowsForVisit($visitForeign), 'G2: no row written for the foreign-Clinic Visit');
        self::assertSame([], $this->recommendationRowsForVisit($visitOtherLoc), 'G2: no row written for the foreign-Location Visit');
    }

    // ============ Group 3 — LOCATION POLICY (intended RED) ============

    public function testGroup3_PortalAuthoringLocationPolicy(): void
    {
        // A. N>1 eligible Locations without an explicit trusted Location => REQUIRED (never guess the first).
        $fxMulti = $this->makePortalStage('g3m');
        $locSecond = $this->insertLocation($fxMulti['clinic'], 'G3 Second Loc', self::TZ_TEHRAN, 0);
        self::assertGreaterThan(0, $locSecond, 'G3.fixture: second Location persisted');
        $patientMulti = $this->insertPatient($fxMulti['clinic'], 'g3m_patient');
        $visitMulti = $this->insertVisit($patientMulti, $fxMulti['clinician'], $fxMulti['clinic'], $fxMulti['location'], 'in_consultation');

        wp_set_current_user($fxMulti['doctor']);
        $rNoLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitMulti), [
            'items' => [['type' => 'diet', 'text' => 'توصیه G3', 'is_patient_visible' => true]],
        ], $this->scopeHeaders($fxMulti['clinic'], null));
        $this->gate(400, $rNoLoc, 'G3.A: N>1 eligible Locations requires an explicit trusted Location');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rNoLoc), 'G3.A: location-required code');
        $rawNoLoc = $this->rawErrorData($rNoLoc);
        self::assertSame('location_id', $rawNoLoc['field'] ?? null, 'G3.A: field=location_id');
        self::assertSame('location_required', $rawNoLoc['reason'] ?? null, 'G3.A: reason=location_required');
        self::assertArrayNotHasKey('eligible_location_ids', $rawNoLoc, 'G3.A: eligible Location IDs must not leak');
        self::assertSame([], $this->recommendationRowsForVisit($visitMulti), 'G3.A: no row written without a trusted Location');

        // B. N>1 with the explicit trusted Location => succeeds.
        $rWithLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitMulti), [
            'items' => [['type' => 'diet', 'text' => 'توصیه G3 با مکان', 'is_patient_visible' => true]],
        ], $this->scopeHeaders($fxMulti['clinic'], $fxMulti['location']));
        $this->gate(200, $rWithLoc, 'G3.B: explicit trusted Location authoring succeeds');
        self::assertNotSame([], $this->recommendationRowsForVisit($visitMulti), 'G3.B: row persisted with the trusted Location');

        // C. Exactly 1 eligible Location => auto-resolution allowed (no Location header).
        $fxSingle = $this->makePortalStage('g3s');
        $patientSingle = $this->insertPatient($fxSingle['clinic'], 'g3s_patient');
        $visitSingle = $this->insertVisit($patientSingle, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        wp_set_current_user($fxSingle['doctor']);
        $rSingle = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FU_CREATE, $visitSingle), [
            'is_needed' => true,
            'suggested_date' => '2026-04-10',
            'reason' => 'پیگیری G3',
        ], $this->scopeHeaders($fxSingle['clinic'], null));
        $this->gate(200, $rSingle, 'G3.C: single eligible Location auto-resolution succeeds');

        // D. 0 eligible Locations => fail closed.
        $fxZero = $this->makePortalStage('g3z');
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE clinic_id = %d', $fxZero['clinic']));
        $activeLeft = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1',
            [$fxZero['clinic']]
        );
        self::assertSame(0, $activeLeft, 'G3.fixture: zero eligible Locations really persisted');
        $patientZero = $this->insertPatient($fxZero['clinic'], 'g3z_patient');
        $visitZero = $this->insertVisit($patientZero, $fxZero['clinician'], $fxZero['clinic'], $fxZero['location'], 'in_consultation');
        wp_set_current_user($fxZero['doctor']);
        $rZero = $this->dispatch('POST', '/' . sprintf(self::PORTAL_REC_CREATE, $visitZero), [
            'items' => [['type' => 'lab', 'text' => 'توصیه G3 صفر', 'is_patient_visible' => false]],
        ], $this->scopeHeaders($fxZero['clinic'], $fxZero['location']));
        $this->gateIn([403, 404], $rZero, 'G3.D: zero eligible Locations fails closed');
        self::assertSame([], $this->recommendationRowsForVisit($visitZero), 'G3.D: no row written with zero eligible Locations');

        // E. Explicit Location of a FOREIGN Clinic => fail closed.
        $foreignOrg = $this->insertOrg('G3 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G3 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G3 Foreign Loc', self::TZ_TEHRAN, 1);
        $rForeignLocHeader = $this->dispatch(
            'POST',
            '/' . sprintf(self::PORTAL_REC_CREATE, $visitSingle),
            ['items' => [['type' => 'care', 'text' => 'توصیه G3 خارجی', 'is_patient_visible' => false]]],
            $this->scopeHeaders($fxSingle['clinic'], $locForeign)
        );
        $this->gate(403, $rForeignLocHeader, 'G3.E: foreign explicit Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rForeignLocHeader), 'G3.E: established scope denial code');

        // F. Inactive explicit Location (own Clinic) => fail closed.
        $locInactive = $this->insertLocation($fxSingle['clinic'], 'G3 Inactive Loc', self::TZ_TEHRAN, 0);
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE id = %d', $locInactive));
        $inactiveActive = (int) App::db()->fetchValue(
            'SELECT is_active FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d',
            [$locInactive]
        );
        self::assertSame(0, $inactiveActive, 'G3.fixture: inactive Location really persisted inactive');
        $rInactiveLoc = $this->dispatch(
            'POST',
            '/' . sprintf(self::PORTAL_REC_CREATE, $visitSingle),
            ['items' => [['type' => 'care', 'text' => 'توصیه G3 غیرفعال', 'is_patient_visible' => false]]],
            $this->scopeHeaders($fxSingle['clinic'], $locInactive)
        );
        $this->gate(403, $rInactiveLoc, 'G3.F: inactive explicit Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rInactiveLoc), 'G3.F: established scope denial code');

        // G. Location-scoped membership, explicit Location NOT assigned to the doctor => fail closed.
        $fxUnassigned = $this->makePortalStage('g3u');
        $locUnassigned = $this->insertLocation($fxUnassigned['clinic'], 'G3 Unassigned Loc', self::TZ_TEHRAN, 0);
        $service = App::membership_service();
        $membership = $service->membership_for($fxUnassigned['clinic'], $fxUnassigned['doctor']);
        self::assertIsArray($membership, 'G3.fixture: doctor membership really persisted');
        $service->set_scope_mode((int) $membership['id'], 'location', [(int) $fxUnassigned['location']]);
        $assigned = $service->membership_location_ids((int) $membership['id']);
        self::assertSame([(int) $fxUnassigned['location']], $assigned, 'G3.fixture: membership assignment is exactly one Location');
        $patientUnassigned = $this->insertPatient($fxUnassigned['clinic'], 'g3u_patient');
        $visitUnassigned = $this->insertVisit($patientUnassigned, $fxUnassigned['clinician'], $fxUnassigned['clinic'], $fxUnassigned['location'], 'in_consultation');
        wp_set_current_user($fxUnassigned['doctor']);
        $rUnassigned = $this->dispatch(
            'POST',
            '/' . sprintf(self::PORTAL_REC_CREATE, $visitUnassigned),
            ['items' => [['type' => 'rest', 'text' => 'توصیه G3 تخصیص‌نیافته', 'is_patient_visible' => false]]],
            $this->scopeHeaders($fxUnassigned['clinic'], $locUnassigned)
        );
        $this->gate(403, $rUnassigned, 'G3.G: explicit unassigned Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rUnassigned), 'G3.G: established scope denial code');
        self::assertSame([], $this->recommendationRowsForVisit($visitUnassigned), 'G3.G: no row written for the unassigned Location');
    }

    // ============ Group 4 — RECOMMENDATION DOMAIN CONTRACT (intended RED) ============

    public function testGroup4_PortalRecommendationDomainContract(): void
    {
        $fx = $this->makePortalStage('g4');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g4_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $route = '/' . sprintf(self::PORTAL_REC_CREATE, $visit);

        // A. Existing types only — all seven established types in one write, per-item visibility preserved.
        $items = [];
        foreach (self::REC_TYPES as $index => $type) {
            $items[] = [
                'type' => $type,
                'text' => 'توصیه پایلوت ' . $type,
                'is_patient_visible' => ($index % 2 === 0),
            ];
        }
        $rAll = $this->dispatch('POST', $route, ['items' => $items], $headers);
        $this->gate(200, $rAll,
            'G4.A: portal write with the seven established recommendation types, got '
            . $rAll->get_status() . '/' . $this->errCode($rAll));
        $payload = $this->payload($rAll);
        self::assertSame(7, (int) ($payload['created'] ?? -1), 'G4.A: created count matches the seven live types');
        self::assertCount(7, $payload['recommendations'] ?? [], 'G4.A: response carries the created recommendations');
        $types = array_map(static fn (array $rec): string => (string) $rec['type'], $payload['recommendations']);
        self::assertSame(self::REC_TYPES, $types, 'G4.A: established types persisted in order (no invented enum)');

        $rows = $this->recommendationRowsForVisit($visit);
        self::assertCount(7, $rows, 'G4.A: seven rows really persisted');
        $visibleCount = 0;
        foreach ($rows as $row) {
            self::assertSame($visit, (int) $row['visit_id'], 'G4.A: row bound to the authorized Visit');
            self::assertSame($patient, (int) $row['patient_id'], 'G4.A: row bound to the Visit patient');
            self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'G4.A: row bound to the trusted Clinic');
            self::assertSame($fx['clinician'], (int) $row['clinician_id'], 'G4.A: row bound to the server-derived clinician');
            self::assertStringStartsWith('توصیه پایلوت ', (string) $row['text'], 'G4.A: row text persisted');
            $visibleCount += (int) $row['is_patient_visible'];
        }
        self::assertSame(4, $visibleCount, 'G4.A: per-item visibility persisted exactly (4 visible / 3 private)');

        // B. Established audit behavior (RECOMMENDATIONS_CREATED).
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            ['RECOMMENDATIONS_CREATED', 'visit', $visit]
        );
        self::assertIsArray($audit, 'G4.B: RECOMMENDATIONS_CREATED audit row exists (established audit intact)');
        self::assertSame($fx['doctor'], (int) $audit['actor_wp_user_id'], 'G4.B: audit actor is the authorized doctor');
        self::assertSame($patient, (int) $audit['patient_id'], 'G4.B: audit patient binding');
        $after = json_decode((string) $audit['after_json'], true);
        self::assertSame(7, (int) ($after['count'] ?? -1), 'G4.B: audit after_json carries the established count');

        // C. Invented recommendation type => rejected by the existing domain contract.
        $rNewType = $this->dispatch('POST', $route, [
            'items' => [['type' => 'specialist_referral', 'text' => 'نوع تازه', 'is_patient_visible' => false]],
        ], $headers);
        $this->gate(422, $rNewType, 'G4.C: a new/invented recommendation type is rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rNewType), 'G4.C: established validation code');
        self::assertSame('specialist_referral', $this->rawErrorData($rNewType)['type'] ?? null, 'G4.C: offending type echoed');

        // D. Established text validation (1..1000) and non-empty items.
        $rEmpty = $this->dispatch('POST', $route, ['items' => []], $headers);
        $this->gate(422, $rEmpty, 'G4.D: empty items rejected at the portal boundary');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rEmpty), 'G4.D: established validation code');

        $rNoText = $this->dispatch('POST', $route, ['items' => [['type' => 'care']]], $headers);
        $this->gate(422, $rNoText, 'G4.D: missing text rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rNoText), 'G4.D: established validation code');

        $rTooLong = $this->dispatch('POST', $route, [
            'items' => [['type' => 'care', 'text' => str_repeat('پ', 1001)]],
        ], $headers);
        $this->gate(422, $rTooLong, 'G4.D: text longer than 1000 characters rejected');

        $boundaryText = str_repeat('ب', 1000);
        $rBoundary = $this->dispatch('POST', $route, [
            'items' => [['type' => 'care', 'text' => $boundaryText, 'is_patient_visible' => true]],
        ], $headers);
        $this->gate(200, $rBoundary, 'G4.D: text at the established 1000-character maximum accepted');
        $boundaryRow = App::db()->fetchRow(
            'SELECT text FROM ' . App::db()->table('cpms_recommendations') .
            ' WHERE visit_id = %d AND type = %s ORDER BY id DESC LIMIT 1',
            [$visit, 'care']
        );
        self::assertIsArray($boundaryRow, 'G4.D: boundary row persisted');
        self::assertSame(1000, mb_strlen((string) $boundaryRow['text']), 'G4.D: boundary text stored in full');

        // E. Established visibility semantics/default: explicit false/true honoured, absent key => private (0).
        $rDefaultVisibility = $this->dispatch('POST', $route, [
            'items' => [['type' => 'lab', 'text' => 'بدون کلید نمایش']],
        ], $headers);
        $this->gate(200, $rDefaultVisibility, 'G4.E: item without the visibility key follows the established domain default');
        $defaultPayload = $this->payload($rDefaultVisibility);
        $defaultEntry = $defaultPayload['recommendations'][count($defaultPayload['recommendations']) - 1] ?? null;
        self::assertIsArray($defaultEntry, 'G4.E: default-visibility item returned');
        self::assertFalse((bool) $defaultEntry['is_patient_visible'], 'G4.E: established default is private (0)');
        self::assertSame('lab', (string) $defaultEntry['type'], 'G4.E: default-visibility row is the lab item');
        $defaultRow = App::db()->fetchRow(
            'SELECT is_patient_visible FROM ' . App::db()->table('cpms_recommendations') .
            ' WHERE visit_id = %d AND text = %s ORDER BY id DESC LIMIT 1',
            [$visit, 'بدون کلید نمایش']
        );
        self::assertIsArray($defaultRow, 'G4.E: default-visibility row persisted');
        self::assertSame(0, (int) $defaultRow['is_patient_visible'], 'G4.E: absent visibility key persisted as private');

        // F. Nonce still required for the authoring boundary.
        $rNoNonce = $this->dispatch('POST', $route, ['items' => [['type' => 'care', 'text' => 'بدون nonce']]], $headers, false);
        $this->gateIn([401, 403], $rNoNonce, 'G4.F: portal recommendation authoring requires the nonce');
    }

    // ============ Group 5 — FOLLOW-UP DOMAIN CONTRACT (intended RED) ============

    public function testGroup5_PortalFollowUpDomainContract(): void
    {
        $fx = $this->makePortalStage('g5');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g5_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $route = '/' . sprintf(self::PORTAL_FU_CREATE, $visit);

        $notificationsBefore = $this->notificationCountForPatient($patient);

        // A. Valid follow-up: is_needed + interval_days + reason (established contract).
        $rInterval = $this->dispatch('POST', $route, [
            'is_needed' => true,
            'interval_days' => 30,
            'reason' => 'کنترل فشار خون',
        ], $headers);
        $this->gate(200, $rInterval, 'G5.A: valid follow-up authoring at the portal boundary');
        $fu = $this->payload($rInterval);
        self::assertKeySet(self::FU_KEYS, $fu, 'G5.A: established presenter keys only (no new field)');
        self::assertTrue($fu['is_needed'], 'G5.A: is_needed round-trip');
        self::assertSame(30, (int) $fu['interval_days'], 'G5.A: interval_days round-trip');
        self::assertNull($fu['suggested_date'], 'G5.A: suggested_date stays null when only the interval is given');
        self::assertSame('کنترل فشار خون', (string) $fu['reason'], 'G5.A: reason round-trip');
        self::assertSame('pending', (string) $fu['status'], 'G5.A: established status semantics');
        self::assertArrayNotHasKey('is_patient_visible', $fu, 'G5.A: no new patient-visibility state on follow-ups');

        $rows = $this->followUpRowsForVisit($visit);
        self::assertCount(1, $rows, 'G5.A: follow-up row really persisted');
        $row = $rows[0];
        self::assertSame($visit, (int) $row['visit_id'], 'G5.A: row bound to the authorized Visit');
        self::assertSame($patient, (int) $row['patient_id'], 'G5.A: row bound to the Visit patient');
        self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'G5.A: row bound to the trusted Clinic');
        self::assertSame($fx['clinician'], (int) $row['clinician_id'], 'G5.A: row bound to the server-derived clinician');
        self::assertSame(1, (int) $row['is_needed'], 'G5.A: is_needed persisted');
        self::assertNull($row['suggested_date'], 'G5.A: suggested_date persisted as NULL');
        self::assertNull($row['linked_appointment_id'], 'G5.A: no appointment is created by authoring');
        self::assertNull($row['reminder_sent_at'], 'G5.A: no reminder/provider state is created by authoring');

        // B. Established audit behavior (FOLLOW_UP_CREATED).
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            ['FOLLOW_UP_CREATED', 'follow_up', (int) $fu['id']]
        );
        self::assertIsArray($audit, 'G5.B: FOLLOW_UP_CREATED audit row exists (established audit intact)');
        self::assertSame($fx['doctor'], (int) $audit['actor_wp_user_id'], 'G5.B: audit actor is the authorized doctor');
        self::assertSame($patient, (int) $audit['patient_id'], 'G5.B: audit patient binding');
        $meta = json_decode((string) $audit['meta_json'], true);
        self::assertSame($visit, (int) ($meta['visit_id'] ?? 0), 'G5.B: audit meta carries the authorized Visit');

        // C. suggested_date variant + validation of the established requirement.
        $rDate = $this->dispatch('POST', $route, [
            'is_needed' => true,
            'suggested_date' => '2026-04-10',
            'reason' => 'پیگیری با تاریخ',
        ], $headers);
        $this->gate(200, $rDate, 'G5.C: valid suggested_date follow-up authoring');
        $datePayload = $this->payload($rDate);
        self::assertSame('2026-04-10', (string) $datePayload['suggested_date'], 'G5.C: suggested_date round-trip');
        self::assertNull($datePayload['interval_days'], 'G5.C: interval_days stays null');

        $rNeededWithoutWhen = $this->dispatch('POST', $route, ['is_needed' => true], $headers);
        $this->gate(422, $rNeededWithoutWhen, 'G5.C: needed follow-up without date/interval rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rNeededWithoutWhen), 'G5.C: established validation code');

        $rBadDate = $this->dispatch('POST', $route, ['is_needed' => true, 'suggested_date' => '14/03/2026'], $headers);
        $this->gate(422, $rBadDate, 'G5.C: invalid suggested_date rejected');

        // D. Established interval bounds (1..3650).
        foreach ([0, 3651] as $outOfRange) {
            $rBadInterval = $this->dispatch('POST', $route, ['is_needed' => true, 'interval_days' => $outOfRange], $headers);
            $this->gate(422, $rBadInterval, 'G5.D: interval_days=' . $outOfRange . ' rejected (established 1..3650)');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rBadInterval), 'G5.D: established validation code');
        }
        $rMaxInterval = $this->dispatch('POST', $route, ['is_needed' => true, 'interval_days' => 3650], $headers);
        $this->gate(200, $rMaxInterval, 'G5.D: interval_days at the established maximum accepted');
        self::assertSame(3650, (int) $this->payload($rMaxInterval)['interval_days'], 'G5.D: maximum interval round-trip');

        // E. Reason keeps the established truncation (255), not a new validation rule.
        $longReason = str_repeat('پ', 300);
        $rLongReason = $this->dispatch('POST', $route, ['is_needed' => true, 'interval_days' => 90, 'reason' => $longReason], $headers);
        $this->gate(200, $rLongReason, 'G5.E: long reason accepted under the established contract');
        self::assertSame(255, mb_strlen((string) $this->payload($rLongReason)['reason']), 'G5.E: reason stored at the established 255 limit');
        self::assertSame(mb_substr($longReason, 0, 255), (string) $this->payload($rLongReason)['reason'], 'G5.E: established truncation preserved');

        // F. is_needed=false keeps the established "not needed" semantics (no date/interval required).
        $rNotNeeded = $this->dispatch('POST', $route, ['is_needed' => false, 'reason' => 'نیاز نیست'], $headers);
        $this->gate(200, $rNotNeeded, 'G5.F: is_needed=false accepted with no dates');
        $notNeeded = $this->payload($rNotNeeded);
        self::assertFalse($notNeeded['is_needed'], 'G5.F: is_needed=false round-trip');
        self::assertNull($notNeeded['suggested_date'], 'G5.F: suggested_date null when not needed');
        self::assertNull($notNeeded['interval_days'], 'G5.F: interval_days null when not needed');

        // G. No new reminder/provider behavior introduced by authoring.
        self::assertSame($notificationsBefore, $this->notificationCountForPatient($patient),
            'G5.G: follow-up authoring must not create notification/provider state (existing jobs reminder path untouched)');
    }

    // ============ Group 6 — READ / PATIENT VISIBILITY CONTRACT (GREEN material control) ============

    public function testGroup6_ReadPayloadAndPatientVisibilityPreserved(): void
    {
        $fx = $this->makePortalStage('g6');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        wp_set_current_user($fx['doctor']);

        $patient = $this->insertPatient($fx['clinic'], 'g6_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        // REAL fixture rows (established schema) — both must be proven persisted.
        $visibleRec = $this->insertRecommendation($visit, $patient, $fx['clinician'], $fx['clinic'], 'rest', 'توصیه قابل‌نمایش G6', 1);
        $privateRec = $this->insertRecommendation($visit, $patient, $fx['clinician'], $fx['clinic'], 'lab', 'PRIVATE G6 توصیه پزشک', 0);
        $followUp = $this->insertFollowUp($visit, $patient, $fx['clinician'], $fx['clinic'], 1, '2026-04-20', 21, 'پیگیری G6');
        self::assertGreaterThan(0, $visibleRec, 'G6.fixture: patient-visible recommendation persisted');
        self::assertGreaterThan(0, $privateRec, 'G6.fixture: private recommendation persisted');
        self::assertGreaterThan(0, $followUp, 'G6.fixture: follow-up persisted');
        self::assertCount(2, $this->recommendationRowsForVisit($visit), 'G6.fixture: exactly two recommendation rows on the Visit');

        // A. Doctor view keeps both recommendations + the follow-up (established record contract).
        $rRecord = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        $this->gate(200, $rRecord, 'G6.A: doctor portal record still returns the Visit record');
        $record = $this->payload($rRecord);
        $recIds = array_map(static fn (array $rec): int => (int) $rec['id'], $record['recommendations'] ?? []);
        self::assertContains($visibleRec, $recIds, 'G6.A: visible recommendation present for the doctor');
        self::assertContains($privateRec, $recIds, 'G6.A: private recommendation present for the doctor');
        $fuIds = array_map(static fn (array $fu): int => (int) $fu['id'], $record['follow_ups'] ?? []);
        self::assertContains($followUp, $fuIds, 'G6.A: follow-up present for the doctor');
        foreach ($record['recommendations'] as $rec) {
            self::assertKeySet(self::REC_KEYS, $rec, 'G6.A: recommendation keys remain the established set');
            self::assertIsBool($rec['is_patient_visible'], 'G6.A: visibility is the established boolean');
        }

        // B. Patient view: only patient-visible recommendations, no private leak, follow-ups retained.
        $patientUser = $this->makePatientUser('g6_patient_user');
        $this->insertPatientLink($fx['clinic'], $patient, $patientUser);
        wp_set_current_user($patientUser);
        $rPatientView = $this->dispatch('GET', '/' . sprintf(self::PATIENT_VISIT_DETAIL, $visit));
        $this->gate(200, $rPatientView,
            'G6.B: patient Visit detail route still reachable, got ' . $rPatientView->get_status() . '/' . $this->errCode($rPatientView));
        $patientPayload = $this->payload($rPatientView);
        $patientRecIds = array_map(static fn (array $rec): int => (int) $rec['id'], $patientPayload['recommendations'] ?? []);
        self::assertContains($visibleRec, $patientRecIds, 'G6.B: patient-visible recommendation exposed');
        self::assertNotContains($privateRec, $patientRecIds, 'G6.B: private recommendation must not be exposed');
        self::assertStringNotContainsString(
            'PRIVATE G6',
            (string) json_encode($patientPayload, JSON_UNESCAPED_UNICODE),
            'G6.B: private recommendation text must not leak in the patient payload'
        );
        foreach ($patientPayload['recommendations'] as $rec) {
            self::assertTrue((bool) $rec['is_patient_visible'], 'G6.B: only visible recommendations reach the patient');
            self::assertKeySet(self::REC_KEYS, $rec, 'G6.B: no new visibility field/state introduced');
        }
        $patientFu = $patientPayload['follow_ups'] ?? [];
        self::assertCount(1, $patientFu, 'G6.B: follow-ups stay readable (no new visibility state)');
        self::assertKeySet(self::FU_KEYS, $patientFu[0], 'G6.B: follow-up keys remain the established set');

        // C. Another patient cannot read this Visit (established non-enumerating denial).
        $otherPatient = $this->insertPatient($fx['clinic'], 'g6_other_patient');
        $otherUser = $this->makePatientUser('g6_other_user');
        $this->insertPatientLink($fx['clinic'], $otherPatient, $otherUser);
        wp_set_current_user($otherUser);
        $rForeign = $this->dispatch('GET', '/' . sprintf(self::PATIENT_VISIT_DETAIL, $visit));
        $this->gate(404, $rForeign, 'G6.C: foreign patient Visit detail denied non-enumerating');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeign), 'G6.C: established non-enumerating 404');
    }

    // ============ Group 7 — SHARED E12/E13 REGRESSION CONTROL (GREEN) ============

    public function testGroup7_SharedRecommendationFollowUpContractUnchanged(): void
    {
        $fx = $this->makePortalStage('g7');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patient = $this->insertPatient($fx['clinic'], 'g7_patient');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');

        $foreignUser = $this->makeUser('g7_foreign_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $foreignOrg = $this->insertOrg('G7 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G7 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G7 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianForeign = $this->insertClinician('Dr G7 Foreign', $clinicForeign, 1, $foreignUser);
        cpms_test_seed_membership($foreignUser, $clinicForeign, 'cpms_doctor');

        // A. Shared E12 stays green for the authorized doctor (backend already implemented).
        wp_set_current_user($fx['doctor']);
        $rSharedRec = $this->dispatch('POST', '/' . sprintf(self::SHARED_REC_CREATE, $visit), [
            'items' => [['type' => 'activity', 'text' => 'توصیه مشترک G7', 'is_patient_visible' => true]],
        ], $headers);
        $this->gate(200, $rSharedRec,
            'G7.A: shared E12 authoring stays green for the owning doctor, got '
            . $rSharedRec->get_status() . '/' . $this->errCode($rSharedRec));
        $sharedPayload = $this->payload($rSharedRec);
        self::assertSame(['created', 'recommendations'], $this->sortedKeys($sharedPayload), 'G7.A: shared E12 payload unchanged');
        self::assertSame(1, (int) $sharedPayload['created'], 'G7.A: shared E12 created count unchanged');

        // A2. Established baseline the portal adapter mirrors: a client clinician_id is NOT authority —
        //     the shared contract derives ownership from the Visit and ignores the client value.
        $rSharedClientClinician = $this->dispatch('POST', '/' . sprintf(self::SHARED_REC_CREATE, $visit), [
            'items' => [['type' => 'other', 'text' => 'توصیه با clinician_id کلاینت', 'is_patient_visible' => false]],
            'clinician_id' => $clinicianForeign,
        ], $headers);
        $this->gate(200, $rSharedClientClinician, 'G7.A2: shared E12 stays green when a client clinician_id is supplied');
        $sharedRows = $this->recommendationRowsForVisit($visit);
        foreach ($sharedRows as $row) {
            self::assertSame($fx['clinician'], (int) $row['clinician_id'],
                'G7.A2: shared E12 ownership is server-derived (client clinician_id ignored)');
        }

        // B. Shared E13 stays green for the authorized doctor.
        $rSharedFu = $this->dispatch('POST', '/' . sprintf(self::SHARED_FU_CREATE, $visit), [
            'is_needed' => true,
            'interval_days' => 45,
            'reason' => 'پیگیری مشترک G7',
        ], $headers);
        $this->gate(200, $rSharedFu,
            'G7.B: shared E13 authoring stays green for the owning doctor, got '
            . $rSharedFu->get_status() . '/' . $this->errCode($rSharedFu));
        self::assertKeySet(self::FU_KEYS, $this->payload($rSharedFu), 'G7.B: shared E13 payload unchanged');

        // C. Established shared validation + role denials unchanged (no global tightening/loosening).
        $rSharedMissingWhen = $this->dispatch('POST', '/' . sprintf(self::SHARED_FU_CREATE, $visit), ['is_needed' => true], $headers);
        $this->gate(422, $rSharedMissingWhen, 'G7.C: shared E13 date/interval rule unchanged');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rSharedMissingWhen), 'G7.C: shared E13 validation code unchanged');

        $secretary = $this->makeUser('g7_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], 'cpms_secretary');
        wp_set_current_user($secretary);
        $rSharedSecretary = $this->dispatch('POST', '/' . sprintf(self::SHARED_REC_CREATE, $visit), [
            'items' => [['type' => 'care', 'text' => 'توصیه منشی', 'is_patient_visible' => false]],
        ], $headers);
        $this->gate(403, $rSharedSecretary, 'G7.C: shared E12 secretary denial preserved');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSharedSecretary), 'G7.C: shared E12 denial code preserved');

        // D. Shared tenant isolation unchanged: foreign doctor + own-Clinic header => non-enumerating 404.
        wp_set_current_user($foreignUser);
        $rForeignDoctor = $this->dispatch(
            'POST',
            '/' . sprintf(self::SHARED_REC_CREATE, $visit),
            ['items' => [['type' => 'care', 'text' => 'توصیه پزشک خارجی', 'is_patient_visible' => false]]],
            $this->scopeHeaders($clinicForeign, $locForeign)
        );
        $this->gate(404, $rForeignDoctor, 'G7.D: shared E12 cross-Clinic isolation preserved');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignDoctor), 'G7.D: non-enumerating 404 preserved');

        // E. Existing reminder path + repository/service contracts untouched (no parallel backend, no schema rewrite).
        self::assertTrue(class_exists(\ClinicCore\Application\Jobs\FollowUpReminderHandler::class),
            'G7.E: existing follow-up reminder path remains the established jobs handler');
        self::assertTrue(class_exists(\ClinicCore\Infrastructure\Repository\RecommendationRepository::class), 'G7.E: repository contract reused');
        self::assertTrue(class_exists(\ClinicCore\Infrastructure\Repository\FollowUpRepository::class), 'G7.E: repository contract reused');
        self::assertTrue(method_exists(\ClinicCore\Application\Clinical\ClinicalService::class, 'addRecommendations'), 'G7.E: E12 service contract reused');
        self::assertTrue(method_exists(\ClinicCore\Application\Clinical\ClinicalService::class, 'addFollowUp'), 'G7.E: E13 service contract reused');
        $latestMigration = (string) App::db()->fetchValue(
            'SELECT version FROM ' . App::db()->table('cpms_schema_migrations') . ' ORDER BY version DESC LIMIT 1'
        );
        self::assertSame('2026_09_20_0022', $latestMigration, 'G7.E: no migration/schema change in this slice');
    }

    // ============ Group 8 — PORTAL UI / BROWSER CONTRACT (intended RED: composer missing) ============

    public function testGroup8_PortalUiAndBrowserContract(): void
    {
        $fx = $this->makePortalStage('g8');

        // GREEN material control: the real merged portal still renders through the established entry.
        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'G8: independent Doctor Portal frontend entry must exist');
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertStringContainsString('cpms-doctor-portal-shell', $html, 'G8: real portal shell rendered');

        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/templates/doctor-portal-shell.php';
        $jsPath = $root . '/assets/js/cpms-doctor-portal.js';
        $pilotPath = $root . '/bin/pilot-doctor-portal.py';
        $pilotGatePath = $root . '/../.github/workflows/pilot-gate.yml';
        self::assertFileExists($templatePath, 'G8: portal template exists');
        self::assertFileExists($jsPath, 'G8: portal JS exists');
        self::assertFileExists($pilotPath, 'G8: existing Doctor Portal pilot harness exists');
        $template = (string) file_get_contents($templatePath);
        $ui = $template . "\n" . (string) file_get_contents($jsPath);
        $pilot = (string) file_get_contents($pilotPath);
        unset($fx);

        // ---- GREEN guards: existing workspace + RTL + no out-of-scope controls ----
        foreach (['workspace-note-form', 'workspace-visibility', 'workspace-content', 'workspace-note-submit'] as $keep) {
            self::assertStringContainsString($keep, $ui, 'G8 guard: Visit Workspace note wiring preserved (' . $keep . ')');
        }
        foreach (['workspace-rx-section', 'workspace-rx-form', 'workspace-rx-submit'] as $keep) {
            self::assertStringContainsString($keep, $ui, 'G8 guard: merged Rx workspace wiring preserved (' . $keep . ')');
        }
        self::assertStringContainsString('dir="rtl"', $template, 'G8 guard: portal stays RTL');
        self::assertStringContainsString('assert_queue_hugs_content', $pilot, 'G8 guard: established no-horizontal-overflow proof retained in the pilot');
        if (is_file($pilotGatePath)) {
            self::assertStringContainsString('pilot-doctor-portal.py', (string) file_get_contents($pilotGatePath),
                'G8 guard: the pilot gate keeps driving the existing Doctor Portal pilot');
        }
        foreach ([
            'rx-void',
            'data-action="void"',
            'rx-print',
            'prescription-print',
            'stylus',
            'handwriting-canvas',
            'workspace-file',
            'workspace-complete',
            'workspace-reopen',
            'visit-complete',
            'visit-reopen',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $ui,
                'G8 guard: out-of-scope control must not exist (' . $forbidden . ')');
        }

        // ---- INTENDED PRODUCT RED: recommendation/follow-up authoring UI wiring is missing ----
        $missing = [];
        foreach ([
            'workspace-rec-section',
            'workspace-rec-list',
            'workspace-rec-item',
            'workspace-rec-form',
            'workspace-rec-type',
            'workspace-rec-text',
            'workspace-rec-visible',
            'workspace-rec-submit',
            'workspace-rec-busy',
            'workspace-rec-error',
            'workspace-rec-success',
            'workspace-fu-section',
            'workspace-fu-list',
            'workspace-fu-item',
            'workspace-fu-form',
            'workspace-fu-date',
            'workspace-fu-interval-days',
            'workspace-fu-reason',
            'workspace-fu-submit',
            'workspace-fu-busy',
            'workspace-fu-error',
            'workspace-fu-success',
        ] as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'authoring marker (' . $marker . ')';
            }
        }
        foreach (self::REC_TYPES as $type) {
            if (!str_contains($ui, '"' . $type . '"') && !str_contains($ui, "'" . $type . "'")) {
                $missing[] = 'established recommendation type option (' . $type . ')';
                break;
            }
        }
        if (!str_contains($ui, '/doctor/portal/visits/') || !str_contains($ui, '/recommendations')) {
            $missing[] = 'POST wiring to /doctor/portal/visits/{id}/recommendations';
        }
        if (!str_contains($ui, '/doctor/portal/visits/') || !str_contains($ui, '/follow-ups')) {
            $missing[] = 'POST wiring to /doctor/portal/visits/{id}/follow-ups';
        }
        if (substr_count($ui, 'submittedVisitId') < 11) {
            $missing[] = 'stale Visit-context guard for both new composers (submitted-vs-live Visit id)';
        }
        if ($missing !== []) {
            $this->annotate('red-G8', 'G8: Doctor Portal recommendation/follow-up authoring UI wiring missing', implode('; ', $missing));
        }
        self::assertSame([], $missing,
            'G8: Doctor Portal recommendation/follow-up authoring UI wiring missing: ' . implode('; ', $missing));
    }

    /**
     * Group 8 (browser half) — the future GREEN browser journey must extend the
     * EXISTING bin/pilot-doctor-portal.py driven by the pilot gate: no new
     * browser framework, and the failed-write/busy/success proof must be real
     * (failed persistence can never be presented as success).
     */
    public function testGroup8b_PortalBrowserJourneyStaysInExistingPilot(): void
    {
        $root = dirname(__DIR__, 2);
        $pilotPath = $root . '/bin/pilot-doctor-portal.py';
        $pilotGatePath = $root . '/../.github/workflows/pilot-gate.yml';
        self::assertFileExists($pilotPath, 'G8b: existing Doctor Portal pilot harness exists');
        $pilot = (string) file_get_contents($pilotPath);

        self::assertStringContainsString('assert_queue_hugs_content', $pilot,
            'G8b guard: established no-horizontal-overflow proof retained in the existing pilot');
        self::assertStringContainsString('prove_workspace_rx', $pilot,
            'G8b guard: merged Rx workspace journey retained in the existing pilot');
        if (is_file($pilotGatePath)) {
            self::assertStringContainsString('pilot-doctor-portal.py', (string) file_get_contents($pilotGatePath),
                'G8b guard: the pilot gate keeps driving the existing Doctor Portal pilot');
        }

        $pilotMissing = [];
        foreach ([
            '/recommendations',
            '/follow-ups',
            'workspace-rec-section',
            'workspace-fu-section',
            'workspace-rec-submit',
            'workspace-fu-submit',
            'workspace-rec-busy',
            'workspace-fu-busy',
            'workspace-rec-error',
            'workspace-fu-error',
            'workspace-rec-success',
            'workspace-fu-success',
        ] as $pilotToken) {
            if (!str_contains($pilot, $pilotToken)) {
                $pilotMissing[] = 'pilot journey token (' . $pilotToken . ')';
            }
        }
        if ($pilotMissing !== []) {
            $this->annotate('red-G8b', 'G8b: recommendation/follow-up browser journey missing from the existing Doctor Portal pilot', implode('; ', $pilotMissing));
        }
        self::assertSame([], $pilotMissing,
            'G8b: the future GREEN browser journey for recommendation/follow-up authoring must live in the existing bin/pilot-doctor-portal.py: '
            . implode('; ', $pilotMissing));
    }

    // ================= helpers (proven Visit Workspace / Rx patterns) =================

    /**
     * @return array{clinic: int, location: int, clinician: int, doctor: int}
     */
    private function makePortalStage(string $tag): array
    {
        $org = $this->insertOrg('Stage ' . $tag);
        $clinic = $this->insertClinicInOrg('Stage Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $loc = $this->insertLocation($clinic, 'Stage Loc ' . $tag, self::TZ_TEHRAN, 1);
        $doctor = $this->makeUser('qa_' . $tag . '_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr QA ' . $tag, $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
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
     * @param list<string> $expected
     * @param array<string, mixed> $actual
     */
    private function assertKeySet(array $expected, array $actual, string $message): void
    {
        sort($expected);
        self::assertSame($expected, $this->sortedKeys($actual), $message);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);
        return $keys;
    }

    /**
     * Annotating status gate: identical semantics to assertSame($wanted, $r->get_status(), $message)
     * plus a standard CI runner annotation (::error) emitted ONLY when the status
     * is unexpected, so the exact failing assertion text is retrievable from the
     * check-run annotations (job log blobs are not reachable from the sandbox).
     */
    private function gate(int $wanted, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if ($got !== $wanted) {
            $this->annotate('status', $message, 'expected=' . $wanted . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertSame($wanted, $got, $message);
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
        self::assertContains($got, $wantedSet, $message);
    }

    private function annotate(string $kind, string $message, string $detail): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $message . ' :: ' . $detail));
        // Runner command escaping (workflow command v2 parameter values).
        $esc = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', ' -', ';'], $text);
        // Unique title per assertion site (G-label) — runner dedupes identical titles.
        $tokens = trim(preg_replace('/[^A-Za-z0-9._-]/', '', strstr($message, ':', true) ?: substr($message, 0, 12)));
        // STDERR bypasses PHPUnit output buffering (echo would be swallowed).
        fwrite(STDERR, '::error title=CPMS-Phase10RecFuWrite-' . $kind . '-' . $tokens . '::' . $esc . PHP_EOL);
    }

    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true): WP_REST_Response
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($withNonce) {
            $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        foreach ($headers as $k => $v) {
            $r->set_header($k, $v);
        }
        return rest_do_request($r);
    }

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
     * @return list<array<string, mixed>>
     */
    private function recommendationRowsForVisit(int $visitId): array
    {
        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table('cpms_recommendations') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        ) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUpRowsForVisit(int $visitId): array
    {
        return App::db()->fetchAll(
            'SELECT * FROM ' . App::db()->table('cpms_follow_ups') . ' WHERE visit_id = %d ORDER BY id ASC',
            [$visitId]
        ) ?: [];
    }

    private function notificationCountForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications') . ' WHERE recipient_patient_id = %d',
            [$patientId]
        );
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
        $id = (int) wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);
        return $id;
    }

    private function makePatientUser(string $login): int
    {
        return $this->makeUser($login, RolesAndCapabilities::ROLE_PATIENT);
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
        // Loud fixture guard: duplicate wp_user_id (u_clinician_user) must fail LOUDLY, never silently.
        self::assertGreaterThan(0, $id, 'clinician fixture row must persist (wp_user_id must be distinct)');
        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-QA-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture row must persist');
        return $id;
    }

    private function insertPatientLink(int $clinicId, int $patientId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at) VALUES (%d, %d, %d, %s, 1, %s)', $clinicId, $patientId, $wpUserId, $mobile, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient user link fixture row must persist (unique patient/user pair)');
        return $id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $active = 'skipped' === $status ? 0 : 1;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, self::FIXED_UTC_DATE, self::FIXED_UTC_DATE . ' 10:00:00', self::FIXED_UTC_DATE . ' 10:00:00', $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        // Loud fixture guard: FK/duplicate failures must fail LOUDLY, never become a fake "404 proof".
        self::assertGreaterThan(0, $id, 'visit fixture row must persist (clinician/location/patient FKs must be valid)');
        return $id;
    }

    private function insertRecommendation(int $visitId, int $patientId, int $clinicianId, int $clinicId, string $type, string $text, int $visible): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_recommendations (clinic_id, visit_id, patient_id, clinician_id, type, text, is_patient_visible, created_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %s)', $clinicId, $visitId, $patientId, $clinicianId, $type, $text, $visible, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'recommendation fixture row must persist');
        return $id;
    }

    private function insertFollowUp(int $visitId, int $patientId, int $clinicianId, int $clinicId, int $isNeeded, ?string $suggestedDate, ?int $intervalDays, ?string $reason): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $token = static function ($v, string $type = '%s') use ($wpdb): string {
            return $v === null ? 'NULL' : (string) $wpdb->prepare($type, $v);
        };
        $sql = 'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups'
            . ' (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, linked_appointment_id, reminder_sent_at, created_at) VALUES ('
            . (int) $clinicId . ', ' . (int) $visitId . ', ' . (int) $patientId . ', ' . (int) $clinicianId . ', ' . (int) $isNeeded . ', '
            . $token($suggestedDate) . ', ' . $token($intervalDays, '%d') . ', ' . $token($reason) . ', ' . $token('pending') . ', NULL, NULL, ' . $token($now) . ')';
        $wpdb->query($sql);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'follow-up fixture row must persist');
        return $id;
    }

    private function tryResolveDoctorPortalUrl(): ?string
    {
        $classes = ['ClinicCore\\Frontend\\DoctorPortalShell'];
        $methods = ['portal_url', 'frontendPortalUrl', 'frontendUrl', 'doctorPortalFrontendUrl', 'pageUrl'];
        foreach ($classes as $c) {
            if (!class_exists($c)) {
                continue;
            }
            foreach ($methods as $m) {
                if (!is_callable([$c, $m])) {
                    continue;
                }
                try {
                    $u = (string) $c::{$m}();
                    if ($u !== '' && !str_contains($u, '/wp-admin/') && str_contains($u, 'http')) {
                        return $u;
                    }
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
        }
        return null;
    }

    /**
     * Route presence by the established portal naming pattern (regex-tolerant:
     * the parameter regex itself is not part of the contract, the path is).
     */
    private function portalRouteRegistered(string $suffix): bool
    {
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            if (str_contains((string) $route, '/doctor/portal/visits/') && str_ends_with((string) $route, $suffix)) {
                return true;
            }
        }
        return false;
    }

    private function renderPortal(int $userId, string $url): string
    {
        wp_set_current_user($userId);
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        $req = $path . ($query !== '' ? '?' . $query : '');
        if ($req === '') {
            $req = '/';
        }
        $this->go_to($req);
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $tpl = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'G8: template_include must intercept with the plugin-owned template');
        self::assertFileExists($tpl);
        ob_start();
        include $tpl;
        return (string) ob_get_clean();
    }
}

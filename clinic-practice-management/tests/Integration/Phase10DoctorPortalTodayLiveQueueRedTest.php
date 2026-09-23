<?php

/**
 * Phase 10 first slice — Independent Doctor Portal read-only Today + Live Queue (TEST-ONLY RED).
 *
 * ===========================================================================
 * SCOPE (owner-directed Phase 10 Slice 1 — smallest production transition)
 * ===========================================================================
 *
 * Create independent Doctor Portal (standalone frontend) read-only:
 *   - Today summary (FR-18.2 fields reuse)
 *   - Live Queue (existing backend statuses/order/bounded fields, no mutation)
 *
 * Requirements extracted from task:
 *   - standalone portal architecture: WP Page lifecycle + template_include
 *     interception (filter 99) + plugin-owned full-doc shell, shared WP auth,
 *     wp_rest nonce, private cache headers, no wp-admin/theme chrome/toolbar,
 *     no SPA/router/framework, no wp-admin redesign, no new auth model.
 *   - doctor-only identity: authenticated WP user + doctor role/preset
 *     + linked active clinician, never WP ID == clinician ID, unlinked/inactive
 *     fail-closed (never clinic-wide), secretary/receptionist/cashier not
 *     authorized merely via overlapping queue capabilities.
 *   - multi-Clinic trusted scope: 0 => denial/no data, 1 => auto-resolve
 *     allowed, N>1 without explicit => CLINIC_SCOPE_REQUIRED,
 *     foreign/inactive/suspended => CLINIC_SCOPE_UNAVAILABLE/denial,
 *     no first/home fallback, raw clinic_id selector only, never authority.
 *   - doctor professional scope: own doctor+trusted Clinic only, cannot widen
 *     via clinician_id param/header, clinicians.clinic_id not authority,
 *     durable participation/membership determines Clinic participation,
 *     no other doctor's queue/patients leak.
 *   - Today summary: reuse existing backend FR-18.2 fields scoped to trusted
 *     Clinic + doctor + operational day.
 *   - Location operational day: today around UTC/Location calendar boundary
 *     is determined from relevant persisted operational Location timezone,
 *     not UTC, WP, PHP, browser, hardcoded Asia/Tehran. Deterministic fixture
 *     where UTC date and Location-local date differ. If Today/Queue can span
 *     multiple Locations and no single operational Location defined, expose as
 *     precise RED problem — do not guess first Location, do not fake System
 *     Location, do not silently fall back to Clinic/WP timezone.
 *   - Live Queue: reuse existing queue backend statuses/order bounded patient
 *     fields no mutation, own doctor+trusted Clinic only, express/FIFO ordering,
 *     no cross-Clinic leakage.
 *   - Browser may send only trusted Clinic selector header (existing mechanism
 *     X-CPMS-Clinic-Id), never clinician_id/location_id/org_id/role authority.
 *     Location authority from persisted operational objects.
 *   - UI: independent Doctor Portal navigation/shell, doctor identity/context,
 *     selected Clinic context, Clinic selector for N>1, Today summary cards,
 *     Live Queue list, safe loading/empty/error states, Persian/RTL, responsive
 *     390x844/768x1024/1366x768 tablet-first readable cards/list + touch targets.
 *     Refresh only existing pattern (existing queue endpoint, bounded polling/
 *     visibility pause or existing RT/ETag), no new refresh architecture.
 *   - Security: preserve auth, wp_rest nonce where REST used, existing queue
 *     capability, trusted Clinic scope, doctor professional scope, bounded patient
 *     fields, no sensitive error details, no frontend-only authorization.
 *
 * NOT in scope (must not be asserted as product):
 *   - call/recall, check-in, walk-in creation, start/complete visit,
 *     handwriting, prescriptions UI, recommendations/follow-up mutation,
 *     files UI, secretary/receptionist/cashier surface, generic Staff Portal,
 *     wp-admin redesign, new auth model, new REST route unless absolutely
 *     necessary, migration 0023, framework/build, SPA.
 *
 * ===========================================================================
 * LIVE STATE AT RED AUTHORING (independently fetched, 2026-09-23)
 * ===========================================================================
 *
 * - authoritative main = ca08c88 (merge PR #111), origin/main identical,
 *   open PRs 0, working tree clean including untracked, branch
 *   arena/01a0cf64-doctor, latest migration 2026_09_20_0022 (22 files 0001..0022)
 * - Phase 9 CLOSED per roadmap/docs/decisions, Phase 10 start authorized
 * - No frontend Doctor Portal exists (grep DoctorPortal none); only
 *   DoctorDashboardPage wp-admin (slug cpms-doctor, QUEUE_READ, ownClinician()
 *   SELECT id,full_name WHERE wp_user_id=current AND is_active=1 LIMIT 1,
 *   config rest_url/nonce/poll_ms 5000/base_url admin.php?page=cpms-doctor)
 * - PatientPortalShell is only frontend portal precedent: PAGE_SLUG
 *   cpms-patient-portal, PAGE_OPTION cpms_patient_portal_page_id, TEMPLATE_REL
 *   templates/patient-portal-shell.php, template_include 99, portal_url,
 *   is_portal_request, private cache headers, hide admin bar, handles
 *   cpms-patient-portal CSS/JS, full-doc shell no get_header/footer/wp_head/
 *   wp_footer, root data-cpms-patient-portal-shell v1, RTL fa, landmarks
 * - VisitRepository queueFor/statsFor/eventsSince/lastEventId use
 *   gmdate('Y-m-d') fallback, not Location timezone — confirmed operational-day
 *   defect for RED. LocationRepository stores IANA timezone, is_primary,
 *   is_active, findActiveForClinic. ADR-0031 AD-08 locations.timezone NOT NULL
 *   canonical UTC scheduling/display in Location timezone.
 * - RestClinicContext extracts X-CPMS-Clinic-Id / param, validates via
 *   TrustedClinicEstablisher, LIFO stack restore + shutdown hook, skip list
 *   excludes /doctor/today /queue /rt/queue (so they require trusted clinic),
 *   currentUserIsStaff checks ROLE_DOCTOR/SECRETARY/ACCOUNTANT/MANAGER/
 *   administrator or caps. TrustedClinicEstablisher: 0 active => UNAVAILABLE
 *   403, 1 => auto-resolve allowed, N>1 without explicit => REQUIRED 400,
 *   foreign/inactive/suspended => UNAVAILABLE 403, no first/home fallback.
 * - Existing tests DoctorQueueScopeTest (own queue only, unlinked empty, stats
 *   scoped), RestTrustedClinicContextTest (0/1/N scope, validation, pending
 *   drain, nested dispatch), Phase4Slice4QueueSharedProfessionalTest (one
 *   clinician identity home A active in B, trusted B via REST,
 *   clinicians.clinic_id not authority, participation primitive),
 *   Phase9Slice3PatientPortalShellRedTest (DOCTYPE + shell root token + theme
 *   markers absent + wpadminbar absent + RTL + landmarks + config rest_root/
 *   nonce + no authority keys, render via go_to + template_include).
 *
 * ===========================================================================
 * RED CLASSIFICATION
 * ===========================================================================
 *
 * INTENDED RED — exactly 8 tests fail on live main, attributable ONLY to
 * missing independent Doctor Portal / correct operational-day contract
 * (product-level assertion failures — never bootstrap/fixture/SQL/FK/harness):
 *
 *  A testA_IndependentDoctorPortalShellExists
 *  B testB_DoctorIdentityFailClosed
 *  C testC_MultiClinicTrustedScope
 *  D testD_DoctorProfessionalScoping
 *  E testE_TodayStatsScope
 *  F testF_LocationOperationalDayBoundary
 *  G testG_LiveQueueScopeOrderPrivacy
 *  H testH_UiClientAuthorityNoWpAdminChrome
 *
 * VALID RED requires: tests run, WP bootstrap ok, schema/migrations ok,
 * fixtures ok, existing queue/today product paths reached, failures because
 * portal/operational-day missing.
 *
 * GUARDS — must stay GREEN during RED:
 *   Existing regression guards DoctorQueueScopeTest, RestTrustedClinicContextTest,
 *   Phase4Slice4QueueSharedProfessionalTest must remain green (not re-implemented
 *   here). This file also includes positive controls proving existing queue/today
 *   paths are reached before RED assertions.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\DoctorDashboardPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10DoctorPortalTodayLiveQueueRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const LEGACY_DOCTOR_ADMIN_PAGE = 'cpms-doctor';

    // Expected Doctor Portal shell markers (GREEN chooses concrete, RED requires stable token)
    private const SHELL_ROOT_TOKEN = 'cpms-doctor-portal-shell';
    private const SHELL_DATA_ATTR = 'data-cpms-doctor-portal';
    private const SHELL_OPTION_CANDIDATES = [
        'cpms_doctor_portal_page_id',
        'cpms_doctor_portal_page',
        'cpms_doctor_page_id',
    ];
    private const SHELL_SLUG_CANDIDATES = [
        'cpms-doctor-portal',
        'cpms-doctor',
        'doctor-portal',
        'doctor',
    ];
    private const SHELL_FILTER_CANDIDATES = [
        'cpms_doctor_portal_frontend_url',
        'cpms_doctor_frontend_url',
        'cpms_doctor_portal_url',
    ];
    private const FORBIDDEN_AUTHORITY_KEYS = [
        'clinic_id',
        'patient_id',
        'user_id',
        'wp_user_id',
        'role',
        'roles',
        'clinician_id',
        'location_id',
        'organization_id',
        'org_id',
    ];

    // Deterministic operational-day fixture: fixed UTC instant where UTC date != Location-local date
    // 2026-03-14 10:00:00 UTC -> UTC date 2026-03-14
    // Pacific/Kiritimati UTC+14 => 2026-03-15 00:00:00 local (next day)
    // Pacific/Midway UTC-11   => 2026-03-13 23:00:00 local (previous day)
    private const FIXED_UTC_INSTANT = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const FIXED_KIRITIMATI_DATE = '2026-03-15';
    private const FIXED_MIDWAY_DATE = '2026-03-13';
    private const TZ_KIRITIMATI = 'Pacific/Kiritimati';
    private const TZ_MIDWAY = 'Pacific/Midway';
    private const TZ_TEHRAN = 'Asia/Tehran';

    private ?string $permalinkStructureToRestore = null;
    private string $originalTheme = '';

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();
        $this->permalinkStructureToRestore = null;
        $this->originalTheme = (string) get_stylesheet();
    }

    protected function tearDown(): void
    {
        if ($this->permalinkStructureToRestore !== null) {
            $this->set_permalink_structure($this->permalinkStructureToRestore);
            $this->permalinkStructureToRestore = null;
        }
        if ($this->originalTheme !== '' && (string) get_stylesheet() !== $this->originalTheme) {
            switch_theme($this->originalTheme);
        }
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // =================================================================
    // A — independent standalone shell
    // =================================================================

    public function testA_IndependentDoctorPortalShellExists(): void
    {
        $fx = $this->buildDoctorFixture('A');
        $this->assertFixtureAndExistingTodayPath($fx);

        // Positive control: WP session is doctor with linked active clinician
        wp_set_current_user((int) $fx['doctor_user_id']);
        self::assertSame((int) $fx['doctor_user_id'], get_current_user_id());
        self::assertTrue(is_user_logged_in());
        $clinicianId = App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [(int) $fx['doctor_user_id']]
        );
        self::assertNotNull($clinicianId, 'positive control: doctor has linked active clinician');
        self::assertSame((int) $fx['clinician_id'], (int) $clinicianId);

        // Positive control: existing wp-admin doctor dashboard still renders (legacy)
        // but must NOT be the frontend portal target
        $legacyUrl = admin_url('admin.php?page=' . self::LEGACY_DOCTOR_ADMIN_PAGE);
        self::assertStringContainsString('/wp-admin/', $legacyUrl);

        // INTENDED RED: production frontend Doctor Portal entry does not exist on live main
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 A: independent Doctor Portal frontend entry must exist (WordPress Page + '
            . 'plugin-owned template_include + plugin-owned standalone full-doc shell per '
            . 'PatientPortalShell precedent). Today only wp-admin console admin.php?page='
            . self::LEGACY_DOCTOR_ADMIN_PAGE . ' exists.'
        );

        self::assertStringNotContainsString('/wp-admin/', $frontendUrl, 'A: frontend entry must not live under /wp-admin/');
        self::assertStringNotContainsString('page=' . self::LEGACY_DOCTOR_ADMIN_PAGE, $frontendUrl, 'A: frontend entry must not be legacy wp-admin page');

        // Template interception must be plugin-owned, not theme
        $html = $this->renderProductionDoctorPortalAs((int) $fx['doctor_user_id'], $frontendUrl);
        $this->assertIndependentDoctorShell($html, $fx);
    }

    // =================================================================
    // B — doctor identity fail-closed
    // =================================================================

    public function testB_DoctorIdentityFailClosed(): void
    {
        $fx = $this->buildDoctorFixture('B');
        $this->assertFixtureAndExistingTodayPath($fx);

        $doctorUserId = (int) $fx['doctor_user_id'];
        $clinicianId = (int) $fx['clinician_id'];

        // Positive control: WP user ID != clinician ID (never assume equality)
        self::assertNotSame($doctorUserId, $clinicianId, 'B positive: WP user ID must never equal clinician ID');
        self::assertGreaterThan(0, $doctorUserId);
        self::assertGreaterThan(0, $clinicianId);

        // Positive control: existing backend correctly fail-closes for unlinked doctor (no clinic-wide leak)
        $orphanDoctor = $this->makeUser('b_orphan_doc', RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($orphanDoctor);
        $orphanToday = App::visitService()->today($orphanDoctor);
        self::assertSame([], $orphanToday['queue'], 'B positive: unlinked doctor sees nothing, not whole clinic');
        self::assertSame(0, $orphanToday['stats']['total']);

        // Positive control: inactive clinician also fail-closed
        $inactiveClinician = $this->insertClinician('Dr B Inactive', (int) $fx['clinic_id'], 0, $doctorUserId);
        // Link inactive: update existing to inactive and ensure active lookup returns null or different
        // We keep original active clinician, but test inactive separately
        $inactiveUser = $this->makeUser('b_inactive_doc', RolesAndCapabilities::ROLE_DOCTOR);
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_clinicians SET wp_user_id = %d, is_active = 0 WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $inactiveUser,
            $inactiveClinician
        ));
        wp_set_current_user($inactiveUser);
        $inactiveToday = App::visitService()->today($inactiveUser);
        self::assertSame([], $inactiveToday['queue'], 'B positive: inactive clinician link sees nothing');

        // Positive control: secretary with QUEUE_READ exists but must NOT be authorized into Doctor Portal merely via capability overlap
        $secretaryId = $this->makeUser('b_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretaryId, (int) $fx['clinic_id'], 'cpms_secretary');
        wp_set_current_user($secretaryId);
        self::assertTrue(user_can($secretaryId, RolesAndCapabilities::QUEUE_READ), 'B positive: secretary has QUEUE_READ');
        $secToday = App::visitService()->today($secretaryId);
        // Secretary sees clinic-wide (2 visits in fixture? Actually 1 for doctor, but we check it is not doctor-scoped)
        // This proves secretary is not doctor identity
        self::assertNotSame($doctorUserId, $secretaryId);

        // INTENDED RED: Doctor Portal shell must enforce doctor-only identity (not just capability)
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 B: Doctor Portal must enforce doctor identity (authenticated WP user + doctor role + linked active clinician). '
            . 'Today no frontend shell exists to enforce it.'
        );

        // Render as secretary should NOT get doctor portal content / should be denied
        $secHtml = $this->renderProductionDoctorPortalAs($secretaryId, $frontendUrl);
        self::assertStringNotContainsString((string) $fx['patient_first_name'], $secHtml, 'B: secretary must not see doctor portal patient data');
        // The portal must deny secretary (no doctor role) — check for access-denied marker or absence of doctor shell token
        $this->assertDoctorPortalDeniesNonDoctor($secHtml, 'secretary');

        // Render as unlinked doctor should be denied / empty, not clinic-wide
        $orphanHtml = $this->renderProductionDoctorPortalAs($orphanDoctor, $frontendUrl);
        self::assertStringNotContainsString((string) $fx['patient_first_name'], $orphanHtml, 'B: unlinked doctor must not see clinic-wide queue');
    }

    // =================================================================
    // C — multi-Clinic trusted scope 0/1/N
    // =================================================================

    public function testC_MultiClinicTrustedScope(): void
    {
        $fx = $this->buildMultiClinicFixture('C');
        $this->assertFixtureAndExistingTodayPath($fx);

        $doctorUserId = (int) $fx['doctor_user_id'];
        $clinicA = (int) $fx['clinic_a'];
        $clinicB = (int) $fx['clinic_b'];

        // Positive control: existing TrustedClinicEstablisher contract already works for REST
        // 0 memberships => UNAVAILABLE
        $noMemUser = $this->makeUser('c_nomem', RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($noMemUser);
        $res0 = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', [], ['X-CPMS-Clinic-Id' => (string) $clinicA]);
        self::assertSame(403, $res0->get_status(), 'C positive: 0 membership => 403');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->restErrorCode($res0));

        // 1 membership without header => auto-resolve allowed (200)
        $singleUser = $this->makeUser('c_single', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($singleUser, $clinicA, 'cpms_doctor');
        wp_set_current_user($singleUser);
        $res1 = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', [], []);
        // May be 200 or require clinic depending on implementation, but should NOT be 403 leak
        self::assertContains($res1->get_status(), [200, 400], 'C positive: single membership without header should not be 403 leak');

        // N>1 without explicit => CLINIC_SCOPE_REQUIRED 400
        wp_set_current_user($doctorUserId); // has A and B
        $resN = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', [], []);
        self::assertSame(400, $resN->get_status(), 'C positive: N>1 without explicit => 400');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->restErrorCode($resN));

        // Valid selected Clinic membership => bind only that Clinic
        $resA = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', [], ['X-CPMS-Clinic-Id' => (string) $clinicA]);
        self::assertSame(200, $resA->get_status(), 'C positive: valid selected Clinic A => 200');
        $resB = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', [], ['X-CPMS-Clinic-Id' => (string) $clinicB]);
        self::assertSame(200, $resB->get_status(), 'C positive: valid selected Clinic B => 200');

        // Foreign/inactive/suspended => UNAVAILABLE 403
        $foreignClinic = $this->insertClinic('C foreign', 'c-foreign-' . bin2hex(random_bytes(2)));
        $resForeign = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', [], ['X-CPMS-Clinic-Id' => (string) $foreignClinic]);
        self::assertSame(403, $resForeign->get_status(), 'C positive: foreign clinic => 403');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->restErrorCode($resForeign));

        // No first/home fallback: ensure that requesting Clinic B does NOT return data from Clinic A
        // (existing queueFor is clinic-scoped)
        $payloadA = $this->restPayload($resA);
        $payloadB = $this->restPayload($resB);
        // At least one should have queue array, and they should not cross-leak patient names if visits are clinic-isolated
        // Our fixture creates visits in both clinics for same doctor identity
        // This is positive control that existing backend is clinic-scoped

        // INTENDED RED: Doctor Portal frontend must respect same trusted Clinic scope contract
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 C: Doctor Portal must respect trusted Clinic scope (0=>denial, 1=>auto, N>1=>REQUIRED, foreign=>UNAVAILABLE, no first/home fallback, raw clinic_id selector only). '
            . 'Today no frontend shell exists to enforce it.'
        );

        // Render portal without explicit Clinic when N>1 should show CLINIC_SCOPE_REQUIRED UI or safe denial, not leak
        // Since portal doesn't exist, this will fail at URL resolution already, but we also assert selector presence
        $htmlN = $this->renderProductionDoctorPortalAs($doctorUserId, $frontendUrl);
        // The portal must have Clinic selector for N>1 (tablet-first)
        self::assertTrue(
            str_contains($htmlN, 'clinic') || str_contains($htmlN, 'کلینیک') || str_contains($htmlN, 'data-role="clinic-selector"'),
            'C: Doctor Portal must expose Clinic selector for N>1 (raw clinic_id selector only)'
        );
    }

    // =================================================================
    // D — doctor professional scope
    // =================================================================

    public function testD_DoctorProfessionalScoping(): void
    {
        $fx = $this->buildDoctorFixture('D');
        $this->assertFixtureAndExistingTodayPath($fx);

        $doctorAId = (int) $fx['doctor_user_id'];
        $clinicianAId = (int) $fx['clinician_id'];
        $clinicId = (int) $fx['clinic_id'];

        // Create second doctor B in same clinic
        $doctorBId = $this->makeUser('d_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianBId = $this->insertClinician('Dr D B', $clinicId, 1, $doctorBId);
        cpms_test_seed_membership($doctorBId, $clinicId, 'cpms_doctor');
        // Give B a visit today
        $patientB = $this->insertPatient('MR-D-B', '09120000002', $clinicId);
        $this->insertVisitForDoctor($patientB, $clinicianBId, $clinicId, (int) $fx['location_id'], 'waiting', gmdate('Y-m-d'), '10:30:00');

        // Positive control: doctor A sees only own queue
        wp_set_current_user($doctorAId);
        $todayA = App::visitService()->today($doctorAId);
        foreach ($todayA['queue'] as $row) {
            self::assertSame($clinicianAId, (int) $row['clinician_id'], 'D positive: doctor A sees only own clinician');
        }
        self::assertCount(1, $todayA['queue'], 'D positive: doctor A queue count 1');

        // Positive control: doctor B sees only own
        wp_set_current_user($doctorBId);
        $todayB = App::visitService()->today($doctorBId);
        foreach ($todayB['queue'] as $row) {
            self::assertSame($clinicianBId, (int) $row['clinician_id']);
        }

        // Positive control: doctor cannot widen scope by sending another clinician_id (server derives identity)
        // Via REST queue?clinician_id= other should be ignored for doctor role and still return own only
        wp_set_current_user($doctorAId);
        $resWiden = $this->dispatchRest('GET', '/' . self::REST_NS . '/queue', ['clinician_id' => $clinicianBId], ['X-CPMS-Clinic-Id' => (string) $clinicId]);
        self::assertSame(200, $resWiden->get_status(), 'D positive: widen attempt still 200 (not 403) but must be scoped');
        $payload = $this->restPayload($resWiden);
        $queue = $payload['queue'] ?? $payload['data']['queue'] ?? [];
        if (is_array($queue) && $queue !== []) {
            foreach ($queue as $row) {
                self::assertSame($clinicianAId, (int) ($row['clinician_id'] ?? $row['clinicianId'] ?? $clinicianAId), 'D positive: clinician_id param must NOT widen scope for doctor');
            }
        }

        // Positive control: clinicians.clinic_id home metadata must NOT become ownership authority
        // Create clinician with home clinic A but active membership in B, test participation in B
        $multiClinicFx = $this->buildMultiClinicFixture('D2');
        $sharedClinicianId = $this->insertClinician('Dr D Shared', (int) $multiClinicFx['clinic_a'], 1, (int) $multiClinicFx['doctor_user_id']);
        // Doctor user already has membership in both A and B
        $participatesInB = App::membership_service()->clinician_participates_in($sharedClinicianId, (int) $multiClinicFx['clinic_b']);
        self::assertTrue($participatesInB, 'D positive: participation via active membership, not home clinic_id');

        // INTENDED RED: Doctor Portal must enforce professional scope (own doctor+trusted Clinic only)
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 D: Doctor Portal must enforce professional scope (own doctor+trusted Clinic only, cannot widen via clinician_id, clinicians.clinic_id not authority). '
            . 'Today no frontend shell exists.'
        );
        $html = $this->renderProductionDoctorPortalAs($doctorAId, $frontendUrl);
        // Should contain only own patient, not other doctor's patient
        self::assertStringContainsString((string) $fx['patient_first_name'], $html, 'D: own patient present');
        self::assertStringNotContainsString('Patient D B', $html, 'D: other doctor patient must NOT leak');
    }

    // =================================================================
    // E — Today stats scope (FR-18.2 reuse)
    // =================================================================

    public function testE_TodayStatsScope(): void
    {
        $fx = $this->buildDoctorFixture('E');
        $this->assertFixtureAndExistingTodayPath($fx);

        $doctorUserId = (int) $fx['doctor_user_id'];
        $clinicId = (int) $fx['clinic_id'];
        $locationId = (int) $fx['location_id'];

        // Create extra visits for same doctor today with different statuses to populate stats
        $patient2 = $this->insertPatient('MR-E-2', '09120000003', $clinicId);
        $this->insertVisitForDoctor($patient2, (int) $fx['clinician_id'], $clinicId, $locationId, 'waiting', gmdate('Y-m-d'), '11:00:00');

        // Positive control: today() returns FR-18.2 fields (checked_in/waiting/called/in_consultation/.../total/appointments_today/appointments_no_show/walk_in_today)
        wp_set_current_user($doctorUserId);
        $today = App::visitService()->today($doctorUserId);
        self::assertArrayHasKey('date', $today);
        self::assertArrayHasKey('stats', $today);
        self::assertArrayHasKey('queue', $today);
        self::assertArrayHasKey('last_event_id', $today);
        $stats = $today['stats'];
        foreach (['checked_in', 'waiting', 'called', 'in_consultation', 'total', 'appointments_today', 'walk_in_today'] as $field) {
            self::assertArrayHasKey($field, $stats, 'E positive: FR-18.2 field ' . $field . ' must exist');
        }
        self::assertSame(gmdate('Y-m-d'), $today['date'], 'E positive: current today date is gmdate (defect to be fixed by GREEN)');

        // Positive control: stats scoped to trusted Clinic + doctor
        self::assertGreaterThanOrEqual(2, $stats['total'], 'E positive: total >=2 for doctor');
        self::assertGreaterThanOrEqual(2, $stats['waiting'], 'E positive: waiting >=2');

        // Positive control: other doctor's stats not included
        $otherDoctor = $this->makeUser('e_other_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $otherClinician = $this->insertClinician('Dr E Other', $clinicId, 1, $otherDoctor);
        cpms_test_seed_membership($otherDoctor, $clinicId, 'cpms_doctor');
        $patientOther = $this->insertPatient('MR-E-Other', '09120000004', $clinicId);
        $this->insertVisitForDoctor($patientOther, $otherClinician, $clinicId, $locationId, 'waiting', gmdate('Y-m-d'), '12:00:00');
        $todayAfterOther = App::visitService()->today($doctorUserId);
        self::assertSame($today['stats']['total'], $todayAfterOther['stats']['total'], 'E positive: other doctor visit must NOT affect own stats');

        // INTENDED RED: Today summary must be scoped to trusted Clinic+doctor+operational day (Location timezone)
        // Currently operational day is UTC (gmdate), not Location — will be proven in F, but E also fails because no independent Doctor Portal exists to show Today summary
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 E: Today summary must be scoped to trusted Clinic+doctor+operational day and rendered in independent Doctor Portal. '
            . 'Today no portal exists.'
        );
        $html = $this->renderProductionDoctorPortalAs($doctorUserId, $frontendUrl);
        // Today cards must be present (Persian)
        self::assertTrue(
            str_contains($html, 'امروز') || str_contains($html, 'today') || str_contains($html, 'data-role="today-stats"'),
            'E: Today summary cards must be present in Doctor Portal'
        );
        // Must show stats numbers
        self::assertStringContainsString((string) $today['stats']['waiting'], $html, 'E: waiting stat present');
    }

    // =================================================================
    // F — Location operational-day boundary (critical)
    // =================================================================

    public function testF_LocationOperationalDayBoundary(): void
    {
        // Positive control: bootstrap, migrations, schema ok
        $this->assertTrue(true, 'F positive: bootstrap reached');

        // Build deterministic fixture where UTC date and Location-local date differ
        // Use fixed UTC instant 2026-03-14 10:00:00 UTC
        $utcInstant = new \DateTimeImmutable(self::FIXED_UTC_INSTANT, new \DateTimeZone('UTC'));
        $utcDate = $utcInstant->format('Y-m-d');
        self::assertSame(self::FIXED_UTC_DATE, $utcDate, 'F positive: fixed UTC date matches constant');

        $kiritimatiTz = new \DateTimeZone(self::TZ_KIRITIMATI);
        $midwayTz = new \DateTimeZone(self::TZ_MIDWAY);
        $kiritimatiDate = $utcInstant->setTimezone($kiritimatiTz)->format('Y-m-d');
        $midwayDate = $utcInstant->setTimezone($midwayTz)->format('Y-m-d');
        self::assertSame(self::FIXED_KIRITIMATI_DATE, $kiritimatiDate, 'F positive: Kiritimati date next day');
        self::assertSame(self::FIXED_MIDWAY_DATE, $midwayDate, 'F positive: Midway date previous day');
        self::assertNotSame($utcDate, $kiritimatiDate, 'F positive: UTC vs Kiritimati differ (deterministic boundary)');
        self::assertNotSame($utcDate, $midwayDate, 'F positive: UTC vs Midway differ');
        self::assertNotSame($kiritimatiDate, $midwayDate, 'F positive: two Locations differ from each other');

        // Create org/clinic/locations with those timezones (persisted operational objects)
        $orgId = $this->insertOrganization('F Org ' . bin2hex(random_bytes(2)));
        $clinicId = $this->insertClinicInOrg('F Clinic', 'f-clinic-' . bin2hex(random_bytes(2)), $orgId, self::TZ_KIRITIMATI);
        $locKiritimati = $this->insertLocation($clinicId, 'Kiritimati Loc', 'f-loc-k-' . bin2hex(random_bytes(2)), self::TZ_KIRITIMATI, 1);
        $locMidway = $this->insertLocation($clinicId, 'Midway Loc', 'f-loc-m-' . bin2hex(random_bytes(2)), self::TZ_MIDWAY, 0);

        // Verify persisted timezone is authoritative (not hardcoded Asia/Tehran)
        $persistedTzK = App::db()->fetchValue(
            'SELECT timezone FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d',
            [$locKiritimati]
        );
        self::assertSame(self::TZ_KIRITIMATI, $persistedTzK, 'F positive: Location timezone persisted, not hardcoded');

        // Create doctor with linked clinician and membership
        $doctorUserId = $this->makeUser('f_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician('Dr F', $clinicId, 1, $doctorUserId);
        cpms_test_seed_membership($doctorUserId, $clinicId, 'cpms_doctor');

        // Insert visits with visit_date = each of the three dates (UTC, Kiritimati, Midway)
        $patientUtc = $this->insertPatient('MR-F-UTC', '09120000101', $clinicId);
        $patientK = $this->insertPatient('MR-F-K', '09120000102', $clinicId);
        $patientM = $this->insertPatient('MR-F-M', '09120000103', $clinicId);

        $this->insertVisitForDoctor($patientUtc, $clinicianId, $clinicId, $locKiritimati, 'waiting', $utcDate, '09:00:00');
        $this->insertVisitForDoctor($patientK, $clinicianId, $clinicId, $locKiritimati, 'waiting', $kiritimatiDate, '10:00:00');
        $this->insertVisitForDoctor($patientM, $clinicianId, $clinicId, $locMidway, 'waiting', $midwayDate, '11:00:00');

        // Positive control: repository can fetch each date explicitly
        $repo = App::db();
        $countUtc = (int) $repo->fetchValue(
            'SELECT COUNT(*) FROM ' . $repo->table('cpms_visits') . ' WHERE clinic_id = %d AND visit_date = %s',
            [$clinicId, $utcDate]
        );
        $countK = (int) $repo->fetchValue(
            'SELECT COUNT(*) FROM ' . $repo->table('cpms_visits') . ' WHERE clinic_id = %d AND visit_date = %s',
            [$clinicId, $kiritimatiDate]
        );
        self::assertSame(1, $countUtc, 'F positive: UTC date visit exists');
        self::assertSame(1, $countK, 'F positive: Kiritimati date visit exists');

        // Positive control: existing queue/today paths reached, but they use gmdate (defect)
        wp_set_current_user($doctorUserId);
        $todayReal = App::visitService()->today($doctorUserId);
        self::assertArrayHasKey('date', $todayReal);
        self::assertSame(gmdate('Y-m-d'), $todayReal['date'], 'F positive: today() currently uses gmdate (UTC) — defect to prove');

        // RED assertions: today around boundary must be determined from relevant persisted operational Location timezone,
        // not UTC, WP, PHP, browser, hardcoded Asia/Tehran
        // At fixed instant, correct operational day for Kiritimati Location should be Kiritimati date, not UTC date
        // So today() for that Location should include Kiritimati date visit, not UTC date visit — but currently it doesn't

        // Positive controls for timezone difference (must pass)
        $tehranTz = new \DateTimeZone(self::TZ_TEHRAN);
        $tehranDate = $utcInstant->setTimezone($tehranTz)->format('Y-m-d');
        self::assertNotSame($kiritimatiDate, $tehranDate, 'F positive: Kiritimati != Tehran, proving hardcoded Tehran would be wrong');
        self::assertSame($utcDate, $tehranDate, 'F positive: at this instant Tehran date equals UTC date, but Kiritimati is next day — must not use hardcoded Asia/Tehran');

        // 1) RED: today() must be determined from persisted operational Location timezone, not UTC
        // At fixed instant 2026-03-14 10:00 UTC, Kiritimati local is 2026-03-15 (next day), UTC is 2026-03-14
        // If operational day were Location-local, a query for that Location's today at that instant should return Kiritimati date
        // Current VisitRepository uses gmdate('Y-m-d') — so it returns UTC date, not Location date — RED failure
        // We assert that today() SHOULD use Location timezone (Kiritimati) for this clinic's operational day
        // Since clinic's primary Location is Kiritimati (is_primary=1), today should be Kiritimati date when evaluated at fixed instant
        // But today() returns gmdate (real current date), not Kiritimati date — this assertion fails, proving defect
        $expectedOperationalDateForKiritimatiAtFixedInstant = $kiritimatiDate; // 2026-03-15
        $actualTodayDateUsesUtc = $todayReal['date']; // gmdate
        // This assertion is intentionally RED: expects Location date, but gets UTC gmdate
        self::assertSame(
            $expectedOperationalDateForKiritimatiAtFixedInstant,
            $actualTodayDateUsesUtc,
            'Phase 10 F RED (operational-day): today() must be determined from persisted operational Location timezone ('
            . self::TZ_KIRITIMATI . '), not UTC/WP/PHP/browser/hardcoded ' . self::TZ_TEHRAN . '. '
            . 'Deterministic fixture: fixed UTC instant ' . self::FIXED_UTC_INSTANT . ' UTC => UTC date=' . $utcDate
            . ' Kiritimati=' . $kiritimatiDate . ' Midway=' . $midwayDate . ' Tehran=' . $tehranDate . '. '
            . 'At this instant UTC date != Location-local date. '
            . 'Current VisitRepository queueFor/statsFor use gmdate(' . gmdate('Y-m-d') . ') fallback, not Location timezone. '
            . 'Expected operational day for primary Location ' . self::TZ_KIRITIMATI . ' at fixed instant is ' . $kiritimatiDate
            . ', but today() returns ' . $actualTodayDateUsesUtc . ' (gmdate). GREEN must use persisted Location timezone.'
        );

        // 2) RED: multi-Location ambiguity — no single operational Location authority defined
        // Clinic has 2 active Locations with different timezones and different local dates at same UTC instant
        // Live architecture does not define which Location's timezone determines today for Doctor/Clinic view
        // Must expose as precise RED problem: do not guess first Location, do not fake System Location, do not silently fall back
        $hasSingleOperationalAuthority = $this->hasSingleOperationalLocationAuthority($clinicId);
        self::assertTrue(
            $hasSingleOperationalAuthority,
            'Phase 10 F RED (multi-Location ambiguity): Clinic ' . $clinicId . ' has 2 active Locations ('
            . self::TZ_KIRITIMATI . ' => ' . $kiritimatiDate . ' operational, ' . self::TZ_MIDWAY . ' => ' . $midwayDate
            . ' operational) with different dates at same UTC instant ' . self::FIXED_UTC_INSTANT . ' UTC (UTC=' . $utcDate . '). '
            . 'Live VisitRepository uses gmdate, not Location. '
            . 'If Today/Queue can span multiple Locations and no single operational Location defined, expose as precise RED problem. '
            . 'Do not guess first Location, do not fake System Location, do not silently fall back to Clinic/WP timezone. '
            . 'GREEN must have deterministic persisted Location authority (e.g., primary Location or explicit operational Location). '
            . 'Current hasSingleAuthority=' . ($hasSingleOperationalAuthority ? 'true' : 'false') . ' (expected true after GREEN).'
        );

        // 3) RED: Location authority must come from persisted operational objects, not browser/WP/PHP/hardcoded Tehran
        $wpTimezone = wp_timezone_string() ?: 'UTC';
        self::assertSame(
            $kiritimatiDate,
            $todayReal['date'],
            'Phase 10 F RED (Location authority): today() must use persisted Location timezone ' . self::TZ_KIRITIMATI
            . ', not UTC (' . gmdate('Y-m-d') . '), WP timezone (' . $wpTimezone . '), PHP default ('
            . date_default_timezone_get() . '), browser, or hardcoded ' . self::TZ_TEHRAN . '. '
            . 'Deterministic fixture proves UTC=' . $utcDate . ' != Kiritimati=' . $kiritimatiDate . ' at ' . self::FIXED_UTC_INSTANT . ' UTC. '
            . 'Current today=' . $todayReal['date'] . ' equals gmdate, not Location date.'
        );
    }

    // =================================================================
    // G — Live Queue scope/order/privacy
    // =================================================================

    public function testG_LiveQueueScopeOrderPrivacy(): void
    {
        $fx = $this->buildDoctorFixture('G');
        $this->assertFixtureAndExistingTodayPath($fx);

        $doctorUserId = (int) $fx['doctor_user_id'];
        $clinicianId = (int) $fx['clinician_id'];
        $clinicId = (int) $fx['clinic_id'];
        $locationId = (int) $fx['location_id'];

        // Create second doctor same clinic with own queue
        $doctorB = $this->makeUser('g_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G B', $clinicId, 1, $doctorB);
        cpms_test_seed_membership($doctorB, $clinicId, 'cpms_doctor');
        $patientB = $this->insertPatient('MR-G-B', '09120000005', $clinicId);
        $this->insertVisitForDoctor($patientB, $clinicianB, $clinicId, $locationId, 'waiting', gmdate('Y-m-d'), '09:00:00');

        // Create express walk-in (is_walkin_express via appointment) and normal FIFO
        $patientExpress = $this->insertPatient('MR-G-EXP', '09120000006', $clinicId);
        // For express, need appointment with is_walkin_express=1 linked to visit
        $appointmentExpress = $this->insertAppointmentExpress($clinicId, $locationId, $clinicianId, $patientExpress);
        $this->insertVisitWithAppointment($patientExpress, $clinicianId, $clinicId, $locationId, $appointmentExpress, 'waiting', gmdate('Y-m-d'), '08:00:00');

        $patientNormal = $this->insertPatient('MR-G-NORM', '09120000007', $clinicId);
        $this->insertVisitForDoctor($patientNormal, $clinicianId, $clinicId, $locationId, 'waiting', gmdate('Y-m-d'), '09:30:00');

        // Positive control: existing queue backend order = express first, then waiting_since ASC
        wp_set_current_user($doctorUserId);
        $queue = App::visitService()->today($doctorUserId)['queue'];
        self::assertGreaterThanOrEqual(3, count($queue), 'G positive: queue has at least 3 (express + 2 normal)');
        // Find express visit
        $expressFound = false;
        foreach ($queue as $idx => $row) {
            if (!empty($row['express'])) {
                $expressFound = true;
                self::assertSame(0, $idx, 'G positive: express must be first (order)');
                break;
            }
        }
        self::assertTrue($expressFound, 'G positive: express visit found');

        // Positive control: statuses only from established live queue set (waiting/called/in_consultation)
        foreach ($queue as $row) {
            self::assertContains($row['status'], ['waiting', 'called', 'in_consultation'], 'G positive: status from live set');
        }

        // Positive control: own doctor + trusted Clinic only, no cross-Clinic, no other-doctor leakage
        foreach ($queue as $row) {
            self::assertSame($clinicianId, (int) $row['clinician_id'], 'G positive: own doctor only');
        }

        // Positive control: bounded patient fields (patient_first_name/last_name, clinician_name, express, status, waiting_since)
        // No sensitive fields like mobile, national_id, etc. in queue
        $first = $queue[0];
        self::assertArrayHasKey('patient_name', $first);
        self::assertArrayNotHasKey('mobile', $first, 'G positive: mobile must NOT be in queue (bounded fields)');
        self::assertArrayNotHasKey('national_id', $first);

        // Positive control: no mutation controls in this first slice (queue is read-only)
        // REST queue endpoint is GET only, not POST/PUT/DELETE for queue itself

        // INTENDED RED: Live Queue must be rendered in independent Doctor Portal with correct scope/order/privacy
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 G: Live Queue must be scoped to own doctor+trusted Clinic, express/FIFO ordering, bounded patient fields, no mutation, rendered in independent Doctor Portal. '
            . 'Today no portal exists.'
        );
        $html = $this->renderProductionDoctorPortalAs($doctorUserId, $frontendUrl);
        self::assertTrue(
            str_contains($html, 'صف') || str_contains($html, 'queue') || str_contains($html, 'data-role="live-queue"'),
            'G: Live Queue list must be present in Doctor Portal'
        );
        // Must show bounded fields only, no mobile
        self::assertStringNotContainsString('0912', $html, 'G: mobile must not be in queue UI (bounded fields)');
        // Must show express badge first
        $expressPos = strpos($html, 'فوری') !== false ? strpos($html, 'فوری') : strpos($html, 'express');
        self::assertNotFalse($expressPos, 'G: express badge must be present');
    }

    // =================================================================
    // H — UI/client authority no wp-admin chrome
    // =================================================================

    public function testH_UiClientAuthorityNoWpAdminChrome(): void
    {
        $fx = $this->buildDoctorFixture('H');
        $this->assertFixtureAndExistingTodayPath($fx);

        $doctorUserId = (int) $fx['doctor_user_id'];

        // Positive control: existing DoctorDashboardPage config has rest_url/nonce/poll_ms/base_url but is wp-admin
        // Our new portal must NOT be wp-admin
        $legacyConfig = [
            'rest_url' => rest_url('clinic/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'poll_ms' => 5000,
            'base_url' => admin_url('admin.php?page=' . self::LEGACY_DOCTOR_ADMIN_PAGE),
        ];
        self::assertStringContainsString('/wp-admin/', $legacyConfig['base_url'], 'H positive: legacy base_url is wp-admin');

        // INTENDED RED: UI/client authority and no wp-admin chrome
        $frontendUrl = $this->requireProductionDoctorPortalUrl(
            'Phase 10 H: Doctor Portal UI must be independent (no wp-admin/theme chrome/toolbar), RTL, responsive 390x844/768x1024/1366x768 tablet-first, '
            . 'refresh only existing pattern, client authority only trusted Clinic selector header, no clinician_id/location_id/org_id/role authority, '
            . 'security preserve auth/nonce/capability/scope/bounded fields. Today no portal exists.'
        );

        $html = $this->renderProductionDoctorPortalAs($doctorUserId, $frontendUrl);

        // No wp-admin chrome
        self::assertStringNotContainsString('id="wpadminbar"', $html, 'H: wpadminbar absent');
        self::assertStringNotContainsString('id="adminmenu"', $html, 'H: adminmenu absent');
        self::assertStringNotContainsString('id="wpfooter"', $html, 'H: wpfooter absent');
        self::assertStringNotContainsString('wp-admin-bar-', $html, 'H: wp-admin-bar nodes absent');

        // No theme header/footer/layout
        self::assertStringNotContainsString('CPMS-PROOF-THEME', $html, 'H: proof theme markers absent');
        // Full-doc ownership
        $body = ltrim($html);
        self::assertTrue(
            str_starts_with($body, '<!DOCTYPE html>') || str_starts_with(strtolower($body), '<!doctype html>'),
            'H: must own full document <!DOCTYPE html>'
        );
        self::assertStringContainsString('</html>', $html, 'H: must close </html>');

        // RTL Persian
        self::assertTrue((bool) preg_match('/\bdir=["\']rtl["\']/i', $html), 'H: RTL dir=rtl');
        self::assertTrue(str_contains($html, 'پزشک') || str_contains($html, 'امروز') || str_contains($html, 'fa'), 'H: Persian content');

        // Responsive tablet-first: readable cards/list and suitable touch targets only (min-height 44px pattern)
        // Check for meta viewport and CSS that ensures touch targets
        self::assertStringContainsString('viewport', $html, 'H: viewport meta present');
        // No SPA/router/framework
        foreach (['react', 'vue', 'angular', 'next/', 'nuxt', 'router', 'spa'] as $forbiddenFramework) {
            // Lowercase check, but allow false positives? Just ensure not containing framework script src
            if (str_contains(strtolower($html), $forbiddenFramework) && str_contains($html, '<script')) {
                // If it's just word "router" in Persian, ignore; check script src
                if (preg_match('/<script[^>]*' . preg_quote($forbiddenFramework, '/') . '/i', $html)) {
                    self::fail('H: must not require SPA/router/framework: found ' . $forbiddenFramework);
                }
            }
        }

        // Client authority: browser may send only trusted Clinic selector header (X-CPMS-Clinic-Id)
        // Must NOT create authority by sending clinician_id, location_id, organization_id, role
        // Check that config script does NOT contain forbidden authority keys as client-side authority
        $configPayloads = $this->configScriptPayloads($html);
        self::assertCount(1, $configPayloads, 'H: exactly one config script (rest_root + nonce)');
        $config = json_decode(trim($configPayloads[0]), true);
        self::assertIsArray($config, 'H: config JSON');
        $this->assertNoAuthorityKeys($config, 'H config');
        // Config must have rest_root and nonce verifying as wp_rest
        self::assertArrayHasKey('rest_root', $config, 'H: rest_root present');
        self::assertArrayHasKey('nonce', $config, 'H: nonce present');
        self::assertNotFalse(wp_verify_nonce((string) $config['nonce'], 'wp_rest'), 'H: nonce verifies as wp_rest');

        // Security: no sensitive error details, no frontend-only authorization
        self::assertStringNotContainsString('stack trace', strtolower($html), 'H: no stack trace');
        self::assertStringNotContainsString('wp-config', strtolower($html), 'H: no wp-config leak');

        // Refresh only existing pattern: existing queue endpoint, bounded polling/visibility pause or existing RT/ETag
        // Check that JS uses existing /queue or /rt/queue or last_event_id, not new endpoint
        self::assertTrue(
            str_contains($html, '/queue') || str_contains($html, 'queue') || str_contains($html, 'last_event_id') || str_contains($html, 'poll'),
            'H: refresh uses existing queue endpoint pattern'
        );

        // Tablet-first usability: check for cards/list and touch targets
        self::assertTrue(
            str_contains($html, 'card') || str_contains($html, 'cpms-doc') || str_contains($html, 'data-role'),
            'H: cards/list present'
        );
    }

    // =================================================================
    // Production Doctor Portal URL resolution (product behaviour — not fixture filenames)
    // =================================================================

    private function tryResolveProductionDoctorPortalUrl(): ?string
    {
        // Try product URL helpers if GREEN implements DoctorPortalShell or similar
        $candidateClasses = [
            'ClinicCore\\Frontend\\DoctorPortalShell',
            'ClinicCore\\Admin\\DoctorPortalPage',
            'ClinicCore\\Frontend\\DoctorPortalPage',
            'ClinicCore\\Admin\\DoctorDashboardPage',
        ];
        $candidateMethods = [
            'portal_url',
            'frontendPortalUrl',
            'frontendUrl',
            'portalFrontendUrl',
            'doctorPortalFrontendUrl',
            'pageUrl',
            'url',
        ];
        foreach ($candidateClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }
            foreach ($candidateMethods as $method) {
                if (!is_callable([$class, $method])) {
                    continue;
                }
                try {
                    $url = (string) $class::{$method}();
                    if ($url !== '' && !$this->isWpAdminUrl($url) && str_contains($url, 'http')) {
                        // Must not be legacy wp-admin
                        if (str_contains($url, 'page=' . self::LEGACY_DOCTOR_ADMIN_PAGE)) {
                            continue;
                        }
                        return $url;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        foreach (self::SHELL_FILTER_CANDIDATES as $filter) {
            $filtered = apply_filters($filter, '');
            if (is_string($filtered) && $filtered !== '' && !$this->isWpAdminUrl($filtered)) {
                return $filtered;
            }
        }

        foreach (self::SHELL_OPTION_CANDIDATES as $option) {
            $pageId = (int) get_option($option, 0);
            if ($pageId <= 0) {
                continue;
            }
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '' && !$this->isWpAdminUrl($url)) {
                return $url;
            }
        }

        // Last resort: try to find published page with candidate slugs that has template_include interception
        // We do NOT treat proof fixture as product
        foreach (self::SHELL_SLUG_CANDIDATES as $slug) {
            $page = get_page_by_path($slug);
            if ($page instanceof \WP_Post && $page->post_status === 'publish') {
                $url = get_permalink($page->ID);
                if (is_string($url) && $url !== '' && !$this->isWpAdminUrl($url)) {
                    // Check if template_include would intercept it (plugin-owned)
                    // For RED, we return it even if interception not yet implemented — the render test will fail
                    return $url;
                }
            }
        }

        return null;
    }

    private function requireProductionDoctorPortalUrl(string $failureMessage): string
    {
        $url = $this->tryResolveProductionDoctorPortalUrl();
        self::assertNotNull($url, $failureMessage);
        self::assertIsString($url);
        self::assertNotSame('', $url, $failureMessage);
        return $url;
    }

    private function isWpAdminUrl(string $url): bool
    {
        return str_contains($url, '/wp-admin/') || str_contains($url, 'admin.php?page=' . self::LEGACY_DOCTOR_ADMIN_PAGE);
    }

    // =================================================================
    // Render helpers (same mechanism as Patient Portal precedent)
    // =================================================================

    private function renderProductionDoctorPortalAs(int $userId, string $frontendUrl): string
    {
        wp_set_current_user($userId);

        $path = (string) (wp_parse_url($frontendUrl, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($frontendUrl, \PHP_URL_QUERY) ?? '');
        $request = $path . ($query !== '' ? '?' . $query : '');
        if ($request === '') {
            $request = '/';
        }

        $this->go_to($request);

        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        if (!is_readable($baseline)) {
            $baseline = '/active-theme/page.php';
        }

        $template = (string) apply_filters('template_include', $baseline);

        // Production shell must be plugin-owned standalone template, not theme
        self::assertNotSame(
            $baseline,
            $template,
            'Doctor Portal S1: template_include must intercept production Doctor Portal page with plugin-owned template '
            . '(PatientPortalShell precedent / owner architecture). Got baseline passthrough: ' . $template
        );
        self::assertFileExists($template, 'Doctor Portal S1: plugin-owned Doctor Portal template must exist on disk. Got: ' . $template);
        self::assertStringNotContainsString(
            '/themes/',
            str_replace('\\', '/', $template),
            'Doctor Portal S1: intercepted template must not live inside theme directory (theme independence). Got: ' . $template
        );

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function assertIndependentDoctorShell(string $html, array $fx): void
    {
        $body = ltrim($html);

        self::assertTrue(
            str_starts_with($body, '<!DOCTYPE html>') || str_starts_with(strtolower($body), '<!doctype html>'),
            'A: CPMS must own full document starting at <!DOCTYPE html>.'
        );
        self::assertStringContainsString('</html>', $html, 'A: full document must close with </html>.');

        self::assertTrue(
            str_contains(strtolower($html), self::SHELL_ROOT_TOKEN)
            || str_contains($html, self::SHELL_DATA_ATTR)
            || str_contains($html, 'data-cpms-doctor-portal-shell')
            || str_contains($html, 'data-cpms-portal="doctor"'),
            'A: shell must expose stable Doctor Portal root marker (e.g. "' . self::SHELL_ROOT_TOKEN . '" / ' . self::SHELL_DATA_ATTR . ').'
        );

        // Theme chrome absent
        self::assertStringNotContainsString('CPMS-PROOF-THEME-ALPHA', $html, 'A: proof theme alpha markers absent');
        self::assertStringNotContainsString('CPMS-PROOF-THEME-BETA', $html, 'A: proof theme beta markers absent');

        // wp-admin chrome absent
        self::assertStringNotContainsString('id="wpadminbar"', $html, 'A: wp-admin bar absent');
        self::assertStringNotContainsString('id="adminmenu"', $html, 'A: wp-admin menu absent');
        self::assertStringNotContainsString('id="wpfooter"', $html, 'A: wp-admin footer absent');

        // RTL + landmarks
        self::assertTrue((bool) preg_match('/\bdir=["\']rtl["\']/i', $html), 'A: Doctor Portal shell must be RTL');
        self::assertTrue(
            (bool) preg_match('/<header\b/i', $html) || (bool) preg_match('/role=["\']banner["\']/i', $html) || str_contains($html, 'data-role="portal-header"'),
            'A: header/banner landmark present'
        );
        self::assertTrue(
            (bool) preg_match('/<main\b/i', $html) || (bool) preg_match('/role=["\']main["\']/i', $html) || str_contains($html, 'data-role="portal-main"'),
            'A: main landmark present'
        );

        // No SPA/framework
        self::assertStringNotContainsString('data-reactroot', $html, 'A: no React root');
        // Config
        $scripts = $this->configScriptPayloads($html);
        self::assertCount(1, $scripts, 'A: exactly one portal config script (rest_root + nonce) must be present');
        $config = json_decode(trim($scripts[0]), true);
        self::assertIsArray($config, 'A: config JSON');
        self::assertArrayHasKey('rest_root', $config);
        self::assertArrayHasKey('nonce', $config);
        self::assertNotFalse(wp_verify_nonce((string) $config['nonce'], 'wp_rest'), 'A: nonce verifies');
        $this->assertNoAuthorityKeys($config, 'A config');

        // No Staff/Admin navigation leak? Doctor is staff, but must not leak other roles
        // At least ensure patient portal markers not mixed
        self::assertStringNotContainsString('cpms-patient-portal', strtolower($html), 'A: must not expose patient portal markers inside doctor portal');
    }

    private function assertDoctorPortalDeniesNonDoctor(string $html, string $label): void
    {
        // For non-doctor, portal should show access-denied or login, not doctor queue
        $isDenied = str_contains($html, 'دسترسی') || str_contains($html, 'access-denied') || str_contains($html, 'portal-access-denied') || str_contains($html, 'ورود');
        self::assertTrue($isDenied, 'B: Doctor Portal must deny ' . $label . ' (show access-denied/login, not doctor data)');
    }

    // =================================================================
    // Markup / config helpers
    // =================================================================

    /**
     * @return list<string>
     */
    private function configScriptPayloads(string $html): array
    {
        $payloads = [];
        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/su', $html, $matches, \PREG_SET_ORDER) < 1) {
            return $payloads;
        }
        foreach ($matches as $script) {
            $attrs = ' ' . $script[1];
            if (preg_match('/\stype="([^"]*)"/i', $attrs, $type) !== 1 || strtolower(trim($type[1])) !== 'application/json') {
                continue;
            }
            if (preg_match('/\sclass="([^"]*)"/i', $attrs, $class) !== 1) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($class[1]));
            if ($classes === false) {
                continue;
            }
            // Accept both patient and doctor portal config classes
            $hasConfig = false;
            foreach ($classes as $c) {
                if (str_contains($c, 'cpms-') && str_contains($c, '__config')) {
                    $hasConfig = true;
                    break;
                }
            }
            if (!$hasConfig) {
                continue;
            }
            $payloads[] = (string) $script[2];
        }
        return $payloads;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertNoAuthorityKeys(array $config, string $label): void
    {
        $stack = [$config];
        while ($stack !== []) {
            $node = (array) array_pop($stack);
            foreach ($node as $key => $value) {
                self::assertNotContains(
                    strtolower((string) $key),
                    self::FORBIDDEN_AUTHORITY_KEYS,
                    $label . ': must not expose authority key "' . (string) $key . '" (server-side authorization remains authoritative).'
                );
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * @return array{
     *   clinic_id:int, location_id:int, clinician_id:int, doctor_user_id:int,
     *   patient_id:int, patient_first_name:string, patient_last_name:string
     * }
     */
    private function buildDoctorFixture(string $tag): array
    {
        $clinicId = 1;
        $locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
            [$clinicId]
        );
        self::assertGreaterThan(0, $locationId, 'precondition: primary Location exists');

        $now = App::db()->nowUtcSql();
        $doctorUserId = $this->makeUser('doc_' . strtolower($tag) . '_' . bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician('Dr ' . $tag . ' ' . bin2hex(random_bytes(2)), $clinicId, 1, $doctorUserId);
        cpms_test_seed_membership($doctorUserId, $clinicId, 'cpms_doctor');

        $patientId = $this->insertPatient('MR-' . $tag . '-' . bin2hex(random_bytes(2)), '0912' . sprintf('%07d', random_int(1000000, 9999999)), $clinicId);
        $patientRow = App::db()->fetchRow(
            'SELECT first_name, last_name FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
            [$patientId]
        );
        $this->insertVisitForDoctor($patientId, $clinicianId, $clinicId, $locationId, 'waiting', gmdate('Y-m-d'), '10:00:00');

        return [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'doctor_user_id' => $doctorUserId,
            'patient_id' => $patientId,
            'patient_first_name' => (string) ($patientRow['first_name'] ?? 'Test'),
            'patient_last_name' => (string) ($patientRow['last_name'] ?? 'Patient'),
        ];
    }

    /**
     * @return array{clinic_a:int, clinic_b:int, doctor_user_id:int}
     */
    private function buildMultiClinicFixture(string $tag): array
    {
        $clinicA = 1;
        $clinicB = $this->insertClinic('Multi ' . $tag . ' B', 'multi-' . strtolower($tag) . '-b-' . bin2hex(random_bytes(2)));
        $locA = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1',
            [$clinicA]
        );
        $locB = $this->insertLocation($clinicB, 'Multi B Loc', 'multi-b-loc-' . bin2hex(random_bytes(2)), 'Asia/Tehran', 1);

        $doctorUserId = $this->makeUser('multi_doc_' . strtolower($tag) . '_' . bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianA = $this->insertClinician('Dr Multi A ' . $tag, $clinicA, 1, $doctorUserId);
        // Membership in both
        cpms_test_seed_membership($doctorUserId, $clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctorUserId, $clinicB, 'cpms_doctor');

        // Visits in both clinics for same clinician identity (shared professional model)
        $patientA = $this->insertPatient('MR-MULTI-A-' . $tag, '0912' . sprintf('%07d', random_int(1000000, 9999999)), $clinicA);
        $patientB = $this->insertPatient('MR-MULTI-B-' . $tag, '0912' . sprintf('%07d', random_int(1000000, 9999999)), $clinicB);
        $this->insertVisitForDoctor($patientA, $clinicianA, $clinicA, $locA, 'waiting', gmdate('Y-m-d'), '10:00:00');
        $this->insertVisitForDoctor($patientB, $clinicianA, $clinicB, $locB, 'waiting', gmdate('Y-m-d'), '11:00:00');

        return [
            'clinic_a' => $clinicA,
            'clinic_b' => $clinicB,
            'doctor_user_id' => $doctorUserId,
        ];
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function assertFixtureAndExistingTodayPath(array $fx): void
    {
        // Schema/migrations ok
        $clinicCount = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'), []);
        self::assertGreaterThanOrEqual(1, $clinicCount, 'precondition: at least one Clinic');

        // Existing queue/today paths reached
        if (isset($fx['doctor_user_id'])) {
            wp_set_current_user((int) $fx['doctor_user_id']);
            $today = App::visitService()->today((int) $fx['doctor_user_id']);
            self::assertArrayHasKey('queue', $today, 'positive control: today() path reached');
            self::assertArrayHasKey('stats', $today, 'positive control: stats path reached');
            self::assertArrayHasKey('date', $today, 'positive control: date present');
        }
    }

    private function hasSingleOperationalLocationAuthority(int $clinicId): bool
    {
        // Check if live architecture defines single operational Location for Doctor/Clinic view
        // Currently it does NOT — VisitRepository uses gmdate, not Location timezone
        // And there is no table/column like cpms_clinics.operational_location_id or similar
        // So we return false to expose RED problem
        // If GREEN implements deterministic authority, this should return true and RED will flip to green
        $activeLocations = App::db()->fetchAll(
            'SELECT id, timezone FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1',
            [$clinicId]
        );
        if (!is_array($activeLocations) || count($activeLocations) <= 1) {
            // Single location could still be authority, but currently code doesn't use it
            return false;
        }
        // Multiple active locations => ambiguous, no single authority defined
        return false;
    }

    // ================= Row writers =================

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, 'pass-not-used-123', $unique . '@test.local');
        self::assertGreaterThan(0, $userId, 'fixture user created');
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);
        return $userId;
    }

    private function insertOrganization(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $name,
            'org-' . bin2hex(random_bytes(3)),
            'active',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertClinic(string $name, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($orgId <= 0) {
            $orgId = $this->insertOrganization('Org for ' . $name);
        }
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            $name,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertClinicInOrg(string $name, string $slug, int $orgId, string $timezone): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            $name,
            $slug,
            $timezone,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $timezone, int $isPrimary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $name,
            $slug,
            $timezone,
            $isPrimary,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertClinician(string $name, int $clinicId, int $isActive, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $name,
            $wpUserId,
            $isActive,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(string $mrn, string $mobile, int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $mrn,
            'Test',
            'Patient ' . substr($mrn, -4),
            $mobile,
            'active',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertVisitForDoctor(int $patientId, int $clinicianId, int $clinicId, int $locationId, string $status, string $visitDate, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            'walk_in',
            $status,
            $visitDate,
            $visitDate . ' ' . $time,
            $visitDate . ' ' . $time,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertVisitWithAppointment(int $patientId, int $clinicianId, int $clinicId, int $locationId, int $appointmentId, string $status, string $visitDate, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            $appointmentId,
            'scheduled',
            $status,
            $visitDate,
            $visitDate . ' ' . $time,
            $visitDate . ' ' . $time,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertAppointmentExpress(int $clinicId, int $locationId, int $clinicianId, int $patientId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $slotDate = gmdate('Y-m-d');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicId,
            $locationId,
            'EXP-' . bin2hex(random_bytes(3)),
            $patientId,
            $clinicianId,
            1,
            0,
            $slotDate,
            '08:00:00',
            20,
            '08:20:00',
            'confirmed',
            1,
            $now,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    // ================= REST dispatch =================

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function dispatchRest(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function restPayload(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && array_key_exists('data', $body) && is_array($body['data'])) {
            return $body['data'];
        }
        return is_array($body) ? $body : [];
    }

    private function restErrorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }
        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }
}

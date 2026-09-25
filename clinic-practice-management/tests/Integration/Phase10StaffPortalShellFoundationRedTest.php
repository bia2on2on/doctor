<?php
/**
 * Phase 10 — Shared Staff Portal Shell Foundation (TEST-ONLY RED).
 *
 * ============================================================================
 * OWNER-APPROVED ARCHITECTURE (this slice only encodes; it never implements)
 * ============================================================================
 *
 * CPMS has THREE primary user environments:
 *   A. Patient Portal                       — stays independent, untouched.
 *   B. WordPress Admin / CPMS management    — stays in wp-admin, untouched.
 *   C. ONE shared operational Staff Portal  — the NEW canonical container.
 *
 * A shared portal does NOT mean shared permissions: server-side authorization
 * remains mandatory for every operation.
 *
 * Owner-approved URL decision encoded here:
 *   - NEW canonical Staff Portal page/shell  → `cpms-staff-portal`
 *     (smallest clear slug consistent with the approved "Staff Portal" concept;
 *      the same naming convention as `cpms-patient-portal` / `cpms-doctor-portal`).
 *   - LEGACY `cpms-doctor-portal` MUST remain a working doctor entry/alias.
 *     Existing bookmarks and established browser journeys must not break.
 *     This RED does NOT require one implementation choice: the legacy entry may
 *     either redirect safely to the canonical Staff Portal doctor landing OR
 *     render the same shared shell. Both satisfy the product contract, so the
 *     in-process assertions below only require that the canonical container
 *     exists (which is what makes legacy compatibility expressible at all) and
 *     that the legacy entry keeps working today.
 *
 * Owner-approved multi-role decision encoded here:
 *   - NO role switcher. NO "strongest role" algorithm.
 *   - Navigation is capability/policy driven.
 *   - Only the already-delivered doctor operational module is mounted in this
 *     slice. Secretary / accountant modules remain future Phase 11 work.
 *   - This slice does NOT invent a landing priority for future modules.
 *
 * ============================================================================
 * LIVE STATE AT RED AUTHORING (independently fetched, 2026-09-25 — VERIFIED)
 * ============================================================================
 *
 * - authoritative main = 705d14c041bd2ba9d7df5459c58525f2dc6d5690
 *   (== PR #123 merge SHA; PR #123 MERGED / POST-MERGE PASS: all 19 push-to-main
 *    checks concluded `success` at head_sha 705d14c041bd)
 * - open PRs = 0 (no active Staff Portal foundation PR exists)
 * - working tree = clean (tracked + untracked: none)
 * - latest migration on disk = 2026_09_20_0022_slot_holds_patient_binding.php
 *   (no 0023 exists; none is created, reserved or referenced by this RED)
 * - Phase 10 = IN PROGRESS; Doctor Portal slices 1..5 CLOSED (merged on main)
 * - live production shells on main:
 *     ClinicCore\Frontend\PatientPortalShell  PAGE_SLUG = cpms-patient-portal
 *     ClinicCore\Frontend\DoctorPortalShell   PAGE_SLUG = cpms-doctor-portal
 *   → NO Staff Portal shell / page / template / option / filter exists on main.
 *
 * ============================================================================
 * TEST GROUP MAP (max 8 groups — approved invariant set)
 * ============================================================================
 *
 *  G1  STAFF PORTAL SHELL EXISTENCE .......................... INTENDED RED
 *  G2  ACCESS ELIGIBILITY .................................... INTENDED RED
 *  G3  CAPABILITY-DRIVEN MODULE NAVIGATION ................... INTENDED RED
 *  G4  MULTI-CLINIC ISOLATION ................................ GREEN CONTROL
 *  G5  DOCTOR LOCATION / CLINICIAN CONTRACT PRESERVED ......... GREEN CONTROL
 *  G6  LEGACY DOCTOR URL COMPATIBILITY ....................... GREEN + RED
 *  G7  SERVER-SIDE AUTHORIZATION PRESERVED ................... GREEN CONTROL
 *  G8  REAL BROWSER FOUNDATION (Plain + Pretty) .............. GREEN + RED
 *
 * The three RED anchors (G1/G2/G3) all fail for the SAME root cause — the
 * missing shared Staff Portal container/entry — but they assert materially
 * different contracts, so a GREEN that merely creates an empty page cannot
 * satisfy them. G6/G8 add two further anchors (legacy compatibility target,
 * Plain+Pretty resolution) that are also unprovable until the container exists.
 *
 * G4/G5/G7 are MATERIAL CONTROLS that are already GREEN on live main and MUST
 * stay GREEN: they are the regression evidence that the existing Doctor Portal
 * clinical behaviour, clinician identity, Location policy, multi-Clinic
 * isolation and server-side REST authorization are unchanged by this slice.
 *
 * ============================================================================
 * WHAT THIS RED DELIBERATELY DOES NOT PRESCRIBE
 * ============================================================================
 *
 * - No class hierarchy / abstract framework is required. GREEN may add a
 *   `StaffPortalShell` class, extend an existing pattern, or reuse an existing
 *   shell — the RED only requires greppable product markers (see
 *   STAFF_SHELL_ROOT_PATTERNS and MODULE_ATTRIBUTES below).
 * - No new WP role, no new capability, no `STAFF_PORTAL_ACCESS`:
 *   shell/module eligibility must be derivable from EXISTING operational
 *   capabilities + active Clinic membership (asserted in G3).
 * - No migration/schema, no new REST namespace, no second backend, no
 *   SPA/router/build system, no wp-admin replacement.
 * - No secretary / accountant / receptionist / cashier / finance product
 *   module, and no fake pages for them: G3 only proves that such placeholders
 *   are NOT shown.
 *
 * Test-only: no product PHP/template/CSS/JS, no migration (latest remains
 * 2026_09_20_0022), no workflow change, no docs closure.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10StaffPortalShellFoundationRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';

    /** Owner-approved canonical Staff Portal slug (smallest clear concept name). */
    private const CANONICAL_SLUG = 'cpms-staff-portal';

    /** Legacy Doctor Portal slug — must remain a working doctor entry/alias. */
    private const LEGACY_DOCTOR_SLUG = 'cpms-doctor-portal';

    /** Existing portal record route reused by G5 (merged Phase 10 Slice 3). */
    private const PORTAL_RECORD = 'clinic/v1/doctor/portal/visits/%d/record';

    /** Existing portal context route (merged Phase 10 Slice 1). */
    private const PORTAL_CONTEXT = 'clinic/v1/doctor/portal/context';

    /** Existing portal locations route (merged Phase 10 Slice 1). */
    private const PORTAL_LOCATIONS = 'clinic/v1/doctor/portal/locations';

    private const TZ_TEHRAN = 'Asia/Tehran';

    /** Deterministic clock — same convention as every merged Phase 10 portal test. */
    private const FIXED_UTC = '2026-03-14 10:00:00';

    private const FIXED_UTC_DATE = '2026-03-14';

    /**
     * Operational capabilities the ALREADY-DELIVERED doctor clinical module
     * requires. Deliberately EXISTING capabilities — this slice introduces no
     * new capability merely to make the shared shell exist.
     *
     * @var list<string>
     */
    private const DOCTOR_MODULE_CAPS = [
        RolesAndCapabilities::QUEUE_READ,
        RolesAndCapabilities::MEDICAL_READ,
    ];

    /**
     * Capabilities that future secretary/accountant modules would need. Used
     * only to prove the CURRENT slice shows no placeholder for them.
     *
     * @var list<string>
     */
    private const FUTURE_MODULE_IDS = ['secretary', 'accountant', 'receptionist', 'cashier', 'finance'];

    /**
     * Accepted greppable markers for the shared Staff Portal shell ROOT.
     * GREEN picks one; the RED only requires that a staff-portal-scoped shell
     * root is present and that it is NOT the doctor-specific shell root.
     *
     * @var list<string>
     */
    private const STAFF_SHELL_ROOT_PATTERNS = [
        'data-cpms-staff-portal-shell',
        'data-cpms-staff-shell',
        'cpms-staff-portal-shell',
        'cpms-staff-shell',
        'data-cpms-portal="staff"',
    ];

    /**
     * Accepted attribute names carrying an operational MODULE identity.
     * `data-role` is deliberately excluded: the existing doctor template uses
     * `data-role="doctor-context"` for a content region, which is NOT a module
     * entry, and including it would create a false GREEN.
     *
     * @var list<string>
     */
    private const MODULE_ATTRIBUTES = [
        'data-cpms-staff-module',
        'data-cpms-staff-portal-module',
        'data-cpms-module',
        'data-staff-module',
        'data-module',
    ];

    /** wp-admin CHROME markers (not the literal string "wp-admin": a legitimate
     *  "back to dashboard" link may contain it; chrome may not). */
    private const WP_ADMIN_CHROME = ['wpadminbar', 'adminmenu', 'wpfooter', 'wp-toolbar', 'id="wpwrap"'];

    /** Forbidden role-switcher / strongest-role markers (§14). */
    private const ROLE_SWITCH_TOKENS = [
        'role-switcher',
        'role_switcher',
        'switch-role',
        'switch_role',
        'staff-role-switch',
        'active-role-select',
        'strongest-role',
    ];

    /** Control-theme markers (PR #98 fixtures) — positive control for theme independence. */
    private const CONTROL_THEMES = [
        'cpms-proof-theme-alpha' => 'CPMS-PROOF-THEME-ALPHA-HEADER',
        'cpms-proof-theme-beta'  => 'CPMS-PROOF-THEME-BETA-HEADER',
    ];

    private ?string $originalPermalink = null;

    private bool $permalinkChanged = false;

    private string $originalTheme = '';

    /** @var null|(callable(): \DateTimeImmutable) */
    private $clockFilter = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        // Bootstrap / schema evidence: migrations run to completion before any
        // fixture row is written (a failure here is D-class, never product RED).
        App::migrations()->migrate();
        do_action('rest_api_init');

        $fixed            = new \DateTimeImmutable(self::FIXED_UTC, new \DateTimeZone('UTC'));
        VisitService::setTestNowUtc($fixed);
        $this->clockFilter = static function () use ($fixed): \DateTimeImmutable {
            return $fixed;
        };
        add_filter('cpms_visit_now_utc', $this->clockFilter);

        $this->originalPermalink = (string) get_option('permalink_structure');
        $this->permalinkChanged  = false;
        $this->originalTheme     = (string) get_stylesheet();
    }

    protected function tearDown(): void
    {
        if ($this->permalinkChanged && $this->originalPermalink !== null) {
            $this->set_permalink_structure($this->originalPermalink);
            $this->permalinkChanged = false;
        }
        if ($this->originalTheme !== '' && (string) get_stylesheet() !== $this->originalTheme) {
            switch_theme($this->originalTheme);
        }
        if ($this->clockFilter !== null) {
            remove_filter('cpms_visit_now_utc', $this->clockFilter);
            $this->clockFilter = null;
        }
        VisitService::setTestNowUtc(null);
        wp_set_current_user(0);
        $_GET                      = [];
        $_POST                     = [];
        $_REQUEST                  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // =================================================================
    // G1 — STAFF PORTAL SHELL EXISTENCE (INTENDED PRODUCT RED)
    // =================================================================

    public function testGroup1_StaffPortalShellExistence(): void
    {
        $fx = $this->buildStaffStage('g1');
        $this->assertStaffFixture($fx);

        // ---- GREEN CONTROL: the already-delivered Doctor Portal still renders today. ----
        $legacyHtml = $this->renderLegacyDoctorPortalAs((int) $fx['doctor']);
        self::assertStringContainsString(
            'cpms-doctor-portal-shell',
            $legacyHtml,
            'G1 control: existing Doctor Portal shell still renders (zero-regression baseline).'
        );

        // ---- INTENDED RED: the canonical shared Staff Portal container does not exist. ----
        $url = $this->requireStaffPortalUrl(
            'G1: a canonical shared Staff Portal WordPress entry must exist (CPMS-owned published Page "'
            . self::CANONICAL_SLUG . '" + plugin-owned template_include interception + plugin-owned '
            . 'standalone template). On live main only the doctor-specific cpms-doctor-portal and the '
            . 'patient-specific cpms-patient-portal exist; no shared operational Staff Portal container exists.'
        );

        self::assertStringNotContainsString('/wp-admin/', $url, 'G1: Staff Portal must not live under /wp-admin/.');
        self::assertNotSame(
            $this->normalizeUrl(DoctorPortalShell::portal_url()),
            $this->normalizeUrl($url),
            'G1: the canonical Staff Portal must be a NEW canonical URL, not a rename of the legacy doctor URL.'
        );

        // Theme independence: the intercepted template is plugin-owned, never a theme file.
        $this->installProofControlThemes();

        foreach (self::CONTROL_THEMES as $theme => $marker) {
            switch_theme($theme);
            self::assertSame($theme, (string) get_stylesheet(), 'G1 positive control: switched to control theme ' . $theme);

            $headerFile = get_theme_root() . '/' . $theme . '/header.php';
            self::assertFileExists($headerFile, 'G1 positive control: control theme header exists.');
            ob_start();
            include $headerFile;
            $headerHtml = (string) ob_get_clean();
            self::assertStringContainsString(
                $marker,
                $headerHtml,
                'G1 positive control: control theme ' . $theme . ' emits its marker when rendered alone '
                . '(so its absence in the portal document is real evidence, not a vacuous assertion).'
            );

            $html = $this->renderCanonicalAs((int) $fx['doctor'], $url);

            self::assertStringNotContainsString(
                $marker,
                $html,
                'G1: the Staff Portal document must not render the active Theme header (theme independence).'
            );
            $this->assertStaffShellRoot($html, 'G1');
            $this->assertNoWpAdminChrome($html, 'G1');
            $this->assertSessionAndRestNonce($html, 'G1');
            self::assertFalse(
                $this->adminBarVisibleOn((int) $fx['doctor'], $url),
                'G1: WordPress admin bar must be suppressed on the operational Staff Portal surface.'
            );
            $this->assertLogoutSurface($html, 'G1');
        }
    }

    // =================================================================
    // G2 — ACCESS ELIGIBILITY (INTENDED PRODUCT RED)
    // =================================================================

    public function testGroup2_AccessEligibility(): void
    {
        $fx = $this->buildStaffStage('g2');
        $this->assertStaffFixture($fx);

        $secretaryId  = (int) $fx['secretary'];
        $accountantId = (int) $fx['accountant'];
        $patientId    = (int) $fx['patient'];

        // ---- GREEN CONTROLS: the existing authorization truth already fails closed today. ----
        $auth = App::authorization_service();
        self::assertTrue(
            $auth->can((int) $fx['doctor'], (int) $fx['clinic'], RolesAndCapabilities::QUEUE_READ),
            'G2 control: doctor with ACTIVE membership keeps operational capability.'
        );
        self::assertFalse(
            $auth->can($patientId, (int) $fx['clinic'], RolesAndCapabilities::QUEUE_READ),
            'G2 control: patient-only user has no operational capability (P-5 ownership only).'
        );
        self::assertFalse(
            $auth->can((int) $fx['noMembershipDoctor'], (int) $fx['clinic'], RolesAndCapabilities::QUEUE_READ),
            'G2 control: WP doctor role with NO active membership fails closed (role is not tenant authority).'
        );
        self::assertFalse(
            $auth->can((int) $fx['suspendedDoctor'], (int) $fx['clinic'], RolesAndCapabilities::QUEUE_READ),
            'G2 control: SUSPENDED membership fails closed.'
        );
        self::assertFalse(
            $auth->can((int) $fx['administrator'], (int) $fx['clinic'], RolesAndCapabilities::MEDICAL_READ),
            'G2 control: WordPress administrator does NOT receive clinical authorization by being administrator.'
        );
        self::assertFalse(
            DoctorPortalShell::isDoctorUser($this->wpUser($patientId)),
            'G2 control: patient-only user is not a doctor user.'
        );
        self::assertFalse(
            DoctorPortalShell::isDoctorUser($this->wpUser((int) $fx['administrator'])),
            'G2 control: administrator is not a doctor user.'
        );
        self::assertTrue(
            DoctorPortalShell::isDoctorUser($this->wpUser((int) $fx['doctor'])),
            'G2 control: the fixture doctor IS a doctor user (server-derived clinician row present).'
        );

        // ---- INTENDED RED: shell-level operational eligibility cannot be expressed yet. ----
        $url = $this->requireStaffPortalUrl(
            'G2: operational Staff Portal access eligibility must be enforced at the shared shell '
            . '(authenticated + ACTIVE Clinic membership for operational Clinic data; patient-only, '
            . 'no-membership and suspended users fail closed; WP administrator gains no clinical module). '
            . 'None of this is expressible because the shared Staff Portal container does not exist yet.'
        );

        $doctorHtml = $this->renderCanonicalAs((int) $fx['doctor'], $url);
        $this->assertStaffShellRoot($doctorHtml, 'G2/doctor');

        $denied = [
            'anonymous'           => 0,
            'patient-only'        => $patientId,
            'no-membership'       => (int) $fx['noMembershipDoctor'],
            'suspended-membership' => (int) $fx['suspendedDoctor'],
            'wp-administrator'    => (int) $fx['administrator'],
        ];

        foreach ($denied as $label => $userId) {
            $html = $this->renderCanonicalAs($userId, $url);
            self::assertFalse(
                $this->hasModuleEntry($html, 'doctor'),
                'G2: ' . $label . ' must NOT receive the doctor operational module entry in the shared Staff Portal.'
            );
        }
    }

    // =================================================================
    // G3 — CAPABILITY-DRIVEN MODULE NAVIGATION (INTENDED PRODUCT RED)
    // =================================================================

    public function testGroup3_CapabilityDrivenModuleNavigation(): void
    {
        $fx = $this->buildStaffStage('g3');
        $this->assertStaffFixture($fx);

        $clinicId = (int) $fx['clinic'];
        $auth     = App::authorization_service();

        // ---- GREEN CONTROL: module eligibility is derivable from EXISTING capabilities
        //      (no new capability, no role switcher, no strongest-role algorithm). ----
        foreach (self::DOCTOR_MODULE_CAPS as $cap) {
            self::assertTrue(
                $auth->can((int) $fx['doctor'], $clinicId, $cap),
                'G3 control: doctor module eligibility derives from the EXISTING capability ' . $cap
                . ' (no new capability is introduced by this slice).'
            );
            self::assertFalse(
                $auth->can((int) $fx['accountant'], $clinicId, $cap),
                'G3 control: accountant does NOT hold the doctor-module capability ' . $cap . '.'
            );
        }
        self::assertFalse(
            $auth->can((int) $fx['secretary'], $clinicId, RolesAndCapabilities::MEDICAL_READ),
            'G3 control: secretary does NOT hold the doctor-only clinical capability.'
        );

        // ---- INTENDED RED: the shared shell must expose capability-filtered module navigation. ----
        $url = $this->requireStaffPortalUrl(
            'G3: the shared Staff Portal shell must render capability/policy-driven module navigation — '
            . 'the already-delivered doctor module visible ONLY to currently authorized operational users, '
            . 'hidden from secretary/accountant/patient-only, with NO future module placeholders and NO role '
            . 'switcher. No shell, therefore no navigation model, exists on live main.'
        );

        $doctorHtml = $this->renderCanonicalAs((int) $fx['doctor'], $url);
        self::assertTrue(
            $this->hasModuleEntry($doctorHtml, 'doctor'),
            'G3: the currently authorized doctor MUST see the doctor operational module entry in the shared shell.'
        );

        foreach (['secretary' => (int) $fx['secretary'], 'accountant' => (int) $fx['accountant'], 'patient-only' => (int) $fx['patient']] as $label => $userId) {
            $html = $this->renderCanonicalAs($userId, $url);
            self::assertFalse(
                $this->hasModuleEntry($html, 'doctor'),
                'G3: ' . $label . ' must NOT see the doctor-only clinical module entry.'
            );
        }

        // No future module placeholders (§13/§17 — no fake secretary/accountant pages).
        $entries = $this->moduleEntries($doctorHtml);
        foreach (self::FUTURE_MODULE_IDS as $future) {
            self::assertFalse(
                $this->hasModuleEntry($doctorHtml, $future),
                'G3: future module placeholder "' . $future . '" must NOT be shown in this slice. Seen: '
                . implode(',', $entries)
            );
        }

        // A menu item never grants authority: no role switcher / strongest-role surface (§14).
        foreach (self::ROLE_SWITCH_TOKENS as $token) {
            self::assertStringNotContainsString(
                $token,
                $doctorHtml,
                'G3: no role switcher / strongest-role surface may exist (' . $token . ').'
            );
        }
    }

    // =================================================================
    // G4 — MULTI-CLINIC ISOLATION (GREEN CONTROL — must stay GREEN)
    // =================================================================

    public function testGroup4_MultiClinicIsolation(): void
    {
        $fx = $this->buildStaffStage('g4');
        $this->assertStaffFixture($fx);

        $doctorId = (int) $fx['doctor'];
        $clinicA  = (int) $fx['clinic'];
        $clinicB  = (int) $fx['secondClinic'];
        $clinicC  = (int) $fx['foreignClinic'];

        self::assertNotSame($clinicA, $clinicB, 'G4 fixture: the two doctor Clinics are distinct.');
        self::assertNotSame($clinicA, $clinicC, 'G4 fixture: the foreign Clinic is distinct.');

        // Fixture persistence proof — memberships really landed with exact status/role_key.
        $rowA = App::membership_service()->membership_for($clinicA, $doctorId);
        self::assertIsArray($rowA, 'G4 fixture: Clinic A membership persisted.');
        self::assertSame('active', (string) $rowA['status'], 'G4 fixture: Clinic A membership status.');
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) $rowA['role_key'], 'G4 fixture: Clinic A role_key.');

        $rowB = App::membership_service()->membership_for($clinicB, $doctorId);
        self::assertIsArray($rowB, 'G4 fixture: Clinic B membership persisted.');
        self::assertSame('active', (string) $rowB['status'], 'G4 fixture: Clinic B membership status.');
        self::assertSame(RolesAndCapabilities::ROLE_ACCOUNTANT, (string) $rowB['role_key'], 'G4 fixture: Clinic B role_key.');

        $rowC = App::membership_service()->membership_for($clinicC, $doctorId);
        self::assertIsArray($rowC, 'G4 fixture: Clinic C membership persisted.');
        self::assertSame('suspended', (string) $rowC['status'], 'G4 fixture: Clinic C membership is SUSPENDED.');

        $active = App::membership_service()->active_clinic_ids_for_user($doctorId);
        sort($active);
        $expected = [$clinicA, $clinicB];
        sort($expected);
        self::assertSame($expected, array_map('intval', $active), 'G4: suspended Clinic C is not an active Clinic.');

        $auth = App::authorization_service();

        // Same actor, different Clinic → different capability policy.
        self::assertTrue($auth->can($doctorId, $clinicA, RolesAndCapabilities::MEDICAL_READ), 'G4: doctor in Clinic A.');
        self::assertFalse($auth->can($doctorId, $clinicB, RolesAndCapabilities::MEDICAL_READ), 'G4: accountant-role in Clinic B has no clinical read.');
        self::assertTrue($auth->can($doctorId, $clinicB, RolesAndCapabilities::FINANCE_READ), 'G4: Clinic B membership does grant its own preset.');

        // No cross-Clinic capability bleed.
        self::assertFalse($auth->can($doctorId, $clinicC, RolesAndCapabilities::MEDICAL_READ), 'G4: suspended Clinic denies.');
        self::assertFalse($auth->can($doctorId, (int) $fx['unrelatedClinic'], RolesAndCapabilities::QUEUE_READ), 'G4: foreign Clinic with no membership denies.');

        // Per-membership capability override stays Clinic-scoped (explicit deny > grant > preset).
        $membershipA = App::membership_service()->membership_for($clinicA, $doctorId);
        self::assertIsArray($membershipA);
        App::membership_service()->set_capability((int) $membershipA['id'], RolesAndCapabilities::RX_CREATE, 'deny');
        $overrides = App::membership_service()->capabilities_for((int) $membershipA['id']);
        $denyPersisted = false;
        foreach ($overrides as $override) {
            if ((string) $override['capability'] === RolesAndCapabilities::RX_CREATE && (string) $override['effect'] === 'deny') {
                $denyPersisted = true;
            }
        }
        self::assertTrue($denyPersisted, 'G4 fixture: explicit deny override really persisted.');
        self::assertFalse($auth->can($doctorId, $clinicA, RolesAndCapabilities::RX_CREATE), 'G4: explicit deny beats the role preset.');
        self::assertTrue($auth->can($doctorId, $clinicA, RolesAndCapabilities::MEDICAL_READ), 'G4: the deny is scoped to one capability only.');

        // N>1 active Clinics ⇒ explicit trusted Clinic selection REQUIRED (no first-Clinic fallback).
        $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
        try {
            $establisher->establish($doctorId, null);
            self::fail('G4: N>1 active Clinics must require explicit trusted Clinic selection (no implicit global Clinic).');
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->apiCode(), 'G4: N>1 active Clinics fails closed with the established code.');
        }

        // 0 active Clinics ⇒ fail closed.
        try {
            $establisher->establish((int) $fx['patient'], null);
            self::fail('G4: 0 active Clinics must fail closed.');
        } catch (ScopeRequiredException $e) {
            self::assertSame(
                'no_membership',
                (string) ($e->getData()['reason'] ?? ''),
                'G4: 0 active Clinics fails closed with reason=no_membership.'
            );
        }

        // Foreign / suspended Clinic selector denied.
        try {
            $establisher->establish($doctorId, $clinicC);
            self::fail('G4: suspended Clinic selector must be denied.');
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $e->apiCode(), 'G4: suspended Clinic selector denied.');
        }
        try {
            $establisher->establish($doctorId, (int) $fx['unrelatedClinic']);
            self::fail('G4: foreign Clinic selector must be denied.');
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $e->apiCode(), 'G4: foreign Clinic selector denied.');
        }

        // 1 active Clinic ⇒ safe auto-resolution is allowed.
        $single = $this->makeUser('g4_single', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($single, $clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $scope = $establisher->establish($single, null);
        self::assertSame($clinicA, $scope->clinicId, 'G4: 1 active Clinic auto-resolves to that Clinic.');
    }

    // =================================================================
    // G5 — DOCTOR LOCATION / CLINICIAN CONTRACT PRESERVED (GREEN CONTROL)
    // =================================================================

    public function testGroup5_DoctorLocationAndClinicianContractPreserved(): void
    {
        $fx = $this->buildStaffStage('g5');
        $this->assertStaffFixture($fx);

        $clinicId   = (int) $fx['clinic'];
        $locationId = (int) $fx['location'];
        $doctorId   = (int) $fx['doctor'];

        // ---- Zero regression: clinician identity exists ONLY for the doctor fixture. ----
        $db = App::db();
        foreach (['secretary', 'accountant', 'patient', 'administrator'] as $label) {
            $count = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1',
                [ (int) $fx[$label] ]
            );
            self::assertSame(
                0,
                $count,
                'G5 fixture: ' . $label . ' must NOT accidentally receive an active clinician row '
                . '(future non-doctor modules must not gain clinician identity).'
            );
        }
        self::assertSame(
            1,
            (int) $db->fetchValue(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1',
                [$doctorId]
            ),
            'G5 fixture: exactly one active clinician row for the doctor fixture.'
        );

        // ---- 1 eligible Location ⇒ auto-resolution allowed; timezone is the trusted Location's. ----
        wp_set_current_user($doctorId);
        $locations = $this->dispatch('GET', '/' . self::PORTAL_LOCATIONS, ['clinic_id' => $clinicId]);
        self::assertSame(200, $locations->get_status(), 'G5: doctor locations route reachable.');
        $rows = $this->payloadOf($locations)['locations'] ?? [];
        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'G5: exactly one eligible Location in the fixture Clinic.');
        self::assertSame($locationId, (int) $rows[0]['id'], 'G5: eligible Location identity.');
        self::assertSame(self::TZ_TEHRAN, (string) $rows[0]['timezone'], 'G5: trusted Location remains the operational timezone authority.');

        $patientId = $this->insertPatient($clinicId, 'g5');
        $visitId   = $this->insertVisit($patientId, (int) $fx['clinician'], $clinicId, $locationId, 'in_consultation');

        $single = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $this->scopeHeaders($clinicId, null));
        self::assertSame(200, $single->get_status(), 'G5: 1 eligible Location ⇒ auto-resolution allowed (existing doctor contract).');

        // ---- N>1 eligible Locations ⇒ explicit trusted Location REQUIRED (never guess the first). ----
        $secondLocation = $this->insertLocation($clinicId, 'G5 Second Loc', self::TZ_TEHRAN, 0);
        self::assertGreaterThan(0, $secondLocation, 'G5 fixture: second Location persisted.');

        wp_set_current_user($doctorId);
        $noLocation = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $this->scopeHeaders($clinicId, null));
        self::assertSame(400, $noLocation->get_status(), 'G5: N>1 eligible Locations requires an explicit trusted Location.');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($noLocation), 'G5: location-required code preserved.');
        $raw = $this->rawErrorData($noLocation);
        self::assertSame('location_id', $raw['field'] ?? null, 'G5: field=location_id preserved.');
        self::assertSame('location_required', $raw['reason'] ?? null, 'G5: reason=location_required preserved.');

        $withLocation = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $this->scopeHeaders($clinicId, $locationId));
        self::assertSame(200, $withLocation->get_status(), 'G5: explicit trusted Location authorizes.');

        // ---- Foreign Location ⇒ fail closed (Location is NOT Clinic identity). ----
        $foreignLocation = $this->insertLocation((int) $fx['foreignClinic'], 'G5 Foreign Loc', self::TZ_TEHRAN, 1);
        wp_set_current_user($doctorId);
        $foreign = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $this->scopeHeaders($clinicId, $foreignLocation));
        self::assertSame(403, $foreign->get_status(), 'G5: foreign explicit Location fails closed.');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($foreign), 'G5: established foreign-Location denial code.');

        // ---- Inactive Location ⇒ fail closed. ----
        $inactiveLocation = $this->insertLocation($clinicId, 'G5 Inactive Loc', self::TZ_TEHRAN, 0);
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE id = %d', $inactiveLocation));
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT is_active FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', [$inactiveLocation]),
            'G5 fixture: inactive Location really persisted inactive.'
        );
        wp_set_current_user($doctorId);
        $inactive = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $this->scopeHeaders($clinicId, $inactiveLocation));
        self::assertSame(403, $inactive->get_status(), 'G5: inactive explicit Location fails closed.');

        // ---- 0 eligible Locations ⇒ fail closed. ----
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE clinic_id = %d', $clinicId));
        self::assertSame(
            0,
            (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1', [$clinicId]),
            'G5 fixture: zero eligible Locations really persisted.'
        );
        wp_set_current_user($doctorId);
        $zero = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $this->scopeHeaders($clinicId, null));
        self::assertSame(403, $zero->get_status(), 'G5: 0 eligible Locations fails closed.');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($zero), 'G5: established zero-Location denial code.');
    }

    // =================================================================
    // G6 — LEGACY DOCTOR URL COMPATIBILITY (GREEN CONTROLS + RED ANCHOR)
    // =================================================================

    public function testGroup6_LegacyDoctorUrlCompatibility(): void
    {
        $fx = $this->buildStaffStage('g6');
        $this->assertStaffFixture($fx);

        $doctorId = (int) $fx['doctor'];

        // ---- GREEN CONTROL: the legacy Doctor Portal URL still works today. ----
        $legacyUrl = DoctorPortalShell::portal_url();
        self::assertNotSame('', $legacyUrl, 'G6 control: legacy Doctor Portal URL resolves.');
        self::assertStringNotContainsString('/wp-admin/', $legacyUrl, 'G6 control: legacy Doctor Portal is a frontend entry.');
        self::assertSame(
            self::LEGACY_DOCTOR_SLUG,
            DoctorPortalShell::PAGE_SLUG,
            'G6 control: legacy Doctor Portal slug contract unchanged (no silent URL migration).'
        );

        $legacyPage = get_page_by_path(self::LEGACY_DOCTOR_SLUG);
        self::assertNotNull($legacyPage, 'G6 control: legacy Doctor Portal Page still exists (must never be deleted).');
        self::assertSame('publish', (string) $legacyPage->post_status, 'G6 control: legacy Doctor Portal Page remains published.');

        $legacyHtml = $this->renderLegacyDoctorPortalAs($doctorId);
        self::assertStringContainsString('cpms-doctor-portal-shell', $legacyHtml, 'G6 control: legacy doctor journey still renders the doctor shell.');
        self::assertStringContainsString('data-role="today-section"', $legacyHtml, 'G6 control: Today section preserved.');
        self::assertStringContainsString('data-role="queue-section"', $legacyHtml, 'G6 control: Live Queue section preserved.');
        self::assertStringContainsString('data-role="workspace-section"', $legacyHtml, 'G6 control: Visit Workspace preserved.');
        self::assertStringContainsString('dir="rtl"', $legacyHtml, 'G6 control: legacy portal remains RTL.');
        $this->assertSessionAndRestNonce($legacyHtml, 'G6 control');

        // No redirect loop on the legacy entry: it renders a real document for the doctor.
        self::assertStringContainsString('<!DOCTYPE html>', $legacyHtml, 'G6 control: legacy entry delivers a document (no redirect loop, no empty response).');

        // ---- RED ANCHOR: legacy→canonical compatibility needs the canonical container. ----
        $canonical = $this->tryResolveStaffPortalUrl();
        self::assertNotNull(
            $canonical,
            'G6: existing Doctor Portal bookmarks must keep working while a NEW canonical Staff Portal URL is '
            . 'introduced as an alias/entry. Compatibility (safe single-hop redirect to the canonical doctor '
            . 'landing OR the same shared shell, with auth/session/Clinic/Location context preserved) can only '
            . 'be expressed once the canonical shared Staff Portal container exists — it does not exist on live main.'
        );

        // Guard that must also hold AFTER the canonical exists: the legacy Page is never deleted.
        $legacyPageAfter = get_page_by_path(self::LEGACY_DOCTOR_SLUG);
        self::assertNotNull($legacyPageAfter, 'G6 guard: the legacy Doctor Portal Page must not be deleted by the canonical introduction.');
        self::assertSame('publish', (string) $legacyPageAfter->post_status, 'G6 guard: the legacy Doctor Portal Page must stay published.');
    }

    // =================================================================
    // G7 — SERVER-SIDE AUTHORIZATION PRESERVED (GREEN CONTROL)
    // =================================================================

    public function testGroup7_ServerSideAuthorizationPreserved(): void
    {
        $fx = $this->buildStaffStage('g7');
        $this->assertStaffFixture($fx);

        $clinicId   = (int) $fx['clinic'];
        $locationId = (int) $fx['location'];
        $headers    = $this->scopeHeaders($clinicId, $locationId);

        // Hiding a module in the shell is never a substitute for REST authorization.
        $actors = [
            'secretary'    => (int) $fx['secretary'],
            'accountant'   => (int) $fx['accountant'],
            'patient-only' => (int) $fx['patient'],
            'administrator' => (int) $fx['administrator'],
            'no-membership-doctor' => (int) $fx['noMembershipDoctor'],
            'suspended-doctor'     => (int) $fx['suspendedDoctor'],
        ];

        foreach ($actors as $label => $userId) {
            wp_set_current_user($userId);
            $context = $this->dispatch('GET', '/' . self::PORTAL_CONTEXT, [], $headers);
            self::assertSame(
                403,
                $context->get_status(),
                'G7: ' . $label . ' must still be denied at the doctor portal context endpoint (server-side).'
            );

            $patientId = $this->insertPatient($clinicId, 'g7');
            $visitId   = $this->insertVisit($patientId, (int) $fx['clinician'], $clinicId, $locationId, 'in_consultation');

            wp_set_current_user($userId);
            $record = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $headers);
            self::assertTrue(
                in_array($record->get_status(), [401, 403], true),
                'G7: ' . $label . ' must NOT read the doctor Visit Workspace through REST '
                . '(UI navigation never substitutes for authorization). Got: ' . $record->get_status()
            );
        }

        // Positive control: the authorized doctor is still allowed.
        $patientId = $this->insertPatient($clinicId, 'g7ok');
        $visitId   = $this->insertVisit($patientId, (int) $fx['clinician'], $clinicId, $locationId, 'in_consultation');
        wp_set_current_user((int) $fx['doctor']);
        $ok = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visitId), [], $headers);
        self::assertSame(200, $ok->get_status(), 'G7 positive control: the authorized doctor still reads the Visit Workspace.');

        // Missing nonce is still rejected (CSRF layer unchanged).
        wp_set_current_user((int) $fx['doctor']);
        $noNonce = $this->dispatch('GET', '/' . self::PORTAL_CONTEXT, [], $headers, false);
        self::assertSame(403, $noNonce->get_status(), 'G7: wp_rest nonce remains mandatory.');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'G7: established nonce denial code.');
    }

    // =================================================================
    // G8 — REAL BROWSER FOUNDATION (GREEN GUARDS + RED ANCHOR)
    // =================================================================

    public function testGroup8_RealBrowserFoundation(): void
    {
        $fx = $this->buildStaffStage('g8');
        $this->assertStaffFixture($fx);

        $root       = dirname(__DIR__, 2);
        $pilotPath  = $root . '/bin/pilot-doctor-portal.py';
        $pilotGate  = $root . '/../.github/workflows/pilot-gate.yml';
        $templatePath = $root . '/templates/doctor-portal-shell.php';
        $jsPath     = $root . '/assets/js/cpms-doctor-portal.js';

        // ---- GREEN GUARDS: the established real-browser pilot foundation is intact. ----
        self::assertFileExists($pilotPath, 'G8 guard: existing Doctor Portal pilot harness exists.');
        self::assertFileExists($templatePath, 'G8 guard: portal template exists.');
        self::assertFileExists($jsPath, 'G8 guard: portal JS exists.');

        $pilot = (string) file_get_contents($pilotPath);
        foreach (['mobile-390', 'tablet-768', 'desktop-1366', '390', '844', '768', '1024', '1366'] as $token) {
            self::assertStringContainsString($token, $pilot, 'G8 guard: viewport ' . $token . ' retained in the pilot.');
        }
        self::assertStringContainsString(
            'assert_queue_hugs_content',
            $pilot,
            'G8 guard: owner-approved no-horizontal-overflow / tablet queue layout proof retained.'
        );
        self::assertStringContainsString('prove_workspace_rx', $pilot, 'G8 guard: Visit Workspace journey retained in the pilot.');
        if (is_file($pilotGate)) {
            self::assertStringContainsString(
                'pilot-doctor-portal.py',
                (string) file_get_contents($pilotGate),
                'G8 guard: the pilot gate keeps driving the existing Doctor Portal pilot.'
            );
        }

        $template = (string) file_get_contents($templatePath);
        $ui       = $template . "\n" . (string) file_get_contents($jsPath);
        self::assertStringContainsString('dir="rtl"', $template, 'G8 guard: portal stays RTL.');
        foreach (['secretary-queue', 'accountant-portal', 'finance-portal', 'receptionist', 'role-switcher'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $ui,
                'G8 guard: out-of-scope Phase 11 surface must not exist (' . $forbidden . ').'
            );
        }

        // ---- RED ANCHOR: the canonical Staff Portal must resolve under BOTH Plain and Pretty. ----
        foreach (['plain' => '', 'pretty' => '/%postname%/'] as $shape => $structure) {
            $this->set_permalink_structure($structure);
            $this->permalinkChanged = true;

            $url = $this->tryResolveStaffPortalUrl();
            self::assertNotNull(
                $url,
                'G8: the canonical Staff Portal must be a real WordPress frontend path that resolves under '
                . $shape . ' permalinks (no custom rewrite framework, no /wp-admin/). It does not exist on live main.'
            );

            self::assertNotSame('', $url, 'G8 (' . $shape . '): canonical URL must be non-empty.');
            self::assertStringContainsString('http', $url, 'G8 (' . $shape . '): canonical URL must be absolute.');
            self::assertStringNotContainsString('/wp-admin/', $url, 'G8 (' . $shape . '): canonical URL must not live under /wp-admin/.');

            $html = $this->renderCanonicalAs((int) $fx['doctor'], $url);
            $this->assertStaffShellRoot($html, 'G8/' . $shape);
            $this->assertNoWpAdminChrome($html, 'G8/' . $shape);
            $this->assertSessionAndRestNonce($html, 'G8/' . $shape);
            self::assertStringContainsString(
                'dir="rtl"',
                $html,
                'G8 (' . $shape . '): the shared operational shell document must stay RTL.'
            );
        }
    }

    // =================================================================
    // Canonical Staff Portal discovery (product seams — never fixture filenames)
    // =================================================================

    /**
     * Resolve the canonical Staff Portal URL through product seams only.
     *
     * GREEN may satisfy ANY of:
     *   1) a StaffPortalShell (or equivalent) class exposing a frontend URL helper;
     *   2) a product filter `cpms_staff_portal_frontend_url`;
     *   3) a product option holding the CPMS-owned Page id;
     *   4) a published CPMS-owned Page at the canonical slug.
     *
     * The test never creates a page: on live main all four seams are absent.
     */
    private function tryResolveStaffPortalUrl(): ?string
    {
        $classes = ['ClinicCore\\Frontend\\StaffPortalShell'];
        $methods = ['portal_url', 'frontendPortalUrl', 'frontendUrl', 'staffPortalFrontendUrl', 'pageUrl'];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }
            foreach ($methods as $method) {
                if (!is_callable([$class, $method])) {
                    continue;
                }
                try {
                    $url = (string) $class::{$method}();
                } catch (\Throwable $e) {
                    unset($e);
                    continue;
                }
                if ($this->isAcceptableStaffPortalUrl($url)) {
                    return $url;
                }
            }
        }

        $filtered = apply_filters('cpms_staff_portal_frontend_url', '');
        if (is_string($filtered) && $this->isAcceptableStaffPortalUrl($filtered)) {
            return $filtered;
        }

        foreach (['cpms_staff_portal_page_id', 'cpms_staff_portal_page'] as $option) {
            $pageId = (int) get_option($option, 0);
            if ($pageId <= 0) {
                continue;
            }
            $url = get_permalink($pageId);
            if (is_string($url) && $this->isAcceptableStaffPortalUrl($url)) {
                return $url;
            }
        }

        $byPath = get_page_by_path(self::CANONICAL_SLUG);
        if ($byPath instanceof \WP_Post && 'publish' === $byPath->post_status && 'page' === $byPath->post_type) {
            $url = get_permalink($byPath->ID);
            if (is_string($url) && $this->isAcceptableStaffPortalUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private function isAcceptableStaffPortalUrl(string $url): bool
    {
        if ('' === $url || !str_contains($url, 'http')) {
            return false;
        }
        if (str_contains($url, '/wp-admin/')) {
            return false;
        }

        return true;
    }

    private function requireStaffPortalUrl(string $message): string
    {
        $url = $this->tryResolveStaffPortalUrl();
        if (null === $url) {
            $this->annotate('red-staff-portal-shell', 'Phase 10 Staff Portal shell foundation missing', $message);
            self::assertNotNull($url, $message);
        }
        self::assertIsString($url);
        self::assertNotSame('', $url, $message);

        return (string) $url;
    }

    // =================================================================
    // Shell assertions
    // =================================================================

    /**
     * @param array<string, mixed> $fx
     */
    private function assertStaffShellRoot(string $html, string $label): void
    {
        $found = false;
        foreach (self::STAFF_SHELL_ROOT_PATTERNS as $token) {
            if (str_contains($html, $token)) {
                $found = true;
                break;
            }
        }
        self::assertTrue(
            $found,
            $label . ': the document must carry a greppable shared Staff Portal shell root marker '
            . '(one of: ' . implode(' | ', self::STAFF_SHELL_ROOT_PATTERNS) . ').'
        );
    }

    private function assertNoWpAdminChrome(string $html, string $label): void
    {
        foreach (self::WP_ADMIN_CHROME as $token) {
            self::assertStringNotContainsString(
                $token,
                $html,
                $label . ': no wp-admin chrome in the operational Staff Portal document (' . $token . ').'
            );
        }
    }

    /**
     * WordPress session reuse + `wp_rest` nonce exposure (no new auth system,
     * no new token mechanism).
     */
    private function assertSessionAndRestNonce(string $html, string $label): void
    {
        $config = null;
        foreach ($this->jsonScriptPayloads($html) as $payload) {
            $decoded = json_decode(trim($payload), true);
            if (is_array($decoded) && isset($decoded['nonce']) && isset($decoded['rest_root'])) {
                $config = $decoded;
                break;
            }
        }

        self::assertNotNull(
            $config,
            $label . ': the shell must publish exactly one runtime config carrying rest_root + nonce '
            . '(WordPress session + wp_rest nonce reuse).'
        );
        self::assertNotFalse(
            wp_verify_nonce((string) $config['nonce'], 'wp_rest'),
            $label . ': the published nonce must verify as a real `wp_rest` nonce.'
        );
        self::assertSame(
            untrailingslashit(rest_url(self::REST_NS)),
            untrailingslashit((string) $config['rest_root']),
            $label . ': the shell must publish the EXISTING CPMS REST namespace (no second backend).'
        );
    }

    private function assertLogoutSurface(string $html, string $label): void
    {
        self::assertMatchesRegularExpression(
            '/logout|خروج/',
            $html,
            $label . ': the operational shell must expose a logout surface for the authenticated session.'
        );
    }

    // =================================================================
    // Module-entry extraction (capability-driven navigation)
    // =================================================================

    /**
     * @return list<string>
     */
    private function moduleEntries(string $html): array
    {
        $found = [];
        if (preg_match_all('/<[a-zA-Z][^>]*>/s', $html, $matches) !== false) {
            foreach ($matches[0] as $tag) {
                foreach (self::MODULE_ATTRIBUTES as $attr) {
                    if (preg_match('/' . preg_quote($attr, '/') . '="([^"]*)"/', $tag, $m) === 1) {
                        $found[] = strtolower(trim($m[1]));
                    }
                }
            }
        }

        return $found;
    }

    private function hasModuleEntry(string $html, string $module): bool
    {
        foreach ($this->moduleEntries($html) as $entry) {
            if ($entry === $module) {
                return true;
            }
            if (preg_match('/(^|[-_])' . preg_quote($module, '/') . '([-_]|$)/', $entry) === 1) {
                return true;
            }
        }

        return false;
    }

    // =================================================================
    // Render helpers
    // =================================================================

    private function requestFor(string $url): string
    {
        $path  = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $req   = $path . ('' !== $query ? '?' . $query : '');

        return '' === $req ? '/' : $req;
    }

    /**
     * Render the canonical Staff Portal through the real WordPress page
     * lifecycle: resolve request → template_include → include the template.
     */
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

        self::assertNotSame(
            $baseline,
            $template,
            'template_include must intercept the canonical Staff Portal page with a plugin-owned template. '
            . 'Got baseline passthrough: ' . $template
        );
        self::assertFileExists($template, 'the plugin-owned Staff Portal template must exist on disk. Got: ' . $template);
        self::assertStringNotContainsString(
            '/themes/',
            str_replace('\\', '/', $template),
            'the intercepted Staff Portal template must not live inside a theme directory (theme independence). Got: ' . $template
        );

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    /** GREEN control path: the already-merged Doctor Portal (legacy entry). */
    private function renderLegacyDoctorPortalAs(int $userId): string
    {
        wp_set_current_user($userId);
        $this->go_to($this->requestFor(DoctorPortalShell::portal_url()));

        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $template = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $template, 'legacy Doctor Portal template interception still works.');
        self::assertFileExists($template, 'legacy Doctor Portal template exists.');

        ob_start();
        include $template;

        return (string) ob_get_clean();
    }

    private function adminBarVisibleOn(int $userId, string $url): bool
    {
        wp_set_current_user($userId);
        $this->go_to($this->requestFor($url));

        return (bool) apply_filters('show_admin_bar', true);
    }

    /**
     * @return list<string>
     */
    private function jsonScriptPayloads(string $html): array
    {
        $out = [];
        if (preg_match_all('/<script[^>]*type="application\/json"[^>]*>(.*?)<\/script>/s', $html, $m) !== false) {
            foreach ($m[1] as $payload) {
                $out[] = (string) $payload;
            }
        }

        return $out;
    }

    private function normalizeUrl(string $url): string
    {
        return untrailingslashit(strtolower(trim($url)));
    }

    private function installProofControlThemes(): void
    {
        $srcRoot    = dirname(__DIR__) . '/Fixtures/standalone-shell/themes';
        $themesRoot = (string) get_theme_root();
        foreach (array_keys(self::CONTROL_THEMES) as $slug) {
            $dest = $themesRoot . '/' . $slug;
            if (!is_dir($dest)) {
                $this->copyDir($srcRoot . '/' . $slug, $dest);
            }
        }
        wp_clean_themes_cache(true);
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        foreach (scandir($src) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $from = $src . '/' . $entry;
            $to   = $dest . '/' . $entry;
            if (is_dir($from)) {
                $this->copyDir($from, $to);
            } else {
                copy($from, $to);
            }
        }
    }

    // =================================================================
    // Fixture
    // =================================================================

    /**
     * Build one shared operational Staff Portal stage.
     *
     * ROLE / CAPABILITY / MEMBERSHIP / LOCATION are kept strictly separate:
     *   - WP role        : coarse preset only (`cpms_doctor`, `cpms_secretary`, …).
     *   - capability     : operation permission atom (from the role preset or a
     *                      per-membership override).
     *   - membership     : tenant participation (per-Clinic role_key + status).
     *   - location       : operational Location authority (doctor module only).
     *
     * @return array<string, int>
     */
    private function buildStaffStage(string $tag): array
    {
        $org     = $this->insertOrg('Staff Org ' . $tag);
        $clinic  = $this->insertClinicInOrg('Staff Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $location = $this->insertLocation($clinic, 'Staff Loc ' . $tag, self::TZ_TEHRAN, 1);

        // --- Doctor (only fixture with an active clinician row) ---
        $doctor    = $this->makeUser('sp_' . $tag . '_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr Staff ' . $tag, $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);

        // --- Second active Clinic where the SAME doctor is an accountant (multi-Clinic policy diff) ---
        $secondClinic = $this->insertClinicInOrg('Staff Second Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $this->insertLocation($secondClinic, 'Staff Second Loc ' . $tag, self::TZ_TEHRAN, 1);
        cpms_test_seed_membership($doctor, $secondClinic, RolesAndCapabilities::ROLE_ACCOUNTANT);

        // --- Suspended Clinic (no operational data) ---
        $foreignClinic = $this->insertClinicInOrg('Staff Suspended Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $this->insertLocation($foreignClinic, 'Staff Suspended Loc ' . $tag, self::TZ_TEHRAN, 1);
        $suspendedMembershipId = cpms_test_seed_membership($doctor, $foreignClinic, RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->suspend_membership($suspendedMembershipId);

        // --- Unrelated Clinic with no membership at all ---
        $unrelatedClinic = $this->insertClinicInOrg('Staff Unrelated Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $this->insertLocation($unrelatedClinic, 'Staff Unrelated Loc ' . $tag, self::TZ_TEHRAN, 1);

        // --- Secretary (active membership, no clinician row) ---
        $secretary = $this->makeUser('sp_' . $tag . '_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);

        // --- Accountant (active membership, no clinician row) ---
        $accountant = $this->makeUser('sp_' . $tag . '_acc', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountant, $clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);

        // --- Patient-only user (Patient Portal, never operational Staff Portal) ---
        $patient = $this->makeUser('sp_' . $tag . '_pat', RolesAndCapabilities::ROLE_PATIENT);

        // --- WP administrator with NO membership (admin ≠ clinical access) ---
        $administrator = $this->makeUser('sp_' . $tag . '_adm', 'administrator');

        // --- WP doctor role + clinician row but NO membership (role is not tenant authority) ---
        $noMembershipDoctor = $this->makeUser('sp_' . $tag . '_nomem', RolesAndCapabilities::ROLE_DOCTOR);
        $this->insertClinician('Dr NoMembership ' . $tag, $clinic, 1, $noMembershipDoctor);

        // --- Suspended membership doctor (active clinician, suspended tenant participation) ---
        $suspendedDoctor = $this->makeUser('sp_' . $tag . '_susp', RolesAndCapabilities::ROLE_DOCTOR);
        $this->insertClinician('Dr Suspended ' . $tag, $clinic, 1, $suspendedDoctor);
        $suspendedId = cpms_test_seed_membership($suspendedDoctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->suspend_membership($suspendedId);

        return [
            'org'              => $org,
            'clinic'           => $clinic,
            'location'         => $location,
            'clinician'        => $clinician,
            'doctor'           => $doctor,
            'secondClinic'     => $secondClinic,
            'foreignClinic'    => $foreignClinic,
            'unrelatedClinic'  => $unrelatedClinic,
            'secretary'        => $secretary,
            'accountant'       => $accountant,
            'patient'          => $patient,
            'administrator'    => $administrator,
            'noMembershipDoctor' => $noMembershipDoctor,
            'suspendedDoctor'  => $suspendedDoctor,
        ];
    }

    /**
     * Fixture persistence proof (§20): every access-denial assertion is only
     * meaningful because these positive controls passed first.
     *
     * @param array<string, int> $fx
     */
    private function assertStaffFixture(array $fx): void
    {
        foreach ($fx as $key => $value) {
            self::assertGreaterThan(0, $value, 'fixture: ' . $key . ' must be a real persisted id.');
        }

        $db = App::db();

        $clinicRow = $db->fetchRow(
            'SELECT id, organization_id, timezone FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d',
            [(int) $fx['clinic']]
        );
        self::assertNotNull($clinicRow, 'fixture: Clinic row persisted.');
        self::assertSame(self::TZ_TEHRAN, (string) $clinicRow['timezone'], 'fixture: Clinic timezone persisted.');
        self::assertSame((int) $fx['org'], (int) $clinicRow['organization_id'], 'fixture: Clinic bound to its Organization.');

        $locationRow = $db->fetchRow(
            'SELECT id, clinic_id, is_active, is_primary, timezone FROM ' . $db->table('cpms_locations') . ' WHERE id = %d',
            [(int) $fx['location']]
        );
        self::assertNotNull($locationRow, 'fixture: Location row persisted.');
        self::assertSame((int) $fx['clinic'], (int) $locationRow['clinic_id'], 'fixture: Location bound to the Clinic (Location is not Clinic identity).');
        self::assertSame(1, (int) $locationRow['is_active'], 'fixture: Location active.');
        self::assertSame(1, (int) $locationRow['is_primary'], 'fixture: Location is primary.');
        self::assertSame(self::TZ_TEHRAN, (string) $locationRow['timezone'], 'fixture: Location timezone persisted.');

        $clinicianRow = $db->fetchRow(
            'SELECT id, clinic_id, wp_user_id, is_active FROM ' . $db->table('cpms_clinicians') . ' WHERE id = %d',
            [(int) $fx['clinician']]
        );
        self::assertNotNull($clinicianRow, 'fixture: clinician row persisted.');
        self::assertSame((int) $fx['doctor'], (int) $clinicianRow['wp_user_id'], 'fixture: clinician bound to the doctor WP user.');
        self::assertSame((int) $fx['clinic'], (int) $clinicianRow['clinic_id'], 'fixture: clinician bound to the Clinic.');
        self::assertSame(1, (int) $clinicianRow['is_active'], 'fixture: clinician active.');

        $membership = App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['doctor']);
        self::assertIsArray($membership, 'fixture: doctor membership persisted.');
        self::assertSame('active', (string) $membership['status'], 'fixture: doctor membership status is exactly "active".');
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) $membership['role_key'], 'fixture: doctor membership role_key is exactly cpms_doctor.');

        $secMembership = App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['secretary']);
        self::assertIsArray($secMembership, 'fixture: secretary membership persisted.');
        self::assertSame('active', (string) $secMembership['status'], 'fixture: secretary membership active.');
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $secMembership['role_key'], 'fixture: secretary membership role_key exactly cpms_secretary.');

        $accMembership = App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['accountant']);
        self::assertIsArray($accMembership, 'fixture: accountant membership persisted.');
        self::assertSame('active', (string) $accMembership['status'], 'fixture: accountant membership active.');
        self::assertSame(RolesAndCapabilities::ROLE_ACCOUNTANT, (string) $accMembership['role_key'], 'fixture: accountant membership role_key exactly cpms_accountant.');

        self::assertNull(
            App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['noMembershipDoctor']),
            'fixture: the no-membership doctor really has NO membership row (not a broken fixture).'
        );
        self::assertNull(
            App::membership_service()->active_membership_for((int) $fx['clinic'], (int) $fx['suspendedDoctor']),
            'fixture: the suspended doctor has no ACTIVE membership (not a broken fixture).'
        );
        self::assertNotNull(
            App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['suspendedDoctor']),
            'fixture: the suspended doctor DOES have a persisted membership row (suspended, not missing).'
        );
        self::assertNull(
            App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['patient']),
            'fixture: the patient-only user has no operational membership (Patient Portal only).'
        );
        self::assertNull(
            App::membership_service()->membership_for((int) $fx['clinic'], (int) $fx['administrator']),
            'fixture: the WP administrator has no operational membership (admin ≠ tenant participation).'
        );

        // Role/capability separation: WP roles persisted exactly.
        self::assertSame(
            [RolesAndCapabilities::ROLE_DOCTOR],
            array_values((array) $this->wpUser((int) $fx['doctor'])->roles),
            'fixture: doctor WP role is exactly cpms_doctor.'
        );
        self::assertSame(
            [RolesAndCapabilities::ROLE_PATIENT],
            array_values((array) $this->wpUser((int) $fx['patient'])->roles),
            'fixture: patient WP role is exactly cpms_patient.'
        );
        self::assertSame(
            ['administrator'],
            array_values((array) $this->wpUser((int) $fx['administrator'])->roles),
            'fixture: administrator WP role is exactly administrator.'
        );
    }

    // =================================================================
    // Generic helpers (proven Phase 10 patterns)
    // =================================================================

    /**
     * @return array<string, string>
     */
    private function scopeHeaders(int $clinic, ?int $location = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinic];
        if (null !== $location) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(WP_REST_Response $res): array
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

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawErrorData(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            $data = $body->get_error_data();

            return is_array($data) ? $data : [];
        }
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return is_array($body) ? $body : [];
    }

    private function annotate(string $kind, string $message, string $detail): void
    {
        $text   = trim((string) preg_replace('/\s+/', ' ', $message . ' :: ' . $detail));
        $escaped = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', ' -', ';'], $text);
        $tokens  = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '', substr($message, 0, 24)));
        fwrite(STDERR, '::error title=CPMS-Phase10StaffPortal-' . $kind . '-' . $tokens . '::' . $escaped . PHP_EOL);
    }

    private function wpUser(int $userId): \WP_User
    {
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'fixture: WP user ' . $userId . ' exists.');

        return $user;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, 'pass-not-used-123', $unique . '@test.local');
        self::assertGreaterThan(0, $userId, 'fixture: WP user created.');
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $userId;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
            $name,
            'org-' . bin2hex(random_bytes(3)),
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization fixture row must persist');

        return $id;
    }

    private function insertClinicInOrg(string $name, int $orgId, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $orgId,
            $name,
            'cl-' . bin2hex(random_bytes(3)),
            $tz,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinic fixture row must persist');
        App::resetScope();

        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $tz, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)',
            $clinicId,
            $name,
            'loc-' . bin2hex(random_bytes(3)),
            $tz,
            $primary,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location fixture row must persist');

        return $id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)',
            $clinicId,
            $name,
            $wpUserId,
            $active,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinician fixture row must persist (wp_user_id must be distinct)');

        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $mrn    = 'MR-SP-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $clinicId,
            $mrn,
            'Test',
            'Patient ' . substr($mrn, -4),
            $mobile,
            'active',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture row must persist');

        return $id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now   = App::db()->nowUtcSql();
        $today = self::FIXED_UTC_DATE;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)',
            $clinicId,
            $locId,
            $clinicianId,
            $patientId,
            'walk_in',
            $status,
            $today,
            $today . ' 10:00:00',
            $today . ' 10:00:00',
            'skipped' === $status ? 0 : 1,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'visit fixture row must persist (clinician/location/patient FKs must be valid)');

        return $id;
    }
}

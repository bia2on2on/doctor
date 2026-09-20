<?php

/**
 * Phase 9 Slice 1 — Patient Portal self-cancel UI (TEST-ONLY RED).
 *
 * ===========================================================================
 * SCOPE (owner-directed Slice 1 start — narrowest useful vertical slice)
 * ===========================================================================
 *
 * The authenticated pure-patient console («نوبت‌های من», PatientPortalPage,
 * slug cpms-patient) must let the patient cancel THEIR OWN confirmed upcoming
 * appointment through the EXISTING backend contract:
 *
 *   B4  POST clinic/v1/appointments/{id}/cancel   (X-WP-Nonce: wp_rest)
 *
 * B4 is already implemented and green (BookingController::cancelPermission →
 * BookingService::cancelByPatient: ownership 403, active-Visit 409,
 * cancel-deadline 409 CLINIC_POLICY_VIOLATION). Slice 1 adds ONLY the portal
 * surface that lets the patient reach it. NOT in scope (NOT asserted here):
 * reschedule, profile, visits, files, notifications, finance, any new REST
 * route, any migration (live latest on disk = 2026_09_20_0022; 0023 is NOT
 * reserved), any JS/CSS asset behaviour.
 *
 * ===========================================================================
 * SLICE 1 UI/CONFIG CONTRACT (what GREEN must satisfy — defined by this file)
 * ===========================================================================
 *
 *  C1  Every UPCOMING row of the patient's own appointments whose status is
 *      cancellable by the patient (confirmed) exposes exactly one accessible
 *      self-cancel action:
 *        <button … data-role="cancel-appointment"
 *                  data-appointment-id="<appointment id>" …>لغو …</button>
 *      - it lives inside the same <tr> as that appointment (reference_code);
 *      - its accessible name (text content or aria-label) contains «لغو»;
 *      - it is not disabled;
 *      - data-role is the stable product-facing marker for vanilla-JS wiring
 *        (precedent: PublicBookingShortcode data-role="…");
 *      - data-appointment-id is a SELECTOR ONLY — authority stays server-side
 *        (B4 ownership check). Cancelled/history rows get no action.
 *
 *  C2  The page publishes exactly one safe runtime config for calling B4:
 *        <script type="application/json" class="cpms-patient-portal__config">
 *          {"rest_root":"…","cancel_path":"/appointments/{id}/cancel","nonce":"…"}
 *        </script>
 *      - rest_root  === untrailingslashit(rest_url('clinic/v1')) — i.e.
 *        rest_url()-derived, so it is correct under BOTH permalink shapes:
 *        Plain  → http://host/index.php?rest_route=/clinic/v1
 *        Pretty → http://host/wp-json/clinic/v1
 *        (composition rule = assets/js/cpms-public-booking.js apiUrl():
 *        rest_root + path; a '?' in path becomes '&' when rest_root already
 *        carries the rest_route query — cancel_path carries no query part);
 *      - cancel_path === '/appointments/{id}/cancel' ({id} placeholder);
 *      - nonce is a wp_rest nonce for the authenticated patient
 *        (wp_verify_nonce(nonce, 'wp_rest') truthy) — B4 rejects requests
 *        without it (403 CLINIC_INVALID_NONCE);
 *      - config exposes NO clinic_id / patient_id (not authority — the server
 *        resolves ownership from the session; precedent: ADR-0019 envelopes,
 *        Phase 8 linked-only policy);
 *      - encoded per repo precedent (PublicBookingShortcode::renderConfig):
 *        wp_json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
 *        → payload contains no raw '<' / '>' (cannot break out of <script>).
 *
 * ===========================================================================
 * LIVE STATE AT RED AUTHORING (independently fetched, 2026-09-20)
 * ===========================================================================
 *
 * - authoritative main = 2263ed673a8fdf4ab91e6a7fffae6d82252e4324
 *   (19/19 checks green; matches the owner's expected main — no drift)
 * - open PRs = 0 (gh pr list --state open => []); remote branches = main only
 * - workspace status = clean (tracked + untracked: none)
 * - latest migration on disk = 2026_09_20_0022_slot_holds_patient_binding.php
 *   (no 0023 exists; none is created or reserved by this RED)
 * - Phase 8 CLOSED per docs/agent-guide.md; Phase 9 row ⏳ (not started)
 * - PatientPortalPage::render() today: title/heading/history tables only —
 *   NO per-row appointment marker, NO cancel action, NO REST/nonce config.
 * - no overlapping Phase 9 Slice 1 work (no open PR, no Phase9Slice1* file)
 *
 * ===========================================================================
 * RED CLASSIFICATION (CI Integration job = source of truth; NOT RUN != PASS)
 * ===========================================================================
 *
 * INTENDED RED — exactly two tests fail on live main, attributable ONLY to
 * the missing Slice 1 UI/config contract (product-level assertion failures,
 * never bootstrap/fixture/SQL/FK/harness failures):
 *
 *  R1 testPortalRendersSelfCancelActionForOwnConfirmedUpcomingAppointment
 *     Fixture + positive controls pass; the real PatientPortalPage::render()
 *     path is reached and lists the appointment as upcoming; then C1 fails
 *     because no data-role="cancel-appointment" action exists (0 ≠ 1).
 *
 *  R2 testPortalPublishesCancelRouteConfigAndRestNonceForAuthenticatedPatient
 *     Same fixture/controls; rendered under Plain permalinks first; C2 fails
 *     because no <script class="cpms-patient-portal__config"> exists (0 ≠ 1).
 *     (Pretty-permalink pass and the config-driven B4 sufficiency call are
 *     behind that first assertion and are reached only in GREEN.)
 *
 * GUARD / POSITIVE CONTROL — must pass today and keep GREEN honest:
 *
 *  G1 testPositiveControlExistingB4RouteCancelsOwnConfirmedUpcomingAppointment
 *     Identical fixture; B4 without nonce → 403 CLINIC_INVALID_NONCE; B4 with
 *     wp_rest nonce as the patient → 200 {data:{appointment_id,status:
 *     cancelled_by_patient}} and the row is persisted as cancelled; the portal
 *     then lists it under «تاریخچه». Proves the fixture is a REAL owned,
 *     confirmed, upcoming appointment that the existing backend accepts —
 *     so R1/R2 can only fail for the missing UI/config, not a backend defect.
 *     Backend contracts stay covered by their own suites (RestBookingTest::
 *     testPatientCannotCancelOthersAppointment, BookingFlowTest::
 *     testCancelPolicyWindow, Phase7Slice2ActiveVisitInvariantRedTest,
 *     PatientPortalTest) and are NOT re-asserted or made RED here.
 *
 * Positive controls executed BEFORE any HTML assertion (fixture proof):
 *  - single seeded Clinic resolves via App::scope() (SOURCE_SYSTEM_SINGLE) —
 *    the portal's App::settings() call requires it; ids are read dynamically;
 *  - WP user exists with exactly the portal-expected role (cpms_patient) and
 *    PatientPortalPage::isPatientOnly() is true;
 *  - Clinic-level Patient link row belongs to that WP user and Clinic;
 *  - appointment row exists, patient_id = linked Patient, status = confirmed,
 *    slot_date in the future, clinic_id = that Clinic;
 *  - BookingService::listMine() (the portal's data path) includes it as
 *    confirmed/upcoming (and includes the cancelled history control row).
 *
 * INVALID-RED PROTECTIONS: fixture inserts use only columns that exist on
 * live main (same column sets as Phase 7/8 RED suites); no hardcoded clinic
 * id; distinct mobiles/logins per test; ScopeContext/Settings caches cleared;
 * permalink structure restored in finally + tearDown; output buffering closed
 * in finally so a wp_die() cannot leak buffers.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase9Slice1PatientPortalSelfCancelRedTest extends WP_UnitTestCase
{
    /** REST namespace of the existing booking API (BookingController::NS). */
    private const NS = 'clinic/v1';

    /** Existing B4 route, as a client-side template ({id} = appointment selector). */
    private const CANCEL_PATH_TEMPLATE = '/appointments/{id}/cancel';

    /** C1 — stable product-facing marker of the per-row self-cancel action. */
    private const CANCEL_ACTION_ROLE = 'cancel-appointment';

    /** C2 — class of the safe JSON runtime config (precedent: cpms-public-booking__config). */
    private const CONFIG_CLASS = 'cpms-patient-portal__config';

    /** Exact product strings of PatientPortalPage (ZWNJ = \u{200C}, verified byte-for-byte). */
    private const TITLE_MY_APPOINTMENTS = "نوبت\u{200C}های من";
    private const HEADING_UPCOMING = "نوبت\u{200C}های پیش\u{200C}رو";
    private const HEADING_HISTORY = 'تاریخچه';

    /** Required word of the accessible name of the self-cancel action («لغو» = cancel). */
    private const CANCEL_WORD = 'لغو';

    private const TZ_TEHRAN = 'Asia/Tehran';

    private ?string $permalinkStructureToRestore = null;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->permalinkStructureToRestore = null;
    }

    protected function tearDown(): void
    {
        if ($this->permalinkStructureToRestore !== null) {
            $this->set_permalink_structure($this->permalinkStructureToRestore);
            $this->permalinkStructureToRestore = null;
        }
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // =================================================================
    // R1 — INTENDED RED: per-row accessible self-cancel action (C1)
    // =================================================================

    public function testPortalRendersSelfCancelActionForOwnConfirmedUpcomingAppointment(): void
    {
        $fx = $this->buildOwnedConfirmedUpcomingAppointmentFixture('r1');
        $this->assertFixtureMaterialized($fx);

        // Real product path: PatientPortalPage::render() as the authenticated pure patient.
        $html = $this->renderPortalAs($fx['user_id']);
        $this->assertPortalListsFixtureRows($html, $fx);

        $row = $this->tableRowContaining($html, $fx['reference_code'], 'upcoming appointment row');

        // ---- INTENDED RED (C1): no self-cancel action exists on live main. ----
        $actions = $this->cancelActionTags($html);
        self::assertCount(
            1,
            $actions,
            'Slice 1 C1: the portal must render exactly one accessible self-cancel action '
            . '(data-role="' . self::CANCEL_ACTION_ROLE . '") for the patient\'s own confirmed upcoming appointment #'
            . $fx['appointment_id'] . '. Rendered upcoming row: ' . $row
        );
        $action = $actions[0];

        self::assertStringContainsString(
            $action,
            $row,
            'Slice 1 C1: the self-cancel action must live inside the same <tr> as appointment #' . $fx['appointment_id'] . '.'
        );
        self::assertMatchesRegularExpression(
            '/^<button\b/i',
            $action,
            'Slice 1 C1: the self-cancel action must be a real <button> (keyboard/screen-reader operable), got: ' . $action
        );
        self::assertSame(
            (string) $fx['appointment_id'],
            $this->attributeValue($action, 'data-appointment-id'),
            'Slice 1 C1: the action must be bound to exactly this appointment via data-appointment-id (selector only).'
        );
        self::assertFalse(
            $this->hasBooleanAttribute($action, 'disabled'),
            'Slice 1 C1: a confirmed upcoming appointment must be cancellable — the action must not be disabled.'
        );

        $name = $this->accessibleName($row, $action);
        self::assertNotSame('', $name, 'Slice 1 C1: the self-cancel action needs a non-empty accessible name.');
        self::assertStringContainsString(
            self::CANCEL_WORD,
            $name,
            'Slice 1 C1: the accessible name must tell the patient it cancels («' . self::CANCEL_WORD . '»), got: ' . $name
        );

        // Negative control (C1): the cancelled history row must NOT expose a cancel action.
        $historyRow = $this->tableRowContaining($html, $fx['history_reference_code'], 'cancelled history row');
        self::assertCount(
            0,
            $this->cancelActionTags($historyRow),
            'Slice 1 C1: an already-cancelled (history) appointment must not expose a self-cancel action.'
        );
    }

    // =================================================================
    // R2 — INTENDED RED: safe runtime config + wp_rest nonce for B4 (C2)
    // =================================================================

    public function testPortalPublishesCancelRouteConfigAndRestNonceForAuthenticatedPatient(): void
    {
        $fx = $this->buildOwnedConfirmedUpcomingAppointmentFixture('r2');
        $this->assertFixtureMaterialized($fx);

        $expectedRoute = '/' . self::NS . '/appointments/' . $fx['appointment_id'] . '/cancel';
        $lastConfig = null;
        $lastComposedUrl = '';

        $original = (string) get_option('permalink_structure');
        $this->permalinkStructureToRestore = $original;
        try {
            // Plain first (the shape the live WP test install uses); Pretty second.
            foreach (['plain' => '', 'pretty' => '/%postname%/'] as $shape => $structure) {
                $this->set_permalink_structure($structure);
                $expectedRestRoot = untrailingslashit(rest_url(self::NS));
                if ($shape === 'plain') {
                    self::assertStringContainsString('rest_route=', $expectedRestRoot, 'precondition: Plain permalinks → rest_url() uses ?rest_route=');
                } else {
                    self::assertStringContainsString('/' . rest_get_url_prefix() . '/' . self::NS, $expectedRestRoot, 'precondition: Pretty permalinks → /wp-json/');
                    self::assertStringNotContainsString('rest_route=', $expectedRestRoot, 'precondition: Pretty permalinks carry no rest_route query');
                }

                $html = $this->renderPortalAs($fx['user_id']);
                $this->assertPortalListsFixtureRows($html, $fx);

                // ---- INTENDED RED (C2): no runtime config/nonce is published on live main. ----
                $scripts = $this->configScriptPayloads($html);
                self::assertCount(
                    1,
                    $scripts,
                    'Slice 1 C2 (' . $shape . ' permalinks): the portal must publish exactly one '
                    . '<script type="application/json" class="' . self::CONFIG_CLASS . '"> runtime config '
                    . '(rest_root + cancel_path + wp_rest nonce) so vanilla JS can call the existing B4 route.'
                );
                $payload = trim($scripts[0]);

                // Safe JSON per repo precedent (JSON_HEX_TAG|JSON_HEX_AMP): no raw angle brackets inside <script>.
                self::assertStringNotContainsString('<', $payload, 'Slice 1 C2 (' . $shape . '): config payload must be JSON_HEX_TAG-encoded (no raw "<").');
                self::assertStringNotContainsString('>', $payload, 'Slice 1 C2 (' . $shape . '): config payload must be JSON_HEX_TAG-encoded (no raw ">").');
                $config = json_decode($payload, true);
                self::assertIsArray($config, 'Slice 1 C2 (' . $shape . '): config payload must be a JSON object. Payload: ' . $payload);

                // No authority identifiers — the server resolves ownership from the session (B4).
                $this->assertNoAuthorityIdentifiers($config, $shape);

                // rest_root — rest_url()-derived (permalink-shape agnostic).
                self::assertArrayHasKey('rest_root', $config, 'Slice 1 C2 (' . $shape . '): config must expose rest_root.');
                self::assertSame(
                    $expectedRestRoot,
                    (string) $config['rest_root'],
                    'Slice 1 C2 (' . $shape . '): rest_root must equal untrailingslashit(rest_url("' . self::NS . '")).'
                );

                // cancel_path — the EXISTING B4 route as a client template.
                self::assertArrayHasKey('cancel_path', $config, 'Slice 1 C2 (' . $shape . '): config must expose cancel_path.');
                self::assertSame(
                    self::CANCEL_PATH_TEMPLATE,
                    (string) $config['cancel_path'],
                    'Slice 1 C2 (' . $shape . '): cancel_path must target the existing B4 route with an {id} placeholder.'
                );

                // nonce — wp_rest nonce valid for the authenticated patient.
                self::assertArrayHasKey('nonce', $config, 'Slice 1 C2 (' . $shape . '): config must expose a wp_rest nonce.');
                $nonce = (string) $config['nonce'];
                self::assertNotSame('', $nonce, 'Slice 1 C2 (' . $shape . '): nonce must be non-empty.');
                self::assertSame($fx['user_id'], get_current_user_id(), 'precondition: nonce is verified as the authenticated patient.');
                self::assertNotFalse(
                    wp_verify_nonce($nonce, 'wp_rest'),
                    'Slice 1 C2 (' . $shape . '): nonce must verify for action "wp_rest" as the authenticated patient.'
                );

                // Composition (apiUrl rule) must resolve to the existing B4 route under this permalink shape.
                $composed = $this->composeApiUrl((string) $config['rest_root'], str_replace('{id}', (string) $fx['appointment_id'], (string) $config['cancel_path']));
                self::assertSame(
                    $expectedRoute,
                    $this->restRouteFromUrl($composed),
                    'Slice 1 C2 (' . $shape . '): rest_root + cancel_path must resolve to the existing B4 route. Composed: ' . $composed
                );

                $lastConfig = $config;
                $lastComposedUrl = $composed;
            }
        } finally {
            $this->set_permalink_structure($original);
            $this->permalinkStructureToRestore = null;
        }

        // Sufficiency: the published config alone (route + nonce) drives the EXISTING B4 route successfully.
        self::assertIsArray($lastConfig, 'precondition: config captured');
        $response = $this->restPostAs($fx['user_id'], $this->restRouteFromUrl($lastComposedUrl), (string) $lastConfig['nonce']);
        self::assertSame(
            200,
            $response->get_status(),
            'Slice 1 C2: config-derived route + published nonce must cancel via existing B4. Body: ' . wp_json_encode($response->get_data())
        );
        self::assertSame('cancelled_by_patient', (string) ($response->get_data()['data']['status'] ?? ''), 'Slice 1 C2: B4 must report cancelled_by_patient.');
    }

    // =================================================================
    // G1 — GUARD / POSITIVE CONTROL (must pass today): backend B4 accepts the fixture
    // =================================================================

    public function testPositiveControlExistingB4RouteCancelsOwnConfirmedUpcomingAppointment(): void
    {
        $fx = $this->buildOwnedConfirmedUpcomingAppointmentFixture('g1');
        $this->assertFixtureMaterialized($fx);

        $route = '/' . self::NS . '/appointments/' . $fx['appointment_id'] . '/cancel';

        // B4 requires the wp_rest nonce (CSRF) — this is why C2 must publish one.
        $noNonce = $this->restPostAs($fx['user_id'], $route, null);
        self::assertSame(403, $noNonce->get_status(), 'GUARD: B4 without X-WP-Nonce must be rejected. Body: ' . wp_json_encode($noNonce->get_data()));
        self::assertSame('CLINIC_INVALID_NONCE', (string) ($noNonce->get_data()['code'] ?? ''), 'GUARD: B4 without nonce → CLINIC_INVALID_NONCE.');
        self::assertSame('confirmed', $this->appointmentStatus($fx['appointment_id']), 'GUARD: a rejected call must not mutate the appointment.');

        // B4 with the patient's wp_rest nonce cancels the OWN confirmed upcoming appointment.
        wp_set_current_user($fx['user_id']);
        $ok = $this->restPostAs($fx['user_id'], $route, wp_create_nonce('wp_rest'));
        self::assertSame(200, $ok->get_status(), 'GUARD: existing B4 must accept the fixture (own, confirmed, upcoming). Body: ' . wp_json_encode($ok->get_data()));
        $data = $ok->get_data();
        self::assertSame($fx['appointment_id'], (int) ($data['data']['appointment_id'] ?? 0), 'GUARD: B4 envelope data.appointment_id.');
        self::assertSame('cancelled_by_patient', (string) ($data['data']['status'] ?? ''), 'GUARD: B4 envelope data.status.');
        self::assertSame('cancelled_by_patient', $this->appointmentStatus($fx['appointment_id']), 'GUARD: cancellation must be persisted.');

        // The portal (same real render path) now lists that appointment under history, not upcoming.
        $html = $this->renderPortalAs($fx['user_id']);
        $posHistory = strpos($html, self::HEADING_HISTORY);
        $posRef = strpos($html, $fx['reference_code']);
        self::assertNotFalse($posHistory, 'GUARD: portal must render the history heading.');
        self::assertNotFalse($posRef, 'GUARD: portal must still list the cancelled appointment (history).');
        self::assertGreaterThan($posHistory, $posRef, 'GUARD: after cancellation the appointment must be listed under «' . self::HEADING_HISTORY . '».');
    }

    // =================================================================
    // Fixture — real pure patient + Clinic-level link + owned confirmed upcoming appointment
    // =================================================================

    /**
     * @return array{
     *     clinic_id: int, location_id: int, clinician_id: int, user_id: int, patient_id: int, mobile: string,
     *     appointment_id: int, reference_code: string, slot_date: string, slot_time: string,
     *     history_appointment_id: int, history_reference_code: string
     * }
     */
    private function buildOwnedConfirmedUpcomingAppointmentFixture(string $tag): array
    {
        // Positive control: the portal calls App::settings(), which resolves the single seeded Clinic
        // (SystemClinicResolver fails closed otherwise). Ids are read dynamically — never hardcoded.
        $clinicCount = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'), []);
        self::assertSame(1, $clinicCount, 'precondition: exactly one seeded Clinic (portal/App::settings() requires single-clinic install).');

        $scope = App::scope();
        self::assertSame(ClinicScope::SOURCE_SYSTEM_SINGLE, $scope->source, 'precondition: scope resolves from the single seeded Clinic.');
        $clinicId = $scope->clinicId;
        self::assertGreaterThan(0, $clinicId, 'precondition: seeded Clinic id.');

        $locationId = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
            [$clinicId]
        );
        self::assertGreaterThan(0, $locationId, 'precondition: primary Location of the seeded Clinic (migration 0011 backfill).');

        $now = App::db()->nowUtcSql();

        $clinicianId = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $clinicId,
            'full_name' => 'Dr P9S1 ' . $tag,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%d', '%s', '%s'], 'clinician');

        $mobile = $this->mobileFor($tag);
        $patientId = $this->insertRow('cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => 'MR-P9S1-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            'first_name' => 'Portal',
            'last_name' => 'Patient ' . $tag,
            'mobile' => $mobile,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], 'patient');

        // Pure patient: exactly the portal-expected role, nothing else.
        $userId = (int) wp_create_user('p9s1_' . $tag . '_' . uniqid('', false), 'pass-not-used-123', uniqid('p9s1_' . $tag . '_', true) . '@test.local');
        self::assertGreaterThan(0, $userId, 'precondition: WP user created.');
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'precondition: WP user readable.');
        $user->set_role(RolesAndCapabilities::ROLE_PATIENT);

        // Durable Clinic-level Patient ↔ WP user link (the portal's listMine authority).
        $this->insertRow('cpms_patient_user_links', [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'wp_user_id' => $userId,
            'mobile_at_link' => $mobile,
            'is_primary' => 1,
            'linked_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%d', '%s'], 'patient_user_link');

        // Owned, CONFIRMED, UPCOMING appointment (+5 days: outside any cancel-deadline window).
        $slotDate = $this->ymdDaysOffset(5);
        $slotTime = '10:00:00';
        $slotId = $this->insertSlot($clinicId, $locationId, $clinicianId, $slotDate, $slotTime, 1);
        $referenceCode = $this->referenceCode($tag . 'u');
        $appointmentId = $this->insertAppointment($clinicId, $locationId, $clinicianId, $patientId, $userId, $slotId, $slotDate, $slotTime, 'confirmed', $referenceCode, $now);

        // Negative-control row: past + already cancelled by the patient → portal history section.
        $historyDate = $this->ymdDaysOffset(-3);
        $historyTime = '09:00:00';
        $historySlotId = $this->insertSlot($clinicId, $locationId, $clinicianId, $historyDate, $historyTime, 0);
        $historyReference = $this->referenceCode($tag . 'h');
        $historyAppointmentId = $this->insertAppointment($clinicId, $locationId, $clinicianId, $patientId, $userId, $historySlotId, $historyDate, $historyTime, 'cancelled_by_patient', $historyReference, $now);

        return [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'user_id' => $userId,
            'patient_id' => $patientId,
            'mobile' => $mobile,
            'appointment_id' => $appointmentId,
            'reference_code' => $referenceCode,
            'slot_date' => $slotDate,
            'slot_time' => $slotTime,
            'history_appointment_id' => $historyAppointmentId,
            'history_reference_code' => $historyReference,
        ];
    }

    /**
     * Positive controls executed BEFORE any HTML assertion — prove the fixture is what the
     * portal expects (so R1/R2 can only fail for the missing UI/config contract).
     *
     * @param array<string, mixed> $fx
     */
    private function assertFixtureMaterialized(array $fx): void
    {
        // WP user exists with exactly the portal-expected patient role.
        $user = get_userdata((int) $fx['user_id']);
        self::assertNotFalse($user, 'positive control: WP user exists.');
        self::assertSame([RolesAndCapabilities::ROLE_PATIENT], array_values((array) $user->roles), 'positive control: pure patient role only.');
        self::assertTrue(PatientPortalPage::isPatientOnly((int) $fx['user_id']), 'positive control: PatientPortalPage::isPatientOnly() accepts the user.');

        // Clinic-level Patient link belongs to that WP user and that Clinic.
        $link = App::db()->fetchRow(
            'SELECT clinic_id, wp_user_id FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE patient_id = %d LIMIT 1',
            [(int) $fx['patient_id']]
        );
        self::assertNotNull($link, 'positive control: Patient link row exists.');
        self::assertSame((int) $fx['user_id'], (int) $link['wp_user_id'], 'positive control: link belongs to the fixture WP user.');
        self::assertSame((int) $fx['clinic_id'], (int) $link['clinic_id'], 'positive control: link belongs to the seeded Clinic.');

        // Appointment exists, belongs to the linked Patient, is confirmed and upcoming.
        $appt = App::db()->fetchRow(
            'SELECT clinic_id, patient_id, status, slot_date, reference_code FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d LIMIT 1',
            [(int) $fx['appointment_id']]
        );
        self::assertNotNull($appt, 'positive control: appointment row exists.');
        self::assertSame((int) $fx['patient_id'], (int) $appt['patient_id'], 'positive control: appointment.patient_id = linked Patient.');
        self::assertSame('confirmed', (string) $appt['status'], 'positive control: appointment status = confirmed.');
        self::assertSame((int) $fx['clinic_id'], (int) $appt['clinic_id'], 'positive control: appointment belongs to the seeded Clinic.');
        self::assertGreaterThan(0, strcmp((string) $appt['slot_date'], gmdate('Y-m-d')), 'positive control: appointment is upcoming (slot_date after today).');
        self::assertSame((string) $fx['reference_code'], (string) $appt['reference_code'], 'positive control: reference_code persisted.');

        // The portal's own data path (BookingService::listMine) includes it as confirmed/upcoming.
        $rows = App::bookingService()->listMine(
            (int) $fx['user_id'],
            gmdate('Y-m-d', strtotime('-365 days')),
            gmdate('Y-m-d', strtotime('+180 days'))
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        self::assertArrayHasKey((int) $fx['appointment_id'], $byId, 'positive control: listMine() includes the appointment.');
        self::assertSame('confirmed', (string) $byId[(int) $fx['appointment_id']]['status'], 'positive control: listMine() view status = confirmed.');
        self::assertGreaterThanOrEqual(gmdate('Y-m-d'), (string) $byId[(int) $fx['appointment_id']]['date'], 'positive control: listMine() view date is upcoming.');
        self::assertSame((string) $fx['reference_code'], (string) $byId[(int) $fx['appointment_id']]['reference_code'], 'positive control: listMine() view reference_code.');
        self::assertArrayHasKey((int) $fx['history_appointment_id'], $byId, 'positive control: listMine() includes the cancelled history row.');
        self::assertSame('cancelled_by_patient', (string) $byId[(int) $fx['history_appointment_id']]['status'], 'positive control: history row status.');
    }

    // =================================================================
    // Render + markup helpers (real PatientPortalPage path)
    // =================================================================

    private function renderPortalAs(int $userId): string
    {
        wp_set_current_user($userId);
        ob_start();
        try {
            PatientPortalPage::render();
        } finally {
            $html = (string) ob_get_clean();
        }
        self::assertStringContainsString(self::TITLE_MY_APPOINTMENTS, $html, 'positive control: real PatientPortalPage::render() path reached (page title).');

        return $html;
    }

    /**
     * @param array<string, mixed> $fx
     */
    private function assertPortalListsFixtureRows(string $html, array $fx): void
    {
        $posUpcoming = strpos($html, self::HEADING_UPCOMING);
        $posHistory = strpos($html, self::HEADING_HISTORY);
        $posRef = strpos($html, (string) $fx['reference_code']);
        $posHistoryRef = strpos($html, (string) $fx['history_reference_code']);

        self::assertNotFalse($posUpcoming, 'positive control: upcoming heading rendered.');
        self::assertNotFalse($posHistory, 'positive control: history heading rendered.');
        self::assertNotFalse($posRef, 'positive control: the confirmed upcoming appointment is listed (reference_code).');
        self::assertNotFalse($posHistoryRef, 'positive control: the cancelled history appointment is listed (reference_code).');
        self::assertTrue($posUpcoming < $posRef && $posRef < $posHistory, 'positive control: the confirmed appointment is listed in the UPCOMING table.');
        self::assertGreaterThan($posHistory, $posHistoryRef, 'positive control: the cancelled appointment is listed in the HISTORY table.');
    }

    private function tableRowContaining(string $html, string $needle, string $label): string
    {
        $count = preg_match_all('/<tr\b[^>]*>(?:(?!<\/tr>).)*<\/tr>/su', $html, $m);
        self::assertGreaterThan(0, (int) $count, 'positive control: portal renders table rows.');
        foreach ($m[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }
        self::fail('positive control: no <tr> contains ' . $needle . ' (' . $label . ').');
    }

    /**
     * @return list<string> opening tags carrying data-role="cancel-appointment"
     */
    private function cancelActionTags(string $fragment): array
    {
        preg_match_all('/<[a-zA-Z][^>]*\sdata-role="' . preg_quote(self::CANCEL_ACTION_ROLE, '/') . '"[^>]*>/su', $fragment, $m);

        return $m[0];
    }

    private function attributeValue(string $tag, string $attribute): ?string
    {
        if (preg_match('/\s' . preg_quote($attribute, '/') . '="([^"]*)"/su', $tag, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function hasBooleanAttribute(string $tag, string $attribute): bool
    {
        return preg_match('/\s' . preg_quote($attribute, '/') . '(?=[\s\/>=])/iu', $tag) === 1;
    }

    /** Accessible name: aria-label wins; otherwise the element's text content. */
    private function accessibleName(string $row, string $openingTag): string
    {
        $ariaLabel = $this->attributeValue($openingTag, 'aria-label');
        if ($ariaLabel !== null && trim($ariaLabel) !== '') {
            return trim($ariaLabel);
        }
        $tagName = preg_match('/^<([a-zA-Z0-9]+)/', $openingTag, $t) === 1 ? strtolower($t[1]) : 'button';
        $pattern = '/' . preg_quote($openingTag, '/') . '(.*?)<\/' . preg_quote($tagName, '/') . '>/su';
        if (preg_match($pattern, $row, $m) !== 1) {
            return '';
        }

        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return list<string> raw payloads of <script type="application/json" class="…cpms-patient-portal__config…">
     */
    private function configScriptPayloads(string $html): array
    {
        $payloads = [];
        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/su', $html, $m, PREG_SET_ORDER) < 1) {
            return $payloads;
        }
        foreach ($m as $script) {
            $attrs = ' ' . $script[1];
            if (preg_match('/\stype="([^"]*)"/i', $attrs, $t) !== 1 || strtolower(trim($t[1])) !== 'application/json') {
                continue;
            }
            if (preg_match('/\sclass="([^"]*)"/i', $attrs, $c) !== 1) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($c[1])) ?: [];
            if (!in_array(self::CONFIG_CLASS, $classes, true)) {
                continue;
            }
            $payloads[] = $script[2];
        }

        return $payloads;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertNoAuthorityIdentifiers(array $config, string $shape): void
    {
        $stack = [$config];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node as $key => $value) {
                self::assertNotContains(
                    strtolower((string) $key),
                    ['clinic_id', 'patient_id'],
                    'Slice 1 C2 (' . $shape . '): config must not expose ' . $key . ' — ownership authority is server-side (B4).'
                );
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }

    // =================================================================
    // URL composition (mirrors assets/js/cpms-public-booking.js apiUrl()) + REST dispatch
    // =================================================================

    private function composeApiUrl(string $restRoot, string $path): string
    {
        if (str_contains($restRoot, '?') && str_contains($path, '?')) {
            $path = preg_replace('/\?/', '&', $path, 1) ?? $path;
        }

        return $restRoot . $path;
    }

    /** Resolves the REST route a composed URL targets, under Plain (?rest_route=) or Pretty (/wp-json/) shape. */
    private function restRouteFromUrl(string $url): string
    {
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            parse_str($query, $params);
            if (isset($params['rest_route']) && is_string($params['rest_route'])) {
                return '/' . ltrim($params['rest_route'], '/');
            }
        }
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
        $prefix = '/' . rest_get_url_prefix() . '/';
        $at = strpos($path, $prefix);
        if ($at === false) {
            return '';
        }

        return '/' . ltrim(substr($path, $at + strlen($prefix)), '/');
    }

    private function restPostAs(int $userId, string $route, ?string $nonce): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('POST', $route);
        if ($nonce !== null) {
            $request->set_header('X-WP-Nonce', $nonce);
        }

        return rest_do_request($request);
    }

    // =================================================================
    // Row writers / readers (columns exist on live main — same sets as Phase 7/8 RED suites)
    // =================================================================

    private function insertSlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time, int $booked): int
    {
        $now = App::db()->nowUtcSql();

        return $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'capacity' => 1,
            'booked_count' => $booked,
            'held_count' => 0,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'], 'slot');
    }

    private function insertAppointment(
        int $clinicId,
        int $locationId,
        int $clinicianId,
        int $patientId,
        int $userId,
        int $slotId,
        string $date,
        string $time,
        string $status,
        string $referenceCode,
        string $now
    ): int {
        $endTime = (new \DateTimeImmutable($date . ' ' . $time, new \DateTimeZone(self::TZ_TEHRAN)))
            ->add(new \DateInterval('PT20M'))->format('H:i:s');
        $isCancelled = str_starts_with($status, 'cancelled_');

        return $this->insertRow('cpms_appointments', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'reference_code' => $referenceCode,
            'patient_id' => $patientId,
            'clinician_id' => $clinicianId,
            'slot_id' => $slotId,
            'wp_user_id' => $userId,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'slot_end_time' => $endTime,
            'status' => $status,
            'is_walkin_express' => 0,
            'confirmed_at' => $now,
            'cancelled_at' => $isCancelled ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'], 'appointment(' . $status . ')');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $formats
     */
    private function insertRow(string $table, array $data, array $formats, string $label): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats);
        self::assertTrue((bool) $ok, 'fixture ' . $label . ' insert failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture ' . $label . ' id must be positive/dynamic.');

        return $id;
    }

    private function appointmentStatus(int $appointmentId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
    }

    private function referenceCode(string $suffix): string
    {
        // VARCHAR(24) UNIQUE — keep it short and random per row.
        return 'P9S1' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $suffix) ?? '') . bin2hex(random_bytes(4));
    }

    private function mobileFor(string $suffix): string
    {
        // Deterministic valid Iranian mobile per test suffix (digits only, prefix 0919 to avoid Phase 8 fixtures).
        $hash = substr(md5('p9s1-' . $suffix), 0, 7);
        $hash = strtr($hash, ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']);

        return '0919' . substr($hash, 0, 7);
    }

    private function ymdDaysOffset(int $days): string
    {
        return gmdate('Y-m-d', time() + $days * 86400);
    }
}

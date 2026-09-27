<?php
/**
 * Phase 11 Slice 2 (bounded) — Staff Portal Reception: Clinic Patient Search
 * (read-only selection) — TEST-ONLY RED.
 *
 * Slice: an authorized secretary working in the EXISTING Staff Portal
 * Reception module searches for an EXISTING patient of the trusted Clinic and
 * selects one read-only. Nothing is created, edited, booked, checked in or
 * enqueued; the selection is presentation-only and creates no authority.
 *
 * Live reconstruction (verified, not assumed — 2026-09-27):
 * - authoritative main = origin/main = 61a90ffd8987c6ecf1db7d61e672235ad64763cc;
 *   open PRs = 0; latest migration = 2026_09_26_0023_handwriting_prescription_paper.php
 *   (this RED adds/reserves no migration).
 * - Phase 11 = STARTED / IN PROGRESS — NOT CLOSED; Slice 1 Arrival Board merged
 *   via PR #133 (ReceptionPortalController + templates/staff-reception.php).
 * - established search contract (reused, never duplicated):
 *   GET /clinic/v1/patients/search → PatientService::search (trim, min 2 chars
 *   ⇒ 400 CLINIC_VALIDATION_FAILED, limit clamped 1..50, default 25) →
 *   PatientRepository::search (trusted clinic_id, status=active, LIKE on
 *   first_name/last_name/mobile/national_id/mrn) → searchView (bounded keys,
 *   masked national ID). The shared route gates on cpms_patient_read only —
 *   it does NOT carry the Reception secretary-role boundary.
 *
 * The RED fails ONLY because the Reception patient-search boundary/UI is
 * missing: bootstrap, migrations and fixtures succeed; the trusted-Clinic
 * secretary path is reached (the shared established search answers 200 for
 * the same secretary in the same fixture); the intended Reception search path
 * answers with the canonical missing-route fingerprint and the rendered
 * Reception module carries no search surface.
 *
 * TEST GROUP MAP:
 *  S1  Secretary searches inside Reception (UI surface + REST) ..... INTENDED RED
 *  S2  Insufficient role / capability deny / missing nonce denied .. INTENDED RED
 *  S3  Suspended / missing membership denied ...................... INTENDED RED
 *  S4  Clinic A search never returns a Clinic B patient ........... INTENDED RED
 *  S5  Location A vs B (same Clinic) does not change results ...... INTENDED RED
 *  S6  Query < 2 chars follows the established bounded behavior ... INTENDED RED
 *  S7  first name / last name / mobile / national ID / MRN match .. INTENDED RED
 *  S8  Bounded result limit preserved ............................. INTENDED RED
 *  S9  Masked national ID + no clinical/private keys .............. INTENDED RED
 *  S10 Search/selection performs no mutation; read-only route ..... INTENDED RED
 *
 * Slice 1 Arrival Board regression stays covered by the existing
 * Phase11StaffPortalReceptionArrivalRedTest / Phase11ReceptionPartialArrivalRecoveryRedTest.
 *
 * Excluded by design: patient create/edit, walk-in, booking, check-in from a
 * search result, finance, patient detail/clinical payload, Organization
 * patient identity, new role/capability, migrations, new patient query/service.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase11ReceptionPatientSearchRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const SEARCH = '/clinic/v1/staff/portal/reception/patients/search';
    private const SHARED_SEARCH = '/clinic/v1/patients/search';

    /** Established searchView presentation — the ONLY keys a result may carry. */
    private const SEARCH_KEYS = ['birth_date', 'first_name', 'gender', 'id', 'last_name', 'mobile', 'mrn', 'national_id', 'status'];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();
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
        parent::tearDown();
    }

    // ============ S1 — Secretary searches inside Reception ============

    public function testS1_SecretaryCanSearchInsideReception(): void
    {
        $fx = $this->makeStage('s1');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $pid = $this->insertPatient($fx['clinic'], 'Shirin', 'Kavian', '09121110001', '0012345678', 'MR-S1-0001');

        // Control: the trusted-Clinic secretary path is reached — the SHARED
        // established search answers for this very secretary and fixture.
        wp_set_current_user($fx['secretary']);
        $shared = $this->dispatch('GET', self::SHARED_SEARCH, ['q' => 'Kavian'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $shared->get_status(), 'S1 control: shared established search reachable for the secretary');
        self::assertContains($pid, $this->ids($shared), 'S1 control: shared search finds the fixture patient');

        // UI: the Reception module carries the search surface.
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="reception-app"', $html, 'S1: reception module renders');
        self::assertStringContainsString('data-role="sr-search"', $html, 'S1: reception exposes the patient search panel');
        self::assertStringContainsString('data-role="sr-search-input"', $html, 'S1: reception exposes the search input');
        self::assertStringContainsString('data-role="sr-search-results"', $html, 'S1: reception exposes the bounded results list');
        self::assertStringContainsString('/staff/portal/reception/patients/search', $html, 'S1: reception search uses the reception boundary');

        // REST: the Reception boundary answers with the established search result.
        $res = $this->dispatch('GET', self::SEARCH, ['q' => 'Kavian'], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertSame(200, $res->get_status(), 'S1: reception search answers — got ' . $res->get_status() . '/' . $this->errCode($res));
        self::assertSame([$pid], $this->ids($res), 'S1: reception search returns the Clinic patient');
    }

    // ============ S2 — Insufficient role / capability / nonce ============

    public function testS2_InsufficientRoleOrCapabilityDenied(): void
    {
        $fx = $this->makeStage('s2');
        $this->insertPatient($fx['clinic'], 'Arman', 'Deny', '09121110002', null, 'MR-S2-0001');

        // A. Doctor holds cpms_patient_read but is NOT the Reception role.
        $this->seedMembership($fx['doctor'], $fx['clinic'], 'cpms_doctor');
        wp_set_current_user($fx['doctor']);
        $doc = $this->dispatch('GET', self::SEARCH, ['q' => 'Deny'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $doc->get_status(), 'S2: doctor denied on the reception search boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($doc), 'S2: doctor denial envelope');

        // B. Accountant with ACTIVE membership — membership alone is not permission.
        $acct = $this->makeUser('qa_s2_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        $this->seedMembership($acct, $fx['clinic'], 'cpms_accountant');
        wp_set_current_user($acct);
        $acc = $this->dispatch('GET', self::SEARCH, ['q' => 'Deny'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $acc->get_status(), 'S2: accountant denied');

        // C. Secretary + ACTIVE membership but clinic-scoped patient-read DENY.
        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->set_capability($mid, RolesAndCapabilities::PATIENT_READ, 'deny');
        wp_set_current_user($fx['secretary']);
        $deny = $this->dispatch('GET', self::SEARCH, ['q' => 'Deny'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $deny->get_status(), 'S2: scoped cpms_patient_read deny blocks reception search');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($deny), 'S2: capability denial envelope');
        self::assertSame([], $this->ids($deny), 'S2: denial carries no patient rows');

        // D. Missing nonce — CSRF layer preserved.
        App::membership_service()->remove_capability($mid, RolesAndCapabilities::PATIENT_READ);
        $noNonce = $this->dispatch('GET', self::SEARCH, ['q' => 'Deny'], $this->scopeHeaders($fx['clinic']), false);
        self::assertSame(403, $noNonce->get_status(), 'S2: missing nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($noNonce), 'S2: nonce denial code');

        // E. Anonymous.
        wp_set_current_user(0);
        $anon = $this->dispatch('GET', self::SEARCH, ['q' => 'Deny'], $this->scopeHeaders($fx['clinic']));
        self::assertContains($anon->get_status(), [401, 403], 'S2: anonymous denied');
        self::assertSame([], $this->ids($anon), 'S2: anonymous gets no rows');
    }

    // ============ S3 — Suspended / missing membership ============

    public function testS3_SuspendedOrMissingMembershipDenied(): void
    {
        $fx = $this->makeStage('s3');
        $this->insertPatient($fx['clinic'], 'Nima', 'Member', '09121110003', null, 'MR-S3-0001');

        $mid = $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        App::membership_service()->suspend_membership($mid);
        wp_set_current_user($fx['secretary']);
        $susp = $this->dispatch('GET', self::SEARCH, ['q' => 'Member'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $susp->get_status(), 'S3: suspended membership denied');
        self::assertSame([], $this->ids($susp), 'S3: suspended membership gets no rows');

        $loner = $this->makeUser('qa_s3_loner', RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($loner);
        $none = $this->dispatch('GET', self::SEARCH, ['q' => 'Member'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(403, $none->get_status(), 'S3: secretary without membership denied');
        self::assertSame([], $this->ids($none), 'S3: no membership gets no rows');
    }

    // ============ S4 — Clinic isolation ============

    public function testS4_ClinicASearchNeverReturnsClinicBPatient(): void
    {
        $a = $this->makeStage('s4a');
        $b = $this->makeStage('s4b');
        $this->seedMembership($a['secretary'], $a['clinic'], 'cpms_secretary');
        $pa = $this->insertPatient($a['clinic'], 'Twin', 'Samename', '09121110041', '1112223334', 'MR-S4-TWIN');
        $pb = $this->insertPatient($b['clinic'], 'Twin', 'Samename', '09121110042', '1112223335', 'MR-S4-TWIN-B');

        wp_set_current_user($a['secretary']);
        foreach (['Samename', 'Twin', '0912111004', '111222333', 'MR-S4-TWIN'] as $q) {
            $res = $this->dispatch('GET', self::SEARCH, ['q' => $q], $this->scopeHeaders($a['clinic'], $a['loc_a']));
            self::assertSame(200, $res->get_status(), 'S4: search answers for "' . $q . '"');
            self::assertSame([$pa], $this->ids($res), 'S4: Clinic A search returns only the Clinic A patient for "' . $q . '"');
            self::assertNotContains($pb, $this->ids($res), 'S4: Clinic B patient absent for "' . $q . '"');
        }

        // Raw Clinic B selector from a Clinic A secretary fails closed (no membership in B).
        $cross = $this->dispatch('GET', self::SEARCH, ['q' => 'Samename'], $this->scopeHeaders($b['clinic']));
        self::assertNotSame(200, $cross->get_status(), 'S4: foreign Clinic selector fails closed');
        self::assertSame([], $this->ids($cross), 'S4: foreign Clinic selector returns no rows');
    }

    // ============ S5 — Location selection does not filter ============

    public function testS5_LocationSelectionDoesNotChangeClinicSearch(): void
    {
        $fx = $this->makeStage('s5');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $p1 = $this->insertPatient($fx['clinic'], 'Loc', 'Neutral One', '09121110051', null, 'MR-S5-0001');
        $p2 = $this->insertPatient($fx['clinic'], 'Loc', 'Neutral Two', '09121110052', null, 'MR-S5-0002');
        // p2 is only ever booked at Location B — it must still appear from Location A.
        $this->insertAppointment($fx['clinic'], $fx['loc_b'], $p2, $fx['clinician']);

        wp_set_current_user($fx['secretary']);
        $atA = $this->dispatch('GET', self::SEARCH, ['q' => 'Neutral'], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        $atB = $this->dispatch('GET', self::SEARCH, ['q' => 'Neutral'], $this->scopeHeaders($fx['clinic'], $fx['loc_b']));
        $noLoc = $this->dispatch('GET', self::SEARCH, ['q' => 'Neutral'], $this->scopeHeaders($fx['clinic']));

        self::assertSame(200, $atA->get_status(), 'S5: Location A search answers — ' . $this->errCode($atA));
        self::assertSame(200, $atB->get_status(), 'S5: Location B search answers — ' . $this->errCode($atB));
        self::assertSame(200, $noLoc->get_status(), 'S5: Clinic search does not require a Location — ' . $this->errCode($noLoc));
        $want = [$p1, $p2];
        sort($want);
        self::assertSame($want, $this->sortedIds($atA), 'S5: Location A sees all Clinic patients');
        self::assertSame($this->sortedIds($atA), $this->sortedIds($atB), 'S5: Location B returns the identical Clinic result');
        self::assertSame($this->sortedIds($atA), $this->sortedIds($noLoc), 'S5: no Location selection returns the identical Clinic result');
        self::assertSame($this->payload($atA), $this->payload($atB), 'S5: payloads are identical across Locations');
    }

    // ============ S6 — Minimum query length ============

    public function testS6_ShortQueryFollowsEstablishedBoundedBehavior(): void
    {
        $fx = $this->makeStage('s6');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $this->insertPatient($fx['clinic'], 'Ab', 'Short', '09121110061', null, 'MR-S6-0001');
        wp_set_current_user($fx['secretary']);

        foreach (['A', ' A ', '', '  '] as $q) {
            $res = $this->dispatch('GET', self::SEARCH, ['q' => $q], $this->scopeHeaders($fx['clinic']));
            self::assertSame(400, $res->get_status(), 'S6: query "' . $q . '" rejected like the established search');
            self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($res), 'S6: established validation code');
            self::assertSame([], $this->ids($res), 'S6: short query returns no rows');
        }
        $two = $this->dispatch('GET', self::SEARCH, ['q' => 'Ab'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $two->get_status(), 'S6: two characters are enough');
    }

    // ============ S7 — Established matching fields ============

    public function testS7_MatchesEstablishedFields(): void
    {
        $fx = $this->makeStage('s7');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $target = $this->insertPatient($fx['clinic'], 'Firoozeh', 'Lastnamex', '09357778899', '4445556667', 'MR-S7-ZQ42');
        $decoy = $this->insertPatient($fx['clinic'], 'Other', 'Person', '09121110071', '9998887776', 'MR-S7-0002');
        wp_set_current_user($fx['secretary']);

        $cases = [
            'first name'  => 'Firoozeh',
            'last name'   => 'Lastnamex',
            'mobile'      => '0935777',
            'national ID' => '4445556667',
            'MRN'         => 'ZQ42',
        ];
        foreach ($cases as $label => $q) {
            $res = $this->dispatch('GET', self::SEARCH, ['q' => $q], $this->scopeHeaders($fx['clinic']));
            self::assertSame(200, $res->get_status(), 'S7: ' . $label . ' search answers');
            self::assertSame([$target], $this->ids($res), 'S7: ' . $label . ' search finds exactly the target');
            self::assertNotContains($decoy, $this->ids($res), 'S7: ' . $label . ' search excludes the decoy');
        }
    }

    // ============ S8 — Bounded result limit ============

    public function testS8_ResultLimitStaysBounded(): void
    {
        $fx = $this->makeStage('s8');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        for ($i = 0; $i < 55; $i++) {
            $this->insertPatient($fx['clinic'], 'Bulk', 'Bounded ' . $i, '0913' . sprintf('%07d', 2000000 + $i), null, 'MR-S8-' . sprintf('%04d', $i));
        }
        wp_set_current_user($fx['secretary']);

        $default = $this->dispatch('GET', self::SEARCH, ['q' => 'Bounded'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $default->get_status(), 'S8: default search answers');
        self::assertCount(25, $this->ids($default), 'S8: established default limit (25) preserved');

        $huge = $this->dispatch('GET', self::SEARCH, ['q' => 'Bounded', 'limit' => 5000], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $huge->get_status(), 'S8: oversized limit answers');
        self::assertLessThanOrEqual(50, count($this->ids($huge)), 'S8: established hard cap (50) preserved');
    }

    // ============ S9 — Privacy ============

    public function testS9_NationalIdMaskedAndNoClinicalFields(): void
    {
        $fx = $this->makeStage('s9');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $pid = $this->insertPatient($fx['clinic'], 'Private', 'Clinicalx', '09121110091', '0081234567', 'MR-S9-0001');
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_patients SET medication_allergies = %s, medical_history = %s, surgery_history = %s, address = %s, emergency_contact_phone = %s WHERE id = %d',
            '["penicillin-SECRET"]',
            'HISTORY-SECRET',
            'SURGERY-SECRET',
            'ADDRESS-SECRET',
            '09990000000',
            $pid
        ));
        wp_set_current_user($fx['secretary']);

        $res = $this->dispatch('GET', self::SEARCH, ['q' => 'Clinicalx'], $this->scopeHeaders($fx['clinic']));
        self::assertSame(200, $res->get_status(), 'S9: search answers');
        $rows = $this->payload($res);
        self::assertCount(1, $rows, 'S9: one row');
        $row = (array) $rows[0];
        $keys = array_keys($row);
        sort($keys);
        self::assertSame(self::SEARCH_KEYS, $keys, 'S9: result carries only the established search presentation');
        self::assertSame('***4567', (string) $row['national_id'], 'S9: national ID masked per established presentation');

        $json = (string) wp_json_encode($res->get_data());
        foreach (['0081234567', 'SECRET', '09990000000', 'medication_allergies', 'medical_history', 'address', 'emergency_contact'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'S9: payload must not expose "' . $needle . '"');
        }
    }

    // ============ S10 — Read-only: no mutation, no write route ============

    public function testS10_SearchPerformsNoMutationAndIsReadOnly(): void
    {
        $fx = $this->makeStage('s10');
        $this->seedMembership($fx['secretary'], $fx['clinic'], 'cpms_secretary');
        $pid = $this->insertPatient($fx['clinic'], 'Readonly', 'Selectx', '09121110101', null, 'MR-S10-0001');
        wp_set_current_user($fx['secretary']);

        $before = $this->fingerprint($fx['clinic']);
        $res = $this->dispatch('GET', self::SEARCH, ['q' => 'Selectx'], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertSame(200, $res->get_status(), 'S10: search answers — ' . $this->errCode($res));
        self::assertSame([$pid], $this->ids($res), 'S10: target found');
        self::assertSame($before, $this->fingerprint($fx['clinic']), 'S10: search creates/changes no patient/visit/appointment/membership row');

        // The boundary is read-only: no write verb exists on the search route.
        $post = $this->dispatch('POST', self::SEARCH, ['q' => 'Selectx', 'patient_id' => $pid], $this->scopeHeaders($fx['clinic'], $fx['loc_a']));
        self::assertNotSame(200, $post->get_status(), 'S10: POST on the search route is not accepted');
        self::assertSame($before, $this->fingerprint($fx['clinic']), 'S10: rejected write leaves durable state untouched');

        // Selecting a result is client-only: the UI script never posts the
        // selected patient anywhere (no create/edit/book/check-in coupling).
        $html = $this->renderReception($fx['secretary']);
        self::assertStringContainsString('data-role="sr-search-selected"', $html, 'S10: read-only selected-state surface exists');
    }

    // ============ Helpers ============

    /**
     * @return array<string, int>
     */
    private function makeStage(string $tag): array
    {
        $org = $this->insertOrg('Srch Org ' . $tag);
        $clinic = $this->insertClinic('Srch Clinic ' . $tag, $org);
        $locA = $this->insertLocation($clinic, 'Srch A ' . $tag, 1);
        $locB = $this->insertLocation($clinic, 'Srch B ' . $tag, 0);
        $doctor = $this->makeUser('qa_' . $tag . '_srdoc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr Srch ' . $tag, $clinic, $doctor);
        $secretary = $this->makeUser('qa_' . $tag . '_srsec', RolesAndCapabilities::ROLE_SECRETARY);
        return [
            'clinic'    => $clinic,
            'loc_a'     => $locA,
            'loc_b'     => $locB,
            'doctor'    => $doctor,
            'clinician' => $clinician,
            'secretary' => $secretary,
        ];
    }

    private function seedMembership(int $userId, int $clinicId, string $roleKey): int
    {
        return (int) cpms_test_seed_membership($userId, $clinicId, $roleKey);
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

    private function dispatch(string $method, string $route, array $params = [], array $headers = [], bool $withNonce = true): WP_REST_Response
    {
        ScopeContext::clear();
        App::resetScope();
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

    /**
     * @return array<int|string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        if ($res->get_status() !== 200) {
            return [];
        }
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return [];
    }

    /**
     * @return list<int>
     */
    private function ids(WP_REST_Response $res): array
    {
        $ids = [];
        foreach ($this->payload($res) as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /**
     * @return list<int>
     */
    private function sortedIds(WP_REST_Response $res): array
    {
        $ids = $this->ids($res);
        sort($ids);
        return $ids;
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
     * Durable-state fingerprint used to prove search/selection mutates nothing.
     *
     * @return array<string, string>
     */
    private function fingerprint(int $clinicId): array
    {
        $db = App::db();
        $out = [];
        foreach (['cpms_patients', 'cpms_visits', 'cpms_appointments', 'cpms_clinic_memberships', 'cpms_visit_status_history'] as $table) {
            $out[$table] = (string) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table($table));
        }
        $out['patients_updated'] = (string) $db->fetchValue('SELECT COALESCE(MAX(updated_at), \'\') FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', [$clinicId]);
        return $out;
    }

    private function renderReception(int $userId): string
    {
        wp_set_current_user($userId);
        $url = StaffPortalShell::portal_url();
        self::assertNotEmpty($url, 'the canonical Staff Portal page exists');
        $path = (string) (wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        $this->go_to($path . ($query !== '' ? '?' . $query . '&' : '?') . 'cpms-module=reception');
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        $tpl = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'template_include must intercept with the plugin-owned Staff Portal template');
        ob_start();
        include $tpl;
        return (string) ob_get_clean();
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

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', $name, 'org-' . bin2hex(random_bytes(3)), 'active', $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertClinic(string $name, int $orgId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), 'Asia/Tehran', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), 'Asia/Tehran', $primary, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertClinician(string $name, int $clinicId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, 1, %s, %s)', $clinicId, $name, $wpUserId, $now, $now));
        return (int) $wpdb->insert_id;
    }

    private function insertPatient(int $clinicId, string $first, string $last, string $mobile, ?string $nationalId, string $mrn): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id'   => $clinicId,
            'mrn'         => $mrn,
            'first_name'  => $first,
            'last_name'   => $last,
            'mobile'      => $mobile,
            'national_id' => $nationalId,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        self::assertNotFalse($ok, 'patient fixture insert: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function insertAppointment(int $clinicId, int $locId, int $patientId, int $clinicianId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = '2026-03-14';
        $time = '10:00:00';
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)', $clinicId, $locId, $clinicianId, $date, $time, 20, 1, 1, 0, 1, 'manual', $now, $now));
        $slotId = (int) $wpdb->insert_id;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)', $clinicId, $locId, 'srch-' . bin2hex(random_bytes(3)), $patientId, $clinicianId, $slotId, 0, $date, $time, 20, $time, 'confirmed', 0, $now, $now, $now));
        return (int) $wpdb->insert_id;
    }
}

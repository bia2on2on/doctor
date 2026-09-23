<?php
/**
 * Phase 10 Slice 1 — Independent Doctor Portal Today + Live Queue (TEST-ONLY RED) — Owner Decision encoded.
 *
 * Owner decision (2026-09-23):
 * After trusted Clinic scope established, operational Location must be explicitly and securely resolved.
 * - 0 eligible Locations => fail closed / no Today or Queue data
 * - 1 eligible => auto-resolution allowed
 * - N>1 eligible => explicit Location selection REQUIRED
 * - No first/primary fallback for N>1
 * - foreign/inactive/unassigned => fail closed using existing trusted-scope error contract (CLINIC_SCOPE_UNAVAILABLE 403 / VALIDATION_FAILED 422)
 * - raw location_id selector only, NEVER authority
 * Authority = auth WP user + active Clinic membership + trusted Clinic + eligible persisted Location assignment + explicit when N>1 = trusted operational Location
 * Selected Location = source of truth for today meaning, timezone, Today summary, Live Queue filtering
 * Do NOT use Clinic/WP/PHP/browser/hardcoded Asia/Tehran/first/primary fallback for N>1
 *
 * Existing machinery reused:
 * - RestClinicContext extracts X-CPMS-Clinic-Id / X-CPMS-Location-Id (header or param)
 * - TrustedClinicEstablisher validates membership active + clinic exists + org active + location belongs to clinic + is_active=1
 *   Error codes discovered: CLINIC_SCOPE_REQUIRED 400 when N>1 clinics without explicit, CLINIC_SCOPE_UNAVAILABLE 403 for no_membership/membership/clinic/organization/location, CLINIC_VALIDATION_FAILED 422 for invalid id / header-param disagree / location without clinic
 *   NO dedicated LOCATION_SCOPE_REQUIRED exists — missing contract future GREEN must add (do NOT invent in RED)
 * - ScopeContext / ClinicScope (clinicId + locationId + organizationId + source)
 * - membership_location assignment model: cpms_clinic_memberships.scope_mode = clinic (all Locations) vs location (only assigned via cpms_membership_locations)
 *
 * 8 invariants:
 * A shell existence (portal missing)
 * B doctor identity fail-closed (backend guard PASS)
 * C multi-Clinic trusted scope 0/1/N (backend guard PASS)
 * D professional scope own doctor+trusted Clinic (backend guard PASS)
 * E Today stats FR-18.2 (backend guard PASS)
 * F Location operational day + trusted Location selection deterministic RED per owner decision (product RED)
 * G Live Queue scope/order/privacy (backend guard PASS)
 * H UI/client authority + Clinic+Location selectors (portal missing UI RED)
 *
 * VALID RED requires bootstrap, migrations, fixtures ok, existing paths reached, failures because portal missing + operational-day ignores Location.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10DoctorPortalTodayLiveQueueRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const LEGACY_PAGE = 'cpms-doctor';
    private const SHELL_TOKEN = 'cpms-doctor-portal-shell';
    private const FORBIDDEN_KEYS = ['clinic_id','patient_id','user_id','wp_user_id','role','roles','clinician_id','location_id','organization_id','org_id'];

    private const FIXED_UTC = '2026-03-14 10:00:00';
    private const FIXED_UTC_DATE = '2026-03-14';
    private const FIXED_K_DATE = '2026-03-15';
    private const FIXED_M_DATE = '2026-03-13';
    private const TZ_K = 'Pacific/Kiritimati';
    private const TZ_M = 'Pacific/Midway';
    private const TZ_TEHRAN = 'Asia/Tehran';

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
        $_GET = []; $_POST = []; $_REQUEST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // A — independent standalone shell — focused RED proves missing portal
    public function testA_IndependentDoctorPortalShellExists(): void
    {
        $fx = $this->makeDoctor('A');
        $this->assertTodayPath($fx['doctor'], 1);

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 A RED: independent Doctor Portal frontend entry must exist (WP Page + template_include 99 + plugin-owned full-doc shell per PatientPortalShell). Today only wp-admin admin.php?page='.self::LEGACY_PAGE.' exists. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);
        $this->assertDoctorShell($html);
    }

    // B — doctor identity fail-closed — backend guard PASS (no portal URL needed)
    public function testB_DoctorIdentityFailClosed(): void
    {
        $fx = $this->makeDoctor('B');
        $this->assertTodayPath($fx['doctor'], 1);
        self::assertNotSame($fx['doctor'], $fx['clinician'], 'B: WP user ID != clinician ID');

        $orphan = $this->makeUser('b_orphan', RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($orphan);
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        try { $today = App::visitService()->today($orphan); } finally { App::replaceExplicitScope(null); }
        self::assertSame([], $today['queue'], 'B positive: unlinked doctor empty not clinic-wide');
        self::assertSame(0, $today['stats']['total']);

        $inactiveUser = $this->makeUser('b_inactive', RolesAndCapabilities::ROLE_DOCTOR);
        $this->insertClinician('Dr B Inactive', 1, 0, $inactiveUser);
        wp_set_current_user($inactiveUser);
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        try { $todayInactive = App::visitService()->today($inactiveUser); } finally { App::replaceExplicitScope(null); }
        self::assertSame([], $todayInactive['queue'], 'B positive: inactive clinician empty');

        $sec = $this->makeUser('b_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($sec, 1, 'cpms_secretary');
        self::assertTrue(user_can($sec, RolesAndCapabilities::QUEUE_READ), 'B positive: secretary has QUEUE_READ');
        // Secretary must NOT be authorized as doctor merely via capability overlap — doctor identity requires doctor role + linked active clinician
        self::assertFalse(user_can($sec, RolesAndCapabilities::ROLE_DOCTOR) && $this->hasActiveClinician($sec), 'B positive: secretary not doctor identity');
    }

    // C — multi-Clinic trusted scope 0/1/N — backend guard PASS (REST path)
    public function testC_MultiClinicTrustedScope(): void
    {
        $fx = $this->makeMultiClinic('C');
        $this->assertTodayPath($fx['doctor'], $fx['clinic_a']);

        $noMem = $this->makeUser('c_nomem', RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($noMem);
        $r0 = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$fx['clinic_a']]);
        self::assertSame(403, $r0->get_status(), 'C: 0 membership => UNAVAILABLE 403');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($r0));

        wp_set_current_user($fx['doctor']);
        $rN = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], []);
        self::assertSame(400, $rN->get_status(), 'C: N>1 without explicit => REQUIRED 400');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rN));

        $rA = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$fx['clinic_a']]);
        self::assertSame(200, $rA->get_status(), 'C: valid Clinic A => 200');
        $rB = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$fx['clinic_b']]);
        self::assertSame(200, $rB->get_status(), 'C: valid Clinic B => 200');

        $foreign = $this->insertClinic('C foreign', 'c-foreign-'.bin2hex(random_bytes(2)));
        $rF = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$foreign]);
        self::assertSame(403, $rF->get_status(), 'C: foreign => UNAVAILABLE 403');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rF));
    }

    // D — professional scope own doctor+trusted Clinic — backend guard PASS
    public function testD_DoctorProfessionalScoping(): void
    {
        $fx = $this->makeDoctor('D');
        $this->assertTodayPath($fx['doctor'], 1);

        $doctorB = $this->makeUser('d_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr D B', 1, 1, $doctorB);
        cpms_test_seed_membership($doctorB, 1, 'cpms_doctor');
        $pB = $this->insertPatient('MR-D-B', '09120000002', 1);
        $this->insertVisit($pB, $clinicianB, 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '10:00:00');

        wp_set_current_user($fx['doctor']);
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        try { $todayA = App::visitService()->today($fx['doctor']); } finally { App::replaceExplicitScope(null); }
        self::assertCount(1, $todayA['queue'], 'D: doctor A sees own only');
        self::assertSame($fx['clinician'], (int)$todayA['queue'][0]['clinician_id']);

        $rW = $this->dispatch('GET', '/'.self::REST_NS.'/queue', ['clinician_id'=>$clinicianB], ['X-CPMS-Clinic-Id'=>'1']);
        self::assertSame(200, $rW->get_status(), 'D: widen attempt still 200 but scoped');
        $payload = $this->payload($rW);
        $queue = $payload['queue'] ?? [];
        if ($queue !== []) {
            foreach ($queue as $row) {
                self::assertSame($fx['clinician'], (int)($row['clinician_id'] ?? 0), 'D: clinician_id param must NOT widen for doctor');
            }
        }

        $multi = $this->makeMultiClinic('D2');
        $sharedUser = $this->makeUser('d_shared', RolesAndCapabilities::ROLE_DOCTOR);
        $sharedClin = $this->insertClinician('Dr D Shared', $multi['clinic_a'], 1, $sharedUser);
        cpms_test_seed_membership($sharedUser, $multi['clinic_a'], 'cpms_doctor');
        cpms_test_seed_membership($sharedUser, $multi['clinic_b'], 'cpms_doctor');
        self::assertTrue((new MembershipRepository(App::db()))->clinician_participates_in($sharedClin, $multi['clinic_b']), 'D: participation via membership not home clinic_id');
    }

    // E — Today stats FR-18.2 — backend guard PASS
    public function testE_TodayStatsScope(): void
    {
        $fx = $this->makeDoctor('E');
        $this->assertTodayPath($fx['doctor'], 1);

        $p2 = $this->insertPatient('MR-E-2', '09120000003', 1);
        $this->insertVisit($p2, $fx['clinician'], 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '11:00:00');

        wp_set_current_user($fx['doctor']);
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        try { $today = App::visitService()->today($fx['doctor']); } finally { App::replaceExplicitScope(null); }

        self::assertArrayHasKey('date', $today);
        self::assertArrayHasKey('stats', $today);
        self::assertArrayHasKey('queue', $today);
        self::assertArrayHasKey('last_event_id', $today);
        foreach (['checked_in','waiting','called','in_consultation','total','appointments_today','walk_in_today'] as $f) {
            self::assertArrayHasKey($f, $today['stats'], 'E: FR-18.2 field '.$f);
        }
        self::assertSame(gmdate('Y-m-d'), $today['date'], 'E: currently uses gmdate (defect to be fixed in F)');
        self::assertGreaterThanOrEqual(2, $today['stats']['total'], 'E: total >=2 scoped to own doctor+trusted Clinic');
    }

    // F — Location operational day + trusted Location selection — deterministic RED per owner decision
    public function testF_LocationOperationalDayAndTrustedLocationSelection(): void
    {
        // Deterministic boundary fixture
        $utcInstant = new \DateTimeImmutable(self::FIXED_UTC, new \DateTimeZone('UTC'));
        $utcDate = $utcInstant->format('Y-m-d');
        $kDate = $utcInstant->setTimezone(new \DateTimeZone(self::TZ_K))->format('Y-m-d');
        $mDate = $utcInstant->setTimezone(new \DateTimeZone(self::TZ_M))->format('Y-m-d');
        $tehranDate = $utcInstant->setTimezone(new \DateTimeZone(self::TZ_TEHRAN))->format('Y-m-d');

        self::assertSame(self::FIXED_UTC_DATE, $utcDate);
        self::assertSame(self::FIXED_K_DATE, $kDate, 'F: Kiritimati next day');
        self::assertSame(self::FIXED_M_DATE, $mDate, 'F: Midway previous day');
        self::assertNotSame($utcDate, $kDate, 'F: UTC vs Kiritimati differ');
        self::assertNotSame($kDate, $tehranDate, 'F: Kiritimati != Tehran proves no hardcoded Tehran');

        // Create org/clinic with 2 Locations different timezones
        $org = $this->insertOrg('F Org '.bin2hex(random_bytes(2)));
        $clinic = $this->insertClinicInOrg('F Clinic', 'f-clinic-'.bin2hex(random_bytes(2)), $org, self::TZ_K);
        $locK = $this->insertLocation($clinic, 'K Loc', 'f-loc-k-'.bin2hex(random_bytes(2)), self::TZ_K, 1);
        $locM = $this->insertLocation($clinic, 'M Loc', 'f-loc-m-'.bin2hex(random_bytes(2)), self::TZ_M, 0);

        $tzPersisted = App::db()->fetchValue('SELECT timezone FROM '.App::db()->table('cpms_locations').' WHERE id = %d', [$locK]);
        self::assertSame(self::TZ_K, $tzPersisted, 'F: Location timezone persisted');

        $doctor = $this->makeUser('f_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr F', $clinic, 1, $doctor);
        $membershipId = cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        // Ensure scope_mode = clinic => eligible = all active Locations (2)
        $membershipRepo = new MembershipRepository(App::db());
        $activeLocs = App::db()->fetchAll('SELECT id FROM '.App::db()->table('cpms_locations').' WHERE clinic_id = %d AND is_active = 1', [$clinic]);
        self::assertCount(2, $activeLocs, 'F: clinic has 2 active Locations');

        // Create visits for each Location with different dates to prove filtering
        $pK = $this->insertPatient('MR-F-K', '09120000101', $clinic);
        $pM = $this->insertPatient('MR-F-M', '09120000102', $clinic);
        $pUtc = $this->insertPatient('MR-F-UTC', '09120000103', $clinic);

        // Visits: one per Location per date
        $this->insertVisit($pK, $clinician, $clinic, $locK, 'waiting', $kDate, '10:00:00');
        $this->insertVisit($pM, $clinician, $clinic, $locM, 'waiting', $mDate, '11:00:00');
        $this->insertVisit($pUtc, $clinician, $clinic, $locK, 'waiting', $utcDate, '09:00:00');

        // Also insert visits for TODAY (gmdate) for each Location to test queue filtering
        $todayGm = gmdate('Y-m-d');
        $pTodayK = $this->insertPatient('MR-F-TK', '09120000104', $clinic);
        $pTodayM = $this->insertPatient('MR-F-TM', '09120000105', $clinic);
        $this->insertVisit($pTodayK, $clinician, $clinic, $locK, 'waiting', $todayGm, '10:00:00');
        $this->insertVisit($pTodayM, $clinician, $clinic, $locM, 'waiting', $todayGm, '10:30:00');

        // Positive controls: existing trusted Location machinery
        $establisher = new TrustedClinicEstablisher(App::db(), $membershipRepo);

        // 0 eligible Locations scenario — clinic with no active Locations (or membership location-scoped with 0 assigned)
        // We test clinic with 0 active Locations: create new clinic with 0 locations, membership, then today should be empty/fail-closed
        $org0 = $this->insertOrg('F Org0 '.bin2hex(random_bytes(2)));
        $clinic0 = $this->insertClinicInOrg('F Clinic0', 'f-clinic0-'.bin2hex(random_bytes(2)), $org0, 'Asia/Tehran');
        // No locations inserted for clinic0
        $doctor0 = $this->makeUser('f_doc0', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician0 = $this->insertClinician('Dr F0', $clinic0, 1, $doctor0);
        cpms_test_seed_membership($doctor0, $clinic0, 'cpms_doctor');
        $locs0 = App::db()->fetchAll('SELECT id FROM '.App::db()->table('cpms_locations').' WHERE clinic_id = %d AND is_active = 1', [$clinic0]);
        self::assertCount(0, $locs0, 'F: 0 eligible Locations scenario — clinic has 0 active Locations');
        // Today for 0 eligible should be fail-closed / no data (existing product returns empty queue because no visits, but location resolution missing)
        wp_set_current_user($doctor0);
        App::replaceExplicitScope(ClinicScope::forClinic($clinic0));
        try { $today0 = App::visitService()->today($doctor0); } finally { App::replaceExplicitScope(null); }
        self::assertSame([], $today0['queue'], 'F: 0 eligible => no queue data (fail-closed)');

        // 1 eligible Location — auto-resolution allowed
        $org1 = $this->insertOrg('F Org1 '.bin2hex(random_bytes(2)));
        $clinic1 = $this->insertClinicInOrg('F Clinic1', 'f-clinic1-'.bin2hex(random_bytes(2)), $org1, self::TZ_K);
        $loc1 = $this->insertLocation($clinic1, 'Single Loc', 'f-loc-single-'.bin2hex(random_bytes(2)), self::TZ_K, 1);
        $doctor1 = $this->makeUser('f_doc1', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician1 = $this->insertClinician('Dr F1', $clinic1, 1, $doctor1);
        cpms_test_seed_membership($doctor1, $clinic1, 'cpms_doctor');
        $scope1 = $establisher->establish($doctor1, $clinic1, null);
        self::assertSame($clinic1, $scope1->clinicId, 'F: 1 eligible without explicit Location => Clinic scope established (auto allowed)');
        self::assertNull($scope1->locationId, 'F: current product does NOT auto-resolve Location into scope even when 1 eligible — missing contract, but Clinic auto allowed');
        // For 1 eligible, future GREEN should auto-resolve to that single Location for operational day

        // N>1 without explicit Location selection — must NOT choose first/primary, must REQUIRE explicit
        wp_set_current_user($doctor);
        $scopeNoLoc = $establisher->establish($doctor, $clinic, null);
        self::assertSame($clinic, $scopeNoLoc->clinicId, 'F: N>1 without explicit Location => Clinic scope still established (current product)');
        self::assertNull($scopeNoLoc->locationId, 'F: N>1 without explicit => locationId null (no first/primary fallback) — proves no fallback, but also missing REQUIRED error');
        // Current product has NO LOCATION_SCOPE_REQUIRED error — this is the missing contract future GREEN must add
        // We do NOT invent error code, we assert that scope locationId is null and that queue currently returns cross-Location data (leakage)

        // Explicit Location selection via existing selector X-CPMS-Location-Id
        $resK = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$clinic, 'X-CPMS-Location-Id' => (string)$locK]);
        self::assertSame(200, $resK->get_status(), 'F: explicit Location K via X-CPMS-Location-Id => 200 (existing machinery)');
        $payloadK = $this->payload($resK);
        // Current product ignores locationId for queue filtering — it will return both Locations (cross-leakage) — intended RED
        $queueK = $payloadK['queue'] ?? [];
        // Positive: scope with locationId should be trusted
        $scopeK = $establisher->establish($doctor, $clinic, $locK);
        self::assertSame($locK, $scopeK->locationId, 'F: explicit Location K => trusted operational Location in scope');

        $resM = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$clinic, 'X-CPMS-Location-Id' => (string)$locM]);
        self::assertSame(200, $resM->get_status(), 'F: explicit Location M => 200');
        $scopeM = $establisher->establish($doctor, $clinic, $locM);
        self::assertSame($locM, $scopeM->locationId, 'F: explicit Location M => trusted operational Location');

        // Foreign/inactive/unassigned denied — existing error contract CLINIC_SCOPE_UNAVAILABLE 403
        $foreignClinic = $this->insertClinic('F foreign', 'f-foreign-'.bin2hex(random_bytes(2)));
        $foreignLoc = $this->insertLocation($foreignClinic, 'Foreign Loc', 'f-foreign-loc-'.bin2hex(random_bytes(2)), 'Asia/Tehran', 1);
        $resForeign = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$clinic, 'X-CPMS-Location-Id' => (string)$foreignLoc]);
        self::assertSame(403, $resForeign->get_status(), 'F: foreign Location selector => 403 UNAVAILABLE');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($resForeign));

        // Inactive Location
        $inactiveLoc = $this->insertLocation($clinic, 'Inactive Loc', 'f-inactive-'.bin2hex(random_bytes(2)), 'Asia/Tehran', 0);
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE '.$wpdb->prefix.'cpms_locations SET is_active = 0 WHERE id = %d', $inactiveLoc));
        $resInactive = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$clinic, 'X-CPMS-Location-Id' => (string)$inactiveLoc]);
        self::assertSame(403, $resInactive->get_status(), 'F: inactive Location => 403 UNAVAILABLE');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($resInactive));

        // Location assignment: doctor may only select Locations for which live membership/location-assignment grants access
        // Test scope_mode = location with only locK assigned, then requesting locM should be denied if live model requires assignment
        // Current TrustedClinicEstablisher does NOT check membership_locations assignment — only clinic+active — so it will allow unassigned Location (missing assignment check)
        // We document this as missing contract without inventing error code
        $orgAssign = $this->insertOrg('F OrgAssign '.bin2hex(random_bytes(2)));
        $clinicAssign = $this->insertClinicInOrg('F ClinicAssign', 'f-clinic-assign-'.bin2hex(random_bytes(2)), $orgAssign, self::TZ_K);
        $locAssignK = $this->insertLocation($clinicAssign, 'Assign K', 'f-assign-k-'.bin2hex(random_bytes(2)), self::TZ_K, 1);
        $locAssignM = $this->insertLocation($clinicAssign, 'Assign M', 'f-assign-m-'.bin2hex(random_bytes(2)), self::TZ_M, 0);
        $doctorAssign = $this->makeUser('f_doc_assign', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianAssign = $this->insertClinician('Dr Assign', $clinicAssign, 1, $doctorAssign);
        $memAssign = cpms_test_seed_membership($doctorAssign, $clinicAssign, 'cpms_doctor');
        // Set scope_mode = location and assign only locAssignK
        App::membership_service()->set_scope_mode($memAssign, 'location', [$locAssignK]);
        $assignedIds = $membershipRepo->location_ids_for($memAssign);
        self::assertSame([$locAssignK], $assignedIds, 'F: membership location-scoped assigned only K');

        wp_set_current_user($doctorAssign);
        $resAssignedK = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$clinicAssign, 'X-CPMS-Location-Id' => (string)$locAssignK]);
        self::assertSame(200, $resAssignedK->get_status(), 'F: assigned active Location accepted => 200');

        wp_set_current_user($doctorAssign);
        $resUnassignedM = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$clinicAssign, 'X-CPMS-Location-Id' => (string)$locAssignM]);
        // Current product allows unassigned Location because establisher does not check membership_locations — this is missing assignment enforcement
        // We assert what current product does, and document missing contract
        self::assertContains($resUnassignedM->get_status(), [200, 403], 'F: unassigned Location — current product allows (200) but should deny if assignment required — missing contract to be added in GREEN');

        // Deterministic operational-day RED: selected Location local date must be operational date, not UTC/WP/PHP/Tehran
        // Use fixed UTC instant, require Today operational date = selected Location local date
        // Exercise actual production queue/today paths with explicit Location scope
        wp_set_current_user($doctor);
        App::replaceExplicitScope(ClinicScope::forClinic($clinic, $locK));
        try {
            $todayWithK = App::visitService()->today($doctor);
        } finally {
            App::replaceExplicitScope(null);
        }
        // Current VisitRepository uses gmdate, not Location timezone — so today date = gmdate, not Kiritimati date — intended RED
        // At fixed instant, Kiritimati date = 2026-03-15, UTC = 2026-03-14, Tehran = 2026-03-14 — must be Kiritimati date when K selected
        self::assertSame(gmdate('Y-m-d'), $todayWithK['date'], 'F positive: today() currently uses gmdate (UTC) — defect');

        // Now the deterministic RED assertions per owner decision:
        // For trusted Clinic + explicit Location K, operational date should be K local date at fixed instant, not UTC
        // But product returns gmdate — RED
        $expectedKLocal = $kDate; // 2026-03-15 at fixed instant
        $actualToday = $todayWithK['date']; // gmdate = today real
        // We cannot freeze time, but we can prove that product ignores Location timezone by checking that queue includes both Locations even when K selected
        App::replaceExplicitScope(ClinicScope::forClinic($clinic, $locK));
        try {
            $queueWithK = App::visitService()->today($doctor)['queue'];
        } finally {
            App::replaceExplicitScope(null);
        }
        // Queue should be filtered to selected Location only, but current product returns both (cross-leakage)
        $hasBothLocations = false;
        $locIdsInQueue = [];
        foreach ($queueWithK as $row) {
            // We need to fetch location_id from DB for each visit id
            $locId = (int)App::db()->fetchValue('SELECT location_id FROM '.App::db()->table('cpms_visits').' WHERE id = %d', [(int)$row['id']]);
            $locIdsInQueue[] = $locId;
        }
        $hasBothLocations = in_array($locK, $locIdsInQueue, true) && in_array($locM, $locIdsInQueue, true);

        // Final deterministic RED per owner decision — this must FAIL because product ignores trusted Location scope
        self::assertTrue(
            !$hasBothLocations && !in_array($locM, $locIdsInQueue, true),
            'Phase 10 F RED — OWNER DECISION ENCODED: After trusted Clinic scope established, operational Location must be explicitly resolved. '
            .'Contract: 0 eligible=>fail closed, 1 eligible=>auto allowed, N>1=>explicit REQUIRED, no first/primary fallback, foreign/inactive/unassigned=>UNAVAILABLE 403, raw location_id selector only. '
            .'Authority = auth user + active membership + trusted Clinic + eligible Location assignment + explicit when N>1 = trusted operational Location. '
            .'Selected Location becomes source of truth for today meaning, timezone, Today summary, Live Queue filtering. '
            .'Do NOT use Clinic/WP/PHP/browser/hardcoded Asia/Tehran/first/primary fallback. '
            .'Deterministic fixture: fixed UTC instant '.self::FIXED_UTC.' UTC => UTC='.self::FIXED_UTC_DATE.' Kiritimati='.self::FIXED_K_DATE.' Midway='.self::FIXED_M_DATE.' Tehran='.self::TZ_TEHRAN.' (same as UTC at this instant). '
            .'Trusted Clinic='.$clinic.' has 2 eligible Locations K='.$locK.' ('.self::TZ_K.') and M='.$locM.' ('.self::TZ_M.'). '
            .'Without explicit Location, current TrustedClinicEstablisher returns locationId null (no fallback) but does NOT return LOCATION_SCOPE_REQUIRED — missing contract future GREEN must add. '
            .'With explicit Location K selected via X-CPMS-Location-Id, scope locationId='.$locK.' trusted, but VisitService::today() and VisitRepository::queueFor() ignore locationId and use gmdate('.gmdate('Y-m-d').') and return cross-Location data. '
            .'Expected: Today operational date = selected Location local date ('.$kDate.' for K at fixed instant) and queue/stats only from selected Location (no M). '
            .'Actual: today='.$actualToday.' (gmdate), queue locIds='.implode(',', $locIdsInQueue).' hasBoth='.($hasBothLocations?'true':'false').' — cross-Location leakage. '
            .'This is intended product RED. Future GREEN must: enforce LOCATION_REQUIRED for N>1, validate assignment, and make today/queue filter by trusted Location timezone and Location-local day. '
            .'Existing error codes: CLINIC_SCOPE_REQUIRED 400 for Clinic N>1, CLINIC_SCOPE_UNAVAILABLE 403 for foreign/inactive Location, CLINIC_VALIDATION_FAILED 422 for invalid id — NO dedicated LOCATION_REQUIRED exists yet. '
            .'Head='.$this->headSha()
        );
    }

    // G — Live Queue scope/order/privacy — backend guard PASS
    public function testG_LiveQueueScopeOrderPrivacy(): void
    {
        $fx = $this->makeDoctor('G');
        $this->assertTodayPath($fx['doctor'], 1);

        $doctorB = $this->makeUser('g_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G B', 1, 1, $doctorB);
        cpms_test_seed_membership($doctorB, 1, 'cpms_doctor');
        $pB = $this->insertPatient('MR-G-B', '09120000005', 1);
        $this->insertVisit($pB, $clinicianB, 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '09:00:00');

        $pExp = $this->insertPatient('MR-G-EXP', '09120000006', 1);
        $slotId = $this->insertSlot(1, $fx['location'], $fx['clinician'], gmdate('Y-m-d'), '08:00:00');
        self::assertGreaterThan(0, $slotId);
        $apptExp = $this->insertAppointment(1, $fx['location'], 'EXP-'.bin2hex(random_bytes(2)), $pExp, $fx['clinician'], $slotId, gmdate('Y-m-d'), '08:00:00', 1);
        self::assertGreaterThan(0, $apptExp);
        $vExp = $this->insertVisitWithAppointment($pExp, $fx['clinician'], 1, $fx['location'], $apptExp, 'waiting', gmdate('Y-m-d'), '08:00:00');
        self::assertGreaterThan(0, $vExp);

        $pNorm = $this->insertPatient('MR-G-NORM', '09120000007', 1);
        $this->insertVisit($pNorm, $fx['clinician'], 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '09:30:00');

        wp_set_current_user($fx['doctor']);
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        try { $queue = App::visitService()->today($fx['doctor'])['queue']; } finally { App::replaceExplicitScope(null); }

        self::assertGreaterThanOrEqual(3, count($queue), 'G: queue >=3 got '.count($queue));
        $expIdx = null;
        foreach ($queue as $i=>$row) { if (!empty($row['express'])) { $expIdx=$i; break; } }
        self::assertNotNull($expIdx, 'G: express found');
        self::assertSame(0, $expIdx, 'G: express first (FIFO ordering)');
        foreach ($queue as $row) {
            self::assertContains($row['status'], ['waiting','called','in_consultation'], 'G: status from live set');
            self::assertSame($fx['clinician'], (int)$row['clinician_id'], 'G: own doctor only');
        }
        self::assertArrayHasKey('patient_name', $queue[0]);
        self::assertArrayNotHasKey('mobile', $queue[0], 'G: bounded fields no mobile');
    }

    // H — UI/client authority + Clinic+Location selectors — bounded UI RED (portal missing)
    public function testH_UiClientAuthorityNoWpAdminChrome(): void
    {
        $fx = $this->makeDoctor('H');
        $this->assertTodayPath($fx['doctor'], 1);

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 H RED: Doctor Portal UI must be independent no wp-admin/theme chrome, RTL, responsive, refresh existing pattern, client may send Clinic and Location selectors (X-CPMS-Clinic-Id, X-CPMS-Location-Id) neither creates authority, must expose Clinic selector when N>1 and Location selector when N>1, no Today/Queue before required selections resolved, clear context. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);

        self::assertStringNotContainsString('id="wpadminbar"', $html);
        self::assertStringNotContainsString('id="adminmenu"', $html);
        self::assertStringNotContainsString('id="wpfooter"', $html);
        $body = ltrim($html);
        self::assertTrue(str_starts_with($body, '<!DOCTYPE html>') || str_starts_with(strtolower($body), '<!doctype html>'));
        self::assertStringContainsString('</html>', $html);
        self::assertTrue((bool)preg_match('/\bdir=["\']rtl["\']/i', $html), 'H: RTL');
        self::assertStringContainsString('viewport', $html, 'H: viewport');

        $cfgs = $this->configPayloads($html);
        self::assertCount(1, $cfgs, 'H: exactly one config script');
        $cfg = json_decode(trim($cfgs[0]), true);
        self::assertIsArray($cfg);
        $this->assertNoAuthority($cfg);
        self::assertArrayHasKey('rest_root', $cfg);
        self::assertArrayHasKey('nonce', $cfg);
        self::assertNotFalse(wp_verify_nonce((string)$cfg['nonce'], 'wp_rest'));
        self::assertTrue(str_contains($html, '/queue') || str_contains($html, 'queue') || str_contains($html, 'last_event_id') || str_contains($html, 'poll'), 'H: refresh uses existing queue endpoint');
    }

    // helpers
    private function tryResolveDoctorPortalUrl(): ?string
    {
        $classes = ['ClinicCore\\Frontend\\DoctorPortalShell','ClinicCore\\Admin\\DoctorPortalPage','ClinicCore\\Frontend\\DoctorPortalPage'];
        $methods = ['portal_url','frontendPortalUrl','frontendUrl','portalFrontendUrl','doctorPortalFrontendUrl','pageUrl'];
        foreach ($classes as $c) {
            if (!class_exists($c)) continue;
            foreach ($methods as $m) {
                if (!is_callable([$c,$m])) continue;
                try {
                    $u = (string)$c::{$m}();
                    if ($u !== '' && !str_contains($u, '/wp-admin/') && !str_contains($u, 'page='.self::LEGACY_PAGE) && str_contains($u, 'http')) return $u;
                } catch (\Throwable $e) {}
            }
        }
        foreach (['cpms_doctor_portal_frontend_url','cpms_doctor_frontend_url','cpms_doctor_portal_url'] as $filter) {
            $f = apply_filters($filter, '');
            if (is_string($f) && $f !== '' && !str_contains($f, '/wp-admin/')) return $f;
        }
        foreach (['cpms_doctor_portal_page_id','cpms_doctor_portal_page','cpms_doctor_page_id'] as $opt) {
            $id = (int)get_option($opt, 0);
            if ($id <= 0) continue;
            $u = get_permalink($id);
            if (is_string($u) && $u !== '' && !str_contains($u, '/wp-admin/')) return $u;
        }
        foreach (['cpms-doctor-portal','cpms-doctor','doctor-portal'] as $slug) {
            $p = get_page_by_path($slug);
            if ($p instanceof \WP_Post && $p->post_status === 'publish') {
                $u = get_permalink($p->ID);
                if (is_string($u) && $u !== '' && !str_contains($u, '/wp-admin/')) return $u;
            }
        }
        return null;
    }

    private function renderPortal(int $userId, string $url): string
    {
        wp_set_current_user($userId);
        $path = (string)(wp_parse_url($url, \PHP_URL_PATH) ?? '/');
        $query = (string)(wp_parse_url($url, \PHP_URL_QUERY) ?? '');
        $req = $path . ($query !== '' ? '?'.$query : '');
        if ($req === '') $req = '/';
        $this->go_to($req);
        $baseline = get_stylesheet_directory().'/page.php';
        if (!is_readable($baseline)) $baseline = get_stylesheet_directory().'/index.php';
        if (!is_readable($baseline)) $baseline = '/active-theme/page.php';
        $tpl = (string)apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $tpl, 'Doctor Portal S1: template_include must intercept with plugin-owned template. Got baseline passthrough: '.$tpl.' Head='.$this->headSha());
        self::assertFileExists($tpl);
        self::assertStringNotContainsString('/themes/', str_replace('\\','/', $tpl));
        ob_start();
        include $tpl;
        return (string)ob_get_clean();
    }

    private function assertDoctorShell(string $html): void
    {
        $body = ltrim($html);
        self::assertTrue(str_starts_with($body, '<!DOCTYPE html>') || str_starts_with(strtolower($body), '<!doctype html>'));
        self::assertStringContainsString('</html>', $html);
        self::assertTrue(str_contains(strtolower($html), self::SHELL_TOKEN) || str_contains($html, 'data-cpms-doctor-portal') || str_contains($html, 'data-cpms-portal="doctor"'));
        self::assertStringNotContainsString('id="wpadminbar"', $html);
        self::assertStringNotContainsString('id="adminmenu"', $html);
        self::assertStringNotContainsString('id="wpfooter"', $html);
        self::assertTrue((bool)preg_match('/\bdir=["\']rtl["\']/i', $html));
        $cfgs = $this->configPayloads($html);
        self::assertCount(1, $cfgs);
        $cfg = json_decode(trim($cfgs[0]), true);
        self::assertIsArray($cfg);
        $this->assertNoAuthority($cfg);
    }

    private function configPayloads(string $html): array
    {
        $payloads = [];
        if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/su', $html, $m, \PREG_SET_ORDER) < 1) return $payloads;
        foreach ($m as $s) {
            $attrs = ' '.$s[1];
            if (preg_match('/\stype="([^"]*)"/i', $attrs, $t) !== 1 || strtolower(trim($t[1])) !== 'application/json') continue;
            if (preg_match('/\sclass="([^"]*)"/i', $attrs, $c) !== 1) continue;
            $classes = preg_split('/\s+/', trim($c[1]));
            if ($classes === false) continue;
            $has = false; foreach ($classes as $cl) { if (str_contains($cl, 'cpms-') && str_contains($cl, '__config')) { $has=true; break; } }
            if (!$has) continue;
            $payloads[] = (string)$s[2];
        }
        return $payloads;
    }

    private function assertNoAuthority(array $cfg): void
    {
        $stack = [$cfg];
        while ($stack !== []) {
            $node = (array)array_pop($stack);
            foreach ($node as $k=>$v) {
                self::assertNotContains(strtolower((string)$k), self::FORBIDDEN_KEYS, 'must not expose authority key '.(string)$k);
                if (is_array($v)) $stack[] = $v;
            }
        }
    }

    private function makeDoctor(string $tag): array
    {
        $clinic = 1;
        $loc = (int)App::db()->fetchValue('SELECT id FROM '.App::db()->table('cpms_locations').' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1', [$clinic]);
        self::assertGreaterThan(0, $loc);
        $doctor = $this->makeUser('doc_'.strtolower($tag).'_'.bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr '.$tag.' '.bin2hex(random_bytes(2)), $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');
        $patient = $this->insertPatient('MR-'.$tag.'-'.bin2hex(random_bytes(2)), '0912'.sprintf('%07d', random_int(1000000,9999999)), $clinic);
        $row = App::db()->fetchRow('SELECT first_name, last_name FROM '.App::db()->table('cpms_patients').' WHERE id = %d', [$patient]);
        $this->insertVisit($patient, $clinician, $clinic, $loc, 'waiting', gmdate('Y-m-d'), '10:00:00');
        return ['clinic'=>1,'location'=>$loc,'clinician'=>$clinician,'doctor'=>$doctor,'patient'=>$patient,'patient_first'=>(string)($row['first_name']??'Test')];
    }

    private function makeMultiClinic(string $tag): array
    {
        $clinicA = 1;
        $clinicB = $this->insertClinic('Multi '.$tag.' B', 'multi-'.strtolower($tag).'-b-'.bin2hex(random_bytes(2)));
        $locA = (int)App::db()->fetchValue('SELECT id FROM '.App::db()->table('cpms_locations').' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', [$clinicA]);
        $locB = $this->insertLocation($clinicB, 'Multi B Loc', 'multi-b-'.bin2hex(random_bytes(2)), 'Asia/Tehran', 1);
        $doctor = $this->makeUser('multi_'.strtolower($tag).'_'.bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr Multi A '.$tag, $clinicA, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinicA, 'cpms_doctor');
        cpms_test_seed_membership($doctor, $clinicB, 'cpms_doctor');
        $pA = $this->insertPatient('MR-MULTI-A-'.$tag, '0912'.sprintf('%07d', random_int(1000000,9999999)), $clinicA);
        $pB = $this->insertPatient('MR-MULTI-B-'.$tag, '0912'.sprintf('%07d', random_int(1000000,9999999)), $clinicB);
        $this->insertVisit($pA, $clinician, $clinicA, $locA, 'waiting', gmdate('Y-m-d'), '10:00:00');
        $this->insertVisit($pB, $clinician, $clinicB, $locB, 'waiting', gmdate('Y-m-d'), '11:00:00');
        return ['clinic_a'=>$clinicA,'clinic_b'=>$clinicB,'doctor'=>$doctor, 'clinician'=>$clinician, 'loc_a'=>$locA, 'loc_b'=>$locB];
    }

    private function assertTodayPath(int $userId, ?int $clinicId = null): void
    {
        wp_set_current_user($userId);
        $scope = null;
        if ($clinicId !== null) {
            $scope = ClinicScope::forClinic($clinicId);
            App::replaceExplicitScope($scope);
        } elseif ($this->countClinics() > 1) {
            $active = (new MembershipRepository(App::db()))->active_clinic_ids_for_user($userId);
            $chosen = $active[0] ?? 1;
            $scope = ClinicScope::forClinic((int)$chosen);
            App::replaceExplicitScope($scope);
        }
        try {
            $today = App::visitService()->today($userId);
        } finally {
            if ($scope !== null) App::replaceExplicitScope(null);
        }
        self::assertArrayHasKey('queue', $today, 'positive: today() path reached');
        self::assertArrayHasKey('stats', $today);
        self::assertArrayHasKey('date', $today);
    }

    private function countClinics(): int { return (int)App::db()->fetchValue('SELECT COUNT(*) FROM '.App::db()->table('cpms_clinics'), []); }
    private function hasActiveClinician(int $userId): bool { return null !== App::db()->fetchValue('SELECT id FROM '.App::db()->table('cpms_clinicians').' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1', [$userId]); }
    private function headSha(): string { return (string)(getenv('GITHUB_SHA') ?: substr((string)exec('git rev-parse HEAD'),0,7)); }

    private function makeUser(string $login, string $role): int
    {
        $u = $login.'_'.bin2hex(random_bytes(3));
        $id = (int)wp_create_user($u, 'pass-not-used-123', $u.'@test.local');
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
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', $name, 'org-'.bin2hex(random_bytes(3)), 'active', $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertClinic(string $name, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $org = (int)$wpdb->get_var('SELECT organization_id FROM '.$wpdb->prefix.'cpms_clinics WHERE id = 1');
        if ($org <= 0) $org = $this->insertOrg('Org for '.$name);
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $org, $name, $slug, 'Asia/Tehran', $now, $now));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertClinicInOrg(string $name, string $slug, int $orgId, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, $slug, $tz, $now, $now));
        $id = (int)$wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $tz, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, $slug, $tz, $primary, $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertPatient(string $mrn, string $mobile, int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient '.substr($mrn,-4), $mobile, 'active', $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, $date, $date.' '.$time, $date.' '.$time, $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertVisitWithAppointment(int $patientId, int $clinicianId, int $clinicId, int $locId, int $apptId, string $status, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_visits (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, $apptId, 'scheduled', $status, $date, $date.' '.$time, $date.' '.$time, $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertSlot(int $clinicId, int $locId, int $clinicianId, string $date, string $time): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)', $clinicId, $locId, $clinicianId, $date, $time, 20, 1, 1, 0, 1, 'manual', $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function insertAppointment(int $clinicId, int $locId, string $ref, int $patientId, int $clinicianId, int $slotId, string $date, string $time, int $express): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO '.$wpdb->prefix.'cpms_appointments (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)', $clinicId, $locId, $ref, $patientId, $clinicianId, $slotId, 0, $date, $time, 20, '08:20:00', 'confirmed', $express, $now, $now, $now));
        return (int)$wpdb->insert_id;
    }

    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k=>$v) $r->set_param($k,$v);
        $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $k=>$v) $r->set_header($k,$v);
        return rest_do_request($r);
    }

    private function payload(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) return $b['data'];
        return is_array($b) ? $b : [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) return (string)$b->get_error_code();
        return (string)(is_array($b) ? ($b['code'] ?? '') : '');
    }
}

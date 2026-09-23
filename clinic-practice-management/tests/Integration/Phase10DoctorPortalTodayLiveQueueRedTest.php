<?php
/**
 * Phase 10 Slice 1 — Independent Doctor Portal Today + Live Queue (TEST-ONLY RED).
 *
 * Live state at authoring 2026-09-23:
 * - main ca08c88 (PR #111), origin/main identical, 1 open PR #112 draft on this branch
 * - latest migration 2026_09_20_0022 (22 files 0001..0022), Phase 9 CLOSED
 * - No frontend Doctor Portal (grep none), only DoctorDashboardPage wp-admin cpms-doctor
 * - PatientPortalShell precedent: Page + template_include 99 + plugin-owned full-doc shell
 * - VisitRepository queueFor/statsFor/eventsSince/lastEventId use gmdate('Y-m-d') fallback, not Location timezone
 * - LocationRepository stores IANA timezone, is_primary, is_active
 * - RestClinicContext + TrustedClinicEstablisher: 0=>UNAVAILABLE 403, 1=>auto, N>1=>REQUIRED 400, foreign=>UNAVAILABLE, no fallback
 *
 * 8 invariants A-H:
 * A shell, B doctor identity fail-closed, C 0/1/N trusted scope, D professional scope,
 * E Today stats FR-18.2, F Location operational-day boundary + ambiguity, G Live Queue, H UI/client authority
 *
 * VALID RED: bootstrap, schema, fixtures ok, existing queue/today paths reached, failures because portal/operational-day missing.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

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
    private const LEGACY_DOCTOR_PAGE = 'cpms-doctor';
    private const SHELL_TOKEN = 'cpms-doctor-portal-shell';
    private const FORBIDDEN_KEYS = ['clinic_id','patient_id','user_id','wp_user_id','role','roles','clinician_id','location_id','organization_id','org_id'];

    // Deterministic boundary: 2026-03-14 10:00 UTC => UTC 2026-03-14, Kiritimati +14 => 2026-03-15, Midway -11 => 2026-03-13
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

    // A — independent standalone shell
    public function testA_IndependentDoctorPortalShellExists(): void
    {
        $fx = $this->makeDoctor('A');
        $this->assertTodayPath($fx['doctor']);

        wp_set_current_user($fx['doctor']);
        $clinicianId = App::db()->fetchValue('SELECT id FROM '.App::db()->table('cpms_clinicians').' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1', [$fx['doctor']]);
        self::assertNotNull($clinicianId);
        self::assertSame($fx['clinician'], (int)$clinicianId);

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 A RED: independent Doctor Portal frontend entry must exist (WP Page + template_include 99 + plugin-owned full-doc shell per PatientPortalShell precedent). Today only wp-admin admin.php?page='.self::LEGACY_DOCTOR_PAGE.' exists. Head='. $this->headSha());
        self::assertStringNotContainsString('/wp-admin/', $url);
        self::assertStringNotContainsString('page='.self::LEGACY_DOCTOR_PAGE, $url);

        $html = $this->renderPortal($fx['doctor'], $url);
        $this->assertDoctorShell($html);
    }

    // B — doctor identity fail-closed
    public function testB_DoctorIdentityFailClosed(): void
    {
        $fx = $this->makeDoctor('B');
        $this->assertTodayPath($fx['doctor']);

        self::assertNotSame($fx['doctor'], $fx['clinician'], 'B positive: WP user ID != clinician ID');

        // unlinked doctor sees nothing (existing behavior, positive control)
        $orphan = $this->makeUser('b_orphan', RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($orphan);
        $today = App::visitService()->today($orphan);
        self::assertSame([], $today['queue'], 'B positive: unlinked doctor empty, not clinic-wide');
        self::assertSame(0, $today['stats']['total']);

        // inactive clinician: create user with inactive link (must not reuse wp_user_id that already has active clinician)
        $inactiveUser = $this->makeUser('b_inactive', RolesAndCapabilities::ROLE_DOCTOR);
        $inactiveClinician = $this->insertClinician('Dr B Inactive', 1, 0, $inactiveUser);
        wp_set_current_user($inactiveUser);
        $todayInactive = App::visitService()->today($inactiveUser);
        self::assertSame([], $todayInactive['queue'], 'B positive: inactive clinician empty');

        // secretary has QUEUE_READ but must NOT be authorized into Doctor Portal via capability overlap
        $sec = $this->makeUser('b_sec', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($sec, 1, 'cpms_secretary');
        self::assertTrue(user_can($sec, RolesAndCapabilities::QUEUE_READ));

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 B RED: Doctor Portal must enforce doctor identity (auth + doctor role + linked active clinician). No frontend shell exists. Head='.$this->headSha());

        $secHtml = $this->renderPortal($sec, $url);
        self::assertStringNotContainsString($fx['patient_first'], $secHtml, 'B: secretary must not see patient data');
        self::assertTrue(str_contains($secHtml, 'دسترسی') || str_contains($secHtml, 'access-denied') || str_contains($secHtml, 'ورود'), 'B: must deny secretary');
    }

    // C — multi-Clinic trusted scope 0/1/N
    public function testC_MultiClinicTrustedScope(): void
    {
        $fx = $this->makeMultiClinic('C');
        $this->assertTodayPath($fx['doctor']);

        // 0 memberships => UNAVAILABLE 403
        $noMem = $this->makeUser('c_nomem', RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($noMem);
        $r0 = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$fx['clinic_a']]);
        self::assertSame(403, $r0->get_status());
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($r0));

        // N>1 without explicit => REQUIRED 400
        wp_set_current_user($fx['doctor']);
        $rN = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], []);
        self::assertSame(400, $rN->get_status());
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rN));

        // valid selected => 200
        $rA = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$fx['clinic_a']]);
        self::assertSame(200, $rA->get_status());
        $rB = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$fx['clinic_b']]);
        self::assertSame(200, $rB->get_status());

        // foreign => UNAVAILABLE 403
        $foreign = $this->insertClinic('C foreign', 'c-foreign-'.bin2hex(random_bytes(2)));
        $rF = $this->dispatch('GET', '/'.self::REST_NS.'/queue', [], ['X-CPMS-Clinic-Id' => (string)$foreign]);
        self::assertSame(403, $rF->get_status());

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 C RED: Doctor Portal must respect trusted Clinic scope 0=>denial 1=>auto N>1=>REQUIRED foreign=>UNAVAILABLE no first/home fallback raw clinic_id only. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertTrue(str_contains($html, 'کلینیک') || str_contains($html, 'clinic') || str_contains($html, 'clinic-selector'), 'C: Clinic selector for N>1');
    }

    // D — doctor professional scope
    public function testD_DoctorProfessionalScoping(): void
    {
        $fx = $this->makeDoctor('D');
        $this->assertTodayPath($fx['doctor']);

        $doctorB = $this->makeUser('d_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr D B', 1, 1, $doctorB);
        cpms_test_seed_membership($doctorB, 1, 'cpms_doctor');
        $patientB = $this->insertPatient('MR-D-B', '09120000002', 1);
        $this->insertVisit($patientB, $clinicianB, 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '10:30:00');

        wp_set_current_user($fx['doctor']);
        $todayA = App::visitService()->today($fx['doctor']);
        self::assertCount(1, $todayA['queue']);
        self::assertSame($fx['clinician'], (int)$todayA['queue'][0]['clinician_id']);

        // cannot widen via clinician_id param
        $rW = $this->dispatch('GET', '/'.self::REST_NS.'/queue', ['clinician_id'=>$clinicianB], ['X-CPMS-Clinic-Id'=>'1']);
        self::assertSame(200, $rW->get_status());
        $payload = $this->payload($rW);
        $queue = $payload['queue'] ?? [];
        if ($queue !== []) {
            foreach ($queue as $row) {
                self::assertSame($fx['clinician'], (int)($row['clinician_id'] ?? 0), 'D positive: clinician_id param must NOT widen for doctor');
            }
        }

        // clinicians.clinic_id not authority: home A but membership B => participates_in B true
        $multi = $this->makeMultiClinic('D2');
        $sharedUser = $this->makeUser('d_shared', RolesAndCapabilities::ROLE_DOCTOR);
        $sharedClinician = $this->insertClinician('Dr D Shared', $multi['clinic_a'], 1, $sharedUser);
        cpms_test_seed_membership($sharedUser, $multi['clinic_a'], 'cpms_doctor');
        cpms_test_seed_membership($sharedUser, $multi['clinic_b'], 'cpms_doctor');
        self::assertTrue(App::membership_service()->clinician_participates_in($sharedClinician, $multi['clinic_b']), 'D positive: participation via membership not home clinic_id');

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 D RED: Doctor Portal must enforce professional scope own doctor+trusted Clinic only. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertStringContainsString($fx['patient_first'], $html);
        self::assertStringNotContainsString('Patient D B', $html);
    }

    // E — Today stats FR-18.2
    public function testE_TodayStatsScope(): void
    {
        $fx = $this->makeDoctor('E');
        $this->assertTodayPath($fx['doctor']);

        $p2 = $this->insertPatient('MR-E-2', '09120000003', 1);
        $this->insertVisit($p2, $fx['clinician'], 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '11:00:00');

        wp_set_current_user($fx['doctor']);
        $today = App::visitService()->today($fx['doctor']);
        self::assertArrayHasKey('date', $today);
        self::assertArrayHasKey('stats', $today);
        self::assertArrayHasKey('queue', $today);
        self::assertArrayHasKey('last_event_id', $today);
        foreach (['checked_in','waiting','called','in_consultation','total','appointments_today','walk_in_today'] as $f) {
            self::assertArrayHasKey($f, $today['stats']);
        }
        self::assertSame(gmdate('Y-m-d'), $today['date'], 'E positive: today uses gmdate currently (defect)');

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 E RED: Today summary must be scoped to trusted Clinic+doctor+operational day in independent Doctor Portal. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertTrue(str_contains($html, 'امروز') || str_contains($html, 'today') || str_contains($html, 'today-stats'));
        self::assertStringContainsString((string)$today['stats']['waiting'], $html);
    }

    // F — Location operational-day boundary + ambiguity (OUTCOME 3)
    public function testF_LocationOperationalDayBoundary(): void
    {
        // Positive: deterministic fixture where UTC date != Location-local date
        $utcInstant = new \DateTimeImmutable(self::FIXED_UTC, new \DateTimeZone('UTC'));
        $utcDate = $utcInstant->format('Y-m-d');
        $kDate = $utcInstant->setTimezone(new \DateTimeZone(self::TZ_K))->format('Y-m-d');
        $mDate = $utcInstant->setTimezone(new \DateTimeZone(self::TZ_M))->format('Y-m-d');
        $tehranDate = $utcInstant->setTimezone(new \DateTimeZone(self::TZ_TEHRAN))->format('Y-m-d');

        self::assertSame(self::FIXED_UTC_DATE, $utcDate);
        self::assertSame(self::FIXED_K_DATE, $kDate, 'F positive: Kiritimati next day');
        self::assertSame(self::FIXED_M_DATE, $mDate, 'F positive: Midway previous day');
        self::assertNotSame($utcDate, $kDate, 'F positive: UTC vs Kiritimati differ deterministic');
        self::assertNotSame($kDate, $mDate);
        self::assertNotSame($kDate, $tehranDate, 'F positive: Kiritimati != Tehran proves no hardcoded Asia/Tehran');

        // Persisted operational Locations
        $org = $this->insertOrg('F Org '.bin2hex(random_bytes(2)));
        $clinic = $this->insertClinicInOrg('F Clinic', 'f-clinic-'.bin2hex(random_bytes(2)), $org, self::TZ_K);
        $locK = $this->insertLocation($clinic, 'K Loc', 'f-loc-k-'.bin2hex(random_bytes(2)), self::TZ_K, 1);
        $locM = $this->insertLocation($clinic, 'M Loc', 'f-loc-m-'.bin2hex(random_bytes(2)), self::TZ_M, 0);

        $tzPersisted = App::db()->fetchValue('SELECT timezone FROM '.App::db()->table('cpms_locations').' WHERE id = %d', [$locK]);
        self::assertSame(self::TZ_K, $tzPersisted, 'F positive: Location timezone persisted');

        $doctor = $this->makeUser('f_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr F', $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, 'cpms_doctor');

        $pUtc = $this->insertPatient('MR-F-UTC', '09120000101', $clinic);
        $pK = $this->insertPatient('MR-F-K', '09120000102', $clinic);
        $this->insertVisit($pUtc, $clinician, $clinic, $locK, 'waiting', $utcDate, '09:00:00');
        $this->insertVisit($pK, $clinician, $clinic, $locK, 'waiting', $kDate, '10:00:00');

        // Positive: repo can fetch each date explicitly, today path uses gmdate
        wp_set_current_user($doctor);
        $today = App::visitService()->today($doctor);
        self::assertSame(gmdate('Y-m-d'), $today['date'], 'F positive: today() currently uses gmdate (UTC) — defect');

        // Investigation of operational Location authority:
        // - visit.location_id exists and is persisted
        // - appointment.location_id exists
        // - membership_locations exists but not used for today
        // - RestClinicContext supports X-CPMS-Location-Id but QueueController ignores it
        // - QueueController today/queue have no location_id arg, only clinician_id
        // - PrimaryLocationResolver exists but only for new visit location, not for today determination
        // - Wireframe doctor.md shows Today + Live Queue but no Location selector
        // - ADR-0031 AD-08 Location timezone NOT NULL, AD-17 topology B says active Location must be explicit or primary rule
        // => No deterministic single Location for Clinic-level Doctor Today view when clinic has multiple active Locations with different timezones

        // OUTCOME 3 — PRODUCT CONTRACT GENUINELY UNRESOLVED
        // We do NOT encode guessed rule. F must prove ambiguity and require owner decision.

        // Evidence: clinic has 2 active Locations with different operational dates at same UTC instant
        $activeLocs = App::db()->fetchAll('SELECT id, timezone FROM '.App::db()->table('cpms_locations').' WHERE clinic_id = %d AND is_active = 1', [$clinic]);
        self::assertCount(2, $activeLocs, 'F positive: clinic has 2 active Locations');

        // Evidence: VisitRepository uses gmdate fallback, not Location timezone (grep confirmed)
        // Evidence: QueueController does not accept location_id, so cannot be explicit per-request
        // Evidence: today() date is gmdate, not Location-local

        // RED that proves ambiguity: hasSingleAuthority is false, but should be true after GREEN defines deterministic authority
        // This failure is INTENDED PRODUCT RED — not fixture defect — because live architecture does not define single operational Location
        $hasSingle = $this->hasSingleOperationalAuthority($clinic);
        self::assertTrue(
            $hasSingle,
            'Phase 10 F RED — BLOCKED ON OWNER PRODUCT DECISION (OUTCOME 3): Clinic '.$clinic.' has 2 active Locations ('.self::TZ_K.'=>'.$kDate.' and '.self::TZ_M.'=>'.$mDate.') with different local dates at same UTC instant '.self::FIXED_UTC.' UTC (UTC='.$utcDate.' Tehran='.$tehranDate.'). '
            .'VisitRepository queueFor/statsFor/eventsSince/lastEventId use gmdate fallback, not Location timezone. QueueController /doctor/today and /queue have no location_id arg, RestClinicContext supports X-CPMS-Location-Id but VisitService ignores it. PrimaryLocationResolver only for new visit location, not today. Wireframe doctor.md shows Today+Queue but no Location selector. ADR-0031 AD-17 says active Location must be explicit or primary rule, but no rule is implemented for Doctor Today view. '
            .'Therefore product contract for operational today at selected Clinic is GENUINELY UNRESOLVED. GREEN must NOT guess first Location, fake System Location, or fallback to Clinic/WP/PHP/browser/hardcoded Asia/Tehran. ONE owner decision required: What persisted object deterministically establishes operational Location for Doctor Today+Live Queue at selected Clinic? Options: (a) primary Location of Clinic, (b) explicit Location selector/context per request (existing X-CPMS-Location-Id header), (c) per-visit Location (multi-Location aggregated view with per-object Location-local dates). Until owner resolves, F must remain proof of ambiguity. Head='.$this->headSha().' gmdate='.gmdate('Y-m-d').' today='.$today['date']
        );
    }

    // G — Live Queue scope/order/privacy
    public function testG_LiveQueueScopeOrderPrivacy(): void
    {
        $fx = $this->makeDoctor('G');
        $this->assertTodayPath($fx['doctor']);

        $doctorB = $this->makeUser('g_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G B', 1, 1, $doctorB);
        cpms_test_seed_membership($doctorB, 1, 'cpms_doctor');
        $pB = $this->insertPatient('MR-G-B', '09120000005', 1);
        $this->insertVisit($pB, $clinicianB, 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '09:00:00');

        // Create express via slot+appointment with is_walkin_express=1 (proper FK chain)
        $pExp = $this->insertPatient('MR-G-EXP', '09120000006', 1);
        $slotId = $this->insertSlot(1, $fx['location'], $fx['clinician'], gmdate('Y-m-d'), '08:00:00');
        $apptExp = $this->insertAppointment(1, $fx['location'], 'EXP-'.bin2hex(random_bytes(2)), $pExp, $fx['clinician'], $slotId, gmdate('Y-m-d'), '08:00:00', 1);
        $this->insertVisitWithAppointment($pExp, $fx['clinician'], 1, $fx['location'], $apptExp, 'waiting', gmdate('Y-m-d'), '08:00:00');

        $pNorm = $this->insertPatient('MR-G-NORM', '09120000007', 1);
        $this->insertVisit($pNorm, $fx['clinician'], 1, $fx['location'], 'waiting', gmdate('Y-m-d'), '09:30:00');

        wp_set_current_user($fx['doctor']);
        $queue = App::visitService()->today($fx['doctor'])['queue'];
        self::assertGreaterThanOrEqual(3, count($queue), 'G positive: queue >=3');
        $expIdx = null;
        foreach ($queue as $i=>$row) { if (!empty($row['express'])) { $expIdx=$i; break; } }
        self::assertNotNull($expIdx, 'G positive: express found');
        self::assertSame(0, $expIdx, 'G positive: express first');
        foreach ($queue as $row) {
            self::assertContains($row['status'], ['waiting','called','in_consultation']);
            self::assertSame($fx['clinician'], (int)$row['clinician_id'], 'G positive: own doctor only');
        }
        self::assertArrayHasKey('patient_name', $queue[0]);
        self::assertArrayNotHasKey('mobile', $queue[0]);

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 G RED: Live Queue must be scoped own doctor+trusted Clinic express/FIFO bounded fields no mutation in independent Doctor Portal. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);
        self::assertTrue(str_contains($html, 'صف') || str_contains($html, 'queue') || str_contains($html, 'live-queue'));
        self::assertStringNotContainsString('0912', $html);
    }

    // H — UI/client authority no wp-admin chrome
    public function testH_UiClientAuthorityNoWpAdminChrome(): void
    {
        $fx = $this->makeDoctor('H');
        $this->assertTodayPath($fx['doctor']);

        $url = $this->tryResolveDoctorPortalUrl();
        self::assertNotNull($url, 'Phase 10 H RED: UI must be independent no wp-admin/theme chrome RTL responsive tablet-first refresh existing pattern client authority only trusted Clinic selector. Head='.$this->headSha());
        $html = $this->renderPortal($fx['doctor'], $url);

        self::assertStringNotContainsString('id="wpadminbar"', $html);
        self::assertStringNotContainsString('id="adminmenu"', $html);
        self::assertStringNotContainsString('id="wpfooter"', $html);
        $body = ltrim($html);
        self::assertTrue(str_starts_with($body, '<!DOCTYPE html>') || str_starts_with(strtolower($body), '<!doctype html>'));
        self::assertStringContainsString('</html>', $html);
        self::assertTrue((bool)preg_match('/\bdir=["\']rtl["\']/i', $html));
        self::assertStringContainsString('viewport', $html);

        $cfgs = $this->configPayloads($html);
        self::assertCount(1, $cfgs, 'H: exactly one config script');
        $cfg = json_decode(trim($cfgs[0]), true);
        self::assertIsArray($cfg);
        $this->assertNoAuthority($cfg);
        self::assertArrayHasKey('rest_root', $cfg);
        self::assertArrayHasKey('nonce', $cfg);
        self::assertNotFalse(wp_verify_nonce((string)$cfg['nonce'], 'wp_rest'));
        self::assertTrue(str_contains($html, '/queue') || str_contains($html, 'queue') || str_contains($html, 'last_event_id') || str_contains($html, 'poll'));
    }

    // helpers — URL resolution, rendering, assertions
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
                    if ($u !== '' && !str_contains($u, '/wp-admin/') && !str_contains($u, 'page='.self::LEGACY_DOCTOR_PAGE) && str_contains($u, 'http')) return $u;
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

    // fixtures
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
        return ['clinic_a'=>$clinicA,'clinic_b'=>$clinicB,'doctor'=>$doctor];
    }

    private function assertTodayPath(int $userId): void
    {
        wp_set_current_user($userId);
        $today = App::visitService()->today($userId);
        self::assertArrayHasKey('queue', $today, 'positive: today() path reached');
        self::assertArrayHasKey('stats', $today);
        self::assertArrayHasKey('date', $today);
    }

    private function hasSingleOperationalAuthority(int $clinicId): bool
    {
        // Live product does NOT define single operational Location for Doctor Today view
        // VisitRepository uses gmdate, QueueController has no location_id, RestClinicContext location header ignored for today
        // PrimaryLocationResolver only for new visit location, not today
        // So return false to expose RED ambiguity — GREEN must implement deterministic authority
        $locs = App::db()->fetchAll('SELECT id FROM '.App::db()->table('cpms_locations').' WHERE clinic_id = %d AND is_active = 1', [$clinicId]);
        if (!is_array($locs)) return false;
        // Even with 1 location, current code doesn't use its timezone — so no deterministic authority yet
        return false;
    }

    private function headSha(): string { return (string)(getenv('GITHUB_SHA') ?: substr((string)exec('git rev-parse HEAD'),0,7)); }

    // row writers
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

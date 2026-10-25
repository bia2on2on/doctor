<?php
/**
 * Phase 10 — Owner mission: public Clinic/doctor discovery (clinic_id="all")
 * + shared-clinic booking authority (optional clinic_id on A1/A4/A2 route
 * args) + weekly Jalali calendar markup. TEST-ONLY.
 *
 * ============================================================================
 * WHAT THIS RED ENCODING GUARDS
 * ============================================================================
 *
 *  1. DISCOVERY DIRECTORY (fail-closed consent):
 *     `[cpms_public_booking clinic_id="all"]` renders a SERVER-RENDERED clinic
 *     directory. A clinic is listed ONLY with ALL of: explicit public-listing
 *     consent (`public.listing_enabled`, default FALSE — no stored row means
 *     no listing), active Organization, non-suspended Clinic, and >= 1 active
 *     Location. Deterministic order (name, id), bounded, no PHI, no new REST
 *     endpoint, no runtime config on the directory state (zero browser
 *     requests). An ineligible/bogus `?cpms_clinic=` selection falls back to
 *     the SAME directory (non-enumerating) — it never reveals whether that
 *     clinic exists.
 *
 *  2. BC PIN: the bare and invalid/absent `clinic_id` shortcodes keep
 *     rendering the historical closed `error` state (Phase 8 Slice 1 T3–T6
 *     unchanged); this file re-pins the bare form as a regression guard.
 *
 *  3. SHARED-CLINIC AUTHORITY: optional `clinic_id` on the EXISTING public
 *     A1/A4 routes (and A2/B1 route args) selects the booking clinic. It is
 *     honored only via MembershipRepository::clinician_participates_in()
 *     (home clinic or ACTIVE durable membership). Everything else — unknown,
 *     foreign, non-participating — fails with the SAME CLINIC_NOT_FOUND/404
 *     envelope as an unknown clinician (non-enumerating). Requests WITHOUT
 *     clinic_id keep the historical home-clinic derivation (BC). With
 *     clinic_id present, `slot.clinic_id == selected clinic` is enforced by
 *     the clinic-scoped slot resolution.
 *
 *  4. DOCTOR DIRECTORY: home-clinic + actively-membershiped doctors are
 *     listed (active only) with server-rendered search/specialty filters,
 *     normalized Persian/Arabic data-search blobs, and clinic-local working
 *     days. No public doctor-list REST endpoint exists or is added.
 *
 *  5. WEEKLY CALENDAR: server-published week-nav controls, day-empty
 *     template, and weekday_names in the runtime config — the browser gets
 *     zero bookability math.
 *
 * Runs in CI (WordPress + MySQL); NOT runnable in the wasm-only local
 * toolchain. All assertions follow the Phase 8 Slice 1 helper contract.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10PublicDiscoverySharedClinicTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const SHORTCODE = 'cpms_public_booking';
    private const CLINIC_ATTR = 'clinic_id';
    private const CONFIG_CLASS = 'cpms-public-booking__config';
    private const TZ_TEHRAN = 'Asia/Tehran';

    private int $org = 0;
    private int $clinicAlpha = 0;
    private int $clinicBeta = 0;
    private int $clinicCharlie = 0;
    private int $clinicDelta = 0;
    private int $clinicEcho = 0;
    private int $locationAlpha = 0;
    private int $locationBeta = 0;
    private int $locationEcho = 0;
    private int $doctorShared = 0;
    private int $doctorInactive = 0;
    private int $slotBeta = 0;
    private string $uid = '';
    private string $alphaName = '';
    private string $betaName = '';
    private string $charlieName = '';
    private string $deltaName = '';
    private string $echoName = '';

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        unset($_GET['cpms_clinic']);

        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        unset($_GET['cpms_clinic']);
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // T1 — directory fails closed with ZERO consent rows (default-false)
    // ------------------------------------------------------------------

    public function testDiscoveryDirectoryListsNothingWithoutExplicitConsent(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => 'all']);

        self::assertSurfaceRendered($html, 'discovery root');
        self::assertStringContainsString('data-discovery="1"', $html, 'the discovery directory state must render');
        self::assertStringContainsString('cpms-public-booking--discovery', $html, 'the discovery root class must be present');

        // Every otherwise-eligible clinic stays hidden while consent is default-false.
        foreach ([$this->alphaName, $this->betaName] as $name) {
            self::assertStringNotContainsString(
                $name,
                $html,
                'fail-closed consent: an eligible clinic without `public.listing_enabled=true` must NOT be publicly listed'
            );
        }

        // The directory publishes NO runtime contract (no browser requests)
        // and no booking affordance at all.
        self::assertStringNotContainsString(self::CONFIG_CLASS, $html, 'the directory state must publish no runtime config');
        self::assertStringNotContainsString('data-clinician-id', $html, 'the directory never exposes clinician identity');
        self::assertStringNotContainsString('/booking/hold', $html, 'no hold affordance on a public listing');
        self::assertStringNotContainsString('/otp/request', $html, 'no OTP affordance on a public listing');
    }

    // ------------------------------------------------------------------
    // T2 — consent + active org + active clinic + active location, in order
    // ------------------------------------------------------------------

    public function testDirectoryListsConsentedEligibleClinicsDeterministically(): void
    {
        // Consent for Alpha/Beta (eligible) and Delta/Echo (later disqualified).
        foreach ([$this->clinicAlpha, $this->clinicBeta, $this->clinicDelta, $this->clinicEcho] as $clinicId) {
            $this->setListingConsent($clinicId, true);
        }
        // Delta becomes a suspended Clinic; Echo loses its only active Location.
        self::assertSame(1, $this->updateRow('cpms_clinics', ['status' => 'suspended'], ['id' => $this->clinicDelta], ['%d', '%d']), 'fixture: suspend Delta');
        self::assertSame(1, $this->updateRow('cpms_locations', ['is_active' => 0], ['id' => $this->locationEcho], ['%d', '%d']), 'fixture: deactivate Echo location');

        $html = $this->renderSurface([self::CLINIC_ATTR => 'all']);

        self::assertStringContainsString($this->alphaName, $html, 'consented eligible clinic must be listed');
        self::assertStringContainsString($this->betaName, $html, 'consented eligible clinic must be listed');
        self::assertStringContainsString('cpms_clinic=' . $this->clinicAlpha, $html, 'each listing links through the bounded GET selector');
        $alphaPos = strpos($html, $this->alphaName);
        $betaPos = strpos($html, $this->betaName);
        self::assertIsInt($alphaPos);
        self::assertIsInt($betaPos);
        self::assertLessThan($betaPos, $alphaPos, 'deterministic ORDER BY name: Alpha before Beta');

        // Fail-closed disqualifications: no consent / suspended clinic / no active location.
        self::assertStringNotContainsString($this->charlieName, $html, 'no-consent clinic must never be listed');
        self::assertStringNotContainsString($this->deltaName, $html, 'suspended clinic must never be listed');
        self::assertStringNotContainsString($this->echoName, $html, 'clinic without any active Location must never be listed');
    }

    // ------------------------------------------------------------------
    // T3 — selected consented clinic: browse surface + back link + filters
    //       + membered doctor + working days + week scaffold + privacy
    // ------------------------------------------------------------------

    public function testSelectedClinicRendersBrowseWithMemberedDoctorAndWeekScaffold(): void
    {
        $this->setListingConsent($this->clinicBeta, true);
        $_GET['cpms_clinic'] = (string) $this->clinicBeta;

        $html = $this->renderSurface([self::CLINIC_ATTR => 'all']);

        self::assertSurfaceRendered($html, 'selected clinic browse');
        self::assertStringContainsString('data-clinic-id="' . $this->clinicBeta . '"', $html, 'the selected clinic binds the browse surface');
        self::assertStringContainsString('data-role="discovery-back"', $html, 'the browse surface offers the server-rendered back link');

        // Doctor directory: actively-membershiped doctor from clinic Alpha shows
        // on clinic Beta; inactive doctor never does.
        self::assertStringContainsString(
            'data-clinician-id="' . $this->doctorShared . '"',
            $html,
            'an ACTIVE home-elsewhere doctor with ACTIVE membership in the selected clinic must be listed (shared clinic)'
        );
        self::assertStringNotContainsString(
            'data-clinician-id="' . $this->doctorInactive . '"',
            $html,
            'inactive clinicians are never published (Slice1 T9 invariant)'
        );

        // Server-rendered filters + Persian/Arabic normalization in data-search.
        self::assertStringContainsString('data-role="doctor-search"', $html, 'name/specialty search input is server-rendered');
        self::assertStringContainsString('data-role="specialty-filter"', $html, 'specialty select is server-rendered');
        self::assertStringContainsString('data-role="doctor-filter-reset"', $html, 'reset control is server-rendered');
        $searchBlobs = [];
        if (preg_match_all('/data-search="([^"]*)"/', $html, $m) === 1 || preg_match_all('/data-search="([^"]*)"/', $html, $m) > 0) {
            $searchBlobs = $m[1];
        }
        self::assertNotSame([], $searchBlobs, 'every clinician button publishes a data-search blob');
        $normalized = false;
        foreach ($searchBlobs as $blob) {
            if (mb_strpos(urldecode($blob), 'سعید') !== false) {
                $normalized = true;
            }
        }
        self::assertTrue($normalized, 'data-search must fold Arabic ي to Persian ی (normalizeDoctorSearch)');
        self::assertStringContainsString('data-specialty="قلب"', $html, 'the specialty facet is published per doctor');

        // Clinic-local working days (cpms_schedule active rows for THIS clinic).
        self::assertStringContainsString('cpms-public-booking__clinician-day', $html, 'working-day chips render');
        self::assertStringContainsString('شنبه', $html, 'the active Saturday schedule row of this clinic renders as a working-day chip');

        // Weekly Jalali calendar scaffold — all strings/structure server-side.
        self::assertStringContainsString('data-role="week-nav"', $html, 'week navigation is server-rendered');
        self::assertStringContainsString('data-role="week-prev"', $html, 'previous-week control exists');
        self::assertStringContainsString('data-role="week-next"', $html, 'next-week control exists');
        self::assertStringContainsString('data-role="week-first"', $html, 'jump-to-first-available-week control exists');
        self::assertStringContainsString('data-role="week-empty"', $html, 'empty-week message is server-rendered');
        self::assertStringContainsString('data-template="day-empty"', $html, 'empty day cell template is server-rendered');

        $config = $this->extractConfig($html);
        self::assertNotSame([], $config, 'the browse surface publishes its runtime contract');
        self::assertSame($this->clinicBeta, (int) ($config['clinic_id'] ?? 0), 'config binds the selected clinic');
        self::assertSame(
            ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'],
            $config['weekday_names'] ?? null,
            'Persian weekday names are server-published for the week grid'
        );

        // Privacy: anonymous config has no nonce/PHI; raw HTML has no
        // continuation affordances (escaped JSON paths never match raw).
        self::assertArrayNotHasKey('nonce', $config, 'anonymous surface publishes no nonce');
        self::assertStringNotContainsString('nonce', (string) wp_json_encode($config), 'no nonce anywhere in the anonymous config');
        foreach (['/booking/hold', '/booking/confirm', '/appointments/mine', '/otp/request', '/otp/verify', 'wp-login.php'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $html,
                'the public HTML must not leak the raw continuation route `' . $forbidden . '`'
            );
        }
    }

    // ------------------------------------------------------------------
    // T4 — non-consented / unknown selection falls back to the directory
    // ------------------------------------------------------------------

    public function testIneligibleSelectedClinicFallsBackToDirectoryWithoutDisclosure(): void
    {
        // Charlie exists but has NO consent row: the selector must fall back
        // to the directory and must not distinguish "exists but hidden" from
        // "does not exist" (non-enumerating).
        $_GET['cpms_clinic'] = (string) $this->clinicCharlie;

        $html = $this->renderSurface([self::CLINIC_ATTR => 'all']);

        self::assertStringContainsString('data-discovery="1"', $html, 'ineligible selection falls back to the directory');
        self::assertStringNotContainsString($this->charlieName, $html, 'the hidden clinic is never disclosed');
        self::assertStringNotContainsString('data-clinic-id=', $html, 'no browse surface is produced for a hidden clinic');

        // A garbage selector behaves identically.
        $_GET['cpms_clinic'] = '999999995';
        $html2 = $this->renderSurface([self::CLINIC_ATTR => 'all']);
        self::assertStringContainsString('data-discovery="1"', $html2, 'unknown selection renders the same directory');
        self::assertStringNotContainsString('data-clinic-id=', $html2, 'unknown selection never renders a browse surface');
    }

    // ------------------------------------------------------------------
    // T5 — BC pin: bare shortcode stays the historical closed state
    // ------------------------------------------------------------------

    public function testBareShortcodeKeepsHistoricalClosedState(): void
    {
        $html = $this->renderSurface([]);

        self::assertSurfaceRendered($html, 'bare shortcode');
        self::assertStringContainsString('data-state="error"', $html, 'bare shortcode keeps the closed error state');
        self::assertStringNotContainsString('data-clinic-id=', $html, 'closed state publishes no clinic binding');
        self::assertStringNotContainsString(self::CONFIG_CLASS, $html, 'closed state publishes no runtime contract');
        self::assertStringNotContainsString('data-discovery="1"', $html, 'bare shortcode must NOT be treated as discovery mode');
    }

    // ------------------------------------------------------------------
    // T6 — shared-clinic A1/A4: participation-verified clinic, BC, parity
    // ------------------------------------------------------------------

    public function testAvailabilityAndQuoteEnforceSelectedClinicWithParity404(): void
    {
        $window = $this->availabilityWindow();
        $spec = $this->localSpecDaysAhead(5, '10:00:00');

        // With clinic_id = Beta (participating via ACTIVE membership): the
        // Beta-scoped slot becomes visible on A1.
        $res = $this->a1($this->doctorShared, $window['from'], $window['to'], $this->clinicBeta);
        self::assertSame(200, $res->get_status(), 'A1 with a participating clinic_id must stay public 200: ' . $this->describe($res));
        $days = $this->payload($res)['days'] ?? [];
        self::assertIsArray($days);
        self::assertNotNull(
            $this->findSlotInDays($days, $spec['date'], $spec['time']),
            'A1 scoped to the selected (participating) clinic returns that clinic\'s slot'
        );

        // BC: without clinic_id the historical home-clinic (Alpha) derivation
        // applies — the Beta slot is NOT advertised.
        $res = $this->a1($this->doctorShared, $window['from'], $window['to'], null);
        self::assertSame(200, $res->get_status());
        $days = $this->payload($res)['days'] ?? [];
        self::assertNull(
            $this->findSlotInDays($days, $spec['date'], $spec['time']),
            'BC: requests without clinic_id keep the home-clinic scope and never leak the shared clinic slot'
        );

        // Non-enumerating parity: a non-existent clinic_id fails exactly like
        // an unknown clinician (CLINIC_NOT_FOUND/404), never a permission oracle.
        $res = $this->a1($this->doctorShared, $window['from'], $window['to'], $this->ghostClinicId());
        self::assertSame(404, $res->get_status(), 'ghost clinic_id must fail-closed 404: ' . $this->describe($res));
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($res), 'same envelope as an unknown clinician');

        // A4 — participating clinic quotes the real slot…
        $res = $this->a4($this->doctorShared, $spec['date'], $spec['time'], $this->slotBeta, $this->locationBeta, $this->clinicBeta);
        self::assertSame(200, $res->get_status(), 'A4 participating clinic: ' . $this->describe($res));
        self::assertTrue((bool) ($this->payload($res)['available'] ?? false), 'the free Beta slot quotes available under the selected clinic');

        // …forging clinic_id = home clinic against the Beta slot fails 404
        // (clinic-scoped slot resolution — slot.clinic_id == selected clinic).
        $res = $this->a4($this->doctorShared, $spec['date'], $spec['time'], $this->slotBeta, $this->locationBeta, $this->clinicAlpha);
        self::assertSame(404, $res->get_status(), 'cross-clinic quote must be CLINIC_NOT_FOUND: ' . $this->describe($res));
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($res), 'cross-clinic parity with Slice2 404 envelope');

        // …and a ghost clinic_id behaves identically (non-enumerating).
        $res = $this->a4($this->doctorShared, $spec['date'], $spec['time'], $this->slotBeta, $this->locationBeta, $this->ghostClinicId());
        self::assertSame(404, $res->get_status());
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($res));
    }

    // ------------------------------------------------------------------
    // T7 — the four EXISTING routes gain the optional clinic_id arg; census
    //       of anonymous endpoints and route COUNT are unchanged elsewhere
    // ------------------------------------------------------------------

    public function testExistingRoutesPublishOptionalClinicIdArgWithoutNewRoutes(): void
    {
        $routes = rest_get_server()->get_routes();

        foreach ([
            self::NS . '/availability',
            self::NS . '/booking/quote',
            self::NS . '/booking/hold',
            self::NS . '/otp/request',
        ] as $route) {
            self::assertArrayHasKey($route, $routes, 'existing route must exist: ' . $route);
            $hasClinicArg = false;
            foreach ($routes[$route] as $endpoint) {
                if (is_array($endpoint) && isset($endpoint['args']['clinic_id'])) {
                    $hasClinicArg = true;
                    self::assertFalse(
                        (bool) ($endpoint['args']['clinic_id']['required'] ?? true),
                        'clinic_id must stay OPTIONAL on ' . $route . ' (BC for requests without it)'
                    );
                }
            }
            self::assertTrue($hasClinicArg, 'route must accept the shared-clinic selector: ' . $route);
        }

        // No new public route: the anonymous census set is unchanged.
        $anon = [];
        foreach ($routes as $route => $handlers) {
            foreach ($handlers as $endpoint) {
                if (!is_array($endpoint) || !isset($endpoint['permission_callback']) || !is_callable($endpoint['permission_callback'])) {
                    continue;
                }
                try {
                    $verdict = $endpoint['permission_callback'](new WP_REST_Request('GET', (string) $route));
                } catch (\Throwable) {
                    continue;
                }
                if ($verdict === true) {
                    $anon[] = (string) $route;
                }
            }
        }
        sort($anon);
        self::assertSame(
            [
                self::NS . '/availability',
                self::NS . '/booking/quote',
                self::NS . '/health',
                self::NS . '/otp/request',
                self::NS . '/otp/verify',
            ],
            $anon,
            'zero new anonymous endpoints — discovery is server-rendered HTML only'
        );
    }

    // ================= Fixture =================

    private function buildFixture(): void
    {
        $now = $this->nowUtcSql();
        $this->uid = uniqid('p10', false);

        $this->org = $this->insertRow('cpms_organizations', [
            'name' => 'Org P10 ' . $this->uid,
            'slug' => 'org-p10-' . $this->uid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s'], 'organization');

        $this->alphaName = 'Discovery Alpha ' . $this->uid;
        $this->betaName = 'Discovery Beta ' . $this->uid;
        $this->charlieName = 'Discovery Charlie ' . $this->uid;
        $this->deltaName = 'Discovery Delta ' . $this->uid;
        $this->echoName = 'Discovery Echo ' . $this->uid;

        $this->clinicAlpha = $this->insertClinic($this->alphaName, 'discovery-alpha-' . $this->uid, $now);
        $this->clinicBeta = $this->insertClinic($this->betaName, 'discovery-beta-' . $this->uid, $now);
        $this->clinicCharlie = $this->insertClinic($this->charlieName, 'discovery-charlie-' . $this->uid, $now);
        $this->clinicDelta = $this->insertClinic($this->deltaName, 'discovery-delta-' . $this->uid, $now);
        $this->clinicEcho = $this->insertClinic($this->echoName, 'discovery-echo-' . $this->uid, $now);

        $this->locationAlpha = $this->insertLocation($this->clinicAlpha, 'Alpha Main ' . $this->uid, 'alpha-main-' . $this->uid, $now);
        $this->locationBeta = $this->insertLocation($this->clinicBeta, 'Beta Main ' . $this->uid, 'beta-main-' . $this->uid, $now);
        $this->insertLocation($this->clinicCharlie, 'Charlie Main ' . $this->uid, 'charlie-main-' . $this->uid, $now);
        $this->insertLocation($this->clinicDelta, 'Delta Main ' . $this->uid, 'delta-main-' . $this->uid, $now);
        $this->locationEcho = $this->insertLocation($this->clinicEcho, 'Echo Main ' . $this->uid, 'echo-main-' . $this->uid, $now);

        // Shared doctor: HOME clinic Alpha, ACTIVE durable membership in Beta.
        $wpUserId = $this->createUser('p10doc-' . $this->uid);
        $this->doctorShared = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinicAlpha,
            'full_name' => 'دکتر سعيد ' . $this->uid,
            'specialty' => 'قلب',
            'is_active' => 1,
            'wp_user_id' => $wpUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%d', '%s', '%s'], 'clinician-shared');
        $this->insertRow('cpms_clinic_memberships', [
            'clinic_id' => $this->clinicBeta,
            'wp_user_id' => $wpUserId,
            'role_key' => 'doctor',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s'], 'membership');

        // Inactive doctor (home Beta) — must never be published.
        $this->doctorInactive = $this->insertClinician($this->clinicBeta, 'Dr P10 Inactive ' . $this->uid, 0, $now);

        // Clinic-local working day for the shared doctor in Beta (شنبه = 0).
        $this->insertRow('cpms_schedule', [
            'clinic_id' => $this->clinicBeta,
            'clinician_id' => $this->doctorShared,
            'day_of_week' => 0,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s'], 'schedule');

        // Booking policy for both clinics used on the A1/A4 paths.
        Settings::flushCache();
        foreach ([$this->clinicAlpha, $this->clinicBeta] as $clinicId) {
            $settings = new Settings(App::db(), $clinicId, App::audit());
            $settings->set('booking.min_lead_hours', 2);
            $settings->set('booking.max_future_days', 60);
        }
        Settings::flushCache();

        // The only seeded slot lives in Beta for the membered doctor.
        $spec = $this->localSpecDaysAhead(5, '10:00:00');
        $this->slotBeta = $this->seedSlot($this->clinicBeta, $this->locationBeta, $this->doctorShared, $spec);

        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        unset($_GET['cpms_clinic']);

        self::assertGreaterThan(1, $this->clinicAlpha, 'AD-13: fixture clinic ids must be dynamic, never 1');
        self::assertSame(0, get_current_user_id(), 'precondition: anonymous');
    }

    private function setListingConsent(int $clinicId, bool $enabled): void
    {
        (new Settings(App::db(), $clinicId, App::audit()))->set('public.listing_enabled', $enabled);
        Settings::flushCache();
    }

    private function insertClinic(string $name, string $slug, string $now): int
    {
        return $this->insertRow('cpms_clinics', [
            'organization_id' => $this->org,
            'name' => $name,
            'slug' => $slug,
            'timezone' => self::TZ_TEHRAN,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s'], 'clinic');
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $now): int
    {
        return $this->insertRow('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'address' => 'Address ' . $name,
            'timezone' => self::TZ_TEHRAN,
            'is_primary' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'], 'location');
    }

    private function insertClinician(int $clinicId, string $name, int $isActive, string $now): int
    {
        return $this->insertRow('cpms_clinicians', [
            'clinic_id' => $clinicId,
            'full_name' => $name,
            'is_active' => $isActive,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%d', '%s', '%s'], 'clinician');
    }

    private function createUser(string $login): int
    {
        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(24),
            'user_email' => $login . '@example.test',
            'role' => 'subscriber',
        ]);
        self::assertNotInstanceOf(\WP_Error::class, $userId, 'fixture user must be created');
        self::assertGreaterThan(0, (int) $userId);

        return (int) $userId;
    }

    private function seedSlot(int $clinicId, int $locationId, int $clinicianId, array $spec): int
    {
        return $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'slot_date' => $spec['date'],
            'slot_time' => $spec['time'],
            'duration_min' => 20,
            'capacity' => 1,
            'booked_count' => 0,
            'held_count' => 0,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => $this->nowUtcSql(),
            'updated_at' => $this->nowUtcSql(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'], 'slot');
    }

    /** @return array{date: string, time: string} */
    private function localSpecDaysAhead(int $days, string $time): array
    {
        $local = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ_TEHRAN))
            ->modify('+' . $days . ' days');

        return [
            'date' => $local->format('Y-m-d'),
            'time' => $time,
        ];
    }

    /** @return array{from: string, to: string} */
    private function availabilityWindow(): array
    {
        $utcToday = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'from' => $utcToday->modify('-1 day')->format('Y-m-d'),
            'to' => $utcToday->modify('+9 days')->format('Y-m-d'),
        ];
    }

    private function updateRow(string $table, array $data, array $where, array $formats): int
    {
        global $wpdb;
        $ok = $wpdb->update($wpdb->prefix . $table, $data, $where, $formats, $formats); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        self::assertNotFalse($ok, 'fixture update failed: ' . $wpdb->last_error);

        return (int) $ok;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $formats
     */
    private function insertRow(string $table, array $data, array $formats, string $label): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        self::assertTrue((bool) $ok, 'fixture ' . $label . ' insert failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture ' . $label . ' id must be positive/dynamic.');

        return $id;
    }

    private function ghostClinicId(): int
    {
        global $wpdb;
        $max = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . $wpdb->prefix . 'cpms_clinics'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        $ghost = $max + 987654;
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', $ghost)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertNull($exists, 'precondition: ghost clinic must not exist');

        return $ghost;
    }

    // ================= Render / REST helpers (Slice1 contract) =================

    /** @param array<string, string> $atts */
    private function renderSurface(array $atts): string
    {
        App::boot();

        $pairs = [];
        foreach ($atts as $key => $value) {
            $pairs[] = $key . '="' . $value . '"';
        }
        $shortcode = '[' . self::SHORTCODE . ($pairs === [] ? '' : ' ' . implode(' ', $pairs)) . ']';

        return (string) do_shortcode($shortcode);
    }

    private function assertSurfaceRendered(string $html, string $context): void
    {
        self::assertNotSame('', trim($html), $context . ': empty render.');
        self::assertStringNotContainsString(
            '[' . self::SHORTCODE,
            $html,
            $context . ': shortcode not registered — no patient-reachable surface.'
        );
    }

    /** @return array<string, mixed> */
    private function extractConfig(string $html): array
    {
        $pattern = '#<script type="application/json" class="' . preg_quote(self::CONFIG_CLASS, '#') . '">(.*?)</script>#s';
        if (preg_match($pattern, $html, $m) !== 1) {
            return [];
        }
        $decoded = json_decode(trim($m[1]), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function a1(int $clinicianId, ?string $from, ?string $to, ?int $clinicId): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', self::NS . '/availability');
        $request->set_param('clinician_id', $clinicianId);
        if (is_string($from) && $from !== '') {
            $request->set_param('from', $from);
        }
        if (is_string($to) && $to !== '') {
            $request->set_param('to', $to);
        }
        if ($clinicId !== null && $clinicId > 0) {
            $request->set_param('clinic_id', $clinicId);
        }

        return rest_do_request($request);
    }

    private function a4(int $clinicianId, string $slotDate, string $slotTime, ?int $slotId, ?int $locationId, ?int $clinicId): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::NS . '/booking/quote');
        $request->set_param('clinician_id', $clinicianId);
        $request->set_param('slot_date', $slotDate);
        $request->set_param('slot_time', $slotTime);
        if ($locationId !== null && $locationId > 0) {
            $request->set_param('location_id', $locationId);
        }
        if ($slotId !== null && $slotId > 0) {
            $request->set_param('slot_id', $slotId);
        }
        if ($clinicId !== null && $clinicId > 0) {
            $request->set_param('clinic_id', $clinicId);
        }

        return rest_do_request($request);
    }

    /** @return array<string, mixed> */
    private function payload(WP_REST_Response $res): array
    {
        $data = $res->get_data();
        if (!is_array($data)) {
            return [];
        }
        $inner = $data['data'] ?? null;

        return is_array($inner) ? $inner : [];
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $data = $res->get_data();
        if ($data instanceof \WP_Error) {
            return (string) $data->get_error_code();
        }

        return (string) (is_array($data) ? ($data['code'] ?? '') : '');
    }

    private function describe(WP_REST_Response $res): string
    {
        $data = $res->get_data();
        $encoded = is_scalar($data) || is_array($data) || $data === null
            ? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : get_class($data);

        return 'HTTP ' . $res->get_status() . ' body=' . substr($encoded, 0, 400);
    }

    /**
     * @param array<int, mixed> $days
     * @return array<string, mixed>|null
     */
    private function findSlotInDays(array $days, string $date, string $time): ?array
    {
        foreach ($days as $day) {
            if (!is_array($day) || (string) ($day['date'] ?? '') !== $date) {
                continue;
            }
            $slots = is_array($day['slots'] ?? null) ? $day['slots'] : [];
            foreach ($slots as $slot) {
                if (is_array($slot) && substr((string) ($slot['time'] ?? ''), 0, 5) === substr($time, 0, 5)) {
                    return $slot;
                }
            }
        }

        return null;
    }

    private function nowUtcSql(): string
    {
        return App::db()->nowUtcSql();
    }
}

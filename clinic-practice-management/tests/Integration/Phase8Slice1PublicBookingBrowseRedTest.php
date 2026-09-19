<?php
/**
 * Phase 8 Slice 1 — Anonymous patient public booking browse surface (TEST-ONLY RED).
 *
 * ============================================================================
 * SLICE CONTRACT UNDER TEST
 * ============================================================================
 *
 * A REAL, patient-reachable WordPress FRONTEND surface on which an ANONYMOUS
 * visitor can:
 *
 *   1. see the ACTIVE clinicians of ONE explicitly configured real Clinic;
 *   2. browse free availability through the EXISTING public A1 contract
 *      (`GET clinic/v1/availability`, `permPublic()`);
 *   3. see Jalali day labels and Location-local slot times;
 *   4. select a slot;
 *   5. have that selection checked through the EXISTING public A4 contract
 *      (`POST clinic/v1/booking/quote`, `permPublic()`);
 *   6. receive a clear bookable / policy-rejected / unavailable / empty /
 *      error state.
 *
 * Anchors: `docs/srs/SRS.md` FR-3.5 (بیمار پیش از Login می‌تواند تقویم و
 * Slotهای آزاد را ببیند — Read-only، بدون داده PHI) and `docs/srs/use-cases.md`
 * UC-01 (مشاهده تقویم و Slotهای آزاد بدون Login — بیمار آنونیم). Both are
 * explicitly recorded as Phase 8 (Patient Public Booking) and as NOT claimed by
 * Phase 6 — see `docs/roadmap/roadmap.md` §0 and `docs/drift-register.md`.
 *
 * Requirements of the surface: anonymous, read-only, no PHI, tenant-safe,
 * responsive, RTL, professionally usable, WordPress-native. No login is
 * required to browse / select / quote.
 *
 * ============================================================================
 * DELIVERY CONTRACT ENCODED BY THIS RED (smallest WordPress-native shape)
 * ============================================================================
 *
 * The repository has NO public frontend booking surface today: a census of the
 * main tree finds zero `add_shortcode()` / `do_shortcode()` calls anywhere.
 * This suite therefore encodes the smallest WordPress-native delivery contract
 * consistent with the existing architecture:
 *
 *  D-1  A frontend SHORTCODE surface, tag `cpms_public_booking`, registered by
 *       the plugin bootstrap so it is patient-reachable through the ordinary
 *       WordPress frontend (`do_shortcode()` on post content) — never a
 *       wp-admin-only surface.
 *  D-2  The surface is explicitly bound to ONE real Clinic through the
 *       shortcode attribute `clinic_id` (`[cpms_public_booking clinic_id="N"]`).
 *       No settings key, no migration, no schema change.
 *  D-3  FAIL CLOSED when the Clinic scope/configuration is absent or invalid:
 *       missing attribute, non-numeric attribute, `<= 0`, or a non-existent
 *       Clinic all render the SAME closed `error` state — never a usable
 *       booking surface, never any clinician identity, and never an implicit
 *       fallback to "clinic 1" / "the only clinic" / "the first clinic"
 *       (ADR-0031 AD-13). Enumeration parity: the closed state must not
 *       distinguish "does not exist" from "invalid input".
 *  D-4  Markup contract (greppable markers — these are the assertions, not a
 *       visual design):
 *         root          `<div class="cpms-public-booking" dir="rtl" lang="fa"
 *                            data-clinic-id="{id}" data-state="{state}">`
 *         clinician     an element carrying `cpms-public-booking__clinician`
 *                       and `data-clinician-id="{id}"`, with the clinician
 *                       `full_name` visible as text
 *         runtime cfg   `<script type="application/json"
 *                            class="cpms-public-booking__config">{...}</script>`
 *         states        `data-state` ∈ {bookable, policy_rejected, unavailable,
 *                       empty, error}
 *       `dir="rtl"` + `lang="fa"` on the root is the RTL requirement; the
 *       Jalali day labels come from A1's existing `days[].jalali` field.
 *  D-5  The published runtime config MUST point the browser at the EXISTING
 *       public contracts and at nothing else:
 *         rest_root          === rest_url('clinic/v1')  (trimmed of trailing /)
 *         availability_path  === '/availability'         (existing A1)
 *         quote_path         === '/booking/quote'        (existing A4)
 *         clinic_id          === the bound Clinic
 *         state_vocabulary   ⊇ {bookable, policy_rejected, unavailable,
 *                               empty, error}
 *  D-6  FRONTEND-SPECIFIC ASSETS ONLY — vanilla, zero external dependencies,
 *       no build step, no CDN, no framework:
 *         style handle  `cpms-public-booking` → assets/css/cpms-public-booking.css
 *         script handle `cpms-public-booking` → assets/js/cpms-public-booking.js
 *       enqueued only for a frontend render of the surface, and with NO effect
 *       on the existing scoped admin assets (`CpmsAssets`, handles `cpms-admin`,
 *       files `assets/css/cpms-admin.css` / `assets/js/cpms-admin.js`), which
 *       must stay untouched.
 *
 * ============================================================================
 * EXPLICITLY OUT OF THIS SLICE (asserted as guards, never implemented)
 * ============================================================================
 *
 * login · registration · OTP handoff · selection persistence across login ·
 * hold · confirm · patient portal · NEW REST endpoints · changing
 * `booking.max_future_days` from 60 to 30 · changing A1 min-lead visibility
 * semantics · A1/A4 rate limiting · caching · branding/polish beyond a
 * professional usable responsive UI · FR-5.4 / any Phase 7 work.
 *
 * T20 and T21 are the guards that keep those boundaries closed: T20 pins the
 * anonymous `clinic/v1` route census (so GREEN cannot add a public endpoint),
 * T21 pins the booking policy defaults (so GREEN cannot silently move
 * max_future_days 60 → 30 or min_lead_hours).
 *
 * ============================================================================
 * RED CLASSIFICATION — VERIFIED, not pre-claimed
 * ============================================================================
 *
 * Base HEAD `97efdb4` (no frontend surface exists). Accepted VALID RED head =
 * `21df8eb79b08cadb7c13f1d63e47d574e07dd22b`, CI run `35448849768`:
 *   Integration junit root — tests="1048" assertions="16436"
 *   errors="0" warnings="0" failures="13" skipped="0"   (time 110.43s)
 *   check runs at that exact SHA: 19/19 completed — 18 success + the intended
 *   Integration failure; Tenant Tripwire, Unit (PHP 8.1/8.2/8.3/8.4), PHPStan,
 *   WPCS, Real WP Acceptance (clinic_ + wp_), Staging Gate, Upgrade path,
 *   Responsive smoke, Closure (PHP 8.1/8.3/8.4 + WP 6.4/6.5/6.6 + destructive
 *   restoreApply) and Release Artifact all SUCCESS.
 *   Latest migration re-confirmed `2026_09_09_0020` (schema-0020 PASS) — this
 *   suite added no migration.
 * Zero collateral failures: all 13 failures belong to THIS suite — no
 * test-queue pollution. (The singleton priming used at that head became inert
 * once PR #88 landed and has since been removed as class-D test-infrastructure
 * cleanup; see the note at the end of this header. No assertion changed.)
 *
 * FAILS — 13 cases (product contract absent; the intended RED):
 *   T1  shortcode `cpms_public_booking` is not registered
 *   T2  render echoes the literal shortcode text instead of an RTL/fa surface
 *   T3  missing `clinic_id` — no closed state exists to render
 *   T4  non-numeric `clinic_id` — same
 *   T5  non-existent `clinic_id` — same
 *   T7  cross-Clinic exposure guard has no surface to guard
 *   T8  no-PHI guard has no surface to inspect
 *   T9  active/inactive clinician listing does not exist
 *   T10 `empty` state (valid Clinic, no active clinician) does not exist
 *   T18 published A1/A4 + five-state runtime config does not exist
 *   T19 no-login/no-hold/no-confirm affordance boundary has no surface
 *   T22 frontend asset files + conditional enqueue do not exist
 *   T23 frontend-only asset isolation has nothing to isolate
 *
 * PASSES ON HEAD — GUARD ONLY, and NOT RED evidence (correction recorded):
 *   T6  no implicit Clinic fallback / no clinician enumeration.
 *       This case asserts only ABSENCES, and an unregistered shortcode renders
 *       nothing at all, so it passes trivially at HEAD. It was originally
 *       classified above as RED; that classification was WRONG and is corrected
 *       here rather than silently rewritten. T6 carries no RED weight — its
 *       value is as a GREEN guard that catches an implicit fallback to
 *       "clinic 1" / "the only clinic" / "the first clinic" (AD-13) or a
 *       Clinician enumeration leak once a surface does exist.
 *
 * PASSES ON HEAD — 9 POSITIVE CONTROLS (behaviour GREEN must PRESERVE, not
 * rewrite; they are the proof that the surface rides contracts that already
 * work anonymously):
 *   T11 anonymous A1 returns Jalali day labels + `slot_id`/`location_id`
 *   T12 anonymous A1 keeps Location-local wall-clock across two distinct IANA
 *       zones inside ONE response (Tehran 10:00 and Berlin 14:30 unchanged)
 *   T13 anonymous A1 returns `{days: []}` for a clinician with no free slot
 *       (the `empty` state's upstream source)
 *   T14 anonymous A4 on a free future slot → `available: true` (`bookable`)
 *   T15 anonymous A4 inside min-lead → `CLINIC_POLICY_VIOLATION` / 409
 *       (`policy_rejected`)
 *   T16 anonymous A4 on a full slot → `available: false, capacity_left: 0`
 *       (`unavailable`)
 *   T17 anonymous A4 with Clinic A's clinician + Clinic B's slot fails closed
 *       `CLINIC_NOT_FOUND` / 404 (A4 tenant safety)
 *   T20 anonymous `clinic/v1` route census is exactly {availability,
 *       booking/quote, health, otp/request, otp/verify}
 *   T21 `booking.max_future_days` default is still 60 and
 *       `booking.min_lead_hours` default is still 2
 *
 * Every failing case fails on an ASSERTION against real WordPress APIs
 * (`shortcode_exists()`, `do_shortcode()`, `wp_style_is()`, `assertFileExists`)
 * — never on a PHP fatal. Nothing in this suite references a
 * not-yet-existing `ClinicCore\Frontend\*` class directly, because calling a
 * class that does not exist would be a harness failure, not product evidence
 * (the discipline recorded for the Phase 7 FR-5.5 RED).
 *
 * ============================================================================
 * ARCHITECTURAL RISK — RESOLVED UPSTREAM BY PR #88 (recorded, not asserted)
 * ============================================================================
 *
 * This suite originally recorded a real multi-Clinic risk: `App::bookingService()`
 * was a process-memoized singleton whose `Settings` dependency was resolved
 * eagerly through `App::scope()` at construction, while `RestClinicContext`
 * deliberately binds no scope for anonymous requests (`userId <= 0` returns
 * early). So on a genuine MULTI-Clinic installation the anonymous A1/A4 routes
 * could construct `BookingService` only if a scoped request had already built
 * the singleton earlier in the same PHP process — i.e. order-dependent, not a
 * property of the request. It was deliberately NOT turned into a RED case: an
 * order-dependent test cannot be attributed cleanly and would be INVALID RED
 * evidence.
 *
 * That risk is now fixed on `main` by PR #88 ("make REST bootstrap and booking
 * settings multi-Clinic safe", merged as e00cec8), which this branch has
 * forward-synced: REST bootstrap is scope-neutral and anonymous A1/A4 resolve
 * booking policy from the CLINICIAN'S PERSISTED Clinic (`BookingService` now
 * takes a `SettingsFactory` and calls `settingsFor($clinicId)` per operation).
 * The property is independently guarded in CI by
 * `tests/bin/multi-clinic-rest-bootstrap-probe.php`, which runs a
 * PROCESS-FRESH multi-Clinic REST bootstrap.
 *
 * ----------------------------------------------------------------------------
 * CLASS-D TEST-INFRASTRUCTURE CLEANUP MADE AT GREEN (reported, no assertion
 * touched)
 * ----------------------------------------------------------------------------
 *
 * Consequence of PR #88 for this file: the singleton priming this suite used at
 * setUp became INERT — `App::bookingService()` no longer reads ambient scope at
 * construction, so there is no memoized Settings clinic left to pin and no way
 * for this suite to pollute a later suite through it. The priming call, its
 * private method, and the stale comments describing the pre-#88 behaviour have
 * been removed.
 *
 * What did NOT change: the 23 test methods and every assertion inside them are
 * byte-identical (no assertion weakened, added, reworded, reordered, skipped or
 * deleted). The A1/A4 positive controls T11..T17 still execute against the real
 * merged PR #88 architecture — and are strictly stronger evidence now, because
 * they no longer depend on an artificial pre-priming of the singleton to be
 * reproducible.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Settings\Settings;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase8Slice1PublicBookingBrowseRedTest extends WP_UnitTestCase
{
    /** Existing public REST namespace (RestBase::NS). */
    private const NS = '/clinic/v1';

    /** D-1 — the frontend shortcode tag. */
    private const SHORTCODE = 'cpms_public_booking';

    /** D-2 — the explicit Clinic binding attribute. */
    private const CLINIC_ATTR = 'clinic_id';

    /** D-4 — greppable markup markers. */
    private const ROOT_CLASS = 'cpms-public-booking';
    private const CLINICIAN_CLASS = 'cpms-public-booking__clinician';
    private const CONFIG_CLASS = 'cpms-public-booking__config';
    private const CLINICIAN_ID_ATTR = 'data-clinician-id';
    private const STATE_ATTR = 'data-state';

    /** D-4 — the five states the surface must be able to express. */
    private const STATES = ['bookable', 'policy_rejected', 'unavailable', 'empty', 'error'];

    /** D-6 — frontend-only asset handles + files. */
    private const ASSET_HANDLE = 'cpms-public-booking';
    private const ASSET_CSS_REL = 'assets/css/cpms-public-booking.css';
    private const ASSET_JS_REL = 'assets/js/cpms-public-booking.js';

    /** D-6 — the existing scoped ADMIN assets that must stay untouched. */
    private const ADMIN_HANDLE = 'cpms-admin';

    /** T20 — the complete anonymous (permPublic) `clinic/v1` census at HEAD. */
    private const ANONYMOUS_ROUTES = [
        '/clinic/v1/availability',
        '/clinic/v1/booking/quote',
        '/clinic/v1/health',
        '/clinic/v1/otp/request',
        '/clinic/v1/otp/verify',
    ];

    /** T19 — affordances this slice must NOT offer. */
    private const FORBIDDEN_AFFORDANCES = [
        '/booking/hold',
        '/booking/confirm',
        '/booking/resume',
        '/appointments/mine',
        '/otp/request',
        '/otp/verify',
        'wp-login.php',
    ];

    /** Location timezones — distinct IANA zones, no hard-coded UTC offset. */
    private const TZ_TEHRAN = 'Asia/Tehran';
    private const TZ_BERLIN = 'Europe/Berlin';

    private int $orgA = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $clinicEmpty = 0;
    private int $locationA1 = 0;
    private int $locationA2 = 0;
    private int $locationB1 = 0;
    private int $clinicianA = 0;
    private int $clinicianAInactive = 0;
    private int $clinicianANoSlots = 0;
    private int $clinicianB = 0;
    private int $clinicianEmptyInactive = 0;
    private int $patientA = 0;

    /** Distinctive PHI strings that must never reach the public surface. */
    private string $phiMrn = '';
    private string $phiMobile = '';
    private string $phiFirstName = '';
    private string $phiLastName = '';

    /** @var array{slot_id:int,date:string,time:string} */
    private array $freeSlot = ['slot_id' => 0, 'date' => '', 'time' => ''];
    /** @var array{slot_id:int,date:string,time:string} */
    private array $berlinSlot = ['slot_id' => 0, 'date' => '', 'time' => ''];
    /** @var array{slot_id:int,date:string,time:string} */
    private array $fullSlot = ['slot_id' => 0, 'date' => '', 'time' => ''];
    /** @var array{slot_id:int,date:string,time:string} */
    private array $minLeadSlot = ['slot_id' => 0, 'date' => '', 'time' => ''];
    /** @var array{slot_id:int,date:string,time:string} */
    private array $clinicBSlot = ['slot_id' => 0, 'date' => '', 'time' => ''];

    // ================= Lifecycle =================

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        // Deterministic asset state — this suite asserts on enqueue handles, so
        // it must start from a known-empty slate and must not leak into others.
        wp_dequeue_style(self::ASSET_HANDLE);
        wp_dequeue_script(self::ASSET_HANDLE);
        wp_dequeue_style(self::ADMIN_HANDLE);
        wp_dequeue_script(self::ADMIN_HANDLE);

        // NOTE (class-D cleanup at GREEN): this setUp used to pin the memoized
        // `BookingService` singleton against the ambient single-Clinic install
        // *before* creating this suite's three extra Clinics, so that the first
        // construction could neither fail closed with CLINIC_SCOPE_REQUIRED nor
        // pin a fixture Clinic for the rest of the PHP process. PR #88 made REST
        // bootstrap scope-neutral and A1/A4 resolve Settings from the
        // clinician's persisted Clinic, so that priming became inert and was
        // removed. See the file header — no assertion changed.

        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        wp_dequeue_style(self::ASSET_HANDLE);
        wp_dequeue_script(self::ASSET_HANDLE);

        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // ================= T1 — the surface is a real WordPress shortcode =================

    public function testPublicBookingBrowseSurfaceIsRegisteredAsRealFrontendShortcode(): void
    {
        App::boot();

        self::assertTrue(
            shortcode_exists(self::SHORTCODE),
            'Phase 8 Slice 1 requires a REAL patient-reachable WordPress frontend surface. `'
            . self::SHORTCODE . '` is not registered via add_shortcode(), so no anonymous visitor '
            . 'can reach the public booking browse surface at all (census at HEAD: zero '
            . 'add_shortcode()/do_shortcode() calls exist anywhere in the repository).'
        );

        $handler = $GLOBALS['shortcode_tags'][self::SHORTCODE] ?? null;
        self::assertTrue(
            is_callable($handler),
            '`' . self::SHORTCODE . '` must be bound to a callable render handler; got '
            . (is_object($handler) ? get_class($handler) : gettype($handler)) . '.'
        );
    }

    // ================= T2 — RTL/Persian surface for an explicitly configured real Clinic =================

    public function testRenderForExplicitlyConfiguredRealClinicProducesRtlPersianSurface(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);

        $this->assertSurfaceRendered($html, 'explicitly configured real Clinic ' . $this->clinicA);

        // Attribute ORDER is deliberately not constrained — only that the root
        // element carries the RTL/language/binding contract.
        $root = $this->rootTag($html, 'explicitly configured real Clinic ' . $this->clinicA);

        self::assertStringContainsString(
            'dir="rtl"',
            $root,
            'The surface root must be RTL (dir="rtl") — the slice requires an RTL surface. Root: ' . $root
        );
        self::assertStringContainsString(
            'lang="fa"',
            $root,
            'The surface root must declare lang="fa" (Persian-localised surface). Root: ' . $root
        );
        self::assertStringContainsString(
            'data-clinic-id="' . $this->clinicA . '"',
            $root,
            'The surface must be explicitly bound to the configured Clinic (data-clinic-id). Root: ' . $root
        );
    }

    // ================= T3..T6 — fail closed on absent/invalid Clinic configuration =================

    public function testMissingClinicIdAttributeFailsClosedWithExplicitErrorState(): void
    {
        $html = $this->renderSurface([]);

        $this->assertSurfaceRendered($html, 'missing clinic_id attribute');
        $this->assertClosedFailSafeState($html, 'missing clinic_id');
    }

    public function testNonNumericClinicIdAttributeFailsClosedWithExplicitErrorState(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => 'not-a-clinic']);

        $this->assertSurfaceRendered($html, 'non-numeric clinic_id attribute');
        $this->assertClosedFailSafeState($html, 'non-numeric clinic_id');
    }

    public function testNonExistentClinicIdAttributeFailsClosedWithExplicitErrorState(): void
    {
        $ghost = $this->nonExistentClinicId();
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $ghost]);

        $this->assertSurfaceRendered($html, 'non-existent clinic_id ' . $ghost);
        $this->assertClosedFailSafeState($html, 'non-existent clinic_id ' . $ghost);
    }

    public function testAbsentClinicIdNeverFallsBackToAnyExistingClinic(): void
    {
        // AD-13: no `clinic_id = 1`, no "the only clinic", no "the first
        // clinic". With several real Clinics present, an unbound surface must
        // not resolve to any of them and must not enumerate them.
        //
        // CLASSIFICATION (corrected, see the file header): this asserts only
        // ABSENCES, so it PASSES trivially at HEAD where nothing renders at all.
        // It carries NO RED weight — it is a GREEN guard against an implicit
        // Clinic fallback or a Clinician enumeration leak. The RED force for the
        // unbound case is T3, which requires the explicit closed root state.
        $html = $this->renderSurface([]);

        foreach ([$this->clinicA, $this->clinicB, $this->clinicEmpty] as $clinicId) {
            self::assertStringNotContainsString(
                'data-clinic-id="' . $clinicId . '"',
                $html,
                'An unbound surface must never implicitly resolve to Clinic ' . $clinicId . ' (ADR-0031 AD-13).'
            );
        }

        foreach ($this->allClinicianNames() as $name) {
            self::assertStringNotContainsString(
                $name,
                $html,
                'An unbound surface must never expose any clinician identity (' . $name . ').'
            );
        }
    }

    // ================= T7 — tenant isolation across Clinics =================

    public function testSurfaceBoundToClinicANeverExposesClinicBClinicians(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (tenant isolation)');

        self::assertStringNotContainsString(
            $this->clinicianNameOf($this->clinicianB),
            $html,
            'Tenant safety: the Clinic A surface must never expose a Clinic B clinician name.'
        );
        self::assertStringNotContainsString(
            self::CLINICIAN_ID_ATTR . '="' . $this->clinicianB . '"',
            $html,
            'Tenant safety: the Clinic A surface must never expose a Clinic B clinician id.'
        );
        self::assertStringNotContainsString(
            'data-clinic-id="' . $this->clinicB . '"',
            $html,
            'Tenant safety: the Clinic A surface must never reference Clinic B.'
        );
    }

    // ================= T8 — no PHI on the public surface =================

    public function testRenderedPublicSurfaceContainsNoPatientPhi(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (no-PHI)');

        foreach ([$this->phiMrn, $this->phiMobile, $this->phiFirstName, $this->phiLastName] as $phi) {
            self::assertNotSame('', $phi, 'fixture precondition: PHI witness value must be non-empty');
            self::assertStringNotContainsString(
                (string) $phi,
                $html,
                'FR-3.5: the pre-login public surface is read-only and must carry NO PHI — found "' . $phi . '".'
            );
        }

        // The published runtime config is what the browser acts on, so it is the
        // second place PHI could leak. Patient identity fields have no business
        // in a public browse contract at all.
        $config = $this->extractConfig($html);
        $keys = array_map('strtolower', array_keys($config));
        foreach (['patient', 'mrn', 'mobile', 'national_id', 'identity'] as $forbiddenKey) {
            self::assertNotContains(
                $forbiddenKey,
                $keys,
                'FR-3.5: the public runtime config must not carry a patient-identity field `'
                . $forbiddenKey . '`. Published keys: ' . implode(', ', $keys)
            );
        }
    }

    // ================= T9 — active clinicians only =================

    public function testActiveCliniciansOfBoundClinicAreListedAndInactiveOnesExcluded(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (clinician listing)');

        self::assertStringContainsString(
            self::CLINICIAN_CLASS,
            $html,
            'The surface must render a clinician list using marker `' . self::CLINICIAN_CLASS . '` (D-4).'
        );

        foreach ([$this->clinicianA, $this->clinicianANoSlots] as $activeId) {
            self::assertStringContainsString(
                self::CLINICIAN_ID_ATTR . '="' . $activeId . '"',
                $html,
                'Active clinician ' . $activeId . ' of the bound Clinic must be selectable on the surface.'
            );
            self::assertStringContainsString(
                $this->clinicianNameOf($activeId),
                $html,
                'Active clinician ' . $activeId . ' must be listed by visible name.'
            );
        }

        self::assertStringNotContainsString(
            self::CLINICIAN_ID_ATTR . '="' . $this->clinicianAInactive . '"',
            $html,
            'An INACTIVE clinician (is_active = 0) must not be offered for public booking.'
        );
        self::assertStringNotContainsString(
            $this->clinicianNameOf($this->clinicianAInactive),
            $html,
            'An INACTIVE clinician must not be listed by name on the public surface.'
        );
    }

    // ================= T10 — explicit `empty` state, distinct from fail-closed `error` =================

    public function testValidClinicWithNoActiveClinicianRendersExplicitEmptyStateNotError(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicEmpty]);

        $this->assertSurfaceRendered($html, 'valid Clinic ' . $this->clinicEmpty . ' with no active clinician');

        // Scoped to the ROOT element: `empty` and `error` are top-level surface
        // states and must be mutually exclusive there.
        $root = $this->rootTag($html, 'valid Clinic ' . $this->clinicEmpty . ' with no active clinician');

        self::assertStringContainsString(
            self::STATE_ATTR . '="empty"',
            $root,
            'A VALID Clinic with no bookable clinician must produce the clear root `empty` state '
            . '(the slice requires a distinct empty state). Root: ' . $root
        );
        self::assertStringNotContainsString(
            self::STATE_ATTR . '="error"',
            $root,
            '`empty` (valid Clinic, nothing to book) must NOT be conflated with `error` '
            . '(absent/invalid Clinic configuration). Root: ' . $root
        );
        self::assertStringContainsString(
            'data-clinic-id="' . $this->clinicEmpty . '"',
            $root,
            'The `empty` state must still be bound to the valid configured Clinic — it is a real '
            . 'Clinic with nothing to book, not a fail-closed configuration. Root: ' . $root
        );
        self::assertStringNotContainsString(
            self::CLINICIAN_ID_ATTR . '="' . $this->clinicianEmptyInactive . '"',
            $html,
            'The empty-state Clinic must not expose its inactive clinician.'
        );
    }

    // ================= T11..T13 — EXISTING public A1 contract, anonymous (positive controls) =================

    public function testAnonymousA1AvailabilityReturnsJalaliDayLabelsAndSlotIdentity(): void
    {
        $this->assertAnonymous();

        $window = $this->availabilityWindow();
        $res = $this->a1($this->clinicianA, $window['from'], $window['to']);

        self::assertSame(
            200,
            $res->get_status(),
            'A1 is an EXISTING public contract and must stay anonymously reachable (permPublic). Got: '
            . $this->describe($res)
        );

        $days = $this->payload($res)['days'] ?? null;
        self::assertIsArray($days, 'A1 must return `{days: [...]}`. Got: ' . $this->describe($res));
        self::assertNotSame([], $days, 'A1 must return the seeded free slot for the bound Clinic clinician.');

        $byDate = [];
        foreach ($days as $day) {
            $byDate[(string) $day['date']] = $day;
        }

        self::assertArrayHasKey(
            $this->freeSlot['date'],
            $byDate,
            'A1 must expose the seeded free Tehran slot date ' . $this->freeSlot['date'] . '. Got: '
            . $this->describe($res)
        );

        $day = $byDate[$this->freeSlot['date']];

        // FR-3.6 / contract item 3 — Jalali day label for the UI.
        self::assertSame(
            Jalali::formatYmd($this->freeSlot['date']),
            (string) ($day['jalali'] ?? ''),
            'A1 must carry the Jalali day label the surface displays (existing `days[].jalali`).'
        );

        $slot = $this->findSlot($day, $this->freeSlot['time']);
        self::assertNotNull($slot, 'A1 must expose the seeded free slot at ' . $this->freeSlot['time'] . '.');
        self::assertSame(2, (int) $slot['capacity_left'], 'A1 must report remaining capacity for a free slot.');
        self::assertSame(
            $this->freeSlot['slot_id'],
            (int) ($slot['slot_id'] ?? 0),
            'A1 must expose `slot_id` so the surface can select the EXACT slot identity (multi-Location safe).'
        );
        self::assertSame(
            $this->locationA1,
            (int) ($slot['location_id'] ?? 0),
            'A1 must expose `location_id` so the surface can label Location-local slot times.'
        );
    }

    public function testAnonymousA1KeepsLocationLocalWallClockAcrossDistinctTimezones(): void
    {
        $this->assertAnonymous();

        $window = $this->availabilityWindow();
        $res = $this->a1($this->clinicianA, $window['from'], $window['to']);
        self::assertSame(200, $res->get_status(), 'A1 must stay anonymously reachable. Got: ' . $this->describe($res));

        $days = $this->payload($res)['days'] ?? [];
        self::assertIsArray($days, 'A1 must return `{days: [...]}`.');

        // Contract item 3 — "Location-local slot times". Two Locations of the
        // same Clinic in two distinct IANA zones (no DST ambiguity asserted, no
        // hard-coded offset): each slot's `time` must come back as the PERSISTED
        // local wall-clock value, never re-based into UTC or into another zone.
        $tehran = $this->findSlotInDays($days, $this->freeSlot['date'], $this->freeSlot['time']);
        $berlin = $this->findSlotInDays($days, $this->berlinSlot['date'], $this->berlinSlot['time']);

        self::assertNotNull(
            $tehran,
            'A1 must expose the Tehran Location slot at its persisted local time '
            . $this->freeSlot['time'] . '. Got: ' . $this->describe($res)
        );
        self::assertNotNull(
            $berlin,
            'A1 must expose the Berlin Location slot at its persisted local time '
            . $this->berlinSlot['time'] . ' — a Location-local wall-clock must not be '
            . 're-based to UTC or to another Location zone. Got: ' . $this->describe($res)
        );

        self::assertSame($this->locationA1, (int) ($tehran['location_id'] ?? 0), 'Tehran slot Location identity.');
        self::assertSame($this->locationA2, (int) ($berlin['location_id'] ?? 0), 'Berlin slot Location identity.');
        self::assertNotSame(
            (int) ($tehran['location_id'] ?? 0),
            (int) ($berlin['location_id'] ?? 0),
            'Precondition: the two slots must belong to two distinct Locations.'
        );

        // Neither zone may collapse onto the other: the two local times differ
        // and both survive the round-trip unchanged.
        self::assertNotSame(
            (string) $tehran['time'],
            (string) $berlin['time'],
            'Precondition: the fixture must use distinct local times per Location.'
        );
    }

    public function testAnonymousA1ReturnsEmptyDaysForClinicianWithoutFreeSlots(): void
    {
        $this->assertAnonymous();

        $window = $this->availabilityWindow();
        $res = $this->a1($this->clinicianANoSlots, $window['from'], $window['to']);

        self::assertSame(200, $res->get_status(), 'A1 must stay 200 for a clinician with nothing free. Got: ' . $this->describe($res));
        self::assertSame(
            ['days' => []],
            $this->payload($res),
            'A1 must return `{days: []}` (the upstream source of the surface `empty` state) — '
            . 'a public endpoint stays 200 and fails closed without an error. Got: ' . $this->describe($res)
        );
    }

    // ================= T14..T17 — EXISTING public A4 quote contract, anonymous (positive controls) =================

    public function testAnonymousA4QuoteOnFreeFutureSlotIsBookable(): void
    {
        $this->assertAnonymous();

        $res = $this->a4($this->clinicianA, $this->freeSlot['date'], $this->freeSlot['time'], $this->freeSlot['slot_id']);

        self::assertSame(200, $res->get_status(), 'A4 is an EXISTING public contract. Got: ' . $this->describe($res));
        $quote = $this->payload($res);
        self::assertTrue(
            (bool) ($quote['available'] ?? false),
            'A4 must report the free future slot as `bookable`. Got: ' . $this->describe($res)
        );
        self::assertSame(2, (int) ($quote['capacity_left'] ?? 0), 'A4 must report remaining capacity.');
        self::assertSame(
            $this->freeSlot['slot_id'],
            (int) ($quote['slot_id'] ?? 0),
            'A4 must echo the exact slot identity the visitor selected.'
        );
        self::assertSame(
            $this->locationA1,
            (int) ($quote['location_id'] ?? 0),
            'A4 must echo the Location of the selected slot.'
        );
    }

    public function testAnonymousA4QuoteInsideMinLeadIsPolicyRejected(): void
    {
        $this->assertAnonymous();

        // 20 minutes ahead violates the established min-lead under every value
        // this repository ever configures (default 2h; suites use 2h or 4h), so
        // the verdict does not depend on which Clinic's Settings the memoized
        // BookingService happens to hold.
        $res = $this->a4($this->clinicianA, $this->minLeadSlot['date'], $this->minLeadSlot['time'], $this->minLeadSlot['slot_id']);

        self::assertSame(409, $res->get_status(), 'A4 must reject a min-lead violation with 409. Got: ' . $this->describe($res));
        self::assertSame(
            'CLINIC_POLICY_VIOLATION',
            $this->errorCode($res),
            'A4 must reject a min-lead violation with the stable code CLINIC_POLICY_VIOLATION '
            . '(the upstream source of the surface `policy_rejected` state). Got: ' . $this->describe($res)
        );
    }

    public function testAnonymousA4QuoteOnFullSlotIsUnavailable(): void
    {
        $this->assertAnonymous();

        $res = $this->a4($this->clinicianA, $this->fullSlot['date'], $this->fullSlot['time'], $this->fullSlot['slot_id']);

        self::assertSame(200, $res->get_status(), 'A4 reports "full" as data, not as an HTTP error. Got: ' . $this->describe($res));
        $quote = $this->payload($res);
        self::assertFalse(
            (bool) ($quote['available'] ?? true),
            'A4 must report a fully booked slot as `unavailable`. Got: ' . $this->describe($res)
        );
        self::assertSame(0, (int) ($quote['capacity_left'] ?? -1), 'A4 must report zero remaining capacity.');
    }

    public function testAnonymousA4QuoteCrossClinicSlotFailsClosed(): void
    {
        $this->assertAnonymous();

        // Clinic A's clinician + Clinic B's slot identity: A4 must fail closed
        // with the established 404 parity — never quote across the tenant line.
        $res = $this->a4($this->clinicianA, $this->clinicBSlot['date'], $this->clinicBSlot['time'], $this->clinicBSlot['slot_id']);

        self::assertSame(
            404,
            $res->get_status(),
            'A4 must fail closed (404) when the selected slot belongs to another Clinic. Got: ' . $this->describe($res)
        );
        self::assertSame(
            'CLINIC_NOT_FOUND',
            $this->errorCode($res),
            'A4 must fail closed with the stable code CLINIC_NOT_FOUND and must not reveal the '
            . 'foreign slot. Got: ' . $this->describe($res)
        );
    }

    // ================= T18 — the surface publishes the EXISTING A1/A4 contract + five states =================

    public function testSurfacePublishesExistingAvailabilityAndQuoteContractWithFiveStateVocabulary(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (published runtime contract)');

        $config = $this->extractConfig($html);

        self::assertSame(
            rtrim(rest_url('clinic/v1'), '/'),
            rtrim((string) ($config['rest_root'] ?? ''), '/'),
            'The surface must publish the EXISTING `clinic/v1` REST root — no new namespace, no proxy.'
        );
        self::assertSame(
            '/availability',
            (string) ($config['availability_path'] ?? ''),
            'The surface must browse availability through the EXISTING public A1 route `/availability`.'
        );
        self::assertSame(
            '/booking/quote',
            (string) ($config['quote_path'] ?? ''),
            'The surface must check a selection through the EXISTING public A4 route `/booking/quote`.'
        );
        self::assertSame(
            $this->clinicA,
            (int) ($config['clinic_id'] ?? 0),
            'The published runtime config must carry the explicitly bound Clinic.'
        );

        $vocabulary = $config['state_vocabulary'] ?? null;
        self::assertIsArray(
            $vocabulary,
            'The surface must publish its state vocabulary so the frontend can render a clear '
            . 'bookable / policy-rejected / unavailable / empty / error state.'
        );
        $vocabulary = array_map('strval', (array) $vocabulary);
        self::assertSame(
            [],
            array_values(array_diff(self::STATES, $vocabulary)),
            'The published vocabulary must cover all five required states. Published: '
            . implode(', ', $vocabulary)
        );
    }

    // ================= T19 — anonymous, read-only: no login/OTP/hold/confirm =================

    public function testSurfaceOffersNoLoginOtpHoldOrConfirmAffordance(): void
    {
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (out-of-scope affordances)');

        foreach (self::FORBIDDEN_AFFORDANCES as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $html,
                'Out of this slice: the public browse surface must not offer `' . $forbidden . '` '
                . '(login / registration / OTP handoff / hold / confirm / patient portal).'
            );
        }

        $config = $this->extractConfig($html);
        $published = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($published, 'published config must be serializable');
        foreach (['/booking/hold', '/booking/confirm', '/booking/resume', '/appointments/mine'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                (string) $published,
                'Out of this slice: the published runtime config must not reference `' . $forbidden . '`.'
            );
        }
    }

    // ================= T20 — no new REST endpoints (anonymous route census guard) =================

    public function testNoNewAnonymousRestEndpointIsRegisteredForThePublicBookingSurface(): void
    {
        App::boot();
        $server = rest_get_server();
        $this->assertAnonymous();

        $routes = $server->get_routes();
        self::assertNotEmpty($routes, 'the REST server must expose the plugin routes');

        $anonymous = [];
        foreach ($routes as $path => $endpoints) {
            if (!is_string($path) || !str_starts_with($path, '/clinic/v1')) {
                continue;
            }
            if ($this->isAnonymousEndpoint($endpoints)) {
                $anonymous[] = $path;
            }
        }
        sort($anonymous);

        $expected = self::ANONYMOUS_ROUTES;
        sort($expected);

        self::assertSame(
            $expected,
            $anonymous,
            'Out of this slice: NO new REST endpoint. The anonymously reachable `clinic/v1` census '
            . 'must remain exactly the existing public set (A1 availability, A4 booking/quote, health, '
            . 'otp/request, otp/verify) — the public booking surface must ride the EXISTING A1/A4 '
            . 'contracts. Unexpected: ' . implode(', ', array_diff($anonymous, $expected))
            . ' | Missing: ' . implode(', ', array_diff($expected, $anonymous))
        );
    }

    // ================= T21 — out-of-scope booking policy defaults are unchanged =================

    public function testOutOfScopeBookingPolicyDefaultsRemainUnchanged(): void
    {
        self::assertSame(
            60,
            (int) Settings::DEFAULTS['booking.max_future_days'],
            'Out of this slice: `booking.max_future_days` must remain 60 — the 60 → 30 change is '
            . 'explicitly NOT part of Phase 8 Slice 1 and must not be resolved implicitly.'
        );
        self::assertSame(
            2,
            (int) Settings::DEFAULTS['booking.min_lead_hours'],
            'Out of this slice: A1/A4 min-lead semantics must not change — `booking.min_lead_hours` '
            . 'default remains 2.'
        );
    }

    // ================= T22 — frontend-only assets exist and are enqueued by the surface =================

    public function testFrontendAssetsExistAndAreEnqueuedByTheSurfaceRender(): void
    {
        $pluginDir = $this->pluginDir();

        self::assertFileExists(
            $pluginDir . self::ASSET_CSS_REL,
            'D-6: the surface needs frontend-specific CSS at ' . self::ASSET_CSS_REL . ' (vanilla, no build step).'
        );
        self::assertFileExists(
            $pluginDir . self::ASSET_JS_REL,
            'D-6: the surface needs frontend-specific JS at ' . self::ASSET_JS_REL . ' (vanilla, no build step).'
        );

        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (asset enqueue)');

        // The surface may enqueue directly from its render callback or from a
        // `wp_enqueue_scripts` handler gated on the shortcode being present —
        // both are WordPress-native, so fire the frontend hook when the render
        // alone did not enqueue.
        if (!$this->isEnqueued('style', self::ASSET_HANDLE) || !$this->isEnqueued('script', self::ASSET_HANDLE)) {
            do_action('wp_enqueue_scripts');
        }

        self::assertTrue(
            $this->isEnqueued('style', self::ASSET_HANDLE),
            'D-6: the frontend style handle `' . self::ASSET_HANDLE . '` must be enqueued for a page '
            . 'that renders the public booking surface.'
        );
        self::assertTrue(
            $this->isEnqueued('script', self::ASSET_HANDLE),
            'D-6: the frontend script handle `' . self::ASSET_HANDLE . '` must be enqueued for a page '
            . 'that renders the public booking surface.'
        );
    }

    // ================= T23 — assets are vanilla, dependency-free, and leave admin assets alone =================

    public function testFrontendAssetsAreDependencyFreeAndDoNotTouchAdminAssets(): void
    {
        $pluginDir = $this->pluginDir();
        $css = $pluginDir . self::ASSET_CSS_REL;
        $js = $pluginDir . self::ASSET_JS_REL;

        self::assertFileExists($css, 'D-6 precondition: ' . self::ASSET_CSS_REL . ' must exist.');
        self::assertFileExists($js, 'D-6 precondition: ' . self::ASSET_JS_REL . ' must exist.');

        $cssBody = (string) file_get_contents($css);
        $jsBody = (string) file_get_contents($js);

        foreach ([self::ASSET_CSS_REL => $cssBody, self::ASSET_JS_REL => $jsBody] as $label => $body) {
            self::assertNotSame('', trim($body), $label . ' must not be an empty stub.');
            self::assertDoesNotMatchRegularExpression(
                '#https?://#i',
                $body,
                'D-6: ' . $label . ' must have ZERO external dependencies — no CDN, no remote URL, '
                . 'consistent with the existing local-only asset policy.'
            );
        }
        self::assertDoesNotMatchRegularExpression(
            '#@import\s+url\(#i',
            $cssBody,
            'D-6: the frontend CSS must not pull remote stylesheets via @import url().'
        );

        // Admin assets must stay untouched by a frontend render.
        $html = $this->renderSurface([self::CLINIC_ATTR => (string) $this->clinicA]);
        $this->assertSurfaceRendered($html, 'Clinic A binding (admin asset isolation)');
        do_action('wp_enqueue_scripts');

        self::assertFalse(
            $this->isEnqueued('style', self::ADMIN_HANDLE),
            'D-6: a frontend render must NOT enqueue the scoped admin style `' . self::ADMIN_HANDLE . '`.'
        );
        self::assertFalse(
            $this->isEnqueued('script', self::ADMIN_HANDLE),
            'D-6: a frontend render must NOT enqueue the scoped admin script `' . self::ADMIN_HANDLE . '`.'
        );
    }

    // ================= Surface render helpers =================

    /**
     * Render the surface through the REAL WordPress shortcode pipeline.
     *
     * `do_shortcode()` is the patient-reachable path (post content on the
     * frontend). When the tag is not registered WordPress returns the literal
     * text unchanged — a clean, attributable assertion failure rather than a
     * PHP fatal, which is what keeps this RED valid.
     *
     * @param array<string, string> $atts
     */
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
        self::assertNotSame(
            '',
            trim($html),
            $context . ': the surface rendered an empty string.'
        );
        self::assertStringNotContainsString(
            '[' . self::SHORTCODE,
            $html,
            $context . ': WordPress echoed the shortcode back literally — `' . self::SHORTCODE
            . '` is not registered, so there is no patient-reachable public booking surface.'
        );
        self::assertStringContainsString(
            self::ROOT_CLASS,
            $html,
            $context . ': the rendered output must contain the surface root marker `' . self::ROOT_CLASS . '`.'
        );
    }

    /**
     * The surface root element (`<div class="cpms-public-booking" ...>`).
     *
     * Attribute order inside the tag is intentionally NOT constrained — only
     * the presence of the root marker and of the individual contract
     * attributes, so GREEN keeps freedom over markup ordering.
     */
    private function rootTag(string $html, string $context): string
    {
        $pattern = '/<div\b[^>]*class="[^"]*' . preg_quote(self::ROOT_CLASS, '/') . '[^"]*"[^>]*>/i';
        self::assertMatchesRegularExpression(
            $pattern,
            $html,
            $context . ': the surface must render a root element carrying the marker `'
            . self::ROOT_CLASS . '` (D-4).'
        );
        if (preg_match($pattern, $html, $m) !== 1) {
            return '';
        }

        return (string) $m[0];
    }

    /**
     * The fail-closed shape required by D-3: an explicit `error` state, no
     * bound Clinic, no clinician identity, and enumeration parity (the same
     * closed state for absent / invalid / non-existent configuration).
     */
    private function assertClosedFailSafeState(string $html, string $context): void
    {
        // Scoped to the ROOT element so a nested sub-component state can never
        // satisfy (or break) the top-level fail-closed contract.
        $root = $this->rootTag($html, $context);

        self::assertStringContainsString(
            self::STATE_ATTR . '="error"',
            $root,
            $context . ': absent/invalid Clinic configuration must FAIL CLOSED into an explicit '
            . 'root `error` state — never into a usable booking surface. Root: ' . $root
        );

        self::assertStringNotContainsString(
            'data-clinic-id="',
            $root,
            $context . ': a fail-closed root must not publish any bound Clinic id. Root: ' . $root
        );

        self::assertStringNotContainsString(
            self::CLINICIAN_CLASS,
            $html,
            $context . ': a fail-closed surface must not render any clinician entry.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/' . preg_quote(self::CLINICIAN_ID_ATTR, '/') . '="\d+"/',
            $html,
            $context . ': a fail-closed surface must not expose any clinician identity.'
        );

        foreach ($this->allClinicianNames() as $name) {
            self::assertStringNotContainsString(
                $name,
                $html,
                $context . ': a fail-closed surface must not leak clinician name "' . $name . '".'
            );
        }
    }

    /**
     * Extract the D-5 runtime config published to the frontend asset.
     *
     * @return array<string, mixed>
     */
    private function extractConfig(string $html): array
    {
        $pattern = '#<script[^>]+class="[^"]*' . preg_quote(self::CONFIG_CLASS, '/') . '[^"]*"[^>]*>(.*?)</script>#s';
        self::assertMatchesRegularExpression(
            $pattern,
            $html,
            'The surface must publish its runtime contract in a <script type="application/json" '
            . 'class="' . self::CONFIG_CLASS . '"> block so the frontend can drive the EXISTING '
            . 'public A1/A4 routes.'
        );
        if (preg_match($pattern, $html, $m) !== 1) {
            return [];
        }

        $decoded = json_decode(trim($m[1]), true);
        self::assertIsArray(
            $decoded,
            'The published runtime config must be valid JSON. Raw: ' . substr(trim($m[1]), 0, 300)
        );

        return is_array($decoded) ? $decoded : [];
    }

    // ================= Existing public contract helpers (A1 / A4) =================

    private function a1(int $clinicianId, ?string $from, ?string $to): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', self::NS . '/availability');
        $request->set_param('clinician_id', $clinicianId);
        if (is_string($from) && $from !== '') {
            $request->set_param('from', $from);
        }
        if (is_string($to) && $to !== '') {
            $request->set_param('to', $to);
        }

        // Deliberately NO nonce and NO auth header: this is the anonymous
        // patient-reachable path (permPublic).
        return rest_do_request($request);
    }

    private function a4(int $clinicianId, string $slotDate, string $slotTime, ?int $slotId): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::NS . '/booking/quote');
        $request->set_param('clinician_id', $clinicianId);
        $request->set_param('slot_date', $slotDate);
        $request->set_param('slot_time', $slotTime);
        if ($slotId !== null && $slotId > 0) {
            // Exact slot identity — avoids the documented CLINIC_SLOT_AMBIGUOUS
            // fail-closed branch when two Locations share a date/time tuple.
            $request->set_param('slot_id', $slotId);
        }

        return rest_do_request($request);
    }

    /**
     * @param mixed $endpoints
     */
    private function isAnonymousEndpoint($endpoints): bool
    {
        if (!is_array($endpoints)) {
            return false;
        }
        $request = new WP_REST_Request('GET', self::NS . '/availability');
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint) || !isset($endpoint['permission_callback'])) {
                continue;
            }
            $callback = $endpoint['permission_callback'];
            if (!is_callable($callback)) {
                continue;
            }
            try {
                // Safe for every callback in this codebase with user 0 and no
                // nonce: each one short-circuits on the nonce/user check before
                // any side effect (permPublic returns true; requireNonce fails
                // first for permCap/permAuthenticated/permAnyRole/requirePatient/
                // configMutation/reschedulePermission/cancelPermission;
                // requireClinicPermission returns 401 for userId <= 0).
                $verdict = $callback($request);
            } catch (Throwable $e) {
                continue;
            }
            if ($verdict === true) {
                return true;
            }
        }

        return false;
    }

    private function assertAnonymous(): void
    {
        self::assertSame(
            0,
            get_current_user_id(),
            'precondition: this slice is anonymous — no login, no patient session, no staff scope.'
        );
        self::assertNull(
            ScopeContext::tryGet(),
            'precondition: an anonymous public request must carry no explicit Clinic scope.'
        );
    }

    /**
     * @return array<string, mixed>
     */
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

        return 'HTTP ' . $res->get_status() . ' body=' . substr($encoded, 0, 600);
    }

    // ================= Slot/day lookup helpers =================

    /**
     * @param array<string, mixed> $day
     * @return array<string, mixed>|null
     */
    private function findSlot(array $day, string $time): ?array
    {
        $slots = $day['slots'] ?? [];
        if (!is_array($slots)) {
            return null;
        }
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            if (substr((string) ($slot['time'] ?? ''), 0, 5) === substr($time, 0, 5)) {
                return $slot;
            }
        }

        return null;
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
            $slot = $this->findSlot($day, $time);
            if ($slot !== null) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * A1 window: explicit `from`/`to` so the assertion never depends on the
     * derived default window (which needs an active schedule row with a
     * Location). Kept narrow enough to satisfy the span bound under every
     * `booking.max_future_days` value this repository configures (14 or 60).
     *
     * @return array{from: string, to: string}
     */
    private function availabilityWindow(): array
    {
        $utcToday = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'from' => $utcToday->sub(new DateInterval('P1D'))->format('Y-m-d'),
            'to' => $utcToday->add(new DateInterval('P9D'))->format('Y-m-d'),
        ];
    }

    /**
     * @return array{date: string, time: string}
     */
    private function localSpecMinutesAhead(int $minutes, string $tz): array
    {
        $local = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))
            ->add(new DateInterval('PT' . $minutes . 'M'));

        return ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i') . ':00'];
    }

    /**
     * @return array{date: string, time: string}
     */
    private function localSpecDaysAhead(int $days, string $time, string $tz): array
    {
        $local = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz))
            ->add(new DateInterval('P' . $days . 'D'));

        return ['date' => $local->format('Y-m-d'), 'time' => $time];
    }

    private function pluginDir(): string
    {
        self::assertTrue(
            defined('CPMS_PLUGIN_DIR') && is_string(constant('CPMS_PLUGIN_DIR')),
            'precondition: the plugin bootstrap must define CPMS_PLUGIN_DIR.'
        );

        return (string) constant('CPMS_PLUGIN_DIR');
    }

    private function isEnqueued(string $type, string $handle): bool
    {
        $check = $type === 'style' ? 'wp_style_is' : 'wp_script_is';

        return (bool) $check($handle, 'enqueued') || (bool) $check($handle, 'queue') || (bool) $check($handle, 'done');
    }

    // ================= Fixture =================

    /**
     * Build the real, committed-to-the-test-transaction topology.
     *
     * Two Clinics under one Organization (tenant isolation) plus a third valid
     * Clinic that has only an INACTIVE clinician (the `empty` state). All ids
     * are captured dynamically — never `clinic_id = 1`, never "the first row"
     * (ADR-0031 AD-13). Two Locations of Clinic A sit in distinct IANA zones so
     * no assertion can pass merely "because Asia/Tehran".
     */
    private function buildFixture(): void
    {
        $now = $this->nowUtcSql();
        $uid = uniqid('p8s1', false);

        $this->orgA = $this->insertRow('cpms_organizations', [
            'name' => 'Org P8S1 ' . $uid,
            'slug' => 'org-p8s1-' . $uid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s'], 'organization');

        $this->clinicA = $this->insertClinic('Clinic A P8S1 ' . $uid, 'clinic-a-p8s1-' . $uid, $now);
        $this->clinicB = $this->insertClinic('Clinic B P8S1 ' . $uid, 'clinic-b-p8s1-' . $uid, $now);
        $this->clinicEmpty = $this->insertClinic('Clinic Empty P8S1 ' . $uid, 'clinic-empty-p8s1-' . $uid, $now);

        $this->locationA1 = $this->insertLocation($this->clinicA, 'Main Tehran P8S1', 'main-tehran-p8s1-' . $uid, self::TZ_TEHRAN, 1, $now);
        $this->locationA2 = $this->insertLocation($this->clinicA, 'Branch Berlin P8S1', 'branch-berlin-p8s1-' . $uid, self::TZ_BERLIN, 0, $now);
        $this->locationB1 = $this->insertLocation($this->clinicB, 'Main B P8S1', 'main-b-p8s1-' . $uid, self::TZ_TEHRAN, 1, $now);

        $this->clinicianA = $this->insertClinician($this->clinicA, 'Dr. Public Tehran ' . $uid, 1, $now);
        $this->clinicianANoSlots = $this->insertClinician($this->clinicA, 'Dr. NoSlots ' . $uid, 1, $now);
        $this->clinicianAInactive = $this->insertClinician($this->clinicA, 'Dr. Inactive Hidden ' . $uid, 0, $now);
        $this->clinicianB = $this->insertClinician($this->clinicB, 'Dr. OtherTenant ' . $uid, 1, $now);
        $this->clinicianEmptyInactive = $this->insertClinician($this->clinicEmpty, 'Dr. EmptyClinic Inactive ' . $uid, 0, $now);

        // PHI witnesses — must never appear on the public surface (FR-3.5).
        $this->phiMrn = 'MRN-P8S1-PHI-' . strtoupper(substr(md5($uid), 0, 8));
        $this->phiMobile = '0912' . substr(strrev(substr(md5($uid . 'mobile'), 0, 7)), 0, 7);
        $this->phiFirstName = 'Phifirst' . substr($uid, -4);
        $this->phiLastName = 'Philast' . substr($uid, -4);
        $this->patientA = $this->insertRow('cpms_patients', [
            'clinic_id' => $this->clinicA,
            'mrn' => $this->phiMrn,
            'first_name' => $this->phiFirstName,
            'last_name' => $this->phiLastName,
            'mobile' => $this->phiMobile,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], 'patient');

        // Settings for the bound Clinic, at the documented defaults. Chosen
        // timings below are unambiguous under every value this repository ever
        // configures, so the verdicts do not depend on which Clinic's Settings
        // the memoized BookingService holds (see the header risk note).
        Settings::flushCache();
        $settings = new Settings(App::db(), $this->clinicA, App::audit());
        $settings->set('booking.min_lead_hours', 2);
        $settings->set('booking.max_future_days', 60);
        Settings::flushCache();

        // Slots — five distinct upstream signals for the five surface states.
        $free = $this->localSpecDaysAhead(5, '10:00:00', self::TZ_TEHRAN);
        $this->freeSlot = $this->seedSlot($this->clinicA, $this->locationA1, $this->clinicianA, $free, 2, 0, 0);

        $berlin = $this->localSpecDaysAhead(5, '14:30:00', self::TZ_BERLIN);
        $this->berlinSlot = $this->seedSlot($this->clinicA, $this->locationA2, $this->clinicianA, $berlin, 1, 0, 0);

        $full = $this->localSpecDaysAhead(6, '11:00:00', self::TZ_TEHRAN);
        $this->fullSlot = $this->seedSlot($this->clinicA, $this->locationA1, $this->clinicianA, $full, 1, 1, 0);

        $near = $this->localSpecMinutesAhead(20, self::TZ_TEHRAN);
        $this->minLeadSlot = $this->seedSlot($this->clinicA, $this->locationA1, $this->clinicianA, $near, 1, 0, 0);

        $other = $this->localSpecDaysAhead(5, '09:00:00', self::TZ_TEHRAN);
        $this->clinicBSlot = $this->seedSlot($this->clinicB, $this->locationB1, $this->clinicianB, $other, 1, 0, 0);

        // The surface must resolve its Clinic binding WITHOUT ambient scope, so
        // this suite never leaves an explicit scope behind that could hide a
        // fail-closed defect. Anonymous REST assertions re-check this.
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        self::assertGreaterThan(1, $this->clinicA, 'AD-13: the fixture Clinic id must be dynamic, never clinic 1.');
        self::assertNotSame($this->clinicA, $this->clinicB, 'precondition: two distinct real Clinics.');
        self::assertNotSame($this->locationA1, $this->locationA2, 'precondition: two distinct Locations.');
        self::assertSame(0, get_current_user_id(), 'precondition: the browse surface is anonymous.');
    }

    private function insertClinic(string $name, string $slug, string $now): int
    {
        return $this->insertRow('cpms_clinics', [
            'organization_id' => $this->orgA,
            'name' => $name,
            'slug' => $slug,
            'timezone' => self::TZ_TEHRAN,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s'], 'clinic');
    }

    private function insertLocation(int $clinicId, string $name, string $slug, string $tz, int $primary, string $now): int
    {
        return $this->insertRow('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => $tz,
            'is_primary' => $primary,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'], 'location');
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

    /**
     * @param array{date: string, time: string} $spec
     * @return array{slot_id: int, date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId, array $spec, int $capacity, int $booked, int $held): array
    {
        $slotId = $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'slot_date' => $spec['date'],
            'slot_time' => $spec['time'],
            'duration_min' => 20,
            'capacity' => $capacity,
            'booked_count' => $booked,
            'held_count' => $held,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => $this->nowUtcSql(),
            'updated_at' => $this->nowUtcSql(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'], 'slot');

        return ['slot_id' => $slotId, 'date' => $spec['date'], 'time' => $spec['time']];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $formats
     */
    private function insertRow(string $table, array $data, array $formats, string $label): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats);
        self::assertTrue(
            (bool) $ok,
            'fixture ' . $label . ' insert failed: ' . $wpdb->last_error // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture ' . $label . ' id must be positive/dynamic.');

        return $id;
    }

    private function nonExistentClinicId(): int
    {
        global $wpdb;
        $max = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . $wpdb->prefix . 'cpms_clinics'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        $ghost = $max + 987654;
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', $ghost)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertNull($exists, 'precondition: the ghost Clinic id must not exist.');

        return $ghost;
    }

    private function clinicianNameOf(int $clinicianId): string
    {
        global $wpdb;
        $name = $wpdb->get_var(
            $wpdb->prepare('SELECT full_name FROM ' . $wpdb->prefix . 'cpms_clinicians WHERE id = %d', $clinicianId) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        );
        self::assertIsString($name, 'precondition: clinician ' . $clinicianId . ' must exist in the fixture.');

        return (string) $name;
    }

    /**
     * @return list<string>
     */
    private function allClinicianNames(): array
    {
        $ids = [
            $this->clinicianA,
            $this->clinicianANoSlots,
            $this->clinicianAInactive,
            $this->clinicianB,
            $this->clinicianEmptyInactive,
        ];
        $names = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $names[] = $this->clinicianNameOf($id);
            }
        }
        self::assertNotSame([], $names, 'precondition: the fixture must own clinicians.');

        return $names;
    }

    private function nowUtcSql(): string
    {
        return App::db()->nowUtcSql();
    }
}

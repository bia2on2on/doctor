<?php

/**
 * Phase 8 Slice 4 — Public Clinic discovery + shared-clinician booking security.
 *
 * This suite is ADDITIVE: no existing Phase 8 assertion is modified, removed or
 * weakened. It pins the three contracts introduced by the discovery slice:
 *
 *  A. Public-listing CONSENT is fail-closed.
 *     A Clinic is advertised by `[cpms_public_booking clinic_id="all"]` only
 *     with an explicit `public.listing_opt_in` row of its own AND an active
 *     Organization, an active Clinic, at least one active Location and at least
 *     one eligible active clinician. Every gate is asserted independently.
 *
 *  B. The two modes coexist without regressing each other.
 *     `clinic_id="N"` keeps the established single-Clinic surface; a bare
 *     `[cpms_public_booking]` still fails closed; two shortcodes on one page
 *     render two independent roots with unique DOM ids and their own runtime
 *     config contract.
 *
 *  C. SHARED-CLINICIAN booking is Clinic-correct end to end.
 *     A clinician whose home Clinic is A but who holds an ACTIVE durable
 *     membership in B is bookable in B through A1 availability, A4 quote,
 *     A2 OTP binding and B1 hold — and every forged Clinic/clinician/slot tuple
 *     collapses to the SAME non-enumerating `CLINIC_NOT_FOUND` / 404 envelope.
 *     Without the new optional `clinic_id` selector every call keeps the legacy
 *     home-Clinic behaviour (backward compatibility).
 *
 * Presentation-only calendar helpers are asserted against the REAL exported
 * implementation in `assets/js/cpms-public-booking.js` (served by the server as
 * a JSON scaffold), never against a reimplementation in the browser.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase8Slice4PublicDiscoverySharedClinicTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const SHORTCODE = 'cpms_public_booking';
    private const TZ_TEHRAN = 'Asia/Tehran';

    /** Persian weekday labels the server may publish (no other label is valid). */
    private const WEEKDAY_LABELS = ['شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    private int $org = 0;
    private int $orgSuspended = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $clinicSuspended = 0;
    private int $clinicNoLocation = 0;
    private int $clinicOrgSuspended = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $locationInactive = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;
    private int $sharedClinician = 0;
    private int $inactiveClinician = 0;
    private int $patientUserId = 0;

    /** @var array{slot_id:int,date:string,time:string} */
    private array $sharedSlotB = ['slot_id' => 0, 'date' => '', 'time' => ''];
    /** @var array{slot_id:int,date:string,time:string} */
    private array $sharedSlotA = ['slot_id' => 0, 'date' => '', 'time' => ''];

    private string $uid = '';

    // ================= Lifecycle =================

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $this->uid = uniqid('p8s4', false);
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // =========================================================================
    // A — consent and lifecycle exclusions
    // =========================================================================

    public function testDirectoryFailsClosedWhenNoClinicOptedIn(): void
    {
        // Fixture consent is removed again: an eligible but NON-consenting
        // Clinic must never appear, even though every lifecycle gate passes.
        $this->deleteSetting($this->clinicA, 'public.listing_opt_in');
        Settings::flushCache();

        $html = $this->render('all');

        self::assertStringContainsString(
            'data-state="empty"',
            $html,
            'With zero opted-in Clinics the discovery surface must render its explicit empty state.'
        );
        self::assertStringNotContainsString(
            'Clinic A P8S4 ' . $this->uid,
            $html,
            'An eligible Clinic WITHOUT explicit public-listing consent must never be advertised.'
        );
    }

    public function testOptedInClinicIsListedWithCliniciansAndSpecialty(): void
    {
        $html = $this->render('all');

        self::assertStringContainsString('data-mode="discovery"', $html, 'Discovery mode must be announced in the root contract.');
        self::assertStringContainsString('Clinic A P8S4 ' . $this->uid, $html, 'The opted-in Clinic must be selectable.');
        self::assertStringContainsString('دکتر سارا احمدی', $html, 'Eligible home-Clinic clinician must be published.');
        self::assertStringContainsString('data-role="clinic-select"', $html, 'A Clinic selector must be rendered.');
        self::assertStringContainsString('data-role="doctor-search"', $html, 'Doctor-name search must be rendered.');
        self::assertStringContainsString('data-role="specialty-filter"', $html, 'Specialty filter must be rendered.');
        self::assertStringContainsString('option value="قلب"', $html, 'Specialty options come from the listed clinicians.');
    }

    public function testSuspendedClinicIsExcludedEvenWithConsent(): void
    {
        $this->setConsent($this->clinicSuspended);
        Settings::flushCache();

        $html = $this->render('all');

        self::assertStringNotContainsString(
            'Clinic Suspended P8S4 ' . $this->uid,
            $html,
            'A suspended Clinic must never be advertised, whatever its consent row says.'
        );
    }

    public function testClinicInSuspendedOrganizationIsExcluded(): void
    {
        $this->setConsent($this->clinicOrgSuspended);
        Settings::flushCache();

        $html = $this->render('all');

        self::assertStringNotContainsString(
            'Clinic OrgSusp P8S4 ' . $this->uid,
            $html,
            'A Clinic whose Organization is suspended must never be advertised.'
        );
    }

    public function testClinicWithoutActiveLocationIsExcluded(): void
    {
        $this->setConsent($this->clinicNoLocation);
        Settings::flushCache();

        $html = $this->render('all');

        self::assertStringNotContainsString(
            'Clinic NoLoc P8S4 ' . $this->uid,
            $html,
            'A Clinic with no active Location cannot be booked and must not be advertised.'
        );
    }

    public function testInactiveClinicianIsNeverPublished(): void
    {
        $html = $this->render('all');

        self::assertStringNotContainsString(
            'دکتر غیرفعال',
            $html,
            'An inactive clinician must never be published on a public surface.'
        );
    }

    public function testDirectoryNeverDisclosesPrivatePatientData(): void
    {
        $html = $this->render('all');

        self::assertStringNotContainsString('mrn', $html, 'No MRN may appear on the public discovery surface.');
        self::assertStringNotContainsString('09120000000', $html, 'No patient/clinician contact data may be published.');
        self::assertStringNotContainsString('reason', $html, 'Schedule-exception reasons must never be published.');
    }

    // =========================================================================
    // B — shortcode contracts and multi-root safety
    // =========================================================================

    public function testSingleClinicModeKeepsEstablishedContract(): void
    {
        $html = $this->render((string) $this->clinicA);

        self::assertStringContainsString('data-mode="single"', $html, 'Explicit clinic_id keeps the single-Clinic mode.');
        self::assertStringContainsString('data-clinic-id="' . $this->clinicA . '"', $html, 'The bound Clinic must be published.');
        self::assertStringContainsString('data-state="bookable"', $html, 'A valid Clinic with clinicians is bookable.');
        self::assertStringContainsString('دکتر سارا احمدی', $html, 'Home-Clinic clinician is listed.');
        self::assertStringNotContainsString('data-role="clinic-select"', $html, 'Single mode renders no Clinic selector.');
    }

    public function testBareShortcodeStillFailsClosed(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('data-state="error"', $html, 'A missing clinic_id must fail closed.');
        self::assertStringNotContainsString('cpms-public-booking__config', $html, 'The closed surface must publish no runtime contract.');
        self::assertStringNotContainsString('cpms-public-booking__clinician', $html, 'The closed surface must publish no clinician.');
    }

    public function testUnknownNonNumericClinicValueFailsClosed(): void
    {
        foreach (['all5', '0', '-3', 'clinic1', ''] as $value) {
            $html = $this->render($value);
            self::assertStringContainsString(
                'data-state="error"',
                $html,
                'Value "' . $value . '" must collapse to the closed state.'
            );
        }
    }

    public function testTwoRootsOnOnePageHaveUniqueDomIdsAndIndependentConfig(): void
    {
        $html = $this->render((string) $this->clinicA) . $this->render('all');

        self::assertSame(
            2,
            (int) preg_match_all('#class="cpms-public-booking(?: [^"]*)?"#', $html),
            'Both shortcode instances must render their own root.'
        );
        preg_match_all('/id="(cpms-public-booking-[^"]+)"/', $html, $matches);
        $ids = $matches[1] ?? [];
        self::assertCount(2, $ids, 'Each root must carry its own unique id.');
        self::assertSame($ids, array_values(array_unique($ids)), 'Two roots must never share a DOM id.');

        self::assertSame(
            2,
            substr_count($html, 'cpms-public-booking__config'),
            'Each root must publish its own runtime config.'
        );
    }

    // =========================================================================
    // C — shared-clinician booking security (A1 / A4 / A2 / B1)
    // =========================================================================

    public function testAvailabilityWithSelectedClinicReturnsThatClinicsSlots(): void
    {
        $response = $this->get(self::NS . '/availability', [
            'clinician_id' => $this->sharedClinician,
            'clinic_id' => $this->clinicB,
        ]);

        self::assertSame(200, $response->get_status(), 'A1 for a participating Clinic must succeed.');
        $days = $this->days($response);
        self::assertNotEmpty($days, 'A1 must return the participating Clinic availability.');

        $locationIds = [];
        foreach ($days as $day) {
            foreach ((array) $day['slots'] as $slot) {
                $locationIds[] = (int) $slot['location_id'];
            }
        }
        self::assertContains($this->locationB, $locationIds, 'Slots must belong to Clinic B locations.');
        self::assertNotContains($this->locationA, $locationIds, 'Clinic A locations must not leak into a Clinic B response.');
    }

    public function testAvailabilityWithoutSelectorKeepsHomeClinicBehaviour(): void
    {
        $response = $this->get(self::NS . '/availability', ['clinician_id' => $this->sharedClinician]);

        self::assertSame(200, $response->get_status(), 'Legacy A1 call must keep working.');
        $days = $this->days($response);
        self::assertNotEmpty($days, 'Legacy A1 must still return the home-Clinic availability.');

        foreach ($days as $day) {
            foreach ((array) $day['slots'] as $slot) {
                self::assertSame(
                    $this->locationA,
                    (int) $slot['location_id'],
                    'Without a selector A1 must stay scoped to the clinician home Clinic.'
                );
            }
        }
    }

    public function testAvailabilityWithForeignClinicFailsClosedWithParity(): void
    {
        // clinicianB has NO participation in Clinic A.
        $forged = $this->get(self::NS . '/availability', [
            'clinician_id' => $this->clinicianB,
            'clinic_id' => $this->clinicA,
        ]);
        $unknown = $this->get(self::NS . '/availability', [
            'clinician_id' => 99999999,
            'clinic_id' => $this->clinicA,
        ]);

        $this->assertClinicError($forged, 'CLINIC_NOT_FOUND', 404, 'Forged Clinic/clinician must fail closed.');
        $this->assertClinicError($unknown, 'CLINIC_NOT_FOUND', 404, 'Unknown clinician must fail closed.');
        self::assertSame(
            $unknown->get_data()['code'],
            $forged->get_data()['code'],
            'Both refusals must be indistinguishable (enumeration parity).'
        );
    }

    public function testQuoteInSelectedSharedClinicIsBookable(): void
    {
        $response = $this->post(self::NS . '/booking/quote', [
            'clinician_id' => $this->sharedClinician,
            'slot_id' => $this->sharedSlotB['slot_id'],
            'slot_date' => $this->sharedSlotB['date'],
            'slot_time' => $this->sharedSlotB['time'],
            'location_id' => $this->locationB,
            'clinic_id' => $this->clinicB,
        ]);

        self::assertSame(200, $response->get_status(), 'A4 for a participating Clinic must succeed.');
        $data = $this->data($response);
        self::assertTrue($data['available'] ?? false, 'The Clinic B slot must quote as available.');
        self::assertSame($this->locationB, (int) ($data['location_id'] ?? 0), 'A4 must report the persisted Location.');
    }

    public function testQuoteWithSlotFromAnotherClinicFailsClosed(): void
    {
        $response = $this->post(self::NS . '/booking/quote', [
            'clinician_id' => $this->sharedClinician,
            'slot_id' => $this->sharedSlotA['slot_id'],
            'slot_date' => $this->sharedSlotA['date'],
            'slot_time' => $this->sharedSlotA['time'],
            'location_id' => $this->locationA,
            'clinic_id' => $this->clinicB,
        ]);

        $this->assertClinicError(
            $response,
            'CLINIC_NOT_FOUND',
            404,
            'A slot persisted in another Clinic must never be quoted through the selected Clinic.'
        );
    }

    public function testOtpRequestBindsChallengeToSelectedSharedClinic(): void
    {
        $response = $this->post(self::NS . '/otp/request', [
            'mobile' => '09121234001',
            'clinician_id' => $this->sharedClinician,
            'slot_id' => $this->sharedSlotB['slot_id'],
            'slot_date' => $this->sharedSlotB['date'],
            'slot_time' => $this->sharedSlotB['time'],
            'clinic_id' => $this->clinicB,
        ]);

        self::assertSame(200, $response->get_status(), 'A2 must accept a selection in the participating Clinic.');
        self::assertSame(
            $this->clinicB,
            (int) $this->latestChallengeClinic('09121234001'),
            'The OTP challenge must be bound to the SELECTED Clinic, not the clinician home Clinic.'
        );
    }

    public function testOtpRequestWithForgedClinicTupleCreatesNoChallenge(): void
    {
        $before = $this->challengeCount('09121234002');
        $response = $this->post(self::NS . '/otp/request', [
            'mobile' => '09121234002',
            'clinician_id' => $this->sharedClinician,
            // Slot belongs to Clinic B, selector claims Clinic A.
            'slot_id' => $this->sharedSlotB['slot_id'],
            'slot_date' => $this->sharedSlotB['date'],
            'slot_time' => $this->sharedSlotB['time'],
            'clinic_id' => $this->clinicA,
        ]);

        $this->assertClinicError($response, 'CLINIC_NOT_FOUND', 404, 'A slot outside the resolved Clinic must fail closed.');
        self::assertSame($before, $this->challengeCount('09121234002'), 'A forged A2 selection must create no challenge.');
    }

    public function testHoldInSelectedSharedClinicPersistsSelectedClinic(): void
    {
        $response = $this->post(self::NS . '/booking/hold', [
            'clinician_id' => $this->sharedClinician,
            'slot_id' => $this->sharedSlotB['slot_id'],
            'slot_date' => $this->sharedSlotB['date'],
            'slot_time' => $this->sharedSlotB['time'],
            'location_id' => $this->locationB,
            'clinic_id' => $this->clinicB,
        ], $this->patientUserId, true);

        self::assertSame(200, $response->get_status(), 'B1 must hold a slot in the participating Clinic.');
        $token = (string) ($this->data($response)['hold_token'] ?? '');
        self::assertNotSame('', $token, 'B1 must return a hold token.');
        self::assertSame(
            $this->clinicB,
            (int) $this->holdClinic($token),
            'The hold must be persisted against the SELECTED Clinic.'
        );
    }

    public function testHoldWithForgedClinicIsRefusedWithoutCapacityEffect(): void
    {
        $before = $this->heldCount($this->sharedSlotB['slot_id']);
        $response = $this->post(self::NS . '/booking/hold', [
            'clinician_id' => $this->sharedClinician,
            'slot_id' => $this->sharedSlotB['slot_id'],
            'slot_date' => $this->sharedSlotB['date'],
            'slot_time' => $this->sharedSlotB['time'],
            'clinic_id' => $this->clinicA,
        ], $this->patientUserId, true);

        $this->assertClinicError($response, 'CLINIC_NOT_FOUND', 404, 'A forged Clinic selector must fail closed on B1.');
        self::assertSame($before, $this->heldCount($this->sharedSlotB['slot_id']), 'A refused hold must not consume capacity.');
    }

    /**
     * Shared clinician must NOT be advertised publicly until the booking stages
     * above are the source of truth — this asserts the interim gate explicitly so
     * flipping it is a deliberate, reviewable change.
     */
    public function testSharedClinicianPublicListingGateIsClosed(): void
    {
        self::assertFalse(
            \ClinicCore\Application\Clinic\PublicClinicDirectory::PUBLIC_SHARED_CLINICIAN_LISTING,
            'Shared-clinician public listing must stay closed unless the PR explicitly enables it.'
        );
    }

    // =========================================================================
    // D — weekly Jalali calendar and search/filter presentation contracts
    // =========================================================================

    public function testRuntimeConfigPublishesServerRenderedJalaliScaffold(): void
    {
        $config = $this->config($this->render((string) $this->clinicA));

        self::assertSame('single', $config['mode'] ?? null, 'Mode must be published.');
        self::assertSame(7, (int) ($config['week_length'] ?? 0), 'The calendar window must be seven days.');
        $calendar = $config['calendar'] ?? [];
        self::assertIsArray($calendar, 'The server must publish the calendar scaffold.');
        self::assertGreaterThanOrEqual(7, count($calendar), 'The scaffold must cover at least one full week.');

        $previous = null;
        foreach ($calendar as $entry) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $entry['date'], 'Dates are ISO YMD.');
            self::assertNotSame('', (string) $entry['jalali'], 'Every entry carries a server-rendered Jalali label.');
            self::assertContains((string) $entry['weekday'], self::WEEKDAY_LABELS, 'Weekday labels must be Persian.');
            if ($previous !== null) {
                self::assertSame(
                    86400,
                    strtotime((string) $entry['date'] . ' UTC') - strtotime($previous . ' UTC'),
                    'Scaffold dates must be consecutive days.'
                );
            }
            $previous = (string) $entry['date'];
        }

        $firstSeven = array_slice($calendar, 0, 7);
        self::assertCount(
            7,
            array_unique(array_map(static fn (array $e): string => (string) $e['weekday'], $firstSeven)),
            'One week must carry seven DISTINCT weekday labels.'
        );
    }

    public function testSearchIndexIsNormalizedForPersianAndArabicCharacters(): void
    {
        $html = $this->render((string) $this->clinicA);

        self::assertStringContainsString(
            'data-search="دکتر علی رضایی"',
            $html,
            'The Arabic-character name must be published in normalized (Persian) form for matching.'
        );
        self::assertStringNotContainsString('data-search="دكتر', $html, 'Arabic kaf/yeh must never survive normalization.');
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    private function buildFixture(): void
    {
        $now = $this->nowUtcSql();

        $this->org = $this->insertRow('cpms_organizations', [
            'name' => 'Org P8S4 ' . $this->uid,
            'slug' => 'org-p8s4-' . $this->uid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s']);

        $this->orgSuspended = $this->insertRow('cpms_organizations', [
            'name' => 'Org Susp P8S4 ' . $this->uid,
            'slug' => 'org-susp-p8s4-' . $this->uid,
            'status' => 'suspended',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s']);

        $this->clinicA = $this->insertClinic($this->org, 'Clinic A P8S4 ' . $this->uid, 'clinic-a-p8s4-' . $this->uid, 'active', $now);
        $this->clinicB = $this->insertClinic($this->org, 'Clinic B P8S4 ' . $this->uid, 'clinic-b-p8s4-' . $this->uid, 'active', $now);
        $this->clinicSuspended = $this->insertClinic($this->org, 'Clinic Suspended P8S4 ' . $this->uid, 'clinic-susp-p8s4-' . $this->uid, 'suspended', $now);
        $this->clinicNoLocation = $this->insertClinic($this->org, 'Clinic NoLoc P8S4 ' . $this->uid, 'clinic-noloc-p8s4-' . $this->uid, 'active', $now);
        $this->clinicOrgSuspended = $this->insertClinic($this->orgSuspended, 'Clinic OrgSusp P8S4 ' . $this->uid, 'clinic-orgsusp-p8s4-' . $this->uid, 'active', $now);

        $this->locationA = $this->insertLocation($this->clinicA, 'Main A P8S4', 'main-a-p8s4-' . $this->uid, 1, $now);
        $this->locationB = $this->insertLocation($this->clinicB, 'Main B P8S4', 'main-b-p8s4-' . $this->uid, 1, $now);
        $this->locationInactive = $this->insertLocation($this->clinicNoLocation, 'Closed P8S4', 'closed-p8s4-' . $this->uid, 0, $now);

        // The booking patient of the B1 case: an OTP-shaped account whose email
        // embeds the verified mobile (`<mobile>@otp.cpms.local`), which is the
        // established mobile authority used by BookingService::mobileForUser().
        $wpUserId = (int) wp_insert_user([
            'user_login' => 'p8s4-shared-' . $this->uid,
            'user_email' => '09121234999@otp.cpms.local',
            'user_pass' => wp_generate_password(20, true),
            'role' => \ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT,
        ]);
        self::assertGreaterThan(0, $wpUserId, 'Fixture WordPress user must be created.');
        $this->patientUserId = $wpUserId;

        // Shared clinician: home Clinic A, ACTIVE membership in Clinic B.

        $this->sharedClinician = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinicA,
            'wp_user_id' => $wpUserId,
            'full_name' => 'دكتر علي رضايي', // deliberately Arabic yeh/kaf
            'specialty' => 'قلب',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%d', '%s', '%s']);

        $this->insertRow('cpms_clinic_memberships', [
            'clinic_id' => $this->clinicB,
            'wp_user_id' => $wpUserId,
            'role_key' => 'doctor',
            'status' => 'active',
            'is_primary' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%d', '%s', '%s']);

        $this->clinicianA = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinicA,
            'full_name' => 'دکتر سارا احمدی',
            'specialty' => 'پوست',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        $this->clinicianB = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinicB,
            'full_name' => 'دکتر رضا کریمی',
            'specialty' => 'قلب',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        $this->inactiveClinician = $this->insertRow('cpms_clinicians', [
            'clinic_id' => $this->clinicA,
            'full_name' => 'دکتر غیرفعال',
            'specialty' => 'قلب',
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%d', '%s', '%s']);

        // Schedule rows: shared clinician works in BOTH clinics.
        $this->insertSchedule($this->clinicA, $this->locationA, $this->sharedClinician, 1, $now);
        $this->insertSchedule($this->clinicB, $this->locationB, $this->sharedClinician, 3, $now);
        $this->insertSchedule($this->clinicA, $this->locationA, $this->clinicianA, 2, $now);

        $future = $this->ymdDaysAhead(7);
        $this->sharedSlotA = $this->insertSlot($this->clinicA, $this->locationA, $this->sharedClinician, $future, '09:00:00', $now);
        $this->sharedSlotB = $this->insertSlot($this->clinicB, $this->locationB, $this->sharedClinician, $future, '11:00:00', $now);

        // Consent + permissive OTP policy for the two clinics under test.
        $this->setConsent($this->clinicA);
        foreach ([$this->clinicA, $this->clinicB] as $clinicId) {
            $this->setSetting($clinicId, 'otp.cooldown_sec', 0);
            $this->setSetting($clinicId, 'otp.daily_max', 50);
            $this->setSetting($clinicId, 'otp.hourly_max', 50);
        }
        Settings::flushCache();
    }

    /** @return list<array<string,mixed>> */
    private function days(WP_REST_Response $response): array
    {
        $data = $this->data($response);

        return is_array($data['days'] ?? null) ? $data['days'] : [];
    }

    /** @return array<string,mixed> */
    private function data(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if (!is_array($body)) {
            return [];
        }
        $data = $body['data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    private function config(string $html): array
    {
        if (preg_match('#<script type="application/json" class="cpms-public-booking__config">(.*?)</script>#s', $html, $m) !== 1) {
            self::fail('The rendered surface must publish its runtime config contract.');
        }
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        self::assertIsArray($decoded, 'The runtime config must be valid JSON.');

        return $decoded;
    }

    private function render(?string $clinicValue): string
    {
        App::boot();
        $atts = $clinicValue === null ? '' : ' clinic_id="' . $clinicValue . '"';

        return (string) do_shortcode('[' . self::SHORTCODE . $atts . ']');
    }

    private function get(string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    private function post(string $route, array $body, ?int $asUserId = null, bool $withNonce = false): WP_REST_Response
    {
        wp_set_current_user($asUserId ?? 0);
        $request = new WP_REST_Request('POST', $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }

        return rest_do_request($request);
    }

    private function assertClinicError(WP_REST_Response $response, string $code, int $status, string $what): void
    {
        self::assertSame($status, $response->get_status(), $what . ' Body: ' . wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertIsArray($data, 'Error envelope must be an array.');
        self::assertSame($code, (string) ($data['code'] ?? ''), $what);
    }

    private function challengeCount(string $mobile): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s',
            $mobile
        ));
    }

    private function latestChallengeClinic(string $mobile): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            $mobile
        ));

        return $value === null ? 0 : (int) $value;
    }

    private function holdClinic(string $token): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE token = %s LIMIT 1',
            $token
        ));

        return $value === null ? -1 : (int) $value;
    }

    private function heldCount(int $slotId): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1',
            $slotId
        ));

        return $value === null ? -1 : (int) $value;
    }

    private function setConsent(int $clinicId): void
    {
        $this->setSetting($clinicId, 'public.listing_opt_in', true);
    }

    private function setSetting(int $clinicId, string $key, mixed $value): void
    {
        global $wpdb;
        $table = App::db()->table('cpms_settings');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE clinic_id = %d AND `key` = %s", $clinicId, $key));
        $this->insertRow('cpms_settings', [
            'clinic_id' => $clinicId,
            'key' => $key,
            'value_json' => (string) wp_json_encode($value),
            'updated_at' => $this->nowUtcSql(),
        ], ['%d', '%s', '%s', '%s']);
        Settings::flushCache();
    }

    private function deleteSetting(int $clinicId, string $key): void
    {
        global $wpdb;
        $table = App::db()->table('cpms_settings');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE clinic_id = %d AND `key` = %s", $clinicId, $key));
    }

    private function insertClinic(int $orgId, string $name, string $slug, string $status, string $now): int
    {
        return $this->insertRow('cpms_clinics', [
            'organization_id' => $orgId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => self::TZ_TEHRAN,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    private function insertLocation(int $clinicId, string $name, string $slug, int $active, string $now): int
    {
        return $this->insertRow('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'address' => 'Address ' . $name,
            'timezone' => self::TZ_TEHRAN,
            'is_primary' => $active,
            'is_active' => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
    }

    private function insertSchedule(int $clinicId, int $locationId, int $clinicianId, int $dayOfWeek, string $now): void
    {
        $this->insertRow('cpms_schedule', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'day_of_week' => $dayOfWeek,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'appointment_duration_min' => 20,
            'slot_capacity' => 1,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);
    }

    /** @return array{slot_id:int,date:string,time:string} */
    private function insertSlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time, string $now): array
    {
        $slotId = $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'capacity' => 1,
            'booked_count' => 0,
            'held_count' => 0,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']);

        return ['slot_id' => $slotId, 'date' => $date, 'time' => substr($time, 0, 5)];
    }

    private function insertRow(string $table, array $data, array $formats): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert($wpdb->prefix . $table, $data, $formats);
        self::assertTrue((bool) $ok, 'fixture insert into ' . $table . ' failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture id must be positive/dynamic.');

        return $id;
    }

    private function nowUtcSql(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function ymdDaysAhead(int $days): string
    {
        $tz = new \DateTimeZone(self::TZ_TEHRAN);

        return (new \DateTimeImmutable('now', $tz))->modify('+' . $days . ' days')->format('Y-m-d');
    }
}

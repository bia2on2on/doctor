<?php
/**
 * RED 2 — wrong-Clinic booking Settings (TEST-ONLY RED).
 *
 * ============================================================================
 * THE CONTRACT UNDER TEST
 * ============================================================================
 *
 * Anonymous A1 (`GET clinic/v1/availability`) and anonymous A4
 * (`POST clinic/v1/booking/quote`) are `permPublic()`. For an anonymous caller
 * `RestClinicContext` deliberately binds NO ClinicScope (`userId <= 0` returns
 * early), so the ONLY trustworthy source of "which Clinic" is the PERSISTED
 * row: `cpms_clinicians.clinic_id`, read by `BookingService::requireClinician()`.
 *
 * The booking POLICY that is then applied (`booking.min_lead_hours` for A4,
 * `booking.max_future_days` for A1) must therefore come from THAT Clinic.
 *
 * Today it does not. `App::bookingService()` is a process-memoized singleton
 * whose `Settings` dependency is resolved ONCE, eagerly, through the AMBIENT
 * `App::scope()` at construction time — inside the `rest_api_init` closure of
 * `App::boot()`, i.e. before any request exists. The singleton then keeps that
 * one Clinic's `Settings` for the whole PHP process and evaluates anonymous
 * A1/A4 policy with it, for clinicians of ANY Clinic.
 *
 * ============================================================================
 * HOW THIS RED PROVES IT WITHOUT BEING ORDER-DEPENDENT
 * ============================================================================
 *
 * 1. Two real Clinics (A and B) with MATERIALLY DIFFERENT booking settings:
 *        Clinic A: min_lead_hours = 48 · max_future_days = 60
 *        Clinic B: min_lead_hours =  0 · max_future_days =  3
 *    Both are asserted from the persisted rows, and both are asserted to differ
 *    from the AMBIENT Clinic's policy as well — so the expected verdict cannot
 *    coincide with the ambient one by accident.
 *
 * 2. The expected verdict is not re-implemented in the test: it is produced by
 *    the REAL `BookingService` contract, executed with each Clinic's OWN
 *    `Settings`. Those two verdicts are asserted to be DIFFERENT, which is the
 *    proof that this RED is not passing "because the same verdict happens under
 *    both Clinics".
 *
 * 3. The REST verdict is then asserted to equal the verdict of the clinician's
 *    PERSISTED Clinic (B) and not the ambient/pre-primed one.
 *
 * 4. The REST server is primed ONCE in `setUp()` while the installation still
 *    holds exactly one Clinic, so this suite can neither be broken by, nor
 *    depend on, whatever a previously executed test class left in the PHPUnit
 *    process. Nothing in this suite clears `App` statics to manufacture
 *    isolation, and `App::bookingService()` is NEVER constructed under Clinic B.
 *
 * ============================================================================
 * OUT OF SCOPE (asserted nowhere; implemented nowhere)
 * ============================================================================
 *
 * No product code, no service refactor, no migration, no schema change, no
 * settings default change, and no change to A1 min-lead visibility semantics or
 * to OTP/rate-limit policy. Which construction chain must change for GREEN is
 * deliberately left open: this RED only proves the observable contract.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class MultiClinicBookingSettingsScopeRedTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private const TZ = 'Asia/Tehran';

    /**
     * Clinic A — a LARGE minimum lead: a slot 30 minutes away is policy-rejected.
     */
    private const MIN_LEAD_HOURS_A = 48;

    /**
     * Clinic B — ZERO minimum lead: the very same slot is bookable.
     *
     * Deliberately also different from the repository default (2) and from every
     * other value any existing suite configures (2 / 4 / 14 / 30 / 60), so the
     * expected verdict can never coincide with an ambient Clinic's verdict.
     */
    private const MIN_LEAD_HOURS_B = 0;

    /** Clinic A — a long booking horizon (the repository default). */
    private const MAX_FUTURE_DAYS_A = 60;

    /**
     * Clinic B — a SHORT horizon: an explicit 31-day span is invalid here and
     * valid under Clinic A / under the default.
     */
    private const MAX_FUTURE_DAYS_B = 3;

    /** The explicit A1 span, in days. 31 > 3 + 2 (invalid under B) · 31 <= 60 + 2 (valid under A). */
    private const SPAN_DAYS = 31;

    /** The A4 slot offset — well inside every min-lead boundary except Clinic A's. */
    private const NEAR_SLOT_MINUTES = 30;

    /**
     * The Clinic whose Settings the ambient/memoized BookingService is pinned to
     * in this PHPUnit process (recorded, never assumed).
     */
    private int $ambientClinicId = 0;

    private int $clinicA = 0;

    private int $clinicB = 0;

    private int $clinicianA = 0;

    private int $clinicianB = 0;

    /** @var array{slot_id: int, date: string, time: string} */
    private array $nearSlotA = ['slot_id' => 0, 'date' => '', 'time' => ''];

    /** @var array{slot_id: int, date: string, time: string} */
    private array $nearSlotB = ['slot_id' => 0, 'date' => '', 'time' => ''];

    // ================= Lifecycle =================

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        /*
         * Prime the REST server (and therefore every controller, and therefore
         * the memoized `App::bookingService()`) at the one moment in a test's
         * life when the ambient scope is deterministic: before this suite adds
         * its own Clinics. This is the same discipline the repository already
         * applies elsewhere, and it is what makes THIS suite independent of
         * PHPUnit class ordering — the routes are reachable whether or not any
         * earlier class happened to build them.
         *
         * The Settings instance the singleton captures here belongs to the
         * AMBIENT Clinic, not to Clinic A or Clinic B. That is precisely the
         * defect this RED reproduces; nothing here hides it.
         */
        App::boot();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        $this->ambientClinicId = App::scope()->clinicId;
        rest_get_server();

        $this->buildFixture();

        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // ================= RED case 1 — A4 / booking.min_lead_hours =================

    /**
     * A4 must apply the min-lead of the PERSISTED Clinic of the requested
     * clinician — never the min-lead of an ambient / pre-primed Clinic.
     */
    public function testAnonymousA4QuoteAppliesMinLeadHoursOfTheCliniciansPersistedClinic(): void
    {
        $this->assertAnonymousAndUnscoped();
        $this->assertPolicySettingsAreMateriallyDifferent();

        // CONTROL — the SAME slot geometry, evaluated by the real BookingService
        // with each Clinic's OWN persisted Settings. These are the two verdicts
        // the product route must be able to produce.
        $verdictUnderA = $this->quoteVerdictFor($this->clinicianA, $this->nearSlotA);
        $verdictUnderB = $this->quoteVerdictFor($this->clinicianB, $this->nearSlotB);

        self::assertNotSame(
            $verdictUnderA,
            $verdictUnderB,
            'CONTROL FAILED — this RED would prove nothing: the near slot ('
            . self::NEAR_SLOT_MINUTES . ' minutes ahead) must be policy-REJECTED under Clinic A (id '
            . $this->clinicA . ', booking.min_lead_hours=' . self::MIN_LEAD_HOURS_A . ') and ACCEPTED under Clinic B (id '
            . $this->clinicB . ', booking.min_lead_hours=' . self::MIN_LEAD_HOURS_B . '). Got the same verdict for both: '
            . $verdictUnderA
        );

        $actual = $this->verdictOf($this->a4Quote($this->clinicianB, $this->nearSlotB));

        self::assertSame(
            $verdictUnderB,
            $actual,
            'A4 must evaluate booking.min_lead_hours against the PERSISTED Clinic of the requested clinician — Clinic B (id '
            . $this->clinicB . ', booking.min_lead_hours=' . self::MIN_LEAD_HOURS_B . ') — not against the ambient/pre-primed Clinic (id '
            . $this->ambientClinicId . '). Verdict under Clinic B: ' . $verdictUnderB
            . ' · verdict under Clinic A: ' . $verdictUnderA . ' · actual route verdict: ' . $actual
        );
    }

    // ================= RED case 2 — A1 / booking.max_future_days =================

    /**
     * A1 must apply the booking horizon of the PERSISTED Clinic of the requested
     * clinician — never the horizon of an ambient / pre-primed Clinic.
     */
    public function testAnonymousA1AvailabilityAppliesMaxFutureDaysOfTheCliniciansPersistedClinic(): void
    {
        $this->assertAnonymousAndUnscoped();
        $this->assertPolicySettingsAreMateriallyDifferent();

        $span = $this->explicitSpan(self::SPAN_DAYS);

        // CONTROL — the SAME explicit span, evaluated by the real BookingService
        // with each Clinic's OWN persisted Settings.
        $verdictUnderA = $this->availabilityVerdictFor($this->clinicianA, $span);
        $verdictUnderB = $this->availabilityVerdictFor($this->clinicianB, $span);

        self::assertNotSame(
            $verdictUnderA,
            $verdictUnderB,
            'CONTROL FAILED — this RED would prove nothing: an explicit ' . self::SPAN_DAYS
            . '-day span must be VALID under Clinic A (id ' . $this->clinicA . ', booking.max_future_days='
            . self::MAX_FUTURE_DAYS_A . ') and INVALID under Clinic B (id ' . $this->clinicB
            . ', booking.max_future_days=' . self::MAX_FUTURE_DAYS_B . '). Got the same verdict for both: '
            . $verdictUnderA
        );

        $actual = $this->verdictOf($this->a1Availability($this->clinicianB, $span));

        self::assertSame(
            $verdictUnderB,
            $actual,
            'A1 must evaluate booking.max_future_days against the PERSISTED Clinic of the requested clinician — Clinic B (id '
            . $this->clinicB . ', booking.max_future_days=' . self::MAX_FUTURE_DAYS_B . ') — not against the ambient/pre-primed Clinic (id '
            . $this->ambientClinicId . '). Verdict under Clinic B: ' . $verdictUnderB
            . ' · verdict under Clinic A: ' . $verdictUnderA . ' · actual route verdict: ' . $actual
        );
    }

    // ================= Anonymous REST helpers =================

    private function a1Availability(int $clinicianId, array $span): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', self::NS . '/availability');
        $request->set_param('clinician_id', $clinicianId);
        $request->set_param('from', $span['from']);
        $request->set_param('to', $span['to']);

        // Deliberately NO nonce and NO auth header — this is the anonymous
        // patient-reachable path (permPublic).
        return rest_do_request($request);
    }

    private function a4Quote(int $clinicianId, array $slot): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::NS . '/booking/quote');
        $request->set_param('clinician_id', $clinicianId);
        $request->set_param('slot_id', $slot['slot_id']);
        $request->set_param('slot_date', $slot['date']);
        $request->set_param('slot_time', $slot['time']);

        return rest_do_request($request);
    }

    /**
     * Normalise any REST outcome to a comparable string.
     */
    private function verdictOf(WP_REST_Response $response): string
    {
        $data  = $response->get_data();
        $code  = is_array($data) && isset($data['code']) ? (string) $data['code'] : 'OK';

        return 'HTTP ' . $response->get_status() . ' / ' . $code;
    }

    // ================= CONTROL — the same contract, per-Clinic Settings =================

    /**
     * The very BookingService the anonymous REST route uses.
     *
     * CONTROL ARM. The contract under test is that booking policy is read from
     * the TRUSTED Clinic of the operation (the persisted clinician's Clinic),
     * so the same service instance MUST produce different verdicts for
     * clinicians of different Clinics. Running the two arms through this one
     * service is what makes that discriminating, and it also proves the
     * geometry/verdicts are real — so a route failure can be attributed to
     * wrong-Clinic Settings resolution rather than to fixture arithmetic.
     */
    private function bookingService(): BookingService
    {
        return App::bookingService();
    }

    /**
     * @param array{slot_id: int, date: string, time: string} $slot
     */
    private function quoteVerdictFor(int $clinicianId, array $slot): string
    {
        try {
            $this->bookingService()->quote(
                $clinicianId,
                $slot['date'],
                $slot['time'],
                $slot['slot_id']
            );

            return 'HTTP 200 / OK';
        } catch (BookingException $e) {
            return 'HTTP ' . $e->httpStatus . ' / ' . $e->errorCode;
        }
    }

    /**
     * @param array{from: string, to: string} $span
     */
    private function availabilityVerdictFor(int $clinicianId, array $span): string
    {
        try {
            $this->bookingService()->availability($clinicianId, $span['from'], $span['to']);

            return 'HTTP 200 / OK';
        } catch (BookingException $e) {
            return 'HTTP ' . $e->httpStatus . ' / ' . $e->errorCode;
        }
    }

    // ================= Guards =================

    private function assertAnonymousAndUnscoped(): void
    {
        self::assertSame(0, get_current_user_id(), 'precondition: the A1/A4 contracts under test are anonymous.');
        self::assertNull(ScopeContext::tryGet(), 'precondition: no explicit ClinicScope may be pre-bound for an anonymous A1/A4.');
    }

    /**
     * Materially assert the two Clinics' booking policy AND that the ambient
     * Clinic's policy differs from Clinic B's — otherwise the expected verdict
     * could coincide with the ambient one and this RED would be meaningless.
     */
    private function assertPolicySettingsAreMateriallyDifferent(): void
    {
        $settingsA     = App::settingsFactory()->forClinic($this->clinicA);
        $settingsB     = App::settingsFactory()->forClinic($this->clinicB);
        $settingsAmbient = App::settingsFactory()->forClinic($this->ambientClinicId);

        $leadA = (int) $settingsA->get('booking.min_lead_hours');
        $leadB = (int) $settingsB->get('booking.min_lead_hours');
        $leadAmbient = (int) $settingsAmbient->get('booking.min_lead_hours');

        self::assertSame(self::MIN_LEAD_HOURS_A, $leadA, 'fixture: Clinic A booking.min_lead_hours must be persisted as set.');
        self::assertSame(self::MIN_LEAD_HOURS_B, $leadB, 'fixture: Clinic B booking.min_lead_hours must be persisted as set.');
        self::assertNotSame($leadA, $leadB, 'fixture: the two Clinics must have materially different min_lead_hours.');
        self::assertNotSame(
            $leadAmbient,
            $leadB,
            'fixture: the AMBIENT Clinic (id ' . $this->ambientClinicId . ') must NOT share Clinic B\'s min_lead_hours, '
            . 'otherwise this RED could pass trivially. ambient=' . $leadAmbient . ' clinicB=' . $leadB
        );

        $futureA = (int) $settingsA->get('booking.max_future_days');
        $futureB = (int) $settingsB->get('booking.max_future_days');
        $futureAmbient = (int) $settingsAmbient->get('booking.max_future_days');

        self::assertSame(self::MAX_FUTURE_DAYS_A, $futureA, 'fixture: Clinic A booking.max_future_days must be persisted as set.');
        self::assertSame(self::MAX_FUTURE_DAYS_B, $futureB, 'fixture: Clinic B booking.max_future_days must be persisted as set.');
        self::assertNotSame($futureA, $futureB, 'fixture: the two Clinics must have materially different max_future_days.');
        self::assertNotSame(
            $futureAmbient,
            $futureB,
            'fixture: the AMBIENT Clinic (id ' . $this->ambientClinicId . ') must NOT share Clinic B\'s max_future_days, '
            . 'otherwise this RED could pass trivially. ambient=' . $futureAmbient . ' clinicB=' . $futureB
        );

        self::assertNotSame($this->clinicA, $this->clinicB, 'fixture: two distinct real Clinics.');
        self::assertNotSame($this->clinicA, $this->ambientClinicId, 'fixture: Clinic A is not the ambient Clinic.');
        self::assertNotSame($this->clinicB, $this->ambientClinicId, 'fixture: Clinic B is not the ambient Clinic.');
    }

    // ================= Fixture =================

    private function buildFixture(): void
    {
        $now = App::db()->nowUtcSql();
        $uid = bin2hex(random_bytes(4));

        $orgId = $this->insertRow('cpms_organizations', [
            'name'       => 'Org MCBS ' . $uid,
            'slug'       => 'org-mcbs-' . $uid,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], 'organization');

        $this->clinicA = $this->insertRow('cpms_clinics', [
            'organization_id' => $orgId,
            'name'            => 'Clinic A MCBS ' . $uid,
            'slug'            => 'clinic-a-mcbs-' . $uid,
            'timezone'        => self::TZ,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], 'clinic A');

        $this->clinicB = $this->insertRow('cpms_clinics', [
            'organization_id' => $orgId,
            'name'            => 'Clinic B MCBS ' . $uid,
            'slug'            => 'clinic-b-mcbs-' . $uid,
            'timezone'        => self::TZ,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], 'clinic B');

        $locationA = $this->insertRow('cpms_locations', [
            'clinic_id'  => $this->clinicA,
            'name'       => 'Loc A MCBS ' . $uid,
            'slug'       => 'loc-a-mcbs-' . $uid,
            'timezone'   => self::TZ,
            'is_primary' => 1,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'location A');

        $locationB = $this->insertRow('cpms_locations', [
            'clinic_id'  => $this->clinicB,
            'name'       => 'Loc B MCBS ' . $uid,
            'slug'       => 'loc-b-mcbs-' . $uid,
            'timezone'   => self::TZ,
            'is_primary' => 1,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'location B');

        // The clinicians whose PERSISTED clinic_id is the trusted scope of A1/A4.
        $this->clinicianA = $this->insertRow('cpms_clinicians', [
            'clinic_id'  => $this->clinicA,
            'full_name'  => 'Dr. ClinicA MCBS ' . $uid,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'clinician A');

        $this->clinicianB = $this->insertRow('cpms_clinicians', [
            'clinic_id'  => $this->clinicB,
            'full_name'  => 'Dr. ClinicB MCBS ' . $uid,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'clinician B');

        // Settings — per-Clinic, written through the per-Clinic factory (never
        // through the ambient App::settings()).
        $settingsA = App::settingsFactory()->forClinic($this->clinicA);
        $settingsA->set('booking.min_lead_hours', self::MIN_LEAD_HOURS_A);
        $settingsA->set('booking.max_future_days', self::MAX_FUTURE_DAYS_A);

        $settingsB = App::settingsFactory()->forClinic($this->clinicB);
        $settingsB->set('booking.min_lead_hours', self::MIN_LEAD_HOURS_B);
        $settingsB->set('booking.max_future_days', self::MAX_FUTURE_DAYS_B);

        Settings::flushCache();

        // IDENTICAL slot geometry in both Clinics — the only thing that may make
        // the A4 verdict differ is the Clinic the policy is read from.
        $near = $this->localSpecMinutesAhead(self::NEAR_SLOT_MINUTES);
        $this->nearSlotA = $this->seedSlot($this->clinicA, $locationA, $this->clinicianA, $near, $now);
        $this->nearSlotB = $this->seedSlot($this->clinicB, $locationB, $this->clinicianB, $near, $now);

        self::assertGreaterThan(1, $this->clinicA, 'AD-13: fixture Clinic ids must be dynamic, never clinic 1.');
        self::assertGreaterThan(1, $this->clinicB, 'AD-13: fixture Clinic ids must be dynamic, never clinic 1.');
        self::assertSame(
            $this->nearSlotA['date'] . ' ' . $this->nearSlotA['time'],
            $this->nearSlotB['date'] . ' ' . $this->nearSlotB['time'],
            'fixture: the A4 control slot geometry must be identical in both Clinics.'
        );
    }

    /**
     * @param array{date: string, time: string} $spec
     * @return array{slot_id: int, date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId, array $spec, string $now): array
    {
        $slotId = $this->insertRow('cpms_schedule_slots', [
            'clinic_id'      => $clinicId,
            'location_id'    => $locationId,
            'clinician_id'   => $clinicianId,
            'slot_date'      => $spec['date'],
            'slot_time'      => $spec['time'],
            'duration_min'   => 20,
            'capacity'       => 1,
            'booked_count'   => 0,
            'held_count'     => 0,
            'is_open'        => 1,
            'generated_from' => 'manual',
            'created_at'     => $now,
            'updated_at'     => $now,
        ], 'slot');

        return ['slot_id' => $slotId, 'date' => $spec['date'], 'time' => $spec['time']];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertRow(string $table, array $data, string $label): int
    {
        global $wpdb;

        $ok = $wpdb->insert($wpdb->prefix . $table, $data);
        self::assertTrue((bool) $ok, 'fixture ' . $label . ' insert failed: ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture ' . $label . ' id must be positive and dynamic.');

        return $id;
    }

    /**
     * @return array{date: string, time: string}
     */
    private function localSpecMinutesAhead(int $minutes): array
    {
        $local = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->add(new DateInterval('PT' . $minutes . 'M'));

        return ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i') . ':00'];
    }

    /**
     * An explicit A1 span of `$days` calendar days, starting from today (UTC).
     *
     * @return array{from: string, to: string}
     */
    private function explicitSpan(int $days): array
    {
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'from' => $today->format('Y-m-d'),
            'to'   => $today->add(new DateInterval('P' . ($days - 1) . 'D'))->format('Y-m-d'),
        ];
    }
}

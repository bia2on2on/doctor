<?php

/**
 * Phase 8 Slice 2 — OTP authentication -> authenticated Hold -> final booking
 * confirmation (TEST-ONLY RED).
 *
 * ============================================================================
 * SLICE CONTRACT UNDER TEST
 * ============================================================================
 *
 * The smallest complete flow this RED encodes:
 *
 *   Anonymous:
 *     Slice 1 selection + successful A4 quote
 *       -> continue
 *       -> mobile
 *       -> A2 OTP request bound to the selection-derived Clinic
 *       -> code
 *       -> A3 verify
 *       -> authenticated WP session
 *   Before authentication: NO Hold (anonymous users NEVER consume capacity).
 *   After successful authentication:
 *       -> preserve/re-present selection
 *       -> authenticated server B1 revalidation
 *       -> create the existing Hold (default TTL 10 minutes)
 *       -> Hold TTL/countdown
 *       -> B2 final confirm (new-patient first_name/last_name gate)
 *       -> reference code / Jalali confirmation
 *
 * Two OWNER PRODUCT DECISIONS are encoded here (OWNER-ISSUED POLICY, not a
 * rewrite of historical FR-4.2/UC-02 — that wording remains unchanged):
 *
 *   DECISION 1 — HOLD TIMING.
 *     Before authentication only the Selection is preserved; anonymous users
 *     never consume capacity through a real Hold. After successful mobile OTP
 *     authentication the server re-resolves/revalidates the persisted
 *     clinician/slot/Clinic and only then creates the existing authenticated
 *     Hold. Raw/client Clinic identifiers are never authority.
 *
 *   DECISION 2 — NEW PATIENT IDENTITY.
 *     After valid authentication the SERVER determines whether the booking
 *     identity already has a Patient in the BOOKING Clinic. An existing
 *     patient in ANOTHER Clinic does not count. For a genuinely new Patient
 *     in the booking Clinic first_name and last_name are required before B2;
 *     blank/whitespace fails; a failed attempt leaves the Hold active and
 *     retryable. For an existing Patient in the booking Clinic names are not
 *     required and client-supplied names are IGNORED (B2 is not a
 *     profile-update route). Mobile comes only from the authenticated server
 *     identity / hold ownership — client mobile/patient_id/is_new_user/
 *     clinic_id claims never select or override server identity.
 *
 * Accepted OTP architecture encoded:
 *   - A2 may receive the preserved selection (clinician_id, slot_id,
 *     slot_date, slot_time) as SELECTORS ONLY. The server must resolve the
 *     active persisted clinician, derive the Clinic from the persisted
 *     clinician, resolve the slot inside that Clinic, prove the
 *     slot/clinician/date/time relationship, and derive the challenge Clinic
 *     from persisted data only. The OTP challenge must DURABLY carry that
 *     derived Clinic (future schema contract: cpms_otp_tokens.clinic_id).
 *   - A3 (verify) accepts NO Clinic/selection authority — it uses the Clinic
 *     persisted on the OTP challenge for OTP policy, Patient lookup and the
 *     Patient-link Clinic.
 *   - Historical/NULL challenge rows: single-Clinic installation keeps the
 *     established resolver behaviour; multi-Clinic fails CLOSED with an
 *     explicit CLINIC_* envelope — never an uncaught exception, never a 500.
 *   - Deterministic OTP WP-user identity {mobile}@otp.cpms.local is REUSED:
 *     A3 must never wp_insert_user into a duplicate-email error; same
 *     user_id, is_new_user=false, wp_users count unchanged. No new identity
 *     table; cpms_patient_identities is NOT authority here.
 *
 * ============================================================================
 * MIGRATION CONDITION (schema RED — no migration file in this task)
 * ============================================================================
 *
 * Architecture proof established that no existing durable trusted identifier
 * safely binds an OTP challenge to its Clinic, so a migration is objectively
 * justified. THIS TASK IS TEST-ONLY RED: no migration file is added and no
 * migration filename is reserved. Live main was retrieved first; at RED
 * authoring time the latest applied migration is the live baseline
 * '2026_09_09_0020' (also pinned by MigrationTest::LATEST_VERSION) and the
 * repository's 4-digit sequential naming convention objectively defines the
 * next number. RED schema assertions (T24/T25) therefore assert the FUTURE
 * contract and fail today:
 *
 *   cpms_otp_tokens.clinic_id
 *     - BIGINT UNSIGNED, NULL allowed, NO default;
 *     - FK to cpms_clinics(id);
 *     - no explicit new index (InnoDB may auto-index for the FK — not asserted);
 *     - no backfill; historical NULL rows remain supported;
 *     - down migration must drop the FK then the column.
 *
 * ============================================================================
 * RED CLASSIFICATION — verified against live main HEAD
 * ============================================================================
 *
 * Live state at RED authoring (independently fetched, 2026-09-19):
 *   - origin/main = 4122539885cadb28d69f62e765ee3368b50b0509
 *     (historical expected main matched; no drift);
 *   - open PRs at authoring = 0 (no overlapping Phase 8 Slice 2 PR);
 *   - latest migration on disk and applied = '2026_09_09_0020' (no 0021);
 *   - workspace clean (tracked + untracked).
 *
 * Local execution = NOT RUN (PHP/MySQL unavailable in this sandbox —
 * established repo constraint); execution evidence is the CI Integration job
 * at the exact RED head (NOT RUN != PASS; CI run id is recorded in the PR).
 *
 * EXECUTED EVIDENCE (Integration run 35474056024, PR #89): Tests: 1075,
 * Assertions: 17331, Failures: 15, Errors: 0 — all 15 failures are the
 * methods below (zero collateral; all 10 guards pass; failures are PHPUnit
 * assertion failures, never harness errors: T03/T08 report the escaping
 * ScopeRequiredException, T04/T05/T06 report 200+sms_sent:true on tampered
 * selections, T09/T10 report ambient-Clinic patient linkage instead of the
 * challenge-Clinic linkage, T11 reports CLINIC_OTP_INVALID/400 from the
 * duplicate-email insert, T14/T16 report 200 with a created appointment,
 * T20/T21/T22 report absent continuation markers, T24 the absent column,
 * T25 the un-advanced baseline).
 *
 * INTENDED RED — fails on live main, attributable ONLY to missing Slice 2
 * contracts (15 test methods):
 *
 *   T03 A2 with a consistent persisted selection must succeed without any
 *       ambient Clinic scope and durably bind the OTP challenge to the
 *       selection-derived Clinic   (fails: ambient-scope resolution throws /
 *       challenge carries no Clinic at all)
 *   T04 A2 with a cross-Clinic slot/clinician tuple must fail closed with
 *       zero challenge and zero SMS   (fails: selection is ignored today —
 *       200 + challenge + SMS are produced)
 *   T05 same for a date/time mismatch against slot_id
 *   T06 same for an inactive clinician
 *   T08 A2 without selection on a multi-Clinic installation must fail closed
 *       with the CLINIC_SCOPE_REQUIRED product envelope — never an uncaught
 *       exception or a 500   (fails: ScopeRequiredException escapes the REST
 *       boundary today)
 *   T09 A3 verifies under the Clinic persisted on the challenge — never the
 *       request body, never the ambient Clinic; the Patient in the other
 *       Clinic is never linked   (fails: verify resolves the Patient from
 *       the ambient Settings Clinic)
 *   T10 two-Clinic challenge order: A's stale code cannot verify B's latest
 *       challenge, failure mutates only the latest challenge, B's valid code
 *       authenticates under B — no client Clinic switch   (fails on the
 *       challenge-Clinic authority assertions; latest-row guards pass)
 *   T11 A3 reuses the deterministic OTP WP user {mobile}@otp.cpms.local —
 *       same user_id, is_new_user=false, wp_users count unchanged   (fails:
 *       wp_insert_user hits the duplicate-email error and A3 ends in
 *       CLINIC_OTP_INVALID / 400)
 *   T14 a genuinely new Patient in the booking Clinic requires non-blank
 *       first_name/last_name before B2; failures create nothing and leave
 *       the Hold active/retryable; tampered patient_id/is_new_user/mobile/
 *       clinic_id claims are ignored   (fails: B2 creates an empty-named
 *       minimal Patient and confirms)
 *   T16 a Patient existing only in ANOTHER Clinic is treated as NEW in the
 *       booking Clinic   (fails: B2 confirms without names)
 *   T20 the anonymous Slice-1 surface gains the continue-to-auth affordance,
 *       the mobile + 6-digit OTP states and the EXISTING A2/A3 paths in its
 *       runtime config — while publishing no nonce and no B1/B2 paths
 *       (fails: none of the auth-continuation markers exist)
 *   T21 the public booking JS posts A3 with same-origin credentials and
 *       reloads after a successful verify   (fails: markers absent)
 *   T22 an authenticated patient render publishes the wp_rest nonce + the
 *       existing B1/B2 paths   (fails: publish does not exist)
 *   T24 cpms_otp_tokens.clinic_id future schema contract   (fails: column
 *       absent; round-trip up/down assertions become executable only once
 *       the migration exists at GREEN)
 *   T25 the latest applied migration has advanced beyond the live baseline
 *       '2026_09_09_0020'   (fails: still the baseline)
 *
 * GUARDS / POSITIVE CONTROLS — pass on live main and keep GREEN honest (they
 * pin behaviour GREEN must PRESERVE, in the Slice-2 context; 10 methods):
 *
 *   T01 anonymous A4 quote + A2 create no cpms_slot_holds row and no
 *       held_count drift; nothing authenticates capacity before a session
 *   T02 anonymous B1 stays 401 CLINIC_UNAUTHORIZED, still with zero capacity
 *       consumption
 *   T07 A2 without selection on a single-Clinic installation keeps the
 *       established resolver behaviour (200 + challenge)
 *   T12 even AFTER A3 (session_issued=true) but BEFORE B1 there is still no
 *       Hold — Hold creation is exclusively the authenticated B1 act
 *   T13 authenticated B1 with the preserved selection creates the existing
 *       normal Hold: holder = authenticated user, persisted slot/Clinic
 *       governs, TTL = configured default (600s), raw Clinic/mobile claims
 *       in the body ignored
 *   T15 existing Patient in the booking Clinic: names not required AND
 *       client-supplied names ignored (existing Patient row unchanged)
 *   T17 authenticated atomicHold race loss -> 409 CLINIC_SLOT_TAKEN with the
 *       established nearby_slots policy (max 5, same local date, same losing
 *       Location, same Clinic + clinician, deterministic ordering, [] if
 *       none) and no capacity drift
 *   T18 unrelated B1 failures (404, policy) never gain nearby_slots
 *   T19 B2 idempotency (same-key replay = same reference_code), ownership
 *       (another user's hold = 403), expired hold = CLINIC_HOLD_EXPIRED; no
 *       duplicate appointment/patient
 *   T23 a logged-in NON-patient (staff) receives no patient continuation
 *       (no nonce / hold / confirm / OTP verify markers)
 *
 * POST-GREEN SECURITY REGRESSION GUARD — added after GREEN, passes on the
 * GREEN head and must keep passing (1 method; total suite = 26 tests):
 *
 *   T26 A3 fails closed with the established CLINIC_OTP_INVALID envelope
 *       when the deterministic bridge email {mobile}@otp.cpms.local is
 *       held by a NON-patient WP account: reuse is gated on the verified
 *       cpms_patient role; no session, no patient_links, no user_id in
 *       the payload, no wp_users/Patient/link side effects, no silent
 *       role conversion, and no disclosure of the bridge-email owner.
 *
 * "No new REST route" is pinned by the existing T20 census guard of
 * Phase8Slice1PublicBookingBrowseRedTest (anonymous census =
 * {availability, booking/quote, health, otp/request, otp/verify}); Slice 2
 * rides the EXISTING A2/A3/B1/B2 routes and registers none.
 *
 * Classification legend (repo convention): EXPECTED RED = valid repro |
 * A = regression by current work | B = pre-existing outside target |
 * C = infra/env | D = test-infra defect. Anything outside the 15 intended
 * RED methods above is NOT Slice-2 RED evidence and must be investigated
 * as class D before proceeding.
 *
 * ============================================================================
 * INVALID-RED PROTECTIONS
 * ============================================================================
 *
 *  - Every failing assertion is a PRODUCT-LEVEL assertion (HTTP status,
 *     envelope code, durable row state, published markup/config/JS markers)
 *     reached through real WP REST dispatch / real service calls / real
 *     schema inspection — never a PHP fatal, import failure or fixture SQL
 *     error.
 *  - Fixture inserts use only columns that exist on live main (the
 *     cpms_otp_tokens.clinic_id column is asserted, never inserted).
 *  - Schema round-trip assertions sit BEHIND the column-presence assertion,
 *     so at RED the code path that would roll back '2026_09_09_0020' is
 *     never reached.
 *  - A2/A3 dispatches whose current behaviour is an escaping exception are
 *     caught and converted into an attributable assertion failure (a test
 *     FAILURE, never a PHPUnit ERROR).
 *  - Distinct mobiles per test method; explicit-scope bookkeeping is reset
 *     in setUp/tearDown; no test depends on execution order.
 *  - Browser/responsive acceptance (focus order, real OTP typing, session
 *     persistence round-trips) is provable at GREEN with the existing
 *     responsive harness; this RED encodes only the server-observable
 *     contract markers so unavailable browser infrastructure does not make
 *     this an INVALID RED.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class Phase8Slice2OtpHoldConfirmRedTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    /** Existing public REST namespace (RestBase::NS). */
    private const NS = '/clinic/v1';

    /** Live migration baseline at RED authoring (retrieved from live main). */
    private const LIVE_MIGRATION_BASELINE = '2026_09_09_0020';

    /** Slice-1 surface markers reused by this slice's UI continuation contract. */
    private const SHORTCODE = 'cpms_public_booking';
    private const ROOT_CLASS = 'cpms-public-booking';
    private const CONFIG_CLASS = 'cpms-public-booking__config';

    /** Slice-2 continuation markers the surface must publish (contract for GREEN). */
    private const CONTINUE_AUTH_MARKER = 'data-role="continue-auth"';
    private const OTP_MOBILE_MARKER = 'data-role="otp-mobile"';
    private const OTP_CODE_MARKER = 'data-role="otp-code"';

    /** Existing REST paths — Slice 2 must ride these and register no new route. */
    private const OTP_REQUEST_PATH = '/otp/request';
    private const OTP_VERIFY_PATH = '/otp/verify';
    private const HOLD_PATH = '/booking/hold';
    private const CONFIRM_PATH = '/booking/confirm';

    /** Distinct valid Iranian mobiles per test method (no cross-test coupling). */
    private const MOBILE_T01 = '09129981001';
    private const MOBILE_T03 = '09129981020';
    private const MOBILE_T04 = '09129981021';
    private const MOBILE_T05 = '09129981022';
    private const MOBILE_T06 = '09129981023';
    private const MOBILE_T07 = '09129981024';
    private const MOBILE_T08 = '09129981025';
    private const MOBILE_T09 = '09129981026';
    private const MOBILE_T10 = '09129981027';
    private const MOBILE_T11 = '09129981028';
    private const MOBILE_T12 = '09129981004';
    private const MOBILE_T13 = '09129981005';
    private const MOBILE_T14 = '09129981006';
    private const MOBILE_T15 = '09129981007';
    private const MOBILE_T16 = '09129981008';
    private const MOBILE_T17A = '09129981009';
    private const MOBILE_T17B = '09129981010';
    private const MOBILE_T18 = '09129981029';
    private const MOBILE_T19A = '09129981011';
    private const MOBILE_T19B = '09129981012';
    private const MOBILE_DECOY = '09129981013';
    private const MOBILE_T26 = '09129981014';

    private const TZ_TEHRAN = 'Asia/Tehran';

    // ---- Two-Clinic fixture state (set by buildTwoClinicFixture()) ----
    private int $orgA = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianA = 0;
    private int $clinicianAInactive = 0;
    private int $clinicianB = 0;

    // ================= Lifecycle =================

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        $this->orgA = 0;
        $this->clinicA = 0;
        $this->clinicB = 0;
        $this->locationA = 0;
        $this->locationB = 0;
        $this->clinicianA = 0;
        $this->clinicianAInactive = 0;
        $this->clinicianB = 0;
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        parent::tearDown();
    }

    // =================================================================
    // AREA 1 — PRE-AUTH CAPACITY (guards)
    // =================================================================

    /**
     * T01 — anonymous quote + OTP request must create NO cpms_slot_holds row
     * and must not move held_count: anonymous users NEVER consume capacity
     * through a real Hold.
     */
    public function testAnonymousQuoteAndOtpRequestCreateNoHoldAndNoCapacityDrift(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'precondition: fixture owns no hold rows.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'precondition: slot starts with no held capacity.');

        // Successful anonymous A4 quote (positive control — Slice 1 T14 rides it too).
        $quote = $this->restPost(self::NS . '/booking/quote', [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ]);
        self::assertSame(200, $quote->get_status(), 'positive control: anonymous A4 quote must succeed.');
        $quoteData = $quote->get_data();
        self::assertTrue(
            (bool) ($quoteData['data']['available'] ?? false),
            'positive control: the free seeded slot must be quotable as available.'
        );

        // Successful A2 for the booking mobile (ambient single-clinic-equivalent
        // explicit scope — the established way this suite reaches the current path).
        $this->ambientScope($this->clinicA);
        $otp = $this->restPost(self::NS . self::OTP_REQUEST_PATH, ['mobile' => self::MOBILE_T01]);
        self::assertSame(200, $otp->get_status(), 'positive control: A2 must succeed for a valid mobile.');
        $this->ambientScope(null);

        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'Anonymous browse/quote/OTP must never create a Hold.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'Anonymous browse/quote/OTP must never touch held_count.');
    }

    /**
     * T02 — anonymous B1 remains unauthorized, still with zero capacity effects.
     */
    public function testAnonymousBookingHoldRemainsUnauthorizedWithoutCapacityEffects(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        // Anonymous WITH a valid anonymous wp_rest nonce: authorization (not CSRF) is the gate.
        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], withNonce: true);

        $this->assertClinicError($hold, 'CLINIC_UNAUTHORIZED', 401, 'Anonymous B1 must stay unauthorized.');
        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'A rejected anonymous B1 must not create a Hold.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'A rejected anonymous B1 must not consume capacity.');
    }

    // =================================================================
    // AREA 2 — A2 TENANT BINDING (RED)
    // =================================================================

    /**
     * T03 — A2 with a CONSISTENT persisted selection must succeed with no
     * ambient Clinic scope at all: the server resolves the active persisted
     * clinician, derives the Clinic from persistence, and the OTP challenge
     * DURABLY carries that derived Clinic. OTP SMS is dispatched under that
     * Clinic.
     *
     * RED on live main: A2 resolves its Clinic from the ambient scope
     * resolver (multi-Clinic + no explicit scope => ScopeRequiredException
     * escapes the REST boundary), and cpms_otp_tokens has no clinic binding
     * column at all.
     */
    public function testA2WithConsistentSelectionIsBoundToSelectionDerivedClinic(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        // Deliberately NO ambient scope: the selection must be the only authority.
        $this->ambientScope(null);
        self::assertNull(ScopeContext::tryGet(), 'precondition: no explicit ambient scope.');

        ['response' => $response, 'thrown' => $thrown] = $this->restPostCatching(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T03,
            'clinician_id' => $this->clinicianA,
            'slot_id' => $slot['slot_id'],
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);

        self::assertNull(
            $thrown,
            'A2 bound to a consistent persisted selection must not throw. Got: '
            . ($thrown !== null ? get_class($thrown) . ': ' . $thrown->getMessage() : '')
        );
        self::assertNotNull($response, 'A2 must return a product response.');
        self::assertSame(
            200,
            $response->get_status(),
            'A2 with a consistent persisted selection must succeed by deriving the Clinic from persistence '
            . '(no ambient Clinic scope on a multi-Clinic installation). Body: '
            . wp_json_encode($response->get_data())
        );

        // The challenge must durably carry the selection-derived Clinic.
        $tokenRow = $this->latestOtpTokenRow(self::MOBILE_T03);
        self::assertNotNull($tokenRow, 'A successful A2 must persist a challenge row.');
        self::assertArrayHasKey(
            'clinic_id',
            $tokenRow,
            'The OTP challenge must durably carry the derived Clinic '
            . '(future schema contract: cpms_otp_tokens.clinic_id).'
        );
        self::assertSame(
            $this->clinicA,
            (int) $tokenRow['clinic_id'],
            'The challenge Clinic must be the selection-derived persisted Clinic, never a client claim.'
        );

        // OTP SMS policy/dispatch uses that same Clinic.
        self::assertSame(1, $this->countSmsFor(self::MOBILE_T03), 'A successful A2 must dispatch exactly one OTP SMS.');
        $smsClinic = (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE recipient = %s ORDER BY id DESC LIMIT 1',
            [self::MOBILE_T03]
        );
        self::assertSame($this->clinicA, $smsClinic, 'The OTP SMS must be dispatched under the challenge Clinic.');
    }

    /**
     * T04 — tampered selection: slot persisted in ANOTHER Clinic than the
     * clinician. Fail closed with the established CLINIC_NOT_FOUND / 404
     * envelope — BEFORE any challenge or SMS exists.
     *
     * RED on live main: the selection is ignored entirely; a challenge and an
     * SMS are produced (200).
     */
    public function testA2RejectsCrossClinicSlotBeforeAnyChallengeOrSms(): void
    {
        $this->buildTwoClinicFixture();
        $slotB = $this->seedSlot($this->clinicB, $this->locationB, $this->clinicianB, $this->ymdDaysAhead(5), '09:00:00', 1);

        // Ambient scope exists ONLY so the current (pre-slice) path is reachable;
        // the tampered selection must be rejected regardless.
        $this->ambientScope($this->clinicA);
        $response = $this->restPost(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T04,
            'clinician_id' => $this->clinicianA,
            'slot_id' => $slotB['slot_id'],
            'slot_date' => $slotB['date'],
            'slot_time' => $slotB['time'],
        ]);
        $this->ambientScope(null);

        $this->assertClinicError(
            $response,
            'CLINIC_NOT_FOUND',
            404,
            'A2 must fail closed on a slot persisted outside the clinician\'s Clinic '
            . '(established fail-closed vocabulary of the booking paths).'
        );
        self::assertSame(0, $this->countOtpTokens(self::MOBILE_T04), 'A tampered A2 selection must create ZERO OTP challenges.');
        self::assertSame(0, $this->countSmsFor(self::MOBILE_T04), 'A tampered A2 selection must dispatch ZERO SMS.');
    }

    /**
     * T05 — tampered selection: slot_id belongs to the clinician but the
     * date/time tuple does not match the persisted slot. Fail closed with
     * CLINIC_VALIDATION_FAILED / 422 — zero challenge, zero SMS.
     */
    public function testA2RejectsSlotDateTimeMismatchBeforeAnyChallengeOrSms(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        $this->ambientScope($this->clinicA);
        $response = $this->restPost(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T05,
            'clinician_id' => $this->clinicianA,
            'slot_id' => $slot['slot_id'],
            'slot_date' => $slot['date'],
            'slot_time' => '11:00:00', // persisted slot is 10:00:00
        ]);
        $this->ambientScope(null);

        $this->assertClinicError(
            $response,
            'CLINIC_VALIDATION_FAILED',
            422,
            'A2 must prove the slot/clinician/date/time relationship and fail closed on mismatch.'
        );
        self::assertSame(0, $this->countOtpTokens(self::MOBILE_T05), 'A mismatched A2 selection must create ZERO OTP challenges.');
        self::assertSame(0, $this->countSmsFor(self::MOBILE_T05), 'A mismatched A2 selection must dispatch ZERO SMS.');
    }

    /**
     * T06 — tampered selection: the clinician is persisted but INACTIVE.
     * Fail closed with CLINIC_NOT_FOUND / 404 — zero challenge, zero SMS.
     */
    public function testA2RejectsInactiveClinicianBeforeAnyChallengeOrSms(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianAInactive, $this->ymdDaysAhead(5), '12:00:00', 1);

        $this->ambientScope($this->clinicA);
        $response = $this->restPost(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T06,
            'clinician_id' => $this->clinicianAInactive,
            'slot_id' => $slot['slot_id'],
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
        ]);
        $this->ambientScope(null);

        $this->assertClinicError(
            $response,
            'CLINIC_NOT_FOUND',
            404,
            'A2 must resolve the ACTIVE persisted clinician; an inactive clinician is not a booking identity.'
        );
        self::assertSame(0, $this->countOtpTokens(self::MOBILE_T06), 'An inactive-clinician A2 must create ZERO OTP challenges.');
        self::assertSame(0, $this->countSmsFor(self::MOBILE_T06), 'An inactive-clinician A2 must dispatch ZERO SMS.');
    }

    // =================================================================
    // AREA 3 — A2 WITHOUT SELECTION (guard + RED)
    // =================================================================

    /**
     * T07 — single-Clinic installation: A2 without any selection keeps the
     * established resolver behaviour (the only Clinic resolves implicitly).
     */
    public function testA2WithoutSelectionOnSingleClinicInstallationRetainsEstablishedBehavior(): void
    {
        // Only the seeded default Clinic exists (migrations seed exactly one).
        global $wpdb;
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertSame(1, $clinicCount, 'precondition: exactly one Clinic (the seeded default) exists.');

        $this->ambientScope(null);
        ['response' => $response, 'thrown' => $thrown] = $this->restPostCatching(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T07,
        ]);

        self::assertNull(
            $thrown,
            'Established single-Clinic behaviour must be retained — no exception. Got: '
            . ($thrown !== null ? get_class($thrown) : '')
        );
        self::assertNotNull($response);
        self::assertSame(200, $response->get_status(), 'Single-Clinic A2 without selection must keep succeeding.');
        $data = $response->get_data();
        self::assertGreaterThan(0, (int) ($data['data']['expires_in'] ?? 0), 'A2 must return a positive OTP TTL.');
        self::assertSame(1, $this->countOtpTokens(self::MOBILE_T07), 'Single-Clinic A2 must persist a challenge.');
    }

    /**
     * T08 — multi-Clinic installation: A2 without any selection must fail
     * CLOSED with the explicit CLINIC_SCOPE_REQUIRED product envelope
     * (HTTP 400) — never an uncaught exception, never a 500, zero challenge,
     * zero SMS.
     *
     * RED on live main: ScopeRequiredException escapes the REST boundary
     * (uncaught / rest_internal_error 500), not a CLINIC_* envelope.
     */
    public function testA2WithoutSelectionOnMultiClinicInstallationFailsClosedWithProductEnvelope(): void
    {
        $this->buildTwoClinicFixture();

        $this->ambientScope(null);
        self::assertNull(ScopeContext::tryGet(), 'precondition: no explicit ambient scope.');

        ['response' => $response, 'thrown' => $thrown] = $this->restPostCatching(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T08,
        ]);

        self::assertNull(
            $thrown,
            'A2 without selection on a multi-Clinic installation must fail closed with an explicit '
            . 'CLINIC_SCOPE_REQUIRED product envelope — never an uncaught exception. Got: '
            . ($thrown !== null ? get_class($thrown) . ': ' . $thrown->getMessage() : '')
        );
        self::assertNotNull($response, 'A2 must answer with a product envelope, not a transport failure.');
        $this->assertClinicError(
            $response,
            'CLINIC_SCOPE_REQUIRED',
            400,
            'Historical/NULL challenge context on a multi-Clinic installation must fail with an explicit '
            . 'CLINIC_* envelope — never a fatal/500.'
        );
        self::assertSame(0, $this->countOtpTokens(self::MOBILE_T08), 'A fail-closed A2 must create ZERO OTP challenges.');
        self::assertSame(0, $this->countSmsFor(self::MOBILE_T08), 'A fail-closed A2 must dispatch ZERO SMS.');
    }

    // =================================================================
    // AREA 4 — A3 TENANT BINDING (RED)
    // =================================================================

    /**
     * T09 — A3 carries NO Clinic/selection authority in its body. The Clinic
     * persisted on the challenge decides the Patient lookup and the
     * Patient-link Clinic. A same-mobile Patient in ANOTHER Clinic is never
     * linked.
     *
     * Fixture: patients with the SAME mobile exist in BOTH Clinic A (the
     * challenge's Clinic) and Clinic B (the ambient/other Clinic). At verify
     * time the ambient scope AND the request body both say Clinic B; the
     * challenge (bound to Clinic A by A2) must win.
     *
     * RED on live main: the challenge has no Clinic, so verify resolves the
     * Patient from the ambient Settings Clinic (B) and links THAT patient.
     */
    public function testA3VerifiesUnderChallengeClinicAndNeverClientOrAmbientClinic(): void
    {
        $this->buildTwoClinicFixture();
        $slotA = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        $patientA = $this->insertPatient($this->clinicA, self::MOBILE_T09, 'Chal', 'LengeA');
        $patientB = $this->insertPatient($this->clinicB, self::MOBILE_T09, 'Other', 'ClinicP');

        // A2 for the Clinic A selection (ambient A merely keeps the pre-slice
        // path reachable; at GREEN the selection alone derives the challenge Clinic).
        $this->ambientScope($this->clinicA);
        $a2 = $this->restPost(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T09,
            'clinician_id' => $this->clinicianA,
            'slot_id' => $slotA['slot_id'],
            'slot_date' => $slotA['date'],
            'slot_time' => $slotA['time'],
        ]);
        self::assertSame(200, $a2->get_status(), 'positive control: A2 succeeds for a valid mobile.');
        $this->patchLatestTokenCode(self::MOBILE_T09, '202020');

        // Verify with BOTH a hostile ambient Clinic (B) and a client body claim
        // of Clinic B. Neither has authority — the challenge's persisted
        // Clinic (A) is the only one that does.
        $this->ambientScope($this->clinicB);
        $verify = $this->restPost(self::NS . self::OTP_VERIFY_PATH, [
            'mobile' => self::MOBILE_T09,
            'code' => '202020',
            'clinic_id' => $this->clinicB,
        ]);
        $this->ambientScope(null);

        self::assertSame(200, $verify->get_status(), 'A valid code must verify. Body: ' . wp_json_encode($verify->get_data()));
        $data = $verify->get_data();
        self::assertIsArray($data['data'] ?? null, 'A3 success envelope carries data.');

        $links = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            (array) ($data['data']['patient_links'] ?? [])
        );
        self::assertSame(
            [$patientA],
            array_values($links),
            'A3 uses the Clinic persisted on the OTP challenge — not the request body clinic_id, '
            . 'not the ambient Clinic. The challenge was bound to Clinic A, so only the Clinic A patient may link.'
        );
        self::assertSame(
            0,
            $this->countPatientLinks($patientB),
            'A same-mobile Patient in another Clinic must NEVER be linked by A3.'
        );
        self::assertSame(
            1,
            $this->countPatientLinks($patientA),
            'The challenge-Clinic Patient must be linked exactly once.'
        );
        $linkClinic = (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE patient_id = %d LIMIT 1',
            [$patientA]
        );
        self::assertSame($this->clinicA, $linkClinic, 'The Patient link must be stamped with the challenge Clinic.');
    }

    // =================================================================
    // AREA 5 — TWO-CLINIC CHALLENGE ORDER (guards + RED)
    // =================================================================

    /**
     * T10 — same mobile: A2 for Clinic A, then A2 for Clinic B. Latest-row
     * semantics are unchanged (AD-15 identity-level cooldown semantics are
     * NOT redefined): A's stale code cannot verify B's latest challenge; the
     * failed attempt mutates ONLY the latest challenge; B's valid code
     * authenticates under B's persisted Clinic — and neither the ambient
     * Clinic nor a client clinic_id can switch it.
     *
     * Guards (pass today): latest-row rejection, attempts mutation on the
     * latest row only, stale challenge left unconsumed.
     * RED: the challenge durably binds Clinic B and verify resolves B's
     * patient — today the ambient resolver decides and no binding exists.
     *
     * Codes are pinned to known values AFTER real A2 challenge creation
     * (test-only hash patch) because no SMS gateway capture exists in this
     * suite; challenge creation itself rides the real A2 route.
     */
    public function testTwoClinicChallengeOrderKeepsLatestRowSemanticsAndChallengeClinicAuthority(): void
    {
        $this->buildTwoClinicFixture();
        $slotA = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);
        $slotB = $this->seedSlot($this->clinicB, $this->locationB, $this->clinicianB, $this->ymdDaysAhead(5), '09:00:00', 1);

        $patientA = $this->insertPatient($this->clinicA, self::MOBILE_T10, 'First', 'ClinicAPat');
        $patientB = $this->insertPatient($this->clinicB, self::MOBILE_T10, 'Second', 'ClinicBPat');

        // --- A2 for Clinic A ---
        $this->ambientScope($this->clinicA);
        $a2a = $this->restPost(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T10,
            'clinician_id' => $this->clinicianA,
            'slot_id' => $slotA['slot_id'],
            'slot_date' => $slotA['date'],
            'slot_time' => $slotA['time'],
        ]);
        self::assertSame(200, $a2a->get_status(), 'positive control: first A2 succeeds.');
        $challengeA = $this->patchLatestTokenCode(self::MOBILE_T10, '111111');

        // --- A2 for Clinic B (same mobile — the later challenge) ---
        $this->ambientScope($this->clinicB);
        $a2b = $this->restPost(self::NS . self::OTP_REQUEST_PATH, [
            'mobile' => self::MOBILE_T10,
            'clinician_id' => $this->clinicianB,
            'slot_id' => $slotB['slot_id'],
            'slot_date' => $slotB['date'],
            'slot_time' => $slotB['time'],
        ]);
        self::assertSame(200, $a2b->get_status(), 'positive control: second A2 succeeds.');
        $challengeB = $this->patchLatestTokenCode(self::MOBILE_T10, '222222');
        self::assertGreaterThan($challengeA, $challengeB, 'precondition: the B challenge is the LATEST row.');

        // --- stale code of A cannot verify B's latest challenge ---
        $stale = $this->restPost(self::NS . self::OTP_VERIFY_PATH, [
            'mobile' => self::MOBILE_T10,
            'code' => '111111',
        ]);
        $this->assertClinicError($stale, 'CLINIC_OTP_INVALID', 400, "A's stale code must not verify B's latest challenge.");

        $attemptsA = (int) App::db()->fetchValue(
            'SELECT attempts FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d',
            [$challengeA]
        );
        $attemptsB = (int) App::db()->fetchValue(
            'SELECT attempts FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d',
            [$challengeB]
        );
        self::assertSame(0, $attemptsA, 'A failed verify must NOT mutate attempts on the stale A challenge.');
        self::assertSame(1, $attemptsB, 'A failed verify mutates attempts ONLY on the latest (B) challenge.');
        $lockedA = App::db()->fetchValue(
            'SELECT locked_until FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d',
            [$challengeA]
        );
        self::assertNull($lockedA, 'The stale challenge must remain untouched (no lock state drift).');

        // --- B's valid code authenticates under B's persisted Clinic ---
        // hostile ambient + client clinic_id claim — both say Clinic A; the
        // challenge persisted for Clinic B is the only authority.
        $this->ambientScope($this->clinicA);
        $verify = $this->restPost(self::NS . self::OTP_VERIFY_PATH, [
            'mobile' => self::MOBILE_T10,
            'code' => '222222',
            'clinic_id' => $this->clinicA,
        ]);
        $this->ambientScope(null);
        self::assertSame(200, $verify->get_status(), "B's valid code must authenticate.");
        $data = $verify->get_data();
        self::assertTrue((bool) ($data['data']['session_issued'] ?? false), 'A login-purpose verify must issue a session.');
        self::assertGreaterThan(0, (int) ($data['data']['user_id'] ?? 0), 'A login-purpose verify must resolve a user.');

        $links = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            (array) ($data['data']['patient_links'] ?? [])
        );
        self::assertSame(
            [$patientB],
            array_values($links),
            "No client Clinic switch: B's valid code authenticates under B's persisted Clinic — "
            . 'the ambient Clinic and the request clinic_id have no authority.'
        );
        self::assertSame(0, $this->countPatientLinks($patientA), 'Patient in the non-challenge Clinic must not be linked.');

        $consumedA = App::db()->fetchValue(
            'SELECT consumed_at FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d',
            [$challengeA]
        );
        self::assertNull($consumedA, "Latest-row semantics: A's challenge is never consumed by B's verify.");

        // The challenge must DURABLY bind the Clinic it belongs to.
        $bRow = $this->otpTokenRowById($challengeB);
        self::assertNotNull($bRow);
        self::assertArrayHasKey(
            'clinic_id',
            $bRow,
            'The OTP challenge must durably carry its Clinic (future schema contract: cpms_otp_tokens.clinic_id).'
        );
        self::assertSame($this->clinicB, (int) $bRow['clinic_id'], "Challenge B's durable Clinic binding must be Clinic B.");
    }

    // =================================================================
    // AREA 6 — EXISTING WP USER REUSE (RED)
    // =================================================================

    /**
     * T11 — the normalized mobile already has the deterministic OTP WP-user
     * identity {mobile}@otp.cpms.local but NO Patient in the booking Clinic
     * (a same-mobile Patient exists only in another Clinic). A3 must REUSE
     * that WP user: same user_id, is_new_user=false, no additional wp_users
     * row, and a Clinic-scoped Patient link only if a Patient in the
     * challenge Clinic exists (it does not here).
     *
     * RED on live main: resolveUser() finds no Patient in the current
     * Settings Clinic and calls wp_insert_user() with the already-taken
     * deterministic email -> WP_Error -> CLINIC_OTP_INVALID / 400.
     */
    public function testA3ReusesExistingDeterministicOtpWpUserWithoutDuplication(): void
    {
        $this->buildTwoClinicFixture();
        // Same-mobile Patient ONLY in the other Clinic — never authority here
        // and never linked (booking Clinic has no Patient for this mobile).
        $patientB = $this->insertPatient($this->clinicB, self::MOBILE_T11, 'Far', 'Tenant');

        $existingUserId = (int) wp_create_user(
            'p8s2_reuse_t11',
            'pass-not-used-123',
            self::MOBILE_T11 . '@otp.cpms.local'
        );
        self::assertGreaterThan(0, $existingUserId, 'precondition: the deterministic OTP WP user exists.');
        $this->setRole($existingUserId, 'cpms_patient');

        global $wpdb;
        $usersBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'users'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

        $this->ambientScope($this->clinicA);
        $this->issueKnownOtpToken(self::MOBILE_T11, '313131');
        $verify = $this->restPost(self::NS . self::OTP_VERIFY_PATH, [
            'mobile' => self::MOBILE_T11,
            'code' => '313131',
        ]);
        $this->ambientScope(null);

        self::assertSame(
            200,
            $verify->get_status(),
            'A3 must REUSE the existing deterministic OTP WP user, never fail on a duplicate '
            . '{mobile}@otp.cpms.local insert. Body: ' . wp_json_encode($verify->get_data())
        );
        $data = $verify->get_data();
        self::assertSame($existingUserId, (int) ($data['data']['user_id'] ?? 0), 'A3 must return the SAME WP user_id.');
        self::assertFalse((bool) ($data['data']['is_new_user'] ?? true), 'Reusing an existing identity is never is_new_user=true.');
        self::assertTrue((bool) ($data['data']['session_issued'] ?? false), 'A login-purpose verify must still issue the session.');

        $usersAfter = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'users'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertSame($usersBefore, $usersAfter, 'No additional wp_users row may be created on reuse.');

        self::assertSame(
            [],
            array_values((array) ($data['data']['patient_links'] ?? ['non-empty'])),
            'No Patient exists in the challenge Clinic, so no Patient link may appear.'
        );
        self::assertSame(0, $this->countPatientLinks($patientB), 'The other-Clinic Patient must never be linked.');
    }

    // =================================================================
    // AREA 6b — BRIDGE-EMAIL COLLISION FAIL-CLOSED (security regression)
    // =================================================================

    /**
     * T26 — SECURITY regression: the deterministic OTP bridge email
     * {mobile}@otp.cpms.local is derived from the mobile alone, so it is
     * guessable. If a NON-patient WP account (e.g. staff with the
     * cpms_secretary role) holds that email, A3 must FAIL CLOSED with the
     * established safe CLINIC_OTP_INVALID product envelope instead of
     * attaching the authenticated session to that account:
     *   - 400 + CLINIC_OTP_INVALID (never 200, never an uncaught exception);
     *   - no session/current user, no patient_links exposure, no user_id
     *     in the error payload, no disclosure of which account owns the
     *     bridge email;
     *   - zero provisioning side effects: no new wp_users row, no Patient
     *     row, no Patient link for the colliding account;
     *   - no silent role conversion: the colliding account keeps its
     *     exact roles.
     * Case-A parity (legitimate cpms_patient owner of the bridge email is
     * still reused: same user_id, is_new_user=false) is pinned by T11.
     */
    public function testA3FailsClosedWhenBridgeEmailIsHeldByANonPatientAccount(): void
    {
        $this->buildTwoClinicFixture();

        // Colliding non-patient account: holds the deterministic bridge
        // email for this mobile but is NOT a CPMS patient identity.
        $collidingUserId = (int) wp_create_user(
            'p8s2_bridge_collision_t26',
            'pass-not-used-123',
            self::MOBILE_T26 . '@otp.cpms.local'
        );
        self::assertGreaterThan(0, $collidingUserId, 'precondition: the colliding staff account exists.');
        $this->setRole($collidingUserId, 'cpms_secretary');

        global $wpdb;
        $usersBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'users'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertSame(
            0,
            $this->countPatientsForMobile($this->clinicA, self::MOBILE_T26),
            'precondition: no Patient exists for this mobile yet.'
        );

        $this->ambientScope($this->clinicA);
        $this->issueKnownOtpToken(self::MOBILE_T26, '262626');
        $verify = $this->restPost(self::NS . self::OTP_VERIFY_PATH, [
            'mobile' => self::MOBILE_T26,
            'code' => '262626',
        ]);
        $this->ambientScope(null);

        $this->assertClinicError(
            $verify,
            'CLINIC_OTP_INVALID',
            400,
            'A3 must fail closed when the bridge-email account is not a CPMS patient identity.'
        );

        // Non-disclosure: the error payload carries no account identity,
        // no patient_links, no session artefact and never names the
        // bridge email or the colliding user id.
        $body = $verify->get_data();
        self::assertIsArray($body, 'Error envelope must be an array.');
        $payload = (array) ($body['data'] ?? []);
        self::assertArrayNotHasKey('user_id', $payload, 'Fail-closed error must not expose a WP user id.');
        self::assertArrayNotHasKey('patient_links', $payload, 'Fail-closed error must not expose patient links.');
        self::assertArrayNotHasKey('session_issued', $payload, 'Fail-closed error must not carry a session marker.');
        $encoded = (string) wp_json_encode($body);
        self::assertStringNotContainsString('@otp.cpms.local', $encoded, 'The bridge email must never leak in the response.');
        self::assertStringNotContainsString(
            (string) $collidingUserId,
            (string) ($body['message'] ?? ''),
            'The message must not disclose which account owns the bridge email.'
        );

        // No session: the failing verify must not authenticate anyone.
        self::assertSame(0, get_current_user_id(), 'Fail-closed verify must leave the request anonymous.');

        // Zero provisioning side effects.
        $usersAfter = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'users'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertSame($usersBefore, $usersAfter, 'No wp_users row may be created on the fail-closed path.');
        self::assertSame(
            0,
            $this->countPatientsForMobile($this->clinicA, self::MOBILE_T26),
            'No Patient row may be created for the mobile on the fail-closed path.'
        );
        self::assertSame(
            0,
            $this->countPatientLinksForUser($collidingUserId),
            'The colliding non-patient account must never gain a Patient link.'
        );

        // No silent role conversion of the colliding account.
        $collider = get_userdata($collidingUserId);
        self::assertNotFalse($collider, 'precondition: the colliding account still exists.');
        self::assertSame(
            ['cpms_secretary'],
            array_values((array) $collider->roles),
            'The colliding non-patient account must keep its exact roles.'
        );
    }

    // =================================================================
    // AREA 7 — OWNER HOLD TIMING (guards)
    // =================================================================

    /**
     * T12 — before session_issued=true there is no Hold and held_count is
     * unchanged; even AFTER A3 (session issued) but BEFORE B1 nothing has
     * consumed capacity. The Hold is exclusively the authenticated B1 act.
     */
    public function testNoHoldExistsBeforeOrImmediatelyAfterAuthentication(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        $this->ambientScope($this->clinicA);
        $a2 = $this->restPost(self::NS . self::OTP_REQUEST_PATH, ['mobile' => self::MOBILE_T12]);
        self::assertSame(200, $a2->get_status(), 'positive control: A2 succeeds.');

        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'Before authentication: no Hold may exist.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'Before authentication: no held capacity.');

        // A3 (service-level like OtpFlowTest) — session is issued, still no Hold.
        $this->issueKnownOtpToken(self::MOBILE_T12, '404040');
        $result = App::otpService()->verify(self::MOBILE_T12, '404040');
        self::assertTrue((bool) $result['session_issued'], 'positive control: login verify issues the session.');
        self::assertGreaterThan(0, (int) $result['user_id'], 'positive control: user provisioned.');
        $this->ambientScope(null);

        self::assertSame(0, $this->countRows('cpms_slot_holds'), 'After A3 but before B1: still no Hold — the Hold is the authenticated B1 act.');
        self::assertSame(0, $this->heldCountOf($slot['slot_id']), 'After A3 but before B1: still no held capacity.');
    }

    /**
     * T13 — after authentication, B1 with the preserved selection creates the
     * existing normal Hold: holder_wp_user_id = the authenticated user, the
     * persisted slot/Clinic relationship governs (raw clinic/mobile claims in
     * the body are ignored), and the TTL is the configured default (600s).
     */
    public function testAuthenticatedHoldBindsAuthenticatedUserPersistedClinicAndConfiguredTtl(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);

        $userId = $this->provisionOtpUser(self::MOBILE_T13, '505050');

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
            // Tampered client claims — none of them has any authority.
            'clinic_id' => $this->clinicB,
            'holder_mobile' => '09000000000',
            'holder_wp_user_id' => 1,
        ], asUserId: $userId, withNonce: true);

        self::assertSame(200, $hold->get_status(), 'Authenticated B1 with the preserved selection must create a Hold. Body: ' . wp_json_encode($hold->get_data()));
        $data = $hold->get_data();
        self::assertNotEmpty($data['data']['hold_token'] ?? null, 'B1 returns the hold_token.');
        self::assertNotEmpty($data['data']['expires_at'] ?? null, 'B1 returns the expiry (Hold TTL/countdown contract).');

        $holdRow = $this->holdRowByToken((string) $data['data']['hold_token']);
        self::assertNotNull($holdRow, 'The Hold row must be persisted.');
        self::assertSame($userId, (int) $holdRow['holder_wp_user_id'], 'The holder is the AUTHENTICATED user, never a client claim.');
        self::assertSame($this->clinicA, (int) $holdRow['clinic_id'], 'The persisted slot->Clinic relationship governs — the raw clinic_id body claim is ignored.');
        self::assertSame($slot['slot_id'], (int) $holdRow['slot_id'], 'The Hold binds the persisted slot.');
        self::assertSame(self::MOBILE_T13, (string) $holdRow['holder_mobile'], 'Holder mobile comes from the server-side identity, not the body.');
        self::assertSame('active', (string) $holdRow['status'], 'A fresh Hold is active.');
        self::assertSame(1, $this->heldCountOf($slot['slot_id']), 'The authenticated Hold consumes exactly one unit of held capacity.');

        $ttl = strtotime((string) $holdRow['expires_at']) - time();
        self::assertGreaterThanOrEqual(540, $ttl, 'The Hold TTL is the existing configured default (600s), not shorter.');
        self::assertLessThanOrEqual(605, $ttl, 'The Hold TTL is the existing configured default (600s), not longer.');
    }

    // =================================================================
    // AREA 8 + 9 — NEW/EXISTING PATIENT NAMES + IDENTITY CLAIM TAMPERING
    // =================================================================

    /**
     * T14 — B2 sees NO Patient in hold.clinic_id (genuinely new Patient in
     * the booking Clinic): first_name and last_name are REQUIRED. Missing /
     * empty / whitespace-only fails with CLINIC_VALIDATION_FAILED, creates no
     * Patient and no Appointment, and leaves the Hold active and retryable.
     * A subsequent attempt WITH valid names succeeds and creates the Patient
     * with exactly those names. Tampered patient_id / is_new_user / mobile /
     * clinic_id claims in the B2 body never select or override the server
     * identity.
     *
     * RED on live main: B2 creates an empty-named minimal Patient and
     * confirms — none of the name requirements exist.
     */
    public function testNewPatientRequiresFirstAndLastNameBeforeConfirmAndHoldStaysRetryable(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);
        $decoyPatient = $this->insertPatient($this->clinicA, self::MOBILE_DECOY, 'Decoy', 'Target');

        $userId = $this->provisionOtpUser(self::MOBILE_T14, '606060');

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'positive control: authenticated Hold succeeds.');
        $holdToken = (string) $hold->get_data()['data']['hold_token'];

        // (1) missing first_name -> validation failure, nothing created.
        $attempt1 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'last_name' => 'احمدی',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($attempt1, 'CLINIC_VALIDATION_FAILED', 400, 'B2 with a missing first_name must fail validation.');
        $this->assertHoldUntouchedAndNothingCreated($slot['slot_id'], $holdToken, self::MOBILE_T14);

        // (2) whitespace-only first_name -> validation failure.
        $attempt2 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'first_name' => '   ',
            'last_name' => 'احمدی',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($attempt2, 'CLINIC_VALIDATION_FAILED', 400, 'B2 with a whitespace-only first_name must fail validation.');
        $this->assertHoldUntouchedAndNothingCreated($slot['slot_id'], $holdToken, self::MOBILE_T14);

        // (3) empty last_name -> validation failure.
        $attempt3 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'first_name' => 'سارا',
            'last_name' => '',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($attempt3, 'CLINIC_VALIDATION_FAILED', 400, 'B2 with a blank last_name must fail validation.');
        $this->assertHoldUntouchedAndNothingCreated($slot['slot_id'], $holdToken, self::MOBILE_T14);

        // (4) valid names + hostile identity claims -> normal confirm; the
        // server identity (hold ownership) is the only authority.
        $attempt4 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'first_name' => 'سارا',
            'last_name' => 'احمدی',
            'patient_id' => $decoyPatient,
            'is_new_user' => false,
            'mobile' => '09000000000',
            'clinic_id' => $this->clinicB,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        self::assertSame(200, $attempt4->get_status(), 'B2 with valid names must confirm. Body: ' . wp_json_encode($attempt4->get_data()));
        $confirmData = $attempt4->get_data();
        self::assertSame('confirmed', (string) ($confirmData['data']['status'] ?? ''), 'The final confirm produces a confirmed appointment.');
        self::assertMatchesRegularExpression('/^AP-\d{8}-\d{2}$/', (string) ($confirmData['data']['reference_code'] ?? ''), 'The confirm returns the reference code.');
        self::assertNotEmpty($confirmData['data']['jalali'] ?? null, 'The confirm returns the Jalali confirmation date.');
        self::assertGreaterThan(0, (int) ($confirmData['data']['appointment_id'] ?? 0), 'The confirm returns the appointment id.');

        $newPatient = $this->patientRowByMobile($this->clinicA, self::MOBILE_T14);
        self::assertNotNull($newPatient, 'A genuinely new Patient must be created in the BOOKING Clinic.');
        self::assertSame('سارا', (string) $newPatient['first_name'], 'The Patient is created with the validated first_name.');
        self::assertSame('احمدی', (string) $newPatient['last_name'], 'The Patient is created with the validated last_name.');
        self::assertSame($this->clinicA, (int) $newPatient['clinic_id'], 'The Patient belongs to the hold Clinic, not the client-claimed clinic_id.');
        self::assertNotSame($decoyPatient, (int) $newPatient['id'], 'The client-sent patient_id claim must never select the identity.');
        self::assertSame(self::MOBILE_T14, (string) $newPatient['mobile'], 'The Patient mobile comes from the server identity (hold ownership).');

        self::assertSame(
            1,
            $this->countAppointmentsForPatient((int) $newPatient['id']),
            'Exactly one appointment is created by the successful confirm.'
        );
        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $confirmData['data']['appointment_id']]
        );
        self::assertSame((int) $newPatient['id'], $apptPatient, 'The appointment binds the server-resolved new Patient.');
        self::assertSame(0, $this->countAppointmentsForPatient($decoyPatient), 'The client-claimed decoy patient_id gains no appointment.');
        self::assertSame(1, $this->countPatientLinksForUser($userId), 'The new Patient links to the authenticated user.');

        $holdRow = $this->holdRowByToken($holdToken);
        self::assertSame('converted', (string) $holdRow['status'], 'A successful confirm converts the Hold.');
    }

    /**
     * T15 — B2 sees an EXISTING Patient in hold.clinic_id: names are not
     * required AND client-supplied first_name/last_name are ignored (B2 is
     * not a profile-update route). The existing Patient row is unchanged.
     */
    public function testExistingPatientNamesAreNeitherRequiredNorRewrittenByConfirm(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);
        $patient = $this->insertPatient($this->clinicA, self::MOBILE_T15, 'Existing', 'Person');

        $this->ambientScope($this->clinicA);
        $this->issueKnownOtpToken(self::MOBILE_T15, '707070');
        $result = App::otpService()->verify(self::MOBILE_T15, '707070');
        $this->ambientScope(null);
        $userId = (int) $result['user_id'];
        self::assertGreaterThan(0, $userId, 'positive control: verify resolves a user.');
        self::assertSame(
            [$patient],
            array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), (array) $result['patient_links']),
            'positive control: the existing challenge-Clinic-adjacent patient is linked (established resolver).'
        );

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'positive control: hold succeeds.');

        $confirm = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => (string) $hold->get_data()['data']['hold_token'],
            'first_name' => 'Tampered',
            'last_name' => 'Rewrite',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        self::assertSame(200, $confirm->get_status(), 'Names are not required when a Patient exists in the booking Clinic.');

        $after = $this->patientRowByMobile($this->clinicA, self::MOBILE_T15);
        self::assertSame('Existing', (string) $after['first_name'], 'B2 is not a profile-update route: first_name is untouched.');
        self::assertSame('Person', (string) $after['last_name'], 'B2 is not a profile-update route: last_name is untouched.');

        $apptPatient = (int) App::db()->fetchValue(
            'SELECT patient_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [(int) $confirm->get_data()['data']['appointment_id']]
        );
        self::assertSame($patient, $apptPatient, 'The appointment binds the EXISTING Patient in the booking Clinic.');
    }

    /**
     * T16 — a Patient existing ONLY in another Clinic is treated as NEW in
     * the booking Clinic: names are required; with valid names a NEW Patient
     * is created in the booking Clinic and the other-Clinic row is untouched.
     */
    public function testPatientExistingOnlyInAnotherClinicIsTreatedAsNewInBookingClinic(): void
    {
        $this->buildTwoClinicFixture();
        $slot = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(5), '10:00:00', 1);
        $patientB = $this->insertPatient($this->clinicB, self::MOBILE_T16, 'Elsewhere', 'Only');

        $userId = $this->provisionOtpUser(self::MOBILE_T16, '808080');

        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot['date'],
            'slot_time' => $slot['time'],
            'slot_id' => $slot['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'positive control: hold succeeds.');
        $holdToken = (string) $hold->get_data()['data']['hold_token'];

        $attempt1 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError(
            $attempt1,
            'CLINIC_VALIDATION_FAILED',
            400,
            'A Patient that exists only in ANOTHER Clinic does not satisfy the booking-Clinic identity — '
            . 'first_name/last_name are required for the genuinely new Patient here.'
        );
        $this->assertHoldUntouchedAndNothingCreated($slot['slot_id'], $holdToken, self::MOBILE_T16);

        $attempt2 = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
            'first_name' => 'نرگس',
            'last_name' => 'کریمی',
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        self::assertSame(200, $attempt2->get_status(), 'The new booking-Clinic Patient confirms with valid names.');

        $newPatient = $this->patientRowByMobile($this->clinicA, self::MOBILE_T16);
        self::assertNotNull($newPatient, 'A NEW Patient is created in the booking Clinic.');
        self::assertSame('نرگس', (string) $newPatient['first_name']);
        self::assertSame('کریمی', (string) $newPatient['last_name']);
        self::assertNotSame($patientB, (int) $newPatient['id'], 'The booking-Clinic Patient is a distinct identity.');

        $otherClinicRow = $this->patientRowByMobile($this->clinicB, self::MOBILE_T16);
        self::assertSame('Elsewhere', (string) $otherClinicRow['first_name'], 'The other-Clinic Patient row is untouched.');
        self::assertSame('Only', (string) $otherClinicRow['last_name'], 'The other-Clinic Patient row is untouched.');
        self::assertSame(0, $this->countAppointmentsForPatient($patientB), 'The other-Clinic Patient gains no appointment here.');
    }

    // =================================================================
    // AREA 10 — B1 LOSS / ALTERNATIVES (guards)
    // =================================================================

    /**
     * T17 — authenticated atomicHold race loss: no Hold, no capacity drift,
     * 409 CLINIC_SLOT_TAKEN with the established nearby_slots policy:
     * maximum 5, same local date, same losing Location, same
     * Clinic + clinician, deterministic ordering (slot_date ASC,
     * slot_time ASC, id ASC), isolation witnesses excluded.
     */
    public function testAuthenticatedHoldRaceLossReturns409WithEstablishedNearbySlotsPolicy(): void
    {
        $this->buildTwoClinicFixture();
        $losingDate = $this->ymdDaysAhead(4);
        $nextDate = $this->ymdDaysAhead(5);

        $losing = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '10:00:00', 1);

        // Six free alternatives — same local date, SAME Location — seeded out
        // of chronological order to prove deterministic ordering and the cap.
        $sixthBeyondCap = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '13:00:00', 1);
        $alt0930 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '09:30:00', 1);
        $alt1100 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '11:00:00', 1);
        $alt1200 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '12:00:00', 1);
        $alt1030 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '10:30:00', 1);
        $alt0800 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $losingDate, '08:00:00', 1);

        // Second Location in Clinic A + isolation witnesses.
        $locationA2 = $this->insertLocation($this->clinicA, 'Branch A2 P8S2', 'branch-a2-p8s2-' . uniqid('', false), self::TZ_TEHRAN, 0);
        $otherLocationWitness = $this->seedSlot($this->clinicA, $locationA2, $this->clinicianA, $losingDate, '15:00:00', 1);
        $nextDateWitness = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $nextDate, '09:00:00', 1);

        // Winner takes the capacity of the losing slot first.
        $winnerId = $this->provisionOtpUser(self::MOBILE_T17A, '909090');
        $this->ambientScope($this->clinicA);
        $winnerHold = App::bookingService()->hold($winnerId, $this->clinicianA, $losing['date'], $losing['time'], $losing['slot_id']);
        $this->ambientScope(null);
        self::assertNotEmpty($winnerHold['hold_token'] ?? null, 'positive control: the winner holds the losing slot.');
        self::assertSame(1, $this->heldCountOf($losing['slot_id']), 'positive control: losing slot capacity is now consumed.');

        $loserId = $this->provisionOtpUser(self::MOBILE_T17B, '919191');
        $loss = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $losing['date'],
            'slot_time' => $losing['time'],
            'slot_id' => $losing['slot_id'],
        ], asUserId: $loserId, withNonce: true);

        self::assertSame(409, $loss->get_status(), 'An atomicHold race loss must be 409.');
        $body = $loss->get_data();
        self::assertSame('CLINIC_SLOT_TAKEN', (string) ($body['code'] ?? ''), 'The losing envelope keeps CLINIC_SLOT_TAKEN.');

        $nearby = $body['data']['nearby_slots'] ?? null;
        self::assertIsArray($nearby, 'The established owner policy: race loss carries nearby_slots.');
        self::assertLessThanOrEqual(5, count($nearby), 'nearby_slots is capped at 5.');

        $nearbyIds = array_map(static fn (array $row): int => (int) ($row['slot_id'] ?? 0), $nearby);
        $expectedOrdered = [$alt0800['slot_id'], $alt0930['slot_id'], $alt1030['slot_id'], $alt1100['slot_id'], $alt1200['slot_id']];
        self::assertSame(
            $expectedOrdered,
            array_values($nearbyIds),
            'nearby_slots: same local date, same losing Location, deterministic order (date, time, id), capped at 5 here.'
        );
        self::assertNotContains($losing['slot_id'], $nearbyIds, 'The losing slot itself is never an alternative.');
        self::assertNotContains($otherLocationWitness['slot_id'], $nearbyIds, 'Another Location is never an alternative.');
        self::assertNotContains($nextDateWitness['slot_id'], $nearbyIds, 'Another local date is never an alternative.');
        self::assertNotContains($sixthBeyondCap['slot_id'], $nearbyIds, 'The 6th eligible alternative is cut by the cap of 5.');
        foreach ($nearby as $entry) {
            self::assertSame($this->locationA, (int) ($entry['location_id'] ?? 0), 'Every alternative keeps the losing Location.');
            self::assertSame($losing['date'], (string) ($entry['date'] ?? ''), 'Every alternative keeps the losing local date.');
            self::assertGreaterThan(0, (int) ($entry['capacity_left'] ?? 0), 'Every alternative is genuinely free.');
        }

        self::assertSame(0, $this->countHoldsForUser($loserId), 'A lost B1 creates no Hold for the loser.');
        self::assertSame(1, $this->heldCountOf($losing['slot_id']), 'A lost B1 causes no capacity drift.');
    }

    /**
     * T18 — unrelated B1 failures (CLINIC_NOT_FOUND 404 / policy failures)
     * never gain nearby_slots.
     */
    public function testUnrelatedHoldFailuresNeverGainNearbySlots(): void
    {
        $this->buildTwoClinicFixture();
        $userId = $this->provisionOtpUser(self::MOBILE_T18, '929292');

        // 404 — the selection resolves to nothing persisted.
        $notFound = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $this->ymdDaysAhead(4),
            'slot_time' => '10:00',
        ], asUserId: $userId, withNonce: true);
        $this->assertClinicError($notFound, 'CLINIC_NOT_FOUND', 404, 'A selection with no persisted slot is 404.');
        $body = $notFound->get_data();
        self::assertArrayNotHasKey('nearby_slots', (array) ($body['data'] ?? []), 'nearby_slots belongs to CLINIC_SLOT_TAKEN only — never to 404.');

        // Policy failure — slot exists but sits inside the min-lead window.
        $near = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_TEHRAN));
        $near = $near->modify('+25 minutes');
        $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $near->format('Y-m-d'), $near->format('H:i:00'), 1);
        $policy = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $near->format('Y-m-d'),
            'slot_time' => $near->format('H:i:00'),
        ], asUserId: $userId, withNonce: true);
        $this->assertClinicError($policy, 'CLINIC_POLICY_VIOLATION', 409, 'A slot inside min-lead is a policy failure.');
        $body = $policy->get_data();
        self::assertArrayNotHasKey('nearby_slots', (array) ($body['data'] ?? []), 'nearby_slots belongs to CLINIC_SLOT_TAKEN only — never to policy failures.');
    }

    // =================================================================
    // AREA 11 — B2 / IDEMPOTENCY (guards)
    // =================================================================

    /**
     * T19 — the existing UUID Idempotency-Key contract in the Slice-2
     * (OTP-authenticated) context: same-key replay returns the same
     * reference_code and creates no duplicate appointment/patient; another
     * user's hold is 403; an expired hold is CLINIC_HOLD_EXPIRED.
     */
    public function testConfirmIdempotencyOwnershipAndExpiryContractsForOtpAuthenticatedUser(): void
    {
        $this->buildTwoClinicFixture();
        $slot1 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(4), '10:00:00', 1);
        $slot2 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(4), '11:00:00', 1);
        $slot3 = $this->seedSlot($this->clinicA, $this->locationA, $this->clinicianA, $this->ymdDaysAhead(4), '12:00:00', 1);

        $patient = $this->insertPatient($this->clinicA, self::MOBILE_T19A, 'Idem', 'Potent');
        $patientOther = $this->insertPatient($this->clinicA, self::MOBILE_T19B, 'Other', 'Owner');
        unset($patientOther); // exists solely so the second user has a distinct identity anchor

        $userId = $this->provisionOtpUser(self::MOBILE_T19A, '939393');
        $otherUserId = $this->provisionOtpUser(self::MOBILE_T19B, '949494');

        // --- same-key replay = same reference_code, no duplicates ---
        $hold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot1['date'],
            'slot_time' => $slot1['time'],
            'slot_id' => $slot1['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $hold->get_status(), 'positive control: hold succeeds.');
        $holdToken = (string) $hold->get_data()['data']['hold_token'];

        $key = $this->uuid();
        $confirm = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $key);
        self::assertSame(200, $confirm->get_status(), 'positive control: confirm succeeds.');
        $referenceCode = (string) $confirm->get_data()['data']['reference_code'];
        $appointmentId = (int) $confirm->get_data()['data']['appointment_id'];
        self::assertNotEmpty($referenceCode, 'positive control: reference code returned.');
        self::assertNotEmpty($confirm->get_data()['data']['jalali'] ?? null, 'positive control: Jalali confirmation returned.');

        $replay = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $holdToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $key);
        self::assertSame(200, $replay->get_status(), 'Same-key replay must not error.');
        self::assertSame($referenceCode, (string) $replay->get_data()['data']['reference_code'], 'Same-key replay returns the SAME reference_code.');
        self::assertSame($appointmentId, (int) $replay->get_data()['data']['appointment_id'], 'Same-key replay returns the same appointment.');
        self::assertSame(1, $this->countAppointmentsForPatient($patient), 'No duplicate appointment is created by replay.');
        self::assertSame(1, $this->countPatientsForMobile($this->clinicA, self::MOBILE_T19A), 'No duplicate patient is created by replay.');

        // --- another user's hold = 403 ---
        $otherHold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot2['date'],
            'slot_time' => $slot2['time'],
            'slot_id' => $slot2['slot_id'],
        ], asUserId: $otherUserId, withNonce: true);
        self::assertSame(200, $otherHold->get_status(), 'positive control: the other user holds a different slot.');
        $stolen = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => (string) $otherHold->get_data()['data']['hold_token'],
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($stolen, 'CLINIC_PERMISSION_DENIED', 403, "Confirming another user's hold is denied.");

        // --- expired hold = CLINIC_HOLD_EXPIRED ---
        $expiredHold = $this->restPost(self::NS . self::HOLD_PATH, [
            'clinician_id' => $this->clinicianA,
            'slot_date' => $slot3['date'],
            'slot_time' => $slot3['time'],
            'slot_id' => $slot3['slot_id'],
        ], asUserId: $userId, withNonce: true);
        self::assertSame(200, $expiredHold->get_status(), 'positive control: a second hold succeeds.');
        $expiredToken = (string) $expiredHold->get_data()['data']['hold_token'];
        $expiredRow = $this->holdRowByToken($expiredToken);
        App::db()->update('cpms_slot_holds', [
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600) . '.000',
        ], ['id' => (int) $expiredRow['id']]);

        $expired = $this->restPost(self::NS . self::CONFIRM_PATH, [
            'hold_token' => $expiredToken,
        ], asUserId: $userId, withNonce: true, idempotencyKey: $this->uuid());
        $this->assertClinicError($expired, 'CLINIC_HOLD_EXPIRED', 422, 'Confirming an expired hold fails with the established envelope.');
        self::assertSame(1, $this->countAppointmentsForPatient($patient), 'An expired hold creates no appointment.');
    }

    // =================================================================
    // AREA 12 — UI CONTINUATION CONTRACT (server-observable markers)
    // =================================================================

    /**
     * T20 — the anonymous Slice-1 surface must expose the continue-to-auth
     * affordance and the mobile + 6-digit OTP states, and publish the
     * EXISTING A2/A3 paths in its runtime config; it must publish NO nonce,
     * NO hold/confirm paths, NO custom auth token and NO patient identity.
     *
     * Guards (pass today): the absence assertions (no nonce / hold / confirm
     * / token / PHI in the anonymous config).
     * RED: otp_request_path / otp_verify_path / continue-auth / otp-mobile /
     * otp-code markers do not exist yet.
     */
    public function testAnonymousSurfaceExposesAuthContinuationWithoutAnySessionCredentials(): void
    {
        $this->buildTwoClinicFixture();
        wp_set_current_user(0);

        $html = $this->renderSurface($this->clinicA);
        self::assertStringContainsString(self::ROOT_CLASS, $html, 'positive control: the Slice-1 surface renders.');

        $config = $this->extractConfig($html);
        self::assertNotSame([], $config, 'positive control: the surface publishes its runtime config.');
        $published = (string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // ---- guards: still no session credentials / PHI on the anonymous surface ----
        self::assertStringNotContainsString('nonce', $published, 'The anonymous surface must publish no nonce.');
        self::assertStringNotContainsString(self::HOLD_PATH, $published, 'The anonymous surface must publish no hold path.');
        self::assertStringNotContainsString(self::CONFIRM_PATH, $published, 'The anonymous surface must publish no confirm path.');
        self::assertStringNotContainsString('@otp.cpms.local', $published, 'The anonymous surface must publish no identity artifact.');
        self::assertStringNotContainsString('auth_token', $published, 'No custom auth token may be introduced.');
        self::assertStringNotContainsString('Authorization', $html, 'No custom Authorization scheme may be introduced.');

        // ---- RED: the continue-to-auth contract ----
        self::assertSame(
            self::OTP_REQUEST_PATH,
            $config['otp_request_path'] ?? null,
            'The bookable surface must publish the EXISTING A2 path for the continue-to-auth step.'
        );
        self::assertSame(
            self::OTP_VERIFY_PATH,
            $config['otp_verify_path'] ?? null,
            'The bookable surface must publish the EXISTING A3 path for the continue-to-auth step.'
        );
        self::assertStringContainsString(
            self::CONTINUE_AUTH_MARKER,
            $html,
            'The bookable state must expose the continue-to-auth affordance (non-PHI selector handoff only).'
        );
        self::assertStringContainsString(
            self::OTP_MOBILE_MARKER,
            $html,
            'The auth continuation must include the mobile-entry state.'
        );
        self::assertStringContainsString(
            self::OTP_CODE_MARKER,
            $html,
            'The auth continuation must include the 6-digit OTP-code state.'
        );
    }

    /**
     * T21 — the public booking JS must post A3 with same-origin credentials
     * (the WP session cookie travels with the fetch) and reload the surface
     * after a successful verify so the authenticated render can publish its
     * contract; the pre-login A1/A4 fetches stay credentials-omit.
     */
    public function testPublicBookingJsPostsOtpVerifyWithSameOriginCredentialsAndReloadsOnSuccess(): void
    {
        $jsFile = $this->pluginDir() . 'assets/js/cpms-public-booking.js';
        self::assertFileExists($jsFile, 'positive control: the Slice-1 JS asset exists.');
        $source = file_get_contents($jsFile);
        self::assertIsString($source, 'positive control: the JS asset is readable.');

        // Guard: the anonymous A1/A4 requests keep credentials:'omit'.
        self::assertGreaterThanOrEqual(
            1,
            substr_count($source, "credentials: 'omit'"),
            'Guard: pre-login A1/A4 fetches keep credentials:omit (the surface works anonymous-first).'
        );
        self::assertSame(0, substr_count($source, 'Authorization'), 'No custom Authorization scheme may be introduced.');

        // RED: A3 uses same-origin credentials (WP auth cookie) and the
        // surface reloads after a successful verify.
        self::assertGreaterThanOrEqual(
            1,
            substr_count($source, "credentials: 'same-origin'"),
            'A3 (and the OTP step generally) must run with credentials:same-origin so the WP session cookie '
            . 'issued by verify travels same-origin — no custom auth token exists.'
        );
        self::assertGreaterThanOrEqual(
            1,
            substr_count($source, 'location.reload'),
            'After a successful A3 the surface reloads so the authenticated render publishes its contract '
            . '(nonce + B1/B2 paths) and the preserved selection re-presents.'
        );
    }

    /**
     * T22 — an authenticated PATIENT render publishes the wp_rest nonce and
     * the EXISTING B1/B2 paths, so the reloaded surface can run the
     * authenticated Hold -> confirm continuation.
     */
    public function testAuthenticatedPatientRenderPublishesNonceAndHoldConfirmPaths(): void
    {
        $this->buildTwoClinicFixture();
        $patientUserId = (int) wp_create_user('p8s2_patient_render', 'pass-not-used-123', '09129981031@otp.cpms.local');
        self::assertGreaterThan(0, $patientUserId);
        $this->setRole($patientUserId, 'cpms_patient');
        wp_set_current_user($patientUserId);

        $html = $this->renderSurface($this->clinicA);
        $config = $this->extractConfig($html);
        self::assertNotSame([], $config, 'positive control: the surface publishes its runtime config.');

        $nonce = $config['nonce'] ?? null;
        self::assertIsString($nonce, 'The authenticated patient render must publish a wp_rest nonce for B1/B2.');
        self::assertNotSame('', $nonce);
        self::assertNotFalse(
            wp_verify_nonce($nonce, 'wp_rest'),
            'The published nonce must be a valid wp_rest nonce for the authenticated patient (same-origin CSRF protection).'
        );
        self::assertSame(
            self::HOLD_PATH,
            $config['hold_path'] ?? null,
            'The authenticated patient render must publish the EXISTING B1 path.'
        );
        self::assertSame(
            self::CONFIRM_PATH,
            $config['confirm_path'] ?? null,
            'The authenticated patient render must publish the EXISTING B2 path.'
        );

        wp_set_current_user(0);
    }

    /**
     * T23 — a logged-in NON-patient (staff without the patient role) receives
     * NO patient continuation: no nonce, no hold/confirm paths, no OTP verify
     * continuation markers.
     */
    public function testLoggedInNonPatientReceivesNoPatientContinuation(): void
    {
        $this->buildTwoClinicFixture();
        $staffId = (int) wp_create_user('p8s2_staff_render', 'pass-not-used-123', '09129981032@otp.cpms.local');
        self::assertGreaterThan(0, $staffId);
        $this->setRole($staffId, 'cpms_secretary');
        cpms_test_seed_membership($staffId, $this->clinicA, 'cpms_secretary');
        wp_set_current_user($staffId);

        $html = $this->renderSurface($this->clinicA);
        $config = $this->extractConfig($html);
        $published = (string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString('nonce', $published, 'A logged-in non-patient must not receive a session nonce for patient flows.');
        self::assertStringNotContainsString(self::HOLD_PATH, $published, 'A logged-in non-patient must not receive the hold path.');
        self::assertStringNotContainsString(self::CONFIRM_PATH, $published, 'A logged-in non-patient must not receive the confirm path.');
        self::assertStringNotContainsString(
            self::OTP_CODE_MARKER,
            $html,
            'A logged-in non-patient must not receive the patient OTP continuation.'
        );

        wp_set_current_user(0);
    }

    // =================================================================
    // AREA 13 — SCHEMA RED (future migration contract; no migration file)
    // =================================================================

    /**
     * T24 — the future schema contract for the durable OTP-challenge Clinic
     * binding: cpms_otp_tokens.clinic_id BIGINT UNSIGNED, NULL allowed, NO
     * default, with an FK to cpms_clinics(id). Historical NULL rows remain
     * supported; no backfill. ALSO (GREEN-reachable only, deliberately placed
     * BEHIND the presence assertion so RED never touches migration state):
     * rollbackOne() must drop the FK then the column, and migrate() must
     * restore them — the down/up contract of the future migration.
     */
    public function testOtpChallengeClinicColumnFutureMigrationContract(): void
    {
        global $wpdb;
        $otpTable = $wpdb->prefix . 'cpms_otp_tokens';
        $clinicsTable = $wpdb->prefix . 'cpms_clinics';

        $column = $wpdb->get_row("SHOW COLUMNS FROM {$otpTable} LIKE 'clinic_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertNotNull(
            $column,
            'cpms_otp_tokens.clinic_id must exist (future migration contract — the durable trusted '
            . 'binding of an OTP challenge to its Clinic; architecture proof found no existing safe identifier).'
        );

        $type = strtolower((string) ($column['Type'] ?? ''));
        self::assertStringContainsString('bigint', $type, 'clinic_id must be BIGINT.');
        self::assertStringContainsString('unsigned', $type, 'clinic_id must be UNSIGNED.');
        self::assertSame('YES', (string) ($column['Null'] ?? ''), 'clinic_id must allow NULL (historical rows remain — no backfill).');
        self::assertNull($column['Default'] ?? null, 'clinic_id must have NO default.');

        $fk = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s
                   AND REFERENCED_TABLE_NAME = %s
                   AND REFERENCED_COLUMN_NAME = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $otpTable,
                'clinic_id',
                $clinicsTable,
                'id'
            )
        );
        self::assertGreaterThanOrEqual(1, $fk, 'clinic_id must carry an FK to cpms_clinics(id).');

        // ----- down/up contract (GREEN-reachable only — see header) -----
        $versionBeforeRollback = App::migrations()->currentVersion();
        self::assertIsString($versionBeforeRollback, 'The applied migration version must be readable.');

        $rolled = $this->withRealTables(static fn (): ?string => App::migrations()->rollbackOne());
        self::assertIsString($rolled, 'The binding migration must be rollbackable.');
        $afterDown = $wpdb->get_row("SHOW COLUMNS FROM {$otpTable} LIKE 'clinic_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertNull($afterDown, 'The down migration must drop the FK then the column.');

        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        $afterUp = $wpdb->get_row("SHOW COLUMNS FROM {$otpTable} LIKE 'clinic_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        self::assertNotNull($afterUp, 'Re-migrating must restore the column (up/down are inverses).');
        self::assertSame(
            $versionBeforeRollback,
            App::migrations()->currentVersion(),
            'The applied version after the round-trip must equal the version before it.'
        );
    }

    /**
     * T25 — the RED state for the schema: the latest applied migration is
     * still the live baseline retrieved from live main; the binding migration
     * is a future contract. This assertion fails today and becomes executable
     * truth only once GREEN adds the migration (no filename reserved here).
     */
    public function testLatestAppliedMigrationHasAdvancedBeyondTheLiveBaseline(): void
    {
        $current = App::migrations()->currentVersion();
        self::assertIsString($current, 'The applied migration version must be readable.');
        self::assertNotSame(
            self::LIVE_MIGRATION_BASELINE,
            $current,
            'The OTP-challenge Clinic binding requires its migration. Live main was retrieved first; '
            . 'the live baseline is ' . self::LIVE_MIGRATION_BASELINE . ' (also pinned by '
            . 'MigrationTest::LATEST_VERSION) and the repository naming convention objectively defines '
            . 'the next number — this RED reserves no filename and adds no migration file.'
        );
    }

    // =================================================================
    // Fixture builders
    // =================================================================

    /**
     * Two-Clinic fixture (AD-13: dynamic ids, never clinic 1).
     *
     * Builds: one Organization; Clinics A and B; one primary Tehran Location
     * per Clinic; one active clinician per Clinic plus one INACTIVE clinician
     * in Clinic A; OTP cooldown disabled on BOTH Clinics (established pattern
     * for back-to-back OTP calls within one test).
     */
    private function buildTwoClinicFixture(): void
    {
        $now = App::db()->nowUtcSql();
        $uid = uniqid('p8s2', false);

        $this->orgA = $this->insertRow('cpms_organizations', [
            'name' => 'Org P8S2 ' . $uid,
            'slug' => 'org-p8s2-' . $uid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s', '%s', '%s', '%s', '%s'], 'organization');

        $this->clinicA = $this->insertClinic('Clinic A P8S2 ' . $uid, 'clinic-a-p8s2-' . $uid, $now);
        $this->clinicB = $this->insertClinic('Clinic B P8S2 ' . $uid, 'clinic-b-p8s2-' . $uid, $now);

        $this->locationA = $this->insertLocation($this->clinicA, 'Main A P8S2', 'main-a-p8s2-' . $uid, self::TZ_TEHRAN, 1);
        $this->locationB = $this->insertLocation($this->clinicB, 'Main B P8S2', 'main-b-p8s2-' . $uid, self::TZ_TEHRAN, 1);

        $this->clinicianA = $this->insertClinician($this->clinicA, 'Dr. Slice2 A ' . $uid, 1);
        $this->clinicianAInactive = $this->insertClinician($this->clinicA, 'Dr. Slice2 Inactive ' . $uid, 0);
        $this->clinicianB = $this->insertClinician($this->clinicB, 'Dr. Slice2 B ' . $uid, 1);

        // OTP cooldown 0 on both Clinics (identity-level cooldown is NOT being
        // tested here; AD-15 semantics stay governed by OtpPolicy — T10 relies
        // on two back-to-back A2 calls for the same mobile).
        foreach ([$this->clinicA, $this->clinicB] as $clinicId) {
            $settings = new Settings(App::db(), $clinicId, App::audit());
            $settings->set('otp.cooldown_sec', 0);
        }
        Settings::flushCache();

        ScopeContext::clear();
        App::resetScope();

        self::assertGreaterThan(1, $this->clinicA, 'AD-13: fixture Clinics are dynamic, never clinic 1.');
        self::assertNotSame($this->clinicA, $this->clinicB, 'precondition: two distinct Clinics.');
        self::assertNotSame($this->locationA, $this->locationB, 'precondition: two distinct Locations.');
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

    private function insertLocation(int $clinicId, string $name, string $slug, string $tz, int $primary): int
    {
        return $this->insertRow('cpms_locations', [
            'clinic_id' => $clinicId,
            'name' => $name,
            'slug' => $slug,
            'timezone' => $tz,
            'is_primary' => $primary,
            'is_active' => 1,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'], 'location');
    }

    private function insertClinician(int $clinicId, string $name, int $isActive): int
    {
        return $this->insertRow('cpms_clinicians', [
            'clinic_id' => $clinicId,
            'full_name' => $name,
            'is_active' => $isActive,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%s', '%d', '%s', '%s'], 'clinician');
    }

    private function insertPatient(int $clinicId, string $mobile, string $firstName, string $lastName): int
    {
        return $this->insertRow('cpms_patients', [
            'clinic_id' => $clinicId,
            'mrn' => 'MR-P8S2-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => $mobile,
            'status' => 'active',
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'], 'patient');
    }

    /**
     * @return array{slot_id: int, date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time, int $capacity): array
    {
        $slotId = $this->insertRow('cpms_schedule_slots', [
            'clinic_id' => $clinicId,
            'location_id' => $locationId,
            'clinician_id' => $clinicianId,
            'slot_date' => $date,
            'slot_time' => $time,
            'duration_min' => 20,
            'capacity' => $capacity,
            'booked_count' => 0,
            'held_count' => 0,
            'is_open' => 1,
            'generated_from' => 'manual',
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'], 'slot');

        return ['slot_id' => $slotId, 'date' => $date, 'time' => $time];
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

    // =================================================================
    // OTP helpers
    // =================================================================

    /**
     * Inserts a challenge row with a KNOWN code (the established
     * OtpFlowTest pattern — the code itself is never stored raw).
     * Only columns that exist on live main are written.
     *
     * @return int the new challenge id
     */
    private function issueKnownOtpToken(string $mobile, string $code): int
    {
        $ok = App::db()->insert('cpms_otp_tokens', [
            'mobile' => $mobile,
            'purpose' => 'login',
            'code_hash' => OtpPolicy::hashCode($code, $this->otpPepper()),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 300) . '.000',
            'attempts' => 0,
            'created_at' => App::db()->nowUtcSql(),
        ]);
        self::assertTrue($ok, 'fixture OTP token insert failed.');

        return (int) App::db()->wpdb_last_insert_id();
    }

    /**
     * Pins the latest challenge's code hash to a known code RIGHT AFTER real
     * A2 challenge creation (no SMS capture exists in this suite).
     *
     * @return int the challenge id
     */
    private function patchLatestTokenCode(string $mobile, string $code): int
    {
        $row = $this->latestOtpTokenRow($mobile);
        self::assertNotNull($row, 'precondition: an A2 challenge row exists for ' . $mobile . '.');
        App::db()->update('cpms_otp_tokens', [
            'code_hash' => OtpPolicy::hashCode($code, $this->otpPepper()),
        ], ['id' => (int) $row['id']]);

        return (int) $row['id'];
    }

    /**
     * Provisions an OTP-authenticated patient-role user through the real
     * service path (known-code token + verify), under the Clinic A ambient.
     */
    private function provisionOtpUser(string $mobile, string $code): int
    {
        $this->ambientScope($this->clinicA);
        $this->issueKnownOtpToken($mobile, $code);
        $result = App::otpService()->verify($mobile, $code);
        $this->ambientScope(null);

        $userId = (int) $result['user_id'];
        self::assertGreaterThan(0, $userId, 'positive control: OTP verify resolves/provisions a user.');
        self::assertTrue((bool) $result['session_issued'], 'positive control: login-purpose verify issues a session.');

        return $userId;
    }

    /**
     * Established pepper resolution (mirrors OtpFlowTest).
     */
    private function otpPepper(): string
    {
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            $pepper = bin2hex(random_bytes(32));
            update_option('cpms_otp_pepper', $pepper, 'no');
        }

        return $pepper;
    }

    // =================================================================
    // REST helpers
    // =================================================================

    /**
     * @param array<string, mixed> $body
     */
    private function restPost(string $route, array $body, ?int $asUserId = null, bool $withNonce = false, ?string $idempotencyKey = null): WP_REST_Response
    {
        wp_set_current_user($asUserId !== null ? $asUserId : 0);

        $request = new WP_REST_Request('POST', $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($withNonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        if ($idempotencyKey !== null) {
            $request->set_header('Idempotency-Key', $idempotencyKey);
        }

        return rest_do_request($request);
    }

    /**
     * Same dispatch, but an escaping product exception becomes observable
     * test data (an attributable FAILURE, never a PHPUnit ERROR).
     *
     * @param array<string, mixed> $body
     * @return array{response: ?WP_REST_Response, thrown: ?\Throwable}
     */
    private function restPostCatching(string $route, array $body): array
    {
        try {
            return ['response' => $this->restPost($route, $body), 'thrown' => null];
        } catch (\Throwable $e) {
            return ['response' => null, 'thrown' => $e];
        }
    }

    /**
     * Standard CLINIC_* envelope assertion (ADR-0019), status included.
     */
    private function assertClinicError(WP_REST_Response $response, string $code, int $status, string $what): void
    {
        self::assertSame($status, $response->get_status(), $what . ' Body: ' . wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertIsArray($data, 'Error envelope must be an array.');
        self::assertArrayHasKey('code', $data, 'Error envelope must carry a machine-readable code.');
        self::assertSame($code, (string) $data['code'], $what);
        self::assertArrayHasKey('message', $data);
        self::assertSame($status, (int) ($data['data']['status'] ?? 0), 'Envelope data.status must match the HTTP status.');
    }

    private function ambientScope(?int $clinicId): void
    {
        if ($clinicId === null) {
            ScopeContext::clear();
        } else {
            ScopeContext::set(ClinicScope::forClinic($clinicId));
        }
        Settings::flushCache();
    }

    // =================================================================
    // Surface render helpers
    // =================================================================

    private function renderSurface(int $clinicId): string
    {
        $html = do_shortcode('[' . self::SHORTCODE . ' clinic_id="' . $clinicId . '"]');
        self::assertIsString($html, 'do_shortcode must return a string.');
        self::assertStringNotContainsString('[' . self::SHORTCODE, $html, 'The shortcode must be registered and rendered (not echoed literally).');

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractConfig(string $html): array
    {
        $matched = preg_match(
            '/<script type="application\/json" class="[^"]*' . preg_quote(self::CONFIG_CLASS, '/') . '[^"]*">(.*?)<\/script>/s',
            $html,
            $m
        );
        if ($matched !== 1) {
            return [];
        }
        $decoded = json_decode(trim($m[1]), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function pluginDir(): string
    {
        return dirname(__DIR__, 2) . '/';
    }

    // =================================================================
    // Row readers / counters
    // =================================================================

    /**
     * @return array<string, mixed>|null
     */
    private function latestOtpTokenRow(string $mobile): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s ORDER BY id DESC LIMIT 1',
            [$mobile]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function otpTokenRowById(int $id): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = %d LIMIT 1',
            [$id]
        );

        return is_array($row) ? $row : null;
    }

    private function countOtpTokens(string $mobile): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s',
            [$mobile]
        );
    }

    private function countSmsFor(string $mobile): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE recipient = %s',
            [$mobile]
        );
    }

    private function countRows(string $shortTable): int
    {
        return (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($shortTable));
    }

    private function heldCountOf(int $slotId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function holdRowByToken(string $token): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE token = %s LIMIT 1',
            [$token]
        );

        return is_array($row) ? $row : null;
    }

    private function countHoldsForUser(int $userId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE holder_wp_user_id = %d',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function patientRowByMobile(int $clinicId, string $mobile): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d AND mobile = %s ORDER BY id DESC LIMIT 1',
            [$clinicId, $mobile]
        );

        return is_array($row) ? $row : null;
    }

    private function countPatientsForMobile(int $clinicId, string $mobile): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d AND mobile = %s',
            [$clinicId, $mobile]
        );
    }

    private function countPatientLinks(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    private function countPatientLinksForUser(int $userId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d',
            [$userId]
        );
    }

    private function countAppointmentsForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE patient_id = %d',
            [$patientId]
        );
    }

    /**
     * Shared post-failure invariants for the new-Patient name gate: nothing
     * is created for the booking mobile and the Hold stays active/retryable
     * with its capacity still held.
     */
    private function assertHoldUntouchedAndNothingCreated(int $slotId, string $holdToken, string $mobile): void
    {
        self::assertSame(0, $this->countPatientsForMobile($this->clinicA, $mobile), 'A failed validation creates no Patient.');
        self::assertSame(0, $this->countRows('cpms_appointments'), 'A failed validation creates no Appointment.');

        $holdRow = $this->holdRowByToken($holdToken);
        self::assertNotNull($holdRow);
        self::assertSame('active', (string) $holdRow['status'], 'A failed validation leaves the Hold active/retryable.');
        self::assertSame(1, $this->heldCountOf($slotId), 'A failed validation keeps the held capacity (no drift).');
    }

    // =================================================================
    // Misc helpers
    // =================================================================

    private function ymdDaysAhead(int $days): string
    {
        return gmdate('Y-m-d', time() + $days * 86400);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function setRole(int $userId, string $role): void
    {
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'precondition: WP user exists.');
        $user->set_role($role);
    }
}

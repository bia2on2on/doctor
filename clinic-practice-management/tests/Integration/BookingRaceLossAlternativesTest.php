<?php

/**
 * Phase 7 Slice 1 / FR-4.6 — Booking race-loss alternatives — RED ONLY (test-first evidence).
 *
 * SRS FR-4.6: "اگر Slot بین انتخاب و تأیید پر شود: پیام واضح + نمایش Slotهای نزدیک خالی."
 *
 * OWNER-ISSUED product policy under test (not original SRS wording) — additive
 * structured error data on the CLINIC_SLOT_TAKEN envelope:
 *  - When a booking loses with CLINIC_SLOT_TAKEN (HTTP 409), suggest up to 5
 *    eligible FREE alternatives:
 *      - same local date as the persisted losing slot;
 *      - same Location as the persisted losing slot;
 *      - established deterministic availability ordering preserved:
 *        slot_date ASC, slot_time ASC, id ASC;
 *      - empty list when no eligible alternative exists;
 *      - CLINIC_SLOT_TAKEN code, HTTP 409 and existing message semantics stay unchanged;
 *      - alternatives are additive data only — no other response field is added.
 *  - Response key: `nearby_slots` — no established key exists on main
 *    (grep: zero hits), so FR-4.6 terminology / prior contract clarification applies.
 *  - Alternative entries mirror the already-established availability entry shape:
 *    {time, capacity_left, duration_min, slot_id, location_id, date} — no new representation.
 *  - Trusted authority: clinic_id, clinician_id, location_id and local date are
 *    derived ONLY from the persisted losing slot row; raw request IDs are
 *    selectors, never authority; no fallback to another Clinic/Location.
 *
 * Fixtures use a dedicated Clinic (ID floor 626xx, AD-13: no clinic_id=1 hardcode).
 *
 * THIS FILE MUST STAY RED on main 4b322d9: the intended failure of every
 * RED scenario is specifically the absence (or wrong content) of `nearby_slots`
 * in the BookingException data. Envelope, message and fixture assertions must
 * pass first — only the nearby_slots assertion may fail.
 *
 * Classification legend (repo convention):
 *  EXPECTED RED = valid repro | A = regression by current work |
 *  B = pre-existing outside target | C = infra/env | D = test-infra defect
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class BookingRaceLossAlternativesTest extends WP_UnitTestCase
{
    private const FX_ORG_ID = 62600;
    private const FX_CLINIC_ID = 62601;
    private const FX_LOC_MAIN_ID = 62610; // primary Location — owns the losing slot
    private const FX_LOC_OTHER_ID = 62611; // different Location — isolation witness
    private const FX_CLINICIAN_ID = 62620;

    private const LOCATION_TZ = 'Asia/Tehran';

    private int $winnerUserId = 0; // wins the race (holds the capacity)
    private int $loserUserId = 0;  // loses the race (receives CLINIC_SLOT_TAKEN + alternatives)

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);
        SystemClinicResolver::flush();

        // Booking window settings (same values as every booking test on main;
        // service code defaults are identical — set explicitly for determinism)
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);
        App::settings()->set('booking.hold_ttl_sec', 600);
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);
        Settings::flushCache();

        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->purgeFixture();
        parent::tearDown();
    }

    // =================================================================
    // Scenario A — hold() race/capacity loss
    // =================================================================

    /**
     * REAL product loss path: SlotRepository::atomicHold() finds no free capacity
     * (capacity=1, held by the winner) → BookingService::hold() throws
     * CLINIC_SLOT_TAKEN / 409 inside its transaction. The envelope must gain an
     * additive `nearby_slots` list of up to 5 eligible free alternatives on the
     * SAME local date and SAME Location as the persisted losing slot, ordered by
     * slot_date ASC, slot_time ASC, id ASC.
     *
     * Isolation witnesses (must be absent): same date + different Location, and
     * next local date + same Location.
     */
    public function testHoldRaceLossSuggestsUpToFiveSameDateSameLocationFreeAlternatives(): void
    {
        global $wpdb;
        $db = App::db();

        $losingDate = gmdate('Y-m-d', time() + 4 * 86400);
        $nextDate = gmdate('Y-m-d', time() + 5 * 86400);

        // Persisted losing slot — primary Location, capacity 1.
        $losingSlotId = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '10:00:00', 1);

        // Six eligible free alternatives — same local date, SAME Location —
        // deliberately inserted out of chronological order to prove the
        // established deterministic availability ordering (date, time, id).
        $sixthBeyondCap = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '13:00:00', 1);
        $alt0930 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '09:30:00', 1);
        $alt1100 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '11:00:00', 1);
        $alt1200 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '12:00:00', 1);
        $alt1030 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '10:30:00', 1);
        $alt0800 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '08:00:00', 1);

        // Isolation witnesses — free, but outside the trusted losing-slot scope.
        $otherLocationWitness = $this->insertSlot(self::FX_LOC_OTHER_ID, $losingDate, '15:00:00', 1);
        $nextDateWitness = $this->insertSlot(self::FX_LOC_MAIN_ID, $nextDate, '09:00:00', 1);

        // ---- fixture materialization must be asserted (RED validity precondition)
        // Note: at this instant the losing slot itself still counts as free
        // (winner has not held yet); total persisted rows are asserted here,
        // trusted-scope eligibility is asserted below at loss time.
        self::assertSame(
            9,
            $this->countSlotsForClinic(),
            'precondition: 6 alternatives + losing slot + 2 isolation witnesses persisted'
        );

        // ---- real winner hold consumes the only capacity unit
        $winnerHold = App::bookingService()->hold(
            $this->winnerUserId,
            self::FX_CLINICIAN_ID,
            $losingDate,
            '10:00:00',
            $losingSlotId
        );
        self::assertNotEmpty($winnerHold['hold_token'], 'positive precondition: winner hold succeeded');
        $losingRow = $this->slotRow($losingSlotId);
        self::assertSame(1, (int) $losingRow['held_count'], 'precondition: losing slot is at capacity (held)');

        // ---- trusted-scope eligibility AT LOSS TIME (winner holds the unit):
        // exactly six free eligible same-date/same-Location alternatives remain.
        self::assertSame(
            6,
            $this->countFreeSlotsAt(self::FX_LOC_MAIN_ID, $losingDate),
            'precondition: exactly six free eligible same-date/same-Location alternatives at loss time'
        );
        self::assertSame(
            1,
            $this->countFreeSlotsAt(self::FX_LOC_OTHER_ID, $losingDate),
            'precondition: different-Location witness is free on the losing date'
        );
        self::assertSame(
            1,
            $this->countFreeSlotsAt(self::FX_LOC_MAIN_ID, $nextDate),
            'precondition: next-local-date witness is free at the same Location'
        );

        // ---- real BookingService::hold() failure path for the loser
        try {
            App::bookingService()->hold(
                $this->loserUserId,
                self::FX_CLINICIAN_ID,
                $losingDate,
                '10:00:00',
                $losingSlotId
            );
            self::fail('Expected CLINIC_SLOT_TAKEN from the real hold() loss path');
        } catch (BookingException $e) {
            // ---- existing envelope semantics must stay unchanged
            self::assertSame('CLINIC_SLOT_TAKEN', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertSame('اسلات در لحظه انتخاب پر شد — اسلات دیگری انتخاب کنید', $e->getMessage());

            // ---- real-path evidence: transaction rolled back, no loser hold row
            $activeHolds = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $db->table('cpms_slot_holds') . ' WHERE slot_id = %d AND status = %s',
                    [$losingSlotId, 'active']
                )
            );
            self::assertSame(1, $activeHolds, 'only the winner hold exists — loser transaction rolled back');
            self::assertSame(1, (int) $this->slotRow($losingSlotId)['held_count'], 'no capacity leak after rollback');

            // ---- FR-4.6 additive policy (RED failure point)
            self::assertArrayHasKey(
                'nearby_slots',
                $e->data,
                'FR-4.6: CLINIC_SLOT_TAKEN envelope must carry additive nearby_slots alternatives'
            );
            $nearby = $e->data['nearby_slots'];
            self::assertIsArray($nearby);
            self::assertCount(5, $nearby, 'alternatives are capped at 5 (six eligible existed)');

            $expected = [
                ['time' => '08:00', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt0800, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
                ['time' => '09:30', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt0930, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
                ['time' => '10:30', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt1030, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
                ['time' => '11:00', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt1100, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
                ['time' => '12:00', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt1200, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
            ];
            self::assertSame(
                $expected,
                $nearby,
                'entries mirror the established availability shape, ordered slot_date ASC, slot_time ASC, id ASC, capped at 5'
            );

            // ---- isolation witnesses are explicitly absent
            $suggestedIds = array_column($nearby, 'slot_id');
            self::assertNotContains($otherLocationWitness, $suggestedIds, 'different-Location witness must be absent');
            self::assertNotContains($nextDateWitness, $suggestedIds, 'next-local-date witness must be absent');
            self::assertNotContains($sixthBeyondCap, $suggestedIds, '7th-ranked alternative must be absent (cap=5)');
            self::assertNotContains($losingSlotId, $suggestedIds, 'the full losing slot itself must not be suggested');
        }
    }

    // =================================================================
    // Scenario B — confirm() FINAL atomicClaim loss (real confirm path)
    // =================================================================

    /**
     * REAL product loss path — no exception is fabricated and no service is
     * mocked: a legitimate hold exists (created by the real hold()); the loser
     * calls the real BookingService::confirm(); the final transaction reaches
     * SlotRepository::atomicClaim() whose `held_count > 0` guard fails, and
     * confirm() throws CLINIC_SLOT_TAKEN / 409 at the real product failure site.
     *
     * Deterministic race-loss state: the hold's capacity unit is consumed
     * outside confirm's transaction (the expiry racer released the slot AFTER
     * confirm passed its expires_at check but BEFORE the final claim). That
     * transient state (active hold + held_count=0) is exactly what the real
     * confirm-vs-expiry race produces and is the only state in which the
     * atomicClaim guard fails; it is materialized here with one direct row
     * update, consistent with the repository's state machine
     * (claim = held_count-1/booked_count+1 WHERE held_count > 0).
     *
     * The pinned Persian message ('اسلات در لحظه نهایی پر شد') is unique to the
     * atomicClaim site — proving the exact intended failure line was reached.
     *
     * The same owner policy is then asserted from the trusted persisted losing
     * slot (clinic/clinician/location/local-date of the slot row behind the hold).
     */
    public function testConfirmFinalAtomicClaimLossSuggestsAlternativesFromPersistedLosingSlot(): void
    {
        global $wpdb;
        $db = App::db();

        $losingDate = gmdate('Y-m-d', time() + 4 * 86400);
        $nextDate = gmdate('Y-m-d', time() + 5 * 86400);

        // Persisted losing slot — primary Location, capacity 1.
        $losingSlotId = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '10:00:00', 1);

        // Two eligible free alternatives — same local date, SAME Location.
        $alt0900 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '09:00:00', 1);
        $alt1130 = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '11:30:00', 1);

        // Isolation witnesses — free, but outside the trusted losing-slot scope.
        $otherLocationWitness = $this->insertSlot(self::FX_LOC_OTHER_ID, $losingDate, '15:00:00', 1);
        $nextDateWitness = $this->insertSlot(self::FX_LOC_MAIN_ID, $nextDate, '08:00:00', 1);

        // ---- legitimate hold via the REAL hold() path
        $hold = App::bookingService()->hold(
            $this->loserUserId,
            self::FX_CLINICIAN_ID,
            $losingDate,
            '10:00:00',
            $losingSlotId
        );
        self::assertNotEmpty($hold['hold_token'], 'precondition: legitimate hold exists');
        $holdRow = $this->holdRowByToken((string) $hold['hold_token']);
        self::assertNotNull($holdRow, 'precondition: hold row persisted');
        self::assertSame('active', (string) $holdRow['status'], 'precondition: hold is active');
        self::assertSame(1, (int) $this->slotRow($losingSlotId)['held_count'], 'precondition: hold reserved one capacity unit');

        // ---- deterministic race-loss state (see docblock): hold still active
        // and unexpired, but its capacity unit was consumed by the racer.
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $db->table('cpms_schedule_slots') . ' SET held_count = 0, updated_at = %s WHERE id = %d',
                [App::db()->nowUtcSql(), $losingSlotId]
            )
        );
        self::assertSame(0, (int) $this->slotRow($losingSlotId)['held_count'], 'precondition: atomicClaim guard state (held_count=0)');

        // ---- real BookingService::confirm() loss at the final atomicClaim site
        try {
            App::bookingService()->confirm((string) $hold['hold_token'], $this->loserUserId, null, $this->uuid());
            self::fail('Expected CLINIC_SLOT_TAKEN from the real confirm() atomicClaim loss path');
        } catch (BookingException $e) {
            // ---- existing envelope semantics must stay unchanged
            self::assertSame('CLINIC_SLOT_TAKEN', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertSame(
                'اسلات در لحظه نهایی پر شد',
                $e->getMessage(),
                'message unique to the atomicClaim failure site — proves the exact intended product failure line'
            );

            // ---- real-path evidence: only confirm()'s catch transitions the
            // active hold to 'released', and the claim never booked capacity.
            $afterRow = $this->holdRowByToken((string) $hold['hold_token']);
            self::assertSame('released', (string) $afterRow['status'], 'real confirm() catch ran (hold released)');
            self::assertSame(0, (int) $this->slotRow($losingSlotId)['booked_count'], 'no appointment capacity was booked');

            // ---- FR-4.6 additive policy from the trusted persisted losing slot
            self::assertArrayHasKey(
                'nearby_slots',
                $e->data,
                'FR-4.6: CLINIC_SLOT_TAKEN envelope must carry additive nearby_slots alternatives'
            );
            $nearby = $e->data['nearby_slots'];
            self::assertIsArray($nearby);
            $expected = [
                ['time' => '09:00', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt0900, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
                ['time' => '11:30', 'capacity_left' => 1, 'duration_min' => 20, 'slot_id' => $alt1130, 'location_id' => self::FX_LOC_MAIN_ID, 'date' => $losingDate],
            ];
            self::assertSame($expected, $nearby, 'same-date/same-Location alternatives in established availability ordering');

            // ---- isolation witnesses are explicitly absent
            $suggestedIds = array_column($nearby, 'slot_id');
            self::assertNotContains($otherLocationWitness, $suggestedIds, 'different-Location witness must be absent');
            self::assertNotContains($nextDateWitness, $suggestedIds, 'next-local-date witness must be absent');
        }
    }

    // =================================================================
    // Scenario C — no eligible alternative
    // =================================================================

    /**
     * Genuine CLINIC_SLOT_TAKEN path (same real hold() loss) with NO eligible
     * same-date/same-Location FREE alternative: the only other slot on that
     * date+Location is full (not free → not eligible); free rows exist only in
     * other scopes (different Location / next date). The envelope stays
     * CLINIC_SLOT_TAKEN / 409 and nearby_slots must be [].
     */
    public function testHoldRaceLossWithNoEligibleAlternativeYieldsEmptyAlternativesList(): void
    {
        $losingDate = gmdate('Y-m-d', time() + 4 * 86400);
        $nextDate = gmdate('Y-m-d', time() + 5 * 86400);

        // Persisted losing slot — primary Location, capacity 1.
        $losingSlotId = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '10:00:00', 1);

        // Same local date + same Location but FULL → exists yet NOT eligible.
        $fullSameDateSameLocation = $this->insertSlot(self::FX_LOC_MAIN_ID, $losingDate, '09:00:00', 1, 1);

        // Free witnesses in other scopes only.
        $otherLocationWitness = $this->insertSlot(self::FX_LOC_OTHER_ID, $losingDate, '15:00:00', 1);
        $nextDateWitness = $this->insertSlot(self::FX_LOC_MAIN_ID, $nextDate, '09:00:00', 1);

        // ---- real winner hold consumes the only capacity unit
        $winnerHold = App::bookingService()->hold(
            $this->winnerUserId,
            self::FX_CLINICIAN_ID,
            $losingDate,
            '10:00:00',
            $losingSlotId
        );
        self::assertNotEmpty($winnerHold['hold_token'], 'positive precondition: winner hold succeeded');

        // ---- fixture materialization AT LOSS TIME: zero eligible free rows in
        // trusted scope (the losing slot is now held; the 09:00 slot is full).
        self::assertSame(
            0,
            $this->countFreeSlotsAt(self::FX_LOC_MAIN_ID, $losingDate),
            'precondition: no eligible free same-date/same-Location alternative exists at loss time'
        );

        // ---- real BookingService::hold() failure path for the loser
        try {
            App::bookingService()->hold(
                $this->loserUserId,
                self::FX_CLINICIAN_ID,
                $losingDate,
                '10:00:00',
                $losingSlotId
            );
            self::fail('Expected CLINIC_SLOT_TAKEN from the real hold() loss path');
        } catch (BookingException $e) {
            // ---- envelope remains unchanged
            self::assertSame('CLINIC_SLOT_TAKEN', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertSame('اسلات در لحظه انتخاب پر شد — اسلات دیگری انتخاب کنید', $e->getMessage());

            // ---- FR-4.6: empty alternatives list when none are eligible (RED failure point)
            self::assertArrayHasKey(
                'nearby_slots',
                $e->data,
                'FR-4.6: nearby_slots key must exist even when no alternative is eligible'
            );
            self::assertSame([], $e->data['nearby_slots'], 'no eligible alternative → empty list (full slot is not eligible)');
            self::assertNotContains($fullSameDateSameLocation, [], 'full same-date/same-Location slot must not be suggested');
            self::assertNotContains($otherLocationWitness, [], 'different-Location witness must be absent');
            self::assertNotContains($nextDateWitness, [], 'next-local-date witness must be absent');
        }
    }

    // =================================================================
    // Positive controls — existing behavior stays unchanged
    // =================================================================

    /**
     * Existing successful hold→confirm flow is untouched by FR-4.6: the same
     * fixture harness that produces the RED scenarios also produces the
     * historical green outcome (hold view, confirm view, counters, single
     * appointment row) — all pinned unchanged.
     */
    public function testPositiveControlSuccessfulHoldAndConfirmRemainUnchanged(): void
    {
        global $wpdb;
        $db = App::db();

        $date = gmdate('Y-m-d', time() + 4 * 86400);
        $slotId = $this->insertSlot(self::FX_LOC_MAIN_ID, $date, '10:00:00', 1);

        $hold = App::bookingService()->hold($this->winnerUserId, self::FX_CLINICIAN_ID, $date, '10:00:00', $slotId);
        self::assertNotEmpty($hold['hold_token']);
        self::assertSame($slotId, (int) $hold['slot']['slot_id'], 'hold view unchanged');

        $view = App::bookingService()->confirm((string) $hold['hold_token'], $this->winnerUserId, null, $this->uuid());
        self::assertSame('confirmed', $view['status']);
        self::assertNotEmpty($view['reference_code']);
        self::assertGreaterThan(0, (int) $view['appointment_id']);

        $slotRow = $this->slotRow($slotId);
        self::assertSame(1, (int) $slotRow['booked_count'], 'confirm booked exactly one unit');
        self::assertSame(0, (int) $slotRow['held_count'], 'hold converted cleanly');

        $apptCount = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments') . ' WHERE slot_id = %d', [$slotId])
        );
        self::assertSame(1, $apptCount, 'exactly one appointment created');
    }

    /**
     * Replay semantics are exercised by the confirm() fixture (Idempotency-Key);
     * pin them unchanged: same key → origin response, no second appointment.
     */
    public function testPositiveControlConfirmIdempotentReplayRemainsUnchanged(): void
    {
        global $wpdb;
        $db = App::db();

        $date = gmdate('Y-m-d', time() + 4 * 86400);
        $slotId = $this->insertSlot(self::FX_LOC_MAIN_ID, $date, '10:00:00', 1);

        $hold = App::bookingService()->hold($this->winnerUserId, self::FX_CLINICIAN_ID, $date, '10:00:00', $slotId);
        $key = $this->uuid();

        $first = App::bookingService()->confirm((string) $hold['hold_token'], $this->winnerUserId, null, $key);
        $second = App::bookingService()->confirm((string) $hold['hold_token'], $this->winnerUserId, null, $key);

        self::assertSame($first['reference_code'], $second['reference_code'], 'replay returns the origin response');
        self::assertSame($first['appointment_id'], $second['appointment_id'], 'replay creates no second appointment');

        $apptCount = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments') . ' WHERE slot_id = %d', [$slotId])
        );
        self::assertSame(1, $apptCount, 'exactly one appointment after replay');
    }

    // =================================================================
    // Fixtures (ID floor 626xx — no clinic_id=1 hardcode, AD-13)
    // =================================================================

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        // Preconditions — no leftover from a previous run.
        self::assertNull(
            $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_ID)),
            'fixture precondition: no leftover clinic 62601'
        );
        self::assertNull(
            $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', self::FX_ORG_ID)),
            'fixture precondition: no leftover org 62600'
        );

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_organizations') . ' (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            [self::FX_ORG_ID, 'RaceLoss Org', 'raceloss-org-' . bin2hex(random_bytes(2)), 'active', $now, $now]
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinics') . ' (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            [self::FX_CLINIC_ID, self::FX_ORG_ID, 'RaceLoss Clinic', 'raceloss-clinic-' . bin2hex(random_bytes(2)), self::LOCATION_TZ, $now, $now]
        ));

        $locations = [
            [self::FX_LOC_MAIN_ID, 'raceloss-loc-main', 1],
            [self::FX_LOC_OTHER_ID, 'raceloss-loc-other', 0],
        ];
        foreach ($locations as [$locationId, $slug, $isPrimary]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $db->table('cpms_locations') . ' (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %d, 1, %s, %s)',
                [$locationId, self::FX_CLINIC_ID, 'RaceLoss ' . $slug, $slug . '-' . bin2hex(random_bytes(2)), self::LOCATION_TZ, $isPrimary, $now, $now]
            ));
        }

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinicians') . ' (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            [self::FX_CLINICIAN_ID, self::FX_CLINIC_ID, 'Dr RaceLoss', $now, $now]
        ));

        // Race winner and loser — patient identity resolved from the
        // @otp.cpms.local email convention (mobile = local part).
        $this->winnerUserId = $this->makePatientUser('09123140001', 'winner');
        $this->loserUserId = $this->makePatientUser('09123140002', 'loser');
    }

    private function makePatientUser(string $mobile, string $label): int
    {
        $userId = wp_create_user(
            'raceloss_' . $label . '_' . bin2hex(random_bytes(4)),
            'pass-12345',
            $mobile . '@otp.cpms.local'
        );
        self::assertNotWPError($userId, 'fixture: patient user created');
        self::assertGreaterThan(0, (int) $userId, 'fixture: patient user created');

        return (int) $userId;
    }

    /**
     * Insert a persisted slot row in the trusted fixture Clinic.
     *
     * @return int slot id
     */
    private function insertSlot(int $locationId, string $date, string $time, int $capacity = 1, int $heldCount = 0): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_schedule_slots') . '
                 (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, %d, %d, 0, %d, 1, %s, %s)',
            [
                self::FX_CLINIC_ID,
                $locationId,
                self::FX_CLINICIAN_ID,
                $date,
                $time,
                20,
                $capacity,
                $heldCount,
                $now,
                $now,
            ]
        ));
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'fixture: slot row persisted');

        return $slotId;
    }

    /**
     * Total persisted slot rows in the trusted fixture Clinic.
     */
    private function countSlotsForClinic(): int
    {
        global $wpdb;
        $db = App::db();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND clinician_id = %d',
                [self::FX_CLINIC_ID, self::FX_CLINICIAN_ID]
            )
        );
    }

    /**
     * Eligible free rows in the trusted scope: same Clinic + Clinician +
     * Location + local date, open, with real free capacity.
     */
    private function countFreeSlotsAt(int $locationId, string $date): int
    {
        global $wpdb;
        $db = App::db();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . '
                 WHERE clinic_id = %d AND clinician_id = %d AND location_id = %d AND slot_date = %s
                   AND is_open = 1 AND capacity - booked_count - held_count > 0',
                [self::FX_CLINIC_ID, self::FX_CLINICIAN_ID, $locationId, $date]
            )
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function slotRow(int $slotId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d', [$slotId]),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function holdRowByToken(string $token): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE token = %s', [$token]),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * FK-safe purge of every fixture row (floor 626xx), mirroring the
     * Phase2MultiLocationTemporalFixture purge conventions.
     */
    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();

        // 1) Leaf history + visits + appointments
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d)',
            [self::FX_CLINIC_ID]
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));

        // 2) Holds, slots, schedule (defensive rows)
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_exceptions') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));

        // 3) Notifications, SMS, idempotency (clinic-scoped keys of the losing flow)
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_idempotency_keys') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));

        // 4) Patient links — before patients AND clinics (FK RESTRICT both ways)
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $db->table('cpms_patient_user_links') . ' WHERE clinic_id = %d OR patient_id IN (SELECT id FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d)',
            [self::FX_CLINIC_ID, self::FX_CLINIC_ID]
        ));

        // 5) Clinician/membership link tables (defensive)
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $db->table('cpms_clinician_locations') . ' WHERE clinician_id = %d OR location_id IN (SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d)',
            [self::FX_CLINICIAN_ID, self::FX_CLINIC_ID]
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $db->table('cpms_membership_locations') . ' WHERE location_id IN (SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d)',
            [self::FX_CLINIC_ID]
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $db->table('cpms_membership_capabilities') . ' WHERE membership_id IN (SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d)',
            [self::FX_CLINIC_ID]
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));

        // 6) Clinical leaf tables (defensive; my flows create none of these)
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinical_notes') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_prescriptions') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_handwriting_documents') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));

        // 7) Patients and clinicians
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE id = %d OR clinic_id = %d', [self::FX_CLINICIAN_ID, self::FX_CLINIC_ID]));

        // 8) Locations, settings, clinic, org
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [self::FX_CLINIC_ID]));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', [self::FX_ORG_ID]));

        Settings::flushCache();
        App::resetScope();
    }
}

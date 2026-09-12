<?php

/**
 * Phase 2 — Multi-Location / Timezone RED evidence slice (TEST-FIRST, DO NOT FIX).
 *
 * Task: Temporal / Multi-Location RED — evidence only, no product fix, no schema, no migration, no API change.
 *
 * Contract under test (docs/architecture/phase2-tenant-context-remediation-design.md + ADR-0013):
 *  - SYSTEM TIMESTAMPS UTC (created_at, queue, OTP, audit)
 *  - OPERATIONAL SLOT/APPOINTMENT wall-clock of authoritative Location
 *  - conversion needs Location IANA timezone
 *  - Two-Clock rule INSTANT vs WALL-CLOCK
 *
 * This file MUST stay RED on current main (a93d20a) — it proves real gaps:
 *  A) SLOT IDENTITY: same Clinic/clinician/date/time different location_id allowed by schema (unique location_id,clinician_id,date,time) but SlotRepository::findByClinicianSlot WHERE clinic_id,clinician_id,date,time LIMIT 1 — no location_id.
 *  B) BOOKING WINDOW: BookingWindow::slotDateTime parses as UTC (new DateTimeZone('UTC')) — wall-clock bug.
 *  C) NO-SHOW periodic: VisitRepository::appointmentsPastGrace CONCAT(slot_date,' ',slot_time) < %s compares local wall-clock string to UTC instant.
 *  D) NO-SHOW lazy: VisitService::checkIn strtotime(slotStart + grace) < strtotime(now) — same UTC bias.
 *  E) REMINDER day-boundary: ApptReminderHandler::localToday via settings->clinicTimezone() not Location timezone.
 *
 * Valid EXPECTED RED requires: bootstrap succeeded, fixture succeeded, intended product path reached, failure on intended behavior/assertion.
 * Fixture/bootstrap/env errors are NOT valid RED (classified D/C).
 *
 * Classification:
 *  EXPECTED RED = valid repro
 *  A = regression by current work
 *  B = pre-existing outside target
 *  C = infra/env
 *  D = test-infra defect
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';
require_once __DIR__ . '/Fixtures/RecordingSmsProvider.php';

use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;
use ClinicCore\Tests\Integration\Fixtures\RecordingSmsProvider;

final class Phase2MultiLocationTemporalRedTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->buildMultiLocationTemporalFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeMultiLocationTemporalFixture();
        parent::tearDown();
    }

    // =================================================================
    // RED #1 — Slot identity: same Clinic/clinician/date/time different Location
    // =================================================================

    public function testSlotIdentitySameDateTimeDifferentLocationMustBeDistinguishable(): void
    {
        global $wpdb;
        $db = App::db();

        // Deterministic future date to avoid past-window policy
        $date = gmdate('Y-m-d', time() + 3 * 86400);
        $time = '10:00:00';

        // Two valid slots same Clinic/clinician/date/time different Location — allowed by schema unique (location_id,clinician_id,slot_date,slot_time)
        $slotA = $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $date, $time, 1, 20, 1);
        $slotB = $this->fxTInsertSlot(self::FX_T_LOC_B_ID, $date, $time, 1, 20, 1);

        self::assertGreaterThan(0, $slotA, 'fixture: slot A created');
        self::assertGreaterThan(0, $slotB, 'fixture: slot B created');
        self::assertNotSame($slotA, $slotB, 'fixture: two distinct slot rows');

        // Verify 2 rows exist matching clinic, clinician, date, time
        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s',
            self::FX_T_CLINIC_ID,
            self::FX_T_CLINICIAN_ID,
            $date,
            $time
        ));
        self::assertSame(2, $count, 'fixture: 2 slots with same clinic/clinician/date/time different location');

        // Product path: SlotRepository::findByClinicianSlot does NOT include location_id — returns arbitrary LIMIT 1
        $repo = new SlotRepository($db);
        $found = $repo->findByClinicianSlot(self::FX_T_CLINIC_ID, self::FX_T_CLINICIAN_ID, $date, $time);
        self::assertNotNull($found, 'product path reached: findByClinicianSlot returns a row');
        self::assertArrayHasKey('location_id', $found, 'product returns location_id but query did not filter by it');

        // The defect: system cannot identify exact Location/Slot — it silently selects arbitrary
        // Invariant: booking boundary must identify exact Location/Slot, must fail-closed if no distinction
        // We now exercise BookingService::hold which internally uses findByClinicianSlot

        // Need a patient WP user for hold
        $patientUserId = $this->makePatientUser();

        $booking = App::bookingService();
        try {
            $hold = $booking->hold($patientUserId, self::FX_T_CLINICIAN_ID, $date, $time);
            // If we reach here, booking succeeded with arbitrary location — this is the RED evidence
            // It should have failed closed because location ambiguous
            $heldSlotId = (int) ($hold['slot']['slot_id'] ?? $hold['slot']['id'] ?? 0);
            $heldLocation = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT location_id FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d',
                $heldSlotId
            ));
            // Prove ambiguity: there exists another slot with same date/time different location that was NOT selected
            $otherLocation = $heldLocation === self::FX_T_LOC_A_ID ? self::FX_T_LOC_B_ID : self::FX_T_LOC_A_ID;
            $otherExists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s AND location_id = %d',
                self::FX_T_CLINIC_ID,
                self::FX_T_CLINICIAN_ID,
                $date,
                $time,
                $otherLocation
            ));
            self::assertSame(1, $otherExists, 'other location slot still exists, proving arbitrary selection');

            self::fail(
                'EXPECTED RED #1 — SLOT IDENTITY AMBIGUITY: same Clinic/clinician/date/time different Location allowed (2 rows), ' .
                'but SlotRepository::findByClinicianSlot WHERE clinic_id,clinician_id,date,time LIMIT 1 returns arbitrary location_id=' . $heldLocation .
                ' without failing closed. Booking hold succeeded on slot_id=' . $heldSlotId . ' while other location=' . $otherLocation . ' also valid. ' .
                'Invariant: system must identify exact Location/Slot, must fail-closed if no distinction.'
            );
        } catch (\ClinicCore\Domain\Booking\BookingException $e) {
            // If product were fixed to fail-closed, it should throw with ambiguous code — then this test would PASS
            // Currently it does NOT throw, so we will not reach here — this is the expected RED path's PASS condition
            self::assertContains($e->errorCode, ['CLINIC_VALIDATION_FAILED', 'CLINIC_SLOT_AMBIGUOUS', 'CLINIC_SLOT_TAKEN'], 'fixed product should fail closed with explicit code');
        }
    }

    // =================================================================
    // RED #2 — BookingWindow wall-clock vs UTC
    // =================================================================

    public function testBookingWindowWallClockVsUtc(): void
    {
        // Location Asia/Tehran, wall-clock 2026-10-05 10:00
        // Derive via DateTimeZone at runtime — no hardcode offset
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $slotLocal = new DateTimeImmutable('2026-10-05 10:00:00', $tzTehran);
        $slotUtc = $slotLocal->setTimezone(new DateTimeZone('UTC'));

        // nowUtc = slot UTC instant minus 1.5h (90 minutes) — lead < 2h, should be policy violation
        $nowUtc = $slotUtc->sub(new \DateInterval('PT90M'));
        $minLeadHours = 2;
        $maxFutureDays = 60;

        // Product path: BookingWindow::checkRequest interprets slot as UTC (new DateTimeZone('UTC'))
        // So it will compute slotDateTime = 2026-10-05 10:00 UTC, now = 05:00 UTC (if Tehran +3:30, slot 10:00 Tehran = 06:30 UTC, now 05:00 UTC)
        // Lead = 5h >2h => product returns null (allowed) — WRONG
        // Correct: slot is 06:30 UTC, now 05:00 UTC, lead 1.5h <2h => should return CLINIC_POLICY_VIOLATION

        // T1 GREEN: use Location-aware check (Two-Clock) — slot wall-clock in Tehran TZ -> UTC instant
        $result = BookingWindow::checkRequestWithTimezone(
            $slotLocal->format('Y-m-d'),
            $slotLocal->format('H:i:s'),
            $tzTehran,
            $nowUtc,
            $minLeadHours,
            $maxFutureDays
        );

        // Assert via DateTimeZone-derived expectation
        $expectedLeadViolation = $slotUtc < $nowUtc->add(new \DateInterval('PT' . $minLeadHours . 'H'));
        self::assertTrue($expectedLeadViolation, 'fixture: derived via DateTimeZone, lead is indeed < minLead (1.5h < 2h)');

        if ($result === null) {
            self::fail(
                'EXPECTED RED #2 — BOOKING WINDOW WALL-CLOCK BUG: slot ' . $slotLocal->format('Y-m-d H:i:s') . ' Asia/Tehran = ' .
                $slotUtc->format('Y-m-d H:i:s') . 'Z, nowUtc=' . $nowUtc->format('Y-m-d H:i:s') . 'Z, minLead=2h, lead=1.5h should be CLINIC_POLICY_VIOLATION, ' .
                'but BookingWindow::slotDateTime parses as UTC (new DateTimeZone(\'UTC\')) and returned null (allowed). ' .
                'Invariant: BookingWindow must evaluate against actual UTC instant of Location-local slot.'
            );
        }

        self::assertSame(BookingWindow::CODE_POLICY, $result, 'fixed product should return POLICY');
    }

    // =================================================================
    // RED #3 — Premature no-show periodic processNoShows — T2 GREEN after fix
    // =================================================================

    public function testPrematureNoShowPeriodic(): void
    {
        global $wpdb;
        $db = App::db();

        // Use Location America/New_York (west) — local lags behind UTC
        $tzNY = new DateTimeZone('America/New_York');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowLocalNY = $nowUtc->setTimezone($tzNY);
        // Appointment local = nowLocalNY +1h (future in NY)
        $apptLocal = $nowLocalNY->add(new \DateInterval('PT1H'));
        $apptUtc = $apptLocal->setTimezone(new DateTimeZone('UTC'));

        $slotDate = $apptLocal->format('Y-m-d');
        $slotTime = $apptLocal->format('H:i:s');

        // Grace from settings = 30 (per-Clinic via SettingsFactory)
        $graceMinutes = 30;
        $before = $nowUtc->sub(new \DateInterval('PT' . $graceMinutes . 'M'))->format('Y-m-d H:i:s');

        // Invariant: appointment must never become no_show before Location-local start+grace
        $apptStartPlusGraceUtc = $apptUtc->add(new \DateInterval('PT' . $graceMinutes . 'M'));
        $shouldBeNoShow = $nowUtc >= $apptStartPlusGraceUtc;
        self::assertFalse($shouldBeNoShow, 'fixture: appointment Location-local start+grace is future vs nowUtc — must NOT be no_show');

        // Insert confirmed appointment with that slot_date/time in NY location, no active_visit_id
        $apptId = $this->fxTInsertAppointment(self::FX_T_LOC_C_ID, $slotDate, $slotTime, 'confirmed');
        self::assertGreaterThan(0, $apptId, 'fixture: appointment created');

        // T2: VisitRepository now Location-aware — should NOT list premature
        $visitRepo = new VisitRepository($db);
        $past = $visitRepo->appointmentsPastGrace($before, 100);
        $ids = array_map(fn($r) => (int) $r['id'], $past);
        $isPrematurelyListed = in_array($apptId, $ids, true);

        // After T2 fix, premature appointment must NOT be listed
        self::assertFalse($isPrematurelyListed, 'T2 fix: appointment with Location-local start+grace future must NOT be listed in appointmentsPastGrace (was premature before)');

        // Exercise real periodic path
        $visitService = App::visitService();
        $processed = $visitService->processNoShows();

        // Check that our appointment remains confirmed, not no_show
        $status = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d',
            $apptId
        ));
        $noShowAt = $wpdb->get_var($wpdb->prepare(
            'SELECT no_show_at FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d',
            $apptId
        ));

        self::assertSame('confirmed', $status, 'T2 fix: future Location-local appointment must remain confirmed after processNoShows');
        self::assertTrue($noShowAt === null || $noShowAt === '', 'T2 fix: no_show_at must remain null for future appointment');

        // Positive control: actually overdue appointment should still become no_show
        $overdueLocal = $nowLocalNY->sub(new DateInterval('PT2H')); // 2h ago local
        $overdueDate = $overdueLocal->format('Y-m-d');
        $overdueTime = $overdueLocal->format('H:i:s');
        $overdueApptId = $this->fxTInsertAppointment(self::FX_T_LOC_C_ID, $overdueDate, $overdueTime, 'confirmed');
        self::assertGreaterThan(0, $overdueApptId, 'control: overdue appointment created');

        $processed2 = $visitService->processNoShows();
        $overdueStatus = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d',
            $overdueApptId
        ));
        self::assertSame('no_show', $overdueStatus, 'positive control: actually overdue appointment must become no_show (fix does not disable all no-show)');
    }

    // =================================================================
    // RED #4 — Lazy / check-in consistency
    // =================================================================

    // =================================================================
    // RED #4 — Lazy / check-in consistency — T2 GREEN after fix
    // =================================================================

    public function testLazyCheckInNoShowConsistency(): void
    {
        global $wpdb;
        $db = App::db();

        $tzNY = new DateTimeZone('America/New_York');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowLocalNY = $nowUtc->setTimezone($tzNY);
        $apptLocal = $nowLocalNY->add(new \DateInterval('PT1H')); // future local
        $apptUtc = $apptLocal->setTimezone(new DateTimeZone('UTC'));

        $slotDate = $apptLocal->format('Y-m-d');
        $slotTime = $apptLocal->format('H:i:s');

        $grace = 30;
        $apptStartPlusGraceUtc = $apptUtc->add(new \DateInterval('PT' . $grace . 'M'));
        self::assertTrue($nowUtc < $apptStartPlusGraceUtc, 'fixture: appointment start+grace future');

        $apptId = $this->fxTInsertAppointment(self::FX_T_LOC_C_ID, $slotDate, $slotTime, 'confirmed');
        self::assertGreaterThan(0, $apptId, 'fixture: appointment for lazy check');

        // Need secretary user with membership for this clinic
        $secretaryId = $this->makeSecretaryUser(self::FX_T_CLINIC_ID);

        $visitService = App::visitService();

        try {
            $visit = $visitService->checkIn($secretaryId, $this->fxTPatient, $apptId, []);
            // T2 fix: lazy path must use Location timezone + per-Clinic grace, same as periodic
            $source = $visit['source'] ?? '';
            $apptStatus = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d',
                $apptId
            ));

            // After fix, future Location-local appointment must be scheduled, not walk_in/no_show
            self::assertSame('scheduled', $source, 'T2 fix: future Location-local appointment check-in must be scheduled, not walk_in');
            self::assertTrue($apptStatus !== 'no_show', 'T2 fix: appointment must NOT be no_show after future check-in, got ' . $apptStatus);

            // Consistency: periodic path must also NOT list it as past grace
            $before = $nowUtc->sub(new \DateInterval('PT' . $grace . 'M'))->format('Y-m-d H:i:s');
            $visitRepo = new VisitRepository($db);
            $past = $visitRepo->appointmentsPastGrace($before, 100);
            $ids = array_map(fn($r) => (int) $r['id'], $past);
            self::assertFalse(in_array($apptId, $ids, true), 'T2 fix: periodic and lazy must agree — future appointment must NOT be in pastGrace');

        } catch (\ClinicCore\Domain\Visits\VisitException $e) {
            self::fail('After T2 fix, checkIn should succeed as scheduled, but threw VisitException: ' . $e->getMessage());
        }

        // Positive control: overdue appointment check-in should be walk_in + no_show (ER-06)
        $overdueLocal = $nowLocalNY->sub(new \DateInterval('PT3H'));
        $overdueDate = $overdueLocal->format('Y-m-d');
        $overdueTime = $overdueLocal->format('H:i:s');
        $overdueApptId = $this->fxTInsertAppointment(self::FX_T_LOC_C_ID, $overdueDate, $overdueTime, 'confirmed');
        $secretaryId2 = $this->makeSecretaryUser(self::FX_T_CLINIC_ID);
        $visit2 = $visitService->checkIn($secretaryId2, $this->fxTPatient, $overdueApptId, []);
        self::assertSame('walk_in', $visit2['source'] ?? '', 'positive control: overdue appointment check-in must be walk_in (ER-06)');
    }

    // =================================================================
    // RED #5 — Reminder day-boundary multi-location

        public function testReminderDayBoundaryMultiLocation(): void
    {
        global $wpdb;
        $db = App::db();

        // Two Locations same Clinic distinct IANA: Tehran and Berlin
        // At real now (2026-09-12 20:51 UTC), Tehran is 2026-09-13 00:21 next day, Berlin is 2026-09-12 22:51 same day — different local dates
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $tzBerlin = new DateTimeZone('Europe/Berlin');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $todayTehran = $nowUtc->setTimezone($tzTehran)->format('Y-m-d');
        $todayBerlin = $nowUtc->setTimezone($tzBerlin)->format('Y-m-d');
        $tomorrowTehran = (new DateTimeImmutable($todayTehran, $tzTehran))->add(new \DateInterval('P1D'))->format('Y-m-d');
        $tomorrowBerlin = (new DateTimeImmutable($todayBerlin, $tzBerlin))->add(new \DateInterval('P1D'))->format('Y-m-d');

        // If dates are same at this UTC hour, force a UTC instant where they differ: 21:00 UTC => Tehran 00:30 next day, Berlin 23:00 same day
        if ($todayTehran === $todayBerlin) {
            $forcedUtc = new DateTimeImmutable('2026-09-12 21:00:00', new DateTimeZone('UTC'));
            $todayTehran = $forcedUtc->setTimezone($tzTehran)->format('Y-m-d'); // 2026-09-13
            $todayBerlin = $forcedUtc->setTimezone($tzBerlin)->format('Y-m-d'); // 2026-09-12
            $tomorrowTehran = (new DateTimeImmutable($todayTehran, $tzTehran))->add(new \DateInterval('P1D'))->format('Y-m-d');
            $tomorrowBerlin = (new DateTimeImmutable($todayBerlin, $tzBerlin))->add(new \DateInterval('P1D'))->format('Y-m-d');
            $nowUtc = $forcedUtc;
        }

        self::assertNotSame($todayTehran, $todayBerlin, 'fixture: Tehran and Berlin have different local dates at chosen UTC instant ' . $nowUtc->format('c'));

        // Create confirmed appointments: one in Tehran with slot_date = todayTehran, one in Berlin with slot_date = todayBerlin
        $apptTehran = $this->fxTInsertAppointment(self::FX_T_LOC_A_ID, $todayTehran, '10:00:00', 'confirmed');
        $apptBerlin = $this->fxTInsertAppointment(self::FX_T_LOC_B_ID, $todayBerlin, '10:00:00', 'confirmed');

        self::assertGreaterThan(0, $apptTehran, 'fixture: Tehran appointment');
        self::assertGreaterThan(0, $apptBerlin, 'fixture: Berlin appointment');

        // Product path: ApptReminderHandler::localToday via settings->clinicTimezone() (Asia/Tehran) — clinic-level, not Location
        $settings = new Settings($db, self::FX_T_CLINIC_ID, App::audit());
        $clinicTz = $settings->clinicTimezone();
        self::assertSame('Asia/Tehran', $clinicTz, 'fixture: clinic timezone Tehran');

        $localTodayClinic = (new DateTimeImmutable('now', new DateTimeZone($clinicTz)))->format('Y-m-d');
        // At real now, localTodayClinic = todayTehran (since clinic tz Tehran)
        // So handler will query slot_date IN (todayTehran, tomorrowTehran) — Berlin appointment with todayBerlin (different) will NOT be included

        // Exercise real handler with RecordingSmsProvider to avoid real SMS
        // Setup recording provider
        $recorder = new RecordingSmsProvider();
        App::providers()->register($recorder);

        // Need SettingsFactory for SmsService? Use App::smsService() which uses SettingsFactory per clinic
        $smsService = App::smsService();
        $notificationService = App::notificationService();
        $opLogger = App::op();

        // Use Settings bound to our clinic (62201) — ApptReminderHandler uses injected Settings (clinic-level)
        $handlerSettings = new Settings($db, self::FX_T_CLINIC_ID, App::audit());

        $handler = new \ClinicCore\Application\Jobs\ApptReminderHandler(
            $db,
            $handlerSettings,
            $smsService,
            $notificationService,
            $opLogger
        );

        $remindedCount = $handler([]);

        // Check notifications table for our appointments
        $notifTehran = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND patient_id = %d AND dedupe_key LIKE %s',
            self::FX_T_CLINIC_ID,
            $this->fxTPatient,
            '%apt:' . $apptTehran . ':remind:%'
        ));
        $notifBerlin = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND patient_id = %d AND dedupe_key LIKE %s',
            self::FX_T_CLINIC_ID,
            $this->fxTPatient,
            '%apt:' . $apptBerlin . ':remind:%'
        ));

        // Invariant: today/tomorrow per Location timezone not Clinic
        // Both appointments are today in their own Location, so both should be reminded
        // But product uses clinic timezone only, so Berlin will be missed when Tehran and Berlin dates differ

        if ($notifBerlin === 0 && $notifTehran > 0) {
            self::fail(
                'EXPECTED RED #5 — REMINDER DAY-BOUNDARY: one UTC instant ' . $nowUtc->format('Y-m-d H:i:s') . 'Z = Tehran ' . $todayTehran . ' vs Berlin ' . $todayBerlin .
                ' different local dates via DateTimeZone. Appointments: Tehran id=' . $apptTehran . ' date=' . $todayTehran . ', Berlin id=' . $apptBerlin . ' date=' . $todayBerlin .
                ' both confirmed, both should be reminded per Location timezone, but ApptReminderHandler localToday via settings->clinicTimezone()=' . $clinicTz . ' = ' . $localTodayClinic .
                ' only queried (' . $todayTehran . ',' . $tomorrowTehran . ') — Berlin appointment not reminded (notifTehran=' . $notifTehran . ', notifBerlin=' . $notifBerlin . ', remindedCount=' . $remindedCount . '). ' .
                'Invariant: today/tomorrow per Location timezone not Clinic.'
            );
        }

        if ($notifTehran === 0 && $notifBerlin === 0) {
            // Both not reminded — maybe quiet hours or other, but still defect if one should be
            self::fail(
                'EXPECTED RED #5 — REMINDER DAY-BOUNDARY (both missed): Tehran date=' . $todayTehran . ' Berlin date=' . $todayBerlin .
                ' clinicToday=' . $localTodayClinic . ' — handler returned ' . $remindedCount . ', notifications 0/0, expected both per Location.'
            );
        }

        // If both reminded, then product would have been fixed to per-Location — then this test would PASS (UNEXPECTED PASS for now)
        self::assertSame(1, $notifTehran, 'Tehran appointment should be reminded');
        self::assertSame(1, $notifBerlin, 'Berlin appointment should be reminded per Location timezone — currently fails');
    }

    // =================================================================
    // Positive control — single practice
    // =================================================================

    public function testSinglePracticeRemainsFunctional(): void
    {
        global $wpdb;
        $db = App::db();

        $date = gmdate('Y-m-d', time() + 5 * 86400);
        $time = '11:00:00';
        $slotId = $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $date, $time, 2, 20, 1);
        self::assertGreaterThan(0, $slotId, 'control: slot created');

        $repo = new SlotRepository($db);
        $found = $repo->findByClinicianSlot(self::FX_T_CLINIC_ID, self::FX_T_CLINICIAN_ID, $date, $time);
        self::assertNotNull($found, 'control: findByClinicianSlot works for single location');
        self::assertSame(self::FX_T_LOC_A_ID, (int) $found['location_id'], 'control: location matches');

        $patientUserId = $this->makePatientUser();
        $booking = App::bookingService();
        $hold = $booking->hold($patientUserId, self::FX_T_CLINICIAN_ID, $date, $time);
        self::assertNotEmpty($hold['hold_token'], 'control: hold works');
    }

    // =================================================================
    // WP/PHP timezone guard — expose strtotime dependence
    // =================================================================

    public function testPhpDefaultTimezoneDoesNotAffectTemporalCalc(): void
    {
        $originalTz = date_default_timezone_get();
        try {
            // Temporarily change PHP default timezone to something else
            date_default_timezone_set('America/New_York');

            $tzTehran = new DateTimeZone('Asia/Tehran');
            $slotLocal = new DateTimeImmutable('2026-10-05 10:00:00', $tzTehran);
            $slotUtc = $slotLocal->setTimezone(new DateTimeZone('UTC'));
            $nowUtc = $slotUtc->sub(new \DateInterval('PT90M'));

            // T1 GREEN: Location-aware check must be independent of PHP default timezone
            $result = BookingWindow::checkRequestWithTimezone(
                $slotLocal->format('Y-m-d'),
                $slotLocal->format('H:i:s'),
                $tzTehran,
                $nowUtc,
                2,
                60
            );

            // With PHP default timezone changed, product still parses as UTC (hardcoded) — but strtotime elsewhere may break
            // This guard ensures temporal calc uses DateTimeZone explicitly, not strtotime default
            // Currently product returns null (allowed) even though should be POLICY — same as RED #2, but now under different default tz
            if ($result === null) {
                self::fail(
                    'EXPECTED RED — PHP TIMEZONE GUARD: default timezone changed to America/New_York, slot Asia/Tehran 2026-10-05 10:00 = ' .
                    $slotUtc->format('c') . ', nowUtc=' . $nowUtc->format('c') . ', lead 1.5h <2h should be POLICY, but got null (allowed). ' .
                    'BookingWindow uses UTC hardcoded, but other paths use strtotime which depends on default timezone — exposed.'
                );
            }

            self::assertSame(BookingWindow::CODE_POLICY, $result, 'with explicit DateTimeZone, result must be POLICY regardless of default tz');
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makePatientUser(): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $login = 'tmpatient_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role('cpms_patient');
        }
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_patient_user_links') . ' (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at) VALUES (%d, %d, %d, %s, 1, %s)',
            self::FX_T_CLINIC_ID,
            $this->fxTPatient,
            $userId,
            '09120000001',
            $now
        ));
        return $userId;
    }

    private function makeSecretaryUser(int $clinicId): int
    {
        $login = 'tsecretary_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role('cpms_secretary');
        }
        cpms_test_seed_membership($userId, $clinicId, 'cpms_secretary');
        return $userId;
    }
}

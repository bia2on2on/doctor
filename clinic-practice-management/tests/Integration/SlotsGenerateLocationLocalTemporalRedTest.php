<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\SystemClinicResolver;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * Phase 2 temporal slice — `slots.generate` clinical calendar frame.
 *
 * HISTORY: this test was originally written as a RED for the C-9 contract
 * (each Location's dates derived from that Location's own local calendar
 * "today"). That frame was later found to regress the committed M2 contract
 * (`SlotsGenerateM2WiringRedTest`): whenever the Location-local date differs
 * from the UTC date (e.g. Europe/Berlin 22:00–23:59 UTC), the whole generation
 * window shifts by one day and the M2 within-horizon UTC date (which then IS
 * the Location's own "today") is never generated. The handler was corrected to
 * anchor candidate dates to the UTC calendar date of the single frame-free
 * reference instant (explicit UTC date math — no strtotime/gmdate ambient
 * dependence).
 *
 * CURRENT contract verified here (corrected, post C-9-regression fix):
 *   - generated slot_dates = reference-instant UTC "today" +1..+horizon (EXACT
 *     set; today itself is never generated) for EVERY Location, regardless of
 *     the Location's IANA offset — the two >23h-apart Locations must produce
 *     the identical clinical calendar;
 *   - the ambient PHP default timezone must not influence generated slot_dates
 *     (all date arithmetic is on explicit UTC objects);
 *   - the Location's validated IANA zone keeps its fail-closed gate role.
 *
 * NOTE (fixture discriminating power under the corrected contract): the Clinic
 * timezone value 'UTC' is now indistinguishable from the corrected frame, so a
 * Clinic-TZ fallback is NOT observable with this fixture; the fixture still
 * distinguishes Location-offset frame leaks and ambient-timezone dependence.
 *
 * Fixture: ONE Clinic, TWO Locations with explicit IANA zones 25 hours apart
 * (Pacific/Kiritimati = UTC+14, Pacific/Pago_Pago = UTC-11). For any reference
 * instant their local calendar dates differ, and at least one of them always
 * differs from the UTC calendar date — so any frame leak (Location-local
 * anchor or ambient-timezone arithmetic) is observable.
 *
 * All IDs are dynamically allocated (insert_id). No fixed tenant IDs, no
 * reliance on Clinic 1, no current-WP-user dependency.
 */
final class SlotsGenerateLocationLocalTemporalRedTest extends WP_UnitTestCase
{
    private const TZ_AHEAD = 'Pacific/Kiritimati';  // UTC+14
    private const TZ_BEHIND = 'Pacific/Pago_Pago';  // UTC-11
    private const CLINIC_TZ = 'UTC';                // deliberately different from both Locations
    private const HORIZON = 3;

    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locAhead = 0;
    private int $locBehind = 0;
    private int $clinicianId = 0;

    private ?string $originalPhpTz = null;

    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach (['db','op','audit','jobs','rate','loginRateLimiter','idem','settingsFactory','migrations','dispatcher','providers','vault','smsService','licenseGate','visitService'] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        SystemClinicResolver::flush();
        \ClinicCore\Settings\Settings::flushCache();
        try {
            App::settingsFactory()->reset();
        } catch (\Throwable $e) {
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPhpTz = date_default_timezone_get();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->assertTimezonesAvailable();
        $this->buildFixture();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpTz !== null) {
            date_default_timezone_set($this->originalPhpTz);
        }
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    /**
     * Timezone-database availability is an ENVIRONMENT precondition, not a
     * product contract — a missing identifier must never be reported as a
     * product RED.
     */
    private function assertTimezonesAvailable(): void
    {
        $all = timezone_identifiers_list();
        foreach ([self::TZ_AHEAD, self::TZ_BEHIND] as $tz) {
            self::assertContains($tz, $all, "ENVIRONMENT precondition: PHP timezone database must provide {$tz}");
            $offset = (new DateTimeZone($tz))->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            self::assertIsInt($offset);
        }
        $ahead = (new DateTimeZone(self::TZ_AHEAD))->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $behind = (new DateTimeZone(self::TZ_BEHIND))->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        self::assertGreaterThan(
            23 * 3600,
            $ahead - $behind,
            'ENVIRONMENT precondition: the two Locations must be >23h apart so their local dates always differ'
        );
    }

    /**
     * Local calendar date of an instant in an explicit IANA zone.
     * Never uses the ambient PHP timezone.
     */
    private function localDate(DateTimeImmutable $utcInstant, string $tz): string
    {
        return $utcInstant->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    }

    /**
     * Expected covered dates for EVERY Location under the corrected contract:
     * offsets {1..horizon} inclusive from the reference instant's UTC "today".
     * Calendar arithmetic is anchored in UTC so it is pure Gregorian date math
     * with no DST policy invented and no ambient timezone involvement.
     *
     * @return list<string>
     */
    private function expectedDates(DateTimeImmutable $utcInstant, int $horizon): array
    {
        $anchor = new DateTimeImmutable(
            $utcInstant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d') . ' 00:00:00',
            new DateTimeZone('UTC')
        );
        $out = [];
        for ($day = 1; $day <= $horizon; $day++) {
            $out[] = $anchor->add(new DateInterval('P' . $day . 'D'))->format('Y-m-d');
        }

        return $out;
    }

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $suffix = bin2hex(random_bytes(4));

        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'TZ Org',
            'tz-org-' . $suffix,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'fixture: organization insert must succeed');
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'fixture: organization id allocated');

        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinics') . ' (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'TZ Clinic',
            'tz-clinic-' . $suffix,
            self::CLINIC_TZ,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'fixture: clinic insert must succeed');
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicId, 'fixture: clinic id dynamically allocated');

        // Two real Locations in the SAME Clinic, explicit distinct IANA zones.
        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicId,
            'Loc Ahead',
            'loc-ahead-' . $suffix,
            self::TZ_AHEAD,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'fixture: ahead location insert must succeed');
        $this->locAhead = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locAhead);

        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 0, 1, %s, %s)',
            $this->clinicId,
            'Loc Behind',
            'loc-behind-' . $suffix,
            self::TZ_BEHIND,
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'fixture: behind location insert must succeed');
        $this->locBehind = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locBehind);
        self::assertNotSame($this->locAhead, $this->locBehind, 'two distinct Locations');

        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicId,
            'Dr TZ',
            $now,
            $now
        ));
        self::assertNotFalse($ok, 'fixture: clinician insert must succeed');
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId);

        // One Schedule per Location per weekday, so EVERY covered calendar date
        // produces slots and the assertion measures the date frame only — not
        // weekday coverage. u_sched_slot = (clinic, location, clinician, dow, start_time).
        foreach ([[$this->locAhead, '09:00:00', '10:00:00'], [$this->locBehind, '11:00:00', '12:00:00']] as [$locId, $start, $end]) {
            for ($dow = 0; $dow <= 6; $dow++) {
                $ok = $wpdb->query($wpdb->prepare(
                    'INSERT INTO ' . $db->table('cpms_schedule') . '
                        (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time,
                         appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
                     VALUES (%d, %d, %d, %d, %s, %s, 30, 1, 1, %s, %s)',
                    $this->clinicId,
                    $locId,
                    $this->clinicianId,
                    $dow,
                    $start,
                    $end,
                    $now,
                    $now
                ));
                self::assertNotFalse($ok, "fixture: schedule insert must succeed (loc={$locId}, dow={$dow})");
            }
        }

        $scheduleCount = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule') . ' WHERE clinician_id = %d',
            $this->clinicianId
        ));
        self::assertSame(14, $scheduleCount, 'fixture: 7 weekday schedules at each of the 2 Locations');

        // Per-Clinic horizon (existing semantics, unchanged by this slice).
        \ClinicCore\Settings\Settings::flushCache();
        $settings = new \ClinicCore\Settings\Settings($db, $this->clinicId, App::audit());
        $settings->set('booking.max_future_days', self::HORIZON);
        \ClinicCore\Settings\Settings::flushCache();
        $check = (int) (new \ClinicCore\Settings\Settings($db, $this->clinicId, App::audit()))->get('booking.max_future_days', 30);
        self::assertSame(self::HORIZON, $check, 'fixture: per-Clinic horizon persisted');
        \ClinicCore\Settings\Settings::flushCache();
        $this->resetAppCaches();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->clinicianId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d', $this->clinicianId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_exceptions') . ' WHERE clinician_id = %d', $this->clinicianId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule') . ' WHERE clinician_id = %d', $this->clinicianId));
        }
        if ($this->clinicId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicId));
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN ("visits.no_show","slots.generate","holds.expire","cleanup.otp","cleanup.rate_limits","cleanup.idem","cleanup.oplog","handwriting.gc","notif.dispatch","appt.reminder","fu.reminder","license.refresh","backup.run","report.export","sms.send")');
    }

    private function purgeOwnSlots(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d', $this->clinicianId));
    }

    /**
     * Distinct generated slot_dates for one Location, ascending.
     *
     * @return list<string>
     */
    private function generatedDates(int $locationId): array
    {
        global $wpdb;
        $db = App::db();
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT slot_date FROM ' . $db->table('cpms_schedule_slots') . '
             WHERE clinician_id = %d AND location_id = %d ORDER BY slot_date',
            $this->clinicianId,
            $locationId
        ));

        return array_values(array_map('strval', (array) $rows));
    }

    /**
     * Runs the REAL production path: enqueue -> App::runTick -> production
     * dispatcher -> SlotsGenerateHandler -> persisted slots.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}  reference UTC instants before/after
     */
    private function runProductionSweep(): array
    {
        global $wpdb;
        $db = App::db();

        $this->purgeJobs();
        $this->purgeOwnSlots();
        $this->resetAppCaches();
        wp_set_current_user(0);

        $queue = App::jobs();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $jobId = $queue->enqueue('slots.generate', [], $before, 3, 1);
        self::assertGreaterThan(0, $jobId, 'job enqueued');

        $tickResult = App::runTick(20);
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($job, 'job row must exist');

        // The M-2 wiring contract is ALREADY fixed (PR #31). A failure here is a
        // wiring/Settings regression, NOT the temporal contract under test.
        self::assertSame(
            'success',
            (string) $job['status'],
            'PRECONDITION (PR #31 wiring): slots.generate must succeed under the real production path. '
                . 'last_error=' . (string) ($job['last_error'] ?? '') . ' tickResult=' . var_export($tickResult, true)
        );

        return [$before, $after];
    }

    /**
     * Guards the midnight race: under the corrected contract only the UTC
     * calendar date (the anchor) matters — if it moved while the production
     * sweep was running, the observation is inconclusive and must NOT be
     * reported as a product RED.
     */
    private function assertNoCalendarBoundaryCrossing(DateTimeImmutable $before, DateTimeImmutable $after): void
    {
        if ($this->localDate($before, 'UTC') !== $this->localDate($after, 'UTC')) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): UTC calendar date changed during execution — '
                . $this->localDate($before, 'UTC') . ' -> ' . $this->localDate($after, 'UTC')
            );
        }
    }

    /**
     * CONTRACT: every Location's generated calendar dates must be anchored to
     * the reference instant's UTC calendar date — NOT shifted by the Location's
     * own IANA offset, the Clinic timezone, or the ambient PHP timezone.
     */
    public function testGeneratedDatesUseUtcReferenceCalendarNotLocationOffset(): void
    {
        [$before, $after] = $this->runProductionSweep();
        $this->assertNoCalendarBoundaryCrossing($before, $after);

        $expected = $this->expectedDates($before, self::HORIZON);

        $actualAhead = $this->generatedDates($this->locAhead);
        $actualBehind = $this->generatedDates($this->locBehind);

        self::assertNotEmpty($actualAhead, 'Location(ahead) must have generated slots');
        self::assertNotEmpty($actualBehind, 'Location(behind) must have generated slots');

        $utcToday = $this->localDate($before, 'UTC');

        self::assertSame(
            $expected,
            $actualAhead,
            "CALENDAR-FRAME DEFECT (Location " . self::TZ_AHEAD . "): generated dates must be the UTC reference "
                . "today+1..+" . self::HORIZON . " regardless of the Location offset. UTC today=" . $utcToday
                . ' local today=' . $this->localDate($before, self::TZ_AHEAD)
                . ' expected=[' . implode(',', $expected) . '] actual=[' . implode(',', $actualAhead) . ']'
        );

        self::assertSame(
            $expected,
            $actualBehind,
            "CALENDAR-FRAME DEFECT (Location " . self::TZ_BEHIND . "): generated dates must be the UTC reference "
                . "today+1..+" . self::HORIZON . " regardless of the Location offset. UTC today=" . $utcToday
                . ' local today=' . $this->localDate($before, self::TZ_BEHIND)
                . ' expected=[' . implode(',', $expected) . '] actual=[' . implode(',', $actualBehind) . ']'
        );

        // The two Locations are >23h apart: any Location-offset frame leak makes
        // their clinical calendars differ.
        self::assertSame(
            $actualAhead,
            $actualBehind,
            'LOCATION-OFFSET LEAK: the 25h-apart Locations must produce the identical clinical calendar'
        );
    }

    /**
     * CONTRACT: the clinical calendar is anchored in explicit UTC, so the
     * ambient PHP default timezone must not influence generated slot_dates.
     *
     * Historically, `strtotime('<Y-m-d> +N days')` resolved midnight in the
     * ambient PHP timezone before `gmdate()` re-read it as UTC, so flipping the
     * ambient timezone shifted the generated dates. This test keeps guarding
     * against ambient-timezone dependence under the corrected contract.
     */
    public function testAmbientPhpTimezoneDoesNotChangeClinicalCalendar(): void
    {
        date_default_timezone_set(self::TZ_BEHIND);
        [$before1, $after1] = $this->runProductionSweep();
        $this->assertNoCalendarBoundaryCrossing($before1, $after1);
        $runBehindAhead = $this->generatedDates($this->locAhead);
        $runBehindBehind = $this->generatedDates($this->locBehind);

        date_default_timezone_set(self::TZ_AHEAD);
        [$before2, $after2] = $this->runProductionSweep();
        $this->assertNoCalendarBoundaryCrossing($before2, $after2);
        $runAheadAhead = $this->generatedDates($this->locAhead);
        $runAheadBehind = $this->generatedDates($this->locBehind);

        // Both runs must observe the same reference (UTC) calendar day, otherwise
        // the comparison is inconclusive rather than a product failure.
        if ($this->localDate($before1, 'UTC') !== $this->localDate($before2, 'UTC')) {
            self::markTestSkipped("INCONCLUSIVE (not a product RED): UTC calendar date changed between the two runs");
        }

        self::assertNotEmpty($runBehindAhead, 'run #1 must generate slots for Location(ahead)');
        self::assertNotEmpty($runBehindBehind, 'run #1 must generate slots for Location(behind)');

        self::assertSame(
            $runBehindAhead,
            $runAheadAhead,
            'AMBIENT PHP TIMEZONE LEAK (Location ' . self::TZ_AHEAD . '): generated dates changed when only the PHP '
                . 'default timezone changed. php=' . self::TZ_BEHIND . ' -> [' . implode(',', $runBehindAhead) . '] '
                . 'php=' . self::TZ_AHEAD . ' -> [' . implode(',', $runAheadAhead) . ']'
        );

        self::assertSame(
            $runBehindBehind,
            $runAheadBehind,
            'AMBIENT PHP TIMEZONE LEAK (Location ' . self::TZ_BEHIND . '): generated dates changed when only the PHP '
                . 'default timezone changed. php=' . self::TZ_BEHIND . ' -> [' . implode(',', $runBehindBehind) . '] '
                . 'php=' . self::TZ_AHEAD . ' -> [' . implode(',', $runAheadBehind) . ']'
        );
    }
}

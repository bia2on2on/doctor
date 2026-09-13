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
 * Phase 2 temporal slice — `slots.generate` must generate each Schedule's
 * calendar dates in the **authoritative Location's** local calendar frame.
 *
 * Classification: B — pre-existing product defect (documented as C-9).
 *
 * Pre-fix defect (`SlotsGenerateHandler`):
 *   $today = gmdate('Y-m-d');                                   // UTC frame for EVERY Location
 *   $date  = gmdate('Y-m-d', strtotime($today.' +'.$day.' days'));
 *   $dow   = toIranianDow((int) gmdate('w', strtotime($date)));
 * Both the generation date and the weekday are therefore derived from the UTC
 * calendar (and `strtotime()` on a date-only string additionally resolves
 * midnight in the **ambient PHP timezone**), regardless of the Location the
 * Schedule actually belongs to.
 *
 * PRESERVED product semantics (NOT changed by this slice):
 *   the loop covers offsets {1 .. horizon} INCLUSIVE relative to "today" —
 *   i.e. today+1 … today+horizon, and today itself is never generated.
 *   `horizon = N` therefore covers exactly N calendar dates. This slice changes
 *   ONLY the calendar **frame** in which "today" is determined; it does not
 *   change `booking.max_future_days` / `horizon_days` semantics.
 *
 * Fixture: ONE Clinic, TWO Locations with explicit IANA zones 25 hours apart
 * (Pacific/Kiritimati = UTC+14, Pacific/Pago_Pago = UTC-11). For any reference
 * instant their local calendar dates differ, and at least one of them always
 * differs from the UTC calendar date — so the UTC-framed implementation cannot
 * satisfy both Locations. Clinic timezone is deliberately a THIRD value (UTC)
 * so that any fallback to Clinic timezone is also observable.
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
     * Expected covered dates for a Location, derived from the CURRENT documented
     * loop semantics: offsets {1..horizon} inclusive from that Location's local
     * "today". Calendar arithmetic is anchored in UTC so it is pure Gregorian
     * date math with no DST policy invented.
     *
     * @return list<string>
     */
    private function expectedDates(DateTimeImmutable $utcInstant, string $tz, int $horizon): array
    {
        $anchor = new DateTimeImmutable($this->localDate($utcInstant, $tz) . ' 00:00:00', new DateTimeZone('UTC'));
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
     * Guards the midnight race: if the relevant local calendar boundary moved
     * while the production sweep was running, the observation is inconclusive
     * and must NOT be reported as a product RED.
     */
    private function assertNoCalendarBoundaryCrossing(DateTimeImmutable $before, DateTimeImmutable $after): void
    {
        foreach ([self::TZ_AHEAD, self::TZ_BEHIND, 'UTC'] as $tz) {
            if ($this->localDate($before, $tz) !== $this->localDate($after, $tz)) {
                self::markTestSkipped(
                    "INCONCLUSIVE (not a product RED): local calendar date in {$tz} changed during execution — "
                    . $this->localDate($before, $tz) . ' -> ' . $this->localDate($after, $tz)
                );
            }
        }
    }

    /**
     * CONTRACT: each Location's generated calendar dates must be derived from
     * that Location's own IANA timezone — not UTC, not the Clinic timezone.
     */
    public function testGenerationDatesUseAuthoritativeLocationLocalCalendar(): void
    {
        [$before, $after] = $this->runProductionSweep();
        $this->assertNoCalendarBoundaryCrossing($before, $after);

        $expectedAhead = $this->expectedDates($before, self::TZ_AHEAD, self::HORIZON);
        $expectedBehind = $this->expectedDates($before, self::TZ_BEHIND, self::HORIZON);
        self::assertNotSame(
            $expectedAhead,
            $expectedBehind,
            'fixture sanity: the two Locations must expect different calendar dates'
        );

        $actualAhead = $this->generatedDates($this->locAhead);
        $actualBehind = $this->generatedDates($this->locBehind);

        self::assertNotEmpty($actualAhead, 'Location(ahead) must have generated slots');
        self::assertNotEmpty($actualBehind, 'Location(behind) must have generated slots');

        $utcToday = $this->localDate($before, 'UTC');

        self::assertSame(
            $expectedAhead,
            $actualAhead,
            "LOCATION-LOCAL TEMPORAL DEFECT (Location " . self::TZ_AHEAD . "): generated dates must be that Location's "
                . "local today+1..+" . self::HORIZON . ". UTC today=" . $utcToday
                . ' local today=' . $this->localDate($before, self::TZ_AHEAD)
                . ' expected=[' . implode(',', $expectedAhead) . '] actual=[' . implode(',', $actualAhead) . ']'
        );

        self::assertSame(
            $expectedBehind,
            $actualBehind,
            "LOCATION-LOCAL TEMPORAL DEFECT (Location " . self::TZ_BEHIND . "): generated dates must be that Location's "
                . "local today+1..+" . self::HORIZON . ". UTC today=" . $utcToday
                . ' local today=' . $this->localDate($before, self::TZ_BEHIND)
                . ' expected=[' . implode(',', $expectedBehind) . '] actual=[' . implode(',', $actualBehind) . ']'
        );
    }

    /**
     * CONTRACT: the clinical calendar is a property of the Location, so the
     * ambient PHP default timezone must not influence generated slot_dates.
     *
     * Pre-fix, `strtotime('<Y-m-d> +N days')` resolves midnight in the ambient
     * PHP timezone before `gmdate()` re-reads it as UTC, so flipping the ambient
     * timezone shifts the generated dates.
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

        // Both runs must observe the same reference calendar day, otherwise the
        // comparison is inconclusive rather than a product failure.
        foreach ([self::TZ_AHEAD, self::TZ_BEHIND, 'UTC'] as $tz) {
            if ($this->localDate($before1, $tz) !== $this->localDate($before2, $tz)) {
                self::markTestSkipped("INCONCLUSIVE (not a product RED): {$tz} calendar date changed between the two runs");
            }
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

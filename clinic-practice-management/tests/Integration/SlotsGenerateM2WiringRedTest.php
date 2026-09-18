<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * M-2 slots.generate — RED 1: scope-neutral wiring + per-Clinic horizon isolation.
 *
 * Proves that production wiring for slots.generate must be scope-neutral and
 * horizon must be resolved per-Clinic, not from ambient Settings.
 *
 * Pre-fix defect: App::dispatcher() registers slots.generate with
 *   new SlotsGenerateHandler($db, App::settings(), $op)
 * which calls App::settings() → App::scope() → throws CLINIC_SCOPE_REQUIRED
 * in multi-Clinic no-Scope worker (recurring cron). With maxAttempts=1 the job
 * fails with FAILED, not SUCCESS.
 *
 * Expected RED (pre-fix): job status FAILED
 * Expected GREEN (post-fix): job status SUCCESS, horizon per-Clinic, fail-closed for
 * Settings failure of one Clinic without bleeding another Clinic's horizon.
 *
 * Uses EMPTY payload to exercise real recurring production semantics.
 * Ids are auto-generated (insert_id), never relying onClinic ID 1 or fixed tenant ID.
 *
 * Horizon isolation fixture: pure M-2 policy test (same IANA timezone for both
 * Locations). Clinic A horizon=3, Clinic B horizon=5. Clinic B has schedule
 * at today+4 days, Clinic A does not — beyond-A-horizon B slot must exist
 * under correct per-Clinic horizon, absent if A horizon leaks into B.
 *
 * TEMPORAL FRAME CORRECTION (classification D — test-fixture contract defect,
 * NOT a product RED): this test originally computed its expected observation
 * dates with gmdate/strtotime (UTC frame). Under the permanent temporal rule —
 * the Location is the operational timezone source of truth (see
 * SlotsGenerateLocationLocalTemporalRedTest) — production generates the
 * Location-local future operational days {local today+1 .. local today+horizon},
 * and local "today" itself is intentionally never generated. Whenever the
 * Location-local date differs from the UTC date (FX_TZ Europe/Berlin:
 * 22:00–23:59 UTC), the old UTC-based withinDate WAS the Location's own local
 * "today" — a date the contract never generates — producing a
 * window-dependent false RED (verified runs: 22:01 UTC and 23:30 UTC RED,
 * 21:37 UTC GREEN on identical code). The expected observation dates are now
 * derived in the SAME explicit Location IANA zone (FX_TZ) with the same
 * pure-date algorithm as production (no gmdate, no strtotime, no ambient PHP
 * timezone). Every POLICY assertion — scope-neutral empty-payload wiring,
 * job success, per-Clinic horizon, Clinic A vs B independence, tenant
 * boundaries — is unchanged.
 *
 * ═══ C7 hardening (bounded slice) — payload horizon upper bound ═══
 * Established current contract: absent/malformed non-numeric horizon_days
 * stays on the settings path (horizonForClinic, clamped 1..365); numeric
 * <= 0 already fails closed per sweep item (SLOTS_GEN_SKIP_INVALID_HORIZON
 * + continue). Numeric > 365 is the DEFECT: the payload path has no upper
 * bound while the settings path clamps 1..365. `bin/cpms slots generate
 * --days=N` is a REAL producer of the override (enqueues horizon_days=N).
 *
 * Target contract (test-only RED; no product change in this commit):
 *   - numeric horizon_days > 365 must fail closed PER SWEEP ITEM (same
 *     warning/operational evidence pattern + continue — never a job-wide
 *     abort) and must never generate an unbounded horizon.
 *   - 365 (upper boundary) and small valid overrides must remain accepted.
 *   - absent/malformed values keep their CURRENT settings-path fallback,
 *     unchanged. Numeric non-integer values (e.g. "90.7") are explicitly
 *     OUT of this slice's contract — no RED is asserted for them.
 *
 * Intended RED on current main: horizon_days=366 is accepted and unbounded
 * generation happens, so the fail-closed assertions (zero slots + warning
 * evidence) fail. The 365 / small-override / fallback contracts must stay
 * GREEN on current main (positive controls).
 */
final class SlotsGenerateM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locA = 0;
    private int $locB = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;

    /** FX_TZ local calendar date at fixture build time (boundary guard). */
    private string $localTodayAtBuild = '';

    /** Single IANA zone for both Locations — pure policy test, not temporal. */
    private const FX_TZ = 'Europe/Berlin';

    /**
     * Calendar date of "now" in the fixture's explicit Location IANA zone
     * (FX_TZ) — the SAME frame production uses for these Locations. Built from
     * an explicit UTC instant + explicit zone: never the ambient PHP timezone.
     */
    private function locationNowDate(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::FX_TZ))
            ->format('Y-m-d');
    }

    /**
     * Pure calendar date +N days, UTC-anchored: Gregorian date math only — no
     * ambient timezone, no invented DST policy (same arithmetic as production).
     */
    private function plusDays(string $ymd, int $days): string
    {
        return (new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC')))
            ->add(new \DateInterval('P' . $days . 'D'))
            ->format('Y-m-d');
    }

    /**
     * Iranian DOW (0=شنبه..6=جمعه) of a pure calendar date — frame-independent;
     * same mapping as production, computed on an explicit UTC-anchored date.
     */
    private function iranianDow(string $ymd): int
    {
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];
        $w = (int) (new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC')))->format('w');

        return $map[$w] ?? 0;
    }

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
        if (method_exists(App::class, 'settingsFactory')) {
            try {
                App::settingsFactory()->reset();
            } catch (\Throwable $e) {
            }
        }
        try {
            $factory = App::settingsFactory();
            $ref = new \ReflectionClass($factory);
            if ($ref->hasProperty('instances')) {
                $p = $ref->getProperty('instances');
                $p->setAccessible(true);
                $p->setValue($factory, []);
            }
        } catch (\Throwable $e) {
        }
    }

    private function assertDispatcherCacheFresh(): void
    {
        $refClass = new \ReflectionClass(App::class);
        if ($refClass->hasProperty('dispatcher')) {
            $prop = $refClass->getProperty('dispatcher');
            $prop->setAccessible(true);
            $value = $prop->getValue();
            self::assertNull($value, 'App::$dispatcher cache must be null/fresh before tick');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->buildFixture();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        // Organization — auto ID
        $orgSlug = 'slots-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Slots Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'org inserted');

        // Clinic A — auto ID, same TZ
        $clinicASlug = 'slots-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Slots Clinic A',
            $clinicASlug,
            self::FX_TZ,
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicA, 'clinic A id >1');

        // Clinic B — auto ID, SAME timezone (pure policy)
        $clinicBSlug = 'slots-clinic-b-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Slots Clinic B',
            $clinicBSlug,
            self::FX_TZ,
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicB, 'clinic B id >1');
        self::assertNotSame($this->clinicA, $this->clinicB, 'two distinct clinics');

        // Locations — one primary per clinic, SAME TZ
        $locASlug = 'slots-loc-a-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicA,
            'Slots Loc A',
            $locASlug,
            self::FX_TZ,
            $now,
            $now
        ));
        $this->locA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locA);

        $locBSlug = 'slots-loc-b-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicB,
            'Slots Loc B',
            $locBSlug,
            self::FX_TZ,
            $now,
            $now
        ));
        $this->locB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locB);

        // Verify same TZ for pure policy isolation
        $tzA = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $wpdb->prefix . 'cpms_locations WHERE id = %d', $this->locA));
        $tzB = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $wpdb->prefix . 'cpms_locations WHERE id = %d', $this->locB));
        self::assertSame(self::FX_TZ, $tzA, 'loc A TZ');
        self::assertSame(self::FX_TZ, $tzB, 'loc B TZ');

        // Clinicians — one per clinic, auto ID
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicA,
            'Dr Slots A',
            $now,
            $now
        ));
        $this->clinicianA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianA);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicB,
            'Dr Slots B',
            $now,
            $now
        ));
        $this->clinicianB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianB);

        // Per-Clinic booking.max_future_days — deliberately different
        $dbObj = App::db();
        \ClinicCore\Settings\Settings::flushCache();
        $settingsA = new \ClinicCore\Settings\Settings($dbObj, $this->clinicA, App::audit());
        $settingsA->set('booking.max_future_days', 3);
        $settingsA->set('booking.min_lead_hours', 2);
        $settingsA->set('booking.hold_ttl_sec', 600);
        $settingsB = new \ClinicCore\Settings\Settings($dbObj, $this->clinicB, App::audit());
        $settingsB->set('booking.max_future_days', 5);
        $settingsB->set('booking.min_lead_hours', 2);
        $settingsB->set('booking.hold_ttl_sec', 600);
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();
        $checkA = (int) (new \ClinicCore\Settings\Settings($dbObj, $this->clinicA, App::audit()))->get('booking.max_future_days', 30);
        $checkB = (int) (new \ClinicCore\Settings\Settings($dbObj, $this->clinicB, App::audit()))->get('booking.max_future_days', 30);
        self::assertSame(3, $checkA, 'Clinic A horizon 3');
        self::assertSame(5, $checkB, 'Clinic B horizon 5');
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();

        // Schedules — horizon isolation condition.
        // Observation dates in the fixture's Location frame (FX_TZ) — the SAME
        // explicit IANA zone production uses for these Locations; pure-date
        // arithmetic with the SAME Iranian-DOW mapping as production. No
        // gmdate/strtotime: no UTC frame, no ambient PHP timezone.
        $today = $this->locationNowDate();
        $this->localTodayAtBuild = $today;

        $day1Date = $this->plusDays($today, 1);
        $day2Date = $this->plusDays($today, 2);
        $day4Date = $this->plusDays($today, 4);
        $dow1 = $this->iranianDow($day1Date);
        $dow2 = $this->iranianDow($day2Date);
        $dow4 = $this->iranianDow($day4Date);
        // Ensure dows are distinct enough for isolation; consecutive days are always distinct,
        // day1 vs day4 differ by 3, also distinct (7 >3).
        self::assertNotSame($dow1, $dow4, 'dow1 != dow4 for horizon bleed observability');

        // Clinic A: only day1 and day2 (no day4) — beyond horizon 3 not needed
        $res = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)',
            $this->clinicA,
            $this->locA,
            $this->clinicianA,
            $dow1,
            '09:00:00',
            '10:00:00',
            30,
            1,
            $now,
            $now
        ));
        self::assertNotFalse($res, 'insert schedule A dow1');
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'schedule A dow1 inserted');

        if ($dow2 !== $dow1) {
            $res = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)',
                $this->clinicA,
                $this->locA,
                $this->clinicianA,
                $dow2,
                '09:00:00',
                '10:00:00',
                30,
                1,
                $now,
                $now
            ));
            self::assertNotFalse($res, 'insert schedule A dow2');
            self::assertGreaterThan(0, (int) $wpdb->insert_id, 'schedule A dow2 inserted');
        }

        // Clinic B: day1, day2, AND day4 (beyond A horizon but within B horizon)
        $res = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)',
            $this->clinicB,
            $this->locB,
            $this->clinicianB,
            $dow1,
            '11:00:00',
            '12:00:00',
            30,
            1,
            $now,
            $now
        ));
        self::assertNotFalse($res, 'insert schedule B dow1');
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'schedule B dow1 inserted');

        if ($dow2 !== $dow1) {
            $res = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)',
                $this->clinicB,
                $this->locB,
                $this->clinicianB,
                $dow2,
                '11:00:00',
                '12:00:00',
                30,
                1,
                $now,
                $now
            ));
            self::assertNotFalse($res, 'insert schedule B dow2');
            self::assertGreaterThan(0, (int) $wpdb->insert_id, 'schedule B dow2 inserted');
        }

        // Distinct day4 for B
        // If dow4 equals dow1 or dow2, need distinct start_time to satisfy UNIQUE
        $startForDow4 = ($dow4 === $dow1 || $dow4 === $dow2) ? '13:00:00' : '11:00:00';
        $endForDow4 = ($startForDow4 === '13:00:00') ? '14:00:00' : '12:00:00';
        $res = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, %s, %s)',
            $this->clinicB,
            $this->locB,
            $this->clinicianB,
            $dow4,
            $startForDow4,
            $endForDow4,
            30,
            1,
            $now,
            $now
        ));
        self::assertNotFalse($res, 'insert schedule B dow4 (beyond A horizon)');
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'schedule B dow4 inserted');

        $cntA = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinician_id = %d', $this->clinicianA));
        $cntB = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_schedule WHERE clinician_id = %d', $this->clinicianB));
        self::assertGreaterThanOrEqual(1, $cntA, 'clinician A has schedule');
        self::assertGreaterThanOrEqual(2, $cntB, 'clinician B has schedule including dow4');

        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->clinicianA > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d', $this->clinicianA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d', $this->clinicianB));
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_exceptions') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_exceptions') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        if (method_exists(App::class, 'settingsFactory')) {
            App::settingsFactory()->reset();
        }
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN ("visits.no_show","slots.generate","holds.expire","cleanup.otp","cleanup.rate_limits","cleanup.idem","cleanup.oplog","handwriting.gc","notif.dispatch","appt.reminder","fu.reminder","license.refresh","backup.run","report.export","sms.send")');
    }

    public function testProductionWiringMustBeScopeNeutralWithEmptyPayload(): void
    {
        global $wpdb;
        $db = App::db();

        // ---- Precondition: >=2 clinics fixture ----
        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        $countAll = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $countAll, 'total clinics >1 to trigger CLINIC_SCOPE_REQUIRED, found ' . $countAll);

        // ---- Precondition: ScopeContext null ----
        $this->resetAppCaches();
        $explicit = ScopeContext::tryGet();
        self::assertNull($explicit, 'no ScopeContext must be set');

        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id());

        $this->assertDispatcherCacheFresh();

        // ---- Precondition: App::scope() throws CLINIC_SCOPE_REQUIRED ----
        try {
            $scope = App::scope();
            self::fail('App::scope() should throw CLINIC_SCOPE_REQUIRED when multiple clinics and no explicit scope, but got clinicId=' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        }

        // ---- Purge jobs and ensure slots clean before tick ----
        $this->purgeJobs();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB));

        // ---- Location frame anchor for this run (FX_TZ) ----
        // Observation dates are FUTURE LOCAL OPERATIONAL DATES in the fixture's
        // Location frame — the permanent temporal contract: the operational
        // calendar is the Location's, never the UTC calendar.
        $today = $this->locationNowDate();
        if ($this->localTodayAtBuild !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed between fixture build and test start'
            );
        }

        // ---- Enqueue slots.generate with EMPTY payload (recurring semantics) and maxAttempts=1 ----
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('slots.generate', [], $now, 3, 1);
        self::assertGreaterThan(0, $jobId);
        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertSame('queued', $jobBefore['status']);
        self::assertSame(1, (int) $jobBefore['max_attempts']);

        // ---- Execute via real production path: App::runTick ----
        $tickResult = App::runTick(20);

        // Boundary guard: the Location-local calendar date must not have moved
        // while the production sweep ran, otherwise the observation is
        // inconclusive (skip) — never a product RED.
        if ($this->locationNowDate() !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed during the production sweep'
            );
        }

        // Future LOCAL operational dates relative to the Location's local "today".
        $withinDate = $this->plusDays($today, 1);
        $beyondDate = $this->plusDays($today, 4); // 4 > 3 (A horizon), 4 <= 5 (B horizon)

        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist');
        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');

        // ---- Correct contract on FIXED wiring: SUCCESS ----
        self::assertSame(
            'success',
            $status,
            'On FIXED wiring, slots.generate job must be SUCCESS (scope-neutral). If wiring is broken (ambient Settings), it becomes FAILED with maxAttempts=1. Found status=' . $status . ' last_error=' . $lastError . ' tickResult=' . var_export($tickResult, true)
        );

        // ---- Horizon isolation: pure policy test (same TZ) ----
        // Clinic A horizon=3, Clinic B horizon=5 — per-Clinic settings.

        // B must have generated slot for beyond-A-horizon date (day 4) because B horizon 5 includes it
        $slotsB_beyond = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s',
            $this->clinicianB,
            $beyondDate
        ));
        $slotsB_within = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s',
            $this->clinicianB,
            $withinDate
        ));
        $slotsA_beyond = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s',
            $this->clinicianA,
            $beyondDate
        ));
        $slotsA_within = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s',
            $this->clinicianA,
            $withinDate
        ));

        // Within horizon (day1) both must have slots (SWEEP processes both)
        self::assertGreaterThan(0, $slotsB_within, "Clinic B within-horizon ($withinDate) must have slots");
        self::assertGreaterThan(0, $slotsA_within, "Clinic A within-horizon ($withinDate) must have slots");

        // Beyond A horizon: B must have slot, A must not (A also has no schedule for dow4)
        self::assertGreaterThan(
            0,
            $slotsB_beyond,
            "HORIZON ISOLATION DEFECT: Clinic B (horizon 5) must generate slot for beyond-A-horizon LOCAL date $beyondDate (local today+4). If A horizon 3 leaked into B, B would not generate it (0). within B=$slotsB_within, beyond B=$slotsB_beyond, beyond A=$slotsA_beyond, tickResult=$tickResult"
        );
        self::assertSame(
            0,
            $slotsA_beyond,
            "Clinic A (horizon 3) must NOT have slot for $beyondDate (beyond its horizon and no schedule for that dow)"
        );
    }

    // ═══════════════ C7-B — payload horizon_days > 365 must fail closed ═══════════════

    /**
     * C7-B/1 (intended RED on current main): a numeric payload horizon_days
     * above the settings-path upper bound (365) must be rejected/skipped
     * per sweep item BEFORE any unbounded slot generation happens — with the
     * established warning evidence (SLOTS_GEN_SKIP_INVALID_HORIZON) — and the
     * job must still complete (per-item skip, never a job-wide abort).
     *
     * Real producer exercised: `bin/cpms slots generate --days=366` enqueues
     * exactly ['horizon_days' => 366, 'source' => 'manual'].
     *
     * On current main the payload path has no upper bound, so 366 is accepted
     * and unbounded generation occurs: the zero-slots assertions below fail
     * (RED) with the observed slot counts/dates as evidence.
     */
    public function testPayloadHorizonAboveSettingsUpperBoundMustFailClosedPerSweepItem(): void
    {
        global $wpdb;
        $db = App::db();

        // ---- Precondition: fixture clinics + fresh app caches, no scope ----
        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no ScopeContext must be set');
        wp_set_current_user(0);
        $this->assertDispatcherCacheFresh();

        $this->purgeJobs();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB));

        $today = $this->locationNowDate();
        if ($this->localTodayAtBuild !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed between fixture build and test start'
            );
        }

        // ---- Op-log watermark for this run's warning evidence ----
        $opLogWatermark = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_operational_logs'));

        // ---- Enqueue slots.generate with horizon_days=366 (real producer shape) ----
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('slots.generate', ['horizon_days' => 366, 'source' => 'manual'], $now, 9, 1);
        self::assertGreaterThan(0, $jobId);
        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertSame('queued', $jobBefore['status']);
        self::assertSame(1, (int) $jobBefore['max_attempts']);

        // ---- Execute via real production path: App::runTick ----
        $tickResult = App::runTick(20);

        if ($this->locationNowDate() !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed during the production sweep'
            );
        }

        // ---- Observed evidence ----
        $slotsA = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d', $this->clinicianA));
        $slotsB = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d', $this->clinicianB));
        $maxDate = (string) ($wpdb->get_var($wpdb->prepare('SELECT MAX(slot_date) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB)) ?? '');
        $warnings366 = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_operational_logs') .
            ' WHERE id > %d AND level = %s AND message = %s' .
            ' AND CAST(JSON_EXTRACT(context_json, \'$.horizon_days\') AS UNSIGNED) = %d',
            $opLogWatermark,
            'warning',
            'SLOTS_GEN_SKIP_INVALID_HORIZON',
            366
        ));
        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist');
        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');

        // ---- fail-closed contract: NO slot may be generated for any sweep item ----
        self::assertSame(
            0,
            $slotsA,
            'C7-B/1 horizon>365 fail-closed: Clinic A sweep item must be skipped BEFORE any generation; '
            . 'no unbounded horizon may be produced. Observed: slotsA=' . $slotsA . ' slotsB=' . $slotsB
            . ' maxDate=' . $maxDate . ' today=' . $today . ' status=' . $status
            . ' warnings366=' . $warnings366 . ' tickResult=' . var_export($tickResult, true)
        );
        self::assertSame(
            0,
            $slotsB,
            'C7-B/1 horizon>365 fail-closed: Clinic B sweep item must be skipped BEFORE any generation. '
            . 'Observed: slotsB=' . $slotsB . ' maxDate=' . $maxDate . ' status=' . $status
        );

        // ---- established warning evidence pattern for the invalid horizon ----
        self::assertGreaterThanOrEqual(
            1,
            $warnings366,
            'C7-B/1 warning evidence: at least one SLOTS_GEN_SKIP_INVALID_HORIZON warning with '
            . 'horizon_days=366 must be recorded (established operational evidence pattern). '
            . 'Observed warnings366=' . $warnings366 . ' watermark=' . $opLogWatermark
        );

        // ---- per-item skip: sweep continues and the job completes ----
        self::assertSame(
            'success',
            $status,
            'C7-B/1 per-item progress: an invalid horizon must skip the sweep ITEM, never abort the job '
            . '(other Clinic work in the sweep must remain possible). Found status=' . $status
            . ' last_error=' . $lastError . ' tickResult=' . var_export($tickResult, true)
        );
    }

    /**
     * C7-B/2 (positive boundary control — must stay GREEN on current main):
     * numeric horizon_days=365 (upper boundary) must remain ACCEPTED and
     * generate within that horizon, with no invalid-horizon warning.
     */
    public function testPayloadHorizonUpperBoundary365MustRemainAccepted(): void
    {
        global $wpdb;
        $db = App::db();

        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no ScopeContext must be set');
        wp_set_current_user(0);
        $this->assertDispatcherCacheFresh();

        $this->purgeJobs();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB));

        $today = $this->locationNowDate();
        if ($this->localTodayAtBuild !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed between fixture build and test start'
            );
        }

        $opLogWatermark = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_operational_logs'));

        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('slots.generate', ['horizon_days' => 365, 'source' => 'manual'], $now, 9, 1);
        self::assertGreaterThan(0, $jobId);

        $tickResult = App::runTick(20);

        if ($this->locationNowDate() !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed during the production sweep'
            );
        }

        $withinDate = $this->plusDays($today, 1);
        $day4Date = $this->plusDays($today, 4); // 4 <= 365 for both clinics

        $slotsA_within = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $withinDate));
        $slotsB_within = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $withinDate));
        $slotsB_day4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day4Date));
        $warnings365 = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_operational_logs') .
            ' WHERE id > %d AND level = %s AND message = %s',
            $opLogWatermark,
            'warning',
            'SLOTS_GEN_SKIP_INVALID_HORIZON'
        ));

        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        $status = (string) ($jobAfter['status'] ?? '');

        self::assertSame('success', $status, 'C7-B/2 boundary 365: job must be SUCCESS. Found status=' . $status . ' tickResult=' . var_export($tickResult, true));
        self::assertGreaterThan(0, $slotsA_within, "C7-B/2 boundary 365 accepted: Clinic A within-horizon ($withinDate) must have slots");
        self::assertGreaterThan(0, $slotsB_within, "C7-B/2 boundary 365 accepted: Clinic B within-horizon ($withinDate) must have slots");
        self::assertGreaterThan(0, $slotsB_day4, "C7-B/2 boundary 365 accepted: Clinic B day4 ($day4Date) must have slots (4 <= 365)");
        self::assertSame(0, $warnings365, 'C7-B/2 boundary 365 must NOT be treated as invalid horizon (no skip warning)');
    }

    /**
     * C7-B/3 (positive control — must stay GREEN on current main): a small
     * valid numeric override (2) must be accepted EXACTLY — days within 2
     * generated, day 4 never generated for either clinic.
     */
    public function testPayloadHorizonSmallOverrideMustRemainAcceptedExactly(): void
    {
        global $wpdb;
        $db = App::db();

        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no ScopeContext must be set');
        wp_set_current_user(0);
        $this->assertDispatcherCacheFresh();

        $this->purgeJobs();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB));

        $today = $this->locationNowDate();
        if ($this->localTodayAtBuild !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed between fixture build and test start'
            );
        }

        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('slots.generate', ['horizon_days' => 2, 'source' => 'manual'], $now, 9, 1);
        self::assertGreaterThan(0, $jobId);

        $tickResult = App::runTick(20);

        if ($this->locationNowDate() !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed during the production sweep'
            );
        }

        $day1 = $this->plusDays($today, 1);
        $day2 = $this->plusDays($today, 2);
        $day4 = $this->plusDays($today, 4);

        $slotsA1 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day1));
        $slotsA2 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day2));
        $slotsA4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day4));
        $slotsB1 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day1));
        $slotsB2 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day2));
        $slotsB4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day4));

        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        $status = (string) ($jobAfter['status'] ?? '');

        self::assertSame('success', $status, 'C7-B/3 small override: job must be SUCCESS. Found status=' . $status . ' tickResult=' . var_export($tickResult, true));
        self::assertGreaterThan(0, $slotsA1, "C7-B/3 override 2: Clinic A day1 ($day1) must have slots");
        self::assertGreaterThan(0, $slotsA2, "C7-B/3 override 2: Clinic A day2 ($day2) must have slots");
        self::assertGreaterThan(0, $slotsB1, "C7-B/3 override 2: Clinic B day1 ($day1) must have slots");
        self::assertGreaterThan(0, $slotsB2, "C7-B/3 override 2: Clinic B day2 ($day2) must have slots");
        self::assertSame(0, $slotsA4, "C7-B/3 override 2: Clinic A day4 ($day4) must NOT have slots (beyond override horizon)");
        self::assertSame(0, $slotsB4, "C7-B/3 override 2: Clinic B day4 ($day4) must NOT have slots (beyond override horizon)");
    }

    /**
     * C7-B/4 (fallback-semantics control — must stay GREEN on current main):
     * absent horizon_days and malformed non-numeric horizon_days must keep
     * their CURRENT behavior: the settings path (clamped 1..365). This pins
     * the fallback so the >365 hardening cannot silently change it.
     */
    public function testAbsentAndMalformedHorizonKeepSettingsFallback(): void
    {
        global $wpdb;
        $db = App::db();

        $this->resetAppCaches();
        self::assertNull(ScopeContext::tryGet(), 'no ScopeContext must be set');
        wp_set_current_user(0);
        $this->assertDispatcherCacheFresh();

        $today = $this->locationNowDate();
        if ($this->localTodayAtBuild !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed between fixture build and test start'
            );
        }

        $day1 = $this->plusDays($today, 1);
        $day4 = $this->plusDays($today, 4); // 4 > A settings (3); 4 <= B settings (5)

        // ---- Case 1: ABSENT horizon_days (manual payload without the key) ----
        $this->purgeJobs();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB));

        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId1 = $queue->enqueue('slots.generate', ['source' => 'manual'], $now, 9, 1);
        self::assertGreaterThan(0, $jobId1);

        $tickResult1 = App::runTick(20);

        if ($this->locationNowDate() !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed during the production sweep (absent case)'
            );
        }

        $absA1 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day1));
        $absA4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day4));
        $absB1 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day1));
        $absB4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day4));

        $jobAfter1 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId1), ARRAY_A);
        $status1 = (string) ($jobAfter1['status'] ?? '');

        self::assertSame('success', $status1, 'C7-B/4 absent: job must be SUCCESS. Found status=' . $status1 . ' tickResult=' . var_export($tickResult1, true));
        self::assertGreaterThan(0, $absA1, "C7-B/4 absent (settings path): Clinic A day1 ($day1) must have slots");
        self::assertSame(0, $absA4, "C7-B/4 absent (settings path, A horizon 3): Clinic A day4 ($day4) must NOT have slots");
        self::assertGreaterThan(0, $absB1, "C7-B/4 absent (settings path): Clinic B day1 ($day1) must have slots");
        self::assertGreaterThan(0, $absB4, "C7-B/4 absent (settings path, B horizon 5): Clinic B day4 ($day4) must have slots");

        // ---- Case 2: MALFORMED non-numeric horizon_days (current behavior = settings path) ----
        $this->purgeJobs();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id IN (%d, %d)', $this->clinicianA, $this->clinicianB));

        $opLogWatermark2 = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . $db->table('cpms_operational_logs'));

        $jobId2 = $queue->enqueue('slots.generate', ['horizon_days' => 'abc', 'source' => 'manual'], $now, 9, 1);
        self::assertGreaterThan(0, $jobId2);

        $tickResult2 = App::runTick(20);

        if ($this->locationNowDate() !== $today) {
            self::markTestSkipped(
                'INCONCLUSIVE (not a product RED): ' . self::FX_TZ
                . ' local calendar date changed during the production sweep (malformed case)'
            );
        }

        $malA1 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day1));
        $malA4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianA, $day4));
        $malB1 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day1));
        $malB4 = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinician_id = %d AND slot_date = %s', $this->clinicianB, $day4));
        $warningsMalformed = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_operational_logs') .
            ' WHERE id > %d AND level = %s AND message = %s',
            $opLogWatermark2,
            'warning',
            'SLOTS_GEN_SKIP_INVALID_HORIZON'
        ));

        $jobAfter2 = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId2), ARRAY_A);
        $status2 = (string) ($jobAfter2['status'] ?? '');

        self::assertSame('success', $status2, 'C7-B/4 malformed: job must be SUCCESS. Found status=' . $status2 . ' tickResult=' . var_export($tickResult2, true));
        self::assertGreaterThan(0, $malA1, "C7-B/4 malformed (CURRENT fallback = settings path): Clinic A day1 ($day1) must have slots");
        self::assertSame(0, $malA4, "C7-B/4 malformed (CURRENT fallback = settings path, A horizon 3): Clinic A day4 ($day4) must NOT have slots");
        self::assertGreaterThan(0, $malB1, "C7-B/4 malformed (CURRENT fallback = settings path): Clinic B day1 ($day1) must have slots");
        self::assertGreaterThan(0, $malB4, "C7-B/4 malformed (CURRENT fallback = settings path, B horizon 5): Clinic B day4 ($day4) must have slots");
        self::assertSame(0, $warningsMalformed, 'C7-B/4 malformed: CURRENT behavior must NOT record an invalid-horizon skip (settings fallback, unchanged)');
    }
}

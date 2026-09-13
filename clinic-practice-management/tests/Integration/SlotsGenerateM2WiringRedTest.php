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
 * Expected date is computed with SAME gmdate/strtotime algorithm as production
 * to isolate horizon-policy contract from known temporal defect.
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

    /** Single IANA zone for both Locations — pure policy test, not temporal. */
    private const FX_TZ = 'Europe/Berlin';

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

        // Schedules — horizon isolation condition
        // Use SAME Iranian-DOW mapping as production handler to isolate policy
        // from known gmdate defect. Compute DOW for today+1, +2, +4.
        $today = gmdate('Y-m-d');
        $map = [0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 0];
        $toIranian = static fn (string $ymd): int => $map[(int) gmdate('w', strtotime($ymd))] ?? 0;

        $day1Date = gmdate('Y-m-d', strtotime($today . ' +1 days'));
        $day2Date = gmdate('Y-m-d', strtotime($today . ' +2 days'));
        $day4Date = gmdate('Y-m-d', strtotime($today . ' +4 days'));
        $dow1 = $toIranian($day1Date);
        $dow2 = $toIranian($day2Date);
        $dow4 = $toIranian($day4Date);
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
        // Clinic A horizon=3, Clinic B horizon=5. Use SAME gmdate/strtotime as handler.
        $today = gmdate('Y-m-d');
        $beyondDate = gmdate('Y-m-d', strtotime($today . ' +4 days')); // 4 >3, <=5
        $withinDate = gmdate('Y-m-d', strtotime($today . ' +1 days'));

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
            "HORIZON ISOLATION DEFECT: Clinic B (horizon 5) must generate slot for beyond-A-horizon date $beyondDate (today+4). If A horizon 3 leaked into B, B would not generate it (0). within B=$slotsB_within, beyond B=$slotsB_beyond, beyond A=$slotsA_beyond, tickResult=$tickResult"
        );
        self::assertSame(
            0,
            $slotsA_beyond,
            "Clinic A (horizon 3) must NOT have slot for $beyondDate (beyond its horizon and no schedule for that dow)"
        );
    }
}

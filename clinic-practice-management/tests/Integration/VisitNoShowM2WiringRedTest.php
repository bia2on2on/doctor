<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * RED 1 — Production wiring must be scope-neutral.
 *
 * Proves visits.no_show handler construction via App::visitService() throws
 * CLINIC_SCOPE_REQUIRED in multi-Clinic no-Scope worker.
 *
 * Expected RED: job fails with CLINIC_SCOPE_REQUIRED.
 * After fix: job succeeds (no CLINIC_SCOPE_REQUIRED).
 */
final class VisitNoShowM2WiringRedTest extends WP_UnitTestCase
{
    private const FX_ORG_ID = 62300;
    private const FX_CLINIC_A_ID = 62301;
    private const FX_CLINIC_B_ID = 62302;
    private const FX_LOC_A_ID = 62310;
    private const FX_LOC_B_ID = 62311;
    private const FX_ID_FLOOR = 62300;

    private function resetAppCaches(): void
    {
        // Reset class-level private static properties — use project's safe convention
        // Includes visitService which is now class-level (was function-static) to allow test reset
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
    }

    private function assertVisitServiceCacheIsFresh(): void
    {
        $refClass = new \ReflectionClass(App::class);
        if ($refClass->hasProperty('visitService')) {
            $prop = $refClass->getProperty('visitService');
            $prop->setAccessible(true);
            $value = $prop->getValue();
            self::assertNull($value, 'App::$visitService cache must be null/fresh before tick');
        }
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
        // Ensure no WP user
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

        // Assert no leftover
        $leftover = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        self::assertSame(0, $leftover, 'precondition: no leftover fixture rows');

        // Org
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, "active", %s, %s)',
            self::FX_ORG_ID,
            'Wiring Org',
            'wiring-org-' . bin2hex(random_bytes(2)),
            $now,
            $now
        ));

        // Clinic A
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_A_ID,
            self::FX_ORG_ID,
            'Wiring Clinic A',
            'wiring-clinic-a-' . bin2hex(random_bytes(3)),
            'Europe/Berlin',
            $now,
            $now
        ));

        // Clinic B
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_B_ID,
            self::FX_ORG_ID,
            'Wiring Clinic B',
            'wiring-clinic-b-' . bin2hex(random_bytes(3)),
            'Asia/Tokyo',
            $now,
            $now
        ));

        // Locations
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_A_ID,
            self::FX_CLINIC_A_ID,
            'Wiring Loc A',
            'wiring-loc-a-' . bin2hex(random_bytes(2)),
            'Europe/Berlin',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_B_ID,
            self::FX_CLINIC_B_ID,
            'Wiring Loc B',
            'wiring-loc-b-' . bin2hex(random_bytes(2)),
            'Asia/Tokyo',
            $now,
            $now
        ));

        // Minimal settings per clinic to avoid default ambiguity
        \ClinicCore\Settings\Settings::flushCache();
        $settingsA = new \ClinicCore\Settings\Settings($db, self::FX_CLINIC_A_ID, App::audit());
        $settingsA->set('queue.no_show_grace_minutes', 30);
        $settingsB = new \ClinicCore\Settings\Settings($db, self::FX_CLINIC_B_ID, App::audit());
        $settingsB->set('queue.no_show_grace_minutes', 30);
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR . ')');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id >= ' . self::FX_ID_FLOOR);
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

    public function testProductionWiringMustBeScopeNeutral(): void
    {
        global $wpdb;
        $db = App::db();

        // ---- Precondition: >=2 real Clinics (fixture + total) ----
        $clinicCountFixture = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        self::assertGreaterThanOrEqual(2, $clinicCountFixture, 'fixture must have at least two real clinics');

        $clinicAExists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_A_ID));
        $clinicBExists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_B_ID));
        self::assertNotEmpty($clinicAExists, 'Clinic A must exist');
        self::assertNotEmpty($clinicBExists, 'Clinic B must exist');

        $countAll = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $countAll, 'total clinic count must be >1 to trigger CLINIC_SCOPE_REQUIRED, found ' . $countAll);

        // ---- Precondition: ScopeContext::tryGet() === null ----
        $this->resetAppCaches();
        $explicit = ScopeContext::tryGet();
        self::assertNull($explicit, 'no ScopeContext must be set for this test');

        // ---- Precondition: current WP user does not establish tenant context ----
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current WP user');

        // ---- Precondition: App VisitService cache is null/fresh + dispatcher fresh ----
        $this->assertVisitServiceCacheIsFresh();

        // ---- Precondition: App::scope() throws CLINIC_SCOPE_REQUIRED when multiple clinics and no explicit scope ----
        try {
            $scope = App::scope();
            self::fail('App::scope() should throw CLINIC_SCOPE_REQUIRED when multiple clinics exist and no explicit scope, but got clinicId=' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'scope must throw CLINIC_SCOPE_REQUIRED');
        }

        // ---- Enqueue visits.no_show with maxAttempts=1 (fail-fast) ----
        $countBeforeTick = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $countBeforeTick, 'clinic count must still be >1 right before runTick, found ' . $countBeforeTick);

        $this->purgeJobs();
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('visits.no_show', [], $now, 5, 1);
        self::assertGreaterThan(0, $jobId, 'job enqueued');

        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobBefore, 'job row exists before tick');
        self::assertSame('queued', $jobBefore['status'], 'job initially queued');
        self::assertSame(1, (int) $jobBefore['max_attempts'], 'maxAttempts must be 1 for fail-fast RED proof');

        // ---- Execute through REAL path: App::runTick -> production dispatcher -> visits.no_show ----
        $tickResult = App::runTick(5);

        // ---- Fetch job after tick ----
        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist after tick');

        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');

        // ---- Correct contract on FIXED wiring: status must be SUCCESS, not FAILED due to implicit Clinic resolution ----
        // Do NOT search last_error for ASCII CLINIC_SCOPE_REQUIRED because dispatcher stores getMessage() (Persian) not errorCode
        self::assertSame(
            'success',
            $status,
            'On FIXED wiring, visits.no_show job must be SUCCESS (scope-neutral). ' .
            'If wiring is broken, it becomes FAILED with maxAttempts=1. ' .
            'Found status=' . $status . ' last_error=' . $lastError . ' tickResult=' . var_export($tickResult, true)
        );
    }
}

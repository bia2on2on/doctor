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
        // Reset class-level private static properties
        $refClass = new \ReflectionClass(App::class);
        foreach (['db','op','audit','jobs','rate','loginRateLimiter','idem','settingsFactory','migrations','dispatcher','providers','vault','smsService','licenseGate'] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        // Reset function-level static caches inside App::*Service() methods
        $serviceMethods = [
            'bookingService','visitService','scheduleService','clinicalService','financeService',
            'handwritingService','notificationService','reportService','exportService','medicalFileService',
            'patientService','otpService','backupService','updateService','wpUpdateBridge','systemHealthService',
            'dispatcher','licenseService','licenseGateway','providers','vault','smsService','membership_service',
            'patient_identity_service','clinicianRepository'
        ];
        foreach ($serviceMethods as $methodName) {
            if (!method_exists(App::class, $methodName)) {
                continue;
            }
            try {
                $rm = new \ReflectionMethod(App::class, $methodName);
                $staticVars = $rm->getStaticVariables();
                foreach ($staticVars as $varName => $varValue) {
                    $rm->setStaticVariable($varName, null);
                }
            } catch (\Throwable $e) {
                // ignore
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
            'wiring-clinic-a',
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
            'wiring-clinic-b',
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

        // Prove at least two real Clinics exist
        $clinicCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        self::assertGreaterThanOrEqual(2, $clinicCount, 'fixture must have at least two real clinics');
        $clinicAExists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_A_ID));
        $clinicBExists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_CLINIC_B_ID));
        self::assertNotEmpty($clinicAExists, 'Clinic A must exist');
        self::assertNotEmpty($clinicBExists, 'Clinic B must exist');

        // Prove no ScopeContext is set and reset caches to force re-resolution
        $this->resetAppCaches();
        $explicit = ScopeContext::tryGet();
        self::assertNull($explicit, 'no ScopeContext must be set for this test');
        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id(), 'no current WP user');

        // Enqueue root visits.no_show
        $this->purgeJobs();
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('visits.no_show', [], $now, 5, 3);
        self::assertGreaterThan(0, $jobId, 'job enqueued');

        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobBefore, 'job row exists before tick');
        self::assertSame('queued', $jobBefore['status'], 'job initially queued');

        // Execute through REAL production path: App::runTick -> production dispatcher -> VisitsNoShowHandler
        $tickResult = App::runTick(5);

        // Fetch job after tick
        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist after tick (either success or failed/queued for retry)');

        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');

        // The intended product path was reached if we attempted to claim and handler threw or succeeded
        // If defect exists, last_error contains CLINIC_SCOPE_REQUIRED
        // For RED: we expect failure with CLINIC_SCOPE_REQUIRED, so we assert it must NOT contain it
        // This assertion will FAIL when defect exists (RED), and PASS after fix (GREEN)
        self::assertStringNotContainsString(
            'CLINIC_SCOPE_REQUIRED',
            $lastError,
            'PRODUCTION WIRING DEFECT: visits.no_show handler construction via App::visitService() must be scope-neutral and must not fail with CLINIC_SCOPE_REQUIRED in multi-Clinic no-Scope worker. ' .
            'Found last_error=' . $lastError . ' status=' . $status . ' tickResult=' . var_export($tickResult, true)
        );

        // Additionally, job should not be terminal FAILED due to scope, it should be SUCCESS or still QUEUED for retry but without scope error
        // After fix, with no appointments, it should be SUCCESS
        // We allow SUCCESS or QUEUED (if no appointments, it completes), but not FAILED with scope error
        self::assertNotSame('failed', $status, 'job must not be terminal FAILED due to scope wiring defect; status=' . $status . ' last_error=' . $lastError);
    }
}

<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * M-2 fu.reminder — RED 1: scope-neutral wiring.
 *
 * Proves that production wiring for fu.reminder must be scope-neutral.
 *
 * Pre-fix defect: App::dispatcher() registers fu.reminder with
 *   new FollowUpReminderHandler($db, App::settings(), App::smsService(), App::notificationService(), $op)
 * which calls App::settings() / App::notificationService() → App::scope() → throws CLINIC_SCOPE_REQUIRED
 * in multi-Clinic no-Scope worker. With maxAttempts=1 the job fails with FAILED, not SUCCESS.
 *
 * Expected RED (pre-fix): job status FAILED
 * Expected GREEN (post-fix): job status SUCCESS
 *
 * Uses EMPTY payload to exercise real recurring production semantics.
 */
final class FollowUpReminderM2WiringRedTest extends WP_UnitTestCase
{
    private const FX_ORG_ID = 62400;
    private const FX_CLINIC_A_ID = 62401;
    private const FX_CLINIC_B_ID = 62402;
    private const FX_LOC_A_ID = 62410;
    private const FX_LOC_B_ID = 62411;
    private const FX_CLINICIAN_A_ID = 62420;
    private const FX_CLINICIAN_B_ID = 62421;
    private const FX_PATIENT_A_ID = 62430;
    private const FX_PATIENT_B_ID = 62431;
    private const FX_VISIT_A_ID = 62440;
    private const FX_VISIT_B_ID = 62441;
    private const FX_FLOOR = 62400;

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

        // Ensure clean
        $leftover = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_FLOOR);
        self::assertSame(0, $leftover, 'precondition: no leftover fixture rows');

        // Org
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, \"active\", %s, %s)',
            self::FX_ORG_ID,
            'FU Wiring Org',
            'fu-wiring-org-' . bin2hex(random_bytes(2)),
            $now,
            $now
        ));

        // Clinic A
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_A_ID,
            self::FX_ORG_ID,
            'FU Wiring Clinic A',
            'fu-wiring-clinic-a-' . bin2hex(random_bytes(3)),
            'Asia/Tehran',
            $now,
            $now
        ));

        // Clinic B
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_B_ID,
            self::FX_ORG_ID,
            'FU Wiring Clinic B',
            'fu-wiring-clinic-b-' . bin2hex(random_bytes(3)),
            'Asia/Tokyo',
            $now,
            $now
        ));

        // Locations
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_A_ID,
            self::FX_CLINIC_A_ID,
            'FU Loc A',
            'fu-loc-a-' . bin2hex(random_bytes(2)),
            'Asia/Tehran',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_B_ID,
            self::FX_CLINIC_B_ID,
            'FU Loc B',
            'fu-loc-b-' . bin2hex(random_bytes(2)),
            'Asia/Tokyo',
            $now,
            $now
        ));

        // Clinicians
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            self::FX_CLINICIAN_A_ID,
            self::FX_CLINIC_A_ID,
            'Dr FU A',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            self::FX_CLINICIAN_B_ID,
            self::FX_CLINIC_B_ID,
            'Dr FU B',
            $now,
            $now
        ));

        // Patients
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (id, clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, \"active\", %s, %s)',
            self::FX_PATIENT_A_ID,
            self::FX_CLINIC_A_ID,
            'MR-FU-A-' . bin2hex(random_bytes(2)),
            'FU',
            'PatientA',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (id, clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, \"active\", %s, %s)',
            self::FX_PATIENT_B_ID,
            self::FX_CLINIC_B_ID,
            'MR-FU-B-' . bin2hex(random_bytes(2)),
            'FU',
            'PatientB',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));

        // Visits (minimal, to satisfy follow_up FK if we insert any, but wiring RED needs no follow-ups)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (id, clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, \"walk_in\", \"checked_in\", %s, %s, %s, %s)',
            self::FX_VISIT_A_ID,
            self::FX_CLINIC_A_ID,
            self::FX_LOC_A_ID,
            self::FX_CLINICIAN_A_ID,
            self::FX_PATIENT_A_ID,
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (id, clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, \"walk_in\", \"checked_in\", %s, %s, %s, %s)',
            self::FX_VISIT_B_ID,
            self::FX_CLINIC_B_ID,
            self::FX_LOC_B_ID,
            self::FX_CLINICIAN_B_ID,
            self::FX_PATIENT_B_ID,
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));

        // Settings per clinic (quiet hours open deterministically)
        \ClinicCore\Settings\Settings::flushCache();
        $settingsA = new \ClinicCore\Settings\Settings($db, self::FX_CLINIC_A_ID, App::audit());
        $settingsA->set('notif.quiet_hours_start', '00:00');
        $settingsA->set('notif.quiet_hours_end', '23:59');
        $settingsB = new \ClinicCore\Settings\Settings($db, self::FX_CLINIC_B_ID, App::audit());
        $settingsB->set('notif.quiet_hours_start', '00:00');
        $settingsB->set('notif.quiet_hours_end', '23:59');
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id >= ' . self::FX_FLOOR);
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
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\"visits.no_show\",\"slots.generate\",\"holds.expire\",\"cleanup.otp\",\"cleanup.rate_limits\",\"cleanup.idem\",\"cleanup.oplog\",\"handwriting.gc\",\"notif.dispatch\",\"appt.reminder\",\"fu.reminder\",\"license.refresh\",\"backup.run\",\"report.export\",\"sms.send\")');
    }

    public function testProductionWiringMustBeScopeNeutralWithEmptyPayload(): void
    {
        global $wpdb;
        $db = App::db();

        // Precondition: >=2 clinics
        $countAll = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $countAll, 'total clinics >1 to trigger CLINIC_SCOPE_REQUIRED, found ' . $countAll);

        // Precondition: ScopeContext null
        $this->resetAppCaches();
        $explicit = ScopeContext::tryGet();
        self::assertNull($explicit, 'no ScopeContext must be set');

        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id());

        $this->assertDispatcherCacheFresh();

        // Precondition: App::scope() throws CLINIC_SCOPE_REQUIRED
        try {
            $scope = App::scope();
            self::fail('App::scope() should throw CLINIC_SCOPE_REQUIRED when multiple clinics and no explicit scope, but got clinicId=' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        }

        $this->purgeJobs();

        // Enqueue fu.reminder with EMPTY payload and maxAttempts=1
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('fu.reminder', [], $now, 4, 1);
        self::assertGreaterThan(0, $jobId);
        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertSame('queued', $jobBefore['status']);
        self::assertSame(1, (int) $jobBefore['max_attempts']);

        // Execute via real production path: App::runTick
        $tickResult = App::runTick(20);

        $jobAfter = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertNotEmpty($jobAfter, 'job row must still exist');
        $status = (string) ($jobAfter['status'] ?? '');
        $lastError = (string) ($jobAfter['last_error'] ?? '');

        self::assertSame(
            'success',
            $status,
            'On FIXED wiring, fu.reminder job must be SUCCESS (scope-neutral). If wiring is broken (ambient Settings), it becomes FAILED with maxAttempts=1. Found status=' . $status . ' last_error=' . $lastError . ' tickResult=' . var_export($tickResult, true)
        );
    }
}

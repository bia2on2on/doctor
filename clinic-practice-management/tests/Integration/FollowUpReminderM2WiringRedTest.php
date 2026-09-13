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
 * Uses auto-generated IDs (insert_id) to avoid fixed-ID conflicts.
 */
final class FollowUpReminderM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locA = 0;
    private int $locB = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;

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

        $orgSlug = 'fu-wiring-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'FU Wiring Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'org inserted');

        $clinicASlug = 'fu-wiring-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'FU Wiring Clinic A',
            $clinicASlug,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicA, 'clinic A id >1');

        $clinicBSlug = 'fu-wiring-clinic-b-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'FU Wiring Clinic B',
            $clinicBSlug,
            'Asia/Tokyo',
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicB, 'clinic B id >1');
        self::assertNotSame($this->clinicA, $this->clinicB, 'two distinct clinics');

        $locASlug = 'fu-wiring-loc-a-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicA,
            'FU Loc A',
            $locASlug,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->locA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locA);

        $locBSlug = 'fu-wiring-loc-b-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicB,
            'FU Loc B',
            $locBSlug,
            'Asia/Tokyo',
            $now,
            $now
        ));
        $this->locB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locB);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicA,
            'Dr FU A',
            $now,
            $now
        ));
        $this->clinicianA = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicB,
            'Dr FU B',
            $now,
            $now
        ));
        $this->clinicianB = (int) $wpdb->insert_id;

        \ClinicCore\Settings\Settings::flushCache();
        $settingsA = new \ClinicCore\Settings\Settings($db, $this->clinicA, App::audit());
        $settingsA->set('notif.quiet_hours_start', '00:00');
        $settingsA->set('notif.quiet_hours_end', '23:59');
        $settingsB = new \ClinicCore\Settings\Settings($db, $this->clinicB, App::audit());
        $settingsB->set('notif.quiet_hours_start', '00:00');
        $settingsB->set('notif.quiet_hours_end', '23:59');
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
        if ($this->orgId > 0) {
            if ($this->clinicA > 0) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicA));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicB));
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
            }
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
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\'visits.no_show\',\'slots.generate\',\'holds.expire\',\'cleanup.otp\',\'cleanup.rate_limits\',\'cleanup.idem\',\'cleanup.oplog\',\'handwriting.gc\',\'notif.dispatch\',\'appt.reminder\',\'fu.reminder\',\'license.refresh\',\'backup.run\',\'report.export\',\'sms.send\')');
    }

    public function testProductionWiringMustBeScopeNeutralWithEmptyPayload(): void
    {
        global $wpdb;
        $db = App::db();

        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        $countAll = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
        self::assertGreaterThan(1, $countAll, 'total clinics >1 to trigger CLINIC_SCOPE_REQUIRED, found ' . $countAll);

        $this->resetAppCaches();
        $explicit = ScopeContext::tryGet();
        self::assertNull($explicit, 'no ScopeContext must be set');

        wp_set_current_user(0);
        self::assertSame(0, get_current_user_id());

        $this->assertDispatcherCacheFresh();

        try {
            $scope = App::scope();
            self::fail('App::scope() should throw CLINIC_SCOPE_REQUIRED when multiple clinics and no explicit scope, but got clinicId=' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        }

        $this->purgeJobs();

        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('fu.reminder', [], $now, 4, 1);
        self::assertGreaterThan(0, $jobId);
        $jobBefore = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d', $jobId), ARRAY_A);
        self::assertSame('queued', $jobBefore['status']);
        self::assertSame(1, (int) $jobBefore['max_attempts']);

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

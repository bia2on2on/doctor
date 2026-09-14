<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use WP_UnitTestCase;

/**
 * M-2 notif.dispatch — RED: scope-neutral worker construction/execution.
 *
 * Proves that production wiring for notif.dispatch must be constructible and
 * executable WITHOUT request/user Clinic scope (a W installation-wide sweep).
 *
 * Pre-fix defect (current main): App::dispatcher() registers notif.dispatch as
 *   (new NotifDispatchHandler(self::notificationService(), self::exportService()))($payload)
 * and App::notificationService() resolves the NotificationService lazily via
 *   self::settings() -> App::scope()
 * which, in a multi-Clinic install with no request/user scope, throws
 * CLINIC_SCOPE_REQUIRED before any dispatch work runs. With maxAttempts=1 the
 * job becomes FAILED (last_error = the scope-resolution message), not SUCCESS.
 *
 * Expected RED (pre-fix): job status FAILED (scope dependency at construction)
 * Expected GREEN (post-fix): job status SUCCESS (scope-neutral worker)
 *
 * Narrow contract (no product-policy decision made here):
 *   A scope-neutral W worker must be constructible/executable without
 *   request/user Clinic scope.
 *
 * ARCHITECTURE BLOCKED (status of the fix, not of this test):
 *   A prior GREEN attempt made notif.dispatch scope-neutral by routing it
 *   directly through NotificationRepository::dispatchQueued(), which silently
 *   removed the ONLY production invocation of NotificationRepository::
 *   purgeArchived() (notification archive retention, notif.archive_days).
 *   That regression was reverted — production notif.dispatch behavior is back
 *   to current main. The fix is BLOCKED until a scope-neutral source for
 *   notif.archive_days is resolved (installation-level Settings semantics),
 *   which is out of scope for this slice. This test remains the valid RED
 *   evidence for the underlying defect.
 *
 * PURGE / RETENTION GUARD:
 *   This test does NOT decide whether notif.archive_days is installation-wide
 *   or per-Clinic, does NOT decide a default, and does NOT assert that purge
 *   is skipped. Retention execution must NOT be silently dropped; the narrow
 *   contract here is only that a scope-neutral W worker be constructible and
 *   executable. The queued->sent mutation assertion (below) documents the
 *   intended GREEN behavior without touching retention.
 *
 * STATIC-CACHE NOTE (classification-relevant):
 *   App::notificationService() memoizes the NotificationService in a
 *   function-local `static $notifications` that cannot be reset from test
 *   code. In the shared long-running Integration process a prior test can
 *   memoize it with a valid Clinic and hide the construction-time scope
 *   dependency, producing a FALSE GREEN. This RED is therefore authoritative
 *   ONLY when run in a dedicated fresh PHP process (as each production
 *   worker is). A green shared-suite Integration result must NOT be treated
 *   as proof that this RED is resolved.
 *
 * Uses EMPTY payload to exercise real recurring production semantics.
 * Ids are auto-generated (insert_id); never clinic_id=1/0, never first-row.
 */
final class NotifDispatchM2WiringRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;

    /**
     * Reset process-level App statics that have no public reset (class props).
     * Function-local statics (e.g. notificationService) are intentionally NOT
     * resettable here; this test is run in a fresh process for that reason.
     */
    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
            'settingsFactory', 'migrations', 'dispatcher', 'providers', 'vault',
            'smsService', 'licenseGate', 'visitService',
        ] as $propName) {
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
            } catch (\Throwable) {
                // ignore — reset is best-effort
            }
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

        $orgSlug = 'notif-wiring-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Notif Wiring Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'org inserted');

        $clinicASlug = 'notif-wiring-clinic-a-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Notif Wiring Clinic A',
            $clinicASlug,
            'Asia/Tehran',
            $now,
            $now
        ));
        $this->clinicA = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicA, 'clinic A id >1');

        $clinicBSlug = 'notif-wiring-clinic-b-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Notif Wiring Clinic B',
            $clinicBSlug,
            'Asia/Tokyo',
            $now,
            $now
        ));
        $this->clinicB = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $this->clinicB, 'clinic B id >1');
        self::assertNotSame($this->clinicA, $this->clinicB, 'two distinct clinics');

        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        if (method_exists(App::class, 'settingsFactory')) {
            App::settingsFactory()->reset();
        }
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->clinicA > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicB));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicA));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicB));
            if ($this->orgId > 0) {
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

    public function testNotifDispatchWiringMustBeScopeNeutralWithEmptyPayload(): void
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

        // Precondition: direct scope resolution must fail closed (no implicit Clinic).
        try {
            $scope = App::scope();
            self::fail('App::scope() should throw CLINIC_SCOPE_REQUIRED when multiple clinics and no explicit scope, but got clinicId=' . $scope->clinicId);
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        }

        $this->purgeJobs();

        // Material fixture: one queued internal notification in Clinic A
        // (dynamic id) so the test proves an actual queued->sent mutation, not
        // merely successful handler construction.
        $dedupeA = 'notif-wiring-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_notifications')
            . ' (clinic_id, channel, template, payload_json, status, attempts, dedupe_key, scheduled_at, created_at)'
            . ' VALUES (%d, "internal", "appt_confirmed", "{}", "queued", 0, %s, %s, %s)',
            $this->clinicA,
            $dedupeA,
            $db->nowUtcSql(),
            $db->nowUtcSql()
        ));
        $notifId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $notifId, 'queued notification must be inserted');
        $notifBefore = $wpdb->get_row($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_notifications') . ' WHERE id = %d', $notifId), ARRAY_A);
        self::assertSame('queued', (string) ($notifBefore['status'] ?? ''), 'fixture notification must start queued');

        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('notif.dispatch', [], $now, 6, 1);
        self::assertGreaterThan(0, $jobId, 'enqueue must succeed');
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
            'On FIXED wiring, notif.dispatch job must be SUCCESS (scope-neutral worker). '
                . 'On current wiring it resolves Clinic-scoped settings at construction and becomes FAILED. '
                . 'Found status=' . $status
                . ' last_error=' . $lastError
                . ' tickResult=' . var_export($tickResult, true)
        );

        // Material mutation evidence: the queued row must have flipped to sent.
        $notifAfter = $wpdb->get_row($wpdb->prepare('SELECT status, sent_at FROM ' . $db->table('cpms_notifications') . ' WHERE id = %d', $notifId), ARRAY_A);
        self::assertSame('sent', (string) ($notifAfter['status'] ?? ''), 'queued notification must flip to sent after tick');
        self::assertNotNull($notifAfter['sent_at'], 'sent_at must be set after dispatch');
    }
}

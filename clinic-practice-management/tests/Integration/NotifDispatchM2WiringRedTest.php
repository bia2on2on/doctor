<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Settings\InstallationSettings;
use WP_UnitTestCase;

/**
 * M-2 notif.dispatch — scope-neutral worker construction/execution + retention.
 *
 * Proves that production wiring for notif.dispatch must be constructible and
 * executable WITHOUT request/user Clinic scope (a W installation-wide sweep),
 * while STILL executing the existing notification archive retention (purge).
 *
 * Pre-fix defect: App::dispatcher() registered notif.dispatch as
 *   (new NotifDispatchHandler(self::notificationService(), self::exportService()))($payload)
 * and App::notificationService() resolved the NotificationService lazily via
 *   self::settings() -> App::scope()
 * which, in a multi-Clinic install with no request/user scope, threw
 * CLINIC_SCOPE_REQUIRED before any dispatch work ran. With maxAttempts=1 the
 * job became FAILED, not SUCCESS.
 *
 * Expected RED (pre-fix): job status FAILED (scope dependency at construction)
 * Expected GREEN (post-fix): job status SUCCESS, queued->sent mutation, and
 *   archive retention still executes using installation-level notif.archive_days.
 *
 * Narrow contract:
 *   A scope-neutral W worker must be constructible/executable without
 *   request/user Clinic scope, and must NOT silently drop retention.
 *
 * PURGE / RETENTION:
 *   The retention window is provided by InstallationSettings (installation-
 *   level wp_options), NOT by per-Clinic cpms_settings. This test sets the
 *   installation option explicitly and asserts a purge/retain boundary without
 *   fixed Clinic IDs.
 *
 * STATIC-CACHE / FALSE GREEN (classification-relevant):
 *   App::notificationService() memoizes the NotificationService in a
 *   function-local `static $notifications` that cannot be reset from test
 *   code. In the shared long-running Integration process a prior test can
 *   memoize it with a valid Clinic and hide the construction-time scope
 *   dependency, producing a FALSE GREEN. The authoritative GREEN for this
 *   defect must therefore execute in a dedicated fresh PHP process (as each
 *   production worker is). A green shared-suite Integration result alone must
 *   NOT be treated as proof this RED is resolved.
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
     */
    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
            'settingsFactory', 'installationSettings', 'migrations', 'dispatcher',
            'providers', 'vault', 'smsService', 'licenseGate', 'visitService',
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
        delete_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS);
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
        delete_option(InstallationSettings::OPTION_NOTIF_ARCHIVE_DAYS);
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

    private function insertNotification(int $clinicId, string $status, string $createdAt, string $tag): int
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_notifications')
            . ' (clinic_id, channel, template, payload_json, status, attempts, dedupe_key, scheduled_at, created_at)'
            . ' VALUES (%d, "internal", "appt_confirmed", "{}", %s, 0, %s, %s, %s)',
            $clinicId,
            $status,
            'notif-wiring-' . $tag . '-' . bin2hex(random_bytes(4)),
            $createdAt,
            $createdAt
        ));

        return (int) $wpdb->insert_id;
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

        // Installation-level retention window (explicit, not per-Clinic).
        App::installationSettings()->setNotifArchiveDays(30);
        self::assertSame(30, App::installationSettings()->getNotifArchiveDays());

        $now = $db->nowUtcSql();
        $oldCutoff = gmdate('Y-m-d H:i:s', time() - 40 * 86400) . '.000';

        // Material fixtures:
        //  - a queued row in Clinic A (dispatch mutation target);
        //  - an old 'sent' row beyond the 30-day retention (must be purged);
        //  - a recent 'sent' row within retention (must be retained).
        $queuedId = $this->insertNotification($this->clinicA, 'queued', $now, 'queued');
        self::assertGreaterThan(0, $queuedId, 'queued notification must be inserted');
        $oldSentId = $this->insertNotification($this->clinicB, 'sent', $oldCutoff, 'oldsent');
        self::assertGreaterThan(0, $oldSentId, 'old sent notification must be inserted');
        $recentSentId = $this->insertNotification($this->clinicB, 'sent', $now, 'recentsent');
        self::assertGreaterThan(0, $recentSentId, 'recent sent notification must be inserted');

        $notifBefore = $wpdb->get_row($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_notifications') . ' WHERE id = %d', $queuedId), ARRAY_A);
        self::assertSame('queued', (string) ($notifBefore['status'] ?? ''), 'fixture notification must start queued');

        $queue = App::jobs();
        $nowDt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = $queue->enqueue('notif.dispatch', [], $nowDt, 6, 1);
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

        // Material dispatch mutation: queued row flips to sent with sent_at set.
        $notifAfter = $wpdb->get_row($wpdb->prepare('SELECT status, sent_at FROM ' . $db->table('cpms_notifications') . ' WHERE id = %d', $queuedId), ARRAY_A);
        self::assertSame('sent', (string) ($notifAfter['status'] ?? ''), 'queued notification must flip to sent after tick');
        self::assertNotNull($notifAfter['sent_at'], 'sent_at must be set after dispatch');

        // Retention still executed: old sent row removed, recent sent row retained.
        $oldCount = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE id = %d', $oldSentId));
        self::assertSame(0, $oldCount, 'old sent row beyond installation retention must be purged');
        $recentCount = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE id = %d', $recentSentId));
        self::assertSame(1, $recentCount, 'recent sent row within retention must be retained');
    }
}

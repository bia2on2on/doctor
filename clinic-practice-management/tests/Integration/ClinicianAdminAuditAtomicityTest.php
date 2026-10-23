<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\ObjectCacheDeleteRecorder;
use WP_UnitTestCase;

/**
 * D7 — ClinicianAdminPage transaction-boundary hygiene (real-MySQL fault).
 *
 * The second auditor block required that no exit/die/terminating redirect can
 * run inside a transaction closure. ClinicianAdminPage now raises a structured
 * ClinicianRedirectException inside the boundary and only performs the
 * wp_safe_redirect()/exit after CpmsDb has rolled back. With a real MySQL 1054
 * on cpms_audit_logs INSERT this suite proves:
 *
 *  - create (with a freshly provisioned WordPress account): the clinician row,
 *    the WP user and its membership all roll back, the production cache
 *    invalidation evicts the ghost identity's WP-owned keys (never a global
 *    flush), and the operator redirect still happens — strictly AFTER rollback;
 *  - toggle: the is_active change rolls back inside its own boundary and the
 *    redirect is likewise post-rollback;
 *  - authorized retries after fault removal commit with the mandatory audit
 *    row carrying the explicit trusted clinic_id.
 */
final class ClinicianAdminAuditAtomicityTest extends WP_UnitTestCase
{
    private const NOTICE_KEY = 'cpms_clinic_notice';

    private int $clinicId;
    private int $adminId;

    private ObjectCacheDeleteRecorder $cache;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    /** @var callable|null */
    private $redirectEscape = null;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->cache = ObjectCacheDeleteRecorder::install();

        $this->clinicId = $this->createClinic();
        $this->adminId = self::factory()->user->create(['role' => 'administrator']);
        cpms_test_seed_membership($this->adminId, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($this->adminId);
        delete_transient(self::NOTICE_KEY);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        $this->removeRedirectEscape();
        $this->cache->restore();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        delete_transient(self::NOTICE_KEY);
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    public function testCreateWithAccountAuditFailureRollsBackEverythingAndEvictsGhostCache(): void
    {
        $login = 'caaa_doc_' . bin2hex(random_bytes(3));
        $email = 'caaa-doc-' . bin2hex(random_bytes(4)) . '@example.test';

        $depthBefore = $this->savepointDepth();
        $this->installRedirectEscape();
        $this->failAuditInserts();

        $threw = false;
        try {
            \ClinicCore\Admin\ClinicianAdminPage::saveClinician();
        } catch (P3S6RedirectExitSimulatedException $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'the post-rollback operator redirect must still terminate the handler');
        self::assertSame(0, $this->clinicianCount(), 'no clinician row may survive the rolled-back create');
        self::assertFalse(get_user_by('login', $login), 'the provisioned WordPress account must roll back');
        self::assertFalse(get_user_by('email', $email), 'the provisioned email must not resolve without any test flush');
        self::assertSame(0, $this->auditCount('CLINICIAN_CREATED'));
        self::assertSame($depthBefore, $this->savepointDepth(), 'SAVEPOINT depth must be restored before the redirect');

        // Production cache rollback: exact WP-owned keys, never a global flush.
        self::assertSame(0, $this->cache->flushCount(), 'rollback must never global-flush the object cache');
        self::assertContains($login, $this->cache->deletedKeysForGroup('userlogins'), 'login slug key evicted after rollback');
        self::assertContains($email, $this->cache->deletedKeysForGroup('useremail'), 'email key evicted after rollback');
        self::assertNotEmpty($this->cache->deletedKeysForGroup('users'), 'users group entry evicted after rollback');
        foreach ($this->cache->deletedKeysForGroup('users') as $evictedId) {
            self::assertTrue($this->cache->deleted($evictedId, 'user_meta'), 'matching user_meta key evicted after rollback');
        }

        $notice = (string) get_transient(self::NOTICE_KEY);
        self::assertNotSame('', $notice, 'an operator error notice must be set on the post-rollback redirect');

        // Authorized retry with the fault removed commits clinician + account + audit.
        $loginOk = 'caaa_ok_' . bin2hex(random_bytes(3));
        $emailOk = 'caaa-ok-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->dispatchCreate($loginOk, $emailOk);

        self::assertSame(1, $this->clinicianCount());
        $user = get_user_by('login', $loginOk);
        self::assertNotFalse($user);
        self::assertNotNull($this->membershipFor((int) $user->ID));
        self::assertSame(1, $this->auditCount('CLINICIAN_CREATED'));
        self::assertSame($this->clinicId, $this->latestAuditClinicId('CLINICIAN_CREATED'), 'trusted clinic_id must be explicit');
    }

    public function testToggleAuditFailureRollsBackStatusAndRedirectsOnlyAfterRollback(): void
    {
        $doctorUser = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        cpms_test_seed_membership($doctorUser, $this->clinicId, RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->insertClinician($doctorUser, 'Dr Toggle Atomicity');

        $depthBefore = $this->savepointDepth();
        $this->installRedirectEscape();
        $this->failAuditInserts();

        $threw = false;
        try {
            \ClinicCore\Admin\ClinicianAdminPage::toggleClinician();
        } catch (P3S6RedirectExitSimulatedException $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'the zero-row/audit failure path must redirect after the rollback, never exit inside the tx');
        self::assertSame(1, (int) App::db()->fetchValue(
            'SELECT is_active FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        ), 'the status change must have been rolled back');
        self::assertSame(0, $this->auditCount('CLINICIAN_STATUS_CHANGED'));
        self::assertSame($depthBefore, $this->savepointDepth());

        // Authorized retry flips the status durably with its mandatory audit.
        $this->dispatchToggle($clinicianId);
        self::assertSame(0, (int) App::db()->fetchValue(
            'SELECT is_active FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        ));
        self::assertSame(1, $this->auditCount('CLINICIAN_STATUS_CHANGED'));
        self::assertSame($this->clinicId, $this->latestAuditClinicId('CLINICIAN_STATUS_CHANGED'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function dispatchCreate(string $login, string $email): void
    {
        $_POST = [
            'clinic_id' => (string) $this->clinicId,
            'full_name' => 'Dr ' . $login,
            'create_account' => '1',
            'account_username' => $login,
            'account_email' => $email,
            'account_password' => 'StrongPass123',
        ];
        $_REQUEST['_wpnonce'] = wp_create_nonce('cpms_clinician_save');
        delete_transient(self::NOTICE_KEY);

        $threw = false;
        try {
            \ClinicCore\Admin\ClinicianAdminPage::saveClinician();
        } catch (P3S6RedirectExitSimulatedException $e) {
            $threw = true;
        }
        self::assertTrue($threw, 'the successful handler still ends in its normal redirect');
        self::assertStringNotContainsString('خطا', (string) get_transient(self::NOTICE_KEY));
        $_POST = [];
        unset($_REQUEST['_wpnonce']);
    }

    private function dispatchToggle(int $clinicianId): void
    {
        $_GET = [
            'clinician_id' => (string) $clinicianId,
            'clinic_id' => (string) $this->clinicId,
        ];
        $_REQUEST['_wpnonce'] = wp_create_nonce('cpms_clinician_toggle_' . $clinicianId);
        delete_transient(self::NOTICE_KEY);

        $threw = false;
        try {
            \ClinicCore\Admin\ClinicianAdminPage::toggleClinician();
        } catch (P3S6RedirectExitSimulatedException $e) {
            $threw = true;
        }
        self::assertTrue($threw);
        $_GET = [];
        unset($_REQUEST['_wpnonce']);
    }

    private function installRedirectEscape(): void
    {
        $escape = static function (string $url): string {
            throw new P3S6RedirectExitSimulatedException('wp_redirect intercepted (exit simulated): ' . $url);
        };
        add_filter('wp_redirect', $escape, 1);
        $this->redirectEscape = $escape;
    }

    private function removeRedirectEscape(): void
    {
        if ($this->redirectEscape !== null) {
            remove_filter('wp_redirect', $this->redirectEscape, 1);
            $this->redirectEscape = null;
        }
    }

    private function insertClinician(int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                 (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicId,
            $name,
            $wpUserId,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function createClinic(): int
    {
        global $wpdb;
        $organizationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'organization fixture must exist');

        $slug = 'clinician-atomicity-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Clinician atomicity ' . $slug,
                $slug,
                'UTC',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
    }

    private function membershipFor(int $userId): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
            [$this->clinicId, $userId]
        );

        return is_array($row) ? $row : null;
    }

    private function clinicianCount(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE clinic_id = %d',
            [$this->clinicId]
        );
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND clinic_id = %d',
            [$action, $this->clinicId]
        );
    }

    private function latestAuditClinicId(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT clinic_id FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s ORDER BY id DESC LIMIT 1',
            [$action]
        );
    }

    private function savepointDepth(): int
    {
        return is_array($GLOBALS['__cpms_sp_stack'] ?? null) ? count($GLOBALS['__cpms_sp_stack']) : 0;
    }

    private function failAuditInserts(): void
    {
        global $wpdb;

        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1
                && str_contains($m[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $m[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };

        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ($this->auditFault === null) {
            return;
        }

        remove_filter('query', $this->auditFault, 5);
        $this->auditFault = null;

        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }
}

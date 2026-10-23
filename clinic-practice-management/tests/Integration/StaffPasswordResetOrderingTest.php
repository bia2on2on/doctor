<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D7 — password-reset side effects must follow the durable commit.
 *
 * The previous implementation called retrieve_password() INSIDE the
 * transaction boundary: WordPress wrote the reset-key user meta and sent the
 * notification email before the boundary committed, so a rolled-back audit
 * could leave a working un-audited reset link in an inbox.
 *
 * The production ordering is now:
 *   1. get_password_reset_key() (meta write) + mandatory audit in ONE boundary;
 *   2. the core-shaped notification email ONLY after the durable commit.
 *
 * This suite pins both directions with a real MySQL 1054 audit fault and a
 * wp_mail interceptor: no email on rollback, exactly one email (carrying the
 * persisted key) on success, and an honest failure when delivery itself fails.
 */
final class StaffPasswordResetOrderingTest extends WP_UnitTestCase
{
    private int $clinicId;
    private int $managerId;
    private int $targetId;

    /** @var list<array{to:string, subject:string, message:string}> */
    private array $sentMail = [];

    /** @var callable|null */
    private $mailInterceptor;

    private bool $mailResult = true;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->clinicId = $this->createClinic();
        $this->managerId = self::factory()->user->create(['role' => 'administrator']);
        cpms_test_seed_membership($this->managerId, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER);

        $this->targetId = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]);
        cpms_test_seed_membership($this->targetId, $this->clinicId, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($this->managerId);
        $this->installMailInterceptor();
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        $this->removeMailInterceptor();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAuditFailureRollsBackResetKeyAndNeverSendsTheEmail(): void
    {
        $depthBefore = $this->savepointDepth();
        $this->failAuditInserts();

        $result = StaffManagementPage::initiatePasswordReset($this->targetId, $this->managerId, $this->clinicId);
        $this->restoreAuditInserts();

        self::assertNotSame('', $result['error'], 'a failed mandatory reset audit must surface, never report false success');
        self::assertSame([], $this->sentMail, 'no reset email may be sent when the boundary rolled back');
        self::assertSame('', $this->activationKey(), 'the reset-key meta must roll back with its audit — no working un-audited link');
        self::assertSame(0, $this->auditCount('STAFF_PASSWORD_RESET_INITIATED'));
        self::assertSame($depthBefore, $this->savepointDepth(), 'SAVEPOINT depth must be restored');
    }

    public function testEmailIsSentOnlyAfterCommitAndCarriesThePersistedKey(): void
    {
        $result = StaffManagementPage::initiatePasswordReset($this->targetId, $this->managerId, $this->clinicId);

        self::assertSame('', $result['error'], 'the committed reset must succeed');
        self::assertCount(1, $this->sentMail, 'exactly one notification email is sent post-commit');
        self::assertSame(1, $this->auditCount('STAFF_PASSWORD_RESET_INITIATED'));
        self::assertSame($this->clinicId, $this->latestAuditClinicId('STAFF_PASSWORD_RESET_INITIATED'), 'trusted clinic_id must be explicit');

        $persistedKey = $this->activationKey();
        self::assertNotSame('', $persistedKey, 'the reset key must be durably persisted');
        // WordPress stores "epoch:key"; the email reset URL carries the same key.
        $rawKey = substr($persistedKey, (int) strpos($persistedKey, ':') + 1);
        self::assertStringContainsString($rawKey, $this->sentMail[0]['message'], 'the email must reference the committed key, never a rolled-back one');
        self::assertStringContainsString((string) get_userdata($this->targetId)->user_login, $this->sentMail[0]['message']);
    }

    public function testDeliveryFailureIsReportedHonestlyWhileKeyAndAuditStayDurable(): void
    {
        $this->mailResult = false;

        $result = StaffManagementPage::initiatePasswordReset($this->targetId, $this->managerId, $this->clinicId);

        self::assertNotSame('', $result['error'], 'a mail transport failure must not be reported as success');
        self::assertNotSame('', $this->activationKey(), 'the committed key stays durable after a delivery failure');
        self::assertSame(1, $this->auditCount('STAFF_PASSWORD_RESET_INITIATED'), 'the durable audit must not be retro-rolled-back by a mail failure');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function activationKey(): string
    {
        global $wpdb;
        $metaKey = $wpdb->get_blog_prefix() . 'user_activation_key';

        return (string) get_user_meta($this->targetId, $metaKey, true);
    }

    private function installMailInterceptor(): void
    {
        $this->sentMail = [];
        $interceptor = function (array $atts) {
            $this->sentMail[] = [
                'to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'],
                'subject' => (string) $atts['subject'],
                'message' => (string) $atts['message'],
            ];

            return $this->mailResult ? $atts : false;
        };
        add_filter('wp_mail', $interceptor, 1);
        $this->mailInterceptor = $interceptor;
    }

    private function removeMailInterceptor(): void
    {
        if ($this->mailInterceptor !== null) {
            remove_filter('wp_mail', $this->mailInterceptor, 1);
            $this->mailInterceptor = null;
        }
        $this->mailResult = true;
    }

    private function createClinic(): int
    {
        global $wpdb;

        $organizationId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_organizations ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'organization fixture must exist');

        $slug = 'reset-ordering-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Reset ordering ' . $slug,
                $slug,
                'UTC',
                $now,
                $now
            )
        );

        return (int) $wpdb->insert_id;
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

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinic\ClinicProfileException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D7 — ClinicProfileService effective atomicity (real-MySQL fault injection).
 *
 * The previous implementation performed the profile UPDATE inside a boundary
 * but wrote the mandatory CLINIC_PROFILE_UPDATED audit only afterwards,
 * swallowing any audit failure in a best-effort catch — an un-audited profile
 * change could therefore durably commit while the caller still saw success.
 *
 * Required contract now asserted with a real MySQL 1054 on cpms_audit_logs:
 *  1. the profile UPDATE and its mandatory audit share ONE boundary, so a
 *     failed audit rolls the name/address/phone change back;
 *  2. the failure surfaces as ClinicProfileException (never false success);
 *  3. preserved columns and SAVEPOINT depth are untouched;
 *  4. the authorized retry commits both rows together, with the audit row
 *     carrying the explicit trusted clinic_id (no ambient-scope reliance).
 */
final class ClinicProfileAuditAtomicityTest extends WP_UnitTestCase
{
    private const OLD_NAME = 'کلینیک پیش از تغییر';
    private const NEW_NAME = 'کلینیک پس از تغییر';

    private int $clinicId;
    private int $managerId;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgSlug = 'cpaa-org-' . bin2hex(random_bytes(3));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'Org ' . $orgSlug,
            $orgSlug,
            $now,
            $now
        ));
        $orgId = (int) $wpdb->insert_id;

        $clinicSlug = 'cpaa-clinic-' . bin2hex(random_bytes(3));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, address, phone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            random_int(64000, 65000),
            $orgId,
            self::OLD_NAME,
            $clinicSlug,
            'Asia/Tehran',
            'old address',
            '02100000000',
            $now,
            $now
        ));
        $this->clinicId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $clinicSlug
        ));

        $this->managerId = (int) wp_create_user('cpaa_mgr_' . bin2hex(random_bytes(3)), 'pass-12345', 'cpaa@test.local');
        $user = get_userdata($this->managerId);
        if ($user !== false) {
            $user->set_role('administrator');
        }
        cpms_test_seed_membership($this->managerId, $this->clinicId, RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($this->managerId);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAuditFailureRollsBackProfileUpdateAndSurfacesAsDomainFailure(): void
    {
        $depthBefore = $this->savepointDepth();

        $this->failAuditInserts();
        $threw = false;
        try {
            App::clinicProfileService()->updateProfile($this->managerId, $this->clinicId, [
                'name' => self::NEW_NAME,
                'address' => 'new address',
                'phone' => '02111111111',
            ]);
        } catch (ClinicProfileException $e) {
            $threw = true;
            self::assertSame(ClinicProfileException::QUERY_FAILED, $e->getErrorCode());
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory profile audit must surface, never return false success');
        $row = App::db()->fetchRow('SELECT name, address, phone FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$this->clinicId]);
        self::assertSame(self::OLD_NAME, (string) $row['name'], 'the profile update must roll back with its failed audit');
        self::assertSame('old address', (string) $row['address']);
        self::assertSame('02100000000', (string) $row['phone']);
        self::assertSame(0, $this->auditCount('CLINIC_PROFILE_UPDATED'), 'no partial audit evidence may survive');
        self::assertSame($depthBefore, $this->savepointDepth(), 'SAVEPOINT depth must be restored');

        // Authorized retry: mutation + audit commit together with explicit attribution.
        $result = App::clinicProfileService()->updateProfile($this->managerId, $this->clinicId, [
            'name' => self::NEW_NAME,
            'address' => 'new address',
            'phone' => '02111111111',
        ]);
        self::assertSame(self::NEW_NAME, (string) $result['name']);
        self::assertSame(1, $this->auditCount('CLINIC_PROFILE_UPDATED'));
        self::assertSame($this->clinicId, $this->latestAuditClinicId('CLINIC_PROFILE_UPDATED'), 'trusted clinic_id must be explicit, never ambient');
        self::assertSame($depthBefore, $this->savepointDepth());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
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
}

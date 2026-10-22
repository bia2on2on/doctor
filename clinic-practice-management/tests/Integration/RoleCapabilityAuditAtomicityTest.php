<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\RoleCapabilitiesPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * D6 — role-capability override audit atomicity (real-MySQL fault injection).
 *
 * PROVEN DEFECT this suite guards against: RoleCapabilitiesPage persisted the
 * cpms_role_caps_override option AND rewrote the wp_user_roles role registry
 * before the mandatory ROLE_PERMISSION_* audit event. A failed audit INSERT
 * therefore left the capability change durably committed without its audit row
 * — and, because option/role object caches are in-memory, the effective
 * permission change also survived even where a MySQL rollback occurred.
 *
 * REQUIRED CONTRACT:
 *  1. the multi-role matrix and every mandatory audit event share one
 *     transaction boundary — an audit failure rolls ALL role changes back;
 *  2. after the failure BOTH durable state (option + wp_user_roles row) and
 *     effective in-memory permissions (WP_Roles and a concrete WP_User) revert
 *     to the pre-request state — no privilege residue, no false success;
 *  3. a partial multi-role request cannot leave role A changed when role B's
 *     audit failed (all-or-nothing);
 *  4. once the fault is removed, the same authorized change succeeds and audits.
 *
 * Fault injection is a real MySQL 1054 produced by rewriting only INSERTs into
 * cpms_audit_logs through the documented wpdb 'query' filter.
 */
final class RoleCapabilityAuditAtomicityTest extends WP_UnitTestCase
{
    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminId);
        delete_option(RolesAndCapabilities::OPTION_OVERRIDE);
        RolesAndCapabilities::register();
        wp_cache_flush();
        wp_roles()->for_site();
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        delete_option(RolesAndCapabilities::OPTION_OVERRIDE);
        RolesAndCapabilities::register();
        wp_cache_flush();
        wp_roles()->for_site();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testAuditFailureRollsBackMultiRoleDurableAndEffectiveCapabilities(): void
    {
        // Full matrix mirroring the admin form: untouched roles keep their
        // templates; secretary gains EXPORT and manager gains REPORT_READ.
        $input = [
            RolesAndCapabilities::ROLE_SECRETARY => array_merge(
                RolesAndCapabilities::SECRETARY_CAPS,
                [RolesAndCapabilities::EXPORT]
            ),
            RolesAndCapabilities::ROLE_DOCTOR => RolesAndCapabilities::DOCTOR_CAPS,
            RolesAndCapabilities::ROLE_ACCOUNTANT => RolesAndCapabilities::ACCOUNTANT_CAPS,
            RolesAndCapabilities::ROLE_MANAGER => array_merge(
                RolesAndCapabilities::MANAGER_CAPS,
                [RolesAndCapabilities::REPORT_READ]
            ),
        ];

        $secretaryUser = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_SECRETARY]);
        $managerUser = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_MANAGER]);
        $doctorUser = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_DOCTOR]);
        $doctorCapsBefore = array_keys(array_filter(RolesAndCapabilities::capsMap(RolesAndCapabilities::ROLE_DOCTOR)));

        $this->failAuditInserts();
        $threw = false;
        try {
            RoleCapabilitiesPage::apply_capability_matrix($input, $this->adminId);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw, 'a failed mandatory audit must propagate, never report false success');

        // Durable state: no override option persisted and the role registry row
        // still reflects the templates (neither cap granted).
        $stored = get_option(RolesAndCapabilities::OPTION_OVERRIDE, []);
        self::assertSame([], is_array($stored) ? $stored : [], 'no override may survive the rolled-back request');
        global $wpdb;
        $durable_roles = get_option( $wpdb->prefix . 'user_roles', [] );
        self::assertArrayNotHasKey(
            RolesAndCapabilities::EXPORT,
            $durable_roles[RolesAndCapabilities::ROLE_SECRETARY]['capabilities'] ?? [],
            'the durable wp_user_roles registry must not retain the rolled-back grant'
        );
        self::assertArrayNotHasKey(
            RolesAndCapabilities::REPORT_READ,
            $durable_roles[RolesAndCapabilities::ROLE_MANAGER]['capabilities'] ?? [],
            'the durable wp_user_roles registry must not retain the rolled-back grant'
        );
        self::assertFalse(get_role(RolesAndCapabilities::ROLE_SECRETARY)->has_cap(RolesAndCapabilities::EXPORT));
        self::assertFalse(get_role(RolesAndCapabilities::ROLE_MANAGER)->has_cap(RolesAndCapabilities::REPORT_READ));

        // Effective permissions: fresh user capability resolution must match the
        // rolled-back durable state (cache cannot retain the granted cap).
        wp_cache_flush();
        wp_roles()->for_site();
        self::assertFalse(user_can($secretaryUser, RolesAndCapabilities::EXPORT), 'effective secretary permission must roll back');
        self::assertFalse(user_can($managerUser, RolesAndCapabilities::REPORT_READ), 'effective manager permission must roll back');
        // An untouched role processed earlier in the same all-or-nothing request
        // must also be byte-identical to its template (no partial multi-role write).
        foreach ($doctorCapsBefore as $cap) {
            self::assertTrue(user_can($doctorUser, $cap), "untouched doctor cap {$cap} must survive the rollback");
        }

        // No partial multi-role audit/change survived.
        self::assertSame(0, $this->auditCount('ROLE_PERMISSION_CHANGED'));

        // Authorized retry after the fault is removed succeeds and is audited.
        $report = RoleCapabilitiesPage::apply_capability_matrix($input, $this->adminId);
        self::assertNotEmpty($report);
        self::assertTrue(get_role(RolesAndCapabilities::ROLE_SECRETARY)->has_cap(RolesAndCapabilities::EXPORT));
        self::assertTrue(user_can($secretaryUser, RolesAndCapabilities::EXPORT));
        self::assertTrue(get_role(RolesAndCapabilities::ROLE_MANAGER)->has_cap(RolesAndCapabilities::REPORT_READ));
        self::assertSame(2, $this->auditCount('ROLE_PERMISSION_CHANGED'));
    }

    public function testAuditFailureDuringTemplateResetRestoresPriorCapabilities(): void
    {
        // Establish a deliberate override first (durable + effective).
        $caps = array_merge(RolesAndCapabilities::SECRETARY_CAPS, [RolesAndCapabilities::EXPORT]);
        self::assertTrue(RolesAndCapabilities::setRoleCaps(RolesAndCapabilities::ROLE_SECRETARY, $caps));
        self::assertTrue(get_role(RolesAndCapabilities::ROLE_SECRETARY)->has_cap(RolesAndCapabilities::EXPORT));
        $before = get_option(RolesAndCapabilities::OPTION_OVERRIDE);

        $this->failAuditInserts();
        $threw = false;
        try {
            RoleCapabilitiesPage::reset_role_to_template(RolesAndCapabilities::ROLE_SECRETARY);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->restoreAuditInserts();

        self::assertTrue($threw);
        // Durable override must be restored, not deleted by the rolled-back reset.
        self::assertEquals($before, get_option(RolesAndCapabilities::OPTION_OVERRIDE));
        wp_cache_flush();
        wp_roles()->for_site();
        self::assertTrue(
            get_role(RolesAndCapabilities::ROLE_SECRETARY)->has_cap(RolesAndCapabilities::EXPORT),
            'the pre-reset effective capability must survive the failed reset'
        );
        self::assertSame(0, $this->auditCount('ROLE_PERMISSION_RESET'));
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
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

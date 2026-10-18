<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\OrganizationManagerBootstrapPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use RuntimeException;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/** Database-backed tests for the Owner-approved, narrow initial-manager bootstrap. */
final class OrganizationManagerBootstrapTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private int $organization_id = 0;
    private int $admin_id = 0;
    private int $target_id = 0;
    private int $clinic_membership_id = 0;
    private bool $original_suppression = false;
    /** @var callable|null */
    private $audit_fault = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Migration 0028 creates FOREIGN KEY bearing tables; the WP test suite
        // rewrites CREATE TABLE into CREATE TEMPORARY TABLE while the test
        // transaction is open and InnoDB refuses a FOREIGN KEY on a temporary
        // table (errno 150). Run the migration with those rewrites suspended.
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        App::resetScope();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->target_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->organization_id = $this->create_organization('bootstrap');
        wp_set_current_user($this->admin_id);
    }

    protected function tearDown(): void
    {
        $this->restore_audit_inserts();
        if ($this->clinic_membership_id > 0) {
            App::db()->query(
                'DELETE FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE id = %d',
                [$this->clinic_membership_id]
            );
        }
        if ($this->organization_id > 0) {
            App::db()->query(
                'DELETE FROM ' . App::db()->table('cpms_organizations') . ' WHERE id = %d',
                [$this->organization_id]
            );
        }
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    public function testAdministratorBootstrapsOnlySelectedUserAndCreatesValidAuditedOrganizationMembership(): void
    {
        $before_roles = (array) get_userdata($this->target_id)->roles;
        $result = App::organization_clinic_provisioning_service()->bootstrap_owner(
            $this->admin_id,
            $this->organization_id,
            $this->target_id
        );

        self::assertFalse($result['noop']);
        self::assertGreaterThan(0, $result['membership_id']);
        self::assertGreaterThan(0, (int) $result['audit_id']);
        $membership = $this->membership($this->organization_id, $this->target_id);
        self::assertNotNull($membership);
        self::assertSame('organization_manager', $membership['role_key']);
        self::assertSame('active', $membership['status']);
        self::assertSame($this->admin_id, (int) $membership['created_by_wp_user_id']);
        self::assertSame($before_roles, (array) get_userdata($this->target_id)->roles, 'bootstrap must not mutate WordPress roles');
        self::assertSame(0, $this->clinic_membership_count($this->target_id), 'Organization authority creates no Clinic membership');
        self::assertSame(0, $this->organization_override_count((int) $result['membership_id']), 'bootstrap grants no capability overrides');

        $audit = $this->audit_row((int) $result['audit_id']);
        self::assertNotNull($audit);
        self::assertSame('ORGANIZATION_MANAGER_BOOTSTRAPPED', $audit['action']);
        self::assertSame('organization', $audit['resource_type']);
        self::assertSame($this->organization_id, (int) $audit['resource_id']);
        self::assertNull($audit['clinic_id'], 'bootstrap is Organization-scoped, not Clinic-scoped');
        self::assertSame($this->admin_id, (int) $audit['actor_wp_user_id']);
        self::assertTrue(App::audit()->verifyChain(1000)['ok'], 'bootstrap event must preserve hash-chain integrity');
    }

    public function testNonAdministratorAndMismatchedActorAreDeniedWithoutMutation(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        try {
            App::organization_clinic_provisioning_service()->bootstrap_owner(
                $subscriber_id,
                $this->organization_id,
                $this->target_id
            );
            self::fail('non-Administrator bootstrap must fail');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('denied', $exception->getMessage());
        }

        wp_set_current_user($this->admin_id);
        try {
            App::organization_clinic_provisioning_service()->bootstrap_owner(
                $subscriber_id,
                $this->organization_id,
                $this->target_id
            );
            self::fail('forged actor id must fail');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('denied', $exception->getMessage());
        }
        self::assertNull($this->membership($this->organization_id, $this->target_id));
    }

    public function testForgedOrganizationAndNonexistentTargetAreDenied(): void
    {
        foreach ([[PHP_INT_MAX, $this->target_id], [$this->organization_id, PHP_INT_MAX]] as [$organization_id, $target_id]) {
            try {
                App::organization_clinic_provisioning_service()->bootstrap_owner(
                    $this->admin_id,
                    $organization_id,
                    $target_id
                );
                self::fail('forged Organization or user must fail');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('denied', $exception->getMessage());
            }
        }
        self::assertNull($this->membership($this->organization_id, $this->target_id));
    }

    public function testSuspendedOrganizationAndConflictingTargetMembershipAreRejected(): void
    {
        App::db()->update('cpms_organizations', ['status' => 'suspended'], ['id' => $this->organization_id]);
        $this->assert_bootstrap_denied($this->organization_id, $this->target_id);
        self::assertNull($this->membership($this->organization_id, $this->target_id));

        App::db()->update('cpms_organizations', ['status' => 'active'], ['id' => $this->organization_id]);
        $now = App::db()->nowUtcSql();
        App::db()->insert('cpms_organization_memberships', [
            'organization_id' => $this->organization_id,
            'wp_user_id' => $this->target_id,
            'role_key' => 'organization_manager',
            'status' => 'suspended',
            'created_by_wp_user_id' => $this->admin_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assert_bootstrap_denied($this->organization_id, $this->target_id);
        self::assertSame('suspended', $this->membership($this->organization_id, $this->target_id)['status']);
    }

    public function testRepeatedIdenticalBootstrapIsIdempotentAndConflictingTargetCannotReplaceManager(): void
    {
        $service = App::organization_clinic_provisioning_service();
        $first = $service->bootstrap_owner($this->admin_id, $this->organization_id, $this->target_id);
        $audit_count = $this->bootstrap_audit_count();
        $repeat = $service->bootstrap_owner($this->admin_id, $this->organization_id, $this->target_id);
        self::assertTrue($repeat['noop']);
        self::assertSame($first['membership_id'], $repeat['membership_id']);
        self::assertNull($repeat['audit_id']);
        self::assertSame($audit_count, $this->bootstrap_audit_count(), 'idempotent retries must not append a duplicate audit');

        $other_user_id = self::factory()->user->create(['role' => 'subscriber']);
        $this->assert_bootstrap_denied($this->organization_id, $other_user_id);
        self::assertNull($this->membership($this->organization_id, $other_user_id));
    }

    public function testConcurrentCompetingInitialManagerRequestsCreateExactlyOneMembershipAndAudit(): void
    {
        $other_target_id = self::factory()->user->create(['role' => 'subscriber']);
        $directory = sys_get_temp_dir() . '/cpms-org-bootstrap-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $go_file = $directory . '/go';
        $workers = [];
        try {
            $workers[] = $this->launch_bootstrap_worker($this->target_id, $directory . '/ready-a', $go_file);
            $workers[] = $this->launch_bootstrap_worker($other_target_id, $directory . '/ready-b', $go_file);
            $this->wait_for_file($directory . '/ready-a');
            $this->wait_for_file($directory . '/ready-b');
            self::assertNotFalse(file_put_contents($go_file, 'go', LOCK_EX));

            $outcomes = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exit_code = proc_close($worker['process']);
                self::assertSame('', trim((string) $stderr), 'bootstrap worker must not leak diagnostics');
                self::assertSame(0, $exit_code, 'worker must finish with a sanitized protocol result');
                $outcomes[] = trim((string) $stdout);
            }
            self::assertCount(1, array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'RESULT:CREATED'));
            self::assertCount(1, array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'RESULT:CONFLICT'));
            self::assertSame(1, (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_organization_memberships')
                . ' WHERE organization_id = %d AND role_key = %s AND status = %s',
                [$this->organization_id, 'organization_manager', 'active']
            ));
            self::assertSame(1, $this->bootstrap_audit_count(), 'only the winning initial assignment is audited');
            self::assertTrue(App::audit()->verifyChain(1000)['ok']);
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                }
                foreach ([1, 2] as $pipe_index) {
                    if (isset($worker['pipes'][$pipe_index]) && is_resource($worker['pipes'][$pipe_index])) {
                        fclose($worker['pipes'][$pipe_index]);
                    }
                }
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testFailedHashChainedAuditRollsBackTheInitialManagerMembership(): void
    {
        $this->fail_audit_inserts();
        try {
            App::organization_clinic_provisioning_service()->bootstrap_owner(
                $this->admin_id,
                $this->organization_id,
                $this->target_id
            );
            self::fail('audit persistence failure must fail bootstrap');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('audit', strtolower($exception->getMessage()));
        } finally {
            $this->restore_audit_inserts();
        }
        self::assertNull($this->membership($this->organization_id, $this->target_id), 'membership and audit share one transaction');
        self::assertSame(0, $this->bootstrap_audit_count());
    }

    public function testBootstrapIsOrganizationIsolatedAndAdministratorGetsNoProvisioningBypass(): void
    {
        $other_organization_id = $this->create_organization('isolated');
        $clinic_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id LIMIT 1'
        );
        self::assertGreaterThan(0, $clinic_id, 'a real Clinic fixture is required for the DENY boundary');
        $this->clinic_membership_id = App::membership_service()->create_membership(
            $clinic_id,
            $this->admin_id,
            RolesAndCapabilities::ROLE_MANAGER
        );
        $clinic_memberships = new MembershipRepository(App::db());
        $clinic_memberships->set_capability($this->clinic_membership_id, 'cpms_medical_read', 'deny');
        $clinic_memberships->set_capability($this->clinic_membership_id, 'cpms_finance_read', 'deny');
        self::assertFalse(App::authorization_service()->can($this->admin_id, $clinic_id, 'cpms_medical_read'));
        self::assertFalse(App::authorization_service()->can($this->admin_id, $clinic_id, 'cpms_finance_read'));
        try {
            App::organization_clinic_provisioning_service()->bootstrap_owner(
                $this->admin_id,
                $this->organization_id,
                $this->target_id
            );
            self::assertFalse(App::authorization_service()->can($this->admin_id, $clinic_id, 'cpms_medical_read'));
            self::assertFalse(App::authorization_service()->can($this->admin_id, $clinic_id, 'cpms_finance_read'));
            self::assertNull($this->membership($other_organization_id, $this->target_id));

            try {
                App::organization_clinic_provisioning_service()->provision(
                    $this->admin_id,
                    $this->organization_id,
                    'Administrator bypass attempt',
                    'admin-bypass-' . bin2hex(random_bytes(3)),
                    'Europe/Copenhagen'
                );
                self::fail('Administrator status must not grant ordinary provisioning authority');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('denied', $exception->getMessage());
            }
            self::assertSame(0, (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics') . ' WHERE organization_id = %d',
                [$this->organization_id]
            ));
        } finally {
            App::db()->query(
                'DELETE FROM ' . App::db()->table('cpms_organizations') . ' WHERE id = %d',
                [$other_organization_id]
            );
        }
    }

    public function testOrganizationManagerDenyOverridesGrantAndWordPressAdministratorStatus(): void
    {
        App::organization_clinic_provisioning_service()->bootstrap_owner(
            $this->admin_id,
            $this->organization_id,
            $this->target_id
        );
        $membership = $this->membership($this->organization_id, $this->target_id);
        self::assertNotNull($membership);
        App::db()->insert('cpms_organization_membership_capabilities', [
            'membership_id' => (int) $membership['id'],
            'capability' => 'cpms_org_membership_manage',
            'effect' => 'deny',
            'created_by_wp_user_id' => $this->admin_id,
            'created_at' => App::db()->nowUtcSql(),
        ]);

        wp_set_current_user($this->target_id);
        try {
            App::organization_clinic_provisioning_service()->set_manager(
                $this->target_id,
                $this->organization_id,
                $this->admin_id,
                true
            );
            self::fail('Organization DENY must override the manager preset');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('denied', $exception->getMessage());
        }
        self::assertNull($this->membership($this->organization_id, $this->admin_id));

        // A WP Administrator not explicitly granted Organization membership
        // cannot use Administrator status to bypass the same operation.
        wp_set_current_user($this->admin_id);
        try {
            App::organization_clinic_provisioning_service()->set_manager(
                $this->admin_id,
                $this->organization_id,
                $this->admin_id,
                true
            );
            self::fail('Administrator status must not bypass Organization membership authority');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('denied', $exception->getMessage());
        }
    }

    public function testOrganizationManagerDelegationKeepsLastActiveManagerProtection(): void
    {
        App::organization_clinic_provisioning_service()->bootstrap_owner(
            $this->admin_id,
            $this->organization_id,
            $this->target_id
        );
        $second_manager_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($this->target_id);
        $service = App::organization_clinic_provisioning_service();
        $service->set_manager($this->target_id, $this->organization_id, $second_manager_id, true);
        $service->set_manager($this->target_id, $this->organization_id, $second_manager_id, false);
        try {
            $service->set_manager($this->target_id, $this->organization_id, $this->target_id, false);
            self::fail('the final active Organization Manager must remain protected');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('last active', $exception->getMessage());
        }
        self::assertTrue(App::audit()->verifyChain(1000)['ok']);
    }

    public function testNonceAndExplicitConfirmationAreRequiredByAdminRequestBoundary(): void
    {
        $valid = [
            '_wpnonce' => wp_create_nonce('cpms_bootstrap_organization_manager'),
            'organization_id' => (string) $this->organization_id,
            'target_user_id' => (string) $this->target_id,
            'confirm_bootstrap' => '1',
        ];
        self::assertTrue(OrganizationManagerBootstrapPage::valid_bootstrap_request($this->admin_id, $valid));
        $invalid_nonce = $valid;
        $invalid_nonce['_wpnonce'] = 'forged';
        self::assertFalse(OrganizationManagerBootstrapPage::valid_bootstrap_request($this->admin_id, $invalid_nonce));
        $unconfirmed = $valid;
        $unconfirmed['confirm_bootstrap'] = '0';
        self::assertFalse(OrganizationManagerBootstrapPage::valid_bootstrap_request($this->admin_id, $unconfirmed));
        $array_identifier = $valid;
        $array_identifier['organization_id'] = [$this->organization_id];
        self::assertFalse(OrganizationManagerBootstrapPage::valid_bootstrap_request($this->admin_id, $array_identifier));
        self::assertNull($this->membership($this->organization_id, $this->target_id), 'request validation is write-free');
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function launch_bootstrap_worker(int $target_user_id, string $ready_file, string $go_file): array
    {
        $tests_dir = rtrim((string) getenv('WP_TESTS_DIR'), '/');
        $environment = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'WP_TESTS_DIR' => $tests_dir,
            'WP_TESTS_CONFIG_FILE_PATH' => (string) (getenv('WP_TESTS_CONFIG_FILE_PATH') ?: $tests_dir . '/wp-tests-config.php'),
            'WP_CORE_DIR' => rtrim(ABSPATH, '/'),
            'CPMS_BOOTSTRAP_ACTOR_ID' => (string) $this->admin_id,
            'CPMS_BOOTSTRAP_ORGANIZATION_ID' => (string) $this->organization_id,
            'CPMS_BOOTSTRAP_TARGET_ID' => (string) $target_user_id,
            'CPMS_BOOTSTRAP_READY_FILE' => $ready_file,
            'CPMS_BOOTSTRAP_GO_FILE' => $go_file,
        ];
        $command = [PHP_BINARY, dirname(__DIR__, 2) . '/tests/bin/organization-manager-bootstrap-race-worker.php'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process, 'independent bootstrap worker must start');
        fclose($pipes[0]);
        return ['process' => $process, 'pipes' => $pipes];
    }

    private function wait_for_file(string $path): void
    {
        $deadline = microtime(true) + 15.0;
        while ( ! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path, 'independent bootstrap worker did not reach the synchronized start gate');
    }

    private function create_organization(string $prefix): int
    {
        $slug = 'org-bootstrap-' . $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $inserted = App::db()->insert('cpms_organizations', [
            'name' => 'Bootstrap test ' . $slug,
            'slug' => $slug,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::assertNotFalse($inserted);
        return App::db()->wpdb_last_insert_id();
    }

    /** @return array<string,mixed>|null */
    private function membership(int $organization_id, int $user_id): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_organization_memberships')
            . ' WHERE organization_id = %d AND wp_user_id = %d LIMIT 1',
            [$organization_id, $user_id]
        );
    }

    private function clinic_membership_count(int $user_id): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE wp_user_id = %d',
            [$user_id]
        );
    }

    private function organization_override_count(int $membership_id): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_organization_membership_capabilities') . ' WHERE membership_id = %d',
            [$membership_id]
        );
    }

    private function bootstrap_audit_count(): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
            . ' WHERE action = %s AND resource_id = %d',
            ['ORGANIZATION_MANAGER_BOOTSTRAPPED', $this->organization_id]
        );
    }

    /** @return array<string,mixed>|null */
    private function audit_row(int $audit_id): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE id = %d LIMIT 1',
            [$audit_id]
        );
    }

    private function assert_bootstrap_denied(int $organization_id, int $target_user_id): void
    {
        try {
            App::organization_clinic_provisioning_service()->bootstrap_owner(
                $this->admin_id,
                $organization_id,
                $target_user_id
            );
            self::fail('bootstrap should fail closed');
        } catch (RuntimeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function fail_audit_inserts(): void
    {
        global $wpdb;
        self::assertNull($this->audit_fault);
        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $matches) === 1
                && str_contains($matches[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $matches[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }
            return $query;
        };
        $this->original_suppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->audit_fault = $fault;
    }

    private function restore_audit_inserts(): void
    {
        if ($this->audit_fault === null) {
            return;
        }
        remove_filter('query', $this->audit_fault, 5);
        $this->audit_fault = null;
        global $wpdb;
        $wpdb->suppress_errors((bool) $this->original_suppression);
    }
}

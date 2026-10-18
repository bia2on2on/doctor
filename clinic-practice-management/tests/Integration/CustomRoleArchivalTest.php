<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CustomRoleDefinitionsPage;
use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
use ClinicCore\Application\Roles\CustomRoleAssignmentException;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use RuntimeException;
use Throwable;
use WP_UnitTestCase;

/**
 * Custom Role Management — Slice 4B, terminal archival security contract.
 *
 * This is integration coverage against the real WordPress/MySQL-backed
 * AuthorizationService, repositories, transaction wrapper, audit log and
 * server-rendered wp-admin surface. Archived definitions are durable and immutable; all membership references,
 * including suspended ones, must be explicitly reassigned first.
 */
final class CustomRoleArchivalTest extends WP_UnitTestCase
{
    private int $clinicA;
    private int $clinicB;
    private int $managerA;
    private int $managerB;
    private int $suspendedManager;
    private int $nonMember;
    private int $member;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();

        global $wpdb;
        $organizationId = (int) $wpdb->get_var(
            'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $organizationId, 'an active Organization fixture must exist');

        $this->clinicA = $this->makeClinic($organizationId, 'crlA');
        $this->clinicB = $this->makeClinic($organizationId, 'crlB');
        $this->managerA = $this->makeUser('crl_mgr_a_' . bin2hex(random_bytes(3)));
        $this->managerB = $this->makeUser('crl_mgr_b_' . bin2hex(random_bytes(3)));
        $this->suspendedManager = $this->makeUser('crl_mgr_sus_' . bin2hex(random_bytes(3)));
        $this->nonMember = $this->makeUser('crl_non_' . bin2hex(random_bytes(3)));
        $this->member = $this->makeUser('crl_member_' . bin2hex(random_bytes(3)));

        App::membership_service()->create_membership($this->clinicA, $this->managerA, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicB, $this->managerB, RolesAndCapabilities::ROLE_MANAGER);
        $suspendedId = App::membership_service()->create_membership($this->clinicA, $this->suspendedManager, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->suspend_membership($suspendedId);
        App::membership_service()->create_membership($this->clinicA, $this->member, RolesAndCapabilities::ROLE_SECRETARY);

        wp_set_current_user($this->managerA);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    public function testActiveRoleArchivalPersistsDefinitionCapabilitiesAndReservedKey(): void
    {
        $role = $this->createRole('archive_active', ['cpms_search']);
        $before = $this->definition('archive_active');
        $result = $this->archive('archive_active', 1);
        self::assertSame('archived', $result['status']);
        self::assertSame(2, $result['version']);
        self::assertTrue($result['changed']);
        $after = $this->definition('archive_active');
        self::assertSame($before['id'], $after['id']);
        self::assertSame($before['role_key'], $after['role_key']);
        self::assertSame($before['display_name'], $after['display_name']);
        self::assertSame($before['capabilities'], $after['capabilities']);
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_ARCHIVED', (int) $role['id']));
        self::assertSame([], (new CustomRoleRepository(App::db()))->active_capabilities_for($this->clinicA, 'archive_active'));
    }

    public function testInactiveRoleCanBeArchivedWithoutReactivation(): void
    {
        $this->createRole('archive_inactive', ['cpms_search']);
        $this->deactivate($this->managerA, $this->clinicA, 'archive_inactive', 1);
        $result = $this->archive('archive_inactive', 2);
        self::assertSame('archived', $result['status']);
        self::assertSame(3, $result['version']);
    }

    public function testUnauthorizedActorCannotArchive(): void
    {
        $role = $this->createRole('archive_denied', []);
        $this->expectLifecycleFailure('archive', $this->member, $this->clinicA, 'archive_denied', 1, AuthorizationException::class);
        self::assertSame('active', $this->definition('archive_denied')['status']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_ARCHIVED', (int) $role['id']));
    }

    public function testWordPressAdministratorWithoutClinicMembershipCannotArchive(): void
    {
        $this->createRole('archive_admin', []);
        $administrator = $this->makeUser('archive_admin_' . bin2hex(random_bytes(3)), 'administrator');
        $this->expectLifecycleFailure('archive', $administrator, $this->clinicA, 'archive_admin', 1, ScopeRequiredException::class);
    }

    public function testForeignClinicDefinitionIsNotAddressable(): void
    {
        $this->service()->create($this->managerB, $this->clinicB, 'archive_foreign', 'Foreign', []);
        $this->expectLifecycleFailure('archive', $this->managerA, $this->clinicA, 'archive_foreign', 1, CustomRoleManagementException::class, 'CUSTOM_ROLE_NOT_FOUND');
        $this->expectLifecycleFailure('archive', $this->managerA, $this->clinicB, 'archive_foreign', 1, ScopeRequiredException::class);
        self::assertSame('active', (new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'archive_foreign')['status']);
    }

    public function testSuspendedActorCannotArchive(): void
    {
        $this->createRole('archive_actor', []);
        $this->expectLifecycleFailure('archive', $this->suspendedManager, $this->clinicA, 'archive_actor', 1, ScopeRequiredException::class);
    }

    public function testActiveMembershipReferenceRefusesArchive(): void
    {
        $this->assertReferenceRefusal(false);
    }

    public function testSuspendedMembershipReferenceRefusesArchive(): void
    {
        $this->assertReferenceRefusal(true);
    }

    public function testForeignClinicReferenceDoesNotBlockLocalArchive(): void
    {
        $this->createRole('archive_local', []);
        $this->service()->create($this->managerB, $this->clinicB, 'archive_local', 'Foreign', []);
        App::membership_service()->create_membership($this->clinicB, $this->member, 'archive_local');
        self::assertTrue($this->archive('archive_local', 1)['changed']);
        self::assertSame('active', (new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'archive_local')['status']);
    }

    public function testArchivedKeyCannotBeRecreated(): void
    {
        $this->createRole('archive_reserved', []);
        $this->archive('archive_reserved', 1);
        $this->expectException(CustomRoleManagementException::class);
        $this->service()->create($this->managerA, $this->clinicA, 'archive_reserved', 'Reused', []);
    }

    public function testArchivedRoleSuppliesNoAuthorityAndOverridePrecedenceStaysUnchanged(): void
    {
        $this->createRole('archive_authority', ['cpms_search', 'cpms_patient_read']);
        $this->archive('archive_authority', 1);
        $membership = (new MembershipRepository(App::db()))->find($this->clinicA, $this->member);
        $id = (int) $membership['id'];
        // Deliberate raw corruption: runtime readers must fail closed even if
        // an external writer bypasses every validating membership entry point.
        App::db()->execute('UPDATE ' . App::db()->table('cpms_clinic_memberships') . ' SET role_key = %s WHERE id = %d', ['archive_authority', $id]);
        $memberships = new MembershipRepository(App::db());
        $memberships->set_capability($id, 'cpms_patient_read', 'deny');
        $memberships->set_capability($id, 'cpms_report_read', 'grant');
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_search'));
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'));
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_report_read'), 'existing membership GRANT semantics remain unchanged');
    }

    public function testArchivedRoleIsNotAnAssignmentOptionAndAssignmentIsDenied(): void
    {
        $this->createRole('archive_assign', ['cpms_search']);
        $this->archive('archive_assign', 1);
        self::assertSame([], App::custom_role_assignment_service()->assignable_definitions($this->managerA, $this->clinicA)['definitions']);
        $this->expectException(CustomRoleAssignmentException::class);
        App::custom_role_assignment_service()->assign($this->managerA, $this->clinicA, $this->member, 'archive_assign');
    }

    public function testArchivedDefinitionRejectsAllManagementMutationPaths(): void
    {
        $this->createRole('archive_terminal', ['cpms_search']);
        $this->archive('archive_terminal', 1);
        $before = $this->definition('archive_terminal');
        $operations = [
            fn() => $this->service()->rename($this->managerA, $this->clinicA, 'archive_terminal', 'Changed'),
            fn() => $this->service()->replace_capabilities($this->managerA, $this->clinicA, 'archive_terminal', []),
            fn() => $this->reactivate($this->managerA, $this->clinicA, 'archive_terminal', 2),
            fn() => $this->deactivate($this->managerA, $this->clinicA, 'archive_terminal', 2),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('archived definitions must be immutable');
            } catch (CustomRoleManagementException $exception) {
                self::assertSame('CUSTOM_ROLE_TERMINAL_STATE', $exception->reason_code());
            }
        }
        self::assertSame($before, $this->definition('archive_terminal'));
    }

    public function testRepositoryPrimitivesCannotEditOrReactivateArchivedDefinitions(): void
    {
        $this->createRole('archive_primitive', ['cpms_search']);
        $this->archive('archive_primitive', 1);
        $roles = new CustomRoleRepository(App::db());
        $before = $this->definition('archive_primitive');
        foreach ([
            fn() => $roles->set_display_name($this->clinicA, 'archive_primitive', 'Changed'),
            fn() => $roles->replace_capabilities($this->clinicA, 'archive_primitive', []),
            fn() => $roles->set_status($this->clinicA, 'archive_primitive', 'active'),
            fn() => $roles->transition_status($this->clinicA, 'archive_primitive', 'archived', 'active', 2),
        ] as $operation) {
            try {
                $operation();
                self::fail('legacy repository mutation must fail closed');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertSame($before, $this->definition('archive_primitive'));
    }

    public function testMembershipRoleMutationPrimitivesRefuseArchivedAssignment(): void
    {
        $this->createRole('archive_member_write', []);
        $this->archive('archive_member_write', 1);
        $memberships = new MembershipRepository(App::db());
        $membership = $memberships->find($this->clinicA, $this->member);
        $before = $membership;
        $freshUser = $this->makeUser('archive_fresh_' . bin2hex(random_bytes(3)));
        foreach ([
            fn() => App::membership_service()->set_role_key((int) $membership['id'], 'archive_member_write'),
            fn() => $memberships->update(['role_key' => 'archive_member_write'], ['id' => (int) $membership['id']]),
            fn() => App::membership_service()->create_membership($this->clinicA, $freshUser, 'archive_member_write'),
        ] as $operation) {
            try {
                $operation();
                self::fail('a membership primitive must never assign an archived role');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertSame($before, $memberships->find($this->clinicA, $this->member));
        self::assertNull($memberships->find($this->clinicA, $freshUser));
    }

    public function testUnknownStoredStatusIsNeverNormalizedToActiveOrAssignable(): void
    {
        $this->createRole('archive_unknown', ['cpms_search']);
        self::assertSame(1, App::db()->execute('UPDATE IGNORE ' . App::db()->table('cpms_custom_role_defs') . ' SET status = %s WHERE clinic_id = %d AND role_key = %s', ['', $this->clinicA, 'archive_unknown']));
        $roles = new CustomRoleRepository(App::db());
        self::assertNotSame('active', $roles->definition_for($this->clinicA, 'archive_unknown')['status'], 'unknown persisted status must not be normalized to ACTIVE');
        self::assertNotSame('active', $roles->list_definitions($this->clinicA)[0]['status']);
        App::db()->transactional(function () use ($roles): void {
            self::assertNotSame('active', $roles->definition_for_update($this->clinicA, 'archive_unknown')['status']);
        });
        self::assertSame([], App::custom_role_assignment_service()->assignable_definitions($this->managerA, $this->clinicA)['definitions']);
        self::assertSame([], $roles->active_capabilities_for($this->clinicA, 'archive_unknown'));
        $this->expectException(CustomRoleAssignmentException::class);
        App::custom_role_assignment_service()->assign($this->managerA, $this->clinicA, $this->member, 'archive_unknown');
    }

    public function testStaleAndZeroVersionsRefuseArchive(): void
    {
        $this->createRole('archive_stale', []);
        $this->service()->rename($this->managerA, $this->clinicA, 'archive_stale', 'New name');
        foreach ([0, 1] as $version) {
            $this->expectLifecycleFailure('archive', $this->managerA, $this->clinicA, 'archive_stale', $version, CustomRoleManagementException::class, 'CUSTOM_ROLE_STALE_VERSION');
        }
        self::assertSame('active', $this->definition('archive_stale')['status']);
    }

    public function testTerminalRepeatWithCurrentVersionIsNoOpWithoutDuplicateAudit(): void
    {
        $role = $this->createRole('archive_repeat', []);
        $this->archive('archive_repeat', 1);
        $before = $this->definition('archive_repeat');
        $result = $this->archive('archive_repeat', 2);
        self::assertFalse($result['changed']);
        self::assertSame(2, $result['version']);
        self::assertSame($before, $this->definition('archive_repeat'));
        self::assertSame(1, $this->statusAuditCount('CUSTOM_ROLE_ARCHIVED', (int) $role['id']));
        $this->expectLifecycleFailure('archive', $this->managerA, $this->clinicA, 'archive_repeat', 1, CustomRoleManagementException::class, 'CUSTOM_ROLE_STALE_VERSION');
    }

    public function testAuditFailureRollsBackArchiveStatusVersionAndCapabilities(): void
    {
        $role = $this->createRole('archive_audit', ['cpms_search']);
        $before = $this->definition('archive_audit');
        $this->failAuditInserts();
        try {
            try {
                $this->archive('archive_audit', 1);
                self::fail('archive without its required audit must never persist');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('audit', strtolower($exception->getMessage()));
            }
        } finally {
            $this->restoreAuditInserts();
        }
        self::assertSame($before, $this->definition('archive_audit'));
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_ARCHIVED', (int) $role['id']));
    }

    public function testArchivalNeverMutatesGlobalRolesOrClinicalRecords(): void
    {
        $this->createRole('archive_isolation', ['cpms_search']);
        $wpRoles = wp_roles()->roles;
        $memberRoles = get_userdata($this->member)->roles;
        $clinical = $this->clinicalSnapshot();
        $this->archive('archive_isolation', 1);
        self::assertSame($wpRoles, wp_roles()->roles);
        self::assertSame($memberRoles, get_userdata($this->member)->roles);
        self::assertNull(get_role('archive_isolation'));
        self::assertSame($clinical, $this->clinicalSnapshot());
    }

    public function testSafeSuspendedReassignmentPreservesSuspensionOverridesRestoreMetadataAndIdentity(): void
    {
        $this->createRole('archive_old_staff', ['cpms_search']);
        $this->createRole('archive_new_staff', ['cpms_patient_read']);
        App::custom_role_assignment_service()->assign($this->managerA, $this->clinicA, $this->member, 'archive_old_staff');
        $repository = new MembershipRepository(App::db());
        $membership = $repository->find($this->clinicA, $this->member);
        $id = (int) $membership['id'];
        $repository->set_capability($id, 'cpms_search', 'deny');
        $repository->set_capability($id, 'cpms_report_read', 'grant');
        $toggle = \ClinicCore\Admin\StaffManagementPage::toggleUser($this->member, 'deactivate', $this->managerA, $this->clinicA);
        self::assertSame('', $toggle['error']);
        $before = $repository->find($this->clinicA, $this->member);
        $overrides = $repository->capabilities_for($id);
        $locations = $repository->location_ids_for($id);
        $metadata = get_user_meta($this->member);
        $wpRoles = get_userdata($this->member)->roles;
        $clinical = $this->clinicalSnapshot();
        self::assertTrue(method_exists(App::custom_role_assignment_service(), 'reassign_suspended'), 'a bounded suspended-member reassignment path is required');
        $result = App::custom_role_assignment_service()->reassign_suspended($this->managerA, $this->clinicA, $this->member, 'archive_new_staff');
        self::assertTrue($result['changed']);
        $after = $repository->find($this->clinicA, $this->member);
        self::assertSame('suspended', $after['status']);
        self::assertSame('archive_new_staff', $after['role_key']);
        unset($before['role_key'], $before['updated_at'], $after['role_key'], $after['updated_at']);
        self::assertSame($before, $after);
        self::assertSame($overrides, $repository->capabilities_for($id));
        self::assertSame($locations, $repository->location_ids_for($id));
        self::assertSame($metadata, get_user_meta($this->member));
        self::assertSame($wpRoles, get_userdata($this->member)->roles);
        self::assertSame($clinical, $this->clinicalSnapshot());
        self::assertFalse(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'), 'reassignment must not silently reactivate');
        self::assertTrue($this->archive('archive_old_staff', 1)['changed'], 'explicit reassignment releases the old role reference');
        $restore = \ClinicCore\Admin\StaffManagementPage::toggleUser($this->member, 'activate', $this->managerA, $this->clinicA);
        self::assertSame('', $restore['error'], 'preserved restoration metadata must still support explicit later restoration');
        self::assertTrue(App::authorization_service()->can($this->member, $this->clinicA, 'cpms_patient_read'));
        self::assertSame('archive_new_staff', $repository->find($this->clinicA, $this->member)['role_key']);
    }

    public function testSuspendedReassignmentCannotBeUsedForActiveTargetsOrUnauthorizedActors(): void
    {
        $this->createRole('archive_suspended_target', []);
        self::assertTrue(method_exists(App::custom_role_assignment_service(), 'reassign_suspended'));
        try {
            App::custom_role_assignment_service()->reassign_suspended($this->managerA, $this->clinicA, $this->member, 'archive_suspended_target');
            self::fail('the suspended-only path must not accept an active target');
        } catch (CustomRoleAssignmentException $exception) {
            self::assertSame('CUSTOM_ROLE_ASSIGNMENT_TARGET_MEMBERSHIP_REQUIRED', $exception->reason_code());
        }
        $membership = (new MembershipRepository(App::db()))->find($this->clinicA, $this->member);
        App::membership_service()->suspend_membership((int) $membership['id']);
        $this->expectException(ScopeRequiredException::class);
        App::custom_role_assignment_service()->reassign_suspended($this->suspendedManager, $this->clinicA, $this->member, 'archive_suspended_target');
    }

    public function testExistingDeactivateReactivateBehaviorRemainsReversible(): void
    {
        $this->createRole('archive_reversible', ['cpms_search']);
        self::assertSame('inactive', $this->deactivate($this->managerA, $this->clinicA, 'archive_reversible', 1)['status']);
        self::assertSame('active', $this->reactivate($this->managerA, $this->clinicA, 'archive_reversible', 2)['status']);
        self::assertSame(3, $this->definition('archive_reversible')['version']);
    }

    public function testArchiveUiHasExplicitActionConsequenceConfirmationAndDedicatedNonce(): void
    {
        $this->createRole('archive_ui', ['cpms_search']);
        wp_set_current_user($this->managerA);
        $oldGet = $_GET;
        $_GET['cpms_clinic_id'] = (string) $this->clinicA;
        try {
            ob_start();
            CustomRoleDefinitionsPage::render();
            $html = (string) ob_get_clean();
            self::assertStringContainsString('cpms_custom_role_archive', $html);
            self::assertStringContainsString(wp_create_nonce('cpms_custom_role_archive'), $html);
            self::assertStringContainsString('confirm_archive', $html);
            self::assertStringContainsString('برگشت', $html, 'terminal consequences must be stated');
            self::assertStringContainsString('version', $html);
            $this->archive('archive_ui', 1);
            ob_start();
            CustomRoleDefinitionsPage::render();
            $archived = (string) ob_get_clean();
            self::assertStringContainsString('بایگانی', $archived);
            foreach (['cpms_custom_role_archive', 'cpms_custom_role_reactivate', 'cpms_custom_role_deactivate', 'cpms_custom_role_rename', 'cpms_custom_role_caps'] as $action) {
                self::assertStringNotContainsString('value="' . $action . '"', $archived, 'archived definitions must be read-only');
            }
        } finally {
            $_GET = $oldGet;
        }
    }

    public function testCustomRoleDefinitionPageDoesNotSelectTheOnlyManageableClinicImplicitly(): void
    {
        $this->createRole('archive_no_scope', ['cpms_search']);
        wp_set_current_user($this->managerA);
        $oldGet = $_GET;
        unset($_GET['cpms_clinic_id']);
        try {
            ob_start();
            CustomRoleDefinitionsPage::render();
            $html = (string) ob_get_clean();
        } finally {
            $_GET = $oldGet;
        }

        self::assertStringContainsString('— انتخاب Clinic —', $html);
        self::assertStringContainsString('Clinic معتبری انتخاب نشده است.', $html);
        self::assertStringNotContainsString('value="cpms_custom_role_deactivate"', $html);
    }

    public function testInvalidArchiveNonceStopsBeforeMutation(): void
    {
        $this->createRole('archive_nonce', []);
        self::assertTrue(method_exists(CustomRoleDefinitionsPage::class, 'handle_archive'));
        $oldPost = $_POST;
        $oldRequest = $_REQUEST;
        $_POST = ['action' => 'cpms_custom_role_archive', 'cpms_clinic_id' => $this->clinicA, 'role_key' => 'archive_nonce', 'version' => '1', 'confirm_archive' => '1', '_wpnonce' => wp_create_nonce('cpms_custom_role_deactivate')];
        $_REQUEST = $_POST;
        $filter = static fn($handler) => static function ($message): void { throw new RuntimeException('archive nonce denied'); };
        add_filter('wp_die_handler', $filter);
        try {
            try {
                CustomRoleDefinitionsPage::handle_archive();
                self::fail('a nonce for another action must be refused');
            } catch (RuntimeException $exception) {
                self::assertSame('archive nonce denied', $exception->getMessage());
            }
        } finally {
            remove_filter('wp_die_handler', $filter);
            $_POST = $oldPost;
            $_REQUEST = $oldRequest;
        }
        self::assertSame('active', $this->definition('archive_nonce')['status']);
        self::assertSame(1, $this->definition('archive_nonce')['version']);
    }

    private function archive(string $roleKey, int $version): array
    {
        return $this->transition('archive', $this->managerA, $this->clinicA, $roleKey, $version);
    }

    private function definition(string $roleKey): array
    {
        $definition = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, $roleKey);
        self::assertNotNull($definition);
        return $definition;
    }

    private function assertReferenceRefusal(bool $suspended): void
    {
        $role = $this->createRole('archive_referenced', ['cpms_search']);
        App::custom_role_assignment_service()->assign($this->managerA, $this->clinicA, $this->member, 'archive_referenced');
        $repository = new MembershipRepository(App::db());
        $membership = $repository->find($this->clinicA, $this->member);
        if ($suspended) {
            App::membership_service()->suspend_membership((int) $membership['id']);
        }
        $before = $repository->find($this->clinicA, $this->member);
        $this->expectLifecycleFailure('archive', $this->managerA, $this->clinicA, 'archive_referenced', 1, CustomRoleManagementException::class, 'CUSTOM_ROLE_MEMBERSHIP_REFERENCES');
        self::assertSame($before, $repository->find($this->clinicA, $this->member), 'no automatic reassignment');
        self::assertSame('active', $this->definition('archive_referenced')['status']);
        self::assertSame(1, $this->definition('archive_referenced')['version']);
        self::assertSame(0, $this->statusAuditCount('CUSTOM_ROLE_ARCHIVED', (int) $role['id']));
    }

    private function clinicalSnapshot(): array
    {
        $snapshot = [];
        foreach (['cpms_patients', 'cpms_clinicians', 'cpms_visits', 'cpms_clinical_notes', 'cpms_clinical_note_versions', 'cpms_prescriptions', 'cpms_prescription_items', 'cpms_medical_attachments', 'cpms_patient_identities', 'cpms_patient_identity_links', 'cpms_clinician_locations'] as $table) {
            $physical = App::db()->table($table);
            if ((int) App::db()->fetchValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', [$physical]) > 0) {
                $snapshot[$table] = App::db()->fetchAll('SELECT * FROM ' . $physical);
            }
        }
        self::assertArrayHasKey('cpms_patients', $snapshot);
        self::assertArrayHasKey('cpms_clinicians', $snapshot);
        return $snapshot;
    }

    private function service(): CustomRoleManagementService
    {
        return App::custom_role_management_service();
    }

    /** @param list<string> $capabilities */
    private function createRole(string $roleKey, array $capabilities): array
    {
        return $this->service()->create($this->managerA, $this->clinicA, $roleKey, 'Lifecycle ' . $roleKey, $capabilities);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function deactivate(int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        return $this->transition('deactivate', $actor, $clinicId, $roleKey, $expectedVersion);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function reactivate(int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        return $this->transition('reactivate', $actor, $clinicId, $roleKey, $expectedVersion);
    }

    /** @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} */
    private function transition(string $method, int $actor, int $clinicId, string $roleKey, int $expectedVersion): array
    {
        $service = $this->service();
        self::assertTrue(method_exists($service, $method), 'Slice 4B must implement the authorized ' . $method . ' operation');
        /** @var array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool} $result */
        $result = call_user_func([$service, $method], $actor, $clinicId, $roleKey, $expectedVersion);

        return $result;
    }

    private function expectLifecycleFailure(
        string $method,
        int $actor,
        int $clinicId,
        string $roleKey,
        int $version,
        string $exceptionClass,
        ?string $reason = null
    ): void {
        try {
            $this->transition($method, $actor, $clinicId, $roleKey, $version);
            self::fail('the lifecycle mutation must be refused');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exceptionClass, $e);
            if ($reason !== null) {
                self::assertSame($reason, method_exists($e, 'reason_code') ? $e->reason_code() : null);
            }
        }
    }

    /** @param list<string> $capabilities */
    private function statusAuditCount(string $action, int $definitionId): int
    {
        global $wpdb;
        $table = App::db()->table('cpms_audit_logs');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE action = %s AND clinic_id = %d AND resource_type = %s AND resource_id = %d",
            $action,
            $this->clinicA,
            'custom_role_definition',
            $definitionId
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function definitionId(string $roleKey): int
    {
        return (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE clinic_id = %d AND role_key = %s',
            [$this->clinicA, $roleKey]
        );
    }

    private function failAuditInserts(): void
    {
        global $wpdb;
        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');
        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\\A\\s*INSERT\\s+INTO\\s+(\\S+)/i', $sql, $matches) === 1
                && str_contains($matches[1], 'cpms_audit_logs')) {
                return 'INSERT INTO ' . $matches[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
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

    private function makeClinic(int $organizationId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $organizationId,
            'Lifecycle test ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $id = self::factory()->user->create(['user_login' => $login, 'role' => $role]);
        self::assertGreaterThan(0, $id);

        return (int) $id;
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\StaffManagementPage;
use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleAssignmentException;
use ClinicCore\Application\Roles\CustomRoleAssignmentService;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 3B: Clinic-local staff
 * custom-role ASSIGNMENT (membership-only).
 *
 * Contract under test (owner-approved product contract + approved Product
 * Decision record docs/decisions/2026-10-10-custom-role-permission-management-
 * model.md):
 *  - assignment is Clinic-local and requires trusted Clinic scope + effective
 *    `cpms_config` management authority (a WP administrator without a durable
 *    membership is denied; a forged Clinic selector never conjures membership);
 *  - the WP-global role of the target is NEVER modified and no clinician /
 *    professional identity row is touched;
 *  - only an ACTIVE custom definition of the exact Clinic is assignable; a
 *    foreign-Clinic key is not addressable and a missing/inactive definition is
 *    refused;
 *  - the target must already hold an ACTIVE membership in that Clinic;
 *  - the assigner cannot delegate capabilities beyond its own effective Clinic
 *    authority, and a membership DENY is never laundered through a custom role;
 *  - existing membership GRANT/DENY rows stay byte-identical and keep their
 *    precedence after reassignment;
 *  - the role update and its audit event are atomic (a failed audit rolls the
 *    membership role back);
 *  - empty-capability roles are assignable and confer nothing;
 *  - assigned staff stay visible/manageable via durable Clinic membership (not
 *    the WP-global role) and reversal through the existing built-in staff flow
 *    still works;
 *  - non-sensitive finance capabilities can be delegated without clinical or
 *    sensitive-finance access.
 */
final class CustomRoleStaffAssignmentTest extends WP_UnitTestCase
{
    private int $clinicA = 0;
    private int $clinicB = 0;

    private int $managerUserId = 0;
    private int $adminManagerUserId = 0;
    private int $adminInBOnlyUserId = 0;
    private int $adminWithoutMembershipUserId = 0;
    private int $suspendedManagerUserId = 0;
    private int $secretaryUserId = 0;
    private int $membershipOnlyUserId = 0;
    private int $foreignTargetUserId = 0;
    private int $suspendedTargetUserId = 0;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();

        global $wpdb;
        $organizationId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $organizationId, 'an organization fixture must exist');

        $this->clinicA = $this->makeClinic($organizationId, 'srA');
        $this->clinicB = $this->makeClinic($organizationId, 'srB');
        self::assertNotSame($this->clinicA, $this->clinicB);

        $this->managerUserId = $this->makeUser('sr_mgr_' . bin2hex(random_bytes(2)));
        $this->adminManagerUserId = $this->makeUser('sr_admin_mgr_' . bin2hex(random_bytes(2)), 'administrator');
        $this->adminInBOnlyUserId = $this->makeUser('sr_admin_b_' . bin2hex(random_bytes(2)), 'administrator');
        $this->adminWithoutMembershipUserId = $this->makeUser('sr_admin_none_' . bin2hex(random_bytes(2)), 'administrator');
        $this->suspendedManagerUserId = $this->makeUser('sr_mgr_sus_' . bin2hex(random_bytes(2)));
        $this->secretaryUserId = $this->makeUser('sr_sec_' . bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_SECRETARY);
        // Membership-only staff: the durable Clinic membership carries the Clinic
        // role while the WordPress-global role stays `subscriber`.
        $this->membershipOnlyUserId = $this->makeUser('sr_attach_' . bin2hex(random_bytes(2)));
        $this->foreignTargetUserId = $this->makeUser('sr_foreign_' . bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_SECRETARY);
        $this->suspendedTargetUserId = $this->makeUser('sr_target_sus_' . bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_SECRETARY);

        foreach ([$this->adminManagerUserId, $this->adminInBOnlyUserId, $this->adminWithoutMembershipUserId] as $adminId) {
            $admin = get_userdata($adminId);
            self::assertNotFalse($admin);
            $admin->add_cap('manage_options');
            $admin->add_cap('cpms_config');
        }

        cpms_test_seed_membership($this->managerUserId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->adminManagerUserId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->adminInBOnlyUserId, $this->clinicB, RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->secretaryUserId, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->membershipOnlyUserId, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->foreignTargetUserId, $this->clinicB, RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->suspendedTargetUserId, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);

        $suspendedActorMembership = cpms_test_seed_membership($this->suspendedManagerUserId, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->suspend_membership($suspendedActorMembership);
        $suspendedTargetMembership = cpms_test_seed_membership($this->suspendedTargetUserId, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->suspend_membership($suspendedTargetMembership);

        wp_set_current_user($this->managerUserId);
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    private function service(): CustomRoleAssignmentService
    {
        return App::custom_role_assignment_service();
    }

    private function definitions(): CustomRoleManagementService
    {
        return App::custom_role_management_service();
    }

    // ------------------------------------------------------------------
    // 1 — authorized same-Clinic assignment (membership-only) + audit.
    // ------------------------------------------------------------------

    public function testAuthorizedManagerAssignsActiveClinicLocalRoleAndAuditsIt(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_desk', 'Reception desk', ['cpms_patient_read', 'cpms_search']);

        $membershipBefore = $this->membership($this->clinicA, $this->secretaryUserId);
        $wpRolesBefore = (array) get_userdata($this->secretaryUserId)->roles;

        $assigned = $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_desk');

        self::assertTrue($assigned['changed']);
        self::assertSame($this->clinicA, $assigned['clinic_id']);
        self::assertSame('sr_desk', $assigned['role_key']);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, $assigned['previous_role_key']);
        self::assertSame((int) $membershipBefore['id'], $assigned['membership_id']);

        // Durable membership is the only thing written...
        $membershipAfter = $this->membership($this->clinicA, $this->secretaryUserId);
        self::assertSame('sr_desk', (string) $membershipAfter['role_key']);
        self::assertSame('active', (string) $membershipAfter['status']);
        self::assertSame((int) $membershipBefore['id'], (int) $membershipAfter['id'], 'assignment must update the existing membership row, never create one');
        self::assertSame(self::membershipCount($this->secretaryUserId), 1, 'no additional membership may be created');

        // ...the WordPress-global role is untouched.
        self::assertSame($wpRolesBefore, (array) get_userdata($this->secretaryUserId)->roles);

        // Effective capabilities resolve through the existing engine only.
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_patient_read'));
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_search'));
        self::assertFalse(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_payment_create'), 'the preset caps are replaced by the custom set');
        self::assertFalse(App::authorization_service()->can($this->secretaryUserId, $this->clinicB, 'cpms_patient_read'), 'the assignment is Clinic-local');

        $audit = $this->auditRow(CustomRoleAssignmentService::AUDIT_ASSIGNED, (int) $membershipAfter['id']);
        self::assertNotNull($audit, 'a successful assignment must be audited');
        self::assertSame($this->clinicA, (int) $audit['clinic_id']);
        self::assertSame($this->managerUserId, (int) $audit['actor_wp_user_id']);
        self::assertSame('membership', (string) $audit['resource_type']);
        $after = json_decode((string) $audit['after_json'], true);
        self::assertIsArray($after);
        self::assertSame('sr_desk', $after['role_key']);
        self::assertSame('clinic_local_membership', $after['scope']);
        self::assertSame(0, (int) $audit['patient_id']);
    }

    public function testAssigningTheRoleTheMembershipAlreadyCarriesIsAReportedNoOp(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_same', 'Same role', ['cpms_patient_read']);
        $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_same');
        $auditBefore = $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED);

        $second = $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_same');

        self::assertFalse($second['changed'], 'a no-op must be reported as such, never as a change');
        self::assertSame($auditBefore, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED), 'a no-op writes no change event');
        self::assertSame('sr_same', (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);
    }

    // ------------------------------------------------------------------
    // 2 + 3 — foreign Clinic, forged selector, administrator non-authority.
    // ------------------------------------------------------------------

    public function testForeignClinicTargetAndForeignDefinitionAreRefused(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_local', 'Local role', ['cpms_patient_read']);
        // A same-keyed definition owned by Clinic B (created by B's manager).
        $this->definitions()->create($this->adminInBOnlyUserId, $this->clinicB, 'sr_local', 'B role', ['cpms_patient_read']);

        // Target has no ACTIVE membership in Clinic A.
        try {
            $this->service()->assign($this->managerUserId, $this->clinicA, $this->foreignTargetUserId, 'sr_local');
            self::fail('a target without an ACTIVE membership in the trusted Clinic must be refused');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_TARGET_MEMBERSHIP_REQUIRED, $e->reason_code());
        }

        // Clinic B's definition is not addressable from Clinic A: the manager of
        // A may not assign B's 'sr_local'… but the key exists in A, so use a key
        // that only exists in B to prove there is no cross-Clinic fallback.
        $this->definitions()->create($this->adminInBOnlyUserId, $this->clinicB, 'sr_only_b', 'B only', ['cpms_patient_read']);
        try {
            $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_only_b');
            self::fail('a definition that lives in another Clinic must not be addressable');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_DEFINITION_NOT_FOUND, $e->reason_code());
        }

        // No cross-Clinic fallback, no partial write.
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);
        self::assertSame(0, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));
    }

    public function testForgedClinicSelectorAndAdministratorWithoutMembershipAreDenied(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_gate', 'Gate role', ['cpms_patient_read']);

        // The actor is only a member of Clinic A but sends Clinic B.
        try {
            $this->service()->assign($this->managerUserId, $this->clinicB, $this->foreignTargetUserId, 'sr_gate');
            self::fail('a forged Clinic selector must never conjure membership');
        } catch (ScopeRequiredException | AuthorizationException $e) {
            self::assertTrue(true);
        }
        self::assertSame(0, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));

        // WordPress administrator + global manage_options + global cpms_config,
        // but no durable membership in Clinic A: no Clinic authority at all.
        try {
            $this->service()->assign($this->adminWithoutMembershipUserId, $this->clinicA, $this->secretaryUserId, 'sr_gate');
            self::fail('a WP administrator without durable Clinic membership must be denied');
        } catch (ScopeRequiredException | AuthorizationException $e) {
            self::assertTrue(true);
        }
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);

        // Assignment is not a cross-Clinic tool: an administrator managing Clinic
        // B cannot assign into Clinic A either.
        try {
            $this->service()->assign($this->adminInBOnlyUserId, $this->clinicA, $this->secretaryUserId, 'sr_gate');
            self::fail('administrator status must not create all-Clinic authority');
        } catch (ScopeRequiredException | AuthorizationException $e) {
            self::assertTrue(true);
        }
    }

    // ------------------------------------------------------------------
    // 4 + 5 — missing/inactive definitions; suspended actor/target.
    // ------------------------------------------------------------------

    public function testMissingInactiveAndInactiveTargetAreRefused(): void
    {
        try {
            $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_missing');
            self::fail('a missing definition must be refused');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_DEFINITION_NOT_FOUND, $e->reason_code());
        }

        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_off', 'Inactive role', ['cpms_patient_read']);
        (new CustomRoleRepository(App::db()))->set_status($this->clinicA, 'sr_off', CustomRoleRepository::STATUS_INACTIVE);
        try {
            $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_off');
            self::fail('an inactive definition must never be assignable');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_DEFINITION_NOT_ACTIVE, $e->reason_code());
        }
        self::assertSame(
            [],
            $this->service()->assignable_definitions($this->managerUserId, $this->clinicA)['definitions'],
            'inactive definitions must not be offered by the assignment surface'
        );

        // Built-in and unknown keys are not assignable through this path at all.
        foreach ([RolesAndCapabilities::ROLE_SECRETARY, 'Administrator', '1'] as $not_a_custom_key) {
            try {
                $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, $not_a_custom_key);
                self::fail('a non-definable key must be refused: ' . $not_a_custom_key);
            } catch (CustomRoleAssignmentException $e) {
                self::assertSame(CustomRoleAssignmentException::CODE_INVALID_ROLE_KEY, $e->reason_code());
            }
        }

        // Suspended TARGET membership (durable, still present) is not assignable.
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_susp_t', 'For suspended', ['cpms_patient_read']);
        try {
            $this->service()->assign($this->managerUserId, $this->clinicA, $this->suspendedTargetUserId, 'sr_susp_t');
            self::fail('a suspended target membership must be refused');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_TARGET_MEMBERSHIP_REQUIRED, $e->reason_code());
        }
    }

    public function testSuspendedActorIsDenied(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_susp_a', 'Actor test', ['cpms_patient_read']);

        try {
            $this->service()->assign($this->suspendedManagerUserId, $this->clinicA, $this->secretaryUserId, 'sr_susp_a');
            self::fail('a suspended actor must be denied');
        } catch (ScopeRequiredException | AuthorizationException $e) {
            self::assertTrue(true);
        }
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);
        self::assertSame(0, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));
    }

    // ------------------------------------------------------------------
    // 6 — bounded delegation and DENY precedence.
    // ------------------------------------------------------------------

    public function testAssignerCannotDelegateCapabilitiesBeyondItsOwnAuthority(): void
    {
        // Definition carrying a capability the assigner effectively holds…
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_read', 'Read only', ['cpms_patient_read']);
        // …and one limited to a member whose DENY row removes that capability.
        $deniedManager = $this->makeUser('sr_mgr_deny_' . bin2hex(random_bytes(2)));
        $deniedMembership = cpms_test_seed_membership($deniedManager, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $repository = new MembershipRepository(App::db());
        $repository->set_capability($deniedMembership, 'cpms_patient_read', 'deny');
        self::assertTrue(App::authorization_service()->can($deniedManager, $this->clinicA, RolesAndCapabilities::CONFIG), 'management authority itself is intact');
        self::assertFalse(App::authorization_service()->can($deniedManager, $this->clinicA, 'cpms_patient_read'), 'the DENY row must win');

        try {
            $this->service()->assign($deniedManager, $this->clinicA, $this->secretaryUserId, 'sr_read');
            self::fail('a membership DENY must not be laundered through a custom role');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_CAPABILITY_NOT_DELEGABLE, $e->reason_code());
        }
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);

        // The surface must not offer it either.
        $offered = array_column($this->service()->assignable_definitions($deniedManager, $this->clinicA)['definitions'], 'role_key');
        self::assertSame([], $offered, 'a non-delegable definition must not be offered');
        self::assertSame(['sr_read'], array_column($this->service()->assignable_definitions($this->managerUserId, $this->clinicA)['definitions'], 'role_key'));
    }

    public function testMembershipDenyAndGrantRowsSurviveReassignmentAndKeepPrecedence(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_matrix', 'Matrix role', ['cpms_patient_read', 'cpms_search']);
        $repository = new MembershipRepository(App::db());
        $membership = $this->membership($this->clinicA, $this->secretaryUserId);
        $repository->set_capability((int) $membership['id'], 'cpms_patient_read', 'deny');
        $repository->set_capability((int) $membership['id'], 'cpms_payment_create', 'grant');
        $rowsBefore = $this->capabilityRows((int) $membership['id']);
        self::assertCount(2, $rowsBefore);

        $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_matrix');

        self::assertSame($rowsBefore, $this->capabilityRows((int) $membership['id']), 'GRANT/DENY rows must stay byte-identical');
        self::assertFalse(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_patient_read'), 'DENY still outranks the role');
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_search'), 'the role still supplies what DENY does not remove');
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_payment_create'), 'a GRANT row still outranks the role');
    }

    // ------------------------------------------------------------------
    // 7 + 8 — immutable WP-global role and professional identity.
    // ------------------------------------------------------------------

    public function testWordPressGlobalRoleIsNeverModifiedForAnyTargetShape(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_wp', 'WP role test', ['cpms_patient_read']);

        foreach ([$this->secretaryUserId, $this->membershipOnlyUserId, $this->managerUserId] as $target) {
            $rolesBefore = (array) get_userdata($target)->roles;
            self::assertNotSame([], $rolesBefore);
            $this->service()->assign($this->managerUserId, $this->clinicA, $target, 'sr_wp');
            self::assertSame($rolesBefore, (array) get_userdata($target)->roles, 'the WP-global role must be immutable');
            self::assertSame(
                'sr_wp',
                (string) $this->membership($this->clinicA, $target)['role_key'],
                'the Clinic-local membership role is what changes'
            );
        }

        // The custom key is never a WordPress role anywhere in the installation.
        self::assertNull(get_role('sr_wp'), 'a custom role must never be registered as a WP-global role');
    }

    public function testProfessionalClinicianIdentityIsNotMutated(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->clinicA,
            'Dr Identity Fixture',
            $this->secretaryUserId,
            $now,
            $now
        ));
        $clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicianId);
        $before = $this->clinicianRow($clinicianId);

        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_ident', 'Identity role', ['cpms_patient_read']);
        $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_ident');

        self::assertSame($before, $this->clinicianRow($clinicianId), 'the professional identity row must be untouched');
        self::assertSame(1, $this->clinicianRowsForUser($this->secretaryUserId), 'no clinician row may be created by an assignment');
    }

    // ------------------------------------------------------------------
    // 10 — atomic role update + audit; failed audit rolls back.
    // ------------------------------------------------------------------

    public function testFailedAuditRollsBackTheMembershipRoleUpdate(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_atomic', 'Atomic role', ['cpms_report_read']);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);

        $this->failAuditInserts();
        $thrown = null;
        try {
            $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_atomic');
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $this->restoreAuditInserts();
        }
        self::assertNotNull($thrown, 'an assignment whose audit event cannot be persisted must fail');

        self::assertSame(
            RolesAndCapabilities::ROLE_SECRETARY,
            (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key'],
            'no partial assignment may survive a failed audit event'
        );
        self::assertFalse(
            App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_report_read'),
            'the rollback must leave the membership with the built-in secretary preset only'
        );
        self::assertSame(0, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));

        // The machinery is healthy afterwards: a second, unfaulted attempt works.
        $assigned = $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_atomic');
        self::assertTrue($assigned['changed']);
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_report_read'));
        self::assertSame(1, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));
        self::assertTrue(App::audit()->verifyChain()['ok'], 'the hash chain must stay valid');
    }

    // ------------------------------------------------------------------
    // 12 — empty-capability roles remain valid and confer nothing.
    // ------------------------------------------------------------------

    public function testEmptyCapabilityRoleIsAssignableAndConfersNothing(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_label', 'Label only', []);

        $offered = $this->service()->assignable_definitions($this->managerUserId, $this->clinicA)['definitions'];
        self::assertSame(['sr_label'], array_column($offered, 'role_key'));
        self::assertTrue($offered[0]['empty'], 'the UI needs to know this role is empty to explain its effect');
        self::assertSame([], $offered[0]['capabilities']);

        $assigned = $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_label');

        self::assertTrue($assigned['changed']);
        self::assertSame([], $assigned['capabilities']);
        foreach (['cpms_patient_read', 'cpms_search', 'cpms_payment_create', 'cpms_finance_read', 'cpms_medical_read'] as $capability) {
            self::assertFalse(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, $capability), 'an empty role confers nothing: ' . $capability);
        }
        self::assertSame(1, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));
    }

    // ------------------------------------------------------------------
    // 11 + 13 — membership-based visibility, manageability, safe reversal.
    // ------------------------------------------------------------------

    public function testAssignedStaffStayVisibleAndManageableViaDurableMembership(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_vis', 'Visible role', ['cpms_patient_read']);
        $this->service()->assign($this->managerUserId, $this->clinicA, $this->membershipOnlyUserId, 'sr_vis');

        // The page's own display gate is the WP-global `cpms_config` capability
        // (unchanged existing behaviour); this actor also carries the durable
        // Clinic membership that makes every write authoritative.
        wp_set_current_user($this->adminManagerUserId);
        $html = $this->renderStaffPage($this->clinicA);

        self::assertStringContainsString('sr_vis', $html, 'the Clinic-local role must be visible in the staff listing');
        self::assertStringContainsString('Visible role', $html, 'the custom display name must be visible');
        self::assertStringContainsString('sr_attach_', $html, 'membership-based staff must appear even when the WP-global role is not a CPMS role');
        // The other Clinic's member must never leak into this Clinic's listing.
        self::assertStringNotContainsString('sr_foreign_', $html);
        // The assignment surface offers only delegable, ACTIVE definitions.
        self::assertStringContainsString('انتساب نقش سفارشی', $html);

        // Manageability of the membership-only assignee through the existing flow.
        $updated = StaffManagementPage::upsertUser([
            'mode' => 'update',
            'user_id' => $this->membershipOnlyUserId,
            'clinic_id' => $this->clinicA,
            'display_name' => 'Attached Staff Renamed',
            'email' => 'attached_' . bin2hex(random_bytes(3)) . '@test.local',
            'role' => '',
            'password' => '',
        ], $this->managerUserId);
        self::assertSame('', $updated['error'], 'a custom-role member must stay manageable: ' . $updated['error']);
        self::assertSame('sr_vis', (string) $this->membership($this->clinicA, $this->membershipOnlyUserId)['role_key'], 'keeping the current custom role must not silently reassign it');
        self::assertSame(['subscriber'], (array) get_userdata($this->membershipOnlyUserId)->roles, 'no WP-global role may be introduced by that edit');
    }

    public function testReversalToABuiltInRoleThroughTheExistingStaffFlowStaysOperational(): void
    {
        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_rev', 'Reversible role', ['cpms_patient_read']);
        $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_rev');
        self::assertFalse(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_payment_create'), 'the custom role replaced the preset');

        $updated = StaffManagementPage::upsertUser([
            'mode' => 'update',
            'user_id' => $this->secretaryUserId,
            'clinic_id' => $this->clinicA,
            'display_name' => 'Secretary Again',
            'email' => 'secretary_again@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => '',
        ], $this->managerUserId);

        self::assertSame('', $updated['error']);
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $this->secretaryUserId)['role_key']);
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_payment_create'), 'the built-in preset is effective again');
        self::assertFalse(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_medical_read'), 'reversal must not widen beyond the built-in preset');

        // The definition itself and the audit history stay intact.
        self::assertNotNull((new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'sr_rev'));
        self::assertSame(1, $this->auditCount(CustomRoleAssignmentService::AUDIT_ASSIGNED));
        self::assertSame(0, $this->auditCount('STAFF_USER_CREATED'));

        // Deactivation/reactivation of a custom-role member stays available and
        // restores the assignment that suspension preserved.
        $this->service()->assign($this->managerUserId, $this->clinicA, $this->membershipOnlyUserId, 'sr_rev');
        $deactivated = StaffManagementPage::toggleUser($this->membershipOnlyUserId, 'deactivate', $this->managerUserId, $this->clinicA);
        self::assertSame('', $deactivated['error'], 'a custom-role member must remain suspendable: ' . $deactivated['error']);
        self::assertSame('suspended', (string) $this->membership($this->clinicA, $this->membershipOnlyUserId)['status']);
        self::assertSame(['subscriber'], (array) get_userdata($this->membershipOnlyUserId)->roles, 'a WP-global role CPMS never assigned must not be overwritten');

        $activated = StaffManagementPage::toggleUser($this->membershipOnlyUserId, 'activate', $this->managerUserId, $this->clinicA);
        self::assertSame('', $activated['error']);
        self::assertSame('active', (string) $this->membership($this->clinicA, $this->membershipOnlyUserId)['status']);
        self::assertSame('sr_rev', (string) $this->membership($this->clinicA, $this->membershipOnlyUserId)['role_key']);
        self::assertTrue(App::authorization_service()->can($this->membershipOnlyUserId, $this->clinicA, 'cpms_patient_read'));
    }

    public function testBuiltInStaffFlowsRemainOperational(): void
    {
        $login = 'sr_builtin_' . bin2hex(random_bytes(2));
        $created = StaffManagementPage::upsertUser([
            'mode' => 'create',
            'username' => $login,
            'display_name' => 'Built-in Secretary',
            'email' => $login . '@test.local',
            'role' => RolesAndCapabilities::ROLE_SECRETARY,
            'password' => 'StrongPass123',
        ], $this->managerUserId);
        self::assertSame('', $created['error']);

        $userId = (int) $created['user_id'];
        self::assertContains(RolesAndCapabilities::ROLE_SECRETARY, (array) get_userdata($userId)->roles, 'the built-in flow still sets the WP-global role');
        self::assertSame(RolesAndCapabilities::ROLE_SECRETARY, (string) $this->membership($this->clinicA, $userId)['role_key']);
        self::assertTrue(App::authorization_service()->can($userId, $this->clinicA, 'cpms_payment_create'));

        // A built-in edit through the existing flow still updates both sides.
        $updated = StaffManagementPage::upsertUser([
            'mode' => 'update',
            'user_id' => $userId,
            'clinic_id' => $this->clinicA,
            'display_name' => 'Built-in Doctor',
            'email' => $login . '@test.local',
            'role' => RolesAndCapabilities::ROLE_DOCTOR,
            'password' => '',
        ], $this->managerUserId);
        self::assertSame('', $updated['error']);
        self::assertContains(RolesAndCapabilities::ROLE_DOCTOR, (array) get_userdata($userId)->roles);
        self::assertSame(RolesAndCapabilities::ROLE_DOCTOR, (string) $this->membership($this->clinicA, $userId)['role_key']);
    }

    // ------------------------------------------------------------------
    // 14 — small-practice finance usability (non-sensitive only).
    // ------------------------------------------------------------------

    public function testSecretaryPlusPaymentFinanceCanBeDelegatedWithoutClinicalOrSensitiveFinanceAccess(): void
    {
        // The existing GRANT-row mechanism gives the responsible manager the
        // non-sensitive finance capabilities it must be able to hand over.
        $managerMembership = $this->membership($this->clinicA, $this->managerUserId);
        $repository = new MembershipRepository(App::db());
        foreach (['cpms_appt_read', 'cpms_payment_create', 'cpms_invoice_read', 'cpms_finance_read'] as $capability) {
            $repository->set_capability((int) $managerMembership['id'], $capability, 'grant');
        }

        // Sensitive finance capabilities can never even enter a definition.
        foreach (['cpms_payment_void', 'cpms_payment_refund', 'cpms_invoice_void', 'cpms_invoice_adjust', 'cpms_export'] as $sensitive) {
            self::assertFalse(CustomRolePolicy::is_editable_capability($sensitive), $sensitive . ' must never be delegable through a custom role');
        }

        $this->definitions()->create($this->managerUserId, $this->clinicA, 'sr_reception_cash', 'Reception + payments', [
            'cpms_patient_read',
            'cpms_appt_read',
            'cpms_invoice_read',
            'cpms_payment_create',
            'cpms_finance_read',
        ]);

        $assigned = $this->service()->assign($this->managerUserId, $this->clinicA, $this->secretaryUserId, 'sr_reception_cash');
        self::assertTrue($assigned['changed']);

        // Payment collection is authorized…
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_payment_create'));
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_invoice_read'));
        self::assertTrue(App::authorization_service()->can($this->secretaryUserId, $this->clinicA, 'cpms_finance_read'));
        // …while refund/void/adjust/export and every clinical capability are not.
        foreach ([
            'cpms_payment_void',
            'cpms_payment_refund',
            'cpms_invoice_void',
            'cpms_invoice_adjust',
            'cpms_export',
            'cpms_audit_read',
            'cpms_medical_read',
            'cpms_private_note_read',
            'cpms_rx_read',
            'cpms_rx_create',
            'cpms_consult_start',
            'cpms_note_create',
            'cpms_config',
        ] as $must_be_absent) {
            self::assertFalse(
                App::authorization_service()->can($this->secretaryUserId, $this->clinicA, $must_be_absent),
                'a reception+payments role must not carry ' . $must_be_absent
            );
        }
        // Accounting access does not imply clinical access for the accountant preset either.
        $accountantUserId = $this->makeUser('sr_acc_' . bin2hex(random_bytes(2)), RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($accountantUserId, $this->clinicA, RolesAndCapabilities::ROLE_ACCOUNTANT);
        self::assertTrue(App::authorization_service()->can($accountantUserId, $this->clinicA, 'cpms_finance_read'));
        self::assertFalse(App::authorization_service()->can($accountantUserId, $this->clinicA, 'cpms_medical_read'));

        // Honest boundary: an assigner who does NOT effectively hold those
        // non-sensitive finance capabilities cannot hand them over (decision 4),
        // so the built-in preset or an explicit GRANT row remains the mechanism.
        $plainManager = $this->makeUser('sr_mgr_plain_' . bin2hex(random_bytes(2)));
        cpms_test_seed_membership($plainManager, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        try {
            $this->service()->assign($plainManager, $this->clinicA, $this->membershipOnlyUserId, 'sr_reception_cash');
            self::fail('an assigner without the finance capabilities must not delegate them');
        } catch (CustomRoleAssignmentException $e) {
            self::assertSame(CustomRoleAssignmentException::CODE_CAPABILITY_NOT_DELEGABLE, $e->reason_code());
        }
    }

    // ================= fixtures / helpers =================

    /**
     * @return array<string, mixed>
     */
    private function membership(int $clinicId, int $userId): array
    {
        $row = (new MembershipRepository(App::db()))->find($clinicId, $userId);
        self::assertNotNull($row, 'membership fixture must exist');

        return $row;
    }

    private function membershipCount(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE wp_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $userId
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function capabilityRows(int $membershipId): array
    {
        return (new MembershipRepository(App::db()))->capabilities_for($membershipId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auditRow(string $action, int $resourceId): ?array
    {
        return App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            [$action, $resourceId]
        );
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clinicianRow(int $clinicianId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        );
        self::assertNotNull($row);

        return $row;
    }

    private function clinicianRowsForUser(int $userId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d',
            [$userId]
        );
    }

    private function renderStaffPage(int $clinicId): string
    {
        $_GET['clinic_id'] = $clinicId;
        ob_start();
        try {
            StaffManagementPage::render();
        } finally {
            $html = (string) ob_get_clean();
            unset($_GET['clinic_id']);
        }

        return $html;
    }

    /**
     * Rewrites every INSERT into the audit table into a statement naming a column
     * that does not exist (real MySQL 1054), so wpdb::insert() genuinely fails.
     */
    private function failAuditInserts(): void
    {
        global $wpdb;

        self::assertNull($this->auditFault, 'the audit fault must not be installed twice');

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1 && str_contains($m[1], 'cpms_audit_logs')) {
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

    private function makeClinic(int $organizationId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $organizationId,
            'Staff role assignment ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@crsa.test');
        self::assertGreaterThan(0, $userId, "user {$login} created");
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}

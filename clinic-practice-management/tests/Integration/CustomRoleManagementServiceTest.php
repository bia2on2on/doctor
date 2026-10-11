<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Roles\CustomRoleManagementException;
use ClinicCore\Application\Roles\CustomRoleManagementService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 2 (authorized Clinic-local
 * management of custom-role DEFINITIONS).
 *
 * Contract under test (per the approved Product Decision record):
 *  - authorized Clinic-local listing / create / rename / capability replacement;
 *  - display names are Unicode-general and persist byte-for-byte;
 *  - the technical role key is immutable and independent of the display name;
 *  - cross-Clinic objects are not addressable, forged Clinic selectors fail;
 *  - suspended / non-member actors are denied; a WordPress administrator without
 *    a durable Clinic membership is denied even with global manage_options +
 *    global cpms_config;
 *  - an editor may only delegate capabilities it effectively holds in that
 *    Clinic (membership DENY beats the role preset);
 *  - sensitive / unknown / WordPress / WooCommerce capabilities are refused, and
 *    a refused write leaves the previous capability set AND version intact;
 *  - every successful mutation is audited; a refused write writes no audit row.
 */
final class CustomRoleManagementServiceTest extends WP_UnitTestCase
{
    private int $clinicA;
    private int $clinicB;

    private int $managerUserId;
    private int $adminManagerUserId;
    private int $adminWithoutMembershipUserId;
    private int $suspendedUserId;
    private int $nonMemberUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'organization must exist');

        $this->clinicA = $this->makeClinic($orgId, 'crmA');
        $this->clinicB = $this->makeClinic($orgId, 'crmB');
        self::assertNotSame($this->clinicA, $this->clinicB);

        $this->managerUserId                = $this->makeUser('crm_mgr_' . bin2hex(random_bytes(2)));
        $this->adminManagerUserId           = $this->makeUser('crm_adm_mgr_' . bin2hex(random_bytes(2)), 'administrator');
        $this->adminWithoutMembershipUserId = $this->makeUser('crm_adm_none_' . bin2hex(random_bytes(2)), 'administrator');
        $this->suspendedUserId              = $this->makeUser('crm_sus_' . bin2hex(random_bytes(2)));
        $this->nonMemberUserId              = $this->makeUser('crm_non_' . bin2hex(random_bytes(2)));

        foreach ([$this->adminManagerUserId, $this->adminWithoutMembershipUserId] as $adminId) {
            $admin = get_userdata($adminId);
            self::assertNotFalse($admin);
            $admin->add_cap('manage_options');
            $admin->add_cap('cpms_config');
        }

        // Durable, Clinic-local management authority: an ACTIVE membership whose
        // role preset carries cpms_config (cpms_manager).
        App::membership_service()->create_membership($this->clinicA, $this->managerUserId, \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER);
        App::membership_service()->create_membership($this->clinicA, $this->adminManagerUserId, \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER);

        $suspendedMembership = App::membership_service()->create_membership(
            $this->clinicA,
            $this->suspendedUserId,
            \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER
        );
        App::membership_service()->suspend_membership($suspendedMembership);
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    private function service(): CustomRoleManagementService
    {
        return App::custom_role_management_service();
    }

    // ------------------------------------------------------------------
    // 1 + 2 — authorized listing and creation in the trusted Clinic.
    // ------------------------------------------------------------------

    public function testAuthorizedManagerListsOnlyOwnClinicDefinitions(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_alpha', 'Alpha role', ['cpms_patient_read']);

        // A different Clinic's definition (created by an admin-manager there) must
        // never appear in Clinic A's list.
        App::membership_service()->create_membership($this->clinicB, $this->adminManagerUserId, \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER);
        $this->service()->create($this->adminManagerUserId, $this->clinicB, 'crm_beta', 'Beta role', ['cpms_patient_read']);

        $listing = $this->service()->list_definitions($this->managerUserId, $this->clinicA);

        self::assertSame($this->clinicA, $listing['clinic_id']);
        self::assertSame(['crm_alpha'], array_column($listing['definitions'], 'role_key'));
        self::assertSame(['Alpha role'], array_column($listing['definitions'], 'display_name'));
    }

    public function testAuthorizedManagerCreatesClinicLocalDefinitionThatResolvesInThatClinicOnly(): void
    {
        $created = $this->service()->create(
            $this->managerUserId,
            $this->clinicA,
            'crm_access',
            'Access desk',
            ['cpms_patient_read', 'cpms_search']
        );

        self::assertSame($this->clinicA, $created['clinic_id']);
        self::assertSame('crm_access', $created['role_key']);
        self::assertSame(['cpms_patient_read', 'cpms_search'], $created['capabilities']);
        self::assertSame(1, $created['version']);

        // Operational proof through the EXISTING authorization engine only.
        $member = $this->makeUser('crm_member_' . bin2hex(random_bytes(2)));
        App::membership_service()->create_membership($this->clinicA, $member, 'crm_access');
        self::assertTrue(App::authorization_service()->can($member, $this->clinicA, 'cpms_patient_read'));
        self::assertFalse(App::authorization_service()->can($member, $this->clinicB, 'cpms_patient_read'));
    }

    // ------------------------------------------------------------------
    // 3 — authorized display-name editing; Unicode persistence.
    // ------------------------------------------------------------------

    public function testAuthorizedDisplayNameEditingPersistsUnicodeAndLeavesKeyAndCapabilities(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_unicode', 'Room 3', ['cpms_patient_read']);

        $unicode = 'پذیرش ارشد — Старшая 受付 3';
        $renamed = $this->service()->rename($this->managerUserId, $this->clinicA, 'crm_unicode', $unicode);

        self::assertSame($unicode, $renamed['display_name']);
        self::assertSame('crm_unicode', $renamed['role_key'], 'the technical key must be untouched by a rename');
        self::assertSame(2, $renamed['version'], 'a rename advances the durable version');
        self::assertSame(['cpms_patient_read'], $renamed['capabilities'], 'a rename must not alter the capability set');

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_unicode');
        self::assertNotNull($stored);
        self::assertSame($unicode, $stored['display_name'], 'the Unicode display name must round-trip byte-for-byte');
    }

    public function testInvalidDisplayNameIsRejectedAndPreviousValueIsPreserved(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_namereject', 'Valid name', ['cpms_patient_read']);

        try {
            $this->service()->rename($this->managerUserId, $this->clinicA, 'crm_namereject', "   \n\t ");
            self::fail('a whitespace-only display name must be rejected');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_INVALID_DISPLAY_NAME, $e->reason_code());
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_namereject');
        self::assertNotNull($stored);
        self::assertSame('Valid name', $stored['display_name']);
        self::assertSame(1, $stored['version'], 'a rejected rename must not advance the version');
    }

    // ------------------------------------------------------------------
    // 4 + 9 — capability-set editing bounded by the editor's own authority.
    // ------------------------------------------------------------------

    public function testAuthorizedCapabilityReplacementWithinEffectiveAuthority(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_caps', 'Caps role', ['cpms_patient_read']);

        // cpms_manager preset holds patient_read/report_read/search — all delegable.
        $updated = $this->service()->replace_capabilities(
            $this->managerUserId,
            $this->clinicA,
            'crm_caps',
            ['cpms_report_read', 'cpms_search', 'cpms_patient_read']
        );

        self::assertSame(['cpms_patient_read', 'cpms_report_read', 'cpms_search'], $updated['capabilities']);
        self::assertSame(2, $updated['version']);
    }

    public function testEditorCannotDelegateCapabilitiesOutsideEffectiveAuthority(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_bounded', 'Bounded', ['cpms_patient_read']);

        // cpms_appt_read is editable in general, but this editor does not hold it.
        try {
            $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crm_bounded', ['cpms_appt_read']);
            self::fail('delegating a capability outside effective authority must be refused');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_CAPABILITY_NOT_DELEGABLE, $e->reason_code());
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_bounded');
        self::assertNotNull($stored);
        self::assertSame(['cpms_patient_read'], $stored['capabilities'], 'the previous capability set must be preserved');
        self::assertSame(1, $stored['version'], 'a refused write must not advance the version');
    }

    public function testCreateRefusesCapabilitiesOutsideEffectiveAuthorityAndWritesNothing(): void
    {
        try {
            $this->service()->create($this->managerUserId, $this->clinicA, 'crm_outside', 'Outside', ['cpms_medical_read']);
            self::fail('create must refuse capabilities the editor does not hold');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_CAPABILITY_NOT_DELEGABLE, $e->reason_code());
        }

        self::assertNull((new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_outside'));
    }

    public function testMembershipDenyBeatsRolePresetForDelegation(): void
    {
        // The manager preset grants cpms_patient_read; an explicit membership DENY
        // must still prevent delegating it.
        $membership = App::membership_service()->membership_for($this->clinicA, $this->managerUserId);
        self::assertNotNull($membership);
        App::membership_service()->set_capability((int) $membership['id'], 'cpms_patient_read', 'deny');

        self::assertFalse(App::authorization_service()->can($this->managerUserId, $this->clinicA, 'cpms_patient_read'));

        try {
            $this->service()->create($this->managerUserId, $this->clinicA, 'crm_denied', 'Denied', ['cpms_patient_read']);
            self::fail('a membership DENY must block delegation of that capability');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_CAPABILITY_NOT_DELEGABLE, $e->reason_code());
        }

        // The management gate itself is also subject to DENY.
        App::membership_service()->set_capability((int) $membership['id'], \ClinicCore\Auth\RolesAndCapabilities::CONFIG, 'deny');
        self::assertFalse($this->service()->can_manage($this->managerUserId, $this->clinicA));

        $this->expectException(AuthorizationException::class);
        $this->service()->list_definitions($this->managerUserId, $this->clinicA);
    }

    // ------------------------------------------------------------------
    // 11 — sensitive / WP / WooCommerce / unknown capability rejection.
    // ------------------------------------------------------------------

    public function testSensitiveWordpressWooCommerceAndUnknownCapabilitiesAreRejected(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_forbidden', 'Forbidden', ['cpms_patient_read']);

        foreach ([
            'cpms_export' => 'sensitive CPMS capability',
            'cpms_config' => 'the management capability itself',
            'manage_options' => 'WordPress core capability',
            'woocommerce_manage_orders' => 'WooCommerce capability',
            'cpms_not_a_real_cap' => 'unknown string',
        ] as $capability => $label) {
            try {
                $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crm_forbidden', [$capability]);
                self::fail("{$label} must be rejected");
            } catch (CustomRoleManagementException $e) {
                self::assertSame(CustomRoleManagementException::CODE_CAPABILITY_NOT_ALLOWED, $e->reason_code());
            }
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_forbidden');
        self::assertNotNull($stored);
        self::assertSame(['cpms_patient_read'], $stored['capabilities']);
        self::assertSame(1, $stored['version']);
    }

    // ------------------------------------------------------------------
    // 12 — immutable technical key.
    // ------------------------------------------------------------------

    public function testTechnicalRoleKeyIsImmutable(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_immutable', 'First', ['cpms_patient_read']);
        $this->service()->rename($this->managerUserId, $this->clinicA, 'crm_immutable', 'Second');
        $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crm_immutable', ['cpms_search']);

        global $wpdb;
        $keys = $wpdb->get_col('SELECT role_key FROM ' . App::db()->table('cpms_custom_role_defs') . " WHERE clinic_id = {$this->clinicA} ORDER BY id"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame(['crm_immutable'], array_values(array_map('strval', (array) $keys)));

        // Re-defining the same key in the same Clinic is an explicit conflict.
        try {
            $this->service()->create($this->managerUserId, $this->clinicA, 'crm_immutable', 'Duplicate', ['cpms_search']);
            self::fail('a duplicate key must not be redefined');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_KEY_CONFLICT, $e->reason_code());
        }

        // No writer API exists for changing a key (immutability by absence).
        foreach (['rename_role_key', 'set_role_key', 'update_role_key', 'replace_role_key'] as $method) {
            self::assertFalse(method_exists(CustomRoleRepository::class, $method), "CustomRoleRepository::{$method} must not exist");
            self::assertFalse(method_exists(CustomRoleManagementService::class, $method), "CustomRoleManagementService::{$method} must not exist");
        }
    }

    public function testBuiltInAndMalformedRoleKeysAreRefused(): void
    {
        foreach (['cpms_doctor', '1abc', 'UPPER', 'ab', 'has-dash'] as $key) {
            try {
                $this->service()->create($this->managerUserId, $this->clinicA, $key, 'Key test', ['cpms_patient_read']);
                self::fail("role key {$key} must be refused");
            } catch (CustomRoleManagementException $e) {
                self::assertSame(CustomRoleManagementException::CODE_INVALID_ROLE_KEY, $e->reason_code());
            }
        }
    }

    // ------------------------------------------------------------------
    // 5 + 6 — cross-Clinic objects and forged Clinic selectors.
    // ------------------------------------------------------------------

    public function testCrossClinicDefinitionIsNotAddressableThroughTrustedClinic(): void
    {
        App::membership_service()->create_membership($this->clinicB, $this->adminManagerUserId, \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER);
        $this->service()->create($this->adminManagerUserId, $this->clinicB, 'crm_cross', 'Clinic B only', ['cpms_patient_read']);

        // Same actor is ALSO authorized in Clinic A, but the definition does not
        // exist there: it must not be addressable (no cross-Clinic fallback).
        try {
            $this->service()->rename($this->adminManagerUserId, $this->clinicA, 'crm_cross', 'Hijacked');
            self::fail('a foreign-Clinic definition must not be editable through this Clinic');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_NOT_FOUND, $e->reason_code());
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'crm_cross');
        self::assertNotNull($stored);
        self::assertSame('Clinic B only', $stored['display_name'], 'the other Clinic definition must be untouched');
        self::assertSame(1, $stored['version']);
    }

    public function testForgedClinicSelectorIsDeniedWithoutWriting(): void
    {
        // The actor manages Clinic A only; Clinic B is a real Clinic.
        foreach ([$this->clinicB, 999999, 0, -1] as $forged) {
            try {
                $this->service()->create($this->managerUserId, $forged, 'crm_forged', 'Forged', ['cpms_patient_read']);
                self::fail("selector {$forged} must not establish authority");
            } catch (ScopeRequiredException|AuthorizationException $e) {
                self::assertTrue(true, 'denied as expected');
            }
        }

        self::assertNull((new CustomRoleRepository(App::db()))->definition_for($this->clinicB, 'crm_forged'));
        self::assertNull((new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_forged'));
    }

    // ------------------------------------------------------------------
    // 7 + 8 — suspended, non-member and WordPress-administrator denial.
    // ------------------------------------------------------------------

    public function testSuspendedMembershipIsDenied(): void
    {
        self::assertFalse($this->service()->can_manage($this->suspendedUserId, $this->clinicA));

        $this->expectException(AuthorizationException::class);
        $this->service()->create($this->suspendedUserId, $this->clinicA, 'crm_suspended', 'Suspended', ['cpms_patient_read']);
    }

    public function testNonMemberIsDenied(): void
    {
        self::assertFalse($this->service()->can_manage($this->nonMemberUserId, $this->clinicA));

        $this->expectException(ScopeRequiredException::class);
        $this->service()->create($this->nonMemberUserId, $this->clinicA, 'crm_nonmember', 'Non-member', ['cpms_patient_read']);
    }

    public function testWordpressAdministratorWithoutClinicMembershipIsDenied(): void
    {
        $adminId = $this->adminWithoutMembershipUserId;

        // Global WordPress authority is present and is deliberately NOT enough.
        $admin = get_userdata($adminId);
        self::assertNotFalse($admin);
        self::assertTrue($admin->has_cap('manage_options'));
        self::assertTrue($admin->has_cap(\ClinicCore\Auth\RolesAndCapabilities::CONFIG));

        self::assertFalse($this->service()->can_manage($adminId, $this->clinicA));
        self::assertFalse($this->service()->can_manage($adminId, $this->clinicB));

        try {
            $this->service()->create($adminId, $this->clinicA, 'crm_admin_implicit', 'Implicit', ['cpms_patient_read']);
            self::fail('administrator status alone must never grant Clinic authority');
        } catch (ScopeRequiredException|AuthorizationException $e) {
            self::assertTrue(true, 'denied as expected');
        }

        self::assertNull((new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_admin_implicit'));
    }

    public function testAuthorizedAdministratorWithExplicitMembershipMayManage(): void
    {
        // The same administrator, with an explicit durable membership, is allowed.
        self::assertTrue($this->service()->can_manage($this->adminManagerUserId, $this->clinicA));

        $created = $this->service()->create($this->adminManagerUserId, $this->clinicA, 'crm_admin_explicit', 'Explicit', ['cpms_patient_read']);
        self::assertSame($this->clinicA, $created['clinic_id']);
    }

    // ------------------------------------------------------------------
    // 13 + 14 — failed writes preserve state; audit consistency.
    // ------------------------------------------------------------------

    public function testFailedWritePreservesCapabilitySetAndVersion(): void
    {
        $this->service()->create($this->managerUserId, $this->clinicA, 'crm_atomic', 'Atomic', ['cpms_patient_read', 'cpms_search']);

        try {
            $this->service()->replace_capabilities(
                $this->managerUserId,
                $this->clinicA,
                'crm_atomic',
                ['cpms_search', 'cpms_export'] // one allowed + one sensitive => all-or-nothing refusal
            );
            self::fail('a partially invalid capability set must be refused');
        } catch (CustomRoleManagementException $e) {
            self::assertSame(CustomRoleManagementException::CODE_CAPABILITY_NOT_ALLOWED, $e->reason_code());
        }

        $stored = (new CustomRoleRepository(App::db()))->definition_for($this->clinicA, 'crm_atomic');
        self::assertNotNull($stored);
        self::assertSame(['cpms_patient_read', 'cpms_search'], $stored['capabilities']);
        self::assertSame(1, $stored['version']);
    }

    public function testEverySuccessfulMutationIsAuditedAndFailedWritesAreNot(): void
    {
        global $wpdb;
        $logs = App::db()->table('cpms_audit_logs');

        $created = $this->service()->create($this->managerUserId, $this->clinicA, 'crm_audit', 'Audit role', ['cpms_patient_read']);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT action, clinic_id, actor_wp_user_id, after_json FROM {$logs} WHERE resource_type = 'custom_role_definition' AND resource_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $created['id']
        ), ARRAY_A);

        self::assertCount(1, (array) $rows);
        self::assertSame('CUSTOM_ROLE_DEFINED', (string) $rows[0]['action']);
        self::assertSame($this->clinicA, (int) $rows[0]['clinic_id'], 'the audit row must carry the exact Clinic');
        self::assertSame($this->managerUserId, (int) $rows[0]['actor_wp_user_id']);
        $this->service()->rename($this->managerUserId, $this->clinicA, 'crm_audit', 'Audit role renamed');
        $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crm_audit', ['cpms_search']);

        $actions = array_map(
            'strval',
            (array) $wpdb->get_col($wpdb->prepare(
                "SELECT action FROM {$logs} WHERE resource_type = 'custom_role_definition' AND resource_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $created['id']
            ))
        );
        self::assertSame(
            ['CUSTOM_ROLE_DEFINED', 'CUSTOM_ROLE_DISPLAY_NAME_CHANGED', 'CUSTOM_ROLE_CAPABILITIES_CHANGED'],
            $actions
        );

        // Failed writes add no audit rows for this definition.
        $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE resource_type = 'custom_role_definition' AND resource_id = %d", $created['id'])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        try {
            $this->service()->replace_capabilities($this->managerUserId, $this->clinicA, 'crm_audit', ['cpms_export', 'cpms_config']);
            self::fail('sensitive capabilities must be refused');
        } catch (CustomRoleManagementException $e) {
            self::assertTrue(true, 'refused as expected');
        }
        $after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs} WHERE resource_type = 'custom_role_definition' AND resource_id = %d", $created['id'])); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertSame($before, $after, 'a refused write must not be audited as a change');

        // The audit chain stays verifiable after these writes.
        self::assertTrue(App::audit()->verifyChain(500)['ok'], 'audit hash chain must remain intact');
    }

    // ================= fixtures =================

    private function makeClinic(int $orgId, string $prefix): int
    {
        global $wpdb;
        $slug = $prefix . '-' . bin2hex(random_bytes(4));
        $now  = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $orgId,
            'CustomRole Mgmt ' . $slug,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@crm.test');
        self::assertGreaterThan(0, $userId, "user {$login} created");
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}

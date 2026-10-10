<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

/**
 * Custom Role & Permission Management — Slice 1 (TEST-ONLY RED): Clinic-local
 * custom-role persistence + authorization-resolution contracts.
 *
 * Scope of this suite (backend foundations ONLY):
 *  - Clinic-local custom role definitions resolve ONLY for the exact membership
 *    Clinic through the existing AuthorizationService precedence:
 *    membership DENY > membership GRANT > role capabilities > default DENY.
 *  - Missing / inactive / malformed / foreign-Clinic definitions supply nothing.
 *  - Forbidden capability sets can neither be written through the repository
 *    boundary nor granted even when raw rows bypass it.
 *  - Built-in roles, suspended/non-member denial, WP-administrator
 *    non-authority, object ownership and tenant isolation remain unchanged.
 *
 * Fixture note: `insertCustomRoleDefRaw()` deliberately writes rows with raw SQL
 * (persistence level). That is the negative-control "bypassing writer" of the
 * security contract: the authorization read path must still fail closed on any
 * capability outside the approved catalogue.
 *
 * RED expectations on the pre-implementation tree:
 *  - tests asserting a custom role RESOLVES a capability fail (resolution absent);
 *  - tests whose only assertions are fail-closed negatives pass (default DENY
 *    already satisfies them) — reported honestly as already-green;
 *  - tests instantiating CustomRoleRepository error (class not implemented).
 */
final class CustomRoleAuthorizationTest extends WP_UnitTestCase
{
    private int $clinicA;
    private int $clinicB;

    private int $memberUserId;
    private int $multiClinicUserId;
    private int $suspendedUserId;
    private int $nonMemberUserId;
    private int $adminUserId;
    private int $doctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $now   = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'organization must exist');

        $slugA = 'cxra-' . bin2hex(random_bytes(4));
        $slugB = 'cxrb-' . bin2hex(random_bytes(4));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'CustomRole Clinic A ' . $slugA,
                $slugA,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->clinicA = (int) $wpdb->insert_id;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'CustomRole Clinic B ' . $slugB,
                $slugB,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->clinicB = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        self::assertNotSame($this->clinicA, $this->clinicB);

        $this->memberUserId       = $this->makeUser('cxr_mem_' . bin2hex(random_bytes(2)));
        $this->multiClinicUserId  = $this->makeUser('cxr_mul_' . bin2hex(random_bytes(2)));
        $this->suspendedUserId    = $this->makeUser('cxr_sus_' . bin2hex(random_bytes(2)));
        $this->nonMemberUserId    = $this->makeUser('cxr_non_' . bin2hex(random_bytes(2)));
        $this->adminUserId        = $this->makeUser('cxr_adm_' . bin2hex(random_bytes(2)), 'administrator');
        $this->doctorUserId       = $this->makeUser('cxr_doc_' . bin2hex(random_bytes(2)), 'cpms_doctor');

        $admin = get_userdata($this->adminUserId);
        self::assertNotFalse($admin);
        $admin->add_cap('manage_options');
        $admin->add_cap('cpms_config');
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Items 1 + 2: same custom role key resolves independently per Clinic;
    // a custom capability never leaks from one Clinic into another.
    // ------------------------------------------------------------------

    public function testSameCustomRoleKeyResolvesIndependentlyPerClinic(): void
    {
        $svc = App::authorization_service();
        $mem = App::membership_service();

        $mem->create_membership($this->clinicA, $this->multiClinicUserId, 'cx_shared');
        $mem->create_membership($this->clinicB, $this->multiClinicUserId, 'cx_shared');

        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_shared', ['cpms_patient_read', 'cpms_appt_read']);
        $this->insertCustomRoleDefRaw($this->clinicB, 'cx_shared', ['cpms_queue_read']);

        self::assertTrue(
            $svc->can($this->multiClinicUserId, $this->clinicA, 'cpms_patient_read'),
            'Clinic A definition must resolve for the exact membership Clinic'
        );
        self::assertFalse(
            $svc->can($this->multiClinicUserId, $this->clinicB, 'cpms_patient_read'),
            'Clinic A custom capability must never leak into Clinic B'
        );
        self::assertTrue(
            $svc->can($this->multiClinicUserId, $this->clinicB, 'cpms_queue_read'),
            'Clinic B definition must resolve independently for the same role key'
        );
        self::assertFalse(
            $svc->can($this->multiClinicUserId, $this->clinicA, 'cpms_queue_read'),
            'Clinic B custom capability must never leak into Clinic A'
        );
    }

    public function testForeignClinicDefinitionNeverSuppliesPermissions(): void
    {
        $svc = App::authorization_service();
        $mem = App::membership_service();

        // Same role key exists only as a Clinic A definition; the membership lives in B.
        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_only_a', ['cpms_patient_read']);
        $mem->create_membership($this->clinicB, $this->memberUserId, 'cx_only_a');

        self::assertFalse(
            $svc->can($this->memberUserId, $this->clinicB, 'cpms_patient_read'),
            'foreign-Clinic definition must not supply permissions'
        );

        // Positive control: the very same definition resolves inside its own Clinic.
        $mem->create_membership($this->clinicA, $this->multiClinicUserId, 'cx_only_a');
        self::assertTrue(
            $svc->can($this->multiClinicUserId, $this->clinicA, 'cpms_patient_read'),
            'in-Clinic definition must resolve (positive control)'
        );
    }

    // ------------------------------------------------------------------
    // Item 3: missing / inactive definitions provide no role-derived permission.
    // ------------------------------------------------------------------

    public function testMissingDefinitionSuppliesNoPermissions(): void
    {
        $svc = App::authorization_service();
        App::membership_service()->create_membership($this->clinicA, $this->memberUserId, 'cx_undefined');

        foreach (['cpms_patient_read', 'cpms_appt_read', 'cpms_queue_read'] as $permission) {
            self::assertFalse(
                $svc->can($this->memberUserId, $this->clinicA, $permission),
                "missing definition must not derive permission {$permission}"
            );
        }
    }

    public function testInactiveDefinitionSuppliesNoPermissions(): void
    {
        global $wpdb;
        $svc  = App::authorization_service();
        $mem  = App::membership_service();
        $defs = App::db()->table('cpms_custom_role_defs');

        $mem->create_membership($this->clinicA, $this->memberUserId, 'cx_life');

        // Missing (not yet defined) => deny.
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'missing definition denies');

        $defId = $this->insertCustomRoleDefRaw($this->clinicA, 'cx_life', ['cpms_patient_read']);
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'active definition resolves');

        // Inactive => deny (same durable rows otherwise).
        $wpdb->query($wpdb->prepare('UPDATE ' . $defs . " SET status = 'inactive' WHERE id = %d", $defId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'inactive definition must not supply permissions');

        // Reactivation restores role-derived resolution on the next evaluation
        // (decision 7: role changes take effect on the next server-side evaluation).
        $wpdb->query($wpdb->prepare('UPDATE ' . $defs . " SET status = 'active' WHERE id = %d", $defId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'reactivated definition resolves again');
    }

    // ------------------------------------------------------------------
    // Item 4: suspended / non-member actors remain denied.
    // ------------------------------------------------------------------

    public function testSuspendedAndNonMemberActorsRemainDenied(): void
    {
        $svc = App::authorization_service();
        $mem = App::membership_service();

        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_susp', ['cpms_patient_read']);

        $suspId = $mem->create_membership($this->clinicA, $this->suspendedUserId, 'cx_susp');
        $mem->suspend_membership($suspId);

        self::assertFalse(
            $svc->can($this->suspendedUserId, $this->clinicA, 'cpms_patient_read'),
            'suspended member must be denied even when the custom role grants the capability'
        );
        $this->assertTypedDenial(
            'AUTH_SUSPENDED',
            403,
            fn () => $svc->authorize($this->suspendedUserId, $this->clinicA, 'cpms_patient_read'),
            'suspended member with custom role'
        );

        self::assertFalse(
            $svc->can($this->nonMemberUserId, $this->clinicA, 'cpms_patient_read'),
            'non-member must be denied even when a custom definition exists in the Clinic'
        );
        $this->assertTypedDenial(
            'AUTH_NO_MEMBERSHIP',
            403,
            fn () => $svc->authorize($this->nonMemberUserId, $this->clinicA, 'cpms_patient_read'),
            'non-member with custom definition present'
        );

        // Positive control: an active member with the same role key resolves it.
        $mem->create_membership($this->clinicA, $this->memberUserId, 'cx_susp');
        self::assertTrue(
            $svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'),
            'active member resolves the custom role (positive control)'
        );
    }

    // ------------------------------------------------------------------
    // Item 5 + 6: precedence — membership DENY > membership GRANT >
    // role capabilities > default DENY.
    // ------------------------------------------------------------------

    public function testExplicitMembershipDenyOverridesCustomRoleGrant(): void
    {
        $svc = App::authorization_service();
        $mem = App::membership_service();

        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_deny', ['cpms_patient_read', 'cpms_appt_read']);
        $membershipId = $mem->create_membership($this->clinicA, $this->memberUserId, 'cx_deny');

        // Role-derived allow (positive control).
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'role-derived permission resolves');

        // DENY > role capabilities.
        $mem->set_capability($membershipId, 'cpms_patient_read', 'deny');
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'explicit DENY overrides custom-role grant');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_appt_read'), 'DENY is capability-scoped, not role-wide');

        // GRANT path remains available and still loses to DENY on another capability.
        $mem->set_capability($membershipId, 'cpms_patient_read', 'grant');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'explicit GRANT allows');
        $mem->set_capability($membershipId, 'cpms_appt_read', 'deny');
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_appt_read'), 'explicit DENY beats role-derived allow');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'other capabilities are untouched');
    }

    public function testExplicitMembershipGrantPrecedenceUnchanged(): void
    {
        $svc = App::authorization_service();
        $mem = App::membership_service();

        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_grant', ['cpms_patient_read']);
        $membershipId = $mem->create_membership($this->clinicA, $this->memberUserId, 'cx_grant');

        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'role-derived permission resolves');
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_appt_create'), 'capability absent from the custom role is denied');

        // Explicit GRANT on the membership stays a first-class override source.
        $mem->set_capability($membershipId, 'cpms_appt_create', 'grant');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_appt_create'), 'explicit GRANT precedence unchanged');

        $mem->set_capability($membershipId, 'cpms_appt_create', 'deny');
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_appt_create'), 'explicit DENY replaces GRANT on the same capability');
    }

    // ------------------------------------------------------------------
    // Item 7: built-in roles remain backward-compatible; a custom definition
    // can never collide with (or augment) a built-in role key.
    // ------------------------------------------------------------------

    public function testBuiltInRolesRemainBackwardCompatible(): void
    {
        global $wpdb;
        $svc = App::authorization_service();
        $mem = App::membership_service();

        $mem->create_membership($this->clinicA, $this->doctorUserId, 'cpms_doctor');

        self::assertTrue($svc->can($this->doctorUserId, $this->clinicA, 'cpms_patient_read'), 'built-in doctor preset unchanged');
        self::assertFalse($svc->can($this->doctorUserId, $this->clinicA, 'cpms_export'), 'built-in doctor preset still excludes cpms_export');

        // Bypass attempt via raw rows: a "custom definition" using a built-in key
        // must be inert — built-in keys never consult custom definitions.
        $this->insertCustomRoleDefRaw($this->clinicA, 'cpms_doctor', ['cpms_export', 'cpms_patient_read']);

        self::assertFalse(
            $svc->can($this->doctorUserId, $this->clinicA, 'cpms_export'),
            'custom definition with a built-in key must never augment a built-in role'
        );
        self::assertTrue($svc->can($this->doctorUserId, $this->clinicA, 'cpms_patient_read'), 'built-in doctor preset unchanged after collision attempt');

        // Membership uniqueness and existing built-in doctor row remain intact.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id = %d AND wp_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicA,
                $this->doctorUserId
            )
        );
        self::assertSame(1, $count, 'no duplicate membership is created');
    }

    // ------------------------------------------------------------------
    // Item 8: forbidden / unknown / WordPress / WooCommerce / sensitive
    // capabilities cannot enter custom-role definitions.
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private static function forbiddenCapabilities(): array
    {
        return [
            'cpms_private_note_read',
            'cpms_private_note_create',
            'cpms_private_note_update',
            'cpms_patient_archive',
            'cpms_patient_merge',
            'cpms_export',
            'cpms_audit_read',
            'cpms_payment_void',
            'cpms_payment_refund',
            'cpms_invoice_void',
            'cpms_invoice_adjust',
            'cpms_consult_reopen',
            'cpms_rx_void',
            'cpms_config',
            'cpms_sms_config',
            'read',
            'manage_options',
            'edit_posts',
            'manage_woocommerce',
            'woocommerce_manage_shop_orders',
            'cpms_unknown_capability',
            '',
        ];
    }

    public function testRepositoryWriteBoundaryRejectsForbiddenCapabilities(): void
    {
        $repo = new CustomRoleRepository(App::db());

        foreach (self::forbiddenCapabilities() as $cap) {
            $label = $cap === '' ? '(empty string)' : $cap;
            try {
                $repo->define($this->clinicA, 'cx_write_guard', ['cpms_patient_read', $cap]);
                self::fail("define() must reject forbidden capability: {$label}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('cap', strtolower($e->getMessage()), "rejection reason for {$label}");
            }

            try {
                $repo->define($this->clinicA, 'cx_write_guard', [$cap]);
                self::fail("define() must reject a set containing only: {$label}");
            } catch (\InvalidArgumentException $e) {
                self::assertTrue(true);
            }
        }

        // Nothing may be persisted by the rejected writes (all-or-nothing).
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_custom_role_defs WHERE clinic_id = %d AND role_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicA,
                'cx_write_guard'
            )
        );
        self::assertSame(0, $count, 'rejected writes must not persist definitions');

        // Mixed valid+forbidden sets are rejected as a whole (no partial write).
        try {
            $repo->define($this->clinicA, 'cx_write_guard', ['cpms_patient_read', 'cpms_export']);
            self::fail('mixed valid+forbidden set must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertTrue(true);
        }
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_custom_role_defs WHERE clinic_id = %d AND role_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicA,
                'cx_write_guard'
            )
        );
        self::assertSame(0, $count, 'mixed-set rejection must not persist anything');
    }

    public function testRepositoryWriteBoundaryRejectsCollidingAndMalformedRoleKeys(): void
    {
        $repo = new CustomRoleRepository(App::db());

        $badKeys = [
            'cpms_patient',
            'cpms_secretary',
            'cpms_doctor',
            'cpms_accountant',
            'cpms_manager',
            '',
            'a',
            '123',
            '0123',
            'UPPER',
            'space key',
            str_repeat('k', 65),
        ];
        foreach ($badKeys as $key) {
            try {
                $repo->define($this->clinicA, $key, ['cpms_patient_read']);
                self::fail('define() must reject role key: ' . var_export($key, true));
            } catch (\InvalidArgumentException $e) {
                self::assertTrue(true);
            }
        }
    }

    public function testRepositoryDefineStoresOnlyValidatedCapabilitySets(): void
    {
        $repo = new CustomRoleRepository(App::db());
        $svc  = App::authorization_service();
        App::membership_service()->create_membership($this->clinicA, $this->memberUserId, 'cx_via_repo');

        $defId = $repo->define($this->clinicA, 'cx_via_repo', ['cpms_patient_read', 'cpms_appt_read']);
        self::assertGreaterThan(0, $defId, 'valid definition is persisted');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'repository-defined role resolves');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_appt_read'), 'repository-defined role resolves all stored capabilities');

        // Immutable keys: a second define() on the same (clinic, key) fails loudly.
        try {
            $repo->define($this->clinicA, 'cx_via_repo', ['cpms_queue_read']);
            self::fail('second define() on the same key must be rejected');
        } catch (\RuntimeException $e) {
            self::assertTrue(true);
        }
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_queue_read'), 'failed redefine must not change the stored set');

        // Versioned, forward-only capability replacement.
        $repo->replace_capabilities($this->clinicA, 'cx_via_repo', ['cpms_queue_read']);
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'), 'replaced set no longer contains removed capabilities');
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_queue_read'), 'replaced set resolves the new capabilities');

        // Replacement is validated exactly like define().
        try {
            $repo->replace_capabilities($this->clinicA, 'cx_via_repo', ['cpms_export']);
            self::fail('replace_capabilities() must reject forbidden capabilities');
        } catch (\InvalidArgumentException $e) {
            self::assertTrue(true);
        }
        self::assertTrue($svc->can($this->memberUserId, $this->clinicA, 'cpms_queue_read'), 'rejected replacement leaves the stored set intact');
    }

    /**
     * Negative control for a bypassing writer: rows inserted with raw SQL (skipping
     * every write-path validation) must never grant a non-allowlisted capability,
     * while an allowlisted capability on the same rows still resolves.
     */
    public function testReadPathDropsForbiddenCapabilitiesEvenIfPersisted(): void
    {
        $svc = App::authorization_service();
        App::membership_service()->create_membership($this->clinicA, $this->memberUserId, 'cx_dirty');

        $this->insertCustomRoleDefRaw(
            $this->clinicA,
            'cx_dirty',
            [
                'cpms_patient_read',
                'cpms_private_note_read',
                'cpms_export',
                'cpms_audit_read',
                'cpms_config',
                'read',
                'manage_options',
                'cpms_unknown_capability',
            ]
        );

        self::assertTrue(
            $svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'),
            'allowlisted capability on the persisted rows resolves (positive control)'
        );

        foreach (self::forbiddenCapabilities() as $cap) {
            self::assertFalse(
                $svc->can($this->memberUserId, $this->clinicA, $cap === '' ? ' ' : $cap),
                'read path must drop capability even when persisted by a bypassing writer: ' . ($cap === '' ? '(empty)' : $cap)
            );
        }
        self::assertFalse($svc->can($this->memberUserId, $this->clinicA, 'cpms_unknown_capability'), 'unknown strings never grant');
    }

    // ------------------------------------------------------------------
    // Item 9: WordPress administrator status alone confers no clinical authority.
    // ------------------------------------------------------------------

    public function testWordPressAdministratorWithoutMembershipGainsNoClinicalAuthority(): void
    {
        $svc = App::authorization_service();

        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_adminish', ['cpms_patient_read']);

        self::assertFalse(
            $svc->can($this->adminUserId, $this->clinicA, 'cpms_patient_read'),
            'WP administrator without Clinic membership gains no authority from a custom definition'
        );
        self::assertFalse(
            $svc->can($this->adminUserId, $this->clinicA, 'cpms_config'),
            'WP administrator global caps are not Clinic authority'
        );
        $this->assertTypedDenial(
            'AUTH_NO_MEMBERSHIP',
            403,
            fn () => $svc->authorize($this->adminUserId, $this->clinicA, 'cpms_patient_read'),
            'WP administrator without membership'
        );

        // Positive control: a real member resolves the same definition.
        App::membership_service()->create_membership($this->clinicA, $this->memberUserId, 'cx_adminish');
        self::assertTrue(
            $svc->can($this->memberUserId, $this->clinicA, 'cpms_patient_read'),
            'member resolves the definition (positive control)'
        );
    }

    // ------------------------------------------------------------------
    // Item 10: object ownership and trusted Clinic boundaries remain intact.
    // ------------------------------------------------------------------

    public function testObjectOwnershipAndTrustedClinicBoundariesRemainIntact(): void
    {
        $svc = App::authorization_service();
        App::membership_service()->create_membership($this->clinicA, $this->memberUserId, 'cx_owner');
        $this->insertCustomRoleDefRaw($this->clinicA, 'cx_owner', ['cpms_patient_read']);

        $patientInB = $this->createPatientInClinic($this->clinicB, 'cxr-cross');
        $ownerB     = $this->getPatientClinicIdFromPersistence($patientInB);
        self::assertSame($this->clinicB, $ownerB, 'durable owner of the Clinic B patient is Clinic B');

        self::assertFalse(
            $svc->canForObject($this->memberUserId, $this->clinicA, 'cpms_patient_read', (int) $ownerB),
            'custom-role capability must not override cross-Clinic object ownership'
        );
        $this->assertTypedDenial(
            'AUTH_CROSS_CLINIC',
            403,
            fn () => $svc->authorizeForObject($this->memberUserId, $this->clinicA, 'cpms_patient_read', (int) $ownerB),
            'cross-Clinic object with custom role'
        );

        // Positive control: same-Clinic durable ownership + custom-role permission allows.
        $patientInA = $this->createPatientInClinic($this->clinicA, 'cxr-own');
        $ownerA     = $this->getPatientClinicIdFromPersistence($patientInA);
        self::assertSame($this->clinicA, $ownerA);
        self::assertTrue(
            $svc->canForObject($this->memberUserId, $this->clinicA, 'cpms_patient_read', (int) $ownerA),
            'custom-role permission applies to same-Clinic objects (positive control)'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeUser(string $login, string $role = 'subscriber'): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@cxr.test');
        self::assertGreaterThan(0, $userId, "user $login created");
        $u = get_userdata($userId);
        if ($u !== false) {
            $u->set_role($role);
        }

        return $userId;
    }

    /**
     * Persistence-level fixture (bypassing writer): raw rows, no write validation.
     * Returns the definition id (0 when the insert did not succeed — never asserted
     * here so pre-implementation RED stays attributable to the resolution contract).
     *
     * @param list<string> $capabilities
     */
    private function insertCustomRoleDefRaw(int $clinicId, string $roleKey, array $capabilities, string $status = 'active'): int
    {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $defs   = $wpdb->prefix . 'cpms_custom_role_defs';
        $defCap = $wpdb->prefix . 'cpms_custom_role_capabilities';

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$defs} (clinic_id, role_key, status, version, created_at, updated_at) VALUES (%d, %s, %s, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $roleKey,
                $status,
                1,
                $now,
                $now
            )
        );
        $defId = (int) $wpdb->insert_id;
        if ($defId <= 0) {
            return 0;
        }

        foreach ($capabilities as $capability) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$defCap} (role_def_id, capability, created_at) VALUES (%d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $defId,
                    $capability,
                    $now
                )
            );
        }

        return $defId;
    }

    /**
     * Helper: durable patient owned by a Clinic (fixture conventions of
     * AuthorizationServiceTest).
     */
    private function createPatientInClinic(int $clinicId, string $suffix): int
    {
        global $wpdb;
        $now    = App::db()->nowUtcSql();
        $mrn    = 'MR-CXR-' . $suffix . '-' . bin2hex(random_bytes(3));
        $mobile = '0915' . random_int(1000000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn,
                'CustomRole',
                $suffix,
                $mobile,
                'active',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "patient $suffix must be persisted in clinic $clinicId");

        return $id;
    }

    /**
     * Helper: durable owner clinic_id from persistence, never from payload.
     */
    private function getPatientClinicIdFromPersistence(int $patientId): ?int
    {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId
            )
        );

        return $val === null ? null : (int) $val;
    }

    /**
     * @param callable():mixed $call
     */
    private function assertTypedDenial(string $expectedCode, int $expectedHttpStatus, callable $call, string $context): AuthorizationException
    {
        try {
            $call();
        } catch (AuthorizationException $e) {
            self::assertSame($expectedCode, $e->getErrorCode(), $context . ': typed error code');
            self::assertSame($expectedHttpStatus, $e->getHttpStatus(), $context . ': HTTP status');

            return $e;
        }

        self::fail($context . ': expected ' . $expectedCode . ' but the call was authorized');
    }
}

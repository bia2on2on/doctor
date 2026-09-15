<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 1 — AuthorizationService foundation (TDD RED).
 *
 * Contracts:
 *  ALLOW: authenticated active member in Clinic A with required scoped permission.
 *  DENY: unauthenticated, non-member, suspended, without permission,
 *        admin without membership, cross-clinic object, untrusted clinic mismatch.
 *  MULTI-CLINIC: same actor independently authorized in Clinic A and B, no fallback.
 */
final class AuthorizationServiceTest extends WP_UnitTestCase
{
    private int $clinicA;
    private int $clinicB;
    private int $doctorUserId;
    private int $nonMemberUserId;
    private int $suspendedUserId;
    private int $adminUserId;
    private int $multiClinicUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::assertGreaterThan(0, $orgId, 'organization must exist');

        $slugA = 'authz-a-' . bin2hex(random_bytes(4));
        $slugB = 'authz-b-' . bin2hex(random_bytes(4));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $orgId,
                'Clinic AuthZ A ' . $slugA,
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
                'Clinic AuthZ B ' . $slugB,
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

        // Users
        $this->doctorUserId = $this->makeUser('authz_doc_' . bin2hex(random_bytes(2)), 'cpms_doctor');
        $this->nonMemberUserId = $this->makeUser('authz_non_' . bin2hex(random_bytes(2)), 'subscriber');
        $this->suspendedUserId = $this->makeUser('authz_sus_' . bin2hex(random_bytes(2)), 'cpms_doctor');
        $this->adminUserId = $this->makeUser('authz_admin_' . bin2hex(random_bytes(2)), 'administrator');
        $this->multiClinicUserId = $this->makeUser('authz_multi_' . bin2hex(random_bytes(2)), 'cpms_doctor');

        // Admin cap check
        $admin = get_userdata($this->adminUserId);
        self::assertNotFalse($admin);
        $admin->add_cap('manage_options');
        $admin->add_cap('cpms_config');

        $membership = App::membership_service();

        // doctorUser in clinicA as doctor (has cpms_patient_read via preset)
        $membership->create_membership($this->clinicA, $this->doctorUserId, 'cpms_doctor');

        // suspendedUser in clinicA then suspend
        $suspId = $membership->create_membership($this->clinicA, $this->suspendedUserId, 'cpms_doctor');
        $membership->suspend_membership($suspId);

        // multiClinicUser in both clinics as doctor
        $membership->create_membership($this->clinicA, $this->multiClinicUserId, 'cpms_doctor');
        $membership->create_membership($this->clinicB, $this->multiClinicUserId, 'cpms_doctor');

        // No membership for nonMemberUser and adminUser (admin has global caps but no clinic membership)
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@authz.test');
        self::assertGreaterThan(0, $userId, "user $login created");
        $u = get_userdata($userId);
        if ($u !== false) {
            $u->set_role($role);
        }
        return $userId;
    }

    private function authzService(): \ClinicCore\Application\Authorization\AuthorizationService
    {
        // Real repository path, not mock
        $repo = new MembershipRepository(App::db());
        return new \ClinicCore\Application\Authorization\AuthorizationService($repo);
    }

    public function testAllowAuthenticatedActiveMemberWithRequiredPermission(): void
    {
        $svc = $this->authzService();

        // doctorUser in clinicA has cpms_patient_read via doctor preset
        $allowed = $svc->can($this->doctorUserId, $this->clinicA, 'cpms_patient_read');
        self::assertTrue($allowed, 'active member with preset permission must be allowed');

        // also test authorize() does not throw
        $svc->authorize($this->doctorUserId, $this->clinicA, 'cpms_patient_read');
        self::assertTrue(true);
    }

    public function testDenyUnauthenticated(): void
    {
        $svc = $this->authzService();
        self::assertFalse($svc->can(0, $this->clinicA, 'cpms_patient_read'), 'unauthenticated must deny');
        try {
            $svc->authorize(0, $this->clinicA, 'cpms_patient_read');
            self::fail('unauthenticated should throw');
        } catch (\ClinicCore\Application\Authorization\AuthorizationException $e) {
            self::assertSame('AUTH_UNAUTHENTICATED', $e->getErrorCode());
        }
    }

    public function testDenyNonMember(): void
    {
        $svc = $this->authzService();
        self::assertFalse($svc->can($this->nonMemberUserId, $this->clinicA, 'cpms_patient_read'), 'non-member must deny');
    }

    public function testDenySuspended(): void
    {
        $svc = $this->authzService();
        self::assertFalse($svc->can($this->suspendedUserId, $this->clinicA, 'cpms_patient_read'), 'suspended must deny');
    }

    public function testDenyActiveMemberWithoutRequiredPermission(): void
    {
        $svc = $this->authzService();
        // doctor preset does NOT include cpms_export (only accountant)
        self::assertFalse($svc->can($this->doctorUserId, $this->clinicA, 'cpms_export'), 'member without permission must deny');
    }

    public function testDenyAdminWithoutMembership(): void
    {
        $svc = $this->authzService();
        // admin has manage_options + cpms_config globally but no clinic membership
        self::assertFalse($svc->can($this->adminUserId, $this->clinicA, 'cpms_patient_read'), 'admin without clinic membership must deny');
        self::assertFalse($svc->can($this->adminUserId, $this->clinicA, 'cpms_config'), 'even cpms_config without membership must deny for clinical auth');
    }

    public function testDenyCrossClinicObjectAccess(): void
    {
        $svc = $this->authzService();

        // --- Real persisted Clinic-owned object (cpms_patients) in Clinic B ---
        // Create a patient owned by Clinic B using normal fixture conventions
        $patientId = $this->createPatientInClinic($this->clinicB, 'authz-cross-b');
        self::assertGreaterThan(0, $patientId, 'patient fixture must be created in Clinic B');

        // Retrieve durable owner clinic_id server-side from persistence (never from payload)
        $durableOwnerClinicId = $this->getPatientClinicIdFromPersistence($patientId);
        self::assertNotNull($durableOwnerClinicId, 'durable owner clinic_id must be retrievable from persistence');
        self::assertSame($this->clinicB, $durableOwnerClinicId, 'retrieved durable owner must be Clinic B');

        // Actor is authorized in Clinic A, but object durably belongs to Clinic B
        // Should deny when independently retrieved durable object ownership contradicts trusted authorization Clinic
        $allowed = $svc->canForObject($this->multiClinicUserId, $this->clinicA, 'cpms_patient_read', $durableOwnerClinicId);
        self::assertFalse($allowed, 'cross-clinic object access must deny when durable ownership (from persistence) contradicts authorized clinic');

        try {
            $svc->authorizeForObject($this->multiClinicUserId, $this->clinicA, 'cpms_patient_read', $durableOwnerClinicId);
            self::fail('cross-clinic should throw');
        } catch (\ClinicCore\Application\Authorization\AuthorizationException $e) {
            self::assertSame('AUTH_CROSS_CLINIC', $e->getErrorCode());
        }
    }

    public function testDenyWhenDurableObjectOwnerDiffersFromAuthorizedClinic(): void
    {
        $svc = $this->authzService();

        // Use another real persisted object owned by Clinic B to prove ownership from persistence
        $patientId = $this->createPatientInClinic($this->clinicB, 'authz-untrusted-b');
        self::assertGreaterThan(0, $patientId, 'second patient fixture must be created in Clinic B');

        $durableOwnerClinicId = $this->getPatientClinicIdFromPersistence($patientId);
        self::assertSame($this->clinicB, $durableOwnerClinicId, 'durable owner retrieved from persistence must be Clinic B');

        // Trusted authorization Clinic = A, durable owner = B (retrieved from persistence) => deny
        self::assertFalse($svc->canForObject($this->multiClinicUserId, $this->clinicA, 'cpms_patient_read', $durableOwnerClinicId), 'must deny when trusted clinic A != durable owner B from persistence');
    }

    public function testMultiClinicIndependentAuthorization(): void
    {
        $svc = $this->authzService();
        // Same actor authorized in A and B independently, no fallback
        self::assertTrue($svc->can($this->multiClinicUserId, $this->clinicA, 'cpms_patient_read'), 'multi user must be allowed in clinic A');
        self::assertTrue($svc->can($this->multiClinicUserId, $this->clinicB, 'cpms_patient_read'), 'multi user must be allowed in clinic B');

        // Ensure no first-clinic fallback: requesting clinic 0 or invalid must deny, not pick first
        self::assertFalse($svc->can($this->multiClinicUserId, 0, 'cpms_patient_read'), 'clinic_id 0 must not fallback');
        self::assertFalse($svc->can($this->multiClinicUserId, 999999999, 'cpms_patient_read'), 'unknown clinic must deny');

        // Membership in A does not grant in B for a user only in A
        self::assertFalse($svc->can($this->doctorUserId, $this->clinicB, 'cpms_patient_read'), 'membership in A must not grant B');
    }

    public function testExplicitDenyOverridesGrantAndPreset(): void
    {
        $svc = $this->authzService();
        $membership = App::membership_service();

        // doctorUser has preset cpms_patient_read, now add explicit deny
        $mem = $membership->membership_for($this->clinicA, $this->doctorUserId);
        self::assertNotNull($mem);
        $membership->set_capability((int) $mem['id'], 'cpms_patient_read', 'deny');

        self::assertFalse($svc->can($this->doctorUserId, $this->clinicA, 'cpms_patient_read'), 'explicit deny must override preset');

        // Now test explicit grant for permission not in preset
        $membership->set_capability((int) $mem['id'], 'cpms_export', 'grant');
        self::assertTrue($svc->can($this->doctorUserId, $this->clinicA, 'cpms_export'), 'explicit grant must allow');

        // Explicit deny overrides grant (change effect to deny)
        $membership->set_capability((int) $mem['id'], 'cpms_export', 'deny');
        self::assertFalse($svc->can($this->doctorUserId, $this->clinicA, 'cpms_export'), 'explicit deny must override grant');
    }

    public function testNoImplicitGlobalClinic(): void
    {
        $svc = $this->authzService();
        // No clinic_id = 0 should never be treated as global
        self::assertFalse($svc->can($this->doctorUserId, 0, 'cpms_patient_read'));
        self::assertFalse($svc->can($this->multiClinicUserId, 0, 'cpms_patient_read'));
    }

    /**
     * Helper: create a real persisted patient owned by a specific clinic.
     * Uses normal fixture conventions (direct $wpdb insert) — smallest existing
     * clinic-owned durable object (cpms_patients).
     */
    private function createPatientInClinic(int $clinicId, string $suffix): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-AUTHZ-' . $suffix . '-' . bin2hex(random_bytes(3));
        $mobile = '0915' . random_int(1000000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $mrn,
                'AuthZ',
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
     * Helper: retrieve durable owner clinic_id server-side from persistence.
     * Never from payload/request.
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
}

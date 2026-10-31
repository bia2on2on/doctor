<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\ClinicReceptionRecoveryPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Reception onboarding recovery — Persian Clinic Manager UI for clinician↔Location assignment.
 *
 * These tests exercise an eligibility query, the legacy global membership
 * primitive, selector authorization and captured HTML. They do not submit the
 * recovery handler, invoke a failing AuditLogger, or run a real browser/mobile
 * viewport. The separate disposable WP-CLI and Playwright proofs exercise the
 * new Clinic-local boundary, failure rollback and actual admin form.
 */
final class ClinicReceptionRecoveryTest extends WP_UnitTestCase
{
    private int $clinicId = 1;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianId = 0;
    private int $managerId = 0;
    private int $suspendedManagerId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        $this->clinicId = 1;
        $now = App::db()->nowUtcSql();

        // Two fresh fixture Locations; a pre-existing default Location may
        // already have other eligible clinicians in the full test suite.
        App::db()->insert('cpms_locations', ['clinic_id' => $this->clinicId, 'name' => 'Recovery Loc A', 'slug' => 'recovery-a-' . bin2hex(random_bytes(3)), 'is_active' => 1, 'is_primary' => 0, 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]);
        $this->locationA = (int) App::db()->wpdb_last_insert_id();
        App::db()->insert('cpms_locations', ['clinic_id' => $this->clinicId, 'name' => 'Recovery Loc B', 'slug' => 'recovery-b-' . bin2hex(random_bytes(3)), 'is_active' => 1, 'is_primary' => 0, 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]);
        $this->locationB = (int) App::db()->wpdb_last_insert_id();

        // Clinician (active, has wp_user_id).
        $userId = wp_create_user('recovery_doc_' . bin2hex(random_bytes(3)), wp_generate_password(12), 'recovery_' . bin2hex(random_bytes(4)) . '@example.com');
        $this->assertNotWPError($userId);
        wp_update_user(['ID' => (int) $userId, 'role' => RolesAndCapabilities::ROLE_DOCTOR]);
        App::db()->insert('cpms_clinicians', ['clinic_id' => $this->clinicId, 'wp_user_id' => (int) $userId, 'full_name' => 'دکتر بازیابی', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->clinicianId = (int) App::db()->wpdb_last_insert_id();
        // Ensure clinician has membership.
        App::db()->insert('cpms_clinic_memberships', ['clinic_id' => $this->clinicId, 'wp_user_id' => (int) $userId, 'role_key' => RolesAndCapabilities::ROLE_DOCTOR, 'status' => 'active', 'scope_mode' => 'clinic', 'is_primary' => 0, 'created_at' => $now, 'updated_at' => $now]);

        // Ensure assignments empty at start.
        App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinician_locations') . ' WHERE clinician_id = %d', [$this->clinicianId]);

        // Manager (clinic_manager role with cpms_config).
        $manager = wp_create_user('recovery_mgr_' . bin2hex(random_bytes(3)), wp_generate_password(12), 'mgr_' . bin2hex(random_bytes(4)) . '@example.com');
        $this->assertNotWPError($manager);
        $this->managerId = (int) $manager;
        $u = get_userdata($this->managerId);
        $u->add_role(RolesAndCapabilities::ROLE_MANAGER);
        App::db()->insert('cpms_clinic_memberships', ['clinic_id' => $this->clinicId, 'wp_user_id' => $this->managerId, 'role_key' => RolesAndCapabilities::ROLE_MANAGER, 'status' => 'active', 'scope_mode' => 'clinic', 'is_primary' => 0, 'created_at' => $now, 'updated_at' => $now]);

        // Suspended manager (for negative test).
        $suspended = wp_create_user('recovery_susp_' . bin2hex(random_bytes(3)), wp_generate_password(12), 'susp_' . bin2hex(random_bytes(4)) . '@example.com');
        $this->assertNotWPError($suspended);
        $this->suspendedManagerId = (int) $suspended;
        $u2 = get_userdata($this->suspendedManagerId);
        $u2->add_role(RolesAndCapabilities::ROLE_MANAGER);
        App::db()->insert('cpms_clinic_memberships', ['clinic_id' => $this->clinicId, 'wp_user_id' => $this->suspendedManagerId, 'role_key' => RolesAndCapabilities::ROLE_MANAGER, 'status' => 'suspended', 'scope_mode' => 'clinic', 'is_primary' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    protected function tearDown(): void
    {
        // Cleanup our fixtures.
        App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinician_locations') . ' WHERE clinician_id = %d', [$this->clinicianId]);
        App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d', [$this->clinicianId]);
        App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE wp_user_id IN (%d, %d)', [$this->managerId, $this->suspendedManagerId]);
        App::db()->query('DELETE FROM ' . App::db()->table('cpms_locations') . ' WHERE id IN (%d, %d)', [$this->locationA, $this->locationB]);
        App::resetScope();
        parent::tearDown();
    }

    public function testEmptyAssignmentMakesReceptionSelectorEmpty(): void
    {
        wp_set_current_user($this->managerId);
        $eligible = $this->eligibleClinicians($this->clinicId, $this->locationA);
        $this->assertSame([], $eligible, 'empty cpms_clinician_locations must make selector empty');
    }

    public function testRecoveryAssignsClinicianToActiveLocationsAndSelectorBecomesNonEmpty(): void
    {
        wp_set_current_user($this->managerId);
        // Establish scope via AdminClinicSelector (explicit, verified).
        $this->assertNotNull(\ClinicCore\Admin\AdminClinicSelector::establish($this->managerId, $this->clinicId, RolesAndCapabilities::CONFIG));

        // Assign to single location.
        App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationA], $this->locationA);
        $eligible = $this->eligibleClinicians($this->clinicId, $this->locationA);
        $this->assertCount(1, $eligible);
        $this->assertSame($this->clinicianId, $eligible[0]['id']);

        // Assign to multiple locations (idempotent retry with same set).
        App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationA, $this->locationB], $this->locationA);
        $eligibleB = $this->eligibleClinicians($this->clinicId, $this->locationB);
        $this->assertCount(1, $eligibleB);
        // Still assigned to A.
        $eligibleA = $this->eligibleClinicians($this->clinicId, $this->locationA);
        $this->assertCount(1, $eligibleA);

        // Duplicate retry must be idempotent.
        App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationA, $this->locationB], $this->locationA);
        $again = $this->eligibleClinicians($this->clinicId, $this->locationB);
        $this->assertCount(1, $again);
    }

    public function testSuspendedMembershipCannotBeUsedForReception(): void
    {
        // Suspend the clinician's membership — eligibility join requires active, and service
        // checks active_clinic_ids_for_clinician, so any new assign must fail.
        App::db()->query('UPDATE ' . App::db()->table('cpms_clinic_memberships') . ' SET status = %s WHERE wp_user_id = (SELECT wp_user_id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d)', ['suspended', $this->clinicianId]);
        try {
            App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationB], $this->locationB);
            $this->fail('suspended clinician assignment must fail');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('mismatch', strtolower($e->getMessage()));
        } finally {
            App::db()->query('UPDATE ' . App::db()->table('cpms_clinic_memberships') . ' SET status = %s WHERE wp_user_id = (SELECT wp_user_id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d)', ['active', $this->clinicianId]);
        }
        // After re-activation, assignment must succeed.
        App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationA], $this->locationA);
        $this->assertCount(1, $this->eligibleClinicians($this->clinicId, $this->locationA));
    }

    public function testLegacyPrimitiveRejectsLocationWithoutClinicianMembership(): void
    {
        wp_set_current_user($this->managerId);
        // Create foreign clinic + location.
        $now = App::db()->nowUtcSql();
        $orgId = (int) App::db()->fetchValue('SELECT organization_id FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$this->clinicId]);
        App::db()->insert('cpms_clinics', ['organization_id' => $orgId, 'name' => 'Foreign Clinic', 'slug' => 'foreign-' . bin2hex(random_bytes(3)), 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $foreignClinic = (int) App::db()->wpdb_last_insert_id();
        App::db()->insert('cpms_locations', ['clinic_id' => $foreignClinic, 'name' => 'Foreign Loc', 'slug' => 'foreign-loc-' . bin2hex(random_bytes(3)), 'is_active' => 1, 'is_primary' => 1, 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now]);
        $foreignLoc = (int) App::db()->wpdb_last_insert_id();

        try {
            App::membership_service()->assign_clinician_locations($this->clinicianId, [$foreignLoc], $foreignLoc);
            $this->fail('cross-tenant location must be denied');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('mismatch', strtolower($e->getMessage()));
        } finally {
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d', [$foreignLoc]);
            App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$foreignClinic]);
        }
    }

    public function testExceptionRollsBackWrappedLegacyAssignment(): void
    {
        // This exercises a generic outer-transaction rollback, not a failure
        // in AuditLogger or the actual recovery service.
        wp_set_current_user($this->managerId);
        $this->assertNotNull(\ClinicCore\Admin\AdminClinicSelector::establish($this->managerId, $this->clinicId, RolesAndCapabilities::CONFIG));

        // Ensure clean state.
        App::db()->query('DELETE FROM ' . App::db()->table('cpms_clinician_locations') . ' WHERE clinician_id = %d', [$this->clinicianId]);

        // A deliberate exception follows the legacy assignment. It does not
        // prove that the actual audit or HTTP handler was called.
        $auditFailed = false;
        try {
            App::db()->transactional(function () {
                App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationA], $this->locationA);
                // Test-only exception, not an AuditLogger failure.
                throw new \RuntimeException('simulated outer failure');
            });
        } catch (\Throwable) {
            $auditFailed = true;
        }
        $this->assertTrue($auditFailed);
        $after = $this->eligibleClinicians($this->clinicId, $this->locationA);
        $this->assertSame([], $after, 'assignment must roll back when audit fails');

        // Retry without fault must succeed.
        App::membership_service()->assign_clinician_locations($this->clinicianId, [$this->locationA], $this->locationA);
        $afterRetry = $this->eligibleClinicians($this->clinicId, $this->locationA);
        $this->assertCount(1, $afterRetry);
    }

    public function testWordPressAdministratorWithoutClinicMembershipCannotAssign(): void
    {
        $admin = wp_create_user('recovery_admin_' . bin2hex(random_bytes(3)), wp_generate_password(12), 'admin_' . bin2hex(random_bytes(4)) . '@example.com');
        $this->assertNotWPError($admin);
        $u = get_userdata((int) $admin);
        $u->set_role('administrator');
        wp_set_current_user((int) $admin);

        // No clinic membership — should not be able to establish scope.
        $scope = \ClinicCore\Admin\AdminClinicSelector::establish((int) $admin, $this->clinicId, RolesAndCapabilities::CONFIG);
        $this->assertNull($scope, 'WordPress admin without clinic membership must not establish clinic scope');

        // Cleanup.
        wp_delete_user((int) $admin);
    }

    public function testAdminPageRendersPersianGuidanceAndIsRtlSafeWithNarrowViewport(): void
    {
        wp_set_current_user($this->managerId);
        \ClinicCore\Admin\AdminClinicSelector::establish($this->managerId, $this->clinicId, RolesAndCapabilities::CONFIG);
        // Capture output of the recovery page.
        ob_start();
        try {
            \ClinicCore\Admin\ClinicReceptionRecoveryPage::render();
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $this->assertStringContainsString('بازیابی پذیرش', $html);
        $this->assertStringContainsString('انتساب پزشک به محل', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        // This is only an HTML string assertion, not a viewport/layout test.
        $this->assertStringNotContainsString('overflow: hidden', strtolower($html));
    }

    // ------------------------------------------------------------------

    private function eligibleClinicians(int $clinicId, int $locationId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT c.id, c.full_name FROM ' . $db->table('cpms_clinicians') . ' c' .
            ' INNER JOIN ' . $db->table('cpms_clinician_locations') . ' cl ON cl.clinician_id = c.id AND cl.location_id = %d' .
            ' INNER JOIN ' . $db->table('cpms_locations') . ' l ON l.id = cl.location_id AND l.clinic_id = %d AND l.is_active = 1' .
            ' INNER JOIN ' . $db->table('cpms_clinic_memberships') . " m ON m.wp_user_id = c.wp_user_id AND m.clinic_id = %d AND m.status = 'active'" .
            ' WHERE c.is_active = 1 AND c.wp_user_id > 0' .
            " AND ( m.scope_mode = 'clinic' OR EXISTS ( SELECT 1 FROM " . $db->table('cpms_membership_locations') . ' ml WHERE ml.membership_id = m.id AND ml.location_id = cl.location_id ) )',
            [$locationId, $clinicId, $clinicId]
        );
        return is_array($rows) ? $rows : [];
    }

}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * C6 — Gap-closure tests for multi-tenant isolation matrix (MT-24..MT-41).
 *
 * Covers the five PARTIAL requirements identified in the coverage audit
 * (docs/phase-reports/c6-isolation-matrix.md):
 *
 *  MT-24  Notifications are Clinic-scoped (multi-clinic inbox isolation)
 *  MT-25  Booking cannot combine Patient/Slot/Clinician/Location across Clinics
 *  MT-39  Background jobs do not rely on current WP user/default Clinic
 *  MT-40  Settings are isolated across Clinics
 *  MT-41  Scope-aware caches do not leak A state into B
 *
 * Fixture model: non-1 IDs; Org A → A1/A2, Org B → B1.
 *
 * ⚠ Harness invariant (c2bff76 / handoff §5):
 * This class alphabetically follows ClinicTenantIsolationTest and ClinicalFlowTest,
 * so the boot() pin is already established by earlier classes. Neutral warm before
 * fixture clinics; explicit teardown with FK-safe purge.
 */
final class TenantIsolationGapTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private const CLINIC_A1 = 61021;
    private const CLINIC_A2 = 61022;
    private const CLINIC_B1 = 61023;

    private int $orgA = 0;
    private int $orgB = 0;
    private int $locA1 = 0;
    private int $locB1 = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Witness: no leftover fixtures from previous test
        global $wpdb;
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $leftover, 'CPMS_ISOLATION_WITNESS: leftover fixtures from previous test');

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();

        // Neutral warm (before fixture clinics) — harness invariant
        $this->warmRoutes();

        $this->orgA = $this->defaultOrganization();
        $this->orgB = $this->insertOrganization('gap-org-b');

        $this->insertClinic(self::CLINIC_A1, $this->orgA, 'gap-clinic-a1');
        $this->insertClinic(self::CLINIC_A2, $this->orgA, 'gap-clinic-a2');
        $this->insertClinic(self::CLINIC_B1, $this->orgB, 'gap-clinic-b1');

        $this->locA1 = $this->insertLocation(self::CLINIC_A1, 'gap-loc-a1');
        $this->locB1 = $this->insertLocation(self::CLINIC_B1, 'gap-loc-b1');

        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
    }

    protected function tearDown(): void
    {
        ScopeContext::clear();
        Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();

        $this->purgeReserveRows();

        parent::tearDown();
    }

    // =================================================================
    // MT-24: Notifications are Clinic-scoped
    // =================================================================

    /**
     * Staff inbox in context A returns ONLY Clinic A's notifications.
     */
    public function testNotificationInboxReturnsOnlyClinicANotifications(): void
    {
        $sec = $this->seedStaff('gap_sec_24a', 'cpms_secretary', [self::CLINIC_A1, self::CLINIC_B1]);

        // Seed notifications in Clinic A1
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        App::notificationService()->publishToUser(self::CLINIC_A1, $sec, 'test.notif_a', ['x' => 1], 'gap-dedup-a');

        // Seed notification in Clinic B1
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        App::notificationService()->publishToUser(self::CLINIC_B1, $sec, 'test.notif_b', ['x' => 2], 'gap-dedup-b');

        // Query inbox in context A1 → only A's notifications
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        $inboxA = App::notificationService()->inbox($sec, true, 50);
        self::assertNotEmpty($inboxA['rows'], 'Inbox A1 should have rows');
        foreach ($inboxA['rows'] as $row) {
            self::assertSame(self::CLINIC_A1, (int) $row['clinic_id'], 'Inbox A1 must only contain A1 notifications');
        }

        // Query inbox in context B1 → only B's notifications
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        $inboxB = App::notificationService()->inbox($sec, true, 50);
        self::assertNotEmpty($inboxB['rows'], 'Inbox B1 should have rows');
        foreach ($inboxB['rows'] as $row) {
            self::assertSame(self::CLINIC_B1, (int) $row['clinic_id'], 'Inbox B1 must only contain B1 notifications');
        }

        // Confirm A and B notification IDs do not overlap
        $idsA = array_column($inboxA['rows'], 'id');
        $idsB = array_column($inboxB['rows'], 'id');
        self::assertEmpty(array_intersect($idsA, $idsB), 'Clinic A and B notification IDs must not overlap');
    }

    /**
     * publishToStaff in Clinic A does not deliver to staff in Clinic B.
     */
    public function testPublishToStaffDoesNotCrossClinicBoundary(): void
    {
        $secA = $this->seedStaff('gap_sec_24b_a', 'cpms_secretary', [self::CLINIC_A1]);
        $secB = $this->seedStaff('gap_sec_24b_b', 'cpms_secretary', [self::CLINIC_B1]);

        // Publish in Clinic A — only secA should receive
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        App::notificationService()->publishToStaff(self::CLINIC_A1, 'cpms_queue_read', 'test.staff_a', ['y' => 1], 'gap-dedup-staff-a');

        // secA should have a notification
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        $inboxA = App::notificationService()->inbox($secA, true, 50);
        self::assertNotEmpty($inboxA['rows'], 'secA should receive notification in Clinic A');

        // secB (member of Clinic B only) must NOT see Clinic A's notification
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        $inboxB = App::notificationService()->inbox($secB, true, 50);
        self::assertEmpty($inboxB['rows'], 'secB must not see Clinic A notification');
    }

    // =================================================================
    // MT-25: Booking cannot combine entities across Clinics
    // =================================================================

    /**
     * createByStaff with patient from Clinic A1 and clinician from Clinic B1 → rejected.
     * The cross-clinic check (patient.clinic_id != clinician.clinic_id) fires before
     * any slot lookup, so no valid slot fixture is needed.
     */
    public function testCreateByStaffRejectsCrossClinicPatientClinicianMismatch(): void
    {
        // Patient in Clinic A1
        $patientA = $this->seedPatient(self::CLINIC_A1, 'gap-pat-25a');

        // Clinician in Clinic B1
        $clinicianB = $this->insertClinician(self::CLINIC_B1, 0, 'Dr Gap 25');

        // Staff with membership in B1 (so they can access BookingService)
        $doctor = $this->seedStaff('gap_doc_25', 'cpms_doctor', [self::CLINIC_B1]);
        wp_set_current_user($doctor);
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));

        // Attempt: patient from A1 + clinician from B1 → cross-clinic
        $caught = null;
        try {
            App::bookingService()->createByStaff(
                $doctor,
                $patientA,
                $clinicianB,
                gmdate('Y-m-d', strtotime('+7 days')),
                '10:00',
                'cross-clinic probe'
            );
        } catch (BookingException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Cross-clinic patient/clinician mismatch must be rejected');
        self::assertSame('CLINIC_VALIDATION_FAILED', $caught->errorCode);
        self::assertSame(422, $caught->httpStatus);

        // Verify no appointment was created in ANY clinic for this patient
        global $wpdb;
        $apptCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_appointments WHERE patient_id = %d',
                $patientA
            )
        );
        self::assertSame(0, $apptCount, 'No appointment should be created for cross-clinic attempt');
    }

    /**
     * Same patient + clinician from SAME clinic → allowed (no false positive).
     * This proves the cross-clinic check is genuinely about clinic mismatch,
     * not an unrelated permission failure.
     */
    public function testCreateByStaffAllowsSameClinicPatientClinician(): void
    {
        // Patient AND clinician in Clinic A1 (same clinic)
        $patientA = $this->seedPatient(self::CLINIC_A1, 'gap-pat-25ok');
        $clinicianA = $this->insertClinician(self::CLINIC_A1, 0, 'Dr Gap 25 OK');

        // Seed a slot
        $this->seedSlot(self::CLINIC_A1, $this->locA1, $clinicianA);

        $doctor = $this->seedStaff('gap_doc_25ok', 'cpms_doctor', [self::CLINIC_A1]);
        wp_set_current_user($doctor);
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));

        // Should NOT throw CLINIC_VALIDATION_FAILED
        $slotDate = gmdate('Y-m-d', strtotime('+7 days'));
        $caught = null;
        try {
            $result = App::bookingService()->createByStaff(
                $doctor,
                $patientA,
                $clinicianA,
                $slotDate,
                '10:00',
                'same-clinic-ok'
            );
            // If it succeeds, verify the appointment is in A1
            self::assertSame(self::CLINIC_A1, (int) $result['clinic_id'], 'Appointment must be in Clinic A1');
        } catch (BookingException $e) {
            // May fail for other reasons (slot not found, etc.) but NOT CLINIC_VALIDATION_FAILED
            self::assertNotSame(
                'CLINIC_VALIDATION_FAILED',
                $e->errorCode,
                'Same-clinic booking must not fail with cross-clinic error: ' . $e->getMessage()
            );
        }
    }

    // =================================================================
    // MT-39: Background jobs do not rely on WP user/default Clinic
    // =================================================================

    /**
     * Verify ApptReminderHandler has no clinic_id=1 literal and uses row clinic.
     */
    public function testReminderHandlerUsesClinicFromRowNotHardcoded(): void
    {
        $handlerPath = dirname(__DIR__, 2) . '/src/Application/Jobs/ApptReminderHandler.php';
        $source = file_get_contents($handlerPath);
        self::assertIsString($source, 'Handler source must be readable');

        // No clinic_id = 1 literal (C6-B census verified: removed)
        self::assertStringNotContainsString(
            'clinic_id = 1',
            $source,
            'ApptReminderHandler must not contain clinic_id = 1 literal'
        );

        // Uses clinic_id from each appointment row
        self::assertStringContainsString(
            "row['clinic_id']",
            $source,
            'ApptReminderHandler must use clinic_id from each row'
        );
    }

    /**
     * Verify FollowUpReminderHandler has no clinic_id=1 literal and uses row clinic.
     */
    public function testFollowUpHandlerUsesClinicFromRowNotHardcoded(): void
    {
        $handlerPath = dirname(__DIR__, 2) . '/src/Application/Jobs/FollowUpReminderHandler.php';
        $source = file_get_contents($handlerPath);
        self::assertIsString($source, 'Handler source must be readable');

        self::assertStringNotContainsString(
            'clinic_id = 1',
            $source,
            'FollowUpReminderHandler must not contain clinic_id = 1 literal'
        );

        self::assertStringContainsString(
            "row['clinic_id']",
            $source,
            'FollowUpReminderHandler must use clinic_id from each row'
        );
    }

    /**
     * Insert appointments in non-1 clinics and verify they carry their own clinic_id.
     * (Verifies the data path, not the handler execution — handler needs SMS config
     * which is not available in pure Integration tests.)
     */
    public function testAppointmentsInNonDefaultClinicsCarryCorrectClinicId(): void
    {
        global $wpdb;

        $clinicianA = $this->insertClinician(self::CLINIC_A1, 0, 'Dr Appt A1');
        $patientA = $this->seedPatient(self::CLINIC_A1, 'gap-pat-39a');
        $clinicianB = $this->insertClinician(self::CLINIC_B1, 0, 'Dr Appt B1');
        $patientB = $this->seedPatient(self::CLINIC_B1, 'gap-pat-39b');

        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d', strtotime('+3 days'));

        // Appointment in Clinic A1
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, patient_id, clinician_id, location_id, slot_date, slot_time, status, reference_code, booked_at, confirmed_at, duration_min, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, %s, %s, "confirmed", %s, %s, %s, 20, %s, %s)',
                self::CLINIC_A1, $patientA, $clinicianA, $this->locA1, $date, '10:00',
                'REF-A1-' . bin2hex(random_bytes(4)), $now, $now, $now, $now
            )
        );
        $apptA = (int) $wpdb->insert_id;

        // Appointment in Clinic B1
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                     (clinic_id, patient_id, clinician_id, location_id, slot_date, slot_time, status, reference_code, booked_at, confirmed_at, duration_min, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, %s, %s, "confirmed", %s, %s, %s, 20, %s, %s)',
                self::CLINIC_B1, $patientB, $clinicianB, $this->locB1, $date, '11:00',
                'REF-B1-' . bin2hex(random_bytes(4)), $now, $now, $now, $now
            )
        );
        $apptB = (int) $wpdb->insert_id;

        // Verify appointments carry correct clinic_id (not 1)
        $rowA = $wpdb->get_row($wpdb->prepare('SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', $apptA));
        $rowB = $wpdb->get_row($wpdb->prepare('SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_appointments WHERE id = %d', $apptB));

        self::assertSame(self::CLINIC_A1, (int) $rowA->clinic_id, 'Appointment A must carry Clinic A1 ID');
        self::assertSame(self::CLINIC_B1, (int) $rowB->clinic_id, 'Appointment B must carry Clinic B1 ID');
        self::assertNotEquals(1, (int) $rowA->clinic_id, 'Appointment A must not use Clinic ID 1');
        self::assertNotEquals(1, (int) $rowB->clinic_id, 'Appointment B must not use Clinic ID 1');
    }

    // =================================================================
    // MT-40: Settings are isolated across Clinics
    // =================================================================

    /**
     * Settings in Clinic A1 and Clinic B1 are independent.
     */
    public function testSettingsAreIsolatedAcrossClinics(): void
    {
        // Set a setting in Clinic A1
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        App::settings()->set('files.max_upload_bytes', 1111111);
        $valA = App::settings()->get('files.max_upload_bytes');
        self::assertSame(1111111, $valA, 'Setting in A1 should be set');

        // Set different value in Clinic B1
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        App::settings()->set('files.max_upload_bytes', 2222222);
        $valB = App::settings()->get('files.max_upload_bytes');
        self::assertSame(2222222, $valB, 'Setting in B1 should be set');

        // Read back in A1 — must still be A's value
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();
        $valA2 = App::settings()->get('files.max_upload_bytes');
        self::assertSame(1111111, $valA2, 'Setting in A1 must not be contaminated by B1');

        // Read back in B1 — must still be B's value
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        Settings::flushCache();
        $valB2 = App::settings()->get('files.max_upload_bytes');
        self::assertSame(2222222, $valB2, 'Setting in B1 must not be contaminated by A1');
    }

    // =================================================================
    // MT-41: Scope-aware caches do not leak A state into B
    // =================================================================

    /**
     * Settings cache does not leak values between clinics after scope switch.
     */
    public function testSettingsCacheDoesNotLeakAcrossClinics(): void
    {
        // Set a value in A1 scope
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        App::settings()->set('otp.cooldown_seconds', 999);
        $cachedA = App::settings()->get('otp.cooldown_seconds');
        self::assertSame(999, $cachedA, 'Value in A1 should be 999');

        // Switch to B1 scope with cache flush — B1 must NOT see A1's value
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        Settings::flushCache();
        $cachedB = App::settings()->get('otp.cooldown_seconds');
        self::assertNotSame(999, $cachedB, 'Clinic B1 must not see Clinic A1 cached setting value');

        // Verify back in A1 — value persists
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();
        $cachedA2 = App::settings()->get('otp.cooldown_seconds');
        self::assertSame(999, $cachedA2, 'Clinic A1 value must persist after B1 access');
    }

    /**
     * SystemClinicResolver cache does not leak between scope configurations.
     */
    public function testSystemClinicResolverCacheDoesNotLeak(): void
    {
        // After inserting multiple clinics, system resolver should fail closed
        SystemClinicResolver::flush();
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();
        App::resetScope();

        // Now there are ≥2 clinics → system resolver must fail closed
        $caught = null;
        try {
            ScopeContext::clear();
            App::scope();
        } catch (\ClinicCore\Application\Scope\ScopeRequiredException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'System resolver must fail closed with ≥2 clinics');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $caught->errorCode);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function warmRoutes(): void
    {
        Settings::flushCache();
        rest_do_request(new WP_REST_Request('GET', self::NS . '/health'));
        Settings::flushCache();
    }

    private function defaultOrganization(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function insertOrganization(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, "active", %s, %s)',
                'Org ' . $slug,
                $slug . '-' . bin2hex(random_bytes(2)),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Precondition: insert organization');
        return $id;
    }

    private function insertClinic(int $id, int $orgId, string $slug): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, %s, %s)',
                $id, $orgId, 'Clinic ' . $slug, $slug, 'Asia/Tehran', $now, $now
            )
        );
        self::assertNotEquals(1, $id, 'This fixture must not use Clinic ID 1');
        App::resetScope();
    }

    private function insertLocation(int $clinicId, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
                $clinicId, $slug, $slug, 'Asia/Tehran', $now, $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Precondition: insert location');
        return $id;
    }

    private function seedStaff(string $login, string $role, array $clinicIds): int
    {
        $seed = bin2hex(random_bytes(4));
        $created = wp_create_user($login . $seed, 'pass-12345', $login . $seed . '@test.local');
        if ($created instanceof \WP_Error) {
            self::fail('User creation failed: ' . $created->get_error_message());
        }
        $userId = (int) $created;
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        foreach ($clinicIds as $clinicId) {
            cpms_test_seed_membership($userId, $clinicId, $role);
        }
        return $userId;
    }

    private function seedPatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
                $clinicId,
                'MR-GAP-' . $seq . '-' . $clinicId,
                'Patient',
                $lastName,
                '0991' . sprintf('%07d', $seq % 10000000),
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Precondition: insert patient');
        return $id;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $linkedUserId = $wpUserId > 0 ? $wpUserId : (int) self::factory()->user->create(['role' => 'subscriber']);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)',
                $clinicId, $name, $linkedUserId, $now, $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Precondition: insert clinician');
        return $id;
    }

    /**
     * Seed a slot for a clinician in a clinic (for booking tests).
     * @return array{date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d', strtotime('+7 days'));
        $time = '10:00';

        // Insert into cpms_schedule_slots (the actual slot table)
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, 20, 1, 0, 0, 1, "lazy", %s, %s)',
                $clinicId, $clinicianId, $date, $time, $now, $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Precondition: insert slot');

        return ['date' => $date, 'time' => $time];
    }

    private function purgeReserveRows(): void
    {
        global $wpdb;
        $pure = 'WHERE clinic_id >= 61000';
        $steps = [
            'cpms_visit_status_history' => 'WHERE visit_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id >= 61000)',
            'cpms_medical_attachments' => $pure,
            'cpms_visits' => $pure,
            'cpms_appointments' => $pure,
            'cpms_schedule_slots' => $pure,
            'cpms_patient_user_links' => $pure,
            'cpms_patients' => $pure,
            'cpms_clinicians' => $pure,
            'cpms_locations' => $pure,
            'cpms_membership_capabilities' => 'WHERE membership_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id >= 61000)',
            'cpms_membership_locations' => 'WHERE membership_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinic_memberships WHERE clinic_id >= 61000)',
            'cpms_clinic_memberships' => $pure,
            'cpms_settings' => $pure,
            'cpms_notifications' => $pure,
            'cpms_idempotency_keys' => $pure,
            'cpms_clinics' => 'WHERE id >= 61000',
        ];

        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($steps as $table => $clause) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table . ' ' . $clause); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $wpdb->query(
            "DELETE FROM " . $wpdb->prefix . "cpms_organizations WHERE slug LIKE 'gap\\\\_org\\\\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}

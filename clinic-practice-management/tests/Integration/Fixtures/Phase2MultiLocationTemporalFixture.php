<?php

/**
 * Phase 2 — Multi-Location Temporal RED fixture (test-only).
 *
 * Minimal multi-location topology for temporal / timezone RED evidence slice.
 *
 * Topology:
 *  - one Organization (id 62200)
 *  - one Clinic (id 62201) timezone Asia/Tehran (clinic-level, but NOT authoritative for operational time)
 *  - three Locations same Clinic, distinct IANA:
 *      A: 62210 Asia/Tehran
 *      B: 62211 Europe/Berlin
 *      C: 62212 America/New_York (west, for no-show premature)
 *  - one Clinician (id 62220) clinic 62201
 *  - one Patient (auto) clinic 62201
 *
 * Guarantees:
 *  - no clinic_id=1 / location_id=1 / row-ordering dependency
 *  - real DB rows, deterministic cleanup in FK-safe order
 *  - distinct IANA zones, no hard-coded offset
 *  - no external network
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration\Fixtures;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;

trait Phase2MultiLocationTemporalFixture
{
    protected const FX_T_ORG_ID = 62200;
    protected const FX_T_CLINIC_ID = 62201;
    protected const FX_T_LOC_A_ID = 62210; // Asia/Tehran
    protected const FX_T_LOC_B_ID = 62211; // Europe/Berlin
    protected const FX_T_LOC_C_ID = 62212; // America/New_York
    protected const FX_T_CLINICIAN_ID = 62220;
    protected const FX_T_FLOOR = 62200;

    protected const FX_T_TZ_CLINIC = 'Asia/Tehran';
    protected const FX_T_TZ_A = 'Asia/Tehran';
    protected const FX_T_TZ_B = 'Europe/Berlin';
    protected const FX_T_TZ_C = 'America/New_York';

    protected int $fxTOrg = 0;
    protected int $fxTClinic = 0;
    protected int $fxTLocA = 0;
    protected int $fxTLocB = 0;
    protected int $fxTLocC = 0;
    protected int $fxTClinician = 0;
    protected int $fxTPatient = 0;

    protected function buildMultiLocationTemporalFixture(): void
    {
        $this->fxTAssertNoLeftover();

        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        // Org
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, "active", %s, %s)',
            self::FX_T_ORG_ID,
            'Temporal Org',
            'temporal-org-' . bin2hex(random_bytes(2)),
            $now,
            $now
        ));
        $this->fxTOrg = self::FX_T_ORG_ID;

        // Clinic
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_T_CLINIC_ID,
            self::FX_T_ORG_ID,
            'Temporal Clinic',
            'temporal-clinic',
            self::FX_T_TZ_CLINIC,
            $now,
            $now
        ));
        $this->fxTClinic = self::FX_T_CLINIC_ID;

        // Locations
        $locs = [
            [self::FX_T_LOC_A_ID, 'temporal-loc-a', self::FX_T_TZ_A, 1],
            [self::FX_T_LOC_B_ID, 'temporal-loc-b', self::FX_T_TZ_B, 0],
            [self::FX_T_LOC_C_ID, 'temporal-loc-c', self::FX_T_TZ_C, 0],
        ];
        foreach ($locs as [$id, $slug, $tz, $primary]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %d, 1, %s, %s)',
                $id,
                self::FX_T_CLINIC_ID,
                'Temporal ' . $slug,
                $slug . '-' . bin2hex(random_bytes(2)),
                $tz,
                $primary,
                $now,
                $now
            ));
        }
        $this->fxTLocA = self::FX_T_LOC_A_ID;
        $this->fxTLocB = self::FX_T_LOC_B_ID;
        $this->fxTLocC = self::FX_T_LOC_C_ID;

        // Clinician
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            self::FX_T_CLINICIAN_ID,
            self::FX_T_CLINIC_ID,
            'Dr Temporal',
            $now,
            $now
        ));
        $this->fxTClinician = self::FX_T_CLINICIAN_ID;

        // Patient
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            self::FX_T_CLINIC_ID,
            'MR-TEMP-' . bin2hex(random_bytes(3)),
            'Temporal',
            'Patient',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $this->fxTPatient = (int) $wpdb->insert_id;

        // Settings per clinic
        Settings::flushCache();
        $settings = new Settings($db, self::FX_T_CLINIC_ID, App::audit());
        $settings->set('booking.min_lead_hours', 2);
        $settings->set('booking.max_future_days', 60);
        $settings->set('booking.hold_ttl_sec', 600);
        $settings->set('booking.cancel_deadline_hours', 24);
        $settings->set('booking.reschedule_deadline_hours', 24);
        $settings->set('queue.no_show_grace_minutes', 30);
        $settings->set('sms.quiet_hours.enabled', false);
        Settings::flushCache();

        App::resetScope();
    }

    protected function fxTInsertSlot(int $locationId, string $date, string $time, int $capacity = 1, int $duration = 20, int $isOpen = 1): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, 0, 0, %d, %s, %s)',
            self::FX_T_CLINIC_ID,
            $locationId,
            self::FX_T_CLINICIAN_ID,
            $date,
            $time,
            $duration,
            $capacity,
            $isOpen,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    protected function fxTInsertPatient(?int $clinicId = null): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $clinicId = $clinicId ?? self::FX_T_CLINIC_ID;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            'MR-TEMP-' . bin2hex(random_bytes(3)),
            'Temporal',
            'Patient' . bin2hex(random_bytes(2)),
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    protected function fxTInsertAppointment(int $locationId, string $date, string $time, string $status = 'confirmed', ?int $slotId = null, ?int $patientId = null): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $slotId = $slotId ?? $this->fxTInsertSlot($locationId, $date, $time, 1, 20, 1);
        $ref = 'TMP-' . bin2hex(random_bytes(6));
        $patientId = $patientId ?? $this->fxTPatient;
        // Minimal appointment fields with unique reference_code
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, status, reference_code, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %d, %s, %s, %s, %s)',
            self::FX_T_CLINIC_ID,
            $locationId,
            self::FX_T_CLINICIAN_ID,
            $patientId,
            $slotId,
            $date,
            $time,
            20,
            $status,
            $ref,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        // booked_count
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_schedule_slots SET booked_count = booked_count + 1, updated_at = %s WHERE id = %d',
            $now,
            $slotId
        ));
        return $id;
    }

    protected function fxTAssertNoLeftover(): void
    {
        global $wpdb;
        $db = App::db();
        $org = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', self::FX_T_ORG_ID));
        self::assertEmpty($org, 'fixture precondition: no leftover org 62200');
        $clinic = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_T_CLINIC_ID));
        self::assertEmpty($clinic, 'fixture precondition: no leftover clinic 62201');
    }

    protected function purgeMultiLocationTemporalFixture(): void
    {
        global $wpdb;
        $db = App::db();

        // Comprehensive FK-safe purge — handle RESTRICT via explicit subqueries
        // 1) Leaf history
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d)', self::FX_T_CLINIC_ID));

        // 2) Visits and appointments (visits FK appointment, both FK patient)
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));

        // 3) Slot holds, slots, schedule
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_slot_holds') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));

        // 4) Notifications, SMS, logs, idempotency
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_idempotency_keys') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));

        // 5) Patient links — must go before patients AND clinics (FK RESTRICT both)
        // Delete by clinic_id and also by patient_id subquery for safety
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patient_user_links') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patient_user_links') . ' WHERE patient_id IN (SELECT id FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d)', self::FX_T_CLINIC_ID));

        // 6) Clinician locations and membership links
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinician_locations') . ' WHERE clinician_id = %d', self::FX_T_CLINICIAN_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinician_locations') . ' WHERE location_id IN (SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d)', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_membership_locations') . ' WHERE location_id IN (SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d)', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_membership_locations') . ' WHERE membership_id IN (SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d)', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_membership_capabilities') . ' WHERE membership_id IN (SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d)', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));

        // 7) Clinical notes, handwriting, etc. that reference visits/patients (defensive)
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinical_notes') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_handwriting_documents') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_prescriptions') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_medical_files') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));

        // 8) Patients and clinicians
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE id = %d', self::FX_T_CLINICIAN_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));

        // 9) Locations, settings, clinics, orgs
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', self::FX_T_CLINIC_ID));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', self::FX_T_ORG_ID));

        Settings::flushCache();
        App::resetScope();
    }
}

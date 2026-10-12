<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * RED contract — WP-global doctor role must never supply Clinic-local
 * doctor-sensitive search authority (globalSearch rx/note branches).
 *
 * Product contract: a WP user holding the global cpms_doctor role has no
 * clinical search results in a Clinic unless the durable Clinic membership
 * itself carries the scoped read authority (cpms_rx_read / cpms_medical_read),
 * and an explicit membership DENY on that authority is never bypassed.
 */
final class DoctorRoleClinicBoundaryRedTest extends WP_UnitTestCase
{
    private int $secretaryId;
    private int $rxDoctorId;
    private int $mixedId;
    private int $patientId;
    private int $visitId;
    private int $rxId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::settings()->set('queue.auto_enqueue', true);
        App::settings()->set('clinical.require_chief_complaint', true);

        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Staff: secretary (Clinic 1 membership) and a real doctor (Clinic 1 membership + clinician profile).
        $this->secretaryId = $this->makeUser('rb_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->secretaryId, 1, RolesAndCapabilities::ROLE_SECRETARY);

        $this->rxDoctorId = $this->makeUser('rb_doctor', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($this->rxDoctorId, 1, RolesAndCapabilities::ROLE_DOCTOR);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (1, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Dr Boundary One',
                $this->rxDoctorId,
                $now,
                $now
            )
        );
        $clinicianId = (int) $wpdb->insert_id;

        // Patient + consultation owned by the real doctor, with one note and one prescription.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-RB-0001',
                'Boundary',
                'Patient',
                '09125550011',
                'active',
                $now,
                $now
            )
        );
        $this->patientId = (int) $wpdb->insert_id;

        $visit = App::visitService()->walkIn($this->secretaryId, $this->patientId, $clinicianId);
        $this->visitId = (int) $visit['id'];
        App::visitService()->transition($this->rxDoctorId, $this->visitId, 'call');
        App::visitService()->transition($this->rxDoctorId, $this->visitId, 'start');

        App::clinicalService()->addNote($this->rxDoctorId, $this->visitId, [
            'category' => 'diagnosis',
            'visibility' => 'patient_visible',
            'content_text' => 'digoxin boundary diagnosis',
        ]);
        $rx = App::clinicalService()->createPrescription($this->rxDoctorId, $this->visitId, [
            'items' => [['generic_name' => 'digoxin', 'dose' => '0.25mg', 'frequency' => 'daily']],
        ]);
        $this->rxId = (int) $rx['id'];

        // Mixed-role account: WP-GLOBAL doctor role, but Clinic 1 membership is secretary
        // (no cpms_rx_read, no cpms_medical_read). No clinician profile.
        $this->mixedId = $this->makeUser('rb_mixed', RolesAndCapabilities::ROLE_DOCTOR);
        cpms_test_seed_membership($this->mixedId, 1, RolesAndCapabilities::ROLE_SECRETARY);
    }

    /** Positive control: a legitimate same-Clinic doctor keeps prescription search. */
    public function testLegitimateSameClinicDoctorKeepsPrescriptionSearch(): void
    {
        $result = App::clinicalService()->globalSearch($this->rxDoctorId, 'digoxin', 'rx');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $result['results']['prescriptions']);
        $this->assertSame([$this->rxId], $ids);
    }

    /** RED: WP-global doctor without Clinic-local rx authority must not receive prescriptions. */
    public function testWpDoctorWithoutClinicRxAuthorityGetsNoPrescriptions(): void
    {
        $result = App::clinicalService()->globalSearch($this->mixedId, 'digoxin', 'rx');
        $this->assertSame([], $result['results']['prescriptions'], 'WP-global doctor role leaked Clinic 1 prescriptions');
    }

    /** RED: WP-global doctor without Clinic-local medical read must not receive clinical notes. */
    public function testWpDoctorWithoutClinicMedicalReadGetsNoNotes(): void
    {
        $result = App::clinicalService()->globalSearch($this->mixedId, 'digoxin', 'note');
        $this->assertSame([], $result['results']['notes'], 'WP-global doctor role leaked Clinic 1 notes');
    }

    /** RED: an explicit Clinic membership DENY on cpms_rx_read must win over the doctor preset. */
    public function testExplicitMembershipRxDenyOverridesDoctorPreset(): void
    {
        $membership = App::membership_service()->membership_for(1, $this->rxDoctorId);
        $this->assertNotNull($membership);
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::RX_READ, 'deny');

        $result = App::clinicalService()->globalSearch($this->rxDoctorId, 'digoxin', 'rx');
        $this->assertSame([], $result['results']['prescriptions'], 'explicit membership DENY on cpms_rx_read was bypassed');
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . bin2hex(random_bytes(2)) . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }
}

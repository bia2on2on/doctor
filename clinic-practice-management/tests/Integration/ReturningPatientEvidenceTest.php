<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\ReturningPatientRepository;
use WP_UnitTestCase;

final class ReturningPatientEvidenceTest extends WP_UnitTestCase
{
    private int $patient;
    private int $previousPatient;
    private int $clinic;
    private int $org;
    private int $identity;
    private int $encounter;
    private int $t0;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $db = App::db();
        $now = $db->nowUtcSql();
        $this->t0 = strtotime('2026-01-31 12:34:56 UTC');
        $this->org = (int) $db->fetchValue('SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [1]);
        $db->insert('cpms_clinics', ['organization_id' => $this->org, 'name' => 'Other clinic', 'slug' => 'returning-evidence', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $this->clinic = $db->wpdb_last_insert_id();
        $this->identity = App::patient_identity_service()->create_identity($this->org);
        foreach ([1, $this->clinic] as $clinic) {
            $db->insert('cpms_patients', ['clinic_id' => $clinic, 'identity_id' => $this->identity, 'mrn' => 'EVIDENCE-' . $clinic, 'first_name' => 'Private', 'last_name' => 'Patient', 'mobile' => '09127770001', 'created_at' => $now, 'updated_at' => $now]);
            if ($clinic === 1) {
                $this->patient = $db->wpdb_last_insert_id();
            } else {
                $this->previousPatient = $db->wpdb_last_insert_id();
            }
        }
        $db->insert('cpms_clinicians', ['clinic_id' => $this->clinic, 'full_name' => 'Private clinician', 'created_at' => $now, 'updated_at' => $now]);
        $clinician = $db->wpdb_last_insert_id();
        $db->insert('cpms_visits', [
            'clinic_id' => $this->clinic, 'patient_id' => $this->previousPatient, 'clinician_id' => $clinician,
            'status' => 'consultation_completed', 'visit_date' => '2026-01-30',
            'check_in_at' => '2026-01-30 12:34:56', 'consultation_completed_at' => '2026-01-31 12:34:55.999',
            'active' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->encounter = $db->wpdb_last_insert_id();
        $this->assertGreaterThan(0, $this->encounter);
    }

    private function verifyIdentity(): void
    {
        $user = (int) wp_create_user('returning_verified', 'pass-12345', 'returning@test.local');
        App::patient_identity_service()->link_user($this->org, $this->identity, $user, '09127770001');
    }

    public function testIdentityAndStrictPreT0CompletionAreBothRequired(): void
    {
        $repo = new ReturningPatientRepository(App::db());
        $this->assertFalse($repo->qualifies($this->patient, 1, $this->t0), 'Generated identity is not verified');
        $this->verifyIdentity();
        foreach (['consultation_completed', 'awaiting_payment', 'paid', 'checked_out'] as $status) {
            App::db()->update('cpms_visits', ['status' => $status], ['id' => $this->encounter]);
            $this->assertTrue($repo->qualifies($this->patient, 1, $this->t0), $status);
        }
        foreach ([null, '2026-01-31 12:34:56.000', '2026-01-31 12:34:56.001'] as $completed) {
            App::db()->update('cpms_visits', ['consultation_completed_at' => $completed], ['id' => $this->encounter]);
            $this->assertFalse($repo->qualifies($this->patient, 1, $this->t0));
        }
        App::db()->update('cpms_visits', ['consultation_completed_at' => '2026-01-30 12:34:56'], ['id' => $this->encounter]);
        foreach (['checked_in', 'waiting', 'called', 'in_consultation', 'cancelled', 'skipped'] as $status) {
            App::db()->update('cpms_visits', ['status' => $status], ['id' => $this->encounter]);
            $this->assertFalse($repo->qualifies($this->patient, 1, $this->t0), $status);
        }
    }

    public function testCrossClinicEligibilityIsBooleanAndNeverCrossesOrganizationOrClinicalScope(): void
    {
        $this->verifyIdentity();
        $repo = new ReturningPatientRepository(App::db());
        $this->assertTrue($repo->qualifies($this->patient, 1, $this->t0));
        $this->assertFalse($repo->qualifies($this->patient, $this->clinic, $this->t0), 'Wrong Clinic cannot select a patient');
        $visible = (new \ClinicCore\Infrastructure\Repository\PatientIdentityRepository(App::db()))->clinical_records_for_clinic(1, $this->identity);
        $this->assertCount(1, $visible);
        $this->assertSame($this->patient, (int) $visible[0]['id']);
        $now = App::db()->nowUtcSql();
        App::db()->insert('cpms_organizations', ['name' => 'Foreign org', 'slug' => 'foreign-returning', 'created_at' => $now, 'updated_at' => $now]);
        $foreign = App::db()->wpdb_last_insert_id();
        App::db()->update('cpms_clinics', ['organization_id' => $foreign], ['id' => $this->clinic]);
        $this->assertFalse($repo->qualifies($this->patient, 1, $this->t0));
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }
}

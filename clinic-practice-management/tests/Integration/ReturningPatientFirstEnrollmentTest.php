<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Patients\PatientService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Licensing\PostExpirationPolicy;
use ClinicCore\Domain\Licensing\SignedLicenseGate;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\ReturningPatientRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Tests\Support\SignedExpirationFixture;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/** First destination-Clinic record, real signed license, explicit session subject. */
final class ReturningPatientFirstEnrollmentTest extends WP_UnitTestCase
{
    use SignedExpirationFixture;

    private int $org;
    private int $clinic;
    private int $location;
    private int $identity;
    private int $user;
    private int $source;
    private int $encounter;
    private int $t0;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::replaceExplicitScope(ClinicScope::forClinic(1));
        $db = App::db();
        $stamp = $db->nowUtcSql();
        $this->t0 = strtotime('2026-01-31 12:34:56 UTC');
        $this->now = strtotime('2026-05-15 12:34:56 UTC');
        $this->org = (int) $db->fetchValue('SELECT organization_id FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [1]);
        $db->insert('cpms_clinics', ['organization_id' => $this->org, 'name' => 'First enrollment destination', 'slug' => 'first-enrollment', 'timezone' => 'UTC', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $this->clinic = $db->wpdb_last_insert_id();
        $this->assertGreaterThan(0, $this->clinic);
        $db->insert('cpms_locations', ['clinic_id' => $this->clinic, 'name' => 'Destination location', 'slug' => 'enrollment-location', 'timezone' => 'UTC', 'is_primary' => 1, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $this->location = $db->wpdb_last_insert_id();
        $this->identity = App::patient_identity_service()->create_identity($this->org, '09127770001');
        $this->user = $this->patientUser('verified');
        App::patient_identity_service()->link_user($this->org, $this->identity, $this->user, '09127770001');
        $db->insert('cpms_patients', ['clinic_id' => 1, 'identity_id' => $this->identity, 'mrn' => 'FIRST-ENROLL-SOURCE', 'first_name' => 'ForeignSecret', 'last_name' => 'ClinicalRecord', 'mobile' => '09127770001', 'medical_history' => 'SOURCE-ONLY-PRIVATE-HISTORY', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $this->source = $db->wpdb_last_insert_id();
        $db->insert('cpms_patient_user_links', ['clinic_id' => 1, 'patient_id' => $this->source, 'wp_user_id' => $this->user, 'mobile_at_link' => '09127770001', 'linked_at' => $stamp]);
        $db->insert('cpms_clinicians', ['clinic_id' => 1, 'full_name' => 'Source clinician', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $clinician = $db->wpdb_last_insert_id();
        $location = (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1', [1]);
        $db->insert('cpms_visits', ['clinic_id' => 1, 'location_id' => $location, 'patient_id' => $this->source, 'clinician_id' => $clinician, 'status' => 'consultation_completed', 'visit_date' => '2026-01-30', 'check_in_at' => '2026-01-30 12:34:56', 'consultation_completed_at' => '2026-01-31 12:34:55.999', 'active' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
        $this->encounter = $db->wpdb_last_insert_id();
        $this->assertGreaterThan(0, $this->encounter);
        remove_all_filters('cpms_license_dev_mode');
        $this->installSignedExpiration($this->t0);
        wp_set_current_user($this->user);
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        wp_set_current_user(0);
        App::resetScope();
        parent::tearDown();
    }

    private function patientUser(string $tag): int
    {
        $user = self::factory()->user->create(['role' => RolesAndCapabilities::ROLE_PATIENT, 'user_login' => 'enroll_' . $tag]);
        return (int) $user;
    }

    private function gate(): SignedLicenseGate
    {
        return new SignedLicenseGate(App::licenseService(), new ReturningPatientRepository(App::db()), fn (): int => $this->now);
    }

    private function service(): PatientService
    {
        return new PatientService(App::db(), new PatientRepository(App::db()), $this->gate(), App::audit(), App::op());
    }

    /** @return array<string, mixed> */
    private function fields(): array
    {
        // Deliberately NOT the source mobile: only the explicit user identity
        // link, not matching phone/name/national ID, can establish eligibility.
        return ['first_name' => 'Local', 'last_name' => 'Enrollment', 'mobile' => '09128880002'];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $result = [];
        foreach (['cpms_patients', 'cpms_patient_identities', 'cpms_patient_identity_links', 'cpms_patient_user_links', 'cpms_audit_logs', 'cpms_appointments', 'cpms_visits'] as $table) {
            $result[$table] = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table));
        }
        return $result;
    }

    private function denied(string $code = 'CLINIC_NOT_FOUND', ?int $clinic = null, ?array $fields = null): void
    {
        $before = $this->counts();
        $scope = ScopeContext::tryGet();
        try {
            $this->service()->enroll_current_patient($clinic ?? $this->clinic, $fields ?? $this->fields());
            $this->fail('Expected enrollment denial');
        } catch (BookingException $e) {
            $this->assertSame($code, $e->errorCode);
        }
        $this->assertSame($before, $this->counts(), 'Denial must not leave a row, link, identity, appointment, encounter or success audit');
        $this->assertSame($scope, ScopeContext::tryGet(), 'Success/failure may not leak destination scope');
    }

    public function testSameOrganizationFirstVisitEnrollsWithoutCopyingForeignPhiAndEnablesAuthorizedNewService(): void
    {
        $db = App::db();
        $source = $db->fetchRow('SELECT * FROM ' . $db->table('cpms_patients') . ' WHERE id = %d', [$this->source]);
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', [$this->clinic]));
        $scope = ScopeContext::tryGet();
        $created = $this->service()->enroll_current_patient($this->clinic, $this->fields() + ['medical_history' => 'forged import', 'identity_id' => 999999, 'organization_id' => 999999, 'wp_user_id' => 999999]);
        $this->assertSame($scope, ScopeContext::tryGet());
        $this->assertSame(['patient_id', 'clinic_id', 'mrn'], array_keys($created));
        $this->assertSame($this->clinic, $created['clinic_id']);
        $row = $db->fetchRow('SELECT * FROM ' . $db->table('cpms_patients') . ' WHERE id = %d', [$created['patient_id']]);
        $this->assertSame($this->identity, (int) $row['identity_id']);
        $this->assertSame('Local', $row['first_name']);
        $this->assertSame('09128880002', $row['mobile']);
        $this->assertNull($row['medical_history']);
        $this->assertSame($source, $db->fetchRow('SELECT * FROM ' . $db->table('cpms_patients') . ' WHERE id = %d', [$this->source]));
        $this->assertSame($this->user, (int) $db->fetchValue('SELECT wp_user_id FROM ' . $db->table('cpms_patient_user_links') . ' WHERE patient_id = %d AND clinic_id = %d', [$created['patient_id'], $this->clinic]));
        $visible = (new PatientIdentityRepository($db))->clinical_records_for_clinic($this->clinic, $this->identity);
        $this->assertCount(1, $visible);
        $this->assertSame($created['patient_id'], (int) $visible[0]['id']);
        $this->assertTrue($this->gate()->assert(LicenseGate::OP_VISIT_CHECKIN, ['clinic_id' => $this->clinic, 'patient_id' => $created['patient_id']])->allowed);
        $this->assertFalse($this->gate()->assert(LicenseGate::OP_APPOINTMENT_BOOK, ['clinic_id' => $this->clinic, 'patient_id' => $created['patient_id']])->allowed);
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments')));
        $this->assertStringNotContainsString('SOURCE-ONLY', wp_json_encode($created));

        $secretary = (int) self::factory()->user->create(['role' => 'cpms_secretary']);
        cpms_test_seed_membership($secretary, $this->clinic, 'cpms_secretary');
        $stamp = $db->nowUtcSql();
        $db->insert('cpms_clinicians', ['clinic_id' => $this->clinic, 'full_name' => 'Destination clinician', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $clinician = $db->wpdb_last_insert_id();
        // A persisted active clinician in its home Clinic participates through
        // MembershipRepository's established home-Clinic rule.
        wp_set_current_user($secretary);
        App::replaceExplicitScope(ClinicScope::forClinic($this->clinic, $this->location));
        $visits = new VisitService($db, new VisitRepository($db), new AppointmentRepository($db), App::settingsFactory(), App::audit(), $this->gate(), null, null);
        $visit = $visits->walkIn($secretary, $created['patient_id'], $clinician);
        $this->assertGreaterThan(0, (int) $visit['id']);
        $persisted = $db->fetchRow('SELECT clinic_id, location_id, patient_id, appointment_id FROM ' . $db->table('cpms_visits') . ' WHERE id = %d', [$visit['id']]);
        $this->assertSame($this->clinic, (int) $persisted['clinic_id']);
        $this->assertSame($this->location, (int) $persisted['location_id']);
        $this->assertSame($created['patient_id'], (int) $persisted['patient_id']);
        $this->assertNull($persisted['appointment_id'], 'Clinical service initiation is not appointment creation');
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
        $this->assertSame($created['patient_id'], (int) $visit['patient_id']);
    }

    public function testDifferentOrganizationCannotUseSourceIdentity(): void
    {
        $db = App::db();
        $stamp = $db->nowUtcSql();
        $db->insert('cpms_organizations', ['name' => 'Unrelated', 'slug' => 'unrelated-enrollment', 'created_at' => $stamp, 'updated_at' => $stamp]);
        $otherOrg = $db->wpdb_last_insert_id();
        $this->assertGreaterThan(0, $otherOrg);
        $db->update('cpms_clinics', ['organization_id' => $otherOrg], ['id' => $this->clinic]);
        $this->denied('CLINIC_NOT_FOUND', null, $this->fields() + ['identity_id' => $this->identity, 'organization_id' => $this->org]);
        $otherIdentity = App::patient_identity_service()->create_identity($otherOrg);
        App::patient_identity_service()->link_user($otherOrg, $otherIdentity, $this->user, '09127770001');
        $this->denied('CLINIC_LICENSE_BLOCKED', null, $this->fields() + ['identity_id' => $this->identity]);
    }

    public function testForgedSubjectAndReturningFlagsCannotUseAnotherPatientsEncounter(): void
    {
        $attacker = $this->patientUser('attacker');
        $own = App::patient_identity_service()->create_identity($this->org);
        App::patient_identity_service()->link_user($this->org, $own, $attacker, '09128880002');
        wp_set_current_user($attacker);
        $this->denied('CLINIC_LICENSE_BLOCKED', null, $this->fields() + ['identity_id' => $this->identity, 'patient_id' => $this->source, 'wp_user_id' => $this->user, 'verified_wp_user_id' => $this->user, 'returning' => true, 'reception' => true, 'expires_at' => $this->now + 86400]);
    }

    public function testAmbiguousOrganizationIdentityFailsClosedWithoutCreatingFreshIdentity(): void
    {
        $other = App::patient_identity_service()->create_identity($this->org);
        App::patient_identity_service()->link_user($this->org, $other, $this->user, '09127770001');
        $this->denied();
    }

    public function testLegacyClinicalLinkAndMatchingMobileAreNotVerifiedOrganizationIdentity(): void
    {
        $db = App::db();
        $db->delete('cpms_patient_identity_links', ['organization_id' => $this->org, 'wp_user_id' => $this->user]);
        $db->update('cpms_patients', ['identity_id' => null], ['id' => $this->source]);
        $this->denied('CLINIC_NOT_FOUND', null, array_replace($this->fields(), ['mobile' => '09127770001', 'first_name' => 'ForeignSecret']));
    }

    /** @dataProvider sixMonthBoundary */
    public function testExactSixMonthBoundaryIsHalfOpen(int $offset, bool $allowed): void
    {
        $this->now = PostExpirationPolicy::deadline($this->t0, 6) + $offset;
        if (!$allowed) {
            $this->denied('CLINIC_LICENSE_BLOCKED');
            return;
        }
        $created = $this->service()->enroll_current_patient($this->clinic, $this->fields());
        $this->assertGreaterThan(0, $created['patient_id']);
    }

    public static function sixMonthBoundary(): array
    {
        return ['one second before' => [-1, true], 'exact deadline' => [0, false], 'one second after' => [1, false]];
    }

    /** @dataProvider insufficientEncounter */
    public function testFirstEnrollmentRequiresGenuinelyQualifyingStrictPreT0Encounter(string $status, ?string $completed): void
    {
        App::db()->update('cpms_visits', ['status' => $status, 'consultation_completed_at' => $completed], ['id' => $this->encounter]);
        $this->denied('CLINIC_LICENSE_BLOCKED');
    }

    public static function insufficientEncounter(): array
    {
        return [
            'exact T0' => ['consultation_completed', '2026-01-31 12:34:56.000'],
            'after T0' => ['paid', '2026-01-31 12:34:56.001'],
            'missing durable completion' => ['paid', null],
            'pending only' => ['waiting', '2026-01-30 12:34:56'],
        ];
    }

    public function testRepeatedEnrollmentCannotCreateAnotherRecordOrOverwriteExistingLink(): void
    {
        $this->service()->enroll_current_patient($this->clinic, $this->fields());
        $this->denied('CLINIC_VALIDATION_FAILED', null, array_replace($this->fields(), ['mobile' => '09128880003']));
    }

    public function testFinalAuditFailureRollsBackEnrollmentAndIdentityEdge(): void
    {
        global $wpdb;
        $before = $this->counts();
        $scope = ScopeContext::tryGet();
        $fault = static function (string $sql): string {
            if (str_contains($sql, 'INSERT INTO') && str_contains($sql, 'cpms_audit_logs') && str_contains($sql, 'PATIENT_CREATED')) {
                return 'INSERT INTO ' . App::db()->table('cpms_audit_logs') . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }
            return $sql;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        try {
            $this->service()->enroll_current_patient($this->clinic, $this->fields());
            $this->fail('Final success audit must persist or the entire enrollment must roll back');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('audit', strtolower($e->getMessage()));
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($suppressed);
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame($scope, ScopeContext::tryGet());
    }

    public function testAnonymousAndNonPatientSessionsCannotEnroll(): void
    {
        wp_set_current_user(0);
        $this->denied('CLINIC_PERMISSION_DENIED');
        wp_set_current_user((int) self::factory()->user->create(['role' => 'cpms_secretary']));
        $this->denied('CLINIC_PERMISSION_DENIED');
    }

    public function testSuspendedOrganizationAndNonexistentClinicFailClosed(): void
    {
        $this->denied('CLINIC_NOT_FOUND', 999999);
        App::db()->update('cpms_organizations', ['status' => 'suspended'], ['id' => $this->org]);
        $this->denied();
    }

    public function testNewUnverifiedStaffPatientRetainsOneMonthReceptionOnlyWindow(): void
    {
        $secretary = (int) self::factory()->user->create(['role' => 'cpms_secretary']);
        App::replaceExplicitScope(ClinicScope::forClinic($this->clinic));
        $this->now = PostExpirationPolicy::deadline($this->t0, 1) - 1;
        $created = $this->service()->create($this->fields() + ['identity_id' => $this->identity, 'wp_user_id' => $this->user, 'returning' => true], $secretary);
        $identity = (int) App::db()->fetchValue('SELECT identity_id FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d', [$created['id']]);
        $this->assertNotSame($this->identity, $identity);
        $this->now++;
        $before = $this->counts();
        try {
            $this->service()->create(array_replace($this->fields(), ['mobile' => '09127770001']), $secretary);
            $this->fail('A matching source mobile must not grant the returning window');
        } catch (BookingException $e) {
            $this->assertSame('CLINIC_LICENSE_BLOCKED', $e->errorCode);
        }
        $this->assertSame($before, $this->counts());
    }

    public function testRestEnrollmentRequiresNonceAndPatientRoleThenReturnsOnlyLocalSelector(): void
    {
        // Exercise the actual production App wiring with wall-clock signed T0.
        $this->installSignedExpiration(strtotime('-3 months'));
        $before = $this->counts();
        $withoutNonce = $this->dispatch($this->user, false);
        $this->assertSame(403, $withoutNonce->get_status());
        $this->assertSame('CLINIC_INVALID_NONCE', $withoutNonce->get_data()['code']);
        $staff = (int) self::factory()->user->create(['role' => 'cpms_secretary']);
        $wrongRole = $this->dispatch($staff, true);
        $this->assertSame(403, $wrongRole->get_status());
        $this->assertSame('CLINIC_PERMISSION_DENIED', $wrongRole->get_data()['code']);
        $this->assertSame($before['cpms_patients'], $this->counts()['cpms_patients']);
        $response = $this->dispatch($this->user, true);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data()['data'];
        $this->assertSame(['patient_id', 'clinic_id', 'mrn'], array_keys($data));
        $this->assertSame($this->clinic, $data['clinic_id']);
        $this->assertGreaterThan(0, $data['patient_id']);
        $this->assertSame($before['cpms_appointments'], $this->counts()['cpms_appointments']);
        $this->assertSame($before['cpms_visits'], $this->counts()['cpms_visits']);
        $this->assertSame(1, ScopeContext::tryGet()->clinicId, 'Patient enrollment restores ambient scope');
    }

    private function dispatch(int $user, bool $nonce): WP_REST_Response
    {
        wp_set_current_user($user);
        $request = new WP_REST_Request('POST', '/clinic/v1/patient/enroll');
        foreach ($this->fields() + ['clinic_id' => $this->clinic, 'identity_id' => 999999, 'wp_user_id' => 999999] as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        return rest_do_request($request);
    }
}

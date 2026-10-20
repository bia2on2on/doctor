<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * FIX 2 — Subscriber Doctor private file upload.
 *
 * Premature global ROLE_DOCTOR check in MedicalFileService::store() is removed.
 * Authority is now: explicit trusted Clinic scope + active clinician identity in
 * correct Organization/Clinic + effective scoped FILE_UPLOAD and PRIVATE_NOTE_CREATE.
 *
 * Positive: Subscriber (WP `subscriber` role) with ACTIVE clinic membership role `doctor`
 * and an active clinician row in the correct Clinic/Organization can upload `doctor_private`.
 *
 * Negative: Non-clinician (subscriber with non-doctor membership and no clinician row)
 * must fail even if FILE_UPLOAD is granted.
 */
final class SubscriberDoctorPrivateFileUploadTest extends WP_UnitTestCase
{
    private string $storagePath = '';
    private int $orgId = 0;
    private int $clinicA = 0;
    /** @var array<int,int> */
    private array $locations = [];
    /** @var list<string> */
    private array $storedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();

        $this->storagePath = sys_get_temp_dir() . '/cpms-subdoc-' . bin2hex(random_bytes(4));
        $this->orgId = $this->createOrganization();
        $this->clinicA = $this->createClinic($this->orgId, 'SD-A');
        self::assertGreaterThan(0, $this->clinicA);

        foreach ([ $this->clinicA ] as $clinicId) {
            App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
            App::settings()->set('files.storage_path', $this->storagePath);
            App::settings()->set('files.max_upload_bytes', 10485760);
            $this->locations[$clinicId] = $this->createLocation($clinicId);
        }
        App::replaceExplicitScope(ClinicScope::forClinic($this->clinicA));
    }

    protected function tearDown(): void
    {
        App::replaceExplicitScope(null);
        Settings::flushCache();
        App::resetScope();
        foreach ($this->storedFiles as $relative) {
            foreach ([ $this->storagePath ] as $root) {
                $p = rtrim($root, '/') . '/' . ltrim($relative, '/');
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
        if (is_dir($this->storagePath)) {
            @rmdir($this->storagePath);
        }
        parent::tearDown();
    }

    public function testSubscriberDoctorCanUploadDoctorPrivate(): void
    {
        $doctor = $this->makeUser('sd_doctor', 'subscriber');
        $this->createMembership($doctor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianId = $this->createClinician($this->clinicA, $doctor);
        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $clinicianId);

        // Verify subscriber has no global ROLE_DOCTOR
        $user = get_userdata($doctor);
        self::assertNotFalse($user);
        self::assertNotContains(RolesAndCapabilities::ROLE_DOCTOR, (array) $user->roles, 'precondition: WP role is subscriber, not doctor');
        self::assertTrue(
            App::authorization_service()->can($doctor, $this->clinicA, RolesAndCapabilities::FILE_UPLOAD),
            'precondition: scoped FILE_UPLOAD via membership preset'
        );
        self::assertTrue(
            App::authorization_service()->can($doctor, $this->clinicA, RolesAndCapabilities::PRIVATE_NOTE_CREATE),
            'precondition: scoped PRIVATE_NOTE_CREATE'
        );

        $this->bindClinic($this->clinicA);
        $row = App::medicalFileService()->upload(
            $doctor,
            $this->uploadedFileArgs('sd-private.pdf', $this->pdfContent()),
            $patient,
            $visitId,
            'document',
            'doctor_private'
        );
        $fileId = (int) ($row['id'] ?? 0);
        self::assertGreaterThan(0, $fileId, 'Subscriber Doctor must succeed for doctor_private upload');
        $this->storedFiles[] = (string) ($row['storage_path'] ?? '');
    }

    public function testNonClinicianCannotUploadDoctorPrivateEvenWithFileUploadGrant(): void
    {
        $secretary = $this->makeUser('sd_secretary', 'subscriber');
        $membershipId = $this->createMembership($secretary, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        // grant FILE_UPLOAD explicitly to ensure failure is not due to missing cap but due to clinician identity
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::FILE_UPLOAD, 'grant');
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::PRIVATE_NOTE_CREATE, 'grant');

        // Create a visit owned by a real doctor (different user) in same clinic
        $realDoctor = $this->makeUser('sd_real_doctor', 'subscriber');
        $this->createMembership($realDoctor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $realClinician = $this->createClinician($this->clinicA, $realDoctor);
        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $realClinician);

        $this->bindClinic($this->clinicA);

        $threw = null;
        try {
            App::medicalFileService()->upload(
                $secretary,
                $this->uploadedFileArgs('sd-should-fail.pdf', $this->pdfContent()),
                $patient,
                $visitId,
                'document',
                'doctor_private'
            );
        } catch (ClinicalException $e) {
            $threw = $e;
        }
        self::assertNotNull($threw, 'non-clinician must be denied for doctor_private upload');
        self::assertContains($threw->errorCode, ['CLINIC_PERMISSION_DENIED', 'CLINIC_NOT_FOUND'], 'denied with permission/not-found shape');
    }

    // ===== helpers (copied from ClinicalFilesScopedAuthorizationTest) =====

    private function bindClinic(int $clinicId): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
        // Also set the REST trusted scope via Membership? For service-level upload, explicit App::scope is sufficient via TrustedClinicId fallback.
        // We bind via App::scope directly: MedicalFileService::store uses patientClinicId -> assertPrivateFileUploadAuthority which checks trustedClinicId via ScopeContext/App::scope or membership.
        // So we ensure App::scope is set.
        try {
            App::scope();
        } catch (\Throwable $e) {
            App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
        }
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(24), $unique . '@sd.test');
        self::assertGreaterThan(0, $userId);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);
        return $userId;
    }

    private function createMembership(int $userId, int $clinicId, string $roleKey): int
    {
        $id = App::membership_service()->create_membership($clinicId, $userId, $roleKey);
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $slug = 'sd-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
            'SD Org ' . $slug,
            $slug,
            'active',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function createClinic(int $orgId, string $suffix): int
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $slug = 'sd-clinic-' . $suffix . '-' . bin2hex(random_bytes(3));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s)',
            $orgId,
            'SD Clinic ' . $suffix,
            $slug,
            'Asia/Tehran',
            'active',
            $now,
            $now
        ));
        $clinicId = (int) $wpdb->insert_id;
        // primary location required for visits
        return $clinicId;
    }

    private function createLocation(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $clinicId,
            'SD Loc ' . $clinicId,
            'sd-loc-' . $clinicId . '-' . bin2hex(random_bytes(2)),
            'Asia/Tehran',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function createPatient(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $clinicId,
            'MR-SD-' . $seq,
            'SD',
            'Patient' . $seq,
            '0912' . sprintf('%07d', $seq % 10000000),
            'active',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function createClinician(int $clinicId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, 1, %s, %s)',
            $clinicId,
            'Dr SD ' . $wpUserId,
            $wpUserId,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function createVisit(int $clinicId, int $patientId, int $clinicianId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d');
        $locationId = (int) ($this->locations[$clinicId] ?? 0);
        self::assertGreaterThan(0, $locationId);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, consultation_started_at, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, 1, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            'walk_in',
            'in_consultation',
            $date,
            $date . ' 10:00:00.000',
            $date . ' 10:00:00.000',
            $date . ' 10:05:00.000',
            $date . ' 10:10:00.000',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function uploadedFileArgs(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sdup_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);
        // Keep path for cleanup via service's storage? We track storage_path from result instead.
        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'error' => 0,
        ];
    }

    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\nSD-MARKER-" . bin2hex(random_bytes(4));
    }
}

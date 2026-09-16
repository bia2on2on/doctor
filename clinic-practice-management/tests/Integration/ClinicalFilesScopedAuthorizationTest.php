<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 5 — Clinic-scoped authorization for ClinicalService + MedicalFileService.
 *
 * Contract under test (staff paths; patient-self access is a separate policy):
 *  1. staff clinical/file authority = authenticated actor + durable ACTIVE Clinic
 *     membership + the exact scoped permission;
 *  2. membership alone is not permission, and a global WordPress capability/role is
 *     never Clinic clinical authority;
 *  3. an explicit membership deny overrides grant/preset;
 *  4. every operation keeps its own semantic permission (no broad mega-cap);
 *  5. the durable owner Clinic of an object comes from persistence (visit/file/patient
 *     rows), never from the request payload;
 *  6. denial happens before any storage read/write or clinical DB mutation;
 *  7. patient-self MedicalFile access stays independent of staff membership.
 *
 * Fixture design:
 *  - one explicit Organization row (status=active) with a database-generated id;
 *  - two dynamic Clinics under it (database-generated ids);
 *  - users, memberships, patients, clinicians, visits and attachments are created
 *    dynamically: no fixed tenant id, no `LIMIT 1` tenant discovery, no Clinic 1.
 *
 * RED evidence is split on purpose: each pair holds one pure *observation* test (what
 * the current product actually did, reported in the failure message) and one
 * *contract* test, so no defect can be hidden behind an earlier assertion.
 */
final class ClinicalFilesScopedAuthorizationTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    /** Marker inside the fixture PDF — the "secret" that must not cross a boundary. */
    private const SECRET_MARKER = 'SLICE5-CONFIDENTIAL-PDF-MARKER';

    private string $storagePath = '';

    private int $orgId = 0;

    private int $clinicA = 0;

    private int $clinicB = 0;

    /** @var list<string> relative storage paths created by this test (cleanup only) */
    private array $storedFiles = [];

    /** @var array<int,int> clinic_id → primary location_id (visits require one) */
    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();

        $this->storagePath = sys_get_temp_dir() . '/cpms-slice5-files-' . bin2hex(random_bytes(4));

        $this->orgId = $this->createOrganization();
        $this->clinicA = $this->createClinic($this->orgId, 'A');
        $this->clinicB = $this->createClinic($this->orgId, 'B');
        self::assertNotSame($this->clinicA, $this->clinicB, 'precondition: two distinct dynamic Clinics');
        self::assertGreaterThan(1, min($this->clinicA, $this->clinicB), 'fixtures must not depend on Clinic 1');

        // Settings are per-Clinic and `App::settings()` derives from the active Scope —
        // with several Clinics the system resolution is intentionally fail-closed, so
        // every Setting write happens under an explicit fixture Scope.
        foreach ([ $this->clinicA, $this->clinicB ] as $clinicId) {
            App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
            App::settings()->set('files.storage_path', $this->storagePath);
            App::settings()->set('files.max_upload_bytes', 10485760);
            $this->locations[$clinicId] = $this->createLocation($clinicId);
        }
        $this->bindHarnessScope($this->clinicA);
        $this->warmRoutes();
    }

    protected function tearDown(): void
    {
        App::replaceExplicitScope(null);
        Settings::flushCache();
        App::resetScope();

        foreach ($this->storedFiles as $relative) {
            foreach ([ $this->storagePath, LocalFileStorage::defaultBasePath() ] as $root) {
                $absolute = rtrim($root, '/') . '/' . ltrim($relative, '/');
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }
        }
        if ($this->storagePath !== '' && is_dir($this->storagePath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->storagePath);
        }

        parent::tearDown();
    }

    // =====================================================================
    // RED A — file disclosure despite a scoped deny (real stream path)
    // =====================================================================

    /**
     * RED A (observation): what the product actually returns for a doctor whose
     * durable membership explicitly denies the scoped `cpms_file_read` permission.
     *
     * On valid main (defect) the forbidden content IS returned ⇒ this test fails and
     * its message is the executable evidence. After GREEN: zero bytes read.
     */
    public function testRedAStreamDisclosesForbiddenFileBytesUnderScopedFileReadDeny(): void
    {
        $actor = $this->doctorWithScopedDeny($this->clinicA, RolesAndCapabilities::FILE_READ, 's5_reda');
        $patient = $this->createPatient($this->clinicA);
        $fileId = $this->uploadViaService($actor, $patient, 'doctor_private', 'red-a-secret.pdf');

        $this->assertScopedDenyPreconditions($actor, $this->clinicA, RolesAndCapabilities::FILE_READ);
        self::assertSame($this->clinicA, $this->attachmentClinicId($fileId), 'precondition: durable file clinic = A');
        self::assertTrue(
            $this->storedFileExists($fileId),
            'precondition: the attachment really exists in protected storage (a denial must not be explained away)'
        );

        $observed = null;
        try {
            $streamed = App::medicalFileService()->stream($actor, $fileId);
            $observed = (string) ($streamed['content'] ?? '');
        } catch (ClinicalException $e) {
            $observed = '';
        }

        self::assertSame(
            0,
            strlen($observed),
            'RED A evidence: MedicalFileService::stream() returned ' . strlen($observed) . ' byte(s) of '
                . 'attachment ' . $fileId . ' (durable clinic ' . $this->clinicA . ', visibility doctor_private) '
                . 'although AuthorizationService denies scoped cpms_file_read for this actor'
                . ' [sha256=' . hash('sha256', $observed) . ']'
        );
    }

    /**
     * RED A (contract): the denial must be enforced and must happen before
     * `storage->read()` — witnessed by the missing FILE_READ audit that every
     * successful sensitive read writes.
     */
    public function testRedAStreamMustFailClosedBeforeStorageReadUnderScopedFileReadDeny(): void
    {
        $actor = $this->doctorWithScopedDeny($this->clinicA, RolesAndCapabilities::FILE_READ, 's5_reda2');
        $patient = $this->createPatient($this->clinicA);
        $fileId = $this->uploadViaService($actor, $patient, 'doctor_private', 'red-a-secret2.pdf');

        $this->assertScopedDenyPreconditions($actor, $this->clinicA, RolesAndCapabilities::FILE_READ);
        self::assertTrue($this->storedFileExists($fileId), 'precondition: the bytes are really on disk');

        $denied = null;
        $returned = '';
        try {
            $streamed = App::medicalFileService()->stream($actor, $fileId);
            $returned = (string) ($streamed['content'] ?? '');
        } catch (ClinicalException $e) {
            $denied = $e;
        }

        self::assertNotNull(
            $denied,
            'a scoped cpms_file_read deny must stop MedicalFileService::stream(); it returned '
                . strlen($returned) . ' byte(s) of clinical content instead'
        );
        // Existing safe contract for an object the actor may not read: the same
        // non-disclosing not-found shape, so file existence is never revealed.
        self::assertSame('CLINIC_NOT_FOUND', $denied->errorCode);
        self::assertSame(404, $denied->httpStatus);
        self::assertSame(0, $this->auditCount('FILE_READ', 'file', $fileId), 'no sensitive read may be recorded');
        self::assertSame('', $returned, 'no bytes may leave storage');
    }

    /**
     * RED A (REST): the real `/files/{id}/stream` route must deliver no file bytes
     * under a scoped deny. The failure message carries the observed status/length.
     */
    public function testRedARestStreamMustNotDeliverFileBytesUnderScopedFileReadDeny(): void
    {
        $actor = $this->doctorWithScopedDeny($this->clinicA, RolesAndCapabilities::FILE_READ, 's5_reda3');
        $patient = $this->createPatient($this->clinicA);
        $fileId = $this->uploadViaRest($actor, $this->clinicA, $patient, 'patient_visible', 'red-a-rest.pdf');

        $this->assertScopedDenyPreconditions($actor, $this->clinicA, RolesAndCapabilities::FILE_READ);
        self::assertSame($this->clinicA, $this->attachmentClinicId($fileId), 'precondition: durable file clinic = A');

        $res = $this->dispatchStream($actor, $fileId);
        $body = is_string($res->get_data()) ? $res->get_data() : '';

        self::assertSame(
            404,
            $res->get_status(),
            'RED A evidence (REST): GET /files/' . $fileId . '/stream answered HTTP ' . $res->get_status()
                . ' with ' . strlen($body) . ' byte(s) of clinical content despite a scoped cpms_file_read deny'
        );
        self::assertSame('', $body, 'the denied response must carry no file bytes');
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($res), 'the safe not-found envelope must be kept');
    }

    // =====================================================================
    // RED B — clinical write despite a scoped deny (real note path)
    // =====================================================================

    /**
     * RED B (observation): does the product persist a durable clinical note for an
     * actor whose membership explicitly denies scoped `cpms_note_create`?
     * On valid main (defect) it does ⇒ the assertion reports the durable rows.
     */
    public function testRedBDurableNoteIsWrittenUnderScopedNoteCreateDeny(): void
    {
        $actor = $this->doctorWithScopedDeny($this->clinicA, RolesAndCapabilities::NOTE_CREATE, 's5_redb');
        $visitId = $this->createVisitFixture($this->clinicA, $actor);

        $this->assertScopedDenyPreconditions($actor, $this->clinicA, RolesAndCapabilities::NOTE_CREATE);
        self::assertSame(0, $this->noteCountForVisit($visitId), 'precondition: the visit starts note-free');

        try {
            App::clinicalService()->addNote($actor, $visitId, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'RED-B-DURABLE-WRITE-MARKER',
            ]);
        } catch (ClinicalException $e) {
            // Deliberately swallowed: this test measures the durable side effect only.
        }

        self::assertSame(
            0,
            $this->noteCountForVisit($visitId),
            'RED B evidence: ClinicalService::addNote() persisted durable row(s) in cpms_clinical_notes for visit '
                . $visitId . ' (durable clinic ' . $this->clinicA . ') although AuthorizationService denies scoped '
                . 'cpms_note_create for this actor [rows=' . $this->noteCountForVisit($visitId)
                . ', NOTE_CREATED audit=' . $this->auditCountByAction('NOTE_CREATED') . ']'
        );
    }

    /**
     * RED B (contract): the write must be denied before any mutation — no note row,
     * no version row, no NOTE_CREATED audit.
     */
    public function testRedBClinicalWriteMustBeDeniedBeforeDatabaseMutation(): void
    {
        $actor = $this->doctorWithScopedDeny($this->clinicA, RolesAndCapabilities::NOTE_CREATE, 's5_redb2');
        $visitId = $this->createVisitFixture($this->clinicA, $actor);

        $this->assertScopedDenyPreconditions($actor, $this->clinicA, RolesAndCapabilities::NOTE_CREATE);

        $denied = null;
        try {
            App::clinicalService()->addNote($actor, $visitId, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'RED-B-CONTRACT',
            ]);
        } catch (ClinicalException $e) {
            $denied = $e;
        }

        self::assertNotNull(
            $denied,
            'a scoped cpms_note_create deny must reject the write; the product persisted the note instead'
        );
        self::assertSame('CLINIC_PERMISSION_DENIED', $denied->errorCode);
        self::assertSame(403, $denied->httpStatus);
        self::assertSame(0, $this->noteCountForVisit($visitId));
        self::assertSame(0, $this->noteVersionRowCount(), 'no append-only version row may be written');
        self::assertSame(0, $this->auditCountByAction('NOTE_CREATED'));
    }

    // =====================================================================
    // DENY matrix
    // =====================================================================

    public function testSuspendedMembershipDeniesFileRead(): void
    {
        $actor = $this->makeUser('s5_susp', RolesAndCapabilities::ROLE_DOCTOR);
        $membershipId = $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $patient = $this->createPatient($this->clinicA);
        $fileId = $this->uploadViaService($actor, $patient, 'doctor_private', 'suspend-me.pdf');

        App::membership_service()->suspend_membership($membershipId);
        self::assertNull(
            App::membership_service()->active_membership_for($this->clinicA, $actor),
            'precondition: no ACTIVE membership remains'
        );
        self::assertFalse(
            $this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_READ),
            'precondition: a suspended membership must not satisfy scoped FILE_READ'
        );
        self::assertTrue($this->storedFileExists($fileId), 'precondition: the file is still on disk');

        try {
            App::medicalFileService()->stream($actor, $fileId);
            self::fail('a suspended membership must not keep reading Clinic files');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
        self::assertSame(0, $this->auditCount('FILE_READ', 'file', $fileId));
    }

    public function testSuspendedMembershipDeniesClinicalWrite(): void
    {
        $actor = $this->makeUser('s5_susp_w', RolesAndCapabilities::ROLE_DOCTOR);
        $membershipId = $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $visitId = $this->createVisitFixture($this->clinicA, $actor);

        App::membership_service()->suspend_membership($membershipId);

        try {
            App::clinicalService()->addNote($actor, $visitId, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'SUSPENDED-WRITE-ATTEMPT',
            ]);
            self::fail('a suspended membership must not be able to write clinical notes');
        } catch (ClinicalException $e) {
            // Non-active membership ⇒ the same non-disclosing not-found shape as a
            // foreign-Clinic object (no existence disclosure).
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame(0, $this->noteCountForVisit($visitId));
    }

    /**
     * CHANGE CONTRACT (۱۰) — an installation administrator has no automatic
     * clinical/file authority, even with every matching global capability granted.
     */
    public function testInstallationAdministratorWithoutMembershipHasNoClinicalOrFileAuthority(): void
    {
        $actor = $this->makeUser('s5_admin', 'administrator');
        $user = get_userdata($actor);
        self::assertNotFalse($user);
        foreach ([
            RolesAndCapabilities::MEDICAL_READ,
            RolesAndCapabilities::FILE_UPLOAD,
            RolesAndCapabilities::FILE_READ,
            RolesAndCapabilities::NOTE_CREATE,
        ] as $cap) {
            $user->add_cap($cap);
        }
        $user = get_userdata($actor);
        self::assertNotFalse($user);
        self::assertTrue($user->has_cap(RolesAndCapabilities::MEDICAL_READ), 'precondition: coarse global cap held');
        self::assertSame(
            [],
            App::membership_service()->active_memberships_for_user($actor),
            'precondition: the administrator holds no durable Clinic membership'
        );

        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $this->createClinician($this->clinicA, $actor));

        // (a) E7 record — full PHI of a Clinic the administrator does not belong to.
        try {
            App::clinicalService()->record($actor, $visitId);
            self::fail('an installation administrator must not read a Clinic medical record');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame(
            0,
            $this->auditCount('MEDICAL_RECORD_VIEWED', 'visit', $visitId),
            'a denied administrator must not even produce a record-view audit'
        );

        // (b) file upload — no durable attachment row and no byte on disk.
        $rowsBefore = $this->attachmentRowCount();
        $bytesBefore = $this->storedByteCount();
        try {
            App::medicalFileService()->upload(
                $actor,
                $this->uploadedFileArgs('admin-upload.pdf', $this->pdfContent()),
                $patient,
                null,
                'document',
                'patient_visible'
            );
            self::fail('an installation administrator must not store clinical files for a Clinic');
        } catch (ClinicalException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame($rowsBefore, $this->attachmentRowCount(), 'no attachment row may be written');
        self::assertSame($bytesBefore, $this->storedByteCount(), 'no byte may reach storage');
    }

    public function testClinicAActorCannotTouchClinicBFileOrVisit(): void
    {
        $actor = $this->makeUser('s5_cross', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);

        // Clinic B fixtures, provisioned by a legitimate B member.
        $bMember = $this->makeUser('s5_bmember', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($bMember, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        $patientB = $this->createPatient($this->clinicB);
        $visitB = $this->createVisit($this->clinicB, $patientB, $this->createClinician($this->clinicB, $bMember));
        $this->bindHarnessScope($this->clinicB);
        $fileB = $this->uploadViaService($bMember, $patientB, 'patient_visible', 'clinic-b-file.pdf');
        $this->bindHarnessScope($this->clinicA);

        self::assertSame($this->clinicB, $this->attachmentClinicId($fileB), 'precondition: file durably in B');
        self::assertTrue(
            $this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_READ),
            'precondition: the actor is a properly authorized member of A'
        );
        self::assertFalse(
            $this->authz()->can($actor, $this->clinicB, RolesAndCapabilities::FILE_READ),
            'precondition: the actor holds no authority in B'
        );

        // (a) read: B's file stays indistinguishable from "not found".
        try {
            App::medicalFileService()->stream($actor, $fileB);
            self::fail('a Clinic A member must not stream a Clinic B file');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
        self::assertSame(0, $this->auditCount('FILE_READ', 'file', $fileB));

        // (b) write: addNote has no Clinic predicate today — durable ownership must stop it.
        $notesBefore = $this->noteCountForVisit($visitB);
        try {
            App::clinicalService()->addNote($actor, $visitB, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'CROSS-CLINIC-WRITE-ATTEMPT',
            ]);
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode, 'foreign-Clinic objects stay non-disclosing');
        }
        self::assertSame(
            $notesBefore,
            $this->noteCountForVisit($visitB),
            'RED evidence (cross-Clinic write): a Clinic A actor mutated a Clinic B visit [rows='
                . $this->noteCountForVisit($visitB) . ']'
        );
    }

    /**
     * Contract (۹) — an explicit permission must never defeat the visibility policy:
     * a secretary holding a scoped `cpms_file_read` GRANT still cannot read a
     * doctor_private file, while patient_visible stays readable.
     */
    public function testExplicitFileReadGrantDoesNotMakeDoctorPrivateVisibleToSecretary(): void
    {
        $secretary = $this->makeUser('s5_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $membershipId = $this->createMembership($secretary, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::FILE_READ, 'grant');

        $doctor = $this->makeUser('s5_doc_priv', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($doctor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $patient = $this->createPatient($this->clinicA);

        $privateFile = $this->uploadViaService($doctor, $patient, 'doctor_private', 'private-for-doctor.pdf');
        $publicFile = $this->uploadViaService($secretary, $patient, 'patient_visible', 'front-desk.pdf');

        self::assertTrue(
            $this->authz()->can($secretary, $this->clinicA, RolesAndCapabilities::FILE_READ),
            'precondition: the secretary does hold the scoped permission'
        );

        $leakedBytes = 0;
        try {
            $streamed = App::medicalFileService()->stream($secretary, $privateFile);
            $leakedBytes = strlen((string) ($streamed['content'] ?? ''));
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode, 'private visibility must stay non-disclosing');
        }
        self::assertSame(
            0,
            $leakedBytes,
            'an explicit cpms_file_read grant must not make a doctor_private file readable for a secretary '
                . '(observed bytes=' . $leakedBytes . ')'
        );

        $readable = App::medicalFileService()->stream($secretary, $publicFile);
        self::assertStringContainsString('%PDF', (string) $readable['content'], 'the non-sensitive file stays readable');
    }

    /**
     * Contract (۱۳) — no first-Clinic fallback: the file's durable owner Clinic may
     * only be used as a candidate that membership + the scoped permission validate.
     */
    public function testMultiClinicActorNeverFallsBackToFirstClinic(): void
    {
        $actor = $this->makeUser('s5_multi', RolesAndCapabilities::ROLE_DOCTOR);
        $membershipA = $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->set_capability($membershipA, RolesAndCapabilities::FILE_READ, 'deny');

        $patientA = $this->createPatient($this->clinicA);
        $fileA = $this->uploadViaRest($actor, $this->clinicA, $patientA, 'patient_visible', 'multi-a.pdf');

        $patientB = $this->createPatient($this->clinicB);
        $this->bindHarnessScope($this->clinicB);
        $fileB = $this->uploadViaRest($actor, $this->clinicB, $patientB, 'patient_visible', 'multi-b.pdf');

        self::assertSame($this->clinicA, $this->attachmentClinicId($fileA));
        self::assertSame($this->clinicB, $this->attachmentClinicId($fileB));
        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_READ));
        self::assertTrue($this->authz()->can($actor, $this->clinicB, RolesAndCapabilities::FILE_READ));
        self::assertSame(
            [ $this->clinicA, $this->clinicB ],
            $this->sortedClinicIds($actor),
            'precondition: two active memberships — first-row selection is forbidden'
        );

        // No bound Scope at all (the stream route is skip-listed because it also
        // serves patients): the file's durable Clinic is the only candidate.
        $deniedA = $this->dispatchStream($actor, $fileA);
        self::assertSame(
            404,
            $deniedA->get_status(),
            'the other active Clinic must never be picked implicitly to satisfy a read of A: '
                . $this->describe($deniedA)
        );
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCode($deniedA));

        $allowedB = $this->dispatchStream($actor, $fileB);
        self::assertSame(
            200,
            $allowedB->get_status(),
            'the validated Clinic B authority must survive: ' . $this->describe($allowedB)
        );
        self::assertStringContainsString('%PDF', (string) $allowedB->get_data());
    }

    /**
     * Durable ownership is the authority, not the request: a file row that durably
     * claims another Clinic is unreachable through the actor's own Clinic context.
     */
    public function testInconsistentDurableOwnershipFailsClosed(): void
    {
        $actor = $this->makeUser('s5_forge', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);

        // A patient of A with an attachment row that durably claims B (the product
        // would never create such a row — that is exactly the point).
        $patientA = $this->createPatient($this->clinicA);
        $relative = $this->writeRawStoredFile($this->clinicB, 'forged.pdf');
        $fileId = $this->insertAttachmentRow($this->clinicB, $patientA, $relative, 'forged.pdf', $actor);

        self::assertSame($this->clinicB, $this->attachmentClinicId($fileId), 'precondition: durable clinic = B');
        self::assertTrue($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_READ));
        self::assertTrue($this->storedFileExists($fileId), 'precondition: bytes exist under the B root');

        try {
            App::medicalFileService()->stream($actor, $fileId);
            self::fail('a file whose durable Clinic is B must not be readable through Clinic A authority');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }

        // Invalid Clinic ids are never authorizable — no clinic_id=0 semantics.
        self::assertFalse($this->authz()->can($actor, 0, RolesAndCapabilities::FILE_READ));
        self::assertFalse($this->authz()->can($actor, -1, RolesAndCapabilities::FILE_READ));
        self::assertFalse($this->authz()->can(0, $this->clinicA, RolesAndCapabilities::FILE_READ));
    }

    /**
     * Contract (۲) — membership alone is not permission: an ACTIVE membership whose
     * role preset carries no clinical/file capability authorizes nothing.
     */
    public function testActiveMembershipAloneIsNotClinicalPermission(): void
    {
        $actor = $this->makeUser('s5_bare', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);

        $doctor = $this->makeUser('s5_owner', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($doctor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $this->createClinician($this->clinicA, $doctor));
        $fileId = $this->uploadViaService($doctor, $patient, 'patient_visible', 'owned-by-doctor.pdf');

        self::assertNotNull(
            App::membership_service()->active_membership_for($this->clinicA, $actor),
            'precondition: a durable ACTIVE membership exists'
        );
        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::NOTE_CREATE));
        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_READ));
        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::MEDICAL_READ));

        // (a) file read — no content may be produced
        try {
            $streamed = App::medicalFileService()->stream($actor, $fileId);
            self::fail(
                'a bare membership must not read a Clinic file; it returned '
                    . strlen((string) ($streamed['content'] ?? '')) . ' byte(s)'
            );
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }

        // (b) clinical write — nothing durable
        try {
            App::clinicalService()->addNote($actor, $visitId, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'MEMBERSHIP-IS-NOT-PERMISSION',
            ]);
            self::fail('a bare membership must not create clinical notes');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame(0, $this->noteCountForVisit($visitId), 'no note may be persisted');
        self::assertSame(0, $this->auditCountByAction('NOTE_CREATED'));

        // (c) record read — no PHI. Here the actor IS an active member of the
        // Clinic, so this is the same disclosure class as an existing capability
        // denial (403), not a foreign-Clinic object (404).
        try {
            App::clinicalService()->record($actor, $visitId);
            self::fail('a bare membership must not open the clinical record');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame(0, $this->auditCountByAction('MEDICAL_RECORD_VIEWED'));
    }

    // =====================================================================
    // ALLOW matrix (existing happy paths must keep working)
    // =====================================================================

    public function testActiveDoctorWithClinicPresetReadsFileAndWritesNote(): void
    {
        $actor = $this->makeUser('s5_allow', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $this->createClinician($this->clinicA, $actor));
        $fileId = $this->uploadViaService($actor, $patient, 'doctor_private', 'allowed.pdf');

        self::assertTrue($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_READ));

        $streamed = App::medicalFileService()->stream($actor, $fileId);
        self::assertStringContainsString(self::SECRET_MARKER, (string) $streamed['content']);
        self::assertSame(1, $this->auditCount('FILE_READ', 'file', $fileId), 'F-4 audit stays on sensitive reads');

        $note = App::clinicalService()->addNote($actor, $visitId, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'مراجعه‌کننده با سردرد — happy path',
        ]);
        self::assertGreaterThan(0, (int) $note['id']);
        self::assertSame(1, $this->noteCountForVisit($visitId));

        $record = App::clinicalService()->record($actor, $visitId);
        self::assertSame($visitId, (int) $record['visit']['id']);
        self::assertSame(1, $this->auditCount('MEDICAL_RECORD_VIEWED', 'visit', $visitId));
    }

    public function testExplicitMembershipGrantAuthorizesAndDenyOverridesIt(): void
    {
        $actor = $this->makeUser('s5_grant', RolesAndCapabilities::ROLE_DOCTOR);
        $membershipId = $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $this->createClinician($this->clinicA, $actor));

        self::assertFalse(
            $this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::NOTE_CREATE),
            'precondition: the membership preset has no clinical write'
        );
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::NOTE_CREATE, 'grant');
        self::assertTrue(
            $this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::NOTE_CREATE),
            'precondition: the explicit scoped grant authorizes it'
        );

        $note = App::clinicalService()->addNote($actor, $visitId, [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'written through a scoped grant',
        ]);
        self::assertGreaterThan(0, (int) $note['id']);
        self::assertSame(1, $this->noteCountForVisit($visitId));

        // Deny still overrides the grant — and then the write stops.
        App::membership_service()->set_capability($membershipId, RolesAndCapabilities::NOTE_CREATE, 'deny');
        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::NOTE_CREATE));
        try {
            App::clinicalService()->addNote($actor, $visitId, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'must never be persisted',
            ]);
            self::fail('an explicit scoped deny must override the grant');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_PERMISSION_DENIED', $e->errorCode);
        }
        self::assertSame(1, $this->noteCountForVisit($visitId), 'only the earlier authorized note exists');
    }

    public function testSameActorIsAuthorizedPerClinicIndependently(): void
    {
        $actor = $this->makeUser('s5_both', RolesAndCapabilities::ROLE_DOCTOR);
        $membershipA = $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $membershipB = $this->createMembership($actor, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR);

        $patientA = $this->createPatient($this->clinicA);
        $visitA = $this->createVisit($this->clinicA, $patientA, $this->createClinician($this->clinicA, $actor));

        // B's clinician must be a different WP user (u_clinician_user is UNIQUE).
        $bOwner = $this->makeUser('s5_bowner', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($bOwner, $this->clinicB, RolesAndCapabilities::ROLE_DOCTOR);
        $patientB = $this->createPatient($this->clinicB);
        $visitB = $this->createVisit($this->clinicB, $patientB, $this->createClinician($this->clinicB, $bOwner));

        $this->bindHarnessScope($this->clinicA);
        App::clinicalService()->addNote($actor, $visitA, [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'note in Clinic A',
        ]);

        $this->bindHarnessScope($this->clinicB);
        App::clinicalService()->addRecommendations($actor, $visitB, [
            'items' => [ [ 'type' => 'rest', 'text' => 'استراحت (Clinic B fixture)' ] ],
        ]);

        self::assertSame(1, $this->noteCountForVisit($visitA), 'precondition: A write landed');
        self::assertSame(1, $this->recommendationCountForVisit($visitB), 'precondition: B write landed');

        // Suspending B must not disturb A.
        App::membership_service()->suspend_membership($membershipB);
        $this->bindHarnessScope($this->clinicA);
        App::clinicalService()->addNote($actor, $visitA, [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'second note in A after B was suspended',
        ]);
        self::assertSame(2, $this->noteCountForVisit($visitA));

        $this->bindHarnessScope($this->clinicB);
        try {
            App::clinicalService()->addNote($actor, $visitB, [
                'category' => 'clinical_note',
                'visibility' => 'patient_visible',
                'content_text' => 'must fail — B membership is suspended',
            ]);
            self::fail('a suspended Clinic B membership must not authorize writes in B');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
        self::assertSame(0, $this->noteCountForVisit($visitB), 'nothing was written into B');

        // And the reverse: suspending A must not disturb B.
        App::membership_service()->suspend_membership($membershipA);
        App::membership_service()->reactivate_membership($membershipB);
        $this->bindHarnessScope($this->clinicB);
        App::clinicalService()->addNote($actor, $visitB, [
            'category' => 'clinical_note',
            'visibility' => 'patient_visible',
            'content_text' => 'note in B after A was suspended',
        ]);
        self::assertSame(1, $this->noteCountForVisit($visitB));
        self::assertSame(2, $this->noteCountForVisit($visitA), 'A keeps only its two earlier notes');
    }

    // =====================================================================
    // PATIENT — ownership policy, independent of staff membership
    // =====================================================================

    public function testPatientReadsOwnPatientVisibleFileWithoutAnyStaffMembership(): void
    {
        $secretary = $this->makeUser('s5_sec_p', RolesAndCapabilities::ROLE_SECRETARY);
        $this->createMembership($secretary, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        $patient = $this->createPatient($this->clinicA);
        $patientUser = $this->linkPatientUser($patient, $this->clinicA, 's5_pat_own')[1];

        self::assertSame(
            [],
            App::membership_service()->active_memberships_for_user($patientUser),
            'precondition: a patient is not Clinic staff and needs no membership'
        );

        // REST upload by the patient + REST stream — both through the route-level
        // service instance, so they share one storage root.
        $fileId = $this->patientUploadViaRest($patientUser, $patient, 'own-copy.pdf');
        $res = $this->dispatchStream($patientUser, $fileId);
        self::assertSame(200, $res->get_status(), 'patient-self stream must stay open: ' . $this->describe($res));
        self::assertStringContainsString('%PDF', (string) $res->get_data());
        self::assertFalse(
            $this->authz()->can($patientUser, $this->clinicA, RolesAndCapabilities::FILE_READ),
            'the patient has no scoped staff permission — and still must be served'
        );
    }

    public function testPatientCannotReadAnotherPatientsFile(): void
    {
        $secretary = $this->makeUser('s5_sec_q', RolesAndCapabilities::ROLE_SECRETARY);
        $this->createMembership($secretary, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);
        $patientA = $this->createPatient($this->clinicA);
        $patientB = $this->createPatient($this->clinicA);
        $userA = $this->linkPatientUser($patientA, $this->clinicA, 's5_pat_a')[1];
        $fileB = $this->uploadViaRest($secretary, $this->clinicA, $patientB, 'patient_visible', 'b-only.pdf');

        $res = $this->dispatchStream($userA, $fileB);
        self::assertSame(404, $res->get_status(), 'another patient\'s file must not open');
        self::assertSame('', is_string($res->get_data()) ? $res->get_data() : '', 'no bytes may be delivered');
        self::assertSame(0, $this->auditCount('FILE_READ', 'file', $fileB));

        // And the file itself is readable for its owner (non-vacuous witness).
        $ownerRes = $this->dispatchStream($this->linkPatientUser($patientB, $this->clinicA, 's5_pat_b')[1], $fileB);
        self::assertSame(200, $ownerRes->get_status(), 'precondition: the owner can read it: ' . $this->describe($ownerRes));
    }

    public function testPatientCannotReadDoctorPrivateFile(): void
    {
        $doctor = $this->makeUser('s5_doc_r', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($doctor, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR);
        $patient = $this->createPatient($this->clinicA);
        $patientUser = $this->linkPatientUser($patient, $this->clinicA, 's5_pat_priv')[1];
        $fileId = $this->uploadViaService($doctor, $patient, 'doctor_private', 'hidden-from-patient.pdf');

        try {
            App::medicalFileService()->stream($patientUser, $fileId);
            self::fail('a patient must not read a doctor_private file');
        } catch (ClinicalException $e) {
            self::assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }

        $res = $this->dispatchStream($patientUser, $fileId);
        self::assertSame(404, $res->get_status());
        self::assertSame('', is_string($res->get_data()) ? $res->get_data() : '');
    }

    // =====================================================================
    // SIDE EFFECT — denied operations mutate nothing
    // =====================================================================

    public function testDeniedOperationsLeaveNoDurableMutation(): void
    {
        // ACTIVE membership whose preset carries no CONSULT_COMPLETE / FILE_UPLOAD,
        // while the global WordPress role does hold those coarse capabilities.
        $actor = $this->makeUser('s5_nomutate', RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);
        $patient = $this->createPatient($this->clinicA);
        $visitId = $this->createVisit($this->clinicA, $patient, $this->createClinician($this->clinicA, $actor));

        $uploader = $this->authorizedDoctorIn($this->clinicA, 's5_uploader');
        $fileId = $this->uploadViaService($uploader, $patient, 'patient_visible', 'to-be-kept.pdf');

        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::CONSULT_COMPLETE));
        self::assertFalse($this->authz()->can($actor, $this->clinicA, RolesAndCapabilities::FILE_UPLOAD));
        self::assertTrue(
            get_userdata($actor)->has_cap(RolesAndCapabilities::CONSULT_COMPLETE),
            'precondition: the coarse global cap is held'
        );

        // (a) lifecycle mutation denied ⇒ the visit row stays untouched.
        try {
            App::clinicalService()->completeConsultation($actor, $visitId);
            self::fail('a scoped CONSULT_COMPLETE deny must stop the lifecycle mutation');
        } catch (ClinicalException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame('in_consultation', $this->visitStatus($visitId), 'no status mutation may happen');
        self::assertSame(0, $this->auditCount('CONSULTATION_COMPLETED', 'visit', $visitId));
        self::assertSame(
            0,
            $this->visitHistoryCount($visitId),
            'no cpms_visit_status_history row may be written'
        );

        // (b) soft delete denied ⇒ deleted_at stays NULL.
        try {
            App::medicalFileService()->softDelete($actor, $fileId);
            self::fail('a scoped cpms_file_upload deny must stop the soft delete');
        } catch (ClinicalException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame('', $this->attachmentDeletedAt($fileId), 'the attachment must not be soft-deleted');

        // (c) upload denied ⇒ no row and no byte.
        $rowsBefore = $this->attachmentRowCount();
        $bytesBefore = $this->storedByteCount();
        try {
            App::medicalFileService()->upload(
                $actor,
                $this->uploadedFileArgs('denied-upload.pdf', $this->pdfContent()),
                $patient,
                $visitId,
                'document',
                'patient_visible'
            );
            self::fail('a scoped cpms_file_upload deny must stop the upload before storage->store()');
        } catch (ClinicalException $e) {
            self::assertSame(404, $e->httpStatus);
        }
        self::assertSame($rowsBefore, $this->attachmentRowCount(), 'no attachment row may be written');
        self::assertSame($bytesBefore, $this->storedByteCount(), 'no byte may reach storage');

        // (d) read denied ⇒ no bytes and no FILE_READ audit.
        $secretFile = $this->uploadViaService($uploader, $patient, 'doctor_private', 'private-denial.pdf');
        $leaked = -1;
        try {
            $readable = App::medicalFileService()->stream($actor, $secretFile);
            $leaked = strlen((string) $readable['content']);
        } catch (ClinicalException $e) {
            $leaked = -1;
        }
        self::assertSame(-1, $leaked, 'a denied stream must throw before reading (observed bytes=' . $leaked . ')');
        self::assertSame(0, $this->auditCount('FILE_READ', 'file', $secretFile));
    }

    // =====================================================================
    // Fixtures / helpers
    // =====================================================================

    /**
     * Doctor actor (WP role cpms_doctor so the coarse global gate is satisfied) with
     * an ACTIVE membership in $clinicId plus an explicit membership deny for
     * $permission — precisely the shape a capability-only check cannot see.
     */
    private function doctorWithScopedDeny(int $clinicId, string $permission, string $login): int
    {
        $actor = $this->makeUser($login, RolesAndCapabilities::ROLE_DOCTOR);
        $membershipId = $this->createMembership($actor, $clinicId, RolesAndCapabilities::ROLE_DOCTOR);
        App::membership_service()->set_capability($membershipId, $permission, 'deny');

        return $actor;
    }

    private function assertScopedDenyPreconditions(int $actor, int $clinicId, string $permission): void
    {
        $user = get_userdata($actor);
        self::assertNotFalse($user, 'precondition: actor user exists');
        self::assertTrue(
            $user->has_cap($permission),
            'precondition: the coarse global capability (' . $permission . ') is held — today\'s gate passes'
        );

        $active = App::membership_service()->active_membership_for($clinicId, $actor);
        self::assertNotNull($active, 'precondition: a durable ACTIVE membership exists');
        self::assertSame('active', (string) $active['status']);

        self::assertFalse(
            $this->authz()->can($actor, $clinicId, $permission),
            'precondition: AuthorizationService denies the scoped permission (explicit deny)'
        );
    }

    private function authorizedDoctorIn(int $clinicId, string $login): int
    {
        $actor = $this->makeUser($login, RolesAndCapabilities::ROLE_DOCTOR);
        $this->createMembership($actor, $clinicId, RolesAndCapabilities::ROLE_DOCTOR);

        return $actor;
    }

    private function authz(): AuthorizationService
    {
        return new AuthorizationService(new MembershipRepository(App::db()));
    }

    private function bindHarnessScope(int $clinicId): void
    {
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
    }

    /**
     * Force route registration once, under a deterministic Scope, so the service
     * instance the REST routes captured is built from a defined storage root
     * (same convention as the other multi-Clinic integration suites).
     */
    private function warmRoutes(): void
    {
        $request = new WP_REST_Request('GET', self::NS . '/health');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        rest_do_request($request);
    }

    /**
     * @return list<int>
     */
    private function sortedClinicIds(int $userId): array
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['clinic_id'],
            App::membership_service()->active_memberships_for_user($userId)
        );
        sort($ids);

        return array_values(array_unique($ids));
    }

    private function createOrganization(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'Slice5 Org ' . $unique,
                's5-org-' . $unique,
                'active',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: explicit Organization row');
        self::assertSame(
            'active',
            (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT status FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $id
                )
            ),
            'precondition: the Organization is durable and active'
        );

        return $id;
    }

    private function createClinic(int $organizationId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics
                     (organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $organizationId,
                'Slice5 Clinic ' . $tag . ' ' . $unique,
                's5-clinic-' . strtolower($tag) . '-' . $unique,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id, 'precondition: dynamic Clinic id (never Clinic 1)');
        self::assertSame(
            $organizationId,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $id
                )
            ),
            'precondition: the Clinic is linked to the explicit Organization'
        );
        App::resetScope();

        return $id;
    }

    /**
     * `cpms_visits.location_id` از مهاجرت 0013 الزامی (NOT NULL + FK) است؛ هر
     * Clinic fixture یک Location پایدار خودش را می‌گیرد.
     */
    private function createLocation(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                     (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, 1, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'S5 Location ' . $unique,
                's5-loc-' . $unique,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: durable Location row (' . $wpdb->last_error . ')');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(24), $unique . '@s5.test');
        self::assertGreaterThan(0, $userId, 'precondition: user ' . $login . ' created');
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'precondition: user readable');
        $user->set_role($role);

        return $userId;
    }

    private function createMembership(int $userId, int $clinicId, string $roleKey): int
    {
        $id = App::membership_service()->create_membership($clinicId, $userId, $roleKey);
        self::assertGreaterThan(0, $id, 'precondition: durable membership row');

        return $id;
    }

    private function createPatient(int $clinicId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $seq = random_int(100000, 9999999);
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'MR-S5-' . $seq,
                'Slice5',
                'Patient' . $seq,
                '0912' . sprintf('%07d', $seq % 10000000),
                'active',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: patient row (' . $wpdb->last_error . ')');

        return $id;
    }

    /**
     * @return array{0:int,1:int} [patient_id, wp_user_id]
     */
    private function linkPatientUser(int $patientId, int $clinicId, string $login): array
    {
        global $wpdb;
        $userId = $this->makeUser($login, RolesAndCapabilities::ROLE_PATIENT);
        $mobile = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT mobile FROM ' . $wpdb->prefix . 'cpms_patients WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $patientId,
                $userId,
                $mobile,
                App::db()->nowUtcSql()
            )
        );
        self::assertGreaterThan(0, (int) $wpdb->insert_id, 'precondition: patient↔user link');

        return [ $patientId, $userId ];
    }

    private function createClinician(int $clinicId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians
                     (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
                 VALUES (%d, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                'Dr S5 ' . $wpUserId,
                $wpUserId,
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: clinician row (' . $wpdb->last_error . ')');

        return $id;
    }

    /**
     * A real persisted in-consultation visit of $clinicId — a status the machine
     * would legitimately accept for a complete transition, so the authorization
     * boundary is the only thing standing in the way.
     */
    private function createVisit(int $clinicId, int $patientId, int $clinicianId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d');
        $locationId = (int) ($this->locations[$clinicId] ?? 0);
        self::assertGreaterThan(0, $locationId, 'precondition: fixture Location for the Clinic');
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_visits
                     (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date,
                      check_in_at, waiting_since, called_at, consultation_started_at, active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: durable visit row (' . $wpdb->last_error . ')');

        return $id;
    }

    /**
     * Visit owned by $doctorUserId (its own clinician row), so the existing
     * own-visit/ownership rules stay satisfied and the scoped permission is the
     * only variable under test.
     */
    private function createVisitFixture(int $clinicId, int $doctorUserId): int
    {
        $patient = $this->createPatient($clinicId);
        $clinician = $this->createClinician($clinicId, $doctorUserId);

        return $this->createVisit($clinicId, $patient, $clinician);
    }

    /**
     * Upload through the product service (the real MIME/storage/metadata path).
     */
    private function uploadViaService(int $actor, int $patientId, string $visibility, string $filename): int
    {
        $row = App::medicalFileService()->upload(
            $actor,
            $this->uploadedFileArgs($filename, $this->pdfContent()),
            $patientId,
            null,
            'document',
            $visibility
        );
        $fileId = (int) ($row['id'] ?? 0);
        self::assertGreaterThan(0, $fileId, 'precondition: upload through the product path');
        $this->trackStoredFile($fileId);

        return $fileId;
    }

    /**
     * Upload through the real REST route: write and read then share the same
     * service instance, i.e. the same storage root, so a stream result can never be
     * explained by a fixture/root mismatch.
     */
    private function uploadViaRest(int $actor, int $clinicId, int $patientId, string $visibility, string $filename): int
    {
        $content = $this->pdfContent();
        $tmp = tempnam(sys_get_temp_dir(), 's5up_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        wp_set_current_user($actor);
        $request = new WP_REST_Request('POST', self::NS . '/files');
        $request->set_param('patient_id', $patientId);
        $request->set_param('category', 'document');
        $request->set_param('visibility', $visibility);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        $request->set_file_params([ 'file' => $this->uploadedFileArgs($filename, $content) ]);

        $res = rest_do_request($request);
        @unlink($tmp);
        $this->bindHarnessScope($clinicId);

        self::assertLessThan(
            400,
            $res->get_status(),
            'precondition: the authorized staff upload must succeed — ' . $this->describe($res)
        );
        $fileId = (int) ($this->dataOf($res)['id'] ?? 0);
        self::assertGreaterThan(0, $fileId, 'precondition: attachment id from the REST upload');
        $this->trackStoredFile($fileId);

        return $fileId;
    }

    private function patientUploadViaRest(int $patientUser, int $patientId, string $filename): int
    {
        $content = $this->pdfContent();
        $tmp = tempnam(sys_get_temp_dir(), 's5pu_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        wp_set_current_user($patientUser);
        $request = new WP_REST_Request('POST', self::NS . '/patients/' . $patientId . '/files');
        $request->set_param('category', 'document');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_file_params([ 'file' => $this->uploadedFileArgs($filename, $content) ]);

        $res = rest_do_request($request);
        @unlink($tmp);

        self::assertLessThan(400, $res->get_status(), 'precondition: the patient upload must succeed — ' . $this->describe($res));
        $fileId = (int) ($this->dataOf($res)['id'] ?? 0);
        self::assertGreaterThan(0, $fileId, 'precondition: attachment id from the patient upload');
        $this->trackStoredFile($fileId);

        return $fileId;
    }

    /**
     * `/files/{id}/stream` is deliberately skip-listed at the REST boundary (it also
     * serves patients), so no Scope is bound for it — that is the real production
     * shape of this route and is not modified by these tests.
     */
    private function dispatchStream(int $userId, int $fileId): WP_REST_Response
    {
        App::replaceExplicitScope(null);
        wp_set_current_user($userId);
        $request = new WP_REST_Request('GET', self::NS . '/files/' . $fileId . '/stream');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }

    /**
     * Durable attachment row + real bytes on disk, built directly because the
     * product would never produce this durable/ownership combination.
     */
    private function insertAttachmentRow(
        int $clinicId,
        int $patientId,
        string $relativePath,
        string $filename,
        int $uploadedBy
    ): int {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_medical_attachments
                     (clinic_id, patient_id, original_filename, stored_filename, mime_type, file_size,
                      storage_path, visibility, category, uploaded_by_wp_user_id, created_at)
                 VALUES (%d, %d, %s, %s, %s, %d, %s, %s, %s, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $clinicId,
                $patientId,
                $filename,
                basename($relativePath),
                'application/pdf',
                strlen($this->pdfContent()),
                $relativePath,
                'patient_visible',
                'document',
                $uploadedBy,
                App::db()->nowUtcSql()
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: durable attachment row (' . $wpdb->last_error . ')');
        $this->storedFiles[] = $relativePath;

        return $id;
    }

    private function writeRawStoredFile(int $clinicId, string $filename): string
    {
        $dir = rtrim($this->storagePath, '/') . '/' . $clinicId . '/s5';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            self::fail('precondition: the fixture storage directory must be creatable');
        }
        $stored = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents($dir . '/' . $stored, $this->pdfContent());

        return $clinicId . '/s5/' . $stored;
    }

    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids[] /Count 0 >>\nendobj\n"
            . '%' . self::SECRET_MARKER . "\n%%EOF\n";
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int}
     */
    private function uploadedFileArgs(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 's5svc_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => UPLOAD_ERR_OK,
            'type' => 'application/pdf',
        ];
    }

    private function trackStoredFile(int $fileId): void
    {
        global $wpdb;
        $path = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT storage_path FROM ' . $wpdb->prefix . 'cpms_medical_attachments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $fileId
            )
        );
        if (is_string($path) && $path !== '') {
            $this->storedFiles[] = $path;
        }
    }

    /**
     * The physical file must exist under one of the roots any service instance in
     * this process can use (fixture root, or the root the route-level instance was
     * built with) — otherwise a denial could be explained away by a missing file.
     */
    private function storedFileExists(int $fileId): bool
    {
        global $wpdb;
        $relative = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT storage_path FROM ' . $wpdb->prefix . 'cpms_medical_attachments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $fileId
            )
        );
        if ($relative === '') {
            return false;
        }
        foreach ([ $this->storagePath, LocalFileStorage::defaultBasePath() ] as $root) {
            if ($root !== '' && is_file(rtrim($root, '/') . '/' . ltrim($relative, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function attachmentClinicId(int $fileId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT clinic_id FROM ' . $wpdb->prefix . 'cpms_medical_attachments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $fileId
            )
        );
    }

    private function attachmentDeletedAt(int $fileId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(deleted_at, \'\') FROM ' . $wpdb->prefix . 'cpms_medical_attachments WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $fileId
            )
        );
    }

    private function attachmentRowCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_medical_attachments' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    private function noteCountForVisit(int $visitId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinical_notes WHERE visit_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
    }

    private function noteVersionRowCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinical_note_versions' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    private function recommendationCountForVisit(int $visitId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_recommendations WHERE visit_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
    }

    private function visitStatus(int $visitId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_visits WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
    }

    private function visitHistoryCount(int $visitId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_visit_status_history WHERE visit_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $visitId
            )
        );
    }

    private function auditCountByAction(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [ $action ]
        );
    }

    private function auditCount(string $action, string $resourceType, int $resourceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
                . ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            [ $action, $resourceType, $resourceId ]
        );
    }

    /**
     * Total bytes under the fixture storage root — the side-effect witness for
     * "denial happens before storage->store()".
     */
    private function storedByteCount(): int
    {
        if ($this->storagePath === '' || !is_dir($this->storagePath)) {
            return 0;
        }
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile()) {
                $bytes += (int) $f->getSize();
            }
        }

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(WP_REST_Response $res): array
    {
        $body = $res->get_data();
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return is_array($body) ? $body : [];
    }

    private function errorCode(WP_REST_Response $res): string
    {
        $body = $res->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }
        if (is_array($body)) {
            return (string) ($body['code'] ?? '');
        }

        return '';
    }

    private function describe(WP_REST_Response $res): string
    {
        $body = $res->get_data();

        return 'http=' . $res->get_status() . ' body=' . substr(
            is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
            0,
            300
        );
    }
}

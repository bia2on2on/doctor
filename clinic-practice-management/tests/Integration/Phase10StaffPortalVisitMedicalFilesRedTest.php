<?php
/**
 * Phase 10 — Staff Portal Doctor Visit Workspace: Medical Files — TEST-ONLY RED.
 *
 * Owner-approved bounded slice (sequencing verified live before writing):
 *   1. THIS slice — Staff Portal Doctor Visit Workspace — Medical Files:
 *      list existing Visit files, upload a protected medical file, and
 *      securely open/download an authorized file;
 *   2. handwriting/stylus is REQUIRED next, before Phase 10 may close;
 *   3. prescription print is NOT a Phase-10 closure requirement;
 *   4. Phase 11 remains NOT STARTED. Phase 10 remains IN PROGRESS — NOT CLOSED.
 *
 * Surface: the EXISTING Visit Workspace of the doctor clinical module mounted
 * in the shared Staff Portal (canonical `cpms-staff-portal`; the legacy
 * `cpms-doctor-portal` URL stays a single 302 entry redirect for an eligible
 * doctor). Every operational interaction is HYBRID: REST/FormData/AJAX with no
 * routine full-page reload (product_reloads=0).
 *
 * Intended portal boundary (narrow adapter in front of the ALREADY MERGED
 * shared E16/E17 + C3/C4 file backend, same architecture as the merged
 * workspace routes record/notes/prescriptions/recommendations/follow-ups/
 * complete and the Rx finalize adapter):
 *   POST /clinic/v1/doctor/portal/visits/{id}/files
 *        multipart `file` + `category` + `visibility` — the raw visit_id is a
 *        selector only; the SERVER derives patient_id/clinic/clinician from the
 *        persisted authorized Visit and delegates to the established
 *        MedicalFileService::upload. No client patient_id/clinician_id/
 *        clinic_id/location_id/visit_id key can create authority.
 *   GET  /clinic/v1/doctor/portal/visits/{id}/files/{file_id}/stream
 *        raw file_id is a selector only; the adapter resolves
 *        file_id -> persisted file -> visit_id/patient_id -> the authorized
 *        current Visit (same Visit Workspace authority as every merged
 *        workspace route) BEFORE delegating to MedicalFileService::stream.
 *   LIST = the ALREADY GREEN portal record payload (GET
 *        /doctor/portal/visits/{id}/record reuses the E7 `files` metadata),
 *        rendered with a portal-side allowlist.
 *
 * Live shared file backend, verified pre-write on main
 * f1bb5d49bf92307005fe41af348a1341b1e99f28 (NOT assumed):
 *   FilesController: POST /clinic/v1/files (E16 staff upload; cap
 *   cpms_file_upload; patient_id IS an established shared-route arg) and
 *   GET /clinic/v1/files/{id}/stream (E17 binary stream; nonce + authn;
 *   resource authorization in the service; safe headers
 *   Content-Type / Content-Length / Content-Disposition attachment /
 *   Cache-Control private / X-Content-Type-Options nosniff); C3/C4 patient
 *   routes /patients/{id}/files. Upload rate limit = 10/hr per WP user
 *   (CLINIC_RATE_LIMITED 429). MedicalFileService: finfo MIME sniff
 *   (allowlist application/pdf|image/jpeg|image/png|image/webp), extension<->MIME
 *   consistency, per-Clinic size setting `files.max_upload_bytes` (default
 *   10485760), category allowlist lab_result|image|scan|document|other
 *   (schema ENUM), visibility allowlist patient_visible|doctor_private
 *   (schema ENUM; doctor_private write = doctor role only; secretary read =
 *   patient_visible only; patient read = own patient_visible files via durable
 *   patient-user link), Clinic scoping + scoped authorization before any disk
 *   IO, Visit binding via nullable cpms_medical_attachments.visit_id
 *   (patient-level files intentionally exist without a Visit and stay on the
 *   established patient/shared surfaces — they are NOT forced into the Visit
 *   Workspace), randomized stored filename (32 hex + extension) under
 *   {clinic}/{2}/{name}.{ext} outside the webroot with deny guards,
 *   original_filename is presentation metadata only (truncated to 255), and
 *   audit events FILE_UPLOADED / FILE_READ (doctor_private OR lab_result) /
 *   FILE_SOFT_DELETED / FORBIDDEN_ACCESS_ATTEMPT. presentFile keys:
 *   id, patient_id, visit_id, category, original_filename, mime_type,
 *   file_size, visibility, created_at. E7 record `files` entry keys (verified
 *   live in ClinicalService::record): id, original_filename, category,
 *   visibility, mime_type, file_size, uploaded_by_wp_user_id, created_at —
 *   this shared contract is a GREEN control and is NOT redesigned here; the
 *   Staff Portal RENDERING allowlist is asserted separately and is the
 *   established-safe field set without uploader internals (and without
 *   storage_path / stored_filename / filesystem detail, which the shared
 *   payload already omits).
 *
 * The existing shared file routes/services and Patient Portal "My Files"
 * (C3/C4) are GREEN regression controls. RED must not and does not claim that
 * protected file storage, validation, audit or the shared stream contract are
 * missing — they exist and stay GREEN. The missing product contract is
 * exactly: the Staff Portal Doctor Visit Workspace file UX + the narrow
 * portal authority adapter above.
 *
 * INTENDED PRODUCT RED (missing behavior, verified pre-write):
 *   - no portal file upload/stream route is registered (REST dispatch answers
 *     404 rest_no_route) — Groups 2..5 anchor here;
 *   - the module template/JS has no Visit Workspace file section, no list
 *     rendering of record.files, no file picker/upload wiring and no
 *     authorized blob open/download wiring — Groups 1/6/8 anchor here;
 *   - the existing bin/pilot-doctor-portal.py has no Medical Files browser
 *     journey — Group 8b anchors here (the GREEN browser journey MUST live in
 *     that existing pilot, driven by the pilot gate).
 * Group 7 and the marked controls in the other groups are GREEN today and
 * MUST stay GREEN.
 *
 * Naming note for GREEN: the merged Phase 10 suites forbid, in the module UI,
 * the substrings 'workspace-file', 'workspace-complete', 'visit-complete',
 * 'workspace-reopen', 'visit-reopen', 'consult-reopen', 'rx-void', 'rx_void',
 * 'rx-print', 'prescription-print', 'voidPrescription', 'stylus',
 * 'handwriting-canvas', 'data-action="void"', 'data-action="complete"' and the
 * location.* navigation calls. The markers below
 * (workspace-visit-files-* / workspace-visit-file-*) are chosen exactly like
 * the merged Complete slice chose workspace-consult-complete-*: so that they
 * never collide with those guards (note 'workspace-visit-files-x' does NOT
 * contain 'workspace-file').
 *
 * Test-only: no product PHP/template/CSS/JS, no migration (latest remains
 * 2026_09_20_0022), no workflow change, no role/capability/category/visibility
 * state, no MedicalFileService change, no Patient Portal change, no docs
 * closure. Fixtures write real bytes only under a per-test sys temp storage
 * directory (current test convention) and clean it in tearDown — no git clean.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class Phase10StaffPortalVisitMedicalFilesRedTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';
    private const PORTAL_RECORD = 'clinic/v1/doctor/portal/visits/%d/record';
    private const PORTAL_FILES_UPLOAD = 'clinic/v1/doctor/portal/visits/%d/files';
    private const PORTAL_FILES_STREAM = 'clinic/v1/doctor/portal/visits/%d/files/%d/stream';
    private const SHARED_FILES_UPLOAD = 'clinic/v1/files';
    private const SHARED_FILES_STREAM = 'clinic/v1/files/%d/stream';
    private const PATIENT_FILES = 'clinic/v1/patients/%d/files';

    private const ROUTE_SHARED_UPLOAD = '/clinic/v1/files';
    private const ROUTE_SHARED_STREAM = '/clinic/v1/files/(?P<id>\d+)/stream';
    private const ROUTE_PATIENT_FILES = '/clinic/v1/patients/(?P<patient_id>\d+)/files';
    private const ROUTE_PORTAL_FILES_UPLOAD = '/clinic/v1/doctor/portal/visits/(?P<id>\d+)/files';
    private const ROUTE_PORTAL_FILES_STREAM = '/clinic/v1/doctor/portal/visits/(?P<id>\d+)/files/(?P<file_id>\d+)/stream';

    private const FIXED_UTC_DATE = '2026-03-14';
    private const TZ_TEHRAN = 'Asia/Tehran';
    private const LATEST_MIGRATION = '2026_09_26_0023';

    /** Live schema enums (initial migration 0001) — no new category/visibility state. */
    private const ATTACHMENT_CATEGORY_ENUM = "enum('lab_result','image','scan','document','other')";
    private const ATTACHMENT_VISIBILITY_ENUM = "enum('patient_visible','doctor_private')";

    /** Shared E7 record `files` entry keys (verified live, sorted) — GREEN control, not redesigned here. */
    private const RECORD_FILE_KEYS = ['category', 'created_at', 'file_size', 'id', 'mime_type', 'original_filename', 'uploaded_by_wp_user_id', 'visibility'];

    /** Established staff upload presenter (MedicalFileService::presentFile). */
    private const UPLOAD_PRESENTER_KEYS = ['category', 'created_at', 'file_size', 'id', 'mime_type', 'original_filename', 'patient_id', 'visibility', 'visit_id'];

    /** Accepted shared Staff Portal shell root markers (same set as the merged shell suites). */
    private const STAFF_SHELL_ROOT_PATTERNS = [
        'data-cpms-staff-portal-shell',
        'data-cpms-staff-shell',
        'cpms-staff-portal-shell',
        'cpms-staff-shell',
        'data-cpms-portal="staff"',
    ];

    /** UI markers — deliberately avoiding every merged-suite forbidden substring (see naming note). */
    private const LIST_MARKERS = [
        'workspace-visit-files-section',
        'workspace-visit-files-list',
        'workspace-visit-file-item',
        'workspace-visit-files-empty',
        'workspace-visit-files-count',
    ];
    private const UPLOAD_MARKERS = [
        'workspace-visit-files-upload-form',
        'workspace-visit-files-upload-input',
        'workspace-visit-files-category',
        'workspace-visit-files-visibility',
        'workspace-visit-files-upload-submit',
        'workspace-visit-files-upload-busy',
        'workspace-visit-files-upload-error',
        'workspace-visit-files-upload-success',
    ];
    private const OPEN_MARKER = 'workspace-visit-file-open';

    /** Merged workspace markers that must remain (GREEN guards). */
    private const MERGED_WORKSPACE_MARKERS = [
        'workspace-note-form',
        'workspace-note-submit',
        'workspace-rx-section',
        'workspace-rec-section',
        'workspace-fu-section',
        'workspace-consult-complete-section',
        'workspace-consult-complete-submit',
    ];

    /** Existing pilot proof tokens that must remain (GREEN guards). */
    private const PILOT_GUARD_TOKENS = [
        'assert_no_product_reload',
        'prove_legacy',
        'prove_queue_actions',
        'prove_workspace_recfu',
        'prove_workspace_complete',
        'assert_queue_hugs_content',
        'mobile-390',
        'tablet-768',
        'desktop-1366',
    ];

    /**
     * Tokens the future GREEN Medical Files browser journey must add to the
     * EXISTING bin/pilot-doctor-portal.py (all verified absent today).
     */
    private const PILOT_RED_TOKENS = [
        'prove_workspace_files',
        '/files',
        'workspace-visit-files-upload-submit',
        'workspace-visit-files-upload-busy',
        'workspace-visit-files-upload-error',
        'workspace-visit-files-upload-success',
        'workspace-visit-file-open',
        'files_real_upload',
        'files_upload_double_submit',
        'files_upload_failed_no_success',
        'files_upload_success_no_reload',
        'files_open_blob',
        'files_object_url_revoked',
        'files_no_public_url',
    ];

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        App::migrations()->migrate();
        do_action('rest_api_init');

        // Protected test storage (current convention: sys temp, outside any
        // webroot), created lazily by the first real store, cleaned in tearDown.
        $this->storagePath = sys_get_temp_dir() . '/cpms-p10-medfiles-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        if (is_dir($this->storagePath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->storagePath);
        }
        parent::tearDown();
    }

    // ============ Group 1 — PORTAL FILES ENTRY/LIST (intended RED on the UI; record metadata GREEN) ============

    public function testGroup1_PortalVisitWorkspaceFileListEntry(): void
    {
        $fx = $this->makePortalStage('g1');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patient = $this->insertPatient($fx['clinic'], 'g1_main');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $visit2 = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');

        $fPub = $this->seedFile($fx['doctor'], $patient, $visit, 'document', 'patient_visible', 'g1-public-report.pdf', $this->pdfContent() . "%g1pub\n");
        $fPriv = $this->seedFile($fx['doctor'], $patient, $visit, 'lab_result', 'doctor_private', 'g1-private-lab.pdf', $this->pdfContent() . "%g1priv\n");
        $fOtherVisit = $this->seedFile($fx['doctor'], $patient, $visit2, 'document', 'patient_visible', 'g1-other-visit.pdf', $this->pdfContent() . "%g1v2\n");
        $fNoVisit = $this->seedFile($fx['doctor'], $patient, null, 'document', 'patient_visible', 'g1-no-visit.pdf', $this->pdfContent() . "%g1nov\n");

        // ---- FIXTURE INTEGRITY (material DB rows + stored objects) ----
        $this->assertClinicianBinding($fx['clinician'], $fx['clinic'], $fx['doctor']);
        $this->assertVisitBinding($visit, $fx['clinic'], $fx['location'], $fx['clinician'], $patient);
        $this->assertVisitBinding($visit2, $fx['clinic'], $fx['location'], $fx['clinician'], $patient);
        $ids = [$fPub['id'], $fPriv['id'], $fOtherVisit['id'], $fNoVisit['id']];
        self::assertSame($ids, array_values(array_unique($ids)), 'G1.fixture: four distinct file rows persisted');
        self::assertSame(4, $this->fileCountForPatient($patient), 'G1.fixture: four live attachment rows really persisted for the patient');
        self::assertFileExists($fPub['abs'], 'G1.fixture: stored object on disk');
        self::assertFileExists($fPriv['abs'], 'G1.fixture: stored object on disk');
        self::assertFileExists($fOtherVisit['abs'], 'G1.fixture: stored object on disk');
        self::assertFileExists($fNoVisit['abs'], 'G1.fixture: stored object on disk');
        self::assertNull($fNoVisit['row']['visit_id'], 'G1.fixture: the patient-level file really has no Visit (model truth)');

        // ---- GREEN controls: the merged portal record already carries Visit file metadata ----
        wp_set_current_user($fx['doctor']);
        $r = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        $this->gate(200, $r, 'G1 control: merged portal record route still serves the authorized Visit');
        $files = $this->payload($r)['files'] ?? null;
        self::assertIsArray($files, 'G1 control: the E7 record payload carries a files list');
        $listedIds = array_map(static fn (array $f): int => (int) ($f['id'] ?? 0), $files);
        sort($listedIds);
        self::assertSame([$fPub['id'], $fPriv['id']], $listedIds,
            'G1 control: record lists exactly the CURRENT Visit files (forVisit), doctor_private included for the doctor (matrix 4.3)');
        foreach ($files as $entry) {
            self::assertSame(self::RECORD_FILE_KEYS, $this->sortedKeys($entry),
                'G1 control: shared E7 files entry key set stays exactly the established set (shared contract, not redesigned here)');
        }
        $encoded = (string) wp_json_encode($this->payload($r));
        self::assertStringNotContainsString('storage_path', $encoded, 'G1 control: record payload never carries storage_path');
        self::assertStringNotContainsString('stored_filename', $encoded, 'G1 control: record payload never carries stored_filename');
        self::assertStringNotContainsString($this->storagePath, $encoded, 'G1 control: record payload never carries filesystem detail');
        self::assertNotContains($fOtherVisit['id'], $listedIds, 'G1 control: another Visit file stays out of the current Visit list');
        self::assertNotContains($fNoVisit['id'], $listedIds, 'G1 control: patient-level (no-Visit) file stays out of the Visit Workspace list');

        // ---- INTENDED PRODUCT RED: the Staff Portal Visit Workspace has no file section/list ----
        $html = $this->renderCanonicalAs($fx['doctor'], StaffPortalShell::portal_url());
        $ui = $this->moduleUi();
        $missing = [];
        foreach (self::LIST_MARKERS as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'file list marker (' . $marker . ')';
            }
            if (!str_contains($html, 'data-role="' . $marker . '"')) {
                $missing[] = 'file list marker rendered in the canonical Staff Portal (' . $marker . ')';
            }
        }
        if (!str_contains($ui, 'data.files')) {
            $missing[] = 'file list rendered from the record payload (data.files)';
        }
        if ($missing !== []) {
            $this->annotate('red-G1', 'G1: Staff Portal Visit Workspace file list is missing', implode('; ', $missing));
        }
        self::assertSame([], $missing, 'G1: Staff Portal Visit Workspace file list is missing: ' . implode('; ', $missing));
    }

    // ============ Group 2 — PORTAL UPLOAD AUTHORITY (intended RED; shared E16 control GREEN) ============

    public function testGroup2_PortalUploadAuthorityAndIsolation(): void
    {
        $fx = $this->makePortalStage('g2');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patientOwn = $this->insertPatient($fx['clinic'], 'g2_own');
        $visitOwn = $this->insertVisit($patientOwn, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $patientVictim = $this->insertPatient($fx['clinic'], 'g2_victim');

        // Cross-doctor Visit in the SAME Clinic/Location — distinct WP user (u_clinician_user).
        $doctorB = $this->makeUser('g2_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G2 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $patientOther = $this->insertPatient($fx['clinic'], 'g2_other');
        $visitOther = $this->insertVisit($patientOther, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        // Foreign Clinic Visit — distinct WP user + persisted bindings.
        $foreignUser = $this->makeUser('g2_foreign_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $foreignOrg = $this->insertOrg('G2 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G2 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G2 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianForeign = $this->insertClinician('Dr G2 Foreign', $clinicForeign, 1, $foreignUser);
        cpms_test_seed_membership($foreignUser, $clinicForeign, RolesAndCapabilities::ROLE_DOCTOR);
        $patientForeign = $this->insertPatient($clinicForeign, 'g2_foreign');
        $visitForeign = $this->insertVisit($patientForeign, $clinicianForeign, $clinicForeign, $locForeign, 'in_consultation');

        // Same Clinic, other Location — own doctor, but the Visit is bound to another Location.
        $locOther = $this->insertLocation($fx['clinic'], 'G2 Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g2_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');

        $secretary = $this->makeUser('g2_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);

        // ---- FIXTURE INTEGRITY ----
        $users = [$fx['doctor'], $doctorB, $foreignUser, $secretary];
        self::assertSame(count($users), count(array_unique($users)), 'G2.fixture: WP users must be distinct');
        $this->assertClinicianBinding($clinicianB, $fx['clinic'], $doctorB);
        $this->assertClinicianBinding($clinicianForeign, $clinicForeign, $foreignUser);
        $this->assertVisitBinding($visitOwn, $fx['clinic'], $fx['location'], $fx['clinician'], $patientOwn);
        $this->assertVisitBinding($visitOther, $fx['clinic'], $fx['location'], $clinicianB, $patientOther);
        $this->assertVisitBinding($visitOtherLoc, $fx['clinic'], $locOther, $fx['clinician'], $patientOtherLoc);
        $this->assertVisitBinding($visitForeign, $clinicForeign, $locForeign, $clinicianForeign, $patientForeign);

        // ---- GREEN control: the established shared E16 upload service path works (regression anchor) ----
        wp_set_current_user($fx['doctor']);
        $rShared = $this->dispatch('POST', '/' . self::SHARED_FILES_UPLOAD, [
            'patient_id' => $patientOwn,
            'visit_id' => $visitOwn,
            'category' => 'document',
            'visibility' => 'patient_visible',
        ], $this->scopeHeaders($fx['clinic'], null), 'valid', $this->makeUploadedFile('g2-shared-control.pdf', $this->pdfContent() . "%shared\n"));
        $this->gate(201, $rShared, 'G2 control: shared E16 staff upload still succeeds for the owning doctor');
        $sharedId = (int) ($this->payload($rShared)['id'] ?? 0);
        self::assertGreaterThan(0, $sharedId, 'G2 control: shared upload persisted a file row');
        self::assertSame($patientOwn, (int) $this->fileRow($sharedId)['patient_id'], 'G2 control: shared upload bound the declared patient (established shared contract)');
        self::assertSame(self::UPLOAD_PRESENTER_KEYS, $this->sortedKeys($this->payload($rShared)),
            'G2 control: established presentFile key set (shared contract, reused by the future portal adapter)');

        // ---- INTENDED PRODUCT RED: the portal upload authority boundary is missing ----
        $rowsBefore = $this->fileCountForPatient($patientOwn) + $this->fileCountForPatient($patientVictim);
        $objectsBefore = $this->storageFileCount();

        $rOwn = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitOwn), [
            'category' => 'lab_result',
            'visibility' => 'doctor_private',
            // Forged client authority keys — NONE of these may create authority,
            // retarget the patient, or bind another Visit/Clinic/Location.
            'patient_id' => $patientVictim,
            'clinician_id' => $clinicianB,
            'clinic_id' => $clinicForeign,
            'location_id' => $locForeign,
            'visit_id' => $visitOther,
        ], $headers, 'valid', $this->makeUploadedFile('g2-portal-lab.pdf', $this->pdfContent() . "%g2portal\n"));
        $this->assertReachedPortalFilesUpload($rOwn, 'G2.A');
        $this->gate(201, $rOwn, 'G2.A: own authorized Visit upload succeeds at the portal boundary');
        $portal = $this->payload($rOwn);
        self::assertSame(self::UPLOAD_PRESENTER_KEYS, $this->sortedKeys($portal),
            'G2.A: portal response reuses the established presenter (no parallel backend)');
        self::assertStringNotContainsString('storage_path', (string) wp_json_encode($portal), 'G2.A: upload response never carries storage_path');
        self::assertStringNotContainsString('stored_filename', (string) wp_json_encode($portal), 'G2.A: upload response never carries stored_filename');
        $fileId = (int) ($portal['id'] ?? 0);
        self::assertGreaterThan(0, $fileId, 'G2.A: upload persisted a file row');
        $row = $this->fileRow($fileId);
        self::assertSame($patientOwn, (int) $row['patient_id'],
            'G2.B: SERVER derives patient_id from the persisted authorized Visit (forged patient_id ignored — never authority)');
        self::assertSame($visitOwn, $row['visit_id'] === null ? null : (int) $row['visit_id'],
            'G2.B: the path Visit selector binds the file (forged body visit_id never retargets)');
        self::assertSame($fx['clinic'], (int) $row['clinic_id'], 'G2.B: clinic comes from the authorized Visit (forged clinic_id ignored)');
        self::assertSame('lab_result', (string) $row['category'], 'G2.A: category persisted');
        self::assertSame('doctor_private', (string) $row['visibility'], 'G2.A: visibility persisted');
        self::assertSame($fx['doctor'], (int) $row['uploaded_by_wp_user_id'], 'G2.A: uploader is the authenticated doctor');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $row['stored_filename'], 'G2.A: randomized stored filename');
        self::assertStringNotContainsString('uploads', (string) $row['storage_path'], 'G2.A: storage stays outside uploads');
        $abs = $this->storagePath . '/' . (string) $row['storage_path'];
        self::assertFileExists($abs, 'G2.A: stored object exists on disk');
        self::assertSame($this->pdfContent() . "%g2portal\n", (string) file_get_contents($abs), 'G2.A: stored bytes match');
        self::assertSame(1, $this->auditCount('FILE_UPLOADED', 'file', $fileId), 'G2.A: exactly one established FILE_UPLOADED audit (no duplicate portal audit event)');
        self::assertSame($rowsBefore + 1, $this->fileCountForPatient($patientOwn) + $this->fileCountForPatient($patientVictim),
            'G2.B: forged patient_id did not create a row on the victim record');

        // ---- INTENDED PRODUCT RED (encoded): isolation denials ----
        $rowsDenyBefore = $this->fileCountForPatient($patientOwn) + $this->fileCountForPatient($patientOther) + $this->fileCountForPatient($patientForeign) + $this->fileCountForPatient($patientOtherLoc);
        $objectsDenyBefore = $this->storageFileCount();
        $auditUploadsBefore = $this->auditCountForAction('FILE_UPLOADED');

        $rCross = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitOther), ['category' => 'document'], $headers, 'valid', $this->makeUploadedFile('g2-cross.pdf', $this->pdfContent()));
        $this->gate(404, $rCross, 'G2.C: same-Clinic cross-doctor Visit upload denied (non-enumerating)');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rCross), 'G2.C: established non-enumerating code');

        $rForeign = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitForeign), ['category' => 'document'], $headers, 'valid', $this->makeUploadedFile('g2-foreign.pdf', $this->pdfContent()));
        $this->gate(404, $rForeign, 'G2.C: foreign-Clinic Visit upload denied (non-enumerating)');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeign), 'G2.C: established non-enumerating code');

        $rOtherLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitOtherLoc), ['category' => 'document'], $headers, 'valid', $this->makeUploadedFile('g2-otherloc.pdf', $this->pdfContent()));
        $this->gate(404, $rOtherLoc, 'G2.C: foreign-Location Visit upload denied (non-enumerating)');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rOtherLoc), 'G2.C: established non-enumerating code');

        wp_set_current_user($secretary);
        $rSecretary = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitOwn), ['category' => 'document'], $headers, 'valid', $this->makeUploadedFile('g2-sec.pdf', $this->pdfContent()));
        $this->gate(403, $rSecretary, 'G2.D: secretary cannot upload through the doctor Visit Workspace boundary');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($rSecretary), 'G2.D: established permission code');

        wp_set_current_user($fx['doctor']);
        $rBadNonce = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitOwn), ['category' => 'document'], $headers, 'invalid', $this->makeUploadedFile('g2-nonce.pdf', $this->pdfContent()));
        $this->gate(403, $rBadNonce, 'G2.D: invalid nonce rejected');
        self::assertSame('CLINIC_INVALID_NONCE', $this->errCode($rBadNonce), 'G2.D: established nonce code');

        wp_set_current_user(0);
        $rAnon = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitOwn), ['category' => 'document'], $headers, 'valid', $this->makeUploadedFile('g2-anon.pdf', $this->pdfContent()));
        $this->gate(401, $rAnon, 'G2.D: anonymous upload rejected');
        self::assertSame('CLINIC_UNAUTHORIZED', $this->errCode($rAnon), 'G2.D: established authentication code');

        self::assertSame($rowsDenyBefore,
            $this->fileCountForPatient($patientOwn) + $this->fileCountForPatient($patientOther) + $this->fileCountForPatient($patientForeign) + $this->fileCountForPatient($patientOtherLoc),
            'G2.C/D: denied uploads persist no file rows');
        self::assertSame($objectsDenyBefore, $this->storageFileCount(), 'G2.C/D: denied uploads store no objects');
        self::assertSame($auditUploadsBefore, $this->auditCountForAction('FILE_UPLOADED'),
            'G2.C/D: denied uploads produce no false success audit (only the shared control + G2.A uploads exist)');
    }

    // ============ Group 3 — LOCATION POLICY 0/1/N at file operations (intended RED) ============

    public function testGroup3_PortalFileUploadLocationPolicy(): void
    {
        global $wpdb;

        // A. N>1 eligible Locations without an explicit trusted Location => REQUIRED (never guess the first/primary).
        $fxMulti = $this->makePortalStage('g3m');
        $locSecond = $this->insertLocation($fxMulti['clinic'], 'G3 Second Loc', self::TZ_TEHRAN, 0);
        $patientMulti = $this->insertPatient($fxMulti['clinic'], 'g3m_patient');
        $visitMulti = $this->insertVisit($patientMulti, $fxMulti['clinician'], $fxMulti['clinic'], $fxMulti['location'], 'in_consultation');
        self::assertSame(1, (int) $this->locationRow($fxMulti['location'])['is_primary'], 'G3.fixture: Visit Location is the primary Location');
        self::assertSame(2, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1', [$fxMulti['clinic']]),
            'G3.fixture: exactly two active Locations persisted');
        self::assertGreaterThan(0, $locSecond, 'G3.fixture: second Location persisted');
        $rowsBefore = $this->fileCountForPatient($patientMulti);
        $objectsBefore = $this->storageFileCount();

        wp_set_current_user($fxMulti['doctor']);
        $rNoLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitMulti), ['category' => 'document'], $this->scopeHeaders($fxMulti['clinic'], null), 'valid', $this->makeUploadedFile('g3m.pdf', $this->pdfContent()));
        $this->assertReachedPortalFilesUpload($rNoLoc, 'G3.A');
        $this->gate(400, $rNoLoc, 'G3.A: N>1 eligible Locations requires an explicit trusted Location');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($rNoLoc), 'G3.A: location-required code');
        $rawNoLoc = $this->rawErrorData($rNoLoc);
        self::assertSame('location_id', $rawNoLoc['field'] ?? null, 'G3.A: field=location_id');
        self::assertSame('location_required', $rawNoLoc['reason'] ?? null, 'G3.A: reason=location_required');
        foreach (array_keys($rawNoLoc) as $key) {
            self::assertStringNotContainsString('eligible', (string) $key, 'G3.A: eligible Location IDs must not leak');
            self::assertNotContains((string) $key, ['location_ids', 'locations'], 'G3.A: Location list must not leak');
        }
        self::assertSame($rowsBefore, $this->fileCountForPatient($patientMulti), 'G3.A: no file row without a trusted Location');
        self::assertSame($objectsBefore, $this->storageFileCount(), 'G3.A: no stored object without a trusted Location');

        // B. N>1 with the explicit trusted Location => succeeds.
        $rWithLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitMulti), ['category' => 'document'], $this->scopeHeaders($fxMulti['clinic'], $fxMulti['location']), 'valid', $this->makeUploadedFile('g3m-ok.pdf', $this->pdfContent()));
        $this->gate(201, $rWithLoc, 'G3.B: explicit trusted Location upload succeeds');
        self::assertSame($rowsBefore + 1, $this->fileCountForPatient($patientMulti), 'G3.B: persisted with the trusted Location');
        $rowB = $this->fileRow((int) ($this->payload($rWithLoc)['id'] ?? 0));
        self::assertSame($visitMulti, $rowB['visit_id'] === null ? null : (int) $rowB['visit_id'], 'G3.B: file bound to the authorized Visit');

        // C. Exactly 1 eligible Location => auto-resolution (no Location header).
        $fxSingle = $this->makePortalStage('g3s');
        $patientSingle = $this->insertPatient($fxSingle['clinic'], 'g3s_patient');
        $visitSingle = $this->insertVisit($patientSingle, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        $patientSingle2 = $this->insertPatient($fxSingle['clinic'], 'g3s_patient2');
        $visitSingle2 = $this->insertVisit($patientSingle2, $fxSingle['clinician'], $fxSingle['clinic'], $fxSingle['location'], 'in_consultation');
        wp_set_current_user($fxSingle['doctor']);
        $rSingle = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitSingle), ['category' => 'document'], $this->scopeHeaders($fxSingle['clinic'], null), 'valid', $this->makeUploadedFile('g3s.pdf', $this->pdfContent()));
        $this->gate(201, $rSingle, 'G3.C: single eligible Location auto-resolution succeeds');

        // D. Explicit Location of a FOREIGN Clinic => fail closed.
        $foreignOrg = $this->insertOrg('G3 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G3 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G3 Foreign Loc', self::TZ_TEHRAN, 1);
        $rowsSingle2 = $this->fileCountForPatient($patientSingle2);
        $rForeignLoc = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitSingle2), ['category' => 'document'], $this->scopeHeaders($fxSingle['clinic'], $locForeign), 'valid', $this->makeUploadedFile('g3s-fl.pdf', $this->pdfContent()));
        $this->gate(403, $rForeignLoc, 'G3.D: foreign explicit Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rForeignLoc), 'G3.D: established scope denial code');

        // E. Inactive explicit Location (own Clinic) => fail closed.
        $locInactive = $this->insertLocation($fxSingle['clinic'], 'G3 Inactive Loc', self::TZ_TEHRAN, 0);
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE id = %d', $locInactive));
        self::assertSame(0, (int) $this->locationRow($locInactive)['is_active'], 'G3.fixture: inactive Location really persisted inactive');
        $rInactive = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitSingle2), ['category' => 'document'], $this->scopeHeaders($fxSingle['clinic'], $locInactive), 'valid', $this->makeUploadedFile('g3s-in.pdf', $this->pdfContent()));
        $this->gate(403, $rInactive, 'G3.E: inactive explicit Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rInactive), 'G3.E: established scope denial code');

        // F. Location-scoped membership, explicit Location NOT assigned to the doctor => fail closed.
        $fxUnassigned = $this->makePortalStage('g3u');
        $locUnassigned = $this->insertLocation($fxUnassigned['clinic'], 'G3 Unassigned Loc', self::TZ_TEHRAN, 0);
        $service = App::membership_service();
        $membership = $service->membership_for($fxUnassigned['clinic'], $fxUnassigned['doctor']);
        self::assertIsArray($membership, 'G3.fixture: doctor membership really persisted');
        $service->set_scope_mode((int) $membership['id'], 'location', [(int) $fxUnassigned['location']]);
        self::assertSame([(int) $fxUnassigned['location']], $service->membership_location_ids((int) $membership['id']),
            'G3.fixture: membership assignment is exactly one Location');
        $patientUnassigned = $this->insertPatient($fxUnassigned['clinic'], 'g3u_patient');
        $visitUnassigned = $this->insertVisit($patientUnassigned, $fxUnassigned['clinician'], $fxUnassigned['clinic'], $locUnassigned, 'in_consultation');
        wp_set_current_user($fxUnassigned['doctor']);
        $rUnassigned = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitUnassigned), ['category' => 'document'], $this->scopeHeaders($fxUnassigned['clinic'], $locUnassigned), 'valid', $this->makeUploadedFile('g3u.pdf', $this->pdfContent()));
        $this->gate(403, $rUnassigned, 'G3.F: explicit unassigned Location fails closed');
        self::assertSame('CLINIC_SCOPE_UNAVAILABLE', $this->errCode($rUnassigned), 'G3.F: established scope denial code');
        self::assertSame(0, $this->fileCountForPatient($patientUnassigned), 'G3.F: unassigned Location stored no file row');

        // G. 0 eligible Locations => fail closed.
        $fxZero = $this->makePortalStage('g3z');
        $patientZero = $this->insertPatient($fxZero['clinic'], 'g3z_patient');
        $visitZero = $this->insertVisit($patientZero, $fxZero['clinician'], $fxZero['clinic'], $fxZero['location'], 'in_consultation');
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_locations SET is_active = 0 WHERE clinic_id = %d', $fxZero['clinic']));
        self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1', [$fxZero['clinic']]),
            'G3.fixture: zero eligible Locations really persisted');
        wp_set_current_user($fxZero['doctor']);
        $rZero = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visitZero), ['category' => 'document'], $this->scopeHeaders($fxZero['clinic'], $fxZero['location']), 'valid', $this->makeUploadedFile('g3z.pdf', $this->pdfContent()));
        $this->gateIn([403, 404], $rZero, 'G3.G: zero eligible Locations fails closed');
        self::assertNotSame('rest_no_route', $this->errCode($rZero), 'G3.G: denial comes from the portal boundary, not a missing route');
        self::assertSame(0, $this->fileCountForPatient($patientZero), 'G3.G: zero eligible Locations stored no file row');
        self::assertSame($rowsSingle2, $this->fileCountForPatient($patientSingle2), 'G3.D/E: denied Locations left no file rows');
    }

    // ============ Group 4 — UPLOAD DOMAIN VALIDATION / STORAGE (shared contract GREEN; portal delegation intended RED) ============

    public function testGroup4_UploadDomainValidationAndStorageContract(): void
    {
        $fx = $this->makePortalStage('g4');
        $patient = $this->insertPatient($fx['clinic'], 'g4_main');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $secretary = $this->makeUser('g4_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($fx['doctor']);

        // ---- GREEN controls: the established MedicalFileService validation/storage contract ----
        $ok = $this->seedFile($fx['doctor'], $patient, $visit, 'document', 'patient_visible', '../../g4-ok.pdf', $this->pdfContent() . "%ok\n");
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $ok['row']['stored_filename'], 'G4 control: randomized stored filename (F-2)');
        self::assertStringNotContainsString('uploads', (string) $ok['row']['storage_path'], 'G4 control: storage outside uploads');
        self::assertStringNotContainsString('..', (string) $ok['row']['storage_path'], 'G4 control: traversal-ish original name never reaches storage_path');
        self::assertSame('../../g4-ok.pdf', (string) $ok['row']['original_filename'], 'G4 control: original filename kept as presentation metadata only');
        self::assertFileExists($this->storagePath . '/.htaccess', 'G4 control: storage root carries the deny guard');
        self::assertSame(1, $this->auditCount('FILE_UPLOADED', 'file', $ok['id']), 'G4 control: successful upload writes FILE_UPLOADED');

        $reject = function (string $label, array $file, ?string $category, ?string $visibility, string $errorCode, int $status, int $actor = 0) use ($patient, $visit, $fx): void {
            $actor = $actor ?: $fx['doctor'];
            $rowsBefore = $this->fileCountForPatient($patient);
            $objectsBefore = $this->storageFileCount();
            $auditsBefore = $this->auditCountForAction('FILE_UPLOADED');
            try {
                App::medicalFileService()->upload(
                    $actor,
                    $file,
                    $patient,
                    $visit,
                    $category ?? 'other',
                    $visibility ?? 'patient_visible'
                );
                self::fail('G4 control: expected ' . $errorCode . ' (' . $label . ')');
            } catch (\ClinicCore\Application\Clinical\ClinicalException $e) {
                self::assertSame($errorCode, $e->errorCode, 'G4 control: ' . $label . ' error code'); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established domain exception property
                self::assertSame($status, $e->httpStatus, 'G4 control: ' . $label . ' HTTP status'); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established domain exception property
            }
            self::assertSame($rowsBefore, $this->fileCountForPatient($patient), 'G4 control: ' . $label . ' persisted no row');
            self::assertSame($objectsBefore, $this->storageFileCount(), 'G4 control: ' . $label . ' stored no object');
            self::assertSame($auditsBefore, $this->auditCountForAction('FILE_UPLOADED'), 'G4 control: ' . $label . ' produced no false success audit');
        };

        // MIME truth (finfo) — php disguised as jpg.
        $reject('fake MIME', $this->makeUploadedFile('shell.jpg', '<?php system($_GET["c"]); ?>'), 'image', 'patient_visible', 'CLINIC_FILE_INVALID', 400);
        // Extension<->MIME consistency — real PDF bytes declared as .jpg.
        $reject('extension/MIME mismatch', $this->makeUploadedFile('document.jpg', $this->pdfContent()), 'document', 'patient_visible', 'CLINIC_FILE_INVALID', 400);
        // Empty/missing upload + upload error + zero size.
        $reject('empty upload', ['name' => 'x.pdf', 'tmp_name' => '', 'size' => 0, 'error' => 0], 'document', 'patient_visible', 'CLINIC_FILE_INVALID', 400);
        $tmpErr = $this->tempFile($this->pdfContent());
        $reject('upload error', ['name' => 'x.pdf', 'tmp_name' => $tmpErr, 'size' => 3, 'error' => UPLOAD_ERR_INI_SIZE], 'document', 'patient_visible', 'CLINIC_FILE_INVALID', 400);
        $tmpZero = $this->tempFile('');
        $reject('zero size', ['name' => 'x.pdf', 'tmp_name' => $tmpZero, 'size' => 0, 'error' => 0], 'document', 'patient_visible', 'CLINIC_FILE_INVALID', 400);
        // Category + visibility allowlists (schema enums).
        $reject('unknown category', $this->makeUploadedFile('c.pdf', $this->pdfContent()), 'invoice', 'patient_visible', 'CLINIC_VALIDATION_FAILED', 422);
        $reject('unknown visibility', $this->makeUploadedFile('v.pdf', $this->pdfContent()), 'document', 'public', 'CLINIC_VALIDATION_FAILED', 422);
        // doctor_private write is doctor-only (P-6).
        $reject('secretary doctor_private', $this->makeUploadedFile('dp.pdf', $this->pdfContent()), 'document', 'doctor_private', 'CLINIC_PERMISSION_DENIED', 403, $secretary);
        // Long filename is truncated to 255 as presentation metadata.
        $long = $this->seedFile($fx['doctor'], $patient, $visit, 'document', 'patient_visible', str_repeat('n', 300) . '.pdf', $this->pdfContent() . "%long\n");
        self::assertLessThanOrEqual(255, strlen((string) $long['row']['original_filename']), 'G4 control: original filename truncated to 255');

        // Size limit from the per-Clinic setting (live constant via setting, not invented).
        App::settingsFactory()->forClinic($fx['clinic'])->set('files.max_upload_bytes', 1024);
        Settings::flushCache();
        self::assertSame(1024, (int) App::settingsFactory()->forClinic($fx['clinic'])->get('files.max_upload_bytes', 10485760),
            'G4.fixture: per-Clinic size override really persisted');
        $reject('size over limit', $this->makeUploadedFile('big.pdf', $this->pdfContent() . str_repeat('x', 4096)), 'document', 'patient_visible', 'CLINIC_FILE_INVALID', 400);
        App::settingsFactory()->forClinic($fx['clinic'])->set('files.max_upload_bytes', 10485760);
        Settings::flushCache();

        // ---- GREEN control: the established upload rate limit (10/hr per user) on the shared route ----
        $rateUser = $this->makeUser('g4_rate_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $rateClinician = $this->insertClinician('Dr G4 Rate', $fx['clinic'], 1, $rateUser);
        cpms_test_seed_membership($rateUser, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $this->assertClinicianBinding($rateClinician, $fx['clinic'], $rateUser);
        $ratePatient = $this->insertPatient($fx['clinic'], 'g4_rate');
        wp_set_current_user($rateUser);
        $limited = null;
        for ($i = 1; $i <= 11; $i++) {
            $r = $this->dispatch('POST', '/' . self::SHARED_FILES_UPLOAD, [
                'patient_id' => $ratePatient,
                'category' => 'other',
            ], $this->scopeHeaders($fx['clinic'], null), 'valid', $this->makeUploadedFile('g4-rate-' . $i . '.pdf', $this->pdfContent() . '%r' . $i . "\n"));
            if ($i <= 10) {
                $this->gate(201, $r, 'G4 control: rate window pass ' . $i . ' of 10 succeeds');
            } else {
                $limited = $r;
            }
        }
        self::assertNotNull($limited, 'G4 control: the 11th upload attempt really ran');
        $this->gate(429, $limited, 'G4 control: 11th upload hits the established rate limit');
        self::assertSame('CLINIC_RATE_LIMITED', $this->errCode($limited), 'G4 control: established rate-limit code');

        // ---- INTENDED PRODUCT RED: the portal upload must apply this same validation contract ----
        wp_set_current_user($fx['doctor']);
        $rowsPortalBefore = $this->fileCountForPatient($patient);
        $objectsPortalBefore = $this->storageFileCount();
        $auditsPortalBefore = $this->auditCountForAction('FILE_UPLOADED');
        $rBadMime = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visit), [
            'category' => 'image',
            'visibility' => 'patient_visible',
        ], $this->scopeHeaders($fx['clinic'], $fx['location']), 'valid', $this->makeUploadedFile('shell.jpg', '<?php system($_GET["c"]); ?>'));
        $this->assertReachedPortalFilesUpload($rBadMime, 'G4.A');
        $this->gate(400, $rBadMime, 'G4.A: portal upload enforces the established MIME/extension/size contract');
        self::assertSame('CLINIC_FILE_INVALID', $this->errCode($rBadMime), 'G4.A: established validation code');
        self::assertSame($rowsPortalBefore, $this->fileCountForPatient($patient), 'G4.A: rejected portal upload persists no row');
        self::assertSame($objectsPortalBefore, $this->storageFileCount(), 'G4.A: rejected portal upload stores no object');

        $rBadCategory = $this->dispatch('POST', '/' . sprintf(self::PORTAL_FILES_UPLOAD, $visit), [
            'category' => 'invoice',
            'visibility' => 'patient_visible',
        ], $this->scopeHeaders($fx['clinic'], $fx['location']), 'valid', $this->makeUploadedFile('g4-badcat.pdf', $this->pdfContent()));
        $this->gate(422, $rBadCategory, 'G4.B: portal upload enforces the established category allowlist');
        self::assertSame('CLINIC_VALIDATION_FAILED', $this->errCode($rBadCategory), 'G4.B: established validation code');
        self::assertSame($rowsPortalBefore, $this->fileCountForPatient($patient), 'G4.B: rejected portal upload persists no row');
        self::assertSame($auditsPortalBefore, $this->auditCountForAction('FILE_UPLOADED'), 'G4.A/B: rejected portal uploads produce no false success audit');
    }

    // ============ Group 5 — SECURE DOWNLOAD AUTHORITY (intended RED; shared E17 control GREEN) ============

    public function testGroup5_PortalSecureDownloadAuthorityAndIsolation(): void
    {
        $fx = $this->makePortalStage('g5');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);

        $patient = $this->insertPatient($fx['clinic'], 'g5_main');
        $visit1 = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $visit2 = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'waiting');

        // Cross-doctor Visit of the SAME patient — same-patient must NOT equal same-Visit authority.
        $doctorB = $this->makeUser('g5_doc_b', RolesAndCapabilities::ROLE_DOCTOR);
        $clinicianB = $this->insertClinician('Dr G5 B', $fx['clinic'], 1, $doctorB);
        cpms_test_seed_membership($doctorB, $fx['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $visit3 = $this->insertVisit($patient, $clinicianB, $fx['clinic'], $fx['location'], 'in_consultation');

        // Foreign Clinic file.
        $foreignUser = $this->makeUser('g5_foreign_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $foreignOrg = $this->insertOrg('G5 Foreign Org');
        $clinicForeign = $this->insertClinicInOrg('G5 Foreign Clinic', $foreignOrg, self::TZ_TEHRAN);
        $locForeign = $this->insertLocation($clinicForeign, 'G5 Foreign Loc', self::TZ_TEHRAN, 1);
        $clinicianForeign = $this->insertClinician('Dr G5 Foreign', $clinicForeign, 1, $foreignUser);
        cpms_test_seed_membership($foreignUser, $clinicForeign, RolesAndCapabilities::ROLE_DOCTOR);
        $patientForeign = $this->insertPatient($clinicForeign, 'g5_foreign');
        $visitForeign = $this->insertVisit($patientForeign, $clinicianForeign, $clinicForeign, $locForeign, 'in_consultation');

        // Foreign Location Visit (own doctor).
        $locOther = $this->insertLocation($fx['clinic'], 'G5 Other Loc', self::TZ_TEHRAN, 0);
        $patientOtherLoc = $this->insertPatient($fx['clinic'], 'g5_otherloc');
        $visitOtherLoc = $this->insertVisit($patientOtherLoc, $fx['clinician'], $fx['clinic'], $locOther, 'in_consultation');

        $content = $this->pdfContent() . "%g5priv\n";
        $fCurrentPriv = $this->seedFile($fx['doctor'], $patient, $visit1, 'lab_result', 'doctor_private', 'g5-current-private.pdf', $content);
        $fCurrentPub = $this->seedFile($fx['doctor'], $patient, $visit1, 'document', 'patient_visible', 'g5-current-public.pdf', $this->pdfContent() . "%g5pub\n");
        $fSamePatientOtherOwnVisit = $this->seedFile($fx['doctor'], $patient, $visit2, 'document', 'patient_visible', 'g5-v2.pdf', $this->pdfContent() . "%g5v2\n");
        $fSamePatientOtherDoctorVisit = $this->seedFile($doctorB, $patient, $visit3, 'document', 'patient_visible', 'g5-v3.pdf', $this->pdfContent() . "%g5v3\n");
        $fForeign = $this->seedFile($foreignUser, $patientForeign, $visitForeign, 'document', 'doctor_private', 'g5-foreign.pdf', $this->pdfContent() . "%g5foreign\n");
        $fOtherLoc = $this->seedFile($fx['doctor'], $patientOtherLoc, $visitOtherLoc, 'document', 'patient_visible', 'g5-otherloc.pdf', $this->pdfContent() . "%g5ol\n");
        $fNoVisit = $this->seedFile($fx['doctor'], $patient, null, 'document', 'patient_visible', 'g5-no-visit.pdf', $this->pdfContent() . "%g5nov\n");
        $fDeleted = $this->seedFile($fx['doctor'], $patient, $visit1, 'document', 'patient_visible', 'g5-deleted.pdf', $this->pdfContent() . "%g5del\n");
        App::medicalFileService()->softDelete($fx['doctor'], $fDeleted['id']);

        // ---- FIXTURE INTEGRITY ----
        $this->assertClinicianBinding($clinicianB, $fx['clinic'], $doctorB);
        $this->assertClinicianBinding($clinicianForeign, $clinicForeign, $foreignUser);
        $this->assertVisitBinding($visit3, $fx['clinic'], $fx['location'], $clinicianB, $patient);
        foreach ([$fCurrentPriv, $fCurrentPub, $fSamePatientOtherOwnVisit, $fSamePatientOtherDoctorVisit, $fForeign, $fOtherLoc, $fNoVisit] as $f) {
            self::assertFileExists($f['abs'], 'G5.fixture: material stored object exists on disk');
        }
        self::assertNotSame($visit1, $visit2, 'G5.fixture: distinct Visits');
        self::assertNull($fNoVisit['row']['visit_id'], 'G5.fixture: patient-level file really has no Visit');

        // ---- GREEN control: the established shared E17 stream contract still works ----
        wp_set_current_user($fx['doctor']);
        $rShared = $this->dispatch('GET', '/' . sprintf(self::SHARED_FILES_STREAM, $fCurrentPub['id']), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(200, $rShared, 'G5 control: shared E17 stream still succeeds for the owning doctor');
        self::assertSame($fCurrentPub['content'], $rShared->get_data(), 'G5 control: shared stream serves the protected bytes');
        self::assertSame('application/pdf', $rShared->headers['Content-Type'] ?? null, 'G5 control: established Content-Type');
        self::assertStringStartsWith('attachment; filename=', (string) ($rShared->headers['Content-Disposition'] ?? ''), 'G5 control: established attachment delivery');
        self::assertSame('nosniff', $rShared->headers['X-Content-Type-Options'] ?? null, 'G5 control: established nosniff header');
        self::assertSame('private, max-age=0, no-cache', $rShared->headers['Cache-Control'] ?? null, 'G5 control: established private caching');
        self::assertSame(0, $this->auditCount('FILE_READ', 'file', $fCurrentPub['id']),
            'G5 control: plain document/patient_visible read writes no FILE_READ (established F-4 trigger set)');

        // ---- INTENDED PRODUCT RED: the portal stream/download authority boundary is missing ----
        $rOwn = $this->dispatch('GET', '/' . sprintf(self::PORTAL_FILES_STREAM, $visit1, $fCurrentPriv['id']), [], $headers);
        $this->assertReachedPortalFilesStream($rOwn, 'G5.A');
        $this->gate(200, $rOwn, 'G5.A: doctor-private file of the authorized current Visit opens at the portal boundary');
        self::assertSame($content, $rOwn->get_data(), 'G5.A: established protected bytes');
        self::assertSame('application/pdf', $rOwn->headers['Content-Type'] ?? null, 'G5.A: established Content-Type');
        self::assertStringStartsWith('attachment; filename=', (string) ($rOwn->headers['Content-Disposition'] ?? ''), 'G5.A: established attachment delivery (no public URL, no raw storage navigation)');
        self::assertSame('nosniff', $rOwn->headers['X-Content-Type-Options'] ?? null, 'G5.A: established nosniff header');
        self::assertSame('private, max-age=0, no-cache', $rOwn->headers['Cache-Control'] ?? null, 'G5.A: established private caching');
        self::assertSame(1, $this->auditCount('FILE_READ', 'file', $fCurrentPriv['id']), 'G5.A: sensitive read writes exactly one established FILE_READ audit');

        // ---- INTENDED PRODUCT RED (encoded): file->Visit/patient binding + isolation matrix ----
        $fileDimensionDenials = [
            'file of another Visit (same doctor, same patient)' => [$visit1, $fSamePatientOtherOwnVisit['id']],
            'file of same patient on another doctor Visit' => [$visit1, $fSamePatientOtherDoctorVisit['id']],
            'file of a foreign Clinic' => [$visit1, $fForeign['id']],
            'file of a foreign Location Visit' => [$visit1, $fOtherLoc['id']],
            'patient-level file without Visit (model truth: not a Visit Workspace file)' => [$visit1, $fNoVisit['id']],
            'soft-deleted file' => [$visit1, $fDeleted['id']],
            'nonexistent file selector' => [$visit1, 2147483647],
        ];
        $auditsBefore = $this->auditCountForAction('FILE_READ');
        $canonicalFingerprint = null;
        foreach ($fileDimensionDenials as $label => $pair) {
            [$vid, $fid] = $pair;
            $r = $this->dispatch('GET', '/' . sprintf(self::PORTAL_FILES_STREAM, $vid, $fid), [], $headers);
            $this->gate(404, $r, 'G5.B: ' . $label . ' denied (selector never grants authority)');
            $fingerprint = [$this->errCode($r), $this->errMessage($r), $r->get_status()];
            if ($canonicalFingerprint === null) {
                $canonicalFingerprint = $fingerprint;
            }
            self::assertSame($canonicalFingerprint, $fingerprint,
                'G5.B: file-dimension denials share one non-enumerating fingerprint (' . $label . ')');
            self::assertNotSame($content, $r->get_data(), 'G5.B: denied stream serves no file bytes for ' . $label);
        }
        self::assertSame(['CLINIC_NOT_FOUND', 'فایل یافت نشد', 404], $canonicalFingerprint,
            'G5.B: established file denial fingerprint (indistinguishable from missing)');

        $visitDimensionDenials = [
            'authorized file via a cross-doctor Visit selector' => [$visit3, $fCurrentPriv['id']],
            'authorized file via a foreign-Clinic Visit selector' => [$visitForeign, $fCurrentPriv['id']],
            'authorized file via a foreign-Location Visit selector' => [$visitOtherLoc, $fCurrentPriv['id']],
        ];
        foreach ($visitDimensionDenials as $label => [$vid, $fid]) {
            $r = $this->dispatch('GET', '/' . sprintf(self::PORTAL_FILES_STREAM, $vid, $fid), [], $headers);
            $this->gate(404, $r, 'G5.C: ' . $label . ' denied');
            self::assertSame('CLINIC_NOT_FOUND', $this->errCode($r), 'G5.C: established workspace non-enumerating code for ' . $label);
            self::assertNotSame($content, $r->get_data(), 'G5.C: denied stream serves no file bytes for ' . $label);
        }

        // Foreign-Clinic doctor and staff/patient roles cannot obtain it through this surface.
        wp_set_current_user($foreignUser);
        $rForeignDoc = $this->dispatch('GET', '/' . sprintf(self::PORTAL_FILES_STREAM, $visit1, $fCurrentPriv['id']), [], $this->scopeHeaders($clinicForeign, $locForeign));
        $this->gate(404, $rForeignDoc, 'G5.D: foreign-Clinic doctor cannot obtain the file');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rForeignDoc), 'G5.D: non-enumerating code');
        self::assertNotSame($content, $rForeignDoc->get_data(), 'G5.D: no bytes leak');

        $secretary = $this->makeUser('g5_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($secretary);
        $rSecretary = $this->dispatch('GET', '/' . sprintf(self::PORTAL_FILES_STREAM, $visit1, $fCurrentPriv['id']), [], $headers);
        $this->gate(403, $rSecretary, 'G5.D: secretary cannot use the doctor Visit Workspace file boundary');
        self::assertNotSame($content, $rSecretary->get_data(), 'G5.D: no bytes leak');

        $patientUser = $this->makeUser('g5_patient', RolesAndCapabilities::ROLE_PATIENT);
        $this->insertPatientLink($fx['clinic'], $patient, $patientUser);
        wp_set_current_user($patientUser);
        $rPatient = $this->dispatch('GET', '/' . sprintf(self::PORTAL_FILES_STREAM, $visit1, $fCurrentPriv['id']), [], $headers);
        $this->gate(403, $rPatient, 'G5.D: patient cannot use the doctor Visit Workspace file boundary');
        self::assertNotSame($content, $rPatient->get_data(), 'G5.D: no bytes leak');

        self::assertSame($auditsBefore, $this->auditCountForAction('FILE_READ'),
            'G5.B-D: denied reads produce no false success FILE_READ audit');
    }

    // ============ Group 6 — METADATA / PRIVACY (shared payload GREEN; portal rendering allowlist intended RED) ============

    public function testGroup6_PortalFileMetadataRenderingAllowlist(): void
    {
        $fx = $this->makePortalStage('g6');
        $headers = $this->scopeHeaders($fx['clinic'], $fx['location']);
        $patient = $this->insertPatient($fx['clinic'], 'g6_main');
        $visit = $this->insertVisit($patient, $fx['clinician'], $fx['clinic'], $fx['location'], 'in_consultation');
        $f = $this->seedFile($fx['doctor'], $patient, $visit, 'document', 'doctor_private', 'g6-secret-name.pdf', $this->pdfContent() . "%g6\n");
        self::assertFileExists($f['abs'], 'G6.fixture: material stored object exists on disk');

        // ---- GREEN control: the shared E7 record presentation already omits storage internals ----
        wp_set_current_user($fx['doctor']);
        $r = $this->dispatch('GET', '/' . sprintf(self::PORTAL_RECORD, $visit), [], $headers);
        $this->gate(200, $r, 'G6 control: merged portal record route serves the Visit');
        $files = $this->payload($r)['files'] ?? [];
        self::assertCount(1, $files, 'G6 control: one Visit file listed');
        $entry = $files[0];
        self::assertSame(self::RECORD_FILE_KEYS, $this->sortedKeys($entry), 'G6 control: shared files entry key set unchanged (broader-than-UI fields stay in the SHARED contract only)');
        self::assertArrayNotHasKey('storage_path', $entry, 'G6 control: no storage_path in the shared entry');
        self::assertArrayNotHasKey('stored_filename', $entry, 'G6 control: no stored_filename in the shared entry');
        self::assertArrayHasKey('original_filename', $entry, 'G6 control: presentation filename present');
        self::assertArrayHasKey('mime_type', $entry, 'G6 control: mime_type present');
        self::assertArrayHasKey('file_size', $entry, 'G6 control: file_size present');
        self::assertArrayHasKey('category', $entry, 'G6 control: category present');
        self::assertArrayHasKey('visibility', $entry, 'G6 control: visibility present');
        self::assertArrayHasKey('created_at', $entry, 'G6 control: created_at present');

        // ---- INTENDED PRODUCT RED: the Staff Portal rendering allowlist for the file list ----
        $ui = $this->moduleUi();
        $missing = [];
        $pos = strpos($ui, 'workspace-visit-file-item');
        if ($pos === false) {
            $missing[] = 'file item rendering (record.files -> workspace items, allowlist: id/original_filename/category/visibility/mime_type/file_size/created_at)';
        } else {
            $window = substr($ui, max(0, $pos - 2500), 9000);
            foreach (['original_filename', 'category', 'visibility', 'file_size'] as $field) {
                if (!str_contains($window, $field)) {
                    $missing[] = 'list rendering shows allowlisted field (' . $field . ')';
                }
            }
            foreach (['storage_path', 'stored_filename', 'metadata_json', 'uploaded_by_wp_user_id'] as $forbidden) {
                if (str_contains($window, $forbidden)) {
                    $missing[] = 'rendering allowlist violation: ' . $forbidden . ' must never render (UI hiding is not authorization, but internal fields stay internal)';
                }
            }
            if (!str_contains($window, 'esc(') && !str_contains($window, 'textContent')) {
                $missing[] = 'output escaping for file name/labels (esc() or textContent)';
            }
        }
        if ($missing !== []) {
            $this->annotate('red-G6', 'G6: Staff Portal file metadata rendering allowlist is missing', implode('; ', $missing));
        }
        self::assertSame([], $missing, 'G6: Staff Portal file metadata rendering allowlist is missing: ' . implode('; ', $missing));
    }

    // ============ Group 7 — SHARED / PATIENT REGRESSION (GREEN controls only) ============

    public function testGroup7_SharedAndPatientPortalFileContractsUnchanged(): void
    {
        // Shared routes unchanged (E16/E17 + C3/C4).
        self::assertTrue($this->routeRegistered(self::ROUTE_SHARED_UPLOAD), 'G7.A: shared E16 upload route unchanged');
        self::assertTrue($this->routeRegistered(self::ROUTE_SHARED_STREAM), 'G7.A: shared E17 stream route unchanged');
        self::assertTrue($this->routeRegistered(self::ROUTE_PATIENT_FILES), 'G7.A: Patient Portal C3/C4 files route unchanged');
        foreach (['upload', 'stream', 'patientUpload', 'patientFiles', 'staffFiles', 'softDelete'] as $method) {
            self::assertTrue(method_exists(\ClinicCore\Application\Clinical\MedicalFileService::class, $method), 'G7.A: shared MedicalFileService::' . $method . ' reused, not duplicated');
        }

        // Schema unchanged — no new category/visibility state, no migration.
        $table = App::db()->table('cpms_medical_attachments');
        self::assertSame(self::ATTACHMENT_CATEGORY_ENUM, strtolower((string) $this->columnType($table, 'category')), 'G7.B: attachment category enum unchanged');
        self::assertSame(self::ATTACHMENT_VISIBILITY_ENUM, strtolower((string) $this->columnType($table, 'visibility')), 'G7.B: attachment visibility enum unchanged');
        $latestMigration = (string) App::db()->fetchValue(
            'SELECT version FROM ' . App::db()->table('cpms_schema_migrations') . ' ORDER BY version DESC LIMIT 1'
        );
        self::assertSame(self::LATEST_MIGRATION, $latestMigration, 'G7.B: no migration/schema change in this slice');

        // Patient Portal "My Files" semantics unchanged (regression evidence, C3/C4).
        $fx = $this->makePortalStage('g7');
        $patient = $this->insertPatient($fx['clinic'], 'g7_main');
        $patientUser = $this->makeUser('g7_patient', RolesAndCapabilities::ROLE_PATIENT);
        $this->insertPatientLink($fx['clinic'], $patient, $patientUser);
        $fPub = $this->seedFile($fx['doctor'], $patient, null, 'document', 'patient_visible', 'g7-pub.pdf', $this->pdfContent() . "%g7pub\n");
        $fPriv = $this->seedFile($fx['doctor'], $patient, null, 'lab_result', 'doctor_private', 'g7-priv.pdf', $this->pdfContent() . "%g7priv\n");

        wp_set_current_user($patientUser);
        $rList = $this->dispatch('GET', '/' . sprintf(self::PATIENT_FILES, $patient), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(200, $rList, 'G7.C: Patient Portal file list unchanged');
        $names = array_map(static fn (array $f): string => (string) ($f['original_filename'] ?? ''), (array) ($this->payload($rList)['files'] ?? []));
        self::assertContains('g7-pub.pdf', $names, 'G7.C: patient_visible file listed');
        self::assertNotContains('g7-priv.pdf', $names, 'G7.C: doctor_private NEVER leaks to the patient');

        $rStream = $this->dispatch('GET', '/' . sprintf(self::SHARED_FILES_STREAM, $fPub['id']), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(200, $rStream, 'G7.C: patient streams own patient_visible file unchanged');
        self::assertSame($fPub['content'], $rStream->get_data(), 'G7.C: protected bytes unchanged');
        $rPriv = $this->dispatch('GET', '/' . sprintf(self::SHARED_FILES_STREAM, $fPriv['id']), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(404, $rPriv, 'G7.C: doctor_private stays unreadable for the patient');
        self::assertSame('CLINIC_NOT_FOUND', $this->errCode($rPriv), 'G7.C: non-enumerating denial unchanged');

        $rUpload = $this->dispatch('POST', '/' . sprintf(self::PATIENT_FILES, $patient), ['category' => 'document'], $this->scopeHeaders($fx['clinic'], null), 'valid', $this->makeUploadedFile('g7-upload.pdf', $this->pdfContent() . "%g7up\n"));
        $this->gate(201, $rUpload, 'G7.C: patient upload (C3) unchanged');
        self::assertSame('patient_visible', (string) ($this->payload($rUpload)['visibility'] ?? ''), 'G7.C: patient uploads stay patient_visible');

        // Staff visibility semantics unchanged: secretary reads patient_visible only, doctor reads all (E16/E17 matrix).
        $secretary = $this->makeUser('g7_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        $list = App::medicalFileService()->staffFiles($secretary, $patient);
        foreach ($list as $row) {
            self::assertSame('patient_visible', (string) ($row['visibility'] ?? ''), 'G7.D: secretary list stays patient_visible-only');
        }
        wp_set_current_user($secretary);
        $rSecPriv = $this->dispatch('GET', '/' . sprintf(self::SHARED_FILES_STREAM, $fPriv['id']), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(404, $rSecPriv, 'G7.D: secretary stream of doctor_private stays denied (shared contract unchanged)');
        wp_set_current_user($fx['doctor']);
        $rDocPriv = $this->dispatch('GET', '/' . sprintf(self::SHARED_FILES_STREAM, $fPriv['id']), [], $this->scopeHeaders($fx['clinic'], null));
        $this->gate(200, $rDocPriv, 'G7.D: doctor stream of doctor_private stays allowed (shared contract unchanged)');
    }

    // ============ Group 8 — STAFF PORTAL HYBRID UI / BROWSER CONTRACT (intended RED) ============

    public function testGroup8_StaffPortalHybridFilesUiContract(): void
    {
        $fx = $this->makePortalStage('g8');
        $secretary = $this->makeUser('g8_secretary', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($secretary, $fx['clinic'], RolesAndCapabilities::ROLE_SECRETARY);

        // ---- GREEN controls: shared Staff Portal architecture is authoritative ----
        self::assertTrue(StaffPortalShell::doctor_module_eligible($fx['doctor']), 'G8 control: doctor module eligible for the doctor');
        self::assertFalse(StaffPortalShell::doctor_module_eligible($secretary), 'G8 control: doctor module (and so the file workflow) not eligible for the secretary');
        $staffUrl = StaffPortalShell::portal_url();
        self::assertStringNotContainsString('/wp-admin/', $staffUrl, 'G8 control: canonical Staff Portal is a frontend URL');

        wp_set_current_user($fx['doctor']);
        $this->go_to($this->requestFor(DoctorPortalShell::portal_url()));
        self::assertSame(untrailingslashit($staffUrl), untrailingslashit(DoctorPortalShell::legacy_redirect_target($fx['doctor'])),
            'G8 control: legacy doctor entry redirects to the canonical Staff Portal');
        wp_set_current_user($secretary);
        self::assertSame('', DoctorPortalShell::legacy_redirect_target($secretary), 'G8 control: no legacy redirect for a non-eligible user');

        $html = $this->renderCanonicalAs($fx['doctor'], $staffUrl);
        $this->assertStaffShellRoot($html, 'G8 control');
        self::assertStringContainsString('data-role="workspace-section"', $html, 'G8 control: Visit Workspace mounted in the canonical Staff Portal');
        $ui = $this->moduleUi();

        // ---- GREEN guards: merged workspace wiring kept, no routine reload/navigation ----
        foreach (self::MERGED_WORKSPACE_MARKERS as $keep) {
            self::assertStringContainsString($keep, $ui, 'G8 guard: merged Visit Workspace wiring preserved (' . $keep . ')');
        }
        foreach ([
            '/reopen',
            'workspace-reopen',
            'visit-reopen',
            'consult-reopen',
            'workspace-complete',
            'visit-complete',
            'data-action="complete"',
            'location.reload(',
            'location.assign(',
            'location.replace(',
            'location.href =',
            'location.href=',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $ui, 'G8 guard: forbidden in the module UI (' . $forbidden . ')');
        }

        // ---- INTENDED PRODUCT RED: hybrid Medical Files list/upload/open wiring missing ----
        $missing = [];
        foreach (array_merge(self::LIST_MARKERS, self::UPLOAD_MARKERS, [self::OPEN_MARKER]) as $marker) {
            if (!str_contains($ui, 'data-role="' . $marker . '"')) {
                $missing[] = 'file marker (' . $marker . ')';
            }
            if (!str_contains($html, 'data-role="' . $marker . '"')) {
                $missing[] = 'file marker rendered in the canonical Staff Portal (' . $marker . ')';
            }
        }
        if (!str_contains($ui, 'data.files')) {
            $missing[] = 'list rendered from the record payload (data.files)';
        }
        if (!str_contains($ui, '/doctor/portal/visits/') || !str_contains($ui, '/files')) {
            $missing[] = 'REST wiring to POST /clinic/v1/doctor/portal/visits/{id}/files';
        }
        if (!str_contains($ui, 'new FormData')) {
            $missing[] = 'FormData/multipart REST upload (no form POST navigation)';
        }
        if (!str_contains($ui, '/stream')) {
            $missing[] = 'REST wiring to GET /clinic/v1/doctor/portal/visits/{id}/files/{file_id}/stream';
        }
        foreach (['createObjectURL', 'revokeObjectURL'] as $token) {
            if (!str_contains($ui, $token)) {
                $missing[] = 'authorized blob open/download (' . $token . ' — established Patient Portal pattern)';
            }
        }
        if (!str_contains($ui, '.blob(') && !str_contains($ui, 'blob()')) {
            $missing[] = 'authorized fetch -> blob download path';
        }
        if (preg_match('/<form\b[^>]*data-role="workspace-visit-files-upload-form"[^>]*>/s', $ui, $formTag) === 1
            && preg_match('/(?<!data-)action\s*=/', $formTag[0]) === 1) {
            $missing[] = 'upload form must stay REST/FormData (no form action navigation)';
        }
        $posSubmit = strpos($ui, 'workspace-visit-files-upload-submit');
        if ($posSubmit !== false) {
            $window = substr($ui, max(0, $posSubmit - 3000), 9000);
            if (!str_contains($window, 'disabled')) {
                $missing[] = 'upload double-submit guard (control disabled while busy)';
            }
        }
        if ($missing !== []) {
            $this->annotate('red-G8', 'G8: Staff Portal hybrid Medical Files UI wiring missing', implode('; ', $missing));
        }
        self::assertSame([], $missing, 'G8: Staff Portal hybrid Medical Files UI wiring missing: ' . implode('; ', $missing));
    }

    /**
     * Group 8 (browser half) — the future GREEN browser journey must extend the
     * EXISTING bin/pilot-doctor-portal.py driven by the pilot gate (canonical
     * Staff Portal, 390/768/1366, zero product reload, real file upload, real
     * authorized blob download with revocation, failed upload != success, no
     * public file URL).
     */
    public function testGroup8b_FilesBrowserJourneyStaysInExistingPilot(): void
    {
        $root = dirname(__DIR__, 2);
        $pilotPath = $root . '/bin/pilot-doctor-portal.py';
        $pilotGatePath = $root . '/../.github/workflows/pilot-gate.yml';
        self::assertFileExists($pilotPath, 'G8b: existing Doctor Portal pilot harness exists');
        $pilot = (string) file_get_contents($pilotPath);

        foreach (self::PILOT_GUARD_TOKENS as $keep) {
            self::assertStringContainsString($keep, $pilot, 'G8b guard: established pilot proof retained (' . $keep . ')');
        }
        if (is_file($pilotGatePath)) {
            self::assertStringContainsString('pilot-doctor-portal.py', (string) file_get_contents($pilotGatePath),
                'G8b guard: the pilot gate keeps driving the existing pilot');
        }
        self::assertStringNotContainsString('/reopen', $pilot, 'G8b guard: no Reopen journey in the Staff Portal pilot');

        $pilotMissing = [];
        foreach (self::PILOT_RED_TOKENS as $token) {
            if (!str_contains($pilot, $token)) {
                $pilotMissing[] = 'pilot journey token (' . $token . ')';
            }
        }
        if ($pilotMissing !== []) {
            $this->annotate('red-G8b', 'G8b: Medical Files browser journey missing from the existing pilot', implode('; ', $pilotMissing));
        }
        self::assertSame([], $pilotMissing,
            'G8b: the future GREEN browser journey for Medical Files must live in the existing bin/pilot-doctor-portal.py: '
            . implode('; ', $pilotMissing));
    }

    // ================= helpers =================

    /**
     * @return array{clinic: int, location: int, clinician: int, doctor: int}
     */
    private function makePortalStage(string $tag): array
    {
        $org = $this->insertOrg('Files Stage ' . $tag);
        $clinic = $this->insertClinicInOrg('Files Clinic ' . $tag, $org, self::TZ_TEHRAN);
        $loc = $this->insertLocation($clinic, 'Files Loc ' . $tag, self::TZ_TEHRAN, 1);
        $doctor = $this->makeUser('mf_' . $tag . '_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $clinician = $this->insertClinician('Dr MF ' . $tag, $clinic, 1, $doctor);
        cpms_test_seed_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        return ['clinic' => $clinic, 'location' => $loc, 'clinician' => $clinician, 'doctor' => $doctor];
    }

    /**
     * @return array<string, string>
     */
    private function scopeHeaders(int $clinic, ?int $location = null): array
    {
        $headers = ['X-CPMS-Clinic-Id' => (string) $clinic];
        if ($location !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }
        return $headers;
    }

    private function moduleUi(): string
    {
        $root = dirname(__DIR__, 2);
        $templatePath = $root . '/templates/doctor-portal-shell.php';
        $jsPath = $root . '/assets/js/cpms-doctor-portal.js';
        self::assertFileExists($templatePath, 'doctor module template exists');
        self::assertFileExists($jsPath, 'doctor module JS exists');
        return (string) file_get_contents($templatePath) . "\n" . (string) file_get_contents($jsPath);
    }

    /**
     * The intended upload boundary must be REACHED: a missing portal route
     * answers 404 rest_no_route, which is the intended product RED, never a
     * fixture error.
     */
    private function assertReachedPortalFilesUpload(WP_REST_Response $r, string $label): void
    {
        if ($this->errCode($r) === 'rest_no_route') {
            $this->annotate('red-route', $label . ': portal Medical Files upload boundary missing', 'POST /clinic/v1/doctor/portal/visits/{id}/files => ' . $r->get_status() . ' rest_no_route');
        }
        self::assertNotSame('rest_no_route', $this->errCode($r),
            $label . ': portal Medical Files upload boundary missing — POST /clinic/v1/doctor/portal/visits/{id}/files returned '
            . $r->get_status() . ' rest_no_route');
    }

    private function assertReachedPortalFilesStream(WP_REST_Response $r, string $label): void
    {
        if ($this->errCode($r) === 'rest_no_route') {
            $this->annotate('red-route', $label . ': portal Medical Files stream boundary missing', 'GET /clinic/v1/doctor/portal/visits/{id}/files/{file_id}/stream => ' . $r->get_status() . ' rest_no_route');
        }
        self::assertNotSame('rest_no_route', $this->errCode($r),
            $label . ': portal Medical Files stream boundary missing — GET /clinic/v1/doctor/portal/visits/{id}/files/{file_id}/stream returned '
            . $r->get_status() . ' rest_no_route');
    }

    private function gate(int $wanted, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if ($got !== $wanted) {
            $this->annotate('status', $message, 'expected=' . $wanted . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertSame($wanted, $got, $message . ' (got ' . $got . ' ' . $this->errCode($r) . ')');
    }

    /**
     * @param list<int> $wantedSet
     */
    private function gateIn(array $wantedSet, WP_REST_Response $r, string $message): void
    {
        $got = $r->get_status();
        if (!in_array($got, $wantedSet, true)) {
            $this->annotate('status-set', $message, 'expected=' . implode('|', $wantedSet) . ' got=' . $got . ' code=' . $this->errCode($r));
        }
        self::assertContains($got, $wantedSet, $message . ' (got ' . $got . ' ' . $this->errCode($r) . ')');
    }

    private function annotate(string $kind, string $message, string $detail): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $message . ' :: ' . $detail));
        $esc = str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', ' -', ';'], $text);
        $tokens = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '', strstr($message, ':', true) ?: substr($message, 0, 12)));
        fwrite(STDERR, '::error title=CPMS-Phase10MedicalFiles-' . $kind . '-' . $tokens . '::' . $esc . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     * @param string                $nonce  'valid' | 'none' | 'invalid'
     * @param array<string, mixed>|null $file $_FILES-shaped upload fixture
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = [], string $nonce = 'valid', ?array $file = null): WP_REST_Response
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        if ($file !== null) {
            $r->set_file_params(['file' => $file]);
        }
        if ($nonce === 'valid') {
            $r->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        } elseif ($nonce === 'invalid') {
            $r->set_header('X-WP-Nonce', 'invalid-nonce-0000');
        }
        foreach ($headers as $k => $v) {
            $r->set_header($k, $v);
        }
        return rest_do_request($r);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return is_array($b) ? $b : [];
    }

    private function errCode(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_code();
        }
        return (string) (is_array($b) ? ($b['code'] ?? '') : '');
    }

    private function errMessage(WP_REST_Response $res): string
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            return (string) $b->get_error_message();
        }
        return (string) (is_array($b) ? ($b['message'] ?? '') : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawErrorData(WP_REST_Response $res): array
    {
        $b = $res->get_data();
        if ($b instanceof \WP_Error) {
            $d = $b->get_error_data();
            return is_array($d) ? $d : [];
        }
        if (is_array($b) && isset($b['data']) && is_array($b['data'])) {
            return $b['data'];
        }
        return is_array($b) ? $b : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_map('strval', array_keys($payload));
        sort($keys);
        return $keys;
    }

    private function routeRegistered(string $route): bool
    {
        return array_key_exists($route, rest_get_server()->get_routes());
    }

    /**
     * @return array<string, mixed>
     */
    private function fileRow(int $fileId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE id = %d', [$fileId]);
        self::assertIsArray($row, 'fixture: attachment row readable');
        return $row;
    }

    private function fileCountForPatient(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE patient_id = %d AND deleted_at IS NULL',
            [$patientId]
        );
    }

    private function storageFileCount(): int
    {
        if (!is_dir($this->storagePath)) {
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    private function auditCount(string $action, string $resourceType, int $resourceId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE resource_type = %s AND resource_id = %d AND action = %s',
            [$resourceType, $resourceId, $action]
        );
    }

    private function auditCountForAction(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
    }

    private function columnType(string $table, string $column): string
    {
        $row = App::db()->fetchRow(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        );
        self::assertIsArray($row, 'schema column readable: ' . $table . '.' . $column);
        return (string) ($row['COLUMN_TYPE'] ?? $row['column_type'] ?? '');
    }

    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n";
    }

    private function tempFile(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cpms_up_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);
        return $tmp;
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int, type: string}
     */
    private function makeUploadedFile(string $name, string $content): array
    {
        $tmp = $this->tempFile($content);
        return [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => 0,
            'type' => 'application/octet-stream', // deliberately ignored — finfo is authoritative
        ];
    }

    /**
     * Material file fixture through the REAL established service (store + row +
     * audit) with a real physical object — never a missing-file trick.
     *
     * @return array{id: int, row: array<string, mixed>, abs: string, content: string}
     */
    private function seedFile(int $actor, int $patientId, ?int $visitId, string $category, string $visibility, string $filename, string $content): array
    {
        $presented = App::medicalFileService()->upload(
            $actor,
            $this->makeUploadedFile($filename, $content),
            $patientId,
            $visitId,
            $category,
            $visibility
        );
        $id = (int) ($presented['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'fixture: file row must persist through the established service');
        $row = $this->fileRow($id);
        self::assertSame($patientId, (int) $row['patient_id'], 'fixture: file bound to the claimed patient');
        self::assertSame($visitId === null ? null : $visitId, $row['visit_id'] === null ? null : (int) $row['visit_id'], 'fixture: file Visit association persisted');
        self::assertSame($visibility, (string) $row['visibility'], 'fixture: visibility persisted');
        self::assertSame($category, (string) $row['category'], 'fixture: category persisted');
        $abs = $this->storagePath . '/' . (string) $row['storage_path'];
        self::assertFileExists($abs, 'fixture: stored object must exist on disk');
        self::assertSame($content, (string) file_get_contents($abs), 'fixture: stored object bytes must match');
        return ['id' => $id, 'row' => $row, 'abs' => $abs, 'content' => $content];
    }

    private function makeUser(string $login, string $role): int
    {
        $u = $login . '_' . bin2hex(random_bytes(3));
        $created = wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertFalse(is_wp_error($created), 'fixture: WP user creation must not fail (' . $login . ')');
        $id = (int) $created;
        self::assertGreaterThan(0, $id, 'fixture: WP user persisted');
        $user = get_userdata($id);
        self::assertNotFalse($user, 'fixture: WP user readable');
        $user->set_role($role);
        return $id;
    }

    private function insertOrg(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)', $name, 'org-' . bin2hex(random_bytes(3)), 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'organization fixture row must persist');
        return $id;
    }

    private function insertClinicInOrg(string $name, int $orgId, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', $orgId, $name, 'cl-' . bin2hex(random_bytes(3)), $tz, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinic fixture row must persist');
        // Per-Clinic protected test storage + live size ceiling (current convention).
        App::settingsFactory()->forClinic($id)->set('files.storage_path', $this->storagePath);
        App::settingsFactory()->forClinic($id)->set('files.max_upload_bytes', 10485760);
        Settings::flushCache();
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $tz, int $primary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', $clinicId, $name, 'loc-' . bin2hex(random_bytes(3)), $tz, $primary, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'location fixture row must persist');
        return $id;
    }

    private function insertClinician(string $name, int $clinicId, int $active, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)', $clinicId, $name, $wpUserId, $active, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'clinician fixture row must persist (wp_user_id must be distinct)');
        return $id;
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-MF-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2));
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)', $clinicId, $mrn, 'Test', 'Patient ' . substr($mrn, -4), $mobile, 'active', $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient fixture row must persist');
        return $id;
    }

    private function insertPatientLink(int $clinicId, int $patientId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mobile = '0912' . sprintf('%07d', random_int(1000000, 9999999));
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at) VALUES (%d, %d, %d, %s, 1, %s)', $clinicId, $patientId, $wpUserId, $mobile, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'patient-user link fixture row must persist');
        return $id;
    }

    private function insertVisit(int $patientId, int $clinicianId, int $clinicId, int $locId, string $status): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s)', $clinicId, $locId, $clinicianId, $patientId, 'walk_in', $status, self::FIXED_UTC_DATE, self::FIXED_UTC_DATE . ' 10:00:00', self::FIXED_UTC_DATE . ' 10:00:00', 1, $now, $now));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'visit fixture row must persist (clinician/location/patient FKs must be valid)');
        return $id;
    }

    private function assertClinicianBinding(int $clinicianId, int $clinicId, int $wpUserId): void
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, wp_user_id, is_active FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d',
            [$clinicianId]
        );
        self::assertIsArray($row, 'fixture: clinician row really persisted');
        self::assertSame($clinicId, (int) $row['clinic_id'], 'fixture: clinician bound to the claimed Clinic');
        self::assertSame($wpUserId, (int) $row['wp_user_id'], 'fixture: clinician bound to the claimed WP user');
        self::assertSame(1, (int) $row['is_active'], 'fixture: clinician is active');
    }

    private function assertVisitBinding(int $visitId, int $clinicId, int $locationId, int $clinicianId, int $patientId): void
    {
        $row = App::db()->fetchRow(
            'SELECT id, clinic_id, location_id, clinician_id, patient_id FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d',
            [$visitId]
        );
        self::assertIsArray($row, 'fixture: visit row really persisted');
        self::assertSame($clinicId, (int) $row['clinic_id'], 'fixture: visit bound to the claimed Clinic');
        self::assertSame($locationId, (int) $row['location_id'], 'fixture: visit bound to the claimed Location');
        self::assertSame($clinicianId, (int) $row['clinician_id'], 'fixture: visit bound to the claimed clinician');
        self::assertSame($patientId, (int) $row['patient_id'], 'fixture: visit bound to the claimed patient');
    }

    /**
     * @return array<string, mixed>
     */
    private function locationRow(int $locationId): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d', [$locationId]);
        self::assertIsArray($row, 'location row readable');
        return $row;
    }

    private function requestFor(string $url): string
    {
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?? '');
        $req = $path . ('' !== $query ? '?' . $query : '');
        return '' === $req ? '/' : $req;
    }

    private function renderCanonicalAs(int $userId, string $url): string
    {
        wp_set_current_user($userId);
        $this->go_to($this->requestFor($url));
        $baseline = get_stylesheet_directory() . '/page.php';
        if (!is_readable($baseline)) {
            $baseline = get_stylesheet_directory() . '/index.php';
        }
        if (!is_readable($baseline)) {
            $baseline = '/active-theme/page.php';
        }
        $template = (string) apply_filters('template_include', $baseline);
        self::assertNotSame($baseline, $template, 'G8 control: template_include intercepts the canonical Staff Portal page');
        self::assertFileExists($template, 'G8 control: plugin-owned Staff Portal template exists');
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }

    private function assertStaffShellRoot(string $html, string $label): void
    {
        $found = false;
        foreach (self::STAFF_SHELL_ROOT_PATTERNS as $token) {
            if (str_contains($html, $token)) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, $label . ': canonical document carries the shared Staff Portal shell root marker');
    }
}

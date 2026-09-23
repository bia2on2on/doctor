<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * TEST-ONLY RED: My Files (list + protected download + patient upload) on the
 * EXISTING C3/C4/E17 backend and the approved CPMS Patient Portal shell.
 *
 * Bounded contract under test (no new route, no migration, no parallel storage):
 *  - C3/C4 gain an OPTIONAL integer `link_id` patient-record selector reusing
 *    PatientService::require_selected_patient semantics: 0 => canonical 404,
 *    1 => auto-resolve, N>1 without selector => 422 CLINIC_SELECTION_REQUIRED,
 *    foreign/inactive/nonexistent selector => canonical non-enumerating 404,
 *    NEVER a primary/first fallback. The path `patient_id` is selector/object
 *    identity only and is validated against server-derived ownership.
 *  - E17 stream authority for patients is membership-based: a patient-visible,
 *    non-deleted file belonging to ANY active Patient record durably linked to
 *    the authenticated WP user is downloadable without a client selector; a
 *    client-supplied selector is never authority. Foreign/unlinked/inactive/
 *    private/deleted files remain denied non-enumerably, before storage read.
 *  - Patient upload stays on existing C3 controls: nonce, owned selected
 *    record, patient_visible, MIME sniffing, size limit, rate limit, protected
 *    randomized storage, no public URL, no delete/edit/replace.
 *  - Jalali display is fail-closed: a paired Jalali date exists ONLY when a
 *    trusted Location context exists (file.visit_id -> Visit -> Location ->
 *    IANA timezone). Visit-less patient uploads carry NO Jalali date and the
 *    UI omits the date instead of guessing. Raw created_at stays unchanged.
 */
final class Phase9Slice7MyFilesRedTest extends WP_UnitTestCase
{
    private int $user;
    private int $foreign;
    private int $empty;
    private array $a;
    private array $b;
    private array $other;
    private string $tag;
    private string $now;
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        $this->tag = bin2hex(random_bytes(5));
        $this->now = App::db()->nowUtcSql();
        $this->user = $this->user('caller');
        $this->foreign = $this->user('foreign');
        $this->empty = $this->user('empty');

        // Protected test storage (outside any webroot), cleaned in tearDown.
        $this->storagePath = sys_get_temp_dir() . '/cpms-p9s7-files-' . $this->tag;

        $org = $this->insert('cpms_organizations', [
            'name' => 'FilesOrg ' . $this->tag, 'slug' => 'files-org-' . $this->tag,
            'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $clinics = [];
        foreach (['Alpha', 'Beta'] as $name) {
            $clinic = $this->insert('cpms_clinics', [
                'organization_id' => $org, 'name' => $name . $this->tag,
                'slug' => strtolower($name) . $this->tag, 'timezone' => 'Asia/Tehran',
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            // Storage + size ceiling for the owning Clinic of every operation.
            App::settingsFactory()->forClinic($clinic)->set('files.storage_path', $this->storagePath);
            App::settingsFactory()->forClinic($clinic)->set('files.max_upload_bytes', 10485760);
            $clinics[] = $clinic;
        }
        self::assertNotSame($clinics[0], $clinics[1]);
        self::assertGreaterThan(1, $clinics[0]);
        Settings::flushCache();

        $this->a = $this->record($clinics[0], $this->user, 'A', 1);
        $this->b = $this->record($clinics[1], $this->user, 'B', 0);
        $this->other = $this->record($clinics[1], $this->foreign, 'Foreign', 1);

        // Material controls before ANY intended RED: persisted topology and the
        // already-GREEN Profile contract prove the two eligible links really exist,
        // and the material file fixtures really exist on disk and in the DB.
        $records = $this->get('/patient/my-records')->get_data()['data'];
        self::assertSame([$this->a['link'], $this->b['link']], array_column($records, 'link_id'));
        $selected = $this->get('/patient/me', ['link_id' => $this->b['link']]);
        self::assertSame(200, $selected->get_status());
        self::assertSame($this->b['patient'], $selected->get_data()['data']['id']);
        foreach ([$this->a, $this->b, $this->other] as $record) {
            $absolute = $this->storagePath . '/' . $record['file']['storage_path'];
            self::assertFileExists($absolute, 'Material fixture file must exist on disk.');
            self::assertSame($record['file']['content'], (string) file_get_contents($absolute));
        }
        self::assertSame(4, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE patient_id = %d',
            [$this->a['patient']]
        ), 'Material fixture: visible + upload + private + deleted rows for record A.');
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
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

    // ================= C4 — multi-record list selection =================

    public function testMultipleRecordsRequireSelectionForListEvenWithForgedAuthority(): void
    {
        $response = $this->get('/patients/' . $this->a['patient'] . '/files', [
            'clinic_id' => $this->a['clinic'], 'patient_id' => $this->a['patient'],
            'organization_id' => 1, 'role' => 'cpms_manager',
        ]);
        $this->error($response, 422, 'CLINIC_SELECTION_REQUIRED');
    }

    public function testSelectedLinkListsOnlyThatRecordFiles(): void
    {
        $response = $this->get('/patients/' . $this->b['patient'] . '/files', [
            'link_id' => $this->b['link'],
            'clinic_id' => $this->a['clinic'], 'patient_id' => $this->a['patient'],
        ]);
        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $names = array_column($response->get_data()['data']['files'], 'original_filename');
        self::assertContains(
            'SYN-FILES-B.pdf',
            $names,
            'link_id must select record B files, never primary A or another user\'s record.'
        );
        self::assertContains('SYN-FILES-B-UP.pdf', $names);
        self::assertNotContains('SYN-FILES-A.pdf', $names);
        self::assertNotContains('SYN-FILES-FOREIGN.pdf', $names);
        self::assertNotContains('SYN-PRIVATE-B.pdf', $names);
        self::assertNotContains('SYN-DELETED-B.pdf', $names);
    }

    public function testPathPatientIdIsVerifiedAgainstServerDerivedSelection(): void
    {
        // Selector says B but the path identity claims A (or a foreign record):
        // the path patient_id is never authority by itself and must not mix records.
        $mismatch = $this->get('/patients/' . $this->a['patient'] . '/files', ['link_id' => $this->b['link']]);
        $this->error($mismatch, 404, 'CLINIC_NOT_FOUND');
        $foreign = $this->get('/patients/' . $this->other['patient'] . '/files', ['link_id' => $this->b['link']]);
        $this->error($foreign, 404, 'CLINIC_NOT_FOUND');

        $fingerprint = null;
        foreach ([$mismatch, $foreign] as $response) {
            $error = $response->get_data();
            $candidate = [$error['code'], $error['message'], $error['data']['status']];
            $fingerprint ??= $candidate;
            self::assertSame($fingerprint, $candidate, 'Selector/path mismatch must stay non-enumerating.');
        }
    }

    public function testInvalidSelectorsAreNonEnumeratingAndNeverFallBack(): void
    {
        $inactive = $this->record($this->b['clinic'], $this->user, 'Archived', 0, 'archived');
        $mismatch = $this->record($this->b['clinic'], $this->user, 'Mismatch', 0);
        global $wpdb;
        self::assertSame(1, $wpdb->update(
            $wpdb->prefix . 'cpms_patient_user_links',
            ['clinic_id' => $this->a['clinic']],
            ['id' => $mismatch['link']]
        ));

        $before = $this->counts();
        $canonical = null;
        foreach ([$this->other['link'], $inactive['link'], $mismatch['link'], 2147483647] as $link) {
            $response = $this->get('/patients/' . $this->b['patient'] . '/files', ['link_id' => $link]);
            $this->error($response, 404, 'CLINIC_NOT_FOUND');
            $error = $response->get_data();
            $fingerprint = [$error['code'], $error['message'], $error['data']['status']];
            $canonical ??= $fingerprint;
            self::assertSame($canonical, $fingerprint, 'Foreign/inactive/mismatched/missing selectors must be indistinguishable.');
        }
        self::assertSame($before, $this->counts(), 'Denied list reads must not write anything.');
    }

    public function testZeroAndSingleRecordControlsKeepExistingBoundsAndAuthentication(): void
    {
        $before = $this->counts();
        $this->error($this->get('/patients/1/files', [], $this->empty), 404, 'CLINIC_NOT_FOUND');
        $this->error($this->get('/patients/' . $this->other['patient'] . '/files', [], $this->foreign, false), 403, 'CLINIC_INVALID_NONCE');
        $this->error($this->get('/patients/' . $this->other['patient'] . '/files', [], 0, false), 403, 'CLINIC_INVALID_NONCE');
        self::assertSame($before, $this->counts(), 'Read/denial must not create Patients, links, or files.');

        // Foreign user owns exactly one Beta record. Auto-resolution without link_id:
        $response = $this->get('/patients/' . $this->other['patient'] . '/files', [], $this->foreign);
        self::assertSame(200, $response->get_status());
        $names = array_column($response->get_data()['data']['files'], 'original_filename');
        self::assertContains('SYN-FILES-FOREIGN.pdf', $names);
        self::assertContains('SYN-FILES-FOREIGN-UP.pdf', $names);
        self::assertNotContains('SYN-PRIVATE-FOREIGN.pdf', $names);
        self::assertNotContains('SYN-DELETED-FOREIGN.pdf', $names);
    }

    public function testListUsesExistingPatientVisibleQueriesAndSafePresentation(): void
    {
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string {
            $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $response = $this->get('/patients/' . $this->other['patient'] . '/files', [], $this->foreign);
        } finally {
            remove_filter('query', $capture);
        }
        self::assertSame(200, $response->get_status());

        $fileQueries = array_filter(
            $queries,
            static fn (string $q): bool => stripos($q, 'SELECT') === 0 && str_contains($q, 'cpms_medical_attachments')
        );
        self::assertNotEmpty($fileQueries, 'Must reach the real medical file repository, not a mock.');
        foreach ($fileQueries as $sql) {
            self::assertMatchesRegularExpression('/deleted_at IS NULL/', $sql, 'Soft delete must remain a query-level predicate.');
            self::assertMatchesRegularExpression('/visibility\s*=\s*\'patient_visible\'/', $sql, 'Patient visibility must remain a query-level predicate.');
        }

        $encoded = (string) wp_json_encode($response->get_data());
        self::assertStringNotContainsString('storage_path', $encoded);
        self::assertStringNotContainsString('stored_filename', $encoded);
        self::assertStringNotContainsString($this->storagePath, $encoded, 'No filesystem detail in patient responses.');
        foreach ($response->get_data()['data']['files'] as $file) {
            self::assertSame($this->now, $file['created_at'], 'Raw created_at API semantics stay unchanged.');
            self::assertArrayHasKey('original_filename', $file);
            self::assertArrayHasKey('mime_type', $file);
            self::assertArrayHasKey('file_size', $file);
            self::assertArrayHasKey('category', $file);
            self::assertArrayNotHasKey('storage_path', $file);
            self::assertArrayNotHasKey('stored_filename', $file);
        }
    }

    // ================= C3 — patient upload selection =================

    public function testUploadRequiresExplicitSelectionForMultiRecord(): void
    {
        $before = $this->counts();
        $filesBefore = $this->storageFileCount();
        $response = $this->upload($this->a['patient'], ['category' => 'document'], 'multi-noselect.pdf', $this->pdfContent('multi-noselect'));
        $this->error($response, 422, 'CLINIC_SELECTION_REQUIRED');
        self::assertSame($before, $this->counts(), 'N>1 upload without explicit selection must not persist anything.');
        self::assertSame($filesBefore, $this->storageFileCount(), 'N>1 upload without explicit selection must not write to storage.');
    }

    public function testUploadWithSelectedLinkStoresForThatRecordOnly(): void
    {
        $content = $this->pdfContent('selected-b');
        $response = $this->upload(
            $this->b['patient'],
            ['category' => 'document', 'link_id' => $this->b['link']],
            'SYN-SELECTED-B.pdf',
            $content
        );
        self::assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data()['data'];
        self::assertSame('patient_visible', $data['visibility'], 'Patient uploads remain patient_visible.');
        self::assertSame('SYN-SELECTED-B.pdf', $data['original_filename']);

        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE id = %d',
            [(int) $data['id']]
        );
        self::assertNotNull($row);
        self::assertSame($this->b['patient'], (int) $row['patient_id'], 'Upload must land on the explicitly selected record.');
        self::assertSame($this->b['clinic'], (int) $row['clinic_id'], 'Selected-record Clinic isolation.');
        self::assertNull($row['visit_id'], 'Patient uploads carry no visit context.');
        self::assertSame('patient_visible', (string) $row['visibility']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $row['stored_filename'], 'Randomized stored filename.');
        self::assertStringNotContainsString('uploads', (string) $row['storage_path'], 'Protected storage stays outside uploads.');
        self::assertFileExists($this->storagePath . '/' . $row['storage_path']);
        self::assertSame($content, (string) file_get_contents($this->storagePath . '/' . $row['storage_path']));

        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['FILE_UPLOADED', 'file', (int) $data['id']]
        );
        self::assertNotNull($audit, 'Upload audit trail preserved.');
    }

    public function testUploadMismatchedPathAndSelectorIsDeniedNonEnumerably(): void
    {
        $before = $this->counts();
        $filesBefore = $this->storageFileCount();
        // Path claims record A while the selector points at B: never mix.
        $response = $this->upload(
            $this->a['patient'],
            ['category' => 'document', 'link_id' => $this->b['link']],
            'SYN-MISMATCH.pdf',
            $this->pdfContent('mismatch')
        );
        $this->error($response, 404, 'CLINIC_NOT_FOUND');
        self::assertSame($before, $this->counts(), 'Mismatched selector/path upload must not persist anything.');
        self::assertSame($filesBefore, $this->storageFileCount(), 'Mismatched selector/path upload must not write to storage.');
    }

    public function testUploadForgedAuthorityNeverChangesSelection(): void
    {
        // Forged client authority keys accompany a valid B selector: the upload
        // must still resolve through the durable link only (and land on B or be
        // denied) — it must NEVER silently fall back to primary record A.
        $response = $this->upload(
            $this->b['patient'],
            [
                'category' => 'document', 'link_id' => $this->b['link'],
                'clinic_id' => $this->a['clinic'], 'organization_id' => 1, 'role' => 'cpms_manager',
            ],
            'SYN-FORGED.pdf',
            $this->pdfContent('forged')
        );
        if ($response->get_status() === 201) {
            $row = App::db()->fetchRow(
                'SELECT patient_id, clinic_id FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE id = %d',
                [(int) $response->get_data()['data']['id']]
            );
            self::assertSame($this->b['patient'], (int) $row['patient_id'], 'Forged authority must not retarget the upload.');
            self::assertSame($this->b['clinic'], (int) $row['clinic_id']);
        } else {
            $this->error($response, 404, 'CLINIC_NOT_FOUND');
        }
        $countA = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE patient_id = %d AND original_filename = %s',
            [$this->a['patient'], 'SYN-FORGED.pdf']
        );
        self::assertSame(0, $countA, 'No primary/first fallback for forged-authority uploads.');
    }

    public function testUploadPreservesExistingControlsOnSoleRecord(): void
    {
        // Sole-record auto-resolution: the established C3 controls stay intact.
        $ok = $this->upload($this->other['patient'], ['category' => 'document'], 'sole-ok.pdf', $this->pdfContent('sole-ok'), $this->foreign);
        self::assertSame(201, $ok->get_status(), (string) wp_json_encode($ok->get_data()));
        self::assertSame('patient_visible', $ok->get_data()['data']['visibility']);

        // PHP disguised as JPG — real MIME sniffing rejects without persisting.
        $before = $this->counts();
        $evil = $this->upload($this->other['patient'], ['category' => 'image'], 'shell.jpg', '<?php system($_GET["c"]); ?>', $this->foreign);
        $this->error($evil, 400, 'CLINIC_FILE_INVALID');
        self::assertSame($before, $this->counts(), 'Invalid MIME upload must not persist.');

        // Extension/MIME mismatch — real PDF content declared as .jpg.
        $mismatch = $this->upload($this->other['patient'], ['category' => 'document'], 'document.jpg', $this->pdfContent('ext-mismatch'), $this->foreign);
        $this->error($mismatch, 400, 'CLINIC_FILE_INVALID');

        // Category whitelist.
        $badCategory = $this->upload($this->other['patient'], ['category' => 'bogus'], 'cat.pdf', $this->pdfContent('cat'), $this->foreign);
        $this->error($badCategory, 422, 'CLINIC_VALIDATION_FAILED');

        // Per-Clinic size ceiling remains enforced.
        App::settingsFactory()->forClinic($this->other['clinic'])->set('files.max_upload_bytes', 1024);
        Settings::flushCache();
        $tooBig = $this->upload($this->other['patient'], ['category' => 'document'], 'big.pdf', $this->pdfContent('big') . str_repeat('x', 4096), $this->foreign);
        $this->error($tooBig, 400, 'CLINIC_FILE_INVALID');
        App::settingsFactory()->forClinic($this->other['clinic'])->set('files.max_upload_bytes', 10485760);
        Settings::flushCache();
    }

    public function testUploadRateLimitRemainsEnforced(): void
    {
        $rateUser = $this->user('rate');
        $record = $this->record($this->a['clinic'], $rateUser, 'Rate', 1);
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->upload($record['patient'], ['category' => 'document'], 'rate-' . $i . '.pdf', $this->pdfContent('rate-' . $i), $rateUser);
            self::assertSame(201, $response->get_status(), 'Upload ' . $i . ' within the existing 10/hour budget.');
        }
        $limited = $this->upload($record['patient'], ['category' => 'document'], 'rate-11.pdf', $this->pdfContent('rate-11'), $rateUser);
        $this->error($limited, 429, 'CLINIC_RATE_LIMITED');
    }

    // ================= E17 — protected stream/download authority =================

    public function testPatientStreamsFileFromAnyLinkedActiveRecordWithoutClientSelector(): void
    {
        // Primary record A — established behavior:
        $primary = $this->stream($this->a['file']['id']);
        self::assertSame(200, $primary->get_status(), (string) wp_json_encode($primary->get_data()));
        self::assertSame($this->a['file']['content'], $primary->get_data());

        // Non-primary record B — membership authority, no selector, no fallback:
        $secondary = $this->stream($this->b['file']['id']);
        self::assertSame(
            200,
            $secondary->get_status(),
            'A file belonging to ANY legitimately linked active Patient record must be downloadable without primary/first fallback. Got: '
                . (string) wp_json_encode($secondary->get_data())
        );
        self::assertSame($this->b['file']['content'], $secondary->get_data());

        // Visit-less patient upload of record B is equally downloadable:
        $uploaded = $this->stream($this->b['upfile']['id']);
        self::assertSame(200, $uploaded->get_status(), (string) wp_json_encode($uploaded->get_data()));
        self::assertSame($this->b['upfile']['content'], $uploaded->get_data());
    }

    public function testStreamClientSelectorIsNeverAuthorityMembershipDecides(): void
    {
        // A foreign file stays denied even when accompanied by a valid owned selector:
        $denied = $this->stream($this->other['file']['id'], ['link_id' => $this->b['link']]);
        $this->error($denied, 404, 'CLINIC_NOT_FOUND');

        // An owned-record file stays allowed regardless of irrelevant selectors:
        foreach ([['link_id' => $this->a['link']], ['link_id' => 2147483647], []] as $params) {
            $response = $this->stream($this->b['file']['id'], $params);
            self::assertSame(
                200,
                $response->get_status(),
                'Stream authority comes from durable membership of the file\'s Patient record, never from a client selector. Params: '
                    . (string) wp_json_encode($params) . ' Body: ' . (string) wp_json_encode($response->get_data())
            );
            self::assertSame($this->b['file']['content'], $response->get_data());
        }
    }

    public function testStreamDenialsRemainNonEnumeratingBeforeStorage(): void
    {
        $inactive = $this->record($this->b['clinic'], $this->user, 'Archived2', 0, 'archived');

        $canonical = null;
        $targets = [
            'foreign record' => $this->other['file']['id'],
            'inactive linked record' => $inactive['file']['id'],
            'doctor_private own record' => $this->a['priv']['id'],
            'soft-deleted own record' => $this->a['del']['id'],
            'nonexistent file' => 2147483647,
        ];
        foreach ($targets as $label => $fileId) {
            $response = $this->stream($fileId);
            $this->error($response, 404, 'CLINIC_NOT_FOUND');
            $error = $response->get_data();
            $fingerprint = [$error['code'], $error['message'], $error['data']['status']];
            $canonical ??= $fingerprint;
            self::assertSame($canonical, $fingerprint, 'Denial fingerprint must be identical: ' . $label);
            $encoded = (string) wp_json_encode($error);
            self::assertStringNotContainsString($this->storagePath, $encoded, 'No filesystem detail in denials: ' . $label);
            self::assertStringNotContainsString('storage_path', $encoded);
            self::assertIsArray($error, 'Denied stream returns the safe envelope, never file bytes: ' . $label);
        }

        // The denied bytes are never served: physical content stays unreferenced.
        self::assertNotSame($this->other['file']['content'], $this->stream($this->other['file']['id'])->get_data());
    }

    public function testStreamHeadersAttachmentAndJailAndMissingPhysicalFile(): void
    {
        // Authorized patient stream keeps the protected-delivery contract:
        $response = $this->stream($this->other['file']['id'], [], $this->foreign);
        self::assertSame(200, $response->get_status());
        self::assertSame('application/pdf', $response->headers['Content-Type'] ?? null);
        self::assertStringStartsWith('attachment; filename=', (string) ($response->headers['Content-Disposition'] ?? ''), 'Content-Disposition remains attachment.');
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
        self::assertSame('private, max-age=0, no-cache', $response->headers['Cache-Control'] ?? null);
        self::assertSame($this->other['file']['content'], $response->get_data());

        // Missing physical file — generic safe error, no filesystem detail:
        $ghost = $this->insert('cpms_medical_attachments', [
            'clinic_id' => $this->other['clinic'], 'patient_id' => $this->other['patient'],
            'visit_id' => null, 'category' => 'document', 'original_filename' => 'ghost.pdf',
            'stored_filename' => str_repeat('a', 32) . '.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 10, 'storage_path' => $this->other['clinic'] . '/aa/' . str_repeat('a', 32) . '.pdf',
            'visibility' => 'patient_visible', 'uploaded_by_wp_user_id' => 0,
            'deleted_at' => null, 'created_at' => $this->now,
        ]);
        $missing = $this->stream($ghost, [], $this->foreign);
        $this->error($missing, 404, 'CLINIC_NOT_FOUND');
        self::assertStringNotContainsString($this->storagePath, (string) wp_json_encode($missing->get_data()));

        // Path-traversal row never escapes the storage jail:
        $jail = $this->insert('cpms_medical_attachments', [
            'clinic_id' => $this->other['clinic'], 'patient_id' => $this->other['patient'],
            'visit_id' => null, 'category' => 'document', 'original_filename' => 'evil.pdf',
            'stored_filename' => str_repeat('b', 32) . '.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 10, 'storage_path' => '../../evil.pdf',
            'visibility' => 'patient_visible', 'uploaded_by_wp_user_id' => 0,
            'deleted_at' => null, 'created_at' => $this->now,
        ]);
        $this->error($this->stream($jail, [], $this->foreign), 404, 'CLINIC_NOT_FOUND');
    }

    // ================= Date/Jalali — fail closed =================

    public function testFileDatesPairJalaliOnlyWhenTrustedLocationContextExists(): void
    {
        $utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $this->now, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->now, new \DateTimeZone('UTC'));
        self::assertNotFalse($utc);
        $local = $utc->setTimezone(new \DateTimeZone('Asia/Tehran'));
        $expectedJalali = Jalali::formatYmd($local->format('Y-m-d'));

        $response = $this->get('/patients/' . $this->other['patient'] . '/files', [], $this->foreign);
        self::assertSame(200, $response->get_status());
        $byName = [];
        foreach ($response->get_data()['data']['files'] as $file) {
            $byName[$file['original_filename']] = $file;
        }

        // Visit-linked file: trusted path file.visit_id -> Visit -> Location ->
        // IANA timezone -> Jalali. Raw created_at stays unchanged.
        $visitLinked = $byName['SYN-FILES-FOREIGN.pdf'] ?? null;
        self::assertNotNull($visitLinked);
        self::assertSame($this->now, $visitLinked['created_at'], 'Raw Gregorian created_at stays unchanged.');
        self::assertArrayHasKey(
            'created_at_jalali',
            $visitLinked,
            'Visit-linked files pair created_at with created_at_jalali from the trusted Location timezone.'
        );
        self::assertSame($expectedJalali, $visitLinked['created_at_jalali'] ?? null);

        // Visit-less patient upload: NO deterministic Location context exists, so
        // no Jalali date may be fabricated — omit instead of guess.
        $uploaded = $byName['SYN-FILES-FOREIGN-UP.pdf'] ?? null;
        self::assertNotNull($uploaded);
        self::assertSame($this->now, $uploaded['created_at']);
        self::assertArrayNotHasKey(
            'created_at_jalali',
            $uploaded,
            'Visit-less patient uploads must not carry a fabricated Jalali date.'
        );

        self::assertSame(
            $this->now,
            App::db()->fetchValue(
                'SELECT created_at FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE id = %d',
                [$this->other['file']['id']]
            ),
            'Presentation formatting must not mutate stored datetimes.'
        );
    }

    // ================= Portal UI RED =================

    public static function cardinalities(): array
    {
        return ['zero records' => [0], 'one record' => [1], 'multiple records' => [2]];
    }

    /** @dataProvider cardinalities */
    public function testMyFilesLivesInApprovedShellWithSafeRecordState(int $count): void
    {
        $utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $this->now, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $this->now, new \DateTimeZone('UTC'));
        self::assertNotFalse($utc);
        $expectedJalali = Jalali::formatYmd($utc->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d'));

        $html = $this->render($count === 0 ? $this->empty : ($count === 1 ? $this->foreign : $this->user));
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);

        // Existing shell guards: previous slices remain intact.
        foreach (['patient-nav', 'appointments-section', 'notifications-section', 'profile-section', 'visits-section', 'prescriptions-section'] as $role) {
            self::assertSame(1, $xpath->query('//*[@data-role="' . $role . '"]')->length, 'Existing shell guard: ' . $role);
        }

        self::assertSame(1, $xpath->query('//nav//*[@data-role="nav-files"]')->length, 'RED UI: one My Files navigation item in the existing shell.');
        $sections = $xpath->query('//section[@data-role="files-section"]');
        self::assertSame(1, $sections->length, 'One My Files section only.');
        $section = $sections->item(0);

        if ($count === 0) {
            self::assertSame(1, $xpath->query('.//*[@data-role="files-empty-state"]', $section)->length);
            self::assertSame(0, $xpath->query('.//*[@data-role="file-item"]', $section)->length);
            self::assertSame(0, $xpath->query('.//input[@data-role="files-upload-input"]', $section)->length, 'No upload target without a linked record.');
        } elseif ($count === 1) {
            $context = $xpath->query('.//*[@data-role="files-context"]', $section);
            self::assertSame(1, $context->length);
            self::assertStringContainsString('Beta' . $this->tag, $context->item(0)->textContent);
            self::assertStringContainsString('Foreign', $context->item(0)->textContent);

            self::assertSame(1, $xpath->query('.//*[@data-role="files-list"]', $section)->length);
            $items = $xpath->query('.//*[@data-role="file-item"]', $section);
            self::assertSame(2, $items->length, 'Sole record auto-resolves to the patient-visible file list.');

            $visitItem = null;
            $uploadItem = null;
            foreach ($items as $item) {
                if (str_contains($item->textContent, 'SYN-FILES-FOREIGN.pdf')) {
                    $visitItem = $item;
                }
                if (str_contains($item->textContent, 'SYN-FILES-FOREIGN-UP.pdf')) {
                    $uploadItem = $item;
                }
            }
            self::assertNotNull($visitItem, 'Visit-linked file row rendered.');
            self::assertNotNull($uploadItem, 'Visit-less patient upload row rendered.');
            self::assertStringContainsString($expectedJalali, $visitItem->textContent, 'Visit-linked row shows the trusted Jalali date.');
            self::assertDoesNotMatchRegularExpression('/\d{4}\/\d{2}/', (string) $uploadItem->textContent, 'Visit-less upload row omits Jalali instead of guessing.');
            self::assertDoesNotMatchRegularExpression('/\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b/', (string) $uploadItem->textContent, 'Visit-less upload row shows no raw Gregorian date.');

            foreach ([$visitItem, $uploadItem] as $item) {
                $download = $xpath->query('.//*[@data-role="file-download"]', $item);
                self::assertSame(1, $download->length, 'Protected download action per file row.');
                self::assertNotSame('', $download->item(0)->getAttribute('data-file-id'), 'File id rides only as the technical stream selector.');
            }
            self::assertSame((string) $this->other['file']['id'], $xpath->query('.//*[@data-role="file-download"]', $visitItem)->item(0)->getAttribute('data-file-id'));

            self::assertSame(1, $xpath->query('.//input[@data-role="files-upload-input"]', $section)->length, 'Patient upload control.');
            self::assertSame(1, $xpath->query('.//*[@data-role="files-upload-button"]', $section)->length);
            foreach (['files-loading', 'files-error', 'files-upload-progress', 'files-upload-success', 'files-upload-error'] as $role) {
                self::assertSame(1, $xpath->query('.//*[@data-role="' . $role . '"]', $section)->length, 'Safe state hook: ' . $role);
            }
            self::assertSame(0, $xpath->query('.//*[@data-role="files-delete"]|.//*[@data-role="files-edit"]|.//*[@data-role="files-replace"]', $section)->length, 'No delete/edit/replace surface.');
        } else {
            $select = $xpath->query('.//select[@data-role="files-record-select"]', $section);
            self::assertSame(1, $select->length);
            $values = [];
            foreach ($xpath->query('./option', $select->item(0)) as $option) {
                $value = $option->getAttribute('value');
                if ($value !== '') {
                    $values[] = (int) $value;
                    self::assertFalse($option->hasAttribute('selected'), 'Never preselect primary/first.');
                }
            }
            self::assertSame([$this->a['link'], $this->b['link']], $values);
            self::assertSame('', $xpath->query('./option', $select->item(0))->item(0)->getAttribute('value'), 'First option must be an empty explicit-choice prompt.');
            self::assertSame(0, $xpath->query('.//*[@data-role="files-list"]', $section)->length, 'No file list before explicit record selection.');
            self::assertSame(0, $xpath->query('.//*[@data-role="file-item"]', $section)->length, 'No files before explicit record selection.');
            self::assertSame(0, $xpath->query('.//input[@data-role="files-upload-input"]', $section)->length, 'No upload target before explicit record selection.');
        }

        // Display allowlist: no authority inputs and no internal storage/identity
        // metadata anywhere in the section markup.
        $sectionHtml = (string) $dom->saveHTML($section);
        foreach (['clinic_id', 'organization_id', 'visit_id', 'storage_path', 'stored_filename', 'uploaded_by_wp_user_id', 'metadata_json', 'doctor_private'] as $key) {
            self::assertStringNotContainsString($key, $sectionHtml, 'No internal/authority metadata in the Files section: ' . $key);
        }
        self::assertStringNotContainsString('SYN-PRIVATE', $sectionHtml, 'Private files never reach the patient surface.');
        self::assertStringNotContainsString('SYN-DELETED', $sectionHtml, 'Deleted files never reach the patient surface.');
        foreach ([$this->a['file']['id'], $this->b['file']['id'], $this->other['file']['id']] as $fileId) {
            self::assertDoesNotMatchRegularExpression('/>\s*' . $fileId . '\s*</', $sectionHtml, 'Internal file id never rendered as visible text.');
        }

        $configs = $xpath->query('//script[@class="cpms-patient-portal__config"]');
        self::assertSame(1, $configs->length);
        $config = json_decode($configs->item(0)->textContent, true);
        self::assertNotFalse(wp_verify_nonce($config['nonce'], 'wp_rest'));
        self::assertSame('/patients/{patient_id}/files', $config['files_path'] ?? null, 'Reuse C3/C4, relative to the existing rest_root.');
        self::assertSame('/files/{id}/stream', $config['files_stream_path'] ?? null, 'Reuse E17, relative to the existing rest_root.');
        foreach (['clinic_id', 'patient_id', 'organization_id', 'role'] as $key) {
            self::assertArrayNotHasKey($key, $config, 'Config must not contain authority key: ' . $key);
        }
    }

    // ================= Fixtures & helpers =================

    private function record(int $clinic, int $user, string $name, int $primary, string $status = 'active'): array
    {
        $now = $this->now;
        $mobile = '09' . str_pad((string) (abs(crc32($this->tag . $name)) % 1000000000), 9, '0', STR_PAD_LEFT);
        $patient = $this->insert('cpms_patients', [
            'clinic_id' => $clinic, 'mrn' => $this->tag . $name, 'first_name' => $name,
            'last_name' => 'Files', 'mobile' => $mobile, 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $link = $this->insert('cpms_patient_user_links', [
            'clinic_id' => $clinic, 'patient_id' => $patient, 'wp_user_id' => $user,
            'mobile_at_link' => $mobile, 'is_primary' => $primary, 'linked_at' => $now,
        ]);
        $clinician = $this->insert('cpms_clinicians', [
            'clinic_id' => $clinic, 'full_name' => 'Doctor ' . $name,
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $location = $this->insert('cpms_locations', [
            'clinic_id' => $clinic, 'name' => 'Location ' . $name, 'slug' => strtolower($name) . $this->tag,
            'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $visit = $this->insert('cpms_visits', [
            'clinic_id' => $clinic, 'patient_id' => $patient,
            'clinician_id' => $clinician, 'location_id' => $location, 'visit_date' => gmdate('Y-m-d'),
            'source' => 'walk_in', 'status' => 'checked_out', 'active' => 0,
            'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $record = compact('clinic', 'patient', 'link', 'clinician', 'location', 'visit');

        // Material file fixtures on the REAL protected storage layout:
        // staff-created visit-linked visible file, visit-less patient upload,
        // doctor_private decoy, soft-deleted decoy.
        $record['file'] = $this->seedFile($clinic, $patient, $visit, 'SYN-FILES-' . strtoupper($name) . '.pdf', 'patient_visible');
        $record['upfile'] = $this->seedFile($clinic, $patient, null, 'SYN-FILES-' . strtoupper($name) . '-UP.pdf', 'patient_visible');
        $record['priv'] = $this->seedFile($clinic, $patient, $visit, 'SYN-PRIVATE-' . strtoupper($name) . '.pdf', 'doctor_private');
        $record['del'] = $this->seedFile($clinic, $patient, $visit, 'SYN-DELETED-' . strtoupper($name) . '.pdf', 'patient_visible', true);

        return $record;
    }

    /**
     * @return array{id: int, storage_path: string, content: string}
     */
    private function seedFile(int $clinic, int $patient, ?int $visit, string $name, string $visibility, bool $deleted = false): array
    {
        $content = $this->pdfContent($name);
        $storage = new LocalFileStorage($this->storagePath);
        $relative = $storage->store($content, $clinic, 'pdf');
        $id = $this->insert('cpms_medical_attachments', [
            'clinic_id' => $clinic, 'patient_id' => $patient, 'visit_id' => $visit,
            'category' => 'document', 'original_filename' => $name,
            'stored_filename' => basename($relative), 'mime_type' => 'application/pdf',
            'file_size' => strlen($content), 'storage_path' => $relative,
            'visibility' => $visibility, 'uploaded_by_wp_user_id' => 0,
            'deleted_at' => $deleted ? $this->now : null, 'created_at' => $this->now,
        ]);

        return ['id' => $id, 'storage_path' => $relative, 'content' => $content];
    }

    private function insert(string $table, array $data): int
    {
        global $wpdb;
        self::assertSame(1, $wpdb->insert($wpdb->prefix . $table, $data), 'Material fixture: ' . $table . ' ' . $wpdb->last_error);
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        self::assertSame($id, (int) App::db()->fetchValue('SELECT id FROM ' . App::db()->table($table) . ' WHERE id = %d', [$id]));
        return $id;
    }

    private function user(string $suffix): int
    {
        $id = wp_create_user('p9s7-' . $this->tag . $suffix, 'test-pass-only');
        self::assertIsInt($id);
        get_userdata($id)->set_role('cpms_patient');
        return $id;
    }

    private function get(string $path, array $params = [], ?int $user = null, bool $nonce = true): WP_REST_Response
    {
        wp_set_current_user($user ?? $this->user);
        $request = new WP_REST_Request('GET', '/clinic/v1' . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        return rest_do_request($request);
    }

    private function upload(int $patientId, array $params, string $name, string $content, ?int $user = null, bool $nonce = true): WP_REST_Response
    {
        wp_set_current_user($user ?? $this->user);
        $tmp = tempnam(sys_get_temp_dir(), 'cpms_p9s7_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $content);
        $request = new WP_REST_Request('POST', '/clinic/v1/patients/' . $patientId . '/files');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_file_params(['file' => [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'error' => 0,
            'type' => 'application/octet-stream', // deliberately ignored — finfo decides
        ]]);
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        return rest_do_request($request);
    }

    private function stream(int $fileId, array $params = [], ?int $user = null, bool $nonce = true): WP_REST_Response
    {
        wp_set_current_user($user ?? $this->user);
        $request = new WP_REST_Request('GET', '/clinic/v1/files/' . $fileId . '/stream');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        if ($nonce) {
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        }
        return rest_do_request($request);
    }

    private function error(WP_REST_Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();
        self::assertSame($code, $data['code']);
        self::assertSame($status, $data['data']['status']);
        self::assertNotEmpty($data['message']);
    }

    private function counts(): array
    {
        return array_map(
            static fn (string $table): int => (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table)),
            ['cpms_patients', 'cpms_patient_user_links', 'cpms_medical_attachments']
        );
    }

    private function storageFileCount(): int
    {
        if (!is_dir($this->storagePath)) {
            return 0;
        }
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    private function pdfContent(string $tag): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R /CPMS (" . $tag . ") >>\nendobj\n%%EOF\n";
    }

    private function render(int $user): string
    {
        wp_set_current_user($user);
        $url = PatientPortalShell::portal_url();
        self::assertStringNotContainsString('/wp-admin/', $url);
        $this->go_to($url);
        $template = apply_filters('template_include', get_stylesheet_directory() . '/page.php');
        self::assertSame(realpath(dirname(__DIR__, 2) . '/templates/patient-portal-shell.php'), realpath($template));
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Storage\LocalFileStorage;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * تست‌های فایل پزشکی (F5) — E16/E17 + C3/C4 + **TP-06 (Security)**:
 *
 *  (a) فایل خارج از uploads + گارد سرور (.htaccess deny) — URL عمومی ندارد
 *  (b) Patient روی فایل بیمار دیگر → 404 + Audit
 *  (c) MIME جعلی (php داخل jpg) → CLINIC_FILE_INVALID بدون ذخیره
 *  (+) تطابق Extension↔MIME، سقف حجم، جداسازی Visibility منشی/بیمار،
 *      Audit خواندن فایل حساس (F-4)، Soft Delete، C3/C4 REST-level.
 */
final class MedicalFilesTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private string $storagePath;
    private int $patientAId;
    private int $patientBId;
    private int $patientAUser;
    private int $patientBUser;
    private int $secretaryUserId;
    private int $doctorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        // ذخیره‌سازی تست در Temp (جدا از wp-content) — Cleanup در tearDown
        $this->storagePath = sys_get_temp_dir() . '/cpms-files-test-' . bin2hex(random_bytes(4));
        App::settings()->set('files.storage_path', $this->storagePath);
        App::settings()->set('files.max_upload_bytes', 10485760);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        foreach ([['MR-MF-0001', '09126660001', 'A'], ['MR-MF-0002', '09126660002', 'B']] as [$mrn, $mobile, $tag]) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, "F", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $mrn,
                $tag,
                $mobile,
                $now,
                $now
            ));
            $patientId = (int) $wpdb->insert_id;
            $userId = $this->makeUser('mf_patient_' . strtolower($tag), 'cpms_patient');
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (1, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId,
                $userId,
                $mobile,
                $now
            ));
            if ($tag === 'A') {
                $this->patientAId = $patientId;
                $this->patientAUser = $userId;
            } else {
                $this->patientBId = $patientId;
                $this->patientBUser = $userId;
            }
        }

        $this->secretaryUserId = $this->makeUser('mf_secretary', 'cpms_secretary');
        $this->doctorUserId = $this->makeUser('mf_doctor', 'cpms_doctor');
    }

    protected function tearDown(): void
    {
        // فایل‌های فیزیکی تست — خارج از تراکنش DB
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

    // ================= Happy path + TP-06(a) — URL عمومی ندارد =================

    public function testStaffUploadStoresOutsideUploadsWithServerGuards(): void
    {
        $file = $this->makeUploadedFile('report.pdf', $this->pdfContent());

        $row = App::medicalFileService()->upload(
            $this->secretaryUserId,
            $file,
            $this->patientAId,
            null,
            'document',
            'patient_visible'
        );

        $this->assertSame('report.pdf', $row['original_filename']);
        $this->assertSame('application/pdf', $row['mime_type']);

        $stored = $this->storageRow((int) $row['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $stored['stored_filename']);

        // TP-06(a): خارج از uploads + گارد سرور
        $this->assertStringNotContainsString('uploads', (string) $stored['storage_path']);
        $this->assertFileExists($this->storagePath . '/.htaccess');
        $this->assertStringContainsString('Deny', (string) file_get_contents($this->storagePath . '/.htaccess'));
        $this->assertFileExists($this->storagePath . '/index.php');
    }

    public function testDoctorStreamsUploadedFile(): void
    {
        $content = $this->pdfContent();
        $file = $this->makeUploadedFile('lab.pdf', $content);
        $row = App::medicalFileService()->upload($this->doctorUserId, $file, $this->patientAId, null, 'lab_result', 'patient_visible');

        $streamed = App::medicalFileService()->stream($this->doctorUserId, (int) $row['id']);
        $this->assertSame($content, $streamed['content']);
        $this->assertSame('application/pdf', $streamed['mime_type']);

        // F-4/TP-06: خواندن lab_result → Audit FILE_READ
        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['FILE_READ', 'file', (int) $row['id']]
        );
        $this->assertNotNull($audit);
    }

    // ================= TP-06(c) — MIME جعلی/عدم تطابق =================

    public function testPhpDisguisedAsJpgIsRejected(): void
    {
        $file = $this->makeUploadedFile('shell.jpg', '<?php system($_GET["c"]); ?>');

        try {
            App::medicalFileService()->upload($this->secretaryUserId, $file, $this->patientAId, null, 'image', 'patient_visible');
            $this->fail('Expected CLINIC_FILE_INVALID (fake MIME)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_FILE_INVALID', $e->errorCode);
        }

        // هیچ ردیفی ذخیره نشده
        $count = App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE patient_id = %d',
            [$this->patientAId]
        );
        $this->assertSame(0, (int) $count);
    }

    public function testExtensionMustMatchRealMime(): void
    {
        // محتوای PDF واقعی اما با نام .jpg → رد (F-3 تطابق دوطرفه)
        $file = $this->makeUploadedFile('document.jpg', $this->pdfContent());

        try {
            App::medicalFileService()->upload($this->secretaryUserId, $file, $this->patientAId, null, 'document', 'patient_visible');
            $this->fail('Expected CLINIC_FILE_INVALID (extension mismatch)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_FILE_INVALID', $e->errorCode);
        }
    }

    public function testSizeLimitIsEnforced(): void
    {
        App::settings()->set('files.max_upload_bytes', 1048576);
        \ClinicCore\Settings\Settings::flushCache();

        $file = $this->makeUploadedFile('big.pdf', $this->pdfContent() . str_repeat('x', 2 * 1024 * 1024));

        try {
            App::medicalFileService()->upload($this->secretaryUserId, $file, $this->patientAId);
            $this->fail('Expected CLINIC_FILE_INVALID (size)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_FILE_INVALID', $e->errorCode);
        }
    }

    // ================= TP-06(b) + Visibility — IDOR و جداسازی =================

    public function testPatientCannotStreamAnotherPatientsFile(): void
    {
        $file = $this->makeUploadedFile('a.pdf', $this->pdfContent());
        $row = App::medicalFileService()->upload($this->secretaryUserId, $file, $this->patientBId, null, 'document', 'patient_visible');

        try {
            App::medicalFileService()->stream($this->patientAUser, (int) $row['id']);
            $this->fail('Expected CLINIC_NOT_FOUND (cross-patient IDOR)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }

        $audit = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') .
            ' WHERE action = %s AND resource_type = %s AND resource_id = %d',
            ['FORBIDDEN_ACCESS_ATTEMPT', 'file', (int) $row['id']]
        );
        $this->assertNotNull($audit);
    }

    public function testSecretaryCannotStreamDoctorPrivateFile(): void
    {
        $file = $this->makeUploadedFile('private.pdf', $this->pdfContent());
        $row = App::medicalFileService()->upload($this->doctorUserId, $file, $this->patientAId, null, 'document', 'doctor_private');

        try {
            App::medicalFileService()->stream($this->secretaryUserId, (int) $row['id']);
            $this->fail('Expected CLINIC_NOT_FOUND (secretary must not read doctor_private)');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }

        // پزشک صاحب فایل می‌تواند
        $streamed = App::medicalFileService()->stream($this->doctorUserId, (int) $row['id']);
        $this->assertSame($this->pdfContent(), $streamed['content']);
    }

    public function testPatientFileListOnlyShowsVisible(): void
    {
        $pub = $this->makeUploadedFile('pub.pdf', $this->pdfContent());
        $priv = $this->makeUploadedFile('priv.pdf', $this->pdfContent());
        App::medicalFileService()->upload($this->secretaryUserId, $pub, $this->patientAId, null, 'document', 'patient_visible');
        App::medicalFileService()->upload($this->doctorUserId, $priv, $this->patientAId, null, 'document', 'doctor_private');

        $list = App::medicalFileService()->patientFiles($this->patientAUser, $this->patientAId);
        $this->assertCount(1, $list);
        $this->assertSame('patient_visible', $list[0]['visibility']);
    }

    // ================= Soft Delete (F-5) =================

    public function testSoftDeleteHidesFileFromAllQueries(): void
    {
        $file = $this->makeUploadedFile('del.pdf', $this->pdfContent());
        $row = App::medicalFileService()->upload($this->secretaryUserId, $file, $this->patientAId);

        App::medicalFileService()->softDelete($this->secretaryUserId, (int) $row['id']);

        $this->assertCount(0, App::medicalFileService()->patientFiles($this->patientAUser, $this->patientAId));
        try {
            App::medicalFileService()->stream($this->doctorUserId, (int) $row['id']);
            $this->fail('Expected CLINIC_NOT_FOUND after soft delete');
        } catch (ClinicalException $e) {
            $this->assertSame('CLINIC_NOT_FOUND', $e->errorCode);
        }
    }

    // ================= C3/C4/E17 — REST-level =================

    public function testPatientUploadViaRestAndStreamOwnFile(): void
    {
        $tmp = $this->tempFile($this->pdfContent());

        wp_set_current_user($this->patientAUser);
        $request = new WP_REST_Request('POST', self::NS . '/patients/' . $this->patientAId . '/files');
        $request->set_param('category', 'document');
        $request->set_file_params(['file' => [
            'name' => 'my-report.pdf',
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => 0,
            'type' => 'application/pdf',
        ]]);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $response = rest_do_request($request);
        $this->assertSame(201, $response->get_status(), (string) json_encode($response->get_data()));
        $fileId = (int) ($response->get_data()['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $fileId);
        $this->assertSame('patient_visible', $response->get_data()['data']['visibility']);

        // C4 — لیست خودش
        $list = $this->dispatch('GET', self::NS . '/patients/' . $this->patientAId . '/files');
        $this->assertSame(200, $list->get_status());
        $this->assertCount(1, $list->get_data()['data']['files'] ?? []);

        // E17 — Stream خودش (باینری، نه Envelope)
        $stream = $this->dispatch('GET', self::NS . '/files/' . $fileId . '/stream');
        $this->assertSame(200, $stream->get_status());
        $this->assertSame($this->pdfContent(), $stream->get_data());
        $this->assertSame('application/pdf', $stream->headers['Content-Type'] ?? null);
    }

    public function testPatientCannotUploadForAnotherPatient(): void
    {
        $tmp = $this->tempFile($this->pdfContent());

        wp_set_current_user($this->patientAUser);
        $request = new WP_REST_Request('POST', self::NS . '/patients/' . $this->patientBId . '/files');
        $request->set_file_params(['file' => [
            'name' => 'evil.pdf',
            'tmp_name' => $tmp,
            'size' => (int) filesize($tmp),
            'error' => 0,
            'type' => 'application/pdf',
        ]]);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $response = rest_do_request($request);
        $this->assertSame(404, $response->get_status());
        $this->assertSame('CLINIC_NOT_FOUND', $response->get_data()['code'] ?? null);
    }

    public function testUnauthenticatedStreamIs401(): void
    {
        $file = $this->makeUploadedFile('x.pdf', $this->pdfContent());
        $row = App::medicalFileService()->upload($this->secretaryUserId, $file, $this->patientAId);

        wp_set_current_user(0);
        $response = $this->dispatch('GET', self::NS . '/files/' . (int) $row['id'] . '/stream');
        $this->assertSame(401, $response->get_status());
    }

    // ================= Helpers =================

    private function storageRow(int $fileId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_medical_attachments') . ' WHERE id = %d',
            [$fileId]
        );
        assert(is_array($row));

        return $row;
    }

    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF\n";
    }

    private function tempFile(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cpms_up_');
        assert(is_string($tmp));
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
            'type' => 'application/octet-stream', // عمداً نادیده گرفته می‌شود — finfo ملاک است
        ];
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(3)), 'pass-12345', $login . '@test.local');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
    }

    private function dispatch(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return rest_do_request($request);
    }
}

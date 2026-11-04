<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Clinical\FileStorageFailure;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Independent-review item 3 — RUNTIME coverage for the real storage-failure
 * path of MedicalFileService::store(). The per-Clinic storage root is pointed
 * inside the document root so the fail-closed private-storage guard rejects it
 * deterministically (CLINIC_STORAGE_INSIDE_WEBROOT) in every environment —
 * no filesystem-permission dependence, no source-grep substitutes.
 *
 * The boundary must then surface: (1) exactly one sanitized operational-log
 * row, (2) the REGISTERED CLINIC_INTERNAL_ERROR/500 client error carrying
 * only actionable Persian guidance and never a filesystem path, while the
 * operation stays fail-closed (no metadata row, no physical object) — and
 * authorization must still reject non-members BEFORE the storage boundary.
 */
final class MedicalFileStorageFailureTest extends WP_UnitTestCase
{
    private string $brokenStoragePath;
    private int $patientId;
    private int $secretaryUserId;
    private int $nonMemberUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        // Break the ACTIVE Clinic (1) storage root deterministically.
        $this->brokenStoragePath = ABSPATH . 'wp-content/uploads/cpms-mfs-fail-'
            . bin2hex(random_bytes(3));
        App::settings()->set('files.storage_path', $this->brokenStoragePath);
        App::settings()->set('files.max_upload_bytes', 10485760);

        global $wpdb;
        $now = App::db()->nowUtcSql();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (1, %s, %s, "F", %s, "active", %s, %s)',
            'MR-SF-0001',
            'S',
            '09126660009',
            $now,
            $now
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        $this->patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->patientId, 'fixture: patient persisted');

        $this->secretaryUserId = $this->makeUser('sf_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');

        // کاربرِ بدونِ عضویت در Clinicِ ۱ — برای اثباتِ پیشیِ Authorization.
        $this->nonMemberUserId = $this->makeUser('sf_nonmember', 'cpms_secretary');
    }

    public function testStorageFailureIsLoggedSanitizedAndFailClosed(): void
    {
        $filesBefore = $this->attachmentCount($this->patientId);
        $logsBefore  = $this->opLogCount(FileStorageFailure::OP_EVENT);

        $exception = null;
        try {
            App::medicalFileService()->upload(
                $this->secretaryUserId,
                $this->makeUploadedFile('scan.pdf', $this->pdfContent()),
                $this->patientId,
                null,
                'document',
                'patient_visible'
            );
        } catch (ClinicalException $e) {
            $exception = $e;
        }

        self::assertInstanceOf(
            ClinicalException::class,
            $exception,
            'storage infrastructure failure must surface as ClinicalException'
        );
        self::assertSame('CLINIC_INTERNAL_ERROR', $exception->errorCode);
        self::assertSame(500, $exception->httpStatus);

        // پیامِ کلاینت فقط «اقدامِ بعدی» است؛ مسیر/کدِ داخلی هرگز.
        $message = $exception->getMessage();
        self::assertStringStartsWith('ثبت فایل انجام نشد', $message);
        self::assertStringContainsString('مدیر کلینیک', $message);
        self::assertStringContainsString('سلامت سیستم', $message);
        self::assertStringNotContainsString('wp-content', $message);
        self::assertStringNotContainsString('uploads', $message);
        self::assertStringNotContainsString('CLINIC_STORAGE_INSIDE_WEBROOT', $message);

        // دقیقاً یک ردیفِ OpLog با Contextِ اسکالرِ sanitize‌شده.
        self::assertSame(
            $logsBefore + 1,
            $this->opLogCount(FileStorageFailure::OP_EVENT),
            'exactly one operational-log row must be recorded'
        );
        $logRow = App::db()->fetchRow(
            'SELECT level, context_json FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s ORDER BY id DESC LIMIT 1',
            [ FileStorageFailure::OP_EVENT ]
        );
        self::assertIsArray($logRow, 'operational-log row must be readable');
        self::assertSame('error', (string) $logRow['level']);

        $contextJson = (string) $logRow['context_json'];
        $context     = json_decode($contextJson, true);
        self::assertIsArray($context, 'op-log context_json must decode');
        self::assertSame('files', $context['root'] ?? null);
        self::assertSame('CLINIC_STORAGE_INSIDE_WEBROOT', $context['failure'] ?? null);
        self::assertIsString($context['reason'] ?? null);
        self::assertLessThanOrEqual(160, mb_strlen((string) $context['reason']));
        foreach ($context as $value) {
            self::assertTrue(is_scalar($value), 'op-log context must stay scalar');
        }

        // حریم: Contextِ لاگ هیچ دادهٔ بالینی/مسیرِ فایلِ آپلودی ندارد.
        self::assertStringNotContainsString('MR-SF-0001', $contextJson);
        self::assertStringNotContainsString('09126660009', $contextJson);
        self::assertStringNotContainsString('cpms_up_', $contextJson);

        // Fail-Closed: نه ردیفِ متادیتا، نه شیءِ فیزیکی، نه پوشهٔ ناامن.
        self::assertSame($filesBefore, $this->attachmentCount($this->patientId));
        self::assertFileDoesNotExist($this->brokenStoragePath);
    }

    public function testAuthorizationStillPrecedesTheStorageBoundary(): void
    {
        $filesBefore = $this->attachmentCount($this->patientId);
        $logsBefore  = $this->opLogCount(FileStorageFailure::OP_EVENT);

        // Storage کلینیک هنوز شکسته است؛ کاربرِ غیرعضو باید پیش از رسیدن به
        // همان مرز رد شود — نه با 500، نه با ردیفِ لاگِ ذخیره‌سازی.
        $exception = null;
        try {
            App::medicalFileService()->upload(
                $this->nonMemberUserId,
                $this->makeUploadedFile('scan.pdf', $this->pdfContent()),
                $this->patientId,
                null,
                'document',
                'patient_visible'
            );
        } catch (ClinicalException $e) {
            $exception = $e;
        }

        self::assertInstanceOf(ClinicalException::class, $exception);
        self::assertNotSame(
            'CLINIC_INTERNAL_ERROR',
            $exception->errorCode,
            'a non-member must be rejected before the storage boundary'
        );
        self::assertNotSame(500, $exception->httpStatus);
        self::assertGreaterThanOrEqual(400, $exception->httpStatus);
        self::assertLessThan(500, $exception->httpStatus);
        self::assertSame(
            $logsBefore,
            $this->opLogCount(FileStorageFailure::OP_EVENT),
            'an unauthorized request must never reach the storage-failure boundary'
        );
        self::assertSame($filesBefore, $this->attachmentCount($this->patientId));
    }

    // ================= Fixture helpers (الگوی MedicalFilesTest) =================

    private function attachmentCount(int $patientId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_medical_attachments')
            . ' WHERE patient_id = %d',
            [ $patientId ]
        );
    }

    private function opLogCount(string $message): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs')
            . ' WHERE message = %s',
            [ $message ]
        );
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user(
            $login . bin2hex(random_bytes(3)),
            'pass-12345',
            $login . '@test.local'
        );
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }

        return $userId;
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
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinical\MedicalFileService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Storage\StorageConfigurationException;
use WP_UnitTestCase;

/**
 * ITEM 2 — شکستِ ذخیره‌سازی (نوشتنِ ناممکن ریشه) باید:
 *  - fail-closed بماند (هیچ Save بی‌صدا/بدون خطا);
 *  - استثنای تایپ‌شده StorageConfigurationException (کد CLINIC_STORAGE_NOT_WRITABLE)
 *    raise کند (نه CLINIC_INTERNAL_ERROR بی‌詳細);
 *  - در لایه کنترلر (FilesController) یک ردیفِ لاگ عملیاتی scalar-context بنویسد
 *    و پاسخ فارسیِ قابل‌اقدام (CLINIC_INTERNAL_ERROR / 500) برگرداند — بدون
 *    افشای مسیر فایل‌سیستم.
 *
 * این تست لایه سرویس را覆盖 می‌کند (.fail-closed + کد تایپ‌شده); نگارشِ کنترلر
 * را با-read کد تأیید می‌کنیم (فراخوانی storageFailureResponse از wrap/stream).
 */
final class MedicalFilesStorageFailureTest extends WP_UnitTestCase
{
    private string $unwritableRoot;
    private int $secretaryUserId;
    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (1, %s, %s, %s, %s, "active", %s, %s)',
            'MR-MFSF-0001', 'فشل', 'ذخیره', '09126669001', $now, $now
        ));
        $this->patientId = (int) $wpdb->insert_id;

        $this->secretaryUserId = $this->makeUser('mfsf_secretary', 'cpms_secretary');
        cpms_test_seed_membership($this->secretaryUserId, 1, 'cpms_secretary');

        $this->unwritableRoot = sys_get_temp_dir() . '/cpms-unwritable-' . bin2hex(random_bytes(4));
        @mkdir($this->unwritableRoot, 0500, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        App::settings()->set('files.storage_path', $this->unwritableRoot);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->unwritableRoot)) {
            @chmod($this->unwritableRoot, 0700); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $this->removeTree($this->unwritableRoot);
        }
        parent::tearDown();
    }

    public function testUnwritableStorageRaisesTypedStorageConfigurationException(): void
    {
        $file = [
            'name' => 'report.pdf',
            'tmp_name' => $this->makeTempUploadedFile(),
            'size' => 100,
            'error' => UPLOAD_ERR_OK,
        ];

        $this->expectException(StorageConfigurationException::class);
        try {
            App::medicalFileService()->upload(
                $this->secretaryUserId,
                $file,
                $this->patientId,
                null,
                'document',
                'patient_visible'
            );
        } catch (StorageConfigurationException $e) {
            self::assertSame('CLINIC_STORAGE_NOT_WRITABLE', $e->errorCode);
            self::assertStringNotContainsString($this->unwritableRoot, $e->getMessage());
            throw $e;
        }
    }

    public function testWritableStorageStillWorks(): void
    {
        $writable = sys_get_temp_dir() . '/cpms-writable-' . bin2hex(random_bytes(4));
        @mkdir($writable, 0700, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        App::settings()->set('files.storage_path', $writable);

        $file = [
            'name' => 'report.pdf',
            'tmp_name' => $this->makeTempUploadedFile(),
            'size' => 100,
            'error' => UPLOAD_ERR_OK,
        ];

        $row = App::medicalFileService()->upload(
            $this->secretaryUserId,
            $file,
            $this->patientId,
            null,
            'document',
            'patient_visible'
        );
        self::assertSame('report.pdf', $row['original_filename']);

        $this->removeTree($writable);
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@mfsf.test');
        self::assertGreaterThan(0, $userId, "user $login created");
        $u = get_userdata($userId);
        if ($u !== false) {
            $u->set_role($role);
        }
        return $userId;
    }

    private function makeTempUploadedFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mfsf_');
        file_put_contents($path, '%PDF-1.4 fake'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        return $path;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
                continue;
            }
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
}

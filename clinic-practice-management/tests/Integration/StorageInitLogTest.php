<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * ITEM 1 — لاگ راه‌اندازی-storage جزئیات خطا را در Context scalar نگه‌دارد:
 * `roots` تودرتو (که OpLogger::sanitize() آن را به {"roots":"array"} تبدیل می‌کرد)
 * به جای آن کلیدهای scalarِ毎 ریشه (files_ok, files_error_code, files_error, files_path
 * و همان‌طور برای backups) ثبت می‌شود و کدهای خطای واقعی در لاگ وجود دارند.
 *
 * OpLogger تغییر نمی‌کند؛ فقط App::initialize_private_storage contextual را فرasenـد می‌کند.
 */
final class StorageInitLogTest extends WP_UnitTestCase
{
    private string $unwritableRoot;
    private int $clinicId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
        ScopeContext::clear();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->insert(
            $wpdb->prefix . 'cpms_organizations',
            ['name' => 'Org Log', 'slug' => 'org-log', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
        );
        $this->clinicId = (int) $wpdb->insert(
            $wpdb->prefix . 'cpms_clinics',
            ['organization_id' => $orgId, 'name' => 'Clinic Log', 'slug' => 'clinic-log', 'timezone' => 'Asia/Tehran', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]
        );

        $this->unwritableRoot = sys_get_temp_dir() . '/cpms-log-' . bin2hex(random_bytes(4));
        @mkdir($this->unwritableRoot, 0500, true); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        App::settingsFactory()->forClinic($this->clinicId)->set('files.storage_path', $this->unwritableRoot);
        \ClinicCore\Settings\Settings::flushCache();

        App::replaceExplicitScope(ClinicScope::forClinic($this->clinicId));
    }

    protected function tearDown(): void
    {
        App::replaceExplicitScope(null);
        App::resetScope();
        ScopeContext::clear();
        if (is_dir($this->unwritableRoot)) {
            @chmod($this->unwritableRoot, 0700); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $this->removeTree($this->unwritableRoot);
        }
        parent::tearDown();
    }

    public function testFailedInitLogsScalarKeysAndRealErrorCodes(): void
    {
        // genesis-state را پاک می‌کنیم تا Initialize اجرا شود (نه کشِ موفق).
        \ClinicCore\Infrastructure\Storage\PrivateStorageInitializer::forget();

        $result = App::initialize_private_storage(true);

        self::assertArrayHasKey('files', $result['roots']);
        self::assertFalse($result['roots']['files']['ok'], 'ریشهٔ غیرقابل‌نوشتن باید initialize ناموفق باشد');

        // آخرین ردیفِ لاگ عملیات را می‌خوانیم.
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT message, context_json FROM ' . $wpdb->prefix . 'cpms_operational_logs
                 WHERE message = %s ORDER BY id DESC LIMIT 1',
                'CPMS_PRIVATE_STORAGE_INIT_FAILED'
            ),
            ARRAY_A
        );

        self::assertNotNull($row, 'ردیفِ لاگ CPMS_PRIVATE_STORAGE_INIT_FAILED باید ثبت شود.');
        $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
        self::assertIsArray($context);

        // کلید 'roots' تودرتو نباشد (همان numeral که OpLogger به "array" برمی‌چسباند).
        self::assertArrayNotHasKey('roots', $context, 'کلید تودرتو roots در لاگ ثبت نمی‌شود.');

        // کلیدهای scalarِ毎 ریشه وجود داشته باشند و کد خطای واقعی داشته باشند.
        self::assertArrayHasKey('files_error_code', $context);
        self::assertArrayHasKey('files_error', $context);
        self::assertArrayHasKey('files_ok', $context);
        self::assertArrayHasKey('files_path', $context);
        self::assertFalse($context['files_ok']);
        self::assertSame('CLINIC_STORAGE_NOT_WRITABLE', $context['files_error_code']);
        self::assertIsString($context['files_error']);
        self::assertNotEmpty($context['files_error']);
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

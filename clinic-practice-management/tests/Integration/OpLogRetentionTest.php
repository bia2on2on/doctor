<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\OpLogCleanupHandler;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\InstallationSettings;
use WP_UnitTestCase;

/**
 * F1-5 / M-2 — Retention لاگ عملیاتی (رگرسیون).
 *
 * `cpms_operational_logs` جدول hot است و پیش‌تر هیچ Retention‌ای نداشت →
 * `cleanup.oplog` (RECURRING) با Setting **سطح نصب**
 * `retention.oplog_days` (پیش‌فرض ۹۰) رشد بی‌کران را می‌بندد — بدون دست‌زدن
 * به رکوردهای تازه و با حذفِ کران‌دار در هر اجرا.
 *
 * M-2: منبعِ پیکربندی از Settingsِ Clinic به `InstallationSettings` منتقل شد؛
 * این تست فقط **تنظیمِ پیکربندی** را به منبع مصوب تغییر داده است و ادعاهای
 * retention (پیش‌فرض ۹۰، حذف فقط قدیمی‌ها، احترام به تغییرِ مقدار) بدونِ
 * تضعیف حفظ شده‌اند.
 */
final class OpLogRetentionTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        delete_option(InstallationSettings::OPTION_OPLOG_RETENTION_DAYS);
    }

    protected function tearDown(): void
    {
        delete_option(InstallationSettings::OPTION_OPLOG_RETENTION_DAYS);
        parent::tearDown();
    }

    public function testDefaultRetentionIs90Days(): void
    {
        $this->assertSame(
            90,
            App::installationSettings()->getOplogRetentionDays(),
            'پیش‌فرض مصوب: ۹۰ روز (سطح نصب)'
        );
    }

    public function testCleanupDeletesOnlyRowsOlderThanRetention(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_operational_logs';
        $old = gmdate('Y-m-d H:i:s', time() - 200 * 86400) . '.000';
        $recent = gmdate('Y-m-d H:i:s', time() - 5 * 86400) . '.000';
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (level, message, context_json, created_at) VALUES ('info', 'oplog-old-row', NULL, %s)", $old)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (level, message, context_json, created_at) VALUES ('info', 'oplog-recent-row', NULL, %s)", $recent)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $deleted = (new OpLogCleanupHandler(App::db(), App::installationSettings()))([]);

        $this->assertSame(1, $deleted, 'فقط ردیف قدیمی‌تر از Retention حذف می‌شود');
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE message = 'oplog-old-row'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE message = 'oplog-recent-row'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function testRetentionChangeIsHonored(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_operational_logs';
        $twoDaysAgo = gmdate('Y-m-d H:i:s', time() - 2 * 86400) . '.000';
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (level, message, context_json, created_at) VALUES ('warning', 'oplog-aged-row', NULL, %s)", $twoDaysAgo)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // M-2: منبعِ مصوب، پیکربندی سطح نصب است (نه Settingsِ Clinic).
        (new InstallationSettings())->setOplogRetentionDays(1);

        $deleted = (new OpLogCleanupHandler(App::db(), App::installationSettings()))([]);
        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE message = 'oplog-aged-row'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function testCleanupOplogIsRegisteredAsRecurringJob(): void
    {
        App::scheduleRecurringJobs();

        $id = App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_jobs') . " WHERE type = 'cleanup.oplog' AND status = 'queued' LIMIT 1"
        );
        $this->assertNotNull($id, 'cleanup.oplog باید در RECURRING_JOBS زمان‌بندی شود');
    }

    public function testPerInvocationDeletionIsBounded(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_operational_logs';
        $batch = OpLogCleanupHandler::DELETE_BATCH_SIZE;
        $this->assertGreaterThan(0, $batch, 'سقف حذف باید یک ثابت مثبت باشد');

        $old = gmdate('Y-m-d H:i:s', time() - 400 * 86400) . '.000';
        $recent = gmdate('Y-m-d H:i:s', time() - 5 * 86400) . '.000';
        $total = $batch + 3;

        $values = [];
        $params = [];
        for ($i = 0; $i < $total; $i++) {
            $values[] = "('info', %s, NULL, %s)";
            $params[] = 'oplog-bounded-' . $i;
            $params[] = $old;
        }
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            "INSERT INTO {$table} (level, message, context_json, created_at) VALUES " . implode(', ', $values),
            ...$params
        ));
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (level, message, context_json, created_at) VALUES ('info', 'oplog-bounded-recent', NULL, %s)", $recent)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $oldBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE message LIKE 'oplog-bounded-%' AND message <> 'oplog-bounded-recent'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame($total, $oldBefore, 'پیش‌شرط: ردیف‌های کهنهٔ بیشتر از سقف باید ساخته شده باشند');

        $deleted = (new OpLogCleanupHandler(App::db(), App::installationSettings()))([]);

        $this->assertLessThanOrEqual($batch, $deleted, 'در هر اجرا حداکثر به اندازهٔ سقف حذف می‌شود');
        $this->assertGreaterThan(0, $deleted, 'اجرا باید دست‌کم یک ردیف حذف کند');
        $oldAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE message LIKE 'oplog-bounded-%' AND message <> 'oplog-bounded-recent'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertGreaterThanOrEqual(1, $oldAfter, 'پس از یک اجرا باید ردیفِ واجدِ شرایط باقی بماند');
        $this->assertLessThanOrEqual($batch, $total - $oldAfter, 'حداکثر سقف ردیف در هر اجرا حذف می‌شود');
        $this->assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE message = 'oplog-bounded-recent'"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'ردیفِ تازه نباید حذف شود'
        );
    }
}

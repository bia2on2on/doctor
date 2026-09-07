<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\OpLogCleanupHandler;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * F1-5 — Retention لاگ عملیاتی (رگرسیون).
 *
 * `cpms_operational_logs` جدول hot است و پیش‌تر هیچ Retentionای نداشت →
 * `cleanup.oplog` (RECURRING) با Setting `retention.oplog_days` (پیش‌فرض ۹۰)
 * رشد بی‌کران را می‌بندد — بدون دست‌زدن به رکوردهای تازه.
 */
final class OpLogRetentionTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
    }

    public function testDefaultRetentionIs90Days(): void
    {
        $this->assertSame(90, App::settings()->get('retention.oplog_days'), 'پیش‌فرض مصوب: ۹۰ روز');
    }

    public function testCleanupDeletesOnlyRowsOlderThanRetention(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cpms_operational_logs';
        $old = gmdate('Y-m-d H:i:s', time() - 200 * 86400) . '.000';
        $recent = gmdate('Y-m-d H:i:s', time() - 5 * 86400) . '.000';
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (level, message, context_json, created_at) VALUES ('info', 'oplog-old-row', NULL, %s)", $old)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (level, message, context_json, created_at) VALUES ('info', 'oplog-recent-row', NULL, %s)", $recent)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $deleted = (new OpLogCleanupHandler(App::db(), App::settings()))([]);

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

        App::settings()->set('retention.oplog_days', 1);

        $deleted = (new OpLogCleanupHandler(App::db(), App::settings()))([]);
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
}

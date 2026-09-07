<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * F1-4 — Audit تغییرات Settings (رگرسیون).
 *
 * هر تغییر مؤثر Config از مسیر `Settings::set()` باید با اکشن مرجع
 * `SETTING_UPDATE` (audit-strategy §2) + before/after + updated_by ثبت شود؛
 * no-op و کلیدهای Runtime (telemetry) ثبت نمی‌شوند.
 */
final class SettingsAuditTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
    }

    public function testSettingChangeIsAuditedWithBeforeAfterAndUpdatedBy(): void
    {
        $adminId = $this->makeUser('cfg-admin', 'administrator');
        $beforeCount = $this->auditCount('SETTING_UPDATE');

        // Default ردیف ندارد → before = Default مؤثر (3) → تغییر مؤثر به 5
        App::settings()->set('otp.daily_max', 5, $adminId);

        $this->assertSame($beforeCount + 1, $this->auditCount('SETTING_UPDATE'), 'تغییر مؤثر Setting باید Audit بگیرد');

        $row = $this->latestAuditRow('SETTING_UPDATE');
        $this->assertSame('setting', $row['resource_type']);
        $this->assertSame((string) $adminId, (string) $row['actor_wp_user_id'], 'updated_by در Audit باید ثبت شود');
        $this->assertSame('administrator', $row['actor_role']);

        $before = json_decode((string) $row['before_json'], true);
        $after = json_decode((string) $row['after_json'], true);
        $this->assertSame('otp.daily_max', $before['setting'], 'کلید Setting باید در before بیاید');
        $this->assertSame(3, $before['value'], 'before = مقدار مؤثر قبلی (Default 3)');
        $this->assertSame('otp.daily_max', $after['setting']);
        $this->assertSame(5, $after['value']);

        // ستون updated_by_wp_user_id در خود cpms_settings (رفتار موجود — رگرسیون)
        global $wpdb;
        $stored = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT updated_by_wp_user_id FROM ' . $wpdb->prefix . 'cpms_settings WHERE `key` = %s AND clinic_id = 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'otp.daily_max'
            ),
            ARRAY_A
        );
        $this->assertNotNull($stored);
        $this->assertSame((string) $adminId, (string) $stored['updated_by_wp_user_id']);
    }

    public function testUnchangedValueDoesNotCreateAuditRecord(): void
    {
        $adminId = $this->makeUser('cfg-admin2', 'administrator');
        $beforeCount = $this->auditCount('SETTING_UPDATE');

        // نوشتن همان مقدار Default → تغییر مؤثر نیست → Audit ندارد (ردیف/updated_by نوشته می‌شود)
        App::settings()->set('booking.max_future_days', 60, $adminId);
        App::settings()->set('booking.max_future_days', 60, $adminId);

        $this->assertSame($beforeCount, $this->auditCount('SETTING_UPDATE'), 'no-op نباید Audit بگیرد');
    }

    public function testRuntimeTelemetryKeysAreNotAudited(): void
    {
        $adminId = $this->makeUser('cfg-admin3', 'administrator');
        $beforeCount = $this->auditCount('SETTING_UPDATE');

        App::settings()->set('jobs.last_tick_at', time(), $adminId);
        App::settings()->set('backup.last_run_at', time(), $adminId);
        App::settings()->set('sms.last_test', ['status' => 'ok', 'at' => time()], $adminId);

        $this->assertSame(
            $beforeCount,
            $this->auditCount('SETTING_UPDATE'),
            'Telemetry عملیاتی (Tick/بکاپ/تست SMS) نباید Audit ۱۰ساله را سیل کند'
        );
    }

    public function testCredentialSettingChangeIsAuditedWithRedactedValue(): void
    {
        $adminId = $this->makeUser('cfg-admin5', 'administrator');
        $beforeCount = $this->auditCount('SETTING_UPDATE');

        // sms.auth = Vault sealed (ciphertext) — سیاست: Secret به Audit تعلق ندارد
        $payload = ['method' => 'api_key', 'fields' => ['api_key' => ['sealed' => 'SEALED-CIPHERTEXT-XYZ', 'last4' => '4567']], 'updated_at' => gmdate('c')];
        App::settings()->set('sms.auth', $payload, $adminId);

        $this->assertSame($beforeCount + 1, $this->auditCount('SETTING_UPDATE'), 'رویدادِ تغییر Credentials باید Audit بگیرد');
        $row = $this->latestAuditRow('SETTING_UPDATE');
        $this->assertStringNotContainsString('SEALED-CIPHERTEXT-XYZ', (string) $row['after_json'], 'مقدار Credential (حتی sealed) نباید در Audit بیاید');
        $after = json_decode((string) $row['after_json'], true);
        $this->assertSame('[redacted:credentials]', $after['value']);
        $this->assertSame('sms.auth', $after['setting']);
        $this->assertSame((string) $adminId, (string) $row['actor_wp_user_id']);
    }

    public function testSystemSetWithoutUserRecordsSystemActor(): void
    {
        $beforeCount = $this->auditCount('SETTING_UPDATE');

        App::settings()->set('booking.min_lead_hours', 4); // بدون updatedBy = سیستم

        $this->assertSame($beforeCount + 1, $this->auditCount('SETTING_UPDATE'));
        $row = $this->latestAuditRow('SETTING_UPDATE');
        $this->assertSame('system', $row['actor_role']);
        $this->assertNull($row['actor_wp_user_id']);
    }

    public function testChainRemainsVerifiableIncludingSettingAudits(): void
    {
        $adminId = $this->makeUser('cfg-admin4', 'administrator');
        App::settings()->set('queue.max_recalls', 2, $adminId);
        App::settings()->set('queue.max_recalls', 5, $adminId);

        $result = App::audit()->verifyChain(1000);
        $this->assertTrue($result['ok'], 'زنجیره Audit با رکوردهای Setting باید سالم بماند');
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

    private function auditCount(string $action): int
    {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $action
            )
        );

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestAuditRow(string $action): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE action = %s ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $action
            ),
            ARRAY_A
        );
        $this->assertNotNull($row, 'رکورد Audit باید وجود داشته باشد');

        return $row;
    }
}

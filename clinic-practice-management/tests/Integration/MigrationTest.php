<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-15 — Migration: اجرا، Idempotency، Rollback.
 *
 * نکته: DDL در MySQL Commit ضمني دارد؛ WP_UnitTestCase tables را Rollback نمی‌کند.
 * به همین دلیل migrate() در setUp (idempotent) و re-migrate در tearDown.
 */
final class MigrationTest extends WP_UnitTestCase
{
    /** آخرین Migration موجود در src/Migrations (با افزودن Migration جدید به‌روز شود). */
    private const LATEST_VERSION = '2026_09_09_0018';

    private const EXPECTED_TABLES = [
        'cpms_clinics', 'cpms_clinicians', 'cpms_patients', 'cpms_patient_user_links',
        'cpms_patient_merges', 'cpms_otp_tokens', 'cpms_idempotency_keys', 'cpms_schedule',
        'cpms_schedule_exceptions', 'cpms_schedule_slots', 'cpms_slot_holds', 'cpms_appointments',
        'cpms_visits', 'cpms_visit_status_history', 'cpms_clinical_notes', 'cpms_clinical_note_versions',
        'cpms_handwriting_documents', 'cpms_handwriting_pages', 'cpms_handwriting_page_versions',
        'cpms_ocr_jobs', 'cpms_prescriptions', 'cpms_prescription_items', 'cpms_drug_reference',
        'cpms_recommendations', 'cpms_follow_ups', 'cpms_medical_attachments', 'cpms_services',
        'cpms_invoices', 'cpms_invoice_items', 'cpms_payments', 'cpms_payment_adjustments',
        'cpms_notifications', 'cpms_jobs', 'cpms_audit_logs', 'cpms_operational_logs',
        'cpms_settings', 'cpms_rate_limits',
        'cpms_license_install', 'cpms_license_state',
        'cpms_organizations', 'cpms_locations', 'cpms_clinic_memberships',
        'cpms_membership_capabilities', 'cpms_membership_locations',
        'cpms_clinician_locations', 'cpms_patient_identities',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    protected function tearDown(): void
    {
        App::migrations()->migrate(); // بازیابی برای تست‌های بعد
        parent::tearDown();
    }

    public function testInitialMigrationCreatesAllTables(): void
    {
        global $wpdb;

        foreach (self::EXPECTED_TABLES as $short) {
            $table = App::db()->table($short);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->assertSame($table, $exists, "missing table: {$short}");
        }
        // آخرین Migration اعمال‌شده — با افزودن 0002/0003 به‌روز شد
        $this->assertSame(self::LATEST_VERSION, App::migrations()->currentVersion());
    }

    public function testDefaultClinicSeeded(): void
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, slug, timezone FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                1
            ),
            ARRAY_A
        );
        $this->assertNotNull($row);
        $this->assertSame('default', $row['slug']);
        $this->assertSame('Asia/Tehran', $row['timezone']);
    }

    public function testMigrateIsIdempotent(): void
    {
        $secondRun = App::migrations()->migrate();
        $this->assertSame([], $secondRun, 'اجرای دوم نباید Migration جدید داشته باشد');
    }

    public function testSlotUniqueConstraint(): void
    {
        // K-2 + Phase2/0014: UNIQUE (location_id, clinician_id, slot_date, slot_time)
        // — Location-scoped؛ دو مکانِ یک Clinician می‌توانند Slot همسان داشته باشند.
        $this->assertTrue($this->hasUnique('cpms_schedule_slots', ['location_id', 'clinician_id', 'slot_date', 'slot_time']));
        $this->assertFalse(
            $this->hasUnique('cpms_schedule_slots', ['clinician_id', 'slot_date', 'slot_time']),
            'UNIQUE سه‌ستونی قدیمی باید با تعریف Location-scoped جایگزین شده باشد'
        );
    }

    public function testPaymentIdempotencyUnique(): void
    {
        // K-3: UNIQUE (invoice_id, idempotency_key) — ضد Double Payment
        $this->assertTrue($this->hasUnique('cpms_payments', ['invoice_id', 'idempotency_key']));
    }

    public function testIdempotencyScopeUnique(): void
    {
        // F9 (بدهی F7 §9): دامنه یکتایی = همان چهار ستون کتاب‌keeping
        $this->assertTrue($this->hasUnique('cpms_idempotency_keys', ['key', 'endpoint', 'wp_user_id', 'context_id']));
        $this->assertFalse($this->hasUnique('cpms_idempotency_keys', ['key']), 'u_idem_key قدیمی باید حذف شده باشد');
    }

    public function testClinicianUserUnique(): void
    {
        // F9 (ADR-0027 Minor #12): یکپارچگی 1:1 Clinician ↔ WP User
        $this->assertTrue($this->hasUnique('cpms_clinicians', ['wp_user_id']));
    }


    /**
     * Phase 2 — بازگشت به نسخهٔ پایه قبل از تست‌های rollback قدیمی.
     * Migrationهای فاز ۲ (0010..0018) دارای down() کامل‌اند؛ این helper
     * عمداً دوتایی‌ها را نادیده می‌گیرد و تا $target برمی‌گردد.
     */
    private function rollbackTo(string $target): void
    {
        while (App::migrations()->currentVersion() !== $target) {
            $v = App::migrations()->rollbackOne();
            self::assertNotNull($v, 'rollbackOne نباید پیش از ' . $target . ' به null برسد.');
        }
    }

    /**
     * F9 — Upgrade Path: دیتابیسِ حالت قدیمی (0005: ستون‌های Nullable +
     * u_idem_key تک‌ستونی) → migrate() → نرمال‌سازی NULL→0 + ایندکس جدید؛
     * داده موجود حفظ می‌شود (بدون حذف/merge).
     */
    public function testUpgradePathFromLegacyIdempotencyState(): void
    {
        global $wpdb;
        $t = App::db()->table('cpms_idempotency_keys');

        // بازگشت به حالت پیش از 0006/0007 — ابتدا از روی Migrationهای فاز ۲
        // (0010..0018 دارای down() کامل‌اند) و سپس 0009/0008/0007/0006
        $this->rollbackTo('2026_09_07_0006');

        // شکل قدیمی: ستون Nullable + u_idem_key
        $col = $wpdb->get_row("SHOW COLUMNS FROM {$t} LIKE 'context_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame('YES', $col['Null']);
        $this->assertNotNull($wpdb->get_row("SHOW INDEX FROM {$t} WHERE Key_name = 'u_idem_key'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // داده legacy (context NULL — الگوی booking/confirm قبل از F9)
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$t} (`key`, clinic_id, wp_user_id, endpoint, context_id, status, created_at) VALUES (%s, 1, 7, 'booking/confirm', NULL, 0, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'legacy-key-0001',
                $now
            )
        );
        $legacyId = (int) $wpdb->insert_id;

        // Upgrade
        $applied = App::migrations()->migrate();
        $this->assertContains('2026_09_07_0006', $applied);
        $this->assertContains('2026_09_07_0007', $applied);

        // ستون‌ها NOT NULL + ایندکس جدید؛ u_idem_key حذف
        $col = $wpdb->get_row("SHOW COLUMNS FROM {$t} LIKE 'context_id'", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame('NO', $col['Null']);
        $this->assertNotNull($wpdb->get_row("SHOW INDEX FROM {$t} WHERE Key_name = 'u_idem_scope'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertNull($wpdb->get_row("SHOW INDEX FROM {$t} WHERE Key_name = 'u_idem_key'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // داده legacy حفظ + نرمال‌سازی NULL→0 (نه حذف، نه merge)
        $row = $wpdb->get_row($wpdb->prepare("SELECT wp_user_id, context_id FROM {$t} WHERE id = %d", $legacyId), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame(7, (int) $row['wp_user_id']);
        $this->assertSame(0, (int) $row['context_id']);

        // سرویسِ جدید ردیف legacy را پیدا می‌کند (کتاب‌keeping سالم پس از Upgrade)
        $svc = new \ClinicCore\Infrastructure\Security\Idempotency(App::db());
        $check = $svc->check('legacy-key-0001', 'booking/confirm', 7, null);
        $this->assertTrue($check['is_replay'], 'کلید PENDING قدیمی باید به‌عنوان in-flight شناسایی شود');
        $this->assertSame(409, $check['response_code']);
    }

    /**
     * F9 — Preflight: تکراری در دامنه هدف → Migration با خطا متوقف می‌شود؛
     * بدون تغییر داده (نه حذف، نه merge خودکار) و بدون ثبت version.
     */
    public function testPreflightAbortsOnDuplicateIdempotencyScope(): void
    {
        global $wpdb;
        $t = App::db()->table('cpms_idempotency_keys');

        // شبیه‌سازی داده معیوب: u_idem_key حذف + دو ردیف هم‌دامنه (مثلاً حاصل Restore/Import)
        $this->rollbackTo('2026_09_07_0006');
        $wpdb->query("ALTER TABLE {$t} DROP INDEX `u_idem_key`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $now = App::db()->nowUtcSql();
        foreach ([11, 12] as $i) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$t} (`key`, clinic_id, wp_user_id, endpoint, context_id, status, created_at) VALUES (%s, 1, 7, 'booking/confirm', NULL, 0, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    'corrupt-key-' . $i,
                    $now
                )
            );
        }
        // همان کلید در هر دو ردیف → پس از نرمال‌سازی در دامنه هدف تکراری
        $wpdb->query($wpdb->prepare("UPDATE {$t} SET `key` = %s WHERE `key` LIKE 'corrupt-key-%%'", 'corrupt-key-same')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        try {
            try {
                App::migrations()->migrate();
                $this->fail('Preflight باید Migration را متوقف می‌کرد');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('duplicate idempotency rows', $e->getMessage());
            }

            // بدون تغییر داده + بدون ثبت version → 0006 هنوز اعمال‌نشده
            $applied = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . App::db()->table('cpms_schema_migrations') . " WHERE version = '2026_09_07_0006'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->assertSame(0, $applied);
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE `key` = 'corrupt-key-same'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->assertSame(2, $count, 'هیچ ردیفی حذف/merge نشده است');
        } finally {
            // پاک‌سازی دستی (مثل سناریوی واقعی) — حتی اگر assertion شکست بخورد تا
            // tearDown/migrate سوئیچ بعدی سالم بماند (DDL تراکنش تست را می‌شکند)
            $wpdb->query("DELETE FROM {$t} WHERE `key` = 'corrupt-key-same' AND id > (SELECT min_id FROM (SELECT MIN(id) min_id FROM {$t} WHERE `key` = 'corrupt-key-same') x)"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $applied = App::migrations()->migrate();
        $this->assertContains('2026_09_07_0006', $applied);
    }

    /**
     * F9 — Preflight ایندکس Clinician: تکراری wp_user_id → توقف بدون تغییر.
     */
    public function testPreflightAbortsOnDuplicateClinicianUser(): void
    {
        global $wpdb;
        $t = App::db()->table('cpms_clinicians');
        $now = App::db()->nowUtcSql();

        // شبیه‌سازی نصب قدیمی: ایندکس نیست + دو پزشک به یک کاربر متصل
        $wpdb->query("ALTER TABLE {$t} DROP INDEX `u_clinician_user`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->delete(App::db()->table('cpms_schema_migrations'), ['version' => '2026_09_07_0007']);

        $uid = (int) wp_create_user('mig_dup_doctor', 'pass-12345', 'mig_dup@test.local');
        $u = get_userdata($uid);
        if ($u !== false) {
            $u->set_role('cpms_doctor');
        }
        foreach ([1, 2] as $i) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$t} (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (1, %s, %d, 1, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    'Dr Dup ' . $i,
                    $uid,
                    $now,
                    $now
                )
            );
        }

        try {
            try {
                App::migrations()->migrate();
                $this->fail('Preflight باید Migration را متوقف می‌کرد');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('share the same wp_user_id', $e->getMessage());
            }

            // بدون تغییر داده
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE wp_user_id = %d", $uid)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $this->assertSame(2, $count);
            $this->assertNull($wpdb->get_row("SHOW INDEX FROM {$t} WHERE Key_name = 'u_clinician_user'")); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        } finally {
            // رفع دستی (NULL کردن پیوند اضافی) — حتی اگر assertion شکست بخورد
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET wp_user_id = NULL WHERE wp_user_id = %d AND full_name = 'Dr Dup 2'", $uid)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $applied = App::migrations()->migrate();
        $this->assertContains('2026_09_07_0007', $applied);
    }

    /**
     * F1-2 — Fail-loud: خطای SQL در up() → Migration واقعاً شکست می‌خورد؛
     * version ثبت نمی‌شود و Migrationهای بعدی اجرا نمی‌شوند.
     * (پیش از رفع، خطای SQL soft-fail می‌شد و Migration «موفق» ثبت می‌شد —
     * همان کلاس خطایی که در Migration 0009 رخ داد: ALTER بی‌صدا رد شد
     * ولی version ثبت شد.)
     */
    public function testFailingMigrationAbortsAndIsNotRecorded(): void
    {
        $dir = sys_get_temp_dir() . '/cpms_mig_fail_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($dir);

        file_put_contents($dir . '/2099_01_01_0001_bad_migration.php', <<<'PHP'
<?php
declare(strict_types=1);
use ClinicCore\Infrastructure\Db\CpmsDb;
return [
    'version' => '2099_01_01_0001',
    'description' => 'F1-2 regression: deliberately failing migration',
    'up' => function (CpmsDb $db): void {
        $db->query('SELECT * FROM cpms_table_that_does_not_exist');
    },
    'down' => function (CpmsDb $db): void {},
];
PHP);
        file_put_contents($dir . '/2099_01_01_0002_good_migration.php', <<<'PHP'
<?php
declare(strict_types=1);
use ClinicCore\Infrastructure\Db\CpmsDb;
return [
    'version' => '2099_01_01_0002',
    'description' => 'F1-2 regression: good migration after the failing one',
    'up' => function (CpmsDb $db): void {},
    'down' => function (CpmsDb $db): void {},
];
PHP);

        $runner = new \ClinicCore\Migrations\MigrationRunner(App::db(), App::op(), $dir);

        try {
            try {
                $runner->migrate();
                $this->fail('Migration دارای خطای SQL باید RuntimeException می‌داد');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('SQL error', $e->getMessage());
            }

            $applied = $runner->applied();
            $this->assertNotContains('2099_01_01_0001', $applied, 'version Migration شکست‌خورده نباید ثبت شود');
            $this->assertNotContains('2099_01_01_0002', $applied, 'Migrationهای پس از Migration شکست‌خورده نباید اجرا شوند');
        } finally {
            foreach (glob($dir . '/*.php') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    private function hasUnique(string $short, array $columns): bool
    {
        global $wpdb;
        $table = App::db()->table($short);
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
        foreach ($indexes as $idx) {
            $col = $idx['Column_name'];
            $name = $idx['Key_name'];
            $isUnique = (int) $idx['Non_unique'] === 0 && $name !== 'PRIMARY';
            if (!$isUnique) {
                continue;
            }
            // جمع کلیدهای همین index
            $cols = [];
            foreach ($indexes as $i2) {
                if ($i2['Key_name'] === $name) {
                    $cols[$i2['Seq_in_index']] = $i2['Column_name'];
                }
            }
            ksort($cols);
            if (array_values($cols) === $columns) {
                return true;
            }
        }

        return false;
    }
}

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
        $this->assertSame('2026_09_05_0001', App::migrations()->currentVersion());
    }

    public function testDefaultClinicSeeded(): void
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, slug, timezone FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
        // K-2: UNIQUE (clinician_id, slot_date, slot_time) — تکراری‌زنی تولید Slot
        $this->assertTrue($this->hasUnique('cpms_schedule_slots', ['clinician_id', 'slot_date', 'slot_time']));
    }

    public function testPaymentIdempotencyUnique(): void
    {
        // K-3: UNIQUE (invoice_id, idempotency_key) — ضد Double Payment
        $this->assertTrue($this->hasUnique('cpms_payments', ['invoice_id', 'idempotency_key']));
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

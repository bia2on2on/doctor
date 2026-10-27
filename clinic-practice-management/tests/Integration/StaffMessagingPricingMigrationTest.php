<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Iran V1 migration 0030 — upgrade safety on MySQL 8 / MariaDB 11.4.
 *
 * Additive-only: six new tables, no change to any pre-existing financial,
 * clinical, visit or membership column. Repeated migrate() is a no-op and a
 * real 0029 → 0030 upgrade preserves existing invoice/payment rows byte-for-byte.
 */
final class StaffMessagingPricingMigrationTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const VERSION = '2026_10_27_0030';

    private const TABLES = [
        'cpms_message_threads',
        'cpms_message_participants',
        'cpms_messages',
        'cpms_doctor_consultation_fees',
        'cpms_visit_consultation_fees',
        'cpms_visit_charge_items',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
    }

    protected function tearDown(): void
    {
        try {
            $this->withRealTables(static fn (): array => App::migrations()->migrate());
        } finally {
            parent::tearDown();
        }
    }

    public function testSixAdditiveTablesExistWithPortableColumnsAndNoForeignKeys(): void
    {
        $db = App::db();
        self::assertSame(self::VERSION, App::migrations()->currentVersion());
        self::assertSame([], $this->withRealTables(static fn (): array => App::migrations()->migrate()));

        foreach (self::TABLES as $short) {
            $table = $db->table($short);
            $info  = $db->fetchRow(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                [$table]
            );
            self::assertNotNull($info, $short . ' must exist as a real table');
            self::assertSame('InnoDB', (string) $info['ENGINE']);
            self::assertStringStartsWith('utf8mb4_', (string) $info['TABLE_COLLATION']);
        }

        $columns = static function (string $short) use ($db): array {
            return array_column($db->fetchAll(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
                [$db->table($short)]
            ), 'COLUMN_NAME');
        };
        self::assertContains('organization_id', $columns('cpms_message_threads'));
        self::assertContains('location_id', $columns('cpms_message_threads'));
        self::assertContains('last_read_message_id', $columns('cpms_message_participants'));
        self::assertContains('body', $columns('cpms_messages'));
        self::assertContains('fee', $columns('cpms_doctor_consultation_fees'));
        self::assertContains('quantity', $columns('cpms_visit_charge_items'));
        self::assertContains('invoiced_invoice_id', $columns('cpms_visit_charge_items'));

        // The DDL is portable under the WP test-suite temporary-table rewrite:
        // no FOREIGN KEY is declared on the six new tables.
        foreach (self::TABLES as $short) {
            $fk = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS'
                . " WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$db->table($short)]
            );
            self::assertSame(0, $fk, $short . ' must not add an engine-specific FK');
        }
    }

    public function testUpgradeFrom0029PreservesExistingFinancialRows(): void
    {
        global $wpdb;
        $db  = App::db();
        $now = $db->nowUtcSql();

        // A pre-0030 installation with an already settled invoice and payment.
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_clinicians', [
            'clinic_id' => 1, 'full_name' => 'پزشک پیش از ارتقا', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $clinician = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_patients', [
            'clinic_id' => 1, 'mrn' => 'IV1-MIG-' . bin2hex(random_bytes(4)),
            'first_name' => 'پیش', 'last_name' => 'ازارتقا', 'mobile' => '09120000000',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]));
        $patient = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_visits', [
            'clinic_id' => 1, 'location_id' => (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_locations') . ' ORDER BY id'),
            'clinician_id' => $clinician,
            'patient_id' => $patient, 'status' => 'paid', 'source' => 'walk_in',
            'visit_date' => gmdate('Y-m-d'), 'check_in_at' => $now, 'active' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $visit = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoices', [
            'clinic_id' => 1, 'invoice_number' => 'INV-MIG-' . bin2hex(random_bytes(4)),
            'patient_id' => $patient, 'visit_id' => $visit, 'status' => 'paid',
            'subtotal' => '250000.00', 'total' => '250000.00', 'currency' => 'IRR',
            'paid_amount' => '250000.00', 'balance' => '0.00', 'issued_by_wp_user_id' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        $invoice = (int) $wpdb->insert_id;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_invoice_items', [
            'invoice_id' => $invoice, 'description' => 'ویزیت قدیمی', 'quantity' => '1.00',
            'unit_price' => '250000.00', 'amount' => '250000.00',
        ]));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'cpms_payments', [
            'clinic_id' => 1, 'payment_number' => 'PAY-MIG-' . bin2hex(random_bytes(4)),
            'invoice_id' => $invoice, 'patient_id' => $patient, 'amount' => '250000.00',
            'method' => 'cash', 'idempotency_key' => 'iv1-mig-' . bin2hex(random_bytes(4)),
            'status' => 'captured', 'paid_at' => $now, 'received_by_wp_user_id' => 1, 'created_at' => $now,
        ]));

        $before = [
            'invoice' => $db->fetchRow('SELECT * FROM ' . $db->table('cpms_invoices') . ' WHERE id = %d', [$invoice]),
            'item'    => $db->fetchRow('SELECT * FROM ' . $db->table('cpms_invoice_items') . ' WHERE invoice_id = %d', [$invoice]),
            'payment' => $db->fetchRow('SELECT * FROM ' . $db->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoice]),
        ];

        $this->withRealTables(function () use ($db): void {
            // Simulate the exact 0029 baseline: no 0030 marker, no 0030 tables.
            $db->query('DELETE FROM ' . $db->table('cpms_schema_migrations') . ' WHERE version = %s', [self::VERSION]);
            foreach (array_reverse(self::TABLES) as $short) {
                $db->query('DROP TABLE IF EXISTS ' . $db->table($short));
            }
            self::assertSame('2026_10_18_0029', App::migrations()->currentVersion());

            $applied = App::migrations()->migrate();
            self::assertContains(self::VERSION, $applied, '0030 applies forward-only from 0029');
            self::assertSame([], App::migrations()->migrate(), 'repeat migration is a no-op');
        });

        foreach (self::TABLES as $short) {
            self::assertNotNull($db->fetchRow(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                [$db->table($short)]
            ), $short . ' exists after upgrade');
        }
        self::assertSame($before['invoice'], $db->fetchRow('SELECT * FROM ' . $db->table('cpms_invoices') . ' WHERE id = %d', [$invoice]));
        self::assertSame($before['item'], $db->fetchRow('SELECT * FROM ' . $db->table('cpms_invoice_items') . ' WHERE invoice_id = %d', [$invoice]));
        self::assertSame($before['payment'], $db->fetchRow('SELECT * FROM ' . $db->table('cpms_payments') . ' WHERE invoice_id = %d', [$invoice]));
    }
}

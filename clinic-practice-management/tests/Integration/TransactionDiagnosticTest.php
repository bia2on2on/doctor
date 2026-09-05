<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * تست تشخیصی موقت (F3 CI) — وضعیت تشخیص تراکنشِ تو در تو را در محیط واقعی
 * WP Test Suite چاپ می‌کند تا ریشه نشت Fixture بین تست‌ها مشخص شود.
 * خروجی STDERR در لاگ CI (بخش TAIL کامنت PR) ظاهر می‌شود.
 */
final class TransactionDiagnosticTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
    }

    public function testNestedTransactionState(): void
    {
        global $wpdb;

        $this->dump('after set_up');

        // مطابق BookingFlowTest::setUp — fixture INSERT روی جدول InnoDB واقعی
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, "Diag", "Diag", %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'MR-DIAG-0001',
                '09129990001',
                $now,
                $now
            )
        );
        $this->dump('after fixture insert');

        App::db()->transactional(function (): bool {
            $this->dump('inside service tx (savepoint expected)');

            return true;
        });
        $this->dump('after service tx');

        App::audit()->log('DIAG_EVENT', ['wp_user_id' => 1, 'role' => 'system'], 'diag', 1, null, null, null);
        $this->dump('after audit log (nested transactional)');

        $this->assertTrue(true);
    }

    private function dump(string $stage): void
    {
        global $wpdb;

        $mysqliTx = ($wpdb->dbh instanceof \mysqli) ? var_export($wpdb->dbh->in_transaction, true) : 'not-mysqli';
        $innodbTrx = $wpdb->get_var(
            'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()'
        );

        $method = new \ReflectionMethod(App::db(), 'inTransaction');
        $method->setAccessible(true);
        $detected = var_export($method->invoke(App::db()), true);

        fwrite(
            STDERR,
            "DIAG[{$stage}] mysqli.in_transaction={$mysqliTx} innodb_trx=" . var_export($innodbTrx, true)
            . " CpmsDb::inTransaction={$detected}\n"
        );
    }
}

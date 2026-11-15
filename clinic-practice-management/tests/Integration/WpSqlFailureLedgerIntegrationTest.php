<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Support\WpSqlFailureLedger;
use WP_UnitTestCase;

/**
 * Real-MySQL publication-boundary contract for WpSqlFailureLedger's table facts.
 *
 * The Unit suite covers the ledger's fail-closed formatting with synthetic
 * fixtures. What only a real, migrated schema can prove — and what this test
 * proves against the Integration job's actual MySQL service — is that the
 * `CPMS_SQL_FAILURE_LEDGER_TABLE` lines carry exactly the ledger's KNOWN_TABLES
 * with boolean `real_exists`/`name_visible` facts and nothing else: no row
 * counts, no values, no identifiers beyond the fixed short table names.
 */
final class WpSqlFailureLedgerIntegrationTest extends WP_UnitTestCase
{
    /** @return list<string> */
    private function buildLedgerLines($wpdb): array
    {
        $method = new \ReflectionMethod(WpSqlFailureLedger::class, 'buildLines');
        $method->setAccessible(true);
        /** @var list<string> $lines */
        $lines = $method->invoke(null, $wpdb);

        return $lines;
    }

    public function testTableFactsAgainstRealMigratedSchemaPublishBooleansOnly(): void
    {
        App::migrations()->migrate();

        global $wpdb;
        self::assertInstanceOf(\mysqli::class, $wpdb->dbh, 'Integration runs require a live mysqli connection.');

        $lines = $this->buildLedgerLines($wpdb);
        $tableLines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_starts_with($line, 'CPMS_SQL_FAILURE_LEDGER_TABLE ')
        ));

        $this->assertCount(3, $tableLines, 'exactly the ledger KNOWN_TABLES must be published');

        $facts = [];
        foreach ($tableLines as $line) {
            // Whole-line fullmatch: prefix + one JSON object, no trailing bytes.
            $this->assertMatchesRegularExpression(
                '/\ACPMS_SQL_FAILURE_LEDGER_TABLE \{"table":"(cpms_custom_role_defs|cpms_custom_role_capabilities|cpms_clinics)","real_exists":(true|false),"name_visible":(true|false)\}\z/',
                $line,
                'table fact line must match the fixed schema exactly'
            );
            $decoded = json_decode(substr($line, strlen('CPMS_SQL_FAILURE_LEDGER_TABLE ')), true);
            $this->assertIsArray($decoded);
            $facts[$decoded['table']] = $decoded;
        }

        $this->assertSame(
            ['cpms_custom_role_defs', 'cpms_custom_role_capabilities', 'cpms_clinics'],
            array_keys($facts),
            'facts must cover exactly the KNOWN_TABLES, in ledger order'
        );

        foreach ($facts as $short => $fact) {
            $this->assertSame(['table', 'real_exists', 'name_visible'], array_keys($fact));
            $this->assertIsBool($fact['real_exists'], $short . ' real_exists must be a boolean');
            $this->assertIsBool($fact['name_visible'], $short . ' name_visible must be a boolean');
            $this->assertTrue($fact['real_exists'], $short . ' must exist after migrations');
            $this->assertTrue($fact['name_visible'], $short . ' must be visible through SHOW TABLES');

            // Independent cross-check against information_schema for the ACTUAL
            // prefixed name the ledger queried.
            $full = $wpdb->prefix . $short;
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $full
            ));
            $this->assertSame(1, $count, $full . ' must exist exactly once');
        }

        // No row counts, no values, no identifiers beyond the fixed names.
        foreach ($lines as $line) {
            if (str_starts_with($line, 'CPMS_SQL_FAILURE_LEDGER_TABLE ')) {
                $this->assertStringNotContainsString('rows', $line);
                $this->assertStringNotContainsString('count', $line);
                $this->assertStringNotContainsString('SELECT', $line);
            }
        }
    }
}

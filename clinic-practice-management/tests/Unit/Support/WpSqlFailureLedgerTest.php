<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Support;

use ClinicCore\Tests\Support\WpSqlFailureLedger;
use PHPUnit\Framework\TestCase;

/**
 * Negative control: the publication boundary of WpSqlFailureLedger.
 *
 * Synthetic fixtures only — every "secret" below is a canary that must NEVER
 * appear in the ledger output, whatever shape the failure takes. The boundary
 * is fail-closed: a field that fails its validator is dropped, never rewritten
 * into "safe-ish" text.
 */
final class WpSqlFailureLedgerTest extends TestCase
{
    private const CANARY = 'patient@example.com';
    private const CANARY_SQL = 'DROP TABLE patients';
    private const CANARY_UNICODE = 'بیمارِ علی ۱۲۳';
    private const CANARY_ID = '987654321';
    private const CANARY_SECRET = 'secret-marker-001';

    /** @var array<string, mixed> */
    private array $savedState = [];

    protected function setUp(): void
    {
        $this->savedState = [
            'signatures' => self::staticValue('signatures'),
            'failures' => self::staticValue('failures'),
            'dropped' => self::staticValue('dropped'),
            'sequence' => self::staticValue('sequence'),
            'inside' => self::staticValue('inside'),
            'EZSQL_ERROR' => $GLOBALS['EZSQL_ERROR'] ?? null,
            'wpdb' => $GLOBALS['wpdb'] ?? null,
        ];
        self::setStaticValue('signatures', []);
        self::setStaticValue('failures', 0);
        self::setStaticValue('dropped', 0);
        self::setStaticValue('sequence', 0);
        self::setStaticValue('inside', false);
    }

    protected function tearDown(): void
    {
        self::setStaticValue('signatures', $this->savedState['signatures']);
        self::setStaticValue('failures', $this->savedState['failures']);
        self::setStaticValue('dropped', $this->savedState['dropped']);
        self::setStaticValue('sequence', $this->savedState['sequence']);
        self::setStaticValue('inside', $this->savedState['inside']);
        if ($this->savedState['EZSQL_ERROR'] === null) {
            unset($GLOBALS['EZSQL_ERROR']);
        } else {
            $GLOBALS['EZSQL_ERROR'] = $this->savedState['EZSQL_ERROR'];
        }
        if ($this->savedState['wpdb'] === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->savedState['wpdb'];
        }
    }

    public function testObserveRecordsNothingWhenThereIsNoError(): void
    {
        $fake = self::fakeWpdb('', 'SELECT 1');
        $this->useWpdb($fake, []);

        $this->assertSame('SELECT 1', WpSqlFailureLedger::observe('SELECT 1'));
        $this->assertSame(0, self::staticValue('failures'));
    }

    public function testDuplicateFailuresAggregateIntoOneSignature(): void
    {
        $fake = self::fakeWpdb("Deadlock found when trying to get lock; try restarting transaction", 'SELECT 1');
        $this->useWpdb($fake, [['query' => 'SELECT 1', 'error_str' => $fake->last_error]]);

        WpSqlFailureLedger::observe('SELECT 1');
        WpSqlFailureLedger::observe('SELECT 1');

        $this->assertSame(2, self::staticValue('failures'));
        $this->assertCount(1, self::staticValue('signatures'));
        $rows = array_values(self::staticValue('signatures'));
        $this->assertSame(2, $rows[0]['count']);
    }

    /**
     * @return list<string>
     */
    private function buildLedgerLines($wpdb): array
    {
        $method = new \ReflectionMethod(WpSqlFailureLedger::class, 'buildLines');
        $method->setAccessible(true);
        /** @var list<string> $lines */
        $lines = $method->invoke(null, $wpdb);

        return $lines;
    }

    public function testLedgerLinesContainNoDriverTextOrFixtureValues(): void
    {
        $error = "Duplicate entry 'patient@example.com' for key 'cpms_clinics.mrn' /* " . self::CANARY_SQL . ' */';
        $fake = self::fakeWpdb($error, "INSERT INTO `wptests_cpms_clinics` (mrn) VALUES ('patient@example.com')");
        $this->useWpdb($fake, [['query' => $fake->last_query, 'error_str' => $error]]);

        WpSqlFailureLedger::observe($fake->last_query);
        $lines = $this->buildLedgerLines($fake);

        $this->assertNotEmpty($lines);
        $joined = implode("\n", $lines);
        $this->assertStringNotContainsString(self::CANARY, $joined);
        $this->assertStringNotContainsString(self::CANARY_SQL, $joined);
        $this->assertStringNotContainsString(self::CANARY_UNICODE, $joined);
        $this->assertStringNotContainsString(self::CANARY_ID, $joined);
        foreach ($lines as $line) {
            $this->assertSame($line, str_replace(["\n", "\r"], '', $line), 'ledger line must be single-line');
        }
        $ledger = $this->ledgerJsonLines($lines, 'CPMS_SQL_FAILURE_LEDGER ');
        $this->assertCount(1, $ledger);
        $this->assertSame('duplicate_entry', $ledger[0]['category']);
        $this->assertSame('cpms_clinics', $ledger[0]['table']);
        $this->assertSame('INSERT', $ledger[0]['op']);
        $this->assertArrayNotHasKey('message', $ledger[0]);
        $this->assertArrayNotHasKey('clinic_id', $ledger[0]);
        $this->assertArrayNotHasKey('role_key', $ledger[0]);
        $this->assertArrayNotHasKey('sql', $ledger[0]);
    }

    public function testMultilineUnicodeAndQuotedMessageStaysOutOfOutput(): void
    {
        $error = "Deadlock found\nwith 'quoted " . self::CANARY . "'\r\nand " . self::CANARY_UNICODE . "\0binary";
        $fake = self::fakeWpdb($error, "UPDATE `wptests_cpms_clinics` SET note = '" . self::CANARY . "' WHERE id = 987654321");
        $this->useWpdb($fake, [['query' => $fake->last_query, 'error_str' => $error]]);

        WpSqlFailureLedger::observe($fake->last_query);
        $joined = implode("\n", $this->buildLedgerLines($fake));

        $this->assertStringNotContainsString('patient@example.com', $joined);
        $this->assertStringNotContainsString(self::CANARY_UNICODE, $joined);
        $this->assertStringNotContainsString(self::CANARY_ID, $joined);
        $this->assertStringNotContainsString(self::CANARY_SQL, $joined);
    }

    public function testMalformedEzsqlEntriesNeverCrashAndStayBounded(): void
    {
        $fixtures = [
            'null entry' => [null],
            'non-string query and error_str' => [['query' => 12345, 'error_str' => ['not', 'a', 'string']]],
            'empty error_str' => [['query' => 'SELECT 1', 'error_str' => '']],
            'missing keys' => [[]],
            'scalar entry' => ['a string instead of array'],
            'zero-length statement' => [['query' => '', 'error_str' => 'MySQL server has gone away']],
        ];
        foreach ($fixtures as $label => $ezsql) {
            self::setStaticValue('signatures', []);
            self::setStaticValue('failures', 0);
            $fake = self::fakeWpdb('MySQL server has gone away', 'SELECT 1');
            $this->useWpdb($fake, $ezsql);
            WpSqlFailureLedger::observe('SELECT 1');
            $lines = $this->buildLedgerLines($fake);
            $joined = implode("\n", $lines);
            $this->assertStringNotContainsString('MySQL server has gone away', $joined, $label . ' must not leak');
        }
    }

    public function testLegacyInputWithoutEzsqlGlobal(): void
    {
        unset($GLOBALS['EZSQL_ERROR']);
        $fake = self::fakeWpdb('Lock wait timeout exceeded; try restarting transaction', 'SELECT 1');
        $GLOBALS['wpdb'] = $fake;

        WpSqlFailureLedger::observe('SELECT 1');
        $lines = $this->buildLedgerLines($fake);

        $ledger = $this->ledgerJsonLines($lines, 'CPMS_SQL_FAILURE_LEDGER ');
        $this->assertNotEmpty($ledger);
        $this->assertSame('lock', $ledger[0]['category']);
    }

    public function testDirectFormatterBypassWithHostileRowIsFailClosed(): void
    {
        $hostile = [
            'seq' => 99999,
            'count' => -5,
            'category' => 'evil; ' . self::CANARY_SQL,
            'table' => "patients;-- " . self::CANARY,
            'op' => "INSERT\nINTO " . self::CANARY_SQL,
            'mysql_errno' => 'not-an-errno',
            'sqlstate' => "41; DROP TABLE patients",
            'test' => "ClinicCore\\Tests\\AppTest::testX\nevil " . self::CANARY_SECRET,
            'product' => "../../etc/passwd::read " . self::CANARY_UNICODE,
            'focus' => 'yes',
        ];
        self::setStaticValue('signatures', ['injected|row' => $hostile]);

        $lines = $this->buildLedgerLines(null);
        $joined = implode("\n", $lines);
        $this->assertStringNotContainsString(self::CANARY, $joined);
        $this->assertStringNotContainsString(self::CANARY_SQL, $joined);
        $this->assertStringNotContainsString(self::CANARY_SECRET, $joined);
        $this->assertStringNotContainsString(self::CANARY_UNICODE, $joined);

        $ledger = $this->ledgerJsonLines($lines, 'CPMS_SQL_FAILURE_LEDGER ');
        $this->assertCount(1, $ledger);
        $row = $ledger[0];
        $this->assertSame('other', $row['category']);
        $this->assertSame('unknown', $row['table']);
        $this->assertSame('OTHER', $row['op']);
        $this->assertSame('unavailable', $row['mysql_errno']);
        $this->assertNull($row['sqlstate']);
        $this->assertSame('(unknown)', $row['test']);
        $this->assertSame('(unknown)', $row['product']);
        $this->assertTrue($row['focus']);
    }

    public function testInjectedCategoriesFallBackToOtherInSummary(): void
    {
        $row = [
            'seq' => 1,
            'count' => 3,
            'category' => 'not_a_real_category',
            'table' => 'unknown',
            'op' => 'OTHER',
            'mysql_errno' => 'unavailable',
            'sqlstate' => null,
            'test' => '(unknown)',
            'product' => '(unknown)',
            'focus' => false,
        ];
        self::setStaticValue('signatures', ['sig' => $row]);

        $lines = $this->buildLedgerLines(null);
        $summary = $this->ledgerJsonLines($lines, 'CPMS_SQL_FAILURE_LEDGER_SUMMARY ');
        $this->assertCount(1, $summary);
        $this->assertSame(['other' => 3], $summary[0]['by_category']);
    }

    public function testMaxSignaturesCapIsEnforced(): void
    {
        $fake = self::fakeWpdb('Duplicate entry for key', 'SELECT 1');
        // Distinct fingerprint per message → distinct signatures.
        for ($i = 0; $i < 65; $i++) {
            $error = 'Duplicate entry for key ' . $i . " " . str_repeat('x', 8);
            $fake->last_error = $error;
            $this->useWpdb($fake, [['query' => 'SELECT 1', 'error_str' => $error]]);
            WpSqlFailureLedger::observe('SELECT 1');
        }
        $this->assertSame(65, self::staticValue('failures'));
        $this->assertCount(60, self::staticValue('signatures'));
        $this->assertSame(5, self::staticValue('dropped'));
    }

    public function testNoTableLinesWithoutDatabaseHandle(): void
    {
        $fake = self::fakeWpdb('Lock wait timeout exceeded', 'SELECT 1');
        $this->useWpdb($fake, []);

        WpSqlFailureLedger::observe('SELECT 1');
        $lines = $this->buildLedgerLines(null);
        foreach ($lines as $line) {
            $this->assertFalse(
                str_starts_with($line, 'CPMS_SQL_FAILURE_LEDGER_TABLE '),
                'Table fact lines must not be emitted without a database handle.'
            );
        }
    }

    public function testTableFactsPublishOnlyBooleansWhenDbExists(): void
    {
        if (!class_exists('\mysqli')) {
            $this->markTestSkipped('mysqli extension not available.');
        }
        try {
            // PHP 8 mysqli throws mysqli_sql_exception on connect failure by
            // default, so a missing server would surface as an error rather
            // than a skipped test — catch it and skip instead.
            $mysqli = new \mysqli('127.0.0.1', 'root', 'root', 'wordpress_test', 3306);
        } catch (\Throwable) {
            $this->markTestSkipped('No MySQL service in this environment.');
        }
        $fake = self::fakeWpdb('Duplicate entry for key', 'SELECT 1');
        $fake->dbh = $mysqli;
        $this->useWpdb($fake, []);

        WpSqlFailureLedger::observe('SELECT 1');
        $lines = $this->buildLedgerLines($fake);
        $facts = $this->ledgerJsonLines($lines, 'CPMS_SQL_FAILURE_LEDGER_TABLE ');
        $mysqli->close();

        $this->assertNotEmpty($facts);
        foreach ($facts as $fact) {
            $this->assertSame(['table', 'real_exists', 'name_visible'], array_keys($fact));
            $this->assertIsBool($fact['real_exists']);
            $this->assertIsBool($fact['name_visible']);
        }
    }

    public function testJsonLedgerLinesDecodeCleanly(): void
    {
        $fake = self::fakeWpdb("Duplicate entry 'x\ny' for key", 'SELECT 1');
        $this->useWpdb($fake, [['query' => 'SELECT 1', 'error_str' => $fake->last_error]]);

        WpSqlFailureLedger::observe('SELECT 1');
        foreach ($this->buildLedgerLines($fake) as $line) {
            $space = strpos($line, ' ');
            if ($space === false) {
                // Spaceless fixed terminator — not a JSON payload line.
                $this->assertSame('CPMS_SQL_FAILURE_LEDGER_END', $line);
                continue;
            }
            $prefix = substr($line, 0, $space);
            if ($prefix === 'CPMS_SQL_FAILURE_LEDGER'
                || $prefix === 'CPMS_SQL_FAILURE_LEDGER_SUMMARY'
                || $prefix === 'CPMS_SQL_FAILURE_LEDGER_TABLE') {
                $decoded = json_decode(substr($line, $space + 1), true);
                $this->assertIsArray($decoded, 'ledger JSON must decode: ' . $line);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerJsonLines(array $lines, string $prefix): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, $prefix)) {
                continue;
            }
            $decoded = json_decode(substr($line, strlen($prefix)), true);
            $this->assertIsArray($decoded);
            $out[] = $decoded;
        }

        return $out;
    }

    private static function fakeWpdb(string $lastError, string $lastQuery): object
    {
        $fake = new class() {
            public string $last_error = '';
            public string $last_query = '';
            public $dbh = null;
            public string $prefix = 'wptests_';
        };
        $fake->last_error = $lastError;
        $fake->last_query = $lastQuery;

        return $fake;
    }

    /**
     * @param object $fake
     * @param list<mixed>|null $ezsql
     */
    private function useWpdb($fake, $ezsql): void
    {
        $GLOBALS['wpdb'] = $fake;
        if ($ezsql === null) {
            unset($GLOBALS['EZSQL_ERROR']);
            return;
        }
        $GLOBALS['EZSQL_ERROR'] = array_values($ezsql);
    }

    private static function staticValue(string $name): mixed
    {
        $property = new \ReflectionProperty(WpSqlFailureLedger::class, $name);
        $property->setAccessible(true);

        return $property->getValue();
    }

    private static function setStaticValue(string $name, mixed $value): void
    {
        $property = new \ReflectionProperty(WpSqlFailureLedger::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

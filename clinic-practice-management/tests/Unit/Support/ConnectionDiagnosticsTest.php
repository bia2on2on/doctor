<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Support;

use ClinicCore\Tests\Support\ConnectionDiagnostics;
use PHPUnit\Framework\TestCase;

/**
 * Hostile-input boundary tests for ConnectionDiagnostics (test-only diagnostics).
 *
 * Synthetic fixtures only — every canary must never reach the output. The
 * query-filter chain is SOURCE-VERIFIED: identifiers declared in this
 * repository pass; everything else (including valid-shaped canaries, WP-core
 * style callbacks and unknown class::method pairs) is dropped and counted by
 * cause (chain_dropped_shape / chain_dropped_unverified).
 */
final class ConnectionDiagnosticsTest extends TestCase
{
    private const CANARY = 'wp_dump_patient_ssn_420042';
    private const CANARY_METHOD = 'leakPatientSsnCanaryXYZ';

    /** @param array<int, array<string, mixed>> $callbacks */
    private function setWpFilter(array $callbacks): void
    {
        $hook = new class {
            /** @var array<int, mixed> */
            public array $callbacks = [];
        };
        $hook->callbacks = $callbacks;
        $GLOBALS['wp_filter'] = ['query' => $hook];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_filter']);
    }

    public function testSourceVerifiedBareFunctionPassesAndCanaryDoesNot(): void
    {
        // cpms_m0002_column_exists is declared in src/Migrations/… — source-verified.
        $this->setWpFilter([10 => [['function' => 'cpms_m0002_column_exists']]]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertContains('10:cpms_m0002_column_exists', $out['chain']);
        $this->assertSame(0, $out['chain_dropped_unverified']);
        $this->assertSame(0, $out['chain_dropped_shape']);

        $this->setWpFilter([10 => [['function' => self::CANARY]]]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertNotContains('10:' . self::CANARY, $out['chain']);
        $this->assertSame(1, $out['chain_dropped_unverified']);
    }

    public function testSourceVerifiedClassMethodPairPassesAndCanaryDoesNot(): void
    {
        $this->setWpFilter([10 => [
            ['function' => ['ClinicCore\\Application\\Booking\\BookingService', 'availability']],
        ]]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertContains('10:ClinicCore\\Application\\Booking\\BookingService::availability', $out['chain']);

        $this->setWpFilter([10 => [
            ['function' => ['ClinicCore\\Application\\Booking\\BookingService', self::CANARY_METHOD]],
        ]]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertNotContains('10:ClinicCore\\Application\\Booking\\BookingService::' . self::CANARY_METHOD, $out['chain']);
        $this->assertSame(1, $out['chain_dropped_unverified']);

        // Non-CPMS namespace (WP-core / other plugin style): never publishable.
        $this->setWpFilter([10 => [['function' => ['WP_DB', 'query']]]]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertSame([], $out['chain']);
        $this->assertSame(1, $out['chain_dropped_unverified']);
    }

    public function testClosuresPassAsBoundedIntTokens(): void
    {
        $this->setWpFilter([10 => [['function' => static fn () => null]]]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertCount(1, $out['chain']);
        $this->assertMatchesRegularExpression('/\A10:closure#[0-9]{1,3}\z/', $out['chain'][0]);
    }

    public function testInvalidPrioritiesAndEntryShapesAreDropped(): void
    {
        $this->setWpFilter([
            10000 => [['function' => 'cpms_m0002_column_exists']], // out of range
            'abc' => [['function' => 'cpms_m0002_column_exists']],  // not an int key
            10 => ['this-is-not-an-entry-array', ['function' => self::CANARY]],
            20 => [
                ['function' => ['ClinicCore\\Application\\Booking\\BookingService', 'a;b(c)']], // shape fail
                ['function' => 12345],                                             // unsupported shape
            ],
        ]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertSame([], $out['chain']);
        // shape: 10000 (range) + 'abc' (key type) + non-array entry +
        // 'a;b(c)' method shape + 12345 callback type = 5; the CANARY entry
        // parses but is not declared in the source tree = 1 unverified.
        $this->assertSame(5, $out['chain_dropped_shape']);
        $this->assertSame(1, $out['chain_dropped_unverified']);
    }

    public function testQueryFiltersReturnShapeIsBounded(): void
    {
        $this->setWpFilter([]);
        $out = ConnectionDiagnostics::queryFilters();
        $this->assertSame(['count', 'chain', 'chain_dropped_shape', 'chain_dropped_unverified'], array_keys($out));
        $this->assertSame(0, $out['count']);
        $this->assertLessThanOrEqual(12, count($out['chain']));
    }

    public function testClassifyExtractsVerbAndKnownTableShape(): void
    {
        $pair = ConnectionDiagnostics::classify('INSERT INTO `wptests_cpms_visits` (id) VALUES (987654321)');
        $this->assertSame('insert', $pair['verb']);
        $this->assertSame('cpms_visits', $pair['table']);

        $pair = ConnectionDiagnostics::classify('SELECT * FROM patient_dumps');
        $this->assertSame('other', $pair['table']);

        $pair = ConnectionDiagnostics::classify("SELECT 1\nDROP TABLE patients");
        $this->assertSame('select', $pair['verb'], 'only the leading token classifies');

        $pair = ConnectionDiagnostics::classify('');
        $this->assertSame(['verb' => 'none', 'table' => 'none'], $pair);
    }

    public function testTableFactsRejectsUnknownShortTableBeforeAnyQuery(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli extension not available in this runner lane');
        }
        // Zero-argument mysqli: no connection is opened, and the early return
        // for an unlisted short table never touches the handle.
        $conn = new \mysqli();
        $facts = ConnectionDiagnostics::tableFacts($conn, 'cpms_patient_dumps', 'id', 1);
        $this->assertSame('none', $facts['table']);
        $this->assertSame(-1, $facts['has_auto_increment']);
        $this->assertSame(-1, $facts['next_auto_increment']);
        $this->assertSame(-1, $facts['row_exists_for_key']);
        $this->assertSame(-1, $facts['temp_shadow_rows']);
    }

    public function testUnknownTableNameNeverLeavesTheBoundary(): void
    {
        // A valid-shaped but unlisted table must never appear in published facts.
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli extension not available in this runner lane');
        }
        $conn = new \mysqli();
        $facts = ConnectionDiagnostics::tableFacts($conn, 'cpms_visits; DROP --', 'id', 1);
        $this->assertSame('none', $facts['table']);
    }
}

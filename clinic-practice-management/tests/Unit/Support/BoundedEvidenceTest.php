<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit\Support;

use ClinicCore\Tests\Support\BoundedEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Hostile-input boundary tests for BoundedEvidence (source-level projector).
 *
 * Synthetic fixtures only. Every "secret" below is a canary that must never
 * survive projection. Valid-shaped canary tokens (identifier-looking strings,
 * Unicode, quoted and multiline payloads, unknown tables/statuses/categories)
 * must be DROPPED — the boundary is fail-closed and never pattern-only.
 */
final class BoundedEvidenceTest extends TestCase
{
    private const CANARY = 'patient@example.com';
    private const CANARY_UNICODE = 'بیمارِ علی ۱۲۳';
    private const CANARY_ID = '987654321';

    public function testVersionDropsSuffixesAndKeepsDottedIntegers(): void
    {
        // Numeric triplet (dotted bounded integers) is published.
        $out = BoundedEvidence::lifecycle(['mysql_version' => '8.0.46']);
        $this->assertSame('8.0.46', $out['mysql_version']);
        $out = BoundedEvidence::lifecycle(['mysql_version' => '11.4.13']);
        $this->assertSame('11.4.13', $out['mysql_version']);

        // Suffixes — pattern-shaped canaries and real MariaDB/distro strings — drop out.
        foreach ([
            '8.0.46-0ubuntu0.24.04.1',
            '11.4.13-MariaDB',
            '8.0.46-canaryCallSign42',
            '8.0.x',
            '8.0.46.1',
            '٨.٠.٤٦',
            '8_0_46',
            "8.0.46\nanything",
            '99999.1.1',
            '8',
            '8.0',
        ] as $hostile) {
            $out = BoundedEvidence::lifecycle(['mysql_version' => $hostile]);
            $this->assertArrayNotHasKey('mysql_version', $out, $hostile);
        }

        // archival() does not project mysql_version at all — suffixed or not.
        $out = BoundedEvidence::archival(['mysql_version' => '8.0.46-0ubuntu0.24.04.1']);
        $this->assertArrayNotHasKey('mysql_version', $out, 'archival must not publish versions');
    }

    public function testTableRejectsUnknownCaseAndInjectedTables(): void
    {
        $out = BoundedEvidence::lifecycle(['wait_edge' => ['table' => 'cpms_visits']]);
        $this->assertSame('cpms_visits', $out['wait_edge']['table']);

        foreach ([
            'cpms_patient_dumps',
            'CPMS_VISITS',
            'cpms_visits; DROP TABLE patients',
            'cpms_valid' . self::CANARY_ID,
            'information_schema',
            'mysql',
            'cpms_ویزیت',
            "cpms_visits'-- ",
        ] as $hostile) {
            $out = BoundedEvidence::lifecycle(['wait_edge' => ['table' => $hostile]]);
            $this->assertArrayNotHasKey('table', $out['wait_edge'], $hostile);
        }

        // Leading/trailing whitespace is trimmed BEFORE the known-table check, so
        // the boundary publishes the canonical short name — never raw input.
        foreach ([' cpms_visits ', "cpms_visits\n"] as $padded) {
            $out = BoundedEvidence::lifecycle(['wait_edge' => ['table' => $padded]]);
            $this->assertSame('cpms_visits', $out['wait_edge']['table'], 'padded input normalizes to the known table');
        }
    }

    public function testVersionTriviaMultilineUnicodeEverywhere(): void
    {
        foreach (["archived\n", 'ARCHIVED', 'archivedd', self::CANARY_UNICODE, 'archived; DROP'] as $hostile) {
            $out = BoundedEvidence::lifecycle(['definition_status_after_parent_commit' => $hostile]);
            $this->assertArrayNotHasKey('definition_status_after_parent_commit', $out, $hostile);
        }

        foreach (["active\n", 'inactive;', 'Active', 'ARCHIVED '] as $ok_or_bad) {
            // exact vocabulary only
            $out = BoundedEvidence::lifecycle(['definition_status_after_parent_commit' => $ok_or_bad]);
            $this->assertArrayNotHasKey('definition_status_after_parent_commit', $out, $ok_or_bad);
        }
        $out = BoundedEvidence::lifecycle(['definition_status_after_parent_commit' => 'inactive']);
        $this->assertSame('inactive', $out['definition_status_after_parent_commit']);
    }

    public function testTransactionStateMultilineUnicodeAndCaseDrops(): void
    {
        foreach (["LOCK WAIT\n", 'lock wait', 'RUNNING ', 'COMMITTING;', self::CANARY_UNICODE, "\x00LOCK WAIT"] as $hostile) {
            $out = BoundedEvidence::lifecycle(['parent' => ['transaction_state_while_child_waited' => $hostile]]);
            $this->assertArrayNotHasKey('transaction_state_while_child_waited', $out['parent'], $hostile);
        }
        $out = BoundedEvidence::lifecycle(['parent' => ['transaction_state_while_child_waited' => 'LOCK WAIT']]);
        $this->assertSame('LOCK WAIT', $out['parent']['transaction_state_while_child_waited']);
    }

    public function testOutcomeVocabularyIsExact(): void
    {
        $out = BoundedEvidence::lifecycle(['child_outcome_after_parent_commit' => 'assigned']);
        $this->assertSame('assigned', $out['child_outcome_after_parent_commit']);
        $out = BoundedEvidence::lifecycle(['child_outcome_after_parent_commit' => 'denied:CUSTOM_ROLE_NOT_FOUND']);
        $this->assertSame('denied:CUSTOM_ROLE_NOT_FOUND', $out['child_outcome_after_parent_commit']);

        foreach ([
            'denied:CUSTOM_ROLE_MADE_UP',
            'denied:custom_role_not_found',
            'denied:',
            'denied:' . self::CANARY_ID,
            'denied:CUSTOM_ROLE_NOT_FOUND; DROP',
            "denied:CUSTOM_ROLE_NOT_FOUND\n",
            self::CANARY_UNICODE,
            'denied:CUSTOM_ROLE_STALE_VERSION;-- ',
        ] as $hostile) {
            $out = BoundedEvidence::lifecycle(['child_outcome_after_parent_commit' => $hostile]);
            $this->assertArrayNotHasKey('child_outcome_after_parent_commit', $out, $hostile);
        }
    }

    public function testSqlModeMembershipIsAllOrNothing(): void
    {
        $out = BoundedEvidence::heartbeat([
            'sql_mode' => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_DATE',
        ]);
        $this->assertSame('ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_DATE', $out['sql_mode']);

        foreach ([
            'ONLY_FULL_GROUP_BY,MADE_UP_MODE',
            'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,',
            'ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES', // embedded space
            "ONLY_FULL_GROUP_BY\nSTRICT_TRANS_TABLES",
            'ONLY_FULL_GROUP_BY​', // zero-width space canary
            '"ONLY_FULL_GROUP_BY"', // quoted
            "'ONLY_FULL_GROUP_BY'",
            self::CANARY_UNICODE,
            '',
        ] as $hostile) {
            $out = BoundedEvidence::heartbeat(['sql_mode' => $hostile]);
            $this->assertArrayNotHasKey('sql_mode', $out, $hostile);
        }
    }

    public function testFdKindValuesAreExactCategoriesOrDrop(): void
    {
        $out = BoundedEvidence::archival([
            'descriptor_isolation' => ['worker_fds_beyond_stdio' => [3 => 'pipe:[12345]', 4 => 'socket:[99]', 5 => 'other']],
        ]);
        $fds = $out['descriptor_isolation']['worker_fds_beyond_stdio'];
        $this->assertSame('pipe', $fds['3']);
        $this->assertSame('socket', $fds['4']);
        $this->assertSame('other', $fds['5']);

        foreach ([
            'pipe:[]',
            'pipe:[notdigits]',
            'pipe:[123456789012345678901]', // >20 digits
            'Socket:[1]',
            'PIPE:[1]',
            'regular file',
            'dev-nulL',
            'socket:[1]; rm -rf /',
            "socket:[1]\n",
            self::CANARY_UNICODE,
            'dev-null9',
        ] as $hostile) {
            $out = BoundedEvidence::archival([
                'descriptor_isolation' => ['worker_fds_beyond_stdio' => [7 => $hostile]],
            ]);
            $this->assertArrayNotHasKey('7', $out['descriptor_isolation']['worker_fds_beyond_stdio'], $hostile);
        }
    }

    public function testFdKeysMustBeBoundedDigits(): void
    {
        $out = BoundedEvidence::archival([
            'descriptor_isolation' => ['worker_fds_beyond_stdio' => [
                '0' => 'other', '12' => 'other', '999' => 'other',
                '1000' => 'other', 'ab' => 'other', '' => 'other', '١٢' => 'other',
            ]],
        ]);
        $fds = $out['descriptor_isolation']['worker_fds_beyond_stdio'];
        $this->assertArrayHasKey('0', $fds);
        $this->assertArrayHasKey('12', $fds);
        $this->assertArrayHasKey('999', $fds);
        $this->assertArrayNotHasKey('1000', $fds); // >3 digits
        $this->assertArrayNotHasKey('ab', $fds);
        $this->assertArrayNotHasKey('', $fds);
        $this->assertArrayNotHasKey('١٢', $fds);
    }

    public function testIntRejectsBoolsAndOverflowStrings(): void
    {
        $out = BoundedEvidence::lifecycle([
            'assignment_audit_count_after' => true,
            'deactivation_audit_count_after' => 10 ** 18,
        ]);
        $this->assertArrayNotHasKey('assignment_audit_count_after', $out);
        $this->assertArrayNotHasKey('deactivation_audit_count_after', $out);

        $out = BoundedEvidence::lifecycle([
            'assignment_audit_count_after' => '42',
            'deactivation_audit_count_after' => -42,
        ]);
        $this->assertSame(42, $out['assignment_audit_count_after']);
        $this->assertSame(-42, $out['deactivation_audit_count_after']);
    }

    public function testHostileJsonStringRoundTripsThroughHeartbeat(): void
    {
        // JSON-quoted/multiline/Unicode primitives must not leak into output.
        $out = BoundedEvidence::heartbeat([
            'json_type' => "STRING\nOBJECT",
            'insert_php_type' => 'string; DROP',
            'update_php_type' => "double\x00",
        ]);
        $this->assertArrayNotHasKey('json_type', $out);
        $this->assertArrayNotHasKey('insert_php_type', $out);
        $this->assertArrayNotHasKey('update_php_type', $out);
    }

    public function testNonArrayNestedStructuresAreDropped(): void
    {
        $out = BoundedEvidence::lifecycle([
            'parent' => 'not-an-array patient@example.com',
            'child' => [self::CANARY_UNICODE],
            'wait_edge' => 'LOCK WAIT',
        ]);
        $this->assertArrayNotHasKey('parent', $out);
        // child must be an object with known keys; a list fails per-key checks.
        $this->assertArrayNotHasKey('connection_id', $out['child'] ?? []);
        $this->assertArrayNotHasKey('wait_edge', $out);
    }
}

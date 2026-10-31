<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Compatibility;

use ClinicCore\Bootstrap\App;
use mysqli;
use mysqli_sql_exception;
use WP_UnitTestCase;

/**
 * Runtime-only MariaDB 10.11 contracts for the scheduled compatibility lane.
 *
 * This file is invoked explicitly by .github/workflows/mariadb-10.11-compat.yml
 * and is intentionally outside tests/Integration, so it does not add routine
 * test cases to the existing required MySQL 8 PR check.
 */
final class MariaDb1011CompatibilityTest extends WP_UnitTestCase
{
    private const JSON_LABEL = 'Zürich 🧑‍⚕️ 医';

    public function testRuntimeIsMariaDb1011WithChecksEnabled(): void
    {
        $server = App::db()->fetchRow(
            'SELECT VERSION() AS server_version, @@version_comment AS version_comment, @@SESSION.check_constraint_checks AS checks_enabled'
        );

        self::assertNotNull($server, 'MariaDB runtime identity must be readable from the actual WordPress connection');
        self::assertSame(1, preg_match('/\A10\.11\.\d+-MariaDB/i', (string) $server['server_version']));
        self::assertStringContainsString('mariadb', strtolower((string) $server['version_comment']));
        self::assertContains(strtoupper((string) $server['checks_enabled']), ['1', 'ON']);
        self::assertSame('2026_10_31_0031', App::migrations()->currentVersion());
    }

    public function testProductionJsonColumnEnforcesCheckAndRoundTripsUtf8mb4(): void
    {
        global $wpdb;

        $db = App::db();
        $table = $db->table('jobs');
        $column = $db->fetchRow(
            'SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS' .
            ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, 'payload_json']
        );

        self::assertNotNull($column, 'the production cpms_jobs.payload_json column must exist after migrations');
        self::assertSame('longtext', strtolower((string) $column['DATA_TYPE']), 'MariaDB JSON is its documented LONGTEXT alias');
        self::assertStringContainsString('longtext', strtolower((string) $column['COLUMN_TYPE']));
        self::assertSame('utf8mb4', strtolower((string) $column['CHARACTER_SET_NAME']));
        self::assertStringStartsWith('utf8mb4_', strtolower((string) $column['COLLATION_NAME']));

        $checks = $db->fetchAll(
            'SELECT cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE' .
            ' FROM information_schema.TABLE_CONSTRAINTS tc' .
            ' JOIN information_schema.CHECK_CONSTRAINTS cc' .
            ' ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA' .
            ' AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND cc.TABLE_NAME = tc.TABLE_NAME' .
            ' WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s AND tc.CONSTRAINT_TYPE = %s',
            [$table, 'CHECK']
        );
        $jsonChecks = array_values(array_filter(
            $checks,
            static fn (array $check): bool => stripos((string) ($check['CHECK_CLAUSE'] ?? ''), 'json_valid') !== false
        ));
        self::assertNotEmpty($jsonChecks, 'MariaDB must expose the JSON_VALID CHECK on the migrated production JSON column');

        $connectionCharsets = $db->fetchRow(
            'SELECT @@character_set_client AS client_charset, @@character_set_connection AS connection_charset'
        );
        self::assertSame('utf8mb4', strtolower((string) ($connectionCharsets['client_charset'] ?? '')));
        self::assertSame('utf8mb4', strtolower((string) ($connectionCharsets['connection_charset'] ?? '')));

        $now = $db->nowUtcSql();
        $prefix = 'maria1011_json_' . bin2hex(random_bytes(6));
        $invalidType = $prefix . '_bad';
        $invalidSql = $wpdb->prepare(
            "INSERT INTO {$table} (`type`, `payload_json`, `run_after`, `created_at`) VALUES (%s, %s, %s, %s)",
            $invalidType,
            '{"truncated":',
            $now,
            $now
        );
        self::assertInstanceOf(mysqli::class, $wpdb->dbh);

        $insertResult = null;
        $constraintError = 0;
        try {
            $insertResult = $wpdb->dbh->query($invalidSql);
        } catch (mysqli_sql_exception $exception) {
            $insertResult = false;
            $constraintError = (int) $exception->getCode();
        }
        if ($constraintError === 0) {
            $constraintError = (int) $wpdb->dbh->errno;
        }
        self::assertFalse($insertResult, 'malformed JSON must fail at the MariaDB CHECK constraint, not be stored');
        self::assertSame(4025, $constraintError, 'MariaDB reports a failed CHECK constraint as error 4025');
        self::assertSame(0, (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE `type` = %s',
            [$invalidType]
        ));

        $validType = $prefix . '_valid';
        $payload = json_encode(
            ['label' => self::JSON_LABEL, 'sample' => '🧪'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        self::assertTrue($db->insert('jobs', [
            'type' => $validType,
            'payload_json' => $payload,
            'run_after' => $now,
            'created_at' => $now,
        ]));

        self::assertSame($payload, $db->fetchValue(
            'SELECT payload_json FROM ' . $table . ' WHERE `type` = %s',
            [$validType]
        ));
        self::assertSame(self::JSON_LABEL, $db->fetchValue(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(payload_json, %s)) FROM ' . $table . ' WHERE `type` = %s',
            ['$.label', $validType]
        ), 'the application connection must round-trip non-BMP Unicode through JSON_EXTRACT');
    }
}

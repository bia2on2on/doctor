<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/** Real MySQL/MariaDB enum evolution and fail-closed downgrade, including non-strict SQL. */
final class CustomRoleArchivalMigrationTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private const VERSION = '2026_10_13_0027';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn(): array => App::migrations()->migrate());
    }

    protected function tearDown(): void
    {
        try {
            $this->withRealTables(static fn(): array => App::migrations()->migrate());
        } finally {
            parent::tearDown();
        }
    }

    public function testFreshInstallHasTerminalEnumAndNoArchivedAtColumn(): void
    {
        self::assertSame(self::VERSION, App::migrations()->currentVersion());
        $column = $this->statusColumn();
        self::assertMatchesRegularExpression(
            "/\\A(?i:enum)\\('active','inactive','archived'\\)\\z/",
            $column['Type']
        );
        self::assertSame('NO', $column['Null']);
        self::assertSame('active', $column['Default']);
        self::assertNull(App::db()->fetchRow('SHOW COLUMNS FROM ' . App::db()->table('cpms_custom_role_defs') . " LIKE 'archived_at'"));
        self::assertSame([], App::migrations()->migrate());
    }

    public function test_mysql_raw_and_mariadb_quoted_active_defaults_share_semantic_ddl(): void
    {
        $cases = [
            [ 'active', [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ] ],
            [ "'active'", [ 'SERVER_VERSION' => '11.4.13-MariaDB', 'VERSION_COMMENT' => 'MariaDB Server' ] ],
            [ "'active'", [ 'SERVER_VERSION' => '10.11.19-MariaDB-ubu2404', 'VERSION_COMMENT' => 'MariaDB Server' ] ],
        ];

        foreach ( $cases as [ $default, $server ] ) {
            $queries = [];
            $column  = $this->validStatusMetadata( $default );
            $db      = $this->mockedMigrationDb( $column, $server, [], '1', $queries );
            $migration = $this->archivalMigration();
            ( $migration['up'] )( $db );

            $alter_statements = array_values(
                array_filter( $queries, static fn ( string $query ): bool => stripos( $query, 'ALTER TABLE' ) === 0 )
            );
            self::assertCount( 1, $alter_statements );
            self::assertStringContainsString( "DEFAULT 'active'", $alter_statements[0] );
            self::assertStringNotContainsString( "DEFAULT '''active'''", $alter_statements[0] );
        }
    }

    public function test_all_noncanonical_status_defaults_are_rejected_before_ddl(): void
    {
        foreach ( [ 'inactive', "'inactive'", 'archived', null, '', 'active ', "'active' ", '"active"', '(active)' ] as $default ) {
            $queries = [];
            $server  = [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ];
            $db      = $this->mockedMigrationDb( $this->validStatusMetadata( $default ), $server, [], '1', $queries );
            $this->assertMigrationUpRejects( $db );
            self::assertSame( [], $queries, 'invalid defaults must fail before any DDL' );
        }
    }

    public function test_status_enum_nullability_extra_and_unknown_metadata_fail_closed(): void
    {
        $server = [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ];
        $cases  = [];

        $reordered = $this->validStatusMetadata( 'active' );
        $reordered['COLUMN_TYPE'] = "enum('inactive','active')";
        $cases[] = $reordered;

        $unexpected = $this->validStatusMetadata( 'active' );
        $unexpected['COLUMN_TYPE'] = "enum('active','inactive','suspended')";
        $cases[] = $unexpected;

        foreach (
            [
                "ENUM('ACTIVE','INACTIVE')",
                "enum('active','InAcTiVe')",
                "enum('active','inactive','Archived')",
            ] as $case_variant
        ) {
            $case_variant_column = $this->validStatusMetadata( 'active' );
            $case_variant_column['COLUMN_TYPE'] = $case_variant;
            $cases[] = $case_variant_column;
        }

        $nullable = $this->validStatusMetadata( 'active' );
        $nullable['IS_NULLABLE'] = 'YES';
        $cases[] = $nullable;

        $extra = $this->validStatusMetadata( 'active' );
        $extra['EXTRA'] = 'DEFAULT_GENERATED';
        $cases[] = $extra;

        $required_fields = [
            'COLUMN_TYPE',
            'IS_NULLABLE',
            'COLUMN_DEFAULT',
            'CHARACTER_SET_NAME',
            'COLLATION_NAME',
            'COLUMN_COMMENT',
            'EXTRA',
        ];
        foreach ( $required_fields as $field ) {
            $missing = $this->validStatusMetadata( 'active' );
            unset( $missing[ $field ] );
            $cases[] = $missing;
        }

        foreach ( $cases as $column ) {
            $queries = [];
            $db      = $this->mockedMigrationDb( $column, $server, [], '1', $queries );
            $this->assertMigrationUpRejects( $db );
            self::assertSame( [], $queries );
        }
    }

    public function test_show_column_type_rejects_case_variants_of_status_values(): void
    {
        $server = [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ];
        $types  = [
            "enum('ACTIVE','INACTIVE')",
            "enum('active','InAcTiVe')",
            "enum('active','inactive','Archived')",
        ];

        foreach ( $types as $type ) {
            $queries = [];
            $db      = $this->mockedMigrationDb(
                $this->validStatusMetadata( 'active' ),
                $server,
                [],
                '1',
                $queries,
                $type
            );
            $this->assertMigrationUpRejects( $db );
            self::assertSame( [], $queries, 'case-variant ENUM literals must fail before DDL' );
        }
    }

    public function test_enum_keyword_case_is_accepted_without_folding_status_literals(): void
    {
        $column = $this->validStatusMetadata( 'active' );
        $column['COLUMN_TYPE'] = "ENUM('active','inactive')";
        $queries = [];
        $server  = [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ];
        $db      = $this->mockedMigrationDb(
            $column,
            $server,
            [],
            '1',
            $queries,
            "ENUM('active','inactive')"
        );

        $migration = $this->archivalMigration();
        ( $migration['up'] )( $db );

        self::assertCount(
            1,
            array_filter( $queries, static fn ( string $query ): bool => stripos( $query, 'ALTER TABLE' ) === 0 )
        );
    }

    public function test_mysql_guard_requires_enforced_yes_and_exact_metadata_ownership(): void
    {
        $defs  = 'wp_cpms_custom_role_defs';
        $guard = $this->guardName( $defs );
        $server = [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ];
        $valid = $this->guardMetadata( $defs, $guard, 'mysql' );

        $valid['ENFORCED'] = 'NO';
        $queries = [];
        $db = $this->mockedMigrationDb( $this->validStatusMetadata( 'active' ), $server, [ $valid ], '1', $queries );
        $this->assertMigrationUpRejects( $db );
        self::assertSame( [], $queries );

        $ownership_fields = [
            'CONSTRAINT_SCHEMA',
            'TABLE_SCHEMA',
            'TABLE_NAME',
            'CONSTRAINT_NAME',
            'CONSTRAINT_TYPE',
            'CHECK_CONSTRAINT_SCHEMA',
            'CHECK_CONSTRAINT_NAME',
            'CHECK_CLAUSE',
        ];
        foreach ( $ownership_fields as $field ) {
            $invalid = $this->guardMetadata( $defs, $guard, 'mysql' );
            $invalid[ $field ] = 'unexpected';
            $queries = [];
            $db = $this->mockedMigrationDb( $this->validStatusMetadata( 'active' ), $server, [ $invalid ], '1', $queries );
            $this->assertMigrationUpRejects( $db );
            self::assertSame( [], $queries, 'a guard with unexpected metadata must not be reused or removed' );
        }

        $valid = $this->guardMetadata( $defs, $guard, 'mysql' );
        $valid['ENFORCED'] = 'YES';
        $queries = [];
        $db = $this->mockedMigrationDb(
            $this->validStatusMetadata( 'active' ),
            $server,
            [ $valid ],
            '1',
            $queries,
            "enum('active','inactive','archived')"
        );
        $migration = $this->archivalMigration();
        ( $migration['up'] )( $db );
        $drop_statements = array_values(
            array_filter( $queries, static fn ( string $query ): bool => stripos( $query, 'DROP CHECK' ) !== false )
        );
        self::assertCount( 1, $drop_statements );
        self::assertStringContainsString( '`' . $guard . '`', $drop_statements[0] );
        self::assertStringNotContainsString( 'DROP CONSTRAINT', $drop_statements[0] );
    }

    public function test_case_different_check_literals_are_rejected_on_both_engines(): void
    {
        $engines = [
            [ 'mysql', [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ], 'active' ],
            [ 'mariadb', [ 'SERVER_VERSION' => '11.4.13-MariaDB', 'VERSION_COMMENT' => 'MariaDB Server' ], "'active'" ],
        ];
        $clauses = [
            "`status` IN ('ACTIVE','INACTIVE')",
            "status iN ('active','InAcTiVe')",
        ];

        foreach ( $engines as [ $engine, $server, $default ] ) {
            $defs  = 'wp_cpms_custom_role_defs';
            $guard = $this->guardName( $defs );
            foreach ( $clauses as $clause ) {
                $invalid = $this->guardMetadata( $defs, $guard, $engine );
                $invalid['CHECK_CLAUSE'] = $clause;
                $queries = [];
                $db = $this->mockedMigrationDb(
                    $this->validStatusMetadata( $default ),
                    $server,
                    [ $invalid ],
                    '1',
                    $queries,
                    "enum('active','inactive','archived')"
                );
                $this->assertMigrationUpRejects( $db );
                self::assertSame( [], $queries, 'case-variant CHECK literals must not be reused or dropped' );
            }
        }
    }

    public function test_unexpected_check_expressions_are_rejected_on_both_engines(): void
    {
        $engines = [
            [ 'mysql', [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ], 'active' ],
            [ 'mariadb', [ 'SERVER_VERSION' => '11.4.13-MariaDB', 'VERSION_COMMENT' => 'MariaDB Server' ], "'active'" ],
        ];
        $clauses = [
            "status IN ('active','inactive') OR 1 = 1",
            "status IN ('active','inactive','archived')",
            "status = 'active' OR status = 'inactive'",
        ];

        foreach ( $engines as [ $engine, $server, $default ] ) {
            $defs  = 'wp_cpms_custom_role_defs';
            $guard = $this->guardName( $defs );
            foreach ( $clauses as $clause ) {
                $invalid = $this->guardMetadata( $defs, $guard, $engine );
                $invalid['CHECK_CLAUSE'] = $clause;
                $queries = [];
                $db = $this->mockedMigrationDb(
                    $this->validStatusMetadata( $default ),
                    $server,
                    [ $invalid ],
                    '1',
                    $queries,
                    "enum('active','inactive','archived')"
                );
                $this->assertMigrationUpRejects( $db );
                self::assertSame( [], $queries, 'an unexpected CHECK expression must not be reused or dropped' );
            }
        }
    }

    public function test_check_sql_presentation_and_exact_guard_identity_are_preserved(): void
    {
        $engines = [
            [
                'mysql',
                [ 'SERVER_VERSION' => '8.4.6', 'VERSION_COMMENT' => 'MySQL Community Server - GPL' ],
                'active',
                'DROP CHECK',
            ],
            [
                'mariadb',
                [ 'SERVER_VERSION' => '11.4.13-MariaDB', 'VERSION_COMMENT' => 'MariaDB Server' ],
                "'active'",
                'DROP CONSTRAINT',
            ],
        ];

        foreach ( $engines as [ $engine, $server, $default, $drop_type ] ) {
            $defs  = 'wp_cpms_custom_role_defs';
            $guard = $this->guardName( $defs );
            $valid = $this->guardMetadata( $defs, $guard, $engine );
            $valid['CHECK_CLAUSE'] = " (( `status` In ( _utf8mb4'active' , _utf8mb4'inactive' ) )) ";
            $column = $this->validStatusMetadata( $default );
            $column['COLUMN_TYPE'] = "ENUM('active','inactive','archived')";
            $queries = [];
            $db = $this->mockedMigrationDb(
                $column,
                $server,
                [ $valid ],
                '1',
                $queries,
                "ENUM('active','inactive','archived')"
            );

            $migration = $this->archivalMigration();
            ( $migration['up'] )( $db );

            $drop_statements = array_values(
                array_filter( $queries, static fn ( string $query ): bool => stripos( $query, $drop_type ) !== false )
            );
            self::assertCount( 1, $drop_statements );
            self::assertStringContainsString( '`' . $guard . '`', $drop_statements[0] );
        }
    }

    public function test_mariadb_check_metadata_and_disabled_session_are_fail_closed(): void
    {
        $defs   = 'wp_cpms_custom_role_defs';
        $guard  = $this->guardName( $defs );
        $server = [ 'SERVER_VERSION' => '11.4.13-MariaDB', 'VERSION_COMMENT' => 'MariaDB Server' ];
        $valid  = $this->guardMetadata( $defs, $guard, 'mariadb' );
        $invalid = $valid;
        $invalid['CHECK_TABLE_NAME'] = 'unexpected';
        $queries = [];
        $db = $this->mockedMigrationDb(
            $this->validStatusMetadata( "'active'" ),
            $server,
            [ $invalid ],
            '1',
            $queries,
            "enum('active','inactive','archived')"
        );
        $this->assertMigrationUpRejects( $db );
        self::assertSame( [], $queries, 'MariaDB guard ownership must be exact before any DDL' );

        $queries = [];
        $db = $this->mockedMigrationDb(
            $this->validStatusMetadata( "'active'" ),
            $server,
            [ $valid ],
            '1',
            $queries,
            "enum('active','inactive','archived')"
        );
        $migration = $this->archivalMigration();
        ( $migration['up'] )( $db );
        $drop_statements = array_values(
            array_filter( $queries, static fn ( string $query ): bool => stripos( $query, 'DROP CONSTRAINT' ) !== false )
        );
        self::assertCount( 1, $drop_statements );
        self::assertStringContainsString( '`' . $guard . '`', $drop_statements[0] );
        self::assertStringNotContainsString( 'ENFORCED', implode( "\n", $queries ) );

        $queries = [];
        $db = $this->mockedMigrationDb( $this->validStatusMetadata( "'active'" ), $server, [], 'OFF', $queries );
        try {
            ( $migration['down'] )( $db );
            self::fail( 'a MariaDB session with check_constraint_checks=OFF must refuse downgrade' );
        } catch ( \RuntimeException $exception ) {
            self::assertStringContainsString( '0027', $exception->getMessage() );
        }
        self::assertSame( [], $queries );
    }

    public function test_unsupported_server_identity_fails_closed(): void
    {
        foreach ( [ '11.4.12-MariaDB', '10.10.26-MariaDB', '10.12.1-MariaDB' ] as $version ) {
            $queries = [];
            $server  = [ 'SERVER_VERSION' => $version, 'VERSION_COMMENT' => 'MariaDB Server' ];
            $db      = $this->mockedMigrationDb( $this->validStatusMetadata( 'active' ), $server, [], '1', $queries );
            $this->assertMigrationUpRejects( $db );
            self::assertSame( [], $queries, 'unsupported MariaDB version must fail before DDL: ' . $version );
        }
    }

    public function test_expanded_enum_without_marker_retries_without_changing_rows_or_indexes(): void
    {
        $db      = App::db();
        $defs    = $db->table( 'cpms_custom_role_defs' );
        $indexes = $this->indexes();
        $column  = $this->statusColumn();

        try {
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_schema_migrations' ) . ' WHERE version = %s', [ self::VERSION ] );
            self::assertSame( '2026_10_11_0026', App::migrations()->currentVersion() );
            self::assertSame( [ self::VERSION ], App::migrations()->migrate() );
            self::assertSame( $column, $this->statusColumn(), 'an already-expanded enum is verified, not rewritten' );
            self::assertSame( $indexes, $this->indexes() );
            self::assertNull( $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'archived_at'" ) );
        } finally {
            App::migrations()->migrate();
        }
    }

    public function test_mariadb_runtime_version_and_real_check_metadata_are_visible(): void
    {
        $server = App::db()->fetchRow( 'SELECT VERSION() AS SERVER_VERSION, @@version_comment AS VERSION_COMMENT' );
        self::assertNotNull( $server );
        self::assertNotSame( '', (string) ( $server['SERVER_VERSION'] ?? '' ) );
        $column = App::db()->fetchRow(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS' .
            ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [ App::db()->table( 'cpms_custom_role_defs' ), 'status' ]
        );
        self::assertNotNull( $column );

        if ( stripos( (string) $server['SERVER_VERSION'], 'MariaDB' ) === false ) {
            self::assertStringStartsWith( '8.', (string) $server['SERVER_VERSION'] );
            self::assertMatchesRegularExpression(
                "/\\A(?i:enum)\\('active','inactive','archived'\\)\\z/",
                (string) $column['COLUMN_TYPE']
            );
            self::assertSame( 'NO', (string) $column['IS_NULLABLE'] );
            self::assertSame( 'active', (string) $column['COLUMN_DEFAULT'] );
            self::assertSame( '', (string) $column['EXTRA'] );
            return;
        }

        self::assertMatchesRegularExpression( '/\\A(?:10\\.11\\.\\d+|11\\.4\\.13)-MariaDB/i', (string) $server['SERVER_VERSION'] );
        self::assertMatchesRegularExpression(
            "/\\A(?i:enum)\\('active','inactive','archived'\\)\\z/",
            (string) $column['COLUMN_TYPE']
        );
        self::assertSame( 'NO', (string) $column['IS_NULLABLE'] );
        self::assertSame( "'active'", (string) $column['COLUMN_DEFAULT'] );
        self::assertSame( '', (string) $column['EXTRA'] );
        self::assertContains( strtoupper( (string) App::db()->fetchValue( 'SELECT @@SESSION.check_constraint_checks' ) ), [ '1', 'ON' ] );
    }

    public function test_real_guard_metadata_recovers_with_portable_drop_constraint(): void
    {
        $db    = App::db();
        $defs  = $db->table( 'cpms_custom_role_defs' );
        $guard = $this->guardName( $defs );
        $server_for_drop = (string) $db->fetchValue( 'SELECT VERSION()' );
        $drop_type = stripos( $server_for_drop, 'MariaDB' ) !== false ? 'DROP CONSTRAINT' : 'DROP CHECK';

        $this->withRealTables( function () use ( $db, $defs, $guard, $drop_type ): void {
            try {
                self::assertSame( self::VERSION, App::migrations()->currentVersion() );
                self::assertSame( self::VERSION, App::migrations()->rollbackOne() );
                self::assertSame( '2026_10_11_0026', App::migrations()->currentVersion() );

                $server = (string) $db->fetchValue( 'SELECT VERSION()' );
                if ( stripos( $server, 'MariaDB' ) !== false ) {
                    $metadata = $db->fetchAll(
                        'SELECT tc.CONSTRAINT_SCHEMA, tc.TABLE_SCHEMA, tc.TABLE_NAME, tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE,' .
                        ' cc.CONSTRAINT_SCHEMA AS CHECK_CONSTRAINT_SCHEMA, cc.CONSTRAINT_NAME AS CHECK_CONSTRAINT_NAME,' .
                        ' cc.TABLE_NAME AS CHECK_TABLE_NAME, cc.CHECK_CLAUSE' .
                        ' FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc' .
                        ' ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND cc.TABLE_NAME = tc.TABLE_NAME' .
                        ' WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s' .
                        ' AND tc.CONSTRAINT_NAME = %s AND tc.CONSTRAINT_TYPE = %s',
                        [ $defs, $guard, 'CHECK' ]
                    );
                } else {
                    $metadata = $db->fetchAll(
                        'SELECT tc.CONSTRAINT_SCHEMA, tc.TABLE_SCHEMA, tc.TABLE_NAME, tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, tc.ENFORCED,' .
                        ' cc.CONSTRAINT_SCHEMA AS CHECK_CONSTRAINT_SCHEMA, cc.CONSTRAINT_NAME AS CHECK_CONSTRAINT_NAME,' .
                        ' cc.CHECK_CLAUSE' .
                        ' FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc' .
                        ' ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME' .
                        ' WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s' .
                        ' AND tc.CONSTRAINT_NAME = %s AND tc.CONSTRAINT_TYPE = %s',
                        [ $defs, $guard, 'CHECK' ]
                    );
                    self::assertSame( 'YES', (string) ( $metadata[0]['ENFORCED'] ?? '' ) );
                }

                self::assertCount( 1, $metadata );
                self::assertSame( $defs, (string) $metadata[0]['TABLE_NAME'] );
                self::assertSame( $guard, (string) $metadata[0]['CONSTRAINT_NAME'] );
                self::assertSame( 'CHECK', (string) $metadata[0]['CONSTRAINT_TYPE'] );
                self::assertSame( $metadata[0]['CONSTRAINT_SCHEMA'], $metadata[0]['CHECK_CONSTRAINT_SCHEMA'] );
                self::assertSame( $guard, (string) $metadata[0]['CHECK_CONSTRAINT_NAME'] );
                if ( isset( $metadata[0]['CHECK_TABLE_NAME'] ) ) {
                    self::assertSame( $defs, (string) $metadata[0]['CHECK_TABLE_NAME'] );
                }
                $check_clause = str_replace( "\\'", "'", (string) $metadata[0]['CHECK_CLAUSE'] );
                self::assertStringContainsString( "'active'", $check_clause );
                self::assertStringContainsString( "'inactive'", $check_clause );

                $decoy = $guard . '_decoy';
                $db->query( "ALTER TABLE {$defs} ADD CONSTRAINT `{$decoy}` CHECK (`status` IN ('active','inactive'))" );
                self::assertSame( [ self::VERSION ], App::migrations()->migrate() );
                self::assertSame( self::VERSION, App::migrations()->currentVersion() );

                $remaining = $db->fetchAll(
                    'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS' .
                    ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_TYPE = %s',
                    [ $defs, 'CHECK' ]
                );
                self::assertContains( [ 'CONSTRAINT_NAME' => $decoy ], $remaining );
                self::assertNotContains( [ 'CONSTRAINT_NAME' => $guard ], $remaining );
                $db->query( "ALTER TABLE {$defs} {$drop_type} `{$decoy}`" );
            } finally {
                if ( App::migrations()->currentVersion() !== self::VERSION ) {
                    App::migrations()->migrate();
                }
                $decoy_exists = (int) $db->fetchValue(
                    'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = %s',
                    [ $defs, $guard . '_decoy', 'CHECK' ]
                ) > 0;
                if ( $decoy_exists ) {
                    $db->query( "ALTER TABLE {$defs} {$drop_type} `{$guard}_decoy`" );
                }
            }
        } );
    }

    public function test_real_mariadb_check_disabled_session_refuses_downgrade(): void
    {
        $db     = App::db();
        $server = (string) $db->fetchValue( 'SELECT VERSION()' );
        if ( stripos( $server, 'MariaDB' ) === false ) {
            self::assertStringStartsWith( '8.', $server );
            return;
        }

        self::assertMatchesRegularExpression( '/\\A(?:10\\.11\\.\\d+|11\\.4\\.13)-MariaDB/i', $server );
        $before = $this->statusColumn();
        $db->query( 'SET SESSION check_constraint_checks = OFF' );
        try {
            try {
                App::migrations()->rollbackOne();
                self::fail( 'MariaDB with session CHECK checks disabled must refuse migration 0027 downgrade' );
            } catch ( \RuntimeException $exception ) {
                self::assertStringContainsString( '0027', $exception->getMessage() );
            }
            self::assertSame( self::VERSION, App::migrations()->currentVersion() );
            self::assertSame( $before, $this->statusColumn() );
        } finally {
            $db->query( 'SET SESSION check_constraint_checks = ON' );
        }
    }

    public function testUpgradeFrom0026PreservesRowsColumnAttributesAndEveryIndex(): void
    {
        /** @var list<int> $ids definitions created by this fixture, tracked from creation */
        $ids = [];

        try {
            $this->withRealTables(function () use (&$ids): void {
                $repository = new CustomRoleRepository(App::db());
                $clinic = (int) App::db()->fetchValue('SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id LIMIT 1');
                // Reclaim durable rows of an earlier run of this fixture (the job runs
                // this file in the focused schema step and again in the full suite).
                $this->purgeOwnedRows($ids);
                $ids[] = $repository->define($clinic, 'arc_mig_active', ['cpms_search'], '受付 — نام');
                $ids[] = $repository->define($clinic, 'arc_mig_inactive', [], 'Inactive');
                $repository->set_status($clinic, 'arc_mig_inactive', 'inactive');

                try {
                    try {
                        self::assertSame(self::VERSION, App::migrations()->rollbackOne());
                    } catch (\Throwable $failure) {
                        $causes = [];
                        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) { $causes[] = $cause->getMessage(); }
                        fwrite(STDERR, 'ARCHIVAL_DOWNGRADE_FAILURE_EVIDENCE ' . json_encode([
                            'causes' => $causes, 'status_column' => $this->statusColumn(),
                            'constraints' => App::db()->fetchAll('SELECT tc.CONSTRAINT_NAME, tc.ENFORCED, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc LEFT JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s', [App::db()->table('cpms_custom_role_defs')]),
                        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
                        throw $failure;
                    }
                    self::assertSame('2026_10_11_0026', App::migrations()->currentVersion());
                    $beforeColumn = $this->statusColumn();
                    $beforeIndexes = $this->indexes();
                    $beforeRows = $this->rows($ids);
                    $beforeCaps = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_custom_role_capabilities') . ' WHERE role_def_id IN (%d, %d) ORDER BY role_def_id, capability', $ids);
                    self::assertSame([self::VERSION], App::migrations()->migrate());
                    $afterColumn = $this->statusColumn();
                    self::assertMatchesRegularExpression(
                        "/\\A(?i:enum)\\('active','inactive','archived'\\)\\z/",
                        $afterColumn['Type']
                    );
                    unset($beforeColumn['Type'], $afterColumn['Type']);
                    self::assertSame($beforeColumn, $afterColumn, 'all other status attributes must survive MODIFY');
                    self::assertSame($beforeIndexes, $this->indexes(), 'indexes and uniqueness must be untouched');
                    self::assertSame($beforeRows, $this->rows($ids));
                    self::assertSame($beforeCaps, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_custom_role_capabilities') . ' WHERE role_def_id IN (%d, %d) ORDER BY role_def_id, capability', $ids));
                    self::assertSame([], App::migrations()->migrate());
                } finally {
                    App::migrations()->migrate();
                }
            });
        } finally {
            // Runs even when a definition above threw: no owned row is left behind.
            $this->purgeOwnedRows($ids);
        }
    }

    public function testArchivedRowsRefuseRollbackAndRetainVersionSchemaAndCapabilities(): void
    {
        $this->assertUnsafeRollback(false);
    }

    public function testNonStrictSqlCannotSilentlyConvertArchivedDefinitionsDuringRollback(): void
    {
        $this->assertUnsafeRollback(true);
    }

    private function assertUnsafeRollback(bool $nonStrict): void
    {
        /** @var list<int> $ids definitions created by this fixture, tracked from creation */
        $ids = [];

        try {
            $this->withRealTables(function () use ($nonStrict, &$ids): void {
                $db = App::db();
                $clinic = (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id LIMIT 1');
                $key = 'arc_mig_reserved_' . bin2hex(random_bytes(3));
                $ids[] = (new CustomRoleRepository($db))->define($clinic, $key, ['cpms_search'], 'Archived definition');
                $id = $ids[0];
                self::assertSame(1, $db->execute('UPDATE ' . $db->table('cpms_custom_role_defs') . ' SET status = %s WHERE id = %d', ['archived', $id]));
                $before = $this->rows([$id]);
                $schema = $this->statusColumn();
                $indexes = $this->indexes();
                $caps = $db->fetchAll('SELECT * FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d', [$id]);
                $sqlMode = (string) $db->fetchValue('SELECT @@SESSION.sql_mode');

                try {
                    if ($nonStrict) {
                        self::assertTrue($db->query('SET SESSION sql_mode = %s', ['']));
                    }
                    try {
                        App::migrations()->rollbackOne();
                        self::fail('an archived definition must block unsafe downgrade');
                    } catch (\RuntimeException $exception) {
                        self::assertStringContainsString('0027', $exception->getMessage());
                    }
                    self::assertSame(self::VERSION, App::migrations()->currentVersion(), 'failed down must not delete its migration marker');
                    self::assertSame($before, $this->rows([$id]));
                    self::assertSame($schema, $this->statusColumn());
                    self::assertSame($indexes, $this->indexes());
                    self::assertSame($caps, $db->fetchAll('SELECT * FROM ' . $db->table('cpms_custom_role_capabilities') . ' WHERE role_def_id = %d', [$id]));
                } finally {
                    $db->query('SET SESSION sql_mode = %s', [$sqlMode]);
                }
            });
        } finally {
            // Runs even when define() threw: the archived probe row never survives.
            $this->purgeOwnedRows($ids);
        }
    }

    /**
     * Removes only the synthetic definitions this fixture owns: the ids it created
     * plus its two deterministic keys (`arc_mig_active` / `arc_mig_inactive`, proven
     * unique to this file). Archived production rows and every unrelated Clinic row
     * are untouched.
     *
     * Why the explicit COMMITs: the fixture runs real DDL through withRealTables(),
     * and MySQL commits implicitly around DDL, so its rows are durable even though
     * WP_UnitTestCase::tearDown() rolls the test transaction back. A plain DELETE
     * would be rolled back with it and leave exactly the residue that made this
     * file's own `arc_mig_active` pre-check fail and that triggered migration 0027's
     * fail-closed downgrade guard in other suites. Committing the removal is the
     * same explicit idiom other committed-fixture suites already use.
     *
     * @param list<int> $ids
     */
    private function purgeOwnedRows(array $ids): void
    {
        $db       = App::db();
        $defs     = $db->table('cpms_custom_role_defs');
        $caps     = $db->table('cpms_custom_role_capabilities');
        $ownedKey = "role_key IN ('arc_mig_active', 'arc_mig_inactive')";

        $db->query('COMMIT'); // close the suite transaction so the DELETEs below are durable

        foreach ($ids as $id) {
            $db->delete('cpms_custom_role_capabilities', ['role_def_id' => $id]);
            $db->delete('cpms_custom_role_defs', ['id' => $id]);
        }
        $db->query('DELETE FROM ' . $caps . ' WHERE role_def_id IN (SELECT id FROM ' . $defs . ' WHERE ' . $ownedKey . ')');
        $db->query('DELETE FROM ' . $defs . ' WHERE ' . $ownedKey);

        $db->query('COMMIT'); // persist the removal: tearDown() only rolls back
    }

    /** @return array<string, mixed> */
    private function validStatusMetadata( mixed $default ): array
    {
        return [
            'COLUMN_TYPE' => "enum('active','inactive')",
            'IS_NULLABLE' => 'NO',
            'COLUMN_DEFAULT' => $default,
            'CHARACTER_SET_NAME' => 'utf8mb4',
            'COLLATION_NAME' => 'utf8mb4_unicode_ci',
            'COLUMN_COMMENT' => '',
            'EXTRA' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function guardMetadata( string $defs, string $guard, string $engine ): array
    {
        $row = [
            'CONSTRAINT_SCHEMA' => 'wordpress',
            'TABLE_SCHEMA' => 'wordpress',
            'TABLE_NAME' => $defs,
            'CONSTRAINT_NAME' => $guard,
            'CONSTRAINT_TYPE' => 'CHECK',
            'CHECK_CONSTRAINT_SCHEMA' => 'wordpress',
            'CHECK_CONSTRAINT_NAME' => $guard,
            'CHECK_CLAUSE' => "`status` in ('active','inactive')",
        ];

        if ( 'mariadb' === $engine ) {
            $row['CHECK_TABLE_NAME'] = $defs;
        } else {
            $row['ENFORCED'] = 'YES';
        }

        return $row;
    }

    private function guardName( string $defs ): string
    {
        return 'cpms_archive_rollback_' . substr( hash( 'sha256', $defs ), 0, 12 );
    }

    private function archivalMigration(): array
    {
        return require dirname( __DIR__, 2 ) . '/src/Migrations/2026_10_13_0027_custom_role_archival.php';
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, mixed> $server
     * @param list<array<string, mixed>> $constraint_rows
     * @param list<string> $queries
     */
    private function mockedMigrationDb(
        array $column,
        array $server,
        array $constraint_rows,
        string $check_value,
        array &$queries,
        string $initial_status_type = "enum('active','inactive')"
    ): CpmsDb {
        $queries = [];
        $ddl_issued = false;
        $guard_removed = false;
        $wpdb = $this->getMockBuilder( \wpdb::class )
            ->disableOriginalConstructor()
            ->onlyMethods( [ 'prepare', 'get_row', 'get_results', 'get_var', 'query' ] )
            ->getMock();
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';

        $wpdb->method( 'prepare' )->willReturnCallback(
            static function ( $query, ...$args ): string {
                foreach ( $args as $arg ) {
                    $quoted = "'" . addslashes( (string) $arg ) . "'";
                    $query = preg_replace( '/%s/', $quoted, $query, 1 );
                }

                return (string) $query;
            }
        );
        $wpdb->method( 'get_row' )->willReturnCallback(
            function ( $query ) use ( &$ddl_issued, $column, $server, $constraint_rows, $initial_status_type, $check_value ): ?array {
                $query = strtolower( (string) $query );
                if ( false !== strpos( $query, 'information_schema.columns' ) ) {
                    return $column;
                }
                if ( false !== strpos( $query, 'select version()' ) ) {
                    return $server;
                }
                if ( false !== strpos( $query, 'information_schema.table_constraints' ) ) {
                    return $constraint_rows[0] ?? null;
                }
                if ( false !== strpos( $query, 'check_constraint_checks' ) ) {
                    return [ 'enabled' => $check_value ];
                }
                if ( false !== strpos( $query, 'show columns' ) ) {
                    return [ 'Type' => $ddl_issued ? "enum('active','inactive','archived')" : $initial_status_type ];
                }

                return null;
            }
        );
        $wpdb->method( 'get_results' )->willReturnCallback(
            static function ( $query ) use ( $constraint_rows, &$guard_removed ): array {
                if ( false === stripos( (string) $query, 'information_schema.table_constraints' ) || $guard_removed ) {
                    return [];
                }

                return $constraint_rows;
            }
        );
        $wpdb->method( 'get_var' )->willReturnCallback(
            static function ( $query ) use ( $check_value ) {
                return false !== stripos( (string) $query, 'check_constraint_checks' ) ? $check_value : null;
            }
        );
        $wpdb->method( 'query' )->willReturnCallback(
            static function ( $query ) use ( &$queries, &$ddl_issued, &$guard_removed ): int {
                $query = (string) $query;
                $queries[] = $query;
                $ddl_issued = true;
                if ( false !== stripos( $query, 'DROP CHECK' ) || false !== stripos( $query, 'DROP CONSTRAINT' ) ) {
                    $guard_removed = true;
                }

                return 1;
            }
        );

        return new CpmsDb( $wpdb );
    }

    private function assertMigrationUpRejects( CpmsDb $db ): void
    {
        try {
            $migration = $this->archivalMigration();
            ( $migration['up'] )( $db );
            self::fail( 'unexpected migration metadata must fail closed' );
        } catch ( \RuntimeException $exception ) {
            self::assertStringContainsString( 'migration 0027', strtolower( $exception->getMessage() ) );
        }
    }

    private function statusColumn(): array
    {
        $column = App::db()->fetchRow('SHOW FULL COLUMNS FROM ' . App::db()->table('cpms_custom_role_defs') . " LIKE 'status'");
        self::assertNotNull($column);
        return $column;
    }

    private function indexes(): array
    {
        return App::db()->fetchAll('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', [App::db()->table('cpms_custom_role_defs')]);
    }

    private function rows(array $ids): array
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        return App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_custom_role_defs') . ' WHERE id IN (' . $placeholders . ') ORDER BY id', $ids);
    }
}

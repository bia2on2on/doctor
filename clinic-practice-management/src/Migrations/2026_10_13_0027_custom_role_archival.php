<?php
/**
 * Correct migration 0027 in place: a failed migration is not recorded, so a
 * successor cannot repair this schema. Accept only the two exact metadata
 * representations of the literal default `active`; preserve all other schema
 * and data, and fail closed when either database engine's guard is uncertain.
 *
 * MySQL 8 reports CHECK enforcement through TABLE_CONSTRAINTS.ENFORCED.
 * MariaDB 10.11.x and 11.4.13 report CHECK ownership through
 * CHECK_CONSTRAINTS.TABLE_NAME and expose no ENFORCED column; CHECK evaluation
 * is controlled by @@SESSION.check_constraint_checks. These engine-specific
 * metadata paths follow the official MySQL 8.0 TABLE_CONSTRAINTS and MariaDB
 * CHECK_CONSTRAINTS documentation.
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;


/** Identify only the runtimes covered by the MySQL and MariaDB integration lanes. */
$cpms_archival_database_engine = static function ( CpmsDb $db ): string {
    $server = $db->fetchRow( 'SELECT VERSION() AS SERVER_VERSION, @@version_comment AS VERSION_COMMENT' );
    if ( $server === null
        || ! isset( $server['SERVER_VERSION'], $server['VERSION_COMMENT'] )
        || ! is_string( $server['SERVER_VERSION'] )
        || ! is_string( $server['VERSION_COMMENT'] ) ) {
        throw new \RuntimeException(
            'migration 0027: database server identity is unavailable; refusing schema changes'
        );
    }

    $version = $server['SERVER_VERSION'];
    $comment = $server['VERSION_COMMENT'];
    if (
        ( stripos( $version, 'MariaDB' ) !== false || stripos( $comment, 'MariaDB' ) !== false )
        && preg_match(
            '/\\A(?:10\\.11\\.\\d+|11\\.4\\.13)(?:-MariaDB)?(?:[-+][a-zA-Z0-9._-]+)?\\z/i',
            $version
        ) === 1
    ) {
        return 'mariadb';
    }

    if ( stripos( $comment, 'MySQL' ) !== false
        && preg_match( '/\A8\.\d+\.\d+(?:[-+][a-zA-Z0-9._-]+)?\z/', $version ) === 1 ) {
        return 'mysql';
    }

    throw new \RuntimeException(
        'migration 0027: unsupported database server; expected MySQL 8, MariaDB 10.11.x, or MariaDB 11.4.13'
    );
};


/** Normalize only the case-insensitive SQL type token; retain ENUM literal spelling. */
$cpms_archival_normalize_enum_keyword = static function ( mixed $type ): ?string {
    if ( ! is_string( $type ) || preg_match( '/\A[eE][nN][uU][mM]\(/', $type ) !== 1 ) {
        return null;
    }

    return 'enum' . substr( $type, 4 );
};


$cpms_archival_status_sql = static function (
    CpmsDb $db,
    bool $archival
) use (
    $cpms_archival_normalize_enum_keyword
): string {
    $column   = $db->fetchRow(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, EXTRA' .
        ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [ $db->table( 'cpms_custom_role_defs' ), 'status' ]
    );
    $required = [
        'COLUMN_TYPE',
        'IS_NULLABLE',
        'COLUMN_DEFAULT',
        'CHARACTER_SET_NAME',
        'COLLATION_NAME',
        'COLUMN_COMMENT',
        'EXTRA',
    ];
    if ( $column === null ) {
        throw new \RuntimeException(
            'migration 0027: unexpected custom-role status schema; no normalization is allowed'
        );
    }
    foreach ( $required as $field ) {
        if ( ! array_key_exists( $field, $column ) || ! is_string( $column[ $field ] ) ) {
            throw new \RuntimeException(
                'migration 0027: incomplete custom-role status metadata; no normalization is allowed'
            );
        }
    }

    $column_type    = $cpms_archival_normalize_enum_keyword( $column['COLUMN_TYPE'] );
    $column_default = (string) $column['COLUMN_DEFAULT'];
    if ( $column_type === null || ! in_array(
        $column_type,
        [ "enum('active','inactive')", "enum('active','inactive','archived')" ],
        true
    ) || $column['IS_NULLABLE'] !== 'NO'
        || ! in_array( $column_default, [ 'active', "'active'" ], true )
        || $column['EXTRA'] !== '' ) {
        throw new \RuntimeException(
            'migration 0027: unexpected custom-role status schema; no normalization is allowed'
        );
    }

    $charset   = (string) $column['CHARACTER_SET_NAME'];
    $collation = (string) $column['COLLATION_NAME'];
    if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $charset ) || ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $collation ) ) {
        throw new \RuntimeException( 'migration 0027: status charset/collation is not established' );
    }
    $enum = $archival ? "ENUM('active','inactive','archived')" : "ENUM('active','inactive')";

    // Both supported COLUMN_DEFAULT representations denote the same SQL value.
    // Always pass that semantic literal through CpmsDb::prepare exactly once.
    return $db->prepare(
        "`status` {$enum} CHARACTER SET {$charset} COLLATE {$collation} NOT NULL DEFAULT %s COMMENT %s",
        [ 'active', (string) $column['COLUMN_COMMENT'] ]
    );
};


/** A partial downgrade guard is reusable/removable only if its full contract is exact. */
$cpms_archival_guard_exists = static function ( CpmsDb $db, string $defs, string $guard, string $engine ): bool {
    $select = 'SELECT tc.CONSTRAINT_SCHEMA, tc.TABLE_SCHEMA, tc.TABLE_NAME, tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE,' .
        ' cc.CONSTRAINT_SCHEMA AS CHECK_CONSTRAINT_SCHEMA, cc.CONSTRAINT_NAME AS CHECK_CONSTRAINT_NAME,' .
        ' cc.CHECK_CLAUSE';
    if ( 'mysql' === $engine ) {
        $select .= ', tc.ENFORCED';
    } else {
        $select .= ', cc.TABLE_NAME AS CHECK_TABLE_NAME';
    }

    $join = ' LEFT JOIN information_schema.CHECK_CONSTRAINTS cc' .
        ' ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME';
    if ( 'mariadb' === $engine ) {
        $join .= ' AND cc.TABLE_NAME = tc.TABLE_NAME';
    }

    $rows = $db->fetchAll(
        $select . ' FROM information_schema.TABLE_CONSTRAINTS tc' . $join .
        ' WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s' .
        ' AND tc.CONSTRAINT_NAME = %s',
        [ $defs, $guard ]
    );
    if ( $db->wpdb()->last_error !== '' ) {
        throw new \RuntimeException( 'migration 0027: rollback guard metadata read failed' );
    }
    if ( $rows === [] ) {
        return false;
    }
    if ( count( $rows ) !== 1 ) {
        throw new \RuntimeException( 'migration 0027: rollback guard metadata is ambiguous' );
    }

    $constraint = $rows[0];
    $required   = [
        'CONSTRAINT_SCHEMA',
        'TABLE_SCHEMA',
        'TABLE_NAME',
        'CONSTRAINT_NAME',
        'CONSTRAINT_TYPE',
        'CHECK_CONSTRAINT_SCHEMA',
        'CHECK_CONSTRAINT_NAME',
        'CHECK_CLAUSE',
    ];
    if ( 'mysql' === $engine ) {
        $required[] = 'ENFORCED';
    } else {
        $required[] = 'CHECK_TABLE_NAME';
    }
    foreach ( $required as $field ) {
        if ( ! array_key_exists( $field, $constraint ) || ! is_string( $constraint[ $field ] ) ) {
            throw new \RuntimeException( 'migration 0027: rollback guard metadata is incomplete' );
        }
    }

    if ( $constraint['CONSTRAINT_SCHEMA'] !== $constraint['TABLE_SCHEMA']
        || $constraint['CONSTRAINT_SCHEMA'] !== $constraint['CHECK_CONSTRAINT_SCHEMA']
        || $constraint['TABLE_NAME'] !== $defs
        || $constraint['CONSTRAINT_NAME'] !== $guard
        || $constraint['CHECK_CONSTRAINT_NAME'] !== $guard
        || $constraint['CONSTRAINT_TYPE'] !== 'CHECK'
        || ( 'mariadb' === $engine && $constraint['CHECK_TABLE_NAME'] !== $defs )
        || ( 'mysql' === $engine && $constraint['ENFORCED'] !== 'YES' ) ) {
        throw new \RuntimeException( 'migration 0027: unexpected rollback guard ownership or enforcement metadata' );
    }

    // CHECK_CLAUSE differs in harmless quoting, whitespace, charset
    // introducers and SQL keyword case. Preserve literal case and the exact
    // predicate so no broader expression can authorize narrowing the ENUM.
    $clause = str_replace( "\\'", "'", (string) $constraint['CHECK_CLAUSE'] );
    $clause = preg_replace( "/_[a-zA-Z0-9]+(?=')/", '', $clause );
    if ( ! is_string( $clause ) ) {
        throw new \RuntimeException( 'migration 0027: rollback guard expression could not be normalized' );
    }
    $clause = trim( $clause );
    while ( $clause !== '' && $clause[0] === '(' && substr( $clause, -1 ) === ')' ) {
        $clause = trim( substr( $clause, 1, -1 ) );
    }
    $expected_clause = '/\A(?i:(?:`status`|status))\s+(?i:IN)\s*\(' .
        '\s*\'active\'\s*,\s*\'inactive\'\s*\)\z/';
    if ( preg_match( $expected_clause, $clause ) !== 1 ) {
        throw new \RuntimeException(
            'migration 0027: unexpected rollback guard expression; no constraint may be silently reused or removed'
        );
    }

    return true;
};


$cpms_archival_mariadb_checks_enabled = static function ( CpmsDb $db ): void {
    $enabled = $db->fetchValue( 'SELECT @@SESSION.check_constraint_checks' );
    if ( ( ! is_string( $enabled ) && ! is_int( $enabled ) )
        || ! in_array( strtoupper( trim( (string) $enabled ) ), [ '1', 'ON' ], true ) ) {
        throw new \RuntimeException(
            'migration 0027: MariaDB CHECK enforcement is disabled or unknown; refusing downgrade'
        );
    }
};


return [
    'version'     => '2026_10_13_0027',
    'description' => 'Slice 4B: terminal archived custom-role status, durable definitions and fail-closed downgrade',
    'up'          => static function ( CpmsDb $db ) use (
        $cpms_archival_database_engine,
        $cpms_archival_normalize_enum_keyword,
        $cpms_archival_status_sql,
        $cpms_archival_guard_exists
    ): void {
        $engine       = $cpms_archival_database_engine( $db );
        $defs         = $db->table( 'cpms_custom_role_defs' );
        $column_sql   = $cpms_archival_status_sql( $db, true );
        $guard        = 'cpms_archive_rollback_' . substr( hash( 'sha256', $defs ), 0, 12 );
        $guard_exists = $cpms_archival_guard_exists( $db, $defs, $guard, $engine );
        $column       = $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'status'" );
        if ( $column === null || ! isset( $column['Type'] ) || ! is_string( $column['Type'] ) ) {
            throw new \RuntimeException( 'migration 0027: missing or unknown status column metadata' );
        }

        $status_type   = $cpms_archival_normalize_enum_keyword( $column['Type'] );
        $allowed_types = [ "enum('active','inactive')", "enum('active','inactive','archived')" ];
        if ( $status_type === null || ! in_array( $status_type, $allowed_types, true ) ) {
            throw new \RuntimeException( 'migration 0027: unexpected status column type; no normalization is allowed' );
        }
        if ( $status_type !== "enum('active','inactive','archived')" || $guard_exists ) {
            // MySQL's historical spelling preserves MySQL 8 compatibility;
            // MariaDB documents DROP CONSTRAINT as the portable CHECK form.
            $drop_type  = 'mysql' === $engine ? 'DROP CHECK' : 'DROP CONSTRAINT';
            $drop_guard = $guard_exists ? ", {$drop_type} `{$guard}`" : '';
            if ( ! $db->query( "ALTER TABLE {$defs} MODIFY COLUMN {$column_sql}{$drop_guard}" ) ) {
                throw new \RuntimeException( 'migration 0027: archival enum extension failed' );
            }
        }

        $verified = $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'status'" );
        if ( $verified === null || ! isset( $verified['Type'] ) || ! is_string( $verified['Type'] )
            || $cpms_archival_normalize_enum_keyword( $verified['Type'] ) !== "enum('active','inactive','archived')" ) {
            throw new \RuntimeException( 'migration 0027: refusing to record an unverified archival enum' );
        }
        if ( $guard_exists && $cpms_archival_guard_exists( $db, $defs, $guard, $engine ) ) {
            throw new \RuntimeException( 'migration 0027: rollback guard remains after archival retry' );
        }
    },
    'down'        => static function ( CpmsDb $db ) use (
        $cpms_archival_database_engine,
        $cpms_archival_normalize_enum_keyword,
        $cpms_archival_status_sql,
        $cpms_archival_guard_exists,
        $cpms_archival_mariadb_checks_enabled
    ): void {
        $defs  = $db->table( 'cpms_custom_role_defs' );
        $guard = 'cpms_archive_rollback_' . substr( hash( 'sha256', $defs ), 0, 12 );

        // A row lock followed by ALTER would NOT protect a preflight: ALTER
        // implicitly commits and releases it. An enforced CHECK instead closes
        // the race across both DDL commits, including non-strict SQL mode. Its
        // installation validates CURRENT rows atomically and refuses archived
        // (or unknown) states before the enum can be narrowed. No conversion.
        // The guard intentionally remains on a downgraded schema; up removes it
        // atomically with expansion. A partial down leaves a fail-closed guard,
        // not a fabricated transactional rollback or permission-granting state.
        try {
            $engine = $cpms_archival_database_engine( $db );
            if ( 'mariadb' === $engine ) {
                $cpms_archival_mariadb_checks_enabled( $db );
            }
            $column_sql = $cpms_archival_status_sql( $db, false );
            $has_guard  = $cpms_archival_guard_exists( $db, $defs, $guard, $engine );
            $add_guard  = "ALTER TABLE {$defs} ADD CONSTRAINT `{$guard}` CHECK (`status` IN ('active','inactive'))";
            if ( ! $has_guard && ! $db->query( $add_guard ) ) {
                throw new \RuntimeException( 'archived or unknown custom-role rows prevent downgrade' );
            }
            if ( ! $cpms_archival_guard_exists( $db, $defs, $guard, $engine ) ) {
                throw new \RuntimeException( 'enforced rollback guard is not established' );
            }
            if ( ! $db->query( "ALTER TABLE {$defs} MODIFY COLUMN {$column_sql}" ) ) {
                throw new \RuntimeException( 'status enum narrowing failed; rollback guard is retained' );
            }
            $verified = $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'status'" );
            if ( $verified === null || ! isset( $verified['Type'] ) || ! is_string( $verified['Type'] )
                || $cpms_archival_normalize_enum_keyword( $verified['Type'] ) !== "enum('active','inactive')" ) {
                throw new \RuntimeException( 'narrowed enum is not verified; rollback marker must remain' );
            }
            $cpms_archival_status_sql( $db, false );
        } catch ( \RuntimeException $failure ) {
            $message = 'migration 0027: unsafe rollback refused; definitions and archival state must be preserved: ' .
                $failure->getMessage();
            throw new \RuntimeException( $message, 0, $failure );
        }
    },
];

<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Build MODIFY from verified live attributes, preserving collation/comment,
 * NOT NULL/default, position, indexes and data. Unexpected schema fails closed.
 * No archived_at or data rewrite is introduced.
 */
$cpms_archival_status_sql = static function ( CpmsDb $db, bool $archival ): string {
    $column = $db->fetchRow(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, EXTRA' .
        ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [ $db->table( 'cpms_custom_role_defs' ), 'status' ]
    );
    if ( $column === null
        || ! in_array( strtolower( (string) $column['COLUMN_TYPE'] ), [ "enum('active','inactive')", "enum('active','inactive','archived')" ], true )
        || (string) $column['IS_NULLABLE'] !== 'NO'
        || (string) $column['COLUMN_DEFAULT'] !== 'active'
        || (string) $column['EXTRA'] !== '' ) {
        throw new \RuntimeException( 'migration 0027: unexpected custom-role status schema; no normalization is allowed' );
    }
    $charset   = (string) $column['CHARACTER_SET_NAME'];
    $collation = (string) $column['COLLATION_NAME'];
    if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $charset ) || ! preg_match( '/\A[a-zA-Z0-9_]+\z/', $collation ) ) {
        throw new \RuntimeException( 'migration 0027: status charset/collation is not established' );
    }
    $enum = $archival ? "ENUM('active','inactive','archived')" : "ENUM('active','inactive')";

    return $db->prepare(
        "`status` {$enum} CHARACTER SET {$charset} COLLATE {$collation} NOT NULL DEFAULT %s COMMENT %s",
        [ (string) $column['COLUMN_DEFAULT'], (string) $column['COLUMN_COMMENT'] ]
    );
};


/** A partial downgrade guard is reusable/removable only if its contract is exact. */
$cpms_archival_guard_exists = static function ( CpmsDb $db, string $defs, string $guard ): bool {
    $constraint = $db->fetchRow(
        'SELECT tc.ENFORCED, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc' .
        ' JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME' .
        ' WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = %s AND tc.CONSTRAINT_NAME = %s AND tc.CONSTRAINT_TYPE = %s',
        [ $defs, $guard, 'CHECK' ]
    );
    if ( $db->wpdb()->last_error !== '' ) {
        throw new \RuntimeException( 'migration 0027: rollback guard metadata read failed' );
    }
    if ( $constraint === null ) {
        return false;
    }
    // MySQL renders string literals with a charset introducer and parentheses.
    $clause = (string) preg_replace( "/_[a-zA-Z0-9]+(?=')/", '', (string) $constraint['CHECK_CLAUSE'] );
    $clause = strtolower( str_replace( [ '`', '(', ')', ' ', "\n", "\r", "\t" ], '', $clause ) );
    if ( (string) $constraint['ENFORCED'] !== 'YES' || $clause !== "statusin'active','inactive'" ) {
        throw new \RuntimeException( 'migration 0027: unexpected rollback guard; no constraint may be silently reused or removed' );
    }

    return true;
};

return [
    'version'     => '2026_10_13_0027',
    'description' => 'Slice 4B: terminal archived custom-role status, durable definitions and fail-closed downgrade',
    'up'          => static function ( CpmsDb $db ) use ( $cpms_archival_status_sql, $cpms_archival_guard_exists ): void {
        $defs       = $db->table( 'cpms_custom_role_defs' );
        $column_sql = $cpms_archival_status_sql( $db, true );
        $guard      = 'cpms_archive_rollback_' . substr( hash( 'sha256', $defs ), 0, 12 );
        $guard_exists = $cpms_archival_guard_exists( $db, $defs, $guard );
        $column = $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'status'" );
        if ( $column === null ) {
            throw new \RuntimeException( 'migration 0027: missing status column' );
        }
        if ( strtolower( (string) $column['Type'] ) !== "enum('active','inactive','archived')" || $guard_exists ) {
            // DDL commits implicitly. This one ALTER is atomic at MySQL's DDL
            // boundary, but cannot be undone by CpmsDb::transactional(). If
            // version persistence later fails, retry safely verifies this enum.
            $drop_guard = $guard_exists ? ", DROP CHECK `{$guard}`" : '';
            if ( ! $db->query( "ALTER TABLE {$defs} MODIFY COLUMN {$column_sql}{$drop_guard}" ) ) {
                throw new \RuntimeException( 'migration 0027: archival enum extension failed' );
            }
        }
        $verified = $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'status'" );
        if ( $verified === null || strtolower( (string) $verified['Type'] ) !== "enum('active','inactive','archived')" ) {
            throw new \RuntimeException( 'migration 0027: refusing to record an unverified archival enum' );
        }
    },
    'down'        => static function ( CpmsDb $db ) use ( $cpms_archival_status_sql, $cpms_archival_guard_exists ): void {
        $defs       = $db->table( 'cpms_custom_role_defs' );
        $column_sql = $cpms_archival_status_sql( $db, false );
        $guard      = 'cpms_archive_rollback_' . substr( hash( 'sha256', $defs ), 0, 12 );

        // A row lock followed by ALTER would NOT protect a preflight: ALTER
        // implicitly commits and releases it. An enforced CHECK instead closes
        // the race across both DDL commits, including non-strict SQL mode. Its
        // installation validates CURRENT rows atomically and refuses archived
        // (or unknown) states before the enum can be narrowed. No conversion.
        // The guard intentionally remains on a downgraded schema; up removes it
        // atomically with expansion. A partial down leaves a fail-closed guard,
        // not a fabricated transactional rollback or permission-granting state.
        try {
            $has_guard = $cpms_archival_guard_exists( $db, $defs, $guard );
            if ( ! $has_guard && ! $db->query( "ALTER TABLE {$defs} ADD CONSTRAINT `{$guard}` CHECK (`status` IN ('active','inactive'))" ) ) {
                throw new \RuntimeException( 'archived or unknown custom-role rows prevent downgrade' );
            }
            if ( ! $cpms_archival_guard_exists( $db, $defs, $guard ) ) {
                throw new \RuntimeException( 'enforced rollback guard is not established' );
            }
            if ( ! $db->query( "ALTER TABLE {$defs} MODIFY COLUMN {$column_sql}" ) ) {
                throw new \RuntimeException( 'status enum narrowing failed; rollback guard is retained' );
            }
            $verified = $db->fetchRow( "SHOW COLUMNS FROM {$defs} LIKE 'status'" );
            if ( $verified === null || strtolower( (string) $verified['Type'] ) !== "enum('active','inactive')" ) {
                throw new \RuntimeException( 'narrowed enum is not verified; rollback marker must remain' );
            }
        } catch ( \RuntimeException $failure ) {
            throw new \RuntimeException( 'migration 0027: unsafe rollback refused; definitions and archival state must be preserved', 0, $failure );
        }
    },
];

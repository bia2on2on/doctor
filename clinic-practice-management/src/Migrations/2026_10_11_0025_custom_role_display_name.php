<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

// Migration 0025 — Custom Role & Permission Management Slice 2:
//
//  `cpms_custom_role_defs.display_name` — the persistent, human-facing label of
//  a Clinic-local custom role definition. Additive and non-destructive:
//   - `role_key` stays the immutable technical key (never derived from the
//     display name, never rewritten by this migration);
//   - `display_name` is NULL-able so every pre-existing definition (and every
//     definition created without an explicit label) keeps working unchanged;
//   - VARCHAR(190) utf8mb4 = the full BMP-and-beyond range in storage; the
//     bounded application-level validation lives in CustomRolePolicy (no
//     locale-specific, Persian-only rule anywhere).
//
// Why a NEW migration instead of editing 2026_10_10_0024: the migration runner
// is forward-only and records applied versions (docs/architecture/phase0.5-
// target-model.md AD-…/MigrationRunner contract). Editing an already-applied
// migration would leave every installed environment — 0024 recorded, column
// absent — silently without the column. Slice 2 is therefore additive.
//
// Safety contract (fail closed, never repair, never rebuild):
//   - missing dependency table  -> loud failure (0024 must be applied first);
//   - column absent             -> single additive ALTER TABLE ADD COLUMN;
//   - column present            -> validated against the required contract;
//   - malformed column          -> loud failure, no DROP/ALTER/MODIFY attempt;
//   - repeat migrate()          -> no-op (idempotent);
//   - existing rows             -> untouched (no data write, no default seed).
return [
    'version'     => '2026_10_11_0025',
    'description' => 'Custom Role Slice 2: additive UTF-8 display_name column for clinic-local custom role definitions',
    'up'          => function ( CpmsDb $db ): void {
        $defs   = $db->table( 'cpms_custom_role_defs' );
        $column = 'display_name';

        $table_exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            [ $defs ]
        ) > 0;
        if ( ! $table_exists ) {
            // Fail loudly instead of creating a table here: the custom-role
            // table belongs to migration 0024 and its absence means the
            // prerequisites are wrong (never silently repair another
            // migration's obligation).
            throw new \RuntimeException( 'migration 0025: required table ' . $defs . ' is missing — migration 2026_10_10_0024 must be applied first' );
        }

        $describe = static function () use ( $db, $defs, $column ): ?array {
            return $db->fetchRow(
                'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                [ $defs, $column ]
            );
        };

        $row = $describe();
        if ( $row === null ) {
            // Additive only. `AFTER role_key` keeps the human label adjacent to
            // the immutable technical key without touching existing columns.
            $db->query(
                "ALTER TABLE {$defs} ADD COLUMN `display_name` VARCHAR(190) NULL DEFAULT NULL AFTER `role_key`"
            );
            $row = $describe();
        }

        if ( $row === null ) {
            throw new \RuntimeException( 'migration 0025: display_name column is absent after the additive ALTER TABLE — refusing to record this migration' );
        }

        // Schema contract for both the freshly added and any pre-existing
        // column: malformed means fail loudly, never DROP/MODIFY/rebuild.
        if ( strtolower( (string) ( $row['DATA_TYPE'] ?? '' ) ) !== 'varchar' ) {
            throw new \RuntimeException( 'migration 0025: display_name must be varchar, found ' . (string) ( $row['DATA_TYPE'] ?? '' ) );
        }
        if ( (int) ( $row['CHARACTER_MAXIMUM_LENGTH'] ?? 0 ) !== 190 ) {
            throw new \RuntimeException( 'migration 0025: display_name must have length 190' );
        }
        if ( strtoupper( (string) ( $row['IS_NULLABLE'] ?? '' ) ) !== 'YES' ) {
            throw new \RuntimeException( 'migration 0025: display_name must be NULL-able (pre-existing definitions keep working)' );
        }
        if ( strtolower( (string) ( $row['CHARACTER_SET_NAME'] ?? '' ) ) !== 'utf8mb4' ) {
            // Unicode display names are a product requirement; a non-utf8mb4
            // column cannot satisfy it and must not be silently accepted.
            throw new \RuntimeException( 'migration 0025: display_name must be utf8mb4 to store Unicode display names' );
        }
    },
    'down'        => function ( CpmsDb $db ): void {
        $defs   = $db->table( 'cpms_custom_role_defs' );
        $column = 'display_name';

        $exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            [ $defs ]
        ) > 0;
        if ( ! $exists ) {
            return;
        }
        $has_column = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [ $defs, $column ]
        ) > 0;
        if ( ! $has_column ) {
            return;
        }
        // Only the column this migration introduced is removed; role_key,
        // status, version and every capability assignment are preserved.
        $db->query( "ALTER TABLE {$defs} DROP COLUMN `display_name`" );
    },
];

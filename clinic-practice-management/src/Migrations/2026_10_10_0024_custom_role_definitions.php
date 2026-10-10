<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

// Migration 0024 — Custom Role & Permission Management Slice 1:
//
//  1) `cpms_custom_role_defs` — Clinic-local custom role definitions. The key
//     is immutable and UNIQUE per (clinic_id, role_key), so it can never
//     collide across Clinics and a key is defined at most once per Clinic.
//     `status` active/inactive (inactive supplies no permissions);
//     `version` is a monotonic revision (forward-only updates advance it).
//  2) `cpms_custom_role_capabilities` — the permitted CPMS capability set of a
//     definition, UNIQUE per (role_def_id, capability).
//
// Schema-only: NO seeds, NO membership migration, NO role assignment, NO
// professional/patient identity writes. Built-in CPMS roles are untouched.
// Capability validation is enforced at the repository write boundary and
// re-checked on the authorization read path (CustomRolePolicy) — not by SQL.
return [
    'version'     => '2026_10_10_0024',
    'description' => 'Custom Role & Permission Management Slice 1: clinic-local custom role definitions + capability sets',
    'up'          => function ( CpmsDb $db ): void {
        $defs    = $db->table( 'cpms_custom_role_defs' );
        $caps    = $db->table( 'cpms_custom_role_capabilities' );
        $clinics = $db->table( 'cpms_clinics' );

        // Real-table guard (same pattern as MigrationRunner::ensureSchemaTable):
        // under the WP test suite the query filter rewrites CREATE TABLE to
        // CREATE TEMPORARY TABLE, and a temporary shadow with a FOREIGN KEY is
        // rejected by InnoDB (errno 150) even when the real table exists. When
        // the real table is already present the CREATE is skipped entirely, so
        // an idempotent migrate() can never attempt a shadow create.
        $defs_exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            [ $defs ]
        ) > 0;
        $caps_exists = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            [ $caps ]
        ) > 0;

        // Schema-contract validation for pre-existing same-name tables: an
        // existing table must be verified against the required schema BEFORE
        // anything is created or recorded. A malformed table fails loudly —
        // no drop/recreate, no silent repair, no destructive normalization;
        // the pre-existing table and its rows stay untouched and version 0024
        // is never recorded. Only material invariants are checked: required
        // columns (type/nullability + material lengths/enums), the two UNIQUE
        // constraints, the lookup index used by the repository read path, and
        // both FOREIGN KEYs with their ON DELETE CASCADE relationships.
        $require_columns = static function ( string $table, array $expect ) use ( $db ): void {
            foreach ( $expect as $column => $spec ) {
                $row = $db->fetchRow(
                    'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    [ $table, $column ]
                );
                if ( $row === null ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . ' fails the required schema contract (missing column ' . $column . ')' );
                }
                if ( strtolower( (string) ( $row['DATA_TYPE'] ?? '' ) ) !== (string) $spec['type'] ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . '.' . $column . ' has type ' . (string) ( $row['DATA_TYPE'] ?? '' ) . ', expected ' . (string) $spec['type'] );
                }
                if ( strtoupper( (string) ( $row['IS_NULLABLE'] ?? '' ) ) !== 'NO' ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . '.' . $column . ' must be NOT NULL' );
                }
                if ( isset( $spec['length'] ) && (int) ( $row['CHARACTER_MAXIMUM_LENGTH'] ?? 0 ) !== (int) $spec['length'] ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . '.' . $column . ' must have length ' . (int) $spec['length'] );
                }
                if ( ! empty( $spec['unsigned'] ) && ! str_contains( strtolower( (string) ( $row['COLUMN_TYPE'] ?? '' ) ), 'unsigned' ) ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . '.' . $column . ' must be unsigned' );
                }
                if ( isset( $spec['enum_contains'] ) && ! str_contains( strtolower( (string) ( $row['COLUMN_TYPE'] ?? '' ) ), strtolower( (string) $spec['enum_contains'] ) ) ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . '.' . $column . ' has unexpected enum values' );
                }
            }
        };
        $require_index   = static function ( string $table, string $index, array $columns, bool $unique ) use ( $db ): void {
            $rows = $db->fetchAll(
                'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX',
                [ $table, $index ]
            );
            if ( $rows === [] ) {
                throw new \RuntimeException( 'migration 0024: existing table ' . $table . ' fails the required schema contract (missing index ' . $index . ')' );
            }
            $actual = [];
            foreach ( $rows as $row ) {
                if ( $unique && (int) ( $row['NON_UNIQUE'] ?? 1 ) !== 0 ) {
                    throw new \RuntimeException( 'migration 0024: existing table ' . $table . ' index ' . $index . ' must be UNIQUE' );
                }
                $actual[] = (string) ( $row['COLUMN_NAME'] ?? '' );
            }
            if ( $actual !== $columns ) {
                throw new \RuntimeException( 'migration 0024: existing table ' . $table . ' index ' . $index . ' must span (' . implode( ', ', $columns ) . ')' );
            }
        };
        $require_fk      = static function ( string $table, string $constraint, string $column, string $ref_table, string $ref_column ) use ( $db ): void {
            $rows = $db->fetchAll(
                'SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE' .
                ' FROM information_schema.KEY_COLUMN_USAGE kcu' .
                ' JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.TABLE_NAME = kcu.TABLE_NAME' .
                ' WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = %s AND kcu.CONSTRAINT_NAME = %s' .
                ' ORDER BY kcu.ORDINAL_POSITION',
                [ $table, $constraint ]
            );
            if ( $rows === [] ) {
                throw new \RuntimeException( 'migration 0024: existing table ' . $table . ' fails the required schema contract (missing foreign key ' . $constraint . ')' );
            }
            $first = $rows[0];
            if (
                (string) ( $first['COLUMN_NAME'] ?? '' ) !== $column
                || (string) ( $first['REFERENCED_TABLE_NAME'] ?? '' ) !== $ref_table
                || (string) ( $first['REFERENCED_COLUMN_NAME'] ?? '' ) !== $ref_column
                || strtoupper( (string) ( $first['DELETE_RULE'] ?? '' ) ) !== 'CASCADE'
            ) {
                throw new \RuntimeException( 'migration 0024: existing table ' . $table . ' foreign key ' . $constraint . ' must reference ' . $ref_table . '(' . $ref_column . ') ON DELETE CASCADE' );
            }
        };

        if ( $defs_exists ) {
            $require_columns(
                $defs,
                [
                    'id'         => [ 'type' => 'bigint', 'unsigned' => true ],
                    'clinic_id'  => [ 'type' => 'bigint', 'unsigned' => true ],
                    'role_key'   => [ 'type' => 'varchar', 'length' => 64 ],
                    'status'     => [ 'type' => 'enum', 'enum_contains' => "'active','inactive'" ],
                    'version'    => [ 'type' => 'int', 'unsigned' => true ],
                    'created_at' => [ 'type' => 'datetime' ],
                    'updated_at' => [ 'type' => 'datetime' ],
                ]
            );
            $require_index( $defs, 'u_custom_role', [ 'clinic_id', 'role_key' ], true );
            $require_index( $defs, 'idx_custom_role_status', [ 'clinic_id', 'status' ], false );
            $require_fk( $defs, 'fk_customrole_clinic', 'clinic_id', $clinics, 'id' );
        }

        if ( $caps_exists ) {
            $require_columns(
                $caps,
                [
                    'id'          => [ 'type' => 'bigint', 'unsigned' => true ],
                    'role_def_id' => [ 'type' => 'bigint', 'unsigned' => true ],
                    'capability'  => [ 'type' => 'varchar', 'length' => 64 ],
                    'created_at'  => [ 'type' => 'datetime' ],
                ]
            );
            $require_index( $caps, 'u_custom_role_cap', [ 'role_def_id', 'capability' ], true );
            $require_fk( $caps, 'fk_customrolecap_def', 'role_def_id', $defs, 'id' );
        }

        if ( ! $defs_exists ) {
            $db->query(
                "CREATE TABLE IF NOT EXISTS {$defs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `role_key` VARCHAR(64) NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_custom_role` (`clinic_id`, `role_key`),
            KEY `idx_custom_role_status` (`clinic_id`, `status`),
            CONSTRAINT `fk_customrole_clinic` FOREIGN KEY (`clinic_id`)
                REFERENCES {$clinics} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if ( ! $caps_exists ) {
            $db->query(
                "CREATE TABLE IF NOT EXISTS {$caps} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `role_def_id` BIGINT UNSIGNED NOT NULL,
            `capability` VARCHAR(64) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_custom_role_cap` (`role_def_id`, `capability`),
            CONSTRAINT `fk_customrolecap_def` FOREIGN KEY (`role_def_id`)
                REFERENCES {$defs} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    },
    'down'        => function ( CpmsDb $db ): void {
        $db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'cpms_custom_role_capabilities' ) );
        $db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'cpms_custom_role_defs' ) );
    },
];

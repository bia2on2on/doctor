<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0024 — Custom Role & Permission Management Slice 1:
 *
 *  1) `cpms_custom_role_defs` — Clinic-local custom role definitions. The key
 *     is immutable and UNIQUE per (clinic_id, role_key), so it can never
 *     collide across Clinics and a key is defined at most once per Clinic.
 *     `status` active/inactive (inactive supplies no permissions);
 *     `version` is a monotonic revision (forward-only updates advance it).
 *  2) `cpms_custom_role_capabilities` — the permitted CPMS capability set of a
 *     definition, UNIQUE per (role_def_id, capability).
 *
 * Schema-only: NO seeds, NO membership migration, NO role assignment, NO
 * professional/patient identity writes. Built-in CPMS roles are untouched.
 * Capability validation is enforced at the repository write boundary and
 * re-checked on the authorization read path (CustomRolePolicy) — not by SQL.
 */
return [
    'version'     => '2026_10_10_0024',
    'description' => 'Custom Role & Permission Management Slice 1: clinic-local custom role definitions + capability sets',
    'up'          => function ( CpmsDb $db ): void {
        $defs    = $db->table( 'cpms_custom_role_defs' );
        $caps    = $db->table( 'cpms_custom_role_capabilities' );
        $clinics = $db->table( 'cpms_clinics' );

        $db->query( "CREATE TABLE IF NOT EXISTS {$defs} (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $db->query( "CREATE TABLE IF NOT EXISTS {$caps} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `role_def_id` BIGINT UNSIGNED NOT NULL,
            `capability` VARCHAR(64) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_custom_role_cap` (`role_def_id`, `capability`),
            CONSTRAINT `fk_customrolecap_def` FOREIGN KEY (`role_def_id`)
                REFERENCES {$defs} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    },
    'down'        => function ( CpmsDb $db ): void {
        $db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'cpms_custom_role_capabilities' ) );
        $db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'cpms_custom_role_defs' ) );
    },
];

<?php
/**
 * Migration 0030 — Iran V1: internal staff messaging + doctor-managed visit pricing.
 *
 * Six additive tables. No existing invoice, payment, invoice-item, visit,
 * clinical or membership row is altered: existing paid/finalized invoices keep
 * their exact persisted values. DDL is deliberately portable MySQL 8 /
 * MariaDB 11.4 (InnoDB, utf8mb4, ordinary DECIMAL/BIGINT/DATETIME(3), no
 * generated columns, no CHECK, no functional index, no IF NOT EXISTS on
 * indexes). Tenant relationships are enforced by the services (and by the
 * query's own clinic_id predicate), so no FOREIGN KEY is declared: this also
 * keeps the DDL safe under the WordPress test-suite CREATE TEMPORARY TABLE
 * rewrite, where InnoDB refuses foreign keys. MigrationRunner wraps up() in a
 * transaction and records the
 * version only after a clean statement; CREATE TABLE IF NOT EXISTS keeps
 * re-runs idempotent.
 *
 * Retention: cpms_messages is append-only. No application route deletes or
 * edits a message body; history is kept for the Clinic lifetime under the
 * existing backup/DR retention policy (see the design decision document).
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version'     => '2026_10_27_0030',
    'description' => 'Iran V1: clinic-scoped staff messaging, doctor consultation fees and draft visit charges',
    'up'          => static function ( CpmsDb $db ): void {
        $threads     = $db->table( 'cpms_message_threads' );
        $participant = $db->table( 'cpms_message_participants' );
        $messages    = $db->table( 'cpms_messages' );
        $fees        = $db->table( 'cpms_doctor_consultation_fees' );
        $overrides   = $db->table( 'cpms_visit_consultation_fees' );
        $charges     = $db->table( 'cpms_visit_charge_items' );

        $db->query( "CREATE TABLE IF NOT EXISTS {$threads} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `organization_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NULL,
            `patient_id` BIGINT UNSIGNED NULL,
            `visit_id` BIGINT UNSIGNED NULL,
            `created_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `last_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `last_message_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_msg_thread_clinic` (`clinic_id`,`id`),
            KEY `idx_msg_thread_org` (`organization_id`,`clinic_id`),
            KEY `idx_msg_thread_visit` (`visit_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $db->query( "CREATE TABLE IF NOT EXISTS {$participant} (
            `thread_id` BIGINT UNSIGNED NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NOT NULL,
            `staff_class` VARCHAR(24) NOT NULL,
            `last_read_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`thread_id`,`wp_user_id`),
            KEY `idx_msg_part_user` (`wp_user_id`,`thread_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $db->query( "CREATE TABLE IF NOT EXISTS {$messages} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `thread_id` BIGINT UNSIGNED NOT NULL,
            `sender_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `body` VARCHAR(2000) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_msg_thread_id` (`thread_id`,`id`),
            KEY `idx_msg_clinic_id` (`clinic_id`,`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $db->query( "CREATE TABLE IF NOT EXISTS {$fees} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `fee` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `currency` CHAR(3) NOT NULL DEFAULT 'IRR',
            `updated_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_doctor_fee` (`clinic_id`,`clinician_id`,`location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $db->query( "CREATE TABLE IF NOT EXISTS {$overrides} (
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `fee` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `set_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`visit_id`),
            KEY `idx_visit_fee_clinic` (`clinic_id`,`visit_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        $db->query( "CREATE TABLE IF NOT EXISTS {$charges} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            `unit_amount` BIGINT UNSIGNED NOT NULL,
            `quantity` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            `note` VARCHAR(500) NULL,
            `invoiced_invoice_id` BIGINT UNSIGNED NULL,
            `created_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `updated_by_wp_user_id` BIGINT UNSIGNED NULL,
            `removed_at` DATETIME(3) NULL,
            `removed_by_wp_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_charge_visit` (`clinic_id`,`visit_id`,`removed_at`),
            KEY `idx_charge_invoice` (`invoiced_invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    },
    'down'        => static function ( CpmsDb $db ): void {
        // DEVELOPMENT ONLY. Production downgrades restore from backup: these
        // tables contain append-only messaging history and auditable pricing.
        foreach (
            [
                'cpms_visit_charge_items',
                'cpms_visit_consultation_fees',
                'cpms_doctor_consultation_fees',
                'cpms_messages',
                'cpms_message_participants',
                'cpms_message_threads',
            ] as $table
        ) {
            $db->query( 'DROP TABLE IF EXISTS ' . $db->table( $table ) );
        }
    },
];

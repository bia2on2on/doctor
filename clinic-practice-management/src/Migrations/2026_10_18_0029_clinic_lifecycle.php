<?php

/**
 * Post-onboarding clinic lifecycle — preserves history, enables safe disable/reactivate.
 *
 * Adds durable lifecycle columns to cpms_clinics. Deactivation/reactivation are
 * Organization-scoped, row-locked, transactional, audited, idempotent and
 * concurrency-safe. No medical, financial, audit or backup rows are removed
 * by lifecycle transitions — they remain queryable as historical evidence.
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version'     => '2026_10_18_0029',
    'description' => 'Clinic lifecycle status + audit columns (post-onboarding Org-managed activation/deactivation)',
    'up'          => static function ( CpmsDb $db ): void {
        $clinics = $db->table( 'cpms_clinics' );

        $col = $db->fetchRow( "SHOW COLUMNS FROM {$clinics} LIKE 'status'" );
        if ( $col === null ) {
            $db->query(
                "ALTER TABLE {$clinics} ADD COLUMN `status` ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER `phone`"
            );
        }

        $col = $db->fetchRow( "SHOW COLUMNS FROM {$clinics} LIKE 'status_updated_at'" );
        if ( $col === null ) {
            $db->query(
                "ALTER TABLE {$clinics} ADD COLUMN `status_updated_at` DATETIME(3) NULL AFTER `status`"
            );
        }

        $col = $db->fetchRow( "SHOW COLUMNS FROM {$clinics} LIKE 'status_updated_by_wp_user_id'" );
        if ( $col === null ) {
            $db->query(
                "ALTER TABLE {$clinics} ADD COLUMN `status_updated_by_wp_user_id` BIGINT UNSIGNED NULL AFTER `status_updated_at`"
            );
        }

        // Index for Org-scoped clinic listing by status
        $idx = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_clinic_org_status'",
            [$clinics]
        );
        if ( (int) ( $idx['n'] ?? 0 ) === 0 ) {
            $db->query( "ALTER TABLE {$clinics} ADD KEY `idx_clinic_org_status` (`organization_id`,`status`)" );
        }
    },
    'down'        => static function ( CpmsDb $db ): void {
        $clinics = $db->table( 'cpms_clinics' );

        // InnoDB FK backing-index invariant: migration 2026_09_09_0010 adds
        // `fk_clinics_organization` on cpms_clinics(organization_id). An FK needs an
        // index whose FIRST column is the referencing column; `idx_clinic_org_status`
        // (organization_id, status) can be that index. Dropping it alone can therefore
        // fail with errno 1553 on MySQL 8 / MariaDB when no other index leads with
        // organization_id. Detect that case and, when needed, add the replacement
        // index in the SAME ALTER as the drop so the invariant is never momentarily
        // unbacked (single atomic DDL statement, no intermediate unsafe state).
        $idx = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_clinic_org_status'",
            [$clinics]
        );
        if ( (int) ( $idx['n'] ?? 0 ) > 0 ) {
            $other_backing = (int) $db->fetchValue(
                "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'organization_id'
                   AND SEQ_IN_INDEX = 1 AND INDEX_NAME <> 'idx_clinic_org_status'",
                [$clinics]
            );
            if ( $other_backing > 0 ) {
                $db->query( "ALTER TABLE {$clinics} DROP KEY `idx_clinic_org_status`" );
            } else {
                $db->query(
                    "ALTER TABLE {$clinics}
                       ADD KEY `idx_clinics_organization_id` (`organization_id`),
                       DROP KEY `idx_clinic_org_status`"
                );
            }
        }

        foreach ( ['status_updated_by_wp_user_id', 'status_updated_at', 'status'] as $column ) {
            $col = $db->fetchRow( "SHOW COLUMNS FROM {$clinics} LIKE '{$column}'" );
            if ( $col !== null ) {
                $db->query( "ALTER TABLE {$clinics} DROP COLUMN `{$column}`" );
            }
        }
    },
];

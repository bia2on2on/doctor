<?php
/**
 * Migration 0019 — Phase 2 (C5 — Patient Identity Foundation / AD-14):
 *
 * ۱) `cpms_patient_identities.normalized_mobile` — صفت lookup در سطح
 *    Organization (نه کلید هویت). تصمیم‌ها:
 *    - plaintext بر اساس دستور C5 («normalized lookup field» + ایندکس
 *      organization+mobile). مکانیزم هش (B-17) همچنان ساخته نمی‌شود — هیچ
 *      ستون هش‌شده‌ای وجود ندارد.
 *    - **بدون UNIQUE** روی (organization_id, normalized_mobile): دو Identity
 *      هم‌سازمان با موبایل یکسان = duplicate candidates مجاز (رفتار
 *      non-destructive؛ ادغام خودکار ممنوع). قید یکتاییِ موجودِ
 *      `u_pat_mobile(clinic_id, mobile)` روی «رکورد بالینی» دست‌نخورده است.
 *    - ایندکس مرکب `idx_identity_org_mobile` دقیقاً مطابق query واقعی
 *      lookup (organization_id + normalized_mobile) — بدون full-scan.
 *
 * ۲) `cpms_patient_identity_links` — لینک صریح WP User ↔ Patient Identity
 *    (سطح Organization). جدا از `cpms_patient_user_links` (Clinic-scoped،
 *    رکورد بالینی). linking خودکار با موبایل ممنوع — فقط primitive صریح.
 *    UNIQUE(identity_id, wp_user_id)؛ بدون FK به wp users (هم‌راستا با
 *    patient_user_links موجود).
 */

declare( strict_types=1 );

use ClinicCore\Infrastructure\Db\CpmsDb;


return [
    'version'     => '2026_09_09_0019',
    'description' => 'Phase 2 C5: identity normalized_mobile (org-scoped lookup, non-unique) + identity<->user links',
    'up'          => function ( CpmsDb $db ): void {
        $identities = $db->table( 'cpms_patient_identities' );

        // ---------- 1) normalized_mobile + composite index ----------
        $col = $db->fetchRow( "SHOW COLUMNS FROM {$identities} LIKE 'normalized_mobile'" );
        if ( $col === null ) {
            $db->query(
                "ALTER TABLE {$identities} ADD COLUMN `normalized_mobile` VARCHAR(16) NULL AFTER `internal_ref`"
            );
        }

        $idx = $db->fetchRow(
            "SHOW INDEX FROM {$identities} WHERE Key_name = 'idx_identity_org_mobile'"
        );
        if ( $idx === null ) {
            $db->query(
                "ALTER TABLE {$identities} ADD KEY `idx_identity_org_mobile`" .
                ' (`organization_id`, `normalized_mobile`)'
            );
        }

        // ---------- 2) identity ↔ user links ----------
        $links = $db->table( 'cpms_patient_identity_links' );
        $db->query(
            "CREATE TABLE IF NOT EXISTS {$links} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` BIGINT UNSIGNED NOT NULL,
            `identity_id` BIGINT UNSIGNED NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NOT NULL,
            `mobile_at_link` VARCHAR(32) NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `linked_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_pil_pair` (`identity_id`, `wp_user_id`),
            KEY `idx_pil_user_org` (`wp_user_id`, `organization_id`),
            KEY `idx_pil_org_identity` (`organization_id`, `identity_id`),
            CONSTRAINT `fk_pil_organization` FOREIGN KEY (`organization_id`)
                REFERENCES " . $db->table( 'cpms_organizations' ) . " (`id`),
            CONSTRAINT `fk_pil_identity` FOREIGN KEY (`identity_id`)
                REFERENCES {$identities} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    },
    'down'        => function ( CpmsDb $db ): void {
        $identities = $db->table( 'cpms_patient_identities' );
        $links      = $db->table( 'cpms_patient_identity_links' );

        $db->query( "DROP TABLE IF EXISTS {$links}" );

        $idx = $db->fetchRow(
            "SHOW INDEX FROM {$identities} WHERE Key_name = 'idx_identity_org_mobile'"
        );
        if ( $idx !== null ) {
            $db->query(
                "ALTER TABLE {$identities} DROP KEY `idx_identity_org_mobile`"
            );
        }

        $col = $db->fetchRow( "SHOW COLUMNS FROM {$identities} LIKE 'normalized_mobile'" );
        if ( $col !== null ) {
            $db->query(
                "ALTER TABLE {$identities} DROP COLUMN `normalized_mobile`"
            );
        }
    },
];

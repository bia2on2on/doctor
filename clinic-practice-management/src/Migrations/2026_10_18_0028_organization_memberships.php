<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/** Organization-scoped management authority. No legacy users are promoted. */
return [
    'version' => '2026_10_18_0028',
    'description' => 'Organization-scoped memberships and explicit capability overrides (ADR-0031 extension)',
    'up' => static function (CpmsDb $db): void {
        $members = $db->table('cpms_organization_memberships');
        $caps = $db->table('cpms_organization_membership_capabilities');
        $db->query("CREATE TABLE IF NOT EXISTS {$members} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `organization_id` BIGINT UNSIGNED NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NOT NULL,
            `role_key` VARCHAR(32) NOT NULL,
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `created_by_wp_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_org_membership` (`organization_id`,`wp_user_id`),
            KEY `idx_org_membership_authority` (`organization_id`,`role_key`,`status`),
            CONSTRAINT `fk_org_membership_org` FOREIGN KEY (`organization_id`) REFERENCES {$db->table('cpms_organizations')} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE IF NOT EXISTS {$caps} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `membership_id` BIGINT UNSIGNED NOT NULL,
            `capability` VARCHAR(64) NOT NULL,
            `effect` ENUM('grant','deny') NOT NULL,
            `created_by_wp_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_org_membership_capability` (`membership_id`,`capability`),
            CONSTRAINT `fk_org_cap_membership` FOREIGN KEY (`membership_id`) REFERENCES {$members} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    'down' => static function (CpmsDb $db): void {
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_organization_membership_capabilities'));
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_organization_memberships'));
    },
];

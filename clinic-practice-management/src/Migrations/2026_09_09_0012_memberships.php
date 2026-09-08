<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0012 — Phase 2 (M-10..M-13 — ADR-0031، AD-05/AD-06/P2-D1):
 *
 *  1) `cpms_clinic_memberships` — عضویت M:N کاربر↔کلینیک با نقش به‌عنوان صفت
 *     رابطه (role_key = Preset، نه نقش WP).
 *  2) `cpms_membership_capabilities` — Override دانه‌ریز per-(User, Clinic)
 *     (deny > grant > preset — ارزیابی در Phase 3).
 *  3) `cpms_membership_locations` — محدودسازی عضویت به Locationهای خاص (اختیاری).
 *  4) `cpms_clinician_locations` — پزشک در چند محل (P2-D1: یک Clinician Profile،
 *     چند Location؛ رابطهٔ واقعی Clinician↔Clinic از Membership می‌آید).
 *  5) Seed: برای هر Clinic × هر کاربر دارای نقش cpms_* یک Membership فعال؛
 *     برای هر Clinician یک لینک به Location اصلیِ کلینیکِ خانه (compatibility).
 *
 * نکتهٔ P2-D1: `clinicians.clinic_id` از این پس فقط «کلینیکِ خانه»ی
 * compatibility است — مرز مجوز/مالکیت نیست.
 */
return [
    'version' => '2026_09_09_0012',
    'description' => 'Phase 2: membership + clinician-location primitives (AD-05/AD-06/P2-D1)',
    'up' => function (CpmsDb $db): void {
        $memberships = $db->table('cpms_clinic_memberships');
        $memberCaps = $db->table('cpms_membership_capabilities');
        $memberLocs = $db->table('cpms_membership_locations');
        $clinicianLocs = $db->table('cpms_clinician_locations');

        $db->query("CREATE TABLE IF NOT EXISTS {$memberships} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NOT NULL,
            `role_key` VARCHAR(64) NOT NULL,
            `scope_mode` ENUM('clinic','location') NOT NULL DEFAULT 'clinic',
            `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `invited_by_wp_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_membership` (`clinic_id`, `wp_user_id`),
            KEY `idx_membership_user` (`wp_user_id`, `status`),
            KEY `idx_membership_role` (`clinic_id`, `role_key`, `status`),
            CONSTRAINT `fk_membership_clinic` FOREIGN KEY (`clinic_id`)
                REFERENCES {$db->table('cpms_clinics')} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS {$memberCaps} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `membership_id` BIGINT UNSIGNED NOT NULL,
            `capability` VARCHAR(64) NOT NULL,
            `effect` ENUM('grant','deny') NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_member_cap` (`membership_id`, `capability`),
            CONSTRAINT `fk_membercap_membership` FOREIGN KEY (`membership_id`)
                REFERENCES {$memberships} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS {$memberLocs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `membership_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_member_loc` (`membership_id`, `location_id`),
            CONSTRAINT `fk_memberloc_membership` FOREIGN KEY (`membership_id`)
                REFERENCES {$memberships} (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_memberloc_location` FOREIGN KEY (`location_id`)
                REFERENCES {$db->table('cpms_locations')} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS {$clinicianLocs} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `location_id` BIGINT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_clinician_loc` (`clinician_id`, `location_id`),
            CONSTRAINT `fk_clinloc_clinician` FOREIGN KEY (`clinician_id`)
                REFERENCES {$db->table('cpms_clinicians')} (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_clinloc_location` FOREIGN KEY (`location_id`)
                REFERENCES {$db->table('cpms_locations')} (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $now = $db->nowUtcSql();

        // ---------- Seed عضویت‌ها (M-13) — از نقش‌های WP موجود ----------
        // نقش WP سراسری می‌ماند (dual-mode Q4/D1)؛ این Seed فقط «عرصهٔ عضویت»
        // را برای مدل جدید می‌سازد. منبع: usermeta capabilities.
        $roleKeys = ['cpms_doctor', 'cpms_secretary', 'cpms_accountant', 'cpms_manager', 'cpms_patient'];
        $prefix = $db->wpdb()->prefix;
        $clinics = $db->fetchAll('SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id');

        $usersByRole = [];
        foreach ($roleKeys as $role) {
            $usersByRole[$role] = $db->fetchAll(
                'SELECT user_id FROM ' . $prefix . 'usermeta
                 WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1000',
                [$prefix . 'capabilities', '%"' . $role . '"%']
            );
        }

        foreach ($clinics as $clinic) {
            $clinicId = (int) $clinic['id'];
            $seenUsers = [];
            foreach ($roleKeys as $role) {
                foreach ($usersByRole[$role] as $row) {
                    $userId = (int) $row['user_id'];
                    if (isset($seenUsers[$userId])) {
                        continue; // اولین نقشِ اولویت‌دار برای این کاربر
                    }
                    $seenUsers[$userId] = true;

                    $db->query(
                        "INSERT IGNORE INTO {$memberships}
                             (clinic_id, wp_user_id, role_key, scope_mode, status, is_primary, created_at, updated_at)
                         VALUES (%d, %d, %s, 'clinic', 'active', 1, %s, %s)",
                        [$clinicId, $userId, $role, $now, $now]
                    );
                }
            }
        }

        // ---------- Seed clinician_locations (P2-D1) ----------
        $db->query(
            'INSERT IGNORE INTO ' . $clinicianLocs . ' (clinician_id, location_id, is_primary, created_at)
             SELECT c.id, l.id, 1, %s
             FROM ' . $db->table('cpms_clinicians') . ' c
             JOIN ' . $db->table('cpms_locations') . ' l ON l.clinic_id = c.clinic_id AND l.is_primary = 1',
            [$now]
        );
    },
    'down' => function (CpmsDb $db): void {
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_clinician_locations'));
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_membership_locations'));
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_membership_capabilities'));
        $db->query('DROP TABLE IF EXISTS ' . $db->table('cpms_clinic_memberships'));
    },
];

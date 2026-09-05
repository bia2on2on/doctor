<?php
/**
 * Migration اولیه — 37 جدول CPMS (docs/erd/erd.md + data-dictionary.md).
 *
 * تغییرات نسبت به ERD v1 (گزارش F1):
 *  - #37 cpms_rate_limits (جدول زیرساختی برای Rate Limit اتمیک).
 *  - cpms_idempotency_keys: افزودن ستون status (pending/done) و context_id.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE برای Seed.
 */

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

return [
    'version' => '2026_09_05_0001',
    'description' => 'Initial CPMS schema (37 tables)',
    'up' => function (CpmsDb $db): void {
        $now = $db->nowUtcSql();

        $sql = [];

        // ============ 1. ساختار ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_clinics') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(190) NOT NULL,
            `slug` VARCHAR(190) NOT NULL,
            `timezone` VARCHAR(64) NOT NULL DEFAULT \'Asia/Tehran\',
            `address` VARCHAR(255) NULL,
            `phone` VARCHAR(32) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_clinics_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_clinicians') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NULL,
            `full_name` VARCHAR(190) NOT NULL,
            `specialty` VARCHAR(190) NULL,
            `room` VARCHAR(64) NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_clinician_active` (`clinic_id`,`is_active`),
            CONSTRAINT `fk_clinicians_clinic` FOREIGN KEY (`clinic_id`) REFERENCES ' . $db->table('cpms_clinics') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // ============ 2. بیمار ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_patients') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `mrn` VARCHAR(32) NOT NULL,
            `first_name` VARCHAR(120) NOT NULL,
            `last_name` VARCHAR(120) NOT NULL,
            `mobile` VARCHAR(32) NOT NULL,
            `national_id` VARCHAR(10) NULL,
            `birth_date` DATE NULL,
            `gender` ENUM(\'male\',\'female\',\'other\',\'unknown\') NOT NULL DEFAULT \'unknown\',
            `address` VARCHAR(255) NULL,
            `phone` VARCHAR(32) NULL,
            `emergency_contact_name` VARCHAR(190) NULL,
            `emergency_contact_phone` VARCHAR(32) NULL,
            `blood_group` VARCHAR(8) NULL,
            `medication_allergies` JSON NULL,
            `other_allergies` JSON NULL,
            `chronic_conditions` JSON NULL,
            `medical_history` TEXT NULL,
            `surgery_history` TEXT NULL,
            `current_medications` JSON NULL,
            `status` ENUM(\'active\',\'archived\') NOT NULL DEFAULT \'active\',
            `archived_at` DATETIME(3) NULL,
            `archive_reason` VARCHAR(255) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_pat_mrn` (`clinic_id`,`mrn`),
            UNIQUE KEY `u_pat_mobile` (`clinic_id`,`mobile`),
            UNIQUE KEY `u_pat_nid` (`clinic_id`,`national_id`),
            KEY `idx_pat_search` (`clinic_id`,`last_name`,`first_name`),
            CONSTRAINT `fk_patients_clinic` FOREIGN KEY (`clinic_id`) REFERENCES ' . $db->table('cpms_clinics') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_patient_user_links') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NOT NULL,
            `mobile_at_link` VARCHAR(32) NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `linked_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_link_pair` (`patient_id`,`wp_user_id`),
            KEY `idx_link_user` (`wp_user_id`),
            CONSTRAINT `fk_links_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_patient_merges') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `surviving_patient_id` BIGINT UNSIGNED NOT NULL,
            `merged_patient_id` BIGINT UNSIGNED NOT NULL,
            `merged_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `reason` VARCHAR(255) NOT NULL,
            `mapping_json` JSON NOT NULL,
            `merged_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_merge_surviving` (`surviving_patient_id`),
            KEY `idx_merge_merged` (`merged_patient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_otp_tokens') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `mobile` VARCHAR(32) NOT NULL,
            `purpose` ENUM(\'login\',\'verify_mobile\') NOT NULL DEFAULT \'login\',
            `code_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `locked_until` DATETIME(3) NULL,
            `consumed_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_otp_mobile` (`mobile`,`purpose`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_idempotency_keys') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key` VARCHAR(128) NOT NULL,
            `clinic_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
            `wp_user_id` BIGINT UNSIGNED NULL,
            `endpoint` VARCHAR(190) NOT NULL,
            `context_id` BIGINT UNSIGNED NULL,
            `status` TINYINT NOT NULL DEFAULT 0,
            `response_code` SMALLINT NULL,
            `response_json` JSON NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_idem_key` (`key`),
            KEY `idx_idem_ctx` (`endpoint`,`wp_user_id`,`context_id`),
            KEY `idx_idem_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // ============ 3. نوبت‌دهی ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_schedule') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `day_of_week` TINYINT NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `break_start` TIME NULL,
            `break_end` TIME NULL,
            `appointment_duration_min` SMALLINT UNSIGNED NOT NULL DEFAULT 20,
            `slot_capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_sched_day` (`clinician_id`,`day_of_week`),
            CONSTRAINT `fk_schedule_clinic` FOREIGN KEY (`clinic_id`) REFERENCES ' . $db->table('cpms_clinics') . ' (`id`),
            CONSTRAINT `fk_schedule_clinician` FOREIGN KEY (`clinician_id`) REFERENCES ' . $db->table('cpms_clinicians') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_schedule_exceptions') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `date` DATE NOT NULL,
            `type` ENUM(\'holiday\',\'leave\',\'blocked\',\'open_override\') NOT NULL,
            `start_time` TIME NULL,
            `end_time` TIME NULL,
            `reason` VARCHAR(255) NULL,
            `created_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_sched_exc` (`clinician_id`,`date`,`type`),
            CONSTRAINT `fk_sched_exc_clinician` FOREIGN KEY (`clinician_id`) REFERENCES ' . $db->table('cpms_clinicians') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_schedule_slots') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `slot_date` DATE NOT NULL,
            `slot_time` TIME NOT NULL,
            `duration_min` SMALLINT UNSIGNED NOT NULL,
            `capacity` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `booked_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `held_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `is_open` TINYINT(1) NOT NULL DEFAULT 1,
            `generated_from` ENUM(\'lazy\',\'cron\',\'manual\') NOT NULL DEFAULT \'lazy\',
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_slot` (`clinician_id`,`slot_date`,`slot_time`),
            KEY `idx_slots_avail` (`clinician_id`,`slot_date`,`is_open`),
            CONSTRAINT `fk_slots_clinician` FOREIGN KEY (`clinician_id`) REFERENCES ' . $db->table('cpms_clinicians') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_slot_holds') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `slot_id` BIGINT UNSIGNED NOT NULL,
            `holder_wp_user_id` BIGINT UNSIGNED NULL,
            `holder_mobile` VARCHAR(32) NULL,
            `token` CHAR(36) NOT NULL,
            `expires_at` DATETIME(3) NOT NULL,
            `status` ENUM(\'active\',\'converted\',\'expired\',\'released\') NOT NULL DEFAULT \'active\',
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_hold_token` (`token`),
            KEY `idx_hold_slot` (`slot_id`,`status`),
            KEY `idx_hold_exp` (`status`,`expires_at`),
            CONSTRAINT `fk_holds_slot` FOREIGN KEY (`slot_id`) REFERENCES ' . $db->table('cpms_schedule_slots') . ' (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_appointments') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `reference_code` VARCHAR(24) NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `slot_id` BIGINT UNSIGNED NOT NULL,
            `slot_date` DATE NOT NULL,
            `slot_time` TIME NOT NULL,
            `wp_user_id` BIGINT UNSIGNED NULL,
            `reason` VARCHAR(255) NULL,
            `status` ENUM(\'pending\',\'confirmed\',\'cancelled_by_patient\',\'cancelled_by_staff\',\'rescheduled\',\'completed\',\'no_show\') NOT NULL DEFAULT \'pending\',
            `is_walkin_express` TINYINT(1) NOT NULL DEFAULT 0,
            `rescheduled_from` BIGINT UNSIGNED NULL,
            `rescheduled_to` BIGINT UNSIGNED NULL,
            `active_visit_id` BIGINT UNSIGNED NULL,
            `booked_at` DATETIME(3) NULL,
            `confirmed_at` DATETIME(3) NULL,
            `cancelled_at` DATETIME(3) NULL,
            `cancel_reason` VARCHAR(255) NULL,
            `cancelled_by_wp_user_id` BIGINT UNSIGNED NULL,
            `no_show_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_appt_ref` (`reference_code`),
            KEY `idx_appt_day` (`clinician_id`,`slot_date`,`status`),
            KEY `idx_appt_patient` (`patient_id`,`slot_date`),
            KEY `idx_appt_visit` (`active_visit_id`),
            KEY `idx_appt_slot` (`slot_id`),
            CONSTRAINT `fk_appt_clinician` FOREIGN KEY (`clinician_id`) REFERENCES ' . $db->table('cpms_clinicians') . ' (`id`),
            CONSTRAINT `fk_appt_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_appt_slot` FOREIGN KEY (`slot_id`) REFERENCES ' . $db->table('cpms_schedule_slots') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // ============ 4. مراجعه / صف ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_visits') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `appointment_id` BIGINT UNSIGNED NULL,
            `source` ENUM(\'scheduled\',\'walk_in\') NOT NULL DEFAULT \'walk_in\',
            `status` ENUM(\'checked_in\',\'waiting\',\'called\',\'in_consultation\',\'consultation_completed\',\'awaiting_payment\',\'paid\',\'checked_out\',\'cancelled\',\'skipped\') NOT NULL DEFAULT \'checked_in\',
            `visit_date` DATE NOT NULL,
            `check_in_at` DATETIME(3) NOT NULL,
            `waiting_since` DATETIME(3) NULL,
            `called_at` DATETIME(3) NULL,
            `consultation_started_at` DATETIME(3) NULL,
            `consultation_completed_at` DATETIME(3) NULL,
            `checked_out_at` DATETIME(3) NULL,
            `cancel_reason` VARCHAR(255) NULL,
            `skip_reason` VARCHAR(255) NULL,
            `cancelled_by_wp_user_id` BIGINT UNSIGNED NULL,
            `recall_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_visit_day` (`clinic_id`,`visit_date`,`status`),
            KEY `idx_visit_queue` (`clinician_id`,`status`,`waiting_since`),
            KEY `idx_visit_patient` (`patient_id`,`visit_date`),
            KEY `idx_visit_appt` (`appointment_id`),
            CONSTRAINT `fk_visit_clinician` FOREIGN KEY (`clinician_id`) REFERENCES ' . $db->table('cpms_clinicians') . ' (`id`),
            CONSTRAINT `fk_visit_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_visit_appt` FOREIGN KEY (`appointment_id`) REFERENCES ' . $db->table('cpms_appointments') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_visit_status_history') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `from_status` VARCHAR(32) NULL,
            `to_status` VARCHAR(32) NOT NULL,
            `changed_at` DATETIME(3) NOT NULL,
            `actor_wp_user_id` BIGINT UNSIGNED NULL,
            `actor_role` VARCHAR(32) NOT NULL,
            `note` VARCHAR(255) NULL,
            `request_id` VARCHAR(64) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_vsh_visit` (`visit_id`,`changed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // ============ 5. بالینی ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_clinical_notes') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `category` ENUM(\'chief_complaint\',\'history\',\'examination\',\'diagnosis\',\'clinical_note\',\'recommendation_text\',\'private_note\',\'other\') NOT NULL DEFAULT \'clinical_note\',
            `visibility` ENUM(\'patient_visible\',\'doctor_private\') NOT NULL DEFAULT \'patient_visible\',
            `content_text` TEXT NOT NULL,
            `content_html` MEDIUMTEXT NULL,
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `correction_of_note_id` BIGINT UNSIGNED NULL,
            `change_reason` VARCHAR(255) NULL,
            `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
            `created_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `updated_by_wp_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_note_visit` (`visit_id`,`category`),
            KEY `idx_note_patient` (`patient_id`,`created_at`),
            KEY `idx_note_vis` (`patient_id`,`visibility`),
            CONSTRAINT `fk_note_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_note_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_note_clinician` FOREIGN KEY (`clinician_id`) REFERENCES ' . $db->table('cpms_clinicians') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_clinical_note_versions') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `note_id` BIGINT UNSIGNED NOT NULL,
            `version` INT UNSIGNED NOT NULL,
            `content_snapshot` MEDIUMTEXT NOT NULL,
            `changed_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `change_reason` VARCHAR(255) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_note_version` (`note_id`,`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_handwriting_documents') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NULL,
            `page_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_hwdoc_visit` (`visit_id`),
            CONSTRAINT `fk_hwdoc_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_handwriting_pages') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `document_id` BIGINT UNSIGNED NOT NULL,
            `page_index` SMALLINT UNSIGNED NOT NULL,
            `width` INT UNSIGNED NOT NULL,
            `height` INT UNSIGNED NOT NULL,
            `stroke_data` LONGTEXT NOT NULL,
            `stroke_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `preview_png` VARCHAR(255) NULL,
            `preview_pdf` VARCHAR(255) NULL,
            `background_template` ENUM(\'blank\',\'lined\',\'graph\',\'form\') NOT NULL DEFAULT \'lined\',
            `client_revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `last_saved_at` DATETIME(3) NULL,
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_hw_page` (`document_id`,`page_index`),
            CONSTRAINT `fk_hwpage_doc` FOREIGN KEY (`document_id`) REFERENCES ' . $db->table('cpms_handwriting_documents') . ' (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_handwriting_page_versions') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `page_id` BIGINT UNSIGNED NOT NULL,
            `version` INT UNSIGNED NOT NULL,
            `stroke_data` LONGTEXT NOT NULL,
            `saved_by` ENUM(\'autosave\',\'manual\',\'sync_recovery\') NOT NULL DEFAULT \'autosave\',
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_hw_page_version` (`page_id`,`version`),
            CONSTRAINT `fk_hwversion_page` FOREIGN KEY (`page_id`) REFERENCES ' . $db->table('cpms_handwriting_pages') . ' (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_ocr_jobs') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `source_page_id` BIGINT UNSIGNED NOT NULL,
            `provider` VARCHAR(64) NOT NULL,
            `model` VARCHAR(128) NULL,
            `status` ENUM(\'queued\',\'processing\',\'success\',\'failed\',\'cancelled\') NOT NULL DEFAULT \'queued\',
            `confidence` DECIMAL(5,4) NULL,
            `extracted_text` MEDIUMTEXT NULL,
            `review_status` ENUM(\'pending\',\'reviewed\',\'confirmed\',\'rejected\') NOT NULL DEFAULT \'pending\',
            `reviewed_by_wp_user_id` BIGINT UNSIGNED NULL,
            `reviewed_at` DATETIME(3) NULL,
            `confirmed_text` MEDIUMTEXT NULL,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(255) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `completed_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ocr_status` (`status`,`created_at`),
            KEY `idx_ocr_page` (`source_page_id`),
            CONSTRAINT `fk_ocr_page` FOREIGN KEY (`source_page_id`) REFERENCES ' . $db->table('cpms_handwriting_pages') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_prescriptions') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `prescription_number` VARCHAR(24) NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM(\'draft\',\'finalized\',\'voided\') NOT NULL DEFAULT \'draft\',
            `is_patient_visible` TINYINT(1) NOT NULL DEFAULT 1,
            `void_reason` VARCHAR(255) NULL,
            `correction_of_prescription_id` BIGINT UNSIGNED NULL,
            `finalized_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_rx_number` (`prescription_number`),
            KEY `idx_rx_visit` (`visit_id`),
            KEY `idx_rx_patient` (`patient_id`,`created_at`),
            CONSTRAINT `fk_rx_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_rx_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_drug_reference') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
            `generic_name` VARCHAR(190) NOT NULL,
            `brand_name` VARCHAR(190) NULL,
            `strength` VARCHAR(64) NOT NULL,
            `form` ENUM(\'tablet\',\'capsule\',\'syrup\',\'injection\',\'ointment\',\'drops\',\'inhaler\',\'other\') NOT NULL DEFAULT \'tablet\',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_drug` (`clinic_id`,`generic_name`,`strength`,`form`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_prescription_items') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `prescription_id` BIGINT UNSIGNED NOT NULL,
            `drug_ref_id` BIGINT UNSIGNED NULL,
            `generic_name` VARCHAR(190) NOT NULL,
            `brand_name` VARCHAR(190) NULL,
            `strength` VARCHAR(64) NULL,
            `form` ENUM(\'tablet\',\'capsule\',\'syrup\',\'injection\',\'ointment\',\'drops\',\'inhaler\',\'other\') NOT NULL DEFAULT \'tablet\',
            `dose` VARCHAR(64) NOT NULL,
            `frequency` VARCHAR(64) NOT NULL,
            `route` ENUM(\'oral\',\'iv\',\'im\',\'sc\',\'topical\',\'inhaled\',\'other\') NOT NULL DEFAULT \'oral\',
            `duration_days` SMALLINT UNSIGNED NULL,
            `instructions` VARCHAR(500) NULL,
            `source` ENUM(\'manual\',\'ocr_confirmed\') NOT NULL DEFAULT \'manual\',
            `ocr_job_id` BIGINT UNSIGNED NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rxitem_rx` (`prescription_id`,`sort_order`),
            CONSTRAINT `fk_rxitem_rx` FOREIGN KEY (`prescription_id`) REFERENCES ' . $db->table('cpms_prescriptions') . ' (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rxitem_drug` FOREIGN KEY (`drug_ref_id`) REFERENCES ' . $db->table('cpms_drug_reference') . ' (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_recommendations') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `type` ENUM(\'diet\',\'rest\',\'activity\',\'care\',\'lab\',\'followup\',\'other\') NOT NULL DEFAULT \'other\',
            `text` VARCHAR(1000) NOT NULL,
            `is_patient_visible` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rec_visit` (`visit_id`),
            KEY `idx_rec_patient` (`patient_id`,`created_at`),
            CONSTRAINT `fk_rec_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_follow_ups') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `clinician_id` BIGINT UNSIGNED NOT NULL,
            `is_needed` TINYINT(1) NOT NULL DEFAULT 1,
            `suggested_date` DATE NULL,
            `interval_days` SMALLINT UNSIGNED NULL,
            `reason` VARCHAR(255) NULL,
            `status` ENUM(\'pending\',\'booked\',\'done\',\'cancelled\') NOT NULL DEFAULT \'pending\',
            `linked_appointment_id` BIGINT UNSIGNED NULL,
            `reminder_sent_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_fu_due` (`status`,`suggested_date`),
            CONSTRAINT `fk_fu_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_fu_appt` FOREIGN KEY (`linked_appointment_id`) REFERENCES ' . $db->table('cpms_appointments') . ' (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_medical_attachments') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NULL,
            `category` ENUM(\'lab_result\',\'image\',\'scan\',\'document\',\'other\') NOT NULL DEFAULT \'other\',
            `original_filename` VARCHAR(255) NOT NULL,
            `stored_filename` VARCHAR(64) NOT NULL,
            `mime_type` VARCHAR(120) NOT NULL,
            `file_size` INT UNSIGNED NOT NULL,
            `storage_path` VARCHAR(255) NOT NULL,
            `visibility` ENUM(\'patient_visible\',\'doctor_private\') NOT NULL DEFAULT \'patient_visible\',
            `metadata_json` JSON NULL,
            `uploaded_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `deleted_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_att_store` (`clinic_id`,`stored_filename`),
            KEY `idx_att_patient` (`patient_id`,`created_at`),
            KEY `idx_att_visit` (`visit_id`),
            CONSTRAINT `fk_att_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_att_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // ============ 6. مالی ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_services') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `code` VARCHAR(32) NOT NULL,
            `name` VARCHAR(190) NOT NULL,
            `price` DECIMAL(12,2) NOT NULL,
            `currency` CHAR(3) NOT NULL DEFAULT \'IRR\',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_service_code` (`clinic_id`,`code`),
            CONSTRAINT `fk_services_clinic` FOREIGN KEY (`clinic_id`) REFERENCES ' . $db->table('cpms_clinics') . ' (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_invoices') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `invoice_number` VARCHAR(24) NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `visit_id` BIGINT UNSIGNED NOT NULL,
            `status` ENUM(\'open\',\'partial\',\'paid\',\'voided\') NOT NULL DEFAULT \'open\',
            `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `discount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `tax` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `total` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `currency` CHAR(3) NOT NULL DEFAULT \'IRR\',
            `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `balance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `issued_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `void_reason` VARCHAR(255) NULL,
            `voided_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_inv_number` (`clinic_id`,`invoice_number`),
            KEY `idx_inv_visit` (`visit_id`),
            KEY `idx_inv_patient` (`patient_id`,`created_at`),
            KEY `idx_inv_status` (`clinic_id`,`status`,`created_at`),
            CONSTRAINT `fk_inv_patient` FOREIGN KEY (`patient_id`) REFERENCES ' . $db->table('cpms_patients') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_inv_visit` FOREIGN KEY (`visit_id`) REFERENCES ' . $db->table('cpms_visits') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_invoice_items') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `service_id` BIGINT UNSIGNED NULL,
            `description` VARCHAR(255) NOT NULL,
            `quantity` DECIMAL(8,2) NOT NULL DEFAULT 1,
            `unit_price` DECIMAL(12,2) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `discount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_invitem_inv` (`invoice_id`),
            CONSTRAINT `fk_invitem_inv` FOREIGN KEY (`invoice_id`) REFERENCES ' . $db->table('cpms_invoices') . ' (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_invitem_service` FOREIGN KEY (`service_id`) REFERENCES ' . $db->table('cpms_services') . ' (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_payments') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `payment_number` VARCHAR(24) NOT NULL,
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `patient_id` BIGINT UNSIGNED NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `method` ENUM(\'cash\',\'card_pos\',\'online\',\'other\') NOT NULL,
            `transaction_ref` VARCHAR(128) NULL,
            `idempotency_key` VARCHAR(128) NOT NULL,
            `status` ENUM(\'captured\',\'voided\',\'refunded\') NOT NULL DEFAULT \'captured\',
            `refunded_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `paid_at` DATETIME(3) NOT NULL,
            `received_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `void_reason` VARCHAR(255) NULL,
            `voided_at` DATETIME(3) NULL,
            `voided_by_wp_user_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_pay_number` (`clinic_id`,`payment_number`),
            UNIQUE KEY `u_pay_idem` (`invoice_id`,`idempotency_key`),
            KEY `idx_pay_invoice` (`invoice_id`,`created_at`),
            KEY `idx_pay_patient` (`patient_id`,`created_at`),
            KEY `idx_pay_day` (`clinic_id`,`paid_at`),
            CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES ' . $db->table('cpms_invoices') . ' (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_payment_adjustments') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `payment_id` BIGINT UNSIGNED NULL,
            `type` ENUM(\'credit\',\'debit\') NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `reason` VARCHAR(255) NOT NULL,
            `approved_by_wp_user_id` BIGINT UNSIGNED NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_adj_invoice` (`invoice_id`),
            CONSTRAINT `fk_adj_invoice` FOREIGN KEY (`invoice_id`) REFERENCES ' . $db->table('cpms_invoices') . ' (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_adj_payment` FOREIGN KEY (`payment_id`) REFERENCES ' . $db->table('cpms_payments') . ' (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        // ============ 7. اعلان / زیرساخت ============
        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_notifications') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `recipient_wp_user_id` BIGINT UNSIGNED NULL,
            `recipient_patient_id` BIGINT UNSIGNED NULL,
            `channel` ENUM(\'internal\',\'sms\',\'email\',\'push\') NOT NULL,
            `template` VARCHAR(64) NOT NULL,
            `payload_json` JSON NOT NULL,
            `status` ENUM(\'queued\',\'sent\',\'delivered\',\'failed\',\'cancelled\') NOT NULL DEFAULT \'queued\',
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `next_retry_at` DATETIME(3) NULL,
            `provider` VARCHAR(64) NULL,
            `provider_ref` VARCHAR(128) NULL,
            `last_error` VARCHAR(255) NULL,
            `dedupe_key` VARCHAR(190) NULL,
            `scheduled_at` DATETIME(3) NULL,
            `sent_at` DATETIME(3) NULL,
            `delivered_at` DATETIME(3) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_notif_dedupe` (`dedupe_key`),
            KEY `idx_notif_rcpt` (`recipient_wp_user_id`,`status`,`created_at`),
            KEY `idx_notif_retry` (`status`,`next_retry_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_jobs') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(64) NOT NULL,
            `payload_json` JSON NULL,
            `status` ENUM(\'queued\',\'processing\',\'success\',\'failed\') NOT NULL DEFAULT \'queued\',
            `priority` TINYINT NOT NULL DEFAULT 5,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            `run_after` DATETIME(3) NOT NULL,
            `locked_by` VARCHAR(64) NULL,
            `lock_expires_at` DATETIME(3) NULL,
            `last_error` VARCHAR(255) NULL,
            `created_at` DATETIME(3) NOT NULL,
            `started_at` DATETIME(3) NULL,
            `completed_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_job_due` (`status`,`run_after`,`priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_audit_logs') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `actor_wp_user_id` BIGINT UNSIGNED NULL,
            `actor_role` VARCHAR(32) NOT NULL,
            `action` VARCHAR(64) NOT NULL,
            `resource_type` VARCHAR(48) NOT NULL,
            `resource_id` BIGINT UNSIGNED NULL,
            `patient_id` BIGINT UNSIGNED NULL,
            `ip_hash` CHAR(64) NULL,
            `session_id` VARCHAR(64) NULL,
            `request_id` VARCHAR(64) NULL,
            `before_json` JSON NULL,
            `after_json` JSON NULL,
            `meta_json` JSON NULL,
            `prev_hash` CHAR(64) NOT NULL,
            `row_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_audit_res` (`resource_type`,`resource_id`,`created_at`),
            KEY `idx_audit_actor` (`actor_wp_user_id`,`created_at`),
            KEY `idx_audit_action` (`action`,`created_at`),
            KEY `idx_audit_patient` (`patient_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_operational_logs') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `level` ENUM(\'debug\',\'info\',\'warning\',\'error\') NOT NULL DEFAULT \'info\',
            `message` VARCHAR(500) NOT NULL,
            `context_json` JSON NULL,
            `request_id` VARCHAR(64) NULL,
            `created_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_oplog_level` (`level`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_settings') . ' (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `clinic_id` BIGINT UNSIGNED NOT NULL,
            `key` VARCHAR(128) NOT NULL,
            `value_json` JSON NOT NULL,
            `updated_by_wp_user_id` BIGINT UNSIGNED NULL,
            `updated_at` DATETIME(3) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `u_setting_key` (`clinic_id`,`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS ' . $db->table('cpms_rate_limits') . ' (
            `window_key` VARCHAR(190) NOT NULL,
            `window_id` INT UNSIGNED NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`window_key`,`window_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        foreach ($sql as $statement) {
            $db->query($statement);
        }

        // Seed: کلینیک پیش‌فرض (V1: واحد)
        $db->query(
            'INSERT IGNORE INTO ' . $db->table('cpms_clinics') . " (id, name, slug, timezone, created_at, updated_at)
             VALUES (1, 'کلینیک پیش‌فرض', 'default', 'Asia/Tehran', {$now}, {$now})"
        );
    },

    'down' => function (CpmsDb $db): void {
        // WARNING: حذف کلی داده — فقط برای محیط توسعه. در Production: Restore از Backup.
        $tables = [
            'cpms_rate_limits', 'cpms_settings', 'cpms_operational_logs', 'cpms_audit_logs',
            'cpms_jobs', 'cpms_notifications', 'cpms_payment_adjustments', 'cpms_payments',
            'cpms_invoice_items', 'cpms_invoices', 'cpms_services', 'cpms_medical_attachments',
            'cpms_follow_ups', 'cpms_recommendations', 'cpms_prescription_items', 'cpms_drug_reference',
            'cpms_prescriptions', 'cpms_ocr_jobs', 'cpms_handwriting_page_versions',
            'cpms_handwriting_pages', 'cpms_handwriting_documents', 'cpms_clinical_note_versions',
            'cpms_clinical_notes', 'cpms_visit_status_history', 'cpms_visits', 'cpms_appointments',
            'cpms_slot_holds', 'cpms_schedule_slots', 'cpms_schedule_exceptions', 'cpms_schedule',
            'cpms_idempotency_keys', 'cpms_otp_tokens', 'cpms_patient_merges',
            'cpms_patient_user_links', 'cpms_patients', 'cpms_clinicians', 'cpms_clinics',
        ];
        foreach ($tables as $t) {
            $db->query('DROP TABLE IF EXISTS ' . $db->table($t));
        }
        $db->query('DELETE FROM ' . $db->table('cpms_schema_migrations') . " WHERE version = '2026_09_05_0001'");
    },
];

<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0017 — Phase 2 (M-09 تصحیح‌شده — ۲۲ FK، نه ۲۱):
 *
 * افزودن FK معنایی `clinic_id → clinics(id)` برای ۲۲ جدولی که ستون دارند ولی
 * تضمین DB ندارند (ماتریس معنایی د-۶-۲ — هر ردیف توجیه معنایی دارد، نه
 * مکانیکی). ۴ جدول (clinicians/patients/schedule/services) از قبل FK دارند.
 *
 * ایمنی: برای هر جدول، Preflight orphan-check (الگوی 0007 — fail-loud، بدون
 * تغییر داده). Idempotent با information_schema.
 */
return [
    'version' => '2026_09_09_0017',
    'description' => 'Phase 2: 22 semantic FKs clinic_id → clinics(id) with orphan preflight',
    'up' => function (CpmsDb $db): void {
        $targets = [
            'cpms_appointments' => 'fk_appointments_clinic',
            'cpms_audit_logs' => 'fk_audit_logs_clinic',
            'cpms_clinical_notes' => 'fk_clinical_notes_clinic',
            'cpms_drug_reference' => 'fk_drug_reference_clinic',
            'cpms_follow_ups' => 'fk_follow_ups_clinic',
            'cpms_handwriting_documents' => 'fk_handwriting_documents_clinic',
            'cpms_idempotency_keys' => 'fk_idempotency_keys_clinic',
            'cpms_invoices' => 'fk_invoices_clinic',
            'cpms_medical_attachments' => 'fk_medical_attachments_clinic',
            'cpms_notifications' => 'fk_notifications_clinic',
            'cpms_ocr_jobs' => 'fk_ocr_jobs_clinic',
            'cpms_patient_merges' => 'fk_patient_merges_clinic',
            'cpms_patient_user_links' => 'fk_patient_user_links_clinic',
            'cpms_payments' => 'fk_payments_clinic',
            'cpms_prescriptions' => 'fk_prescriptions_clinic',
            'cpms_recommendations' => 'fk_recommendations_clinic',
            'cpms_schedule_exceptions' => 'fk_schedule_exceptions_clinic',
            'cpms_schedule_slots' => 'fk_schedule_slots_clinic',
            'cpms_settings' => 'fk_settings_clinic',
            'cpms_slot_holds' => 'fk_slot_holds_clinic',
            'cpms_sms_messages' => 'fk_sms_messages_clinic',
            'cpms_visits' => 'fk_visits_clinic',
        ];

        // ---------- Preflight: هیچ clinic_id یتیمی نباید باشد ----------
        $orphans = [];
        foreach (array_keys($targets) as $name) {
            $t = $db->table($name);
            $count = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM {$t}
                 WHERE clinic_id IS NOT NULL AND clinic_id NOT IN (SELECT id FROM " . $db->table('cpms_clinics') . ')'
            );
            if ($count > 0) {
                $orphans[] = $name . ' (' . $count . ' rows)';
            }
        }
        if ($orphans !== []) {
            throw new RuntimeException(
                'Migration 0017 aborted: orphan clinic_id rows found — ' . implode(', ', $orphans) .
                '. Resolve manually (fix or remove orphan rows), then re-run. No data was changed.'
            );
        }

        foreach ($targets as $name => $fkName) {
            $t = $db->table($name);
            $fk = $db->fetchRow(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
                   AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$t, $fkName]
            );
            if ((int) ($fk['n'] ?? 0) === 0) {
                $db->query(
                    "ALTER TABLE {$t} ADD CONSTRAINT `{$fkName}`
                     FOREIGN KEY (`clinic_id`) REFERENCES " . $db->table('cpms_clinics') . ' (`id`)'
                );
            }
        }
    },
    'down' => function (CpmsDb $db): void {
        $targets = [
            'cpms_appointments' => 'fk_appointments_clinic',
            'cpms_audit_logs' => 'fk_audit_logs_clinic',
            'cpms_clinical_notes' => 'fk_clinical_notes_clinic',
            'cpms_drug_reference' => 'fk_drug_reference_clinic',
            'cpms_follow_ups' => 'fk_follow_ups_clinic',
            'cpms_handwriting_documents' => 'fk_handwriting_documents_clinic',
            'cpms_idempotency_keys' => 'fk_idempotency_keys_clinic',
            'cpms_invoices' => 'fk_invoices_clinic',
            'cpms_medical_attachments' => 'fk_medical_attachments_clinic',
            'cpms_notifications' => 'fk_notifications_clinic',
            'cpms_ocr_jobs' => 'fk_ocr_jobs_clinic',
            'cpms_patient_merges' => 'fk_patient_merges_clinic',
            'cpms_patient_user_links' => 'fk_patient_user_links_clinic',
            'cpms_payments' => 'fk_payments_clinic',
            'cpms_prescriptions' => 'fk_prescriptions_clinic',
            'cpms_recommendations' => 'fk_recommendations_clinic',
            'cpms_schedule_exceptions' => 'fk_schedule_exceptions_clinic',
            'cpms_schedule_slots' => 'fk_schedule_slots_clinic',
            'cpms_settings' => 'fk_settings_clinic',
            'cpms_slot_holds' => 'fk_slot_holds_clinic',
            'cpms_sms_messages' => 'fk_sms_messages_clinic',
            'cpms_visits' => 'fk_visits_clinic',
        ];
        foreach ($targets as $name => $fkName) {
            $t = $db->table($name);
            $fk = $db->fetchRow(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
                   AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$t, $fkName]
            );
            if ((int) ($fk['n'] ?? 0) > 0) {
                $db->query("ALTER TABLE {$t} DROP FOREIGN KEY `{$fkName}`");
            }
        }
    },
];

<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0016 — Phase 2 (M-08b + B-11 — تصحیح Pre-Phase-2 Gate):
 *
 *  1) `cpms_sms_messages.clinic_id`: INT UNSIGNED → BIGINT UNSIGNED
 *     (هم‌نوع با clinics.id) + حذف DEFAULT 1.
 *  2) حذف DEFAULT 1 از `cpms_drug_reference.clinic_id` و
 *     `cpms_idempotency_keys.clinic_id` (الگوی tenant=1 در سطح schema — AD-13).
 *  3) `cpms_audit_logs.clinic_id` → NULL-able (B-11): رویدادهای پیش از Scope
 *     کلینیکی (مثل LOGIN_*) بدون Clinic ثبت می‌شوند؛ NULL = سیستمی، نه «1».
 *
 * Idempotent: SHOW COLUMNS (نوع/Default/Null بررسی می‌شود).
 */
return [
    'version' => '2026_09_09_0016',
    'description' => 'Phase 2: tenant column hygiene — sms BIGINT, drop DEFAULT tenant=1, audit_logs.clinic_id nullable (B-11)',
    'up' => function (CpmsDb $db): void {
        // ---------- 1) sms_messages: نوع + DEFAULT ----------
        $sms = $db->table('cpms_sms_messages');
        $col = $db->fetchRow("SHOW COLUMNS FROM {$sms} LIKE 'clinic_id'");
        if ($col !== null) {
            $needsType = stripos((string) $col['Type'], 'bigint') === false;
            if ($needsType || (string) $col['Default'] === '1') {
                $db->query("ALTER TABLE {$sms} MODIFY `clinic_id` BIGINT UNSIGNED NOT NULL");
            }
        }

        // ---------- 2) حذف DEFAULT 1 از drug_reference / idempotency_keys ----------
        foreach (['cpms_drug_reference', 'cpms_idempotency_keys'] as $name) {
            $t = $db->table($name);
            $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'clinic_id'");
            if ($col !== null && (string) $col['Default'] === '1') {
                $db->query("ALTER TABLE {$t} MODIFY `clinic_id` BIGINT UNSIGNED NOT NULL");
            }
        }

        // ---------- 3) audit_logs.clinic_id → NULL-able (B-11) ----------
        $audit = $db->table('cpms_audit_logs');
        $col = $db->fetchRow("SHOW COLUMNS FROM {$audit} LIKE 'clinic_id'");
        if ($col !== null && strtoupper((string) $col['Null']) === 'NO') {
            $db->query("ALTER TABLE {$audit} MODIFY `clinic_id` BIGINT UNSIGNED NULL");
        }
    },
    'down' => function (CpmsDb $db): void {
        // برگشت نوع sms (اگر NULL نباشد) و بازگردانی NOT NULL برای audit_logs.
        // DEFAULT 1 عمداً بازنمی‌گردد (الگوی tenant=1 حذف‌شده باقی می‌ماند).
        $sms = $db->table('cpms_sms_messages');
        $col = $db->fetchRow("SHOW COLUMNS FROM {$sms} LIKE 'clinic_id'");
        if ($col !== null) {
            $db->query("ALTER TABLE {$sms} MODIFY `clinic_id` INT UNSIGNED NOT NULL");
        }

        $audit = $db->table('cpms_audit_logs');
        $nulls = $db->fetchValue("SELECT COUNT(*) FROM {$audit} WHERE clinic_id IS NULL");
        if ((int) $nulls === 0) {
            $col = $db->fetchRow("SHOW COLUMNS FROM {$audit} LIKE 'clinic_id'");
            if ($col !== null && strtoupper((string) $col['Null']) === 'YES') {
                $db->query("ALTER TABLE {$audit} MODIFY `clinic_id` BIGINT UNSIGNED NOT NULL");
            }
        }
    },
];

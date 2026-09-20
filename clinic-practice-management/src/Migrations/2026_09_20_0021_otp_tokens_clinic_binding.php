<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0021 — Phase 8 Slice 2 (تصمیم مالک: Hold-Timing + هویت حداقلی بیمار):
 *
 * معماری OTP تا امروز هیچ پیوندِ پایداری بین Challenge و Clinic نداشت —
 * اثبات‌شده که هیچ شناسهٔ پایدارِ قابل‌اعتمادِ موجودی این پیوند را امن تأمین
 * نمی‌کند (نه mobile، نه purpose، نه Settings محیطی). بنابراین تغییر Schema
 * عیناً توجیه دارد:
 *
 *   cpms_otp_tokens.clinic_id
 *     - BIGINT UNSIGNED؛
 *     - NULL مجاز (NO DEFAULT) — ردیف‌های تاریخی/بدون Clinic پشتیبانی می‌شوند؛
 *     - FK → cpms_clinics(id)؛
 *     - بدون Backfill — هیچ دادهٔ تاریخی حدس زده نمی‌شود؛
 *     - ایندکس صریح جدید نه (InnoDB برای FK ایندکس لازم را خودش می‌سازد).
 *
 * مصرف (Slice 2): A2 با انتخابِ bookable (clinician/slot/date/time) Clinic را
 * فقط از دادهٔ persisted مشتق می‌کند و روی Challenge مُهر می‌زند؛ A3 سیاست OTP،
 * جست‌وجوی Patient و Clinicِ لینک را از همین ستون می‌خواند — نه از بدنهٔ کلاینت،
 * نه از Scope محیطی. ردیف‌های NULL تاریخی: تک‌Clinic = resolver تثبیت‌شده،
 * چند-Clinic = fail-closed با پاکت CLINIC_SCOPE_REQUIRED (هرگز 500).
 *
 * ایمنی (قاعدهٔ کارفرما — الگوی 0017/0020):
 *  - Preflight: در شاخهٔ FK، هر clinic_id غیر NULL باید به Clinic واقعی
 *    ارجاع دهد؛ وگرنه Migration با خطا متوقف می‌شود — بدون حذف/تغییر داده.
 *  - همهٔ مراحل Idempotent (SHOW COLUMNS / information_schema) → اجرای مجدد
 *    پس از شکست امن است.
 *  - down(): ابتدا FK، سپس ستون — inverse دقیق up().
 */
return [
    'version' => '2026_09_20_0021',
    'description' => 'Phase 8 Slice 2: durably bind the OTP challenge to its Clinic — cpms_otp_tokens.clinic_id (BIGINT UNSIGNED NULL, FK → clinics(id), no backfill)',
    'up' => function (CpmsDb $db): void {
        $t = $db->table('cpms_otp_tokens');
        $fkName = 'fk_otp_tokens_clinic';

        // ---------- 1) ستون (Idempotent با SHOW COLUMNS) ----------
        $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'clinic_id'");
        if ($col === null) {
            // NULL مجاز، بدون DEFAULT صریح — ردیف‌های تاریخی بدون Clinic می‌مانند.
            $db->query("ALTER TABLE {$t} ADD COLUMN `clinic_id` BIGINT UNSIGNED NULL AFTER `purpose`");
        } else {
            // Forward-safe: نصبِ نیمه‌اعمال‌شده با تعریفِ ناسازگار = توقف روشن.
            $type = strtolower((string) ($col['Type'] ?? ''));
            $nullable = strtoupper((string) ($col['Null'] ?? ''));
            if (!str_contains($type, 'bigint') || $nullable !== 'YES') {
                throw new RuntimeException(
                    'Migration 0021 aborted: cpms_otp_tokens.clinic_id already exists with an incompatible ' .
                    "definition (type={$type}, nullable={$nullable}) — resolve manually, then re-run. No data was changed."
                );
            }
        }

        // ---------- 2) FK (Idempotent با information_schema — الگوی 0017) ----------
        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$t, $fkName]
        );
        if ((int) ($fk['n'] ?? 0) === 0) {
            // Preflight (fail-loud، بدون تغییر داده): هیچ مقدار غیر NULL نباید
            // به Clinic ناموجود ارجاع دهد — وگرنه افزودن FK ممکن نیست.
            $orphans = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM {$t}
                 WHERE clinic_id IS NOT NULL AND clinic_id NOT IN (SELECT id FROM " . $db->table('cpms_clinics') . ')'
            );
            if ($orphans > 0) {
                throw new RuntimeException(
                    'Migration 0021 aborted: orphan clinic_id rows found in cpms_otp_tokens (' . $orphans .
                    ' rows) — resolve manually, then re-run. No data was changed.'
                );
            }

            $db->query(
                "ALTER TABLE {$t} ADD CONSTRAINT `{$fkName}`
                 FOREIGN KEY (`clinic_id`) REFERENCES " . $db->table('cpms_clinics') . ' (`id`)'
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $t = $db->table('cpms_otp_tokens');

        // ترتیب قرارداد (T24): ابتدا FK، سپس ستون.
        $fk = $db->fetchRow(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$t, 'fk_otp_tokens_clinic']
        );
        if ((int) ($fk['n'] ?? 0) > 0) {
            $db->query("ALTER TABLE {$t} DROP FOREIGN KEY `fk_otp_tokens_clinic`");
        }

        $col = $db->fetchRow("SHOW COLUMNS FROM {$t} LIKE 'clinic_id'");
        if ($col !== null) {
            $db->query("ALTER TABLE {$t} DROP COLUMN `clinic_id`");
        }
    },
];

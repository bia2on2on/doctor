<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0007 — یکپارچگی 1:1 Clinician ↔ WordPress User (ADR-0027 Minor #12):
 *
 * `cpms_clinicians.wp_user_id` تاکنون فقط «به‌قرارداد» یکتا بود (ownClinician با
 * LIMIT 1). این Migration تضمین ساختاری می‌سازد: یک WP User حداکثر به یک
 * Clinician متصل است — کلیدِ Scope سرور-side چندپزشکی (ADR-0026 D-4).
 *
 * NULL semantics: NULL = Clinician بدون حساب ورود (مجاز، چندتایی) — UNIQUE
 * ایندکس MySQL مقادیر NULL را نادیده می‌گیرد.
 *
 * ایمنی (قاعده کارفرما):
 *  - Preflight: تکراری‌های wp_user_id غیر NULL → توقف با خطا + جزئیات؛
 *    **بدون حذف/merge خودکار**.
 *  - Idempotent (SHOW INDEX) → اجرای مجدد امن.
 *  - down(): فقط DROP INDEX (بدون تغییر داده).
 */

return [
    'version' => '2026_09_07_0007',
    'description' => 'Clinician<->WP user 1:1 UNIQUE index (ADR-0027 Minor #12, F9)',
    'up' => function (CpmsDb $db): void {
        $t = $db->table('cpms_clinicians');

        // ---------- 1) Preflight (بدون تغییر داده) ----------
        $dups = $db->fetchAll(
            'SELECT wp_user_id, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids' .
            ' FROM ' . $t .
            ' WHERE wp_user_id IS NOT NULL' .
            ' GROUP BY wp_user_id HAVING n > 1 LIMIT 5'
        );
        if (is_array($dups) && $dups !== []) {
            $detail = implode('; ', array_map(
                static fn (array $r): string => 'wp_user_id=' . $r['wp_user_id'] . ' (clinicians ' . $r['ids'] . ')',
                $dups
            ));
            throw new RuntimeException(
                'Migration 0007 aborted: clinicians share the same wp_user_id [' . $detail . '] — ' .
                'decide the correct mapping manually (keep one link, NULL the others), then re-run. No data was changed.'
            );
        }

        // ---------- 2) ایندکس یونیک ----------
        $has = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_clinician_user'");
        if ($has === null) {
            $db->query('ALTER TABLE ' . $t . ' ADD UNIQUE KEY `u_clinician_user` (`wp_user_id`)');
        }
    },
    'down' => function (CpmsDb $db): void {
        $t = $db->table('cpms_clinicians');
        $has = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_clinician_user'");
        if ($has !== null) {
            $db->query('ALTER TABLE ' . $t . ' DROP INDEX `u_clinician_user`');
        }
    },
];

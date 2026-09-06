<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0006 — اصلاح دامنه یکتایی Idempotency (بدهی F7 §9 → F9 Hardening):
 *
 * مسئله (ریشه‌ای): UNIQUE فقط روی `key` بود در حالی که کتاب‌keeping روی
 * (key, endpoint, wp_user_id, context_id) انجام می‌شود؛ به‌علاوه `<=> %d` در
 * prepare مقدار NULL را به 0 تبدیل می‌کرد و SELECT/UPDATE هرگز ردیف‌های
 * context_id=NULL را پیدا نمی‌کرد (بازپخش پاسخ ذخیره‌شده عملاً خاموش بود و
 * بنر Duplicate در لاگ‌ها می‌آمد).
 *
 * اصلاح: دامنه یکتایی = همان چهار ستونِ کوئری‌ها + نرمال‌سازی NULL→0
 * (ستون‌ها NOT NULL DEFAULT 0) تا سوراخ NULL در UNIQUE باقی نماند.
 *
 * ایمنی (قاعده کارفرما):
 *  - Preflight: قبل از هر تغییری، تکراری‌های «پس از نرمال‌سازی» شناسایی و در
 *    صورت وجود Migration با خطا متوقف می‌شود — **بدون حذف/merge خودکار**.
 *  - همه مراحل Idempotent (SHOW INDEX/COLUMNS) → اجرای مجدد پس از شکست امن است.
 *  - down(): بازگردانی شکل قبلی (best-effort؛ نیازمند داده بدون تکرار key).
 */

return [
    'version' => '2026_09_07_0006',
    'description' => 'Idempotency scope: UNIQUE(key,endpoint,user,context) + NULL->0 normalization (F9)',
    'up' => function (CpmsDb $db): void {
        $t = $db->table('cpms_idempotency_keys');

        // ---------- 1) Preflight (بدون تغییر داده) ----------
        // تکراری‌ها پس از نرمال‌سازی NULL→0 — اگر وجود داشت: توقف با خطا.
        $dups = $db->fetchAll(
            'SELECT `key`, endpoint, IFNULL(wp_user_id, 0) AS u, IFNULL(context_id, 0) AS c, COUNT(*) AS n' .
            ' FROM ' . $t .
            ' GROUP BY `key`, endpoint, IFNULL(wp_user_id, 0), IFNULL(context_id, 0)' .
            ' HAVING n > 1 LIMIT 5'
        );
        if (is_array($dups) && $dups !== []) {
            $detail = implode('; ', array_map(
                static fn (array $r): string => $r['key'] . '@' . $r['endpoint'] . ' u=' . $r['u'] . ' c=' . $r['c'] . ' x' . $r['n'],
                $dups
            ));
            throw new RuntimeException(
                'Migration 0006 aborted: duplicate idempotency rows in target scope [' . $detail . '] — ' .
                'resolve manually (keep the newest DONE row per scope), then re-run. No data was changed.'
            );
        }

        // ---------- 2) نرمال‌سازی NULL → 0 ----------
        $db->query('UPDATE ' . $t . ' SET wp_user_id = 0 WHERE wp_user_id IS NULL');
        $db->query('UPDATE ' . $t . ' SET context_id = 0 WHERE context_id IS NULL');

        // ---------- 3) ستون‌ها NOT NULL DEFAULT 0 ----------
        $userCol = $db->fetchRow('SHOW COLUMNS FROM ' . $t . " LIKE 'wp_user_id'");
        if ($userCol !== null && strtoupper((string) $userCol['Null']) === 'YES') {
            $db->query('ALTER TABLE ' . $t . ' MODIFY `wp_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
        $ctxCol = $db->fetchRow('SHOW COLUMNS FROM ' . $t . " LIKE 'context_id'");
        if ($ctxCol !== null && strtoupper((string) $ctxCol['Null']) === 'YES') {
            $db->query('ALTER TABLE ' . $t . ' MODIFY `context_id` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }

        // ---------- 4) جابه‌جایی ایندکس یونیک به دامنه واقعی ----------
        $hasOld = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_idem_key'");
        if ($hasOld !== null) {
            $db->query('ALTER TABLE ' . $t . ' DROP INDEX `u_idem_key`');
        }
        $hasNew = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_idem_scope'");
        if ($hasNew === null) {
            $db->query(
                'ALTER TABLE ' . $t .
                ' ADD UNIQUE KEY `u_idem_scope` (`key`, `endpoint`, `wp_user_id`, `context_id`)'
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $t = $db->table('cpms_idempotency_keys');

        $hasNew = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_idem_scope'");
        if ($hasNew !== null) {
            $db->query('ALTER TABLE ' . $t . ' DROP INDEX `u_idem_scope`');
        }
        $hasOld = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_idem_key'");
        if ($hasOld === null) {
            $db->query('ALTER TABLE ' . $t . ' ADD UNIQUE KEY `u_idem_key` (`key`)');
        }

        // بازگردانی NULL semantics (۰ = «بدون زمینه» → NULL) + Nullable
        $db->query('UPDATE ' . $t . ' SET wp_user_id = NULL WHERE wp_user_id = 0');
        $db->query('UPDATE ' . $t . ' SET context_id = NULL WHERE context_id = 0');
        $userCol = $db->fetchRow('SHOW COLUMNS FROM ' . $t . " LIKE 'wp_user_id'");
        if ($userCol !== null && strtoupper((string) $userCol['Null']) === 'NO') {
            $db->query('ALTER TABLE ' . $t . ' MODIFY `wp_user_id` BIGINT UNSIGNED NULL');
        }
        $ctxCol = $db->fetchRow('SHOW COLUMNS FROM ' . $t . " LIKE 'context_id'");
        if ($ctxCol !== null && strtoupper((string) $ctxCol['Null']) === 'NO') {
            $db->query('ALTER TABLE ' . $t . ' MODIFY `context_id` BIGINT UNSIGNED NULL');
        }
    },
];

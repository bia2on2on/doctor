<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Migration 0020 — C6 (bug 2 census، ADR-0031/AD-13):
 *
 * دامنه یکتایی Idempotency چهارستونه بود (u_idem_scope از 0006:
 * key, endpoint, wp_user_id, context_id) در حالی که clinic_id در ردیف
 * ذخیره می‌شد اما بخشی از دامنه نبود — کلید یکسان در دو Clinic برخورد
 * می‌کرد و پاسخِ ذخیره‌شدهٔ Clinic دیگر Replay می‌شد (leak cross-tenant).
 *
 * اصلاح: UNIQUE پنج‌ستونه — (key, endpoint, wp_user_id, context_id, clinic_id).
 *
 * ایمنی (قاعدهٔ کارفرما):
 *  - Preflight: تکراری‌های «پس از دامنهٔ جدید» شناسایی و در صورت وجود
 *    Migration با خطا متوقف می‌شود — بدون حذف/merge خودکار.
 *  - همه مراحل Idempotent (SHOW INDEX) → اجرای مجدد پس از شکست امن است.
 *  - down(): بازگردانی UNIQUE چهارستونه (فقط در نبود تکراری پس از ادغام
 *    دامنه — best-effort).
 */
return [
    'version' => '2026_09_09_0020',
    'description' => 'C6: idempotency UNIQUE gains clinic_id — same key in two clinics must not collide/replay',
    'up' => function (CpmsDb $db): void {
        $t = $db->table('cpms_idempotency_keys');

        // ---------- 1) Preflight (بدون تغییر داده) ----------
        // تکراری در دامنهٔ جدید (هر row یکتا در دامنهٔ قدیم است چون UNIQUE
        // فعلی برقرار است؛ بنابراین تکراریِ جدید فقط اگر clinic_id تکراری
        // داشته باشد ممکن است — ستون NOT NULL است ولی محکم‌کاری می‌کنیم).
        $nullClinic = $db->fetchValue('SELECT COUNT(*) FROM ' . $t . ' WHERE clinic_id IS NULL OR clinic_id = 0');
        if ($nullClinic !== null && (int) $nullClinic > 0) {
            // ردیف‌های بدون Clinic (پیش از 0016) — همهٔ نصب‌ها تا امروز
            // تک‌کلینیکی‌اند؛ normalize به 1 فقط با تأییدِ نبود Clinic دیگر.
            $clinicCount = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics'));
            if ($clinicCount > 1) {
                throw new RuntimeException(
                    'Migration 0020 aborted: idempotency rows without clinic_id exist in a multi-clinic install — ' .
                    'resolve manually, then re-run. No data was changed.'
                );
            }
            $db->query('UPDATE ' . $t . ' SET clinic_id = 1 WHERE clinic_id IS NULL OR clinic_id = 0');
        }

        $dups = $db->fetchAll(
            'SELECT `key`, endpoint, wp_user_id, context_id, clinic_id, COUNT(*) AS n' .
            ' FROM ' . $t .
            ' GROUP BY `key`, endpoint, wp_user_id, context_id, clinic_id' .
            ' HAVING n > 1 LIMIT 5'
        );
        if (is_array($dups) && $dups !== []) {
            $detail = implode('; ', array_map(
                static fn (array $r): string => $r['key'] . '@' . $r['endpoint'] . ' u=' . $r['wp_user_id'] .
                    ' c=' . $r['context_id'] . ' cl=' . $r['clinic_id'] . ' x' . $r['n'],
                $dups
            ));
            throw new RuntimeException(
                'Migration 0020 aborted: duplicate idempotency rows in target scope [' . $detail . '] — ' .
                'resolve manually, then re-run. No data was changed.'
            );
        }

        // ---------- 2) UNIQUE پنج‌ستونه ----------
        $idx = $db->fetchAll('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_idem_scope'");
        $cols = [];
        if (is_array($idx)) {
            foreach ($idx as $row) {
                $cols[] = (string) $row['Column_name'];
            }
        }
        $target = ['key', 'endpoint', 'wp_user_id', 'context_id', 'clinic_id'];
        if ($cols !== $target) {
            if ($cols !== []) {
                $db->query('ALTER TABLE ' . $t . ' DROP INDEX `u_idem_scope`');
            }
            $db->query(
                'ALTER TABLE ' . $t .
                ' ADD UNIQUE KEY `u_idem_scope` (`key`, `endpoint`, `wp_user_id`, `context_id`, `clinic_id`)'
            );
        }
    },
    'down' => function (CpmsDb $db): void {
        $t = $db->table('cpms_idempotency_keys');

        // دامنهٔ کوچک‌تر فقط در نبود تکراری جدید معتبر است.
        $dups = $db->fetchAll(
            'SELECT `key`, endpoint, wp_user_id, context_id, COUNT(*) AS n' .
            ' FROM ' . $t .
            ' GROUP BY `key`, endpoint, wp_user_id, context_id' .
            ' HAVING n > 1 LIMIT 5'
        );
        if (is_array($dups) && $dups !== []) {
            throw new RuntimeException(
                'Migration 0020 down() aborted: collapsing scope would create duplicates — resolve manually.'
            );
        }

        $idx = $db->fetchRow('SHOW INDEX FROM ' . $t . " WHERE Key_name = 'u_idem_scope' AND Column_name = 'clinic_id'");
        if ($idx !== null) {
            $db->query('ALTER TABLE ' . $t . ' DROP INDEX `u_idem_scope`');
            $db->query(
                'ALTER TABLE ' . $t .
                ' ADD UNIQUE KEY `u_idem_scope` (`key`, `endpoint`, `wp_user_id`, `context_id`)'
            );
        }
    },
];

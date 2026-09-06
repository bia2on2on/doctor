<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * مخزن وضعیت لایسنس (ADR-0023 §2) — فقط Data-Access.
 *
 * دو موجودیت:
 *  - install: شناسه‌ی نصب پرآنتروپی (یکتا؛ هرگز فقط دامنه)
 *  - state  : یک ردیفِ تکی (id=1) شامل سندِ امضاشده‌ی مجوزِ آخر + ابرداده‌ی
 *             refresh. هیچ PHI در این جدول ذخیره نمی‌شود (ADR-0028).
 */
final class LicenseRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * شناسه‌ی نصب — می‌سازد اگر نبود (Idempotent). آنتروپی بالا (32 hex).
     *
     * پنجرهٔ فعال‌سازی (تصمیم کارفرما):
     *  - ردیفِ id=1 معمولاً در Migration 0008 با activation_window_started_at
     *    و نوع (fresh|migration) ساخته می‌شود؛ این متد فقط در غیاب ردیف
     *    (مثلاً تست بدون Migration) یک ردیفِ پیش‌فرضِ fresh با start=now می‌سازد.
     *  - هرگز start/type موجود را بازنویسی نمی‌کند (anti-reset: deactivate/
     *    reactivate/reinstall پنجره را از نو شروع نمی‌کند).
     */
    public function installId(): string
    {
        $row = $this->db->fetchRow(
            'SELECT id, install_id FROM ' . $this->db->table('cpms_license_install') . ' ORDER BY id ASC LIMIT 1'
        );
        if ($row !== null && (string) $row['install_id'] !== '') {
            return (string) $row['install_id'];
        }

        $now = $this->db->nowUtcSql();
        $id = bin2hex(random_bytes(16));
        $this->db->query(
            'INSERT INTO ' . $this->db->table('cpms_license_install') .
            ' (install_id, environment, activation_window_started_at, activation_window_type, created_at, updated_at)' .
            ' VALUES (%s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            [$id, $this->environment(), $now, 'fresh', $now, $now]
        );

        return $id;
    }

    /**
     * ردیفِ پنجرهٔ فعال‌سازی (id=1) — شروع + نوع (fresh|migration).
     *
     * @return array<string, mixed>|null
     */
    public function activationWindow(): ?array
    {
        return $this->db->fetchRow(
            'SELECT install_id, activation_window_started_at, activation_window_type, created_at
             FROM ' . $this->db->table('cpms_license_install') . ' ORDER BY id ASC LIMIT 1'
        );
    }

    /**
     * به‌روزرسانی زمانِ شروع پنجره — فقط برای تست/ابزار تشخیص؛ در مسیرهای
     * عادی هرگز صدا زده نمی‌شود (anti-reset در UI/Deactivate/Reinstall).
     */
    public function setActivationWindowStart(string $utcSql): void
    {
        $this->db->update(
            'cpms_license_install',
            ['activation_window_started_at' => $utcSql, 'updated_at' => $this->db->nowUtcSql()],
            ['id' => 1]
        );
    }

    /**
     * @return array<string, mixed>|null ردیف state (بدون کلیدهای حساس)
     */
    public function state(): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_license_state') . ' ORDER BY id ASC LIMIT 1'
        );
    }

    /**
     * ذخیره/به‌روزرسانی سند معتبر (پس از تأیید امضا در سرویس).
     *
     * @param array<string, mixed> $payload
     */
    public function saveVerified(array $payload, string $signatureB64, string $licenseId): void
    {
        $now = $this->db->nowUtcSql();
        $row = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table('cpms_license_state') . ' ORDER BY id ASC LIMIT 1'
        );
        $data = [
            'license_id' => $licenseId,
            'install_id' => $this->installId(),
            'payload_json' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'signature_b64' => $signatureB64,
            'verified_at' => $now,
            'last_refresh_attempt_at' => $now,
            'last_refresh_error' => null,
            'refresh_fail_count' => 0,
            'updated_at' => $now,
        ];
        if ($row === null) {
            $data['id'] = 1;
            $this->db->insert('cpms_license_state', $data);
        } else {
            $this->db->update('cpms_license_state', $data, ['id' => (int) $row['id']]);
        }
    }

    /**
     * ثبت تلاش ناموفق refresh (خطای شبکه/HTTP — نه امضا).
     */
    public function recordRefreshFailure(string $error): void
    {
        $now = $this->db->nowUtcSql();
        $row = $this->db->fetchRow(
            'SELECT id, refresh_fail_count FROM ' . $this->db->table('cpms_license_state') . ' ORDER BY id ASC LIMIT 1'
        );
        if ($row === null) {
            return;
        }
        $fails = min(65535, (int) $row['refresh_fail_count'] + 1);
        $this->db->update(
            'cpms_license_state',
            [
                'last_refresh_attempt_at' => $now,
                'last_refresh_error' => mb_substr($error, 0, 250),
                'refresh_fail_count' => $fails,
                'updated_at' => $now,
            ],
            ['id' => (int) $row['id']]
        );
    }

    public function recordUnreachableNoState(): void
    {
        // بدون state (فعال‌سازی نشده) — فقط attempt را ثبت می‌کنیم (ردیف نمی‌سازیم)
    }

    private function environment(): string
    {
        return defined('WP_ENVIRONMENT_TYPE') ? (string) WP_ENVIRONMENT_TYPE : 'production';
    }
}

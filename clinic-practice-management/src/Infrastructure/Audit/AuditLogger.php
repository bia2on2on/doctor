<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Audit;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;

/**
 * Audit Logger — Append-only + Hash Chain (ADR-0008, FR-21.x).
 *
 * - فقط INSERT؛ هیچ API برای UPDATE/DELETE وجود ندارد.
 * - قبل/بعد: فقط change-set فیلدها + Masking مقادیر حساس.
 * - ممنوع: OTP/رمز/Token — با sanitize به‌طور اجباری حذف می‌شوند.
 */
final class AuditLogger
{
    /** کلیدهایی که هرگز در Audit ذخیره نمی‌شوند. */
    private const FORBIDDEN_KEYS = ['otp', 'otp_code', 'code', 'password', 'token', 'secret', 'api_key', 'pepper'];

    private const MASK_KEYS = ['mobile', 'phone', 'national_id', 'card_number', 'email'];

    public function __construct(private readonly CpmsDb $db, private readonly OpLogger $op)
    {
    }

    /**
     * @param array{wp_user_id: int|null, role: string}|null $actor null = سیستم
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $meta
     *
     * @return int id رکورد Audit
     */
    public function log(
        string $action,
        ?array $actor,
        string $resourceType,
        ?int $resourceId,
        ?int $patientId,
        ?array $before,
        ?array $after,
        array $meta = [],
        ?int $clinicId = 1
    ): int {
        return $this->db->transactional(function () use (
            $action,
            $actor,
            $resourceType,
            $resourceId,
            $patientId,
            $before,
            $after,
            $meta,
            $clinicId
        ) {
            // آخرین hash — با Lock برای جلوگیری از شکاف زنجیر در Write هم‌زمان
            $last = $this->db->fetchRowForUpdate(
                'SELECT row_hash FROM ' . $this->db->table('cpms_audit_logs') . ' ORDER BY id DESC LIMIT 1'
            );
            $prevHash = $last['row_hash'] ?? HashChain::GENESIS;

            $now = $this->db->nowUtcSql();
            $row = [
                'clinic_id' => $clinicId,
                'actor_wp_user_id' => $actor['wp_user_id'] ?? null,
                'actor_role' => $actor['role'] ?? 'system',
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'patient_id' => $patientId,
                'ip_hash' => $this->ipHash(),
                'session_id' => $this->sessionId(),
                'request_id' => $this->requestId(),
                'before_json' => $before !== null ? json_encode($this->sanitize($before), JSON_UNESCAPED_UNICODE) : null,
                'after_json' => $after !== null ? json_encode($this->sanitize($after), JSON_UNESCAPED_UNICODE) : null,
                'meta_json' => $meta !== [] ? json_encode($this->sanitize($meta), JSON_UNESCAPED_UNICODE) : null,
                'created_at' => $now,
            ];

            $row['prev_hash'] = $prevHash;
            $row['row_hash'] = HashChain::computeRowHash($prevHash, HashChain::fieldsFor($row));

            $this->db->insert('cpms_audit_logs', $row);

            return (int) $this->db->wpdb_last_insert_id();
        });
    }

    /**
     * صحت‌سنجی زنجیر (آخرین $limit رکورد).
     *
     * @return array{ok: bool, checked: int, broken_at: int|null}
     */
    public function verifyChain(int $limit = 10_000): array
    {
        $limit = max(1, min($limit, 100_000));
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_audit_logs') . ' ORDER BY id DESC LIMIT %d',
            [$limit]
        );
        $rows = array_reverse($rows);

        $result = HashChain::verify($rows);

        if (!$result['ok']) {
            $this->op->error('AUDIT_CHAIN_BROKEN', ['broken_at' => $result['broken_at']]);
        }

        return $result;
    }

    /**
     * حذف کلیدهای ممنوع + Masking حساس‌ها.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            if (preg_match('/(' . implode('|', self::FORBIDDEN_KEYS) . ')/i', $key)) {
                continue; // حذف کامل
            }
            if (in_array($key, self::MASK_KEYS, true) && is_string($v) && strlen($v) >= 4) {
                $out[$key] = '***' . substr($v, -4);
                continue;
            }
            if (is_array($v)) {
                $out[$key] = $this->sanitize($v);
                continue;
            }
            if (is_string($v) && mb_strlen($v) > 2000) {
                $out[$key] = mb_substr($v, 0, 2000) . '…[truncated]';
                continue;
            }
            $out[$key] = $v;
        }

        return $out;
    }

    private function ipHash(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip)) {
            return null;
        }

        return hash_hmac('sha256', $ip, $this->pepper());
    }

    private function sessionId(): ?string
    {
        $id = function_exists('cpms_session_id') ? cpms_session_id() : null;

        return is_string($id) ? substr($id, 0, 64) : null;
    }

    private function requestId(): ?string
    {
        $id = function_exists('cpms_request_id') ? cpms_request_id() : null;

        return is_string($id) ? substr($id, 0, 64) : null;
    }

    private function pepper(): string
    {
        $pepper = defined('CPMS_PEPPER') ? CPMS_PEPPER : '';

        return $pepper !== '' ? $pepper : 'cpms-dev-pepper-change-me';
    }
}

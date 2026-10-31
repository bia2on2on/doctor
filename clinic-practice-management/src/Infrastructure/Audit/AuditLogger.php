<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName,WordPress.NamingConventions.ValidFunctionName,PEAR.Functions.FunctionCallSignature,WordPress.WhiteSpace.ControlStructureSpacing,Generic.WhiteSpace,Generic.Formatting.MultipleStatementAlignment,Generic.Functions.OpeningFunctionBraceKernighanRitchie -- audit chain uses camelCase prevHash/rowHash and legacy spacing; security, SQL, audit, and hash correctness sniffs remain via per-line checks.

namespace ClinicCore\Infrastructure\Audit;

use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
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
     * @throws \RuntimeException اگر INSERT رویداد ثبت نشود (fail-closed؛ هیچ
     *                           رویداد Audit بی‌ثبت و هیچ «موفقیتِ کاذب»)
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
        ?int $clinicId = null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public audit API parameter name
        bool $suppress_scope_fallback = false
    ): int {
        // C6 (AD-13): کلینیک صریحِ event یا Scope فعالِ درخواست؛ اگر Scope
        // مبهم/ناموجود بود (event واقعاً سیستمی) NULL ثبت می‌شود — ستون از
        // 0016 nullable است؛ NULL = سیستمی، نه «1».
        if ( $clinicId === null && ! $suppress_scope_fallback ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing public audit API parameter name
            try {
                $clinicId = App::scope()->clinicId;
            } catch (ScopeRequiredException) {
                $clinicId = null;
            }
        }
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
            // Serialization guard: singleton InnoDB row locked inside the SAME outer
            // transaction before reading the tail. This is the fixed serialization
            // point — without it, two concurrent writers can read the same
            // committed tail and both link to it (unexpected genesis prev_hash).
            // See migration 0031. Fail-closed if the guard row is missing.
            $guard = $this->db->fetchRowForUpdate(
                'SELECT id FROM ' . $this->db->table( 'cpms_audit_seq' ) . ' WHERE id = 1'
            );
            if ($guard === null) {
                throw new \RuntimeException('audit guard row missing — run migrations');
            }

            // Current read of the durable tail (FOR UPDATE reads latest committed).
            $last = $this->db->fetchRowForUpdate(
                'SELECT row_hash FROM ' . $this->db->table( 'cpms_audit_logs' ) . ' ORDER BY id DESC LIMIT 1'
            );
            $prev_hash = ( isset( $last['row_hash'] ) && is_string( $last['row_hash'] ) && '' !== $last['row_hash'] ) ? (string) $last['row_hash'] : HashChain::GENESIS;

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

            $row['prev_hash'] = $prev_hash;
            $row['row_hash'] = HashChain::computeRowHash( $prev_hash, HashChain::fieldsFor($row));

            // F2-1: یک رویداد Audit که ذخیره نشده باشد هرگز نباید «موفق» به
            // نظر برسد. wpdb در حالت non-strict فقط false برمی‌گرداند؛ قبلاً
            // این نتیجه نادیده گرفته می‌شد و فراخوان نتیجهٔ 0 (بی‌معنا) را
            // موفق تلقی می‌کرد. اکنون شکست ذخیره‌سازی Exception می‌دهد تا
            // تراکنشِ دربرگیرنده (مرزِ mutation + audit) fail-closed عقب
            // برود. پیام فقط نام action را دارد — هیچ دادهٔ درخواست/PHI.
            $persisted = $this->db->insert( 'cpms_audit_logs', $row );
            $audit_id  = (int) $this->db->wpdb_last_insert_id();
            if ( ! $persisted || $audit_id <= 0 ) {
                throw new \RuntimeException( 'audit event was not persisted: ' . $action );
            }

            // Bookkeeping for diagnostics — part of the same transaction, so a
            // rolled-back audit never advances the guard.
            $this->db->query(
                'UPDATE ' . $this->db->table( 'cpms_audit_seq' ) . ' SET last_audit_id = %d, updated_at = %s WHERE id = 1',
                [$audit_id, $now]
            );

            return $audit_id;
        });
    }

    /**
     * صحت‌سنجی زنجیر (آخرین $limit رکورد).
     *
     * برای پسوند (suffix) لنگرِ واقعیِ پیش از نخستین ردیف خوانده می‌شود تا اعتبارسنجی
     * محدودشده نتیجهٔ کاذبِ “شکسته” ندهد. زنجیر کامل با GENESIS بسته می‌شود.
     *
     * @return array{ok: bool, checked: int, broken_at: int|null, broken_id: int|null}
     */
    public function verify_chain( int $limit = 10_000 ): array {

        $limit = max(1, min($limit, 100_000));
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table( 'cpms_audit_logs' ) . ' ORDER BY id DESC LIMIT %d',
            [$limit]
        );
        $rows = array_reverse($rows);

        if ($rows === []) {
            return ['ok' => true, 'checked' => 0, 'broken_at' => null, 'broken_id' => null];
        }

        // Anchor for suffix verification: the durable predecessor's row_hash or GENESIS.
        $first_id = (int) ($rows[0]['id'] ?? 0);
        $anchor = HashChain::GENESIS;
        if ($first_id > 0) {
            $predecessor = $this->db->fetchRow(
                'SELECT row_hash FROM ' . $this->db->table( 'cpms_audit_logs' ) . ' WHERE id < %d ORDER BY id DESC LIMIT 1',
                [$first_id]
            );
            if ($predecessor !== null && isset($predecessor['row_hash']) && is_string($predecessor['row_hash']) && $predecessor['row_hash'] !== '') {
                $anchor = (string) $predecessor['row_hash'];
            } elseif ($first_id !== 1) {
                // Non-genesis first row but no predecessor found — could be a gap or
                // a limit that trimmed the genesis; treat as full-chain from GENESIS
                // so an unexpected genesis is reported as broken (matches installer report).
                $anchor = HashChain::GENESIS;
            }
        }

        $result = HashChain::verify($rows, $anchor);

        if ( ! $result['ok'] ) {
            $this->op->error('AUDIT_CHAIN_BROKEN', ['broken_at' => $result['broken_at'], 'broken_id' => $result['broken_id'] ?? null]);
        }

        return $result;
    }

    // BC alias — new code uses verify_chain().
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- BC.
    public function verifyChain( int $limit = 10_000 ): array {
        return $this->verify_chain( $limit );
    }

    /**
     * صحت‌سنجی کامل (بدون محدودیت سقف).
     *
     * @return array{ok: bool, checked: int, broken_at: int|null, broken_id: int|null}
     */
    public function verify_full_chain(): array {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table( 'cpms_audit_logs' ) . ' ORDER BY id ASC'
        );

        // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- BC HashChain alias.
        $result = HashChain::verify_full_chain( $rows );

        if ( ! $result['ok'] ) {
            $this->op->error('AUDIT_CHAIN_BROKEN', ['broken_at' => $result['broken_at'], 'broken_id' => $result['broken_id'] ?? null]);
        }

        return $result;
    }

    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- BC.
    public function verifyFullChain(): array {
        return $this->verify_full_chain();
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
            if (in_array(mb_strtolower($key), self::FORBIDDEN_KEYS, true)) {
                continue; // حذف کامل — تطبیق دقیق نام کلید (case-insensitive) — F1-8
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

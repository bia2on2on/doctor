<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Security;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Idempotency (NFR/ER-05, ER-19, T-18):
 *
 * کلاینت: Header `Idempotency-Key: <uuid>`.
 * سرور:   (invoice_id|context, key) → یا پاسخ ذخیره‌شده (Replay) یا Claim جدید.
 *
 * چرخه: check() → [عمل] → complete() | fail() (fail = آزادسازی کلید برای تلاش مجدد).
 */
final class Idempotency
{
    public const STATUS_PENDING = 0;
    public const STATUS_DONE = 1;

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @return array{is_replay: bool, response: array<string,mixed>|null, response_code: int|null}
     */
    public function check(string $key, string $endpoint, ?int $userId, ?int $contextId = null, ?int $clinicId = 1): array
    {
        $existing = $this->db->fetchRow(
            'SELECT response_code, response_json, status FROM ' . $this->db->table('cpms_idempotency_keys') .
            ' WHERE key = %s AND endpoint = %s AND (wp_user_id <=> %d) AND (context_id <=> %d) LIMIT 1',
            [$key, $endpoint, $userId, $contextId]
        );

        if ($existing === null) {
            $this->db->insert('cpms_idempotency_keys', [
                'key' => $key,
                'clinic_id' => $clinicId,
                'wp_user_id' => $userId,
                'endpoint' => $endpoint,
                'context_id' => $contextId,
                'status' => self::STATUS_PENDING,
                'response_code' => null,
                'response_json' => null,
                'created_at' => $this->db->nowUtcSql(),
            ]);

            return ['is_replay' => false, 'response' => null, 'response_code' => null];
        }

        if ((int) $existing['status'] === self::STATUS_DONE && $existing['response_json'] !== null) {
            $decoded = json_decode((string) $existing['response_json'], true);

            return [
                'is_replay' => true,
                'response' => is_array($decoded) ? $decoded : null,
                'response_code' => $existing['response_code'] !== null ? (int) $existing['response_code'] : null,
            ];
        }

        // در حال پردازش (Request موازی) → 409
        return ['is_replay' => true, 'response' => ['error' => 'CLINIC_DUPLICATE_IN_FLIGHT'], 'response_code' => 409];
    }

    /**
     * @param array<string, mixed> $response
     */
    public function complete(string $key, string $endpoint, ?int $userId, ?int $contextId, int $responseCode, array $response): void
    {
        $this->db->query(
            'UPDATE ' . $this->db->table('cpms_idempotency_keys') .
            ' SET status = %d, response_code = %d, response_json = %s
             WHERE key = %s AND endpoint = %s AND (wp_user_id <=> %d) AND (context_id <=> %d)',
            [
                self::STATUS_DONE,
                $responseCode,
                json_encode($response, JSON_UNESCAPED_UNICODE) ?: 'null',
                $key,
                $endpoint,
                $userId,
                $contextId,
            ]
        );
    }

    /**
     * اگر عمل شکست: کلید آزاد می‌شود تا کلاینت بتواند دوباره تلاش کند.
     */
    public function release(string $key, string $endpoint, ?int $userId, ?int $contextId): void
    {
        $this->db->query(
            'DELETE FROM ' . $this->db->table('cpms_idempotency_keys') .
            ' WHERE key = %s AND endpoint = %s AND (wp_user_id <=> %d) AND (context_id <=> %d) AND status = %d',
            [$key, $endpoint, $userId, $contextId, self::STATUS_PENDING]
        );
    }

    /**
     * پاک‌سازی کلیدهای قدیمی (Job).
     */
    public function cleanup(int $olderThanDays = 90): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $olderThanDays * 86400) . '.000';

        return $this->db->query(
            'DELETE FROM ' . $this->db->table('cpms_idempotency_keys') . ' WHERE created_at < %s',
            [$cutoff]
        );
    }
}

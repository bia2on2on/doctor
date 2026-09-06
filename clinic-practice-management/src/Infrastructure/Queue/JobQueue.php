<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Queue;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;

/**
 * Job Queue — DB-based (V1) با Interface قابل تعویض (ADR roadmap, docs/architecture/background-jobs.md).
 *
 * چرخه: queued → processing (claim با Lock) → success | failed
 * Retry: fail() → اگر attempts < max → queued با run_after = now + backoff
 * Idempotency: هر Handler باید تکرارپذیر باشد (J-2).
 */
final class JobQueue
{
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    public function __construct(private readonly CpmsDb $db, private readonly OpLogger $op)
    {
    }

    public function enqueue(
        string $type,
        array $payload = [],
        ?\DateTimeImmutable $runAfter = null,
        int $priority = 5,
        int $maxAttempts = 3 // تصمیم کارفرما F1-D3
    ): int {
        $this->db->insert('cpms_jobs', [
            'type' => $type,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null,
            'status' => self::QUEUED,
            'priority' => $priority,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'run_after' => ($runAfter ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.000'),
            'created_at' => $this->db->nowUtcSql(),
        ]);

        return (int) $this->db->wpdb_last_insert_id();
    }

    /**
     * یک Job سررسید را Claim می‌کند (Row Lock + Update شرطی → بدون Double-Claim).
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $workerId, int $lockSec = 120): ?array
    {
        return $this->db->transactional(function () use ($workerId, $lockSec) {
            $row = $this->db->fetchRowForUpdate(
                'SELECT * FROM ' . $this->db->table('cpms_jobs') .
                ' WHERE status = %s AND run_after <= %s
                 ORDER BY priority DESC, id ASC LIMIT 1',
                [self::QUEUED, $this->db->nowUtcSql()]
            );
            if ($row === null) {
                return null;
            }

            $lockExpiry = gmdate('Y-m-d H:i:s', time() + $lockSec) . '.000';
            $updated = $this->db->query(
                'UPDATE ' . $this->db->table('cpms_jobs') .
                ' SET status = %s, locked_by = %s, lock_expires_at = %s, started_at = %s, attempts = attempts + 1
                 WHERE id = %d AND status = %s',
                [self::PROCESSING, $workerId, $lockExpiry, $this->db->nowUtcSql(), (int) $row['id'], self::QUEUED]
            );

            return $updated ? $row : null;
        });
    }

    public function complete(int $jobId): void
    {
        $this->db->query(
            'UPDATE ' . $this->db->table('cpms_jobs') .
            ' SET status = %s, completed_at = %s, locked_by = NULL, lock_expires_at = NULL
             WHERE id = %d AND status = %s',
            [self::SUCCESS, $this->db->nowUtcSql(), $jobId, self::PROCESSING]
        );
    }

    /**
     * شکست + Retry با Backoff (اگر آزمون باقی مانده باشد).
     */
    public function fail(int $jobId, string $error): void
    {
        $job = $this->db->fetchRow(
            'SELECT attempts, max_attempts, type FROM ' . $this->db->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
        if ($job === null) {
            return;
        }

        $attempts = (int) $job['attempts'];
        $max = (int) $job['max_attempts'];
        if ($attempts < $max) {
            $delay = self::backoffSeconds($attempts);
            $nextRun = gmdate('Y-m-d H:i:s', time() + $delay) . '.000';
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_jobs') .
                ' SET status = %s, last_error = %s, run_after = %s, locked_by = NULL, lock_expires_at = NULL
                 WHERE id = %d',
                [self::QUEUED, mb_substr($error, 0, 250), $nextRun, $jobId]
            );
            $this->op->warning('JOB_RETRY', ['job_id' => $jobId, 'type' => $job['type'], 'attempt' => $attempts]);
        } else {
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_jobs') .
                ' SET status = %s, last_error = %s, locked_by = NULL, lock_expires_at = NULL, completed_at = %s
                 WHERE id = %d',
                [self::FAILED, mb_substr($error, 0, 250), $this->db->nowUtcSql(), $jobId]
            );
            $this->op->error('JOB_FAILED_FINAL', ['job_id' => $jobId, 'type' => $job['type'], 'error' => $error]);
        }
    }

    /**
     * آزادسازی Jobهای لاک‌شده‌ی منقضی (Worker مرده) — Job خودش Idempotent است.
     */
    public function releaseStaleLocks(): int
    {
        return $this->db->execute(
            'UPDATE ' . $this->db->table('cpms_jobs') .
            ' SET status = %s, locked_by = NULL, lock_expires_at = NULL
             WHERE status = %s AND lock_expires_at < %s',
            [self::QUEUED, self::PROCESSING, $this->db->nowUtcSql()]
        );
    }

    /**
     * Backoff ثانی بر اساس آزمون (1, 5, 15, 60, 300 — پس از آن ثابت 300).
     */
    public static function backoffSeconds(int $attempt): int
    {
        $table = [1 => 1, 2 => 5, 3 => 15, 4 => 60, 5 => 300];

        return $table[max(1, min($attempt, 5))];
    }
}

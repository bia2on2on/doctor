<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;

/**
 * Dispatcher Jobها — V1: Single Worker (Row Lock؛ J-3).
 * هر Handler باید Idempotent باشد (J-2) — تکرار اجرا = یک اثر (TP-13).
 */
final class JobsDispatcher
{
    /** @var array<string, callable(array<string,mixed>): void> */
    private array $handlers = [];

    public function __construct(
        private readonly JobQueue $queue,
        private readonly OpLogger $op
    ) {
    }

    public function register(string $type, callable $handler): self
    {
        $this->handlers[$type] = $handler;

        return $this;
    }

    /**
     * اجرای Jobهای سررسید (از Cron OS هر دقیقه).
     *
     * @return int تعداد Jobهای پردازش‌شده
     */
    public function tick(int $limit = 20, ?string $workerId = null): int
    {
        $workerId = $workerId ?? 'wp-' . gethostname() . '-' . getmypid();
        $this->queue->releaseStaleLocks();

        $processed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->queue->claim($workerId);
            if ($job === null) {
                break;
            }

            $type = (string) $job['type'];
            $handler = $this->handlers[$type] ?? null;
            try {
                if ($handler === null) {
                    throw new \RuntimeException('NO_HANDLER:' . $type);
                }
                $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [];
                $handler($payload);
                $this->queue->complete((int) $job['id']);
                $processed++;
            } catch (\Throwable $e) {
                $this->queue->fail((int) $job['id'], $e->getMessage());
            }
        }

        return $processed;
    }
}

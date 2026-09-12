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

    /**
     * ثبت Handler یک نوع.
     *
     * Phase 2 (RT-12 / A-1.10): هر نوع **باید** طبقهٔ scope ثبت‌شده داشته باشد؛
     * نوعِ بدونِ طبقه در همین‌جا reject می‌شود (نه حدس در زمانِ اجرا). این کار
     * `JobScopeRegistry` را به منبعِ حقیقتِ مصرف‌شده توسط خودِ dispatcher تبدیل
     * می‌کند، پس طبقه‌بندی و handlerها نمی‌توانند از هم drift کنند.
     *
     * @throws JobScopeUnknownException اگر نوع در `JobScopeRegistry` نباشد
     */
    public function register(string $type, callable $handler): self
    {
        JobScopeRegistry::classFor($type);
        $this->handlers[$type] = $handler;

        return $this;
    }

    /**
     * انواعِ ثبت‌شده در زمانِ اجرا — accessor عمومی و فقط‌خواندنی.
     *
     * بدون این متد، تنها راهِ خواندنِ registry خصوصیِ handlerها Reflection بود؛
     * تست‌های RT-12 اکنون مستقیماً از همین قراردادِ عمومی می‌خوانند.
     *
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        $types = array_map('strval', array_keys($this->handlers));
        sort($types);

        return $types;
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
                $this->queue->fail((int) $job['id'], $e->getMessage(), $workerId);
            }
        }

        return $processed;
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use DateTimeImmutable;
use DateTimeZone;

/**
 * No-show خودکار (FR-5.5 — Job: visits.no_show — هر دقیقه).
 *
 * T2 starvation fix: از payload در cpms_jobs برای cursor پایدار استفاده می‌کند.
 * - [] = root معتبر (legacy)
 * - {cursor:{slot_date,slot_time,id}, continuation:true, depth:int} = continuation معتبر
 * - malformed => throw JobPayloadInvalidException با کد JOB_PAYLOAD_INVALID (fail-closed، نه silent root)
 * - cursor باید strictly greater باشد
 * - حداکثر یک continuation از نوع visits.no_show در هر اجرا enqueue می‌شود
 * - depth برای observability/diagnostics است، نه سقف گرسنگی مصنوعی — پیشرفت از طریق cursor اثبات می‌شود
 * - idempotent: sweep داخل Row Lock دوباره وضعیت را چک می‌کند
 */
final class VisitsNoShowHandler
{
    private const MAX_PAYLOAD_SIZE = 1024; // bytes

    public function __construct(
        private readonly VisitService $visits,
        private readonly ?JobQueue $queue = null,
        private readonly ?CpmsDb $db = null,
        private readonly ?OpLogger $op = null
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return int تعداد no_show شده در این تیک
     * @throws JobPayloadInvalidException وقتی payload ادامه نامعتبر است
     */
    public function __invoke(array $payload): int
    {
        // Empty payload = valid root (legacy compatibility)
        if ($payload === []) {
            $result = $this->visits->processNoShows(null, 0);
            $this->maybeEnqueueContinuation($result, null, 0);
            return (int) ($result['processed'] ?? 0);
        }

        // Size bound check
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) > self::MAX_PAYLOAD_SIZE) {
            $this->op?->warning('visit.no_show_payload_too_large', ['size' => strlen($json)]);
            throw new JobPayloadInvalidException('JOB_PAYLOAD_INVALID', 'Payload exceeds max size', ['size' => strlen($json)]);
        }

        // Validate continuation payload structure
        $validation = $this->validateContinuationPayload($payload);
        if ($validation !== null) {
            // validation returns error array if invalid
            $this->op?->warning('visit.no_show_payload_invalid', $validation);
            throw new JobPayloadInvalidException('JOB_PAYLOAD_INVALID', $validation['message'] ?? 'Invalid continuation payload', $validation);
        }

        /** @var array{slot_date:string, slot_time:string, id:int} $cursor */
        $cursor = $payload['cursor'];
        $depth = (int) $payload['depth'];

        // Depth is observability only — must be int >=0, no arbitrary product ceiling.
        // PHP integer safety: depth must be >=0 and <= PHP_INT_MAX (always true for int >=0, but check overflow via is_int)
        if ($depth < 0) {
            $this->op?->warning('visit.no_show_depth_out_of_bounds', ['depth' => $depth]);
            throw new JobPayloadInvalidException('JOB_PAYLOAD_INVALID', 'Depth out of bounds (negative)', ['depth' => $depth]);
        }

        // Process with cursor
        $result = $this->visits->processNoShows($cursor, $depth);

        $this->maybeEnqueueContinuation($result, $cursor, $depth);

        return (int) ($result['processed'] ?? 0);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null null if valid, error info if invalid
     */
    private function validateContinuationPayload(array $payload): ?array
    {
        // Must have exactly continuation marker, cursor, depth (allow extra? we require strict)
        if (!array_key_exists('cursor', $payload)) {
            return ['message' => 'Missing cursor', 'payload' => $payload];
        }
        if (!array_key_exists('continuation', $payload)) {
            return ['message' => 'Missing continuation marker', 'payload' => $payload];
        }
        if (!array_key_exists('depth', $payload)) {
            return ['message' => 'Missing depth', 'payload' => $payload];
        }

        // continuation must be true bool
        if ($payload['continuation'] !== true) {
            return ['message' => 'Continuation must be true bool', 'continuation' => $payload['continuation']];
        }

        // depth must be int >=0 — no arbitrary product ceiling, only PHP integer safety
        if (!is_int($payload['depth'])) {
            return ['message' => 'Depth must be int', 'depth' => $payload['depth']];
        }
        if ($payload['depth'] < 0) {
            return ['message' => 'Depth out of range (negative)', 'depth' => $payload['depth']];
        }
        // Upper bound is PHP_INT_MAX implicitly — no arbitrary 100/1000 ceiling to avoid starvation

        // cursor validation
        $cursor = $payload['cursor'];
        if (!is_array($cursor)) {
            return ['message' => 'Cursor must be array', 'cursor' => $cursor];
        }
        if (!isset($cursor['slot_date'], $cursor['slot_time'], $cursor['id'])) {
            return ['message' => 'Cursor missing required fields', 'cursor' => $cursor];
        }

        // slot_date Y-m-d
        if (!is_string($cursor['slot_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cursor['slot_date'])) {
            return ['message' => 'Invalid slot_date format', 'slot_date' => $cursor['slot_date']];
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $cursor['slot_date']);
        if ($d === false || $d->format('Y-m-d') !== $cursor['slot_date']) {
            return ['message' => 'Invalid slot_date value', 'slot_date' => $cursor['slot_date']];
        }

        // slot_time H:i:s
        if (!is_string($cursor['slot_time']) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $cursor['slot_time'])) {
            return ['message' => 'Invalid slot_time format', 'slot_time' => $cursor['slot_time']];
        }
        $t = \DateTimeImmutable::createFromFormat('H:i:s', $cursor['slot_time']);
        if ($t === false || $t->format('H:i:s') !== $cursor['slot_time']) {
            return ['message' => 'Invalid slot_time value', 'slot_time' => $cursor['slot_time']];
        }

        // id positive int
        if (!is_int($cursor['id']) || $cursor['id'] <= 0) {
            return ['message' => 'Invalid cursor id', 'id' => $cursor['id']];
        }

        return null; // valid
    }

    /**
     * @param array{processed:int, next_cursor:?array, has_more:bool, depth:int, scanned:int} $result
     * @param array{slot_date:string, slot_time:string, id:int}|null $incomingCursor
     */
    private function maybeEnqueueContinuation(array $result, ?array $incomingCursor, int $incomingDepth): void
    {
        if ($this->queue === null || $this->db === null) {
            return;
        }

        $hasMore = (bool) ($result['has_more'] ?? false);
        $nextCursor = $result['next_cursor'] ?? null;

        if (!$hasMore) {
            return;
        }
        if ($nextCursor === null) {
            return;
        }

        // Safety is via provable forward progress, not arbitrary depth ceiling.
        // No MAX_DEPTH check here — chain can progress beyond old 100 limit as long as cursor advances.

        // Strictly greater check
        if ($incomingCursor !== null) {
            if (!$this->isCursorStrictlyGreater($nextCursor, $incomingCursor)) {
                $this->op?->warning('visit.no_show_cursor_not_advancing', [
                    'incoming' => $incomingCursor,
                    'next' => $nextCursor,
                ]);
                return;
            }
        }

        // Deduplication: prevent uncontrolled duplicate continuation when parent retries
        // after continuation already persisted. Under single-worker GET_LOCK (cpms_jobs_tick)
        // ticks are serialized, so SELECT-then-INSERT is safe against concurrent writers.
        // We check both QUEUED and PROCESSING to avoid duplicate while continuation is in flight.
        if ($this->isContinuationAlreadyQueued($nextCursor)) {
            $this->op?->info('visit.no_show_continuation_deduped', [
                'depth' => $incomingDepth + 1,
                'cursor' => $nextCursor,
            ]);
            return;
        }

        // Enqueue at most ONE continuation
        $nextDepth = $incomingDepth + 1;
        $payload = [
            'cursor' => $nextCursor,
            'continuation' => true,
            'depth' => $nextDepth,
        ];

        try {
            $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->queue->enqueue('visits.no_show', $payload, $nowUtc, 5, 3);
            $this->op?->info('visit.no_show_continuation_enqueued', [
                'depth' => $nextDepth,
                'cursor' => $nextCursor,
                'has_more' => $hasMore,
            ]);
        } catch (\Throwable $e) {
            $this->op?->warning('visit.no_show_continuation_enqueue_failed', [
                'error' => $e->getMessage(),
                'depth' => $nextDepth,
            ]);
        }
    }

    /**
     * Check if a continuation with the same cursor already exists as QUEUED or PROCESSING.
     * Prevents duplicate continuation when parent retries after crash/before complete.
     *
     * @param array{slot_date:string, slot_time:string, id:int} $cursor
     */
    private function isContinuationAlreadyQueued(array $cursor): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            $rows = $this->db->fetchAll(
                'SELECT payload_json FROM ' . $this->db->table('cpms_jobs') . ' WHERE type = %s AND status IN (%s, %s)',
                ['visits.no_show', JobQueue::QUEUED, JobQueue::PROCESSING]
            );
            foreach ($rows as $row) {
                $json = $row['payload_json'] ?? null;
                if (!is_string($json) || $json === '' || $json === '[]') {
                    continue;
                }
                $decoded = json_decode($json, true);
                if (!is_array($decoded) || !isset($decoded['cursor']) || !is_array($decoded['cursor'])) {
                    continue;
                }
                $c = $decoded['cursor'];
                if (!isset($c['slot_date'], $c['slot_time'], $c['id'])) {
                    continue;
                }
                // Exact cursor equality (same slot_date, slot_time, id)
                if ($c['slot_date'] === $cursor['slot_date']
                    && $c['slot_time'] === $cursor['slot_time']
                    && (int) $c['id'] === (int) $cursor['id']) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Fail-open for dedup check: if we cannot read, allow enqueue to preserve progress
            $this->op?->warning('visit.no_show_dedup_check_failed', ['error' => $e->getMessage()]);
            return false;
        }
        return false;
    }

    /**
     * @param array{slot_date:string, slot_time:string, id:int} $a
     * @param array{slot_date:string, slot_time:string, id:int} $b
     */
    private function isCursorStrictlyGreater(array $a, array $b): bool
    {
        // Compare slot_date, then slot_time, then id
        if ($a['slot_date'] !== $b['slot_date']) {
            return $a['slot_date'] > $b['slot_date'];
        }
        if ($a['slot_time'] !== $b['slot_time']) {
            return $a['slot_time'] > $b['slot_time'];
        }
        return $a['id'] > $b['id'];
    }
}

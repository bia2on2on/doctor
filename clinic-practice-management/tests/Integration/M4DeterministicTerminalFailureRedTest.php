<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * M-4 (canonical definition) — RED.
 *
 * Defect: `JobsDispatcher::tick()` sends every Throwable to the generic
 * `JobQueue::fail()`, so a DETERMINISTIC typed failure still consumes the
 * generic retry/backoff budget (queued + run_after) before max_attempts.
 *
 * Contract asserted here is the FINAL one (not a characterisation of the current
 * wrong behaviour): after the FIRST attempt of a failure that is declared
 * non-retryable by its TYPE — here `JobPayloadInvalidException` with the stable
 * code `JOB_PAYLOAD_INVALID`, thrown by the real registered `appt.reminder`
 * handler from an unchanged invalid payload — the job must be:
 *
 *   status = failed, attempts = 1, no requeue, no backoff, never claimed again.
 *
 * On the current head this fails with status = queued (generic retry path)
 * because attempts (1) < max_attempts (3). No message-substring classification
 * is involved anywhere: the payload is merely invalid, and the failure type is
 * what must decide the disposition.
 *
 * The path under test is the production one: real `App::dispatcher()` (15
 * registered types, scope-neutral wiring), real `JobQueue`, real handler payload
 * validation.
 */
final class M4DeterministicTerminalFailureRedTest extends WP_UnitTestCase
{
    /** Registered production job type whose handler validates its payload fail-closed. */
    private const JOB_TYPE = 'appt.reminder';

    /** Op-log event the handler writes immediately before the typed throw. */
    private const HANDLER_TYPED_SIGNAL = 'appt.reminder_payload_invalid';

    /** Production default retry budget (`JobQueue::enqueue()`). */
    private const RETRY_BUDGET = 3;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->purgeJobs();
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        parent::tearDown();
    }

    public function testDeterministicPayloadFailureIsTerminalOnFirstAttempt(): void
    {
        // A continuation envelope that is structurally well formed (exact key set,
        // marker, version, valid UTC reference) but whose cursor is deterministically
        // invalid. The handler must therefore fail closed with
        // JobPayloadInvalidException('JOB_PAYLOAD_INVALID') instead of silently
        // restarting the root sweep. The queue stores the payload unchanged.
        $payload = [
            'continuation' => true,
            'version' => 1,
            'reference_utc' => '2026-01-01T00:00:00Z',
            'cursor' => 'not-a-cursor',
        ];

        $signalsBefore = $this->opLogCount(self::HANDLER_TYPED_SIGNAL);

        // priority 99 > highest recurring priority (license.refresh = 9): tick(1)
        // claims exactly this job, so `attempts` stays an unambiguous signal.
        $jobId = App::jobs()->enqueue(
            self::JOB_TYPE,
            $payload,
            new DateTimeImmutable('-60 seconds', new DateTimeZone('UTC')),
            99,
            self::RETRY_BUDGET
        );
        self::assertGreaterThan(0, $jobId, 'precondition: job enqueued');

        $before = $this->jobRow($jobId);
        self::assertSame(JobQueue::QUEUED, (string) $before['status'], 'precondition: job starts queued');
        self::assertSame(0, (int) $before['attempts'], 'precondition: no attempt consumed yet');
        self::assertSame(self::RETRY_BUDGET, (int) $before['max_attempts'], 'precondition: generic retry budget intact');

        // REAL path: production dispatcher + JobQueue (WP-Cron and CLI share it).
        App::dispatcher()->tick(1);

        $after = $this->jobRow($jobId);
        $status = (string) $after['status'];
        $attempts = (int) $after['attempts'];

        // --- Execution evidence (true on the RED head as well) ---
        self::assertSame(1, $attempts, 'exactly one attempt must have been consumed (single claim, no second claim)');
        self::assertSame(
            $signalsBefore + 1,
            $this->opLogCount(self::HANDLER_TYPED_SIGNAL),
            'the intended registered handler must have executed and reached its deterministic typed failure'
        );

        // --- The final M-4 contract (RED: status is queued here) ---
        self::assertSame(
            JobQueue::FAILED,
            $status,
            'M-4: a deterministic typed failure (JOB_PAYLOAD_INVALID) must be terminal on the FIRST attempt. '
            . 'Observed: status=' . $status
            . ' attempts=' . $attempts
            . ' max_attempts=' . (int) $after['max_attempts']
            . ' run_after=' . (string) $after['run_after']
            . ' last_error=' . (string) $after['last_error']
            . ' JOB_RETRY=' . $this->opLogCount('JOB_RETRY')
            . ' JOB_FAILED_FINAL=' . $this->opLogCount('JOB_FAILED_FINAL')
        );

        // --- Terminal-state coherence (reachable only on the fixed head) ---
        self::assertSame(self::RETRY_BUDGET, (int) $after['max_attempts'], 'the persisted retry budget must not be rewritten');
        self::assertNotNull($after['completed_at'], 'a terminal job must record completed_at');
        self::assertNull($after['locked_by'], 'a terminal job must release the worker lock');
        self::assertNull($after['lock_expires_at'], 'a terminal job must release the worker lock expiry');
        self::assertSame(0, $this->opLogCount('JOB_RETRY'), 'no generic retry/backoff may be scheduled for a non-retryable failure');
        self::assertSame(1, $this->opLogCount('JOB_FAILED_FINAL'), 'the terminal transition must be logged exactly once');

        // --- No repeated deterministic churn: a terminal job is never claimed again ---
        App::dispatcher()->tick(1);

        self::assertSame(1, (int) $this->jobRow($jobId)['attempts'], 'a terminal job must not be claimed again');
        self::assertSame(1, $this->opLogCount('JOB_FAILED_FINAL'), 'the terminal transition must not repeat');
    }

    /**
     * Fixture isolation: `tick(1)` must be able to claim only the job under test.
     * The deletes run inside the test transaction and are rolled back in tearDown.
     */
    private function purgeJobs(): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM ' . App::db()->table('cpms_jobs')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(int $jobId): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . App::db()->table('cpms_jobs') . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $jobId
            ),
            ARRAY_A
        );
        self::assertNotNull($row, 'job row must exist');

        return $row;
    }

    private function opLogCount(string $message): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_operational_logs') . ' WHERE message = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $message
            )
        );
    }
}

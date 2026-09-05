<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * TP-13 — Queue: enqueue/claim/complete/fail/retry + Idempotency Dispatcher.
 */
final class JobQueueTest extends WP_UnitTestCase
{
    private \ClinicCore\Infrastructure\Queue\JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->queue = App::jobs();
    }

    public function testEnqueueClaimComplete(): void
    {
        $jobId = $this->queue->enqueue('test.job', ['x' => 1]);
        $job = $this->queue->claim('worker-test');
        $this->assertNotNull($job);
        $this->assertSame((string) $jobId, (string) $job['id']);
        $this->queue->complete((int) $job['id']);

        $status = $this->dbStatus((int) $job['id']);
        $this->assertSame('success', $status);
    }

    public function testFutureJobNotClaimed(): void
    {
        $this->queue->enqueue('test.later', [], (new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'))));
        $this->assertNull($this->queue->claim('worker-test'));
    }

    public function testFailRetriesWithBackoffThenFails(): void
    {
        $jobId = $this->queue->enqueue('test.flaky', [], null, 5, 2); // max 2 attempts

        $job = $this->queue->claim('w');
        $this->queue->fail((int) $job['id'], 'boom-1');
        $this->assertSame('queued', $this->dbStatus((int) $job['id']), 'آزمون اول → retry');

        // جاب را قابل Claim مجدد کن (بازگشت run_after)
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_jobs SET run_after = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            '2020-01-01 00:00:00.000',
            $jobId
        ));

        $job2 = $this->queue->claim('w');
        $this->assertNotNull($job2);
        $this->queue->fail((int) $job2['id'], 'boom-2');
        $this->assertSame('failed', $this->dbStatus((int) $job2['id']), 'آزمون دوم → failed نهایی');
    }

    public function testPriorityOrdering(): void
    {
        $this->queue->enqueue('test.low', [], null, 1);
        $this->queue->enqueue('test.high', [], null, 9);

        $first = $this->queue->claim('w');
        $this->assertSame('test.high', $first['type']);
    }

    public function testReleaseStaleLocks(): void
    {
        global $wpdb;
        $jobId = $this->queue->enqueue('test.stale');
        $job = $this->queue->claim('dead-worker');
        $this->assertNotNull($job);

        // لاک را منقضی نشان بده (شبیه‌سازی Worker مرده)
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_jobs SET lock_expires_at = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            '2020-01-01 00:00:00.000',
            $jobId
        ));

        $this->queue->releaseStaleLocks();
        $this->assertSame('queued', $this->dbStatus((int) $job['id']));

        // دوباره قابل Claim
        $again = $this->queue->claim('new-worker');
        $this->assertNotNull($again);
    }

    public function testDispatcherProcessesAndStopsWhenEmpty(): void
    {
        $dispatcher = new JobsDispatcher($this->queue, App::op());
        $calls = 0;
        $dispatcher->register('test.count', static function (array $payload) use (&$calls): void {
            $calls += (int) ($payload['n'] ?? 1);
        });

        $this->queue->enqueue('test.count', ['n' => 2]);
        $this->queue->enqueue('test.count', ['n' => 3]);

        $processed = $dispatcher->tick(10);
        $this->assertSame(2, $processed);
        $this->assertSame(5, $calls);

        // tick دوم: Job باقی‌مانده نیست (Idempotency چرخه)
        $this->assertSame(0, $dispatcher->tick(10));
        $this->assertSame(5, $calls, 'تکرار tick نباید دوباره اجرا کند');
    }

    public function testFailedJobAlertsOpLog(): void
    {
        $jobId = $this->queue->enqueue('test.doomed', [], null, 5, 1);
        $job = $this->queue->claim('w');
        $this->queue->fail((int) $job['id'], 'final-error');

        global $wpdb;
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_operational_logs WHERE message = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'JOB_FAILED_FINAL'
            )
        );
        $this->assertSame(1, (int) $found);
    }

    private function dbStatus(int $jobId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $jobId
            )
        );
    }
}

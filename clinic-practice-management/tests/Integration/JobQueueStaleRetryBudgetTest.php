<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

final class JobQueueStaleRetryBudgetTest extends WP_UnitTestCase
{
    private \ClinicCore\Infrastructure\Queue\JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->queue = App::jobs();
        $this->purgeTestJobs();
    }

    protected function tearDown(): void
    {
        $this->purgeTestJobs();
        parent::tearDown();
    }

    private function purgeTestJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type LIKE "test.stale-budget%"');
    }

    private function dbRow(int $jobId): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id = %d', $jobId),
            ARRAY_A
        );
        self::assertNotNull($row, 'Job must exist');
        return $row;
    }

    private function dbStatus(int $jobId): string
    {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT status FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id = %d', $jobId)
        );
    }

    public function testStaleRecoveryAllowsRetryWhenAttemptsLessThanMax(): void
    {
        global $wpdb;
        $jobId = $this->queue->enqueue('test.stale-budget-allow', [], null, 5, 3);
        $job = $this->queue->claim('worker-1');
        self::assertNotNull($job, 'first claim must succeed');
        self::assertSame((string) $jobId, (string) $job['id']);
        $row1 = $this->dbRow($jobId);
        self::assertSame('1', (string) $row1['attempts'], 'attempts after first claim =1');
        self::assertSame('processing', $row1['status']);

        // Expire lock
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_jobs SET lock_expires_at = %s WHERE id = %d',
            '2020-01-01 00:00:00.000',
            $jobId
        ));

        $recovered = $this->queue->releaseStaleLocks();
        self::assertGreaterThanOrEqual(1, $recovered, 'stale recovery should recover 1 job when attempts<max');
        self::assertSame('queued', $this->dbStatus($jobId), 'stale job with attempts<max must return to QUEUED');

        // Second claim should succeed, attempts=2
        $job2 = $this->queue->claim('worker-2');
        self::assertNotNull($job2, 'second claim after stale recovery must succeed when attempts<max');
        $row2 = $this->dbRow($jobId);
        self::assertSame('2', (string) $row2['attempts'], 'attempts after second claim =2');
    }

    public function testStaleRecoveryDoesNotReturnToQueuedWhenAttemptsGeMax(): void
    {
        global $wpdb;
        $jobId = $this->queue->enqueue('test.stale-budget-deny', [], null, 5, 2); // max 2

        // First claim -> attempts 1
        $job1 = $this->queue->claim('w1');
        self::assertNotNull($job1);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_jobs SET lock_expires_at = %s WHERE id = %d',
            '2020-01-01 00:00:00.000',
            $jobId
        ));
        $this->queue->releaseStaleLocks();
        self::assertSame('queued', $this->dbStatus($jobId));

        // Second claim -> attempts 2 == max
        $job2 = $this->queue->claim('w2');
        self::assertNotNull($job2);
        $row2 = $this->dbRow($jobId);
        self::assertSame('2', (string) $row2['attempts'], 'attempts after second claim = max');

        // Expire again
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_jobs SET lock_expires_at = %s WHERE id = %d',
            '2020-01-01 00:00:00.000',
            $jobId
        ));

        // This stale recovery must NOT return to QUEUED, must terminalize
        $this->queue->releaseStaleLocks();

        $statusAfter = $this->dbStatus($jobId);
        // Expected after fix: FAILED (terminal), not QUEUED
        self::assertSame('failed', $statusAfter, 'stale job with attempts>=max must NOT return to QUEUED, must become FAILED — found status=' . $statusAfter);

        // Must not be claimable again
        $job3 = $this->queue->claim('w3');
        self::assertNull($job3, 'terminalized stale job must not be claimable again');
    }

    public function testTerminalStaleRecoveryAttemptsCannotExceedMax(): void
    {
        global $wpdb;
        $jobId = $this->queue->enqueue('test.stale-budget-terminal', [], null, 5, 2);

        // Claim twice to reach max
        $this->queue->claim('w1');
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_jobs SET lock_expires_at = %s WHERE id = %d', '2020-01-01 00:00:00.000', $jobId));
        $this->queue->releaseStaleLocks();
        $this->queue->claim('w2');
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_jobs SET lock_expires_at = %s WHERE id = %d', '2020-01-01 00:00:00.000', $jobId));

        // Terminal recovery
        $this->queue->releaseStaleLocks();
        $row = $this->dbRow($jobId);
        self::assertSame('failed', $row['status'], 'terminal state must be FAILED');
        self::assertNotNull($row['completed_at'], 'completed_at must be set for terminal stale job');
        self::assertNull($row['locked_by'], 'locked_by must be cleared');
        self::assertNull($row['lock_expires_at'], 'lock_expires_at must be cleared');
        // Attempts must NOT exceed max
        self::assertLessThanOrEqual((int) $row['max_attempts'], (int) $row['attempts'], 'attempts must not exceed max_attempts after terminal stale recovery — attempts=' . $row['attempts'] . ' max=' . $row['max_attempts']);
    }

    public function testExistingFailBackoffUnchanged(): void
    {
        $jobId = $this->queue->enqueue('test.stale-budget-fail', [], null, 5, 2);
        $job = $this->queue->claim('w');
        $this->queue->fail((int) $job['id'], 'boom-1');
        self::assertSame('queued', $this->dbStatus((int) $job['id']), 'first fail -> retry');

        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_jobs SET run_after = %s WHERE id = %d', '2020-01-01 00:00:00.000', $jobId));
        $job2 = $this->queue->claim('w');
        self::assertNotNull($job2);
        $this->queue->fail((int) $job2['id'], 'boom-2');
        self::assertSame('failed', $this->dbStatus((int) $job2['id']), 'second fail -> terminal FAILED');
    }
}

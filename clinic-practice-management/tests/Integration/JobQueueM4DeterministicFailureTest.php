<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobPayloadInvalidException;
use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;
use WP_UnitTestCase;

/**
 * M-4 RED: a typed, unchanged invalid job payload must not use generic retry.
 *
 * This intentionally asserts the desired terminal state. On main, the real
 * dispatcher catches the typed exception and JobQueue::fail() requeues it,
 * producing a genuine product RED rather than a fixture/bootstrap failure.
 */
final class JobQueueM4DeterministicFailureTest extends WP_UnitTestCase
{
    private JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->queue = App::jobs();
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}cpms_jobs WHERE type IN ('m4.invalid','m4.transient')");
    }

    public function testTypedInvalidPayloadIsTerminalOnFirstAttempt(): void
    {
        global $wpdb;
        $dispatcher = new JobsDispatcher($this->queue, App::op(), true);
        $handlerReached = false;
        $dispatcher->register('m4.invalid', function (array $payload) use (&$handlerReached): void {
            $handlerReached = true;
            throw new JobPayloadInvalidException(
                'JOB_PAYLOAD_INVALID',
                'unchanged invalid continuation payload',
                ['payload_keys' => array_keys($payload)]
            );
        });

        $jobId = $this->queue->enqueue('m4.invalid', ['continuation' => 'invalid'], null, 5, 3);
        self::assertGreaterThan(0, $jobId, 'enqueue must succeed');

        $dispatcher->tick(1, 'm4-red-worker');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, attempts, run_after, last_error FROM {$wpdb->prefix}cpms_jobs WHERE id = %d",
            $jobId
        ), ARRAY_A);
        self::assertNotEmpty($row, 'job row must remain available for evidence');
        self::assertTrue($handlerReached, 'real registered handler must be reached');
        self::assertSame(1, (int) $row['attempts'], 'exactly first attempt must be consumed');
        self::assertSame('unchanged invalid continuation payload', (string) $row['last_error'], 'typed failure must reach queue');
        self::assertSame(
            JobQueue::FAILED,
            (string) $row['status'],
            'M-4 RED_SIGNATURE expected=failed actual=' . $row['status']
            . ' attempts=' . $row['attempts'] . ' run_after=' . $row['run_after']
        );
    }

    public function testTransientFailureKeepsExistingRetryBackoff(): void
    {
        global $wpdb;
        $dispatcher = new JobsDispatcher($this->queue, App::op(), true);
        $dispatcher->register('m4.transient', static function (array $payload): void {
            throw new \RuntimeException('transient-control');
        });
        $jobId = $this->queue->enqueue('m4.transient', [], null, 5, 2);
        $before = time();
        $dispatcher->tick(1, 'm4-transient-worker');
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, attempts, run_after, last_error FROM {$wpdb->prefix}cpms_jobs WHERE id = %d", $jobId), ARRAY_A);
        self::assertSame(JobQueue::QUEUED, (string) $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('transient-control', (string) $row['last_error']);
        self::assertGreaterThan($before, strtotime((string) $row['run_after']));
    }

    public function testTransientFailureReachesExistingMaxAttempts(): void
    {
        global $wpdb;
        $dispatcher = new JobsDispatcher($this->queue, App::op(), true);
        $dispatcher->register('m4.transient', static function (array $payload): void {
            throw new \RuntimeException('transient-control-final');
        });
        $jobId = $this->queue->enqueue('m4.transient', [], null, 5, 2);
        $dispatcher->tick(1, 'm4-max-worker');
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}cpms_jobs SET run_after = %s WHERE id = %d", '2020-01-01 00:00:00.000', $jobId));
        $dispatcher->tick(1, 'm4-max-worker');
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, attempts FROM {$wpdb->prefix}cpms_jobs WHERE id = %d", $jobId), ARRAY_A);
        self::assertSame(JobQueue::FAILED, (string) $row['status']);
        self::assertSame(2, (int) $row['attempts']);
    }
}

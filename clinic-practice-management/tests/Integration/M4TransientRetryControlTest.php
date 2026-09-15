<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobsDispatcher;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WP_UnitTestCase;

/**
 * M-4 — transient/retryable control set.
 *
 * The slice must NOT change the disposition of failures that are not declared
 * non-retryable: they keep the existing `queued` + `run_after` (backoff) retry
 * path while attempts < max_attempts, and the existing terminal transition at
 * the max_attempts threshold.
 *
 * This file is expected to be GREEN both on the pre-fix head and after the M-4
 * change (it is a control, not a RED). It fails only if the change over-reaches:
 * terminalizing a plain RuntimeException, terminalizing ScopeRequiredException,
 * or dropping the max_attempts threshold.
 */
final class M4TransientRetryControlTest extends WP_UnitTestCase
{
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

    /**
     * A plain retryable RuntimeException (implements no non-retryable contract)
     * must keep the existing generic backoff behaviour.
     */
    public function testGenericRuntimeExceptionStillRetriesWithExistingBackoff(): void
    {
        // Same public test-infrastructure pattern as `JobQueueTest`: an artificial
        // type plus the explicit opt-out of the production scope registry.
        $dispatcher = new JobsDispatcher(App::jobs(), App::op(), true);
        $dispatcher->register('test.m4_transient', static function (array $payload): void {
            throw new RuntimeException('transient failure');
        });

        $jobId = App::jobs()->enqueue(
            'test.m4_transient',
            [],
            new DateTimeImmutable('-60 seconds', new DateTimeZone('UTC')),
            99,
            3
        );

        $dispatcher->tick(1);

        $row = $this->jobRow($jobId);
        self::assertSame(JobQueue::QUEUED, (string) $row['status'], 'a transient failure must remain retryable');
        self::assertSame(1, (int) $row['attempts'], 'the first attempt must be consumed');
        self::assertNull($row['locked_by'], 'the worker lock must be released between attempts');
        self::assertGreaterThan(
            gmdate('Y-m-d H:i:s'),
            substr((string) $row['run_after'], 0, 19),
            'run_after must be in the future (existing backoff)'
        );
        self::assertSame(1, $this->opLogCount('JOB_RETRY'), 'the generic retry must be logged');
        self::assertSame(0, $this->opLogCount('JOB_FAILED_FINAL'), 'a transient failure must not be terminal');
    }

    /**
     * A missing tenant scope is explicitly NOT terminal in this slice (the M-4
     * contract forbids terminalizing every ScopeRequiredException). Typed evidence
     * comes from the real registered path — `report.export` validates
     * `payload.clinic_id` fail-closed — and the queue outcome then proves the
     * dispatcher keeps it retryable.
     */
    public function testScopeRequiredFailureOnRealRegisteredPathRemainsRetryable(): void
    {
        try {
            App::exportService()->generate([]);
            self::fail('report.export with an empty payload must fail closed with ScopeRequiredException');
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode, 'typed scope failure code');
        }

        $jobId = App::jobs()->enqueue(
            'report.export',
            [],
            new DateTimeImmutable('-60 seconds', new DateTimeZone('UTC')),
            99,
            3
        );

        App::dispatcher()->tick(1);

        $row = $this->jobRow($jobId);
        self::assertSame(
            JobQueue::QUEUED,
            (string) $row['status'],
            'ScopeRequiredException must remain retryable (no broad terminalization)'
        );
        self::assertSame(1, (int) $row['attempts'], 'the first attempt must be consumed');
        self::assertGreaterThan(
            gmdate('Y-m-d H:i:s'),
            substr((string) $row['run_after'], 0, 19),
            'the existing backoff must apply'
        );
        self::assertSame(0, $this->opLogCount('JOB_FAILED_FINAL'), 'a scope failure must not be terminal in this slice');
    }

    /**
     * The existing threshold behaviour must stay: once attempts reaches
     * max_attempts the job becomes terminal `failed`.
     */
    public function testMaxAttemptsExhaustionStillTerminalizesAtExistingThreshold(): void
    {
        $jobId = App::jobs()->enqueue(
            'report.export',
            [],
            new DateTimeImmutable('-60 seconds', new DateTimeZone('UTC')),
            99,
            1 // a single attempt: the threshold is reached by the first failure
        );

        App::dispatcher()->tick(1);

        $row = $this->jobRow($jobId);
        self::assertSame(JobQueue::FAILED, (string) $row['status'], 'the max_attempts threshold must still terminalize');
        self::assertSame(1, (int) $row['attempts'], 'attempts must stay coherent');
        self::assertNotNull($row['completed_at'], 'a terminal job must record completed_at');
        self::assertNull($row['locked_by'], 'a terminal job must release the worker lock');
        self::assertSame(0, $this->opLogCount('JOB_RETRY'), 'no retry may be scheduled when the budget is exhausted');
        self::assertSame(1, $this->opLogCount('JOB_FAILED_FINAL'), 'the terminal transition must be logged once');
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

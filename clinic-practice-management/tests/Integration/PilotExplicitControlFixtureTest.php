<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;
use WP_UnitTestCase;

/**
 * Phase 19 — Pilot explicit-tick control fixture (bin/pilot-job-start.php, explicit mode).
 *
 * The explicit control enqueues its synthetic batch through the same production
 * JobQueue class WITHOUT the optional advisory JobWake collaborator. This test pins
 * the mechanism the control relies on:
 *  1. the no-wake fixture persists its rows and schedules NO installation-wide wake;
 *  2. those rows are not drained by anything except an explicit runTick() call;
 *  3. the autonomous production queue (App::jobs()) still requests the wake.
 *
 * Independent of JobFastWakeContractRedTest, which remains the autonomous contract.
 */
final class PilotExplicitControlFixtureTest extends WP_UnitTestCase
{
    private const WAKE_HOOK = 'cpms_jobs_wake';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        wp_clear_scheduled_hook(self::WAKE_HOOK);
        delete_transient('doing_cron');
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook(self::WAKE_HOOK);
        delete_transient('doing_cron');
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jobRow(int $jobId): ?array
    {
        $db = App::db();

        return $db->fetchRow(
            'SELECT * FROM ' . $db->table('cpms_jobs') . ' WHERE id = %d',
            [$jobId]
        );
    }

    public function testExplicitFixtureSchedulesNoWakeAndLeavesRowsQueued(): void
    {
        $queue = new JobQueue(App::db(), App::op());

        $ids = [];
        for ($index = 0; $index < 3; $index++) {
            $ids[] = $queue->enqueue('backup.run', [], priority: 1, maxAttempts: 1);
        }

        $this->assertFalse(
            wp_next_scheduled(self::WAKE_HOOK),
            'the explicit control fixture must not request an autonomous fast wake'
        );
        foreach ($ids as $jobId) {
            $row = $this->jobRow($jobId);
            $this->assertNotNull($row);
            $this->assertSame('queued', (string) $row['status']);
            $this->assertNull($row['started_at'], 'nothing may start the explicit batch before the explicit tick');
            $this->assertSame(0, (int) $row['attempts']);
        }
    }

    public function testExplicitFixtureRowsStartOnlyWhenAnExplicitRunTickRuns(): void
    {
        $queue = new JobQueue(App::db(), App::op());
        $jobId = $queue->enqueue('backup.run', [], priority: 1, maxAttempts: 1);

        $before = $this->jobRow($jobId);
        $this->assertNotNull($before);
        $this->assertSame('queued', (string) $before['status'], 'no wake may drain the no-wake fixture');

        $processed = App::runTick(500);
        $this->assertGreaterThanOrEqual(1, $processed, 'the explicit tick must process the queued fixture row');

        $after = $this->jobRow($jobId);
        $this->assertNotNull($after);
        $this->assertNotNull($after['started_at'], 'the explicit tick must claim the row');
        $this->assertSame(1, (int) $after['attempts'], 'the explicit tick must claim the row exactly once');
        $this->assertNotSame('queued', (string) $after['status']);
    }

    public function testAutonomousProductionQueueStillRequestsTheWake(): void
    {
        App::jobs()->enqueue('backup.run', [], priority: 1, maxAttempts: 1);

        $this->assertNotFalse(
            wp_next_scheduled(self::WAKE_HOOK),
            'the autonomous production path keeps its advisory fast wake'
        );
    }
}

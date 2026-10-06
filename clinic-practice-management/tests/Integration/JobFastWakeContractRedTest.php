<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobWake;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 17 — autonomous background-job fast-start scheduling contract (RED).
 *
 * Product contract under test (measurement slice):
 *  1. `enqueue()` persists the job row FIRST; the wake-up is advisory only.
 *  2. A successful enqueue requests ONE near-immediate, installation-wide
 *     WordPress-native wake-up — not one event per job.
 *  3. Burst enqueues coalesce to ONE pending wake event within the window.
 *  4. The wake callback uses the EXISTING `App::runTick()` path, so the
 *     existing tick lock + `JobQueue::claim()` stay the only processing
 *     authority; a duplicate wake callback is harmless.
 *  5. The existing recurring minute schedule (`cpms_jobs_tick`, `cpms_minute`)
 *     stays registered and unchanged as the recovery/fallback trigger.
 *  6. Wake-up failure must not make the enqueue fail or lose the persisted job;
 *     the fallback still processes queued jobs when no fast wake is available.
 *  7. No Clinic/user authority and no arbitrary/input-controlled wake target:
 *     the event carries no args and the loopback target is WordPress's own
 *     `wp-cron.php` reached through `spawn_cron()`.
 *
 * This file is the test-only RED head for the slice: it reaches the missing
 * fast-wake product contract and is not a syntax/bootstrap/fixture failure.
 */
final class JobFastWakeContractRedTest extends WP_UnitTestCase
{
    /**
     * Fixed, installation-wide wake key. Deliberately NOT a constant on the
     * production class in this file: the literal is the contract the product
     * must satisfy, so the RED run cannot pass by redefining the key.
     */
    private const WAKE_HOOK = 'cpms_jobs_wake';

    private const RECURRING_HOOK = 'cpms_jobs_tick';

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
        remove_all_filters('pre_schedule_event');
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    /**
     * Count pending cron entries for one hook across the whole cron array.
     */
    private function pendingEvents(string $hook): int
    {
        $count = 0;
        foreach (_get_cron_array() as $timestamp => $hooks) {
            if (isset($hooks[$hook]) && is_array($hooks[$hook])) {
                $count += count($hooks[$hook]);
            }
        }

        return $count;
    }

    /**
     * @return list<int> enqueued job ids
     */
    private function enqueueBackupRuns(int $count): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = App::jobs()->enqueue('backup.run', [], priority: 1, maxAttempts: 1);
        }

        return $ids;
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

    public function testFirstEnqueueRequestsOneInstallationWideFastWakeUp(): void
    {
        $ids = $this->enqueueBackupRuns(1);

        $this->assertGreaterThan(0, $ids[0]);
        $this->assertNotFalse(
            wp_next_scheduled(self::WAKE_HOOK),
            'the production enqueue path must request a near-immediate fast wake-up'
        );
        $this->assertSame(
            1,
            $this->pendingEvents(self::WAKE_HOOK),
            'exactly one installation-wide wake event must be pending'
        );

        // WordPress-native single event: not a recurring schedule.
        $event = wp_get_scheduled_event(self::WAKE_HOOK);
        $this->assertNotNull($event);
        $this->assertFalse($event->schedule, 'the fast wake-up must be a single event, not recurring');
        $this->assertSame([], $event->args, 'the wake key is installation-wide and carries no args');
    }

    public function testBurstEnqueueCoalescesToOnePendingWakeEvent(): void
    {
        $ids = $this->enqueueBackupRuns(25);

        $this->assertCount(25, $ids);
        $this->assertSame(
            1,
            $this->pendingEvents(self::WAKE_HOOK),
            'a burst of enqueues must coalesce to one pending wake-up within the window'
        );
        $this->assertSame(
            1,
            $this->pendingEvents(self::WAKE_HOOK),
            'repeated bursts inside the coalescing window must not add wake events'
        );
    }

    /**
     * STEP 5 request-path overhead bound: the production enqueue path must not
     * perform any network call, and a burst may reserve at most one
     * installation-wide wake scheduling attempt (repeated attempts inside the
     * coalescing window are skipped, not re-scheduled).
     */
    public function testBurstEnqueueDoesNoNetworkAndReservesOneWakeSchedule(): void
    {
        if (!class_exists(JobWake::class)) {
            $this->fail('ClinicCore\Application\Jobs\JobWake does not exist yet (missing fast-wake contract)');
        }

        $http_calls        = 0;
        $schedule_attempts = 0;

        add_filter(
            'pre_http_request',
            static function ($pre) use (&$http_calls) {
                $http_calls++;

                return false;
            },
            10,
            1
        );
        add_filter(
            'pre_schedule_event',
            static function ($pre, $event) use (&$schedule_attempts) {
                if (is_object($event) && isset($event->hook) && JobWake::HOOK === $event->hook) {
                    $schedule_attempts++;
                }

                return $pre;
            },
            10,
            2
        );

        $this->enqueueBackupRuns(25);

        remove_all_filters('pre_http_request');
        remove_all_filters('pre_schedule_event');

        $this->assertSame(0, $http_calls, 'the enqueue request path must never perform a network call');
        $this->assertSame(1, $schedule_attempts, 'a burst must attempt exactly one installation-wide wake scheduling');
        $this->assertSame(1, $this->pendingEvents(JobWake::HOOK), 'exactly one wake event may remain pending after a burst');
    }

    public function testRecurringMinuteTickRemainsRegisteredAndUnchanged(): void
    {
        $before = wp_get_scheduled_event(self::RECURRING_HOOK);
        $this->assertNotNull($before, 'the existing recurring minute tick must stay registered');
        $this->assertSame('cpms_minute', $before->schedule);
        $this->assertSame(60, $before->interval);

        $this->enqueueBackupRuns(5);

        $after = wp_get_scheduled_event(self::RECURRING_HOOK);
        $this->assertNotNull($after);
        $this->assertSame($before->schedule, $after->schedule);
        $this->assertSame($before->interval, $after->interval);
        $this->assertSame($before->timestamp, $after->timestamp);
    }

    public function testEnqueueNeverRunsTheHandlerSynchronously(): void
    {
        $ids = $this->enqueueBackupRuns(1);

        $row = $this->jobRow($ids[0]);
        $this->assertNotNull($row);
        $this->assertSame('queued', (string) $row['status']);
        $this->assertNull($row['started_at'], 'the heavy handler must not run inside the enqueue request');
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNotFalse(wp_next_scheduled(self::WAKE_HOOK));
    }

    public function testWakeCallbackProcessesThroughExistingRunTickAndIsDuplicateSafe(): void
    {
        $ids = $this->enqueueBackupRuns(1);

        do_action(self::WAKE_HOOK);

        $row = $this->jobRow($ids[0]);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['attempts'], 'the wake callback must claim through the existing runTick path');
        $this->assertNotNull($row['started_at']);
        $this->assertNotSame('queued', (string) $row['status']);

        // A duplicate wake callback must be safe: the existing claim semantics
        // make a second run harmless (no re-claim, no retry overwrite).
        do_action(self::WAKE_HOOK);
        $after = $this->jobRow($ids[0]);
        $this->assertNotNull($after);
        $this->assertSame(1, (int) $after['attempts']);
        $this->assertSame((string) $row['started_at'], (string) $after['started_at']);
    }

    public function testEnqueuePersistsTheJobWhenWakeSchedulingFails(): void
    {
        // WordPress-native failure injection: refuse the wake event scheduling.
        add_filter('pre_schedule_event', '__return_false');

        $ids = $this->enqueueBackupRuns(1);

        $this->assertGreaterThan(0, $ids[0], 'enqueue success must not depend on wake-up scheduling');
        $row = $this->jobRow($ids[0]);
        $this->assertNotNull($row, 'the job row must be persisted even when the wake-up cannot be scheduled');
        $this->assertSame('queued', (string) $row['status']);
        $this->assertSame(0, $this->pendingEvents(self::WAKE_HOOK));

        // Removing the failure must restore the fast wake-up request.
        remove_all_filters('pre_schedule_event');
        $this->enqueueBackupRuns(1);
        $this->assertNotFalse(
            wp_next_scheduled(self::WAKE_HOOK),
            'the wake-up request must work once scheduling is possible again'
        );

        // The minute fallback remains the authority and still drains the queue.
        wp_clear_scheduled_hook(self::WAKE_HOOK);
        App::runTick(50);
        $drained = $this->jobRow($ids[0]);
        $this->assertNotNull($drained);
        $this->assertSame(1, (int) $drained['attempts']);
    }

    public function testFallbackProcessesQueuedJobsWhenFastWakeIsUnavailable(): void
    {
        $ids = $this->enqueueBackupRuns(3);

        // Simulate a host where the fast wake-up is unavailable/blocked.
        wp_clear_scheduled_hook(self::WAKE_HOOK);

        App::runTick(50);

        foreach ($ids as $jobId) {
            $row = $this->jobRow($jobId);
            $this->assertNotNull($row);
            $this->assertSame(1, (int) $row['attempts'], 'the recurring/fallback trigger must still process queued jobs');
            $this->assertNotSame('queued', (string) $row['status']);
        }
    }

    public function testWakeTargetIsWordPressNativeAndNotInputControlled(): void
    {
        if (!class_exists(JobWake::class)) {
            $this->fail('ClinicCore\Application\Jobs\JobWake does not exist yet (missing fast-wake contract)');
        }

        $captured = [];
        add_filter(
            'pre_http_request',
            static function ($pre, array $args, string $url) use (&$captured) {
                $captured[] = ['url' => $url, 'args' => $args];

                return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => ''];
            },
            10,
            3
        );

        $this->enqueueBackupRuns(2);
        $this->assertSame(JobWake::HOOK, self::WAKE_HOOK, 'the wake key must stay installation-wide and stable');

        JobWake::spawn();

        $this->assertCount(1, $captured, 'the loopback spawn must be a single coalesced request');
        $url = $captured[0]['url'];
        $args = $captured[0]['args'];
        $site = site_url();
        $this->assertStringStartsWith(rtrim($site, '/'), $url);
        $this->assertStringContainsString('wp-cron.php', $url);
        $this->assertSame(
            (string) wp_parse_url($site, PHP_URL_HOST),
            (string) wp_parse_url($url, PHP_URL_HOST),
            'the wake target must be the site’s own host, never an arbitrary external URL'
        );
        $this->assertFalse($args['blocking'] ?? true, 'the loopback must be non-blocking');
        $body = $args['body'] ?? null;
        $this->assertTrue(
            null === $body || '' === $body,
            'no payload/job data may be sent over the loopback'
        );

        delete_transient('doing_cron');
    }

    public function testWakeIntroducesNoClinicOrUserAuthorityAndNoRestEndpoint(): void
    {
        if (!class_exists(JobWake::class)) {
            $this->fail('ClinicCore\Application\Jobs\JobWake does not exist yet (missing fast-wake contract)');
        }

        $this->enqueueBackupRuns(1);
        $event = wp_get_scheduled_event(JobWake::HOOK);
        $this->assertNotNull($event);
        $this->assertSame([], $event->args, 'the wake event must not carry Clinic/user/job identity');

        $routes = rest_get_server()->get_routes();
        foreach (array_keys($routes) as $route) {
            $this->assertStringNotContainsString(
                JobWake::HOOK,
                (string) $route,
                'the fast wake-up must not add an externally callable privileged endpoint'
            );
        }
    }
}

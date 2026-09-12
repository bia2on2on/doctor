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
        // زیرساختِ عمومیِ تست: `test.count` یک نوعِ **مصنوعی** است و عمداً در
        // `JobScopeRegistry` محصول ثبت نشده. از Slice 1B.1 به بعد enforcementِ
        // T/S/W **پیش‌فرض** است، پس opt-out باید صریح باشد (آرگومانِ سوم).
        // این تنها مصرف‌کنندهٔ مجازِ حالتِ سهل‌گیر است؛ production هرگز از آن
        // استفاده نمی‌کند.
        $dispatcher = new JobsDispatcher($this->queue, App::op(), true);
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

    /**
     * FR-5.5 — جاب‌های تکرارشونده: بعد از اجرا (complete) دوباره
     * زمان‌بندی می‌شوند و بدون نسخه Queued دوباره ثبت نمی‌شوند (Idempotent).
     */
    public function testRecurringJobsAreRescheduledIdempotently(): void
    {
        // اولین زمان‌بندی: هر سه جاب تکرارشونده در صف
        App::scheduleRecurringJobs();
        $queuedCount = static fn (): int => (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s',
            ['visits.no_show', \ClinicCore\Infrastructure\Queue\JobQueue::QUEUED]
        );
        $this->assertSame(1, $queuedCount());

        // دوباره صدا زدن → هیچ نسخه جدیدی (dedup)
        App::scheduleRecurringJobs();
        $this->assertSame(1, $queuedCount());

        // اجرای tick واقعی → جاب پردازش شد → صف خالی از no_show
        $processed = App::dispatcher()->tick(20);
        $this->assertGreaterThanOrEqual(1, $processed);
        $this->assertSame(0, $queuedCount());

        // زمان‌بندی دوباره (مثل cpms_jobs_tick بعدی) → باز در صف
        App::scheduleRecurringJobs();
        $this->assertSame(1, $queuedCount());
    }

    /**
     * Regression (Pilot Gate / J-4): مسیر CLI (`bin/cpms jobs tick`) و WP-Cron
     * باید یکسان باشند — هر Tick جاب‌های دوره‌ای را (Idempotent) دوباره
     * زمان‌بندی «و در همان Tick اجرا» کند. قبلاً bin/cpms فقط dispatcher()
     * را صدا می‌زد → در استقرار system-cron جاب‌های دوره‌ای بعد از اولین
     * اجرا برای همیشه متوقف می‌شدند (FR-5.5).
     */
    public function testRunTickReschedulesAndProcessesRecurringJobs(): void
    {
        $successCount = static fn (): int => (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE type = %s AND status = %s',
            ['visits.no_show', \ClinicCore\Infrastructure\Queue\JobQueue::SUCCESS]
        );

        // خالی کردن صف (Tick قبل از تست — بدون re-schedule)
        App::dispatcher()->tick(50);
        $before = $successCount();
        $this->assertSame(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE status = %s',
                [\ClinicCore\Infrastructure\Queue\JobQueue::QUEUED]
            ),
            'پیش‌شرط: صف باید خالی باشد'
        );

        // runTick = مسیر مشترک WP-Cron و CLI: re-enqueue + اجرا در همان چرخه
        $processed = App::runTick(50);
        $this->assertGreaterThanOrEqual(1, $processed, 'runTick باید جاب‌های دوره‌ای re-enqueue شده را اجرا کند');
        $this->assertSame(
            $before + 1,
            $successCount(),
            'جاب دوره‌ای باید در همان runTick دوباره زمان‌بندی و اجرا شود (FR-5.5)'
        );

        // چرخه دوم هم باید دوباره اجرا کند (پیوستگی زمان‌بندی دوره‌ای)
        $this->assertGreaterThanOrEqual(1, App::runTick(50));
        $this->assertSame($before + 2, $successCount());
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

    public function testFailWithLostLockDoesNotTouchJob(): void
    {
        // F1-6 — Worker کهنه بعد از چرخش قفل حق تغییر سرنوشت Job را ندارد
        $jobId = $this->queue->enqueue('test.lostlock', [], null, 5, 3);
        $job = $this->queue->claim('w1');
        $this->assertNotNull($job);

        global $wpdb;
        // شبیه‌سازی چرخش قفل: Worker دیگری (w2) بعد از انقضای لاک Claim کرده است
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_jobs SET locked_by = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'w2',
            $jobId
        ));

        $this->queue->fail((int) $job['id'], 'zombie-fail', 'w1');

        $this->assertSame('processing', $this->dbStatus($jobId), 'Status نباید عوض شود');
        $row = $this->dbRow($jobId);
        $this->assertSame('w2', (string) $row['locked_by'], 'قفل باید دست Worker جدید بماند');
        $this->assertNull($row['last_error'], 'last_error نباید نوشته شود');
        $this->assertSame('1', (string) $row['attempts'], 'attempts نباید دست بخورد');
    }

    public function testFailWithOwnerWorkerSchedulesRetry(): void
    {
        $jobId = $this->queue->enqueue('test.ownerfail', [], null, 5, 3);
        $job = $this->queue->claim('w1');
        $this->assertNotNull($job);

        $this->queue->fail($jobId, 'boom', 'w1');

        $this->assertSame('queued', $this->dbStatus($jobId));
        $row = $this->dbRow($jobId);
        $this->assertNull($row['locked_by']);
        $this->assertSame('boom', (string) $row['last_error']);
        $this->assertGreaterThan(
            gmdate('Y-m-d H:i:s'),
            substr((string) $row['run_after'], 0, 19),
            'run_after باید آینده باشد (Backoff)'
        );
    }

    public function testFailFinalWithOwnerWorkerMarksFailed(): void
    {
        $jobId = $this->queue->enqueue('test.finalfail', [], null, 5, 1);
        $job = $this->queue->claim('w1');
        $this->assertNotNull($job);

        $this->queue->fail($jobId, 'fatal', 'w1');

        $this->assertSame('failed', $this->dbStatus($jobId));
        $row = $this->dbRow($jobId);
        $this->assertNotNull($row['completed_at']);
        $this->assertNull($row['locked_by']);
    }

    /**
     * @return array<string, mixed>
     */
    private function dbRow(int $jobId): array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'cpms_jobs WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $jobId
            ),
            ARRAY_A
        );
        $this->assertNotNull($row, 'Job باید وجود داشته باشد');

        return $row;
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

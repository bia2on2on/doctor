<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\VisitsNoShowHandler;
use ClinicCore\Bootstrap\App;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;

final class VisitNoShowQueueHardeningTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->buildMultiLocationTemporalFixture();
        $this->purgeJobs();
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeMultiLocationTemporalFixture();
        parent::tearDown();
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');
    }

    public function testSchedulerSuppressesRootWhileProcessing(): void
    {
        global $wpdb;
        $db = App::db();
        $this->purgeJobs();

        $nowSql = $db->nowUtcSql();
        $futureLock = gmdate('Y-m-d H:i:s', time() + 600) . '.000';

        // Insert PROCESSING job
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_jobs') . ' (type, payload_json, status, priority, attempts, max_attempts, run_after, locked_by, lock_expires_at, created_at) VALUES (%s, %s, %s, %d, %d, %d, %s, %s, %s, %s)',
            'visits.no_show',
            '[]',
            'processing',
            5,
            1,
            3,
            $nowSql,
            'test-worker',
            $futureLock,
            $nowSql
        ));
        self::assertGreaterThan(0, $wpdb->insert_id, 'fixture: processing job inserted');

        $countBeforeQueued = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status = "queued"');
        $countBeforeTotal = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status IN ("queued","processing")');

        // This is the contract: scheduler must NOT enqueue new root while PROCESSING exists
        App::scheduleRecurringJobs();

        $countAfterQueued = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status = "queued"');
        $countAfterTotal = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status IN ("queued","processing")');

        // Before fix: countAfterQueued = 1 (duplicate root) -> RED
        // After fix: countAfterQueued = 0, total still 1 -> GREEN
        self::assertSame(0, $countAfterQueued, 'scheduler must suppress duplicate root while PROCESSING exists — found queued=' . $countAfterQueued . ' total=' . $countAfterTotal);
        self::assertSame(1, $countAfterTotal, 'only original PROCESSING should remain');
    }

    public function testSchedulerCreatesFreshRootAfterChainFinishes(): void
    {
        global $wpdb;
        $db = App::db();
        $this->purgeJobs();

        $countBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status IN ("queued","processing")');
        self::assertSame(0, $countBefore, 'precondition: no active jobs');

        App::scheduleRecurringJobs();

        $countAfter = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status = "queued"');
        self::assertSame(1, $countAfter, 'after chain finishes, scheduler must be able to create fresh root');

        $row = $wpdb->get_row('SELECT payload_json FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status = "queued" LIMIT 1', ARRAY_A);
        self::assertNotNull($row, 'root row exists');
        $payload = json_decode($row['payload_json'] ?? '[]', true);
        self::assertSame([], $payload, 'fresh root payload must be empty []');
    }

    public function testContinuationDedupPreventsDuplicateSameCursor(): void
    {
        global $wpdb;
        $db = App::db();
        $queue = App::jobs();
        $this->purgeJobs();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cursor = ['slot_date' => '2026-01-01', 'slot_time' => '00:00:00', 'id' => 1];
        $payload = ['cursor' => $cursor, 'continuation' => true, 'depth' => 1];

        // First enqueue
        $queue->enqueue('visits.no_show', $payload, $now, 5, 3);
        $count1 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status = "queued"');
        self::assertSame(1, $count1, 'first continuation enqueued');

        // Simulate parent retry trying to enqueue same cursor again via handler's maybeEnqueue path
        // We use the handler directly with a stub result that has same next_cursor
        $handler = new VisitsNoShowHandler(App::visitService(), $queue, $db, App::op());

        // Use reflection to call maybeEnqueueContinuation with same next_cursor
        $ref = new \ReflectionClass($handler);
        $method = $ref->getMethod('maybeEnqueueContinuation');
        $method->setAccessible(true);

        $result = [
            'processed' => 0,
            'next_cursor' => $cursor,
            'has_more' => true,
            'depth' => 0,
            'scanned' => 100,
        ];

        // This second attempt should be deduped — not create second job with same cursor
        $method->invoke($handler, $result, null, 0);

        $count2 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" AND status = "queued"');
        // Before fix: count2 = 2 (duplicate) -> RED
        // After fix: count2 = 1 (deduped) -> GREEN
        self::assertSame(1, $count2, 'duplicate continuation with same cursor must be suppressed — found count=' . $count2);
    }
}

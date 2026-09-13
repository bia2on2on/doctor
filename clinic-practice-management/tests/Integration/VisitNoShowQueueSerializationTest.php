<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';
use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;

final class VisitNoShowQueueSerializationTest extends WP_UnitTestCase
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
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN ("visits.no_show","slots.generate")');
    }

    public function testSlotsGenerateDoesNotUseUnlockedDispatcherTick(): void
    {
        $cpmsPath = dirname(__DIR__, 2) . '/bin/cpms';
        self::assertFileExists($cpmsPath, 'bin/cpms must exist');
        $content = file_get_contents($cpmsPath);
        self::assertNotFalse($content, 'read bin/cpms');

        // Extract slots generate block
        $hasDirectTick = false;
        if (preg_match('/case\s+\'slots\':.*?generate.*?\{.*?App::dispatcher\(\)->tick/s', $content)) {
            $hasDirectTick = true;
        }
        // Also check for any direct dispatcher tick in slots section
        $lines = explode("\n", $content);
        $inSlots = false;
        $foundDirectTickInSlots = false;
        $foundRunTickInSlots = false;
        foreach ($lines as $line) {
            if (strpos($line, "case 'slots':") !== false) {
                $inSlots = true;
            }
            if ($inSlots) {
                if (strpos($line, "App::dispatcher()->tick") !== false) {
                    $foundDirectTickInSlots = true;
                }
                if (strpos($line, "App::runTick") !== false) {
                    $foundRunTickInSlots = true;
                }
                if (strpos($line, "break;") !== false && $inSlots) {
                    // rough end of case, but continue a bit
                    if ($foundDirectTickInSlots || $foundRunTickInSlots) {
                        break;
                    }
                }
            }
        }

        // Contract: slots generate must NOT use unlocked dispatcher()->tick
        // It must use runTick (canonical locked abstraction) or enqueue-only
        self::assertFalse($foundDirectTickInSlots, 'slots generate must NOT use unlocked App::dispatcher()->tick — found direct tick, which creates second global consumer without GET_LOCK');
        self::assertTrue($foundRunTickInSlots, 'slots generate should use App::runTick (canonical locked queue consumer) for synchronous processing');
    }

    public function testRunTickSerializationPreventsConcurrentConsumption(): void
    {
        global $wpdb;
        $db = App::db();
        $this->purgeJobs();

        // Enqueue a job
        $queue = App::jobs();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $queue->enqueue('visits.no_show', [], $now, 5, 3);
        $countBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status="queued"');
        self::assertSame(1, $countBefore, 'one job queued');

        // Acquire lock via other connection
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->query("SELECT GET_LOCK('" . App::TICK_LOCK . "', 0)");
        $isFree = $other->get_var("SELECT IS_FREE_LOCK('" . App::TICK_LOCK . "')");
        self::assertSame('0', (string) $isFree, 'lock should be held by other connection');

        // runTick should skip and return -1, not consume job
        $result = App::runTick(5);
        self::assertSame(-1, $result, 'runTick must return -1 when lock held by other worker');

        $countAfter = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status="queued"');
        self::assertSame(1, $countAfter, 'job must remain queued when tick skipped due to lock');

        // Release lock
        $other->query("SELECT RELEASE_LOCK('" . App::TICK_LOCK . "')");
        $other->close();

        // Now runTick should process
        $result2 = App::runTick(5);
        self::assertGreaterThanOrEqual(0, $result2, 'runTick after lock release should process');
    }

    public function testRecurringSuppressionStillValid(): void
    {
        global $wpdb;
        $db = App::db();
        $this->purgeJobs();

        // No active jobs -> scheduler should create root
        App::scheduleRecurringJobs();
        $count1 = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status IN ("queued","processing")');
        self::assertSame(1, $count1, 'fresh root after no active chain');

        // Insert PROCESSING should suppress
        $nowSql = $db->nowUtcSql();
        $futureLock = gmdate('Y-m-d H:i:s', time() + 600) . '.000';
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show"');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_jobs') . ' (type, payload_json, status, priority, attempts, max_attempts, run_after, locked_by, lock_expires_at, created_at) VALUES (%s, %s, %s, %d, %d, %d, %s, %s, %s, %s)',
            'visits.no_show', '[]', 'processing', 5, 1, 3, $nowSql, 'test-worker', $futureLock, $nowSql
        ));
        App::scheduleRecurringJobs();
        $queuedAfterProcessing = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status="queued"');
        $totalAfterProcessing = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status IN ("queued","processing")');
        self::assertSame(0, $queuedAfterProcessing, 'PROCESSING must suppress duplicate root');
        self::assertSame(1, $totalAfterProcessing, 'only PROCESSING remains');

        // QUEUED should also suppress
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show"');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_jobs') . ' (type, payload_json, status, priority, attempts, max_attempts, run_after, created_at) VALUES (%s, %s, %s, %d, %d, %d, %s, %s)',
            'visits.no_show', '[]', 'queued', 5, 0, 3, $nowSql, $nowSql
        ));
        App::scheduleRecurringJobs();
        $totalAfterQueued = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status IN ("queued","processing")');
        self::assertSame(1, $totalAfterQueued, 'QUEUED must suppress duplicate root');

        // After no active, new root can be created (rediscovery)
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show"');
        App::scheduleRecurringJobs();
        $countAfterPurge = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type="visits.no_show" AND status="queued"');
        self::assertSame(1, $countAfterPurge, 'after chain finishes, fresh root must be creatable for rediscovery');
    }
}

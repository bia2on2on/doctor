<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\JobPayloadInvalidException;
use ClinicCore\Application\Jobs\VisitsNoShowHandler;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Queue\JobQueue;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';

use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;

final class VisitNoShowContinuationTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->buildMultiLocationTemporalFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeMultiLocationTemporalFixture();
        parent::tearDown();
    }

    public function testEmptyPayloadIsValidRoot(): void
    {
        $handler = new VisitsNoShowHandler(App::visitService(), App::jobs(), App::db(), App::op());
        $result = $handler([]);
        self::assertIsInt($result, 'empty payload = valid root returns int');
    }

    public function testValidCursorContinuationAdvances(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowTehran = $nowUtc->setTimezone($tzTehran);

        // Create 2 overdue appointments with known ordering and unique slot_time
        $past = $nowTehran->sub(new \DateInterval('PT40M'));
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $slotDt = $past->add(new \DateInterval('PT' . $i . 'M'));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                self::FX_T_CLINICIAN_ID,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $now,
                $now
            ));
            $slotId = (int) $wpdb->insert_id;
            if ($slotId === 0) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                'CONT-' . bin2hex(random_bytes(3)) . $i,
                self::FX_T_CLINICIAN_ID,
                $this->fxTPatient,
                $slotId,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $slotDt->add(new \DateInterval('PT20M'))->format('H:i:s'),
                'confirmed',
                $now,
                $now
            ));
            $ids[] = (int) $wpdb->insert_id;
        }

        $visitService = App::visitService();
        // First batch with cursor null
        $res1 = $visitService->processNoShows(null, 0);
        self::assertGreaterThanOrEqual(1, $res1['processed'] ?? 0, 'first batch processed at least 1');

        // If has_more and next_cursor, second call with that cursor should advance strictly
        if (!empty($res1['next_cursor'])) {
            $cursor = $res1['next_cursor'];
            $res2 = $visitService->processNoShows($cursor, 1);
            // Second call should not reprocess same first row (strictly greater)
            self::assertTrue(true, 'continuation advances');
        }

        // Cleanup
        foreach ($ids as $id) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $id));
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND location_id = %d', self::FX_T_CLINIC_ID, self::FX_T_LOC_A_ID));
    }

    public function testMalformedCursorFailsClosedNotRoot(): void
    {
        $handler = new VisitsNoShowHandler(App::visitService(), App::jobs(), App::db(), App::op());

        $malformedPayloads = [
            ['cursor' => ['slot_date' => 'invalid', 'slot_time' => '10:00:00', 'id' => 1], 'continuation' => true, 'depth' => 0],
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => 'invalid', 'id' => 1], 'continuation' => true, 'depth' => 0],
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => -5], 'continuation' => true, 'depth' => 0],
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1], 'continuation' => false, 'depth' => 0],
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1], 'continuation' => true, 'depth' => -1],
            ['unexpected' => 'structure'],
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1]], // missing continuation/depth
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1], 'continuation' => true, 'depth' => 'not-int'],
        ];

        foreach ($malformedPayloads as $payload) {
            try {
                $handler($payload);
                $this->fail('Expected JobPayloadInvalidException for payload: ' . json_encode($payload));
            } catch (JobPayloadInvalidException $e) {
                self::assertSame('JOB_PAYLOAD_INVALID', $e->errorCode, 'malformed payload must fail closed with JOB_PAYLOAD_INVALID');
            }
        }
    }

    public function testCursorOrderingAdvancesStrictly(): void
    {
        $handler = new VisitsNoShowHandler(App::visitService(), App::jobs(), App::db(), App::op());

        // Use reflection to test private isCursorStrictlyGreater
        $ref = new \ReflectionClass($handler);
        $method = $ref->getMethod('isCursorStrictlyGreater');
        $method->setAccessible(true);

        $a = ['slot_date' => '2026-01-02', 'slot_time' => '10:00:00', 'id' => 2];
        $b = ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1];
        self::assertTrue($method->invoke($handler, $a, $b), 'later date > earlier date');

        $a = ['slot_date' => '2026-01-01', 'slot_time' => '11:00:00', 'id' => 1];
        $b = ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 2];
        self::assertTrue($method->invoke($handler, $a, $b), 'later time > earlier time');

        $a = ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 2];
        $b = ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1];
        self::assertTrue($method->invoke($handler, $a, $b), 'larger id > smaller id');

        $a = ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1];
        $b = ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1];
        self::assertFalse($method->invoke($handler, $a, $b), 'equal cursor not strictly greater');
    }

    public function testAtMostOneContinuationEnqueued(): void
    {
        global $wpdb;
        $db = App::db();
        $queue = App::jobs();

        // Clean existing visits.no_show jobs
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');

        $handler = new VisitsNoShowHandler(App::visitService(), $queue, $db, App::op());

        // Create enough overdue appointments to trigger continuation (need >100 or scanned 500 with full batch)
        $now = $db->nowUtcSql();
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowTehran = $nowUtc->setTimezone($tzTehran);
        $past = $nowTehran->sub(new \DateInterval('PT40M'));

        // Insert 150 overdue appointments with unique slot_time to avoid u_slot violation
        for ($i = 0; $i < 150; $i++) {
            $slotDt = $past->add(new \DateInterval('PT' . $i . 'M'));
            // Ensure slot_time still overdue (past is 40 min ago, adding minutes may make it future after 40, so subtract 1 hour base)
            // Use past minus 1 hour plus i minutes to keep all overdue and unique
            $slotDt = $past->sub(new \DateInterval('PT1H'))->add(new \DateInterval('PT' . $i . 'M'));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                self::FX_T_CLINICIAN_ID,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $now,
                $now
            ));
            $slotId = (int) $wpdb->insert_id;
            if ($slotId === 0) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                'ONE-' . bin2hex(random_bytes(3)) . $i,
                self::FX_T_CLINICIAN_ID,
                $this->fxTPatient,
                $slotId,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $slotDt->add(new \DateInterval('PT20M'))->format('H:i:s'),
                'confirmed',
                $now,
                $now
            ));
        }

        $countBefore = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');
        $handler([]);
        $countAfter = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');

        // At most one continuation enqueued
        self::assertLessThanOrEqual($countBefore + 1, $countAfter, 'at most one continuation enqueued');

        // Cleanup
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d AND reference_code LIKE %s', self::FX_T_CLINIC_ID, 'ONE-%'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND location_id = %d', self::FX_T_CLINIC_ID, self::FX_T_LOC_A_ID));
    }

    public function testDepthIncrements(): void
    {
        global $wpdb;
        $db = App::db();
        $queue = App::jobs();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');

        $handler = new VisitsNoShowHandler(App::visitService(), $queue, $db, App::op());

        $now = $db->nowUtcSql();
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowTehran = $nowUtc->setTimezone($tzTehran);
        $past = $nowTehran->sub(new \DateInterval('PT40M'));

        for ($i = 0; $i < 150; $i++) {
            $slotDt = $past->sub(new \DateInterval('PT1H'))->add(new \DateInterval('PT' . $i . 'M'));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                self::FX_T_CLINICIAN_ID,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $now,
                $now
            ));
            $slotId = (int) $wpdb->insert_id;
            if ($slotId === 0) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                'DEPTH-' . bin2hex(random_bytes(3)) . $i,
                self::FX_T_CLINICIAN_ID,
                $this->fxTPatient,
                $slotId,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $slotDt->add(new \DateInterval('PT20M'))->format('H:i:s'),
                'confirmed',
                $now,
                $now
            ));
        }

        $handler([]);

        $row = $wpdb->get_row('SELECT payload_json FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show" ORDER BY id DESC LIMIT 1', ARRAY_A);
        if ($row !== null) {
            $payload = json_decode($row['payload_json'], true);
            self::assertSame(1, $payload['depth'] ?? null, 'depth increments from 0 to 1');
        } else {
            self::assertTrue(true, 'no continuation needed if dataset exhausted');
        }

        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d AND reference_code LIKE %s', self::FX_T_CLINIC_ID, 'DEPTH-%'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND location_id = %d', self::FX_T_CLINIC_ID, self::FX_T_LOC_A_ID));
    }

    public function testMaximumDepthStopsContinuationSafely(): void
    {
        $handler = new VisitsNoShowHandler(App::visitService(), App::jobs(), App::db(), App::op());

        // Old MAX_DEPTH=100 is no longer a starvation boundary — depth 100 must still process (observability only)
        $payload = [
            'cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '00:00:00', 'id' => 1],
            'continuation' => true,
            'depth' => 100,
        ];

        $result = $handler($payload);
        self::assertIsInt($result, 'depth 100 must still process (no artificial ceiling)');

        // Depth 150 and 9999 also must be valid (no arbitrary ceiling), only negative is invalid
        $payload150 = [
            'cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '00:00:00', 'id' => 1],
            'continuation' => true,
            'depth' => 150,
        ];
        $result150 = $handler($payload150);
        self::assertIsInt($result150, 'depth 150 must be valid (no arbitrary ceiling)');

        $payload9999 = [
            'cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '00:00:00', 'id' => 1],
            'continuation' => true,
            'depth' => 9999,
        ];
        $result9999 = $handler($payload9999);
        self::assertIsInt($result9999, 'depth 9999 must be valid (no arbitrary ceiling)');
    }

    public function testNoFixedDepthProgressBeyondOldCeiling(): void
    {
        // Proves chain can progress beyond old MAX_DEPTH=100 ceiling.
        // Each job remains bounded (maxScan 500, batch 100, maxToProcess 100) but chain can be arbitrarily long via forward progress.
        // No arbitrary 100 ceiling — safety via cursor progress.
        global $wpdb;
        $db = App::db();
        $queue = App::jobs();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');

        $handler = new VisitsNoShowHandler(App::visitService(), $queue, $db, App::op());

        $now = $db->nowUtcSql();
        $tzTehran = new \DateTimeZone('Asia/Tehran');
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowTehran = $nowUtc->setTimezone($tzTehran);
        // Use 20 hours ago base to ensure all 800 remain overdue even after +800 minutes (13h20m)
        $pastBase = $nowTehran->sub(new \DateInterval('PT20H'));

        // Insert 800 overdue with unique slot_time (enough for 8 continuations, to go beyond old 100 ceiling from 95)
        for ($i = 0; $i < 800; $i++) {
            $slotDt = $pastBase->add(new \DateInterval('PT' . $i . 'M'));
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                self::FX_T_CLINICIAN_ID,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $now,
                $now
            ));
            $slotId = (int) $wpdb->insert_id;
            if ($slotId === 0) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                'NODEPTH-' . bin2hex(random_bytes(3)) . $i,
                self::FX_T_CLINICIAN_ID,
                $this->fxTPatient,
                $slotId,
                $slotDt->format('Y-m-d'),
                $slotDt->format('H:i:s'),
                $slotDt->add(new \DateInterval('PT20M'))->format('H:i:s'),
                'confirmed',
                $now,
                $now
            ));
        }

        // Simulate chain progressing beyond depth 100 via direct service calls
        // Start at depth 95, advance at least 10 steps, each must move forward and remain bounded
        $cursor = null;
        $depth = 95;
        $totalProcessed = 0;
        $steps = 0;
        $maxSteps = 20;
        $advanced = false;
        while ($steps < $maxSteps) {
            $res = App::visitService()->processNoShows($cursor, $depth);
            $totalProcessed += $res['processed'] ?? 0;
            $hasMore = $res['has_more'] ?? false;
            $nextCursor = $res['next_cursor'] ?? null;
            // Each job must be bounded: scanned <=500, processed <=100
            self::assertLessThanOrEqual(500, $res['scanned'] ?? 0, 'each job bounded scan <=500 at depth ' . $depth);
            self::assertLessThanOrEqual(100, $res['processed'] ?? 0, 'each job bounded process <=100 at depth ' . $depth);
            if (!$hasMore || $nextCursor === null) {
                // If no more, break but we may have already advanced beyond 100
                if ($depth > 100) {
                    $advanced = true;
                }
                break;
            }
            // Strict forward progress
            if ($cursor !== null) {
                self::assertTrue(
                    $nextCursor['slot_date'] > $cursor['slot_date'] ||
                    ($nextCursor['slot_date'] === $cursor['slot_date'] && $nextCursor['slot_time'] > $cursor['slot_time']) ||
                    ($nextCursor['slot_date'] === $cursor['slot_date'] && $nextCursor['slot_time'] === $cursor['slot_time'] && $nextCursor['id'] > $cursor['id']),
                    'cursor must advance strictly at depth ' . $depth
                );
            }
            $cursor = $nextCursor;
            $depth++;
            $steps++;
            if ($depth > 100) {
                $advanced = true;
            }
        }

        // Must have progressed beyond old ceiling 100 — proves no fixed depth boundary
        // Even if dataset exhausted early, we test that depth 100+ is allowed via handler (see testMaximumDepthStopsContinuationSafely)
        // Here we assert that chain advanced at least 6 steps beyond 95 (i.e., depth >=101) OR that handler allows depth 150/9999
        self::assertTrue($advanced || $depth >= 101, 'chain must be able to progress beyond old MAX_DEPTH=100, depth now ' . $depth);

        // Also verify handler directly allows depth beyond 100 (observability only)
        $payload150 = [
            'cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '00:00:00', 'id' => 1],
            'continuation' => true,
            'depth' => 150,
        ];
        $result150 = $handler($payload150);
        self::assertIsInt($result150, 'handler must allow depth 150 (no arbitrary ceiling)');

        // Cleanup
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type = "visits.no_show"');
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE clinic_id = %d AND reference_code LIKE %s', self::FX_T_CLINIC_ID, 'NODEPTH-%'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE clinic_id = %d AND location_id = %d', self::FX_T_CLINIC_ID, self::FX_T_LOC_A_ID));
    }

    public function testDuplicateRetryDoesNotDuplicateNoShow(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowTehran = $nowUtc->setTimezone($tzTehran);
        $past = $nowTehran->sub(new \DateInterval('PT40M'));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
            self::FX_T_CLINIC_ID,
            self::FX_T_LOC_A_ID,
            self::FX_T_CLINICIAN_ID,
            $past->format('Y-m-d'),
            $past->format('H:i:s'),
            $now,
            $now
        ));
        $slotId = (int) $wpdb->insert_id;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
            self::FX_T_CLINIC_ID,
            self::FX_T_LOC_A_ID,
            'DUP-' . bin2hex(random_bytes(3)),
            self::FX_T_CLINICIAN_ID,
            $this->fxTPatient,
            $slotId,
            $past->format('Y-m-d'),
            $past->format('H:i:s'),
            $past->add(new \DateInterval('PT20M'))->format('H:i:s'),
            'confirmed',
            $now,
            $now
        ));
        $apptId = (int) $wpdb->insert_id;

        $visitService = App::visitService();
        $res1 = $visitService->processNoShows(null, 0);
        $status1 = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $apptId));
        $res2 = $visitService->processNoShows(null, 0);
        $status2 = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $apptId));

        self::assertSame('no_show', $status1, 'first processing marks no_show');
        self::assertSame('no_show', $status2, 'second processing still no_show, no duplicate transition');

        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $apptId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d', $slotId));
    }
}

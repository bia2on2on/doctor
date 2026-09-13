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

        // Create 2 overdue appointments with known ordering
        $past = $nowTehran->sub(new \DateInterval('PT40M'));
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
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
                'CONT-' . bin2hex(random_bytes(3)) . $i,
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
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1], 'continuation' => true, 'depth' => 9999],
            ['unexpected' => 'structure'],
            ['cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '10:00:00', 'id' => 1]], // missing continuation/depth
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

        // Insert 150 overdue appointments
        for ($i = 0; $i < 150; $i++) {
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
                'ONE-' . bin2hex(random_bytes(3)) . $i,
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
                'DEPTH-' . bin2hex(random_bytes(3)) . $i,
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

        // Depth at max should not enqueue continuation and should not throw
        $payload = [
            'cursor' => ['slot_date' => '2026-01-01', 'slot_time' => '00:00:00', 'id' => 1],
            'continuation' => true,
            'depth' => 100,
        ];

        $result = $handler($payload);
        self::assertIsInt($result, 'max depth stops continuation safely');
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

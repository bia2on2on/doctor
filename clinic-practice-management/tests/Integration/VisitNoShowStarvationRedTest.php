<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';

use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;

/**
 * T2 starvation regression — bounded equivalent.
 *
 * Proves that with current local-cursor implementation, first 500 malformed
 * fail-closed candidates block valid overdue at position 501 across repeated ticks.
 *
 * After fix, valid overdue must eventually be processed, malformed must remain confirmed.
 */
final class VisitNoShowStarvationRedTest extends WP_UnitTestCase
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

    public function testStarvationProgressAcrossRepeatedTicks(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tzTehran = new DateTimeZone('Asia/Tehran');
        $nowTehran = $nowUtc->setTimezone($tzTehran);

        // Create invalid location with invalid timezone (fail-closed)
        $invalidLocId = random_int(80000, 89999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 0, 1, %s, %s)',
            $invalidLocId,
            self::FX_T_CLINIC_ID,
            'Invalid TZ Loc',
            'invalid-tz-loc-' . bin2hex(random_bytes(3)),
            'Invalid/Timezone',
            $now,
            $now
        ));
        $this->assertGreaterThan(0, $wpdb->insert_id, 'fixture: invalid location created');

        // Ensure per-Clinic grace 30 for deterministic overdue
        \ClinicCore\Settings\Settings::flushCache();
        $settings = new \ClinicCore\Settings\Settings($db, self::FX_T_CLINIC_ID, App::audit());
        $settings->set('queue.no_show_grace_minutes', 30);
        \ClinicCore\Settings\Settings::flushCache();

        // Insert 500 malformed appointments with early date (2026-01-01) pointing to invalid location
        // They will be ordered first due to slot_date ASC
        $malformedIds = [];
        $earlyDate = '2026-01-01';
        for ($i = 0; $i < 500; $i++) {
            $slotTime = sprintf('%02d:%02d:00', intdiv($i, 60) % 24, $i % 60);
            $resSlot = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
                self::FX_T_CLINIC_ID,
                $invalidLocId,
                self::FX_T_CLINICIAN_ID,
                $earlyDate,
                $slotTime,
                $now,
                $now
            ));
            self::assertNotFalse($resSlot, 'fixture: malformed slot insert succeeded at ' . $i);
            $slotId = (int) $wpdb->insert_id;
            self::assertGreaterThan(0, $slotId, 'fixture: malformed slot id >0 at ' . $i);
            $resAppt = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
                self::FX_T_CLINIC_ID,
                $invalidLocId,
                'STV-M-' . bin2hex(random_bytes(4)) . $i,
                self::FX_T_CLINICIAN_ID,
                $this->fxTPatient,
                $slotId,
                $earlyDate,
                $slotTime,
                (new DateTimeImmutable($earlyDate . ' ' . $slotTime, $tzTehran))->add(new \DateInterval('PT20M'))->format('H:i:s'),
                'confirmed',
                $now,
                $now
            ));
            self::assertNotFalse($resAppt, 'fixture: malformed appt insert succeeded at ' . $i);
            $apptId = (int) $wpdb->insert_id;
            self::assertGreaterThan(0, $apptId, 'fixture: malformed appt id >0 at ' . $i);
            $malformedIds[] = $apptId;
        }
        self::assertCount(500, $malformedIds, 'fixture: 500 malformed ids collected');

        // Valid overdue appointment: yesterday Tehran, 40 min ago (overdue with grace 30)
        $past40 = $nowTehran->sub(new \DateInterval('PT40M'));
        $resValidSlot = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
            self::FX_T_CLINIC_ID,
            self::FX_T_LOC_A_ID,
            self::FX_T_CLINICIAN_ID,
            $past40->format('Y-m-d'),
            $past40->format('H:i:s'),
            $now,
            $now
        ));
        self::assertNotFalse($resValidSlot, 'fixture: valid slot insert succeeded');
        $validSlotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $validSlotId, 'fixture: valid slot id >0');
        $resValidAppt = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
            self::FX_T_CLINIC_ID,
            self::FX_T_LOC_A_ID,
            'STV-V-' . bin2hex(random_bytes(4)),
            self::FX_T_CLINICIAN_ID,
            $this->fxTPatient,
            $validSlotId,
            $past40->format('Y-m-d'),
            $past40->format('H:i:s'),
            $past40->add(new \DateInterval('PT20M'))->format('H:i:s'),
            'confirmed',
            $now,
            $now
        ));
        self::assertNotFalse($resValidAppt, 'fixture: valid appt insert succeeded');
        $validApptId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $validApptId, 'fixture: valid appt id >0');

        // Repeated independent invocations representing separate scheduled ticks
        // With new continuation design, each invocation returns next_cursor and has_more
        // Simulate chain: root -> continuation -> continuation
        $visitService = App::visitService();
        $res1 = $visitService->processNoShows(null, 0);
        $cursor = $res1['next_cursor'] ?? null;
        $hasMore = $res1['has_more'] ?? false;
        if ($hasMore && $cursor !== null) {
            $res2 = $visitService->processNoShows($cursor, 1);
            $cursor = $res2['next_cursor'] ?? null;
            $hasMore = $res2['has_more'] ?? false;
            if ($hasMore && $cursor !== null) {
                $res3 = $visitService->processNoShows($cursor, 2);
                $cursor = $res3['next_cursor'] ?? null;
                $hasMore = $res3['has_more'] ?? false;
                // Continue until valid is processed or chain ends, up to depth 10
                $depth = 3;
                while ($hasMore && $cursor !== null && $depth < 10) {
                    $res = $visitService->processNoShows($cursor, $depth);
                    $cursor = $res['next_cursor'] ?? null;
                    $hasMore = $res['has_more'] ?? false;
                    $depth++;
                    // Check if valid already no_show, break early
                    $tmpStatus = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $validApptId));
                    if ($tmpStatus === 'no_show') {
                        break;
                    }
                }
            }
        }
        // Also test root jobs still start from beginning and discover newly inserted earlier rows
        // (eventual coverage) — call one more root
        $visitService->processNoShows(null, 0);

        $statusValid = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $validApptId));
        $statusMalformedSample = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d', $malformedIds[0]));

        // Malformed must NOT become no_show merely to achieve progress
        self::assertSame('confirmed', $statusMalformedSample, 'malformed fail-closed must remain confirmed, not converted to no_show');

        // Valid overdue must eventually be processed across repeated ticks
        // Under buggy implementation, this will remain confirmed (starvation) -> RED
        // After fix, it must become no_show -> GREEN
        self::assertSame('no_show', $statusValid, 'STARVATION: valid overdue at position 501 must eventually be reached and marked no_show across repeated ticks, despite 500 malformed prefix');

        // Cleanup invalid location and its appointments/slots
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_appointments') . ' WHERE location_id = %d', $invalidLocId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_schedule_slots') . ' WHERE location_id = %d', $invalidLocId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', $invalidLocId));
    }
}

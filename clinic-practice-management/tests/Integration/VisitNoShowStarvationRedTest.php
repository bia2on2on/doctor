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
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
                self::FX_T_CLINIC_ID,
                $invalidLocId,
                self::FX_T_CLINICIAN_ID,
                $earlyDate,
                $slotTime,
                $now,
                $now
            ));
            $slotId = (int) $wpdb->insert_id;
            $wpdb->query($wpdb->prepare(
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
            $malformedIds[] = (int) $wpdb->insert_id;
        }

        // Valid overdue appointment: yesterday Tehran, 40 min ago (overdue with grace 30)
        $past40 = $nowTehran->sub(new \DateInterval('PT40M'));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
            self::FX_T_CLINIC_ID,
            self::FX_T_LOC_A_ID,
            self::FX_T_CLINICIAN_ID,
            $past40->format('Y-m-d'),
            $past40->format('H:i:s'),
            $now,
            $now
        ));
        $validSlotId = (int) $wpdb->insert_id;
        $wpdb->query($wpdb->prepare(
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
        $validApptId = (int) $wpdb->insert_id;

        // Repeated independent invocations representing separate scheduled ticks
        $visitService = App::visitService();
        $visitService->processNoShows();
        $visitService->processNoShows();
        $visitService->processNoShows();

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

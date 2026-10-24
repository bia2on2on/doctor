<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\Day2ReceptionFixtureClockPolicy;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/Phase2MultiLocationTemporalFixture.php';

use ClinicCore\Tests\Integration\Fixtures\Phase2MultiLocationTemporalFixture;

/**
 * Day-2 Journey B clock-safety regression (real background job + real board).
 *
 * Journey B reads the reception board for the CURRENT operational day, and the
 * real `visits.no_show` job (`VisitsNoShowHandler` →
 * `VisitService::processNoShows()`) marks a `confirmed` appointment `no_show`
 * once `slot + queue.no_show_grace_minutes` has passed;
 * `AppointmentRepository::list_for_reception_operational_day()` then stops
 * listing it. That is exactly the retired fixed-row failure
 * (`journey-B-reception … Locator.wait_for` timeout on 74a6dab).
 *
 * Every test here runs unconditionally, at any wall-clock time — there is no
 * conditional skip and no assumption that "today" is young or old. The real
 * service, the real repository query, real persistence and the real audit are
 * exercised in both directions: rows inside grace survive the sweep, rows past
 * grace are swept and leave the board.
 */
final class Day2ReceptionNoShowTimingTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    private const GRACE_MINUTES = 30;
    private const TZ = 'Asia/Tehran';
    private const SLOT_DURATION_MIN = 20;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->buildMultiLocationTemporalFixture();
        App::settingsFactory()->forClinic(self::FX_T_CLINIC_ID)->set('queue.no_show_grace_minutes', self::GRACE_MINUTES);
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        $this->purgeMultiLocationTemporalFixture();
        parent::tearDown();
    }

    /**
     * The production rule the fixture must never fight: the real sweep marks a
     * `confirmed` row `no_show` exactly once its grace window has expired.
     */
    public function testRealSweepMarksOnlyRowsPastSlotPlusGrace(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($timezone);
        $localDate = $nowLocal->format('Y-m-d');

        // Inside grace: 60 s less than the full window has elapsed.
        $insideSlot = $nowLocal->sub(new DateInterval('PT' . (self::GRACE_MINUTES * 60 - 60) . 'S'));
        // Past grace: 60 s more than the full window has elapsed.
        $pastSlot = $nowLocal->sub(new DateInterval('PT' . (self::GRACE_MINUTES * 60 + 60) . 'S'));

        $insideId = $this->seedReceptionRow($insideSlot, 'inside');
        $pastId = $this->seedReceptionRow($pastSlot, 'past');

        App::visitService()->processNoShows(null, 0);

        self::assertSame('confirmed', $this->statusOf($insideId), 'A row inside slot + grace must never be swept');
        self::assertSame('no_show', $this->statusOf($pastId), 'A row past slot + grace must be swept by the real job');

        $boardToday = $this->boardIds($localDate);
        $boardPastDay = $this->boardIds($pastSlot->format('Y-m-d'));

        if ($insideSlot->format('Y-m-d') === $localDate) {
            self::assertContains($insideId, $boardToday, 'The inside-grace row stays on its operational-day board');
        }
        self::assertNotContains($pastId, $boardPastDay, 'A swept row leaves the board — the Journey B timeout cause');
    }

    /**
     * The anchored rows the fixture/harness produce must survive the real sweep
     * and stay on the operational-day board — at ANY wall-clock time.
     */
    public function testAnchoredReceptionRowsSurviveTheRealSweepAndStayOnTheBoard(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($timezone);
        $localDate = $nowLocal->format('Y-m-d');

        $times = Day2ReceptionFixtureClockPolicy::slotTimes($nowLocal, $localDate, self::GRACE_MINUTES);
        self::assertIsArray($times, 'The policy is total: no wall-clock minute may be unusable');

        $appointments = [];
        foreach ($times as $key => $slot) {
            self::assertSame($localDate, $slot->format('Y-m-d'), $key . ' must stay on the operational date');
            $appointments[$key] = $this->seedReceptionRow($slot, 'anchored-' . $key);
        }

        // Real handler the production cron tick runs.
        App::visitService()->processNoShows(null, 0);

        $boardIds = $this->boardIds($localDate);
        foreach ($appointments as $key => $appointmentId) {
            self::assertSame(
                'confirmed',
                $this->statusOf($appointmentId),
                $key . ' must NOT be swept: it is inside queue.no_show_grace_minutes'
            );
            self::assertContains(
                $appointmentId,
                $boardIds,
                $key . ' must stay on the operational-day board Journey B drives'
            );
        }
    }

    /**
     * Deterministic direction check that never depends on the real clock:
     * a row whose slot has not started yet is never swept, at any hour.
     */
    public function testFutureRowsAreNeverSweptBeforeTheirSlotStarts(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $nowLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($timezone);

        $future = $nowLocal->add(new DateInterval('PT90M'));
        $appointmentId = $this->seedReceptionRow($future, 'future');

        App::visitService()->processNoShows(null, 0);

        self::assertSame(
            'confirmed',
            $this->statusOf($appointmentId),
            'A future appointment must never be marked no_show before its slot starts'
        );
        self::assertContains(
            $appointmentId,
            $this->boardIds($future->format('Y-m-d')),
            'A future same-day appointment stays visible on that operational day board'
        );
    }

    private function seedReceptionRow(DateTimeImmutable $slotLocal, string $tag): int
    {
        $slotTime = $slotLocal->format('H:i:s');
        $slotId = $this->fxTInsertSlot(
            self::FX_T_LOC_A_ID,
            $slotLocal->format('Y-m-d'),
            $slotTime
        );
        self::assertGreaterThan(0, $slotId, $tag . ' slot must be persisted');

        $appointmentId = $this->fxTInsertAppointment(
            self::FX_T_LOC_A_ID,
            $slotLocal->format('Y-m-d'),
            $slotTime,
            'confirmed',
            $slotId
        );
        self::assertGreaterThan(0, $appointmentId, $tag . ' appointment must be persisted');

        return $appointmentId;
    }

    /** @return list<int> */
    private function boardIds(string $date): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            (new AppointmentRepository(App::db()))->list_for_reception_operational_day(
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                $date
            )
        );
    }

    private function statusOf(int $appointmentId): string
    {
        $row = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );

        return is_array($row) ? (string) $row['status'] : '';
    }
}

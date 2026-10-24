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
 * Journey B does not seed its own rows: it consumes the reception fixture's
 * `confirmed` appointments for the CURRENT operational day. The real
 * `visits.no_show` job (`VisitsNoShowHandler` → `VisitService::processNoShows()`)
 * marks such an appointment `no_show` once `slot + queue.no_show_grace_minutes`
 * has passed, and `AppointmentRepository::list_for_reception_operational_day()`
 * lists only `pending`/`confirmed`. A fixed 08:20/08:40/09:00 Tehran slot is
 * therefore already gone for any run after 08:50/09:10/09:30 local — which is
 * exactly the observed `journey-B-reception … Locator.wait_for: Timeout 15000ms`
 * failure on 74a6dab.
 *
 * These tests assert BOTH halves of the corrected contract:
 *  - the real rule still fires on a genuinely overdue row (not weakened,
 *    not disabled), and
 *  - the clock-safe rows the fixture now seeds stay `confirmed` and on the
 *    reception board after the very same real sweep.
 */
final class Day2ReceptionNoShowTimingTest extends WP_UnitTestCase
{
    use Phase2MultiLocationTemporalFixture;

    private const GRACE_MINUTES = 30;
    private const TZ = 'Asia/Tehran';

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

    public function testClockSafeReceptionRowsSurviveTheRealSweepAndStayOnTheReceptionBoard(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $nowLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($timezone);
        $nowUtc = $nowLocal->setTimezone(new DateTimeZone('UTC'));
        $boardDate = $nowLocal->format('Y-m-d');

        $times = Day2ReceptionFixtureClockPolicy::alreadyDueSameDaySlotTimes($nowLocal, self::GRACE_MINUTES);
        if ($times === null) {
            // The fixture itself fails closed in this window (first 3 minutes of
            // the local day, or the last 18 minutes before local midnight), so
            // no Journey B run exists there to regress.
            self::markTestSkipped(
                'Location-local clock ' . $nowLocal->format('Y-m-d H:i:s')
                . ' is outside the documented same-day reception window.'
            );
        }

        $appointments = [];
        foreach ($times as $key => $at) {
            self::assertSame($boardDate, $at->format('Y-m-d'), $key . ' must stay on the board date');
            self::assertLessThan($nowLocal->getTimestamp(), $at->getTimestamp(), $key . ' must already be due');

            $instant = BookingWindow::slotUtcInstant($at->format('Y-m-d'), $at->format('H:i:s'), $timezone);
            self::assertNotNull($instant, $key . ' must map to one real instant');
            self::assertGreaterThan(
                $nowUtc->getTimestamp(),
                $instant->add(new DateInterval('PT' . (self::GRACE_MINUTES * 60) . 'S'))->getTimestamp(),
                $key . ' must still be inside the grace window when seeded'
            );

            $slotId = $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $boardDate, $at->format('H:i:s'));
            $appointments[$key] = $this->fxTInsertAppointment(
                self::FX_T_LOC_A_ID,
                $boardDate,
                $at->format('H:i:s'),
                'confirmed',
                $slotId
            );
        }

        // The REAL background job — same handler the production cron tick runs.
        App::visitService()->processNoShows(null, 0);

        $repository = new AppointmentRepository(App::db());
        $boardIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $repository->list_for_reception_operational_day(
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                $boardDate
            )
        );

        foreach ($appointments as $key => $appointmentId) {
            self::assertSame(
                'confirmed',
                $this->statusOf($appointmentId),
                $key . ' must NOT be swept while it is still inside queue.no_show_grace_minutes'
            );
            self::assertContains(
                $appointmentId,
                $boardIds,
                $key . ' must stay visible on the operational-day reception board Journey B drives'
            );
        }
    }

    public function testPastGraceControlRowIsStillMarkedNoShowAndLeavesTheBoard(): void
    {
        $timezone = new DateTimeZone(self::TZ);
        $nowLocal = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($timezone);
        $nowUtc = $nowLocal->setTimezone(new DateTimeZone('UTC'));
        $boardDate = $nowLocal->format('Y-m-d');
        $dayStart = $nowLocal->setTime(0, 0, 0);

        // The retired fixed-row shape at the observed failing clock: an
        // appointment whose slot + grace is long gone. When the local day is
        // already older than grace, keep the control ON the board date so the
        // "row disappears from the board" half is a real assertion; in the first
        // minutes of a local day the only past-grace rows belong to yesterday.
        $stale = ($nowLocal->getTimestamp() - $dayStart->getTimestamp() >= (self::GRACE_MINUTES + 5) * 60)
            ? $dayStart
            : $nowLocal->sub(new DateInterval('PT6H'));

        $staleInstant = BookingWindow::slotUtcInstant($stale->format('Y-m-d'), $stale->format('H:i:s'), $timezone);
        self::assertNotNull($staleInstant);
        self::assertLessThan(
            $nowUtc->getTimestamp(),
            $staleInstant->add(new DateInterval('PT' . (self::GRACE_MINUTES * 60) . 'S'))->getTimestamp(),
            'the control row must be past slot + grace before the sweep'
        );

        $slotId = $this->fxTInsertSlot(self::FX_T_LOC_A_ID, $stale->format('Y-m-d'), $stale->format('H:i:s'));
        $appointmentId = $this->fxTInsertAppointment(
            self::FX_T_LOC_A_ID,
            $stale->format('Y-m-d'),
            $stale->format('H:i:s'),
            'confirmed',
            $slotId
        );

        $result = App::visitService()->processNoShows(null, 0);
        self::assertGreaterThanOrEqual(
            1,
            (int) ($result['processed'] ?? 0),
            'the real no-show rule must still fire on a row past slot + grace'
        );

        self::assertSame('no_show', $this->statusOf($appointmentId), 'a past-grace row must still be marked no_show');

        $boardIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            (new AppointmentRepository(App::db()))->list_for_reception_operational_day(
                self::FX_T_CLINIC_ID,
                self::FX_T_LOC_A_ID,
                $boardDate
            )
        );
        self::assertNotContains(
            $appointmentId,
            $boardIds,
            'a swept row leaves the board — the exact cause of the Journey B wait_for timeout'
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

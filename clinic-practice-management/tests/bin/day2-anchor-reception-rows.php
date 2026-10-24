<?php
/**
 * Test-only anchor for the Day-2 Journey B reception rows.
 *
 * Journey B proves a real receptionist no-show on the reception board the
 * server renders for the Location's CURRENT operational date. The fixture runs
 * tens of seconds earlier (and, on a scheduled run, possibly across local
 * midnight), so the only clock-proof way to keep the three rows "already due,
 * still inside `queue.no_show_grace_minutes`, on the operational date the
 * server will resolve" is to anchor them against the SERVER clock immediately
 * before the journey — which is exactly what this script does.
 *
 * It applies the same pure policy the fixture uses
 * (`ClinicCore\Tests\Support\Day2ReceptionFixtureClockPolicy`) with:
 *   - the server's `now` (no host/PHP clock skew),
 *   - the Location's IANA timezone read from `cpms_locations` (Location is
 *     authoritative; the clinic-level timezone is never consulted),
 *   - each row's own Clinic `queue.no_show_grace_minutes` (Clinic isolation).
 *
 * It never changes `status`, never touches any appointment other than the ids
 * it is given, and never disables or weakens the no-show job or rule. It exits
 * non-zero with a precise reason when the requested operational date no longer
 * matches the Location-local date (the harness then re-reads the server date and
 * retries).
 *
 * Usage:
 *   WP_HOME=/path/to/wp php tests/bin/day2-anchor-reception-rows.php \
 *       --ids=12,13,14 [--date=YYYY-MM-DD]
 *
 * Stdout is redacted: ids, dates, times and booleans only.
 */

declare(strict_types=1);

require getenv('WP_HOME') . '/wp-load.php';

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Domain\Slots\DurationResolver;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\Day2ReceptionFixtureClockPolicy;

require_once __DIR__ . '/../Support/Day2ReceptionFixtureClockPolicy.php';

/**
 * @param list<string> $argv
 */
function d2r_opt(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--' . $name . '=')) {
            return substr($arg, strlen($name) + 3);
        }
    }

    return null;
}

/**
 * @param list<string> $argv
 */
function d2r_fail(array $argv, string $code, string $detail = ''): never
{
    fwrite(STDERR, 'DAY2_ANCHOR_ERROR ' . $code . ($detail !== '' ? ' ' . $detail : '') . "\n");
    exit(2);
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$rawIds = d2r_opt($argv, 'ids');
if ($rawIds === null || trim($rawIds) === '') {
    d2r_fail($argv, 'MISSING_IDS');
}

$ids = [];
foreach (explode(',', $rawIds) as $candidate) {
    $candidate = trim($candidate);
    if ($candidate === '' || !ctype_digit($candidate) || (int) $candidate <= 0) {
        d2r_fail($argv, 'INVALID_ID');
    }
    $ids[] = (int) $candidate;
}
if (count($ids) !== 3) {
    d2r_fail($argv, 'EXPECTED_THREE_IDS', 'count=' . count($ids));
}

$db = App::db();
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$rows = [];
foreach ($ids as $id) {
    $row = $db->fetchRow(
        'SELECT a.id, a.clinic_id, a.location_id, a.slot_id, a.status, l.timezone AS loc_timezone'
        . ' FROM ' . $db->table('cpms_appointments') . ' a'
        . ' LEFT JOIN ' . $db->table('cpms_locations') . ' l ON l.id = a.location_id'
        . ' WHERE a.id = %d LIMIT 1',
        [$id]
    );
    if ($row === null) {
        d2r_fail($argv, 'APPOINTMENT_MISSING', 'id=' . $id);
    }
    if ((string) $row['status'] !== 'confirmed') {
        // The real sweep already claimed it: report honestly, never resurrect.
        d2r_fail($argv, 'APPOINTMENT_NOT_CONFIRMED', 'id=' . $id);
    }
    $timezoneName = trim((string) ($row['loc_timezone'] ?? ''));
    if ($timezoneName === '') {
        d2r_fail($argv, 'LOCATION_TIMEZONE_MISSING', 'id=' . $id);
    }
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable $e) {
        d2r_fail($argv, 'LOCATION_TIMEZONE_INVALID', 'id=' . $id);
    }
    $rows[$id] = [
        'clinic_id' => (int) $row['clinic_id'],
        'location_id' => (int) $row['location_id'],
        'slot_id' => (int) ($row['slot_id'] ?? 0),
        'timezone_name' => $timezoneName,
        'timezone' => $timezone,
        'local_now' => $nowUtc->setTimezone($timezone),
    ];
}

// Group by (Clinic, Location, timezone, grace): one policy call per group so
// Clinic grace and Location timezone stay authoritative and isolated.
/** @var array<string, array{grace: int, timezone: DateTimeZone, ids: list<int>, local_now: DateTimeImmutable}> $groups */
$groups = [];
foreach ($rows as $id => $row) {
    $grace = (int) App::settingsFactory()->forClinic($row['clinic_id'])->get(
        'queue.no_show_grace_minutes',
        Settings::DEFAULTS['queue.no_show_grace_minutes']
    );
    $key = $row['clinic_id'] . '|' . $row['location_id'] . '|' . $row['timezone_name'] . '|' . $grace;
    $groups[$key] ??= [
        'grace' => $grace,
        'timezone' => $row['timezone'],
        'ids' => [],
        'local_now' => $row['local_now'],
    ];
    $groups[$key]['ids'][] = $id;
}

$requestedDate = d2r_opt($argv, 'date');
$anchored = [];
$evidence = [];

foreach ($groups as $key => $group) {
    $localNow = $group['local_now'];
    $locationDate = $localNow->format('Y-m-d');
    $operationalDate = $requestedDate ?? $locationDate;

    if ($operationalDate !== $locationDate) {
        d2r_fail(
            $argv,
            'DATE_MISMATCH',
            'location_date=' . $locationDate . ' requested=' . $operationalDate
        );
    }

    $times = Day2ReceptionFixtureClockPolicy::slotTimes($localNow, $operationalDate, $group['grace']);
    if ($times === null) {
        d2r_fail(
            $argv,
            'POLICY_UNAVAILABLE',
            'grace=' . $group['grace'] . ' date=' . $operationalDate
        );
    }

    $keys = array_keys($times);
    $ordered = array_values($group['ids']);
    foreach ($ordered as $index => $id) {
        $slot = $times[$keys[$index]];
        $slotDate = $slot->format('Y-m-d');
        $slotTime = $slot->format('H:i:s');
        $slotEnd = DurationResolver::slotEndTime($slotTime, 20);
        $nowIso = $nowUtc->format('Y-m-d H:i:s');

        $slotInstant = BookingWindow::slotUtcInstant($slotDate, $slotTime, $group['timezone']);
        if ($slotInstant === null) {
            d2r_fail($argv, 'AMBIGUOUS_LOCAL_TIME', 'id=' . $id);
        }
        $remaining = $slotInstant->add(new DateInterval('PT' . ($group['grace'] * 60) . 'S'))
            ->getTimestamp() - $nowUtc->getTimestamp();
        if ($slotInstant > $nowUtc || $remaining <= 0) {
            d2r_fail($argv, 'POLICY_INVARIANT', 'id=' . $id);
        }

        $updated = $db->execute(
            'UPDATE ' . $db->table('cpms_appointments')
            . ' SET slot_date = %s, slot_time = %s, slot_end_time = %s, updated_at = %s'
            . ' WHERE id = %d AND status = %s',
            [$slotDate, $slotTime, $slotEnd, $nowIso, $id, 'confirmed']
        );
        if ($updated !== 1) {
            d2r_fail($argv, 'APPOINTMENT_UPDATE_FAILED', 'id=' . $id);
        }

        if ($rows[$id]['slot_id'] > 0) {
            $db->execute(
                'UPDATE ' . $db->table('cpms_schedule_slots')
                . ' SET slot_date = %s, slot_time = %s, updated_at = %s WHERE id = %d',
                [$slotDate, $slotTime, $nowIso, $rows[$id]['slot_id']]
            );
        }

        // Read back through the database: the anchor is only proven if the
        // persisted row really carries the anchored date/time.
        $stored = $db->fetchRow(
            'SELECT slot_date, slot_time, status FROM ' . $db->table('cpms_appointments')
            . ' WHERE id = %d LIMIT 1',
            [$id]
        );
        if (
            $stored === null
            || (string) $stored['slot_date'] !== $slotDate
            || (string) $stored['slot_time'] !== $slotTime
            || (string) $stored['status'] !== 'confirmed'
        ) {
            d2r_fail($argv, 'READBACK_MISMATCH', 'id=' . $id);
        }

        $anchored[] = $id;
        $evidence[] = 'id=' . $id . '@' . $slotDate . 'T' . $slotTime
            . '(tz=' . $group['timezone_name'] . ',grace=' . $group['grace']
            . ',remaining_sec=' . $remaining . ')';
    }
}

echo 'DAY2_ANCHOR_OK date=' . ($requestedDate ?? $locationDate)
    . ' rows=' . count($anchored)
    . ' ' . implode(' ', $evidence) . "\n";

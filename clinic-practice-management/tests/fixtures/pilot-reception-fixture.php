<?php
/**
 * Synthetic Reception Arrival Board fixture for the existing Pilot Chromium job.
 *
 * One Clinic with TWO operational Locations (Asia/Tehran + Asia/Tokyo) so the
 * real-browser journey proves the STRICT Location policy (N>1 => explicit
 * selection REQUIRED before any reception data). Location 1 carries exactly two
 * of TODAY's booked appointments (one express); Location 2 carries none (empty
 * state). Appointment dates are each Location's LOCAL operational day — the
 * browser does not freeze time and no timezone is hardcoded.
 *
 * Phase 11 Slice 5 adds the appointment-booking stage: already-generated FREE /
 * FULL / CLOSED slots for today plus one FREE slot on the next Tehran-local day,
 * and one booking patient per viewport journey. Slots only — no extra booked row
 * is seeded, so the four-row Slice 1 board invariant holds until a real journey
 * books one through the UI.
 *
 * Stdout is redacted. Passwords go only to /tmp/reception.env.
 */

require getenv('WP_HOME') . '/wp-load.php';

global $wpdb;

$db = \ClinicCore\Bootstrap\App::db();
$now = $db->nowUtcSql();
$uniq = substr(bin2hex(random_bytes(4)), 0, 8);
$todayTehran = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$todayTokyo  = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

function rp_fail(string $msg): void
{
    $safe = preg_replace('/\d{10,}/', '[redacted]', $msg) ?? $msg;
    echo 'RECEPTION_FIXTURE_ERROR: ' . $safe . "\n";
    exit(1);
}

function rp_insert(\wpdb $wpdb, string $sql, array $args, string $label): int
{
    $prepared = $wpdb->prepare($sql, ...$args);
    if (!is_string($prepared) || $wpdb->query($prepared) === false) {
        rp_fail($label . ': ' . ($wpdb->last_error !== '' ? $wpdb->last_error : 'prepare/query failed'));
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) {
        rp_fail($label . ': insert id missing');
    }

    return $id;
}

function rp_field(string $value, string $label): string
{
    if ($value === '' || str_contains($value, '|') || str_contains($value, "\n")) {
        rp_fail($label . ' cannot be serialized');
    }

    return $value;
}

$pass = 'RpSec-' . $uniq . '-2026!';
$login = 'rpsec' . $uniq;

$orgId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
    ['Synthetic Reception Org', 'rp-org-' . $uniq, 'active', $now, $now],
    'organization'
);
if ($orgId <= 1) {
    rp_fail('organization id must be nontrivial');
}

// Clinic timezone is deliberately NOT the operational-day authority: each
// Location carries its own IANA timezone (Tehran + Tokyo).
$clinicId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
    ['Synthetic Reception Clinic', 'rp-clinic-' . $uniq, 'Asia/Tehran', $orgId, $now, $now],
    'clinic'
);
if ($clinicId <= 1) {
    rp_fail('clinic id must be nontrivial');
}

$locTehran = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, %d, %d, %s, %s)',
    [$clinicId, 'Synthetic Reception Tehran', 'rp-tehran-' . $uniq, 'Asia/Tehran', 1, 1, $now, $now],
    'tehran location'
);
$locTokyo = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, %d, %d, %s, %s)',
    [$clinicId, 'Synthetic Reception Tokyo', 'rp-tokyo-' . $uniq, 'Asia/Tokyo', 0, 1, $now, $now],
    'tokyo location'
);
if ($locTehran <= 1 || $locTokyo <= 1 || $locTehran === $locTokyo) {
    rp_fail('location ids must be nontrivial and distinct');
}

$clinicianId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, NULL, %s, NULL, NULL, 1, %s, %s)',
    [$clinicId, 'Dr Reception ' . $uniq, $now, $now],
    'clinician'
);

// Authorized secretary: WP secretary role + ACTIVE membership (existing
// cpms_secretary preset — no new role/capability is introduced).
$userId = wp_create_user($login, $pass, $login . '@pilot.local');
if (is_wp_error($userId)) {
    rp_fail('secretary user create failed');
}
(new WP_User((int) $userId))->set_role('cpms_secretary');
try {
    \ClinicCore\Bootstrap\App::membership_service()->create_membership($clinicId, (int) $userId, 'cpms_secretary');
} catch (\Throwable $e) {
    rp_fail('secretary membership: ' . $e->getMessage());
}

// Authorized doctor for the EXISTING Doctor-module queue evidence (call/start
// by the real doctor path): WP cpms_doctor role + ACTIVE membership + the
// clinicians row bound to that user — existing presets only, no new role or
// capability is introduced.
$doctorLogin = 'rpdoc-' . $uniq;
$doctorPass = 'RpDoc-' . $uniq . '-2026!';
$doctorUserId = wp_create_user($doctorLogin, $doctorPass, $doctorLogin . '@pilot.local');
if (is_wp_error($doctorUserId)) {
    rp_fail('doctor user create failed');
}
(new WP_User((int) $doctorUserId))->set_role('cpms_doctor');
try {
    \ClinicCore\Bootstrap\App::membership_service()->create_membership($clinicId, (int) $doctorUserId, 'cpms_doctor');
} catch (\Throwable $e) {
    rp_fail('doctor membership: ' . $e->getMessage());
}
if ($wpdb->query($wpdb->prepare('UPDATE ' . $db->table('cpms_clinicians') . ' SET wp_user_id = %d WHERE id = %d', (int) $doctorUserId, $clinicianId)) === false) {
    rp_fail('clinician bind: ' . $wpdb->last_error);
}

// Phase 11 Slice 4 — walk-in eligibility stage (existing tables/services only).
// Dr Reception is durably assigned to Tehran. A second doctor whose HOME
// clinicians.clinic_id is the foreign Clinic participates here through an
// ACTIVE membership and is assigned to Tehran + Tokyo ⇒ Tehran N=2, Tokyo 1.
// A decoy clinician (home = this Clinic, raw Tehran assignment row, NO bound
// user / membership) must never be offered: home metadata is not authority.
try {
    \ClinicCore\Bootstrap\App::membership_service()->assign_clinician_locations($clinicianId, [$locTehran]);
} catch (\Throwable $e) {
    rp_fail('clinician location assign: ' . $e->getMessage());
}
$walkinForeignHome = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
    ['Synthetic Walk-in Home Clinic', 'rp-wihome-' . $uniq, 'Asia/Tehran', $orgId, $now, $now],
    'walk-in second doctor home clinic'
);
$doctor2Login = 'rpdoc2-' . $uniq;
$doctor2UserId = wp_create_user($doctor2Login, 'RpDoc2-' . $uniq . '-2026!', $doctor2Login . '@pilot.local');
if (is_wp_error($doctor2UserId)) {
    rp_fail('second doctor user create failed');
}
(new WP_User((int) $doctor2UserId))->set_role('cpms_doctor');
$clinician2Id = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, NULL, NULL, 1, %s, %s)',
    [$walkinForeignHome, (int) $doctor2UserId, 'Dr Walkin Second ' . $uniq, $now, $now],
    'second walk-in clinician'
);
try {
    \ClinicCore\Bootstrap\App::membership_service()->create_membership($clinicId, (int) $doctor2UserId, 'cpms_doctor');
    \ClinicCore\Bootstrap\App::membership_service()->assign_clinician_locations($clinician2Id, [$locTehran, $locTokyo]);
} catch (\Throwable $e) {
    rp_fail('second doctor participation: ' . $e->getMessage());
}
$decoyClinicianId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, NULL, %s, NULL, NULL, 1, %s, %s)',
    [$clinicId, 'Dr Decoy ' . $uniq, $now, $now],
    'decoy clinician'
);
rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinician_locations') . ' (clinician_id, location_id, created_at) VALUES (%d, %d, %s)',
    [$decoyClinicianId, $locTehran, $now],
    'decoy raw location row'
);
$walkinPatients = [];
foreach (['MOBILE', 'TABLET', 'DESKTOP', 'PARTIAL'] as $wIndex => $wTag) {
    $walkinPatients[$wTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, 'MR-RP-WI-' . $wTag . '-' . $uniq, 'Walkin', ucfirst(strtolower($wTag)), '0913' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . (string) ($wIndex + 1), 'active', $now, $now],
        'walk-in patient ' . $wTag
    );
}

// The EXISTING standalone Doctor Portal page (the same product page the
// established doctor journeys exercise) for the call/start evidence.
$doctorUrl = \ClinicCore\Frontend\DoctorPortalShell::portal_url();
if (!is_string($doctorUrl) || $doctorUrl === '' || $doctorUrl === home_url('/')) {
    rp_fail('doctor portal URL invalid: ' . (string) $doctorUrl);
}

$patientIds = [];
$patientIndex = 0;
foreach (['a', 'b', 'c', 'd'] as $tag) {
    $patientIndex++;
    $patientIds[$tag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [
            $clinicId,
            'MR-RP-' . strtoupper($tag) . '-' . $uniq,
            'Reception',
            'Patient ' . strtoupper($tag),
            // Unique per patient: cpms_patients.u_pat_mobile is unique per clinic.
            '0912' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . (string) $patientIndex,
            'active',
            $now,
            $now,
        ],
        'patient ' . $tag
    );
}

// Phase 11 Slice 2 — read-only Clinic patient search proof. One UNBOOKED
// patient of this Clinic with a national ID (masked presentation proof) and a
// same-name decoy in ANOTHER Clinic of the same Organization (Clinic isolation
// proof; dormant Organization patient identity is not touched).
$probeNid = sprintf('%010d', hexdec(substr($uniq, 0, 7)) % 10000000000);
$probeId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, national_id, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)',
    [$clinicId, 'MR-RP-PROBE-' . $uniq, 'Search', 'Probe', '0912' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . '5', $probeNid, 'active', $now, $now],
    'search probe patient'
);
$foreignClinicId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
    ['Synthetic Foreign Clinic', 'rp-foreign-' . $uniq, 'Asia/Tehran', $orgId, $now, $now],
    'foreign clinic'
);
$foreignProbeId = rp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
    [$foreignClinicId, 'MR-RP-FOREIGN-' . $uniq, 'Search', 'Probe', '0935' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . '9', 'active', $now, $now],
    'foreign search probe patient'
);

// Tehran Location: exactly four of TODAY's booked rows (first is express) —
// one per real-browser journey (three viewport arrivals + one partial-arrival
// recovery journey), so each journey performs its OWN arrival on its OWN row.
// Slots sit in the NEAR FUTURE of the Location-local day: the EXISTING ER-06
// check-in semantics treat an arrival after slot start + per-Clinic grace as a
// late arrival (no_show + walk-in-like visit), so the happy-path journey must
// arrive within the grace window like a real reception desk.
$nowTehran = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
$lateToday = (int) $nowTehran->format('H') >= 23;
$slotExpress = $lateToday ? $nowTehran->setTime(23, 55) : $nowTehran->add(new DateInterval('PT10M'));
$slotPlain = $lateToday ? $nowTehran->setTime(23, 57) : $nowTehran->add(new DateInterval('PT30M'));
$slotThird = $lateToday ? $nowTehran->setTime(23, 58) : $nowTehran->add(new DateInterval('PT50M'));
$slotPartial = $lateToday ? $nowTehran->setTime(23, 59) : $nowTehran->add(new DateInterval('PT70M'));
$apptExpress = null;
$apptPlain = null;
$apptThird = null;
$apptPartial = null;
foreach ([['a', $slotExpress->format('H:i:s'), 1], ['b', $slotPlain->format('H:i:s'), 0], ['c', $slotThird->format('H:i:s'), 0], ['d', $slotPartial->format('H:i:s'), 0]] as $spec) {
    [$tag, $time, $express] = $spec;
    $slotId = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
        [$clinicId, $locTehran, $clinicianId, $todayTehran, $time, 20, 1, 1, 0, 1, 'manual', $now, $now],
        'slot ' . $tag
    );
    $apptId = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_appointments') . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)',
        [$clinicId, $locTehran, 'rp-' . $tag . '-' . $uniq, $patientIds[$tag], $clinicianId, $slotId, 0, $todayTehran, $time, 20, $time, 'confirmed', $express, $now, $now, $now],
        'appointment ' . $tag
    );
    if ($express === 1) {
        $apptExpress = $apptId;
    } elseif ($tag === 'b') {
        $apptPlain = $apptId;
    } elseif ($tag === 'c') {
        $apptThird = $apptId;
    } else {
        $apptPartial = $apptId;
    }
}

// Phase 11 Slice 5 — appointment booking stage: ALREADY-GENERATED slots only
// (the pilot never generates a schedule and the reception boundary never
// fabricates one). Dr Reception at Tehran carries two FREE slots for the
// explicit-selection journeys — free_a with capacity 2 so the same persisted
// slot can prove the bounded duplicate rule for one patient and the honest
// remaining-capacity indicator for another — plus one FULL and one CLOSED slot
// that must never be offered, and one FREE slot on the NEXT Tehran-local day
// for the future-date journey (a future appointment must never enter today's
// board or queue). No appointment row is inserted here: the booking journeys
// create them through the real UI, so the Slice 1 board invariant (exactly four
// booked rows for today) is unchanged until a journey books one.
$slotFreeA   = $lateToday ? $nowTehran->setTime(23, 45) : $nowTehran->add(new DateInterval('PT95M'));
$slotFreeB   = $lateToday ? $nowTehran->setTime(23, 47) : $nowTehran->add(new DateInterval('PT115M'));
$slotFreeC   = $lateToday ? $nowTehran->setTime(23, 49) : $nowTehran->add(new DateInterval('PT135M'));
$slotFull    = $lateToday ? $nowTehran->setTime(23, 51) : $nowTehran->add(new DateInterval('PT155M'));
$slotClosed  = $lateToday ? $nowTehran->setTime(23, 53) : $nowTehran->add(new DateInterval('PT175M'));
$tomorrowTehran = $nowTehran->add(new DateInterval('P1D'))->format('Y-m-d');
$futureTime     = '10:00:00';

// Cancel journeys need three distinct, still-future slots when their browser
// stage runs (after the other viewport journeys). Use today's operational date
// only when the latest slot plus a 60-minute run-up remains before Tehran
// midnight; otherwise put all three on the existing booking journey's supported next-day
// date so they stay future even if the fixture/browser stage crosses midnight.
$cancelOffsets      = [105, 125, 145];
$cancelRunwayMinutes = 60;
$cancelLatestAt     = $nowTehran->add(new DateInterval('PT' . (max($cancelOffsets) + $cancelRunwayMinutes) . 'M'));
$cancelDate         = $cancelLatestAt->format('Y-m-d') === $todayTehran
    ? $todayTehran
    : $tomorrowTehran;
if ($cancelDate === $todayTehran) {
    $cancelSlotTimes = [];
    foreach ($cancelOffsets as $cancelOffset) {
        $cancelSlotTimes[] = $nowTehran->add(new DateInterval('PT' . $cancelOffset . 'M'))->format('H:i:s');
    }
} else {
    $cancelSlotTimes = ['10:15:00', '10:35:00', '10:55:00'];
}

$bookingSlotIds = [];
foreach ([
    ['free_a', $todayTehran, $slotFreeA->format('H:i:s'), 2, 0, 1],
    ['free_b', $todayTehran, $slotFreeB->format('H:i:s'), 2, 0, 1],
    ['free_c', $todayTehran, $slotFreeC->format('H:i:s'), 2, 0, 1],
    ['full', $todayTehran, $slotFull->format('H:i:s'), 1, 1, 1],
    ['closed', $todayTehran, $slotClosed->format('H:i:s'), 1, 0, 0],
    ['future', $tomorrowTehran, $futureTime, 4, 0, 1],
] as $bookingSpec) {
    [$bookingKey, $bookingDate, $bookingTime, $bookingCapacity, $bookingBooked, $bookingOpen] = $bookingSpec;
    $bookingSlotIds[$bookingKey] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
        [$clinicId, $locTehran, $clinicianId, $bookingDate, $bookingTime, 20, $bookingCapacity, $bookingBooked, 0, $bookingOpen, 'manual', $now, $now],
        'booking slot ' . $bookingKey
    );
}

// One booking patient per viewport journey (each journey books its own patient,
// so no journey depends on another one's mutable state).
$bookingPatients = [];
foreach (['MOBILE', 'TABLET', 'DESKTOP'] as $bookingIndex => $bookingTag) {
    $bookingPatients[$bookingTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, 'MR-RP-BK-' . $bookingTag . '-' . $uniq, 'Booking', ucfirst(strtolower($bookingTag)), '0914' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . (string) ($bookingIndex + 2), 'active', $now, $now],
        'booking patient ' . $bookingTag
    );
}

// Phase 11 Slice 6 — reception cancel stage: one dedicated ACTIVE patient and
// one dedicated capacity-1 slot per viewport journey. No appointment row is
// inserted here either: each journey first books through the real Slice 5 UI,
// then cancels that booked row through the new boundary. This keeps the Slice 1
// board invariant (four booked fixture rows) intact and makes the slot release
// observable through the EXISTING bounded slot read: capacity 1 is fully claimed
// by the journey's own appointment, so the freed slot can be proven only after
// the cancellation actually happened.
$cancelSlotIds = [];
foreach (['MOBILE', 'TABLET', 'DESKTOP'] as $cancelIndex => $cancelTag) {
    $cancelSlotIds[$cancelTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
        [$clinicId, $locTehran, $clinicianId, $cancelDate, $cancelSlotTimes[$cancelIndex], 20, 1, 0, 0, 1, 'manual', $now, $now],
        'cancel slot ' . $cancelTag
    );
}
$cancelPatients = [];
foreach (['MOBILE', 'TABLET', 'DESKTOP'] as $cancelIndex => $cancelTag) {
    $cancelPatients[$cancelTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, 'MR-RP-CX-' . $cancelTag . '-' . $uniq, 'Cancel', ucfirst(strtolower($cancelTag)), '0912' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . (string) ($cancelIndex + 6), 'active', $now, $now],
        'cancel patient ' . $cancelTag
    );
}

// Phase 11 Slice 7 — reception reschedule stage: one dedicated ACTIVE patient,
// one dedicated capacity-1 SOURCE slot and one dedicated capacity-1
// DESTINATION slot per viewport journey. No appointment row is inserted here
// either: each journey first books its own source slot through the real Slice 5
// UI (so the source row is genuinely confirmed, on today's board and
// not-yet-received), then reschedules that row through the new boundary. The
// Slice 1 board invariant (four booked fixture rows) therefore stays intact
// until a journey books one, exactly like the Slice 5/Slice 6 stages.
//
// Destinations stay inside the CURRENT trusted Location (cross-Location is out
// of scope): MOBILE → the same clinician later today, TABLET → the OTHER
// eligible clinician at the same Location, DESKTOP → the next Tehran-local day
// (so the journey can prove an off-operational-day destination stays OFF
// today's board). When an offset crosses local midnight the destination is
// simply on the next local day, and the exported date tells the journey which
// branch to assert.
$rescheduleSourceOffsets = ['MOBILE' => 35, 'TABLET' => 55, 'DESKTOP' => 65];
$rescheduleDestOffsets   = ['MOBILE' => 75, 'TABLET' => 100];
$rescheduleLateSource    = ['MOBILE' => '23:14:00', 'TABLET' => '23:18:00', 'DESKTOP' => '23:22:00'];
$rescheduleSlots = ['source' => [], 'dest' => [], 'dest_date' => [], 'patient' => []];
$rescheduleDestDoctor = ['MOBILE' => $clinicianId, 'TABLET' => $clinician2Id, 'DESKTOP' => $clinicianId];
foreach (['MOBILE', 'TABLET', 'DESKTOP'] as $resIndex => $resTag) {
    $sourceAt = $lateToday
        ? $nowTehran->setTime((int) substr($rescheduleLateSource[$resTag], 0, 2), (int) substr($rescheduleLateSource[$resTag], 3, 2))
        : $nowTehran->add(new DateInterval('PT' . $rescheduleSourceOffsets[$resTag] . 'M'));
    if ($sourceAt->format('Y-m-d') !== $todayTehran) {
        $sourceAt = $nowTehran->setTime(23, 55);
    }
    $rescheduleSlots['source'][$resTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
        [$clinicId, $locTehran, $clinicianId, $todayTehran, $sourceAt->format('H:i:s'), 20, 1, 0, 0, 1, 'manual', $now, $now],
        'reschedule source slot ' . $resTag
    );
    if ($resTag === 'DESKTOP') {
        $destAt   = new DateTimeImmutable($tomorrowTehran . ' 11:00:00', new DateTimeZone('Asia/Tehran'));
    } else {
        $destAt = $nowTehran->add(new DateInterval('PT' . $rescheduleDestOffsets[$resTag] . 'M'));
    }
    $rescheduleSlots['dest_date'][$resTag] = $destAt->format('Y-m-d');
    $rescheduleSlots['dest'][$resTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
        [$clinicId, $locTehran, $rescheduleDestDoctor[$resTag], $destAt->format('Y-m-d'), $destAt->format('H:i:s'), 20, 1, 0, 0, 1, 'manual', $now, $now],
        'reschedule destination slot ' . $resTag
    );
    $rescheduleSlots['patient'][$resTag] = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, 'MR-RP-RS-' . $resTag . '-' . $uniq, 'Reschedule', ucfirst(strtolower($resTag)), '0913' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . (string) ($resIndex + 8), 'active', $now, $now],
        'reschedule patient ' . $resTag
    );
}

// Slice 8 evidence-only future sources and persisted destinations. The fixture
// seeds booked appointments; both transitions themselves run through the real
// Reception UI/service, never by changing the source status in the fixture.
$upcomingProof = [];
$upcomingDestToday = $nowTehran->add(new DateInterval('PT90M'));
if ($upcomingDestToday->format('Y-m-d') !== $todayTehran) {
    $upcomingDestToday = $nowTehran->setTime(23, 54);
}
foreach (['future' => [$tomorrowTehran, '13:00:00', $nowTehran->add(new DateInterval('P2D'))->format('Y-m-d'), '15:00:00'],
          'today' => [$tomorrowTehran, '14:00:00', $todayTehran, $upcomingDestToday->format('H:i:s')]] as $kind => $spec) {
    [$sourceDate, $sourceTime, $destDate, $destTime] = $spec;
    $proofPatient = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, 'MR-RP-UP-' . $kind . '-' . $uniq, 'Upcoming', ucfirst($kind), '0916' . sprintf('%06d', hexdec(substr($uniq, 0, 6)) % 1000000) . ($kind === 'future' ? '1' : '2'), 'active', $now, $now],
        'upcoming patient ' . $kind
    );
    $proofSlots = [];
    foreach (['source' => [$sourceDate, $sourceTime, 1], 'dest' => [$destDate, $destTime, 0]] as $position => $slotSpec) {
        [$slotDate, $slotTime, $booked] = $slotSpec;
        $proofSlots[$position] = rp_insert(
            $wpdb,
            'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
            [$clinicId, $locTehran, $clinicianId, $slotDate, $slotTime, 20, 1, $booked, 0, 1, 'manual', $now, $now],
            'upcoming ' . $kind . ' ' . $position
        );
    }
    $proofAppt = rp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_appointments') . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)',
        [$clinicId, $locTehran, 'rp-up-' . $kind . '-' . $uniq, $proofPatient, $clinicianId, $proofSlots['source'], 0, $sourceDate, $sourceTime, 20, $sourceTime, 'confirmed', 0, $now, $now, $now],
        'upcoming appointment ' . $kind
    );
    $upcomingProof[$kind] = ['appointment' => $proofAppt, 'patient' => $proofPatient, 'source' => $proofSlots['source'], 'dest' => $proofSlots['dest'], 'dest_date' => $destDate];
}

// The EXISTING per-Clinic two-stage knob: with auto-enqueue off, the reception
// action runs the established check-in then the explicit enqueue transition —
// the exact surface the partial-arrival acceptance covers (FR-6.1 keeps the
// auto path for clinics that leave it on).
(new \ClinicCore\Settings\Settings($db, $clinicId))->set('queue.auto_enqueue', false);

// TEST-ONLY: install the cookie-triggered enqueue sabotage mu-plugin so the
// real-browser journey can witness a partial arrival and its recovery. The
// mu-plugin is inert without the rp_sabotage cookie and is never shipped.
$muDir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : (WP_CONTENT_DIR . '/mu-plugins');
if (!is_dir($muDir) && !mkdir($muDir, 0777, true) && !is_dir($muDir)) {
    rp_fail('mu-plugins dir unavailable');
}
if (!copy(__DIR__ . '/pilot-reception-sabotage-mu.php', $muDir . '/rp-partial-sabotage.php')) {
    rp_fail('sabotage mu-plugin install failed');
}
// Tokyo Location: deliberately NO booked rows (empty state proof).

$url = \ClinicCore\Frontend\StaffPortalShell::portal_url();
if (!is_string($url) || !str_starts_with($url, 'http') || str_contains($url, '/wp-admin/')) {
    rp_fail('staff portal URL invalid: ' . (string) $url);
}
$receptionUrl = $url . (str_contains($url, '?') ? '&' : '?') . 'cpms-module=reception';

file_put_contents(
    '/tmp/reception.env',
    'RECEPTION_URL=' . $receptionUrl . "\n"
    . 'STAFF_PORTAL_URL=' . $url . "\n"
    . 'RECEPTION_SECRETARY=' . implode('|', [$login, $pass, (string) $userId]) . "\n"
    . 'RECEPTION_DOCTOR=' . implode('|', [$doctorLogin, $doctorPass, (string) $doctorUserId]) . "\n"
    . 'RECEPTION_DOCTOR_URL=' . $doctorUrl . "\n"
    . 'RECEPTION_PUBLIC=' . implode('|', [
        (string) $clinicId,
        (string) $locTehran,
        (string) $locTokyo,
        (string) $apptExpress,
        (string) $apptPlain,
        (string) $apptThird,
        (string) $apptPartial,
        $todayTehran,
        $todayTokyo,
    ]) . "\n"
    . 'RECEPTION_SEARCH=' . implode('|', [
        (string) $probeId,
        (string) $foreignProbeId,
        substr($probeNid, -4),
    ]) . "\n"
    . 'RECEPTION_BOOKING=' . implode('|', [
        (string) $clinicianId,
        (string) $clinician2Id,
        (string) $bookingSlotIds['free_a'],
        (string) $bookingSlotIds['free_b'],
        (string) $bookingSlotIds['free_c'],
        (string) $bookingSlotIds['full'],
        (string) $bookingSlotIds['closed'],
        (string) $bookingSlotIds['future'],
        $tomorrowTehran,
        (string) $bookingPatients['MOBILE'],
        (string) $bookingPatients['TABLET'],
        (string) $bookingPatients['DESKTOP'],
        'MR-RP-BK-',
        '-' . $uniq,
    ]) . "\n"
    . 'RECEPTION_WALKIN=' . implode('|', [
        (string) $clinicianId,
        (string) $clinician2Id,
        (string) $decoyClinicianId,
        (string) $walkinPatients['MOBILE'],
        (string) $walkinPatients['TABLET'],
        (string) $walkinPatients['DESKTOP'],
        (string) $walkinPatients['PARTIAL'],
        'MR-RP-WI-',
        '-' . $uniq,
    ]) . "\n"
    . 'RECEPTION_RESCHEDULE=' . implode('|', [
        (string) $clinicianId,
        (string) $clinician2Id,
        (string) $rescheduleSlots['source']['MOBILE'],
        (string) $rescheduleSlots['source']['TABLET'],
        (string) $rescheduleSlots['source']['DESKTOP'],
        (string) $rescheduleSlots['dest']['MOBILE'],
        (string) $rescheduleSlots['dest']['TABLET'],
        (string) $rescheduleSlots['dest']['DESKTOP'],
        $rescheduleSlots['dest_date']['MOBILE'],
        $rescheduleSlots['dest_date']['TABLET'],
        $rescheduleSlots['dest_date']['DESKTOP'],
        (string) $rescheduleSlots['patient']['MOBILE'],
        (string) $rescheduleSlots['patient']['TABLET'],
        (string) $rescheduleSlots['patient']['DESKTOP'],
        'MR-RP-RS-',
        '-' . $uniq,
    ]) . "\n"
    . 'RECEPTION_UPCOMING=' . implode('|', [
        (string) $upcomingProof['future']['appointment'],
        (string) $upcomingProof['future']['patient'],
        (string) $upcomingProof['future']['source'],
        (string) $upcomingProof['future']['dest'],
        $upcomingProof['future']['dest_date'],
        (string) $upcomingProof['today']['appointment'],
        (string) $upcomingProof['today']['patient'],
        (string) $upcomingProof['today']['source'],
        (string) $upcomingProof['today']['dest'],
        $upcomingProof['today']['dest_date'],
    ]) . "\n"
    . 'RECEPTION_CANCEL=' . implode('|', [
        (string) $clinicianId,
        (string) $cancelSlotIds['MOBILE'],
        (string) $cancelSlotIds['TABLET'],
        (string) $cancelSlotIds['DESKTOP'],
        (string) $cancelPatients['MOBILE'],
        (string) $cancelPatients['TABLET'],
        (string) $cancelPatients['DESKTOP'],
        'MR-RP-CX-',
        '-' . $uniq,
        $cancelDate,
    ]) . "\n"
);

echo 'fixture: reception clinic=' . $clinicId
    . ' loc_tehran=' . $locTehran . ' loc_tokyo=' . $locTokyo
    . ' appts=' . $apptExpress . ',' . $apptPlain . ',' . $apptThird . ',' . $apptPartial
    . ' today_tehran=' . $todayTehran . ' today_tokyo=' . $todayTokyo
    . ' search_probe=' . $probeId . ' foreign_probe=' . $foreignProbeId
    . ' booking_slots=' . implode(',', $bookingSlotIds) . ' tomorrow_tehran=' . $tomorrowTehran
    . ' cancel_slots=' . implode(',', $cancelSlotIds)
    . ' reschedule_slots=' . implode(',', $rescheduleSlots['source'])
    . ' reschedule_dest=' . implode(',', $rescheduleSlots['dest']) . "\n";

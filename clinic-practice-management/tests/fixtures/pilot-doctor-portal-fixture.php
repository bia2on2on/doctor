<?php
/**
 * Synthetic Doctor Portal fixture for the existing Pilot Chromium job.
 *
 * One clinic with one eligible Location (auto-resolve) and a second doctor
 * in that same Location. A separate clinic with two eligible Locations plus
 * one inactive Location (no auto-select). Visit dates are the Location-local
 * Asia/Tehran today — the browser does not freeze time.
 *
 * Stdout is redacted. Passwords go only to /tmp/doctor-portal.env.
 */

require getenv('WP_HOME') . '/wp-load.php';

global $wpdb;

$db = \ClinicCore\Bootstrap\App::db();
$now = $db->nowUtcSql();
$uniq = substr(bin2hex(random_bytes(4)), 0, 8);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');

function dp_fail(string $msg): void
{
    $safe = preg_replace('/\d{10,}/', '[redacted]', $msg) ?? $msg;
    echo 'DOCTOR_PORTAL_FIXTURE_ERROR: ' . $safe . "\n";
    exit(1);
}

function dp_insert(\wpdb $wpdb, string $sql, array $args, string $label): int
{
    $prepared = $wpdb->prepare($sql, ...$args);
    if (!is_string($prepared) || $wpdb->query($prepared) === false) {
        dp_fail($label . ': ' . ($wpdb->last_error !== '' ? $wpdb->last_error : 'prepare/query failed'));
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) {
        dp_fail($label . ': insert id missing');
    }

    return $id;
}

function dp_user(string $login, string $pass): int
{
    $userId = wp_create_user($login, $pass, $login . '@pilot.local');
    if (is_wp_error($userId)) {
        dp_fail('user create failed');
    }
    $user = new WP_User((int) $userId);
    $user->set_role('cpms_doctor');

    return (int) $userId;
}

function dp_field(string $value, string $label): string
{
    if ($value === '' || str_contains($value, '|') || str_contains($value, "\n")) {
        dp_fail($label . ' cannot be serialized');
    }

    return $value;
}

$n = hexdec(substr($uniq, 0, 6)) % 1000000;
$mobile = static function (string $prefix) use ($n): string {
    return $prefix . sprintf('%07d', $n);
};

$passOne = 'DpOne-' . $uniq . '-2026!';
$passOther = 'DpOther-' . $uniq . '-2026!';
$passMulti = 'DpMulti-' . $uniq . '-2026!';
$loginOne = 'dpone' . $uniq;
$loginOther = 'dpother' . $uniq;
$loginMulti = 'dpmulti' . $uniq;

$orgId = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
    ['Synthetic Doctor Portal Org', 'dp-org-' . $uniq, 'active', $now, $now],
    'organization'
);
if ($orgId <= 1) {
    dp_fail('organization id must be nontrivial');
}

$oneClinic = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
    ['Synthetic Clinic One', 'dp-one-' . $uniq, 'Asia/Tehran', $orgId, $now, $now],
    'one clinic'
);
$multiClinic = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
    ['Synthetic Clinic Multi', 'dp-multi-' . $uniq, 'Asia/Tehran', $orgId, $now, $now],
    'multi clinic'
);
if ($oneClinic <= 1 || $multiClinic <= 1 || $oneClinic === $multiClinic) {
    dp_fail('clinic ids must be nontrivial and distinct');
}

$oneLoc = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, %d, %d, %s, %s)',
    [$oneClinic, 'Synthetic Location One', 'one-' . $uniq, 'Asia/Tehran', 1, 1, $now, $now],
    'one location'
);
$locA = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, %d, %d, %s, %s)',
    [$multiClinic, 'Synthetic Location A', 'loc-a-' . $uniq, 'Asia/Tehran', 1, 1, $now, $now],
    'location A'
);
$locB = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, %d, %d, %s, %s)',
    [$multiClinic, 'Synthetic Location B', 'loc-b-' . $uniq, 'Asia/Tehran', 0, 1, $now, $now],
    'location B'
);
$inactiveLoc = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, %d, %d, %s, %s)',
    [$multiClinic, 'Synthetic Location Inactive', 'loc-off-' . $uniq, 'Asia/Tehran', 0, 0, $now, $now],
    'inactive location'
);

$userOne = dp_user($loginOne, $passOne);
$userOther = dp_user($loginOther, $passOther);
$userMulti = dp_user($loginMulti, $passMulti);

$clinOne = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, NULL, 1, %s, %s)',
    [$oneClinic, $userOne, 'Synthetic Doctor A', 'general', $now, $now],
    'clinician A'
);
$clinOther = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, NULL, 1, %s, %s)',
    [$oneClinic, $userOther, 'Synthetic Doctor B', 'general', $now, $now],
    'clinician B'
);
$clinMulti = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, NULL, 1, %s, %s)',
    [$multiClinic, $userMulti, 'Synthetic Doctor Multi', 'general', $now, $now],
    'clinician multi'
);
$clinColleague = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, NULL, %s, %s, NULL, 1, %s, %s)',
    [$multiClinic, 'Synthetic Doctor Colleague', 'general', $now, $now],
    'colleague clinician'
);

try {
    $memberships = \ClinicCore\Bootstrap\App::membership_service();
    $memberships->create_membership($oneClinic, $userOne, 'cpms_doctor');
    $memberships->create_membership($oneClinic, $userOther, 'cpms_doctor');
    $memberships->create_membership($multiClinic, $userMulti, 'cpms_doctor');
} catch (Throwable $e) {
    dp_fail('membership: ' . $e->getMessage());
}

// Phase 10 — NON-doctor staff actor with an ACTIVE membership in the same
// Clinic. Proves the legacy Doctor Portal entry is not an authorization
// bypass: no compatibility redirect and no doctor module for this actor.
$loginSecretary = 'dpsec' . $uniq;
$passSecretary = 'DpSec-' . $uniq . '-2026!';
$userSecretary = wp_create_user($loginSecretary, $passSecretary, $loginSecretary . '@pilot.local');
if (is_wp_error($userSecretary)) {
    dp_fail('secretary user create failed');
}
$userSecretary = (int) $userSecretary;
(new WP_User($userSecretary))->set_role('cpms_secretary');
try {
    \ClinicCore\Bootstrap\App::membership_service()->create_membership($oneClinic, $userSecretary, 'cpms_secretary');
} catch (Throwable $e) {
    dp_fail('secretary membership: ' . $e->getMessage());
}

$patient = static function (int $clinicId, string $mrn, string $mobile) use ($wpdb, $db, $now): int {
    return dp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, $mrn, 'Synthetic', $mrn, $mobile, 'active', $now, $now],
        'patient ' . $mrn
    );
};

$visit = static function (int $clinicId, int $locationId, int $clinicianId, int $patientId) use ($wpdb, $db, $now, $today): int {
    return dp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_visits') . ' (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)',
        [$clinicId, $locationId, $clinicianId, $patientId, 'walk_in', 'waiting', $today, $now, $now, $now, $now],
        'visit'
    );
};

$visitOwn = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-A-' . $uniq, $mobile('0911')));
$visitOther = $visit($oneClinic, $oneLoc, $clinOther, $patient($oneClinic, 'SYN-DP-B-' . $uniq, $mobile('0912')));
// Phase 10 Slice 2 GREEN: per-viewport dedicated queue-action visits for the
// ONE doctor (call/recall/start on act_*, skip on skip_*). visit_own is never
// mutated so the read-only assertions stay stable in every viewport run.
$act390 = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-C1-' . $uniq, $mobile('0916')));
$skip390 = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-S1-' . $uniq, $mobile('0917')));
$act768 = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-C2-' . $uniq, $mobile('0918')));
$skip768 = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-S2-' . $uniq, $mobile('0919')));
$act1366 = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-C3-' . $uniq, $mobile('0920')));
$skip1366 = $visit($oneClinic, $oneLoc, $clinOne, $patient($oneClinic, 'SYN-DP-S3-' . $uniq, $mobile('0921')));
$visitA = $visit($multiClinic, $locA, $clinMulti, $patient($multiClinic, 'SYN-DP-MA-' . $uniq, $mobile('0913')));
$visitB = $visit($multiClinic, $locB, $clinMulti, $patient($multiClinic, 'SYN-DP-MB-' . $uniq, $mobile('0914')));
$visitColleague = $visit($multiClinic, $locB, $clinColleague, $patient($multiClinic, 'SYN-DP-MC-' . $uniq, $mobile('0915')));

$namedPatient = static function (int $clinicId, string $mrn, string $mobileValue, string $first, string $last) use ($wpdb, $db, $now): int {
    return dp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, national_id, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)',
        [$clinicId, $mrn, $first, $last, $mobileValue, substr(preg_replace('/\D/', '', $mobileValue) ?? '', 0, 10), 'active', $now, $now],
        'named patient ' . $mrn
    );
};
$slot = static function (int $clinicId, int $locationId, int $clinicianId, string $time) use ($wpdb, $db, $now, $today): int {
    return dp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots') . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %d, %d, %d, %d, %d, %s, %s, %s)',
        [$clinicId, $locationId, $clinicianId, $today, $time, 20, 1, 1, 0, 1, 'manual', $now, $now],
        'slot'
    );
};
$appointment = static function (int $clinicId, int $locationId, int $clinicianId, int $patientId, int $slotId, string $time, string $ref) use ($wpdb, $db, $now, $today): int {
    return dp_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_appointments') . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s, %s)',
        [$clinicId, $locationId, $ref, $patientId, $clinicianId, $slotId, 0, $today, $time, 20, '00:20:00', 'confirmed', 0, $now, $now, $now],
        'appointment'
    );
};

$bookedName = 'Mina Booked';
$arrivedName = 'Nima Arrived';
$otherName = 'Sara Other';
$betaName = 'Lale Beta';
$apptBooked = $appointment(
    $oneClinic,
    $oneLoc,
    $clinOne,
    $namedPatient($oneClinic, 'SYN-DP-AB-' . $uniq, $mobile('0931'), 'Mina', 'Booked'),
    $slot($oneClinic, $oneLoc, $clinOne, '09:10:00'),
    '09:10:00',
    'DPB' . $uniq . 'A'
);
$arrivedPatient = $namedPatient($oneClinic, 'SYN-DP-AA-' . $uniq, $mobile('0932'), 'Nima', 'Arrived');
$arrivedSlot = $slot($oneClinic, $oneLoc, $clinOne, '09:40:00');
$apptArrived = $appointment($oneClinic, $oneLoc, $clinOne, $arrivedPatient, $arrivedSlot, '09:40:00', 'DPA' . $uniq . 'A');
$arrivedVisit = dp_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_visits') . ' (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)',
    [$oneClinic, $oneLoc, $clinOne, $arrivedPatient, $apptArrived, 'scheduled', 'checked_in', $today, $now, $now, $now, $now],
    'checked-in visit'
);
$linked = $wpdb->query($wpdb->prepare(
    'UPDATE ' . $db->table('cpms_appointments') . ' SET active_visit_id = %d WHERE id = %d',
    $arrivedVisit,
    $apptArrived
));
if ($linked === false) {
    dp_fail('appointment visit link failed');
}
$apptOther = $appointment(
    $oneClinic,
    $oneLoc,
    $clinOther,
    $namedPatient($oneClinic, 'SYN-DP-AO-' . $uniq, $mobile('0933'), 'Sara', 'Other'),
    $slot($oneClinic, $oneLoc, $clinOther, '10:20:00'),
    '10:20:00',
    'DPO' . $uniq . 'B'
);
$apptA = $appointment(
    $multiClinic,
    $locA,
    $clinMulti,
    $namedPatient($multiClinic, 'SYN-DP-APA-' . $uniq, $mobile('0934'), 'Reza', 'Alpha'),
    $slot($multiClinic, $locA, $clinMulti, '11:00:00'),
    '11:00:00',
    'DPM' . $uniq . 'A'
);
$apptB = $appointment(
    $multiClinic,
    $locB,
    $clinMulti,
    $namedPatient($multiClinic, 'SYN-DP-APB-' . $uniq, $mobile('0935'), 'Lale', 'Beta'),
    $slot($multiClinic, $locB, $clinMulti, '11:30:00'),
    '11:30:00',
    'DPM' . $uniq . 'B'
);
$apptColleague = $appointment(
    $multiClinic,
    $locB,
    $clinColleague,
    $namedPatient($multiClinic, 'SYN-DP-APC-' . $uniq, $mobile('0936'), 'Colleague', 'Hidden'),
    $slot($multiClinic, $locB, $clinColleague, '12:00:00'),
    '12:00:00',
    'DPM' . $uniq . 'C'
);

// Visits and appointments live in independent tables with independent
// auto-increments — a visit id may legitimately equal an appointment id
// (the harness addresses them via separate data-visit-id /
// data-appointment-id attributes). Distinctness is only meaningful per table.
$visitIds = [$visitOwn, $visitOther, $visitA, $visitB, $visitColleague, $act390, $skip390, $act768, $skip768, $act1366, $skip1366];
$apptIds = [$apptBooked, $apptArrived, $apptOther, $apptA, $apptB, $apptColleague];
if (count($visitIds) !== count(array_unique($visitIds)) || count($apptIds) !== count(array_unique($apptIds))) {
    dp_fail('visit ids must be distinct');
}

$url = \ClinicCore\Frontend\DoctorPortalShell::portal_url();
if (!is_string($url) || !str_starts_with($url, 'http') || str_contains($url, '/wp-admin/')) {
    dp_fail('portal url is not a frontend permalink');
}

// Phase 10 — canonical shared Staff Portal entry (the legacy Doctor Portal URL
// above stays the backward-compatible doctor entry). Resolved via the product
// seam only; the fixture never creates the Page itself.
$staffUrl = \ClinicCore\Frontend\StaffPortalShell::portal_url();
if (!is_string($staffUrl) || !str_starts_with($staffUrl, 'http') || str_contains($staffUrl, '/wp-admin/')) {
    dp_fail('staff portal url is not a frontend permalink');
}
if (rtrim($staffUrl, '/') === rtrim($url, '/')) {
    dp_fail('staff portal url must differ from the legacy doctor portal url');
}

$oneLine = implode('|', [
    dp_field($loginOne, 'login'),
    dp_field($passOne, 'pass'),
    (string) $userOne,
    (string) $clinOne,
    (string) $oneClinic,
    (string) $oneLoc,
    (string) $visitOwn,
    (string) $visitOther,
    dp_field($today, 'today'),
    'Synthetic Clinic One',
    'Synthetic Location One',
    'Synthetic Doctor A',
    (string) $apptBooked,
    (string) $apptArrived,
    (string) $apptOther,
    '0',
    $bookedName,
    $arrivedName,
    (string) $act390,
    (string) $skip390,
    (string) $act768,
    (string) $skip768,
    (string) $act1366,
    (string) $skip1366,
]);
$otherLine = implode('|', [
    dp_field($loginOther, 'login'),
    dp_field($passOther, 'pass'),
    (string) $userOther,
    (string) $clinOther,
    (string) $oneClinic,
    (string) $oneLoc,
    (string) $visitOther,
    (string) $visitOwn,
    dp_field($today, 'today'),
    'Synthetic Clinic One',
    'Synthetic Location One',
    'Synthetic Doctor B',
    (string) $apptOther,
    '0',
    (string) $apptBooked,
    (string) $apptArrived,
    $otherName,
    'none',
]);
$multiLine = implode('|', [
    dp_field($loginMulti, 'login'),
    dp_field($passMulti, 'pass'),
    (string) $userMulti,
    (string) $clinMulti,
    (string) $multiClinic,
    (string) $locA,
    (string) $locB,
    (string) $visitA,
    (string) $visitB,
    (string) $visitColleague,
    (string) $inactiveLoc,
    dp_field($today, 'today'),
    'Synthetic Clinic Multi',
    'Synthetic Location A',
    'Synthetic Location B',
    (string) $apptA,
    (string) $apptB,
    (string) $apptColleague,
    $betaName,
]);
$public = implode('|', [
    $url,
    (string) $oneClinic,
    (string) $oneLoc,
    (string) $visitOwn,
    (string) $visitOther,
    (string) $multiClinic,
    (string) $locA,
    (string) $locB,
    (string) $visitA,
    (string) $visitB,
    $today,
]);

file_put_contents(
    '/tmp/doctor-portal.env',
    'DOCTOR_PORTAL_URL=' . $url . "\n"
    . 'STAFF_PORTAL_URL=' . $staffUrl . "\n"
    . 'STAFF_SECRETARY=' . implode('|', [$loginSecretary, $passSecretary, (string) $userSecretary]) . "\n"
    . 'DOCTOR_ONE=' . $oneLine . "\n"
    . 'DOCTOR_OTHER=' . $otherLine . "\n"
    . 'DOCTOR_MULTI=' . $multiLine . "\n"
    . 'DOCTOR_PORTAL_PUBLIC=' . $public . "\n"
);

echo 'fixture: doctor-portal clinic_one=' . $oneClinic . ' loc=' . $oneLoc
    . ' visits=' . $visitOwn . ',' . $visitOther
    . ' clinic_multi=' . $multiClinic . ' locA=' . $locA . ' locB=' . $locB
    . ' inactive=' . $inactiveLoc . ' today=' . $today . "\n";

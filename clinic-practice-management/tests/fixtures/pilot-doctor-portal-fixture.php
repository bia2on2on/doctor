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
$visitA = $visit($multiClinic, $locA, $clinMulti, $patient($multiClinic, 'SYN-DP-MA-' . $uniq, $mobile('0913')));
$visitB = $visit($multiClinic, $locB, $clinMulti, $patient($multiClinic, 'SYN-DP-MB-' . $uniq, $mobile('0914')));
$visitColleague = $visit($multiClinic, $locB, $clinColleague, $patient($multiClinic, 'SYN-DP-MC-' . $uniq, $mobile('0915')));

$ids = [$visitOwn, $visitOther, $visitA, $visitB, $visitColleague];
if (count($ids) !== count(array_unique($ids))) {
    dp_fail('visit ids must be distinct');
}

$url = \ClinicCore\Frontend\DoctorPortalShell::portal_url();
if (!is_string($url) || !str_starts_with($url, 'http') || str_contains($url, '/wp-admin/')) {
    dp_fail('portal url is not a frontend permalink');
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
    . 'DOCTOR_ONE=' . $oneLine . "\n"
    . 'DOCTOR_OTHER=' . $otherLine . "\n"
    . 'DOCTOR_MULTI=' . $multiLine . "\n"
    . 'DOCTOR_PORTAL_PUBLIC=' . $public . "\n"
);

echo 'fixture: doctor-portal clinic_one=' . $oneClinic . ' loc=' . $oneLoc
    . ' visits=' . $visitOwn . ',' . $visitOther
    . ' clinic_multi=' . $multiClinic . ' locA=' . $locA . ' locB=' . $locB
    . ' inactive=' . $inactiveLoc . ' today=' . $today . "\n";

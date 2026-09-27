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

$patientIds = [];
$patientIndex = 0;
foreach (['a', 'b'] as $tag) {
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

// Tehran Location: exactly two of TODAY's booked rows (first is express).
// Slots sit in the NEAR FUTURE of the Location-local day: the EXISTING ER-06
// check-in semantics treat an arrival after slot start + per-Clinic grace as a
// late arrival (no_show + walk-in-like visit), so the happy-path journey must
// arrive within the grace window like a real reception desk.
$nowTehran = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
$slotExpress = (int) $nowTehran->format('H') >= 23 ? $nowTehran->setTime(23, 55) : $nowTehran->add(new DateInterval('PT10M'));
$slotPlain = (int) $nowTehran->format('H') >= 23 ? $nowTehran->setTime(23, 57) : $nowTehran->add(new DateInterval('PT30M'));
$apptExpress = null;
$apptPlain = null;
foreach ([['a', $slotExpress->format('H:i:s'), 1], ['b', $slotPlain->format('H:i:s'), 0]] as $spec) {
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
    } else {
        $apptPlain = $apptId;
    }
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
    . 'RECEPTION_PUBLIC=' . implode('|', [
        (string) $clinicId,
        (string) $locTehran,
        (string) $locTokyo,
        (string) $apptExpress,
        (string) $apptPlain,
        $todayTehran,
        $todayTokyo,
    ]) . "\n"
);

echo 'fixture: reception clinic=' . $clinicId
    . ' loc_tehran=' . $locTehran . ' loc_tokyo=' . $locTokyo
    . ' appts=' . $apptExpress . ',' . $apptPlain
    . ' today_tehran=' . $todayTehran . ' today_tokyo=' . $todayTokyo . "\n";

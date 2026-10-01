<?php
/**
 * Synthetic Phase 14 Reports fixture for the existing Pilot Chromium job.
 *
 * One Organization with three Clinics (Alpha, Beta, Gamma), each with one
 * Location, one Clinician and waiting visits on ONE fixed explicit business
 * date (never "today"):
 *   Alpha: 600s + 300s  -> avg 450s over 2 visits
 *   Beta : 120s         -> avg 120s over 1 visit
 *   Gamma: 900s         -> never granted to the synthetic reporters
 *
 * Two synthetic report readers (global cpms_report_read through the existing
 * Clinic Manager role + ACTIVE memberships):
 *   multi  : Alpha + Beta  (N > 1 -> explicit Clinic selection)
 *   single : Alpha only    (exactly one eligible Clinic)
 *
 * Stdout is redacted. Credentials go only to /tmp/reports.env.
 */

require getenv('WP_HOME') . '/wp-load.php';

global $wpdb;

$db   = \ClinicCore\Bootstrap\App::db();
$now  = $db->nowUtcSql();
$uniq = substr(bin2hex(random_bytes(4)), 0, 8);
$date = '2026-03-14';

function rp_fail(string $msg): void
{
    $safe = preg_replace('/\d{10,}/', '[redacted]', $msg) ?? $msg;
    echo 'REPORTS_FIXTURE_ERROR: ' . $safe . "\n";
    exit(1);
}

function rp_insert(\wpdb $wpdb, string $table, array $row, string $label): int
{
    if (false === $wpdb->insert($table, $row)) {
        rp_fail($label . ' insert failed');
    }

    return (int) $wpdb->insert_id;
}

function rp_user(string $login, string $pass): int
{
    $userId = wp_create_user($login, $pass, $login . '@pilot.local');
    if (is_wp_error($userId)) {
        rp_fail('user create failed');
    }
    (new WP_User((int) $userId))->set_role(\ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER);

    return (int) $userId;
}

$orgId = rp_insert($wpdb, $db->table('cpms_organizations'), [
    'name' => 'Synthetic Reports Org', 'slug' => 'rp-org-' . $uniq, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
], 'organization');

$clinics = [];
foreach (['alpha' => 'Synthetic Reports Alpha', 'beta' => 'Synthetic Reports Beta', 'gamma' => 'Synthetic Reports Gamma'] as $key => $name) {
    $clinicId = rp_insert($wpdb, $db->table('cpms_clinics'), [
        'organization_id' => $orgId, 'name' => $name, 'slug' => 'rp-' . $key . '-' . $uniq, 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now,
    ], $key . ' clinic');
    $locationId = rp_insert($wpdb, $db->table('cpms_locations'), [
        'clinic_id' => $clinicId, 'name' => $name . ' Location', 'slug' => 'rp-loc-' . $key . '-' . $uniq, 'timezone' => 'Asia/Tehran',
        'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ], $key . ' location');
    $clinicianId = rp_insert($wpdb, $db->table('cpms_clinicians'), [
        'clinic_id' => $clinicId, 'full_name' => 'Synthetic Reports Doctor ' . $key, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ], $key . ' clinician');
    $clinics[$key] = ['id' => $clinicId, 'name' => $name, 'location' => $locationId, 'clinician' => $clinicianId];
}

$visit = static function (string $key, string $waitingSince, string $calledAt) use ($wpdb, $db, $now, $date, $clinics, $uniq): void {
    $c = $clinics[$key];
    $patientId = rp_insert($wpdb, $db->table('cpms_patients'), [
        'clinic_id' => $c['id'], 'mrn' => 'SYN-RP-' . strtoupper($key) . '-' . bin2hex(random_bytes(3)), 'first_name' => 'Synthetic Reports',
        'last_name' => 'Patient ' . $uniq, 'mobile' => '09' . random_int(1000000000, 9999999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ], $key . ' patient');
    rp_insert($wpdb, $db->table('cpms_visits'), [
        'clinic_id' => $c['id'], 'location_id' => $c['location'], 'clinician_id' => $c['clinician'], 'patient_id' => $patientId,
        'source' => 'walk_in', 'status' => 'waiting', 'visit_date' => $date,
        'check_in_at' => $date . ' ' . $waitingSince . '.000', 'waiting_since' => $date . ' ' . $waitingSince . '.000',
        'called_at' => $date . ' ' . $calledAt . '.000', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ], $key . ' visit');
};
$visit('alpha', '10:00:00', '10:10:00');
$visit('alpha', '11:00:00', '11:05:00');
$visit('beta', '09:00:00', '09:02:00');
$visit('gamma', '08:00:00', '08:15:00');

$memberships = \ClinicCore\Bootstrap\App::membership_service();
$multiLogin  = 'rpmulti' . $uniq;
$multiPass   = 'RpMulti-' . $uniq . '-2026!';
$singleLogin = 'rpsingle' . $uniq;
$singlePass  = 'RpSingle-' . $uniq . '-2026!';
$multiUser   = rp_user($multiLogin, $multiPass);
$singleUser  = rp_user($singleLogin, $singlePass);
try {
    $memberships->create_membership($clinics['alpha']['id'], $multiUser, 'cpms_manager');
    $memberships->create_membership($clinics['beta']['id'], $multiUser, 'cpms_manager');
    $memberships->create_membership($clinics['alpha']['id'], $singleUser, 'cpms_manager');
} catch (Throwable $e) {
    rp_fail('membership: ' . $e->getMessage());
}

$portalUrl = add_query_arg(
    \ClinicCore\Frontend\StaffPortalShell::MODULE_PARAM,
    \ClinicCore\Frontend\StaffPortalShell::MODULE_REPORTS,
    \ClinicCore\Frontend\StaffPortalShell::portal_url()
);
$env = [
    'REPORTS_URL'          => $portalUrl,
    'REPORTS_MULTI_LOGIN'  => $multiLogin,
    'REPORTS_MULTI_PASS'   => $multiPass,
    'REPORTS_SINGLE_LOGIN' => $singleLogin,
    'REPORTS_SINGLE_PASS'  => $singlePass,
    'REPORTS_DATE'         => $date,
    'REPORTS_ALPHA_ID'     => (string) $clinics['alpha']['id'],
    'REPORTS_ALPHA_NAME'   => $clinics['alpha']['name'],
    'REPORTS_BETA_ID'      => (string) $clinics['beta']['id'],
    'REPORTS_BETA_NAME'    => $clinics['beta']['name'],
    'REPORTS_GAMMA_ID'     => (string) $clinics['gamma']['id'],
    'REPORTS_GAMMA_NAME'   => $clinics['gamma']['name'],
];
$lines = [];
foreach ($env as $key => $value) {
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        rp_fail('unsafe environment value');
    }
    $lines[] = $key . '=' . $value;
}
file_put_contents('/tmp/reports.env', implode("\n", $lines) . "\n");
echo 'fixture: reports clinics=3 reporters=2 (multi=2 eligible, single=1 eligible) visits_alpha=2 visits_beta=1 visits_gamma=1 date=' . $date . "\n";

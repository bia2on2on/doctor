<?php
/**
 * Synthetic Day-2 Operational Workflows fixture for the Pilot Chromium gate.
 *
 * Seeds an isolated Organization + two Clinics (Clinic A with 2 active Tehran
 * Locations + Foreign Clinic B) and exercises the Owner's Access Policy:
 *  - Every non-patient operational persona (Doctor 1, Doctor 2, Deny-Doctor,
 *    Receptionist, Accountant) is a real WordPress `subscriber` with active
 *    scoped CPMS membership — ZERO global WP clinical/reception/finance roles.
 *  - Public Booking page with `[cpms_public_booking clinic_id="..."]` exposing
 *    both clinician switcher (2 clinicians) and Location switcher (2 Locations).
 *  - Authenticated pure-patient reschedule journey with concurrent slot
 *    collision recovery + Clinic `booking.max_future_days = 30` window check.
 *  - Receptionist daily clinician filter + authorized no-show + blank-reason
 *    validation + cross-Clinic refusal.
 *  - Doctor Visit Workspace medical-history panel: own `doctor_private` note
 *    visible, colleague's `doctor_private` note hidden, `RX_READ` prescription
 *    visibility, and `DENY > GRANT` override persona.
 *  - Accountant Staff Portal reconciliation: scoped Location totals, Location
 *    switching, cross-Clinic denial, and zero patient PHI leakage.
 *
 * Stdout is redacted (IDs/booleans only). Credentials are written only to
 * `/tmp/day2-workflows.env`.
 */

declare(strict_types=1);

require getenv('WP_HOME') . '/wp-load.php';

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use ClinicCore\Infrastructure\Repository\ClinicalNoteRepository;
use ClinicCore\Infrastructure\Repository\FollowUpRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Settings\Settings;

global $wpdb;

$db   = App::db();
$now  = $db->nowUtcSql();
$uniq = substr(bin2hex(random_bytes(4)), 0, 8);

function d2w_fail(string $msg): void
{
    $safe = preg_replace('/\d{10,}/', '[redacted]', $msg) ?? $msg;
    fwrite(STDERR, 'DAY2_FIXTURE_ERROR: ' . $safe . "\n");
    exit(1);
}

/**
 * @param array<int, mixed> $args
 */
function d2w_insert(\wpdb $wpdb, string $sql, array $args, string $label): int
{
    $prepared = $wpdb->prepare($sql, ...$args);
    if (!is_string($prepared) || $wpdb->query($prepared) === false) {
        d2w_fail($label . ': ' . ($wpdb->last_error !== '' ? $wpdb->last_error : 'prepare/query failed'));
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) {
        d2w_fail($label . ': insert id missing');
    }

    return $id;
}

function d2w_user(string $prefix, string $pass, string $wpRole): int
{
    $login  = $prefix . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $userId = wp_create_user($login, $pass, $login . '@pilot-day2.local');
    if (is_wp_error($userId) || (int) $userId <= 0) {
        d2w_fail('user create failed for ' . $prefix);
    }
    $u = new \WP_User((int) $userId);
    $u->set_role($wpRole);

    return (int) $userId;
}

$tz         = new \DateTimeZone('Asia/Tehran');
$nowLocal   = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($tz);
$today      = $nowLocal->format('Y-m-d');
$tomorrow   = $nowLocal->modify('+1 day')->format('Y-m-d');
$day2       = $nowLocal->modify('+2 days')->format('Y-m-d');
$day3       = $nowLocal->modify('+3 days')->format('Y-m-d');
$day4       = $nowLocal->modify('+4 days')->format('Y-m-d');
$day40      = $nowLocal->modify('+40 days')->format('Y-m-d');
$pastDate1  = $nowLocal->modify('-10 days')->format('Y-m-d');
$pastDate2  = $nowLocal->modify('-5 days')->format('Y-m-d');
$pastDate3  = $nowLocal->modify('-2 days')->format('Y-m-d');

$orgId = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
    ['Synthetic Day2 Org ' . $uniq, 'd2w-org-' . $uniq, 'active', $now, $now],
    'organization'
);

$clinicA = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, %s, NULL, %s, %s)',
    ['کلینیک روز دوم الف ' . $uniq, 'd2w-clinic-a-' . $uniq, 'Asia/Tehran', $orgId, 'تهران، ولیعصر', $now, $now],
    'clinic A'
);

$clinicB = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, %s, NULL, %s, %s)',
    ['کلینیک روز دوم ب ' . $uniq, 'd2w-clinic-b-' . $uniq, 'Asia/Tehran', $orgId, 'شیراز، زند', $now, $now],
    'clinic B'
);

if ($clinicA <= 1 || $clinicB <= 1 || $clinicA === $clinicB) {
    d2w_fail('clinic ids must be nontrivial and distinct');
}

// Configure Clinic A booking window: max_future_days = 30, min_lead_hours = 1.
$settingsA = App::settingsFactory()->forClinic($clinicA);
$settingsA->set('booking.max_future_days', 30);
$settingsA->set('booking.min_lead_hours', 1);
$settingsA->set('booking.reschedule_deadline_hours', 24);
Settings::flushCache();

$locA1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, NULL, %s, %d, %d, %s, %s)',
    [$clinicA, 'شعبه مرکزی ولیعصر', 'd2w-loc-a1-' . $uniq, 'تهران، خیابان ولیعصر، پلاک ۱۲', 'Asia/Tehran', 1, 1, $now, $now],
    'loc A1'
);

$locA2 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, NULL, %s, %d, %d, %s, %s)',
    [$clinicA, 'شعبه شمال تجریش', 'd2w-loc-a2-' . $uniq, 'تهران، میدان تجریش، پلاک ۴', 'Asia/Tehran', 0, 1, $now, $now],
    'loc A2'
);

$locB1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, NULL, %s, %d, %d, %s, %s)',
    [$clinicB, 'شعبه خارجی شیراز', 'd2w-loc-b1-' . $uniq, 'شیراز، بلوار زند', 'Asia/Tehran', 1, 1, $now, $now],
    'loc B1'
);

$pass = 'D2w-' . $uniq . '-2026!';

// Operational personas — ALL WordPress `subscriber` users!
$doc1UserId    = d2w_user('d2doc1', $pass, 'subscriber');
$doc2UserId    = d2w_user('d2doc2', $pass, 'subscriber');
$docDenyUserId = d2w_user('d2docdeny', $pass, 'subscriber');
$secUserId     = d2w_user('d2sec', $pass, 'subscriber');
$accUserId     = d2w_user('d2acc', $pass, 'subscriber');
$ptUserId      = d2w_user('d2pt', $pass, RolesAndCapabilities::ROLE_PATIENT);

$doc1Login    = (string) get_userdata($doc1UserId)->user_login;
$doc2Login    = (string) get_userdata($doc2UserId)->user_login;
$docDenyLogin = (string) get_userdata($docDenyUserId)->user_login;
$secLogin     = (string) get_userdata($secUserId)->user_login;
$accLogin     = (string) get_userdata($accUserId)->user_login;
$ptLogin      = (string) get_userdata($ptUserId)->user_login;

$clinician1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, %s, %s)',
    [$clinicA, $doc1UserId, 'دکتر بهرام سپهری', 'داخلی', '۱۰۱', $now, $now],
    'clinician 1'
);

$clinician2 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, %s, %s)',
    [$clinicA, $doc2UserId, 'دکتر سارا کاظمی', 'قلب و عروق', '۱۰۲', $now, $now],
    'clinician 2'
);

$clinicianDeny = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, %s, %s)',
    [$clinicA, $docDenyUserId, 'دکتر امید نادری', 'عمومی', '۱۰۳', $now, $now],
    'clinician deny'
);

$clinicianForeign = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_clinicians') . ' (clinic_id, wp_user_id, full_name, specialty, room, is_active, created_at, updated_at) VALUES (%d, NULL, %s, %s, NULL, 1, %s, %s)',
    [$clinicB, 'دکتر کلینیک ب', 'داخلی', $now, $now],
    'clinician foreign'
);

$memSvc     = App::membership_service();
$memDoc1    = $memSvc->create_membership($clinicA, $doc1UserId, 'cpms_doctor');
$memDoc2    = $memSvc->create_membership($clinicA, $doc2UserId, 'cpms_doctor');
$memDocDeny = $memSvc->create_membership($clinicA, $docDenyUserId, 'cpms_doctor');
$memSec     = $memSvc->create_membership($clinicA, $secUserId, 'cpms_secretary');
$memAcc     = $memSvc->create_membership($clinicA, $accUserId, 'cpms_accountant');

// Explicit DENY overrides on PRIVATE_NOTE_READ and RX_READ for $docDenyUserId (`DENY > GRANT`).
$memSvc->set_capability($memDocDeny, RolesAndCapabilities::PRIVATE_NOTE_READ, 'deny');
$memSvc->set_capability($memDocDeny, RolesAndCapabilities::RX_READ, 'deny');

$memSvc->assign_clinician_locations($clinician1, [$locA1, $locA2], $locA1);
$memSvc->assign_clinician_locations($clinician2, [$locA1], $locA1);
$memSvc->assign_clinician_locations($clinicianDeny, [$locA1], $locA1);

// Seed active weekly schedule rows so public availability(clinicianId, null, null) resolves default window.
foreach ([
    [$clinician1, $locA1],
    [$clinician1, $locA2],
    [$clinician2, $locA1],
] as [$cid, $lid]) {
    for ($dow = 0; $dow <= 6; $dow++) {
        d2w_insert(
            $wpdb,
            'INSERT INTO ' . $db->table('cpms_schedule')
            . ' (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, buffer_pre_min, buffer_post_min, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, %s, %s, %d, %d, 1, 0, 0, %s, %s)',
            [$clinicA, $lid, $cid, $dow, '08:00:00', '20:00:00', 20, 1, $now, $now],
            'schedule ' . $cid . '-' . $lid . '-' . $dow
        );
    }
}

$seedSlot = static function (int $clinicId, int $locId, int $clinicianId, string $date, string $time, int $capacity = 1, int $booked = 0) use ($wpdb, $db, $now): int {
    return d2w_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_schedule_slots')
        . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)'
        . ' VALUES (%d, %d, %d, %s, %s, 20, %d, %d, 0, 1, %s, %s, %s)',
        [$clinicId, $locId, $clinicianId, $date, $time, $capacity, $booked, 'manual', $now, $now],
        'slot ' . $date . ' ' . $time
    );
};

// --- Journey A1: Public Booking Page + Slots (Clinician 1 @ Loc A1 & Loc A2, Clinician 2 @ Loc A1) ---
$pubPageId = wp_insert_post([
    'post_type'    => 'page',
    'post_status'  => 'publish',
    'post_title'   => 'رزرو نوبت روز دوم ' . $uniq,
    'post_name'    => 'd2w-public-booking-' . $uniq,
    'post_content' => '[cpms_public_booking clinic_id="' . $clinicA . '"]',
]);
if (!is_int($pubPageId) || $pubPageId <= 0) {
    d2w_fail('failed to create public booking page');
}
$pubBookingUrl = (string) get_permalink($pubPageId);
if ($pubBookingUrl === '') {
    d2w_fail('public booking permalink empty');
}

// Use day2 (+2 days) so min_lead_hours=1 is always comfortably satisfied regardless of wall-clock hour.
$pubSlotDoc1Loc1 = $seedSlot($clinicA, $locA1, $clinician1, $day2, '09:15:00', 1, 0);
$pubSlotDoc1Loc2 = $seedSlot($clinicA, $locA2, $clinician1, $day2, '14:15:00', 1, 0);
$pubSlotDoc2Loc1 = $seedSlot($clinicA, $locA1, $clinician2, $day2, '16:15:00', 1, 0);

// --- Journey A2: Authenticated Patient Reschedule + Collision Recovery ---
$ptMobile  = '0912' . sprintf('%07d', random_int(2000000, 5999999));
$patientId = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients')
    . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
    [$clinicA, 'MR-D2W-PT-' . $uniq, 'مینا', 'شریفی', $ptMobile, 'active', $now, $now],
    'reschedule patient'
);
$db->insert('cpms_patient_user_links', [
    'clinic_id'      => $clinicA,
    'patient_id'     => $patientId,
    'wp_user_id'     => $ptUserId,
    'mobile_at_link' => $ptMobile,
    'is_primary'     => 1,
    'linked_at'      => $now,
]);

$reschedCurSlot      = $seedSlot($clinicA, $locA1, $clinician1, $day3, '09:00:00', 1, 1);
$reschedCollideSlot  = $seedSlot($clinicA, $locA1, $clinician1, $day4, '10:00:00', 1, 0);
$reschedCleanSlot    = $seedSlot($clinicA, $locA1, $clinician1, $day4, '11:30:00', 1, 0);
$reschedBeyondWindow = $seedSlot($clinicA, $locA1, $clinician1, $day40, '10:00:00', 1, 0);

$reschedApptId = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_appointments')
    . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %d, %d, %s, %s, 20, %s, %s, 0, %s, %s, %s)',
    [$clinicA, $locA1, 'AP-D2R-' . $uniq, $patientId, $clinician1, $reschedCurSlot, $ptUserId, $day3, '09:00:00', '09:20:00', 'confirmed', $now, $now, $now],
    'reschedule source appointment'
);

// --- Journey B: Receptionist Daily Clinician Filter + No-Show + Cross-Clinic Denial ---
$secPt1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients')
    . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
    [$clinicA, 'MR-D2W-S1-' . $uniq, 'آرش', 'کریمی', '0912' . sprintf('%07d', random_int(6000000, 6999999)), 'active', $now, $now],
    'sec patient 1'
);
$secPt2 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients')
    . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
    [$clinicA, 'MR-D2W-S2-' . $uniq, 'الهام', 'جعفری', '0912' . sprintf('%07d', random_int(7000000, 7999999)), 'active', $now, $now],
    'sec patient 2'
);
$foreignPt = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients')
    . ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)'
    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
    [$clinicB, 'MR-D2W-FB-' . $uniq, 'بیمار', 'خارجی', '0912' . sprintf('%07d', random_int(8000000, 8999999)), 'active', $now, $now],
    'foreign patient'
);

$secSlot1    = $seedSlot($clinicA, $locA1, $clinician1, $today, '08:20:00', 1, 1);
$secSlot2    = $seedSlot($clinicA, $locA1, $clinician2, $today, '08:40:00', 1, 1);
$foreignSlot = $seedSlot($clinicB, $locB1, $clinicianForeign, $today, '09:00:00', 1, 1);

$secApptDoc1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_appointments')
    . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, reason, is_walkin_express, confirmed_at, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %d, 0, %s, %s, 20, %s, %s, %s, 0, %s, %s, %s)',
    [$clinicA, $locA1, 'AP-D2S1-' . $uniq, $secPt1, $clinician1, $secSlot1, $today, '08:20:00', '08:40:00', 'confirmed', 'چکاپ دوره‌ای', $now, $now, $now],
    'sec appt doc1'
);
$secApptDoc2 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_appointments')
    . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %d, 0, %s, %s, 20, %s, %s, 0, %s, %s, %s)',
    [$clinicA, $locA1, 'AP-D2S2-' . $uniq, $secPt2, $clinician2, $secSlot2, $today, '08:40:00', '09:00:00', 'confirmed', $now, $now, $now],
    'sec appt doc2'
);
$foreignApptId = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_appointments')
    . ' (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, wp_user_id, slot_date, slot_time, duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %d, 0, %s, %s, 20, %s, %s, 0, %s, %s, %s)',
    [$clinicB, $locB1, 'AP-D2FB-' . $uniq, $foreignPt, $clinicianForeign, $foreignSlot, $today, '09:00:00', '09:20:00', 'confirmed', $now, $now, $now],
    'foreign appt'
);

// --- Journey C: Subscriber Doctor Medical History + Private Notes + RX_READ + DENY ---
$medPt = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients')
    . ' (clinic_id, mrn, first_name, last_name, mobile, blood_group, medication_allergies, chronic_conditions, medical_history, status, created_at, updated_at)'
    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
    [
        $clinicA,
        'MR-D2W-MH-' . $uniq,
        'کیوان',
        'رحمانی',
        '0912' . sprintf('%07d', random_int(9000000, 9499999)),
        'A+',
        wp_json_encode(['پنی‌سیلین'], JSON_UNESCAPED_UNICODE),
        wp_json_encode(['فشار خون بالا'], JSON_UNESCAPED_UNICODE),
        'سابقهٔ تپش قلب خفیف',
        'active',
        $now,
        $now,
    ],
    'medical history patient'
);

// Separate patient for Deny-Doctor active visit (with shared prior history on that patient too)
// because cpms_visits enforces at most one active visit per patient if constrained, so give Deny-Doctor
// its own patient with identical rich prior history!
$medPtDeny = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_patients')
    . ' (clinic_id, mrn, first_name, last_name, mobile, blood_group, medication_allergies, status, created_at, updated_at)'
    . ' VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
    [
        $clinicA,
        'MR-D2W-MHD-' . $uniq,
        'پریسا',
        'مهدوی',
        '0912' . sprintf('%07d', random_int(9500000, 9899999)),
        'O+',
        wp_json_encode(['آسپرین'], JSON_UNESCAPED_UNICODE),
        'active',
        $now,
        $now,
    ],
    'medical history patient deny'
);

$seedVisit = static function (int $clinicId, int $locId, int $ptId, int $cid, string $vdate, string $status, int $active) use ($wpdb, $db, $now): int {
    return d2w_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_visits')
        . ' (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, active, created_at, updated_at)'
        . ' VALUES (%d, %d, %d, %d, NULL, %s, %s, %s, %s, %d, %s, %s)',
        [$clinicId, $locId, $cid, $ptId, 'walk_in', $status, $vdate, $now, $active, $now, $now],
        'visit ' . $ptId . '-' . $cid . '-' . $status
    );
};

$priorVisitDoc1    = $seedVisit($clinicA, $locA1, $medPt, $clinician1, $pastDate1, 'consultation_completed', 0);
$priorVisitDoc2    = $seedVisit($clinicA, $locA1, $medPt, $clinician2, $pastDate2, 'consultation_completed', 0);
$activeVisitDoc1   = $seedVisit($clinicA, $locA1, $medPt, $clinician1, $today, 'in_consultation', 1);

$priorVisitDenyOwn = $seedVisit($clinicA, $locA1, $medPtDeny, $clinicianDeny, $pastDate3, 'consultation_completed', 0);
$priorVisitDenyCol = $seedVisit($clinicA, $locA1, $medPtDeny, $clinician2, $pastDate2, 'consultation_completed', 0);
$activeVisitDeny   = $seedVisit($clinicA, $locA1, $medPtDeny, $clinicianDeny, $today, 'in_consultation', 1);

$notesRepo = new ClinicalNoteRepository($db);
$notesRepo->insert($clinicA, [
    'visit_id'              => $priorVisitDoc1,
    'patient_id'            => $medPt,
    'clinician_id'          => $clinician1,
    'category'              => 'diagnosis',
    'visibility'            => 'doctor_private',
    'content_text'          => 'یادداشت خصوصی دکتر سپهری قابل مشاهده برای خودش',
    'created_by_wp_user_id' => $doc1UserId,
]);
$notesRepo->insert($clinicA, [
    'visit_id'              => $priorVisitDoc2,
    'patient_id'            => $medPt,
    'clinician_id'          => $clinician2,
    'category'              => 'diagnosis',
    'visibility'            => 'patient_visible',
    'content_text'          => 'تشخیص عمومی دکتر کاظمی در پرونده قبلی',
    'created_by_wp_user_id' => $doc2UserId,
]);
$notesRepo->insert($clinicA, [
    'visit_id'              => $priorVisitDoc2,
    'patient_id'            => $medPt,
    'clinician_id'          => $clinician2,
    'category'              => 'clinical_note',
    'visibility'            => 'doctor_private',
    'content_text'          => 'یادداشت محرمانه دکتر کاظمی هرگز افشا نشود',
    'created_by_wp_user_id' => $doc2UserId,
]);

$notesRepo->insert($clinicA, [
    'visit_id'              => $priorVisitDenyOwn,
    'patient_id'            => $medPtDeny,
    'clinician_id'          => $clinicianDeny,
    'category'              => 'diagnosis',
    'visibility'            => 'doctor_private',
    'content_text'          => 'یادداشت خصوصی دکتر نادری مسدود با DENY',
    'created_by_wp_user_id' => $docDenyUserId,
]);
$notesRepo->insert($clinicA, [
    'visit_id'              => $priorVisitDenyCol,
    'patient_id'            => $medPtDeny,
    'clinician_id'          => $clinician2,
    'category'              => 'diagnosis',
    'visibility'            => 'patient_visible',
    'content_text'          => 'یادداشت عمومی قابل مشاهده برای دکتر نادری',
    'created_by_wp_user_id' => $doc2UserId,
]);

(new FollowUpRepository($db))->insert($clinicA, [
    'visit_id'       => $priorVisitDoc2,
    'patient_id'     => $medPt,
    'clinician_id'   => $clinician2,
    'is_needed'      => 1,
    'suggested_date' => $today,
    'reason'         => 'پایش فشار خون ماهانه',
]);

foreach ([
    [$priorVisitDoc2, $medPt, 'ValsartanD2'],
    [$priorVisitDenyCol, $medPtDeny, 'AmlodipineDenyD2'],
] as [$rxVisitId, $rxPatientId, $drugName]) {
    $rxId = d2w_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_prescriptions')
        . ' (clinic_id, prescription_number, visit_id, patient_id, clinician_id, status, is_patient_visible, finalized_at, created_at, updated_at)'
        . ' VALUES (%d, %s, %d, %d, %d, %s, 1, %s, %s, %s)',
        [$clinicA, 'RX-D2W-' . substr(bin2hex(random_bytes(3)), 0, 5), $rxVisitId, $rxPatientId, $clinician2, 'finalized', $now, $now, $now],
        'prescription ' . $drugName
    );
    d2w_insert(
        $wpdb,
        'INSERT INTO ' . $db->table('cpms_prescription_items')
        . ' (prescription_id, generic_name, strength, form, dose, frequency, route, duration_days, source, sort_order, created_at)'
        . ' VALUES (%d, %s, %s, %s, %s, %s, %s, 30, %s, 0, %s)',
        [$rxId, $drugName, '80mg', 'tablet', '1 عدد', 'روزانه', 'oral', 'manual', $now],
        'prescription item ' . $drugName
    );
}

// --- Journey D: Subscriber Accountant Reconciliation (Loc A1 vs Loc A2 vs Foreign Clinic B) ---
$finVisitA1 = $seedVisit($clinicA, $locA1, $secPt1, $clinician1, $today, 'consultation_completed', 0);
$finVisitA2 = $seedVisit($clinicA, $locA2, $secPt2, $clinician1, $today, 'consultation_completed', 0);
$finVisitB1 = $seedVisit($clinicB, $locB1, $foreignPt, $clinicianForeign, $today, 'consultation_completed', 0);

$invNoA1 = 'INV-D2W-A1-' . $uniq;
$invNoA2 = 'INV-D2W-A2-' . $uniq;
$invNoB1 = 'INV-D2W-B1-' . $uniq;

$invA1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_invoices')
    . ' (clinic_id, location_id, invoice_number, patient_id, visit_id, status, subtotal, total, currency, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
    [$clinicA, $locA1, $invNoA1, $secPt1, $finVisitA1, 'partial', '600000.00', '600000.00', 'IRR', '450000.00', '150000.00', $secUserId, $now, $now],
    'invoice A1'
);
$invA2 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_invoices')
    . ' (clinic_id, location_id, invoice_number, patient_id, visit_id, status, subtotal, total, currency, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
    [$clinicA, $locA2, $invNoA2, $secPt2, $finVisitA2, 'paid', '800000.00', '800000.00', 'IRR', '800000.00', '0.00', $secUserId, $now, $now],
    'invoice A2'
);
$invB1 = d2w_insert(
    $wpdb,
    'INSERT INTO ' . $db->table('cpms_invoices')
    . ' (clinic_id, location_id, invoice_number, patient_id, visit_id, status, subtotal, total, currency, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)'
    . ' VALUES (%d, %d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
    [$clinicB, $locB1, $invNoB1, $foreignPt, $finVisitB1, 'paid', '990000.00', '990000.00', 'IRR', '990000.00', '0.00', $secUserId, $now, $now],
    'invoice B1'
);

$payNoA1Cash = 'PAY-D2W-A1C-' . $uniq;
$payNoA1Pos  = 'PAY-D2W-A1P-' . $uniq;
$payNoA1Void = 'PAY-D2W-A1V-' . $uniq;
$payNoA2Pos  = 'PAY-D2W-A2P-' . $uniq;
$payNoB1Pos  = 'PAY-D2W-B1P-' . $uniq;

$payRepo = new PaymentRepository($db);
$payRepo->insert($clinicA, [
    'location_id'            => $locA1,
    'payment_number'         => $payNoA1Cash,
    'invoice_id'             => $invA1,
    'patient_id'             => $secPt1,
    'amount'                 => '250000.00',
    'method'                 => 'cash',
    'status'                 => 'captured',
    'idempotency_key'        => 'idem-d2w-a1c-' . $uniq,
    'received_by_wp_user_id' => $secUserId,
    'paid_at'                => $now,
]);
$payRepo->insert($clinicA, [
    'location_id'            => $locA1,
    'payment_number'         => $payNoA1Pos,
    'invoice_id'             => $invA1,
    'patient_id'             => $secPt1,
    'amount'                 => '200000.00',
    'method'                 => 'card_pos',
    'status'                 => 'captured',
    'idempotency_key'        => 'idem-d2w-a1p-' . $uniq,
    'received_by_wp_user_id' => $secUserId,
    'paid_at'                => $now,
]);
$payRepo->insert($clinicA, [
    'location_id'            => $locA1,
    'payment_number'         => $payNoA1Void,
    'invoice_id'             => $invA1,
    'patient_id'             => $secPt1,
    'amount'                 => '90000.00',
    'method'                 => 'cash',
    'status'                 => 'voided',
    'voided_at'              => $now,
    'void_reason'            => 'ثبت آزمایشی باطل‌شده',
    'idempotency_key'        => 'idem-d2w-a1v-' . $uniq,
    'received_by_wp_user_id' => $secUserId,
    'paid_at'                => $now,
]);
$payRepo->insert($clinicA, [
    'location_id'            => $locA2,
    'payment_number'         => $payNoA2Pos,
    'invoice_id'             => $invA2,
    'patient_id'             => $secPt2,
    'amount'                 => '800000.00',
    'method'                 => 'card_pos',
    'status'                 => 'captured',
    'idempotency_key'        => 'idem-d2w-a2p-' . $uniq,
    'received_by_wp_user_id' => $secUserId,
    'paid_at'                => $now,
]);
$payRepo->insert($clinicB, [
    'location_id'            => $locB1,
    'payment_number'         => $payNoB1Pos,
    'invoice_id'             => $invB1,
    'patient_id'             => $foreignPt,
    'amount'                 => '990000.00',
    'method'                 => 'card_pos',
    'status'                 => 'captured',
    'idempotency_key'        => 'idem-d2w-b1p-' . $uniq,
    'received_by_wp_user_id' => $secUserId,
    'paid_at'                => $now,
]);

$staffUrl     = StaffPortalShell::portal_url();
$receptionUrl = add_query_arg(StaffPortalShell::MODULE_PARAM, StaffPortalShell::MODULE_RECEPTION, $staffUrl);
$doctorUrl    = add_query_arg(StaffPortalShell::MODULE_PARAM, StaffPortalShell::MODULE_DOCTOR, $staffUrl);
$financeUrl   = add_query_arg(StaffPortalShell::MODULE_PARAM, StaffPortalShell::MODULE_FINANCE, $staffUrl);

$envPayload = [
    'DAY2_PUBLIC_URL'          => $pubBookingUrl,
    'DAY2_RECEPTION_URL'       => $receptionUrl,
    'DAY2_DOCTOR_URL'          => $doctorUrl,
    'DAY2_FINANCE_URL'         => $financeUrl,
    'DAY2_CLINIC_A'            => (string) $clinicA,
    'DAY2_CLINIC_B'            => (string) $clinicB,
    'DAY2_LOC_A1'              => (string) $locA1,
    'DAY2_LOC_A2'              => (string) $locA2,
    'DAY2_LOC_B1'              => (string) $locB1,
    'DAY2_CLINICIAN_1'         => (string) $clinician1,
    'DAY2_CLINICIAN_2'         => (string) $clinician2,
    'DAY2_CLINICIAN_DENY'      => (string) $clinicianDeny,
    'DAY2_PUB_SLOT_DOC1_LOC1'  => (string) $pubSlotDoc1Loc1,
    'DAY2_PUB_SLOT_DOC1_LOC2'  => (string) $pubSlotDoc1Loc2,
    'DAY2_PUB_SLOT_DOC2_LOC1'  => (string) $pubSlotDoc2Loc1,
    'DAY2_PASS'                => $pass,
    'DAY2_PT_LOGIN'            => $ptLogin,
    'DAY2_PT_USER_ID'          => (string) $ptUserId,
    'DAY2_RESCHED_APPT'        => (string) $reschedApptId,
    'DAY2_RESCHED_COLLIDE_SLOT'=> (string) $reschedCollideSlot,
    'DAY2_RESCHED_CLEAN_SLOT'  => (string) $reschedCleanSlot,
    'DAY2_RESCHED_BEYOND_SLOT' => (string) $reschedBeyondWindow,
    'DAY2_SEC_LOGIN'           => $secLogin,
    'DAY2_SEC_USER_ID'         => (string) $secUserId,
    'DAY2_SEC_APPT_DOC1'       => (string) $secApptDoc1,
    'DAY2_SEC_APPT_DOC2'       => (string) $secApptDoc2,
    'DAY2_FOREIGN_APPT'        => (string) $foreignApptId,
    'DAY2_DOC1_LOGIN'          => $doc1Login,
    'DAY2_DOC1_USER_ID'        => (string) $doc1UserId,
    'DAY2_ACTIVE_VISIT_DOC1'   => (string) $activeVisitDoc1,
    'DAY2_DOC_DENY_LOGIN'      => $docDenyLogin,
    'DAY2_DOC_DENY_USER_ID'    => (string) $docDenyUserId,
    'DAY2_ACTIVE_VISIT_DENY'   => (string) $activeVisitDeny,
    'DAY2_ACC_LOGIN'           => $accLogin,
    'DAY2_ACC_USER_ID'         => (string) $accUserId,
    'DAY2_PAY_A1_CASH'         => $payNoA1Cash,
    'DAY2_PAY_A1_POS'          => $payNoA1Pos,
    'DAY2_PAY_A1_VOID'         => $payNoA1Void,
    'DAY2_PAY_A2_POS'          => $payNoA2Pos,
    'DAY2_PAY_B1_POS'          => $payNoB1Pos,
];

$lines = [];
foreach ($envPayload as $k => $v) {
    $lines[] = $k . '=' . $v;
}
if (file_put_contents('/tmp/day2-workflows.env', implode("\n", $lines) . "\n") === false) {
    d2w_fail('failed writing /tmp/day2-workflows.env');
}

echo sprintf(
    "DAY2_FIXTURE_OK clinic_a=%d clinic_b=%d loc_a1=%d loc_a2=%d doc1_sub=1 doc2_sub=1 doc_deny_sub=1 sec_sub=1 acc_sub=1\n",
    $clinicA,
    $clinicB,
    $locA1,
    $locA2
);

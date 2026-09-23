<?php
/**
 * Synthetic Slice-4 Profile fixture for the existing Patient Portal pilot.
 *
 * Creates:
 *   - one pure patient with exactly one active link (auto-resolved Profile);
 *   - one pure patient with two active links in two Clinics, plus a foreign
 *     link and an archived link that must not appear in the selector.
 *
 * Stdout is redacted. Secrets (password, mobile, national id) go only to
 * /tmp/portal-profile.env for the following browser step.
 */

require getenv('WP_HOME') . '/wp-load.php';

global $wpdb;

$db = \ClinicCore\Bootstrap\App::db();
$now = $db->nowUtcSql();
$uniq = substr(bin2hex(random_bytes(4)), 0, 8);

function profile_fail(string $msg): void
{
    $safe = preg_replace('/\d{10,}/', '[redacted]', $msg) ?? $msg;
    echo 'PROFILE_FIXTURE_ERROR: ' . $safe . "\n";
    exit(1);
}

if (!\ClinicCore\Domain\Validators\NationalIdValidator::isValid('0000000140')) {
    profile_fail('synthetic checksum id was rejected by the validator');
}
if (\ClinicCore\Domain\Validators\NationalIdValidator::isValid('0000000000')) {
    profile_fail('all-zero id was unexpectedly accepted');
}

$n = hexdec(substr($uniq, 0, 6)) % 1000000;
$mobiles = [
    'one' => '0901' . sprintf('%07d', $n),
    'a' => '0902' . sprintf('%07d', $n),
    'b' => '0903' . sprintf('%07d', $n),
    'f' => '0904' . sprintf('%07d', $n),
    'i' => '0905' . sprintf('%07d', $n),
];

$passOne = 'P9S4One-' . $uniq . '-2026!';
$passMulti = 'P9S4Multi-' . $uniq . '-2026!';

$wpdb->query($wpdb->prepare(
    'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
    'سازمان آزمایشی پروفایل',
    'p9s4-org-' . $uniq,
    'active',
    $now,
    $now
));
$orgId = (int) $wpdb->insert_id;
if ($orgId <= 1) {
    profile_fail('org id must be nontrivial');
}

$makeClinic = static function (string $name, string $slug) use ($wpdb, $db, $orgId, $now): array {
    $clinicOk = $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
        $name,
        $slug,
        'Asia/Tehran',
        $orgId,
        $now,
        $now
    ));
    $clinicId = (int) $wpdb->insert_id;
    if ($clinicOk === false || $clinicId <= 1) {
        profile_fail('clinic insert failed');
    }
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_locations') . ' (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, NULL, NULL, %s, 1, 1, %s, %s)',
        $clinicId,
        $name,
        $slug . '-loc',
        'Asia/Tehran',
        $now,
        $now
    ));
    if ((int) $wpdb->insert_id <= 0) {
        profile_fail('location insert failed: ' . $wpdb->last_error);
    }

    return [$clinicId, $name];
};

[$clinicOne, $clinicOneName] = $makeClinic('مطب آزمایشی تک', 'p9s4-one-' . $uniq);
[$clinicA, $clinicAName] = $makeClinic('مطب آزمایشی الف', 'p9s4-a-' . $uniq);
[$clinicB, $clinicBName] = $makeClinic('مطب آزمایشی ب', 'p9s4-b-' . $uniq);

$makeUser = static function (string $login, string $pass): int {
    $userId = wp_create_user($login, $pass, $login . '@p9s4.local');
    if (is_wp_error($userId)) {
        profile_fail('user create failed');
    }
    $user = new WP_User((int) $userId);
    $user->set_role('cpms_patient');

    return (int) $userId;
};

$makePatient = static function (
    int $clinicId,
    string $mrn,
    string $first,
    string $last,
    string $mobile,
    string $status,
    string $nationalId,
    ?string $address
) use ($wpdb, $db, $now): int {
    // A null/empty national_id becomes '' under wpdb prepare and collides on
    // UNIQUE (clinic_id, national_id). Every seeded row gets its own valid id.
    $ok = $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_patients') . ' (clinic_id, mrn, first_name, last_name, mobile, national_id, birth_date, gender, address, phone, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
        $clinicId,
        $mrn,
        $first,
        $last,
        $mobile,
        $nationalId,
        '1990-01-15',
        'other',
        $address,
        '02100001111',
        $status,
        $now,
        $now
    ));
    if ($ok === false) {
        profile_fail('patient insert failed: ' . $wpdb->last_error);
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) {
        profile_fail('patient id missing');
    }

    return $id;
};

$link = static function (int $clinicId, int $patientId, int $userId, string $mobile, int $primary) use ($wpdb, $db, $now): int {
    $ok = $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_patient_user_links') . ' (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at) VALUES (%d, %d, %d, %s, %d, %s)',
        $clinicId,
        $patientId,
        $userId,
        $mobile,
        $primary,
        $now
    ));
    if ($ok === false) {
        profile_fail('link insert failed: ' . $wpdb->last_error);
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0) {
        profile_fail('link id missing');
    }

    return $id;
};

$validNid = static function (int $seq) use ($uniq): string {
    $seed = hexdec(substr(hash('sha256', $uniq . ':' . $seq), 0, 7));
    $base = str_pad((string) (100000000 + ($seed % 800000000)), 9, '0', STR_PAD_LEFT);
    $base = substr($base, 0, 9);
    if (preg_match('/^(\\d)\\1{8}$/', $base) === 1) {
        $base = '123456780';
    }
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int) $base[$i] * (10 - $i);
    }
    $remainder = $sum % 11;
    $check = $remainder < 2 ? $remainder : 11 - $remainder;
    $nid = $base . (string) $check;
    if (!\ClinicCore\Domain\Validators\NationalIdValidator::isValid($nid)) {
        profile_fail('generated national id failed the validator');
    }

    return $nid;
};

$loginOne = 'p9s4_one_' . $uniq;
$userOne = $makeUser($loginOne, $passOne);
$patientOne = $makePatient($clinicOne, 'SYN-P9S4-ONE-' . $uniq, 'SynOne', 'Profile', $mobiles['one'], 'active', $validNid(1), 'seed-addr');
$linkOne = $link($clinicOne, $patientOne, $userOne, $mobiles['one'], 1);

$loginMulti = 'p9s4_multi_' . $uniq;
$userMulti = $makeUser($loginMulti, $passMulti);
$patientA = $makePatient($clinicA, 'SYN-P9S4-A-' . $uniq, 'SynA', 'Record', $mobiles['a'], 'active', $validNid(2), 'addr-a');
$patientB = $makePatient($clinicB, 'SYN-P9S4-B-' . $uniq, 'SynB', 'Record', $mobiles['b'], 'active', $validNid(3), 'addr-b');
$linkA = $link($clinicA, $patientA, $userMulti, $mobiles['a'], 1);
$linkB = $link($clinicB, $patientB, $userMulti, $mobiles['b'], 0);

$loginForeign = 'p9s4_foreign_' . $uniq;
$userForeign = $makeUser($loginForeign, $passMulti);
$patientForeign = $makePatient($clinicA, 'SYN-P9S4-F-' . $uniq, 'SynF', 'Foreign', $mobiles['f'], 'active', $validNid(4), 'addr-f');
$linkForeign = $link($clinicA, $patientForeign, $userForeign, $mobiles['f'], 1);

$patientInactive = $makePatient($clinicB, 'SYN-P9S4-I-' . $uniq, 'SynI', 'Inactive', $mobiles['i'], 'archived', $validNid(5), 'addr-i');
$linkInactive = $link($clinicB, $patientInactive, $userMulti, $mobiles['i'], 0);

$oneRecords = \ClinicCore\Bootstrap\App::patientService()->linked_records($userOne);
if (count($oneRecords) !== 1 || (int) $oneRecords[0]['link_id'] !== $linkOne) {
    profile_fail('one-record linked_records mismatch count=' . count($oneRecords));
}
$oneMe = \ClinicCore\Bootstrap\App::patientService()->me($userOne, $linkOne);
if ((string) ($oneMe['first_name'] ?? '') !== 'SynOne' || (string) ($oneMe['address'] ?? '') !== 'seed-addr') {
    profile_fail('one-record me() did not return the seeded editable fields');
}

$multiRecords = \ClinicCore\Bootstrap\App::patientService()->linked_records($userMulti);
$multiLinks = array_map(static fn ($row): int => (int) $row['link_id'], $multiRecords);
sort($multiLinks);
$expectedLinks = [$linkA, $linkB];
sort($expectedLinks);
if ($multiLinks !== $expectedLinks) {
    profile_fail('multi linked_records mismatch count=' . count($multiRecords));
}
foreach ($multiRecords as $row) {
    if (isset($row['patient_id']) && in_array((int) $row['patient_id'], [$patientForeign, $patientInactive], true)) {
        profile_fail('selector source included a foreign or inactive patient');
    }
}

// Slice 5 TEST-ONLY RED: reuse these authenticated linked records for a real
// read-only Visits browser journey. No new harness, migration or product path.
$visits = [];
$visitDate = gmdate('Y-m-d');
foreach ([[$clinicA, $patientA, 'A'], [$clinicB, $patientB, 'B'], [$clinicOne, $patientOne, 'ONE']] as [$clinic, $patient, $label]) {
    $insertVisitFixture = static function (string $table, array $row) use ($wpdb, $db): int {
        if ($wpdb->insert($db->table($table), $row) !== 1) {
            profile_fail('visits fixture insert failed: ' . $table . ' ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    };
    $doctor = $insertVisitFixture('cpms_clinicians', [
        'clinic_id' => $clinic, 'full_name' => 'SYN-VISITS-DOCTOR-' . $label,
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $location = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $clinic
    ));
    if ($location <= 0) {
        profile_fail('visits fixture needs the existing persisted Clinic Location');
    }
    $visit = $insertVisitFixture('cpms_visits', [
        'location_id' => $location,
        'clinic_id' => $clinic, 'patient_id' => $patient, 'clinician_id' => $doctor,
        'visit_date' => $visitDate, 'source' => 'walk_in', 'status' => 'checked_out',
        'active' => 0, 'check_in_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach (['patient_visible', 'doctor_private'] as $visibility) {
        $insertVisitFixture('cpms_clinical_notes', [
            'clinic_id' => $clinic, 'patient_id' => $patient, 'clinician_id' => $doctor,
            'visit_id' => $visit, 'visibility' => $visibility, 'category' => 'clinical_note',
            'content_text' => ($visibility === 'patient_visible' ? 'SYN-VISIBLE-' : 'SYN-PRIVATE-') . $label,
            'change_reason' => 'SYN-INTERNAL-CORRECTION', 'created_by_wp_user_id' => $userMulti,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $visits[] = $visit;
}
echo 'PROFILE_PUBLIC=visits_fixture_ok visits=3 notes=6' . "\n";

// Slice 6 TEST-ONLY RED: seed durable prescriptions on the visits above.
$prescriptions = [];
foreach ([[$clinicA, $patientA, 'A', $visits[0]], [$clinicB, $patientB, 'B', $visits[1]], [$clinicOne, $patientOne, 'ONE', $visits[2]]] as [$clinic, $patient, $label, $visitId]) {
    $location = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $clinic
    ));
    $doctor = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1', $clinic
    ));
    $rx = $insertVisitFixture('cpms_prescriptions', [
        'clinic_id' => $clinic,
        'location_id' => $location,
        'prescription_number' => 'RX-SYN-00' . $label,
        'visit_id' => $visitId,
        'patient_id' => $patient,
        'clinician_id' => $doctor,
        'status' => 'finalized',
        'is_patient_visible' => 1,
        'finalized_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $insertVisitFixture('cpms_prescription_items', [
        'prescription_id' => $rx,
        'generic_name' => 'SYN-GENERIC-' . $label,
        'brand_name' => 'SYN-BRAND-' . $label,
        'strength' => '500mg',
        'form' => 'capsule',
        'dose' => '1 cap',
        'frequency' => 'TDS',
        'route' => 'oral',
        'duration_days' => 7,
        'instructions' => 'Take with water ' . $label,
        'source' => 'manual',
        'sort_order' => 0,
        'created_at' => $now,
    ]);
    // Draft prescription (must NOT appear for patient):
    $draftRx = $insertVisitFixture('cpms_prescriptions', [
        'clinic_id' => $clinic,
        'location_id' => $location,
        'prescription_number' => 'RX-DRAFT-SYN-00' . $label,
        'visit_id' => $visitId,
        'patient_id' => $patient,
        'clinician_id' => $doctor,
        'status' => 'draft',
        'is_patient_visible' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $insertVisitFixture('cpms_prescription_items', [
        'prescription_id' => $draftRx,
        'generic_name' => 'SYN-PRIVATE-DRAFT-' . $label,
        'dose' => '1 tab',
        'frequency' => 'daily',
        'form' => 'tablet',
        'route' => 'oral',
        'sort_order' => 0,
        'created_at' => $now,
    ]);
    // Hidden prescription (is_patient_visible = 0):
    $hiddenRx = $insertVisitFixture('cpms_prescriptions', [
        'clinic_id' => $clinic,
        'location_id' => $location,
        'prescription_number' => 'RX-HIDDEN-SYN-00' . $label,
        'visit_id' => $visitId,
        'patient_id' => $patient,
        'clinician_id' => $doctor,
        'status' => 'finalized',
        'is_patient_visible' => 0,
        'finalized_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $insertVisitFixture('cpms_prescription_items', [
        'prescription_id' => $hiddenRx,
        'generic_name' => 'SYN-PRIVATE-HIDDEN-' . $label,
        'dose' => '1 tab',
        'frequency' => 'daily',
        'form' => 'tablet',
        'route' => 'oral',
        'sort_order' => 0,
        'created_at' => $now,
    ]);
    $prescriptions[] = $rx;
}
echo 'PROFILE_PUBLIC=prescriptions_fixture_ok prescriptions=3 items=3' . "\n";

// Slice 7 TEST-ONLY RED: durable medical files on the same linked records,
// reusing the existing protected storage stack (no parallel storage system).
// Per record: a staff-created visit-linked visible file, a visit-less patient
// upload, a doctor_private decoy and a soft-deleted decoy.
$filesStorage = '/home/runner/clinic-storage-p9files';
foreach ([$clinicA, $clinicB, $clinicOne] as $filesClinicId) {
    \ClinicCore\Bootstrap\App::settingsFactory()->forClinic($filesClinicId)->set('files.storage_path', $filesStorage);
}
\ClinicCore\Settings\Settings::flushCache();
$filesStore = new \ClinicCore\Infrastructure\Storage\LocalFileStorage($filesStorage);
$seedPilotFile = static function (int $clinicId, int $patientId, ?int $visitId, string $name, string $visibility, bool $deleted = false) use ($wpdb, $db, $now, $filesStore): int {
    $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R /CPMS (" . $name . ") >>\nendobj\n%%EOF\n";
    $relative = $filesStore->store($content, $clinicId, 'pdf');
    if ($wpdb->insert($db->table('cpms_medical_attachments'), [
        'clinic_id' => $clinicId,
        'patient_id' => $patientId,
        'visit_id' => $visitId,
        'category' => 'document',
        'original_filename' => $name,
        'stored_filename' => basename($relative),
        'mime_type' => 'application/pdf',
        'file_size' => strlen($content),
        'storage_path' => $relative,
        'visibility' => $visibility,
        'uploaded_by_wp_user_id' => 0,
        'deleted_at' => $deleted ? $now : null,
        'created_at' => $now,
    ]) !== 1) {
        profile_fail('files fixture insert failed: ' . $wpdb->last_error);
    }
    $id = (int) $wpdb->insert_id;
    if ($id <= 0 || !is_file($filesStore->basePath() . '/' . $relative)) {
        profile_fail('files fixture row or physical file missing');
    }

    return $id;
};
$fileA = $seedPilotFile($clinicA, $patientA, $visits[0], 'SYN-FILES-A.pdf', 'patient_visible');
$seedPilotFile($clinicA, $patientA, null, 'SYN-FILES-A-UP.pdf', 'patient_visible');
$seedPilotFile($clinicA, $patientA, $visits[0], 'SYN-PRIVATE-A.pdf', 'doctor_private');
$seedPilotFile($clinicA, $patientA, $visits[0], 'SYN-DELETED-A.pdf', 'patient_visible', true);
$fileB = $seedPilotFile($clinicB, $patientB, $visits[1], 'SYN-FILES-B.pdf', 'patient_visible');
$seedPilotFile($clinicB, $patientB, null, 'SYN-FILES-B-UP.pdf', 'patient_visible');
$seedPilotFile($clinicB, $patientB, $visits[1], 'SYN-PRIVATE-B.pdf', 'doctor_private');
$seedPilotFile($clinicB, $patientB, $visits[1], 'SYN-DELETED-B.pdf', 'patient_visible', true);
$fileOne = $seedPilotFile($clinicOne, $patientOne, $visits[2], 'SYN-FILES-ONE.pdf', 'patient_visible');
$fileOneUp = $seedPilotFile($clinicOne, $patientOne, null, 'SYN-FILES-ONE-UP.pdf', 'patient_visible');
$seedPilotFile($clinicOne, $patientOne, $visits[2], 'SYN-PRIVATE-ONE.pdf', 'doctor_private');
$seedPilotFile($clinicOne, $patientOne, $visits[2], 'SYN-DELETED-ONE.pdf', 'patient_visible', true);

// Test-env permissions: seeding runs as the CLI user, but the browser C3 upload
// executes under the Apache user, which must be able to create NEW shard dirs
// ({clinic}/{xx}) inside the seeded root. Loosen the seeded tree (test-only).
$loosenStorageTree = static function (string $dir) use (&$loosenStorageTree): void {
    @chmod($dir, 0777);
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $loosenStorageTree($path);
        } else {
            @chmod($path, 0666);
        }
    }
};
$loosenStorageTree($filesStorage);

// Trusted Jalali pairing context for the visit-linked files: fixture UTC now
// converted through the persisted Location IANA timezone (Asia/Tehran).
$filesUtc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $now, new \DateTimeZone('UTC'))
    ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now, new \DateTimeZone('UTC'));
if ($filesUtc === false) {
    profile_fail('files fixture could not parse the fixture datetime');
}
$filesLocal = $filesUtc->setTimezone(new \DateTimeZone('Asia/Tehran'));
$filesJalali = \ClinicCore\Domain\Time\Jalali::formatYmd($filesLocal->format('Y-m-d'));
echo 'PROFILE_PUBLIC=files_fixture_ok files=12 storage=' . $filesStorage . "\n";

$env = 'VISITS_PAIR=' . implode('|', array_slice($visits, 0, 2)) . "\n"
    . 'VISITS_DATE=' . $visitDate . "\n"
    . 'VISITS_JALALI=' . \ClinicCore\Domain\Time\Jalali::formatYmd($visitDate) . "\n"
    . 'VISITS_ONE=' . $visits[2] . "\n"
    . 'RX_PAIR=' . implode('|', array_slice($prescriptions, 0, 2)) . "\n"
    . 'RX_ONE=' . $prescriptions[2] . "\n"
    . 'FILES_STORAGE=' . $filesStorage . "\n"
    . 'FILES_A=' . $fileA . "\n"
    . 'FILES_B=' . $fileB . "\n"
    . 'FILES_ONE=' . $fileOne . '|' . $fileOneUp . '|' . $filesJalali . '|' . $filesLocal->format('Y-m-d') . "\n"
    . 'PROFILE_ONE=' . $loginOne . '|' . $passOne . '|' . $userOne . '|' . $patientOne . '|' . $linkOne . '|' . $clinicOne . "\n"
    . 'PROFILE_MULTI=' . $loginMulti . '|' . $passMulti . '|' . $userMulti . '|' . $linkA . '|' . $linkB . '|' . $patientA . '|' . $patientB . '|' . $clinicA . '|' . $clinicB . '|' . $linkForeign . '|' . $linkInactive . "\n"
    . 'PROFILE_PUBLIC=one_user=' . $userOne . ' one_patient=' . $patientOne . ' one_link=' . $linkOne . ' one_clinic=' . $clinicOne
    . ' multi_user=' . $userMulti . ' link_a=' . $linkA . ' link_b=' . $linkB
    . ' patient_a=' . $patientA . ' patient_b=' . $patientB
    . ' clinic_a=' . $clinicA . ' clinic_b=' . $clinicB
    . ' foreign_link=' . $linkForeign . ' inactive_link=' . $linkInactive . "\n";
if (file_put_contents('/tmp/portal-profile.env', $env) === false) {
    profile_fail('could not write env file');
}

echo 'PROFILE_FIXTURE_OK clinics=3 links_one=1 links_multi=2 decoys=2' . "\n";
echo 'PROFILE_PUBLIC=one_user=' . $userOne . ' one_link=' . $linkOne . ' link_a=' . $linkA . ' link_b=' . $linkB
    . ' foreign_link=' . $linkForeign . ' inactive_link=' . $linkInactive . "\n";

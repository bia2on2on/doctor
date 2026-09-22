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
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_clinics') . ' (name, slug, timezone, organization_id, address, phone, created_at, updated_at) VALUES (%s, %s, %s, %d, NULL, NULL, %s, %s)',
        $name,
        $slug,
        'Asia/Tehran',
        $orgId,
        $now,
        $now
    ));
    $clinicId = (int) $wpdb->insert_id;
    if ($clinicId <= 1) {
        profile_fail('clinic id must be nontrivial');
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
    ?string $nationalId,
    ?string $address
) use ($wpdb, $db, $now): int {
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

$loginOne = 'p9s4_one_' . $uniq;
$userOne = $makeUser($loginOne, $passOne);
$patientOne = $makePatient($clinicOne, 'SYN-P9S4-ONE-' . $uniq, 'SynOne', 'Profile', $mobiles['one'], 'active', '0000000140', 'seed-addr');
$linkOne = $link($clinicOne, $patientOne, $userOne, $mobiles['one'], 1);

$loginMulti = 'p9s4_multi_' . $uniq;
$userMulti = $makeUser($loginMulti, $passMulti);
$patientA = $makePatient($clinicA, 'SYN-P9S4-A-' . $uniq, 'SynA', 'Record', $mobiles['a'], 'active', null, 'addr-a');
$patientB = $makePatient($clinicB, 'SYN-P9S4-B-' . $uniq, 'SynB', 'Record', $mobiles['b'], 'active', null, 'addr-b');
$linkA = $link($clinicA, $patientA, $userMulti, $mobiles['a'], 1);
$linkB = $link($clinicB, $patientB, $userMulti, $mobiles['b'], 0);

$loginForeign = 'p9s4_foreign_' . $uniq;
$userForeign = $makeUser($loginForeign, $passMulti);
$patientForeign = $makePatient($clinicA, 'SYN-P9S4-F-' . $uniq, 'SynF', 'Foreign', $mobiles['f'], 'active', null, 'addr-f');
$linkForeign = $link($clinicA, $patientForeign, $userForeign, $mobiles['f'], 1);

$patientInactive = $makePatient($clinicB, 'SYN-P9S4-I-' . $uniq, 'SynI', 'Inactive', $mobiles['i'], 'archived', null, 'addr-i');
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

$env = 'PROFILE_ONE=' . $loginOne . '|' . $passOne . '|' . $userOne . '|' . $patientOne . '|' . $linkOne . '|' . $clinicOne . "\n"
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

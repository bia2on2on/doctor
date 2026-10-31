<?php
/** CI-only synthetic visits for comparable HTTP POST profiles. */
if (PHP_SAPI !== 'cli') { exit(1); }
require getenv('WP_HOME') . '/wp-load.php';
$parts = explode('|', (string) getenv('DOCTOR_ONE'));
if (count($parts) < 6) { exit(1); }
[, , , $clinician, $clinic, $location] = $parts;
$db = \ClinicCore\Bootstrap\App::db();
$now = $db->nowUtcSql();
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$ids = [];
for ($i = 0; $i < 24; ++$i) {
    $tag = 'HTTP-PERF-' . bin2hex(random_bytes(5));
    $db->insert('cpms_patients', ['clinic_id' => (int) $clinic, 'mrn' => $tag, 'first_name' => 'Synthetic', 'last_name' => $tag, 'mobile' => '0912' . random_int(1000000, 9999999), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $patient = $db->wpdb_last_insert_id();
    $db->insert('cpms_visits', ['clinic_id' => (int) $clinic, 'location_id' => (int) $location, 'clinician_id' => (int) $clinician, 'patient_id' => $patient, 'source' => 'walk_in', 'status' => 'in_consultation', 'visit_date' => $today, 'check_in_at' => $now, 'consultation_started_at' => $now, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $ids[] = $db->wpdb_last_insert_id();
}
file_put_contents('/tmp/classic-v1-http-visit-ids.json', wp_json_encode($ids, JSON_THROW_ON_ERROR));
echo "SYNTHETIC_HTTP_VISITS=24\n";

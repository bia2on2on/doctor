<?php
/**
 * pilot-smoke.php — Workflow Smoke Tests روی محیط واقعی نصب‌شده (Pilot Gate).
 *
 * اجرا:  wp eval-file bin/pilot-smoke.php
 * پیش‌نیاز (Workflow می‌سازد): افزونه فعال؛ کاربران pilot_admin/pilot_secretary/
 * pilot_doctor؛ Clinician متصل به pilot_doctor (wp_user_id)؛ provider SMS = log.
 *
 * سناریوها (همه با داده Synthetic — بدون PHI واقعی):
 *   S1  Patient:  OTP request (مسیر SMS) + Booking hold→confirm
 *   S2  Secretary: walk-in ثبت مراجعه
 *   S3  Doctor:   call→start→note→complete + (منشی) invoice/payment + idempotent replay
 *   S4  Handwriting: document/page + revision apply + conflict 409
 *   S5  Notifications: publish به بیمار + inbox منشی
 *   S6  Reports/Export: اجرای گزارش + درخواست Export async
 *   S7  Protected files: آپلود → ذخیره خارج webroot
 *   S8  Idempotency: UNIQUE چهارستونه + stored replay سالم
 *   S9  SMS test path: event «test» → صف → LogSmsProvider → sent
 *
 * خروجی: خطوط «PASS <id> — <title>» + JSON summary؛ exit 2 در صورت شکست.
 */

use ClinicCore\Bootstrap\App;

if (!defined('ABSPATH') || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "must run via wp eval-file\n");
    exit(1);
}

global $wpdb;
$db = App::db();
$failures = [];
$results = [];

function scenario(string $id, string $title, callable $fn): void
{
    global $failures, $results;
    try {
        $detail = $fn() ?: 'ok';
        $results[] = ['id' => $id, 'title' => $title, 'status' => 'PASS', 'detail' => (string) $detail];
        echo "PASS $id — $title\n";
    } catch (\Throwable $e) {
        $failures[] = $id;
        $results[] = ['id' => $id, 'title' => $title, 'status' => 'FAIL', 'detail' => $e->getMessage()];
        echo "FAIL $id — $title — {$e->getMessage()}\n";
    }
}

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$now = $db->nowUtcSql();

// ---------- Fixtures (Synthetic) ----------
$doctorUserId = (int) username_exists('pilot_doctor');
$secretaryId = (int) username_exists('pilot_secretary');
$adminId = (int) username_exists('pilot_admin');
if (!$doctorUserId || !$secretaryId || !$adminId) {
    fwrite(STDERR, "fixtures missing: pilot_admin/pilot_secretary/pilot_doctor required\n");
    exit(1);
}

$clinicianId = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT id FROM ' . $db->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
    $doctorUserId
));
if (!$clinicianId) {
    fwrite(STDERR, "doctor has no linked clinician (wp_user_id)\n");
    exit(1);
}

$wpdb->query($wpdb->prepare(
    'INSERT INTO ' . $db->table('cpms_patients') . '
         (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
     VALUES (1, %s, %s, %s, %s, %s, %s, %s)',
    'SYN-SMOKE-001', 'بیمار', 'آزمایشی-دودی', '09120009999', 'active', $now, $now
));
$patientId = (int) $wpdb->insert_id;

$patientUserId = 0;
$existingPatientUser = username_exists('pilot_patient');
if ($existingPatientUser) {
    $patientUserId = (int) $existingPatientUser;
} else {
    $newUser = wp_create_user('pilot_patient', wp_generate_password(24), 'pilot-patient@synthetic.local');
    if (is_int($newUser) && $newUser > 0) {
        $patientUserId = $newUser;
        $u = get_userdata($patientUserId);
        if ($u !== false) {
            $u->set_role('cpms_patient');
        }
    }
}
if ($patientUserId) {
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_patient_user_links') . '
             (patient_id, wp_user_id, is_primary, created_at)
         VALUES (%d, %d, 1, %s)',
        $patientId, $patientUserId, $now
    ));
}

// Slot برای فردا (تقویم واقعی)
$slotDate = gmdate('Y-m-d', time() + 86400);
$wpdb->query($wpdb->prepare(
    'INSERT INTO ' . $db->table('cpms_schedule_slots') . '
         (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
     VALUES (1, %d, %s, %s, 20, 1, 0, 0, 1, %s, %s)',
    $clinicianId, $slotDate, '10:00', $now, $now
));

// ---------- S1: Patient (OTP path + booking) ----------
scenario('S1', 'Patient: OTP request + booking hold→confirm', function () use ($patientUserId, $clinicianId, $slotDate) {
    if (!$patientUserId) {
        throw new RuntimeException('patient user creation failed');
    }
    $otp = App::otpService()->request('09120009999');
    if (!isset($otp['expires_in'])) {
        throw new RuntimeException('otp request failed: ' . wp_json_encode($otp));
    }
    $hold = App::bookingService()->hold($patientUserId, $clinicianId, $slotDate, '10:00');
    if (empty($hold['hold_token'])) {
        throw new RuntimeException('hold failed');
    }
    $appt = App::bookingService()->confirm($hold['hold_token'], $patientUserId, 'بررسی دوره‌ای (Synthetic)', uuid4());
    if (($appt['status'] ?? '') !== 'confirmed') {
        throw new RuntimeException('confirm status=' . ($appt['status'] ?? '?'));
    }
    return 'otp queued; appointment #' . $appt['appointment_id'] . ' ref=' . $appt['reference_code'];
});

// ---------- S2: Secretary workflow ----------
$visitIdRef = 0;
scenario('S2', 'Secretary: walk-in ثبت مراجعه در صف', function () use ($secretaryId, $patientId, $clinicianId, &$visitIdRef) {
    $visit = App::visitService()->walkIn($secretaryId, $patientId, $clinicianId);
    $visitIdRef = (int) $visit['id'];
    if ($visit['status'] !== 'waiting') {
        throw new RuntimeException('walk-in status=' . $visit['status']);
    }
    return 'visit #' . $visitIdRef . ' waiting';
});

// ---------- S3: Doctor workflow + finance ----------
scenario('S3', 'Doctor: call→start→note→complete + invoice/payment + idempotent replay', function () use ($doctorUserId, $secretaryId, $visitIdRef) {
    if (!$visitIdRef) {
        throw new RuntimeException('no visit from S2');
    }
    $v = App::visitService()->transition($doctorUserId, $visitIdRef, 'call', ['room' => '1']);
    if ($v['status'] !== 'called') {
        throw new RuntimeException('call status=' . $v['status']);
    }
    $v = App::visitService()->transition($doctorUserId, $visitIdRef, 'start', []);
    if ($v['status'] !== 'in_consultation') {
        throw new RuntimeException('start status=' . $v['status']);
    }
    $note = App::clinicalService()->addNote($doctorUserId, $visitIdRef, [
        'category' => 'chief_complaint',
        'visibility' => 'patient_visible',
        'content_text' => 'سردرد خفیف (Synthetic smoke)',
    ]);
    if (!isset($note['id'])) {
        throw new RuntimeException('note failed');
    }
    $done = App::visitService()->transition($doctorUserId, $visitIdRef, 'complete', []);
    if ($done['status'] !== 'consultation_completed') {
        throw new RuntimeException('complete status=' . $done['status']);
    }
    $inv = App::financeService()->issueInvoice($secretaryId, [
        'visit_id' => $visitIdRef,
        'items' => [['description' => 'ویزیت (Synthetic)', 'quantity' => 1, 'unit_price' => 250000]],
    ]);
    $key = uuid4();
    $pay = App::financeService()->recordPayment($secretaryId, (int) $inv['id'], ['amount' => 250000, 'method' => 'cash'], $key);
    $replay = App::financeService()->recordPayment($secretaryId, (int) $inv['id'], ['amount' => 250000, 'method' => 'cash'], $key);
    if (($replay['payment_id'] ?? 0) !== ($pay['payment_id'] ?? -1)) {
        throw new RuntimeException('idempotent replay mismatch');
    }
    return 'visit complete; invoice #' . $inv['id'] . ' paid; replay same payment_id #' . $pay['payment_id'];
});

// ---------- S4: Handwriting ----------
scenario('S4', 'Handwriting: document+page + revision apply + stale-revision conflict', function () use ($doctorUserId, $visitIdRef) {
    if (!$visitIdRef) {
        throw new RuntimeException('no visit');
    }
    $doc = App::handwritingService()->createDocument($doctorUserId, $visitIdRef, 'نسخه دودی', []);
    $page = App::handwritingService()->addPage($doctorUserId, (int) $doc['id'], []);
    $stroke = [[
        'id' => 's1', 'tool' => 'pen', 'color' => '#1a1a2e', 'size' => 4,
        'points' => [[10, 20, 0.5, 1690000000], [40, 60, 0.8, 1690000050]],
    ]];
    $save = App::handwritingService()->savePage($doctorUserId, (int) $page['id'], [
        'client_revision' => 1,
        'stroke_data' => base64_encode((string) gzencode((string) wp_json_encode($stroke))),
        'width' => 1240, 'height' => 1754, 'saved_by' => 'manual',
    ], uuid4());
    if ((int) ($save['response']['version'] ?? 0) < 2) {
        throw new RuntimeException('save failed: ' . wp_json_encode($save));
    }
    $conflicted = false;
    try {
        App::handwritingService()->savePage($doctorUserId, (int) $page['id'], [
            'client_revision' => 1, // stale — سرور جلوتر رفته
            'stroke_data' => base64_encode((string) gzencode('[]')),
            'width' => 1240, 'height' => 1754,
        ], uuid4());
    } catch (\Throwable $e) {
        $conflicted = true;
    }
    if (!$conflicted) {
        throw new RuntimeException('stale revision must conflict');
    }
    return 'doc #' . $doc['id'] . ' page v' . $save['response']['version'] . '; stale-revision conflict OK';
});

// ---------- S5: Notifications ----------
scenario('S5', 'Notifications: publish به بیمار + inbox منشی', function () use ($secretaryId, $patientId) {
    $id = App::notificationService()->publishToPatient($patientId, 'queue_called', ['visit' => 'synthetic'], null);
    if ($id === null) {
        throw new RuntimeException('publish failed');
    }
    $inbox = App::notificationService()->inbox($secretaryId, false, 10);
    if (!is_array($inbox)) {
        throw new RuntimeException('inbox failed');
    }
    return 'notification #' . $id . '; secretary inbox OK (' . count($inbox) . ' items)';
});

// ---------- S6: Reports + Export ----------
scenario('S6', 'Reports/Export: اجرای گزارش + درخواست Export async', function () use ($secretaryId) {
    $today = App::reportService()->run($secretaryId, 'appointments_today', null, null);
    if (!is_array($today)) {
        throw new RuntimeException('report failed');
    }
    $export = App::exportService()->request($secretaryId, 'visits', gmdate('Y-m-d', time() - 7 * 86400), gmdate('Y-m-d'));
    if (!isset($export['job_id'])) {
        throw new RuntimeException('export request failed');
    }
    $n = App::dispatcher()->tick(5);
    return 'appointments_today OK; export job #' . $export['job_id'] . ' (tick processed ' . $n . ')';
});

// ---------- S7: Protected medical files ----------
scenario('S7', 'Protected files: آپلود → ذخیره خارج webroot', function () use ($doctorUserId, $patientId, $visitIdRef) {
    $tmp = tempnam(sys_get_temp_dir(), 'cpms-smoke') . '.pdf';
    // PDF معتبر مینیمال (finfo واقعی MIME را می‌خواند)
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 99 99]>>endobj\nxref\n0 4\ntrailer<</Size 4/Root 1 0 R>>\n%%EOF\n");
    $meta = App::medicalFileService()->upload($doctorUserId, [
        'name' => 'synthetic-report.pdf',
        'type' => 'application/pdf',
        'size' => (int) filesize($tmp),
        'tmp_name' => $tmp,
        'error' => 0,
    ], $patientId, $visitIdRef ?: null, 'document', 'patient_visible');
    $abs = App::localFileStorage()->absolutePath((string) $meta['storage_path']);
    if (!$abs || !is_file((string) $abs)) {
        throw new RuntimeException('file missing in protected storage');
    }
    $docRoot = rtrim(ABSPATH, '/');
    if (str_starts_with((string) $abs, $docRoot)) {
        throw new RuntimeException('file inside webroot: ' . $abs);
    }
    @unlink($tmp);
    return 'stored outside webroot: ' . dirname((string) $abs);
});

// ---------- S8: Idempotency integrity ----------
scenario('S8', 'Idempotency: UNIQUE(key,endpoint,user,context) + stored replay', function () use ($db, $wpdb, $patientUserId) {
    $key = 'pilot-smoke-idem-' . wp_rand(1000, 9999);
    $ok = $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_idempotency_keys') . '
             (`key`, clinic_id, wp_user_id, endpoint, context_id, status, response_code, response_json, created_at)
         VALUES (%s, 1, %d, %s, 0, 1, 200, %s, %s)',
        $key, $patientUserId, 'booking/confirm', '{"replayed":true}', $db->nowUtcSql()
    ));
    if (!$ok) {
        throw new RuntimeException('insert failed');
    }
    $dup = $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_idempotency_keys') . '
             (`key`, clinic_id, wp_user_id, endpoint, context_id, status, created_at)
         VALUES (%s, 1, %d, %s, 0, 0, %s)',
        $key, $patientUserId, 'booking/confirm', $db->nowUtcSql()
    ));
    if ($dup !== false) {
        throw new RuntimeException('duplicate scope accepted — UNIQUE missing!');
    }
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT response_code, response_json FROM ' . $db->table('cpms_idempotency_keys') . ' WHERE `key` = %s',
        $key
    ), ARRAY_A);
    if ((int) $row['response_code'] !== 200) {
        throw new RuntimeException('stored response wrong');
    }
    return 'scope UNIQUE enforced; stored replay intact';
});

// ---------- S9: SMS test provider (safe path) ----------
scenario('S9', 'SMS test path: event test → صف → LogSmsProvider → sent', function () use ($db, $wpdb) {
    $sent = App::smsService()->sendEvent('test', '09120009999', [], null, null, false, 5, 'Pilot Gate synthetic test');
    if (!is_array($sent)) {
        throw new RuntimeException('sendEvent failed');
    }
    App::dispatcher()->tick(10);
    $row = $wpdb->get_row(
        'SELECT status, provider, failure_code FROM ' . $db->table('cpms_sms_messages') . "
         WHERE mobile = '09120009999' AND template = 'test' ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
    if (!$row || $row['status'] !== 'sent' || $row['provider'] !== 'log') {
        throw new RuntimeException('sms not sent via log provider: ' . wp_json_encode($row));
    }
    return 'sent via provider=log; failure_code=' . var_export($row['failure_code'], true);
});

// ---------- خروجی ----------
echo wp_json_encode(['ok' => $failures === [], 'scenarios' => $results], JSON_UNESCAPED_UNICODE) . "\n";
if ($failures !== []) {
    exit(2);
}

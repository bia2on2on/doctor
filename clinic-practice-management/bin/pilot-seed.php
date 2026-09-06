<?php
/**
 * pilot-seed.php — داده Synthetic برای محیط Pilot/Staging (اجرای واقعی ممنوع روی داده بیمار واقعی).
 *
 * اجرا:  PILOT_PATIENTS=400 PILOT_DAYS=30 wp eval-file bin/pilot-seed.php
 * پیش‌نیاز: افزونه فعال + دو Clinician موجود (پارامترها پایین).
 *
 * همه داده‌ها آشکارا ساختگی‌اند:
 *   نام:   «بیمار آزمایشی N» / پزشک = پارامترهای ورودی
 *   موبایل: 0912000NNNN (شماره‌های تست)
 *   MRN:   SYN-NNNN
 *   کد نوبت: SYNAP-NNNN
 * هیچ داده واقعی PHI وارد نمی‌شود.
 */

use ClinicCore\Bootstrap\App;

if (!defined('ABSPATH') || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "must run via wp eval-file\n");
    exit(1);
}

// پارامترها از Environment (wp eval-file args قابل اطمینان نیست)
$patients = max(1, (int) (getenv('PILOT_PATIENTS') ?: 400));
$days = max(1, (int) (getenv('PILOT_DAYS') ?: 30));
$slotsPerDay = max(1, (int) (getenv('PILOT_SLOTS_PER_DAY') ?: 15));

global $wpdb;
$db = App::db();
$now = gmdate('Y-m-d H:i:s') . '.000';

$clinicians = $wpdb->get_results(
    'SELECT id FROM ' . $db->table('cpms_clinicians') . ' WHERE is_active = 1 ORDER BY id LIMIT 2',
    ARRAY_A
);
if (count($clinicians) < 2) {
    fwrite(STDERR, "need at least 2 active clinicians before seeding\n");
    exit(1);
}
[$docA, $docB] = [(int) $clinicians[0]['id'], (int) $clinicians[1]['id']];

// ---------- 1) Slots (تقویم آینده — پایه Benchmark تقویم) ----------
$slotIds = [];
for ($d = 0; $d < $days; $d++) {
    $date = gmdate('Y-m-d', time() + $d * 86400);
    for ($t = 0; $t < $slotsPerDay; $t++) {
        $time = sprintf('%02d:%02d', 9 + intdiv($t * 20, 60), ($t * 20) % 60);
        foreach ([$docA, $docB] as $ci => $clinicianId) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $db->table('cpms_schedule_slots') . '
                     (clinic_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
                 VALUES (1, %d, %s, %s, 20, 4, 0, 0, 1, %s, %s)',
                $clinicianId, $date, $time, $now, $now
            ));
            $slotIds[$ci][] = (int) $wpdb->insert_id;
        }
    }
}

// ---------- 2) Patients ----------
$patientIds = [];
for ($i = 1; $i <= $patients; $i++) {
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_patients') . '
             (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
         VALUES (1, %s, %s, %s, %s, %s, %s, %s)',
        sprintf('SYN-%04d', $i),
        'بیمار آزمایشی',
        (string) $i,
        sprintf('0912000%04d', $i % 10000),
        'active',
        $now,
        $now
    ));
    $patientIds[] = (int) $wpdb->insert_id;
}

// ---------- 3) Appointments (گذشته + آینده، همه وضعیتها) ----------
$apptStatuses = ['confirmed', 'confirmed', 'completed', 'completed', 'cancelled_by_patient', 'no_show', 'rescheduled', 'pending'];
$apptCount = 0;
$n = count($patientIds);
for ($i = 0; $i < (int) ($patients * 1.5); $i++) {
    $patientId = $patientIds[$i % $n];
    $clinicianId = ($i % 2 === 0) ? $docA : $docB;
    $slotRef = $slotIds[$i % 2][$i % count($slotIds[0])];
    $slot = $wpdb->get_row($wpdb->prepare(
        'SELECT slot_date, slot_time FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d',
        $slotRef
    ), ARRAY_A);
    if (!$slot) {
        continue;
    }
    $slot = (object) $slot;
    $status = $apptStatuses[$i % count($apptStatuses)];
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_appointments') . '
             (clinic_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time,
              reason, status, booked_at, confirmed_at, created_at, updated_at)
         VALUES (1, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s)',
        sprintf('SYNAP-%05d', $i),
        $clinicianId,
        $patientId,
        $slotRef,
        $slot->slot_date,
        $slot->slot_time,
        'بررسی دوره‌ای (Synthetic)',
        $status,
        $now,
        in_array($status, ['confirmed', 'completed'], true) ? $now : null,
        $now,
        $now
    ));
    $apptCount++;
}

// ---------- 4) Visits + Invoices + Payments + Notifications ----------
$visitStatuses = ['checked_out', 'checked_out', 'awaiting_payment', 'paid', 'cancelled', 'skipped', 'waiting'];
$today = gmdate('Y-m-d');
$visitCount = $invCount = $payCount = $notifCount = 0;
for ($i = 0; $i < (int) ($patients * 0.9); $i++) {
    $patientId = $patientIds[$i % $n];
    $clinicianId = ($i % 2 === 0) ? $docA : $docB;
    $status = $visitStatuses[$i % count($visitStatuses)];
    $visitDate = ($i % 3 === 0) ? $today : gmdate('Y-m-d', time() - (($i % 21) + 1) * 86400);
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_visits') . '
             (clinic_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at)
         VALUES (1, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)',
        $clinicianId,
        $patientId,
        $i % 4 === 0 ? 'walk_in' : 'scheduled',
        $status,
        $visitDate,
        $visitDate . ' 09:00:00.000',
        $visitDate . ' 09:00:00.000',
        $now,
        $now
    ));
    $visitId = (int) $wpdb->insert_id;
    $visitCount++;

    if (in_array($status, ['awaiting_payment', 'paid', 'checked_out'], true)) {
        $total = 200000 + ($i % 7) * 50000;
        $paid = ($status === 'paid' || $status === 'checked_out') ? $total : ($i % 2) * ($total / 2);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_invoices') . '
                 (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, discount, tax, total,
                  paid_amount, balance, issued_by_wp_user_id, created_at, updated_at)
             VALUES (1, %s, %d, %d, %s, %d, 0, 0, %d, %d, %d, 1, %s, %s)',
            sprintf('SYNINV-%05d', $i),
            $patientId,
            $visitId,
            $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'open'),
            $total,
            $total,
            $paid,
            $total - $paid,
            $now,
            $now
        ));
        $invoiceId = (int) $wpdb->insert_id;
        $invCount++;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_invoice_items') . '
                 (invoice_id, description, quantity, unit_price, amount)
             VALUES (%d, %s, 1, %d, %d)',
            $invoiceId,
            'ویزیت و معاینه (Synthetic)',
            $total,
            $total
        ));
        if ($paid > 0) {
            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . $db->table('cpms_payments') . '
                     (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key,
                      status, paid_at, received_by_wp_user_id, created_at)
                 VALUES (1, %s, %d, %d, %d, %s, %s, %s, %s, 1, %s)',
                sprintf('SYNPAY-%05d', $i),
                $invoiceId,
                $patientId,
                $paid,
                $i % 2 ? 'cash' : 'card_pos',
                'syn-seed-' . $i,
                'captured',
                $now,
                $now
            ));
            $payCount++;
        }
    }

    // اعلان‌ها (status enum: queued/sent/delivered/...؛ read = ستون read_at)
    foreach ([0, 1, 2] as $k) {
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_notifications') . '
                 (clinic_id, recipient_patient_id, channel, template, payload_json, status, dedupe_key, sent_at, read_at, created_at)
             VALUES (1, %d, %s, %s, %s, %s, %s, %s, %s, %s)',
            $patientId,
            $k === 0 ? 'internal' : 'sms',
            'appointment_reminder',
            wp_json_encode(['visit' => $i, 'note' => 'Synthetic pilot data'], JSON_UNESCAPED_UNICODE),
            $k === 0 ? 'queued' : 'sent',
            'syn-notif-' . $i . '-' . $k,
            $k === 0 ? null : $now,
            $k === 2 ? $now : null,
            $now
        ));
        $notifCount++;
    }
}

// ---------- 5) Idempotency legacy-style rows (دامنه جدید — بعد از Migration 0006 همه 0 هستند) ----------
for ($i = 0; $i < 60; $i++) {
    $wpdb->query($wpdb->prepare(
        'INSERT INTO ' . $db->table('cpms_idempotency_keys') . '
             (`key`, clinic_id, wp_user_id, endpoint, context_id, status, response_code, response_json, created_at)
         VALUES (%s, 1, %d, %s, 0, %s, 200, %s, %s)',
        'syn-idem-' . $i,
        2 + ($i % 5),
        $i % 2 ? 'booking/confirm' : 'invoices/payments',
        1, // STATUS_DONE
        '{}',
        $now
    ));
}

// ---------- خروجی JSON برای گزارش Gate ----------
$size = $wpdb->get_var(
    'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
     FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE "' . $wpdb->prefix . 'cpms_%"'
);
$counts = [];
foreach (['cpms_patients', 'cpms_schedule_slots', 'cpms_appointments', 'cpms_visits', 'cpms_invoices', 'cpms_payments', 'cpms_notifications', 'cpms_idempotency_keys'] as $t) {
    $counts[$t] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table($t));
}
echo wp_json_encode([
    'ok' => true,
    'data' => 'SYNTHETIC',
    'clinicians' => [$docA, $docB],
    'counts' => $counts,
    'db_size_mb' => (float) $size,
], JSON_UNESCAPED_UNICODE) . "\n";

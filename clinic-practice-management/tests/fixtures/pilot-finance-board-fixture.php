<?php
/**
 * Synthetic awaiting-payment rows for the existing Pilot Staff Portal browser gate.
 * Reuses the one-Clinic Secretary created by pilot-doctor-portal-fixture.php.
 */

require getenv('WP_HOME') . '/wp-load.php';

global $wpdb;

$db = \ClinicCore\Bootstrap\App::db();
$secretary = explode('|', (string) getenv('STAFF_SECRETARY'));
$portalFixture = explode('|', (string) getenv('DOCTOR_PORTAL_PUBLIC'));
if (count($secretary) < 3 || count($portalFixture) < 3) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: prerequisite Staff Portal fixture missing\n");
    exit(1);
}

[$login, $password] = array_slice($secretary, 0, 2);
$clinicId = (int) $portalFixture[1];
$locationId = (int) $portalFixture[2];
$secretaryId = (int) $secretary[2];
$clinicianId = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT id FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1',
        $clinicId
    )
);
if ($clinicId <= 0 || $locationId <= 0 || $secretaryId <= 0 || $clinicianId <= 0) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: trusted synthetic identifiers unavailable\n");
    exit(1);
}

$now = $db->nowUtcSql();
$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
$nonce = substr(bin2hex(random_bytes(4)), 0, 8);
$patients = array(
    array('Synthetic Invoice ' . $nonce, 'invoice'),
    array('Synthetic NoInvoice ' . $nonce, 'noinvoice'),
    // Phase 12 Slice 3 — a dedicated open invoice the responsive/app journey
    // captures against: first a REAL partial payment, then an EXACT full
    // settlement (both through the existing finance service via the UI).
    array('Synthetic Capture ' . $nonce, 'capture'),
    // Phase 12 Slice 4 — a dedicated paid Visit (settlement complete) for the
    // paid/checkout-ready board: the real checkout journey targets the
    // capture visit after its REAL settlement; this row stays untouched.
    array('Synthetic Checkout ' . $nonce, 'checkout'),
);
$patientIds = array();
$visitIds = array();
foreach ($patients as [$name, $kind]) {
    $inserted = $wpdb->insert(
        $db->table('cpms_patients'),
        array(
            'clinic_id'  => $clinicId,
            'mrn'        => 'SYN-FIN-' . strtoupper($kind) . '-' . $nonce,
            'first_name' => $name,
            'last_name'  => 'Fixture',
            'mobile'     => '09' . random_int(1000000000, 9999999999),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        )
    );
    if ( false === $inserted ) {
        fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: patient seed failed\n");
        exit(1);
    }
    $patientId = (int) $wpdb->insert_id;
    // Phase 12 Slice 4 — the checkout patient starts ALREADY paid (its
    // settlement is complete); everyone else starts awaiting_payment.
    $visitStatus = 'checkout' === $kind ? 'paid' : 'awaiting_payment';
    $visitInserted = $wpdb->insert(
        $db->table('cpms_visits'),
        array(
            'clinic_id'   => $clinicId,
            'location_id' => $locationId,
            'clinician_id'=> $clinicianId,
            'patient_id'  => $patientId,
            'source'      => 'walk_in',
            'status'      => $visitStatus,
            'visit_date'  => $date,
            'check_in_at' => $now,
            'waiting_since' => $now,
            'active'      => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        )
    );
    if ( false === $visitInserted ) {
        fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: visit seed failed\n");
        exit(1);
    }
    $patientIds[$kind] = $patientId;
    $visitIds[$kind] = (int) $wpdb->insert_id;
}

// Phase 12 Slice 4 — the settlement-complete invoice behind the paid Visit:
// fully paid, zero remaining — the board's settlement summary shows it.
$checkoutInvoiceInserted = $wpdb->insert(
    $db->table('cpms_invoices'),
    array(
        'clinic_id'            => $clinicId,
        'location_id'          => $locationId,
        'invoice_number'       => 'SYN-FIN-CHK-' . $nonce,
        'patient_id'           => $patientIds['checkout'],
        'visit_id'             => $visitIds['checkout'],
        'status'               => 'paid',
        'subtotal'             => '250000.00',
        'total'                => '250000.00',
        'currency'             => 'IRR',
        'paid_amount'          => '250000.00',
        'balance'              => '0.00',
        'issued_by_wp_user_id' => $secretaryId,
        'created_at'           => $now,
        'updated_at'           => $now,
    )
);
if ( false === $checkoutInvoiceInserted ) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: checkout invoice seed failed\n");
    exit(1);
}

$invoiceInserted = $wpdb->insert(
    $db->table('cpms_invoices'),
    array(
        'clinic_id'            => $clinicId,
        'location_id'          => $locationId,
        'invoice_number'       => 'SYN-FIN-' . $nonce,
        'patient_id'           => $patientIds['invoice'],
        'visit_id'             => $visitIds['invoice'],
        'status'               => 'partial',
        'subtotal'             => '1234.00',
        'total'                => '1234.00',
        'currency'             => 'IRR',
        'paid_amount'          => '300.00',
        'balance'              => '934.00',
        'issued_by_wp_user_id' => $secretaryId,
        'created_at'           => $now,
        'updated_at'           => $now,
    )
);
if ( false === $invoiceInserted ) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: invoice seed failed\n");
    exit(1);
}

// Phase 12 Slice 3 — the untouched OPEN invoice for the manual capture journey
// (500000.00 Rial, nothing paid yet) so the browser flow can prove a partial
// capture and then an exact full settlement against server truth.
$captureInvoiceInserted = $wpdb->insert(
    $db->table('cpms_invoices'),
    array(
        'clinic_id'            => $clinicId,
        'location_id'          => $locationId,
        'invoice_number'       => 'SYN-FIN-CAP-' . $nonce,
        'patient_id'           => $patientIds['capture'],
        'visit_id'             => $visitIds['capture'],
        'status'               => 'open',
        'subtotal'             => '500000.00',
        'total'                => '500000.00',
        'currency'             => 'IRR',
        'paid_amount'          => '0.00',
        'balance'              => '500000.00',
        'issued_by_wp_user_id' => $secretaryId,
        'created_at'           => $now,
        'updated_at'           => $now,
    )
);
if ( false === $captureInvoiceInserted ) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: capture invoice seed failed\n");
    exit(1);
}

// Phase 12 Slice 2 — one consultation-completed Visit for FIRST issuance. It
// gets its own clinician so the existing awaiting-payment rows and the
// clinician-scoped Doctor Portal views stay exactly as they were.
$eligibleClinicianInserted = $wpdb->insert(
    $db->table('cpms_clinicians'),
    array(
        'clinic_id'  => $clinicId,
        'full_name'  => 'Synthetic Finance Doctor ' . $nonce,
        'is_active'  => 1,
        'created_at' => $now,
        'updated_at' => $now,
    )
);
if ( false === $eligibleClinicianInserted ) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: eligible clinician seed failed\n");
    exit(1);
}
$eligibleClinicianId = (int) $wpdb->insert_id;

$eligiblePatientName = 'Synthetic Eligible ' . $nonce;
$eligiblePatientInserted = $wpdb->insert(
    $db->table('cpms_patients'),
    array(
        'clinic_id'  => $clinicId,
        'mrn'        => 'SYN-FIN-ELIGIBLE-' . strtoupper($nonce),
        'first_name' => $eligiblePatientName,
        'last_name'  => 'Fixture',
        'mobile'     => '09' . random_int(1000000000, 9999999999),
        'status'     => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    )
);
if ( false === $eligiblePatientInserted ) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: eligible patient seed failed\n");
    exit(1);
}
$eligiblePatientId = (int) $wpdb->insert_id;

$eligibleVisitInserted = $wpdb->insert(
    $db->table('cpms_visits'),
    array(
        'clinic_id'     => $clinicId,
        'location_id'   => $locationId,
        'clinician_id'  => $eligibleClinicianId,
        'patient_id'    => $eligiblePatientId,
        'source'        => 'walk_in',
        'status'        => 'consultation_completed',
        'visit_date'    => $date,
        'check_in_at'   => $now,
        'waiting_since' => $now,
        'active'        => 1,
        'created_at'    => $now,
        'updated_at'    => $now,
    )
);
if ( false === $eligibleVisitInserted ) {
    fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: eligible visit seed failed\n");
    exit(1);
}
$eligibleVisitId = (int) $wpdb->insert_id;

$portalUrl = add_query_arg(
    \ClinicCore\Frontend\StaffPortalShell::MODULE_PARAM,
    \ClinicCore\Frontend\StaffPortalShell::MODULE_FINANCE,
    \ClinicCore\Frontend\StaffPortalShell::portal_url()
);
$env = array(
    'FINANCE_BOARD_URL'       => $portalUrl,
    'FINANCE_BOARD_LOGIN'     => $login,
    'FINANCE_BOARD_PASS'      => $password,
    'FINANCE_BOARD_CLINIC_ID' => (string) $clinicId,
    'FINANCE_BOARD_LOCATION_ID' => (string) $locationId,
    'FINANCE_BOARD_INVOICE_PATIENT' => $patients[0][0] . ' Fixture',
    'FINANCE_BOARD_NO_INVOICE_PATIENT' => $patients[1][0] . ' Fixture',
    'FINANCE_BOARD_ELIGIBLE_PATIENT' => $eligiblePatientName . ' Fixture',
    'FINANCE_BOARD_PAYMENT_PATIENT' => $patients[2][0] . ' Fixture',
    'FINANCE_BOARD_CHECKOUT_PATIENT' => $patients[3][0] . ' Fixture',
);
$lines = array();
foreach ($env as $key => $value) {
    if ( str_contains( $value, "\n" ) || str_contains( $value, "\r" ) ) {
        fwrite(STDERR, "FINANCE_BOARD_FIXTURE_ERROR: unsafe environment value\n");
        exit(1);
    }
    $lines[] = $key . '=' . $value;
}
file_put_contents('/tmp/finance-board.env', implode("\n", $lines) . "\n");
echo 'fixture: finance-board clinic=' . $clinicId . ' location=' . $locationId . ' synthetic_rows=4 invoice_rows=3 open_capture_invoice=1 paid_checkout_visit=1 eligible_rows=1 eligible_visit=' . $eligibleVisitId . ' timezone=Asia/Tehran' . "\n";

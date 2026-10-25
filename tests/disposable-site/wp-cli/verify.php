<?php

declare(strict_types=1);

/** Verify empty activation, a provisioned fixture, or post-browser invariants. */

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

require __DIR__ . '/common.php';

$cpms_ds_stage = 'verify-bootstrap';

try {
    $expect = cpms_ds_env('CPMS_DISPOSABLE_EXPECT');
    if (!in_array($expect, ['empty', 'active', 'browser-complete'], true)) {
        cpms_ds_fail('CONFIG', 'CPMS_DISPOSABLE_EXPECT must be empty, active, or browser-complete');
    }
    cpms_ds_admin_id();
    $db = cpms_ds_db();

    if ($expect === 'empty') {
        cpms_ds_assert_empty_site();
        global $wpdb;
        $demo_pages = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name LIKE %s",
                'cpms-disposable-%'
            )
        );
        if ($demo_pages !== 0) {
            cpms_ds_fail('ACTIVATION_SIDE_EFFECT', 'activation created a disposable fixture page');
        }
        echo 'CPMS_DISPOSABLE_VERIFY=' . wp_json_encode([
            'expect' => 'empty',
            'ok' => true,
            'business_rows' => 0,
            'fixture_users' => 0,
            'fixture_pages' => 0,
        ]) . PHP_EOL;
        return;
    }

    $state_path = cpms_ds_secret_path(cpms_ds_env('CPMS_DISPOSABLE_STATE'));
    $state = cpms_ds_load_state($state_path);
    cpms_ds_assert_fixture_owner($state);
    $clinic_id = (int) $state['clinic_id'];
    $organization_id = (int) $state['organization_id'];
    $after_browser = $expect === 'browser-complete';

    $cpms_ds_stage = 'verify-accounts';
    $expected_roles = [
        'organization_manager' => 'subscriber',
        'clinic_manager' => 'subscriber',
        'doctor' => 'subscriber',
        'receptionist' => 'subscriber',
        'accountant' => 'subscriber',
        'patient' => RolesAndCapabilities::ROLE_PATIENT,
    ];
    $passwords = [];
    $account_evidence = [];
    foreach ($expected_roles as $key => $expected_role) {
        $account = (array) $state['accounts'][$key];
        $user_id = (int) $account['id'];
        $user = get_userdata($user_id);
        $password = (string) $account['password'];
        if ($user === false || (array) $user->roles !== [$expected_role]
            || (string) $user->user_login !== (string) $account['username']
            || in_array('administrator', (array) $user->roles, true)
            || preg_match('/^[a-f0-9]{48}$/', $password) !== 1
            || !wp_check_password($password, (string) $user->user_pass, $user_id)
        ) {
            cpms_ds_fail('ACCOUNT_CONTRACT', 'account role, identity, or CSPRNG password verification failed for ' . $key);
        }
        $passwords[] = $password;
        $account_evidence[$key] = ['id' => $user_id, 'wp_role' => $expected_role];
    }
    if (count(array_unique($passwords)) !== 6) {
        cpms_ds_fail('ACCOUNT_CONTRACT', 'the six passwords are not unique');
    }

    $cpms_ds_stage = 'verify-memberships';
    $org_membership = $db->fetchRow(
        'SELECT role_key, status FROM ' . $db->table('cpms_organization_memberships')
        . ' WHERE organization_id = %d AND wp_user_id = %d LIMIT 1',
        [$organization_id, (int) $state['accounts']['organization_manager']['id']]
    );
    if ($org_membership === null || $org_membership['role_key'] !== 'organization_manager' || $org_membership['status'] !== 'active') {
        cpms_ds_fail('MEMBERSHIP_CONTRACT', 'Organization Manager membership is missing or invalid');
    }
    $expected_memberships = [
        (int) $state['accounts']['clinic_manager']['id'] => RolesAndCapabilities::ROLE_MANAGER,
        (int) $state['accounts']['doctor']['id'] => RolesAndCapabilities::ROLE_DOCTOR,
        (int) $state['accounts']['receptionist']['id'] => RolesAndCapabilities::ROLE_SECRETARY,
        (int) $state['accounts']['accountant']['id'] => RolesAndCapabilities::ROLE_ACCOUNTANT,
    ];
    $membership_rows = $db->fetchAll(
        'SELECT wp_user_id, role_key, scope_mode, status FROM ' . $db->table('cpms_clinic_memberships')
        . ' WHERE clinic_id = %d ORDER BY wp_user_id',
        [$clinic_id]
    );
    if (count($membership_rows) !== 4) {
        cpms_ds_fail('MEMBERSHIP_CONTRACT', 'exactly four scoped Clinic staff memberships are required');
    }
    foreach ($membership_rows as $row) {
        $user_id = (int) $row['wp_user_id'];
        if (!isset($expected_memberships[$user_id])
            || $expected_memberships[$user_id] !== (string) $row['role_key']
            || $row['scope_mode'] !== 'clinic' || $row['status'] !== 'active'
        ) {
            cpms_ds_fail('MEMBERSHIP_CONTRACT', 'a Clinic membership has an incorrect role, scope, or status');
        }
        $account_evidence[array_search($user_id, array_map(static fn(array $a): int => (int) $a['id'], $state['accounts']), true)]['membership_role'] = (string) $row['role_key'];
    }
    $org_count = (int) $db->fetchValue(
        'SELECT COUNT(*) FROM ' . $db->table('cpms_organization_memberships') . ' WHERE organization_id = %d',
        [$organization_id]
    );
    if ($org_count !== 1) {
        cpms_ds_fail('MEMBERSHIP_CONTRACT', 'unexpected Organization membership count');
    }

    $cpms_ds_stage = 'verify-authorization';
    $admin_id = (int) $state['provisioning_admin_id'];
    if (App::membership_service()->membership_for($clinic_id, $admin_id) !== null) {
        cpms_ds_fail('ADMIN_AUTHORITY', 'the provisioning Administrator received a Clinic membership');
    }
    foreach ([RolesAndCapabilities::PATIENT_READ, RolesAndCapabilities::NOTE_CREATE, RolesAndCapabilities::INVOICE_CREATE, RolesAndCapabilities::PAYMENT_CREATE] as $capability) {
        if (App::authorization_service()->can($admin_id, $clinic_id, $capability)) {
            cpms_ds_fail('ADMIN_AUTHORITY', 'Administrator received implicit clinical/finance authority');
        }
    }
    $positive = [
        'clinic_manager' => RolesAndCapabilities::CONFIG,
        'doctor' => RolesAndCapabilities::NOTE_CREATE,
        'receptionist' => RolesAndCapabilities::PATIENT_CREATE,
        'accountant' => RolesAndCapabilities::PAYMENT_CREATE,
    ];
    foreach ($positive as $key => $capability) {
        if (!App::authorization_service()->can((int) $state['accounts'][$key]['id'], $clinic_id, $capability)) {
            cpms_ds_fail('SCOPED_GRANT', 'required scoped authority is missing for ' . $key);
        }
    }
    $negative = [
        'doctor' => RolesAndCapabilities::PAYMENT_CREATE,
        'receptionist' => RolesAndCapabilities::PRIVATE_NOTE_READ,
        'accountant' => RolesAndCapabilities::NOTE_CREATE,
        'patient' => RolesAndCapabilities::PATIENT_READ,
    ];
    foreach ($negative as $key => $capability) {
        if (App::authorization_service()->can((int) $state['accounts'][$key]['id'], $clinic_id, $capability)) {
            cpms_ds_fail('SCOPED_DENY', 'forbidden authority was granted to ' . $key);
        }
    }
    if (App::authorization_service()->can((int) $state['accounts']['doctor']['id'], $clinic_id + 1000000, RolesAndCapabilities::NOTE_CREATE)) {
        cpms_ds_fail('SCOPED_DENY', 'doctor authority escaped its exact Clinic');
    }

    $cpms_ds_stage = 'verify-pages';
    $page = get_post((int) $state['booking_page_id']);
    $shortcode = '[cpms_public_booking clinic_id="' . $clinic_id . '"]';
    if ($page === null || $page->post_status !== 'publish' || $page->post_content !== $shortcode
        || (string) $state['shortcode'] !== $shortcode || !shortcode_exists('cpms_public_booking')
    ) {
        cpms_ds_fail('SHORTCODE_CONTRACT', 'the exact production public-booking shortcode is not executable');
    }
    foreach (['booking', 'patient', 'staff', 'doctor', 'management'] as $key) {
        if (!is_string($state['links'][$key] ?? null) || !str_starts_with((string) $state['links'][$key], home_url())) {
            cpms_ds_fail('PORTAL_LINKS', 'an actual portal URL is missing or foreign: ' . $key);
        }
    }

    $cpms_ds_stage = 'verify-operational-data';
    $counts = cpms_ds_counts();
    $exact = [
        'cpms_organizations' => 2, // untouched default + synthetic Organization
        'cpms_clinics' => 2,       // untouched default + synthetic Clinic
        'cpms_locations' => 2,     // untouched default + synthetic Location
        'cpms_clinicians' => 1,
        'cpms_clinic_memberships' => 4,
        'cpms_organization_memberships' => 1,
        'cpms_clinician_locations' => 1,
        'cpms_schedule' => 7,
        'cpms_patients' => 1,
        'cpms_patient_identities' => 1,
        'cpms_services' => 1,
    ];
    foreach ($exact as $table => $expected_count) {
        if ($counts[$table] !== $expected_count) {
            cpms_ds_fail('BOUNDED_FIXTURE', $table . ' count differs from the bounded fixture');
        }
    }
    if ($counts['cpms_schedule_slots'] < 2 || $counts['cpms_schedule_slots'] > 3) {
        cpms_ds_fail('BOUNDED_FIXTURE', 'slot count must remain between two and three');
    }
    if (!$after_browser) {
        $initial = [
            'cpms_appointments' => 1,
            'cpms_visits' => 1,
            'cpms_clinical_notes' => 2,
            'cpms_prescriptions' => 1,
            'cpms_invoices' => 1,
            'cpms_invoice_items' => 1,
            'cpms_payments' => 1,
            'cpms_patient_user_links' => 0,
        ];
        foreach ($initial as $table => $expected_count) {
            if ($counts[$table] !== $expected_count) {
                cpms_ds_fail('IDEMPOTENCY', $table . ' changed during repeated provisioning');
            }
        }
    } else {
        $minimum = [
            'cpms_appointments' => 2,
            'cpms_visits' => 2,
            'cpms_clinical_notes' => 4,
            'cpms_prescriptions' => 2,
            'cpms_invoices' => 2,
            'cpms_payments' => 2,
            'cpms_patient_user_links' => 1,
            'cpms_otp_tokens' => 1,
        ];
        foreach ($minimum as $table => $expected_count) {
            if ($counts[$table] < $expected_count) {
                cpms_ds_fail('BROWSER_DATA', $table . ' does not prove the completed browser journey');
            }
        }
        $link = $db->fetchRow(
            'SELECT patient_id, wp_user_id FROM ' . $db->table('cpms_patient_user_links')
            . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            [$clinic_id]
        );
        if ($link === null || (int) $link['patient_id'] !== (int) $state['patient_id']
            || (int) $link['wp_user_id'] !== (int) $state['accounts']['patient']['id']
        ) {
            cpms_ds_fail('OTP_IDENTITY', 'normal OTP verification did not link the exact synthetic Patient account');
        }
    }

    foreach ([
        ['cpms_visits', 'completed_visit_id', 'checked_out'],
        ['cpms_appointments', 'appointment_id', 'confirmed'],
        ['cpms_invoices', 'invoice_id', 'paid'],
    ] as [$table, $key, $expected_status]) {
        $status = $db->fetchValue('SELECT status FROM ' . $db->table($table) . ' WHERE id = %d', [(int) $state[$key]]);
        if ((string) $status !== $expected_status) {
            cpms_ds_fail('OPERATIONAL_STATE', $key . ' has an unexpected status');
        }
    }
    if ($after_browser) {
        // The live Visit is the one created by Reception from the public
        // appointment, then completed by Doctor and checked out after payment.
        // It is deliberately not pre-seeded: a waiting walk-in for the same
        // patient/clinician/location would trigger CPMS's duplicate-active
        // guard and would not prove the real Reception journey.
        $live_visit = $db->fetchRow(
            'SELECT id, appointment_id, status FROM ' . $db->table('cpms_visits')
            . ' WHERE clinic_id = %d AND id <> %d ORDER BY id DESC LIMIT 1',
            [$clinic_id, (int) $state['completed_visit_id']]
        );
        if ($live_visit === null || (int) $live_visit['appointment_id'] <= 0
            || (int) $live_visit['appointment_id'] === (int) $state['appointment_id']
            || (string) $live_visit['status'] !== 'checked_out'
        ) {
            cpms_ds_fail('OPERATIONAL_STATE', 'the browser-created appointment Visit is not checked out');
        }
    }

    $cpms_ds_stage = 'verify-no-external-delivery';
    $settings = App::settingsFactory()->forClinic($clinic_id);
    if ((string) $settings->get('sms.provider', '') !== 'log' || defined('CPMS_DEV_OTP_ECHO')) {
        cpms_ds_fail('SMS_SAFETY', 'only the production offline log provider is allowed');
    }
    $non_log_sms = (int) $db->fetchValue(
        'SELECT COUNT(*) FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d AND provider <> %s',
        [$clinic_id, 'log']
    );
    $online_payments = (int) $db->fetchValue(
        'SELECT COUNT(*) FROM ' . $db->table('cpms_payments') . ' WHERE clinic_id = %d AND method <> %s',
        [$clinic_id, 'other']
    );
    if ($non_log_sms !== 0 || $online_payments !== 0) {
        cpms_ds_fail('EXTERNAL_DELIVERY', 'a non-log SMS or non-synthetic payment method was recorded');
    }
    $otp_rows = $db->fetchAll(
        'SELECT message, vars_json, provider, consumed_at FROM ' . $db->table('cpms_sms_messages')
        . ' WHERE clinic_id = %d AND event = %s',
        [$clinic_id, 'otp']
    );
    foreach ($otp_rows as $row) {
        if ($row['vars_json'] !== null || $row['provider'] !== 'log' || preg_match('/(?<!\d)\d{6}(?!\d)/', (string) $row['message'])) {
            cpms_ds_fail('OTP_STORAGE', 'an OTP message contains plaintext variables/code or a real provider');
        }
    }
    if ($after_browser) {
        $unconsumed = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_otp_tokens')
            . ' WHERE clinic_id = %d AND mobile = %s AND consumed_at IS NULL',
            [$clinic_id, CPMS_DS_MOBILE]
        );
        if ($unconsumed !== 0) {
            cpms_ds_fail('OTP_CONSUMPTION', 'the browser OTP challenge was not consumed');
        }
    }

    $cpms_ds_stage = 'verify-audit';
    $audit = App::audit()->verifyChain(10000);
    if (!$audit['ok']) {
        cpms_ds_fail('AUDIT_CHAIN', 'the production audit chain is invalid');
    }

    echo 'CPMS_DISPOSABLE_VERIFY=' . wp_json_encode([
        'expect' => $expect,
        'ok' => true,
        'accounts' => $account_evidence,
        'counts' => $counts,
        'authorization' => [
            'scoped_grants' => array_keys($positive),
            'scoped_denials' => array_keys($negative),
            'administrator_clinical_finance' => false,
        ],
        'sms_provider' => 'log',
        'real_sms_messages' => $non_log_sms,
        'online_payments' => $online_payments,
        'audit_chain' => true,
        'credential_file_mode' => substr(decoct(((int) fileperms($state_path)) & 0777), -4),
        'secrets_printed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $cpms_ds_error) {
    cpms_ds_report_failure($cpms_ds_error, $cpms_ds_stage);
    exit(1);
}

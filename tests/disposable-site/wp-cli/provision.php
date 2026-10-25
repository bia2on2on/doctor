<?php

declare(strict_types=1);

/**
 * Provision one bounded synthetic facility on an explicitly disposable WP site.
 *
 * Usage (from the repository root):
 *   CPMS_DISPOSABLE_CONFIRM=I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE \
 *   CPMS_DISPOSABLE_STATE=/secure/outside/site/credentials.json \
 *   wp eval-file tests/disposable-site/wp-cli/provision.php --use-include \
 *     --path=/path/to/wp --user=existing_admin
 */

use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Frontend\ManagementPortalShell;
use ClinicCore\Frontend\PatientPortalShell;
use ClinicCore\Frontend\StaffPortalShell;

require __DIR__ . '/common.php';

$cpms_ds_stage = 'bootstrap';

try {
    if (!hash_equals(CPMS_DS_CONFIRM, cpms_ds_env('CPMS_DISPOSABLE_CONFIRM', false))) {
        cpms_ds_fail('CONFIRMATION_REQUIRED', 'explicit empty disposable-site confirmation is required');
    }
    $state_path = cpms_ds_secret_path(cpms_ds_env('CPMS_DISPOSABLE_STATE'));
    $lock = cpms_ds_lock($state_path);
    $actor_id = cpms_ds_admin_id();
    $db = cpms_ds_db();
    if (App::migrations()->currentVersion() !== '2026_10_18_0029') {
        cpms_ds_fail('SCHEMA_VERSION', 'the verified migration set through 2026_10_18_0029 is required');
    }

    if (is_file($state_path)) {
        $state = cpms_ds_load_state($state_path);
        cpms_ds_assert_fixture_owner($state);
        echo 'CPMS_DISPOSABLE=' . cpms_ds_safe_summary($state, true) . PHP_EOL;
        flock($lock, LOCK_UN);
        fclose($lock);
        return;
    }

    $cpms_ds_stage = 'empty-site-preflight';
    cpms_ds_assert_empty_site();

    $cpms_ds_stage = 'protected-state';
    $fixture_id = bin2hex(random_bytes(8));
    $labels = [
        'organization_manager' => 'مدیر سازمان آزمایشی CPMS',
        'clinic_manager' => 'مدیر درمانگاه آزمایشی CPMS',
        'doctor' => 'پزشک آزمایشی CPMS',
        'receptionist' => 'پذیرش آزمایشی CPMS',
        'accountant' => 'حسابدار آزمایشی CPMS',
        'patient' => 'بیمار آزمایشی CPMS',
    ];
    $accounts = [];
    foreach ($labels as $key => $label) {
        $accounts[$key] = [
            'id' => 0,
            'username' => 'cpms-disposable-' . $key . '-' . $fixture_id,
            'password' => bin2hex(random_bytes(24)),
            'display_name' => $label,
        ];
    }
    global $wpdb;
    $state = [
        'format' => CPMS_DS_FORMAT,
        'status' => 'provisioning',
        'fixture_id' => $fixture_id,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'site' => [
            'home_url' => home_url(),
            'table_prefix' => (string) $wpdb->prefix,
            'plugin_version' => defined('CPMS_VERSION') ? (string) CPMS_VERSION : '',
            'schema_version' => App::migrations()->currentVersion(),
        ],
        'provisioning_admin_id' => $actor_id,
        'accounts' => $accounts,
        'patient_mobile' => CPMS_DS_MOBILE,
    ];
    cpms_ds_write_json($state_path, $state);

    $cpms_ds_stage = 'accounts';
    foreach ($state['accounts'] as $key => $account) {
        $patient = $key === 'patient';
        $user_id = wp_insert_user([
            'user_login' => $account['username'],
            'user_pass' => $account['password'],
            // OtpService's existing deterministic identity links only this exact
            // synthetic account; no phone/demographic lookup selects a patient.
            'user_email' => $patient ? CPMS_DS_MOBILE . '@otp.cpms.local' : $account['username'] . '@demo.invalid',
            'display_name' => $account['display_name'],
            'role' => $patient ? RolesAndCapabilities::ROLE_PATIENT : 'subscriber',
        ]);
        if (is_wp_error($user_id) || (int) $user_id <= 0) {
            cpms_ds_fail('USER_CREATE', 'cannot create synthetic account ' . $key);
        }
        $state['accounts'][$key]['id'] = (int) $user_id;
        cpms_ds_write_json($state_path, $state);
    }

    $cpms_ds_stage = 'organization';
    $now = $db->nowUtcSql();
    if (!$db->insert('cpms_organizations', [
        'name' => 'سازمان کاملاً آزمایشی CPMS ' . $fixture_id,
        'slug' => 'cpms-disposable-' . $fixture_id,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ])) {
        cpms_ds_fail('ORGANIZATION_CREATE', 'synthetic Organization insert failed');
    }
    $state['organization_id'] = (int) $db->wpdb_last_insert_id();
    cpms_ds_write_json($state_path, $state);

    $cpms_ds_stage = 'onboarding';
    $onboarding = App::organization_clinic_provisioning_service();
    $onboarding->bootstrap_owner(
        $actor_id,
        (int) $state['organization_id'],
        (int) $state['accounts']['organization_manager']['id']
    );
    $clinic = $onboarding->provision(
        (int) $state['accounts']['organization_manager']['id'],
        (int) $state['organization_id'],
        'درمانگاه آزمایشی CPMS',
        'cpms-disposable-clinic-' . $fixture_id,
        'Asia/Tehran',
        'محل آزمایشی CPMS',
        'نشانی کاملاً فرضی؛ هیچ نشانی واقعی نیست',
        null
    );
    $state['clinic_id'] = (int) $clinic['clinic_id'];
    $state['location_id'] = (int) $clinic['location_id'];
    cpms_ds_write_json($state_path, $state);

    $cpms_ds_stage = 'memberships';
    $onboarding->designate_clinic_manager(
        (int) $state['accounts']['organization_manager']['id'],
        (int) $state['organization_id'],
        (int) $state['clinic_id'],
        (int) $state['accounts']['clinic_manager']['id']
    );
    $roles = [
        'doctor' => RolesAndCapabilities::ROLE_DOCTOR,
        'receptionist' => RolesAndCapabilities::ROLE_SECRETARY,
        'accountant' => RolesAndCapabilities::ROLE_ACCOUNTANT,
    ];
    foreach ($roles as $key => $role) {
        App::membership_service()->create_membership(
            (int) $state['clinic_id'],
            (int) $state['accounts'][$key]['id'],
            $role,
            'clinic',
            $actor_id
        );
    }

    $previous_scope = ScopeContext::tryGet();
    ScopeContext::set(
        ClinicScope::forClinic((int) $state['clinic_id'], (int) $state['location_id'])
            ->withOrganization((int) $state['organization_id'])
    );
    try {
        $cpms_ds_stage = 'clinician';
        $clinician_id = App::clinicianRepository()->create((int) $state['clinic_id'], [
            'wp_user_id' => (int) $state['accounts']['doctor']['id'],
            'full_name' => 'دکتر کاملاً آزمایشی CPMS',
            'specialty' => 'پزشک عمومی — داده فرضی',
            'room' => 'SYNTHETIC',
            'is_active' => 1,
        ]);
        if ($clinician_id <= 0) {
            cpms_ds_fail('CLINICIAN_CREATE', 'synthetic Clinician insert failed');
        }
        $state['clinician_id'] = $clinician_id;
        App::membership_service()->assign_clinician_locations(
            $clinician_id,
            [(int) $state['location_id']],
            (int) $state['location_id']
        );
        cpms_ds_write_json($state_path, $state);

        $cpms_ds_stage = 'schedules';
        // ScheduleService::create() enqueues the existing global slots.generate
        // handler. Do not invoke that unbounded side effect on shared hosts.
        // These seven fixed rows and at most three slots belong only to this
        // exact synthetic Clinician/Location; all other tenants remain untouched.
        for ($day = 0; $day <= 6; $day++) {
            if (!$db->insert('cpms_schedule', [
                'clinic_id' => (int) $state['clinic_id'],
                'clinician_id' => $clinician_id,
                'location_id' => (int) $state['location_id'],
                'day_of_week' => $day,
                'start_time' => '00:00:00',
                'end_time' => '23:59:00',
                'break_start' => null,
                'break_end' => null,
                'appointment_duration_min' => 20,
                'slot_capacity' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])) {
                cpms_ds_fail('SCHEDULE_CREATE', 'bounded synthetic schedule persistence failed');
            }
        }

        $cpms_ds_stage = 'patient';
        $patient = App::patientService()->create([
            'first_name' => 'بیمار',
            'last_name' => 'کاملاً آزمایشی',
            'mobile' => CPMS_DS_MOBILE,
            'gender' => 'unknown',
            'birth_date' => null,
        ], (int) $state['accounts']['receptionist']['id']);
        $state['patient_id'] = (int) $patient['id'];
        $state['identity_id'] = (int) ($patient['identity_id'] ?? 0);
        cpms_ds_write_json($state_path, $state);

        $cpms_ds_stage = 'safe-settings';
        $settings = App::settingsFactory()->forClinic((int) $state['clinic_id']);
        $settings->set('sms.provider', 'log');
        $settings->set('booking.min_lead_hours', 0);
        if (defined('CPMS_DEV_OTP_ECHO')) {
            cpms_ds_fail('UNSAFE_OTP_CONFIG', 'CPMS_DEV_OTP_ECHO must not be defined');
        }

        $cpms_ds_stage = 'slots';
        $local = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        $first_slot = $local->modify('+30 minutes');
        if ($first_slot->format('Y-m-d') !== $local->format('Y-m-d')) {
            $first_slot = $local->modify('+1 minute');
        }
        if ($first_slot->format('Y-m-d') !== $local->format('Y-m-d')) {
            cpms_ds_fail('SLOT_TIME', 'a same-day future synthetic slot is required');
        }
        $slot_date = $first_slot->format('Y-m-d');
        $slot_id = 0;
        $staff_slot_id = 0;
        $staff_slot_time = '';
        $now = $db->nowUtcSql();
        foreach ([0, 20, 40] as $offset) {
            $slot = $first_slot->modify('+' . $offset . ' minutes');
            if ($slot->format('Y-m-d') !== $slot_date) {
                continue;
            }
            $slot_time = $slot->format('H:i:00');
            $db->query(
                'INSERT IGNORE INTO ' . $db->table('cpms_schedule_slots')
                . ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)'
                . ' VALUES (%d, %d, %d, %s, %s, %d, %d, 0, 0, 1, %s, %s, %s)',
                [(int) $state['clinic_id'], (int) $state['location_id'], $clinician_id, $slot_date, $slot_time, 20, 1, 'manual', $now, $now]
            );
            $current_slot_id = (int) $db->fetchValue(
                'SELECT id FROM ' . $db->table('cpms_schedule_slots')
                . ' WHERE clinic_id = %d AND location_id = %d AND clinician_id = %d AND slot_date = %s AND slot_time = %s LIMIT 1',
                [(int) $state['clinic_id'], (int) $state['location_id'], $clinician_id, $slot_date, $slot_time]
            );
            if ($current_slot_id <= 0) {
                cpms_ds_fail('SLOT_CREATE', 'bounded synthetic slot persistence failed');
            }
            if ($offset === 0) {
                $slot_id = $current_slot_id;
            }
            if ($offset === 20) {
                $staff_slot_id = $current_slot_id;
                $staff_slot_time = $slot_time;
            }
        }
        if ($slot_id <= 0 || $staff_slot_id <= 0 || $staff_slot_time === '') {
            cpms_ds_fail('SLOT_TIME', 'provision at least 50 minutes before Tehran midnight for the same-day journey');
        }
        $state['slot_id'] = $slot_id;
        $state['slot_date'] = $slot_date;
        cpms_ds_write_json($state_path, $state);

        $cpms_ds_stage = 'appointment';
        $appointment = App::bookingService()->createByStaff(
            (int) $state['accounts']['receptionist']['id'],
            (int) $state['patient_id'],
            $clinician_id,
            $slot_date,
            $staff_slot_time,
            'SYNTHETIC-' . $fixture_id,
            $staff_slot_id
        );
        $state['appointment_id'] = (int) $appointment['appointment_id'];
        cpms_ds_write_json($state_path, $state);

        $cpms_ds_stage = 'service-catalog';
        $service = App::financeService()->createService((int) $state['accounts']['clinic_manager']['id'], [
            'code' => 'SYNTHETIC-CONSULT',
            'name' => 'ویزیت آزمایشی — بدون ارزش واقعی',
            'price' => 1000,
        ]);
        $state['service_id'] = (int) $service['id'];
        cpms_ds_write_json($state_path, $state);

        $cpms_ds_stage = 'clinical-finance';
        $visit = App::visitService()->walkIn(
            (int) $state['accounts']['receptionist']['id'],
            (int) $state['patient_id'],
            $clinician_id,
            ['note' => 'داده کاملاً فرضی']
        );
        $visit_id = (int) $visit['id'];
        $doctor_id = (int) $state['accounts']['doctor']['id'];
        App::visitService()->transition($doctor_id, $visit_id, 'call', ['room' => 'SYNTHETIC']);
        App::visitService()->transition($doctor_id, $visit_id, 'start');
        App::clinicalService()->addNote($doctor_id, $visit_id, [
            'category' => 'chief_complaint',
            'visibility' => 'patient_visible',
            'content_text' => 'شکایت فرضی صرفاً برای آزمون نرم‌افزار؛ هیچ داده پزشکی واقعی نیست.',
        ]);
        App::clinicalService()->addNote($doctor_id, $visit_id, [
            'category' => 'examination',
            'visibility' => 'patient_visible',
            'content_text' => 'شرح‌حال و معاینه فرضی برای نمایش فرایند.',
        ]);
        $prescription = App::clinicalService()->createPrescription($doctor_id, $visit_id, [
            'items' => [[
                'generic_name' => 'SYNTHETIC-NOT-A-DRUG',
                'dose' => '0 demo-unit',
                'frequency' => 'test only',
                'form' => 'tablet',
                'route' => 'oral',
                'duration_days' => 1,
                'instructions' => 'نسخه غیرواقعی؛ هرگز مصرف نشود.',
            ]],
        ]);
        App::clinicalService()->finalizePrescription($doctor_id, (int) $prescription['id']);
        App::clinicalService()->completeConsultation($doctor_id, $visit_id);
        $accountant_id = (int) $state['accounts']['accountant']['id'];
        $invoice = App::financeService()->issueInvoice($accountant_id, [
            'visit_id' => $visit_id,
            'items' => [[
                'service_id' => (int) $state['service_id'],
                'description' => 'ویزیت آزمایشی',
                'quantity' => 1,
                'unit_price' => 1000,
                'discount' => 0,
            ]],
            'discount' => 0,
            'tax' => 0,
        ]);
        App::financeService()->recordPayment($accountant_id, (int) $invoice['id'], [
            'amount' => 1000,
            'method' => 'other',
            'transaction_ref' => 'SYNTHETIC-NO-PAYMENT',
        ], 'synthetic-payment-' . $fixture_id);
        App::visitService()->transition((int) $state['accounts']['receptionist']['id'], $visit_id, 'check_out');
        $state['completed_visit_id'] = $visit_id;
        $state['invoice_id'] = (int) $invoice['id'];
        cpms_ds_write_json($state_path, $state);

        $cpms_ds_stage = 'booking-page';
        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_author' => $actor_id,
            'post_name' => 'cpms-disposable-booking-' . $fixture_id,
            'post_title' => 'رزرو آزمایشی CPMS — داده فرضی',
            'post_content' => '[cpms_public_booking clinic_id="' . (int) $state['clinic_id'] . '"]',
        ], true);
        if (is_wp_error($page_id) || (int) $page_id <= 0) {
            cpms_ds_fail('BOOKING_PAGE', 'synthetic booking page creation failed');
        }
        $state['booking_page_id'] = (int) $page_id;
        $state['shortcode'] = '[cpms_public_booking clinic_id="' . (int) $state['clinic_id'] . '"]';
    } finally {
        App::replaceExplicitScope($previous_scope);
    }

    $cpms_ds_stage = 'portal-links';
    $state['links'] = [
        'booking' => get_permalink((int) $state['booking_page_id']),
        'patient' => PatientPortalShell::portal_url(),
        'staff' => StaffPortalShell::portal_url(),
        'doctor' => DoctorPortalShell::portal_url(),
        'management' => ManagementPortalShell::portal_url(),
    ];
    if (count(array_filter($state['links'], 'is_string')) !== 5 || in_array('', $state['links'], true)) {
        cpms_ds_fail('PORTAL_LINKS', 'actual CPMS portal URLs could not be resolved');
    }
    $state['status'] = 'active';
    $state['provisioned_at'] = gmdate('Y-m-d\TH:i:s\Z');
    cpms_ds_write_json($state_path, $state);

    App::audit()->log(
        'DISPOSABLE_SITE_PROVISIONED',
        ['wp_user_id' => $actor_id, 'role' => 'wordpress_administrator'],
        'clinic',
        (int) $state['clinic_id'],
        null,
        null,
        [
            'fixture_id' => $fixture_id,
            'accounts' => 6,
            'bounded_schedule_days' => 7,
            'sms_provider' => 'log',
            'payment_method' => 'other',
        ],
        ['op' => 'external_disposable_provision'],
        (int) $state['clinic_id']
    );

    $cpms_ds_stage = 'complete';
    echo 'CPMS_DISPOSABLE=' . cpms_ds_safe_summary($state, false) . PHP_EOL;
    flock($lock, LOCK_UN);
    fclose($lock);
} catch (Throwable $cpms_ds_error) {
    if (isset($state_path, $state) && is_array($state) && is_file($state_path)) {
        try {
            $state['status'] = 'failed';
            $state['failed_stage'] = $cpms_ds_stage;
            cpms_ds_write_json($state_path, $state);
        } catch (Throwable) {
            // Preserve the original safe error; never dump state or credentials.
        }
    }
    cpms_ds_report_failure($cpms_ds_error, $cpms_ds_stage);
    exit(1);
}

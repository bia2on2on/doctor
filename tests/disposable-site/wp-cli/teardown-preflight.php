<?php

declare(strict_types=1);

/** Prove the explicit whole-database teardown target is the same disposable site. */

require __DIR__ . '/common.php';

$cpms_ds_stage = 'teardown-preflight';

try {
    cpms_ds_admin_id();
    if (!hash_equals(CPMS_DS_DESTROY_CONFIRM, cpms_ds_env('CPMS_DISPOSABLE_CONFIRM', false))) {
        cpms_ds_fail('DESTROY_CONFIRMATION_REQUIRED', 'explicit whole-database destruction confirmation is required');
    }
    $state_path = cpms_ds_secret_path(cpms_ds_env('CPMS_DISPOSABLE_STATE'));
    $state = cpms_ds_load_state($state_path, false);
    $status = (string) $state['status'];
    if (!in_array($status, ['provisioning', 'active', 'failed'], true)) {
        cpms_ds_fail('STATE_INVALID', 'teardown requires a provisioning, active, or failed disposable marker');
    }
    $clinic_id = (int) ($state['clinic_id'] ?? 0);
    if ($clinic_id > 0) {
        cpms_ds_assert_fixture_owner($state);
    } else {
        // Only a partially-created fixture may lack a Clinic. An active or
        // arbitrary/non-demo JSON marker must never authorize database reset.
        $accounts = (array) ($state['accounts'] ?? []);
        $expected = ['organization_manager', 'clinic_manager', 'doctor', 'receptionist', 'accountant', 'patient'];
        if ($status === 'active' || array_keys($accounts) !== $expected
            || (int) ($state['provisioning_admin_id'] ?? 0) <= 0
            || (string) ($state['patient_mobile'] ?? '') !== CPMS_DS_MOBILE
        ) {
            cpms_ds_fail('STATE_OWNERSHIP', 'teardown requires a verified disposable fixture marker');
        }
    }
    echo 'CPMS_DISPOSABLE_TEARDOWN_PREFLIGHT=' . wp_json_encode([
        'ok' => true,
        'fixture_id' => (string) $state['fixture_id'],
        'status' => (string) $state['status'],
        'whole_database' => true,
    ]) . PHP_EOL;
} catch (Throwable $cpms_ds_error) {
    cpms_ds_report_failure($cpms_ds_error, $cpms_ds_stage);
    exit(1);
}

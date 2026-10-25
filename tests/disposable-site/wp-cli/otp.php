<?php

declare(strict_types=1);

/**
 * Bind a fresh CSPRNG code to the latest real synthetic OTP challenge.
 *
 * This is a disposable-database test fixture, not an authentication provider or
 * production bypass. The browser must still use the normal OTP verify endpoint.
 * No plaintext code, pepper, credential, or mobile number is printed.
 */

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;

require __DIR__ . '/common.php';

$cpms_ds_stage = 'otp-bootstrap';

try {
    cpms_ds_admin_id();
    $state_path = cpms_ds_secret_path(cpms_ds_env('CPMS_DISPOSABLE_STATE'));
    $otp_path = cpms_ds_secret_path(cpms_ds_env('CPMS_DISPOSABLE_OTP_FILE'));
    $state = cpms_ds_load_state($state_path);
    cpms_ds_assert_fixture_owner($state);
    if (defined('CPMS_DEV_OTP_ECHO')) {
        cpms_ds_fail('UNSAFE_OTP_CONFIG', 'CPMS_DEV_OTP_ECHO must not be defined');
    }
    $clinic_id = (int) $state['clinic_id'];
    $settings = App::settingsFactory()->forClinic($clinic_id);
    if ((string) $settings->get('sms.provider', '') !== 'log') {
        cpms_ds_fail('SMS_SAFETY', 'the synthetic Clinic must use the offline log provider');
    }

    $cpms_ds_stage = 'otp-challenge';
    $db = cpms_ds_db();
    $row = $db->fetchRow(
        'SELECT id, expires_at FROM ' . $db->table('cpms_otp_tokens')
        . ' WHERE clinic_id = %d AND mobile = %s AND purpose = %s AND consumed_at IS NULL AND attempts = 0'
        . ' ORDER BY id DESC LIMIT 1',
        [$clinic_id, CPMS_DS_MOBILE, 'login']
    );
    if ($row === null || strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
        cpms_ds_fail('OTP_CHALLENGE', 'no live unconsumed synthetic OTP challenge exists; request one through the real UI first');
    }
    $pepper = defined('CPMS_PEPPER') ? (string) CPMS_PEPPER : (string) get_option('cpms_otp_pepper', '');
    if ($pepper === '') {
        cpms_ds_fail('OTP_CHALLENGE', 'the normal OTP request did not initialize its server-side pepper');
    }

    $code = OtpPolicy::generateCode(6);
    $updated = $db->query(
        'UPDATE ' . $db->table('cpms_otp_tokens') . ' SET code_hash = %s'
        . ' WHERE id = %d AND consumed_at IS NULL AND attempts = 0',
        [OtpPolicy::hashCode($code, $pepper), (int) $row['id']]
    );
    if ($updated !== 1) {
        cpms_ds_fail('OTP_CHALLENGE', 'the synthetic challenge changed concurrently; request a fresh code');
    }
    unset($pepper);

    cpms_ds_write_json($otp_path, [
        'format' => CPMS_DS_FORMAT,
        'fixture_id' => (string) $state['fixture_id'],
        'token_id' => (int) $row['id'],
        'code' => $code,
        'expires_at' => (string) $row['expires_at'],
    ]);
    $code = '';
    echo 'CPMS_DISPOSABLE_OTP=' . wp_json_encode([
        'ready' => true,
        'token_id' => (int) $row['id'],
        'code_printed' => false,
        'credential_mode' => '0600',
    ]) . PHP_EOL;
} catch (Throwable $cpms_ds_error) {
    if (isset($code)) {
        $code = '';
    }
    cpms_ds_report_failure($cpms_ds_error, $cpms_ds_stage);
    exit(1);
}

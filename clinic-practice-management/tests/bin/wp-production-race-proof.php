<?php
/**
 * Disposable WP-CLI worker for real OTP and BookingService contention tests.
 * Never run on a live site. A test-only in-memory provider captures synthetic
 * OTP codes; no code, token, identifier or raw exception is logged. Synthetic
 * users, slots, patients, visits, invoices and boundary timestamps are setup
 * fixtures; the exercised writes use the production application services.
 * WP-CLI eval-file intentionally cannot have a strict_types declaration.
 */

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;

if (!defined('WP_CLI') || !WP_CLI || getenv('CPMS_PROOF_DIR') !== '/tmp/cpms-production-proof') {
    exit(1);
}
$dir = '/tmp/cpms-production-proof';
$mode = (string) getenv('CPMS_PROOF_MODE');
$round = (string) getenv('CPMS_PROOF_ROUND');
$index = (int) getenv('CPMS_PROOF_INDEX');

final class CpmsProofFailure extends RuntimeException {}

final class CpmsProofSmsProvider implements SmsProviderInterface
{
    public ?string $code = null;
    public function id(): string { return 'log'; }
    public function label(): string { return 'Synthetic in-memory provider'; }
    public function capabilities(): array { return ['text' => true, 'template' => false, 'otp_template' => false, 'delivery_status' => false, 'sender_list' => false, 'balance' => false, 'bulk' => false]; }
    public function authMethods(): array { return []; }
    public function authFields(): array { return []; }
    public function testConnection(array $creds): array { return ['ok' => true, 'message' => 'ok']; }
    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array
    {
        if (preg_match('/(?<![0-9])[0-9]{6}(?![0-9])/', $message, $matches) === 1) {
            $this->code = $matches[0];
        }
        return ['provider_ref' => 'synthetic'];
    }
    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array
    {
        $this->code = isset($variables['otp_code']) ? (string) $variables['otp_code'] : null;
        return ['provider_ref' => 'synthetic'];
    }
    public function mapStatus(string $providerStatus): string { return 'SENT'; }
    public function fetchSenders(array $creds): ?array { return null; }
    public function fetchBalance(array $creds): ?array { return null; }
}

$require = static function (bool $condition, string $code): void {
    if (!$condition) {
        throw new CpmsProofFailure($code);
    }
};
$write = static function (string $path, array $value): void {
    if (file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('proof-file');
    }
};
$await = static function (string $label) use ($dir, $index): void {
    $ready = $dir . '/ready-' . $label . '-' . $index;
    if (file_put_contents($ready, 'ready', LOCK_EX) === false) {
        throw new RuntimeException('proof-ready');
    }
    $deadline = microtime(true) + 180;
    while (!is_file($dir . '/release-' . $label)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('proof-barrier-timeout');
        }
        usleep(10000);
        clearstatcache(true, $dir . '/release-' . $label);
    }
};

try {
    $fixture = json_decode((string) file_get_contents('/tmp/cpms-reception-proof-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    $clinic = (int) $fixture['clinic_a'];
    $location = (int) $fixture['a1'];
    $clinician = (int) $fixture['browser_clinician'];
    $require($clinic > 0 && $location > 0 && $clinician > 0, 'fixture-identifiers');
    App::replaceExplicitScope(ClinicScope::forClinic($clinic));
    $provider = new CpmsProofSmsProvider();
    App::providers()->register($provider);

    if ($mode === 'setup') {
        $db = App::db();
        $date = gmdate('Y-m-d', time() + 7 * 86400);
        $slots = [];
        foreach (['10' => '10:00:00', '25' => '11:00:00', '50' => '12:00:00', 'rollback' => '13:00:00'] as $size => $time) {
            $ok = $db->insert('cpms_schedule_slots', [
                'clinic_id' => $clinic, 'location_id' => $location, 'clinician_id' => $clinician,
                'slot_date' => $date, 'slot_time' => $time, 'duration_min' => 30,
                'capacity' => 1, 'booked_count' => 0, 'held_count' => 0, 'is_open' => 1,
                'generated_from' => 'manual', 'created_at' => $db->nowUtcSql(), 'updated_at' => $db->nowUtcSql(),
            ]);
            $slot = (int) $db->wpdb_last_insert_id();
            $require($ok && $slot > 0, 'fixture-slot');
            $slots[(string) $size] = ['id' => $slot, 'date' => $date, 'time' => substr($time, 0, 5)];
        }
        // Independent mixed-writer fixtures: authorization is supplied by real
        // Clinic memberships, not bypassed or mocked. These rows are disposable.
        $manager = get_user_by('login', (string) $fixture['manager_login']);
        $doctor_id = (int) $db->fetchValue('SELECT wp_user_id FROM ' . $db->table('cpms_clinicians') . ' WHERE id = %d', [$clinician]);
        $accountant = wp_create_user('ci_mixed_accountant', wp_generate_password(24, true, true), 'ci-mixed-accountant@example.test');
        $require($manager instanceof WP_User && $doctor_id > 0 && !is_wp_error($accountant) && (int) $accountant > 0, 'mixed-users');
        $accountant = (int) $accountant;
        get_userdata($accountant)->set_role('cpms_accountant');
        $now = $db->nowUtcSql();
        // Finance exclusively locks its Clinic to allocate a payment number.
        // Put it in Clinic B so the explicit audit-guard barrier does not hold
        // that lock while A's clinical/SMS FK inserts wait for it. The audit
        // chain/guard itself remains genuinely shared across both Clinics.
        $finance_clinic = (int) $fixture['clinic_b'];
        $finance_location = (int) $fixture['b1'];
        $require($finance_clinic > 0 && $finance_clinic !== $clinic && $finance_location > 0, 'mixed-finance-scope');
        $require($db->insert('cpms_clinic_memberships', [
            'clinic_id' => $finance_clinic, 'wp_user_id' => $accountant, 'role_key' => 'cpms_accountant',
            'scope_mode' => 'clinic', 'status' => 'active', 'is_primary' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]), 'mixed-membership');
        $require($db->insert('cpms_clinicians', [
            'clinic_id' => $finance_clinic, 'full_name' => 'پزشک مالی آزمایشی', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]), 'mixed-finance-clinician');
        $finance_clinician = (int) $db->wpdb_last_insert_id();
        $require($finance_clinician > 0, 'mixed-finance-clinician-id');
        $patients = [];
        foreach (['clinical' => $clinic, 'finance' => $finance_clinic] as $kind => $patient_clinic) {
            $require($db->insert('cpms_patients', [
                'clinic_id' => $patient_clinic, 'mrn' => 'CI-MIXED-' . $kind,
                'first_name' => 'نمونه', 'last_name' => 'آزمایشی', 'mobile' => '09128000000',
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]), 'mixed-patient');
            $patients[$kind] = (int) $db->wpdb_last_insert_id();
            $require($patients[$kind] > 0, 'mixed-patient-id');
        }
        $visits = [];
        foreach (['clinical' => 'in_consultation', 'finance' => 'awaiting_payment'] as $kind => $status) {
            $require($db->insert('cpms_visits', [
                'clinic_id' => $kind === 'clinical' ? $clinic : $finance_clinic,
                'location_id' => $kind === 'clinical' ? $location : $finance_location,
                'clinician_id' => $kind === 'clinical' ? $clinician : $finance_clinician,
                'patient_id' => $patients[$kind],
                'source' => 'walk_in', 'status' => $status, 'visit_date' => gmdate('Y-m-d'), 'check_in_at' => $now,
                'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]), 'mixed-visit');
            $visits[$kind] = (int) $db->wpdb_last_insert_id();
            $require($visits[$kind] > 0, 'mixed-visit-id');
        }
        $require($db->insert('cpms_invoices', [
            'clinic_id' => $finance_clinic, 'invoice_number' => 'CI-MIXED-INV', 'patient_id' => $patients['finance'],
            'visit_id' => $visits['finance'], 'status' => 'open', 'subtotal' => '100.00', 'total' => '100.00',
            'currency' => 'IRR', 'paid_amount' => '0.00', 'balance' => '100.00',
            'issued_by_wp_user_id' => $accountant, 'created_at' => $now, 'updated_at' => $now,
        ]), 'mixed-invoice');
        $invoice = (int) $db->wpdb_last_insert_id();
        $require($invoice > 0, 'mixed-invoice-id');

        $shared_mobile = '09129990000';
        $request = App::otpService()->request($shared_mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic);
        $require($request['sms_sent'] && is_string($provider->code) && preg_match('/^[0-9]{6}$/D', $provider->code) === 1, 'otp-fixture-delivery');
        $shared_code = $provider->code;
        $wrong_mobile = '09129990001';
        $provider->code = null;
        $request = App::otpService()->request($wrong_mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic);
        $require($request['sms_sent'] && is_string($provider->code) && preg_match('/^[0-9]{6}$/D', $provider->code) === 1, 'otp-wrong-fixture-delivery');
        $wrong_code = $provider->code === '000000' ? '000001' : '000000';
        $wrong_id = (int) $db->fetchValue(
            'SELECT id FROM ' . $db->table('cpms_otp_tokens') . ' WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1',
            [$wrong_mobile, OtpService::PURPOSE_LOGIN]
        );
        $require($wrong_id > 0, 'otp-wrong-fixture-id');
        $write($dir . '/fixture.json', [
            'slots' => $slots, 'mobile' => $shared_mobile, 'code' => $shared_code,
            'wrong_mobile' => $wrong_mobile, 'wrong_code' => $wrong_code, 'wrong_id' => $wrong_id,
            'mixed' => [
                'doctor' => $doctor_id, 'accountant' => $accountant, 'manager' => (int) $manager->ID,
                'clinical_visit' => $visits['clinical'], 'invoice' => $invoice, 'finance_clinic' => $finance_clinic,
            ],
        ]);
        echo "PRODUCTION_RACE_SETUP ok\n";
        return;
    }

    $state = json_decode((string) file_get_contents($dir . '/fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($mode === 'boundaries') {
        // Deterministic clock-boundary probes: only synthetic fixture timestamps
        // are advanced; every request/verification and audit uses real services.
        $db = App::db();
        $otp = App::otpService();
        $expectOtp = static function (callable $action, string $expected, string $marker) use ($require): OtpException {
            try {
                $action();
            } catch (OtpException $error) {
                $require($error->apiCode() === $expected, $marker);
                return $error;
            }
            throw new CpmsProofFailure($marker);
        };
        $isPersian = static fn (string $message): bool => preg_match('/[\x{0600}-\x{06FF}]/u', $message) === 1;
        $issue = static function (string $mobile) use ($otp, $clinic, $provider, $require): array {
            $provider->code = null;
            $result = $otp->request($mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic);
            $require($result['sms_sent'] && $result['expires_in'] === 120 && is_string($provider->code), 'boundary-issue');
            return [$result, $provider->code];
        };
        $latest = static function (string $mobile) use ($db): int {
            return (int) $db->fetchValue(
                'SELECT id FROM ' . $db->table('cpms_otp_tokens') . ' WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1',
                [$mobile, OtpService::PURPOSE_LOGIN]
            );
        };

        $mobile = '09127000001';
        [$first] = $issue($mobile);
        $require($first['retry_after'] === 60, 'boundary-send-retry');
        $id = $latest($mobile);
        $require($id > 0, 'boundary-cooldown-id');
        $cooldown = $expectOtp(static fn () => $otp->request($mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic), 'CLINIC_OTP_COOLDOWN', 'boundary-cooldown');
        $cooldown_data = $cooldown->getData();
        $require($isPersian($cooldown->getMessage()) && ($cooldown_data['ttl'] ?? 0) === 120
            && ($cooldown_data['retry_after'] ?? 0) >= 1 && ($cooldown_data['retry_after'] ?? 0) <= 60, 'boundary-cooldown-data');
        $require($db->update('cpms_otp_tokens', ['created_at' => gmdate('Y-m-d H:i:s', time() - 61) . '.000'], ['id' => $id]) === 1, 'boundary-cooldown-fixture');
        $issue($mobile); // At/after the cooldown boundary, the third rate hit is allowed.
        $daily = $expectOtp(static fn () => $otp->request($mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic), 'CLINIC_OTP_DAILY_LIMIT', 'boundary-daily');
        $daily_data = $daily->getData();
        $require($isPersian($daily->getMessage()) && ($daily_data['retry_after'] ?? 0) >= 1
            && ($daily_data['retry_after'] ?? 0) <= 86400
            && abs((int) ($daily_data['reset_at'] ?? 0) - time() - (int) ($daily_data['retry_after'] ?? 0)) <= 2, 'boundary-daily-data');

        $mobile = '09127000002';
        [, $expired_code] = $issue($mobile);
        $id = $latest($mobile);
        $require($id > 0 && $db->update('cpms_otp_tokens', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1) . '.000'], ['id' => $id]) === 1, 'boundary-expiry-fixture');
        $expired = $expectOtp(static fn () => $otp->verify($mobile, $expired_code), 'CLINIC_OTP_EXPIRED', 'boundary-expired');
        $require($isPersian($expired->getMessage()) && $db->fetchValue('SELECT consumed_at FROM ' . $db->table('cpms_otp_tokens') . ' WHERE id = %d', [$id]) === null, 'boundary-expiry-data');

        $mobile = '09127000003';
        [, $valid_code] = $issue($mobile);
        $wrong = $valid_code === '000000' ? '000001' : '000000';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $expected = $attempt === 5 ? 'CLINIC_OTP_LOCKED' : 'CLINIC_OTP_INVALID';
            $failure = $expectOtp(static fn () => $otp->verify($mobile, $wrong), $expected, 'boundary-attempt');
            $data = $failure->getData();
            $require($isPersian($failure->getMessage()) && ($data['remaining'] ?? -1) === 5 - $attempt, 'boundary-attempt-data');
            if ($attempt === 5) {
                $require(($data['retry_after'] ?? 0) >= 1 && ($data['retry_after'] ?? 0) <= 900, 'boundary-lock-retry');
            }
        }
        $locked = $expectOtp(static fn () => $otp->verify($mobile, $valid_code), 'CLINIC_OTP_LOCKED', 'boundary-locked');
        $require($isPersian($locked->getMessage()) && ($locked->getData()['retry_after'] ?? 0) >= 1, 'boundary-locked-data');
        $id = $latest($mobile);
        $require($id > 0 && $db->update('cpms_otp_tokens', ['locked_until' => gmdate('Y-m-d H:i:s', time() - 1) . '.000'], ['id' => $id]) === 1, 'boundary-unlock-fixture');
        $unlocked = $otp->verify($mobile, $valid_code);
        $require($unlocked['session_issued'] && (int) $unlocked['user_id'] > 0, 'boundary-unlocked');
        echo "PRODUCTION_OTP_BOUNDARIES cooldown=ok daily=ok ttl_expired=ok lockout=ok unlock=ok persian=ok retry_after=ok fixture_clock=adjusted\n";
        return;
    }
    if ($mode === 'rollback') {
        $mobile = '09126009999';
        $request = App::otpService()->request($mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic);
        $require($request['sms_sent'] && is_string($provider->code), 'rollback-otp');
        $verified = App::otpService()->verify($mobile, $provider->code);
        $user = (int) $verified['user_id'];
        $require($verified['session_issued'] && $user > 0, 'rollback-session');
        wp_set_current_user($user);
        $slot = $state['slots']['rollback'];
        $db = App::db();
        $before = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs') . ' WHERE action = %s', ['HOLD_CREATED']);
        $guard_failed = false;
        try {
            $db->transactional(static function () use ($db, $slot, $user, $clinician, $location, $before, $require, &$guard_failed): void {
                $require($db->delete('cpms_audit_seq', ['id' => 1]), 'rollback-guard-delete');
                try {
                    App::bookingService()->hold($user, $clinician, (string) $slot['date'], (string) $slot['time'], (int) $slot['id'], null, $location);
                } catch (RuntimeException $error) {
                    $guard_failed = str_contains($error->getMessage(), 'audit guard row missing');
                }
                $held = (int) $db->fetchValue('SELECT held_count FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d', [(int) $slot['id']]);
                $holds = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_slot_holds') . ' WHERE slot_id = %d', [(int) $slot['id']]);
                $audits = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_audit_logs') . ' WHERE action = %s', ['HOLD_CREATED']);
                $require($guard_failed && $held === 0 && $holds === 0 && $audits === $before, 'booking-audit-rollback');
                throw new RuntimeException('restore-proof-guard');
            });
        } catch (RuntimeException $error) {
            $require($error->getMessage() === 'restore-proof-guard', 'rollback-boundary');
        }
        $require($guard_failed && (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_audit_seq') . ' WHERE id = 1') === 1, 'rollback-guard-restored');
        echo "PRODUCTION_BOOKING_ROLLBACK mandatory_audit=failed capacity=restored holds=0 guard=restored\n";
        return;
    }
    $require((in_array($round, ['otp', 'wrong', 'mixed', '10', '25', '50'], true) || preg_match('/^issue[0-4]$/D', $round) === 1) && $index >= 0 && $index < 50, 'worker-arguments');
    $status = 'error';
    $code = 'UNEXPECTED';
    $replay = false;
    $persian = false;
    $retry_valid = false;
    $elapsed_ms = 0.0;

    if ($mode === 'otp-issue' && preg_match('/^issue([0-4])$/D', $round, $issue_match) === 1 && $index < 3) {
        // Synchronize immediately before acquiring the per-mobile issuance
        // guard, after the rate counters pass and before any row lock is held.
        $mobile = '09129991' . str_pad($issue_match[1], 3, '0', STR_PAD_LEFT);
        $guard_prefix = 'INSERT INTO ' . App::db()->table('cpms_rate_limits');
        $reached = false;
        $start = null;
        $filter = static function (string $query) use ($guard_prefix, $await, $round, &$reached, &$start): string {
            if (str_starts_with($query, $guard_prefix) && str_contains($query, 'otp-send-guard:') && !$reached) {
                $reached = true;
                $await($round);
                $start = hrtime(true);
            }
            return $query;
        };
        add_filter('query', $filter);
        try {
            $request = App::otpService()->request($mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic);
            $require($reached && $request['sms_sent'] && is_string($provider->code), 'otp-issue-delivery');
            $status = 'issued';
            $code = 'OK';
        } catch (OtpException $error) {
            $code = $error->apiCode();
            $status = $reached && $code === 'CLINIC_OTP_COOLDOWN' ? 'cooldown' : 'error';
            $persian = preg_match('/[\x{0600}-\x{06FF}]/u', $error->getMessage()) === 1;
            $retry = $error->getData()['retry_after'] ?? 0;
            $retry_valid = is_int($retry) && $retry >= 1 && $retry <= 60;
        } finally {
            remove_filter('query', $filter);
            $elapsed_ms = $start !== null ? (hrtime(true) - $start) / 1000000 : 0.0;
        }
    } elseif (($mode === 'otp-verify' && $round === 'otp') || ($mode === 'otp-wrong' && $round === 'wrong')) {
        $prefix = 'SELECT * FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE id = ';
        $reached = false;
        $start = null;
        $filter = static function (string $query) use ($prefix, $await, $round, &$reached, &$start): string {
            if (str_starts_with($query, $prefix) && str_ends_with($query, ' FOR UPDATE') && !$reached) {
                $reached = true;
                // Every worker passed the initial read before any obtains the
                // real challenge row lock. SQL and results are never changed.
                $await($round);
                $start = hrtime(true);
            }
            return $query;
        };
        add_filter('query', $filter);
        try {
            $mobile = $round === 'wrong' ? (string) $state['wrong_mobile'] : (string) $state['mobile'];
            $candidate = $round === 'wrong' ? (string) $state['wrong_code'] : (string) $state['code'];
            $result = App::otpService()->verify($mobile, $candidate);
            $require($round === 'otp' && $reached && $result['session_issued'] && (int) $result['user_id'] > 0, 'otp-session');
            $status = 'success';
            $code = 'OK';
        } catch (OtpException $error) {
            $code = $error->apiCode();
            $persian = preg_match('/[\x{0600}-\x{06FF}]/u', $error->getMessage()) === 1;
            $retry = $error->getData()['retry_after'] ?? 0;
            $retry_valid = is_int($retry) && $retry >= 1 && $retry <= 900;
            if ($round === 'otp') {
                $status = $code === 'CLINIC_OTP_INVALID' && $reached ? 'consumed' : 'error';
            } else {
                $status = $reached && $code === 'CLINIC_OTP_INVALID' ? 'invalid' :
                    ($reached && $code === 'CLINIC_OTP_LOCKED' && $retry_valid ? 'locked' : 'error');
            }
        } finally {
            remove_filter('query', $filter);
            $elapsed_ms = $start !== null ? (hrtime(true) - $start) / 1000000 : 0;
        }
    } elseif ($mode === 'mixed' && $round === 'mixed' && $index < 3) {
        $mixed = $state['mixed'];
        $db = App::db();
        $prefix = 'SELECT id FROM ' . $db->table('cpms_audit_seq') . ' WHERE id = 1';
        $note_insert = 'INSERT INTO `' . $db->table('cpms_clinical_notes') . '`';
        $note_seen = false;
        $note_observed = false;
        $note_insert_id = 0;
        $note_error = '';
        $reached = false;
        $start = null;
        $filter = static function (string $query) use ($db, $prefix, $note_insert, $await, &$reached, &$start, &$note_seen, &$note_observed, &$note_insert_id, &$note_error): string {
            // Observe only the result of the real note INSERT; the hook neither
            // changes SQL nor publishes its potentially sensitive error text.
            if ($note_seen && !$note_observed) {
                $note_observed = true;
                $note_insert_id = (int) $db->wpdb()->insert_id;
                $note_error = (string) $db->wpdb()->last_error;
            }
            if (str_starts_with($query, $note_insert)) {
                $note_seen = true;
            }
            if (str_starts_with($query, $prefix) && str_ends_with($query, ' FOR UPDATE') && !$reached) {
                $reached = true;
                // The three real services have reached their mandatory audit
                // boundary. Release all before any guard lock is acquired.
                $await('mixed');
                $start = hrtime(true);
            }
            return $query;
        };
        add_filter('query', $filter);
        try {
            if ($index === 0) {
                wp_set_current_user((int) $mixed['doctor']);
                $note = App::clinicalService()->addNote((int) $mixed['doctor'], (int) $mixed['clinical_visit'], [
                    'category' => 'clinical_note', 'visibility' => 'patient_visible', 'content_text' => 'یادداشت آزمایشی',
                ]);
                if ((int) ($note['id'] ?? 0) <= 0) {
                    if (!$note_seen) {
                        throw new CpmsProofFailure('mixed-clinical-insert-not-observed');
                    }
                    if ($note_error !== '') {
                        if (stripos($note_error, 'foreign key constraint') !== false) {
                            throw new CpmsProofFailure('mixed-clinical-foreign-key');
                        }
                        if (stripos($note_error, 'deadlock') !== false || stripos($note_error, 'lock wait') !== false) {
                            throw new CpmsProofFailure('mixed-clinical-lock-error');
                        }
                        if (stripos($note_error, 'incorrect string value') !== false || stripos($note_error, 'invalid data') !== false) {
                            throw new CpmsProofFailure('mixed-clinical-encoding');
                        }
                        throw new CpmsProofFailure('mixed-clinical-other-sql-error');
                    }
                    if ($note_observed && $note_insert_id > 0) {
                        throw new CpmsProofFailure('mixed-clinical-id-lost');
                    }
                    throw new CpmsProofFailure('mixed-clinical-zero-id');
                }
                $status = 'clinical';
            } elseif ($index === 1) {
                App::replaceExplicitScope(ClinicScope::forClinic((int) $mixed['finance_clinic']));
                wp_set_current_user((int) $mixed['accountant']);
                $payment = App::financeService()->recordPayment((int) $mixed['accountant'], (int) $mixed['invoice'], [
                    'amount' => 5, 'method' => 'cash',
                ], 'cpms-mixed-proof-' . bin2hex(random_bytes(16)));
                $require((int) ($payment['payment_id'] ?? 0) > 0, 'mixed-finance');
                $status = 'finance';
            } else {
                wp_set_current_user((int) $mixed['manager']);
                $sms = App::smsService()->testSend('09128000001', 'پیامک آزمایشی', (int) $mixed['manager']);
                if (($sms['status'] ?? '') !== 'SENT') {
                    if (!App::licenseGate()->assert(\ClinicCore\Domain\Licensing\LicenseGate::OP_SMS_COMMERCIAL)->allowed) {
                        throw new CpmsProofFailure('mixed-sms-license-blocked');
                    }
                    throw new CpmsProofFailure('mixed-sms-send-failed');
                }
                $status = 'sms';
            }
            $require($reached, 'mixed-audit-boundary');
            $code = 'OK';
        } finally {
            remove_filter('query', $filter);
            $elapsed_ms = $start !== null ? (hrtime(true) - $start) / 1000000 : 0;
        }
    } elseif ($mode === 'booking' && in_array($round, ['10', '25', '50'], true)) {
        // Every worker goes through real request, test-provider delivery and
        // verify before the coordinator releases the capacity-one hold race.
        $mobile = '09126' . str_pad((string) ((int) $round * 100 + $index), 6, '0', STR_PAD_LEFT);
        $request = App::otpService()->request($mobile, OtpService::PURPOSE_LOGIN, null, null, $clinic);
        $require($request['sms_sent'] && is_string($provider->code) && preg_match('/^[0-9]{6}$/D', $provider->code) === 1, 'otp-delivery');
        $verified = App::otpService()->verify($mobile, $provider->code);
        $user = (int) $verified['user_id'];
        $require($verified['session_issued'] && $user > 0, 'otp-verification');
        wp_set_current_user($user);
        $slot = $state['slots'][$round];
        $await($round);
        $start = hrtime(true);
        try {
            $hold = App::bookingService()->hold($user, $clinician, (string) $slot['date'], (string) $slot['time'], (int) $slot['id'], null, $location);
            $key = 'cpms-proof-' . bin2hex(random_bytes(16));
            $first = App::bookingService()->confirm((string) $hold['hold_token'], $user, null, $key, 'آزمایشی', 'نمونه');
            $second = App::bookingService()->confirm((string) $hold['hold_token'], $user, null, $key, 'آزمایشی', 'نمونه');
            $first_id = (int) ($first['id'] ?? $first['appointment_id'] ?? 0);
            $second_id = (int) ($second['id'] ?? $second['appointment_id'] ?? 0);
            $replay = $first_id > 0 && $first_id === $second_id;
            $require($replay, 'booking-idempotency');
            $status = 'booked';
            $code = 'OK';
        } catch (BookingException $error) {
            $code = $error->errorCode;
            $status = $code === 'CLINIC_SLOT_TAKEN' ? 'slot_taken' : 'error';
        } finally {
            $elapsed_ms = (hrtime(true) - $start) / 1000000;
        }
    } else {
        throw new RuntimeException('worker-mode');
    }
    $write($dir . '/result-' . $round . '-' . $index . '.json', [
        'status' => $status,
        'code' => preg_match('/^[A-Z0-9_]{1,64}$/D', $code) === 1 ? $code : 'OTHER',
        'replay' => $replay,
        'persian' => $persian,
        'retry_valid' => $retry_valid,
        'elapsed_ms' => round($elapsed_ms, 3),
    ]);
} catch (Throwable $error) {
    // Only proof assertions supply these fixed, non-sensitive markers. Never
    // print arbitrary exception text, DB errors, tokens or request values.
    if ($error instanceof CpmsProofFailure && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $error->getMessage()) === 1) {
        echo 'PROOF_FAILURE ' . $error->getMessage() . "\n";
    }
    if ((in_array($round, ['otp', 'wrong', 'mixed', '10', '25', '50'], true) || preg_match('/^issue[0-4]$/D', $round) === 1) && $index >= 0 && $index < 50) {
        $category = $error instanceof CpmsProofFailure ? 'PROOF_' . strtoupper(str_replace('-', '_', $error->getMessage())) :
            ($error instanceof OtpException ? $error->apiCode() : ($error instanceof BookingException ? $error->errorCode : 'WORKER_FAILURE'));
        $category = preg_match('/^[A-Z0-9_]{1,64}$/D', $category) === 1 ? $category : 'WORKER_FAILURE';
        try {
            $write($dir . '/result-' . $round . '-' . $index . '.json', ['status' => 'error', 'code' => $category, 'replay' => false, 'elapsed_ms' => 0]);
        } catch (Throwable $ignored) {
        }
    }
    exit(1);
}

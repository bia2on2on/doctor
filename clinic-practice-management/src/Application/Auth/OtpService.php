<?php

declare(strict_types=1);

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Domain\Otp\OtpState;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Security\RateLimiter;
use ClinicCore\Settings\Settings;

/**
 * جریان Mobile+OTP (F2) — docs/security/auth-authorization.md.
 *
 * قوانین:
 *  - کد خام هرگز ذخیره/لاگ نمی‌شود (فقط SHA-256(code+pepper)).
 *  - TTL/Attempts/Cooldown/DailyMax/Lockout از Settings (OtpPolicy).
 *  - ارسال SMS: Sync با Timeout کوتاه؛ شکست → Job Retry (max 3) — بدون Block طولانی Request.
 *  - هر رویداد → Audit (بدون کد).
 */
final class OtpService
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_VERIFY_MOBILE = 'verify_mobile';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly Settings $settings,
        private readonly RateLimiter $rate,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        private readonly SmsService $sms,
        private readonly JobQueue $jobs
    ) {
    }

    /**
     * درخواست کد جدید (A2).
     *
     * @return array{expires_in: int, sms_sent: bool, retry_enqueued: bool}
     *
     * @throws OtpException
     */
    public function request(string $rawMobile, string $purpose = self::PURPOSE_LOGIN, ?int $userId = null, ?string $ip = null): array
    {
        $mobile = MobileValidator::normalize($rawMobile);
        if ($mobile === null) {
            throw new OtpException('CLINIC_MOBILE_INVALID', 'شماره موبایل معتبر نیست');
        }

        $dailyMax = (int) $this->settings->get('otp.daily_max');
        $hourlyMax = (int) $this->settings->get('otp.hourly_max');

        $byDay = $this->rate->hit('otp-day:' . $mobile, $dailyMax, 86400);
        if (!$byDay['allowed']) {
            $this->audit('OTP_DENIED_DAILY', $userId, $mobile, $ip);
            throw new OtpException('CLINIC_OTP_DAILY_LIMIT', 'محدودیت ارسال کد در روز به پایان رسیده است', $byDay);
        }
        $byHour = $this->rate->hit('otp-hour:' . $mobile, $hourlyMax, 3600);
        if (!$byHour['allowed']) {
            throw new OtpException('CLINIC_RATE_LIMITED', 'درخواست‌های شما موقتاً محدود شده است', $byHour);
        }
        if ($ip !== null && $ip !== '') {
            $byIp = $this->rate->hit('otp-ip:' . $ip, 10, 3600);
            if (!$byIp['allowed']) {
                throw new OtpException('CLINIC_RATE_LIMITED', 'درخواست‌های شما موقتاً محدود شده است', $byIp);
            }
        }

        $policy = $this->settings->otpPolicy();
        $state = $this->loadState($mobile, $purpose);
        $send = $policy->canSend($state, $this->now());
        if (!$send['ok']) {
            $code = match ($send['reason']) {
                OtpPolicy::REASON_LOCKED => 'CLINIC_OTP_LOCKED',
                OtpPolicy::REASON_DAILY_LIMIT => 'CLINIC_OTP_DAILY_LIMIT',
                default => 'CLINIC_OTP_COOLDOWN',
            };
            $this->audit('OTP_DENIED_' . strtoupper($send['reason']), $userId, $mobile, $ip);
            throw new OtpException($code, 'هنوز زود است — بعداً تلاش کنید');
        }

        // ساخت Token (فقط Hash)
        $code = OtpPolicy::generateCode(6);
        $ttl = (int) $this->settings->get('otp.ttl_sec');
        $expiresAt = $this->addSeconds($this->now(), $ttl);
        $this->db->insert('cpms_otp_tokens', [
            'mobile' => $mobile,
            'purpose' => $purpose,
            'code_hash' => OtpPolicy::hashCode($code, $this->pepper()),
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'created_at' => $this->db->nowUtcSql(),
        ]);
        $tokenId = $this->db->wpdb_last_insert_id();

        $this->audit('OTP_REQUEST', $userId, $mobile, $ip);

        // ارسال از طریق SmsService (ADR-0025): Template/Text + Record + Fast-path + Queue Fallback.
        // OTP Service مستقل از Provider است — کد فقط به‌عنوان متغیر قالب می‌رود (§25).
        $sent = false;
        $retryEnqueued = false;
        try {
            $res = $this->sms->sendEvent(
                SmsEvents::OTP,
                $mobile,
                ['otp_code' => $code],
                'otp_token',
                (int) $tokenId,
                inline: true,
                priority: 8
            );
            $sent = (string) $res['status'] === SmsMessageStatus::SENT;
            $retryEnqueued = in_array($res['status'], [SmsMessageStatus::QUEUED, SmsMessageStatus::RETRYING], true);
        } catch (\Throwable $e) {
            // خطای ساختاری (مثلاً Table Migration نشده) — Token معتبر می‌ماند؛ ارسال از Queue ادامه دارد
            $this->op->error('OTP_SMS_FAILED', ['token_id' => $tokenId, 'error' => $e->getMessage()]);
            $retryEnqueued = true;
        }
        $this->audit($sent ? 'OTP_SENT_OK' : 'OTP_SENT_FAIL', $userId, $mobile, $ip);

        return ['expires_in' => $ttl, 'sms_sent' => $sent, 'retry_enqueued' => $retryEnqueued];
    }

    /**
     * تأیید کد + Login (A3) — Session کاربر ساخته می‌شود.
     *
     * @return array{user_id: int, patient_links: list<array<string,mixed>>, is_new_user: bool}
     *
     * @throws OtpException
     */
    public function verify(string $rawMobile, string $code, string $purpose = self::PURPOSE_LOGIN, ?int $ip = null): array
    {
        $mobile = MobileValidator::normalize($rawMobile);
        if ($mobile === null || !preg_match('/^\d{6}$/', $code)) {
            throw new OtpException('CLINIC_OTP_INVALID', 'کد واردشده معتبر نیست');
        }
        if ($ip !== null && $ip !== '') {
            $rl = $this->rate->hit('otp-verify-ip:' . $ip, 20, 3600);
            if (!$rl['allowed']) {
                throw new OtpException('CLINIC_RATE_LIMITED', 'درخواست‌های شما موقتاً محدود شده است', $rl);
            }
        }

        $policy = $this->settings->otpPolicy();
        $now = $this->now();

        $row = $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_otp_tokens') .
            ' WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1',
            [$mobile, $purpose]
        );
        if ($row === null) {
            throw new OtpException('CLINIC_OTP_EXPIRED', 'کدی برای این شماره صادر نشده است — درخواست کد جدید بدهید');
        }
        if ($row['consumed_at'] !== null) {
            throw new OtpException('CLINIC_OTP_INVALID', 'این کد قبلاً استفاده شده است');
        }
        if ($this->toDateTime($row['expires_at']) <= $now) {
            throw new OtpException('CLINIC_OTP_EXPIRED', 'کد منقضی شده است — درخواست کد جدید بدهید');
        }

        $state = new OtpState(
            (int) $row['attempts'],
            $row['locked_until'] !== null ? $this->toDateTime($row['locked_until']) : null,
            $this->toDateTime($row['created_at']),
            (int) $row['attempts']
        );
        if ($state->isLocked($now)) {
            $this->audit('CLINIC_OTP_LOCKED', null, $mobile, $ip);
            throw new OtpException('CLINIC_OTP_LOCKED', 'تلاش‌های نادرست زیاد بود — 15 دقیقه صبر کنید');
        }

        if (!hash_equals(OtpPolicy::hashCode($code, $this->pepper()), (string) $row['code_hash'])) {
            $failed = $policy->registerFailedAttempt($state, $now);
            // update() (نه prepare): wpdb::prepare مقدار null را به رشته خالی
            // تبدیل می‌کند و `locked_until = ''` در MySQL خطا می‌دهد → UPDATE
            // کلاً رد می‌شد و شمارنده تلاش‌ها هرگز ذخیره نمی‌شد.
            $this->db->update(
                'cpms_otp_tokens',
                [
                    'attempts' => $failed->attempts,
                    'locked_until' => $failed->lockedUntil?->format('Y-m-d H:i:s.000'),
                ],
                ['id' => (int) $row['id']]
            );
            $this->audit('OTP_VERIFY_FAIL', null, $mobile, $ip, ['remaining' => $policy->remainingAttempts($failed)]);
            throw new OtpException(
                $failed->lockedUntil !== null ? 'CLINIC_OTP_LOCKED' : 'CLINIC_OTP_INVALID',
                $failed->lockedUntil !== null ? 'تلاش‌های نادرست زیاد بود' : 'کد اشتباه است',
                ['remaining' => $policy->remainingAttempts($failed)]
            );
        }

        // مصرف تک‌بار (اتومیک: اگر هم‌زمان مصرف شده باشد، 0 row)
        $consumed = $this->db->query(
            'UPDATE ' . $this->db->table('cpms_otp_tokens') .
            ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
            [$this->db->nowUtcSql(), (int) $row['id']]
        );
        if (!$consumed) {
            throw new OtpException('CLINIC_OTP_INVALID', 'این کد قبلاً استفاده شده است');
        }

        // ---- Resolution کاربر/بیمار ----
        $isNewUser = false;
        $userId = $this->resolveUser($mobile, $purpose, $isNewUser);

        // Session
        if (function_exists('wp_set_auth_cookie')) {
            wp_set_auth_cookie($userId, true);
        }

        $patientLinks = $this->patientLinks($userId);

        $this->audit('OTP_VERIFY_OK', $userId, $mobile, $ip);
        $this->audit('LOGIN_SUCCESS', $userId, $mobile, $ip);

        return [
            'user_id' => $userId,
            'patient_links' => $patientLinks,
            'is_new_user' => $isNewUser,
        ];
    }

    /**
     * پیدا کردن/ساختن کاربر + لینک به بیمار(ان) موجود با همین موبایل.
     */
    private function resolveUser(string $mobile, string $purpose, bool &$isNewUser): int
    {
        $patient = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table('cpms_patients') .
            ' WHERE clinic_id = 1 AND mobile = %s AND status = %s ORDER BY id DESC LIMIT 1',
            [$mobile, 'active']
        );

        $link = null;
        if ($patient !== null) {
            $link = $this->db->fetchRow(
                'SELECT wp_user_id FROM ' . $this->db->table('cpms_patient_user_links') .
                ' WHERE patient_id = %d ORDER BY is_primary DESC, id ASC LIMIT 1',
                [(int) $patient['id']]
            );
        }

        $userId = null;
        if ($link !== null) {
            $user = $this->db->fetchRow(
                'SELECT ID FROM ' . $this->db->wpdb()->prefix . 'users WHERE ID = %d',
                [(int) $link['wp_user_id']]
            );
            if ($user !== null) {
                $userId = (int) $link['wp_user_id'];
            }
        }

        if ($userId === null) {
            $userId = $this->createWpUser($mobile);
            $isNewUser = true;
            if ($patient !== null) {
                $this->db->insert('cpms_patient_user_links', [
                    'clinic_id' => 1,
                    'patient_id' => (int) $patient['id'],
                    'wp_user_id' => $userId,
                    'mobile_at_link' => $mobile,
                    'is_primary' => 1,
                    'linked_at' => $this->db->nowUtcSql(),
                ]);
            }
        }

        return $userId;
    }

    private function createWpUser(string $mobile): int
    {
        $base = 'pt_' . substr(hash('sha256', $mobile . (string) time()), 0, 10);
        $username = $base;
        for ($i = 0; $i < 5 && function_exists('username_exists') && username_exists($username); $i++) {
            $username = $base . '_' . $i;
        }
        $email = $mobile . '@otp.cpms.local';

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => wp_generate_password(24, false),
            'role' => 'cpms_patient',
        ]);
        if (is_wp_error($userId)) {
            throw new OtpException('CLINIC_OTP_INVALID', 'خطا در ایجاد حساب — دوباره تلاش کنید');
        }

        return (int) $userId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function patientLinks(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.mrn, p.first_name, p.last_name, l.is_primary
             FROM ' . $this->db->table('cpms_patient_user_links') . ' l
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = l.patient_id
             WHERE l.wp_user_id = %d AND p.status = %s
             ORDER BY l.is_primary DESC, l.id ASC',
            [$userId, 'active']
        );
    }

    private function loadState(string $mobile, string $purpose): OtpState
    {
        $row = $this->db->fetchRow(
            'SELECT attempts, locked_until, created_at FROM ' . $this->db->table('cpms_otp_tokens') .
            ' WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1',
            [$mobile, $purpose]
        );
        if ($row === null) {
            return new OtpState();
        }

        return new OtpState(
            (int) $row['attempts'],
            $row['locked_until'] !== null ? $this->toDateTime($row['locked_until']) : null,
            $this->toDateTime($row['created_at']),
            0
        );
    }

    private function audit(string $action, ?int $userId, string $mobile, ?string $ip): void
    {
        $this->audit->log(
            $action,
            $userId !== null ? ['wp_user_id' => $userId, 'role' => 'patient'] : ['wp_user_id' => null, 'role' => 'anonymous'],
            'otp',
            null,
            null,
            null,
            ['mobile' => MobileValidator::mask($mobile)]
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function addSeconds(\DateTimeImmutable $dt, int $sec): string
    {
        return $dt->modify('+' . $sec . ' seconds')->format('Y-m-d H:i:s.000');
    }

    private function toDateTime(string $utcSql): \DateTimeImmutable
    {
        $clean = preg_replace('/\.\d+$/', '', $utcSql) ?? $utcSql;

        return new \DateTimeImmutable($clean, new \DateTimeZone('UTC'));
    }

    private function pepper(): string
    {
        $pepper = defined('CPMS_PEPPER') ? CPMS_PEPPER : '';

        return $pepper !== '' ? $pepper : 'cpms-dev-pepper-change-me';
    }
}

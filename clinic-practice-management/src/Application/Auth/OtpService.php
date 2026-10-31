<?php

declare(strict_types=1);

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Domain\Otp\OtpState;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\UserCacheRollback;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Security\RateLimiter;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use Closure;

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

    /**
     * فهرست بستهٔ Purposeهای مجاز (Phase 1A — Context Binding).
     *
     * پیش از این `purpose` یک رشتهٔ آزاد بود: هر مقدار دلخواهی Token
     * می‌ساخت و چون State سیاست (Cooldown/Lockout) به ازای
     * (mobile, purpose) نگهداری می‌شود، مهاجم می‌توانست با تغییر
     * `purpose` وضعیت سیاست را تکه‌تکه کند و ردیف بی‌نهایت بسازد.
     *
     * @var list<string>
     */
    public const PURPOSES = [self::PURPOSE_LOGIN, self::PURPOSE_VERIFY_MOBILE];

    /**
     * فقط این Purposeها اجازه دارند Session بسازند.
     *
     * `verify_mobile` تأیید مالکیت شماره است، نه ورود؛ پیش از Phase 1
     * verify() بدون توجه به Purpose کوکی احراز هویت صادر می‌کرد.
     *
     * @var list<string>
     */
    private const SESSION_PURPOSES = [self::PURPOSE_LOGIN];

    /**
     * تنها Purposeهایی که اجازه دارند در نبود کاربر، حساب بسازند.
     *
     * OD-8 — گزینهٔ الف: `verify_mobile` عمداً در این فهرست **نیست**.
     */
    private const PROVISIONING_PURPOSES = [self::PURPOSE_LOGIN];

    private const PEPPER_OPTION = 'cpms_otp_pepper';

    /**
     * Phase 8 Slice 2 — پسوندِ ایمیلِ قطعیِ هویت OTP.
     *
     * تنها قالبِ ایمیلِ مسیرِ OTP: `{mobile}@otp.cpms.local` (قابل‌حدسِ
     * قطعی از موبایلِ نرمال‌شده). بازاستفادهٔ کاربرِ موجود با همین ایمیل
     * یعنی همان user_id بدون ساخت wp_users تکراری.
     */
    private const OTP_EMAIL_SUFFIX = '@otp.cpms.local';

    /**
     * @param Closure(): int $currentClinicResolver Clinicِ فعالِ عملیاتِ جاری.
     *        به‌صورت Closure تزریق می‌شود تا ساختِ این سرویس به هیچ Clinic/Scope
     *        محیطی گره نخورد: ثبتِ مسیرهای REST (`rest_api_init`) پیش از برقراری
     *        هر Scope‌ای انجام می‌شود و فراخوانیِ زودهنگامِ `App::settings()`
     *        در زمانِ ساخت، bootstrap را در نصبِ چند-Clinicه می‌انداخت.
     *        سیاستِ انتخابِ Clinic **تغییر نکرده** — همان منبع، فقط در زمانِ
     *        فراخوانی حل می‌شود؛ نبودِ زمینهٔ معتبر ⇒ همان Fail-Closed در زمانِ صدا
     *        زدن (OTP product policy دست‌نخورده).
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly SettingsFactory $settingsFactory,
        private readonly Closure $currentClinicResolver,
        private readonly RateLimiter $rate,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op,
        private readonly SmsService $sms,
        private readonly JobQueue $jobs
    ) {
    }

    /**
     * پیکربندیِ Clinicِ فعال — در زمانِ عملیات حل می‌شود (نه در زمانِ ساخت).
     *
     * کلیدِ کش در `SettingsFactory` برابر `clinicId` است، پس حل‌کردنِ مکرر در
     * یک عملیات هزینه‌ای ندارد.
     */
    private function currentSettings(): Settings
    {
        return $this->settingsFactory->forClinic((int) ($this->currentClinicResolver)());
    }

    /**
     * درخواست کد جدید (A2).
     *
     * Phase 8 Slice 2 — تصمیم مالک (Hold-Timing): پارامتر اختیاری `$clinicId`
     * «تنها» از دادهٔ persisted مشتق می‌شود (OtpController انتخابِ bookable را
     * روی clinician/slot/Clinic حل می‌کند؛ کلاینت هرگز منبع Clinic نیست).
     * وقتی ارائه شود، تمام سیاست OTP (Rate/Cooldown/TTL) و ارسال SMS و مُهرِ
     * Clinic روی Challenge از همین Clinic استفاده می‌کند؛ وقتی `null` باشد
     * (بدون انتخاب)، رفتار تثبیت‌شدهٔ resolver (Scope محیطی/تک-Clinic) عیناً
     * حفظ می‌شود و روی Challenge Clinic مُهر نمی‌شود (NULL تاریخی).
     *
     * @return array{expires_in: int, sms_sent: bool, retry_enqueued: bool, retry_after: int}
     *
     * @throws OtpException
     */
    public function request(string $rawMobile, string $purpose = self::PURPOSE_LOGIN, ?int $userId = null, ?string $ip = null, ?int $clinicId = null): array
    {
        $mobile = MobileValidator::normalize($rawMobile);
        if ($mobile === null) {
            throw new OtpException('CLINIC_MOBILE_INVALID', 'شماره موبایل معتبر نیست');
        }
        $purpose = $this->assertPurpose($purpose);

        // Phase 8 Slice 2 — Clinicِ عملیات: فقط از منبعِ صریح (دادهٔ persisted
        // ناشی از انتخاب) یا resolver تثبیت‌شده؛ هرگز از ورودی خام کلاینت.
        $settings = $clinicId !== null && $clinicId > 0
            ? $this->settingsFactory->forClinic($clinicId)
            : $this->currentSettings();

        $dailyMax = (int) $settings->get('otp.daily_max');
        $hourlyMax = (int) $settings->get('otp.hourly_max');

        $byDay = $this->rate->hit('otp-day:' . $mobile, $dailyMax, 86400);
        if (!$byDay['allowed']) {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR signature parameter, established file style.
            $this->audit( 'OTP_DENIED_DAILY', $userId, $mobile, $ip, [], $clinicId );
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

        $policy = $settings->otpPolicy();
        $state = $this->loadState($mobile, $purpose);
        $send = $policy->canSend($state, $this->now());
        if (!$send['ok']) {
            $now = $this->now();
            $reason = (string) $send['reason'];
            $auditCode = 'OTP_DENIED_' . strtoupper($reason);
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR signature parameter, established file style.
            $this->audit($auditCode, $userId, $mobile, $ip, [], $clinicId);
            if ($reason === OtpPolicy::REASON_LOCKED) {
                $retryAfter = $policy->remainingLockout($state, $now);
                throw new OtpException(
                    'CLINIC_OTP_LOCKED',
                    'تلاش‌های نادرست زیاد بود — 15 دقیقه صبر کنید',
                    ['retry_after' => $retryAfter > 0 ? $retryAfter : $policy->lockoutSeconds, 'remaining' => 0]
                );
            }
            if ($reason === OtpPolicy::REASON_DAILY_LIMIT) {
                // Daily limit via OtpState is a secondary guard — the primary daily gate is
                // the RateLimiter `otp-day` window above. Keep the message identical so
                // policy and rate-limiter daily denials are indistinguishable to the client.
                throw new OtpException(
                    'CLINIC_OTP_DAILY_LIMIT',
                    'محدودیت ارسال کد در روز به پایان رسیده است',
                    ['retry_after' => 86400 - (time() % 86400)]
                );
            }
            // Cooldown — include exact remaining seconds so the client can show a consistent TTL.
            $retryAfter = $policy->remainingCooldown($state, $now);
            throw new OtpException(
                'CLINIC_OTP_COOLDOWN',
                'هنوز زود است — بعداً تلاش کنید',
                ['retry_after' => $retryAfter > 0 ? $retryAfter : $policy->cooldown_seconds(), 'ttl' => (int) $settings->get('otp.ttl_sec')]
            );
        }

        // ساخت Token (فقط Hash). Phase 8 Slice 2: وقتی Clinic از دادهٔ persisted
        // مشتق شده، روی Challenge مُهر می‌شود (Migration 0021 — ستون NULL مجاز).
        // کلیدِ ستون فقط در حضور Clinic اضافه می‌شود تا ردیفِ تاریخی/بدون Clinic
        // دقیقاً NULL بماند (بدون Backfill — Migration 0021).
        $code = OtpPolicy::generateCode(6);
        $ttl = (int) $settings->get('otp.ttl_sec');
        $expiresAt = $this->addSeconds($this->now(), $ttl);
        $tokenRow = [
            'mobile' => $mobile,
            'purpose' => $purpose,
            'code_hash' => OtpPolicy::hashCode($code, $this->pepper()),
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'created_at' => $this->db->nowUtcSql(),
        ];
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR signature parameter, established file style.
        if ($clinicId !== null && $clinicId > 0) {
            $tokenRow['clinic_id'] = $clinicId;
        }
        // D6 audit atomicity: the challenge row and its mandatory OTP_REQUEST
        // audit share one boundary — a failed audit rolls the token back, so no
        // un-audited OTP challenge can exist. SMS dispatch is an external side
        // effect and stays after the commit; its delivery-result audit
        // (OTP_SENT_OK/FAIL) cannot be paired with the SMS itself and remains
        // post-commit by design (the challenge itself is already audited here).
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Existing legacy local naming preserved.
        $token_row = $tokenRow;
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Existing legacy parameter naming preserved.
        $user_id  = $userId;
        $token_id = $this->db->transactional(
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR local imported into the audit boundary.
            function () use ( $token_row, $user_id, $mobile, $ip, $clinicId ): int {
                $this->db->insert( 'cpms_otp_tokens', $token_row );
                $inserted_id = (int) $this->db->wpdb_last_insert_id();
                // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR local imported into the audit boundary.
                $this->audit( 'OTP_REQUEST', $user_id, $mobile, $ip, [], $clinicId );

                return $inserted_id;
            }
        );

        // ارسال از طریق SmsService (ADR-0025): Template/Text + Record + Fast-path + Queue Fallback.
        // OTP Service مستقل از Provider است — کد فقط به‌عنوان متغیر قالب می‌رود (§25).
        $sent = false;
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR local, established file style.
        $retry_enqueued = false;
        try {
            // Phase 8 Slice 2: Clinicِ ارسال = Clinicِ Challenge (از انتخابِ
            // persisted مشتق‌شده) یا resolver تثبیت‌شده در مسیر بدون انتخاب.
            $res = $this->sms->sendEvent(
                $settings->clinicId(),
                SmsEvents::OTP,
                $mobile,
                ['otp_code' => $code],
                'otp_token',
                (int) $token_id,
                inline: true,
                priority: 8
            );
            $sent = (string) $res['status'] === SmsMessageStatus::SENT;
            $retry_enqueued = in_array( $res['status'], [ SmsMessageStatus::QUEUED, SmsMessageStatus::RETRYING ], true );
        } catch (\Throwable $e) {
            // OTP dispatch is always inline and never enqueues a recoverable
            // retry (the plaintext code exists only in this request's memory;
            // SmsService classifies OTP provider failures FAILED, not RETRYING).
            // Reporting retry_enqueued=true here would therefore promise a retry
            // that can never happen — keep the honest value and let the
            // OTP_SENT_FAIL audit below record the structural failure; the
            // durable challenge token stays valid for a fresh user-initiated
            // request, which mints a new code through the normal path.
            $this->op->error( 'OTP_SMS_FAILED', [ 'token_id' => $token_id, 'error' => $e->getMessage() ] );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR local, established file style.
            $retry_enqueued = false;
        }
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR signature parameter, established file style.
        $this->audit( $sent ? 'OTP_SENT_OK' : 'OTP_SENT_FAIL', $userId, $mobile, $ip, [], $clinicId );

        return [
            'expires_in'     => $ttl,
            'sms_sent'       => $sent,
            'retry_enqueued' => $retry_enqueued,
            'retry_after'    => $policy->cooldown_seconds(),
        ];
    }

    /**
     * تأیید کد + Login (A3) — Session کاربر ساخته می‌شود.
     *
     * OD-8: برای Purposeهای غیرِ ورود (اکنون فقط `verify_mobile`) هیچ حسابی
     * ساخته نمی‌شود. اگر شماره به هیچ کاربری متصل نباشد، `user_id = 0`،
     * `is_new_user = false` و `session_issued = false` برمی‌گردد و تأیید
     * همچنان موفق است — نتیجهٔ آن «تأییدشدگی شماره» است، نه یک حساب.
     *
     * @return array{user_id: int, patient_links: list<array<string,mixed>>, is_new_user: bool, session_issued: bool}
     *
     * @throws OtpException
     */
    public function verify(string $rawMobile, string $code, string $purpose = self::PURPOSE_LOGIN, ?string $ip = null): array
    {
        $mobile = MobileValidator::normalize($rawMobile);
        if ($mobile === null || !preg_match('/^\d{6}$/', $code)) {
            throw new OtpException('CLINIC_OTP_INVALID', 'کد واردشده معتبر نیست');
        }
        $purpose = $this->assertPurpose($purpose);
        if ($ip !== null && $ip !== '') {
            $rl = $this->rate->hit('otp-verify-ip:' . $ip, 20, 3600);
            if (!$rl['allowed']) {
                throw new OtpException('CLINIC_RATE_LIMITED', 'درخواست‌های شما موقتاً محدود شده است', $rl);
            }
        }

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

        // Phase 8 Slice 2 — Clinicِ چالش تنها مرجع این verify است (نه بدنهٔ
        // کلاینت، نه Scope محیطی): سیاست OTP، جست‌وجوی Patient و Clinicِ لینک
        // از Clinicِ مُهرشده روی همین ردیف می‌آید (Migration 0021). ردیفِ
        // تاریخیِ NULL: resolver تثبیت‌شده (تک-Clinic / Scope صریح)؛ در نصبِ
        // چند-Clinicِ بدون Scope ⇒ fail-closed با پاکت CLINIC_SCOPE_REQUIRED —
        // هرگز استثنای فراری، هرگز 500. کلیدهای Cooldown/Lockout هویت‌سطح
        // می‌مانند (AD-15 — بدون بازطراحی).
        $rowClinicId = (isset($row['clinic_id']) && $row['clinic_id'] !== null) ? (int) $row['clinic_id'] : null;
        try {
            $settings = $rowClinicId !== null
                ? $this->settingsFactory->forClinic($rowClinicId)
                : $this->currentSettings();
        } catch (ScopeRequiredException) {
            throw new OtpException(
                'CLINIC_SCOPE_REQUIRED',
                'امکان تعیین مرکزِ این کد به‌صورت ضمنی وجود نیست — درخواست کد با انتخاب نوبت را تکرار کنید',
                ['reason' => 'historical_null_challenge_without_scope']
            );
        }
        $policy = $settings->otpPolicy();
        $clinicId = $rowClinicId ?? (int) $settings->clinicId();

        $state = new OtpState(
            (int) $row['attempts'],
            $row['locked_until'] !== null ? $this->toDateTime($row['locked_until']) : null,
            $this->toDateTime($row['created_at']),
            0
        );
        if ($state->isLocked($now)) {
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR local, established file style.
            $this->audit( 'CLINIC_OTP_LOCKED', null, $mobile, $ip, [], $clinicId );
            $retryAfter = $policy->remainingLockout($state, $now);
            throw new OtpException(
                'CLINIC_OTP_LOCKED',
                'تلاش‌های نادرست زیاد بود — 15 دقیقه صبر کنید',
                ['retry_after' => $retryAfter > 0 ? $retryAfter : $policy->lockoutSeconds, 'remaining' => 0]
            );
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
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PSR local, established file style.
            $this->audit( 'OTP_VERIFY_FAIL', null, $mobile, $ip, [ 'remaining' => $policy->remainingAttempts( $failed ) ], $clinicId );
            throw new OtpException(
                $failed->lockedUntil !== null ? 'CLINIC_OTP_LOCKED' : 'CLINIC_OTP_INVALID',
                $failed->lockedUntil !== null ? 'تلاش‌های نادرست زیاد بود' : 'کد اشتباه است',
                ['remaining' => $policy->remainingAttempts($failed)]
            );
        }

        // D6 audit atomicity: the single-use consumption, identity resolution
        // (wp_users/patient-link writes) and the mandatory OTP_VERIFY_OK /
        // LOGIN_SUCCESS audits share ONE boundary — a failed audit rolls the
        // consume and every provisioning write back, so a consumed token or a
        // provisioned patient account can never outlive its missing audit.
        // The WP auth cookie is a non-transactional side effect and is issued
        // only after the durable commit.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Existing legacy local naming preserved.
        $clinic_id = $clinicId;

        // Populated only when the boundary provisions a fresh WP user; used for
        // narrow cache invalidation if the boundary rolls back (see catch below).
        $provisioned = null;

        try {
            $verify_outcome = $this->db->transactional(
                function () use ( $row, $mobile, $purpose, $ip, $clinic_id, &$provisioned ): array {
                    // مصرف تک‌بار (اتومیک: اگر هم‌زمان مصرف شده باشد، 0 row)
                    $consumed = $this->db->query(
                        'UPDATE ' . $this->db->table( 'cpms_otp_tokens' ) .
                        ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
                        [ $this->db->nowUtcSql(), (int) $row['id'] ]
                    );
                    if ( ! $consumed ) {
                        throw new OtpException( 'CLINIC_OTP_INVALID', 'این کد قبلاً استفاده شده است' );
                    }

                    // ---- Resolution کاربر/بیمار ----
                    //
                    // OD-8 (تصمیم مالک — گزینهٔ الف): تأیید شماره نباید به‌عنوان اثر
                    // جانبی حساب کاربری بسازد. `verify_mobile` صرفاً «تأییدشدگی» را
                    // اثبات می‌کند؛ ساخت حساب باید Workflow صریح و جدای خودش را داشته
                    // باشد. فقط Purposeهای ورود اجازهٔ Provisioning دارند.
                    $is_new_user   = false;
                    $may_provision = in_array( $purpose, self::PROVISIONING_PURPOSES, true );
                    $user_id       = $may_provision
                        ? $this->resolveUser( $mobile, $purpose, $is_new_user, $clinic_id, $provisioned )
                        : $this->findExistingUser( $mobile, $clinic_id );

                    // Session — فقط برای Purposeهای ورود (Context Binding).
                    // یک کد `verify_mobile` نباید به Login تبدیل شود.
                    $session_issued = in_array( $purpose, self::SESSION_PURPOSES, true ) && $user_id > 0;
                    $patient_links  = $user_id > 0 ? $this->patientLinks( $user_id ) : [];

                    $this->audit( 'OTP_VERIFY_OK', $user_id, $mobile, $ip, [ 'purpose' => $purpose ], $clinic_id );
                    if ( $session_issued ) {
                        $this->audit( 'LOGIN_SUCCESS', $user_id, $mobile, $ip, [], $clinic_id );
                    }

                    return [
                        'user_id'        => $user_id,
                        'patient_links'  => $patient_links,
                        'is_new_user'    => $is_new_user,
                        'session_issued' => $session_issued,
                    ];
                }
            );
        } catch ( \Throwable $e ) {
            // CpmsDb has already rolled the boundary back when this catch runs.
            // The InnoDB identity row is gone but WordPress' user caches (which
            // survive the request on Redis/Memcached backends) still hold the
            // ghost identity; evict exactly those keys, never a global flush.
            UserCacheRollback::invalidate( $provisioned !== null ? [ $provisioned ] : [] );
            throw $e;
        }

        // کوکی احراز هویت وردپرس پس از Commitِ پایدار صادر می‌شود (اثر جانبی
        // غیرتراکنشی) — هر شکست در مرز بالا پیش از این نقطه کامل roll back شده.
        if ( $verify_outcome['session_issued'] && function_exists( 'wp_set_auth_cookie' ) ) {
            wp_set_auth_cookie( (int) $verify_outcome['user_id'], true );
        }

        return [
            'user_id'        => (int) $verify_outcome['user_id'],
            'patient_links'  => $verify_outcome['patient_links'],
            'is_new_user'    => (bool) $verify_outcome['is_new_user'],
            'session_issued' => (bool) $verify_outcome['session_issued'],
        ];
    }

    /**
     * جست‌وجوی فقط-خواندنی کاربر متصل به یک شماره — **بدون هیچ اثر جانبی**.
     *
     * OD-8: مسیر `verify_mobile` از این تابع استفاده می‌کند. اگر کاربری وجود
     * نداشته باشد `0` برمی‌گردد و هیچ حسابی ساخته نمی‌شود و هیچ لینک
     * بیمار⇄کاربری درج نمی‌شود.
     */
    private function findExistingUser(string $mobile, int $clinicId): int
    {
        $patientId = $this->findActivePatientIdByMobile($mobile, $clinicId);
        if ($patientId === null) {
            return 0;
        }

        $link = $this->db->fetchRow(
            'SELECT wp_user_id FROM ' . $this->db->table('cpms_patient_user_links') .
            ' WHERE patient_id = %d ORDER BY is_primary DESC, id ASC LIMIT 1',
            [$patientId]
        );
        if ($link === null) {
            return 0;
        }

        $user = $this->db->fetchRow(
            'SELECT ID FROM ' . $this->db->wpdb()->prefix . 'users WHERE ID = %d',
            [(int) $link['wp_user_id']]
        );

        return $user === null ? 0 : (int) $link['wp_user_id'];
    }

    /**
     * بیمارِ فعالِ دارای این موبایل در Clinicِ داده‌شده —
     * **نقطهٔ واحد** این جست‌وجو.
     *
     * AD-13 (تصحیح Pre-Phase-2 Gate): تا این اصلاح، دو نسخهٔ کپی‌شده از
     * همین کوئری با literal `clinic_id = 1` وجود داشت (یکی از پیش از
     * Phase 1A و یکی افزودهٔ OD-8 در کامیت 4c16009 — رانش AD-13). هر دو
     * به این متدِ پارامتری‌شده تبدیل شدند؛ هیچ
     * مفهوم Scope جدیدی ساخته نشد (Organization/ClinicContext = Phase 2).
     *
     * Phase 8 Slice 2: Clinic همیشه از فراخواننده می‌آید — Clinicِ مُهرشده
     * روی Challenge (Migration 0021) یا resolver تثبیت‌شده برای ردیفِ
     * تاریخیِ NULL؛ هرگز از بدنهٔ کلاینت. «بیمارِ همان موبایل در Clinicِ
     * دیگر» = «نبودِ بیمار» (هیچ پیوندی بین-Clinic ساخته نمی‌شود).
     */
    private function findActivePatientIdByMobile(string $mobile, int $clinicId): ?int
    {
        $patient = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table('cpms_patients') .
            ' WHERE clinic_id = %d AND mobile = %s AND status = %s ORDER BY id DESC LIMIT 1',
            [$clinicId, $mobile, 'active']
        );

        return $patient === null ? null : (int) $patient['id'];
    }

    /**
     * پیدا کردن/ساختن کاربر + لینک به بیمار(ان) موجود با همین موبایل.
     *
     * Phase 8 Slice 2:
     *  - Clinicِ جست‌وجوی Patient و Clinicِ لینک، همانی است که verify از
     *    Challenge/Resolver گرفته (هرگز بدنهٔ کلاینت).
     *  - پیش از هر ساختِ کاربر، هویتِ قطعیِ OTP یعنی
     *    `{mobile}@otp.cpms.local` جست‌وجو و در صورت وجود **بازاستفاده**
     *    می‌شود: همان user_id، `is_new_user=false`، بدون ردیف تکراری
     *    wp_users؛ لینکِ Patient فقط اگر Patient در Clinicِ همین verify
     *    وجود داشته باشد (Patientِ Clinicِ دیگر هرگز لینک نمی‌شود).
     */
    // phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- established PSR-style in this file: camelCase signatures/locals and compact call style; security, SQL, i18n and escaping sniffs stay active.
    private function resolveUser(string $mobile, string $purpose, bool &$isNewUser, int $clinicId, ?array &$provisioned = null): int
    {
        $patientId = $this->findActivePatientIdByMobile($mobile, $clinicId);

        $link = null;
        if ($patientId !== null) {
            $link = $this->db->fetchRow(
                'SELECT wp_user_id FROM ' . $this->db->table('cpms_patient_user_links') .
                ' WHERE patient_id = %d ORDER BY is_primary DESC, id ASC LIMIT 1',
                [$patientId]
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
            // بازاستفادهٔ هویت قطعی OTP — هرگز wp_insert_user روی ایمیل تکراری.
            $userId = $this->findExistingOtpUserId($mobile);
        }

        if ($userId !== null) {
            // OTP authenticates a Patient identity only. A legacy Patient link
            // must never turn possession of that mobile into a Doctor/Staff or
            // Administrator session; keep the public failure generic.
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
            $this->assert_patient_otp_user( (int) $userId );

            // هویت موجود: هیچ ردیف کاربر جدیدی ساخته نمی‌شود؛ لینکِ Clinic-scoped
            // فقط وقتی Patient در Clinicِ همین verify موجود است.
            $isNewUser = false;
            if ($patientId !== null) {
                $this->linkPatientToUser($patientId, $userId, $mobile, $clinicId);
            }

            return $userId;
        }

        $account = $this->createWpUser($mobile);
        $userId = (int) $account['id'];
        // Captured for narrow post-rollback cache invalidation: the InnoDB row
        // is undone by ROLLBACK TO SAVEPOINT, but WordPress' user caches are not.
        $provisioned = [
            'id'    => $userId,
            'login' => (string) $account['login'],
            'email' => (string) $account['email'],
        ];
        $isNewUser = true;
        if ($patientId !== null) {
            $this->linkPatientToUser($patientId, $userId, $mobile, $clinicId);
        }

        return $userId;
    }
    // phpcs:enable

    /**
     * جست‌وجوی هویت قطعیِ OTP برای یک موبایل نرمال‌شده.
     *
     * `{mobile}@otp.cpms.local` تنها قالبِ ایمیلِ مسیر OTP است؛ بازگشتِ
     * شناسهٔ کاربرِ موجود یعنی بازاستفادهٔ همان هویت — **فقط** اگر حساب
     * واقعاً هویتِ بیمارِ CPMS باشد (نقشِ مستقر `cpms_patient` — همان
     * قراردادِ PatientPortalPage::isPatientOnly). ایمیلِ پل قابل‌حدس است؛
     * اگر حسابِ غیربیمار (کارمند/غیره) آن را پیش‌گرفته باشد، بازاستفادهٔ کور
     * احراز را به هویتِ اشتباه متصل می‌کرد. چنین تصادمی fail-closed است:
     * بدون session، بدون افشای patient_links، بدون ساختن کاربر، بدون تغییر
     * نقشِ حسابِ مزاحم و بدون افشای اینکه چه حسابی مالک آن ایمیل است (پاکتِ
     * محصولیِ عمومیِ تثبیت‌شده).
     */
    private function findExistingOtpUserId(string $mobile): ?int
    {
        $user = $this->db->fetchRow(
            'SELECT ID FROM ' . $this->db->wpdb()->prefix . 'users WHERE user_email = %s ORDER BY ID ASC LIMIT 1',
            [$mobile . self::OTP_EMAIL_SUFFIX]
        );
        if ($user === null) {
            return null;
        }

        $userId = (int) $user['ID'];
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
        $this->assert_patient_otp_user( (int) $userId );

        return $userId;
    }

    /**
     * Only Patient accounts may receive OTP login sessions. A legacy Patient
     * link or deterministic OTP identity carrying a staff/admin role fails
     * closed with the same public envelope as an invalid code.
     */
    private function assert_patient_otp_user( int $user_id ): void {
        $wp_user     = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
        $roles       = $wp_user instanceof \WP_User ? (array) $wp_user->roles : [];
        $staff_roles = [
            RolesAndCapabilities::ROLE_DOCTOR,
            RolesAndCapabilities::ROLE_SECRETARY,
            RolesAndCapabilities::ROLE_MANAGER,
            RolesAndCapabilities::ROLE_ACCOUNTANT,
            'administrator',
        ];
        if (
            ! in_array( RolesAndCapabilities::ROLE_PATIENT, $roles, true )
            || array_intersect( $staff_roles, $roles ) !== []
        ) {
            throw new OtpException( 'CLINIC_OTP_INVALID', 'کد واردشده معتبر نیست' );
        }
    }

    /**
     * لینکِ Clinic-scoped Patient⇄User (نقطهٔ واحد درج — جلوگیری از تکرار).
     */
    private function linkPatientToUser(int $patientId, int $userId, string $mobile, int $clinicId): void
    {
        $exists = $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_patient_user_links') .
            ' WHERE patient_id = %d AND wp_user_id = %d',
            [$patientId, $userId]
        );
        if ($exists !== null && (int) $exists > 0) {
            return;
        }

        $this->db->insert('cpms_patient_user_links', [
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'wp_user_id' => $userId,
            'mobile_at_link' => $mobile,
            'is_primary' => 1,
            'linked_at' => $this->db->nowUtcSql(),
        ]);
    }

    /**
     * @return array{id: int, login: string, email: string}
     */
    // phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose -- established PSR-style in this file: camelCase signatures/locals; security, SQL, i18n and escaping sniffs stay active.
    private function createWpUser(string $mobile): array
    {
        $base = 'pt_' . substr(hash('sha256', $mobile . (string) time()), 0, 10);
        $username = $base;
        for ($i = 0; $i < 5 && function_exists('username_exists') && username_exists($username); $i++) {
            $username = $base . '_' . $i;
        }
        $email = $mobile . self::OTP_EMAIL_SUFFIX;

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => wp_generate_password(24, false),
            'role' => 'cpms_patient',
        ]);
        if (is_wp_error($userId)) {
            throw new OtpException('CLINIC_OTP_INVALID', 'خطا در ایجاد حساب — دوباره تلاش کنید');
        }

        return [ 'id' => (int) $userId, 'login' => $username, 'email' => $email ];
    }
    // phpcs:enable

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

    // phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- established PSR-style in this file: camelCase signature and compact call style; security, SQL, i18n and escaping sniffs stay active.
    private function audit(string $action, ?int $userId, string $mobile, ?string $ip, array $meta = [], ?int $clinicId = null): void
    {
        // Explicit trusted Clinic attribution: OTP runs on public/patient paths
        // where an ambient request scope must never be trusted to supply the
        // Clinic. A null value means the challenge genuinely has no Clinic
        // (historical/no-selection path) and is persisted as NULL, never guessed.
        $this->audit->log(
            $action,
            $userId !== null ? ['wp_user_id' => $userId, 'role' => 'patient'] : ['wp_user_id' => null, 'role' => 'anonymous'],
            'otp',
            null,
            null,
            null,
            ['mobile' => MobileValidator::mask($mobile)] + $meta,
            [],
            $clinicId,
            true
        );
    }
    // phpcs:enable

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

    /**
     * Purpose باید عضو فهرست بسته باشد (Phase 1A).
     *
     * @throws OtpException
     */
    private function assertPurpose(string $purpose): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new OtpException('CLINIC_OTP_PURPOSE_INVALID', 'نوع درخواست کد معتبر نیست');
        }

        return $purpose;
    }

    /**
     * Pepper مخصوص هش OTP.
     *
     * ترتیب: ثابت `CPMS_PEPPER` → Secret تصادفیِ ماندگار در Option.
     *
     * تا پیش از Phase 1 اگر ثابت تعریف نمی‌شد، مقدار ثابتِ درونِ کد
     * (`cpms-dev-pepper-change-me`) استفاده می‌شد؛ چون کد OTP فقط 6 رقم
     * است، هرکس یک نسخهٔ Dump از جدول را می‌دید می‌توانست تمام کدها را
     * آفلاین بازیابی کند. حالا در نبود ثابت، یک Secret تصادفی 256 بیتی
     * ساخته و ذخیره می‌شود (autoload = no).
     *
     * ⚠️ دامنه: این تغییر فقط OTP است. `AuditLogger::pepper()` هنوز همان
     * مقدار پیش‌فرضِ درونِ کد را دارد و عمداً در Phase 1A دست نخورده، چون
     * تعویض Pepper زنجیرهٔ Hash رکوردهای Audit موجود را روی نصب‌های فعلی
     * نامعتبر می‌کند — این یک تصمیم پیامدساز است، نه اصلاح ساده
     * (OPEN DECISION در گزارش Phase 1A). برخلاف Audit، Tokenهای OTP عمر
     * چنددقیقه‌ای دارند و تعویض Pepper بی‌خطر است.
     */
    private function pepper(): string
    {
        $pepper = defined('CPMS_PEPPER') ? (string) CPMS_PEPPER : '';
        if ($pepper !== '') {
            return $pepper;
        }

        if (!function_exists('get_option') || !function_exists('add_option')) {
            // خارج از WordPress (Unit) — Secret درونِ فرایند، هرگز ماندگار نمی‌شود.
            static $ephemeral = null;
            $ephemeral ??= bin2hex(random_bytes(32));

            return $ephemeral;
        }

        $stored = (string) get_option(self::PEPPER_OPTION, '');
        if ($stored === '') {
            $stored = bin2hex(random_bytes(32));
            // add_option با autoload=no — اگر همزمان ساخته شده باشد، مقدار موجود برنده است.
            if (!add_option(self::PEPPER_OPTION, $stored, '', 'no')) {
                $stored = (string) get_option(self::PEPPER_OPTION, $stored);
            }
        }

        return $stored;
    }
}

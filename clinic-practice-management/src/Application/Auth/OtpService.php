<?php

declare(strict_types=1);

// phpcs:disable WordPress.WhiteSpace.ControlStructureSpacing,PEAR.Functions.FunctionCallSignature,Generic.WhiteSpace.ArbitraryParenthesesSpacing,Generic.Formatting.MultipleStatementAlignment,WordPress.NamingConventions.ValidVariableName -- legacy PSR compact style in this file; security, SQL, and runtime correctness sniffs remain active via per-line ignores where needed.

namespace ClinicCore\Application\Auth;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
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
     * C11 — پنجره‌های سهمیهٔ صادرشده (روزانه/ساعتی). همان مقادیری که پیش‌تر
     * به‌صورت Literal در request() بود؛ ثابت شده تا «بررسی» و «مصرف» نتوانند
     * با دو پنجرهٔ متفاوت محاسبه شوند.
     */
    private const DAY_WINDOW_SEC = 86400;
    private const HOUR_WINDOW_SEC = 3600;

    private const PEPPER_OPTION = 'cpms_otp_pepper';
    // The lock duration is configurable; clients receive the exact remaining
    // seconds as retry_after rather than a misleading hard-coded duration.
    private const LOCKED_MESSAGE = 'تلاش‌های نادرست زیاد بود — پس از پایان زمان قفل دوباره تلاش کنید';

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
        private readonly JobQueue $jobs,
        private readonly ?PatientSessionScope $session_scope = null
    ) {
    }

    private function session_scope(): PatientSessionScope {
        return $this->session_scope ?? App::patient_session_scope();
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
     * نرمال‌سازیِ سقفِ درخواستِ OTP از یک IP در هر ساعت (کلیدِ otp.ip_hourly_max).
     *
     * مرور ۲۰۲۶-۱۱-۰۵ — نگاشت به سمتِ پیش‌فرضِ کران‌دار (توصیفِ دقیق: parity
     * با پیش‌فرضِ مستند + ضدِlockout؛ نه fail-closed — سد با پیش‌فرضِ ۱۰ همچنان
     * نرم است و گارد را کاملاً نمی‌بندد): مقدارِ خامِ غیرقابل‌استفاده (null،
     * رشتهٔ خالی، بولی، رشتهٔ غیرعددی مثل 'abc') به پیش‌فرضِ ۱۰ نگاشته می‌شود —
     * همان رفتارِ literal تاریخی؛ پیش‌تر (int)۰→۱ یک پیکربندیِ معیوب را بی‌صدا
     * به سدِ ۱درخواست‌در‌ساعت تبدیل می‌کرد (lockoutِ پنهانِ دسترس‌پذیری).
     * مقدارِ عددیِ خارج از [1, 1000] همچنان clamp می‌شود: هرگز ۰ (سدِ خاموش)
     * یا بی‌کران نمی‌شود.
     */
    public static function clamp_ip_hourly_max( mixed $raw ): int {
        if ( ! is_numeric( $raw ) ) {
            return 10;
        }
        return max( 1, min( 1000, (int) $raw ) );
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

        // Rate-limit inputs (daily/hourly/IP ceilings and the OTP policy
        // below) must all come from one verified settings snapshot: if
        // this Clinic's settings cannot be read, the request fails closed
        // here instead of combining unverified defaults with stored
        // values loaded a moment later, which could weaken the limits.
        $settings->require_verified_snapshot();

        $daily_max = (int) $settings->get('otp.daily_max');
        $hourly_max = (int) $settings->get('otp.hourly_max');

        // C11 — per-phone day/hour are *issuance* quotas, not attempt quotas:
        // they are charged only when a challenge is actually created, so a
        // request rejected by cooldown or lockout consumes neither. They are no
        // longer hit here: the decision and the charge both live inside the
        // per-mobile issuance guard below (see issuance_quota_denial() and the
        // charge after every policy gate passes).
        //
        // The per-IP counter stays exactly where it was and keeps its meaning:
        // it limits *attempts* from one address per hour, including attempts
        // that end up rejected by cooldown/lockout. That behaviour is
        // deliberately unchanged.
        if ($ip !== null && $ip !== '') {
            // سقفِ per-IP از Settingsِ همان Clinic خوانده می‌شود (پیش‌فرض ۱۰ =
            // رفتارِ قبلیِ literal)؛ نامِ سطل و پنجرهٔ یک‌ساعته دست‌نخورده‌اند.
            // ⚠️ سطل 'otp-ip:'.IP کلینیک‌آگاه نیست: شمارندهٔ Hits بین همهٔ
            // Clinicها روی یک IP **مشترک** است و فقط آستانه از Clinicِ متناظر
            // می‌آید؛ تداخلِ متقاطع روی NAT مشترک خطرِ نهفتهٔ ثبت‌شده است
            // (docs/decisions/2026-11-05-otp-ip-bucket-shared-counter-record.md)
            // و کلیدبندیِ سطل به تصمیمِ بازِ مالک Q6 موکول مانده — Scopeی‌کردنِ
            // کلید پیش از آن تصمیم، سوگندِ Tenant را پیش‌داوری می‌کند.
            // هیچ Writerِ محصولی برای otp.ip_hourly_max وجود ندارد؛ فقطِ Seed/
            // تست می‌نویسند تا وقتی که Q6/سطحِ ادمین تصمیم گیرد.
            $ip_hourly_max = self::clamp_ip_hourly_max( $settings->get( 'otp.ip_hourly_max' ) );
            $byIp = $this->rate->hit('otp-ip:' . $ip, $ip_hourly_max, 3600);
            if (!$byIp['allowed']) {
                throw new OtpException('CLINIC_RATE_LIMITED', 'درخواست‌های شما موقتاً محدود شده است', $byIp);
            }
        }

        $policy    = $settings->otpPolicy();
        $user_id   = $userId;
        $clinic_id = $clinicId;
        // The guard is a separate, hashed per-mobile row in the existing rate
        // table. Its zero timestamp makes it eligible for ordinary rate-limit
        // cleanup. An UPSERT takes an exclusive InnoDB row lock until the
        // outermost transaction commits, including when cleanup races it.
        // It serializes all purposes and Clinics, matching the existing global
        // per-mobile limits; it never stores a raw mobile or a code.
        $guard_key = 'otp-send-guard:' . hash( 'sha256', $mobile );
        $issue = $this->db->transactional(
            function () use ( $guard_key, $mobile, $purpose, $policy, $settings, $user_id, $ip, $clinic_id, $daily_max, $hourly_max ): array {
                $locked = $this->db->query(
                    'INSERT INTO ' . $this->db->table( 'cpms_rate_limits' ) .
                    ' (window_key, window_id, window_sec, hits) VALUES (%s, 0, 1, 0)' .
                    ' ON DUPLICATE KEY UPDATE hits = hits',
                    [ $guard_key ]
                );
                if ( ! $locked || $this->db->wpdb()->last_error !== '' ) {
                    throw new \RuntimeException( 'OTP issuance guard failed' );
                }

                // C11 — quota gate. Checked here so the denial is serialized
                // with issuance too, and it only reads (peek, never hit): a
                // request that ends up rejected must not consume the day/hour
                // quota. Error precedence is preserved: the day/hour caps are
                // still reported before cooldown/lockout, as before.
                $quota = $this->issuance_quota_denial( $mobile, $daily_max, $hourly_max );
                if ( $quota !== null ) {
                    if ( $quota->apiCode() === 'CLINIC_OTP_DAILY_LIMIT' ) {
                        $this->audit( 'OTP_DENIED_DAILY', $user_id, $mobile, $ip, [], $clinic_id );
                    }
                    // Committed like every other denial audit: throw only
                    // after the transaction returns.
                    return [ 'error' => $quota ];
                }

                // Read again only after the guard: an earlier unlocked read can
                // let several simultaneous requests all pass cooldown. Lock an
                // existing token by primary key so concurrent failed attempts
                // cannot be bypassed by issuing during lockout.
                $state = $this->loadState( $mobile, $purpose );
                $now = $this->now();
                $send = $policy->canSend( $state, $now );
                if ( ! $send['ok'] ) {
                    $reason = (string) $send['reason'];
                    $this->audit( 'OTP_DENIED_' . strtoupper( $reason ), $user_id, $mobile, $ip, [], $clinic_id );
                    if ( $reason === OtpPolicy::REASON_LOCKED ) {
                        $retry_after = $policy->remaining_lockout( $state, $now );
                        $error = new OtpException(
                            'CLINIC_OTP_LOCKED',
                            self::LOCKED_MESSAGE,
                            [ 'retry_after' => $retry_after > 0 ? $retry_after : $policy->lockout_seconds(), 'remaining' => 0 ]
                        );
                    } elseif ( $reason === OtpPolicy::REASON_DAILY_LIMIT ) {
                        $error = new OtpException(
                            'CLINIC_OTP_DAILY_LIMIT',
                            'محدودیت ارسال کد در روز به پایان رسیده است',
                            [ 'retry_after' => 86400 - ( time() % 86400 ) ]
                        );
                    } else {
                        $retry_after = $policy->remaining_cooldown( $state, $now );
                        $error = new OtpException(
                            'CLINIC_OTP_COOLDOWN',
                            'هنوز زود است — بعداً تلاش کنید',
                            [ 'retry_after' => $retry_after > 0 ? $retry_after : $policy->cooldown_seconds(), 'ttl' => (int) $settings->get( 'otp.ttl_sec' ) ]
                        );
                    }
                    // The denial audit must commit, so throw only after this
                    // transaction returns, as in the verification boundary.
                    return [ 'error' => $error ];
                }

                // C11 — every gate passed, so a challenge is certain and only
                // now are the per-phone day/hour quotas charged. The charge and
                // the insert below share the transaction and the per-mobile
                // guard row lock taken above, which makes check-and-consume
                // atomic with the issue decision: with N concurrent issuing
                // requests, at most maxPerWindow challenges are issued per
                // window (and a rollback refunds the charge). peek() proved
                // both counters were below their caps a moment ago; the only
                // way hit() can disagree is a window rollover in between, and
                // then refusing is the fail-closed answer.
                $day_charge = $this->rate->hit( 'otp-day:' . $mobile, $daily_max, self::DAY_WINDOW_SEC );
                $hour_charge = $this->rate->hit( 'otp-hour:' . $mobile, $hourly_max, self::HOUR_WINDOW_SEC );
                if ( ! $day_charge['allowed'] || ! $hour_charge['allowed'] ) {
                    throw new \RuntimeException( 'OTP issuance quota invariant violated' );
                }

                // Only hashes are durable; a trusted explicit Clinic remains
                // attached to the challenge as before. The plaintext code is
                // kept solely for the post-commit in-memory SMS dispatch.
                $code = OtpPolicy::generateCode( 6 );
                $ttl = (int) $settings->get( 'otp.ttl_sec' );
                $token_row = [
                    'mobile'     => $mobile,
                    'purpose'    => $purpose,
                    'code_hash'  => OtpPolicy::hashCode( $code, $this->pepper() ),
                    'expires_at' => $this->addSeconds( $now, $ttl ),
                    'attempts'   => 0,
                    'created_at' => $this->db->nowUtcSql(),
                ];
                if ( $clinic_id !== null && $clinic_id > 0 ) {
                    $token_row['clinic_id'] = $clinic_id;
                }
                $inserted = $this->db->insert( 'cpms_otp_tokens', $token_row );
                $token_id = (int) $this->db->wpdb_last_insert_id();
                if ( ! $inserted || $token_id <= 0 ) {
                    throw new \RuntimeException( 'OTP challenge insert failed' );
                }
                $this->audit( 'OTP_REQUEST', $user_id, $mobile, $ip, [], $clinic_id );
                return [ 'token_id' => $token_id, 'code' => $code, 'ttl' => $ttl ];
            }
        );
        if ( isset( $issue['error'] ) ) {
            throw $issue['error'];
        }
        $token_id = $issue['token_id'];
        $code     = $issue['code'];
        $ttl      = $issue['ttl'];

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
        // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- compact audit helper.
        $this->audit( $sent ? 'OTP_SENT_OK' : 'OTP_SENT_FAIL', $userId, $mobile, $ip, [], $clinicId );

        return [
            'expires_in'     => $ttl,
            'sms_sent'       => $sent,
            'retry_enqueued' => $retry_enqueued,
            'retry_after'    => $policy->cooldown_seconds(),
        ];
    }

    /**
     * تأیید کد (A3) — فقط اثباتِ «مالکیتِ شماره» است؛ هیچ Login/Sessionای
     * انجام نمی‌شود.
     *
     * تصمیم مالک ۲۰۲۶-۱۱-۰۹ (Option A — OTP possession only):
     *  - هیچ Patientی با موبایل جست‌وجو نمی‌شود؛
     *  - هیچ حسابِ mobile-derived ساخته/بازاستفاده نمی‌شود (ایمیلِ قطعی
     *    `{mobile}@otp.cpms.local` دیگر هیچ مقامی ندارد)؛
     *  - هیچ لینکِ Patient⇄User درج یا خوانده نمی‌شود (no auto-link)؛
     *  - دسترسی به پروندهٔ Patient فقط از مسیر حسابِ WPِ صریحاً لینک‌شده
     *    توسط clinic staff می‌گذرد (Slice 2). تا آن زمان حتی یک لینکِ
     *    ازپیش‌موجود هم از طریق OTP هیچ دسترسی نمی‌دهد؛
     *  - پاسخ برای شماره‌های موجود و ناموجود یکسان و non-enumerating است.
     * OD-8 هم برقرار است: Purpose `verify_mobile` نیز همین مسیر را می‌گیرد.
     *
     * @return array{user_id: int, patient_links: list<array<string,mixed>>, is_new_user: bool, session_issued: bool}
     *    همیشه `user_id=0، patient_links=[]، is_new_user=false، session_issued=false` —
     *    برای Purpose ورود هم Sessionی صادر نمی‌شود (تصمیم مالک).
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
        // کلاینت، نه Scope محیطی): سیاست OTP از Clinicِ مُهرشده روی همین ردیف
        // می‌آید (Migration 0021)؛ هیچ جست‌وجوی Patientی انجام نمی‌شود (تصمیم
        // مالک ۲۰۲۶-۱۱-۰۹ — Option A). ردیفِ
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

        // D6 audit atomicity: the single-use consumption and the mandatory
        // OTP_VERIFY_OK audit share ONE boundary — a failed audit rolls the
        // consume back, so a consumed token can never outlive its missing
        // audit. owner decision A: the boundary performs NO identity
        // provisioning anymore (no wp_users / patient-link writes, no
        // LOGIN_SUCCESS); the WP auth cookie below is therefore unreachable
        // this slice and stays as inert defense in depth.
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Existing legacy local naming preserved.
        $clinic_id = $clinicId;

        try {
            $verify_outcome = $this->db->transactional(
                function () use ( $row, $rowClinicId, $mobile, $code, $purpose, $ip, $clinic_id, $policy ): array {
                    // One current read serializes failures and successful consumes
                    // on this challenge. Recheck expiry/lock AFTER any lock wait:
                    // stale pre-transaction state cannot lose failed attempts or
                    // authenticate a token that expired or locked while waiting.
                    $locked = $this->db->fetchRowForUpdate(
                        'SELECT * FROM ' . $this->db->table( 'cpms_otp_tokens' ) . ' WHERE id = %d',
                        [ (int) $row['id'] ]
                    );
                    if ( $this->db->wpdb()->last_error !== '' ) {
                        throw new \RuntimeException( 'OTP challenge read failed' );
                    }
                    $locked_clinic = $locked !== null && isset( $locked['clinic_id'] ) ? (int) $locked['clinic_id'] : null;
                    if ( $locked === null || $locked_clinic !== $rowClinicId
                        || (string) $locked['mobile'] !== $mobile || (string) $locked['purpose'] !== $purpose
                        || $locked['consumed_at'] !== null ) {
                        return [ 'error' => new OtpException( 'CLINIC_OTP_INVALID', 'این کد قبلاً استفاده شده است' ) ];
                    }
                    $current_time = $this->now();
                    if ( $this->toDateTime( (string) $locked['expires_at'] ) <= $current_time ) {
                        return [ 'error' => new OtpException( 'CLINIC_OTP_EXPIRED', 'کد منقضی شده است — درخواست کد جدید بدهید' ) ];
                    }
                    $state = new OtpState(
                        (int) $locked['attempts'],
                        $locked['locked_until'] !== null ? $this->toDateTime( (string) $locked['locked_until'] ) : null,
                        $this->toDateTime( (string) $locked['created_at'] ),
                        0
                    );
                    if ( $state->isLocked( $current_time ) ) {
                        $this->audit( 'CLINIC_OTP_LOCKED', null, $mobile, $ip, [], $clinic_id );
                        $retry_after = max( 1, $policy->remaining_lockout( $state, $current_time ) );
                        return [
                            'error' => new OtpException(
                                'CLINIC_OTP_LOCKED',
                                self::LOCKED_MESSAGE,
                                [ 'retry_after' => $retry_after, 'remaining' => 0 ]
                            ),
                        ];
                    }
                    if ( ! hash_equals( OtpPolicy::hashCode( $code, $this->pepper() ), (string) $locked['code_hash'] ) ) {
                        $failed = $policy->registerFailedAttempt( $state, $current_time );
                        // wpdb::update preserves SQL NULL for locked_until.
                        // Treat a failed/zero-row write as an error, not a
                        // successful counter update or audit event.
                        $updated = $this->db->update(
                            'cpms_otp_tokens',
                            [
                                'attempts'     => $failed->attempts,
                                'locked_until' => $failed->lockedUntil?->format( 'Y-m-d H:i:s.000' ),
                            ],
                            [ 'id' => (int) $locked['id'] ],
                            true
                        );
                        if ( $updated !== 1 ) {
                            throw new \RuntimeException( 'OTP attempt update failed' );
                        }
                        $remaining = $policy->remainingAttempts( $failed );
                        $this->audit( 'OTP_VERIFY_FAIL', null, $mobile, $ip, [ 'remaining' => $remaining ], $clinic_id );
                        $failure_data = [ 'remaining' => $remaining ];
                        if ( $failed->lockedUntil !== null ) {
                            $failure_data['retry_after'] = max( 1, $policy->remaining_lockout( $failed, $current_time ) );
                        }
                        // Return, then throw outside the transaction: failure
                        // counter and mandatory audit must commit together.
                        return [
                            'error' => new OtpException(
                                $failed->lockedUntil !== null ? 'CLINIC_OTP_LOCKED' : 'CLINIC_OTP_INVALID',
                                $failed->lockedUntil !== null ? self::LOCKED_MESSAGE : 'کد اشتباه است',
                                $failure_data
                            ),
                        ];
                    }

                    // مصرف تک‌بار (اتومیک: اگر هم‌زمان مصرف شده باشد، 0 row)
                    // query() reports SQL success even when zero rows matched.
                    // A competing verifier may have consumed this row after our
                    // earlier read, so require exactly one affected row instead.
                    $consumed = $this->db->execute(
                        'UPDATE ' . $this->db->table( 'cpms_otp_tokens' ) .
                        ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL',
                        [ $this->db->nowUtcSql(), (int) $row['id'] ]
                    );
                    if ( $consumed !== 1 ) {
                        throw new OtpException( 'CLINIC_OTP_INVALID', 'این کد قبلاً استفاده شده است' );
                    }

                    // ---- بدون Resolution هویتی (تصمیم مالک ۲۰۲۶-۱۱-۰۹ — Option A) ----
                    //
                    // OTP فقط «مالکیتِ شماره» را اثبات می‌کند؛ چیزی بیشتر نه.
                    //  - هیچ Patient با موبایل جست‌وجو نمی‌شود؛
                    //  - هیچ لینکِ Patient⇄User درج یا بازیابی نمی‌شود (no auto-link)؛
                    //  - هیچ حسابِ mobile-derived ساخته یا بازاستفاده نمی‌شود؛
                    //  - پاسخ برای شماره‌های موجود و ناموجود یکسان و غیرشمارشگر است.
                    //
                    // دسترسی به پروندهٔ Patient فقط از مسیر حسابِ WPِ صریحاً لینک‌شده
                    // به Patient توسط clinic staff می‌گذرد (ابزار staff-link =
                    // Slice 2، خارج از این تغییر). تا آن زمان — حتی اگر لینکِ
                    // ازپیش‌موجودی باشد — OTP هیچ Sessionی صادر نمی‌کند؛ موبایل
                    // هرگز basisِ ownership/access نیست.
                    //
                    // OD-8 هم برقرار است: Purposeِ `verify_mobile` نیز همین مسیر
                    // را می‌گیرد (هیچ حسابی ساخته نمی‌شود).
                    $user_id        = 0;
                    $is_new_user    = false;
                    $session_issued = false;
                    $patient_links  = [];

                    // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- compact audit helper.
                    $this->audit( 'OTP_VERIFY_OK', $user_id, $mobile, $ip, [ 'purpose' => $purpose ], $clinic_id );
                    // LOGIN_SUCCESS عمداً نوشته نمی‌شود: هیچ Sessionی صادر نمی‌شود
                    // (تصمیم مالک — Option A). در صورت بازگشتِ Session در Slice 2،
                    // این Audit باید هم‌زمان با صدور کوکی بازگردانده شود.

                    return [
                        'user_id'        => $user_id,
                        'clinic_id'      => $clinic_id,
                        'patient_links'  => $patient_links,
                        'is_new_user'    => $is_new_user,
                        'session_issued' => $session_issued,
                    ];
                }
            );
        } catch ( \Throwable $e ) {
            // CpmsDb has already rolled the boundary back when this catch runs.
            // Since owner decision A the boundary provisions no identities, so
            // there are no WP user caches to evict; the call stays as a narrow,
            // allocation-free no-op (defense in depth, never a global flush).
            UserCacheRollback::invalidate( [] );
            throw $e;
        }

        if ( isset( $verify_outcome['error'] ) ) {
            throw $verify_outcome['error'];
        }

        // کوکی احراز هویت وردپرس پس از Commitِ پایدار صادر می‌شود (اثر جانبی
        // غیرتراکنشی) — هر شکست در مرز بالا پیش از این نقطه کامل roll back شده.
        if ( $verify_outcome['session_issued'] && function_exists( 'wp_set_auth_cookie' ) ) {
            $scope = $this->session_scope();
            $scope->begin_otp_cookie_issue( (int) $verify_outcome['user_id'], (int) $verify_outcome['clinic_id'] );
            try {
                wp_set_auth_cookie( (int) $verify_outcome['user_id'], true );
            } finally {
                $scope->end_otp_cookie_issue();
            }
        }

        return [
            'user_id'        => (int) $verify_outcome['user_id'],
            'patient_links'  => $verify_outcome['patient_links'],
            'is_new_user'    => (bool) $verify_outcome['is_new_user'],
            'session_issued' => (bool) $verify_outcome['session_issued'],
        ];
    }

    /**
     * C11 — تصمیمِ سهمیهٔ صدور (روزانه/ساعتی) برای یک موبایل.
     *
     * فقط می‌خوانَد (RateLimiter::peek) و هیچ شمارنده‌ای را بالا نمی‌برد؛ مصرف
     * در محلِ صدور و زیرِ همان قفل انجام می‌شود. باید فقط داخل قفلِ صدورِ همان
     * موبایل صدا زده شود، وگرنه مقایسه با سقف دوباره Racy می‌شود.
     *
     * @param string $mobile     شمارهٔ نرمال‌شده (کلیدِ سهمیه).
     * @param int    $daily_max  سقفِ روزانهٔ صادرشده.
     * @param int    $hourly_max سقفِ ساعتیِ صادرشده.
     * @return OtpException|null null یعنی سهمیه آزاد است.
     */
    private function issuance_quota_denial( string $mobile, int $daily_max, int $hourly_max ): ?OtpException {
        if ( $this->rate->peek( 'otp-day:' . $mobile, self::DAY_WINDOW_SEC ) >= max( 1, $daily_max ) ) {
            return new OtpException(
                'CLINIC_OTP_DAILY_LIMIT',
                'محدودیت ارسال کد در روز به پایان رسیده است',
                $this->quota_denied_meta( self::DAY_WINDOW_SEC )
            );
        }
        if ( $this->rate->peek( 'otp-hour:' . $mobile, self::HOUR_WINDOW_SEC ) >= max( 1, $hourly_max ) ) {
            return new OtpException(
                'CLINIC_RATE_LIMITED',
                'درخواست‌های شما موقتاً محدود شده است',
                $this->quota_denied_meta( self::HOUR_WINDOW_SEC )
            );
        }

        return null;
    }

    /**
     * متادیتای ردِ سهمیه با همان شکلِ پیشین (allowed/remaining/reset_at) به‌علاوهٔ
     * retry_after نسبی — همان محاسبهٔ پنجرهٔ RateLimiter::hit().
     *
     * @param int $window_sec طولِ پنجرهٔ شمارنده (ثانیه).
     * @return array{allowed: bool, remaining: int, reset_at: int, retry_after: int}
     */
    private function quota_denied_meta( int $window_sec ): array {
        $reset_at = ( intdiv( time(), $window_sec ) + 1 ) * $window_sec;

        return [
            'allowed'     => false,
            'remaining'   => 0,
            'reset_at'    => $reset_at,
            'retry_after' => max( 1, $reset_at - time() ),
        ];
    }

    private function loadState(string $mobile, string $purpose): OtpState // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose -- established legacy PSR-style in this file; unchanged by OTP slice 1 (this line only became ratchet-visible because the slice's deletions re-anchored the diff hunk); security, SQL, i18n and escaping sniffs stay active.
    {
        // Called inside the per-mobile issuance guard. A non-locking lookup of
        // the latest primary key avoids taking a gap lock for an unseen mobile.
        $latest = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table( 'cpms_otp_tokens' ) .
            ' WHERE mobile = %s AND purpose = %s ORDER BY id DESC LIMIT 1',
            [ $mobile, $purpose ]
        );
        if ( $this->db->wpdb()->last_error !== '' ) {
            throw new \RuntimeException( 'OTP state lookup failed' );
        }
        if ( $latest === null ) {
            return new OtpState();
        }
        // Current-read the exact row after any verification lock wait. Failed
        // verification counters or lockouts committed meanwhile are visible.
        $row = $this->db->fetchRowForUpdate(
            'SELECT attempts, locked_until, created_at FROM ' . $this->db->table( 'cpms_otp_tokens' ) . ' WHERE id = %d',
            [ (int) $latest['id'] ]
        );
        if ( $this->db->wpdb()->last_error !== '' ) {
            throw new \RuntimeException( 'OTP state lock failed' );
        }
        if ( $row === null ) {
            return new OtpState();
        }

        return new OtpState(
            (int) $row['attempts'],
            $row['locked_until'] !== null ? $this->toDateTime( (string) $row['locked_until'] ) : null,
            $this->toDateTime( (string) $row['created_at'] ),
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

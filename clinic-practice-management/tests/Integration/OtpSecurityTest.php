<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Otp\OtpPolicy;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Phase 1A — Item 2 (Authentication) و Item 3 (Rate Limiting) برای OTP.
 *
 * سناریوهای حمله‌ای که پیش از Phase 1 پوشش نداشتند:
 *
 *  - V-1: امضای `verify()` پارامتر IP را `?int` اعلام کرده بود در حالی که
 *    تنها فراخوانِ Production یک `?string` می‌فرستد. با `strict_types=1`
 *    این یعنی TypeError روی هر درخواست واقعی، و در نتیجه Rate Limit
 *    `otp-verify-ip` هرگز اجرا نمی‌شد. هیچ تستی مسیر REST را صدا نمی‌زد
 *    و تست‌های موجود `verify()` را با دو آرگومان صدا می‌زدند، پس باگ در
 *    CI نامرئی بود.
 *  - V-3: `purpose` رشتهٔ آزاد بود و `verify()` بدون توجه به آن کوکی
 *    احراز هویت صادر می‌کرد (Purpose Confusion).
 *  - Replay و Bruteforce و Enumeration.
 */
final class OtpSecurityTest extends WP_UnitTestCase
{
    private const MOBILE = '09121234567';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
    }

    private function service(): OtpService
    {
        return App::otpService();
    }

    /**
     * کد خام هرگز برگردانده نمی‌شود، پس برای تست مستقیم در جدول می‌نویسیم.
     */
    private function seedToken(string $code, string $purpose = OtpService::PURPOSE_LOGIN, int $ttl = 300): int
    {
        $db = App::db();
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            // اجبار به ساخت Pepper ماندگار از طریق یک درخواست واقعی
            try {
                $this->service()->request(self::MOBILE, $purpose);
            } catch (OtpException) {
                // محدودیت نرخ در این مرحله مهم نیست
            }
            $pepper = (string) get_option('cpms_otp_pepper', '');
        }

        $db->insert('cpms_otp_tokens', [
            'mobile' => self::MOBILE,
            'purpose' => $purpose,
            'code_hash' => OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s.000', time() + $ttl),
            'attempts' => 0,
            'created_at' => $db->nowUtcSql(),
        ]);

        return (int) $db->wpdb_last_insert_id();
    }

    // ---------------------------------------------------------------
    // V-1 — امضای IP
    // ---------------------------------------------------------------

    /**
     * رگرسیون مستقیم V-1: عبور یک IP رشته‌ای (همان چیزی که Controller
     * می‌فرستد) نباید TypeError بدهد.
     */
    public function testVerifyAcceptsStringClientIpWithoutTypeError(): void
    {
        $this->seedToken('424242');

        $result = $this->service()->verify(self::MOBILE, '424242', OtpService::PURPOSE_LOGIN, '203.0.113.9');

        self::assertIsInt($result['user_id']);
        self::assertGreaterThan(0, $result['user_id']);
    }

    /**
     * چون IP دیگر رد نمی‌شود، Rate Limit تأیید هم واقعاً اجرا می‌شود.
     */
    public function testVerifyIpRateLimitIsEnforced(): void
    {
        $ip = '203.0.113.10';
        $denied = false;

        for ($i = 0; $i < 25; $i++) {
            $this->seedToken('111111');
            try {
                $this->service()->verify(self::MOBILE, '999999', OtpService::PURPOSE_LOGIN, $ip);
            } catch (OtpException $e) {
                if ($e->apiCode() === 'CLINIC_RATE_LIMITED') {
                    $denied = true;
                    break;
                }
            }
        }

        self::assertTrue($denied, 'Rate Limit تأیید OTP بر اساس IP اعمال نشد.');
    }

    // ---------------------------------------------------------------
    // V-3 — Purpose / Context Binding
    // ---------------------------------------------------------------

    public function testArbitraryPurposeIsRejectedOnRequest(): void
    {
        $this->expectException(OtpException::class);
        $this->expectExceptionMessage('نوع درخواست کد معتبر نیست');

        $this->service()->request(self::MOBILE, 'password_reset_' . uniqid());
    }

    public function testArbitraryPurposeIsRejectedOnVerify(): void
    {
        $this->seedToken('424242');

        try {
            $this->service()->verify(self::MOBILE, '424242', 'anything-goes', '203.0.113.11');
            self::fail('Purpose نامعتبر باید رد شود.');
        } catch (OtpException $e) {
            self::assertSame('CLINIC_OTP_PURPOSE_INVALID', $e->apiCode());
            self::assertSame(400, $e->httpStatus());
        }
    }

    /**
     * یک کد `verify_mobile` نباید به Session ورود تبدیل شود.
     */
    public function testVerifyMobilePurposeDoesNotIssueLoginSession(): void
    {
        $this->seedToken('135790', OtpService::PURPOSE_VERIFY_MOBILE);

        $result = $this->service()->verify(
            self::MOBILE,
            '135790',
            OtpService::PURPOSE_VERIFY_MOBILE,
            '203.0.113.12'
        );

        self::assertFalse($result['session_issued'], 'verify_mobile نباید کوکی احراز هویت بسازد.');
    }

    public function testLoginPurposeIssuesSession(): void
    {
        $this->seedToken('246810', OtpService::PURPOSE_LOGIN);

        $result = $this->service()->verify(self::MOBILE, '246810', OtpService::PURPOSE_LOGIN, '203.0.113.13');

        self::assertTrue($result['session_issued']);
    }

    // ---------------------------------------------------------------
    // Replay / Bruteforce
    // ---------------------------------------------------------------

    public function testCodeCannotBeReplayed(): void
    {
        $this->seedToken('303030');
        $this->service()->verify(self::MOBILE, '303030', OtpService::PURPOSE_LOGIN, '203.0.113.14');

        try {
            $this->service()->verify(self::MOBILE, '303030', OtpService::PURPOSE_LOGIN, '203.0.113.14');
            self::fail('کد مصرف‌شده نباید دوباره پذیرفته شود.');
        } catch (OtpException $e) {
            self::assertSame('CLINIC_OTP_INVALID', $e->apiCode());
        }
    }

    public function testMalformedCodeIsRejectedWithoutDatabaseLookup(): void
    {
        foreach (['', 'abcdef', '12345', '1234567', '12 34 56', '<script>'] as $bad) {
            try {
                $this->service()->verify(self::MOBILE, $bad, OtpService::PURPOSE_LOGIN, '203.0.113.15');
                self::fail('کد بدشکل باید رد شود: ' . $bad);
            } catch (OtpException $e) {
                self::assertSame('CLINIC_OTP_INVALID', $e->apiCode());
            }
        }
    }

    public function testExpiredCodeIsRejected(): void
    {
        $this->seedToken('505050', OtpService::PURPOSE_LOGIN, -60);

        try {
            $this->service()->verify(self::MOBILE, '505050', OtpService::PURPOSE_LOGIN, '203.0.113.16');
            self::fail('کد منقضی باید رد شود.');
        } catch (OtpException $e) {
            self::assertSame('CLINIC_OTP_EXPIRED', $e->apiCode());
        }
    }

    // ---------------------------------------------------------------
    // Pepper
    // ---------------------------------------------------------------

    public function testHashIsKeyedHmacNotPlainConcatenation(): void
    {
        self::assertSame(
            hash_hmac('sha256', '123456', 'p'),
            OtpPolicy::hashCode('123456', 'p')
        );
        self::assertNotSame(
            hash('sha256', '123456' . 'p'),
            OtpPolicy::hashCode('123456', 'p')
        );
    }

    /**
     * در نبود ثابت CPMS_PEPPER باید یک Secret تصادفیِ ماندگار ساخته شود،
     * نه رشتهٔ ثابتِ درونِ کد.
     */
    public function testPepperFallbackIsRandomAndPersisted(): void
    {
        if (defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== '') {
            self::markTestSkipped('CPMS_PEPPER در این محیط تعریف شده است.');
        }

        delete_option('cpms_otp_pepper');
        try {
            $this->service()->request(self::MOBILE, OtpService::PURPOSE_LOGIN);
        } catch (OtpException) {
            // محدودیت نرخ مهم نیست؛ فقط ساخت Pepper مدنظر است
        }

        $pepper = (string) get_option('cpms_otp_pepper', '');
        self::assertNotSame('', $pepper, 'Pepper ماندگار ساخته نشد.');
        self::assertNotSame('cpms-dev-pepper-change-me', $pepper);
        self::assertSame(64, strlen($pepper), 'Pepper باید 256 بیت (64 hex) باشد.');
    }

    // ================= OD-8 =================

    private function dbh(): \ClinicCore\Infrastructure\Db\CpmsDb
    {
        return App::db();
    }

    private function userCount(): int
    {
        return (int) $this->dbh()->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->dbh()->wpdb()->prefix . 'users'
        );
    }

    /**
     * درج مستقیم یک Token معتبر برای یک شمارهٔ دلخواه (کد در DB هش می‌شود و
     * قابل بازخوانی نیست، پس باید خودمان آن را بکاریم).
     */
    private function seedFor(string $mobile, string $code, string $purpose): void
    {
        $db = $this->dbh();
        $pepper = defined('CPMS_PEPPER') && (string) CPMS_PEPPER !== ''
            ? (string) CPMS_PEPPER
            : (string) get_option('cpms_otp_pepper', '');
        if ($pepper === '') {
            try {
                $this->service()->request($mobile, $purpose);
            } catch (OtpException) {
                // بی‌اهمیت در این مرحله
            }
            $pepper = (string) get_option('cpms_otp_pepper', '');
        }

        $db->insert('cpms_otp_tokens', [
            'mobile' => MobileValidator::normalize($mobile),
            'purpose' => $purpose,
            'code_hash' => OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s.000', time() + 300),
            'attempts' => 0,
            'created_at' => $db->nowUtcSql(),
        ]);
    }

    /**
     * OD-8 — گزینهٔ الف: تأیید شماره نباید به‌عنوان اثر جانبی حساب بسازد.
     */
    public function testVerifyMobileDoesNotCreateAccount(): void
    {
        $mobile = '09121110001';
        $this->seedFor($mobile, '111001', OtpService::PURPOSE_VERIFY_MOBILE);
        $before = $this->userCount();

        $result = $this->service()->verify($mobile, '111001', OtpService::PURPOSE_VERIFY_MOBILE);

        self::assertSame($before, $this->userCount(), 'verify_mobile نباید هیچ کاربری بسازد.');
        self::assertSame(0, $result['user_id'], 'شمارهٔ بی‌صاحب باید user_id = 0 بدهد.');
        self::assertFalse($result['is_new_user']);
        self::assertFalse($result['session_issued'], 'verify_mobile هرگز نباید Session بدهد.');
    }

    /**
     * و نباید هیچ لینک بیمار⇄کاربری درج کند.
     */
    public function testVerifyMobileDoesNotCreatePatientUserLink(): void
    {
        $mobile = '09121110002';
        $this->seedFor($mobile, '111002', OtpService::PURPOSE_VERIFY_MOBILE);
        $before = (int) $this->dbh()->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->dbh()->table('cpms_patient_user_links')
        );

        $this->service()->verify($mobile, '111002', OtpService::PURPOSE_VERIFY_MOBILE);

        self::assertSame(
            $before,
            (int) $this->dbh()->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->dbh()->table('cpms_patient_user_links')
            ),
            'verify_mobile نباید لینک بیمار⇄کاربر بسازد.'
        );
    }

    /**
     * OD-8 نباید ورود را بشکند — LOGIN همچنان کاربر و Session می‌سازد.
     */
    public function testLoginStillProvisionsUserAndIssuesSession(): void
    {
        $mobile = '09121110003';
        $this->seedFor($mobile, '111003', OtpService::PURPOSE_LOGIN);
        $before = $this->userCount();

        $result = $this->service()->verify($mobile, '111003', OtpService::PURPOSE_LOGIN);

        self::assertGreaterThan(0, (int) $result['user_id'], 'ورود باید کاربر بسازد/بیابد.');
        self::assertTrue($result['is_new_user']);
        self::assertTrue($result['session_issued'], 'ورود باید Session بدهد.');
        self::assertSame($before + 1, $this->userCount());
    }

    /**
     * معناشناسی واقعی Resolution: `resolveUser()` کاربر را **از مسیر رکورد
     * بیمار** پیدا می‌کند (`cpms_patients` → `cpms_patient_user_links`).
     * `createWpUser()` هیچ رکورد بیماری نمی‌سازد، پس کاربری که با ورود ساخته
     * شده ولی رکورد بیمار ندارد، از این مسیر قابل بازیابی نیست.
     *
     * این عمدی است و با AD-14/Q2 هم‌راستاست: شمارهٔ موبایل به‌تنهایی کلید
     * هویت نیست و Resolution هویت باید صریح باشد، نه ضمنی. بنابراین
     * `verify_mobile` در این حالت `0` می‌دهد — و مهم‌تر: **باز هم چیزی
     * نمی‌سازد**، که دقیقاً هدف OD-8 است.
     */
    public function testVerifyMobileWithoutPatientRecordResolvesToZeroAndStillCreatesNothing(): void
    {
        $mobile = '09121110004';

        $this->seedFor($mobile, '111004', OtpService::PURPOSE_LOGIN);
        $login = $this->service()->verify($mobile, '111004', OtpService::PURPOSE_LOGIN);
        self::assertGreaterThan(0, (int) $login['user_id']);

        $before = $this->userCount();

        $this->seedFor($mobile, '111005', OtpService::PURPOSE_VERIFY_MOBILE);
        $verify = $this->service()->verify($mobile, '111005', OtpService::PURPOSE_VERIFY_MOBILE);

        self::assertSame($before, $this->userCount(), 'نباید کاربر تازه‌ای ساخته شود.');
        self::assertSame(0, (int) $verify['user_id'], 'بدون رکورد بیمار، Resolution به کاربر نمی‌رسد.');
        self::assertFalse($verify['is_new_user']);
        self::assertFalse($verify['session_issued']);
    }

    /**
     * و وقتی زنجیرهٔ بیمار⇄کاربر واقعاً وجود دارد، `verify_mobile` باید همان
     * کاربر موجود را بیابد — بدون ساخت هیچ چیز.
     */
    public function testVerifyMobileResolvesTheLinkedUserOfAnExistingPatient(): void
    {
        $mobile = '09121110006';
        $db = $this->dbh();
        $now = $db->nowUtcSql();

        $userId = self::factory()->user->create(['role' => 'cpms_patient']);
        $db->insert('cpms_patients', [
            'clinic_id' => 1,
            'mrn' => 'OD8-' . substr((string) $userId, -6),
            'first_name' => 'تست',
            'last_name' => 'OD8',
            'mobile' => MobileValidator::normalize($mobile),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $patientId = (int) $db->wpdb_last_insert_id();
        $db->insert('cpms_patient_user_links', [
            'clinic_id' => 1,
            'patient_id' => $patientId,
            'wp_user_id' => $userId,
            'mobile_at_link' => MobileValidator::normalize($mobile),
            'is_primary' => 1,
            'linked_at' => $now,
        ]);

        $before = $this->userCount();
        $linksBefore = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM ' . $db->table('cpms_patient_user_links')
        );

        $this->seedFor($mobile, '111006', OtpService::PURPOSE_VERIFY_MOBILE);
        $verify = $this->service()->verify($mobile, '111006', OtpService::PURPOSE_VERIFY_MOBILE);

        self::assertSame($userId, (int) $verify['user_id'], 'باید همان کاربر متصل پیدا شود.');
        self::assertFalse($verify['is_new_user']);
        self::assertFalse($verify['session_issued'], 'verify_mobile هرگز Session نمی‌دهد.');
        self::assertSame($before, $this->userCount(), 'نباید کاربری ساخته شود.');
        self::assertSame(
            $linksBefore,
            (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_patient_user_links')),
            'نباید لینک تازه‌ای ساخته شود.'
        );
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Auth\OtpException;
use WP_UnitTestCase;

/**
 * TP-05/TP-17 — جریان کامل Mobile+OTP (F2):
 * request → verify (موفق/ناموفق/منقضی/قفل/لیمیت روزانه) + ساخت کاربر + لینک بیمار.
 */
final class OtpFlowTest extends WP_UnitTestCase
{
    private const MOBILE = '09129998877';

    /** @var array<int, string> کدهای تولیدشده (برای تست — از طریق override gateway) */
    private array $issuedCodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
    }

    /**
     * درج مستقیم Token با کد مشخص (جایگزین SMS واقعی در تست).
     */
    private function issueKnownCode(string $code, int $ttlSec = 120): void
    {
        $pepper = defined('CPMS_PEPPER') ? CPMS_PEPPER : 'cpms-dev-pepper-change-me';
        App::db()->insert('cpms_otp_tokens', [
            'mobile' => self::MOBILE,
            'purpose' => 'login',
            'code_hash' => \ClinicCore\Domain\Otp\OtpPolicy::hashCode($code, $pepper),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSec) . '.000',
            'attempts' => 0,
            'created_at' => App::db()->nowUtcSql(),
        ]);
    }

    private function service(): \ClinicCore\Application\Auth\OtpService
    {
        return App::otpService();
    }

    public function testInvalidMobileRejected(): void
    {
        $this->expectException(OtpException::class);
        try {
            $this->service()->request('08121234567');
        } catch (OtpException $e) {
            $this->assertSame('CLINIC_MOBILE_INVALID', $e->apiCode());
            throw $e;
        }
    }

    public function testRequestCreatesHashedTokenNotRawCode(): void
    {
        $result = $this->service()->request(self::MOBILE);
        $this->assertSame(120, $result['expires_in']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT code_hash FROM ' . $wpdb->prefix . 'cpms_otp_tokens WHERE mobile = %s ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::MOBILE
            ),
            ARRAY_A
        );
        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['code_hash']);
        $this->assertStringNotContainsString('کد', (string) $row['code_hash']);
    }

    public function testVerifySuccessCreatesUserAndLogsIn(): void
    {
        $this->issueKnownCode('424242');

        $result = $this->service()->verify(self::MOBILE, '424242');

        $this->assertGreaterThan(0, $result['user_id']);
        $this->assertTrue($result['is_new_user']);
        $this->assertSame([], $result['patient_links'], 'بیماری با این موبایل هنوز نیست');

        // کاربر در wp_users با نقش بیمار
        global $wpdb;
        $role = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT meta_value FROM ' . $wpdb->prefix . 'usermeta um
                 JOIN ' . $wpdb->prefix . 'users u ON u.ID = um.user_id
                 WHERE um.meta_key = %s AND u.ID = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'wp_capabilities',
                $result['user_id']
            )
        );
        $caps = unserialize((string) $role, ['allowed_classes' => ['array']]);
        $this->assertIsArray($caps);
        $this->assertArrayHasKey('cpms_patient', $caps);

        // Token مصرف شده (تک‌بار)
        $consumed = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_otp_tokens WHERE mobile = %s AND consumed_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                self::MOBILE
            )
        );
        $this->assertSame(0, (int) $consumed);
    }

    public function testVerifyWrongCodeIncrementsAttemptsThenLocks(): void
    {
        $this->issueKnownCode('424242');

        // 5 بار اشتباه → قفل
        for ($i = 1; $i <= 5; $i++) {
            try {
                $this->service()->verify(self::MOBILE, '000000');
                $this->fail('باید خطا بدهد');
            } catch (OtpException $e) {
                if ($i < 5) {
                    $this->assertSame('CLINIC_OTP_INVALID', $e->apiCode());
                } else {
                    $this->assertSame('CLINIC_OTP_LOCKED', $e->apiCode());
                }
            }
        }

        // حتی کد درست هم در قفل رد می‌شود
        $this->expectException(OtpException::class);
        $this->service()->verify(self::MOBILE, '424242');
    }

    public function testExpiredTokenRejected(): void
    {
        $this->issueKnownCode('424242', ttlSec: -10); // منقضی

        $this->expectException(OtpException::class);
        try {
            $this->service()->verify(self::MOBILE, '424242');
        } catch (OtpException $e) {
            $this->assertSame('CLINIC_OTP_EXPIRED', $e->apiCode());
            throw $e;
        }
    }

    public function testSecondUseOfSameCodeRejected(): void
    {
        $this->issueKnownCode('424242');
        $this->service()->verify(self::MOBILE, '424242');

        $this->expectException(OtpException::class);
        try {
            $this->service()->verify(self::MOBILE, '424242');
        } catch (OtpException $e) {
            $this->assertContains($e->apiCode(), ['CLINIC_OTP_INVALID', 'CLINIC_OTP_EXPIRED']);
            throw $e;
        }
    }

    public function testVerifyLinksExistingPatient(): void
    {
        // بیمار موجود با این موبایل
        $now = App::db()->nowUtcSql();
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (1, %s, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'P-000999',
                'بیمار',
                'تست',
                self::MOBILE,
                'active',
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;

        $this->issueKnownCode('135790');
        $result = $this->service()->verify(self::MOBILE, '135790');

        $this->assertSame(1, count($result['patient_links']));
        $this->assertSame((string) $patientId, (string) $result['patient_links'][0]['id']);
        $this->assertSame('P-000999', $result['patient_links'][0]['mrn']);

        // لینک ساخته شده
        $link = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_patient_user_links WHERE patient_id = %d AND wp_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $patientId,
                $result['user_id']
            )
        );
        $this->assertSame(1, (int) $link);
    }

    public function testDailyLimitBlocksNewRequests(): void
    {
        $service = $this->service();
        $service->request(self::MOBILE);
        $service->request(self::MOBILE);
        $service->request(self::MOBILE);

        $this->expectException(OtpException::class);
        try {
            $service->request(self::MOBILE); // دعوای چهارم
        } catch (OtpException $e) {
            $this->assertSame('CLINIC_OTP_DAILY_LIMIT', $e->apiCode());
            throw $e;
        }
    }

    public function testAuditEventsRecordedWithoutCode(): void
    {
        $this->issueKnownCode('424242');
        $this->service()->verify(self::MOBILE, '424242');

        global $wpdb;
        $actions = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT action FROM ' . $wpdb->prefix . 'cpms_audit_logs WHERE resource_type = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'otp'
            )
        );
        $this->assertContains('OTP_VERIFY_OK', $actions);
        $this->assertContains('LOGIN_SUCCESS', $actions);

        // هیچ کد در Audit نباشد
        $all = (string) $wpdb->get_var(
            'SELECT GROUP_CONCAT(before_json, after_json, meta_json SEPARATOR " ") FROM ' . $wpdb->prefix . 'cpms_audit_logs' // phpcs:ignore
        );
        $this->assertStringNotContainsString('424242', $all, 'کد OTP نباید در Audit باشد');
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use WP_UnitTestCase;

/**
 * ADR-0025 — جریان کامل ماژول پیامک (Provider=log در تست):
 * ارسال از Business Logic (OTP) → Record → SENT
 * + تنظیمات/Secret Mask + Permission + Dedupe + Log Mask.
 */
final class SmsFlowTest extends WP_UnitTestCase
{
    private const MOBILE = '09128887766';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
    }

    private function sms(): SmsService
    {
        return App::smsService();
    }

    public function testOtpRequestCreatesSentMessageRow(): void
    {
        $result = App::otpService()->request(self::MOBILE);
        $this->assertTrue($result['sms_sent'], 'با Provider=log ارسال باید SENT باشد');

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE event = %s ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'otp'
            ),
            ARRAY_A
        );
        $this->assertNotNull($row);
        $this->assertSame(SmsMessageStatus::SENT, (string) $row['status']);
        $this->assertSame(self::MOBILE, (string) $row['recipient']);
        $this->assertNotNull($row['provider_msg_id']);

        // ایمنی (§8): کد OTP خام در رکورد Message ذخیره نمی‌شود — متن Mask است
        $this->assertStringContainsString('***', (string) $row['message']);
        $this->assertNull($row['vars_json'], 'متغیرهای OTP (شامل کد) نباید ذخیره شوند');
    }

    public function testDedupePreventsDuplicateContextMessage(): void
    {
        $first = $this->sms()->sendEvent('appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', 4242, inline: true);
        $second = $this->sms()->sendEvent('appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', 4242, inline: true);

        $this->assertSame($first['message_id'], $second['message_id'], 'ارسال دوم باید Dedupe شود');

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE context_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                4242
            )
        );
        $this->assertSame(1, $count);
    }

    public function testStatusEndpointNeverReturnsSecrets(): void
    {
        // ذخیره Credential
        App::settings()->set('sms.provider', 'generic_api');
        App::settings()->set(
            'sms.auth',
            [
                'method' => 'api_key',
                'fields' => [
                    'api_key' => [
                        'sealed' => App::vault()->encrypt('top-secret-key-9876'),
                        'last4' => '9876',
                    ],
                ],
                'updated_at' => gmdate('c'),
            ]
        );
        \ClinicCore\Settings\Settings::flushCache();

        $status = $this->sms()->status();

        $this->assertSame('CONFIGURED', $status['state']); // generic + api_key ذخیره‌شده
        $serialized = json_encode($status, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('top-secret-key-9876', (string) $serialized, 'Secret نباید در خروجی Settings باشد');
        $this->assertStringContainsString('••••••••9876', (string) $serialized, 'Placeholder با 4 رقم آخر');
    }

    public function testSaveSettingsKeepsStoredCredentialWhenInputEmpty(): void
    {
        App::settings()->set('sms.provider', 'generic_api');
        App::settings()->set(
            'sms.auth',
            [
                'method' => 'api_key',
                'fields' => [
                    'api_key' => [
                        'sealed' => App::vault()->encrypt('keep-me-1234'),
                        'last4' => '1234',
                    ],
                ],
                'updated_at' => gmdate('c'),
            ]
        );
        \ClinicCore\Settings\Settings::flushCache();

        // Save با Credential خالی → باید قبلی حفظ شود
        $this->sms()->saveSettings(['provider' => 'generic_api', 'auth_method' => 'api_key', 'credentials' => ['api_key' => '']], 1);

        $plain = App::vault()->decrypt((array) ((array) App::settings()->get('sms.auth', []))['fields']['api_key']['sealed']);
        $this->assertSame('keep-me-1234', $plain, 'Credential ذخیره‌شده نباید با Field خالی پاک شود');

        // حذف صریح
        $this->sms()->saveSettings(['provider' => 'generic_api', 'auth_method' => 'api_key', 'credentials' => ['api_key' => '__CLEAR__']], 1);
        $stored = (array) App::settings()->get('sms.auth', []);
        $this->assertArrayNotHasKey('api_key', (array) ($stored['fields'] ?? []), '__CLEAR__ باید Credential را حذف کند');
    }

    public function testTemplateTestWithLogProvider(): void
    {
        $result = $this->sms()->testTemplate(
            'otp',
            self::MOBILE,
            ['otp_code' => '111222'],
            1
        );

        $this->assertSame(SmsMessageStatus::SENT, $result['status']);
        $this->assertIsString($result['preview']);
    }

    public function testPreviewRejectsMissingRequiredVar(): void
    {
        $this->expectException(\ClinicCore\Domain\Sms\SmsTemplateException::class);
        $this->sms()->preview('appointment_reminder', ['patient_name' => 'علی']); // تاریخ/ساعت ندارند
    }

    public function testLogsAreMaskedAndPaginated(): void
    {
        $this->sms()->sendEvent('appointment_cancelled', self::MOBILE, $this->apptVars(), 'appointment', 777, inline: true);

        $logs = $this->sms()->logs(null, 1, 20);

        $this->assertGreaterThan(0, $logs['total']);
        $item = $logs['items'][0];
        $this->assertSame('0912***7766', $item['recipient']);
        $this->assertStringNotContainsString(self::MOBILE, json_encode($logs['items'], JSON_UNESCAPED_UNICODE));
    }

    public function testProviderListExposesCapabilitiesNotSecrets(): void
    {
        $list = App::providers()->all();

        $ids = array_column($list, 'id');
        $this->assertContains('log', $ids);
        $this->assertContains('generic_api', $ids);

        $serialized = json_encode($list, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('sealed', (string) $serialized);
    }

    /**
     * @return array<string, string>
     */
    private function apptVars(): array
    {
        return [
            'patient_name' => 'بیمار تست',
            'doctor_name' => 'پزشک تست',
            'appointment_date' => '1405/06/10',
            'appointment_time' => '09:30',
            'clinic_name' => 'مطب تست',
        ];
    }
}

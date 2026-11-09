<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * ADR-0025 — جریان کامل ماژول پیامک (Provider=log در تست):
 * ارسال از Business Logic (OTP) → Record → SENT
 * + تنظیمات/Secret Mask + Permission + Dedupe + Log Mask.
 */
final class SmsFlowTest extends WP_UnitTestCase
{
    use \ClinicCore\Tests\Support\SignedExpirationFixture;

    private const MOBILE = '09128887766';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
    }

    protected function tearDown(): void
    {
        remove_all_filters('cpms_license_public_key');
        parent::tearDown();
    }

    public function testExpirationSuppressesNewAndQueuedCommercialSmsButKeepsSecurityOtp(): void
    {
        $this->installSignedExpiration(time() + 86400);
        $queued = $this->sms()->sendEvent(1, 'appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', 8675309);
        $this->assertSame(SmsMessageStatus::QUEUED, $queued['status']);

        $this->installSignedExpiration(time() - 1); // Inside historical grace/skew, still T0.
        $this->sms()->dispatchMessage($queued['message_id']);
        $row = App::db()->fetchRow('SELECT status, failure_code FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE id = %d', [$queued['message_id']]);
        $this->assertSame(SmsMessageStatus::FAILED, $row['status']);
        $this->assertSame('CLINIC_LICENSE_BLOCKED', $row['failure_code']);
        $suppressed = $this->sms()->sendEvent(1, 'appointment_reminder', self::MOBILE, $this->apptVars(), inline: true);
        $this->assertSame(0, $suppressed['message_id']);
        $this->assertSame(SmsMessageStatus::FAILED, $suppressed['status']);
        $this->testOtpRequestCreatesSentMessageRow();

        $this->installSignedExpiration(time() + 86400);
        $renewed = $this->sms()->sendEvent(1, 'appointment_reminder', self::MOBILE, $this->apptVars(), inline: true);
        $this->assertSame(SmsMessageStatus::SENT, $renewed['status']);
        $this->assertTrue(App::audit()->verifyChain(500)['ok']);
    }


    private function failSettingsBulkReads(int $clinicId, bool $oneShot): \Closure
    {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'cpms_settings';
        $fired          = false;

        return static function ($query) use ($clinicId, $settings_table, $oneShot, &$fired) {
            $query = (string) $query;
            if (
                (! $oneShot || ! $fired)
                && str_contains($query, 'SELECT `key`, value_json FROM ' . $settings_table)
                && preg_match('/WHERE clinic_id = ' . $clinicId . '\s*$/', $query) === 1
            ) {
                $fired = true;

                return str_replace('FROM ' . $settings_table, 'FROM ' . $settings_table . '_read_failure', $query);
            }

            return $query;
        };
    }

    private function sms(): SmsService
    {
        return App::smsService();
    }

    /**
     * C6‑F (Class A repair) — Dedupe نباید Clinic‌ها را با هم ترکیب کند:
     * یک رویداد/Context/روزِ یکسان در دو Clinic دو پیغام جدا می‌سازد.
     * (شاهد قدیم: dedupe_key = sha256(event|ctxType|ctxId|day) — بدون هویت Clinic،
     *  در حالی که uq_dedupe روی ستون سراسری است.)
     */
    public function testDedupeIsScopedPerClinic(): void
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at)
                 VALUES (%d, (SELECT organization_id FROM (SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = 1) o), %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                60011,
                'Clinic Dedupe B',
                'clinic-dedupe-b',
                'Asia/Tehran',
                $now,
                $now
            )
        );
        \ClinicCore\Application\Scope\SystemClinicResolver::flush();

        $ctxId = 909090;
        $a = $this->sms()->sendEvent(1, 'appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', $ctxId, inline: true);
        $b = $this->sms()->sendEvent(60011, 'appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', $ctxId, inline: true);

        $this->assertNotSame(
            $a['message_id'],
            $b['message_id'],
            'ارسال Clinic دیگر نباید به‌خاطر Dedupe بی‌نامِ Clinic سرکوب شود'
        );

        $keys = (array) $wpdb->get_col(
            $wpdb->prepare(
                'SELECT dedupe_key FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE context_id = %d ORDER BY id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $ctxId
            )
        );
        $this->assertCount(2, $keys, 'دو ردیف (نه یکیِ سرکوب‌شده)');
        $this->assertNotSame($keys[0], $keys[1], 'dedupe_key باید هویت Clinic را داشته باشد');
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
        $first = $this->sms()->sendEvent(1, 'appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', 4242, inline: true);
        $second = $this->sms()->sendEvent(1, 'appointment_reminder', self::MOBILE, $this->apptVars(), 'appointment', 4242, inline: true);

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


    public function testSaveSettingsPreservesCredentialsAcrossTransientSettingsReadFailure(): void
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

        // One transient bulk-read failure: an earlier soft read yields
        // unverified defaults, then the actual save runs after recovery.
        // Blank credential fields mean "retain existing", so the stored
        // credential must survive — never be dropped by an unverified read.
        $clinic_id = (int) App::scope()->clinicId;
        $one_shot  = $this->failSettingsBulkReads($clinic_id, true);
        add_filter('query', $one_shot, 5, 1);
        self::assertSame('', (string) App::settings()->get('sms.provider', ''), 'the transient failure must surface as unverified defaults first');
        remove_filter('query', $one_shot, 5);

        $this->sms()->saveSettings(['provider' => 'generic_api', 'auth_method' => 'api_key', 'credentials' => ['api_key' => '']], 1);

        $plain = App::vault()->decrypt((array) ((array) App::settings()->get('sms.auth', []))['fields']['api_key']['sealed']);
        self::assertSame('keep-me-1234', $plain, 'the stored credential must survive a save computed across a transient read failure');

        // A persistent read failure refuses the save without touching the
        // stored credential.
        \ClinicCore\Settings\Settings::flushCache();
        $persistent = $this->failSettingsBulkReads($clinic_id, false);
        add_filter('query', $persistent, 5, 1);
        $threw = false;
        try {
            $this->sms()->saveSettings(['provider' => 'generic_api', 'auth_method' => 'api_key', 'credentials' => ['api_key' => '']], 1);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            remove_filter('query', $persistent, 5);
        }
        \ClinicCore\Settings\Settings::flushCache();

        self::assertTrue($threw, 'an unverifiable settings snapshot must refuse the SMS save');
        $plain = App::vault()->decrypt((array) ((array) App::settings()->get('sms.auth', []))['fields']['api_key']['sealed']);
        self::assertSame('keep-me-1234', $plain, 'the stored credential must be untouched by the refused save');
    }

    public function testSaveTemplateMergesAgainstVerifiedSnapshotAfterTransientReadFailure(): void
    {
        App::settings()->set('sms.templates', [
            'appointment_reminder' => ['template_id' => 'TPL-KEEP', 'updated_at' => gmdate('c')],
            'otp'                  => ['template_id' => 'TPL-OTP', 'updated_at' => gmdate('c')],
        ]);
        Settings::flushCache();

        // One transient bulk-read failure: an earlier soft read yields the
        // unverified empty default, then the actual save runs after
        // recovery. The merge must be computed from the verified stored
        // map so the other event's template is not dropped.
        $clinic_id = (int) App::scope()->clinicId;
        $one_shot  = $this->failSettingsBulkReads($clinic_id, true);
        add_filter('query', $one_shot, 5, 1);
        self::assertSame([], (array) App::settings()->get('sms.templates', []), 'the transient failure must surface as unverified defaults first');
        remove_filter('query', $one_shot, 5);

        $this->sms()->saveTemplate('appointment_reminder', '', 1);

        Settings::flushCache();
        $stored = (array) App::settings()->get('sms.templates', []);
        self::assertSame('', (string) ($stored['appointment_reminder']['template_id'] ?? 'missing'), 'the edited event must be updated');
        self::assertSame('TPL-OTP', (string) ($stored['otp']['template_id'] ?? ''), 'the other event template must survive a merge computed after a transient read failure');

        // A persistent read failure refuses the save and leaves the
        // stored template map untouched.
        Settings::flushCache();
        $persistent = $this->failSettingsBulkReads($clinic_id, false);
        add_filter('query', $persistent, 5, 1);
        $threw = false;
        try {
            $this->sms()->saveTemplate('otp', '', 1);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            remove_filter('query', $persistent, 5);
        }
        Settings::flushCache();

        self::assertTrue($threw, 'an unverifiable settings snapshot must refuse the template save');
        $stored = (array) App::settings()->get('sms.templates', []);
        self::assertSame('TPL-OTP', (string) ($stored['otp']['template_id'] ?? ''), 'the stored template map must be untouched by the refused save');
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
        $this->sms()->sendEvent(1, 'appointment_cancelled', self::MOBILE, $this->apptVars(), 'appointment', 777, inline: true);

        $logs = $this->sms()->logs(1, null, 1, 20);

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

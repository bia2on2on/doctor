<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Settings\Settings;
use ClinicCore\Tests\Support\RecordingSmsProvider;
use WP_UnitTestCase;

/**
 * D5 — OTP delivery without secret persistence (real MySQL, recording provider).
 *
 * PROVEN DEFECT this suite pins down: for a plain-text (non-template) OTP the
 * stored message is deliberately masked ("***"), and dispatchMessage() handed
 * that masked row text straight to the provider's sendText(), so the SMS
 * transport received "***" instead of the real one-time code.
 *
 * REQUIRED CONTRACT (asserted here):
 *  1. an immediate plain-text OTP dispatch gives the provider the REAL code,
 *     transiently in memory only;
 *  2. the persisted cpms_sms_messages row keeps the masked text and a NULL
 *     vars_json — no plaintext OTP at rest and no OTP in queue data;
 *  3. template mode still works and carries the live code as a variable;
 *  4. a re-drive that no longer holds the live code fails CLOSED with the
 *     explicit CLINIC_SMS_OTP_NO_RETRY classification — it never sends the
 *     masked text as if delivered and never calls the provider;
 *  5. non-OTP messages keep their existing stored-text behavior.
 */
final class SmsOtpDispatchTest extends WP_UnitTestCase
{
    private const MOBILE = '09123450001';
    private const CLINIC_ID = 1;

    private RecordingSmsProvider $recording;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();

        $this->recording = new RecordingSmsProvider();
        App::providers()->register($this->recording);

        App::settings()->set('sms.provider', RecordingSmsProvider::ID);
        App::settings()->set('sms.templates', []);
        Settings::flushCache();
    }

    protected function tearDown(): void
    {
        App::settings()->set('sms.provider', 'log');
        App::settings()->set('sms.templates', []);
        Settings::flushCache();
        parent::tearDown();
    }

    public function testPlainTextOtpProviderReceivesRealCodeWhileStoredRowStaysMasked(): void
    {
        $code = '482915';

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );

        self::assertSame(SmsMessageStatus::SENT, $result['status']);
        self::assertGreaterThan(0, $result['message_id']);

        // The transport received the real code, exactly once, as plain text.
        self::assertCount(1, $this->recording->textSends, 'one plain-text provider call must happen');
        self::assertSame([], $this->recording->templateSends, 'text mode must not use the template transport');
        $outbound = $this->recording->textSends[0];
        self::assertSame(self::MOBILE, $outbound['mobile']);
        self::assertStringContainsString($code, $outbound['message'], 'the provider must receive the real OTP, not the mask');
        self::assertStringNotContainsString('***', $outbound['message'], 'the outbound provider text must not be masked');

        // The durable row keeps the mask and never stores the code or its vars.
        $row = $this->fetchRow((int) $result['message_id']);
        self::assertSame(SmsMessageStatus::SENT, (string) $row['status']);
        self::assertStringContainsString('***', (string) $row['message'], 'the stored message must stay masked');
        self::assertStringNotContainsString($code, (string) $row['message'], 'the raw OTP must never be persisted');
        self::assertNull($row['vars_json'], 'OTP variables must never be persisted');

        // No OTP value anywhere else in the row or in the durable job queue.
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->queueRowsContaining($code), 'no queue payload may carry the OTP');
    }

    public function testTemplateModeStillDeliversLiveOtpVariableAndStoresPlaceholder(): void
    {
        App::settings()->set('sms.templates', ['otp' => ['template_id' => 'TPL-OTP-1', 'updated_at' => gmdate('c')]]);
        Settings::flushCache();

        $code = '731046';
        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );

        self::assertSame(SmsMessageStatus::SENT, $result['status']);
        self::assertCount(1, $this->recording->templateSends, 'template mode must use the template transport');
        self::assertSame([], $this->recording->textSends);

        $tpl = $this->recording->templateSends[0];
        self::assertSame('TPL-OTP-1', $tpl['template_id']);
        self::assertSame($code, (string) ($tpl['variables']['otp_code'] ?? ''), 'the template variables must carry the real OTP');

        $row = $this->fetchRow((int) $result['message_id']);
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE), 'no OTP at rest in template mode either');
        self::assertNull($row['vars_json']);
        self::assertSame('TPL-OTP-1', (string) $row['template_id']);
    }

    public function testReDriveWithoutLiveOtpFailsClosedAndNeverCallsProvider(): void
    {
        $code = '905612';
        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);
        self::assertCount(1, $this->recording->textSends);

        // Simulate a post-exit re-drive (job/manual retry in another process):
        // put the row back into a non-terminal state and dispatch without vars.
        $messageId = (int) $result['message_id'];
        App::db()->update('cpms_sms_messages', ['status' => SmsMessageStatus::QUEUED, 'failure_code' => null], ['id' => $messageId]);

        App::smsService()->dispatchMessage($messageId);

        $row = $this->fetchRow($messageId);
        self::assertSame(SmsMessageStatus::FAILED, (string) $row['status'], 'an OTP without live vars must fail closed');
        self::assertSame(
            \ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE,
            (string) $row['failure_code'],
            'the explicit no-retry classification must be recorded'
        );
        self::assertCount(1, $this->recording->textSends, 'the fail-closed path must not call the provider again');
        self::assertStringContainsString('***', (string) $row['message'], 'the stored message must remain masked');
        self::assertNull($row['vars_json']);
    }

    public function testFailedImmediateOtpFailsClosedWithoutRetryableClassification(): void
    {
        $code = '664820';
        $this->recording->failNextRetryable = true;

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::OTP,
            self::MOBILE,
            ['otp_code' => $code],
            'otp_token',
            $this->uniqueContext(),
            inline: true
        );
        // P2: an OTP is always inline and never enqueued, so even with the live
        // code in memory a retryable transport failure must be classified FAILED
        // (never RETRYING / retry_enqueued) — no recoverable retry job exists.
        self::assertSame(SmsMessageStatus::FAILED, $result['status']);

        $messageId = (int) $result['message_id'];
        $row = $this->fetchRow($messageId);
        self::assertSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE), 'a failed send must not leave the OTP at rest');
        self::assertNull($row['vars_json']);

        // A later re-drive keeps the fail-closed state and never calls the provider.
        App::smsService()->dispatchMessage($messageId);
        $row = $this->fetchRow($messageId);
        self::assertSame(SmsMessageStatus::FAILED, (string) $row['status']);
        self::assertSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
        self::assertCount(0, $this->recording->textSends, 'no masked or retried OTP send must reach the provider');
    }

    public function testRetryableNonOtpFailureStillClassifiesAsRetrying(): void
    {
        // P2 must not change the ordinary queued-message retry contract: only OTP
        // events fail closed; a retryable non-OTP inline failure stays RETRYING.
        $this->recording->failNextRetryable = true;

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            [
                'patient_name'     => 'بیمار تست',
                'appointment_date' => '1405/08/01',
                'appointment_time' => '09:00',
            ],
            'appointment',
            $this->uniqueContext(),
            inline: true
        );

        self::assertSame(SmsMessageStatus::RETRYING, $result['status']);
        $row = $this->fetchRow((int) $result['message_id']);
        self::assertSame('RETRYING', (string) $row['status']);
        self::assertNotSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
    }

    public function testNonOtpMessagesKeepStoredTextAndVarsBehavior(): void
    {
        $vars = [
            'patient_name' => 'بیمار تست',
            'doctor_name' => 'دکتر تست',
            'appointment_date' => '1405/06/10',
            'appointment_time' => '09:30',
            'clinic_name' => 'مطب تست',
        ];

        $result = App::smsService()->sendEvent(
            self::CLINIC_ID,
            SmsEvents::APPT_REMINDER,
            self::MOBILE,
            $vars,
            'appointment',
            $this->uniqueContext(),
            inline: true
        );
        self::assertSame(SmsMessageStatus::SENT, $result['status']);

        self::assertCount(1, $this->recording->textSends);
        $row = $this->fetchRow((int) $result['message_id']);
        // Non-OTP behavior is untouched: provider text equals the stored text,
        // and non-secret variables remain persisted for template retries.
        self::assertSame((string) $row['message'], $this->recording->textSends[0]['message']);
        self::assertNotNull($row['vars_json']);
        self::assertStringContainsString('بیمار تست', (string) $row['vars_json']);
    }

    // ------------------------------------------------------------------

    /**
     * D7 — OtpService must never report retry_enqueued=true when dispatch
     * fails: OTP delivery is inline-only, the plaintext code lives in this
     * request's memory and no recoverable queue retry can ever run. A
     * structural failure (here a real MySQL 1054 on the sms message INSERT,
     * which SmsService cannot catch internally) must surface as an honest
     * sms_sent=false / retry_enqueued=false while the challenge token stays
     * durable for a fresh user-initiated request.
     */
    public function testOtpServiceNeverReportsFalseRetryEnqueuedOnStructuralDispatchFailure(): void
    {
        $mobile = '0912' . random_int(4000000, 4999999);

        $fault = static function ($query) {
            $sql = (string) $query;
            if (preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $m) === 1
                && str_contains($m[1], 'cpms_sms_messages')) {
                return 'INSERT INTO ' . $m[1] . ' (`cpms_fault_injection_no_such_column`) VALUES (1)';
            }

            return $query;
        };
        global $wpdb;
        $suppressed = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);

        try {
            $result = App::otpService()->request($mobile, 'login', null, null, self::CLINIC_ID);
        } finally {
            remove_filter('query', $fault, 5);
            $wpdb->suppress_errors($suppressed);
        }

        self::assertFalse($result['sms_sent'], 'the failed dispatch must not be reported as sent');
        self::assertFalse($result['retry_enqueued'], 'no retry can ever be enqueued for an inline OTP — the flag must stay honest');
        self::assertSame(
            1,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s',
                [$mobile]
            ),
            'the durable challenge token survives the dispatch failure for an authorized fresh request'
        );
        self::assertSame(
            0,
            (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages')),
            'the faulted message row must not exist — and therefore no plaintext OTP can be at rest'
        );
        self::assertGreaterThan(
            0,
            (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
                ['OTP_SENT_FAIL']
            ),
            'the structural failure must be audited as OTP_SENT_FAIL'
        );

        // Authorized fresh request (fault removed): normal honest success flags.
        $mobileOk = '0912' . random_int(5000000, 5999999);
        $ok = App::otpService()->request($mobileOk, 'login', null, null, self::CLINIC_ID);
        self::assertTrue($ok['sms_sent']);
        self::assertFalse($ok['retry_enqueued'], 'a successful inline OTP send never enqueues a retry either');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(int $messageId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE id = %d',
            [$messageId]
        );
        self::assertIsArray($row);

        return $row;
    }

    private function queueRowsContaining(string $needle): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . ' WHERE payload_json LIKE %s',
            ['%' . $needle . '%']
        );
    }

    private function uniqueContext(): int
    {
        return (int) (microtime(true) * 1000) + random_int(1000, 9999);
    }
}

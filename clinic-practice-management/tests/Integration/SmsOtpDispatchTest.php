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

    public function testFailedImmediateOtpIsSafeAndSubsequentReDriveFailsClosed(): void
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
        // The immediate attempt still held the live code, so it follows the
        // ordinary retryable policy rather than the no-retry classification.
        self::assertSame(SmsMessageStatus::RETRYING, $result['status']);

        $messageId = (int) $result['message_id'];
        $row = $this->fetchRow($messageId);
        self::assertStringNotContainsString($code, (string) json_encode($row, JSON_UNESCAPED_UNICODE), 'a failed send must not leave the OTP at rest');
        self::assertNull($row['vars_json']);

        // The queued retry no longer has the live code: fail closed, no provider call.
        App::smsService()->dispatchMessage($messageId);
        $row = $this->fetchRow($messageId);
        self::assertSame(SmsMessageStatus::FAILED, (string) $row['status']);
        self::assertSame(\ClinicCore\Application\Notifications\SmsService::OTP_NO_RETRY_CODE, (string) $row['failure_code']);
        self::assertCount(0, $this->recording->textSends, 'neither the retry nor any masked send must reach the provider');
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

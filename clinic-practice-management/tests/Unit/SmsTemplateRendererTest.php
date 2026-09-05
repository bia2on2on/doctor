<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Sms\SmsTemplateException;
use ClinicCore\Domain\Sms\SmsTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0025 §12/§15 — رندر و اعتبارسنجی Template SMS.
 */
final class SmsTemplateRendererTest extends TestCase
{
    private const ALLOWED = ['patient_name', 'doctor_name', 'appointment_date', 'appointment_time', 'clinic_name'];
    private const REQUIRED = ['patient_name', 'appointment_date', 'appointment_time'];

    public function testRendersVariables(): void
    {
        $text = 'سلام {{patient_name}}، نوبت شما {{appointment_date}} ساعت {{appointment_time}}.';
        $out = SmsTemplateRenderer::render($text, [
            'patient_name' => 'علی',
            'appointment_date' => '1405/06/10',
            'appointment_time' => '09:30',
        ], self::ALLOWED, self::REQUIRED);

        $this->assertSame('سلام علی، نوبت شما 1405/06/10 ساعت 09:30.', $out);
    }

    public function testUnknownVariableRejected(): void
    {
        $this->expectException(SmsTemplateException::class);
        $this->expectExceptionMessage('otp_code');
        SmsTemplateRenderer::render(
            'کد شما: {{otp_code}}',
            ['otp_code' => '123456'],
            self::ALLOWED,
            self::REQUIRED
        );
    }

    public function testMissingRequiredVariableRejected(): void
    {
        $this->expectException(SmsTemplateException::class);
        $this->expectExceptionMessage('patient_name');
        SmsTemplateRenderer::render(
            'سلام {{patient_name}}',
            [],
            self::ALLOWED,
            self::REQUIRED
        );
    }

    public function testWhitespaceInsideBracesTolerated(): void
    {
        $out = SmsTemplateRenderer::render(
            'کد: {{ otp_code }}',
            ['otp_code' => '654321'],
            ['otp_code'],
            ['otp_code']
        );

        $this->assertSame('کد: 654321', $out);
    }

    public function testFindVariables(): void
    {
        $found = SmsTemplateRenderer::findVariables('سلام {{patient_name}} و {{clinic_name}}، تکرار: {{patient_name}}');

        $this->assertSame(['patient_name', 'clinic_name'], $found);
    }

    public function testNoVariablesInText(): void
    {
        $this->assertSame('متن ساده', SmsTemplateRenderer::render('متن ساده', [], self::ALLOWED, []));
    }

    public function testExtraVarsInPayloadAreIgnored(): void
    {
        $out = SmsTemplateRenderer::render(
            '{{patient_name}}',
            ['patient_name' => 'مریم', 'secret_extra' => 'x'],
            self::ALLOWED,
            ['patient_name']
        );

        $this->assertSame('مریم', $out);
    }
}

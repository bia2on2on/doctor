<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Sms\SmsEvents;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0025 §10/§12 — Registry رویدادهای SMS + قوانین متغیرها.
 */
final class SmsEventsTest extends TestCase
{
    public function testAllRequiredEventsExist(): void
    {
        foreach (['otp', 'appointment_confirmed', 'appointment_reminder', 'appointment_cancelled', 'appointment_rescheduled', 'follow_up_reminder'] as $id) {
            $this->assertTrue(SmsEvents::exists($id), "رویداد {$id} وجود ندارد");
        }
    }

    public function testOtpEventUsesOnlyOtpCodeVariable(): void
    {
        $info = SmsEvents::info('otp');

        $this->assertNotNull($info);
        $this->assertSame(['otp_code'], $info['variables']);
        $this->assertSame(['otp_code'], $info['required']);
        $this->assertStringContainsString('{{otp_code}}', $info['default_text']);
    }

    public function testAppointmentEventsHaveRequiredVariables(): void
    {
        foreach (['appointment_confirmed', 'appointment_reminder', 'appointment_cancelled', 'appointment_rescheduled', 'follow_up_reminder'] as $id) {
            $info = SmsEvents::info($id);
            $this->assertNotNull($info);
            foreach (['patient_name', 'appointment_date', 'appointment_time'] as $required) {
                $this->assertContains($required, $info['required'], "{$id} — missing required {$required}");
                $this->assertContains($required, $info['variables'], "{$id} — {$required} not allowed");
            }
        }
    }

    public function testUnknownEvent(): void
    {
        $this->assertNull(SmsEvents::info('does_not_exist'));
        $this->assertFalse(SmsEvents::exists('does_not_exist'));
    }

    public function testAllVariablesAreKnown(): void
    {
        foreach (SmsEvents::all() as $info) {
            foreach ($info['variables'] as $v) {
                $this->assertArrayHasKey($v, SmsEvents::VARIABLES, "متغیر {$v} در جدول متغیرها نیست");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Clinical\DoctorDisplayName;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Package E — the ONE doctor-title normalizer (PHP side) and the SMS text
 * boundary that must never emit a doubled title.
 *
 * The JS equivalent (`assets/js/cpms-ui-text.js`) is exercised by
 * tests/js/pkg-e-texts.spec.js with the exact same expectations.
 */
final class DoctorDisplayNameTest extends TestCase
{
    public function testTitleIsAddedWhenTheStoredNameHasNone(): void
    {
        self::assertSame('دکتر رضایی', DoctorDisplayName::withTitle('رضایی'));
        self::assertSame('دکتر سارا کریمی', DoctorDisplayName::withTitle('سارا کریمی'));
    }

    public function testTitleIsNeverDoubledForAnyAcceptedPrefixForm(): void
    {
        self::assertSame('دکتر رضایی', DoctorDisplayName::withTitle('دکتر رضایی'));
        self::assertSame('دکتر رضایی', DoctorDisplayName::withTitle('دکتر. رضایی'));
        self::assertSame('دکتر رضایی', DoctorDisplayName::withTitle('دکتر .رضایی'));
        self::assertSame('دکتر رضایی', DoctorDisplayName::withTitle("دکتر\u{200C}رضایی"));
        self::assertSame('دکتر رضایی', DoctorDisplayName::withTitle('  دکتر   رضایی  '));
        self::assertSame('دکتر', DoctorDisplayName::withTitle('دکتر'));
        self::assertSame('دکتر', DoctorDisplayName::withTitle("دکتر.\u{200C} "));
    }

    public function testEmptyNameStaysEmptySoTheCallerOwnsItsFallback(): void
    {
        self::assertSame('', DoctorDisplayName::withTitle(''));
        self::assertSame('', DoctorDisplayName::withTitle('   '));
    }

    public function testDoubledTitleInsideRenderedTextCollapses(): void
    {
        self::assertSame('با دکتر رضایی', DoctorDisplayName::collapseDoubledTitle('با دکتر دکتر رضایی'));
        self::assertSame('دکتر رضایی', DoctorDisplayName::collapseDoubledTitle('دکتر  دکتر رضایی'));
        self::assertSame('دکتر رضایی', DoctorDisplayName::collapseDoubledTitle("دکتر\u{200C}دکتر رضایی"));
        self::assertSame('با دکتر رضایی', DoctorDisplayName::collapseDoubledTitle('با دکتر رضایی'));
    }

    public function testDefaultSmsTemplatesDoNotHardcodeTheTitle(): void
    {
        foreach (SmsEvents::all() as $event => $definition) {
            $text = (string) $definition['default_text'];
            self::assertStringNotContainsString(
                'دکتر {{doctor_name}}',
                $text,
                "قالب پیش‌فرض {$event} نباید عنوان را تکراری بسازد"
            );
        }
    }

    /**
     * Regression for the doubled-title defect: a template saved BEFORE this
     * change already contains «دکتر {{doctor_name}}», while the payload now
     * carries the title — the rendered output must still have ONE title.
     */
    public function testStoredTemplateWithLiteralTitleRendersOneTitle(): void
    {
        $rendered = SmsTemplateRenderer::render(
            'سلام {{patient_name}}، نوبت شما با دکتر {{doctor_name}} در {{appointment_date}} تأیید شد.',
            [
                'patient_name'     => 'سارا',
                'doctor_name'      => DoctorDisplayName::withTitle('رضایی'),
                'appointment_date' => '1405/08/10',
            ],
            ['patient_name', 'doctor_name', 'appointment_date'],
            ['patient_name', 'doctor_name', 'appointment_date']
        );

        self::assertStringContainsString('با دکتر رضایی', $rendered);
        self::assertStringNotContainsString('دکتر دکتر', $rendered);
    }

    public function testCurrentDefaultTemplateRendersOneTitle(): void
    {
        $definition = SmsEvents::info(SmsEvents::APPT_CONFIRMED);
        self::assertNotNull($definition);

        $rendered = SmsTemplateRenderer::render(
            (string) $definition['default_text'],
            [
                'patient_name'     => 'سارا',
                'doctor_name'      => DoctorDisplayName::withTitle('رضایی'),
                'appointment_date' => '1405/08/10',
                'appointment_time' => '09:30',
                'clinic_name'      => 'مطب نمونه',
            ],
            $definition['variables'],
            $definition['required']
        );

        self::assertStringContainsString('با دکتر رضایی', $rendered);
        self::assertStringNotContainsString('دکتر دکتر', $rendered);
    }
}

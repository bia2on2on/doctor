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
 * The rule is owner decision D1: a leading title is recognized ONLY as a whole
 * token — the word «دکتر» (Persian kaf U+06A9, or Arabic kaf U+0643 for
 * comparison) or Latin "Dr"/"Dr." (case-insensitive) — followed by end-of-string
 * or a separator (ASCII whitespace, NBSP, ZWNJ, dot). A word that merely starts
 * with those letters is a NAME and stays intact. Nothing inside the remaining
 * name is rewritten.
 *
 * The expectations live in ONE shared fixture that the JS spec reads too, so
 * PHP/JS parity is checked from a single source:
 *   tests/fixtures/doctor-display-name-vectors.json
 */
final class DoctorDisplayNameTest extends TestCase
{
    private const FIXTURE_REL = 'tests/fixtures/doctor-display-name-vectors.json';

    /**
     * @return array{title: string, vectors: list<array{id: string, input: ?string, expected: string, note: string}>}
     */
    private static function fixture(): array
    {
        $path = \dirname(__DIR__, 2) . '/' . self::FIXTURE_REL;
        self::assertFileExists($path, 'the shared doctor-title fixture must exist');

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'the fixture must decode to an array');
        self::assertArrayHasKey('vectors', $decoded);

        return $decoded;
    }

    public function testSharedFixtureVectorsMatchThePhpHelper(): void
    {
        $fixture = self::fixture();
        self::assertNotEmpty($fixture['vectors'], 'the fixture must carry vectors');

        $seen = [];
        foreach ($fixture['vectors'] as $vector) {
            $id  = (string) $vector['id'];
            $in  = $vector['input'];
            $exp = (string) $vector['expected'];

            self::assertArrayNotHasKey($id, $seen, "duplicate fixture id: {$id}");
            $seen[$id] = true;

            self::assertSame(
                $exp,
                DoctorDisplayName::with_title(is_string($in) ? $in : null),
                "vector ‘{$id}’ ({$vector['note']})"
            );
        }
    }

    public function testNullAndBlankAreAcceptedAndCollapseToEmpty(): void
    {
        // D1: with_title accepts ?string and returns '' for null/blank.
        self::assertSame('', DoctorDisplayName::with_title(null));
        self::assertSame('', DoctorDisplayName::with_title(''));
        self::assertSame('', DoctorDisplayName::with_title("   \t "));
        self::assertSame('', DoctorDisplayName::with_title("\u{00A0}"));
    }

    /**
     * The trim/collapse class must be EXACTLY ASCII whitespace + NBSP in both
     * languages (owner rule): everything else is neither trimmed nor rewritten.
     */
    public function testTrimClassIsAsciiWhitespacePlusNbspOnly(): void
    {
        $name = 'رضایی';

        foreach ([" ", "\t", "\n", "\r", "\f", "\v", "\u{00A0}"] as $ws) {
            self::assertSame(
                'دکتر ' . $name,
                DoctorDisplayName::with_title($ws . $name . $ws),
                'edge whitespace must be trimmed: ' . bin2hex($ws)
            );
        }

        foreach (["\u{2009}", "\u{3000}", "\u{FEFF}", "\u{200C}"] as $other) {
            self::assertSame(
                'دکتر ' . $other . $name,
                DoctorDisplayName::with_title($other . $name),
                'must NOT be trimmed: ' . bin2hex($other)
            );
            self::assertSame(
                'دکتر ' . $name . $other,
                DoctorDisplayName::with_title($name . $other),
                'must NOT be trimmed: ' . bin2hex($other)
            );
        }
    }

    /**
     * Fail-closed: an invalid-UTF-8 name must not be trimmed, rewritten or
     * passed through — every preg_* call with a /u pattern returns null, so the
     * helper returns '' and the caller supplies its own fallback.
     *
     * PHP-only: there is no JS equivalent because JS strings are UTF-16 and
     * cannot hold invalid bytes.
     */
    public function testInvalidUtf8FailsClosedInsteadOfMutating(): void
    {
        // preg_* emits a warning for malformed UTF-8; the outcome is the point here.
        $previous = set_error_handler(static fn (): bool => true);
        try {
            self::assertSame('', DoctorDisplayName::with_title("رضایی\xFF"));
            self::assertSame('', DoctorDisplayName::with_title("\xFF\xFE"));
            self::assertFalse(DoctorDisplayName::starts_with_title("\xFF"));
        } finally {
            set_error_handler($previous);
        }
    }

    public function testStartsWithTitleUsesTheSameWholeTokenRule(): void
    {
        $fixture = self::fixture();
        foreach ($fixture['vectors'] as $vector) {
            $in = $vector['input'];
            self::assertSame(
                (bool) $vector['had_title'],
                DoctorDisplayName::starts_with_title(is_string($in) ? $in : null),
                "starts_with_title disagrees with vector ‘{$vector['id']}’"
            );
        }
    }

    public function testDoubledTitleInsideRenderedTextCollapsesButNamesStayIntact(): void
    {
        $suffix = ' رضایی';
        self::assertSame(
            'با ' . DoctorDisplayName::TITLE . $suffix,
            DoctorDisplayName::collapse_doubled_title('با دکتر دکتر' . $suffix)
        );
        self::assertSame(
            DoctorDisplayName::TITLE . ' رضایی',
            DoctorDisplayName::collapse_doubled_title("دکتر\u{200C}دکتر رضایی")
        );
        // A WORD that merely starts with the title letters must never be eaten.
        self::assertSame(
            'دکتر دکتری',
            DoctorDisplayName::collapse_doubled_title('دکتر دکتری')
        );
        self::assertSame(
            DoctorDisplayName::TITLE . ' دکترزاده',
            DoctorDisplayName::collapse_doubled_title('دکتر دکترزاده')
        );
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
                'doctor_name'      => DoctorDisplayName::with_title('رضایی'),
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
                'doctor_name'      => DoctorDisplayName::with_title('رضایی'),
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

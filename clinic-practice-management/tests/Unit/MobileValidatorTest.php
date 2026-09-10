<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Validators\MobileValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobileValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'domestic 09' => ['09121234567', '09121234567'],
            'without leading zero' => ['9121234567', '09121234567'],
            'plus country code' => ['+989121234567', '09121234567'],
            '00 country code' => ['00989121234567', '09121234567'],
            'with spaces' => ['0912 123 4567', '09121234567'],
            'with dashes' => ['0912-123-4567', '09121234567'],
            // C5 — فرم‌های پیشوند 98 با صفرِ میان‌شهری
            '98 bare' => ['989121234567', '09121234567'],
            '98 with trunk zero' => ['9809121234567', '09121234567'],
            '98 with trunk zero and plus' => ['+9809121234567', '09121234567'],
            '0098 with trunk zero' => ['009809121234567', '09121234567'],
            // C5 — ارقام یونیکد
            'persian digits domestic' => ['۰۹۱۲۱۲۳۴۵۶۷', '09121234567'],
            'persian digits with plus 98' => ['+۹۸۹۱۲۱۲۳۴۵۶۷', '09121234567'],
            'persian digits with spaces' => ['۰۹۱۲ ۱۲۳ ۴۵۶۷', '09121234567'],
            'arabic-indic digits domestic' => ['٠٩١٢١٢٣٤٥٦٧', '09121234567'],
            'arabic-indic digits 0098' => ['٠٠٩٨٩١٢١٢٣٤٥٦٧', '09121234567'],
            'mixed latin and persian digits' => ['0912۱۲۳4567', '09121234567'],
            // C5 — رد ورودی‌های نامعتبر
            'too short' => ['0912123456', null],
            'too long' => ['091212345678', null],
            'invalid prefix' => ['08121234567', null],
            'landline tehran rejected' => ['02112345678', null],
            'empty' => ['', null],
            'letters' => ['0912abcdefgh', null],
            'persian letters are not digits' => ['۰۹۱۲الفالف', null],
            // رفتارِ قبلاً معیوب: ۱۲ رقمِ «9809...» خروجی خراب «00...» می‌داد؛
            // حالا ورودی نامعتبر است (فرم معتبرِ 98+صفرِ میان‌شهری ۱۳ رقم است).
            'legacy garbage 9809 twelve digits now rejected' => ['980912345678', null],
        ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, MobileValidator::normalize($input));
    }

    public function testIsValidMatchesNormalize(): void
    {
        $this->assertTrue(MobileValidator::isValid('+989121234567'));
        $this->assertFalse(MobileValidator::isValid('abc'));
    }

    public function testMask(): void
    {
        $this->assertSame('0912***4567', MobileValidator::mask('09121234567'));
        $this->assertSame('***', MobileValidator::mask('123'));
    }
}

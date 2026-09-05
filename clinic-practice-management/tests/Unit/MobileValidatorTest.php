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
            'too short' => ['0912123456', null],
            'too long' => ['091212345678', null],
            'invalid prefix' => ['08121234567', null],
            'empty' => ['', null],
            'letters' => ['0912abcdefgh', null],
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

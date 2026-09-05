<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Validators\NationalIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NationalIdValidatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function validProvider(): array
    {
        return [
            // weights: pos0..pos8 = 10,9,8,7,6,5,4,3,2
            'valid rem 0 (sum=11)' => ['0000000140', true],
            'valid rem 1 (sum=12)' => ['0000000061', true],
            'valid rem 9 (sum=9)' => ['0000000302', true],
            'valid rem 7 (sum=7)' => ['0001000004', true],
            'invalid rem 7 (check 3)' => ['0001000003', false],
            'invalid rem 0 (check 1)' => ['0000000141', false],
            '9-digit' => ['123456789', false],
            '11-digit' => ['12345678901', false],
            'all same digits' => ['1111111111', false],
            'letters inside' => ['12a4567893', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('validProvider')]
    public function testIsValid(string $input, bool $expected): void
    {
        $this->assertSame($expected, NationalIdValidator::isValid($input));
    }

    public function testMask(): void
    {
        $this->assertSame('***5673', NationalIdValidator::mask('0012345673'));
        $this->assertSame('***', NationalIdValidator::mask('12'));
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Finance\MoneyInput;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Iran V1 — integer Rial input validation (no fractions, no toman conversion).
 */
final class MoneyInputTest extends TestCase
{
    public function testIntegerRialFormsAreAccepted(): void
    {
        self::assertSame(250000, MoneyInput::amount(250000));
        self::assertSame(250000, MoneyInput::amount('250000'));
        self::assertSame(250000, MoneyInput::amount(' 250000 '));
        self::assertSame(250000, MoneyInput::amount(250000.0));
        self::assertSame(0, MoneyInput::amount(0));
    }

    public function testFractionsNegativesAndForeignAmountsAreRejected(): void
    {
        $invalid = [
            '100.5', 100.5, -1, '۱۰۰', [], null, MoneyInput::MAX_AMOUNT + 1, NAN,
        ];
        foreach ($invalid as $value) {
            try {
                MoneyInput::amount($value);
                self::fail('Expected an invalid-amount rejection');
            } catch (DomainException $exception) {
                self::assertSame('CLINIC_AMOUNT_INVALID', $exception->getMessage());
            }
        }
    }

    public function testZeroIsRejectedForAChargeButAllowedForAFee(): void
    {
        self::assertSame(0, MoneyInput::amount(0));
        $this->expectException(DomainException::class);
        MoneyInput::amount(0, true);
    }

    public function testQuantitySupportsTwoDecimalsWithinBounds(): void
    {
        self::assertSame(1.0, MoneyInput::quantity(1));
        self::assertSame(2.5, MoneyInput::quantity('2.5'));
        self::assertSame(100.0, MoneyInput::quantity(100));
        foreach ([0, -1, 101, 1.234, 'abc', NAN] as $invalid) {
            try {
                MoneyInput::quantity($invalid);
                self::fail('Expected an invalid-quantity rejection');
            } catch (DomainException $exception) {
                self::assertSame('CLINIC_QUANTITY_INVALID', $exception->getMessage());
            }
        }
    }

    public function testDescriptionIsTagFreeAndLengthBounded(): void
    {
        self::assertSame('پانسمان', MoneyInput::text('<b>پانسمان</b>', 255));
        self::assertSame('', MoneyInput::text(null, 500, false));
        $this->expectException(DomainException::class);
        MoneyInput::text(str_repeat('خ', 256), 255);
    }
}

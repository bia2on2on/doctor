<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Finance;

use DomainException;

/**
 * Integer-Rial input validation for doctor-managed pricing — pure PHP.
 *
 * The project stores money as DECIMAL(12,2) but computes in integer "minor"
 * units (in IRR: Rial). Fractions, negative values and non-integral numbers
 * are rejected so no rial/toman conversion ambiguity can enter the ledger.
 */
final class MoneyInput
{
    /** Per-unit cap, comfortably below DECIMAL(12,2) limits. */
    public const MAX_AMOUNT = 999999999;

    /** Sum cap for the whole invoice (DECIMAL(12,2) headroom). */
    public const MAX_TOTAL = 9999999999;

    public const MAX_QUANTITY = 100.0;

    private function __construct()
    {
    }

    /**
     * Non-negative integer Rial amount.
     *
     * @throws DomainException
     */
    public static function amount(mixed $value, bool $positive = false): int
    {
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_float($value) && is_finite($value) && $value === floor($value)) {
            $amount = (int) $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/', trim($value)) === 1) {
            $amount = (int) trim($value);
        } else {
            throw new DomainException('CLINIC_AMOUNT_INVALID');
        }

        if ($amount < 0 || ($positive && $amount === 0) || $amount > self::MAX_AMOUNT) {
            throw new DomainException('CLINIC_AMOUNT_INVALID');
        }

        return $amount;
    }

    /**
     * Quantity supported by cpms_invoice_items.quantity DECIMAL(8,2).
     *
     * @throws DomainException
     */
    public static function quantity(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            $quantity = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $quantity = (float) trim($value);
        } else {
            throw new DomainException('CLINIC_QUANTITY_INVALID');
        }

        if (!is_finite($quantity) || $quantity <= 0 || $quantity > self::MAX_QUANTITY) {
            throw new DomainException('CLINIC_QUANTITY_INVALID');
        }
        if (round($quantity, 2) !== $quantity) {
            throw new DomainException('CLINIC_QUANTITY_INVALID');
        }

        return $quantity;
    }

    /**
     * Plain-text, tag-free, length-bounded service description or note.
     *
     * @throws DomainException
     */
    public static function text(mixed $value, int $max, bool $required = true): string
    {
        if (!is_string($value)) {
            if (!$required) {
                return '';
            }
            throw new DomainException('CLINIC_TEXT_INVALID');
        }
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $value) ?? '';
        $text = trim(strip_tags($text));
        if (($required && $text === '') || mb_strlen($text, 'UTF-8') > $max) {
            throw new DomainException('CLINIC_TEXT_INVALID');
        }

        return $text;
    }
}

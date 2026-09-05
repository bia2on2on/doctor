<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Finance;

use DomainException;

/**
 * محاسبات مالی فاکتور/پرداخت — خالص.
 *
 * همه اعداد «واحد صغیر» (int) هستند — در IRR همان ریال؛ در DB DECIMAL(12,2).
 * قوانین سفت: M-1..M-7 (docs/state-machines/payment.md).
 */
final class InvoiceCalc
{
    public const CODE_INVALID_ITEM = 1;
    public const CODE_INVALID_AMOUNT = 2;
    public const CODE_OVERPAYMENT = 3;

    /**
     * محاسبه جمع فاکتور هنگام صدور.
     *
     * @param list<array{quantity: float, unit_price: int, discount?: int}> $items
     *        discount در item: واحد صغیر از مبلغ آیتم
     *
     * @return array{subtotal: int, discount: int, tax: int, total: int}
     *
     * @throws DomainException
     */
    public static function issueTotals(array $items, int $invoiceDiscount, int $tax): array
    {
        if ($items === []) {
            throw new DomainException('INVOICE_EMPTY: فاکتور بدون آیتم', self::CODE_INVALID_ITEM);
        }
        if ($invoiceDiscount < 0 || $tax < 0) {
            throw new DomainException('مقدار منفی', self::CODE_INVALID_AMOUNT);
        }

        $subtotal = 0;
        $itemDiscount = 0;
        foreach ($items as $item) {
            $qty = $item['quantity'];
            $price = $item['unit_price'];
            if ($qty <= 0 || $price < 0) {
                throw new DomainException('CLINIC_INVOICE_ITEM_INVALID', self::CODE_INVALID_ITEM);
            }
            $amount = (int) round($qty * $price);
            $disc = (int) ($item['discount'] ?? 0);
            if ($disc < 0 || $disc > $amount) {
                throw new DomainException('CLINIC_ITEM_DISCOUNT_INVALID', self::CODE_INVALID_AMOUNT);
            }
            $subtotal += $amount;
            $itemDiscount += $disc;
        }

        $subtotal -= $itemDiscount;
        $discount = $itemDiscount + $invoiceDiscount;
        if ($discount > $subtotal) {
            throw new DomainException('CLINIC_DISCOUNT_EXCEEDS', self::CODE_INVALID_AMOUNT);
        }

        $base = $subtotal - $invoiceDiscount;
        $taxAmount = $tax;
        $total = $base + $taxAmount;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $taxAmount,
            'total' => $total,
        ];
    }

    /**
     * اعمال پرداخت روی فاکتور.
     *
     * @return array{paid: int, balance: int, event: string} event = pay_partial|pay_full
     *
     * @throws DomainException CLINIC_OVERPAYMENT / INVALID_AMOUNT
     */
    public static function applyPayment(int $total, int $paid, int $amount): array
    {
        if ($amount <= 0) {
            throw new DomainException('CLINIC_PAYMENT_AMOUNT_INVALID', self::CODE_INVALID_AMOUNT);
        }
        $balance = $total - $paid;
        if ($amount > $balance) {
            throw new DomainException(
                sprintf('CLINIC_OVERPAYMENT: amount=%d balance=%d', $amount, $balance),
                self::CODE_OVERPAYMENT
            );
        }

        $newPaid = $paid + $amount;

        return [
            'paid' => $newPaid,
            'balance' => $total - $newPaid,
            'event' => $newPaid >= $total ? 'pay_full' : 'pay_partial',
        ];
    }

    /**
     * اعمال اصلاح (Adjustment): credit = کسر از بدهی، debit = افزایش بدهی.
     *
     * @return array{balance: int}
     *
     * @throws DomainException
     */
    public static function applyAdjustment(int $total, int $paid, int $balance, string $type, int $amount): array
    {
        if (!in_array($type, ['credit', 'debit'], true) || $amount <= 0) {
            throw new DomainException('CLINIC_ADJUSTMENT_INVALID', self::CODE_INVALID_AMOUNT);
        }

        $newBalance = $type === 'credit' ? $balance - $amount : $balance + $amount;
        if ($newBalance < 0) {
            throw new DomainException('CLINIC_ADJUSTMENT_EXCEEDS', self::CODE_INVALID_AMOUNT);
        }

        // روی فاکتور paid فقط credit تا صفر (سود بیمار) مجاز است — در لایه Application کنترل می‌شود
        return ['balance' => $newBalance];
    }
}

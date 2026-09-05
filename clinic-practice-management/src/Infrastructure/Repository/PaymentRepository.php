<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository پرداخت‌ها (cpms_payments) — D13/D14/P3.
 *
 * M-1: UNIQUE(invoice_id, idempotency_key) — Idempotency در سطح خود جدول؛
 * M-2: payment_number یکتا به‌فرمت PAY-YYMMDD-NNNN؛ کلید عددگیری = قفل
 * ردیف کلینیک در Service.
 */
final class PaymentRepository
{
    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $row += [
            'clinic_id' => 1,
            'transaction_ref' => null,
            'status' => 'captured',
            'refunded_amount' => 0,
            'void_reason' => null,
            'voided_at' => null,
            'voided_by_wp_user_id' => null,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $this->db->insert('cpms_payments', $row);

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_payments') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_payments') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * Idempotent lookup — M-1 (تکرار کلید روی همان فاکتور = همان پرداخت).
     *
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(int $invoiceId, string $key): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_payments') .
            ' WHERE invoice_id = %d AND idempotency_key = %s LIMIT 1',
            [$invoiceId, $key]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->db->update('cpms_payments', $data, ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forInvoice(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_payments') .
            ' WHERE invoice_id = %d ORDER BY id ASC',
            [$invoiceId]
        ) ?: [];
    }

    public function nextPaymentNumber(): string
    {
        $prefix = 'PAY-' . gmdate('ymd') . '-';
        $max = $this->db->fetchValue(
            'SELECT MAX(payment_number) FROM ' . $this->db->table('cpms_payments') .
            " WHERE clinic_id = %d AND payment_number LIKE %s",
            [1, $prefix . '%']
        );

        $seq = 0;
        if (is_string($max) && preg_match('/^PAY-\d{6}-(\d{4,})$/', $max, $m) === 1) {
            $seq = (int) $m[1];
        }

        return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * خلاصه درآمد بازه — D18: captured (منهای refunded)؛ voided کلاً حذف.
     *
     * @return array{total: float, by_method: array<string, float>, refunded: float, count: int}
     */
    public function revenueSummary(string $fromDate, string $toDate): array
    {
        $rows = $this->db->fetchAll(
            'SELECT method, amount, refunded_amount FROM ' . $this->db->table('cpms_payments') .
            " WHERE clinic_id = %d AND status IN ('captured', 'refunded')" .
            ' AND paid_at >= %s AND paid_at < %s',
            [1, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999']
        ) ?: [];

        $byMethod = ['cash' => 0.0, 'card_pos' => 0.0, 'online' => 0.0, 'other' => 0.0];
        $total = 0.0;
        $refunded = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $net = (float) $r['amount'] - (float) $r['refunded_amount'];
            $method = (string) $r['method'];
            if (!isset($byMethod[$method])) {
                $byMethod[$method] = 0.0;
            }
            $byMethod[$method] += $net;
            $total += $net;
            $refunded += (float) $r['refunded_amount'];
            $count++;
        }

        return ['total' => $total, 'by_method' => $byMethod, 'refunded' => $refunded, 'count' => $count];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forRange(string $fromDate, string $toDate, int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT pay.*, inv.invoice_number FROM ' . $this->db->table('cpms_payments') . ' pay' .
            ' JOIN ' . $this->db->table('cpms_invoices') . ' inv ON inv.id = pay.invoice_id' .
            ' WHERE pay.clinic_id = %d' .
            ' AND pay.paid_at >= %s AND pay.paid_at < %s' .
            ' ORDER BY pay.id DESC LIMIT %d',
            [1, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999', $limit]
        ) ?: [];
    }
}

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
     *
     * @throws \RuntimeException وقتی درج پرداخت ناموفق است (C6 corrective)
     */
    public function insert(int $clinic_id, array $row): int
    {
        $row += [
            'clinic_id' => $clinic_id,
            'transaction_ref' => null,
            'status' => 'captured',
            'refunded_amount' => 0,
            'void_reason' => null,
            'voided_at' => null,
            'voided_by_wp_user_id' => null,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $ok = $this->db->insert('cpms_payments', $row);
        $id = $this->db->wpdb_last_insert_id();

        /*
         * C6 corrective — پیش از این فراخواننده `!$ok` را بررسی می‌کرد، ولی
         * `$ok` همان insert_id بود و wpdb در خطا insert_id قدیمی را نگه
         * می‌دارد؛ پس فقط حالت «صفر» گرفته می‌شد و شناسهٔ stale عبور می‌کرد.
         * حالا شکست درج همیشه استثنا می‌دهد و تشخیص Idempotency-race در
         * FinanceService با catch همان استثنا انجام می‌شود.
         */
        if (!$ok || $id <= 0) {
            throw new \RuntimeException('cpms_payments insert failed');
        }

        return $id;
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

    /**
     * شماره بعدی پرداخت — per Clinic (قفل ردیف همان Clinic در Service).
     *
     * C6 corrective: Clinic الزامی است؛ عددگیری هرگز با literal 1 انجام نمی‌شود.
     */
    public function nextPaymentNumber(int $clinic_id): string
    {
        $prefix = 'PAY-' . gmdate('ymd') . '-';
        $max = $this->db->fetchValue(
            'SELECT MAX(payment_number) FROM ' . $this->db->table('cpms_payments') .
            " WHERE clinic_id = %d AND payment_number LIKE %s",
            [$clinic_id, $prefix . '%']
        );

        $seq = 0;
        if (is_string($max) && preg_match('/^PAY-\d{6}-(\d{4,})$/', $max, $m) === 1) {
            $seq = (int) $m[1];
        }

        return $prefix . str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * خلاصه درآمد بازهٔ یک Clinic — D18: captured (منهای refunded)؛ voided کلاً حذف.
     *
     * C6 corrective: Clinic الزامی است؛ پیش از این با literal 1 bind می‌شد.
     *
     * @return array{total: float, by_method: array<string, float>, refunded: float, count: int}
     */
    public function revenueSummary(int $clinic_id, string $fromDate, string $toDate): array
    {
        $rows = $this->db->fetchAll(
            'SELECT method, amount, refunded_amount FROM ' . $this->db->table('cpms_payments') .
            " WHERE clinic_id = %d AND status IN ('captured', 'refunded')" .
            ' AND paid_at >= %s AND paid_at < %s',
            [$clinic_id, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999']
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
     * پرداخت‌های بازهٔ یک Clinic — D18.
     *
     * C6 corrective: Clinic الزامی است؛ پیش از این با literal 1 bind می‌شد.
     *
     * @return list<array<string, mixed>>
     */
    public function forRange(
        int $clinic_id,
        string $fromDate,
        string $toDate,
        int $limit = 200
    ): array {
        return $this->db->fetchAll(
            'SELECT pay.*, inv.invoice_number FROM ' . $this->db->table('cpms_payments') . ' pay' .
            ' JOIN ' . $this->db->table('cpms_invoices') . ' inv ON inv.id = pay.invoice_id' .
            ' WHERE pay.clinic_id = %d' .
            ' AND pay.paid_at >= %s AND pay.paid_at < %s' .
            ' ORDER BY pay.id DESC LIMIT %d',
            [$clinic_id, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999', $limit]
        ) ?: [];
    }
}

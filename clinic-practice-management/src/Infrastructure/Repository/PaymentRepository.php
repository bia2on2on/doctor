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

    /**
     * شماره بعدی پرداخت — مستقل برای هر Clinic (کلید یکتایی per-Clinic).
     */
    public function nextPaymentNumber(int $clinicId): string
    {
        $prefix = 'PAY-' . gmdate('ymd') . '-';
        $max = $this->db->fetchValue(
            'SELECT MAX(payment_number) FROM ' . $this->db->table('cpms_payments') .
            " WHERE clinic_id = %d AND payment_number LIKE %s",
            [$clinicId, $prefix . '%']
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
    public function revenueSummary(int $clinicId, string $fromDate, string $toDate): array
    {
        $rows = $this->db->fetchAll(
            'SELECT method, amount, refunded_amount FROM ' . $this->db->table('cpms_payments') .
            " WHERE clinic_id = %d AND status IN ('captured', 'refunded')" .
            ' AND paid_at >= %s AND paid_at < %s',
            [$clinicId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999']
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
     * 2026-10-15 — مالکیت پایدار تراکنش (سیاست مجوز مالی).
     *
     * پزشکِ مسئولِ سرویسِ همین پرداخت، از زنجیرهٔ پایدار
     * payment → invoice → visit → clinician و پروفایل بالینیِ متصل به کاربر
     * (`clinicians.wp_user_id`، یکتا با u_clinician_user).
     *
     *  - همهٔ ردیف‌های زنجیره باید به همان Clinic تعلق داشته باشند؛
     *  - پزشکِ غیرفعال یا بدون کاربرِ متصل ⇒ null (fail-closed)؛
     *  - هرگز از payload/درخواست/نقش سراسری WordPress خوانده نمی‌شود.
     *
     * @return int|null wp_user_id پزشکِ مسئول، یا null وقتی مالکیت اثبات‌پذیر نیست.
     */
    public function owning_clinician_wp_user_id( int $payment_id, int $clinic_id ): ?int {
        if ( $payment_id <= 0 || $clinic_id <= 0 ) {
            return null;
        }

        $value = $this->db->fetchValue(
            'SELECT cli.wp_user_id FROM ' . $this->db->table( 'cpms_payments' ) . ' pay' .
            ' INNER JOIN ' . $this->db->table( 'cpms_invoices' ) . ' inv ON inv.id = pay.invoice_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_visits' ) . ' vis ON vis.id = inv.visit_id' .
            ' INNER JOIN ' . $this->db->table( 'cpms_clinicians' ) . ' cli ON cli.id = vis.clinician_id' .
            ' WHERE pay.id = %d AND pay.clinic_id = %d AND inv.clinic_id = %d AND vis.clinic_id = %d' .
            ' AND cli.is_active = 1 AND cli.wp_user_id IS NOT NULL LIMIT 1',
            [ $payment_id, $clinic_id, $clinic_id, $clinic_id ]
        );

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forRange(int $clinicId, string $fromDate, string $toDate, int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT pay.*, inv.invoice_number FROM ' . $this->db->table('cpms_payments') . ' pay' .
            ' JOIN ' . $this->db->table('cpms_invoices') . ' inv ON inv.id = pay.invoice_id' .
            ' WHERE pay.clinic_id = %d' .
            ' AND pay.paid_at >= %s AND pay.paid_at < %s' .
            ' ORDER BY pay.id DESC LIMIT %d',
            [$clinicId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59.999', $limit]
        ) ?: [];
    }
}

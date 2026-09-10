<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository فاکتورها + اقلام + اصلاحات (cpms_invoices / cpms_invoice_items /
 * cpms_payment_adjustments) — D12/D15/D17.
 *
 * فقط Data-Access (ADR-0021). مبلغ‌ها DECIMAL(12,2) — محاسبات در Service
 * با integer-cents انجام می‌شود (TP-18).
 */
final class InvoiceRepository
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
            'status' => 'open',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'currency' => 'IRR',
            'paid_amount' => 0,
            'balance' => 0,
            'void_reason' => null,
            'voided_at' => null,
            'created_at' => $this->db->nowUtcSql(),
            'updated_at' => $this->db->nowUtcSql(),
        ];
        $ok = $this->db->insert('cpms_invoices', $row);
        $id = $this->db->wpdb_last_insert_id();

        /*
         * C6 corrective (insert-failure): `CpmsDb::$strict` در runtime خاموش است،
         * پس خطای SQL خاموش می‌ماند و `wpdb::insert` در خطا `false` می‌دهد درحالی‌که
         * `wpdb::$insert_id` مقدارِ insert موفقِ قبلی سرِ جایش می‌ماند. بدون این
         * گارد، فراخواننده با شناسهٔ بیگانه/صفر ادامه می‌داد، اقلام فاکتور به
         * فاکتور دیگری (احتمالاً از Clinic دیگر) چسبانده می‌شد و تراکنش هم
         * commit می‌شد.
         */
        if (!$ok || $id <= 0) {
            throw new \RuntimeException('cpms_invoices insert failed');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = $this->db->nowUtcSql();
        $this->db->update('cpms_invoices', $data, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_invoices') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchRowForUpdate(
            'SELECT * FROM ' . $this->db->table('cpms_invoices') . ' WHERE id = %d LIMIT 1',
            [$id]
        );
    }

    /**
     * فاکتور فعال (open/partial/paid — نه voided) ویزیت — I1/I4:
     * هر ویزیت حداکثر یک فاکتور غیرابطال‌شده.
     *
     * @return array<string, mixed>|null
     */
    public function activeForVisit(int $visitId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table('cpms_invoices') .
            " WHERE visit_id = %d AND status != 'voided' ORDER BY id DESC LIMIT 1",
            [$visitId]
        );
    }

    /**
     * بدهی فعال ویزیت برای گارد NOT_SETTLED در Checkout (V14).
     *
     * @return array{balance: float, count: int}
     */
    public function unsettledForVisit(int $visitId): array
    {
        $row = $this->db->fetchRow(
            'SELECT COUNT(*) AS n, COALESCE(SUM(balance), 0) AS bal FROM ' . $this->db->table('cpms_invoices') .
            " WHERE visit_id = %d AND status IN ('open', 'partial')",
            [$visitId]
        );

        return ['balance' => (float) ($row['bal'] ?? 0), 'count' => (int) ($row['n'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertItem(int $invoiceId, array $row): void
    {
        $row += [
            'invoice_id' => $invoiceId,
            'service_id' => null,
            'discount' => 0,
        ];
        // C6 corrective — قلمِ ثبت‌نشده نباید خاموش بماند: فاکتور بدون قلم با
        // مبالغ ناسازگار commit می‌شد.
        if (!$this->db->insert('cpms_invoice_items', $row)) {
            throw new \RuntimeException('cpms_invoice_items insert failed');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsFor(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_invoice_items') .
            ' WHERE invoice_id = %d ORDER BY id ASC',
            [$invoiceId]
        ) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insertAdjustment(array $row): int
    {
        $row += [
            'payment_id' => null,
            'created_at' => $this->db->nowUtcSql(),
        ];
        $ok = $this->db->insert('cpms_payment_adjustments', $row);
        $id = $this->db->wpdb_last_insert_id();
        // C6 corrective — همان گارد insert فاکتور: شناسهٔ stale/صفر هرگز به
        // Audit یا پاسخ برنمی‌گردد.
        if (!$ok || $id <= 0) {
            throw new \RuntimeException('cpms_payment_adjustments insert failed');
        }

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adjustmentsFor(int $invoiceId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_payment_adjustments') .
            ' WHERE invoice_id = %d ORDER BY id ASC',
            [$invoiceId]
        ) ?: [];
    }

    /**
     * @return array{credit: float, debit: float}
     */
    public function adjustmentTotals(int $invoiceId): array
    {
        $row = $this->db->fetchRow(
            'SELECT' .
            " COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credit," .
            " COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debit" .
            ' FROM ' . $this->db->table('cpms_payment_adjustments') .
            ' WHERE invoice_id = %d',
            [$invoiceId]
        );

        return ['credit' => (float) ($row['credit'] ?? 0), 'debit' => (float) ($row['debit'] ?? 0)];
    }

    /**
     * شماره بعدی فاکتور: INV-YYMMDD-NNN — per Clinic (قفل ردیف همان Clinic در
     * Service گرفته می‌شود).
     *
     * C6 corrective: Clinic الزامی است؛ عددگیری هرگز با literal `1` bind نمی‌شود،
     * پس شماره‌گذاری هر Clinic مستقل از بقیه پیش می‌رود.
     *
     * @param int $clinic_id Clinic مالک فاکتور — الزامی، بدون fallback
     */
    public function nextInvoiceNumber(int $clinic_id): string
    {
        $prefix = 'INV-' . gmdate('ymd') . '-';
        $max = $this->db->fetchValue(
            'SELECT MAX(invoice_number) FROM ' . $this->db->table('cpms_invoices') .
            " WHERE clinic_id = %d AND invoice_number LIKE %s",
            [$clinic_id, $prefix . '%']
        );

        $seq = 0;
        if (is_string($max) && preg_match('/^INV-\d{6}-(\d{3,})$/', $max, $m) === 1) {
            $seq = (int) $m[1];
        }

        return $prefix . str_pad((string) ($seq + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * فاکتورهای باز یک Clinic (بدهی‌های باز — FR-14.8).
     *
     * C6 corrective: Clinic الزامی است؛ پیش از این با literal `1` bind می‌شد و
     * نام و MRN بیمارِ Clinic دیگر را در خلاصهٔ مالی هر Clinic نشان می‌داد.
     *
     * @param int $clinic_id Clinic مالک فاکتورها — الزامی، بدون fallback
     *
     * @return list<array<string, mixed>>
     */
    public function openInvoices(int $clinic_id, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.first_name AS patient_first_name, p.last_name AS patient_last_name, p.mrn AS patient_mrn' .
            ' FROM ' . $this->db->table('cpms_invoices') . ' i' .
            ' JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = i.patient_id' .
            " WHERE i.clinic_id = %d AND i.status IN ('open', 'partial')" .
            ' ORDER BY i.id DESC LIMIT %d',
            [$clinic_id, $limit]
        ) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forVisit(int $visitId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ' . $this->db->table('cpms_invoices') .
            ' WHERE visit_id = %d ORDER BY id DESC',
            [$visitId]
        ) ?: [];
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Finance;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Finance\InvoiceCalc;
use ClinicCore\Domain\Machine\InvoiceMachine;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Infrastructure\Repository\ServiceRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DomainException;

/**
 * سرویس مالی (F6) — Invoice/Payment/Adjustment/Void/Refund + تعرفه‌ها.
 *
 * Scope (SRS §3.14/§3.15 + docs/state-machines/payment.md + API D12–D18/G2):
 *  - **Invoice ≠ Payment** (قانون سفت): فاکتور = بدهی؛ پرداخت = تراکنش Immutable.
 *  - P1/M-1: Idempotency روی خود جدول payments (UNIQUE(invoice_id,key))؛
 *    تکرار کلید = همان پاسخ 200 (TP-02) — نه خطا.
 *  - M-3 بیش‌پرداخت ممنوع → CLINIC_OVERPAYMENT 422؛ محاسبات همه integer
 *    (TP-18) در «واحد صغیر» = ریال (توافق با InvoiceCalc).
 *  - M-6 عمل روی فاکتور paid/voided → CLINIC_INVOICE_NOT_MODIFIABLE.
 *  - M-7 هر عمل مالی + Transition ویزیت (V11/V12 نقش system) در یک
 *    Transaction واحد با Row Lock روی فاکتور/ویزیت.
 *  - P2 ابطال پرداخت: فقط همان روز (UTC) + دلیل؛ رکورد حذف نمی‌شود.
 *  - P3 بازپرداخت: جزئی/کامل؛ refunded_amount روی همان رکورد.
 *  - Audit با اکشن‌های مرجع audit-strategy §2 (INVOICE_CREATE,
 *    PAYMENT_CAPTURE, PAYMENT_VOID, PAYMENT_REFUND, PAYMENT_ADJUST) و
 *    before/after مبلغی (M-4) — بدون PHI اضافی.
 *  - عددگیری سریال INV/PAY: قفل ردیف کلینیک → سریال per-clinic.
 *
 * Deviation (مستند در report-f6): بازگشت از paid (void/refund) وضعیت ویزیت
 * را برنمی‌گرداند — V12 یک‌طرفه است (لاگ + Open Item برای F7).
 */
final class FinanceService
{
    private const PAYMENT_METHODS = ['cash', 'card_pos', 'online', 'other'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ServiceRepository $services,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly VisitRepository $visits,
        private readonly VisitService $visitService,
        private readonly PatientRepository $patients,
        private readonly AuditLogger $audit
    ) {
    }

    // ================= G2 — تعرفه خدمات (cpms_config) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(int $actorUserId, bool $onlyActive = true): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::INVOICE_READ, 'services.read');

        return array_map(static fn (array $s): array => [
            'id' => (int) $s['id'],
            'code' => (string) $s['code'],
            'name' => (string) $s['name'],
            'price' => (float) $s['price'],
            'currency' => (string) $s['currency'],
            'is_active' => (int) $s['is_active'] === 1,
        ], $this->services->all($onlyActive));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createService(int $actorUserId, array $input): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::CONFIG, 'services.config');
        [$code, $name, $price] = $this->validateServiceInput($input, null);
        if ($this->services->existsWithCode($code)) {
            throw FinanceException::of('CLINIC_POLICY_VIOLATION', 'کد خدمت تکراری است', 409, ['code' => $code]);
        }
        $id = $this->services->insert([
            'code' => $code,
            'name' => $name,
            'price' => $price,
        ]);
        // SETTING_UPDATE — اکشن مرجع audit-strategy برای تغییرات Config
        $this->audit->log('SETTING_UPDATE', $this->actor($actorUserId), 'service', $id, null, null, [
            'code' => $code, 'name' => $name, 'price' => $price, 'is_active' => 1,
        ], ['op' => 'service_create']);

        return ['id' => $id, 'code' => $code, 'name' => $name, 'price' => $price, 'is_active' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateService(int $actorUserId, int $id, array $input): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::CONFIG, 'services.config');
        $existing = $this->services->find($id);
        if ($existing === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'خدمت یافت نشد', 404);
        }
        [$code, $name, $price] = $this->validateServiceInput($input, $existing);
        if ($this->services->existsWithCode($code, $id)) {
            throw FinanceException::of('CLINIC_POLICY_VIOLATION', 'کد خدمت تکراری است', 409, ['code' => $code]);
        }
        $this->services->update($id, ['code' => $code, 'name' => $name, 'price' => $price]);
        $this->audit->log('SETTING_UPDATE', $this->actor($actorUserId), 'service', $id, null, [
            'service.code' => (string) $existing['code'],
            'service.name' => (string) $existing['name'],
            'service.price' => (float) $existing['price'],
        ], [
            'service.code' => $code,
            'service.name' => $name,
            'service.price' => $price,
        ], ['op' => 'service_update']);

        return ['id' => $id, 'code' => $code, 'name' => $name, 'price' => $price];
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivateService(int $actorUserId, int $id): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::CONFIG, 'services.config');
        $existing = $this->services->find($id);
        if ($existing === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'خدمت یافت نشد', 404);
        }
        // حذف منطقی — اقلام فاکتور تاریخی باید به تعرفه ارجاع بدهند (FR-14.9)
        $this->services->update($id, ['is_active' => 0]);
        $this->audit->log('SETTING_UPDATE', $this->actor($actorUserId), 'service', $id, null, [
            'service.is_active' => 1,
        ], [
            'service.is_active' => 0,
        ], ['op' => 'service_deactivate']);

        return ['id' => $id, 'is_active' => false];
    }

    // ================= D12 — صدور فاکتور (I1) =================

    /**
     * @param array<string, mixed> $input {visit_id, items:[{service_id?, description, quantity|qty, unit_price|price, discount?}], discount?, tax?}
     * @return array<string, mixed>
     */
    public function issueInvoice(int $actorUserId, array $input): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::INVOICE_CREATE, 'invoice.issue');

        $visitId = (int) ($input['visit_id'] ?? 0);
        $itemsIn = $input['items'] ?? null;
        if ($visitId <= 0 || !is_array($itemsIn) || $itemsIn === []) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'visit_id و حداقل یک قلم فاکتور الزامی است', 422);
        }
        $discount = $this->toMinor($input['discount'] ?? 0);
        $tax = $this->toMinor($input['tax'] ?? 0);
        if ($discount === null || $discount < 0 || $tax === null || $tax < 0) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'تخفیف/مالیات باید عدد صحیح غیرمنفی باشد', 422);
        }

        return $this->db->transactional(function () use ($actorUserId, $visitId, $itemsIn, $discount, $tax): array {
            $visit = $this->visits->findForUpdate($visitId);
            if ($visit === null) {
                throw FinanceException::of('CLINIC_NOT_FOUND', 'ویزیت یافت نشد', 404);
            }
            // I1: فقط از consultation_completed/awaiting_payment
            if (!in_array((string) $visit['status'], ['consultation_completed', 'awaiting_payment'], true)) {
                throw FinanceException::of(
                    'CLINIC_INVALID_TRANSITION',
                    'فاکتور فقط برای ویزیت پایان‌یافته قابل صدور است',
                    409,
                    ['visit_status' => (string) $visit['status']]
                );
            }
            // I1/I4: هر ویزیت حداکثر یک فاکتور غیرابطال‌شده
            if ($this->invoices->activeForVisit($visitId) !== null) {
                throw FinanceException::of(
                    'CLINIC_POLICY_VIOLATION',
                    'این ویزیت فاکتور فعال دارد — ابتدا وضعیت آن را نهایی کنید (تسویه/ابطال فاکتور)',
                    409,
                    ['visit_id' => $visitId]
                );
            }

            // اقلام — سرویس کاتالوگ (FR-14.9) یا قلم دستی؛ مبلغ‌ها ریال صحیح
            $calcItems = [];
            $rows = [];
            foreach ($itemsIn as $item) {
                if (!is_array($item)) {
                    throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'قلم فاکتور نامعتبر است', 422);
                }
                $serviceId = isset($item['service_id']) && $item['service_id'] !== '' && $item['service_id'] !== null
                    ? (int) $item['service_id'] : null;
                $description = trim((string) ($item['description'] ?? ''));
                $quantity = isset($item['quantity']) && $item['quantity'] !== ''
                    ? (float) $item['quantity']
                    : (isset($item['qty']) && $item['qty'] !== '' ? (float) $item['qty'] : 1.0);
                $unitPrice = $this->toMinorOrNull($item['unit_price'] ?? ($item['price'] ?? null));
                $itemDiscount = $this->toMinor($item['discount'] ?? 0) ?? -1;

                if ($serviceId !== null) {
                    $service = $this->services->find($serviceId);
                    if ($service === null) {
                        throw FinanceException::of('CLINIC_NOT_FOUND', 'خدمت انتخاب‌شده یافت نشد', 404, ['service_id' => $serviceId]);
                    }
                    if ($description === '') {
                        $description = (string) $service['name'];
                    }
                    if ($unitPrice === null) {
                        $unitPrice = $this->toMinor((string) $service['price']) ?? 0;
                    }
                }
                if ($description === '') {
                    throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'شرح قلم فاکتور الزامی است', 422);
                }
                if ($unitPrice === null || $unitPrice < 0) {
                    throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'قیمت واحد باید عدد صحیح غیرمنفی باشد', 422);
                }
                if ($itemDiscount < 0) {
                    throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'تخفیف قلم باید عدد صحیح غیرمنفی باشد', 422);
                }

                $calcItems[] = [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $itemDiscount,
                ];
                $rows[] = [
                    'service_id' => $serviceId,
                    'description' => mb_substr($description, 0, 255),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $itemDiscount,
                ];
            }

            // محاسبه خالص دامنه (TP-18 — integer) + نگاشت خطاها
            try {
                $totals = InvoiceCalc::issueTotals($calcItems, $discount, $tax);
            } catch (DomainException $e) {
                throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'اقلام فاکتور نامعتبر است: ' . $e->getMessage(), 422);
            }

            // عددگیری سریال — قفل کلینیک همه عددگیری‌های موازی را سریال می‌کند
            $this->lockClinic();
            $number = $this->invoices->nextInvoiceNumber();

            $invoiceId = $this->invoices->insert([
                'invoice_number' => $number,
                'patient_id' => (int) $visit['patient_id'],
                'visit_id' => $visitId,
                'status' => 'open',
                'subtotal' => $this->minorToDb($totals['subtotal']),
                'discount' => $this->minorToDb($totals['discount']),
                'tax' => $this->minorToDb($totals['tax']),
                'total' => $this->minorToDb($totals['total']),
                'paid_amount' => 0,
                'balance' => $this->minorToDb($totals['total']),
                'issued_by_wp_user_id' => $actorUserId,
            ]);
            foreach ($rows as $row) {
                $this->invoices->insertItem($invoiceId, [
                    'service_id' => $row['service_id'],
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $this->minorToDb($row['unit_price']),
                    'amount' => $this->minorToDb((int) round($row['quantity'] * $row['unit_price']) - $row['discount']),
                    'discount' => $this->minorToDb($row['discount']),
                ]);
            }

            // V11: consultation_completed → awaiting_payment (نقش system — M-7)
            if ((string) $visit['status'] === 'consultation_completed') {
                $this->visitService->applyTransition($actorUserId, $visit, 'invoice_ready', [], 'system');
            }

            $this->audit->log(
                'INVOICE_CREATE',
                $this->actor($actorUserId),
                'invoice',
                $invoiceId,
                (int) $visit['patient_id'],
                null,
                [
                    'invoice.number' => $number,
                    'invoice.subtotal' => $totals['subtotal'],
                    'invoice.discount' => $totals['discount'],
                    'invoice.tax' => $totals['tax'],
                    'invoice.total' => $totals['total'],
                    'invoice.balance' => $totals['total'],
                    'invoice.items' => count($rows),
                    'visit_id' => $visitId,
                ],
                []
            );

            return $this->invoiceView($invoiceId);
        });
    }

    // ================= D13 — ثبت پرداخت (P1/I2/I3) =================

    /**
     * @param array<string, mixed> $input {amount, method, transaction_ref?}
     * @return array<string, mixed> {payment_id, payment_number, invoice, idempotent_replay?}
     */
    public function recordPayment(int $actorUserId, int $invoiceId, array $input, string $idempotencyKey): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::PAYMENT_CREATE, 'payment.capture');
        if ($idempotencyKey === '') {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'هدر Idempotency-Key (UUID) الزامی است', 400);
        }

        $amount = $this->toMinor($input['amount'] ?? null);
        $method = (string) ($input['method'] ?? '');
        $ref = isset($input['transaction_ref']) && $input['transaction_ref'] !== '' && $input['transaction_ref'] !== null
            ? mb_substr((string) $input['transaction_ref'], 0, 128) : null;
        if ($amount === null || $amount <= 0) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'مبلغ پرداخت باید عدد صحیح مثبت باشد', 422);
        }
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'روش پرداخت نامعتبر است (cash/card_pos/online/other)', 422);
        }

        // M-1: Idempotency روی خود جدول — تکرار کلید = همان پاسخ (TP-02)
        $existing = $this->payments->findByIdempotencyKey($invoiceId, $idempotencyKey);
        if ($existing !== null) {
            return $this->paymentResult($existing, $this->invoiceView($invoiceId), true);
        }

        $result = $this->db->transactional(
            function () use ($actorUserId, $invoiceId, $amount, $method, $ref, $idempotencyKey): array {
                $invoice = $this->requireOpenInvoiceForUpdate($invoiceId);

                // M-3 — بیش‌پرداخت ممنوع (چک صریح برای خطای غنی؛ InvoiceCalc دفاع دوم)
                $effectiveTotal = $this->effectiveTotalCents($invoice);
                $balance = $effectiveTotal - ($this->toMinor((string) $invoice['paid_amount']) ?? 0);
                if ($amount > $balance) {
                    throw FinanceException::of(
                        'CLINIC_OVERPAYMENT',
                        'مبلغ پرداخت بیش از باقیمانده فاکتور است',
                        422,
                        ['amount' => $amount, 'balance' => $balance]
                    );
                }

                $this->lockClinic();
                $number = $this->payments->nextPaymentNumber();
                $ok = $this->payments->insert([
                    'payment_number' => $number,
                    'invoice_id' => $invoiceId,
                    'patient_id' => (int) $invoice['patient_id'],
                    'amount' => $this->minorToDb($amount),
                    'method' => $method,
                    'transaction_ref' => $ref,
                    'idempotency_key' => $idempotencyKey,
                    'paid_at' => $this->db->nowUtcSql(),
                    'received_by_wp_user_id' => $actorUserId,
                ]);
                $paymentId = $this->db->wpdb_last_insert_id();
                if (!$ok || $paymentId <= 0) {
                    // UNIQUE(invoice_id,key) — درخواست هم‌زمان با همان کلید
                    $raced = $this->payments->findByIdempotencyKey($invoiceId, $idempotencyKey);
                    if ($raced !== null) {
                        return ['replay' => $raced];
                    }
                    throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'ثبت پرداخت انجام نشد', 500);
                }

                // I2/I3 + V12 — همه در همین Transaction (M-7)
                $this->applyInvoicePaymentEffect($invoice, $amount, $actorUserId);

                $this->audit->log(
                    'PAYMENT_CAPTURE',
                    $this->actor($actorUserId),
                    'payment',
                    $paymentId,
                    (int) $invoice['patient_id'],
                    [
                        'invoice.paid_amount' => (float) $invoice['paid_amount'],
                        'invoice.balance' => (float) $invoice['balance'],
                        'invoice.status' => (string) $invoice['status'],
                    ],
                    [
                        'invoice.paid_amount' => (float) $invoice['paid_amount'] + $amount,
                        'invoice.balance' => (float) $invoice['balance'] - $amount,
                        'payment_number' => $number,
                    ],
                    ['method' => $method, 'amount' => $amount, 'invoice_id' => $invoiceId]
                );

                return ['payment_id' => $paymentId];
            }
        );

        if (isset($result['replay'])) {
            return $this->paymentResult($result['replay'], $this->invoiceView($invoiceId), true);
        }

        return $this->paymentResult(
            $this->payments->find($result['payment_id']) ?? [],
            $this->invoiceView($invoiceId),
            false
        );
    }

    // ================= D14 — ابطال پرداخت (P2) =================

    /**
     * @return array<string, mixed>
     */
    public function voidPayment(int $actorUserId, int $paymentId, string $reason): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::PAYMENT_VOID, 'payment.void');
        $reason = trim($reason);
        if ($reason === '') {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'دلیل ابطال الزامی است', 422);
        }

        return $this->db->transactional(function () use ($actorUserId, $paymentId, $reason): array {
            $payment = $this->payments->findForUpdate($paymentId);
            if ($payment === null) {
                throw FinanceException::of('CLINIC_NOT_FOUND', 'پرداخت یافت نشد', 404);
            }
            if ((string) $payment['status'] !== 'captured') {
                throw FinanceException::of(
                    'CLINIC_INVALID_TRANSITION',
                    'فقط پرداخت captured قابل ابطال است',
                    409,
                    ['status' => (string) $payment['status']]
                );
            }
            // P2: بازه ابطال — همان روز ثبت (UTC)
            $paidDay = substr((string) $payment['paid_at'], 0, 10);
            if ($paidDay !== gmdate('Y-m-d')) {
                throw FinanceException::of(
                    'CLINIC_VOID_WINDOW_EXPIRED',
                    'ابطال پرداخت فقط در همان روز ثبت ممکن است',
                    409,
                    ['paid_at' => (string) $payment['paid_at']]
                );
            }

            $invoice = $this->invoices->findForUpdate((int) $payment['invoice_id']);
            if ($invoice === null) {
                throw FinanceException::of('CLINIC_NOT_FOUND', 'فاکتور یافت نشد', 404);
            }
            if ((string) $invoice['status'] === 'voided') {
                throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'فاکتور ابطال‌شده قابل تغییر نیست', 409);
            }

            $amount = $this->toMinor((string) $payment['amount']) ?? 0;
            $this->payments->update($paymentId, [
                'status' => 'voided',
                'void_reason' => mb_substr($reason, 0, 255),
                'voided_at' => $this->db->nowUtcSql(),
                'voided_by_wp_user_id' => $actorUserId,
            ]);
            $restored = $this->reverseInvoicePayment($invoice, $amount);

            $this->audit->log(
                'PAYMENT_VOID',
                $this->actor($actorUserId),
                'payment',
                $paymentId,
                (int) $payment['patient_id'],
                [
                    'payment.status' => 'captured',
                    'invoice.paid_amount' => (float) $invoice['paid_amount'],
                    'invoice.balance' => (float) $invoice['balance'],
                    'invoice.status' => (string) $invoice['status'],
                ],
                [
                    'payment.status' => 'voided',
                    'invoice.paid_amount' => $restored['paid_amount'],
                    'invoice.balance' => $restored['balance'],
                    'invoice.status' => $restored['status'],
                ],
                ['reason' => mb_substr($reason, 0, 255), 'payment_number' => (string) $payment['payment_number']]
            );

            return [
                'payment' => $this->presentPayment($this->payments->find($paymentId) ?? []),
                'invoice' => $this->invoiceView((int) $payment['invoice_id']),
            ];
        });
    }

    // ================= P3 — بازپرداخت (Refund) =================

    /**
     * @param array<string, mixed>|null $input {amount?} — پیش‌فرض: کل مبلغ باقیمانده قابل بازگردانی
     * @return array<string, mixed>
     */
    public function refundPayment(int $actorUserId, int $paymentId, string $reason, ?array $input = null): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::PAYMENT_REFUND, 'payment.refund');
        $reason = trim($reason);
        if ($reason === '') {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'دلیل بازپرداخت الزامی است', 422);
        }
        $input = $input ?? [];
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'پرداخت یافت نشد', 404);
        }

        $paidAmount = $this->toMinor((string) $payment['amount']) ?? 0;
        $alreadyRefunded = $this->toMinor((string) $payment['refunded_amount']) ?? 0;
        $maxRefundable = $paidAmount - $alreadyRefunded;
        $refund = isset($input['amount']) && $input['amount'] !== null && $input['amount'] !== ''
            ? $this->toMinor($input['amount'])
            : $maxRefundable;
        if ($refund === null || $refund <= 0 || $refund > $maxRefundable) {
            throw FinanceException::of(
                'CLINIC_VALIDATION_FAILED',
                'مبلغ بازپرداخت خارج از محدوده مجاز است',
                422,
                ['max_refundable' => $maxRefundable]
            );
        }

        return $this->db->transactional(function () use ($actorUserId, $paymentId, $reason, $refund, $alreadyRefunded): array {
            $payment = $this->payments->findForUpdate($paymentId);
            if ((string) $payment['status'] !== 'captured') {
                throw FinanceException::of(
                    'CLINIC_INVALID_TRANSITION',
                    'فقط پرداخت captured قابل بازپرداخت است',
                    409,
                    ['status' => (string) $payment['status']]
                );
            }
            $invoice = $this->invoices->findForUpdate((int) $payment['invoice_id']);
            if ($invoice === null || (string) $invoice['status'] === 'voided') {
                throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'فاکتور قابل تغییر نیست', 409);
            }

            $newRefunded = $alreadyRefunded + $refund;
            $this->payments->update($paymentId, [
                'refunded_amount' => $this->minorToDb($newRefunded),
                'status' => $newRefunded >= ($this->toMinor((string) $payment['amount']) ?? 0) ? 'refunded' : 'captured',
            ]);
            $restored = $this->reverseInvoicePayment($invoice, $refund);

            $this->audit->log(
                'PAYMENT_REFUND',
                $this->actor($actorUserId),
                'payment',
                $paymentId,
                (int) $payment['patient_id'],
                [
                    'payment.refunded_amount' => (float) $payment['refunded_amount'],
                    'invoice.balance' => (float) $invoice['balance'],
                    'invoice.status' => (string) $invoice['status'],
                ],
                [
                    'payment.refunded_amount' => $newRefunded,
                    'invoice.balance' => $restored['balance'],
                    'invoice.status' => $restored['status'],
                ],
                ['reason' => mb_substr($reason, 0, 255), 'refund_amount' => $refund, 'payment_number' => (string) $payment['payment_number']]
            );

            return [
                'payment' => $this->presentPayment($this->payments->find($paymentId) ?? []),
                'invoice' => $this->invoiceView((int) $payment['invoice_id']),
            ];
        });
    }

    // ================= D15 — اصلاح (Credit/Debit) =================

    /**
     * @param array<string, mixed> $input {amount, reason}
     * @return array<string, mixed>
     */
    public function addAdjustment(int $actorUserId, int $invoiceId, string $type, array $input): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::INVOICE_ADJUST, 'invoice.adjust');
        if (!in_array($type, ['credit', 'debit'], true)) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'type باید credit یا debit باشد', 422);
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'دلیل اصلاح الزامی است', 422);
        }
        $amount = $this->toMinor($input['amount'] ?? null);
        if ($amount === null || $amount <= 0) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'مبلغ اصلاح باید عدد صحیح مثبت باشد', 422);
        }

        return $this->db->transactional(function () use ($actorUserId, $invoiceId, $type, $reason, $amount): array {
            $invoice = $this->requireOpenInvoiceForUpdate($invoiceId);
            $balance = $this->toMinor((string) $invoice['balance']) ?? 0;

            // Credit هرگز بیشتر از بدهی باقیمانده نیست (به سود بیمار)
            if ($type === 'credit' && $amount > $balance) {
                throw FinanceException::of(
                    'CLINIC_VALIDATION_FAILED',
                    'اعتبار (credit) بیش از بدهی باقیمانده است',
                    422,
                    ['balance' => $balance]
                );
            }

            // محاسبه خالص دامنه — defense in depth (چک بالا قبلاً خطا داده)
            try {
                $r = InvoiceCalc::applyAdjustment(
                    $this->toMinor((string) $invoice['total']) ?? 0,
                    $this->toMinor((string) $invoice['paid_amount']) ?? 0,
                    $balance,
                    $type,
                    $amount
                );
            } catch (DomainException $e) {
                throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'اصلاح نامعتبر: ' . $e->getMessage(), 422);
            }
            $newBalance = $r['balance'];

            $adjId = $this->invoices->insertAdjustment([
                'invoice_id' => $invoiceId,
                'payment_id' => null,
                'type' => $type,
                'amount' => $this->minorToDb($amount),
                'reason' => mb_substr($reason, 0, 255),
                'approved_by_wp_user_id' => $actorUserId,
            ]);

            // FR-14.6: credit = کسر از بدهی؛ debit = افزایش بدهی.
            // رویداد وضعیت (open/partial/paid) شرط کسب‌وکاری است که همین‌جا
            // محاسبه می‌شود (یادداشت InvoiceMachine).
            $paid = $this->toMinor((string) $invoice['paid_amount']) ?? 0;
            $status = $newBalance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'open');
            $this->invoices->update($invoiceId, [
                'balance' => $this->minorToDb($newBalance),
                'status' => $status,
            ]);
            if ($status === 'paid') {
                $this->settleVisitIfAwaitingPayment((int) $invoice['visit_id'], $actorUserId);
            }

            $this->audit->log(
                'PAYMENT_ADJUST',
                $this->actor($actorUserId),
                'invoice',
                $invoiceId,
                (int) $invoice['patient_id'],
                [
                    'invoice.balance' => (float) $invoice['balance'],
                    'invoice.status' => (string) $invoice['status'],
                ],
                [
                    'invoice.balance' => $newBalance,
                    'invoice.status' => $status,
                    'adjustment.type' => $type,
                    'adjustment.amount' => $amount,
                    'adjustment.reason' => mb_substr($reason, 0, 255),
                ],
                ['adjustment_id' => $adjId]
            );

            return [
                'adjustment' => [
                    'id' => $adjId,
                    'type' => $type,
                    'amount' => $amount,
                    'reason' => mb_substr($reason, 0, 255),
                ],
                'invoice' => $this->invoiceView($invoiceId),
            ];
        });
    }

    // ================= D17 — رسید (M-5 Deterministic) =================

    /**
     * @return array<string, mixed>
     */
    public function receipt(int $actorUserId, int $invoiceId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::INVOICE_READ, 'invoice.receipt');
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'فاکتور یافت نشد', 404);
        }
        $patient = $this->patients->find((int) $invoice['patient_id']);
        $clinic = $this->db->fetchRow(
            'SELECT name, address, phone FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
            [(int) $invoice['clinic_id']]
        );
        $payments = array_values(array_filter(
            $this->payments->forInvoice($invoiceId),
            static fn (array $p): bool => (string) $p['status'] === 'captured'
        ));

        // M-5: تولید مجدد = همان محتوا — بدون Timestamp تولید
        return [
            'receipt' => [
                'invoice_number' => (string) $invoice['invoice_number'],
                'invoice_status' => (string) $invoice['status'],
                'jalali_date' => Jalali::formatYmd(substr((string) $invoice['created_at'], 0, 10)),
                'clinic' => [
                    'name' => (string) ($clinic['name'] ?? ''),
                    'address' => $clinic['address'] ?? null,
                    'phone' => $clinic['phone'] ?? null,
                ],
                'patient' => [
                    'name' => $patient !== null ? trim((string) $patient['first_name'] . ' ' . (string) $patient['last_name']) : '',
                    'mrn' => $patient !== null ? (string) $patient['mrn'] : '',
                ],
                'items' => array_map(static fn (array $i): array => [
                    'description' => (string) $i['description'],
                    'quantity' => (float) $i['quantity'],
                    'unit_price' => (float) $i['unit_price'],
                    'amount' => (float) $i['amount'],
                ], $this->invoices->itemsFor($invoiceId)),
                'totals' => [
                    'subtotal' => (float) $invoice['subtotal'],
                    'discount' => (float) $invoice['discount'],
                    'tax' => (float) $invoice['tax'],
                    'total' => (float) $invoice['total'],
                    'paid_amount' => (float) $invoice['paid_amount'],
                    'balance' => (float) $invoice['balance'],
                    'currency' => (string) $invoice['currency'],
                ],
                'payments' => array_map(static fn (array $p): array => [
                    'payment_number' => (string) $p['payment_number'],
                    'method' => (string) $p['method'],
                    'amount' => (float) $p['amount'],
                    'jalali_paid_at' => Jalali::formatYmd(substr((string) $p['paid_at'], 0, 10)),
                ], $payments),
            ],
        ];
    }

    // ================= D18 — خلاصه مالی =================

    /**
     * @return array<string, mixed>
     */
    public function summary(int $actorUserId, ?string $from = null, ?string $to = null): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::FINANCE_READ, 'finance.summary');
        $from = $from ?? gmdate('Y-m-d');
        $to = $to ?? gmdate('Y-m-d');
        foreach (['from' => $from, 'to' => $to] as $label => $date) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
            if ($dt === false || $dt->format('Y-m-d') !== $date) {
                throw FinanceException::of('CLINIC_VALIDATION_FAILED', "پارامتر {$label} باید تاریخ YYYY-MM-DD باشد", 422);
            }
        }

        $revenue = $this->payments->revenueSummary($from, $to);
        $openInvoices = $this->invoices->openInvoices(500);
        $openBalance = 0;
        foreach ($openInvoices as $inv) {
            $openBalance += (int) round((float) $inv['balance']);
        }

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => [
                'total' => (int) round($revenue['total']),
                'by_method' => array_map(static fn (float $v): int => (int) round($v), $revenue['by_method']),
                'refunded' => (int) round($revenue['refunded']),
                'payment_count' => $revenue['count'],
            ],
            'open_balances' => [
                'total' => $openBalance,
                'invoice_count' => count($openInvoices),
                'invoices' => array_map(static fn (array $inv): array => [
                    'id' => (int) $inv['id'],
                    'invoice_number' => (string) $inv['invoice_number'],
                    'patient_name' => trim((string) $inv['patient_first_name'] . ' ' . (string) $inv['patient_last_name']),
                    'mrn' => (string) $inv['patient_mrn'],
                    'balance' => (int) round((float) $inv['balance']),
                    'status' => (string) $inv['status'],
                ], array_slice($openInvoices, 0, 50)),
            ],
            'payments' => array_map(
                fn (array $p): array => $this->presentPayment($p),
                $this->payments->forRange($from, $to, 100)
            ),
        ];
    }

    // ================= خواندن =================

    /**
     * @return array<string, mixed>
     */
    public function invoiceView(int $invoiceId): array
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'فاکتور یافت نشد', 404);
        }
        $adjustments = $this->invoices->adjustmentTotals($invoiceId);

        return [
            'id' => (int) $invoice['id'],
            'invoice_number' => (string) $invoice['invoice_number'],
            'visit_id' => (int) $invoice['visit_id'],
            'patient_id' => (int) $invoice['patient_id'],
            'status' => (string) $invoice['status'],
            'subtotal' => (float) $invoice['subtotal'],
            'discount' => (float) $invoice['discount'],
            'tax' => (float) $invoice['tax'],
            'total' => (float) $invoice['total'],
            'paid_amount' => (float) $invoice['paid_amount'],
            'balance' => (float) $invoice['balance'],
            'currency' => (string) $invoice['currency'],
            'adjustments' => ['credit' => $adjustments['credit'], 'debit' => $adjustments['debit']],
            'items' => array_map(static fn (array $i): array => [
                'id' => (int) $i['id'],
                'service_id' => $i['service_id'] !== null ? (int) $i['service_id'] : null,
                'description' => (string) $i['description'],
                'quantity' => (float) $i['quantity'],
                'unit_price' => (float) $i['unit_price'],
                'amount' => (float) $i['amount'],
            ], $this->invoices->itemsFor($invoiceId)),
            'payments' => array_map(fn (array $p): array => $this->presentPayment($p), $this->payments->forInvoice($invoiceId)),
            'void_reason' => $invoice['void_reason'],
            'created_at' => (string) $invoice['created_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function findInvoiceForActor(int $actorUserId, int $invoiceId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::INVOICE_READ, 'invoice.read');

        return $this->invoiceView($invoiceId);
    }

    /**
     * فاکتور فعال ویزیت (open/partial/paid) — برای UI تسویه (رفع ویزیت→فاکتور).
     *
     * @return array<string, mixed>
     */
    public function invoiceForVisit(int $actorUserId, int $visitId): array
    {
        $this->requireCap($actorUserId, RolesAndCapabilities::INVOICE_READ, 'invoice.read');
        $invoice = $this->invoices->activeForVisit($visitId);
        if ($invoice === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'این ویزیت فاکتور فعال ندارد', 404, ['visit_id' => $visitId]);
        }

        return $this->invoiceView((int) $invoice['id']);
    }

    // ================= Helpers — تراکنش/محاسبات =================

    /**
     * @return array<string, mixed>
     */
    private function requireOpenInvoiceForUpdate(int $invoiceId): array
    {
        $invoice = $this->invoices->findForUpdate($invoiceId);
        if ($invoice === null) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'فاکتور یافت نشد', 404);
        }
        if (!in_array((string) $invoice['status'], ['open', 'partial'], true)) {
            // M-6 — عمل روی فاکتور paid/voided
            throw FinanceException::of(
                'CLINIC_INVOICE_NOT_MODIFIABLE',
                'این فاکتور (' . (string) $invoice['status'] . ') نهایی شده و قابل تغییر نیست',
                409,
                ['status' => (string) $invoice['status']]
            );
        }

        return $invoice;
    }

    /**
     * کلید تسویه مؤثر: total − credit + debit (ریال صحیح — TP-18).
     *
     * @param array<string, mixed> $invoice
     */
    private function effectiveTotalCents(array $invoice): int
    {
        $adjustments = $this->invoices->adjustmentTotals((int) $invoice['id']);

        return ($this->toMinor((string) $invoice['total']) ?? 0)
            - (int) round((float) $adjustments['credit'])
            + (int) round((float) $adjustments['debit']);
    }

    /**
     * اثر پرداخت روی فاکتور (I2/I3 — InvoiceCalc + InvoiceMachine) و در
     * تسویه کامل روی ویزیت (V12). داخل Transaction فراخواننده (M-7).
     *
     * @param array<string, mixed> $invoice
     */
    private function applyInvoicePaymentEffect(array $invoice, int $amount, int $actorUserId): void
    {
        $paid = $this->toMinor((string) $invoice['paid_amount']) ?? 0;
        $effectiveTotal = $this->effectiveTotalCents($invoice);

        try {
            $r = InvoiceCalc::applyPayment($effectiveTotal, $paid, $amount);
        } catch (DomainException $e) {
            throw FinanceException::of('CLINIC_OVERPAYMENT', $e->getMessage(), 422);
        }

        // رویداد pay_partial/pay_full — نقش system (I2/I3)
        $toStatus = InvoiceMachine::create()->machine()->assert((string) $invoice['status'], $r['event'], 'system');

        $this->invoices->update((int) $invoice['id'], [
            'paid_amount' => $this->minorToDb($r['paid']),
            'balance' => $this->minorToDb($r['balance']),
            'status' => $toStatus,
        ]);

        if ($r['event'] === 'pay_full') {
            $this->settleVisitIfAwaitingPayment((int) $invoice['visit_id'], $actorUserId);
        }
    }

    /**
     * برگرداندن اثر مبلغ (ابطال/بازپرداخت) روی فاکتور — P2/P3.
     * وضعیت ویزیت برنمی‌گردد (V12 یک‌طرفه — Deviation مستند).
     *
     * @param array<string, mixed> $invoice
     * @return array{paid_amount: int, balance: int, status: string}
     */
    private function reverseInvoicePayment(array $invoice, int $amount): array
    {
        $paid = max(0, ($this->toMinor((string) $invoice['paid_amount']) ?? 0) - $amount);
        $effectiveTotal = $this->effectiveTotalCents($invoice);
        $balance = max(0, $effectiveTotal - $paid);
        $status = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'open');

        $this->invoices->update((int) $invoice['id'], [
            'paid_amount' => $this->minorToDb($paid),
            'balance' => $this->minorToDb($balance),
            'status' => $status,
        ]);

        return ['paid_amount' => $paid, 'balance' => $balance, 'status' => $status];
    }

    /**
     * V12: awaiting_payment → paid (نقش system) — فقط اگر هنوز در انتظار است.
     */
    private function settleVisitIfAwaitingPayment(int $visitId, int $actorUserId): void
    {
        $visit = $this->visits->findForUpdate($visitId);
        if ($visit === null || (string) $visit['status'] !== 'awaiting_payment') {
            return;
        }
        try {
            $this->visitService->applyTransition($actorUserId, $visit, 'settled', [], 'system');
        } catch (VisitException $e) {
            // وضعیت هم‌زمان عوض شده (مثلاً waive) — فاکتور ملاک مالی است؛
            // خطای Transition ویزیت عمل مالیِ انجام‌شده را بی‌اعتبار نمی‌کند.
            error_log('[CPMS][Finance] settle skipped: ' . $e->getMessage());
        }
    }

    /**
     * قفل ردیف کلینیک — سریال‌سازی عددگیری INV/PAY (رقابت موازی روی MAX+1).
     */
    private function lockClinic(): void
    {
        $this->db->fetchRowForUpdate(
            'SELECT id FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
            [1]
        );
    }

    /**
     * پول ورودی → ریال صحیح (واحد صغیر توافق‌شده با InvoiceCalc)؛
     * مقدار غیرعددی/کسری → null (خطای Validation در فراخواننده). TP-18.
     */
    private function toMinor(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return $value == floor($value) ? (int) $value : null;
        }
        if (is_string($value) && is_numeric($value)) {
            $f = (float) $value;

            return $f == floor($f) ? (int) $f : null;
        }

        return null;
    }

    /** مثل toMinor ولی null/'' ورودی خالی را هم null می‌دهد (قلم اختیاری). */
    private function toMinorOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->toMinor($value);
    }

    /** ریال صحیح → مقدار ستون DECIMAL(12,2). */
    private function minorToDb(int $minor): float
    {
        return (float) $minor;
    }

    /**
     * @return array{0: string, 1: string, 2: float}
     */
    private function validateServiceInput(array $input, ?array $existing): array
    {
        $code = mb_substr(trim((string) ($input['code'] ?? ($existing['code'] ?? ''))), 0, 32);
        $name = mb_substr(trim((string) ($input['name'] ?? ($existing['name'] ?? ''))), 0, 190);
        $price = $this->toMinorOrNull($input['price'] ?? ($existing['price'] ?? null));
        if ($code === '' || $name === '') {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'کد و نام خدمت الزامی است', 422);
        }
        if ($price === null || $price < 0) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'قیمت خدمت باید عدد صحیح غیرمنفی باشد', 422);
        }

        return [$code, $name, $this->minorToDb($price)];
    }

    private function requireCap(int $wpUserId, string $cap, string $scope): void
    {
        if (!user_can($wpUserId, $cap)) {
            throw FinanceException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403, ['scope' => $scope]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(int $wpUserId): array
    {
        $user = get_userdata($wpUserId);

        return ['wp_user_id' => $wpUserId, 'role' => $user !== false ? ($user->roles[0] ?? 'unknown') : 'unknown'];
    }

    /**
     * پاسخ استاندارد D13 — شکل قرارداد + شناسه Replay.
     *
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $invoiceView
     * @return array<string, mixed>
     */
    private function paymentResult(array $payment, array $invoiceView, bool $replay): array
    {
        $result = [
            'payment_id' => (int) ($payment['id'] ?? 0),
            'payment_number' => (string) ($payment['payment_number'] ?? ''),
            'payment' => $this->presentPayment($payment),
            'invoice' => [
                'status' => $invoiceView['status'],
                'balance' => $invoiceView['balance'],
                'paid_amount' => $invoiceView['paid_amount'],
                'invoice_number' => $invoiceView['invoice_number'],
            ],
        ];
        if ($replay) {
            // TP-02: همان payment با کد CLINIC_IDEMPOTENCY_REPLAY و HTTP 200
            $result['idempotent_replay'] = true;
            $result['code'] = 'CLINIC_IDEMPOTENCY_REPLAY';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function presentPayment(array $p): array
    {
        return [
            'id' => (int) ($p['id'] ?? 0),
            'payment_number' => (string) ($p['payment_number'] ?? ''),
            'invoice_id' => (int) ($p['invoice_id'] ?? 0),
            'amount' => (float) ($p['amount'] ?? 0),
            'method' => (string) ($p['method'] ?? ''),
            'transaction_ref' => $p['transaction_ref'] ?? null,
            'status' => (string) ($p['status'] ?? ''),
            'refunded_amount' => (float) ($p['refunded_amount'] ?? 0),
            'paid_at' => (string) ($p['paid_at'] ?? ''),
            'void_reason' => $p['void_reason'] ?? null,
        ];
    }
}

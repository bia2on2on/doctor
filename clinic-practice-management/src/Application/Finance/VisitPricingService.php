<?php

declare(strict_types=1);

namespace ClinicCore\Application\Finance;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Finance\MoneyInput;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\InvoiceRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\PaymentRepository;
use ClinicCore\Infrastructure\Repository\VisitPricingRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DomainException;
use Throwable;

/**
 * Doctor-managed consultation pricing and draft treatment charges.
 *
 * This is NOT a billing engine: it only owns (a) the doctor's default fee,
 * (b) a per-visit override, (c) draft charge items, and (d) the projection a
 * receptionist reads. Invoice totals/payments/state transitions are produced
 * exclusively by FinanceService + InvoiceCalc + the existing repositories.
 *
 * Three distinct concepts stay distinct in storage and in every response:
 *   default_fee (per doctor/clinic/location),
 *   override_fee (per visit),
 *   charges[]   (additional treatment/service items).
 */
final class VisitPricingService
{
    public const CURRENCY = 'IRR';
    public const CURRENCY_LABEL = 'ریال';

    public const CONSULTATION_DESCRIPTION = 'ویزیت و مشاوره';

    private const DOCTOR_CAP  = RolesAndCapabilities::CONSULT_COMPLETE;
    private const READ_CAP    = RolesAndCapabilities::INVOICE_READ;
    private const WRITE_CAP   = RolesAndCapabilities::INVOICE_CREATE;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly VisitPricingRepository $pricing,
        private readonly VisitRepository $visits,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly ClinicianRepository $clinicians,
        private readonly MembershipRepository $memberships,
        private readonly PatientRepository $patients,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Doctor pricing context (read-only projection + UI authority hints).
     *
     * @return array<string, mixed>
     */
    public function context(int $actor_user_id, ?int $location_id = null): array
    {
        $ctx       = $this->context_for($actor_user_id, false);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $target    = $location_id === null ? 0 : $location_id;
        $this->assert_location_allowed($ctx, $actor_user_id, $target);
        $fee = $this->pricing->default_fee($ctx['clinic_id'], (int) $clinician['id'], $target);

        return [
            'clinic_id'        => $ctx['clinic_id'],
            'clinician_id'     => (int) $clinician['id'],
            'clinician_name'   => (string) $clinician['full_name'],
            'location_id'      => $target,
            'default_fee'      => $fee !== null ? (int) $fee['fee'] : null,
            'currency'         => self::CURRENCY,
            'currency_label'   => self::CURRENCY_LABEL,
            'can_edit'         => App::authorization_service()->can($actor_user_id, $ctx['clinic_id'], self::WRITE_CAP),
            'max_amount'       => MoneyInput::MAX_AMOUNT,
            'max_quantity'     => MoneyInput::MAX_QUANTITY,
        ];
    }

    /**
     * Today's visits of the CURRENT doctor (server-derived clinician identity).
     *
     * @return array<string, mixed>
     */
    public function visits(int $actor_user_id): array
    {
        $ctx       = $this->context_for($actor_user_id, false);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $date      = $this->operational_date($ctx['clinic_id']);
        $rows      = $this->pricing->visits_for_doctor(
            $ctx['clinic_id'],
            (int) $clinician['id'],
            $date,
            $this->allowed_locations($ctx['clinic_id'], $actor_user_id),
            100
        );
        $visits = [];
        foreach ($rows as $row) {
            $visits[] = [
                'visit_id'     => (int) $row['id'],
                'patient_name' => trim((string) $row['patient_first_name'] . ' ' . (string) $row['patient_last_name']),
                'status'       => (string) $row['status'],
                'visit_date'   => (string) $row['visit_date'],
                'location_id'  => (int) $row['location_id'],
            ];
        }

        return ['date' => $date, 'clinician_id' => (int) $clinician['id'], 'visits' => $visits];
    }

    /**
     * Itemized billing projection for a doctor OR receptionist with invoice read.
     * Never mutates; never returns clinical notes or medical records.
     *
     * @return array<string, mixed>
     */
    public function billing_for_visit(int $actor_user_id, int $visit_id): array
    {
        $ctx   = $this->context_for($actor_user_id, false);
        $visit = $this->require_visit($ctx, $actor_user_id, $visit_id);
        $this->assert_location_allowed($ctx, $actor_user_id, (int) ($visit['location_id'] ?? 0));

        return $this->billing_view($ctx, $actor_user_id, $visit);
    }

    /**
     * @return array<string, mixed>
     */
    public function set_default_fee(int $actor_user_id, ?int $location_id, mixed $amount): array
    {
        $ctx       = $this->context_for($actor_user_id, true);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $target    = $location_id === null ? 0 : $location_id;
        $this->assert_location_allowed($ctx, $actor_user_id, $target);
        $fee = $this->amount($amount);

        $change = $this->db->transactional(function () use ($ctx, $actor_user_id, $clinician, $target, $fee): array {
            $result = $this->pricing->set_default_fee(
                $ctx['clinic_id'],
                (int) $clinician['id'],
                $target,
                $fee,
                $actor_user_id
            );
            $this->audit->log(
                'SETTING_UPDATE',
                $this->actor($actor_user_id),
                'doctor_consultation_fee',
                (int) $clinician['id'],
                null,
                ['fee' => $result['before'], 'currency' => self::CURRENCY, 'location_id' => $target],
                ['fee' => $result['after'], 'currency' => self::CURRENCY, 'location_id' => $target],
                ['op' => 'doctor_default_fee']
            );

            return $result;
        });

        return [
            'clinician_id'   => (int) $clinician['id'],
            'location_id'    => $target,
            'default_fee'    => $change['after'],
            'previous_fee'   => $change['before'],
            'currency'       => self::CURRENCY,
            'currency_label' => self::CURRENCY_LABEL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function set_visit_consultation_fee(int $actor_user_id, int $visit_id, mixed $amount): array
    {
        $ctx       = $this->context_for($actor_user_id, true);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $visit     = $this->require_visit_for_write($ctx, $actor_user_id, $visit_id, $clinician);
        $fee       = $this->amount($amount);

        $this->db->transactional(function () use ($ctx, $actor_user_id, $clinician, $visit_id, $fee): void {
            $before = $this->pricing->visit_override($visit_id);
            $this->pricing->set_visit_override($visit_id, $ctx['clinic_id'], (int) $clinician['id'], $fee, $actor_user_id);
            $this->audit->log(
                'INVOICE_ADJUST',
                $this->actor($actor_user_id),
                'visit_consultation_fee',
                $visit_id,
                null,
                ['fee' => $before !== null ? (int) $before['fee'] : null, 'currency' => self::CURRENCY],
                ['fee' => $fee, 'currency' => self::CURRENCY],
                ['op' => 'visit_consultation_fee_override', 'clinician_id' => (int) $clinician['id']]
            );
        });
        unset($visit);

        return $this->billing_view($ctx, $actor_user_id, $this->require_visit($ctx, $actor_user_id, $visit_id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function add_charge(int $actor_user_id, int $visit_id, array $input): array
    {
        $ctx       = $this->context_for($actor_user_id, true);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $visit     = $this->require_visit_for_write($ctx, $actor_user_id, $visit_id, $clinician);
        $fields    = $this->charge_input($input);

        $charge_id = $this->db->transactional(function () use ($ctx, $actor_user_id, $clinician, $visit, $visit_id, $fields): int {
            $id = $this->pricing->insert_charge([
                'clinic_id'             => $ctx['clinic_id'],
                'visit_id'              => $visit_id,
                'clinician_id'          => (int) $clinician['id'],
                'description'           => $fields['description'],
                'unit_amount'           => $fields['amount'],
                'quantity'              => $fields['quantity'],
                'note'                  => $fields['note'],
                'created_by_wp_user_id' => $actor_user_id,
            ]);
            $this->audit->log(
                'INVOICE_ITEM_CREATE',
                $this->actor($actor_user_id),
                'visit_charge_item',
                $id,
                (int) $visit['patient_id'],
                null,
                [
                    'visit_id'    => $visit_id,
                    'description' => $fields['description'],
                    'unit_amount' => $fields['amount'],
                    'quantity'    => $fields['quantity'],
                    'currency'    => self::CURRENCY,
                ]
            );

            return $id;
        });
        unset($visit);

        return $this->billing_view($ctx, $actor_user_id, $this->require_visit($ctx, $actor_user_id, $visit_id)) + ['charge_id' => $charge_id];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update_charge(int $actor_user_id, int $visit_id, int $charge_id, array $input): array
    {
        $ctx       = $this->context_for($actor_user_id, true);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $visit     = $this->require_visit_for_write($ctx, $actor_user_id, $visit_id, $clinician);
        $charge    = $this->require_charge($ctx, $visit_id, $charge_id);
        $fields    = $this->charge_input([
            'description' => $input['description'] ?? $charge['description'],
            'amount'      => $input['amount'] ?? ($input['unit_amount'] ?? $charge['unit_amount']),
            'quantity'    => $input['quantity'] ?? $charge['quantity'],
            'note'        => array_key_exists('note', $input) ? $input['note'] : $charge['note'],
        ]);

        $this->db->transactional(function () use ($actor_user_id, $visit, $charge_id, $charge, $fields): void {
            $this->pricing->update_charge($charge_id, [
                'description'           => $fields['description'],
                'unit_amount'           => $fields['amount'],
                'quantity'              => $fields['quantity'],
                'note'                  => $fields['note'],
                'updated_by_wp_user_id' => $actor_user_id,
            ]);
            $this->audit->log(
                'INVOICE_ITEM_UPDATE',
                $this->actor($actor_user_id),
                'visit_charge_item',
                $charge_id,
                (int) $visit['patient_id'],
                [
                    'description' => (string) $charge['description'],
                    'unit_amount' => (int) $charge['unit_amount'],
                    'quantity'    => (float) $charge['quantity'],
                    'note'        => $charge['note'],
                ],
                [
                    'description' => $fields['description'],
                    'unit_amount' => $fields['amount'],
                    'quantity'    => $fields['quantity'],
                    'note'        => $fields['note'],
                ],
                ['visit_id' => (int) $visit['id'], 'currency' => self::CURRENCY]
            );
        });
        unset($visit);

        return $this->billing_view($ctx, $actor_user_id, $this->require_visit($ctx, $actor_user_id, $visit_id));
    }

    /**
     * Soft removal: the row and its audit history survive; settled invoices
     * and recorded payments are never touched.
     *
     * @return array<string, mixed>
     */
    public function remove_charge(int $actor_user_id, int $visit_id, int $charge_id): array
    {
        $ctx       = $this->context_for($actor_user_id, true);
        $clinician = $this->require_doctor($ctx, $actor_user_id);
        $visit     = $this->require_visit_for_write($ctx, $actor_user_id, $visit_id, $clinician);
        $charge    = $this->require_charge($ctx, $visit_id, $charge_id);

        $this->db->transactional(function () use ($actor_user_id, $visit, $charge_id, $charge): void {
            $this->pricing->update_charge($charge_id, [
                'removed_at'            => $this->db->nowUtcSql(),
                'removed_by_wp_user_id' => $actor_user_id,
                'updated_by_wp_user_id' => $actor_user_id,
            ]);
            $this->audit->log(
                'INVOICE_ITEM_REMOVE',
                $this->actor($actor_user_id),
                'visit_charge_item',
                $charge_id,
                (int) $visit['patient_id'],
                [
                    'description' => (string) $charge['description'],
                    'unit_amount' => (int) $charge['unit_amount'],
                    'quantity'    => (float) $charge['quantity'],
                    'removed_at'  => null,
                ],
                ['removed_at' => 'set', 'currency' => self::CURRENCY],
                ['visit_id' => (int) $visit['id'], 'soft_delete' => true]
            );
        });
        unset($visit);

        return $this->billing_view($ctx, $actor_user_id, $this->require_visit($ctx, $actor_user_id, $visit_id));
    }

    /**
     * Build the input items for the EXISTING FinanceService::issueInvoice().
     * Called only by FinanceService::issue_invoice_from_pricing() inside its
     * transaction. No invoice/payment row is written here.
     *
     * @param array<string, mixed> $visit
     * @return array{items: list<array<string, mixed>>, charge_ids: list<int>, clinic_id: int}
     */
    public function build_invoice_items(array $visit): array
    {
        $clinic_id   = (int) $visit['clinic_id'];
        $visit_id    = (int) $visit['id'];
        $location_id = (int) ($visit['location_id'] ?? 0);
        $clinician_id = (int) $visit['clinician_id'];

        $override = $this->pricing->visit_override($visit_id);
        $default  = $this->pricing->default_fee($clinic_id, $clinician_id, $location_id);
        $fee      = $override !== null ? (int) $override['fee'] : ($default !== null ? (int) $default['fee'] : null);

        $charges = [];
        foreach ($this->pricing->charges_for_visit($clinic_id, $visit_id) as $row) {
            if ($row['invoiced_invoice_id'] !== null) {
                throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'برخی اقلام پیشتر در فاکتور ثبت شدهاند', 409);
            }
            $charges[] = $row;
        }
        if ($fee === null && $charges === []) {
            throw FinanceException::of(
                'CLINIC_VALIDATION_FAILED',
                'تعرفه پیشفرض ویزیت پزشک تنظیم نشده است؛ ابتدا تعرفه یا خدمت ویزیت را ثبت کنید',
                422,
                ['visit_id' => $visit_id]
            );
        }

        $items      = [];
        $charge_ids = [];
        $total      = 0;
        if ($fee !== null) {
            $items[] = ['description' => self::CONSULTATION_DESCRIPTION, 'quantity' => 1, 'unit_price' => $fee];
            $total  += $fee;
        }
        foreach ($charges as $row) {
            $quantity = (float) $row['quantity'];
            $amount   = (int) $row['unit_amount'];
            $items[]  = [
                'description' => (string) $row['description'],
                'quantity'    => $quantity,
                'unit_price'  => $amount,
            ];
            $charge_ids[] = (int) $row['id'];
            $total       += (int) round($quantity * $amount);
        }
        if ($total <= 0 || $total > MoneyInput::MAX_TOTAL) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'جمع اقلام فاکتور خارج از محدوده مجاز است', 422);
        }

        return ['items' => $items, 'charge_ids' => $charge_ids, 'clinic_id' => $clinic_id];
    }

    /**
     * Stamp consumed draft charges with the invoice id (same transaction as
     * issuance) so a charge can never be edited into a settled invoice.
     *
     * @param list<int> $charge_ids
     */
    public function stamp_charges_invoiced(array $charge_ids, int $invoice_id, int $clinic_id): void
    {
        $this->pricing->stamp_charges_invoiced($charge_ids, $invoice_id, $clinic_id);
    }

    // ================= Helpers =================

    /**
     * @return array{clinic_id: int, organization_id: int|null}
     */
    private function context_for(int $actor_user_id, bool $write): array
    {
        $scope = ScopeContext::tryGet();
        if ($scope === null || $scope->clinicId <= 0) {
            throw FinanceException::of('CLINIC_SCOPE_REQUIRED', 'زمینهٔ معتبر مطب لازم است', 400);
        }
        if ($actor_user_id <= 0) {
            throw FinanceException::of('CLINIC_UNAUTHORIZED', 'وارد نشده اید', 401);
        }
        $clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope API.
        if ($this->memberships->find_active($clinic_id, $actor_user_id) === null) {
            throw FinanceException::of('CLINIC_PERMISSION_DENIED', 'عضویت فعالی در این مطب ندارید', 403);
        }
        $auth = App::authorization_service();
        foreach ($write ? [self::READ_CAP, self::WRITE_CAP] : [self::READ_CAP] as $cap) {
            try {
                $auth->authorize($actor_user_id, $clinic_id, $cap);
            } catch (AuthorizationException $exception) {
                throw FinanceException::of('CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403, ['reason' => $exception->getErrorCode()]);
            }
        }

        return [
            'clinic_id'       => $clinic_id,
            'organization_id' => $scope->organizationId !== null ? (int) $scope->organizationId : null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope API.
        ];
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @return array<string, mixed>
     */
    private function require_doctor(array $ctx, int $actor_user_id): array
    {
        $clinician = $this->clinicians->find_by_wp_user($actor_user_id);
        if (
            $clinician === null
            || (int) ($clinician['is_active'] ?? 0) !== 1
            || !$this->memberships->clinician_participates_in((int) $clinician['id'], $ctx['clinic_id'])
            || !App::authorization_service()->can($actor_user_id, $ctx['clinic_id'], self::DOCTOR_CAP)
        ) {
            throw FinanceException::of('CLINIC_PERMISSION_DENIED', 'تنظیم تعرفه فقط برای پزشک همان ویزیت مجاز است', 403);
        }

        return $clinician;
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @return array<string, mixed>
     */
    private function require_visit(array $ctx, int $actor_user_id, int $visit_id): array
    {
        $visit = $this->visits->find($visit_id);
        if ($visit === null || (int) $visit['clinic_id'] !== $ctx['clinic_id']) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'ویزیت یافت نشد', 404);
        }

        return $visit;
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @param array<string, mixed> $clinician
     * @return array<string, mixed>
     */
    private function require_visit_for_write(array $ctx, int $actor_user_id, int $visit_id, array $clinician): array
    {
        $visit = $this->require_visit($ctx, $actor_user_id, $visit_id);
        if ((int) $visit['clinician_id'] !== (int) $clinician['id']) {
            throw FinanceException::of('CLINIC_PERMISSION_DENIED', 'این ویزیت به پزشک جاری تعلق ندارد', 403);
        }
        $this->assert_location_allowed($ctx, $actor_user_id, (int) ($visit['location_id'] ?? 0));
        $this->assert_draft($ctx, $visit);

        return $visit;
    }

    /**
     * Draft-only guard: an active invoice, ANY captured payment, a checked-out
     * /paid Visit, or a charge already stamped into an invoice freezes pricing.
     *
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @param array<string, mixed> $visit
     */
    private function assert_draft(array $ctx, array $visit): void
    {
        $visit_id = (int) $visit['id'];
        if (in_array((string) $visit['status'], ['paid', 'checked_out'], true)) {
            throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'ویزیت تسویهشده قابل تغییر نیست', 409);
        }
        if ($this->invoices->activeForVisit($visit_id) !== null) {
            throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'فاکتور ویزیت صادر شده است', 409);
        }
        foreach ($this->invoices->forVisit($visit_id) as $invoice) {
            foreach ($this->payments->forInvoice((int) $invoice['id']) as $payment) {
                if ((string) $payment['status'] === 'captured') {
                    throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'پرداخت ثبتشده قابل تغییر نیست', 409);
                }
            }
        }
        foreach ($this->pricing->charges_for_visit($ctx['clinic_id'], $visit_id, true) as $charge) {
            if ($charge['invoiced_invoice_id'] !== null) {
                throw FinanceException::of('CLINIC_INVOICE_NOT_MODIFIABLE', 'اقلام فاکتورشده قابل تغییر نیستند', 409);
            }
        }
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @return array<string, mixed>
     */
    private function require_charge(array $ctx, int $visit_id, int $charge_id): array
    {
        $charge = $this->pricing->find_charge($charge_id);
        if (
            $charge === null
            || (int) $charge['clinic_id'] !== $ctx['clinic_id']
            || (int) $charge['visit_id'] !== $visit_id
            || $charge['removed_at'] !== null
            || $charge['invoiced_invoice_id'] !== null
        ) {
            throw FinanceException::of('CLINIC_NOT_FOUND', 'قلم خدمت یافت نشد', 404);
        }

        return $charge;
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function billing_view(array $ctx, int $actor_user_id, array $visit): array
    {
        $clinic_id    = $ctx['clinic_id'];
        $visit_id     = (int) $visit['id'];
        $clinician_id = (int) $visit['clinician_id'];
        $location_id  = (int) ($visit['location_id'] ?? 0);
        $default_row  = $this->pricing->default_fee($clinic_id, $clinician_id, $location_id);
        $override_row = $this->pricing->visit_override($visit_id);
        $default_fee  = $default_row !== null ? (int) $default_row['fee'] : null;
        $override_fee = $override_row !== null ? (int) $override_row['fee'] : null;
        $effective    = $override_fee ?? $default_fee;

        $charges       = [];
        $charges_total = 0;
        $can_edit      = $this->draft_editable($ctx, $visit);
        foreach ($this->pricing->charges_for_visit($clinic_id, $visit_id) as $row) {
            $line   = (int) round((float) $row['quantity'] * (int) $row['unit_amount']);
            $charges_total += $line;
            $charges[] = [
                'id'                  => (int) $row['id'],
                'description'         => (string) $row['description'],
                'unit_amount'         => (int) $row['unit_amount'],
                'quantity'            => (float) $row['quantity'],
                'line_total'          => $line,
                'note'                => $row['note'] !== null ? (string) $row['note'] : null,
                'invoiced_invoice_id' => $row['invoiced_invoice_id'] !== null ? (int) $row['invoiced_invoice_id'] : null,
                'editable'            => $can_edit,
            ];
        }

        $invoice = $this->invoices->activeForVisit($visit_id);
        $patient = $this->patients->find((int) $visit['patient_id']);
        $paid    = 0;
        $balance = 0;
        $status  = 'without_invoice';
        $invoice_view = null;
        if ($invoice !== null) {
            $paid    = (int) round((float) $invoice['paid_amount']);
            $balance = (int) round((float) $invoice['balance']);
            $status  = (string) $invoice['status'];
            $invoice_view = [
                'id'            => (int) $invoice['id'],
                'invoice_number' => (string) $invoice['invoice_number'],
                'status'        => $status,
                'subtotal'      => (int) round((float) $invoice['subtotal']),
                'total'         => (int) round((float) $invoice['total']),
                'paid_amount'   => $paid,
                'balance'       => $balance,
                'currency'      => (string) $invoice['currency'],
                'currency_label' => self::CURRENCY_LABEL,
                'items'         => array_map(static fn (array $item): array => [
                    'id'          => (int) $item['id'],
                    'description' => (string) $item['description'],
                    'quantity'    => (float) $item['quantity'],
                    'unit_price'  => (int) round((float) $item['unit_price']),
                    'amount'      => (int) round((float) $item['amount']),
                ], $this->invoices->itemsFor((int) $invoice['id'])),
                'payments'      => array_map(static fn (array $payment): array => [
                    'id'      => (int) $payment['id'],
                    'amount'  => (int) round((float) $payment['amount']),
                    'method'  => (string) $payment['method'],
                    'status'  => (string) $payment['status'],
                    'paid_at' => (string) $payment['paid_at'],
                ], $this->payments->forInvoice((int) $invoice['id'])),
            ];
        }

        return [
            'visit_id'           => $visit_id,
            'clinic_id'          => $clinic_id,
            'location_id'        => $location_id,
            'clinician_id'       => $clinician_id,
            'patient_name'       => $patient !== null ? trim((string) $patient['first_name'] . ' ' . (string) $patient['last_name']) : '',
            'visit_status'       => (string) $visit['status'],
            'currency'           => self::CURRENCY,
            'currency_label'     => self::CURRENCY_LABEL,
            'default_fee'        => $default_fee,
            'override_fee'       => $override_fee,
            'effective_fee'      => $effective,
            'fee_source'         => $override_fee !== null ? 'visit_override' : ($default_fee !== null ? 'doctor_default' : 'unset'),
            'consultation_label' => self::CONSULTATION_DESCRIPTION,
            'charges'            => $charges,
            'charges_total'      => $charges_total,
            'expected_total'     => ($effective ?? 0) + $charges_total,
            'editable'           => $can_edit,
            'invoice'            => $invoice_view,
            'paid_amount'        => $paid,
            'remaining_balance'  => $balance,
            'payment_status'     => $status,
            'payment_status_label' => $this->status_label($status),
            'can_edit_pricing'   => $can_edit && $this->doctor_owns_visit($actor_user_id, $clinic_id, $visit)
                && App::authorization_service()->can($actor_user_id, $clinic_id, self::WRITE_CAP),
        ];
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     * @param array<string, mixed> $visit
     */
    private function draft_editable(array $ctx, array $visit): bool
    {
        try {
            $this->assert_draft($ctx, $visit);
        } catch (FinanceException $exception) {
            return false;
        }

        return true;
    }

    private function doctor_owns_visit(int $actor_user_id, int $clinic_id, array $visit): bool
    {
        $clinician = $this->clinicians->find_by_wp_user($actor_user_id);
        if ($clinician === null || (int) $clinician['id'] !== (int) $visit['clinician_id']) {
            return false;
        }

        return $this->memberships->clinician_participates_in((int) $clinician['id'], $clinic_id)
            && App::authorization_service()->can($actor_user_id, $clinic_id, self::DOCTOR_CAP);
    }

    private function status_label(string $status): string
    {
        return match ($status) {
            'without_invoice' => 'بدون فاکتور',
            'open'            => 'پرداخت نشده',
            'partial'         => 'پرداخت جزئی',
            'paid'            => 'پرداخت شده',
            'voided'          => 'ابطال شده',
            default           => $status,
        };
    }

    private function amount(mixed $value): int
    {
        try {
            return MoneyInput::amount($value);
        } catch (DomainException $exception) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'مبلغ باید عدد صحیح غیرمنفی به ریال باشد', 422);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{description: string, amount: int, quantity: float, note: string|null}
     */
    private function charge_input(array $input): array
    {
        try {
            $description = MoneyInput::text($input['description'] ?? null, 255);
            $amount      = MoneyInput::amount($input['amount'] ?? ($input['unit_amount'] ?? null), true);
            $quantity    = MoneyInput::quantity($input['quantity'] ?? 1);
            $note        = MoneyInput::text($input['note'] ?? '', 500, false);
        } catch (DomainException $exception) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'شرح، مبلغ (ریال) یا تعداد خدمت نامعتبر است', 422);
        }
        if ((int) round($quantity * $amount) > MoneyInput::MAX_TOTAL) {
            throw FinanceException::of('CLINIC_VALIDATION_FAILED', 'مبلغ قلم خدمت خارج از محدوده مجاز است', 422);
        }

        return ['description' => $description, 'amount' => $amount, 'quantity' => $quantity, 'note' => $note === '' ? null : $note];
    }

    /**
     * @return list<int>|null
     */
    private function allowed_locations(int $clinic_id, int $actor_user_id): ?array
    {
        $membership = $this->memberships->find_active($clinic_id, $actor_user_id);
        if ($membership === null || (string) ($membership['scope_mode'] ?? 'clinic') !== 'location') {
            return null;
        }

        return array_values(array_map('intval', $this->memberships->location_ids_for((int) $membership['id'])));
    }

    /**
     * @param array{clinic_id: int, organization_id: int|null} $ctx
     */
    private function assert_location_allowed(array $ctx, int $actor_user_id, int $location_id): void
    {
        if ($location_id === 0) {
            return; // 0 is the clinic-wide fee sentinel, never a tenant selector.
        }
        $allowed = $this->allowed_locations($ctx['clinic_id'], $actor_user_id);
        if ($allowed !== null && !in_array($location_id, $allowed, true)) {
            throw FinanceException::of('CLINIC_PERMISSION_DENIED', 'دسترسی به این موقعیت عملیاتی ندارید', 403);
        }
    }

    private function operational_date(int $clinic_id): string
    {
        $timezone = (string) $this->db->fetchValue(
            'SELECT timezone FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d',
            [$clinic_id]
        );
        try {
            $zone = new \DateTimeZone($timezone !== '' ? $timezone : 'Asia/Tehran');
        } catch (Throwable) {
            $zone = new \DateTimeZone('Asia/Tehran');
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
    }

    /**
     * @return array{wp_user_id: int, role: string}
     */
    private function actor(int $user_id): array
    {
        $role = 'unknown';
        if (function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user !== false && $user !== null) {
                $role = (string) ($user->roles[0] ?? 'unknown');
            }
        }

        return ['wp_user_id' => $user_id, 'role' => $role];
    }
}

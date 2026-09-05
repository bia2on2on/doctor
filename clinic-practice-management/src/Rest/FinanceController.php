<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای مالی (F6) — API Contract D12–D18 + P3 (Refund) + G2 (تعرفه‌ها).
 *
 * مجوز (5 لایه — auth-authorization.md §2.1):
 *  1) Authentication/Nonce  2) Capability (ماتریس §7)  3) Data-Access (Service)
 *  4) Field-Access  5) Action Rules (InvoiceMachine/VisitMachine + InvoiceCalc).
 *
 * ماتریس:
 *  - invoice_create:  منشی/پزشک | payment_create: منشی | invoice_adjust: منشی
 *  - payment_void:    منشی/پزشک | payment_refund: منشی/پزشک
 *  - invoice_read:    منشی/پزشک | finance_read:   منشی/پزشک
 *  - config (تعرفه‌ها): فقط admin وردپرس (P-3 — فنی)
 *
 * D13 Idempotency (M-1/TP-02): هدر Idempotency-Key اجباری؛ اولین ثبت 201،
 * تکرار همان کلید = همان پاسخ با HTTP 200 + کد CLINIC_IDEMPOTENCY_REPLAY.
 */
final class FinanceController extends RestBase
{
    public function __construct(private readonly FinanceService $finance)
    {
    }

    public function register_routes(): void
    {
        // ================= D12 — صدور فاکتور =================
        register_rest_route(self::NS, '/invoices', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::INVOICE_CREATE,
                    fn () => $this->finance->issueInvoice($this->userId($r), $this->body($r)),
                    201
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'visit_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                    'items' => ['required' => true, 'type' => 'array', 'items' => ['type' => 'object']],
                    'discount' => ['required' => false, 'type' => 'number'],
                    'tax' => ['required' => false, 'type' => 'number'],
                ],
            ],
        ]);

        // ---------- خواندن فاکتور (UI تسویه + رسید) ----------
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::INVOICE_READ,
                    fn () => $this->finance->findInvoiceForActor($this->userId($r), (int) $r['id'])
                ),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ---------- فاکتور فعال ویزیت (UI تسویه: ویزیت→فاکتور) ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/invoice', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::INVOICE_READ,
                    fn () => $this->finance->invoiceForVisit($this->userId($r), (int) $r['id'])
                ),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ================= D13 — ثبت پرداخت (Idempotent) =================
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/payments', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => function (WP_REST_Request $r): WP_REST_Response|WP_Error {
                    $key = $this->idempotencyKey($r);
                    if ($key === null) {
                        return $this->error('CLINIC_VALIDATION_FAILED', 400, 'هدر Idempotency-Key (UUID) برای ثبت پرداخت الزامی است');
                    }

                    return $this->staff(
                        $r,
                        RolesAndCapabilities::PAYMENT_CREATE,
                        fn () => $this->finance->recordPayment($this->userId($r), (int) $r['id'], $this->body($r), $key),
                        null,
                        fn (array $data): int => empty($data['idempotent_replay']) ? 201 : 200
                    );
                },
                'permission_callback' => '__return_true',
                'args' => [
                    'amount' => ['required' => true, 'type' => 'number'],
                    'method' => ['required' => true, 'type' => 'string', 'enum' => ['cash', 'card_pos', 'online', 'other']],
                    'transaction_ref' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ================= D14 — ابطال پرداخت (همان روز) =================
        register_rest_route(self::NS, '/payments/(?P<id>\d+)/void', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::PAYMENT_VOID,
                    fn () => $this->finance->voidPayment($this->userId($r), (int) $r['id'], (string) $r->get_param('reason'))
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'reason' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        // ================= P3 — بازپرداخت =================
        register_rest_route(self::NS, '/payments/(?P<id>\d+)/refund', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::PAYMENT_REFUND,
                    fn () => $this->finance->refundPayment($this->userId($r), (int) $r['id'], (string) $r->get_param('reason'), $this->body($r))
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'reason' => ['required' => true, 'type' => 'string'],
                    'amount' => ['required' => false, 'type' => 'number'],
                ],
            ],
        ]);

        // ================= D15 — اصلاح (Credit/Debit) =================
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/adjustments', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::INVOICE_ADJUST,
                    fn () => $this->finance->addAdjustment($this->userId($r), (int) $r['id'], (string) $r['type'], $this->body($r))
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'type' => ['required' => true, 'type' => 'string', 'enum' => ['credit', 'debit']],
                    'amount' => ['required' => true, 'type' => 'number'],
                    'reason' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        // ================= D17 — رسید (Deterministic — M-5) =================
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/receipt', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::INVOICE_READ,
                    fn () => $this->finance->receipt($this->userId($r), (int) $r['id'])
                ),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ================= D18 — خلاصه مالی =================
        register_rest_route(self::NS, '/finance/summary', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::FINANCE_READ,
                    fn () => $this->finance->summary($this->userId($r), $r->get_param('from'), $r->get_param('to'))
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'from' => ['required' => false, 'type' => 'string', 'format' => 'date'],
                    'to' => ['required' => false, 'type' => 'string', 'format' => 'date'],
                ],
            ],
        ]);

        // ================= G2 — تعرفه‌ها (cpms_config — فقط admin) =================
        register_rest_route(self::NS, '/config/services', [
            [
                'methods' => WP_REST_Server::READABLE,
                // Cap در Service چک می‌شود (OR): cpms_invoice_read (فاکتورسازی)
                // یا cpms_config (مدیریت admin فنی — P-3)
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    null,
                    fn () => $this->finance->listServices($this->userId($r), $r->get_param('scope') !== 'all')
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'scope' => ['required' => false, 'type' => 'string', 'enum' => ['active', 'all'], 'default' => 'active'],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::CONFIG,
                    fn () => $this->finance->createService($this->userId($r), $this->body($r)),
                    201
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'code' => ['required' => true, 'type' => 'string'],
                    'name' => ['required' => true, 'type' => 'string'],
                    'price' => ['required' => true, 'type' => 'number'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/config/services/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::CONFIG,
                    fn () => $this->finance->updateService($this->userId($r), (int) $r['id'], $this->body($r))
                ),
                'permission_callback' => '__return_true',
                'args' => [
                    'code' => ['required' => false, 'type' => 'string'],
                    'name' => ['required' => false, 'type' => 'string'],
                    'price' => ['required' => false, 'type' => 'number'],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    RolesAndCapabilities::CONFIG,
                    fn () => $this->finance->deactivateService($this->userId($r), (int) $r['id'])
                ),
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    // ================= Helpers =================

    /**
     * Endpoint کارمند مطب — Nonce + Capability + Envelope خطای مالی.
     *
     * @template T
     *
     * @param callable(): T $fn
     * @param callable(array<string, mixed>): int|null $statusFn وضعیت پویا (D13: 201/200)
     */
    private function staff(
        WP_REST_Request $r,
        ?string $cap,
        callable $fn,
        ?int $status = null,
        ?callable $statusFn = null
    ): WP_REST_Response|WP_Error {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        if ($cap !== null) {
            $perm = $this->requireCap($cap);
            if ($perm instanceof WP_Error) {
                return $perm;
            }
        }

        try {
            $data = $fn();
            $http = $status ?? ($statusFn !== null ? $statusFn(is_array($data) ? $data : []) : 200);

            return $this->success($data, $http);
        } catch (FinanceException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (VisitException $e) {
            // Transitionهای واگذار‌شده به VisitService (V11/V12)
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][FinanceController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    private function userId(WP_REST_Request $r): int
    {
        return (int) (wp_get_current_user()->ID ?: 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $r): array
    {
        $params = $r->get_json_params();
        if (is_array($params)) {
            return $params;
        }
        $params = $r->get_params();

        return is_array($params) ? $params : [];
    }
}

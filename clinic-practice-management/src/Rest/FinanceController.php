<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Finance\FinanceException;
use ClinicCore\Application\Finance\FinanceService;
use ClinicCore\Application\Scope\ScopeContext;
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::INVOICE_CREATE),
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::INVOICE_READ),
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::INVOICE_READ),
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::PAYMENT_CREATE),
                'args' => [
                    'amount' => ['required' => true, 'type' => 'number'],
                    'method' => ['required' => true, 'type' => 'string', 'enum' => ['cash', 'card_pos', 'online', 'other']],
                    'transaction_ref' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ================= D14 — ابطال پرداخت (همان روز) =================
        // مجوزِ مرزِ این دو مسیر مالی حساس، Clinic-scoped است (نه Capability سراسریِ وردپرس):
        // گرنت/واگذاریِ عضویت باید کار کند؛ DENY صریح و تعلیق همان‌طور رد می‌شوند.
        $void_cap = RolesAndCapabilities::PAYMENT_VOID;
        register_rest_route(self::NS, '/payments/(?P<id>\d+)/void', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    // مجوزِ این Route دو بار enforce می‌شود: در همین مرز (perm_clinic_cap،
                    // Clinic-scoped) و در سرویس (requireCap + authorizeScoped). دروازهٔ
                    // Capability سراسریِ این wrapper نمی‌تواند گرنت/واگذاریِ عضویت را ببیند،
                    // پس اینجا حذف می‌شود؛ هیچ لایهٔ مجوزی بدون جانشین حذف نشده است.
                    null,
                    fn () => $this->finance->voidPayment($this->userId($r), (int) $r['id'], (string) $r->get_param('reason'))
                ),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->perm_clinic_cap( $r, $void_cap ),
                'args' => [
                    'reason' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        // ================= P3 — بازپرداخت =================
        // بازپرداخت — همان مرزِ Clinic-scoped (مالکیتِ تراکنش در سرویس enforce می‌شود).
        $refund_cap = RolesAndCapabilities::PAYMENT_REFUND;
        register_rest_route(self::NS, '/payments/(?P<id>\d+)/refund', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    // مجوزِ این Route دو بار enforce می‌شود: در همین مرز (perm_clinic_cap،
                    // Clinic-scoped) و در سرویس (requireCap + authorizeScoped). دروازهٔ
                    // Capability سراسریِ این wrapper نمی‌تواند گرنت/واگذاریِ عضویت را ببیند،
                    // پس اینجا حذف می‌شود؛ هیچ لایهٔ مجوزی بدون جانشین حذف نشده است.
                    null,
                    fn () => $this->finance->refundPayment($this->userId($r), (int) $r['id'], (string) $r->get_param('reason'), $this->body($r))
                ),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->perm_clinic_cap( $r, $refund_cap ),
                'args' => [
                    'reason' => ['required' => true, 'type' => 'string'],
                    'amount' => ['required' => false, 'type' => 'number'],
                ],
            ],
        ]);

        // ================= D15 — اصلاح (Credit/Debit) =================
        // اصلاح فاکتور نیز مثل void/refund یک عملیات مالی حساسِ قابل واگذاری است:
        // مجوز در همین مرز Clinic-scoped است، نه Capability سراسریِ وردپرس.
        $adjust_cap = RolesAndCapabilities::INVOICE_ADJUST;
        register_rest_route(self::NS, '/invoices/(?P<id>\d+)/adjustments', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff(
                    $r,
                    // مجوز دو بار enforce می‌شود: مرزِ perm_clinic_cap و سرویس
                    // (requireCap + authorizeScoped). دروازهٔ سراسریِ این wrapper
                    // گرنت/واگذاریِ عضویت را نمی‌بیند؛ بدون جانشین حذف نشده است.
                    null,
                    fn () => $this->finance->addAdjustment($this->userId($r), (int) $r['id'], (string) $r['type'], $this->body($r))
                ),
                'permission_callback' => fn ( WP_REST_Request $r ) => $this->perm_clinic_cap( $r, $adjust_cap ),
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::INVOICE_READ),
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::FINANCE_READ),
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
                'permission_callback' => fn (WP_REST_Request $r) => $this->permAuthenticated($r),
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
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::CONFIG),
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
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::CONFIG),
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
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::CONFIG),
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
            $message = $e->getMessage();
            /*
             * C9 — بومی‌سازی فقط در مرز ارائه؛ تکنیکِ **گذرا و محدودشده** برای همین دو
             * پیامِ معرفی‌شده توسط C7 (نه معماریِ مطلوبِ بلندمدت).
             *
             * کدِ `CLINIC_SCOPE_REQUIRED` در این مرز یکتا **نیست** — مثلاً
             * `FinanceService::trustedClinicId()` (مسیر listServices/summary) همان کد را با
             * پیامِ **پویای** `SystemClinicResolver` باز‑نگاشت می‌کند. پس این واریانتِ C7 با
             * «کدِ پایدار + خودِ پیامِ منبع» تشخیص داده می‌شود، نه با شکلِ `data` (چون
             * پیش‌فرضِ `FinanceException::$data` همان `[]` است و شناسهٔ معنایی نیست).
             * هر واریانتِ دیگری دست‌نخورده عبور می‌کند؛ اگر literal سرویس عوض شود شرط
             * برقرار نمی‌شود و پیامِ واقعیِ سرویس عبور می‌کند (fail-safe).
             *
             * msgid عمدتاً literal است (قاعدهٔ `WordPress.WP.I18n` در WPCS) و دامنهٔ آن
             * `cpms`. چون افزونه هیچ کاتالوگ `cpms` بارگذاری نمی‌کند، خروجی پیش‌فرض همان
             * متن فارسیِ منبع است (بدون هیچ تغییر رفتاری).
             */
            if (
                $e->errorCode === 'CLINIC_SCOPE_REQUIRED'
                && $message === 'عملیات حساس مالی بدون زمینهٔ کلینیک معتبر مجاز نیست — Clinic از شیء هدف استخراج نمی‌شود.'
            ) {
                $message = __('عملیات حساس مالی بدون زمینهٔ کلینیک معتبر مجاز نیست — Clinic از شیء هدف استخراج نمی‌شود.', 'cpms');
            }

            return $this->error($e->errorCode, $e->httpStatus, $message, $e->data);
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

    /**
     * Nonce + مجوزِ Clinic-scoped برای مسیرهای حساس مالی (void/refund).
     *
     *  - نخست موتور مجوزِ Clinic-scoped روی Scopeِ مورد اعتماد تصمیم می‌گیرد
     *    (`AuthorizationService::can`): عضویتِ فعال، DENY صریح > Grant صریح > Preset.
     *    بدون این لایه، گرنت/واگذاریِ عضویت‌محور هرگز به سرویس نمی‌رسید.
     *  - اگر آن لایه مجاز نکند، لایهٔ خشنِ سراسریِ WordPress عیناً مثل قبل
     *    ارزیابی می‌شود (سازگاریِ عقب‌رو با نصب‌های موجود و پاسخِ 403 پیشین).
     *  - Clinic از هدر خوانده نمی‌شود: Scopeِ مورد اعتماد پیش از
     *    permission_callback در `RestClinicContext` مستقر شده است. نبودِ آن ⇒
     *    همان gating رد می‌کند و مسیرِ کانونیِ سرویس پاسخ می‌دهد
     *    (CLINIC_SCOPE_REQUIRED) — هرگز Clinic از شیءِ هدف وام گرفته نمی‌شود.
     */
    private function perm_clinic_cap( WP_REST_Request $r, string $capability ): bool|WP_Error {
        $nonce = $this->requireNonce( $r );
        if ( $nonce instanceof WP_Error ) {
            return $nonce;
        }

        if ( $this->has_effective_clinic_capability( $capability ) ) {
            return true;
        }

        $global_check = $this->requireCap( $capability );

        return $global_check instanceof WP_Error
            ? $this->error( 'CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید' )
            : true;
    }

    /**
     * آیا Capability در Clinicِ Scopeِ مورد اعتماد برای کاربر جاری مؤثر است؟
     */
    private function has_effective_clinic_capability( string $capability ): bool {
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return false;
        }

        $scope_context = ScopeContext::tryGet();
        if ( $scope_context === null ) {
            return false;
        }

        $clinic_id = (int) $scope_context->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.
        if ( $clinic_id <= 0 ) {
            return false;
        }

        return App::authorization_service()->can( $user_id, $clinic_id, $capability );
    }
}

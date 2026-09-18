<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای مدیریت برنامه کاری (F3) — API Contract G1 (+ توسعه Additive برای
 * استثنائات SRS FR-3.2 — ثبت‌شده در Contract v1.1 و گزارش F3).
 *
 * مجوز: Capability `cpms_config` (Admin فنی — Matrix §4.4) + Nonce روی موتانت‌ها.
 * داده‌ها فنی‌اند — بدون PHI.
 *
 * Phase 3 Slice 6B: هر هفت مسیر (خواندن/نوشتن برنامه و استثناها) علاوه بر
 * لایه‌های قبلی، مجوز Clinic-scoped `cpms_config` را از طریق
 * RestBase::requireClinicPermission می‌گیرند — عضویت فعال پایدار در Clinicِ
 * معتبرِ درخواست + دقیقاً همان مجوز؛ cap سراسری فقط defense-in-depth.
 * مالکیت پایدارِ پزشک/برنامه در برابر Clinic معتبر در ScheduleService اعمال
 * می‌شود (C7-S2/S5 — 404 parity).
 *
 * خطاها: Envelope استاندارد `CLINIC_*` (ADR-0019).
 */
final class ScheduleController extends RestBase
{
    public function __construct(private readonly ScheduleService $schedules)
    {
    }

    public function register_routes(): void
    {
        // ---------- G1 — Weekly Schedule ----------
        register_rest_route(self::NS, '/config/schedules', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->list($request),
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::CONFIG),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->create($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'day_of_week' => ['required' => true, 'type' => 'integer'],
                    'start_time' => ['required' => true, 'type' => 'string'],
                    'end_time' => ['required' => true, 'type' => 'string'],
                    /*
                     * Phase 6 Slice 3: در قراردادِ مدرن، create برنامه Location
                     * صریح می‌خواهد. الزامی‌شدن در لایهٔ Service اعمال می‌شود
                     * (نه `required => true` در REST) تا ترتیبِ خطاها حفظ بماند:
                     * پزشک بیگانه/ناموجود همچنان 404-parity می‌گیرد (پیش از هر
                     * بررسی فیلدی) و Location نامعتبر/ناموجود/بیگانه 404
                     * «محل یافت نشد» — نه 400ِ زودهنگامِ route.
                     */
                    'location_id' => ['required' => false, 'type' => 'integer'],
                    'break_start' => ['required' => false, 'type' => 'string'],
                    'break_end' => ['required' => false, 'type' => 'string'],
                    'appointment_duration_min' => ['required' => false, 'type' => 'integer'],
                    'slot_capacity' => ['required' => false, 'type' => 'integer'],
                    'is_active' => ['required' => false, 'type' => 'boolean'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/config/schedules/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $request) => $this->update($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                    'start_time' => ['required' => false, 'type' => 'string'],
                    'end_time' => ['required' => false, 'type' => 'string'],
                    'break_start' => ['required' => false, 'type' => 'string'],
                    'break_end' => ['required' => false, 'type' => 'string'],
                    'appointment_duration_min' => ['required' => false, 'type' => 'integer'],
                    'slot_capacity' => ['required' => false, 'type' => 'integer'],
                    'is_active' => ['required' => false, 'type' => 'boolean'],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => fn (WP_REST_Request $request) => $this->delete($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);

        // ---------- G1b — Schedule Exceptions (SRS FR-3.2) ----------
        register_rest_route(self::NS, '/config/schedule-exceptions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->listExceptions($request),
                'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::CONFIG),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'from' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d')],
                    'to' => ['required' => false, 'type' => 'string', 'default' => gmdate('Y-m-d', time() + 90 * 86400)],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->createException($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'date' => ['required' => true, 'type' => 'string'],
                    'type' => ['required' => true, 'type' => 'string', 'enum' => ['holiday', 'leave', 'blocked', 'open_override']],
                    'start_time' => ['required' => false, 'type' => 'string'],
                    'end_time' => ['required' => false, 'type' => 'string'],
                    'reason' => ['required' => false, 'type' => 'string'],
                    /*
                     * Phase 6 Slice 6: Location اختیاریِ استثنا (قرارداد Migration
                     * 0015) — غایب/تهی یعنی «همهٔ محل‌ها» (NULL) و مقدار یعنی
                     * فقط همان Location. الزامی‌نبودن در route عمدی است (الگوی
                     * Slice 3): قرارداد در Service اعمال می‌شود تا ترتیبِ خطاها و
                     * پاریتِ not-found حفظ بماند — بیگانه/ناموجود/غیرفعال همه
                     * 404 «محل یافت نشد» می‌گیرند، نه 400ِ زودهنگامِ route.
                     */
                    'location_id' => ['required' => false, 'type' => 'integer'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/config/schedule-exceptions/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => fn (WP_REST_Request $request) => $this->deleteException($request),
                'permission_callback' => fn (WP_REST_Request $request) => $this->configMutation($request),
                'args' => [
                    'id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);
    }

    // ---------- Handlers ----------

    private function list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }

        return $this->wrap(fn () => $this->schedules->list((int) $request->get_param('clinician_id')));
    }

    private function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->create((int) $user->ID, $this->body($request)));
    }

    private function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->update(
            (int) $user->ID,
            (int) $request->get_param('id'),
            $this->body($request)
        ));
    }

    private function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->delete((int) $user->ID, (int) $request->get_param('id')));
    }

    private function listExceptions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }

        return $this->wrap(fn () => $this->schedules->listExceptions(
            (int) $request->get_param('clinician_id'),
            (string) $request->get_param('from'),
            (string) $request->get_param('to')
        ));
    }

    private function createException(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->createException((int) $user->ID, $this->body($request)));
    }

    private function deleteException(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->requireClinicPermission(RolesAndCapabilities::CONFIG);
        if ($denied instanceof WP_Error) {
            return $denied;
        }
        $user = wp_get_current_user();

        return $this->wrap(fn () => $this->schedules->deleteException((int) $user->ID, (int) $request->get_param('id')));
    }

    // ---------- Helpers ----------

    /**
     * موتانت Config: Capability `cpms_config` + Nonce (CSRF).
     */
    private function configMutation(WP_REST_Request $request): bool|WP_Error
    {
        $nonceError = $this->requireNonce($request);
        if ($nonceError instanceof WP_Error) {
            return $nonceError;
        }

        return $this->requireCap(RolesAndCapabilities::CONFIG);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $params = $request->get_params();

        return is_array($params) ? $params : [];
    }

    /**
     * Wrap استاندارد: BookingException → WP_Error با کد CLINIC_* + HTTP.
     *
     * @template T
     * @param callable(): T $fn
     * @return WP_REST_Response|WP_Error
     */
    private function wrap(callable $fn): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn(), 200);
        } catch (BookingException $e) {
            $message = $e->getMessage();
            /*
             * C9 — بومی‌سازی فقط در مرز ارائه (نه در Application/Domain).
             *
             * در این مرز تنها منبعِ `CLINIC_SCOPE_REQUIRED` همان گارد fail-closedِ
             * `ScheduleService::requireClinicianForTrustedClinic()` است (C7-S5)، پس
             * خودِ کد برای تشخیصِ این واریانت کافی است. هر پیامِ دیگری بدون تغییر و
             * بایت‌به‌بایت عبور می‌کند؛ `code`/`httpStatus`/`data` دست‌نخورده می‌مانند.
             *
             * msgid عمدتاً literal است (قاعدهٔ `WordPress.WP.I18n` در WPCS) و دامنهٔ
             * آن `cpms` — همان دامنهٔ هدر افزونه و الگوی مستقر `RestClinicContext`.
             * چون افزونه هیچ کاتالوگ `cpms` بارگذاری نمی‌کند، خروجی پیش‌فرض همان
             * متن فارسیِ منبع است (بدون هیچ تغییر رفتاری).
             */
            if ($e->errorCode === 'CLINIC_SCOPE_REQUIRED') {
                $message = __('عملیات برنامهٔ هفتگی بدون زمینهٔ کلینیک معتبر مجاز نیست', 'cpms');
            }

            return $this->error($e->errorCode, $e->httpStatus, $message, $e->data);
        }
    }
}

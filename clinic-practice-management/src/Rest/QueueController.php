<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Visits\VisitService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Visits\VisitException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای مراجعه/صف (F4) — API Contract D1/D6/D7/D8/D16 + E1–E6 + R1.
 *
 * مجوز (5 لایه — auth-authorization.md §2.1):
 *  1) Authentication/Nonce  2) Capability/Role  3) Data-Access (Service)
 *  4) Field-Access (presentVisit)  5) Action Rules (VisitMachine).
 *
 * R1 (ADR-0007): Controlled Polling — ETag = last_event_id؛ بدون تغییر → 304
 * (Body خالی)؛ سرور فقط رویدادهای بعد از `since` برمی‌گرداند (Light).
 */
final class QueueController extends RestBase
{
    /** D8: نگاشت to_status منشی → Event ماشین (Transitionهای مجاز منشی). */
    private const SECRETARY_STATUS_EVENTS = [
        'waiting' => 'enqueue',
        'cancelled' => 'cancel',
        'awaiting_payment' => 'invoice_ready',
        'checked_out' => 'waive',
    ];

    /** E3–E6: اکشنهای صف پزشک → Event ماشین (E14 complete از F5 در ClinicalController است). */
    private const DOCTOR_EVENTS = ['call', 'recall', 'start', 'skip'];

    public function __construct(private readonly VisitService $visits)
    {
    }

    public function register_routes(): void
    {
        // ---------- D1 — داشبورد منشی ----------
        register_rest_route(self::NS, '/secretary/today', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_READ, fn () => $this->visits->today($this->userId($r))),
                'permission_callback' => '__return_true',
                'args' => [
                    'clinician_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        // ---------- D6 — Check-in ----------
        register_rest_route(self::NS, '/visits/checkin', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_CHECKIN, function () use ($r): array {
                    return $this->visits->checkIn(
                        $this->userId($r),
                        (int) $r['patient_id'],
                        (int) $r['appointment_id'],
                        ['note' => $r['note'] ?? null]
                    );
                }),
                'permission_callback' => '__return_true',
                'args' => [
                    'patient_id' => ['required' => true, 'type' => 'integer'],
                    'appointment_id' => ['required' => true, 'type' => 'integer'],
                    'note' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- D7 — Walk-in ----------
        register_rest_route(self::NS, '/visits/walk-in', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_CHECKIN, function () use ($r): array {
                    return $this->visits->walkIn(
                        $this->userId($r),
                        (int) $r['patient_id'],
                        (int) $r['clinician_id'],
                        ['note' => $r['note'] ?? null]
                    );
                }),
                'permission_callback' => '__return_true',
                'args' => [
                    'patient_id' => ['required' => true, 'type' => 'integer'],
                    'clinician_id' => ['required' => true, 'type' => 'integer'],
                    'note' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- D8 — Transition منشی ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/status', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_ADVANCE, function () use ($r): array {
                    $toStatus = (string) $r['to_status'];
                    $event = self::SECRETARY_STATUS_EVENTS[$toStatus] ?? null;
                    if ($event === null) {
                        throw VisitException::of(
                            'CLINIC_VALIDATION_FAILED',
                            'وضعیت هدف برای منشی مجاز نیست (waiting, cancelled, awaiting_payment, checked_out)',
                            422,
                            ['to_status' => $toStatus]
                        );
                    }

                    return $this->visits->transition(
                        $this->userId($r),
                        (int) $r['id'],
                        $event,
                        ['reason' => $r['note'] ?? $r['reason'] ?? null, 'note' => $r['note'] ?? null]
                    );
                }),
                'permission_callback' => '__return_true',
                'args' => [
                    'to_status' => ['required' => true, 'type' => 'string'],
                    'note' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- D16 — Checkout ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/checkout', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_CHECKOUT, fn () => $this->checkout($r)),
                'permission_callback' => '__return_true',
                'args' => [
                    'waive_invoice' => ['required' => false, 'type' => 'object'],
                ],
            ],
        ]);

        // ---------- E1 — داشبورد پزشک ----------
        register_rest_route(self::NS, '/doctor/today', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_READ, fn () => $this->visits->today($this->userId($r))),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ---------- E2 — صف ----------
        register_rest_route(self::NS, '/queue', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, RolesAndCapabilities::QUEUE_READ, function () use ($r): array {
                    $clinicianId = isset($r['clinician_id']) ? (int) $r['clinician_id'] : null;

                    return $this->visits->today($this->userId($r), $clinicianId);
                }),
                'permission_callback' => '__return_true',
                'args' => [
                    'clinician_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        // ---------- E3–E6 — اکشنهای پزشک ----------
        foreach (self::DOCTOR_EVENTS as $event) {
            register_rest_route(self::NS, '/visits/(?P<id>\d+)/' . $event, [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => fn (WP_REST_Request $r) => $this->doctorAction($r, $event),
                    'permission_callback' => '__return_true',
                    'args' => [
                        'reason' => ['required' => false, 'type' => 'string'], // skip الزامی — Service چک می‌کند
                        'room' => ['required' => false, 'type' => 'string'],
                    ],
                ],
            ]);
        }

        // ---------- R1 — Real-time Feed (ADR-0007) ----------
        register_rest_route(self::NS, '/rt/queue', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->rtQueue($r),
                'permission_callback' => '__return_true',
                'args' => [
                    'since' => ['required' => false, 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);
    }

    // ================= Handlers =================

    /**
     * E3–E6: call/recall/start/skip — Capability متمایز هر اکشن.
     *
     * E14 (complete) از F5 به ClinicalController منتقل شده (Validation بالینی
     * FR-8.7)؛ اینجا فقط اکشن‌های صف اعمال می‌شوند.
     */
    private function doctorAction(WP_REST_Request $r, string $event): WP_REST_Response|WP_Error
    {
        $cap = match ($event) {
            'start' => RolesAndCapabilities::CONSULT_START,
            default => RolesAndCapabilities::QUEUE_CALL, // call/recall/skip
        };

        return $this->guard($r, $cap, fn () => $this->visits->transition(
            $this->userId($r),
            (int) $r['id'],
            $event,
            ['reason' => $r['reason'] ?? null, 'room' => $r['room'] ?? null]
        ));
    }

    /**
     * D16: paid → check_out (V14)؛ awaiting_payment + waive → waive (V13)؛
     * بدون پرداخت/معافیت → CLINIC_POLICY_VIOLATION (فاکتور/پرداخت واقعی = F6).
     */
    private function checkout(WP_REST_Request $r): array
    {
        $waive = $r['waive_invoice'] ?? null;
        $waiveReason = is_array($waive) && isset($waive['reason']) ? (string) $waive['reason'] : null;

        return $this->visits->checkout($this->userId($r), (int) $r['id'], $waiveReason);
    }

    /**
     * R1: ETag/304 — اگر کلاینت ETag آخرین رویداد را دارد → 304 بدون Body.
     */
    private function rtQueue(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $guard = $this->guard($r, RolesAndCapabilities::QUEUE_READ, null);
        if ($guard instanceof WP_Error) {
            return $guard;
        }

        // Polling Guard (ADR-0007 — uncontrolled polling ممنوع):
        // منشی 3s → 20/min؛ سقف با حاشیه = 60/min
        $rl = $this->rateLimit($r, 'rt_queue_' . $this->userId($r), 60, MINUTE_IN_SECONDS);
        if ($rl instanceof WP_Error) {
            return $rl;
        }

        $userId = $this->userId($r);
        $since = (int) ($r['since'] ?? 0);

        // ETag = آخرین event_id کلینیک — بدون تغییر → 304 (کاهش بار)
        $lastEventId = $this->visits->lastEventId($userId);
        $etag = '"' . $lastEventId . '"';
        $ifNoneMatch = trim((string) $r->get_header('If-None-Match'));
        if ($ifNoneMatch === $etag) {
            $notModified = new WP_REST_Response(null, 304);
            $notModified->header('ETag', $etag);

            return $notModified;
        }

        $payload = $this->visits->eventsSince($userId, $since);
        $response = $this->success($payload);
        $response->header('ETag', $etag);

        return $response;
    }

    // ================= Helpers =================

    private function userId(WP_REST_Request $r): int
    {
        return (int) (wp_get_current_user()->ID ?: 0);
    }

    /**
     * Guard استاندارد: Nonce → Capability → اجرا؛ VisitException → WP_Error.
     *
     * @template T
     * @param callable(): T $fn
     * @return WP_REST_Response|WP_Error
     */
    private function guard(WP_REST_Request $r, string $cap, ?callable $fn): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap($cap);
        if ($perm instanceof WP_Error) {
            return $perm;
        }
        if ($fn === null) {
            return $this->success(null);
        }

        try {
            return $this->success($fn(), 200);
        } catch (VisitException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            // Fallback غیرمنتظره (اتخاذ از Audit Agent-2): Envelope خطای داخلی
            // استاندارد — جزئیات Exception هرگز به کلاینت نشت نمی‌کند؛ فقط کلاس
            // برای Debug لاگ‌شده در سمت سرور (Error Log استاندارد PHP/WP).
            error_log('[CPMS][QueueController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());
            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }
}

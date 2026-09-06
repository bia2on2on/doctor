<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Clinical\ClinicalException;
use ClinicCore\Application\Clinical\ClinicalService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Visits\VisitException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای بالینی (F5) — API Contract E7–E15 (پزشک) + C5/C6/C7 (بیمار).
 *
 * مجوز (5 لایه — auth-authorization.md §2.1):
 *  1) Authentication/Nonce  2) Capability  3) Data-Access (Service: Ownership/
 *     Visibility)  4) Field-Access (presenters)  5) Action Rules (ماشین‌ها).
 *
 * E14 (complete) از QueueController به این‌جا منتقل شد: از F5 Validation
 * بالینی (FR-8.7) قبل از Transition اجرا می‌شود.
 */
final class ClinicalController extends RestBase
{
    public function __construct(private readonly ClinicalService $clinical)
    {
    }

    public function register_routes(): void
    {
        // ================= Doctor (E7–E15) =================

        // ---------- E7 — پرونده کامل ویزیت ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/record', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::MEDICAL_READ,
                    fn () => $this->clinical->record($this->userId($r), (int) $r['id'])),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ---------- E8 — ثبت یادداشت ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/notes', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::NOTE_CREATE,
                    fn () => $this->clinical->addNote($this->userId($r), (int) $r['id'], $this->body($r))),
                'permission_callback' => '__return_true',
                'args' => [
                    'category' => ['required' => true, 'type' => 'string'],
                    'visibility' => ['required' => false, 'type' => 'string', 'default' => 'patient_visible'],
                    'content_text' => ['required' => true, 'type' => 'string'],
                    'change_reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- E9 — Correction یادداشت (نسخه جدید) ----------
        register_rest_route(self::NS, '/notes/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::NOTE_UPDATE,
                    fn () => $this->clinical->updateNote($this->userId($r), (int) $r['id'], $this->body($r))),
                'permission_callback' => '__return_true',
                'args' => [
                    'content_text' => ['required' => true, 'type' => 'string'],
                    'change_reason' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- E10 — نسخه Draft ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/prescriptions', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::RX_CREATE,
                    fn () => $this->clinical->createPrescription($this->userId($r), (int) $r['id'], $this->body($r))),
                'permission_callback' => '__return_true',
                'args' => [
                    'items' => ['required' => true, 'type' => 'array', 'items' => ['type' => 'object']],
                    'is_patient_visible' => ['required' => false, 'type' => 'boolean', 'default' => true],
                    'correction_of_prescription_id' => ['required' => false, 'type' => 'integer'],
                ],
            ],
        ]);

        // ---------- E11 — نهایی‌سازی نسخه ----------
        register_rest_route(self::NS, '/prescriptions/(?P<id>\d+)/finalize', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::RX_CREATE,
                    fn () => $this->clinical->finalizePrescription($this->userId($r), (int) $r['id'])),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ---------- E12 — توصیه‌ها ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/recommendations', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::REC_CREATE,
                    fn () => $this->clinical->addRecommendations($this->userId($r), (int) $r['id'], $this->body($r))),
                'permission_callback' => '__return_true',
                'args' => [
                    'items' => ['required' => true, 'type' => 'array', 'items' => ['type' => 'object']],
                ],
            ],
        ]);

        // ---------- E13 — Follow-up ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/follow-ups', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::REC_CREATE,
                    fn () => $this->clinical->addFollowUp($this->userId($r), (int) $r['id'], $this->body($r))),
                'permission_callback' => '__return_true',
                'args' => [
                    'is_needed' => ['required' => false, 'type' => 'boolean', 'default' => true],
                    'suggested_date' => ['required' => false, 'type' => 'string'],
                    'interval_days' => ['required' => false, 'type' => 'integer'],
                    'reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- E14 — پایان ویزیت (Validation بالینی + V10) ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/complete', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::CONSULT_COMPLETE,
                    fn () => $this->clinical->completeConsultation($this->userId($r), (int) $r['id'])),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ---------- E15 — بازگشایی (Correction — مجوز بالا + دلیل) ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)/reopen', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->doctor($r, RolesAndCapabilities::CONSULT_REOPEN,
                    fn () => $this->clinical->reopenConsultation($this->userId($r), (int) $r['id'], (string) ($r['reason'] ?? ''))),
                'permission_callback' => '__return_true',
                'args' => [
                    'reason' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);

        // ================= Patient (C5–C7) — Ownership در Service =================

        // ---------- C5 — تاریخچه ویزیت‌های من ----------
        register_rest_route(self::NS, '/visits', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->patient($r,
                    fn () => $this->clinical->patientVisits($this->userId($r), $r['from'] ?? null, $r['to'] ?? null)),
                'permission_callback' => '__return_true',
                'args' => [
                    'from' => ['required' => false, 'type' => 'string'],
                    'to' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        // ---------- C6 — جزئیات ویزیت من (نمای مجاز) ----------
        register_rest_route(self::NS, '/visits/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->patient($r,
                    fn () => $this->clinical->patientVisitDetail($this->userId($r), (int) $r['id'])),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ---------- C7 — نسخه‌های من ----------
        register_rest_route(self::NS, '/prescriptions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->patient($r,
                    fn () => $this->clinical->patientPrescriptions($this->userId($r))),
                'permission_callback' => '__return_true',
            ],
        ]);

        // ================= E18 — جستجوی جامع Role-Aware =================
        // cpms_search: منشی + پزشک؛ نتایج note/rx فقط پزشک (ماتریس §4 — Service
        // فیلتر می‌کند، منشی فقط بیمار می‌بیند → TP-08 در جستجو هم برقرار است).
        register_rest_route(self::NS, '/search', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::SEARCH,
                    fn () => $this->clinical->globalSearch(
                        $this->userId($r),
                        (string) $r['q'],
                        (string) ($r['type'] ?? 'all'),
                        $r['from'] ?? null,
                        $r['to'] ?? null
                    )),
                'permission_callback' => '__return_true',
                'args' => [
                    'q' => ['required' => true, 'type' => 'string'],
                    'type' => [
                        'required' => false,
                        'type' => 'string',
                        'enum' => ['patient', 'note', 'rx', 'all'],
                        'default' => 'all',
                    ],
                    'from' => ['required' => false, 'type' => 'string'],
                    'to' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);
    }

    // ================= Guards =================

    /**
     * Endpoint کارمند (پزشک/منشی) — Nonce + Capability؛ نقش در Service
     * فیلتر نتایج را تعیین می‌کند (E18: منشی فقط بیمار، پزشک همه).
     *
     * @template T
     *
     * @param callable(): T $fn
     */
    private function staff(WP_REST_Request $r, string $cap, callable $fn): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap($cap);
        if ($perm instanceof WP_Error) {
            return $perm;
        }

        return $this->wrap($fn);
    }

    /**
     * Endpoint پزشک — Nonce + Capability + Envelope خطای بالینی.
     *
     * @template T
     *
     * @param callable(): T $fn
     */
    private function doctor(WP_REST_Request $r, string $cap, callable $fn): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap($cap);
        if ($perm instanceof WP_Error) {
            return $perm;
        }

        return $this->wrap($fn);
    }

    /**
     * Endpoint بیمار — Nonce + نقش بیمار (بدون Capability — P-5)؛
     * Ownership در Service (P-8) بررسی می‌شود.
     *
     * @template T
     *
     * @param callable(): T $fn
     */
    private function patient(WP_REST_Request $r, callable $fn): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید');
        }
        if (!in_array(RolesAndCapabilities::ROLE_PATIENT, (array) $user->roles, true)) {
            App::audit()->log(
                'FORBIDDEN_ACCESS_ATTEMPT',
                ['wp_user_id' => (int) $user->ID, 'role' => $user->roles[0] ?? 'unknown'],
                'clinical',
                null,
                null,
                null,
                null,
                ['reason' => 'patient_role_required']
            );

            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
        }

        return $this->wrap($fn);
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     */
    private function wrap(callable $fn): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($fn(), 200);
        } catch (ClinicalException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (VisitException $e) {
            // Transitionهای واگذار‌شده به VisitService (E14/E15)
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][ClinicalController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    // ================= Helpers =================

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

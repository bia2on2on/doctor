<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Handwriting\HandwritingException;
use ClinicCore\Application\Handwriting\HandwritingService;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Endpointهای دست‌خط پزشک (F7 — F1/F1b/F1c/F2/F3).
 *
 * مجوزها (permission-matrix §4.3):
 *  - نوشتن (F1/F1c/F2): `cpms_note_create` + مالکیت ویزیت (در Service).
 *  - خواندن (F1b/F3):   `cpms_medical_read` + مالکیت ویزیت (در Service).
 *
 * F2 (Save): `Idempotency-Key` الزامی است (Contract §0) — رترای همان
 * Save پاسخ ذخیره‌شده را برمی‌گرداند بدون version bump (ADR-0014).
 */
final class HandwritingController extends RestBase
{
    public function __construct(private readonly HandwritingService $handwriting)
    {
    }

    public function register_routes(): void
    {
        // ---------- F1 — ایجاد سند برای ویزیت ----------
        register_rest_route(self::NS, '/handwriting/documents', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::NOTE_CREATE, fn () => $this->handwriting->createDocument(
                    $this->userId(),
                    (int) $r['visit_id'],
                    isset($r['title']) ? (string) $r['title'] : null,
                    is_array($r['pages'] ?? null) ? array_values((array) $r['pages']) : []
                ), 201),
                'permission_callback' => '__return_true',
                'args' => [
                    'visit_id' => ['required' => true, 'type' => 'integer'],
                    'title' => ['required' => false, 'type' => 'string'],
                    'pages' => ['required' => false, 'type' => 'array'],
                ],
            ],
            // ---------- F1b — سند ویزیت (بازکردن مجدد) ----------
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::MEDICAL_READ, fn () => $this->handwriting->listDocuments(
                    $this->userId(),
                    (int) $r['visit_id']
                )),
                'permission_callback' => '__return_true',
                'args' => [
                    'visit_id' => ['required' => true, 'type' => 'integer'],
                ],
            ],
        ]);

        // ---------- F1c — افزودن صفحه ([+ صفحه]) ----------
        register_rest_route(self::NS, '/handwriting/documents/(?P<id>\d+)/pages', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::NOTE_CREATE, fn () => $this->handwriting->addPage(
                    $this->userId(),
                    (int) $r['id'],
                    $this->body($r)
                ), 201),
                'permission_callback' => '__return_true',
                'args' => [
                    'width' => ['required' => false, 'type' => 'integer'],
                    'height' => ['required' => false, 'type' => 'integer'],
                    'background_template' => ['required' => false, 'type' => 'string', 'default' => 'lined'],
                    'background_attachment_id' => ['required' => false, 'type' => 'integer'],
                ],
            ],
        ]);

        // ---------- F3 + F2 — خواندن/ذخیره صفحه ----------
        register_rest_route(self::NS, '/handwriting/pages/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->staff($r, RolesAndCapabilities::MEDICAL_READ, fn () => $this->handwriting->getPage(
                    $this->userId(),
                    (int) $r['id']
                )),
                'permission_callback' => '__return_true',
                'args' => [],
            ],
            [
                // F2 — ذخیره (Revision + Idempotency)
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => fn (WP_REST_Request $r) => $this->save($r),
                'permission_callback' => '__return_true',
                'args' => [
                    'client_revision' => ['required' => true, 'type' => 'integer'],
                    'stroke_data' => ['required' => true, 'type' => 'string'],
                    'width' => ['required' => false, 'type' => 'integer'],
                    'height' => ['required' => false, 'type' => 'integer'],
                    'background_template' => ['required' => false, 'type' => 'string'],
                    'background_attachment_id' => ['required' => false, 'type' => 'integer'],
                    'saved_by' => ['required' => false, 'type' => 'string', 'default' => 'autosave'],
                    'conflict_reason' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);
    }

    /**
     * PUT /handwriting/pages/{id} — Idempotency-Key الزامی (Contract §0).
     */
    private function save(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap(RolesAndCapabilities::NOTE_CREATE);
        if ($perm instanceof WP_Error) {
            return $perm;
        }

        $key = $this->idempotencyKey($r);
        if ($key === null) {
            return $this->error('CLINIC_VALIDATION', 400, 'هدر Idempotency-Key (UUID) برای ذخیره دست‌خط الزامی است');
        }

        try {
            $result = $this->handwriting->savePage(
                $this->userId(),
                (int) $r['id'],
                $this->body($r),
                $key
            );

            return $this->success($result['response'], $result['status']);
        } catch (HandwritingException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][HandwritingController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    /**
     * Wrapper مشترک سایر Endpointها (nonce + cap + error-mapping).
     *
     * @param callable(): mixed $fn
     */
    private function staff(WP_REST_Request $r, string $cap, callable $fn, int $status = 200): WP_REST_Response|WP_Error
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }
        $perm = $this->requireCap($cap);
        if ($perm instanceof WP_Error) {
            return $perm;
        }

        try {
            return $this->success($fn(), $status);
        } catch (HandwritingException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (\Throwable $e) {
            error_log('[CPMS][HandwritingController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }

    private function userId(): int
    {
        return (int) (wp_get_current_user()->ID ?: 0);
    }

    /**
     * Body درخواست — JSON یا Paramهای مسیر (مثل تست‌ها).
     *
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

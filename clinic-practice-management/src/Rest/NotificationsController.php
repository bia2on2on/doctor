<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Auth\RolesAndCapabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * G6 + R2 (F8 — اعلان‌ها):
 *  - GET  /notifications        — Inbox نقش‌خود (منشی/پزشک/بیمار) + شمار خوانده‌نشده
 *  - POST /notifications/read   — علامت‌گذاری خوانده‌شده (ids یا all)
 *  - GET  /rt/notifications     — Real-time Badge (ADR-0007: ETag/304 + since)
 *
 * G6 «نقش خود» سرور-side تضمین می‌شود: Inbox فقط با recipient خود Actor
 * کوئری می‌شود (هیچ پارامتری گیرنده را عوض نمی‌کند).
 */
final class NotificationsController extends RestBase
{
    protected const NS = 'clinic/v1';

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/notifications', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, fn (): array => $this->notifications->inbox(
                    $this->userId($r),
                    (bool) ($r['unread'] ?? false),
                    (int) ($r['limit'] ?? 50)
                )),
                'permission_callback' => '__return_true',
                'args' => [
                    'unread' => ['required' => false, 'type' => 'boolean', 'default' => false],
                    'limit' => ['required' => false, 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/notifications/read', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->guard($r, function () use ($r): array {
                    $ids = is_array($r['ids'] ?? null) ? array_map('intval', (array) $r['ids']) : [];
                    $marked = $this->notifications->markRead(
                        $this->userId($r),
                        $ids,
                        (bool) ($r['all'] ?? false)
                    );

                    return ['marked' => $marked];
                }),
                'permission_callback' => '__return_true',
                'args' => [
                    'ids' => ['required' => false, 'type' => 'array', 'items' => ['type' => 'integer']],
                    'all' => ['required' => false, 'type' => 'boolean', 'default' => false],
                ],
            ],
        ]);

        // R2 — Real-time Badge (ADR-0007 — Controlled Polling)
        register_rest_route(self::NS, '/rt/notifications', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->rtNotifications($r),
                'permission_callback' => '__return_true',
                'args' => [
                    'since' => ['required' => false, 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'],
                ],
            ],
        ]);
    }

    // ================= R2 — ETag/304 =================

    private function rtNotifications(WP_REST_Request $r): WP_REST_Response|WP_Error
    {
        $guard = $this->guard($r, null);
        if ($guard instanceof WP_Error) {
            return $guard;
        }

        // Polling Guard (ADR-0007) — Badge سبک‌تر از R1: سقف مشابه
        $rl = $this->rateLimit($r, 'rt_notif_' . $this->userId($r), 60, MINUTE_IN_SECONDS);
        if ($rl instanceof WP_Error) {
            return $rl;
        }

        $userId = $this->userId($r);
        $etag = '"' . $this->notifications->lastId($userId) . '"';
        $ifNoneMatch = trim((string) $r->get_header('If-None-Match'));
        if ($ifNoneMatch === $etag) {
            $notModified = new WP_REST_Response(null, 304);
            $notModified->header('ETag', $etag);

            return $notModified;
        }

        $payload = $this->notifications->since($userId, (int) ($r['since'] ?? 0));
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
     * Guard استاندارد: Nonce → نقش CPMS (staff یا بیمار متصل) → اجرا.
     *
     * @param callable(): mixed|null $fn
     */
    private function guard(WP_REST_Request $r, ?callable $fn): WP_REST_Response|WP_Error|null
    {
        $nonce = $this->requireNonce($r);
        if ($nonce instanceof WP_Error) {
            return $nonce;
        }

        $user = wp_get_current_user();
        $roles = (array) ($user->roles ?? []);
        $isCpms = in_array(RolesAndCapabilities::ROLE_PATIENT, $roles, true)
            || in_array(RolesAndCapabilities::ROLE_SECRETARY, $roles, true)
            || in_array(RolesAndCapabilities::ROLE_DOCTOR, $roles, true);
        if (!$isCpms) {
            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی به اعلان‌ها برای نقش شما مجاز نیست');
        }

        if ($fn === null) {
            return null;
        }

        try {
            return $this->success($fn());
        } catch (\Throwable $e) {
            error_log('[CPMS][NotificationsController] unexpected: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->error('CLINIC_INTERNAL_ERROR', 500, 'خطای داخلی سرور — لطفاً دوباره تلاش کنید');
        }
    }
}

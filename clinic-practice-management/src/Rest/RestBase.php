<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Base کل REST Endpoints — کنواسیون‌های docs/api/api-contract.md §0.
 *
 * 5 لایه مجوز (docs/security/auth-authorization.md §2.1):
 *  1) Authentication (nonce)  2) Capability  3) Data-Access (در Service/Repository)
 *  4) Field-Access  5) Action Rules
 *
 * هر Endpoint جدید بدون API Contract ممنوع است (Section 56).
 */
abstract class RestBase
{
    protected const NS = 'clinic/v1';

    /**
     * Nonce check برای Requestهای Authenticated (CSRF).
     */
    protected function requireNonce(WP_REST_Request $request): bool|WP_Error
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return $this->error('CLINIC_INVALID_NONCE', 403, 'Nonce نامعتبر است (CSRF)');
        }

        return true;
    }

    /**
     * Capability check (لایه 2).
     *
     * @param string|string[] $caps
     */
    protected function requireCap(string|array $caps): bool|WP_Error
    {
        $user = wp_get_current_user();
        if (!$user->exists()) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید');
        }
        $caps = (array) $caps;
        foreach ($caps as $cap) {
            if (!$user->has_cap($cap)) {
                App::audit()->log(
                    'FORBIDDEN_ACCESS_ATTEMPT',
                    ['wp_user_id' => (int) $user->ID, 'role' => $user->roles[0] ?? 'unknown'],
                    'capability',
                    null,
                    null,
                    null,
                    ['cap' => $cap]
                );

                return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
            }
        }

        return true;
    }

    /**
     * Rate limit هدرها + کنترل.
     *
     * @return array{allowed: bool, remaining: int, reset_at: int}|WP_Error
     */
    protected function rateLimit(WP_REST_Request $request, string $key, int $max, int $windowSec)
    {
        $result = App::rate()->hit($key, $max, $windowSec);

        if (!$result['allowed']) {
            return $this->error('CLINIC_RATE_LIMITED', 429, 'درخواست‌های شما موقتاً محدود شده است', [
                'retry_after' => $result['reset_at'] - time(),
            ]);
        }

        return $result;
    }

    /**
     * پاسخ خطای استاندارد (Contract §0 / ADR-0019):
     * top-level `code` = ثابت ماشین‌خوان `CLINIC_*` (Stable) — `message` = فارسی
     * کاربر — `data.status` = HTTP. جزئیات فنی فقط در Log.
     */
    protected function error(string $code, int $http, string $message, array $data = []): WP_Error
    {
        // «status» کلید رزرو Envelope است — حتی اگر Data خطا هم‌نام داشته باشد،
        // HTTP Status رسمی Envelope اولویت دارد (مصون از تداخل کلید).
        return new WP_Error($code, $message, array_merge($data, ['status' => $http]));
    }

    /**
     * پاسخ موفق استاندارد.
     *
     * @param mixed $data
     */
    protected function success($data, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response(['data' => $data], $status);
        // Correlation ID (M10) — برای Trace از سمت کلاینت/Log
        if (function_exists('cpms_request_id')) {
            $response->header('X-CPMS-Correlation-Id', (string) cpms_request_id());
        }

        return $response;
    }

    /**
     * استخراج کلاینت با اعتبارسنجی (UUID).
     */
    protected function idempotencyKey(WP_REST_Request $request): ?string
    {
        $key = $request->get_header('Idempotency-Key');

        return (is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key))
            ? strtolower($key)
            : null;
    }
}

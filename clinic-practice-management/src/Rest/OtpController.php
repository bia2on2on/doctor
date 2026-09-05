<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Endpointهای Public احراز هویت — API Contract A2/A3.
 *
 * امنیت: Public (بدون Nonce) — به همین دلیل Rate Limit سخت‌گیرانه
 * (otp-day, otp-hour, otp-ip) + محدودیت‌های OtpPolicy.
 */
final class OtpController extends RestBase
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/otp/request', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->requestCode($request),
                'permission_callback' => '__return_true',
                'args' => [
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'purpose' => ['required' => false, 'type' => 'string', 'default' => OtpService::PURPOSE_LOGIN],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/otp/verify', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->verifyCode($request),
                'permission_callback' => '__return_true',
                'args' => [
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'code' => ['required' => true, 'type' => 'string'],
                    'purpose' => ['required' => false, 'type' => 'string', 'default' => OtpService::PURPOSE_LOGIN],
                ],
            ],
        ]);
    }

    private function requestCode(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $result = $this->otp->request(
                (string) $request->get_param('mobile'),
                (string) $request->get_param('purpose'),
                null,
                $this->clientIp($request)
            );

            return $this->success($result, 200);
        } catch (OtpException $e) {
            return $this->error($e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData());
        }
    }

    private function verifyCode(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $result = $this->otp->verify(
                (string) $request->get_param('mobile'),
                (string) $request->get_param('code'),
                (string) $request->get_param('purpose'),
                $this->clientIp($request)
            );

            return $this->success($result, 200);
        } catch (OtpException $e) {
            return $this->error($e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData());
        }
    }

    private function clientIp(WP_REST_Request $request): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}

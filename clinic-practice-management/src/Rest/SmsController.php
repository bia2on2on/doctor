<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Sms\SmsTemplateException;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Endpointهای ماژول پیامک (ADR-0025) — Settings → SMS/پیامک.
 *
 * امنیت:
 *  - Capability: cpms_sms_config (فنی)
 *  - Nonce (CSRF) در همه Endpointها
 *  - Rate Limit برای Testهای ارسال‌کننده
 *  - هیچ Response حاوی Secret/Credential plaintext نیست
 */
final class SmsController extends RestBase
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    public function register_routes(): void
    {
        $cap = [RolesAndCapabilities::SMS_CONFIG];

        register_rest_route(self::NS, '/sms/status', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => fn (WP_REST_Request $r) => $this->status($r), 'permission_callback' => fn () => $this->can($cap)],
        ]);
        register_rest_route(self::NS, '/sms/providers', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => fn (WP_REST_Request $r) => $this->providers($r), 'permission_callback' => fn () => $this->can($cap)],
        ]);
        register_rest_route(self::NS, '/sms/settings', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => fn (WP_REST_Request $r) => $this->saveSettings($r), 'permission_callback' => fn () => $this->can($cap)],
        ]);
        register_rest_route(self::NS, '/sms/test-connection', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => fn (WP_REST_Request $r) => $this->testConnection($r), 'permission_callback' => fn () => $this->can($cap)],
        ]);
        register_rest_route(self::NS, '/sms/test-send', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->testSend($r),
                'permission_callback' => fn () => $this->can($cap),
                'args' => [
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'message' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);
        register_rest_route(self::NS, '/sms/templates', [
            [
                'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
                'callback' => fn (WP_REST_Request $r) => $r->get_method() === 'POST' ? $this->saveTemplate($r) : $this->templates(),
                'permission_callback' => fn () => $this->can($cap),
            ],
        ]);
        register_rest_route(self::NS, '/sms/templates/test', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->testTemplate($r),
                'permission_callback' => fn () => $this->can($cap),
                'args' => [
                    'event' => ['required' => true, 'type' => 'string'],
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'vars' => ['required' => false, 'type' => 'object', 'default' => []],
                ],
            ],
        ]);
        register_rest_route(self::NS, '/sms/logs', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $r) => $this->logs($r),
                'permission_callback' => fn () => $this->can($cap),
            ],
        ]);
        register_rest_route(self::NS, '/sms/balance', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => fn (WP_REST_Request $r) => $this->balance($r), 'permission_callback' => fn () => $this->can($cap)],
        ]);
    }

    // ===== Handlers =====

    private function status(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }

        return $this->success($this->sms->status());
    }

    private function providers(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }

        return $this->success($this->smsProvidersList());
    }

    private function saveSettings(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }
        try {
            return $this->success($this->sms->saveSettings($request->get_json_params() ?: [], $this->userId()));
        } catch (SmsTemplateException $ex) {
            return $this->error($ex->apiCode(), 400, $ex->getMessage());
        }
    }

    private function testConnection(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }
        $rl = $this->rateLimit($request, 'sms-test-' . $this->userId(), 10, 3600);
        if (is_wp_error($rl)) {
            return $rl;
        }

        return $this->success($this->sms->testConnection($request->get_json_params() ?: [], $this->userId()));
    }

    private function testSend(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }
        $rl = $this->rateLimit($request, 'sms-send-' . $this->userId(), 10, 3600);
        if (is_wp_error($rl)) {
            return $rl;
        }
        try {
            return $this->success(
                $this->sms->testSend(
                    (string) $request->get_param('mobile'),
                    (string) $request->get_param('message'),
                    $this->userId()
                )
            );
        } catch (SmsTemplateException $ex) {
            return $this->error($ex->apiCode(), 400, $ex->getMessage());
        }
    }

    private function templates(): \WP_REST_Response|\WP_Error
    {
        return $this->success($this->sms->templates());
    }

    private function saveTemplate(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }
        $params = $request->get_json_params() ?: [];
        $event = (string) ($params['event'] ?? '');
        $templateId = (string) ($params['template_id'] ?? '');
        try {
            return $this->success($this->sms->saveTemplate($event, $templateId, $this->userId()));
        } catch (SmsTemplateException $ex) {
            return $this->error($ex->apiCode(), 400, $ex->getMessage());
        }
    }

    private function testTemplate(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }
        $rl = $this->rateLimit($request, 'sms-tmpl-' . $this->userId(), 20, 3600);
        if (is_wp_error($rl)) {
            return $rl;
        }
        try {
            return $this->success(
                $this->sms->testTemplate(
                    (string) $request->get_param('event'),
                    (string) $request->get_param('mobile'),
                    (array) $request->get_param('vars'),
                    $this->userId()
                )
            );
        } catch (SmsTemplateException $ex) {
            return $this->error($ex->apiCode(), 400, $ex->getMessage());
        }
    }

    private function logs(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }

        return $this->success(
            $this->sms->logs(
                $request->get_param('status') !== null ? (string) $request->get_param('status') : null,
                (int) $request->get_param('page'),
                (int) $request->get_param('per_page')
            )
        );
    }

    private function balance(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if ($e = $this->requireNonce($request)) {
            return $e;
        }

        return $this->success($this->sms->balance());
    }

    // ===== Helpers =====

    /**
     * @param list<string> $caps
     */
    private function can(array $caps): bool
    {
        $user = wp_get_current_user();

        return $user->exists() && $user->has_cap($caps[0]);
    }

    private function userId(): int
    {
        return (int) get_current_user_id();
    }

    /**
     * @return array<int, mixed>
     */
    private function smsProvidersList(): array
    {
        return \ClinicCore\Bootstrap\App::providers()->all();
    }
}

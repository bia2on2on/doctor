<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsTemplateException;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Endpointهای ماژول پیامک (ADR-0025) — Settings → SMS/پیامک.
 *
 * امنیت (Phase 3 Slice 3):
 *  - Capability: cpms_sms_config (فنی) — coarse, defense in depth
 *  - Nonce (CSRF) در همه Endpointها
 *  - Clinic-scoped SMS_CONFIG via AuthorizationService (App::scope()->clinicId trusted, never payload)
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
        register_rest_route(self::NS, '/sms/status', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => fn (WP_REST_Request $r) => $this->status($r), 'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG)],
        ]);
        register_rest_route(self::NS, '/sms/providers', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => fn (WP_REST_Request $r) => $this->providers($r), 'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG)],
        ]);
        register_rest_route(self::NS, '/sms/settings', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => fn (WP_REST_Request $r) => $this->saveSettings($r), 'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG)],
        ]);
        register_rest_route(self::NS, '/sms/test-connection', [
            ['methods' => WP_REST_Server::CREATABLE, 'callback' => fn (WP_REST_Request $r) => $this->testConnection($r), 'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG)],
        ]);
        register_rest_route(self::NS, '/sms/test-send', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->testSend($r),
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG),
                'args' => [
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'message' => ['required' => true, 'type' => 'string'],
                ],
            ],
        ]);
        register_rest_route(self::NS, '/sms/templates', [
            [
                'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
                'callback' => fn (WP_REST_Request $r) => $r->get_method() === 'POST' ? $this->saveTemplate($r) : $this->templates($r),
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG),
            ],
        ]);
        register_rest_route(self::NS, '/sms/templates/test', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $r) => $this->testTemplate($r),
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG),
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
                'permission_callback' => fn (WP_REST_Request $r)
                    => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG),
            ],
        ]);
        register_rest_route(self::NS, '/sms/balance', [
            ['methods' => WP_REST_Server::READABLE, 'callback' => fn (WP_REST_Request $r) => $this->balance($r), 'permission_callback' => fn (WP_REST_Request $r) => $this->permCap($r, RolesAndCapabilities::SMS_CONFIG)],
        ]);
    }

    // ===== Clinic-scoped Authorization (Phase 3 Slice 3) =====

    /**
     * Clinic-scoped SMS_CONFIG authorization — fail closed.
     *
     * Invariants:
     *  - Trusted Clinic from App::scope() (established by RestClinicContext), never from request payload.
     *  - Global WP capability alone insufficient — durable ACTIVE membership + scoped permission required.
     *  - Explicit deny overrides grant/preset, suspended/non-member fails closed.
     *  - On denial: no settings/template/last_test mutation, no SMS side effect, no credential disclosure.
     *
     * Error contract: generic CLINIC_PERMISSION_DENIED 403 to avoid clinic existence leak.
     * Existing coarse WP capability + nonce checks remain as defense in depth (checked before this helper).
     */
    private function requireClinicSmsAuth(): bool|\WP_Error
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return $this->error('CLINIC_UNAUTHORIZED', 401, 'وارد نشده‌اید');
        }
        try {
            $clinicId = App::scope()->clinicId;
        } catch (\Throwable $e) {
            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
        }
        if ($clinicId <= 0) {
            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
        }
        try {
            App::authorization_service()->authorize($userId, $clinicId, RolesAndCapabilities::SMS_CONFIG);
        } catch (\ClinicCore\Application\Authorization\AuthorizationException $ex) {
            return $this->error('CLINIC_PERMISSION_DENIED', 403, 'دسترسی ندارید');
        }

        return true;
    }

    // ===== Handlers =====

    private function status(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }

        return $this->success($this->sms->status());
    }

    private function providers(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }

        return $this->success($this->smsProvidersList());
    }

    private function saveSettings(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }
        try {
            return $this->success($this->sms->saveSettings($request->get_json_params() ?: [], $this->userId()));
        } catch (SmsTemplateException $ex) {
            return $this->error($ex->apiCode(), 400, $ex->getMessage());
        }
    }

    private function testConnection(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }
        $rl = $this->rateLimit($request, 'sms-test-' . $this->userId(), 10, 3600);
        if (is_wp_error($rl)) {
            return $rl;
        }

        return $this->success($this->sms->testConnection($request->get_json_params() ?: [], $this->userId()));
    }

    private function testSend(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
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

    private function templates(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }

        return $this->success($this->sms->templates());
    }

    private function saveTemplate(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
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
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
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
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }

        return $this->success(
            $this->sms->logs(
                // Trusted Scope (مرز C6) — هیچ clinic_id قابل‌اعتمادی از کلاینت وجود ندارد.
                App::scope()->clinicId,
                $request->get_param('status') !== null ? (string) $request->get_param('status') : null,
                (int) $request->get_param('page'),
                (int) $request->get_param('per_page')
            )
        );
    }

    private function balance(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $e = $this->requireNonce($request);
        if ($e instanceof \WP_Error) {
            return $e;
        }
        $p = $this->requireCap(RolesAndCapabilities::SMS_CONFIG);
        if ($p instanceof \WP_Error) {
            return $p;
        }
        $a = $this->requireClinicSmsAuth();
        if ($a instanceof \WP_Error) {
            return $a;
        }

        return $this->success($this->sms->balance());
    }

    // ===== Helpers =====

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

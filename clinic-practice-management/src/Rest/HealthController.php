<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Bootstrap\App;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Endpoint بهداشت (فنی) — بدون PHI.
 */
final class HealthController extends RestBase
{
    public function register_routes(): void
    {
        register_rest_route(self::NS, '/health', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => fn (WP_REST_Request $request) => $this->health($request),
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    private function health(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $version = App::migrations()->currentVersion();

        return $this->success([
            'ok' => true,
            'plugin' => 'cpms',
            'version' => defined('CPMS_VERSION') ? CPMS_VERSION : 'dev',
            'schema' => $version,
            'time_utc' => gmdate('c'),
            // Health Check Queue/Cron (ADR-0016)
            'jobs' => App::queueHealth(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Update;

use ClinicCore\Infrastructure\Sms\SsrfGuard;

/**
 * دریافت مانیفست انتشار از سرور فروشنده (HTTPS + SSRF-guard + Timeout).
 * فقط برای CPMS خود؛ هرگز PHI (فقط channel/version) — ADR-0028.
 */
final class HttpUpdateMetadataGateway implements UpdateMetadataGateway
{
    private const DEFAULT_TIMEOUT = 10;

    /**
     * @param array<string, mixed> $settings (license.server_url مشترک)
     */
    public function __construct(
        private readonly array $settings,
        private readonly int $timeout = self::DEFAULT_TIMEOUT
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->settings['server_url'] ?? '')) !== '';
    }

    public function fetch(string $channel): array
    {
        $endpoint = rtrim((string) ($this->settings['server_url'] ?? ''), '/');
        if ($endpoint === '') {
            throw new \RuntimeException('update server not configured');
        }
        if (str_starts_with($endpoint, 'http://')) {
            throw new \RuntimeException('update server must use HTTPS');
        }
        SsrfGuard::assertSafe($endpoint);

        $response = wp_remote_get($endpoint . '/updates/manifest?channel=' . rawurlencode($channel), [
            'timeout' => $this->timeout,
            'redirection' => 0,
            'headers' => [
                'Accept' => 'application/json',
                'X-CPMS-Version' => defined('CPMS_VERSION') ? CPMS_VERSION : 'dev',
            ],
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('update server unreachable');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status > 299) {
            throw new \RuntimeException('update server error HTTP ' . $status);
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !isset($decoded['payload'], $decoded['signature_b64'])) {
            throw new \RuntimeException('malformed update manifest response');
        }

        return [
            'payload' => (array) $decoded['payload'],
            'signature_b64' => (string) $decoded['signature_b64'],
        ];
    }
}

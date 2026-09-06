<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Licensing;

use ClinicCore\Infrastructure\Sms\SsrfGuard;

/**
 * پیاده‌سازی تولیدی VendorGateway از طریق WP HTTP API.
 *
 * امنیت (ADR-0028/§41):
 *  - فقط https + SSRF Guard (مقصد عمومی) — هیچ endpoint داخلی/لوپ‌بک.
 *  - Timeout اتصال/درخواست (بدون انتظار بی‌کران)؛ بدون Retry در مسیر
 *    درخواست (retry فقط در Job refresh با Backoff).
 *  - ارسال فقط ابرداده‌ی Allowlist (ADR-0028 §2) — بدون PHI.
 */
final class HttpVendorGateway implements VendorGateway
{
    private const DEFAULT_TIMEOUT = 10;

    /**
     * @param array<string, mixed> $settings (license.server_url)
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

    public function activate(array $request): array
    {
        return $this->call('activate', $request);
    }

    public function refresh(array $request): array
    {
        return $this->call('refresh', $request);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{payload: array<string, mixed>, signature_b64: string}
     */
    private function call(string $action, array $request): array
    {
        $endpoint = rtrim((string) ($this->settings['server_url'] ?? ''), '/');
        if ($endpoint === '') {
            throw new LicenseGatewayException('License server not configured', false, 'CLINIC_LICENSE_NOT_CONFIGURED');
        }
        if (str_starts_with($endpoint, 'http://')) {
            throw new LicenseGatewayException('License server must use HTTPS', false, 'CLINIC_LICENSE_ENDPOINT_INSECURE');
        }
        SsrfGuard::assertSafe($endpoint);

        $url = $endpoint . '/' . $action;
        $response = wp_remote_post($url, [
            'timeout' => $this->timeout,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-CPMS-Install' => (string) ($request['install_id'] ?? ''),
            ],
            'body' => (string) json_encode($this->allowlisted($request), JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            throw new LicenseGatewayException(
                'License server unreachable: ' . $response->get_error_message(),
                true,
                'CLINIC_LICENSE_UNREACHABLE'
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status === 401 || $status === 403) {
            throw new LicenseGatewayException('License key rejected by server', false, 'CLINIC_LICENSE_ACTIVATION_FAILED');
        }
        if ($status === 429) {
            throw new LicenseGatewayException('License server rate limited', true, 'CLINIC_LICENSE_RATE_LIMITED');
        }
        if ($status >= 500 || $status === 408) {
            throw new LicenseGatewayException('License server temporary error (HTTP ' . $status . ')', true, 'CLINIC_LICENSE_SERVER_ERROR');
        }
        if ($status < 200 || $status > 299) {
            throw new LicenseGatewayException('License server error (HTTP ' . $status . ')', false, 'CLINIC_LICENSE_SERVER_ERROR');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['payload'], $decoded['signature_b64'])) {
            throw new LicenseGatewayException('Malformed license server response', false, 'CLINIC_LICENSE_MALFORMED');
        }

        return [
            'payload' => (array) $decoded['payload'],
            'signature_b64' => (string) $decoded['signature_b64'],
        ];
    }

    /**
     * Allowlist سخت ابرداده (ADR-0028 §2) — هر کلید خارج از فهرست حذف می‌شود.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function allowlisted(array $request): array
    {
        $allowed = [
            'install_id',
            'license_id',
            'environment',
            'license_key',
            'version',
            'wp_version',
            'php_version',
            'domain',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $request)) {
                $out[$k] = $request[$k];
            }
        }

        return $out;
    }
}

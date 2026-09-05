<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms\Providers;

use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsSendException;
use ClinicCore\Infrastructure\Sms\SsrfGuard;

/**
 * Generic API Provider (الزام §17): اتصال پنل‌های پیامکی ناشناخته با Mapping — بدون تغییر Core.
 *
 * تنظیمات (Settings → sms.generic):
 *   endpoint, http_method, auth_header, auth_format, extra_headers{},
 *   request_json (Template با Placeholderها: {mobile} {message} {template_id} {vars} {sender}),
 *   response: {success_field, success_values[], id_field, error_field, status_field?}
 *
 * امنیت:
 *  - SSRF Guard (فقط Public http/https)
 *  - Arbitrary PHP/eval/Code Execution ممنوع (فقط String Substitution)
 *  - Timeout اجباری
 *  - Secret فقط در Request Header/Body — هرگز در Log
 */
final class GenericApiSmsProvider implements SmsProviderInterface
{
    private const PLACEHOLDERS = ['{mobile}', '{message}', '{template_id}', '{vars}', '{sender}', '{key}'];

    /**
     * @param array<string, mixed> $config از Settings (sms.generic)
     */
    public function __construct(private readonly array $config)
    {
    }

    public function id(): string
    {
        return 'generic_api';
    }

    public function label(): string
    {
        return 'Generic API (پنل دلخواه)';
    }

    public function capabilities(): array
    {
        $cfg = $this->config;

        return [
            'text' => true,
            'template' => true,
            'otp_template' => true,
            'delivery_status' => isset($cfg['response']['status_field']),
            'sender_list' => false,
            'balance' => false,
            'bulk' => false,
        ];
    }

    public function authMethods(): array
    {
        return ['api_key', 'bearer', 'username_password'];
    }

    public function authFields(): array
    {
        return [
            'api_key' => ['label' => 'API Key', 'secret' => true, 'required' => true],
            'bearer_token' => ['label' => 'Bearer Token', 'secret' => true, 'required' => true],
            'username' => ['label' => 'نام کاربری', 'secret' => false, 'required' => false],
            'password' => ['label' => 'رمز عبور', 'secret' => true, 'required' => false],
        ];
    }

    public function testConnection(array $creds): array
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');
        if ($endpoint === '') {
            return ['ok' => false, 'message' => '✗ Endpoint پنل پیامک تنظیم نشده است.'];
        }
        SsrfGuard::assertSafe($endpoint);

        $status = $this->httpCall($endpoint, 'GET', $creds, null, null, null);
        if ($status === 'unreachable') {
            return [
                'ok' => false,
                'message' => '✗ به پنل پیامک دسترسی نداریم (Timeout/DNS).',
                'technical' => 'unreachable: ' . $endpoint,
            ];
        }

        $httpCode = (int) ($status['http_code'] ?? 0);
        if (in_array($httpCode, [401, 403], true)) {
            return [
                'ok' => false,
                'message' => '✗ شناسه ورود/API Key نامعتبر است.',
                'technical' => 'http_' . $httpCode,
            ];
        }

        return [
            'ok' => true,
            'message' => '✓ اتصال برقرار شد (HTTP ' . $httpCode . ').',
            'technical' => 'http_' . $httpCode,
        ];
    }

    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array
    {
        return $this->send($creds, $mobile, $message, '', $opts);
    }

    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array
    {
        return $this->send($creds, $mobile, '', $templateId, $opts, $variables);
    }

    public function mapStatus(string $providerStatus): string
    {
        $s = strtolower(trim($providerStatus));

        return match (true) {
            $s === 'delivered' || $s === 'received' => 'DELIVERED',
            $s === 'sent' || $s === 'success' || $s === '1' || $s === 'true' => 'SENT',
            $s === 'failed' || $s === 'error' || $s === 'rejected' => 'FAILED',
            default => 'QUEUED',
        };
    }

    public function fetchSenders(array $creds): ?array
    {
        return null;
    }

    public function fetchBalance(array $creds): ?array
    {
        return null;
    }

    /**
     * @param array<string, string> $creds
     * @param array<string, string> $variables
     * @return array{provider_ref: string|null}
     */
    private function send(array $creds, string $mobile, string $message, string $templateId, array $opts, array $variables = []): array
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');
        if ($endpoint === '') {
            throw new SmsSendException('Endpoint پنل پیامک تنظیم نشده است', false, 'CLINIC_SMS_NOT_CONFIGURED');
        }
        SsrfGuard::assertSafe($endpoint);

        $body = $this->buildRequestBody($mobile, $message, $templateId, $variables, (string) ($opts['sender'] ?? ''));
        $timeout = (int) ($opts['timeout_sec'] ?? 5);

        $status = $this->httpCall($endpoint, (string) ($this->config['http_method'] ?? 'POST'), $creds, $body, $timeout, null);
        if ($status === 'unreachable') {
            throw new SmsSendException('پنل پیامک در دسترس نیست (Timeout/اتصال قطع)', true, 'CLINIC_SMS_PROVIDER_UNREACHABLE');
        }

        $httpCode = (int) ($status['http_code'] ?? 0);
        $decoded = json_decode((string) ($status['body'] ?? ''), true);
        $errorText = $this->responseError($decoded, (string) ($status['body'] ?? ''));

        if ($httpCode === 429) {
            throw new SmsSendException('محدودیت ارسال پنل فعال است', true, 'CLINIC_SMS_RATE_LIMITED');
        }
        if ($httpCode >= 500) {
            throw new SmsSendException('خطای موقت پنل پیامک (HTTP ' . $httpCode . ')', true, 'CLINIC_SMS_PROVIDER_ERROR', ['http' => $httpCode]);
        }
        if ($httpCode >= 400 || $this->successFlag($decoded) === false) {
            $perm = $errorText !== '' && (str_contains(strtolower($errorText), 'credit') || str_contains(strtolower($errorText), 'balance') || str_contains($errorText, 'اعتبار'));

            throw new SmsSendException(
                $errorText !== '' ? $errorText : 'پنل پیامک خطا را برگرداند (HTTP ' . $httpCode . ')',
                !$perm,
                $perm ? 'CLINIC_SMS_NO_CREDIT' : 'CLINIC_SMS_PROVIDER_ERROR',
                ['http' => $httpCode, 'error' => mb_substr($errorText, 0, 200)]
            );
        }

        return ['provider_ref' => $this->responseId($decoded)];
    }

    /**
     * ساخت Body از Template Mapping (فقط String Substitution — بدون Code Execution).
     */
    private function buildRequestBody(string $mobile, string $message, string $templateId, array $variables, string $sender): string
    {
        $template = (string) ($this->config['request_json'] ?? '{"to": "{mobile}", "message": "{message}"}');

        // محافظت: هرگونه نشانه Code/PHP در Template ممنوع
        if (preg_match('/<\?|eval\s*\(|function\s*\(|@/i', $template)) {
            throw new SmsSendException('Request Template حاوی محتوای مجاز نیست', false, 'CLINIC_SMS_MAPPING_INVALID');
        }

        $map = [
            '{mobile}' => $mobile,
            '{message}' => $message,
            '{template_id}' => $templateId,
            '{vars}' => (string) json_encode($variables, JSON_UNESCAPED_UNICODE),
            '{sender}' => $sender,
        ];

        $body = strtr($template, $map);
        $leftover = preg_match_all('/\{[a-z_]+\}/', $body);
        if ($leftover === false || $leftover > 0) {
            // Placeholder بدون مقدار — برای سلیس بودن با خالی جایگزین می‌شود
            $body = preg_replace('/\{[a-z_]+\}/', '', $body) ?? '';
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function successFlag(array $decoded): ?bool
    {
        $response = $this->config['response'] ?? [];
        $field = (string) ($response['success_field'] ?? '');
        if ($field === '' || !isset($decoded[$field])) {
            return null; // بدون معیار → روی HTTP Status تکیه کن
        }
        $values = $response['success_values'] ?? [];
        if (!is_array($values) || $values === []) {
            $values = ['sent', '1', 'true', 'ok'];
        }

        return in_array((string) $decoded[$field], array_map('strval', $values), true);
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function responseError(?array $decoded, string $rawBody): string
    {
        if ($decoded === null) {
            return '';
        }
        $field = (string) (($this->config['response']['error_field'] ?? 'error'));
        if (isset($decoded[$field])) {
            return trim((string) $decoded[$field]);
        }
        if (isset($decoded['message'])) {
            return trim((string) $decoded['message']);
        }

        return mb_substr(trim($rawBody), 0, 200);
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function responseId(?array $decoded): ?string
    {
        if ($decoded === null) {
            return null;
        }
        $field = (string) ($this->config['response']['id_field'] ?? 'message_id');
        if (isset($decoded[$field]) && is_scalar($decoded[$field])) {
            return (string) $decoded[$field];
        }

        return null;
    }

    /**
     * @param array<string, string> $creds
     * @return array{http_code: int, body: string}|string 'unreachable'
     */
    private function httpCall(string $url, string $method, array $creds, ?string $body, ?int $timeout, ?string $templateId)
    {
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];
        $this->applyAuth($headers, $creds);
        foreach ((array) ($this->config['extra_headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[] = $name . ': ' . (string) $value;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout ?? 5,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            return 'unreachable';
        }

        $httpCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }

        return ['http_code' => $httpCode, 'body' => (string) $responseBody];
    }

    /**
     * @param list<string> $headers (by reference)
     * @param array<string, string> $creds
     */
    private function applyAuth(array &$headers, array $creds): void
    {
        $method = (string) ($this->config['auth_method'] ?? 'api_key');
        $headerName = (string) ($this->config['auth_header'] ?? 'Authorization');
        $format = (string) ($this->config['auth_format'] ?? 'Bearer {key}');

        switch ($method) {
            case 'bearer':
                $key = (string) ($creds['bearer_token'] ?? '');
                if ($key !== '') {
                    $headers[] = $headerName . ': ' . $key;
                }
                break;

            case 'username_password':
                $user = (string) ($creds['username'] ?? '');
                $pass = (string) ($creds['password'] ?? '');
                if ($user !== '') {
                    $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
                }
                break;

            case 'api_key':
            default:
                $key = (string) ($creds['api_key'] ?? '');
                if ($key !== '') {
                    $headers[] = $headerName . ': ' . str_replace('{key}', $key, $format);
                }
                break;
        }
    }
}

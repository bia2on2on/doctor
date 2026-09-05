<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms\Providers;

use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsSendException;

/**
 * Provider توسعه/تست (الزام §32): پیام در Op Log — بدون ارسال واقعی.
 *
 * امنیت (FR-21.3): متن کامل (احتمالاً شامل کد OTP) فقط وقتی `CPMS_DEV_OTP_ECHO`
 * تعریف باشد لاگ می‌شود؛ در غیر این صورت ارقام Mask می‌شوند.
 */
final class LogSmsProvider implements SmsProviderInterface
{
    public function __construct(private readonly OpLogger $op)
    {
    }

    public function id(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return 'Log / Development (توسعه)';
    }

    public function capabilities(): array
    {
        return [
            'text' => true,
            'template' => true,
            'otp_template' => true,
            'delivery_status' => false,
            'sender_list' => false,
            'balance' => false,
            'bulk' => false,
        ];
    }

    public function authMethods(): array
    {
        return [];
    }

    public function authFields(): array
    {
        return [];
    }

    public function testConnection(array $creds): array
    {
        return ['ok' => true, 'message' => '✓ Provider توسعه فعال است (بدون نیاز به اتصال).'];
    }

    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array
    {
        $this->log($mobile, $message);

        return ['provider_ref' => 'log-' . bin2hex(random_bytes(4))];
    }

    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array
    {
        // Provider توسعه: Template را همان‌طور شبیه‌سازی می‌کند (متن از SmsService می‌آید)
        $this->log($mobile, '[template:' . $templateId . '] ' . json_encode($variables, JSON_UNESCAPED_UNICODE));

        return ['provider_ref' => 'log-' . bin2hex(random_bytes(4))];
    }

    public function mapStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'delivered' => 'DELIVERED',
            'sent' => 'SENT',
            'failed' => 'FAILED',
            default => 'QUEUED',
        };
    }

    public function fetchSenders(array $creds): ?array
    {
        return null;
    }

    public function fetchBalance(array $creds): ?array
    {
        return ['balance' => 'unlimited', 'currency' => 'dev'];
    }

    private function log(string $mobile, string $message): void
    {
        $text = $message;
        if (!defined('CPMS_DEV_OTP_ECHO')) {
            $text = preg_replace_callback('/\d{3,}/', static fn (array $m): string => '***', $message) ?? $message;
        }
        $this->op->info('SMS_LOG_SENT', [
            'to' => $mobile,
            'message' => $text,
            'dev_echo' => defined('CPMS_DEV_OTP_ECHO'),
        ]);
    }
}

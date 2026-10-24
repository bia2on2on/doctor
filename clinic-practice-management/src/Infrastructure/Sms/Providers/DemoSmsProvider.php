<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms\Providers;

use ClinicCore\Application\Demo\DemoService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsSendException;

/**
 * Offline OTP sink for the explicitly selected demo Clinic only.
 * The code is encrypted by DemoService; it is never logged or echoed here.
 */
final class DemoSmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return DemoService::PROVIDER_ID;
    }

    public function label(): string
    {
        return 'CPMS temporary demo (offline; synthetic data only)';
    }

    public function capabilities(): array
    {
        return [
            'text' => true,
            'template' => false,
            'otp_template' => false,
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
        unset($creds);
        return [
            'ok' => App::demo_service()->is_active(),
            'message' => App::demo_service()->is_active() ? 'Offline demo sink is active.' : 'Demo is not active.',
        ];
    }

    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array
    {
        unset($creds);
        $clinic_id = (int) ($opts['clinic_id'] ?? 0);
        $event = (string) ($opts['event'] ?? '');
        if ($event !== SmsEvents::OTP) {
            // Demo reminders never leave the installation and contain no OTP.
            if (!App::demo_service()->is_demo_clinic($clinic_id)) {
                throw new SmsSendException('Demo SMS sink is inactive or belongs to another Clinic.', false, 'provider_unavailable');
            }
            return ['provider_ref' => 'demo-noop-' . bin2hex(random_bytes(8))];
        }
        if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $message, $matches) !== 1) {
            throw new SmsSendException('Demo OTP message format was not recognized.', false, 'invalid_demo_message');
        }
        App::demo_service()->capture_otp($clinic_id, $mobile, (string) $matches[1]);
        return ['provider_ref' => 'demo-otp-' . bin2hex(random_bytes(8))];
    }

    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array
    {
        unset($creds, $mobile, $templateId, $variables, $opts);
        throw new SmsSendException('Demo provider does not support templates.', false, 'template_unsupported');
    }

    public function mapStatus(string $providerStatus): string
    {
        unset($providerStatus);
        return SmsMessageStatus::SENT;
    }

    public function fetchSenders(array $creds): ?array
    {
        unset($creds);
        return null;
    }

    public function fetchBalance(array $creds): ?array
    {
        unset($creds);
        return null;
    }
}

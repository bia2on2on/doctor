<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

use ClinicCore\Infrastructure\Sms\SmsProviderInterface;
use ClinicCore\Infrastructure\Sms\SmsSendException;

/**
 * Test-only recording SMS provider (never registered in production).
 *
 * It keeps every outbound text/template call in process memory so the D5
 * regression suite can assert that a plain-text OTP dispatch hands the REAL
 * code to the provider while the persisted message stays masked. Nothing here
 * is written to disk, the database, logs or audit tables.
 */
final class RecordingSmsProvider implements SmsProviderInterface
{
    public const ID = 'cpms_test_recording';

    /** @var list<array{mobile:string, message:string, opts:array<string, mixed>}> */
    public array $textSends = [];

    /** @var list<array{mobile:string, template_id:string, variables:array<string, string>, opts:array<string, mixed>}> */
    public array $templateSends = [];

    /** When true, the next send throws a retryable provider exception. */
    public bool $failNextRetryable = false;

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'CPMS test recording provider';
    }

    /**
     * @return array<string, bool>
     */
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

    /**
     * @return list<string>
     */
    public function authMethods(): array
    {
        return [];
    }

    /**
     * @return array<string, array{label: string, secret: bool, required: bool}>
     */
    public function authFields(): array
    {
        return [];
    }

    /**
     * @param array<string, string> $creds
     * @return array{ok: bool, message: string}
     */
    public function testConnection(array $creds): array
    {
        return ['ok' => true, 'message' => 'recording provider'];
    }

    /**
     * @param array<string, string> $creds
     * @param array{sender?: string|null, timeout_sec?: int} $opts
     * @return array{provider_ref: string|null}
     */
    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array
    {
        if ($this->failNextRetryable) {
            $this->failNextRetryable = false;

            throw new SmsSendException('recording provider temporary failure', true, 'CLINIC_SMS_PROVIDER_ERROR');
        }

        $this->textSends[] = ['mobile' => $mobile, 'message' => $message, 'opts' => $opts];

        return ['provider_ref' => 'rec-text-' . count($this->textSends)];
    }

    /**
     * @param array<string, string> $creds
     * @param array<string, string> $variables
     * @param array{sender?: string|null, timeout_sec?: int} $opts
     * @return array{provider_ref: string|null}
     */
    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array
    {
        if ($this->failNextRetryable) {
            $this->failNextRetryable = false;

            throw new SmsSendException('recording provider temporary failure', true, 'CLINIC_SMS_PROVIDER_ERROR');
        }

        $this->templateSends[] = ['mobile' => $mobile, 'template_id' => $templateId, 'variables' => $variables, 'opts' => $opts];

        return ['provider_ref' => 'rec-tpl-' . count($this->templateSends)];
    }

    public function mapStatus(string $providerStatus): string
    {
        return 'QUEUED';
    }

    /**
     * @param array<string, string> $creds
     * @return list<array{sender: string, label: string}>|null
     */
    public function fetchSenders(array $creds): ?array
    {
        return null;
    }

    /**
     * @param array<string, string> $creds
     * @return array{balance: int|string, currency: string}|null
     */
    public function fetchBalance(array $creds): ?array
    {
        return null;
    }

    public function reset(): void
    {
        $this->textSends = [];
        $this->templateSends = [];
        $this->failNextRetryable = false;
    }
}

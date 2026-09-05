<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms;

/**
 * Contract Adapterهای پنل پیامک (ADR-0025) — Developer Contract: docs/sms/adding-provider.md
 *
 * هر Provider فقط روش‌های Authentication/قابلیت‌های خودش را اعلام می‌کند؛
 * UI و Business Logic بر اساس این Contract عمومی کار می‌کنند.
 */
interface SmsProviderInterface
{
    public function id(): string;

    public function label(): string;

    /**
     * @return array<string, bool> text, template, otp_template, delivery_status, sender_list, balance, bulk
     */
    public function capabilities(): array;

    /**
     * @return list<string> 'api_key' | 'bearer' | 'username_password'
     */
    public function authMethods(): array;

    /**
     * @return array<string, array{label: string, secret: bool, required: bool}>
     */
    public function authFields(): array;

    /**
     * @param array<string, string> $creds
     * @return array{ok: bool, message: string, technical?: string}
     */
    public function testConnection(array $creds): array;

    /**
     * @param array<string, string> $creds
     * @param array{sender?: string|null, timeout_sec?: int} $opts
     * @return array{provider_ref: string|null}
     *
     * @throws SmsSendException
     */
    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array;

    /**
     * @param array<string, string> $creds
     * @param array<string, string> $variables متغیرهای داخلی
     * @param array{sender?: string|null, timeout_sec?: int} $opts
     * @return array{provider_ref: string|null}
     *
     * @throws SmsSendException
     */
    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array;

    /**
     * Status Provider → Status داخلی (QUEUED/SENDING/SENT/DELIVERED/FAILED)
     */
    public function mapStatus(string $providerStatus): string;

    /**
     * @param array<string, string> $creds
     * @return list<array{sender: string, label: string}>|null
     */
    public function fetchSenders(array $creds): ?array;

    /**
     * @param array<string, string> $creds
     * @return array{balance: int|string, currency: string}|null
     */
    public function fetchBalance(array $creds): ?array;
}

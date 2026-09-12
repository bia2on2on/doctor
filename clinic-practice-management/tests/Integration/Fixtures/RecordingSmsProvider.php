<?php

/**
 * Phase 2 — RED foundation fixture (test-only).
 *
 * A fake, in-memory SMS transport that RECORDS the configuration it was
 * actually handed by the production code path. It performs no network I/O and
 * never touches a real panel.
 *
 * Why this exists: `docs/architecture/phase2-tenant-context-remediation-design.md`
 * §5-D / RT-3 requires the assertion to be about the **tenant-sensitive
 * configuration that reached the provider** (credential identity, sender,
 * advanced options) — not merely about `cpms_sms_messages.clinic_id`. The only
 * way to observe that from outside is to sit on the provider boundary, which is
 * the documented extension point (`SmsProviderRegistry::register()`).
 *
 * The provider id is deliberately namespaced (`cpms_rt_recorder`) so it can
 * never collide with a production provider id.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration\Fixtures;

use ClinicCore\Infrastructure\Sms\SmsProviderInterface;

final class RecordingSmsProvider implements SmsProviderInterface
{
    public const ID = 'cpms_rt_recorder';

    /**
     * Every invocation, in order.
     *
     * @var list<array{call: string, creds: array<string,string>, mobile: string, message: string, template_id: string|null, variables: array<string,string>, opts: array<string,mixed>}>
     */
    private array $invocations = [];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'CPMS RT recorder (test only)';
    }

    /**
     * `template` is intentionally false so the production path goes through
     * `sendText()` — that keeps the observable surface (creds + opts) on a
     * single, unambiguous method.
     */
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
        return ['api_key'];
    }

    public function authFields(): array
    {
        return [
            'api_key' => ['label' => 'API Key', 'secret' => true, 'required' => true],
        ];
    }

    public function testConnection(array $creds): array
    {
        return ['ok' => true, 'message' => 'recorder'];
    }

    public function sendText(array $creds, string $mobile, string $message, array $opts = []): array
    {
        $this->record('sendText', $creds, $mobile, $message, null, [], $opts);

        return ['provider_ref' => 'RT-REF-' . count($this->invocations)];
    }

    public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array
    {
        $this->record('sendTemplate', $creds, $mobile, '', $templateId, $variables, $opts);

        return ['provider_ref' => 'RT-REF-' . count($this->invocations)];
    }

    public function mapStatus(string $providerStatus): string
    {
        return $providerStatus;
    }

    public function fetchSenders(array $creds): ?array
    {
        return null;
    }

    public function fetchBalance(array $creds): ?array
    {
        return null;
    }

    // =========================================================
    // Test observation API
    // =========================================================

    /**
     * @return list<array{call: string, creds: array<string,string>, mobile: string, message: string, template_id: string|null, variables: array<string,string>, opts: array<string,mixed>}>
     */
    public function invocations(): array
    {
        return $this->invocations;
    }

    /**
     * @return array{call: string, creds: array<string,string>, mobile: string, message: string, template_id: string|null, variables: array<string,string>, opts: array<string,mixed>}|null
     */
    public function lastInvocation(): ?array
    {
        $n = count($this->invocations);

        return $n === 0 ? null : $this->invocations[$n - 1];
    }

    public function reset(): void
    {
        $this->invocations = [];
    }

    /**
     * @param array<string,string>       $creds
     * @param array<string,string>       $variables
     * @param array<string,mixed>        $opts
     */
    private function record(
        string $call,
        array $creds,
        string $mobile,
        string $message,
        ?string $templateId,
        array $variables,
        array $opts
    ): void {
        $this->invocations[] = [
            'call' => $call,
            'creds' => $creds,
            'mobile' => $mobile,
            'message' => $message,
            'template_id' => $templateId,
            'variables' => $variables,
            'opts' => $opts,
        ];
    }
}

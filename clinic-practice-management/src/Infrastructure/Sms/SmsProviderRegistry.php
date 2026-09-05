<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms;

/**
 * Registry Adapterهای SMS — افزونه‌پذیر بدون تغییر Core (ADR-0025، الزام §2/§31).
 *
 * ثبت داخلی: App::providers()
 * ثبت بیرونی: add_action('cpms_sms_provider', fn ($registry) => $registry->register(new XProvider()))
 */
final class SmsProviderRegistry
{
    /** @var array<string, SmsProviderInterface> */
    private array $providers = [];

    public function register(SmsProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    public function get(string $id): ?SmsProviderInterface
    {
        return $this->providers[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    /**
     * @return list<array{id: string, label: string, capabilities: array<string, bool>, auth_methods: list<string>, auth_fields: array<string, mixed>}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->providers as $p) {
            $out[] = [
                'id' => $p->id(),
                'label' => $p->label(),
                'capabilities' => $p->capabilities(),
                'auth_methods' => $p->authMethods(),
                'auth_fields' => $p->authFields(),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->providers);
    }
}

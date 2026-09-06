<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Update;

/**
 * Gateway مانیفست انتشار (ADR-0029) — مرز شبکه‌ی کنترل‌پلین (ADR-0028).
 * قابل تعویض برای تست (Fixture).
 */
interface UpdateMetadataGateway
{
    /**
     * @param string $channel stable|beta
     *
     * @return array{payload: array<string, mixed>, signature_b64: string}
     *
     * @throws \Throwable
     */
    public function fetch(string $channel): array;

    public function isConfigured(): bool;
}

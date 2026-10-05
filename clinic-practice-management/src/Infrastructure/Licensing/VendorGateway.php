<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Licensing;

/**
 * Gateway سرویس مجوز فروشنده — مرز شبکه‌ایِ کنترل‌پلین (ADR-0028 §3).
 *
 * تنها شیئی که مجاز به ارتباط با سرویس مجوز است؛ هر پیاده‌سازی باید:
 *  - TLS + Timeout (اتصال و درخواست) + طبقه‌بندی خطا داشته باشد،
 *  - هرگز PHI نفرستد (payload فقط ابرداده‌ی Allowlist — ADR-0028 §2)،
 *  - برای تست/استیجینگ قابل تعویض باشد (Mock/Fixture خارج از src).
 *
 * Phase 16 Slice 4 — activation identity boundary (ADR-0023 §2): the signed
 * payload returned by activate()/refresh() MAY carry `activation_id`, the
 * central service's activation RECORD id for this installation. Only the
 * external central service issues/supersedes activation records and enforces
 * "one active production activation per standard License"; CPMS verifies and
 * stores the signed claim (LicenseActivationId grammar), never counts
 * activations from local state, and does not echo the claim back — the
 * request allowlist is unchanged.
 */
interface VendorGateway
{
    /**
     * فعال‌سازی نصب.
     *
     * @param array<string, mixed> $request {install_id, environment, license_key?, version, wp_version, php_version, domain?}
     *
     * @return array{payload: array<string, mixed>, signature_b64: string}
     *
     * @throws \Throwable طبقه‌بندی‌شده (retryable/permanent)
     */
    public function activate(array $request): array;

    /**
     * Refresh سند جاری.
     *
     * @param array<string, mixed> $request {install_id, license_id, environment, version, domain}
     *
     * @return array{payload: array<string, mixed>, signature_b64: string}
     *
     * @throws \Throwable
     */
    public function refresh(array $request): array;

    /**
     * آیا endpoint پیکربندی شده است؟ (false → refresh غیرفعال/not_configured)
     */
    public function isConfigured(): bool;
}

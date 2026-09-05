<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Logging;

/**
 * Correlation ID (Baseline §26, M10) — خالص و قابل تست.
 *
 * - کلاینت می‌تواند `X-CPMS-Correlation-Id` بفرستد؛ فقط اگر با Whitelist زیر
 *   تطابق کند پذیرفته می‌شود (حداکثر افشای Log: حروف/اعداد و `._-`).
 * - در غیر این‌صورت: مقدار Server-Generated (random 16 hex) — غیرقابل پیش‌بینی.
 * - **قاعده امنیتی:** Correlation ID هرگز نباید حاوی PHI/Credential/داده حساس باشد؛
 *   Whitelist کاراکترها + طول محدود این ریسک را مهار می‌کند (مقادیر آزاد/فاصله‌دار رد می‌شوند).
 * - در همه Logهای Request/Operation/Audit و هدر پاسخ REST استفاده می‌شود.
 */
final class CorrelationId
{
    private const PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    /**
     * @param string|null $header مقدار هدر `X-CPMS-Correlation-Id` (یا null)
     */
    public static function fromHeader(?string $header): string
    {
        if (is_string($header) && $header !== '' && preg_match(self::PATTERN, $header) === 1) {
            return $header;
        }

        return self::generate();
    }

    public static function generate(): string
    {
        return 'cpms-' . bin2hex(random_bytes(8));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}

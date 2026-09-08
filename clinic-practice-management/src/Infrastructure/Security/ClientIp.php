<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Security;

/**
 * تشخیص IP کلاینت با رفتار «صریح» نسبت به Reverse Proxy.
 *
 * Phase 1A — Item 3.
 *
 * مسئله: هر کنترل ضد سوءاستفاده‌ای که کلیدش IP است، دقیقاً به اندازهٔ
 * قابل‌اعتماد بودنِ آن IP ارزش دارد.
 *
 *  - اگر کورکورانه به `X-Forwarded-For` اعتماد کنیم، مهاجم با جعل هدر
 *    هر محدودیتی را دور می‌زند (هر درخواست = یک IP جدید).
 *  - اگر همیشه `REMOTE_ADDR` را بگیریم، پشت Proxy/CDN همهٔ کاربران یک IP
 *    مشترک دارند و محدودیت به یک قفل سراسری تبدیل می‌شود.
 *
 * پس هیچ پیش‌فرضِ «درست»ی وجود ندارد و انتخاب باید صریح و پیکربندی‌شده
 * باشد. رفتار پیش‌فرض این کلاس امنِ محافظه‌کارانه است: فقط `REMOTE_ADDR`.
 * پذیرش هدرهای Forwarded تنها وقتی فعال می‌شود که راه‌انداز، Proxyهای
 * مورد اعتماد را صراحتاً اعلام کند:
 *
 *   define('CPMS_TRUSTED_PROXIES', '10.0.0.0/8, 172.18.0.5');
 *
 * و حتی در آن حالت، هدر فقط وقتی خوانده می‌شود که `REMOTE_ADDR` واقعاً
 * یکی از همان Proxyها باشد؛ نزدیک‌ترین IPِ غیرمعتمد از راست به چپ در
 * زنجیره انتخاب می‌شود (روش استاندارد و مقاوم در برابر جعل).
 *
 * ⚠️ این کلاس Scope-Independent است و هیچ ارتباطی با Organization/Clinic
 * ندارد (Phase 1B/2).
 */
final class ClientIp
{
    public const TRUSTED_PROXIES_CONST = 'CPMS_TRUSTED_PROXIES';

    /**
     * هدرهای زنجیرهٔ Proxy به ترتیب اولویت.
     *
     * @var list<string>
     */
    private const FORWARD_HEADERS = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];

    /**
     * IP مؤثر کلاینت — یا null اگر قابل تشخیص نباشد.
     *
     * @param array<string, mixed>|null $server برای تست؛ پیش‌فرض $_SERVER
     */
    public static function resolve(?array $server = null): ?string
    {
        $server ??= $_SERVER;

        $remote = self::normalize($server['REMOTE_ADDR'] ?? null);
        if ($remote === null) {
            return null;
        }

        $trusted = self::trustedProxies();
        if ($trusted === [] || !self::matchesAny($remote, $trusted)) {
            // یا Proxy معتمدی اعلام نشده، یا درخواست مستقیماً از جایی
            // غیر از Proxy معتمد آمده: هدرها بی‌اعتبارند.
            return $remote;
        }

        foreach (self::FORWARD_HEADERS as $header) {
            $raw = $server[$header] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $chain = array_map('trim', explode(',', $raw));
            // از راست به چپ: اولین IP ای که Proxy معتمد نیست، کلاینت است.
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = self::normalize($chain[$i]);
                if ($candidate === null) {
                    continue;
                }
                if (!self::matchesAny($candidate, $trusted)) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    /**
     * آیا این نصب صراحتاً Proxy معتمد اعلام کرده است؟
     */
    public static function trustsProxies(): bool
    {
        return self::trustedProxies() !== [];
    }

    /**
     * @return list<string> CIDR یا IP خام
     */
    private static function trustedProxies(): array
    {
        if (!defined(self::TRUSTED_PROXIES_CONST)) {
            return [];
        }
        $raw = constant(self::TRUSTED_PROXIES_CONST);
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw)) {
            $parts = explode(',', $raw);
        } else {
            return [];
        }

        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $ranges
     */
    private static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bitsRaw] = explode('/', $range, 2);
        $bits = (int) $bitsRaw;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remaining)) - 1) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    private static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // شکل «IPv6 در براکت با پورت» و «IPv4:port»
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            if ($end !== false) {
                $value = substr($value, 1, $end - 1);
            }
        } elseif (substr_count($value, ':') === 1 && str_contains($value, '.')) {
            $value = substr($value, 0, (int) strpos($value, ':'));
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }
}

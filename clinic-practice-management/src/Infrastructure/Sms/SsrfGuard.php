<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Sms;

/**
 * محافظت در برابر SSRF برای Endpointهای دلخواه (Generic API Provider — الزام §17).
 *
 * قواعد: فقط http/https؛ هدف نهایی (IP resolve‌شده) نباید Loopback/Private/Link-Local/Reserved باشد.
 * اگر hostname resolve نشود → reject (safe default).
 */
final class SsrfGuard
{
    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new SmsSendException('Endpoint پیامک نامعتبر است', false, 'CLINIC_SMS_ENDPOINT_INVALID');
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new SmsSendException('فقط Endpointهای http/https مجاز است', false, 'CLINIC_SMS_ENDPOINT_INVALID');
        }

        $host = strtolower((string) $parts['host']);

        // IP صریح → مستقیم بررسی
        $direct = filter_var($host, FILTER_VALIDATE_IP);
        $ips = $direct !== false ? [$direct] : self::resolve($host);
        if ($ips === []) {
            throw new SmsSendException('Hostname پنل پیامک قابل resolve نیست', false, 'CLINIC_SMS_ENDPOINT_UNRESOLVABLE');
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
                throw new SmsSendException('اتصال به آدرس داخلی/خصوصی مجاز نیست (SSRF)', false, 'CLINIC_SSRF_BLOCKED');
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (function_exists('gethostbynamel')) {
            $all = gethostbynamel($host);
            if (is_array($all) && $all !== []) {
                return $all;
            }
        }
        $single = gethostbyname($host);

        return $single !== $host ? [$single] : [];
    }
}

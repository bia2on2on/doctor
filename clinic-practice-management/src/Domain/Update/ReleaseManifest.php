<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Update;

/**
 * مانیفست انتشار (ADR-0029 §1) — خالص.
 *
 * @see ADR-0029 — secure update delivery
 */
final class ReleaseManifest
{
    public const PRODUCT = 'cpms';
    public const CHANNELS = ['stable', 'beta'];

    /**
     * Phase 16 Slice 6B — ردهٔ انتشار (ADR-0029 §1)، داخلِ payload و بنابراین امضاشده.
     *
     * غیبتِ `release_kind` سازگار است و `normal` معنا می‌دهد؛ هر مقدارِ حاضرِ خارج
     * از این enum بسته، مانیفست را نامعتبر می‌کند (fail-closed).
     */
    public const RELEASE_KIND_NORMAL = 'normal';
    public const RELEASE_KIND_SECURITY = 'security';
    public const RELEASE_KINDS = [self::RELEASE_KIND_NORMAL, self::RELEASE_KIND_SECURITY];

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    public static function validate(array $raw): array
    {
        $errors = [];
        if (($raw['product'] ?? '') !== self::PRODUCT) {
            $errors[] = 'product mismatch';
        }
        $version = (string) ($raw['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/', $version)) {
            $errors[] = 'invalid version';
        }
        if (!in_array((string) ($raw['channel'] ?? 'stable'), self::CHANNELS, true)) {
            $errors[] = 'invalid channel';
        }
        $url = (string) ($raw['package_url'] ?? '');
        if (!preg_match('#^https://#i', $url)) {
            $errors[] = 'package_url must be https';
        }
        if (!preg_match('/^[0-9a-f]{64}$/', (string) ($raw['package_sha256'] ?? ''))) {
            $errors[] = 'invalid package_sha256';
        }
        foreach (['min_wp_version', 'min_php_version', 'min_cpms_version'] as $k) {
            if (isset($raw[$k]) && !preg_match('/^\d+\.\d+(\.\d+)?$/', (string) $raw[$k])) {
                $errors[] = 'invalid ' . $k;
            }
        }
        if (isset($raw['signed_at']) && !is_numeric($raw['signed_at'])) {
            $errors[] = 'invalid signed_at';
        }
        // Phase 16 Slice 6B — `release_kind` اختیاری است (غیبت = normal سازگار)، اما
        // مقدارِ حاضر باید در enum بسته باشد؛ غیررشته/ناشناخته = نامعتبر.
        if (array_key_exists('release_kind', $raw) && !self::isValidReleaseKind($raw['release_kind'])) {
            $errors[] = 'invalid release_kind';
        }

        return $errors;
    }

    public static function isValid(array $raw): bool
    {
        return self::validate($raw) === [];
    }

    /**
     * @param mixed $kind
     */
    private static function isValidReleaseKind(mixed $kind): bool
    {
        return is_string($kind) && in_array($kind, self::RELEASE_KINDS, true);
    }

    /**
     * ردهٔ امضاشدهٔ انتشار. فراخوان باید مانیفست را قبلاً اعتبارسنجی کرده باشد:
     * غیبت = `normal` (سازگاری با مانیفست‌های امضاشدهٔ موجود)، و مقدارِ ناشناختهٔ
     * حاضر همان‌جا در validate() رد می‌شود.
     *
     * @param array<string, mixed> $raw
     */
    public static function releaseKind(array $raw): string
    {
        $kind = $raw['release_kind'] ?? null;

        return self::isValidReleaseKind($kind) ? (string) $kind : self::RELEASE_KIND_NORMAL;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function isSecurityRelease(array $raw): bool
    {
        return self::releaseKind($raw) === self::RELEASE_KIND_SECURITY;
    }

    /**
     * Phase 16 Slice 6B — `signed_at`ِ عددِ صحیح (تنها ورودیِ قطعیِ مقایسهٔ حقوقِ نسخه).
     *
     * `null` = غایب یا غیرِعددِ صحیح. سازگاریِ بدون‌مرز دست‌نخورده می‌ماند (validate
     * همان قاعدهٔ numeric را برای مانیفست‌های بدونِ ادعا نگه می‌دارد)، اما هیچ
     * مقایسهٔ مرزی هرگز روی رشته/اعشار/غایب انجام نمی‌شود.
     *
     * @param array<string, mixed> $raw
     */
    public static function signedAt(array $raw): ?int
    {
        $value = $raw['signed_at'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * آیا این انتشار برای نصب جاری مجاز است؟
     *
     * @param array<string, mixed> $raw
     */
    public static function isApplicable(array $raw, string $currentVersion, string $wpVersion, string $phpVersion): bool
    {
        if (!self::isValid($raw)) {
            return false;
        }
        $new = (string) $raw['version'];
        // نسخه جدیدتر
        if (version_compare($new, $currentVersion, '<=')) {
            return false;
        }
        if (isset($raw['min_cpms_version']) && version_compare($currentVersion, (string) $raw['min_cpms_version'], '<')) {
            return false;
        }
        if (isset($raw['min_wp_version']) && version_compare($wpVersion, (string) $raw['min_wp_version'], '<')) {
            return false;
        }
        if (isset($raw['min_php_version']) && version_compare($phpVersion, (string) $raw['min_php_version'], '<')) {
            return false;
        }

        return true;
    }
}

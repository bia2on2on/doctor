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

        return $errors;
    }

    public static function isValid(array $raw): bool
    {
        return self::validate($raw) === [];
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

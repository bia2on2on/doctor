<?php

declare(strict_types=1);

namespace ClinicCore\Application\Update;

use ClinicCore\Application\Licensing\LicenseService;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Domain\Update\ReleaseManifest;
use ClinicCore\Domain\Update\ReleaseSignature;
use ClinicCore\Infrastructure\Update\UpdateMetadataGateway;
use ClinicCore\Settings\Settings;

/**
 * سرویس به‌روزرسانی امن (ADR-0029 / spec §19):
 *  - بررسی دستی (Admin/CLI) یا کش‌شده (transient) — بدون شبکه در صفحات عادی.
 *  - مانیفست فقط با امضای معتبرِ کلید انتشار پذیرفته می‌شود؛ نسخه/پیش‌نیازها
 *    سنجیده و package_sha256 برای نصب‌کننده‌ی استاندارد WP آماده می‌شود.
 *  - هرگز eval/کد از راه دور اجرا نمی‌کند؛ فقط authorize + integrity-verify.
 *
 * Entitlement (ADR-0023 §5): نصب فعال‌نشده (NOT_CONFIGURED) = به‌روزرسانی
 * آزاد (محیط توسعه/قبل از فعال‌سازی)؛ بعد از فعال‌سازی، feature `updates`
 * باید در سند باشد.
 */
final class UpdateService
{
    public const CACHE_PREFIX = 'cpms_update_check';

    public function __construct(
        private readonly Settings $settings,
        private readonly LicenseService $licenses,
        private readonly UpdateMetadataGateway $gateway
    ) {
    }

    /**
     * کانال فعال به‌روزرسانی (stable|beta) از Settings — پیش‌فرض stable.
     */
    public function channel(): string
    {
        try {
            $channel = (string) $this->settings->get('update.channel', 'stable');
        } catch (\Throwable) {
            $channel = 'stable';
        }

        return ($channel === 'beta' || $channel === 'stable') ? $channel : 'stable';
    }

    /**
     * آیا این نصب اجازه‌ی دریافت به‌روزرسانی دارد؟
     *
     * پیش از فعال‌سازی (NOT_CONFIGURED/ACTIVATION_PENDING/ACTIVATION_GRACE) و
     * حالت توسعه = آزاد (راه‌اندازی/توسعه — ADR-0023 §5)؛ پس از فعال‌سازی،
     * feature `updates` باید در سند باشد.
     */
    public function isUpdateEntitled(): bool
    {
        $status = $this->licenses->currentState()['status'];
        if (in_array($status, LicenseStatus::PRE_ACTIVATION, true) || $status === LicenseStatus::DEVELOPMENT) {
            return true;
        }

        return $this->licenses->entitlements()->hasFeature('updates');
    }

    /**
     * بررسی به‌روزرسانی (کش + TTL). force برای Admin.
     *
     * @return array<string, mixed>
     */
    public function checkForUpdates(bool $force = false, string $channel = 'stable'): array
    {
        if (!$this->gateway->isConfigured()) {
            return ['available' => false, 'reason' => 'not_configured', 'checked_at' => time()];
        }
        if (!$this->isUpdateEntitled()) {
            return ['available' => false, 'reason' => 'not_entitled', 'checked_at' => time()];
        }

        $cacheKey = self::CACHE_PREFIX . '_' . $channel;
        $ttl = max(60, (int) $this->settings->get('update.check_interval_hours', 24) * 3600);
        $cached = function_exists('get_transient') ? get_transient($cacheKey) : false;
        if (!$force && is_array($cached) && isset($cached['checked_at']) && (time() - (int) $cached['checked_at']) < $ttl) {
            return $cached;
        }

        try {
            $doc = $this->gateway->fetch($channel);
            $result = $this->evaluateManifest(
                $doc['payload'],
                $doc['signature_b64'],
                $channel,
                (string) (defined('CPMS_VERSION') ? CPMS_VERSION : '0'),
                function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '0',
                PHP_VERSION
            );
        } catch (\Throwable $e) {
            $result = ['available' => false, 'reason' => 'fetch_failed', 'checked_at' => time()];
        }
        $result['checked_at'] = time();
        if (function_exists('set_transient')) {
            set_transient($cacheKey, $result, $ttl);
        }

        return $result;
    }

    /**
     * ارزیابی مانیفست (خالص نسبت به شبکه): امضا → ساختار → applicability.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function evaluateManifest(
        array $payload,
        string $signatureB64,
        string $channel,
        string $currentVersion,
        string $wpVersion,
        string $phpVersion
    ): array {
        if (!ReleaseSignature::verify($payload, $signatureB64)) {
            return ['available' => false, 'reason' => 'invalid_signature'];
        }
        if (!ReleaseManifest::isValid($payload)) {
            return ['available' => false, 'reason' => 'invalid_manifest'];
        }
        if ((string) ($payload['channel'] ?? 'stable') !== $channel) {
            return ['available' => false, 'reason' => 'channel_mismatch'];
        }
        if (!ReleaseManifest::isApplicable($payload, $currentVersion, $wpVersion, $phpVersion)) {
            return ['available' => false, 'reason' => 'not_applicable'];
        }

        return [
            'available' => true,
            'version' => (string) $payload['version'],
            'package_url' => (string) $payload['package_url'],
            'package_sha256' => (string) $payload['package_sha256'],
            'channel' => (string) $payload['channel'],
            'release_notes' => (string) ($payload['release_notes'] ?? ''),
            'reason' => '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Application\Licensing;

use ClinicCore\Domain\Licensing\EntitlementRegistry;
use ClinicCore\Domain\Licensing\LicenseKeys;
use ClinicCore\Domain\Licensing\LicensePolicy;
use ClinicCore\Domain\Licensing\LicenseSignature;
use ClinicCore\Domain\Licensing\LicenseStateMachine;
use ClinicCore\Domain\Licensing\LicenseStateProvider;
use ClinicCore\Domain\Licensing\LicenseStatus;
use ClinicCore\Infrastructure\Licensing\LicenseGatewayException;
use ClinicCore\Infrastructure\Licensing\VendorGateway;
use ClinicCore\Infrastructure\Repository\LicenseRepository;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * سرویس لایسنس (F10 / ADR-0023) — وضعیت محلیِ امضاشده + همگام‌سازی با
 * سرویس فروشنده (فقط از Job refresh؛ هرگز در مسیر درخواست).
 *
 * Provider پیاده‌سازی LicenseStateProvider برای SignedLicenseGate.
 * هیچ PHI وارد payloadها/جدول لایسنس نمی‌شود (ADR-0028).
 */
final class LicenseService implements LicenseStateProvider
{
    private const PRODUCT = 'cpms';

    public function __construct(
        private readonly LicenseRepository $repo,
        private readonly VendorGateway $gateway,
        private readonly CpmsDb $db,
        private readonly LicensePolicy $policy = new LicensePolicy()
    ) {
    }

    public function installId(): string
    {
        return $this->repo->installId();
    }

    // ================= LicenseStateProvider =================

    public function currentState(): array
    {
        // حالت توسعه/تست — فقط مکانیسم صریحِ مستند (ثابت CPMS_DEV_MODE یا
        // فیلتر cpms_license_dev_mode). هرگز تشخیص خودکارِ محیط/دامنه/localhost.
        if (self::devModeEnabled()) {
            return [
                'status' => LicenseStatus::DEVELOPMENT,
                'reason' => 'dev_mode',
                'expires_at' => null,
                'needs_renewal' => false,
            ];
        }

        $row = $this->stateRowSafe();
        if ($row !== null) {
            $payload = $this->decodePayload((string) ($row['payload_json'] ?? ''));
            $verdict = ($row['last_refresh_error'] === null || $row['last_refresh_error'] === '')
                ? LicenseStateMachine::VERIFIED
                : LicenseStateMachine::UNREACHABLE;

            $out = LicenseStateMachine::compute($payload, $verdict, time(), $this->policy);

            return [
                'status' => $out['status'],
                'reason' => $out['reason'],
                'expires_at' => $out['expires_at'],
                'needs_renewal' => $out['needs_renewal'],
            ];
        }

        // بدون سند معتبر → وضعیتِ پنجرهٔ فعال‌سازی (تصمیم کارفرما):
        //   fresh (نصب تازه، ۷ روز) → ACTIVATION_PENDING → RESTRICTED
        //   migration (pre-F10، ۳۰ روز) → ACTIVATION_GRACE → RESTRICTED
        // هرگز UNREACHABLE نیست — «قطع شبکه با داشتن سند» جای خودش است.
        return $this->activationWindowState(time());
    }

    /**
     * @return array{status:string, reason:string, expires_at:int|null, needs_renewal:bool, renewal_in_sec:int|null}
     */
    private function activationWindowState(int $now): array
    {
        try {
            $win = $this->repo->activationWindow();
        } catch (\Throwable) {
            $win = null; // قبل از Migration / جدول ناقص — دفاعی
        }
        $startedAt = null;
        $type = 'fresh';
        if (is_array($win) && isset($win['activation_window_started_at']) && $win['activation_window_started_at'] !== null) {
            $ts = strtotime((string) $win['activation_window_started_at']);
            if ($ts !== false) {
                $startedAt = $ts;
            }
        }
        if (is_array($win) && ($win['activation_window_type'] ?? '') === 'migration') {
            $type = 'migration';
        }

        return LicenseStateMachine::computeActivationWindow($startedAt, $type, $now, $this->policy);
    }

    /**
     * حالت توسعه/تست صریح — ثابت `CPMS_DEV_MODE` (wp-config.php) یا
     * فیلتر `cpms_license_dev_mode`. هیچ تشخیص خودکار محیط/دامنه وجود ندارد
     * و هیچ unlock مخفی/جهانی در package نیست (ADR-0023؛ مستند).
     */
    public static function devModeEnabled(): bool
    {
        $declared = defined('CPMS_DEV_MODE') && CPMS_DEV_MODE;
        if (function_exists('apply_filters')) {
            return (bool) apply_filters('cpms_license_dev_mode', $declared);
        }

        return $declared;
    }

    public function entitlements(): EntitlementRegistry
    {
        $row = $this->stateRowSafe();
        if ($row === null) {
            return new EntitlementRegistry();
        }
        $payload = $this->decodePayload((string) ($row['payload_json'] ?? ''));
        $raw = isset($payload['entitlements']) && is_array($payload['entitlements'])
            ? $payload['entitlements']
            : null;

        return new EntitlementRegistry($raw);
    }

    /**
     * وضعیت کامل برای UI/Health (بدون کلید حساس).
     *
     * @return array<string, mixed>
     */
    public function statusMeta(): array
    {
        $row = $this->stateRowSafe();
        $state = $this->currentState();
        $install = $this->installId();
        $winType = null;
        try {
            $win = $this->repo->activationWindow();
            if (is_array($win)) {
                $winType = ($win['activation_window_type'] ?? '') === 'migration' ? 'migration' : 'fresh';
            }
        } catch (\Throwable) {
            $winType = null;
        }

        return [
            'configured' => $row !== null,
            'install_id' => $install,
            'install_id_masked' => substr($install, 0, 8) . '…',
            'license_id' => $row !== null ? (string) ($row['license_id'] ?? '') : '',
            'status' => $state['status'],
            'reason' => $state['reason'],
            'expires_at' => $state['expires_at'],
            'needs_renewal' => $state['needs_renewal'],
            'activation_window_type' => $winType,
            'verified_at' => $row !== null ? (string) ($row['verified_at'] ?? '') : null,
            'last_refresh_attempt_at' => $row !== null ? (string) ($row['last_refresh_attempt_at'] ?? '') : null,
            'last_refresh_error' => $row !== null ? (string) ($row['last_refresh_error'] ?? '') : null,
            'refresh_fail_count' => $row !== null ? (int) ($row['refresh_fail_count'] ?? 0) : 0,
            'gateway_configured' => $this->gateway->isConfigured(),
            'signature_available' => LicenseSignature::available(),
        ];
    }

    // ================= Activation / Refresh =================

    /**
     * فعال‌سازی از سرویس فروشنده با license key.
     */
    public function activateWithKey(string $licenseKey): array
    {
        if (!$this->gateway->isConfigured()) {
            throw new LicenseGatewayException('License server not configured', false, 'CLINIC_LICENSE_NOT_CONFIGURED');
        }
        $doc = $this->gateway->activate([
            'install_id' => $this->installId(),
            'environment' => $this->environment(),
            'license_key' => $licenseKey,
            'version' => defined('CPMS_VERSION') ? CPMS_VERSION : 'dev',
            'wp_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
            'php_version' => PHP_VERSION,
            'domain' => $this->domain(),
        ]);
        $this->verifyAndStore($doc['payload'], $doc['signature_b64']);

        return $this->statusMeta();
    }

    /**
     * فعال‌سازی دستی/آفلاین با فایل سند امضاشده (بدون نیاز به سرور).
     */
    public function activateWithDocument(string $payloadJson, string $signatureB64): array
    {
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new LicenseGatewayException('Malformed license document', false, 'CLINIC_LICENSE_MALFORMED');
        }
        $this->verifyAndStore($payload, $signatureB64);

        return $this->statusMeta();
    }

    /**
     * Refresh (فقط Job — Backoff بیرونی).
     */
    public function refresh(): array
    {
        $row = $this->stateRowSafe();
        if ($row === null) {
            throw new LicenseGatewayException('No license to refresh — activate first', false, 'CLINIC_LICENSE_NOT_ACTIVATED');
        }
        if (!$this->gateway->isConfigured()) {
            // بدون سرور پیکربندی‌شده: manual activation است؛ refresh لازم نیست
            return $this->statusMeta();
        }
        try {
            $doc = $this->gateway->refresh([
                'install_id' => (string) ($row['install_id'] ?? $this->installId()),
                'license_id' => (string) ($row['license_id'] ?? ''),
                'environment' => $this->environment(),
                'version' => defined('CPMS_VERSION') ? CPMS_VERSION : 'dev',
            ]);
            $this->verifyAndStore($doc['payload'], $doc['signature_b64']);

            return $this->statusMeta();
        } catch (LicenseGatewayException $e) {
            if ($e->retryable) {
                $this->repo->recordRefreshFailure($e->apiCode() . ': ' . $e->getMessage());
            } else {
                $this->repo->recordRefreshFailure($e->apiCode());
            }
            throw $e;
        }
    }

    /**
     * آیا Job refresh الان لازم است؟ (Backoff: شکست‌ها با فاصله‌ی فزاینده)
     */
    public function refreshDue(int $now = 0): bool
    {
        $now = $now > 0 ? $now : time();
        $row = $this->stateRowSafe();
        if ($row === null || !$this->gateway->isConfigured()) {
            return false;
        }
        $lastAttempt = $row['last_refresh_attempt_at'] !== null
            ? (int) strtotime((string) $row['last_refresh_attempt_at'])
            : 0;
        $fails = (int) ($row['refresh_fail_count'] ?? 0);
        // Backoff: 1h پایه × 2^min(fails,5) + jitter — سقف ~32h
        $delay = min($this->policy->renewIntervalSeconds(), 3600) * (1 << min($fails, 5));
        $state = $this->currentState();

        return $state['needs_renewal'] || ($lastAttempt > 0 && ($now - $lastAttempt) >= $delay);
    }

    // ================= Private =================

    /**
     * اعتبارسنجی ساختار + انطباق نصب + امضا، سپس ذخیره (تنها نقطه‌ی ورود doc).
     *
     * @param array<string, mixed> $payload
     */
    private function verifyAndStore(array $payload, string $signatureB64): void
    {
        if (($payload['product'] ?? '') !== self::PRODUCT) {
            throw new LicenseGatewayException('License document is not for CPMS', false, 'CLINIC_LICENSE_INVALID');
        }
        if (($payload['install_id'] ?? '') !== $this->installId()) {
            throw new LicenseGatewayException('License document install mismatch', false, 'CLINIC_LICENSE_INVALID');
        }
        $licenseId = (string) ($payload['license_id'] ?? '');
        $expiresAt = isset($payload['expires_at']) ? (int) $payload['expires_at'] : 0;
        $issuedAt = isset($payload['issued_at']) ? (int) $payload['issued_at'] : 0;
        if ($licenseId === '' || $expiresAt <= 0 || ($issuedAt > 0 && $expiresAt < $issuedAt)) {
            throw new LicenseGatewayException('License document has invalid dates', false, 'CLINIC_LICENSE_INVALID');
        }

        $message = LicenseSignature::canonicalJson($payload);
        if (!LicenseSignature::verify($message, $signatureB64, LicenseKeys::publicKey())) {
            throw new LicenseGatewayException('License signature verification failed', false, 'CLINIC_LICENSE_INVALID');
        }

        $this->repo->saveVerified($payload, $signatureB64, $licenseId);
    }

    /**
     * خواندن امن state — قبل از اجرای Migration (اولین درخواست‌ها) یا در
     * محیط‌های ناقص، جدول نبودن نباید مسیر کاری را بشکند (NOT_CONFIGURED).
     *
     * @return array<string, mixed>|null
     */
    private function stateRowSafe(): ?array
    {
        try {
            return $this->repo->state();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function environment(): string
    {
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return (string) WP_ENVIRONMENT_TYPE;
        }

        return 'production';
    }

    private function domain(): string
    {
        if (!function_exists('home_url')) {
            return '';
        }
        $host = (string) parse_url((string) home_url(), PHP_URL_HOST);

        return strtolower(preg_replace('/^www\./', '', $host) ?? '');
    }
}

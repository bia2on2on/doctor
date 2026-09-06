<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Licensing;

/**
 * انتitlement‌های مجوز — لایه‌ی میانی License → Entitlements → Capabilities
 * (F10 spec §17 / ADR-0023 §4). خالص و بدون WP.
 *
 * - Featureها: کلیدهای boolean (handwriting, ocr, reports_advanced,
 *   multi_doctor, staff, backup_remote, updates, api).
 * - Limitها: اعداد (doctors, staff, branches).
 * - کلید ناشناخته = fail-closed (false/null) — هرگز کرش نمی‌کند.
 * - Downgrade هرگز موجودیت تاریخی را حذف/غیرفعال نمی‌کند؛ فقط ساخت جدید
 *   محدود می‌شود (در لایه‌ی سرویس با شمارش + قید یکتایی اجرا می‌شود).
 */
final class EntitlementRegistry
{
    /** @var array<string, bool> */
    private array $features;

    /** @var array<string, int> */
    private array $limits;

    /**
     * @param array<string, mixed>|null $raw از payload سند (entitlements)
     */
    public function __construct(?array $raw = null)
    {
        $features = [];
        $limits = [];
        if (is_array($raw)) {
            foreach ((array) ($raw['features'] ?? []) as $k => $v) {
                if (is_string($k)) {
                    $features[$k] = (bool) $v;
                }
            }
            foreach ((array) ($raw['limits'] ?? []) as $k => $v) {
                if (is_string($k) && is_numeric($v)) {
                    $limits[$k] = max(0, (int) $v);
                }
            }
        }
        $this->features = $features;
        $this->limits = $limits;
    }

    public function hasFeature(string $feature): bool
    {
        // fail-closed: ناشناخته/غایب = غیرفعال
        return $this->features[$feature] ?? false;
    }

    /**
     * سقف یک Limit (null = بدون سقف).
     */
    public function limitOf(string $key): ?int
    {
        return array_key_exists($key, $this->limits) ? $this->limits[$key] : null;
    }

    /**
     * آیا ساخت/فعال‌سازی جدید در این limit مجاز است؟
     *
     * @param int $current تعداد فعلی (شمارش سمت سرویس، در تراکنش)
     */
    public function allowsCreation(string $limitKey, int $current): bool
    {
        $max = $this->limitOf($limitKey);
        if ($max === null) {
            return true;
        }

        return $current < $max;
    }

    /**
     * @return array<string, bool>
     */
    public function allFeatures(): array
    {
        return $this->features;
    }

    /**
     * @return array<string, int>
     */
    public function allLimits(): array
    {
        return $this->limits;
    }
}

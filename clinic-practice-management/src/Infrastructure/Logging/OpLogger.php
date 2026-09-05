<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Logging;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Operational Log — جدا از Audit (ADR-0008, T-14).
 *
 * سیاست: هیچ PHI در پیام/Context نمی‌رود. اگر لازم شد، فقط IDها (و نه محتوا/موبایل/...).
 */
final class OpLogger
{
    public const LEVELS = ['debug', 'info', 'warning', 'error'];

    public function __construct(private readonly CpmsDb $db)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        try {
            // نمایش خطای DB خاموش — نوشتن Log عملیاتی هرگز نباید خروجی Request/
            // Bootstrap را آلوده کند (مثلاً وقتی خود جدولِ Log هنوز ساخته نشده).
            $wpdb = $this->db->wpdb();
            $hadSuppression = $wpdb->suppress_errors;
            $wpdb->suppress_errors(true);
            try {
                $this->db->insert('cpms_operational_logs', [
                    'level' => $level,
                    'message' => mb_substr($message, 0, 500),
                    'context_json' => json_encode($this->sanitize($context), JSON_UNESCAPED_UNICODE) ?: null,
                    'request_id' => $this->requestId(),
                    'created_at' => $this->db->nowUtcSql(),
                ]);
            } finally {
                $wpdb->suppress_errors($hadSuppression);
            }
        } catch (\Throwable) {
            // Log هرگز نباید Request را شکست بدهد
            error_log('[cpms] op-log write failed: ' . $message);
        }
    }

    /**
     * حذف کلیدهای حساس و کوتاه‌کردن مقادیر — خط دفاع دوم در برابر PHI نادرست.
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $sensitive = ['mobile', 'phone', 'national_id', 'otp', 'code', 'password', 'token', 'secret', 'card', 'content', 'text'];
        $out = [];
        foreach ($context as $k => $v) {
            $key = (string) $k;
            if (preg_match('/(' . implode('|', $sensitive) . ')/i', $key)) {
                $out[$key] = '[masked]';
                continue;
            }
            if (is_string($v) && mb_strlen($v) > 200) {
                $out[$key] = mb_substr($v, 0, 200) . '…';
                continue;
            }
            $out[$key] = is_scalar($v) || $v === null ? $v : gettype($v);
        }

        return $out;
    }

    private function requestId(): ?string
    {
        if (function_exists('cpms_request_id')) {
            return cpms_request_id();
        }

        return null;
    }
}

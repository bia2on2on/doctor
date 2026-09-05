<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Sms;

use ClinicCore\Infrastructure\Sms\SmsSendException;

/**
 * طبقه‌بندی خطا برای Retry Policy (الزام §20):
 *
 * Retry:  Network Timeout / Temporary Provider Failure / Rate Limit (429) / 5xx
 * بدون Blind Retry: Invalid Mobile / Invalid Template / Invalid Credentials / Invalid Request
 */
final class RetryClassifier
{
    public const RETRYABLE = 'retryable';
    public const PERMANENT = 'permanent';

    public static function classify(\Throwable $e): string
    {
        if ($e instanceof SmsSendException) {
            return $e->isRetryable() ? self::RETRYABLE : self::PERMANENT;
        }
        if ($e instanceof SmsTemplateException) {
            return self::PERMANENT; // خطای تنظیمات/Template — تکرار بی‌فایده
        }

        // خطای نامشخص زیرساخت → به‌طور پیش‌فرض Retryable (با سقف Attempts + Dedupe)
        return self::RETRYABLE;
    }
}

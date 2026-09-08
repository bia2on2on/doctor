<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Storage;

use RuntimeException;

/**
 * پیکربندی ذخیره‌سازی خصوصی نامعتبر است.
 *
 * این استثنا عمداً یک خطای **پیکربندی** است، نه یک خطای دامنه: سیستم نباید
 * بی‌سروصدا به یک مسیر ناامن برگردد و نباید وانمود کند همه چیز عادی است.
 */
final class StorageConfigurationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $path = ''
    ) {
        parent::__construct($message);
    }

    public static function insideWebRoot(string $path, string $what): self
    {
        return new self(
            'CLINIC_STORAGE_INSIDE_WEBROOT',
            sprintf(
                'پیکربندی ناامن: مسیر %s داخل DocumentRoot است (%s). '
                . 'ذخیره‌سازی بالینی خصوصی باید بیرون از ریشهٔ وب باشد. '
                . 'مسیر را در تنظیمات به یک مسیر مطلقِ بیرون از DocumentRoot تغییر دهید '
                . 'یا ثابت CPMS_PRIVATE_STORAGE_DIR را در wp-config.php تعریف کنید.',
                $what,
                $path
            ),
            $path
        );
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Sms;

/**
 * رندر Template SMS با متغیرهای داخلی {{var}} (خالص — ADR-0025، الزام §12/§15).
 *
 * قواعد:
 *  - متغیر ناشناخته در متن → خطا (VALIDATION قبل از ارسال)
 *  - متغیر Required بدون مقدار → خطا
 *  - متغیرهای اضافی در $vars بی‌ضررند (فراخوانی نمی‌شوند)
 */
final class SmsTemplateRenderer
{
    private const VAR_PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * @param array<string, string> $vars
     *
     * @throws SmsTemplateException
     */
    public static function render(string $text, array $vars, array $allowedVars, array $requiredVars = []): string
    {
        $count = preg_match_all(self::VAR_PATTERN, $text, $matches);
        if ($count === false) {
            throw new SmsTemplateException('CLINIC_SMS_TEMPLATE_INVALID', 'رندر Template ناموفق بود');
        }

        // 1) متغیرهای موجود در متن باید مجاز باشند
        foreach ($matches[1] as $name) {
            if (!in_array($name, $allowedVars, true)) {
                throw new SmsTemplateException('CLINIC_SMS_UNKNOWN_VARIABLE', "متغیر {$name} برای این الگو مجاز نیست");
            }
        }

        // 2) متغیرهای Required باید مقدار داشته باشند
        foreach ($requiredVars as $name) {
            $value = (string) ($vars[$name] ?? '');
            if (trim($value) === '') {
                throw new SmsTemplateException('CLINIC_SMS_MISSING_VARIABLE', "مقدار متغیر {$name} لازم است");
            }
        }

        // 3) جایگذاری
        return (string) preg_replace_callback(
            self::VAR_PATTERN,
            static fn (array $m): string => (string) ($vars[$m[1]] ?? ''),
            $text
        );
    }

    /**
     * کشف متغیرهای استفاده‌شده در یک متن (برای Preview/Validation UI).
     *
     * @return list<string>
     */
    public static function findVariables(string $text): array
    {
        preg_match_all(self::VAR_PATTERN, $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}

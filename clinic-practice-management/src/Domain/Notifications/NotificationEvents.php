<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Notifications;

/**
 * کاتالوگ رویدادهای اعلان — notifications.md §3 (Event Catalog).
 *
 * Registry باز: رویداد جدید = افزودن یک entry (الگوی SmsEvents).
 * هر رویداد Internal یک Template (متن فارسی) + Recipient Policy دارد؛
 * تاریخ‌ها در لایه Service با UTC→Timezone کلینیک→Jalali رندر می‌شوند (N-6).
 */
final class NotificationEvents
{
    // نوبت
    public const APPT_CONFIRMED = 'appt_confirmed';
    public const APPT_REMINDER = 'appt_reminder';
    public const APPT_CHANGED = 'appt_changed';
    public const APPT_CANCELLED = 'appt_cancelled';

    // صف
    public const QUEUE_CALLED = 'queue_called';
    public const QUEUE_READY_PAYMENT = 'queue_ready_payment';

    // پیگیری / خروجی
    public const FOLLOWUP_REMINDER = 'followup_reminder';
    public const REPORT_EXPORT_READY = 'report_export_ready';

    /** گیرندگان Staff با Capability (به‌جز فراخواننده رویداد). */
    public const RECIPIENTS_QUEUE_STAFF = 'queue_staff';

    /** گیرنده = خود بیمار (recipient_patient_id). */
    public const RECIPIENT_PATIENT = 'patient';

    /** گیرنده = یک کاربر مشخص (مثلاً درخواست‌دهنده Export). */
    public const RECIPIENT_USER = 'user';

    /**
     * @return array<string, array{label: string, recipients: string, title: string, body: string}>
     */
    public static function all(): array
    {
        return [
            self::APPT_CONFIRMED => [
                'label' => 'تأیید نوبت',
                'recipients' => self::RECIPIENT_PATIENT,
                'title' => 'نوبت تأیید شد',
                'body' => 'نوبت شما با {doctor_name} در تاریخ {appointment_date} ساعت {appointment_time} تأیید شد.',
            ],
            self::APPT_REMINDER => [
                'label' => 'یادآوری نوبت',
                'recipients' => self::RECIPIENT_PATIENT,
                'title' => 'یادآوری نوبت',
                'body' => 'یادآوری: نوبت شما با {doctor_name} در تاریخ {appointment_date} ساعت {appointment_time}.',
            ],
            self::APPT_CHANGED => [
                'label' => 'جابه‌جایی نوبت',
                'recipients' => self::RECIPIENT_PATIENT,
                'title' => 'نوبت جابه‌جا شد',
                'body' => 'نوبت شما جابه‌جا شد: {doctor_name}، تاریخ {appointment_date} ساعت {appointment_time}.',
            ],
            self::APPT_CANCELLED => [
                'label' => 'لغو نوبت',
                'recipients' => self::RECIPIENT_PATIENT,
                'title' => 'نوبت لغو شد',
                'body' => 'نوبت شما با {doctor_name} در تاریخ {appointment_date} ساعت {appointment_time} لغو شد.',
            ],
            self::QUEUE_CALLED => [
                'label' => 'فراخوان بیمار',
                'recipients' => self::RECIPIENTS_QUEUE_STAFF,
                'title' => 'فراخوان بیمار',
                'body' => 'بیمار {patient_name} فراخوان شد (اتاق {room}).',
            ],
            self::QUEUE_READY_PAYMENT => [
                'label' => 'آماده پرداخت',
                'recipients' => self::RECIPIENTS_QUEUE_STAFF,
                'title' => 'آماده پرداخت',
                'body' => 'ویزیت {patient_name} تکمیل شد و آماده صدور فاکتور/دریافت پرداخت است.',
            ],
            self::FOLLOWUP_REMINDER => [
                'label' => 'یادآوری پیگیری',
                'recipients' => self::RECIPIENT_PATIENT,
                'title' => 'یادآوری پیگیری',
                'body' => 'یادآوری پیگیری: ویزیت بعدی شما با {doctor_name} در تاریخ {appointment_date} است.',
            ],
            self::REPORT_EXPORT_READY => [
                'label' => 'آماده شدن خروجی گزارش',
                'recipients' => self::RECIPIENT_USER,
                'title' => 'خروجی گزارش آماده شد',
                'body' => 'خروجی گزارش «{report_label}» آماده دانلود است (تا {expires_at}).',
            ],
        ];
    }

    /**
     * @return array{label: string, recipients: string, title: string, body: string}|null
     */
    public static function info(string $event): ?array
    {
        return self::all()[$event] ?? null;
    }

    public static function isKnown(string $event): bool
    {
        return isset(self::all()[$event]);
    }

    /**
     * رندر عنوان/متن با متغیرهای Payload — جايگزینی امن (بدون HTML).
     *
     * @param array<string, string> $vars
     * @return array{title: string, body: string}
     */
    public static function render(string $event, array $vars): array
    {
        $info = self::info($event) ?? ['title' => $event, 'body' => ''];

        $sub = static function (string $text) use ($vars): string {
            return preg_replace_callback(
                '/\{([a-z0-9_]+)\}/',
                static fn (array $m): string => $vars[$m[1]] ?? '',
                $text
            ) ?? $text;
        };

        return ['title' => $sub($info['title']), 'body' => $sub($info['body'])];
    }
}

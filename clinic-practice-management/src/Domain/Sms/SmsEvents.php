<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Sms;

/**
 * رویدادهای SMS — معنای واحد «SMS Template» برای Pattern/Template/Verify/Service Message
 * همه Providerها (ADR-0025، الزامات §9–12).
 *
 * Registry باز: Event جدید = افزودن یک entry (بدون تغییر بقیه سیستم).
 */
final class SmsEvents
{
    public const OTP = 'otp';
    public const APPT_CONFIRMED = 'appointment_confirmed';
    public const APPT_REMINDER = 'appointment_reminder';
    public const APPT_CANCELLED = 'appointment_cancelled';
    public const APPT_RESCHEDULED = 'appointment_rescheduled';
    public const FOLLOW_UP = 'follow_up_reminder';

    /**
     * متغیرهای داخلی استاندارد (الزام §12).
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        'otp_code' => 'کد ورود',
        'patient_name' => 'نام بیمار',
        'doctor_name' => 'نام پزشک',
        'appointment_date' => 'تاریخ نوبت',
        'appointment_time' => 'ساعت نوبت',
        'clinic_name' => 'نام مطب',
    ];

    /**
     * @return array<string, array{label: string, variables: list<string>, required: list<string>, default_text: string}>
     */
    public static function all(): array
    {
        $apptVars = ['patient_name', 'doctor_name', 'appointment_date', 'appointment_time', 'clinic_name'];
        $apptRequired = ['patient_name', 'appointment_date', 'appointment_time'];

        return [
            self::OTP => [
                'label' => 'کد ورود (OTP)',
                'variables' => ['otp_code'],
                'required' => ['otp_code'],
                'default_text' => 'کد ورود شما: {{otp_code}} — معتبر به مدت ۲ دقیقه.',
            ],
            self::APPT_CONFIRMED => [
                'label' => 'تأیید نوبت',
                'variables' => $apptVars,
                'required' => $apptRequired,
                'default_text' => 'سلام {{patient_name}}، نوبت شما با دکتر {{doctor_name}} در {{appointment_date}} ساعت {{appointment_time}} در {{clinic_name}} تأیید شد.',
            ],
            self::APPT_REMINDER => [
                'label' => 'یادآوری نوبت',
                'variables' => $apptVars,
                'required' => $apptRequired,
                'default_text' => 'یادآوری نوبت: {{patient_name}} عزیز، نوبت شما با دکتر {{doctor_name}} در {{appointment_date}} ساعت {{appointment_time}}.',
            ],
            self::APPT_CANCELLED => [
                'label' => 'لغو نوبت',
                'variables' => $apptVars,
                'required' => $apptRequired,
                'default_text' => 'سلام {{patient_name}}، نوبت شما با دکتر {{doctor_name}} در {{appointment_date}} ساعت {{appointment_time}} لغو شد.',
            ],
            self::APPT_RESCHEDULED => [
                'label' => 'جابه‌جایی نوبت',
                'variables' => $apptVars,
                'required' => $apptRequired,
                'default_text' => 'سلام {{patient_name}}، نوبت شما جابه‌جا شد: با دکتر {{doctor_name}} در {{appointment_date}} ساعت {{appointment_time}}.',
            ],
            self::FOLLOW_UP => [
                'label' => 'یادآوری پیگیری (Follow-up)',
                'variables' => $apptVars,
                'required' => $apptRequired,
                'default_text' => 'یادآوری پیگیری: {{patient_name}} عزیز، ویزیت با دکتر {{doctor_name}} در {{appointment_date}} ساعت {{appointment_time}}.',
            ],
        ];
    }

    public static function exists(string $event): bool
    {
        return array_key_exists($event, self::all());
    }

    /**
     * @return array{label: string, variables: list<string>, required: list<string>, default_text: string}|null
     */
    public static function info(string $event): ?array
    {
        $all = self::all();

        return $all[$event] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }
}

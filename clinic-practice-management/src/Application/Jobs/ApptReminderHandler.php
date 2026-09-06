<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\Settings;
use Throwable;

/**
 * Job: appt.reminder — یادآوری نوبت (background-jobs.md §2، FR-20.2/20.6).
 *
 * شب قبل + صبح روز (21:00/08:00 مفهومی): هر Tick، نوبت‌های confirmed با
 * slot_date = امروز/فردا (به وقت Timezone کلینیک) → SMS (در Quiet Hours) +
 * اعلان Internal به بیمار.
 *
 * Idempotency (J-2): بدون ستون ارجاع — Dedupe دو-لایه:
 *  - SMS: dedupe_key پایپ‌لاین SmsService (رویداد+کانتکست+روز ارسال).
 *  - Internal: dedupe_key فاز (`apt:{id}:remind:eve|morn`) → حداکثر ۲ اعلان.
 */
final class ApptReminderHandler
{
    private const LIMIT = 200;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly Settings $settings,
        private readonly SmsService $sms,
        private readonly NotificationService $notifications,
        private readonly OpLogger $op
    ) {
    }

    public function __invoke(array $payload): int
    {
        $today = $this->localToday();
        $tomorrow = gmdate('Y-m-d', strtotime($today . ' +1 day'));
        $reminded = 0;

        $rows = $this->db->fetchAll(
            'SELECT a.id, a.patient_id, a.slot_date, a.slot_time, a.clinician_id,
                    p.first_name, p.last_name, p.mobile
             FROM ' . $this->db->table('cpms_appointments') . ' a
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = a.patient_id
             WHERE a.clinic_id = 1 AND a.status = %s AND a.slot_date IN (%s, %s)
             ORDER BY a.id ASC LIMIT %d',
            ['confirmed', $today, $tomorrow, self::LIMIT]
        );

        // Quiet Hours (§5): SMS فقط در بازه مجاز؛ اعلان Internal همیشه
        // (اپ/صفحه درون‌برنامه‌ای مزاحم نیست) — OTP مستثنا (مسیر جدا).
        $smsOpen = $this->notifications->smsQuietHoursOpen();

        foreach ($rows as $row) {
            $phase = ((string) $row['slot_date']) === $today ? 'morn' : 'eve';
            $vars = $this->vars($row);

            try {
                if ($smsOpen) {
                    $this->sms->sendEvent(
                        SmsEvents::APPT_REMINDER,
                        (string) $row['mobile'],
                        $vars,
                        'appointment',
                        (int) $row['id']
                    );
                }
                $fresh = $this->notifications->publishToPatient(
                    (int) $row['patient_id'],
                    \ClinicCore\Domain\Notifications\NotificationEvents::APPT_REMINDER,
                    $vars,
                    'apt:' . (int) $row['id'] . ':remind:' . $phase
                ) !== null;
                if ($fresh) {
                    // فقط نوبت‌های «جدید» شمرده می‌شوند — rerun همان روز = 0 (J-2)
                    $reminded++;
                }
            } catch (Throwable $e) {
                // یادآوری هرگز نباید Job/صف را زمین بزند (الگوی BookingService)
                $this->op->warning('appt.reminder_failed', [
                    'appointment_id' => (int) $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $reminded;
    }

    /**
     * امروز به وقت Timezone کلینیک — slot_dateها تاریخ تقویم محلی مطب‌اند (ADR-0013).
     */
    private function localToday(): string
    {
        try {
            $tz = new \DateTimeZone($this->settings->clinicTimezone());

            return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        } catch (\Exception) {
            return gmdate('Y-m-d');
        }
    }

    /**
     * متغیرهای استاندارد ADR-0025 + تاریخ Jalali (N-6).
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function vars(array $row): array
    {
        $doctor = (string) $this->db->fetchValue(
            'SELECT full_name FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
            [(int) $row['clinician_id']]
        );
        $clinic = (string) $this->db->fetchValue(
            'SELECT name FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = 1 LIMIT 1'
        );
        $patientName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);

        return [
            'patient_name' => $patientName !== '' ? $patientName : 'بیمار گرامی',
            'doctor_name' => $doctor !== '' ? $doctor : 'پزشک',
            'appointment_date' => Jalali::formatYmd((string) $row['slot_date']),
            'appointment_time' => substr((string) $row['slot_time'], 0, 5),
            'clinic_name' => $clinic !== '' ? $clinic : 'مطب',
        ];
    }
}

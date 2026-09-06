<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Settings\Settings;
use Throwable;

/**
 * Job: fu.reminder — یادآوری Follow-Up سررسید (background-jobs.md §2).
 *
 * notifications.md §3: «Job روی suggested_date - 1d» → هر Tick، پیگیری‌های
 * pending با suggested_date = فردا (به وقت Timezone کلینیک) و بدون ارسال قبلی →
 * SMS (در Quiet Hours) + اعلان Internal بیمار.
 *
 * Idempotency (J-2): ستون reminder_sent_at (data-dictionary §25) — رکورد
 * فقط وقتی reminder_sent_at IS NULL انتخاب می‌شود و پس از ارسال ست می‌شود.
 */
final class FollowUpReminderHandler
{
    private const LIMIT = 100;

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
        $tomorrow = $this->localTomorrow();

        $rows = $this->db->fetchAll(
            'SELECT f.id, f.patient_id, f.suggested_date, f.clinician_id,
                    p.first_name, p.last_name, p.mobile
             FROM ' . $this->db->table('cpms_follow_ups') . ' f
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = f.patient_id
             WHERE f.clinic_id = 1 AND f.status = %s AND f.reminder_sent_at IS NULL
               AND f.suggested_date = %s
             ORDER BY f.id ASC LIMIT %d',
            ['pending', $tomorrow, self::LIMIT]
        );

        $smsOpen = $this->notifications->smsQuietHoursOpen();
        $reminded = 0;

        foreach ($rows as $row) {
            $vars = $this->vars($row);

            try {
                if ($smsOpen) {
                    $this->sms->sendEvent(
                        SmsEvents::FOLLOW_UP,
                        (string) $row['mobile'],
                        $vars,
                        'follow_up',
                        (int) $row['id']
                    );
                }
                $this->notifications->publishToPatient(
                    (int) $row['patient_id'],
                    NotificationEvents::FOLLOWUP_REMINDER,
                    $vars,
                    'fu:' . (int) $row['id'] . ':remind'
                );
            } catch (Throwable $e) {
                $this->op->warning('fu.reminder_failed', [
                    'follow_up_id' => (int) $row['id'],
                    'error' => $e->getMessage(),
                ]);
                continue; // reminder_sent_at ست نمی‌شود → تیک بعدی دوباره تلاش می‌کند
            }

            // Idempotency مارکر (J-2): فقط وقتی SMS هم واقعاً تلاش شده است ست
            // می‌شود — در Quiet Hours بسته، Internal می‌رود (Dedupe تکرار نمی‌سازد)
            // و تیک بعدی داخل بازه، SMS را هم می‌فرستد.
            if ($smsOpen) {
                $this->db->update('cpms_follow_ups', [
                    'reminder_sent_at' => $this->db->nowUtcSql(),
                ], ['id' => (int) $row['id']]);
            }
            $reminded++;
        }

        return $reminded;
    }

    private function localTomorrow(): string
    {
        try {
            $tz = new \DateTimeZone($this->settings->clinicTimezone());

            return (new \DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
        } catch (\Exception) {
            return gmdate('Y-m-d', strtotime('+1 day'));
        }
    }

    /**
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
            'appointment_date' => Jalali::formatYmd((string) $row['suggested_date']),
            'appointment_time' => '—', // Follow-Up ساعت مشخص ندارد
            'clinic_name' => $clinic !== '' ? $clinic : 'مطب',
        ];
    }
}

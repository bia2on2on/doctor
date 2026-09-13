<?php
declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Job: appt.reminder — Location-local calendar eligibility with bounded
 * keyset scanning and durable continuation.
 */
final class ApptReminderHandler
{
    private const LIMIT = 200;
    private const MAX_PAYLOAD_SIZE = 1024;
    private const PAYLOAD_VERSION = 1;
    private const MIN_TIMEZONE = 'Etc/GMT+12'; // UTC-12
    private const MAX_TIMEZONE = 'Etc/GMT-14'; // UTC+14

    /**
     * @param SettingsFactory|Settings $settingsFactory Legacy Settings is kept
     *        for direct test/caller compatibility; production uses the factory.
     * @param \Closure(int):NotificationService|null $notificationResolver
     */
    public function __construct(
        private readonly CpmsDb $db,
        private readonly SettingsFactory|Settings $settingsFactory,
        private readonly SmsService $sms,
        private readonly ?NotificationService $notifications,
        private readonly OpLogger $op,
        private readonly ?JobQueue $queue = null,
        private readonly ?\Closure $notificationResolver = null
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function __invoke(array $payload): int
    {
        $referenceUtc = $this->referenceUtc($payload);
        $cursor = $this->cursorFromPayload($payload);
        [$lowerDate, $upperDate] = $this->candidateWindow($referenceUtc);

        $where = 'a.status = %s AND a.location_id IS NOT NULL' .
            ' AND l.timezone <> %s AND a.slot_date BETWEEN %s AND %s';
        $params = ['confirmed', '', $lowerDate, $upperDate];
        if ($cursor !== null) {
            $where .= ' AND ((a.slot_date > %s)' .
                ' OR (a.slot_date = %s AND a.slot_time > %s)' .
                ' OR (a.slot_date = %s AND a.slot_time = %s AND a.id > %d))';
            $params[] = $cursor['slot_date'];
            $params[] = $cursor['slot_date'];
            $params[] = $cursor['slot_time'];
            $params[] = $cursor['slot_date'];
            $params[] = $cursor['slot_time'];
            $params[] = $cursor['id'];
        }

        $rows = $this->db->fetchAll(
            'SELECT a.id, a.clinic_id, a.location_id, a.patient_id, a.slot_date, a.slot_time,
                    a.clinician_id, l.clinic_id AS location_clinic_id, l.timezone AS location_timezone,
                    p.first_name, p.last_name, p.mobile
             FROM ' . $this->db->table('cpms_appointments') . ' a
             INNER JOIN ' . $this->db->table('cpms_locations') . ' l
                ON l.id = a.location_id AND l.clinic_id = a.clinic_id
             INNER JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = a.patient_id
             WHERE ' . $where . '
             ORDER BY a.slot_date ASC, a.slot_time ASC, a.id ASC
             LIMIT %d',
            array_merge($params, [self::LIMIT])
        );
        $rows = is_array($rows) ? $rows : [];

        $reminded = 0;
        $nextCursor = $cursor;
        foreach ($rows as $row) {
            // Advance after every selected row, including temporal rejects.
            $nextCursor = $this->rowCursor($row);
            if (!$this->eligibleForLocationDate($row, $referenceUtc)) {
                continue;
            }

            try {
                $clinicId = (int) ($row['clinic_id'] ?? 0);
                $notifications = $this->notificationServiceForClinic($clinicId);
                $vars = $this->vars($row);
                $phase = $this->phase($row, $referenceUtc);
                if ($notifications->smsQuietHoursOpen()) {
                    $this->sms->sendEvent(
                        $clinicId,
                        SmsEvents::APPT_REMINDER,
                        (string) $row['mobile'],
                        $vars,
                        'appointment',
                        (int) $row['id']
                    );
                }
                $fresh = $notifications->publishToPatient(
                    $clinicId,
                    (int) $row['patient_id'],
                    \ClinicCore\Domain\Notifications\NotificationEvents::APPT_REMINDER,
                    $vars,
                    'apt:' . (int) $row['id'] . ':remind:' . $phase
                ) !== null;
                if ($fresh) {
                    $reminded++;
                }
            } catch (Throwable $e) {
                // A malformed/failed row must not abort unrelated rows.
                $this->op->warning('appt.reminder_failed', [
                    'appointment_id' => (int) ($row['id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (count($rows) === self::LIMIT && $nextCursor !== null &&
            ($cursor === null || $this->strictlyGreater($nextCursor, $cursor))) {
            $this->enqueueContinuation($nextCursor, $referenceUtc);
        }
        return $reminded;
    }

    /** @param array<string,mixed> $payload */
    private function referenceUtc(array $payload): DateTimeImmutable
    {
        if ($payload === []) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $this->validatePayloadSize($payload);
        if (($payload['continuation'] ?? null) !== true ||
            ($payload['version'] ?? null) !== self::PAYLOAD_VERSION ||
            !isset($payload['reference_utc'], $payload['cursor']) ||
            !is_string($payload['reference_utc']) || !is_array($payload['cursor'])) {
            throw new ReminderPayloadInvalidException('REMINDER_PAYLOAD_INVALID', 'Invalid continuation structure');
        }
        $value = $payload['reference_utc'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            throw new ReminderPayloadInvalidException('REMINDER_PAYLOAD_INVALID', 'Invalid reference UTC');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
            $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new ReminderPayloadInvalidException('REMINDER_PAYLOAD_INVALID', 'Invalid reference UTC');
        }
        return $date;
    }

    /** @param array<string,mixed> $payload */
    private function cursorFromPayload(array $payload): ?array
    {
        if ($payload === []) {
            return null;
        }
        $cursor = $payload['cursor'] ?? null;
        if (!is_array($cursor) ||
            !is_string($cursor['slot_date'] ?? null) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cursor['slot_date']) ||
            !is_string($cursor['slot_time'] ?? null) ||
            !preg_match('/^\d{2}:\d{2}:\d{2}$/', $cursor['slot_time']) ||
            !is_int($cursor['id']) || $cursor['id'] <= 0) {
            throw new ReminderPayloadInvalidException('REMINDER_PAYLOAD_INVALID', 'Invalid reminder cursor');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cursor['slot_date']);
        $time = DateTimeImmutable::createFromFormat('!H:i:s', $cursor['slot_time']);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $time === false ||
            ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ReminderPayloadInvalidException('REMINDER_PAYLOAD_INVALID', 'Invalid reminder cursor date/time');
        }
        return [
            'slot_date' => $cursor['slot_date'],
            'slot_time' => $cursor['slot_time'],
            'id' => $cursor['id'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function validatePayloadSize(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json !== false && strlen($json) > self::MAX_PAYLOAD_SIZE) {
            throw new ReminderPayloadInvalidException('REMINDER_PAYLOAD_INVALID', 'Payload exceeds maximum size');
        }
    }

    /** @return array{0:string,1:string} */
    private function candidateWindow(DateTimeImmutable $referenceUtc): array
    {
        $minimum = $referenceUtc->setTimezone(new DateTimeZone(self::MIN_TIMEZONE));
        $maximum = $referenceUtc->setTimezone(new DateTimeZone(self::MAX_TIMEZONE))->modify('+1 day');
        return [$minimum->format('Y-m-d'), $maximum->format('Y-m-d')];
    }

    /** @param array<string,mixed> $row */
    private function eligibleForLocationDate(array $row, DateTimeImmutable $referenceUtc): bool
    {
        $clinicId = (int) ($row['clinic_id'] ?? 0);
        $locationClinicId = (int) ($row['location_clinic_id'] ?? 0);
        $timezone = trim((string) ($row['location_timezone'] ?? ''));
        if ($clinicId <= 0 || $locationClinicId !== $clinicId || $timezone === '') {
            return false;
        }
        try {
            $local = $referenceUtc->setTimezone(new DateTimeZone($timezone));
            $today = $local->format('Y-m-d');
            $tomorrow = $local->modify('+1 day')->format('Y-m-d');
            $date = (string) ($row['slot_date'] ?? '');
            return $date === $today || $date === $tomorrow;
        } catch (Throwable $e) {
            $this->op->warning('appt.reminder_location_timezone_invalid', [
                'appointment_id' => (int) ($row['id'] ?? 0),
                'location_id' => (int) ($row['location_id'] ?? 0),
            ]);
            return false;
        }
    }

    /** @param array<string,mixed> $row */
    private function phase(array $row, DateTimeImmutable $referenceUtc): string
    {
        $local = $referenceUtc->setTimezone(new DateTimeZone((string) $row['location_timezone']));
        return (string) $row['slot_date'] === $local->format('Y-m-d') ? 'morn' : 'eve';
    }

    /** @param array<string,mixed> $row */
    private function rowCursor(array $row): array
    {
        return [
            'slot_date' => (string) ($row['slot_date'] ?? ''),
            'slot_time' => (string) ($row['slot_time'] ?? ''),
            'id' => (int) ($row['id'] ?? 0),
        ];
    }

    /** @param array{slot_date:string,slot_time:string,id:int} $a
     * @param array{slot_date:string,slot_time:string,id:int} $b */
    private function strictlyGreater(array $a, array $b): bool
    {
        return [$a['slot_date'], $a['slot_time'], $a['id']] > [$b['slot_date'], $b['slot_time'], $b['id']];
    }

    /** @param array{slot_date:string,slot_time:string,id:int} $cursor */
    private function enqueueContinuation(array $cursor, DateTimeImmutable $referenceUtc): void
    {
        if ($this->queue === null) {
            return;
        }
        $payload = [
            'version' => self::PAYLOAD_VERSION,
            'continuation' => true,
            'reference_utc' => $referenceUtc->format('Y-m-d\TH:i:s\Z'),
            'cursor' => $cursor,
        ];
        try {
            $this->queue->enqueue('appt.reminder', $payload, new DateTimeImmutable('now', new DateTimeZone('UTC')), 4, 3);
        } catch (Throwable $e) {
            $this->op->warning('appt.reminder_continuation_enqueue_failed', ['error' => $e->getMessage()]);
        }
    }

    private function notificationServiceForClinic(int $clinicId): NotificationService
    {
        if ($this->notificationResolver !== null) {
            return ($this->notificationResolver)($clinicId);
        }
        if ($this->notifications !== null) {
            return $this->notifications;
        }
        throw new ReminderPayloadInvalidException('REMINDER_DEPENDENCY_INVALID', 'Notification service unavailable');
    }

    /** @param array<string,mixed> $row
     * @return array<string,string> */
    private function vars(array $row): array
    {
        $doctor = (string) $this->db->fetchValue(
            'SELECT full_name FROM ' . $this->db->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
            [(int) $row['clinician_id']]
        );
        $clinic = (string) $this->db->fetchValue(
            'SELECT name FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d LIMIT 1',
            [(int) $row['clinic_id']]
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

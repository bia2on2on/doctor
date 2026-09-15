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
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Job: appt.reminder — یادآوری نوبت (background-jobs.md §2، FR-20.2/20.6).
 *
 * W-sweep: هر candidate از خودِ Appointment و Location ذخیره‌شده‌اش شناخته
 * می‌شود. Calendar eligibility هرگز از Clinic timezone، WordPress timezone یا
 * default timezone فرایند مشتق نمی‌شود.
 *
 * Continuation contract:
 * - [] = root سازگار با Job زمان‌بندی‌شدهٔ قدیمی
 * - continuation v1 = reference UTC ثابت + cursor کلیدمرتب
 * - payload مخدوش fail-closed است؛ هرگز root restart نمی‌شود.
 *
 * Idempotency (J-2):
 * - SMS: dedupe_key پایپ‌لاین SmsService (رویداد+کانتکست+روز ارسال).
 * - Internal: dedupe_key فاز (`apt:{id}:remind:eve|morn`) → حداکثر ۲ اعلان.
 */
final class ApptReminderHandler
{
    /** هر page کوچک است تا joinِ Location/Patient پاسخ حجیم نسازد. */
    private const QUERY_BATCH_SIZE = 40;

    /** کمتر از LIMIT تاریخی 200؛ حداکثر سه page در یک اجرای Job. */
    private const MAX_SCANNED_CANDIDATES = 100;

    /** سقف مستقل side-effectهای SMS/Internal در یک اجرای Job. */
    private const MAX_NOTIFICATION_ATTEMPTS = 60;

    private const MAX_PAYLOAD_SIZE = 1024;

    private const PAYLOAD_VERSION = 1;

    private const REFERENCE_UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @var array<string, array{lower:string,upper:string}> */
    private static array $candidateWindowCache = [];

    /**
     * @param \Closure(int): NotificationService $notificationForClinic
     * @param \Closure(): DateTimeImmutable|null $utcNow test seam؛ در production null = UTC now
     */
    private readonly CpmsDb $db;
    private readonly SmsService $sms;
    /** @var \Closure(int): NotificationService */
    private readonly \Closure $notificationForClinic;
    private readonly JobQueue $queue;
    private readonly OpLogger $op;
    private readonly ?\Closure $utcNow;

    /**
     * The first form is the scope-neutral T3 constructor. The second form is
     * retained for existing direct handler tests and third-party callers:
     * `(db, Settings, SmsService, NotificationService, OpLogger)`.
     */
    public function __construct(
        CpmsDb $db,
        SmsService|Settings $smsOrLegacySettings,
        \Closure|SmsService $notificationFactoryOrSms,
        JobQueue|NotificationService $queueOrLegacyNotifications,
        OpLogger $op,
        ?\Closure $utcNow = null
    ) {
        $this->db = $db;
        $this->op = $op;
        $this->utcNow = $utcNow;

        if ($smsOrLegacySettings instanceof Settings) {
            if (!$notificationFactoryOrSms instanceof SmsService
                || !$queueOrLegacyNotifications instanceof NotificationService
            ) {
                throw new \InvalidArgumentException('Invalid legacy appt.reminder dependencies');
            }
            $legacyNotifications = $queueOrLegacyNotifications;
            $this->sms = $notificationFactoryOrSms;
            $this->notificationForClinic = static fn (int $clinicId): NotificationService => $legacyNotifications;
            $this->queue = new JobQueue($db, $op);

            return;
        }

        if (!$notificationFactoryOrSms instanceof \Closure || !$queueOrLegacyNotifications instanceof JobQueue) {
            throw new \InvalidArgumentException('Invalid scope-neutral appt.reminder dependencies');
        }
        $this->sms = $smsOrLegacySettings;
        $this->notificationForClinic = $notificationFactoryOrSms;
        $this->queue = $queueOrLegacyNotifications;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws JobPayloadInvalidException اگر payload continuation معتبر نباشد
     */
    public function __invoke(array $payload): int
    {
        if ($payload === []) {
            $referenceUtc = $this->rootReferenceUtc();
            $incomingCursor = null;
        } else {
            $continuation = $this->parseContinuationPayload($payload);
            $referenceUtc = $continuation['reference_utc'];
            $incomingCursor = $continuation['cursor'];
        }

        $scan = $this->scanCandidates($incomingCursor, $referenceUtc, $this->candidateWindow($referenceUtc));
        $this->maybeEnqueueContinuation(
            $scan['has_more'],
            $scan['next_cursor'],
            $incomingCursor,
            $referenceUtc
        );

        return $scan['reminded'];
    }

    /**
     * Root فقط یک‌بار زمان UTC می‌گیرد و آن را به نمایش machine-readable بدون
     * microsecond نرمال می‌کند؛ continuation فقط همان رشته را parse می‌کند.
     */
    private function rootReferenceUtc(): DateTimeImmutable
    {
        $now = $this->utcNow === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($this->utcNow)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('appt.reminder clock must return DateTimeImmutable');
        }

        $normalized = $now->setTimezone(new DateTimeZone('UTC'))->format(self::REFERENCE_UTC_FORMAT);
        $reference = $this->parseReferenceUtc($normalized);
        if ($reference === null) {
            throw new RuntimeException('appt.reminder could not normalize root UTC reference');
        }

        return $reference;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{reference_utc:DateTimeImmutable,cursor:array{slot_date:string,slot_time:string,id:int}}
     */
    private function parseContinuationPayload(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_PAYLOAD_SIZE) {
            $this->failPayload('Payload exceeds maximum size', ['size' => $json === false ? null : strlen($json)]);
        }

        $keys = array_keys($payload);
        sort($keys);
        $expected = ['continuation', 'cursor', 'reference_utc', 'version'];
        sort($expected);
        if ($keys !== $expected) {
            $this->failPayload('Unexpected continuation payload structure', ['keys' => $keys]);
        }

        if (($payload['continuation'] ?? null) !== true) {
            $this->failPayload('Continuation marker must be true', []);
        }
        if (!is_int($payload['version']) || $payload['version'] !== self::PAYLOAD_VERSION) {
            $this->failPayload('Unsupported continuation version', ['version' => $payload['version'] ?? null]);
        }
        if (!is_string($payload['reference_utc'])) {
            $this->failPayload('Reference UTC must be a string', []);
        }

        $reference = $this->parseReferenceUtc($payload['reference_utc']);
        if ($reference === null) {
            $this->failPayload('Invalid reference UTC', ['reference_utc' => $payload['reference_utc']]);
        }

        $cursor = $this->parseCursor($payload['cursor'] ?? null);
        if ($cursor === null) {
            $this->failPayload('Invalid continuation cursor', ['cursor' => $payload['cursor'] ?? null]);
        }

        return ['reference_utc' => $reference, 'cursor' => $cursor];
    }

    /**
     * @return array{slot_date:string,slot_time:string,id:int}|null
     */
    private function parseCursor(mixed $cursor): ?array
    {
        if (!is_array($cursor)) {
            return null;
        }
        $keys = array_keys($cursor);
        sort($keys);
        $expected = ['id', 'slot_date', 'slot_time'];
        sort($expected);
        if ($keys !== $expected
            || !is_string($cursor['slot_date'] ?? null)
            || !is_string($cursor['slot_time'] ?? null)
            || !is_int($cursor['id'] ?? null)
            || $cursor['id'] <= 0
        ) {
            return null;
        }
        if (!$this->isExactDate($cursor['slot_date']) || !$this->isExactTime($cursor['slot_time'])) {
            return null;
        }

        return [
            'slot_date' => $cursor['slot_date'],
            'slot_time' => $cursor['slot_time'],
            'id' => $cursor['id'],
        ];
    }

    private function parseReferenceUtc(string $reference): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $reference) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s\\Z',
            $reference,
            new DateTimeZone('UTC')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format(self::REFERENCE_UTC_FORMAT) !== $reference
        ) {
            return null;
        }

        return $parsed;
    }

    private function isExactDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function isExactTime(string $value): bool
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return false;
        }
        $time = DateTimeImmutable::createFromFormat('!H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $time !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $time->format('H:i:s') === $value;
    }

    /**
     * Conservative prefilter over every valid IANA zone supported by this PHP
     * runtime. For one fixed UTC instant, each Location-local "today" lies in
     * [min local date, max local date]; adding one local calendar day gives the
     * inclusive upper bound. Final eligibility still uses the row's actual zone.
     *
     * @return array{lower:string,upper:string}
     */
    private function candidateWindow(DateTimeImmutable $referenceUtc): array
    {
        $key = $referenceUtc->format(self::REFERENCE_UTC_FORMAT);
        if (isset(self::$candidateWindowCache[$key])) {
            return self::$candidateWindowCache[$key];
        }

        $min = null;
        $max = null;
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $identifier) {
            try {
                $localDate = $referenceUtc->setTimezone(new DateTimeZone($identifier))->format('Y-m-d');
            } catch (\Exception) {
                // Some PHP/tzdata builds expose registry metadata such as
                // `leapseconds` or `tzdata.zi`; these are not IANA zones.
                continue;
            }
            $min = $min === null || $localDate < $min ? $localDate : $min;
            $max = $max === null || $localDate > $max ? $localDate : $max;
        }
        if ($min === null || $max === null) {
            throw new RuntimeException('appt.reminder could not derive candidate date window');
        }

        $maxDate = DateTimeImmutable::createFromFormat('!Y-m-d', $max, new DateTimeZone('UTC'));
        if ($maxDate === false) {
            throw new RuntimeException('appt.reminder derived invalid candidate upper date');
        }
        $window = ['lower' => $min, 'upper' => $maxDate->modify('+1 day')->format('Y-m-d')];
        self::$candidateWindowCache[$key] = $window;

        return $window;
    }

    /**
     * @param array{slot_date:string,slot_time:string,id:int}|null $incomingCursor
     * @param array{lower:string,upper:string} $window
     * @return array{reminded:int,scanned:int,notification_attempts:int,has_more:bool,next_cursor:?array{slot_date:string,slot_time:string,id:int}}
     */
    private function scanCandidates(?array $incomingCursor, DateTimeImmutable $referenceUtc, array $window): array
    {
        $cursor = $incomingCursor;
        $scanned = 0;
        $notificationAttempts = 0;
        $reminded = 0;
        $hasMore = false;
        /** @var array<int, NotificationService> $notificationServices */
        $notificationServices = [];

        while ($scanned < self::MAX_SCANNED_CANDIDATES && $notificationAttempts < self::MAX_NOTIFICATION_ATTEMPTS) {
            $pageSize = min(self::QUERY_BATCH_SIZE, self::MAX_SCANNED_CANDIDATES - $scanned);
            $rows = $this->fetchCandidatePage($cursor, $window, $pageSize + 1);
            if ($rows === []) {
                break;
            }

            $pageHasMore = count($rows) > $pageSize;
            $page = array_slice($rows, 0, $pageSize);
            foreach ($page as $row) {
                if ($notificationAttempts >= self::MAX_NOTIFICATION_ATTEMPTS) {
                    $hasMore = true;
                    break 2;
                }

                $candidateCursor = [
                    'slot_date' => (string) $row['slot_date'],
                    'slot_time' => (string) $row['slot_time'],
                    'id' => (int) $row['id'],
                ];
                // Query rows come from DATE/TIME/positive primary-key columns. This
                // defensive check preserves the continuation payload contract.
                if ($this->parseCursor($candidateCursor) === null) {
                    $this->op->warning('appt.reminder_candidate_cursor_invalid', [
                        'appointment_id' => (int) $row['id'],
                    ]);
                    continue;
                }

                $cursor = $candidateCursor; // advances for every scanned candidate, including rejects.
                ++$scanned;

                if (!$this->isLocationEligible($row, $referenceUtc)) {
                    continue;
                }

                ++$notificationAttempts;
                $clinicId = (int) $row['clinic_id'];
                $notificationService = $notificationServices[$clinicId] ??= $this->notificationServiceForClinic($clinicId);
                if ($this->dispatchReminder($row, $notificationService, $referenceUtc)) {
                    ++$reminded;
                }
            }

            if (!$pageHasMore) {
                break;
            }
            if ($scanned >= self::MAX_SCANNED_CANDIDATES || $notificationAttempts >= self::MAX_NOTIFICATION_ATTEMPTS) {
                $hasMore = true;
                break;
            }
        }

        return [
            'reminded' => $reminded,
            'scanned' => $scanned,
            'notification_attempts' => $notificationAttempts,
            'has_more' => $hasMore,
            'next_cursor' => $cursor,
        ];
    }

    /**
     * Location join is structural: missing Location or Clinic/Location mismatch
     * cannot enter the candidate set. Non-empty timezone is required here;
     * invalid IANA names are rejected per-row in isLocationEligible().
     *
     * @param array{slot_date:string,slot_time:string,id:int}|null $cursor
     * @param array{lower:string,upper:string} $window
     * @return list<array<string,mixed>>
     */
    private function fetchCandidatePage(?array $cursor, array $window, int $limit): array
    {
        $sql = 'SELECT a.id, a.clinic_id, a.location_id, a.patient_id, a.clinician_id, a.slot_date, a.slot_time,
                       p.first_name, p.last_name, p.mobile,
                       l.clinic_id AS location_clinic_id, l.timezone AS location_timezone
                FROM ' . $this->db->table('cpms_appointments') . ' a
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = a.patient_id
                JOIN ' . $this->db->table('cpms_locations') . ' l
                  ON l.id = a.location_id AND l.clinic_id = a.clinic_id
                WHERE a.status = %s AND a.slot_date >= %s AND a.slot_date <= %s
                  AND l.timezone IS NOT NULL AND TRIM(l.timezone) <> %s';
        $params = ['confirmed', $window['lower'], $window['upper'], ''];

        if ($cursor !== null) {
            $sql .= ' AND (a.slot_date > %s
                    OR (a.slot_date = %s AND a.slot_time > %s)
                    OR (a.slot_date = %s AND a.slot_time = %s AND a.id > %d))';
            array_push(
                $params,
                $cursor['slot_date'],
                $cursor['slot_date'],
                $cursor['slot_time'],
                $cursor['slot_date'],
                $cursor['slot_time'],
                $cursor['id']
            );
        }

        $sql .= ' ORDER BY a.slot_date ASC, a.slot_time ASC, a.id ASC LIMIT %d';
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Final row-level Location-local calendar test. This deliberately does not
     * use the Clinic timezone, PHP default timezone, WordPress timezone, or a
     * fallback Location.
     *
     * @param array<string,mixed> $row
     */
    private function isLocationEligible(array $row, DateTimeImmutable $referenceUtc): bool
    {
        if ((int) ($row['location_clinic_id'] ?? 0) !== (int) ($row['clinic_id'] ?? 0)
            || (int) ($row['location_id'] ?? 0) <= 0
        ) {
            $this->op->warning('appt.reminder_location_clinic_mismatch', [
                'appointment_id' => (int) ($row['id'] ?? 0),
                'location_id' => (int) ($row['location_id'] ?? 0),
            ]);

            return false;
        }

        $timezone = trim((string) ($row['location_timezone'] ?? ''));
        if ($timezone === '') {
            $this->op->warning('appt.reminder_location_timezone_missing', [
                'appointment_id' => (int) ($row['id'] ?? 0),
                'location_id' => (int) ($row['location_id'] ?? 0),
            ]);

            return false;
        }

        try {
            $locationZone = new DateTimeZone($timezone);
            $localReference = $referenceUtc->setTimezone($locationZone);
            $today = $localReference->format('Y-m-d');
            $tomorrow = $localReference->modify('+1 day')->format('Y-m-d');

            return (string) $row['slot_date'] === $today || (string) $row['slot_date'] === $tomorrow;
        } catch (\Exception) {
            $this->op->warning('appt.reminder_location_timezone_invalid', [
                'appointment_id' => (int) ($row['id'] ?? 0),
                'location_id' => (int) ($row['location_id'] ?? 0),
            ]);

            return false;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function dispatchReminder(
        array $row,
        NotificationService $notifications,
        DateTimeImmutable $referenceUtc
    ): bool
    {
        try {
            $vars = $this->vars($row);
            if ($notifications->smsQuietHoursOpen(
                (string) ($row['location_timezone'] ?? ''),
                $referenceUtc
            )) {
                $this->sms->sendEvent(
                    (int) $row['clinic_id'],
                    SmsEvents::APPT_REMINDER,
                    (string) $row['mobile'],
                    $vars,
                    'appointment',
                    (int) $row['id']
                );
            }

            return $notifications->publishToPatient(
                (int) $row['clinic_id'],
                (int) $row['patient_id'],
                NotificationEvents::APPT_REMINDER,
                $vars,
                'apt:' . (int) $row['id'] . ':remind:' . $this->reminderPhase($row, $referenceUtc)
            ) !== null;
        } catch (Throwable $e) {
            // A broken row/provider is isolated from unrelated candidates; the
            // cursor has already advanced for this row.
            $this->op->warning('appt.reminder_failed', [
                'appointment_id' => (int) $row['id'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The phase is derived from the same fixed chain reference and row Location
     * timezone used by eligibility; no later wall-clock read can change it.
     *
     * @param array<string,mixed> $row
     */
    private function reminderPhase(array $row, DateTimeImmutable $referenceUtc): string
    {
        try {
            $zone = new DateTimeZone((string) $row['location_timezone']);
            $today = $referenceUtc->setTimezone($zone)->format('Y-m-d');

            return (string) $row['slot_date'] === $today ? 'morn' : 'eve';
        } catch (\Exception) {
            // isLocationEligible() already accepted the row with the stable chain
            // reference. This branch is defensive only and does not alter eligibility.
            return 'eve';
        }
    }

    /**
     * @return NotificationService
     */
    private function notificationServiceForClinic(int $clinicId): NotificationService
    {
        $service = ($this->notificationForClinic)($clinicId);
        if (!$service instanceof NotificationService) {
            throw new RuntimeException('appt.reminder notification factory must return NotificationService');
        }

        return $service;
    }

    /**
     * @param array{slot_date:string,slot_time:string,id:int}|null $nextCursor
     * @param array{slot_date:string,slot_time:string,id:int}|null $incomingCursor
     */
    private function maybeEnqueueContinuation(
        bool $hasMore,
        ?array $nextCursor,
        ?array $incomingCursor,
        DateTimeImmutable $referenceUtc
    ): void {
        if (!$hasMore || $nextCursor === null) {
            return;
        }
        if ($incomingCursor !== null && !$this->isCursorStrictlyGreater($nextCursor, $incomingCursor)) {
            $this->op->warning('appt.reminder_cursor_not_advancing', [
                'incoming' => $incomingCursor,
                'next' => $nextCursor,
            ]);

            return;
        }

        $payload = [
            'continuation' => true,
            'version' => self::PAYLOAD_VERSION,
            'reference_utc' => $referenceUtc->format(self::REFERENCE_UTC_FORMAT),
            'cursor' => $nextCursor,
        ];

        // One call only. If durable enqueue fails, fail the current Job so the
        // queue's existing retry semantics can retry; swallowing would make a
        // bounded prefix a permanent starvation point.
        try {
            $this->queue->enqueue('appt.reminder', $payload, $referenceUtc, 4, 3);
        } catch (Throwable $e) {
            $this->op->error('appt.reminder_continuation_enqueue_failed', [
                'error' => $e->getMessage(),
                'cursor' => $nextCursor,
            ]);
            throw $e;
        }
    }

    /**
     * @param array{slot_date:string,slot_time:string,id:int} $a
     * @param array{slot_date:string,slot_time:string,id:int} $b
     */
    private function isCursorStrictlyGreater(array $a, array $b): bool
    {
        if ($a['slot_date'] !== $b['slot_date']) {
            return $a['slot_date'] > $b['slot_date'];
        }
        if ($a['slot_time'] !== $b['slot_time']) {
            return $a['slot_time'] > $b['slot_time'];
        }

        return $a['id'] > $b['id'];
    }

    /**
     * متغیرهای استاندارد ADR-0025 + تاریخ Jalali (N-6).
     *
     * این دو lookup per-reminder از قبل وجود داشتند؛ T3 فقط Location را به
     * candidate query join کرده و N+1 جدیدی برای Location ایجاد نمی‌کند.
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

    /**
     * @param array<string,mixed> $data
     * @return never
     */
    private function failPayload(string $message, array $data): never
    {
        $this->op->warning('appt.reminder_payload_invalid', $data);
        throw new JobPayloadInvalidException('JOB_PAYLOAD_INVALID', $message, $data);
    }
}

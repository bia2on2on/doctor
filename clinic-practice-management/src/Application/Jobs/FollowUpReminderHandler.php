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
use ClinicCore\Settings\SettingsFactory;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Job: fu.reminder — یادآوری Follow-Up سررسید (background-jobs.md §2).
 *
 * Phase 2 M-2 temporal slice + starvation fix:
 *  - Scope-neutral construction (no ambient App::settings()/notificationService at build time)
 *  - Authoritative per-row Location calendar: follow_up.visit_id -> visits.location_id -> locations.timezone
 *  - Each row's suggested_date is compared to Location-local "tomorrow" derived from a single
 *    reference UTC instant for the whole chain.
 *  - Malformed/mismatched rows fail closed individually with warning, sweep continues.
 *  - No fallback to Clinic timezone, WordPress timezone, or PHP ambient timezone.
 *  - Per-Clinic NotificationService via factory for quiet-hours (OUT OF SCOPE: policy not redesigned)
 *  - SmsService remains scope-neutral, receives explicit durable clinic_id.
 *
 *  - Continuation contract (starvation fix):
 *    [] = root sweep (legacy compatible)
 *    {continuation:true, version:1, reference_utc:"Y-m-d\TH:i:s\Z", cursor:{id:int}} = continuation
 *    Malformed continuation => throw JobPayloadInvalidException JOB_PAYLOAD_INVALID (fail-closed, never silent root)
 *    reference_utc remains constant across chain to avoid Location-local drift
 *    cursor is progress only (id ASC), never tenant authorization, no Clinic ID from payload
 *    Query uses keyset f.id > cursor_id ORDER BY f.id ASC LIMIT bounded, no OFFSET
 *    Cursor advances for EVERY candidate scanned, including rejected/mismatched/calendar-ineligible
 *    At most one continuation job per handler execution
 *    Duplicate execution remains harmless (idempotent reminder_sent_at)
 *
 * Idempotency (J-2): reminder_sent_at column — row only selected when IS NULL and set after send.
 * When quiet-hours closed, internal notification goes, reminder_sent_at stays NULL → next tick retries SMS.
 */
final class FollowUpReminderHandler
{
    private const LIMIT = 100;
    private const PAYLOAD_VERSION = 1;
    private const REFERENCE_UTC_FORMAT = 'Y-m-d\TH:i:s\Z';
    private const MAX_PAYLOAD_SIZE = 2048;

    /** @var array<string, array{lower:string,upper:string}> cache per reference UTC string */
    private static array $candidateWindowCache = [];

    /** @var array<string, bool> validated IANA identifiers */
    private array $timezoneValid = [];

    /** @var array<int, NotificationService> per-Clinic notification cache */
    private array $notificationCache = [];

    private readonly CpmsDb $db;
    private readonly SmsService $sms;
    private readonly OpLogger $op;
    /** @var \Closure(int): NotificationService|null */
    private readonly ?\Closure $notificationForClinic;
    private readonly ?SettingsFactory $settingsFactory;
    private readonly ?Settings $legacySettings;
    private readonly ?NotificationService $legacyNotifications;
    private readonly ?JobQueue $queue;
    /** @var \Closure(): DateTimeImmutable|null */
    private readonly ?\Closure $utcNow;

    /**
     * New scope-neutral constructor:
     *   (CpmsDb, SettingsFactory, SmsService, Closure(int):NotificationService, OpLogger, ?JobQueue, ?Closure)
     * Legacy constructor (retained for existing tests):
     *   (CpmsDb, Settings, SmsService, NotificationService, OpLogger, ?JobQueue, ?Closure)
     * Also supports older 6-arg form where 6th is Closure utcNow (queue null).
     */
    public function __construct(
        CpmsDb $db,
        SettingsFactory|Settings $settingsFactoryOrLegacySettings,
        SmsService $sms,
        \Closure|NotificationService $notificationFactoryOrLegacyNotifications,
        OpLogger $op,
        JobQueue|\Closure|null $queueOrUtcNow = null,
        ?\Closure $utcNow = null
    ) {
        $this->db = $db;
        $this->op = $op;

        // Flexible handling of 6th arg being either JobQueue or Closure (utcNow)
        $resolvedQueue = null;
        $resolvedUtcNow = null;
        if ($queueOrUtcNow instanceof JobQueue) {
            $resolvedQueue = $queueOrUtcNow;
            $resolvedUtcNow = $utcNow;
        } elseif ($queueOrUtcNow instanceof \Closure) {
            $resolvedQueue = null;
            $resolvedUtcNow = $queueOrUtcNow;
            // If 7th also provided, prefer 7th? But 6th being Closure means legacy call with utcNow as 6th.
            // If 7th is also Closure, use 7th as override (should not happen).
            if ($utcNow instanceof \Closure) {
                $resolvedUtcNow = $utcNow;
            }
        } else {
            $resolvedQueue = null;
            $resolvedUtcNow = $utcNow;
        }

        $this->queue = $resolvedQueue;
        $this->utcNow = $resolvedUtcNow;

        if ($settingsFactoryOrLegacySettings instanceof Settings) {
            // Legacy path
            if (!$notificationFactoryOrLegacyNotifications instanceof NotificationService) {
                throw new \InvalidArgumentException('Invalid legacy fu.reminder dependencies');
            }
            $this->settingsFactory = null;
            $this->legacySettings = $settingsFactoryOrLegacySettings;
            $this->legacyNotifications = $notificationFactoryOrLegacyNotifications;
            $this->notificationForClinic = null;
            $this->sms = $sms;
            return;
        }

        // New path
        if (!$settingsFactoryOrLegacySettings instanceof SettingsFactory
            || !$notificationFactoryOrLegacyNotifications instanceof \Closure) {
            throw new \InvalidArgumentException('Invalid scope-neutral fu.reminder dependencies');
        }
        $this->settingsFactory = $settingsFactoryOrLegacySettings;
        $this->legacySettings = null;
        $this->legacyNotifications = null;
        $this->notificationForClinic = $notificationFactoryOrLegacyNotifications;
        $this->sms = $sms;
    }

    /**
     * @param array<string,mixed> $payload
     * @throws JobPayloadInvalidException
     */
    public function __invoke(array $payload): int
    {
        // Root vs continuation parsing
        if ($payload === []) {
            $referenceUtc = $this->rootReferenceUtc();
            $cursorId = 0;
        } else {
            $parsed = $this->parseContinuationPayload($payload);
            $referenceUtc = $parsed['reference_utc'];
            $cursorId = $parsed['cursor']['id'];
        }

        $window = $this->candidateWindow($referenceUtc);

        // Fetch LIMIT+1 to detect has_more without extra query
        $fetchLimit = self::LIMIT + 1;
        $rows = $this->fetchCandidatePage($cursorId, $window, $fetchLimit);

        $hasMore = count($rows) > self::LIMIT;
        $page = $hasMore ? array_slice($rows, 0, self::LIMIT) : $rows;

        $reminded = 0;
        $lastScannedId = $cursorId;
        $scanned = 0;

        foreach ($page as $row) {
            $followUpId = (int) ($row['id'] ?? 0);
            // Cursor advances for EVERY candidate scanned, including rejected
            $lastScannedId = $followUpId > $lastScannedId ? $followUpId : $lastScannedId;
            $scanned++;

            $clinicId = (int) ($row['clinic_id'] ?? 0);

            // ---- Validation chain: fail-closed per row ----
            if ($clinicId <= 0) {
                $this->op->warning('fu.reminder_follow_up_clinic_invalid', ['follow_up_id' => $followUpId]);
                continue;
            }

            $visitId = (int) ($row['visit_id'] ?? 0);
            $visitJoinedId = (int) ($row['visit_id_joined'] ?? 0);
            $visitClinicId = (int) ($row['visit_clinic_id'] ?? 0);
            $visitLocationId = (int) ($row['visit_location_id'] ?? 0);

            if ($visitJoinedId <= 0 || $visitId !== $visitJoinedId) {
                $this->op->warning('fu.reminder_visit_missing', [
                    'follow_up_id' => $followUpId,
                    'visit_id' => $visitId,
                ]);
                continue;
            }

            if ($visitClinicId !== $clinicId) {
                $this->op->warning('fu.reminder_clinic_mismatch', [
                    'follow_up_id' => $followUpId,
                    'follow_up_clinic_id' => $clinicId,
                    'visit_clinic_id' => $visitClinicId,
                ]);
                continue;
            }

            $locationId = (int) ($row['location_id'] ?? 0);
            $locationClinicId = (int) ($row['location_clinic_id'] ?? 0);
            $locationTzRaw = (string) ($row['location_timezone'] ?? '');

            if ($locationId <= 0 || $visitLocationId <= 0 || $locationId !== $visitLocationId) {
                $this->op->warning('fu.reminder_location_missing', [
                    'follow_up_id' => $followUpId,
                    'visit_location_id' => $visitLocationId,
                    'location_id' => $locationId,
                ]);
                continue;
            }

            if ($locationClinicId !== $clinicId) {
                $this->op->warning('fu.reminder_location_clinic_mismatch', [
                    'follow_up_id' => $followUpId,
                    'follow_up_clinic_id' => $clinicId,
                    'location_id' => $locationId,
                    'location_clinic_id' => $locationClinicId,
                ]);
                continue;
            }

            $locationTz = $this->locationTimezone($locationTzRaw);
            if ($locationTz === null) {
                $this->op->warning('fu.reminder_location_timezone_invalid', [
                    'follow_up_id' => $followUpId,
                    'location_id' => $locationId,
                    'timezone' => $locationTzRaw,
                ]);
                continue;
            }

            // ---- Location-local tomorrow computation ----
            try {
                $localTomorrow = $referenceUtc->setTimezone($locationTz)->modify('+1 day')->format('Y-m-d');
            } catch (\Throwable $e) {
                $this->op->warning('fu.reminder_timezone_conversion_failed', [
                    'follow_up_id' => $followUpId,
                    'location_id' => $locationId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $suggestedDate = (string) ($row['suggested_date'] ?? '');
            if ($suggestedDate !== $localTomorrow) {
                // Not due for this Location's calendar
                continue;
            }

            // ---- Per-Clinic NotificationService (quiet-hours remains OUT OF SCOPE, not redesigned) ----
            $notifications = $this->notificationServiceForClinic($clinicId);
            $smsOpen = $notifications->smsQuietHoursOpen($locationTzRaw, $referenceUtc);

            $vars = $this->vars($row);

            try {
                if ($smsOpen) {
                    $this->sms->sendEvent(
                        $clinicId,
                        SmsEvents::FOLLOW_UP,
                        (string) $row['mobile'],
                        $vars,
                        'follow_up',
                        $followUpId
                    );
                }
                $notifications->publishToPatient(
                    $clinicId,
                    (int) $row['patient_id'],
                    NotificationEvents::FOLLOWUP_REMINDER,
                    $vars,
                    'fu:' . $followUpId . ':remind'
                );
            } catch (Throwable $e) {
                $this->op->warning('fu.reminder_failed', [
                    'follow_up_id' => $followUpId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($smsOpen) {
                $this->db->update('cpms_follow_ups', [
                    'reminder_sent_at' => $this->db->nowUtcSql(),
                ], ['id' => $followUpId]);
            }
            $reminded++;
        }

        // If we scanned LIMIT rows and there may be more, enqueue continuation
        // Cursor is progress only, even if all scanned rows were rejected
        if ($hasMore || $scanned === self::LIMIT) {
            // Check if there are more rows beyond lastScannedId within window and pending
            // We already know hasMore if we fetched LIMIT+1, but also need to handle case where scanned==LIMIT but we didn't fetch extra
            // For safety, we treat hasMore true when we fetched LIMIT+1, or when we scanned LIMIT and need to check existence
            $shouldContinue = $hasMore;
            if (!$hasMore && $scanned === self::LIMIT) {
                // Check existence of next row beyond lastScannedId
                $exists = $this->db->fetchValue(
                    'SELECT id FROM ' . $this->db->table('cpms_follow_ups') . ' WHERE id > %d AND status = %s AND reminder_sent_at IS NULL AND suggested_date IS NOT NULL AND suggested_date >= %s AND suggested_date <= %s ORDER BY id ASC LIMIT 1',
                    [$lastScannedId, 'pending', $window['lower'], $window['upper']]
                );
                $shouldContinue = $exists !== null;
            }

            if ($shouldContinue) {
                $this->maybeEnqueueContinuation($lastScannedId, $referenceUtc);
            }
        }

        return $reminded;
    }

    private function rootReferenceUtc(): DateTimeImmutable
    {
        $now = $this->utcNow === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($this->utcNow)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('fu.reminder clock must return DateTimeImmutable');
        }
        $normalized = $now->setTimezone(new DateTimeZone('UTC'))->format(self::REFERENCE_UTC_FORMAT);
        $reference = $this->parseReferenceUtc($normalized);
        if ($reference === null) {
            throw new RuntimeException('fu.reminder could not normalize root UTC reference');
        }
        return $reference;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{reference_utc:DateTimeImmutable,cursor:array{id:int}}
     * @throws JobPayloadInvalidException
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
            $this->failPayload('Unexpected continuation payload structure', ['keys' => $keys, 'expected' => $expected]);
        }

        if (($payload['continuation'] ?? null) !== true) {
            $this->failPayload('Continuation marker must be true', ['continuation' => $payload['continuation'] ?? null]);
        }

        if (!is_int($payload['version'] ?? null) || ($payload['version'] ?? null) !== self::PAYLOAD_VERSION) {
            $this->failPayload('Unsupported continuation version', ['version' => $payload['version'] ?? null]);
        }

        if (!is_string($payload['reference_utc'] ?? null)) {
            $this->failPayload('Reference UTC must be a string', ['reference_utc' => $payload['reference_utc'] ?? null]);
        }

        $reference = $this->parseReferenceUtc($payload['reference_utc']);
        if ($reference === null) {
            $this->failPayload('Invalid reference UTC', ['reference_utc' => $payload['reference_utc']]);
        }

        $cursor = $this->parseCursor($payload['cursor'] ?? null);
        if ($cursor === null) {
            $this->failPayload('Invalid continuation cursor', ['cursor' => $payload['cursor'] ?? null]);
        }

        // No Clinic ID from payload - enforce
        if (isset($payload['clinic_id']) || isset($payload['clinicId'])) {
            $this->failPayload('Clinic ID must not be in payload', ['payload' => $payload]);
        }

        return ['reference_utc' => $reference, 'cursor' => $cursor];
    }

    /**
     * @return array{id:int}|null
     */
    private function parseCursor(mixed $cursor): ?array
    {
        if (!is_array($cursor)) {
            return null;
        }
        $keys = array_keys($cursor);
        sort($keys);
        $expected = ['id'];
        if ($keys !== $expected) {
            return null;
        }
        if (!isset($cursor['id']) || !is_int($cursor['id']) || $cursor['id'] <= 0) {
            return null;
        }
        return ['id' => $cursor['id']];
    }

    private function parseReferenceUtc(string $reference): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $reference) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
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

    /**
     * @param int $cursorId 0 for root
     * @param array{lower:string,upper:string} $window
     * @return list<array<string,mixed>>
     */
    private function fetchCandidatePage(int $cursorId, array $window, int $limit): array
    {
        // Keyset pagination: f.id > cursor_id, ORDER BY f.id ASC, no OFFSET
        $sql = 'SELECT f.id, f.clinic_id, f.patient_id, f.suggested_date, f.clinician_id, f.visit_id,
                       p.first_name, p.last_name, p.mobile,
                       v.id AS visit_id_joined, v.clinic_id AS visit_clinic_id, v.location_id AS visit_location_id,
                       l.id AS location_id, l.clinic_id AS location_clinic_id, l.timezone AS location_timezone
                FROM ' . $this->db->table('cpms_follow_ups') . ' f
                JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = f.patient_id
                LEFT JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = f.visit_id
                LEFT JOIN ' . $this->db->table('cpms_locations') . ' l ON l.id = v.location_id
                WHERE f.id > %d AND f.status = %s AND f.reminder_sent_at IS NULL
                  AND f.suggested_date IS NOT NULL
                  AND f.suggested_date >= %s AND f.suggested_date <= %s
                ORDER BY f.id ASC LIMIT %d';
        return $this->db->fetchAll($sql, [$cursorId, 'pending', $window['lower'], $window['upper'], $limit]);
    }

    /**
     * Conservative prefilter: for a fixed UTC instant, compute min and max
     * Location-local "tomorrow" across all valid IANA zones.
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
                $zone = new DateTimeZone($identifier);
                $localTomorrow = $referenceUtc->setTimezone($zone)->modify('+1 day')->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
            $min = $min === null || $localTomorrow < $min ? $localTomorrow : $min;
            $max = $max === null || $localTomorrow > $max ? $localTomorrow : $max;
        }
        if ($min === null || $max === null) {
            $fallback = $referenceUtc->modify('+1 day')->format('Y-m-d');
            $window = ['lower' => $fallback, 'upper' => $fallback];
            self::$candidateWindowCache[$key] = $window;
            return $window;
        }

        $window = ['lower' => $min, 'upper' => $max];
        self::$candidateWindowCache[$key] = $window;
        return $window;
    }

    private function locationTimezone(string $tz): ?DateTimeZone
    {
        $tz = trim($tz);
        if ($tz === '') {
            return null;
        }
        if (!isset($this->timezoneValid[$tz])) {
            $this->timezoneValid[$tz] = in_array($tz, timezone_identifiers_list(), true);
        }
        if ($this->timezoneValid[$tz] === false) {
            return null;
        }
        try {
            return new DateTimeZone($tz);
        } catch (Throwable) {
            $this->timezoneValid[$tz] = false;
            return null;
        }
    }

    private function notificationServiceForClinic(int $clinicId): NotificationService
    {
        if ($this->legacyNotifications !== null) {
            return $this->legacyNotifications;
        }
        if (isset($this->notificationCache[$clinicId])) {
            return $this->notificationCache[$clinicId];
        }
        if ($this->notificationForClinic === null) {
            throw new \RuntimeException('fu.reminder notification factory not configured');
        }
        $service = ($this->notificationForClinic)($clinicId);
        if (!$service instanceof NotificationService) {
            throw new \RuntimeException('fu.reminder notification factory must return NotificationService');
        }
        $this->notificationCache[$clinicId] = $service;
        return $service;
    }

    private function maybeEnqueueContinuation(int $lastScannedId, DateTimeImmutable $referenceUtc): void
    {
        if ($this->queue === null) {
            // No queue injected (legacy tests) - cannot enqueue, but progress is still tracked via cursor for next manual call if caller provides it
            // For production, queue is always present via App.php wiring
            return;
        }

        if ($lastScannedId <= 0) {
            return;
        }

        $payload = [
            'continuation' => true,
            'version' => self::PAYLOAD_VERSION,
            'reference_utc' => $referenceUtc->format(self::REFERENCE_UTC_FORMAT),
            'cursor' => ['id' => $lastScannedId],
        ];

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            // Use referenceUtc for run_at to keep chain reference stable, or now? We use now for immediacy, but payload holds reference_utc constant
            // Following appt.reminder pattern: enqueue with referenceUtc as runAt
            $this->queue->enqueue('fu.reminder', $payload, $referenceUtc, 4, 3);
            $this->op->info('fu.reminder_continuation_enqueued', [
                'cursor_id' => $lastScannedId,
                'reference_utc' => $payload['reference_utc'],
            ]);
        } catch (Throwable $e) {
            $this->op->error('fu.reminder_continuation_enqueue_failed', [
                'error' => $e->getMessage(),
                'cursor_id' => $lastScannedId,
            ]);
            // If enqueue fails, throw to fail current job so retry can attempt again (preserves forward progress guarantee)
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $row
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
            'appointment_date' => Jalali::formatYmd((string) $row['suggested_date']),
            'appointment_time' => '—',
            'clinic_name' => $clinic !== '' ? $clinic : 'مطب',
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return never
     */
    private function failPayload(string $message, array $data): never
    {
        $this->op->warning('fu.reminder_payload_invalid', $data);
        throw new JobPayloadInvalidException('JOB_PAYLOAD_INVALID', $message, $data);
    }
}

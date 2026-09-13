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
use ClinicCore\Settings\SettingsFactory;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Job: fu.reminder — یادآوری Follow-Up سررسید (background-jobs.md §2).
 *
 * Phase 2 M-2 temporal slice:
 *  - Scope-neutral construction (no ambient App::settings()/notificationService at build time)
 *  - Authoritative per-row Location calendar: follow_up.visit_id -> visits.location_id -> locations.timezone
 *  - Each row's suggested_date is compared to Location-local "tomorrow" derived from a single
 *    reference UTC instant for the whole tick.
 *  - Malformed/mismatched rows fail closed individually with warning, sweep continues.
 *  - No fallback to Clinic timezone, WordPress timezone, or PHP ambient timezone.
 *  - Per-Clinic NotificationService via factory for quiet-hours (OUT OF SCOPE: policy not redesigned)
 *  - SmsService remains scope-neutral, receives explicit durable clinic_id.
 *
 * Idempotency (J-2): reminder_sent_at column — row only selected when IS NULL and set after send.
 * When quiet-hours closed, internal notification goes, reminder_sent_at stays NULL → next tick retries SMS.
 */
final class FollowUpReminderHandler
{
    private const LIMIT = 100;

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
    /** @var \Closure(): DateTimeImmutable|null */
    private readonly ?\Closure $utcNow;

    /**
     * New scope-neutral constructor:
     *   (CpmsDb, SettingsFactory, SmsService, Closure(int):NotificationService, OpLogger, ?Closure)
     * Legacy constructor (retained for existing tests):
     *   (CpmsDb, Settings, SmsService, NotificationService, OpLogger)
     */
    public function __construct(
        CpmsDb $db,
        SettingsFactory|Settings $settingsFactoryOrLegacySettings,
        SmsService $sms,
        \Closure|NotificationService $notificationFactoryOrLegacyNotifications,
        OpLogger $op,
        ?\Closure $utcNow = null
    ) {
        $this->db = $db;
        $this->op = $op;
        $this->utcNow = $utcNow;

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

    public function __invoke(array $payload): int
    {
        $referenceUtc = $this->referenceUtc();

        $window = $this->candidateWindow($referenceUtc);

        // Fetch candidates with JOINs to avoid N+1, but LEFT to observe malformed rows for fail-closed
        $rows = $this->db->fetchAll(
            'SELECT f.id, f.clinic_id, f.patient_id, f.suggested_date, f.clinician_id, f.visit_id,
                    p.first_name, p.last_name, p.mobile,
                    v.id AS visit_id_joined, v.clinic_id AS visit_clinic_id, v.location_id AS visit_location_id,
                    l.id AS location_id, l.clinic_id AS location_clinic_id, l.timezone AS location_timezone
             FROM ' . $this->db->table('cpms_follow_ups') . ' f
             JOIN ' . $this->db->table('cpms_patients') . ' p ON p.id = f.patient_id
             LEFT JOIN ' . $this->db->table('cpms_visits') . ' v ON v.id = f.visit_id
             LEFT JOIN ' . $this->db->table('cpms_locations') . ' l ON l.id = v.location_id
             WHERE f.status = %s AND f.reminder_sent_at IS NULL
               AND f.suggested_date IS NOT NULL
               AND f.suggested_date >= %s AND f.suggested_date <= %s
             ORDER BY f.id ASC LIMIT %d',
            ['pending', $window['lower'], $window['upper'], self::LIMIT]
        );

        $reminded = 0;

        foreach ($rows as $row) {
            $followUpId = (int) ($row['id'] ?? 0);
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
            $smsOpen = $notifications->smsQuietHoursOpen();

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

        return $reminded;
    }

    private function referenceUtc(): DateTimeImmutable
    {
        if ($this->utcNow !== null) {
            $now = ($this->utcNow)();
            if (!$now instanceof DateTimeImmutable) {
                throw new \RuntimeException('fu.reminder clock must return DateTimeImmutable');
            }
            return $now->setTimezone(new DateTimeZone('UTC'));
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Conservative prefilter: for a fixed UTC instant, compute min and max
     * Location-local "tomorrow" across all valid IANA zones.
     *
     * @return array{lower:string,upper:string}
     */
    private function candidateWindow(DateTimeImmutable $referenceUtc): array
    {
        $key = $referenceUtc->format('Y-m-d\TH:i:s\Z');
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
            // Fallback to simple +1 day in UTC if timezone list fails
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
}

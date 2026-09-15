<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\ApptReminderHandler;
use ClinicCore\Application\Jobs\FollowUpReminderHandler;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * Phase 2 RED — reminder SMS quiet-hours must use the operational row Location.
 *
 * This is intentionally a fresh, controlled-clock integration probe.  It reaches
 * both production reminder handlers.  The current main defect is that both
 * handlers pass an explicit Location-aware row to a NotificationService whose
 * quiet-hours method still reads the Clinic timezone and the ambient current time.
 */
final class ReminderLocationQuietHoursRedTest extends WP_UnitTestCase
{
    private const TZ_A = 'Pacific/Kiritimati';
    private const TZ_B = 'Pacific/Niue';

    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianId = 0;
    private int $patientA = 0;
    private int $patientB = 0;
    private int $visitA = 0;
    private int $visitB = 0;
    private int $appointmentA = 0;
    private int $appointmentB = 0;
    private int $followUpA = 0;
    private int $followUpB = 0;
    private DateTimeImmutable $controlledUtc;
    private string $mobileA = '';
    private string $mobileB = '';

    /**
     * Reset all App/Settings singletons that could otherwise mask a fresh
     * per-Clinic fixture or retain a previous test's temporal context.
     */
    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach (
            [
                'db', 'op', 'audit', 'jobs', 'rate', 'loginRateLimiter', 'idem',
                'settingsFactory', 'migrations', 'dispatcher', 'providers', 'vault',
                'smsService', 'licenseGate', 'visitService', 'installationSettings',
            ] as $propName
        ) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        try {
            $factory = App::settingsFactory();
            $factory->reset();
            $factoryRef = new \ReflectionClass($factory);
            if ($factoryRef->hasProperty('instances')) {
                $instances = $factoryRef->getProperty('instances');
                $instances->setAccessible(true);
                $instances->setValue($factory, []);
            }
        } catch (\Throwable) {
            // The test assertions below still fail loudly if cache reset is not possible.
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
        $this->controlledUtc = new DateTimeImmutable('2026-09-15 10:50:36', new DateTimeZone('UTC'));
        $this->buildFixture();
        $this->purgeJobs();
        $this->resetAppCaches();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $this->purgeJobs();
        $this->purgeFixture();
        $this->resetAppCaches();
        parent::tearDown();
    }

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_organizations') .
            ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
            'Quiet Hours RED Organization',
            'quiet-hours-red-org-' . bin2hex(random_bytes(5)),
            'active',
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'fixture organization insert');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinics') .
            ' (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'Quiet Hours RED Clinic',
            'quiet-hours-red-clinic-' . bin2hex(random_bytes(5)),
            self::TZ_A,
            $now,
            $now
        ));
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicId, 'fixture clinic insert');

        $this->locationA = $this->insertLocation('Location A', self::TZ_A, true, $now);
        $this->locationB = $this->insertLocation('Location B', self::TZ_B, false, $now);

        $offsetDifference = abs(
            (new DateTimeZone(self::TZ_A))->getOffset($this->controlledUtc)
                - (new DateTimeZone(self::TZ_B))->getOffset($this->controlledUtc)
        );
        self::assertGreaterThanOrEqual(86400, $offsetDifference, 'Location fixture must have >=24h offset difference');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $db->table('cpms_clinicians') .
            ' (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicId,
            'Quiet Hours RED Clinician',
            $now,
            $now
        ));
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId, 'fixture clinician insert');

        $this->mobileA = '0912000' . random_int(1000, 4999);
        $this->mobileB = '0912000' . random_int(5000, 9999);
        $this->patientA = $this->insertPatient('Patient A', $this->mobileA, $now);
        $this->patientB = $this->insertPatient('Patient B', $this->mobileB, $now);

        $tomorrowA = $this->locationTomorrow(self::TZ_A);
        $tomorrowB = $this->locationTomorrow(self::TZ_B);
        $slotTime = '10:00:00';

        $slotA = $this->insertSlot($this->locationA, $tomorrowA, $slotTime, $now);
        $slotB = $this->insertSlot($this->locationB, $tomorrowB, $slotTime, $now);
        $this->appointmentA = $this->insertAppointment($this->locationA, $this->patientA, $slotA, $tomorrowA, $now);
        $this->appointmentB = $this->insertAppointment($this->locationB, $this->patientB, $slotB, $tomorrowB, $now);

        $this->visitA = $this->insertVisit($this->locationA, $this->patientA, self::TZ_A, $now);
        $this->visitB = $this->insertVisit($this->locationB, $this->patientB, self::TZ_B, $now);
        $this->followUpA = $this->insertFollowUp($this->visitA, $this->patientA, $tomorrowA, $now);
        $this->followUpB = $this->insertFollowUp($this->visitB, $this->patientB, $tomorrowB, $now);

        foreach (
            [
                $this->locationA, $this->locationB, $this->clinicianId,
                $this->patientA, $this->patientB, $this->appointmentA,
                $this->appointmentB, $this->visitA, $this->visitB,
                $this->followUpA, $this->followUpB,
            ] as $fixtureId
        ) {
            self::assertGreaterThan(0, $fixtureId, 'all operational fixture inserts must succeed');
        }

        // Configure a one-hour window around the controlled Location A hour.
        // A is open, while B is one local hour away at this same UTC instant.
        $localA = $this->controlledUtc->setTimezone(new DateTimeZone(self::TZ_A));
        $startHour = (int) $localA->format('G');
        $endHour = ($startHour + 1) % 24;
        $settings = new Settings($db, $this->clinicId, App::audit());
        $settings->set('notif.quiet_hours_start', sprintf('%02d:00', $startHour));
        $settings->set('notif.quiet_hours_end', sprintf('%02d:00', $endHour));
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();

        $expectedA = $this->expectedQuietHoursOpen(self::TZ_A, $startHour, $endHour);
        $expectedB = $this->expectedQuietHoursOpen(self::TZ_B, $startHour, $endHour);
        $clinicExpected = $this->expectedQuietHoursOpen(self::TZ_A, $startHour, $endHour);
        self::assertTrue($expectedA, $this->diagnostic('Location A must be open at the controlled instant'));
        self::assertFalse($expectedB, $this->diagnostic('Location B must be closed at the controlled instant'));
        self::assertTrue($clinicExpected, $this->diagnostic('Clinic timezone is intentionally pinned to Location A'));
    }

    public function testBothReachableReminderPathsUseTheirExplicitLocationForQuietHours(): void
    {
        global $wpdb;
        $db = App::db();
        $originalPhpTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $controlledUtc = $this->controlledUtc;
            $notificationFactory = static function (int $clinicId): NotificationService {
                $db = App::db();
                return new NotificationService(
                    $db,
                    new NotificationRepository($db),
                    new MembershipRepository($db),
                    App::settingsFactory()->forClinic($clinicId),
                    App::op()
                );
            };

            $apptHandler = new ApptReminderHandler(
                $db,
                App::smsService(),
                $notificationFactory,
                App::jobs(),
                App::op(),
                static fn (): DateTimeImmutable => $controlledUtc
            );
            $fuHandler = new FollowUpReminderHandler(
                $db,
                App::settingsFactory(),
                App::smsService(),
                $notificationFactory,
                App::op(),
                static fn (): DateTimeImmutable => $controlledUtc
            );

            $apptResult = $apptHandler([]);
            $fuResult = $fuHandler([]);

            self::assertSame(2, $apptResult, $this->diagnostic('Appointment reminder product path was not reached for both Location rows'));
            self::assertSame(2, $fuResult, $this->diagnostic('Follow-up reminder product path was not reached for both Location rows'));

            $apptSmsA = $this->smsCount($this->mobileA, SmsEvents::APPT_REMINDER);
            $apptSmsB = $this->smsCount($this->mobileB, SmsEvents::APPT_REMINDER);
            $fuSmsA = $this->smsCount($this->mobileA, SmsEvents::FOLLOW_UP);
            $fuSmsB = $this->smsCount($this->mobileB, SmsEvents::FOLLOW_UP);

            // This is the intended RED contract on current main: both handlers
            // evaluate NotificationService's Clinic timezone, so B is sent too.
            self::assertSame(1, $apptSmsA, $this->diagnostic('Location A appointment SMS must be sent'));
            self::assertSame(0, $apptSmsB, $this->diagnostic('RED: Location B appointment SMS must be suppressed by its Location timezone'));
            self::assertSame(1, $fuSmsA, $this->diagnostic('Location A follow-up SMS must be sent'));
            self::assertSame(0, $fuSmsB, $this->diagnostic('RED: Location B follow-up SMS must be suppressed by its Location timezone'));

            self::assertSame(
                1,
                $this->notificationCount($this->patientB, 'appt_reminder'),
                $this->diagnostic('Appointment reminder internal notification proves the A/B row reached the product path')
            );
            self::assertSame(
                1,
                $this->notificationCount($this->patientB, 'followup_reminder'),
                $this->diagnostic('Follow-up internal notification proves the A/B row reached the product path')
            );
        } finally {
            date_default_timezone_set($originalPhpTimezone);
            App::replaceExplicitScope(null);
        }
    }

    private function insertLocation(string $name, string $timezone, bool $primary, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . App::db()->table('cpms_locations') .
            ' (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)',
            $this->clinicId,
            $name,
            strtolower(str_replace(' ', '-', $name)) . '-' . bin2hex(random_bytes(3)),
            $timezone,
            $primary ? 1 : 0,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function insertPatient(string $name, string $mobile, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . App::db()->table('cpms_patients') .
            ' (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $this->clinicId,
            'MR-QUIET-' . bin2hex(random_bytes(4)),
            $name,
            'Reminder',
            $mobile,
            'active',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function insertSlot(int $locationId, string $date, string $time, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . App::db()->table('cpms_schedule_slots') .
            ' (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, 20, 1, 1, 0, 1, %s, %s)',
            $this->clinicId,
            $locationId,
            $this->clinicianId,
            $date,
            $time,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function insertAppointment(int $locationId, int $patientId, int $slotId, string $date, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . App::db()->table('cpms_appointments') .
            ' (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, duration_min, slot_end_time, status, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, %s, %s, %s)',
            $this->clinicId,
            $locationId,
            'APT-QUIET-' . bin2hex(random_bytes(5)),
            $this->clinicianId,
            $patientId,
            $slotId,
            $date,
            '10:00:00',
            '10:20:00',
            'confirmed',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function insertVisit(int $locationId, int $patientId, string $timezone, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . App::db()->table('cpms_visits') .
            ' (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, %s)',
            $this->clinicId,
            $locationId,
            $this->clinicianId,
            $patientId,
            'walk_in',
            'checked_in',
            $this->controlledUtc->setTimezone(new DateTimeZone($timezone))->format('Y-m-d'),
            $now,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function insertFollowUp(int $visitId, int $patientId, string $date, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . App::db()->table('cpms_follow_ups') .
            ' (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, %s, %s)',
            $this->clinicId,
            $visitId,
            $patientId,
            $this->clinicianId,
            $date,
            'Location quiet-hours RED',
            'pending',
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function locationTomorrow(string $timezone): string
    {
        return $this->controlledUtc->setTimezone(new DateTimeZone($timezone))->modify('+1 day')->format('Y-m-d');
    }

    private function expectedQuietHoursOpen(string $timezone, int $start, int $end): bool
    {
        $hour = (int) $this->controlledUtc->setTimezone(new DateTimeZone($timezone))->format('G');
        return $start <= $end
            ? $hour >= $start && $hour < $end
            : $hour >= $start || $hour < $end;
    }

    private function smsCount(string $mobile, string $event): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE clinic_id = %d AND recipient = %s AND event = %s',
            [$this->clinicId, $mobile, $event]
        );
    }

    private function notificationCount(int $patientId, string $template): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications') . ' WHERE clinic_id = %d AND recipient_patient_id = %d AND template = %s',
            [$this->clinicId, $patientId, $template]
        );
    }

    private function diagnostic(string $message): string
    {
        $a = $this->controlledUtc->setTimezone(new DateTimeZone(self::TZ_A));
        $b = $this->controlledUtc->setTimezone(new DateTimeZone(self::TZ_B));

        return $message . sprintf(
            ' [controlled_utc=%s clinic_tz=%s A=%s(%s) B=%s(%s) clinic_id=%d location_a=%d location_b=%d appointment_a=%d appointment_b=%d follow_up_a=%d follow_up_b=%d]',
            $this->controlledUtc->format('Y-m-d\TH:i:s\Z'),
            self::TZ_A,
            self::TZ_A,
            $a->format('Y-m-d H:i:s T'),
            self::TZ_B,
            $b->format('Y-m-d H:i:s T'),
            $this->clinicId,
            $this->locationA,
            $this->locationB,
            $this->appointmentA,
            $this->appointmentB,
            $this->followUpA,
            $this->followUpB
        );
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $wpdb->query(
            'DELETE FROM ' . App::db()->table('cpms_jobs') .
            ' WHERE type IN ("appt.reminder", "fu.reminder", "sms.send")'
        );
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->clinicId > 0) {
            foreach (
                [
                    'cpms_follow_ups', 'cpms_notifications', 'cpms_sms_messages',
                    'cpms_appointments', 'cpms_visits', 'cpms_schedule_slots',
                    'cpms_patients', 'cpms_clinicians', 'cpms_settings',
                ] as $table
            ) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table($table) . ' WHERE clinic_id = %d', $this->clinicId));
            }
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicId));
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}

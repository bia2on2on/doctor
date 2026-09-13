<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\NotificationRepository;
use ClinicCore\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

/**
 * M-2 fu.reminder — RED 2: Location-local calendar.
 *
 * Proves that follow-up selection must be per-row Location-local, not global Clinic/UTC.
 *
 * Topology:
 *  - one Clinic (62501) timezone = Pacific/Kiritimati (UTC+14)
 *  - Location A (62510) = Pacific/Kiritimati (UTC+14)
 *  - Location B (62511) = Pacific/Niue (UTC-11) — 25h apart, dates never equal
 *  - Clinic timezone pinned to Location A, so pre-fix handler (clinic timezone)
 *    will miss Location B's tomorrow.
 *
 * Expected RED (pre-fix): only 1 of 2 follow-ups reminded (clinic timezone)
 * Expected GREEN (post-fix): both reminded, each via its own Location calendar.
 */
final class FollowUpReminderLocationCalendarRedTest extends WP_UnitTestCase
{
    private const FX_ORG_ID = 62500;
    private const FX_CLINIC_ID = 62501;
    private const FX_LOC_A_ID = 62510; // Pacific/Kiritimati UTC+14
    private const FX_LOC_B_ID = 62511; // Pacific/Niue UTC-11
    private const FX_CLINICIAN_ID = 62520;
    private const FX_PATIENT_A_ID = 62530;
    private const FX_PATIENT_B_ID = 62531;
    private const FX_VISIT_A_ID = 62540;
    private const FX_VISIT_B_ID = 62541;
    private const FX_FLOOR = 62500;

    private const TZ_A = 'Pacific/Kiritimati';
    private const TZ_B = 'Pacific/Niue';

    private function resetAppCaches(): void
    {
        $refClass = new \ReflectionClass(App::class);
        foreach (['db','op','audit','jobs','rate','loginRateLimiter','idem','settingsFactory','migrations','dispatcher','providers','vault','smsService','licenseGate','visitService'] as $propName) {
            if ($refClass->hasProperty($propName)) {
                $prop = $refClass->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        if (method_exists(App::class, 'settingsFactory')) {
            try {
                App::settingsFactory()->reset();
            } catch (\Throwable $e) {
            }
        }
        try {
            $factory = App::settingsFactory();
            $ref = new \ReflectionClass($factory);
            if ($ref->hasProperty('instances')) {
                $p = $ref->getProperty('instances');
                $p->setAccessible(true);
                $p->setValue($factory, []);
            }
        } catch (\Throwable $e) {
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        $this->resetAppCaches();
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

        $leftover = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_FLOOR);
        self::assertSame(0, $leftover, 'precondition: no leftover fixture rows');

        // Org
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (id, name, slug, status, created_at, updated_at) VALUES (%d, %s, %s, \"active\", %s, %s)',
            self::FX_ORG_ID,
            'FU Cal Org',
            'fu-cal-org-' . bin2hex(random_bytes(2)),
            $now,
            $now
        ));

        // Clinic with TZ_A
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            self::FX_CLINIC_ID,
            self::FX_ORG_ID,
            'FU Cal Clinic',
            'fu-cal-clinic-' . bin2hex(random_bytes(3)),
            self::TZ_A,
            $now,
            $now
        ));

        // Locations
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            self::FX_LOC_A_ID,
            self::FX_CLINIC_ID,
            'FU Cal Loc A',
            'fu-cal-loc-a-' . bin2hex(random_bytes(2)),
            self::TZ_A,
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 0, 1, %s, %s)',
            self::FX_LOC_B_ID,
            self::FX_CLINIC_ID,
            'FU Cal Loc B',
            'fu-cal-loc-b-' . bin2hex(random_bytes(2)),
            self::TZ_B,
            $now,
            $now
        ));

        // Clinician
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (id, clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %d, %s, 1, %s, %s)',
            self::FX_CLINICIAN_ID,
            self::FX_CLINIC_ID,
            'Dr FU Cal',
            $now,
            $now
        ));

        // Patients
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (id, clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, \"active\", %s, %s)',
            self::FX_PATIENT_A_ID,
            self::FX_CLINIC_ID,
            'MR-FU-CAL-A-' . bin2hex(random_bytes(2)),
            'FUCal',
            'PatientA',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (id, clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, \"active\", %s, %s)',
            self::FX_PATIENT_B_ID,
            self::FX_CLINIC_ID,
            'MR-FU-CAL-B-' . bin2hex(random_bytes(2)),
            'FUCal',
            'PatientB',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));

        // Visits with explicit Locations
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (id, clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, \"walk_in\", \"checked_in\", %s, %s, %s, %s)',
            self::FX_VISIT_A_ID,
            self::FX_CLINIC_ID,
            self::FX_LOC_A_ID,
            self::FX_CLINICIAN_ID,
            self::FX_PATIENT_A_ID,
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (id, clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, \"walk_in\", \"checked_in\", %s, %s, %s, %s)',
            self::FX_VISIT_B_ID,
            self::FX_CLINIC_ID,
            self::FX_LOC_B_ID,
            self::FX_CLINICIAN_ID,
            self::FX_PATIENT_B_ID,
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));

        // Settings per clinic — quiet hours open deterministically
        Settings::flushCache();
        $settings = new Settings($db, self::FX_CLINIC_ID, App::audit());
        $settings->set('notif.quiet_hours_start', '00:00');
        $settings->set('notif.quiet_hours_end', '23:59');
        $settings->set('sms.quiet_hours.enabled', false);
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id >= ' . self::FX_FLOOR);
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        if (method_exists(App::class, 'settingsFactory')) {
            App::settingsFactory()->reset();
        }
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\"visits.no_show\",\"slots.generate\",\"holds.expire\",\"cleanup.otp\",\"cleanup.rate_limits\",\"cleanup.idem\",\"cleanup.oplog\",\"handwriting.gc\",\"notif.dispatch\",\"appt.reminder\",\"fu.reminder\",\"license.refresh\",\"backup.run\",\"report.export\",\"sms.send\")');
    }

    /**
     * Compute Location-local tomorrow for a given UTC instant and IANA zone.
     */
    private function locationLocalTomorrow(DateTimeImmutable $utc, string $tz): string
    {
        $zone = new DateTimeZone($tz);
        $local = $utc->setTimezone($zone);
        return $local->modify('+1 day')->format('Y-m-d');
    }

    /**
     * Build handler via smallest scope-neutral path.
     * Tries new constructor (SettingsFactory + notification factory) first,
     * falls back to legacy constructor with explicit scope for RED evidence.
     */
    private function buildHandler(): object
    {
        $db = App::db();
        $op = App::op();

        // Try new scope-neutral constructor if available (after GREEN)
        try {
            $ref = new \ReflectionClass(\ClinicCore\Application\Jobs\FollowUpReminderHandler::class);
            $ctor = $ref->getConstructor();
            if ($ctor !== null) {
                $params = $ctor->getParameters();
                // New signature: (CpmsDb, SettingsFactory, SmsService, Closure, OpLogger, ?Closure)
                if (count($params) >= 4) {
                    $firstType = $params[1]->getType();
                    if ($firstType instanceof \ReflectionNamedType && $firstType->getName() === \ClinicCore\Settings\SettingsFactory::class) {
                        // New wiring
                        return new \ClinicCore\Application\Jobs\FollowUpReminderHandler(
                            $db,
                            App::settingsFactory(),
                            App::smsService(),
                            static fn (int $clinicId): NotificationService => new NotificationService(
                                $db,
                                new NotificationRepository($db),
                                new MembershipRepository($db),
                                App::settingsFactory()->forClinic($clinicId),
                                $op
                            ),
                            $op
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to legacy
        }

        // Legacy wiring — set explicit scope to bypass CLINIC_SCOPE_REQUIRED
        App::replaceExplicitScope(ClinicScope::forClinic(self::FX_CLINIC_ID));
        $settings = App::settings();
        $sms = App::smsService();
        $notifications = new NotificationService(
            $db,
            new NotificationRepository($db),
            new MembershipRepository($db),
            $settings,
            $op
        );
        return new \ClinicCore\Application\Jobs\FollowUpReminderHandler(
            $db,
            $settings,
            $sms,
            $notifications,
            $op
        );
    }

    public function testLocationLocalCalendarSelectsPerRow(): void
    {
        global $wpdb;
        $db = App::db();
        $nowSql = $db->nowUtcSql();

        // Reference-time safety: capture UTC immediately before execution
        $beforeUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Compute per-Location tomorrow using explicit DateTimeImmutable/DateTimeZone
        $tomorrowA = $this->locationLocalTomorrow($beforeUtc, self::TZ_A);
        $tomorrowB = $this->locationLocalTomorrow($beforeUtc, self::TZ_B);

        // Determinism precondition: these two zones differ by 25h, so local dates differ always
        $todayA = $beforeUtc->setTimezone(new DateTimeZone(self::TZ_A))->format('Y-m-d');
        $todayB = $beforeUtc->setTimezone(new DateTimeZone(self::TZ_B))->format('Y-m-d');
        self::assertNotSame($todayA, $todayB, 'Location-local today must differ for deterministic RED (Kiritimati vs Niue)');
        // Tomorrow should also differ in most instants, but at least one of today/tomorrow differs
        // The key invariant: Clinic pinned to TZ_A, so clinic tomorrow = tomorrowA, not tomorrowB necessarily
        // For Kiritimati vs Niue, tomorrowA and tomorrowB differ by at least 1 day always when today differs
        // Let's assert they differ to ensure RED is observable
        // In edge case where date line crossing causes same tomorrow? With 25h offset, tomorrow also differs.
        // Verify with explicit calculation
        $tzA = new DateTimeZone(self::TZ_A);
        $tzB = new DateTimeZone(self::TZ_B);
        $offsetDiff = abs($tzA->getOffset($beforeUtc) - $tzB->getOffset($beforeUtc));
        self::assertGreaterThanOrEqual(86400, $offsetDiff, 'Offset diff must be >= 1 day for deterministic split');

        // Ensure fixture Locations have correct TZ
        $locATz = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', self::FX_LOC_A_ID));
        $locBTz = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', self::FX_LOC_B_ID));
        self::assertSame(self::TZ_A, $locATz, 'Location A TZ');
        self::assertSame(self::TZ_B, $locBTz, 'Location B TZ');

        // Insert follow-ups linked to exact Visits, suggested_date derived from each Visit Location's local calendar
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, \"pending\", %s)',
            self::FX_CLINIC_ID,
            self::FX_VISIT_A_ID,
            self::FX_PATIENT_A_ID,
            self::FX_CLINICIAN_ID,
            $tomorrowA,
            'FU A reason',
            $nowSql
        ));
        $fuAId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, \"pending\", %s)',
            self::FX_CLINIC_ID,
            self::FX_VISIT_B_ID,
            self::FX_PATIENT_B_ID,
            self::FX_CLINICIAN_ID,
            $tomorrowB,
            'FU B reason',
            $nowSql
        ));
        $fuBId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $fuAId, 'FU A inserted');
        self::assertGreaterThan(0, $fuBId, 'FU B inserted');

        // Quiet hours open already configured in fixture (00:00-23:59)
        // Also ensure ambient PHP timezone does NOT affect selection — set it to something else
        $originalPhpTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            // Exercise handler through smallest scope-neutral path that actually tests temporal selection logic
            $handler = $this->buildHandler();

            // Capture reference UTC again just before execution for boundary check
            $execBefore = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $result = $handler([]);
            $execAfter = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // Reference-time safety: ensure calendar boundaries did not change during test
            $tomorrowABefore = $this->locationLocalTomorrow($execBefore, self::TZ_A);
            $tomorrowAAfter = $this->locationLocalTomorrow($execAfter, self::TZ_A);
            $tomorrowBBefore = $this->locationLocalTomorrow($execBefore, self::TZ_B);
            $tomorrowBAfter = $this->locationLocalTomorrow($execAfter, self::TZ_B);

            self::assertSame($tomorrowA, $tomorrowABefore, 'Calendar boundary for Location A must not have changed during execution (before)');
            self::assertSame($tomorrowA, $tomorrowAAfter, 'Calendar boundary for Location A must not have changed during execution (after)');
            self::assertSame($tomorrowB, $tomorrowBBefore, 'Calendar boundary for Location B must not have changed during execution (before)');
            self::assertSame($tomorrowB, $tomorrowBAfter, 'Calendar boundary for Location B must not have changed during execution (after)');

            // The invariant: Each follow-up is selected according to its Visit's Location-local calendar date.
            // Both should be reminded, not just the one matching Clinic timezone.
            $remindedA = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $fuAId));
            $remindedB = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $fuBId));

            $notifCountA = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND recipient_patient_id = %d AND template = %s', self::FX_CLINIC_ID, self::FX_PATIENT_A_ID, 'followup_reminder'));
            $notifCountB = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND recipient_patient_id = %d AND template = %s', self::FX_CLINIC_ID, self::FX_PATIENT_B_ID, 'followup_reminder'));

            // Diagnostic message for RED evidence
            $diagnostic = sprintf(
                'Location-local calendar RED: clinic_tz=%s, locA_tz=%s local_today_A=%s tomorrow_A=%s, locB_tz=%s local_today_B=%s tomorrow_B=%s, beforeUtc=%s afterUtc=%s, handlerResult=%s, remindedA=%s remindedB=%s notifA=%d notifB=%d, originalPhpTz=%s currentPhpTz=%s',
                self::TZ_A,
                self::TZ_A,
                $todayA,
                $tomorrowA,
                self::TZ_B,
                $todayB,
                $tomorrowB,
                $beforeUtc->format('c'),
                $execAfter->format('c'),
                var_export($result, true),
                $remindedA ? 'set' : 'null',
                $remindedB ? 'set' : 'null',
                $notifCountA,
                $notifCountB,
                $originalPhpTz,
                date_default_timezone_get()
            );

            // Both must be reminded — per-row Location calendar
            self::assertNotNull($remindedA, 'FU A (Location A tomorrow) must be reminded per its Location-local calendar. ' . $diagnostic);
            self::assertNotNull($remindedB, 'FU B (Location B tomorrow) must be reminded per its Location-local calendar, not missed due to global Clinic/UTC date. ' . $diagnostic);

            // Also assert notifications created (SMS eligibility open)
            self::assertSame(1, $notifCountA, 'FU A notification must be exactly 1. ' . $diagnostic);
            self::assertSame(1, $notifCountB, 'FU B notification must be exactly 1. ' . $diagnostic);

            // Proof that PHP ambient timezone is not temporal source: we set ambient to America/New_York and still got both
            self::assertSame('America/New_York', date_default_timezone_get(), 'Ambient PHP timezone should be New_York during execution');
        } finally {
            date_default_timezone_set($originalPhpTz);
            App::replaceExplicitScope(null);
        }

        // Cleanup follow-ups for next assertions (mismatch test)
        $wpdb->query('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
        $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
    }

    public function testClinicVisitLocationMismatchFailsClosed(): void
    {
        global $wpdb;
        $db = App::db();
        $nowSql = $db->nowUtcSql();

        $beforeUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tomorrowA = $this->locationLocalTomorrow($beforeUtc, self::TZ_A);

        // Create a second clinic and location to simulate mismatch
        $otherClinicId = 62502;
        $otherLocId = 62512;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s)',
            $otherClinicId,
            self::FX_ORG_ID,
            'FU Mismatch Clinic',
            'fu-mismatch-clinic-' . bin2hex(random_bytes(3)),
            self::TZ_B,
            $nowSql,
            $nowSql
        ));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (id, clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, 1, 1, %s, %s)',
            $otherLocId,
            $otherClinicId,
            'FU Mismatch Loc',
            'fu-mismatch-loc-' . bin2hex(random_bytes(2)),
            self::TZ_B,
            $nowSql,
            $nowSql
        ));

        // Visit that belongs to other clinic but we will try to link follow-up claiming our clinic
        $mismatchVisitId = 62542;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (id, clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, \"walk_in\", \"checked_in\", %s, %s, %s, %s)',
            $mismatchVisitId,
            $otherClinicId,
            $otherLocId,
            self::FX_CLINICIAN_ID,
            self::FX_PATIENT_A_ID,
            gmdate('Y-m-d'),
            $nowSql,
            $nowSql,
            $nowSql
        ));

        // Valid follow-up (should succeed)
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, \"pending\", %s)',
            self::FX_CLINIC_ID,
            self::FX_VISIT_A_ID,
            self::FX_PATIENT_A_ID,
            self::FX_CLINICIAN_ID,
            $tomorrowA,
            'Valid FU',
            $nowSql
        ));
        $validFuId = (int) $wpdb->insert_id;

        // Mismatched follow-up: clinic_id = our clinic, but visit belongs to other clinic
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, \"pending\", %s)',
            self::FX_CLINIC_ID,
            $mismatchVisitId,
            self::FX_PATIENT_A_ID,
            self::FX_CLINICIAN_ID,
            $tomorrowA,
            'Mismatch FU',
            $nowSql
        ));
        $mismatchFuId = (int) $wpdb->insert_id;

        try {
            $handler = $this->buildHandler();
            $result = $handler([]);

            $validReminded = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $validFuId));
            $mismatchReminded = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $mismatchFuId));

            self::assertNotNull($validReminded, 'Valid FU must be reminded despite presence of mismatched row');
            self::assertNull($mismatchReminded, 'Mismatched FU (clinic_id != visit.clinic_id) must fail closed individually and not be reminded');
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
            $wpdb->query('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE id IN (' . $validFuId . ',' . $mismatchFuId . ')');
            $wpdb->query('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE id = ' . $mismatchVisitId);
            $wpdb->query('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE id = ' . $otherLocId);
            $wpdb->query('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = ' . $otherClinicId);
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
            $wpdb->query('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
            $wpdb->query('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id >= ' . self::FX_FLOOR);
            App::replaceExplicitScope(null);
        }
    }
}

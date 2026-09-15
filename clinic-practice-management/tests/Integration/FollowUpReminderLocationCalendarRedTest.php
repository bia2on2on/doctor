<?php
declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
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
 *  - one Clinic timezone = Pacific/Kiritimati (UTC+14)
 *  - Location A = Pacific/Kiritimati (UTC+14)
 *  - Location B = Pacific/Niue (UTC-11) — 25h apart, dates never equal
 *  - Clinic timezone pinned to Location A, so pre-fix handler (clinic timezone)
 *    will miss Location B's tomorrow.
 *
 * Expected RED (pre-fix): only 1 of 2 follow-ups reminded (clinic timezone)
 * Expected GREEN (post-fix): both reminded, each via its own Location calendar.
 */
final class FollowUpReminderLocationCalendarRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locA = 0;
    private int $locB = 0;
    private int $clinicianId = 0;
    private int $patientA = 0;
    private int $patientB = 0;
    private int $visitA = 0;
    private int $visitB = 0;

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

        $orgSlug = 'fu-cal-org-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'FU Cal Org',
            $orgSlug,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId);

        $clinicSlug = 'fu-cal-clinic-' . bin2hex(random_bytes(4));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'FU Cal Clinic',
            $clinicSlug,
            self::TZ_A,
            $now,
            $now
        ));
        $this->clinicId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicId);

        $locASlug = 'fu-cal-loc-a-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $this->clinicId,
            'FU Cal Loc A',
            $locASlug,
            self::TZ_A,
            $now,
            $now
        ));
        $this->locA = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locA);

        $locBSlug = 'fu-cal-loc-b-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 0, 1, %s, %s)',
            $this->clinicId,
            'FU Cal Loc B',
            $locBSlug,
            self::TZ_B,
            $now,
            $now
        ));
        $this->locB = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->locB);

        $tzA = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', $this->locA));
        $tzB = $wpdb->get_var($wpdb->prepare('SELECT timezone FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', $this->locB));
        self::assertSame(self::TZ_A, $tzA, 'loc A TZ');
        self::assertSame(self::TZ_B, $tzB, 'loc B TZ');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)',
            $this->clinicId,
            'Dr FU Cal',
            $now,
            $now
        ));
        $this->clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->clinicianId);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $this->clinicId,
            'MR-FU-CAL-A-' . bin2hex(random_bytes(3)),
            'FUCal',
            'PatientA',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $this->patientA = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $this->clinicId,
            'MR-FU-CAL-B-' . bin2hex(random_bytes(3)),
            'FUCal',
            'PatientB',
            '0912000' . random_int(1000, 9999),
            $now,
            $now
        ));
        $this->patientB = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, "walk_in", "checked_in", %s, %s, %s, %s)',
            $this->clinicId,
            $this->locA,
            $this->clinicianId,
            $this->patientA,
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $this->visitA = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, "walk_in", "checked_in", %s, %s, %s, %s)',
            $this->clinicId,
            $this->locB,
            $this->clinicianId,
            $this->patientB,
            gmdate('Y-m-d'),
            $now,
            $now,
            $now
        ));
        $this->visitB = (int) $wpdb->insert_id;

        Settings::flushCache();
        $settings = new Settings($db, $this->clinicId, App::audit());
        $settings->set('notif.quiet_hours_start', '00:00');
        // '24:00' causes parseHour() to return null, triggering fail-open 24/7 window
        // (same pattern as FollowUpReminderStarvationRedTest), preventing the 1-hour dead zone
        // at hour 23 (09:00-10:00 UTC) caused by integer hour truncation with '23:59'.
        $settings->set('notif.quiet_hours_end', '24:00');
        Settings::flushCache();
        App::resetScope();
        SystemClinicResolver::flush();
        App::settingsFactory()->reset();
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visit_status_history') . ' WHERE visit_id IN (SELECT id FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d)', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinicians') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_settings') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId));
        }
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
        $wpdb->query('DELETE FROM ' . $db->table('cpms_jobs') . ' WHERE type IN (\'visits.no_show\',\'slots.generate\',\'holds.expire\',\'cleanup.otp\',\'cleanup.rate_limits\',\'cleanup.idem\',\'cleanup.oplog\',\'handwriting.gc\',\'notif.dispatch\',\'appt.reminder\',\'fu.reminder\',\'license.refresh\',\'backup.run\',\'report.export\',\'sms.send\')');
    }

    private function locationLocalTomorrow(DateTimeImmutable $utc, string $tz): string
    {
        $zone = new DateTimeZone($tz);
        $local = $utc->setTimezone($zone);
        return $local->modify('+1 day')->format('Y-m-d');
    }

    /**
     * آیا نوعِ reflection این پارامتر، کلاس خواسته‌شده را می‌پذیرد؟
     *
     * سازندهٔ واقعی FollowUpReminderHandler پارامتر #۲ را به‌صورت نوع ترکیبی
     * (SettingsFactory|Settings) اعلان می‌کند؛ reflection برای نوع ترکیبی
     * «ReflectionUnionType» برمی‌گرداند و نه «ReflectionNamedType». شرط قبلی
     * فقط ReflectionNamedType را می‌پذیرفت، پس هرگز برقرار نمی‌شد (اثبات اجرایی
     * در run 34832417233: ReflectionUnionType{...SettingsFactory|...Settings}).
     */
    private function reflectionTypeAccepts(?\ReflectionType $type, string $className): bool
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName() === $className;
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && $member->getName() === $className) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * NEGATIVE CONTROL — helper هرگز نباید بی‌صدا از کنار «تزریق‌نشدن ساعت
     * کنترل‌شده» عبور کند. اگر utcNow تزریق نشده باشد یا دقیقاً همان لحظهٔ
     * کنترل‌شده را برنگرداند، همین‌جا شکست صریح رخ می‌دهد (سبز کاذب ممکن نیست).
     */
    private function assertControlledClockInjected(object $handler, DateTimeImmutable $referenceUtc): void
    {
        $prop = (new \ReflectionClass($handler))->getProperty('utcNow');
        $prop->setAccessible(true);
        $clock = $prop->getValue($handler);

        self::assertNotNull(
            $clock,
            'NEGATIVE CONTROL: FollowUpReminderHandler was constructed without an injected utcNow closure — '
                . 'rootReferenceUtc() would fall back to uncontrolled wall-clock time.'
        );
        self::assertInstanceOf(\Closure::class, $clock, 'NEGATIVE CONTROL: injected utcNow must be a Closure');

        $value = $clock();
        self::assertInstanceOf(
            DateTimeImmutable::class,
            $value,
            'NEGATIVE CONTROL: injected utcNow must return DateTimeImmutable'
        );
        self::assertSame(
            $referenceUtc->format('Y-m-d\TH:i:s\Z'),
            $value->format('Y-m-d\TH:i:s\Z'),
            'NEGATIVE CONTROL: injected utcNow must return exactly the controlled reference instant'
        );
    }

    private function buildHandler(DateTimeImmutable $referenceUtc): object
    {
        $db = App::db();
        $op = App::op();

        $handler = null;
        $probeError = null;
        $probeReason = null;

        try {
            $ref = new \ReflectionClass(\ClinicCore\Application\Jobs\FollowUpReminderHandler::class);
            $ctor = $ref->getConstructor();
            if ($ctor === null) {
                $probeReason = 'constructor is missing';
            } else {
                $params = $ctor->getParameters();
                if (count($params) < 4) {
                    $probeReason = 'constructor exposes only ' . count($params) . ' parameter(s)';
                } else {
                    $firstType = $params[1]->getType();
                    if ($this->reflectionTypeAccepts($firstType, \ClinicCore\Settings\SettingsFactory::class)) {
                        $handler = new \ClinicCore\Application\Jobs\FollowUpReminderHandler(
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
                            $op,
                            static fn (): DateTimeImmutable => $referenceUtc
                        );
                    } else {
                        $probeReason = 'constructor parameter #2 reflection = ' . $this->describeReflectionType($firstType);
                    }
                }
            }
        } catch (\Throwable $e) {
            // بلعیدن بی‌صدا ممنوع: هر خطای غیرمنتظرهٔ reflection/setup باید
            // صریح گزارش شود، نه اینکه خاموش به مسیر بدون ساعت کنترل‌شده تنزل کند.
            $probeError = $e;
        }

        if ($probeError !== null) {
            self::fail(
                'buildHandler(): scope-neutral FollowUpReminderHandler construction failed unexpectedly — '
                    . get_class($probeError) . ': ' . $probeError->getMessage()
            );
        }

        if ($handler === null) {
            self::fail(
                'buildHandler(): FollowUpReminderHandler does not accept a scope-neutral SettingsFactory ('
                    . $probeReason
                    . ') — refusing to silently degrade to a handler whose reference time is uncontrolled.'
            );
        }

        // NEGATIVE CONTROL — عمداً بیرون از try/catch است تا AssertionFailedError
        // بلعیده نشود و شکست با پیام دقیق خودش گزارش شود.
        $this->assertControlledClockInjected($handler, $referenceUtc);

        return $handler;
    }

    // ------------------------------------------------------------------
    // TEST-ONLY DIAGNOSTIC (evidence branch) — هیچ assertion محصولی را
    // تغییر نمی‌دهد و هیچ کد محصولی را لمس نمی‌کند.
    //
    // هدف: اثبات «اجرایی» اینکه آیا buildHandler() واقعاً سازندهٔ
    // scope-neutral را می‌سازد و زمان مرجع کنترل‌شده را تزریق می‌کند،
    // یا اینکه بی‌صدا به سازندهٔ legacy (بدون ساعت کنترل‌شده) تنزل می‌کند.
    //
    // هر دو تست زیر باید پیش از اصلاح helper قرمز (RED) باشند.
    // پیام‌های DIAG-SETUP خطای fixture/setup را از RED اصلی جدا می‌کنند تا
    // شکست bootstrap/fixture هرگز به‌عنوان RED محصولی شمرده نشود.
    // ------------------------------------------------------------------

    private function describeReflectionType(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'null';
        }
        if ($type instanceof \ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                $names[] = $member instanceof \ReflectionNamedType ? $member->getName() : get_class($member);
            }
            return get_class($type) . '{' . implode('|', $names) . '}';
        }
        if ($type instanceof \ReflectionNamedType) {
            return get_class($type) . '{' . $type->getName() . '}';
        }
        return get_class($type);
    }

    /**
     * DIAG 1 — پارامتر #۲ سازنده باید SettingsFactory را بپذیرد.
     *
     * پیش از اصلاح helper این تست قرمز بود (run 34832417233): reflection نوع
     * ترکیبی ReflectionUnionType{...SettingsFactory|...Settings} برمی‌گرداند و
     * شرط «instanceof ReflectionNamedType» هرگز برقرار نمی‌شد.
     */
    public function testDiagnosticProbeMatchesSettingsFactoryConstructorParam(): void
    {
        $ctor = (new \ReflectionClass(\ClinicCore\Application\Jobs\FollowUpReminderHandler::class))->getConstructor();
        self::assertNotNull($ctor, 'DIAG-SETUP: FollowUpReminderHandler constructor must exist');

        $params = $ctor->getParameters();
        self::assertGreaterThanOrEqual(4, count($params), 'DIAG-SETUP: constructor must expose at least 4 parameters');

        $type = $params[1]->getType();
        self::assertNotNull($type, 'DIAG-SETUP: constructor parameter #2 must be typed');

        self::assertTrue(
            $this->reflectionTypeAccepts($type, \ClinicCore\Settings\SettingsFactory::class),
            'DIAG: constructor parameter #2 must accept ' . \ClinicCore\Settings\SettingsFactory::class
                . ' so the scope-neutral handler can be constructed; reflection reports '
                . $this->describeReflectionType($type)
        );
    }

    /**
     * DIAG 2 — آیا زمان مرجع کنترل‌شده واقعاً به handler تزریق می‌شود؟
     */
    public function testDiagnosticBuildHandlerInjectsControlledReferenceTime(): void
    {
        self::assertGreaterThan(0, $this->clinicId, 'DIAG-SETUP: fixture clinic must exist');
        self::assertGreaterThan(0, $this->locA, 'DIAG-SETUP: fixture location A must exist');
        self::assertGreaterThan(0, $this->locB, 'DIAG-SETUP: fixture location B must exist');

        $controlled = new DateTimeImmutable('2026-01-02T03:04:05+00:00', new DateTimeZone('UTC'));
        $handler = $this->buildHandler($controlled);

        self::assertInstanceOf(
            \ClinicCore\Application\Jobs\FollowUpReminderHandler::class,
            $handler,
            'DIAG-SETUP: helper must return a FollowUpReminderHandler'
        );

        $prop = (new \ReflectionClass($handler))->getProperty('utcNow');
        $prop->setAccessible(true);
        $clock = $prop->getValue($handler);

        self::assertNotNull(
            $clock,
            'DIAG: controlled reference time was NOT injected — handler->utcNow is null, so '
                . 'FollowUpReminderHandler::rootReferenceUtc() falls back to uncontrolled current wall-clock time, '
                . 'while the fixture suggested_date values were derived from the test-controlled instant.'
        );

        self::assertInstanceOf(\Closure::class, $clock, 'DIAG: injected clock must be a Closure');
        $value = $clock();
        self::assertInstanceOf(DateTimeImmutable::class, $value, 'DIAG: injected clock must return DateTimeImmutable');
        self::assertSame(
            $controlled->format('Y-m-d\TH:i:s\Z'),
            $value->format('Y-m-d\TH:i:s\Z'),
            'DIAG: injected clock must return exactly the controlled reference instant'
        );
    }

    public function testLocationLocalCalendarSelectsPerRow(): void
    {
        global $wpdb;
        $db = App::db();
        $nowSql = $db->nowUtcSql();

        $beforeUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $tomorrowA = $this->locationLocalTomorrow($beforeUtc, self::TZ_A);
        $tomorrowB = $this->locationLocalTomorrow($beforeUtc, self::TZ_B);

        $todayA = $beforeUtc->setTimezone(new DateTimeZone(self::TZ_A))->format('Y-m-d');
        $todayB = $beforeUtc->setTimezone(new DateTimeZone(self::TZ_B))->format('Y-m-d');
        self::assertNotSame($todayA, $todayB, 'Location-local today must differ for deterministic RED (Kiritimati vs Niue)');

        $tzA = new DateTimeZone(self::TZ_A);
        $tzB = new DateTimeZone(self::TZ_B);
        $offsetDiff = abs($tzA->getOffset($beforeUtc) - $tzB->getOffset($beforeUtc));
        self::assertGreaterThanOrEqual(86400, $offsetDiff, 'Offset diff must be >= 1 day for deterministic split');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, "pending", %s)',
            $this->clinicId,
            $this->visitA,
            $this->patientA,
            $this->clinicianId,
            $tomorrowA,
            'FU A reason',
            $nowSql
        ));
        $fuAId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, "pending", %s)',
            $this->clinicId,
            $this->visitB,
            $this->patientB,
            $this->clinicianId,
            $tomorrowB,
            'FU B reason',
            $nowSql
        ));
        $fuBId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $fuAId, 'FU A inserted');
        self::assertGreaterThan(0, $fuBId, 'FU B inserted');

        $originalPhpTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $execBefore = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $handler = $this->buildHandler($execBefore);
            $result = $handler([]);
            $execAfter = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $tomorrowABefore = $this->locationLocalTomorrow($execBefore, self::TZ_A);
            $tomorrowAAfter = $this->locationLocalTomorrow($execAfter, self::TZ_A);
            $tomorrowBBefore = $this->locationLocalTomorrow($execBefore, self::TZ_B);
            $tomorrowBAfter = $this->locationLocalTomorrow($execAfter, self::TZ_B);

            self::assertSame($tomorrowA, $tomorrowABefore, 'Calendar boundary for Location A must not have changed during execution (before)');
            self::assertSame($tomorrowA, $tomorrowAAfter, 'Calendar boundary for Location A must not have changed during execution (after)');
            self::assertSame($tomorrowB, $tomorrowBBefore, 'Calendar boundary for Location B must not have changed during execution (before)');
            self::assertSame($tomorrowB, $tomorrowBAfter, 'Calendar boundary for Location B must not have changed during execution (after)');

            $remindedA = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $fuAId));
            $remindedB = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $fuBId));

            $notifCountA = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND recipient_patient_id = %d AND template = %s', $this->clinicId, $this->patientA, 'followup_reminder'));
            $notifCountB = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d AND recipient_patient_id = %d AND template = %s', $this->clinicId, $this->patientB, 'followup_reminder'));

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

            self::assertNotNull($remindedA, 'FU A (Location A tomorrow) must be reminded per its Location-local calendar. ' . $diagnostic);
            self::assertNotNull($remindedB, 'FU B (Location B tomorrow) must be reminded per its Location-local calendar, not missed due to global Clinic/UTC date. ' . $diagnostic);
            self::assertSame(1, $notifCountA, 'FU A notification must be exactly 1. ' . $diagnostic);
            self::assertSame(1, $notifCountB, 'FU B notification must be exactly 1. ' . $diagnostic);
            self::assertSame('America/New_York', date_default_timezone_get(), 'Ambient PHP timezone should be New_York during execution');
        } finally {
            date_default_timezone_set($originalPhpTz);
            App::replaceExplicitScope(null);
        }

        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d', $this->clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicId));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $this->clinicId));
    }

    public function testClinicVisitLocationMismatchFailsClosed(): void
    {
        global $wpdb;
        $db = App::db();
        $nowSql = $db->nowUtcSql();

        $beforeUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tomorrowA = $this->locationLocalTomorrow($beforeUtc, self::TZ_A);

        $otherClinicId = 0;
        $otherLocId = 0;
        $mismatchVisitId = 0;

        $otherClinicSlug = 'fu-mismatch-clinic-' . bin2hex(random_bytes(3));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $this->orgId,
            'FU Mismatch Clinic',
            $otherClinicSlug,
            self::TZ_B,
            $nowSql,
            $nowSql
        ));
        $otherClinicId = (int) $wpdb->insert_id;

        $otherLocSlug = 'fu-mismatch-loc-' . bin2hex(random_bytes(2));
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $otherClinicId,
            'FU Mismatch Loc',
            $otherLocSlug,
            self::TZ_B,
            $nowSql,
            $nowSql
        ));
        $otherLocId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, "walk_in", "checked_in", %s, %s, %s, %s)',
            $otherClinicId,
            $otherLocId,
            $this->clinicianId,
            $this->patientA,
            gmdate('Y-m-d'),
            $nowSql,
            $nowSql,
            $nowSql
        ));
        $mismatchVisitId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, "pending", %s)',
            $this->clinicId,
            $this->visitA,
            $this->patientA,
            $this->clinicianId,
            $tomorrowA,
            'Valid FU',
            $nowSql
        ));
        $validFuId = (int) $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_follow_ups (clinic_id, visit_id, patient_id, clinician_id, is_needed, suggested_date, interval_days, reason, status, created_at) VALUES (%d, %d, %d, %d, 1, %s, 30, %s, "pending", %s)',
            $this->clinicId,
            $mismatchVisitId,
            $this->patientA,
            $this->clinicianId,
            $tomorrowA,
            'Mismatch FU',
            $nowSql
        ));
        $mismatchFuId = (int) $wpdb->insert_id;

        try {
            $handler = $this->buildHandler($beforeUtc);
            $handler([]);

            $validReminded = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $validFuId));
            $mismatchReminded = $wpdb->get_var($wpdb->prepare('SELECT reminder_sent_at FROM ' . $db->table('cpms_follow_ups') . ' WHERE id = %d', $mismatchFuId));

            self::assertNotNull($validReminded, 'Valid FU must be reminded despite presence of mismatched row');
            self::assertNull($mismatchReminded, 'Mismatched FU (clinic_id != visit.clinic_id) must fail closed individually and not be reminded');
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_follow_ups') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_visits') . ' WHERE id = %d', $mismatchVisitId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', $otherLocId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $otherClinicId));
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_notifications') . ' WHERE clinic_id = %d', $this->clinicId));
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_sms_messages') . ' WHERE clinic_id = %d', $this->clinicId));
            App::replaceExplicitScope(null);
        }
    }
}

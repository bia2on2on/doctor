<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Notifications\SmsService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Booking\BookingWindow;
use ClinicCore\Domain\Licensing\LicenseDecision;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Infrastructure\Security\Idempotency;
use ClinicCore\Infrastructure\Sms\CredentialVault;
use ClinicCore\Infrastructure\Sms\SmsProviderRegistry;
use ClinicCore\Settings\Settings;
use ClinicCore\Settings\SettingsFactory;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Phase 6 — Scheduling Slice 2 (RED, TEST-FIRST):
 * «Location-local calendar/time-boundary correctness».
 *
 * مدل زمانی تثبیت‌شده (ADR-0013 + Phase 2 C-9 + Two-Clock در BookingWindow):
 *   - `slot_date`/`slot_time` پایسته = wall-clock محلیِ Locationِ همان اسلات
 *     (SlotsGenerateHandler آن‌ها را در قاب تقویم محلی Location می‌نویسد).
 *   - هر تصمیم «گذشته/آینده» باید از روی لحظهٔ مرجع UTC از طریق timezoneِ
 *     معتبر IANAِ همان Location گرفته شود — هرگز UTC/Clinic/WP/PHP به‌عنوان
 *     fallback عملیاتی.
 *
 * نقص‌های هدف (طبقه‌بندی B = نقص محصول ازپیش‌موجود در main):
 *   D-1  BookingService::availability() — Clamp و past-filter با
 *        gmdate('Y-m-d')/gmdate('H:i:s') (قاب UTC) روی مقادیر محلی Location.
 *   D-2  پنجرهٔ پیش‌فرض REST availability (from/to) مبتنی‌بر gmdate است.
 *   D-3  ScheduleService::impact()/regenerate() — مرز «آینده» gmdate('Y-m-d').
 *   D-4  پیش‌چک legacy-UTC در hold()/quote() می‌تواند اسلاتِ معتبرِ محلیِ
 *        غرب UTC را پیش از بررسی دقیق Two-Clock رد کند (ناسازگاری با نمایش).
 *
 * دترمینیستی‌بودن بدون Clock seam: جفت Locationهای UTC+14 (Pacific/Kiritimati)
 * و UTC-11 (Pacific/Niue) ۲۵ ساعت فاصله دارند، پس تقویم محلی آن‌ها در هیچ
 * لحظه‌ای برابر نیست؛ در هر رژیم ساعت UTC دست‌کم یک پایهٔ (leg) از اثبات‌ها
 * با رفتار فعلی ناسازگار است. مقادیر مورد انتظار در زمان اجرا از DateTimeZone
 * مشتق می‌شوند (بدون offset هاردکد). گارد «عبور مرز میان‌اجرا» مطابق قرارداد
 * تست‌های زمانی موجود مخزن markTestSkipped می‌کند نه fail.
 *
 * همهٔ شناسه‌ها داینامیک (insert_id) — بدون ID رزرو و بدون وابستگی به Clinic 1.
 *
 * طبقه‌بندی شکست‌ها:
 *   A = رگرسیون توسط کار جاری · B = نقص محصول ازپیش‌موجود (هدف)
 *   C = زیرساخت/محیط          · D = نقص تست/fixture
 */
final class SchedulingLocationLocalBoundaryRedTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';

    private const TZ_EAST = 'Asia/Tehran';          // شرق UTC (+3:30، بدون DST از ۲۰۲۲)
    private const TZ_EAST_EDGE = 'Pacific/Kiritimati'; // شرق UTC (+14، بدون DST)
    private const TZ_WEST = 'Pacific/Niue';          // غرب UTC (-11، بدون DST)
    private const TZ_WEST_HOLD = 'America/New_York'; // غرب UTC (DST آگاه؛ سپتامبر = EDT ثابت)
    private const TZ_INVALID = 'Invalid/NotAZone';
    private const CLINIC_TZ = 'UTC';                 // عمداً با همهٔ Locationها متفاوت

    private const RACE_CONNECTION_TIMEOUT_SECONDS = 5;
    private const RACE_LOCATION_WAIT_TIMEOUT_SECONDS = 10;
    private const RACE_WRITER_COMMAND_TIMEOUT_SECONDS = 30;
    private const RACE_GRACEFUL_REAP_TIMEOUT_SECONDS = 5;
    private const RACE_TERMINATION_REAP_TIMEOUT_SECONDS = 2;

    private int $orgId = 0;
    private int $clinicId = 0;
    private int $locTehran = 0;
    private int $locKiritimati = 0;
    private int $locNiue = 0;
    private int $locNewYork = 0;
    private int $locInvalid = 0;
    private int $clinicianEast = 0;
    private int $clinicianWest = 0;
    private int $clinicianMulti = 0;
    private int $clinicianHold = 0;
    private int $clinicianRegen = 0;
    private int $clinicianImpact = 0;
    private int $clinicianInvalid = 0;

    private ?string $originalPhpTz = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPhpTz = date_default_timezone_get();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        $this->assertTimezonesAvailable();
        // warmRoutes/prewarm پیش از buildFixture: rest_api_init در حالت تک‌Clinic
        // (تنها Clinic سیستم) همهٔ controllerها شامل BookingService را یک‌بار
        // می‌سازد و singletonهایش کش می‌مانند؛ پس از ساخت fixture (دو Clinic)
        // صدا زدن /health بدون Scope صریح CLINIC_SCOPE_REQUIRED می‌دهد
        // (SystemClinicResolver fail-closed — رفتار صحیح محصول، نه قرارداد تست).
        $this->warmRoutes();
        $this->buildFixture();
        $this->purgeJobs();
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpTz !== null) {
            date_default_timezone_set($this->originalPhpTz);
        }
        wp_set_current_user(0);
        $this->purgeJobs();
        $this->purgeFixture();
        ScopeContext::clear();
        App::resetScope();
        Settings::flushCache();
        parent::tearDown();
    }

    // =================================================================
    // Preconditions — محیط/fixture (نه قرارداد محصول)
    // =================================================================

    private function assertTimezonesAvailable(): void
    {
        $all = timezone_identifiers_list();
        foreach ([self::TZ_EAST, self::TZ_EAST_EDGE, self::TZ_WEST, self::TZ_WEST_HOLD] as $tz) {
            self::assertContains($tz, $all, "ENVIRONMENT precondition: IANA zone {$tz} باید موجود باشد");
        }
        self::assertNotContains(self::TZ_INVALID, $all, 'ENVIRONMENT precondition: Invalid/NotAZone واقعاً معتبر نیست');
        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $east = (new DateTimeZone(self::TZ_EAST_EDGE))->getOffset($utc);
        $west = (new DateTimeZone(self::TZ_WEST))->getOffset($utc);
        self::assertGreaterThan(
            23 * 3600,
            $east - $west,
            'ENVIRONMENT precondition: دو Location شرقی/غربی باید >23h فاصله داشته باشند تا تقویمشان هیچ‌وقت برابر نشود'
        );
    }

    public function testFixtureTopologyPreconditions(): void
    {
        // Ownership واقعی: هر Location متعلق به همان Clinic و هر طبیب به Clinic خودش.
        foreach ([$this->locTehran, $this->locKiritimati, $this->locNiue, $this->locNewYork, $this->locInvalid] as $loc) {
            $owner = (int) App::db()->fetchValue(
                'SELECT clinic_id FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d LIMIT 1',
                [$loc]
            );
            self::assertSame($this->clinicId, $owner, 'fixture: Location باید متعلق به Clinic خودش باشد');
        }
        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $probe = $utc->setTime(0, 30, 0);
        for ($day = 0; $day < 2; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $instant = $probe->modify('+' . ($day * 24 + $hour) . ' hours');
                self::assertNotSame(
                    $instant->setTimezone(new DateTimeZone(self::TZ_EAST_EDGE))->format('Y-m-d'),
                    $instant->setTimezone(new DateTimeZone(self::TZ_WEST))->format('Y-m-d'),
                    'fixture: تقویم محلی دو یال (Kiritimati/Niue) در هیچ ساعتی برابر نیست — تحت‌پوشش‌بودن همهٔ رژیم‌ها'
                );
            }
        }
        self::assertNotSame(
            self::CLINIC_TZ,
            $this->locationTimezone($this->locTehran),
            'fixture: Clinic tz عمداً با Location tz متفاوت است (هر fallback به Clinic قابل‌مشاهده می‌شود)'
        );
    }

    // =================================================================
    // RED/T1 — CASE A (شرق UTC): اسلات گذشتهٔ محلی نباید در دسترس باشد
    // =================================================================

    public function testPastLocationLocalSlotIsNotAvailableEastOfUtc(): void
    {
        $svc = $this->newBookingService();

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tehranPast = $before->setTimezone(new DateTimeZone(self::TZ_EAST))->modify('-90 minutes');
        $slotDate = $tehranPast->format('Y-m-d');
        $slotTime = $tehranPast->format('H:i:s');

        $pastId = $this->insertSlot($this->clinicianEast, $this->locTehran, $slotDate, $slotTime);
        // کنترل مثبت: اسلات آیندهٔ محلیِ همان Location باید بماند.
        $tehranFuture = $before->setTimezone(new DateTimeZone(self::TZ_EAST))->modify('+3 hours');
        $futureId = $this->insertSlot($this->clinicianEast, $this->locTehran, $tehranFuture->format('Y-m-d'), $tehranFuture->format('H:i:s'));

        $result = $svc->availability(
            $this->clinicianEast,
            $tehranPast->modify('-2 days')->format('Y-m-d'),
            $tehranFuture->modify('+2 days')->format('Y-m-d')
        );

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_EAST, 'UTC']);

        $ids = $this->flattenAvailabilityIds($result);
        self::assertContains($futureId, $ids, 'کنترل مثبت: اسلات آیندهٔ محلی Tehran باید در دسترس باشد');
        self::assertNotContains(
            $pastId,
            $ids,
            'CASE A (شرق UTC): اسلاتی که ۹۰ دقیقه در ساعت محلی Tehran گذشته است نباید در دسترس باشد؛ '
            . 'اما پیاده‌سازی فعلی مقایسه را با gmdate() (قاب UTC) انجام می‌دهد و آن را نشان می‌دهد. '
            . 'slot=' . $slotDate . ' ' . $slotTime . ' Asia/Tehran utc_now=' . $before->format('Y-m-d H:i:s')
        );
    }

    // =================================================================
    // RED/T2 — CASE B (غرب UTC): اسلات آیندهٔ محلی نباید توسط مرز UTC مخفی شود
    // =================================================================

    public function testFutureLocationLocalSlotIsNotHiddenWestOfUtc(): void
    {
        $svc = $this->newBookingService();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $niueNow = $before->setTimezone(new DateTimeZone(self::TZ_WEST));
        $todayNiue = $niueNow->format('Y-m-d');

        $legPrimary = 0;   // niueNow+2h — رژیم UTC≥11:00Z همیشه divergent (زمان‌روز UTC جلوتر از محلی)
        $w2Time = $niueNow->modify('+2 hours');

        $slotFixedId = 0;
        $applicable = [];

        // Leg W2: فقط وقتی امن است که +2h در همان روز محلی بماند (نیاز: HH ≤ 21).
        if ((int) $niueNow->format('H') <= 21) {
            $legPrimary = $this->insertSlot(
                $this->clinicianWest,
                $this->locNiue,
                $w2Time->format('Y-m-d'),
                $w2Time->format('H:i:s')
            );
            $applicable[] = 'W2(niueNow+2h=' . $w2Time->format('Y-m-d H:i:s') . ')';
        }
        // Leg W1: ثابت ۲۳:۴۵ امروز محلی — فقط وقتی هنوز ۲۳:۴۵ محلی نرسیده (حاشیه ۱۵ دقیقه).
        if ($niueNow->format('H:i:s') <= '23:44:00') {
            $slotFixedId = $this->insertSlot($this->clinicianWest, $this->locNiue, $todayNiue, '23:45:00');
            $applicable[] = 'W1(niue 23:45:00)';
        }
        if ($applicable === []) {
            self::markTestSkipped('INCONCLUSIVE: پنجرهٔ نادقیق ۱۵ دقیقه‌ای پایان روز محلی Niue — اجرای مجدد در لحظهٔ دیگر');
        }

        $result = $svc->availability($this->clinicianWest, $todayNiue, $niueNow->modify('+5 days')->format('Y-m-d'));
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_WEST, 'UTC']);

        $ids = $this->flattenAvailabilityIds($result);
        if ($legPrimary > 0) {
            self::assertContains(
                $legPrimary,
                $ids,
                'CASE B (غرب UTC, leg W2): اسلاتِ ۲ ساعتِ آیندهٔ محلی Niue نباید توسط مقایسهٔ '
                . 'slot_time با gmdate(\'H:i:s\') مخفی شود (UTC روزمحلی را به‌اشتباه «امروزِ همه‌جا» می‌داند)'
            );
        }
        if ($slotFixedId > 0) {
            self::assertContains(
                $slotFixedId,
                $ids,
                'CASE B (غرب UTC, leg W1): اسلاتِ ۲۳:۴۵ امروز محلی Niue نباید توسط Clamp '
                . '$from=max($from, gmdate(\'Y-m-d\')) مخفی شود — UTC هنوز در «فردای» Niue نیست'
            );
        }
        self::assertNotEmpty($applicable, 'دست‌کم یک leg باید فعال باشد: ' . implode(',', $applicable));
    }

    // =================================================================
    // RED/T3 — تجمیع چند-Location: فیلتر باید per-slot با Locationِ خودش باشد
    //   (هیچ انتخاب دلخواهِ یک timezone برای کل پاسخ مجاز نیست)
    // =================================================================

    public function testAggregatedAvailabilityUsesEachSlotsOwnLocation(): void
    {
        $svc = $this->newBookingService();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $tehranPast = $before->setTimezone(new DateTimeZone(self::TZ_EAST))->modify('-90 minutes');
        $eastId = $this->insertSlot(
            $this->clinicianMulti,
            $this->locTehran,
            $tehranPast->format('Y-m-d'),
            $tehranPast->format('H:i:s')
        );

        $niueNow = $before->setTimezone(new DateTimeZone(self::TZ_WEST));
        $westFuture = $niueNow->modify('+2 hours');
        $westId = $this->insertSlot(
            $this->clinicianMulti,
            $this->locNiue,
            $westFuture->format('Y-m-d'),
            $westFuture->format('H:i:s')
        );

        $from = min($tehranPast->format('Y-m-d'), $westFuture->format('Y-m-d'));
        $to = max($tehranPast->format('Y-m-d'), $westFuture->format('Y-m-d'));

        $runAvailability = function () use ($svc, $from, $to): array {
            $result = $svc->availability($this->clinicianMulti, $from, $to);

            return $this->flattenAvailabilityIds($result);
        };

        $ids = $runAvailability();

        // اینورینت ۷: timezone محیطی PHP نباید هیچ اثری بر تصمیم داشته باشد.
        $ambientZones = ['UTC', 'Pacific/Kiritimati', 'America/New_York'];
        foreach ($ambientZones as $ambient) {
            date_default_timezone_set($ambient);
            $underAmbient = $runAvailability();
            date_default_timezone_set((string) $this->originalPhpTz);
            self::assertSame(
                $ids,
                $underAmbient,
                'اینورینت ۷: نتیجهٔ availability نباید با تغییر PHP default timezone (' . $ambient . ') عوض شود'
            );
        }

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_EAST, self::TZ_WEST, 'UTC']);

        $t804 = $this->flattenAvailabilityIds($svc->availability($this->clinicianMulti, $from, $to));
        self::assertSame($ids, $t804, 'تکرار فراخوانی باید پایدار باشد (آشکارسازی نویز)');

        self::assertNotContains(
            $eastId,
            $ids,
            'T3/CASE A: اسلات Tehran (۹۰ دقیقه پیش در ساعت محلی) نباید در پاسخ تجمیعی باشد — '
            . 'پاسخ یکپارچه نمی‌تواند با یک timezone واحد برای هر دو Location درست باشد'
        );
        self::assertContains(
            $westId,
            $ids,
            'T3/CASE B: اسلات Niue (۲ ساعتِ آیندهٔ محلی) باید در همان پاسخ تجمیعی باقی بماند'
        );
    }

    // =================================================================
    // RED/T4 — CASE B + اینورینت ۴: پنجرهٔ پیش‌فرض REST بر مبنای تقویم Location
    // =================================================================

    public function testRestDefaultWindowUsesLocationCalendarWestOfUtc(): void
    {
        // Routeها در setUp (حالت تک‌Clinic) register و singletonها prewarm شده‌اند.
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $niueNow = $before->setTimezone(new DateTimeZone(self::TZ_WEST));

        $slotId = 0;
        if ($niueNow->format('H:i:s') <= '21:55:00') {
            $w = $niueNow->modify('+2 hours');
            $slotId = $this->insertSlot($this->clinicianWest, $this->locNiue, $w->format('Y-m-d'), $w->format('H:i:s'));
        } elseif ($niueNow->format('H:i:s') <= '23:44:00') {
            $slotId = $this->insertSlot($this->clinicianWest, $this->locNiue, $niueNow->format('Y-m-d'), '23:45:00');
        } else {
            self::markTestSkipped('INCONCLUSIVE: پنجرهٔ نادقیق پایان روز محلی Niue (۱۵ دقیقه) — اجرای مجدد در لحظهٔ دیگر');
        }

        // Schedule فعال برای پزشک تا Location عملیاتیِ endpoint شناخته‌شده باشد
        // (پنجرهٔ پیش‌فرش باید از تقویم همین Location ساخته شود، نه gmdate).
        // مقدار day_of_week در این تست بی‌اهمیت است (availability فقط اسلات‌ها را می‌خواند).
        $this->insertSchedule($this->clinicianWest, $this->locNiue, 3, '08:00:00', '20:00:00');

        $request = new WP_REST_Request('GET', self::NS . '/availability');
        $request->set_param('clinician_id', $this->clinicianWest);
        // from/to عمداً ارسال نمی‌شوند → مسیر «پیش‌فرض پنجره».
        $response = rest_do_request($request);

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_WEST, 'UTC']);

        self::assertSame(200, $response->get_status(), 'REST availability باید بدون from/to صریح 200 بدهد');
        $data = $response->get_data();
        $ids = [];
        foreach ((array) ($data['data']['days'] ?? []) as $day) {
            foreach ((array) ($day['slots'] ?? []) as $slot) {
                $ids[] = (int) ($slot['slot_id'] ?? 0);
            }
        }
        self::assertContains(
            $slotId,
            $ids,
            'CASE B + اینورینت ۴: با حذف from/to، پنجرهٔ پیش‌فرش باید از تقویم محلی Niue شروع شود؛ '
            . 'پیاده‌سازی فعلی پیش‌فرش from=gmdate(\'Y-m-d\') دارد که روز فعلی Niue را در بیشتر ساعات روز حذف می‌کند.'
        );
    }

    // =================================================================
    // RED/T5 — اینورینت ۶: سازگاری hold با نمایش (غرب UTC)
    //   اسلات معتبر آیندهٔ محلی که availability نشانش می‌دهد نباید توسط
    //   پیش‌چک legacy-UTC رد شود.
    // =================================================================

    public function testHoldAcceptsLocationLocalFutureSlotWestOfUtc(): void
    {
        $svc = $this->newBookingService();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nyNow = $before->setTimezone(new DateTimeZone(self::TZ_WEST_HOLD));

        // 5.5 ساعت آیندهٔ محلی → لحظهٔ واقعی UTC همان 5.5 ساعت آینده است (≥ minLead=2h)؛
        // اما تفسیر UTCِ همان رشتهٔ wall-clock فقط ~1.5 ساعت آینده دیده می‌شود (EDT≈UTC-4).
        $slotLocal = $nyNow->modify('+330 minutes');
        $slotDate = $slotLocal->format('Y-m-d');
        $slotTime = $slotLocal->format('H:i:s');
        $slotId = $this->insertSlot($this->clinicianHold, $this->locNewYork, $slotDate, $slotTime);

        $patientUserId = $this->makePatientUser();

        $availabilityIds = $this->flattenAvailabilityIds(
            $svc->availability($this->clinicianHold, $slotLocal->modify('-1 day')->format('Y-m-d'), $slotLocal->modify('+1 day')->format('Y-m-d'))
        );

        try {
            $hold = $svc->hold($patientUserId, $this->clinicianHold, $slotDate, $slotTime, $slotId);
        } catch (BookingException $e) {
            $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_WEST_HOLD, 'UTC']);
            self::fail(
                'اینورینت ۶ (ناسازگاری claim با نمایش): اسلات ' . $slotDate . ' ' . $slotTime . ' '
                . self::TZ_WEST_HOLD . ' در ساعت محلی ۵٫۵ ساعتِ آینده است (lead واقعی UTC نیز ۵٫۵h ≥ minLead=2h) '
                . 'و availability هم آن را معتبر نشان می‌دهد، اما hold() با ' . $e->errorCode . ' رد شد — '
                . 'پیش‌چک legacy-UTC رشتهٔ wall-clock را UTC تفسیر کرده (~1.5h < 2h). utc_now=' . $before->format('Y-m-d H:i:s')
            );
        }

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_WEST_HOLD, 'UTC']);
        self::assertNotEmpty($hold['hold_token'] ?? '', 'hold باید token برگرداند');
        self::assertContains($slotId, $availabilityIds, 'context: availability همان اسلات را آینده نشان داده است');
    }

    public function testRepeatedNewYorkSlotIsNeverOfferedHeldOrConfirmed(): void
    {
        global $wpdb;
        $svc = $this->newBookingService();
        $repeated = $this->futureRepeatedLocalWallTime(self::TZ_WEST_HOLD);
        $date = $repeated['date'];
        $time = $repeated['time'];
        $this->allowFutureTransitionBooking();
        $ambiguousSlotId = $this->insertSlot($this->clinicianHold, $this->locNewYork, $date, $time);

        $repeatedWall = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone(self::TZ_WEST_HOLD));
        $available = $this->flattenAvailabilityIds($svc->availability(
            $this->clinicianHold,
            $repeatedWall->modify('-1 day')->format('Y-m-d'),
            $repeatedWall->modify('+1 day')->format('Y-m-d')
        ));
        self::assertNotContains(
            $ambiguousSlotId,
            $available,
            'A repeated America/New_York wall time must be hidden from public availability.'
        );

        foreach (['quote', 'hold'] as $path) {
            try {
                if ($path === 'quote') {
                    $svc->quote($this->clinicianHold, $date, $time, $ambiguousSlotId);
                } else {
                    $svc->hold($this->makePatientUser(), $this->clinicianHold, $date, $time, $ambiguousSlotId);
                }
                self::fail('Repeated Location-local wall time must be rejected by ' . $path . '.');
            } catch (BookingException $e) {
                self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
            }
        }

        // A hold that pre-dates this policy must not be converted into an
        // appointment if its persisted slot later resolves to a repeated time.
        // This OTP-shaped identity deliberately has no Patient or Patient/User
        // link. Confirm would create both on a valid slot, so it exposes whether
        // the final locked DST check happens before every identity side effect.
        [$patientUserId, $mobile] = $this->makeUnlinkedOtpUser();
        $legacyClinicianId = $this->insertClinician('Dr Legacy Hold');
        $legacySlotId = $this->insertSlot($legacyClinicianId, $this->locNewYork, $date, '03:00:00');
        $hold = $svc->hold($patientUserId, $legacyClinicianId, $date, '03:00:00', $legacySlotId);
        self::assertNotEmpty($hold['hold_token']);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . App::db()->table('cpms_schedule_slots') . ' SET slot_time = %s WHERE id = %d',
            $time,
            $legacySlotId
        ));

        // Snapshot after HOLD_CREATED: the rejected confirm itself must add no
        // Patient, link, clinical record, audit entry, or retained idem state.
        $patientsBefore = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d',
            $this->clinicId
        ));
        $linksBefore = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d',
            $patientUserId
        ));
        $auditsBefore = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
            $this->clinicId
        ));
        $idemKey = 'dst-policy-confirm-' . bin2hex(random_bytes(4));

        try {
            $svc->confirm((string) $hold['hold_token'], $patientUserId, null, $idemKey, 'Legacy', 'Fixture');
            self::fail('A legacy hold for a repeated Location-local wall time must not confirm.');
        } catch (BookingException $e) {
            self::assertSame('CLINIC_VALIDATION_FAILED', $e->errorCode);
        }

        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE slot_id = %d',
                $legacySlotId
            )),
            'Failed confirmation must not create an appointment from an ambiguous legacy hold.'
        );
        self::assertSame(
            $patientsBefore,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d',
                $this->clinicId
            )),
            'Rejected confirm must leave existing unrelated Patients unchanged.'
        );
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d AND mobile = %s',
                $this->clinicId,
                $mobile
            )),
            'Rejected confirm must not create the previously unlinked Patient.'
        );
        self::assertSame(
            $linksBefore,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d',
                $patientUserId
            )),
            'Rejected confirm must not create a Patient/User link.'
        );
        self::assertSame(
            $auditsBefore,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
                $this->clinicId
            )),
            'Rejected confirm must not emit Patient or success audit side effects.'
        );
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_idempotency_keys') . ' WHERE `key` = %s AND clinic_id = %d',
                $idemKey,
                $this->clinicId
            )),
            'Rejected confirm must release its idempotency reservation.'
        );
        $releasedHold = App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_slot_holds') . ' WHERE token = %s',
            [(string) $hold['hold_token']]
        );
        self::assertIsArray($releasedHold);
        self::assertSame('released', (string) $releasedHold['status'], 'Rejected confirm preserves established hold release behavior.');
        $slotCounts = App::db()->fetchRow(
            'SELECT booked_count, held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$legacySlotId]
        );
        self::assertIsArray($slotCounts);
        self::assertSame(0, (int) $slotCounts['booked_count']);
        self::assertSame(0, (int) $slotCounts['held_count'], 'Rejected confirm releases the prior hold capacity.');
        $legacySlot = App::db()->fetchRow(
            'SELECT slot_date, slot_time FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$legacySlotId]
        );
        self::assertIsArray($legacySlot);
        self::assertSame($date, (string) $legacySlot['slot_date']);
        self::assertSame($time, (string) $legacySlot['slot_time']);
    }

    public function testStaffCreateLocksLocationBeforeFinalTimezoneValidation(): void
    {
        if (
            !function_exists('pcntl_fork')
            || !function_exists('posix_kill')
            || !defined('SIGTERM')
            || !defined('SIGKILL')
            || !function_exists('stream_socket_pair')
            || !class_exists('mysqli')
        ) {
            self::markTestSkipped('pcntl/posix signals, stream_socket_pair, and mysqli are required for the independent-session race proof.');
        }

        global $wpdb;
        $repeated = $this->futureRepeatedLocalWallTime(self::TZ_WEST_HOLD);
        $date = $repeated['date'];
        $time = $repeated['time'];
        $this->allowFutureTransitionBooking();

        // Preflight must see this committed valid timezone. The writer then
        // changes the same row to New York, but holds its transaction open.
        $wpdb->update(
            App::db()->table('cpms_locations'),
            ['timezone' => 'Asia/Tokyo', 'updated_at' => App::db()->nowUtcSql()],
            ['id' => $this->locNewYork]
        );
        $slotId = $this->insertSlot($this->clinicianHold, $this->locNewYork, $date, $time);
        $staffUserId = $this->makePatientUser();
        $patientId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT patient_id FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d LIMIT 1',
            $staffUserId
        ));
        self::assertGreaterThan(0, $patientId, 'fixture: persisted Clinic Patient');

        $before = [
            'appointments' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE slot_id = %d',
                $slotId
            )),
            'patients' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d',
                $this->clinicId
            )),
            'links' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d',
                $staffUserId
            )),
            'audits' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
                $this->clinicId
            )),
            'patient' => App::db()->fetchRow(
                'SELECT first_name, last_name, mobile, status FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
                [$patientId]
            ),
        ];
        self::assertIsArray($before['patient']);

        // Make fixture writes visible to all independent sessions before fork.
        $wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $prefix = $wpdb->prefix;
        $writerPipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $bookingPipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($writerPipes === false || $bookingPipes === false) {
            self::markTestSkipped('Cannot create independent-process synchronization pipes.');
        }
        [$writerParent, $writerChild] = $writerPipes;
        [$bookingParent, $bookingChild] = $bookingPipes;
        $writerPid = null;
        $bookingPid = null;
        $writerReleased = false;
        $writerStatus = null;
        $bookingStatus = null;
        $observer = null;

        try {
            $writerFork = pcntl_fork();
            if ($writerFork === 0) {
                fclose($writerParent);
                fclose($bookingParent);
                fclose($bookingChild);
                $this->locationTimezoneWriterChild($writerChild, $this->locNewYork);
                exit(2);
            }
            if ($writerFork < 1) {
                self::fail('pcntl_fork failed for the independent Location writer');
            }
            $writerPid = $writerFork;
            self::closeSynchronizationPipe($writerChild);

            $writerMessage = $this->readSynchronizationLine($writerParent, self::RACE_CONNECTION_TIMEOUT_SECONDS);
            self::assertIsString($writerMessage, 'writer must report its held Location lock before booking starts');
            self::assertMatchesRegularExpression('/^writer_locked:[1-9][0-9]*$/', $writerMessage);
            $writerConnectionId = (int) substr($writerMessage, strlen('writer_locked:'));

            $bookingFork = pcntl_fork();
            if ($bookingFork === 0) {
                fclose($bookingParent);
                fclose($writerParent);
                $this->staffBookingChild(
                    $bookingChild,
                    $prefix,
                    $this->clinicId,
                    $staffUserId,
                    $patientId,
                    $this->clinicianHold,
                    $date,
                    $time,
                    $slotId
                );
                exit(2);
            }
            if ($bookingFork < 1) {
                self::fail('pcntl_fork failed for the independent BookingService actor');
            }
            $bookingPid = $bookingFork;
            self::closeSynchronizationPipe($bookingChild);

            $bookingMessage = $this->readSynchronizationLine($bookingParent, self::RACE_CONNECTION_TIMEOUT_SECONDS);
            self::assertIsString($bookingMessage, 'booking actor must report its own fresh DB connection before product execution');
            self::assertMatchesRegularExpression('/^booking_connected:[1-9][0-9]*$/', $bookingMessage);
            $bookingConnectionId = (int) substr($bookingMessage, strlen('booking_connected:'));
            self::assertNotSame($writerConnectionId, $bookingConnectionId, 'writer and BookingService actor must use distinct DB sessions');
            self::assertNotSame(getmypid(), $writerPid, 'writer must be a distinct process');
            self::assertNotSame(getmypid(), $bookingPid, 'booking actor must be a distinct process');

            $observer = $this->freshMysqli();
            // This bounded state poll is the synchronization oracle: a matching
            // InnoDB wait edge can exist only after BookingService accepted the
            // Tokyo preflight, entered its transaction, locked Slot, and then
            // blocked on the writer's still-held Location row.
            self::assertTrue(
                $this->waitForLocationLockContention(
                    $observer,
                    $bookingConnectionId,
                    $writerConnectionId,
                    $this->locNewYork,
                    self::RACE_LOCATION_WAIT_TIMEOUT_SECONDS
                ),
                'mandatory interleaving not observed: BookingService never became blocked by the writer-held Location row'
            );

            // Independently prove the first half of that interleaving: the
            // blocked booking transaction owns the Slot lock, so NOWAIT must
            // fail immediately rather than becoming a timing-based assertion.
            $slotProbe = $this->freshMysqli();
            try {
                $slotResult = @$slotProbe->query(
                    'SELECT id FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = ' . $slotId . ' FOR UPDATE NOWAIT'
                );
                self::assertFalse($slotResult, 'booking actor must retain the Slot lock while blocked on Location');
                self::assertSame(3572, $slotProbe->errno, 'NOWAIT must prove a live Slot-row conflict, not a timeout or unrelated SQL error');
            } finally {
                @$slotProbe->close();
            }

            // Commit is deliberately impossible before both required facts above
            // are observed. No sleep participates in correctness.
            self::assertSame(7, fwrite($writerParent, "commit\n"), 'parent must release the writer only after the observed contention');
            $writerReleased = true;

            $bookingOutcomeLine = $this->readSynchronizationLine($bookingParent, self::RACE_CONNECTION_TIMEOUT_SECONDS);
            self::assertIsString($bookingOutcomeLine, 'booking actor must finish after writer commit');
            $bookingOutcome = json_decode($bookingOutcomeLine, true);
            self::assertIsArray($bookingOutcome, 'booking actor must return structured outcome evidence');
            self::assertSame('booking_exception', $bookingOutcome['result'] ?? null, 'final protected validation must reject after the authoritative timezone commit');
            self::assertSame('CLINIC_VALIDATION_FAILED', $bookingOutcome['code'] ?? null, 'committed repeated New York wall time must be rejected');
            self::assertSame($bookingConnectionId, (int) ($bookingOutcome['connection_id'] ?? 0), 'outcome must come from the announced fresh BookingService session');
            self::assertTrue((bool) ($bookingOutcome['own_wpdb_connected'] ?? false), 'BookingService actor must own its wpdb connection');

            $writerStatus = $this->waitForChildExit($writerPid, self::RACE_GRACEFUL_REAP_TIMEOUT_SECONDS);
            $bookingStatus = $this->waitForChildExit($bookingPid, self::RACE_GRACEFUL_REAP_TIMEOUT_SECONDS);
            self::assertIsInt($writerStatus, 'writer must exit within the bounded cleanup window');
            self::assertIsInt($bookingStatus, 'booking actor must exit within the bounded cleanup window');
            self::assertTrue(pcntl_wifexited($writerStatus) && pcntl_wexitstatus($writerStatus) === 0, 'writer must commit its intended Location timezone update');
            self::assertTrue(pcntl_wifexited($bookingStatus) && pcntl_wexitstatus($bookingStatus) === 0, 'booking actor must terminate cleanly after the rejection');

            self::assertSame('America/New_York', $this->locationTimezone($this->locNewYork), 'final validation must consume the writer-committed authoritative timezone');
            self::assertSame($before['appointments'], (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE slot_id = %d',
                $slotId
            )), 'rejected staff booking must not create an appointment');
            self::assertSame($before['patients'], (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d',
                $this->clinicId
            )), 'rejected staff booking must not create or alter Patients');
            self::assertSame($before['links'], (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE wp_user_id = %d',
                $staffUserId
            )), 'rejected staff booking must not create or alter Patient/User links');
            self::assertSame($before['audits'], (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE clinic_id = %d',
                $this->clinicId
            )), 'rejected staff booking must not emit a success audit');
            self::assertSame($before['patient'], App::db()->fetchRow(
                'SELECT first_name, last_name, mobile, status FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d',
                [$patientId]
            ), 'rejected staff booking must leave the existing Patient unchanged');
            $slot = App::db()->fetchRow(
                'SELECT booked_count, held_count FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
                [$slotId]
            );
            self::assertIsArray($slot);
            self::assertSame(0, (int) $slot['booked_count'], 'rejected staff booking must not consume capacity');
            self::assertSame(0, (int) $slot['held_count'], 'rejected staff booking must not create or retain a hold');
            $this->assertRaceLocksReleased($slotId, $this->locNewYork);
        } finally {
            if (!$writerReleased && is_resource($writerParent)) {
                @fwrite($writerParent, "rollback\n");
            }
            $this->reapOrTerminateKnownChild($writerPid, $writerStatus);
            $this->reapOrTerminateKnownChild($bookingPid, $bookingStatus);
            if ($observer instanceof \mysqli) {
                @$observer->close();
            }
            self::closeSynchronizationPipe($writerParent);
            self::closeSynchronizationPipe($writerChild);
            self::closeSynchronizationPipe($bookingParent);
            self::closeSynchronizationPipe($bookingChild);
        }
    }

    public function testRaceCleanupGuardsPidsAndBoundsTermination(): void
    {
        if (!defined('SIGTERM') || !defined('SIGKILL')) {
            self::markTestSkipped('POSIX signal constants are required for cleanup helper coverage.');
        }

        $waits = [];
        $signals = [];
        $neverWait = static function (int $pid, int $seconds) use (&$waits): int {
            $waits[] = [$pid, $seconds];

            return 0;
        };
        $neverSignal = static function (int $pid, int $signal) use (&$signals): bool {
            $signals[] = [$pid, $signal];

            return true;
        };
        self::assertNull(self::cleanupKnownChild(-1, null, $neverWait, $neverSignal));
        self::assertNull(self::cleanupKnownChild(0, null, $neverWait, $neverSignal));
        self::assertNull(self::cleanupKnownChild(null, null, $neverWait, $neverSignal));
        self::assertSame([], $waits, 'fork failure and invalid PIDs must never reach wait or kill callbacks');
        self::assertSame([], $signals, 'fork failure and invalid PIDs must never reach wait or kill callbacks');

        $waits = [];
        $signals = [];
        $alreadyExited = self::cleanupKnownChild(
            123,
            null,
            static function (int $pid, int $seconds) use (&$waits): int {
                $waits[] = [$pid, $seconds];

                return 0;
            },
            static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = [$pid, $signal];

                return true;
            }
        );
        self::assertSame(0, $alreadyExited, 'an already-exited child must be reaped without signalling');
        self::assertSame([[123, self::RACE_GRACEFUL_REAP_TIMEOUT_SECONDS]], $waits);
        self::assertSame([], $signals);

        $waits = [];
        $signals = [];
        $outcomes = [null, null, 0];
        $terminated = self::cleanupKnownChild(
            456,
            null,
            static function (int $pid, int $seconds) use (&$waits, &$outcomes): int|false|null {
                $waits[] = [$pid, $seconds];

                return array_shift($outcomes);
            },
            static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = [$pid, $signal];

                return true;
            }
        );
        self::assertSame(0, $terminated, 'bounded escalation must return the final reap status');
        self::assertSame([
            [456, self::RACE_GRACEFUL_REAP_TIMEOUT_SECONDS],
            [456, self::RACE_TERMINATION_REAP_TIMEOUT_SECONDS],
            [456, self::RACE_TERMINATION_REAP_TIMEOUT_SECONDS],
        ], $waits, 'timeout escalation must use only the three bounded reap windows');
        self::assertSame([[456, SIGTERM], [456, SIGKILL]], $signals, 'only the recorded positive child PID may be signalled');
    }

    public function testRaceSynchronizationFailureClosesAllPipeEnds(): void
    {
        if (!function_exists('stream_socket_pair')) {
            self::markTestSkipped('stream_socket_pair is required for synchronization cleanup coverage.');
        }
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pipes === false) {
            self::markTestSkipped('Cannot create synchronization pipes.');
        }
        [$parent, $child] = $pipes;
        $failureObserved = false;
        try {
            throw new \RuntimeException('deterministic synchronization failure');
        } catch (\RuntimeException) {
            $failureObserved = true;
        } finally {
            self::closeSynchronizationPipe($parent);
            self::closeSynchronizationPipe($child);
        }

        self::assertTrue($failureObserved);
        self::assertFalse(is_resource($parent), 'failure cleanup must close the parent synchronization pipe');
        self::assertFalse(is_resource($child), 'failure cleanup must close the child synchronization pipe');
    }

    // =================================================================
    // RED/T6 — اینورینت ۵: impact() باید از مرز «امروز محلیِ Locationِ هر اسلات» استفاده کند
    // =================================================================

    public function testImpactUsesSlotLocationLocalTodayBoundary(): void
    {
        $svc = $this->newScheduleService();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $todayKiri = $before->setTimezone(new DateTimeZone(self::TZ_EAST_EDGE))->format('Y-m-d');
        $todayNiue = $before->setTimezone(new DateTimeZone(self::TZ_WEST))->format('Y-m-d');

        // A1 = امروزِ محلی Kiritimati (در بیشتر ساعات روز = «فردای» UTC) — نباید «آینده» شمرده شود.
        $this->insertSlot($this->clinicianImpact, $this->locKiritimati, $todayKiri, '10:00:00');
        // A2 = فردای محلی Kiritimati — باید شمرده شود.
        $this->insertSlot($this->clinicianImpact, $this->locKiritimati, $before->setTimezone(new DateTimeZone(self::TZ_EAST_EDGE))->modify('+1 day')->format('Y-m-d'), '11:00:00');
        // B2 = فردای محلی Niue (= در رژیم UTC<11:00Z همان «امروز» UTC) — باید شمرده شود.
        $this->insertSlot($this->clinicianImpact, $this->locNiue, $before->setTimezone(new DateTimeZone(self::TZ_WEST))->modify('+1 day')->format('Y-m-d'), '12:00:00');
        // B9 = رزروشدهٔ فردای محلی Niue — باید reserved شمرده شود.
        $this->insertSlot($this->clinicianImpact, $this->locNiue, $before->setTimezone(new DateTimeZone(self::TZ_WEST))->modify('+1 day')->format('Y-m-d'), '13:00:00', 1);

        $impact = $this->withScope($this->clinicId, fn (): array => $svc->impact($this->clinicianImpact));

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_EAST_EDGE, self::TZ_WEST, 'UTC']);

        self::assertSame(
            2,
            (int) $impact['future_empty_slots'],
            'اینورینت ۵/impact: مرز باید «امروز محلیِ Locationِ هر اسلات» باشد — '
            . 'فقط A2 (فردای محلی Kiritimati) و B2 (فردای محلی Niue) خالیِ آینده‌اند؛ '
            . 'مرز gmdate (UTC) یا A1 را اشتباه می‌شمارد (UTC≥10:00Z) یا B2 را نمی‌شمارد (UTC<11:00Z). '
            . 'utc_now=' . $before->format('Y-m-d H:i:s') . ' todayKiri=' . $todayKiri . ' todayNiue=' . $todayNiue
        );
        self::assertSame(
            1,
            (int) $impact['future_reserved_slots'],
            'اینورینت ۵/impact: B9 (رزروشدهٔ فردای محلی Niue) باید reserved آینده شمرده شود — '
            . 'مرز UTC در رژیم UTC<11:00Z آن را گذشته می‌انگارد.'
        );
    }

    // =================================================================
    // RED/T7 — اینورینت ۵/۸/۱۰: بازتولید با مرز محلیِ cohort و fail-closed
    // =================================================================

    public function testRegenerationUsesSlotLocationLocalTodayBoundary(): void
    {
        $svc = $this->newScheduleService();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $todayKiri = $before->setTimezone(new DateTimeZone(self::TZ_EAST_EDGE))->format('Y-m-d');
        $niue = $before->setTimezone(new DateTimeZone(self::TZ_WEST));

        // ردیف برنامهٔ واقعی جهت update (مسیر محصول): لازم است Schedule در همان Clinic باشد.
        $scheduleId = $this->insertSchedule($this->clinicianRegen, $this->locKiritimati, 3, '08:00:00', '12:00:00');

        // rA1: خالی، امروزِ محلی Kiritimati → بازتولید نباید حذفش کند (بازتولید مجدد امروز محلی را هرگز نمی‌سازد).
        $rA1 = $this->insertSlot($this->clinicianRegen, $this->locKiritimati, $todayKiri, '09:00:00');
        // rB2: خالی، فردای محلی Niue → باید حذف شود (بازتولید مجدد آن را با برنامهٔ جدید بازتولید می‌کند).
        $rB2 = $this->insertSlot($this->clinicianRegen, $this->locNiue, $niue->modify('+1 day')->format('Y-m-d'), '10:00:00');
        // rI1: خالی در Location با timezone نامعتبر → fail-closed: دست‌نخورده.
        $rI1 = $this->insertSlot($this->clinicianRegen, $this->locInvalid, gmdate('Y-m-d', time() + 3 * 86400), '10:00:00');
        // rB9: رزروشده در Niue → محافظت‌شده.
        $rB9 = $this->insertSlot($this->clinicianRegen, $this->locNiue, $niue->modify('+3 days')->format('Y-m-d'), '11:00:00', 1);

        $updated = $this->withScope(
            $this->clinicId,
            fn (): array => $svc->update(321, $scheduleId, ['end_time' => '12:30'])
        );
        self::assertSame('12:30', (string) ($updated['end_time'] ?? ''), 'مسیر محصول باید واقعاً به‌روزرسانی کرده باشد');

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertNoCalendarBoundaryCrossing($before, $after, [self::TZ_EAST_EDGE, self::TZ_WEST, 'UTC']);

        self::assertTrue(
            $this->slotExists($rA1),
            'اینورینت ۵/regenerate: اسلاتِ خالیِ امروزِ محلی Kiritimati نباید حذف شود — '
            . 'با مرز UTC (gmdate) در رژیم UTC≥10:00Z حذف می‌شود در حالی‌که تولید مجدد فقط از فردای محلی می‌سازد.'
        );
        self::assertFalse(
            $this->slotExists($rB2),
            'اینورینت ۵/regenerate: اسلاتِ خالیِ فردای محلی Niue باید حذف شود تا با برنامهٔ جدید بازتولید گردد — '
            . 'با مرز UTC در رژیم UTC<11:00Z رها می‌شود (stale availability بر اساس برنامهٔ قبلی).'
        );
        self::assertTrue(
            $this->slotExists($rI1),
            'اینورینت ۸/regenerate: cohort متعلق به Location با timezone نامعتبر باید fail-closed دست‌نخورده بماند'
        );
        self::assertTrue(
            $this->slotExists($rB9),
            'اینورینت ۱۰/regenerate: اسلات رزروشده هرگز حذف نمی‌شود'
        );
    }

    // =================================================================
    // RED/T8 — اینورینت ۸/availability: timezone نامعتبر Location ⇒ fail-closed
    // =================================================================

    public function testInvalidLocationTimezoneFailsClosedInAvailability(): void
    {
        $svc = $this->newBookingService();
        $utcToday = gmdate('Y-m-d');
        $date = gmdate('Y-m-d', time() + 3 * 86400);
        $slotId = $this->insertSlot($this->clinicianInvalid, $this->locInvalid, $date, '10:00:00');

        $result = $svc->availability($this->clinicianInvalid, $utcToday, gmdate('Y-m-d', time() + 6 * 86400));
        $ids = $this->flattenAvailabilityIds($result);

        self::assertNotContains(
            $slotId,
            $ids,
            'اینورینت ۸: اسلاتِ متعلق به Location با timezone نامعتبر (' . self::TZ_INVALID . ') '
            . 'باید fail-closed از availability حذف شود؛ فیلتر فعلی هیچ درکی از timezone Location ندارد و آن را نشان می‌دهد.'
        );
    }

    /**
     * Select a real future fallback transition from IANA data. The midpoint of
     * the repeated local interval is represented as a wall-clock date/time and
     * is proved ambiguous before it reaches the product path.
     *
     * @return array{date: string, time: string, transition_utc: int}
     */
    private function futureRepeatedLocalWallTime(string $timezone): array
    {
        $now = time();
        $from = $now + 2 * DAY_IN_SECONDS;
        $to = $now + 400 * DAY_IN_SECONDS;
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($from, $to);
        if ($transitions === false) {
            self::markTestSkipped('Environment does not expose IANA transition data for ' . $timezone . '.');
        }

        for ($i = 1, $count = count($transitions); $i < $count; $i++) {
            $beforeOffset = (int) $transitions[$i - 1]['offset'];
            $afterOffset = (int) $transitions[$i]['offset'];
            if ($afterOffset >= $beforeOffset) {
                continue;
            }
            $transitionUtc = (int) $transitions[$i]['ts'];
            $wallTimestamp = $transitionUtc + $afterOffset + intdiv($beforeOffset - $afterOffset, 2);
            $firstUtc = $wallTimestamp - $beforeOffset;
            $secondUtc = $wallTimestamp - $afterOffset;
            $wall = gmdate('Y-m-d H:i:s', $wallTimestamp);

            self::assertNotSame($firstUtc, $secondUtc, 'fixture: fallback must map one wall time to two distinct UTC instants');
            self::assertSame($wall, gmdate('Y-m-d H:i:s', $firstUtc + $beforeOffset), 'fixture: first offset must reproduce repeated wall time');
            self::assertSame($wall, gmdate('Y-m-d H:i:s', $secondUtc + $afterOffset), 'fixture: second offset must reproduce repeated wall time');
            self::assertNull(
                BookingWindow::slotUtcInstant(substr($wall, 0, 10), substr($wall, 11), $zone),
                'fixture: selected IANA transition midpoint must be rejected as a repeated Location-local wall time'
            );

            return [
                'date' => substr($wall, 0, 10),
                'time' => substr($wall, 11),
                'transition_utc' => $transitionUtc,
            ];
        }

        self::markTestSkipped('No future repeated local-time interval found for ' . $timezone . ' within bounded IANA search window.');
    }

    private function allowFutureTransitionBooking(): void
    {
        (new Settings(App::db(), $this->clinicId, App::audit()))->set('booking.max_future_days', 400);
        Settings::flushCache();
    }

    private function locationTimezoneWriterChild($pipe, int $locationId): void
    {
        global $wpdb;
        $mysqli = null;
        try {
            $mysqli = $this->freshMysqli();
            $mysqli->query('SET SESSION innodb_lock_wait_timeout = 5'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $mysqli->begin_transaction();
            $locations = App::db()->table('cpms_locations');
            $locked = $mysqli->query('SELECT id FROM ' . $locations . ' WHERE id = ' . $locationId . ' FOR UPDATE');
            $updated = $locked !== false && $mysqli->query(
                "UPDATE {$locations} SET timezone = 'America/New_York' WHERE id = " . $locationId
            ) !== false;
            if (!$updated) {
                @fwrite($pipe, "writer_error\n");
                $mysqli->rollback();
                exit(2);
            }
            @fwrite($pipe, 'writer_locked:' . $mysqli->thread_id . "\n");
            // The writer is already locked when this wait starts. Thirty seconds
            // exceeds the parent booking-connection (5s) and lock-observation
            // (10s) budgets with 15s bounded headroom for the Slot NOWAIT probe.
            // This is timeout safety only; the pipe/Performance-Schema state is
            // still the synchronization oracle.
            $command = $this->readSynchronizationLine($pipe, self::RACE_WRITER_COMMAND_TIMEOUT_SECONDS);
            if ($command === 'commit') {
                $mysqli->commit();
                exit(0);
            }
            $mysqli->rollback();
            exit(3);
        } catch (\Throwable $e) {
            @fwrite($pipe, "writer_error\n");
            if ($mysqli instanceof \mysqli) {
                @$mysqli->rollback();
            }
            exit(2);
        } finally {
            if ($mysqli instanceof \mysqli) {
                @$mysqli->close();
            }
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private function staffBookingChild(
        $pipe,
        string $prefix,
        int $clinicId,
        int $staffUserId,
        int $patientId,
        int $clinicianId,
        string $date,
        string $time,
        int $slotId
    ): void {
        $wdb = null;
        try {
            global $wpdb;
            $wdb = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $wdb->set_prefix($prefix);
            $wpdb = $wdb;
            remove_all_filters('query');
            $wdb->has_connected = false;
            $wdb->init_charset();
            $wdb->check_connection();
            $connectionId = (int) $wdb->get_var('SELECT CONNECTION_ID()');
            if ($connectionId <= 0) {
                throw new \RuntimeException('fresh BookingService wpdb connection was not established');
            }
            ScopeContext::set(ClinicScope::forClinic($clinicId));
            @fwrite($pipe, 'booking_connected:' . $connectionId . "\n");

            $cpms = new CpmsDb($wdb);
            $op = new OpLogger($cpms);
            $audit = new AuditLogger($cpms, $op);
            $factory = new SettingsFactory($cpms, $audit);
            $sms = new SmsService(
                $cpms,
                $factory,
                new SmsProviderRegistry(),
                new CredentialVault(),
                $audit,
                $op,
                new JobQueue($cpms, $op),
                static fn (): int => $clinicId
            );
            $service = new BookingService(
                $cpms,
                new SlotRepository($cpms),
                new AppointmentRepository($cpms),
                new PatientRepository($cpms),
                $factory,
                new SchedulingLocationLocalAllowAllLicenseGate(),
                $audit,
                $op,
                new Idempotency($cpms),
                $sms,
                null,
                new MembershipRepository($cpms)
            );

            try {
                $service->createByStaff($staffUserId, $patientId, $clinicianId, $date, $time, null, $slotId);
                $outcome = ['result' => 'unexpected_success'];
            } catch (BookingException $e) {
                $outcome = ['result' => 'booking_exception', 'code' => $e->errorCode];
            }
            $outcome['connection_id'] = $connectionId;
            $outcome['own_wpdb_connected'] = true;
            @fwrite($pipe, json_encode($outcome, JSON_UNESCAPED_UNICODE) . "\n");
            exit(0);
        } catch (\Throwable $e) {
            @fwrite($pipe, json_encode([
                'result' => 'fatal',
                'detail' => get_class($e) . ': ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE) . "\n");
            exit(2);
        } finally {
            ScopeContext::clear();
            if ($wdb instanceof \wpdb) {
                @$wdb->close();
            }
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private function freshMysqli(): \mysqli
    {
        global $wpdb;
        $mysqli = @new \mysqli($wpdb->dbhost, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname);
        if ($mysqli->connect_errno !== 0) {
            throw new \RuntimeException('fresh mysqli connection failed: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        return $mysqli;
    }

    private function readSynchronizationLine($pipe, int $seconds): ?string
    {
        stream_set_timeout($pipe, $seconds);
        $line = fgets($pipe);
        $meta = stream_get_meta_data($pipe);
        if ($line === false || !empty($meta['timed_out'])) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private function waitForLocationLockContention(
        \mysqli $observer,
        int $bookingConnectionId,
        int $writerConnectionId,
        int $locationId,
        int $seconds
    ): bool {
        $deadline = microtime(true) + $seconds;
        $locationTable = $observer->real_escape_string(App::db()->table('cpms_locations'));
        // MySQL 8.4 removed the INFORMATION_SCHEMA INNODB_LOCK_* tables.
        // Performance Schema gives the exact requesting/blocking sessions and
        // the blocking Location record, rather than inferring contention from
        // a timeout or a mere matching transaction state.
        $sql = 'SELECT COUNT(*) FROM performance_schema.data_lock_waits waits '
            . 'INNER JOIN performance_schema.threads requesting '
            . 'ON requesting.thread_id = waits.requesting_thread_id '
            . 'INNER JOIN performance_schema.threads blocking '
            . 'ON blocking.thread_id = waits.blocking_thread_id '
            . 'INNER JOIN performance_schema.data_locks blocking_lock '
            . 'ON blocking_lock.engine_lock_id = waits.blocking_engine_lock_id '
            . 'WHERE requesting.processlist_id = ' . $bookingConnectionId . ' '
            . 'AND blocking.processlist_id = ' . $writerConnectionId . ' '
            . "AND blocking_lock.object_schema = DATABASE() AND blocking_lock.object_name = '{$locationTable}' "
            . "AND blocking_lock.lock_type = 'RECORD' AND blocking_lock.lock_data = '{$locationId}'";
        do {
            $result = $observer->query($sql);
            if ($result === false) {
                throw new \RuntimeException('Cannot inspect Performance Schema lock waits: ' . $observer->error);
            }
            $row = $result->fetch_row();
            $result->free();
            if ((int) ($row[0] ?? 0) > 0) {
                return true;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function waitForChildExit(int $pid, int $seconds): int|false|null
    {
        if ($pid <= 0 || $seconds <= 0) {
            return false;
        }
        $deadline = microtime(true) + $seconds;
        do {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                return $status;
            }
            if ($waited === -1) {
                // It was already reaped elsewhere (or is no longer our child).
                // Do not signal a PID which could subsequently be reused.
                return false;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function reapOrTerminateKnownChild(?int $pid, int|false|null $status): int|false|null
    {
        return self::cleanupKnownChild(
            $pid,
            $status,
            fn (int $childPid, int $seconds): int|false|null => $this->waitForChildExit($childPid, $seconds),
            static function (int $childPid, int $signal): bool {
                return function_exists('posix_kill') && @posix_kill($childPid, $signal);
            }
        );
    }

    /**
     * This deterministic seam makes cleanup safety testable without creating
     * synthetic fork failures or signalling any runner process.
     *
     * @param callable(int, int): (int|false|null) $waitForExit
     * @param callable(int, int): bool $sendSignal
     */
    private static function cleanupKnownChild(
        ?int $pid,
        int|false|null $status,
        callable $waitForExit,
        callable $sendSignal
    ): int|false|null {
        if ($status !== null || $pid === null || $pid <= 0) {
            return $status;
        }

        $status = $waitForExit($pid, self::RACE_GRACEFUL_REAP_TIMEOUT_SECONDS);
        if ($status !== null) {
            return $status;
        }
        $sendSignal($pid, SIGTERM);

        $status = $waitForExit($pid, self::RACE_TERMINATION_REAP_TIMEOUT_SECONDS);
        if ($status !== null) {
            return $status;
        }
        $sendSignal($pid, SIGKILL);

        return $waitForExit($pid, self::RACE_TERMINATION_REAP_TIMEOUT_SECONDS);
    }

    private static function closeSynchronizationPipe(&$pipe): void
    {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
        $pipe = null;
    }

    private function assertRaceLocksReleased(int $slotId, int $locationId): void
    {
        $probe = $this->freshMysqli();
        try {
            $probe->begin_transaction();
            self::assertNotFalse($probe->query(
                'SELECT id FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = ' . $slotId . ' FOR UPDATE NOWAIT'
            ), 'Slot lock must be released after rejected booking');
            self::assertNotFalse($probe->query(
                'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE id = ' . $locationId . ' FOR UPDATE NOWAIT'
            ), 'Location lock must be released after writer commit and booking rollback');
        } finally {
            @$probe->rollback();
            @$probe->close();
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function newBookingService(): BookingService
    {
        $db = App::db();

        return new BookingService(
            $db,
            new SlotRepository($db),
            new AppointmentRepository($db),
            new PatientRepository($db),
            App::settingsFactory(),
            App::licenseGate(),
            App::audit(),
            App::op(),
            App::idem(),
            App::smsService(),
            null,
            new MembershipRepository($db)
        );
    }

    private function newScheduleService(): ScheduleService
    {
        return new ScheduleService(
            App::db(),
            new ScheduleRepository(App::db()),
            new MembershipRepository(App::db()),
            new LocationRepository(App::db()),
            App::jobs(),
            App::audit(),
            App::op()
        );
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withScope(int $clinicId, callable $fn)
    {
        $previous = ScopeContext::tryGet();
        App::replaceExplicitScope(ClinicScope::forClinic($clinicId));
        try {
            return $fn();
        } finally {
            App::replaceExplicitScope($previous);
        }
    }

    private function warmRoutes(): void
    {
        Settings::flushCache();
        rest_do_request(new WP_REST_Request('GET', self::NS . '/health'));
        Settings::flushCache();
    }

    /**
     * @param array<string, mixed> $result
     * @return list<int>
     */
    private function flattenAvailabilityIds(array $result): array
    {
        $ids = [];
        foreach ((array) ($result['days'] ?? []) as $day) {
            foreach ((array) ($day['slots'] ?? []) as $slot) {
                $ids[] = (int) ($slot['slot_id'] ?? 0);
            }
        }
        sort($ids);

        return array_values($ids);
    }

    private function slotExists(int $slotId): bool
    {
        return App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        ) !== null;
    }

    /**
     * گارد عبور مرز تقویم میان‌اجرا — مطابق قرارداد تست‌های زمانی موجود:
     * در صورت رخداد (نادر) مشاهده «INCONCLUSIVE» است نه RED محصولی.
     *
     * @param list<string> $zones
     */
    private function assertNoCalendarBoundaryCrossing(DateTimeImmutable $before, DateTimeImmutable $after, array $zones): void
    {
        foreach ($zones as $tz) {
            $b = $before->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
            $a = $after->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
            if ($a !== $b) {
                self::markTestSkipped("INCONCLUSIVE (not a product RED): تاریخ تقویم {$tz} در میانهٔ اجرا عوض شد ({$b}→{$a})");
            }
        }
    }

    private function locationTimezone(int $locationId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT timezone FROM ' . App::db()->table('cpms_locations') . ' WHERE id = %d LIMIT 1',
            [$locationId]
        );
    }

    private function insertSlot(
        int $clinicianId,
        int $locationId,
        string $date,
        string $time,
        int $booked = 0,
        int $held = 0
    ): int {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . App::db()->table('cpms_schedule_slots') . '
                     (clinic_id, clinician_id, location_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, generated_from, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, 20, 2, %d, %d, 1, "manual", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $clinicianId,
                $locationId,
                $date,
                $time,
                $booked,
                $held,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'fixture: درج اسلات باید موفق باشد');
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'fixture: id اسلات تخصیص یافت');

        return $id;
    }

    private function insertSchedule(int $clinicianId, int $locationId, int $dow, string $start, string $end): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . App::db()->table('cpms_schedule') . '
                     (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, appointment_duration_min, slot_capacity, is_active, created_at, updated_at)
                 VALUES (%d, %d, %d, %d, %s, %s, 30, 2, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $locationId,
                $clinicianId,
                $dow,
                $start,
                $end,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'fixture: درج برنامه باید موفق باشد');

        return (int) $wpdb->insert_id;
    }

    private function makePatientUser(): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();

        // Patient record (برای satisfy mobileForUser → patient_user_links primary)
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . App::db()->table('cpms_patients') . '
                     (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                'MR-P6S2-' . bin2hex(random_bytes(3)),
                'Tz',
                'Patient',
                '0912' . random_int(1000000, 9999999),
                $now,
                $now
            )
        );
        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $patientId, 'fixture: patient ساخته شود');

        $mobile = (string) $wpdb->get_var(
            $wpdb->prepare('SELECT mobile FROM ' . App::db()->table('cpms_patients') . ' WHERE id = %d', $patientId)
        );

        $login = 'p6s2_pat_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login, 'pass-12345', $login . '@test.local');
        self::assertGreaterThan(0, $userId, 'fixture: کاربر بیمار ساخته شود');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role('cpms_patient');
        }

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . App::db()->table('cpms_patient_user_links') . '
                     (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
                 VALUES (%d, %d, %d, %s, 1, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $patientId,
                $userId,
                $mobile,
                $now
            )
        );

        return $userId;
    }

    /**
     * @return array{0: int, 1: string} user id + OTP-shaped server mobile
     */
    private function makeUnlinkedOtpUser(): array
    {
        $mobile = '0912' . random_int(1000000, 9999999);
        $login = 'p6s2_unlinked_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($login, 'pass-12345', $mobile . '@otp.cpms.local');
        self::assertGreaterThan(0, $userId, 'fixture: unlinked OTP user must be created');
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role('cpms_patient');
        }

        return [$userId, $mobile];
    }

    // ---------------- Fixture lifecycle ----------------

    private function buildFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $now = $db->nowUtcSql();
        $suffix = bin2hex(random_bytes(4));

        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $db->table('cpms_organizations') . ' (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'P6S2 Org',
                'p6s2-org-' . $suffix,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'fixture: organization ساخته شود');
        $this->orgId = (int) $wpdb->insert_id;

        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $db->table('cpms_clinics') . ' (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->orgId,
                'P6S2 Clinic',
                'p6s2-clinic-' . $suffix,
                self::CLINIC_TZ,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'fixture: clinic ساخته شود');
        $this->clinicId = (int) $wpdb->insert_id;

        $this->locTehran = $this->insertLocation('Tehran East', 'p6s2-loc-tehran-' . $suffix, self::TZ_EAST, 1);
        $this->locKiritimati = $this->insertLocation('Kiritimati Edge', 'p6s2-loc-kiri-' . $suffix, self::TZ_EAST_EDGE, 0);
        $this->locNiue = $this->insertLocation('Niue West', 'p6s2-loc-niue-' . $suffix, self::TZ_WEST, 0);
        $this->locNewYork = $this->insertLocation('New York West', 'p6s2-loc-ny-' . $suffix, self::TZ_WEST_HOLD, 0);
        $this->locInvalid = $this->insertLocation('Broken TZ', 'p6s2-loc-inv-' . $suffix, self::TZ_INVALID, 0);

        $this->clinicianEast = $this->insertClinician('Dr East');
        $this->clinicianWest = $this->insertClinician('Dr West');
        $this->clinicianMulti = $this->insertClinician('Dr Multi');
        $this->clinicianHold = $this->insertClinician('Dr Hold');
        $this->clinicianRegen = $this->insertClinician('Dr Regen');
        $this->clinicianImpact = $this->insertClinician('Dr Impact');
        $this->clinicianInvalid = $this->insertClinician('Dr InvalidTz');
    }

    private function insertLocation(string $name, string $slug, string $tz, int $isPrimary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . App::db()->table('cpms_locations') . ' (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $name,
                $slug,
                $tz,
                $isPrimary,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'fixture: location ساخته شود');

        return (int) $wpdb->insert_id;
    }

    private function insertClinician(string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $ok = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . App::db()->table('cpms_clinicians') . ' (clinic_id, full_name, is_active, created_at, updated_at) VALUES (%d, %s, 1, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId,
                $name,
                $now,
                $now
            )
        );
        self::assertNotFalse($ok, 'fixture: clinician ساخته شود');

        return (int) $wpdb->insert_id;
    }

    private function purgeFixture(): void
    {
        global $wpdb;
        $db = App::db();
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($this->clinicId > 0) {
            foreach ([
                'cpms_slot_holds',
                'cpms_appointments',
                'cpms_schedule_slots',
                'cpms_schedule_exceptions',
                'cpms_schedule',
                'cpms_patient_user_links',
                'cpms_patients',
                'cpms_clinicians',
                'cpms_settings',
                'cpms_notifications',
                'cpms_sms_messages',
                'cpms_audit_logs',
            ] as $t) {
                $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table($t) . ' WHERE clinic_id = %d', $this->clinicId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d', $this->clinicId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', $this->clinicId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ($this->orgId > 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . $db->table('cpms_organizations') . ' WHERE id = %d', $this->orgId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function purgeJobs(): void
    {
        global $wpdb;
        $wpdb->query(
            'DELETE FROM ' . App::db()->table('cpms_jobs') . ' WHERE type IN ("slots.generate","holds.expire")' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }
}


/** Test-only child wiring: license policy is intentionally outside this lock-order regression. */
final class SchedulingLocationLocalAllowAllLicenseGate implements LicenseGate
{
    public function assert(string $operation, array $context = []): LicenseDecision
    {
        return LicenseDecision::allow();
    }

    public function state(): string
    {
        return 'active';
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}

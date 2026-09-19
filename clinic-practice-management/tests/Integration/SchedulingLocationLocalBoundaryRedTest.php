<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use ClinicCore\Infrastructure\Repository\SlotRepository;
use ClinicCore\Settings\Settings;
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

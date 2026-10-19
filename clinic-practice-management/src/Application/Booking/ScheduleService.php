<?php

declare(strict_types=1);

namespace ClinicCore\Application\Booking;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Logging\OpLogger;
use ClinicCore\Infrastructure\Queue\JobQueue;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\ScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * سرویس مدیریت برنامه هفتگی و استثنائات (F3 — SRS FR-3.1/FR-3.2، Contract G1).
 *
 * دامنه: Technical Config — Capability `cpms_config` (Admin فنی؛ Matrix §4.4).
 * هیچ PHI در ورودی/خروجی نیست.
 *
 * Regeneration (ADR-0004 Consequence):
 *  هر تغییر برنامه/استثنا → Slotهای آینده «خالی» (booked=0, held=0) پزشک حذف و
 *  Job `slots.generate` فوری enqueue می‌شود (بازتولید Idempotent با INSERT IGNORE).
 *  Slotهای دارای رزرو/Hold هرگز حذف نمی‌شوند.
 *
 * LicenseGate: عملیات Config جزو عملیات Protected نیست (Seam فقط برای
 * Booking/Patient/Visit/Invoice — ADR-0023). F10 در صورت نیاز اضافه می‌کند.
 */
final class ScheduleService
{
    private const DAY_TYPES = ['holiday', 'leave', 'blocked', 'open_override'];
    private const FULL_DAY_TYPES = ['holiday', 'leave'];
    private const RANGE_TYPES = ['blocked', 'open_override'];

    public function __construct(
        private readonly CpmsDb $db,
        private readonly ScheduleRepository $schedules,
        private readonly MembershipRepository $memberships,
        private readonly LocationRepository $locations,
        private readonly JobQueue $jobs,
        private readonly AuditLogger $audit,
        private readonly OpLogger $op
    ) {
    }

    // ================= G1 — Weekly Schedule =================

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $clinicianId): array
    {
        // Phase 4 Slice 1: پزشکِ انتخاب‌شده «شیء» است — مشارکتش در Clinicِ
        // معتبرِ درخواست راستی‌آزمایی می‌شود (عضویت فعال پایدار؛ نه Clinicِ
        // خانه) و ردیف‌های برگشتی هم به همان Clinic دامنه‌بندی می‌شوند؛ پس
        // برنامهٔ شعبهٔ دیگرِ همان پزشک افشا نمی‌شود (C7-S2 parity).
        //
        // C7 hardening: بدون Scope صریحِ معتبر این خواندن fail-closed است
        // (CLINIC_NOT_FOUND / 404) — کوئریِ بدون دامنهٔ Clinic هرگز اجرا
        // نمی‌شود (تفصیل در requireClinicianWithinTrustedClinic).
        $trustedClinicId = $this->requireClinicianWithinTrustedClinic($clinicianId);

        $rows = $this->schedules->listByClinicianInClinic($clinicianId, $trustedClinicId);

        return array_map(
            fn (array $r): array => $this->scheduleView($r),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function create(int $actorUserId, array $fields): array
    {
        $actor_user_id = (int) func_get_arg( 0 );
        $clinician_id  = $this->intField( $fields, 'clinician_id' );
        // C7-S5: پزشکِ انتخاب‌شدهٔ کلاینت «شیء» است، نه tenant context —
        // مالکیت او نسبت به Clinic معتبرِ درخواست راستی‌آزمایی می‌شود و کلینیکِ
        // ردیف برنامه هرگز از خودِ ردیف پزشک به‌عنوان اعتماد گرفته نمی‌شود.
        $clinic_id = $this->requireClinicianForTrustedClinic( $clinician_id );

        $day = $this->intField($fields, 'day_of_week');
        if ($day < 0 || $day > 6) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'روز هفته نامعتبر است (0=شنبه … 6=جمعه)', 400, ['errors' => ['day_of_week' => 'range_0_6']]);
        }

        /*
         * Phase 6 Slice 3: Locationِ برنامه حالا ورودیِ صریحِ قرارداد است —
         *   1) الزامی: نبود `location_id` ⇒ fail-closed (400 required) — هیچ
         *      جایگزینی بی‌صدا با Location اصلی/اولی/خانهٔ پزشک نمی‌شود.
         *   2) معتبر در برابر Clinic معتبرِ Scope: real + active + clinic match
         *      در یک پیش‌شرط؛ بیگانه/ناموجود/غیرفعال همه دقیقاً همان پاکتِ
         *      «محل یافت نشد» را می‌گیرند (پاریتِ 404 — عدم شمارش/افشای وجود).
         *      Clinic هرگز از payload یا Locationِ ارسالی گرفته نمی‌شود —
         *      فقط از Scope مورد اعتمادِ درخواست.
         */
        $location_id = $this->intField( $fields, 'location_id' );
        if ( $this->locations->findActiveForClinic( $clinic_id, $location_id ) === null ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'محل یافت نشد', 404 );
        }

        $data = $this->validatedScheduleFields($fields);

        // Phase 6 Slice 7 (concurrency-safe multi-shift conflict enforcement):
        // اتمیسیتی بررسی همپوشانی و درج از طریق تراکنش DB و قفل پایدار ردیف پزشک
        // تضمین می‌شود تا دو درخواست موازی نتوانند وضعیت سلول را همزمان خوانده و هر دو بنویسند.
        $created_id = $this->db->transactional(
            function () use (
                $clinician_id,
                $day,
                $clinic_id,
                $location_id,
                $data
            ): int {
                $this->lock_organization_and_clinic_for_schedule_write( $clinic_id );
                $this->lockClinicianForScheduleWrite( $clinician_id );

                $this->assertNoShiftConflict(
                    $clinician_id,
                    $day,
                    $clinic_id,
                    $location_id,
                    (string) $data['start_time'],
                    (string) $data['end_time'],
                    (int) $data['is_active'],
                    null
                );
                // Recheck lifecycle under the Location row lock; the preflight
                // above cannot prevent a concurrent deactivation from racing this write.
                $this->lock_active_location_for_schedule_write( $clinic_id, $location_id );

                $now_sql = $this->db->nowUtcSql();

                return $this->schedules->create(
                    $data + [
                        'clinic_id'    => $clinic_id,
                        'clinician_id' => $clinician_id,
                        'day_of_week'  => $day,
                        'location_id'  => $location_id,
                        'created_at'   => $now_sql,
                        'updated_at'   => $now_sql,
                    ]
                );
            }
        );

        $view = $this->scheduleView( (array) $this->schedules->find( $created_id ) );
        $this->audit( 'SCHEDULE_CREATED', $actor_user_id, 'schedule', $created_id, null, null, $view );
        $this->op->info(
            'config.schedule_created',
            [
                'schedule_id'  => $created_id,
                'clinician_id' => $clinician_id,
                'clinic_id'    => $clinic_id,
                'location_id'  => $location_id,
                'actor'        => $actor_user_id,
            ]
        );
        // Phase 6 Slice 1: regenerate فقط روی Clinic معتبرِ عملیات (هرگز
        // clinician-only؛ چندعضویتی مشروع ایزوله می‌ماند).
        $this->regenerate( $clinician_id, $clinic_id );

        return $view;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(int $actorUserId, int $id, array $fields): array
    {
        $actor_user_id = (int) func_get_arg( 0 );
        // Phase 6 Slice 7: ترتیبی‌سازی همزمان Create و Update تحت یک قرارداد قفل یکسان.
        $result = $this->db->transactional(
            function () use ( $id, $fields ): array {
				// C7-S2: بررسی اولیه مالکیت و وجود ردیف در دامنه معتبر
				$current      = $this->requireScheduleForTrustedClinic( $id );
				$clinician_id = (int) $current['clinician_id'];
				$clinic_id    = (int) $current['clinic_id'];

				$this->lock_organization_and_clinic_for_schedule_write( $clinic_id );
				$this->lockClinicianForScheduleWrite( $clinician_id );

            // واکشی ردیف تازه و قفل‌شده برای جلوگیری از تداخل بازنویسی همزمان
            $fresh = $this->schedules->findForUpdate($id);
            if ($fresh === null) {
                throw BookingException::of('CLINIC_NOT_FOUND', 'برنامه یافت نشد', 404);
            }

            $data = $this->validatedScheduleFields($fields, (array) $fresh);

				$this->assertNoShiftConflict(
                    $clinician_id,
                    (int) $fresh['day_of_week'],
                    $clinic_id,
                    (int) $fresh['location_id'],
                    (string) $data['start_time'],
                    (string) $data['end_time'],
                    (int) $data['is_active'],
                    $id
				);
				if ( 1 === (int) $data['is_active'] ) {
					// Activating a Schedule is new scheduling demand; it must not
					// beat concurrent Location deactivation via an unlocked read.
					$this->lock_active_location_for_schedule_write(
                        $clinic_id,
                        (int) $fresh['location_id']
					);
				}

            if ($data !== []) {
                $data['updated_at'] = $this->db->nowUtcSql();
                $this->schedules->update($id, $data);
            }

				return [
					'current'      => $current,
					'clinic_id'    => $clinic_id,
					'clinician_id' => $clinician_id,
				];
			}
        );

        $current      = $result['current'];
        $clinic_id    = $result['clinic_id'];
        $clinician_id = $result['clinician_id'];

        $updated = (array) $this->schedules->find( $id );
        $view    = $this->scheduleView( $updated );
        $this->audit( 'SCHEDULE_UPDATED', $actor_user_id, 'schedule', $id, null, $this->scheduleView( $current ), $view );
        $this->op->info(
            'config.schedule_updated',
            [
                'schedule_id' => $id,
                'clinic_id'   => $clinic_id,
                'actor'       => $actor_user_id,
            ]
        );
        $this->regenerate( $clinician_id, $clinic_id );

        return $view;
    }

    /**
     * Lock the Organization -> Clinic prefix shared by lifecycle, scheduling,
     * slot-generation, and booking writers before schedule/Location row locks.
     */
    private function lock_organization_and_clinic_for_schedule_write( int $clinic_id ): void {
        if ( $clinic_id <= 0 ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'کلینیک یافت نشد', 404 );
        }

        $candidate       = $this->db->fetchRow(
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $clinic_id ]
        );
        $organization_id = (int) ( $candidate['organization_id'] ?? 0 );
        if ( $candidate === null || $organization_id <= 0 ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'کلینیک یافت نشد', 404 );
        }

        $organization = $this->db->fetchRowForUpdate(
            'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
            [ $organization_id ]
        );
        if (
            $organization === null
            || (int) ( $organization['id'] ?? 0 ) !== $organization_id
            || (string) ( $organization['status'] ?? '' ) !== 'active'
        ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'کلینیک یافت نشد', 404 );
        }

        $clinic = $this->db->fetchRowForUpdate(
            'SELECT id, organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ $clinic_id ]
        );
        if (
            $clinic === null
            || (int) ( $clinic['id'] ?? 0 ) !== $clinic_id
            || (int) ( $clinic['organization_id'] ?? 0 ) !== $organization_id
        ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'کلینیک یافت نشد', 404 );
        }
    }

    /**
     * قفل پایدار ردیف پزشک در تراکنش برای ترتیبی‌سازی جهش‌های برنامه.
     */
    private function lockClinicianForScheduleWrite(int $clinicianId): void
    {
        $locked = $this->db->fetchRowForUpdate(
            "SELECT id FROM " . $this->db->table("cpms_clinicians") . " WHERE id = %d LIMIT 1",
            [$clinicianId]
        );
        if ($locked === null) {
            throw BookingException::of("CLINIC_NOT_FOUND", "پزشک یافت نشد", 404);
        }
    }

    /**
     * Recheck and serialize active-Location schedule writes against lifecycle
     * changes. Call after the Organization/Clinic and Clinician/Schedule locks,
     * matching slot generation's prefix and avoiding a Location/Schedule inversion.
     */
    private function lock_active_location_for_schedule_write( int $clinic_id, int $location_id ): void {
        if ( $clinic_id <= 0 || $location_id <= 0 ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'محل یافت نشد', 404 );
        }

        $location = $this->db->fetchRowForUpdate(
            'SELECT id, clinic_id, is_active FROM ' . $this->db->table( 'cpms_locations' ) . ' WHERE id = %d LIMIT 1',
            [ $location_id ]
        );
        if (
            $location === null
            || (int) $location['clinic_id'] !== $clinic_id
            || (int) ( $location['is_active'] ?? 0 ) !== 1
        ) {
            throw BookingException::of( 'CLINIC_NOT_FOUND', 'محل یافت نشد', 404 );
        }
    }

    public function delete(int $actorUserId, int $id): array
    {
        $actor_user_id = (int) func_get_arg( 0 );
        // C7-S2: ownership preflight, then revalidate the persisted row while
        // holding the shared Organization -> Clinic -> Clinician -> Schedule order.
        $candidate    = $this->requireScheduleForTrustedClinic( $id );
        $clinic_id    = (int) $candidate['clinic_id'];
        $clinician_id = (int) $candidate['clinician_id'];
        $current      = $this->db->transactional(
            function () use (
                $id,
                $clinic_id,
                $clinician_id
            ): array {
                $this->lock_organization_and_clinic_for_schedule_write( $clinic_id );
                $this->lockClinicianForScheduleWrite( $clinician_id );
                $fresh = $this->schedules->findForUpdate( $id );
                if (
                    $fresh === null
                    || (int) ( $fresh['clinic_id'] ?? 0 ) !== $clinic_id
                    || (int) ( $fresh['clinician_id'] ?? 0 ) !== $clinician_id
                ) {
                    throw BookingException::of( 'CLINIC_NOT_FOUND', 'برنامه یافت نشد', 404 );
                }
                if ( 1 !== $this->schedules->delete( $id ) ) {
                    throw BookingException::of( 'CLINIC_NOT_FOUND', 'برنامه یافت نشد', 404 );
                }

                return $fresh;
            }
        );

        $this->audit( 'SCHEDULE_DELETED', $actor_user_id, 'schedule', $id, null, $this->scheduleView( $current ), null );
        $this->op->info(
            'config.schedule_deleted',
            [
                'schedule_id' => $id,
                'clinic_id'   => $clinic_id,
                'actor'       => $actor_user_id,
            ]
        );
        $this->regenerate( $clinician_id, $clinic_id );

        return [ 'id' => $id, 'deleted' => true ];
    }

    // ================= Impact / Preview (Chunk D — بدون invalidate بی‌صدا) =================

    /**
     * گزارش «تأثیر تغییر برنامه» برای یک پزشک — قبل از اینکه تغییری اعمال شود به مدیر
     * گفته می‌شود:
     *  - چند Slot خالی آینده (booked=0, held=0) حذف و بازتولید خواهند شد؛
     *  - چند Slot دارای رزرو/Hold «محافظت» می‌شوند و هرگز حذف نمی‌شوند (data snapshot).
     *
     * Phase 6 Slice 1: شمارش‌ها Clinic-scoped هستند — Slotهای Clinic دیگرِ
     * همان پزشک (مشارکت مشروع چندعضویتی) در اعداد Clinic جاری ظاهر نمی‌شوند.
     * Clinic معتبر از Scope صریحِ درخواست به‌دست می‌آید؛ بدون Scope صریح،
     * fail-closed (هیچ fallback به clinic_id پروفایل یا حدس از ردیف اول).
     *
     * @return array{future_empty_slots:int, future_reserved_slots:int}
     */
    public function impact(int $clinicianId): array
    {
        // Clinic معتبرِ درخواست — fail-closed اگر Scope صریح برقرار نباشد.
        $clinicId = $this->requireClinicianForTrustedClinic($clinicianId);

        // Phase 6 Slice 2: مرز «آینده» برای هر cohort مکانی، «امروزِ محلیِ
        // همان Location» است (slot_date مقدار wall-clock محلیِ Location است) —
        // نه gmdate سرور. cohortهای با Location نامعتبر (fail-closed) در
        // شمارش «آینده» نمی‌آیند: regeneration نیز به آن‌ها دست نمی‌زند و
        // impact باید دقیقاً همان چیزی را پیش‌نمایش کند که regenerate انجام می‌دهد.
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $empty = 0;
        $reserved = 0;
        foreach ($this->schedules->distinctClinicianSlotLocationIds($clinicianId, $clinicId) as $locationId) {
            $tz = $this->resolveLocationTimezoneSoft($locationId, $clinicId);
            if ($tz === null) {
                continue; // fail-closed — هرگز با سرور/کلینیک حدس نمی‌زنیم
            }
            $localToday = $nowUtc->setTimezone($tz)->format('Y-m-d');
            $empty += $this->schedules->countFutureEmptySlots($clinicianId, $clinicId, $locationId, $localToday);
            $reserved += $this->schedules->countFutureReservedSlots($clinicianId, $clinicId, $locationId, $localToday);
        }

        return [
            'future_empty_slots' => $empty,
            'future_reserved_slots' => $reserved,
        ];
    }

    // ================= G1b — Schedule Exceptions (SRS FR-3.2) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function listExceptions(int $clinicianId, string $fromDate, string $toDate): array
    {
        // Phase 4 Slice 1: همان معیار مشارکت + دامنهٔ Clinic معتبر برای استثناها
        // (تعطیلی یک شعبه، شعبهٔ دیگرِ همان پزشک را افشا/نمی‌بندد).
        //
        // C7 hardening: بدون Scope صریحِ معتبر این خواندن fail-closed است
        // (CLINIC_NOT_FOUND / 404) — کوئریِ بدون دامنهٔ Clinic هرگز اجرا
        // نمی‌شود (تفصیل در requireClinicianWithinTrustedClinic).
        $trustedClinicId = $this->requireClinicianWithinTrustedClinic($clinicianId);
        $from = $this->parseYmd($fromDate, 'from');
        $to = $this->parseYmd($toDate, 'to');
        if ($to < $from) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'بازه تاریخ نامعتبر است');
        }

        $rows = $this->schedules->listExceptionsInClinic($clinicianId, $trustedClinicId, $from, $to);

        return array_map(
            fn (array $r): array => $this->exceptionView($r),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function createException(int $actorUserId, array $fields): array
    {
        $actor_user_id = (int) func_get_arg( 0 );
        $clinician_id  = $this->intField( $fields, 'clinician_id' );
        // C7-S6: همان قاعدهٔ مالکیت create (C7-S5) — پزشکِ انتخاب‌شدهٔ کلاینت
        // «شیء» است؛ مالکیتش نسبت به Clinic معتبرِ درخواست راستی‌آزمایی می‌شود و
        // کلینیکِ ردیف استثنا هرگز از خودِ ردیف پزشک به‌عنوان اعتماد گرفته نمی‌شود.
        $clinic_id = $this->requireClinicianForTrustedClinic( $clinician_id );

        /*
         * Phase 6 Slice 6: Location اختیاریِ استثنا — قرارداد از قبل‌موجودِ
         * Migration 0015 (ستون nullable + FK `fk_schedexc_location`):
         *   NULL ⇒ استثنا برای همهٔ Locationهای همین Clinic معتبر است
         *          (تعطیلی رسمی سراسری؛ رفتار امروزِ اپراتورها و همهٔ ردیف‌های
         *          تاریخی حفظ می‌شود — بدون Backfill).
         *   مقدار ⇒ استثنا فقط به همان Location گره می‌خورد؛ باید واقعی، فعال
         *          و متعلق به Clinic معتبرِ Scope باشد. بیگانه/ناموجود/غیرفعال
         *          همه دقیقاً همان پاکتِ 404 «محل یافت نشد» را می‌گیرند (پاریتِ
         *          not-found — عدم افشای وجود/شمارش) و هیچ ردیفی نوشته نمی‌شود؛
         *          هیچ جایگزینی بی‌صدا با Location اصلی/اولی/خانه انجام نمی‌شود.
         * این بررسی پیش از اعتبارسنجیِ فیلدهاست چون selector مکانی نیز بخشی از
         * مرز tenant است (مانند پزشک): پاسخ برای هر payload یکسان و غیرافشاگر
         * می‌ماند.
         */
        $location_id = $this->optionalLocationIdForTrustedClinic( $fields, $clinic_id );

        $date = $this->parseYmd((string) ($fields['date'] ?? ''), 'date');
        if ($date < gmdate('Y-m-d')) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'تاریخ استثنا باید امروز یا آینده باشد', 400, ['errors' => ['date' => 'past_date']]);
        }

        $type = (string) ($fields['type'] ?? '');
        if (!in_array($type, self::DAY_TYPES, true)) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'نوع استثنا نامعتبر است', 400, ['errors' => ['type' => 'invalid_type']]);
        }

        $start_time = isset( $fields['start_time'] ) && $fields['start_time'] !== ''
            ? $this->parseTime( (string) $fields['start_time'], 'start_time' )
            : null;
        $end_time   = isset( $fields['end_time'] ) && $fields['end_time'] !== ''
            ? $this->parseTime( (string) $fields['end_time'], 'end_time' )
            : null;

        if (in_array($type, self::RANGE_TYPES, true)) {
            // blocked / open_override: بازه الزامی
            if ( $start_time === null || $end_time === null ) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'برای این نوع استثنا، ساعت شروع و پایان الزامی است', 400, ['errors' => ['start_time,end_time' => 'required_for_range_type']]);
            }
            if ( $end_time <= $start_time ) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ساعت پایان باید بعد از شروع باشد', 400, ['errors' => ['end_time' => 'must_be_after_start']]);
            }
        } else {
            // holiday / leave: کل روز — بازه معنا ندارد
            $start_time = null;
            $end_time   = null;
        }

        $reason = isset($fields['reason']) && is_string($fields['reason']) && $fields['reason'] !== ''
            ? mb_substr($fields['reason'], 0, 255)
            : null;

        $id = $this->db->transactional(
            function () use (
                $clinic_id,
                $location_id,
                $clinician_id,
                $date,
                $type,
                $start_time,
                $end_time,
                $reason,
                $actor_user_id
            ): int {
                $this->lock_organization_and_clinic_for_schedule_write( $clinic_id );
                if ( $location_id !== null ) {
                    $this->lock_active_location_for_schedule_write( $clinic_id, $location_id );
                }

                return $this->schedules->createException(
                    [
                        'clinic_id'             => $clinic_id,
                        'location_id'           => $location_id,
                        'clinician_id'          => $clinician_id,
                        'date'                  => $date,
                        'type'                  => $type,
                        'start_time'            => $start_time,
                        'end_time'              => $end_time,
                        'reason'                => $reason,
                        'created_by_wp_user_id' => $actor_user_id,
                        'created_at'            => $this->db->nowUtcSql(),
                    ]
                );
            }
        );

        $view = $this->exceptionView( (array) $this->schedules->findException( $id ) );
        $this->audit( 'SCHEDULE_EXCEPTION_CREATED', $actor_user_id, 'schedule_exception', $id, null, null, $view );
        $this->op->info(
            'config.schedule_exception_created',
            [
                'exception_id' => $id,
                'clinician_id' => $clinician_id,
                'clinic_id'    => $clinic_id,
                'location_id'  => $location_id,
                'actor'        => $actor_user_id,
            ]
        );
        // Phase 6 Slice 1: regenerate فقط روی Clinic معتبرِ عملیات.
        $this->regenerate( $clinician_id, $clinic_id );

        return $view;
    }

    /**
     * @return array{id: int, deleted: true}
     */
    public function deleteException(int $actorUserId, int $id): array
    {
        $actor_user_id = (int) func_get_arg( 0 );
        // C7-S2: ownership preflight, then revalidate under the lifecycle prefix.
        // No Location lock is needed: deleting a policy row creates no demand and
        // remains allowed after Location deactivation.
        $candidate    = $this->requireExceptionForTrustedClinic( $id );
        $clinic_id    = (int) $candidate['clinic_id'];
        $clinician_id = (int) $candidate['clinician_id'];
        $current      = $this->db->transactional(
            function () use (
                $id,
                $clinic_id,
                $clinician_id
            ): array {
                $this->lock_organization_and_clinic_for_schedule_write( $clinic_id );
                $this->lockClinicianForScheduleWrite( $clinician_id );
                $fresh = $this->db->fetchRowForUpdate(
                    'SELECT * FROM ' . $this->db->table( 'cpms_schedule_exceptions' ) . ' WHERE id = %d LIMIT 1',
                    [ $id ]
                );
                if (
                    $fresh === null
                    || (int) ( $fresh['clinic_id'] ?? 0 ) !== $clinic_id
                    || (int) ( $fresh['clinician_id'] ?? 0 ) !== $clinician_id
                ) {
                    throw BookingException::of( 'CLINIC_NOT_FOUND', 'استثنای برنامه یافت نشد', 404 );
                }
                if ( 1 !== $this->schedules->deleteException( $id ) ) {
                    throw BookingException::of( 'CLINIC_NOT_FOUND', 'استثنای برنامه یافت نشد', 404 );
                }

                return $fresh;
            }
        );

        $this->audit( 'SCHEDULE_EXCEPTION_DELETED', $actor_user_id, 'schedule_exception', $id, null, $this->exceptionView( $current ), null );
        $this->op->info(
            'config.schedule_exception_deleted',
            [
                'exception_id' => $id,
                'clinic_id'    => $clinic_id,
                'actor'        => $actor_user_id,
            ]
        );
        $this->regenerate( $clinician_id, $clinic_id );

        return [ 'id' => $id, 'deleted' => true ];
    }

    // ================= Internal =================

    /**
     * Phase 6 Slice 4 — گارد multi-shift برای یک محدودهٔ
     * (Clinic، Location، پزشک، روز هفته): تکرارِ دقیقِ start_time (در هر وضعیت
     * فعالی — کلید یکتای `u_sched_slot` مؤلفهٔ is_active ندارد و هر چیز دیگری
     * خطای خامِ DB را نشت می‌داد) همچنان پاکتِ پایدارِ `duplicate_schedule_day`
     * را می‌گیرد؛ وقتی ردیفِ حاصل ACTIVE است، هر تقاطعِ پنجرهٔ زمانی با یک
     * ردیفِ ACTIVE دیگر با دلیلِ متمایزِ `overlapping_shift` رد می‌شود. مرزهای
     * مماس (end == start) همپوشانی نیستند ([s1,e1) در برابر [s2,e2):
     * s1 < e2 و s2 < e1). وقفه داخلِ شیفت است و هرگز در تشخیص شرکت نمی‌کند.
     * ناوردا: هیچ دو ردیفِ ACTIVEای همپوشانی ندارند.
     */
    private function assertNoShiftConflict(
        int $clinicianId,
        int $dayOfWeek,
        int $clinicId,
        int $locationId,
        string $startTime,
        string $endTime,
        int $isActive,
        ?int $excludeId
    ): void {
        $rows = $this->schedules->listByClinicianDayInClinicAndLocationForUpdate($clinicianId, $dayOfWeek, $clinicId, $locationId);
        $newStart = self::shiftToSeconds(substr($startTime, 0, 8));
        $newEnd = self::shiftToSeconds(substr($endTime, 0, 8));
        foreach ($rows as $row) {
            if ($excludeId !== null && (int) $row['id'] === $excludeId) {
                continue;
            }
            $rowStart = substr((string) $row['start_time'], 0, 8);
            if ($rowStart === substr($startTime, 0, 8)) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'برای این روز هفته قبلاً برنامه ثبت شده — از ویرایش استفاده کنید', 400, ['errors' => ['day_of_week' => 'duplicate_schedule_day']]);
            }
            if ($isActive === 1 && (int) $row['is_active'] === 1
                && $newStart < self::shiftToSeconds(substr((string) $row['end_time'], 0, 8))
                && self::shiftToSeconds($rowStart) < $newEnd) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'این شیفت با شیفت فعال دیگری در همین روز و محل همپوشانی دارد', 400, ['errors' => ['start_time,end_time' => 'overlapping_shift']]);
            }
        }
    }

    /**
     * تبدیل "HH:MM[:SS]" به ثانیه از نیمه‌شب (ورودیِ بالادست اعتبارسنجی‌شده است).
     */
    private static function shiftToSeconds(string $hms): int
    {
        $parts = array_pad(explode(':', $hms), 3, '0');

        return ((int) $parts[0]) * 3600 + ((int) $parts[1]) * 60 + ((int) $parts[2]);
    }

    /**
     * اعتبارسنجی فیلدهای برنامه (Create/Update مشترک) — Merge روی مقادیر فعلی.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function validatedScheduleFields(array $fields, ?array $current = null): array
    {
        $out = [];

        $start = isset($fields['start_time']) && $fields['start_time'] !== ''
            ? $this->parseTime((string) $fields['start_time'], 'start_time')
            : ($current['start_time'] ?? null);
        $end = isset($fields['end_time']) && $fields['end_time'] !== ''
            ? $this->parseTime((string) $fields['end_time'], 'end_time')
            : ($current['end_time'] ?? null);
        $start = $start !== null ? substr((string) $start, 0, 8) : null;
        $end = $end !== null ? substr((string) $end, 0, 8) : null;

        if ($start === null || $end === null) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ساعت شروع و پایان برنامه الزامی است', 400, ['errors' => ['start_time,end_time' => 'required']]);
        }
        if ($end <= $start) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ساعت پایان باید بعد از شروع باشد', 400, ['errors' => ['end_time' => 'must_be_after_start']]);
        }

        $breakStart = isset($fields['break_start']) && $fields['break_start'] !== ''
            ? $this->parseTime((string) $fields['break_start'], 'break_start')
            : ($current['break_start'] ?? null);
        $breakEnd = isset($fields['break_end']) && $fields['break_end'] !== ''
            ? $this->parseTime((string) $fields['break_end'], 'break_end')
            : ($current['break_end'] ?? null);
        $breakStart = $breakStart !== null ? substr((string) $breakStart, 0, 8) : null;
        $breakEnd = $breakEnd !== null ? substr((string) $breakEnd, 0, 8) : null;
        if (($breakStart === null) !== ($breakEnd === null)) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'استراحت باید هم شروع و هم پایان داشته باشد (یا هیچ‌کدام)', 400, ['errors' => ['break_start,break_end' => 'pair_required']]);
        }
        if ($breakStart !== null && $breakEnd !== null) {
            if ($breakEnd <= $breakStart || $breakStart < $start || $breakEnd > $end) {
                throw BookingException::of('CLINIC_VALIDATION_FAILED', 'بازه استراحت باید داخل ساعات کاری باشد', 400, ['errors' => ['break_start,break_end' => 'outside_work_hours']]);
            }
        }

        $duration = array_key_exists('appointment_duration_min', $fields)
            ? (int) $fields['appointment_duration_min']
            : (isset($current['appointment_duration_min']) ? (int) $current['appointment_duration_min'] : 20);
        if ($duration < 5 || $duration > 240) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'مدت نوبت باید بین ۵ تا ۲۴۰ دقیقه باشد', 400, ['errors' => ['appointment_duration_min' => 'range_5_240']]);
        }

        $capacity = array_key_exists('slot_capacity', $fields)
            ? (int) $fields['slot_capacity']
            : (isset($current['slot_capacity']) ? (int) $current['slot_capacity'] : 1);
        if ($capacity < 1 || $capacity > 50) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', 'ظرفیت اسلات باید بین ۱ تا ۵۰ باشد', 400, ['errors' => ['slot_capacity' => 'range_1_50']]);
        }

        $isActive = array_key_exists('is_active', $fields)
            ? (int) (bool) $fields['is_active']
            : (isset($current['is_active']) ? (int) $current['is_active'] : 1);

        $out['start_time'] = $start;
        $out['end_time'] = $end;
        $out['break_start'] = $breakStart;
        $out['break_end'] = $breakEnd;
        $out['appointment_duration_min'] = $duration;
        $out['slot_capacity'] = $capacity;
        $out['is_active'] = $isActive;

        return $out;
    }

    /**
     * Phase 6 Slice 1: Regeneration — حذف Slotهای آینده خالی فقط در Clinic
     * معتبرِ عملیات + Job فوری تولید.
     *
     * قاعدهٔ کلیدی (امنیتی/tenant):
     *  - $clinicId الزاماً از یک Clinic معتبرِ ازپیش‌اعتبارسنجی‌شده می‌آید
     *    (Scope درخواست یا ردیف پایدار شیء)؛ هرگز از payload، از
     *    clinician.clinic_id یا از «اولین ردیف» استنتاج نمی‌شود.
     *  - Slotهای خالی/محافظت‌شدهٔ Clinic دیگرِ همان پزشک دست‌نخورده می‌مانند.
     */
    private function regenerate(int $clinicianId, int $clinicId): void
    {
        try {
            // Phase 6 Slice 2: مرز حذف برای هر cohort مکانی «امروزِ محلیِ خودِ
            // آن Location» است. cohortهای با Location نامعتبر/بیگانه هرگز
            // دست‌نخورده می‌مانند (fail-closed؛ تولید مجدد نیز مبتنی بر تقویم
            // محلی در SlotsGenerateHandler انجام می‌شود و این دو سازگارند).
            $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $removed = 0;
            foreach ($this->schedules->distinctClinicianSlotLocationIds($clinicianId, $clinicId) as $locationId) {
                $tz = $this->resolveLocationTimezoneSoft($locationId, $clinicId);
                if ($tz === null) {
                    continue; // fail-closed — بدون Location قابل‌اعتماد، حذف نمی‌کنیم
                }
                $localToday = $nowUtc->setTimezone($tz)->format('Y-m-d');
                $removed += $this->schedules->deleteFutureEmptySlots($clinicianId, $clinicId, $locationId, $localToday);
            }
            $this->jobs->enqueue(
                'slots.generate',
                ['source' => 'manual'], // ENUM generated_from: lazy|cron|manual
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                priority: 3
            );
            if ($removed > 0) {
                $this->op->info('config.schedule_regenerated', ['clinician_id' => $clinicianId, 'clinic_id' => $clinicId, 'removed_empty_slots' => $removed]);
            }
        } catch (\Throwable $e) {
            // Regeneration هرگز نباید تغییر Config را شکست بدهد — Job روزانه/Cron خودش جبران می‌کند
            $this->op->warning('config.schedule_regen_failed', ['clinician_id' => $clinicianId, 'clinic_id' => $clinicId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Phase 6 Slice 2: تفکیک Location به timezone معتبر برای cohortبندی —
     * fail-closed با null (Location نامفقود، متعلق به Clinic دیگر، یا
     * timezone نامعتبر). الگوی مشابه BookingService::resolveLocationTimezone؛
     * اینجا به‌جای throw مقدار null برمی‌گردانیم چون مسیرهای caller
     * (impact/regenerate) باید cohort نامعتبر را «دست‌نخورده» رها کنند تا
     * هیچ تصمیم زمانی بر مبنای حدس گرفته نشود.
     */
    private function resolveLocationTimezoneSoft(int $locationId, int $clinicId): ?DateTimeZone
    {
        if ($locationId <= 0) {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT id, clinic_id, timezone FROM ' . $this->db->table('cpms_locations') . ' WHERE id = %d LIMIT 1',
            [$locationId]
        );
        if ($row === null || (int) $row['clinic_id'] !== $clinicId) {
            return null;
        }
        try {
            return new DateTimeZone(trim((string) ($row['timezone'] ?? '')));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * C7-S5 + Phase 4 Slice 1 — پزشک به‌عنوان «شیء» راستی‌آزمایی می‌شود، نه
     * منبع Clinic معتبر.
     *
     * ترتیب الزامی: Clinic معتبرِ درخواست ← راستی‌آزمایی مشارکتِ پایدار پزشک در
     * همان Clinic ← فقط سپس اعتبارسنجی فیلدها/درج برنامه/بازتولید Slot:
     *   - بدون Scope صریح معتبر ⇒ بسته (CLINIC_SCOPE_REQUIRED — همان
     *     معناشناسی کانونی fail-closed سرویس‌های حساس).
     *   - مشارکت پزشک در Clinic معتبر = عضویتِ فعالِ پایدارِ کاربرِ همان پروفایل
     *     (`cpms_clinic_memberships` — SoT؛ مدل هدف د-۶-۳) + مسیر سازگاریِ
     *     Clinicِ خانهٔ پروفایل؛ بدون Scope صریح و بدون هیچ مقدارِ payload.
     *   - نبود/تعلیق عضویت در Clinic معتبر ⇒ دقیقاً همان پاکتِ «پزشک یافت نشد»
     *     (عدم شمارش/افشای وجود پزشک خارجی)؛ بدون درج ردیف و بدون regenerate.
     * Clinicِ خانهٔ ردیف پزشک فقط مسیر سازگاری است، نه مرز مشارکت/مالکیت.
     */
    private function requireClinicianForTrustedClinic(int $clinicianId): int
    {
        $scope = ScopeContext::tryGet();
        if ($scope === null) {
            throw BookingException::of('CLINIC_SCOPE_REQUIRED', 'عملیات برنامهٔ هفتگی بدون زمینهٔ کلینیک معتبر مجاز نیست', 400);
        }

        $trustedClinicId = (int) $scope->clinicId;
        if (!$this->memberships->clinician_participates_in($clinicianId, $trustedClinicId)) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد', 404);
        }

        return $trustedClinicId;
    }

    /**
     * Phase 3 Slice 6B + Phase 4 Slice 1 + C7 hardening — مشارکت پایدار پزشک در
     * برابر Clinic معتبرِ صریح (خواندن).
     *
     * برای مسیرهای خواندن (list/listExceptions): با Scope صریحِ معتبر (مرز REST
     * staff / wp-admin پس از C7-S4) پزشکی که در آن Clinic مشارکت پایدار فعال
     * ندارد بارگذاری نمی‌شود و همان پاکتِ «پزشک یافت نشد» را می‌گیرد (عدم
     * شمارش)؛ فراخوان‌های بعدی هم به همان Clinic دامنه‌بندی می‌شوند تا ردیفِ
     * شعبهٔ دیگرِ همان پزشک افشا نشود.
     *
     * C7 hardening: بدون Scope صریحِ معتبر این خواندن‌ها fail-closed هستند —
     * دقیقاً همان پاکتِ پایدارِ «پزشک یافت نشد» (CLINIC_NOT_FOUND / 404)
     * برگردانده می‌شود و هرگز کوئریِ بدون دامنهٔ Clinic اجرا نمی‌شود. برخلافِ
     * مسیر سازگاریِ پیشین، `clinicians.clinic_id` اینجا هرگز به‌عنوان مرجع
     * tenant به‌کار نمی‌رود. حسابرسیِ فراخوان: تولیدِ REST همیشه Scope صریح
     * برقرار می‌کند (RestClinicContext ← TrustedClinicEstablisher، در غیر این
     * صورت 403 پیش از سرویس) و ClinicianAdminPage::render نیز پیش از هر
     * خواندن Scope برقرار می‌کند (در غیر این صورت پوستهٔ Scope-الزامی)، پس
     * فراخوانِ مشروعِ scoped تغییری نمی‌بیند و شکستِ Scope/کوئری همچنان
     * fail-closed می‌ماند. مسیرهای نوشتن موجودیت (requireSchedule/
     * requireExceptionForTrustedClinic) در این برش دست‌نخورده‌اند.
     *
     * @return int Clinicِ معتبرِ درخواست؛ بدون Scope صریح ⇒ استثنا (fail-closed)
     */
    private function requireClinicianWithinTrustedClinic(int $clinicianId): int
    {
        $trustedClinicId = $this->explicitTrustedClinicId();
        if ($trustedClinicId === null) {
            // بدون Scope صریح معتبر: خواندنِ برنامه/استثنا fail-closed است (C7).
            throw BookingException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد', 404);
        }

        if (!$this->memberships->clinician_participates_in($clinicianId, $trustedClinicId)) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد', 404);
        }

        return $trustedClinicId;
    }

    /**
     * Clinic صریحِ مورد اعتمادِ درخواست — اگر مرز حمل (REST) برقرارشده باشد؛
     * بدون fallback به Resolution سیستمیِ «تنها Clinic».
     *
     * C7-S2: شناسهٔ شیءِ ارسالیِ کلاینت (schedule/exception id) هرگز خودش
     * tenant context نیست؛ Clinicِ ردیف فقط «شاهد مالکیت برای مقایسه» است،
     * نه منبع اعتماد. مرز REST برای staff همیشه Scope صریح برقرار می‌کند
     * (RestClinicContext ← TrustedClinicEstablisher: هدر درخواست یا عضویت
     * فعالِ یکتا)، پس مسیر تولیدیِ RESTِ این متدها همیشه تحت این دامنه است.
     *
     * چرا Resolution سیستمی اینجا صریحاً fallback نمی‌شود: خواندن‌های
     * list/listExceptions در برش سخت‌گیرسازیِ C7 حاضر fail-closed شدند
     * (requireClinicianWithinTrustedClinic؛ Scope صحیح پیش‌شرط است و
     * فراخوان‌های تولیدی — مرز REST و render ادمین — پیش از سرویس Scope
     * برقرار می‌کنند). مسیر سازگاریِ null-scope اکنون فقط برای نوشتن‌های
     * موجودیت (requireSchedule/requireExceptionForTrustedClinic) باقی است که
     * فراخوانِ تولیدیِ wp-admin آن‌ها (ClinicianAdminPage::saveSchedules/
     * deleteSchedule/deleteException — گارد cpms_config + nonce) بدون Scope
     * صریح کار می‌کند و شکستن آن در این برش، رگرسیون عملکردیِ خارج از سه نقصِ
     * اثبات‌شده است. سخت‌گیرسازیِ آن مسیرهای نوشتن به برش بعدی C7 سپرده
     * می‌شود — همان‌طور که برای Finance با تست no-scope مشخصه‌نگاری شد.
     */
    private function explicitTrustedClinicId(): ?int
    {
        $scope = ScopeContext::tryGet();

        return $scope === null ? null : (int) $scope->clinicId;
    }

    /**
     * C7-S2: برنامهٔ هفتگی بر پایهٔ شناسهٔ ورودی — با Scope صریحِ معتبر، واکشی
     * دامنه‌بندی‌شده به همان Clinic انجام می‌شود؛ ردیفِ کلینیک دیگر اصلاً
     * بارگذاری نمی‌شود و پاسخ، پاکتِ بایت‌به‌بایت یکسان با «برنامه یافت نشد»
     * است (عدم شمارش). بدون Scope صریح (فراخوان داخلی/wp-admin)، رفتار
     * موجود حفظ می‌شود.
     *
     * @return array<string, mixed>
     */
    private function requireScheduleForTrustedClinic(int $id): array
    {
        $trustedClinicId = $this->explicitTrustedClinicId();
        $current = $trustedClinicId === null
            ? $this->schedules->find($id)
            : $this->schedules->findForClinic($id, $trustedClinicId);
        if ($current === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'برنامه یافت نشد', 404);
        }

        return $current;
    }

    /**
     * Phase 6 Slice 6: Location اختیاریِ استثنای برنامه، دامنه‌بندی‌شده به Clinic
     * معتبر — تنها منبع معنا: قرارداد Migration 0015.
     *
     *  - نبودِ فیلد/مقدار تهی ⇒ `null` (همهٔ Locationهای همین Clinic معتبر).
     *  - مقدارِ ارائه‌شده (حتی ناسالم/غیرعددی) ⇒ باید با
     *    `LocationRepository::findActiveForClinic` (واقعی + فعال + مالکیت همان
     *    Clinic) تأیید شود؛ در غیر این صورت پاکتِ پایدار `CLINIC_NOT_FOUND`
     *    با 404 — بدون افشای وجود، بدون شمارش، بدون جایگزینی بی‌صدا.
     * Clinic هرگز از payload گرفته نمی‌شود؛ فقط از Scope مورد اعتماد.
     *
     * @param array<string, mixed> $fields
     */
    private function optionalLocationIdForTrustedClinic(array $fields, int $clinicId): ?int
    {
        if (!array_key_exists('location_id', $fields)) {
            return null;
        }

        $raw = $fields['location_id'];
        if ($raw === null || $raw === '') {
            return null;
        }

        $locationId = is_numeric($raw) ? (int) $raw : 0;
        if ($this->locations->findActiveForClinic($clinicId, $locationId) === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'محل یافت نشد', 404);
        }

        return $locationId;
    }

    /**
     * C7-S2: همان قرارداد مالکیت برای استثنای برنامه (404 parity).
     *
     * @return array<string, mixed>
     */
    private function requireExceptionForTrustedClinic(int $id): array
    {
        $trustedClinicId = $this->explicitTrustedClinicId();
        $current = $trustedClinicId === null
            ? $this->schedules->findException($id)
            : $this->schedules->findExceptionForClinic($id, $trustedClinicId);
        if ($current === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'استثنا یافت نشد', 404);
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function scheduleView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'clinician_id' => (int) $row['clinician_id'],
            'location_id' => (int) ($row['location_id'] ?? 0),
            'day_of_week' => (int) $row['day_of_week'],
            'start_time' => substr((string) $row['start_time'], 0, 5),
            'end_time' => substr((string) $row['end_time'], 0, 5),
            'break_start' => $row['break_start'] !== null ? substr((string) $row['break_start'], 0, 5) : null,
            'break_end' => $row['break_end'] !== null ? substr((string) $row['break_end'], 0, 5) : null,
            'appointment_duration_min' => (int) $row['appointment_duration_min'],
            'slot_capacity' => (int) $row['slot_capacity'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function exceptionView(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'clinician_id' => (int) $row['clinician_id'],
            // Phase 6 Slice 6: مقدار ذخیره‌شدهٔ Location — null یعنی استثنا برای
            // همهٔ Locationهای Clinic معتبر است (قرارداد 0015) و عدد یعنی فقط
            // همان Location. بدون این فیلد، فراخوان نمی‌تواند این دو را تفکیک کند.
            'location_id' => isset($row['location_id']) && $row['location_id'] !== null
                ? (int) $row['location_id']
                : null,
            'date' => (string) $row['date'],
            'type' => (string) $row['type'],
            'start_time' => $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 5) : null,
            'end_time' => $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 5) : null,
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
        ];
    }

    private function parseYmd(string $value, string $label): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value) !== 1) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} باید به فرمت YYYY-MM-DD باشد");
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value, new DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} نامعتبر است");
        }

        return $value;
    }

    private function parseTime(string $value, string $label): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value) !== 1) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "{$label} باید به فرمت HH:MM باشد", 400, ['errors' => [$label => 'invalid_time']]);
        }
        $parts = explode(':', $value);

        return sprintf('%02d:%02d:00', (int) $parts[0], (int) $parts[1]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function intField(array $fields, string $key): int
    {
        $value = $fields[$key] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw BookingException::of('CLINIC_VALIDATION_FAILED', "فیلد {$key} الزامی است", 400, ['errors' => [$key => 'required']]);
        }

        return (int) $value;
    }

    private function audit(
        string $action,
        int $wpUserId,
        string $resourceType,
        int $resourceId,
        ?int $patientId,
        ?array $before,
        ?array $after
    ): void {
        $this->audit->log(
            $action,
            ['wp_user_id' => $wpUserId, 'role' => 'staff'],
            $resourceType,
            $resourceId,
            $patientId,
            $before,
            $after
        );
    }
}

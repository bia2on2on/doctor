<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Package D (patient portal) — مهلتِ لغو/جابه‌جایی در رابط + هم‌خوانیِ
 * «گزینه‌های جابه‌جایی» با «ثبتِ جابه‌جایی».
 *
 * قرارداد:
 *  1. دکمهٔ لغو فقط تا پیش از `booking.cancel_deadline_hours` و دکمهٔ جابه‌جایی
 *     فقط تا پیش از `booking.reschedule_deadline_hours` رندر می‌شود — با همان
 *     قاعدهٔ سرور (BookingWindow::checkCancelWithTimezone در timezone Location).
 *     تصمیم در BookingService گرفته می‌شود، نه در قالب (بدون محاسبهٔ موازی).
 *  2. ردیفی که یک یا هر دو دکمه‌اش پنهان شده، یادداشتِ «تماس با مطب» را نشان می‌دهد.
 *  3. GET `/reschedule-options` برای نوبتِ داخلِ مهلت، همان خطای POST
 *     `/reschedule` را می‌دهد (HTTP status، کد و پیامِ فارسی) — بدون بن‌بست.
 *
 * ⚑ Clinic / Location / Clinician / Patient / Slot / Appointment همگی fixture
 *   واقعی‌اند؛ Clinic رزروشده در این کلاس ساخته نمی‌شود.
 */
final class PatientPortalChangeDeadlineTest extends WP_UnitTestCase
{
    private const TZ = 'Asia/Tehran';

    private const POLICY_MESSAGE = 'زمان جابه‌جایی این نوبت گذشته است (سیاست مطب)';

    private const NOTE = 'برای تغییر یا لغو این نوبت، با مطب در تماس باشید.';

    private int $clinicId = 0;

    private int $locationId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        global $wpdb;

        // پیش‌شرط: نصب تک‌کلینیکی (پورتال و App::settings() همین را فرض می‌کنند).
        $clinicCount = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_clinics' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertSame(1, $clinicCount, 'precondition: exactly one seeded Clinic.');

        $this->clinicId = (int) $wpdb->get_var(
            'SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics ORDER BY id ASC LIMIT 1' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        self::assertGreaterThan(0, $this->clinicId, 'precondition: seeded Clinic id.');

        $this->locationId = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'cpms_locations WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $this->clinicId
            )
        );
        self::assertGreaterThan(0, $this->locationId, 'precondition: primary Location of the seeded Clinic.');

        // defaultهای سیاست برای هر تست صریح تنظیم می‌شود.
        $this->setDeadlines(24, 24);
    }

    protected function tearDown(): void
    {
        $this->setDeadlines(24, 24);
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();
        parent::tearDown();
    }

    // =================================================================
    // 1. دکمه‌ها و یادداشتِ «تماس با مطب»
    // =================================================================

    public function testAppointmentOutsideBothDeadlinesRendersBothButtonsAndNoContactNote(): void
    {
        [$userId, $appointmentId] = $this->fixtureWithAppointment('outside', '+5 days', '10:00:00');

        $html = $this->renderPortalAs($userId);
        $row  = $this->upcomingRow($html, $appointmentId);

        self::assertCount(1, $this->actionTags($row, 'cancel-appointment'), 'outside the cancel deadline the cancel action must render.');
        self::assertCount(1, $this->actionTags($row, 'reschedule-appointment'), 'outside the reschedule deadline the reschedule action must render.');
        self::assertStringNotContainsString(self::NOTE, $row, 'a row with both actions must not carry the contact-the-clinic note.');
    }

    public function testAppointmentInsideBothDeadlinesHidesBothButtonsAndShowsContactNote(): void
    {
        [$userId, $appointmentId] = $this->fixtureWithAppointment('inside', '+2 hours', null);

        $html = $this->renderPortalAs($userId);
        $row  = $this->upcomingRow($html, $appointmentId);

        self::assertCount(0, $this->actionTags($row, 'cancel-appointment'), 'inside the cancel deadline no cancel action may render.');
        self::assertCount(0, $this->actionTags($row, 'reschedule-appointment'), 'inside the reschedule deadline no reschedule action may render.');
        self::assertStringContainsString(self::NOTE, $row, 'a row whose actions are hidden must tell the patient to contact the clinic.');
    }

    public function testCancelDeadlineAloneHidesCancelAndKeepsReschedule(): void
    {
        // مهلتِ لغو 48h، مهلتِ جابه‌جایی 1h، نوبت 30h بعد ⇒ فقط لغو پنهان می‌شود.
        $this->setDeadlines(48, 1);

        [$userId, $appointmentId] = $this->fixtureWithAppointment('cancel_only', '+30 hours', null);

        $html = $this->renderPortalAs($userId);
        $row  = $this->upcomingRow($html, $appointmentId);

        self::assertCount(0, $this->actionTags($row, 'cancel-appointment'), 'past booking.cancel_deadline_hours the cancel action must be hidden.');
        self::assertCount(1, $this->actionTags($row, 'reschedule-appointment'), 'still inside booking.reschedule_deadline_hours the reschedule action must stay.');
        self::assertStringContainsString(self::NOTE, $row, 'a partially hidden row still carries the contact-the-clinic note.');
    }

    public function testRescheduleDeadlineAloneHidesRescheduleAndKeepsCancel(): void
    {
        // مهلتِ لغو 1h، مهلتِ جابه‌جایی 48h، نوبت 30h بعد ⇒ فقط جابه‌جایی پنهان می‌شود.
        $this->setDeadlines(1, 48);

        [$userId, $appointmentId] = $this->fixtureWithAppointment('resched_only', '+30 hours', null);

        $html = $this->renderPortalAs($userId);
        $row  = $this->upcomingRow($html, $appointmentId);

        self::assertCount(1, $this->actionTags($row, 'cancel-appointment'), 'still inside booking.cancel_deadline_hours the cancel action must stay.');
        self::assertCount(0, $this->actionTags($row, 'reschedule-appointment'), 'past booking.reschedule_deadline_hours the reschedule action must be hidden (not only the POST).');
        self::assertStringContainsString(self::NOTE, $row, 'a partially hidden row still carries the contact-the-clinic note.');
    }

    // =================================================================
    // 2. هم‌خوانیِ reschedule-options با reschedule (fail closed)
    // =================================================================

    public function testRescheduleOptionsReturnsTheSamePolicyErrorAsReschedulePost(): void
    {
        [$userId, $appointmentId, $slotId] = $this->fixtureWithAppointment('parity', '+2 hours', null);

        wp_set_current_user($userId);

        $options = $this->dispatch('GET', '/clinic/v1/appointments/' . $appointmentId . '/reschedule-options');
        $post    = $this->dispatch(
            'POST',
            '/clinic/v1/appointments/' . $appointmentId . '/reschedule',
            [
                'slot_date' => (string) $this->slotDate($slotId),
                'slot_time' => (string) $this->slotTime($slotId),
                'slot_id'   => $slotId,
            ],
            ['Idempotency-Key' => $this->uuid4()]
        );

        self::assertSame(409, $options->get_status(), 'GET reschedule-options must fail closed inside the reschedule deadline.');
        self::assertSame('CLINIC_POLICY_VIOLATION', $this->errCode($options));
        self::assertSame(self::POLICY_MESSAGE, $this->errMessage($options));

        self::assertSame(409, $post->get_status(), 'POST reschedule keeps rejecting the same appointment.');
        self::assertSame('CLINIC_POLICY_VIOLATION', $this->errCode($post));
        self::assertSame(self::POLICY_MESSAGE, $this->errMessage($post));

        self::assertSame($post->get_status(), $options->get_status(), 'status parity options/POST.');
        self::assertSame($this->errCode($post), $this->errCode($options), 'code parity options/POST.');
        self::assertSame($this->errMessage($post), $this->errMessage($options), 'Persian message parity options/POST.');

        self::assertSame('confirmed', $this->appointmentStatus($appointmentId), 'a denied reschedule must not mutate the appointment.');
    }

    public function testRescheduleOptionsReturnsSlotsOutsideTheDeadline(): void
    {
        [$userId, $appointmentId, , , $clinicianId] = $this->fixtureWithAppointment('options_ok', '+5 days', '10:00:00');

        $altSlot = $this->insertSlot($clinicianId, $this->localDate('+6 days'), '11:30:00', 0);

        wp_set_current_user($userId);

        $options = $this->dispatch('GET', '/clinic/v1/appointments/' . $appointmentId . '/reschedule-options');
        self::assertSame(200, $options->get_status(), 'outside the reschedule deadline options must load.');

        $payload = $this->payload($options);
        self::assertSame($appointmentId, (int) ($payload['appointment_id'] ?? 0));

        $ids = [];
        foreach ((array) ($payload['days'] ?? []) as $day) {
            foreach ((array) ($day['slots'] ?? []) as $slot) {
                $ids[] = (int) $slot['slot_id'];
            }
        }
        self::assertContains($altSlot, $ids, 'a free alternative slot must be offered when the policy allows rescheduling.');
    }

    // =================================================================
    // 3. متنِ نمایشی فارسی (برچسبِ پرونده، ارقامِ مدت، گزینهٔ نامشخص)
    // =================================================================

    public function testProfileContextUsesPersianMrnLabelAndGenderSelectOffersUnknown(): void
    {
        [$userId] = $this->fixtureWithAppointment('labels', '+5 days', '10:00:00');

        $mrn = (string) App::db()->fetchValue(
            'SELECT mrn FROM ' . App::db()->table('cpms_patients') . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1',
            [$this->clinicId]
        );
        self::assertNotSame('', $mrn, 'precondition: fixture Patient has an MRN.');

        $html = $this->renderPortalAs($userId);

        self::assertStringContainsString('شمارهٔ پرونده', $html, 'the patient record label must be Persian.');
        self::assertStringNotContainsString('MRN:', $html, 'the Latin «MRN:» label must not be rendered anywhere in the portal.');

        self::assertMatchesRegularExpression(
            '/<option\s+value="unknown"[^>]*>\s*نامشخص\s*<\/option>/u',
            $html,
            'the gender select must offer the database default value `unknown` with the Persian label «نامشخص».'
        );
    }

    public function testPrescriptionDurationRendersPersianDigits(): void
    {
        [$userId, , , $patientId, $clinicianId] = $this->fixtureWithAppointment('duration', '+5 days', '10:00:00');

        $this->insertFinalizedPrescriptionWithDuration($patientId, $clinicianId, 7);

        $html = $this->renderPortalAs($userId);

        self::assertStringContainsString('۷ روز', $html, 'the prescription duration must use Persian digits («۷ روز»).');
        self::assertStringNotContainsString('7 روز', $html, 'no Latin digit may appear in the Persian duration text.');
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * @return array{0:int,1:int,2:int,3:int,4:int} [user_id, appointment_id, slot_id, patient_id, clinician_id]
     */
    private function fixtureWithAppointment(
        string $tag,
        string $modify,
        ?string $time,
        bool $withAlternativeSlot = false
    ): array {
        $patient   = $this->makePatient('PD' . $tag);
        $userId    = $this->makePurePatientUser($tag);
        $this->linkUser($userId, $patient['patient_id'], $patient['mobile']);
        $clinicianId = $this->insertClinician('دکتر پروندهٔ ' . $tag);

        $date = $this->localDate($modify);
        $time = $time ?? $this->localTime($modify);

        $slotId        = $this->insertSlot($clinicianId, $date, $time, 1);
        $appointmentId = $this->insertAppointment($patient['patient_id'], $userId, $clinicianId, $slotId, $date, $time);

        if ($withAlternativeSlot) {
            $this->insertSlot($clinicianId, $this->localDate('+7 days'), '12:00:00', 0);
        }

        // Witness: نوبت واقعاً در جدولِ «پیش‌رو»ی پورتال دیده می‌شود.
        $rows = App::bookingService()->listMine(
            $userId,
            gmdate('Y-m-d', strtotime('-365 days')),
            gmdate('Y-m-d', strtotime('+180 days'))
        );
        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['id']] = (string) $row['status'];
        }
        self::assertArrayHasKey($appointmentId, $found, 'positive control: listMine() returns the fixture appointment.');
        self::assertSame('confirmed', $found[$appointmentId], 'positive control: fixture appointment is confirmed.');

        return [$userId, $appointmentId, $slotId, $patient['patient_id'], $clinicianId];
    }

    private function setDeadlines(int $cancelHours, int $rescheduleHours): void
    {
        $settings = App::settingsFactory()->forClinic($this->clinicId);
        $settings->set('booking.cancel_deadline_hours', $cancelHours);
        $settings->set('booking.reschedule_deadline_hours', $rescheduleHours);
        Settings::flushCache();
    }

    /**
     * @return array{patient_id:int, mobile:string}
     */
    private function makePatient(string $tag): array
    {
        global $wpdb;

        $now    = App::db()->nowUtcSql();
        $mobile = '09' . substr(bin2hex(random_bytes(5)), 0, 9);

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_patients',
            [
                'clinic_id'  => $this->clinicId,
                'mrn'        => 'MR-PD-' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $tag) ?? '') . bin2hex(random_bytes(4)),
                'first_name' => 'PortalD',
                'last_name'  => $tag,
                'mobile'     => $mobile,
                'gender'     => 'unknown',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: patient insert failed: ' . $wpdb->last_error);

        return ['patient_id' => (int) $wpdb->insert_id, 'mobile' => $mobile];
    }

    private function makePurePatientUser(string $tag): int
    {
        $suffix = strtolower((string) (preg_replace('/[^a-z0-9]/i', '', $tag) ?? '')) . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user(
            'pdpkg_' . $suffix . '_' . uniqid('', false),
            'pass-not-used-123',
            'pdpkg_' . $suffix . '_' . uniqid('', false) . '@test.local'
        );
        self::assertGreaterThan(0, $userId, 'fixture: WP user created.');
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role(RolesAndCapabilities::ROLE_PATIENT);
        self::assertTrue(PatientPortalPage::isPatientOnly($userId), 'positive control: pure patient role.');

        return $userId;
    }

    private function linkUser(int $userId, int $patientId, string $mobile): void
    {
        global $wpdb;

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_patient_user_links',
            [
                'clinic_id'      => $this->clinicId,
                'patient_id'     => $patientId,
                'wp_user_id'     => $userId,
                'mobile_at_link' => $mobile,
                'is_primary'     => 1,
                'linked_at'      => App::db()->nowUtcSql(),
            ],
            ['%d', '%d', '%d', '%s', '%d', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: patient_user_link insert failed: ' . $wpdb->last_error);
    }

    private function insertClinician(string $name): int
    {
        global $wpdb;

        $now = App::db()->nowUtcSql();

        // clinician.wp_user_id پیش‌شرطِ تخصیصِ Location است
        // (MembershipService::assign_clinician_locations → clinician_wp_user_id()).
        $docUserId = (int) wp_create_user(
            'pdpkg_doc_' . bin2hex(random_bytes(4)) . '_' . uniqid('', false),
            'pass-not-used-123',
            'pdpkg_doc_' . bin2hex(random_bytes(4)) . '_' . uniqid('', false) . '@test.local'
        );
        self::assertGreaterThan(0, $docUserId, 'fixture: clinician user created.');
        $docUser = get_userdata($docUserId);
        self::assertNotFalse($docUser);
        $docUser->set_role('subscriber');

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_clinicians',
            [
                'clinic_id'  => $this->clinicId,
                'wp_user_id' => $docUserId,
                'full_name'  => $name,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%d', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: clinician insert failed: ' . $wpdb->last_error);
        $clinicianId = (int) $wpdb->insert_id;

        // مشارکتِ پایدار + تخصیص Location — پیش‌شرطِ فهرستِ اسلاتِ آزاد در
        // reschedule-options (availabilityCandidates).
        cpms_test_seed_membership($docUserId, $this->clinicId, 'cpms_doctor');
        App::membership_service()->assign_clinician_locations($clinicianId, [$this->locationId], $this->locationId);

        return $clinicianId;
    }

    private function insertSlot(int $clinicianId, string $date, string $time, int $booked): int
    {
        global $wpdb;

        $now = App::db()->nowUtcSql();
        $ok  = $wpdb->insert(
            $wpdb->prefix . 'cpms_schedule_slots',
            [
                'clinic_id'      => $this->clinicId,
                'location_id'    => $this->locationId,
                'clinician_id'   => $clinicianId,
                'slot_date'      => $date,
                'slot_time'      => $time,
                'duration_min'   => 20,
                'capacity'       => 1,
                'booked_count'   => $booked,
                'held_count'     => 0,
                'is_open'        => 1,
                'generated_from' => 'manual',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: slot insert failed: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function insertAppointment(int $patientId, int $userId, int $clinicianId, int $slotId, string $date, string $time): int
    {
        global $wpdb;

        $now     = App::db()->nowUtcSql();
        $endTime = (new \DateTimeImmutable($date . ' ' . $time, new \DateTimeZone(self::TZ)))
            ->add(new \DateInterval('PT20M'))->format('H:i:s');

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_appointments',
            [
                'clinic_id'         => $this->clinicId,
                'location_id'       => $this->locationId,
                'reference_code'    => 'PDPKG' . strtoupper(bin2hex(random_bytes(6))),
                'patient_id'        => $patientId,
                'clinician_id'      => $clinicianId,
                'slot_id'           => $slotId,
                'wp_user_id'        => $userId,
                'slot_date'         => $date,
                'slot_time'         => $time,
                'duration_min'      => 20,
                'slot_end_time'     => $endTime,
                'status'            => 'confirmed',
                'is_walkin_express' => 0,
                'confirmed_at'      => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            ['%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: appointment insert failed: ' . $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    private function insertFinalizedPrescriptionWithDuration(int $patientId, int $clinicianId, int $durationDays): int
    {
        global $wpdb;

        $now    = App::db()->nowUtcSql();
        $visits = $wpdb->prefix . 'cpms_visits';

        $ok = $wpdb->insert(
            $visits,
            [
                'clinic_id'        => $this->clinicId,
                'clinician_id'     => $clinicianId,
                'patient_id'       => $patientId,
                'source'           => 'walk_in',
                'status'           => 'checked_out',
                'visit_date'       => $this->localDate('-1 day'),
                'check_in_at'      => $now,
                'active'           => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: visit insert failed: ' . $wpdb->last_error);
        $visitId = (int) $wpdb->insert_id;

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_prescriptions',
            [
                'clinic_id'           => $this->clinicId,
                'prescription_number' => 'PDRX' . strtoupper(bin2hex(random_bytes(6))),
                'visit_id'            => $visitId,
                'patient_id'          => $patientId,
                'clinician_id'        => $clinicianId,
                'status'              => 'finalized',
                'is_patient_visible'  => 1,
                'finalized_at'        => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ],
            ['%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: prescription insert failed: ' . $wpdb->last_error);
        $prescriptionId = (int) $wpdb->insert_id;

        $ok = $wpdb->insert(
            $wpdb->prefix . 'cpms_prescription_items',
            [
                'prescription_id' => $prescriptionId,
                'generic_name'    => 'Metformin',
                'strength'        => '500mg',
                'form'            => 'tablet',
                'dose'            => '1',
                'frequency'       => 'BD',
                'route'           => 'oral',
                'duration_days'   => $durationDays,
                'source'          => 'manual',
                'sort_order'      => 0,
                'created_at'      => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s']
        );
        self::assertTrue((bool) $ok, 'fixture: prescription item insert failed: ' . $wpdb->last_error);

        return $prescriptionId;
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function renderPortalAs(int $userId): string
    {
        wp_set_current_user($userId);
        ob_start();
        try {
            PatientPortalPage::render();
        } finally {
            $html = (string) ob_get_clean();
        }
        self::assertNotSame('', $html, 'positive control: PatientPortalPage::render() produced markup.');

        return $html;
    }

    private function upcomingRow(string $html, int $appointmentId): string
    {
        $needle = 'data-appointment-id="' . $appointmentId . '"';
        $count  = preg_match_all('/<tr\b[^>]*>(?:(?!<\/tr>).)*<\/tr>/su', $html, $m);
        self::assertGreaterThan(0, (int) $count, 'positive control: portal renders table rows.');
        foreach ($m[0] as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        // ردیفی که همهٔ دکمه‌هایش پنهان شده هیچ data-appointment-id ندارد؛
        // آن ردیف را با کد رهگیریِ همان نوبت پیدا می‌کنیم.
        $reference = (string) App::db()->fetchValue(
            'SELECT reference_code FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
        self::assertNotSame('', $reference, 'precondition: appointment reference code.');
        foreach ($m[0] as $row) {
            if (str_contains($row, $reference)) {
                return $row;
            }
        }

        self::fail('positive control: no <tr> belongs to fixture appointment #' . $appointmentId . '.');
    }

    /**
     * @return list<string>
     */
    private function actionTags(string $fragment, string $role): array
    {
        preg_match_all('/<[a-zA-Z][^>]*\sdata-role="' . preg_quote($role, '/') . '"[^>]*>/su', $fragment, $m);

        return $m[0];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        ScopeContext::clear();
        App::resetScope();
        $request = new \WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Response $response): array
    {
        $body = $response->get_data();
        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return is_array($body) ? $body : [];
    }

    private function errCode(WP_REST_Response $response): string
    {
        $body = $response->get_data();

        return $body instanceof \WP_Error ? (string) $body->get_error_code() : '';
    }

    private function errMessage(WP_REST_Response $response): string
    {
        $body = $response->get_data();

        return $body instanceof \WP_Error ? (string) $body->get_error_message() : '';
    }

    private function appointmentStatus(int $appointmentId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$appointmentId]
        );
    }

    private function slotDate(int $slotId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT slot_date FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
    }

    private function slotTime(int $slotId): string
    {
        return (string) App::db()->fetchValue(
            'SELECT slot_time FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
    }

    private function localDate(string $modify = ''): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TZ));
        if ($modify !== '') {
            $now = $now->modify($modify);
        }

        return $now->format('Y-m-d');
    }

    private function localTime(string $modify = ''): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TZ));
        if ($modify !== '') {
            $now = $now->modify($modify);
        }

        return $now->format('H:i:s');
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

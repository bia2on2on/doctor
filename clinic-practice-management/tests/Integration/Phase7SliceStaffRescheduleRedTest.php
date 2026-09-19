<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Phase 7 — TEST-ONLY RED: staff/secretary appointment reschedule (FR-5.3 / T7).
 *
 * OWNER-ISSUED PRODUCT POLICY (not original SRS wording):
 *  - the patient's 24-hour reschedule deadline does NOT apply to staff;
 *  - the patient-specific destination minimum-lead restriction does NOT apply;
 *  - after a successful staff reschedule the patient receives BOTH an internal
 *    notification AND the existing reschedule SMS/change notification.
 *
 * Engineering contract encoded here:
 *  - capability `cpms_appt_reschedule` + trusted explicit Clinic scope;
 *  - raw request IDs are selectors, never authority;
 *  - confirmed → rescheduled only; exactly one confirmed replacement; two-way linkage;
 *  - HAS_ACTIVE_VISIT / 409 with zero booking mutation for a genuine live Visit;
 *  - stale `active_visit_id` must not block;
 *  - Idempotency-Key required; successful replay is origin semantics; failure does not stick the key;
 *  - patient reschedule deadline / min-lead policy is unchanged;
 *  - REST reachability through the established mutation surface
 *    `POST /clinic/v1/appointments/{id}/reschedule`.
 *
 * No portal/UI coverage. No new concurrency suite: staff reuses the shared
 * reschedule core already exercised in Phase7Slice2.
 */
final class Phase7SliceStaffRescheduleRedTest extends WP_UnitTestCase
{
    private const NS = '/clinic/v1';
    private const TZ = 'Asia/Tehran';
    private const LIVE_VISIT_STATUSES = [
        'checked_in',
        'waiting',
        'called',
        'in_consultation',
        'consultation_completed',
        'awaiting_payment',
        'paid',
    ];

    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianA = 0;
    private int $clinicianB = 0;
    private int $patientA = 0;
    private int $patientB = 0;
    private int $secretaryA = 0;
    private int $doctorA = 0;
    private int $patientUserA = 0;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user(0);

        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at)
             VALUES (%s, %s, "active", %s, %s)',
            'P7SR Org ' . $unique,
            'p7sr-org-' . $unique,
            $now,
            $now
        ));
        $this->orgId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->orgId, 'precondition: organization');

        $this->clinicA = $this->insertClinic($this->orgId, 'a', $unique, $now);
        $this->clinicB = $this->insertClinic($this->orgId, 'b', $unique, $now);
        $this->locationA = $this->insertLocation($this->clinicA, 'a', $unique, $now);
        $this->locationB = $this->insertLocation($this->clinicB, 'b', $unique, $now);

        $this->secretaryA = $this->makeUser('p7sr_sec', RolesAndCapabilities::ROLE_SECRETARY);
        $this->doctorA = $this->makeUser('p7sr_doc', RolesAndCapabilities::ROLE_DOCTOR);
        $this->patientUserA = $this->makeUser('p7sr_pat', RolesAndCapabilities::ROLE_PATIENT);

        $this->clinicianA = $this->insertClinician($this->clinicA, $this->doctorA, 'Dr P7SR A', $now);
        $this->clinicianB = $this->insertClinician($this->clinicB, 0, 'Dr P7SR B', $now);
        $this->patientA = $this->insertPatient($this->clinicA, 'A', $now);
        $this->patientB = $this->insertPatient($this->clinicB, 'B', $now);

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patient_user_links
                 (clinic_id, patient_id, wp_user_id, mobile_at_link, is_primary, linked_at)
             VALUES (%d, %d, %d, %s, 1, %s)',
            $this->clinicA,
            $this->patientA,
            $this->patientUserA,
            '09120000000',
            $now
        ));

        self::assertGreaterThan(0, cpms_test_seed_membership($this->secretaryA, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY));
        self::assertGreaterThan(0, cpms_test_seed_membership($this->doctorA, $this->clinicA, RolesAndCapabilities::ROLE_DOCTOR));

        $settings = App::settingsFactory()->forClinic($this->clinicA);
        $settings->set('booking.cancel_deadline_hours', 24);
        $settings->set('booking.reschedule_deadline_hours', 24);
        $settings->set('booking.min_lead_hours', 2);
        $settings->set('booking.max_future_days', 60);
        $settings->set('sms.provider', 'log');
        App::settings()->set('booking.cancel_deadline_hours', 24);
        App::settings()->set('booking.reschedule_deadline_hours', 24);
        App::settings()->set('booking.min_lead_hours', 2);
        App::settings()->set('booking.max_future_days', 60);

        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
    }

    protected function tearDown(): void
    {
        $this->purgeFixture();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1–3 + 11–12 — authorized secretary happy path
    // ------------------------------------------------------------------

    public function testAuthorizedSecretaryReschedulesConfirmedAppointmentWithLinkageSlotsAuditAndNotifications(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '10:00:00', 'ok');
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 5, '14:00:00');

        $result = $this->staffReschedule(
            $this->secretaryA,
            $appt['id'],
            $this->clinicianA,
            $dest['date'],
            $dest['time'],
            $this->uuid(),
            $dest['id']
        );

        self::assertSame('confirmed', (string) $result['status']);
        self::assertSame($appt['id'], (int) $result['previous_appointment_id']);
        self::assertNotSame($appt['id'], (int) $result['appointment_id']);

        $old = $this->appointmentRow($appt['id']);
        $new = $this->appointmentRow((int) $result['appointment_id']);
        self::assertSame('rescheduled', (string) $old['status']);
        self::assertSame('confirmed', (string) $new['status']);
        self::assertSame((int) $new['id'], (int) $old['rescheduled_to']);
        self::assertSame((int) $old['id'], (int) $new['rescheduled_from']);
        self::assertSame(1, $this->replacementCount($appt['id']));
        self::assertSame(0, (int) $this->slotRow($appt['slot_id'])['booked_count']);
        self::assertSame(1, (int) $this->slotRow($dest['id'])['booked_count']);

        $audit = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') .
            " WHERE action = 'APPOINTMENT_RESCHEDULED' AND actor_wp_user_id = %d AND clinic_id = %d",
            [$this->secretaryA, $this->clinicA]
        );
        self::assertGreaterThanOrEqual(1, $audit, 'successful staff reschedule must write APPOINTMENT_RESCHEDULED audit');

        $sms = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages') .
            ' WHERE clinic_id = %d AND event = %s AND context_type = %s AND context_id = %d',
            [$this->clinicA, SmsEvents::APPT_RESCHEDULED, 'appointment', (int) $new['id']]
        );
        self::assertGreaterThanOrEqual(1, $sms, 'patient must receive the existing reschedule SMS/change notification');

        $notif = (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_notifications') .
            ' WHERE clinic_id = %d AND recipient_patient_id = %d AND template = %s',
            [$this->clinicA, $this->patientA, NotificationEvents::APPT_CHANGED]
        );
        self::assertGreaterThanOrEqual(1, $notif, 'patient must receive an internal notification');
    }

    // ------------------------------------------------------------------
    // 4 — staff succeeds inside the patient 24-hour deadline
    // ------------------------------------------------------------------

    public function testSecretaryRescheduleSucceedsInsidePatientTwentyFourHourDeadline(): void
    {
        $near = $this->seedLocalSlotHoursFromNow($this->clinicA, $this->locationA, $this->clinicianA, 6, true);
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 5, '15:00:00');

        $patientError = $this->captureBookingError(function () use ($near, $dest): void {
            $this->booking()->reschedule(
                $this->patientUserA,
                $near['id'],
                $this->clinicianA,
                $dest['date'],
                $dest['time'],
                $this->uuid(),
                $dest['id']
            );
        }, 'patient deadline');
        self::assertSame('CLINIC_POLICY_VIOLATION', $patientError->errorCode);
        self::assertSame(409, $patientError->httpStatus);
        self::assertSame('confirmed', (string) $this->appointmentRow($near['id'])['status']);

        $result = $this->staffReschedule(
            $this->secretaryA,
            $near['id'],
            $this->clinicianA,
            $dest['date'],
            $dest['time'],
            $this->uuid(),
            $dest['id']
        );
        self::assertSame('confirmed', (string) $result['status']);
        self::assertSame('rescheduled', (string) $this->appointmentRow($near['id'])['status']);
    }

    // ------------------------------------------------------------------
    // 5 — staff succeeds without patient destination min-lead
    // ------------------------------------------------------------------

    public function testSecretaryRescheduleSucceedsWithoutPatientDestinationMinLead(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 4, '10:00:00', 'lead');
        $nearDest = $this->seedLocalOpenSlotHoursFromNow($this->clinicA, $this->locationA, $this->clinicianA, 1);

        $patientError = $this->captureBookingError(function () use ($appt, $nearDest): void {
            $this->booking()->reschedule(
                $this->patientUserA,
                $appt['id'],
                $this->clinicianA,
                $nearDest['date'],
                $nearDest['time'],
                $this->uuid(),
                $nearDest['id']
            );
        }, 'patient min-lead');
        self::assertSame('CLINIC_POLICY_VIOLATION', $patientError->errorCode);
        self::assertSame(409, $patientError->httpStatus);

        $result = $this->staffReschedule(
            $this->secretaryA,
            $appt['id'],
            $this->clinicianA,
            $nearDest['date'],
            $nearDest['time'],
            $this->uuid(),
            $nearDest['id']
        );
        self::assertSame('confirmed', (string) $result['status']);
        self::assertSame(1, (int) $this->slotRow($nearDest['id'])['booked_count']);
    }

    // ------------------------------------------------------------------
    // 6 — missing cpms_appt_reschedule
    // ------------------------------------------------------------------

    public function testMissingCpmsApptRescheduleAuthorizationIsRejected(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '11:00:00', 'deny');
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 5, '16:00:00');

        $membership = App::membership_service()->active_membership_for($this->clinicA, $this->secretaryA);
        self::assertNotNull($membership);
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::APPT_RESCHEDULE, 'deny');
        self::assertTrue(user_can($this->secretaryA, RolesAndCapabilities::APPT_RESCHEDULE), 'precondition: coarse WP cap remains');
        self::assertFalse(
            (new AuthorizationService(new MembershipRepository(App::db())))->can(
                $this->secretaryA,
                $this->clinicA,
                RolesAndCapabilities::APPT_RESCHEDULE
            ),
            'precondition: scoped deny'
        );

        $response = $this->dispatchStaffReschedule($this->secretaryA, $this->clinicA, $appt['id'], $dest, $this->uuid());
        self::assertSame(403, $response->get_status());
        $this->assertClinicError($response, 'CLINIC_PERMISSION_DENIED');
        self::assertSame('confirmed', (string) $this->appointmentRow($appt['id'])['status']);
        self::assertSame(0, $this->replacementCount($appt['id']));
        self::assertSame(1, (int) $this->slotRow($appt['slot_id'])['booked_count']);
        self::assertSame(0, (int) $this->slotRow($dest['id'])['booked_count']);
    }

    // ------------------------------------------------------------------
    // 7 — cross-Clinic fail-closed
    // ------------------------------------------------------------------

    public function testCrossClinicAppointmentAndDestinationFailClosedWithEstablishedParity(): void
    {
        $apptB = $this->seedConfirmedAppointment($this->clinicB, $this->locationB, $this->patientB, $this->clinicianB, 3, '10:00:00', 'xb');
        $destB = $this->seedOpenSlot($this->clinicB, $this->locationB, $this->clinicianB, 5, '11:00:00');
        $apptA = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '12:00:00', 'xa');

        $crossAppt = $this->dispatchStaffReschedule($this->secretaryA, $this->clinicA, $apptB['id'], $destB, $this->uuid());
        self::assertSame(404, $crossAppt->get_status(), 'Clinic-B appointment is missing for a Clinic-A actor');
        $this->assertClinicError($crossAppt, 'CLINIC_NOT_FOUND');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptB['id'])['status']);
        self::assertSame(0, $this->replacementCount($apptB['id']));

        $crossDest = $this->dispatchStaffReschedule($this->secretaryA, $this->clinicA, $apptA['id'], $destB, $this->uuid());
        self::assertSame(404, $crossDest->get_status(), 'Clinic-B destination identifiers fail closed');
        $this->assertClinicError($crossDest, 'CLINIC_NOT_FOUND');
        self::assertSame('confirmed', (string) $this->appointmentRow($apptA['id'])['status']);
        self::assertSame(0, (int) $this->slotRow($destB['id'])['booked_count']);
    }

    // ------------------------------------------------------------------
    // 8–9 — active Visit / stale pointer
    // ------------------------------------------------------------------

    public function testGenuinelyActiveVisitRejectsStaffRescheduleWithZeroMutation(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '13:00:00', 'vis');
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 5, '17:00:00');
        $visit = App::visitService()->checkIn($this->secretaryA, $this->patientA, $appt['id']);
        self::assertContains((string) $visit['status'], self::LIVE_VISIT_STATUSES);

        $error = $this->captureBookingError(function () use ($appt, $dest): void {
            $this->staffReschedule(
                $this->secretaryA,
                $appt['id'],
                $this->clinicianA,
                $dest['date'],
                $dest['time'],
                $this->uuid(),
                $dest['id']
            );
        }, 'HAS_ACTIVE_VISIT');
        self::assertSame('HAS_ACTIVE_VISIT', $error->errorCode);
        self::assertSame(409, $error->httpStatus);
        self::assertSame('confirmed', (string) $this->appointmentRow($appt['id'])['status']);
        self::assertSame(0, $this->replacementCount($appt['id']));
        self::assertSame(1, (int) $this->slotRow($appt['slot_id'])['booked_count']);
        self::assertSame(0, (int) $this->slotRow($dest['id'])['booked_count']);
    }

    public function testStaleActiveVisitIdDoesNotBlockStaffReschedule(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '09:00:00', 'stale');
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 6, '09:30:00');
        $visit = App::visitService()->checkIn($this->secretaryA, $this->patientA, $appt['id']);
        App::visitService()->transition($this->secretaryA, (int) $visit['id'], 'cancel', ['reason' => 'پایان ویزیت']);
        self::assertSame((int) $visit['id'], (int) $this->appointmentRow($appt['id'])['active_visit_id']);

        $result = $this->staffReschedule(
            $this->secretaryA,
            $appt['id'],
            $this->clinicianA,
            $dest['date'],
            $dest['time'],
            $this->uuid(),
            $dest['id']
        );
        self::assertSame('confirmed', (string) $result['status']);
        self::assertSame('rescheduled', (string) $this->appointmentRow($appt['id'])['status']);
    }

    // ------------------------------------------------------------------
    // 10 — Idempotency-Key
    // ------------------------------------------------------------------

    public function testStaffRescheduleIdempotencyKeyRequiredReplayAndFailureDoesNotStick(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '08:00:00', 'idem');
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 5, '08:30:00');

        $missing = $this->dispatchStaffReschedule($this->secretaryA, $this->clinicA, $appt['id'], $dest, null);
        self::assertSame(400, $missing->get_status());
        $this->assertClinicError($missing, 'CLINIC_VALIDATION_FAILED');

        $visit = App::visitService()->checkIn($this->secretaryA, $this->patientA, $appt['id']);
        $key = $this->uuid();
        $failed = $this->captureBookingError(function () use ($appt, $dest, $key): void {
            $this->staffReschedule(
                $this->secretaryA,
                $appt['id'],
                $this->clinicianA,
                $dest['date'],
                $dest['time'],
                $key,
                $dest['id']
            );
        }, 'idempotency failure');
        self::assertSame('HAS_ACTIVE_VISIT', $failed->errorCode);

        App::visitService()->transition($this->secretaryA, (int) $visit['id'], 'cancel', ['reason' => 'retry']);
        $first = $this->staffReschedule(
            $this->secretaryA,
            $appt['id'],
            $this->clinicianA,
            $dest['date'],
            $dest['time'],
            $key,
            $dest['id']
        );
        self::assertSame('confirmed', (string) $first['status']);
        $replay = $this->staffReschedule(
            $this->secretaryA,
            $appt['id'],
            $this->clinicianA,
            $dest['date'],
            $dest['time'],
            $key,
            $dest['id']
        );
        self::assertSame((int) $first['appointment_id'], (int) $replay['appointment_id']);
        self::assertSame(1, $this->replacementCount($appt['id']), 'replay must not create a second replacement');
    }

    // ------------------------------------------------------------------
    // 13 — patient policy retained
    // ------------------------------------------------------------------

    public function testPatientRescheduleRetainsDeadlineAndMinLeadPolicy(): void
    {
        $near = $this->seedLocalSlotHoursFromNow($this->clinicA, $this->locationA, $this->clinicianA, 5, true);
        $farDest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 6, '10:00:00');
        $deadline = $this->captureBookingError(function () use ($near, $farDest): void {
            $this->booking()->reschedule(
                $this->patientUserA,
                $near['id'],
                $this->clinicianA,
                $farDest['date'],
                $farDest['time'],
                $this->uuid(),
                $farDest['id']
            );
        }, 'patient deadline retained');
        self::assertSame('CLINIC_POLICY_VIOLATION', $deadline->errorCode);

        $far = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 4, '16:00:00', 'pmin');
        $nearDest = $this->seedLocalOpenSlotHoursFromNow($this->clinicA, $this->locationA, $this->clinicianA, 1);
        $lead = $this->captureBookingError(function () use ($far, $nearDest): void {
            $this->booking()->reschedule(
                $this->patientUserA,
                $far['id'],
                $this->clinicianA,
                $nearDest['date'],
                $nearDest['time'],
                $this->uuid(),
                $nearDest['id']
            );
        }, 'patient min-lead retained');
        self::assertSame('CLINIC_POLICY_VIOLATION', $lead->errorCode);
        self::assertSame('confirmed', (string) $this->appointmentRow($far['id'])['status']);
    }

    // ------------------------------------------------------------------
    // REST reachability
    // ------------------------------------------------------------------

    public function testAuthorizedSecretaryReachesStaffRescheduleThroughRestMutationSurface(): void
    {
        $appt = $this->seedConfirmedAppointment($this->clinicA, $this->locationA, $this->patientA, $this->clinicianA, 3, '18:00:00', 'rest');
        $dest = $this->seedOpenSlot($this->clinicA, $this->locationA, $this->clinicianA, 5, '18:30:00');

        self::assertTrue(
            (new AuthorizationService(new MembershipRepository(App::db())))->can(
                $this->secretaryA,
                $this->clinicA,
                RolesAndCapabilities::APPT_RESCHEDULE
            ),
            'precondition: scoped cpms_appt_reschedule'
        );

        $response = $this->dispatchStaffReschedule($this->secretaryA, $this->clinicA, $appt['id'], $dest, $this->uuid());
        self::assertSame(200, $response->get_status(), 'authorized secretary must reach staff reschedule through REST');
        $data = $response->get_data()['data'] ?? [];
        self::assertSame('confirmed', (string) ($data['status'] ?? ''));
        self::assertSame($appt['id'], (int) ($data['previous_appointment_id'] ?? 0));
        self::assertSame('rescheduled', (string) $this->appointmentRow($appt['id'])['status']);
        self::assertSame(1, $this->replacementCount($appt['id']));
    }

    // ------------------------------------------------------------------
    // Staff entry
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function staffReschedule(
        int $actorUserId,
        int $appointmentId,
        int $newClinicianId,
        string $newDate,
        string $newTime,
        string $idemKey,
        int $newSlotId
    ): array {
        if (!method_exists(BookingService::class, 'rescheduleByStaff')) {
            $this->fail('missing staff reschedule contract: BookingService::rescheduleByStaff');
        }

        /** @var callable $fn */
        $fn = [$this->booking(), 'rescheduleByStaff'];

        return $fn($actorUserId, $appointmentId, $newClinicianId, $newDate, $newTime, $idemKey, $newSlotId);
    }

    private function booking(): BookingService
    {
        return App::bookingService();
    }

    /**
     * @param callable(): void $call
     */
    private function captureBookingError(callable $call, string $context): BookingException
    {
        try {
            $call();
        } catch (BookingException $e) {
            return $e;
        } catch (Throwable $e) {
            $this->fail($context . ': expected BookingException, got ' . get_class($e) . ': ' . $e->getMessage());
        }
        $this->fail($context . ': expected a BookingException — the operation succeeded.');
    }

    /**
     * @param array{id: int, date: string, time: string} $dest
     */
    private function dispatchStaffReschedule(int $userId, int $clinicId, int $appointmentId, array $dest, ?string $idemKey): WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('POST', self::NS . '/appointments/' . $appointmentId . '/reschedule');
        $request->set_param('slot_date', $dest['date']);
        $request->set_param('slot_time', $dest['time']);
        $request->set_param('slot_id', $dest['id']);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        if ($idemKey !== null) {
            $request->set_header('Idempotency-Key', $idemKey);
        }

        return rest_do_request($request);
    }

    private function assertClinicError(WP_REST_Response $response, string $code): void
    {
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame($code, $data['code'] ?? null);
        self::assertSame($response->get_status(), $data['data']['status'] ?? null);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array{id: int, slot_id: int, date: string, time: string}
     */
    private function seedConfirmedAppointment(
        int $clinicId,
        int $locationId,
        int $patientId,
        int $clinicianId,
        int $daysAhead,
        string $time,
        string $tag
    ): array {
        $slot = $this->seedSlot($clinicId, $locationId, $clinicianId, $daysAhead, $time, 1);
        $id = $this->insertAppointment($clinicId, $locationId, $patientId, $clinicianId, $slot, $tag);

        return ['id' => $id, 'slot_id' => $slot['id'], 'date' => $slot['date'], 'time' => $slot['time']];
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function seedOpenSlot(int $clinicId, int $locationId, int $clinicianId, int $daysAhead, string $time): array
    {
        return $this->seedSlot($clinicId, $locationId, $clinicianId, $daysAhead, $time, 0);
    }

    /**
     * Confirmed appointment whose start is ~$hoursFromNow locally (inside 24h).
     *
     * @return array{id: int, slot_id: int, date: string, time: string}
     */
    private function seedLocalSlotHoursFromNow(int $clinicId, int $locationId, int $clinicianId, int $hoursFromNow, bool $booked): array
    {
        $slot = $this->seedLocalOpenSlotHoursFromNow($clinicId, $locationId, $clinicianId, $hoursFromNow, $booked ? 1 : 0);
        $id = $this->insertAppointment($clinicId, $locationId, $this->patientA, $clinicianId, $slot, 'near');

        return ['id' => $id, 'slot_id' => $slot['id'], 'date' => $slot['date'], 'time' => $slot['time']];
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function seedLocalOpenSlotHoursFromNow(int $clinicId, int $locationId, int $clinicianId, int $hoursFromNow, int $booked = 0): array
    {
        $local = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->modify('+' . $hoursFromNow . ' hours');
        $date = $local->format('Y-m-d');
        $time = $local->format('H:i:00');

        return $this->insertSlotRow($clinicId, $locationId, $clinicianId, $date, $time, $booked);
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function seedSlot(int $clinicId, int $locationId, int $clinicianId, int $daysAhead, string $time, int $booked): array
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $daysAhead . ' days')
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->format('Y-m-d');

        return $this->insertSlotRow($clinicId, $locationId, $clinicianId, $date, $time, $booked);
    }

    /**
     * @return array{id: int, date: string, time: string}
     */
    private function insertSlotRow(int $clinicId, int $locationId, int $clinicianId, string $date, string $time, int $booked): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots
                 (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, booked_count, held_count, is_open, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, 20, 1, %d, 0, 1, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $date,
            $time,
            $booked,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: slot (' . $wpdb->last_error . ')');

        return ['id' => $id, 'date' => $date, 'time' => substr($time, 0, 5)];
    }

    /**
     * @param array{id: int, date: string, time: string} $slot
     */
    private function insertAppointment(int $clinicId, int $locationId, int $patientId, int $clinicianId, array $slot, string $tag): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $time = strlen($slot['time']) === 5 ? $slot['time'] . ':00' : $slot['time'];
        $endTime = (new \DateTimeImmutable($slot['date'] . ' ' . $time, new \DateTimeZone(self::TZ)))
            ->add(new \DateInterval('PT20M'))->format('H:i:s');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments
                 (clinic_id, location_id, reference_code, patient_id, clinician_id, slot_id, slot_date, slot_time,
                  duration_min, slot_end_time, status, is_walkin_express, confirmed_at, created_at, updated_at)
             VALUES (%d, %d, %s, %d, %d, %d, %s, %s, 20, %s, "confirmed", 0, %s, %s, %s)',
            $clinicId,
            $locationId,
            'P7SR-' . $tag . '-' . bin2hex(random_bytes(3)),
            $patientId,
            $clinicianId,
            (int) $slot['id'],
            $slot['date'],
            $time,
            $endTime,
            $now,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'precondition: appointment (' . $wpdb->last_error . ')');

        return $id;
    }

    private function insertClinic(int $orgId, string $tag, string $unique, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s)',
            $orgId,
            'P7SR Clinic ' . $tag . ' ' . $unique,
            'p7sr-clinic-' . $tag . '-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(1, $id);

        return $id;
    }

    private function insertLocation(int $clinicId, string $tag, string $unique, string $now): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations
                 (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at)
             VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $clinicId,
            'P7SR Loc ' . $tag,
            'p7sr-loc-' . $tag . '-' . $unique,
            self::TZ,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function insertClinician(int $clinicId, int $wpUserId, string $name, string $now): int
    {
        global $wpdb;
        $linked = $wpUserId > 0 ? $wpUserId : $this->makeUser('p7sr_clin', 'subscriber');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at)
             VALUES (%d, %s, %d, 1, %s, %s)',
            $clinicId,
            $name,
            $linked,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function insertPatient(int $clinicId, string $tag, string $now): int
    {
        global $wpdb;
        $seq = random_int(1000000, 9999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients
                 (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            'MR-P7SR-' . $tag . '-' . $seq,
            'P7SR',
            $tag,
            '0912' . str_pad((string) ($seq % 10000000), 7, '0', STR_PAD_LEFT),
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $unique = $login . '_' . bin2hex(random_bytes(3));
        $userId = (int) wp_create_user($unique, wp_generate_password(22), $unique . '@p7sr.test');
        self::assertGreaterThan(0, $userId);
        $user = get_userdata($userId);
        self::assertNotFalse($user);
        $user->set_role($role);
        $this->userIds[] = $userId;

        return $userId;
    }

    /** @return array<string, mixed> */
    private function appointmentRow(int $id): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d LIMIT 1', [$id]);
        self::assertNotNull($row);

        return (array) $row;
    }

    /** @return array<string, mixed> */
    private function slotRow(int $id): array
    {
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d LIMIT 1', [$id]);
        self::assertNotNull($row);

        return (array) $row;
    }

    private function replacementCount(int $apptId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_appointments') . ' WHERE rescheduled_from = %d',
            [$apptId]
        );
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function purgeFixture(): void
    {
        if ($this->orgId <= 0) {
            return;
        }
        global $wpdb;
        $org = (int) $this->orgId;
        $wpdb->query('DELETE h FROM ' . $wpdb->prefix . 'cpms_visit_status_history h
                      JOIN ' . $wpdb->prefix . 'cpms_visits v ON v.id = h.visit_id
                      JOIN ' . $wpdb->prefix . 'cpms_clinics c ON c.id = v.clinic_id
                      WHERE c.organization_id = ' . $org);
        foreach ([
            'cpms_notifications',
            'cpms_sms_messages',
            'cpms_idempotency_keys',
            'cpms_audit_logs',
            'cpms_appointments',
            'cpms_visits',
            'cpms_schedule_slots',
            'cpms_clinicians',
            'cpms_patient_user_links',
            'cpms_clinic_memberships',
            'cpms_patients',
            'cpms_settings',
            'cpms_locations',
        ] as $table) {
            $wpdb->query('DELETE t FROM ' . $wpdb->prefix . $table . ' t
                          WHERE t.clinic_id IN (SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org . ')');
        }
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_clinics WHERE organization_id = ' . $org);
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = ' . $org);
        foreach ($this->userIds as $userId) {
            $wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
            $wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
        }
    }
}

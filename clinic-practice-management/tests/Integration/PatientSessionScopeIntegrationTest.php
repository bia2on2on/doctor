<?php

/**
 * Clinic-scoped patient session authority — end-to-end integration coverage.
 *
 * Covers the three surfaces that consume PatientSessionScope:
 *
 *  - M11 session stamping: OTP cookie issue arms `cpms_clinic_id` onto the
 *    issued WP_Session_Tokens token; rotation across Clinics re-stamps the
 *    newest token; non-OTP sessions (wp_signon) are never stamped; clearing
 *    the auth cookie drops in-process authority.
 *  - M5 patient booking scope: the 0 / 1 / N-link session matrix, the
 *    clinic-name-free CLINIC_SCOPE_REQUIRED exception, REST /appointments/mine
 *    fail-closed behaviour, and the ownership-before-session ordering for
 *    cancel and patient_change_deadlines (missing 404 -> unowned 403 ->
 *    mismatched session 400).
 *  - M6 patient inbox: clinic-filtered reads, fail-closed multi-link reads,
 *    cross-Clinic markRead as a no-op, and hostile ambient ScopeContext.
 *  - D13 booking guards: hold, reschedule-options and patient reschedule refuse a
 *    session stamped for another Clinic, leave every counter, hold, appointment
 *    and idempotency row unchanged, and pass the guard for the matching Clinic.
 *  - Notification routing by account type (staff role, linked user without role,
 *    patient role without link, multi-Clinic without marker, resolved-Clinic markRead).
 *
 * Application-password exclusion is covered in the pure-PHP Unit suite
 * (PatientSessionScopeUnitTest) through the app_password_check seam, because
 * did_action() is process-global and cannot be safely toggled here.
 */

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\PatientSessionScope;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\SystemClinicResolver;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

final class PatientSessionScopeIntegrationTest extends WP_UnitTestCase
{
    private const TZ = 'Asia/Tehran';
    private const FUTURE_DATE = '2031-03-10';
    private const FUTURE_TIME = '10:00';

    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locationA = 0;
    private int $locationB = 0;
    private int $clinicianB = 0;

    // ================= Lifecycle =================

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        wp_set_current_user(0);
        ScopeContext::clear();
        App::resetScope();
        App::patient_session_scope()->reset_in_process_state();
        App::patient_session_scope()->register_hooks();
        SystemClinicResolver::flush();
        Settings::flushCache();

        $this->orgId = 0;
        $this->clinicA = 0;
        $this->clinicB = 0;
        $this->locationA = 0;
        $this->locationB = 0;
        $this->clinicianB = 0;
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        ScopeContext::clear();
        App::patient_session_scope()->reset_in_process_state();
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        App::resetScope();
        SystemClinicResolver::flush();
        Settings::flushCache();

        parent::tearDown();
    }

    // =================================================================
    // M11 — OTP session stamping, rotation, non-OTP sessions, logout
    // =================================================================

    public function testOtpCookieIssueStampsClinicOnNewSessionToken(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m11_stamp', RolesAndCapabilities::ROLE_PATIENT);
        $scope = App::patient_session_scope();

        $token = \WP_Session_Tokens::get_instance($uid)->create(time() + DAY_IN_SECONDS);

        $scope->begin_otp_cookie_issue($uid, $this->clinicA);
        try {
            $scope->on_set_auth_cookie('', 0, 0, $uid, 'auth', $token);
        } finally {
            $scope->end_otp_cookie_issue();
        }

        $session = \WP_Session_Tokens::get_instance($uid)->get($token);
        self::assertIsArray($session);
        self::assertSame($this->clinicA, (int) ($session[PatientSessionScope::SESSION_CLINIC_KEY] ?? 0));
        self::assertSame($this->clinicA, $scope->stamped_session_clinic_id($uid));
    }

    public function testRotationAcrossClinicsResolvesToNewestStamp(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m11_rotate', RolesAndCapabilities::ROLE_PATIENT);
        $scope = App::patient_session_scope();
        $manager = \WP_Session_Tokens::get_instance($uid);

        $tokenA = $manager->create(time() + DAY_IN_SECONDS);
        $scope->begin_otp_cookie_issue($uid, $this->clinicA);
        try {
            $scope->on_set_auth_cookie('', 0, 0, $uid, 'auth', $tokenA);
        } finally {
            $scope->end_otp_cookie_issue();
        }
        self::assertSame($this->clinicA, $scope->stamped_session_clinic_id($uid));

        $tokenB = $manager->create(time() + DAY_IN_SECONDS);
        $scope->begin_otp_cookie_issue($uid, $this->clinicB);
        try {
            $scope->on_set_auth_cookie('', 0, 0, $uid, 'auth', $tokenB);
        } finally {
            $scope->end_otp_cookie_issue();
        }

        $newest = $manager->get($tokenB);
        self::assertIsArray($newest);
        self::assertSame($this->clinicB, (int) ($newest[PatientSessionScope::SESSION_CLINIC_KEY] ?? 0));
        self::assertSame($this->clinicB, $scope->stamped_session_clinic_id($uid), 'rotation must re-bind authority to the newest Clinic.');
    }

    public function testWpSignonWithoutPendingOtpIsNeverStamped(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m11_signon', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'SG'), $uid);
        $scope = App::patient_session_scope();

        $token = \WP_Session_Tokens::get_instance($uid)->create(time() + DAY_IN_SECONDS);
        $scope->set_in_process_session_token($uid, $token);
        $scope->on_set_auth_cookie('', 0, 0, $uid, 'auth', $token);

        $session = \WP_Session_Tokens::get_instance($uid)->get($token);
        self::assertIsArray($session);
        self::assertArrayNotHasKey(PatientSessionScope::SESSION_CLINIC_KEY, $session, 'wp_signon sessions must not carry a clinic marker.');
        self::assertNull($scope->stamped_session_clinic_id($uid));
        self::assertSame($this->clinicA, $scope->resolve_clinic_id($uid), 'unmarked single-link session falls back to the one linked Clinic.');
    }

    public function testClearAuthCookieDropsInProcessAuthority(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m11_logout', RolesAndCapabilities::ROLE_PATIENT);
        $scope = App::patient_session_scope();

        cpms_test_seed_patient_session($uid, $this->clinicB);
        self::assertSame($this->clinicB, $scope->stamped_session_clinic_id($uid));

        // Logout: the browser drops the auth cookie, so the request no longer carries it.
        unset($_COOKIE[LOGGED_IN_COOKIE]);
        do_action('clear_auth_cookie');

        self::assertNull($scope->stamped_session_clinic_id($uid), 'clear_auth_cookie must drop in-process session authority.');
    }

    // =================================================================
    // M5 — 0 / 1 / N link matrix and clinic-name-free fail-closed errors
    // =================================================================

    public function testSessionMatrixZeroOneAndMultiLink(): void
    {
        $this->buildTwoClinicFixture();
        $scope = App::patient_session_scope();

        $zero = $this->makeUser('m5_zero', RolesAndCapabilities::ROLE_PATIENT);
        self::assertNull($scope->require_clinic_for_read($zero), '0 links: read returns null (empty result, not an error).');
        self::assertNull($scope->resolve_clinic_id($zero));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_write($zero));

        $one = $this->makeUser('m5_one', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'ONE'), $one);
        self::assertSame($this->clinicA, $scope->require_clinic_for_read($one));
        self::assertSame($this->clinicA, $scope->require_clinic_for_write($one));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_matching($one, 0));
        self::assertSame($this->clinicA, $scope->require_clinic_matching($one, $this->clinicA));
        $this->assertScopeRequired(fn () => $scope->require_clinic_matching($one, $this->clinicB));

        $multi = $this->makeUser('m5_multi', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'MA'), $multi);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'MB'), $multi);
        self::assertNull($scope->resolve_clinic_id($multi));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_read($multi));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_write($multi));

        cpms_test_seed_patient_session($multi, $this->clinicB);
        self::assertSame($this->clinicB, $scope->require_clinic_for_read($multi), 'stamped marker resolves a multi-link user.');
        self::assertSame($this->clinicB, $scope->require_clinic_matching($multi, $this->clinicB));
        $this->assertScopeRequired(fn () => $scope->require_clinic_matching($multi, $this->clinicA));
    }

    public function testScopeRequiredExceptionIsClinicNameFree(): void
    {
        $this->buildTwoClinicFixture();
        $scope = App::patient_session_scope();
        $multi = $this->makeUser('m5_names', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'NA'), $multi);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'NB'), $multi);

        try {
            $scope->require_clinic_for_read($multi);
            self::fail('Expected CLINIC_SCOPE_REQUIRED for an unmarked multi-Clinic session.');
        } catch (ScopeRequiredException $e) {
            self::assertSame('CLINIC_SCOPE_REQUIRED', $e->apiCode());
            self::assertSame(400, $e->httpStatus());
            self::assertSame([], $e->getData());
            self::assertSame(PatientSessionScope::SCOPE_REQUIRED_MESSAGE, $e->getMessage());
            self::assertStringNotContainsString('M5M11', $e->getMessage(), 'exception must not name Clinics.');
        }
    }

    public function testRestMineFailsClosedOnMultiLinkWithoutMarker(): void
    {
        $this->buildTwoClinicFixture();

        $zero = $this->makeUser('m5_rest_zero', RolesAndCapabilities::ROLE_PATIENT);
        wp_set_current_user($zero);
        $ok = rest_do_request($this->mineRequest());
        self::assertSame(200, $ok->get_status());
        self::assertSame([], $ok->get_data()['data'] ?? null, '0 links returns an empty list, not an error.');

        $multi = $this->makeUser('m5_rest_multi', RolesAndCapabilities::ROLE_PATIENT);
        $patientA = $this->insertPatient($this->clinicA, 'RA');
        $patientB = $this->insertPatient($this->clinicB, 'RB');
        $this->linkPatient($this->clinicA, $patientA, $multi);
        $this->linkPatient($this->clinicB, $patientB, $multi);
        // One appointment per Clinic for the same patient user. The date must sit
        // inside the endpoint's default window (today .. today + 365 days).
        $date = gmdate('Y-m-d', time() + 30 * 86400);
        $clinicianA = $this->insertClinician($this->clinicA, 'RA');
        $apptA = $this->insertAppointment($this->clinicA, $this->locationA, $patientA, $clinicianA, $date, '10:00');
        $apptB = $this->insertAppointment($this->clinicB, $this->locationB, $patientB, $this->clinicianB, $date, '11:00');
        wp_set_current_user($multi);
        $blocked = rest_do_request($this->mineRequest());
        self::assertSame(400, $blocked->get_status());
        self::assertSame('CLINIC_SCOPE_REQUIRED', (string) ($blocked->get_data()['code'] ?? ''));

        cpms_test_seed_patient_session($multi, $this->clinicA);
        $scoped = rest_do_request($this->mineRequest());
        self::assertSame(200, $scoped->get_status(), 'stamped session resolves the multi-link user.');
        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $scoped->get_data()['data'] ?? []
        );
        self::assertContains($apptA, $ids, 'clinic A session must list the clinic A appointment.');
        self::assertNotContains($apptB, $ids, 'clinic A session must not list the clinic B appointment.');
        self::assertSame([$apptA], $ids, 'clinic A session returns exactly the clinic A appointment.');
    }

    /**
     * Ordering contract: (a) missing => 404 CLINIC_NOT_FOUND, (b) unowned => 403
     * CLINIC_PERMISSION_DENIED, (c) owned but session bound to another Clinic =>
     * CLINIC_SCOPE_REQUIRED. The appointment must remain untouched in all three.
     */
    public function testCancelAndDeadlinesOrderMissingUnownedAndMismatchedSession(): void
    {
        $this->buildTwoClinicFixture();
        $ownerB = $this->makeUser('m5_owner_b', RolesAndCapabilities::ROLE_PATIENT);
        $patientB = $this->insertPatient($this->clinicB, 'OB');
        $this->linkPatient($this->clinicB, $patientB, $ownerB);
        $patientA = $this->insertPatient($this->clinicA, 'OA');
        $this->linkPatient($this->clinicA, $patientA, $ownerB);

        $apptB = $this->insertAppointment($this->clinicB, $this->locationB, $patientB, $this->clinicianB, self::FUTURE_DATE, self::FUTURE_TIME);

        $stranger = $this->makeUser('m5_stranger', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'SA'), $stranger);

        $service = App::bookingService();

        // (a) missing object.
        cpms_test_seed_patient_session($ownerB, $this->clinicB);
        self::assertSame('CLINIC_NOT_FOUND', $this->errorCodeOf(static fn () => $service->cancelByPatient($ownerB, 999999991, null)));

        // (b) unowned: stranger has no active patient in Clinic B.
        cpms_test_seed_patient_session($stranger, $this->clinicA);
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCodeOf(static fn () => $service->cancelByPatient($stranger, $apptB, null)));
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errorCodeOf(static fn () => $service->patient_change_deadlines($stranger, $apptB)));

        // (c) owned, but the session is bound to Clinic A.
        cpms_test_seed_patient_session($ownerB, $this->clinicA);
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCodeOf(static fn () => $service->cancelByPatient($ownerB, $apptB, null)));
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCodeOf(static fn () => $service->patient_change_deadlines($ownerB, $apptB)));

        $status = (string) App::db()->fetchRow(
            'SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptB]
        )['status'];
        self::assertSame('confirmed', $status, 'refused scope/ownership attempts must not mutate the appointment.');
    }

    public function testOwnedAppointmentDeadlinesAreReadableUnderMatchingSession(): void
    {
        $this->buildTwoClinicFixture();
        $owner = $this->makeUser('m5_deadlines', RolesAndCapabilities::ROLE_PATIENT);
        $patientB = $this->insertPatient($this->clinicB, 'DL');
        $this->linkPatient($this->clinicB, $patientB, $owner);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'DA'), $owner);
        $apptB = $this->insertAppointment($this->clinicB, $this->locationB, $patientB, $this->clinicianB, self::FUTURE_DATE, self::FUTURE_TIME);

        cpms_test_seed_patient_session($owner, $this->clinicB);
        $deadlines = App::bookingService()->patient_change_deadlines($owner, $apptB);

        self::assertArrayHasKey('cancel_allowed', $deadlines);
        self::assertArrayHasKey('reschedule_allowed', $deadlines);
    }

    // =================================================================
    // M6 — patient inbox, markRead, hostile ambient ScopeContext
    // =================================================================

    public function testInboxIsClinicFilteredForStampedSession(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m6_inbox', RolesAndCapabilities::ROLE_PATIENT);
        $patientA = $this->insertPatient($this->clinicA, 'IA');
        $patientB = $this->insertPatient($this->clinicB, 'IB');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $patientB, $uid);

        $notifA = App::notificationService()->publishToPatient($this->clinicA, $patientA, 'appt_reminder', $this->apptVars(), null);
        $notifB = App::notificationService()->publishToPatient($this->clinicB, $patientB, 'appt_reminder', $this->apptVars(), null);
        self::assertNotNull($notifA);
        self::assertNotNull($notifB);

        cpms_test_seed_patient_session($uid, $this->clinicA);
        $ids = $this->inboxIds($uid);
        self::assertContains((int) $notifA, $ids);
        self::assertNotContains((int) $notifB, $ids, 'Clinic B notifications must not leak into a Clinic A session.');
    }

    public function testInboxFailsClosedOnMultiLinkWithoutMarker(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m6_multi', RolesAndCapabilities::ROLE_PATIENT);
        $patientA = $this->insertPatient($this->clinicA, 'FA');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'FB'), $uid);
        App::notificationService()->publishToPatient($this->clinicA, $patientA, 'appt_reminder', $this->apptVars(), null);

        $service = App::notificationService();
        $this->assertScopeRequired(static fn () => $service->inbox($uid, false, 20));
        $this->assertScopeRequired(static fn () => $service->markRead($uid, [], true));
    }

    public function testMarkReadCrossClinicIdsAreNoOp(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m6_markread', RolesAndCapabilities::ROLE_PATIENT);
        $patientA = $this->insertPatient($this->clinicA, 'MRA');
        $patientB = $this->insertPatient($this->clinicB, 'MRB');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $patientB, $uid);

        $notifA = (int) App::notificationService()->publishToPatient($this->clinicA, $patientA, 'appt_reminder', $this->apptVars(), null);
        $notifB = (int) App::notificationService()->publishToPatient($this->clinicB, $patientB, 'appt_reminder', $this->apptVars(), null);

        cpms_test_seed_patient_session($uid, $this->clinicA);
        $rowBBefore = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d', [$notifB]);

        $changedForeign = App::notificationService()->markRead($uid, [$notifB], false);
        self::assertSame(0, $changedForeign, 'a Clinic B notification id under a Clinic A session must not be marked.');

        $rowBAfter = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d', [$notifB]);
        self::assertSame($rowBBefore, $rowBAfter);

        $changedOwn = App::notificationService()->markRead($uid, [$notifA], false);
        self::assertSame(1, $changedOwn, 'positive control: own Clinic A notification is marked.');
    }

    public function testHostileAmbientScopeDoesNotWidenPatientInbox(): void
    {
        $this->buildTwoClinicFixture();
        $uid = $this->makeUser('m6_hostile', RolesAndCapabilities::ROLE_PATIENT);
        $patientA = $this->insertPatient($this->clinicA, 'HA');
        $patientB = $this->insertPatient($this->clinicB, 'HB');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $patientB, $uid);

        $notifA = (int) App::notificationService()->publishToPatient($this->clinicA, $patientA, 'appt_reminder', $this->apptVars(), null);
        $notifB = (int) App::notificationService()->publishToPatient($this->clinicB, $patientB, 'appt_reminder', $this->apptVars(), null);

        cpms_test_seed_patient_session($uid, $this->clinicA);
        ScopeContext::set(ClinicScope::forClinic($this->clinicB));
        try {
            $ids = $this->inboxIds($uid);
        } finally {
            ScopeContext::clear();
        }

        self::assertContains($notifA, $ids);
        self::assertNotContains($notifB, $ids, 'pure-patient actors must ignore ambient ScopeContext.');
    }

    // =================================================================
    // D13 — booking operations under a session stamped for another Clinic.
    // Each guard runs before any write, so a refused call must leave the slot
    // counters, the appointment row, the hold rows and the idempotency rows
    // exactly as they were. The positive control in each test uses a session
    // stamped for the slot's own Clinic and must pass the same guard.
    // =================================================================

    public function testHoldOnClinicASlotRefusesSessionStampedForClinicB(): void
    {
        $this->buildTwoClinicFixture();
        $clinicianA = $this->insertClinician($this->clinicA, 'D13 hold A');
        $uid        = $this->makeUser('d13_hold', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'D13HA'), $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'D13HB'), $uid);
        $locationA = $this->locationA;
        $slotId    = $this->insertOpenSlot($this->clinicA, $locationA, $clinicianA, self::FUTURE_DATE, '11:00');
        $before    = $this->bookingStateSnapshot([$slotId], []);
        $service   = App::bookingService();

        // Refused: the session is stamped for Clinic B, the persisted slot is in Clinic A.
        cpms_test_seed_patient_session($uid, $this->clinicB);
        $denied = $this->bookingExceptionOf(
            fn () => $service->hold($uid, $clinicianA, self::FUTURE_DATE, '11:00', $slotId, null, $locationA)
        );
        $this->assertScopeRequiredException($denied);
        self::assertSame(
            $before,
            $this->bookingStateSnapshot([$slotId], []),
            'a refused hold must leave slot counters, hold rows and idempotency rows unchanged.'
        );

        // Positive control: the same slot with a session stamped for Clinic A passes the guard.
        cpms_test_seed_patient_session($uid, $this->clinicA);
        $hold = $service->hold($uid, $clinicianA, self::FUTURE_DATE, '11:00', $slotId, null, $locationA);
        self::assertNotSame('', (string) ($hold['hold_token'] ?? ''), 'positive control: the matching-Clinic session creates the hold.');
        self::assertSame(1, (int) $this->slotRow($slotId)['held_count'], 'positive control: exactly one held unit.');
    }

    public function testRescheduleOptionsOnClinicAAppointmentRefusesSessionStampedForClinicB(): void
    {
        $this->buildTwoClinicFixture();
        $clinicianA = $this->insertClinician($this->clinicA, 'D13 options A');
        $uid        = $this->makeUser('d13_options', RolesAndCapabilities::ROLE_PATIENT);
        $patientA   = $this->insertPatient($this->clinicA, 'D13OA');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'D13OB'), $uid);
        $apptId  = $this->insertAppointment($this->clinicA, $this->locationA, $patientA, $clinicianA, self::FUTURE_DATE, '10:00');
        $slotId  = $this->appointmentSlotId($apptId);
        $before  = $this->bookingStateSnapshot([$slotId], [$apptId]);
        $service = App::bookingService();

        // Refused: the appointment belongs to Clinic A, the session is stamped for Clinic B.
        cpms_test_seed_patient_session($uid, $this->clinicB);
        $denied = $this->bookingExceptionOf(fn () => $service->reschedule_options($uid, $apptId));
        $this->assertScopeRequiredException($denied);
        self::assertSame(
            $before,
            $this->bookingStateSnapshot([$slotId], [$apptId]),
            'refused reschedule-options must leave the appointment row, slot counters and idempotency rows unchanged.'
        );

        // Positive control: the same appointment with a session stamped for Clinic A passes the guard.
        cpms_test_seed_patient_session($uid, $this->clinicA);
        $options = $service->reschedule_options($uid, $apptId);
        self::assertSame($apptId, (int) ($options['appointment_id'] ?? 0), 'positive control: the matching-Clinic session returns the options for this appointment.');
    }

    public function testPatientRescheduleOfClinicAAppointmentRefusesSessionStampedForClinicB(): void
    {
        $this->buildTwoClinicFixture();
        $clinicianA = $this->insertClinician($this->clinicA, 'D13 resched A');
        $uid        = $this->makeUser('d13_resched', RolesAndCapabilities::ROLE_PATIENT);
        $patientA   = $this->insertPatient($this->clinicA, 'D13RA');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'D13RB'), $uid);
        $apptId       = $this->insertAppointment($this->clinicA, $this->locationA, $patientA, $clinicianA, self::FUTURE_DATE, '10:00');
        $sourceSlotId = $this->appointmentSlotId($apptId);
        $destSlotId   = $this->insertOpenSlot($this->clinicA, $this->locationA, $clinicianA, self::FUTURE_DATE, '12:00');
        $before       = $this->bookingStateSnapshot([$sourceSlotId, $destSlotId], [$apptId]);
        $service      = App::bookingService();

        // Refused at the source-appointment preflight, before any idempotency claim.
        cpms_test_seed_patient_session($uid, $this->clinicB);
        $denied = $this->bookingExceptionOf(
            fn () => $service->reschedule($uid, $apptId, $clinicianA, self::FUTURE_DATE, '12:00', wp_generate_uuid4(), $destSlotId)
        );
        $this->assertScopeRequiredException($denied);
        self::assertSame(
            $before,
            $this->bookingStateSnapshot([$sourceSlotId, $destSlotId], [$apptId]),
            'a refused patient reschedule must leave the appointment row, both slot counters and idempotency rows unchanged.'
        );

        // Positive control: the same reschedule with a session stamped for Clinic A passes the guard and moves the booking.
        cpms_test_seed_patient_session($uid, $this->clinicA);
        $result = $service->reschedule($uid, $apptId, $clinicianA, self::FUTURE_DATE, '12:00', wp_generate_uuid4(), $destSlotId);
        self::assertSame($apptId, (int) $result['previous_appointment_id'], 'positive control: the replacement points back at the source appointment.');
        self::assertNotSame($apptId, (int) $result['appointment_id'], 'positive control: a replacement appointment is created.');
        self::assertSame(0, (int) $this->slotRow($sourceSlotId)['booked_count'], 'positive control: the source slot is released.');
        self::assertSame(1, (int) $this->slotRow($destSlotId)['booked_count'], 'positive control: the destination slot is booked.');
    }

    // =================================================================
    // Notification routing by account type (merged tree). These tests
    // document the current behaviour; PR #326 body, section 3a.
    // =================================================================

    public function testStaffRoleWithActivePatientLinkTakesStaffPath(): void
    {
        $this->buildTwoClinicFixture();
        $uid       = $this->makeUser('n_staff_link', RolesAndCapabilities::ROLE_SECRETARY);
        $patientId = $this->insertPatient($this->clinicA, 'NSL');
        $this->linkPatient($this->clinicA, $patientId, $uid);
        $service       = App::notificationService();
        $patientNotif  = (int) $service->publishToPatient($this->clinicA, $patientId, 'appt_reminder', $this->apptVars(), null);
        $staffNotif    = (int) $service->publishToUser($this->clinicA, $uid, 'report_export_ready', $this->reportVars(), null);
        self::assertGreaterThan(0, $patientNotif, 'fixture: patient-addressed notification is published.');
        self::assertGreaterThan(0, $staffNotif, 'fixture: user-addressed notification is published.');

        // Without an ambient scope the staff path resolves App::scope(), which fails closed on a
        // multi-Clinic install. The patient path would have resolved the single linked Clinic.
        $this->assertScopeRequired(static fn () => $service->inbox($uid, false, 20));

        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
        try {
            $ids = $this->inboxIds($uid);
            self::assertContains($staffNotif, $ids, 'staff path lists the staff-addressed notification.');
            self::assertNotContains($patientNotif, $ids, 'staff path does not list the patient-addressed notification.');
            self::assertSame(0, $service->markRead($uid, [$patientNotif], false), 'staff path cannot mark a patient-addressed row.');
            self::assertSame(1, $service->markRead($uid, [$staffNotif], false), 'staff path marks its own staff-addressed row.');
        } finally {
            ScopeContext::clear();
        }
        self::assertNull($this->readAt($patientNotif), 'the patient-addressed row stays unread.');
        self::assertNotNull($this->readAt($staffNotif), 'the staff-addressed row is read.');
    }

    public function testLinkedUserWithoutPatientOrStaffRoleFollowsAmbientScope(): void
    {
        $this->buildTwoClinicFixture();
        $uid       = $this->makeUser('n_bare_link', 'subscriber');
        $patientId = $this->insertPatient($this->clinicA, 'NBL');
        $this->linkPatient($this->clinicA, $patientId, $uid);
        $service      = App::notificationService();
        $patientNotif = (int) $service->publishToPatient($this->clinicA, $patientId, 'appt_reminder', $this->apptVars(), null);
        $staffNotif   = (int) $service->publishToUser($this->clinicA, $uid, 'report_export_ready', $this->reportVars(), null);

        // No ambient ScopeContext: patient path (the user has one linked Clinic).
        $ids = $this->inboxIds($uid);
        self::assertContains($patientNotif, $ids, 'no ambient scope: patient path lists the linked patient notification.');
        self::assertNotContains($staffNotif, $ids, 'no ambient scope: patient path does not list the user-addressed notification.');

        // With an ambient ScopeContext: staff path (current behaviour; the ambient scope decides).
        ScopeContext::set(ClinicScope::forClinic($this->clinicA));
        try {
            $ids = $this->inboxIds($uid);
            self::assertContains($staffNotif, $ids, 'ambient scope: staff path lists the user-addressed notification.');
            self::assertNotContains($patientNotif, $ids, 'ambient scope: staff path does not list the patient notification.');
        } finally {
            ScopeContext::clear();
        }
    }

    public function testPatientRoleWithoutActiveLinkTakesPatientPathAndSeesNothing(): void
    {
        $this->buildTwoClinicFixture();
        $service = App::notificationService();

        // (1) Patient role, no link at all.
        $uid        = $this->makeUser('n_patient_nolink', RolesAndCapabilities::ROLE_PATIENT);
        $staffNotif = (int) $service->publishToUser($this->clinicA, $uid, 'report_export_ready', $this->reportVars(), null);
        self::assertGreaterThan(0, $staffNotif, 'fixture: user-addressed notification is published.');

        // No exception and empty results: the patient path with zero links. The staff path
        // would have thrown here, because a multi-Clinic install has no implicit scope.
        $result = $service->inbox($uid, false, 20);
        self::assertSame([], $result['notifications'], 'zero-link patient: inbox is empty.');
        self::assertSame(0, (int) $result['unread_count']);
        self::assertSame(0, $service->lastId($uid), 'zero-link patient: lastId is 0.');
        $since = $service->since($uid, 0);
        self::assertSame([], $since['notifications'], 'zero-link patient: since is empty.');
        self::assertSame(0, (int) $since['last_id']);
        // markRead resolves a Clinic for writes, so a zero-link patient fails closed here.
        $this->assertScopeRequired(static fn () => $service->markRead($uid, [$staffNotif], false));
        self::assertNull($this->readAt($staffNotif), 'refused markRead leaves the row unread.');

        // (2) Patient role, link to an inactive patient: a Clinic resolves, but no active patient does.
        $uid2            = $this->makeUser('n_patient_inactive', RolesAndCapabilities::ROLE_PATIENT);
        $inactivePatient = $this->insertPatient($this->clinicA, 'NIN');
        $this->linkPatient($this->clinicA, $inactivePatient, $uid2);
        App::db()->execute(
            'UPDATE ' . App::db()->table('cpms_patients') . ' SET status = %s WHERE id = %d',
            ['inactive', $inactivePatient]
        );
        $inactiveNotif = (int) $service->publishToPatient($this->clinicA, $inactivePatient, 'appt_reminder', $this->apptVars(), null);
        self::assertSame([], $this->inboxIds($uid2), 'inactive-link patient: inbox is empty.');
        self::assertSame(0, $service->markRead($uid2, [$inactiveNotif], false), 'inactive-link patient: markRead changes nothing and does not throw.');
        self::assertNull($this->readAt($inactiveNotif));
    }

    public function testMultiClinicPatientWithoutMarkerFailsClosedOnEveryNotificationRead(): void
    {
        $this->buildTwoClinicFixture();
        $uid      = $this->makeUser('n_multi_nomarker', RolesAndCapabilities::ROLE_PATIENT);
        $patientA = $this->insertPatient($this->clinicA, 'NMA');
        $this->linkPatient($this->clinicA, $patientA, $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'NMB'), $uid);
        $service = App::notificationService();
        $notif   = (int) $service->publishToPatient($this->clinicA, $patientA, 'appt_reminder', $this->apptVars(), null);
        self::assertGreaterThan(0, $notif, 'fixture: patient-addressed notification is published.');

        // No session marker, two linked Clinics: every read and every write throws, never a silent empty result.
        $this->assertScopeRequired(static fn () => $service->inbox($uid, false, 20));
        $this->assertScopeRequired(static fn () => $service->since($uid, 0));
        $this->assertScopeRequired(static fn () => $service->lastId($uid));
        $this->assertScopeRequired(static fn () => $service->markRead($uid, [], true));
        $this->assertScopeRequired(static fn () => $service->markRead($uid, [$notif], false));
        self::assertNull($this->readAt($notif), 'refused markRead leaves the row unread.');
    }

    public function testMarkReadAllInResolvedClinicMarksEveryActiveLinkedPatientThere(): void
    {
        $this->buildTwoClinicFixture();
        $uid       = $this->makeUser('n_resolved_all', RolesAndCapabilities::ROLE_PATIENT);
        $a1        = $this->insertPatient($this->clinicA, 'RA1');
        $a2        = $this->insertPatient($this->clinicA, 'RA2');
        $a3        = $this->insertPatient($this->clinicA, 'RA3');
        $b1        = $this->insertPatient($this->clinicB, 'RB1');
        $unlinked  = $this->insertPatient($this->clinicA, 'RX');
        $this->linkPatient($this->clinicA, $a1, $uid);
        $this->linkPatient($this->clinicA, $a2, $uid);
        $this->linkPatient($this->clinicA, $a3, $uid);
        $this->linkPatient($this->clinicB, $b1, $uid);
        App::db()->execute(
            'UPDATE ' . App::db()->table('cpms_patients') . ' SET status = %s WHERE id = %d',
            ['inactive', $a3]
        );

        $nA1 = $this->publishTo($this->clinicA, $a1);
        $nA2 = $this->publishTo($this->clinicA, $a2);
        $nA3 = $this->publishTo($this->clinicA, $a3);
        $nB1 = $this->publishTo($this->clinicB, $b1);
        $nX  = $this->publishTo($this->clinicA, $unlinked);

        // Clinic resolved by the session marker (current: Clinic A is a live link).
        cpms_test_seed_patient_session($uid, $this->clinicA);
        $changed = App::notificationService()->markRead($uid, [], true);

        // Documented behaviour: all active linked patients of the resolved Clinic, not only the primary one.
        self::assertSame(2, $changed, 'resolved Clinic A marks the two active linked patients there.');
        self::assertNotNull($this->readAt($nA1), 'active linked patient A1 is marked.');
        self::assertNotNull($this->readAt($nA2), 'active linked patient A2 is marked.');
        self::assertNull($this->readAt($nA3), 'linked but inactive patient A3 is not marked.');
        self::assertNull($this->readAt($nB1), 'the linked patient in Clinic B is not marked.');
        self::assertNull($this->readAt($nX), 'an unlinked patient in Clinic A is not marked.');
    }

    // =================================================================
    // Helpers
    // =================================================================

    // =================================================================
    // Cookie-only resolution: the real auth cookie is the only binding.
    // No in-process token is injected, so PatientSessionScope must validate
    // the logged_in cookie through WordPress core to see the session at all.
    // =================================================================

    public function testCookieOnlyStampedMarkerResolvesMultiLinkUser(): void
    {
        $this->buildTwoClinicFixture();
        $scope = App::patient_session_scope();
        $uid   = $this->makeUser('m5_cookie_multi', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'CKA'), $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'CKB'), $uid);

        $this->bindCookieOnlySession($uid, $this->clinicB);

        self::assertSame($this->clinicB, $scope->stamped_session_clinic_id($uid), 'marker read through the cookie-validated session.');
        self::assertSame($this->clinicB, $scope->require_clinic_for_read($uid));
        self::assertSame($this->clinicB, $scope->require_clinic_matching($uid, $this->clinicB));
        $this->assertScopeRequired(fn () => $scope->require_clinic_matching($uid, $this->clinicA));
    }

    public function testCookieOnlyWithoutMarkerMultiLinkFailsClosed(): void
    {
        $this->buildTwoClinicFixture();
        $scope = App::patient_session_scope();
        $uid   = $this->makeUser('m5_cookie_unmarked', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'UMA'), $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'UMB'), $uid);

        $this->bindCookieOnlySession($uid, null);

        self::assertNull($scope->stamped_session_clinic_id($uid));
        self::assertNull($scope->resolve_clinic_id($uid));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_read($uid));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_write($uid));
    }

    public function testCookieOnlyStaleMarkerFailsClosedAfterLinkRemoved(): void
    {
        $this->buildTwoClinicFixture();
        $scope = App::patient_session_scope();
        $uid   = $this->makeUser('m5_cookie_stale', RolesAndCapabilities::ROLE_PATIENT);
        $this->linkPatient($this->clinicA, $this->insertPatient($this->clinicA, 'STA'), $uid);
        $this->linkPatient($this->clinicB, $this->insertPatient($this->clinicB, 'STB'), $uid);

        // Session stamped for Clinic A while the user is linked to both Clinics.
        $this->bindCookieOnlySession($uid, $this->clinicA);
        self::assertSame($this->clinicA, $scope->require_clinic_for_read($uid), 'precondition: marker current.');

        // Clinic A link is removed; only Clinic B remains live.
        App::db()->execute(
            'DELETE FROM ' . App::db()->table('cpms_patient_user_links') . ' WHERE clinic_id = %d AND wp_user_id = %d',
            [$this->clinicA, $uid]
        );

        // The raw marker is still on the session, but it no longer grants authority.
        self::assertSame($this->clinicA, $scope->stamped_session_clinic_id($uid));
        self::assertNull($scope->resolve_clinic_id($uid));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_read($uid));
        $this->assertScopeRequired(static fn () => $scope->require_clinic_for_write($uid));
        // Endpoint level (M6 inbox): fails closed rather than reading Clinic B silently.
        $this->assertScopeRequired(static fn () => App::notificationService()->since($uid, 0));
    }

    public function testCookieOnlyNewPatientWithoutLinksKeepsStampedClinic(): void
    {
        $this->buildTwoClinicFixture();
        $scope = App::patient_session_scope();
        // OTP-verified new patient: stamped for Clinic A, no link yet.
        $uid = $this->makeUser('m5_cookie_newpatient', RolesAndCapabilities::ROLE_PATIENT);

        $this->bindCookieOnlySession($uid, $this->clinicA);

        self::assertSame($this->clinicA, $scope->resolve_clinic_id($uid));
        self::assertSame($this->clinicA, $scope->require_clinic_for_read($uid));
        self::assertSame($this->clinicA, $scope->require_clinic_for_write($uid));
    }

    /**
     * Bind a session through a real logged_in cookie only. The in-process map
     * is cleared first, so nothing but WordPress core cookie validation can
     * resolve the session.
     */
    private function bindCookieOnlySession(int $uid, ?int $stampClinicId): void
    {
        App::patient_session_scope()->reset_in_process_state();

        $manager = \WP_Session_Tokens::get_instance($uid);
        $token   = $manager->create(time() + DAY_IN_SECONDS);
        if (null !== $stampClinicId) {
            $session = $manager->get($token);
            self::assertIsArray($session);
            $session = array_merge($session, App::patient_session_scope()->session_marker_for($uid, $stampClinicId));
            $manager->update($token, $session);
        }

        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($uid, time() + DAY_IN_SECONDS, 'logged_in', $token);
        self::assertSame($uid, (int) wp_validate_auth_cookie('', 'logged_in'), 'fixture: logged_in cookie validates.');
    }

    private function buildTwoClinicFixture(): void
    {
        $now = App::db()->nowUtcSql();
        $uid = uniqid('m5m11', false);

        App::db()->insert('cpms_organizations', [
            'name'       => 'Org M5M11 ' . $uid,
            'slug'       => 'org-m5m11-' . $uid,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->orgId = App::db()->wpdb_last_insert_id();

        $this->clinicA = $this->insertClinic('Clinic A M5M11 ' . $uid, 'clinic-a-m5m11-' . $uid, $now);
        $this->clinicB = $this->insertClinic('Clinic B M5M11 ' . $uid, 'clinic-b-m5m11-' . $uid, $now);
        $this->locationA = $this->insertLocation($this->clinicA, 'Main A M5M11', 'main-a-m5m11-' . $uid);
        $this->locationB = $this->insertLocation($this->clinicB, 'Main B M5M11', 'main-b-m5m11-' . $uid);

        $this->clinicianB = $this->insertFixtureRow('cpms_clinicians', [
            'clinic_id'  => $this->clinicB,
            'full_name'  => 'Dr M5M11 B ' . $uid,
            'wp_user_id' => null,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'clinician B');

        foreach ([$this->clinicA, $this->clinicB] as $clinicId) {
            $settings = new Settings(App::db(), $clinicId, App::audit());
            $settings->set('otp.cooldown_sec', 0);
        }
        Settings::flushCache();

        ScopeContext::clear();
        App::resetScope();

        self::assertGreaterThan(1, $this->clinicA, 'fixture Clinics are dynamic, never clinic 1.');
        self::assertNotSame($this->clinicA, $this->clinicB, 'precondition: two distinct Clinics.');
    }

    private function insertClinic(string $name, string $slug, string $now): int
    {
        App::db()->insert('cpms_clinics', [
            'organization_id' => $this->orgId,
            'name'            => $name,
            'slug'            => $slug,
            'timezone'        => self::TZ,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return App::db()->wpdb_last_insert_id();
    }

    private function insertLocation(int $clinicId, string $name, string $slug): int
    {
        App::db()->insert('cpms_locations', [
            'clinic_id'  => $clinicId,
            'name'       => $name,
            'slug'       => $slug,
            'timezone'   => self::TZ,
            'is_primary' => 1,
            'is_active'  => 1,
            'created_at' => App::db()->nowUtcSql(),
            'updated_at' => App::db()->nowUtcSql(),
        ]);

        return App::db()->wpdb_last_insert_id();
    }

    private function insertPatient(int $clinicId, string $tag): int
    {
        $now = App::db()->nowUtcSql();
        App::db()->insert('cpms_patients', [
            'clinic_id'  => $clinicId,
            'mrn'        => 'MR-M5M11-' . $tag . '-' . bin2hex(random_bytes(2)),
            'first_name' => 'Patient',
            'last_name'  => 'M5M11 ' . $tag,
            'mobile'     => '0912' . sprintf('%07d', random_int(1000000, 9999999)),
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return App::db()->wpdb_last_insert_id();
    }

    private function linkPatient(int $clinicId, int $patientId, int $wpUserId): int
    {
        App::db()->insert('cpms_patient_user_links', [
            'clinic_id'      => $clinicId,
            'patient_id'     => $patientId,
            'wp_user_id'     => $wpUserId,
            'mobile_at_link' => '09121234567',
            'is_primary'     => 1,
            'linked_at'      => App::db()->nowUtcSql(),
        ]);

        return App::db()->wpdb_last_insert_id();
    }

    /**
     * Fixture insert that fails loudly. The test DB is non-strict, so a rejected
     * insert returns false and leaves a 0 or stale insert_id behind; both checks
     * stop that from reaching the assertions as a plausible-looking id.
     *
     * @param array<string, mixed> $data
     */
    private function insertFixtureRow(string $table, array $data, string $what): int
    {
        self::assertTrue(App::db()->insert($table, $data), 'fixture: ' . $what . ' insert must succeed.');
        $id = App::db()->wpdb_last_insert_id();
        self::assertGreaterThan(0, $id, 'fixture: ' . $what . ' id must be > 0.');

        return $id;
    }

    private function insertClinician(int $clinicId, string $tag): int
    {
        $now = App::db()->nowUtcSql();

        return $this->insertFixtureRow('cpms_clinicians', [
            'clinic_id'  => $clinicId,
            'full_name'  => 'Dr M5M11 ' . $tag . ' ' . bin2hex(random_bytes(2)),
            // NULL = clinician without a login account. u_clinician_user (0007)
            // is UNIQUE on wp_user_id, so a literal 0 collides on the second row.
            'wp_user_id' => null,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], 'clinician ' . $tag);
    }

    private function insertAppointment(int $clinicId, int $locationId, int $patientId, int $clinicianId, string $date, string $time): int
    {
        $now = App::db()->nowUtcSql();
        $slotId = $this->insertFixtureRow('cpms_schedule_slots', [
            'clinic_id'      => $clinicId,
            'location_id'    => $locationId,
            'clinician_id'   => $clinicianId,
            'slot_date'      => $date,
            'slot_time'      => $time,
            'duration_min'   => 20,
            'capacity'       => 1,
            'booked_count'   => 1,
            'held_count'     => 0,
            'is_open'        => 1,
            'generated_from' => 'manual',
            'created_at'     => $now,
            'updated_at'     => $now,
        ], 'slot ' . $time);

        return $this->insertFixtureRow('cpms_appointments', [
            'clinic_id'         => $clinicId,
            'location_id'       => $locationId,
            'reference_code'    => 'm5m11-' . bin2hex(random_bytes(3)),
            'patient_id'        => $patientId,
            'clinician_id'      => $clinicianId,
            'slot_id'           => $slotId,
            'wp_user_id'        => 0,
            'slot_date'         => $date,
            'slot_time'         => $time,
            'duration_min'      => 20,
            'slot_end_time'     => $time,
            'status'            => 'confirmed',
            'reason'            => null,
            'is_walkin_express' => 0,
            'confirmed_at'      => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ], 'appointment ' . $time);
    }

    private function makeUser(string $login, string $role): int
    {
        $u  = $login . '_' . bin2hex(random_bytes(3));
        $id = (int) wp_create_user($u, 'pass-not-used-123', $u . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);

        return $id;
    }

    private function mineRequest(): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/clinic/v1/appointments/mine');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        return $request;
    }

    /**
     * @return list<int>
     */
    private function inboxIds(int $uid): array
    {
        $result = App::notificationService()->inbox($uid, false, 50);

        return array_values(array_map(static fn ($row): int => (int) $row['id'], $result['notifications']));
    }

    /**
     * @return array<string, string>
     */
    private function apptVars(): array
    {
        return [
            'patient_name'     => 'Notify',
            'doctor_name'      => 'Dr Notify',
            'appointment_date' => '1405-01-01',
            'appointment_time' => '10:00',
            'clinic_name'      => 'مطب',
        ];
    }

    private function errorCodeOf(callable $fn): string
    {
        try {
            $fn();
        } catch (ScopeRequiredException $e) {
            return $e->apiCode();
        } catch (BookingException $e) {
            return $e->errorCode;
        }

        return 'NO_EXCEPTION';
    }

    private function assertScopeRequired(callable $fn): void
    {
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errorCodeOf($fn));
    }

    /**
     * Open, unbooked slot (capacity 1). Unlike insertAppointment(), no appointment is created.
     */
    private function insertOpenSlot(int $clinicId, int $locationId, int $clinicianId, string $date, string $time): int
    {
        $now = App::db()->nowUtcSql();

        return $this->insertFixtureRow('cpms_schedule_slots', [
            'clinic_id'      => $clinicId,
            'location_id'    => $locationId,
            'clinician_id'   => $clinicianId,
            'slot_date'      => $date,
            'slot_time'      => $time,
            'duration_min'   => 20,
            'capacity'       => 1,
            'booked_count'   => 0,
            'held_count'     => 0,
            'is_open'        => 1,
            'generated_from' => 'manual',
            'created_at'     => $now,
            'updated_at'     => $now,
        ], 'open slot ' . $time);
    }

    private function appointmentSlotId(int $apptId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT slot_id FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d',
            [$apptId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function slotRow(int $slotId): array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d',
            [$slotId]
        );
        self::assertIsArray($row, 'fixture: slot row exists.');

        return $row;
    }

    /**
     * Full rows for the given slots and appointments, plus the table-level counts that a
     * refused booking call must not change (hold rows, appointment rows, idempotency rows).
     *
     * @param list<int> $slotIds
     * @param list<int> $apptIds
     * @return array<string, mixed>
     */
    private function bookingStateSnapshot(array $slotIds, array $apptIds): array
    {
        $db    = App::db();
        $state = [
            'slots'           => [],
            'appointments'    => [],
            'hold_rows'       => 0,
            'appointment_rows' => 0,
            'idempotency_rows' => 0,
        ];
        foreach ($slotIds as $slotId) {
            $state['slots'][$slotId] = $db->fetchRow(
                'SELECT * FROM ' . $db->table('cpms_schedule_slots') . ' WHERE id = %d',
                [$slotId]
            );
        }
        foreach ($apptIds as $apptId) {
            $state['appointments'][$apptId] = $db->fetchRow(
                'SELECT * FROM ' . $db->table('cpms_appointments') . ' WHERE id = %d',
                [$apptId]
            );
        }
        $state['hold_rows']        = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_slot_holds'));
        $state['appointment_rows'] = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_appointments'));
        $state['idempotency_rows'] = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_idempotency_keys'));

        return $state;
    }

    /**
     * Run a booking call that must be refused and return the BookingException it raised.
     * A ScopeRequiredException is normalised the same way BookingService does.
     */
    private function bookingExceptionOf(callable $fn): BookingException
    {
        try {
            $fn();
        } catch (BookingException $e) {
            return $e;
        } catch (ScopeRequiredException $e) {
            return BookingException::of($e->apiCode(), $e->getMessage(), $e->httpStatus(), $e->getData());
        }

        self::fail('expected a refused booking call, but it returned.');
    }

    private function assertScopeRequiredException(BookingException $e): void
    {
        self::assertSame('CLINIC_SCOPE_REQUIRED', $e->errorCode);
        self::assertSame(400, $e->httpStatus);
        self::assertSame(PatientSessionScope::SCOPE_REQUIRED_MESSAGE, $e->getMessage());
    }

    /**
     * @return array<string, mixed>
     */
    private function reportVars(): array
    {
        return [
            'report_label' => 'Test report',
            'expires_at'   => '1405-01-01',
        ];
    }

    private function publishTo(int $clinicId, int $patientId): int
    {
        return (int) App::notificationService()->publishToPatient($clinicId, $patientId, 'appt_reminder', $this->apptVars(), null);
    }

    /**
     * read_at of one notification row; null means unread.
     */
    private function readAt(int $notificationId): ?string
    {
        $value = App::db()->fetchValue(
            'SELECT read_at FROM ' . App::db()->table('cpms_notifications') . ' WHERE id = %d',
            [$notificationId]
        );

        return null === $value ? null : (string) $value;
    }
}

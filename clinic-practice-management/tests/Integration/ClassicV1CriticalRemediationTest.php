<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Jobs\SmsSendJobHandler;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Sms\SmsEvents;
use ClinicCore\Domain\Sms\SmsMessageStatus;
use ClinicCore\Infrastructure\Sms\SmsSendException;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use ClinicCore\Tests\Support\RecordingSmsProvider;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Executable regression contract for the Classic V1 installer leads.
 * The original installer report was unavailable; these fixtures reproduce the
 * supplied scenarios against the production plugin and a real WordPress DB.
 */
final class ClassicV1CriticalRemediationTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    private int $org;
    private int $clinic;
    private int $locationA;
    private int $locationB;
    private int $doctor;
    private int $clinician;
    private int $patient;
    private int $visit;
    private RecordingSmsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv1_boot();
        $this->org = $this->iv1_org('classic');
        $this->clinic = $this->iv1_clinic($this->org);
        $this->locationA = $this->iv1_location($this->clinic, 'A');
        $this->locationB = $this->iv1_location($this->clinic, 'B');
        $this->doctor = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'classic_doctor');
        $membership = $this->iv1_membership($this->doctor, $this->clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_restrict_to_location($membership, $this->locationA);
        $this->clinician = $this->iv1_clinician($this->clinic, $this->doctor);
        (new \ClinicCore\Infrastructure\Repository\MembershipRepository(App::db()))->replace_clinician_locations($this->clinician, [$this->locationA, $this->locationB], $this->locationA);
        $this->patient = $this->iv1_patient($this->clinic);
        $this->visit = $this->iv1_visit($this->clinic, $this->locationA, $this->patient, $this->clinician, 'in_consultation');
        $this->provider = new RecordingSmsProvider();
        App::providers()->register($this->provider);
        $this->iv1_scope($this->clinic, function (): void {
            App::settings()->set('sms.provider', 'classic_missing_provider');
            App::settings()->set('sms.templates', []);
        });
        wp_set_current_user($this->doctor);
    }

    protected function tearDown(): void
    {
        $this->iv1_reset();
        parent::tearDown();
    }

    public function testOtpDeliveryFailureIsNotHttpSuccessAndKeepsLimitsAndHashOnlyStorage(): void
    {
        $mobile = '0912' . random_int(1000000, 9999999);
        $response = $this->iv1_scope($this->clinic, fn () => $this->post('/otp/request', ['mobile' => $mobile]));
        self::assertSame(503, $response->get_status(), 'An undelivered OTP must not enter the code-waiting UI: ' . wp_json_encode($response->get_data()));
        self::assertSame('CLINIC_OTP_DELIVERY_FAILED', $this->iv1_code($response));
        $data = $response->get_data()['data'] ?? [];
        self::assertFalse($data['sms_sent'] ?? true);
        self::assertFalse($data['retry_enqueued'] ?? true);
        self::assertGreaterThan(0, (int) ($data['retry_after'] ?? 0));
        $row = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_otp_tokens') . ' WHERE mobile = %s', [$mobile]);
        self::assertNotNull($row);
        self::assertSame(64, strlen((string) $row['code_hash']));
        self::assertStringNotContainsString($mobile, (string) wp_json_encode($response->get_data()));
        $limited = $this->iv1_scope($this->clinic, fn () => $this->post('/otp/request', ['mobile' => $mobile]));
        self::assertSame(429, $limited->get_status(), 'Delivery failure must not bypass the existing cooldown.');
        self::assertSame('CLINIC_OTP_COOLDOWN', $this->iv1_code($limited));
    }

    public function testSuccessfulOtpStillReturnsSuccessWithoutSecretOrRetryPromise(): void
    {
        $this->iv1_scope($this->clinic, fn() => App::settings()->set('sms.provider', RecordingSmsProvider::ID));
        $response = $this->iv1_scope($this->clinic, fn () => $this->post('/otp/request', ['mobile' => '0912' . random_int(1000000, 9999999)]));
        self::assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $this->iv1_data($response);
        self::assertTrue($data['sms_sent']);
        self::assertFalse($data['retry_enqueued']);
        self::assertCount(1, $this->provider->textSends);
        self::assertStringNotContainsString('otp_code', (string) wp_json_encode($data));
    }

    public function testUnsafeSmsEndpointSaveIsPersianValidationWithoutPartialSettingsOrStaleVerifiedState(): void
    {
        $response = $this->saveUnsafeEndpoint();
        self::assertSame(400, $response->get_status());
        self::assertSame('CLINIC_SSRF_BLOCKED', $this->iv1_code($response));
        self::assertStringContainsString('داخلی', $response->get_data()['message'] ?? '');
        $status = $this->iv1_scope($this->clinic, fn() => App::smsService()->status());
        self::assertNotSame('VERIFIED', $status['state']);
        self::assertSame('', App::settings()->get('sms.provider', ''), 'Rejected save must not partially switch provider.');
        self::assertSame([], $this->provider->textSends, 'SSRF validation must precede network I/O.');
    }

    public function testUnsafeSmsConnectionTestBecomesSafeErrorAndClearsVerifiedIndicator(): void
    {
        $this->iv1_scope($this->clinic, function (): void {
            App::settings()->set('sms.provider', 'generic_api');
            App::settings()->set('sms.generic', ['endpoint' => 'http://127.0.0.1/internal']);
            App::settings()->set('sms.last_test', ['status' => 'ok', 'provider' => 'generic_api', 'message' => 'old success']);
        });
        $this->asSmsManager();
        $response = $this->post('/sms/test-connection', ['provider' => 'generic_api']);
        self::assertSame(400, $response->get_status());
        self::assertSame('CLINIC_SSRF_BLOCKED', $this->iv1_code($response));
        $status = $this->iv1_scope($this->clinic, fn() => App::smsService()->status());
        self::assertSame('ERROR', $status['state']);
        self::assertSame('failed', $status['last_test']['status']);
        self::assertStringNotContainsString('127.0.0.1', (string) wp_json_encode($status));
    }

    public function testChangingCredentialsOrEndpointInvalidatesPreviousConnectionVerification(): void
    {
        $this->iv1_scope($this->clinic, function (): void {
            App::settings()->set('sms.provider', 'generic_api');
            App::settings()->set('sms.generic', ['endpoint' => 'https://sms.example.invalid/old']);
            App::settings()->set('sms.last_test', ['status' => 'ok', 'provider' => 'generic_api']);
        });
        $this->asSmsManager();
        $response = $this->post('/sms/settings', ['provider' => 'generic_api', 'generic' => ['endpoint' => 'https://sms.example.invalid/new']]);
        self::assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $status = $this->iv1_scope($this->clinic, fn() => App::smsService()->status());
        self::assertSame([], $status['last_test']);
        self::assertNotSame('VERIFIED', $status['state']);
    }

    public function testRecordLocationSelectorCannotExposeAnotherLocationsRecord(): void
    {
        $response = $this->record($this->visit, $this->locationB);
        self::assertSame(404, $response->get_status());
        self::assertSame('CLINIC_NOT_FOUND', $this->iv1_code($response));
        self::assertStringNotContainsString('آزمایشی', (string) wp_json_encode($response->get_data()));
    }

    public function testOmittingLocationCannotBypassPersistedLocationMembership(): void
    {
        $foreignVisit = $this->iv1_visit($this->clinic, $this->locationB, $this->patient, $this->clinician, 'in_consultation');
        $response = $this->record($foreignVisit, null);
        self::assertSame(404, $response->get_status());
        self::assertSame('CLINIC_NOT_FOUND', $this->iv1_code($response));
    }

    public function testAssignedLocationAndClinicWideMembershipRemainUsable(): void
    {
        self::assertSame(200, $this->record($this->visit, $this->locationA)->get_status());
        self::assertSame(200, $this->record($this->visit, null)->get_status());
        $wideDoctor = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'classic_wide');
        $this->iv1_membership($wideDoctor, $this->clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_clinician($this->clinic, $wideDoctor);
        wp_set_current_user($wideDoctor);
        $foreignVisit = $this->iv1_visit($this->clinic, $this->locationB, $this->patient, $this->clinician, 'in_consultation');
        self::assertSame(200, $this->record($foreignVisit, null)->get_status(), 'ADR-0031 clinic-wide membership intentionally spans its Clinic Locations.');
        self::assertSame(404, $this->record($foreignVisit, $this->locationA)->get_status(), 'An explicit selector must match the persisted Visit.');
    }

    public function testCrossClinicAndCrossOrganizationRecordsRemainDenied(): void
    {
        $otherClinic = $this->iv1_clinic($this->org);
        $otherLocation = $this->iv1_location($otherClinic);
        $otherPatient = $this->iv1_patient($otherClinic);
        $otherVisit = $this->iv1_visit($otherClinic, $otherLocation, $otherPatient, $this->clinician);
        $response = $this->iv1_dispatch('GET', '/clinic/v1/visits/' . $otherVisit . '/record', [], $this->iv1_headers($this->clinic));
        self::assertSame(404, $response->get_status());
        $otherOrg = $this->iv1_org('foreign');
        $foreignClinic = $this->iv1_clinic($otherOrg);
        $foreignLocation = $this->iv1_location($foreignClinic);
        $foreignPatient = $this->iv1_patient($foreignClinic);
        $foreignVisit = $this->iv1_visit($foreignClinic, $foreignLocation, $foreignPatient, $this->clinician);
        $response = $this->iv1_dispatch('GET', '/clinic/v1/visits/' . $foreignVisit . '/record', [], $this->iv1_headers($foreignClinic));
        self::assertSame(403, $response->get_status());
        self::assertStringNotContainsString('آزمایشی', (string) wp_json_encode($response->get_data()));
    }

    public function testStaffCancellationQueuesOneSmsAndInternalNotificationWithoutClaimingDelivery(): void
    {
        $appointment = $this->appointment();
        $response = $this->cancel($appointment);
        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        self::assertSame('cancelled_by_staff', $this->iv1_data($response)['status']);
        self::assertArrayNotHasKey('sms_sent', $this->iv1_data($response));
        self::assertCount(0, App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_sms_messages')));
        App::bookingService()->dispatchCancellationNotification($appointment);
        $messages = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE context_type = %s AND context_id = %d', ['appointment', $appointment]);
        self::assertCount(1, $messages);
        self::assertSame(SmsEvents::APPT_CANCELLED, $messages[0]['event']);
        self::assertSame(SmsMessageStatus::QUEUED, $messages[0]['status']);
        self::assertSame(0, (int) $messages[0]['attempts']);
        self::assertCount(0, $this->provider->textSends);
        $notifications = App::db()->fetchAll('SELECT * FROM ' . App::db()->table('cpms_notifications') . ' WHERE recipient_patient_id = %d AND template = %s', [$this->patient, 'APPT.cancelled']);
        self::assertCount(1, $notifications);
        self::assertSame('queued', $notifications[0]['status']);
        $duplicate = $this->cancel($appointment);
        self::assertSame(409, $duplicate->get_status());
        self::assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_sms_messages')));

        $handler = new SmsSendJobHandler(App::smsService());
        $payload = ['message_id' => (int) $messages[0]['id']];
        $handler($payload);
        $handler($payload);
        self::assertCount(1, $this->provider->textSends, 'Repeated worker delivery must not send twice.');
        $row = App::db()->fetchRow('SELECT status FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE id = %d', [(int) $messages[0]['id']]);
        self::assertSame(SmsMessageStatus::SENT, $row['status'], 'Provider acceptance is SENT, not DELIVERED.');
    }

    public function testCancellationProviderFailureRemainsDurableAndDoesNotRollBackCancellation(): void
    {
        $this->iv1_scope($this->clinic, fn() => App::settings()->set('sms.provider', 'classic_fail'));
        App::providers()->register(new class implements \ClinicCore\Infrastructure\Sms\SmsProviderInterface {
            public function id(): string { return 'classic_fail'; }
            public function label(): string { return 'Synthetic failure'; }
            public function capabilities(): array { return ['text' => true, 'template' => false]; }
            public function authMethods(): array { return []; }
            public function authFields(): array { return []; }
            public function testConnection(array $creds): array { return ['ok' => false, 'message' => 'failed']; }
            public function sendText(array $creds, string $mobile, string $message, array $opts = []): array { throw new SmsSendException('synthetic timeout', true, 'CLINIC_SMS_TIMEOUT'); }
            public function sendTemplate(array $creds, string $mobile, string $templateId, array $variables, array $opts = []): array { throw new SmsSendException('synthetic timeout', true, 'CLINIC_SMS_TIMEOUT'); }
            public function mapStatus(string $status): string { return $status; }
            public function fetchSenders(array $creds): ?array { return null; }
            public function fetchBalance(array $creds): ?array { return null; }
        });
        $appointment = $this->appointment();
        self::assertSame(200, $this->cancel($appointment)->get_status());
        App::bookingService()->dispatchCancellationNotification($appointment);
        $message = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_sms_messages'));
        (new SmsSendJobHandler(App::smsService()))(['message_id' => (int) $message['id']]);
        $after = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE id = %d', [(int) $message['id']]);
        self::assertSame(SmsMessageStatus::RETRYING, $after['status']);
        self::assertSame('cancelled_by_staff', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [$appointment]));
        self::assertSame(1, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_jobs') . " WHERE type = 'sms.send'"));
    }

    private function appointment(): int
    {
        return $this->iv1_scope($this->clinic, function (): array {
            $slot = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE clinic_id = %d LIMIT 1', [$this->clinic]);
            if ($slot === null) {
                $now = App::db()->nowUtcSql();
                App::db()->insert('cpms_schedule_slots', ['clinic_id' => $this->clinic, 'location_id' => $this->locationA, 'clinician_id' => $this->clinician, 'slot_date' => gmdate('Y-m-d', time() + 86400), 'slot_time' => '09:00:00', 'duration_min' => 20, 'capacity' => 1, 'booked_count' => 1, 'held_count' => 0, 'is_open' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $slot = App::db()->fetchRow('SELECT * FROM ' . App::db()->table('cpms_schedule_slots') . ' WHERE id = %d', [App::db()->wpdb_last_insert_id()]);
            }
            App::db()->insert('cpms_appointments', ['clinic_id' => $this->clinic, 'location_id' => $this->locationA, 'reference_code' => 'CLASSIC-' . bin2hex(random_bytes(5)), 'patient_id' => $this->patient, 'clinician_id' => $this->clinician, 'slot_id' => (int) $slot['id'], 'slot_date' => $slot['slot_date'], 'slot_time' => $slot['slot_time'], 'duration_min' => 20, 'status' => 'confirmed', 'created_at' => App::db()->nowUtcSql(), 'updated_at' => App::db()->nowUtcSql()]);
            return ['id' => App::db()->wpdb_last_insert_id()];
        })['id'];
    }

    private function cancel(int $id): WP_REST_Response
    {
        return $this->post('/appointments/' . $id . '/cancel', ['reason' => 'Synthetic staff cancellation']);
    }

    private function record(int $visit, ?int $location): WP_REST_Response
    {
        $params = $location === null ? [] : ['location_id' => $location];
        return $this->iv1_dispatch('GET', '/clinic/v1/visits/' . $visit . '/record', $params, $this->iv1_headers($this->clinic));
    }

    private function asSmsManager(): void
    {
        $manager = $this->iv1_user(RolesAndCapabilities::ROLE_MANAGER, 'classic_manager');
        $this->iv1_membership($manager, $this->clinic, RolesAndCapabilities::ROLE_MANAGER);
        wp_set_current_user($manager);
    }

    private function saveUnsafeEndpoint(): WP_REST_Response
    {
        $this->asSmsManager();
        return $this->post('/sms/settings', ['provider' => 'generic_api', 'auth_method' => 'api_key', 'generic' => ['endpoint' => 'http://127.0.0.1/internal']]);
    }

    /** @param array<string,mixed> $body */
    private function post(string $route, array $body): WP_REST_Response
    {
        return $this->iv1_dispatch('POST', '/clinic/v1' . $route, [], $this->iv1_headers($this->clinic), $body);
    }
}

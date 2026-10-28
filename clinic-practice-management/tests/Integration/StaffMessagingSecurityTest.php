<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Messaging\MessagingException;
use ClinicCore\Application\Messaging\StaffMessagingService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Tests\Integration\Fixtures\IranV1Fixture;
use WP_UnitTestCase;

require_once __DIR__ . '/Fixtures/IranV1Fixture.php';

/**
 * Iran V1 — private one-to-one staff messaging security and behaviour.
 *
 * Covers: same-Clinic two-way conversation, unread indicators, timestamps,
 * cross-Clinic/cross-Organization denial, patient/visit scope verification,
 * Location isolation, XSS sanitization, send rate limiting and append-only
 * retention (no destructive route and no clinical-record projection).
 */
final class StaffMessagingSecurityTest extends WP_UnitTestCase
{
    use IranV1Fixture;

    private const CONTEXT = '/clinic/v1/staff/portal/messages/context';
    private const THREADS = '/clinic/v1/staff/portal/messages/threads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->iv1_boot();
    }

    protected function tearDown(): void
    {
        $this->iv1_reset();
        parent::tearDown();
    }

    /**
     * @return array<string, int>
     */
    private function world(): array
    {
        $org      = $this->iv1_org('msg');
        $clinic   = $this->iv1_clinic($org);
        $location = $this->iv1_location($clinic);
        $doctor   = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'doc');
        $secretary = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'sec');
        $this->iv1_membership($doctor, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_membership($secretary, $clinic, RolesAndCapabilities::ROLE_SECRETARY);
        $clinician = $this->iv1_clinician($clinic, $doctor, 'پزشک پیام');
        $patient   = $this->iv1_patient($clinic, 'بیمار پیام');
        $visit     = $this->iv1_visit($clinic, $location, $patient, $clinician);

        return compact('org', 'clinic', 'location', 'doctor', 'secretary', 'clinician', 'patient', 'visit');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function call_thread(array $world, int $actor, array $payload): array
    {
        return $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->create_thread($actor, $payload));
    }

    private function assertDenied(callable $fn, string $code, int $status): void
    {
        try {
            $fn();
            self::fail('Expected MessagingException ' . $code);
        } catch (MessagingException $exception) {
            self::assertSame($code, $exception->error_code);
            self::assertSame($status, $exception->http_status);
        }
    }

    public function testSameClinicTwoWayConversationUnreadIndicatorsAndTimestamps(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);

        $thread = $this->call_thread($world, (int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
            'patient_id'           => (int) $world['patient'],
            'visit_id'             => (int) $world['visit'],
        ]);
        self::assertGreaterThan(0, (int) $thread['id']);
        self::assertSame((int) $world['visit'], (int) $thread['visit_id']);
        self::assertSame((int) $world['location'], (int) $thread['location_id']);

        $service = App::staff_messaging_service();
        $sent = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['doctor'],
            (int) $thread['id'],
            '<script>alert(1)</script>سلام، بیمار آماده پذیرش است.'
        ));
        self::assertStringNotContainsString('<script', (string) $sent['body']);
        self::assertStringContainsString('بیمار آماده پذیرش', (string) $sent['body']);
        self::assertNotSame('', (string) $sent['created_at']);
        self::assertNotSame('', (string) $sent['jalali_time']);

        $secretary_threads = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads((int) $world['secretary']));
        self::assertCount(1, $secretary_threads['threads']);
        self::assertSame(1, (int) $secretary_threads['threads'][0]['unread_count']);
        self::assertSame(1, (int) $secretary_threads['unread_total']);

        $inbox = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['secretary'], (int) $thread['id'], 0, 50));
        self::assertCount(1, $inbox['messages']);
        self::assertFalse($inbox['messages'][0]['mine']);
        self::assertArrayNotHasKey('clinical_note', $inbox['messages'][0]);
        self::assertArrayNotHasKey('medical_record', $inbox['messages'][0]);

        $read = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->mark_read((int) $world['secretary'], (int) $thread['id']));
        self::assertSame(0, (int) $read['unread_count']);

        $reply = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send(
            (int) $world['secretary'],
            (int) $thread['id'],
            'پذیرش انجام شد.'
        ));
        self::assertTrue($reply['mine']);
        $incremental = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['doctor'], (int) $thread['id'], (int) $sent['id'], 50));
        self::assertCount(1, $incremental['messages'], 'incremental polling returns only new messages');
        self::assertSame((int) $reply['id'], (int) $incremental['messages'][0]['id']);

        $context = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->context((int) $world['secretary']));
        self::assertSame(StaffMessagingService::RETENTION_POLICY, $context['retention_policy']);
        self::assertContains((int) $world['doctor'], array_column($context['counterparts'], 'wp_user_id'));

        // Audit records the send without ever storing the message body.
        $audit = App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs')
            . " WHERE action = 'MESSAGE_SEND' AND resource_id = %d AND COALESCE(meta_json, '') NOT LIKE %s",
            [(int) $reply['id'], '%alert(1)%']
        );
        self::assertSame(1, (int) $audit);
    }

    public function testCrossClinicAndCrossOrganizationThreadsAreDenied(): void
    {
        $world = $this->world();
        $other_org = $this->iv1_org('other');
        $other_clinic = $this->iv1_clinic($other_org);
        $foreign = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'foreign');
        $this->iv1_membership($foreign, $other_clinic, RolesAndCapabilities::ROLE_DOCTOR);

        wp_set_current_user((int) $world['doctor']);
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        $id = (int) $thread['id'];

        // A thread id from Clinic A is indistinguishable from an unknown id
        // under Clinic B's trusted scope (404 parity, no existence disclosure).
        $this->assertDenied(
            fn () => $this->iv1_scope($other_clinic, fn (): array => App::staff_messaging_service()->messages($foreign, $id, 0, 50)),
            'CLINIC_NOT_FOUND',
            404
        );
        $this->assertDenied(
            fn () => $this->iv1_scope($other_clinic, fn (): array => App::staff_messaging_service()->send($foreign, $id, 'نفوذ')),
            'CLINIC_NOT_FOUND',
            404
        );

        wp_set_current_user($foreign);
        $rest = $this->iv1_dispatch('GET', '/clinic/v1/staff/portal/messages/threads/' . $id . '/messages', [], $this->iv1_headers($other_clinic));
        self::assertSame(404, $rest->get_status());
        self::assertSame('CLINIC_NOT_FOUND', $this->iv1_code($rest));

        // Explicit Organization binding: a corrupted/foreign Organization id on
        // the same thread row must not make it readable inside the trusted Org.
        App::db()->update('cpms_message_threads', ['organization_id' => $other_org], ['id' => $id]);
        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->messages((int) $world['doctor'], $id, 0, 50)),
            'CLINIC_NOT_FOUND',
            404
        );
    }

    public function testPatientAndVisitReferencesAreVerifiedTogetherAndScoped(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);
        $service = App::staff_messaging_service();
        $clinic = (int) $world['clinic'];

        $this->assertDenied(
            fn () => $this->call_thread($world, (int) $world['doctor'], [
                'recipient_wp_user_id' => (int) $world['secretary'], 'patient_id' => (int) $world['patient'],
            ]),
            'CLINIC_VALIDATION_FAILED',
            422
        );
        $this->assertDenied(
            fn () => $this->call_thread($world, (int) $world['doctor'], [
                'recipient_wp_user_id' => (int) $world['secretary'], 'visit_id' => (int) $world['visit'],
            ]),
            'CLINIC_VALIDATION_FAILED',
            422
        );

        $other_patient = $this->iv1_patient($clinic, 'بیمار دیگر');
        $this->assertDenied(
            fn () => $this->call_thread($world, (int) $world['doctor'], [
                'recipient_wp_user_id' => (int) $world['secretary'],
                'patient_id'           => $other_patient,
                'visit_id'             => (int) $world['visit'],
            ]),
            'CLINIC_NOT_FOUND',
            404
        );

        $foreign_org = $this->iv1_org('foreign');
        $foreign_clinic = $this->iv1_clinic($foreign_org);
        $foreign_location = $this->iv1_location($foreign_clinic);
        $foreign_patient = $this->iv1_patient($foreign_clinic, 'بیمار خارجی');
        $foreign_clinician = $this->iv1_clinician($foreign_clinic, null, 'پزشک خارجی');
        $foreign_visit = $this->iv1_visit($foreign_clinic, $foreign_location, $foreign_patient, $foreign_clinician);
        $this->assertDenied(
            fn () => $this->call_thread($world, (int) $world['doctor'], [
                'recipient_wp_user_id' => (int) $world['secretary'],
                'patient_id'           => $foreign_patient,
                'visit_id'             => $foreign_visit,
            ]),
            'CLINIC_NOT_FOUND',
            404
        );

        // A second doctor in the SAME Clinic cannot open a patient-linked
        // conversation about the first doctor's Visit.
        $doctor2 = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'doc2');
        $this->iv1_membership($doctor2, $clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_clinician($clinic, $doctor2, 'پزشک دوم');
        $this->assertDenied(
            fn () => $this->call_thread($world, $doctor2, [
                'recipient_wp_user_id' => (int) $world['secretary'],
                'patient_id'           => (int) $world['patient'],
                'visit_id'             => (int) $world['visit'],
            ]),
            'CLINIC_PERMISSION_DENIED',
            403
        );
        self::assertNotNull($service);
    }

    public function testLocationScopedStaffCannotReadOrBeLinkedToForeignLocationThread(): void
    {
        $world = $this->world();
        $location2 = $this->iv1_location((int) $world['clinic'], 'محل دوم');
        $secretary_membership = App::membership_service()->membership_for((int) $world['clinic'], (int) $world['secretary']);
        self::assertNotNull($secretary_membership);
        $this->iv1_restrict_to_location((int) $secretary_membership['id'], $location2);

        wp_set_current_user((int) $world['doctor']);
        // Cannot even create a linked thread whose Location the counterpart
        // is not authorized for.
        $this->assertDenied(
            fn () => $this->call_thread($world, (int) $world['doctor'], [
                'recipient_wp_user_id' => (int) $world['secretary'],
                'patient_id'           => (int) $world['patient'],
                'visit_id'             => (int) $world['visit'],
            ]),
            'CLINIC_VALIDATION_FAILED',
            422
        );

        // A clinic-wide thread created before the restriction becomes
        // unreadable when it is stamped with a foreign Location.
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        App::db()->update('cpms_message_threads', ['location_id' => (int) $world['location']], ['id' => (int) $thread['id']]);
        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->messages((int) $world['secretary'], (int) $thread['id'], 0, 50)),
            'CLINIC_PERMISSION_DENIED',
            403
        );
    }

    public function testRestRejectsMissingNonceXssAndAppliesSendRateLimit(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);
        $service = App::staff_messaging_service();
        $thread = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->create_thread((int) $world['doctor'], [
            'recipient_wp_user_id' => (int) $world['secretary'],
        ]));
        $id = (int) $thread['id'];
        $route = '/clinic/v1/staff/portal/messages/threads/' . $id . '/messages';

        $bad_nonce = new \WP_REST_Request('POST', $route);
        $bad_nonce->set_header('Content-Type', 'application/json');
        $bad_nonce->set_body((string) wp_json_encode(['body' => 'without nonce']));
        $no_nonce = rest_do_request($bad_nonce);
        self::assertSame(403, $no_nonce->get_status());
        self::assertSame('CLINIC_INVALID_NONCE', $this->iv1_code($no_nonce));

        $xss = $this->iv1_dispatch('POST', $route, [], $this->iv1_headers((int) $world['clinic']), [
            'body' => '<img src=x onerror="alert(1)">پیام تست',
        ]);
        self::assertSame(201, $xss->get_status(), 'first send succeeds — ' . $this->iv1_code($xss));
        $stored = (string) App::db()->fetchValue(
            'SELECT body FROM ' . App::db()->table('cpms_messages') . ' WHERE thread_id = %d ORDER BY id DESC',
            [$id]
        );
        self::assertStringNotContainsString('<img', $stored);
        self::assertStringContainsString('پیام تست', $stored);

        // 20 sends/minute per actor: the 21st (this is number 21 including the
        // XSS send above) is refused before any service mutation.
        $limited = null;
        for ($index = 0; $index < 20; $index++) {
            $limited = $this->iv1_dispatch('POST', $route, [], $this->iv1_headers((int) $world['clinic']), ['body' => 'پیام ' . $index]);
            if ($limited->get_status() === 429) {
                break;
            }
        }
        self::assertSame(429, $limited->get_status());
        self::assertSame('CLINIC_RATE_LIMITED', $this->iv1_code($limited));

        // Append-only retention: no destructive REST route exists and every
        // earlier message is still present after all reads and refusals.
        foreach (rest_get_server()->get_routes() as $registered => $endpoints) {
            if (!str_contains($registered, '/staff/portal/messages/')) {
                continue;
            }
            foreach ((array) $endpoints as $endpoint) {
                $raw = $endpoint['methods'] ?? [];
                $method_text = is_array($raw)
                    ? implode(' ', array_merge(array_map('strval', array_keys($raw)), array_map('strval', array_values($raw))))
                    : (string) $raw;
                self::assertStringNotContainsString('DELETE', strtoupper($method_text));
            }
        }
        self::assertSame(20, (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_messages') . ' WHERE thread_id = %d',
            [$id]
        ));
    }

    /**
     * Finance staff are eligible only through current effective CPMS capabilities.
     * Every supported pairing remains a private, explicit two-person thread.
     */
    public function testAuthorizedStaffRolesAreEligibleAsExplicitOneToOneCounterparts(): void
    {
        $world = $this->world();
        $doctor2 = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'doc2');
        $this->iv1_membership($doctor2, (int) $world['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $secretary2 = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'sec2');
        $this->iv1_membership($secretary2, (int) $world['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        $finance1 = $this->iv1_user(RolesAndCapabilities::ROLE_ACCOUNTANT, 'cash1');
        $this->iv1_membership($finance1, (int) $world['clinic'], RolesAndCapabilities::ROLE_ACCOUNTANT);
        $finance2 = $this->iv1_user(RolesAndCapabilities::ROLE_ACCOUNTANT, 'cash2');
        $this->iv1_membership($finance2, (int) $world['clinic'], RolesAndCapabilities::ROLE_ACCOUNTANT);

        $pairs = [
            [(int) $world['doctor'], (int) $world['secretary']],
            [(int) $world['doctor'], $finance1],
            [(int) $world['secretary'], $finance1],
            [(int) $world['doctor'], $doctor2],
            [(int) $world['secretary'], $secretary2],
            [$finance1, $finance2],
        ];
        foreach ($pairs as [$actor, $recipient]) {
            wp_set_current_user($actor);
            $thread = $this->call_thread($world, $actor, ['recipient_wp_user_id' => $recipient]);
            self::assertGreaterThan(0, (int) $thread['id']);
            self::assertSame($recipient, (int) $thread['counterpart']['wp_user_id']);
            self::assertSame(2, (int) App::db()->fetchValue(
                'SELECT COUNT(*) FROM ' . App::db()->table('cpms_message_participants') . ' WHERE thread_id = %d',
                [(int) $thread['id']]
            ));
        }

        wp_set_current_user((int) $world['doctor']);
        $context = $this->iv1_scope(
            (int) $world['clinic'],
            fn (): array => App::staff_messaging_service()->context((int) $world['doctor'])
        );
        $ids = array_map('intval', array_column($context['counterparts'], 'wp_user_id'));
        self::assertContains($finance1, $ids);
        $finance_counterpart = null;
        foreach ( $context['counterparts'] as $person ) {
            if ( (int) $person['wp_user_id'] === $finance1 ) {
                $finance_counterpart = $person;
                break;
            }
        }
        self::assertIsArray( $finance_counterpart );
        self::assertSame( 'مالی', $finance_counterpart['label'] );

        // Finance staff are authorized by durable CPMS capabilities and can use
        // the actual authenticated REST endpoints, not only the domain service.
        wp_set_current_user((int) $world['doctor']);
        $rest_thread = $this->iv1_dispatch(
            'POST', self::THREADS, [], $this->iv1_headers((int) $world['clinic']),
            ['recipient_wp_user_id' => $finance1]
        );
        self::assertSame(201, $rest_thread->get_status());
        $rest_id = (int) ($rest_thread->get_data()['data']['id'] ?? 0);
        self::assertGreaterThan(0, $rest_id);
        $sent = $this->iv1_dispatch(
            'POST', '/clinic/v1/staff/portal/messages/threads/' . $rest_id . '/messages', [],
            $this->iv1_headers((int) $world['clinic']), ['body' => 'پیام خصوصی مالی']
        );
        self::assertSame(201, $sent->get_status());
        wp_set_current_user($finance1);
        $inbox = $this->iv1_dispatch(
            'GET', '/clinic/v1/staff/portal/messages/threads/' . $rest_id . '/messages', [],
            $this->iv1_headers((int) $world['clinic'])
        );
        self::assertSame(200, $inbox->get_status());
        self::assertSame('پیام خصوصی مالی', $inbox->get_data()['data']['messages'][0]['body']);
        $finance_reply = $this->iv1_dispatch(
            'POST', '/clinic/v1/staff/portal/messages/threads/' . $rest_id . '/messages', [],
            $this->iv1_headers((int) $world['clinic']), ['body' => 'پاسخ خصوصی مالی']
        );
        self::assertSame(201, $finance_reply->get_status());
        wp_set_current_user((int) $world['doctor']);
        $doctor_inbox = $this->iv1_dispatch(
            'GET', '/clinic/v1/staff/portal/messages/threads/' . $rest_id . '/messages', [],
            $this->iv1_headers((int) $world['clinic'])
        );
        self::assertSame(200, $doctor_inbox->get_status());
        self::assertSame('پاسخ خصوصی مالی', $doctor_inbox->get_data()['data']['messages'][1]['body']);

        wp_set_current_user((int) $world['secretary']);
        $reception_thread = $this->iv1_dispatch(
            'POST', self::THREADS, [], $this->iv1_headers((int) $world['clinic']),
            ['recipient_wp_user_id' => $finance1]
        );
        self::assertSame(201, $reception_thread->get_status());
        $reception_thread_id = (int) $reception_thread->get_data()['data']['id'];
        $reception_send = $this->iv1_dispatch(
            'POST', '/clinic/v1/staff/portal/messages/threads/' . $reception_thread_id . '/messages', [],
            $this->iv1_headers((int) $world['clinic']), ['body' => 'پیام خصوصی پذیرش و مالی']
        );
        self::assertSame(201, $reception_send->get_status());
        wp_set_current_user($finance1);
        $finance_reception_inbox = $this->iv1_dispatch(
            'GET', '/clinic/v1/staff/portal/messages/threads/' . $reception_thread_id . '/messages', [],
            $this->iv1_headers((int) $world['clinic'])
        );
        self::assertSame(200, $finance_reception_inbox->get_status());
        self::assertSame('پیام خصوصی پذیرش و مالی', $finance_reception_inbox->get_data()['data']['messages'][0]['body']);

        $audit_meta = (string) App::db()->fetchValue(
            'SELECT meta_json FROM ' . App::db()->table('cpms_audit_logs')
            . " WHERE action = 'MESSAGE_THREAD_CREATE' AND resource_id = %d ORDER BY id DESC",
            [$rest_id]
        );
        self::assertStringNotContainsString('recipient_id', $audit_meta);
        self::assertStringNotContainsString((string) $finance1, $audit_meta);
    }

    public function testOneDoctorCanKeepTwoReceptionistThreadsStrictlySeparate(): void
    {
        $world = $this->world();
        $secretary2 = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'sec2');
        $this->iv1_membership($secretary2, (int) $world['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        $service = App::staff_messaging_service();
        wp_set_current_user((int) $world['doctor']);
        $thread1 = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        $thread2 = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => $secretary2]);
        $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send((int) $world['doctor'], (int) $thread1['id'], 'فقط منشی اول'));
        $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send((int) $world['doctor'], (int) $thread2['id'], 'فقط منشی دوم'));

        $one = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads((int) $world['secretary']));
        $two = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads($secretary2));
        self::assertCount(1, $one['threads']);
        self::assertCount(1, $two['threads']);
        self::assertSame((int) $thread1['id'], (int) $one['threads'][0]['id']);
        self::assertSame((int) $thread2['id'], (int) $two['threads'][0]['id']);
        self::assertSame(1, (int) $one['unread_total']);
        self::assertSame(1, (int) $two['unread_total']);
        $one_messages = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['secretary'], (int) $thread1['id'], 0, 50));
        $two_messages = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages($secretary2, (int) $thread2['id'], 0, 50));
        self::assertSame('فقط منشی اول', $one_messages['messages'][0]['body']);
        self::assertSame('فقط منشی دوم', $two_messages['messages'][0]['body']);
        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages((int) $world['secretary'], (int) $thread2['id'], 0, 50)),
            'CLINIC_NOT_FOUND',
            404
        );
    }

    public function testUnexpectedThirdParticipantMakesThreadUnreadableToEveryone(): void
    {
        $world = $this->world();
        $third = $this->iv1_user(RolesAndCapabilities::ROLE_SECRETARY, 'third');
        $this->iv1_membership($third, (int) $world['clinic'], RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user((int) $world['doctor']);
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        $service = App::staff_messaging_service();
        $this->iv1_scope((int) $world['clinic'], fn (): array => $service->send((int) $world['doctor'], (int) $thread['id'], 'خصوصی'));
        (new \ClinicCore\Infrastructure\Repository\MessageRepository(App::db()))->add_participant(
            (int) $thread['id'], $third, 'receptionist'
        );

        foreach ([(int) $world['doctor'], (int) $world['secretary'], $third] as $actor) {
            $this->assertDenied(
                fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => $service->messages($actor, (int) $thread['id'], 0, 50)),
                'CLINIC_NOT_FOUND',
                404
            );
            $listing = $this->iv1_scope((int) $world['clinic'], fn (): array => $service->threads($actor));
            self::assertSame([], $listing['threads']);
            self::assertSame(0, (int) $listing['unread_total']);
        }
    }

    public function testThirdClinicMemberCannotEnumerateOrUseKnownPrivateThreadIdThroughRest(): void
    {
        $world = $this->world();
        $doctor2 = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'doc2');
        $this->iv1_membership($doctor2, (int) $world['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user((int) $world['doctor']);
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        $thread_id = (int) $thread['id'];
        $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->send(
            (int) $world['doctor'], $thread_id, 'فقط برای گیرنده'
        ));

        wp_set_current_user($doctor2);
        $headers = $this->iv1_headers((int) $world['clinic']);
        $listing = $this->iv1_dispatch('GET', self::THREADS, [], $headers);
        self::assertSame(200, $listing->get_status());
        $data = $listing->get_data()['data'] ?? [];
        self::assertSame([], $data['threads'] ?? []);
        self::assertSame(0, (int) ($data['unread_total'] ?? -1));

        foreach ([
            ['GET', '/clinic/v1/staff/portal/messages/threads/' . $thread_id . '/messages', []],
            ['POST', '/clinic/v1/staff/portal/messages/threads/' . $thread_id . '/messages', ['body' => 'افشای پیام']],
            ['POST', '/clinic/v1/staff/portal/messages/threads/' . $thread_id . '/read', []],
        ] as [$method, $route, $payload]) {
            $response = $this->iv1_dispatch($method, $route, [], $headers, $payload);
            self::assertSame(404, $response->get_status());
            self::assertSame('CLINIC_NOT_FOUND', $this->iv1_code($response));
            self::assertStringNotContainsString('فقط برای گیرنده', wp_json_encode($response->get_data()));
            self::assertStringNotContainsString((string) $world['secretary'], wp_json_encode($response->get_data()));
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $admin_response = $this->iv1_dispatch(
            'GET', '/clinic/v1/staff/portal/messages/threads/' . $thread_id . '/messages', [], $headers
        );
        self::assertSame(403, $admin_response->get_status());
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->iv1_code($admin_response));
    }
    public function testPatientLinkedThreadRequiresSelectedDoctorOnVisitCareTeam(): void
    {
        $world = $this->world();
        $unrelated_doctor = $this->iv1_user(RolesAndCapabilities::ROLE_DOCTOR, 'unrelated_doc');
        $this->iv1_membership($unrelated_doctor, (int) $world['clinic'], RolesAndCapabilities::ROLE_DOCTOR);
        $this->iv1_clinician((int) $world['clinic'], $unrelated_doctor, 'پزشک نامرتبط');
        wp_set_current_user((int) $world['secretary']);

        // Unlinked clinic messaging remains available to eligible staff.
        $general = $this->call_thread($world, (int) $world['secretary'], ['recipient_wp_user_id' => $unrelated_doctor]);
        self::assertGreaterThan(0, (int) $general['id']);

        $this->assertDenied(
            fn () => $this->call_thread($world, (int) $world['secretary'], [
                'recipient_wp_user_id' => $unrelated_doctor,
                'patient_id' => (int) $world['patient'],
                'visit_id' => (int) $world['visit'],
            ]),
            'CLINIC_PERMISSION_DENIED',
            403
        );
        $linked = $this->call_thread($world, (int) $world['secretary'], [
            'recipient_wp_user_id' => (int) $world['doctor'],
            'patient_id' => (int) $world['patient'],
            'visit_id' => (int) $world['visit'],
        ]);
        self::assertSame((int) $world['visit'], (int) $linked['visit_id']);
    }

    public function testThreadAccessStopsWhenCounterpartMembershipIsNoLongerActive(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        $membership = App::membership_service()->membership_for((int) $world['clinic'], (int) $world['secretary']);
        self::assertNotNull($membership);
        App::membership_service()->suspend_membership((int) $membership['id']);

        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->messages(
                (int) $world['doctor'], (int) $thread['id'], 0, 50
            )),
            'CLINIC_PERMISSION_DENIED',
            403
        );
    }

    public function testFinanceParticipantLosesAccessWhenMessagingCapabilityIsRevoked(): void
    {
        $world = $this->world();
        $finance = $this->iv1_user(RolesAndCapabilities::ROLE_ACCOUNTANT, 'cashier');
        $membership_id = $this->iv1_membership($finance, (int) $world['clinic'], RolesAndCapabilities::ROLE_ACCOUNTANT);
        wp_set_current_user((int) $world['doctor']);
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => $finance]);
        App::membership_service()->set_capability(
            $membership_id,
            RolesAndCapabilities::FINANCE_READ,
            'deny'
        );

        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->messages(
                (int) $world['doctor'], (int) $thread['id'], 0, 50
            )),
            'CLINIC_PERMISSION_DENIED',
            403
        );
        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->context($finance)),
            'CLINIC_PERMISSION_DENIED',
            403
        );
    }

    public function testThreadAccessStopsWhenCounterpartLosesMessagingRoleCapability(): void
    {
        $world = $this->world();
        wp_set_current_user((int) $world['doctor']);
        $thread = $this->call_thread($world, (int) $world['doctor'], ['recipient_wp_user_id' => (int) $world['secretary']]);
        $membership = App::membership_service()->membership_for((int) $world['clinic'], (int) $world['secretary']);
        self::assertNotNull($membership);
        App::membership_service()->set_capability(
            (int) $membership['id'],
            RolesAndCapabilities::QUEUE_CHECKIN,
            'deny'
        );

        $this->assertDenied(
            fn () => $this->iv1_scope((int) $world['clinic'], fn (): array => App::staff_messaging_service()->messages(
                (int) $world['doctor'], (int) $thread['id'], 0, 50
            )),
            'CLINIC_PERMISSION_DENIED',
            403
        );
    }

}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Demo\DemoAccessGuard;
use ClinicCore\Application\Demo\DemoService;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use RuntimeException;
use WP_Error;
use WP_UnitTestCase;
use WP_User;

require_once __DIR__ . '/RealTableMigrations.php';

/** Real-WordPress security and lifecycle coverage for the optional demo. */
final class DemoFacilityTest extends WP_UnitTestCase
{
    use RealTableMigrations;

    private int $admin = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withRealTables(static fn (): array => App::migrations()->migrate());
        delete_option(DemoService::STATE_OPTION);
        delete_option(DemoService::OUTBOX_OPTION);
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        delete_option(DemoService::STATE_OPTION);
        delete_option(DemoService::OUTBOX_OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function testProvisionOtpRotationRevocationIdempotencyAndOwnedCleanup(): void
    {
        $service = App::demo_service();
        self::assertSame([], $service->state());
        $admin_user = get_userdata($this->admin);
        self::assertSame($admin_user, DemoAccessGuard::authenticate($admin_user));

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        try {
            $service->provision($subscriber, true);
            self::fail('A Subscriber must not provision the demo.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Administrator', $exception->getMessage());
        }
        self::assertSame([], $service->state());

        wp_set_current_user($this->admin);
        try {
            $service->provision($this->admin, false);
            self::fail('Provisioning without acknowledgement must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('acknowledgement', $exception->getMessage());
        }
        self::assertSame([], $service->state());

        $db = App::db();
        $now = $db->nowUtcSql();
        $db->insert('cpms_organizations', [
            'name' => 'Unrelated sentinel Organization',
            'slug' => 'unrelated-sentinel-organization',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sentinel_org_id = $db->wpdb_last_insert_id();
        $db->insert('cpms_clinics', [
            'organization_id' => $sentinel_org_id,
            'name' => 'Unrelated sentinel Clinic',
            'slug' => 'unrelated-sentinel-clinic',
            'timezone' => 'Asia/Tehran',
            'address' => null,
            'phone' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sentinel = ['id' => $db->wpdb_last_insert_id(), 'name' => 'Unrelated sentinel Clinic'];
        $baseline_users = count(get_users(['fields' => 'ID']));

        $result = $service->provision($this->admin, true);
        self::assertTrue($result['created']);
        $credentials = $result['credentials'];
        self::assertCount(6, $credentials);
        self::assertSame(6, count(array_unique(array_column($credentials, 'password'))));
        $state = $service->state();
        self::assertSame('active', $state['status']);
        self::assertSame((int) $state['acknowledged_at'] + DemoService::LIFETIME_SECONDS, (int) $state['expires_at']);
        self::assertGreaterThan((int) $sentinel['id'], (int) $state['clinic_id']);
        self::assertSame(6, count((array) $state['users']));

        foreach ((array) $state['users'] as $key => $entry) {
            $user = get_userdata((int) $entry['id']);
            self::assertInstanceOf(WP_User::class, $user);
            $expected_roles = $key === 'patient' ? [RolesAndCapabilities::ROLE_PATIENT] : ['subscriber'];
            self::assertSame($expected_roles, $user->roles);
            self::assertFalse($user->user_pass === $credentials[$key]['password']);
            self::assertTrue(wp_check_password($credentials[$key]['password'], $user->user_pass, $user->ID));
        }
        $serialized_state = serialize($state); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- test-only secret leak assertion.
        foreach ($credentials as $credential) {
            self::assertFalse(str_contains($serialized_state, $credential['password']));
        }

        $clinic_id = (int) $state['clinic_id'];
        $patient_id = (int) $state['patient_id'];
        $doctor_id = (int) $state['users']['doctor']['id'];
        $patient_user_id = (int) $state['users']['patient']['id'];
        self::assertSame('checked_out', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [(int) $state['completed_visit_id']]));
        self::assertSame('waiting', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_visits') . ' WHERE id = %d', [(int) $state['active_visit_id']]));
        self::assertSame('paid', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_invoices') . ' WHERE id = %d', [(int) $state['invoice_id']]));
        self::assertSame('confirmed', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_appointments') . ' WHERE id = %d', [(int) $state['appointment_id']]));
        self::assertSame(2, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinical_notes') . ' WHERE clinic_id = %d', [$clinic_id]));
        self::assertSame('finalized', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_prescriptions') . ' WHERE clinic_id = %d', [$clinic_id]));

        $page = get_post((int) $state['booking_page_id']);
        self::assertNotNull($page);
        self::assertSame('[cpms_public_booking clinic_id="' . $clinic_id . '"]', $page->post_content);
        $rendered = do_shortcode($page->post_content);
        self::assertFalse(str_contains($rendered, '[cpms_public_booking'));
        self::assertTrue(shortcode_exists('cpms_public_booking'));

        $user_count_after = count(get_users(['fields' => 'ID']));
        $clinic_count_after = (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics'));
        $repeat = $service->provision($this->admin, true);
        self::assertFalse($repeat['created']);
        self::assertSame([], $repeat['credentials']);
        self::assertSame($user_count_after, count(get_users(['fields' => 'ID'])));
        self::assertSame($clinic_count_after, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table('cpms_clinics')));
        self::assertGreaterThan($baseline_users, $user_count_after);

        // Role and tenant boundaries: no implicit staff capability or cross-Clinic grant.
        self::assertFalse(App::authorization_service()->can($doctor_id, (int) $sentinel['id'], RolesAndCapabilities::NOTE_CREATE));
        self::assertFalse(App::authorization_service()->can($doctor_id, $clinic_id, RolesAndCapabilities::PAYMENT_CREATE));
        $membership_id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d',
            [$clinic_id, $doctor_id]
        );
        App::db()->insert('cpms_membership_capabilities', [
            'membership_id' => $membership_id,
            'capability' => RolesAndCapabilities::NOTE_CREATE,
            'effect' => 'deny',
            'created_at' => App::db()->nowUtcSql(),
        ]);
        self::assertFalse(App::authorization_service()->can($doctor_id, $clinic_id, RolesAndCapabilities::NOTE_CREATE));
        App::db()->execute('DELETE FROM ' . App::db()->table('cpms_membership_capabilities') . ' WHERE membership_id = %d', [$membership_id]);
        self::assertTrue(App::authorization_service()->can($doctor_id, $clinic_id, RolesAndCapabilities::NOTE_CREATE));

        // Real OTP request + encrypted Administrator-only retrieval + normal verify.
        $otp_request = App::otpService()->request('09000000000', OtpService::PURPOSE_LOGIN, null, '127.0.0.1', $clinic_id);
        self::assertTrue($otp_request['sms_sent']);
        $code = $service->latest_otp($this->admin);
        self::assertSame(1, preg_match('/^\d{6}$/', $code));
        $outbox = get_option(DemoService::OUTBOX_OPTION, []);
        self::assertFalse(str_contains(serialize($outbox), $code)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- test-only ciphertext assertion.
        $sms_row = App::db()->fetchRow('SELECT message, vars_json FROM ' . App::db()->table('cpms_sms_messages') . ' WHERE clinic_id = %d ORDER BY id DESC LIMIT 1', [$clinic_id]);
        self::assertNotNull($sms_row);
        self::assertFalse(str_contains((string) $sms_row['message'], $code));
        self::assertNull($sms_row['vars_json']);
        $verified = App::otpService()->verify('09000000000', $code, OtpService::PURPOSE_LOGIN, '127.0.0.1');
        self::assertSame($patient_user_id, $verified['user_id']);
        self::assertTrue($verified['session_issued']);
        self::assertSame($patient_id, (int) $verified['patient_links'][0]['id']);
        try {
            App::otpService()->verify('09000000000', $code, OtpService::PURPOSE_LOGIN, '127.0.0.1');
            self::fail('OTP must be single-use.');
        } catch (OtpException $exception) {
            self::assertSame('CLINIC_OTP_INVALID', $exception->errorCode);
        }

        // Rotation is per-account, CSPRNG, and destroys existing sessions.
        $tokens = \WP_Session_Tokens::get_instance($doctor_id);
        $old_token = $tokens->create(time() + 3600);
        self::assertNotNull($tokens->get($old_token));
        $rotated = $service->rotate_password($this->admin, 'doctor');
        self::assertSame($state['users']['doctor']['username'], $rotated['username']);
        self::assertNull($tokens->get($old_token));
        $doctor = get_userdata($doctor_id);
        self::assertTrue(wp_check_password($rotated['password'], $doctor->user_pass, $doctor_id));
        self::assertFalse(wp_check_password($credentials['doctor']['password'], $doctor->user_pass, $doctor_id));

        // Keep one valid, unverified challenge; End must block its session seam.
        App::otpService()->request('09000000000', OtpService::PURPOSE_LOGIN, null, '127.0.0.1', $clinic_id);
        $pre_end_code = $service->latest_otp($this->admin);
        $service->end($this->admin);
        self::assertSame('ended', $service->state()['status']);
        self::assertSame('suspended', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$clinic_id]));
        self::assertTrue($service->is_blocked_user($doctor_id));
        self::assertInstanceOf(WP_Error::class, DemoAccessGuard::authenticate(get_userdata($doctor_id)));
        self::assertSame(0, DemoAccessGuard::determine_current_user($doctor_id));
        self::assertInstanceOf(WP_Error::class, DemoAccessGuard::allow_password_reset(true, $doctor_id));
        self::assertFalse(DemoAccessGuard::otp_session_authorized(true, $patient_user_id));
        try {
            App::otpService()->verify('09000000000', $pre_end_code, OtpService::PURPOSE_LOGIN, '127.0.0.1');
            self::fail('A pre-end OTP must not create a session after revocation.');
        } catch (OtpException $exception) {
            self::assertSame('CLINIC_OTP_SESSION_DENIED', $exception->errorCode);
        }

        $service->cleanup($this->admin);
        self::assertSame([], $service->state());
        foreach ((array) $state['users'] as $entry) {
            self::assertFalse(get_userdata((int) $entry['id']));
        }
        foreach (['cpms_patients', 'cpms_appointments', 'cpms_visits', 'cpms_invoices', 'cpms_clinic_memberships'] as $table) {
            self::assertSame(0, (int) App::db()->fetchValue('SELECT COUNT(*) FROM ' . App::db()->table($table) . ' WHERE clinic_id = %d', [$clinic_id]));
        }
        self::assertNull(get_post((int) $state['booking_page_id']));
        self::assertSame($sentinel['name'], App::db()->fetchValue('SELECT name FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [(int) $sentinel['id']]));
        self::assertSame('suspended', App::db()->fetchValue('SELECT status FROM ' . App::db()->table('cpms_clinics') . ' WHERE id = %d', [$clinic_id]));
        self::assertTrue(App::audit()->verifyChain(10000)['ok']);
    }

    public function testRequestDrivenExpiryRevokesCookiePasswordRestAndSuspendsClinic(): void
    {
        $db = App::db();
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $now = $db->nowUtcSql();
        $db->insert('cpms_organizations', [
            'name' => 'Expiry fixture Organization',
            'slug' => 'cpms-demo-expiry-fixture',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $org_id = $db->wpdb_last_insert_id();
        $db->insert('cpms_clinics', [
            'organization_id' => $org_id,
            'name' => 'Expiry fixture Clinic',
            'slug' => 'cpms-demo-clinic-expiry-fixture',
            'timezone' => 'Asia/Tehran',
            'address' => null,
            'phone' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $clinic_id = $db->wpdb_last_insert_id();
        $db->insert('cpms_organization_memberships', [
            'organization_id' => $org_id,
            'wp_user_id' => $user_id,
            'role_key' => 'organization_manager',
            'status' => 'active',
            'created_by_wp_user_id' => $this->admin,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        update_option(DemoService::STATE_OPTION, [
            'demo_id' => 'expiry-fixture',
            'status' => 'active',
            'expires_at' => time() - 1,
            'organization_id' => $org_id,
            'clinic_id' => $clinic_id,
            'users' => ['organization_manager' => ['id' => $user_id, 'username' => 'demo-expiry']],
        ], true);
        $tokens = \WP_Session_Tokens::get_instance($user_id);
        $token = $tokens->create(time() + 3600);
        self::assertNotNull($tokens->get($token));

        App::demo_service()->expire_if_due();
        self::assertSame('expired', App::demo_service()->state()['status']);
        self::assertNull($tokens->get($token));
        self::assertTrue(App::demo_service()->is_blocked_user($user_id));
        self::assertSame(0, DemoAccessGuard::determine_current_user($user_id));
        self::assertInstanceOf(WP_Error::class, DemoAccessGuard::allow_password_reset(true, $user_id));
        self::assertSame('suspended', $db->fetchValue('SELECT status FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$clinic_id]));
    }
}

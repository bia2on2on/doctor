<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 3 — RED test for Clinic-scoped SMS_CONFIG authorization.
 *
 * This test proves CURRENT defect:
 *  - actor has global WP capability cpms_sms_config
 *  - actor has ACTIVE durable membership in dynamically-created Clinic B
 *  - membership role does NOT authorize cpms_sms_config, with explicit deny
 *  - trusted Clinic context established for Clinic B
 *  - POST /clinic/v1/sms/settings dispatched with valid nonce
 *  - AuthorizationService itself says NOT allowed
 *  - current endpoint nevertheless accepts operation and changes persisted setting
 *
 * Desired after fix: 403 + unchanged.
 */
final class SmsClinicAuthorizationRedTest extends WP_UnitTestCase
{
    private int $clinicA;
    private int $clinicB;
    private int $actorUserId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1');
        self::assertGreaterThan(0, $orgId, 'organization must exist for dynamic clinic creation');

        $slugA = 'sms-authz-a-' . bin2hex(random_bytes(4));
        $slugB = 'sms-authz-b-' . bin2hex(random_bytes(4));

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $orgId,
                'Clinic SMS AuthZ A ' . $slugA,
                $slugA,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->clinicA = (int) $wpdb->insert_id;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $orgId,
                'Clinic SMS AuthZ B ' . $slugB,
                $slugB,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $this->clinicB = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $this->clinicA);
        self::assertGreaterThan(0, $this->clinicB);
        self::assertNotSame($this->clinicA, $this->clinicB);

        // Actor with global capability
        $this->actorUserId = $this->makeUser('sms_authz_actor_' . bin2hex(random_bytes(2)), 'administrator');
        $admin = get_userdata($this->actorUserId);
        self::assertNotFalse($admin);
        $admin->add_cap('cpms_sms_config');
        $admin->add_cap('manage_options');

        // Membership in Clinic B as secretary (preset lacks SMS_CONFIG) + explicit deny
        $membershipService = App::membership_service();
        $membershipId = $membershipService->create_membership($this->clinicB, $this->actorUserId, 'cpms_secretary');
        self::assertGreaterThan(0, $membershipId, 'membership fixture must be created');
        $membershipService->set_capability($membershipId, 'cpms_sms_config', 'deny');

        // Also create membership in Clinic A as manager for cross-clinic tests later (not needed for RED but ensures multi)
        // Do not create for RED actor in A to keep A clean; we will create another clinic membership later in matrix.

        // Set initial SMS sender for Clinic B to known original value
        $factory = App::settingsFactory();
        $settingsB = $factory->forClinic($this->clinicB);
        $settingsB->set('sms.sender', 'original-sender-' . bin2hex(random_bytes(2)), $this->actorUserId);
        $settingsB->set('sms.provider', 'log', $this->actorUserId);
        \ClinicCore\Settings\Settings::flushCache();

        // Verify fixture inserts
        $senderBefore = $this->getSenderForClinic($this->clinicB);
        self::assertStringStartsWith('original-sender-', $senderBefore, 'initial sender must be persisted');
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login, wp_generate_password(24), $login . '@sms-authz.test');
        self::assertGreaterThan(0, $userId, "user $login created");
        $u = get_userdata($userId);
        if ($u !== false) {
            $u->set_role($role);
        }
        return $userId;
    }

    private function getSenderForClinic(int $clinicId): string
    {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT value_json FROM ' . $wpdb->prefix . 'cpms_settings WHERE clinic_id = %d AND `key` = %s LIMIT 1',
                $clinicId,
                'sms.sender'
            )
        );
        if ($val === null) {
            return '';
        }
        $decoded = json_decode($val, true);
        return is_string($decoded) ? $decoded : (string) $decoded;
    }

    private function authzService(): \ClinicCore\Application\Authorization\AuthorizationService
    {
        $repo = new MembershipRepository(App::db());
        return new \ClinicCore\Application\Authorization\AuthorizationService($repo);
    }

    public function testRedCurrentDefectSmsSettingsAcceptsEvenThoughAuthorizationDenies(): void
    {
        // Coarse permission check must pass
        self::assertTrue(user_can($this->actorUserId, 'cpms_sms_config'), 'actor must have global cpms_sms_config to pass coarse check');

        // Active membership must exist in Clinic B
        $active = App::membership_service()->active_membership_for($this->clinicB, $this->actorUserId);
        self::assertNotNull($active, 'active membership in Clinic B must exist');
        self::assertSame('active', $active['status']);

        // AuthorizationService must say NOT allowed (explicit deny overrides)
        $svc = $this->authzService();
        $can = $svc->can($this->actorUserId, $this->clinicB, 'cpms_sms_config');
        self::assertFalse($can, 'AuthorizationService must DENY cpms_sms_config for this actor in Clinic B (explicit deny)');

        // Trusted Clinic context will be established via header; verify that establisher would succeed (membership exists)
        // Dispatch with valid nonce and trusted clinic header
        wp_set_current_user($this->actorUserId);

        $originalSender = $this->getSenderForClinic($this->clinicB);
        self::assertStringStartsWith('original-sender-', $originalSender, 'precondition: original sender persisted');

        $newSender = 'attacker-sender-' . bin2hex(random_bytes(3));

        $request = new WP_REST_Request('POST', '/clinic/v1/sms/settings');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $this->clinicB);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'provider' => 'log',
            'sender' => $newSender,
        ]));

        $response = rest_do_request($request);

        // Capture evidence for RED
        $status = $response->get_status();
        $data = $response->get_data();
        $afterSender = $this->getSenderForClinic($this->clinicB);

        // For RED demonstration, we assert the CURRENT defect:
        // - coarse permission passed (already asserted)
        // - trusted clinic path reached (if scope unavailable, status would be 403 CLINIC_SCOPE_UNAVAILABLE, not 200)
        // - endpoint performed real product operation (sender changed)
        // The test is expected to FAIL on the desired contract (403 + unchanged) — this failure is RED.

        // Assert that route was really dispatched (not 404)
        self::assertNotSame(404, $status, 'route must be dispatched, not 404');

        // Assert trusted clinic path reached (not scope unavailable)
        if (is_array($data) && isset($data['code'])) {
            self::assertNotSame('CLINIC_SCOPE_UNAVAILABLE', $data['code'], 'trusted clinic must be established, not unavailable');
            self::assertNotSame('CLINIC_SCOPE_REQUIRED', $data['code'], 'clinic scope must be established');
        }

        // CURRENT defect: endpoint accepts (200) and changes persisted value
        // We log this as explicit evidence via assertions that PASS now but would FAIL after fix if we expected 403.
        // For TDD RED, we now assert the DESIRED contract and expect it to FAIL.

        // Desired contract after fix:
        // - HTTP 403
        // - original persisted value unchanged
        // These assertions will FAIL in RED, proving defect.

        // Store evidence in test output for report
        error_log('[RED-EVIDENCE] actor=' . $this->actorUserId . ' clinicB=' . $this->clinicB . ' can=' . ($can ? 'true' : 'false') . ' status=' . $status . ' original=' . $originalSender . ' after=' . $afterSender);

        // Desired contract assertions (will FAIL in RED, PASS after GREEN)
        self::assertSame(403, $status, 'Desired after fix: HTTP 403 for denied actor');
        self::assertSame($originalSender, $afterSender, 'Desired after fix: original persisted sender must remain unchanged');
    }
}

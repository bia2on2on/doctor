<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 3 — Clinic-scoped SMS_CONFIG authorization matrix.
 *
 * Covers:
 * DENY: global admin no membership, explicit deny, suspended, cross-clinic, preset lacks
 * ALLOW: manager in correct clinic, multi-clinic independent
 * SIDE-EFFECT SAFETY: settings/templates/last_test/message unchanged on deny
 */
final class SmsClinicAuthorizationTest extends WP_UnitTestCase
{
    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();

        global $wpdb;
        $this->orgId = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics LIMIT 1');
        self::assertGreaterThan(0, $this->orgId, 'org must exist');
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    // ===== helpers =====

    private function createClinic(string $suffix): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $slug = 'sms-authz-' . $suffix . '-' . bin2hex(random_bytes(3));
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $this->orgId,
                'Clinic SMS ' . $suffix . ' ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "clinic $suffix created");
        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(2)), wp_generate_password(24), $login . '@sms.test');
        self::assertGreaterThan(0, $userId);
        $u = get_userdata($userId);
        if ($u !== false) {
            $u->set_role($role);
        }
        return $userId;
    }

    private function getSettingJson(int $clinicId, string $key): ?string
    {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                'SELECT value_json FROM ' . $wpdb->prefix . 'cpms_settings WHERE clinic_id = %d AND `key` = %s LIMIT 1',
                $clinicId,
                $key
            )
        );
    }

    private function getSender(int $clinicId): string
    {
        $json = $this->getSettingJson($clinicId, 'sms.sender');
        if ($json === null) {
            return '';
        }
        $decoded = json_decode($json, true);
        return is_string($decoded) ? $decoded : (string) $decoded;
    }

    private function getTemplates(int $clinicId): array
    {
        $json = $this->getSettingJson($clinicId, 'sms.templates');
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getLastTest(int $clinicId): array
    {
        $json = $this->getSettingJson($clinicId, 'sms.last_test');
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function countSmsMessages(int $clinicId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_sms_messages WHERE clinic_id = %d',
                $clinicId
            )
        );
    }

    private function dispatchJson(string $method, string $route, array $body, int $clinicId, int $userId): \WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request($method, $route);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        return rest_do_request($request);
    }

    private function dispatchGet(string $route, int $clinicId, int $userId, array $params = []): \WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        return rest_do_request($request);
    }

    private function dispatchPostParams(string $route, int $clinicId, int $userId, array $params): \WP_REST_Response
    {
        wp_set_current_user($userId);
        $request = new WP_REST_Request('POST', $route);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_header('X-CPMS-Clinic-Id', (string) $clinicId);
        return rest_do_request($request);
    }

    // ===== DENY =====

    public function testDenyGlobalAdminWithNoActiveMembership(): void
    {
        $clinicB = $this->createClinic('no-mem-' . bin2hex(random_bytes(2)));
        $admin = $this->makeUser('sms_admin_no_mem_' . bin2hex(random_bytes(2)), 'administrator');
        $u = get_userdata($admin);
        $u->add_cap('cpms_sms_config');

        // No membership
        self::assertSame([], App::membership_service()->active_memberships_for_user($admin), 'no membership precondition');

        // Ensure initial setting
        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-no-mem', $admin);
        \ClinicCore\Settings\Settings::flushCache();
        $orig = $this->getSender($clinicB);
        self::assertSame('orig-no-mem', $orig);

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker'], $clinicB, $admin);
        // RestClinicContext will deny with CLINIC_SCOPE_UNAVAILABLE (403) because no membership, or our helper with PERMISSION_DENIED
        self::assertSame(403, $res->get_status(), 'global admin without membership must be denied');
        self::assertSame('orig-no-mem', $this->getSender($clinicB), 'settings must remain unchanged on deny');
    }

    public function testDenyActiveMemberWithExplicitDeny(): void
    {
        $clinicB = $this->createClinic('explicit-deny-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_explicit_deny_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_manager');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-deny', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-deny'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'explicit deny must win');
        self::assertSame('orig-deny', $this->getSender($clinicB), 'settings unchanged');
    }

    public function testDenySuspendedMember(): void
    {
        $clinicB = $this->createClinic('suspended-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_suspended_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_manager');
        App::membership_service()->suspend_membership($memId);

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-susp', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-susp'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'suspended must fail closed');
        self::assertSame('orig-susp', $this->getSender($clinicB));
    }

    public function testDenyActorAuthorizedInClinicAAttemptingClinicB(): void
    {
        $clinicA = $this->createClinic('cross-a-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic('cross-b-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_cross_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        // Authorized in A only as manager
        App::membership_service()->create_membership($clinicA, $actor, 'cpms_manager');
        // No membership in B

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-cross', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-cross'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'Clinic A auth cannot reach Clinic B');
        self::assertSame('orig-cross', $this->getSender($clinicB));

        // Also attempt with explicit membership in B but as secretary (no sms_config) — should also deny even though A is manager
        $memB = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memB, 'cpms_sms_config', 'deny');
        \ClinicCore\Settings\Settings::flushCache();
        $res2 = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-cross2'], $clinicB, $actor);
        self::assertSame(403, $res2->get_status(), 'explicit deny in B must deny even if A is manager');
        self::assertSame('orig-cross', $this->getSender($clinicB));
    }

    public function testDenyActiveMemberWhoseRolePresetLacksSmsConfig(): void
    {
        $clinicB = $this->createClinic('preset-lack-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_preset_lack_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        // secretary preset lacks sms_config
        App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-preset', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-preset'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'preset lacking sms_config must deny');
        self::assertSame('orig-preset', $this->getSender($clinicB));
    }

    // ===== ALLOW =====

    public function testAllowActiveManagerInCorrectClinic(): void
    {
        $clinicB = $this->createClinic('allow-manager-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_allow_manager_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        App::membership_service()->create_membership($clinicB, $actor, 'cpms_manager');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-allow', $actor);
        $factory->forClinic($clinicB)->set('sms.provider', 'log', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $newSender = 'new-allow-' . bin2hex(random_bytes(2));
        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => $newSender], $clinicB, $actor);
        self::assertSame(200, $res->get_status(), 'manager in correct clinic must be allowed');
        self::assertSame($newSender, $this->getSender($clinicB), 'settings must be updated for allowed actor');

        // Also test read: status
        $statusRes = $this->dispatchGet('/clinic/v1/sms/status', $clinicB, $actor);
        self::assertSame(200, $statusRes->get_status(), 'status read must be allowed for manager');
        $payload = $statusRes->get_data();
        self::assertArrayHasKey('data', $payload);
        // Ensure no credential plaintext
        $flat = json_encode($payload);
        self::assertStringNotContainsString('top-secret', $flat);
    }

    public function testAllowSameActorInTwoClinicsIndependently(): void
    {
        $clinicA = $this->createClinic('multi-a-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic('multi-b-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_multi_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        App::membership_service()->create_membership($clinicA, $actor, 'cpms_manager');
        App::membership_service()->create_membership($clinicB, $actor, 'cpms_manager');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicA)->set('sms.sender', 'orig-multi-a', $actor);
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-multi-b', $actor);
        $factory->forClinic($clinicA)->set('sms.provider', 'log', $actor);
        $factory->forClinic($clinicB)->set('sms.provider', 'log', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $senderA = 'new-a-' . bin2hex(random_bytes(2));
        $resA = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => $senderA], $clinicA, $actor);
        self::assertSame(200, $resA->get_status(), 'actor must be allowed in clinic A');
        self::assertSame($senderA, $this->getSender($clinicA));
        self::assertSame('orig-multi-b', $this->getSender($clinicB), 'clinic B must remain unchanged when operating on A');

        $senderB = 'new-b-' . bin2hex(random_bytes(2));
        $resB = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => $senderB], $clinicB, $actor);
        self::assertSame(200, $resB->get_status(), 'actor must be allowed in clinic B');
        self::assertSame($senderB, $this->getSender($clinicB));
        self::assertSame($senderA, $this->getSender($clinicA), 'clinic A must remain as set when operating on B');
    }

    // ===== SIDE-EFFECT SAFETY =====

    public function testSideEffectSafetyDeniedSettingsUnchanged(): void
    {
        $clinicB = $this->createClinic('safety-settings-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_safety_settings_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-safety', $actor);
        $factory->forClinic($clinicB)->set('sms.provider', 'log', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-safety'], $clinicB, $actor);
        self::assertSame(403, $res->get_status());
        self::assertSame('orig-safety', $this->getSender($clinicB), 'settings must not mutate on deny');
    }

    public function testSideEffectSafetyDeniedTemplateUnchanged(): void
    {
        $clinicB = $this->createClinic('safety-tmpl-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_safety_tmpl_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.templates', ['appointment_reminder' => ['template_id' => 'orig-tmpl', 'updated_at' => gmdate('c')]], $actor);
        \ClinicCore\Settings\Settings::flushCache();
        $orig = $this->getTemplates($clinicB);
        self::assertSame('orig-tmpl', $orig['appointment_reminder']['template_id'] ?? '');

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/templates', ['event' => 'appointment_reminder', 'template_id' => 'attacker-tmpl'], $clinicB, $actor);
        self::assertSame(403, $res->get_status());
        $after = $this->getTemplates($clinicB);
        self::assertSame('orig-tmpl', $after['appointment_reminder']['template_id'] ?? '', 'templates must not mutate on deny');
    }

    public function testSideEffectSafetyDeniedLastTestNotMutated(): void
    {
        $clinicB = $this->createClinic('safety-lasttest-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_safety_last_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.last_test', ['status' => 'ok', 'at' => 123456, 'provider' => 'log', 'message' => 'orig'], $actor);
        \ClinicCore\Settings\Settings::flushCache();
        $orig = $this->getLastTest($clinicB);
        self::assertSame('orig', $orig['message'] ?? '');

        // test-connection would mutate last_test on success
        $res = $this->dispatchJson('POST', '/clinic/v1/sms/test-connection', ['provider' => 'log'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'test-connection must be denied');
        $after = $this->getLastTest($clinicB);
        self::assertSame('orig', $after['message'] ?? '', 'last_test must not mutate on deny');
    }

    public function testSideEffectSafetyDeniedTestSendNoMessage(): void
    {
        $clinicB = $this->createClinic('safety-send-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_safety_send_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.provider', 'log', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $beforeCount = $this->countSmsMessages($clinicB);

        $res = $this->dispatchPostParams('/clinic/v1/sms/test-send', $clinicB, $actor, ['mobile' => '09120000001', 'message' => 'test']);
        self::assertSame(403, $res->get_status(), 'test-send must be denied');

        $afterCount = $this->countSmsMessages($clinicB);
        self::assertSame($beforeCount, $afterCount, 'no SMS message should be created on deny');

        // Also ensure last_test not mutated via test-send path (testSend does not set last_test, but testConnection does)
        // So we check logs endpoint also denied and no credential disclosure
        $logsRes = $this->dispatchGet('/clinic/v1/sms/logs', $clinicB, $actor, ['per_page' => 5]);
        self::assertSame(403, $logsRes->get_status(), 'logs must be denied');
        $flat = json_encode($logsRes->get_data());
        self::assertStringNotContainsString('sealed', $flat);
        self::assertStringNotContainsString('credential', strtolower($flat));
    }

    public function testDenyReadEndpointsForUnauthorized(): void
    {
        $clinicB = $this->createClinic('deny-read-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_deny_read_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $endpoints = [
            '/clinic/v1/sms/status',
            '/clinic/v1/sms/providers',
            '/clinic/v1/sms/templates',
            '/clinic/v1/sms/logs',
            '/clinic/v1/sms/balance',
        ];
        foreach ($endpoints as $route) {
            $res = $this->dispatchGet($route, $clinicB, $actor);
            self::assertSame(403, $res->get_status(), "GET $route must be denied for unauthorized actor");
        }
    }
}

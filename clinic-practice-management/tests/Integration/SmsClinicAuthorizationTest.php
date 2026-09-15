<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Phase 3 Slice 3 — Clinic-scoped SMS_CONFIG authorization matrix (fixture-corrected).
 *
 * Fixture design (Blocker 1 fix):
 *  - No first-row tenant assumptions (no SELECT ... LIMIT 1, no id=1).
 *  - Each test creates an explicit Organization with dynamic unique data, status=active, asserted insertion.
 *  - Clinics are created under that explicit Organization, with asserted org linkage.
 *
 * Covers:
 * DENY: global admin no membership, explicit deny, suspended, cross-clinic, preset lacks
 * ALLOW: manager in correct clinic, multi-clinic independent
 * SIDE-EFFECT SAFETY: settings/templates/last_test/message unchanged on deny
 * Preserved RED preconditions: coarse cap, active membership, authz denies, endpoint denies no mutation.
 */
final class SmsClinicAuthorizationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        \ClinicCore\Settings\Settings::flushCache();
        App::resetScope();
    }

    protected function tearDown(): void
    {
        App::resetScope();
        parent::tearDown();
    }

    // ===== explicit tenant fixtures (no first-row) =====

    private function createOrganization(string $suffix): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(4));
        $slug = 'org-sms-' . $suffix . '-' . $unique;
        $name = 'Org SMS ' . $suffix . ' ' . $unique;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)',
                $name,
                $slug,
                'active',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "explicit organization $suffix must be created with generated ID");

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, organization_id, status, slug FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d LIMIT 1',
                $id
            ) === null ? '' : $wpdb->prepare(
                'SELECT id, status, slug FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d LIMIT 1',
                $id
            ),
            ARRAY_A
        );
        // The above ternary is defensive; actual query:
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, status, slug FROM ' . $wpdb->prefix . 'cpms_organizations WHERE id = %d LIMIT 1',
                $id
            ),
            ARRAY_A
        );
        self::assertNotNull($row, 'organization row must be retrievable after insertion');
        self::assertSame($id, (int) ($row['id'] ?? 0), 'organization ID must match generated ID');
        self::assertSame('active', (string) ($row['status'] ?? ''), 'organization status must be active');
        self::assertStringStartsWith('org-sms-', (string) ($row['slug'] ?? ''), 'organization slug must be dynamic');

        return $id;
    }

    private function createClinic(int $orgId, string $suffix): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(3));
        $slug = 'sms-authz-' . $suffix . '-' . $unique;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
                $orgId,
                'Clinic SMS ' . $suffix . ' ' . $slug,
                $slug,
                'Asia/Tehran',
                $now,
                $now
            )
        );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, "clinic $suffix must be created with generated ID");

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d LIMIT 1',
                $id
            ),
            ARRAY_A
        );
        self::assertNotNull($row, 'clinic row must be retrievable after insertion');
        self::assertSame($id, (int) ($row['id'] ?? 0), 'clinic ID must match generated ID');
        self::assertSame($orgId, (int) ($row['organization_id'] ?? 0), 'clinic.organization_id must equal explicitly-created organization');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $userId = (int) wp_create_user($login . bin2hex(random_bytes(2)), wp_generate_password(24), $login . '@sms.test');
        self::assertGreaterThan(0, $userId, "user $login must be created");
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

    private function authzService(): \ClinicCore\Application\Authorization\AuthorizationService
    {
        return new \ClinicCore\Application\Authorization\AuthorizationService(new MembershipRepository(App::db()));
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

    // ===== preserved RED preconditions (Blocker 2) =====

    public function testPreservedRedPreconditionsCoarseCapAndAuthzDenyAndNoMutation(): void
    {
        // Explicit organization + clinic (no first-row)
        $orgId = $this->createOrganization('red-preserve-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'red-preserve-b-' . bin2hex(random_bytes(2)));

        $actor = $this->makeUser('sms_red_preserve_' . bin2hex(random_bytes(2)), 'administrator');
        $u = get_userdata($actor);
        self::assertNotFalse($u);
        $u->add_cap('cpms_sms_config');
        $u->add_cap('manage_options');

        // Active durable membership in Clinic B with role lacking SMS_CONFIG + explicit deny
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        self::assertGreaterThan(0, $memId, 'membership must be created');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        // Initial persisted state
        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'original-preserved-' . bin2hex(random_bytes(2)), $actor);
        $factory->forClinic($clinicB)->set('sms.provider', 'log', $actor);
        \ClinicCore\Settings\Settings::flushCache();
        $original = $this->getSender($clinicB);
        self::assertStringStartsWith('original-preserved-', $original, 'original sender must be persisted');

        // Useful preconditions preserved from RED:
        self::assertTrue(user_can($actor, 'cpms_sms_config'), 'coarse WP capability must be present');
        $active = App::membership_service()->active_membership_for($clinicB, $actor);
        self::assertNotNull($active, 'active durable membership must exist');
        self::assertSame('active', $active['status']);
        $svc = $this->authzService();
        self::assertFalse($svc->can($actor, $clinicB, 'cpms_sms_config'), 'AuthorizationService must deny scoped permission');

        // REST dispatch with trusted clinic context
        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-preserved'], $clinicB, $actor);

        self::assertSame(403, $res->get_status(), 'endpoint must deny with 403 after fix');
        self::assertSame($original, $this->getSender($clinicB), 'protected state must not mutate on deny');
    }

    // ===== DENY =====

    public function testDenyGlobalAdminWithNoActiveMembership(): void
    {
        $orgId = $this->createOrganization('no-mem-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'no-mem-b-' . bin2hex(random_bytes(2)));
        $admin = $this->makeUser('sms_admin_no_mem_' . bin2hex(random_bytes(2)), 'administrator');
        $u = get_userdata($admin);
        $u->add_cap('cpms_sms_config');

        self::assertSame([], App::membership_service()->active_memberships_for_user($admin), 'no membership precondition');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-no-mem', $admin);
        \ClinicCore\Settings\Settings::flushCache();
        $orig = $this->getSender($clinicB);
        self::assertSame('orig-no-mem', $orig);

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker'], $clinicB, $admin);
        self::assertSame(403, $res->get_status(), 'global admin without membership must be denied');
        self::assertSame('orig-no-mem', $this->getSender($clinicB), 'settings must remain unchanged on deny');
    }

    public function testDenyActiveMemberWithExplicitDeny(): void
    {
        $orgId = $this->createOrganization('explicit-deny-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'explicit-deny-b-' . bin2hex(random_bytes(2)));
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
        $orgId = $this->createOrganization('suspended-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'suspended-b-' . bin2hex(random_bytes(2)));
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
        $orgId = $this->createOrganization('cross-' . bin2hex(random_bytes(2)));
        $clinicA = $this->createClinic($orgId, 'cross-a-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'cross-b-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_cross_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

        App::membership_service()->create_membership($clinicA, $actor, 'cpms_manager');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.sender', 'orig-cross', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-cross'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'Clinic A auth cannot reach Clinic B');
        self::assertSame('orig-cross', $this->getSender($clinicB));

        $memB = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memB, 'cpms_sms_config', 'deny');
        \ClinicCore\Settings\Settings::flushCache();
        $res2 = $this->dispatchJson('POST', '/clinic/v1/sms/settings', ['provider' => 'log', 'sender' => 'attacker-cross2'], $clinicB, $actor);
        self::assertSame(403, $res2->get_status(), 'explicit deny in B must deny even if A is manager');
        self::assertSame('orig-cross', $this->getSender($clinicB));
    }

    public function testDenyActiveMemberWhoseRolePresetLacksSmsConfig(): void
    {
        $orgId = $this->createOrganization('preset-lack-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'preset-lack-b-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_preset_lack_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');

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
        $orgId = $this->createOrganization('allow-manager-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'allow-manager-b-' . bin2hex(random_bytes(2)));
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

        $statusRes = $this->dispatchGet('/clinic/v1/sms/status', $clinicB, $actor);
        self::assertSame(200, $statusRes->get_status(), 'status read must be allowed for manager');
        $payload = $statusRes->get_data();
        self::assertArrayHasKey('data', $payload);
        $flat = json_encode($payload);
        self::assertStringNotContainsString('top-secret', $flat);
    }

    public function testAllowSameActorInTwoClinicsIndependently(): void
    {
        $orgId = $this->createOrganization('multi-' . bin2hex(random_bytes(2)));
        $clinicA = $this->createClinic($orgId, 'multi-a-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'multi-b-' . bin2hex(random_bytes(2)));
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
        $orgId = $this->createOrganization('safety-settings-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'safety-settings-b-' . bin2hex(random_bytes(2)));
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
        $orgId = $this->createOrganization('safety-tmpl-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'safety-tmpl-b-' . bin2hex(random_bytes(2)));
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
        $orgId = $this->createOrganization('safety-lasttest-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'safety-lasttest-b-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_safety_last_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.last_test', ['status' => 'ok', 'at' => 123456, 'provider' => 'log', 'message' => 'orig'], $actor);
        \ClinicCore\Settings\Settings::flushCache();
        $orig = $this->getLastTest($clinicB);
        self::assertSame('orig', $orig['message'] ?? '');

        $res = $this->dispatchJson('POST', '/clinic/v1/sms/test-connection', ['provider' => 'log'], $clinicB, $actor);
        self::assertSame(403, $res->get_status(), 'test-connection must be denied');
        $after = $this->getLastTest($clinicB);
        self::assertSame('orig', $after['message'] ?? '', 'last_test must not mutate on deny');
    }

    public function testSideEffectSafetyDeniedTestSendNoMessage(): void
    {
        $orgId = $this->createOrganization('safety-send-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'safety-send-b-' . bin2hex(random_bytes(2)));
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

        $logsRes = $this->dispatchGet('/clinic/v1/sms/logs', $clinicB, $actor, ['per_page' => 5]);
        self::assertSame(403, $logsRes->get_status(), 'logs must be denied');
        $flat = json_encode($logsRes->get_data());
        self::assertStringNotContainsString('sealed', $flat);
        self::assertStringNotContainsString('credential', strtolower($flat));
    }

    public function testDenyReadEndpointsForUnauthorized(): void
    {
        $orgId = $this->createOrganization('deny-read-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'deny-read-b-' . bin2hex(random_bytes(2)));
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

    public function testDenyTemplatesTestEndpointForUnauthorized(): void
    {
        $orgId = $this->createOrganization('deny-tmpl-test-' . bin2hex(random_bytes(2)));
        $clinicB = $this->createClinic($orgId, 'deny-tmpl-test-b-' . bin2hex(random_bytes(2)));
        $actor = $this->makeUser('sms_deny_tmpl_test_' . bin2hex(random_bytes(2)), 'administrator');
        get_userdata($actor)->add_cap('cpms_sms_config');
        $memId = App::membership_service()->create_membership($clinicB, $actor, 'cpms_secretary');
        App::membership_service()->set_capability($memId, 'cpms_sms_config', 'deny');

        $factory = App::settingsFactory();
        $factory->forClinic($clinicB)->set('sms.provider', 'log', $actor);
        \ClinicCore\Settings\Settings::flushCache();

        $beforeCount = $this->countSmsMessages($clinicB);
        $origLast = $this->getLastTest($clinicB);

        $res = $this->dispatchPostParams('/clinic/v1/sms/templates/test', $clinicB, $actor, [
            'event' => 'appointment_reminder',
            'mobile' => '09120000001',
            'vars' => [
                'patient_name' => 'Test',
                'doctor_name' => 'Dr',
                'appointment_date' => '1405/01/01',
                'appointment_time' => '10:00',
                'clinic_name' => 'Clinic',
            ],
        ]);
        self::assertSame(403, $res->get_status(), 'POST /sms/templates/test must be denied for unauthorized actor');
        self::assertSame($beforeCount, $this->countSmsMessages($clinicB), 'no SMS on denied templates/test');
        self::assertSame($origLast, $this->getLastTest($clinicB), 'last_test unchanged on denied templates/test');
    }
}

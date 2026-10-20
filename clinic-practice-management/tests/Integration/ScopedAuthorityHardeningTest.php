<?php
/**
 * Round-4 hardening — a Clinic membership is FINAL for its Clinic.
 *
 * The independent review closed a bypass: an actor with an ACTIVE membership
 * that did NOT grant the requested capability could still be authorized by a
 * WordPress-global capability. This suite pins the hardened contract:
 *
 *   ACTIVE membership + effective capability      => allow (Subscriber included)
 *   ACTIVE membership WITHOUT the capability      => DENY (no global fallback)
 *   explicit membership DENY                      => DENY (overrides any global grant)
 *   SUSPENDED / non-active membership             => DENY (never global fallback)
 *   NO membership row at all                      => legacy WordPress path only
 *
 * The tests exercise the real REST boundaries and the AuthorizationService
 * decision helper, not mocks.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class ScopedAuthorityHardeningTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';

    private int $clinic;

    private AuthorizationService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        \ClinicCore\Application\Scope\ScopeContext::clear();
        App::resetScope();
        \ClinicCore\Settings\Settings::flushCache();
        App::migrations()->migrate();
        $this->clinic = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_clinics') . ' ORDER BY id ASC LIMIT 1'
        );
        self::assertGreaterThan(0, $this->clinic, 'precondition: a Clinic exists');
        $this->auth = new AuthorizationService(
            new MembershipRepository(App::db()),
            new CustomRoleRepository(App::db())
        );
    }

    // ------------------------------------------------------------------
    // Decision helper contract.
    // ------------------------------------------------------------------

    public function testActiveMembershipWithoutTheCapabilityIsFinalDeny(): void
    {
        $user = $this->makeUser('sh_acct', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);

        self::assertFalse(
            $this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::PATIENT_READ),
            'the accountant preset does not grant patient read: final DENY, never null'
        );
        self::assertTrue(
            $this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::FINANCE_READ),
            'the same membership still grants its own capabilities'
        );
    }

    public function testSuspendedMembershipIsFinalDenyNotNull(): void
    {
        $user = $this->makeUser('sh_susp', 'subscriber');
        $membership_id = cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->suspend_membership((int) $membership_id);

        self::assertFalse(
            $this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::QUEUE_READ),
            'a suspended membership fails closed and never falls back to WordPress caps'
        );
    }

    public function testNoMembershipAtAllKeepsTheLegacyPath(): void
    {
        $user = $this->makeUser('sh_none', 'subscriber');
        self::assertNull(
            $this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::QUEUE_READ),
            'without a membership row the caller keeps its legacy capability path'
        );
    }

    public function testExplicitGrantWinsOverTheRolePresetAndDenyWinsOverGrant(): void
    {
        $user = $this->makeUser('sh_grant', 'subscriber');
        $membership_id = (int) cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);

        self::assertFalse($this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::PATIENT_READ));
        App::membership_service()->set_capability($membership_id, RolesAndCapabilities::PATIENT_READ, 'grant');
        self::assertTrue(
            $this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::PATIENT_READ),
            'an explicit grant is legitimate scoped authority'
        );
        App::membership_service()->set_capability($membership_id, RolesAndCapabilities::PATIENT_READ, 'deny');
        self::assertFalse(
            $this->auth->scoped_decision($user, $this->clinic, RolesAndCapabilities::PATIENT_READ),
            'deny outranks grant'
        );
    }

    // ------------------------------------------------------------------
    // REST boundaries: no global-capability bypass.
    // ------------------------------------------------------------------

    public function testGlobalCapabilityCannotBuyACapabilityTheMembershipLacks(): void
    {
        $user = $this->makeUser('sh_global', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);
        // A legacy WordPress capability grant that the accountant preset lacks.
        get_userdata($user)->add_cap(RolesAndCapabilities::PATIENT_READ);
        wp_set_current_user($user);
        self::assertTrue(user_can($user, RolesAndCapabilities::PATIENT_READ), 'precondition: the global capability is present');

        $denied = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/patients/search',
            ['q' => 'zz'],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $denied->get_status(), 'the membership decides; the global capability cannot override it');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($denied));
    }

    public function testSuspendedMembershipCannotBeRescuedByAGlobalCapability(): void
    {
        $user = $this->makeUser('sh_susp_cap', 'subscriber');
        $membership_id = (int) cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->suspend_membership($membership_id);
        get_userdata($user)->add_cap(RolesAndCapabilities::QUEUE_READ);
        wp_set_current_user($user);

        $board = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/reception/board',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $board->get_status(), 'a suspended membership is not rescued by a global capability');
    }

    public function testSuspendedClinicIsNotAReceptionSurfaceEvenForAnActiveSecretary(): void
    {
        global $wpdb;
        $user = $this->makeUser('sh_susp_clinic', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        $location = $this->primaryLocation();

        wp_set_current_user($user);
        $ok = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/reception/board',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic, 'X-CPMS-Location-Id' => (string) $location]
        );
        self::assertSame(200, $ok->get_status(), 'baseline reception read — ' . $this->errDetail($ok));

        $wpdb->update($wpdb->prefix . 'cpms_clinics', ['status' => 'suspended'], ['id' => $this->clinic]);
        $denied = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/reception/board',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic, 'X-CPMS-Location-Id' => (string) $location]
        );
        self::assertSame(403, $denied->get_status(), 'a suspended Clinic opens no new reception work');
        $wpdb->update($wpdb->prefix . 'cpms_clinics', ['status' => 'active'], ['id' => $this->clinic]);
    }

    public function testDoctorPrivateUploadRequiresDoctorIdentityNotOnlyAGrant(): void
    {
        // A secretary whose membership carries an explicit GRANT for private-note
        // creation still cannot create a doctor_private file: the durable clinician
        // profile is required.
        $user = $this->makeUser('sh_sec_private', 'subscriber');
        $membership_id = (int) cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        App::membership_service()->set_capability($membership_id, RolesAndCapabilities::PRIVATE_NOTE_CREATE, 'grant');

        self::assertFalse(
            App::authorization_service()->scoped_decision($user, $this->clinic, RolesAndCapabilities::PRIVATE_NOTE_CREATE) === false,
            'the grant is effective at the engine level (the doctor-identity conjunct is the guard under test)'
        );

        // The upload path is exercised through the service with a synthetic file;
        // the guard must reject before any disk write because the actor has no
        // clinician profile.
        wp_set_current_user($user);
        App::replaceExplicitScope(\ClinicCore\Application\Scope\ClinicScope::forClinic($this->clinic));
        $patient = $this->insertPatient($this->clinic);
        $tmp = wp_tempnam('cpms-private-probe');
        self::assertNotSame('', (string) $tmp);
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $threw = false;
        try {
            App::medicalFileService()->upload(
                $user,
                ['name' => 'probe.pdf', 'tmp_name' => $tmp, 'size' => (int) filesize($tmp), 'error' => 0],
                $patient,
                null,
                'document',
                'doctor_private'
            );
        } catch (\Throwable $e) {
            $threw = true;
        } finally {
            App::replaceExplicitScope(null);
        }
        self::assertTrue($threw, 'a non-doctor can never write a doctor_private file');
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    private function insertPatient(int $clinic_id): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $clinic_id,
            'MR-SH-' . strtoupper(bin2hex(random_bytes(3))),
            'Hardening',
            'Patient',
            '0912' . sprintf('%07d', random_int(1000000, 9999999)),
            'active',
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    private function primaryLocation(): int
    {
        $id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1',
            [$this->clinic]
        );
        self::assertGreaterThan(0, $id, 'precondition: an active Location exists');

        return $id;
    }

    private function makeUser(string $login, string $role): int
    {
        $name = $login . '_' . bin2hex(random_bytes(3));
        $id   = (int) wp_create_user($name, 'pass-not-used-123', $name . '@test.local');
        self::assertGreaterThan(0, $id);
        get_userdata($id)->set_role($role);

        return $id;
    }

    private function dispatch(string $method, string $route, array $params = [], array $headers = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }

        return rest_do_request($request);
    }

    private function errCode(WP_REST_Response $response): string
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }

        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }

    private function errDetail(WP_REST_Response $response): string
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code() . ' :: ' . (string) $body->get_error_message();
        }

        return (string) wp_json_encode($body);
    }
}

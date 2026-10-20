<?php
/**
 * Owner policy — WordPress-independent business access.
 *
 * A WordPress Subscriber that holds a durable CPMS membership and the effective
 * scoped permission of the explicitly selected Clinic must be able to use the
 * operational portals without any privileged WordPress role. Authority is CPMS
 * data only:
 *
 *   authenticated actor + explicit trusted Clinic/Location scope
 *   + ACTIVE membership + effective scoped capability (DENY > GRANT > preset)
 *   + server-derived clinician identity where the operation requires it.
 *
 * These tests are the negative/positive contract of that policy for the Doctor
 * Portal and Reception boundaries. Cross-Clinic authority is never accepted,
 * explicit DENY wins, suspended memberships fail closed, an ambiguous scope
 * fails closed, and a WordPress administrator without CPMS authority is denied.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Frontend\StaffPortalShell;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class SubscriberOperationalAccessTest extends WP_UnitTestCase
{
    private const REST_NS = 'clinic/v1';

    private int $clinic;

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
    }

    // ------------------------------------------------------------------
    // 1. Subscriber doctor: the operational read path is open on CPMS data.
    // ------------------------------------------------------------------

    public function testSubscriberDoctorUsesDoctorPortalOnScopedAuthorityAlone(): void
    {
        $fx = $this->makeSubscriberDoctor('sub_pos');

        wp_set_current_user($fx['user']);
        self::assertFalse(user_can($fx['user'], RolesAndCapabilities::QUEUE_READ), 'precondition: no WordPress-global capability');
        self::assertContains('subscriber', (array) get_userdata($fx['user'])->roles, 'precondition: plain Subscriber');

        $today = $this->doctorRequest('GET', '/doctor/today', $fx['location']);
        self::assertSame(200, $today->get_status(), 'Subscriber doctor Today must be reachable :: ' . $this->errDetail($today));

        $queue = $this->doctorRequest('GET', '/doctor/portal/rt/queue', $fx['location']);
        self::assertSame(200, $queue->get_status(), 'Subscriber doctor live queue must be reachable :: code=' . $this->errCode($queue));

        // Workspace boundary: identity + scoped capability are enough; the record
        // itself is still owned by the durable visit.
        $workspace = $this->doctorRequest('GET', '/doctor/portal/visits/' . $fx['visit'] . '/record', $fx['location']);
        self::assertSame(200, $workspace->get_status(), 'Subscriber doctor opens the visit record on scoped authority :: code=' . $this->errCode($workspace));

        // Bootstrap context/selector: lists only the Clinics the scoped engine
        // allows for this actor (never authority by itself).
        $context = $this->doctorRequest('GET', '/doctor/portal/context', $fx['location']);
        self::assertSame(200, $context->get_status(), 'Subscriber doctor context must be reachable :: code=' . $this->errCode($context));
        $clinics = array_map('intval', array_column($this->payload($context)['clinics'] ?? [], 'id'));
        self::assertContains($this->clinic, $clinics, 'the authorized Clinic is offered');
    }

    // ------------------------------------------------------------------
    // 2. Cross-Clinic isolation: Clinic A authority never opens Clinic B.
    // ------------------------------------------------------------------

    public function testSubscriberDoctorIsRefusedInAForeignClinic(): void
    {
        $fx   = $this->makeSubscriberDoctor('sub_cross');
        $away = $this->insertClinic('Foreign Subscriber Clinic', 'sub-foreign-' . bin2hex(random_bytes(3)));
        $awayLocation = $this->insertLocation($away, 'Foreign Loc', 'sub-foreign-loc-' . bin2hex(random_bytes(3)));

        wp_set_current_user($fx['user']);
        $foreign = $this->doctorRequest('GET', '/doctor/today', $awayLocation, $away);
        self::assertSame(403, $foreign->get_status(), 'Clinic A authority must not open Clinic B');
        self::assertContains(
            $this->errCode($foreign),
            ['CLINIC_SCOPE_UNAVAILABLE', 'CLINIC_PERMISSION_DENIED'],
            'the foreign Clinic is refused by the trusted-scope or capability boundary'
        );
    }

    // ------------------------------------------------------------------
    // 3. Explicit membership DENY overrides the role preset.
    // ------------------------------------------------------------------

    public function testExplicitDenyOverridesTheDoctorPresetForASubscriber(): void
    {
        $fx = $this->makeSubscriberDoctor('sub_deny');
        $membership = App::membership_service()->membership_for($this->clinic, $fx['user']);
        self::assertNotNull($membership);
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::QUEUE_READ, 'deny');

        wp_set_current_user($fx['user']);
        $denied = $this->doctorRequest('GET', '/doctor/today', $fx['location']);
        self::assertSame(403, $denied->get_status(), 'explicit DENY must win over the doctor preset');
    }

    // ------------------------------------------------------------------
    // 4. Suspended membership and inactive Location fail closed.
    // ------------------------------------------------------------------

    public function testSuspendedMembershipFailsClosedForASubscriber(): void
    {
        $fx = $this->makeSubscriberDoctor('sub_susp');
        $membership = App::membership_service()->membership_for($this->clinic, $fx['user']);
        self::assertNotNull($membership);
        App::membership_service()->suspend_membership((int) $membership['id']);

        wp_set_current_user($fx['user']);
        $denied = $this->doctorRequest('GET', '/doctor/today', $fx['location']);
        self::assertSame(403, $denied->get_status(), 'a suspended membership supplies nothing');
    }

    public function testInactiveLocationFailsClosedForASubscriber(): void
    {
        $fx = $this->makeSubscriberDoctor('sub_loc');
        // A Location without Visit continuity: inactive means it can never be
        // an operational scope for new Doctor Portal demand.
        $inactive = $this->insertLocation($this->clinic, 'Inactive Loc', 'sub-inactive-' . bin2hex(random_bytes(3)));
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'cpms_locations', ['is_active' => 0], ['id' => $inactive]);

        wp_set_current_user($fx['user']);
        $denied = $this->doctorRequest('GET', '/doctor/today', $inactive);
        self::assertSame(403, $denied->get_status(), 'an inactive Location without continuity is not an operational scope');

        // Control: the same actor keeps the existing Visit's Location.
        $allowed = $this->doctorRequest('GET', '/doctor/today', $fx['location']);
        self::assertSame(200, $allowed->get_status(), 'the active Location of the same actor stays reachable :: ' . $this->errDetail($allowed));
    }

    // ------------------------------------------------------------------
    // 5. Ambiguous / missing scope fails closed.
    // ------------------------------------------------------------------

    public function testMissingClinicScopeFailsClosedForASubscriber(): void
    {
        $fx = $this->makeSubscriberDoctor('sub_noscope');
        $second = $this->insertClinic('Second Subscriber Clinic', 'sub-second-' . bin2hex(random_bytes(3)));
        cpms_test_seed_membership($fx['user'], $second, RolesAndCapabilities::ROLE_DOCTOR);

        wp_set_current_user($fx['user']);
        $unscoped = $this->dispatch('GET', '/' . self::REST_NS . '/doctor/portal/rt/queue');
        self::assertContains($unscoped->get_status(), [400, 403], 'multi-Clinic ambiguity must not resolve implicitly');
        self::assertNotSame(200, $unscoped->get_status(), 'no implicit Clinic is ever chosen');

        $reception = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/reception/board',
            ['clinic_id' => $this->clinic],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(400, $reception->get_status(), 'Reception requires an explicit operational Location');
    }

    // ------------------------------------------------------------------
    // 6. Identity and membership are still required.
    // ------------------------------------------------------------------

    public function testWordPressAdministratorWithoutCpmsAuthorityIsDenied(): void
    {
        $admin = $this->makeUser('sub_admin', 'administrator');
        $location = $this->primaryLocation();

        wp_set_current_user($admin);
        $denied = $this->doctorRequest('GET', '/doctor/today', $location);
        self::assertSame(403, $denied->get_status(), 'a WordPress administrator has no CPMS authority by itself');
    }

    public function testSubscriberWithoutIdentityOrMembershipIsDenied(): void
    {
        // Clinician identity but no membership.
        $user = $this->makeUser('sub_nomem', 'subscriber');
        $this->insertClinician('Dr No Membership', 1, $user);
        wp_set_current_user($user);
        $noMembership = $this->doctorRequest('GET', '/doctor/today', $this->primaryLocation());
        self::assertSame(403, $noMembership->get_status(), 'clinician identity alone is never authority');

        // Membership but no clinician identity.
        $plain = $this->makeUser('sub_noident', 'subscriber');
        cpms_test_seed_membership($plain, $this->clinic, RolesAndCapabilities::ROLE_DOCTOR);
        wp_set_current_user($plain);
        $noIdentity = $this->doctorRequest('GET', '/doctor/today', $this->primaryLocation());
        self::assertSame(403, $noIdentity->get_status(), 'a doctor membership without an active clinician profile is not a doctor');
    }

    // ------------------------------------------------------------------
    // 7. Reception: Subscriber secretary uses the same scoped model.
    // ------------------------------------------------------------------

    public function testSubscriberSecretaryReachesReceptionContextOnScopedAuthority(): void
    {
        $user = $this->makeUser('sub_sec', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($user);
        self::assertFalse(user_can($user, RolesAndCapabilities::QUEUE_READ), 'precondition: no WordPress-global capability');

        $context = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context');
        self::assertSame(200, $context->get_status(), 'Subscriber secretary must reach the Reception context');

        // Cross-Clinic probe: a foreign Clinic id discloses nothing.
        $away         = $this->insertClinic('Foreign Reception Clinic', 'sub-rec-foreign-' . bin2hex(random_bytes(3)));
        $awayLocation = $this->insertLocation($away, 'Foreign Rec Loc', 'sub-rec-foreign-loc-' . bin2hex(random_bytes(3)));
        $foreign      = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/reception/board',
            ['clinic_id' => $away],
            ['X-CPMS-Clinic-Id' => (string) $away, 'X-CPMS-Location-Id' => (string) $awayLocation]
        );
        self::assertSame(403, $foreign->get_status(), 'Reception authority never crosses Clinics');
    }

    // ------------------------------------------------------------------
    // 8. Finance and Reports: Subscriber accountant on scoped authority.
    // ------------------------------------------------------------------

    public function testSubscriberUsesFinanceAndReportsOnScopedAuthority(): void
    {
        // The doctor preset carries the finance read set (finance/invoice/queue),
        // so a plain Subscriber with that membership must reach the finance board.
        $fx   = $this->makeSubscriberDoctor('sub_fin');
        $user = $fx['user'];
        $membership = App::membership_service()->membership_for($this->clinic, $user);
        self::assertNotNull($membership);
        wp_set_current_user($user);
        self::assertFalse(user_can($user, RolesAndCapabilities::FINANCE_READ), 'precondition: no WordPress-global finance capability');

        $finance = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/finance/context',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(200, $finance->get_status(), 'Subscriber with the scoped finance read set reaches the finance board context');

        $reports = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(200, $reports->get_status(), 'Subscriber with the scoped report capability reaches the reports catalog');

        // Explicit DENY on the same membership overrides the preset for both surfaces.
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::FINANCE_READ, 'deny');
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::REPORT_READ, 'deny');
        $deniedFinance = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/finance/context',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $deniedFinance->get_status(), 'explicit DENY on cpms_finance_read wins over the preset');
        $deniedReports = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $deniedReports->get_status(), 'explicit DENY on cpms_report_read wins over the preset');
    }

    public function testSubscriberWithoutFinanceAuthorityIsDeniedTheFinanceBoard(): void
    {
        // A finance-capable membership with an explicit DENY on the finance read
        // capability must be refused, even as a WordPress Subscriber.
        $fx = $this->makeSubscriberDoctor('sub_fin_deny');
        $membership = App::membership_service()->membership_for($this->clinic, $fx['user']);
        self::assertNotNull($membership);
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::FINANCE_READ, 'deny');
        wp_set_current_user($fx['user']);
        $finance = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/finance/context',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $finance->get_status(), 'an explicit DENY on the finance capability refuses the board :: code=' . $this->errCode($finance));
    }

    // ------------------------------------------------------------------
    // 9. TRUE Subscriber accountant (not a doctor fixture): the finance board
    //    and the report surfaces work on the accountant preset alone.
    // ------------------------------------------------------------------

    public function testTrueSubscriberAccountantReadsFinanceAndReports(): void
    {
        $user = $this->makeUser('sub_acct', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);
        wp_set_current_user($user);

        self::assertFalse(user_can($user, RolesAndCapabilities::FINANCE_READ), 'precondition: no WordPress-global finance capability');
        self::assertFalse(user_can($user, RolesAndCapabilities::QUEUE_READ), 'precondition: no WordPress-global queue capability');
        $membership = App::membership_service()->membership_for($this->clinic, $user);
        self::assertNotNull($membership);
        self::assertSame(RolesAndCapabilities::ROLE_ACCOUNTANT, (string) $membership['role_key']);

        // The accountant preset is the finance board's read authority: finance +
        // invoice read in ONE Clinic, and queue read is NOT required.
        self::assertTrue(StaffPortalShell::finance_module_eligible($user), 'the accountant membership opens the finance board');

        $finance = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/finance/context',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(200, $finance->get_status(), 'Subscriber accountant finance context — ' . $this->errDetail($finance));

        $reports = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(200, $reports->get_status(), 'Subscriber accountant report catalog — ' . $this->errDetail($reports));

        // Explicit DENY on a report-specific capability is FINAL for the scope,
        // even though the legacy WordPress role would carry it.
        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::REPORT_READ, 'deny');
        $denied = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $denied->get_status(), 'explicit DENY on cpms_report_read refuses the catalog');
    }

    // ------------------------------------------------------------------
    // 10. Explicit scoped DENY overrides a legacy WordPress business role.
    // ------------------------------------------------------------------

    public function testScopedDenyOverridesALegacyWordPressRoleCapability(): void
    {
        $user = $this->makeUser('legacy_acct', RolesAndCapabilities::ROLE_ACCOUNTANT);
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_ACCOUNTANT);
        $membership = App::membership_service()->membership_for($this->clinic, $user);
        self::assertNotNull($membership);
        wp_set_current_user($user);
        self::assertTrue(user_can($user, RolesAndCapabilities::REPORT_READ), 'precondition: the legacy WordPress role carries report read');

        $allowed = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(200, $allowed->get_status(), 'the baseline read is authorized — ' . $this->errDetail($allowed));

        App::membership_service()->set_capability((int) $membership['id'], RolesAndCapabilities::REPORT_READ, 'deny');
        $denied = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertSame(403, $denied->get_status(), 'the scoped DENY outranks the WordPress-global capability');

        $export = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/reports/export',
            [],
            ['X-CPMS-Clinic-Id' => (string) $this->clinic]
        );
        self::assertContains($export->get_status(), [400, 403, 404, 422], 'no export payload is produced for a denied actor');
        self::assertNotSame(200, $export->get_status());
    }

    // ------------------------------------------------------------------
    // 11. Reception: an explicit DENY on the required capabilities refuses an
    //     UNSCOPED request with 403 (never a 400 selector oracle).
    // ------------------------------------------------------------------

    public function testReceptionUnscopedRequestIsDeniedWhenReceptionAuthorityIsDenied(): void
    {
        $user = $this->makeUser('sub_sec_deny', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        $membership = App::membership_service()->membership_for($this->clinic, $user);
        self::assertNotNull($membership);
        foreach ([RolesAndCapabilities::QUEUE_READ, RolesAndCapabilities::QUEUE_CHECKIN, RolesAndCapabilities::QUEUE_ADVANCE] as $cap) {
            App::membership_service()->set_capability((int) $membership['id'], $cap, 'deny');
        }
        wp_set_current_user($user);

        // Unscoped context and unscoped operational read.
        $context = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context');
        self::assertSame(403, $context->get_status(), 'a denied actor is refused, not asked for a Clinic — ' . $this->errDetail($context));
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($context));

        $board = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board');
        self::assertSame(403, $board->get_status(), 'unscoped operational read of a denied actor is 403');
        self::assertSame('CLINIC_PERMISSION_DENIED', $this->errCode($board));

        // An actor that DOES hold Reception authority somewhere still gets the
        // explicit-selector contract (the 400 is never granted to a denied actor).
        $secretary = $this->makeUser('sub_sec_ok', 'subscriber');
        cpms_test_seed_membership($secretary, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($secretary);
        $selector = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/board');
        self::assertSame(400, $selector->get_status(), 'an authorized actor is asked for the explicit Clinic');
        self::assertSame('CLINIC_SCOPE_REQUIRED', $this->errCode($selector));
    }

    // ------------------------------------------------------------------
    // 12. Suspended Organization / suspended Clinic never qualify.
    // ------------------------------------------------------------------

    public function testSuspendedOrganizationAndClinicAreNotOperational(): void
    {
        global $wpdb;
        $user = $this->makeUser('sub_sec_life', 'subscriber');
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_SECRETARY);
        wp_set_current_user($user);

        $context = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context');
        self::assertSame(200, $context->get_status(), 'baseline context — ' . $this->errDetail($context));
        self::assertContains(
            $this->clinic,
            array_map('intval', array_column($this->payload($context)['clinics'] ?? [], 'id')),
            'baseline: the operational Clinic is offered'
        );

        $location = $this->primaryLocation();
        $clinic_id = $this->clinic;
        $org_id = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = ' . $clinic_id);

        // Suspended Organization.
        $wpdb->update($wpdb->prefix . 'cpms_organizations', ['status' => 'suspended'], ['id' => $org_id]);
        $suspendedOrg = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context');
        self::assertNotContains(
            $clinic_id,
            array_map('intval', array_column($this->payload($suspendedOrg)['clinics'] ?? [], 'id')),
            'a suspended Organization removes the Clinic from the selector'
        );
        $boardOrg = $this->dispatch(
            'GET',
            '/' . self::REST_NS . '/staff/portal/reception/board',
            [],
            ['X-CPMS-Clinic-Id' => (string) $clinic_id, 'X-CPMS-Location-Id' => (string) $location]
        );
        self::assertSame(403, $boardOrg->get_status(), 'no operational read under a suspended Organization');
        $wpdb->update($wpdb->prefix . 'cpms_organizations', ['status' => 'active'], ['id' => $org_id]);

        // Suspended Clinic.
        $wpdb->update($wpdb->prefix . 'cpms_clinics', ['status' => 'suspended'], ['id' => $clinic_id]);
        $suspendedClinic = $this->dispatch('GET', '/' . self::REST_NS . '/staff/portal/reception/context');
        self::assertNotContains(
            $clinic_id,
            array_map('intval', array_column($this->payload($suspendedClinic)['clinics'] ?? [], 'id')),
            'a suspended Clinic is removed from the selector'
        );
        $wpdb->update($wpdb->prefix . 'cpms_clinics', ['status' => 'active'], ['id' => $clinic_id]);
    }

    // ------------------------------------------------------------------
    // 13. Authorization before PHI/object lookup: a foreign EXISTING visit and
    //     a non-existent visit are indistinguishable.
    // ------------------------------------------------------------------

    public function testAuthorizationPrecedesPhiLookupForForeignAndMissingVisits(): void
    {
        $fx = $this->makeSubscriberDoctor('sub_phi');
        $away = $this->insertClinic('PHI Foreign Clinic', 'sub-phi-foreign-' . bin2hex(random_bytes(3)));
        $awayLocation = $this->insertLocation($away, 'PHI Foreign Loc', 'sub-phi-foreign-loc-' . bin2hex(random_bytes(3)));

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $away,
            'MR-PHI-' . strtoupper(bin2hex(random_bytes(2))),
            'PhiCanary',
            'ForeignPatient',
            '0912' . sprintf('%07d', random_int(1000000, 9999999)),
            'active',
            $now,
            $now
        ));
        $foreignPatient = (int) $wpdb->insert_id;
        $awayClinician = $this->insertClinician('Dr Away', $away, 999999);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, active, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, %s, %s, %s, 1, %s, %s)',
            $away,
            $awayLocation,
            $awayClinician,
            $foreignPatient,
            'walk_in',
            'waiting',
            '2026-01-01',
            $now,
            $now
        ));
        $foreignVisit = (int) $wpdb->insert_id;
        $missingVisit = $foreignVisit + 999999;

        wp_set_current_user($fx['user']);
        $existing = $this->doctorRequest('GET', '/doctor/portal/visits/' . $foreignVisit . '/record', $fx['location']);
        $missing  = $this->doctorRequest('GET', '/doctor/portal/visits/' . $missingVisit . '/record', $fx['location']);

        self::assertSame(
            $existing->get_status(),
            $missing->get_status(),
            'a foreign EXISTING visit and a NONEXISTENT visit share one envelope (no existence oracle)'
        );
        self::assertSame(
            $this->errCode($existing),
            $this->errCode($missing),
            'the denial code never discloses whether the identifier exists'
        );
        self::assertNotSame(200, $existing->get_status(), 'no foreign PHI is served');
        $body = wp_json_encode($existing->get_data());
        self::assertStringNotContainsString('PhiCanary', (string) $body, 'no foreign patient identity in the denial');
        self::assertStringNotContainsString('ForeignPatient', (string) $body, 'no foreign patient identity in the denial');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array{user:int, clinician:int, location:int, visit:int}
     */
    private function makeSubscriberDoctor(string $tag): array
    {
        $user      = $this->makeUser($tag, 'subscriber');
        $clinician = $this->insertClinician('Dr ' . $tag, $this->clinic, $user);
        self::assertGreaterThan(0, $clinician, 'fixture: the professional profile row must exist');
        self::assertNotNull(
            App::db()->fetchValue(
                'SELECT id FROM ' . App::db()->table('cpms_clinicians') . ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
                [$user]
            ),
            'fixture: the professional profile must resolve by WordPress user id'
        );
        cpms_test_seed_membership($user, $this->clinic, RolesAndCapabilities::ROLE_DOCTOR);
        $location = $this->primaryLocation();

        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s)',
            $this->clinic,
            'MR-' . strtoupper($tag) . '-' . bin2hex(random_bytes(2)),
            'Subscriber',
            'Patient ' . strtoupper($tag),
            '0912' . sprintf('%07d', random_int(1000000, 9999999)),
            'active',
            $now,
            $now
        ));
        $patient = (int) $wpdb->insert_id;

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, active, created_at, updated_at)'
            . ' VALUES (%d, %d, %d, %d, %s, %s, %s, %s, %s, 1, %s, %s)',
            $this->clinic,
            $location,
            $clinician,
            $patient,
            'walk_in',
            'waiting',
            $today,
            $today . ' 09:00:00',
            $today . ' 09:00:00',
            $now,
            $now
        ));
        $visit = (int) $wpdb->insert_id;

        return ['user' => $user, 'clinician' => $clinician, 'location' => $location, 'visit' => $visit];
    }

    private function makeUser(string $login, string $role): int
    {
        $name = $login . '_' . bin2hex(random_bytes(3));
        $id   = (int) wp_create_user($name, 'pass-not-used-123', $name . '@test.local');
        self::assertGreaterThan(0, $id);
        $user = get_userdata($id);
        self::assertNotFalse($user);
        $user->set_role($role);
        return $id;
    }

    private function primaryLocation(): int
    {
        $id = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id ASC LIMIT 1',
            [$this->clinic]
        );
        self::assertGreaterThan(0, $id, 'precondition: primary Location exists');
        return $id;
    }

    private function insertClinic(string $name, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $org = (int) $wpdb->get_var('SELECT organization_id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = ' . $this->clinic);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (organization_id, name, slug, timezone, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)',
            $org > 0 ? $org : 1,
            $name,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);
        App::resetScope();
        return $id;
    }

    private function insertLocation(int $clinicId, string $name, string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 0, 1, %s, %s)',
            $clinicId,
            $name,
            $slug,
            'Asia/Tehran',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertClinician(string $name, int $clinicId, int $wpUserId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, %d, %s, %s)',
            $clinicId,
            $name,
            $wpUserId,
            1,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function doctorRequest(string $method, string $route, ?int $location = null, ?int $clinic = null): WP_REST_Response
    {
        $clinicId = $clinic ?? $this->clinic;
        $headers  = ['X-CPMS-Clinic-Id' => (string) $clinicId];
        if ($location !== null) {
            $headers['X-CPMS-Location-Id'] = (string) $location;
        }
        return $this->dispatch($method, '/' . self::REST_NS . $route, [], $headers);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
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

    private function errDetail(WP_REST_Response $response): string
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code() . ' :: ' . (string) $body->get_error_message() . ' :: ' . wp_json_encode($body->get_error_data());
        }

        return wp_json_encode($body);
    }

    private function errCode(WP_REST_Response $response): string
    {
        $body = $response->get_data();
        if ($body instanceof \WP_Error) {
            return (string) $body->get_error_code();
        }
        return (string) (is_array($body) ? ($body['code'] ?? '') : '');
    }
}

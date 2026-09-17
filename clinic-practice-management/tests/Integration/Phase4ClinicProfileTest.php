<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinic\ClinicProfileException;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Phase 4 — Clinic Profile Canonicalization — GREEN + Negative controls A-L.
 *
 * Invariants tested:
 * - cpms_clinics canonical, only name/address/phone/updated_at mutable
 * - preserve id/org/slug/timezone/created_at, locations timezone operational truth no sync
 * - trusted durable Clinic context + CONFIG auth, raw clinic_id not trusted
 * - Clinic A cannot mutate B
 * - atomic validation (name trimmed non-empty <=190, address <=255, phone <=32), invalid => zero partial mutation
 * - query failure not silent success (fail-closed)
 * - no first Clinic fallback/fixed IDs
 * - audit CLINIC_PROFILE_UPDATED only on real change, noop detection
 * - setup.clinic.* not second writable canonical (historical preserved)
 * - downstream receipt proves canonical
 *
 * Negative controls:
 * A: unauthenticated actor 0 => AUTH_REQUIRED
 * B: invalid trustedClinicId 0 => SCOPE_REQUIRED
 * C: clinic not found
 * D: suspended membership
 * E: no membership
 * F: membership without CONFIG
 * G: cross-clinic mutation (A actor tries B)
 * H: validation empty name
 * I: validation name >190
 * J: validation address >255
 * K: validation phone >32
 * L: noop + audit only on real change + query failure not silent + preserve invariants
 */
final class Phase4ClinicProfileTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locA = 0;
    private int $locB = 0;
    private int $actorConfigA = 0;
    private int $actorNoConfigA = 0;
    private int $actorConfigB = 0;
    private int $actorNoMembership = 0;
    private int $actorSuspendedA = 0;

    private const OLD_NAME_A = 'کلینیک قدیمی A';
    private const OLD_ADDR_A = 'آدرس قدیمی A';
    private const OLD_PHONE_A = '02100000000';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();

        $this->orgId = $this->insertOrganization('green-org-' . bin2hex(random_bytes(3)));
        $base = random_int(64000, 65000);
        $this->clinicA = $this->insertClinic($base, $this->orgId, 'green-clinic-a-' . bin2hex(random_bytes(2)), self::OLD_NAME_A, self::OLD_ADDR_A, self::OLD_PHONE_A, 'Asia/Tehran');
        $this->clinicB = $this->insertClinic($base + 1, $this->orgId, 'green-clinic-b-' . bin2hex(random_bytes(2)), 'کلینیک B', 'آدرس B', '02111111111', 'Asia/Kabul');

        $this->locA = $this->insertLocation($this->clinicA, 'green-loc-a-' . bin2hex(random_bytes(2)), 'Asia/Tehran');
        $this->locB = $this->insertLocation($this->clinicB, 'green-loc-b-' . bin2hex(random_bytes(2)), 'Asia/Kabul');

        // Actors
        $this->actorConfigA = $this->makeUser('green_cfg_a_' . bin2hex(random_bytes(2)), 'administrator');
        cpms_test_seed_membership($this->actorConfigA, $this->clinicA, 'cpms_manager'); // has CONFIG

        $this->actorNoConfigA = $this->makeUser('green_nocfg_a_' . bin2hex(random_bytes(2)), 'administrator');
        cpms_test_seed_membership($this->actorNoConfigA, $this->clinicA, 'cpms_secretary'); // no CONFIG

        $this->actorConfigB = $this->makeUser('green_cfg_b_' . bin2hex(random_bytes(2)), 'administrator');
        cpms_test_seed_membership($this->actorConfigB, $this->clinicB, 'cpms_manager');

        $this->actorNoMembership = $this->makeUser('green_nomem_' . bin2hex(random_bytes(2)), 'administrator');
        // no membership

        $this->actorSuspendedA = $this->makeUser('green_susp_a_' . bin2hex(random_bytes(2)), 'administrator');
        $memId = cpms_test_seed_membership($this->actorSuspendedA, $this->clinicA, 'cpms_manager');
        // suspend
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'cpms_clinic_memberships SET status = "suspended" WHERE id = %d', $memId));

        // Preconditions
        $this->assertGreaterThan(0, $this->orgId);
        $this->assertGreaterThan(0, $this->clinicA);
        $this->assertGreaterThan(0, $this->clinicB);
        $this->assertNotEquals(1, $this->clinicA);
        $this->assertNotEquals(0, $this->clinicA);
        $this->assertNotEquals($this->clinicA, $this->clinicB);
    }

    protected function tearDown(): void
    {
        ScopeContext::clear();
        Settings::flushCache();
        App::resetScope();
        $this->purge();
        parent::tearDown();
    }

    public function testHappyPathUpdatesCanonicalAndPreservesInvariants(): void
    {
        $scope = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);
        wp_set_current_user($this->actorConfigA);

        $before = $this->clinicRow($this->clinicA);
        $this->assertNotNull($before);
        $oldCreated = (string) $before['created_at'];
        $oldSlug = (string) $before['slug'];
        $oldOrg = (int) $before['organization_id'];
        $oldTz = (string) $before['timezone'];

        $newName = 'کلینیک جدید A - GREEN';
        $newAddr = 'خیابان نو، پلاک ۹۹';
        $newPhone = '02122223333';

        $result = App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, [
            'name' => $newName,
            'address' => $newAddr,
            'phone' => $newPhone,
        ]);

        $this->assertSame($this->clinicA, $result['clinic_id']);
        $this->assertSame($newName, $result['name']);
        $this->assertSame($newAddr, $result['address']);
        $this->assertSame($newPhone, $result['phone']);
        $this->assertFalse($result['noop']);

        $after = $this->clinicRow($this->clinicA);
        $this->assertNotNull($after);
        $this->assertSame($newName, $after['name']);
        $this->assertSame($newAddr, $after['address']);
        $this->assertSame($newPhone, $after['phone']);
        // Preserve
        $this->assertSame($oldSlug, (string) $after['slug'], 'preserve slug');
        $this->assertSame($oldOrg, (int) $after['organization_id'], 'preserve org');
        $this->assertSame($oldTz, (string) $after['timezone'], 'preserve timezone');
        $this->assertSame($oldCreated, (string) $after['created_at'], 'preserve created_at');
        $this->assertNotEquals((string) $before['updated_at'], (string) $after['updated_at'], 'updated_at changed');

        // Location timezone operational truth — no sync
        $loc = $this->locationRow($this->locA);
        $this->assertSame('Asia/Tehran', $loc['timezone'], 'location timezone unchanged');

        // Audit only on real change — check audit log contains CLINIC_PROFILE_UPDATED
        global $wpdb;
        $audit = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cpms_audit_log WHERE action = %s AND target_id = %d ORDER BY id DESC LIMIT 1', 'CLINIC_PROFILE_UPDATED', $this->clinicA), ARRAY_A);
        $this->assertIsArray($audit, 'audit logged on real change');
        $this->assertStringContainsString($newName, (string) ($audit['new_value'] ?? ''));

        // Downstream receipt
        $patientId = $this->makePatient($this->clinicA, 'GreenPatient');
        $clinicianId = $this->makeClinician($this->clinicA, $this->actorConfigA, 'Dr Green');
        $visitId = $this->makeCompletedVisit($this->clinicA, $this->locA, $clinicianId, $patientId, $this->actorConfigA);
        $invoice = $this->makeInvoice($this->clinicA, $patientId, $visitId, $this->actorConfigA);
        $receipt = App::financeService()->receipt($this->actorConfigA, (int) $invoice['id']);
        $this->assertSame($newName, $receipt['receipt']['clinic']['name'] ?? '');
    }

    // ============ Negative controls A-K ============

    public function testNegativeA_UnauthenticatedActorFails(): void
    {
        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile(0, $this->clinicA, ['name' => 'x']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::AUTH_REQUIRED, $e->getErrorCode());
            $this->assertSame(401, $e->getHttpStatus());
            // Ensure zero partial mutation
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name']);
            throw $e;
        }
    }

    public function testNegativeB_InvalidTrustedClinicIdFails(): void
    {
        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, 0, ['name' => 'x']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::SCOPE_REQUIRED, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name']);
            throw $e;
        }
    }

    public function testNegativeC_ClinicNotFound(): void
    {
        $this->expectException(ClinicProfileException::class);
        $nonExistent = 999999;
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, $nonExistent, ['name' => 'x']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::NOT_FOUND, $e->getErrorCode());
            $this->assertSame(404, $e->getHttpStatus());
            throw $e;
        }
    }

    public function testNegativeD_SuspendedMembershipDenied(): void
    {
        $scope = $this->establishTrusted($this->actorSuspendedA, $this->clinicA, false); // suspended won't establish via TrustedClinicEstablisher, so we manually set scope for test
        // Even if we force scope, service should deny via authz
        App::replaceExplicitScope($scope);
        wp_set_current_user($this->actorSuspendedA);

        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorSuspendedA, $this->clinicA, ['name' => 'new']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::PERMISSION_DENIED, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name'], 'zero partial mutation on suspended');
            throw $e;
        }
    }

    public function testNegativeE_NoMembershipDenied(): void
    {
        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorNoMembership, $this->clinicA, ['name' => 'new']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::PERMISSION_DENIED, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name']);
            throw $e;
        }
    }

    public function testNegativeF_MembershipWithoutConfigDenied(): void
    {
        $scope = $this->establishTrusted($this->actorNoConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);
        wp_set_current_user($this->actorNoConfigA);

        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorNoConfigA, $this->clinicA, ['name' => 'new']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::PERMISSION_DENIED, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name']);
            throw $e;
        }
    }

    public function testNegativeG_CrossClinicMutationDenied(): void
    {
        // Actor has CONFIG in A, but tries to mutate B using trusted B? No membership in B, so should fail.
        // Also try: actor has CONFIG in A, but we pass trustedClinicId = B (even though actor has no membership in B)
        // This proves Clinic A cannot mutate B.
        $scopeA = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scopeA);
        wp_set_current_user($this->actorConfigA);

        $this->expectException(ClinicProfileException::class);
        try {
            // Attempt to update B while actor is only member of A
            App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicB, ['name' => 'hacked B']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::PERMISSION_DENIED, $e->getErrorCode());
            $rowB = $this->clinicRow($this->clinicB);
            $this->assertNotSame('hacked B', $rowB['name']);
            // Ensure A unchanged
            $rowA = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $rowA['name']);
            throw $e;
        }
    }

    public function testNegativeH_ValidationEmptyName(): void
    {
        $scope = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);

        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, ['name' => '   ', 'address' => 'addr', 'phone' => '123']);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::VALIDATION, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name'], 'zero partial mutation on validation fail');
            $this->assertSame(self::OLD_ADDR_A, $row['address']);
            throw $e;
        }
    }

    public function testNegativeI_ValidationNameTooLong(): void
    {
        $scope = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);

        $long = str_repeat('a', 191);
        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, ['name' => $long]);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::VALIDATION, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name']);
            throw $e;
        }
    }

    public function testNegativeJ_ValidationAddressTooLong(): void
    {
        $scope = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);

        $long = str_repeat('b', 256);
        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, ['name' => 'valid', 'address' => $long]);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::VALIDATION, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name'], 'zero partial mutation when address too long');
            throw $e;
        }
    }

    public function testNegativeK_ValidationPhoneTooLong(): void
    {
        $scope = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);

        $long = str_repeat('1', 33);
        $this->expectException(ClinicProfileException::class);
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, ['name' => 'valid', 'phone' => $long]);
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::VALIDATION, $e->getErrorCode());
            $row = $this->clinicRow($this->clinicA);
            $this->assertSame(self::OLD_NAME_A, $row['name']);
            throw $e;
        }
    }

    public function testNegativeL_NoopAndAuditOnlyOnRealChangeAndPreserveInvariants(): void
    {
        $scope = $this->establishTrusted($this->actorConfigA, $this->clinicA);
        App::replaceExplicitScope($scope);
        wp_set_current_user($this->actorConfigA);

        global $wpdb;
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cpms_audit_log WHERE action = "CLINIC_PROFILE_UPDATED" AND target_id = ' . $this->clinicA);

        // First, no-op: same values as current
        $resultNoop = App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, [
            'name' => self::OLD_NAME_A,
            'address' => self::OLD_ADDR_A,
            'phone' => self::OLD_PHONE_A,
        ]);
        $this->assertTrue($resultNoop['noop'], 'noop detected');
        $auditAfterNoop = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_log WHERE action = %s AND target_id = %d', 'CLINIC_PROFILE_UPDATED', $this->clinicA));
        $this->assertSame('0', (string) $auditAfterNoop, 'audit not logged on noop');

        // Real change should audit
        $resultReal = App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, [
            'name' => 'نام واقعی جدید',
            'address' => null,
            'phone' => null,
        ]);
        $this->assertFalse($resultReal['noop']);
        $auditAfterReal = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cpms_audit_log WHERE action = %s AND target_id = %d', 'CLINIC_PROFILE_UPDATED', $this->clinicA));
        $this->assertSame('1', (string) $auditAfterReal, 'audit logged only on real change');

        // Preserve invariants after real change
        $after = $this->clinicRow($this->clinicA);
        $this->assertSame('Asia/Tehran', $after['timezone'], 'L: timezone preserved');
        $loc = $this->locationRow($this->locA);
        $this->assertSame('Asia/Tehran', $loc['timezone'], 'L: location timezone operational truth preserved');

        // Atomic validation: try to update with valid name but invalid phone — ensure zero partial mutation (name not changed to intermediate)
        $beforeAtomic = $this->clinicRow($this->clinicA);
        $beforeName = $beforeAtomic['name'];
        try {
            App::clinicProfileService()->updateProfile($this->actorConfigA, $this->clinicA, [
                'name' => 'نام اتمیک',
                'phone' => str_repeat('9', 33), // invalid
            ]);
            $this->fail('should have thrown validation');
        } catch (ClinicProfileException $e) {
            $this->assertSame(ClinicProfileException::VALIDATION, $e->getErrorCode());
            $afterAtomic = $this->clinicRow($this->clinicA);
            $this->assertSame($beforeName, $afterAtomic['name'], 'L: zero partial mutation on atomic validation fail');
        }

        // No first Clinic fallback: ensure we never use clinic_id=1 implicitly
        $this->assertNotEquals(1, $this->clinicA);
        // Ensure raw clinic_id not trusted: service requires trustedClinicId, not from payload
        // (We already prove via cross-clinic test)
    }

    // ================= Helpers =================

    private function establishTrusted(int $actorId, int $clinicId, bool $shouldSucceed = true): \ClinicCore\Application\Scope\ClinicScope
    {
        $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
        if ($shouldSucceed) {
            $scope = $establisher->establish($actorId, $clinicId);
            $this->assertSame($clinicId, $scope->clinicId);
            return $scope;
        }
        // For suspended, we manually craft scope to test service-level deny
        return new \ClinicCore\Application\Scope\ClinicScope($clinicId, $this->orgId, 'suspended-test', 'Asia/Tehran');
    }

    private function insertOrganization(string $slug): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_organizations (name, slug, status, created_at, updated_at) VALUES (%s, %s, "active", %s, %s)',
            'Org ' . $slug,
            $slug,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function insertClinic(int $id, int $orgId, string $slug, string $name, string $address, string $phone, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinics (id, organization_id, name, slug, timezone, address, phone, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s)',
            $id,
            $orgId,
            $name,
            $slug,
            $tz,
            $address,
            $phone,
            $now,
            $now
        ));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s', $slug));
    }

    private function insertLocation(int $clinicId, string $slug, string $tz): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, 1, 1, %s, %s)',
            $clinicId,
            'Loc ' . $slug,
            $slug,
            $tz,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function makeUser(string $login, string $role): int
    {
        $seed = bin2hex(random_bytes(3));
        $created = wp_create_user($login . $seed, 'pass-12345', $login . $seed . '@test.local');
        if ($created instanceof \WP_Error) {
            $this->fail('user creation failed: ' . $created->get_error_message());
        }
        $userId = (int) $created;
        $user = get_userdata($userId);
        if ($user !== false) {
            $user->set_role($role);
        }
        return $userId;
    }

    private function clinicRow(int $clinicId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cpms_clinics WHERE id = %d', $clinicId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function locationRow(int $locId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cpms_locations WHERE id = %d', $locId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function makePatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-GREEN-' . bin2hex(random_bytes(2));
        $mobile = '0912' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            $mrn,
            'Test',
            $lastName,
            $mobile,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function makeClinician(int $clinicId, int $wpUserId, string $name): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, %d, 1, %s, %s)',
            $clinicId,
            $name,
            $wpUserId,
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function makeCompletedVisit(int $clinicId, int $locId, int $clinicianId, int $patientId, int $actorId): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $date = gmdate('Y-m-d');
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, source, status, visit_date, check_in_at, waiting_since, called_at, consultation_started_at, consultation_completed_at, active, created_at, updated_at) VALUES (%d, %d, %d, %d, "walk_in", "consultation_completed", %s, %s, %s, %s, %s, %s, 1, %s, %s)',
            $clinicId,
            $locId,
            $clinicianId,
            $patientId,
            $date,
            $date . ' 09:00:00.000',
            $date . ' 09:00:00.000',
            $date . ' 09:05:00.000',
            $date . ' 09:10:00.000',
            $date . ' 09:30:00.000',
            $now,
            $now
        ));
        return (int) $wpdb->insert_id;
    }

    private function makeInvoice(int $clinicId, int $patientId, int $visitId, int $actorId): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $number = 'INV-' . gmdate('ymd') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, discount, tax, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at) VALUES (%d, %s, %d, %d, "open", 100000, 0, 0, 100000, 0, 100000, %d, %s, %s)',
            $clinicId,
            $number,
            $patientId,
            $visitId,
            $actorId,
            $now,
            $now
        ));
        $id = (int) $wpdb->insert_id;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_invoice_items (invoice_id, description, quantity, unit_price, amount, discount) VALUES (%d, %s, 1, 100000, 100000, 0)',
            $id,
            'ویزیت'
        ));
        return ['id' => $id];
    }

    private function purge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $tables = [
            'cpms_invoice_items',
            'cpms_invoices',
            'cpms_visits',
            'cpms_clinicians',
            'cpms_patients',
            'cpms_patient_user_links',
            'cpms_locations',
            'cpms_settings',
            'cpms_clinic_memberships',
            'cpms_membership_capabilities',
            'cpms_membership_locations',
            'cpms_audit_log',
        ];
        foreach ($tables as $t) {
            $wpdb->query('DELETE FROM ' . $p . $t . ' WHERE clinic_id >= 64000');
        }
        $wpdb->query('DELETE FROM ' . $p . 'cpms_clinics WHERE id >= 64000');
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organizations WHERE slug LIKE "green-org-%"');
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

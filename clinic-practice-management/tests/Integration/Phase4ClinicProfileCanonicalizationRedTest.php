<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Phase 4 — Clinic Profile Canonicalization — VALID RED.
 *
 * هدف: اثبات نقص فعلی:
 * - wizard save path (setup.clinic.*) موفق است، اما cpms_clinics قدیمی می‌ماند
 * - downstream consumer (FinanceService::receipt) همچنان stale است
 *
 * مسیر محصول استفاده‌شده:
 * - CpmsSetupWizard::saveClinic() — narrowest existing callable wizard method/path
 *   که هنوز validation/save واقعی production را اجرا می‌کند.
 * - دلیل عدم استفاده از admin-post wrapper: save() شامل wp_safe_redirect + exit است
 *   که در محیط PHPUnit بدون شبیه‌سازی redirect منجر به خاتمه فرآیند می‌شود؛
 *   بنابراین مستقیماً saveClinic() صدا زده می‌شود که هستهٔ اعتبارسنجی/ذخیرهٔ گام
 *   «اطلاعات کلینیک» است و دقیقاً همان منطق production را دارد.
 *
 * Fixture:
 * - Organization (dynamic)
 * - Clinic A (dynamic, not fixed, not 1, not 0)
 * - Clinic B (dynamic, different timezone)
 * - Location A with explicit timezone Asia/Tehran
 * - Location B with different explicit timezone Asia/Kabul
 * - active authorized CONFIG actor for Clinic A
 * - durable active membership in Clinic A
 * - existing canonical cpms_clinics row for A with old name/address/phone
 */
final class Phase4ClinicProfileCanonicalizationRedTest extends WP_UnitTestCase
{
    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locA = 0;
    private int $locB = 0;
    private int $actorA = 0;

    private const OLD_NAME = 'کلینیک قدیمی A';
    private const OLD_ADDR = 'آدرس قدیمی A';
    private const OLD_PHONE = '02100000000';

    private const NEW_NAME = 'کلینیک جدید A - RED TEST';
    private const NEW_ADDR = 'خیابان جدید، پلاک ۱۲۳';
    private const NEW_PHONE = '02199998888';

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();

        // Build Organization + Clinics + Locations dynamically
        $this->orgId = $this->insertOrganization('red-org-' . bin2hex(random_bytes(3)));
        // Dynamic IDs: use random high range to avoid fixed IDs, not 1, not 0
        $base = random_int(63000, 64000);
        $this->clinicA = $this->insertClinic($base, $this->orgId, 'red-clinic-a-' . bin2hex(random_bytes(2)), self::OLD_NAME, self::OLD_ADDR, self::OLD_PHONE, 'Asia/Tehran');
        $this->clinicB = $this->insertClinic($base + 1, $this->orgId, 'red-clinic-b-' . bin2hex(random_bytes(2)), 'کلینیک B', 'آدرس B', '02111111111', 'Asia/Kabul');

        $this->locA = $this->insertLocation($this->clinicA, 'red-loc-a-' . bin2hex(random_bytes(2)), 'Asia/Tehran');
        $this->locB = $this->insertLocation($this->clinicB, 'red-loc-b-' . bin2hex(random_bytes(2)), 'Asia/Kabul');

        // Actor with CONFIG capability, membership only in Clinic A
        $this->actorA = $this->makeUser('red_actor_a_' . bin2hex(random_bytes(2)), 'administrator');
        // Seed membership with role that has CONFIG (cpms_manager)
        cpms_test_seed_membership($this->actorA, $this->clinicA, 'cpms_manager');
        // Ensure actor does NOT have membership in B
        // (no seed for B)

        // Assert material fixture insertions
        $this->assertGreaterThan(0, $this->orgId, 'fixture: org created');
        $this->assertGreaterThan(0, $this->clinicA, 'fixture: clinic A created');
        $this->assertGreaterThan(0, $this->clinicB, 'fixture: clinic B created');
        $this->assertNotEquals(1, $this->clinicA, 'fixture must not use clinic_id=1');
        $this->assertNotEquals(0, $this->clinicA, 'fixture must not use clinic_id=0');
        $this->assertNotEquals($this->clinicA, $this->clinicB, 'fixture: A != B');
        $this->assertGreaterThan(0, $this->locA, 'fixture: loc A created');
        $this->assertGreaterThan(0, $this->locB, 'fixture: loc B created');
        $this->assertGreaterThan(0, $this->actorA, 'fixture: actor A created');

        $rowA = $this->clinicRow($this->clinicA);
        $this->assertSame(self::OLD_NAME, $rowA['name'], 'precondition: old name in canonical');
        $this->assertSame(self::OLD_ADDR, $rowA['address'], 'precondition: old address in canonical');
        $this->assertSame(self::OLD_PHONE, $rowA['phone'], 'precondition: old phone in canonical');
        $this->assertSame('Asia/Tehran', $rowA['timezone'], 'precondition: timezone A');
        $rowB = $this->clinicRow($this->clinicB);
        $this->assertSame('Asia/Kabul', $rowB['timezone'], 'precondition: timezone B different');

        // Durable active membership in A
        $membership = App::membership_service()->active_membership_for($this->clinicA, $this->actorA);
        $this->assertNotNull($membership, 'precondition: active membership A');
        $this->assertSame('active', $membership['status'], 'precondition: membership active');
    }

    protected function tearDown(): void
    {
        ScopeContext::clear();
        Settings::flushCache();
        App::resetScope();
        $this->purge();
        parent::tearDown();
    }

    /**
     * Primary RED: wizard save succeeds into setup.clinic.*, but cpms_clinics remains old
     * and downstream receipt remains stale.
     */
    public function testWizardSaveShouldUpdateCanonicalAndDownstreamButCurrentlyDoesNot(): void
    {
        // Establish trusted Clinic A context via production mechanism
        $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
        $scope = $establisher->establish($this->actorA, $this->clinicA);
        $this->assertSame($this->clinicA, $scope->clinicId, 'trusted Clinic A established');
        $this->assertGreaterThan(0, $scope->organizationId, 'trusted org present');

        App::replaceExplicitScope($scope);
        wp_set_current_user($this->actorA);

        // Use existing wizard save mechanism as far as possible: saveClinic is the core logic
        $settings = new Settings(App::db(), $this->clinicA, App::audit());
        $err = CpmsSetupWizard::saveClinic($settings, [
            'clinic_name' => self::NEW_NAME,
            'clinic_address' => self::NEW_ADDR,
            'clinic_phone' => self::NEW_PHONE,
            'clinic_timezone' => 'Asia/Tehran',
        ], $this->actorA);

        $this->assertSame('', $err, 'wizard saveClinic should succeed (production validation path reached)');

        // Verify wizard reported save into setup.clinic.* (defective behavior: it writes there)
        $this->assertSame(self::NEW_NAME, $settings->get('setup.clinic.name'), 'wizard wrote new name to setup.clinic.*');
        $this->assertSame(self::NEW_ADDR, $settings->get('setup.clinic.address'), 'wizard wrote new address to setup.clinic.*');
        $this->assertSame(self::NEW_PHONE, $settings->get('setup.clinic.phone'), 'wizard wrote new phone to setup.clinic.*');

        // Canonical should equal new values — this is the intended contract, but currently fails (RED)
        $canonical = $this->clinicRow($this->clinicA);
        $this->assertNotNull($canonical, 'canonical row still exists');

        // Capture exact RED evidence before asserting failure
        // Expected: canonical equals NEW, Actual: still OLD
        // We intentionally assert NEW to produce RED
        $this->assertSame(self::NEW_NAME, $canonical['name'], 'RED EVIDENCE: cpms_clinics(A).name should equal operator-entered value after wizard save, but remains old');
        $this->assertSame(self::NEW_ADDR, $canonical['address'], 'RED EVIDENCE: cpms_clinics(A).address should equal new value');
        $this->assertSame(self::NEW_PHONE, $canonical['phone'], 'RED EVIDENCE: cpms_clinics(A).phone should equal new value');

        // Downstream consumer proof: FinanceService::receipt() should see new values without custom wiring
        // Create minimal invoice to call receipt
        $patientId = $this->makePatient($this->clinicA, 'RedPatient');
        $clinicianId = $this->makeClinician($this->clinicA, $this->actorA, 'Dr Red');
        $visitId = $this->makeCompletedVisit($this->clinicA, $this->locA, $clinicianId, $patientId, $this->actorA);
        $invoice = $this->makeInvoice($this->clinicA, $patientId, $visitId, $this->actorA);

        $receipt = App::financeService()->receipt($this->actorA, (int) $invoice['id']);
        $clinicInReceipt = $receipt['receipt']['clinic'] ?? [];
        $this->assertSame(self::NEW_NAME, $clinicInReceipt['name'] ?? '', 'RED EVIDENCE: receipt clinic.name should be new canonical value');
    }

    // ================= Helpers =================

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
        $created = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s', $slug));
        return $created;
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

    private function makePatient(int $clinicId, string $lastName): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $mrn = 'MR-RED-' . bin2hex(random_bytes(2));
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
        return ['id' => $id, 'invoice_number' => $number];
    }

    private function purge(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        // Delete in reverse FK order
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
        ];
        foreach ($tables as $t) {
            $wpdb->query('DELETE FROM ' . $p . $t . ' WHERE clinic_id >= 63000');
        }
        $wpdb->query('DELETE FROM ' . $p . 'cpms_clinics WHERE id >= 63000');
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organizations WHERE slug LIKE "red-org-%"');
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

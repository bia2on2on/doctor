<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

/**
 * Phase 4 — Clinic Profile Canonicalization — RED→GREEN.
 *
 * این تست ابتدا RED بود (wizard به setup.clinic.* می‌نوشت و cpms_clinics قدیمی می‌ماند).
 * پس از GREEN، باید نشان دهد:
 * - wizard save از مسیر canonical یکسان استفاده می‌کند (cpms_clinics به‌روز می‌شود)
 * - downstream consumer (FinanceService::receipt) مقدار جدید را می‌بیند
 * - setup.clinic.* دیگر second writable canonical نیست (تاریخی حذف نمی‌شود اما جدید نوشته نمی‌شود)
 *
 * مسیر محصول:
 * - CpmsSetupWizard::saveClinic() — narrowest existing callable wizard method
 *   دلیل عدم استفاده از admin-post wrapper: save() شامل wp_safe_redirect + exit است.
 *
 * Fixture: Org dynamic + Clinic A/B dynamic (not 1, not 0) + Location A/B explicit timezones
 * + active CONFIG actor + durable membership + old cpms_clinics values.
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

        $this->orgId = $this->insertOrganization('red-org-' . bin2hex(random_bytes(3)));
        $base = random_int(63000, 64000);
        $this->clinicA = $this->insertClinic($base, $this->orgId, 'red-clinic-a-' . bin2hex(random_bytes(2)), self::OLD_NAME, self::OLD_ADDR, self::OLD_PHONE, 'Asia/Tehran');
        $this->clinicB = $this->insertClinic($base + 1, $this->orgId, 'red-clinic-b-' . bin2hex(random_bytes(2)), 'کلینیک B', 'آدرس B', '02111111111', 'Asia/Kabul');

        $this->locA = $this->insertLocation($this->clinicA, 'red-loc-a-' . bin2hex(random_bytes(2)), 'Asia/Tehran');
        $this->locB = $this->insertLocation($this->clinicB, 'red-loc-b-' . bin2hex(random_bytes(2)), 'Asia/Kabul');

        $this->actorA = $this->makeUser('red_actor_a_' . bin2hex(random_bytes(2)), 'administrator');
        $memA = cpms_test_seed_membership($this->actorA, $this->clinicA, 'cpms_manager');
        // For downstream receipt proof, need INVOICE_READ as well (manager role lacks it)
        global $wpdb;
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->prefix . 'cpms_membership_capabilities (membership_id, capability, effect) VALUES (%d, %s, "grant") ON DUPLICATE KEY UPDATE effect="grant"', $memA, 'cpms_invoice_read'));
        $this->grantWpCap($this->actorA, 'cpms_invoice_read');
        $this->grantWpCap($this->actorA, 'cpms_config');

        $this->assertGreaterThan(0, $this->orgId);
        $this->assertGreaterThan(0, $this->clinicA);
        $this->assertGreaterThan(0, $this->clinicB);
        $this->assertNotEquals(1, $this->clinicA);
        $this->assertNotEquals(0, $this->clinicA);
        $this->assertNotEquals($this->clinicA, $this->clinicB);

        $rowA = $this->clinicRow($this->clinicA);
        $this->assertSame(self::OLD_NAME, $rowA['name']);
        $this->assertSame(self::OLD_ADDR, $rowA['address']);
        $this->assertSame(self::OLD_PHONE, $rowA['phone']);
        $this->assertSame('Asia/Tehran', $rowA['timezone']);
        $rowB = $this->clinicRow($this->clinicB);
        $this->assertSame('Asia/Kabul', $rowB['timezone']);

        $membership = App::membership_service()->active_membership_for($this->clinicA, $this->actorA);
        $this->assertNotNull($membership);
        $this->assertSame('active', $membership['status']);
    }

    protected function tearDown(): void
    {
        ScopeContext::clear();
        Settings::flushCache();
        App::resetScope();
        $this->purge();
        parent::tearDown();
    }

    public function testWizardSaveShouldUpdateCanonicalAndDownstream(): void
    {
        $establisher = new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db()));
        $scope = $establisher->establish($this->actorA, $this->clinicA);
        $this->assertSame($this->clinicA, $scope->clinicId);
        App::replaceExplicitScope($scope);
        wp_set_current_user($this->actorA);

        // Pre-seed historical setup.clinic.* to prove we don't delete but also don't overwrite as canonical
        $settings = new Settings(App::db(), $this->clinicA, App::audit());
        $settings->set('setup.clinic.name', self::OLD_NAME, $this->actorA);
        $settings->set('setup.clinic.address', self::OLD_ADDR, $this->actorA);
        $settings->set('setup.clinic.phone', self::OLD_PHONE, $this->actorA);

        $err = CpmsSetupWizard::saveClinic($settings, [
            'clinic_name' => self::NEW_NAME,
            'clinic_address' => self::NEW_ADDR,
            'clinic_phone' => self::NEW_PHONE,
            'clinic_timezone' => 'Asia/Tehran',
        ], $this->actorA);

        $this->assertSame('', $err, 'wizard saveClinic should succeed via canonical path');

        // Canonical must be updated (GREEN)
        $canonical = $this->clinicRow($this->clinicA);
        $this->assertNotNull($canonical);
        $this->assertSame(self::NEW_NAME, $canonical['name'], 'canonical name updated');
        $this->assertSame(self::NEW_ADDR, $canonical['address'], 'canonical address updated');
        $this->assertSame(self::NEW_PHONE, $canonical['phone'], 'canonical phone updated');

        // Invariants: preserve id/org/slug/timezone/created_at, locations unchanged
        $this->assertSame($this->clinicA, (int) $canonical['id']);
        $this->assertSame($this->orgId, (int) $canonical['organization_id']);
        $this->assertSame('Asia/Tehran', $canonical['timezone'], 'timezone preserved, no sync');
        $locA = $this->locationRow($this->locA);
        $this->assertSame('Asia/Tehran', $locA['timezone'], 'Location A timezone operational truth preserved');

        // setup.clinic.* must NOT remain second writable canonical — historical rows not deleted, but new canonical write should NOT update them
        // After GREEN, wizard should NOT write to setup.clinic.* (we stopped dual-writing)
        // So the old value should remain (or at least not equal new if we pre-seeded old)
        $afterSettingsName = $settings->get('setup.clinic.name', '');
        // We allow either old value (proving no overwrite) or empty, but NOT new as second canonical would have been.
        // Since we pre-seeded old, it should still be old if we stopped writing.
        $this->assertSame(self::OLD_NAME, $afterSettingsName, 'setup.clinic.* must not be second writable canonical after GREEN (historical preserved, not overwritten with new)');

        // Downstream consumer proof: FinanceService::receipt() should see new values without custom wiring
        $patientId = $this->makePatient($this->clinicA, 'RedPatient');
        $clinicianId = $this->makeClinician($this->clinicA, $this->actorA, 'Dr Red');
        $visitId = $this->makeCompletedVisit($this->clinicA, $this->locA, $clinicianId, $patientId, $this->actorA);
        $invoice = $this->makeInvoice($this->clinicA, $patientId, $visitId, $this->actorA);

        $receipt = App::financeService()->receipt($this->actorA, (int) $invoice['id']);
        $clinicInReceipt = $receipt['receipt']['clinic'] ?? [];
        $this->assertSame(self::NEW_NAME, $clinicInReceipt['name'] ?? '', 'receipt clinic.name should be new canonical value');
        $this->assertSame(self::NEW_ADDR, $clinicInReceipt['address'] ?? '', 'receipt clinic.address should be new');
        $this->assertSame(self::NEW_PHONE, $clinicInReceipt['phone'] ?? '', 'receipt clinic.phone should be new');
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

    private function grantWpCap(int $userId, string $cap): void
    {
        $user = get_userdata($userId);
        if ($user instanceof \WP_User) {
            $user->add_cap($cap);
        }
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
        ];
        foreach ($tables as $t) {
            $wpdb->query('DELETE FROM ' . $p . $t . ' WHERE clinic_id >= 63000');
        }
        $wpdb->query('DELETE FROM ' . $p . 'cpms_clinics WHERE id >= 63000');
        $wpdb->query('DELETE FROM ' . $p . 'cpms_organizations WHERE slug LIKE "red-org-%"');
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

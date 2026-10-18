<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Phase 4 — Location master data: CREATE + UPDATE name/timezone.
 *
 * Product path under test: the real admin boundary
 * LocationAdminPage::upsertLocation() — the pure write boundary that the
 * nonce-verified admin-post handler save() delegates to (same pattern as
 * StaffManagementPage::upsertUser()).
 *
 * Note on RED: no Location-management product boundary existed on main before
 * this slice (PRE-EXISTING RED NOT AVAILABLE). The boundary and these contract
 * tests were created together; behavior was then driven test-first inside the
 * new boundary (approved exception). The no-op test below is that iteration's
 * RED contract: the initial real implementation updated + audited
 * unconditionally and failed this test behaviorally after the real service was
 * reached.
 */
final class Phase4LocationMasterDataTest extends WP_UnitTestCase
{
    private const OLD_TS = '2020-01-01 00:00:00.000';

    private int $orgId = 0;
    private int $clinicA = 0;
    private int $clinicB = 0;
    private int $locA = 0;
    private int $locB = 0;
    private int $managerA = 0;
    private int $secretaryA = 0;
    private int $managerB = 0;
    private int $noMembership = 0;

    /** @var callable|null */
    private $auditFault = null;

    private bool $previousSuppression = false;

    protected function setUp(): void
    {
        parent::setUp();
        App::migrations()->migrate();
        App::resetScope();
        wp_set_current_user(0);

        $this->orgId = $this->insertOrganization('p4loc-org-' . bin2hex(random_bytes(3)));
        $base = random_int(64000, 65000);
        $this->clinicA = $this->insertClinic($base, $this->orgId, 'p4loc-a-' . bin2hex(random_bytes(3)), 'کلینیک A محل', 'Asia/Tehran');
        $this->clinicB = $this->insertClinic($base + 1, $this->orgId, 'p4loc-b-' . bin2hex(random_bytes(3)), 'کلینیک B محل', 'Asia/Kabul');

        $this->locA = $this->insertLocation($this->clinicA, 'p4loc-primary-a-' . bin2hex(random_bytes(2)), 'شعبه اصلی A', 'Asia/Tehran', 1);
        $this->locB = $this->insertLocation($this->clinicB, 'p4loc-primary-b-' . bin2hex(random_bytes(2)), 'شعبه اصلی B', 'Asia/Kabul', 1);

        $this->managerA = $this->makeUser('p4loc_mgr_a', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->managerA, $this->clinicA, RolesAndCapabilities::ROLE_MANAGER);

        $this->secretaryA = $this->makeUser('p4loc_sec_a', RolesAndCapabilities::ROLE_SECRETARY);
        cpms_test_seed_membership($this->secretaryA, $this->clinicA, RolesAndCapabilities::ROLE_SECRETARY);

        $this->managerB = $this->makeUser('p4loc_mgr_b', RolesAndCapabilities::ROLE_MANAGER);
        cpms_test_seed_membership($this->managerB, $this->clinicB, RolesAndCapabilities::ROLE_MANAGER);

        $this->noMembership = $this->makeUser('p4loc_nomem', RolesAndCapabilities::ROLE_PATIENT);

        // ---- fixture assertions (every material fixture asserted) ----
        self::assertGreaterThan(0, $this->orgId, 'fixture: organization persisted');
        self::assertGreaterThan(0, $this->clinicA, 'fixture: clinic A persisted');
        self::assertGreaterThan(0, $this->clinicB, 'fixture: clinic B persisted');
        self::assertNotSame($this->clinicA, $this->clinicB, 'fixture: clinics distinct');
        self::assertNotEquals(1, $this->clinicA, 'fixture: no fixed tenant id 1 (J)');
        self::assertNotEquals(0, $this->clinicA, 'fixture: no clinic_id 0 (J)');
        self::assertNotEquals(1, $this->clinicB, 'fixture: no fixed tenant id 1 (J)');

        $rowA = $this->locationRow($this->locA);
        $rowB = $this->locationRow($this->locB);
        self::assertNotNull($rowA, 'fixture: primary location A persisted');
        self::assertNotNull($rowB, 'fixture: primary location B persisted');
        self::assertSame(1, (int) $rowA['is_primary'], 'fixture: location A is primary');
        self::assertSame(1, (int) $rowA['is_active'], 'fixture: location A active');
        self::assertSame($this->clinicA, (int) $rowA['clinic_id'], 'fixture: location A belongs to clinic A');
        self::assertSame(1, (int) $rowB['is_primary'], 'fixture: location B is primary');
        self::assertSame($this->clinicB, (int) $rowB['clinic_id'], 'fixture: location B belongs to clinic B');

        self::assertTrue(
            App::authorization_service()->can($this->managerA, $this->clinicA, RolesAndCapabilities::CONFIG),
            'fixture: manager A has scoped CONFIG in clinic A'
        );
        self::assertFalse(
            App::authorization_service()->can($this->secretaryA, $this->clinicA, RolesAndCapabilities::CONFIG),
            'fixture: secretary A lacks scoped CONFIG in clinic A'
        );
        self::assertTrue(
            App::authorization_service()->can($this->managerB, $this->clinicB, RolesAndCapabilities::CONFIG),
            'fixture: manager B has scoped CONFIG in clinic B'
        );
        self::assertFalse(
            App::authorization_service()->can($this->managerA, $this->clinicB, RolesAndCapabilities::CONFIG),
            'fixture: manager A has no CONFIG in clinic B'
        );
        self::assertSame(
            'active',
            (string) App::membership_service()->membership_for($this->clinicA, $this->managerA)['status'],
            'fixture: manager A membership in clinic A is active'
        );
    }

    protected function tearDown(): void
    {
        $this->restoreAuditInserts();
        App::resetScope();
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ================= CREATE =================

    public function testAuthorizedManagerCreatesNonPrimaryActiveLocationInTrustedClinic(): void
    {
        wp_set_current_user($this->managerA);

        $primaryBefore = $this->locationRow($this->locA);
        $clinicBefore = $this->clinicRow($this->clinicA);
        $countBefore = $this->countLocations($this->clinicA);
        $countBBefore = $this->countLocations($this->clinicB);

        $slug = 'north-branch-' . bin2hex(random_bytes(2));

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => '  شعبه دوم شمال  ',
            'address' => 'خیابان نمونه، پلاک ۱۲',
            'phone' => '021-12345678',
            'slug' => $slug,
            'timezone' => 'Europe/Berlin',
        ], $this->managerA);

        self::assertSame('', (string) $result['error'], 'authorized create must succeed through the admin boundary');
        $newId = (int) $result['location_id'];
        self::assertGreaterThan(0, $newId, 'create must return the new location id');
        self::assertFalse((bool) $result['noop'], 'a real create is never a no-op');

        $row = $this->locationRow($newId);
        self::assertNotNull($row, 'created location must be readable');
        self::assertSame($this->clinicA, (int) $row['clinic_id'], 'durable ownership is exactly the trusted clinic');
        self::assertSame('شعبه دوم شمال', (string) $row['name'], 'name is stored trimmed');
        self::assertSame('خیابان نمونه، پلاک ۱۲', (string) $row['address'], 'optional address is stored');
        self::assertSame('021-12345678', (string) $row['phone'], 'optional phone is stored');
        self::assertSame($slug, (string) $row['slug'], 'slug stored as requested');
        self::assertSame(0, (int) $row['is_primary'], 'CREATE invariant: is_primary = 0');
        self::assertSame(1, (int) $row['is_active'], 'CREATE invariant: is_active = 1');
        self::assertSame('Europe/Berlin', (string) $row['timezone'], 'valid IANA identifier stored verbatim');
        self::assertNotSame($this->locA, $newId, 'new location is a distinct row');
        self::assertSame($countBefore + 1, $this->countLocations($this->clinicA), 'exactly one new row in clinic A');
        self::assertSame($countBBefore, $this->countLocations($this->clinicB), 'no location created in clinic B');

        // primary untouched (section 8)
        $primaryAfter = $this->locationRow($this->locA);
        self::assertSame((int) $primaryBefore['id'], (int) $primaryAfter['id'], 'primary id unchanged');
        self::assertSame(1, (int) $primaryAfter['is_primary'], 'primary stays primary');
        self::assertSame(1, (int) $primaryAfter['is_active'], 'primary stays active');
        self::assertSame((string) $primaryBefore['name'], (string) $primaryAfter['name'], 'primary name unchanged');
        self::assertSame((string) $primaryBefore['timezone'], (string) $primaryAfter['timezone'], 'primary timezone unchanged');
        self::assertSame((string) $primaryBefore['created_at'], (string) $primaryAfter['created_at'], 'primary created_at unchanged');

        // clinic timezone never synchronized (invariant 8)
        $clinicAfter = $this->clinicRow($this->clinicA);
        self::assertSame((string) $clinicBefore['timezone'], (string) $clinicAfter['timezone'], 'cpms_clinics.timezone unchanged');

        // audit: real change, trusted clinic passed explicitly
        $audit = $this->auditRow('LOCATION_CREATED', $newId);
        self::assertNotNull($audit, 'LOCATION_CREATED audit row exists');
        self::assertSame($this->clinicA, (int) $audit['clinic_id'], 'audit received the trusted clinic id explicitly');
        self::assertSame('location', (string) $audit['resource_type'], 'audit resource_type is location');
        self::assertSame($this->managerA, (int) $audit['actor_wp_user_id'], 'audit actor is the operating manager');
        $after = is_string($audit['after_json']) ? (array) json_decode($audit['after_json'], true) : [];
        self::assertSame('شعبه دوم شمال', (string) ($after['name'] ?? ''), 'audit after carries the name');
        self::assertSame('Europe/Berlin', (string) ($after['timezone'] ?? ''), 'audit after carries the timezone');
        self::assertSame('خیابان نمونه، پلاک ۱۲', (string) ($after['address'] ?? ''), 'audit carries the Location address');
        self::assertSame('***5678', (string) ($after['phone'] ?? ''), 'audit masks the Location phone');
        self::assertSame(0, (int) ($after['is_primary'] ?? 1), 'audit after records non-primary');
    }

    // ================= LIFECYCLE =================

    public function testLocationLifecyclePreservesRowAndAuditsBothTransitions(): void
    {
        $locationId = $this->insertSecondLocation(
            $this->clinicA,
            'p4loc-lifecycle-' . bin2hex(random_bytes(2)),
            'شعبه قابل مدیریت',
            'Asia/Tehran'
        );
        $historyIds = $this->insertOperationalRows($this->clinicA, $locationId);
        $historyBefore = [
            'schedule' => $this->tableRow('cpms_schedule', $historyIds['schedule']),
            'slot' => $this->tableRow('cpms_schedule_slots', $historyIds['slot']),
            'appointment' => $this->tableRow('cpms_appointments', $historyIds['appointment']),
            'visit' => $this->tableRow('cpms_visits', $historyIds['visit']),
            'clinical_note' => $this->tableRow('cpms_clinical_notes', $historyIds['clinical_note']),
            'clinical_note_version' => $this->tableRow('cpms_clinical_note_versions', $historyIds['clinical_note_version']),
            'invoice' => $this->tableRow('cpms_invoices', $historyIds['invoice']),
            'payment' => $this->tableRow('cpms_payments', $historyIds['payment']),
        ];
        wp_set_current_user($this->managerA);

        $disabled = App::locationService()->set_active($this->managerA, $this->clinicA, $locationId, false);
        self::assertFalse((bool) $disabled['noop'], 'deactivation is a real transition');
        $disabledRow = $this->locationRow($locationId);
        self::assertNotNull($disabledRow, 'deactivation preserves the Location row');
        self::assertSame(0, (int) $disabledRow['is_active'], 'Location is inactive');
        self::assertSame($this->clinicA, (int) $disabledRow['clinic_id'], 'ownership is preserved');
        self::assertSame(0, (int) $disabledRow['is_primary'], 'non-primary identity is preserved');
        foreach ([
            'schedule' => ['cpms_schedule', $historyIds['schedule']],
            'slot' => ['cpms_schedule_slots', $historyIds['slot']],
            'appointment' => ['cpms_appointments', $historyIds['appointment']],
            'visit' => ['cpms_visits', $historyIds['visit']],
            'clinical_note' => ['cpms_clinical_notes', $historyIds['clinical_note']],
            'clinical_note_version' => ['cpms_clinical_note_versions', $historyIds['clinical_note_version']],
            'invoice' => ['cpms_invoices', $historyIds['invoice']],
            'payment' => ['cpms_payments', $historyIds['payment']],
        ] as $kind => [$table, $id]) {
            $this->assertEqualsHistorical($historyBefore[$kind], $this->tableRow($table, $id), $kind . ' history survives Location deactivation');
        }
        self::assertSame(1, $this->auditCount('LOCATION_DEACTIVATED'), 'deactivation is audited');
        $duplicate = App::locationService()->set_active($this->managerA, $this->clinicA, $locationId, false);
        self::assertTrue((bool) $duplicate['noop'], 'duplicate deactivation is idempotent');
        self::assertSame(1, $this->auditCount('LOCATION_DEACTIVATED'), 'duplicate submissions do not duplicate audit entries');

        $enabled = App::locationService()->set_active($this->managerA, $this->clinicA, $locationId, true);
        self::assertFalse((bool) $enabled['noop'], 'reactivation is a real transition');
        $enabledRow = $this->locationRow($locationId);
        self::assertNotNull($enabledRow, 'reactivation preserves the Location row');
        self::assertSame(1, (int) $enabledRow['is_active'], 'Location is active again');
        self::assertSame(1, $this->auditCount('LOCATION_REACTIVATED'), 'reactivation is audited');
    }

    public function testLifecycleMutationCannotCrossClinicScope(): void
    {
        $locationId = $this->insertSecondLocation(
            $this->clinicA,
            'p4loc-cross-scope-' . bin2hex(random_bytes(2)),
            'شعبه محدود',
            'Asia/Tehran'
        );
        wp_set_current_user($this->managerB);

        try {
            App::locationService()->set_active($this->managerB, $this->clinicA, $locationId, false);
            self::fail('a manager from Clinic B cannot change Clinic A Location state');
        } catch (\ClinicCore\Application\Location\LocationException $exception) {
            self::assertSame('LOCATION_PERMISSION_DENIED', $exception->getErrorCode());
        }

        $row = $this->locationRow($locationId);
        self::assertNotNull($row, 'foreign Location remains present');
        self::assertSame(1, (int) $row['is_active'], 'foreign Location remains active');
        self::assertSame(0, $this->auditCount('LOCATION_DEACTIVATED'), 'denied lifecycle write is not audited as success');
    }

    public function testLifecycleRejectsPrimaryDeactivation(): void
    {
        wp_set_current_user($this->managerA);
        try {
            App::locationService()->set_active($this->managerA, $this->clinicA, $this->locA, false);
            self::fail('primary Location cannot be deactivated');
        } catch (\ClinicCore\Application\Location\LocationException $exception) {
            self::assertSame('LOCATION_LIFECYCLE_CONFLICT', $exception->getErrorCode());
        }
    }

    // ================= UPDATE =================

    public function testUpdateTimezoneAndNamePreservesProtectedColumns(): void
    {
        $second = $this->insertSecondLocation($this->clinicA, 'p4loc-second-a-' . bin2hex(random_bytes(2)), 'شعبه دوم', 'Asia/Tehran');
        wp_set_current_user($this->managerA);

        $before = $this->locationRow($second);
        $primaryBefore = $this->locationRow($this->locA);
        $clinicBefore = $this->clinicRow($this->clinicA);
        self::assertSame(self::OLD_TS, (string) $before['updated_at'], 'fixture: deterministic baseline updated_at');

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => $second,
            'name' => 'شعبه دوم — نام جدید',
            'address' => 'بلوار جدید، پلاک ۳',
            'phone' => '021-87654321',
            'timezone' => 'Asia/Kabul',
        ], $this->managerA);

        self::assertSame('', (string) $result['error'], 'authorized update must succeed');
        self::assertFalse((bool) $result['noop'], 'a real change is not a no-op');
        self::assertSame($second, (int) $result['location_id'], 'update returns the same location id');

        $after = $this->locationRow($second);
        self::assertSame('شعبه دوم — نام جدید', (string) $after['name'], 'name updated');
        self::assertSame('Asia/Kabul', (string) $after['timezone'], 'timezone updated to intended IANA identifier');
        self::assertSame('بلوار جدید، پلاک ۳', (string) $after['address'], 'address is editable');
        self::assertSame('021-87654321', (string) $after['phone'], 'phone is editable');
        self::assertSame((string) $before['slug'], (string) $after['slug'], 'UPDATE cannot alter slug');
        self::assertSame((string) $before['clinic_id'], (string) $after['clinic_id'], 'UPDATE cannot alter clinic_id');
        self::assertSame((string) $before['is_primary'], (string) $after['is_primary'], 'UPDATE cannot alter is_primary');
        self::assertSame((string) $before['is_active'], (string) $after['is_active'], 'UPDATE cannot alter is_active');
        self::assertSame((string) $before['created_at'], (string) $after['created_at'], 'UPDATE cannot alter created_at');
        self::assertNotSame((string) $before['updated_at'], (string) $after['updated_at'], 'updated_at changes on real update');

        // primary + clinic untouched
        self::assertEquals($primaryBefore, $this->locationRow($this->locA), 'primary location row untouched');
        self::assertSame((string) $clinicBefore['timezone'], (string) $this->clinicRow($this->clinicA)['timezone'], 'clinic timezone untouched');

        // audit: before/after carry the changed fields only, trusted clinic explicit
        $audit = $this->auditRow('LOCATION_UPDATED', $second);
        self::assertNotNull($audit, 'LOCATION_UPDATED audit row exists');
        self::assertSame($this->clinicA, (int) $audit['clinic_id'], 'audit trusted clinic id explicit');
        $beforeJson = is_string($audit['before_json']) ? (array) json_decode($audit['before_json'], true) : [];
        $afterJson = is_string($audit['after_json']) ? (array) json_decode($audit['after_json'], true) : [];
        self::assertSame('شعبه دوم', (string) ($beforeJson['name'] ?? ''), 'audit before carries old name');
        self::assertSame('Asia/Tehran', (string) ($beforeJson['timezone'] ?? ''), 'audit before carries old timezone');
        self::assertSame('شعبه دوم — نام جدید', (string) ($afterJson['name'] ?? ''), 'audit after carries new name');
        self::assertSame('Asia/Kabul', (string) ($afterJson['timezone'] ?? ''), 'audit after carries new timezone');
        self::assertSame('***0000', (string) ($beforeJson['phone'] ?? ''), 'audit masks the old phone');
        self::assertSame('***4321', (string) ($afterJson['phone'] ?? ''), 'audit masks the new phone');
    }

    public function testUpdateTimezoneDoesNotRewriteHistoricalOperationalRows(): void
    {
        $second = $this->insertSecondLocation($this->clinicA, 'p4loc-hist-' . bin2hex(random_bytes(2)), 'شعبه تاریخی', 'Asia/Tehran');
        $ids = $this->insertOperationalRows($this->clinicA, $second);

        $scheduleBefore = $this->tableRow('cpms_schedule', $ids['schedule']);
        $slotBefore = $this->tableRow('cpms_schedule_slots', $ids['slot']);
        $apptBefore = $this->tableRow('cpms_appointments', $ids['appointment']);
        $visitBefore = $this->tableRow('cpms_visits', $ids['visit']);
        $profileBefore = $this->locationRow($second);

        wp_set_current_user($this->managerA);
        $result = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => $second,
            'name' => 'شعبه تاریخی',
            'timezone' => 'Europe/Berlin',
        ], $this->managerA);
        self::assertSame('', (string) $result['error'], 'timezone-only update must succeed');

        // forward-looking only: no historical row rewritten or translated
        $this->assertEqualsHistorical($scheduleBefore, $this->tableRow('cpms_schedule', $ids['schedule']));
        $this->assertEqualsHistorical($slotBefore, $this->tableRow('cpms_schedule_slots', $ids['slot']));
        $this->assertEqualsHistorical($apptBefore, $this->tableRow('cpms_appointments', $ids['appointment']));
        $this->assertEqualsHistorical($visitBefore, $this->tableRow('cpms_visits', $ids['visit']));

        $slotAfter = $this->tableRow('cpms_schedule_slots', $ids['slot']);
        self::assertSame((string) $slotBefore['slot_date'], (string) $slotAfter['slot_date'], 'slot wall-clock date untouched');
        self::assertSame((string) $slotBefore['slot_time'], (string) $slotAfter['slot_time'], 'slot wall-clock time untouched');
        self::assertSame((string) $slotBefore['location_id'], (string) $slotAfter['location_id'], 'durable location_id reference untouched');

        $visitAfter = $this->tableRow('cpms_visits', $ids['visit']);
        self::assertSame((string) $visitBefore['check_in_at'], (string) $visitAfter['check_in_at'], 'visit check-in wall-clock untouched');
        self::assertSame((string) $visitBefore['location_id'], (string) $visitAfter['location_id'], 'visit location_id untouched');

        $profileAfter = $this->locationRow( $second );
        self::assertSame( $profileBefore['address'], $profileAfter['address'], 'an omitted address field preserves the stored value' );
        self::assertSame( $profileBefore['phone'], $profileAfter['phone'], 'an omitted phone field preserves the stored value' );
        self::assertSame('Europe/Berlin', (string) $profileAfter['timezone'], 'location timezone updated');
        self::assertSame('Asia/Tehran', (string) $this->locationRow($this->locA)['timezone'], 'other location timezone unchanged');
        self::assertSame('Asia/Tehran', (string) $this->clinicRow($this->clinicA)['timezone'], 'clinic timezone unchanged');
    }

    /**
     * Test-first iteration RED contract inside the new boundary: the initial
     * real implementation updated + audited unconditionally; the no-op contract
     * requires an explicit no-op without a misleading success audit.
     */
    public function testNoOpUpdateIsExplicitAndProducesNoSuccessAudit(): void
    {
        $second = $this->insertSecondLocation($this->clinicA, 'p4loc-noop-' . bin2hex(random_bytes(2)), 'نام ثابت', 'Asia/Tehran');
        wp_set_current_user($this->managerA);

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => $second,
            'name' => 'نام ثابت',
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);

        self::assertSame('', (string) $result['error'], 'identical values are not an error');
        self::assertTrue((bool) $result['noop'], 'identical name+timezone must be reported as an explicit no-op');

        $after = $this->locationRow($second);
        self::assertSame(self::OLD_TS, (string) $after['updated_at'], 'no-op must not rewrite updated_at');
        self::assertSame('نام ثابت', (string) $after['name'], 'no-op must not change name');
        self::assertSame('Asia/Tehran', (string) $after['timezone'], 'no-op must not change timezone');

        self::assertSame(0, $this->auditCount('LOCATION_UPDATED'), 'no-op must not produce a success audit');
        self::assertSame(0, $this->auditCount('LOCATION_CREATED'), 'no create audit either');
    }

    // ================= TENANT / SECURITY (A–J) =================

    public function testUnauthorizedActorCannotCreate(): void
    {
        $countBefore = $this->countLocations($this->clinicA);

        // active membership but no scoped CONFIG
        wp_set_current_user($this->secretaryA);
        $result = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه غیرمجاز',
            'slug' => 'forbidden-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->secretaryA);
        self::assertNotSame('', (string) $result['error'], 'secretary without CONFIG must be denied');
        self::assertSame(0, (int) $result['location_id']);
        $invalidUnderUnauthorized = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => '',
            'slug' => 'denied-invalid-' . bin2hex(random_bytes(2)),
            'timezone' => 'Not/AZone',
        ], $this->secretaryA);
        self::assertSame((string) $result['error'], (string) $invalidUnderUnauthorized['error'], 'authorization denial takes precedence over caller-controlled validation');

        // actor with no membership at all
        wp_set_current_user($this->noMembership);
        $result2 = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه غیرعضو',
            'slug' => 'nomem-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->noMembership);
        self::assertNotSame('', (string) $result2['error'], 'actor without membership must be denied');

        // actor/updatedBy mismatch (forged caller id)
        wp_set_current_user($this->secretaryA);
        $result3 = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه جعل‌شده',
            'slug' => 'forged-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);
        self::assertNotSame('', (string) $result3['error'], 'current user must match the declared actor');

        // unauthenticated actor
        wp_set_current_user(0);
        $result4 = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه ناشناس',
            'slug' => 'anon-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], 0);
        self::assertNotSame('', (string) $result4['error'], 'unauthenticated actor must be denied');

        self::assertSame($countBefore, $this->countLocations($this->clinicA), 'zero partial mutation (G)');
        self::assertSame(0, $this->auditCount('LOCATION_CREATED'), 'no success audit on rejection');
    }

    public function testClinicAActorCannotCreateUnderClinicBRequestedScope(): void
    {
        wp_set_current_user($this->managerA);
        $countBBefore = $this->countLocations($this->clinicB);

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicB,
            'name' => 'شعبه نفوذی',
            'slug' => 'intrusion-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);

        self::assertNotSame('', (string) $result['error'], 'Clinic-A-only operator cannot create under Clinic B scope');
        self::assertSame(0, (int) $result['location_id']);
        self::assertSame($countBBefore, $this->countLocations($this->clinicB), 'clinic B has zero new locations');
        self::assertSame(0, $this->auditCount('LOCATION_CREATED'), 'no success audit');
    }

    public function testClinicAActorCannotUpdateClinicBLocation(): void
    {
        wp_set_current_user($this->managerA);
        $before = $this->locationRow($this->locB);

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicB,
            'location_id' => $this->locB,
            'name' => 'تلاش برای تغییر B',
            'timezone' => 'Europe/Berlin',
        ], $this->managerA);

        self::assertNotSame('', (string) $result['error'], 'Clinic-A operator cannot mutate Clinic B even when requesting its scope');
        $this->assertEqualsHistorical($before, $this->locationRow($this->locB));
        self::assertSame(0, $this->auditCount('LOCATION_UPDATED'), 'no success audit');
    }

    public function testForeignAndNonexistentLocationDenialsAreIndistinguishable(): void
    {
        $locABefore = $this->locationRow($this->locA);
        $locBBefore = $this->locationRow($this->locB);

        wp_set_current_user($this->managerA);

        $foreign = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => $this->locB,
            'name' => 'نام یکسان',
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);

        $nonexistent = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => 99999999,
            'name' => 'نام یکسان',
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);

        self::assertNotSame('', (string) $foreign['error'], 'foreign-Clinic location is denied');
        self::assertNotSame('', (string) $nonexistent['error'], 'nonexistent location is denied');
        self::assertSame(
            (string) $foreign['error'],
            (string) $nonexistent['error'],
            'foreign and nonexistent denials are externally indistinguishable (E)'
        );

        // reverse direction: Clinic-B operator targeting Clinic-A location
        wp_set_current_user($this->managerB);
        $foreignReverse = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicB,
            'location_id' => $this->locA,
            'name' => 'نام یکسان',
            'timezone' => 'Asia/Tehran',
        ], $this->managerB);
        self::assertSame((string) $nonexistent['error'], (string) $foreignReverse['error'], 'reverse direction parity');

        $this->assertEqualsHistorical($locABefore, $this->locationRow($this->locA), 'targeted foreign location A untouched');
        $this->assertEqualsHistorical($locBBefore, $this->locationRow($this->locB), 'targeted foreign location B untouched');
        self::assertSame(0, $this->auditCount('LOCATION_UPDATED'), 'no success audit');
    }

    public function testRawClinicIdNeverBypassesTrustedEstablishment(): void
    {
        // manager B is legitimately authorized in B, but requests raw clinic_id = A
        wp_set_current_user($this->managerB);
        $countABefore = $this->countLocations($this->clinicA);

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه با clinic_id خام',
            'slug' => 'raw-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->managerB);

        self::assertNotSame('', (string) $result['error'], 'raw clinic_id without membership is not trusted');
        self::assertSame($countABefore, $this->countLocations($this->clinicA), 'no row created under the raw clinic id (F)');

        // clinic_id=0 is an invalid scope, never an implicit first clinic
        wp_set_current_user($this->managerA);
        $zero = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => 0,
            'name' => 'شعبه صفر',
            'slug' => 'zero-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);
        self::assertNotSame('', (string) $zero['error'], 'clinic_id 0 must not become an implicit clinic scope');

        // A unique active membership is not a default for a management form:
        // without an explicit selector the page must not infer a target Clinic.
        $countBeforeMissing = $this->countLocations($this->clinicA);
        $missing = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => null,
            'name' => 'شعبه بدون انتخاب Clinic',
            'slug' => 'missing-clinic-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);
        self::assertNotSame('', (string) $missing['error'], 'missing Clinic selector must be denied even for a sole active membership');
        self::assertSame($countBeforeMissing, $this->countLocations($this->clinicA), 'missing selector cannot create a Location');
    }

    public function testLocationAdminRequiresExplicitClinicAndShowsClinicVsLocationActions(): void
    {
        wp_set_current_user($this->managerA);
        $previousGet = $_GET;
        try {
            unset($_GET['clinic_id'], $_GET['edit']);
            $_GET['page'] = LocationAdminPage::PAGE_SLUG;
            ob_start();
            LocationAdminPage::render();
            $unselected = (string) ob_get_clean();

            self::assertStringContainsString('Clinic را صریحاً از فهرست انتخاب کنید', $unselected, 'no default Clinic is inferred');
            self::assertStringNotContainsString('شعبه اصلی A', $unselected, 'no Location data is rendered before explicit selection');
            self::assertStringNotContainsString('افزودن شعبهٔ جدید', $unselected, 'no Location write form appears before selection');
            self::assertStringContainsString('مدیریت Clinicها', $unselected, 'Clinic creation is clearly a separate Organization-scoped flow');

            $_GET['clinic_id'] = (string) $this->clinicA;
            ob_start();
            LocationAdminPage::render();
            $selected = (string) ob_get_clean();
            self::assertStringContainsString('شعبه اصلی A', $selected, 'selected Clinic A Locations are rendered');
            self::assertStringNotContainsString('شعبه اصلی B', $selected, 'Clinic B Locations are not rendered');
            self::assertStringContainsString('page=cpms-staff', $selected, 'role assignment links to Clinic-scoped staff management');
            self::assertStringContainsString('_wpnonce', $selected, 'Location write forms include WordPress nonce fields');
            self::assertStringContainsString('این صفحه فقط برای افزودن و ویرایش یک', $selected, 'Clinic-versus-Location distinction is visible');
        } finally {
            $_GET = $previousGet;
        }
    }

    public function testInvalidInputsCauseZeroPartialMutation(): void
    {
        wp_set_current_user($this->managerA);
        $countBefore = $this->countLocations($this->clinicA);
        $unique = bin2hex(random_bytes(3));

        $invalidCreates = [
            'empty name' => ['name' => '', 'slug' => 'inv1-' . $unique, 'timezone' => 'Asia/Tehran'],
            'long name' => ['name' => str_repeat('ن', 191), 'slug' => 'inv2-' . $unique, 'timezone' => 'Asia/Tehran'],
            'empty slug' => ['name' => 'نام معتبر', 'slug' => '', 'timezone' => 'Asia/Tehran'],
            'long slug' => ['name' => 'نام معتبر', 'slug' => str_repeat('s', 191), 'timezone' => 'Asia/Tehran'],
            'empty timezone' => ['name' => 'نام معتبر', 'slug' => 'inv5-' . $unique, 'timezone' => ''],
            'invalid timezone' => ['name' => 'نام معتبر', 'slug' => 'inv6-' . $unique, 'timezone' => 'Mars/Olympus'],
            'garbage timezone' => ['name' => 'نام معتبر', 'slug' => 'inv7-' . $unique, 'timezone' => 'Not/AZone'],
        ];

        foreach ($invalidCreates as $label => $payload) {
            $result = LocationAdminPage::upsertLocation([
                'mode' => 'create',
                'clinic_id' => $this->clinicA,
            ] + $payload, $this->managerA);
            self::assertNotSame('', (string) $result['error'], 'invalid input must be rejected: ' . $label);
            self::assertSame(0, (int) $result['location_id'], 'no location id on rejection: ' . $label);
        }

        self::assertSame($countBefore, $this->countLocations($this->clinicA), 'zero partial mutation across all invalid creates (G)');
        self::assertSame(0, $this->auditCount('LOCATION_CREATED'), 'no success audit on rejections');

        // invalid update leaves the row fully untouched
        $second = $this->insertSecondLocation($this->clinicA, 'p4loc-invalid-upd-' . bin2hex(random_bytes(2)), 'نام اولیه', 'Asia/Tehran');
        $before = $this->locationRow($second);
        $badUpdate = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => $second,
            'name' => 'نام جدید اما زمانه نامعتبر',
            'timezone' => 'Invalid/Zone',
        ], $this->managerA);
        self::assertNotSame('', (string) $badUpdate['error'], 'invalid timezone on update must be rejected');
        $this->assertEqualsHistorical($before, $this->locationRow($second), 'update rejection leaves row untouched');
        self::assertSame(0, $this->auditCount('LOCATION_UPDATED'), 'no success audit');
    }

    public function testDuplicateSlugWithinSameClinicIsRejected(): void
    {
        $slug = 'dup-' . bin2hex(random_bytes(2));
        $second = $this->insertSecondLocation($this->clinicA, $slug, 'شعبه اول با این slug', 'Asia/Tehran');
        self::assertGreaterThan(0, $second);

        wp_set_current_user($this->managerA);
        $countBefore = $this->countLocations($this->clinicA);

        $result = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه دوم با همان slug',
            'slug' => $slug,
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);

        self::assertNotSame('', (string) $result['error'], 'duplicate slug inside the same clinic is a deterministic conflict (H)');
        self::assertSame(0, (int) $result['location_id']);
        self::assertSame($countBefore, $this->countLocations($this->clinicA), 'no duplicate row created');
        self::assertSame(1, $this->countLocationsWithSlug($this->clinicA, $slug), 'exactly one row with that slug in clinic A');
        self::assertSame(0, $this->auditCount('LOCATION_CREATED'), 'no success audit');
    }

    public function testSameSlugIsAllowedInDifferentClinics(): void
    {
        $slug = 'shared-' . bin2hex(random_bytes(2));
        $secondA = $this->insertSecondLocation($this->clinicA, $slug, 'شعبه هم‌نام در A', 'Asia/Tehran');
        self::assertGreaterThan(0, $secondA);

        wp_set_current_user($this->managerB);
        $result = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicB,
            'name' => 'شعبه هم‌نام در B',
            'slug' => $slug,
            'timezone' => 'Asia/Tehran',
        ], $this->managerB);

        self::assertSame('', (string) $result['error'], 'same slug in a different clinic is allowed by the scoped-uniqueness contract (I)');
        $row = $this->locationRow((int) $result['location_id']);
        self::assertNotNull($row);
        self::assertSame($this->clinicB, (int) $row['clinic_id'], 'the B row durably belongs to clinic B');
        self::assertSame($slug, (string) $row['slug']);
        self::assertSame(1, $this->countLocationsWithSlug($this->clinicA, $slug), 'clinic A row unaffected');
    }

    // ================= TIMEZONE (section 9) =================

    public function testTimezoneIsOperationalTruthAndValidated(): void
    {
        wp_set_current_user($this->managerA);
        $countBefore = $this->countLocations($this->clinicA);
        $clinicBefore = $this->clinicRow($this->clinicA);

        // valid Asia/Tehran
        $r1 = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه تهران',
            'slug' => 'tz-tehran-' . bin2hex(random_bytes(2)),
            'timezone' => 'Asia/Tehran',
        ], $this->managerA);
        self::assertSame('', (string) $r1['error']);
        self::assertSame('Asia/Tehran', (string) $this->locationRow((int) $r1['location_id'])['timezone']);

        // another valid IANA identifier
        $r2 = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه نیویورک',
            'slug' => 'tz-newyork-' . bin2hex(random_bytes(2)),
            'timezone' => 'America/New_York',
        ], $this->managerA);
        self::assertSame('', (string) $r2['error']);
        self::assertSame('America/New_York', (string) $this->locationRow((int) $r2['location_id'])['timezone']);

        // invalid + empty rejected without fallback
        foreach (['Solar/Mars', ''] as $bad) {
            $r3 = LocationAdminPage::upsertLocation([
                'mode' => 'create',
                'clinic_id' => $this->clinicA,
                'name' => 'شعبه نامعتبر',
                'slug' => 'tz-bad-' . bin2hex(random_bytes(2)),
                'timezone' => $bad,
            ], $this->managerA);
            self::assertNotSame('', (string) $r3['error'], 'invalid/empty timezone rejected: ' . $bad);
        }

        self::assertSame($countBefore + 2, $this->countLocations($this->clinicA), 'only the two valid creates persisted');

        // other location + clinic timezone remain unchanged when a location changes
        $locABefore = $this->locationRow($this->locA);
        $update = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => (int) $r1['location_id'],
            'name' => 'شعبه تهران',
            'timezone' => 'Australia/Sydney',
        ], $this->managerA);
        self::assertSame('', (string) $update['error']);
        self::assertSame('Australia/Sydney', (string) $this->locationRow((int) $r1['location_id'])['timezone']);
        $this->assertEqualsHistorical($locABefore, $this->locationRow($this->locA), 'other location row unchanged while its sibling location was updated');
        self::assertSame((string) $clinicBefore['timezone'], (string) $this->clinicRow($this->clinicA)['timezone'], 'cpms_clinics.timezone never synchronized');
    }

    // ================= PRIMARY SAFETY (section 8) =================

    public function testPrimaryLocationRemainsUntouchedAcrossCreateAndUpdate(): void
    {
        $primaryBefore = $this->locationRow($this->locA);
        wp_set_current_user($this->managerA);

        $created = LocationAdminPage::upsertLocation([
            'mode' => 'create',
            'clinic_id' => $this->clinicA,
            'name' => 'شعبه امنیت اصلی',
            'slug' => 'primary-safety-' . bin2hex(random_bytes(2)),
            'timezone' => 'Europe/Berlin',
        ], $this->managerA);
        self::assertSame('', (string) $created['error']);
        $this->assertEqualsHistorical($primaryBefore, $this->locationRow($this->locA), 'primary unchanged after CREATE');

        $updated = LocationAdminPage::upsertLocation([
            'mode' => 'update',
            'clinic_id' => $this->clinicA,
            'location_id' => (int) $created['location_id'],
            'name' => 'شعبه امنیت اصلی — ویرایش',
            'timezone' => 'America/New_York',
        ], $this->managerA);
        self::assertSame('', (string) $updated['error']);
        $this->assertEqualsHistorical($primaryBefore, $this->locationRow($this->locA), 'primary unchanged after UPDATE');

        $finalPrimary = $this->locationRow($this->locA);
        self::assertSame(1, (int) $finalPrimary['is_primary'], 'primary flag intact');
        self::assertSame(1, (int) $finalPrimary['is_active'], 'primary active flag intact');
        self::assertSame(0, (int) $this->locationRow((int) $created['location_id'])['is_primary'], 'created location stays non-primary');
    }

    public function testLocationSaveRejectsInvalidNonceBeforeCreating(): void
    {
        wp_set_current_user( $this->managerA );
        $slug        = 'p4loc-invalid-nonce-' . bin2hex( random_bytes( 3 ) );
        $countBefore = $this->countLocations( $this->clinicA );
        $oldPost     = $_POST;
        $oldRequest  = $_REQUEST;
        $_POST       = [
            'mode'      => 'create',
            'clinic_id' => (string) $this->clinicA,
            'name'      => 'درخواست با nonce نامعتبر',
            'slug'      => $slug,
            'timezone'  => 'Europe/Berlin',
            '_wpnonce'  => 'invalid-location-save-nonce',
        ];
        $_REQUEST = $_POST;
        $dieFilter = static fn ( $handler ) => static function ( $message ): void {
            throw new \RuntimeException( 'location save nonce denied' );
        };
        add_filter( 'wp_die_handler', $dieFilter );
        try {
            try {
                LocationAdminPage::save();
                self::fail( 'invalid Location save nonce must stop the handler' );
            } catch ( \RuntimeException $exception ) {
                self::assertSame( 'location save nonce denied', $exception->getMessage() );
            }
        } finally {
            remove_filter( 'wp_die_handler', $dieFilter );
            $_POST    = $oldPost;
            $_REQUEST = $oldRequest;
        }

        self::assertSame( $countBefore, $this->countLocations( $this->clinicA ), 'nonce failure cannot create a Location' );
        self::assertSame( 0, $this->countLocationsWithSlug( $this->clinicA, $slug ) );
    }

    public function testLocationLifecycleRejectsInvalidNonceBeforeStatusChange(): void
    {
        $locationId = $this->insertSecondLocation( $this->clinicA, 'p4loc-invalid-lifecycle-nonce-' . bin2hex( random_bytes( 2 ) ), 'Nonce transition', 'Asia/Tehran' );
        $before     = $this->locationRow( $locationId );
        $auditBefore = $this->auditCount( 'LOCATION_DEACTIVATED' );
        wp_set_current_user( $this->managerA );
        $oldPost    = $_POST;
        $oldRequest = $_REQUEST;
        $_POST      = [
            'clinic_id'   => (string) $this->clinicA,
            'location_id' => (string) $locationId,
            'active'      => '0',
            '_wpnonce'    => 'invalid-location-lifecycle-nonce',
        ];
        $_REQUEST = $_POST;
        $dieFilter = static fn ( $handler ) => static function ( $message ): void {
            throw new \RuntimeException( 'location lifecycle nonce denied' );
        };
        add_filter( 'wp_die_handler', $dieFilter );
        try {
            try {
                LocationAdminPage::save_lifecycle();
                self::fail( 'invalid Location lifecycle nonce must stop the handler' );
            } catch ( \RuntimeException $exception ) {
                self::assertSame( 'location lifecycle nonce denied', $exception->getMessage() );
            }
        } finally {
            remove_filter( 'wp_die_handler', $dieFilter );
            $_POST    = $oldPost;
            $_REQUEST = $oldRequest;
        }

        $this->assertEqualsHistorical( $before, $this->locationRow( $locationId ), 'invalid nonce preserves Location active-state fields' );
        self::assertSame( $auditBefore, $this->auditCount( 'LOCATION_DEACTIVATED' ), 'invalid nonce emits no lifecycle audit event' );
    }

    public function testFailedCreateAuditRollsBackLocationInsert(): void
    {
        wp_set_current_user($this->managerA);
        $slug = 'p4loc-audit-create-' . bin2hex(random_bytes(3));
        $countBefore = $this->countLocations($this->clinicA);
        $auditBefore = $this->auditCount('LOCATION_CREATED');

        $this->failAuditInserts();
        try {
            $result = LocationAdminPage::upsertLocation([
                'mode' => 'create',
                'clinic_id' => $this->clinicA,
                'name' => 'ایجاد با شکست Audit',
                'slug' => $slug,
                'timezone' => 'Europe/Berlin',
            ], $this->managerA);
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotSame('', (string) $result['error'], 'a failed hash-chained audit must fail the create request');
        self::assertSame($countBefore, $this->countLocations($this->clinicA), 'Location insert rolls back with audit failure');
        self::assertSame(0, $this->countLocationsWithSlug($this->clinicA, $slug), 'no partial Location row remains');
        self::assertSame($auditBefore, $this->auditCount('LOCATION_CREATED'), 'no success audit was persisted');
        self::assertTrue(App::audit()->verifyChain()['ok'], 'the audit chain remains valid after rollback');
    }

    public function testFailedUpdateAuditRollsBackProfileChange(): void
    {
        $locationId = $this->insertSecondLocation($this->clinicA, 'p4loc-audit-update-' . bin2hex(random_bytes(2)), 'پروفایل اولیه', 'Asia/Tehran');
        $before = $this->locationRow($locationId);
        $auditBefore = $this->auditCount('LOCATION_UPDATED');
        wp_set_current_user($this->managerA);

        $this->failAuditInserts();
        try {
            $result = LocationAdminPage::upsertLocation([
                'mode' => 'update',
                'clinic_id' => $this->clinicA,
                'location_id' => $locationId,
                'name' => 'تغییر نباید بماند',
                'address' => 'آدرس موقت',
                'phone' => '021-99998888',
                'timezone' => 'Europe/Berlin',
            ], $this->managerA);
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertNotSame('', (string) $result['error'], 'a failed audit must fail the update request');
        $this->assertEqualsHistorical($before, $this->locationRow($locationId), 'profile update rolls back with audit failure');
        self::assertSame($auditBefore, $this->auditCount('LOCATION_UPDATED'), 'no success audit was persisted');
        self::assertTrue(App::audit()->verifyChain()['ok'], 'the audit chain remains valid after rollback');
    }

    public function testFailedLifecycleAuditRollsBackActiveFlag(): void
    {
        $locationId = $this->insertSecondLocation($this->clinicA, 'p4loc-audit-lifecycle-' . bin2hex(random_bytes(2)), 'وضعیت اولیه', 'Asia/Tehran');
        $before = $this->locationRow($locationId);
        $auditBefore = $this->auditCount('LOCATION_DEACTIVATED');
        wp_set_current_user($this->managerA);

        $this->failAuditInserts();
        try {
            $failed = false;
            try {
                App::locationService()->set_active($this->managerA, $this->clinicA, $locationId, false);
            } catch (\ClinicCore\Application\Location\LocationException) {
                $failed = true;
            }
        } finally {
            $this->restoreAuditInserts();
        }

        self::assertTrue($failed, 'failed lifecycle audit must return a fail-closed error');
        $this->assertEqualsHistorical($before, $this->locationRow($locationId), 'active-state update rolls back with audit failure');
        self::assertSame($auditBefore, $this->auditCount('LOCATION_DEACTIVATED'), 'no success audit was persisted');
        self::assertTrue(App::audit()->verifyChain()['ok'], 'the audit chain remains valid after rollback');
    }

    // ================= Helpers =================

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed>|null $after
     */
    private function assertEqualsHistorical(array $before, ?array $after, string $message = 'row must be completely unchanged'): void
    {
        self::assertNotNull($after, $message . ' (row must still exist)');
        self::assertEquals($before, $after, $message);
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

    private function insertClinic(int $id, int $orgId, string $slug, string $name, string $tz): int
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
            'آدرس ' . $slug,
            '02100000000',
            $now,
            $now
        ));

        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'cpms_clinics WHERE slug = %s', $slug));
    }

    private function insertLocation(int $clinicId, string $slug, string $name, string $tz, int $isPrimary): int
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_locations (clinic_id, name, slug, address, phone, timezone, is_primary, is_active, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %d, 1, %s, %s)',
            $clinicId,
            $name,
            $slug,
            'آدرس ' . $slug,
            '02100000000',
            $tz,
            $isPrimary,
            $now,
            $now
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * Second (non-primary) location with a deterministic old timestamp baseline.
     */
    private function insertSecondLocation(int $clinicId, string $slug, string $name, string $tz): int
    {
        global $wpdb;
        $id = $this->insertLocation($clinicId, $slug, $name, $tz, 0);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . $wpdb->prefix . 'cpms_locations SET created_at = %s, updated_at = %s WHERE id = %d',
            self::OLD_TS,
            self::OLD_TS,
            $id
        ));

        return $id;
    }

    /**
     * Historical operational rows (schedule/slot/appointment/visit) bound to a
     * location — the rows a timezone update must never rewrite.
     *
     * @return array{clinician:int, patient:int, schedule:int, slot:int, appointment:int, visit:int, clinical_note:int, clinical_note_version:int, invoice:int, payment:int}
     */
    private function insertOperationalRows(int $clinicId, int $locationId): array
    {
        global $wpdb;
        $now = App::db()->nowUtcSql();
        $unique = bin2hex(random_bytes(3));

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinicians (clinic_id, full_name, wp_user_id, is_active, created_at, updated_at) VALUES (%d, %s, NULL, 1, %s, %s)',
            $clinicId,
            'Dr Hist ' . $unique,
            $now,
            $now
        ));
        $clinicianId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicianId, 'fixture: clinician row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_patients (clinic_id, mrn, first_name, last_name, mobile, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, "active", %s, %s)',
            $clinicId,
            'MR-P4LOC-' . $unique,
            'بیمار',
            'تاریخی',
            '0912' . (string) random_int(1000000, 9999999),
            $now,
            $now
        ));
        $patientId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $patientId, 'fixture: patient row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule (clinic_id, location_id, clinician_id, day_of_week, start_time, end_time, created_at, updated_at) VALUES (%d, %d, %d, 1, "09:00:00", "17:00:00", %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $now,
            $now
        ));
        $scheduleId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $scheduleId, 'fixture: schedule row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_schedule_slots (clinic_id, location_id, clinician_id, slot_date, slot_time, duration_min, capacity, created_at, updated_at) VALUES (%d, %d, %d, "2026-10-01", "09:30:00", 20, 1, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $now,
            $now
        ));
        $slotId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $slotId, 'fixture: slot row');

        $reference = 'P4LOC-' . strtoupper($unique);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_appointments (clinic_id, location_id, reference_code, clinician_id, patient_id, slot_id, slot_date, slot_time, status, booked_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %d, %d, "2026-10-01", "09:30:00", "confirmed", %s, %s, %s)',
            $clinicId,
            $locationId,
            $reference,
            $clinicianId,
            $patientId,
            $slotId,
            $now,
            $now,
            $now
        ));
        $appointmentId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $appointmentId, 'fixture: appointment row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_visits (clinic_id, location_id, clinician_id, patient_id, appointment_id, source, status, visit_date, check_in_at, created_at, updated_at) VALUES (%d, %d, %d, %d, %d, "scheduled", "waiting", "2026-10-01", %s, %s, %s)',
            $clinicId,
            $locationId,
            $clinicianId,
            $patientId,
            $appointmentId,
            $now,
            $now,
            $now
        ));
        $visitId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $visitId, 'fixture: visit row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinical_notes (clinic_id, visit_id, patient_id, clinician_id, category, visibility, content_text, version, is_archived, created_by_wp_user_id, created_at, updated_at) VALUES (%d, %d, %d, %d, "clinical_note", "doctor_private", %s, 1, 0, %d, %s, %s)',
            $clinicId,
            $visitId,
            $patientId,
            $clinicianId,
            'یادداشت تاریخی ' . $unique,
            $this->managerA,
            $now,
            $now
        ));
        $clinicalNoteId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicalNoteId, 'fixture: clinical record row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_clinical_note_versions (note_id, version, content_snapshot, changed_by_wp_user_id, created_at) VALUES (%d, 1, %s, %d, %s)',
            $clinicalNoteId,
            'نسخهٔ اول یادداشت تاریخی ' . $unique,
            $this->managerA,
            $now
        ));
        $clinicalNoteVersionId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $clinicalNoteVersionId, 'fixture: clinical record history row');

        $invoiceNumber = 'INV-' . strtoupper($unique);
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_invoices (clinic_id, invoice_number, patient_id, visit_id, status, subtotal, total, paid_amount, balance, issued_by_wp_user_id, created_at, updated_at) VALUES (%d, %s, %d, %d, "partial", 1000, 1000, 500, 500, %d, %s, %s)',
            $clinicId,
            $invoiceNumber,
            $patientId,
            $visitId,
            $this->managerA,
            $now,
            $now
        ));
        $invoiceId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $invoiceId, 'fixture: finance invoice row');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'cpms_payments (clinic_id, payment_number, invoice_id, patient_id, amount, method, idempotency_key, status, paid_at, received_by_wp_user_id, created_at) VALUES (%d, %s, %d, %d, 500, "cash", %s, "captured", %s, %d, %s)',
            $clinicId,
            'PAY-' . strtoupper($unique),
            $invoiceId,
            $patientId,
            'p4loc-' . $unique,
            $now,
            $this->managerA,
            $now
        ));
        $paymentId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $paymentId, 'fixture: finance payment row');

        return [
            'clinician' => $clinicianId,
            'patient' => $patientId,
            'schedule' => $scheduleId,
            'slot' => $slotId,
            'appointment' => $appointmentId,
            'visit' => $visitId,
            'clinical_note' => $clinicalNoteId,
            'clinical_note_version' => $clinicalNoteVersionId,
            'invoice' => $invoiceId,
            'payment' => $paymentId,
        ];
    }

    /** Fail real audit INSERTs with a MySQL unknown-column error. */
    private function failAuditInserts(): void
    {
        global $wpdb;
        self::assertNull($this->auditFault, 'audit failure filter must not be installed twice');
        $fault = static function ($query) {
            $sql = (string) $query;
            if ( preg_match('/\A\s*INSERT\s+INTO\s+(\S+)/i', $sql, $match) === 1 && str_contains($match[1], 'cpms_audit_logs') ) {
                return 'INSERT INTO ' . $match[1] . ' (`cpms_fault_no_such_column`) VALUES (1)';
            }
            return $query;
        };
        $this->previousSuppression = (bool) $wpdb->suppress_errors(true);
        add_filter('query', $fault, 5);
        $this->auditFault = $fault;
    }

    private function restoreAuditInserts(): void
    {
        if ( $this->auditFault === null ) {
            return;
        }
        remove_filter('query', $this->auditFault, 5);
        $this->auditFault = null;
        global $wpdb;
        $wpdb->suppress_errors($this->previousSuppression);
    }

    private function makeUser(string $prefix, string $role): int
    {
        $unique = $prefix . '_' . bin2hex(random_bytes(4));
        $userId = (int) wp_create_user($unique, wp_generate_password(24), $unique . '@p4loc.test');
        self::assertGreaterThan(0, $userId, 'fixture: WP user created');
        $user = get_userdata($userId);
        self::assertNotFalse($user, 'fixture: WP user queryable');
        $user->set_role($role);
        self::assertContains($role, (array) get_userdata($userId)->roles, 'fixture: WP role assigned');

        return $userId;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function locationRow(int $locationId): ?array
    {
        return $this->tableRow('cpms_locations', $locationId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function clinicRow(int $clinicId): ?array
    {
        return $this->tableRow('cpms_clinics', $clinicId);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function tableRow(string $table, int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . $table . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function countLocations(int $clinicId): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d',
            [$clinicId]
        );
    }

    private function countLocationsWithSlug(int $clinicId, string $slug): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_locations') . ' WHERE clinic_id = %d AND slug = %s',
            [$clinicId, $slug]
        );
    }

    private function auditCount(string $action): int
    {
        return (int) App::db()->fetchValue(
            'SELECT COUNT(*) FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s',
            [$action]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function auditRow(string $action, int $resourceId): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT * FROM ' . App::db()->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d ORDER BY id DESC LIMIT 1',
            [$action, $resourceId]
        );

        return $row;
    }
}

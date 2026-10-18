<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\AddressSelectorField;
use ClinicCore\Admin\ClinicManagementPage;
use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

/**
 * Integration tests for offline Iranian province/city selectors across Clinic and Location forms.
 */
final class IranGeographyAddressFormsTest extends WP_UnitTestCase {
    use RealTableMigrations;

    private int $org_id     = 0;
    private int $clinic_id  = 0;
    private int $org_mgr_id = 0;
    private int $loc_mgr_id = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->withRealTables( static fn (): array => App::migrations()->migrate() );
        App::resetScope();

        $db  = App::db();
        $now = $db->nowUtcSql();

        $db->insert(
            'cpms_organizations',
            [
                'name'       => 'سازمان جغرافیا',
                'slug'       => 'iran-geo-org-' . wp_generate_password( 6, false ),
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $this->org_id = (int) $db->wpdb_last_insert_id();

        $admin_id         = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->org_mgr_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->loc_mgr_id = (int) self::factory()->user->create( [ 'role' => RolesAndCapabilities::ROLE_MANAGER ] );

        wp_set_current_user( $admin_id );
        App::organization_clinic_provisioning_service()->bootstrap_owner(
            $admin_id,
            $this->org_id,
            $this->org_mgr_id
        );

        wp_set_current_user( $this->org_mgr_id );
        $provisioned     = App::organization_clinic_provisioning_service()->provision(
            $this->org_mgr_id,
            $this->org_id,
            'درمانگاه جغرافیا',
            'iran-geo-clinic-' . strtolower( wp_generate_password( 6, false ) ),
            'Asia/Tehran',
            'شعبه اصلی جغرافیا',
            'تهران، خیابان آزادی، پلاک ۱۲',
            '02188880000'
        );
        $this->clinic_id = (int) $provisioned['clinic_id'];

        App::membership_service()->create_membership(
            $this->clinic_id,
            $this->loc_mgr_id,
            RolesAndCapabilities::ROLE_MANAGER
        );
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );
        App::resetScope();
        parent::tearDown();
    }

    public function testAddressSelectorFieldRendersOfflineCatalogWithoutTehranDefault(): void {
        ob_start();
        AddressSelectorField::render( '', 'test_addr', 'address_province', 'address_city', 'test_addr' );
        $html = (string) ob_get_clean();

        self::assertStringContainsString( 'data-cpms-address-geography="1"', $html );
        self::assertStringContainsString( 'name="address_province"', $html );
        self::assertStringContainsString( 'name="address_city"', $html );
        self::assertStringContainsString( 'آذربایجان شرقی', $html );
        self::assertStringContainsString( 'خراسان رضوی', $html );
        self::assertStringContainsString( 'سیستان و بلوچستان', $html );
        self::assertStringContainsString( 'کهگیلویه و بویراحمد', $html );
        self::assertStringNotContainsString( 'value="تهران" selected', $html, 'Must never default to Tehran.' );

        ob_start();
        AddressSelectorField::render( 'اصفهان، کاشان، خیابان امیرکبیر، پلاک ۱۰', 'test_selected' );
        $selected_html = (string) ob_get_clean();

        self::assertMatchesRegularExpression( '/<option value="اصفهان"\s+selected=\'selected\'>/u', $selected_html );
        self::assertStringContainsString( '<option value="کاشان" data-province="اصفهان" selected>', $selected_html );
    }

    public function testClinicAndLocationFormsComposeAndValidateIranProvinceAndCityAndPreserveLegacyAddress(): void {
        $db = App::db();

        // 1. Clinic profile update with valid province/city composes canonical Persian address.
        wp_set_current_user( $this->org_mgr_id );
        $res = ClinicManagementPage::update_clinic_for_test(
            [
                'organization_id'  => $this->org_id,
                'clinic_id'        => $this->clinic_id,
                'name'             => 'درمانگاه جغرافیا',
                'address'          => 'خیابان امیرکبیر، پلاک ۱۰',
                'address_province' => 'اصفهان',
                'address_city'     => 'کاشان',
                'phone'            => '03155550000',
            ],
            $this->org_mgr_id
        );
        self::assertSame( '', $res['error'] );
        self::assertFalse( $res['noop'] );
        $stored_clinic_addr = (string) $db->fetchValue(
            'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinic_id ]
        );
        self::assertSame( 'اصفهان، کاشان، خیابان امیرکبیر، پلاک ۱۰', $stored_clinic_addr );

        // 2. Forged cross-province city is rejected fail-closed without mutating stored address.
        $bad_clinic = ClinicManagementPage::update_clinic_for_test(
            [
                'organization_id'  => $this->org_id,
                'clinic_id'        => $this->clinic_id,
                'name'             => 'درمانگاه جغرافیا',
                'address'          => 'خیابان نمونه',
                'address_province' => 'تهران',
                'address_city'     => 'شیراز',
                'phone'            => '03155550000',
            ],
            $this->org_mgr_id
        );
        self::assertNotSame( '', $bad_clinic['error'] );
        self::assertSame(
            'اصفهان، کاشان، خیابان امیرکبیر، پلاک ۱۰',
            (string) $db->fetchValue( 'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', [ $this->clinic_id ] )
        );

        // 3. Location create and update with offline province/city selector and legacy preservation.
        wp_set_current_user( $this->loc_mgr_id );
        $created_loc = LocationAdminPage::upsertLocation(
            [
                'mode'             => 'create',
                'clinic_id'        => $this->clinic_id,
                'name'             => 'شعبه دوم مشهد',
                'slug'             => 'mashhad-branch',
                'address'          => 'بلوار وکیل‌آباد، پلاک ۱۵',
                'address_province' => 'خراسان رضوی',
                'address_city'     => 'مشهد',
                'phone'            => '05138880000',
                'timezone'         => 'Asia/Tehran',
            ],
            $this->loc_mgr_id
        );
        self::assertSame( '', $created_loc['error'] );
        self::assertGreaterThan( 0, $created_loc['location_id'] );
        $loc_addr = (string) $db->fetchValue(
            'SELECT address FROM ' . $db->table( 'cpms_locations' ) . ' WHERE id = %d',
            [ $created_loc['location_id'] ]
        );
        self::assertSame( 'خراسان رضوی، مشهد، بلوار وکیل‌آباد، پلاک ۱۵', $loc_addr );

        // Forged province on Location update is rejected fail-closed.
        $bad_loc = LocationAdminPage::upsertLocation(
            [
                'mode'             => 'update',
                'clinic_id'        => $this->clinic_id,
                'location_id'      => $created_loc['location_id'],
                'name'             => 'شعبه دوم مشهد',
                'address'          => 'بلوار وکیل‌آباد، پلاک ۱۵',
                'address_province' => 'استان ناموجود',
                'address_city'     => '',
                'timezone'         => 'Asia/Tehran',
            ],
            $this->loc_mgr_id
        );
        self::assertNotSame( '', $bad_loc['error'] );
        self::assertSame(
            'خراسان رضوی، مشهد، بلوار وکیل‌آباد، پلاک ۱۵',
            (string) $db->fetchValue( 'SELECT address FROM ' . $db->table( 'cpms_locations' ) . ' WHERE id = %d', [ $created_loc['location_id'] ] )
        );
    }
}

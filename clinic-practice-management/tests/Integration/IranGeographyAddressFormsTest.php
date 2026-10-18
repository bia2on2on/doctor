<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\AddressSelectorField;
use ClinicCore\Admin\ClinicManagementPage;
use ClinicCore\Admin\CpmsAssets;
use ClinicCore\Admin\LocationAdminPage;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/**
 * Integration tests for offline Iranian province/city selectors across Clinic and Location forms.
 */
final class IranGeographyAddressFormsTest extends WP_UnitTestCase {

    private int $org_id     = 0;
    private int $clinic_id  = 0;
    private int $org_mgr_id = 0;
    private int $loc_mgr_id = 0;

    protected function setUp(): void {
        parent::setUp();
        App::migrations()->migrate();
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

        $db->insert(
            'cpms_clinic_memberships',
            [
                'clinic_id'  => $this->clinic_id,
                'wp_user_id' => $this->loc_mgr_id,
                'role_key'   => RolesAndCapabilities::ROLE_MANAGER,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    protected function tearDown(): void {
        unset( $_GET['page'] );
        wp_dequeue_script( 'cpms-admin' );
        wp_dequeue_style( 'cpms-admin' );
        wp_set_current_user( 0 );
        App::resetScope();
        parent::tearDown();
    }

    public function testCpmsAssetsRecognizesAndEnqueuesOnOrganizationClinicAndLocationPages(): void {
        foreach ( [ 'cpms-organizations', 'cpms-clinic-management', 'cpms-locations' ] as $slug ) {
            wp_dequeue_script( 'cpms-admin' );
            wp_dequeue_style( 'cpms-admin' );
            $_GET['page'] = $slug;

            self::assertTrue( CpmsAssets::isCpmsPage(), "Page {$slug} must be recognized by CpmsAssets." );
            self::assertStringContainsString( 'cpms-admin', CpmsAssets::bodyClass( 'wp-admin' ), "Page {$slug} must receive cpms-admin body class." );

            CpmsAssets::enqueue();
            self::assertTrue( wp_script_is( 'cpms-admin', 'enqueued' ), "cpms-admin.js must be enqueued on {$slug}." );
            self::assertTrue( wp_style_is( 'cpms-admin', 'enqueued' ), "cpms-admin.css must be enqueued on {$slug}." );
        }

        // Pages without geography/admin-JS controls remain excluded from the scoped allowlist.
        foreach ( [ 'cpms-organization-manager-bootstrap', 'cpms-organization-managers', 'cpms-not-a-page' ] as $non_asset_slug ) {
            wp_dequeue_script( 'cpms-admin' );
            wp_dequeue_style( 'cpms-admin' );
            $_GET['page'] = $non_asset_slug;

            self::assertFalse( CpmsAssets::isCpmsPage(), "Page {$non_asset_slug} must not be in CpmsAssets allowlist." );
            self::assertSame( 'wp-admin', CpmsAssets::bodyClass( 'wp-admin' ) );
            CpmsAssets::enqueue();
            self::assertFalse( wp_script_is( 'cpms-admin', 'enqueued' ) );
            self::assertFalse( wp_style_is( 'cpms-admin', 'enqueued' ) );
        }
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

        // Province + city pre-selection.
        ob_start();
        AddressSelectorField::render( 'اصفهان، کاشان، خیابان امیرکبیر، پلاک ۱۰', 'test_selected' );
        $selected_html = (string) ob_get_clean();

        self::assertStringContainsString( '<option value="اصفهان" selected>', $selected_html );
        self::assertStringContainsString( '<option value="کاشان" data-province="اصفهان" selected>', $selected_html );

        // Province-only stored address: province is pre-selected, city remains unselected (no default city).
        ob_start();
        AddressSelectorField::render( 'خراسان رضوی، بلوار وکیل‌آباد، پلاک ۱۵', 'test_prov_only' );
        $prov_only_html = (string) ob_get_clean();

        self::assertStringContainsString( '<option value="خراسان رضوی" selected>', $prov_only_html );
        self::assertStringContainsString( 'data-selected-city=""', $prov_only_html );
        self::assertStringNotContainsString( 'data-province="خراسان رضوی" selected>', $prov_only_html );

        // Historical free-text address without province: neither province nor city is selected.
        ob_start();
        AddressSelectorField::render( 'خیابان ولیعصر، کوچه سوم، پلاک ۸', 'test_free_text' );
        $free_text_html = (string) ob_get_clean();

        self::assertStringNotContainsString( ' selected>', $free_text_html );
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

        // 1b. Province-only Clinic profile update and round-trip edit to another province without duplication.
        $prov_only_res = ClinicManagementPage::update_clinic_for_test(
            [
                'organization_id'  => $this->org_id,
                'clinic_id'        => $this->clinic_id,
                'name'             => 'درمانگاه جغرافیا',
                'address'          => $stored_clinic_addr,
                'address_province' => 'یزد',
                'address_city'     => '',
                'phone'            => '03535550000',
            ],
            $this->org_mgr_id
        );
        self::assertSame( '', $prov_only_res['error'] );
        $stored_prov_only = (string) $db->fetchValue(
            'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinic_id ]
        );
        self::assertSame( 'یزد، خیابان امیرکبیر، پلاک ۱۰', $stored_prov_only );

        // Editing the province-only address to another province replaces 'یزد' cleanly.
        $prov_switch_res = ClinicManagementPage::update_clinic_for_test(
            [
                'organization_id'  => $this->org_id,
                'clinic_id'        => $this->clinic_id,
                'name'             => 'درمانگاه جغرافیا',
                'address'          => $stored_prov_only,
                'address_province' => 'کرمان',
                'address_city'     => '',
                'phone'            => '03435550000',
            ],
            $this->org_mgr_id
        );
        self::assertSame( '', $prov_switch_res['error'] );
        $stored_prov_switch = (string) $db->fetchValue(
            'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinic_id ]
        );
        self::assertSame( 'کرمان، خیابان امیرکبیر، پلاک ۱۰', $stored_prov_switch );

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
            'کرمان، خیابان امیرکبیر، پلاک ۱۰',
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

        // 3b. Historical free-text Location address is preserved verbatim when province/city selectors are empty.
        $free_text_loc = LocationAdminPage::upsertLocation(
            [
                'mode'             => 'update',
                'clinic_id'        => $this->clinic_id,
                'location_id'      => $created_loc['location_id'],
                'name'             => 'شعبه دوم مشهد',
                'address'          => 'خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۱۲',
                'address_province' => '',
                'address_city'     => '',
                'timezone'         => 'Asia/Tehran',
            ],
            $this->loc_mgr_id
        );
        self::assertSame( '', $free_text_loc['error'] );
        self::assertSame(
            'خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۱۲',
            (string) $db->fetchValue( 'SELECT address FROM ' . $db->table( 'cpms_locations' ) . ' WHERE id = %d', [ $created_loc['location_id'] ] )
        );

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
            'خیابان ولیعصر، بالاتر از میدان ونک، پلاک ۱۲',
            (string) $db->fetchValue( 'SELECT address FROM ' . $db->table( 'cpms_locations' ) . ' WHERE id = %d', [ $created_loc['location_id'] ] )
        );
    }
}

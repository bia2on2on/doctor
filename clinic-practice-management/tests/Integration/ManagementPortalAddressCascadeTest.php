<?php
/**
 * Regression coverage for the two reproduced frontend Management Portal defects:
 *
 *  D1 — the frontend portal did not initialize province→city filtering, so a
 *       Tehran clinic could be submitted with Tabriz selected (or vice versa)
 *       and the server rejected an otherwise normal onboarding submission.
 *  D2 — repeated Clinic forms shared HTML element ids (address-city controls,
 *       `_cpms_nonce`), so labels targeted the wrong control.
 *
 * The tests below run against real migrations and real MySQL (same fixture
 * pattern as ManagementPortalTest) and render the REAL portal template —
 * no mocks, no synthetic markup.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Admin\AddressSelectorField;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Geography\IranProvinceCityCatalog;
use ClinicCore\Frontend\ManagementPortalController;
use ClinicCore\Frontend\ManagementPortalShell;
use WP_UnitTestCase;

require_once __DIR__ . '/RealTableMigrations.php';

final class ManagementPortalAddressCascadeTest extends WP_UnitTestCase {

    use RealTableMigrations;

    private int $org_id       = 0;
    private int $org_mgr_id   = 0;
    private int $clinic_a     = 0;
    private int $clinic_b     = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->withRealTables( static fn(): array => App::migrations()->migrate() );
        App::resetScope();

        $this->org_id     = $this->create_org( 'Cascade Org' );
        $this->org_mgr_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->grant_org_manager( $this->org_id, $this->org_mgr_id );

        // Both Clinics are created through the SAME frontend dispatch path the
        // portal uses (Organization authority), never through a direct insert.
        $created_a = ManagementPortalController::dispatch(
            $this->org_mgr_id,
            'org_create_clinic',
            [
                'organization_id'  => (string) $this->org_id,
                'name'             => 'Cascade Clinic A',
                'slug'             => 'cascade-clinic-a',
                'timezone'         => 'Asia/Tehran',
                'location_name'    => 'Main A',
                'address'          => 'خیابان ولیعصر، پلاک ۱',
                'address_province' => 'تهران',
                'address_city'     => 'تهران',
            ]
        );
        self::assertTrue( $created_a['ok'], 'fixture: valid Tehran/Tehran clinic is accepted' );
        $this->clinic_a = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE slug = %s',
            [ 'cascade-clinic-a' ]
        );

        $created_b = ManagementPortalController::dispatch(
            $this->org_mgr_id,
            'org_create_clinic',
            [
                'organization_id'  => (string) $this->org_id,
                'name'             => 'Cascade Clinic B (held)',
                'slug'             => 'cascade-clinic-b',
                'timezone'         => 'Asia/Tehran',
                'location_name'    => 'Main B',
                'address'          => 'میدان نقش جهان',
                'address_province' => 'اصفهان',
                'address_city'     => 'اصفهان',
            ]
        );
        self::assertTrue( $created_b['ok'], 'fixture: valid Isfahan/Isfahan clinic is accepted' );
        $this->clinic_b = (int) App::db()->fetchValue(
            'SELECT id FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE slug = %s',
            [ 'cascade-clinic-b' ]
        );
        self::assertGreaterThan( 0, $this->clinic_a );
        self::assertGreaterThan( 0, $this->clinic_b );

        // Clinic B is a HELD Clinic (suspended): the page must therefore show one
        // held and one active Clinic, both with their own repeated forms.
        $held = ManagementPortalController::dispatch(
            $this->org_mgr_id,
            'org_clinic_status',
            [
                'organization_id' => (string) $this->org_id,
                'clinic_id'       => (string) $this->clinic_b,
                'active'          => '0',
            ]
        );
        self::assertTrue( $held['ok'], 'fixture: Clinic B becomes a held (suspended) Clinic' );
        self::assertSame(
            'suspended',
            (string) App::db()->fetchValue(
                'SELECT status FROM ' . App::db()->table( 'cpms_clinics' ) . ' WHERE id = %d',
                [ $this->clinic_b ]
            )
        );

        wp_set_current_user( $this->org_mgr_id );
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );
        $_GET = [];
        $db   = App::db();
        foreach ( [ $this->clinic_a, $this->clinic_b ] as $cid ) {
            if ( $cid > 0 ) {
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE clinic_id = %d', [ $cid ] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d', [ $cid ] );
                $db->query( 'DELETE FROM ' . $db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d', [ $cid ] );
            }
        }
        if ( $this->org_id > 0 ) {
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d', [ $this->org_id ] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_organization_memberships' ) . ' WHERE organization_id = %d', [ $this->org_id ] );
            $db->query( 'DELETE FROM ' . $db->table( 'cpms_organizations' ) . ' WHERE id = %d', [ $this->org_id ] );
        }
        if ( $this->org_mgr_id > 0 ) {
            wp_delete_user( $this->org_mgr_id );
        }
        App::resetScope();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures (proven pattern, identical to ManagementPortalTest)
    // ------------------------------------------------------------------
    private function create_org( string $name ): int {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_organizations',
            [
                'name'       => $name . ' ' . bin2hex( random_bytes( 3 ) ),
                'slug'       => 'cascade-org-' . bin2hex( random_bytes( 4 ) ),
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return (int) $db->wpdb_last_insert_id();
    }

    private function grant_org_manager( int $org_id, int $user_id ): void {
        $db  = App::db();
        $now = $db->nowUtcSql();
        $db->insert(
            'cpms_organization_memberships',
            [
                'organization_id'       => $org_id,
                'wp_user_id'            => $user_id,
                'role_key'              => 'organization_manager',
                'status'                => 'active',
                'created_by_wp_user_id' => $user_id,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]
        );
    }

    /** Render the production portal template exactly as WordPress would. */
    private function render_portal( int $org_id ): string {
        $_GET = [ ManagementPortalShell::ORG_PARAM => (string) $org_id ];
        ob_start();
        include ManagementPortalShell::template_path();
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------
    // D1 — cascade is initialized and Tehran never offers Tabriz
    // ------------------------------------------------------------------
    public function testPortalViewLoadsTheOfflineCascadeScriptAndKeepsTehranAndTabrizApart(): void {
        $html = $this->render_portal( $this->org_id );

        // The portal owns its document (no wp_head), so the scoped script must be
        // present exactly for this surface.
        self::assertStringContainsString( '/assets/js/cpms-management-portal.js', $html );
        self::assertStringContainsString( 'data-cpms-address-geography="1"', $html );
        self::assertStringContainsString( 'data-cpms-province-select="1"', $html );
        self::assertStringContainsString( 'data-cpms-city-select="1"', $html );

        // Every city option is scoped to its province, so selecting Tehran hides
        // Tabriz (East Azerbaijan) instead of leaving it selectable.
        self::assertMatchesRegularExpression(
            '/data-province="آذربایجان شرقی"[^>]*>(?:(?!<\/optgroup>).)*value="تبریز"/su',
            $html,
            'Tabriz must be grouped under East Azerbaijan'
        );
        self::assertMatchesRegularExpression(
            '/data-province="تهران"[^>]*>(?:(?!<\/optgroup>).)*value="تهران"/su',
            $html,
            'Tehran city must be grouped under Tehran province'
        );

        // A held Clinic stays visible (its own form) — not removed by the cascade.
        self::assertStringContainsString( 'Cascade Clinic B (held)', $html );
    }

    public function testCatalogRejectsCrossProvinceCityAndCityWithoutProvince(): void {
        try {
            IranProvinceCityCatalog::resolve_submitted_address( 'خیابان آزمون', 'تهران', 'تبریز' );
            self::fail( 'Tehran province + Tabriz city must be rejected.' );
        } catch ( \InvalidArgumentException $e ) {
            self::assertStringContainsString( 'شهر', $e->getMessage() );
        }

        try {
            IranProvinceCityCatalog::resolve_submitted_address( 'خیابان آزمون', '', 'تهران' );
            self::fail( 'A city without its province must be rejected.' );
        } catch ( \InvalidArgumentException $e ) {
            self::assertStringContainsString( 'شهر', $e->getMessage() );
        }

        self::assertSame(
            'تهران، تهران، خیابان ولیعصر، پلاک ۱',
            IranProvinceCityCatalog::resolve_submitted_address( 'خیابان ولیعصر، پلاک ۱', 'تهران', 'تهران' )
        );
    }

    public function testServerRejectsInvalidProvinceCityPairsAndPreservesTheStoredAddress(): void {
        $db = App::db();
        $stored_before = (string) $db->fetchValue(
            'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $this->clinic_a ]
        );
        self::assertStringContainsString( 'تهران', $stored_before );

        // Forged cross-province pair (Tehran + Tabriz) on an update: refused, and
        // the durable address is untouched.
        $bad_update = ManagementPortalController::dispatch(
            $this->org_mgr_id,
            'org_update_clinic',
            [
                'organization_id'  => (string) $this->org_id,
                'clinic_id'        => (string) $this->clinic_a,
                'name'             => 'Cascade Clinic A',
                'address'          => 'خیابان آزمون',
                'address_province' => 'تهران',
                'address_city'     => 'تبریز',
            ]
        );
        self::assertFalse( $bad_update['ok'], 'server-side rejection is mandatory (client filtering is never trusted)' );
        self::assertSame(
            $stored_before,
            (string) $db->fetchValue( 'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', [ $this->clinic_a ] )
        );

        // Unknown province on create: refused, nothing persisted.
        $bad_create = ManagementPortalController::dispatch(
            $this->org_mgr_id,
            'org_create_clinic',
            [
                'organization_id'  => (string) $this->org_id,
                'name'             => 'Forged Province Clinic',
                'slug'             => 'forged-province-clinic',
                'timezone'         => 'Asia/Tehran',
                'address_province' => 'آتلانتیس',
                'address_city'     => '',
            ]
        );
        self::assertFalse( $bad_create['ok'] );
        self::assertSame(
            0,
            (int) $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE slug = %s', [ 'forged-province-clinic' ] )
        );

        // A valid province switch replaces the prefix cleanly (never duplicates it).
        $ok_update = ManagementPortalController::dispatch(
            $this->org_mgr_id,
            'org_update_clinic',
            [
                'organization_id'  => (string) $this->org_id,
                'clinic_id'        => (string) $this->clinic_a,
                'name'             => 'Cascade Clinic A',
                'address'          => $stored_before,
                'address_province' => 'یزد',
                'address_city'     => '',
            ]
        );
        self::assertTrue( $ok_update['ok'] );
        self::assertSame(
            'یزد، خیابان ولیعصر، پلاک ۱',
            (string) $db->fetchValue( 'SELECT address FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', [ $this->clinic_a ] )
        );
    }

    // ------------------------------------------------------------------
    // D2 — repeated Clinic forms: zero duplicate ids, labels target controls
    // ------------------------------------------------------------------
    public function testPortalPageWithTwoClinicsHasNoDuplicateElementIds(): void {
        $html = $this->render_portal( $this->org_id );

        // Both Clinic forms are on the page (one active, one held).
        $parsed = new \DOMDocument();
        libxml_use_internal_errors( true );
        $parsed->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();

        $xpath = new \DOMXPath( $parsed );
        $forms = $xpath->query( '//form[contains(@class,"cpms-mgmt-form")]' );
        self::assertGreaterThanOrEqual(
            2,
            $forms->length,
            'the Organization overview must render repeated Clinic forms'
        );

        $ids       = [];
        $duplicate = [];
        foreach ( $xpath->query( '//*[@id]' ) as $node ) {
            $id = (string) $node->getAttribute( 'id' );
            if ( '' === $id ) {
                continue;
            }
            if ( isset( $ids[ $id ] ) ) {
                $duplicate[] = $id;
            }
            $ids[ $id ] = true;
        }
        self::assertSame( [], array_values( array_unique( $duplicate ) ), 'rendered portal must contain zero duplicate HTML ids (D2)' );

        // Specifically: the nonce inputs and the address-city controls are unique.
        $nonce_ids = [];
        foreach ( $xpath->query( '//input[@name="_cpms_nonce"]' ) as $nonce ) {
            $nonce_ids[] = (string) $nonce->getAttribute( 'id' );
        }
        self::assertGreaterThanOrEqual( 2, count( $nonce_ids ), 'each form carries its own nonce' );
        self::assertSame( array_values( array_unique( $nonce_ids ) ), $nonce_ids, 'nonce element ids must be unique' );
        self::assertNotContains( '_cpms_nonce', $nonce_ids, 'the shared nonce NAME must never be reused as an element id' );

        foreach ( $xpath->query( '//select[@data-cpms-city-select="1"]' ) as $city ) {
            self::assertNotSame( '', (string) $city->getAttribute( 'id' ) );
        }

        // Every label's `for` resolves to exactly one control in the document.
        foreach ( $xpath->query( '//label[@for]' ) as $label ) {
            $target = (string) $label->getAttribute( 'for' );
            self::assertSame(
                1,
                $xpath->query( '//*[@id="' . $target . '"]' )->length,
                'label for="' . $target . '" must resolve to exactly one control'
            );
        }
    }

    public function testTwoClinicFormsAddressSelectorsAreDistinctAndLabelsResolve(): void {
        $html = $this->render_portal( $this->org_id );

        $parsed = new \DOMDocument();
        libxml_use_internal_errors( true );
        $parsed->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new \DOMXPath( $parsed );

        $provinces = $xpath->query( '//select[@data-cpms-province-select="1"]' );
        self::assertGreaterThanOrEqual( 2, $provinces->length );

        $seen = [];
        foreach ( $provinces as $province ) {
            $id = (string) $province->getAttribute( 'id' );
            self::assertArrayNotHasKey( $id, $seen, 'province select ids must be unique per form' );
            $seen[ $id ] = true;
        }

        // The stored Tehran clinic pre-selects Tehran; the Isfahan clinic Isfahan.
        $selected = [];
        foreach ( $xpath->query( '//select[@data-cpms-province-select="1"]/option[@selected]' ) as $option ) {
            $selected[] = (string) $option->getAttribute( 'value' );
        }
        self::assertContains( 'تهران', $selected, 'the Tehran Clinic keeps its province during edit' );
        self::assertContains( 'اصفهان', $selected, 'the Isfahan Clinic keeps its province during edit' );
    }

    // ------------------------------------------------------------------
    // Persian RTL / mobile 390px contract
    // ------------------------------------------------------------------
    public function testPortalDocumentIsPersianRtlAndResponsiveAt390px(): void {
        $html = $this->render_portal( $this->org_id );

        self::assertStringContainsString( 'lang="fa"', $html );
        self::assertStringContainsString( 'dir="rtl"', $html );
        self::assertStringContainsString( 'name="viewport" content="width=device-width, initial-scale=1"', $html );
        // Responsive address block: wraps instead of overflowing a 390px viewport.
        self::assertStringContainsString( 'flex-wrap:wrap', $html );
        self::assertStringContainsString( 'max-width:100%', $html );
    }

    // ------------------------------------------------------------------
    // Assets and progressive enhancement
    // ------------------------------------------------------------------
    public function testCascadeScriptIsScopedToThePortalAndFormStaysUsableWithoutJs(): void {
        self::assertFalse( ManagementPortalShell::is_portal_request(), 'unit environment is not a portal request' );

        $js_path = dirname( __DIR__, 2 ) . '/assets/js/cpms-management-portal.js';
        self::assertFileExists( $js_path );
        $js = (string) file_get_contents( $js_path );
        self::assertStringContainsString( 'data-cpms-address-geography', $js );
        self::assertStringContainsString( 'data-cpms-province-select', $js );
        self::assertStringNotContainsString( 'fetch(', $js, 'cascade must be fully offline (no network calls)' );
        self::assertStringNotContainsString( 'XMLHttpRequest', $js );

        // Without JS the selects still exist with every city group visible and the
        // server revalidates, so the form remains usable (no hidden required data).
        ob_start();
        AddressSelectorField::render( '', 'nojs_probe' );
        $markup = (string) ob_get_clean();
        self::assertStringContainsString( 'name="address_city"', $markup );
        self::assertStringNotContainsString( 'hidden disabled', $markup, 'no province selected ⇒ nothing is hidden for a no-JS client' );
    }
}

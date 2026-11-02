<?php

/**
 * Package P3 — defect 6 follow-up: a Clinic whose clinicians have NO durable
 * Location assignment must stay usable on the public booking surface.
 *
 * The Location-first contract publishes, server-side, only the Locations that
 * already have at least one eligible clinician. When a Clinic has no such
 * Location at all, publishing the Clinic with an EMPTY list is not "fail
 * closed" for a visitor: the frontend then has a Location step that offers
 * nothing and disables every doctor, so the page cannot be booked at all. That
 * data state is expected to exist — the product itself ships a recovery screen
 * for clinicians that have no ``cpms_clinician_locations`` row — and the
 * pre-change surface simply let the visitor pick a doctor and derived the
 * Location from the availability answer.
 *
 * This suite pins the behaviour of that state:
 *
 *  A. A Clinic with zero eligible Locations is NOT published in
 *     ``locations_by_clinic``; the frontend keeps the pre-change flow.
 *  B. A durable assignment publishes exactly that one Location (positive
 *     control — the fix must not become a blanket "never publish").
 *  C. The eligibility rule itself does not change: an unassigned doctor still
 *     advertises no Location, and the doctor list is still server-rendered.
 *
 * The suite is READ-ONLY: it creates fixtures, renders the shortcode and reads
 * the published contract. Nothing is booked, mutated or deleted. No assertion
 * depends on wall-clock values.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PkgP3UnassignedClinicianLegacyTest extends WP_UnitTestCase
{
    private const SHORTCODE = 'cpms_public_booking';
    private const TZ_TEHRAN = 'Asia/Tehran';

    private string $uid = '';

    private int $org = 0;

    private int $clinic_id = 0;

    private int $location_id = 0;

    private int $clinician_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user( 0 );

        $this->uid = uniqid( 'p3ul', false );
        $this->build_fixture();
    }

    protected function tearDown(): void
    {
        Settings::flushCache();
        ScopeContext::clear();
        App::resetScope();
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    // =====================================================================
    // A — no eligible Location must not be published as an empty contract
    // =====================================================================

    public function test_clinic_with_no_eligible_location_publishes_no_contract_entry(): void
    {
        $config    = $this->config( $this->render( (string) $this->clinic_id ) );
        $by_clinic = is_array( $config['locations_by_clinic'] ?? null ) ? $config['locations_by_clinic'] : [];

        self::assertArrayNotHasKey(
            (string) $this->clinic_id,
            $by_clinic,
            'A Clinic with zero eligible Locations must not be published as an EMPTY Location '
            . 'list: the frontend would render a Location step with no option and disable every '
            . 'doctor, so the public surface could not be booked at all. That data state has no '
            . 'Location to choose, therefore the pre-change flow stays in force.'
        );
    }

    public function test_unassigned_doctor_advertises_no_location(): void
    {
        $html = $this->render( (string) $this->clinic_id );

        self::assertSame(
            [],
            $this->locations_of( $html, $this->clinician_id ),
            'An unassigned doctor is eligible nowhere; the item must not advertise a Location.'
        );
    }

    public function test_surface_still_server_renders_the_doctor_list(): void
    {
        $html = $this->render( (string) $this->clinic_id );

        self::assertStringContainsString(
            'data-clinician-id="' . $this->clinician_id . '"',
            $html,
            'The pre-change flow needs the server-rendered doctor list; it is not filtered by '
            . 'Location on the server.'
        );
    }

    // =====================================================================
    // B — positive control: one durable assignment publishes one Location
    // =====================================================================

    public function test_a_durable_assignment_publishes_exactly_that_location(): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->insert_clinician_location( $this->clinician_id, $this->location_id, 1, $now );

        $config    = $this->config( $this->render( (string) $this->clinic_id ) );
        $by_clinic = is_array( $config['locations_by_clinic'] ?? null ) ? $config['locations_by_clinic'] : [];

        self::assertArrayHasKey(
            (string) $this->clinic_id,
            $by_clinic,
            'With a durable assignment the Clinic MUST carry its eligible Location.'
        );

        $published = array_map(
            static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ),
            (array) $by_clinic[ (string) $this->clinic_id ]
        );

        self::assertSame(
            [ $this->location_id ],
            $published,
            'Exactly the assigned ACTIVE Location of THIS Clinic is published.'
        );
        self::assertSame(
            [ $this->location_id ],
            $this->locations_of( $this->render( (string) $this->clinic_id ), $this->clinician_id ),
            'The doctor item now carries the Location where it is eligible.'
        );
    }

    // =====================================================================
    // fixture
    // =====================================================================

    private function build_fixture(): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );

        $this->org = $this->insert_row(
            'cpms_organizations',
            [
                'name'       => 'Org P3UL ' . $this->uid,
                'slug'       => 'org-p3ul-' . $this->uid,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        $this->clinic_id = $this->insert_row(
            'cpms_clinics',
            [
                'organization_id' => $this->org,
                'name'            => 'Clinic P3UL ' . $this->uid,
                'slug'            => 'clinic-p3ul-' . $this->uid,
                'timezone'        => self::TZ_TEHRAN,
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        // One ACTIVE Location of this Clinic that nobody is assigned to yet.
        $this->location_id = $this->insert_row(
            'cpms_locations',
            [
                'clinic_id'  => $this->clinic_id,
                'name'       => 'Main P3UL ' . $this->uid,
                'slug'       => 'main-p3ul-' . $this->uid,
                'address'    => 'Address P3UL',
                'timezone'   => self::TZ_TEHRAN,
                'is_primary' => 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        $wp_user_id = (int) wp_insert_user(
            [
                'user_login' => 'p3ul-' . $this->uid,
                'user_email' => 'p3ul-' . $this->uid . '@p3ul.cpms.test',
                'user_pass'  => wp_generate_password( 20, true ),
                'role'       => RolesAndCapabilities::ROLE_DOCTOR,
            ]
        );
        self::assertGreaterThan( 0, $wp_user_id, 'Fixture WordPress user must be created.' );

        // Active clinician, linked user, active Clinic-wide membership — the ONLY
        // missing piece is the durable clinician -> Location assignment.
        $this->clinician_id = $this->insert_row(
            'cpms_clinicians',
            [
                'clinic_id'  => $this->clinic_id,
                'wp_user_id' => $wp_user_id,
                'full_name'  => 'Dr Unassigned ' . $this->uid,
                'specialty'  => 'قلب',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->insert_row(
            'cpms_clinic_memberships',
            [
                'clinic_id'  => $this->clinic_id,
                'wp_user_id' => $wp_user_id,
                'role_key'   => 'doctor',
                'scope_mode' => 'clinic',
                'status'     => 'active',
                'is_primary' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    private function insert_clinician_location( int $clinician_id, int $location_id, int $is_primary, string $now ): void
    {
        $this->insert_row(
            'cpms_clinician_locations',
            [
                'clinician_id' => $clinician_id,
                'location_id'  => $location_id,
                'is_primary'   => $is_primary,
                'created_at'   => $now,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );
    }

    /**
     * Location ids published for one clinician item.
     *
     * @return list<int>
     */
    private function locations_of( string $html, int $clinician_id ): array
    {
        $pattern = '#data-clinician-id="' . $clinician_id . '"[^>]*data-locations="([^"]*)"#';
        if ( preg_match( $pattern, $html, $m ) !== 1 ) {
            return [];
        }

        $ids = [];
        foreach ( preg_split( '/\s+/', trim( (string) $m[1] ) ) ?: [] as $token ) {
            if ( $token !== '' ) {
                $ids[] = (int) $token;
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function config( string $html ): array
    {
        $pattern = '#<script type="application/json" class="cpms-public-booking__config">(.*?)</script>#s';
        if ( preg_match( $pattern, $html, $m ) !== 1 ) {
            self::fail( 'The rendered surface must publish its runtime config contract.' );
        }

        $decoded = json_decode( (string) html_entity_decode( $m[1], ENT_QUOTES ), true );
        self::assertIsArray( $decoded, 'The runtime config must be valid JSON.' );

        return $decoded;
    }

    private function render( string $clinic_value ): string
    {
        App::boot();

        return (string) do_shortcode( '[' . self::SHORTCODE . ' clinic_id="' . $clinic_value . '"]' );
    }

    /** @param array<string, mixed> $data @param list<string> $formats */
    private function insert_row( string $table, array $data, array $formats ): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert( $wpdb->prefix . $table, $data, $formats );
        self::assertTrue( (bool) $ok, 'fixture insert into ' . $table . ' failed: ' . $wpdb->last_error );
        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan( 0, $id, 'fixture id must be positive/dynamic.' );

        return $id;
    }
}

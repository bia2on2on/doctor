<?php

/**
 * Package P3 — defect 6: the public booking flow must ask for the LOCATION
 * first and then offer only the doctors who are eligible at that location.
 *
 * This suite is ADDITIVE: no existing assertion is modified, removed or
 * weakened. It pins the read-only contract the fix relies on:
 *
 *  A. The Location -> clinician mapping is the SAME eligibility rule the
 *     reception surface already uses (active clinician + linked user + active
 *     Clinic membership whose scope allows the Location + durable assignment
 *     to that ACTIVE Location of THIS Clinic). One shared query, two callers.
 *
 *  B. The public surface publishes, server-side, only the ACTIVE Locations of
 *     the bound Clinic that have at least one eligible clinician, and each
 *     clinician item carries only the Locations where THAT clinician is
 *     eligible. A Location of another Clinic, an inactive Location, an unknown
 *     Location id and a clinician that is inactive / has no linked user / is
 *     assigned only to a foreign Location are never published.
 *
 *  C. Every lookup fails CLOSED: an unknown or foreign Location answers with an
 *     empty list, never with a guessed or first-row Location.
 *
 * The mapping is read-only; nothing here creates, mutates or books anything.
 * No wall-clock value is used to decide any assertion.
 */

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Clinic\PublicClinicDirectory;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;

final class PkgP3LocationFirstTest extends WP_UnitTestCase
{
    private const SHORTCODE = 'cpms_public_booking';
    private const TZ_TEHRAN = 'Asia/Tehran';
    private const UNKNOWN_LOCATION_ID = 987654321;

    private string $uid = '';

    private int $org = 0;

    private int $clinic_a = 0;

    private int $clinic_b = 0;

    private int $location_a1 = 0;

    private int $location_a2 = 0;

    private int $location_a3 = 0;

    private int $location_b1 = 0;

    private int $clinician_eligible_a1 = 0;

    private int $clinician_eligible_both = 0;

    private int $clinician_no_user = 0;

    private int $clinician_foreign_location = 0;

    private int $clinician_inactive = 0;

    private int $clinician_clinic_b = 0;

    protected function setUp(): void
    {
        parent::setUp();

        App::migrations()->migrate();
        Settings::flushCache();
        App::resetScope();
        ScopeContext::clear();
        wp_set_current_user( 0 );

        $this->uid = uniqid( 'p3lf', false );
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
    // A + C — the shared eligibility read (reception rule), fail closed
    // =====================================================================

    public function test_location_scoped_clinician_list_returns_only_eligible_clinicians(): void
    {
        $repo = new MembershipRepository( App::db() );

        $names = $this->names(
            $repo->clinicians_at_location( $this->clinic_a, $this->location_a1 )
        );

        self::assertContains(
            $this->doctor_name( 'Eligible A1', $this->clinician_eligible_a1 ),
            $names,
            'An ACTIVE clinician with an ACTIVE membership and a durable assignment '
            . 'to this ACTIVE Location of this Clinic must be listed.'
        );
        self::assertContains(
            $this->doctor_name( 'Eligible Both', $this->clinician_eligible_both ),
            $names,
            'A clinician assigned to two Locations must be listed at this one.'
        );
        self::assertNotContains(
            $this->doctor_name( 'No User', $this->clinician_no_user ),
            $names,
            'A clinician without a linked WordPress user has no Clinic membership, so it '
            . 'is never eligible (the reception rule requires the membership join).'
        );
        self::assertNotContains(
            $this->doctor_name( 'Foreign Loc', $this->clinician_foreign_location ),
            $names,
            'A clinician assigned ONLY to a Location of another Clinic is never eligible here.'
        );
        self::assertNotContains(
            $this->doctor_name( 'Inactive', $this->clinician_inactive ),
            $names,
            'An inactive clinician is never eligible.'
        );
        self::assertNotContains(
            $this->doctor_name( 'Clinic B', $this->clinician_clinic_b ),
            $names,
            'A clinician of another Clinic is never returned for this Clinic.'
        );
    }

    public function test_foreign_clinic_location_fails_closed(): void
    {
        $repo = new MembershipRepository( App::db() );

        self::assertSame(
            [],
            $repo->clinicians_at_location( $this->clinic_a, $this->location_b1 ),
            'A Location of ANOTHER Clinic must never yield a clinician for this Clinic.'
        );
        self::assertSame(
            [],
            $repo->clinicians_at_location( $this->clinic_b, $this->location_a1 ),
            'The same hold in the other direction: a foreign Location yields nothing.'
        );
    }

    public function test_unknown_location_fails_closed(): void
    {
        $repo = new MembershipRepository( App::db() );

        self::assertSame(
            [],
            $repo->clinicians_at_location( $this->clinic_a, self::UNKNOWN_LOCATION_ID ),
            'An unknown Location id answers with an empty list, never a guessed Location.'
        );
        self::assertSame(
            [],
            $repo->clinicians_at_location( 0, 0 ),
            'A non-positive selector answers with an empty list.'
        );
    }

    public function test_single_clinician_narrowing_never_crosses_tenants(): void
    {
        $repo = new MembershipRepository( App::db() );

        self::assertSame(
            [],
            $repo->clinicians_at_location( $this->clinic_a, $this->location_a1, $this->clinician_clinic_b ),
            'Narrowing to a clinician of another Clinic must return nothing.'
        );
        self::assertNotSame(
            [],
            $repo->clinicians_at_location( $this->clinic_a, $this->location_a1, $this->clinician_eligible_a1 ),
            'Narrowing to an eligible clinician of this Clinic keeps exactly that row.'
        );
    }

    // =====================================================================
    // A — the public directory mapping (one rule, no duplication)
    // =====================================================================

    public function test_directory_publishes_only_locations_with_eligible_clinicians(): void
    {
        $map = ( new PublicClinicDirectory() )->clinicians_by_location( $this->clinic_a );

        self::assertSame(
            [ $this->location_a1, $this->location_a2 ],
            array_keys( $map ),
            'Only ACTIVE Locations of this Clinic that have at least one eligible clinician '
            . 'are published, in ascending Location id order.'
        );
        self::assertArrayNotHasKey(
            $this->location_a3,
            $map,
            'An ACTIVE Location with no eligible clinician is not offered (no dead end).'
        );
        self::assertArrayNotHasKey(
            $this->location_b1,
            $map,
            'A Location of another Clinic is never published.'
        );
    }

    public function test_directory_maps_each_clinician_to_its_own_locations_only(): void
    {
        $map = ( new PublicClinicDirectory() )->clinicians_by_location( $this->clinic_a );

        $a1 = array_column( $map[ $this->location_a1 ] ?? [], 'id' );
        $a2 = array_column( $map[ $this->location_a2 ] ?? [], 'id' );

        self::assertContains( $this->clinician_eligible_a1, $a1, 'Clinician assigned to A1 is published there.' );
        self::assertNotContains(
            $this->clinician_eligible_a1,
            $a2,
            'The same clinician is NOT published at a Location it is not assigned to.'
        );
        self::assertContains( $this->clinician_eligible_both, $a1, 'Multi-Location clinician appears at A1.' );
        self::assertContains( $this->clinician_eligible_both, $a2, 'Multi-Location clinician appears at A2.' );
        self::assertNotContains(
            $this->clinician_no_user,
            $a1,
            'A clinician without a linked user is not eligible anywhere.'
        );
    }

    public function test_directory_fails_closed_for_non_positive_clinic(): void
    {
        self::assertSame(
            [],
            ( new PublicClinicDirectory() )->clinicians_by_location( 0 ),
            'A non-positive Clinic id answers with an empty mapping.'
        );
    }

    // =====================================================================
    // B — what the public booking surface actually publishes
    // =====================================================================

    public function test_surface_publishes_location_first_contract_for_the_bound_clinic(): void
    {
        $html   = $this->render( (string) $this->clinic_a );
        $config = $this->config( $html );

        self::assertArrayHasKey(
            'locations_by_clinic',
            $config,
            'The runtime contract must publish the server-side Location list so the frontend '
            . 'can ask for the Location FIRST (no doctor-first step).'
        );

        $by_clinic = is_array( $config['locations_by_clinic'] ?? null ) ? $config['locations_by_clinic'] : [];
        self::assertArrayHasKey(
            (string) $this->clinic_a,
            $by_clinic,
            'The bound Clinic must carry its own Location list.'
        );

        $published = array_map(
            static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ),
            (array) $by_clinic[ (string) $this->clinic_a ]
        );

        self::assertSame(
            [ $this->location_a1, $this->location_a2 ],
            $published,
            'Only the ACTIVE Locations of THIS Clinic that have an eligible clinician may be published.'
        );
        self::assertNotContains( $this->location_b1, $published, 'A foreign Location must never be published.' );
        self::assertNotContains( $this->location_a3, $published, 'A Location with no eligible clinician is not offered.' );
    }

    public function test_surface_never_leaks_a_foreign_or_inactive_location(): void
    {
        $html = $this->render( (string) $this->clinic_a );

        self::assertStringNotContainsString(
            'Main B P3LF',
            $html,
            'The name of another Clinic\'s Location must never reach the public surface.'
        );
        self::assertStringNotContainsString(
            'data-location-id="' . $this->location_b1 . '"',
            $html,
            'Another Clinic\'s Location id must never be published as an option.'
        );
        self::assertStringNotContainsString(
            'data-location-id="' . $this->location_a3 . '"',
            $html,
            'An ACTIVE Location without any eligible clinician is not offered.'
        );
    }

    public function test_surface_marks_each_clinician_with_its_eligible_locations_only(): void
    {
        $html = $this->render( (string) $this->clinic_a );

        self::assertMatchesRegularExpression(
            '#data-clinician-id="' . $this->clinician_eligible_a1 . '"[^>]*data-locations="#',
            $html,
            'Every rendered clinician item must carry the server-computed Location list that '
            . 'filters it once a Location is chosen.'
        );

        self::assertSame(
            [ $this->location_a1 ],
            $this->locations_of( $html, $this->clinician_eligible_a1 ),
            'A clinician assigned to one Location carries exactly that Location.'
        );
        self::assertSame(
            [ $this->location_a1, $this->location_a2 ],
            $this->locations_of( $html, $this->clinician_eligible_both ),
            'A clinician assigned to two Locations carries exactly those two.'
        );
        self::assertSame(
            [],
            $this->locations_of( $html, $this->clinician_no_user ),
            'A clinician with no eligible Location carries an empty list (never a guessed one).'
        );
    }

    // =====================================================================
    // Fixture helpers
    // =====================================================================

    private function build_fixture(): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );

        $this->org = $this->insert_row(
            'cpms_organizations',
            [
                'name'       => 'Org P3LF ' . $this->uid,
                'slug'       => 'org-p3lf-' . $this->uid,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        $this->clinic_a = $this->insert_clinic( 'Clinic A P3LF ' . $this->uid, 'clinic-a-p3lf-' . $this->uid, $now );
        $this->clinic_b = $this->insert_clinic( 'Clinic B P3LF ' . $this->uid, 'clinic-b-p3lf-' . $this->uid, $now );

        $this->location_a1 = $this->insert_location( $this->clinic_a, 'Main A1 P3LF', 'main-a1-p3lf-' . $this->uid, $now );
        $this->location_a2 = $this->insert_location( $this->clinic_a, 'Branch A2 P3LF', 'branch-a2-p3lf-' . $this->uid, $now );
        $this->location_a3 = $this->insert_location( $this->clinic_a, 'Branch A3 P3LF', 'branch-a3-p3lf-' . $this->uid, $now );
        $this->location_b1 = $this->insert_location( $this->clinic_b, 'Main B P3LF', 'main-b-p3lf-' . $this->uid, $now );

        // One distinct WordPress user per clinician profile: wp_user_id is never 0
        // and never shared (UNIQUE u_clinician_user).
        $user_a1    = $this->insert_user( 'p3lf-a1-' . $this->uid );
        $user_both  = $this->insert_user( 'p3lf-both-' . $this->uid );
        $user_floc  = $this->insert_user( 'p3lf-floc-' . $this->uid );
        $user_inact = $this->insert_user( 'p3lf-inact-' . $this->uid );
        $user_b     = $this->insert_user( 'p3lf-b-' . $this->uid );

        $this->clinician_eligible_a1 = $this->insert_clinician( $this->clinic_a, 'Eligible A1', $user_a1, 1, $now );
        $this->clinician_eligible_both = $this->insert_clinician( $this->clinic_a, 'Eligible Both', $user_both, 1, $now );
        $this->clinician_no_user = $this->insert_clinician( $this->clinic_a, 'No User', null, 1, $now );
        $this->clinician_foreign_location = $this->insert_clinician( $this->clinic_a, 'Foreign Loc', $user_floc, 1, $now );
        $this->clinician_inactive = $this->insert_clinician( $this->clinic_a, 'Inactive', $user_inact, 0, $now );
        $this->clinician_clinic_b = $this->insert_clinician( $this->clinic_b, 'Clinic B', $user_b, 1, $now );

        $this->insert_clinician_location( $this->clinician_eligible_a1, $this->location_a1, 1, $now );
        $this->insert_clinician_location( $this->clinician_eligible_both, $this->location_a1, 1, $now );
        $this->insert_clinician_location( $this->clinician_eligible_both, $this->location_a2, 0, $now );
        $this->insert_clinician_location( $this->clinician_no_user, $this->location_a1, 0, $now );
        $this->insert_clinician_location( $this->clinician_inactive, $this->location_a1, 0, $now );
        // Deliberately a FOREIGN Location: the home Clinic is A, the assignment is B1.
        $this->insert_clinician_location( $this->clinician_foreign_location, $this->location_b1, 1, $now );
        $this->insert_clinician_location( $this->clinician_clinic_b, $this->location_b1, 1, $now );

        // ACTIVE memberships: clinic-wide scope for the A clinicians, B for its own.
        $this->insert_membership( $this->clinic_a, $user_a1, $now );
        $this->insert_membership( $this->clinic_a, $user_both, $now );
        $this->insert_membership( $this->clinic_a, $user_floc, $now );
        $this->insert_membership( $this->clinic_a, $user_inact, $now );
        $this->insert_membership( $this->clinic_b, $user_b, $now );
    }

    /** @param list<array<string, mixed>> $rows */
    private function names( array $rows ): array
    {
        return array_map( static fn ( array $row ): string => (string) ( $row['name'] ?? '' ), $rows );
    }

    private function doctor_name( string $label, int $clinician_id ): string
    {
        return 'Dr ' . $label . ' ' . $this->uid . ' #' . $clinician_id;
    }

    /**
     * Location ids published for one clinician item, in document order.
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

    private function insert_clinic( string $name, string $slug, string $now ): int
    {
        return $this->insert_row(
            'cpms_clinics',
            [
                'organization_id' => $this->org,
                'name'            => $name,
                'slug'            => $slug,
                'timezone'        => self::TZ_TEHRAN,
                'status'          => 'active',
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    private function insert_location( int $clinic_id, string $name, string $slug, string $now ): int
    {
        return $this->insert_row(
            'cpms_locations',
            [
                'clinic_id'  => $clinic_id,
                'name'       => $name,
                'slug'       => $slug,
                'address'    => 'Address ' . $name,
                'timezone'   => self::TZ_TEHRAN,
                'is_primary' => 0,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );
    }

    private function insert_user( string $suffix ): int
    {
        $user_id = (int) wp_insert_user(
            [
                'user_login' => $suffix,
                'user_email' => $suffix . '@p3lf.cpms.test',
                'user_pass'  => wp_generate_password( 20, true ),
                'role'       => RolesAndCapabilities::ROLE_DOCTOR,
            ]
        );
        self::assertGreaterThan( 0, $user_id, 'Fixture WordPress user must be created.' );

        return $user_id;
    }

    private function insert_clinician( int $clinic_id, string $label, ?int $wp_user_id, int $is_active, string $now ): int
    {
        // wp_user_id is either a distinct non-zero id or NULL — never 0 (the
        // eligibility rule requires a linked user; the fixture column stays null
        // for the "no linked user" clinician).
        $data = [
            'clinic_id'  => $clinic_id,
            'wp_user_id' => $wp_user_id,
            'full_name'  => '',
            'specialty'  => 'قلب',
            'is_active'  => $is_active,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $formats = [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ];

        $id = $this->insert_row( 'cpms_clinicians', $data, $formats );
        // The display name embeds the deterministic fixture id: no wall clock, no
        // collision between suites that share one database.
        self::assertGreaterThan( 0, $id, 'Fixture clinician id must be positive.' );
        App::db()->update(
            'cpms_clinicians',
            [ 'full_name' => $this->doctor_name( $label, $id ) ],
            [ 'id' => $id ]
        );

        return $id;
    }

    private function insert_clinician_location( int $clinician_id, int $location_id, int $is_primary, string $now ): void
    {
        $id = $this->insert_row(
            'cpms_clinician_locations',
            [
                'clinician_id' => $clinician_id,
                'location_id'  => $location_id,
                'is_primary'   => $is_primary,
                'created_at'   => $now,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );
        self::assertGreaterThan( 0, $id, 'Fixture clinician-location row must be created.' );
    }

    private function insert_membership( int $clinic_id, int $wp_user_id, string $now ): void
    {
        $id = $this->insert_row(
            'cpms_clinic_memberships',
            [
                'clinic_id'  => $clinic_id,
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
        self::assertGreaterThan( 0, $id, 'Fixture membership must be created.' );
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

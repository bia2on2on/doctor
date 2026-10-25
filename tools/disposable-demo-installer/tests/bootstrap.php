<?php
/**
 * Test bootstrap: minimal WordPress surface + in-memory doubles.
 *
 * This file is test-only. It is never packaged into the installer ZIP.
 *
 * @package CpmsDisposableDemo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
define( 'CPDD_VERSION', '1.0.0' );
define( 'CPDD_FILE', __DIR__ . '/../cpms-disposable-demo.php' );
define( 'CPDD_DIR', __DIR__ . '/../' );
define( 'CPDD_URL', 'https://example.test/wp-content/plugins/cpms-disposable-demo/' );
define( 'CPDD_CPMS_PLUGIN', 'clinic-practice-management/clinic-practice-management.php' );
define( 'CPDD_SCHEMA_VERSION', '2026_10_18_0029' );

// --- Minimal WordPress functions used by the tested code paths. -------------
function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function plugin_basename( string $file ): string {
	$dir = str_replace( '\\', '/', dirname( $file ) );
	$dir = preg_replace( '#^.*/wp-content/plugins/#', '', $dir );

	return ( $dir !== '' ? $dir . '/' : '' ) . basename( $file );
}

function add_query_arg( string $key, $value, string $url ): string {
	$sep = str_contains( $url, '?' ) ? '&' : '?';

	return $url . $sep . rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
}

function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
}

function wp_unslash( $value ) {
	return $value;
}

function is_ssl(): bool {
	return true;
}

function is_multisite(): bool {
	return false;
}

function current_user_can( string $cap ): bool {
	return true;
}

function wp_using_ext_object_cache(): bool {
	return false;
}

// --- Plugin classes under test (main plugin file is never loaded here). -----
require_once CPDD_DIR . 'includes/class-cpms-demo-exception.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-env.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-platform.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-manifest.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-preflight.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-credentials.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-request.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-otp.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-provisioner.php';
require_once CPDD_DIR . 'includes/class-cpms-demo-teardown.php';

/**
 * In-memory WordPress environment.
 */
class Cpdd_Fake_Env extends CpmsDemo\Env {

	public array $options       = array();
	public array $users         = array();
	public array $posts         = array();
	public array $passwords     = array();
	public array $sessions      = array();
	public array $caps          = array();
	public array $plugin_list   = array( 'clinic-practice-management/clinic-practice-management.php', 'cpms-disposable-demo/cpms-disposable-demo.php' );
	public bool $multisite      = false;
	public bool $ssl            = true;
	public bool $object_cache    = false;
	public string $php           = '8.3.0';
	public string $wp            = '6.9';
	public int $comments         = 0;
	public int $next_user        = 100;
	public int $next_post        = 500;
	public int $current_user     = 1;
	public int $now              = 1780000000;
	public string $prefix        = 'wp_';
	public string $db            = 'wp_disposable';
	public string $home          = 'https://demo.test/';
	public array $nonces        = array();

	public function is_multisite(): bool {
		return $this->multisite;
	}

	public function is_ssl(): bool {
		return $this->ssl;
	}

	public function home_url(): string {
		return $this->home;
	}

	public function site_url(): string {
		return $this->home;
	}

	public function db_name(): string {
		return $this->db;
	}

	public function table_prefix(): string {
		return $this->prefix;
	}

	public function current_user_id(): int {
		return $this->current_user;
	}

	public function user_can( int $user_id, string $capability ): bool {
		return (bool) ( $this->caps[ $user_id ][ $capability ] ?? false );
	}

	public function user( int $user_id ): ?array {
		return $this->users[ $user_id ] ?? null;
	}

	public function user_by_login( string $login ): ?array {
		foreach ( $this->users as $user ) {
			if ( $user['login'] === $login ) {
				return $user;
			}
		}

		return null;
	}

	public function all_users(): array {
		return array_values( $this->users );
	}

	public function username_exists( string $login ): bool {
		return null !== $this->user_by_login( $login );
	}

	public function email_exists( string $email ): bool {
		foreach ( $this->users as $user ) {
			if ( $user['email'] === $email ) {
				return true;
			}
		}

		return false;
	}

	public function create_user( array $args ): int {
		$id                = $this->next_user++;
		$this->users[ $id ] = array(
			'id'    => $id,
			'login' => $args['user_login'],
			'email' => $args['user_email'],
			'roles' => array( $args['role'] ),
		);
		$this->passwords[ $id ] = $args['user_pass'];

		return $id;
	}

	public function set_user_password( int $user_id, string $password ): void {
		$this->passwords[ $user_id ] = $password;
	}

	public function destroy_sessions( int $user_id ): void {
		$this->sessions[ $user_id ] = 0;
	}

	public function delete_user( int $user_id ): bool {
		unset( $this->users[ $user_id ] );

		return true;
	}

	public function option_get( string $name, $default = false ) {
		return $this->options[ $name ] ?? $default;
	}

	public function option_set( string $name, $value ): bool {
		$this->options[ $name ] = $value;

		return true;
	}

	public function option_delete( string $name ): bool {
		unset( $this->options[ $name ] );

		return true;
	}

	public function active_plugins(): array {
		return $this->plugin_list;
	}

	public function posts_summary(): array {
		$out = array();
		foreach ( $this->posts as $post ) {
			$out[] = array(
				'id'     => $post['id'],
				'type'   => $post['type'],
				'status' => $post['status'],
				'slug'   => $post['slug'],
				'author' => $post['author'],
			);
		}

		return $out;
	}

	public function comment_count(): int {
		return $this->comments;
	}

	public function unexpected_comment_count(): int {
		return $this->comments;
	}

	public function insert_page( array $args ): int {
		$id              = $this->next_post++;
		$this->posts[ $id ] = array(
			'id'      => $id,
			'type'    => 'page',
			'status'  => 'publish',
			'slug'    => $args['slug'],
			'content' => $args['content'],
			'author'  => $args['author'],
		);

		return $id;
	}

	public function post( int $post_id ): ?array {
		return $this->posts[ $post_id ] ?? null;
	}

	public function delete_post( int $post_id ): bool {
		unset( $this->posts[ $post_id ] );

		return true;
	}

	public function permalink( int $post_id ): string {
		return $this->home . '?page_id=' . $post_id;
	}

	public function object_cache_enabled(): bool {
		return $this->object_cache;
	}

	public function wp_version(): string {
		return $this->wp;
	}

	public function php_version(): string {
		return $this->php;
	}

	public function now(): int {
		return $this->now;
	}

	public function now_sql(): string {
		return gmdate( 'Y-m-d H:i:s', $this->now );
	}

	public function mac_key(): string {
		return 'test-mac-key|cpms-disposable-demo|1';
	}

	public function nonce_create( string $action ): string {
		return 'nonce-' . $action;
	}

	public function nonce_verify( string $nonce, string $action ): bool {
		return hash_equals( 'nonce-' . $action, $nonce );
	}

	public function is_user_logged_in(): bool {
		return $this->current_user > 0;
	}

	public function constant_defined( string $key ): bool {
		return false;
	}

	/**
	 * Seed a clean disposable site: one Administrator, default WP content.
	 */
	public function seed_clean_site(): void {
		$this->users        = array(
			1 => array(
				'id'    => 1,
				'login' => 'owner',
				'email' => 'owner@example.test',
				'roles' => array( 'administrator' ),
			),
		);
		$this->caps         = array( 1 => array( 'manage_options' => true, 'install_plugins' => true ) );
		$this->posts        = array(
			1 => array(
				'id'      => 1,
				'type'    => 'post',
				'status'  => 'publish',
				'slug'    => 'hello-world',
				'content' => '',
				'author'  => 1,
			),
			2 => array(
				'id'      => 2,
				'type'    => 'page',
				'status'  => 'publish',
				'slug'    => 'sample-page',
				'content' => '',
				'author'  => 1,
			),
		);
		$this->current_user = 1;
		$this->next_user    = 100;
		$this->next_post    = 500;
	}
}

/**
 * In-memory CPMS adapter.
 *
 * Implements the ownership predicates the teardown uses, so the tests exercise
 * the real predicate-selection logic rather than a mocked plan.
 */
class Cpdd_Fake_Platform extends CpmsDemo\Platform {

	public array $tables_data = array();
	public array $calls       = array();
	public bool $available_flag = true;
	public string $schema     = '2026_10_18_0029';
	public array $settings    = array();
	public int $fake_clinic_id = 0;

	public function available(): bool {
		return $this->available_flag;
	}

	public function plugin_version(): string {
		return '1.0.0';
	}

	public function schema_version(): ?string {
		return $this->schema;
	}

	public function seed_clean_install(): void {
		$this->tables_data = array(
			'cpms_organizations' => array( array( 'id' => 1, 'slug' => 'default', 'status' => 'active' ) ),
			'cpms_clinics'       => array( array( 'id' => 1, 'organization_id' => 1, 'slug' => 'default', 'status' => 'active' ) ),
			'cpms_locations'     => array( array( 'id' => 1, 'clinic_id' => 1, 'slug' => 'main', 'is_primary' => 1, 'is_active' => 1 ) ),
			'cpms_settings'      => array(
				array(
					'clinic_id'            => 1,
					'key'                  => 'jobs.last_tick_at',
					'value_json'           => (string) json_encode( 1780000000 ),
					'updated_by_wp_user_id' => null,
				),
			),
		);
		foreach ( CpmsDemo\Preflight::KNOWN_TABLES as $table ) {
			if ( ! isset( $this->tables_data[ $table ] ) ) {
				$this->tables_data[ $table ] = array();
			}
		}
	}

	public function table( string $short ): string {
		return 'wp_' . $short;
	}

	public function tables(): array {
		return array_map( static fn ( $name ) => 'wp_' . $name, array_keys( $this->tables_data ) );
	}

	public function table_names(): array {
		return array_keys( $this->tables_data );
	}

	public function table_exists( string $short ): bool {
		return array_key_exists( $short, $this->tables_data );
	}

	public function count_all( string $short ): int {
		return count( $this->tables_data[ $short ] ?? array() );
	}

	public function columns( string $table ): array {
		$short = str_starts_with( $table, 'wp_' ) ? substr( $table, 3 ) : $table;
		$rows  = $this->tables_data[ $short ] ?? array();
		if ( array() === $rows ) {
			return $this->default_columns( $short );
		}

		return array_keys( $rows[0] );
	}

	/**
	 * @return list<string>
	 */
	private function default_columns( string $short ): array {
		$map = array(
			'cpms_clinics'       => array( 'id', 'organization_id', 'slug', 'status' ),
			'cpms_organizations' => array( 'id', 'slug', 'status' ),
			'cpms_locations'     => array( 'id', 'clinic_id', 'slug', 'is_primary', 'is_active' ),
			'cpms_settings'      => array( 'clinic_id', 'key', 'value_json', 'updated_by_wp_user_id' ),
			'cpms_patients'      => array( 'id', 'clinic_id', 'mobile' ),
		);

		return $map[ $short ] ?? array( 'id', 'clinic_id' );
	}

	/**
	 * Evaluate the small predicate grammar the teardown emits.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function matches( string $short, string $where, array $params ): array {
		$rows = $this->tables_data[ $short ] ?? array();
		$out  = array();
		foreach ( $rows as $index => $row ) {
			if ( preg_match( '/^([a-z_]+) = %d$/', $where, $m ) === 1 ) {
				if ( (int) ( $row[ $m[1] ] ?? -1 ) === (int) $params[0] ) {
					$out[ $index ] = $row;
				}
				continue;
			}
			if ( preg_match( '/^([a-z_]+) IN \((.*)\)$/', $where, $m ) === 1 ) {
				$values = array_map( 'intval', $params );
				if ( in_array( (int) ( $row[ $m[1] ] ?? -1 ), $values, true ) ) {
					$out[ $index ] = $row;
				}
				continue;
			}
			throw new RuntimeException( 'unsupported test predicate: ' . $where );
		}

		return $out;
	}

	public function count_where( string $table, string $where, array $params = array() ): int {
		$short = str_starts_with( $table, 'wp_' ) ? substr( $table, 3 ) : $table;

		return count( $this->matches( $short, $where, $params ) );
	}

	public function delete_where( string $table, string $where, array $params = array() ): int {
		$short   = str_starts_with( $table, 'wp_' ) ? substr( $table, 3 ) : $table;
		$matched = $this->matches( $short, $where, $params );
		$keep    = array();
		$drops   = 0;
		foreach ( $this->tables_data[ $short ] ?? array() as $index => $row ) {
			if ( in_array( $index, array_keys( $matched ), true ) ) {
				++$drops;
				continue;
			}
			$keep[] = $row;
		}
		$this->tables_data[ $short ] = $keep;

		return $drops;
	}

	public function fetch_all( string $sql, array $params = array() ): array {
		if ( preg_match( '/FROM wp_([a-z_]+) WHERE ([a-z_]+) = %d/', $sql, $m ) === 1 ) {
			return $this->matches( $m[1], $m[2] . ' = %d', $params );
		}
		if ( preg_match( '/FROM wp_([a-z_]+) ORDER BY id/', $sql, $m ) === 1 ) {
			return $this->tables_data[ $m[1] ] ?? array();
		}
		throw new RuntimeException( 'unsupported test query: ' . $sql );
	}

	public function fetch_row( string $sql, array $params = array() ): ?array {
		if ( preg_match( '/FROM wp_([a-z_]+) WHERE id = %d/', $sql, $m ) === 1 ) {
			foreach ( $this->tables_data[ $m[1] ] ?? array() as $row ) {
				if ( (int) $row['id'] === (int) $params[0] ) {
					return $row;
				}
			}

			return null;
		}
		throw new RuntimeException( 'unsupported test query: ' . $sql );
	}

	public function bootstrap_state(): array {
		$organizations = $this->tables_data['cpms_organizations'];
		$clinics       = $this->tables_data['cpms_clinics'];
		$locations     = $this->tables_data['cpms_locations'];
		$settings      = $this->tables_data['cpms_settings'];
		$ok            = 1 === count( $organizations )
			&& 1 === (int) $organizations[0]['id']
			&& 'default' === $organizations[0]['slug']
			&& 'active' === $organizations[0]['status']
			&& 1 === count( $clinics )
			&& 1 === (int) $clinics[0]['id']
			&& 1 === (int) $clinics[0]['organization_id']
			&& 'default' === $clinics[0]['slug']
			&& 'active' === $clinics[0]['status']
			&& 1 === count( $locations )
			&& 1 === (int) $locations[0]['clinic_id']
			&& 'main' === $locations[0]['slug']
			&& 1 === (int) $locations[0]['is_primary']
			&& 1 === (int) $locations[0]['is_active'];
		if ( ! $ok ) {
			return array( 'ok' => false, 'reason' => 'bootstrap mismatch' );
		}
		foreach ( $settings as $setting ) {
			$value = json_decode( (string) $setting['value_json'], true );
			if ( 1 !== (int) $setting['clinic_id']
				|| 'jobs.last_tick_at' !== $setting['key']
				|| ! in_array( $setting['updated_by_wp_user_id'], array( null, 0, '0' ), true )
				|| ! is_int( $value )
				|| $value <= 0
			) {
				return array( 'ok' => false, 'reason' => 'unexpected setting' );
			}
		}

		return array( 'ok' => true );
	}

	public function otp_echo_defined(): bool {
		return false;
	}

	// --- Provisioning double ---------------------------------------------------

	public function create_organization( string $name, string $slug ): int {
		$this->calls[] = 'create_organization';
		$id            = max( array_map( static fn ( $row ) => (int) $row['id'], $this->tables_data['cpms_organizations'] ) ) + 1;
		$this->tables_data['cpms_organizations'][] = array(
			'id'     => $id,
			'slug'   => $slug,
			'status' => 'active',
			'name'   => $name,
		);

		return $id;
	}

	public function bootstrap_owner( int $actor_id, int $organization_id, int $manager_id ): array {
		$this->calls[] = 'bootstrap_owner';
		$this->tables_data['cpms_organization_memberships'][] = array(
			'id'              => 1,
			'organization_id' => $organization_id,
			'wp_user_id'      => $manager_id,
		);

		return array( 'ok' => true );
	}

	public function provision_clinic( int $actor_id, int $organization_id, string $name, string $slug, string $timezone, string $location_name, string $address ): array {
		$this->calls[] = 'provision_clinic';
		$clinic_id     = max( array_map( static fn ( $row ) => (int) $row['id'], $this->tables_data['cpms_clinics'] ) ) + 1;
		$location_id   = max( array_map( static fn ( $row ) => (int) $row['id'], $this->tables_data['cpms_locations'] ) ) + 1;
		$this->fake_clinic_id = $clinic_id;
		$this->tables_data['cpms_clinics'][]   = array(
			'id'              => $clinic_id,
			'organization_id' => $organization_id,
			'slug'            => $slug,
			'status'          => 'active',
		);
		$this->tables_data['cpms_locations'][] = array(
			'id'         => $location_id,
			'clinic_id'  => $clinic_id,
			'slug'       => $slug . '-main',
			'is_primary' => 1,
			'is_active'  => 1,
		);

		return array( 'clinic_id' => $clinic_id, 'location_id' => $location_id );
	}

	public function designate_clinic_manager( int $actor_id, int $organization_id, int $clinic_id, int $target_id ): array {
		$this->calls[] = 'designate_clinic_manager';

		return array( 'ok' => true );
	}

	public function create_membership( int $clinic_id, int $wp_user_id, string $role_key, int $actor_id ): int {
		$this->calls[] = 'membership:' . $role_key;
		$this->tables_data['cpms_clinic_memberships'][] = array(
			'id'         => count( $this->tables_data['cpms_clinic_memberships'] ) + 1,
			'clinic_id'  => $clinic_id,
			'wp_user_id' => $wp_user_id,
			'role_key'   => $role_key,
		);

		return 1;
	}

	public function set_scope( int $clinic_id, int $location_id, int $organization_id ) {
		return null;
	}

	public function restore_scope( $previous ): void {
	}

	public function create_location( int $actor_id, int $clinic_id, string $name, string $slug ): int {
		$this->calls[] = 'create_location';
		$id            = max( array_map( static fn ( $row ) => (int) $row['id'], $this->tables_data['cpms_locations'] ) ) + 1;
		$this->tables_data['cpms_locations'][] = array(
			'id'         => $id,
			'clinic_id'  => $clinic_id,
			'slug'       => $slug,
			'is_primary' => 0,
			'is_active'  => 1,
		);

		return $id;
	}

	public function create_clinician( int $clinic_id, int $wp_user_id, string $full_name ): int {
		$this->calls[] = 'create_clinician';
		$this->tables_data['cpms_clinicians'][] = array(
			'id'         => 1,
			'clinic_id'  => $clinic_id,
			'wp_user_id' => $wp_user_id,
			'full_name'  => $full_name,
		);

		return 1;
	}

	public function assign_clinician_locations( int $clinician_id, array $location_ids, int $primary_id ): void {
		$this->calls[] = 'assign_clinician_locations';
	}

	public function create_bounded_schedule( int $clinic_id, int $clinician_id, int $location_id ): int {
		$this->calls[] = 'create_bounded_schedule';
		for ( $i = 0; $i < 7; $i++ ) {
			$this->tables_data['cpms_schedule'][] = array(
				'id'           => $i + 1,
				'clinic_id'    => $clinic_id,
				'clinician_id' => $clinician_id,
				'location_id'  => $location_id,
				'day_of_week'  => $i,
			);
		}

		return 7;
	}

	public function create_patient( int $actor_id, array $fields ): array {
		$this->calls[] = 'create_patient';
		$id            = count( $this->tables_data['cpms_patients'] ) + 1;
		$this->tables_data['cpms_patients'][] = array(
			'id'        => $id,
			'clinic_id' => $this->fake_clinic_id,
			'mobile'    => $fields['mobile'],
			'name'      => $fields['first_name'],
		);

		return array( 'id' => $id, 'identity_id' => 1 );
	}

	public function set_setting( int $clinic_id, string $key, $value ): void {
		$this->calls[] = 'setting:' . $key;
		$this->settings[ $key ] = $value;
	}

	public function get_setting( int $clinic_id, string $key ) {
		return $this->settings[ $key ] ?? null;
	}

	public function insert_slot( int $clinic_id, int $location_id, int $clinician_id, string $date, string $time ): int {
		$this->calls[] = 'insert_slot';
		$id            = count( $this->tables_data['cpms_schedule_slots'] ) + 1;
		$this->tables_data['cpms_schedule_slots'][] = array(
			'id'           => $id,
			'clinic_id'    => $clinic_id,
			'clinician_id' => $clinician_id,
			'slot_date'    => $date,
			'slot_time'    => $time,
		);

		return $id;
	}

	public function create_staff_appointment( int $actor_id, int $patient_id, int $clinician_id, string $date, string $time, string $reason, int $slot_id ): int {
		$this->calls[] = 'create_staff_appointment';
		$this->tables_data['cpms_appointments'][] = array(
			'id'           => 1,
			'clinic_id'    => $this->fake_clinic_id,
			'patient_id'   => $patient_id,
			'clinician_id' => $clinician_id,
		);

		return 1;
	}

	public function create_service( int $actor_id, array $input ): int {
		$this->calls[] = 'create_service';
		$this->tables_data['cpms_services'][] = array( 'id' => 1, 'clinic_id' => $this->fake_clinic_id, 'code' => $input['code'] );

		return 1;
	}

	public function walk_in( int $actor_id, int $patient_id, int $clinician_id, array $input ): int {
		$this->calls[] = 'walk_in';
		$this->tables_data['cpms_visits'][] = array(
			'id'         => 1,
			'clinic_id'  => $this->fake_clinic_id,
			'patient_id' => $patient_id,
		);

		return 1;
	}

	public function transition( int $actor_id, int $visit_id, string $event, array $extra = array() ): void {
		$this->calls[] = 'transition:' . $event;
	}

	public function add_note( int $actor_id, int $visit_id, array $note ): int {
		$this->calls[] = 'add_note';
		$id            = count( $this->tables_data['cpms_clinical_notes'] ) + 1;
		$this->tables_data['cpms_clinical_notes'][] = array(
			'id'        => $id,
			'clinic_id' => $this->fake_clinic_id,
			'visit_id'  => $visit_id,
		);

		return $id;
	}

	public function create_prescription( int $actor_id, int $visit_id, array $items ): int {
		$this->calls[] = 'create_prescription';
		$this->tables_data['cpms_prescriptions'][] = array( 'id' => 1, 'clinic_id' => $this->fake_clinic_id, 'visit_id' => $visit_id );

		return 1;
	}

	public function complete_consultation( int $actor_id, int $visit_id ): void {
		$this->calls[] = 'complete_consultation';
	}

	public function add_follow_up( int $actor_id, int $visit_id, array $input ): int {
		$this->calls[] = 'add_follow_up';
		$this->tables_data['cpms_follow_ups'][] = array( 'id' => 1, 'clinic_id' => $this->fake_clinic_id, 'visit_id' => $visit_id );

		return 1;
	}

	public function issue_invoice( int $actor_id, array $input ): int {
		$this->calls[] = 'issue_invoice';
		$this->tables_data['cpms_invoices'][] = array( 'id' => 1, 'clinic_id' => $this->fake_clinic_id, 'visit_id' => (int) $input['visit_id'] );

		return 1;
	}

	public function record_payment( int $actor_id, int $invoice_id, array $input, string $idempotency_key ): int {
		$this->calls[] = 'record_payment';
		$this->tables_data['cpms_payments'][] = array( 'id' => 1, 'clinic_id' => $this->fake_clinic_id, 'invoice_id' => $invoice_id, 'method' => $input['method'] );

		return 1;
	}

	public function audit( string $action, int $actor_id, int $clinic_id, array $meta ): void {
		$this->calls[] = 'audit:' . $action;
	}

	public function portal_links(): array {
		return array(
			'patient'    => 'https://demo.test/cpms-patient-portal/',
			'staff'      => 'https://demo.test/cpms-staff-portal/',
			'management' => 'https://demo.test/cpms-management-portal/',
			'reception'  => 'https://demo.test/cpms-staff-portal/?cpms-module=reception',
			'finance'    => 'https://demo.test/cpms-staff-portal/?cpms-module=finance',
			'doctor'     => 'https://demo.test/cpms-staff-portal/?cpms-module=doctor',
			'reports'    => 'https://demo.test/cpms-staff-portal/?cpms-module=reports',
		);
	}

	public function role_keys(): array {
		return array(
			'patient'        => 'cpms_patient',
			'doctor'         => 'cpms_doctor',
			'receptionist'   => 'cpms_secretary',
			'accountant'     => 'cpms_accountant',
			'clinic_manager' => 'cpms_manager',
		);
	}

	public function booking_shortcode( int $clinic_id ): string {
		return '[cpms_public_booking clinic_id="' . $clinic_id . '"]';
	}

	public function patients_with_mobile_outside_clinic( int $clinic_id, string $mobile ): int {
		$count = 0;
		foreach ( $this->tables_data['cpms_patients'] as $row ) {
			if ( $row['mobile'] === $mobile && (int) $row['clinic_id'] !== $clinic_id ) {
				++$count;
			}
		}

		return $count;
	}

	public function latest_live_challenge( int $clinic_id, string $mobile, string $purpose ): ?array {
		foreach ( array_reverse( $this->tables_data['cpms_otp_tokens'] ) as $row ) {
			if ( (int) $row['clinic_id'] === $clinic_id && $row['mobile'] === $mobile && $row['purpose'] === $purpose && null === $row['consumed_at'] ) {
				return $row;
			}
		}

		return null;
	}

	public function otp_pepper(): string {
		return 'test-pepper';
	}

	public function generate_otp_code( string $unused = '' ): string {
		return '123456';
	}

	public function rebind_challenge_code( int $token_id, string $code, string $pepper ): bool {
		foreach ( $this->tables_data['cpms_otp_tokens'] as $index => $row ) {
			if ( (int) $row['id'] === $token_id && null === $row['consumed_at'] && 0 === (int) $row['attempts'] ) {
				$this->tables_data['cpms_otp_tokens'][ $index ]['code_hash'] = hash_hmac( 'sha256', $code, $pepper );

				return true;
			}
		}

		return false;
	}
}

/**
 * Tiny assertion helper.
 */
final class Cpdd_Test {

	public static int $passed = 0;
	public static int $failed = 0;
	/** @var list<string> */
	public static array $failures = array();

	public static function ok( bool $condition, string $message ): void {
		if ( $condition ) {
			++self::$passed;

			return;
		}
		++self::$failed;
		self::$failures[] = $message;
		echo 'FAIL: ' . $message . PHP_EOL;
	}

	public static function equals( $expected, $actual, string $message ): void {
		self::ok( $expected === $actual, $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
	}

	/**
	 * @param callable():void $fn Block expected to throw.
	 */
	public static function refuses( callable $fn, string $expected_code, string $message ): void {
		try {
			$fn();
		} catch ( CpmsDemo\Exception $e ) {
			self::equals( $expected_code, $e->demo_code(), $message );

			return;
		} catch ( Throwable $e ) {
			self::ok( false, $message . ' (threw ' . get_class( $e ) . ': ' . $e->getMessage() . ')' );

			return;
		}
		self::ok( false, $message . ' (no exception)' );
	}

	public static function summary(): int {
		echo PHP_EOL . 'PASS=' . self::$passed . ' FAIL=' . self::$failed . PHP_EOL;

		return self::$failed > 0 ? 1 : 0;
	}
}

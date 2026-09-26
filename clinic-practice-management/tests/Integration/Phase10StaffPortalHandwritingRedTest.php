<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;

/** Portal handwriting contract: the request reaches the new product route. */
final class Phase10StaffPortalHandwritingRedTest extends WP_UnitTestCase {
	public function test_visit_document_route_is_reached(): void {
		$request = new WP_REST_Request( 'GET', '/clinic/v1/doctor/portal/visits/1/handwriting' );
		$response = rest_do_request( $request );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertNotSame( 'rest_no_route', $data['code'] ?? null, 'Product RED: the portal handwriting boundary is absent, not a fixture or bootstrap failure.' );
	}

	public function test_shared_engine_is_not_duplicated_in_admin_page(): void {
		$root = dirname( __DIR__, 2 );
		self::assertFileExists( $root . '/assets/js/doctor-handwriting.js', 'Product RED: shared handwriting engine asset does not exist.' );
		self::assertFileExists( $root . '/assets/css/doctor-handwriting.css', 'Product RED: scoped handwriting stylesheet does not exist.' );
		$admin = (string) file_get_contents( $root . '/src/Admin/DoctorHandwritingPage.php' );
		self::assertStringNotContainsString( 'var BACKOFF = [5000, 30000', $admin, 'Product RED: admin still contains the inline engine.' );
	}

	/** @return array<string, string> */
	private function routes(): array {
		$base = '/clinic/v1/doctor/portal/visits/1/handwriting';
		return [
			'GET list' => $base,
			'POST create' => $base,
			'POST page' => $base . '/documents/1/pages',
			'GET page' => $base . '/pages/1',
			'PUT save' => $base . '/pages/1',
		];
	}

	public function test_every_route_drops_forged_authority_keys(): void {
		$routes = rest_get_server()->get_routes();
		foreach ( $routes as $pattern => $handlers ) {
			if ( ! str_contains( $pattern, '/doctor/portal/visits/' ) || ! str_contains( $pattern, '/handwriting' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
					continue;
				}
				foreach ( [ 'patient_id', 'clinician_id', 'clinic_id', 'location_id', 'visit_id' ] as $key ) {
					self::assertArrayHasKey( $key, $handler['args'] ?? [], $pattern . ' must discard ' . $key );
					self::assertNull( $handler['args'][ $key ]['sanitize_callback']( 'forged' ) );
				}
			}
		}
	}

	public function test_every_route_enforces_nonce_and_denies_secretary_and_patient(): void {
		foreach ( [ 'cpms_secretary', 'cpms_patient' ] as $role ) {
			$user_id = (int) wp_create_user( 'hw_portal_' . $role . wp_rand( 10000, 99999 ), 'test-password', 'hw_' . wp_rand( 10000, 99999 ) . '@example.invalid' );
			get_userdata( $user_id )->set_role( $role );
			wp_set_current_user( $user_id );
			foreach ( $this->routes() as $name => $route ) {
				[ $method ] = explode( ' ', $name );
				$request = new WP_REST_Request( $method, $route );
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
				$response = rest_do_request( $request );
				self::assertSame( 403, $response->get_status(), $role . ' ' . $name );
				self::assertStringNotContainsString( 'stroke_data', (string) wp_json_encode( $response->get_data() ), 'No handwriting bytes on denied role.' );
			}
		}
		$request = new WP_REST_Request( 'GET', $this->routes()['GET list'] );
		$request->set_header( 'X-WP-Nonce', 'invalid-nonce-0000' );
		self::assertSame( 'CLINIC_INVALID_NONCE', rest_do_request( $request )->get_data()['code'] ?? '' );
	}

	/** @return array{clinic:int, location:int, doctor:int, visit:int, patient:int, clinician:int} */
	private function stage(): array {
		$db = \ClinicCore\Bootstrap\App::db();
		\ClinicCore\Bootstrap\App::migrations()->migrate();
		\ClinicCore\Application\Scope\ScopeContext::clear();
		\ClinicCore\Bootstrap\App::resetScope();
		$now = $db->nowUtcSql();
		$tag = bin2hex( random_bytes( 4 ) );
		self::assertTrue( $db->insert( 'cpms_organizations', [ 'name' => 'HW org', 'slug' => 'hw-org-' . $tag, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] ) );
		global $wpdb;
		$org = (int) $wpdb->insert_id;
		self::assertTrue( $db->insert( 'cpms_clinics', [ 'organization_id' => $org, 'name' => 'HW clinic', 'slug' => 'hw-clinic-' . $tag, 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now ] ) );
		$clinic = (int) $wpdb->insert_id;
		self::assertTrue( $db->insert( 'cpms_locations', [ 'clinic_id' => $clinic, 'name' => 'HW loc', 'slug' => 'hw-loc-' . $tag, 'timezone' => 'Asia/Tehran', 'is_primary' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ] ) );
		$location = (int) $wpdb->insert_id;
		$doctor = (int) wp_create_user( 'hw-doc-' . $tag, 'test-pass', 'doc-' . $tag . '@example.invalid' );
		get_userdata( $doctor )->set_role( 'cpms_doctor' );
		cpms_test_seed_membership( $doctor, $clinic, 'cpms_doctor' );
		self::assertTrue( $db->insert( 'cpms_clinicians', [ 'clinic_id' => $clinic, 'full_name' => 'Dr HW', 'wp_user_id' => $doctor, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ] ) );
		$clinician = (int) $wpdb->insert_id;
		self::assertTrue( $db->insert( 'cpms_patients', [ 'clinic_id' => $clinic, 'mrn' => 'HW-' . $tag, 'first_name' => 'HW', 'last_name' => 'Patient', 'mobile' => '0912' . sprintf( '%07d', random_int( 1000000, 9999999 ) ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] ) );
		$patient = (int) $wpdb->insert_id;
		self::assertTrue( $db->insert( 'cpms_visits', [ 'clinic_id' => $clinic, 'location_id' => $location, 'clinician_id' => $clinician, 'patient_id' => $patient, 'source' => 'walk_in', 'status' => 'in_consultation', 'visit_date' => gmdate( 'Y-m-d' ), 'check_in_at' => $now, 'waiting_since' => $now, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ] ) );
		$visit = (int) $wpdb->insert_id;
		self::assertGreaterThan( 0, $visit );
		wp_set_current_user( $doctor );
		return compact( 'clinic', 'location', 'doctor', 'visit', 'patient', 'clinician' );
	}

	/** @param array<string,int> $fx */
	private function call_portal( string $method, string $route, array $fx, ?int $location = null, array $body = [], ?string $key = null ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'X-CPMS-Clinic-Id', (string) $fx['clinic'] );
		if ( null !== $location ) {
			$request->set_header( 'X-CPMS-Location-Id', (string) $location );
		}
		if ( null !== $key ) {
			$request->set_header( 'Idempotency-Key', $key );
		}
		foreach ( $body as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return rest_do_request( $request );
	}

	public function test_portal_location_rule_and_authorized_visit_create_audited_once(): void {
		$fx = $this->stage();
		$path = '/clinic/v1/doctor/portal/visits/' . $fx['visit'] . '/handwriting';
		$db = \ClinicCore\Bootstrap\App::db();
		$db->update( 'cpms_locations', [ 'is_active' => 0 ], [ 'id' => $fx['location'] ] );
		$zero = $this->call_portal( 'GET', $path, $fx );
		self::assertSame( 403, $zero->get_status() );
		self::assertSame( 'location', $zero->get_data()['data']['reason'] ?? '' );
		$db->update( 'cpms_locations', [ 'is_active' => 1 ], [ 'id' => $fx['location'] ] );
		$one = $this->call_portal( 'GET', $path, $fx );
		self::assertSame( 200, $one->get_status(), 'Exactly one Location auto-resolves.' );
		$now = $db->nowUtcSql();
		self::assertTrue( $db->insert( 'cpms_locations', [ 'clinic_id' => $fx['clinic'], 'name' => 'Second', 'slug' => 'second-' . bin2hex( random_bytes( 4 ) ), 'timezone' => 'Asia/Tehran', 'is_primary' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ] ) );
		global $wpdb;
		$other_location = (int) $wpdb->insert_id;
		$many = $this->call_portal( 'GET', $path, $fx );
		self::assertSame( 400, $many->get_status() );
		self::assertSame( 'location_required', $many->get_data()['data']['reason'] ?? '' );
		self::assertStringNotContainsString( (string) $other_location, (string) wp_json_encode( $many->get_data()['message'] ?? '' ) );
		$wrong = $this->call_portal( 'GET', $path, $fx, $other_location );
		self::assertSame( 404, $wrong->get_status(), 'Visit is bound to its original Location.' );
		$db->update( 'cpms_locations', [ 'is_active' => 0 ], [ 'id' => $other_location ] );
		$inactive = $this->call_portal( 'GET', $path, $fx, $other_location );
		self::assertSame( 403, $inactive->get_status() );
		self::assertSame( 'location', $inactive->get_data()['data']['reason'] ?? '' );
		$created = $this->call_portal( 'POST', $path, $fx, $fx['location'], [ 'patient_id' => 999999, 'clinic_id' => 999999, 'clinician_id' => 999999, 'visit_id' => 999999, 'location_id' => 999999 ] );
		self::assertSame( 201, $created->get_status() );
		$doc = $created->get_data()['data'];
		self::assertSame( $fx['patient'], (int) $doc['patient_id'] );
		self::assertSame( $fx['visit'], (int) $doc['visit_id'] );
		$count = $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE action = %s AND resource_id = %d', [ 'HW_DOC_CREATE', (int) $doc['id'] ] );
		self::assertSame( 1, (int) $count );
	}

	public function test_page_selector_is_rebound_to_visit_and_clinic_on_each_call_with_one_save_audit(): void {
		$fx = $this->stage();
		$path = '/clinic/v1/doctor/portal/visits/' . $fx['visit'] . '/handwriting';
		$db = \ClinicCore\Bootstrap\App::db();
		$created = $this->call_portal( 'POST', $path, $fx, $fx['location'] );
		self::assertSame( 201, $created->get_status() );
		$doc = $created->get_data()['data'];
		$doc_id = (int) $doc['id'];
		$page_id = (int) $doc['pages'][0]['id'];
		$page_path = $path . '/pages/' . $page_id;
		self::assertSame( 200, $this->call_portal( 'GET', $page_path, $fx, $fx['location'] )->get_status() );
		$strokes = base64_encode( (string) wp_json_encode( [] ) );
		$body = [ 'client_revision' => 1, 'stroke_data' => $strokes ];
		$key = '00000000-0000-4000-8000-' . bin2hex( random_bytes( 6 ) );
		$saved = $this->call_portal( 'PUT', $page_path, $fx, $fx['location'], $body, $key );
		self::assertSame( 200, $saved->get_status() );
		$replay = $this->call_portal( 'PUT', $page_path, $fx, $fx['location'], $body, $key );
		self::assertSame( $saved->get_data(), $replay->get_data() );
		$conflict = $this->call_portal( 'PUT', $page_path, $fx, $fx['location'], $body, '00000000-0000-4000-8000-' . bin2hex( random_bytes( 6 ) ) );
		self::assertSame( 409, $conflict->get_status() );
		self::assertSame( 'CLINIC_CONFLICT', $conflict->get_data()['code'] ?? '' );
		$count = $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE action = %s AND resource_id = %d', [ 'HW_PAGE_SAVE', $page_id ] );
		self::assertSame( 1, (int) $count, 'Replay and conflict cannot log success.' );
		$now = $db->nowUtcSql();
		$org = $db->fetchValue( 'SELECT organization_id FROM ' . $db->table( 'cpms_clinics' ) . ' WHERE id = %d', [ $fx['clinic'] ] );
		self::assertTrue( $db->insert( 'cpms_clinics', [ 'organization_id' => (int) $org, 'name' => 'Foreign HW clinic', 'slug' => 'hw-foreign-' . bin2hex( random_bytes( 4 ) ), 'timezone' => 'Asia/Tehran', 'created_at' => $now, 'updated_at' => $now ] ) );
		global $wpdb;
		$foreign_clinic = (int) $wpdb->insert_id;
		self::assertSame( 1, $db->update( 'cpms_handwriting_documents', [ 'clinic_id' => $foreign_clinic ], [ 'id' => $doc_id ] ) );
		self::assertSame( 404, $this->call_portal( 'GET', $page_path, $fx, $fx['location'] )->get_status() );
		self::assertSame( 404, $this->call_portal( 'PUT', $page_path, $fx, $fx['location'], [ 'client_revision' => 2, 'stroke_data' => $strokes ], '00000000-0000-4000-8000-' . bin2hex( random_bytes( 6 ) ) )->get_status() );
		self::assertSame( 404, $this->call_portal( 'POST', $path . '/documents/' . $doc_id . '/pages', $fx, $fx['location'] )->get_status() );
		$denials = $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE action = %s AND resource_id = %d', [ 'FORBIDDEN_ACCESS_ATTEMPT', $fx['visit'] ] );
		self::assertGreaterThanOrEqual( 3, (int) $denials );
	}

	public function test_active_clinician_and_trusted_visit_authority_are_required(): void {
		$fx = $this->stage();
		$path = '/clinic/v1/doctor/portal/visits/' . $fx['visit'] . '/handwriting';
		$db = \ClinicCore\Bootstrap\App::db();
		$now = $db->nowUtcSql();
		$other = (int) wp_create_user( 'hw-other-' . bin2hex( random_bytes( 4 ) ), 'test-pass', 'other-' . bin2hex( random_bytes( 4 ) ) . '@example.invalid' );
		get_userdata( $other )->set_role( 'cpms_doctor' );
		cpms_test_seed_membership( $other, $fx['clinic'], 'cpms_doctor' );
		self::assertTrue( $db->insert( 'cpms_clinicians', [ 'clinic_id' => $fx['clinic'], 'full_name' => 'Other doctor', 'wp_user_id' => $other, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ] ) );
		global $wpdb;
		$other_clinician = (int) $wpdb->insert_id;
		self::assertSame( 1, $db->update( 'cpms_visits', [ 'clinician_id' => $other_clinician ], [ 'id' => $fx['visit'] ] ) );
		$denied = $this->call_portal( 'GET', $path, $fx, $fx['location'] );
		self::assertSame( 404, $denied->get_status() );
		self::assertSame( 'CLINIC_NOT_FOUND', $denied->get_data()['code'] ?? '' );
		$count = $db->fetchValue( 'SELECT COUNT(*) FROM ' . $db->table( 'cpms_audit_logs' ) . ' WHERE action = %s AND resource_id = %d', [ 'FORBIDDEN_ACCESS_ATTEMPT', $fx['visit'] ] );
		self::assertSame( 1, (int) $count );
		self::assertSame( 1, $db->update( 'cpms_clinicians', [ 'is_active' => 0 ], [ 'id' => $fx['clinician'] ] ) );
		self::assertSame( 403, $this->call_portal( 'POST', $path, $fx, $fx['location'] )->get_status() );
	}

}

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

}

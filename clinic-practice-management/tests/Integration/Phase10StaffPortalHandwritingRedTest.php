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
}

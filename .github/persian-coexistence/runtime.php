<?php
// Out-of-tree PHP identity, without loading WordPress merely for JSON helpers.
// Version components are integers; SAPI values are literal JSON, never input.
header( 'Content-Type: application/json' );
if ( PHP_SAPI !== 'apache2handler' && PHP_SAPI !== 'cli' ) {
	http_response_code( 503 );
	echo '{"error":"unsupported probe SAPI"}';
	return;
}
printf( '{"php_version":"%d.%d.%d",', PHP_MAJOR_VERSION, PHP_MINOR_VERSION, PHP_RELEASE_VERSION );
if ( PHP_SAPI === 'apache2handler' ) {
	echo '"php_sapi":"apache2handler"}';
} else {
	echo '"php_sapi":"cli"}';
}

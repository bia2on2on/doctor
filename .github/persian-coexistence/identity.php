<?php

declare(strict_types=1);

// Test-only local endpoint. It is served by the same Apache/mod_php pool as
// WordPress but does not bootstrap WordPress or load any plugin.
header( 'Content-Type: application/json; charset=utf-8' );

if ( PHP_SAPI !== 'apache2handler'
    || ! function_exists( 'posix_geteuid' )
    || ! function_exists( 'posix_getegid' )
    || ! function_exists( 'posix_getpwuid' )
    || ! function_exists( 'posix_getgrgid' )
) {
    http_response_code( 503 );
    echo '{"error":"effective process identity unavailable"}';
    return;
}

$cpms_identity_uid   = posix_geteuid();
$cpms_identity_gid   = posix_getegid();
$cpms_identity_user  = posix_getpwuid( $cpms_identity_uid );
$cpms_identity_group = posix_getgrgid( $cpms_identity_gid );

$cpms_identity_payload = [
    'effective_uid'   => $cpms_identity_uid,
    'effective_user'  => is_array( $cpms_identity_user ) ? (string) ( $cpms_identity_user['name'] ?? '' ) : '',
    'effective_gid'   => $cpms_identity_gid,
    'effective_group' => is_array( $cpms_identity_group ) ? (string) ( $cpms_identity_group['name'] ?? '' ) : '',
    'php_sapi'        => PHP_SAPI,
];

// This out-of-WordPress endpoint must not bootstrap WordPress to load wp_json_encode.
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the standalone endpoint contract.
echo json_encode( $cpms_identity_payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

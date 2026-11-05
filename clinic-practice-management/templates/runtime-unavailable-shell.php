<?php
/**
 * Standalone neutral fallback shell when runtime is paused due to pending
 * schema migrations on an unsupported database engine.
 *
 * Contains zero patient, clinic, or infrastructure details.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use ClinicCore\Bootstrap\RuntimeSchemaGate;

$cpms_charset = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'charset' ) : 'UTF-8';
if ( '' === $cpms_charset ) {
    $cpms_charset = 'UTF-8';
}
?><!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-runtime-gate="blocked">
<head>
<meta charset="<?php echo esc_attr( $cpms_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( RuntimeSchemaGate::NEUTRAL_MESSAGE ); ?></title>
</head>
<body class="cpms-runtime-unavailable-body">
<main role="main" data-role="cpms-runtime-unavailable">
<p><?php echo esc_html( RuntimeSchemaGate::NEUTRAL_MESSAGE ); ?></p>
</main>
</body>
</html>

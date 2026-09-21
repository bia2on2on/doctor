<?php
/**
 * TEST FIXTURE theme Beta — header (proof infrastructure only).
 * Emits the greppable positive-control marker when (and only when) the theme
 * actually renders — proving theme-marker absence elsewhere is not vacuous.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php wp_title('|', true, 'right'); bloginfo('name'); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class('proof-beta'); ?>>
<div class="proof-beta-banner">CPMS-PROOF-THEME-BETA-HEADER</div>

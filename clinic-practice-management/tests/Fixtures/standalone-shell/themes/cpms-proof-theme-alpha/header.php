<?php
/**
 * TEST FIXTURE theme Alpha — header (proof infrastructure only).
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
<body <?php body_class('proof-alpha'); ?>>
<header class="proof-alpha-header">CPMS-PROOF-THEME-ALPHA-HEADER</header>
<nav class="proof-alpha-nav"><ul><li>Alpha Nav Item 1</li><li>Alpha Nav Item 2</li></ul></nav>

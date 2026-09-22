<?php
/**
 * Production standalone Patient Portal shell template (Phase 9 Slice 3).
 *
 * Owns the full HTML document. Deliberately does NOT call:
 *   get_header() / get_footer() / wp_head() / wp_footer() / wp_body_open()
 * so the active Theme never controls header/footer/layout/navigation/shell.
 *
 * WordPress lifecycle already ran (query, auth, cookies). Session, current user,
 * and `wp_rest` nonces remain available. Assets are printed explicitly and only
 * for this surface (PatientPortalShell::enqueue_for_portal).
 *
 * @package ClinicCore
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use ClinicCore\Admin\PatientPortalPage;
use ClinicCore\Frontend\PatientPortalShell;

// Fail-safe: if loaded outside the normal request lifecycle, exit quietly.
if ( ! function_exists( 'wp_get_current_user' ) ) {
	return;
}

// Ensure portal-scoped handles are registered/enqueued even if template_include
// runs before wp_enqueue_scripts in some test paths.
PatientPortalShell::register_handles();
PatientPortalShell::enqueue_for_portal();

$cpms_user       = wp_get_current_user();
$cpms_logged_in  = ( $cpms_user instanceof WP_User && (int) $cpms_user->ID > 0 );
$cpms_is_patient = $cpms_logged_in && PatientPortalPage::isPatientOnly( $cpms_user );
$cpms_login_name = $cpms_logged_in ? (string) $cpms_user->display_name : '';
if ( $cpms_login_name === '' && $cpms_logged_in ) {
	$cpms_login_name = (string) $cpms_user->user_login;
}

$cpms_portal_url = PatientPortalShell::portal_url();
$cpms_login_url  = wp_login_url( $cpms_portal_url );
$cpms_logout_url = $cpms_logged_in ? wp_logout_url( $cpms_portal_url ) : '';

$cpms_charset = (string) get_bloginfo( 'charset' );
if ( $cpms_charset === '' ) {
	$cpms_charset = 'UTF-8';
}
$cpms_site_name = (string) get_bloginfo( 'name' );

// Capture body content without theme chrome.
ob_start();
if ( $cpms_is_patient ) {
	// Existing Slice 1/2 portal surface (appointments, cancel, notifications, config).
	PatientPortalPage::render();
} elseif ( $cpms_logged_in ) {
	// Staff / multi-role / non-patient: no Patient data, no Patient authority.
	echo '<section class="cpms-patient-portal-shell__notice" role="status" data-role="portal-access-denied">';
	echo '<h2>دسترسی پورتال بیمار</h2>';
	echo '<p>این پورتال فقط برای بیماران است. حساب کاربری شما نقش بیمارِ خالص ندارد.</p>';
	echo '<p><a class="cpms-pp-btn cpms-pp-btn--primary" href="' . esc_url( admin_url() ) . '">بازگشت به پیشخوان</a></p>';
	echo '</section>';
} else {
	// Unauthenticated: no Patient queries/PHI — safe Persian login state.
	echo '<section class="cpms-patient-portal-shell__notice" role="status" data-role="portal-login">';
	echo '<h2>ورود به پورتال بیمار</h2>';
	echo '<p>برای مشاهده نوبت‌ها و اعلان‌ها وارد شوید. پس از ورود به همین پورتال بازمی‌گردید.</p>';
	echo '<p><a class="cpms-pp-btn cpms-pp-btn--primary" href="' . esc_url( $cpms_login_url ) . '">ورود</a></p>';
	echo '</section>';
}
$cpms_body_html = (string) ob_get_clean();

// Collect only the portal-scoped style/script tags (no theme wp_head/wp_footer).
ob_start();
wp_print_styles( [ PatientPortalShell::CSS_HANDLE ] );
$cpms_styles_html = (string) ob_get_clean();

ob_start();
wp_print_scripts( [ PatientPortalShell::JS_HANDLE ] );
$cpms_scripts_html = (string) ob_get_clean();

?><!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-patient-portal="shell" data-cpms-portal="patient">
<head>
<meta charset="<?php echo esc_attr( $cpms_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( 'پورتال بیمار' . ( $cpms_site_name !== '' ? ' — ' . $cpms_site_name : '' ) ); ?></title>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style tags from wp_print_styles (registered local handles only).
echo $cpms_styles_html;
?>
</head>
<body class="cpms-patient-portal-shell-body">
<div
	id="cpms-patient-portal-shell"
	class="cpms-patient-portal-shell"
	data-cpms-patient-portal-shell="v1"
	data-shell-contract="patient-v1"
	<?php echo $cpms_is_patient ? 'data-shell-user="patient"' : ( $cpms_logged_in ? 'data-shell-user="non-patient"' : 'data-shell-user="anonymous"' ); ?>
>
	<header class="cpms-patient-portal-shell__header" role="banner" data-role="portal-header">
		<div class="cpms-patient-portal-shell__brand">
			<span class="cpms-patient-portal-shell__mark" aria-hidden="true">CPMS</span>
			<div class="cpms-patient-portal-shell__titles">
				<p class="cpms-patient-portal-shell__product">پورتال بیمار</p>
				<?php if ( $cpms_is_patient ) : ?>
					<p class="cpms-patient-portal-shell__title" data-role="portal-header-title">نوبت‌های من</p>
				<?php else : ?>
					<h1 class="cpms-patient-portal-shell__title">پورتال بیمار</h1>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $cpms_is_patient ) : ?>
			<div class="cpms-patient-portal-shell__session">
				<span class="cpms-patient-portal-shell__who" data-role="portal-user"><?php echo esc_html( $cpms_login_name ); ?></span>
				<a class="cpms-pp-btn cpms-pp-btn--ghost" data-role="portal-logout" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
			</div>
		<?php elseif ( $cpms_logged_in ) : ?>
			<div class="cpms-patient-portal-shell__session">
				<a class="cpms-pp-btn cpms-pp-btn--ghost" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( $cpms_is_patient ) : ?>
	<nav class="cpms-patient-portal-shell__nav" role="navigation" aria-label="ناوبری پورتال بیمار" data-role="patient-nav">
		<ul class="cpms-patient-portal-shell__nav-list">
			<li><a class="is-active" href="<?php echo esc_url( $cpms_portal_url ); ?>#appointments" data-role="nav-appointments" aria-current="page">نوبت‌های من</a></li>
			<li><a href="<?php echo esc_url( $cpms_portal_url ); ?>#profile" data-role="nav-profile">پروفایل</a></li>
		</ul>
	</nav>
	<?php endif; ?>

	<main class="cpms-patient-portal-shell__main" role="main" data-role="portal-main" id="cpms-patient-portal-main">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup produced by PatientPortalPage (escaped internally) or static safe notices above.
		echo $cpms_body_html;
		?>
	</main>

	<footer class="cpms-patient-portal-shell__footer" role="contentinfo" data-role="portal-footer">
		<p class="cpms-patient-portal-shell__footer-text">پورتال بیمار · CPMS</p>
	</footer>
</div>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags from wp_print_scripts (registered local handles only).
echo $cpms_scripts_html;
?>
</body>
</html>

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
 * for this surface (PatientPortalShell::enqueueForPortal).
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
PatientPortalShell::registerHandles();
PatientPortalShell::enqueueForPortal();

$user       = wp_get_current_user();
$logged_in  = ( $user instanceof WP_User && (int) $user->ID > 0 );
$is_patient = $logged_in && PatientPortalPage::isPatientOnly( $user );
$login_name = $logged_in ? (string) $user->display_name : '';
if ( $login_name === '' && $logged_in ) {
	$login_name = (string) $user->user_login;
}

$portal_url = PatientPortalShell::portalUrl();
$login_url  = wp_login_url( $portal_url );
$logout_url = $logged_in ? wp_logout_url( $portal_url ) : '';

$charset = (string) get_bloginfo( 'charset' );
if ( $charset === '' ) {
	$charset = 'UTF-8';
}
$site_name = (string) get_bloginfo( 'name' );

// Capture body content without theme chrome.
ob_start();
if ( $is_patient ) {
	// Existing Slice 1/2 portal surface (appointments, cancel, notifications, config).
	PatientPortalPage::render();
} elseif ( $logged_in ) {
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
	echo '<p><a class="cpms-pp-btn cpms-pp-btn--primary" href="' . esc_url( $login_url ) . '">ورود</a></p>';
	echo '</section>';
}
$body_html = (string) ob_get_clean();

// Collect only the portal-scoped style/script tags (no theme wp_head/wp_footer).
ob_start();
wp_print_styles( [ PatientPortalShell::CSS_HANDLE ] );
$styles_html = (string) ob_get_clean();

ob_start();
wp_print_scripts( [ PatientPortalShell::JS_HANDLE ] );
$scripts_html = (string) ob_get_clean();

?><!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-patient-portal="shell" data-cpms-portal="patient">
<head>
<meta charset="<?php echo esc_attr( $charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( 'پورتال بیمار' . ( $site_name !== '' ? ' — ' . $site_name : '' ) ); ?></title>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style tags from wp_print_styles (registered local handles only).
echo $styles_html;
?>
</head>
<body class="cpms-patient-portal-shell-body">
<div
	id="cpms-patient-portal-shell"
	class="cpms-patient-portal-shell"
	data-cpms-patient-portal-shell="v1"
	data-shell-contract="patient-v1"
	<?php echo $is_patient ? 'data-shell-user="patient"' : ( $logged_in ? 'data-shell-user="non-patient"' : 'data-shell-user="anonymous"' ); ?>
>
	<header class="cpms-patient-portal-shell__header" role="banner" data-role="portal-header">
		<div class="cpms-patient-portal-shell__brand">
			<span class="cpms-patient-portal-shell__mark" aria-hidden="true">CPMS</span>
			<div class="cpms-patient-portal-shell__titles">
				<p class="cpms-patient-portal-shell__product">پورتال بیمار</p>
				<?php if ( $is_patient ) : ?>
					<p class="cpms-patient-portal-shell__title" data-role="portal-header-title">نوبت‌های من</p>
				<?php else : ?>
					<h1 class="cpms-patient-portal-shell__title">پورتال بیمار</h1>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $is_patient ) : ?>
			<div class="cpms-patient-portal-shell__session">
				<span class="cpms-patient-portal-shell__who" data-role="portal-user"><?php echo esc_html( $login_name ); ?></span>
				<a class="cpms-pp-btn cpms-pp-btn--ghost" data-role="portal-logout" href="<?php echo esc_url( $logout_url ); ?>">خروج</a>
			</div>
		<?php elseif ( $logged_in ) : ?>
			<div class="cpms-patient-portal-shell__session">
				<a class="cpms-pp-btn cpms-pp-btn--ghost" href="<?php echo esc_url( $logout_url ); ?>">خروج</a>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( $is_patient ) : ?>
	<nav class="cpms-patient-portal-shell__nav" role="navigation" aria-label="ناوبری پورتال بیمار" data-role="patient-nav">
		<ul class="cpms-patient-portal-shell__nav-list">
			<li><a class="is-active" href="<?php echo esc_url( $portal_url ); ?>" aria-current="page">نوبت‌های من</a></li>
		</ul>
	</nav>
	<?php endif; ?>

	<main class="cpms-patient-portal-shell__main" role="main" data-role="portal-main" id="cpms-patient-portal-main">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup produced by PatientPortalPage (escaped internally) or static safe notices above.
		echo $body_html;
		?>
	</main>

	<footer class="cpms-patient-portal-shell__footer" role="contentinfo" data-role="portal-footer">
		<p class="cpms-patient-portal-shell__footer-text">پورتال بیمار · CPMS</p>
	</footer>
</div>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags from wp_print_scripts (registered local handles only).
echo $scripts_html;
?>
</body>
</html>

<?php

/**
 * Production standalone SHARED STAFF PORTAL shell template (Phase 10).
 *
 * Canonical operational container for authorized non-patient operational users.
 * Owns the full HTML document. Deliberately does NOT call:
 *   get_header() / get_footer() / wp_head() / wp_footer() / wp_body_open()
 * so the active Theme never controls header/footer/layout/navigation/shell.
 *
 * Rendering model (owner contract): HYBRID, not a full SPA.
 *   - shell, navigation and main page structure: SERVER-RENDERED here;
 *   - daily operational interactions: REST/AJAX, provided by the mounted module.
 *
 * Only the delivered doctor module exists in this slice. It is mounted by
 * including the Doctor Portal template in embed mode ($cpms_staff_embed): no
 * markup, data-role contract, runtime config or REST interaction of the doctor
 * module is rewritten, and the doctor assets are reused (not duplicated).
 *
 * Visitors without an eligible module receive a staff-level notice only: no
 * doctor markup, no module assets and no REST nonce payload.
 *
 * The legacy Doctor Portal URL sends eligible doctors here with ONE
 * server-side compatibility redirect (DoctorPortalShell), so there is one
 * shared operational container and one visual shell; no redirect loop.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use ClinicCore\Frontend\StaffPortalShell;

if ( ! function_exists( 'wp_get_current_user' ) ) {
    return;
}

StaffPortalShell::register_handles();
StaffPortalShell::enqueue_for_portal();

$cpms_user       = wp_get_current_user();
$cpms_logged_in  = $cpms_user instanceof WP_User && (int) $cpms_user->ID > 0;
$cpms_modules    = StaffPortalShell::eligible_modules( $cpms_logged_in ? (int) $cpms_user->ID : 0 );
$cpms_doctor_mod = in_array( StaffPortalShell::MODULE_DOCTOR, array_column( $cpms_modules, 'id' ), true );

$cpms_login_name = $cpms_logged_in ? (string) $cpms_user->display_name : '';
if ( '' === $cpms_login_name && $cpms_logged_in ) {
    $cpms_login_name = (string) $cpms_user->user_login;
}

$cpms_portal_url = StaffPortalShell::portal_url();
$cpms_login_url  = wp_login_url( $cpms_portal_url );
$cpms_logout_url = $cpms_logged_in ? wp_logout_url( $cpms_portal_url ) : '';

$cpms_charset = (string) get_bloginfo( 'charset' );
if ( '' === $cpms_charset ) {
    $cpms_charset = 'UTF-8';
}
$cpms_site_name = (string) get_bloginfo( 'name' );

$cpms_styles_html  = '';
$cpms_scripts_html = '';
if ( $cpms_doctor_mod ) {
    // REUSE of the delivered doctor assets: same handles, printed once.
    ob_start();
    wp_print_styles( array( StaffPortalShell::DOCTOR_CSS_HANDLE ) );
    $cpms_styles_html = (string) ob_get_clean();

    ob_start();
    wp_print_scripts( array( StaffPortalShell::DOCTOR_JS_HANDLE ) );
    $cpms_scripts_html = (string) ob_get_clean();
}

$cpms_header_title = $cpms_doctor_mod ? 'امروز پزشک — صف زنده' : 'پورتال کارکنان';

// The doctor stylesheet is scoped to these existing classes; carrying them when
// the doctor module is mounted reuses the delivered design without copying CSS.
$cpms_wrap_class = $cpms_doctor_mod ? 'cpms-staff-portal-shell cpms-doctor-portal-shell' : 'cpms-staff-portal-shell';
$cpms_body_class = $cpms_doctor_mod ? 'cpms-staff-portal-shell-body cpms-doctor-portal-shell-body' : 'cpms-staff-portal-shell-body';
$cpms_shell_user = 'anonymous';
if ( $cpms_doctor_mod ) {
    $cpms_shell_user = 'doctor';
} elseif ( $cpms_logged_in ) {
    $cpms_shell_user = 'non-doctor';
}
$cpms_title_full = '' !== $cpms_site_name ? $cpms_header_title . ' — ' . $cpms_site_name : $cpms_header_title;

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-staff-portal-shell="v1" data-cpms-portal="staff"<?php echo $cpms_doctor_mod ? ' data-cpms-doctor-portal-shell="v1"' : ''; ?>>
<head>
<meta charset="<?php echo esc_attr( $cpms_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $cpms_title_full ); ?></title>
<style>
:root { --cpms-primary: #0f5c56; --cpms-bg: #f3f6f8; --cpms-text: #1b2830; --cpms-muted: #5c6b76; --cpms-border: #e1e8ee; }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
body.cpms-staff-portal-shell-body { margin: 0; font-family: Tahoma, Vazirmatn, sans-serif; background: var(--cpms-bg); color: var(--cpms-text); line-height: 1.45; direction: rtl; }
.cpms-doc-selectors:not(:has(.cpms-doc-selector:not([hidden]))) { display: none; }
.cpms-staff-portal-shell { max-width: 1320px; margin: 0 auto; padding: 10px; }
.cpms-staff-portal-shell__nav { margin: 0 0 12px; }
.cpms-staff-portal-shell__nav-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }
.cpms-staff-portal-shell__nav-link { display: inline-flex; align-items: center; min-height: 40px; padding: 6px 12px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: var(--cpms-text); text-decoration: none; font-size: 0.9rem; }
.cpms-staff-portal-shell__nav-link[aria-current="page"] { background: var(--cpms-primary); border-color: var(--cpms-primary); color: #fff; }
.cpms-staff-portal-shell__notice { background: #fff; border: 1px solid var(--cpms-border); border-radius: 8px; padding: 16px; }
</style>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style tags from wp_print_styles (registered local handles only).
echo $cpms_styles_html;
?>
</head>
<body class="<?php echo esc_attr( $cpms_body_class ); ?>">
<div
    id="cpms-staff-portal-shell"
    class="<?php echo esc_attr( $cpms_wrap_class ); ?>"
    data-cpms-staff-portal-shell="v1"
    data-cpms-portal="staff"
    data-shell-contract="staff-v1"
    data-shell-user="<?php echo esc_attr( $cpms_shell_user ); ?>"
>
    <header class="cpms-doctor-portal-shell__header cpms-staff-portal-shell__header" role="banner" data-role="portal-header">
        <div class="cpms-doctor-portal-shell__brand">
            <span class="cpms-doctor-portal-shell__mark" aria-hidden="true">CPMS</span>
            <div class="cpms-doctor-portal-shell__titles">
                <p class="cpms-doctor-portal-shell__product">پورتال کارکنان</p>
                <h1 class="cpms-doctor-portal-shell__title" data-role="portal-header-title"><?php echo esc_html( $cpms_header_title ); ?></h1>
            </div>
        </div>
        <?php if ( $cpms_logged_in ) : ?>
            <div class="cpms-doctor-portal-shell__session">
                <span class="cpms-doctor-portal-shell__who" data-role="portal-user"><?php echo esc_html( $cpms_login_name ); ?></span>
                <a class="cpms-doc-btn cpms-doc-btn--ghost" data-role="portal-logout" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ( array() !== $cpms_modules ) : ?>
        <nav class="cpms-staff-portal-shell__nav" data-role="staff-nav" aria-label="ماژول‌های عملیاتی">
            <ul class="cpms-staff-portal-shell__nav-list">
                <?php foreach ( $cpms_modules as $cpms_module ) : ?>
                    <li><a class="cpms-staff-portal-shell__nav-link" data-role="staff-module-link" data-cpms-staff-module="<?php echo esc_attr( $cpms_module['id'] ); ?>" aria-current="page" href="<?php echo esc_url( $cpms_portal_url ); ?>"><?php echo esc_html( $cpms_module['title'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

<?php if ( $cpms_doctor_mod ) : ?>
    <?php
    // Mount the delivered doctor operational module in embed mode: this shell
    // owns the document, header and navigation; the module contributes <main>,
    // its runtime config and its REST/AJAX behaviour unchanged, and closes the
    // shell wrapper exactly as it does in the legacy document.
    $cpms_staff_embed = true;
    $cpms_is_doctor   = true;
    include StaffPortalShell::module_template_path();
    ?>
<?php else : ?>
    <main class="cpms-staff-portal-shell__main" role="main" data-role="portal-main">
        <?php if ( ! $cpms_logged_in ) : ?>
            <section class="cpms-staff-portal-shell__notice" role="status" data-role="portal-login">
                <h2>ورود به پورتال کارکنان</h2>
                <p>برای دسترسی به بخش‌های عملیاتی، وارد حساب کاربری خود شوید.</p>
                <p><a class="cpms-doc-btn cpms-doc-btn--primary" href="<?php echo esc_url( $cpms_login_url ); ?>">ورود</a></p>
            </section>
        <?php else : ?>
            <section class="cpms-staff-portal-shell__notice" role="alert" data-role="portal-access-denied">
                <h2>بخش عملیاتی در دسترس نیست</h2>
                <p>برای حساب شما در حال حاضر هیچ بخش عملیاتی فعالی در پورتال کارکنان وجود ندارد.</p>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php endif; ?>

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags from wp_print_scripts (registered local handles only).
echo $cpms_scripts_html;
?>
</body>
</html>

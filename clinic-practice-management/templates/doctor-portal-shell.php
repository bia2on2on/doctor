<?php

/**
 * Production standalone Doctor Portal shell template (Phase 10 Slice 1).
 *
 * Owns the full HTML document. Deliberately does NOT call:
 *   get_header() / get_footer() / wp_head() / wp_footer() / wp_body_open()
 * so the active Theme never controls header/footer/layout/navigation/shell.
 *
 * WordPress lifecycle already ran (query, auth, cookies). Session, current user,
 * and `wp_rest` nonces remain available. Assets are printed explicitly and only
 * for this surface (DoctorPortalShell::enqueue_for_portal).
 *
 * @package ClinicCore
 */

declare(strict_types=1);


defined('ABSPATH') || exit; // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS

use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Auth\RolesAndCapabilities;

if ( !function_exists('wp_get_current_user' )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- WPCS
    return;
}

DoctorPortalShell::register_handles();
DoctorPortalShell::enqueue_for_portal();

// Embed mode: when the canonical shared Staff Portal shell mounts this
// doctor operational module it sets $cpms_staff_embed = true and owns the
// <html>/<head>, the header, the navigation and the printed assets.
// Standalone mode (legacy Doctor Portal URL) is functionally unchanged.
$cpms_staff_embed = isset( $cpms_staff_embed ) && true === $cpms_staff_embed;

$cpms_user = wp_get_current_user(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
$cpms_logged_in = ($cpms_user instanceof WP_User && (int) $cpms_user->ID > 0); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceAfterOpen,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceBeforeClose -- legacy alignment
if ( ! $cpms_staff_embed ) {
    $cpms_is_doctor = $cpms_logged_in && DoctorPortalShell::isDoctorUser($cpms_user); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- legacy alignment
}
$cpms_login_name = $cpms_logged_in ? (string) $cpms_user->display_name : '';
if ( '' === $cpms_login_name && $cpms_logged_in ) {
    $cpms_login_name = (string) $cpms_user->user_login;
}

if ( ! $cpms_staff_embed ) {
    $cpms_portal_url = DoctorPortalShell::portal_url();
    $cpms_login_url = wp_login_url( $cpms_portal_url ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
    $cpms_logout_url = $cpms_logged_in ? wp_logout_url( $cpms_portal_url ) : '';
}

$cpms_charset = (string) get_bloginfo('charset'); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
if ( '' === $cpms_charset ) {
    $cpms_charset = 'UTF-8';
}
$cpms_site_name = (string) get_bloginfo('name'); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS

if ( ! $cpms_staff_embed ) {
    // In embed mode the Staff Portal shell prints these (same handles, once).
    ob_start();
    wp_print_styles([DoctorPortalShell::CSS_HANDLE]); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
    $cpms_styles_html = (string) ob_get_clean();

    ob_start();
    wp_print_scripts([DoctorPortalShell::JS_HANDLE]); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
    $cpms_scripts_html = (string) ob_get_clean();
}

$rest_root = untrailingslashit(rest_url('clinic/v1')); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template var, not global
$config = [ // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- legacy alignment
    'rest_root' => $rest_root, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment
    'nonce' => wp_create_nonce( 'wp_rest' ), // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment
    'portal_url' => $cpms_portal_url,
    'is_doctor' => $cpms_is_doctor, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment
];
$config_json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WPCS
if ( !is_string($config_json ) || '' === $config_json) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- WPCS
    $config_json = '{}'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WPCS
}

?>
<?php if ( ! $cpms_staff_embed ) : ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-doctor-portal-shell="v1" data-cpms-portal="doctor" data-cpms-doctor-portal="shell">
<head>
<meta charset="<?php echo esc_attr( $cpms_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( 'پورتال پزشک' . ( '' !== $cpms_site_name ? ' — ' . $cpms_site_name : '' ) ); ?></title>
<style>
:root { --cpms-primary: #0f5c56; --cpms-bg: #f3f6f8; --cpms-text: #1b2830; --cpms-muted: #5c6b76; --cpms-border: #e1e8ee; }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
body.cpms-doctor-portal-shell-body { margin: 0; font-family: Tahoma, Vazirmatn, sans-serif; background: var(--cpms-bg); color: var(--cpms-text); line-height: 1.45; direction: rtl; }
.cpms-doc-selectors:not(:has(.cpms-doc-selector:not([hidden]))) { display: none; }
.cpms-doctor-portal-shell { max-width: 1320px; margin: 0 auto; padding: 10px; }
</style>
    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style tags from wp_print_styles (registered local handles only).
    echo $cpms_styles_html;
    ?>
</head>
<body class="cpms-doctor-portal-shell-body">
<div
    id="cpms-doctor-portal-shell"
    class="cpms-doctor-portal-shell"
    data-cpms-doctor-portal-shell="v1"
    data-cpms-portal="doctor"
    data-cpms-doctor-portal="shell"
    data-shell-contract="doctor-v1"
    <?php echo $cpms_is_doctor ? 'data-shell-user="doctor"' : ( $cpms_logged_in ? 'data-shell-user="non-doctor"' : 'data-shell-user="anonymous"' ); // phpcs:ignore Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceBeforeClose -- WPCS ?>
>
    <header class="cpms-doctor-portal-shell__header" role="banner" data-role="portal-header">
        <div class="cpms-doctor-portal-shell__brand">
            <span class="cpms-doctor-portal-shell__mark" aria-hidden="true">CPMS</span>
            <div class="cpms-doctor-portal-shell__titles">
                <p class="cpms-doctor-portal-shell__product">پورتال پزشک</p>
                <h1 class="cpms-doctor-portal-shell__title" data-role="portal-header-title">امروز پزشک — صف زنده</h1>
            </div>
        </div>
        <?php if ( $cpms_is_doctor ) : ?>
            <div class="cpms-doctor-portal-shell__session">
                <span class="cpms-doctor-portal-shell__who" data-role="portal-user"><?php echo esc_html( $cpms_login_name ); ?></span>
                <a class="cpms-doc-btn cpms-doc-btn--ghost" data-role="portal-logout" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
            </div>
        <?php elseif ( $cpms_logged_in ) : ?>
            <div class="cpms-doctor-portal-shell__session">
                <a class="cpms-doc-btn cpms-doc-btn--ghost" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
            </div>
        <?php endif; ?>
    </header>

<?php endif; ?>
    <main class="cpms-doctor-portal-shell__main" role="main" data-role="portal-main" id="cpms-doctor-portal-main">
        <?php if ( ! $cpms_logged_in ) : ?>
            <section class="cpms-doctor-portal-shell__notice" role="status" data-role="portal-login">
                <h2>ورود به پورتال پزشک</h2>
                <p>برای مشاهده صف امروز و بیماران، وارد حساب پزشک خود شوید.</p>
                <p><a class="cpms-doc-btn cpms-doc-btn--primary" href="<?php echo esc_url( $cpms_login_url ); ?>">ورود</a></p>
            </section>
        <?php elseif ( ! $cpms_is_doctor ) : ?>
            <section class="cpms-doctor-portal-shell__notice" role="alert" data-role="portal-access-denied">
                <h2>دسترسی پورتال پزشک</h2>
                <p>این پورتال فقط برای پزشکان با پروفایل فعال است. حساب شما نقش پزشک فعال ندارد یا به پروفایل پزشک متصل نیست.</p>
                <?php if ( current_user_can( RolesAndCapabilities::ROLE_SECRETARY ) ) : ?>
                    <p>حساب منشی نمی‌تواند وارد پورتال پزشک شود.</p>
                <?php endif; ?>
                <p><a class="cpms-doc-btn cpms-doc-btn--primary" href="<?php echo esc_url( admin_url() ); ?>">بازگشت به پیشخوان</a></p>
            </section>
        <?php else : ?>
            <div id="cpms-doctor-portal-app" data-cpms-doctor-portal="app">
                <section class="cpms-doc-context" data-role="doctor-context" aria-live="polite">
                    <div class="cpms-doc-identity">
                        <span class="cpms-doc-identity__mark" aria-hidden="true"></span>
                        <div class="cpms-doc-identity__copy">
                            <p class="cpms-doc-kicker">پزشک فعال</p>
                            <h2 data-role="context-title">در حال بارگذاری اطلاعات پزشک…</h2>
                        </div>
                    </div>
                    <div data-role="context-details" class="cpms-doc-context-details"></div>
                </section>

                <section class="cpms-doc-selectors" data-role="selectors">
                    <div class="cpms-doc-section-head">
                        <h2>زمینه عملیاتی</h2>
                    </div>
                    <div class="cpms-doc-selector-row">
                    <div class="cpms-doc-selector" data-role="clinic-selector-wrap" hidden>
                        <label for="cpms-doc-clinic-select">مطب</label>
                        <select id="cpms-doc-clinic-select" data-role="clinic-select"><option value="">انتخاب مطب…</option></select>
                        <p class="description" data-role="clinic-hint">چند مطب فعال دارید — یکی را انتخاب کنید.</p>
                    </div>
                    <div class="cpms-doc-selector" data-role="location-selector-wrap" hidden>
                        <label for="cpms-doc-location-select">شعبه</label>
                        <select id="cpms-doc-location-select" data-role="location-select"><option value="">انتخاب شعبه…</option></select>
                        <p class="description" data-role="location-hint">چند شعبه واجد شرایط دارید — یکی را انتخاب کنید. بدون انتخاب، داده‌ای نمایش داده نمی‌شود.</p>
                    </div>
                    </div>
                </section>

                <section class="cpms-doc-today" data-role="today-section" hidden>
                    <div class="cpms-doc-section-head">
                        <h2>امروز</h2>
                        <div data-role="today-date" class="cpms-doc-date"></div>
                    </div>
                    <div data-role="today-stats" class="cpms-doc-stats"></div>
                </section>

                <section class="cpms-doc-queue" data-role="queue-section" hidden>
                    <div class="cpms-doc-section-head">
                        <h2>صف زنده</h2>
                        <span class="cpms-doc-live" data-role="live-indicator">● زنده</span>
                    </div>
                    <div class="cpms-doc-queue-head" aria-hidden="true">
                        <span>بیمار</span>
                        <span>وضعیت و انتظار</span>
                    </div>
                    <div data-role="queue-loading" class="cpms-doc-loading">در حال دریافت صف…</div>
                    <div data-role="queue-empty" class="cpms-doc-empty" hidden>صف خالی است.</div>
                    <div data-role="queue-error" class="cpms-doc-error" role="alert" hidden></div>
                    <ul data-role="queue-list" class="cpms-doc-queue-list"></ul>
                </section>

                <section class="cpms-doc-workspace" data-role="workspace-section" hidden aria-label="فضای کاری ویزیت">
                    <div class="cpms-doc-section-head">
                        <h2 data-role="workspace-title">فضای کاری ویزیت</h2>
                        <button type="button" class="cpms-doc-btn cpms-doc-btn--ghost" data-role="workspace-close">بستن</button>
                    </div>
                    <div data-role="workspace-loading" class="cpms-doc-loading" hidden>در حال دریافت پروندهٔ ویزیت…</div>
                    <div data-role="workspace-error" class="cpms-doc-error" role="alert" hidden></div>
                    <div data-role="workspace-body" hidden>
                        <div class="cpms-doc-ws-header" data-role="workspace-header"></div>
                        <div class="cpms-doc-ws-notes-wrap">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>یادداشت‌های این ویزیت</h3>
                                <span class="cpms-doc-count" data-role="workspace-notes-count"></span>
                            </div>
                            <ul data-role="workspace-notes" class="cpms-doc-ws-notes-list"></ul>
                            <div data-role="workspace-notes-empty" class="cpms-doc-empty" hidden>هنوز یادداشتی برای این ویزیت ثبت نشده است.</div>
                        </div>
                        <form class="cpms-doc-ws-form" data-role="workspace-note-form">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>یادداشت جدید</h3>
                            </div>
                            <div class="cpms-doc-ws-field">
                                <label for="cpms-doc-ws-visibility">سطح دسترسی یادداشت</label>
                                <select id="cpms-doc-ws-visibility" data-role="workspace-visibility">
                                    <option value="patient_visible">قابل مشاهده برای بیمار</option>
                                    <option value="doctor_private">خصوصی پزشک</option>
                                </select>
                            </div>
                            <div class="cpms-doc-ws-field">
                                <label for="cpms-doc-ws-content">متن یادداشت</label>
                                <textarea id="cpms-doc-ws-content" data-role="workspace-content" rows="4"></textarea>
                            </div>
                            <div data-role="workspace-form-error" class="cpms-doc-error" role="alert" hidden></div>
                            <div data-role="workspace-form-success" class="cpms-doc-success" role="status" hidden></div>
                            <button type="submit" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-note-submit">ثبت یادداشت</button>
                        </form>
                        <div class="cpms-doc-ws-rx" data-role="workspace-rx-section">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>نسخه‌های این ویزیت</h3>
                                <span class="cpms-doc-count" data-role="workspace-rx-count"></span>
                            </div>
                            <ul data-role="workspace-rx-list" class="cpms-doc-ws-rx-list"></ul>
                            <div data-role="workspace-rx-empty" class="cpms-doc-empty" hidden>هنوز نسخه‌ای برای این ویزیت ثبت نشده است.</div>
                            <form class="cpms-doc-ws-form" data-role="workspace-rx-form">
                                <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                    <h3>نسخهٔ جدید</h3>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-generic">نام ژنریک دارو</label>
                                    <input id="cpms-doc-ws-rx-generic" type="text" data-role="workspace-rx-generic-name" maxlength="190" autocomplete="off">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-dose">مقدار مصرف</label>
                                    <input id="cpms-doc-ws-rx-dose" type="text" data-role="workspace-rx-dose" maxlength="64" autocomplete="off">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-frequency">تکرار مصرف</label>
                                    <input id="cpms-doc-ws-rx-frequency" type="text" data-role="workspace-rx-frequency" maxlength="64" autocomplete="off">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-form">فرم دارو</label>
                                    <select id="cpms-doc-ws-rx-form" data-role="workspace-rx-form-select">
                                        <option value="tablet">قرص</option>
                                        <option value="capsule">کپسول</option>
                                        <option value="syrup">شربت</option>
                                        <option value="injection">آمپول</option>
                                        <option value="ointment">پماد</option>
                                        <option value="drops">قطره</option>
                                        <option value="inhaler">استنشاقی</option>
                                        <option value="other">سایر</option>
                                    </select>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-route">مسیر مصرف</label>
                                    <select id="cpms-doc-ws-rx-route" data-role="workspace-rx-route">
                                        <option value="oral">خوراکی</option>
                                        <option value="iv">وریدی</option>
                                        <option value="im">عضلانی</option>
                                        <option value="sc">زیرجلدی</option>
                                        <option value="topical">موضعی</option>
                                        <option value="inhaled">استنشاقی</option>
                                        <option value="other">سایر</option>
                                    </select>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-duration">مدت مصرف (روز)</label>
                                    <input id="cpms-doc-ws-rx-duration" type="number" min="1" max="3650" inputmode="numeric" data-role="workspace-rx-duration-days">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rx-instructions">دستور مصرف</label>
                                    <input id="cpms-doc-ws-rx-instructions" type="text" data-role="workspace-rx-instructions" maxlength="500" autocomplete="off">
                                </div>
                                <div class="cpms-doc-ws-field cpms-doc-ws-check">
                                    <label for="cpms-doc-ws-rx-visible">
                                        <input id="cpms-doc-ws-rx-visible" type="checkbox" data-role="workspace-rx-visible" checked>
                                        قابل مشاهده برای بیمار (پس از نهایی‌سازی)
                                    </label>
                                </div>
                                <div data-role="workspace-rx-busy" class="cpms-doc-loading" hidden>در حال ثبت نسخه…</div>
                                <div data-role="workspace-rx-error" class="cpms-doc-error" role="alert" hidden></div>
                                <div data-role="workspace-rx-success" class="cpms-doc-success" role="status" hidden></div>
                                <button type="submit" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-rx-submit">ثبت نسخه (پیش‌نویس)</button>
                            </form>
                        </div>
                        <div class="cpms-doc-ws-rec" data-role="workspace-rec-section">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>توصیه‌های این ویزیت</h3>
                                <span class="cpms-doc-count" data-role="workspace-rec-count"></span>
                            </div>
                            <ul data-role="workspace-rec-list" class="cpms-doc-ws-rec-list"></ul>
                            <div data-role="workspace-rec-empty" class="cpms-doc-empty" hidden>هنوز توصیه‌ای برای این ویزیت ثبت نشده است.</div>
                            <form class="cpms-doc-ws-form" data-role="workspace-rec-form">
                                <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                    <h3>توصیهٔ جدید</h3>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rec-type">نوع توصیه</label>
                                    <select id="cpms-doc-ws-rec-type" data-role="workspace-rec-type">
                                        <option value="diet">رژیم غذایی</option>
                                        <option value="rest">استراحت</option>
                                        <option value="activity">فعالیت</option>
                                        <option value="care">مراقبت</option>
                                        <option value="lab">آزمایش</option>
                                        <option value="followup">پیگیری</option>
                                        <option value="other">سایر</option>
                                    </select>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-rec-text">متن توصیه</label>
                                    <textarea id="cpms-doc-ws-rec-text" data-role="workspace-rec-text" rows="3" maxlength="1000"></textarea>
                                </div>
                                <div class="cpms-doc-ws-field cpms-doc-ws-check">
                                    <label for="cpms-doc-ws-rec-visible">
                                        <input id="cpms-doc-ws-rec-visible" type="checkbox" data-role="workspace-rec-visible" checked>
                                        قابل مشاهده برای بیمار
                                    </label>
                                </div>
                                <div data-role="workspace-rec-busy" class="cpms-doc-loading" hidden>در حال ثبت توصیه…</div>
                                <div data-role="workspace-rec-error" class="cpms-doc-error" role="alert" hidden></div>
                                <div data-role="workspace-rec-success" class="cpms-doc-success" role="status" hidden></div>
                                <button type="submit" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-rec-submit">ثبت توصیه</button>
                            </form>
                        </div>
                        <div class="cpms-doc-ws-fu" data-role="workspace-fu-section">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>پیگیری‌های این ویزیت</h3>
                                <span class="cpms-doc-count" data-role="workspace-fu-count"></span>
                            </div>
                            <ul data-role="workspace-fu-list" class="cpms-doc-ws-fu-list"></ul>
                            <div data-role="workspace-fu-empty" class="cpms-doc-empty" hidden>هنوز پیگیری‌ای برای این ویزیت ثبت نشده است.</div>
                            <form class="cpms-doc-ws-form" data-role="workspace-fu-form">
                                <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                    <h3>پیگیری جدید</h3>
                                </div>
                                <div class="cpms-doc-ws-field cpms-doc-ws-check">
                                    <label for="cpms-doc-ws-fu-needed">
                                        <input id="cpms-doc-ws-fu-needed" type="checkbox" data-role="workspace-fu-needed" checked>
                                        نیاز به پیگیری
                                    </label>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-fu-date">تاریخ پیشنهادی (YYYY-MM-DD)</label>
                                    <input id="cpms-doc-ws-fu-date" type="text" inputmode="numeric" placeholder="2026-04-10" autocomplete="off" data-role="workspace-fu-date">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-fu-interval-days">یا بازه (روز)</label>
                                    <input id="cpms-doc-ws-fu-interval-days" type="number" min="1" max="3650" inputmode="numeric" data-role="workspace-fu-interval-days">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-fu-reason">علت پیگیری</label>
                                    <input id="cpms-doc-ws-fu-reason" type="text" maxlength="255" autocomplete="off" data-role="workspace-fu-reason">
                                </div>
                                <div data-role="workspace-fu-busy" class="cpms-doc-loading" hidden>در حال ثبت پیگیری…</div>
                                <div data-role="workspace-fu-error" class="cpms-doc-error" role="alert" hidden></div>
                                <div data-role="workspace-fu-success" class="cpms-doc-success" role="status" hidden></div>
                                <button type="submit" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-fu-submit">ثبت پیگیری</button>
                            </form>
                        </div>
                        <div class="cpms-doc-ws-files" data-role="workspace-visit-files-section">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>فایل‌های این ویزیت</h3>
                                <span class="cpms-doc-count" data-role="workspace-visit-files-count"></span>
                            </div>
                            <ul data-role="workspace-visit-files-list" class="cpms-doc-ws-files-list"></ul>
                            <div data-role="workspace-visit-files-empty" class="cpms-doc-empty" hidden>هنوز فایلی برای این ویزیت ثبت نشده است.</div>
                            <form class="cpms-doc-ws-form" data-role="workspace-visit-files-upload-form">
                                <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                    <h3>افزودن فایل</h3>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-file-input">فایل (PDF/JPG/PNG/WEBP)</label>
                                    <input id="cpms-doc-ws-file-input" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" data-role="workspace-visit-files-upload-input">
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-file-category">دسته‌بندی</label>
                                    <select id="cpms-doc-ws-file-category" data-role="workspace-visit-files-category">
                                        <option value="other">سایر</option>
                                        <option value="lab_result">نتیجه آزمایش</option>
                                        <option value="image">تصویر</option>
                                        <option value="scan">اسکن</option>
                                        <option value="document">سند</option>
                                    </select>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-file-visibility">سطح دسترسی</label>
                                    <select id="cpms-doc-ws-file-visibility" data-role="workspace-visit-files-visibility">
                                        <option value="patient_visible">قابل مشاهده برای بیمار</option>
                                        <option value="doctor_private">خصوصی پزشک</option>
                                    </select>
                                </div>
                                <div data-role="workspace-visit-files-upload-busy" class="cpms-doc-loading" hidden>در حال آپلود فایل…</div>
                                <div data-role="workspace-visit-files-upload-error" class="cpms-doc-error" role="alert" hidden></div>
                                <div data-role="workspace-visit-files-upload-success" class="cpms-doc-success" role="status" hidden></div>
                                <button type="submit" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-visit-files-upload-submit">آپلود فایل</button>
                            </form>
                        </div>
                        <div class="cpms-doc-ws-consult" data-role="workspace-consult-complete-section">
                            <div class="cpms-doc-section-head cpms-doc-ws-subhead">
                                <h3>شکایت اصلی و پایان ویزیت</h3>
                            </div>
                            <p class="cpms-doc-ws-cc-state" data-role="workspace-cc-state" role="status"></p>
                            <form class="cpms-doc-ws-form" data-role="workspace-cc-form">
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-cc-text">شکایت اصلی بیمار</label>
                                    <textarea id="cpms-doc-ws-cc-text" data-role="workspace-cc-text" rows="2"></textarea>
                                </div>
                                <div class="cpms-doc-ws-field">
                                    <label for="cpms-doc-ws-cc-visibility">سطح دسترسی</label>
                                    <select id="cpms-doc-ws-cc-visibility" data-role="workspace-cc-visibility">
                                        <option value="patient_visible">قابل مشاهده برای بیمار</option>
                                        <option value="doctor_private">خصوصی پزشک</option>
                                    </select>
                                </div>
                                <div data-role="workspace-cc-busy" class="cpms-doc-loading" hidden>در حال ثبت شکایت اصلی…</div>
                                <div data-role="workspace-cc-error" class="cpms-doc-error" role="alert" hidden></div>
                                <div data-role="workspace-cc-success" class="cpms-doc-success" role="status" hidden></div>
                                <button type="submit" class="cpms-doc-btn cpms-doc-btn--ghost" data-role="workspace-cc-submit">ثبت شکایت اصلی</button>
                            </form>
                            <div class="cpms-doc-ws-consult-end">
                                <p class="cpms-doc-ws-cc-state" data-role="workspace-consult-complete-hint" hidden></p>
                                <div data-role="workspace-consult-complete-busy" class="cpms-doc-loading" hidden>در حال پایان ویزیت…</div>
                                <div data-role="workspace-consult-complete-error" class="cpms-doc-error" role="alert" hidden></div>
                                <div data-role="workspace-consult-complete-success" class="cpms-doc-success" role="status" hidden></div>
                                <button type="button" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-consult-complete-submit" hidden>پایان ویزیت</button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="cpms-doc-appointments" data-role="appointments-section" hidden>
                    <div class="cpms-doc-section-head">
                        <h2>نوبت‌های امروز</h2>
                        <span class="cpms-doc-count" data-role="appointments-count"></span>
                    </div>
                    <div class="cpms-doc-queue-head" aria-hidden="true">
                        <span>بیمار و ساعت</span>
                        <span>وضعیت</span>
                    </div>
                    <div data-role="appointments-empty" class="cpms-doc-empty" hidden>نوبتی برای این روز ثبت نشده است.</div>
                    <ul data-role="appointments-list" class="cpms-doc-queue-list"></ul>
                </section>

                <section class="cpms-doc-no-data" data-role="no-data" hidden>
                    <h3>داده‌ای برای نمایش وجود ندارد</h3>
                    <p>هیچ شعبه فعالی برای این مطب یافت نشد یا هنوز انتخاب انجام نشده است.</p>
                </section>
            </div>
        <?php endif; ?>
    </main>
</div>

<script type="application/json" class="cpms-doctor-portal__config"><?php echo $config_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload, already encoded via wp_json_encode ?></script>

<script>
(function(){
'use strict';
var cfgEl = document.querySelector('.cpms-doctor-portal__config');
if ( !cfgEl ) return;

// Visit Workspace — Medical Files (Phase 10): the upload control is disabled
// while a transfer is in flight (double-submit guard).
function workspaceVisitUploadBusy(busy){
    var btn = qs('[data-role="workspace-visit-files-upload-submit"]');
    if ( !btn ) return;
    btn.disabled = !!busy;
    if ( busy ) btn.setAttribute('aria-busy', 'true');
    else btn.removeAttribute('aria-busy');
}

var CFG;
try { CFG = JSON.parse(cfgEl.textContent || '{}'); } catch(e){ CFG = {}; }
if ( !CFG.rest_root || !CFG.nonce ) return;
if ( !CFG.is_doctor ) return;

var state = {
    clinics: [],
    locations: [],
    selectedClinicId: null,
    selectedLocationId: null,
    today: null,
    queue: [],
    lastEventId: 0,
    timer: null,
    pollPaused: false,
    workspaceVisitId: null,
    workspaceNotes: [],
    workspaceBusy: false,
    workspaceRx: [],
    workspaceRxBusy: false,
    workspaceRec: [],
    workspaceRecBusy: false,
    workspaceFu: [],
    workspaceFuBusy: false,
    workspaceFiles: [],
    workspaceFilesBusy: false,
    workspaceStatus: '',
    workspaceData: null,
    workspaceCcBusy: false,
    workspaceCompleteBusy: false
};

function apiUrl(path){
    var base = CFG.rest_root;
    if ( base.indexOf('?' ) !== -1 && path.indexOf('?') !== -1) {
        return base + path.replace('?', '&');
    }
    return base + path;
}
function api(method, path, body, extraHeaders){
    var headers = { 'X-WP-Nonce': CFG.nonce };
    if ( extraHeaders ) { for ( var k in extraHeaders ){ headers[k]=extraHeaders[k]; } }
    var opts = { method: method, headers: headers };
    if ( body ) {
        if ( body instanceof FormData ) { opts.body = body; }
        else { headers['Content-Type']='application/json'; opts.body=JSON.stringify(body); }
    }
    return fetch(apiUrl(path), opts).then(function(r){
        return r.json().then(function(j){ return { status: r.status, body: j, headers: r.headers }; }, function(){
            return { status: r.status, body: {}, headers: r.headers };
        });
    });
}
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function qs(sel){ return document.querySelector(sel); }
function show(el){ if ( el ) el.hidden=false; }
function hide(el){ if ( el ) el.hidden=true; }

function renderContext(doctor, clinic, location){
    var titleEl = qs('[data-role="context-title"]');
    var detailsEl = qs('[data-role="context-details"]');
    if ( !titleEl || !detailsEl ) return;
    if ( !doctor ) {
        titleEl.textContent = 'اطلاعات پزشک یافت نشد';
        return;
    }
    titleEl.textContent = 'دکتر ' + (doctor.clinician_name || doctor.display_name || '');
    var parts = [];
    if ( clinic ) parts.push('<span data-role="context-clinic">مطب: ' + esc(clinic.name) + '</span>');
    if ( location ) parts.push('<span data-role="context-location">شعبه: ' + esc(location.name) + ' (' + esc(location.timezone) + ')</span>');
    if ( doctor.clinician_id ) parts.push('<span>شناسه حرفه‌ای: ' + esc(doctor.clinician_id) + '</span>');
    detailsEl.innerHTML = parts.join('');
}

function renderClinicSelector(){
    var wrap = qs('[data-role="clinic-selector-wrap"]');
    var sel = qs('[data-role="clinic-select"]');
    if ( !wrap || !sel ) return;
    if ( state.clinics.length <= 1 ) {
        hide(wrap);
        if ( state.clinics.length === 1 ) {
            state.selectedClinicId = state.clinics[0].id;
        }
        return;
    }
    show(wrap);
    sel.innerHTML = '<option value="">انتخاب مطب…</option>' + state.clinics.map(function(c){
        return '<option value="' + c.id + '"' + (String(c.id)===String(state.selectedClinicId)?' selected':'') + '>' + esc(c.name) + '</option>';
    }).join('');
}

function renderLocationSelector(){
    var wrap = qs('[data-role="location-selector-wrap"]');
    var sel = qs('[data-role="location-select"]');
    if ( !wrap || !sel ) return;
    if ( !state.selectedClinicId ) {
        hide(wrap);
        return;
    }
    if ( state.locations.length === 0 ) {
        hide(wrap);
        var noData = qs('[data-role="no-data"]');
        show(noData);
        hide(qs('[data-role="today-section"]'));
        hide(qs('[data-role="queue-section"]'));
        hide(qs('[data-role="appointments-section"]'));
        return;
    }
    if ( state.locations.length === 1 ) {
        hide(wrap);
        state.selectedLocationId = state.locations[0].id;
        return;
    }
    show(wrap);
    sel.innerHTML = '<option value="">انتخاب شعبه…</option>' + state.locations.map(function(l){
        return '<option value="' + l.id + '"' + (String(l.id)===String(state.selectedLocationId)?' selected':'') + '>' + esc(l.name) + ' (' + esc(l.timezone) + ')</option>';
    }).join('');
}

function renderToday(){
    var sec = qs('[data-role="today-section"]');
    var dateEl = qs('[data-role="today-date"]');
    var statsEl = qs('[data-role="today-stats"]');
    if ( !sec || !dateEl || !statsEl ) return;
    if ( !state.today ) {
        hide(sec);
        return;
    }
    show(sec);
    dateEl.textContent = 'تاریخ عملیاتی (بر اساس شعبه): ' + (state.today.date || '');
    var s = state.today.stats || {};
    var fields = [
        ['waiting','در انتظار'],
        ['called','فراخوانده‌شده'],
        ['in_consultation','در ویزیت'],
        ['checked_in','ثبت‌شده'],
        ['total','کل امروز'],
        ['appointments_today','نوبت امروز'],
        ['walk_in_today','حضوری امروز']
    ];
    statsEl.innerHTML = fields.map(function(f){
        return '<div class="cpms-doc-stat" data-role="stat-' + f[0] + '"><b>' + esc(s[f[0]]!=null?s[f[0]]:'—') + '</b><span>' + f[1] + '</span></div>';
    }).join('');
}

function appointmentStatusLabel(status){
    return {
        pending: 'در انتظار تأیید',
        confirmed: 'رزرو شده',
        cancelled_by_patient: 'لغو توسط بیمار',
        cancelled_by_staff: 'لغو توسط مطب',
        rescheduled: 'جابه‌جا شده',
        completed: 'انجام شده',
        no_show: 'عدم حضور'
    }[status] || status || '';
}

function visitStatusLabel(status){
    return {
        checked_in: 'پذیرش‌شده',
        waiting: 'در صف',
        called: 'فراخوانده',
        in_consultation: 'در ویزیت',
        consultation_completed: 'ویزیت تمام‌شده',
        awaiting_payment: 'منتظر پرداخت',
        paid: 'پرداخت‌شده',
        checked_out: 'ترخیص‌شده',
        cancelled: 'لغو شده',
        skipped: 'رد شده'
    }[status] || status || '';
}

function renderAppointments(){
    var sec = qs('[data-role="appointments-section"]');
    var empty = qs('[data-role="appointments-empty"]');
    var list = qs('[data-role="appointments-list"]');
    var count = qs('[data-role="appointments-count"]');
    if ( !sec || !list ) return;
    if ( !state.selectedClinicId || !state.selectedLocationId || !state.today ) {
        hide(sec);
        return;
    }
    show(sec);
    var rows = state.today.appointments || [];
    if ( count ) count.textContent = rows.length + ' نوبت';
    if ( rows.length===0 ) {
        show(empty);
        list.innerHTML = '';
        return;
    }
    hide(empty);
    list.innerHTML = rows.map(function(row){
        var badges = '';
        if ( row.express ) badges += '<span class="cpms-doc-badge express">فوری</span>';
        badges += '<span class="cpms-doc-badge status-' + esc(row.status||'') + '">' + esc(appointmentStatusLabel(row.status)) + '</span>';
        if ( row.visit_status ) {
            badges += '<span class="cpms-doc-badge status-' + esc(row.visit_status) + '">' + esc(visitStatusLabel(row.visit_status)) + '</span>';
        }
        return '<li class="cpms-doc-queue-item" data-role="appointment-item" data-appointment-id="' + esc(row.id) + '" data-status="' + esc(row.status||'') + '" data-visit-status="' + esc(row.visit_status||'') + '">' +
            '<div class="cpms-doc-queue-main"><strong class="cpms-doc-appt-time">' + esc(row.time||'—') + '</strong>' +
            '<strong data-role="patient-name">' + esc(row.patient_name || 'بیمار') + '</strong></div>' +
            '<div class="cpms-doc-queue-aside">' + badges + '</div></li>';
    }).join('');
}

function queueActions(v){
    var id = esc(v.id);
    if ( v.status === 'waiting' ) {
        return '<div class="cpms-doc-actions" data-role="queue-actions">' +
            '<button type="button" class="cpms-doc-btn cpms-doc-btn--primary" data-action="call" data-visit-id="' + id + '">فراخوان</button>' +
            '<button type="button" class="cpms-doc-btn cpms-doc-btn--ghost" data-action="skip" data-visit-id="' + id + '">رد کردن</button>' +
            '</div>';
    }
    if ( v.status === 'called' ) {
        return '<div class="cpms-doc-actions" data-role="queue-actions">' +
            '<button type="button" class="cpms-doc-btn cpms-doc-btn--primary" data-action="start" data-visit-id="' + id + '">شروع ویزیت</button>' +
            '<button type="button" class="cpms-doc-btn cpms-doc-btn--ghost" data-action="recall" data-visit-id="' + id + '">فراخوان مجدد</button>' +
            '<button type="button" class="cpms-doc-btn cpms-doc-btn--ghost" data-action="skip" data-visit-id="' + id + '">رد کردن</button>' +
            '</div>';
    }
    return '';
}

function renderQueue(){
    var sec = qs('[data-role="queue-section"]');
    var loading = qs('[data-role="queue-loading"]');
    var empty = qs('[data-role="queue-empty"]');
    var err = qs('[data-role="queue-error"]');
    var list = qs('[data-role="queue-list"]');
    if ( !sec || !list ) return;
    if ( !state.selectedClinicId || !state.selectedLocationId ) {
        hide(sec);
        return;
    }
    show(sec);
    if ( loading ) hide(loading);
    if ( state.queue.length===0 ) {
        show(empty);
        list.innerHTML='';
    } else {
        hide(empty);
        list.innerHTML = state.queue.map(function(v){
            var badge = '';
            if ( v.express ) badge += '<span class="cpms-doc-badge express">فوری</span>';
            var statusLabel = visitStatusLabel(v.status);
            var statusClass = 'status-' + (v.status||'');
            return '<li class="cpms-doc-queue-item" data-role="queue-item" data-visit-id="' + esc(v.id) + '" data-status="' + esc(v.status||'') + '">' +
                '<div class="cpms-doc-queue-main"><strong data-role="patient-name">' + esc(v.patient_name || 'بیمار') + '</strong>' +
                '<span class="cpms-doc-queue-source">' + esc(v.source||'') + '</span></div>' +
                '<div class="cpms-doc-queue-aside">' + badge +
                '<span class="cpms-doc-badge ' + esc(statusClass) + '">' + esc(statusLabel) + '</span>' +
                '<span class="cpms-doc-wait">انتظار <b>' + esc(v.waiting_since||'—') + '</b></span></div>' +
                queueActions(v) +
                '</li>';
        }).join('');
    }
    if ( err && state.queue.length>0 ) hide(err);
}

function setRowBusy(row, busy){
    if ( !row ) return;
    if ( busy ) row.setAttribute('aria-busy', 'true');
    else row.removeAttribute('aria-busy');
    var btns = row.querySelectorAll('[data-action]');
    for ( var i=0; i<btns.length; i++ ) btns[i].disabled = busy;
}

function actionErrorMessage(r){
    var fallback = 'خطا در انجام عملیات — دوباره تلاش کنید';
    if ( !r || !r.body ) return fallback;
    var msg = r.body.message;
    if ( typeof msg === 'string' && msg !== '' ) return msg.slice(0, 200);
    return fallback;
}

function doQueueAction(visitId, action){
    var row = document.querySelector('[data-role="queue-item"][data-visit-id="' + visitId + '"]');
    if ( row && row.getAttribute('aria-busy') === 'true' ) return;
    var body = null;
    if ( action === 'skip' ) {
        var input = window.prompt('علت رد کردن را بنویسید:', '');
        if ( input === null ) return;
        var reason = String(input).trim().slice(0, 255);
        if ( reason === '' ) {
            showError('علت رد کردن الزامی است.');
            return;
        }
        body = { reason: reason };
    }
    setRowBusy(row, true);
    var headers = {
        'X-CPMS-Clinic-Id': String(state.selectedClinicId),
        'X-CPMS-Location-Id': String(state.selectedLocationId)
    };
    api('POST', '/visits/' + encodeURIComponent(String(visitId)) + '/' + action, body, headers).then(function(r){
        if ( r.status === 200 ) {
            loadTodayAndQueue();
            return;
        }
        setRowBusy(row, false);
        showError(actionErrorMessage(r));
    }).catch(function(){
        setRowBusy(row, false);
        showError('خطای ارتباط — دوباره تلاش کنید');
    });
}

function showError(msg){
    var err = qs('[data-role="queue-error"]');
    if ( !err ) return;
    err.textContent = msg;
    show(err);
}

function scopeHeaders(){
    return {
        'X-CPMS-Clinic-Id': String(state.selectedClinicId),
        'X-CPMS-Location-Id': String(state.selectedLocationId)
    };
}

// ================= Visit Workspace (Phase 10 Slice 3) =================
// Selection = queue row visit_id (selector only). The read goes through the
// Doctor Portal workspace boundary (GET /doctor/portal/visits/{id}/record),
// which reuses the established E7 record behavior after the portal-specific
// guard (server-derived clinician identity + trusted Clinic + trusted
// operational Location + Visit ownership) — with the portal nonce and the
// trusted Clinic/Location selector headers; authority stays server-side.

function resetRxUi(){
    state.workspaceRx = [];
    setRxBusy(false);
    var list = qs('[data-role="workspace-rx-list"]');
    if ( list ) list.innerHTML = '';
    var count = qs('[data-role="workspace-rx-count"]');
    if ( count ) count.textContent = '';
    show(qs('[data-role="workspace-rx-empty"]'));
    hide(qs('[data-role="workspace-rx-busy"]'));
    hide(qs('[data-role="workspace-rx-error"]'));
    hide(qs('[data-role="workspace-rx-success"]'));
    clearRxComposer();
}

function resetRecFuUi(){
    state.workspaceRec = [];
    state.workspaceFu = [];
    setRecBusy(false);
    setFuBusy(false);
    var recList = qs('[data-role="workspace-rec-list"]');
    if ( recList ) recList.innerHTML = '';
    var recCount = qs('[data-role="workspace-rec-count"]');
    if ( recCount ) recCount.textContent = '';
    show(qs('[data-role="workspace-rec-empty"]'));
    hide(qs('[data-role="workspace-rec-busy"]'));
    hide(qs('[data-role="workspace-rec-error"]'));
    hide(qs('[data-role="workspace-rec-success"]'));
    clearRecComposer();
    var fuList = qs('[data-role="workspace-fu-list"]');
    if ( fuList ) fuList.innerHTML = '';
    var fuCount = qs('[data-role="workspace-fu-count"]');
    if ( fuCount ) fuCount.textContent = '';
    show(qs('[data-role="workspace-fu-empty"]'));
    hide(qs('[data-role="workspace-fu-busy"]'));
    hide(qs('[data-role="workspace-fu-error"]'));
    hide(qs('[data-role="workspace-fu-success"]'));
    clearFuComposer();
}

function closeWorkspace(){
    state.workspaceVisitId = null;
    state.workspaceNotes = [];
    hide(qs('[data-role="workspace-section"]'));
    hide(qs('[data-role="workspace-body"]'));
    hide(qs('[data-role="workspace-loading"]'));
    hide(qs('[data-role="workspace-error"]'));
    hide(qs('[data-role="workspace-form-error"]'));
    hide(qs('[data-role="workspace-form-success"]'));
    var contentEl = qs('[data-role="workspace-content"]');
    if ( contentEl ) contentEl.value = '';
    setNoteSubmitBusy(false);
    resetRxUi();
    resetRecFuUi();
    resetFilesUi();
    resetConsultUi();
}

function setNoteSubmitBusy(busy){
    state.workspaceBusy = !!busy;
    var btn = qs('[data-role="workspace-note-submit"]');
    if ( !btn ) return;
    btn.disabled = !!busy;
    if ( busy ) btn.setAttribute('aria-busy', 'true');
    else btn.removeAttribute('aria-busy');
}

function openWorkspace(visitId){
    if ( !state.selectedClinicId || !state.selectedLocationId ) return;
    var sec = qs('[data-role="workspace-section"]');
    if ( !sec ) return;
    state.workspaceVisitId = visitId;
    state.workspaceNotes = [];
    show(sec);
    show(qs('[data-role="workspace-loading"]'));
    hide(qs('[data-role="workspace-body"]'));
    hide(qs('[data-role="workspace-error"]'));
    hide(qs('[data-role="workspace-form-error"]'));
    hide(qs('[data-role="workspace-form-success"]'));
    setNoteSubmitBusy(false);
    resetRxUi();
    resetRecFuUi();
    resetFilesUi();
    resetConsultUi();
    var contentEl = qs('[data-role="workspace-content"]');
    if ( contentEl ) contentEl.value = '';
    var openedVisitId = visitId;
    var clinicId = state.selectedClinicId;
    var locationId = state.selectedLocationId;
    api('GET', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/record', null, scopeHeaders()).then(function(r){
        // Stale guard: scope or selection changed while in flight.
        if ( state.workspaceVisitId !== openedVisitId || state.selectedClinicId !== clinicId || state.selectedLocationId !== locationId ) return;
        hide(qs('[data-role="workspace-loading"]'));
        if ( r.status !== 200 ) {
            var el = qs('[data-role="workspace-error"]');
            if ( el ) {
                el.textContent = (r.body && r.body.message) ? String(r.body.message).slice(0, 200) : 'خطا در دریافت پروندهٔ ویزیت — دوباره تلاش کنید';
                show(el);
            }
            return;
        }
        var data = (r.body && r.body.data) || r.body || {};
        renderWorkspace(data);
    }).catch(function(){
        if ( state.workspaceVisitId !== openedVisitId || state.selectedClinicId !== clinicId || state.selectedLocationId !== locationId ) return;
        hide(qs('[data-role="workspace-loading"]'));
        var el = qs('[data-role="workspace-error"]');
        if ( el ) { el.textContent = 'خطای ارتباط در دریافت پرونده — دوباره تلاش کنید'; show(el); }
    });
}

function genderLabel(value){
    return { male: 'مرد', female: 'زن' }[value] || value || '';
}

function renderWorkspace(data){
    var body = qs('[data-role="workspace-body"]');
    if ( !body ) return;
    renderWorkspaceHeader(data);
    state.workspaceNotes = (data && data.notes) || [];
    renderWorkspaceNotes();
    state.workspaceRx = (data && data.prescriptions) || [];
    renderWorkspaceRx();
    state.workspaceRec = (data && data.recommendations) || [];
    renderWorkspaceRec();
    state.workspaceFu = (data && data.follow_ups) || [];
    renderWorkspaceFu();
    state.workspaceFiles = (data && data.files) || [];
    renderWorkspaceFiles();
    state.workspaceData = data || null;
    state.workspaceStatus = String((data && data.visit && data.visit.status) || '');
    renderConsultComplete();
    show(body);
}

function renderWorkspaceHeader(data){
    var el = qs('[data-role="workspace-header"]');
    if ( !el ) return;
    var p = (data && data.patient) || {};
    var v = (data && data.visit) || {};
    var meta = [];
    if ( p.mrn ) meta.push('<span class="cpms-doc-ws-meta-item">MRN: <b>' + esc(p.mrn) + '</b></span>');
    if ( p.age != null && p.age !== '' ) meta.push('<span class="cpms-doc-ws-meta-item">سن: <b>' + esc(p.age) + '</b></span>');
    if ( p.gender ) meta.push('<span class="cpms-doc-ws-meta-item">جنسیت: <b>' + esc(genderLabel(p.gender)) + '</b></span>');
    if ( v.visit_date ) meta.push('<span class="cpms-doc-ws-meta-item">تاریخ ویزیت: <b>' + esc(v.visit_date) + '</b></span>');
    var statusBadge = v.status
        ? '<span class="cpms-doc-badge status-' + esc(v.status) + '">' + esc(visitStatusLabel(v.status)) + '</span>'
        : '';
    el.innerHTML =
        '<div class="cpms-doc-ws-id">' +
            '<strong class="cpms-doc-ws-name" data-role="workspace-patient-name">' + esc(p.full_name || 'بیمار') + '</strong>' +
            statusBadge +
        '</div>' +
        (meta.length ? '<div class="cpms-doc-ws-meta">' + meta.join('') + '</div>' : '');
}

function noteVisibilityLabel(visibility){
    return visibility === 'doctor_private' ? 'خصوصی پزشک' : 'قابل مشاهده برای بیمار';
}

function noteVisibilityClass(visibility){
    return visibility === 'doctor_private' ? 'vis-private' : 'vis-patient';
}

function renderWorkspaceNotes(){
    var list = qs('[data-role="workspace-notes"]');
    var empty = qs('[data-role="workspace-notes-empty"]');
    var count = qs('[data-role="workspace-notes-count"]');
    if ( !list ) return;
    var notes = state.workspaceNotes;
    if ( count ) count.textContent = notes.length + ' یادداشت';
    if ( notes.length === 0 ) {
        list.innerHTML = '';
        show(empty);
        return;
    }
    hide(empty);
    list.innerHTML = notes.map(function(n){
        return '<li class="cpms-doc-ws-note" data-note-id="' + esc(n.id) + '">' +
            '<div class="cpms-doc-ws-note-head">' +
                (n.category === 'chief_complaint' ? '<span class="cpms-doc-badge" data-role="workspace-note-cc">شکایت اصلی</span>' : '') +
                '<span class="cpms-doc-badge ' + esc(noteVisibilityClass(n.visibility)) + '">' + esc(noteVisibilityLabel(n.visibility)) + '</span>' +
                '<span class="cpms-doc-ws-note-date">' + esc(n.created_at || '') + '</span>' +
            '</div>' +
            '<p class="cpms-doc-ws-note-text">' + esc(n.content_text || '') + '</p>' +
        '</li>';
    }).join('');
}

function submitWorkspaceNote(){
    var visitId = state.workspaceVisitId;
    if ( !visitId || state.workspaceBusy ) return;
    var errEl = qs('[data-role="workspace-form-error"]');
    var okEl = qs('[data-role="workspace-form-success"]');
    var contentEl = qs('[data-role="workspace-content"]');
    var visEl = qs('[data-role="workspace-visibility"]');
    if ( !contentEl || !visEl ) return;
    hide(errEl);
    hide(okEl);
    var content = String(contentEl.value || '').trim();
    var visibility = visEl.value === 'doctor_private' ? 'doctor_private' : 'patient_visible';
    if ( content === '' ) {
        if ( errEl ) { errEl.textContent = 'متن یادداشت الزامی است.'; show(errEl); }
        return;
    }
    setNoteSubmitBusy(true);
    var submittedVisitId = visitId;
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/notes', {
        category: 'clinical_note',
        visibility: visibility,
        content_text: content
    }, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setNoteSubmitBusy(false);
        if ( r.status === 200 ) {
            var note = (r.body && r.body.data) || r.body || {};
            if ( note && note.id ) {
                state.workspaceNotes = [note].concat(state.workspaceNotes);
                renderWorkspaceNotes();
            }
            contentEl.value = '';
            if ( okEl ) { okEl.textContent = 'یادداشت با موفقیت ثبت شد.'; show(okEl); }
            return;
        }
        // Failed persistence is never presented as success.
        if ( errEl ) {
            errEl.textContent = (r.body && r.body.message) ? String(r.body.message).slice(0, 200) : 'خطا در ثبت یادداشت — دوباره تلاش کنید';
            show(errEl);
        }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setNoteSubmitBusy(false);
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در ثبت یادداشت — دوباره تلاش کنید'; show(errEl); }
    });
}

// ================= Visit Workspace — prescriptions (Phase 10 Rx write) =================
// Write goes through the Doctor Portal prescription boundary
// (/doctor/portal/visits/{id}/prescriptions, /doctor/portal/prescriptions/{id}/finalize)
// with the portal nonce and trusted Clinic/Location selector headers ONLY.
// clinician_id is never sent; authority stays server-derived.

function setRxBusy(busy){
    state.workspaceRxBusy = !!busy;
    var btn = qs('[data-role="workspace-rx-submit"]');
    if ( btn ) {
        btn.disabled = !!busy;
        if ( busy ) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
    }
    var busyEl = qs('[data-role="workspace-rx-busy"]');
    if ( busyEl ) {
        if ( busy ) show(busyEl); else hide(busyEl);
    }
}

function clearRxComposer(){
    var ids = ['workspace-rx-generic-name', 'workspace-rx-dose', 'workspace-rx-frequency', 'workspace-rx-duration-days', 'workspace-rx-instructions'];
    for ( var i = 0; i < ids.length; i++ ) {
        var el = qs('[data-role="' + ids[i] + '"]');
        if ( el ) el.value = '';
    }
    var form = qs('[data-role="workspace-rx-form-select"]');
    if ( form ) form.selectedIndex = 0;
    var route = qs('[data-role="workspace-rx-route"]');
    if ( route ) route.selectedIndex = 0;
    var visible = qs('[data-role="workspace-rx-visible"]');
    if ( visible ) visible.checked = true;
}

function rxStatusLabel(status){
    return { draft: 'پیش‌نویس', finalized: 'نهایی‌شده — فقط خواندنی' }[status] || status || '';
}

function renderWorkspaceRx(){
    var list = qs('[data-role="workspace-rx-list"]');
    var empty = qs('[data-role="workspace-rx-empty"]');
    var count = qs('[data-role="workspace-rx-count"]');
    if ( !list ) return;
    var rxList = state.workspaceRx;
    if ( count ) count.textContent = rxList.length + ' نسخه';
    if ( rxList.length === 0 ) {
        list.innerHTML = '';
        show(empty);
        return;
    }
    hide(empty);
    list.innerHTML = rxList.map(function(rx){
        var items = (rx.items || []).map(function(it){
            return '<li class="cpms-doc-ws-rx-item-row" data-role="workspace-rx-item-row">' +
                '<strong>' + esc(it.generic_name || '') + '</strong>' +
                ' — ' + esc(it.dose || '') + ' · ' + esc(it.frequency || '') +
                (it.duration_days != null && it.duration_days !== '' ? ' · ' + esc(it.duration_days) + ' روز' : '') +
                (it.instructions ? ' · ' + esc(it.instructions) : '') +
                '</li>';
        }).join('');
        var control = rx.status === 'draft'
            ? '<button type="button" class="cpms-doc-btn cpms-doc-btn--primary" data-role="workspace-rx-finalize" data-rx-id="' + esc(rx.id) + '">نهایی‌سازی نسخه</button>'
            : '<span data-role="workspace-rx-readonly">نهایی‌شده — فقط خواندنی</span>';
        return '<li class="cpms-doc-ws-rx-item" data-role="workspace-rx-item" data-rx-id="' + esc(rx.id) + '" data-status="' + esc(rx.status || '') + '">' +
            '<div class="cpms-doc-ws-rx-head">' +
                '<span data-role="workspace-rx-number">' + esc(rx.prescription_number || '') + '</span>' +
                '<span class="cpms-doc-badge rx-status-' + esc(rx.status || '') + '">' + esc(rxStatusLabel(rx.status)) + '</span>' +
            '</div>' +
            '<ul class="cpms-doc-ws-rx-items">' + items + '</ul>' +
            '<div class="cpms-doc-ws-rx-row">' + control + '</div>' +
        '</li>';
    }).join('');
}

function upsertWorkspaceRx(rx){
    if ( !rx || !rx.id ) return;
    var next = [];
    var found = false;
    for ( var i = 0; i < state.workspaceRx.length; i++ ) {
        if ( String(state.workspaceRx[i].id) === String(rx.id) ) {
            next.push(rx);
            found = true;
        } else {
            next.push(state.workspaceRx[i]);
        }
    }
    if ( !found ) next.unshift(rx);
    state.workspaceRx = next;
    renderWorkspaceRx();
}

function rxFeedbackError(r){
    var fallback = 'خطا در ذخیرهٔ نسخه — دوباره تلاش کنید';
    if ( !r || !r.body ) return fallback;
    var msg = r.body.message;
    if ( typeof msg === 'string' && msg !== '' ) return msg.slice(0, 200);
    return fallback;
}

function submitWorkspaceRx(){
    var visitId = state.workspaceVisitId;
    if ( !visitId || state.workspaceRxBusy ) return;
    var errEl = qs('[data-role="workspace-rx-error"]');
    var okEl = qs('[data-role="workspace-rx-success"]');
    hide(errEl);
    hide(okEl);
    var value = function(role){
        var el = qs('[data-role="' + role + '"]');
        return el ? String(el.value || '').trim() : '';
    };
    var genericName = value('workspace-rx-generic-name');
    var dose = value('workspace-rx-dose');
    var frequency = value('workspace-rx-frequency');
    var formSel = qs('[data-role="workspace-rx-form-select"]');
    var routeSel = qs('[data-role="workspace-rx-route"]');
    var durationRaw = value('workspace-rx-duration-days');
    var visibleEl = qs('[data-role="workspace-rx-visible"]');
    var item = {
        generic_name: genericName,
        dose: dose,
        frequency: frequency,
        form: formSel ? formSel.value : 'tablet',
        route: routeSel ? routeSel.value : 'oral',
        instructions: value('workspace-rx-instructions')
    };
    if ( durationRaw !== '' ) {
        var duration = parseInt(durationRaw, 10);
        if ( !isNaN(duration) ) item.duration_days = duration;
    }
    setRxBusy(true);
    var submittedVisitId = visitId;
    // POST /doctor/portal/visits/{id}/prescriptions — selector headers only.
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/prescriptions', {
        items: [item],
        is_patient_visible: !!(visibleEl && visibleEl.checked)
    }, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setRxBusy(false);
        if ( r.status === 200 ) {
            var rx = (r.body && r.body.data) || r.body || {};
            if ( rx && rx.id ) upsertWorkspaceRx(rx);
            clearRxComposer();
            if ( okEl ) { okEl.textContent = 'نسخه با موفقیت به‌صورت پیش‌نویس ثبت شد.'; show(okEl); }
            return;
        }
        // Failed persistence is never presented as success.
        if ( errEl ) { errEl.textContent = rxFeedbackError(r); show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setRxBusy(false);
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در ثبت نسخه — دوباره تلاش کنید'; show(errEl); }
    });
}

function finalizeWorkspaceRx(btn, rxId){
    if ( !btn || btn.disabled ) return;
    var visitId = state.workspaceVisitId;
    if ( !visitId ) return;
    var errEl = qs('[data-role="workspace-rx-error"]');
    var okEl = qs('[data-role="workspace-rx-success"]');
    hide(errEl);
    hide(okEl);
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    var submittedVisitId = visitId;
    // POST /doctor/portal/prescriptions/{id}/finalize — selector only;
    // server resolves prescription->Visit ownership before delegating.
    api('POST', '/doctor/portal/prescriptions/' + encodeURIComponent(String(rxId)) + '/finalize', null, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if ( r.status === 200 ) {
            var rx = (r.body && r.body.data) || r.body || {};
            if ( rx && rx.id ) upsertWorkspaceRx(rx);
            if ( okEl ) { okEl.textContent = 'نسخه نهایی شد و فقط خواندنی است.'; show(okEl); }
            return;
        }
        // Repeat/invalid finalize keeps the established failure surface.
        if ( errEl ) { errEl.textContent = rxFeedbackError(r); show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در نهایی‌سازی — دوباره تلاش کنید'; show(errEl); }
    });
}

// ============ Visit Workspace — recommendations + follow-up (Phase 10) ============
// Writes go through the Doctor Portal recommendation/follow-up boundaries
// (/doctor/portal/visits/{id}/recommendations|follow-ups) with the portal
// nonce and trusted Clinic/Location selector headers ONLY. clinician_id is
// never sent; authority stays server-derived and the shared E12/E13 domain
// contract (types/validation/visibility/audit) is reused unchanged.

var REC_TYPE_LABELS = {
    diet: 'رژیم غذایی',
    rest: 'استراحت',
    activity: 'فعالیت',
    care: 'مراقبت',
    lab: 'آزمایش',
    followup: 'پیگیری',
    other: 'سایر'
};

function recTypeLabel(type){
    var key = String(type == null ? '' : type);
    if ( REC_TYPE_LABELS[key] ) return REC_TYPE_LABELS[key];
    return esc(key);
}

function setRecBusy(busy){
    state.workspaceRecBusy = !!busy;
    var btn = qs('[data-role="workspace-rec-submit"]');
    if ( btn ) {
        btn.disabled = !!busy;
        if ( busy ) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
    }
    var busyEl = qs('[data-role="workspace-rec-busy"]');
    if ( busyEl ) {
        if ( busy ) show(busyEl); else hide(busyEl);
    }
}

function setFuBusy(busy){
    state.workspaceFuBusy = !!busy;
    var btn = qs('[data-role="workspace-fu-submit"]');
    if ( btn ) {
        btn.disabled = !!busy;
        if ( busy ) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
    }
    var busyEl = qs('[data-role="workspace-fu-busy"]');
    if ( busyEl ) {
        if ( busy ) show(busyEl); else hide(busyEl);
    }
}

function clearRecComposer(){
    var text = qs('[data-role="workspace-rec-text"]');
    if ( text ) text.value = '';
    var type = qs('[data-role="workspace-rec-type"]');
    if ( type ) type.selectedIndex = 0;
    var visible = qs('[data-role="workspace-rec-visible"]');
    if ( visible ) visible.checked = true;
}

function clearFuComposer(){
    var date = qs('[data-role="workspace-fu-date"]');
    if ( date ) date.value = '';
    var interval = qs('[data-role="workspace-fu-interval-days"]');
    if ( interval ) interval.value = '';
    var reason = qs('[data-role="workspace-fu-reason"]');
    if ( reason ) reason.value = '';
    var needed = qs('[data-role="workspace-fu-needed"]');
    if ( needed ) needed.checked = true;
}

function recFuFeedbackError(r, fallback){
    if ( !r || !r.body ) return fallback;
    var msg = r.body.message;
    if ( typeof msg === 'string' && msg !== '' ) return msg.slice(0, 200);
    return fallback;
}

function renderWorkspaceRec(){
    var list = qs('[data-role="workspace-rec-list"]');
    var empty = qs('[data-role="workspace-rec-empty"]');
    var count = qs('[data-role="workspace-rec-count"]');
    if ( !list ) return;
    var recs = state.workspaceRec;
    if ( count ) count.textContent = recs.length + ' توصیه';
    if ( recs.length === 0 ) {
        list.innerHTML = '';
        show(empty);
        return;
    }
    hide(empty);
    list.innerHTML = recs.map(function(rec){
        return '<li class="cpms-doc-ws-rec-item" data-role="workspace-rec-item" data-rec-id="' + esc(rec.id) + '">' +
            '<div class="cpms-doc-ws-rec-head">' +
                '<span class="cpms-doc-badge vis-' + (rec.is_patient_visible ? 'patient' : 'private') + '">' +
                    (rec.is_patient_visible ? 'قابل مشاهده برای بیمار' : 'خصوصی') +
                '</span>' +
                '<span class="cpms-doc-ws-rec-type">' + recTypeLabel(rec.type) + '</span>' +
                '<span class="cpms-doc-ws-rec-date">' + esc(rec.created_at || '') + '</span>' +
            '</div>' +
            '<p class="cpms-doc-ws-rec-text">' + esc(rec.text || '') + '</p>' +
        '</li>';
    }).join('');
}

function renderWorkspaceFu(){
    var list = qs('[data-role="workspace-fu-list"]');
    var empty = qs('[data-role="workspace-fu-empty"]');
    var count = qs('[data-role="workspace-fu-count"]');
    if ( !list ) return;
    var followUps = state.workspaceFu;
    if ( count ) count.textContent = followUps.length + ' پیگیری';
    if ( followUps.length === 0 ) {
        list.innerHTML = '';
        show(empty);
        return;
    }
    hide(empty);
    list.innerHTML = followUps.map(function(fu){
        var when = [];
        if ( fu.suggested_date ) when.push('تاریخ: ' + esc(fu.suggested_date));
        if ( fu.interval_days != null && fu.interval_days !== '' ) when.push('بازه: ' + esc(fu.interval_days) + ' روز');
        if ( when.length === 0 ) when.push('بدون تاریخ/بازه');
        return '<li class="cpms-doc-ws-fu-item" data-role="workspace-fu-item" data-fu-id="' + esc(fu.id) + '" data-needed="' + (fu.is_needed ? '1' : '0') + '">' +
            '<div class="cpms-doc-ws-fu-head">' +
                '<span class="cpms-doc-badge">' +
                    (fu.is_needed ? 'نیاز به پیگیری' : 'نیازی نیست') +
                '</span>' +
                '<span class="cpms-doc-ws-fu-when">' + when.join(' · ') + '</span>' +
                '<span class="cpms-doc-ws-fu-status">' + esc(fu.status || '') + '</span>' +
            '</div>' +
            (fu.reason ? '<p class="cpms-doc-ws-fu-reason">' + esc(fu.reason) + '</p>' : '') +
        '</li>';
    }).join('');
}

function submitWorkspaceRec(){
    var visitId = state.workspaceVisitId;
    if ( !visitId || state.workspaceRecBusy ) return;
    var errEl = qs('[data-role="workspace-rec-error"]');
    var okEl = qs('[data-role="workspace-rec-success"]');
    var typeEl = qs('[data-role="workspace-rec-type"]');
    var textEl = qs('[data-role="workspace-rec-text"]');
    var visEl = qs('[data-role="workspace-rec-visible"]');
    if ( !typeEl || !textEl ) return;
    hide(errEl);
    hide(okEl);
    setRecBusy(true);
    var submittedVisitId = visitId;
    // POST /doctor/portal/visits/{id}/recommendations — selector headers only.
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/recommendations', {
        items: [{
            type: typeEl.value,
            text: String(textEl.value || '').trim(),
            is_patient_visible: !!(visEl && visEl.checked)
        }]
    }, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setRecBusy(false);
        if ( r.status === 200 ) {
            var data = (r.body && r.body.data) || {};
            if ( data && data.recommendations ) {
                state.workspaceRec = data.recommendations;
                renderWorkspaceRec();
            }
            clearRecComposer();
            if ( okEl ) { okEl.textContent = 'توصیه با موفقیت ثبت شد.'; show(okEl); }
            return;
        }
        // Failed persistence is never presented as success.
        if ( errEl ) { errEl.textContent = recFuFeedbackError(r, 'خطا در ثبت توصیه — دوباره تلاش کنید'); show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setRecBusy(false);
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در ثبت توصیه — دوباره تلاش کنید'; show(errEl); }
    });
}

function submitWorkspaceFu(){
    var visitId = state.workspaceVisitId;
    if ( !visitId || state.workspaceFuBusy ) return;
    var errEl = qs('[data-role="workspace-fu-error"]');
    var okEl = qs('[data-role="workspace-fu-success"]');
    var neededEl = qs('[data-role="workspace-fu-needed"]');
    var dateEl = qs('[data-role="workspace-fu-date"]');
    var intervalEl = qs('[data-role="workspace-fu-interval-days"]');
    var reasonEl = qs('[data-role="workspace-fu-reason"]');
    hide(errEl);
    hide(okEl);
    var body = { is_needed: !(neededEl && !neededEl.checked) };
    var dateVal = dateEl ? String(dateEl.value || '').trim() : '';
    var intervalVal = intervalEl ? String(intervalEl.value || '').trim() : '';
    if ( dateVal !== '' ) body.suggested_date = dateVal;
    if ( intervalVal !== '' ) {
        var interval = parseInt(intervalVal, 10);
        if ( !isNaN(interval) ) body.interval_days = interval;
    }
    if ( reasonEl && String(reasonEl.value || '').trim() !== '' ) {
        body.reason = String(reasonEl.value).trim();
    }
    setFuBusy(true);
    var submittedVisitId = visitId;
    // POST /doctor/portal/visits/{id}/follow-ups — selector headers only.
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/follow-ups', body, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setFuBusy(false);
        if ( r.status === 200 ) {
            var fu = (r.body && r.body.data) || null;
            if ( fu && fu.id ) {
                state.workspaceFu = [fu].concat(state.workspaceFu);
                renderWorkspaceFu();
            }
            clearFuComposer();
            if ( okEl ) { okEl.textContent = 'پیگیری با موفقیت ثبت شد.'; show(okEl); }
            return;
        }
        // Failed persistence is never presented as success.
        if ( errEl ) { errEl.textContent = recFuFeedbackError(r, 'خطا در ثبت پیگیری — دوباره تلاش کنید'); show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setFuBusy(false);
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در ثبت پیگیری — دوباره تلاش کنید'; show(errEl); }
    });
}

// ================= Visit Workspace — Medical Files (Phase 10) =================
// The list renders from the established record payload (data.files) with a
// bounded doctor allowlist (name/category/visibility/size/mime/created). The
// upload goes through the Doctor Portal file boundary as multipart FormData
// over REST (selector headers only; the server derives patient/Visit authority
// and the shared MedicalFileService stays authoritative for validation,
// storage and audit). Open/download is an authorized protected fetch ->
// blob -> temporary object URL -> save -> revoke — never a public/storage URL.

function resetFilesUi(){
    state.workspaceFiles = [];
    state.workspaceFilesBusy = false;
    workspaceVisitUploadBusy(false);
    var list = qs('[data-role="workspace-visit-files-list"]');
    if ( list ) list.innerHTML = '';
    var count = qs('[data-role="workspace-visit-files-count"]');
    if ( count ) count.textContent = '';
    show(qs('[data-role="workspace-visit-files-empty"]'));
    hide(qs('[data-role="workspace-visit-files-upload-busy"]'));
    hide(qs('[data-role="workspace-visit-files-upload-error"]'));
    hide(qs('[data-role="workspace-visit-files-upload-success"]'));
    var input = qs('[data-role="workspace-visit-files-upload-input"]');
    if ( input ) input.value = '';
}

function fileCategoryLabel(category){
    return {
        lab_result: 'نتیجه آزمایش',
        image: 'تصویر',
        scan: 'اسکن',
        document: 'سند',
        other: 'سایر'
    }[category] || category || '';
}

function fileSizeLabel(bytes){
    var n = parseInt(bytes, 10);
    if ( isNaN(n) || n < 0 ) return '';
    if ( n < 1024 ) return n + ' بایت';
    if ( n < 1048576 ) return Math.round(n / 1024) + ' کیلوبایت';
    return (Math.round(n / 10485.76) / 100) + ' مگابایت';
}

function renderWorkspaceFiles(){
    var list = qs('[data-role="workspace-visit-files-list"]');
    var empty = qs('[data-role="workspace-visit-files-empty"]');
    var count = qs('[data-role="workspace-visit-files-count"]');
    var files = state.workspaceFiles || [];
    if ( !list ) return;
    if ( count ) count.textContent = files.length ? String(files.length) : '';
    if ( !files.length ) {
        list.textContent = '';
        show(empty);
        return;
    }
    hide(empty);
    // Bounded allowlist rendering (id/original_filename/category/visibility/
    // file_size/mime_type/created_at) with output escaping; the temporary
    // object URL is created only at open time and revoked after use.
    list.innerHTML = files.map(function(f){
        var id = String(f.id);
        var label = String(f.original_filename == null ? '' : f.original_filename);
        return '<li class="cpms-doc-ws-file-item" data-role="workspace-visit-file-item" data-file-id="' + esc(id) + '">' +
            '<span class="cpms-doc-ws-file-name">' + esc(label) + '</span>' +
            '<span class="cpms-doc-ws-file-meta">' +
                esc(String(fileCategoryLabel(f.category))) + ' · ' +
                esc(String(noteVisibilityLabel(f.visibility))) + ' · ' +
                esc(String(fileSizeLabel(f.file_size))) + ' · ' +
                esc(String(f.mime_type || '')) + ' · ' +
                esc(String(f.created_at || '')) +
            '</span>' +
            '<button type="button" class="cpms-doc-btn cpms-doc-btn--ghost" data-role="workspace-visit-file-open" data-file-id="' + esc(id) + '" data-file-name="' + esc(label) + '">باز کردن / دانلود</button>' +
        '</li>';
    }).join('');
}

function workspaceFilesFeedbackError(r){
    var fallback = 'خطا در آپلود فایل — دوباره تلاش کنید';
    if ( !r || !r.body ) return fallback;
    var msg = r.body.message;
    if ( typeof msg === 'string' && msg !== '' ) return msg.slice(0, 200);
    return fallback;
}

function submitWorkspaceVisitFiles(){
    var visitId = state.workspaceVisitId;
    if ( !visitId || state.workspaceFilesBusy ) return;
    var input = qs('[data-role="workspace-visit-files-upload-input"]');
    var category = qs('[data-role="workspace-visit-files-category"]');
    var visibility = qs('[data-role="workspace-visit-files-visibility"]');
    var errEl = qs('[data-role="workspace-visit-files-upload-error"]');
    var okEl = qs('[data-role="workspace-visit-files-upload-success"]');
    hide(errEl);
    hide(okEl);
    if ( !input || !input.files || !input.files.length ) {
        if ( errEl ) { errEl.textContent = 'ابتدا یک فایل انتخاب کنید.'; show(errEl); }
        return;
    }
    // Client hints only (accept/labels) — the server is authoritative for real
    // MIME sniffing, extension match, size ceiling, category and visibility.
    var fd = new FormData();
    fd.append('file', input.files[0]);
    fd.append('category', category ? category.value : 'other');
    fd.append('visibility', visibility ? visibility.value : 'patient_visible');
    state.workspaceFilesBusy = true;
    workspaceVisitUploadBusy(true);
    show(qs('[data-role="workspace-visit-files-upload-busy"]'));
    var submittedVisitId = visitId;
    // POST /doctor/portal/visits/{id}/files — multipart FormData over REST;
    // no form navigation and no reload. Selector headers only.
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/files', fd, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        state.workspaceFilesBusy = false;
        workspaceVisitUploadBusy(false);
        hide(qs('[data-role="workspace-visit-files-upload-busy"]'));
        if ( r.status === 201 ) {
            var file = (r.body && r.body.data) || r.body || {};
            state.workspaceFiles = [file].concat(state.workspaceFiles || []);
            renderWorkspaceFiles();
            if ( input ) input.value = '';
            if ( okEl ) { okEl.textContent = 'فایل با موفقیت بارگذاری شد.'; show(okEl); }
            return;
        }
        // A rejected upload is never presented as success and adds no row.
        if ( errEl ) { errEl.textContent = workspaceFilesFeedbackError(r); show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        state.workspaceFilesBusy = false;
        workspaceVisitUploadBusy(false);
        hide(qs('[data-role="workspace-visit-files-upload-busy"]'));
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در آپلود فایل — دوباره تلاش کنید'; show(errEl); }
    });
}

function workspaceVisitFileOpen(fileId, filename){
    var visitId = state.workspaceVisitId;
    if ( !visitId || !fileId ) return;
    var errEl = qs('[data-role="workspace-visit-files-upload-error"]');
    hide(errEl);
    var openedVisitId = visitId;
    var headers = scopeHeaders();
    headers['X-WP-Nonce'] = CFG.nonce;
    // Authorized protected fetch (portal boundary) -> blob -> temporary
    // object URL -> save/open -> revoke after use. The object URL is never
    // persisted in the DOM/state and no public/storage URL is ever used.
    fetch(apiUrl('/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/files/' + encodeURIComponent(String(fileId)) + '/stream'), {
        method: 'GET',
        credentials: 'same-origin',
        headers: headers
    }).then(function(res){
        if ( state.workspaceVisitId !== openedVisitId ) return null;
        if ( !res.ok ) {
            return res.json().then(function(j){
                throw new Error((j && j.message) || 'خطا در دریافت فایل');
            }, function(){ throw new Error('خطا در دریافت فایل'); });
        }
        return res.blob();
    }).then(function(blob){
        if ( !blob ) return;
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = String(filename || 'file');
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
    }).catch(function(e){
        if ( state.workspaceVisitId !== openedVisitId ) return;
        if ( errEl ) { errEl.textContent = String((e && e.message) || 'خطا در دریافت فایل').slice(0, 200); show(errEl); }
    });
}

// ================= Visit Workspace — Chief Complaint + Visit Complete (Phase 10) =================
// Chief Complaint reuses the established portal note boundary with the
// established chief_complaint category and visibility options. Whether it is
// REQUIRED before Complete stays the server's per-Clinic policy: the 422 is
// surfaced here, never pre-empted or invented client-side. Complete goes
// through the Doctor Portal Visit boundary with the portal nonce and the
// trusted Clinic/Location selector headers only; authority, ownership, the
// Visit state machine, history and audit all stay server-side. No Reopen.

function hasChiefComplaint(){
    for ( var i = 0; i < state.workspaceNotes.length; i++ ) {
        if ( state.workspaceNotes[i] && state.workspaceNotes[i].category === 'chief_complaint' ) return true;
    }
    return false;
}

function setCcBusy(busy){
    state.workspaceCcBusy = !!busy;
    var btn = qs('[data-role="workspace-cc-submit"]');
    if ( btn ) {
        btn.disabled = !!busy;
        if ( busy ) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
    }
    var busyEl = qs('[data-role="workspace-cc-busy"]');
    if ( busyEl ) {
        if ( busy ) show(busyEl); else hide(busyEl);
    }
}

function renderConsultComplete(){
    var ccState = qs('[data-role="workspace-cc-state"]');
    if ( ccState ) {
        ccState.textContent = hasChiefComplaint()
            ? 'شکایت اصلی برای این ویزیت ثبت شده است.'
            : 'شکایت اصلی هنوز برای این ویزیت ثبت نشده است.';
    }
    var status = state.workspaceStatus;
    var btn = qs('[data-role="workspace-consult-complete-submit"]');
    var hint = qs('[data-role="workspace-consult-complete-hint"]');
    // Only a current consultation offers Complete; the server still decides.
    if ( btn ) btn.hidden = status !== 'in_consultation';
    if ( !hint ) return;
    if ( status === 'in_consultation' ) {
        hint.textContent = '';
        hide(hint);
        return;
    }
    hint.textContent = status === 'consultation_completed'
        ? 'این ویزیت پایان یافته است.'
        : 'پایان ویزیت پس از شروع ویزیت در دسترس است.';
    show(hint);
}

function resetConsultUi(){
    state.workspaceStatus = '';
    state.workspaceData = null;
    setCcBusy(false);
    setCompleteBusy(false);
    var ccText = qs('[data-role="workspace-cc-text"]');
    if ( ccText ) ccText.value = '';
    var ccVis = qs('[data-role="workspace-cc-visibility"]');
    if ( ccVis ) ccVis.selectedIndex = 0;
    var roles = ['workspace-cc-error', 'workspace-cc-success', 'workspace-consult-complete-error', 'workspace-consult-complete-success', 'workspace-consult-complete-hint', 'workspace-consult-complete-submit'];
    for ( var i = 0; i < roles.length; i++ ) hide(qs('[data-role="' + roles[i] + '"]'));
}

function submitWorkspaceCc(){
    var visitId = state.workspaceVisitId;
    if ( !visitId || state.workspaceCcBusy ) return;
    var errEl = qs('[data-role="workspace-cc-error"]');
    var okEl = qs('[data-role="workspace-cc-success"]');
    var textEl = qs('[data-role="workspace-cc-text"]');
    var visEl = qs('[data-role="workspace-cc-visibility"]');
    if ( !textEl || !visEl ) return;
    hide(errEl);
    hide(okEl);
    var visibility = visEl.value === 'doctor_private' ? 'doctor_private' : 'patient_visible';
    setCcBusy(true);
    var submittedVisitId = visitId;
    // Established note boundary; content validation stays server-side.
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/notes', {
        category: 'chief_complaint',
        visibility: visibility,
        content_text: String(textEl.value || '').trim()
    }, scopeHeaders()).then(function(r){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setCcBusy(false);
        if ( r.status === 200 ) {
            var note = (r.body && r.body.data) || r.body || {};
            if ( note && note.id ) {
                state.workspaceNotes = [note].concat(state.workspaceNotes);
                renderWorkspaceNotes();
            }
            textEl.value = '';
            hide(qs('[data-role="workspace-consult-complete-error"]'));
            renderConsultComplete();
            if ( okEl ) { okEl.textContent = 'شکایت اصلی با موفقیت ثبت شد.'; show(okEl); }
            return;
        }
        // Failed persistence is never presented as success.
        if ( errEl ) { errEl.textContent = recFuFeedbackError(r, 'خطا در ثبت شکایت اصلی — دوباره تلاش کنید'); show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setCcBusy(false);
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در ثبت شکایت اصلی — دوباره تلاش کنید'; show(errEl); }
    });
}

function setCompleteBusy(busy){
    state.workspaceCompleteBusy = !!busy;
    var btn = qs('[data-role="workspace-consult-complete-submit"]');
    if ( btn ) {
        btn.disabled = !!busy;
        if ( busy ) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
    }
    var busyEl = qs('[data-role="workspace-consult-complete-busy"]');
    if ( busyEl ) {
        if ( busy ) show(busyEl); else hide(busyEl);
    }
}

function submitWorkspaceComplete(){
    var visitId = state.workspaceVisitId;
    // Double-submit guard: one in-flight Complete per open Visit.
    if ( !visitId || state.workspaceCompleteBusy ) return;
    var errEl = qs('[data-role="workspace-consult-complete-error"]');
    var okEl = qs('[data-role="workspace-consult-complete-success"]');
    hide(errEl);
    hide(okEl);
    if ( !window.confirm('پایان این ویزیت ثبت شود؟ ویزیت از صف زنده خارج می‌شود.') ) return;
    setCompleteBusy(true);
    var submittedVisitId = visitId;
    var clinicId = state.selectedClinicId;
    var locationId = state.selectedLocationId;
    api('POST', '/doctor/portal/visits/' + encodeURIComponent(String(visitId)) + '/complete', null, scopeHeaders()).then(function(r){
        // Stale guard: another Visit or scope is open now — never paint it.
        if ( state.workspaceVisitId !== submittedVisitId || state.selectedClinicId !== clinicId || state.selectedLocationId !== locationId ) return;
        setCompleteBusy(false);
        var body = r.body || {};
        if ( r.status === 200 ) {
            var visit = body.data || {};
            state.workspaceStatus = String(visit.status || 'consultation_completed');
            var d = state.workspaceData;
            if ( d && d.visit ) { d.visit.status = state.workspaceStatus; renderWorkspaceHeader(d); }
            renderConsultComplete();
            if ( okEl ) { okEl.textContent = 'ویزیت با موفقیت پایان یافت.'; show(okEl); }
            loadTodayAndQueue();
            return;
        }
        var missing = (body.data && body.data.missing) || '';
        var msg = recFuFeedbackError(r, 'خطا در پایان ویزیت — دوباره تلاش کنید');
        if ( r.status === 422 && body.code === 'CLINIC_VALIDATION_FAILED' && missing === 'chief_complaint' ) {
            msg = 'پیش از پایان ویزیت، «شکایت اصلی بیمار» را در همین بخش ثبت کنید.';
            var ccText = qs('[data-role="workspace-cc-text"]');
            if ( ccText ) ccText.focus();
        } else if ( r.status === 409 && body.code === 'CLINIC_INVALID_TRANSITION' ) {
            msg = 'این ویزیت در وضعیت قابل پایان نیست (ممکن است قبلاً پایان یافته باشد).';
            loadTodayAndQueue();
        }
        if ( errEl ) { errEl.textContent = msg; show(errEl); }
    }).catch(function(){
        if ( state.workspaceVisitId !== submittedVisitId ) return;
        setCompleteBusy(false);
        if ( errEl ) { errEl.textContent = 'خطای ارتباط در پایان ویزیت — دوباره تلاش کنید'; show(errEl); }
    });
}

function loadContext(){
    return api('GET', '/doctor/portal/context').then(function(r){
        if ( r.status!==200 ) {
            throw new Error((r.body && r.body.message) || 'خطا در دریافت اطلاعات مطب‌ها');
        }
        var data = (r.body && r.body.data) || r.body || {};
        state.clinics = data.clinics || [];
        var doctor = data.doctor || null;
        var currentClinic = data.current_clinic || null;
        var currentLocation = data.current_location || null;
        if ( data.selected_clinic_id ) state.selectedClinicId = data.selected_clinic_id;
        else if ( currentClinic ) state.selectedClinicId = currentClinic.id;
        else if ( state.clinics.length===1 ) state.selectedClinicId = state.clinics[0].id;

        renderClinicSelector();

        if ( state.selectedClinicId ) {
            return loadLocations(state.selectedClinicId).then(function(){
                if ( state.locations.length===1 ) state.selectedLocationId = state.locations[0].id;
                else if ( data.selected_location_id ) state.selectedLocationId = data.selected_location_id;
                else if ( currentLocation ) state.selectedLocationId = currentLocation.id;

                renderLocationSelector();
                renderContext(doctor, currentClinic, currentLocation);

                if ( state.selectedClinicId && state.selectedLocationId ) {
                    return loadTodayAndQueue();
                }
            });
        } else {
            renderContext(doctor, null, null);
        }
    }).catch(function(e){
        showError(e.message || 'خطا');
    });
}

function loadLocations(clinicId){
    return api('GET', '/doctor/portal/locations?clinic_id=' + encodeURIComponent(clinicId)).then(function(r){
        if ( r.status!==200 ) throw new Error('خطا در دریافت شعبه‌ها');
        var data = (r.body && r.body.data) || r.body || {};
        state.locations = data.locations || [];
    });
}

function loadTodayAndQueue(){
    if ( !state.selectedClinicId || !state.selectedLocationId ) return Promise.resolve();
    var requestedLocation = state.selectedLocationId;
    var headers = {
        'X-CPMS-Clinic-Id': String(state.selectedClinicId),
        'X-CPMS-Location-Id': String(state.selectedLocationId)
    };
    return api('GET', '/doctor/today', null, headers).then(function(r){
        if ( state.selectedLocationId !== requestedLocation ) return;
        if ( r.status===400 ) {
            var body = r.body || {};
            var code = body.code || '';
            var data = body.data || {};
            if ( code==='CLINIC_SCOPE_REQUIRED' && data.field==='location_id' ) {
                var wrap = qs('[data-role="location-selector-wrap"]');
                show(wrap);
                state.today = null;
                hide(qs('[data-role="today-section"]'));
                hide(qs('[data-role="queue-section"]'));
                hide(qs('[data-role="appointments-section"]'));
                showError('لطفاً شعبه را انتخاب کنید.');
                return;
            }
            throw new Error(body.message || 'خطا در دریافت امروز');
        }
        if ( r.status===403 ) {
            throw new Error('دسترسی به این مطب/شعبه ندارید.');
        }
        if ( r.status!==200 ) throw new Error('خطا در دریافت امروز');
        var payload = (r.body && r.body.data) || r.body || {};
        state.today = payload;
        state.queue = payload.queue || [];
        state.lastEventId = payload.last_event_id || 0;
        renderToday();
        renderQueue();
        renderAppointments();
    }).catch(function(e){ showError(e.message); });
}

function pollQueue(){
    if ( state.pollPaused || document.hidden ) return;
    if ( !state.selectedClinicId || !state.selectedLocationId ) return;
    var headers = {
        'X-CPMS-Clinic-Id': String(state.selectedClinicId),
        'X-CPMS-Location-Id': String(state.selectedLocationId)
    };
    var since = state.lastEventId || 0;
    api('GET', '/rt/queue?since=' + encodeURIComponent(since), null, headers).then(function(r){
        if ( r.status===304 ) return;
        if ( r.status!==200 ) return;
        var data = (r.body && r.body.data) || r.body || {};
        var events = data.events || [];
        if ( events.length>0 ) {
            loadTodayAndQueue();
        }
        if ( data.last_event_id ) state.lastEventId = data.last_event_id;
    });
}

document.addEventListener('click', function(ev){
    var target = (ev.target && ev.target.closest) ? ev.target : null;
    var btn = target ? target.closest('[data-action]') : null;
    if ( btn ) {
        if ( btn.disabled ) return;
        var row = btn.closest('[data-role="queue-item"]');
        var visitId = btn.getAttribute('data-visit-id') || (row ? row.getAttribute('data-visit-id') : '');
        if ( !visitId ) return;
        ev.preventDefault();
        doQueueAction(visitId, btn.getAttribute('data-action'));
        return;
    }
    var closeBtn = target ? target.closest('[data-role="workspace-close"]') : null;
    if ( closeBtn ) {
        ev.preventDefault();
        closeWorkspace();
        return;
    }
    var completeBtn = target ? target.closest('[data-role="workspace-consult-complete-submit"]') : null;
    if ( completeBtn ) {
        ev.preventDefault();
        if ( completeBtn.disabled ) return;
        submitWorkspaceComplete();
        return;
    }
    var openFile = target ? target.closest('[data-role="workspace-visit-file-open"]') : null;
    if ( openFile ) {
        ev.preventDefault();
        if ( openFile.disabled ) return;
        workspaceVisitFileOpen(parseInt(openFile.getAttribute('data-file-id'), 10), openFile.getAttribute('data-file-name'));
        return;
    }
    var finBtn = target ? target.closest('[data-role="workspace-rx-finalize"]') : null;
    if ( finBtn ) {
        ev.preventDefault();
        if ( finBtn.disabled ) return;
        finalizeWorkspaceRx(finBtn, finBtn.getAttribute('data-rx-id'));
        return;
    }
    var item = target ? target.closest('[data-role="queue-item"]') : null;
    if ( !item ) return;
    var selectedVisitId = item.getAttribute('data-visit-id');
    if ( !selectedVisitId ) return;
    openWorkspace(selectedVisitId);
});

document.addEventListener('submit', function(ev){
    var filesForm = (ev.target && ev.target.closest) ? ev.target.closest('[data-role="workspace-visit-files-upload-form"]') : null;
    if ( filesForm ) {
        ev.preventDefault();
        submitWorkspaceVisitFiles();
        return;
    }
    var recForm = (ev.target && ev.target.closest) ? ev.target.closest('[data-role="workspace-rec-form"]') : null;
    if ( recForm ) {
        ev.preventDefault();
        submitWorkspaceRec();
        return;
    }
    var fuForm = (ev.target && ev.target.closest) ? ev.target.closest('[data-role="workspace-fu-form"]') : null;
    if ( fuForm ) {
        ev.preventDefault();
        submitWorkspaceFu();
        return;
    }
    var ccForm = (ev.target && ev.target.closest) ? ev.target.closest('[data-role="workspace-cc-form"]') : null;
    if ( ccForm ) {
        ev.preventDefault();
        submitWorkspaceCc();
        return;
    }
    var rxForm = (ev.target && ev.target.closest) ? ev.target.closest('[data-role="workspace-rx-form"]') : null;
    if ( rxForm ) {
        ev.preventDefault();
        submitWorkspaceRx();
        return;
    }
    var form = (ev.target && ev.target.closest) ? ev.target.closest('[data-role="workspace-note-form"]') : null;
    if ( !form ) return;
    ev.preventDefault();
    submitWorkspaceNote();
});

document.addEventListener('change', function(ev){
    var clinicSel = ev.target.closest('[data-role="clinic-select"]');
    if ( clinicSel ) {
        var val = clinicSel.value;
        state.selectedClinicId = val ? parseInt(val,10) : null;
        state.selectedLocationId = null;
        state.locations = [];
        state.today = null;
        state.queue = [];
        closeWorkspace();
        hide(qs('[data-role="today-section"]'));
        hide(qs('[data-role="queue-section"]'));
        hide(qs('[data-role="no-data"]'));
        if ( state.selectedClinicId ) {
            loadLocations(state.selectedClinicId).then(function(){
                renderLocationSelector();
                if ( state.locations.length===1 ) {
                    state.selectedLocationId = state.locations[0].id;
                    loadTodayAndQueue();
                }
            });
        }
        return;
    }
    var locSel = ev.target.closest('[data-role="location-select"]');
    if ( locSel ) {
        var v = locSel.value;
        state.selectedLocationId = v ? parseInt(v,10) : null;
        closeWorkspace();
        if ( state.selectedLocationId ) {
            loadTodayAndQueue();
        } else {
            state.today = null;
            hide(qs('[data-role="today-section"]'));
            hide(qs('[data-role="queue-section"]'));
            hide(qs('[data-role="appointments-section"]'));
        }
    }
});

document.addEventListener('visibilitychange', function(){
    var ind = qs('[data-role="live-indicator"]');
    if ( document.hidden ) {
        state.pollPaused = true;
        if ( ind ) { ind.classList.add('paused'); ind.textContent='● متوقف (تب مخفی)'; }
    } else {
        state.pollPaused = false;
        if ( ind ) { ind.classList.remove('paused'); ind.textContent='● زنده'; }
        pollQueue();
    }
});

function start(){
    if ( !CFG.is_doctor ) return;
    loadContext().then(function(){
        state.timer = setInterval(pollQueue, 5000);
    });
}
if ( document.readyState==='loading' ) document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
<?php if ( ! $cpms_staff_embed ) : ?>

    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags from wp_print_scripts (registered local handles only).
    echo $cpms_scripts_html;
    ?>
</body>
</html>
<?php endif; ?>

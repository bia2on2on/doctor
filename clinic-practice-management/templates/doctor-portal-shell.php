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


defined('ABSPATH') || exit;

use ClinicCore\Frontend\DoctorPortalShell;
use ClinicCore\Auth\RolesAndCapabilities;

if ( !function_exists('wp_get_current_user' )) {
    return;
}

DoctorPortalShell::register_handles();
DoctorPortalShell::enqueue_for_portal();

$cpms_user = wp_get_current_user(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
$cpms_logged_in = ($cpms_user instanceof WP_User && (int) $cpms_user->ID > 0); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceAfterOpen,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceBeforeClose -- legacy alignment
$cpms_is_doctor = $cpms_logged_in && DoctorPortalShell::isDoctorUser($cpms_user); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- legacy alignment
$cpms_login_name = $cpms_logged_in ? (string) $cpms_user->display_name : '';
if ( '' === $cpms_login_name && $cpms_logged_in ) {
    $cpms_login_name = (string) $cpms_user->user_login;
}

$cpms_portal_url = DoctorPortalShell::portal_url();
$cpms_login_url = wp_login_url( $cpms_portal_url ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- legacy alignment
$cpms_logout_url = $cpms_logged_in ? wp_logout_url( $cpms_portal_url ) : '';

$cpms_charset = (string) get_bloginfo('charset');
if ( '' === $cpms_charset ) {
    $cpms_charset = 'UTF-8';
}
$cpms_site_name = (string) get_bloginfo('name');

ob_start();
wp_print_styles([DoctorPortalShell::CSS_HANDLE]);
$cpms_styles_html = (string) ob_get_clean();

ob_start();
wp_print_scripts([DoctorPortalShell::JS_HANDLE]);
$cpms_scripts_html = (string) ob_get_clean();

$rest_root = untrailingslashit(rest_url('clinic/v1')); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- legacy, template var
$config = [ // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- legacy, template var
    'rest_root' => $rest_root, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment
    'nonce' => wp_create_nonce( 'wp_rest' ), // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment
    'portal_url' => $cpms_portal_url,
    'is_doctor' => $cpms_is_doctor, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment
];
$config_json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- legacy, template var
if ( !is_string($config_json ) || '' === $config_json) {
    $config_json = '{}'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- legacy, template var
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-cpms-doctor-portal-shell="v1" data-cpms-portal="doctor" data-cpms-doctor-portal="shell">
<head>
<meta charset="<?php echo esc_attr( $cpms_charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( 'پورتال پزشک' . ('' !== $cpms_site_name ? ' — ' . $cpms_site_name : '' )); ?></title>
<style>
:root { --cpms-primary: #2271b1; --cpms-bg: #f6f7f7; --cpms-card-bg: #fff; --cpms-border: #dcdcde; --cpms-text: #1d2327; --cpms-muted: #646970; }
* { box-sizing: border-box; }
body.cpms-doctor-portal-shell-body { margin: 0; font-family: Tahoma, Vazirmatn, sans-serif; background: var(--cpms-bg); color: var(--cpms-text); line-height: 1.6; direction: rtl; }
.cpms-doctor-portal-shell { max-width: 1366px; margin: 0 auto; padding: 12px; }
.cpms-doctor-portal-shell__header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; background: var(--cpms-card-bg); border: 1px solid var(--cpms-border); border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; }
.cpms-doctor-portal-shell__brand { display: flex; align-items: center; gap: 12px; }
.cpms-doctor-portal-shell__mark { background: var(--cpms-primary); color: #fff; border-radius: 6px; padding: 4px 8px; font-weight: 700; }
.cpms-doctor-portal-shell__titles p { margin: 0; }
.cpms-doctor-portal-shell__product { font-size: 13px; color: var(--cpms-muted); }
.cpms-doctor-portal-shell__title { font-size: 18px; font-weight: 700; margin: 0; }
.cpms-doctor-portal-shell__session { display: flex; align-items: center; gap: 8px; }
.cpms-doc-btn { display: inline-block; padding: 8px 14px; border-radius: 6px; border: 1px solid var(--cpms-border); background: var(--cpms-card-bg); text-decoration: none; color: var(--cpms-text); min-height: 44px; line-height: 1.2; }
.cpms-doc-btn--primary { background: var(--cpms-primary); color: #fff; border-color: var(--cpms-primary); }
.cpms-doc-btn--ghost { background: transparent; }
.cpms-doctor-portal-shell__notice { background: var(--cpms-card-bg); border: 1px solid var(--cpms-border); border-radius: 8px; padding: 20px; margin: 20px 0; }
.cpms-doc-context, .cpms-doc-selectors, .cpms-doc-today, .cpms-doc-queue, .cpms-doc-no-data { background: var(--cpms-card-bg); border: 1px solid var(--cpms-border); border-radius: 8px; padding: 16px; margin-bottom: 12px; }
.cpms-doc-context-details { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.cpms-doc-context-details span { background: #f0f6fc; border: 1px solid #2271b1; border-radius: 12px; padding: 2px 10px; font-size: 12px; }
.cpms-doc-selector { margin-bottom: 12px; }
.cpms-doc-selector label { display: block; font-weight: 600; margin-bottom: 4px; }
.cpms-doc-selector select { width: 100%; max-width: 400px; min-height: 44px; padding: 8px; border-radius: 6px; border: 1px solid var(--cpms-border); }
.cpms-doc-stats { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.cpms-doc-stat { background: #fff; border: 1px solid var(--cpms-border); border-inline-start: 4px solid var(--cpms-primary); border-radius: 6px; padding: 10px 14px; min-width: 110px; }
.cpms-doc-stat b { display: block; font-size: 20px; }
.cpms-doc-stat span { color: var(--cpms-muted); font-size: 12px; }
.cpms-doc-date { color: var(--cpms-muted); font-size: 13px; margin-bottom: 8px; }
.cpms-doc-queue-list { list-style: none; margin: 0; padding: 0; }
.cpms-doc-queue-list li { border: 1px solid var(--cpms-border); border-radius: 6px; padding: 10px 12px; margin: 6px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
.cpms-doc-badge { display: inline-block; font-size: 11px; border-radius: 10px; padding: 2px 8px; border: 1px solid; }
.cpms-doc-badge.express { background: #fcf0f1; color: #b32d2e; border-color: #b32d2e; }
.cpms-doc-badge.status-waiting { background: #fcf9e8; color: #996800; border-color: #996800; }
.cpms-doc-badge.status-called { background: #f0f6fc; color: #2271b1; border-color: #2271b1; }
.cpms-doc-badge.status-in_consultation { background: #edfaef; color: #00a32a; border-color: #00a32a; }
.cpms-doc-live { font-size: 12px; color: #00a32a; }
.cpms-doc-live.paused { color: #99a; }
.cpms-doc-loading, .cpms-doc-empty, .cpms-doc-error, .cpms-doc-no-data { padding: 12px; border-radius: 6px; margin: 8px 0; }
.cpms-doc-loading { background: #f0f6fc; }
.cpms-doc-empty { background: #f6f7f7; color: var(--cpms-muted); }
.cpms-doc-error { background: #fcf0f1; color: #b32d2e; border: 1px solid #b32d2e; }
.cpms-doctor-portal-shell__footer { text-align: center; color: var(--cpms-muted); font-size: 12px; margin-top: 20px; }
@media (max-width: 390px) { .cpms-doctor-portal-shell { padding: 8px; } .cpms-doc-stats { gap: 6px; } .cpms-doc-stat { min-width: 90px; padding: 8px 10px; } }
@media (min-width: 768px) { .cpms-doctor-portal-shell { padding: 16px; } .cpms-doc-selector select { max-width: 500px; } }
@media (min-width: 1366px) { .cpms-doctor-portal-shell { padding: 20px; } }
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
    <?php echo $cpms_is_doctor ? 'data-shell-user="doctor"' : ($cpms_logged_in ? 'data-shell-user="non-doctor"' : 'data-shell-user="anonymous"');
>
    <header class="cpms-doctor-portal-shell__header" role="banner" data-role="portal-header">
        <div class="cpms-doctor-portal-shell__brand">
            <span class="cpms-doctor-portal-shell__mark" aria-hidden="true">CPMS</span>
            <div class="cpms-doctor-portal-shell__titles">
                <p class="cpms-doctor-portal-shell__product">پورتال پزشک</p>
                <h1 class="cpms-doctor-portal-shell__title" data-role="portal-header-title">امروز پزشک — صف زنده</h1>
            </div>
        </div>
        <?php if ( $cpms_is_doctor ) :
            <div class="cpms-doctor-portal-shell__session">
                <span class="cpms-doctor-portal-shell__who" data-role="portal-user"><?php echo esc_html( $cpms_login_name ); ?></span>
                <a class="cpms-doc-btn cpms-doc-btn--ghost" data-role="portal-logout" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
            </div>
        <?php elseif ( $cpms_logged_in ) :
            <div class="cpms-doctor-portal-shell__session">
                <a class="cpms-doc-btn cpms-doc-btn--ghost" href="<?php echo esc_url( $cpms_logout_url ); ?>">خروج</a>
            </div>
        <?php endif; ?>
    </header>

    <main class="cpms-doctor-portal-shell__main" role="main" data-role="portal-main" id="cpms-doctor-portal-main">
        <?php if ( ! $cpms_logged_in ) :
            <section class="cpms-doctor-portal-shell__notice" role="status" data-role="portal-login">
                <h2>ورود به پورتال پزشک</h2>
                <p>برای مشاهده صف امروز و بیماران، وارد حساب پزشک خود شوید.</p>
                <p><a class="cpms-doc-btn cpms-doc-btn--primary" href="<?php echo esc_url( $cpms_login_url ); ?>">ورود</a></p>
            </section>
        <?php elseif ( ! $cpms_is_doctor ) :
            <section class="cpms-doctor-portal-shell__notice" role="alert" data-role="portal-access-denied">
                <h2>دسترسی پورتال پزشک</h2>
                <p>این پورتال فقط برای پزشکان با پروفایل فعال است. حساب شما نقش پزشک فعال ندارد یا به پروفایل پزشک متصل نیست.</p>
                <?php if ( current_user_can(RolesAndCapabilities::ROLE_SECRETARY )) :
                    <p>حساب منشی نمی‌تواند وارد پورتال پزشک شود.</p>
                <?php endif; ?>
                <p><a class="cpms-doc-btn cpms-doc-btn--primary" href="<?php echo esc_url( admin_url( )); ?>">بازگشت به پیشخوان</a></p>
            </section>
        <?php else : ?>
            <div id="cpms-doctor-portal-app" data-cpms-doctor-portal="app">
                <section class="cpms-doc-context" data-role="doctor-context" aria-live="polite">
                    <h2 data-role="context-title">در حال بارگذاری اطلاعات پزشک…</h2>
                    <div data-role="context-details" class="cpms-doc-context-details"></div>
                </section>

                <section class="cpms-doc-selectors" data-role="selectors">
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
                </section>

                <section class="cpms-doc-today" data-role="today-section" hidden>
                    <h2>امروز</h2>
                    <div data-role="today-date" class="cpms-doc-date"></div>
                    <div data-role="today-stats" class="cpms-doc-stats"></div>
                </section>

                <section class="cpms-doc-queue" data-role="queue-section" hidden>
                    <h2>صف زنده <span class="cpms-doc-live" data-role="live-indicator">● زنده</span></h2>
                    <div data-role="queue-loading" class="cpms-doc-loading">در حال دریافت صف…</div>
                    <div data-role="queue-empty" class="cpms-doc-empty" hidden>صف خالی است.</div>
                    <div data-role="queue-error" class="cpms-doc-error" role="alert" hidden></div>
                    <ul data-role="queue-list" class="cpms-doc-queue-list"></ul>
                </section>

                <section class="cpms-doc-no-data" data-role="no-data" hidden>
                    <h3>داده‌ای برای نمایش وجود ندارد</h3>
                    <p>هیچ شعبه فعالی برای این مطب یافت نشد یا هنوز انتخاب انجام نشده است.</p>
                </section>
            </div>
        <?php endif; ?>
    </main>

    <footer class="cpms-doctor-portal-shell__footer" role="contentinfo" data-role="portal-footer">
        <p class="cpms-doctor-portal-shell__footer-text">پورتال پزشک · CPMS — مستقل، بدون کروم wp-admin/theme</p>
    </footer>
</div>

<script type="application/json" class="cpms-doctor-portal__config"><?php echo $config_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON payload, already encoded via wp_json_encode ?></script>

<script>
(function(){
'use strict';
var cfgEl = document.querySelector('.cpms-doctor-portal__config');
if ( !cfgEl ) return;
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
    pollPaused: false
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
function esc(s){ return String(s==null?'':s).replace(/[&<>\"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c];}); }
function qs(sel){ return document.querySelector(sel); }
function show(el){ if( el ) el.hidden=false; }
function hide(el){ if( el ) el.hidden=true; }

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
            if ( v.express ) badge += ' <span class="cpms-doc-badge express">فوری</span>';
            var statusLabel = { waiting:'در صف', called:'فراخوانده', in_consultation:'در ویزیت' }[v.status] || v.status;
            var statusClass = 'status-' + (v.status||'');
            return '<li data-role="queue-item" data-visit-id="' + esc(v.id) + '">' +
                '<div><strong data-role="patient-name">' + esc(v.patient_name || 'بیمار') + '</strong> ' + badge +
                ' <span class="cpms-doc-badge ' + esc(statusClass) + '">' + esc(statusLabel) + '</span></div>' +
                '<div class="cpms-doc-muted" style="font-size:12px">' + esc(v.source||'') + ' · انتظار: ' + esc(v.waiting_since||'') + '</div>' +
                '</li>';
        }).join('');
    }
    if ( err && state.queue.length>0 ) hide(err);
}

function showError(msg){
    var err = qs('[data-role="queue-error"]');
    if ( !err ) return;
    err.textContent = msg;
    show(err);
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
    var headers = {
        'X-CPMS-Clinic-Id': String(state.selectedClinicId),
        'X-CPMS-Location-Id': String(state.selectedLocationId)
    };
    return api('GET', '/doctor/today', null, headers).then(function(r){
        if ( r.status===400 ) {
            var body = r.body || {};
            var code = body.code || '';
            var data = body.data || {};
            if ( code==='CLINIC_SCOPE_REQUIRED' && data.field==='location_id' ) {
                var wrap = qs('[data-role="location-selector-wrap"]');
                show(wrap);
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

document.addEventListener('change', function(ev){
    var clinicSel = ev.target.closest('[data-role="clinic-select"]');
    if ( clinicSel ) {
        var val = clinicSel.value;
        state.selectedClinicId = val ? parseInt(val,10) : null;
        state.selectedLocationId = null;
        state.locations = [];
        state.today = null;
        state.queue = [];
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
        if ( state.selectedLocationId ) {
            loadTodayAndQueue();
        } else {
            hide(qs('[data-role="today-section"]'));
            hide(qs('[data-role="queue-section"]'));
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

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags from wp_print_scripts (registered local handles only).
echo $cpms_scripts_html;
?>
</body>
</html>

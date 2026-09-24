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

$cpms_user = wp_get_current_user(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
$cpms_logged_in = ($cpms_user instanceof WP_User && (int) $cpms_user->ID > 0); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceAfterOpen,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceBeforeClose -- legacy alignment
$cpms_is_doctor = $cpms_logged_in && DoctorPortalShell::isDoctorUser($cpms_user); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- legacy alignment
$cpms_login_name = $cpms_logged_in ? (string) $cpms_user->display_name : '';
if ( '' === $cpms_login_name && $cpms_logged_in ) {
    $cpms_login_name = (string) $cpms_user->user_login;
}

$cpms_portal_url = DoctorPortalShell::portal_url();
$cpms_login_url = wp_login_url( $cpms_portal_url ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
$cpms_logout_url = $cpms_logged_in ? wp_logout_url( $cpms_portal_url ) : '';

$cpms_charset = (string) get_bloginfo('charset'); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
if ( '' === $cpms_charset ) {
    $cpms_charset = 'UTF-8';
}
$cpms_site_name = (string) get_bloginfo('name'); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS

ob_start();
wp_print_styles([DoctorPortalShell::CSS_HANDLE]); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
$cpms_styles_html = (string) ob_get_clean();

ob_start();
wp_print_scripts([DoctorPortalShell::JS_HANDLE]); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
$cpms_scripts_html = (string) ob_get_clean();

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
    var btn = (ev.target && ev.target.closest) ? ev.target.closest('[data-action]') : null;
    if ( !btn || btn.disabled ) return;
    var row = btn.closest('[data-role="queue-item"]');
    var visitId = btn.getAttribute('data-visit-id') || (row ? row.getAttribute('data-visit-id') : '');
    if ( !visitId ) return;
    ev.preventDefault();
    doQueueAction(visitId, btn.getAttribute('data-action'));
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

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script tags from wp_print_scripts (registered local handles only).
echo $cpms_scripts_html;
?>
</body>
</html>

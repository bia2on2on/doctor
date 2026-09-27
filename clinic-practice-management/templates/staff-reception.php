<?php
/**
 * Phase 11 Slice 1 — Staff Portal reception module (Arrival Board), embed mode.
 *
 * Mounted by templates/staff-portal-shell.php inside the canonical shared
 * Staff Portal shell. One trusted Clinic + one operational Location, today's
 * booked patients for that Location, ONE arrival action ("arrived / ready")
 * that runs the EXISTING check-in + enqueue transitions in valid order, and a
 * read-only queue status board (waiting / called / in_consultation / skipped)
 * reflecting the EXISTING doctor call/start/recall/skip actions.
 *
 * Phase 11 Slice 2 adds a read-only Clinic patient search panel: it calls the
 * reception search boundary (a thin adapter over the ESTABLISHED
 * PatientService::search contract), renders the bounded search presentation
 * (masked national ID) and lets the secretary mark ONE result as selected.
 * The selection is presentation-only: it is never posted anywhere, creates no
 * authority, and is not coupled to the arrival action. Location never filters
 * this Clinic-scoped search.
 *
 * Phase 11 Slice 3 adds a SMALL create-patient form on the same panel. Create
 * posts to the reception adapter over PatientService::create, then places the
 * new patient into the EXISTING read-only selected presentation. No walk-in,
 * appointment, check-in, queue or Visit is started.
 *
 * Embed contract (same as the doctor module): the shell owns the document,
 * header and navigation; this module contributes its <main>, ONE inline style
 * block, ONE runtime config JSON and ONE inline script, and closes with
 * </main></div> exactly like the doctor module. No theme calls, no footer.
 *
 * No frontend framework, no build step, no CDN, no external assets.
 *
 * @package ClinicCore
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use ClinicCore\Frontend\StaffPortalShell;

if ( empty( $cpms_staff_embed ) ) {
    // Standalone access is fail-closed: reception only lives inside the shell.
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>پورتال کارکنان</title></head>
<body>
<p>این صفحه فقط از طریق پورتال کارکنان در دسترس است.</p>
</body>
</html>
    <?php
    return;
}

if ( ! class_exists( 'ClinicCore\Frontend\StaffPortalShell' ) ) {
    require_once __DIR__ . '/../src/Frontend/StaffPortalShell.php';
}

$cpms_reception_cfg = [
    'rest_root'    => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce'        => esc_attr( wp_create_nonce( 'wp_rest' ) ),
    'portal_url'   => esc_url_raw( StaffPortalShell::portal_url() ),
    'is_reception' => true,
];
?>
<style>
.cpms-staff-reception { max-width: 1100px; }
.cpms-staff-reception .cpms-sr-panel { background: #fff; border: 1px solid var(--cpms-border); border-radius: 10px; padding: 12px; margin: 0 0 12px; }
.cpms-staff-reception .cpms-sr-top { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
.cpms-staff-reception .cpms-sr-chip { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 4px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #f7fafb; font-size: 0.86rem; max-width: 100%; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-chip--primary { background: var(--cpms-primary); border-color: var(--cpms-primary); color: #fff; }
.cpms-staff-reception .cpms-sr-select { min-height: 38px; max-width: 100%; padding: 4px 8px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.9rem; }
.cpms-staff-reception .cpms-sr-required { font-size: 0.82rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-board { display: grid; gap: 12px; }
.cpms-staff-reception .cpms-sr-stats { display: flex; flex-wrap: wrap; gap: 8px; }
.cpms-staff-reception .cpms-sr-stat { flex: 1 1 140px; min-width: 0; background: #f7fafb; border: 1px solid var(--cpms-border); border-radius: 8px; padding: 8px 10px; }
.cpms-staff-reception .cpms-sr-stat b { display: block; font-size: 1.1rem; }
.cpms-staff-reception .cpms-sr-table { width: 100%; border-collapse: collapse; }
.cpms-staff-reception .cpms-sr-table th, .cpms-staff-reception .cpms-sr-table td { padding: 8px 6px; border-bottom: 1px solid var(--cpms-border); text-align: right; font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-table tr:last-child td { border-bottom: 0; }
.cpms-staff-reception .cpms-sr-name { font-weight: bold; }
.cpms-staff-reception .cpms-sr-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--cpms-border); font-size: 0.78rem; white-space: nowrap; }
.cpms-staff-reception .cpms-sr-badge--waiting { background: #fff7e6; border-color: #e8c37e; }
.cpms-staff-reception .cpms-sr-badge--called { background: #e8f1ff; border-color: #8db4e8; }
.cpms-staff-reception .cpms-sr-badge--in_consultation { background: #e7f6ec; border-color: #7dc494; }
.cpms-staff-reception .cpms-sr-badge--checked_in { background: #f0eefe; border-color: #a89ee8; }
.cpms-staff-reception .cpms-sr-badge--skipped { background: #fbecec; border-color: #e09a9a; }
.cpms-staff-reception .cpms-sr-badge--express { background: #fdf1e3; border-color: #e2b184; }
.cpms-staff-reception .cpms-sr-btn { min-height: 40px; min-width: 96px; padding: 6px 14px; border: 1px solid var(--cpms-primary); border-radius: 8px; background: var(--cpms-primary); color: #fff; font-size: 0.9rem; cursor: pointer; }
.cpms-staff-reception .cpms-sr-btn[disabled] { opacity: 0.55; cursor: default; }
.cpms-staff-reception .cpms-sr-btn--ghost { background: #fff; color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-status { font-size: 0.92rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-status--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-status--ok { color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-empty { color: var(--cpms-muted); padding: 10px 4px; }
.cpms-staff-reception .cpms-sr-live { min-height: 1.4em; }
.cpms-staff-reception .cpms-sr-search h2 { margin: 0 0 8px; font-size: 1rem; }
.cpms-staff-reception .cpms-sr-search-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.cpms-staff-reception .cpms-sr-search-field { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; min-width: 0; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-search-input { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-search-input:focus-visible, .cpms-staff-reception .cpms-sr-search-result:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-search-state { margin: 8px 0 0; font-size: 0.86rem; color: var(--cpms-muted); min-height: 1.3em; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-search-state--error { color: #a12828; }
.cpms-staff-reception .cpms-sr-search-results { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 6px; max-height: 360px; overflow-y: auto; }
.cpms-staff-reception .cpms-sr-search-result { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: baseline; width: 100%; min-height: 44px; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; font-size: 0.9rem; text-align: right; cursor: pointer; }
.cpms-staff-reception .cpms-sr-search-result[aria-pressed="true"] { border-color: var(--cpms-primary); background: #eef7f6; box-shadow: inset 3px 0 0 var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-search-meta { color: var(--cpms-muted); font-size: 0.84rem; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-search-meta bdi { unicode-bidi: isolate; }
.cpms-staff-reception .cpms-sr-search-selected { margin: 10px 0 0; padding: 10px; border: 1px solid var(--cpms-primary); border-radius: 8px; background: #f3faf9; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; justify-content: space-between; }
.cpms-staff-reception .cpms-sr-search-selected-body { min-width: 0; overflow-wrap: anywhere; }
.cpms-staff-reception .cpms-sr-search-selected-note { display: block; font-size: 0.8rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-search-selected-note--ok { color: var(--cpms-primary); }
.cpms-staff-reception .cpms-sr-create-open { margin: 8px 0 0; }
.cpms-staff-reception .cpms-sr-create { margin: 10px 0 0; padding: 10px; border: 1px dashed var(--cpms-border); border-radius: 8px; background: #fbfcfd; }
.cpms-staff-reception .cpms-sr-create h3 { margin: 0 0 8px; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-create-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.cpms-staff-reception .cpms-sr-create-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; font-size: 0.86rem; }
.cpms-staff-reception .cpms-sr-create-field--wide { grid-column: 1 / -1; }
.cpms-staff-reception .cpms-sr-create-optional { margin: 10px 0 0; padding-top: 8px; border-top: 1px solid var(--cpms-border); }
.cpms-staff-reception .cpms-sr-create-optional-legend { display: block; margin: 0 0 6px; font-size: 0.78rem; color: var(--cpms-muted); }
.cpms-staff-reception .cpms-sr-create-req { color: #a12828; }
.cpms-staff-reception .cpms-sr-create input, .cpms-staff-reception .cpms-sr-create select { min-height: 40px; width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font-size: 0.95rem; }
.cpms-staff-reception .cpms-sr-create input:focus-visible, .cpms-staff-reception .cpms-sr-create select:focus-visible { outline: 2px solid var(--cpms-primary); outline-offset: 1px; }
.cpms-staff-reception .cpms-sr-create-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 0; }
.cpms-staff-reception .cpms-sr-create-error { margin: 8px 0 0; font-size: 0.86rem; color: #a12828; overflow-wrap: anywhere; }
@media (max-width: 768px) {
    .cpms-staff-reception .cpms-sr-top { flex-direction: column; align-items: stretch; }
    .cpms-staff-reception .cpms-sr-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cpms-staff-reception .cpms-sr-table thead { display: none; }
    .cpms-staff-reception .cpms-sr-table, .cpms-staff-reception .cpms-sr-table tbody, .cpms-staff-reception .cpms-sr-table tr, .cpms-staff-reception .cpms-sr-table td { display: block; width: 100%; }
    .cpms-staff-reception .cpms-sr-table tr { border-bottom: 1px solid var(--cpms-border); padding: 8px 0; }
    .cpms-staff-reception .cpms-sr-table td { border-bottom: 0; padding: 3px 4px; }
    .cpms-staff-reception .cpms-sr-create-grid { grid-template-columns: 1fr; }
}
</style>
<div class="cpms-staff-reception" data-role="reception-app">
<main id="cpms-staff-reception-main" class="cpms-staff-reception-main" role="main" data-cpms-staff-module="reception">
    <section class="cpms-sr-panel cpms-sr-top" data-role="sr-top">
        <div class="cpms-sr-chips" data-role="sr-scope">
            <span class="cpms-sr-chip cpms-sr-chip--primary" data-role="sr-clinic" title="کلینیک مورد اعتماد">—</span>
            <label class="cpms-sr-chip" data-role="sr-location-wrap">
                <span>موقعیت:</span>
                <select class="cpms-sr-select" data-role="sr-location-select" aria-label="انتخاب موقعیت عملیاتی" hidden></select>
                <span class="cpms-sr-chip" data-role="sr-location" hidden>—</span>
            </label>
        </div>
        <div class="cpms-sr-chip" data-role="sr-date">—</div>
    </section>

    <section class="cpms-sr-panel cpms-sr-status cpms-sr-live" data-role="sr-status" role="status" aria-live="polite">در حال بارگذاری…</section>

    <section class="cpms-sr-panel cpms-sr-search" data-role="sr-search" aria-labelledby="cpms-sr-search-title">
        <h2 id="cpms-sr-search-title">جستجوی بیمار در کلینیک</h2>
        <form class="cpms-sr-search-form" data-role="sr-search-form" role="search" novalidate>
            <label class="cpms-sr-search-field" for="cpms-sr-search-input">
                <span>نام، نام خانوادگی، موبایل، کد ملی یا شمارهٔ پرونده</span>
                <input type="search" id="cpms-sr-search-input" class="cpms-sr-search-input" data-role="sr-search-input" maxlength="64" autocomplete="off" spellcheck="false" enterkeyhint="search" aria-describedby="cpms-sr-search-state">
            </label>
            <button type="submit" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-search-submit">جستجو</button>
        </form>
        <p class="cpms-sr-search-state" id="cpms-sr-search-state" data-role="sr-search-state" role="status" aria-live="polite">برای جستجو دست‌کم ۲ نویسه وارد کنید.</p>
        <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost cpms-sr-create-open" data-role="sr-create-open" hidden>ثبت بیمار جدید</button>
        <form class="cpms-sr-create" data-role="sr-create" hidden novalidate>
            <h3>ثبت بیمار تازه</h3>
            <div class="cpms-sr-create-grid">
                <label class="cpms-sr-create-field" for="cpms-sr-create-first-name">
                    <span>نام <span class="cpms-sr-create-req">*</span></span>
                    <input type="text" id="cpms-sr-create-first-name" name="first_name" data-role="sr-create-first-name" maxlength="120" autocomplete="off" required>
                </label>
                <label class="cpms-sr-create-field" for="cpms-sr-create-last-name">
                    <span>نام خانوادگی <span class="cpms-sr-create-req">*</span></span>
                    <input type="text" id="cpms-sr-create-last-name" name="last_name" data-role="sr-create-last-name" maxlength="120" autocomplete="off" required>
                </label>
                <label class="cpms-sr-create-field cpms-sr-create-field--wide" for="cpms-sr-create-mobile">
                    <span>موبایل <span class="cpms-sr-create-req">*</span></span>
                    <input type="tel" id="cpms-sr-create-mobile" name="mobile" data-role="sr-create-mobile" maxlength="20" inputmode="tel" autocomplete="off" required>
                </label>
            </div>
            <div class="cpms-sr-create-optional">
                <span class="cpms-sr-create-optional-legend">اختیاری</span>
                <div class="cpms-sr-create-grid">
                    <label class="cpms-sr-create-field" for="cpms-sr-create-national-id">
                        <span>کد ملی</span>
                        <input type="text" id="cpms-sr-create-national-id" name="national_id" data-role="sr-create-national-id" maxlength="10" inputmode="numeric" autocomplete="off">
                    </label>
                    <label class="cpms-sr-create-field" for="cpms-sr-create-birth-date">
                        <span>تاریخ تولد</span>
                        <input type="date" id="cpms-sr-create-birth-date" name="birth_date" data-role="sr-create-birth-date" autocomplete="off">
                    </label>
                    <label class="cpms-sr-create-field" for="cpms-sr-create-gender">
                        <span>جنسیت</span>
                        <select id="cpms-sr-create-gender" name="gender" data-role="sr-create-gender">
                            <option value="">—</option>
                            <option value="female">زن</option>
                            <option value="male">مرد</option>
                            <option value="other">دیگر</option>
                            <option value="unknown">نامشخص</option>
                        </select>
                    </label>
                </div>
            </div>
            <p class="cpms-sr-create-error" data-role="sr-create-error" role="alert" hidden></p>
            <div class="cpms-sr-create-actions">
                <button type="submit" class="cpms-sr-btn" data-role="sr-create-submit">ثبت بیمار</button>
                <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-create-cancel">انصراف</button>
            </div>
        </form>
        <ul class="cpms-sr-search-results" data-role="sr-search-results" aria-label="نتایج جستجوی بیمار"></ul>
        <div class="cpms-sr-search-selected" data-role="sr-search-selected" hidden>
            <div class="cpms-sr-search-selected-body">
                <strong>بیمار انتخاب‌شده:</strong> <span data-role="sr-search-selected-text"></span>
                <span class="cpms-sr-search-selected-note" data-role="sr-search-selected-note">فقط برای شناسایی — هیچ تغییری در پرونده یا نوبت ایجاد نمی‌شود.</span>
            </div>
            <button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" data-role="sr-search-clear">لغو انتخاب</button>
        </div>
    </section>

    <section class="cpms-sr-board" data-role="sr-board">
        <div class="cpms-sr-stats" data-role="sr-stats" hidden>
            <div class="cpms-sr-stat" data-role="sr-stat-waiting"><b>—</b><span>در انتظار</span></div>
            <div class="cpms-sr-stat" data-role="sr-stat-called"><b>—</b><span>فراخوانی‌شده</span></div>
            <div class="cpms-sr-stat" data-role="sr-stat-in_consultation"><b>—</b><span>در ویزیت</span></div>
            <div class="cpms-sr-stat" data-role="sr-stat-skipped"><b>—</b><span>لغو امروز</span></div>
        </div>

        <div class="cpms-sr-panel" data-role="sr-appointments-panel">
            <table class="cpms-sr-table" data-role="sr-appointments">
                <thead>
                    <tr>
                        <th scope="col">ساعت</th>
                        <th scope="col">بیمار</th>
                        <th scope="col">وضعیت</th>
                        <th scope="col">اقدام</th>
                    </tr>
                </thead>
                <tbody data-role="sr-appointments-body">
                    <tr><td colspan="4" class="cpms-sr-empty" data-role="sr-appointments-empty">در حال بارگذاری…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="cpms-sr-panel" data-role="sr-queue-panel">
            <table class="cpms-sr-table" data-role="sr-queue">
                <thead>
                    <tr>
                        <th scope="col">صف امروز</th>
                        <th scope="col">بیمار</th>
                        <th scope="col">وضعیت</th>
                    </tr>
                </thead>
                <tbody data-role="sr-queue-body">
                    <tr><td colspan="3" class="cpms-sr-empty" data-role="sr-queue-empty">—</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</main>
</div>
<script type="application/json" id="cpms-staff-reception-config"><?php echo wp_json_encode( $cpms_reception_cfg ); ?></script>
<script>
(function () {
    'use strict';

    var CONFIG = { restRoot: '', nonce: '', portalUrl: '' };
    try {
        var raw = document.getElementById('cpms-staff-reception-config').textContent;
        var parsed = JSON.parse(raw);
        CONFIG.restRoot = String(parsed.rest_root || '').replace(/\/$/, '');
        CONFIG.nonce = String(parsed.nonce || '');
        CONFIG.portalUrl = String(parsed.portal_url || '');
    } catch (e) {
        return;
    }

    var POLL_MS = 5000;

    var state = {
        clinicId: null,
        clinicName: '',
        locationId: null,
        locationName: '',
        locations: [],
        date: null,
        busy: false
    };

    function el(role) {
        return document.querySelector('[data-role="' + role + '"]');
    }

    var statusKind = null;
    var statusSticky = false;

    function setStatus(text, kind, sticky) {
        var node = el('sr-status');
        statusKind = text ? kind : null;
        statusSticky = Boolean(text && sticky);
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-panel cpms-sr-status cpms-sr-live' + (kind === 'error' ? ' cpms-sr-status--error' : (kind === 'ok' ? ' cpms-sr-status--ok' : ''));
    }

    function scopeHeaders() {
        var headers = {};
        if (state.clinicId) {
            headers['X-CPMS-Clinic-Id'] = String(state.clinicId);
        }
        if (state.locationId) {
            headers['X-CPMS-Location-Id'] = String(state.locationId);
        }
        return headers;
    }

    function api(path, options) {
        options = options || {};
        var headers = Object.assign({ 'X-WP-Nonce': CONFIG.nonce, 'Accept': 'application/json' }, scopeHeaders(), options.headers || {});
        var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin', cache: 'no-store' };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        return fetch(CONFIG.restRoot + path, init).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                return { status: response.status, ok: response.ok, body: body };
            });
        });
    }

    function payloadOf(body) {
        return (body && typeof body === 'object' && body.data && typeof body.data === 'object') ? body.data : (body || {});
    }

    function errorCodeOf(body) {
        return String((body && body.code) || '');
    }

    function errorMessageOf(body, fallback) {
        return String((body && body.message) || fallback);
    }

    function statusLabel(status) {
        var labels = {
            waiting: 'در انتظار',
            called: 'فراخوانی‌شده',
            in_consultation: 'در ویزیت',
            checked_in: 'حضوریافته',
            skipped: 'لغو امروز',
            completed: 'پایان‌یافته'
        };
        return labels[status] || status || '—';
    }

    function statusBadge(status, extra) {
        return '<span class="cpms-sr-badge cpms-sr-badge--' + (status || 'none') + (extra ? ' ' + extra : '') + '">' + escapeHtml(statusLabel(status)) + '</span>';
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function renderScope() {
        var clinicNode = el('sr-clinic');
        if (clinicNode) {
            clinicNode.textContent = state.clinicName || 'کلینیک';
        }
        var select = el('sr-location-select');
        var chip = el('sr-location');
        if (select && chip) {
            if (state.locations.length > 1) {
                select.hidden = false;
                chip.hidden = true;
                var current = String(state.locationId || '');
                if (select.options.length !== state.locations.length) {
                    select.innerHTML = '';
                    for (var i = 0; i < state.locations.length; i += 1) {
                        var option = document.createElement('option');
                        option.value = String(state.locations[i].id);
                        option.textContent = String(state.locations[i].name || ('موقعیت ' + state.locations[i].id));
                        select.appendChild(option);
                    }
                }
                if (current) {
                    select.value = current;
                }
            } else {
                select.hidden = true;
                chip.hidden = false;
                chip.textContent = state.locationName || '—';
            }
        }
        var dateNode = el('sr-date');
        if (dateNode) {
            dateNode.textContent = state.date ? ('تاریخ عملیاتی: ' + state.date) : 'تاریخ عملیاتی: —';
        }
    }

    function renderStats(stats) {
        var wrap = el('sr-stats');
        if (!wrap) {
            return;
        }
        wrap.hidden = false;
        var map = { waiting: 'sr-stat-waiting', called: 'sr-stat-called', in_consultation: 'sr-stat-in_consultation', skipped: 'sr-stat-skipped' };
        Object.keys(map).forEach(function (key) {
            var node = el(map[key]);
            if (node && node.firstElementChild) {
                node.firstElementChild.textContent = String((stats && stats[key]) || 0);
            }
        });
    }

    function renderAppointments(rows) {
        var body = el('sr-appointments-body');
        if (!body) {
            return;
        }
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="cpms-sr-empty">نوبتی برای امروز در این موقعیت ثبت نشده است.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i];
            var canArrive = !row.visit_status && (row.status === 'pending' || row.status === 'confirmed');
            var canRecover = row.visit_status === 'checked_in';
            // Presentation-only clarity: the real appointment state badge is
            // kept as-is; a booked row without a visit also shows that the
            // patient has NOT yet been received (no state semantics change).
            var badge = row.visit_status ? statusBadge(row.visit_status) : '<span class="cpms-sr-badge">' + escapeHtml(row.status === 'confirmed' ? 'تاییدشده' : 'رزرو شده') + '</span> <span class="cpms-sr-badge cpms-sr-badge--not-received">هنوز پذیرش نشده</span>';
            var express = row.express ? ' <span class="cpms-sr-badge cpms-sr-badge--express">فوری</span>' : '';
            // Recoverable partial (checked_in) keeps a clear completion action;
            // queued/in-service rows expose NO retry action at all.
            var action = '';
            if (canRecover) {
                action = '<button type="button" class="cpms-sr-btn cpms-sr-btn--recover" data-role="sr-recover" data-patient-id="' + escapeHtml(row.patient_id) + '" data-appointment-id="' + escapeHtml(row.id) + '" title="حضور ثبت شده است؛ قرارگیری در صف انتظار هنوز کامل نشده">تکمیل ورود به صف</button>';
            } else if (canArrive) {
                action = '<button type="button" class="cpms-sr-btn" data-role="sr-arrive" data-patient-id="' + escapeHtml(row.patient_id) + '" data-appointment-id="' + escapeHtml(row.id) + '">حضوریافت / آماده شد</button>';
            }
            html += '<tr data-role="sr-row" data-appointment-id="' + escapeHtml(row.id) + '">' +
                '<td data-role="sr-row-time">' + escapeHtml(row.time) + '</td>' +
                '<td class="cpms-sr-name" data-role="sr-row-name">' + escapeHtml(row.patient_name) + express + '</td>' +
                '<td data-role="sr-row-status">' + badge + '</td>' +
                '<td data-role="sr-row-action">' + action + '</td>' +
                '</tr>';
        }
        body.innerHTML = html;
    }

    function renderQueue(rows) {
        var body = el('sr-queue-body');
        if (!body) {
            return;
        }
        if (!rows || rows.length === 0) {
            body.innerHTML = '<tr><td colspan="3" class="cpms-sr-empty">صف امروز خالی است.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i];
            html += '<tr data-role="sr-queue-row">' +
                '<td data-role="sr-queue-pos">' + escapeHtml(row.queue_position !== null && row.queue_position !== undefined ? row.queue_position : (i + 1)) + '</td>' +
                '<td class="cpms-sr-name">' + escapeHtml(row.patient_name || ('بیمار ' + (row.patient_id || ''))) + '</td>' +
                '<td>' + statusBadge(row.status) + '</td>' +
                '</tr>';
        }
        body.innerHTML = html;
    }

    function renderBoard(data) {
        state.date = data.date || null;
        if (data.location_id) {
            state.locationId = Number(data.location_id);
        }
        if (data.location_name) {
            state.locationName = String(data.location_name);
        }
        renderScope();
        renderStats(data.stats);
        renderAppointments(data.appointments || []);
        renderQueue(data.queue || []);
    }

    function loadContext() {
        return api('/staff/portal/reception/context').then(function (result) {
            var data = payloadOf(result.body);
            if (!result.ok) {
                setStatus('خطا در دریافت محدودهٔ کاری: ' + errorMessageOf(result.body, ''), 'error');
                return;
            }
            state.locations = (data.eligible_locations || []).map(function (loc) {
                return { id: Number(loc.id), name: String(loc.name || '') };
            });
            if (data.current_clinic) {
                state.clinicId = Number(data.current_clinic.id);
                state.clinicName = String(data.current_clinic.name || '');
            } else if (data.selected_clinic_id) {
                state.clinicId = Number(data.selected_clinic_id);
            }
            if (data.current_location) {
                state.locationId = Number(data.current_location.id);
                state.locationName = String(data.current_location.name || '');
            } else if (data.selected_location_id) {
                state.locationId = Number(data.selected_location_id);
            }
            renderScope();
        }).catch(function () {
            setStatus('خطای شبکه هنگام دریافت محدودهٔ کاری.', 'error');
        });
    }

    function loadBoard(silent) {
        if (state.locations.length > 1 && !state.locationId) {
            renderScope();
            setStatus('بیش از یک موقعیت واجد شرایط موجود است — انتخاب موقعیت الزامی است.', 'error');
            return Promise.resolve();
        }
        return api('/staff/portal/reception/board').then(function (result) {
            if (!result.ok) {
                var code = errorCodeOf(result.body);
                if (code === 'CLINIC_SCOPE_REQUIRED') {
                    setStatus('انتخاب موقعیت عملیاتی الزامی است.', 'error');
                    return;
                }
                if (code === 'CLINIC_SCOPE_UNAVAILABLE') {
                    setStatus('امکان تعیین محدودهٔ کلینیک معتبر نیست.', 'error');
                    return;
                }
                if (!silent) {
                    setStatus('خطا در دریافت تختهٔ پذیرش: ' + errorMessageOf(result.body, ''), 'error');
                }
                return;
            }
            renderBoard(payloadOf(result.body));
            // Keep durable success/status messages across silent refreshes. A
            // silent success may clear only a NON-sticky error (e.g. a network
            // hiccup on refresh) — the actionable partial-arrival message is
            // sticky until an explicit/new action or a successful recovery.
            if (!silent || (statusKind === 'error' && !statusSticky)) {
                setStatus('', null);
            }
        }).catch(function () {
            setStatus('خطای شبکه هنگام دریافت تختهٔ پذیرش.', 'error');
        });
    }

    function arrive(button) {
        if (state.busy) {
            return;
        }
        state.busy = true;
        button.disabled = true;
        var patientId = Number(button.getAttribute('data-patient-id') || 0);
        var appointmentId = Number(button.getAttribute('data-appointment-id') || 0);
        var isRecovery = button.getAttribute('data-role') === 'sr-recover';
        setStatus(isRecovery ? 'در حال تکمیل ورود به صف…' : 'در حال ثبت حضور…', null);
        api('/staff/portal/reception/arrivals', { method: 'POST', body: { patient_id: patientId, appointment_id: appointmentId } })
            .then(function (result) {
                var data = payloadOf(result.body);
                var arrival = data.arrival || {};
                var code = (result.body && result.body.code) || (data && data.code) || '';
                if (!result.ok) {
                    if (code === 'CLINIC_ARRIVAL_INCOMPLETE' || arrival.complete === false) {
                        // Actionable partial: arrival recorded, queue placement
                        // still needs completion. Sticky — a silent board
                        // refresh must not erase it; only a new action or a
                        // successful recovery clears it.
                        var partialErr = (arrival.enqueue_error && arrival.enqueue_error.message) ? arrival.enqueue_error.message : 'افزودن به صف انجام نشد';
                        setStatus('حضور ثبت شد، اما قرارگیری در صف انجام نشد (' + partialErr + ') — برای تکمیل، «تکمیل ورود به صف» را بزنید.', 'error', true);
                    } else {
                        setStatus((isRecovery ? 'تکمیل ورود به صف انجام نشد: ' : 'ثبت حضور انجام نشد: ') + errorMessageOf(result.body, ''), 'error');
                    }
                    return loadBoard(true);
                }
                if (arrival.complete) {
                    setStatus('حضور ثبت شد و بیمار در صف انتظار قرار گرفت.', 'ok');
                } else {
                    // Defensive: never claim success over an incomplete arrival.
                    var partial = (arrival.enqueue_error && arrival.enqueue_error.message) ? arrival.enqueue_error.message : 'افزودن به صف انجام نشد';
                    setStatus('حضور ثبت شد، اما قرارگیری در صف انجام نشد (' + partial + ') — برای تکمیل، «تکمیل ورود به صف» را بزنید.', 'error', true);
                }
                return loadBoard(true);
            })
            .catch(function () {
                setStatus('خطای شبکه هنگام ثبت حضور.', 'error');
            })
            .then(function () {
                state.busy = false;
            });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target && target.getAttribute) {
            var role = target.getAttribute('data-role');
            if (role === 'sr-arrive' || role === 'sr-recover') {
                arrive(target);
            }
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.getAttribute && target.getAttribute('data-role') === 'sr-location-select') {
            state.locationId = Number(target.value || 0);
            state.locationName = '';
            setStatus('در حال بارگذاری…', null);
            loadBoard(false);
        }
    });

    // ---- Phase 11 Slice 2: read-only Clinic patient search ----
    // Calls the reception adapter over the ESTABLISHED patient search contract
    // (min 2 chars, server-bounded limit, masked national ID). Debounced; a
    // stale response never overwrites a newer query. The selected result is a
    // local, presentation-only copy of the bounded search row: it is never
    // posted anywhere and is not coupled to the arrival action.
    var SEARCH_MIN = 2;
    var SEARCH_DEBOUNCE_MS = 350;
    var SEARCH_LIMIT_HINT = 25;
    var SEARCH_IDLE = 'برای جستجو دست‌کم ۲ نویسه وارد کنید.';
    var search = { timer: null, seq: 0, lastQuery: '', results: [], selected: null, created: null };
    var createBusy = false;

    function faDigits(value) {
        return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(Number(d)); });
    }

    function setSearchState(text, isError) {
        var node = el('sr-search-state');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cpms-sr-search-state' + (isError ? ' cpms-sr-search-state--error' : '');
    }

    function patientLabel(p) {
        var name = String((p.first_name || '') + ' ' + (p.last_name || '')).trim();
        return name || ('بیمار ' + p.id);
    }

    function patientMeta(p) {
        var meta = [];
        if (p.mrn) {
            meta.push('پرونده: <bdi>' + escapeHtml(p.mrn) + '</bdi>');
        }
        if (p.mobile) {
            meta.push('موبایل: <bdi>' + escapeHtml(p.mobile) + '</bdi>');
        }
        if (p.national_id) {
            meta.push('کد ملی: <bdi>' + escapeHtml(p.national_id) + '</bdi>');
        }
        return meta.join(' · ');
    }

    function syncSearchPressed() {
        var buttons = document.querySelectorAll('[data-role="sr-search-result"]');
        var selectedId = search.selected ? String(search.selected.id) : '';
        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].setAttribute('aria-pressed', buttons[i].getAttribute('data-patient-id') === selectedId ? 'true' : 'false');
        }
    }

    function renderSearchResults() {
        var list = el('sr-search-results');
        if (!list) {
            return;
        }
        var html = '';
        for (var i = 0; i < search.results.length; i += 1) {
            var p = search.results[i];
            html += '<li><button type="button" class="cpms-sr-search-result" data-role="sr-search-result" data-patient-id="' + escapeHtml(p.id) + '" aria-pressed="false">' +
                '<span class="cpms-sr-name" data-role="sr-search-result-name">' + escapeHtml(patientLabel(p)) + '</span>' +
                '<span class="cpms-sr-search-meta">' + patientMeta(p) + '</span>' +
                '</button></li>';
        }
        list.innerHTML = html;
        syncSearchPressed();
    }

    function setSelectedNote(created) {
        var note = el('sr-search-selected-note');
        if (!note) {
            return;
        }
        if (created) {
            note.textContent = 'بیمار با موفقیت ثبت شد — فقط برای شناسایی؛ هیچ نوبت یا ویزیتی ایجاد نشد.';
            note.className = 'cpms-sr-search-selected-note cpms-sr-search-selected-note--ok';
        } else {
            note.textContent = 'فقط برای شناسایی — هیچ تغییری در پرونده یا نوبت ایجاد نمی‌شود.';
            note.className = 'cpms-sr-search-selected-note';
        }
    }

    function renderSelected() {
        var box = el('sr-search-selected');
        var text = el('sr-search-selected-text');
        if (!box || !text) {
            return;
        }
        if (!search.selected) {
            text.innerHTML = '';
            box.hidden = true;
            setSelectedNote(false);
            return;
        }
        text.innerHTML = '<span class="cpms-sr-name">' + escapeHtml(patientLabel(search.selected)) + '</span> <span class="cpms-sr-search-meta">' + patientMeta(search.selected) + '</span>';
        box.hidden = false;
        setSelectedNote(Boolean(search.created && search.selected && String(search.created) === String(search.selected.id)));
    }

    function runSearch(force) {
        var input = el('sr-search-input');
        var q = input ? String(input.value || '').trim() : '';
        if (search.timer) {
            window.clearTimeout(search.timer);
            search.timer = null;
        }
        if (q.length < SEARCH_MIN) {
            search.seq += 1;
            search.lastQuery = '';
            search.results = [];
            renderSearchResults();
            setSearchState(SEARCH_IDLE, false);
            return;
        }
        if (!force && q === search.lastQuery) {
            return;
        }
        search.lastQuery = q;
        search.seq += 1;
        var seq = search.seq;
        setSearchState('در حال جستجو…', false);
        var path = '/staff/portal/reception/patients/search' + (CONFIG.restRoot.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
        api(path).then(function (result) {
            if (seq !== search.seq) {
                return;
            }
            if (!result.ok) {
                search.lastQuery = '';
                search.results = [];
                renderSearchResults();
                if (result.status === 403) {
                    setSearchState('دسترسی جستجوی بیمار برای شما فعال نیست.', true);
                } else {
                    setSearchState('جستجو انجام نشد: ' + errorMessageOf(result.body, ''), true);
                }
                return;
            }
            var rows = (result.body && Array.isArray(result.body.data)) ? result.body.data : [];
            search.results = rows;
            renderSearchResults();
            if (rows.length === 0) {
                setSearchState('بیماری با این مشخصات در این کلینیک یافت نشد. می‌توانید بیمار تازه را ثبت کنید.', false);
                var createOpenMiss = el('sr-create-open');
                if (createOpenMiss && el('sr-create') && el('sr-create').hidden) {
                    createOpenMiss.hidden = false;
                }
            } else if (rows.length >= SEARCH_LIMIT_HINT) {
                setSearchState(faDigits(rows.length) + ' نتیجهٔ نخست نمایش داده شد؛ برای یافتن دقیق‌تر، عبارت کامل‌تری وارد کنید.', false);
            } else {
                setSearchState(faDigits(rows.length) + ' بیمار یافت شد. برای شناسایی، روی بیمار موردنظر بزنید.', false);
            }
        }).catch(function () {
            if (seq !== search.seq) {
                return;
            }
            search.lastQuery = '';
            setSearchState('خطای شبکه هنگام جستجو.', true);
        });
    }

    function selectSearchResult(button) {
        var id = String(button.getAttribute('data-patient-id') || '');
        for (var i = 0; i < search.results.length; i += 1) {
            if (String(search.results[i].id) === id) {
                search.selected = search.results[i];
                break;
            }
        }
        syncSearchPressed();
        renderSelected();
    }

    (function bindSearch() {
        var panel = el('sr-search');
        var form = el('sr-search-form');
        var input = el('sr-search-input');
        if (!panel || !form || !input) {
            return;
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            runSearch(true);
        });
        input.addEventListener('input', function () {
            if (search.timer) {
                window.clearTimeout(search.timer);
            }
            if (String(input.value || '').trim().length < SEARCH_MIN) {
                runSearch(false);
                return;
            }
            search.timer = window.setTimeout(function () {
                search.timer = null;
                runSearch(false);
            }, SEARCH_DEBOUNCE_MS);
        });
        panel.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var result = target.closest('[data-role="sr-search-result"]');
            if (result) {
                selectSearchResult(result);
                return;
            }
            if (target.closest('[data-role="sr-search-clear"]')) {
                search.selected = null;
                syncSearchPressed();
                renderSelected();
                input.focus();
                return;
            }
            if (target.closest('[data-role="sr-create-open"]')) {
                openCreate();
                return;
            }
            if (target.closest('[data-role="sr-create-cancel"]')) {
                closeCreate(false);
            }
        });
        var createForm = el('sr-create');
        if (createForm) {
            createForm.addEventListener('submit', submitCreate);
        }
        var createOpen = el('sr-create-open');
        if (createOpen) {
            createOpen.hidden = false;
        }
    }());

    function setCreateError(text) {
        var node = el('sr-create-error');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.hidden = !text;
    }

    function openCreate() {
        var form = el('sr-create');
        if (!form) {
            return;
        }
        form.hidden = false;
        var open = el('sr-create-open');
        if (open) {
            open.hidden = true;
        }
        setCreateError('');
        var first = el('sr-create-first-name');
        if (first) {
            first.focus();
        }
    }

    function closeCreate(reset) {
        var form = el('sr-create');
        if (form) {
            form.hidden = true;
            if (reset) {
                form.reset();
            }
        }
        var open = el('sr-create-open');
        if (open) {
            open.hidden = false;
        }
        setCreateError('');
    }

    function submitCreate(event) {
        event.preventDefault();
        if (createBusy) {
            return;
        }
        var firstNode = el('sr-create-first-name');
        var lastNode = el('sr-create-last-name');
        var mobileNode = el('sr-create-mobile');
        var first = firstNode ? String(firstNode.value || '').trim() : '';
        var last = lastNode ? String(lastNode.value || '').trim() : '';
        var mobile = mobileNode ? String(mobileNode.value || '').trim() : '';
        if (!first || !last || !mobile) {
            setCreateError('نام، نام خانوادگی و موبایل الزامی است.');
            return;
        }
        var body = { first_name: first, last_name: last, mobile: mobile };
        var nidNode = el('sr-create-national-id');
        var birthNode = el('sr-create-birth-date');
        var genderNode = el('sr-create-gender');
        var nid = nidNode ? String(nidNode.value || '').trim() : '';
        var birth = birthNode ? String(birthNode.value || '').trim() : '';
        var gender = genderNode ? String(genderNode.value || '').trim() : '';
        if (nid) {
            body.national_id = nid;
        }
        if (birth) {
            body.birth_date = birth;
        }
        if (gender) {
            body.gender = gender;
        }
        createBusy = true;
        var submit = el('sr-create-submit');
        if (submit) {
            submit.disabled = true;
        }
        setCreateError('');
        api('/staff/portal/reception/patients', { method: 'POST', body: body }).then(function (result) {
            createBusy = false;
            if (submit) {
                submit.disabled = false;
            }
            if (!result.ok) {
                setCreateError(errorMessageOf(result.body, 'ثبت بیمار انجام نشد.'));
                return;
            }
            var row = payloadOf(result.body);
            if (!row || !row.id) {
                setCreateError('ثبت بیمار انجام نشد.');
                return;
            }
            search.created = row.id;
            search.selected = row;
            syncSearchPressed();
            renderSelected();
            closeCreate(true);
            setSearchState('بیمار با موفقیت ثبت شد.', false);
        }).catch(function () {
            createBusy = false;
            if (submit) {
                submit.disabled = false;
            }
            setCreateError('خطای شبکه هنگام ثبت بیمار.');
        });
    }

    function poll() {
        loadBoard(true).then(function () {
            window.setTimeout(poll, POLL_MS);
        }, function () {
            window.setTimeout(poll, POLL_MS);
        });
    }

    setStatus('در حال بارگذاری…', null);
    loadContext().then(function () {
        return loadBoard(false);
    }).then(poll);
}());
</script>

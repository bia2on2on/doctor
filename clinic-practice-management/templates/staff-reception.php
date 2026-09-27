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
@media (max-width: 768px) {
    .cpms-staff-reception .cpms-sr-top { flex-direction: column; align-items: stretch; }
    .cpms-staff-reception .cpms-sr-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cpms-staff-reception .cpms-sr-table thead { display: none; }
    .cpms-staff-reception .cpms-sr-table, .cpms-staff-reception .cpms-sr-table tbody, .cpms-staff-reception .cpms-sr-table tr, .cpms-staff-reception .cpms-sr-table td { display: block; width: 100%; }
    .cpms-staff-reception .cpms-sr-table tr { border-bottom: 1px solid var(--cpms-border); padding: 8px 0; }
    .cpms-staff-reception .cpms-sr-table td { border-bottom: 0; padding: 3px 4px; }
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

    function setStatus(text, kind) {
        var node = el('sr-status');
        statusKind = text ? kind : null;
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
            var badge = row.visit_status ? statusBadge(row.visit_status) : '<span class="cpms-sr-badge">' + escapeHtml(row.status === 'confirmed' ? 'تاییدشده' : 'رزرو شده') + '</span>';
            var express = row.express ? ' <span class="cpms-sr-badge cpms-sr-badge--express">فوری</span>' : '';
            var action = canArrive
                ? '<button type="button" class="cpms-sr-btn" data-role="sr-arrive" data-patient-id="' + escapeHtml(row.patient_id) + '" data-appointment-id="' + escapeHtml(row.id) + '">حضوریافت / آماده شد</button>'
                : '<button type="button" class="cpms-sr-btn cpms-sr-btn--ghost" disabled>' + escapeHtml(statusLabel(row.visit_status)) + '</button>';
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
            // Keep durable success/status messages across silent refreshes; a
            // silent success after an error means the board recovered.
            if (!silent || statusKind === 'error') {
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
        setStatus('در حال ثبت حضور…', null);
        api('/staff/portal/reception/arrivals', { method: 'POST', body: { patient_id: patientId, appointment_id: appointmentId } })
            .then(function (result) {
                var data = payloadOf(result.body);
                var arrival = data.arrival || {};
                if (!result.ok) {
                    setStatus('ثبت حضور انجام نشد: ' + errorMessageOf(result.body, ''), 'error');
                    return;
                }
                if (arrival.complete) {
                    setStatus('حضور ثبت شد و بیمار در صف انتظار قرار گرفت.', 'ok');
                } else {
                    // Honest partial: check-in committed, enqueue did not.
                    var partial = (arrival.enqueue_error && arrival.enqueue_error.message) ? arrival.enqueue_error.message : 'افزودن به صف انجام نشد';
                    setStatus('حضور ثبت شد؛ افزودن به صف ناقص ماند (' + partial + ') — وضعیت فعلی: ' + statusLabel(arrival.outcome), 'error');
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
        if (target && target.getAttribute && target.getAttribute('data-role') === 'sr-arrive') {
            arrive(target);
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

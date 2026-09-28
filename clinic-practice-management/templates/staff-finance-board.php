<?php
/**
 * Phase 12 Slice 1 — read-only finance board inside the independent Staff Portal.
 * No wp-admin template, finance controls, or write-capability UI is included.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( empty( $cpms_staff_embed ) ) {
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>پورتال کارکنان</title></head>
<body><p>این صفحه فقط از طریق پورتال کارکنان در دسترس است.</p></body>
</html>
    <?php
    return;
}
?>
<style>
.cpms-finance-board { max-width: 1120px; display: grid; gap: 12px; }
.cpms-finance-board__panel { min-width: 0; padding: 14px; border: 1px solid var(--cpms-border); border-radius: 10px; background: #fff; }
.cpms-finance-board__heading { margin: 0 0 8px; font-size: 1.15rem; }
.cpms-finance-board__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin: 0; color: var(--cpms-muted); }
.cpms-finance-board__location { min-height: 40px; max-width: 100%; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font: inherit; }
.cpms-finance-board__status { min-height: 1.5em; margin: 0; overflow-wrap: anywhere; }
.cpms-finance-board__status[data-kind="error"] { color: #a12828; }
.cpms-finance-board__table-wrap { width: 100%; overflow-x: auto; }
.cpms-finance-board__table { width: 100%; border-collapse: collapse; }
.cpms-finance-board__table th, .cpms-finance-board__table td { padding: 9px 8px; border-bottom: 1px solid var(--cpms-border); text-align: right; vertical-align: top; overflow-wrap: anywhere; }
.cpms-finance-board__table th { color: var(--cpms-muted); font-size: .84rem; white-space: nowrap; }
.cpms-finance-board__table tr:last-child td { border-bottom: 0; }
.cpms-finance-board__patient { font-weight: 700; }
.cpms-finance-board__invoice { display: grid; gap: 2px; min-width: 125px; }
.cpms-finance-board__invoice strong { font-weight: 700; }
.cpms-finance-board__muted { color: var(--cpms-muted); }
.cpms-finance-board__empty { padding: 16px 8px !important; text-align: center !important; color: var(--cpms-muted); }
@media (max-width: 760px) {
    .cpms-finance-board__panel { padding: 11px; }
    .cpms-finance-board__table thead { display: none; }
    .cpms-finance-board__table, .cpms-finance-board__table tbody, .cpms-finance-board__table tr, .cpms-finance-board__table td { display: block; width: 100%; }
    .cpms-finance-board__table tr { padding: 9px 0; border-bottom: 1px solid var(--cpms-border); }
    .cpms-finance-board__table tr:last-child { border-bottom: 0; }
    .cpms-finance-board__table td { display: grid; grid-template-columns: minmax(95px, 34%) minmax(0, 1fr); gap: 8px; padding: 4px 2px; border: 0; }
    .cpms-finance-board__table td::before { content: attr(data-label); color: var(--cpms-muted); font-size: .82rem; }
    .cpms-finance-board__empty { display: block !important; }
}
</style>
<main class="cpms-finance-board" data-role="finance-board" aria-labelledby="cpms-finance-board-title">
    <section class="cpms-finance-board__panel">
        <h2 class="cpms-finance-board__heading" id="cpms-finance-board-title">ویزیت‌های در انتظار پرداخت</h2>
        <div class="cpms-finance-board__meta">
            <label>موقعیت عملیاتی
                <select class="cpms-finance-board__location" data-role="finance-location" aria-label="انتخاب موقعیت عملیاتی" hidden></select>
            </label>
            <span data-role="finance-date">تاریخ عملیاتی: —</span>
        </div>
    </section>
    <p class="cpms-finance-board__panel cpms-finance-board__status" data-role="finance-status" role="status" aria-live="polite">در حال دریافت تختهٔ مالی…</p>
    <section class="cpms-finance-board__panel" aria-label="تختهٔ ویزیت‌های در انتظار پرداخت">
        <div class="cpms-finance-board__table-wrap">
            <table class="cpms-finance-board__table">
                <thead><tr><th>بیمار</th><th>پزشک</th><th>تاریخ و ساعت عملیاتی</th><th>وضعیت ویزیت</th><th>فاکتور</th></tr></thead>
                <tbody data-role="finance-rows"><tr><td class="cpms-finance-board__empty" colspan="5">در حال بارگذاری…</td></tr></tbody>
            </table>
        </div>
    </section>
</main>
<script type="application/json" id="cpms-finance-board-config"><?php echo wp_json_encode( [
    'rest_root' => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce' => esc_attr( wp_create_nonce( 'wp_rest' ) ),
] ); ?></script>
<script>
(function () {
    'use strict';
    var configNode = document.getElementById('cpms-finance-board-config');
    var config;
    try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
    var root = String(config.rest_root || '').replace(/\/$/, '');
    var state = { clinicId: null, locationId: null, locations: [], busy: false };
    var locationSelect = document.querySelector('[data-role="finance-location"]');
    var rowsNode = document.querySelector('[data-role="finance-rows"]');
    var statusNode = document.querySelector('[data-role="finance-status"]');
    var dateNode = document.querySelector('[data-role="finance-date"]');

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function setStatus(message, kind) {
        statusNode.textContent = message || '';
        statusNode.dataset.kind = kind || '';
    }
    function headers() {
        var value = { 'X-WP-Nonce': String(config.nonce || ''), 'Accept': 'application/json' };
        if (state.clinicId) value['X-CPMS-Clinic-Id'] = String(state.clinicId);
        if (state.locationId) value['X-CPMS-Location-Id'] = String(state.locationId);
        return value;
    }
    function request(path) {
        return fetch(root + path, { method: 'GET', headers: headers(), credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    return { ok: response.ok, body: body };
                });
            });
    }
    function payload(result) { return result.body && result.body.data ? result.body.data : {}; }
    function showError(result, fallback) {
        var code = result.body && result.body.code || '';
        if (code === 'CLINIC_SCOPE_REQUIRED') return 'انتخاب موقعیت عملیاتی الزامی است.';
        if (code === 'CLINIC_SCOPE_UNAVAILABLE') return 'محدودهٔ عملیاتی معتبر در دسترس نیست.';
        if (result.body && result.body.message) return result.body.message;
        return fallback;
    }
    function invoiceStatusLabel(status) {
        var labels = { open: 'باز', partial: 'پرداخت بخشی', paid: 'پرداخت کامل' };
        return labels[status] || status;
    }
    function renderInvoice(invoice) {
        if (!invoice) return '<span class="cpms-finance-board__muted">فاکتور ثبت نشده</span>';
        return '<span class="cpms-finance-board__invoice"><strong>' + esc(invoiceStatusLabel(invoice.status)) + '</strong>' +
            '<span>کل: ' + esc(invoice.total) + ' ' + esc(invoice.currency) + '</span>' +
            '<span>پرداخت‌شده: ' + esc(invoice.paid) + ' ' + esc(invoice.currency) + '</span>' +
            '<span>باقی‌مانده: ' + esc(invoice.remaining) + ' ' + esc(invoice.currency) + '</span></span>';
    }
    function renderBoard(data) {
        var visits = Array.isArray(data.visits) ? data.visits : [];
        dateNode.textContent = 'تاریخ عملیاتی: ' + String(data.date || '—') + (data.jalali_date ? ' · ' + String(data.jalali_date) : '');
        if (data.location_id) state.locationId = Number(data.location_id);
        var labels = ['بیمار', 'پزشک', 'تاریخ و ساعت عملیاتی', 'وضعیت ویزیت', 'فاکتور'];
        if (!visits.length) {
            rowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="5">برای این موقعیت در انتظار پرداختی وجود ندارد.</td></tr>';
        } else {
            rowsNode.innerHTML = visits.map(function (visit) {
                var values = [
                    '<span class="cpms-finance-board__patient">' + esc(visit.patient_name) + '</span>',
                    esc(visit.clinician_name),
                    esc(visit.operational_date) + (visit.jalali_date ? ' · ' + esc(visit.jalali_date) : '') + ' · ' + esc(visit.operational_time),
                    'در انتظار پرداخت',
                    renderInvoice(visit.invoice)
                ];
                return '<tr>' + values.map(function (value, index) {
                    return '<td data-label="' + labels[index] + '">' + value + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }
        setStatus(data.has_more ? 'فهرست به ۱۰۰ مورد محدود شده است؛ همهٔ نتایج در این نما نشان داده نمی‌شوند.' : (visits.length ? 'تخته فقط‌خواندنی است.' : ''), '');
    }
    function loadBoard() {
        if (state.busy) return;
        if (state.locations.length > 1 && !state.locationId) {
            setStatus('برای مشاهدهٔ اطلاعات مالی، یک موقعیت را انتخاب کنید.', 'error');
            return;
        }
        state.busy = true;
        locationSelect.disabled = true;
        request('/staff/portal/finance/awaiting-payment').then(function (result) {
            if (!result.ok) { setStatus(showError(result, 'دریافت تختهٔ مالی ناموفق بود.'), 'error'); return; }
            renderBoard(payload(result));
        }).catch(function () {
            setStatus('ارتباط با تختهٔ مالی برقرار نشد.', 'error');
        }).then(function () {
            state.busy = false;
            locationSelect.disabled = false;
        });
    }
    request('/staff/portal/finance/context').then(function (result) {
        if (!result.ok) { setStatus(showError(result, 'دریافت محدودهٔ مالی ناموفق بود.'), 'error'); return; }
        var context = payload(result);
        state.clinicId = Number(context.clinic_id || 0) || null;
        state.locations = Array.isArray(context.locations) ? context.locations : [];
        state.locationId = Number(context.location_id || 0) || null;
        if (state.locations.length > 1) {
            locationSelect.hidden = false;
            locationSelect.innerHTML = '<option value="">انتخاب موقعیت</option>' + state.locations.map(function (location) {
                return '<option value="' + Number(location.id) + '">' + esc(location.name) + '</option>';
            }).join('');
            locationSelect.value = state.locationId ? String(state.locationId) : '';
        }
        loadBoard();
    }).catch(function () { setStatus('دریافت محدودهٔ مالی ناموفق بود.', 'error'); });
    locationSelect.addEventListener('change', function () {
        state.locationId = Number(locationSelect.value || 0) || null;
        rowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="5">در حال بارگذاری…</td></tr>';
        loadBoard();
    });
}());
</script>
</div>

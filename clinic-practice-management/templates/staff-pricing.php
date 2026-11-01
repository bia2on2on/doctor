<?php
/**
 * Iran V1 — doctor consultation fee + draft treatment charges (Staff Portal).
 *
 * Doctor-owned module. Default fee, per-visit override and additional service
 * charges remain three distinct values. Settled invoices/payments are shown
 * read-only; draft charges can be corrected or removed before settlement.
 * All amounts are integer Rial (IRR) with an explicit «ریال» label.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( empty( $cpms_staff_embed ) ) {
    ?><!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>تعرفه و خدمات ویزیت</title></head><body><p>این صفحه فقط از طریق پورتال کارکنان در دسترس است.</p></body></html>
    <?php
    return;
}

use ClinicCore\Bootstrap\App;

$cpms_pricing_user_id = (int) get_current_user_id();
$cpms_pricing_clinics = array();
foreach ( App::membership_service()->active_memberships_for_user( $cpms_pricing_user_id ) as $cpms_membership ) {
    $cpms_cid = (int) ( $cpms_membership['clinic_id'] ?? 0 );
    if ( $cpms_cid > 0 ) {
        $cpms_pricing_clinics[] = array(
            'id'   => $cpms_cid,
            'name' => (string) ( $cpms_membership['clinic_name'] ?? ( 'مطب ' . $cpms_cid ) ),
        );
    }
}
$cpms_pricing_config      = array(
    'rest_root' => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce'     => (string) wp_create_nonce( 'wp_rest' ),
    'clinics'   => $cpms_pricing_clinics,
);
$cpms_pricing_config_json = wp_json_encode( $cpms_pricing_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $cpms_pricing_config_json ) ) {
    $cpms_pricing_config_json = '{}';
}
?>
<style>
.cpms-pricing { --cpms-primary: #0f5c56; --cpms-border: #e1e8ee; --cpms-muted: #5c6b76; --cpms-error: #a12828; max-width: 1120px; display: grid; gap: 12px; }
.cpms-pricing * { box-sizing: border-box; }
.cpms-pricing__panel { min-width: 0; padding: 14px; border: 1px solid var(--cpms-border); border-radius: 10px; background: #fff; }
.cpms-pricing__heading { margin: 0 0 8px; font-size: 1.12rem; }
.cpms-pricing__hint { margin: 0 0 10px; color: var(--cpms-muted); font-size: .88rem; }
.cpms-pricing__status { min-height: 1.5em; margin: 0; overflow-wrap: anywhere; }
.cpms-pricing__status[data-kind="error"] { color: var(--cpms-error); }
.cpms-pricing__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px; align-items: end; }
.cpms-pricing__field { display: grid; gap: 4px; min-width: 0; color: var(--cpms-muted); font-size: .86rem; }
.cpms-pricing__input, .cpms-pricing__select { width: 100%; min-height: 42px; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; }
.cpms-pricing__input:focus-visible, .cpms-pricing__select:focus-visible, .cpms-pricing__btn:focus-visible { outline: 3px solid var(--cpms-primary); outline-offset: 2px; }
.cpms-pricing__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.cpms-pricing__btn { min-height: 40px; padding: 6px 14px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; cursor: pointer; }
.cpms-pricing__btn--primary { background: var(--cpms-primary); border-color: var(--cpms-primary); color: #fff; }
.cpms-pricing__btn[disabled] { opacity: .6; cursor: default; }
.cpms-pricing__grid { display: grid; grid-template-columns: minmax(220px, 30%) minmax(0, 1fr); gap: 12px; align-items: start; }
.cpms-pricing__visits { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; max-height: 340px; overflow-y: auto; }
.cpms-pricing__visit { width: 100%; min-height: 44px; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; text-align: right; cursor: pointer; }
.cpms-pricing__visit[aria-current="true"] { border-color: var(--cpms-primary); background: #f0f8f7; }
.cpms-pricing__stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin: 10px 0; }
.cpms-pricing__stat { padding: 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #f8fafc; display: grid; gap: 3px; }
.cpms-pricing__stat span { color: var(--cpms-muted); font-size: .82rem; }
.cpms-pricing__stat strong { font-size: 1.02rem; overflow-wrap: anywhere; }
.cpms-pricing__table-wrap { width: 100%; overflow-x: auto; }
.cpms-pricing__table { width: 100%; border-collapse: collapse; }
.cpms-pricing__table th, .cpms-pricing__table td { padding: 8px 6px; border-bottom: 1px solid var(--cpms-border); text-align: right; vertical-align: top; overflow-wrap: anywhere; }
.cpms-pricing__table th { color: var(--cpms-muted); font-size: .82rem; white-space: nowrap; }
.cpms-pricing__muted { color: var(--cpms-muted); }
.cpms-pricing__form { display: grid; gap: 8px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--cpms-border); }
@media (max-width: 760px) {
    .cpms-pricing { padding: 4px; }
    .cpms-pricing__panel { padding: 10px; }
    .cpms-pricing__grid { grid-template-columns: minmax(0, 1fr); }
    .cpms-pricing__visits { max-height: 200px; }
    .cpms-pricing__table thead { display: none; }
    .cpms-pricing__table, .cpms-pricing__table tbody, .cpms-pricing__table tr, .cpms-pricing__table td { display: block; width: 100%; }
    .cpms-pricing__table tr { padding: 8px 0; border-bottom: 1px solid var(--cpms-border); }
    .cpms-pricing__table td { display: grid; grid-template-columns: minmax(95px, 34%) minmax(0, 1fr); gap: 8px; padding: 3px 0; border: 0; }
    .cpms-pricing__table td::before { content: attr(data-label); color: var(--cpms-muted); font-size: .82rem; }
    .cpms-pricing__actions .cpms-pricing__btn { flex: 1 1 auto; }
}
@media (max-width: 390px) {
    .cpms-pricing__input, .cpms-pricing__select, .cpms-pricing__btn { min-height: 44px; }
    .cpms-pricing__fields { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 340px) {
    .cpms-pricing__panel { padding: 8px; }
    .cpms-pricing__table td { grid-template-columns: minmax(0, 1fr); }
}
</style>
<main class="cpms-pricing" data-role="pricing-root" aria-labelledby="cpms-pricing-title">
    <section class="cpms-pricing__panel" aria-label="تعرفه پیشفرض ویزیت">
        <h2 class="cpms-pricing__heading" id="cpms-pricing-title">تعرفه پیشفرض ویزیت پزشک</h2>
        <p class="cpms-pricing__hint">مبالغ فقط به <strong>ریال</strong> و عدد صحیح وارد میشوند. تعرفه فقط برای ویزیتهای جدید همان پزشک، مطب و موقعیت اعمال میشود؛ فاکتورهای پرداختشده تغییر نمیکنند.</p>
        <div class="cpms-pricing__fields">
            <label class="cpms-pricing__field">مطب
                <select class="cpms-pricing__select" data-role="pricing-clinic" aria-label="انتخاب مطب"></select>
            </label>
            <label class="cpms-pricing__field">تعرفه پیشفرض (ریال)
                <input class="cpms-pricing__input" type="number" min="0" step="1" inputmode="numeric" data-role="pricing-default-amount" aria-label="تعرفه پیشفرض به ریال">
            </label>
            <label class="cpms-pricing__field">شناسه موقعیت (۰ = همه)
                <input class="cpms-pricing__input" type="number" min="0" step="1" value="0" data-role="pricing-default-location" aria-label="شناسه موقعیت">
            </label>
            <div class="cpms-pricing__field"><button type="button" class="cpms-pricing__btn cpms-pricing__btn--primary" data-role="pricing-save-default">ذخیره تعرفه</button></div>
        </div>
        <p class="cpms-pricing__hint" data-role="pricing-context-label" style="margin-top:10px">در حال دریافت تعرفه…</p>
    </section>
    <p class="cpms-pricing__panel cpms-pricing__status" data-role="pricing-status" role="status" aria-live="polite">در حال بارگذاری…</p>
    <div class="cpms-pricing__grid">
        <section class="cpms-pricing__panel" aria-label="ویزیتهای امروز پزشک">
            <h3 class="cpms-pricing__heading">ویزیتهای امروز</h3>
            <ul class="cpms-pricing__visits" data-role="pricing-visits"></ul>
        </section>
        <section class="cpms-pricing__panel" aria-label="صورتحساب ویزیت" data-role="pricing-billing" hidden>
            <h3 class="cpms-pricing__heading" data-role="pricing-visit-title">ویزیت انتخاب نشده است</h3>
            <div class="cpms-pricing__stats" data-role="pricing-stats"></div>
            <h4 class="cpms-pricing__heading">خدمات و درمانهای اضافی</h4>
            <div class="cpms-pricing__table-wrap">
                <table class="cpms-pricing__table">
                    <thead><tr><th>شرح خدمت</th><th>مبلغ واحد (ریال)</th><th>تعداد</th><th>جمع (ریال)</th><th>توضیح</th><th>اقدام</th></tr></thead>
                    <tbody data-role="pricing-charges"></tbody>
                </table>
            </div>
            <form class="cpms-pricing__form" data-role="pricing-charge-form">
                <div class="cpms-pricing__fields">
                    <label class="cpms-pricing__field">شرح خدمت
                        <input class="cpms-pricing__input" type="text" maxlength="255" required data-role="pricing-charge-description" aria-label="شرح خدمت">
                    </label>
                    <label class="cpms-pricing__field">مبلغ واحد (ریال)
                        <input class="cpms-pricing__input" type="number" min="1" step="1" required data-role="pricing-charge-amount" aria-label="مبلغ خدمت به ریال">
                    </label>
                    <label class="cpms-pricing__field">تعداد
                        <input class="cpms-pricing__input" type="number" min="0.01" max="100" step="0.01" value="1" data-role="pricing-charge-quantity" aria-label="تعداد خدمت">
                    </label>
                    <label class="cpms-pricing__field">توضیح (اختیاری)
                        <input class="cpms-pricing__input" type="text" maxlength="500" data-role="pricing-charge-note" aria-label="توضیح خدمت">
                    </label>
                </div>
                <div class="cpms-pricing__actions">
                    <button type="submit" class="cpms-pricing__btn cpms-pricing__btn--primary" data-role="pricing-charge-add">افزودن خدمت</button>
                </div>
            </form>
            <form class="cpms-pricing__form" data-role="pricing-override-form">
                <div class="cpms-pricing__fields">
                    <label class="cpms-pricing__field">تعرفه این ویزیت (ریال) — جایگزین پیشفرض
                        <input class="cpms-pricing__input" type="number" min="0" step="1" required data-role="pricing-override-amount" aria-label="تعرفه اختصاصی ویزیت به ریال">
                    </label>
                    <div class="cpms-pricing__field"><button type="submit" class="cpms-pricing__btn" data-role="pricing-override-save">ثبت تعرفه ویزیت</button></div>
                </div>
            </form>
            <h4 class="cpms-pricing__heading" style="margin-top:14px">فاکتور و پرداخت</h4>
            <div class="cpms-pricing__table-wrap">
                <table class="cpms-pricing__table">
                    <thead><tr><th>شرح قلم فاکتور</th><th>تعداد</th><th>مبلغ واحد (ریال)</th><th>مبلغ (ریال)</th></tr></thead>
                    <tbody data-role="pricing-invoice-items"></tbody>
                </table>
            </div>
        </section>
    </div>
</main>
<script type="application/json" id="cpms-pricing-config"><?php echo $cpms_pricing_config_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX flags by wp_json_encode. ?></script>
<script>
(function () {
    'use strict';
    var configNode = document.getElementById('cpms-pricing-config');
    var config;
    try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
    var root = String(config.rest_root || '').replace(/\/$/, '');
    var state = { clinicId: 0, visitId: 0, billing: null, busy: false };
    var statusNode = document.querySelector('[data-role="pricing-status"]');
    var clinicSelect = document.querySelector('[data-role="pricing-clinic"]');
    var visitsNode = document.querySelector('[data-role="pricing-visits"]');
    var chargesNode = document.querySelector('[data-role="pricing-charges"]');
    var itemsNode = document.querySelector('[data-role="pricing-invoice-items"]');
    var statsNode = document.querySelector('[data-role="pricing-stats"]');
    var billingNode = document.querySelector('[data-role="pricing-billing"]');
    var contextLabel = document.querySelector('[data-role="pricing-context-label"]');

    function setStatus(message, kind) {
        statusNode.textContent = message || '';
        statusNode.dataset.kind = kind || '';
    }
    function node(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== undefined && text !== null) el.textContent = String(text);
        return el;
    }
    function rial(value) {
        return String(value === null || value === undefined ? '—' : value) + ' ریال';
    }
    function headers() {
        var value = { 'X-WP-Nonce': String(config.nonce || ''), 'Accept': 'application/json' };
        if (state.clinicId) value['X-CPMS-Clinic-Id'] = String(state.clinicId);
        return value;
    }
    function restUrl(path) {
        var base = String(config.rest_root || '');
        var q = path.indexOf('?');
        var restPath = q === -1 ? path : path.slice(0, q);
        var query = q === -1 ? '' : path.slice(q + 1);
        if (base.indexOf('rest_route=') === -1) {
            return base.replace(/\/$/, '') + restPath + (query ? '?' + query : '');
        }
        var url = new URL(base);
        var route = url.searchParams.get('rest_route') || '/clinic/v1';
        url.searchParams.set('rest_route', route.replace(/\/$/, '') + restPath);
        if (query) {
            query.split('&').forEach(function (pair) {
                if (!pair) return;
                var idx = pair.indexOf('=');
                var key = idx === -1 ? pair : pair.slice(0, idx);
                var value = idx === -1 ? '' : pair.slice(idx + 1);
                url.searchParams.set(key, decodeURIComponent(value));
            });
        }
        return url.toString();
    }
    function request(path, method, body) {
        var options = { method: method || 'GET', headers: headers(), credentials: 'same-origin', cache: 'no-store' };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(restUrl(path), options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload || !payload.data) {
                    var error = new Error((payload && payload.message) || 'خطای ارتباط');
                    error.status = response.status;
                    throw error;
                }
                return payload.data;
            });
        });
    }
    function renderClinics() {
        var clinics = Array.isArray(config.clinics) ? config.clinics : [];
        clinics.forEach(function (clinic) {
            var option = node('option', '', clinic.name || ('مطب ' + clinic.id));
            option.value = String(clinic.id);
            clinicSelect.appendChild(option);
        });
        if (clinics.length) { state.clinicId = Number(clinics[0].id) || 0; clinicSelect.value = String(state.clinicId); }
        clinicSelect.hidden = clinics.length < 2;
    }
    function loadContext() {
        return request('/staff/portal/pricing/context').then(function (data) {
            state.clinicId = Number(data.clinic_id) || state.clinicId;
            clinicSelect.value = String(state.clinicId);
            contextLabel.textContent = 'پزشک: ' + (data.clinician_name || '') + ' — تعرفه پیشفرض فعلی: '
                + (data.default_fee === null ? 'تنظیم نشده' : rial(data.default_fee))
                + ' — سیاست: ' + (data.fee_policy || 'تعرفه هر ویزیت هنگام پذیرش ثبت میشود');
            document.querySelector('[data-role="pricing-default-amount"]').value = data.default_fee === null ? '' : String(data.default_fee);
            if (!data.can_edit) setStatus('دسترسی ویرایش تعرفه برای این حساب فعال نیست.', 'error');
        });
    }
    function loadVisits() {
        return request('/staff/portal/pricing/visits').then(function (data) {
            visitsNode.textContent = '';
            var visits = Array.isArray(data.visits) ? data.visits : [];
            if (!visits.length) { visitsNode.appendChild(node('li', 'cpms-pricing__hint', 'ویزیتی برای امروز ثبت نشده است.')); return; }
            visits.forEach(function (visit) {
                var li = document.createElement('li');
                var button = node('button', 'cpms-pricing__visit', visit.patient_name + ' — ویزیت ' + visit.visit_id);
                button.type = 'button';
                if (Number(visit.visit_id) === state.visitId) button.setAttribute('aria-current', 'true');
                button.addEventListener('click', function () { state.visitId = Number(visit.visit_id); loadBilling().then(loadVisits); });
                li.appendChild(button);
                visitsNode.appendChild(li);
            });
        });
    }
    function stat(label, value) {
        var box = node('div', 'cpms-pricing__stat');
        box.appendChild(node('span', '', label));
        box.appendChild(node('strong', '', value));
        statsNode.appendChild(box);
    }
    function feeSourceLabel(source) {
        if (source === 'visit_override') return 'تعرفه اختصاصی پزشک';
        if (source === 'checkin_snapshot') return 'تعرفه ثبتشده هنگام پذیرش';
        if (source === 'snapshot_failed') return 'ثبت تعرفه هنگام پذیرش ناموفق بود؛ تعیین دستی لازم است';
        if (source === 'doctor_default') return 'پیشفرض پزشک';
        return 'تنظیم نشده';
    }
    function cell(text, label) {
        var td = node('td', '', text);
        if (label) td.dataset.label = label;
        return td;
    }
    function renderBilling(data) {
        state.billing = data;
        billingNode.hidden = false;
        document.querySelector('[data-role="pricing-visit-title"]').textContent = 'صورتحساب ویزیت ' + data.visit_id + ' — ' + (data.patient_name || '');
        statsNode.textContent = '';
        stat('تعرفه پیشفرض پزشک', data.default_fee === null ? 'تنظیم نشده' : rial(data.default_fee));
        stat('تعرفه ثبتشده هنگام پذیرش', data.snapshot_fee === null ? 'ثبت نشده' : rial(data.snapshot_fee));
        stat('تعرفه اختصاصی این ویزیت', data.override_fee === null ? 'بدون تغییر' : rial(data.override_fee));
        stat('تعرفه اعمالشده ویزیت', rial(data.effective_fee === null ? 0 : data.effective_fee));
        stat('جمع خدمات اضافی', rial(data.charges_total));
        stat('جمع کل', rial(data.expected_total));
        stat('مبلغ پرداختشده', rial(data.paid_amount));
        stat('مانده بدهی', rial(data.remaining_balance));
        stat('وضعیت پرداخت', data.payment_status_label);
        stat('منبع تعرفه', feeSourceLabel(data.fee_source));
        stat('سیاست تعرفه', 'تغییر تعرفه پیشفرض، ویزیتهای ثبتشده را تغییر نمیدهد');

        chargesNode.textContent = '';
        var charges = Array.isArray(data.charges) ? data.charges : [];
        if (!charges.length) {
            var emptyRow = document.createElement('tr');
            var emptyCell = cell('خدمت اضافی ثبت نشده است.', 'خدمات');
            emptyCell.colSpan = 6;
            emptyRow.appendChild(emptyCell);
            chargesNode.appendChild(emptyRow);
        }
        charges.forEach(function (charge) {
            var row = document.createElement('tr');
            row.appendChild(cell(charge.description, 'شرح'));
            row.appendChild(cell(rial(charge.unit_amount), 'مبلغ واحد'));
            row.appendChild(cell(String(charge.quantity), 'تعداد'));
            row.appendChild(cell(rial(charge.line_total), 'جمع'));
            row.appendChild(cell(charge.note || '—', 'توضیح'));
            var actionCell = document.createElement('td');
            actionCell.dataset.label = 'اقدام';
            if (charge.editable) {
                var edit = node('button', 'cpms-pricing__btn', 'اصلاح');
                edit.type = 'button';
                edit.addEventListener('click', function () { editCharge(charge); });
                var remove = node('button', 'cpms-pricing__btn', 'حذف');
                remove.type = 'button';
                remove.addEventListener('click', function () {
                    if (!window.confirm('این خدمت حذف شود؟')) return;
                    mutate('/staff/portal/pricing/visits/' + state.visitId + '/charges/' + charge.id, 'DELETE');
                });
                actionCell.appendChild(edit); actionCell.appendChild(remove);
            } else {
                actionCell.appendChild(node('span', 'cpms-pricing__muted', 'قطعی'));
            }
            row.appendChild(actionCell);
            chargesNode.appendChild(row);
        });

        itemsNode.textContent = '';
        var invoice = data.invoice;
        var items = invoice && Array.isArray(invoice.items) ? invoice.items : [];
        if (!items.length) {
            var emptyInvoiceRow = document.createElement('tr');
            var emptyInvoiceCell = cell('فاکتوری صادر نشده است؛ اقلام بالا پس از صدور فاکتور نمایش داده میشوند.', 'فاکتور');
            emptyInvoiceCell.colSpan = 4;
            emptyInvoiceRow.appendChild(emptyInvoiceCell);
            itemsNode.appendChild(emptyInvoiceRow);
        }
        items.forEach(function (item) {
            var row = document.createElement('tr');
            row.appendChild(cell(item.description, 'شرح'));
            row.appendChild(cell(String(item.quantity), 'تعداد'));
            row.appendChild(cell(rial(item.unit_price), 'مبلغ واحد'));
            row.appendChild(cell(rial(item.amount), 'مبلغ'));
            itemsNode.appendChild(row);
        });

        var editable = !!data.can_edit_pricing;
        ['pricing-charge-add', 'pricing-override-save'].forEach(function (role) {
            var button = document.querySelector('[data-role="' + role + '"]');
            if (button) button.disabled = !editable;
        });
        document.querySelector('[data-role="pricing-override-amount"]').value = data.override_fee === null ? '' : String(data.override_fee);
        if (!editable) setStatus('این ویزیت تسویه یا فاکتور شده است؛ اقلام فقط خواندنی هستند.', '');
        else setStatus('', '');
    }
    function editCharge(charge) {
        var description = window.prompt('شرح خدمت', charge.description);
        if (description === null) return;
        var amount = window.prompt('مبلغ واحد (ریال)', String(charge.unit_amount));
        if (amount === null) return;
        var quantity = window.prompt('تعداد', String(charge.quantity));
        if (quantity === null) return;
        mutate('/staff/portal/pricing/visits/' + state.visitId + '/charges/' + charge.id, 'PUT', {
            description: description, amount: Number(amount), quantity: Number(quantity), note: charge.note || ''
        });
    }
    function loadBilling() {
        if (!state.visitId) return Promise.resolve();
        return request('/staff/portal/pricing/visits/' + state.visitId + '/billing').then(renderBilling);
    }
    function mutate(path, method, body) {
        if (state.busy) return;
        state.busy = true;
        request(path, method, body).then(function () {
            setStatus('تغییرات ذخیره شد.', '');
            return loadBilling();
        }).catch(function (error) {
            setStatus(error.message || 'ذخیره تغییرات ناموفق بود.', 'error');
        }).then(function () { state.busy = false; });
    }
    function connect() {
        return loadContext().then(loadVisits).then(loadBilling);
    }
    clinicSelect.addEventListener('change', function () {
        state.clinicId = Number(clinicSelect.value || 0);
        state.visitId = 0; state.billing = null; billingNode.hidden = true;
        connect().catch(function (error) { setStatus(error.message || 'دریافت اطلاعات ناموفق بود.', 'error'); });
    });
    document.querySelector('[data-role="pricing-save-default"]').addEventListener('click', function () {
        var amount = Number(document.querySelector('[data-role="pricing-default-amount"]').value);
        var locationId = Number(document.querySelector('[data-role="pricing-default-location"]').value || 0);
        request('/staff/portal/pricing/default-fee', 'POST', { amount: amount, location_id: locationId })
            .then(function () { setStatus('تعرفه پیشفرض ذخیره شد.', ''); return connect(); })
            .catch(function (error) { setStatus(error.message || 'ذخیره تعرفه ناموفق بود.', 'error'); });
    });
    document.querySelector('[data-role="pricing-charge-form"]').addEventListener('submit', function (event) {
        event.preventDefault();
        if (!state.visitId) { setStatus('ابتدا یک ویزیت انتخاب کنید.', 'error'); return; }
        mutate('/staff/portal/pricing/visits/' + state.visitId + '/charges', 'POST', {
            description: document.querySelector('[data-role="pricing-charge-description"]').value,
            amount: Number(document.querySelector('[data-role="pricing-charge-amount"]').value),
            quantity: Number(document.querySelector('[data-role="pricing-charge-quantity"]').value || 1),
            note: document.querySelector('[data-role="pricing-charge-note"]').value
        });
        document.querySelector('[data-role="pricing-charge-form"]').reset();
    });
    document.querySelector('[data-role="pricing-override-form"]').addEventListener('submit', function (event) {
        event.preventDefault();
        if (!state.visitId) { setStatus('ابتدا یک ویزیت انتخاب کنید.', 'error'); return; }
        mutate('/staff/portal/pricing/visits/' + state.visitId + '/consultation-fee', 'POST', {
            amount: Number(document.querySelector('[data-role="pricing-override-amount"]').value)
        });
    });
    renderClinics();
    connect().catch(function (error) { setStatus(error.message || 'دریافت اطلاعات ناموفق بود.', 'error'); });
    window.setInterval(function () {
        if (document.visibilityState !== 'visible' || !state.visitId) return;
        loadBilling().catch(function () {});
    }, 15000);
}());
</script>
</div>

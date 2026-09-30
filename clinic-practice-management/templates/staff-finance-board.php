<?php
/**
 * Phase 12 — Finance module inside the independent Staff Portal.
 *
 * Slice 1: read-only awaiting-payment board.
 * Slice 2: bounded first issuance for CURRENT trusted Location
 *          consultation-completed Visits. The mutation delegates to the
 *          existing finance boundary.
 * Slice 3: bounded manual capture of one payment (partial or the exact
 *          remaining balance) against an existing invoice of the CURRENT
 *          trusted Location. Manual entry only — cash / card_pos / other are
 *          recorded by hand from what the operator reads off the payment
 *          instrument; nothing is captured automatically and no hardware is
 *          involved. The mutation delegates to the existing finance boundary.
 * Slice 4: bounded paid/checkout-ready board for the CURRENT trusted
 *          Location (exact persisted `paid` state) + the explicit,
 *          confirmed NORMAL paid checkout action. The mutation delegates to
 *          the existing queue checkout boundary; no other finance action is
 *          added here.
 * The module carries no other finance mutation, no tax/discount/service
 * management, and no wp-admin template/chrome is used.
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
.cpms-finance-board__hint { margin: 0 0 10px; color: var(--cpms-muted); }
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
.cpms-finance-board__invoice .cpms-finance-board__action { margin-top: 6px; }
.cpms-finance-board__muted { color: var(--cpms-muted); }
.cpms-finance-board__empty { padding: 16px 8px !important; text-align: center !important; color: var(--cpms-muted); }
.cpms-finance-board__action { min-height: 36px; padding: 6px 12px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font: inherit; cursor: pointer; }
.cpms-finance-board__form { display: grid; gap: 10px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--cpms-border); }
.cpms-finance-board__form[hidden] { display: none; }
.cpms-finance-board__target { margin: 0; font-weight: 700; }
.cpms-finance-board__fields { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr); gap: 8px; align-items: end; }
.cpms-finance-board__field { display: grid; gap: 4px; min-width: 0; color: var(--cpms-muted); }
.cpms-finance-board__field input, .cpms-finance-board__field select { min-height: 40px; width: 100%; min-width: 0; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font: inherit; color: inherit; }
.cpms-finance-board__form-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cpms-finance-board__submit { min-height: 40px; padding: 6px 16px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #1d2327; color: #fff; font: inherit; cursor: pointer; }
.cpms-finance-board__submit[disabled] { opacity: .6; cursor: default; }
/* Phase 12 Slice 5 — the read-only receipt surface. Everything here is a
    projection of server truth; printing uses the browser's own print path and
    never a server-side document. */
.cpms-finance-board__receipt-body { display: grid; gap: 4px; margin: 6px 0 12px; }
.cpms-finance-board__receipt-row { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; margin: 0; padding: 5px 0; border-bottom: 1px solid var(--cpms-border); }
.cpms-finance-board__receipt-row:last-child { border-bottom: 0; }
.cpms-finance-board__receipt-total { font-weight: 700; }
.cpms-finance-board__receipt-table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
.cpms-finance-board__receipt-table th, .cpms-finance-board__receipt-table td { padding: 6px 4px; border-bottom: 1px solid var(--cpms-border); text-align: right; vertical-align: top; overflow-wrap: anywhere; }
@media (max-width: 760px) {
    .cpms-finance-board__panel { padding: 11px; }
    .cpms-finance-board__table thead { display: none; }
    .cpms-finance-board__table, .cpms-finance-board__table tbody, .cpms-finance-board__table tr, .cpms-finance-board__table td { display: block; width: 100%; }
    .cpms-finance-board__table tr { padding: 9px 0; border-bottom: 1px solid var(--cpms-border); }
    .cpms-finance-board__table tr:last-child { border-bottom: 0; }
    .cpms-finance-board__table td { display: grid; grid-template-columns: minmax(95px, 34%) minmax(0, 1fr); gap: 8px; padding: 4px 2px; border: 0; }
    .cpms-finance-board__table td::before { content: attr(data-label); color: var(--cpms-muted); font-size: .82rem; }
    .cpms-finance-board__empty { display: block !important; }
    .cpms-finance-board__fields { grid-template-columns: minmax(0, 1fr); }
    .cpms-finance-board__action, .cpms-finance-board__submit { width: 100%; }
}
/* Print isolation: only the receipt surface is printed; the portal chrome and
    every other panel are masked while the browser print dialog is active. */
@media print {
    body.cpms-finance-printing { background: #fff; }
    body.cpms-finance-printing * { visibility: hidden !important; }
    body.cpms-finance-printing [data-role="finance-receipt"],
    body.cpms-finance-printing [data-role="finance-receipt"] * { visibility: visible !important; }
    body.cpms-finance-printing [data-role="finance-receipt"] { position: absolute; top: 0; right: 0; left: 0; width: 100%; margin: 0; border: 0; padding: 0; }
    body.cpms-finance-printing [data-role="finance-receipt-print"],
    body.cpms-finance-printing [data-role="finance-receipt-close"],
    body.cpms-finance-printing [data-role="finance-receipt-hint"] { display: none !important; }
}
</style>
<main class="cpms-finance-board" data-role="finance-root" aria-labelledby="cpms-finance-board-title">
    <section class="cpms-finance-board__panel" aria-label="محدودهٔ عملیاتی مالی">
        <h2 class="cpms-finance-board__heading" id="cpms-finance-board-title">مالی — موقعیت عملیاتی جاری</h2>
        <div class="cpms-finance-board__meta">
            <label>موقعیت عملیاتی
                <select class="cpms-finance-board__location" data-role="finance-location" aria-label="انتخاب موقعیت عملیاتی" hidden></select>
            </label>
            <span data-role="finance-date">تاریخ عملیاتی: —</span>
        </div>
    </section>
    <p class="cpms-finance-board__panel cpms-finance-board__status" data-role="finance-status" role="status" aria-live="polite">در حال دریافت اطلاعات مالی…</p>
    <section class="cpms-finance-board__panel" data-role="finance-eligible" aria-label="صدور فاکتور ویزیت‌های پایان‌مشاوره">
        <h2 class="cpms-finance-board__heading">صدور فاکتور</h2>
        <p class="cpms-finance-board__hint">ویزیت‌های «مشاوره تمام‌شده» امروزِ همین موقعیت عملیاتی. پس از صدور، ویزیت از این فهرست خارج و به تختهٔ «در انتظار پرداخت» منتقل می‌شود.</p>
        <div class="cpms-finance-board__table-wrap">
            <table class="cpms-finance-board__table">
                <thead><tr><th>بیمار</th><th>پزشک</th><th>تاریخ و ساعت عملیاتی</th><th>وضعیت ویزیت</th><th>اقدام</th></tr></thead>
                <tbody data-role="finance-eligible-rows"><tr><td class="cpms-finance-board__empty" colspan="5">در حال بارگذاری…</td></tr></tbody>
            </table>
        </div>
        <form class="cpms-finance-board__form" data-role="finance-issue-form" hidden>
            <p class="cpms-finance-board__target" data-role="finance-issue-target" aria-live="polite"></p>
            <p class="cpms-finance-board__hint">مبالغ به ریال؛ جمع فاکتور در سرور محاسبه می‌شود.</p>
            <div class="cpms-finance-board__fields">
                <label class="cpms-finance-board__field">شرح خدمت
                    <input type="text" maxlength="255" required data-role="finance-issue-description" aria-label="شرح خدمت">
                </label>
                <label class="cpms-finance-board__field">تعداد
                    <input type="number" min="0.5" step="0.5" value="1" required data-role="finance-issue-quantity" aria-label="تعداد">
                </label>
                <label class="cpms-finance-board__field">مبلغ واحد (ریال)
                    <input type="number" min="0" step="1" required data-role="finance-issue-unit-price" aria-label="مبلغ واحد به ریال">
                </label>
            </div>
            <div class="cpms-finance-board__form-actions">
                <button type="submit" class="cpms-finance-board__submit" data-role="finance-issue-submit">صدور فاکتور</button>
                <button type="button" class="cpms-finance-board__action" data-role="finance-issue-cancel">انصراف</button>
            </div>
        </form>
    </section>
    <section class="cpms-finance-board__panel" data-role="finance-board" aria-label="تختهٔ ویزیت‌های در انتظار پرداخت">
        <h2 class="cpms-finance-board__heading">ویزیت‌های در انتظار پرداخت</h2>
        <div class="cpms-finance-board__table-wrap">
            <table class="cpms-finance-board__table">
                <thead><tr><th>بیمار</th><th>پزشک</th><th>تاریخ و ساعت عملیاتی</th><th>وضعیت ویزیت</th><th>فاکتور</th></tr></thead>
                <tbody data-role="finance-rows"><tr><td class="cpms-finance-board__empty" colspan="5">در حال بارگذاری…</td></tr></tbody>
            </table>
        </div>
    </section>
    <section class="cpms-finance-board__panel" data-role="finance-payment" aria-label="ثبت دستی پرداخت" hidden>
        <h2 class="cpms-finance-board__heading">ثبت پرداخت</h2>
        <p class="cpms-finance-board__hint">پرداخت دریافت‌شده به‌صورت دستی ثبت می‌شود؛ مبلغ بیش از باقی‌ماندهٔ سرور پذیرفته نمی‌شود و ثبت کامل، فاکتور را تسویه می‌کند.</p>
        <form class="cpms-finance-board__form" data-role="finance-pay-form">
            <p class="cpms-finance-board__target" data-role="finance-pay-target" aria-live="polite"></p>
            <div class="cpms-finance-board__fields">
                <label class="cpms-finance-board__field">مبلغ (ریال)
                    <input type="number" min="1" step="1" required data-role="finance-pay-amount" aria-label="مبلغ پرداخت به ریال">
                </label>
                <label class="cpms-finance-board__field">روش پرداخت
                    <select data-role="finance-pay-method" aria-label="روش پرداخت">
                        <option value="cash">نقد</option>
                        <option value="card_pos">کارت‌خوان — ثبت دستی</option>
                        <option value="other">سایر</option>
                    </select>
                </label>
                <label class="cpms-finance-board__field">شمارهٔ پیگیری (اختیاری)
                    <input type="text" maxlength="128" data-role="finance-pay-ref" aria-label="شمارهٔ پیگیری اختیاری">
                </label>
            </div>
            <div class="cpms-finance-board__form-actions">
                <button type="submit" class="cpms-finance-board__submit" data-role="finance-pay-submit">ثبت پرداخت</button>
                <button type="button" class="cpms-finance-board__action" data-role="finance-pay-cancel">انصراف</button>
            </div>
        </form>
    </section>
    <section class="cpms-finance-board__panel" data-role="finance-paid" aria-label="تختهٔ ویزیت‌های پرداخت‌شده — آمادهٔ خروج">
        <h2 class="cpms-finance-board__heading">ویزیت‌های پرداخت‌شده — آمادهٔ خروج</h2>
        <p class="cpms-finance-board__hint">ویزیت‌های «پرداخت کامل» امروزِ همین موقعیت عملیاتی. خروج، یک اقدام صریح و تأییدشدهٔ منشی است؛ پس از تأیید، ویزیت از این فهرست خارج می‌شود.</p>
        <div class="cpms-finance-board__table-wrap">
            <table class="cpms-finance-board__table">
                <thead><tr><th>بیمار</th><th>پزشک</th><th>تاریخ و ساعت عملیاتی</th><th>وضعیت ویزیت</th><th>فاکتور</th><th>اقدام</th></tr></thead>
                <tbody data-role="finance-paid-rows"><tr><td class="cpms-finance-board__empty" colspan="6">در حال بارگذاری…</td></tr></tbody>
            </table>
        </div>
    </section>
    <section class="cpms-finance-board__panel" data-role="finance-checkout" aria-label="تأیید خروج ویزیت" hidden>
        <h2 class="cpms-finance-board__heading">تأیید خروج از کلینیک</h2>
        <form class="cpms-finance-board__form" data-role="finance-checkout-form">
            <p class="cpms-finance-board__target" data-role="finance-checkout-target" aria-live="polite"></p>
            <p class="cpms-finance-board__hint">با تأیید، ویزیت به وضعیت «خارج‌شده» می‌رود و این عملیات قابل بازگشت نیست.</p>
            <div class="cpms-finance-board__form-actions">
                <button type="submit" class="cpms-finance-board__submit" data-role="finance-checkout-submit">تأیید خروج</button>
                <button type="button" class="cpms-finance-board__action" data-role="finance-checkout-cancel">انصراف</button>
            </div>
        </form>
    </section>
    <!-- Phase 12 Slice 5 — read-only receipt of a NORMAL fully paid invoice.
        It is only ever filled from the server's durable payment truth, and
        it is printed through the browser's own print path; there is no
        mutation, no server-side document and no other finance action here. -->
    <section class="cpms-finance-board__panel" data-role="finance-receipt" aria-label="رسید پرداخت" hidden>
        <h2 class="cpms-finance-board__heading">رسید پرداخت</h2>
        <p class="cpms-finance-board__hint" data-role="finance-receipt-hint">این رسید فقط از دادهٔ پایدارِ مسیر تسویهٔ عادی ساخته می‌شود و تاریخ‌های آن بر پایهٔ منطقهٔ زمانی موقعیت عملیاتی جاری است.</p>
        <div class="cpms-finance-board__receipt-body" data-role="finance-receipt-body"></div>
        <div class="cpms-finance-board__form-actions">
            <button type="button" class="cpms-finance-board__submit" data-role="finance-receipt-print">چاپ رسید</button>
            <button type="button" class="cpms-finance-board__action" data-role="finance-receipt-close">بستن</button>
        </div>
    </section>
</main>
<script type="application/json" id="cpms-finance-board-config">
<?php
$cpms_finance_board_config = array(
    'rest_root' => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce'     => esc_attr( wp_create_nonce( 'wp_rest' ) ),
);
echo wp_json_encode( $cpms_finance_board_config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON config is encoded by wp_json_encode.
?>
</script>
<script>
(function () {
    'use strict';
    var configNode = document.getElementById('cpms-finance-board-config');
    var config;
    try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
    var root = String(config.rest_root || '').replace(/\/$/, '');
    var state = { clinicId: null, locationId: null, locations: [], busy: false, canIssue: false, canCapture: false, canCheckOut: false, selectedVisit: null, selectedInvoice: null, paymentKey: null, selectedCheckoutVisit: null, selectedReceiptVisit: null, receiptLabel: '' };
    var locationSelect = document.querySelector('[data-role="finance-location"]');
    var rowsNode = document.querySelector('[data-role="finance-rows"]');
    var eligiblePanel = document.querySelector('[data-role="finance-eligible"]');
    var eligibleRowsNode = document.querySelector('[data-role="finance-eligible-rows"]');
    var paymentPanel = document.querySelector('[data-role="finance-payment"]');
    var payFormNode = document.querySelector('[data-role="finance-pay-form"]');
    var payTargetNode = document.querySelector('[data-role="finance-pay-target"]');
    var payAmountInput = document.querySelector('[data-role="finance-pay-amount"]');
    var payMethodSelect = document.querySelector('[data-role="finance-pay-method"]');
    var payRefInput = document.querySelector('[data-role="finance-pay-ref"]');
    var paySubmitNode = document.querySelector('[data-role="finance-pay-submit"]');
    var payCancelNode = document.querySelector('[data-role="finance-pay-cancel"]');
    var paidPanel = document.querySelector('[data-role="finance-paid"]');
    var paidRowsNode = document.querySelector('[data-role="finance-paid-rows"]');
    var checkoutPanel = document.querySelector('[data-role="finance-checkout"]');
    var checkoutFormNode = document.querySelector('[data-role="finance-checkout-form"]');
    var checkoutTargetNode = document.querySelector('[data-role="finance-checkout-target"]');
    var checkoutSubmitNode = document.querySelector('[data-role="finance-checkout-submit"]');
    var checkoutCancelNode = document.querySelector('[data-role="finance-checkout-cancel"]');
    var receiptPanel = document.querySelector('[data-role="finance-receipt"]');
    var receiptBodyNode = document.querySelector('[data-role="finance-receipt-body"]');
    var receiptPrintNode = document.querySelector('[data-role="finance-receipt-print"]');
    var receiptCloseNode = document.querySelector('[data-role="finance-receipt-close"]');
    var formNode = document.querySelector('[data-role="finance-issue-form"]');
    var targetNode = document.querySelector('[data-role="finance-issue-target"]');
    var descriptionInput = document.querySelector('[data-role="finance-issue-description"]');
    var quantityInput = document.querySelector('[data-role="finance-issue-quantity"]');
    var priceInput = document.querySelector('[data-role="finance-issue-unit-price"]');
    var submitNode = document.querySelector('[data-role="finance-issue-submit"]');
    var cancelNode = document.querySelector('[data-role="finance-issue-cancel"]');
    var statusNode = document.querySelector('[data-role="finance-status"]');
    var dateNode = document.querySelector('[data-role="finance-date"]');
    var boardLabels = ['بیمار', 'پزشک', 'تاریخ و ساعت عملیاتی', 'وضعیت ویزیت', 'فاکتور'];
    var eligibleLabels = ['بیمار', 'پزشک', 'تاریخ و ساعت عملیاتی', 'وضعیت ویزیت', 'اقدام'];
    var paidLabels = ['بیمار', 'پزشک', 'تاریخ و ساعت عملیاتی', 'وضعیت ویزیت', 'فاکتور', 'اقدام'];

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
    function request(path, method, body, extraHeaders) {
        var options = { method: method || 'GET', headers: headers(), credentials: 'same-origin', cache: 'no-store' };
        if (extraHeaders) {
            Object.keys(extraHeaders).forEach(function (name) { options.headers[name] = String(extraHeaders[name]); });
        }
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(root + path, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                return { ok: response.ok, body: payload };
            });
        });
    }
    function payload(result) { return result.body && result.body.data ? result.body.data : {}; }
    function showError(result, fallback) {
        var code = result.body && result.body.code || '';
        if (code === 'CLINIC_SCOPE_REQUIRED') return 'انتخاب موقعیت عملیاتی الزامی است.';
        if (code === 'CLINIC_SCOPE_UNAVAILABLE') return 'محدودهٔ عملیاتی معتبر در دسترس نیست.';
        if (code === 'CLINIC_INVALID_TRANSITION') return 'این ویزیت در وضعیت قابل صدور نیست؛ فهرست به‌روز شد.';
        if (code === 'CLINIC_NOT_FOUND') return 'ویزیت انتخاب‌شده در این موقعیت عملیاتی در دسترس نیست.';
        if (code === 'CLINIC_POLICY_VIOLATION') return 'این ویزیت فاکتور فعال دارد.';
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
    function operationalStamp(visit) {
        return esc(visit.operational_date) + (visit.jalali_date ? ' · ' + esc(visit.jalali_date) : '') + ' · ' + esc(visit.operational_time);
    }
    // One Idempotency-Key per capture attempt target: a double submit or a
    // retry after a dropped response replays the same payment server-side
    // instead of writing a second one. A new target gets a new key.
    function paymentKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return String(window.crypto.randomUUID()).toLowerCase();
        }
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = '';
        for (var j = 0; j < 16; j++) hex += ('0' + bytes[j].toString(16)).slice(-2);
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }
    function closeIssueForm() {
        state.selectedVisit = null;
        formNode.hidden = true;
        targetNode.textContent = '';
    }
    function closePayForm() {
        state.selectedInvoice = null;
        state.paymentKey = null;
        paymentPanel.hidden = true;
        payFormNode.hidden = true;
        payTargetNode.textContent = '';
    }
    function openPayForm(invoiceId, patientName, remaining, currency) {
        if (!state.canCapture || !invoiceId) return;
        state.selectedInvoice = Number(invoiceId);
        state.paymentKey = paymentKey();
        payTargetNode.textContent = 'فاکتور ' + patientName + ' — باقی‌ماندهٔ سرور: ' + String(remaining) + ' ' + String(currency);
        payAmountInput.value = '';
        payMethodSelect.value = 'cash';
        payRefInput.value = '';
        paySubmitNode.disabled = false;
        paymentPanel.hidden = false;
        payFormNode.hidden = false;
        payAmountInput.focus();
    }
    function openIssueForm(visitId, patientName) {
        if (!state.canIssue || !visitId) return;
        state.selectedVisit = visitId;
        targetNode.textContent = 'ویزیت انتخاب‌شده: ' + patientName;
        descriptionInput.value = '';
        quantityInput.value = '1';
        priceInput.value = '';
        submitNode.disabled = false;
        formNode.hidden = false;
        descriptionInput.focus();
    }
    function renderEligible(data) {
        var visits = Array.isArray(data.visits) ? data.visits : [];
        var selectedStillListed = false;
        if (!visits.length) {
            eligibleRowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="5">ویزیت پایان‌مشاوره‌ای برای صدور فاکتور وجود ندارد.</td></tr>';
        } else {
            eligibleRowsNode.innerHTML = visits.map(function (visit) {
                if (Number(visit.visit_id) === Number(state.selectedVisit)) selectedStillListed = true;
                var values = [
                    '<span class="cpms-finance-board__patient">' + esc(visit.patient_name) + '</span>',
                    esc(visit.clinician_name),
                    operationalStamp(visit),
                    'مشاوره تمام‌شده',
                    '<button type="button" class="cpms-finance-board__action" data-role="finance-issue-open" data-visit-id="' + Number(visit.visit_id) + '" data-patient="' + esc(visit.patient_name) + '">صدور فاکتور</button>'
                ];
                return '<tr>' + values.map(function (value, index) {
                    return '<td data-label="' + eligibleLabels[index] + '">' + value + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }
        if (state.selectedVisit && !selectedStillListed) closeIssueForm();
        if (data.has_more) setStatus('فهرست ویزیت‌های قابل صدور به ۱۰۰ مورد محدود شده است.', '');
    }
    function renderBoard(data) {
        var visits = Array.isArray(data.visits) ? data.visits : [];
        var invoiceStillListed = false;
        dateNode.textContent = 'تاریخ عملیاتی: ' + String(data.date || '—') + (data.jalali_date ? ' · ' + String(data.jalali_date) : '');
        if (!visits.length) {
            rowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="5">برای این موقعیت در انتظار پرداختی وجود ندارد.</td></tr>';
        } else {
            rowsNode.innerHTML = visits.map(function (visit) {
                if (visit.invoice_id && Number(visit.invoice_id) === Number(state.selectedInvoice)) invoiceStillListed = true;
                var invoiceCell = renderInvoice(visit.invoice);
                if (state.canCapture && visit.invoice_id && visit.invoice) {
                    invoiceCell += '<button type="button" class="cpms-finance-board__action" data-role="finance-pay-open"' +
                        ' data-invoice-id="' + Number(visit.invoice_id) + '"' +
                        ' data-patient="' + esc(visit.patient_name) + '"' +
                        ' data-remaining="' + esc(visit.invoice.remaining) + '"' +
                        ' data-currency="' + esc(visit.invoice.currency) + '">ثبت پرداخت</button>';
                }
                var values = [
                    '<span class="cpms-finance-board__patient">' + esc(visit.patient_name) + '</span>',
                    esc(visit.clinician_name),
                    operationalStamp(visit),
                    'در انتظار پرداخت',
                    invoiceCell
                ];
                return '<tr>' + values.map(function (value, index) {
                    return '<td data-label="' + boardLabels[index] + '">' + value + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }
        if (state.selectedInvoice && !invoiceStillListed) closePayForm();
        if (data.has_more) setStatus('فهرست به ۱۰۰ مورد محدود شده است؛ همهٔ نتایج در این نما نشان داده نمی‌شوند.', '');
    }
    function renderPaid(data) {
        var visits = Array.isArray(data.visits) ? data.visits : [];
        var selectedStillListed = false;
        if (!visits.length) {
            paidRowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="6">برای این موقعیت ویزیت پرداخت‌شده‌ای وجود ندارد.</td></tr>';
        } else {
            paidRowsNode.innerHTML = visits.map(function (visit) {
                if (visit.visit_id && Number(visit.visit_id) === Number(state.selectedCheckoutVisit)) selectedStillListed = true;
                var invoiceCell = renderInvoice(visit.invoice);
                var remaining = visit.invoice ? String(visit.invoice.remaining) : '';
                var currency = visit.invoice ? String(visit.invoice.currency) : '';
                // A fully paid row always offers the read-only receipt; checkout
                // stays exactly the Slice 4 write control it was.
                var receiptCell = visit.invoice && String(visit.invoice.status) === 'paid'
                    ? '<button type="button" class="cpms-finance-board__action" data-role="finance-receipt-open" data-visit-id="' + Number(visit.visit_id) + '" data-patient="' + esc(visit.patient_name) + '">رسید</button>'
                    : '';
                var actionCell = (state.canCheckOut
                    ? '<button type="button" class="cpms-finance-board__action" data-role="finance-checkout-open" data-visit-id="' + Number(visit.visit_id) + '" data-patient="' + esc(visit.patient_name) + '" data-remaining="' + esc(remaining) + '" data-currency="' + esc(currency) + '">خروج از کلینیک</button>'
                    : '') + receiptCell;
                if (!actionCell) actionCell = '<span class="cpms-finance-board__muted">—</span>';
                var values = [
                    '<span class="cpms-finance-board__patient">' + esc(visit.patient_name) + '</span>',
                    esc(visit.clinician_name),
                    operationalStamp(visit),
                    'پرداخت کامل',
                    invoiceCell,
                    actionCell
                ];
                return '<tr>' + values.map(function (value, index) {
                    return '<td data-label="' + paidLabels[index] + '">' + value + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }
        if (state.selectedCheckoutVisit && !selectedStillListed) closeCheckoutForm();
        if (state.selectedReceiptVisit && !visits.some(function (visit) {
            return Number(visit.visit_id) === Number(state.selectedReceiptVisit);
        })) {
            closeReceipt();
        }
        if (data.has_more) setStatus('فهرست ویزیت‌های پرداخت‌شده به ۱۰۰ مورد محدود شده است.', '');
    }
    function openCheckoutForm(visitId, patientName, remaining, currency) {
        if (!state.canCheckOut || !visitId) return;
        state.selectedCheckoutVisit = Number(visitId);
        var summary = remaining ? ' — فاکتور: باقی‌ماندهٔ سرور ' + String(remaining) + ' ' + String(currency) : ' — بدون فاکتور فعال';
        checkoutTargetNode.textContent = 'خروج بیمار ' + patientName + summary;
        checkoutSubmitNode.disabled = false;
        checkoutPanel.hidden = false;
        checkoutFormNode.hidden = false;
    }
    function closeCheckoutForm() {
        state.selectedCheckoutVisit = null;
        checkoutPanel.hidden = true;
        checkoutFormNode.hidden = true;
        checkoutTargetNode.textContent = '';
    }
    function closeReceipt() {
        state.selectedReceiptVisit = null;
        state.receiptLabel = '';
        receiptPanel.hidden = true;
        receiptBodyNode.innerHTML = '';
    }
    function receiptRow(label, value) {
        return '<p class="cpms-finance-board__receipt-row"><span class="cpms-finance-board__muted">' + esc(label) + '</span><span>' + esc(value) + '</span></p>';
    }
    function methodLabel(method) {
        var labels = { cash: 'نقد', card_pos: 'کارت‌خوان — ثبت دستی', other: 'سایر' };
        return labels[method] || method;
    }
    // The receipt body is a bounded projection of the server's durable
    // payment truth; every value is escaped and nothing beyond the printed
    // receipt fields is rendered.
    function renderReceipt(receipt) {
        var totals = receipt.totals || {};
        var currency = String(totals.currency || '');
        var items = Array.isArray(receipt.items) ? receipt.items : [];
        var payments = Array.isArray(receipt.payments) ? receipt.payments : [];
        var itemRows = items.map(function (item) {
            return '<tr><td>' + esc(item.description) + '</td><td>' + esc(item.quantity) + '</td><td>' + esc(item.unit_price) + '</td><td>' + esc(item.amount) + '</td></tr>';
        }).join('');
        var paymentRows = payments.map(function (payment) {
            return receiptRow(
                String(payment.payment_number || '') + ' · ' + methodLabel(String(payment.method || '')) + ' · ' + String(payment.amount || '') + ' ' + currency,
                String(payment.paid_at || '') + (payment.jalali_paid_at ? ' · ' + String(payment.jalali_paid_at) : '')
            );
        }).join('');
        receiptBodyNode.innerHTML =
            '<p class="cpms-finance-board__target">' + esc((receipt.clinic || {}).name || '') +
                (receipt.clinic && receipt.clinic.phone ? ' · ' + esc(receipt.clinic.phone) : '') + '</p>' +
            (receipt.clinic && receipt.clinic.address ? '<p class="cpms-finance-board__hint">' + esc(receipt.clinic.address) + '</p>' : '') +
            receiptRow('بیمار', (receipt.patient || {}).name || '') +
            receiptRow('شمارهٔ فاکتور', receipt.invoice_number || '') +
            receiptRow('تاریخ فاکتور', String(receipt.invoice_date || '') + (receipt.jalali_invoice_date ? ' · ' + String(receipt.jalali_invoice_date) : '')) +
            '<table class="cpms-finance-board__receipt-table"><thead><tr><th>شرح</th><th>تعداد</th><th>مبلغ واحد</th><th>مبلغ</th></tr></thead><tbody>' + itemRows + '</tbody></table>' +
            receiptRow('جمع جزء', String(totals.subtotal || '') + ' ' + currency) +
            receiptRow('تخفیف', String(totals.discount || '') + ' ' + currency) +
            receiptRow('مالیات', String(totals.tax || '') + ' ' + currency) +
            '<p class="cpms-finance-board__receipt-row cpms-finance-board__receipt-total"><span>مبلغ کل</span><span>' + esc(String(totals.total || '') + ' ' + currency) + '</span></p>' +
            receiptRow('پرداخت‌شده', String(totals.paid_amount || '') + ' ' + currency) +
            receiptRow('باقی‌مانده', String(totals.balance || '') + ' ' + currency) +
            paymentRows;
    }
    function openReceipt(visitId) {
        if (state.busy || !visitId) return;
        state.busy = true;
        request('/staff/portal/finance/visits/' + Number(visitId) + '/receipt').then(function (result) {
            state.busy = false;
            if (!result.ok) {
                var code = result.body && result.body.code || '';
                if (code === 'CLINIC_RECEIPT_NOT_ELIGIBLE') {
                    // Fail closed: accounting history that cannot be proven as
                    // the normal fully paid path is never turned into a receipt.
                    closeReceipt();
                    setStatus('رسید این فاکتور در دسترس نیست؛ مسیر تسویهٔ عادی و کامل آن قابل اثبات نیست.', 'error');
                    return;
                }
                setStatus(showError(result, 'دریافت رسید ناموفق بود.'), 'error');
                return;
            }
            var data = payload(result);
            state.selectedReceiptVisit = Number(visitId);
            state.receiptLabel = String((data.receipt || {}).invoice_number || '');
            renderReceipt(data.receipt || {});
            receiptPanel.hidden = false;
            setStatus('رسید از دادهٔ پایدار سرور خوانده شد.', '');
        }).catch(function () {
            state.busy = false;
            setStatus('ارتباط با سرویس مالی برقرار نشد.', 'error');
        });
    }
    // Browser print only: the receipt surface is isolated from the portal
    // chrome while the browser print dialog is active, then the page returns
    // to its normal state. No server-side document is produced.
    function printReceipt() {
        if (!state.selectedReceiptVisit) return;
        var previousTitle = document.title;
        document.title = 'رسید ' + String(state.receiptLabel || '');
        document.body.classList.add('cpms-finance-printing');
        var cleanup = function () {
            document.body.classList.remove('cpms-finance-printing');
            document.title = previousTitle;
            window.removeEventListener('afterprint', cleanup);
        };
        window.addEventListener('afterprint', cleanup);
        try {
            window.print();
        } finally {
            window.setTimeout(cleanup, 1500);
        }
    }
    function loadPaid() {
        if (state.busy) return Promise.resolve();
        if (state.locations.length > 1 && !state.locationId) return Promise.resolve();
        state.busy = true;
        return request('/staff/portal/finance/paid').then(function (result) {
            if (!result.ok) { setStatus(showError(result, 'دریافت تختهٔ پرداخت‌شده ناموفق بود.'), 'error'); return; }
            renderPaid(payload(result));
        }).catch(function () {
            setStatus('ارتباط با تختهٔ پرداخت‌شده برقرار نشد.', 'error');
        }).then(function () {
            state.busy = false;
        });
    }
    function submitCheckout() {
        if (state.busy || !state.selectedCheckoutVisit) return;
        state.busy = true;
        checkoutSubmitNode.disabled = true;
        request('/staff/portal/finance/visits/' + Number(state.selectedCheckoutVisit) + '/checkout', 'POST', {}).then(function (result) {
            state.busy = false;
            checkoutSubmitNode.disabled = false;
            if (!result.ok) {
                var message = result.body && result.body.message ? result.body.message : 'خروج ویزیت ناموفق بود.';
                setStatus(message, 'error');
                refreshAll();
                return;
            }
            closeCheckoutForm();
            setStatus('ویزیت خارج شد؛ فهرست از سرور به‌روز شد.', '');
            refreshAll();
        }).catch(function () {
            state.busy = false;
            checkoutSubmitNode.disabled = false;
            setStatus('ارتباط با سرویس مالی برقرار نشد.', 'error');
        });
    }
    function loadEligible() {
        if (!state.canIssue) return Promise.resolve();
        if (state.busy) return Promise.resolve();
        if (state.locations.length > 1 && !state.locationId) return Promise.resolve();
        state.busy = true;
        return request('/staff/portal/finance/invoice-eligible').then(function (result) {
            if (!result.ok) { setStatus(showError(result, 'دریافت فهرست ویزیت‌های قابل صدور ناموفق بود.'), 'error'); return; }
            renderEligible(payload(result));
        }).catch(function () {
            setStatus('ارتباط با سرویس مالی برقرار نشد.', 'error');
        }).then(function () {
            state.busy = false;
        });
    }
    function loadBoard() {
        if (state.busy) return Promise.resolve();
        if (state.locations.length > 1 && !state.locationId) {
            setStatus('برای مشاهدهٔ اطلاعات مالی، یک موقعیت را انتخاب کنید.', 'error');
            return Promise.resolve();
        }
        state.busy = true;
        locationSelect.disabled = true;
        return request('/staff/portal/finance/awaiting-payment').then(function (result) {
            if (!result.ok) { setStatus(showError(result, 'دریافت تختهٔ مالی ناموفق بود.'), 'error'); return; }
            renderBoard(payload(result));
        }).catch(function () {
            setStatus('ارتباط با تختهٔ مالی برقرار نشد.', 'error');
        }).then(function () {
            state.busy = false;
            locationSelect.disabled = false;
        });
    }
    function refreshAll() {
        return loadEligible().then(function () { return loadBoard(); }).then(function () { return loadPaid(); });
    }
    function submitIssue() {
        if (state.busy || !state.selectedVisit) return;
        var description = String(descriptionInput.value || '').trim();
        var quantity = Number(quantityInput.value);
        var unitPrice = Number(priceInput.value);
        if (!description) { setStatus('شرح خدمت الزامی است.', 'error'); return; }
        if (!isFinite(quantity) || quantity <= 0) { setStatus('تعداد باید بزرگ‌تر از صفر باشد.', 'error'); return; }
        if (!isFinite(unitPrice) || unitPrice < 0 || Math.floor(unitPrice) !== unitPrice) { setStatus('مبلغ واحد باید عدد صحیح غیرمنفی (ریال) باشد.', 'error'); return; }
        state.busy = true;
        submitNode.disabled = true;
        request('/staff/portal/finance/visits/' + Number(state.selectedVisit) + '/invoice', 'POST', {
            items: [{ description: description, quantity: quantity, unit_price: unitPrice }]
        }).then(function (result) {
            state.busy = false;
            submitNode.disabled = false;
            if (!result.ok) {
                setStatus(showError(result, 'صدور فاکتور ناموفق بود.'), 'error');
                refreshAll();
                return;
            }
            var invoice = payload(result);
            closeIssueForm();
            setStatus('فاکتور ' + String(invoice.invoice_number || '') + ' صادر شد.', '');
            refreshAll();
        }).catch(function () {
            state.busy = false;
            submitNode.disabled = false;
            setStatus('ارتباط با سرویس مالی برقرار نشد.', 'error');
        });
    }

    function submitPayment() {
        if (state.busy || !state.selectedInvoice) return;
        var amount = Number(payAmountInput.value);
        var method = String(payMethodSelect.value || '');
        if (!isFinite(amount) || amount <= 0 || Math.floor(amount) !== amount) {
            setStatus('مبلغ پرداخت باید عدد صحیح مثبت (ریال) باشد.', 'error');
            return;
        }
        if (method !== 'cash' && method !== 'card_pos' && method !== 'other') {
            setStatus('روش پرداخت را انتخاب کنید.', 'error');
            return;
        }
        var body = { amount: amount, method: method };
        var reference = String(payRefInput.value || '').trim();
        if (reference) body.transaction_ref = reference;
        var key = state.paymentKey || paymentKey();
        state.paymentKey = key;
        state.busy = true;
        paySubmitNode.disabled = true;
        request('/staff/portal/finance/invoices/' + Number(state.selectedInvoice) + '/payments', 'POST', body, { 'Idempotency-Key': key }).then(function (result) {
            state.busy = false;
            paySubmitNode.disabled = false;
            if (!result.ok) {
                setStatus(showError(result, 'ثبت پرداخت ناموفق بود.'), 'error');
                refreshAll();
                return;
            }
            var data = payload(result);
            var replay = data && data.idempotent_replay === true;
            closePayForm();
            setStatus(replay ? 'این پرداخت پیش‌تر ثبت شده بود؛ همان رکورد بازخوانی شد.' : 'پرداخت ' + String(amount) + ' ریال ثبت شد.', '');
            refreshAll();
        }).catch(function () {
            state.busy = false;
            paySubmitNode.disabled = false;
            setStatus('ارتباط با سرویس مالی برقرار نشد.', 'error');
        });
    }

    rowsNode.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-role="finance-pay-open"]') : null;
        if (!trigger) return;
        openPayForm(
            trigger.getAttribute('data-invoice-id'),
            String(trigger.getAttribute('data-patient') || ''),
            String(trigger.getAttribute('data-remaining') || ''),
            String(trigger.getAttribute('data-currency') || '')
        );
    });
    payFormNode.addEventListener('submit', function (event) {
        event.preventDefault();
        submitPayment();
    });
    payCancelNode.addEventListener('click', function () { closePayForm(); });

    paidRowsNode.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-role="finance-checkout-open"]') : null;
        if (!trigger) return;
        openCheckoutForm(
            trigger.getAttribute('data-visit-id'),
            String(trigger.getAttribute('data-patient') || ''),
            String(trigger.getAttribute('data-remaining') || ''),
            String(trigger.getAttribute('data-currency') || '')
        );
    });
    checkoutFormNode.addEventListener('submit', function (event) {
        event.preventDefault();
        submitCheckout();
    });
    checkoutCancelNode.addEventListener('click', function () { closeCheckoutForm(); });

    paidRowsNode.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-role="finance-receipt-open"]') : null;
        if (!trigger) return;
        openReceipt(trigger.getAttribute('data-visit-id'));
    });
    receiptPrintNode.addEventListener('click', function () { printReceipt(); });
    receiptCloseNode.addEventListener('click', function () { closeReceipt(); });

    eligibleRowsNode.addEventListener('click', function (event) {
        var trigger = event.target && event.target.closest ? event.target.closest('[data-role="finance-issue-open"]') : null;
        if (!trigger) return;
        openIssueForm(Number(trigger.getAttribute('data-visit-id')), String(trigger.getAttribute('data-patient') || ''));
    });
    formNode.addEventListener('submit', function (event) {
        event.preventDefault();
        submitIssue();
    });
    cancelNode.addEventListener('click', function () { closeIssueForm(); });
    locationSelect.addEventListener('change', function () {
        state.locationId = Number(locationSelect.value || 0) || null;
        rowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="5">در حال بارگذاری…</td></tr>';
        eligibleRowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="5">در حال بارگذاری…</td></tr>';
        paidRowsNode.innerHTML = '<tr><td class="cpms-finance-board__empty" colspan="6">در حال بارگذاری…</td></tr>';
        closeIssueForm();
        closePayForm();
        closeCheckoutForm();
        closeReceipt();
        refreshAll();
    });

    eligiblePanel.hidden = true;
    request('/staff/portal/finance/context').then(function (result) {
        if (!result.ok) { setStatus(showError(result, 'دریافت محدودهٔ مالی ناموفق بود.'), 'error'); return; }
        var context = payload(result);
        state.clinicId = Number(context.clinic_id || 0) || null;
        state.locations = Array.isArray(context.locations) ? context.locations : [];
        state.locationId = Number(context.location_id || 0) || null;
        state.canIssue = context.can_issue_invoice === true;
        state.canCapture = context.can_capture_payment === true;
        state.canCheckOut = context.can_check_out === true;
        eligiblePanel.hidden = !state.canIssue;
        if (!state.canCapture) closePayForm();
        if (!state.canCheckOut) closeCheckoutForm();
        if (state.locations.length > 1) {
            locationSelect.hidden = false;
            locationSelect.innerHTML = '<option value="">انتخاب موقعیت</option>' + state.locations.map(function (location) {
                return '<option value="' + Number(location.id) + '">' + esc(location.name) + '</option>';
            }).join('');
            locationSelect.value = state.locationId ? String(state.locationId) : '';
        }
        refreshAll();
    }).catch(function () { setStatus('دریافت محدودهٔ مالی ناموفق بود.', 'error'); });
}());
</script>
</div>

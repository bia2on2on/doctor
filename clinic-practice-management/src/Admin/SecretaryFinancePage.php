<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * داشبورد مالی منشی (F6 — D12–D18/P3/G2).
 *
 * - فقط برای کاربر با cpms_finance_read (اکشن‌ها با cap خودشان در REST چک می‌شوند)
 * - تب «تعرفه‌ها» فقط با cpms_config (admin وردپرس — P-3)
 * - رسید: JSON ساخت‌یافته از REST → نمای چاپ (window.print) — PDF سمت سرور
 *   در Backlog است (بدون Dependency جدید — engineering-baseline §2)
 * - Idempotency-Key پرداخت: crypto.randomUUID (TP-02 — هر ارسال یک کلید ثابت)
 * - Assets فقط روی همین صفحه — JS inline بدون وابستگی؛ بدون PHI در HTML اولیه
 */
final class SecretaryFinancePage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'cpms-queue',
            'مالی و تسویه',
            'مالی و تسویه',
            RolesAndCapabilities::FINANCE_READ,
            'cpms-finance',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::FINANCE_READ)) {
            wp_die('دسترسی ندارید', 403);
        }

        $config = [
            'rest_url' => esc_url_raw(rest_url('clinic/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'today' => gmdate('Y-m-d'),
            'can_invoice' => current_user_can(RolesAndCapabilities::INVOICE_CREATE),
            'can_payment' => current_user_can(RolesAndCapabilities::PAYMENT_CREATE),
            'can_void' => current_user_can(RolesAndCapabilities::PAYMENT_VOID),
            'can_refund' => current_user_can(RolesAndCapabilities::PAYMENT_REFUND),
            'can_adjust' => current_user_can(RolesAndCapabilities::INVOICE_ADJUST),
            'can_config' => current_user_can(RolesAndCapabilities::CONFIG),
            // ویزیت انتخاب‌شده از صف امروز (?visit=ID) — پیش‌بارگذاری فرم صدور/تسویه
            'focus_visit' => isset($_GET['visit']) ? (int) $_GET['visit'] : 0,
        ];
        ?>
<div class="wrap" dir="rtl" id="cpms-fin-wrap">
    <h1 class="cpms-fin-title">مالی و تسویه</h1>

    <div id="cpms-fin-notice" class="notice" style="display:none"></div>

    <h2 class="nav-tab-wrapper" id="cpms-fin-tabs">
        <a href="#dashboard" class="nav-tab nav-tab-active" data-tab="dashboard">داشبورد</a>
        <a href="#awaiting" class="nav-tab" data-tab="awaiting">در انتظار تسویه</a>
        <a href="#balances" class="nav-tab" data-tab="balances">بدهی‌های باز</a>
        <?php if ($config['can_config']): ?>
            <a href="#services" class="nav-tab" data-tab="services">تعرفه‌ها</a>
        <?php endif; ?>
    </h2>

    <!-- ================= داشبورد (D18) ================= -->
    <div id="cpms-fin-tab-dashboard" class="cpms-fin-tab">
        <div class="cpms-fin-toolbar">
            <label>از <input type="date" id="cpms-fin-from" dir="ltr"></label>
            <label>تا <input type="date" id="cpms-fin-to" dir="ltr"></label>
            <button type="button" class="button" id="cpms-fin-summary-btn">به‌روزرسانی</button>
        </div>
        <div class="cpms-fin-stats" id="cpms-fin-stats"></div>
        <h3>روش‌های دریافت امروز</h3>
        <div class="cpms-fin-stats" id="cpms-fin-methods"></div>
        <h3>آخرین پرداخت‌های بازه</h3>
        <table class="widefat striped">
            <thead>
                <tr><th>شماره</th><th>فاکتور</th><th>مبلغ (ریال)</th><th>روش</th><th>وضعیت</th><th>تاریخ</th></tr>
            </thead>
            <tbody id="cpms-fin-payments-tbody">
                <tr><td colspan="6">در حال بارگذاری…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ================= در انتظار تسویه (صدور فاکتور D12) ================= -->
    <div id="cpms-fin-tab-awaiting" class="cpms-fin-tab" style="display:none">
        <p class="description">
            مراجععین امروز که ویزیتشان پایان یافته ولی هنوز تسویه نشده‌اند.
            «صدور فاکتور» فقط برای <code>consultation_completed</code> و «تسویه» برای <code>awaiting_payment</code>.
        </p>
        <table class="widefat striped">
            <thead>
                <tr><th>بیمار</th><th>پزشک</th><th>وضعیت ویزیت</th><th>ساعت ورود</th><th>اقدام</th></tr>
            </thead>
            <tbody id="cpms-fin-awaiting-tbody">
                <tr><td colspan="5">در حال بارگذاری…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ================= بدهی‌های باز (FR-14.8) ================= -->
    <div id="cpms-fin-tab-balances" class="cpms-fin-tab" style="display:none">
        <table class="widefat striped">
            <thead>
                <tr><th>شماره فاکتور</th><th>بیمار</th><th>MRN</th><th>باقیمانده (ریال)</th><th>وضعیت</th><th>اقدام</th></tr>
            </thead>
            <tbody id="cpms-fin-balances-tbody">
                <tr><td colspan="6">در حال بارگذاری…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ================= تعرفه‌ها (G2 — cpms_config) ================= -->
    <?php if ($config['can_config']): ?>
    <div id="cpms-fin-tab-services" class="cpms-fin-tab" style="display:none">
        <div class="card cpms-fin-panel">
            <h3>افزودن / ویرایش تعرفه</h3>
            <div class="cpms-fin-form">
                <input type="hidden" id="cpms-fin-svc-id" value="">
                <label>کد <input type="text" id="cpms-fin-svc-code" dir="ltr" placeholder="VISIT"></label>
                <label>نام <input type="text" id="cpms-fin-svc-name" placeholder="ویزیت تخصصی"></label>
                <label>قیمت (ریال) <input type="number" id="cpms-fin-svc-price" dir="ltr" min="0" step="1"></label>
                <div>
                    <button type="button" class="button button-primary" id="cpms-fin-svc-save">ذخیره</button>
                    <button type="button" class="button" id="cpms-fin-svc-cancel" style="display:none">انصراف</button>
                </div>
            </div>
        </div>
        <table class="widefat striped">
            <thead>
                <tr><th>کد</th><th>نام</th><th>قیمت (ریال)</th><th>وضعیت</th><th>اقدام</th></tr>
            </thead>
            <tbody id="cpms-fin-svc-tbody">
                <tr><td colspan="5">در حال بارگذاری…</td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Drawer صدور فاکتور (D12) -->
<div id="cpms-fin-issue" class="cpms-fin-drawer" dir="rtl" style="display:none">
    <div class="cpms-fin-drawer-head">
        <strong id="cpms-fin-issue-title">صدور فاکتور</strong>
        <button type="button" class="button" id="cpms-fin-issue-close" title="Esc">✕</button>
    </div>
    <div class="cpms-fin-drawer-body" id="cpms-fin-issue-body"></div>
</div>

<!-- Drawer تسویه فاکتور (D13/D14/P3/D15/D17) -->
<div id="cpms-fin-inv" class="cpms-fin-drawer" dir="rtl" style="display:none">
    <div class="cpms-fin-drawer-head">
        <strong id="cpms-fin-inv-title">فاکتور</strong>
        <button type="button" class="button" id="cpms-fin-inv-close" title="Esc">✕</button>
    </div>
    <div class="cpms-fin-drawer-body" id="cpms-fin-inv-body"></div>
</div>
<div id="cpms-fin-backdrop" style="display:none"></div>

<style>
.cpms-fin-title { margin-bottom: 12px; }
.cpms-fin-tab { margin-top: 12px; }
.cpms-fin-toolbar { display: flex; gap: 10px; align-items: center; margin: 10px 0 14px; flex-wrap: wrap; }
.cpms-fin-stats { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.cpms-fin-stat { background: #fff; border: 1px solid #dcdcde; border-inline-start: 4px solid #2271b1;
    border-radius: 4px; padding: 8px 14px; min-width: 120px; }
.cpms-fin-stat b { display: block; font-size: 20px; }
.cpms-fin-stat span { color: #646970; font-size: 12px; }
.cpms-fin-panel { max-width: 560px; margin-bottom: 16px; padding: 8px 14px; }
.cpms-fin-form { display: flex; flex-direction: column; gap: 8px; }
.cpms-fin-form label { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.cpms-fin-form input, .cpms-fin-form select { flex: 1; }
.cpms-fin-items { margin: 8px 0; }
.cpms-fin-item { display: grid; grid-template-columns: 1fr 70px 110px 100px 34px; gap: 6px; margin: 4px 0; align-items: center; }
.cpms-fin-drawer { position: fixed; top: 0; bottom: 0; inset-inline-start: 0; width: 460px; max-width: 94vw;
    background: #fff; box-shadow: 0 0 20px rgba(0,0,0,.25); z-index: 100002; overflow-y: auto; }
.cpms-fin-drawer-head { display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; border-bottom: 1px solid #dcdcde; position: sticky; top: 0; background: #fff; }
.cpms-fin-drawer-body { padding: 12px 16px; }
#cpms-fin-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.3); z-index: 100001; }
.cpms-fin-badge { display: inline-block; font-size: 11px; border-radius: 8px; padding: 1px 8px; }
.cpms-fin-badge.paid { background: #edfaef; color: #1c7c3c; border: 1px solid #1c7c3c; }
.cpms-fin-badge.partial { background: #fcf0f1; color: #b32d2e; border: 1px solid #b32d2e; }
.cpms-fin-badge.open { background: #f0f6fc; color: #2271b1; border: 1px solid #2271b1; }
.cpms-fin-badge.captured { background: #edfaef; color: #1c7c3c; }
.cpms-fin-badge.voided, .cpms-fin-badge.refunded { background: #f6f7f7; color: #8c8f94; }
.cpms-fin-sep { border-top: 1px solid #dcdcde; margin: 12px 0; }
.cpms-fin-muted { color: #646970; font-size: 12px; }
</style>

<script>
window.CPMS_FIN = <?php echo wp_json_encode($config); ?>;
</script>
<script>
(function () {
    'use strict';
    if (!window.CPMS_FIN) { return; }
    var CFG = window.CPMS_FIN;
    var state = { summary: null, services: [], issueVisit: null, invoice: null, lastIdemKey: null };

    var STATUS_LABELS = {
        consultation_completed: 'پایان ویزیت', awaiting_payment: 'در انتظار پرداخت', paid: 'پرداخت‌شده'
    };
    var METHOD_LABELS = { cash: 'نقدی', card_pos: 'کارت‌خوان', online: 'آنلاین', other: 'سایر' };

    function api(method, path, body, extraHeaders) {
        var opts = { method: method, headers: Object.assign({
            'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json'
        }, extraHeaders || {}) };
        if (body) { opts.body = JSON.stringify(body); }
        return fetch(CFG.rest_url + path, opts).then(function (r) {
            return r.json().then(function (j) { return { __status: r.status, body: j }; });
        });
    }

    function notice(msg, kind) {
        var el = document.getElementById('cpms-fin-notice');
        el.className = 'notice notice-' + (kind || 'info');
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(notice._t);
        notice._t = setTimeout(function () { el.style.display = 'none'; }, 5000);
    }

    function errMessage(resp) {
        if (!resp || !resp.body) { return 'خطای نامشخص'; }
        var d = resp.body.data || resp.body;
        return d.message || d.code || 'خطا';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function fmt(n) { return Number(n || 0).toLocaleString('fa-IR'); }

    function fmtTime(dt) {
        if (!dt) { return '—'; }
        var d = new Date(String(dt).replace(' ', 'T') + 'Z');
        return isNaN(d) ? dt : d.toLocaleString('fa-IR');
    }

    // ---------- تب‌ها ----------

    document.getElementById('cpms-fin-tabs').addEventListener('click', function (e) {
        var a = e.target.closest('a[data-tab]');
        if (!a) { return; }
        e.preventDefault();
        document.querySelectorAll('#cpms-fin-tabs a').forEach(function (x) { x.classList.remove('nav-tab-active'); });
        a.classList.add('nav-tab-active');
        document.querySelectorAll('.cpms-fin-tab').forEach(function (t) { t.style.display = 'none'; });
        document.getElementById('cpms-fin-tab-' + a.getAttribute('data-tab')).style.display = 'block';
    });

    // ---------- داشبورد (D18) ----------

    function loadSummary() {
        var from = document.getElementById('cpms-fin-from').value || CFG.today;
        var to = document.getElementById('cpms-fin-to').value || CFG.today;
        return api('GET', 'finance/summary?from=' + from + '&to=' + to).then(function (r) {
            if (r.__status !== 200) { throw new Error(errMessage(r)); }
            state.summary = r.body.data;
            renderSummary();
        });
    }

    function renderSummary() {
        var s = state.summary;
        var rev = s.revenue || {};
        var ob = s.open_balances || {};
        var stats = [
            ['دریافتی بازه', fmt(rev.total), '#1c7c3c'],
            ['بازپرداختی', fmt(rev.refunded), '#b32d2e'],
            ['تعداد تراکنش', fmt(rev.payment_count), '#2271b1'],
            ['بدهی باز (کل)', fmt(ob.total), '#e07b39'],
            ['فاکتور باز', fmt(ob.invoice_count), '#8c8f94']
        ];
        document.getElementById('cpms-fin-stats').innerHTML = stats.map(function (c) {
            return '<div class="cpms-fin-stat" style="border-inline-start-color:' + c[2] + '">' +
                '<b>' + c[1] + '</b><span>' + c[0] + '</span></div>';
        }).join('');

        var methods = rev.by_method || {};
        var mHtml = Object.keys(METHOD_LABELS).map(function (m) {
            return '<div class="cpms-fin-stat"><b>' + fmt(methods[m] || 0) + '</b><span>' + METHOD_LABELS[m] + '</span></div>';
        }).join('');
        document.getElementById('cpms-fin-methods').innerHTML = mHtml;

        document.getElementById('cpms-fin-payments-tbody').innerHTML = (s.payments || []).length === 0
            ? '<tr><td colspan="6">پرداختی در این بازه نیست.</td></tr>'
            : s.payments.map(function (p) {
                return '<tr><td dir="ltr">' + esc(p.payment_number) + '</td>' +
                    '<td dir="ltr">#' + p.invoice_id + '</td>' +
                    '<td>' + fmt(p.amount) + '</td>' +
                    '<td>' + (METHOD_LABELS[p.method] || esc(p.method)) + '</td>' +
                    '<td><span class="cpms-fin-badge ' + esc(p.status) + '">' + esc(p.status) + '</span></td>' +
                    '<td dir="ltr">' + fmtTime(p.paid_at) + '</td></tr>';
            }).join('');

        renderBalances();
    }

    document.getElementById('cpms-fin-summary-btn').addEventListener('click', function () {
        loadSummary().catch(function (e) { notice(String(e.message || e), 'error'); });
    });

    // ---------- در انتظار تسویه (از صف امروز) ----------

    function loadAwaiting() {
        return api('GET', 'secretary/today').then(function (r) {
            if (r.__status !== 200) { throw new Error(errMessage(r)); }
            var rows = (r.body.data.queue || []).filter(function (v) {
                return v.status === 'consultation_completed' || v.status === 'awaiting_payment';
            });
            var tbody = document.getElementById('cpms-fin-awaiting-tbody');
            tbody.innerHTML = rows.length === 0
                ? '<tr><td colspan="5">مراجعه تسویه‌نشده‌ای نیست. 👌</td></tr>'
                : rows.map(function (v) {
                    var hasInvoice = v.status === 'awaiting_payment';
                    var act = hasInvoice
                        ? '<button class="button button-small button-primary" data-act="settle" data-id="' + v.id + '">تسویه</button>'
                        : (CFG.can_invoice
                            ? '<button class="button button-small button-primary" data-act="issue" data-id="' + v.id + '">صدور فاکتور</button>'
                            : '—');
                    return '<tr><td><strong>' + esc(v.patient_name) + '</strong></td>' +
                        '<td>' + esc(v.clinician_name || '—') + '</td>' +
                        '<td>' + (STATUS_LABELS[v.status] || v.status) + '</td>' +
                        '<td dir="ltr">' + fmtTime(v.check_in_at) + '</td>' +
                        '<td>' + act + '</td></tr>';
                }).join('');
        });
    }

    document.getElementById('cpms-fin-awaiting-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) { return; }
        var id = parseInt(btn.getAttribute('data-id'), 10);
        if (btn.getAttribute('data-act') === 'issue') { openIssue(id); return; }
        openInvoiceByVisit(id);
    });

    // ---------- بدهی‌های باز (FR-14.8) ----------

    function renderBalances() {
        var invs = (state.summary && state.summary.open_balances && state.summary.open_balances.invoices) || [];
        document.getElementById('cpms-fin-balances-tbody').innerHTML = invs.length === 0
            ? '<tr><td colspan="6">بدهی بازی وجود ندارد.</td></tr>'
            : invs.map(function (v) {
                return '<tr><td dir="ltr">' + esc(v.invoice_number) + '</td>' +
                    '<td>' + esc(v.patient_name) + '</td>' +
                    '<td dir="ltr">' + esc(v.mrn) + '</td>' +
                    '<td><strong>' + fmt(v.balance) + '</strong></td>' +
                    '<td><span class="cpms-fin-badge ' + esc(v.status) + '">' + esc(v.status) + '</span></td>' +
                    '<td><button class="button button-small" data-inv="' + v.id + '">جزئیات</button></td></tr>';
            }).join('');
    }

    document.getElementById('cpms-fin-balances-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-inv]');
        if (!btn) { return; }
        openInvoice(parseInt(btn.getAttribute('data-inv'), 10));
    });

    // ---------- تعرفه‌ها (G2 — فقط با cpms_config؛ المان‌ها فقط در آن حالت رندر می‌شوند) ----------

    function loadServices() {
        if (!document.getElementById('cpms-fin-svc-tbody')) { return Promise.resolve(); }
        return api('GET', 'config/services?scope=all').then(function (r) {
            if (r.__status !== 200) { throw new Error(errMessage(r)); }
            state.services = r.body.data || [];
            renderServices();
        });
    }

    function renderServices() {
        document.getElementById('cpms-fin-svc-tbody').innerHTML = state.services.length === 0
            ? '<tr><td colspan="5">تعرفه‌ای ثبت نشده است.</td></tr>'
            : state.services.map(function (s) {
                return '<tr><td dir="ltr">' + esc(s.code) + '</td>' +
                    '<td>' + esc(s.name) + '</td>' +
                    '<td>' + fmt(s.price) + '</td>' +
                    '<td>' + (s.is_active ? 'فعال' : 'غیرفعال') + '</td>' +
                    '<td>' +
                    '<button class="button button-small" data-sact="edit" data-id="' + s.id + '">ویرایش</button> ' +
                    (s.is_active ? '<button class="button button-small" data-sact="deactivate" data-id="' + s.id + '">غیرفعال</button>' : '') +
                    '</td></tr>';
            }).join('');
    }

    var svcTbody = document.getElementById('cpms-fin-svc-tbody');
    if (svcTbody) {
        svcTbody.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-sact]');
            if (!btn) { return; }
            var id = parseInt(btn.getAttribute('data-id'), 10);
            var s = state.services.filter(function (x) { return x.id === id; })[0];
            if (!s) { return; }
            if (btn.getAttribute('data-sact') === 'edit') {
                document.getElementById('cpms-fin-svc-id').value = s.id;
                document.getElementById('cpms-fin-svc-code').value = s.code;
                document.getElementById('cpms-fin-svc-name').value = s.name;
                document.getElementById('cpms-fin-svc-price').value = s.price;
                document.getElementById('cpms-fin-svc-cancel').style.display = 'inline-block';
            } else {
                if (!window.confirm('تعرفه «' + s.name + '» غیرفعال شود؟ (اقلام فاکتور تاریخی حفظ می‌شوند)')) { return; }
                api('DELETE', 'config/services/' + id).then(function (r) {
                    if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
                    notice('تعرفه غیرفعال شد', 'success');
                    loadServices().catch(function () {});
                });
            }
        });
    }

    var svcSave = document.getElementById('cpms-fin-svc-save');
    if (svcSave) {
        svcSave.addEventListener('click', function () {
            var id = document.getElementById('cpms-fin-svc-id').value;
            var body = {
                code: document.getElementById('cpms-fin-svc-code').value.trim(),
                name: document.getElementById('cpms-fin-svc-name').value.trim(),
                price: parseInt(document.getElementById('cpms-fin-svc-price').value, 10)
            };
            if (!body.code || !body.name || isNaN(body.price) || body.price < 0) {
                notice('کد، نام و قیمت صحیح وارد کنید', 'warning');
                return;
            }
            api('POST', id ? 'config/services/' + id : 'config/services', body).then(function (r) {
                if (r.__status !== 200 && r.__status !== 201) { notice(errMessage(r), 'error'); return; }
                notice('ذخیره شد', 'success');
                resetSvcForm();
                loadServices().catch(function () {});
            });
        });
        document.getElementById('cpms-fin-svc-cancel').addEventListener('click', resetSvcForm);
    }

    function resetSvcForm() {
        document.getElementById('cpms-fin-svc-id').value = '';
        document.getElementById('cpms-fin-svc-code').value = '';
        document.getElementById('cpms-fin-svc-name').value = '';
        document.getElementById('cpms-fin-svc-price').value = '';
        document.getElementById('cpms-fin-svc-cancel').style.display = 'none';
    }

    // ---------- صدور فاکتور (D12) ----------

    function openIssue(visitId) {
        state.issueVisit = visitId;
        var body = document.getElementById('cpms-fin-issue-body');
        var options = state.services.filter(function (s) { return s.is_active; })
            .map(function (s) { return '<option value="' + s.id + '">' + esc(s.name) + ' — ' + fmt(s.price) + ' ریال</option>'; })
            .join('');
        body.innerHTML =
            '<p class="description">اقلام فاکتور — از تعرفه یا قلم دستی (شرح + قیمت). مبالغ به ریال.</p>' +
            '<div class="cpms-fin-items" id="cpms-fin-items"></div>' +
            '<button type="button" class="button" id="cpms-fin-add-svc"' + (options ? '' : ' style="display:none"') + '>＋ از تعرفه‌ها</button> ' +
            '<button type="button" class="button" id="cpms-fin-add-manual">＋ قلم دستی</button>' +
            '<div class="cpms-fin-sep"></div>' +
            '<div class="cpms-fin-form">' +
            '<label>تخفیف کل (ریال) <input type="number" id="cpms-fin-inv-discount" dir="ltr" min="0" step="1" value="0"></label>' +
            '<label>مالیات (ریال) <input type="number" id="cpms-fin-inv-tax" dir="ltr" min="0" step="1" value="0"></label>' +
            '<button type="button" class="button button-primary" id="cpms-fin-issue-submit">صدور فاکتور</button>' +
            '</div>';
        document.getElementById('cpms-fin-issue-title').textContent = 'صدور فاکتور — ویزیت #' + visitId;
        document.getElementById('cpms-fin-issue').style.display = 'block';
        document.getElementById('cpms-fin-backdrop').style.display = 'block';
        document.getElementById('cpms-fin-add-svc').addEventListener('click', function () { addItem(true, options); });
        document.getElementById('cpms-fin-add-manual').addEventListener('click', function () { addItem(false, ''); });
        if (!options) { addItem(false, ''); } else { addItem(true, options); }
        document.getElementById('cpms-fin-issue-submit').addEventListener('click', submitIssue);
    }

    function addItem(fromCatalog, options) {
        var wrap = document.getElementById('cpms-fin-items');
        var row = document.createElement('div');
        row.className = 'cpms-fin-item';
        if (fromCatalog) {
            row.innerHTML = '<select class="cpms-fin-i-svc">' + options + '</select>' +
                '<input type="number" class="cpms-fin-i-qty" dir="ltr" min="0.01" step="1" value="1" title="تعداد">' +
                '<input type="number" class="cpms-fin-i-price" dir="ltr" min="0" step="1" placeholder="قیمت (خالی=تعرفه)">' +
                '<input type="number" class="cpms-fin-i-disc" dir="ltr" min="0" step="1" value="0" title="تخفیف قلم">' +
                '<button type="button" class="button" title="حذف">✕</button>';
        } else {
            row.innerHTML = '<input type="text" class="cpms-fin-i-desc" placeholder="شرح قلم">' +
                '<input type="number" class="cpms-fin-i-qty" dir="ltr" min="0.01" step="1" value="1" title="تعداد">' +
                '<input type="number" class="cpms-fin-i-price" dir="ltr" min="0" step="1" placeholder="قیمت واحد">' +
                '<input type="number" class="cpms-fin-i-disc" dir="ltr" min="0" step="1" value="0" title="تخفیف قلم">' +
                '<button type="button" class="button" title="حذف">✕</button>';
        }
        row.querySelector('button').addEventListener('click', function () {
            row.remove();
            if (document.querySelectorAll('#cpms-fin-items .cpms-fin-item').length === 0) { addItem(false, ''); }
        });
        wrap.appendChild(row);
    }

    function submitIssue() {
        var items = [];
        var ok = true;
        document.querySelectorAll('#cpms-fin-items .cpms-fin-item').forEach(function (row) {
            if (!ok) { return; }
            var svc = row.querySelector('.cpms-fin-i-svc');
            var desc = row.querySelector('.cpms-fin-i-desc');
            var qty = parseFloat(row.querySelector('.cpms-fin-i-qty').value);
            var price = row.querySelector('.cpms-fin-i-price').value;
            var disc = parseInt(row.querySelector('.cpms-fin-i-disc').value || '0', 10);
            var item = { quantity: isNaN(qty) ? 1 : qty, discount: isNaN(disc) ? 0 : disc };
            if (svc) {
                item.service_id = parseInt(svc.value, 10);
                if (!item.service_id) { ok = false; return; }
            } else {
                item.description = desc.value.trim();
                if (!item.description) { ok = false; return; }
            }
            if (price !== '') { item.unit_price = parseInt(price, 10); }
            items.push(item);
        });
        if (!ok || items.length === 0) { notice('هر قلم باید شرح/تعرفه و مقادیر معتبر داشته باشد', 'warning'); return; }
        var body = {
            visit_id: state.issueVisit,
            items: items,
            discount: parseInt(document.getElementById('cpms-fin-inv-discount').value || '0', 10),
            tax: parseInt(document.getElementById('cpms-fin-inv-tax').value || '0', 10)
        };
        api('POST', 'invoices', body).then(function (r) {
            if (r.__status !== 201) { notice(errMessage(r), 'error'); return; }
            notice('فاکتور ' + r.body.data.invoice_number + ' صادر شد', 'success');
            closeDrawers();
            refreshAll();
            openInvoice(r.body.data.id);
        }).catch(function () { notice('خطای شبکه', 'error'); });
    }

    // ---------- فاکتور: تسویه/ابطال/بازپرداخت/اصلاح/رسید ----------

    function openInvoiceByVisit(visitId) {
        api('GET', 'visits/' + visitId + '/invoice').then(function (r) {
            if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
            openInvoice(r.body.data.id, r.body.data);
        });
    }

    function openInvoice(invoiceId, cached) {
        var render = function (inv) {
            state.invoice = inv;
            document.getElementById('cpms-fin-inv-title').textContent = 'فاکتور ' + inv.invoice_number;
            document.getElementById('cpms-fin-inv-body').innerHTML = renderInvoice(inv);
            document.getElementById('cpms-fin-inv').style.display = 'block';
            document.getElementById('cpms-fin-backdrop').style.display = 'block';
            bindInvoiceActions(inv);
        };
        if (cached) { render(cached); return; }
        api('GET', 'invoices/' + invoiceId).then(function (r) {
            if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
            render(r.body.data);
        });
    }

    function renderInvoice(inv) {
        var items = (inv.items || []).map(function (i) {
            return '<tr><td>' + esc(i.description) + '</td><td>' + fmt(i.quantity) + '</td>' +
                '<td>' + fmt(i.unit_price) + '</td><td>' + fmt(i.amount) + '</td></tr>';
        }).join('');
        var pays = (inv.payments || []).map(function (p) {
            return '<tr><td dir="ltr">' + esc(p.payment_number) + '</td><td>' + fmt(p.amount) + '</td>' +
                '<td>' + (METHOD_LABELS[p.method] || esc(p.method)) + '</td>' +
                '<td><span class="cpms-fin-badge ' + esc(p.status) + '">' + esc(p.status) + '</span></td>' +
                '<td>' + fmt(p.refunded_amount || 0) + '</td>' +
                '<td>' + paymentActions(p) + '</td></tr>';
        }).join('');
        var adj = inv.adjustments || { credit: 0, debit: 0 };
        var open = inv.status === 'open' || inv.status === 'partial';
        var html =
            '<table class="widefat striped"><tbody>' +
            '<tr><td style="width:110px">وضعیت</td><td><span class="cpms-fin-badge ' + esc(inv.status) + '">' + esc(inv.status) + '</span></td></tr>' +
            '<tr><td>جمع اقلام</td><td>' + fmt(inv.subtotal) + '</td></tr>' +
            '<tr><td>تخفیف</td><td>' + fmt(inv.discount) + '</td></tr>' +
            '<tr><td>مالیات</td><td>' + fmt(inv.tax) + '</td></tr>' +
            '<tr><td>جمع کل</td><td><strong>' + fmt(inv.total) + '</strong></td></tr>' +
            '<tr><td>پرداخت‌شده</td><td>' + fmt(inv.paid_amount) + '</td></tr>' +
            '<tr><td>باقیمانده</td><td><strong style="color:#b32d2e">' + fmt(inv.balance) + '</strong></td></tr>' +
            '<tr><td>اصلاحات</td><td>اعتبار: ' + fmt(adj.credit) + ' / بدهی: ' + fmt(adj.debit) + '</td></tr>' +
            '</tbody></table>' +
            '<h3>اقلام</h3><table class="widefat striped"><thead><tr><th>شرح</th><th>تعداد</th><th>قیمت واحد</th><th>مبلغ</th></tr></thead><tbody>' + items + '</tbody></table>';
        if (pays) {
            html += '<h3>پرداخت‌ها</h3><table class="widefat striped"><thead><tr><th>شماره</th><th>مبلغ</th><th>روش</th><th>وضعیت</th><th>بازپرداختی</th><th>اقدام</th></tr></thead><tbody>' + pays + '</tbody></table>';
        }
        html += '<div class="cpms-fin-sep"></div>';
        if (CFG.can_payment && open) {
            html += '<h3>ثبت پرداخت (D13)</h3><div class="cpms-fin-form">' +
                '<label>مبلغ (ریال) <input type="number" id="cpms-fin-pay-amount" dir="ltr" min="1" step="1" value="' + (inv.balance || 0) + '"></label>' +
                '<label>روش <select id="cpms-fin-pay-method">' +
                '<option value="cash">نقدی</option><option value="card_pos">کارت‌خوان</option>' +
                '<option value="online">آنلاین</option><option value="other">سایر</option></select></label>' +
                '<label>مرجع (اختیاری) <input type="text" id="cpms-fin-pay-ref" dir="ltr"></label>' +
                '<button type="button" class="button button-primary" id="cpms-fin-pay-submit">ثبت پرداخت</button>' +
                '</div><p class="cpms-fin-muted">Idempotency-Key برای هر دکمه یک‌بار ساخته می‌شود (TP-02) — ارسال تکراری همان پرداخت را برمی‌گرداند.</p>';
        }
        if (CFG.can_adjust && open) {
            html += '<h3>اصلاح فاکتور (D15)</h3><div class="cpms-fin-form">' +
                '<label>نوع <select id="cpms-fin-adj-type"><option value="credit">اعتبار (کسر از بدهی)</option><option value="debit">بدهی (افزایش)</option></select></label>' +
                '<label>مبلغ (ریال) <input type="number" id="cpms-fin-adj-amount" dir="ltr" min="1" step="1"></label>' +
                '<label>دلیل <input type="text" id="cpms-fin-adj-reason"></label>' +
                '<button type="button" class="button" id="cpms-fin-adj-submit">ثبت اصلاح</button>' +
                '</div>';
        }
        html += '<div class="cpms-fin-sep"></div>' +
            '<button type="button" class="button" id="cpms-fin-receipt-btn">🧾 رسید / چاپ</button>';
        return html;
    }

    function paymentActions(p) {
        var btns = [];
        if (p.status === 'captured') {
            if (CFG.can_void) { btns.push('<button class="button button-small" data-pact="void" data-id="' + p.id + '">ابطال</button>'); }
            if (CFG.can_refund) { btns.push('<button class="button button-small" data-pact="refund" data-id="' + p.id + '">بازپرداخت</button>'); }
        }
        return btns.join(' ') || '—';
    }

    function bindInvoiceActions(inv) {
        var body = document.getElementById('cpms-fin-inv-body');
        var payBtn = document.getElementById('cpms-fin-pay-submit');
        if (payBtn) {
            payBtn.addEventListener('click', function () {
                var amount = parseInt(document.getElementById('cpms-fin-pay-amount').value, 10);
                if (isNaN(amount) || amount <= 0) { notice('مبلغ نامعتبر است', 'warning'); return; }
                var headers = { 'Idempotency-Key': (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : uidFallback() };
                api('POST', 'invoices/' + inv.id + '/payments', {
                    amount: amount,
                    method: document.getElementById('cpms-fin-pay-method').value,
                    transaction_ref: document.getElementById('cpms-fin-pay-ref').value || null
                }, headers).then(function (r) {
                    if (r.__status !== 200 && r.__status !== 201) { notice(errMessage(r), 'error'); return; }
                    var replay = r.__status === 200;
                    notice(replay ? 'این پرداخت قبلاً با همین کلید ثبت شده بود (Replay)' : 'پرداخت ' + r.body.data.payment_number + ' ثبت شد', replay ? 'warning' : 'success');
                    refreshAll();
                    openInvoice(inv.id);
                }).catch(function () { notice('خطای شبکه', 'error'); });
            });
        }
        var adjBtn = document.getElementById('cpms-fin-adj-submit');
        if (adjBtn) {
            adjBtn.addEventListener('click', function () {
                var amount = parseInt(document.getElementById('cpms-fin-adj-amount').value, 10);
                var reason = document.getElementById('cpms-fin-adj-reason').value.trim();
                if (isNaN(amount) || amount <= 0 || !reason) { notice('مبلغ و دلیل اصلاح الزامی است', 'warning'); return; }
                api('POST', 'invoices/' + inv.id + '/adjustments', {
                    type: document.getElementById('cpms-fin-adj-type').value,
                    amount: amount,
                    reason: reason
                }).then(function (r) {
                    if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
                    notice('اصلاح ثبت شد', 'success');
                    refreshAll();
                    openInvoice(inv.id);
                }).catch(function () { notice('خطای شبکه', 'error'); });
            });
        }
        body.querySelectorAll('button[data-pact]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-id'), 10);
                if (btn.getAttribute('data-pact') === 'void') {
                    var vr = window.prompt('دلیل ابطال (الزامی — فقط همان روز ثبت امکان‌پذیر است):');
                    if (!vr) { return; }
                    api('POST', 'payments/' + id + '/void', { reason: vr }).then(function (r) {
                        if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
                        notice('پرداخت ابطال شد', 'success');
                        refreshAll();
                        openInvoice(inv.id);
                    });
                } else {
                    var rr = window.prompt('دلیل بازپرداخت (الزامی):');
                    if (!rr) { return; }
                    var amt = window.prompt('مبلغ بازپرداخت به ریال (خالی = کل مبلغ باقیمانده قابل بازگردانی):', '');
                    var body2 = { reason: rr };
                    if (amt && !isNaN(parseInt(amt, 10))) { body2.amount = parseInt(amt, 10); }
                    api('POST', 'payments/' + id + '/refund', body2).then(function (r) {
                        if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
                        notice('بازپرداخت ثبت شد', 'success');
                        refreshAll();
                        openInvoice(inv.id);
                    });
                }
            });
        });
        document.getElementById('cpms-fin-receipt-btn').addEventListener('click', function () {
            api('GET', 'invoices/' + inv.id + '/receipt').then(function (r) {
                if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
                printReceipt(r.body.data.receipt);
            });
        });
    }

    function uidFallback() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    // ---------- رسید (D17 — M-5 Deterministic) ----------

    function printReceipt(rc) {
        var w = window.open('', '_blank', 'width=420,height=640');
        if (!w) { notice('پنجره چاپ توسط مرورگر مسدود شد', 'error'); return; }
        var rows = (rc.items || []).map(function (i) {
            return '<tr><td>' + esc(i.description) + '</td><td>' + fmt(i.quantity) + '</td><td>' + fmt(i.unit_price) + '</td><td>' + fmt(i.amount) + '</td></tr>';
        }).join('');
        var pays = (rc.payments || []).map(function (p) {
            return '<tr><td>' + esc(p.payment_number) + '</td><td>' + (METHOD_LABELS[p.method] || esc(p.method)) + '</td><td>' + fmt(p.amount) + '</td><td>' + esc(p.jalali_paid_at) + '</td></tr>';
        }).join('');
        var t = rc.totals || {};
        w.document.write('<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8">' +
            '<title>رسید ' + esc(rc.invoice_number) + '</title>' +
            '<style>body{font-family:Tahoma,Arial,sans-serif;padding:16px;font-size:12px}' +
            'h1{font-size:15px;text-align:center;margin:4px 0}table{width:100%;border-collapse:collapse;margin:8px 0}' +
            'td,th{border-bottom:1px solid #ddd;padding:4px;text-align:right}.tot td{font-weight:bold}.muted{color:#777;text-align:center}</style>' +
            '</head><body>' +
            '<h1>' + esc((rc.clinic || {}).name || 'رسید مطب') + '</h1>' +
            '<p class="muted">' + esc(((rc.clinic || {}).address || '') + ' ' + ((rc.clinic || {}).phone || '')) + '</p>' +
            '<table><tr><th>رسید فاکتور</th><td dir="ltr">' + esc(rc.invoice_number) + '</td></tr>' +
            '<tr><th>تاریخ</th><td>' + esc(rc.jalali_date) + '</td></tr>' +
            '<tr><th>بیمار</th><td>' + esc((rc.patient || {}).name || '') + ' (' + esc((rc.patient || {}).mrn || '') + ')</td></tr></table>' +
            '<table><thead><tr><th>شرح</th><th>تعداد</th><th>قیمت</th><th>مبلغ</th></tr></thead><tbody>' + rows + '</tbody></table>' +
            '<table>' +
            '<tr><th>جمع اقلام</th><td>' + fmt(t.subtotal) + '</td></tr>' +
            '<tr><th>تخفیف</th><td>' + fmt(t.discount) + '</td></tr>' +
            '<tr><th>مالیات</th><td>' + fmt(t.tax) + '</td></tr>' +
            '<tr class="tot"><th>جمع کل</th><td>' + fmt(t.total) + ' ' + esc(t.currency || '') + '</td></tr>' +
            '<tr><th>پرداخت‌شده</th><td>' + fmt(t.paid_amount) + '</td></tr>' +
            '<tr class="tot"><th>باقیمانده</th><td>' + fmt(t.balance) + '</td></tr></table>' +
            (pays ? '<table><thead><tr><th>پرداخت</th><th>روش</th><th>مبلغ</th><th>تاریخ</th></tr></thead><tbody>' + pays + '</tbody></table>' : '') +
            '<p class="muted">این رسید به‌صورت سیستمی تولید شده است.</p>' +
            '<script>window.onload=function(){window.print();}<\/script>' +
            '</body></html>');
        w.document.close();
    }

    // ---------- Drawerها ----------

    function closeDrawers() {
        document.getElementById('cpms-fin-issue').style.display = 'none';
        document.getElementById('cpms-fin-inv').style.display = 'none';
        document.getElementById('cpms-fin-backdrop').style.display = 'none';
    }

    document.getElementById('cpms-fin-issue-close').addEventListener('click', closeDrawers);
    document.getElementById('cpms-fin-inv-close').addEventListener('click', closeDrawers);
    document.getElementById('cpms-fin-backdrop').addEventListener('click', closeDrawers);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeDrawers(); }
    });

    // ---------- شروع ----------

    function refreshAll() {
        loadSummary().catch(function (e) { notice(String(e.message || e), 'error'); });
        loadAwaiting().catch(function () {});
    }

    document.getElementById('cpms-fin-from').value = CFG.today;
    document.getElementById('cpms-fin-to').value = CFG.today;
    loadServices().catch(function (e) { notice(String(e.message || e), 'error'); });
    refreshAll();

    // ویزیت ارجاع‌شده از صف (?visit=ID)
    if (CFG.focus_visit > 0) {
        api('GET', 'secretary/today').then(function (r) {
            if (r.__status !== 200) { return; }
            var v = (r.body.data.queue || []).filter(function (x) { return x.id === CFG.focus_visit; })[0];
            if (!v) { return; }
            if (v.status === 'consultation_completed') { openIssue(v.id); }
            else if (v.status === 'awaiting_payment') { openInvoiceByVisit(v.id); }
        });
    }
})();
</script>
        <?php
    }
}

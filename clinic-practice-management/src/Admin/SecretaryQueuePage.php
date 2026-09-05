<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * داشبورد صف منشی (F4 — roadmap: امروز/Drawer/Walk-in/Keyboard).
 *
 * - فقط برای کاربر با cpms_queue_read (اکشن‌ها با cap خودشان در REST چک می‌شوند)
 * - Real-time: Polling کنترل‌شده R1 (ADR-0007) — منشی 3s، ETag/304،
 *   توقف خودکار وقتی Tab مخفی است (Page Visibility)
 * - Assets فقط روی همین صفحه (Baseline §2) — JS inline بدون وابستگی
 * - هیچ داده PHI در HTML اولیه رندر نمی‌شود؛ همه از REST (Authorization لایه‌بندی)
 */
final class SecretaryQueuePage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        add_menu_page(
            'صف امروز',
            'صف امروز',
            RolesAndCapabilities::QUEUE_READ,
            'cpms-queue',
            [self::class, 'render'],
            'dashicons-list-view',
            26
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function activeClinicians(): array
    {
        $rows = App::db()->fetchAll(
            'SELECT id, full_name FROM ' . App::db()->table('cpms_clinicians') .
            ' WHERE clinic_id = 1 AND is_active = 1 ORDER BY full_name ASC LIMIT 100'
        );

        $list = [];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $list[] = ['id' => (int) $r['id'], 'name' => (string) $r['full_name']];
        }

        return $list;
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::QUEUE_READ)) {
            wp_die('دسترسی ندارید', 403);
        }

        $config = [
            'rest_url' => esc_url_raw(rest_url('clinic/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'poll_ms' => 3000, // منشی 3s (ADR-0007)
            'can_checkin' => current_user_can(RolesAndCapabilities::QUEUE_CHECKIN),
            'can_advance' => current_user_can(RolesAndCapabilities::QUEUE_ADVANCE),
            'can_checkout' => current_user_can(RolesAndCapabilities::QUEUE_CHECKOUT),
            // F6: دکمه «آماده‌سازی صورتحساب» به داشبورد مالی می‌رود (صدور فاکتور واقعی)
            'finance_url' => current_user_can(RolesAndCapabilities::FINANCE_READ)
                ? admin_url('admin.php?page=cpms-finance') : null,
        ];
        $clinicians = self::activeClinicians();
        ?>
<div class="wrap" dir="rtl" id="cpms-queue-wrap">
    <h1 class="cpms-q-title">
        صف امروز
        <span class="cpms-q-live" id="cpms-q-live" title="به‌روزرسانی خودکار هر ۳ ثانیه">● زنده</span>
    </h1>

    <div id="cpms-q-notice" class="notice" style="display:none"></div>

    <div class="cpms-q-stats" id="cpms-q-stats"></div>

    <div class="cpms-q-toolbar">
        <button type="button" class="button button-primary" id="cpms-q-walkin-btn" title="میانبر: W">
            ＋ مراجعه حضوری (Walk-in)
        </button>
        <button type="button" class="button" id="cpms-q-checkin-btn" title="میانبر: C">
            ✓ چک‌این با نوبت
        </button>
        <button type="button" class="button" id="cpms-q-refresh-btn" title="میانبر: R">↻ تازه‌سازی</button>
        <span class="description" id="cpms-q-clock" dir="ltr"></span>
    </div>

    <div id="cpms-q-walkin-panel" class="card cpms-q-panel" style="display:none">
        <h2>مراجعه حضوری — ثبت فوری</h2>
        <p class="description">بیمار در کلینیک حاضر است و بدون نوبت آمده است.</p>
        <div class="cpms-q-form">
            <input type="text" id="cpms-q-walkin-search" class="regular-text" placeholder="جستجوی بیمار: نام، موبایل، کدملی یا MRN…  (میانبر: /)">
            <div id="cpms-q-walkin-results" class="cpms-q-results"></div>
            <select id="cpms-q-walkin-clinician">
                <option value="">— انتخاب پزشک —</option>
                <?php foreach ($clinicians as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button button-primary" id="cpms-q-walkin-submit" disabled>ثبت در صف</button>
        </div>
    </div>

    <div id="cpms-q-checkin-panel" class="card cpms-q-panel" style="display:none">
        <h2>چک‌این با نوبت</h2>
        <p class="description">نوبت‌های تأییدشده امروز بیمار را بگیرید و چک‌این کنید.</p>
        <div class="cpms-q-form">
            <input type="text" id="cpms-q-checkin-search" class="regular-text" placeholder="جستجوی بیمار…">
            <div id="cpms-q-checkin-results" class="cpms-q-results"></div>
            <div id="cpms-q-checkin-appts"></div>
        </div>
    </div>

    <h2>صف زنده</h2>
    <table class="widefat striped" id="cpms-q-table">
        <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>بیمار</th>
                <th>پزشک</th>
                <th>نوع</th>
                <th>وضعیت</th>
                <th>ساعت ورود</th>
                <th>انتظار</th>
                <th style="width:220px">اقدام</th>
            </tr>
        </thead>
        <tbody id="cpms-q-tbody">
            <tr><td colspan="8">در حال بارگذاری…</td></tr>
        </tbody>
    </table>
</div>

<!-- Drawer جزئیات ویزیت -->
<div id="cpms-q-drawer" class="cpms-q-drawer" dir="rtl" style="display:none">
    <div class="cpms-q-drawer-head">
        <strong id="cpms-q-drawer-title">جزئیات مراجعه</strong>
        <button type="button" class="button" id="cpms-q-drawer-close" title="Esc">✕</button>
    </div>
    <div id="cpms-q-drawer-body"></div>
</div>
<div id="cpms-q-drawer-backdrop" style="display:none"></div>

<style>
.cpms-q-live { font-size: 12px; color: #00a32a; margin-inline-start: 8px; }
.cpms-q-live.paused { color: #99a; }
.cpms-q-stats { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.cpms-q-stat { background: #fff; border: 1px solid #dcdcde; border-inline-start: 4px solid #2271b1;
    border-radius: 4px; padding: 8px 14px; min-width: 110px; }
.cpms-q-stat b { display: block; font-size: 20px; }
.cpms-q-stat span { color: #646970; font-size: 12px; }
.cpms-q-toolbar { display: flex; gap: 8px; align-items: center; margin: 10px 0 16px; }
.cpms-q-panel { max-width: 720px; margin-bottom: 16px; }
.cpms-q-form { display: flex; flex-direction: column; gap: 8px; }
.cpms-q-results { max-height: 180px; overflow-y: auto; }
.cpms-q-result { padding: 6px 8px; border: 1px solid #dcdcde; border-radius: 3px; margin: 4px 0;
    cursor: pointer; background: #fff; }
.cpms-q-result:hover, .cpms-q-result.selected { background: #f0f6fc; border-color: #2271b1; }
.cpms-q-badge { display: inline-block; font-size: 11px; border-radius: 8px; padding: 1px 8px; margin-inline-start: 4px; }
.cpms-q-badge.express { background: #fcf0f1; color: #b32d2e; border: 1px solid #b32d2e; }
.cpms-q-badge.walkin { background: #f0f6fc; color: #2271b1; border: 1px solid #2271b1; }
.cpms-q-drawer { position: fixed; top: 0; bottom: 0; inset-inline-start: 0; width: 400px; max-width: 92vw;
    background: #fff; box-shadow: 0 0 20px rgba(0,0,0,.25); z-index: 100002; overflow-y: auto; }
.cpms-q-drawer-head { display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px; border-bottom: 1px solid #dcdcde; position: sticky; top: 0; background: #fff; }
.cpms-q-drawer-body { padding: 12px 16px; }
#cpms-q-drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.3); z-index: 100001; }
.cpms-q-hist { list-style: none; margin: 8px 0; padding: 0; }
.cpms-q-hist li { padding: 6px 8px; border-inline-start: 3px solid #dcdcde; margin: 4px 0; font-size: 12px; }
.cpms-q-hist li b { font-size: 12px; }
</style>

<script>
window.CPMS_Q = <?php echo wp_json_encode($config); ?>;
</script>
<script>
(function () {
    'use strict';
    if (!window.CPMS_Q) { return; }
    var CFG = window.CPMS_Q;
    var state = {
        today: null,          // پاسخ /secretary/today
        events: {},           // visitId -> [{to_status, changed_at, actor_role, note}]
        since: 0,
        etag: null,
        timer: null,
        selectedPatient: null,
        pollPaused: false
    };

    var STATUS_LABELS = {
        checked_in: 'ثبت‌شده', waiting: 'در صف', called: 'فراخوانده‌شده',
        in_consultation: 'در ویزیت', consultation_completed: 'پایان ویزیت',
        awaiting_payment: 'در انتظار پرداخت', paid: 'پرداخت‌شده',
        checked_out: 'خارج‌شده', cancelled: 'لغوشده', skipped: 'ردشده'
    };
    var EXPRESS = 'نوبت فوری';

    function api(method, path, body) {
        var opts = { method: method, headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' } };
        if (state.etag !== null && method === 'GET' && path.indexOf('/rt/queue') === 0) {
            opts.headers['If-None-Match'] = state.etag;
        }
        if (body) { opts.body = JSON.stringify(body); }
        return fetch(CFG.rest_url + path, opts).then(function (r) {
            if (r.status === 304) { return { __notModified: true, __status: 304 }; }
            var et = r.headers.get('ETag');
            if (et) { state.etag = et; }
            return r.json().then(function (j) { return { __status: r.status, body: j }; });
        });
    }

    function notice(msg, kind) {
        var el = document.getElementById('cpms-q-notice');
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

    // ---------- بارگذاری ----------

    function loadToday() {
        return api('GET', 'secretary/today').then(function (r) {
            if (r.__status !== 200) { throw new Error(errMessage(r)); }
            state.today = r.body.data;
            renderStats();
            renderQueue();
        });
    }

    function poll() {
        if (state.pollPaused || document.hidden) { return; }
        api('GET', 'rt/queue?since=' + state.since).then(function (r) {
            if (r.__notModified) { return; }
            if (r.__status !== 200) { return; }
            var d = r.body.data;
            (d.events || []).forEach(function (ev) {
                if (!state.events[ev.visit_id]) { state.events[ev.visit_id] = []; }
                state.events[ev.visit_id].push(ev);
                if (ev.event_id > state.since) { state.since = ev.event_id; }
            });
            // تغییر جدید → رفرش کامل داشبورد (R1 سبک است؛ Today فقط در تغییر)
            loadToday().catch(function () {});
        });
    }

    // ---------- رندر ----------

    function renderStats() {
        var s = state.today.stats || {};
        var cards = [
            ['waiting', 'در صف', '#2271b1'], ['called', 'فراخوانده', '#8c8'],
            ['in_consultation', 'در ویزیت', '#68de7c'],
            ['checked_out', 'خارج‌شده', '#aaa'], ['cancelled', 'لغو', '#b32d2e'],
            ['skipped', 'ردشده', '#e07b39'], ['total', 'کل امروز', '#333']
        ];
        var html = cards.map(function (c) {
            return '<div class="cpms-q-stat" style="border-inline-start-color:' + c[2] + '">' +
                '<b>' + (s[c[0]] || 0) + '</b><span>' + c[1] + '</span></div>';
        }).join('');
        document.getElementById('cpms-q-stats').innerHTML = html;
        document.getElementById('cpms-q-clock').textContent =
            new Date(state.today.date + 'T00:00:00Z').toLocaleDateString('fa-IR');
    }

    function fmtTime(dt) {
        if (!dt) { return '—'; }
        var d = new Date(String(dt).replace(' ', 'T') + (String(dt).indexOf('Z') < 0 && String(dt).indexOf('+') < 0 ? 'Z' : ''));
        return isNaN(d) ? dt : d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    }

    function waitMin(v) {
        if (!v) { return '—'; }
        var d = new Date(String(v).replace(' ', 'T') + 'Z');
        if (isNaN(d)) { return '—'; }
        var m = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
        return m + ' دقیقه';
    }

    function actionsFor(v) {
        var btns = [];
        var id = v.id;
        if (CFG.can_advance && v.status === 'checked_in') {
            btns.push('<button class="button button-small" data-act="enqueue" data-id="' + id + '">به صف</button>');
        }
        if (CFG.can_advance && ['checked_in', 'waiting', 'called'].indexOf(v.status) >= 0) {
            btns.push('<button class="button button-small" data-act="cancel" data-id="' + id + '">لغو</button>');
        }
        if (CFG.can_advance && v.status === 'consultation_completed') {
            btns.push('<button class="button button-small" data-act="invoice" data-id="' + id + '">آماده‌سازی صورتحساب</button>');
        }
        if (CFG.can_checkout && v.status === 'awaiting_payment') {
            btns.push('<button class="button button-small" data-act="waive" data-id="' + id + '">خروج با معافیت</button>');
        }
        if (CFG.can_checkout && v.status === 'paid') {
            btns.push('<button class="button button-small button-primary" data-act="checkout" data-id="' + id + '">خروج</button>');
        }
        btns.push('<button class="button button-small" data-act="open" data-id="' + id + '">جزئیات</button>');
        return btns.join(' ');
    }

    function renderQueue() {
        var tbody = document.getElementById('cpms-q-tbody');
        var q = state.today.queue || [];
        if (q.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8">صف خالی است.</td></tr>';
            return;
        }
        tbody.innerHTML = q.map(function (v, i) {
            var kind = v.source === 'scheduled' ? 'نوبت‌دار' : 'حضوری';
            var badge = v.source === 'walk_in' ? ' <span class="cpms-q-badge walkin">حضوری</span>' : '';
            if (v.express) { badge += ' <span class="cpms-q-badge express">' + EXPRESS + '</span>'; }
            return '<tr data-id="' + v.id + '">' +
                '<td>' + (i + 1) + '</td>' +
                '<td><strong>' + esc(v.patient_name) + '</strong></td>' +
                '<td>' + esc(v.clinician_name || '—') + '</td>' +
                '<td>' + kind + badge + '</td>' +
                '<td>' + (STATUS_LABELS[v.status] || v.status) + '</td>' +
                '<td dir="ltr">' + fmtTime(v.check_in_at) + '</td>' +
                '<td>' + (v.status === 'waiting' ? waitMin(v.waiting_since) : '—') + '</td>' +
                '<td>' + actionsFor(v) + '</td>' +
                '</tr>';
        }).join('');
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------- Drawer ----------

    function openDrawer(visitId) {
        var v = (state.today.queue || []).filter(function (x) { return x.id === visitId; })[0];
        var hist = state.events[visitId] || [];
        var body = document.getElementById('cpms-q-drawer-body');
        if (!v) {
            body.innerHTML = '<p>این مراجعه دیگر در صف زنده نیست.</p>';
        } else {
            var rows = [
                ['بیمار', esc(v.patient_name)], ['پزشک', esc(v.clinician_name || '—')],
                ['وضعیت', STATUS_LABELS[v.status] || v.status],
                ['نوع', v.source === 'scheduled' ? 'نوبت‌دار' : 'حضوری'],
                ['نوبت مرجع', v.appointment_id ? '#' + v.appointment_id : '—'],
                ['ساعت ورود', fmtTime(v.check_in_at)],
                ['فراخوان مجدد', String(v.recall_count)]
            ];
            var html = '<table class="widefat striped"><tbody>' +
                rows.map(function (r) { return '<tr><td style="width:110px">' + r[0] + '</td><td>' + r[1] + '</td></tr>'; }).join('') +
                '</tbody></table><h3>گردش کار</h3><ul class="cpms-q-hist">';
            if (hist.length === 0) {
                html += '<li>رویدادی ثبت نشده (قبل از باز شدن صفحه).</li>';
            } else {
                hist.forEach(function (h) {
                    html += '<li><b>' + fmtTime(h.changed_at) + '</b> — ' +
                        (h.from_status ? (STATUS_LABELS[h.from_status] || h.from_status) + ' ← ' : '') +
                        (STATUS_LABELS[h.to_status] || h.to_status) +
                        (h.note ? ' <i>(' + esc(h.note) + ')</i>' : '') + '</li>';
                });
            }
            html += '</ul><div style="margin-top:10px">' + actionsFor(v) + '</div>';
            body.innerHTML = html;
        }
        document.getElementById('cpms-q-drawer').style.display = 'block';
        document.getElementById('cpms-q-drawer-backdrop').style.display = 'block';
        var title = v ? (v.patient_name + ' — ' + (STATUS_LABELS[v.status] || v.status)) : 'جزئیات';
        document.getElementById('cpms-q-drawer-title').textContent = title;
    }

    function closeDrawer() {
        document.getElementById('cpms-q-drawer').style.display = 'none';
        document.getElementById('cpms-q-drawer-backdrop').style.display = 'none';
    }

    // ---------- اکشن‌ها ----------

    function doPost(path, body, okMsg) {
        api('POST', path, body).then(function (r) {
            if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
            notice(okMsg, 'success');
            loadToday().catch(function () {});
        }).catch(function () { notice('خطای شبکه', 'error'); });
    }

    document.getElementById('cpms-q-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) { return; }
        var id = btn.getAttribute('data-id');
        var act = btn.getAttribute('data-act');
        if (act === 'open') { openDrawer(parseInt(id, 10)); return; }
        if (act === 'cancel') {
            var reason = prompt('دلیل لغو (الزامی):');
            if (!reason) { return; }
            doPost('visits/' + id + '/status', { to_status: 'cancelled', note: reason }, 'مراجعه لغو شد');
            return;
        }
        if (act === 'enqueue') {
            doPost('visits/' + id + '/status', { to_status: 'waiting' }, 'به صف اضافه شد');
            return;
        }
        if (act === 'invoice') {
            // F6: صدور فاکتور واقعی در داشبورد مالی (D12) — V11 آنجا به‌صورت سیستمی اعمال می‌شود
            if (CFG.finance_url) { window.location.href = CFG.finance_url + '&visit=' + id; return; }
            doPost('visits/' + id + '/status', { to_status: 'awaiting_payment' }, 'در انتظار پرداخت');
            return;
        }
        if (act === 'waive') {
            var wr = prompt('دلیل معافیت از پرداخت (الزامی):');
            if (!wr) { return; }
            doPost('visits/' + id + '/checkout', { waive_invoice: { reason: wr } }, 'خروج ثبت شد');
            return;
        }
        if (act === 'checkout') {
            doPost('visits/' + id + '/checkout', {}, 'خروج ثبت شد');
        }
    });

    document.getElementById('cpms-q-drawer-body').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (btn && btn.getAttribute('data-act') === 'open') {
            openDrawer(parseInt(btn.getAttribute('data-id'), 10));
        }
    });
    document.getElementById('cpms-q-drawer-close').addEventListener('click', closeDrawer);
    document.getElementById('cpms-q-drawer-backdrop').addEventListener('click', closeDrawer);

    // ---------- جستجوی بیمار (D2) ----------

    function bindPatientSearch(inputId, resultsId, onPick) {
        var input = document.getElementById(inputId);
        var box = document.getElementById(resultsId);
        var t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            var q = input.value.trim();
            if (q.length < 2) { box.innerHTML = ''; return; }
            t = setTimeout(function () {
                api('GET', 'patients/search?q=' + encodeURIComponent(q)).then(function (r) {
                    if (r.__status !== 200) { box.innerHTML = '<p class="description">' + errMessage(r) + '</p>'; return; }
                    var items = r.body.data || [];
                    if (!items.length) { box.innerHTML = '<p class="description">بیماری یافت نشد.</p>'; return; }
                    box.innerHTML = items.map(function (p) {
                        return '<div class="cpms-q-result" data-pid="' + p.id + '">' +
                            esc(p.first_name + ' ' + p.last_name) +
                            ' <span class="description" dir="ltr">' + esc(p.mobile || '') + '</span>' +
                            ' <span class="description">' + esc(p.mrn || '') + '</span></div>';
                    }).join('');
                    box.querySelectorAll('.cpms-q-result').forEach(function (el) {
                        el.addEventListener('click', function () {
                            box.querySelectorAll('.cpms-q-result').forEach(function (x) { x.classList.remove('selected'); });
                            el.classList.add('selected');
                            var picked = items.filter(function (p) { return String(p.id) === el.getAttribute('data-pid'); })[0];
                            onPick(picked);
                        });
                    });
                });
            }, 250);
        });
    }

    // ---------- Walk-in (D7) ----------

    var walkinPatient = null;
    bindPatientSearch('cpms-q-walkin-search', 'cpms-q-walkin-results', function (p) {
        walkinPatient = p;
        document.getElementById('cpms-q-walkin-submit').disabled = false;
    });
    document.getElementById('cpms-q-walkin-submit').addEventListener('click', function () {
        var clinicianId = parseInt(document.getElementById('cpms-q-walkin-clinician').value, 10);
        if (!walkinPatient || !clinicianId) { notice('بیمار و پزشک را انتخاب کنید', 'warning'); return; }
        doPost('visits/walk-in', { patient_id: walkinPatient.id, clinician_id: clinicianId }, 'به صف اضافه شد');
        walkinPatient = null;
        document.getElementById('cpms-q-walkin-submit').disabled = true;
        document.getElementById('cpms-q-walkin-search').value = '';
        document.getElementById('cpms-q-walkin-results').innerHTML = '';
        togglePanel('cpms-q-walkin-panel', false);
    });

    // ---------- Check-in با نوبت (D6 + D9) ----------

    var checkinPatient = null;
    bindPatientSearch('cpms-q-checkin-search', 'cpms-q-checkin-results', function (p) {
        checkinPatient = p;
        loadCheckinAppointments(p);
    });

    function loadCheckinAppointments(p) {
        var box = document.getElementById('cpms-q-checkin-appts');
        box.innerHTML = '<p class="description">در حال دریافت نوبت‌های امروز…</p>';
        // نوبت‌های امروز همه پزشکان: پرس‌وجو برای هر پزشک فعال — ساده: هر پزشک موجود
        var clinicians = <?php echo wp_json_encode($clinicians); ?>;
        var pending = clinicians.length;
        var found = [];
        clinicians.forEach(function (c) {
            api('GET', 'appointments?clinician_id=' + c.id + '&status=confirmed').then(function (r) {
                if (r.__status === 200) {
                    (r.body.data || []).forEach(function (a) {
                        if (a.patient_id === p.id) { found.push(a); }
                    });
                }
                if (--pending === 0) { renderCheckinAppointments(found, p); }
            }).catch(function () { if (--pending === 0) { renderCheckinAppointments(found, p); } });
        });
    }

    function renderCheckinAppointments(appts, p) {
        var box = document.getElementById('cpms-q-checkin-appts');
        if (!appts.length) {
            box.innerHTML = '<p class="description">نوبت تأییدشده‌ای برای امروز این بیمار پیدا نشد — از Walk-in استفاده کنید.</p>';
            return;
        }
        box.innerHTML = appts.map(function (a) {
            return '<div class="cpms-q-result">' +
                esc(p.first_name + ' ' + p.last_name) + ' — نوبت #' + a.id +
                ' <span class="description" dir="ltr">' + esc(a.slot_time || '') + '</span>' +
                ' <button class="button button-small button-primary" data-appt="' + a.id + '">چک‌این</button></div>';
        }).join('');
        box.querySelectorAll('button[data-appt]').forEach(function (b) {
            b.addEventListener('click', function () {
                doPost('visits/checkin', { patient_id: p.id, appointment_id: parseInt(b.getAttribute('data-appt'), 10) }, 'چک‌این انجام شد');
                box.innerHTML = '';
                document.getElementById('cpms-q-checkin-search').value = '';
                document.getElementById('cpms-q-checkin-results').innerHTML = '';
                togglePanel('cpms-q-checkin-panel', false);
            });
        });
    }

    // ---------- پنل‌ها / میانبرها ----------

    function togglePanel(id, force) {
        var el = document.getElementById(id);
        var show = force === undefined ? el.style.display === 'none' : force;
        el.style.display = show ? 'block' : 'none';
        if (show) {
            var inp = el.querySelector('input[type="text"]');
            if (inp) { inp.focus(); }
        }
    }

    document.getElementById('cpms-q-walkin-btn').addEventListener('click', function () {
        togglePanel('cpms-q-walkin-panel'); togglePanel('cpms-q-checkin-panel', false);
    });
    document.getElementById('cpms-q-checkin-btn').addEventListener('click', function () {
        togglePanel('cpms-q-checkin-panel'); togglePanel('cpms-q-walkin-panel', false);
    });
    document.getElementById('cpms-q-refresh-btn').addEventListener('click', function () {
        state.since = 0;
        loadToday().catch(function (e) { notice(String(e.message || e), 'error'); });
    });

    document.addEventListener('keydown', function (e) {
        var tag = (document.activeElement && document.activeElement.tagName) || '';
        var typing = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA';
        if (e.key === 'Escape') { closeDrawer(); return; }
        if (typing) { return; }
        if (e.key === 'w' || e.key === 'W') { togglePanel('cpms-q-walkin-panel'); togglePanel('cpms-q-checkin-panel', false); }
        if (e.key === 'c' || e.key === 'C') { togglePanel('cpms-q-checkin-panel'); togglePanel('cpms-q-walkin-panel', false); }
        if (e.key === 'r' || e.key === 'R') { document.getElementById('cpms-q-refresh-btn').click(); }
        if (e.key === '/') {
            e.preventDefault();
            togglePanel('cpms-q-walkin-panel', true);
            document.getElementById('cpms-q-walkin-search').focus();
        }
    });

    // ---------- Page Visibility (ADR-0007) ----------

    document.addEventListener('visibilitychange', function () {
        var live = document.getElementById('cpms-q-live');
        if (document.hidden) {
            live.classList.add('paused');
            live.textContent = '● متوقف (تب مخفی)';
        } else {
            live.classList.remove('paused');
            live.textContent = '● زنده';
            poll(); // بلافاصله به‌روز
        }
    });

    // ---------- شروع ----------

    loadToday().catch(function (e) { notice(String(e.message || e), 'error'); });
    state.timer = setInterval(poll, CFG.poll_ms);
})();
</script>
        <?php
    }
}

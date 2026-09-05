<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;

/**
 * داشبورد پزشک (F5) — صف خودِ پزشک + صفحه ویزیت (One-Page).
 *
 * - منو با cpms_queue_read (پزشک/منشی هر دو دارند؛ محتوای صفحه برای پزشک است)
 * - Queue View: صف امروزِ «کلینسین متصل به این کاربر» + Call/Recall/Start (E3–E5)
 *   + ورود به صفحه ویزیت — Polling کنترل‌شده 5s (ADR-0007) با توقف Tab مخفی
 * - Visit View: پرونده (E7) + ثبت یادداشت/Correction (E8/E9) + نسخه Draft/Finalize
 *   (E10/E11) + توصیه/پیگیری (E12/E13) + پایان/بازگشایی (E14/E15) + فایل (E16/E17)
 * - هیچ PHI در HTML اولیه؛ همه از REST (Authorization لایه‌بندی‌شده)
 * - ویرایشگر دست‌خط و تاریخ Jalali جزو فاز 6 هستند (ADR-0014) — این‌جا نیست
 */
final class DoctorDashboardPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        add_menu_page(
            'امروز پزشک',
            'امروز پزشک',
            RolesAndCapabilities::QUEUE_READ,
            'cpms-doctor',
            [self::class, 'render'],
            'dashicons-heart',
            25
        );
    }

    /**
     * کلینسین متصل به کاربر جاری — «ویزیت خودش» (ماتریس 4.3).
     *
     * @return array{id: int, name: string}|null
     */
    private static function ownClinician(): ?array
    {
        $row = App::db()->fetchRow(
            'SELECT id, full_name FROM ' . App::db()->table('cpms_clinicians') .
            ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [get_current_user_id()]
        );
        if ($row === null) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['full_name']];
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::QUEUE_READ)) {
            wp_die('دسترسی ندارید', 403);
        }

        $clinician = self::ownClinician();
        $visitId = isset($_GET['visit_id']) ? absint($_GET['visit_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($clinician === null) {
            echo '<div class="wrap" dir="rtl"><h1>امروز پزشک</h1>' .
                '<div class="notice notice-warning"><p>حساب شما به پروفایل پزشک فعالی متصل نیست. ' .
                'از مدیر بخواهید در تنظیمات کلینیک، کاربر شما را به پزشک مربوطه متصل کند.</p></div></div>';

            return;
        }

        $config = [
            'rest_url' => esc_url_raw(rest_url('clinic/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'poll_ms' => 5000, // پزشک 5s (ADR-0007)
            'clinician_id' => $clinician['id'],
            'clinician_name' => $clinician['name'],
            'visit_id' => $visitId,
            'base_url' => admin_url('admin.php?page=cpms-doctor'),
            'can_call' => current_user_can(RolesAndCapabilities::QUEUE_CALL),
            'can_start' => current_user_can(RolesAndCapabilities::CONSULT_START),
            'can_complete' => current_user_can(RolesAndCapabilities::CONSULT_COMPLETE),
            'can_upload' => current_user_can(RolesAndCapabilities::FILE_UPLOAD),
        ];
        ?>
<div class="wrap" dir="rtl" id="cpms-doc-wrap">
    <h1 class="cpms-doc-title">
        <?php echo $visitId > 0 ? 'صفحه ویزیت' : 'امروز پزشک'; ?>
        <span class="cpms-doc-live" id="cpms-doc-live">● زنده</span>
        <a class="cpms-doc-back" href="<?php echo esc_url(admin_url('admin.php?page=cpms-doctor')); ?>">← بازگشت به صف</a>
    </h1>

    <div id="cpms-doc-notice" class="notice" style="display:none"></div>

    <!-- ================= View A: صف پزشک ================= -->
    <div id="cpms-doc-queue-view">
        <p class="description" id="cpms-doc-header">در حال بارگذاری…</p>
        <div class="cpms-doc-stats" id="cpms-doc-stats"></div>

        <h2>صف من (زنده)</h2>
        <table class="widefat striped" id="cpms-doc-table">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>بیمار</th>
                    <th>نوع</th>
                    <th>وضعیت</th>
                    <th>انتظار</th>
                    <th style="width:300px">اقدام</th>
                </tr>
            </thead>
            <tbody id="cpms-doc-tbody">
                <tr><td colspan="6">در حال بارگذاری…</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ================= View B: صفحه ویزیت ================= -->
    <div id="cpms-doc-visit-view" style="display:none"></div>
</div>

<style>
.cpms-doc-title { display: flex; align-items: center; gap: 10px; }
.cpms-doc-live { font-size: 12px; color: #00a32a; }
.cpms-doc-live.paused { color: #99a; }
.cpms-doc-back { font-size: 13px; margin-inline-start: auto; }
.cpms-doc-stats { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
.cpms-doc-stat { background: #fff; border: 1px solid #dcdcde; border-inline-start: 4px solid #2271b1;
    border-radius: 4px; padding: 8px 14px; min-width: 110px; }
.cpms-doc-stat b { display: block; font-size: 20px; }
.cpms-doc-stat span { color: #646970; font-size: 12px; }
.cpms-doc-badge { display: inline-block; font-size: 11px; border-radius: 8px; padding: 1px 8px; }
.cpms-doc-badge.express { background: #fcf0f1; color: #b32d2e; border: 1px solid #b32d2e; }
.cpms-doc-badge.walkin { background: #f0f6fc; color: #2271b1; border: 1px solid #2271b1; }
.cpms-doc-badge.status-in_consultation { background: #edfaef; color: #00a32a; border: 1px solid #00a32a; }
.cpms-doc-badge.status-waiting { background: #fcf9e8; color: #996800; border: 1px solid #996800; }
.cpms-doc-badge.status-called { background: #f0f6fc; color: #2271b1; border: 1px solid #2271b1; }
.cpms-doc-btn { min-height: 40px; }
.cpms-doc-patient-head { display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; margin: 10px 0; }
.cpms-doc-patient-head h2 { margin: 0; }
.cpms-doc-alert { background: #fcf0f1; border: 1px solid #b32d2e; border-inline-start: 4px solid #b32d2e;
    border-radius: 4px; padding: 8px 12px; margin: 8px 0; font-weight: 600; }
.cpms-doc-alert.clear { background: #edfaef; border-color: #00a32a; }
.cpms-doc-section { background: #fff; border: 1px solid #dcdcde; border-radius: 4px;
    padding: 4px 14px 14px; margin: 14px 0; }
.cpms-doc-section > summary { cursor: pointer; font-size: 15px; font-weight: 600; padding: 10px 0; }
.cpms-doc-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; margin: 6px 0; }
.cpms-doc-note { border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 10px; margin: 6px 0; }
.cpms-doc-note .meta { color: #646970; font-size: 12px; }
.cpms-doc-note.private { border-inline-start: 4px solid #b32d2e; }
.cpms-doc-note.visible { border-inline-start: 4px solid #00a32a; }
.cpms-doc-form { display: flex; flex-direction: column; gap: 8px; max-width: 760px; }
.cpms-doc-form textarea { min-height: 64px; }
.cpms-doc-itemlist { list-style: none; margin: 0; padding: 0; }
.cpms-doc-itemlist li { border: 1px solid #dcdcde; border-radius: 3px; padding: 6px 10px; margin: 4px 0; }
.cpms-doc-muted { color: #646970; }
.cpms-doc-complete-bar { display: flex; gap: 10px; align-items: center; margin: 16px 0; }
button.cpms-doc-big { min-height: 56px; padding: 6px 24px; font-size: 15px; } /* الگوی UX تبلت — ≥56px */
</style>

<script>
window.CPMS_DOC = <?php echo wp_json_encode($config); ?>;
</script>
<script>
(function () {
    'use strict';
    if (!window.CPMS_DOC) { return; }
    var CFG = window.CPMS_DOC;
    var state = { queue: null, record: null, timer: null, pollPaused: false };

    var STATUS_LABELS = {
        checked_in: 'ثبت‌شده', waiting: 'در صف', called: 'فراخوانده‌شده',
        in_consultation: 'در ویزیت', consultation_completed: 'پایان ویزیت',
        awaiting_payment: 'در انتظار پرداخت', paid: 'پرداخت‌شده',
        checked_out: 'خارج‌شده', cancelled: 'لغوشده', skipped: 'ردشده'
    };
    var NOTE_CATS = {
        chief_complaint: 'شکایت اصلی', history: 'شرح حال', examination: 'معاینه',
        diagnosis: 'تشخیص', clinical_note: 'یادداشت بالینی',
        recommendation_text: 'توصیه', private_note: 'یادداشت خصوصی', other: 'سایر'
    };
    var REC_TYPES = { diet: 'رژیم', rest: 'استراحت', activity: 'فعالیت', care: 'مراقبت', lab: 'آزمایش', followup: 'پیگیری', other: 'سایر' };
    var FILE_CATS = { lab_result: 'نتیجه آزمایش', image: 'تصویر', scan: 'اسکن', document: 'مدرک', other: 'سایر' };

    function api(method, path, body, isForm) {
        var opts = { method: method, headers: { 'X-WP-Nonce': CFG.nonce } };
        if (!isForm) { opts.headers['Content-Type'] = 'application/json'; }
        if (body) { opts.body = isForm ? body : JSON.stringify(body); }
        return fetch(CFG.rest_url + path, opts).then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; }, function () {
                return { status: r.status, body: {} };
            });
        });
    }

    function notice(msg, kind) {
        var el = document.getElementById('cpms-doc-notice');
        el.className = 'notice notice-' + (kind || 'info');
        el.textContent = msg;
        el.style.display = 'block';
        clearTimeout(notice._t);
        notice._t = setTimeout(function () { el.style.display = 'none'; }, 6000);
        if (kind === 'error' || kind === 'warning') { window.scrollTo(0, 0); }
    }

    function errMessage(resp) {
        var d = (resp && resp.body && resp.body.data) || resp && resp.body || {};
        return d.message || d.code || 'خطا';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function waitingMinutes(v) {
        if (!v) { return '—'; }
        var mins = Math.max(0, Math.round((Date.now() - new Date(String(v).replace(' ', 'T') + 'Z').getTime()) / 60000));
        return mins >= 60 ? Math.floor(mins / 60) + 'س ' + (mins % 60) + 'م' : mins + ' دقیقه';
    }

    // ================= View A — صف پزشک =================

    function loadQueue() {
        return api('GET', 'queue?clinician_id=' + CFG.clinician_id).then(function (r) {
            if (r.status !== 200) { throw new Error(errMessage(r)); }
            state.queue = r.body.data;
            renderQueue();
        });
    }

    function renderQueue() {
        var d = state.queue;
        if (!d) { return; }
        document.getElementById('cpms-doc-header').textContent =
            'دکتر ' + CFG.clinician_name + ' — ' + d.date;

        var s = d.stats || {};
        var cards = [
            ['today', 'مراجعه امروز'], ['waiting', 'در انتظار'], ['called', 'فراخوانده‌شده'],
            ['in_consultation', 'در ویزیت'], ['checked_out', 'پایان‌یافته']
        ];
        document.getElementById('cpms-doc-stats').innerHTML = cards.map(function (c) {
            return '<div class="cpms-doc-stat"><b>' + esc(s[c[0]] != null ? s[c[0]] : '—') +
                '</b><span>' + c[1] + '</span></div>';
        }).join('');

        var mine = (d.queue || []).filter(function (v) {
            return v.clinician_id === CFG.clinician_id;
        });
        var tb = document.getElementById('cpms-doc-tbody');
        if (!mine.length) {
            tb.innerHTML = '<tr><td colspan="6">مراجعه‌ای برای شما در صف نیست.</td></tr>';
            return;
        }
        tb.innerHTML = mine.map(function (v, i) {
            var badge = (v.express ? ' <span class="cpms-doc-badge express">فوری</span>' : '') +
                (v.source === 'walk_in' ? ' <span class="cpms-doc-badge walkin">حضوری</span>' : '');
            var actions = '';
            if (CFG.can_call && (v.status === 'waiting' || v.status === 'called')) {
                actions += '<button class="button cpms-doc-btn" data-act="call" data-id="' + v.id + '">📣 فراخوانی</button> ';
                if (v.status === 'called') {
                    actions += '<button class="button cpms-doc-btn" data-act="recall" data-id="' + v.id + '">🔁 فراخوانی مجدد</button> ';
                }
            }
            if (CFG.can_start && v.status === 'called') {
                actions += '<button class="button button-primary cpms-doc-btn" data-act="start" data-id="' + v.id + '">✅ شروع ویزیت</button> ';
            }
            if (v.status === 'in_consultation') {
                actions += '<a class="button button-primary cpms-doc-btn" href="' + CFG.base_url + '&visit_id=' + v.id + '">📂 صفحه ویزیت</a> ';
            }
            if (v.status === 'consultation_completed' || v.status === 'awaiting_payment' || v.status === 'paid') {
                actions += '<a class="button cpms-doc-btn" href="' + CFG.base_url + '&visit_id=' + v.id + '">📖 پرونده</a> ';
            }
            return '<tr><td>' + (i + 1) + '</td><td><strong>' + esc(v.patient_name) + '</strong>' + badge +
                '</td><td>' + (v.source === 'walk_in' ? 'حضوری' : 'نوبت') + '</td>' +
                '<td><span class="cpms-doc-badge status-' + esc(v.status) + '">' + (STATUS_LABELS[v.status] || esc(v.status)) + '</span></td>' +
                '<td>' + waitingMinutes(v.waiting_since) + '</td><td>' + actions + '</td></tr>';
        }).join('');
    }

    function queueAction(act, id) {
        var path = 'visits/' + id + '/' + (act === 'recall' ? 'recall' : act === 'call' ? 'call' : 'start');
        api('POST', path, {}).then(function (r) {
            if (r.status !== 200) { notice(errMessage(r), 'error'); return; }
            notice(act === 'start' ? 'ویزیت آغاز شد.' : 'بیمار فراخوانده شد.', 'success');
            loadQueue();
        }).catch(function () { notice('خطای شبکه', 'error'); });
    }

    // ================= View B — صفحه ویزیت =================

    function loadRecord() {
        return api('GET', 'visits/' + CFG.visit_id + '/record').then(function (r) {
            if (r.status !== 200) { throw new Error(errMessage(r)); }
            state.record = r.body.data;
            renderVisit();
        });
    }

    function renderVisit() {
        var d = state.record;
        document.getElementById('cpms-doc-queue-view').style.display = 'none';
        var view = document.getElementById('cpms-doc-visit-view');
        view.style.display = 'block';

        var p = d.patient, v = d.visit;
        var alerts = '';
        (p.allergies && (p.allergies.medication || []).concat(p.allergies.other || [])).forEach(function (a) {
            alerts += '<div class="cpms-doc-alert">⚠️ آلرژی: ' + esc(a && a.name ? a.name : a) +
                (a && a.note ? ' — ' + esc(a.note) : '') + '</div>';
        });
        (p.chronic_conditions || []).forEach(function (c) {
            alerts += '<div class="cpms-doc-alert clear">🩺 زمینه: ' + esc(c) + '</div>';
        });

        var statusBadge = '<span class="cpms-doc-badge status-' + esc(v.status) + '">' + (STATUS_LABELS[v.status] || esc(v.status)) + '</span>';
        var headActions = '';
        if (CFG.can_call && v.status === 'called') {
            headActions += '<button class="button cpms-doc-btn" data-act="call" data-id="' + v.id + '">📣 فراخوانی مجدد</button>';
        }
        var completeBar = '';
        if (v.status === 'in_consultation' && CFG.can_complete) {
            completeBar = '<div class="cpms-doc-complete-bar">' +
                '<button class="button button-primary button-hero cpms-doc-big" data-act="complete" data-id="' + v.id + '">✅ پایان ویزیت</button>' +
                '<span class="description">پایان ویزیت نیازمند ثبت «شکایت اصلی» است (FR-8.7).</span></div>';
        } else if (v.status === 'consultation_completed') {
            completeBar = '<div class="cpms-doc-complete-bar">' +
                '<span class="cpms-doc-badge status-consultation_completed">ویزیت پایان یافته</span>' +
                '<button class="button cpms-doc-btn" data-act="reopen" data-id="' + v.id + '">↩️ بازگشایی برای اصلاح</button>' +
                '<span class="description">بازگشایی دلیل الزامی دارد و در Audit ثبت می‌شود (FR-8.8).</span></div>';
        }

        var notesHtml = (d.notes || []).map(function (n) {
            return '<div class="cpms-doc-note ' + (n.visibility === 'doctor_private' ? 'private' : 'visible') + '">' +
                '<div><b>' + esc(NOTE_CATS[n.category] || n.category) + '</b>' +
                (n.visibility === 'doctor_private' ? ' <span class="cpms-doc-badge express">🔒 خصوصی</span>' : '') +
                ' <span class="meta">نسخه ' + n.version + (n.versions > 1 ? ' (' + n.versions + ' نسخه)' : '') + ' — ' + esc(n.created_at) + '</span></div>' +
                '<div class="content">' + esc(n.content_text) + '</div>' +
                '<div class="cpms-doc-row"><button class="button button-small" data-act="edit-note" data-id="' + n.id + '">✎ اصلاح (نسخه جدید)</button></div>' +
                '<div class="edit-form" style="display:none"></div></div>';
        }).join('') || '<p class="cpms-doc-muted">یادداشتی ثبت نشده است.</p>';

        var rxHtml = (d.prescriptions || []).map(function (rx) {
            var items = (rx.items || []).map(function (i) {
                return '<li>' + esc(i.generic_name) + (i.strength ? ' ' + esc(i.strength) : '') +
                    ' | ' + esc(i.dose) + ' | ' + esc(i.frequency) +
                    (i.duration_days ? ' | ' + i.duration_days + ' روز' : '') + '</li>';
            }).join('');
            var fin = rx.status === 'draft'
                ? '<button class="button button-small" data-act="finalize-rx" data-id="' + rx.id + '">✅ نهایی‌سازی نسخه</button>'
                : '<span class="cpms-doc-badge status-checked_out">' + (rx.status === 'finalized' ? 'نهایی‌شده' : esc(rx.status)) + '</span>';
            return '<div class="cpms-doc-note"><div><b>نسخه ' + esc(rx.prescription_number) + '</b> ' + fin +
                ' <span class="meta">' + esc(rx.created_at) + '</span></div><ul class="cpms-doc-itemlist">' + items + '</ul></div>';
        }).join('') || '<p class="cpms-doc-muted">نسخه‌ای ثبت نشده است.</p>';

        var recHtml = (d.recommendations || []).map(function (rc) {
            return '<li>' + esc(REC_TYPES[rc.type] || rc.type) + ': ' + esc(rc.text) +
                (rc.is_patient_visible ? '' : ' <span class="cpms-doc-badge express">🔒 فقط پزشک</span>') + '</li>';
        }).join('') || '<p class="cpms-doc-muted">توصیه‌ای ثبت نشده است.</p>';

        var fuHtml = (d.follow_ups || []).map(function (f) {
            return '<li>' + (f.suggested_date ? 'تاریخ: ' + esc(f.suggested_date) : 'بازه: ' + esc(f.interval_days) + ' روز') +
                ' — ' + esc(f.reason || '') + ' <span class="cpms-doc-badge status-' + esc(f.status) + '">' + esc(f.status) + '</span></li>';
        }).join('') || '<p class="cpms-doc-muted">پیگیری ثبت نشده است.</p>';

        var filesHtml = (d.files || []).map(function (f) {
            return '<li>' + esc(f.original_filename) + ' (' + esc(FILE_CATS[f.category] || f.category) +
                (f.visibility === 'doctor_private' ? '، 🔒 خصوصی' : '') + '، ' + Math.round((f.file_size || 0) / 1024) + 'KB)' +
                ' <button class="button button-small" data-act="stream" data-id="' + f.id + '">👁 مشاهده</button></li>';
        }).join('') || '<p class="cpms-doc-muted">فایلی پیوست نشده است.</p>';

        var pastHtml = (d.past_visits || []).map(function (pv) {
            return '<li>' + esc(pv.visit_date) + ' — ' + (STATUS_LABELS[pv.status] || esc(pv.status)) +
                (pv.clinician_name ? ' (' + esc(pv.clinician_name) + ')' : '') + '</li>';
        }).join('') || '<p class="cpms-doc-muted">سابقه‌ای نیست.</p>';

        view.innerHTML =
        '<div class="cpms-doc-patient-head">' +
            '<h2>' + esc(p.full_name) + '</h2>' +
            '<span class="cpms-doc-muted">' + esc(p.mrn) + (p.age != null ? ' · ' + p.age + ' سال' : '') +
            ' · ' + (p.gender === 'male' ? '♂' : p.gender === 'female' ? '♀' : esc(p.gender)) +
            (p.blood_group ? ' · ' + esc(p.blood_group) : '') + '</span>' + statusBadge + headActions +
        '</div>' + alerts + completeBar +

        '<details class="cpms-doc-section" open><summary>📝 یادداشت‌های ویزیت</summary>' +
            '<div class="cpms-doc-form" style="margin-bottom:12px">' +
                '<div class="cpms-doc-row"><select id="cpms-doc-note-cat">' +
                    Object.keys(NOTE_CATS).map(function (k) { return '<option value="' + k + '">' + NOTE_CATS[k] + '</option>'; }).join('') +
                '</select>' +
                '<select id="cpms-doc-note-vis"><option value="patient_visible">قابل نمایش به بیمار</option>' +
                '<option value="doctor_private">🔒 خصوصی — فقط پزشک</option></select></div>' +
                '<textarea id="cpms-doc-note-text" placeholder="متن یادداشت…"></textarea>' +
                '<div><button class="button button-primary" data-act="add-note">＋ ثبت یادداشت</button></div>' +
            '</div>' + notesHtml +
        '</details>' +

        '<details class="cpms-doc-section" open><summary>💊 نسخه دارویی (Draft → Finalize)</summary>' +
            '<div class="cpms-doc-form" style="margin-bottom:12px">' +
                '<div class="cpms-doc-row">' +
                    '<input id="cpms-doc-rx-generic" placeholder="نام ژنریک" style="flex:2">' +
                    '<input id="cpms-doc-rx-dose" placeholder="دوز (مثلاً 500mg)">' +
                    '<input id="cpms-doc-rx-freq" placeholder="بهرا‌ه‌برداری (مثلاً هر ۸ ساعت)">' +
                    '<input id="cpms-doc-rx-days" type="number" min="1" placeholder="مدت (روز)">' +
                '</div>' +
                '<div class="cpms-doc-row"><label><input type="checkbox" id="cpms-doc-rx-visible" checked> قابل نمایش به بیمار</label></div>' +
                '<div><button class="button button-primary" data-act="add-rx">＋ ثبت نسخه Draft</button></div>' +
            '</div>' + rxHtml +
        '</details>' +

        '<details class="cpms-doc-section"><summary>💡 توصیه‌ها و 📅 پیگیری</summary>' +
            '<div class="cpms-doc-form" style="margin-bottom:12px">' +
                '<div class="cpms-doc-row"><select id="cpms-doc-rec-type">' +
                    Object.keys(REC_TYPES).map(function (k) { return '<option value="' + k + '">' + REC_TYPES[k] + '</option>'; }).join('') +
                '</select>' +
                '<input id="cpms-doc-rec-text" placeholder="متن توصیه" style="flex:2">' +
                '<label><input type="checkbox" id="cpms-doc-rec-visible" checked> نمایش به بیمار</label></div>' +
                '<div><button class="button button-primary" data-act="add-rec">＋ ثبت توصیه</button></div>' +
                '<hr><div class="cpms-doc-row">' +
                    '<input id="cpms-doc-fu-date" type="date">' +
                    '<input id="cpms-doc-fu-days" type="number" min="1" placeholder="یا بازه (روز)">' +
                    '<input id="cpms-doc-fu-reason" placeholder="دلیل پیگیری" style="flex:2"></div>' +
                '<div><button class="button" data-act="add-fu">＋ ثبت پیگیری</button></div>' +
            '</div>' +
            '<h4>توصیه‌ها</h4><ul class="cpms-doc-itemlist">' + recHtml + '</ul>' +
            '<h4>پیگیری‌ها</h4><ul class="cpms-doc-itemlist">' + fuHtml + '</ul>' +
        '</details>' +

        (CFG.can_upload ? '<details class="cpms-doc-section"><summary>📄 فایل‌ها (E16/E17)</summary>' +
            '<div class="cpms-doc-form" style="margin-bottom:12px">' +
                '<div class="cpms-doc-row"><select id="cpms-doc-file-cat">' +
                    Object.keys(FILE_CATS).map(function (k) { return '<option value="' + k + '">' + FILE_CATS[k] + '</option>'; }).join('') +
                '</select>' +
                '<select id="cpms-doc-file-vis"><option value="patient_visible">قابل نمایش به بیمار</option>' +
                '<option value="doctor_private">🔒 خصوصی — فقط پزشک</option></select></div>' +
                '<div class="cpms-doc-row"><input type="file" id="cpms-doc-file-input" accept=".pdf,.jpg,.jpeg,.png,.webp">' +
                '<button class="button button-primary" data-act="add-file">⬆️ آپلود</button>' +
                '<span class="description">PDF/JPG/PNG/WebP — حداکثر حجم و نوع روی سرور اعتبارسنجی می‌شود.</span></div>' +
            '</div><ul class="cpms-doc-itemlist">' + filesHtml + '</ul></details>' : '') +

        '<details class="cpms-doc-section"><summary>📖 سوابق و ویزیت‌های قبلی</summary>' +
            '<ul class="cpms-doc-itemlist">' + pastHtml + '</ul>' +
        '</details>';
    }

    function visitAction(act, id, extra) {
        var record = state.record;
        switch (act) {
            case 'call':
                api('POST', 'visits/' + id + '/call', {}).then(reloadOrError);
                break;
            case 'complete':
                api('POST', 'visits/' + id + '/complete', {}).then(function (r) {
                    if (r.status !== 200) {
                        var d = (r.body && r.body.data) || {};
                        notice(d.message || errMessage(r), r.status === 422 ? 'warning' : 'error');
                        return;
                    }
                    notice('ویزیت پایان یافت.', 'success');
                    loadRecord();
                });
                break;
            case 'reopen':
                var reason = window.prompt('دلیل بازگشایی ویزیت (الزامی — در Audit ثبت می‌شود):');
                if (!reason) { return; }
                api('POST', 'visits/' + id + '/reopen', { reason: reason }).then(reloadOrError);
                break;
            case 'add-note':
                api('POST', 'visits/' + CFG.visit_id + '/notes', {
                    category: document.getElementById('cpms-doc-note-cat').value,
                    visibility: document.getElementById('cpms-doc-note-vis').value,
                    content_text: document.getElementById('cpms-doc-note-text').value
                }).then(function (r) {
                    if (r.status !== 201) { notice(errMessage(r), 'error'); return; }
                    notice('یادداشت ثبت شد.', 'success');
                    loadRecord();
                });
                break;
            case 'edit-note':
                openNoteEdit(id);
                break;
            case 'save-note':
                api('PUT', 'notes/' + id, {
                    content_text: document.getElementById('cpms-doc-edit-text-' + id).value,
                    change_reason: document.getElementById('cpms-doc-edit-reason-' + id).value
                }).then(function (r) {
                    if (r.status !== 200) { notice(errMessage(r), 'error'); return; }
                    notice('اصلاح ثبت شد — نسخه قبلی حفظ می‌شود (FR-8.5).', 'success');
                    loadRecord();
                });
                break;
            case 'add-rx':
                api('POST', 'visits/' + CFG.visit_id + '/prescriptions', {
                    items: [{
                        generic_name: document.getElementById('cpms-doc-rx-generic').value,
                        dose: document.getElementById('cpms-doc-rx-dose').value,
                        frequency: document.getElementById('cpms-doc-rx-freq').value,
                        duration_days: document.getElementById('cpms-doc-rx-days').value || null
                    }],
                    is_patient_visible: document.getElementById('cpms-doc-rx-visible').checked
                }).then(function (r) {
                    if (r.status !== 201) { notice(errMessage(r), 'error'); return; }
                    notice('نسخه Draft ثبت شد — پس از بررسی نهایی‌سازی کنید.', 'success');
                    loadRecord();
                });
                break;
            case 'finalize-rx':
                api('POST', 'prescriptions/' + id + '/finalize', {}).then(function (r) {
                    if (r.status !== 200) { notice(errMessage(r), 'error'); return; }
                    notice('نسخه نهایی شد.', 'success');
                    loadRecord();
                });
                break;
            case 'add-rec':
                api('POST', 'visits/' + CFG.visit_id + '/recommendations', {
                    items: [{
                        type: document.getElementById('cpms-doc-rec-type').value,
                        text: document.getElementById('cpms-doc-rec-text').value,
                        is_patient_visible: document.getElementById('cpms-doc-rec-visible').checked
                    }]
                }).then(function (r) {
                    if (r.status !== 201) { notice(errMessage(r), 'error'); return; }
                    notice('توصیه ثبت شد.', 'success');
                    loadRecord();
                });
                break;
            case 'add-fu':
                var body = { is_needed: true, reason: document.getElementById('cpms-doc-fu-reason').value };
                if (document.getElementById('cpms-doc-fu-date').value) { body.suggested_date = document.getElementById('cpms-doc-fu-date').value; }
                if (document.getElementById('cpms-doc-fu-days').value) { body.interval_days = document.getElementById('cpms-doc-fu-days').value; }
                api('POST', 'visits/' + CFG.visit_id + '/follow-ups', body).then(function (r) {
                    if (r.status !== 201) { notice(errMessage(r), 'error'); return; }
                    notice('پیگیری ثبت شد.', 'success');
                    loadRecord();
                });
                break;
            case 'add-file':
                var input = document.getElementById('cpms-doc-file-input');
                if (!input.files || !input.files[0]) { notice('ابتدا فایل را انتخاب کنید.', 'warning'); return; }
                var fd = new FormData();
                fd.append('file', input.files[0]);
                fd.append('patient_id', record.patient.id);
                fd.append('visit_id', CFG.visit_id);
                fd.append('category', document.getElementById('cpms-doc-file-cat').value);
                fd.append('visibility', document.getElementById('cpms-doc-file-vis').value);
                api('POST', 'files', fd, true).then(function (r) {
                    if (r.status !== 201) { notice(errMessage(r), 'error'); return; }
                    notice('فایل آپلود شد.', 'success');
                    loadRecord();
                });
                break;
            case 'stream':
                fetch(CFG.rest_url + 'files/' + id + '/stream', { headers: { 'X-WP-Nonce': CFG.nonce } })
                    .then(function (r) {
                        if (r.status !== 200) { throw new Error('stream ' + r.status); }
                        return r.blob();
                    })
                    .then(function (b) { window.open(URL.createObjectURL(b), '_blank'); })
                    .catch(function () { notice('دریافت فایل ممکن نشد.', 'error'); });
                break;
        }
    }

    function reloadOrError(r) {
        if (r.status !== 200) { notice(errMessage(r), 'error'); return; }
        notice('انجام شد.', 'success');
        loadRecord();
    }

    function openNoteEdit(noteId) {
        var noteEl = document.querySelector('[data-act="edit-note"][data-id="' + noteId + '"]');
        if (!noteEl) { return; }
        var form = noteEl.parentElement.parentElement.querySelector('.edit-form');
        var note = (state.record.notes || []).filter(function (n) { return String(n.id) === String(noteId); })[0];
        if (!form || !note) { return; }
        form.style.display = 'block';
        form.innerHTML = '<div class="cpms-doc-form" style="margin-top:6px">' +
            '<textarea id="cpms-doc-edit-text-' + noteId + '">' + esc(note.content_text) + '</textarea>' +
            '<input id="cpms-doc-edit-reason-' + noteId + '" placeholder="دلیل اصلاح (الزامی)">' +
            '<button class="button button-primary button-small" data-act="save-note" data-id="' + noteId + '">ذخیره نسخه جدید</button></div>';
    }

    // ================= Wiring =================

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button[data-act], a[data-act]');
        if (!btn) { return; }
        var act = btn.getAttribute('data-act');
        var id = btn.getAttribute('data-id') || CFG.visit_id;
        if (btn.tagName === 'A') { return; }
        ev.preventDefault();
        if (CFG.visit_id > 0) {
            visitAction(act, id);
        } else if (['call', 'recall', 'start'].indexOf(act) >= 0) {
            queueAction(act, id);
        }
    });

    function poll() {
        if (state.pollPaused || document.hidden || CFG.visit_id > 0) { return; }
        loadQueue().catch(function () {});
    }

    function start() {
        if (CFG.visit_id > 0) {
            loadRecord().catch(function (e) {
                notice(e.message || 'خطا در دریافت پرونده', 'error');
            });
        } else {
            loadQueue().catch(function (e) { notice(e.message || 'خطا در دریافت صف', 'error'); });
            state.timer = setInterval(poll, CFG.poll_ms);
        }
        document.addEventListener('visibilitychange', function () {
            var el = document.getElementById('cpms-doc-live');
            if (document.hidden) {
                el.classList.add('paused');
                el.textContent = '● متوقف (تب مخفی)';
            } else {
                el.classList.remove('paused');
                el.textContent = '● زنده';
                poll();
            }
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
<?php
    }
}

<?php
/**
 * Phase 14 Slices 1–3 — Reports module inside the independent Staff Portal.
 *
 * A read-only Average Waiting, Visit Duration and Walk-in-visits-recorded
 * surface for ONE explicitly selected Gregorian Y-m-d date. Each action reuses its existing GET report
 * route (from = to = the selected date); it calculates nothing itself and adds
 * no REST route or service. Visit Duration is the consultation interval from
 * consultation_started_at to consultation_completed_at, for records with both
 * timestamps, grouped by the already-recorded visit_date. Walk-in visits recorded
 * counts Visit records whose stored source is exactly walk_in on that stored
 * visit_date — all statuses, all sources otherwise excluded, never the 500-row
 * list cap. The Clinic is chosen
 * through the existing REST Clinic-context header (X-CPMS-Clinic-Id): 1 eligible
 * Clinic is shown and sent as-is, N>1 require an explicit selection (no
 * first-Clinic fallback). The id is a SELECTOR only — the REST boundary validates
 * it against active membership and report capability on every request, and the
 * own-vs-Clinic scope stays server-decided. No Location id is ever sent. Only
 * aggregates (sample count + average) are shown — never visit rows, patient data
 * or clinical detail. No wp-admin chrome, Location selector, chart, export or
 * print surface.
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
$cpms_reports_clinics = \ClinicCore\Frontend\StaffPortalShell::reports_eligible_clinics( (int) get_current_user_id() );
?>
<style>
.cpms-reports-board { max-width: 760px; display: grid; gap: 12px; }
.cpms-reports-board__panel { min-width: 0; padding: 14px; border: 1px solid var(--cpms-border); border-radius: 10px; background: #fff; }
.cpms-reports-board__heading { margin: 0 0 8px; font-size: 1.15rem; }
.cpms-reports-board__hint { margin: 0 0 10px; color: var(--cpms-muted); overflow-wrap: anywhere; }
.cpms-reports-board__form { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; }
.cpms-reports-board__field { display: grid; gap: 4px; min-width: 0; color: var(--cpms-muted); }
.cpms-reports-board__field input, .cpms-reports-board__field select { min-height: 40px; min-width: 190px; max-width: 100%; padding: 6px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; font: inherit; color: var(--cpms-text); direction: ltr; text-align: right; }
.cpms-reports-board__clinic { margin: 0 0 10px; overflow-wrap: anywhere; }
.cpms-reports-board__submit { min-height: 40px; padding: 6px 16px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #1d2327; color: #fff; font: inherit; cursor: pointer; }
.cpms-reports-board__submit[disabled] { opacity: .6; cursor: default; }
.cpms-reports-board__submit--duration, .cpms-reports-board__submit--walkin { background: #fff; color: var(--cpms-text); }
.cpms-reports-board__status { min-height: 1.5em; margin: 0; overflow-wrap: anywhere; }
.cpms-reports-board__status[data-kind="error"] { color: #a12828; }
.cpms-reports-board__metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0; }
.cpms-reports-board__metric { margin: 0; padding: 10px; border: 1px solid var(--cpms-border); border-radius: 8px; }
.cpms-reports-board__metric dt { color: var(--cpms-muted); font-size: .84rem; }
.cpms-reports-board__metric dd { margin: 4px 0 0; font-size: 1.15rem; font-weight: 700; overflow-wrap: anywhere; }
@media (max-width: 560px) {
    .cpms-reports-board__panel { padding: 11px; }
    .cpms-reports-board__form { display: grid; }
    .cpms-reports-board__field input, .cpms-reports-board__field select, .cpms-reports-board__submit { width: 100%; }
    .cpms-reports-board__metrics { grid-template-columns: minmax(0, 1fr); }
}
</style>
<main class="cpms-reports-board" data-role="reports-root" data-cpms-staff-module="reports" aria-labelledby="cpms-reports-board-title">
    <section class="cpms-reports-board__panel" aria-label="گزارش میانگین زمان انتظار">
        <h2 class="cpms-reports-board__heading" id="cpms-reports-board-title">میانگین زمان انتظار — یک تاریخ مشخص</h2>
        <p class="cpms-reports-board__hint" data-role="reports-date-note">تاریخ را به‌صورت میلادی و با قالب «سال-ماه-روز» (مثلاً 2026-03-14) انتخاب کنید. گزارش بر پایهٔ «تاریخ ثبت‌شدهٔ ویزیت» محاسبه می‌شود؛ این به‌معنای یک روز تقویمی محلیِ واحد برای همهٔ موقعیت‌های کلینیک نیست. تاریخ پیش‌فرضی انتخاب نمی‌شود.</p>
<?php if ( array() === $cpms_reports_clinics ) : ?>
        <p class="cpms-reports-board__status" data-role="reports-no-clinic" data-kind="error">کلینیکی با دسترسی مشاهدهٔ گزارش برای شما در دسترس نیست.</p>
<?php else : ?>
	<?php if ( 1 === count( $cpms_reports_clinics ) ) : ?>
        <p class="cpms-reports-board__clinic" data-role="reports-clinic-fixed" data-clinic-id="<?php echo esc_attr( (string) $cpms_reports_clinics[0]['id'] ); ?>">کلینیک: <strong data-role="reports-clinic-name"><?php echo esc_html( $cpms_reports_clinics[0]['name'] ); ?></strong></p>
<?php endif; ?>
        <form class="cpms-reports-board__form" data-role="reports-avg-waiting-form" novalidate>
	<?php if ( count( $cpms_reports_clinics ) > 1 ) : ?>
            <label class="cpms-reports-board__field">کلینیک
                <select name="clinic" required data-role="reports-clinic-select" aria-describedby="cpms-reports-board-status">
                    <option value="">انتخاب کلینیک…</option>
		<?php foreach ( $cpms_reports_clinics as $cpms_reports_clinic ) : ?>
                    <option value="<?php echo esc_attr( (string) $cpms_reports_clinic['id'] ); ?>"><?php echo esc_html( $cpms_reports_clinic['name'] ); ?></option>
<?php endforeach; ?>
                </select>
            </label>
<?php endif; ?>
            <label class="cpms-reports-board__field">تاریخ گزارش (میلادی، YYYY-MM-DD)
                <input type="date" name="date" required inputmode="numeric" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}" autocomplete="off" data-role="reports-date-input" aria-describedby="cpms-reports-board-status">
            </label>
            <button type="submit" class="cpms-reports-board__submit" data-role="reports-submit">نمایش میانگین زمان انتظار</button>
            <button type="button" class="cpms-reports-board__submit cpms-reports-board__submit--duration" data-role="reports-visit-duration-action" aria-controls="cpms-reports-visit-duration-result">نمایش میانگین مدت مشاوره</button>
            <button type="button" class="cpms-reports-board__submit cpms-reports-board__submit--walkin" data-role="reports-walk-in-count-action" aria-controls="cpms-reports-walk-in-count-result">نمایش تعداد ویزیت‌های بدون نوبت ثبت‌شده</button>
        </form>
<?php endif; ?>
    </section>
    <p class="cpms-reports-board__panel cpms-reports-board__status" id="cpms-reports-board-status" data-role="reports-status" role="status" aria-live="polite"<?php echo array() === $cpms_reports_clinics ? ' hidden' : ''; ?>><?php echo count( $cpms_reports_clinics ) > 1 ? 'برای مشاهدهٔ گزارش، یک کلینیک و یک تاریخ انتخاب کنید.' : 'برای مشاهدهٔ گزارش، یک تاریخ انتخاب کنید.'; ?></p>
    <section class="cpms-reports-board__panel" data-role="reports-result" aria-label="نتیجهٔ گزارش میانگین زمان انتظار" hidden>
        <p class="cpms-reports-board__hint" data-role="reports-result-meta"></p>
        <dl class="cpms-reports-board__metrics">
            <div class="cpms-reports-board__metric"><dt>میانگین زمان انتظار</dt><dd data-role="reports-avg"></dd></div>
            <div class="cpms-reports-board__metric"><dt>تعداد ویزیت‌های نمونه</dt><dd data-role="reports-count"></dd></div>
        </dl>
    </section>
    <section class="cpms-reports-board__panel" id="cpms-reports-visit-duration-result" data-role="reports-visit-duration-result" aria-label="نتیجهٔ گزارش مدت مشاوره" hidden>
        <p class="cpms-reports-board__hint" data-role="reports-visit-duration-description">میانگین مدت مشاوره از زمان شروع ثبت‌شدهٔ مشاوره (<code>consultation_started_at</code>) تا زمان پایان ثبت‌شدهٔ آن (<code>consultation_completed_at</code>) محاسبه می‌شود و فقط ویزیت‌هایی را دربرمی‌گیرد که هر دو زمان را دارند. تاریخ بر پایهٔ تاریخ ثبت‌شدهٔ ویزیت (<code>visit_date</code>) است.</p>
        <p class="cpms-reports-board__hint" data-role="reports-visit-duration-meta"></p>
        <dl class="cpms-reports-board__metrics">
            <div class="cpms-reports-board__metric"><dt>تعداد ویزیت‌های نمونه</dt><dd data-role="reports-visit-duration-count">—</dd></div>
            <div class="cpms-reports-board__metric"><dt>میانگین مدت مشاوره</dt><dd data-role="reports-visit-duration-average">—</dd></div>
        </dl>
    </section>
    <section class="cpms-reports-board__panel" id="cpms-reports-walk-in-count-result" data-role="reports-walk-in-count-result" aria-label="نتیجهٔ گزارش ویزیت‌های بدون نوبت ثبت‌شده" hidden>
        <p class="cpms-reports-board__hint" data-role="reports-walk-in-count-description">این عدد «تعداد رکوردهای ویزیت» با منبع ثبت‌شدهٔ <code>walk_in</code> است که تاریخ ثبت‌شدهٔ ویزیت (<code>visit_date</code>) آن‌ها برابر تاریخ انتخاب‌شده باشد. شمارش شامل همهٔ وضعیت‌های ویزیت است؛ بیماران یکتا، نوبت‌ها، ویزیت‌های تکمیل‌شده یا فقط Walk-inهای ساخته‌شده توسط پذیرش را نمی‌شمارد و ادعای یک روز تقویمی محلیِ واحد برای همهٔ موقعیت‌های کلینیک نیست.</p>
        <p class="cpms-reports-board__hint" data-role="reports-walk-in-count-meta"></p>
        <dl class="cpms-reports-board__metrics">
            <div class="cpms-reports-board__metric"><dt>تعداد رکوردهای ویزیت با منبع walk_in</dt><dd data-role="reports-walk-in-count">—</dd></div>
        </dl>
    </section>
</main>
<script type="application/json" id="cpms-reports-board-config">
<?php
$cpms_reports_board_config = array(
    'rest_root' => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
);
echo wp_json_encode( $cpms_reports_board_config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON config is encoded by wp_json_encode.
?>
</script>
<script>
(function () {
    'use strict';
    var configNode = document.getElementById('cpms-reports-board-config');
    var config;
    try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
    var root = String(config.rest_root || '').replace(/\/$/, '');
    var form = document.querySelector('[data-role="reports-avg-waiting-form"]');
    if (!form) return; // no eligible Clinic: nothing to submit (fail closed)
    var clinicSelect = document.querySelector('[data-role="reports-clinic-select"]');
    var clinicFixed = document.querySelector('[data-role="reports-clinic-fixed"]');
    var input = document.querySelector('[data-role="reports-date-input"]');
    var submit = document.querySelector('[data-role="reports-submit"]');
    var durationAction = document.querySelector('[data-role="reports-visit-duration-action"]');
    var statusNode = document.querySelector('[data-role="reports-status"]');
    var resultNode = document.querySelector('[data-role="reports-result"]');
    var metaNode = document.querySelector('[data-role="reports-result-meta"]');
    var avgNode = document.querySelector('[data-role="reports-avg"]');
    var countNode = document.querySelector('[data-role="reports-count"]');
    var durationResultNode = document.querySelector('[data-role="reports-visit-duration-result"]');
    var durationMetaNode = document.querySelector('[data-role="reports-visit-duration-meta"]');
    var durationCountNode = document.querySelector('[data-role="reports-visit-duration-count"]');
    var durationAverageNode = document.querySelector('[data-role="reports-visit-duration-average"]');
    var walkInAction = document.querySelector('[data-role="reports-walk-in-count-action"]');
    var walkInResultNode = document.querySelector('[data-role="reports-walk-in-count-result"]');
    var walkInMetaNode = document.querySelector('[data-role="reports-walk-in-count-meta"]');
    var walkInCountNode = document.querySelector('[data-role="reports-walk-in-count"]');
    var ticket = 0;
    var activeController = null;
    var activeTimer = null;
    var TIMEOUT_MS = 20000;
    var routes = {
        avg_waiting: '/reports/avg_waiting',
        visit_duration: '/reports/visit_duration',
        walk_ins_recorded: '/reports/walk_ins_recorded'
    };

    function setStatus(message, kind) {
        statusNode.textContent = message || '';
        statusNode.dataset.kind = kind || '';
    }
    function setActionsDisabled(disabled) {
        submit.disabled = disabled;
        durationAction.disabled = disabled;
        walkInAction.disabled = disabled;
    }
    function clearResults() {
        resultNode.hidden = true;
        metaNode.textContent = '';
        avgNode.textContent = '';
        countNode.textContent = '';
        durationResultNode.hidden = true;
        durationMetaNode.textContent = '';
        durationCountNode.textContent = '';
        durationAverageNode.textContent = '';
        walkInResultNode.hidden = true;
        walkInMetaNode.textContent = '';
        walkInCountNode.textContent = '';
    }
    function cancelActive() {
        ticket += 1;
        if (activeTimer !== null) window.clearTimeout(activeTimer);
        activeTimer = null;
        if (activeController !== null) {
            activeController.abort();
            activeController = null;
        }
        setActionsDisabled(false);
        return ticket;
    }
    function fa(number) { return Number(number).toLocaleString('fa-IR'); }
    // The server is authoritative; this only avoids a pointless request.
    function validDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        if (!match) return false;
        var year = Number(match[1]);
        var month = Number(match[2]) - 1;
        var day = Number(match[3]);
        if (year < 1) return false;
        // setUTCFullYear avoids Date.UTC's special 1900 offset for years 00–99.
        var probe = new Date(0);
        probe.setUTCHours(0, 0, 0, 0);
        probe.setUTCFullYear(year, month, day);
        return probe.getUTCFullYear() === year && probe.getUTCMonth() === month && probe.getUTCDate() === day;
    }
    function duration(totalSeconds) {
        var seconds = Math.max(0, Math.round(Number(totalSeconds) || 0));
        var minutes = Math.floor(seconds / 60);
        var rest = seconds % 60;
        if (minutes === 0) return fa(rest) + ' ثانیه';
        return fa(minutes) + ' دقیقه' + (rest > 0 ? ' و ' + fa(rest) + ' ثانیه' : '');
    }
    // Clinic SELECTOR only (never authority): the server validates it on every
    // request. N>1 has no default — an empty choice yields null (no request).
    function chosenClinic() {
        var raw = '';
        var name = '';
        if (clinicSelect) {
            raw = String(clinicSelect.value || '');
            var option = clinicSelect.options[clinicSelect.selectedIndex];
            name = option ? String(option.textContent || '') : '';
        } else if (clinicFixed) {
            raw = String(clinicFixed.getAttribute('data-clinic-id') || '');
            var strong = clinicFixed.querySelector('[data-role="reports-clinic-name"]');
            name = strong ? String(strong.textContent || '') : '';
        }
        return /^[1-9][0-9]{0,18}$/.test(raw) ? { id: raw, name: name } : null;
    }
    function selectionKey() {
        var clinic = chosenClinic();
        return (clinic ? clinic.id : '') + '|' + String(input.value || '');
    }
    var currentSelection = selectionKey();
    function invalidateSelection() {
        var nextSelection = selectionKey();
        if (nextSelection === currentSelection) return;
        currentSelection = nextSelection;
        cancelActive();
        clearResults();
        setStatus('برای دریافت هر گزارش، یک تاریخ انتخاب کنید و یکی از دکمه‌های گزارش را بزنید.', '');
    }
    function errorMessage(status, code) {
        if (code === 'CLINIC_VALIDATION_FAILED') return 'تاریخ یا کلینیک واردشده معتبر نیست؛ تاریخ را به‌صورت میلادی و با قالب YYYY-MM-DD وارد کنید.';
        if (code === 'CLINIC_SCOPE_REQUIRED') return 'ابتدا یک کلینیک را انتخاب کنید.';
        if (code === 'CLINIC_SCOPE_UNAVAILABLE') return 'کلینیک انتخاب‌شده برای شما در دسترس نیست؛ صفحه را تازه کنید و دوباره تلاش کنید.';
        if (status === 401 || status === 403 || code === 'CLINIC_PERMISSION_DENIED' || code === 'CLINIC_INVALID_NONCE') return 'دسترسی شما به این گزارش مجاز نیست یا نشست منقضی شده است.';
        return 'دریافت گزارش ناموفق بود. دوباره تلاش کنید.';
    }
    function endpoint(type, date) {
        var path = routes[type];
        if (!path) return '';
        var url = root + path;
        // Plain permalinks already carry a query (?rest_route=…): join with &.
        var glue = url.indexOf('?') === -1 ? '?' : '&';
        return url + glue + 'from=' + encodeURIComponent(date) + '&to=' + encodeURIComponent(date);
    }
    function resultMeta(data, date, clinic) {
        var scope = data && data.scope === 'own' ? 'فقط ویزیت‌های مربوط به شما' : 'مجموع ویزیت‌های کلینیک';
        var jalali = data && data.from_jalali ? ' — ' + String(data.from_jalali) : '';
        return 'کلینیک: ' + clinic.name + ' · تاریخ ثبت‌شدهٔ ویزیت: ' + date + jalali + ' · ' + scope;
    }
    function renderWaiting(data, date, clinic) {
        var summary = data && data.summary ? data.summary : {};
        var visits = Number(summary.visits) || 0;
        resultNode.hidden = false;
        metaNode.textContent = resultMeta(data, date, clinic);
        if (visits === 0) {
            avgNode.textContent = '—';
            countNode.textContent = fa(0);
            setStatus('برای این تاریخ ویزیتی با زمان انتظار ثبت‌شده وجود ندارد.', '');
            return;
        }
        avgNode.textContent = duration(summary.avg_sec);
        countNode.textContent = fa(visits);
        setStatus('گزارش دریافت شد.', '');
    }
    function renderVisitDuration(data, date, clinic) {
        var summary = data && data.summary ? data.summary : null;
        var visits = summary ? Number(summary.visits) : NaN;
        if (!Number.isFinite(visits) || visits < 0 || Math.floor(visits) !== visits) {
            setStatus('دادهٔ گزارش معتبر نیست. دوباره تلاش کنید.', 'error');
            return;
        }
        durationResultNode.hidden = false;
        durationMetaNode.textContent = resultMeta(data, date, clinic);
        durationCountNode.textContent = fa(visits);
        if (visits === 0) {
            durationAverageNode.textContent = '—';
            setStatus('برای این تاریخ ویزیتی با هر دو زمان شروع و پایان مشاوره ثبت نشده است.', '');
            return;
        }
        var averageSeconds = Number(summary.avg_sec);
        if (!Number.isFinite(averageSeconds) || averageSeconds < 0) {
            durationResultNode.hidden = true;
            setStatus('دادهٔ گزارش معتبر نیست. دوباره تلاش کنید.', 'error');
            return;
        }
        durationAverageNode.textContent = duration(averageSeconds);
        setStatus('گزارش مدت مشاوره دریافت شد.', '');
    }
    // Aggregate-only count of Visit records whose STORED source is walk_in on the
    // STORED visit_date. Status never redefines the metric; zero is a real 0.
    function renderWalkInCount(data, date, clinic) {
        var summary = data && data.summary ? data.summary : null;
        var count = summary ? Number(summary.count) : NaN;
        if (!Number.isFinite(count) || count < 0 || Math.floor(count) !== count) {
            setStatus('دادهٔ گزارش معتبر نیست. دوباره تلاش کنید.', 'error');
            return;
        }
        walkInResultNode.hidden = false;
        walkInMetaNode.textContent = resultMeta(data, date, clinic);
        walkInCountNode.textContent = fa(count);
        setStatus(count === 0 ? 'در این تاریخ هیچ ویزیت بدون نوبت ثبت نشده است.' : 'گزارش ویزیت‌های بدون نوبت ثبت‌شده دریافت شد.', '');
    }
    function loadReport(type, date, clinic) {
        var current = cancelActive();
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        activeController = controller;
        if (controller) {
            activeTimer = window.setTimeout(function () {
                if (current === ticket && activeController === controller) controller.abort();
            }, TIMEOUT_MS);
        }
        var options = { method: 'GET', headers: { 'X-WP-Nonce': String(config.nonce || ''), 'X-CPMS-Clinic-Id': clinic.id, 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' };
        if (controller) options.signal = controller.signal;
        clearResults();
        setActionsDisabled(true);
        setStatus('در حال دریافت گزارش…', '');
        fetch(endpoint(type, date), options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                return { ok: response.ok, status: response.status, body: body };
            });
        }).then(function (result) {
            if (current !== ticket) return;
            if (!result.ok) {
                setStatus(errorMessage(result.status, result.body && result.body.code || ''), 'error');
                return;
            }
            var data = result.body && result.body.data ? result.body.data : {};
            if (type === 'visit_duration') renderVisitDuration(data, date, clinic);
            else if (type === 'walk_ins_recorded') renderWalkInCount(data, date, clinic);
            else renderWaiting(data, date, clinic);
        }).catch(function () {
            if (current !== ticket) return;
            setStatus('دریافت گزارش ناموفق بود. دوباره تلاش کنید.', 'error');
        }).then(function () {
            if (current !== ticket) return;
            if (activeTimer !== null) window.clearTimeout(activeTimer);
            activeTimer = null;
            activeController = null;
            setActionsDisabled(false);
        });
    }
    function runReport(type) {
        if (submit.disabled || durationAction.disabled || walkInAction.disabled) return;
        var clinic = chosenClinic();
        if (!clinic) {
            cancelActive();
            clearResults();
            setStatus('ابتدا یک کلینیک را انتخاب کنید.', 'error');
            if (clinicSelect) clinicSelect.focus();
            return;
        }
        var date = String(input.value || '').trim();
        if (date === '') {
            cancelActive();
            clearResults();
            setStatus('ابتدا یک تاریخ انتخاب کنید.', 'error');
            input.focus();
            return;
        }
        if (!validDate(date)) {
            cancelActive();
            clearResults();
            setStatus('تاریخ واردشده معتبر نیست؛ آن را به‌صورت میلادی و با قالب YYYY-MM-DD وارد کنید.', 'error');
            input.focus();
            return;
        }
        loadReport(type, date, clinic);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        runReport('avg_waiting');
    });
    durationAction.addEventListener('click', function (event) {
        event.preventDefault();
        runReport('visit_duration');
    });
    walkInAction.addEventListener('click', function (event) {
        event.preventDefault();
        runReport('walk_ins_recorded');
    });

    // A Clinic or date change invalidates both displayed and in-flight results.
    if (clinicSelect) clinicSelect.addEventListener('change', invalidateSelection);
    input.addEventListener('input', invalidateSelection);
    input.addEventListener('change', invalidateSelection);
}());
</script>
</div>

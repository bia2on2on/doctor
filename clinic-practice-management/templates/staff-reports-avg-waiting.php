<?php
/**
 * Phase 14 Slice 1 — Reports module inside the independent Staff Portal.
 *
 * A read-only Average Waiting surface for ONE explicitly selected Gregorian
 * Y-m-d date. It reuses the existing `GET /clinic/v1/reports/avg_waiting`
 * route (from = to = the selected date); it calculates nothing itself and adds
 * no REST route. The Clinic is chosen through the existing REST Clinic-context
 * header (`X-CPMS-Clinic-Id`): 1 eligible Clinic is shown and sent as-is, N>1
 * require an explicit selection (no first-Clinic fallback). The id is a
 * SELECTOR only — the REST boundary validates it against the authenticated
 * user's active membership and the report capability on every request, and the
 * own-vs-Clinic scope stays server-decided. No Location id is ever sent. Only the aggregate (average + visit count) is shown — never visit rows,
 * patient data or clinical detail. No wp-admin chrome, selector, chart, export
 * or print surface.
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
            <button type="submit" class="cpms-reports-board__submit" data-role="reports-submit">نمایش گزارش</button>
        </form>
<?php endif; ?>
    </section>
    <p class="cpms-reports-board__panel cpms-reports-board__status" id="cpms-reports-board-status" data-role="reports-status" role="status" aria-live="polite"<?php echo array() === $cpms_reports_clinics ? ' hidden' : ''; ?>><?php echo count( $cpms_reports_clinics ) > 1 ? 'برای مشاهدهٔ گزارش، یک کلینیک و یک تاریخ انتخاب کنید.' : 'برای مشاهدهٔ گزارش، یک تاریخ انتخاب کنید.'; ?></p>
    <section class="cpms-reports-board__panel" data-role="reports-result" aria-label="نتیجهٔ گزارش" hidden>
        <p class="cpms-reports-board__hint" data-role="reports-result-meta"></p>
        <dl class="cpms-reports-board__metrics">
            <div class="cpms-reports-board__metric"><dt>میانگین زمان انتظار</dt><dd data-role="reports-avg"></dd></div>
            <div class="cpms-reports-board__metric"><dt>تعداد ویزیت‌های نمونه</dt><dd data-role="reports-count"></dd></div>
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
    var statusNode = document.querySelector('[data-role="reports-status"]');
    var resultNode = document.querySelector('[data-role="reports-result"]');
    var metaNode = document.querySelector('[data-role="reports-result-meta"]');
    var avgNode = document.querySelector('[data-role="reports-avg"]');
    var countNode = document.querySelector('[data-role="reports-count"]');
    var ticket = 0;
    var TIMEOUT_MS = 20000;

    function setStatus(message, kind) {
        statusNode.textContent = message || '';
        statusNode.dataset.kind = kind || '';
    }
    function fa(number) { return Number(number).toLocaleString('fa-IR'); }
    // The server is authoritative; this only avoids a pointless request.
    function validDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        if (!match) return false;
        var probe = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
        return probe.getUTCFullYear() === Number(match[1]) && probe.getUTCMonth() === Number(match[2]) - 1 && probe.getUTCDate() === Number(match[3]);
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
    function errorMessage(status, code) {
        if (code === 'CLINIC_VALIDATION_FAILED') return 'تاریخ یا کلینیک واردشده معتبر نیست؛ تاریخ را به‌صورت میلادی و با قالب YYYY-MM-DD وارد کنید.';
        if (code === 'CLINIC_SCOPE_REQUIRED') return 'ابتدا یک کلینیک را انتخاب کنید.';
        if (code === 'CLINIC_SCOPE_UNAVAILABLE') return 'کلینیک انتخاب‌شده برای شما در دسترس نیست؛ صفحه را تازه کنید و دوباره تلاش کنید.';
        if (status === 401 || status === 403 || code === 'CLINIC_PERMISSION_DENIED' || code === 'CLINIC_INVALID_NONCE') return 'دسترسی شما به این گزارش مجاز نیست یا نشست منقضی شده است.';
        return 'دریافت گزارش ناموفق بود. دوباره تلاش کنید.';
    }
    function endpoint(date) {
        var url = root + '/reports/avg_waiting';
        // Plain permalinks already carry a query (?rest_route=…): join with &.
        var glue = url.indexOf('?') === -1 ? '?' : '&';
        return url + glue + 'from=' + encodeURIComponent(date) + '&to=' + encodeURIComponent(date);
    }
    function render(data, date, clinic) {
        var summary = data && data.summary ? data.summary : {};
        var visits = Number(summary.visits) || 0;
        resultNode.hidden = false;
        var scope = data && data.scope === 'own' ? 'فقط ویزیت‌های مربوط به شما' : 'مجموع ویزیت‌های کلینیک';
        var jalali = data && data.from_jalali ? ' — ' + String(data.from_jalali) : '';
        metaNode.textContent = 'کلینیک: ' + clinic.name + ' · تاریخ ثبت‌شدهٔ ویزیت: ' + date + jalali + ' · ' + scope;
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
    function load(date, clinic) {
        var current = ++ticket;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? window.setTimeout(function () { controller.abort(); }, TIMEOUT_MS) : null;
        var options = { method: 'GET', headers: { 'X-WP-Nonce': String(config.nonce || ''), 'X-CPMS-Clinic-Id': clinic.id, 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' };
        if (controller) options.signal = controller.signal;
        submit.disabled = true;
        resultNode.hidden = true;
        setStatus('در حال دریافت گزارش…', '');
        fetch(endpoint(date), options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                return { ok: response.ok, status: response.status, body: body };
            });
        }).then(function (result) {
            if (current !== ticket) return;
            if (!result.ok) {
                setStatus(errorMessage(result.status, result.body && result.body.code || ''), 'error');
                return;
            }
            render(result.body && result.body.data ? result.body.data : {}, date, clinic);
        }).catch(function () {
            if (current !== ticket) return;
            setStatus('دریافت گزارش ناموفق بود. دوباره تلاش کنید.', 'error');
        }).then(function () {
            if (timer) window.clearTimeout(timer);
            if (current === ticket) submit.disabled = false;
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (submit.disabled) return;
        var clinic = chosenClinic();
        if (!clinic) {
            resultNode.hidden = true;
            setStatus('ابتدا یک کلینیک را انتخاب کنید.', 'error');
            if (clinicSelect) clinicSelect.focus();
            return;
        }
        var date = String(input.value || '').trim();
        if (date === '') {
            resultNode.hidden = true;
            setStatus('ابتدا یک تاریخ انتخاب کنید.', 'error');
            input.focus();
            return;
        }
        if (!validDate(date)) {
            resultNode.hidden = true;
            setStatus('تاریخ واردشده معتبر نیست؛ آن را به‌صورت میلادی و با قالب YYYY-MM-DD وارد کنید.', 'error');
            input.focus();
            return;
        }
        load(date, clinic);
    });

    // A different Clinic invalidates any shown/in-flight result: never leave one
    // Clinic's numbers on screen under another Clinic's selection.
    if (clinicSelect) {
        clinicSelect.addEventListener('change', function () {
            ticket += 1;
            submit.disabled = false;
            resultNode.hidden = true;
            setStatus('برای مشاهدهٔ گزارش، تاریخ را انتخاب و «نمایش گزارش» را بزنید.', '');
        });
    }
}());
</script>
</div>

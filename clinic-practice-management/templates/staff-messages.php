<?php
/**
 * Iran V1 — internal Doctor/Receptionist messaging module (Staff Portal).
 *
 * Self-contained hybrid module: the shell/document and this markup are
 * server-rendered; lists/messages use authenticated incremental REST polling
 * (no WebSocket, no WordPress comments). All dynamic text is rendered with
 * textContent, never innerHTML. Persian RTL, responsive, keyboard accessible.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( empty( $cpms_staff_embed ) ) {
    ?><!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>پیام‌های داخلی</title></head><body><p>این صفحه فقط از طریق پورتال کارکنان در دسترس است.</p></body></html><?php
    return;
}

use ClinicCore\Bootstrap\App;

$cpms_messages_user_id = (int) get_current_user_id();
$cpms_messages_clinics = array();
foreach ( App::membership_service()->active_memberships_for_user( $cpms_messages_user_id ) as $cpms_membership ) {
    $cpms_cid = (int) ( $cpms_membership['clinic_id'] ?? 0 );
    if ( $cpms_cid > 0 ) {
        $cpms_messages_clinics[] = array(
            'id'   => $cpms_cid,
            'name' => (string) ( $cpms_membership['clinic_name'] ?? ( 'مطب ' . $cpms_cid ) ),
        );
    }
}
$cpms_messages_config = array(
    'rest_root'    => esc_url_raw( untrailingslashit( rest_url( 'clinic/v1' ) ) ),
    'nonce'        => (string) wp_create_nonce( 'wp_rest' ),
    'clinics'      => $cpms_messages_clinics,
    'portal_url'   => esc_url_raw( \ClinicCore\Frontend\StaffPortalShell::portal_url() ),
);
$cpms_messages_config_json = wp_json_encode( $cpms_messages_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $cpms_messages_config_json ) ) {
    $cpms_messages_config_json = '{}';
}
?>
<style>
.cpms-msg { --cpms-primary: #0f5c56; --cpms-border: #e1e8ee; --cpms-muted: #5c6b76; --cpms-error: #a12828; max-width: 1120px; display: grid; gap: 12px; }
.cpms-msg * { box-sizing: border-box; }
.cpms-msg__panel { min-width: 0; padding: 14px; border: 1px solid var(--cpms-border); border-radius: 10px; background: #fff; }
.cpms-msg__heading { margin: 0 0 8px; font-size: 1.12rem; }
.cpms-msg__hint { margin: 0 0 10px; color: var(--cpms-muted); font-size: .88rem; }
.cpms-msg__status { min-height: 1.5em; margin: 0; overflow-wrap: anywhere; }
.cpms-msg__status[data-kind="error"] { color: var(--cpms-error); }
.cpms-msg__grid { display: grid; grid-template-columns: minmax(220px, 32%) minmax(0, 1fr); gap: 12px; align-items: start; }
.cpms-msg__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; max-height: 460px; overflow-y: auto; }
.cpms-msg__item { width: 100%; min-height: 46px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 10px; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; text-align: right; cursor: pointer; }
.cpms-msg__item[aria-current="true"] { border-color: var(--cpms-primary); background: #f0f8f7; }
.cpms-msg__item:focus-visible, .cpms-msg__btn:focus-visible, .cpms-msg__input:focus-visible, .cpms-msg__select:focus-visible, .cpms-msg__area:focus-visible { outline: 3px solid var(--cpms-primary); outline-offset: 2px; }
.cpms-msg__item-name { font-weight: 700; }
.cpms-msg__item-excerpt { color: var(--cpms-muted); font-size: .84rem; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cpms-msg__badge { min-width: 24px; padding: 2px 7px; border-radius: 999px; background: var(--cpms-primary); color: #fff; font-size: .78rem; text-align: center; }
.cpms-msg__badge[hidden] { display: none; }
.cpms-msg__conversation { display: grid; gap: 10px; min-width: 0; }
.cpms-msg__messages { display: grid; gap: 8px; min-height: 180px; max-height: 420px; overflow-y: auto; padding: 6px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fbfcfd; }
.cpms-msg__bubble { max-width: 88%; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 10px; background: #fff; overflow-wrap: anywhere; }
.cpms-msg__bubble[data-mine="true"] { background: #e8f4f2; border-color: #bcded9; margin-inline-start: auto; }
.cpms-msg__meta { display: block; margin-top: 4px; color: var(--cpms-muted); font-size: .76rem; }
.cpms-msg__form { display: grid; gap: 8px; }
.cpms-msg__area, .cpms-msg__input, .cpms-msg__select { width: 100%; min-height: 42px; padding: 8px 10px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; }
.cpms-msg__area { min-height: 76px; resize: vertical; }
.cpms-msg__row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; }
.cpms-msg__field { display: grid; gap: 4px; color: var(--cpms-muted); font-size: .86rem; }
.cpms-msg__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cpms-msg__btn { min-height: 40px; padding: 6px 14px; border: 1px solid var(--cpms-border); border-radius: 8px; background: #fff; color: inherit; font: inherit; cursor: pointer; }
.cpms-msg__btn--primary { background: var(--cpms-primary); border-color: var(--cpms-primary); color: #fff; }
.cpms-msg__btn[disabled] { opacity: .6; cursor: default; }
.cpms-msg__label { font-weight: 700; }
@media (max-width: 760px) {
    .cpms-msg { padding: 4px; }
    .cpms-msg__panel { padding: 10px; }
    .cpms-msg__grid { grid-template-columns: minmax(0, 1fr); }
    .cpms-msg__list { max-height: 220px; }
    .cpms-msg__bubble { max-width: 100%; }
    .cpms-msg__actions .cpms-msg__btn { flex: 1 1 auto; }
}
@media (max-width: 390px) {
    .cpms-msg__row { grid-template-columns: minmax(0, 1fr); }
    .cpms-msg__input, .cpms-msg__select, .cpms-msg__btn { min-height: 44px; }
}
@media (max-width: 340px) {
    .cpms-msg__panel { padding: 8px; }
    .cpms-msg__messages { padding: 4px; }
}
</style>
<main class="cpms-msg" data-role="messages-root" aria-labelledby="cpms-messages-title">
    <section class="cpms-msg__panel" aria-label="محدوده پیامرسانی">
        <h2 class="cpms-msg__heading" id="cpms-messages-title">پیام‌های داخلی پزشک و پذیرش <span class="cpms-msg__badge" data-role="messages-unread-total" hidden>0</span></h2>
        <p class="cpms-msg__hint">پیامها فقط در همان مطب و بین کارکنان مجاز نمایش داده میشوند. متن پیام ساده است و یادداشت بالینی یا پرونده پزشکی از این طریق ارسال نمیشود. همه پیامها نگهداری میشوند.</p>
        <div class="cpms-msg__row">
            <label class="cpms-msg__field">مطب
                <select class="cpms-msg__select" data-role="messages-clinic" aria-label="انتخاب مطب"></select>
            </label>
            <label class="cpms-msg__field">گفتگو با
                <select class="cpms-msg__select" data-role="messages-counterpart" aria-label="انتخاب همکار"></select>
            </label>
        </div>
        <div class="cpms-msg__row" style="margin-top:8px">
            <label class="cpms-msg__field">شناسه بیمار (اختیاری)
                <input class="cpms-msg__input" type="number" min="1" step="1" data-role="messages-patient-id" aria-label="شناسه بیمار">
            </label>
            <label class="cpms-msg__field">شناسه ویزیت (اختیاری)
                <input class="cpms-msg__input" type="number" min="1" step="1" data-role="messages-visit-id" aria-label="شناسه ویزیت">
            </label>
            <div class="cpms-msg__field" style="justify-content:end">
                <button type="button" class="cpms-msg__btn" data-role="messages-new">گفتگوی جدید</button>
            </div>
        </div>
    </section>
    <p class="cpms-msg__panel cpms-msg__status" data-role="messages-status" role="status" aria-live="polite">در حال دریافت گفتگوها…</p>
    <div class="cpms-msg__grid">
        <section class="cpms-msg__panel" aria-label="فهرست گفتگوها">
            <h3 class="cpms-msg__heading">گفتگوها</h3>
            <ul class="cpms-msg__list" data-role="messages-threads"></ul>
        </section>
        <section class="cpms-msg__panel cpms-msg__conversation" aria-label="متن گفتگو">
            <h3 class="cpms-msg__heading" data-role="messages-thread-title">یک گفتگو را انتخاب کنید</h3>
            <div class="cpms-msg__messages" data-role="messages-list" aria-live="polite"></div>
            <form class="cpms-msg__form" data-role="messages-form">
                <label class="cpms-msg__field">پیام جدید
                    <textarea class="cpms-msg__area" maxlength="2000" required data-role="messages-body" aria-label="متن پیام" placeholder="پیام خود را بنویسید…"></textarea>
                </label>
                <div class="cpms-msg__actions">
                    <button type="submit" class="cpms-msg__btn cpms-msg__btn--primary" data-role="messages-send">ارسال پیام</button>
                    <button type="button" class="cpms-msg__btn" data-role="messages-refresh">بروزرسانی</button>
                </div>
            </form>
        </section>
    </div>
</main>
<script type="application/json" id="cpms-messages-config"><?php echo $cpms_messages_config_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX flags by wp_json_encode. ?></script>
<script>
(function () {
    'use strict';
    var configNode = document.getElementById('cpms-messages-config');
    var config;
    try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
    var root = String(config.rest_root || '').replace(/\/$/, '');
    var state = { clinicId: 0, threadId: 0, lastId: 0, counterparts: [], sending: false };
    var statusNode = document.querySelector('[data-role="messages-status"]');
    var clinicSelect = document.querySelector('[data-role="messages-clinic"]');
    var counterpartSelect = document.querySelector('[data-role="messages-counterpart"]');
    var threadsNode = document.querySelector('[data-role="messages-threads"]');
    var listNode = document.querySelector('[data-role="messages-list"]');
    var titleNode = document.querySelector('[data-role="messages-thread-title"]');
    var badgeNode = document.querySelector('[data-role="messages-unread-total"]');
    var bodyNode = document.querySelector('[data-role="messages-body"]');
    var formNode = document.querySelector('[data-role="messages-form"]');

    function setStatus(message, kind) {
        statusNode.textContent = message || '';
        statusNode.dataset.kind = kind || '';
    }
    function node(tag, className, text) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = String(text);
        return element;
    }
    function headers() {
        var value = { 'X-WP-Nonce': String(config.nonce || ''), 'Accept': 'application/json' };
        if (state.clinicId) value['X-CPMS-Clinic-Id'] = String(state.clinicId);
        return value;
    }
    function request(path, method, body) {
        var options = { method: method || 'GET', headers: headers(), credentials: 'same-origin', cache: 'no-store' };
        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(root + path, options).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload || !payload.data) {
                    var error = new Error((payload && payload.message) || 'خطای ارتباط');
                    error.code = (payload && payload.code) || 'CLINIC_INTERNAL_ERROR';
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
    function renderCounterparts(data) {
        state.counterparts = Array.isArray(data.counterparts) ? data.counterparts : [];
        counterpartSelect.textContent = '';
        state.counterparts.forEach(function (person) {
            var option = node('option', '', person.display_name + ' — ' + (person.label || ''));
            option.value = String(person.wp_user_id);
            counterpartSelect.appendChild(option);
        });
        if (data.clinic_id) { state.clinicId = Number(data.clinic_id); clinicSelect.value = String(state.clinicId); }
        updateBadge(data.unread_total);
    }
    function updateBadge(count) {
        var total = Number(count || 0);
        badgeNode.hidden = total <= 0;
        badgeNode.textContent = String(total);
    }
    function renderThreads(data) {
        threadsNode.textContent = '';
        var threads = Array.isArray(data.threads) ? data.threads : [];
        updateBadge(data.unread_total);
        if (!threads.length) {
            threadsNode.appendChild(node('li', 'cpms-msg__hint', 'هنوز گفتگویی وجود ندارد.'));
            return;
        }
        threads.forEach(function (thread) {
            var li = document.createElement('li');
            var button = node('button', 'cpms-msg__item');
            button.type = 'button';
            if (Number(thread.id) === state.threadId) button.setAttribute('aria-current', 'true');
            var name = node('span', 'cpms-msg__item-name', (thread.counterpart && thread.counterpart.display_name) || 'همکار');
            var badge = node('span', 'cpms-msg__badge', thread.unread_count || 0);
            badge.hidden = !Number(thread.unread_count);
            var excerpt = node('span', 'cpms-msg__item-excerpt', thread.last_message_excerpt || 'بدون پیام');
            var time = node('span', 'cpms-msg__meta', thread.last_message_jalali || '');
            var top = node('span', 'cpms-msg__row');
            top.appendChild(name); top.appendChild(badge);
            button.appendChild(top); button.appendChild(excerpt); button.appendChild(time);
            button.addEventListener('click', function () { openThread(Number(thread.id), name.textContent); });
            li.appendChild(button);
            threadsNode.appendChild(li);
        });
    }
    function appendMessage(message) {
        var bubble = node('div', 'cpms-msg__bubble');
        bubble.dataset.mine = message.mine ? 'true' : 'false';
        bubble.appendChild(node('span', '', message.body));
        bubble.appendChild(node('small', 'cpms-msg__meta', (message.mine ? 'شما — ' : '') + (message.jalali_time || message.created_at || '')));
        listNode.appendChild(bubble);
        listNode.scrollTop = listNode.scrollHeight;
    }
    function loadThreads() {
        return request('/staff/portal/messages/threads?limit=50').then(renderThreads);
    }
    function loadMessages(since) {
        if (!state.threadId) return Promise.resolve();
        return request('/staff/portal/messages/threads/' + state.threadId + '/messages?since_id=' + Number(since || 0) + '&limit=100')
            .then(function (data) {
                (data.messages || []).forEach(appendMessage);
                state.lastId = Math.max(state.lastId, Number(data.last_id || 0));
                updateBadge(data.unread_count);
            });
    }
    function openThread(id, title) {
        state.threadId = Number(id);
        state.lastId = 0;
        listNode.textContent = '';
        titleNode.textContent = title || 'گفتگو';
        setStatus('در حال دریافت پیامها…', '');
        return loadMessages(0).then(function () {
            return request('/staff/portal/messages/threads/' + state.threadId + '/read', 'POST', {});
        }).then(function (data) {
            updateBadge(data.unread_total);
            setStatus('', '');
            return loadThreads();
        }).catch(function (error) { setStatus(error.message || 'دریافت گفتگو ناموفق بود.', 'error'); });
    }
    function newThread() {
        var recipient = Number(counterpartSelect.value || 0);
        if (!recipient) { setStatus('ابتدا یک همکار را انتخاب کنید.', 'error'); return; }
        var payload = { recipient_wp_user_id: recipient };
        var patientId = Number(document.querySelector('[data-role="messages-patient-id"]').value || 0);
        var visitId = Number(document.querySelector('[data-role="messages-visit-id"]').value || 0);
        if (patientId || visitId) {
            if (!patientId || !visitId) { setStatus('برای گفتگوی بیمارمحور، شناسه بیمار و ویزیت هر دو لازم است.', 'error'); return; }
            payload.patient_id = patientId;
            payload.visit_id = visitId;
        }
        request('/staff/portal/messages/threads', 'POST', payload).then(function (thread) {
            return loadThreads().then(function () { return openThread(thread.id, thread.counterpart && thread.counterpart.display_name); });
        }).catch(function (error) { setStatus(error.message || 'ایجاد گفتگو ناموفق بود.', 'error'); });
    }
    function send() {
        if (!state.threadId || state.sending) return;
        var text = bodyNode.value;
        if (!text.trim()) { setStatus('متن پیام الزامی است.', 'error'); return; }
        state.sending = true;
        request('/staff/portal/messages/threads/' + state.threadId + '/messages', 'POST', { body: text })
            .then(function (message) {
                bodyNode.value = '';
                appendMessage(message);
                state.lastId = Math.max(state.lastId, Number(message.id || 0));
                setStatus('پیام ارسال شد.', '');
                return loadThreads();
            })
            .catch(function (error) { setStatus(error.message || 'ارسال پیام ناموفق بود.', 'error'); })
            .then(function () { state.sending = false; });
    }
    function connect() {
        return request('/staff/portal/messages/context').then(renderCounterparts).then(loadThreads).then(function () {
            setStatus('', '');
        });
    }
    clinicSelect.addEventListener('change', function () {
        state.clinicId = Number(clinicSelect.value || 0);
        state.threadId = 0; state.lastId = 0; listNode.textContent = '';
        titleNode.textContent = 'یک گفتگو را انتخاب کنید';
        connect().catch(function (error) { setStatus(error.message || 'دریافت اطلاعات ناموفق بود.', 'error'); });
    });
    document.querySelector('[data-role="messages-new"]').addEventListener('click', newThread);
    document.querySelector('[data-role="messages-refresh"]').addEventListener('click', function () {
        loadThreads().then(function () { return loadMessages(state.lastId); }).catch(function (error) { setStatus(error.message, 'error'); });
    });
    formNode.addEventListener('submit', function (event) { event.preventDefault(); send(); });
    renderClinics();
    connect().catch(function (error) { setStatus(error.message || 'دریافت اطلاعات ناموفق بود.', 'error'); });
    window.setInterval(function () {
        if (document.visibilityState !== 'visible') return;
        if (state.threadId) {
            loadMessages(state.lastId).catch(function () {});
        } else {
            loadThreads().catch(function () {});
        }
    }, 8000);
}());
</script>
</div>

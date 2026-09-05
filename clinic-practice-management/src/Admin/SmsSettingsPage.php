<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Auth\RolesAndCapabilities;

/**
 * صفحه تنظیمات پیامک — Settings → SMS/پیامک (ADR-0025، الزام §26).
 *
 * - فقط برای کاربر با cpms_sms_config
 * - Assets فقط روی این صفحه (Baseline §2)
 * - Credential ذخیره‌شده فقط به‌صورت Placeholder (••••••••abcd) — هرگز plaintext
 */
final class SmsSettingsPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            'تنظیمات پیامک',
            'پیامک',
            RolesAndCapabilities::SMS_CONFIG,
            'cpms-sms',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can(RolesAndCapabilities::SMS_CONFIG)) {
            wp_die('دسترسی ندارید', 403);
        }

        $config = [
            'rest_url' => esc_url_raw(rest_url('clinic/v1/sms/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ];
        ?>
<div class="wrap" dir="rtl" id="cpms-sms-wrap">
    <h1>تنظیمات پیامک</h1>

    <div class="notice notice-info" id="cpms-sms-state-banner" style="display:none"></div>

    <div class="card" style="max-width:860px">
        <h2>اتصال</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">وضعیت</th>
                <td><span id="cpms-sms-state">در حال بارگذاری…</span></td>
            </tr>
            <tr>
                <th scope="row">ارائه‌دهنده (Provider)</th>
                <td><select id="cpms-sms-provider"></select></td>
            </tr>
            <tr>
                <th scope="row">روش اتصال</th>
                <td><select id="cpms-sms-auth-method"></select></td>
            </tr>
            <tr id="cpms-sms-auth-fields-row">
                <th scope="row">اطلاعات اتصال</th>
                <td id="cpms-sms-auth-fields"></td>
            </tr>
            <tr>
                <th scope="row">شماره ارسال (Sender)</th>
                <td><input type="text" id="cpms-sms-sender" class="regular-text" dir="ltr" placeholder="1000…"></td>
            </tr>
            <tr>
                <th scope="row"></th>
                <td>
                    <button type="button" class="button" id="cpms-sms-test-conn">تست اتصال</button>
                    <span id="cpms-sms-test-conn-result"></span>
                </td>
            </tr>
        </table>

        <h3>Generic API (برای Provider = Generic)</h3>
        <table class="form-table" role="presentation" id="cpms-sms-generic-table">
            <tr>
                <th scope="row">API Endpoint</th>
                <td><input type="text" id="cpms-sms-generic-endpoint" class="large-text code" dir="ltr" placeholder="https://panel.example.com/api/v1/send-sms"></td>
            </tr>
            <tr>
                <th scope="row">Request Mapping (JSON)</th>
                <td>
                    <textarea id="cpms-sms-generic-request" rows="4" class="large-text code" dir="ltr"
                              placeholder='{"to": "{mobile}", "message": "{message}"}'></textarea>
                    <p class="description">Placeholderها: <code dir="ltr">{mobile} {message} {template_id} {vars} {sender}</code> — بدون Code.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Response Mapping</th>
                <td>
                    <table class="widefat" style="max-width:520px">
                        <tr><td><input type="text" id="cpms-sms-r-success" placeholder="success_field" dir="ltr"></td>
                            <td><input type="text" id="cpms-sms-r-values" placeholder="success_values: sent,1" dir="ltr"></td></tr>
                        <tr><td><input type="text" id="cpms-sms-r-id" placeholder="id_field" dir="ltr"></td>
                            <td><input type="text" id="cpms-sms-r-error" placeholder="error_field" dir="ltr"></td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="card" style="max-width:860px; margin-top:12px">
        <h2>الگوهای پیامک (Templates)</h2>
        <p class="description">اگر پنل شما Pattern/Template/Verify دارد، Template ID را وارد کنید. در غیر این صورت متن پیش‌فرض داخلی استفاده می‌شود.</p>
        <div id="cpms-sms-templates"></div>
    </div>

    <div class="card" style="max-width:860px; margin-top:12px">
        <h2>تست و اعتبار</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">پیامک آزمایشی</th>
                <td>
                    <input type="text" id="cpms-sms-test-mobile" class="regular-text" dir="ltr" placeholder="09xxxxxxxxx">
                    <input type="text" id="cpms-sms-test-message" class="regular-text" value="پیام آزمایشی سیستم مطب">
                    <button type="button" class="button" id="cpms-sms-test-send">Send Test</button>
                    <span id="cpms-sms-test-send-result"></span>
                </td>
            </tr>
            <tr>
                <th scope="row">اعتبار پنل</th>
                <td><span id="cpms-sms-balance">—</span></td>
            </tr>
        </table>
    </div>

    <div class="card" style="max-width:980px; margin-top:12px">
        <h2>گزارش ارسال (Log)</h2>
        <table class="widefat striped" id="cpms-sms-logs" dir="ltr">
            <thead>
            <tr>
                <th>ID</th><th>Event</th><th>Recipient</th><th>Message</th><th>Provider</th>
                <th>Status</th><th>Attempts</th><th>Provider Msg ID</th><th>Failure</th><th>Created (UTC)</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
        <p>
            <button type="button" class="button" id="cpms-sms-logs-refresh">تازه‌سازی</button>
            <span class="description" id="cpms-sms-logs-count"></span>
        </p>
    </div>

    <p style="max-width:860px">
        <button type="button" class="button button-primary" id="cpms-sms-save">ذخیره تنظیمات</button>
        <span id="cpms-sms-save-result"></span>
    </p>
</div>
<script>
window.CPMS_SMS = <?php echo wp_json_encode($config); ?>;
</script>
<script>
(function () {
    'use strict';
    var CFG = window.CPMS_SMS;
    var state = { providers: [], status: null, templates: null };

    function api(method, path, body) {
        var headers = { 'X-WP-Nonce': CFG.nonce };
        if (body) headers['Content-Type'] = 'application/json';
        return fetch(CFG.rest_url + path, {
            method: method,
            credentials: 'same-origin',
            headers: headers,
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) {
                    var msg = (data && data.message) ? data.message : 'خطا';
                    var err = new Error(msg);
                    err.data = data;
                    throw err;
                }
                return data.data;
            });
        });
    }

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function result(id, text, ok) {
        var node = el(id);
        node.textContent = text || '';
        node.style.color = ok === null ? '' : (ok ? 'green' : '#b32d2e');
    }

    function loadProviders() {
        return api('GET', 'providers').then(function (list) {
            state.providers = list;
            var sel = el('cpms-sms-provider');
            sel.innerHTML = list.map(function (p) {
                return '<option value="' + esc(p.id) + '">' + esc(p.label) + '</option>';
            }).join('');
            sel.onchange = function () { renderAuth(); renderGeneric(); };
            renderAuth();
            renderGeneric();
        });
    }

    function currentProvider() {
        var id = el('cpms-sms-provider').value;
        for (var i = 0; i < state.providers.length; i++) {
            if (state.providers[i].id === id) return state.providers[i];
        }
        return null;
    }

    function renderAuth() {
        var p = currentProvider();
        var methodSel = el('cpms-sms-auth-method');
        var fieldsBox = el('cpms-sms-auth-fields');
        el('cpms-sms-auth-fields-row').style.display = '';
        if (!p || !p.auth_methods || p.auth_methods.length === 0) {
            methodSel.innerHTML = '<option value="">— (بدون نیاز)</option>';
            fieldsBox.innerHTML = '<span class="description">این Provider نیاز به اطلاعات اتصال ندارد.</span>';
            methodSel.disabled = true;
            return;
        }
        methodSel.disabled = false;
        methodSel.innerHTML = p.auth_methods.map(function (m) {
            var labels = { api_key: 'API Key', bearer: 'Bearer Token', username_password: 'Username + Password' };
            return '<option value="' + esc(m) + '">' + esc(labels[m] || m) + '</option>';
        }).join('');
        methodSel.onchange = renderAuthFields;
        renderAuthFields();
    }

    function renderAuthFields() {
        var p = currentProvider();
        var method = el('cpms-sms-auth-method').value;
        var fieldsBox = el('cpms-sms-auth-fields');
        if (!p) { fieldsBox.innerHTML = ''; return; }
        var map = {
            api_key: ['api_key'],
            bearer: ['bearer_token'],
            username_password: ['username', 'password']
        };
        var keys = map[method] || [];
        var saved = (state.status && state.status.credentials) || {};
        var html = keys.map(function (k) {
            var spec = (p.auth_fields || {})[k] || {};
            var placeholder = saved[k] ? ('' + saved[k] + ' (برای تغییر مقدار جدید را وارد کنید)') : esc(spec.label || k);
            var type = spec.secret ? 'password' : 'text';
            return '<label><span>' + esc(spec.label || k) + ' ' +
                (spec.required ? '(الزامی)' : '') + '</span><br>' +
                '<input type="' + type + '" class="regular-text" dir="ltr" data-field="' + esc(k) + '" ' +
                'placeholder="' + placeholder + '" value=""></label><br><br>';
        }).join('');
        html += '<label><input type="checkbox" id="cpms-sms-clear-creds"> حذف صریح اطلاعات ذخیره‌شده</label>';
        fieldsBox.innerHTML = html || '<span class="description">فیلدی لازم نیست.</span>';
    }

    function renderGeneric() {
        var p = currentProvider();
        el('cpms-sms-generic-table').style.display = (p && p.id === 'generic_api') ? '' : 'none';
    }

    function loadStatus() {
        return api('GET', 'status').then(function (s) {
            state.status = s;
            el('cpms-sms-sender').value = s.sender || '';
            var providerSel = el('cpms-sms-provider');
            for (var i = 0; i < providerSel.options.length; i++) {
                if (providerSel.options[i].value === s.provider) {
                    providerSel.selectedIndex = i;
                    break;
                }
            }
            renderAuth();
            renderGeneric();
            renderStateBanner();
            return s;
        });
    }

    function renderStateBanner() {
        var s = state.status;
        if (!s) return;
        var banner = el('cpms-sms-state-banner');
        var labels = {
            NOT_CONFIGURED: ['تنظیم نشده — Provider و اطلاعات اتصال را وارد کنید.', 'info'],
            CONFIGURED: ['● تنظیم شد (تست اتصال انجام نشده).', 'info'],
            VERIFIED: ['● متصل — اتصال اخیراً تست و تأیید شد.', 'success'],
            ERROR: ['● خطا در اتصال اخیر — جزئیات در Log.', 'error']
        };
        var item = labels[s.state] || labels.NOT_CONFIGURED;
        banner.className = 'notice notice-' + item[1];
        banner.style.display = '';
        banner.innerHTML = '<p>' + esc(item[0]) + '</p>';
        el('cpms-sms-state').textContent = s.state;
        if (s.last_test && s.last_test.message) {
            el('cpms-sms-test-conn-result').textContent = ' (آخرین تست: ' + s.last_test.message + ')';
        }
    }

    function loadTemplates() {
        return api('GET', 'templates').then(function (t) {
            state.templates = t;
            renderTemplates();
        });
    }

    function renderTemplates() {
        var t = state.templates;
        if (!t) return;
        var box = el('cpms-sms-templates');
        if (!t.provider_supports_template) {
            box.innerHTML = '<p class="description">Provider فعلی از Template/Pattern پشتیبانی نمی‌کند — متن‌های پیش‌فرض داخلی استفاده می‌شوند.</p>';
            return;
        }
        var html = '';
        Object.keys(t.events).forEach(function (id) {
            var e = t.events[id];
            html += '<tr><th scope="row" style="width:220px">' + esc(e.label) + '</th><td>' +
                '<input type="text" class="regular-text" dir="ltr" data-event="' + esc(id) + '" placeholder="Template ID در پنل" value="' + esc(e.template_id) + '"> ' +
                '<button type="button" class="button" data-test-event="' + esc(id) + '">تست الگو</button> ' +
                '<span class="description" data-tmpl-status="' + esc(id) + '"></span><br>' +
                '<span class="description">متن پیش‌فرض: ' + esc(e.default_text) + '</span></td></tr>';
        });
        box.innerHTML = '<table class="form-table" role="presentation">' + html + '</table>';
        box.querySelectorAll('[data-test-event]').forEach(function (btn) {
            btn.onclick = function () { testTemplate(btn.getAttribute('data-test-event')); };
        });
    }

    function collectTemplateVars(eventId) {
        var e = state.templates.events[eventId];
        var mobile = prompt('شماره موبایل تست:');
        if (!mobile) return;
        var vars = {};
        e.required.forEach(function (v) {
            if (v === 'otp_code') { vars[v] = '123456'; return; }
            var val = prompt('مقدار متغیر ' + (e.variable_labels[v] || v) + ' (' + v + '):');
            if (val === null) throw new Error('cancel');
            vars[v] = val || '';
        });
        e.variables.forEach(function (v) {
            if (!vars.hasOwnProperty(v) && v.indexOf('name') >= 0 || (v === 'clinic_name')) {
                var val2 = prompt('مقدار متغیر ' + (e.variable_labels[v] || v) + ' (' + v + ') [اختیاری]:');
                if (val2 !== null) vars[v] = val2;
            }
        });
        return { mobile: mobile, vars: vars };
    }

    function testTemplate(eventId) {
        var input;
        try { input = collectTemplateVars(eventId); } catch (e) { return; }
        api('POST', 'templates/test', { event: eventId, mobile: input.mobile, vars: input.vars })
            .then(function (r) {
                el('cpms-sms-test-send-result').textContent = '';
                var span = document.querySelector('[data-tmpl-status="' + eventId + '"]');
                span.textContent = r.status === 'SENT' ? '✓ ارسال شد' : ('نتیجه: ' + r.status);
                span.style.color = r.status === 'SENT' ? 'green' : '#b32d2e';
            })
            .catch(function (err) { alert('خطا: ' + err.message); });
    }

    function loadLogs() {
        api('GET', 'logs?per_page=20').then(function (r) {
            var tbody = el('cpms-sms-logs').querySelector('tbody');
            tbody.innerHTML = r.items.map(function (m) {
                return '<tr><td>' + m.id + '</td><td>' + esc(m.event) + '</td><td>' + esc(m.recipient) +
                    '</td><td>' + esc(m.message) + '</td><td>' + esc(m.provider) + '</td><td>' + esc(m.status) +
                    '</td><td>' + m.attempts + '</td><td>' + esc(m.provider_msg_id || '') + '</td><td>' +
                    esc(m.failure_code || '') + '</td><td>' + esc(m.created_at) + '</td></tr>';
            }).join('') || '<tr><td colspan="10">موردی نیست.</td></tr>';
            el('cpms-sms-logs-count').textContent = 'مجموع: ' + r.total;
        }).catch(function () { });
    }

    function loadBalance() {
        api('GET', 'balance').then(function (b) {
            el('cpms-sms-balance').textContent = b ? ('اعتبار پنل: ' + b.balance + ' ' + b.currency) : '— (پشتیبانی نمی‌شود)';
        }).catch(function () { });
    }

    // ===== Actions =====
    el('cpms-sms-test-conn').onclick = function () {
        var body = {
            provider: el('cpms-sms-provider').value,
            auth_method: el('cpms-sms-auth-method').value,
            credentials: collectCreds()
        };
        result('cpms-sms-test-conn-result', 'در حال تست…');
        api('POST', 'test-connection', body).then(function (r) {
            result('cpms-sms-test-conn-result', r.message, r.ok);
            loadStatus();
        }).catch(function (err) { result('cpms-sms-test-conn-result', 'خطا: ' + err.message, false); });
    };

    el('cpms-sms-test-send').onclick = function () {
        var body = { mobile: el('cpms-sms-test-mobile').value, message: el('cpms-sms-test-message').value };
        result('cpms-sms-test-send-result', 'در حال ارسال…');
        api('POST', 'test-send', body).then(function (r) {
            result('cpms-sms-test-send-result', r.message + ' (' + r.status + (r.provider_msg_id ? ' — ref: ' + r.provider_msg_id : '') + ')', r.status === 'SENT');
            loadLogs();
        }).catch(function (err) { result('cpms-sms-test-send-result', 'خطا: ' + err.message, false); });
    };

    el('cpms-sms-logs-refresh').onclick = loadLogs;

    el('cpms-sms-save').onclick = function () {
        var body = {
            provider: el('cpms-sms-provider').value,
            auth_method: el('cpms-sms-auth-method').value,
            credentials: collectCreds(),
            sender: el('cpms-sms-sender').value,
            advanced: {
                timeout_sec: 5,
                retry_count: 3
            },
            generic: {
                endpoint: el('cpms-sms-generic-endpoint').value,
                request_json: el('cpms-sms-generic-request').value,
                response: {
                    success_field: el('cpms-sms-r-success').value,
                    success_values: el('cpms-sms-r-values').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean),
                    id_field: el('cpms-sms-r-id').value,
                    error_field: el('cpms-sms-r-error').value
                }
            }
        };
        // Templateهای ذخیره‌شده
        document.querySelectorAll('[data-event]').forEach(function (input) {
            body['template_' + input.getAttribute('data-event')] = input.value;
        });
        result('cpms-sms-save-result', 'در حال ذخیره…');
        var chain = api('POST', 'settings', body);
        document.querySelectorAll('[data-event]').forEach(function (input) {
            var eventId = input.getAttribute('data-event');
            var value = (input.value || '').trim();
            chain = chain.then(function () {
                var prev = state.templates && state.templates.events[eventId];
                if (prev && (prev.template_id || '') !== value) {
                    return api('POST', 'templates', { event: eventId, template_id: value });
                }
            });
        });
        chain.then(function () {
            result('cpms-sms-save-result', '✓ ذخیره شد.', true);
            return loadStatus().then(loadTemplates);
        }).catch(function (err) { result('cpms-sms-save-result', 'خطا: ' + err.message, false); });
    };

    function collectCreds() {
        var out = {};
        var clear = el('cpms-sms-clear-creds');
        document.querySelectorAll('[data-field]').forEach(function (input) {
            var v = (input.value || '').trim();
            out[input.getAttribute('data-field')] = v !== '' ? v : (clear && clear.checked ? '__CLEAR__' : '');
        });
        return out;
    }

    // ===== Init =====
    Promise.all([loadProviders(), loadStatus(), loadTemplates()])
        .then(function () { loadLogs(); loadBalance(); })
        .catch(function (err) { alert('خطا در بارگذاری تنظیمات پیامک: ' + err.message); });
})();
</script>
        <?php
    }
}

/**
 * Real wp-admin inline renderers and the asynchronous reopen action.
 * No npm dependencies, network, browser, or edits to legacy lock tests.
 */
'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vm = require('vm');
var root = path.resolve(__dirname, '..', '..');
var text = require(path.join(root, 'assets/js/cpms-ui-text.js'));

function read(relative) {
  return fs.readFileSync(path.join(root, relative), 'utf8');
}

function sourceFunction(source, name) {
  var start = source.indexOf('    function ' + name + '(');
  var end = source.indexOf('\n    function ', start + 1);
  assert.ok(start >= 0 && end > start, 'extract ' + name);
  return source.slice(start, end);
}

function deferred() {
  var resolve;
  var reject;
  var promise = new Promise(function (yes, no) { resolve = yes; reject = no; });
  return { promise: promise, resolve: resolve, reject: reject };
}

function response(status, body) {
  return { status: status, json: function () { return Promise.resolve(body || {}); } };
}

function flush() {
  return new Promise(function (resolve) { setImmediate(resolve); });
}

class FixedDate extends Date {
  static now() { return Date.parse('2026-03-21T02:00:00Z'); }
}

var dashboardSource = read('src/Admin/DoctorDashboardPage.php');
assert.match(dashboardSource,
  /'clinician_name'\s*=>\s*DoctorDisplayName::with_title_or_fallback\(\s*\$clinician\['name'\]\s*\)/,
  'the config is normalized on the PHP side');
var begin = dashboardSource.indexOf('<script>\n(function () {');
var end = dashboardSource.indexOf('\n</script>', begin);
assert.ok(begin >= 0 && end > begin, 'real dashboard IIFE exists');
var dashboardJs = dashboardSource.slice(begin + '<script>\n'.length, end);
var marker = '    // ================= Wiring =================';
assert.ok(dashboardJs.includes(marker));
dashboardJs = dashboardJs.replace(marker,
  '    window.__test = {state: state, renderQueue: renderQueue, renderVisit: renderVisit, ' +
  'waitingMinutes: waitingMinutes};\n' + marker);

function makeDashboard(useSharedText) {
  var nodes = {};
  var listeners = {};
  var prompts = [];
  var requests = [];
  function node(id) {
    if (!nodes[id]) { nodes[id] = { style: {}, textContent: '', innerHTML: '' }; }
    return nodes[id];
  }
  var win = {
    CPMS_DOC: {
      clinician_name: 'دکتر رضایی', clinician_id: 9, visit_id: 0,
      rest_url: '/wp-json/clinic/v1/', nonce: 'nonce', base_url: '/admin',
      print_url: '/print', can_call: true, can_start: true, can_upload: true
    },
    scrollTo: function () {},
    CpmsDialog: { prompt: function (message, options) {
      var pending = deferred();
      prompts.push({ message: message, options: options, pending: pending });
      return pending.promise;
    } }
  };
  if (useSharedText) { win.CpmsText = text; }
  var document = {
    readyState: 'loading', hidden: false,
    getElementById: node,
    addEventListener: function (event, callback) { listeners[event] = callback; },
    querySelector: function () { return null; }
  };
  var context = {
    window: win, document: document, Date: FixedDate, Promise: Promise,
    setTimeout: function () { return 1; }, clearTimeout: function () {},
    setInterval: function () { return 1; },
    fetch: function (url, options) {
      var pending = deferred();
      requests.push({ url: url, options: options, pending: pending });
      return pending.promise;
    }
  };
  vm.createContext(context);
  vm.runInContext(dashboardJs, context, { filename: 'DoctorDashboardPage.inline.js' });
  return {
    window: win, nodes: nodes, node: node, prompts: prompts, requests: requests,
    test: win.__test,
    click: function (button) {
      listeners.click({ target: { closest: function () { return button; } },
        preventDefault: function () {} });
    }
  };
}

function button(action, id) {
  return { disabled: false, tagName: 'BUTTON', getAttribute: function (name) {
    return name === 'data-act' ? action : name === 'data-id' ? String(id) : null;
  } };
}

function posts(env) {
  return env.requests.filter(function (request) { return request.options.method === 'POST'; });
}

function testDoctorRendering(shared) {
  var env = makeDashboard(shared);
  var queue = {
    date: '2026-03-21', jalali: '1405/01/01',
    stats: { total: 12, waiting: 7, called: 0, in_consultation: 3, checked_out: 1 },
    queue: [
      { id: 31, clinician_id: 9, patient_name: 'سارا', status: 'waiting',
        source: 'walk_in', waiting_since: '2026-03-21 00:35:00' },
      { id: 32, clinician_id: 8, patient_name: 'Another', status: 'waiting' }
    ]
  };
  var before = JSON.stringify(queue);
  env.test.state.queue = queue;
  env.test.renderQueue();
  assert.strictEqual(env.node('cpms-doc-header').textContent, 'دکتر رضایی — ۱۴۰۵/۰۱/۰۱');
  assert.ok(env.node('cpms-doc-stats').innerHTML.includes('<b>۱۲</b><span>مراجعه امروز'));
  assert.ok(env.node('cpms-doc-stats').innerHTML.includes('<b>۷</b><span>در انتظار'));
  assert.ok(env.node('cpms-doc-stats').innerHTML.includes('<b>۰</b>'));
  assert.ok(env.node('cpms-doc-tbody').innerHTML.includes('<tr><td>۱</td>'));
  assert.ok(env.node('cpms-doc-tbody').innerHTML.includes('۱س ۲۵م'));
  assert.ok(!env.node('cpms-doc-tbody').innerHTML.includes('Another'));
  assert.strictEqual(env.test.waitingMinutes('2026-03-21 01:48:00'), '۱۲ دقیقه');
  assert.strictEqual(env.test.waitingMinutes('invalid'), '—');
  assert.strictEqual(JSON.stringify(queue), before, 'rendering does not mutate endpoint data');
  [null, '', '  ', undefined, { raw: '2026-03-21' }].forEach(function (missing) {
    queue.jalali = missing;
    env.test.renderQueue();
    assert.strictEqual(env.node('cpms-doc-header').textContent, 'دکتر رضایی — —');
    assert.ok(!env.node('cpms-doc-header').textContent.includes('2026'));
  });
  env.window.CPMS_DOC.clinician_name = '';
  env.test.renderQueue();
  assert.strictEqual(env.node('cpms-doc-header').textContent, 'پزشک — —');

  var record = {
    patient: { full_name: 'سارا', mrn: 'MR-12', age: 34, gender: 'female',
      allergies: { medication: [], other: [] } },
    visit: { id: 31, status: 'consultation_completed' },
    notes: [{ id: 4, category: 'history', version: 3, versions: 12,
      created_at_jalali: '۱۴۰۵/۰۱/۰۱', content_text: 'یادداشت' }],
    prescriptions: [{ id: 5, prescription_number: 'RX-34', status: 'finalized',
      created_at_jalali: '۱۴۰۵/۰۱/۰۱', items: [{ generic_name: 'دارو', dose: 'x',
        frequency: 'y', duration_days: 21 }] }],
    follow_ups: [{ id: 6, interval_days: 14, reason: 'پیگیری', status: 'open' }],
    files: [{ id: 7, original_filename: 'report-9.pdf', category: 'document',
      file_size: 4096, visibility: 'patient_visible' }]
  };
  var recordBefore = JSON.stringify(record);
  env.test.state.record = record;
  env.test.renderVisit();
  var html = env.node('cpms-doc-visit-view').innerHTML;
  ['نسخه ۳ (۱۲ نسخه)', 'بازه: ۱۴ روز', '۲۱ روز', 'RX-۳۴', 'MR-۱۲', '۳۴ سال', '۴KB']
    .forEach(function (expected) { assert.ok(html.includes(expected), expected); });
  assert.ok(html.includes('report-9.pdf'), 'file names remain unchanged');
  assert.ok(html.includes('data-id="4"'), 'machine ids remain ASCII');
  assert.strictEqual(JSON.stringify(record), recordBefore, 'record is unchanged');
}

async function testReopen() {
  var env = makeDashboard(true);
  env.window.CPMS_DOC.visit_id = 91;
  var cancelled = button('reopen', 91);
  env.click(cancelled);
  assert.strictEqual(cancelled.disabled, true, 'disabled while the prompt is pending');
  env.click(cancelled);
  assert.strictEqual(env.prompts.length, 1, 'repeat activation cannot open another prompt');
  assert.strictEqual(env.prompts[0].message, 'دلیل بازگشایی ویزیت (الزامی — در Audit ثبت می‌شود):');
  assert.strictEqual(env.prompts[0].options.required, true);
  assert.strictEqual(env.prompts[0].options.requiredMessage, 'دلیل بازگشایی ویزیت الزامی است.');
  assert.strictEqual(posts(env).length, 0);
  env.prompts[0].pending.resolve(null);
  await flush();
  assert.strictEqual(cancelled.disabled, false, 'cancel releases the button');
  assert.strictEqual(posts(env).length, 0, 'cancel never sends a POST');

  var success = button('reopen', 91);
  env.click(success);
  env.prompts[1].pending.resolve('اصلاح 12');
  await flush();
  assert.strictEqual(posts(env).length, 1);
  assert.strictEqual(posts(env)[0].url, '/wp-json/clinic/v1/visits/91/reopen');
  assert.deepStrictEqual(JSON.parse(posts(env)[0].options.body), { reason: 'اصلاح 12' });
  assert.strictEqual(success.disabled, true, 'disabled throughout the POST');
  var jsonPending = deferred();
  posts(env)[0].pending.resolve({ status: 200, json: function () { return jsonPending.promise; } });
  await flush();
  assert.strictEqual(success.disabled, true, 'not released before response parsing settles');
  jsonPending.resolve({});
  await flush();
  assert.strictEqual(success.disabled, false, 'success releases');
  assert.strictEqual(env.node('cpms-doc-notice').textContent, 'انجام شد.');

  var failure = button('reopen', 92);
  env.click(failure);
  env.prompts[2].pending.resolve('retry');
  await flush();
  assert.strictEqual(failure.disabled, true);
  posts(env)[1].pending.reject(new Error('network'));
  await flush();
  assert.strictEqual(failure.disabled, false, 'network rejection releases');

  var promptFailure = button('reopen', 93);
  env.click(promptFailure);
  env.prompts[3].pending.reject(new Error('dialog'));
  await flush();
  assert.strictEqual(promptFailure.disabled, false, 'dialog rejection releases without a POST');
  assert.strictEqual(posts(env).length, 2);
  env.window.CpmsDialog.prompt = function () { throw new Error('dialog sync'); };
  var syncFailure = button('reopen', 94);
  assert.throws(function () { env.click(syncFailure); }, /dialog sync/);
  assert.strictEqual(syncFailure.disabled, false, 'synchronous dialog errors also release');
}

async function testIndependentReopen() {
  var env = makeDashboard(true);
  env.window.CPMS_DOC.visit_id = 91;
  var first = button('reopen', 91);
  var second = button('reopen', 92);
  env.click(first);
  env.click(second);
  env.prompts[0].pending.resolve('first');
  env.prompts[1].pending.resolve('second');
  await flush();
  assert.strictEqual(posts(env).length, 2);
  assert.ok(first.disabled && second.disabled);
  posts(env)[1].pending.resolve(response(400, { data: { message: 'server error' } }));
  await flush();
  assert.strictEqual(second.disabled, false, 'second releases on its own response');
  assert.strictEqual(first.disabled, true, 'first remains locked');
  assert.strictEqual(env.node('cpms-doc-notice').textContent, 'server error');
  posts(env)[0].pending.reject(new Error('network'));
  await flush();
  assert.strictEqual(first.disabled, false);

  // The special reopen flow must also stay independent of an ordinary write.
  var ordinary = button('complete', 91);
  var reopen = button('reopen', 92);
  env.click(ordinary);
  env.click(reopen);
  env.prompts[2].pending.resolve('third');
  await flush();
  assert.ok(ordinary.disabled && reopen.disabled);
  posts(env)[3].pending.resolve(response(400, { data: { message: 'reopen failed' } }));
  await flush();
  assert.strictEqual(reopen.disabled, false);
  assert.strictEqual(ordinary.disabled, true);
  posts(env)[2].pending.resolve(response(422, { data: { message: 'complete failed' } }));
  await flush();
  assert.strictEqual(ordinary.disabled, false);
}

function testSecretaryRendering(shared) {
  var src = read('src/Admin/SecretaryQueuePage.php');
  var nodes = {};
  var state = { today: { date: '2026-03-21', jalali: '1405/01/01',
    stats: { waiting: 12, total: 20 }, queue: [{ id: 123, patient_name: 'سارا',
      clinician_name: null, source: 'walk_in', status: 'waiting',
      waiting_since: '2026-03-21 01:48:00' }] } };
  var original = JSON.stringify(state.today);
  var context = {
    state: state, window: shared ? { CpmsText: text } : {}, Date: FixedDate,
    CFG: {}, STATUS_LABELS: { waiting: 'در صف' }, EXPRESS: 'نوبت فوری',
    document: { getElementById: function (id) { return nodes[id] || (nodes[id] = {}); } }
  };
  vm.createContext(context);
  ['renderStats', 'fmtTime', 'waitMin', 'actionsFor', 'renderQueue', 'esc', 'displayDigits']
    .forEach(function (name) { vm.runInContext(sourceFunction(src, name), context); });
  context.renderStats();
  context.renderQueue();
  assert.strictEqual(nodes['cpms-q-clock'].textContent, '۱۴۰۵/۰۱/۰۱');
  assert.ok(nodes['cpms-q-stats'].innerHTML.includes('<b>۱۲</b><span>در صف'));
  assert.ok(nodes['cpms-q-stats'].innerHTML.includes('<b>۲۰</b><span>کل امروز'));
  assert.ok(nodes['cpms-q-tbody'].innerHTML.includes('<td>۱</td>'));
  assert.ok(nodes['cpms-q-tbody'].innerHTML.includes('۱۲ دقیقه'));
  assert.ok(nodes['cpms-q-tbody'].innerHTML.includes('<td>—</td>'));
  assert.ok(nodes['cpms-q-tbody'].innerHTML.includes('data-id="123"'));
  assert.strictEqual(context.waitMin('invalid'), '—');
  assert.strictEqual(JSON.stringify(state.today), original);
  state.today.jalali = null;
  context.renderStats();
  assert.strictEqual(nodes['cpms-q-clock'].textContent, '—');
}

async function testSmsRendering(shared) {
  var src = read('src/Admin/SmsSettingsPage.php');
  var tbody = { innerHTML: '' };
  var count = { textContent: '' };
  var data = { total: 21, items: [{ id: 42, attempts: 3, event: 'event', recipient: 'x',
    message: 'text', provider: 'provider', status: 'sent', provider_msg_id: 'msg-98' }] };
  var before = JSON.stringify(data);
  var context = {
    window: shared ? { CpmsText: text } : {},
    api: function (method, route) {
      assert.strictEqual(method, 'GET');
      assert.strictEqual(route, 'logs?per_page=20');
      return Promise.resolve(data);
    },
    el: function (id) {
      if (id === 'cpms-sms-logs') { return { querySelector: function () { return tbody; } }; }
      if (id === 'cpms-sms-logs-count') { return count; }
      throw new Error('unexpected id: ' + id);
    }
  };
  vm.createContext(context);
  ['esc', 'displayDigits', 'smsTime', 'loadLogs']
    .forEach(function (name) { vm.runInContext(sourceFunction(src, name), context); });
  context.loadLogs();
  await flush();
  assert.strictEqual(count.textContent, 'مجموع: ۲۱');
  assert.ok(tbody.innerHTML.includes('<td>۳</td>'));
  assert.ok(tbody.innerHTML.includes('<td>42</td>'));
  assert.ok(tbody.innerHTML.includes('msg-98'));
  assert.strictEqual(JSON.stringify(data), before);
}

(async function () {
  [true, false].forEach(testDoctorRendering);
  [true, false].forEach(testSecretaryRendering);
  await testSmsRendering(true);
  await testSmsRendering(false);
  await testReopen();
  await testIndependentReopen();
  console.log('OK: dashboard/queue/SMS presentation and independent reopen flows');
})().catch(function (error) {
  console.error(error && error.stack ? error.stack : error);
  process.exitCode = 1;
});

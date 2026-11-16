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
var jalaliContext = { window: {}, document: { readyState: 'loading', addEventListener: function () {} } };
vm.createContext(jalaliContext);
vm.runInContext(read('assets/js/cpms-jalali-date.js'), jalaliContext, { filename: 'cpms-jalali-date.js' });
var shippedJalali = jalaliContext.window.CpmsJalali;

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
var opener = '<script>\n(function () {';
var iifeCount = 0;
var pos = dashboardSource.indexOf(opener);
while (pos >= 0) { iifeCount++; pos = dashboardSource.indexOf(opener, pos + 1); }
assert.strictEqual(iifeCount, 1, 'exactly one dashboard IIFE block (found ' + iifeCount + ')');
var begin = dashboardSource.indexOf(opener);
var end = dashboardSource.indexOf('\n</script>', begin);
assert.ok(end > begin, 'real dashboard IIFE close tag');
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
    if (!nodes[id]) { nodes[id] = { style: {}, textContent: '', innerHTML: '', querySelectorAll: function () { return []; } }; }
    return nodes[id];
  }
  var win = {
    CPMS_DOC: {
      clinician_name: 'دکتر رضایی', clinician_id: 9, visit_id: 0,
      rest_url: '/wp-json/clinic/v1/', nonce: 'nonce', base_url: '/admin',
      print_url: '/print', can_call: true, can_start: true, can_upload: true
    },
    scrollTo: function () {},
    CpmsJalali: shippedJalali,
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
    querySelector: function () { return null; },
    querySelectorAll: function () { return []; }
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
      created_at_jalali: "1405/01/01 it's", content_text: 'یادداشت' }],
    prescriptions: [{ id: 5, prescription_number: 'RX-34', status: 'finalized',
      created_at_jalali: "1405/01/02 he said \"hi\"", items: [{ generic_name: 'دارو', dose: 'x',
        frequency: 'y', duration_days: 21 }] }],
    presentation: { follow_ups: { '6': { suggested_date_jalali: '1405/02/03 <b>&' } } },
    follow_ups: [{ id: 6, suggested_date: '2026-04-23', interval_days: 14, reason: 'پیگیری', status: 'open' },
      { id: 7, interval_days: 14, reason: 'پیگیری بازه', status: 'open' }],
    files: [{ id: 7, original_filename: 'report-9.pdf', category: 'document',
      file_size: 4096, visibility: 'patient_visible' }],
    past_visits: [
      { id: 101, jalali_date: '۱۴۰۵/۰۱/۰۳', visit_date: '2026-03-21',
        status: 'consultation_completed', clinician_name: 'دکتر رضایی' },
      { id: 102, visit_date: "2026-03-22 it's <b>&</b>", status: 'checked_out' },
      { id: 103, visit_date: '2026-03-21', status: 'paid' }
    ]
  };
  var recordBefore = JSON.stringify(record);
  env.test.state.record = record;
  env.test.renderVisit();
  var html = env.node('cpms-doc-visit-view').innerHTML;
  ['نسخه ۳ (۱۲ نسخه)', 'تاریخ: ۱۴۰۵/۰۲/۰۳ &lt;b&gt;&amp;', 'بازه: ۱۴ روز', '۲۱ روز', 'RX-۳۴', 'MR-۱۲', '۳۴ سال', '۴KB']
    .forEach(function (expected) { assert.ok(html.includes(expected), expected); });
  assert.ok(html.includes('placeholder="دوز (مثلاً ۵۰۰mg)"'), 'static dose placeholder uses Persian digits');
  assert.ok(!html.includes('placeholder="دوز (مثلاً 500mg)"'), 'dose placeholder has no ASCII digits');
  assert.ok(html.includes('report-9.pdf'), 'file names remain unchanged');
  assert.ok(html.includes('data-id="4"'), 'machine ids remain ASCII');
  // Item A: notes/prescriptions created_at_jalali is ASCII Jalali from the PHP
  // layer; the page must render it in Persian digits via displayDigits(), keeping
  // esc() around it (XSS intact). Distinct dates per item so each assertion is
  // specific to its source.
  assert.ok(html.includes('۱۴۰۵/۰۱/۰۱'), 'note created_at_jalali rendered in Persian digits');
  assert.ok(html.includes('۱۴۰۵/۰۱/۰۲'), 'prescription created_at_jalali rendered in Persian digits');
  assert.ok(!html.includes('1405/01/01'), 'no ASCII digits in note created_at_jalali');
  assert.ok(!html.includes('1405/01/02'), 'no ASCII digits in prescription created_at_jalali');
  // Escaping at the three render lines (:421 note, :443 prescription, :467
  // past_visit): esc() is applied to the digit-converted value, so HTML-special
  // characters are entity-encoded and no raw tag/quote leaks. The apostrophe
  // case also asserts esc() runs BEFORE displayDigits: a swapped order
  // displayDigits(esc(x)) would convert the digits inside &#39; and the exact
  // entity text below would not match.
  assert.ok(html.includes('۱۴۰۵/۰۱/۰۱ it&#39;s'), 'note meta escaped; esc before displayDigits');
  assert.ok(!html.includes("1405/01/01 it's"), 'note meta has no raw digits or quote');
  assert.ok(html.includes('۱۴۰۵/۰۱/۰۲ he said &quot;hi&quot;'), 'prescription meta escaped');
  assert.ok(!html.includes('he said "hi"'), 'prescription no raw quote');
  assert.ok(html.includes('۲۰۲۶-۰۳-۲۲ it&#39;s &lt;b&gt;&amp;&lt;/b&gt;'), 'past_visit fallback escaped');
  assert.ok(!html.includes('<b>&</b>'), 'past_visit no raw <b>&</b> tag');
  assert.ok(!html.includes("2026-03-22 it's"), 'past_visit no raw digits or quote');
  // Past visits use the shipped Gregorian-to-Jalali helper loaded on cpms-doctor.
  assert.ok(html.includes('<li>۱۴۰۵/۰۱/۰۳ — پایان ویزیت (دکتر رضایی)</li>'), 'server jalali_date is rendered as the exact past-visit row');
  assert.ok(html.includes('<li>۱۴۰۵/۰۱/۰۱ — پرداخت‌شده</li>'), 'Gregorian fallback converts to the exact expected Jalali row');
  assert.ok(html.includes('<li>۲۰۲۶-۰۳-۲۲ it&#39;s &lt;b&gt;&amp;&lt;/b&gt; — خارج‌شده</li>'), 'invalid visit date fallback is rendered in the exact row');
  assert.ok(!html.includes('2026-03-21'), 'Gregorian ASCII date is not displayed');
  assert.ok(!html.includes('۲۰۲۶-۰۳-۲۱'), 'Gregorian date is not displayed in Persian digits either');
  assert.ok(!html.includes('2026-03-22'), 'invalid fallback has Persian digits');
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

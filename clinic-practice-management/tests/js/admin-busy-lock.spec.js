/**
 * Admin action buttons — busy-lock against the double-submit window.
 *
 * Four cpms admin pages issue a write request from a persistent button without
 * disabling it, so a second click could start a second in-flight POST:
 *   src/Admin/DoctorDashboardPage.php   (data-act action buttons)
 *   src/Admin/SecretaryFinancePage.php  (#cpms-fin-issue-submit)
 *   src/Admin/SecretaryQueuePage.php    (row checkout button via doPost)
 *   src/Admin/SmsSettingsPage.php       (#cpms-sms-save)
 *
 * Runs the REAL shipped code extracted from those PHP inline scripts in a plain
 * Node `vm` / Function sandbox with hand-written stubs (no jsdom, no npm, no
 * network, no browser).
 *
 * Contract proven here:
 *   1. The trigger is disabled synchronously, before the write settles.
 *   2. It is re-enabled when the write settles — on fulfillment AND on
 *      rejection (a "finally" release, never a stuck button).
 *   3. doPost without a trigger keeps working exactly as before (the other
 *      call sites are unchanged) and the request path/body are untouched.
 *   4. The two pages whose locks are not extractable as a free function still
 *      carry the disable + release statements, so the guard cannot vanish.
 *
 * Run standalone: node clinic-practice-management/tests/js/admin-busy-lock.spec.js
 * Shelled out to (FAIL, not skip, when node is missing) from
 * ClinicCore\Tests\Unit\AdminBusyLockTest.
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var pluginRoot = path.join(__dirname, '..', '..');

function read(rel) {
  return fs.readFileSync(path.join(pluginRoot, rel), 'utf8');
}

var failures = 0;
var checks = 0;

function fail(name, message) {
  failures++;
  console.error('FAIL - ' + name + ': ' + message);
}

function pass(name) {
  checks++;
  console.log('ok - ' + name);
}

var queueOfChecks = [];

function check(name, run) {
  queueOfChecks.push({ name: name, run: run });
}

async function runChecks() {
  for (var i = 0; i < queueOfChecks.length; i++) {
    try {
      await queueOfChecks[i].run();
      pass(queueOfChecks[i].name);
    } catch (error) {
      fail(queueOfChecks[i].name, error && error.message ? error.message : String(error));
    }
  }
  console.log('\n' + checks + ' checks passed, ' + failures + ' failed');
  if (failures > 0) {
    process.exit(1);
  }
}

function must(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

/** Extract one function (any indentation) by brace matching, from real source. */
function extractFunction(source, name) {
  var start = source.indexOf('function ' + name + '(');
  must(start >= 0, 'function ' + name + ' must exist');
  var open = source.indexOf('{', start);
  must(open > start, 'function ' + name + ' must have a body');
  var depth = 0;
  for (var i = open; i < source.length; i++) {
    if (source[i] === '{') {
      depth++;
    } else if (source[i] === '}') {
      depth--;
      if (depth === 0) {
        return source.slice(start, i + 1);
      }
    }
  }
  throw new Error('function ' + name + ' is unbalanced');
}

function microtaskFlush() {
  return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

/* ------------------------------------------------------------------------- *
 * 1) Doctor dashboard — lockTrigger / releaseTrigger, run for real.
 * ------------------------------------------------------------------------- */

var doctor = read('src/Admin/DoctorDashboardPage.php');

function doctorLockSandbox() {
  var snippet = [
    'var busyTrigger = null;',
    'var busySent = false;',
    extractFunction(doctor, 'lockTrigger'),
    extractFunction(doctor, 'releaseTrigger')
  ].join('\n');
  var context = {};
  vm.createContext(context);
  vm.runInContext(snippet, context);
  return context;
}

check('dashboard: the action button is disabled before the write settles', function () {
  var sandbox = doctorLockSandbox();
  var button = { disabled: false };
  sandbox.lockTrigger(button);
  must(button.disabled === true, 'lockTrigger must disable the trigger');
});

check('dashboard: the action button is re-enabled on release', function () {
  var sandbox = doctorLockSandbox();
  var button = { disabled: false };
  sandbox.lockTrigger(button);
  sandbox.releaseTrigger();
  must(button.disabled === false, 'releaseTrigger must re-enable the trigger');
});

check('dashboard: release without a lock is a no-op', function () {
  var sandbox = doctorLockSandbox();
  sandbox.releaseTrigger();
  must(sandbox.busyTrigger === null, 'no trigger must be tracked');
});

check('dashboard: the dispatcher locks the clicked button and releases a no-send action', function () {
  must(doctor.indexOf('lockTrigger(btn);') !== -1, 'the click handler must lock the trigger');
  must(
    doctor.indexOf('if (!busySent) { releaseTrigger(); }') !== -1,
    'an action that sends nothing must release the trigger'
  );
});

check('dashboard: only a non-GET request releases the lock', function () {
  must(
    doctor.indexOf("if (busyTrigger && method !== 'GET' && !busySent) {") !== -1,
    'a GET must not release the write lock'
  );
  must(
    doctor.indexOf('request.then(releaseTrigger, releaseTrigger);') !== -1,
    'the release must run on both settle paths'
  );
});

/* ------------------------------------------------------------------------- *
 * 2) Secretary queue — doPost(path, body, okMsg, btn), run for real.
 * ------------------------------------------------------------------------- */

var queue = read('src/Admin/SecretaryQueuePage.php');

function makeDoPost() {
  var source = extractFunction(queue, 'doPost');
  var calls = [];
  var notices = [];
  var apiBehaviour = 'resolve';
  var factory = new Function(
    'api',
    'notice',
    'errMessage',
    'loadToday',
    'return (' + source + ');'
  );
  var doPost = factory(
    function (method, requestPath, body) {
      calls.push({ method: method, path: requestPath, body: body });
      return apiBehaviour === 'reject'
        ? Promise.reject(new Error('network'))
        : Promise.resolve({ __status: 200, body: {} });
    },
    function (message, kind) { notices.push({ message: message, kind: kind }); },
    function () { return 'خطا'; },
    function () { return Promise.resolve(); }
  );
  return {
    doPost: doPost,
    calls: calls,
    notices: notices,
    rejectNext: function () { apiBehaviour = 'reject'; }
  };
}

check('queue: doPost disables the trigger before the POST resolves', function () {
  var env = makeDoPost();
  var button = { disabled: false };
  env.doPost('visits/7/checkout', {}, 'خروج ثبت شد', button);
  must(button.disabled === true, 'the trigger must be disabled synchronously');
});

check('queue: doPost re-enables the trigger once the POST settles', async function () {
  var env = makeDoPost();
  var button = { disabled: false };
  env.doPost('visits/7/checkout', {}, 'خروج ثبت شد', button);
  await microtaskFlush();
  must(button.disabled === false, 'the trigger must be re-enabled after settle');
});

check('queue: doPost re-enables the trigger when the POST rejects', async function () {
  var env = makeDoPost();
  var button = { disabled: false };
  env.rejectNext();
  env.doPost('visits/7/checkout', {}, 'خروج ثبت شد', button);
  await microtaskFlush();
  must(button.disabled === false, 'a failed write must not leave the button stuck');
  must(env.notices.length >= 1, 'the network notice must still be shown');
});

check('queue: doPost keeps the same request path, body and message', async function () {
  var env = makeDoPost();
  var button = { disabled: false };
  var payload = { waive_invoice: { reason: 'x' } };
  env.doPost('visits/9/checkout', payload, 'خروج ثبت شد', button);
  await microtaskFlush();
  must(env.calls.length === 1, 'exactly one request must be issued');
  must(env.calls[0].method === 'POST', 'the method must stay POST');
  must(env.calls[0].path === 'visits/9/checkout', 'the path must be unchanged');
  must(env.calls[0].body === payload, 'the body must be passed through untouched');
  must(env.notices[0].message === 'خروج ثبت شد', 'the success message must be unchanged');
});

check('queue: doPost without a trigger still posts (unchanged call sites)', async function () {
  var env = makeDoPost();
  env.doPost('visits/walk-in', { patient_id: 3 }, 'به صف اضافه شد');
  await microtaskFlush();
  must(env.calls.length === 1, 'a call without a trigger must still post once');
});

/* ------------------------------------------------------------------------- *
 * 3) Finance + SMS — the lock statements are present in the real pages.
 * ------------------------------------------------------------------------- */

var finance = read('src/Admin/SecretaryFinancePage.php');
var sms = read('src/Admin/SmsSettingsPage.php');

check('finance: the issue button is disabled and released on settle', function () {
  must(
    finance.indexOf("var submitBtn = document.getElementById('cpms-fin-issue-submit');") !== -1,
    'the invoice issue button must be the locked trigger'
  );
  must(
    finance.indexOf('if (submitBtn) { submitBtn.disabled = true; }') !== -1,
    'the trigger must be disabled before the POST'
  );
  must(
    finance.indexOf('issueRequest.then(releaseIssue, releaseIssue);') !== -1,
    'the trigger must be released on both settle paths'
  );
  must(finance.indexOf("api('POST', 'invoices', body)") !== -1, 'the POST body must be unchanged');
});

check('sms: the save button is disabled and released on settle', function () {
  must(sms.indexOf("var saveBtn = el('cpms-sms-save');") !== -1, 'the save button must be locked');
  must(sms.indexOf('saveBtn.disabled = true;') !== -1, 'the save button must be disabled');
  must(
    sms.indexOf('chainDone.then(releaseSave, releaseSave);') !== -1,
    'the save button must be released on both settle paths'
  );
  must(sms.indexOf("api('POST', 'settings', body)") !== -1, 'the settings body must be unchanged');
});

/* ------------------------------------------------------------------------- *
 * Summary.
 * ------------------------------------------------------------------------- */

runChecks();

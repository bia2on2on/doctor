/**
 * Doctor module — the two converted confirmation sites must use the SHARED
 * in-page dialog with fail-closed behaviour (never native window.confirm/prompt).
 *
 * Runs the REAL shipped functions extracted from
 * `templates/doctor-portal-shell.php` in a plain Node `vm` with hand-written
 * stubs (no jsdom, no npm dependency, no network, no browser):
 *
 *   - doQueueAction()          -> skip-reason prompt
 *   - submitWorkspaceComplete() / finishWorkspaceComplete() -> finish-visit confirm
 *
 * Contract proven here:
 *   1. Both sites call window.CpmsDialog.prompt / window.CpmsDialog.confirm and
 *      contain NO bare native confirm/prompt call.
 *   2. Unavailable dialog, cancel and Escape settle as null/false => NO POST.
 *   3. An empty skip reason shows the Persian error and sends NO POST.
 *   4. An accepted confirmation sends exactly ONE POST, with the same Persian
 *      wording and the same reason/Complete payloads the native path used.
 *   5. A non-skip queue action still posts synchronously (double-click guard).
 *
 * Run standalone: node clinic-practice-management/tests/js/doctor-shell-dialog.spec.js
 * Shelled out to (FAIL, not skip, when node is missing) from
 * ClinicCore\Tests\Integration\PkgDoctorStaffShellDialogTest.
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
  console.log('ok - ' + name);
}

/** Extract one top-level (column-0) function from the shipped template source. */
function sourceFunction(source, name) {
  var start = source.indexOf('\nfunction ' + name + '(');
  if (start < 0) {
    throw new Error('function exists: ' + name);
  }
  var end = source.indexOf('\nfunction ', start + 1);
  if (end <= start) {
    throw new Error('next function bounds ' + name);
  }
  return source.slice(start, end);
}

var shell = read('templates/doctor-portal-shell.php');

function makeEnv(dialog) {
  var calls = [];
  var errors = [];
  var busy = [];
  var stubs = {
    textContent: '',
    hidden: false,
    focus: function () {},
    getAttribute: function () { return null; }
  };
  var state = {
    selectedClinicId: 7,
    selectedLocationId: 9,
    workspaceVisitId: 42,
    workspaceCompleteBusy: false,
    workspaceData: null
  };
  var context = {
    console: console,
    Promise: Promise,
    state: state,
    document: { querySelector: function () { return stubs; } },
    window: { CpmsDialog: dialog },
    qs: function () { return stubs; },
    hide: function () {},
    show: function () {},
    setRowBusy: function () {},
    setCompleteBusy: function (v) { busy.push(!!v); state.workspaceCompleteBusy = !!v; },
    loadTodayAndQueue: function () {},
    renderWorkspaceHeader: function () {},
    renderConsultComplete: function () {},
    recFuFeedbackError: function (r, fallback) { return fallback; },
    actionErrorMessage: function () { return 'error'; },
    scopeHeaders: function () { return {}; },
    showError: function (msg) { errors.push(String(msg)); },
    api: function (method, url, body) {
      calls.push({ method: method, url: url, body: body });
      return Promise.resolve({ status: 200, body: { data: {} } });
    }
  };
  vm.createContext(context);
  vm.runInContext(sourceFunction(shell, 'doQueueAction'), context, { filename: 'doQueueAction.js' });
  vm.runInContext(sourceFunction(shell, 'submitWorkspaceComplete'), context, { filename: 'submitWorkspaceComplete.js' });
  vm.runInContext(sourceFunction(shell, 'finishWorkspaceComplete'), context, { filename: 'finishWorkspaceComplete.js' });
  return { context: context, calls: calls, errors: errors, busy: busy };
}

function dialogWith(promptValue, confirmValue) {
  return {
    prompt: function () { return Promise.resolve(promptValue); },
    confirm: function () { return Promise.resolve(confirmValue); }
  };
}

/** Flush promise microtasks without a browser timer. */
function tick() {
  return new Promise(function (resolve) { setImmediate(resolve); });
}

var queue = [];
function check(name, fn) {
  queue.push({ name: name, fn: fn });
}

check('the two converted sites call CpmsDialog and never a native dialog', function () {
  var skip = sourceFunction(shell, 'doQueueAction');
  var complete = sourceFunction(shell, 'submitWorkspaceComplete');
  var bare = /(?<![A-Za-z0-9_$.])(?:confirm|prompt)\s*\(/;
  if (bare.test(skip) || bare.test(complete)) {
    throw new Error('a bare native confirm/prompt call remains in a converted site');
  }
  if (skip.indexOf('window.confirm(') !== -1 || skip.indexOf('window.prompt(') !== -1) {
    throw new Error('window.confirm/prompt remains in doQueueAction');
  }
  if (complete.indexOf('window.confirm(') !== -1 || complete.indexOf('window.prompt(') !== -1) {
    throw new Error('window.confirm/prompt remains in submitWorkspaceComplete');
  }
  if (skip.indexOf('window.CpmsDialog.prompt(') === -1) {
    throw new Error('doQueueAction must call the shared in-page prompt');
  }
  if (complete.indexOf('window.CpmsDialog.confirm(') === -1) {
    throw new Error('submitWorkspaceComplete must call the shared in-page confirm');
  }
  if (skip.indexOf('Promise.resolve(null)') === -1) {
    throw new Error('doQueueAction must fail closed with null');
  }
  if (complete.indexOf('Promise.resolve(false)') === -1) {
    throw new Error('submitWorkspaceComplete must fail closed with false');
  }
  if (skip.indexOf('علت رد کردن را بنویسید:') === -1 || skip.indexOf('علت رد کردن الزامی است.') === -1) {
    throw new Error('the established Persian skip wording was lost');
  }
  if (complete.indexOf('پایان این ویزیت ثبت شود؟') === -1) {
    throw new Error('the established Persian Complete wording was lost');
  }
});

check('skip: cancelled prompt (null) sends nothing', async function () {
  var env = makeEnv(dialogWith(null, false));
  env.context.doQueueAction(42, 'skip');
  await tick();
  if (env.calls.length !== 0) { throw new Error('a cancelled skip sent a POST'); }
});

check('skip: unavailable dialog fails closed with null and sends nothing', async function () {
  var env = makeEnv(undefined);
  env.context.doQueueAction(42, 'skip');
  await tick();
  if (env.calls.length !== 0) { throw new Error('an unavailable dialog sent a POST'); }
});

check('skip: empty reason shows the Persian error and sends nothing', async function () {
  var env = makeEnv(dialogWith('', false));
  env.context.doQueueAction(42, 'skip');
  await tick();
  if (env.calls.length !== 0) { throw new Error('an empty skip reason sent a POST'); }
  if (env.errors.length !== 1 || env.errors[0] !== 'علت رد کردن الزامی است.') {
    throw new Error('the established empty-reason error was not shown: ' + JSON.stringify(env.errors));
  }
});

check('skip: accepted reason posts once with the trimmed reason', async function () {
  var env = makeEnv(dialogWith('  دلیل رد  ', false));
  env.context.doQueueAction(42, 'skip');
  await tick();
  if (env.calls.length !== 1) { throw new Error('expected exactly one skip POST'); }
  var call = env.calls[0];
  if (call.method !== 'POST' || call.url !== '/doctor/portal/visits/42/skip') {
    throw new Error('unexpected skip route: ' + call.url);
  }
  if (!call.body || call.body.reason !== 'دلیل رد') {
    throw new Error('skip body lost the reason: ' + JSON.stringify(call.body));
  }
});

check('non-skip action still posts synchronously', async function () {
  var env = makeEnv(dialogWith(null, false));
  env.context.doQueueAction(42, 'call');
  if (env.calls.length !== 1) { throw new Error('the synchronous POST path was weakened'); }
  await tick();
});

check('complete: cancelled confirm (false) sends nothing', async function () {
  var env = makeEnv(dialogWith(null, false));
  env.context.submitWorkspaceComplete();
  await tick();
  if (env.calls.length !== 0) { throw new Error('a cancelled Complete sent a POST'); }
});

check('complete: unavailable dialog fails closed with false and sends nothing', async function () {
  var env = makeEnv(undefined);
  env.context.submitWorkspaceComplete();
  await tick();
  if (env.calls.length !== 0) { throw new Error('an unavailable dialog sent a POST'); }
});

check('complete: accepted confirm posts exactly once to /complete', async function () {
  var env = makeEnv(dialogWith(null, true));
  env.context.submitWorkspaceComplete();
  await tick();
  if (env.calls.length !== 1) { throw new Error('expected exactly one Complete POST'); }
  var call = env.calls[0];
  if (call.method !== 'POST' || call.url !== '/doctor/portal/visits/42/complete') {
    throw new Error('unexpected Complete route: ' + call.url);
  }
  if (call.body !== null) { throw new Error('Complete must keep its empty body'); }
  if (env.busy[0] !== true) { throw new Error('double-submit busy guard not armed'); }
});

async function run() {
  for (var i = 0; i < queue.length; i++) {
    checks++;
    try {
      await queue[i].fn();
      pass(queue[i].name);
    } catch (e) {
      fail(queue[i].name, (e && e.message) || String(e));
    }
  }
  console.log('\n' + checks + ' checks, ' + failures + ' failure(s)');
  process.exit(failures === 0 ? 0 : 1);
}

run();

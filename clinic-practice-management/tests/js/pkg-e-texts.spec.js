/**
 * Package E — Persian UI text regressions (plain Node, zero dependencies).
 *
 * Executes the REAL shipped assets (not reimplementations):
 *  - assets/js/cpms-ui-text.js   → shared doctor-title / Persian-digit helper
 *  - assets/js/cpms-dialog.js    → shared in-page confirm/prompt component
 * and greps the production templates for the regressions this package fixes.
 *
 * Run standalone: node clinic-practice-management/tests/js/pkg-e-texts.spec.js
 * Also shelled out to from ClinicCore\Tests\Integration\PkgEPersianTextTest.
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');
var assert = require('assert');

var pluginRoot = path.join(__dirname, '..', '..');

function read(rel) {
  return fs.readFileSync(path.join(pluginRoot, rel), 'utf8');
}

function loadAsset(rel, globals) {
  var sandbox = globals || {};
  sandbox.window = sandbox.window || sandbox;
  sandbox.document = sandbox.document || {
    readyState: 'loading',
    addEventListener: function () {},
    getElementById: function () { return null; }
  };
  vm.createContext(sandbox);
  vm.runInContext(read(rel), sandbox, { filename: rel });
  return sandbox;
}

var failures = 0;
function check(name, fn) {
  try {
    fn();
    console.log('ok - ' + name);
  } catch (e) {
    failures++;
    console.error('FAIL - ' + name + ': ' + (e && e.message));
  }
}

// ---------------------------------------------------------------------------
// 1) Doctor display name — one shared normalizer, JS side
// ---------------------------------------------------------------------------
var textEnv = loadAsset('assets/js/cpms-ui-text.js', {});
var CpmsText = textEnv.window.CpmsText;

check('cpms-ui-text.js exports CpmsText.doctorDisplayName', function () {
  assert.ok(CpmsText && typeof CpmsText.doctorDisplayName === 'function');
});

check('doctor title is added when the name has no title', function () {
  assert.strictEqual(CpmsText.doctorDisplayName('رضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.doctorDisplayName('سارا کریمی'), 'دکتر سارا کریمی');
});

check('doctor title is never doubled (plain, dotted, ZWNJ, extra spaces)', function () {
  assert.strictEqual(CpmsText.doctorDisplayName('دکتر رضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.doctorDisplayName('دکتر. رضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.doctorDisplayName('دکتر .رضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.doctorDisplayName('دکتر\u200cرضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.doctorDisplayName('  دکتر   رضایی  '), 'دکتر رضایی');
  assert.strictEqual(CpmsText.doctorDisplayName('دکتر'), 'دکتر');
});

check('empty name stays empty (caller supplies its own Persian fallback)', function () {
  assert.strictEqual(CpmsText.doctorDisplayName(''), '');
  assert.strictEqual(CpmsText.doctorDisplayName(null), '');
});

check('a doubled title inside an already-rendered text collapses', function () {
  assert.strictEqual(CpmsText.collapseDoubledTitle('با دکتر دکتر رضایی'), 'با دکتر رضایی');
  assert.strictEqual(CpmsText.collapseDoubledTitle('دکتر  دکتر رضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.collapseDoubledTitle('با دکتر رضایی'), 'با دکتر رضایی');
});

check('faDigits renders Persian digits and never latin ones', function () {
  assert.strictEqual(CpmsText.faDigits(5), '۵');
  assert.strictEqual(CpmsText.faDigits('12'), '۱۲');
  assert.strictEqual(/[0-9]/.test(CpmsText.faDigits('2026-11-01')), false);
});

// ---------------------------------------------------------------------------
// 2) Shared dialog asset: contract + no native dialogs in cpms-admin.js
// ---------------------------------------------------------------------------
check('cpms-dialog.js exposes confirm/prompt returning promises', function () {
  var dialogEnv = loadAsset('assets/js/cpms-dialog.js', {});
  assert.strictEqual(typeof dialogEnv.window.CpmsDialog.confirm, 'function');
  assert.strictEqual(typeof dialogEnv.window.CpmsDialog.prompt, 'function');
});

check('cpms-dialog.js implements focus trap, Escape and RTL modal semantics', function () {
  var src = read('assets/js/cpms-dialog.js');
  ['aria-modal', "'Escape'", "'Tab'", 'dir', 'rtl', 'lastFocus.focus()', 'role'].forEach(function (token) {
    assert.ok(src.indexOf(token) !== -1, 'cpms-dialog.js must contain ' + token);
  });
});

check('cpms-admin.js delegates data-cpms-confirm to the shared dialog and uses no native dialog', function () {
  var src = read('assets/js/cpms-admin.js');
  assert.strictEqual(/window\.confirm\s*\(/.test(src), false, 'cpms-admin.js must not call window.confirm');
  assert.strictEqual(/window\.prompt\s*\(/.test(src), false, 'cpms-admin.js must not call window.prompt');
  assert.strictEqual(/[^.\w]alert\s*\(/.test(src), false, 'cpms-admin.js must not call alert');
  assert.ok(src.indexOf('window.CpmsDialog.confirm') !== -1, 'cpms-admin.js must delegate to window.CpmsDialog');
});

// ---------------------------------------------------------------------------
// 3) Production templates/pages: Persian labels, no raw enum fallbacks
// ---------------------------------------------------------------------------
var doctor = read('templates/doctor-portal-shell.php');

check('doctor shell never falls back to a raw status enum', function () {
  assert.strictEqual(/\}\[status\] \|\| status \|\| ''/.test(doctor), false, 'status labels must fall back to Persian');
  assert.ok(doctor.indexOf("|| 'وضعیت نامشخص'") !== -1, 'Persian status fallback must exist');
});

check('doctor shell never renders the raw visit source enum', function () {
  assert.strictEqual(doctor.indexOf("esc(v.source||'')"), -1, 'v.source must go through visitSourceLabel()');
  assert.ok(doctor.indexOf('function visitSourceLabel(') !== -1);
  assert.ok(doctor.indexOf('walk_in:') !== -1 && doctor.indexOf('scheduled:') !== -1);
});

check('doctor shell gender label has a Persian fallback (never "unknown")', function () {
  assert.strictEqual(/\}\[value\] \|\| value \|\| ''/.test(doctor), false);
  assert.ok(doctor.indexOf("unknown: 'نامشخص'") !== -1);
});

check('doctor shell uses the shared doctor-name helper, not a hardcoded title', function () {
  assert.strictEqual(doctor.indexOf("'دکتر ' + (doctor.clinician_name"), -1);
  assert.ok(doctor.indexOf('cpmsDoctorDisplayName(') !== -1);
});

check('doctor shell renders Persian digits for day durations and prescription item titles', function () {
  assert.strictEqual(doctor.indexOf("esc(it.duration_days) + ' روز'"), -1);
  assert.strictEqual(doctor.indexOf("'دارو ' + (i + 1)"), -1);
  assert.ok(doctor.indexOf('دارو ۱') !== -1, 'static legend uses a Persian digit');
  assert.ok(doctor.indexOf('cpmsFaDigits(i + 1)') !== -1);
});

// ---------------------------------------------------------------------------
// 4) Finance help text is cleared, staff-pricing half-spaces are fixed
// ---------------------------------------------------------------------------
var finance = read('templates/staff-finance-board.php');

check('finance board clears the "select a location" hint', function () {
  assert.ok(finance.indexOf('clearLocationHint()') !== -1, 'a clearing helper must exist');
  assert.ok(/state\.locationHintShown = \(message \|\| ''\) === LOCATION_HINT;/.test(finance), 'setStatus must track the hint');
  assert.ok(/locationSelect\.addEventListener\('change', function \(\) \{\s*state\.locationId = [\s\S]*?clearLocationHint\(\);/.test(finance),
    'choosing a location must clear the hint before it reloads');
  assert.ok(/renderBoard\(payload\(result\)\);\s*clearLocationHint\(\);/.test(finance), 'a successful board load must clear the hint');
});

var pricing = read('templates/staff-pricing.php');

check('staff-pricing help text uses ZWNJ (no glued Persian words)', function () {
  ['پیشفرض', 'ویزیتهای', 'میشوند', 'میشود', 'پرداختشده', 'نمیکنند', 'ثبتشده', 'اعمالشده', 'درمانهای'].forEach(function (glued) {
    assert.strictEqual(pricing.indexOf(glued), -1, 'glued form found: ' + glued);
  });
  assert.ok(pricing.indexOf('پیش\u200cفرض') !== -1, 'the half-space form must exist');
});

// ---------------------------------------------------------------------------
// 5) Persian table headers / MRN labels / no doubled SMS title
// ---------------------------------------------------------------------------
check('SMS log table headers are Persian', function () {
  var sms = read('src/Admin/SmsSettingsPage.php');
  ['<th>Status</th>', '<th>Attempts</th>', '<th>Provider Msg ID</th>', '<th>Failure</th>'].forEach(function (english) {
    assert.strictEqual(sms.indexOf(english), -1, 'English header still present: ' + english);
  });
  ['وضعیت', 'تلاش‌ها', 'شناسهٔ پیام ارائه‌دهنده', 'خطا'].forEach(function (persian) {
    assert.ok(sms.indexOf(persian) !== -1, 'Persian header missing: ' + persian);
  });
});

check('MRN is shown with a Persian label in the doctor shell and finance page', function () {
  assert.strictEqual(doctor.indexOf('MRN: <b>'), -1, 'raw "MRN:" label must be gone');
  assert.ok(doctor.indexOf('کد پرونده: <b>') !== -1);
  var financePage = read('src/Admin/SecretaryFinancePage.php');
  assert.strictEqual(financePage.indexOf('<th>MRN</th>'), -1);
  assert.ok(financePage.indexOf('<th>کد پرونده</th>') !== -1);
});

check('SMS default templates no longer hardcode the doctor title', function () {
  var events = read('src/Domain/Sms/SmsEvents.php');
  assert.strictEqual(events.indexOf('دکتر {{doctor_name}}'), -1, 'title now comes from the payload helper');
});

if (failures > 0) {
  console.error(failures + ' assertion(s) failed');
  process.exit(1);
}
console.log('OK: pkg-e-texts spec (' + 0 + ' failures)');

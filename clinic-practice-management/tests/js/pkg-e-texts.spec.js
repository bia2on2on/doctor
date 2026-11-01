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

// The expectations come from the ONE shared fixture that the PHP unit test reads
// too (tests/fixtures/doctor-display-name-vectors.json), so PHP/JS parity is
// checked from a single source instead of two hand-maintained lists.
var titleFixture = JSON.parse(read('tests/fixtures/doctor-display-name-vectors.json'));

check('shared fixture is readable and carries the D1 vectors', function () {
  assert.ok(Array.isArray(titleFixture.vectors) && titleFixture.vectors.length >= 20);
  ['prefix-word-doktari', 'name-with-zwnj', 'name-with-interior-dot', 'latin-dr-dot', 'null'].forEach(function (id) {
    assert.ok(titleFixture.vectors.some(function (v) { return v.id === id; }), 'fixture vector missing: ' + id);
  });
});

titleFixture.vectors.forEach(function (vector) {
  check('title vector ' + vector.id + ' (' + vector.note + ')', function () {
    assert.strictEqual(
      CpmsText.doctorDisplayName(vector.input === null ? null : vector.input),
      vector.expected
    );
    if (typeof vector.had_title === 'boolean') {
      assert.strictEqual(
        CpmsText.startsWithTitle(vector.input === null ? null : vector.input),
        vector.had_title,
        'startsWithTitle must use the same whole-token rule'
      );
    }
  });
});

check('empty name stays empty (caller supplies its own Persian fallback)', function () {
  assert.strictEqual(CpmsText.doctorDisplayName(''), '');
  assert.strictEqual(CpmsText.doctorDisplayName(undefined), '');
});

check('a doubled title inside an already-rendered text collapses, names stay intact', function () {
  assert.strictEqual(CpmsText.collapseDoubledTitle('با دکتر دکتر رضایی'), 'با دکتر رضایی');
  assert.strictEqual(CpmsText.collapseDoubledTitle('دکتر  دکتر رضایی'), 'دکتر رضایی');
  assert.strictEqual(CpmsText.collapseDoubledTitle('با دکتر رضایی'), 'با دکتر رضایی');
  // A word that merely STARTS with the title letters must never be eaten.
  assert.strictEqual(CpmsText.collapseDoubledTitle('دکتر دکتری'), 'دکتر دکتری');
  assert.strictEqual(CpmsText.collapseDoubledTitle('دکتر دکترزاده'), 'دکتر دکترزاده');
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

check('cpms-admin.js delegates to the shared dialog; the ONLY native dialog is the D2 fallback', function () {
  var src = read('assets/js/cpms-admin.js');
  assert.strictEqual(/window\.prompt\s*\(/.test(src), false, 'cpms-admin.js must not call window.prompt');
  assert.strictEqual(/[^.\w]alert\s*\(/.test(src), false, 'cpms-admin.js must not call alert');
  assert.ok(src.indexOf('window.CpmsDialog.confirm') !== -1, 'cpms-admin.js must delegate to window.CpmsDialog');
  // D2: the shared dialog is preferred; the native confirm exists ONLY as the
  // fail-closed fallback inside nativeConfirm().
  var nativeCalls = (src.match(/window\.confirm\s*\(/g) || []).length;
  assert.strictEqual(nativeCalls, 1, 'exactly one native window.confirm call (the D2 fallback) is allowed');
  var fallbackBody = src.slice(src.indexOf('function nativeConfirm'), src.indexOf('function showConfirm'));
  assert.ok(fallbackBody.indexOf('window.confirm(') !== -1, 'the native confirm must live inside nativeConfirm()');
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
// 4) Finance help text is cleared on location selection / successful load
// ---------------------------------------------------------------------------
var finance = read('templates/staff-finance-board.php');

check('finance board clears the "select a location" hint', function () {
  assert.ok(finance.indexOf('clearLocationHint()') !== -1, 'a clearing helper must exist');
  assert.ok(/state\.locationHintShown = \(message \|\| ''\) === LOCATION_HINT;/.test(finance), 'setStatus must track the hint');
  assert.ok(/locationSelect\.addEventListener\('change', function \(\) \{\s*state\.locationId = [\s\S]*?clearLocationHint\(\);/.test(finance),
    'choosing a location must clear the hint before it reloads');
  assert.ok(/renderBoard\(payload\(result\)\);\s*clearLocationHint\(\);/.test(finance), 'a successful board load must clear the hint');
});

// NOTE: the staff-pricing half-space (ZWNJ) repair is NOT part of this change.
// The corrected spellings (e.g. «تعرفه ثبت‌شده هنگام پذیرش») are asserted with the
// OLD glued spelling by acceptance/pilot paths outside this package
// (the scoped-demo and disposable-site harnesses under tests/, bin/pilot-finance-board.py and the
// companion tool's own acceptance suite), so repairing the copy here turns those
// guards red. That repair needs its own PR which updates those expectations
// together — including the companion path — rather than mixing production and
// companion changes in one PR.

// ---------------------------------------------------------------------------
// 5) Persian table headers / MRN labels / no doubled SMS title
// ---------------------------------------------------------------------------
check('SMS log table headers are Persian (matched inside the <th> cells)', function () {
  var sms = read('src/Admin/SmsSettingsPage.php');
  ['<th>Status</th>', '<th>Attempts</th>', '<th>Provider Msg ID</th>', '<th>Failure</th>'].forEach(function (english) {
    assert.strictEqual(sms.indexOf(english), -1, 'English header still present: ' + english);
  });
  // R5: these words occur ELSEWHERE in the file, so a bare indexOf() would pass
  // even with English headers. Match the exact <th> cells instead.
  ['<th>وضعیت</th>', '<th>تلاش‌ها</th>', '<th>شناسهٔ پیام ارائه‌دهنده</th>', '<th>خطا</th>', '<th>زمان ثبت</th>'].forEach(function (cell) {
    assert.ok(sms.indexOf(cell) !== -1, 'Persian header cell missing: ' + cell);
  });
  var thCells = (sms.match(/<th>[^<]*<\/th>/g) || []);
  var latinCells = thCells
    .map(function (cell) { return cell.replace(/<\/?th>/g, ''); })
    .filter(function (label) { return /[A-Za-z]/.test(label); });
  assert.deepStrictEqual(latinCells, [], 'no Latin-only header cell may remain in the log table');
});

check('SMS status slots announce themselves to assistive tech (D4)', function () {
  var sms = read('src/Admin/SmsSettingsPage.php');
  assert.ok(/<span id="cpms-sms-state" role="status" aria-live="polite"/.test(sms),
    '#cpms-sms-state needs role=status aria-live=polite');
  assert.ok(/<span id="cpms-sms-test-send-result" role="status" aria-live="polite"/.test(sms),
    '#cpms-sms-test-send-result needs role=status aria-live=polite');
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

// ---------------------------------------------------------------------------
// 6) Native dialogs removed from the converted sites (no window.confirm/prompt/alert)
// ---------------------------------------------------------------------------
check('cpms-admin.js delegates to the shared dialog, which keeps the shipped acceptance surface', function () {
  var dialog = read('assets/js/cpms-dialog.js');
  // bin/rwp-acceptance.py::capture_confirm() drives a real dangerous action and
  // requires .cpms-modal-overlay, a focused element whose class contains
  // 'confirm', and Escape closing it. Those tokens must stay on the shared dialog.
  assert.ok(dialog.indexOf('cpms-modal-overlay') !== -1, 'overlay legacy token missing (acceptance gate would break)');
  assert.ok(dialog.indexOf('cpms-modal-confirm') !== -1, 'confirm-button legacy token missing (focus assertion would break)');
  assert.ok(/\'Escape\'/.test(dialog), 'Escape must cancel');
  assert.ok(dialog.indexOf('aria-modal') !== -1 && dialog.indexOf('aria-labelledby') !== -1);
});

check('CustomRoleDefinitionsPage has no server-rendered native confirm', function () {
  var src = read('src/Admin/CustomRoleDefinitionsPage.php');
  assert.strictEqual(/return confirm\s*\(/.test(src), false, 'onclick="return confirm(...)" must be gone');
  assert.strictEqual(/onclick=/.test(src), false, 'no inline onclick handler may remain');
  assert.ok(src.indexOf('data-cpms-confirm=') !== -1, 'the shared dialog is driven by data-cpms-confirm');
});

check('SmsSettingsPage no longer uses native alert()', function () {
  var src = read('src/Admin/SmsSettingsPage.php');
  assert.strictEqual(/[^.\w]alert\s*\(/.test(src), false, 'alert() must be replaced by inline Persian status text');
  assert.ok(src.indexOf('cpms-sms-test-send-result') !== -1);
});

check('cpms-admin.js falls back to native confirm (fail-closed) and never skips confirmation', function () {
  var src = read('assets/js/cpms-admin.js');
  assert.ok(src.indexOf('nativeConfirm') !== -1, 'native fallback required by D2');
  assert.ok(/window\.confirm\(/.test(src), 'the fallback must be the native window.confirm');
  // The old fail-open shape (`if (!dialogAvailable()) { return; }` before any
  // confirmation) must be gone.
  assert.strictEqual(/if \(!dialogAvailable\(\)\) \{ return; \}/.test(src), false,
    'fail-open early return must not remain');
  assert.ok(src.indexOf('isPending') !== -1, 'D3: a pending trigger must be ignored');
});

check('cpms-dialog.js settles a pending dialog as cancel before opening another (D3)', function () {
  var src = read('assets/js/cpms-dialog.js');
  assert.ok(/active\.settle\(\)/.test(src), 'the pending dialog must be settled as cancel');
  assert.ok(/if \(active !== handle\)/.test(src), 'a stale keydown handler must not act');
});

if (failures > 0) {
  console.error(failures + ' assertion(s) failed');
  process.exit(1);
}
console.log('OK: pkg-e-texts spec (' + 0 + ' failures)');

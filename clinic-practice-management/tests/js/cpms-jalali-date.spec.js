/**
 * Pure-logic regression test for assets/js/cpms-jalali-date.js — executes the
 * REAL production script (not a reimplementation) against a minimal DOM
 * stub, then exercises the exported `window.CpmsJalali` conversion/
 * validation API directly. Plain Node, zero dependencies, zero network
 * access (consistent with the asset's own "local-only" contract).
 *
 * Run standalone:   node tests/js/cpms-jalali-date.spec.js
 * Run from PHPUnit: ClinicCore\Tests\Integration\CpmsJalaliDateLogicTest
 *                    shells out to this exact file with `node`.
 *
 * Exit code 0 = all assertions passed. Non-zero = at least one failure
 * (message printed to stderr).
 */
'use strict';

var path = require('path');
var fs = require('fs');

// ---- Minimal DOM stub: just enough for the script's top-level code (the
// IIFE body outside any DOM-mutating function) to execute without throwing.
// readyState 'loading' means it only registers a DOMContentLoaded listener
// and returns — it never touches querySelectorAll/createElement here, so
// none of that needs to be stubbed for THIS test's purpose (pure date-logic
// only; DOM widget wiring is covered separately by real-browser Playwright
// acceptance). ----
global.window = {};
global.document = {
  readyState: 'loading',
  addEventListener: function () {}
};

var scriptPath = path.join(__dirname, '..', '..', 'assets', 'js', 'cpms-jalali-date.js');
var code = fs.readFileSync(scriptPath, 'utf8');
// eslint-disable-next-line no-new-func
new Function(code)();

var CpmsJalali = global.window.CpmsJalali;
if (!CpmsJalali || typeof CpmsJalali.jalaliTextToIso !== 'function') {
  console.error('FAIL: window.CpmsJalali was not exported by the script at ' + scriptPath);
  process.exit(1);
}

var failures = [];

function assertEqual(actual, expected, label) {
  var a = JSON.stringify(actual);
  var e = JSON.stringify(expected);
  if (a !== e) {
    failures.push(label + ': expected ' + e + ', got ' + a);
  }
}

function assertNull(actual, label) {
  if (actual !== null) {
    failures.push(label + ': expected null, got ' + JSON.stringify(actual));
  }
}

function assertNotNull(actual, label) {
  if (actual === null) {
    failures.push(label + ': expected a non-null value, got null');
  }
}

// ===================== PRIORITY 1 — exact regression from the independent
// review: "Typing invalid 1405/12/30 must NEVER submit the intermediate
// valid 1405/12/03 Gregorian equivalent." =====================

// 1405 is NOT a leap Jalali year in the canonical table (Nowruz 1405 →
// 2026-03-20, Nowruz 1406 → 2027-03-20 — exactly 365 days apart), so Esfand
// 1405 has only 29 days: day 30 must be rejected outright.
assertNull(CpmsJalali.jalaliTextToIso('1405/12/30'), 'REGRESSION: 1405/12/30 (non-leap Esfand 30) must be rejected');
var reasonFor140512_30 = CpmsJalali.classifyCompleteJalaliEntry('1405/12/30');
assertEqual(reasonFor140512_30.ok, false, 'REGRESSION: 1405/12/30 classified not-ok');
assertEqual(reasonFor140512_30.reason, 'day', 'REGRESSION: 1405/12/30 reason is "day"');

// The intermediate prefix the admin would have typed on the way to
// "1405/12/30" (e.g. "1405/12/3") IS itself a syntactically complete and
// numerically valid date (3 Esfand 1405) — this is exactly the "stale
// intermediate value" the independent review flagged. The conversion
// function correctly resolves it to its OWN Gregorian date; the actual bug
// was in the WIDGET committing it to the native input prematurely while the
// admin kept typing, not in this pure conversion. We assert here that this
// intermediate text converts to a DIFFERENT Gregorian date than "1405/12/30"
// would (if it were valid), proving they must never be conflated:
var intermediate = CpmsJalali.jalaliTextToIso('1405/12/3');
assertNotNull(intermediate, '1405/12/3 (a real, different, complete date) is independently valid');
assertEqual(intermediate, '2027-02-21', '1405/12/3 resolves to its own correct Gregorian date (not a stand-in for 1405/12/30)');

// ===================== Positive: a real leap-Esfand-30 date must be
// accepted (1401 IS a leap Jalali year in the canonical table: Nowruz 1401 →
// 2022-03-20, Nowruz 1402 → 2023-03-21 — 366 days apart). =====================
var leapIso = CpmsJalali.jalaliTextToIso('1401/12/30');
assertNotNull(leapIso, 'POSITIVE: 1401/12/30 (leap Esfand 30) must be accepted');
var leapResult = CpmsJalali.classifyCompleteJalaliEntry('1401/12/30');
assertEqual(leapResult.ok, true, 'POSITIVE: 1401/12/30 classifies ok');

// Persian-digit input must behave identically to ASCII-digit input.
assertEqual(
  CpmsJalali.jalaliTextToIso('۱۴۰۱/۱۲/۳۰'),
  CpmsJalali.jalaliTextToIso('1401/12/30'),
  'Persian-digit entry matches ASCII-digit entry'
);

// ===================== Supported year window: 1300-1499 =====================
assertNull(CpmsJalali.jalaliTextToIso('1299/01/01'), 'year 1299 (below supported window) must be rejected');
assertNull(CpmsJalali.jalaliTextToIso('1500/01/01'), 'year 1500 (above supported window) must be rejected');
assertNotNull(CpmsJalali.jalaliTextToIso('1300/01/01'), 'year 1300 (lower bound, inclusive) must be accepted');
assertNotNull(CpmsJalali.jalaliTextToIso('1499/01/01'), 'year 1499 (upper bound, inclusive) must be accepted');
assertEqual(CpmsJalali.classifyCompleteJalaliEntry('1299/01/01').reason, 'year', 'year 1299 reason is "year"');
assertEqual(CpmsJalali.classifyCompleteJalaliEntry('1500/01/01').reason, 'year', 'year 1500 reason is "year"');

// ===================== Out-of-range month / malformed shape =====================
assertNull(CpmsJalali.jalaliTextToIso('1404/13/01'), 'month 13 must be rejected');
assertEqual(CpmsJalali.classifyCompleteJalaliEntry('1404/13/01').reason, 'month', 'month 13 reason is "month"');
assertNull(CpmsJalali.jalaliTextToIso('1404/00/01'), 'month 0 must be rejected');
assertNull(CpmsJalali.jalaliTextToIso('not-a-date'), 'malformed text must be rejected');
assertEqual(CpmsJalali.jalaliEntryShapeComplete('1404/01'), false, '"1404/01" (still typing, 2 groups) is NOT a complete shape');
assertEqual(CpmsJalali.jalaliEntryShapeComplete('1404'), false, '"1404" (still typing, 1 group) is NOT a complete shape');
assertEqual(CpmsJalali.jalaliEntryShapeComplete('1404/01/01'), true, '"1404/01/01" IS a complete shape');

// ===================== Non-leap day-31/32 rejections in short months =====================
assertNull(CpmsJalali.jalaliTextToIso('1404/07/31'), 'day 31 in a 30-day month (Mehr) must be rejected');
assertNotNull(CpmsJalali.jalaliTextToIso('1404/07/30'), 'day 30 in a 30-day month (Mehr) must be accepted');
assertNull(CpmsJalali.jalaliTextToIso('1404/01/32'), 'day 32 is never valid, even in a 31-day month');

// ===================== Round-trip sanity: display and entry agree =====================
assertEqual(
  CpmsJalali.toPersianDigits(CpmsJalali.toJalali(2027, 2, 21).join('/')),
  CpmsJalali.toPersianDigits('1405/12/3'.split('/').map(Number).join('/')),
  'toJalali(2027-02-21) matches the 1405/12/3 entry used above'
);

// ===================== Scope: standalone Staff Portal shell (Owner B) =====================
// enhanceAll must ALSO match real calendar-date inputs mounted on the
// plugin-owned Staff Portal shell (<html dir="rtl">), not only CPMS admin
// `.wrap[dir="rtl"]` screens. Both scopes share ONE enhancement pass.
var capturedSelector = null;
var stubRoot = {
  querySelectorAll: function (sel) { capturedSelector = sel; return []; }
};
CpmsJalali.enhanceAll(stubRoot);
assertEqual(
  capturedSelector,
  '.wrap[dir="rtl"] input[type="date"], html[dir="rtl"] input[type="date"]',
  'enhanceAll covers the admin .wrap[dir=rtl] scope AND the standalone portal html[dir=rtl] scope'
);

if (failures.length > 0) {
  console.error('FAIL (' + failures.length + '):');
  failures.forEach(function (f) { console.error(' - ' + f); });
  process.exit(1);
}
console.log('OK: all ' + 'cpms-jalali-date.js logic assertions passed');
process.exit(0);

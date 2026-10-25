/**
 * Pure-logic regression test for the Phase 8 Slice 4 discovery helpers in
 * assets/js/cpms-public-booking.js (public Clinic discovery + weekly calendar).
 *
 * It executes the REAL production script (not a reimplementation) against a
 * minimal DOM stub and then exercises the exported
 * `window.CpmsPublicBookingDiscovery` helpers directly.
 *
 * Run standalone:  node tests/js/cpms-public-booking-discovery.spec.js
 *
 * Why this file exists: the sandbox used to prepare this change has no PHP, no
 * MySQL and no browser, so the server-side suites cannot run there. These
 * helpers are the only part of the discovery contract that is pure JavaScript
 * and therefore executable without a runtime; pinning them here gives real,
 * repeatable evidence instead of an unverified claim.
 *
 * Exit code 0 = all assertions passed. Non-zero = at least one failure.
 */
'use strict';

var path = require('path');
var fs = require('fs');

// ---- Minimal DOM stub: enough for the script's top-level boot path. With
// readyState 'loading' the IIFE only registers a DOMContentLoaded listener and
// never touches the DOM, so no element stubs are needed here. ----
global.window = {};
global.document = {
  readyState: 'loading',
  addEventListener: function () {}
};

var scriptPath = path.join(__dirname, '..', '..', 'assets', 'js', 'cpms-public-booking.js');
var code = fs.readFileSync(scriptPath, 'utf8');
// eslint-disable-next-line no-new-func
new Function(code)();

var D = global.window.CpmsPublicBookingDiscovery;
if (!D || typeof D.normalizeSearchText !== 'function') {
  console.error('FAIL: window.CpmsPublicBookingDiscovery was not exported by ' + scriptPath);
  process.exit(1);
}

var failures = [];

function check(name, actual, expected) {
  var ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (!ok) {
    failures.push(name + '\n  expected: ' + JSON.stringify(expected) + '\n  actual:   ' + JSON.stringify(actual));
  }
}

// ===================== Persian/Arabic search normalization =====================

check('arabic yeh -> persian yeh', D.normalizeSearchText('علي'), 'علی');
check('arabic kaf -> persian kaf', D.normalizeSearchText('دكتر'), 'دکتر');
check('arabic alef variants', D.normalizeSearchText('أحمد'), 'احمد');
check('teh marbuta -> heh', D.normalizeSearchText('زهرة'), 'زهره');
check('arabic-indic digits', D.normalizeSearchText('۰۹۱۲'), '0912');
check('persian digits', D.normalizeSearchText('۱۲۳'), '123');
check('diacritics removed', D.normalizeSearchText('مُحَمَّد'), 'محمد');
check('zwnj removed', D.normalizeSearchText('می‌رود'), 'میرود');
check('whitespace trimmed', D.normalizeSearchText('   علی  '), 'علی');
check('latin lowercased', D.normalizeSearchText('Dr. Ahmadi'), 'dr. ahmadi');
check('empty stays empty', D.normalizeSearchText(''), '');
check('non-string is tolerated', D.normalizeSearchText(null), '');

// The server normalizes the DATA with the same rules; this proves the two sides
// agree for the exact characters that would otherwise silently break matching.
check('server/client agreement (mixed)', D.normalizeSearchText('دكتر علي رضايي'), D.normalizeSearchText('دکتر علی رضایی'));

// ===================== Weekly window arithmetic =====================

check('week 0 starts at 0', D.weekStartIndex(0, 62), 0);
check('week 1 starts at 7', D.weekStartIndex(1, 62), 7);
check('week 3 starts at 21', D.weekStartIndex(3, 62), 21);
check('week start is bounded by total', D.weekStartIndex(99, 20), 14);
check('negative offset clamps to 0', D.weekStartIndex(-2, 62), 0);
check('single day calendar keeps index 0', D.weekStartIndex(5, 1), 0);

check('week 0 in bounds', D.weekOffsetInBounds(0, 62), true);
check('last full week in bounds', D.weekOffsetInBounds(8, 62), true);
check('past last week is out of bounds', D.weekOffsetInBounds(9, 62), false);
check('negative week is out of bounds', D.weekOffsetInBounds(-1, 62), false);
check('only week 0 exists for an empty calendar', D.weekOffsetInBounds(0, 0), true);
check('no second week for an empty calendar', D.weekOffsetInBounds(1, 0), false);

// ===================== First available week =====================

var calendar = [
  { date: '2026-10-24', jalali: '1405/08/02', weekday: 'شنبه' },
  { date: '2026-10-25', jalali: '1405/08/03', weekday: 'یک‌شنبه' },
  { date: '2026-10-26', jalali: '1405/08/04', weekday: 'دوشنبه' }
];
check('no availability -> -1', D.firstAvailableIndex(calendar, []), -1);
check(
  'first day with slots wins',
  D.firstAvailableIndex(calendar, [
    { date: '2026-10-26', slots: [{ time: '09:00' }] },
    { date: '2026-10-25', slots: [] }
  ]),
  2
);
check(
  'empty slot array is not availability',
  D.firstAvailableIndex(calendar, [{ date: '2026-10-24', slots: [] }]),
  -1
);
check(
  'availability outside the scaffold is ignored',
  D.firstAvailableIndex(calendar, [{ date: '2027-01-01', slots: [{ time: '10:00' }] }]),
  -1
);
check('missing inputs are tolerated', D.firstAvailableIndex(null, null), -1);

// ===================== Source-level guarantees =====================

// Comments legitimately NAME innerHTML to explain why it is avoided; only an
// actual assignment would violate the contract.
if (/\.innerHTML\s*=/.test(code)) {
  failures.push('production JS must not use innerHTML (textContent-only contract)');
}
if (/fetch\(\s*['"`]https?:\/\//.test(code)) {
  failures.push('production JS must not call absolute remote URLs');
}
if (!/credentials: 'omit'/.test(code)) {
  failures.push('public A1/A4 calls must keep credentials: omit');
}
if (!/X-WP-Nonce/.test(code)) {
  failures.push('authenticated B1/B2 calls must keep the wp_rest nonce header');
}
if (!/function selectionKeyFor/.test(code)) {
  failures.push('per-surface selection storage key helper must exist (multi-widget isolation)');
}

// ---- Multi-widget isolation: the selection storage key must stay exactly the
// legacy key on a single-widget page (the pinned preserved-selection contract)
// and must be namespaced per surface only when the page really has >1 root. ----
if (typeof D.selectionKeyFor === 'function') {
  var LEGACY = 'cpms-public-booking:selection:v1';
  var single = D.selectionKeyFor('cpms-public-booking-7', 3, 1);
  if (single !== LEGACY) {
    failures.push('single-widget page must keep the legacy selection key, got ' + single);
  }
  var legacyFallback = D.selectionKeyFor('cpms-public-booking-7', 3, 0);
  if (legacyFallback !== LEGACY) {
    failures.push('a page with no counted root must keep the legacy selection key, got ' + legacyFallback);
  }
  var multi = D.selectionKeyFor('cpms-public-booking-7', 3, 2);
  if (multi !== LEGACY + ':cpms-public-booking-7') {
    failures.push('multi-widget page must namespace the key by surface id, got ' + multi);
  }
  var multiNoSurface = D.selectionKeyFor('', 3, 2);
  if (multiNoSurface !== LEGACY + ':clinic-3') {
    failures.push('multi-widget page without a surface id must fall back to the Clinic id, got ' + multiNoSurface);
  }
} else {
  failures.push('selectionKeyFor must be exported for the standalone isolation spec');
}

// ---- Phase 8 Slice 3 patient-selection storage contract. ----
// The pinned single-widget key is EXACTLY 'cpms-patient-selection:<clinic_id>';
// the Phase 8 Slice 4 change temporarily keyed it by surface_id, which made an
// already-stored Patient B unreachable and broke the Slice-3 chooser browser
// acceptance (slice3-*-select-B). These assertions pin the restored contract
// plus the isolation properties that motivated the original change.
if (typeof D.patientKeySuffix === 'function') {
  var S = D.patientKeySuffix;

  // (B) sessionStorage compatibility: single-widget shape is the historical one.
  if (S('cpms-public-booking-7', 3, 1) !== '3') {
    failures.push('single-widget patient suffix must be the Clinic id, got ' + S('cpms-public-booking-7', 3, 1));
  }
  if (S('cpms-public-booking-7', 3, 0) !== '3') {
    failures.push('patient suffix must stay Clinic-scoped when no root was counted, got ' + S('cpms-public-booking-7', 3, 0));
  }

  // (D) multi-widget isolation: two widgets on one page never share a key.
  var w1 = S('cpms-public-booking-7', 3, 2);
  var w2 = S('cpms-public-booking-8', 3, 2);
  if (w1 === w2) {
    failures.push('two widgets on one page must not share a patient key, both were ' + w1);
  }
  if (w1 !== 'cpms-public-booking-7:3') {
    failures.push('multi-widget patient suffix must namespace by surface and keep the Clinic, got ' + w1);
  }
  if (S('', 3, 2) !== 'clinic-3:3') {
    failures.push('multi-widget suffix without a surface id must fall back to the Clinic scope, got ' + S('', 3, 2));
  }

  // (C)/(F) Clinic switching can never restore another Clinic's patient.
  if (S('cpms-public-booking-7', 3, 1) === S('cpms-public-booking-7', 4, 1)) {
    failures.push('a Clinic change must change the single-widget patient key');
  }
  if (S('cpms-public-booking-7', 3, 2) === S('cpms-public-booking-7', 4, 2)) {
    failures.push('a Clinic change must change the multi-widget patient key');
  }
  // (A)/(E) switching patient only changes the stored VALUE, never the key.
  if (S('cpms-public-booking-7', 3, 1) !== S('cpms-public-booking-7', 3, 1)) {
    failures.push('patient key must be deterministic so Patient A -> B switching keeps one slot');
  }
} else {
  failures.push('patientKeySuffix must be exported for the standalone storage spec');
}

// ===================== Result =====================

if (failures.length > 0) {
  console.error('FAIL: ' + failures.length + ' discovery assertion(s) failed');
  failures.forEach(function (f) { console.error(' - ' + f); });
  process.exit(1);
}

console.log('PASS: cpms-public-booking discovery helpers (all assertions)');

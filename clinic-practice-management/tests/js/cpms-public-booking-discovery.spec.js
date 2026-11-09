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

// ---- M5 clinic-scoped patient session matcher ----
if (typeof D.sessionMatchesClinic === 'function') {
  check('matching positive clinic ids', D.sessionMatchesClinic(3, 3), true);
  check('numeric string clinic ids match', D.sessionMatchesClinic('3', 3), true);
  check('different clinic ids do not match', D.sessionMatchesClinic(3, 5), false);
  check('null session clinic never matches', D.sessionMatchesClinic(null, 3), false);
  check('zero session clinic never matches', D.sessionMatchesClinic(0, 3), false);
  check('zero target clinic never matches', D.sessionMatchesClinic(3, 0), false);
} else {
  failures.push('sessionMatchesClinic must be exported for the M5 session gate spec');
}

// ---- M5 interactive DOM flows: discovery clinic switch + OTP gate + CLINIC_SCOPE_REQUIRED fallback ----
var vm = require('vm');

function DomNode(tag) {
  this.nodeType = 1;
  this.tagName = String(tag || 'div').toUpperCase();
  this._attrs = {};
  this.children = [];
  this.parentElement = null;
  this.disabled = false;
  this.value = '';
  this.selectedIndex = 0;
  this._text = '';
  this.listeners = {};
}
Object.defineProperty(DomNode.prototype, 'hidden', {
  get: function () { return Object.prototype.hasOwnProperty.call(this._attrs, 'hidden'); },
  set: function (v) { if (v) { this._attrs.hidden = ''; } else { delete this._attrs.hidden; } }
});
Object.defineProperty(DomNode.prototype, 'textContent', {
  get: function () {
    if (this.children.length === 0) { return this._text; }
    return this.children.map(function (c) { return c.textContent; }).join('');
  },
  set: function (v) { this.children = []; this._text = String(v); }
});
Object.defineProperty(DomNode.prototype, 'firstChild', {
  get: function () { return this.children.length ? this.children[0] : null; }
});
Object.defineProperty(DomNode.prototype, 'firstElementChild', {
  get: function () { return this.children.length ? this.children[0] : null; }
});
Object.defineProperty(DomNode.prototype, 'options', {
  get: function () {
    return this.children.filter(function (c) { return c.tagName === 'OPTION'; });
  }
});
DomNode.prototype.getAttribute = function (n) {
  return Object.prototype.hasOwnProperty.call(this._attrs, n) ? this._attrs[n] : null;
};
DomNode.prototype.setAttribute = function (n, v) { this._attrs[n] = String(v); };
DomNode.prototype.hasAttribute = function (n) { return Object.prototype.hasOwnProperty.call(this._attrs, n); };
DomNode.prototype.removeAttribute = function (n) { delete this._attrs[n]; };
DomNode.prototype.focus = function () {};
DomNode.prototype.appendChild = function (c) { c.parentElement = this; this.children.push(c); return c; };
DomNode.prototype.removeChild = function (c) {
  var idx = this.children.indexOf(c);
  if (idx >= 0) { this.children.splice(idx, 1); }
  c.parentElement = null;
  return c;
};
function domMatchSimple(node, sel) {
  sel = String(sel || '').trim();
  if (!sel || !node || node.nodeType !== 1) { return false; }
  var tag = sel.match(/^[a-zA-Z0-9]+/);
  if (tag && node.tagName.toLowerCase() !== tag[0].toLowerCase()) { return false; }
  var classes = sel.match(/\.[a-zA-Z0-9_-]+/g) || [];
  for (var i = 0; i < classes.length; i++) {
    if (String(node.getAttribute('class') || '').split(/\s+/).indexOf(classes[i].slice(1)) === -1) { return false; }
  }
  var attrRe = /\[([a-zA-Z0-9_:-]+)(?:="([^"]*)")?\]/g;
  var m;
  while ((m = attrRe.exec(sel)) !== null) {
    if (!node.hasAttribute(m[1])) { return false; }
    if (m[2] !== undefined && node.getAttribute(m[1]) !== m[2]) { return false; }
  }
  return true;
}
function domMatches(node, selector) {
  var groups = String(selector).split(',');
  for (var g = 0; g < groups.length; g++) {
    var parts = groups[g].trim().split(/\s+/).filter(Boolean);
    if (!parts.length || !domMatchSimple(node, parts[parts.length - 1])) { continue; }
    var cur = node.parentElement;
    var ok = true;
    for (var p = parts.length - 2; p >= 0; p--) {
      while (cur && !domMatchSimple(cur, parts[p])) { cur = cur.parentElement; }
      if (!cur) { ok = false; break; }
      cur = cur.parentElement;
    }
    if (ok) { return true; }
  }
  return false;
}
DomNode.prototype.matches = function (s) { return domMatches(this, s); };
function domDesc(node, out) {
  for (var i = 0; i < node.children.length; i++) { out.push(node.children[i]); domDesc(node.children[i], out); }
  return out;
}
DomNode.prototype.querySelectorAll = function (s) { return domDesc(this, []).filter(function (n) { return domMatches(n, s); }); };
DomNode.prototype.querySelector = function (s) { var a = this.querySelectorAll(s); return a.length ? a[0] : null; };
DomNode.prototype.contains = function (o) { var c = o; while (c) { if (c === this) { return true; } c = c.parentElement; } return false; };
DomNode.prototype.closest = function (s) { var c = this; while (c) { if (domMatches(c, s)) { return c; } c = c.parentElement; } return null; };
DomNode.prototype.cloneNode = function (deep) {
  var cp = new DomNode(this.tagName);
  var self = this;
  Object.keys(this._attrs).forEach(function (k) { cp._attrs[k] = self._attrs[k]; });
  cp.disabled = this.disabled; cp.value = this.value; cp._text = this._text;
  if (deep) { this.children.forEach(function (c) { cp.appendChild(c.cloneNode(true)); }); }
  return cp;
};
DomNode.prototype.addEventListener = function (t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); };
DomNode.prototype.dispatch = function (t) {
  var ev = { type: t, target: this };
  var c = this;
  while (c) { (c.listeners[t] || []).forEach(function (fn) { fn(ev); }); c = c.parentElement; }
};
function mk(tag, attrs, kids) {
  var n = new DomNode(tag);
  Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
  (kids || []).forEach(function (k) { n.appendChild(k); });
  return n;
}
function buildM5Surface(cfg) {
  var opt3 = mk('option', { value: '3' }); opt3.value = '3';
  var opt5 = mk('option', { value: '5' }); opt5.value = '5';
  var select = mk('select', { 'data-role': 'clinic-select' }, [opt3, opt5]);
  select.value = '3';
  var doc3 = mk('li', { 'class': 'cpms-public-booking__clinician-item', 'data-clinic-id': '3', 'data-locations': '101' }, [
    mk('button', { 'type': 'button', 'class': 'cpms-public-booking__clinician', 'data-clinician-id': '11', 'data-clinic-id': '3', 'aria-pressed': 'false' })
  ]);
  var doc5 = mk('li', { 'class': 'cpms-public-booking__clinician-item', 'data-clinic-id': '5', 'data-locations': '201' }, [
    mk('button', { 'type': 'button', 'class': 'cpms-public-booking__clinician', 'data-clinician-id': '22', 'data-clinic-id': '5', 'aria-pressed': 'false' })
  ]);
  var locTpl = mk('template', { 'data-template': 'location-option' });
  locTpl.content = mk('frag', {}, [mk('li', {}, [mk('button', { 'type': 'button', 'data-role': 'location-option', 'aria-pressed': 'false' }, [mk('span', { 'data-fill': 'name' }), mk('span', { 'data-fill': 'address' })])])]);
  var dayTpl = mk('template', { 'data-template': 'day' });
  dayTpl.content = mk('frag', {}, [mk('div', {}, [mk('p', { 'data-fill': 'jalali' }), mk('div', { 'data-host': 'slots' })])]);
  var slotTpl = mk('template', { 'data-template': 'slot' });
  slotTpl.content = mk('frag', {}, [mk('button', { 'type': 'button', 'class': 'cpms-public-booking__slot' }, [mk('span', { 'data-fill': 'time' })])]);
  var cfgScript = mk('script', { 'type': 'application/json', 'class': 'cpms-public-booking__config' });
  cfgScript.textContent = JSON.stringify(cfg);

  var children = [];
  if (cfg.mode === 'discovery') {
    children.push(select);
  }
  children.push(
    mk('div', { 'data-role': 'location-picker', 'hidden': '' }, [mk('ul', { 'data-role': 'location-options' })]),
    mk('ul', { 'data-role': 'clinicians' }, [doc3, doc5]),
    mk('div', { 'data-role': 'panel', 'data-state': 'idle' }, [mk('p', { 'data-role': 'detail', 'hidden': '' }), mk('div', { 'data-role': 'days' })]),
    mk('div', { 'class': 'cpms-public-booking__auth', 'data-role': 'otp-step', 'hidden': '' }, [
      mk('button', { 'type': 'button', 'data-role': 'continue-auth' }),
      mk('div', { 'data-auth-step': 'otp-mobile', 'hidden': '' }),
      mk('div', { 'data-auth-step': 'otp-code', 'hidden': '' }),
      mk('p', { 'data-role': 'auth-status', 'hidden': '' })
    ]),
    mk('div', { 'data-role': 'patient-chooser', 'data-clinic-id': '3' }, [
      mk('button', { 'type': 'button', 'data-role': 'patient-option', 'data-patient-id': '701', 'aria-pressed': 'false' }),
      mk('button', { 'type': 'button', 'data-role': 'patient-option', 'data-patient-id': '702', 'aria-pressed': 'false' })
    ]),
    mk('div', { 'data-role': 'booking-continue', 'hidden': '' }, [
      mk('p', { 'data-role': 'continue-status', 'hidden': '' }),
      mk('p', { 'data-role': 'hold-countdown', 'hidden': '' }, [mk('b', { 'data-role': 'countdown-value' })]),
      mk('div', { 'data-role': 'names-form', 'hidden': '' }),
      mk('div', { 'data-role': 'reason-wrap', 'hidden': '' }),
      mk('button', { 'type': 'button', 'data-role': 'confirm-btn', 'hidden': '' }),
      mk('div', { 'data-role': 'receipt', 'hidden': '' }),
      mk('div', { 'data-role': 'nearby', 'hidden': '' }, [mk('div', { 'data-role': 'nearby-list' })])
    ]),
    locTpl, dayTpl, slotTpl, cfgScript
  );
  return mk('div', { 'class': 'cpms-public-booking', 'data-clinic-id': cfg.mode === 'discovery' ? '0' : String(cfg.clinic_id) }, children);
}

function bootSandbox(root, handler) {
  var doc = {
    readyState: 'complete',
    querySelectorAll: function (s) {
      var out = [];
      if (domMatches(root, s)) { out.push(root); }
      domDesc(root, []).forEach(function (n) { if (domMatches(n, s)) { out.push(n); } });
      return out;
    },
    querySelector: function (s) { var a = this.querySelectorAll(s); return a.length ? a[0] : null; },
    addEventListener: function () {}
  };
  var calls = [];
  var win = {
    document: doc,
    Promise: Promise,
    setInterval: function () { return 1; },
    clearInterval: function () {},
    sessionStorage: {
      s: {},
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(this.s, k) ? this.s[k] : null; },
      setItem: function (k, v) { this.s[k] = String(v); },
      removeItem: function (k) { delete this.s[k]; }
    },
    fetch: function (url, opts) {
      calls.push({ url: String(url), opts: opts || {} });
      var res = handler(String(url), opts || {});
      return Promise.resolve({
        ok: res.status >= 200 && res.status < 300,
        status: res.status,
        json: function () { return Promise.resolve(res.body); }
      });
    }
  };
  var ctx = vm.createContext({ window: win, document: doc, console: console, setTimeout: setTimeout, clearTimeout: clearTimeout });
  vm.runInContext(code, ctx);
  return { root: root, calls: calls, win: win };
}

function tick() { return new Promise(function (r) { setTimeout(r, 0); }); }

function runPublicSpecialtyFilterSpecs() {
  var root = buildM5Surface({
    surface_id: 'pb-specialty-filter',
    mode: 'single',
    clinic_id: 3,
    rest_root: 'https://clinic.test/wp-json/clinic/v1',
    availability_path: '/availability',
    quote_path: '/booking/quote',
    hold_path: '/booking/hold',
    confirm_path: '/booking/confirm',
    session_clinic_id: 0,
    locations_by_clinic: {}
  });
  var list = root.querySelector('[data-role="clinicians"]');
  var items = list.querySelectorAll('.cpms-public-booking__clinician-item');
  var aliasItem = items[0];
  aliasItem.setAttribute('data-specialty', 'cardiology');

  function addClinician(id, specialty) {
    return list.appendChild(mk('li', {
      'class': 'cpms-public-booking__clinician-item',
      'data-clinic-id': '3',
      'data-specialty': specialty,
      'data-search': ''
    }, [mk('button', {
      'type': 'button',
      'class': 'cpms-public-booking__clinician',
      'data-clinician-id': String(id),
      'data-clinic-id': '3',
      'aria-pressed': 'false'
    })]));
  }

  var canonicalItem = addClinician(33, 'متخصص قلب و عروق');
  var customItem = addClinician(34, 'فلوشیپ ناباروری');
  var doctorSearch = mk('input', { 'data-role': 'doctor-search' });
  var specialtyFilter = mk('select', { 'data-role': 'specialty-filter' });
  var aliasOption = mk('option', { value: 'cardiology' });
  aliasOption.value = 'cardiology';
  aliasOption.textContent = 'متخصص قلب و عروق';
  specialtyFilter.appendChild(aliasOption);
  root.appendChild(doctorSearch);
  root.appendChild(specialtyFilter);

  bootSandbox(root, function () { return { status: 404, body: { ok: false } }; });

  specialtyFilter.value = 'cardiology';
  specialtyFilter.dispatch('change');
  check(
    'specialty alias filter uses raw catalog key, not Persian label',
    [aliasItem.hidden, canonicalItem.hidden, customItem.hidden],
    [false, true, true]
  );
  check(
    'alias filter leaves clinician selection identifiers and state intact',
    [aliasItem.querySelector('button').getAttribute('data-clinician-id'), aliasItem.querySelector('button').getAttribute('aria-pressed')],
    ['11', 'false']
  );

  specialtyFilter.value = 'متخصص قلب و عروق';
  specialtyFilter.dispatch('change');
  check(
    'canonical Persian filter key selects its matching raw value',
    [aliasItem.hidden, canonicalItem.hidden, customItem.hidden],
    [true, false, true]
  );

  specialtyFilter.value = 'فلوشیپ ناباروری';
  specialtyFilter.dispatch('change');
  check(
    'custom specialty filter key remains selectable',
    [aliasItem.hidden, canonicalItem.hidden, customItem.hidden],
    [true, true, false]
  );
}

(async function runM5Specs() {
  runPublicSpecialtyFilterSpecs();

  // 1. Discovery mode: session_clinic_id=3 allows hold in Clinic 3, then switching to Clinic 5
  //    clears hold/patient/doctor/slot state, hides Clinic 3 chooser, unhides OTP step, and gates B1.
  var discRoot = buildM5Surface({
    surface_id: 'pb-m5-disc',
    mode: 'discovery',
    clinic_id: 0,
    rest_root: 'https://clinic.test/wp-json/clinic/v1',
    availability_path: '/availability',
    quote_path: '/booking/quote',
    nonce: 'nonce-123',
    hold_path: '/booking/hold',
    confirm_path: '/booking/confirm',
    session_clinic_id: 3,
    otp_request_path: '/otp/request',
    otp_verify_path: '/otp/verify',
    locations_by_clinic: {
      '3': [{ id: 101, name: 'شعبه ۳', address: '' }],
      '5': [{ id: 201, name: 'شعبه ۵', address: '' }]
    }
  });
  var sb = bootSandbox(discRoot, function (url) {
    if (url.indexOf('/availability') !== -1 && url.indexOf('clinic_id=3') !== -1) {
      return { status: 200, body: { ok: true, data: { days: [{ date: '2026-11-10', jalali: '1405/08/19', slots: [{ slot_id: 901, location_id: 101, time: '09:00', duration_min: 20, capacity_left: 1 }] }] } } };
    }
    if (url.indexOf('/availability') !== -1 && url.indexOf('clinic_id=5') !== -1) {
      return { status: 200, body: { ok: true, data: { days: [{ date: '2026-11-10', jalali: '1405/08/19', slots: [{ slot_id: 902, location_id: 201, time: '10:00', duration_min: 20, capacity_left: 1 }] }] } } };
    }
    if (url.indexOf('/booking/quote') !== -1) {
      var loc = url && sb.calls[sb.calls.length - 1].opts.body.indexOf('902') !== -1 ? 201 : 101;
      return { status: 200, body: { ok: true, data: { available: true, location_id: loc, capacity_left: 1 } } };
    }
    if (url.indexOf('/booking/hold') !== -1) {
      return { status: 200, body: { ok: true, data: { hold_token: 'ht-3', expires_at: '2099-01-01 00:00:00' } } };
    }
    return { status: 404, body: { ok: false } };
  });
  await tick();

  var otpStep = discRoot.querySelector('[data-role="otp-step"]');
  var chooser3 = discRoot.querySelector('[data-role="patient-chooser"]');
  check('discovery Clinic 3 matching session hides OTP step', otpStep.hidden, true);
  check('discovery Clinic 3 matching session shows Clinic 3 chooser', chooser3.hidden, false);

  discRoot.querySelector('[data-patient-id="702"]').dispatch('click');
  discRoot.querySelector('[data-clinician-id="11"]').dispatch('click');
  await tick(); await tick();
  discRoot.querySelector('.cpms-public-booking__slot').dispatch('click');
  await tick(); await tick(); await tick();

  var holdCalls = sb.calls.filter(function (c) { return c.url.indexOf('/booking/hold') !== -1; });
  check('matching sessionClinicId=3 invokes /booking/hold', holdCalls.length, 1);
  check('confirm button visible after Clinic 3 hold', discRoot.querySelector('[data-role="confirm-btn"]').hidden, false);

  // Switch to Clinic 5 (session_clinic_id=3 != 5).
  var sel = discRoot.querySelector('[data-role="clinic-select"]');
  sel.value = '5';
  sel.selectedIndex = 1;
  sel.dispatch('change');
  await tick();

  check('switching to Clinic 5 hides Clinic 3 chooser', chooser3.hidden, true);
  check('switching to Clinic 5 unhides OTP step', otpStep.hidden, false);
  check('switching to Clinic 5 hides confirm button from Clinic 3 hold', discRoot.querySelector('[data-role="confirm-btn"]').hidden, true);
  check('switching to Clinic 5 hides continueBox from Clinic 3 hold', discRoot.querySelector('[data-role="booking-continue"]').hidden, true);

  discRoot.querySelector('[data-clinician-id="22"]').dispatch('click');
  await tick(); await tick();
  discRoot.querySelector('.cpms-public-booking__slot').dispatch('click');
  await tick(); await tick(); await tick();

  var holdCallsAfter = sb.calls.filter(function (c) { return c.url.indexOf('/booking/hold') !== -1; });
  check('mismatched Clinic 5 does NOT call /booking/hold before OTP', holdCallsAfter.length, 1);
  check('OTP step stays visible for Clinic 5 slot continuation', otpStep.hidden, false);

  // 2. Server fallback: if /booking/hold returns CLINIC_SCOPE_REQUIRED (e.g. rotated cookie in another tab),
  //    JS opens OTP step with server message and preserves stored selection.
  var fallbackRoot = buildM5Surface({
    surface_id: 'pb-m5-fb',
    mode: 'single',
    clinic_id: 3,
    rest_root: 'https://clinic.test/wp-json/clinic/v1',
    availability_path: '/availability',
    quote_path: '/booking/quote',
    nonce: 'nonce-fb',
    hold_path: '/booking/hold',
    confirm_path: '/booking/confirm',
    session_clinic_id: 3,
    otp_request_path: '/otp/request',
    otp_verify_path: '/otp/verify',
    locations_by_clinic: { '3': [{ id: 101, name: 'شعبه ۳', address: '' }] }
  });
  var sb2 = bootSandbox(fallbackRoot, function (url) {
    if (url.indexOf('/availability') !== -1) {
      return { status: 200, body: { ok: true, data: { days: [{ date: '2026-11-10', jalali: '1405/08/19', slots: [{ slot_id: 903, location_id: 101, time: '11:00', duration_min: 20, capacity_left: 1 }] }] } } };
    }
    if (url.indexOf('/booking/quote') !== -1) {
      return { status: 200, body: { ok: true, data: { available: true, location_id: 101, capacity_left: 1 } } };
    }
    if (url.indexOf('/booking/hold') !== -1) {
      return { status: 400, body: { ok: false, code: 'CLINIC_SCOPE_REQUIRED', message: 'برای ادامه در این مرکز، احراز هویت با کد یک‌بارمصرف الزامی است' } };
    }
    return { status: 404, body: { ok: false } };
  });
  await tick();
  fallbackRoot.querySelector('[data-clinician-id="11"]').dispatch('click');
  await tick(); await tick();
  fallbackRoot.querySelector('.cpms-public-booking__slot').dispatch('click');
  await tick(); await tick(); await tick();

  var fbOtp = fallbackRoot.querySelector('[data-role="otp-step"]');
  var fbMsg = fallbackRoot.querySelector('[data-role="auth-status"]');
  check('CLINIC_SCOPE_REQUIRED fallback reveals OTP step', fbOtp.hidden, false);
  check('CLINIC_SCOPE_REQUIRED fallback displays server message', fbMsg.textContent, 'برای ادامه در این مرکز، احراز هویت با کد یک‌بارمصرف الزامی است');
  check('CLINIC_SCOPE_REQUIRED fallback preserves stored selection', sb2.win.sessionStorage.getItem('cpms-public-booking:selection:v1') !== null, true);

  // ===================== Result =====================

  if (failures.length > 0) {
    console.error('FAIL: ' + failures.length + ' discovery assertion(s) failed');
    failures.forEach(function (f) { console.error(' - ' + f); });
    process.exit(1);
  }

  console.log('PASS: cpms-public-booking discovery helpers (all assertions)');
})().catch(function (err) {
  console.error('FAIL: unexpected error in discovery spec: ' + (err && err.stack ? err.stack : String(err)));
  process.exit(1);
});

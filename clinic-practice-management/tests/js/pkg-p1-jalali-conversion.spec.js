/**
 * Shared-fixture conversion and widget-hygiene spec for
 * clinic-practice-management/assets/js/cpms-jalali-date.js.
 *
 * Reads tests/fixtures/jalali-intl-vectors.json (generated from Node ICU Intl
 * calendar=persian) and verifies:
 *  1. All fixture vectors (anchors, 200 Nowruz dates 1300..1499, 192 month-end
 *     dates 1395..1410, >=1000 deterministic sample days across 1300..1499).
 *  2. Full day-by-day round-trip jalali -> gregorian -> jalali across all
 *     73,049 days of 1300..1499 with +1 UTC day monotonicity.
 *  3. Month lengths across 1300..1499 (months 1..6 = 31, 7..11 = 30,
 *     12 = 29 or 30 matching ICU leap_years).
 *  4. jalaliTextToIso rejecting day 31 in month 7 and day 30 of Esfand in
 *     non-leap years (including 1401 and 1405), plus strict range 1300..1499
 *     returning null/empty outside range rather than a wrong date.
 *  5. form.reset() syncing the visible Shamsi entry to the native reset value,
 *     and window-level guard preventing double enhancement on second script load.
 */
'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var scriptPath = path.join(__dirname, '..', '..', 'assets', 'js', 'cpms-jalali-date.js');
var fixturePath = path.join(__dirname, '..', 'fixtures', 'jalali-intl-vectors.json');

var source = fs.readFileSync(scriptPath, 'utf8');
var fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

function pad2(n) {
  return (n < 10 ? '0' : '') + n;
}

function makeMiniDom() {
  var observers = [];

  function CustomEventStub(type, opts) {
    this.type = type;
    this.bubbles = Boolean(opts && opts.bubbles);
    this.cancelable = Boolean(opts && opts.cancelable);
    this.defaultPrevented = false;
    this.target = null;
  }
  CustomEventStub.prototype.preventDefault = function () {
    this.defaultPrevented = true;
  };

  function makeClassList(el) {
    return {
      add: function (cls) {
        var parts = (el.className || '').split(/\s+/).filter(Boolean);
        if (parts.indexOf(cls) === -1) { parts.push(cls); }
        el.className = parts.join(' ');
      },
      contains: function (cls) {
        return (el.className || '').split(/\s+/).indexOf(cls) !== -1;
      }
    };
  }

  function matchesSingle(el, sel) {
    sel = sel.trim();
    if (sel === 'input[type="date"]') {
      return el.tagName === 'INPUT' && String(el.type || '').toLowerCase() === 'date';
    }
    if (sel === '.wrap[dir="rtl"]') {
      return el.classList.contains('wrap') && el.getAttribute('dir') === 'rtl';
    }
    if (sel === 'html[dir="rtl"]') {
      return el.tagName === 'HTML' && el.getAttribute('dir') === 'rtl';
    }
    return false;
  }

  function hasAncestor(el, ancestorSel) {
    var p = el.parentNode;
    while (p && p.nodeType === 1) {
      if (matchesSingle(p, ancestorSel)) { return true; }
      p = p.parentNode;
    }
    return false;
  }

  function matchesSelector(el, selectorGroup) {
    var groups = selectorGroup.split(',');
    for (var i = 0; i < groups.length; i += 1) {
      var parts = groups[i].trim().split(/\s+/);
      if (parts.length === 1 && matchesSingle(el, parts[0])) { return true; }
      if (parts.length === 2 && matchesSingle(el, parts[1]) && hasAncestor(el, parts[0])) { return true; }
    }
    return false;
  }

  var docListeners = Object.create(null);

  function ElementStub(tagName) {
    this.nodeType = 1;
    this.tagName = String(tagName || '').toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.attributes = Object.create(null);
    this.dataset = Object.create(null);
    this.listeners = Object.create(null);
    this.className = '';
    this.id = '';
    this.type = '';
    this._value = '';
    this.defaultValue = '';
    this.textContent = '';
    this.hidden = false;
    this.tabIndex = 0;
    this.customValidity = '';
    this.classList = makeClassList(this);
  }

  Object.defineProperty(ElementStub.prototype, 'value', {
    configurable: true,
    enumerable: true,
    get: function () { return this._value; },
    set: function (v) { this._value = String(v == null ? '' : v); }
  });

  function dashToCamel(name) {
    return name.replace(/-([a-z])/g, function (_, ch) { return ch.toUpperCase(); });
  }

  ElementStub.prototype.setAttribute = function (name, val) {
    var v = String(val);
    this.attributes[name] = v;
    if (name === 'id') { this.id = v; }
    if (name === 'class') { this.className = v; }
    if (name === 'type') { this.type = v; }
    if (name === 'value') {
      this.defaultValue = v;
      this._value = v;
    }
    if (name.indexOf('data-') === 0) {
      this.dataset[dashToCamel(name.slice(5))] = v;
    }
  };
  ElementStub.prototype.getAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
  };
  ElementStub.prototype.removeAttribute = function (name) {
    delete this.attributes[name];
    if (name.indexOf('data-') === 0) {
      delete this.dataset[dashToCamel(name.slice(5))];
    }
  };
  ElementStub.prototype.appendChild = function (child) {
    if (child.parentNode) {
      var idx = child.parentNode.children.indexOf(child);
      if (idx !== -1) { child.parentNode.children.splice(idx, 1); }
    }
    child.parentNode = this;
    this.children.push(child);
    return child;
  };
  ElementStub.prototype.insertBefore = function (newNode, refNode) {
    if (newNode.parentNode) {
      var oldIdx = newNode.parentNode.children.indexOf(newNode);
      if (oldIdx !== -1) { newNode.parentNode.children.splice(oldIdx, 1); }
    }
    var idx = this.children.indexOf(refNode);
    newNode.parentNode = this;
    if (idx === -1) { this.children.push(newNode); } else { this.children.splice(idx, 0, newNode); }
    return newNode;
  };
  ElementStub.prototype.addEventListener = function (type, fn) {
    if (!this.listeners[type]) { this.listeners[type] = []; }
    this.listeners[type].push(fn);
  };
  ElementStub.prototype.dispatchEvent = function (ev) {
    ev.target = ev.target || this;
    var list = (this.listeners[ev.type] || []).slice();
    for (var i = 0; i < list.length; i += 1) {
      list[i].call(this, ev);
    }
    if (ev.bubbles && this.parentNode) {
      if (this.parentNode.dispatchEvent) {
        this.parentNode.dispatchEvent(ev);
      }
    } else if (ev.bubbles && !this.parentNode) {
      var dList = (docListeners[ev.type] || []).slice();
      for (var d = 0; d < dList.length; d += 1) {
        dList[d](ev);
      }
    }
    return !ev.defaultPrevented;
  };
  ElementStub.prototype.setCustomValidity = function (msg) {
    this.customValidity = String(msg || '');
  };
  ElementStub.prototype.focus = function () {};
  ElementStub.prototype.contains = function (other) {
    var cur = other;
    while (cur) {
      if (cur === this) { return true; }
      cur = cur.parentNode;
    }
    return false;
  };
  ElementStub.prototype.querySelectorAll = function (selectorGroup) {
    var out = [];
    function walk(node) {
      for (var i = 0; i < node.children.length; i += 1) {
        var c = node.children[i];
        if (matchesSelector(c, selectorGroup)) { out.push(c); }
        walk(c);
      }
    }
    walk(this);
    return out;
  };
  ElementStub.prototype.reset = function () {
    if (this.tagName !== 'FORM') { return; }
    var ev = new CustomEventStub('reset', { bubbles: true, cancelable: true });
    this.dispatchEvent(ev);
    if (ev.defaultPrevented) { return; }
    function restoreDefaults(node) {
      for (var i = 0; i < node.children.length; i += 1) {
        var c = node.children[i];
        if (c.tagName === 'INPUT' && String(c.type || '').toLowerCase() === 'date') {
          // Browser native form.reset() restores underlying control value
          // directly without invoking JS property setter or input/change events.
          c._value = c.defaultValue || '';
        }
        restoreDefaults(c);
      }
    }
    restoreDefaults(this);
  };

  var html = new ElementStub('html');
  html.setAttribute('dir', 'rtl');
  var body = new ElementStub('body');
  html.appendChild(body);

  var doc = {
    readyState: 'complete',
    documentElement: html,
    body: body,
    createElement: function (tag) { return new ElementStub(tag); },
    querySelectorAll: function (sel) { return html.querySelectorAll(sel); },
    addEventListener: function (type, fn) {
      if (!docListeners[type]) { docListeners[type] = []; }
      docListeners[type].push(fn);
    },
    removeEventListener: function () {}
  };
  html.parentNode = {
    dispatchEvent: function (ev) {
      var dList = (docListeners[ev.type] || []).slice();
      for (var i = 0; i < dList.length; i += 1) { dList[i](ev); }
    }
  };

  function MutationObserverStub(cb) {
    this.cb = cb;
    observers.push(this);
  }
  MutationObserverStub.prototype.observe = function () {};
  MutationObserverStub.prototype.takeRecords = function () { return []; };

  var sandbox = {
    window: {},
    document: doc,
    Event: CustomEventStub,
    MutationObserver: MutationObserverStub,
    Date: Date,
    Math: Math,
    Object: Object,
    String: String,
    Number: Number,
    Boolean: Boolean,
    Array: Array,
    RegExp: RegExp,
    Error: Error,
    Promise: Promise,
    parseInt: parseInt,
    isNaN: isNaN,
    setTimeout: function (fn) { fn(); return 1; },
    clearTimeout: function () {}
  };
  sandbox.window.document = doc;
  sandbox.window.Event = CustomEventStub;
  sandbox.window.MutationObserver = MutationObserverStub;

  return { sandbox: sandbox, doc: doc, body: body, observers: observers, Event: CustomEventStub };
}

var failures = [];
function check(name, fn) {
  try {
    fn();
  } catch (err) {
    failures.push(name + ': ' + (err && err.message ? err.message : String(err)));
  }
}

var baseEnv = makeMiniDom();
vm.createContext(baseEnv.sandbox);
vm.runInContext(source, baseEnv.sandbox, { filename: 'cpms-jalali-date.js' });
var CpmsJalali = baseEnv.sandbox.window.CpmsJalali;

check('fixture header records ICU version and required vector counts', function () {
  assert.ok(fixture && fixture.meta && typeof fixture.meta.icu_version === 'string' && fixture.meta.icu_version.length > 0);
  assert.strictEqual(fixture.nowruz.length, 200);
  assert.strictEqual(fixture.month_ends_1395_1410.length, 192);
  assert.ok(fixture.samples.length >= 1000);
});

function toHostArray(arr) {
  return arr ? [arr[0], arr[1], arr[2]] : arr;
}

function verifyVectorList(list, groupLabel) {
  for (var i = 0; i < list.length; i += 1) {
    var v = list[i];
    var gotJ = toHostArray(CpmsJalali.toJalali(v.gy, v.gm, v.gd));
    assert.deepStrictEqual(
      gotJ,
      [v.jy, v.jm, v.jd],
      groupLabel + ' toJalali(' + v.iso + ') expected ' + v.jy + '/' + v.jm + '/' + v.jd + ' got ' + JSON.stringify(gotJ)
    );
    var gotG = toHostArray(CpmsJalali.toGregorian(v.jy, v.jm, v.jd));
    assert.deepStrictEqual(
      gotG,
      [v.gy, v.gm, v.gd],
      groupLabel + ' toGregorian(' + v.jy + '/' + v.jm + '/' + v.jd + ') expected ' + v.iso + ' got ' + JSON.stringify(gotG)
    );
    var jText = v.jy + '/' + pad2(v.jm) + '/' + pad2(v.jd);
    assert.strictEqual(CpmsJalali.jalaliTextToIso(jText), v.iso, groupLabel + ' jalaliTextToIso(' + jText + ')');
    assert.strictEqual(
      CpmsJalali.jalaliTextToIso(CpmsJalali.toPersianDigits(jText)),
      v.iso,
      groupLabel + ' Persian jalaliTextToIso(' + jText + ')'
    );
    assert.strictEqual(
      CpmsJalali.isoToJalaliText(v.iso),
      CpmsJalali.toPersianDigits(jText),
      groupLabel + ' isoToJalaliText(' + v.iso + ')'
    );
  }
}

check('all anchor vectors match Intl fixture', function () {
  verifyVectorList(fixture.anchors, 'anchors');
});

check('all Nowruz vectors 1300..1499 match Intl fixture', function () {
  verifyVectorList(fixture.nowruz, 'nowruz');
});

check('all month-end vectors 1395..1410 match Intl fixture', function () {
  verifyVectorList(fixture.month_ends_1395_1410, 'month_ends');
});

check('all >=1000 deterministic sample days across 1300..1499 match Intl fixture', function () {
  verifyVectorList(fixture.samples, 'samples');
});

check('full round-trip jalali -> gregorian -> jalali and month lengths across all 73,049 days of 1300..1499', function () {
  var leapSet = Object.create(null);
  for (var i = 0; i < fixture.leap_years.length; i += 1) {
    leapSet[fixture.leap_years[i]] = true;
  }
  assert.strictEqual(Boolean(leapSet[1399]), true, '1399 must be leap');
  assert.strictEqual(Boolean(leapSet[1403]), true, '1403 must be leap');
  assert.strictEqual(Boolean(leapSet[1401]), false, '1401 must be non-leap');
  assert.strictEqual(Boolean(leapSet[1405]), false, '1405 must be non-leap');

  var prevUtcMs = null;
  var count = 0;

  for (var jy = 1300; jy <= 1499; jy += 1) {
    var isLeap = Boolean(leapSet[jy]);
    for (var jm = 1; jm <= 12; jm += 1) {
      var expectedLen = jm <= 6 ? 31 : (jm <= 11 ? 30 : (isLeap ? 30 : 29));
      assert.strictEqual(
        CpmsJalali.toGregorian(jy, jm, expectedLen + 1),
        null,
        'overflow day ' + jy + '/' + jm + '/' + (expectedLen + 1) + ' must return null'
      );
      for (var jd = 1; jd <= expectedLen; jd += 1) {
        var g = CpmsJalali.toGregorian(jy, jm, jd);
        assert.ok(Array.isArray(g) && g.length === 3, 'valid date returned null: ' + jy + '/' + jm + '/' + jd);
        var utcMs = Date.UTC(g[0], g[1] - 1, g[2]);
        if (prevUtcMs !== null) {
          assert.strictEqual(utcMs - prevUtcMs, 86400000, 'non-consecutive UTC day at ' + jy + '/' + jm + '/' + jd);
        }
        prevUtcMs = utcMs;
        var back = toHostArray(CpmsJalali.toJalali(g[0], g[1], g[2]));
        assert.deepStrictEqual(back, [jy, jm, jd], 'round-trip mismatch at ' + jy + '/' + jm + '/' + jd);
        count += 1;
      }
    }
  }
  assert.strictEqual(count, fixture.meta.total_days_in_range);
});

check('rejects day 31 in month 7, day 30 Esfand in non-leap years, and out-of-range years outside 1300..1499', function () {
  assert.strictEqual(CpmsJalali.jalaliTextToIso('1403/07/31'), null);
  assert.strictEqual(CpmsJalali.jalaliTextToIso('1404/07/31'), null);
  assert.strictEqual(CpmsJalali.jalaliTextToIso('۱۴۰۳/۰۷/۳۱'), null);
  assert.strictEqual(CpmsJalali.classifyCompleteJalaliEntry('1403/07/31').reason, 'day');

  assert.strictEqual(CpmsJalali.jalaliTextToIso('1401/12/30'), null);
  assert.strictEqual(CpmsJalali.jalaliTextToIso('1404/12/30'), null);
  assert.strictEqual(CpmsJalali.jalaliTextToIso('1405/12/30'), null);
  assert.strictEqual(CpmsJalali.jalaliTextToIso('۱۴۰۱/۱۲/۳۰'), null);
  assert.strictEqual(CpmsJalali.classifyCompleteJalaliEntry('1401/12/30').reason, 'day');

  assert.strictEqual(CpmsJalali.jalaliTextToIso('1299/12/29'), null);
  assert.strictEqual(CpmsJalali.jalaliTextToIso('1500/01/01'), null);
  assert.strictEqual(CpmsJalali.toGregorian(1299, 12, 29), null);
  assert.strictEqual(CpmsJalali.toGregorian(1500, 1, 1), null);
  assert.strictEqual(CpmsJalali.toJalali(1921, 3, 20), null);
  assert.strictEqual(CpmsJalali.toJalali(2121, 3, 21), null);
  assert.strictEqual(CpmsJalali.toJalali(2025, 2, 29), null);
  assert.strictEqual(CpmsJalali.isoToJalaliText('1921-03-20'), '');
  assert.strictEqual(CpmsJalali.isoToJalaliText('2121-03-21'), '');
});

check('form.reset() syncs visible Shamsi text back to native defaultValue and clears validity block', function () {
  var env = makeMiniDom();
  var form = env.doc.createElement('form');
  var input = env.doc.createElement('input');
  input.setAttribute('type', 'date');
  input.setAttribute('value', '2024-03-20');
  form.appendChild(input);
  env.body.appendChild(form);

  vm.createContext(env.sandbox);
  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });

  var entries = form.querySelectorAll('input[type="date"]');
  assert.strictEqual(entries.length, 1);
  var wrap = input.parentNode;
  var entry = null;
  for (var i = 0; i < wrap.children.length; i += 1) {
    if (wrap.children[i].classList.contains('cpms-jalali-entry')) {
      entry = wrap.children[i];
    }
  }
  assert.ok(entry, 'entry input must exist');
  assert.strictEqual(entry.value, '۱۴۰۳/۰۱/۰۱');

  entry.value = '1405/12/30';
  entry.dispatchEvent(new env.Event('input', { bubbles: true }));
  entry.dispatchEvent(new env.Event('blur', { bubbles: true }));
  assert.notStrictEqual(input.customValidity, '');

  form.reset();
  assert.strictEqual(input.value, '2024-03-20');
  assert.strictEqual(entry.value, '۱۴۰۳/۰۱/۰۱');
  assert.strictEqual(input.customValidity, '');
});

check('window-level guard prevents double script execution from wrapping inputs or registering a second observer', function () {
  var env = makeMiniDom();
  var input = env.doc.createElement('input');
  input.setAttribute('type', 'date');
  input.setAttribute('value', '2026-03-21');
  env.body.appendChild(input);

  vm.createContext(env.sandbox);
  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });
  var firstApi = env.sandbox.window.CpmsJalali;
  assert.strictEqual(env.observers.length, 1, 'first load starts one MutationObserver');

  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });
  assert.strictEqual(env.sandbox.window.CpmsJalali, firstApi, 'second load keeps existing window.CpmsJalali');
  assert.strictEqual(env.observers.length, 1, 'second load must not register a second MutationObserver');

  var fields = [];
  function collectFields(node) {
    for (var i = 0; i < node.children.length; i += 1) {
      if (node.children[i].classList.contains('cpms-jalali-field')) { fields.push(node.children[i]); }
      collectFields(node.children[i]);
    }
  }
  collectFields(env.body);
  assert.strictEqual(fields.length, 1, 'input must be wrapped only once');
});

if (failures.length > 0) {
  console.error('FAIL (' + failures.length + '):');
  for (var f = 0; f < failures.length; f += 1) {
    console.error(' - ' + failures[f]);
  }
  process.exit(1);
}

console.log('OK: all pkg-p1-jalali-conversion.spec.js checks passed');
process.exit(0);

/**
 * Package P2A — Persian & Arabic-Indic digit parsing (B4) and out-of-range
 * ISO birth-date preservation on blur/submit (B5) in `cpms-jalali-date.js`.
 *
 * Plain Node, zero npm dependencies. Executed directly by `node` and by
 * `ClinicCore\Tests\Unit\PersianDigitsTest::test_p2a_jalali_js_spec_passes`.
 */
'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vm = require('vm');

var assetPath = path.resolve(__dirname, '..', '..', 'assets', 'js', 'cpms-jalali-date.js');
var source = fs.readFileSync(assetPath, 'utf8');

function makeDom(options) {
  var opts = options || {};
  var htmlDir = opts.htmlDir || 'rtl';
  var observers = [];

  function CustomEventStub(type, init) {
    this.type = type;
    this.bubbles = !!(init && init.bubbles);
    this.defaultPrevented = false;
    this.target = null;
    if (init) {
      for (var k in init) {
        if (Object.prototype.hasOwnProperty.call(init, k)) {
          this[k] = init[k];
        }
      }
    }
  }
  CustomEventStub.prototype.preventDefault = function () {
    this.defaultPrevented = true;
  };

  function isInclusiveAncestor(ancestor, node) {
    var cur = node;
    while (cur) {
      if (cur === ancestor) { return true; }
      cur = cur.parentNode;
    }
    return false;
  }

  function notifyAdded(parent, node) {
    for (var i = 0; i < observers.length; i += 1) {
      var rec = observers[i];
      if (!rec.active || !isInclusiveAncestor(rec.target, parent)) { continue; }
      rec.callback([{ type: 'childList', target: parent, addedNodes: [node] }], rec.instance);
    }
  }

  function Element(tag) {
    this.nodeType = 1;
    this.tagName = String(tag || 'DIV').toUpperCase();
    this.id = '';
    this.type = '';
    this.name = '';
    this.className = '';
    this.textContent = '';
    this.placeholder = '';
    this.hidden = false;
    this.disabled = false;
    this.dataset = {};
    this._value = '';
    this._attrs = {};
    this._listeners = {};
    this._validity = '';
    this.children = [];
    this.parentNode = null;
    var self = this;
    this.classList = {
      add: function (cls) {
        var parts = self.className ? self.className.split(/\s+/) : [];
        if (parts.indexOf(cls) === -1) { parts.push(cls); }
        self.className = parts.join(' ').trim();
      }
    };
  }

  Object.defineProperty(Element.prototype, 'value', {
    configurable: true,
    enumerable: true,
    get: function () { return this._value; },
    set: function (val) { this._value = val === null || val === undefined ? '' : String(val); }
  });

  Object.defineProperty(Element.prototype, 'innerHTML', {
    configurable: true,
    enumerable: true,
    get: function () { return ''; },
    set: function (val) { if (!val) { this.children = []; } }
  });

  function syncDataAttr(el, name, val) {
    if (name.indexOf('data-') !== 0) { return; }
    var camel = name.slice(5).replace(/-([a-z])/g, function (_, ch) { return ch.toUpperCase(); });
    if (val === null) { delete el.dataset[camel]; } else { el.dataset[camel] = String(val); }
  }

  Element.prototype.setAttribute = function (k, v) {
    var sval = String(v);
    this._attrs[k] = sval;
    if (k === 'id') { this.id = sval; }
    if (k === 'type') { this.type = sval; }
    if (k === 'name') { this.name = sval; }
    if (k === 'class') { this.className = sval; }
    syncDataAttr(this, k, sval);
  };
  Element.prototype.getAttribute = function (k) {
    return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null;
  };
  Element.prototype.removeAttribute = function (k) {
    delete this._attrs[k];
    syncDataAttr(this, k, null);
  };
  Element.prototype.appendChild = function (child) {
    if (child.parentNode) {
      var idx = child.parentNode.children.indexOf(child);
      if (idx >= 0) { child.parentNode.children.splice(idx, 1); }
    }
    child.parentNode = this;
    this.children.push(child);
    notifyAdded(this, child);
    return child;
  };
  Element.prototype.insertBefore = function (newNode, refNode) {
    if (newNode.parentNode) {
      var oldIdx = newNode.parentNode.children.indexOf(newNode);
      if (oldIdx >= 0) { newNode.parentNode.children.splice(oldIdx, 1); }
    }
    var idx = this.children.indexOf(refNode);
    newNode.parentNode = this;
    if (idx < 0) { this.children.push(newNode); } else { this.children.splice(idx, 0, newNode); }
    notifyAdded(this, newNode);
    return newNode;
  };
  Element.prototype.contains = function (other) { return isInclusiveAncestor(this, other); };
  Element.prototype.addEventListener = function (type, fn) {
    (this._listeners[type] = this._listeners[type] || []).push(fn);
  };
  Element.prototype.dispatchEvent = function (ev) {
    ev.target = this;
    var list = (this._listeners[ev.type] || []).slice();
    for (var i = 0; i < list.length; i += 1) { list[i].call(this, ev); }
    return !ev.defaultPrevented;
  };
  Element.prototype.focus = function () { doc.activeElement = this; };
  Element.prototype.setCustomValidity = function (msg) { this._validity = String(msg || ''); };

  function hasClass(el, cls) {
    return (el.className || '').split(/\s+/).indexOf(cls) !== -1;
  }

  function hasRtlAncestor(el) {
    var cur = el.parentNode;
    while (cur) {
      if (cur.tagName === 'HTML' && cur.getAttribute('dir') === 'rtl') { return true; }
      if (hasClass(cur, 'wrap') && cur.getAttribute('dir') === 'rtl') { return true; }
      cur = cur.parentNode;
    }
    return false;
  }

  function walkDescendants(root, visit) {
    for (var i = 0; i < root.children.length; i += 1) {
      var ch = root.children[i];
      visit(ch);
      walkDescendants(ch, visit);
    }
  }

  Element.prototype.querySelectorAll = function (selector) {
    var out = [];
    var isRtlSelector = selector.indexOf('.wrap[dir="rtl"]') !== -1
      && selector.indexOf('html[dir="rtl"]') !== -1;
    walkDescendants(this, function (el) {
      if (isRtlSelector) {
        if (el.tagName === 'INPUT' && el.type === 'date' && hasRtlAncestor(el)) { out.push(el); }
      } else if (selector === 'input[type="date"]') {
        if (el.tagName === 'INPUT' && el.type === 'date') { out.push(el); }
      }
    });
    return out;
  };

  function MutationObserverStub(callback) {
    this._record = { active: false, target: null, callback: callback, instance: this };
    observers.push(this._record);
  }
  MutationObserverStub.prototype.observe = function (target) {
    this._record.target = target;
    this._record.active = true;
  };
  MutationObserverStub.prototype.disconnect = function () { this._record.active = false; };

  var html = new Element('html');
  html.setAttribute('dir', htmlDir);
  var body = new Element('body');
  html.appendChild(body);

  var docListeners = {};
  var doc = {
    readyState: 'complete',
    documentElement: html,
    body: body,
    activeElement: null,
    createElement: function (tag) { return new Element(tag); },
    addEventListener: function (type, fn) {
      (docListeners[type] = docListeners[type] || []).push(fn);
    },
    dispatchEvent: function (ev) {
      var list = (docListeners[ev.type] || []).slice();
      for (var i = 0; i < list.length; i += 1) { list[i](ev); }
    },
    querySelectorAll: function (selector) { return html.querySelectorAll(selector); }
  };

  var win = {
    document: doc,
    Event: CustomEventStub,
    MutationObserver: MutationObserverStub,
    Object: Object,
    Date: Date,
    Promise: Promise,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    parseInt: parseInt,
    Math: Math,
    String: String,
    Array: Array
  };
  win.window = win;

  var ctx = vm.createContext(win);
  vm.runInContext(source, ctx, { filename: 'cpms-jalali-date.js' });

  function findEntryFor(nativeInput) {
    var wrap = nativeInput.parentNode;
    if (!wrap) { return null; }
    for (var i = 0; i < wrap.children.length; i += 1) {
      if (hasClass(wrap.children[i], 'cpms-jalali-entry')) { return wrap.children[i]; }
    }
    return null;
  }

  return {
    win: win,
    doc: doc,
    body: body,
    Event: CustomEventStub,
    CpmsJalali: win.CpmsJalali,
    findEntryFor: findEntryFor
  };
}

// =========================================================================
// B4: Persian (۰-۹) and Arabic-Indic (٠-٩) digits in jalaliTextToIso & UI
// =========================================================================
(function testB4PersianAndArabicIndicDigits() {
  var env = makeDom();
  var api = env.CpmsJalali;

  // Persian digits.
  assert.strictEqual(api.jalaliTextToIso('۱۴۰۴/۰۱/۰۱'), '2025-03-21');
  assert.strictEqual(api.jalaliTextToIso('۱۴۰۳/۱۲/۳۰'), '2025-03-20');
  assert.strictEqual(api.jalaliEntryShapeComplete('۱۴۰۴/۰۱/۰۱'), true);

  // Arabic-Indic digits (U+0660..U+0669).
  assert.strictEqual(api.jalaliTextToIso('١٤٠٤/٠١/٠١'), '2025-03-21');
  assert.strictEqual(api.jalaliTextToIso('١٤٠٣-١٢-٣٠'), '2025-03-20');
  assert.strictEqual(api.jalaliTextToIso('١٣٠٠/٠١/٠١'), '1921-03-21');
  assert.strictEqual(api.jalaliTextToIso('١٤٩٩/١٢/٢٩'), '2121-03-20');
  assert.strictEqual(api.jalaliEntryShapeComplete('١٤٠٤/٠١/٠١'), true);

  // Mixed Persian + Arabic-Indic + ASCII digits.
  assert.strictEqual(api.jalaliTextToIso('۱٤04/۰١/0۱'), '2025-03-21');

  // Invalid dates in Arabic-Indic digits fail closed with exact reasons.
  assert.strictEqual(api.jalaliTextToIso('١٤٠٥/١٢/٣٠'), null);
  assert.strictEqual(api.classifyCompleteJalaliEntry('١٤٠٥/١٢/٣٠').reason, 'day');
  assert.strictEqual(api.jalaliTextToIso('١٤٠٤/٠٧/٣١'), null);
  assert.strictEqual(api.classifyCompleteJalaliEntry('١٤٠٤/٠٧/٣١').reason, 'day');
  assert.strictEqual(api.jalaliTextToIso('١٤٠٤/١٣/٠١'), null);
  assert.strictEqual(api.classifyCompleteJalaliEntry('١٤٠٤/١٣/٠١').reason, 'month');
  assert.strictEqual(api.jalaliTextToIso('١٢٩٩/١٢/٢٩'), null);
  assert.strictEqual(api.classifyCompleteJalaliEntry('١٢٩٩/١٢/٢٩').reason, 'year');

  // Typing Arabic-Indic digits into an enhanced control commits canonical ISO on blur.
  var input = env.doc.createElement('input');
  input.setAttribute('type', 'date');
  input.setAttribute('name', 'birth_date');
  env.body.appendChild(input);
  var entry = env.findEntryFor(input);
  assert.ok(entry, 'enhanced Shamsi entry control must exist');

  entry.value = '١٤٠٤/٠٧/٢٧';
  entry.dispatchEvent(new env.Event('input', { bubbles: true }));
  entry.dispatchEvent(new env.Event('blur'));
  assert.strictEqual(input.value, '2025-10-19');
  assert.strictEqual(entry.getAttribute('aria-invalid'), null);
  assert.strictEqual(input._validity, '');
}());

// =========================================================================
// B5: Out-of-range ISO birth_date (outside 1300..1499) is never erased
// =========================================================================
(function testB5OutOfRangeBirthDatePreservedOnBlurAndSubmit() {
  var env = makeDom();
  var form = env.doc.createElement('form');
  var birthInput = env.doc.createElement('input');
  birthInput.setAttribute('type', 'date');
  birthInput.setAttribute('name', 'birth_date');
  form.appendChild(birthInput);
  env.body.appendChild(form);

  var entry = env.findEntryFor(birthInput);
  assert.ok(entry, 'birth_date input must be enhanced');

  // 1) Programmatic populateForm path (Patient Portal edit form) with 1915-05-10 (Jalali 1294).
  birthInput.value = '1915-05-10';
  assert.strictEqual(entry.value, '', 'out-of-range ISO cannot be rendered in 1300..1499 entry');

  var changeCount = 0;
  birthInput.addEventListener('change', function () { changeCount += 1; });

  // User tabs through the empty Shamsi entry without editing it.
  entry.focus();
  entry.dispatchEvent(new env.Event('blur'));
  assert.strictEqual(
    birthInput.value,
    '1915-05-10',
    'blurring empty Shamsi entry must NOT erase existing out-of-range ISO birth_date'
  );
  assert.strictEqual(birthInput._validity, '');
  assert.strictEqual(changeCount, 0);

  // Pressing Enter on empty Shamsi entry must also preserve 1915-05-10.
  entry.dispatchEvent(new env.Event('keydown', { key: 'Enter' }));
  assert.strictEqual(birthInput.value, '1915-05-10');

  // Typing an invalid/aborted draft and clearing entry back to '' still preserves 1915-05-10.
  entry.value = '۱۲۹۴/۰۲/۱۹';
  entry.dispatchEvent(new env.Event('input', { bubbles: true }));
  entry.value = '';
  entry.dispatchEvent(new env.Event('input', { bubbles: true }));
  entry.dispatchEvent(new env.Event('blur'));
  assert.strictEqual(birthInput.value, '1915-05-10');
  assert.strictEqual(birthInput._validity, '');

  // 2) Upper out-of-range ISO date (2130-05-10) is also preserved on blur.
  birthInput.value = '2130-05-10';
  entry.dispatchEvent(new env.Event('blur'));
  assert.strictEqual(birthInput.value, '2130-05-10');

  // 3) Positive control A: typing a new valid 1300..1499 date replaces the out-of-range value.
  birthInput.value = '1915-05-10';
  entry.value = '۱۳۷۰/۰۲/۲۰';
  entry.dispatchEvent(new env.Event('input', { bubbles: true }));
  entry.dispatchEvent(new env.Event('blur'));
  assert.strictEqual(birthInput.value, '1991-05-10');

  // 4) Positive control B: an in-range date (1990-05-10) cleared by user DOES clear to ''.
  birthInput.value = '1990-05-10';
  assert.strictEqual(entry.value, '۱۳۶۹/۰۲/۲۰');
  entry.value = '';
  entry.dispatchEvent(new env.Event('input', { bubbles: true }));
  entry.dispatchEvent(new env.Event('blur'));
  assert.strictEqual(birthInput.value, '');
}());

console.log('OK: pkg-p2a-persian-digits spec passed.');

/**
 * Package P1 — Dynamic & programmatic Jalali date enhancement spec
 * (plain Node, zero npm dependencies).
 *
 * Loads and executes the REAL shipped `assets/js/cpms-jalali-date.js` inside a
 * hand-written DOM + MutationObserver stub, proving:
 *   1. Initial `input[type="date"]` inside RTL root is enhanced on load with
 *      Persian digits and Persian month names, keeping Gregorian ISO `.value`.
 *   2. `input[type="date"]` inserted dynamically inside a wrapper subtree
 *      after initial load (e.g. Patient Portal multi-record `buildFormMarkup`)
 *      is automatically enhanced inside `html[dir="rtl"]`.
 *   3. `input[type="date"]` appended directly into `.wrap[dir="rtl"]` after
 *      initial load is automatically enhanced, displays Persian month names
 *      and Persian digits in its popup, and commits Gregorian ISO `.value`.
 *   4. Programmatic `.value` assignments after enhancement (e.g. async
 *      `populateForm`, finance today-range fill, follow-up reset) keep the
 *      Shamsi entry synchronized while preserving Gregorian ISO `.value`.
 *   5. Dynamically inserted `input[type="date"]` outside RTL scope or carrying
 *      `data-cpms-no-jalali="1"` is never enhanced.
 *
 * Run standalone:
 *   node clinic-practice-management/tests/js/pkg-p1-jalali-dynamic.spec.js
 */
'use strict';

var assert = require('assert');
var fs     = require('fs');
var path   = require('path');
var vm     = require('vm');

var assetPath = path.resolve(__dirname, '..', '..', 'assets', 'js', 'cpms-jalali-date.js');
var source    = fs.readFileSync(assetPath, 'utf8');

function makeDom(options) {
  var opts      = options || {};
  var htmlDir   = opts.htmlDir || 'rtl';
  var observers = [];

  function CustomEventStub(type, init) {
    this.type             = type;
    this.bubbles          = !!(init && init.bubbles);
    this.defaultPrevented = false;
    this.target           = null;
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

  function notifyAdded(parent, node) {
    for (var i = 0; i < observers.length; i += 1) {
      var rec = observers[i];
      if (!rec.active) { continue; }
      if (!isInclusiveAncestor(rec.target, parent)) { continue; }
      rec.callback([
        {
          type:       'childList',
          target:     parent,
          addedNodes: [node]
        }
      ], rec.instance);
    }
  }

  function isInclusiveAncestor(ancestor, node) {
    var cur = node;
    while (cur) {
      if (cur === ancestor) { return true; }
      cur = cur.parentNode;
    }
    return false;
  }

  function Element(tag) {
    this.nodeType    = 1;
    this.tagName     = String(tag || 'DIV').toUpperCase();
    this.id          = '';
    this.type        = '';
    this.name        = '';
    this.className   = '';
    this.textContent = '';
    this.placeholder = '';
    this.hidden      = false;
    this.disabled    = false;
    this.dataset     = {};
    this._value      = '';
    this._attrs      = {};
    this._listeners  = {};
    this._validity   = '';
    this.children    = [];
    this.parentNode  = null;
    var self         = this;
    this.classList   = {
      add: function (cls) {
        var parts = self.className ? self.className.split(/\s+/) : [];
        if (parts.indexOf(cls) === -1) { parts.push(cls); }
        self.className = parts.join(' ').trim();
      }
    };
  }

  Object.defineProperty(Element.prototype, 'value', {
    configurable: true,
    enumerable:   true,
    get: function () {
      return this._value;
    },
    set: function (val) {
      this._value = val === null || val === undefined ? '' : String(val);
    }
  });

  Object.defineProperty(Element.prototype, 'innerHTML', {
    configurable: true,
    enumerable:   true,
    get: function () {
      return '';
    },
    set: function (val) {
      if (!val) {
        this.children = [];
      }
    }
  });

  function syncDataAttr(el, name, val) {
    if (name.indexOf('data-') !== 0) { return; }
    var camel = name.slice(5).replace(/-([a-z])/g, function (_, ch) {
      return ch.toUpperCase();
    });
    if (val === null) {
      delete el.dataset[camel];
    } else {
      el.dataset[camel] = String(val);
    }
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
    if (idx < 0) {
      this.children.push(newNode);
    } else {
      this.children.splice(idx, 0, newNode);
    }
    notifyAdded(this, newNode);
    return newNode;
  };
  Element.prototype.contains = function (other) {
    return isInclusiveAncestor(this, other);
  };
  Element.prototype.addEventListener = function (type, fn) {
    (this._listeners[type] = this._listeners[type] || []).push(fn);
  };
  Element.prototype.dispatchEvent = function (ev) {
    ev.target = this;
    var list = (this._listeners[ev.type] || []).slice();
    for (var i = 0; i < list.length; i += 1) {
      list[i].call(this, ev);
    }
    return !ev.defaultPrevented;
  };
  Element.prototype.focus = function () { doc.activeElement = this; };
  Element.prototype.setCustomValidity = function (msg) { this._validity = String(msg || ''); };

  function hasClass(el, cls) {
    var parts = (el.className || '').split(/\s+/);
    return parts.indexOf(cls) !== -1;
  }

  function hasRtlAncestor(el) {
    var cur = el.parentNode;
    while (cur) {
      if (cur.tagName === 'HTML' && cur.getAttribute('dir') === 'rtl') {
        return true;
      }
      if (hasClass(cur, 'wrap') && cur.getAttribute('dir') === 'rtl') {
        return true;
      }
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
        if (el.tagName === 'INPUT' && el.type === 'date' && hasRtlAncestor(el)) {
          out.push(el);
        }
      } else if (selector === 'input[type="date"]') {
        if (el.tagName === 'INPUT' && el.type === 'date') {
          out.push(el);
        }
      } else if (selector === '.cpms-jalali-day') {
        if (hasClass(el, 'cpms-jalali-day')) {
          out.push(el);
        }
      }
    });
    return out;
  };

  function MutationObserverStub(callback) {
    this._record = {
      active:   false,
      target:   null,
      callback: callback,
      instance: this
    };
    observers.push(this._record);
  }
  MutationObserverStub.prototype.observe = function (target) {
    this._record.target = target;
    this._record.active = true;
  };
  MutationObserverStub.prototype.disconnect = function () {
    this._record.active = false;
  };

  var html = new Element('html');
  html.setAttribute('dir', htmlDir);
  var body = new Element('body');
  body.className = 'cpms-jalali-scope';
  html.appendChild(body);

  var docListeners = {};
  var doc = {
    readyState:      'complete',
    documentElement: html,
    body:            body,
    activeElement:   null,
    createElement:   function (tag) { return new Element(tag); },
    addEventListener: function (type, fn) {
      (docListeners[type] = docListeners[type] || []).push(fn);
    },
    dispatchEvent: function (ev) {
      var list = (docListeners[ev.type] || []).slice();
      for (var i = 0; i < list.length; i += 1) { list[i](ev); }
    },
    querySelectorAll: function (selector) {
      return html.querySelectorAll(selector);
    }
  };

  var sandbox = {
    window:           null,
    document:         doc,
    Event:            CustomEventStub,
    MutationObserver: MutationObserverStub,
    HTMLInputElement: Element
  };
  sandbox.window = sandbox;
  sandbox.window.MutationObserver = MutationObserverStub;
  sandbox.window.HTMLInputElement = Element;

  return {
    sandbox: sandbox,
    doc:     doc,
    html:    html,
    body:    body,
    Element: Element,
    Event:   CustomEventStub,
    findByClass: function (root, cls) {
      var found = [];
      walkDescendants(root, function (el) {
        if (hasClass(el, cls)) { found.push(el); }
      });
      return found;
    }
  };
}

var failures = 0;
function check(name, fn) {
  try {
    fn();
    console.log('ok - ' + name);
  } catch (err) {
    failures += 1;
    console.error('FAIL - ' + name + ': ' + (err && err.message ? err.message : String(err)));
  }
}

// 1) Baseline positive control: initial input[type="date"] enhanced at load
check('initial input[type="date"] inside html[dir="rtl"] is enhanced on load with Persian digits and month names', function () {
  var env = makeDom({ htmlDir: 'rtl' });
  var initial = env.doc.createElement('input');
  initial.setAttribute('type', 'date');
  initial.setAttribute('id', 'initial-date');
  initial.value = '2026-03-20';
  env.body.appendChild(initial);

  vm.createContext(env.sandbox);
  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });

  assert.strictEqual(initial.dataset.cpmsJalaliEnhanced, '1', 'initial input must be enhanced');
  var entries = env.findByClass(env.body, 'cpms-jalali-entry');
  assert.strictEqual(entries.length, 1, 'one Shamsi entry companion must exist');
  assert.strictEqual(entries[0].value, '۱۴۰۴/۱۲/۲۹', 'Shamsi entry must display Persian digits');

  var triggers = env.findByClass(env.body, 'cpms-jalali-trigger');
  triggers[0].dispatchEvent(new env.Event('click', { bubbles: true }));
  var titles = env.findByClass(env.body, 'cpms-jalali-popup-title');
  assert.ok(titles[0].textContent.indexOf('اسفند') !== -1, 'popup header must show Persian month name');
  assert.ok(titles[0].textContent.indexOf('۱۴۰۴') !== -1, 'popup header must show Persian year digits');
});

// 2) Dynamic wrapper subtree inserted after load inside html[dir="rtl"]
check('dynamically inserted wrapper containing input[type="date"] inside html[dir="rtl"] is auto-enhanced', function () {
  var env = makeDom({ htmlDir: 'rtl' });
  vm.createContext(env.sandbox);
  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });

  // Simulate Patient Portal multi-record profile form injected after load:
  var wrap = env.doc.createElement('div');
  var form = env.doc.createElement('form');
  var birthInput = env.doc.createElement('input');
  birthInput.setAttribute('id', 'cpms-pp-birth_date');
  birthInput.setAttribute('name', 'birth_date');
  birthInput.setAttribute('type', 'date');
  birthInput.setAttribute('dir', 'ltr');
  birthInput.value = '2012-03-20';
  form.appendChild(birthInput);
  wrap.appendChild(form);
  env.body.appendChild(wrap);

  assert.strictEqual(birthInput.dataset.cpmsJalaliEnhanced, '1', 'dynamically inserted birth_date input must be enhanced without manual enhanceAll call');
  var entries = env.findByClass(wrap, 'cpms-jalali-entry');
  assert.strictEqual(entries.length, 1, 'one Shamsi entry companion must wrap the dynamic input');
  assert.strictEqual(entries[0].value, '۱۳۹۱/۰۱/۰۱', 'initial ISO value on dynamic input must sync to Persian Shamsi text');

  // Typing a new Shamsi date and blurring commits Gregorian ISO onto the native input:
  entries[0].value = '۱۳۹۵/۰۲/۱۵';
  entries[0].dispatchEvent(new env.Event('input', { bubbles: true }));
  entries[0].dispatchEvent(new env.Event('blur', { bubbles: true }));
  assert.strictEqual(birthInput.value, '2016-05-04', 'submitted native value must remain canonical Gregorian ISO');
});

// 3) Direct dynamic input[type="date"] appended inside .wrap[dir="rtl"] on ltr document
check('dynamically appended input[type="date"] directly inside .wrap[dir="rtl"] is auto-enhanced', function () {
  var env = makeDom({ htmlDir: 'ltr' });
  var adminWrap = env.doc.createElement('div');
  adminWrap.setAttribute('class', 'wrap');
  adminWrap.setAttribute('dir', 'rtl');
  env.body.appendChild(adminWrap);

  vm.createContext(env.sandbox);
  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });

  var dynInput = env.doc.createElement('input');
  dynInput.setAttribute('type', 'date');
  dynInput.setAttribute('id', 'dyn-admin-date');
  adminWrap.appendChild(dynInput);

  assert.strictEqual(dynInput.dataset.cpmsJalaliEnhanced, '1', 'direct dynamic input inside .wrap[dir="rtl"] must be enhanced');
  var triggers = env.findByClass(adminWrap, 'cpms-jalali-trigger');
  assert.strictEqual(triggers.length, 1);

  dynInput.value = '2026-10-23';
  dynInput.dispatchEvent(new env.Event('change', { bubbles: true }));
  triggers[0].dispatchEvent(new env.Event('click', { bubbles: true }));
  var titles = env.findByClass(adminWrap, 'cpms-jalali-popup-title');
  assert.ok(titles[0].textContent.indexOf('آبان') !== -1, 'popup header must show Persian month آبان');
  assert.ok(titles[0].textContent.indexOf('۱۴۰۵') !== -1, 'popup header must show Persian year ۱۴۰۵');

  var days = env.findByClass(adminWrap, 'cpms-jalali-day').filter(function (el) {
    return el.tagName === 'BUTTON';
  });
  assert.ok(days.length >= 29, 'calendar popup must render day buttons');
  assert.strictEqual(days[0].textContent, '۱', 'day buttons must use Persian digits');
  days[9].dispatchEvent(new env.Event('click', { bubbles: true }));
  assert.strictEqual(dynInput.value, '2026-11-01', 'clicking day ۱۰ آبان ۱۴۰۵ must write Gregorian ISO 2026-11-01');
});

// 4) Programmatic .value updates after enhancement keep Shamsi entry synchronized
check('programmatic input.value assignment after enhancement syncs Shamsi entry and preserves ISO', function () {
  var env = makeDom({ htmlDir: 'rtl' });
  vm.createContext(env.sandbox);
  vm.runInContext(source, env.sandbox, { filename: 'cpms-jalali-date.js' });

  var wrap = env.doc.createElement('div');
  var birthInput = env.doc.createElement('input');
  birthInput.setAttribute('type', 'date');
  birthInput.value = '';
  wrap.appendChild(birthInput);
  env.body.appendChild(wrap);

  assert.strictEqual(birthInput.dataset.cpmsJalaliEnhanced, '1', 'dynamic input must be enhanced');
  var entries = env.findByClass(wrap, 'cpms-jalali-entry');
  assert.strictEqual(entries[0].value, '');

  // Async REST populateForm sets input.value directly without firing change:
  birthInput.value = '2020-05-10';
  assert.strictEqual(birthInput.value, '2020-05-10', 'native value stays Gregorian ISO');
  assert.strictEqual(entries[0].value, '۱۳۹۹/۰۲/۲۱', 'Shamsi entry must sync on programmatic ISO assignment');

  // Programmatic clear (e.g. clearFuComposer) clears Shamsi entry too:
  birthInput.value = '';
  assert.strictEqual(birthInput.value, '');
  assert.strictEqual(entries[0].value, '', 'Shamsi entry must clear when native value is cleared programmatically');
});

// 5) Guard: dynamic inputs outside RTL scope or with data-cpms-no-jalali="1" stay untouched
check('dynamic input[type="date"] outside RTL scope or with data-cpms-no-jalali="1" is never enhanced', function () {
  var envLtr = makeDom({ htmlDir: 'ltr' });
  vm.createContext(envLtr.sandbox);
  vm.runInContext(source, envLtr.sandbox, { filename: 'cpms-jalali-date.js' });

  var outsideInput = envLtr.doc.createElement('input');
  outsideInput.setAttribute('type', 'date');
  envLtr.body.appendChild(outsideInput);
  assert.notStrictEqual(outsideInput.dataset.cpmsJalaliEnhanced, '1', 'input outside RTL scope must not be enhanced');

  var envRtl = makeDom({ htmlDir: 'rtl' });
  vm.createContext(envRtl.sandbox);
  vm.runInContext(source, envRtl.sandbox, { filename: 'cpms-jalali-date.js' });

  var optedOut = envRtl.doc.createElement('input');
  optedOut.setAttribute('type', 'date');
  optedOut.setAttribute('data-cpms-no-jalali', '1');
  envRtl.body.appendChild(optedOut);
  assert.notStrictEqual(optedOut.dataset.cpmsJalaliEnhanced, '1', 'opted-out input must not be enhanced');
});

if (failures > 0) {
  console.error(failures + ' assertion(s) failed in pkg-p1-jalali-dynamic.spec.js');
  process.exit(1);
}
console.log('pkg-p1-jalali-dynamic.spec.js: all checks passed');

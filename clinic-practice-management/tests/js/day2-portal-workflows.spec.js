/**
 * Focused Day-2 behavioral & contract regression suite for:
 *  - Priority A: Patient Portal self-rescheduling (`assets/js/cpms-patient-portal.js`)
 *                & Public Booking OTP / first-time patient / receipt UX (`assets/js/cpms-public-booking.js`)
 *  - Priority B: Receptionist no-show + daily schedule clinician filter (`templates/staff-reception.php`)
 *  - Priority C: Doctor Visit Workspace medical history summary & details (`templates/doctor-portal-shell.php`)
 *  - Priority D: Accountant daily reconciliation & scoped financial reporting (`templates/staff-finance-board.php`)
 *
 * Plain Node, zero external dependencies, zero network access.
 * Run standalone: node clinic-practice-management/tests/js/day2-portal-workflows.spec.js
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');
var assert = require('assert');

var pluginRoot = path.join(__dirname, '..', '..');

function readPluginFile(relPath) {
  return fs.readFileSync(path.join(pluginRoot, relPath), 'utf8');
}

// ---------------------------------------------------------------------------
// Minimal recursive DOM implementation for executing real portal IIFEs in VM
// ---------------------------------------------------------------------------
function parseAttrs(attrText) {
  var attrs = {};
  var re = /([a-zA-Z0-9_:-]+)(?:="([^"]*)")?/g;
  var m;
  while ((m = re.exec(attrText)) !== null) {
    attrs[m[1]] = m[2] !== undefined ? m[2] : '';
  }
  return attrs;
}

function matchesSimpleSelector(el, sel) {
  sel = sel.trim();
  if (!sel || !el || el.nodeType !== 1) return false;
  var tagMatch = sel.match(/^[a-zA-Z0-9]+/);
  if (tagMatch && el.tagName.toLowerCase() !== tagMatch[0].toLowerCase()) {
    return false;
  }
  var idMatch = sel.match(/#([a-zA-Z0-9_-]+)/);
  if (idMatch && el.getAttribute('id') !== idMatch[1]) {
    return false;
  }
  var classMatches = sel.match(/\.[a-zA-Z0-9_-]+/g) || [];
  for (var c = 0; c < classMatches.length; c++) {
    var cls = classMatches[c].slice(1);
    var classes = String(el.className || '').split(/\s+/).filter(Boolean);
    if (classes.indexOf(cls) === -1) return false;
  }
  var attrRe = /\[([a-zA-Z0-9_:-]+)(?:="([^"]*)")?\]/g;
  var am;
  while ((am = attrRe.exec(sel)) !== null) {
    var name = am[1];
    var expected = am[2];
    if (!el.hasAttribute(name)) return false;
    if (expected !== undefined && el.getAttribute(name) !== expected) return false;
  }
  return true;
}

function matchesSelectorChain(el, selectorGroup) {
  var groups = selectorGroup.split(',');
  for (var g = 0; g < groups.length; g++) {
    var parts = groups[g].trim().split(/\s+/).filter(Boolean);
    if (!parts.length) continue;
    if (!matchesSimpleSelector(el, parts[parts.length - 1])) continue;
    var cur = el.parentElement;
    var ok = true;
    for (var p = parts.length - 2; p >= 0; p--) {
      while (cur && !matchesSimpleSelector(cur, parts[p])) {
        cur = cur.parentElement;
      }
      if (!cur) {
        ok = false;
        break;
      }
      cur = cur.parentElement;
    }
    if (ok) return true;
  }
  return false;
}

function createDomEnv(initialHtml, customFetch, onReload) {
  function MiniElement(tagName, ownerDocument) {
    this.nodeType = 1;
    this.tagName = String(tagName || 'DIV').toUpperCase();
    this.ownerDocument = ownerDocument;
    this.parentElement = null;
    this.children = [];
    this._attrs = {};
    this._listeners = {};
    this.dataset = {};
    this.style = {};
    this.disabled = false;
    this.value = '';
    this._textContent = '';
    var self = this;
    this.classList = {
      add: function (n) {
        var list = String(self.className || '').split(/\s+/).filter(Boolean);
        if (list.indexOf(n) === -1) list.push(n);
        self.className = list.join(' ');
      },
      remove: function (n) {
        var list = String(self.className || '').split(/\s+/).filter(function (x) { return x && x !== n; });
        self.className = list.join(' ');
      },
      contains: function (n) {
        return String(self.className || '').split(/\s+/).indexOf(n) !== -1;
      }
    };
  }

  Object.defineProperty(MiniElement.prototype, 'id', {
    get: function () { return this._attrs.id || ''; },
    set: function (v) { this.setAttribute('id', String(v)); }
  });

  Object.defineProperty(MiniElement.prototype, 'className', {
    get: function () { return this._attrs['class'] || ''; },
    set: function (v) { this.setAttribute('class', String(v)); }
  });

  Object.defineProperty(MiniElement.prototype, 'hidden', {
    get: function () { return Object.prototype.hasOwnProperty.call(this._attrs, 'hidden'); },
    set: function (v) {
      if (v) this._attrs.hidden = '';
      else delete this._attrs.hidden;
    }
  });

  Object.defineProperty(MiniElement.prototype, 'textContent', {
    get: function () {
      if (this.children.length === 0) return this._textContent;
      return this.children.map(function (c) { return c.textContent; }).join('');
    },
    set: function (v) {
      this.children = [];
      this._textContent = String(v === null || v === undefined ? '' : v);
    }
  });

  Object.defineProperty(MiniElement.prototype, 'innerHTML', {
    get: function () { return this._rawInner || this.textContent; },
    set: function (html) {
      this._rawInner = String(html || '');
      this._textContent = '';
      this.children = parseHtmlFragment(this._rawInner, this.ownerDocument, this);
    }
  });

  Object.defineProperty(MiniElement.prototype, 'firstElementChild', {
    get: function () { return this.children[0] || null; }
  });

  MiniElement.prototype.setAttribute = function (name, val) {
    this._attrs[name] = String(val);
    if (name.indexOf('data-') === 0) {
      var camel = name.slice(5).replace(/-([a-z])/g, function (_, ch) { return ch.toUpperCase(); });
      this.dataset[camel] = String(val);
    }
    if (name === 'value') this.value = String(val);
    if (name === 'disabled') this.disabled = true;
  };

  MiniElement.prototype.getAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null;
  };

  MiniElement.prototype.hasAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this._attrs, name);
  };

  MiniElement.prototype.removeAttribute = function (name) {
    delete this._attrs[name];
    if (name === 'disabled') this.disabled = false;
  };

  MiniElement.prototype.appendChild = function (child) {
    if (child.nodeType === 11) {
      var copy = child.children.slice();
      for (var i = 0; i < copy.length; i++) this.appendChild(copy[i]);
      child.children = [];
      return child;
    }
    child.parentElement = this;
    this.children.push(child);
    // Minimal <select> semantics: the first appended <option> becomes the
    // selected value, matching browser behavior used by portal modules.
    if (this.tagName === 'SELECT' && child.tagName === 'OPTION' && !this.value) {
      this.value = child.value || child.getAttribute('value') || '';
    }
    return child;
  };

  MiniElement.prototype.removeChild = function (child) {
    var idx = this.children.indexOf(child);
    if (idx !== -1) {
      this.children.splice(idx, 1);
      child.parentElement = null;
    }
    return child;
  };

  MiniElement.prototype.contains = function (other) {
    var cur = other;
    while (cur) {
      if (cur === this) return true;
      cur = cur.parentElement;
    }
    return false;
  };

  MiniElement.prototype.closest = function (selector) {
    var cur = this;
    while (cur && cur.nodeType === 1) {
      if (matchesSelectorChain(cur, selector)) return cur;
      cur = cur.parentElement;
    }
    return null;
  };

  MiniElement.prototype.querySelectorAll = function (selector) {
    var out = [];
    function walk(node) {
      for (var i = 0; i < node.children.length; i++) {
        var ch = node.children[i];
        if (matchesSelectorChain(ch, selector)) out.push(ch);
        walk(ch);
      }
    }
    walk(this);
    return out;
  };

  MiniElement.prototype.querySelector = function (selector) {
    return this.querySelectorAll(selector)[0] || null;
  };

  MiniElement.prototype.addEventListener = function (type, fn) {
    if (!this._listeners[type]) this._listeners[type] = [];
    this._listeners[type].push(fn);
  };

  MiniElement.prototype.focus = function () {
    this.ownerDocument.activeElement = this;
  };

  MiniElement.prototype.reset = function () {};

  MiniElement.prototype.cloneNode = function () {
    var clone = new MiniElement(this.tagName, this.ownerDocument);
    Object.keys(this._attrs).forEach(function (k) { clone.setAttribute(k, this._attrs[k]); }, this);
    clone.innerHTML = this._rawInner || this._textContent;
    return clone;
  };

  MiniElement.prototype.dispatch = function (type, extra) {
    var ev = Object.assign({
      type: type,
      target: this,
      defaultPrevented: false,
      preventDefault: function () { this.defaultPrevented = true; },
      stopPropagation: function () { this._stopped = true; }
    }, extra || {});
    var cur = this;
    while (cur) {
      var list = cur._listeners && cur._listeners[type] ? cur._listeners[type].slice() : [];
      for (var i = 0; i < list.length; i++) {
        list[i].call(cur, ev);
      }
      if (ev._stopped) break;
      cur = cur.parentElement;
    }
    if (!ev._stopped && this.ownerDocument._listeners[type]) {
      var docListeners = this.ownerDocument._listeners[type].slice();
      for (var j = 0; j < docListeners.length; j++) {
        docListeners[j].call(this.ownerDocument, ev);
      }
    }
    return ev;
  };

  var voidTags = { INPUT: 1, BR: 1, HR: 1, META: 1, IMG: 1 };

  function parseHtmlFragment(html, doc, parent) {
    var rootNodes = [];
    var stack = [];
    var tokenRe = /<!--[\s\S]*?-->|<\/([a-zA-Z0-9]+)\s*>|<([a-zA-Z0-9]+)([^>]*)>|([^<]+)/g;
    var m;
    while ((m = tokenRe.exec(html)) !== null) {
      if (m[0].indexOf('<!--') === 0) continue;
      if (m[1]) {
        stack.pop();
      } else if (m[2]) {
        var tag = m[2].toUpperCase();
        var attrStr = m[3] || '';
        var selfClosing = /\/\s*$/.test(attrStr) || !!voidTags[tag];
        var el = new MiniElement(tag, doc);
        var attrs = parseAttrs(attrStr);
        Object.keys(attrs).forEach(function (k) { el.setAttribute(k, attrs[k]); });
        if (tag === 'TEMPLATE') {
          var closeIdx = html.indexOf('</template>', tokenRe.lastIndex);
          if (closeIdx !== -1) {
            var tplInner = html.slice(tokenRe.lastIndex, closeIdx);
            (function (inner) {
              el.content = {
                cloneNode: function () {
                  var frag = { nodeType: 11, children: [] };
                  frag.children = parseHtmlFragment(inner, doc, frag);
                  frag.querySelector = MiniElement.prototype.querySelector.bind(frag);
                  frag.querySelectorAll = MiniElement.prototype.querySelectorAll.bind(frag);
                  Object.defineProperty(frag, 'firstElementChild', {
                    get: function () { return frag.children[0] || null; }
                  });
                  return frag;
                }
              };
            }(tplInner));
            tokenRe.lastIndex = closeIdx + '</template>'.length;
            selfClosing = true;
          }
        }
        var container = stack.length ? stack[stack.length - 1] : null;
        if (container) {
          container.children.push(el);
          el.parentElement = container;
        } else {
          el.parentElement = parent || null;
          rootNodes.push(el);
        }
        if (!selfClosing) stack.push(el);
      } else if (m[4]) {
        var txt = m[4];
        if (stack.length && stack[stack.length - 1].children.length === 0) {
          stack[stack.length - 1]._textContent += txt;
        }
      }
    }
    return rootNodes;
  }

  var doc = {
    readyState: 'complete',
    hidden: false,
    activeElement: null,
    _listeners: {},
    body: null,
    createElement: function (tag) { return new MiniElement(tag, doc); },
    createDocumentFragment: function () {
      var frag = { nodeType: 11, children: [] };
      frag.appendChild = function (c) { frag.children.push(c); return c; };
      return frag;
    },
    createTextNode: function (txt) {
      var span = new MiniElement('SPAN', doc);
      span.textContent = txt;
      return span;
    },
    addEventListener: function (type, fn) {
      if (!doc._listeners[type]) doc._listeners[type] = [];
      doc._listeners[type].push(fn);
    },
    getElementById: function (id) {
      return doc.body.querySelector('#' + id);
    },
    querySelector: function (sel) {
      return doc.body.querySelector(sel);
    },
    querySelectorAll: function (sel) {
      return doc.body.querySelectorAll(sel);
    }
  };

  doc.body = new MiniElement('BODY', doc);
  doc.body.innerHTML = initialHtml;

  var storage = {};
  var win = {
    document: doc,
    fetch: customFetch,
    Promise: Promise,
    crypto: {
      randomUUID: function () { return '12345678-1234-4abc-8def-1234567890ab'; },
      getRandomValues: function (arr) {
        for (var i = 0; i < arr.length; i++) arr[i] = (i * 17 + 3) & 0xff;
        return arr;
      }
    },
    sessionStorage: {
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(storage, k) ? storage[k] : null; },
      setItem: function (k, v) { storage[k] = String(v); },
      removeItem: function (k) { delete storage[k]; }
    },
    location: {
      reload: function () { if (onReload) onReload(); }
    },
    setTimeout: function (fn) { if (typeof fn === 'function') fn(); return 1; },
    clearTimeout: function () {},
    setInterval: function () { return 1; },
    clearInterval: function () {},
    addEventListener: function () {},
    print: function () {}
  };

  var context = vm.createContext({
    window: win,
    document: doc,
    fetch: customFetch,
    crypto: win.crypto,
    sessionStorage: win.sessionStorage,
    setTimeout: win.setTimeout,
    clearTimeout: win.clearTimeout,
    setInterval: win.setInterval,
    clearInterval: win.clearInterval,
    Date: Date,
    Math: Math,
    JSON: JSON,
    Number: Number,
    String: String,
    Boolean: Boolean,
    Array: Array,
    Object: Object,
    RegExp: RegExp,
    Error: Error,
    Promise: Promise,
    Uint8Array: Uint8Array,
    encodeURIComponent: encodeURIComponent,
    decodeURIComponent: decodeURIComponent,
    URL: URL,
    URLSearchParams: URLSearchParams,
    parseInt: parseInt,
    isNaN: isNaN
  });

  return { doc: doc, win: win, context: context };
}

function jsonResponse(status, body) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status: status,
    json: function () { return Promise.resolve(body); },
    text: function () { return Promise.resolve(JSON.stringify(body)); }
  });
}

function flushPromises() {
  return new Promise(function (resolve) { setImmediate(resolve); })
    .then(function () { return new Promise(function (resolve) { setImmediate(resolve); }); })
    .then(function () { return new Promise(function (resolve) { setImmediate(resolve); }); });
}

// ---------------------------------------------------------------------------
// TEST 1: Patient Portal Self-Reschedule Flow (`assets/js/cpms-patient-portal.js`)
// ---------------------------------------------------------------------------
async function testPatientPortalReschedule() {
  var calls = [];
  var reloaded = false;
  var html =
    '<div class="cpms-patient-portal">' +
      '<table><tbody>' +
        '<tr data-appointment-id="42">' +
          '<td><button type="button" data-role="reschedule-appointment" data-appointment-id="42">تغییر زمان نوبت</button></td>' +
        '</tr>' +
      '</tbody></table>' +
      '<div data-role="reschedule-panel" hidden>' +
        '<p data-role="reschedule-summary"></p>' +
        '<p data-role="reschedule-loading" hidden></p>' +
        '<p data-role="reschedule-error" hidden></p>' +
        '<p data-role="reschedule-success" hidden></p>' +
        '<p data-role="reschedule-empty" hidden></p>' +
        '<div data-role="reschedule-picker" hidden>' +
          '<div data-role="reschedule-days"></div>' +
          '<div data-role="reschedule-slots"></div>' +
          '<p data-role="reschedule-selected"></p>' +
        '</div>' +
        '<button type="button" data-role="reschedule-confirm" disabled>تأیید تغییر نوبت</button>' +
        '<button type="button" data-role="reschedule-abort">انصراف</button>' +
      '</div>' +
      '<script type="application/json" class="cpms-patient-portal__config">' +
        JSON.stringify({
          rest_root: 'https://clinic.test/wp-json/clinic/v1',
          nonce: 'nonce-pp-1',
          cancel_path: '/appointments/{id}/cancel',
          notifications_read_path: '/notifications/read'
        }) +
      '</script>' +
    '</div>';

  var env = createDomEnv(html, function (url, opts) {
    calls.push({ url: url, opts: opts || {} });
    if (url.indexOf('/appointments/42/reschedule-options') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          appointment_id: 42,
          reference_code: 'APT-261023-0042',
          current_jalali: '1405/08/02',
          current_time: '09:00',
          clinician_name: 'دکتر رضایی',
          days: [
            {
              date: '2026-10-25',
              jalali: '1405/08/03',
              slots: [
                { slot_id: 777, time: '10:30', duration_min: 15 },
                { slot_id: 778, time: '11:00', duration_min: 15 }
              ]
            }
          ]
        }
      });
    }
    if (url.indexOf('/appointments/42/reschedule') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: { appointment_id: 99, status: 'confirmed', reference_code: 'APT-261023-0099' }
      });
    }
    return jsonResponse(404, { ok: false });
  }, function () { reloaded = true; });

  var code = readPluginFile('assets/js/cpms-patient-portal.js');
  assert.strictEqual(code.indexOf('window.location.replace'), -1, 'Patient portal JS must never call window.location.replace');
  vm.runInContext(code, env.context);

  var openBtn = env.doc.querySelector('[data-role="reschedule-appointment"]');
  openBtn.dispatch('click');
  await flushPromises();

  assert.strictEqual(calls.length, 1, 'Opening reschedule panel fetches reschedule-options once');
  assert.ok(calls[0].url.endsWith('/appointments/42/reschedule-options'), 'Fetches bounded reschedule-options endpoint');

  var panel = env.doc.querySelector('[data-role="reschedule-panel"]');
  assert.strictEqual(panel.hidden, false, 'Reschedule panel becomes visible');
  var slotBtns = env.doc.querySelectorAll('[data-role="reschedule-slot"]');
  assert.strictEqual(slotBtns.length, 2, 'Renders 2 available slots from reschedule-options');

  slotBtns[1].dispatch('click');
  var submitBtn = env.doc.querySelector('[data-role="reschedule-confirm"]');
  assert.strictEqual(submitBtn.disabled, false, 'Confirm button enabled once slot chosen');

  submitBtn.dispatch('click');
  await flushPromises();

  assert.strictEqual(calls.length, 2, 'Submitting posts to reschedule endpoint');
  assert.ok(calls[1].url.endsWith('/appointments/42/reschedule'), 'Posts to /appointments/42/reschedule');
  assert.strictEqual(calls[1].opts.headers['X-WP-Nonce'], 'nonce-pp-1', 'Sends wp_rest nonce');
  assert.match(calls[1].opts.headers['Idempotency-Key'], /^[0-9a-f-]{36}$/i, 'Sends UUIDv4 Idempotency-Key');
  var parsedBody = JSON.parse(calls[1].opts.body);
  assert.deepStrictEqual(parsedBody, { slot_date: '2026-10-25', slot_time: '11:00', slot_id: 778 });
  assert.strictEqual(reloaded, true, 'Reloads portal view after confirmed reschedule');
}

// ---------------------------------------------------------------------------
// TEST 2: Public Booking OTP Validation & Change-Mobile (`assets/js/cpms-public-booking.js`)
// ---------------------------------------------------------------------------
async function testPublicBookingOtpAndTripwires() {
  var pbCode = readPluginFile('assets/js/cpms-public-booking.js');
  assert.strictEqual(pbCode.indexOf('Authorization'), -1, 'Phase8Slice2 T21: zero occurrences of Authorization');
  assert.ok(pbCode.indexOf("credentials: 'omit'") !== -1, 'Anonymous A1/A4 fetches keep credentials: omit');
  assert.ok(pbCode.indexOf("credentials: 'same-origin'") !== -1, 'Authenticated/OTP fetches keep credentials: same-origin');

  var calls = [];
  var html =
    '<section class="cpms-public-booking" data-surface-id="pb-1">' +
      '<button type="button" class="cpms-public-booking__clinician" data-clinician-id="5">دکتر نمونه</button>' +
      '<div data-role="panel" data-state="idle">' +
        '<div data-role="days"></div>' +
        '<div data-role="detail" hidden></div>' +
        '<div data-role="auth-box" hidden>' +
          '<p data-role="auth-status" hidden></p>' +
          '<div data-auth-step="otp-mobile" hidden>' +
            '<input type="tel" data-role="otp-mobile" value="">' +
            '<button type="button" data-auth-action="otp-request">دریافت کد</button>' +
          '</div>' +
          '<div data-auth-step="otp-code" hidden>' +
            '<input type="text" data-role="otp-code" value="">' +
            '<button type="button" data-auth-action="otp-verify">تأیید</button>' +
            '<button type="button" data-auth-action="otp-change-mobile">تغییر شماره</button>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<template data-template="day"><section><h4 data-fill="jalali"></h4><ul data-role="slots"></ul></section></template>' +
      '<template data-template="slot"><li><button type="button" class="cpms-public-booking__slot" data-role="slot"><span data-fill="time"></span></button></li></template>' +
      '<script type="application/json" class="cpms-public-booking__config">' +
        JSON.stringify({
          surface_id: 'pb-1',
          rest_root: 'https://clinic.test/wp-json/clinic/v1',
          availability_path: '/availability',
          quote_path: '/booking/quote',
          otp_request_path: '/otp/request',
          otp_verify_path: '/otp/verify',
          clinic_id: 3,
          state_vocabulary: ['idle', 'loading', 'selectable', 'location_required', 'bookable', 'policy_rejected', 'unavailable', 'empty', 'error'],
          initial_state: 'idle'
        }) +
      '</script>' +
    '</section>';

  var env = createDomEnv(html, function (url, opts) {
    calls.push({ url: url, opts: opts || {} });
    return jsonResponse(200, { ok: true, data: {} });
  });

  vm.runInContext(pbCode, env.context);

  var mobileInput = env.doc.querySelector('[data-role="otp-mobile"]');
  var reqBtn = env.doc.querySelector('[data-auth-action="otp-request"]');
  mobileInput.value = '12345';
  reqBtn.dispatch('click');
  await flushPromises();
  assert.strictEqual(calls.length, 0, 'Invalid mobile number does not trigger network request');
  assert.strictEqual(mobileInput.getAttribute('aria-invalid'), 'true', 'Sets aria-invalid on invalid mobile');

  // Persian digits normalize cleanly to 09123456789
  mobileInput.value = '۰۹۱۲۳۴۵۶۷۸۹';
  reqBtn.dispatch('click');
  await flushPromises();
  assert.strictEqual(calls.length, 1, 'Valid Persian-digit mobile posts to /otp/request');
  assert.strictEqual(JSON.parse(calls[0].opts.body).mobile, '09123456789', 'Normalizes Persian digits to ASCII');
  assert.strictEqual(mobileInput.getAttribute('aria-invalid'), null, 'Clears aria-invalid on valid mobile');

  var stepMobile = env.doc.querySelector('[data-auth-step="otp-mobile"]');
  var stepCode = env.doc.querySelector('[data-auth-step="otp-code"]');
  assert.strictEqual(stepCode.hidden, false, 'Advances to otp-code step');
  assert.strictEqual(stepMobile.hidden, true, 'Hides otp-mobile step');

  var changeBtn = env.doc.querySelector('[data-auth-action="otp-change-mobile"]');
  changeBtn.dispatch('click');
  assert.strictEqual(stepMobile.hidden, false, 'otp-change-mobile returns user to otp-mobile step');
  assert.strictEqual(stepCode.hidden, true, 'otp-change-mobile hides otp-code step');

  // Subtest 2B: Authenticated first-time patient Hold -> b2-gate -> Confirm + Receipt
  var pCalls = [];
  var patientHtml =
    '<section class="cpms-public-booking" data-surface-id="pb-2">' +
      '<button type="button" class="cpms-public-booking__clinician" data-clinician-id="5">دکتر نمونه</button>' +
      '<div data-role="panel" data-state="idle">' +
        '<div data-role="location-picker" hidden>' +
          '<ul data-role="location-options"></ul>' +
          '<div data-role="location-details" hidden><span data-role="location-name"></span><span data-role="location-address-row"><span data-role="location-address"></span></span></div>' +
        '</div>' +
        '<div data-role="days"></div>' +
        '<div data-role="detail" hidden></div>' +
        '<div data-role="booking-continue" hidden>' +
          '<p data-role="continue-status" hidden></p>' +
          '<p data-role="hold-countdown" hidden><b data-role="countdown-value"></b></p>' +
          '<div data-role="names-form" data-first-time="1" hidden>' +
            '<input type="text" data-role="patient-first-name" value="">' +
            '<input type="text" data-role="patient-last-name" value="">' +
          '</div>' +
          '<div data-role="reason-wrap" hidden>' +
            '<input type="text" data-role="booking-reason" value="">' +
          '</div>' +
          '<button type="button" data-role="confirm-btn" hidden>تأیید نهایی نوبت</button>' +
          '<div data-role="receipt" hidden>' +
            '<b data-role="reference-code"></b>' +
            '<b data-role="slot-jalali"></b>' +
            '<b data-role="slot-time"></b>' +
            '<a data-role="receipt-portal-link" href="/cpms-patient-portal/">پرتال بیمار</a>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<template data-template="day"><div class="cpms-public-booking__day"><p data-fill="jalali"></p><div data-host="slots"></div></div></template>' +
      '<template data-template="slot"><button type="button" class="cpms-public-booking__slot"><span data-fill="time"></span></button></template>' +
      '<template data-template="location-option"><li><button type="button" data-role="location-option"><span data-fill="name"></span><span data-fill="address"></span></button></li></template>' +
      '<script type="application/json" class="cpms-public-booking__config">' +
        JSON.stringify({
          surface_id: 'pb-2',
          rest_root: 'https://clinic.test/wp-json/clinic/v1',
          availability_path: '/availability',
          quote_path: '/booking/quote',
          hold_path: '/booking/hold',
          confirm_path: '/booking/confirm',
          nonce: 'nonce-patient-2',
          clinic_id: 3,
          state_vocabulary: ['idle', 'loading', 'selectable', 'location_required', 'bookable', 'policy_rejected', 'unavailable', 'empty', 'error'],
          initial_state: 'idle'
        }) +
      '</script>' +
    '</section>';

  var pEnv = createDomEnv(patientHtml, function (url, opts) {
    pCalls.push({ url: url, opts: opts || {} });
    if (url.indexOf('/availability') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          locations: [{ id: 10, name: 'شعبه مرکزی', address: 'تهران' }],
          days: [{ date: '2026-10-25', jalali: '1405/08/03', slots: [{ slot_id: 801, location_id: 10, location_name: 'شعبه مرکزی', time: '09:30' }] }]
        }
      });
    }
    if (url.indexOf('/booking/quote') !== -1) {
      return jsonResponse(200, { ok: true, data: { available: true, location_id: 10, capacity_left: 1, jalali: '1405/08/03', time: '09:30' } });
    }
    if (url.indexOf('/booking/hold') !== -1) {
      return jsonResponse(200, { ok: true, data: { hold_token: 'hold-xyz', expires_at: '2026-10-25 10:00:00' } });
    }
    if (url.indexOf('/booking/confirm') !== -1) {
      var body = JSON.parse(opts.body || '{}');
      if (!body.first_name || !body.last_name) {
        return jsonResponse(400, { ok: false, code: 'CLINIC_VALIDATION_FAILED', message: 'نام و نام خانوادگی الزامی است.' });
      }
      return jsonResponse(200, { ok: true, data: { reference_code: 'APT-261025-0001', jalali: '1405/08/03', time: '09:30' } });
    }
    return jsonResponse(404, { ok: false });
  });

  // Pre-seed preserved slot selection as after OTP reload
  pEnv.win.sessionStorage.setItem('cpms-public-booking:selection:v1', JSON.stringify({
    clinician_id: 5,
    slot_id: 801,
    location_id: 10,
    slot_date: '2026-10-25',
    slot_time: '09:30'
  }));

  vm.runInContext(pbCode, pEnv.context);
  await flushPromises();
  await flushPromises();
  await flushPromises();

  var namesForm = pEnv.doc.querySelector('[data-role="names-form"]');
  var reasonWrap = pEnv.doc.querySelector('[data-role="reason-wrap"]');
  var confirmBtn = pEnv.doc.querySelector('[data-role="confirm-btn"]');
  assert.strictEqual(namesForm.hidden, false, 'First-time patient names-form is proactively revealed after Hold');
  assert.strictEqual(reasonWrap.hidden, false, 'Optional reason-wrap is revealed after Hold');
  assert.strictEqual(confirmBtn.hidden, false, 'confirm-btn is revealed after Hold');

  // b2-gate: clicking confirm with empty names still posts to server & marks aria-invalid
  confirmBtn.dispatch('click');
  await flushPromises();
  var fnInput = pEnv.doc.querySelector('[data-role="patient-first-name"]');
  var lnInput = pEnv.doc.querySelector('[data-role="patient-last-name"]');
  assert.strictEqual(fnInput.getAttribute('aria-invalid'), 'true', 'Marks empty first_name aria-invalid on CLINIC_VALIDATION_FAILED');
  assert.strictEqual(lnInput.getAttribute('aria-invalid'), 'true', 'Marks empty last_name aria-invalid on CLINIC_VALIDATION_FAILED');

  // Fill names + reason and confirm
  fnInput.value = 'سارا';
  lnInput.value = 'محمدی';
  pEnv.doc.querySelector('[data-role="booking-reason"]').value = 'چکاپ دوره‌ای';
  confirmBtn.dispatch('click');
  await flushPromises();

  var lastConfirm = pCalls[pCalls.length - 1];
  assert.deepStrictEqual(JSON.parse(lastConfirm.opts.body), {
    hold_token: 'hold-xyz',
    first_name: 'سارا',
    last_name: 'محمدی',
    reason: 'چکاپ دوره‌ای'
  });
  var receipt = pEnv.doc.querySelector('[data-role="receipt"]');
  assert.strictEqual(receipt.hidden, false, 'Receipt is shown on successful confirm');
  assert.strictEqual(pEnv.doc.querySelector('[data-role="reference-code"]').textContent, 'APT-261025-0001');
  assert.ok(pEnv.doc.querySelector('[data-role="receipt-portal-link"]'), 'Receipt includes Patient Portal management link');
}

// ---------------------------------------------------------------------------
// TEST 3: Reception, Doctor, and Finance Portal Template Contracts & Tripwires
// ---------------------------------------------------------------------------
function testStaffTemplatesContracts() {
  var reception = readPluginFile('templates/staff-reception.php');
  assert.strictEqual(reception.indexOf('data-role="sr-no-show"'), -1, 'Phase11 forbidden marker sr-no-show absent');
  assert.strictEqual(reception.indexOf('data-role="sr-appt-details"'), -1, 'Phase11 forbidden marker sr-appt-details absent');
  ['sr-noshow-open', 'sr-row-noshow', 'sr-noshow-form', 'sr-noshow-reason', 'sr-noshow-confirm', 'sr-noshow-abort', 'sr-schedule-clinician-filter', '/no-show'].forEach(function (marker) {
    assert.ok(reception.indexOf(marker) !== -1, 'Reception template includes ' + marker);
  });

  var doctor = readPluginFile('templates/doctor-portal-shell.php');
  [
    '/appointments/', 'rx-void', 'rx_void', 'data-action="void"', 'voidPrescription',
    'stylus', 'handwriting-canvas', 'workspace-file', 'workspace-complete', 'workspace-reopen',
    'visit-complete', 'visit-reopen', 'consult-reopen', 'data-action="complete"',
    'location.reload(', 'location.assign(', 'location.replace(', 'location.href =', 'location.href=',
    'cpms-prescription-print', 'rx-history-handwriting'
  ].forEach(function (tok) {
    assert.strictEqual(doctor.indexOf(tok), -1, 'Doctor template excludes forbidden token: ' + tok);
  });
  ['workspace-med-history-section', 'workspace-med-history-summary', 'workspace-med-history-load', 'workspace-med-history-list', '/medical-history'].forEach(function (marker) {
    assert.ok(doctor.indexOf(marker) !== -1, 'Doctor template includes ' + marker);
  });

  var finance = readPluginFile('templates/staff-finance-board.php');
  var lowFinance = finance.toLowerCase();
  assert.strictEqual((lowFinance.match(/device/g) || []).length, 1, 'Finance template has only viewport device token');
  var scannedFinance = lowFinance.replace('width=device-width', 'width=viewport');
  [
    'void', 'void_', 'voidreason', 'waive', 'معافیت', 'refund', 'adjustment',
    'reissue', 'settle', 'online', 'terminal', 'device', 'sdk', 'serial',
    'usb', 'nfc', 'bluetooth', 'websocket', 'polling', 'merchant', 'application/pdf'
  ].forEach(function (tok) {
    assert.strictEqual(scannedFinance.indexOf(tok), -1, 'Finance template excludes forbidden token: ' + tok);
  });
  ['finance-reconciliation', 'finance-recon-from', 'finance-recon-to', 'finance-recon-load', 'finance-recon-today', 'finance-recon-summary', 'finance-recon-rows', '/staff/portal/finance/reconciliation'].forEach(function (marker) {
    assert.ok(finance.indexOf(marker) !== -1, 'Finance template includes ' + marker);
  });
}

// ---------------------------------------------------------------------------
// Finance board DOM fixture shared by the finance VM tests. It mirrors every
// data-role the inline IIFE queries, so the real template script runs unmodified.
function financeBoardHtml() {
  return (
    '<main data-role="finance-root">' +
      '<select data-role="finance-location" hidden></select>' +
      '<span data-role="finance-date"></span>' +
      '<p data-role="finance-status"></p>' +
      '<section data-role="finance-eligible"><tbody data-role="finance-eligible-rows"></tbody>' +
        '<form data-role="finance-issue-form" hidden>' +
          '<p data-role="finance-issue-target"></p>' +
          '<input data-role="finance-issue-description">' +
          '<input data-role="finance-issue-quantity">' +
          '<input data-role="finance-issue-unit-price">' +
          '<button data-role="finance-issue-submit"></button>' +
          '<button data-role="finance-issue-cancel"></button>' +
        '</form>' +
      '</section>' +
      '<section data-role="finance-board"><tbody data-role="finance-rows"></tbody></section>' +
      '<section data-role="finance-payment" hidden>' +
        '<form data-role="finance-pay-form">' +
          '<p data-role="finance-pay-target"></p>' +
          '<input data-role="finance-pay-amount">' +
          '<select data-role="finance-pay-method"></select>' +
          '<input data-role="finance-pay-ref">' +
          '<button data-role="finance-pay-submit"></button>' +
          '<button data-role="finance-pay-cancel"></button>' +
        '</form>' +
      '</section>' +
      '<section data-role="finance-paid"><tbody data-role="finance-paid-rows"></tbody></section>' +
      '<section data-role="finance-checkout" hidden>' +
        '<form data-role="finance-checkout-form">' +
          '<p data-role="finance-checkout-target"></p>' +
          '<button data-role="finance-checkout-submit"></button>' +
          '<button data-role="finance-checkout-cancel"></button>' +
        '</form>' +
      '</section>' +
      '<section data-role="finance-receipt" hidden>' +
        '<div data-role="finance-receipt-body"></div>' +
        '<button data-role="finance-receipt-print"></button>' +
        '<button data-role="finance-receipt-close"></button>' +
      '</section>' +
      '<section data-role="finance-reconciliation">' +
        '<input type="date" data-role="finance-recon-from" value="2026-10-20">' +
        '<input type="date" data-role="finance-recon-to" value="2026-10-23">' +
        '<button type="button" data-role="finance-recon-load">نمایش گزارش بازه</button>' +
        '<button type="button" data-role="finance-recon-today">تطبیق امروز</button>' +
        '<p data-role="finance-recon-range" hidden></p>' +
        '<div data-role="finance-recon-summary" hidden></div>' +
        '<div data-role="finance-recon-table-wrap" hidden><tbody data-role="finance-recon-rows"></tbody></div>' +
      '</section>' +
      '<section data-role="finance-billing" hidden>' +
        '<h2><span data-role="finance-billing-visit"></span></h2>' +
        '<div data-role="finance-billing-stats"></div>' +
        '<table><tbody data-role="finance-billing-charges"></tbody></table>' +
        '<table><tbody data-role="finance-billing-items"></tbody></table>' +
        '<button type="button" data-role="finance-billing-auto" hidden></button>' +
        '<button type="button" data-role="finance-billing-close"></button>' +
      '</section>' +
      '<script type="application/json" id="cpms-finance-board-config">' +
        JSON.stringify({ rest_root: 'https://clinic.test/wp-json/clinic/v1', nonce: 'nonce-fin-1' }) +
      '</script>' +
    '</main>'
  );
}

// TEST 4: Finance Daily Reconciliation Live VM Execution (`templates/staff-finance-board.php`)
// ---------------------------------------------------------------------------
async function testFinanceReconciliationVm() {
  var financePhp = readPluginFile('templates/staff-finance-board.php');
  var scriptMatch = financePhp.match(/<script>\n([\s\S]*?)\n<\/script>/);
  assert.ok(scriptMatch && scriptMatch[1], 'Extracts finance inline script');

  var calls = [];
  var html = financeBoardHtml();

  var env = createDomEnv(html, function (url, opts) {
    calls.push({ url: url, opts: opts || {} });
    if (url.indexOf('/staff/portal/finance/context') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          clinic_id: 7,
          location_id: 14,
          locations: [{ id: 14, name: 'شعبه ۱' }, { id: 15, name: 'شعبه ۲' }],
          can_issue_invoice: false,
          can_capture_payment: false,
          can_check_out: false
        }
      });
    }
    if (url.indexOf('/staff/portal/finance/reconciliation') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          from: '2026-10-20',
          to: '2026-10-23',
          jalali_from: '1405/07/28',
          jalali_to: '1405/08/01',
          location_id: 14,
          location_name: 'شعبه ۱',
          currency: 'IRR',
          totals: {
            captured_count: 2,
            captured_total: '350000.00',
            cash_total: '150000.00',
            card_pos_total: '200000.00',
            other_total: '0.00',
            issued_count: 2,
            paid_count: 1,
            open_count: 1,
            invoiced_total: '500000.00',
            outstanding_balance: '150000.00'
          },
          payments: [
            {
              payment_number: 'PAY-261023-0002',
              invoice_number: 'INV-261023-0002',
              method: 'card_pos',
              amount: '200000.00',
              currency: 'IRR',
              payment_date: '2026-10-23',
              jalali_date: '1405/08/01',
              payment_time: '11:45'
            }
          ],
          has_more: false
        }
      });
    }
    return jsonResponse(200, { ok: true, data: { date: '2026-10-23', jalali_date: '1405/08/01', location_id: 14, visits: [], has_more: false } });
  });

  vm.runInContext(scriptMatch[1], env.context);
  await flushPromises();
  await flushPromises();

  // Initial load must NOT auto-fetch reconciliation
  assert.strictEqual(
    calls.some(function (c) { return c.url.indexOf('/reconciliation') !== -1; }),
    false,
    'Initial finance load sequence stays untouched until explicit reconciliation click'
  );

  var reconBtn = env.doc.querySelector('[data-role="finance-recon-load"]');
  reconBtn.dispatch('click');
  await flushPromises();

  var reconCall = calls.find(function (c) { return c.url.indexOf('/reconciliation') !== -1; });
  assert.ok(reconCall, 'Clicking finance-recon-load fetches reconciliation endpoint');
  assert.ok(reconCall.url.indexOf('from=2026-10-20&to=2026-10-23') !== -1, 'Passes date range query params');
  assert.strictEqual(reconCall.opts.headers['X-CPMS-Clinic-Id'], '7', 'Sends trusted Clinic selector header');
  assert.strictEqual(reconCall.opts.headers['X-CPMS-Location-Id'], '14', 'Sends trusted Location selector header');

  var summary = env.doc.querySelector('[data-role="finance-recon-summary"]');
  var rowsWrap = env.doc.querySelector('[data-role="finance-recon-table-wrap"]');
  assert.strictEqual(summary.hidden, false, 'Displays reconciliation summary cards');
  assert.ok(summary.innerHTML.indexOf('350000.00') !== -1, 'Displays captured_total');
  assert.strictEqual(rowsWrap.hidden, false, 'Displays PHI-free payment ledger rows');

  // Switching Location resets reconciliation view cleanly
  var locSelect = env.doc.querySelector('[data-role="finance-location"]');
  locSelect.value = '15';
  locSelect.dispatch('change');
  assert.strictEqual(summary.hidden, true, 'Switching Location resets reconciliation summary');
  assert.strictEqual(rowsWrap.hidden, true, 'Switching Location hides stale reconciliation rows');
}

function testPatientPortalCompletionTemplateContracts() {
  var page = readPluginFile('src/Admin/PatientPortalPage.php');
  var shell = readPluginFile('templates/patient-portal-shell.php');
  var css = readPluginFile('assets/css/cpms-patient-portal.css');
  var portalJs = readPluginFile('assets/js/cpms-patient-portal.js');

  [
    'dashboard-section',
    'booking-section',
    'PublicBookingShortcode::render',
    'appointment-details'
  ].forEach(function (marker) {
    assert.ok(page.indexOf(marker) !== -1, 'Patient portal completion page includes ' + marker);
  });
  ['nav-dashboard', 'nav-booking', 'PublicBookingAssets::HANDLE', 'cpms_has_booking_body'].forEach(function (marker) {
    assert.ok(shell.indexOf(marker) !== -1, 'Patient shell integrates booking asset/navigation marker ' + marker);
  });
  ['.cpms-pp-dashboard__stats', '.cpms-pp-dashboard__next', '.cpms-pp-appointment-details', '@media (max-width: 340px)'].forEach(function (marker) {
    assert.ok(css.indexOf(marker) !== -1, 'Patient portal mobile CSS includes ' + marker);
  });
  assert.ok(portalJs.indexOf("['dashboard', 'booking', 'appointments'") !== -1, 'Section navigation recognizes dashboard and booking hashes');
  assert.ok(portalJs.indexOf(": 'dashboard';") !== -1, 'Dashboard remains the default active navigation target');
  assert.strictEqual(page.indexOf('رزرو آنلاین اینترنتی به‌زودی فعال می‌شود'), -1, 'Stale booking-soon placeholder is removed');
}

// TEST 5: A finance submit is never dropped by an in-flight board read
// ---------------------------------------------------------------------------
// Regression: the board used one shared "busy" flag for reads and writes and
// every entry point returned early while it was set. A submit pressed while the
// initial board read was still in flight therefore produced no request and no
// message, which is exactly the state the disposable browser run captured (a
// rendered issue button, no invoice POST at all). The board now orders requests
// instead of dropping them.
async function testFinanceSubmitSurvivesInFlightRead() {
  var financePhp = readPluginFile('templates/staff-finance-board.php');
  var scriptMatch = financePhp.match(/<script>\n([\s\S]*?)\n<\/script>/);
  assert.ok(scriptMatch && scriptMatch[1], 'Extracts finance inline script');

  var calls = [];
  var releaseBoard = null;
  var boardGate = new Promise(function (resolve) { releaseBoard = resolve; });
  var env = createDomEnv(financeBoardHtml(), function (url, opts) {
    calls.push({ url: url, opts: opts || {} });
    if (url.indexOf('/staff/portal/finance/context') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          clinic_id: 7,
          location_id: 14,
          locations: [{ id: 14, name: 'شعبه ۱' }],
          can_issue_invoice: true,
          can_capture_payment: true,
          can_check_out: true
        }
      });
    }
    if (url.indexOf('/staff/portal/finance/invoice-eligible') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          date: '2026-10-23',
          jalali_date: '1405/08/01',
          location_id: 14,
          visits: [{
            visit_id: 2,
            patient_name: 'بیمار آزمایشی',
            clinician_name: 'پزشک آزمایشی',
            operational_date: '2026-10-23',
            operational_time: '11:45',
            visit_status: 'completed'
          }],
          has_more: false
        }
      });
    }
    if (url.indexOf('/staff/portal/finance/awaiting-payment') !== -1) {
      // Held open on purpose: the user acts while this read is still in flight.
      return boardGate.then(function () {
        return jsonResponse(200, { ok: true, data: { date: '2026-10-23', visits: [], has_more: false } });
      });
    }
    if (url.indexOf('/staff/portal/finance/invoice') !== -1) {
      return jsonResponse(201, { ok: true, data: { invoice_number: 'INV-TEST-1', invoice_id: 9 } });
    }
    return jsonResponse(200, { ok: true, data: { date: '2026-10-23', visits: [], has_more: false } });
  });

  vm.runInContext(scriptMatch[1], env.context);
  await flushPromises();
  await flushPromises();

  assert.ok(
    calls.some(function (c) { return c.url.indexOf('/awaiting-payment') !== -1; }),
    'Initial board read is in flight'
  );
  assert.ok(
    !calls.some(function (c) { return c.url.indexOf('/finance/visits/') !== -1; }),
    'No invoice request before the user acts'
  );

  var openButton = env.doc.querySelector('[data-role="finance-issue-open"]');
  assert.ok(openButton, 'Eligible row renders an issue action');
  openButton.dispatch('click');
  var form = env.doc.querySelector('[data-role="finance-issue-form"]');
  assert.strictEqual(form.hidden, false, 'Clicking the row action opens the issue form');

  env.doc.querySelector('[data-role="finance-issue-description"]').value = 'ویزیت آزمایشی مرورگر';
  env.doc.querySelector('[data-role="finance-issue-quantity"]').value = '1';
  env.doc.querySelector('[data-role="finance-issue-unit-price"]').value = '1000';
  form.dispatch('submit');
  // A second press while the first submit is queued must not issue a second invoice.
  form.dispatch('submit');
  await flushPromises();
  await flushPromises();

  var invoicePosts = function () {
    return calls.filter(function (c) {
      return c.url.indexOf('/finance/visits/2/invoice') !== -1 && String(c.opts.method || '').toUpperCase() === 'POST';
    });
  };
  assert.strictEqual(invoicePosts().length, 0, 'The submit waits for the in-flight read instead of racing it');

  releaseBoard();
  await flushPromises();
  await flushPromises();
  await flushPromises();

  var posted = invoicePosts();
  assert.strictEqual(posted.length, 1, 'The submit is delivered exactly once after the in-flight read settles');
  assert.strictEqual(posted[0].opts.headers['X-CPMS-Clinic-Id'], '7', 'Submit keeps the trusted Clinic selector header');
  assert.strictEqual(posted[0].opts.headers['X-CPMS-Location-Id'], '14', 'Submit keeps the trusted Location selector header');
  assert.strictEqual(posted[0].opts.headers['X-WP-Nonce'], 'nonce-fin-1', 'Submit keeps the REST nonce');
  assert.deepStrictEqual(
    JSON.parse(posted[0].opts.body),
    { items: [{ description: 'ویزیت آزمایشی مرورگر', quantity: 1, unit_price: 1000 }] },
    'Submit sends the exact invoice line the user typed'
  );
}

// Iran V1 — the receptionist itemized billing panel renders server truth and
// keeps the existing payment/issuance controls untouched.
async function testFinanceBillingPanelVm() {
  var financePhp = readPluginFile('templates/staff-finance-board.php');
  var scriptMatch = financePhp.match(/<script>\n([\s\S]*?)\n<\/script>/);
  assert.ok(scriptMatch && scriptMatch[1], 'Extracts finance inline script');

  var calls = [];
  var env = createDomEnv(financeBoardHtml(), function (url, opts) {
    calls.push({ url: url, opts: opts || {} });
    if (url.indexOf('/staff/portal/finance/context') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          clinic_id: 7,
          location_id: 14,
          locations: [{ id: 14, name: 'شعبه ۱' }],
          can_issue_invoice: false,
          can_capture_payment: false,
          can_check_out: false
        }
      });
    }
    if (url.indexOf('/visits/88/billing') !== -1) {
      return jsonResponse(200, {
        ok: true,
        data: {
          visit_id: 88,
          patient_name: 'بیمار',
          default_fee: 600000,
          snapshot_fee: 500000,
          override_fee: null,
          effective_fee: 500000,
          fee_source: 'checkin_snapshot',
          charges_total: 70000,
          expected_total: 570000,
          paid_amount: 0,
          remaining_balance: 570000,
          payment_status_label: 'پرداخت نشده',
          charges: [
            { description: 'پانسمان', unit_amount: 20000, quantity: 2, line_total: 40000, note: 'دو بار' }
          ],
          invoice: null
        }
      });
    }
    return jsonResponse(200, { ok: true, data: { date: '2026-10-23', jalali_date: '1405/08/01', location_id: 14, visits: [], has_more: false } });
  });

  vm.runInContext(scriptMatch[1], env.context);
  await flushPromises();
  await flushPromises();

  var rows = env.doc.querySelector('[data-role="finance-rows"]');
  rows.innerHTML = '<tr><td><button type="button" data-role="finance-billing-open" data-visit-id="88">جزئیات</button></td></tr>';
  env.doc.querySelector('[data-role="finance-billing-open"]').dispatch('click');
  await flushPromises();
  await flushPromises();

  var billingCall = calls.find(function (c) { return c.url.indexOf('/visits/88/billing') !== -1; });
  assert.ok(billingCall, 'Clicking billing details fetches the read-only itemized billing route');
  assert.strictEqual(billingCall.opts.headers['X-CPMS-Clinic-Id'], '7', 'Billing read keeps the trusted Clinic selector');
  var panel = env.doc.querySelector('[data-role="finance-billing"]');
  assert.strictEqual(panel.hidden, false, 'Billing panel opens from the row action');
  var stats = env.doc.querySelector('[data-role="finance-billing-stats"]');
  assert.ok(stats.innerHTML.indexOf('570000') !== -1, 'Billing panel renders the server total');
  assert.ok(stats.innerHTML.indexOf('500000') !== -1, 'Billing panel renders the check-in fee snapshot');
  assert.ok(stats.innerHTML.indexOf('تعرفه ثبتشده هنگام پذیرش') !== -1, 'Billing panel labels the snapshot source');
  assert.ok(stats.innerHTML.indexOf('ریال') !== -1, 'Billing panel labels Rial amounts');
  var charges = env.doc.querySelector('[data-role="finance-billing-charges"]');
  assert.ok(charges.innerHTML.indexOf('پانسمان') !== -1, 'Billing panel itemizes treatment charges');
  env.doc.querySelector('[data-role="finance-billing-close"]').dispatch('click');
  assert.strictEqual(panel.hidden, true, 'Billing panel closes without a server mutation');
}

// Iran V1 — plain-permalink REST URL construction for the messaging module.
// WordPress serves REST as ?rest_route=/clinic/v1 on sites without pretty
// permalinks; appending a path that itself carries ?limit=... must never put a
// second '?' inside the rest_route value.
function messagesModuleHtml(restRoot) {
  return (
    '<main data-role="messages-root" dir="rtl" lang="fa">' +
      '<select data-role="messages-clinic"></select>' +
      '<select data-role="messages-counterpart"></select>' +
      '<input data-role="messages-patient-id">' +
      '<input data-role="messages-visit-id">' +
      '<p data-role="messages-status"></p>' +
      '<span data-role="messages-unread-total">0</span>' +
      '<h2 data-role="messages-thread-title"></h2>' +
      '<ul data-role="messages-threads"></ul>' +
      '<div data-role="messages-list"></div>' +
      '<button type="button" data-role="messages-new"></button>' +
      '<button type="button" data-role="messages-refresh"></button>' +
      '<form data-role="messages-form">' +
        '<textarea data-role="messages-body"></textarea>' +
        '<button type="submit" data-role="messages-send"></button>' +
      '</form>' +
      '<script type="application/json" id="cpms-messages-config">' +
        JSON.stringify({ rest_root: restRoot, nonce: 'nonce-msg-1', clinics: [{ id: 7, name: 'مطب ۱' }] }) +
      '</script>' +
    '</main>'
  );
}

function plainRouteParts(url) {
  var parsed = new URL(url);
  return {
    route: parsed.searchParams.get('rest_route') || '',
    params: parsed.searchParams,
    questionMarks: url.split('?').length - 1
  };
}

async function testMessagesPlainPermalinkVm() {
  var messagesPhp = readPluginFile('templates/staff-messages.php');
  var scriptMatch = messagesPhp.match(/<script>\n([\s\S]*?)\n<\/script>/);
  assert.ok(scriptMatch && scriptMatch[1], 'Extracts messaging inline script');

  var calls = [];
  var env = createDomEnv(
    messagesModuleHtml('https://clinic.test/?rest_route=/clinic/v1'),
    function (url, opts) {
      calls.push({ url: url, opts: opts || {} });
      var parts = plainRouteParts(url);
      var route = parts.route;
      var method = String((opts && opts.method) || 'GET').toUpperCase();
      if (route === '/clinic/v1/staff/portal/messages/context') {
        return jsonResponse(200, { ok: true, data: {
          clinic_id: 7, organization_id: 3, actor_class: 'receptionist',
          counterparts: [{ wp_user_id: 9, display_name: 'پزشک', label: 'پزشک' }],
          unread_total: 0, retention_policy: 'append_only', max_message_chars: 2000
        } });
      }
      if (route === '/clinic/v1/staff/portal/messages/threads' && method === 'GET') {
        return jsonResponse(200, { ok: true, data: {
          threads: [{ id: 11, counterpart: { display_name: 'پزشک' }, unread_count: 1,
            last_message_excerpt: 'سلام', last_message_jalali: '۱۴۰۵/۰۸/۰۶' }],
          unread_total: 1, polling: { strategy: 'authenticated_incremental', interval_ms: 8000 }
        } });
      }
      if (route === '/clinic/v1/staff/portal/messages/threads' && method === 'POST') {
        return jsonResponse(201, { ok: true, data: { id: 11, counterpart: { display_name: 'پزشک' } } });
      }
      if (route === '/clinic/v1/staff/portal/messages/threads/11/messages' && method === 'GET') {
        return jsonResponse(200, { ok: true, data: {
          messages: [{ id: 1, body: 'سلام', mine: false, jalali_time: '۱۴۰۵/۰۸/۰۶' }],
          last_id: 1, unread_count: 0
        } });
      }
      if (route === '/clinic/v1/staff/portal/messages/threads/11/messages' && method === 'POST') {
        return jsonResponse(201, { ok: true, data: {
          id: 2, body: 'پاسخ', mine: true, jalali_time: '۱۴۰۵/۰۸/۰۶'
        } });
      }
      if (route === '/clinic/v1/staff/portal/messages/threads/11/read' && method === 'POST') {
        return jsonResponse(200, { ok: true, data: {
          thread_id: 11, last_read_message_id: 1, unread_count: 0, unread_total: 0
        } });
      }
      return jsonResponse(404, { ok: false, code: 'CLINIC_NOT_FOUND' });
    }
  );

  vm.runInContext(scriptMatch[1], env.context);
  await flushPromises();
  await flushPromises();

  function callsFor(route, method) {
    return calls.filter(function (c) {
      return plainRouteParts(c.url).route === route &&
        String((c.opts && c.opts.method) || 'GET').toUpperCase() === method;
    });
  }

  var contextCalls = callsFor('/clinic/v1/staff/portal/messages/context', 'GET');
  assert.strictEqual(contextCalls.length, 1, 'context is fetched exactly once');
  assert.strictEqual(contextCalls[0].opts.headers['X-CPMS-Clinic-Id'], '7', 'context keeps trusted Clinic header');

  var threadCalls = callsFor('/clinic/v1/staff/portal/messages/threads', 'GET');
  assert.ok(threadCalls.length >= 1, 'thread listing is fetched');
  var threadParts = plainRouteParts(threadCalls[0].url);
  assert.strictEqual(threadParts.params.get('limit'), '50', 'limit stays a separate query parameter');
  assert.strictEqual(threadParts.questionMarks, 1, 'thread listing URL has exactly one query separator');

  // Create a thread, open it (poll + read ack), then send a message.
  env.doc.querySelector('[data-role="messages-new"]').dispatch('click');
  await flushPromises();
  await flushPromises();
  await flushPromises();

  var pollCalls = callsFor('/clinic/v1/staff/portal/messages/threads/11/messages', 'GET');
  assert.ok(pollCalls.length >= 1, 'message polling is fetched');
  var pollParts = plainRouteParts(pollCalls[0].url);
  assert.strictEqual(pollParts.params.get('since_id'), '0', 'since_id stays a separate query parameter');
  assert.strictEqual(pollParts.params.get('limit'), '100', 'poll limit stays a separate query parameter');
  assert.strictEqual(pollParts.questionMarks, 1, 'polling URL has exactly one query separator');

  var readCalls = callsFor('/clinic/v1/staff/portal/messages/threads/11/read', 'POST');
  assert.strictEqual(readCalls.length, 1, 'read acknowledgment is posted exactly once');

  env.doc.querySelector('[data-role="messages-body"]').value = 'پاسخ';
  env.doc.querySelector('[data-role="messages-form"]').dispatch('submit');
  await flushPromises();
  await flushPromises();
  var sendCalls = callsFor('/clinic/v1/staff/portal/messages/threads/11/messages', 'POST');
  assert.strictEqual(sendCalls.length, 1, 'send is posted exactly once');
  assert.deepStrictEqual(JSON.parse(sendCalls[0].opts.body), { body: 'پاسخ' }, 'send body is preserved');

  // Explicit refresh exercises incremental polling with a non-zero since_id.
  env.doc.querySelector('[data-role="messages-refresh"]').dispatch('click');
  await flushPromises();
  await flushPromises();
  var refreshCalls = callsFor('/clinic/v1/staff/portal/messages/threads/11/messages', 'GET');
  var incremental = refreshCalls.some(function (c) {
    return plainRouteParts(c.url).params.get('since_id') === '2';
  });
  assert.ok(incremental, 'refresh polls with the last seen message id');

  calls.forEach(function (c) {
    assert.strictEqual(plainRouteParts(c.url).questionMarks, 1, 'no nested query separator in ' + c.url);
    assert.ok(plainRouteParts(c.url).route.indexOf('clinic/v1/staff/portal/messages/') !== -1,
      'route stays under the messages namespace');
  });
}

(async function main() {
  try {
    await testPatientPortalReschedule();
    await testPublicBookingOtpAndTripwires();
    testStaffTemplatesContracts();
    testPatientPortalCompletionTemplateContracts();
    await testFinanceReconciliationVm();
    await testFinanceSubmitSurvivesInFlightRead();
    await testFinanceBillingPanelVm();
    await testMessagesPlainPermalinkVm();
    console.log('OK: all Day-2 portal workflow JS & template assertions passed');
  } catch (err) {
    console.error('FAIL:', err && err.stack ? err.stack : err);
    process.exit(1);
  }
})();

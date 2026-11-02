/**
 * Package P3 — defect 6 regression spec for the LOCATION-FIRST order of the
 * public booking surface (assets/js/cpms-public-booking.js).
 *
 * It executes the REAL production script (not a reimplementation) against a
 * hand-written DOM stub with zero external dependencies and zero network
 * access, then drives the surface the way a visitor does.
 *
 * Contract pinned here:
 *  1. With a server-published Location list (`config.locations_by_clinic`)
 *     the doctor list stays DISABLED until a Location is chosen, and only the
 *     doctors eligible at the chosen Location become available.
 *  2. Changing the Location CLEARS the chosen doctor, the chosen day and the
 *     rendered slots (no doctor/day/slot may survive a Location change).
 *  3. A surface without a server-published Location list keeps the previous
 *     behaviour (Locations derived from A1 availability), so an older
 *     deployment is not broken by this change.
 *
 * Run standalone:  node clinic-practice-management/tests/js/pkg-p3-location-first.spec.js
 *
 * NOTE (honesty): CI does NOT execute the files under tests/js (no workflow
 * runs node; verified 2026-11-02). This spec is real executable evidence only
 * when run manually, and it is NOT a substitute for the browser pilot.
 *
 * Exit code 0 = all assertions passed. Non-zero = at least one failure.
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');
var assert = require('assert');

var scriptPath = path.join(__dirname, '..', '..', 'assets', 'js', 'cpms-public-booking.js');
var code = fs.readFileSync(scriptPath, 'utf8');

// ---------------------------------------------------------------------------
// Minimal DOM: nodes are built programmatically (no HTML parser needed).
// ---------------------------------------------------------------------------

function setAttr(node, name, value) {
  node._attrs[name] = String(value);
  if (name === 'class') {
    node.className = String(value);
  }
}

function Node(tag) {
  this.nodeType = 1;
  this.tagName = String(tag || 'div').toUpperCase();
  this._attrs = {};
  this.children = [];
  this.parentElement = null;
  this.disabled = false;
  this.value = '';
  this._text = '';
  this.listeners = {};
}

Object.defineProperty(Node.prototype, 'className', {
  get: function () { return this._attrs.class || ''; },
  set: function (v) { this._attrs.class = String(v); }
});

Object.defineProperty(Node.prototype, 'id', {
  get: function () { return this._attrs.id || ''; },
  set: function (v) { this._attrs.id = String(v); }
});

Object.defineProperty(Node.prototype, 'hidden', {
  get: function () { return Object.prototype.hasOwnProperty.call(this._attrs, 'hidden'); },
  set: function (v) {
    if (v) { this._attrs.hidden = ''; } else { delete this._attrs.hidden; }
  }
});

Object.defineProperty(Node.prototype, 'textContent', {
  get: function () {
    if (this.children.length === 0) { return this._text; }
    return this.children.map(function (c) { return c.textContent; }).join('');
  },
  set: function (v) {
    this.children = [];
    this._text = String(v);
  }
});

Object.defineProperty(Node.prototype, 'firstChild', {
  get: function () { return this.children.length ? this.children[0] : null; }
});

Object.defineProperty(Node.prototype, 'firstElementChild', {
  get: function () { return this.children.length ? this.children[0] : null; }
});

Node.prototype.getAttribute = function (name) {
  return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null;
};
Node.prototype.setAttribute = function (name, value) { setAttr(this, name, value); };
Node.prototype.hasAttribute = function (name) {
  return Object.prototype.hasOwnProperty.call(this._attrs, name);
};
Node.prototype.removeAttribute = function (name) { delete this._attrs[name]; };

Node.prototype.appendChild = function (child) {
  child.parentElement = this;
  this.children.push(child);
  return child;
};

Node.prototype.removeChild = function (child) {
  var at = this.children.indexOf(child);
  if (at >= 0) { this.children.splice(at, 1); }
  child.parentElement = null;
  return child;
};

function matchesSimple(node, sel) {
  sel = String(sel || '').trim();
  if (sel === '' || !node || node.nodeType !== 1) { return false; }
  var tag = sel.match(/^[a-zA-Z0-9]+/);
  if (tag && node.tagName.toLowerCase() !== tag[0].toLowerCase()) { return false; }
  var id = sel.match(/#([a-zA-Z0-9_-]+)/);
  if (id && node.getAttribute('id') !== id[1]) { return false; }
  var classes = sel.match(/\.[a-zA-Z0-9_-]+/g) || [];
  for (var i = 0; i < classes.length; i++) {
    var cls = classes[i].slice(1);
    if (String(node.getAttribute('class') || '').split(/\s+/).indexOf(cls) === -1) { return false; }
  }
  var attrRe = /\[([a-zA-Z0-9_:-]+)(?:="([^"]*)")?\]/g;
  var m;
  while ((m = attrRe.exec(sel)) !== null) {
    if (!node.hasAttribute(m[1])) { return false; }
    if (m[2] !== undefined && node.getAttribute(m[1]) !== m[2]) { return false; }
  }
  return true;
}

function matches(node, selector) {
  var groups = String(selector).split(',');
  for (var g = 0; g < groups.length; g++) {
    var parts = groups[g].trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) { continue; }
    if (!matchesSimple(node, parts[parts.length - 1])) { continue; }
    var cursor = node.parentElement;
    var ok = true;
    for (var p = parts.length - 2; p >= 0; p--) {
      while (cursor && !matchesSimple(cursor, parts[p])) { cursor = cursor.parentElement; }
      if (!cursor) { ok = false; break; }
      cursor = cursor.parentElement;
    }
    if (ok) { return true; }
  }
  return false;
}

Node.prototype.matches = function (selector) { return matches(this, selector); };

function descendants(node, out) {
  for (var i = 0; i < node.children.length; i++) {
    out.push(node.children[i]);
    descendants(node.children[i], out);
  }
  return out;
}

Node.prototype.querySelectorAll = function (selector) {
  return descendants(this, []).filter(function (n) { return matches(n, selector); });
};
Node.prototype.querySelector = function (selector) {
  var all = this.querySelectorAll(selector);
  return all.length ? all[0] : null;
};
Node.prototype.contains = function (other) {
  var cur = other;
  while (cur) {
    if (cur === this) { return true; }
    cur = cur.parentElement;
  }
  return false;
};
Node.prototype.closest = function (selector) {
  var cur = this;
  while (cur) {
    if (matches(cur, selector)) { return cur; }
    cur = cur.parentElement;
  }
  return null;
};
Node.prototype.cloneNode = function (deep) {
  var copy = new Node(this.tagName);
  var self = this;
  Object.keys(this._attrs).forEach(function (k) { copy._attrs[k] = self._attrs[k]; });
  copy.disabled = this.disabled;
  copy.value = this.value;
  copy._text = this._text;
  if (deep) {
    this.children.forEach(function (c) { copy.appendChild(c.cloneNode(true)); });
  }
  return copy;
};
Node.prototype.addEventListener = function (type, fn) {
  (this.listeners[type] = this.listeners[type] || []).push(fn);
};
Node.prototype.dispatch = function (type, extra) {
  var event = { type: type, target: this };
  Object.keys(extra || {}).forEach(function (k) { event[k] = extra[k]; });
  var cur = this;
  while (cur) {
    var list = cur.listeners[type] || [];
    for (var i = 0; i < list.length; i++) { list[i](event); }
    cur = cur.parentElement;
  }
};

// ---------------------------------------------------------------------------
// Tiny element factory: el(tag, attrs, children)
// ---------------------------------------------------------------------------
function el(tag, attrs, children) {
  var node = new Node(tag);
  Object.keys(attrs || {}).forEach(function (k) { setAttr(node, k, attrs[k]); });
  (children || []).forEach(function (c) { node.appendChild(c); });
  return node;
}

function text(value) {
  var node = new Node('span');
  node.textContent = value;
  return node;
}

function createEnv(root) {
  var document = {
    readyState: 'complete',
    _roots: [root],
    querySelectorAll: function (selector) {
      var out = [];
      this._roots.forEach(function (r) {
        if (matches(r, selector)) { out.push(r); }
        descendants(r, []).forEach(function (n) {
          if (matches(n, selector)) { out.push(n); }
        });
      });
      return out;
    },
    querySelector: function (selector) {
      var all = this.querySelectorAll(selector);
      return all.length ? all[0] : null;
    },
    createElement: function (tag) { return new Node(tag); },
    addEventListener: function () {}
  };

  var calls = [];
  var responses = {};
  var window = {
    fetch: function (url, opts) {
      calls.push({ url: String(url), opts: opts || {} });
      var key = Object.keys(responses).filter(function (frag) {
        return String(url).indexOf(frag) !== -1;
      })[0];
      var payload = key ? responses[key] : { ok: false, status: 404, body: { ok: false } };
      return Promise.resolve({
        ok: payload.status >= 200 && payload.status < 300,
        status: payload.status,
        json: function () { return Promise.resolve(payload.body); }
      });
    },
    Promise: Promise,
    sessionStorage: {
      store: {},
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(this.store, k) ? this.store[k] : null; },
      setItem: function (k, v) { this.store[k] = String(v); },
      removeItem: function (k) { delete this.store[k]; }
    }
  };

  var sandbox = { window: window, document: document, console: console, setTimeout: setTimeout, clearTimeout: clearTimeout };
  sandbox.window.document = document;
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);

  return { window: window, document: document, calls: calls, responses: responses };
}

function flush() {
  return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

// ---------------------------------------------------------------------------
// The surface markup the server now renders (contract under test)
// ---------------------------------------------------------------------------
function buildSurface(config) {
  var liA = el('li', {
    'class': 'cpms-public-booking__clinician-item cpms-public-booking__doctor',
    'data-clinic-id': '3',
    'data-clinician-id': '11',
    'data-specialty': 'قلب',
    'data-search': 'دکتر الف',
    'data-locations': '101'
  }, [el('button', {
    'type': 'button',
    'class': 'cpms-public-booking__clinician',
    'data-clinician-id': '11',
    'data-clinic-id': '3',
    'aria-pressed': 'false'
  }, [text('دکتر الف')])]);

  var liB = el('li', {
    'class': 'cpms-public-booking__clinician-item cpms-public-booking__doctor',
    'data-clinic-id': '3',
    'data-clinician-id': '12',
    'data-specialty': 'پوست',
    'data-search': 'دکتر ب',
    'data-locations': '101 102'
  }, [el('button', {
    'type': 'button',
    'class': 'cpms-public-booking__clinician',
    'data-clinician-id': '12',
    'data-clinic-id': '3',
    'aria-pressed': 'false'
  }, [text('دکتر ب')])]);

  var optionTemplate = el('template', { 'data-template': 'location-option' });
  optionTemplate.content = el('div', {}, [
    el('li', {}, [
      el('button', { 'type': 'button', 'data-role': 'location-option', 'aria-pressed': 'false' }, [
        el('span', { 'data-fill': 'name' }),
        el('span', { 'data-fill': 'address' })
      ])
    ])
  ]);

  var dayTemplate = el('template', { 'data-template': 'day' });
  dayTemplate.content = el('div', {}, [
    el('p', { 'data-fill': 'jalali' }),
    el('div', { 'data-host': 'slots' })
  ]);

  var slotTemplate = el('template', { 'data-template': 'slot' });
  slotTemplate.content = el('div', {}, [
    el('button', { 'type': 'button', 'class': 'cpms-public-booking__slot' }, [
      el('span', { 'data-fill': 'time' })
    ])
  ]);

  var configNode = el('script', { 'type': 'application/json', 'class': 'cpms-public-booking__config' });
  configNode.textContent = JSON.stringify(config);

  return el('section', { 'class': 'cpms-public-booking', 'data-clinic-id': '3', 'data-state': 'bookable' }, [
    el('div', { 'class': 'cpms-public-booking__body' }, [
      el('div', { 'class': 'cpms-public-booking__locations' }, [
        el('div', {
          'class': 'cpms-public-booking__location-picker',
          'data-role': 'location-picker',
          'hidden': ''
        }, [
          el('ul', { 'class': 'cpms-public-booking__location-list', 'data-role': 'location-options' }),
          el('div', { 'data-role': 'location-details', 'hidden': '' }, [
            el('b', { 'data-role': 'location-name' }),
            el('span', { 'data-role': 'location-address-row', 'hidden': '' }, [
              el('span', { 'data-role': 'location-address' })
            ])
          ]),
          el('p', { 'data-role': 'location-hint', 'hidden': '' })
        ])
      ]),
      el('div', { 'class': 'cpms-public-booking__clinicians' }, [
        el('ul', { 'data-role': 'clinicians' }, [liA, liB])
      ]),
      el('div', { 'data-role': 'panel', 'data-state': 'idle', 'aria-busy': 'false' }, [
        el('div', {}, [
          el('p', { 'class': 'cpms-public-booking__message', 'data-message': 'idle' }),
          el('p', { 'class': 'cpms-public-booking__message', 'data-message': 'location_required' }),
          el('p', { 'class': 'cpms-public-booking__message', 'data-message': 'selectable' }),
          el('p', { 'class': 'cpms-public-booking__message', 'data-message': 'empty' })
        ]),
        el('p', { 'data-role': 'detail', 'hidden': '' }),
        el('div', { 'data-role': 'days' })
      ]),
      optionTemplate,
      dayTemplate,
      slotTemplate,
      configNode
    ])
  ]);
}

var baseConfig = {
  surface_id: 'pb-p3',
  rest_root: 'https://clinic.test/wp-json/clinic/v1',
  availability_path: '/availability',
  quote_path: '/booking/quote',
  clinic_id: 3,
  state_vocabulary: ['idle', 'loading', 'selectable', 'location_required', 'bookable', 'policy_rejected', 'unavailable', 'empty', 'error'],
  initial_state: 'idle',
  locations_by_clinic: {
    '3': [
      { id: 101, name: 'شعبهٔ مرکزی', address: 'تهران' },
      { id: 102, name: 'شعبهٔ دوم', address: 'کرج' }
    ]
  }
};

var availabilityPayload = {
  ok: true,
  data: {
    days: [{
      date: '2026-10-25',
      jalali: '۱۴۰۵/۰۸/۰۳',
      slots: [
        { slot_id: 501, location_id: 101, location_name: 'شعبهٔ مرکزی', time: '09:30', duration_min: 20, capacity_left: 1 },
        { slot_id: 502, location_id: 102, location_name: 'شعبهٔ دوم', time: '11:30', duration_min: 20, capacity_left: 1 }
      ]
    }]
  }
};

function clinicianButtons(document) {
  return document.querySelectorAll('.cpms-public-booking__clinician');
}

async function main() {
  // =====================================================================
  // 1. Location FIRST: the doctor list is disabled until a Location is chosen
  // =====================================================================
  var env = createEnv(buildSurface(baseConfig));
  env.responses['/availability'] = { status: 200, body: availabilityPayload };
  await flush();
  await flush();

  var doc = env.document;
  var buttons = clinicianButtons(doc);

  assert.strictEqual(buttons.length, 2, 'both clinicians render');
  assert.strictEqual(
    buttons[0].disabled,
    true,
    'LOCATION FIRST: a doctor button must stay disabled until a Location is chosen'
  );
  assert.strictEqual(
    buttons[1].disabled,
    true,
    'LOCATION FIRST: both doctor buttons stay disabled until a Location is chosen'
  );
  assert.strictEqual(env.calls.length, 0, 'no availability request may fire before a Location is chosen');

  var picker = doc.querySelector('[data-role="location-picker"]');
  assert.strictEqual(picker.hidden, false, 'the server-published Location list is offered immediately');

  var options = doc.querySelectorAll('[data-role="location-option"]');
  assert.strictEqual(options.length, 2, 'both server-published Locations are offered');
  assert.deepStrictEqual(
    options.map(function (o) { return o.getAttribute('data-location-id'); }),
    ['101', '102'],
    'Location options carry the server ids in server order'
  );
  assert.strictEqual(
    options[0].getAttribute('aria-pressed'),
    'false',
    'NO DEFAULT LOCATION: the first Location is not preselected'
  );

  // =====================================================================
  // 2. Choosing a Location enables only the clinicians eligible there
  // =====================================================================
  options[0].dispatch('click');
  await flush();

  buttons = clinicianButtons(doc);
  assert.strictEqual(options[0].getAttribute('aria-pressed'), 'true', 'the chosen Location is marked pressed');
  assert.strictEqual(buttons[0].disabled, false, 'the clinician eligible at the chosen Location is enabled');
  assert.strictEqual(buttons[1].disabled, false, 'a clinician eligible at both Locations is enabled too');

  var itemB = doc.querySelector('.cpms-public-booking__clinician-item[data-clinician-id="12"]');
  assert.notStrictEqual(itemB, null, 'the second clinician item is present');

  // =====================================================================
  // 3. Choosing the doctor loads its availability (unchanged A1 contract)
  // =====================================================================
  buttons[0].dispatch('click');
  await flush();
  await flush();

  assert.strictEqual(env.calls.length, 1, 'exactly one availability request for the chosen doctor');
  assert.ok(
    env.calls[0].url.indexOf('clinician_id=11') !== -1,
    'the availability request carries the chosen clinician id'
  );
  assert.strictEqual(
    doc.querySelectorAll('.cpms-public-booking__slot').length,
    1,
    'only the slots of the CHOSEN Location are rendered'
  );
  var firstSlot = doc.querySelector('.cpms-public-booking__slot');
  assert.strictEqual(firstSlot.getAttribute('data-slot-id'), '501', 'the slot of the chosen Location is rendered');
  assert.strictEqual(firstSlot.getAttribute('data-location-id'), '101', 'slot keeps its own Location id');

  // =====================================================================
  // 4. Changing the Location clears doctor, day and slots
  // =====================================================================
  options = doc.querySelectorAll('[data-role="location-option"]');
  options[1].dispatch('click');
  await flush();

  buttons = clinicianButtons(doc);
  assert.strictEqual(
    buttons[0].getAttribute('aria-pressed'),
    'false',
    'CHANGING LOCATION clears the chosen doctor'
  );
  assert.strictEqual(
    doc.querySelectorAll('.cpms-public-booking__slot').length,
    0,
    'CHANGING LOCATION clears the rendered slots and days'
  );
  assert.strictEqual(
    doc.querySelectorAll('[data-role="days"] .cpms-public-booking__slot').length,
    0,
    'the day host is empty after a Location change'
  );
  assert.strictEqual(
    buttons[0].disabled,
    true,
    'a doctor NOT eligible at the new Location is disabled after the change'
  );
  assert.strictEqual(
    buttons[1].disabled,
    false,
    'a doctor eligible at the new Location is enabled after the change'
  );

  // Selecting a doctor again works from a clean slate.
  buttons[1].dispatch('click');
  await flush();
  await flush();
  assert.strictEqual(env.calls.length, 2, 'a second availability request follows the second choice');
  assert.strictEqual(
    doc.querySelectorAll('.cpms-public-booking__slot').length,
    1,
    'the new Location renders its own slot only'
  );

  // =====================================================================
  // 5. Backward compatibility: no server Location list => previous behaviour
  // =====================================================================
  var legacyConfig = Object.assign({}, baseConfig);
  delete legacyConfig.locations_by_clinic;
  var legacyEnv = createEnv(buildSurface(legacyConfig));
  legacyEnv.responses['/availability'] = { status: 200, body: availabilityPayload };
  await flush();
  await flush();

  var legacyButtons = clinicianButtons(legacyEnv.document);
  assert.strictEqual(
    legacyButtons[0].disabled,
    false,
    'WITHOUT a server Location list the doctor list is not gated (older contract kept working)'
  );
  legacyButtons[0].dispatch('click');
  await flush();
  await flush();
  assert.strictEqual(
    legacyEnv.document.querySelectorAll('[data-role="location-option"]').length,
    2,
    'the legacy path still derives the Location options from A1 availability'
  );

  console.log('PASS pkg-p3-location-first: location-first order, reset on change and legacy fallback');
}

main().then(
  function () { process.exit(0); },
  function (error) {
    console.error('FAIL pkg-p3-location-first: ' + (error && error.message ? error.message : String(error)));
    process.exit(1);
  }
);

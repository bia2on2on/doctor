/**
 * Package "native dialogs" (Defect 9, partial) — patient-portal cancel behaviour.
 *
 * Executes the REAL shipped `assets/js/cpms-patient-portal.js` together with the
 * REAL shared dialog `assets/js/cpms-dialog.js` against a hand-written DOM stub
 * (no jsdom, no npm dependency, no network):
 *
 *  1. Clicking `button[data-role="cancel-appointment"][data-cpms-confirm]` opens
 *     the SHARED in-page dialog — `window.confirm/prompt/alert` are never called.
 *  2. Escape closes the dialog: no request, focus returns to the trigger, the
 *     `data-cpms-confirm` hook stays on the button.
 *  3. Accepting the dialog sends exactly one same-origin cancel POST; when the
 *     server answers with an error, the button is usable again, `aria-busy` is
 *     cleared, `data-cpms-confirm` is RESTORED, the Persian alert shows, and
 *     focus is back on the trigger button.
 *  4. A second click while the dialog is pending never stacks a second dialog.
 *  5. A network-level failure restores the button exactly like a server error.
 *
 * Run standalone: node clinic-practice-management/tests/js/pkg-native-dialogs-portal.spec.js
 * Shelled out to (FAIL, not skip, when node is missing) from
 * ClinicCore\Tests\Integration\PkgNativeDialogsTest (same mechanism as PkgP1JalaliAssetsTest).
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var pluginRoot = path.join(__dirname, '..', '..');

function read(rel) {
  return fs.readFileSync(path.join(pluginRoot, rel), 'utf8');
}

var failures = 0;
var checks = 0;

function fail(name, message) {
  failures++;
  console.error('FAIL - ' + name + ': ' + message);
}

function pass(name) {
  console.log('ok - ' + name);
}

var CONFIRM_MSG = 'نوبت ۱۴۰۴/۰۸/۱۲ ساعت ۱۰:۳۰ لغو شود؟ این کار قابل بازگشت نیست.';
var CANCEL_URL = 'http://portal.test/wp-json/clinic/v1/appointments/7/cancel';
var SERVER_ERROR = { code: 'CLINIC_POLICY_VIOLATION', message: 'مهلت لغو این نوبت گذشته است.' };

/* ------------------------------------------------------------------------- *
 * Minimal DOM stub — only what the two shipped assets actually touch.
 * ------------------------------------------------------------------------- */

function matches(el, selector) {
  return selector.split(',').some(function (part) {
    part = part.trim();
    // No descendant combinators are needed by the fixtures below.
    if (part === '' || part.indexOf(' ') !== -1) {
      return false;
    }
    var m = /^([a-zA-Z][a-zA-Z0-9]*)?((?:\.[A-Za-z0-9_-]+|\[[^\]]+\])*)$/.exec(part);
    if (!m) {
      return false;
    }
    var tag = m[1] ? m[1].toUpperCase() : '';
    if (tag && el.tagName !== tag) {
      return false;
    }
    var tokens = (m[2] || '').match(/\.[A-Za-z0-9_-]+|\[[^\]]+\]/g) || [];
    for (var i = 0; i < tokens.length; i++) {
      var t = tokens[i];
      if (t.charAt(0) === '.') {
        var cls = ' ' + (el.className || '') + ' ';
        if (cls.indexOf(' ' + t.slice(1) + ' ') === -1) {
          return false;
        }
      } else {
        var inner = t.slice(1, -1);
        var eq = inner.indexOf('=');
        if (eq === -1) {
          if (!el.hasAttribute(inner)) {
            return false;
          }
        } else {
          var name = inner.slice(0, eq).trim();
          var val = inner.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
          if (el.getAttribute(name) !== val) {
            return false;
          }
        }
      }
    }
    return true;
  });
}

function createDocument() {
  var doc = {};

  function El(tag) {
    this.tagName = String(tag).toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.attrs = {};
    this.listeners = {};
    this.className = '';
    this.id = '';
    this.textContent = '';
    this.value = '';
    this.hidden = false;
    this.type = '';
    this.rows = 0;
    this.disabled = false;
  }
  El.prototype.setAttribute = function (name, value) {
    this.attrs[name] = String(value);
    if (name === 'class') {
      this.className = String(value);
    }
    if (name === 'id') {
      this.id = String(value);
    }
  };
  El.prototype.getAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
  };
  El.prototype.hasAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(this.attrs, name);
  };
  El.prototype.removeAttribute = function (name) {
    delete this.attrs[name];
  };
  El.prototype.appendChild = function (child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  };
  El.prototype.removeChild = function (child) {
    var i = this.children.indexOf(child);
    if (i !== -1) {
      this.children.splice(i, 1);
    }
    child.parentNode = null;
    return child;
  };
  El.prototype.addEventListener = function (type, fn) {
    (this.listeners[type] = this.listeners[type] || []).push(fn);
  };
  El.prototype.removeEventListener = function (type, fn) {
    var list = this.listeners[type] || [];
    var i = list.indexOf(fn);
    if (i !== -1) {
      list.splice(i, 1);
    }
  };
  El.prototype.focus = function () {
    doc.activeElement = this;
  };
  El.prototype.select = function () {
    this.wasSelected = true;
  };
  El.prototype.click = function () {
    fire(this, 'click', {});
  };
  El.prototype.listenerCount = function (type) {
    return (this.listeners[type] || []).length;
  };
  El.prototype.querySelectorAll = function (selector) {
    var out = [];
    (function walk(node) {
      (node.children || []).forEach(function (child) {
        if (matches(child, selector)) {
          out.push(child);
        }
        walk(child);
      });
    })(this);
    return out;
  };
  El.prototype.querySelector = function (selector) {
    return this.querySelectorAll(selector)[0] || null;
  };
  // Minimal innerHTML parser — just enough for the markup the shipped assets
  // inject (elements + class/type/role attributes + nesting; text is ignored).
  Object.defineProperty(El.prototype, 'innerHTML', {
    set: function (html) {
      this.children = [];
      var stack = [this];
      var re = /<(\/?)([a-zA-Z0-9]+)((?:\s+[\w-]+(?:="[^"]*")?)*)\s*(\/?)>/g;
      var m;
      while ((m = re.exec(String(html)))) {
        if (m[1] === '/') {
          if (stack.length > 1) {
            stack.pop();
          }
          continue;
        }
        var node = new El(m[2]);
        var attrs = m[3] || '';
        var are = /([\w-]+)(?:="([^"]*)")?/g;
        var a;
        while ((a = are.exec(attrs))) {
          node.setAttribute(a[1], a[2] === undefined ? '' : a[2]);
        }
        stack[stack.length - 1].appendChild(node);
        if (m[4] !== '/' && !/^(br|img|input|hr|meta|link)$/.test(m[2].toLowerCase())) {
          stack.push(node);
        }
      }
    },
    get: function () {
      return '';
    }
  });

  doc.El = El;
  doc.createEl = function (tag) {
    return new El(tag);
  };
  doc.createElement = doc.createEl;
  doc.documentElement = new El('html');
  doc.head = new El('head');
  doc.body = new El('body');
  doc.documentElement.appendChild(doc.head);
  doc.documentElement.appendChild(doc.body);
  doc.readyState = 'complete';
  doc.activeElement = doc.body;
  doc.listeners = {};
  doc.addEventListener = function (type, fn) {
    (doc.listeners[type] = doc.listeners[type] || []).push(fn);
  };
  doc.removeEventListener = function (type, fn) {
    var list = doc.listeners[type] || [];
    var i = list.indexOf(fn);
    if (i !== -1) {
      list.splice(i, 1);
    }
  };
  doc.listenerCount = function (type) {
    return (doc.listeners[type] || []).length;
  };
  doc.getElementById = function (id) {
    var found = null;
    (function walk(node) {
      if (found) {
        return;
      }
      if (node.id === id) {
        found = node;
        return;
      }
      (node.children || []).forEach(walk);
    })(doc.documentElement);
    return found;
  };
  doc.registered = [];
  doc.register = function (el) {
    doc.registered.push(el);
    doc.body.appendChild(el);
    return el;
  };
  doc.querySelectorAll = function (selector) {
    return doc.registered.filter(function (el) {
      return matches(el, selector);
    });
  };
  doc.querySelector = function (selector) {
    return doc.querySelectorAll(selector)[0] || null;
  };

  function fire(target, type, props) {
    var event = {
      type: type,
      target: target,
      shiftKey: !!(props && props.shiftKey),
      key: props && props.key,
      defaultPrevented: false,
      propagationStopped: false,
      preventDefault: function () {
        event.defaultPrevented = true;
      },
      stopPropagation: function () {
        event.propagationStopped = true;
      }
    };
    (target.listeners[type] || []).slice().forEach(function (fn) {
      fn(event);
    });
    if (!event.propagationStopped) {
      (doc.listeners[type] || []).slice().forEach(function (fn) {
        fn(event);
      });
    }
    return event;
  }

  doc.fire = fire;
  doc.keydown = function (key, target, shiftKey) {
    return fire(doc, 'keydown', { key: key, shiftKey: !!shiftKey, target: target });
  };
  doc.overlays = function () {
    return doc.body.children.filter(function (child) {
      return String(child.className).indexOf('cpms-modal-overlay') !== -1;
    });
  };
  doc.dialogButtons = function (overlay) {
    var buttons = [];
    (function walk(node) {
      if (node.tagName === 'BUTTON') {
        buttons.push(node);
      }
      (node.children || []).forEach(walk);
    })(overlay);
    return buttons;
  };
  return doc;
}

function loadEnv() {
  var doc = createDocument();
  var sandbox = {
    document: doc,
    console: console,
    location: { hash: '' },
    nativeCalls: [],
    fetchCalls: [],
    fetchImpl: null
  };
  sandbox.window = sandbox;
  sandbox.addEventListener = function () {};
  sandbox.removeEventListener = function () {};
  sandbox.confirm = function (message) {
    sandbox.nativeCalls.push(['confirm', message]);
    return true;
  };
  sandbox.prompt = function (message) {
    sandbox.nativeCalls.push(['prompt', message]);
    return '';
  };
  sandbox.alert = function (message) {
    sandbox.nativeCalls.push(['alert', message]);
  };
  sandbox.fetch = function (url, options) {
    sandbox.fetchCalls.push({ url: String(url), options: options || {} });
    return sandbox.fetchImpl(url, options);
  };
  vm.createContext(sandbox);

  // The shared in-page dialog loads first, exactly like the page <head> does.
  vm.runInContext(read('assets/js/cpms-dialog.js'), sandbox, { filename: 'cpms-dialog.js' });

  // Fixtures: runtime config script, one cancel button, one hidden alert box.
  var cfg = doc.createEl('script');
  cfg.setAttribute('class', 'cpms-patient-portal__config');
  cfg.textContent = JSON.stringify({
    rest_root: 'http://portal.test/wp-json/clinic/v1',
    nonce: 'nonce-abc',
    cancel_path: '/appointments/{id}/cancel'
  });
  doc.register(cfg);

  var button = doc.createEl('button');
  button.type = 'button';
  button.setAttribute('data-role', 'cancel-appointment');
  button.setAttribute('data-appointment-id', '7');
  button.setAttribute('data-cpms-confirm', CONFIRM_MSG);
  button.textContent = 'لغو نوبت';
  doc.register(button);

  var errorBox = doc.createEl('div');
  errorBox.setAttribute('data-role', 'cancel-error');
  errorBox.setAttribute('role', 'alert');
  errorBox.hidden = true;
  errorBox.appendChild(doc.createEl('p'));
  doc.register(errorBox);

  // The portal asset initialises immediately (readyState === 'complete').
  vm.runInContext(read('assets/js/cpms-patient-portal.js'), sandbox, { filename: 'cpms-patient-portal.js' });

  return { sandbox: sandbox, doc: doc, button: button, errorBox: errorBox };
}

function flush() {
  return new Promise(function (resolve) {
    setImmediate(resolve);
  });
}

function serverErrorFetch() {
  return function () {
    return Promise.resolve({
      ok: false,
      status: 409,
      json: function () {
        return Promise.resolve(SERVER_ERROR);
      }
    });
  };
}

function networkErrorFetch() {
  return function () {
    return Promise.reject(new Error('network down'));
  };
}

var queue = [];
function check(name, fn) {
  queue.push({ name: name, fn: fn });
}

async function run() {
  for (var i = 0; i < queue.length; i++) {
    checks++;
    try {
      await queue[i].fn();
      pass(queue[i].name);
    } catch (e) {
      fail(queue[i].name, (e && e.message) || String(e));
    }
  }
  console.log('---');
  if (failures > 0) {
    console.error(failures + '/' + checks + ' assertion(s) failed');
    process.exit(1);
  }
  console.log('OK: pkg-native-dialogs portal spec (' + checks + ' checks, 0 failures)');
}

function assert(cond, message) {
  if (!cond) {
    throw new Error(message);
  }
}

/* ------------------------------------------------------------------------- *
 * 1) Click opens the SHARED dialog — never window.confirm/prompt/alert.
 * ------------------------------------------------------------------------- */

check('click opens the shared in-page dialog; native dialogs stay untouched', async function () {
  var env = loadEnv();
  var doc = env.doc;
  env.sandbox.fetchImpl = serverErrorFetch();
  env.button.focus();
  env.button.click();
  await flush();

  assert(env.sandbox.nativeCalls.length === 0,
    'window.confirm/prompt/alert must never be called, got ' + JSON.stringify(env.sandbox.nativeCalls));
  assert(doc.overlays().length === 1, 'exactly one dialog overlay must open, got ' + doc.overlays().length);
  var overlay = doc.overlays()[0];
  assert(String(overlay.className).indexOf('cpms-modal-overlay') !== -1,
    'the overlay must keep the shipped acceptance token cpms-modal-overlay');
  var buttons = doc.dialogButtons(overlay);
  assert(buttons.length === 2, 'the dialog must expose confirm + cancel buttons, got ' + buttons.length);
  assert(/confirm/.test(buttons[0].className), 'the confirm button must keep the confirm class token');
  assert(env.button.hasAttribute('data-cpms-confirm'),
    'the confirm hook must stay on the button while the dialog is pending');
  assert(env.sandbox.fetchCalls.length === 0, 'no request may fire before the user confirms');
});

/* ------------------------------------------------------------------------- *
 * 2) Escape = cancel: no request, focus back on the trigger, hook kept.
 * ------------------------------------------------------------------------- */

check('Escape cancels: no request, focus returns to the trigger, hook kept', async function () {
  var env = loadEnv();
  var doc = env.doc;
  env.sandbox.fetchImpl = serverErrorFetch();
  env.button.focus();
  env.button.click();
  await flush();
  assert(doc.overlays().length === 1, 'dialog must be open');

  doc.keydown('Escape', doc.activeElement, false);
  await flush();

  assert(doc.overlays().length === 0, 'Escape must close the dialog');
  assert(env.sandbox.fetchCalls.length === 0, 'Escape must not send any request');
  assert(doc.activeElement === env.button, 'focus must return to the trigger button after the dialog closes');
  assert(env.button.hasAttribute('data-cpms-confirm'), 'cancel must keep data-cpms-confirm');
  assert(env.button.disabled === false, 'cancel must leave the button enabled');
  assert(env.sandbox.nativeCalls.length === 0, 'native dialogs must stay untouched');
});

/* ------------------------------------------------------------------------- *
 * 3) Accept → one cancel POST → server error → button/hook/aria-busy/focus restored.
 * ------------------------------------------------------------------------- */

check('accept sends one cancel POST; a server error restores button, hook, aria-busy and focus', async function () {
  var env = loadEnv();
  var doc = env.doc;
  env.sandbox.fetchImpl = serverErrorFetch();
  env.button.focus();
  env.button.click();
  await flush();
  var overlay = doc.overlays()[0];
  var confirmBtn = doc.dialogButtons(overlay)[0];
  doc.fire(confirmBtn, 'click', {});
  await flush();
  await flush();

  assert(env.sandbox.fetchCalls.length === 1, 'exactly one cancel request expected, got ' + env.sandbox.fetchCalls.length);
  var call = env.sandbox.fetchCalls[0];
  assert(call.url === CANCEL_URL, 'the request must target the B4 cancel route, got ' + call.url);
  assert(call.options.method === 'POST', 'the cancel request must be POST');
  assert(call.options.headers && call.options.headers['X-WP-Nonce'] === 'nonce-abc', 'the wp_rest nonce must be sent');
  assert(doc.overlays().length === 0, 'the dialog must be closed after accepting');

  // While in flight the button was busy; after the error everything is restored.
  assert(env.button.disabled === false, 'the button must be usable again after a cancel error');
  assert(env.button.getAttribute('aria-busy') === 'false', 'aria-busy must be cleared after a cancel error');
  assert(env.button.textContent === 'لغو نوبت', 'the idle label must be restored, got ' + env.button.textContent);
  assert(env.button.hasAttribute('data-cpms-confirm'),
    'data-cpms-confirm must be RESTORED after a cancel error so the next click asks again');
  assert(env.button.getAttribute('data-cpms-confirm') === CONFIRM_MSG,
    'the restored hook must carry the original message');
  assert(doc.activeElement === env.button, 'focus must be on the trigger button after the error');
  assert(env.errorBox.hidden === false, 'the Persian alert region must be visible');
  assert(env.errorBox.getAttribute('data-error-code') === 'CLINIC_POLICY_VIOLATION',
    'the alert must carry the server code, got ' + env.errorBox.getAttribute('data-error-code'));
  var alertText = (env.errorBox.querySelector('p') || env.errorBox).textContent;
  assert(/[\u0600-\u06FF]/.test(alertText), 'the alert text must be Persian, got ' + alertText);
  assert(alertText === SERVER_ERROR.message, 'the server message must be shown verbatim');
  assert(env.sandbox.nativeCalls.length === 0, 'native dialogs must stay untouched');
});

/* ------------------------------------------------------------------------- *
 * 4) Double activation while the dialog is pending — one dialog only.
 * ------------------------------------------------------------------------- */

check('a second click while the dialog is pending never stacks a second dialog', async function () {
  var env = loadEnv();
  var doc = env.doc;
  env.sandbox.fetchImpl = serverErrorFetch();
  env.button.focus();
  env.button.click();
  await flush();
  env.button.click();
  await flush();

  assert(doc.overlays().length === 1, 'exactly one dialog may be live, got ' + doc.overlays().length);
  assert(env.sandbox.fetchCalls.length === 0, 'no request may fire while pending');
  doc.keydown('Escape', doc.activeElement, false);
  await flush();
  assert(env.sandbox.fetchCalls.length === 0, 'Escape must still cancel cleanly');
  assert(doc.activeElement === env.button, 'focus must return to the trigger');
});

/* ------------------------------------------------------------------------- *
 * 5) Network-level failure — the same restore contract.
 * ------------------------------------------------------------------------- */

check('a network failure restores button, hook, aria-busy and focus too', async function () {
  var env = loadEnv();
  var doc = env.doc;
  env.sandbox.fetchImpl = networkErrorFetch();
  env.button.focus();
  env.button.click();
  await flush();
  var overlay = doc.overlays()[0];
  doc.fire(doc.dialogButtons(overlay)[0], 'click', {});
  await flush();
  await flush();

  assert(env.sandbox.fetchCalls.length === 1, 'exactly one attempt expected');
  assert(env.button.disabled === false, 'the button must be usable again after a network error');
  assert(env.button.getAttribute('aria-busy') === 'false', 'aria-busy must be cleared after a network error');
  assert(env.button.hasAttribute('data-cpms-confirm'), 'data-cpms-confirm must be restored after a network error');
  assert(doc.activeElement === env.button, 'focus must be on the trigger after a network error');
  assert(env.errorBox.hidden === false, 'the alert region must be visible');
  assert(env.errorBox.getAttribute('data-error-code') === 'NETWORK', 'the code must be NETWORK');
  assert(env.sandbox.nativeCalls.length === 0, 'native dialogs must stay untouched');
});

run();

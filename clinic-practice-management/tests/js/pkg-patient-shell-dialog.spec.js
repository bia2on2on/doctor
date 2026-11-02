/**
 * Patient shell — the shared in-page dialog must satisfy the SHIPPED browser
 * harness selectors (additive compatibility classes only).
 *
 * Executes the REAL shipped `assets/js/cpms-dialog.js` against a hand-written DOM
 * stub (no jsdom, no npm dependency, no network) and evaluates the selectors that
 * the shipped Chromium journey `bin/pilot-slice-portal-cancel.py` waits for — read
 * from that file at run time, so the spec follows the harness instead of freezing
 * a copy of it:
 *
 *   DIALOG = '.cpms-modal[role="dialog"]'      → the confirm dialog root
 *   dialog.locator('.cpms-modal-confirm')      → exactly one confirm button
 *   '.cpms-modal-overlay'                      → bin/rwp-acceptance.py capture_confirm
 *
 * and asserts the classes are ADDED, never replaced (the existing
 * `cpms-dialog`/`cpms-dialog-overlay` tokens stay in place).
 *
 * Run standalone: node clinic-practice-management/tests/js/pkg-patient-shell-dialog.spec.js
 * Shelled out to (FAIL, not skip, when node is missing) from
 * ClinicCore\Tests\Integration\PkgPatientShellDialogTest.
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

/* ------------------------------------------------------------------------- *
 * Minimal DOM stub — only what assets/js/cpms-dialog.js actually touches.
 * ------------------------------------------------------------------------- */

function classList(el) {
  return (' ' + (el.className || '') + ' ').replace(/\s+/g, ' ');
}

function hasClass(el, name) {
  return classList(el).indexOf(' ' + name + ' ') !== -1;
}

/**
 * Match a compound CSS selector (`tag.class1[attr="value"]`) against one element.
 * No descendant combinators — the callers only need single-element selectors.
 */
function matches(el, selector) {
  if (selector.indexOf(' ') !== -1 && !/\[[^\]]* [^\]]*\]/.test(selector)) {
    return false;
  }
  var m = /^([a-zA-Z][a-zA-Z0-9]*)?((?:\.[A-Za-z0-9_-]+|\[[^\]]+\])*)$/.exec(selector.trim());
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
      if (!hasClass(el, t.slice(1))) {
        return false;
      }
      continue;
    }
    var inner = t.slice(1, -1);
    var eq = inner.indexOf('=');
    if (eq === -1) {
      if (!el.hasAttribute(inner)) {
        return false;
      }
      continue;
    }
    var name = inner.slice(0, eq).trim();
    var want = inner.slice(eq + 1).trim().replace(/^["']|["']$/g, '');
    if (el.getAttribute(name) !== want) {
      return false;
    }
  }
  return true;
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
  El.prototype.select = function () {};

  doc.El = El;
  doc.createElement = function (tag) {
    return new El(tag);
  };
  doc.documentElement = new El('html');
  doc.head = new El('head');
  doc.body = new El('body');
  doc.documentElement.appendChild(doc.head);
  doc.documentElement.appendChild(doc.body);
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
  doc.descendants = function (root) {
    var out = [];
    (function walk(node) {
      (node.children || []).forEach(function (child) {
        out.push(child);
        walk(child);
      });
    })(root);
    return out;
  };
  doc.find = function (selector) {
    return doc.descendants(doc.documentElement).filter(function (el) {
      return matches(el, selector);
    });
  };
  doc.keydown = function (key) {
    var event = {
      key: key,
      defaultPrevented: false,
      preventDefault: function () {
        this.defaultPrevented = true;
      }
    };
    (doc.listeners.keydown || []).slice().forEach(function (fn) {
      fn(event);
    });
    return event;
  };
  return doc;
}

function loadDialog() {
  var doc = createDocument();
  var sandbox = { document: doc, window: null, console: console };
  sandbox.window = sandbox;
  sandbox.CpmsDialog = undefined;
  vm.createContext(sandbox);
  vm.runInContext(read('assets/js/cpms-dialog.js'), sandbox, { filename: 'cpms-dialog.js' });
  return { sandbox: sandbox, doc: doc };
}

/** The selectors the shipped harness actually uses, read from the harness itself. */
function harnessSelectors() {
  var py = read('bin/pilot-slice-portal-cancel.py');
  var dialog = /^DIALOG\s*=\s*'([^']+)'\s*$/m.exec(py);
  if (!dialog) {
    throw new Error('could not read DIALOG from bin/pilot-slice-portal-cancel.py');
  }
  var confirmToken = /locator\("(\.[A-Za-z0-9_-]+)"\)/.exec(py);
  if (!confirmToken) {
    throw new Error('could not read the confirm-button locator from the pilot script');
  }
  return {
    dialog: dialog[1],
    confirm: confirmToken[1],
    overlay: '.cpms-modal-overlay'
  };
}

var queue = [];
function check(name, fn) {
  queue.push({ name: name, fn: fn });
}

check('harness selectors are read from the shipped pilot script', function () {
  var sel = harnessSelectors();
  if (sel.dialog !== '.cpms-modal[role="dialog"]') {
    throw new Error('unexpected DIALOG selector in the pilot script: ' + sel.dialog);
  }
  if (sel.confirm !== '.cpms-modal-confirm') {
    throw new Error('unexpected confirm locator in the pilot script: ' + sel.confirm);
  }
});

check('confirm dialog root carries the compatibility class cpms-modal AND role=dialog', function () {
  var env = loadDialog();
  var sel = harnessSelectors();
  env.sandbox.CpmsDialog.confirm('نوبت لغو شود؟', { title: 'تأیید عملیات' });

  var found = env.doc.find(sel.dialog);
  if (found.length !== 1) {
    throw new Error(
      'the pilot script waits for ' + sel.dialog + ' but ' + found.length + ' element(s) match; ' +
        'the dialog root must keep role="dialog" and gain the cpms-modal class'
    );
  }
  if (found[0].getAttribute('aria-modal') !== 'true') {
    throw new Error('the dialog root must stay aria-modal="true"');
  }
});

check('the cpms-modal class is ADDED, the existing cpms-dialog token is kept', function () {
  var env = loadDialog();
  env.sandbox.CpmsDialog.confirm('نوبت لغو شود؟', {});
  var roots = env.doc.find('.cpms-dialog');
  if (roots.length !== 1) {
    throw new Error('exactly one .cpms-dialog root expected, got ' + roots.length);
  }
  if (!hasClass(roots[0], 'cpms-modal')) {
    throw new Error('the dialog root must also carry cpms-modal (additive, never a replacement)');
  }
});

check('exactly one confirm button inside the dialog root and it carries cpms-modal-confirm', function () {
  var env = loadDialog();
  var sel = harnessSelectors();
  env.sandbox.CpmsDialog.confirm('نوبت لغو شود؟', {});
  var root = env.doc.find(sel.dialog)[0];
  var confirms = env.doc.descendants(root).filter(function (el) {
    return matches(el, sel.confirm);
  });
  if (confirms.length !== 1) {
    throw new Error('the pilot script clicks exactly one ' + sel.confirm + ', got ' + confirms.length);
  }
  if (confirms[0].tagName !== 'BUTTON') {
    throw new Error('the confirm control must stay a real <button>');
  }
});

check('the overlay keeps the cpms-modal-overlay acceptance token and contains the dialog', function () {
  var env = loadDialog();
  var sel = harnessSelectors();
  env.sandbox.CpmsDialog.confirm('نوبت لغو شود؟', {});
  var overlays = env.doc.find(sel.overlay);
  if (overlays.length !== 1) {
    throw new Error('bin/rwp-acceptance.py capture_confirm needs one .cpms-modal-overlay, got ' + overlays.length);
  }
  var root = env.doc.find(sel.dialog)[0];
  if (root.parentNode !== overlays[0]) {
    throw new Error('the dialog root must be a child of the overlay (harness scopes its locator there)');
  }
});

check('Escape still resolves the dialog as NOT confirmed and removes it (fail-closed)', function () {
  var env = loadDialog();
  var result = 'pending';
  env.sandbox.CpmsDialog.confirm('نوبت لغو شود؟', {}).then(function (value) {
    result = value;
  });
  env.doc.keydown('Escape');
  return Promise.resolve().then(function () {
    if (result !== false) {
      throw new Error('Escape must resolve false (never confirmed), got ' + JSON.stringify(result));
    }
    if (env.doc.find('.cpms-modal-overlay').length !== 0) {
      throw new Error('Escape must remove the overlay');
    }
  });
});

check('a prompt still renders exactly one input inside the dialog root', function () {
  var env = loadDialog();
  var sel = harnessSelectors();
  env.sandbox.CpmsDialog.prompt('دلیل لغو', { required: true });
  var root = env.doc.find(sel.dialog)[0];
  if (!root) {
    throw new Error('the prompt dialog must satisfy ' + sel.dialog + ' too');
  }
  var inputs = env.doc.descendants(root).filter(function (el) {
    return el.tagName === 'INPUT' || el.tagName === 'TEXTAREA';
  });
  if (inputs.length !== 1) {
    throw new Error('exactly one input expected in the prompt dialog, got ' + inputs.length);
  }
});

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
  console.log('\n' + checks + ' checks, ' + failures + ' failure(s)');
  process.exit(failures === 0 ? 0 : 1);
}

run();

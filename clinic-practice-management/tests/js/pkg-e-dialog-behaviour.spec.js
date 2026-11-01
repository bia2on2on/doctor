/**
 * Package E — behaviour regression for the shared dialog and its consumers.
 *
 * Executes the REAL shipped assets against a HAND-WRITTEN DOM stub (no jsdom, no
 * npm dependency, no network):
 *  - assets/js/cpms-dialog.js → one live dialog, D3 settle-previous-as-cancel,
 *    stale keydown handlers must not resolve an orphaned promise, focus return,
 *    Tab trap in both directions, Escape = cancel, Enter = confirm.
 *  - assets/js/cpms-admin.js  → `data-cpms-confirm` binding and the D2
 *    fail-closed native `window.confirm` fallback (cancel ⇒ no action).
 *
 * Run standalone: node clinic-practice-management/tests/js/pkg-e-dialog-behaviour.spec.js
 * Also shelled out to from ClinicCore\Tests\Integration\PkgEPersianTextTest.
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
 * Minimal DOM stub. Only what the two shipped assets actually touch.
 * ------------------------------------------------------------------------- */

function matches(el, selector) {
  return selector.split(',').some(function (part) {
    part = part.trim();
    if (part === '') {
      return false;
    }
    var m = /^([a-zA-Z][a-zA-Z0-9]*)?((?:\[[^\]]+\])*)(:not\(\[[^\]]+\]\))?$/.exec(part);
    if (!m) {
      return false;
    }
    var tag = m[1] ? m[1].toUpperCase() : '';
    if (tag && el.tagName !== tag) {
      return false;
    }
    var attrs = m[2] ? m[2].match(/\[[^\]]+\]/g) || [] : [];
    for (var i = 0; i < attrs.length; i++) {
      var name = attrs[i].slice(1, -1);
      if (!el.hasAttribute(name)) {
        return false;
      }
    }
    if (m[3]) {
      var excluded = /:not\(\[([^\]]+)\]\)/.exec(m[3])[1];
      if (el.hasAttribute(excluded)) {
        return false;
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
    // A programmatic click behaves like a user click: the event is dispatched and,
    // unless cancelled, the element's own default action runs.
    var event = fire(this, 'click', {});
    if (!event.defaultPrevented && typeof this.defaultAction === 'function') {
      this.defaultAction();
    }
  };
  El.prototype.listenerCount = function (type) {
    return (this.listeners[type] || []).length;
  };

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
  // How a browser performs a form submission. `guardRecursion` models the
  // HTML "firing submission events" guard (a requestSubmit() issued while a
  // submit event is already being dispatched is ignored); with it OFF we model
  // the behaviour documented on MDN, where requestSubmit() simply fires another
  // submit event. The spec asserts the SAME outcome under both models.
  doc.guardRecursion = false;

  doc.userSubmit = function (form) {
    var outer = form.inSubmit;
    form.inSubmit = true;
    var event = fire(form, 'submit', {});
    form.inSubmit = outer;
    if (!event.defaultPrevented) {
      form.nativeSubmits++;
    }
    return event;
  };

  doc.overlays = function () {
    return doc.body.children.filter(function (child) {
      return String(child.className).indexOf('cpms-modal-overlay') !== -1;
    });
  };
  return doc;
}

function loadDialog(sharedDoc) {
  var doc = sharedDoc || createDocument();
  var sandbox = { document: doc, window: null, console: console };
  sandbox.window = sandbox;
  sandbox.CpmsDialog = undefined;
  vm.createContext(sandbox);
  vm.runInContext(read('assets/js/cpms-dialog.js'), sandbox, { filename: 'cpms-dialog.js' });
  sandbox.doc = doc;
  return sandbox;
}

function loadAdmin(options) {
  var opts = options || {};
  var doc = opts.doc || createDocument();
  var sandbox = {
    document: doc,
    window: null,
    console: console,
    confirmCalls: [],
    nativeConfirmResult: true
  };
  sandbox.window = sandbox;
  if (opts.dialog) {
    sandbox.CpmsDialog = opts.dialog;
  }
  // cpms-admin.js binds on init(), so the fixtures must exist first.
  sandbox.fixtures = opts.fixtures ? opts.fixtures(doc) : {};
  sandbox.confirm = function (message) {
    sandbox.confirmCalls.push(message);
    if (opts.confirmThrows) {
      throw new Error('blocked');
    }
    return sandbox.nativeConfirmResult;
  };
  vm.createContext(sandbox);
  vm.runInContext(read('assets/js/cpms-admin.js'), sandbox, { filename: 'cpms-admin.js' });
  sandbox.doc = doc;
  return sandbox;
}

function flush() {
  return new Promise(function (resolve) {
    setImmediate(resolve);
  });
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
  console.log('OK: pkg-e-dialog behaviour spec (' + checks + ' checks, 0 failures)');
}

/* ------------------------------------------------------------------------- *
 * 1) cpms-dialog.js — confirm / prompt resolution
 * ------------------------------------------------------------------------- */

check('confirm: the confirm button resolves true and the overlay is removed', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var settled = 'pending';
  env.CpmsDialog.confirm('حذف شود؟').then(function (v) { settled = v; });
  await flush();

  var overlay = doc.overlays()[0];
  if (!overlay) { throw new Error('no overlay rendered'); }
  var confirmBtn = doc.getElementById('cpms-dialog-title') ? null : null;
  var buttons = [];
  (function walk(node) {
    if (node.tagName === 'BUTTON') { buttons.push(node); }
    (node.children || []).forEach(walk);
  })(overlay);
  if (buttons.length !== 2) { throw new Error('expected 2 buttons, got ' + buttons.length); }
  if (!/confirm/.test(buttons[0].className)) {
    throw new Error('the primary button must keep the shipped `confirm` class token');
  }
  doc.fire(buttons[0], 'click', {});
  await flush();

  if (settled !== true) { throw new Error('expected true, got ' + settled); }
  if (doc.overlays().length !== 0) { throw new Error('overlay left in the DOM'); }
  if (doc.listenerCount('keydown') !== 0) { throw new Error('keydown listener leaked'); }
  if (confirmBtn) { throw new Error('unreachable'); }
});

check('confirm: the cancel button resolves false', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var settled = 'pending';
  env.CpmsDialog.confirm('حذف شود؟').then(function (v) { settled = v; });
  await flush();
  var overlay = doc.overlays()[0];
  var buttons = [];
  (function walk(node) {
    if (node.tagName === 'BUTTON') { buttons.push(node); }
    (node.children || []).forEach(walk);
  })(overlay);
  doc.fire(buttons[1], 'click', {});
  await flush();
  if (settled !== false) { throw new Error('expected false, got ' + settled); }
  if (doc.overlays().length !== 0) { throw new Error('overlay left in the DOM'); }
});

check('confirm: Escape resolves false and Enter resolves true', async function () {
  var env = loadDialog();
  var doc = env.doc;

  var first = 'pending';
  env.CpmsDialog.confirm('الف').then(function (v) { first = v; });
  await flush();
  var esc = doc.keydown('Escape', doc.activeElement, false);
  await flush();
  if (first !== false) { throw new Error('Escape must cancel, got ' + first); }
  if (!esc.defaultPrevented) { throw new Error('Escape must be consumed'); }

  var second = 'pending';
  env.CpmsDialog.confirm('ب').then(function (v) { second = v; });
  await flush();
  doc.keydown('Enter', doc.activeElement, false);
  await flush();
  if (second !== true) { throw new Error('Enter must confirm, got ' + second); }
});

check('prompt: the value is returned and null means cancel', async function () {
  var env = loadDialog();
  var doc = env.doc;

  var typed = 'pending';
  env.CpmsDialog.prompt('دلیل؟').then(function (v) { typed = v; });
  await flush();
  var input = doc.activeElement;
  if (!input || input.tagName !== 'INPUT') { throw new Error('the prompt input must take focus'); }
  input.value = '  مرخصی  ';
  doc.keydown('Enter', input, false);
  await flush();
  if (typed !== 'مرخصی') { throw new Error('expected trimmed value, got ' + JSON.stringify(typed)); }

  var cancelled = 'pending';
  env.CpmsDialog.prompt('دلیل؟').then(function (v) { cancelled = v; });
  await flush();
  doc.keydown('Escape', doc.activeElement, false);
  await flush();
  if (cancelled !== null) { throw new Error('prompt cancel must be null, got ' + cancelled); }
});

check('prompt: a required blank value blocks submit and settles nothing', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var settled = 'pending';
  env.CpmsDialog.prompt('دلیل؟', { required: true }).then(function (v) { settled = v; });
  await flush();
  var input = doc.activeElement;
  input.value = '   ';
  doc.keydown('Enter', input, false);
  await flush();
  if (settled !== 'pending') { throw new Error('a blank required value must not settle'); }
  if (doc.overlays().length !== 1) { throw new Error('the dialog must stay open'); }
});

check('focus returns to the trigger after the dialog settles', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var trigger = doc.register(doc.createEl('button'));
  trigger.focus();
  env.CpmsDialog.confirm('ادامه؟');
  await flush();
  if (doc.activeElement === trigger) { throw new Error('focus must move into the dialog'); }
  doc.keydown('Escape', doc.activeElement, false);
  await flush();
  if (doc.activeElement !== trigger) { throw new Error('focus must return to the trigger'); }
});

check('Tab is trapped inside the dialog in both directions', async function () {
  var env = loadDialog();
  var doc = env.doc;
  env.CpmsDialog.confirm('ادامه؟');
  await flush();

  var overlay = doc.overlays()[0];
  var buttons = [];
  (function walk(node) {
    if (node.tagName === 'BUTTON') { buttons.push(node); }
    (node.children || []).forEach(walk);
  })(overlay);
  var confirmBtn = buttons[0];
  var cancelBtn = buttons[1];

  // focusables() === [confirm, cancel]: confirm is FIRST, cancel is LAST.
  cancelBtn.focus();
  var fwd = doc.keydown('Tab', cancelBtn, false);
  if (!fwd.defaultPrevented) { throw new Error('Tab off the last control must be intercepted'); }
  if (doc.activeElement !== confirmBtn) { throw new Error('Tab must wrap to the confirm button'); }

  var back = doc.keydown('Tab', confirmBtn, true);
  if (!back.defaultPrevented) { throw new Error('Shift+Tab off the first control must be intercepted'); }
  if (doc.activeElement !== cancelBtn) { throw new Error('Shift+Tab must wrap to the cancel button'); }

  confirmBtn.focus();
  var mid = doc.keydown('Tab', confirmBtn, false);
  if (mid.defaultPrevented) { throw new Error('Tab between the two controls must move natively'); }
});

/* ------------------------------------------------------------------------- *
 * 2) D3 — one live dialog only
 * ------------------------------------------------------------------------- */

check('D3: opening a second dialog settles the first as CANCEL immediately', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var first = 'pending';
  var second = 'pending';

  env.CpmsDialog.confirm('اول').then(function (v) { first = v; });
  await flush();
  env.CpmsDialog.confirm('دوم').then(function (v) { second = v; });
  await flush();

  if (first !== false) {
    throw new Error('the orphaned dialog must resolve false, got ' + JSON.stringify(first));
  }
  if (second !== 'pending') { throw new Error('the new dialog must still be pending'); }
  if (doc.overlays().length !== 1) {
    throw new Error('exactly one overlay must remain, got ' + doc.overlays().length);
  }
  if (doc.listenerCount('keydown') !== 1) {
    throw new Error('exactly one keydown handler must remain, got ' + doc.listenerCount('keydown'));
  }
});

check('D3: after a double open, Enter resolves ONLY the visible dialog', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var first = 'pending';
  var second = 'pending';

  env.CpmsDialog.confirm('اول').then(function (v) { first = v; });
  await flush();
  env.CpmsDialog.confirm('دوم').then(function (v) { second = v; });
  await flush();

  doc.keydown('Enter', doc.activeElement, false);
  await flush();

  if (first !== false) { throw new Error('the stale dialog must stay cancelled, got ' + first); }
  if (second !== true) { throw new Error('Enter must confirm the visible dialog, got ' + second); }
  if (doc.overlays().length !== 0) { throw new Error('the overlay must be gone'); }
});

check('D3: after a double open, Escape resolves ONLY the visible dialog', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var first = 'pending';
  var second = 'pending';

  env.CpmsDialog.confirm('اول').then(function (v) { first = v; });
  await flush();
  env.CpmsDialog.confirm('دوم').then(function (v) { second = v; });
  await flush();

  var ev = doc.keydown('Escape', doc.activeElement, false);
  await flush();

  if (first !== false) { throw new Error('the stale dialog must stay cancelled, got ' + first); }
  if (second !== false) { throw new Error('Escape must cancel the visible dialog, got ' + second); }
  if (!ev.defaultPrevented) { throw new Error('Escape must be consumed by the live dialog'); }
  if (doc.overlays().length !== 0) { throw new Error('the overlay must be gone'); }
});

check('D3: focus is restored to a LIVE node after a double open', async function () {
  var env = loadDialog();
  var doc = env.doc;
  var trigger = doc.register(doc.createEl('button'));
  trigger.focus();

  env.CpmsDialog.confirm('اول');
  await flush();
  env.CpmsDialog.confirm('دوم');
  await flush();

  // The second dialog must have captured the restored page trigger, not the
  // first dialog's (removed) confirm button.
  doc.keydown('Escape', doc.activeElement, false);
  await flush();

  if (doc.activeElement !== trigger) {
    throw new Error('focus must land on the live trigger, not a detached node');
  }
  if (doc.activeElement.parentNode === null) {
    throw new Error('the focused node must still be attached to the document');
  }
});

/* ------------------------------------------------------------------------- *
 * 3) cpms-admin.js — binding + D2 native fallback
 * ------------------------------------------------------------------------- */

function makeForm(doc, message) {
  var form = doc.createEl('form');
  form.setAttribute('data-cpms-confirm', message);
  form.nativeSubmits = 0;        // submissions the browser performed
  form.programmaticSubmits = 0;  // form.submit() calls (no submit event)
  form.inSubmit = false;

  form.requestSubmit = function () {
    if (form.inSubmit && doc.guardRecursion) {
      return; // "firing submission events" guard
    }
    var outer = form.inSubmit;
    form.inSubmit = true;
    var event = doc.fire(form, 'submit', {});
    form.inSubmit = outer;
    if (!event.defaultPrevented) {
      form.nativeSubmits++;
    }
  };
  form.submit = function () {
    form.programmaticSubmits++;
  };
  form.submissions = function () {
    return form.nativeSubmits + form.programmaticSubmits;
  };
  doc.register(form);
  return form;
}

function makeSubmitButton(doc, form, message) {
  var btn = doc.createEl('button');
  btn.type = 'submit';
  btn.setAttribute('data-cpms-confirm', message);
  btn.defaultAction = function () {
    doc.userSubmit(form);
  };
  doc.register(btn);
  return btn;
}

function makeAdminEnv(options, fixtures) {
  var opts = options || {};
  opts.fixtures = fixtures;
  return loadAdmin(opts);
}

// One document shared by the dialog and its consumer, like a real page.
function makeSharedEnv(fixtures) {
  var doc = createDocument();
  var dialogEnv = loadDialog(doc);
  var adminEnv = loadAdmin({ doc: doc, dialog: dialogEnv.CpmsDialog, fixtures: fixtures });
  adminEnv.doc = doc;
  return adminEnv;
}

check('data-cpms-confirm: cancel means NO submit; confirm submits once', async function () {
  var env = makeSharedEnv(function (doc) {
    return { form: makeForm(doc, 'حذف شود؟') };
  });
  var doc = env.doc;
  var form = env.fixtures.form;
  if (!form.listenerCount('submit')) { throw new Error('the confirm binding did not attach'); }

  var cancelled = doc.fire(form, 'submit', {});
  await flush();
  if (!cancelled.defaultPrevented) { throw new Error('the submit must be intercepted while a dialog is pending'); }
  if (doc.overlays().length !== 1) { throw new Error('the shared dialog must open'); }
  doc.keydown('Escape', doc.activeElement, false);
  await flush();
  if (form.submissions() !== 0) { throw new Error('cancel must not submit'); }

  var accepted = doc.fire(form, 'submit', {});
  await flush();
  if (!accepted.defaultPrevented) { throw new Error('the second submit must be intercepted too'); }
  doc.keydown('Enter', doc.activeElement, false);
  await flush();
  if (form.submissions() !== 1) { throw new Error('confirm must submit exactly once, got ' + form.submissions()); }
  if (form.hasAttribute('data-cpms-confirm')) {
    throw new Error('the guard attribute must be removed before the real submit');
  }
});

check('D3: a repeated activation of the SAME trigger while pending is ignored', async function () {
  var env = makeSharedEnv(function (doc) {
    return { form: makeForm(doc, 'حذف شود؟') };
  });
  var doc = env.doc;
  var form = env.fixtures.form;

  doc.fire(form, 'submit', {});
  await flush();
  doc.fire(form, 'submit', {});
  await flush();

  if (doc.overlays().length !== 1) {
    throw new Error('a second activation must not stack a second dialog, got ' + doc.overlays().length);
  }
  doc.keydown('Enter', doc.activeElement, false);
  await flush();
  if (form.submissions() !== 1) { throw new Error('exactly one submit expected, got ' + form.submissions()); }
});

check('D2: without CpmsDialog, window.confirm(false) blocks the submit', async function () {
  var env = makeAdminEnv({}, function (doc) {
    return { form: makeForm(doc, 'حذف شود؟') };
  });
  env.nativeConfirmResult = false;
  var doc = env.doc;
  var form = env.fixtures.form;

  var ev = doc.userSubmit(form);
  if (!ev.defaultPrevented) { throw new Error('a refused confirmation must cancel the event'); }
  if (form.submissions() !== 0) { throw new Error('no submit may happen without a confirmation'); }
  if (env.confirmCalls.length !== 1) { throw new Error('the native fallback must ask exactly once'); }
  if (doc.overlays().length !== 0) { throw new Error('no overlay should exist in the native path'); }
  if (!form.hasAttribute('data-cpms-confirm')) { throw new Error('a refused confirmation must keep the guard for the next attempt'); }
});

check('D2: without CpmsDialog, window.confirm(true) lets the submit through', async function () {
  var env = makeAdminEnv({}, function (doc) {
    return { form: makeForm(doc, 'حذف شود؟') };
  });
  env.nativeConfirmResult = true;
  var doc = env.doc;
  var form = env.fixtures.form;

  // userSubmit models the browser: the event is dispatched and, when it is not
  // cancelled, the native submission happens exactly once.
  var ev = doc.userSubmit(form);
  if (ev.defaultPrevented) { throw new Error('an accepted confirmation must not be cancelled'); }
  if (form.submissions() !== 1) { throw new Error('the submit must run once, got ' + form.submissions()); }
});

check('D2: a throwing window.confirm counts as CANCEL (fail-closed)', async function () {
  var env = makeAdminEnv({ confirmThrows: true }, function (doc) {
    return { form: makeForm(doc, 'حذف شود؟') };
  });
  var doc = env.doc;
  var form = env.fixtures.form;
  env.nativeConfirmResult = true; // would submit if the throw were ignored

  var ev = doc.userSubmit(form);
  if (!ev.defaultPrevented) { throw new Error('a blocked native dialog must cancel the event'); }
  if (form.submissions() !== 0) { throw new Error('no submit may happen'); }
});

check('schedule-delete: no action when the confirmation is refused', async function () {
  var env = makeAdminEnv({}, function (doc) {
    var btn = doc.createEl('button');
    btn.setAttribute('data-cpms-schedule-delete', '42');
    btn.setAttribute('data-cpms-confirm', 'حذف برنامه این روز؟');
    doc.register(btn);

    var hidden = doc.createEl('form');
    hidden.id = 'cpms-sched-del';
    hidden.schedule_id = doc.createEl('input');
    hidden.submit = function () {
      hidden.submits = (hidden.submits || 0) + 1;
    };
    doc.register(hidden);
    return { btn: btn, hidden: hidden };
  });
  env.nativeConfirmResult = false;
  var doc = env.doc;
  var btn = env.fixtures.btn;
  var hidden = env.fixtures.hidden;

  var ev = doc.fire(btn, 'click', {});
  if (!ev.defaultPrevented) { throw new Error('the click must be cancelled when the user refuses'); }
  if (hidden.submits) { throw new Error('the delete form must not be submitted'); }
  if (hidden.schedule_id.value === '42') { throw new Error('the hidden id must not be filled in'); }
});

check('schedule-delete: with the shared dialog, cancel still prevents the delete', async function () {
  var env = makeSharedEnv(function (doc) {
    var btn = doc.createEl('button');
    btn.setAttribute('data-cpms-schedule-delete', '42');
    btn.setAttribute('data-cpms-confirm', 'حذف برنامه این روز؟');
    doc.register(btn);

    var hidden = doc.createEl('form');
    hidden.id = 'cpms-sched-del';
    hidden.schedule_id = doc.createEl('input');
    hidden.submit = function () {
      hidden.submits = (hidden.submits || 0) + 1;
    };
    doc.register(hidden);
    return { btn: btn, hidden: hidden };
  });
  var btn = env.fixtures.btn;
  var hidden = env.fixtures.hidden;

  var ev = doc_fire_click(env.doc, btn);
  await flush();
  if (!ev.defaultPrevented) { throw new Error('the click must be intercepted while the dialog is pending'); }
  env.doc.keydown('Escape', env.doc.activeElement, false);
  await flush();
  if (hidden.submits) { throw new Error('Escape must cancel the delete'); }

  var again = doc_fire_click(env.doc, btn);
  await flush();
  if (!again.defaultPrevented) { throw new Error('the second click must be intercepted too'); }
  env.doc.keydown('Enter', env.doc.activeElement, false);
  await flush();
  if (hidden.submits !== 1) { throw new Error('Enter must perform the delete once, got ' + hidden.submits); }
  if (hidden.schedule_id.value !== '42') { throw new Error('the hidden id must be filled in'); }
});

function doc_fire_click(doc, el) {
  return doc.fire(el, 'click', {});
}

/* ------------------------------------------------------------------------- *
 * 4) T1 — the native-confirm fallback must submit EXACTLY once
 *
 * The browser's own default action (form submission, submit-button activation)
 * is counted, and so is any programmatic requestSubmit()/submit()/click() the
 * handler performs. Each case is asserted under BOTH submission models:
 *   - guardRecursion=false: requestSubmit() during dispatch fires again (the
 *     behaviour MDN documents for requestSubmit: it fires the submit event);
 *   - guardRecursion=true: the HTML "firing submission events" guard drops it.
 * A correct binding must produce one submission either way.
 * ------------------------------------------------------------------------- */

function withModels(fn) {
  [false, true].forEach(function (guard) {
    fn(guard);
  });
}

check('T1: native confirm(true) on a <form> submits exactly once (both models)', async function () {
  var seen = [];
  withModels(function (guard) {
    var env = makeAdminEnv({}, function (doc) {
      doc.guardRecursion = guard;
      return { form: makeForm(doc, 'حذف شود؟') };
    });
    env.nativeConfirmResult = true;
    var form = env.fixtures.form;
    env.doc.userSubmit(form);
    seen.push({ guard: guard, count: form.submissions() });
  });
  seen.forEach(function (r) {
    if (r.count !== 1) {
      throw new Error('guardRecursion=' + r.guard + ': expected exactly 1 submit, got ' + r.count);
    }
  });
});

check('T1: native confirm(false) on a <form> submits zero times (both models)', async function () {
  withModels(function (guard) {
    var env = makeAdminEnv({}, function (doc) {
      doc.guardRecursion = guard;
      return { form: makeForm(doc, 'حذف شود؟') };
    });
    env.nativeConfirmResult = false;
    var form = env.fixtures.form;
    var ev = env.doc.userSubmit(form);
    if (!ev.defaultPrevented) {
      throw new Error('guardRecursion=' + guard + ': the submit must be cancelled');
    }
    if (form.submissions() !== 0) {
      throw new Error('guardRecursion=' + guard + ': expected 0 submits, got ' + form.submissions());
    }
  });
});

check('T1: a non-true answer is never an unconfirmed submit (both models)', async function () {
  withModels(function (guard) {
    var env = makeAdminEnv({}, function (doc) {
      doc.guardRecursion = guard;
      return { form: makeForm(doc, 'حذف شود؟') };
    });
    // A blocked/silent dialog can return undefined; only `true` may proceed.
    env.nativeConfirmResult = undefined;
    var form = env.fixtures.form;
    env.doc.userSubmit(form);
    if (form.submissions() !== 0) {
      throw new Error('guardRecursion=' + guard + ': undefined must count as CANCEL');
    }
  });
});

check('T1: native confirm(true) on a submit button submits its form exactly once (both models)', async function () {
  withModels(function (guard) {
    var env = makeAdminEnv({}, function (doc) {
      doc.guardRecursion = guard;
      var form = makeForm(doc, 'حذف شود؟');
      form.removeAttribute('data-cpms-confirm'); // the BUTTON carries the confirm
      return { form: form, btn: makeSubmitButton(doc, form, 'بایگانی شود؟') };
    });
    env.nativeConfirmResult = true;
    var form = env.fixtures.form;
    var btn = env.fixtures.btn;
    btn.click();
    if (form.submissions() !== 1) {
      throw new Error('guardRecursion=' + guard + ': expected exactly 1 submit, got ' + form.submissions());
    }
  });
});

check('T1: schedule-delete submits its hidden form exactly once (both models)', async function () {
  withModels(function (guard) {
    var env = makeAdminEnv({}, function (doc) {
      doc.guardRecursion = guard;
      var btn = doc.createEl('button');
      btn.type = 'button';
      btn.setAttribute('data-cpms-schedule-delete', '42');
      btn.setAttribute('data-cpms-confirm', 'حذف برنامه این روز؟');
      doc.register(btn);
      var hidden = makeForm(doc, 'unused');
      hidden.removeAttribute('data-cpms-confirm');
      hidden.id = 'cpms-sched-del';
      hidden.schedule_id = doc.createEl('input');
      return { btn: btn, hidden: hidden };
    });
    env.nativeConfirmResult = true;
    var btn = env.fixtures.btn;
    var hidden = env.fixtures.hidden;
    btn.click();
    if (hidden.submissions() !== 1) {
      throw new Error('guardRecursion=' + guard + ': expected exactly 1 submit, got ' + hidden.submissions());
    }
    if (hidden.schedule_id.value !== '42') {
      throw new Error('guardRecursion=' + guard + ': the id must be filled in');
    }
  });
});

check('T1: with the shared dialog, a confirmed form submits exactly once (both models)', async function () {
  for (var guard of [false, true]) {
    var env = makeSharedEnv(function (doc) {
      doc.guardRecursion = guard;
      return { form: makeForm(doc, 'حذف شود؟') };
    });
    var form = env.fixtures.form;
    env.doc.userSubmit(form);
    await flush();
    env.doc.keydown('Enter', env.doc.activeElement, false);
    await flush();
    if (form.submissions() !== 1) {
      throw new Error('guardRecursion=' + guard + ': expected exactly 1 submit, got ' + form.submissions());
    }
  }
});

run();

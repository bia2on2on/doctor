'use strict';
/**
 * NEW — the three edit-flow prompts in templates/staff-pricing.php must be
 * REQUIRED, so an empty answer can never be confirmed into a PUT.
 *
 * Runs the REAL shipped `editCharge()` and the REAL shared dialog
 * `assets/js/cpms-dialog.js` in a plain Node `vm` with a hand-written DOM stub
 * (no jsdom, no npm dependency, no network, no browser). Existing tests are
 * not modified; this is an additional spec.
 *
 * Contract proven here:
 *   1. Each of the three CpmsDialog.prompt options carries required:true and a
 *      non-empty Persian requiredMessage.
 *   2. The REAL dialog refuses to settle a required prompt with an empty input:
 *      it shows the Persian error and keeps the dialog open; a non-empty value
 *      is required to resolve. A prompt WITHOUT required still accepts empty
 *      (negative control proving `required` is the operative difference).
 *   3. Cancel resolves null and sends nothing (equal-or-stricter to the
 *      existing spec).
 *   4. End-to-end: driving editCharge() through the REAL dialog, an empty
 *      answer at every one of the three steps sends NO mutate; filling the
 *      value advances the chain and sends exactly one PUT.
 *
 * Run standalone: node clinic-practice-management/tests/js/staff-pricing-edit-required.spec.js
 * Shelled out (FAIL, not skip, when node is missing) from
 * ClinicCore\Tests\Unit\Frontend\StaffPricingEditRequiredTest.
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const root = path.join(__dirname, '../..');
const pricing = fs.readFileSync(path.join(root, 'templates/staff-pricing.php'), 'utf8');
const dialogSrc = fs.readFileSync(path.join(root, 'assets/js/cpms-dialog.js'), 'utf8');

/** Slice one indented top-level function out of the shipped template source. */
function fn(src, name) {
  const start = src.indexOf('    function ' + name + '(');
  assert(start >= 0, name);
  const end = src.indexOf('\n    }', start);
  assert(end >= 0, name + ' end');
  return src.slice(start, end + 6) + '\n';
}

function makeDom() {
  function node(tag) {
    const n = {
      tagName: String(tag).toUpperCase(),
      className: '', id: '', textContent: '', hidden: false,
      value: '', rows: 0, type: '', parentNode: null, children: [], _h: {},
      setAttribute() {},
      appendChild(c) { c.parentNode = n; n.children.push(c); return c; },
      removeChild(c) {
        c.parentNode = null;
        n.children = n.children.filter(x => x !== c);
      },
      addEventListener(type, handler) {
        (n._h[type] = n._h[type] || []).push(handler);
      },
      focus() { doc.activeElement = n; },
      select() {},
      click() { (n._h.click || []).forEach(h => h({})); }
    };
    return n;
  }
  const doc = {
    activeElement: { focus() {} },
    head: node('head'), body: node('body'), documentElement: node('html'),
    getElementById() { return {}; },
    createElement: node,
    addEventListener() {}, removeEventListener() {}
  };
  return doc;
}

function findByClass(root, className) {
  if (root.className && root.className.split(' ').indexOf(className) !== -1) {
    return root;
  }
  for (const child of root.children) {
    const hit = findByClass(child, className);
    if (hit) return hit;
  }
  return null;
}

function findByText(root, text) {
  if (root.textContent === text) return root;
  for (const child of root.children) {
    const hit = findByText(child, text);
    if (hit) return hit;
  }
  return null;
}

/** Flush all pending Node microtasks before asserting on the promise chain. */
const tick = () => new Promise(resolve => setImmediate(resolve));

const CHARGE = {
  id: 8, description: 'خدمت', unit_amount: '1200',
  quantity: '2', note: 'یادداشت'
};
const PERSIAN = /[\u0600-\u06FF]/;

async function sourceAndStubOptions() {
  const body = fn(pricing, 'editCharge');
  assert(!/\bwindow\.prompt\s*\(/.test(pricing));
  assert.strictEqual((body.match(/required:\s*true/g) || []).length, 3);
  assert.strictEqual((body.match(/requiredMessage:/g) || []).length, 3);

  const calls = [], errors = [], prompts = [];
  let values = [];
  const ctx = {
    window: { CpmsDialog: { prompt: (message, options) => {
      prompts.push([message, options]);
      return Promise.resolve(values.shift());
    } } },
    state: { visitId: 42 },
    setStatus: (...a) => errors.push(a),
    mutate: (...a) => calls.push(a),
    Promise, Number, String
  };
  vm.createContext(ctx);
  vm.runInContext(body, ctx);

  values = ['الف', '2500', '3'];
  await ctx.editCharge(CHARGE);
  assert.strictEqual(prompts.length, 3, 'three edit prompts');
  assert.deepStrictEqual(prompts.map(p => p[0]),
    ['شرح خدمت', 'مبلغ واحد (ریال)', 'تعداد']);
  for (const [message, options] of prompts) {
    assert.strictEqual(options.required, true, message + ': required must be true');
    assert.strictEqual(typeof options.requiredMessage, 'string', message);
    assert(options.requiredMessage.length > 0, message + ': a message is required');
    assert(PERSIAN.test(options.requiredMessage), message + ': Persian requiredMessage');
  }
  // JSON.stringify matches the established spec: VM-realm object literals
  // have a different prototype, so deepStrictEqual would fail spuriously.
  assert.strictEqual(JSON.stringify(calls.shift()), JSON.stringify(
    ['/staff/portal/pricing/visits/42/charges/8', 'PUT',
      { description: 'الف', amount: 2500, quantity: 3, note: 'یادداشت' }]));

  for (const sequence of [[null], ['الف', null], ['الف', '2500', null]]) {
    values = sequence.slice();
    prompts.length = 0;
    await ctx.editCharge(CHARGE);
    assert.strictEqual(calls.length, 0, 'cancel must send nothing');
    assert.strictEqual(prompts.length, sequence.length);
  }
  console.log('ok - three edit prompts are required; cancel still sends nothing');
}

async function realDialogRequiredGate() {
  const doc = makeDom();
  const ctx = { window: {}, document: doc, console, Promise, String };
  vm.createContext(ctx);
  vm.runInContext(dialogSrc, ctx);
  assert(ctx.window.CpmsDialog, 'shared dialog must register');

  let settled = false, resolved = 'unset';
  const p = ctx.window.CpmsDialog.prompt('شرح خدمت', {
    value: '', required: true, requiredMessage: 'شرح خدمت الزامی است.'
  });
  p.then(v => { settled = true; resolved = v; });
  const overlay = doc.body.children[0];
  assert(overlay, 'overlay must be attached');
  const input = findByClass(overlay, 'cpms-dialog__input');
  const confirm = findByClass(overlay, 'cpms-modal-confirm');
  const error = findByClass(overlay, 'cpms-dialog__error');
  assert(input && confirm && error, 'prompt dialog parts');

  input.value = '';
  confirm.click();
  await tick();
  assert.strictEqual(settled, false, 'empty required value must NOT be confirmed');
  assert.strictEqual(error.hidden, false, 'required error must be shown');
  assert.strictEqual(error.textContent, 'شرح خدمت الزامی است.');
  assert.strictEqual(doc.body.children.length, 1, 'dialog stays open on empty');

  input.value = 'خدمت';
  confirm.click();
  await p;
  assert.strictEqual(settled, true);
  assert.strictEqual(resolved, 'خدمت');

  // Negative control: without required, an empty value is still accepted.
  let free = 'unset';
  const p2 = ctx.window.CpmsDialog.prompt('شرح خدمت', { value: '' });
  p2.then(v => { free = v; });
  findByClass(doc.body.children[0], 'cpms-modal-confirm').click();
  await p2;
  assert.strictEqual(free, '', 'a prompt without required still accepts empty');

  // Cancel resolves null (nothing sent) even on a required prompt.
  let cancelled = 'unset';
  const p3 = ctx.window.CpmsDialog.prompt('تعداد', {
    value: '2', required: true, requiredMessage: 'تعداد الزامی است.'
  });
  p3.then(v => { cancelled = v; });
  const cancelBtn = findByText(doc.body.children[0], 'انصراف');
  assert(cancelBtn, 'cancel button must exist');
  cancelBtn.click();
  await p3;
  assert.strictEqual(cancelled, null, 'cancel resolves null');
  console.log('ok - real dialog blocks empty required input, shows Persian error');
}

async function endToEndEditCharge() {
  const doc = makeDom();
  const calls = [];
  const ctx = {
    window: {}, document: doc, console, Promise, String, Number,
    state: { visitId: 42 },
    setStatus() {},
    mutate: (...a) => calls.push(a)
  };
  vm.createContext(ctx);
  vm.runInContext(dialogSrc, ctx);
  vm.runInContext(fn(pricing, 'editCharge'), ctx);

  const job = ctx.editCharge(CHARGE);
  for (const answer of ['خدمت', '2500', '3']) {
    const overlay = doc.body.children[0];
    assert(overlay, 'a dialog must be open for ' + answer);
    const input = findByClass(overlay, 'cpms-dialog__input');
    const confirm = findByClass(overlay, 'cpms-modal-confirm');
    input.value = '';
    confirm.click();
    await tick();
    assert.strictEqual(calls.length, 0, 'empty answer must not send anything');
    assert.strictEqual(doc.body.children.length, 1, 'empty answer keeps dialog open');
    input.value = answer;
    confirm.click();
    await tick();
  }
  await job;
  assert.strictEqual(calls.length, 1, 'exactly one PUT after three filled answers');
  assert.strictEqual(JSON.stringify(calls[0]), JSON.stringify(
    ['/staff/portal/pricing/visits/42/charges/8', 'PUT',
      { description: 'خدمت', amount: 2500, quantity: 3, note: 'یادداشت' }]));
  console.log('ok - empty answer cannot be confirmed in the real edit flow');
}

async function main() {
  await sourceAndStubOptions();
  await realDialogRequiredGate();
  await endToEndEditCharge();
  console.log('staff pricing edit prompts required: passed');
}

main().catch(e => { console.error(e); process.exitCode = 1; });

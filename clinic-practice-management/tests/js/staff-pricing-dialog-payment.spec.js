'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');
const root = path.join(__dirname, '../..');
const pricing = fs.readFileSync(path.join(root, 'templates/staff-pricing.php'), 'utf8');
const finance = fs.readFileSync(path.join(root, 'templates/staff-finance-board.php'), 'utf8');
function fn(src, name) {
  const start = src.indexOf('    function ' + name + '(');
  assert(start >= 0, name);
  const end = src.indexOf('\n    }', start);
  assert(end >= 0, name + ' end');
  return src.slice(start, end + 6) + '\n';
}
async function main() {
  assert(!/\bwindow\.(confirm|prompt|alert)\s*\(/.test(pricing));
  assert(!/(?<![\w$.])(confirm|prompt|alert)\s*\(/.test(pricing));
  assert(pricing.includes('removeCharge(charge);'));
  assert(pricing.includes('editCharge(charge);'));
  const calls = [], errors = [], prompts = [];
  const charge = { id: 8, description: 'خدمت', unit_amount: '1200', quantity: '2', note: 'یادداشت' };
  const ctx = { window: {}, state: { visitId: 42 }, setStatus: (...a) => errors.push(a),
    mutate: (...a) => calls.push(a), Promise, Number, String };
  vm.createContext(ctx);
  vm.runInContext(fn(pricing, 'removeCharge') + fn(pricing, 'editCharge'), ctx);
  ctx.removeCharge(charge); ctx.editCharge(charge);
  assert.strictEqual(calls.length, 0); assert.strictEqual(errors.length, 2);
  assert(errors.every(x => x[1] === 'error' && x[0]));
  let confirmResult = false;
  ctx.window.CpmsDialog = { confirm: (message, options) => {
    assert.strictEqual(message, 'این خدمت حذف شود؟'); assert(options.confirmLabel);
    return Promise.resolve(confirmResult);
  }, prompt: (message, options) => { prompts.push([message, options.value]); return Promise.resolve(values.shift()); } };
  await ctx.removeCharge(charge); assert.strictEqual(calls.length, 0);
  confirmResult = true; await ctx.removeCharge(charge);
  assert.deepStrictEqual(calls.shift(), ['/staff/portal/pricing/visits/42/charges/8', 'DELETE']);
  let values;
  for (const sequence of [[null], ['الف', null], ['الف', '2500', null]]) {
    values = sequence.slice(); prompts.length = 0;
    await ctx.editCharge(charge); assert.strictEqual(calls.length, 0);
    assert.strictEqual(prompts.length, sequence.length);
  }
  values = ['الف', '2500', '3']; prompts.length = 0;
  await ctx.editCharge(charge);
  assert.deepStrictEqual(prompts, [['شرح خدمت', 'خدمت'], ['مبلغ واحد (ریال)', '1200'], ['تعداد', '2']]);
  assert.strictEqual(JSON.stringify(calls.shift()), JSON.stringify(['/staff/portal/pricing/visits/42/charges/8', 'PUT',
    {description:'الف', amount:2500, quantity:3, note:'یادداشت'}]));
  const money = require(path.join(root, 'assets/js/cpms-money.js'));
  async function payment(helper) {
    let posted, status;
    const p = { money: helper, state:{ selectedInvoice: 12, paymentKey: '', mutating: false },
      payAmountInput:{value:'1200000'}, payMethodSelect:{value:'cash'}, payRefInput:{value:' ref '},
      paySubmitNode:{}, paymentKey:()=> 'key', enqueue:f=>f(),
      request:(...args)=>{ posted=args; return Promise.resolve({ok:true}); },
      payload:()=>({}), closePayForm:()=>{}, setStatus:(message)=>{status=message;}, refreshAll:()=>{},
      Number, String, Math, isFinite };
    vm.createContext(p); vm.runInContext(fn(finance, 'rial') + fn(finance, 'submitPayment'), p);
    await p.submitPayment();
    assert.strictEqual(JSON.stringify(posted), JSON.stringify(['/staff/portal/finance/invoices/12/payments', 'POST',
      {amount:1200000, method:'cash', transaction_ref:'ref'}, {'Idempotency-Key':'key'}]));
    return status;
  }
  assert.strictEqual(await payment(money), 'پرداخت ۱٬۲۰۰٬۰۰۰ ریال ثبت شد.');
  assert.strictEqual(await payment(null), 'پرداخت 1200000 ریال ثبت شد.');
  assert.strictEqual(await payment({}), 'پرداخت 1200000 ریال ثبت شد.');
  console.log('staff pricing dialogs and payment display: passed');
}
main().catch(e => { console.error(e); process.exitCode = 1; });

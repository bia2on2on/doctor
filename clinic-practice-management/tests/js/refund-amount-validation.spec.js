'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const source = read('src/Admin/SecretaryFinancePage.php');
const assert = (v, m) => { if (!v) throw new Error(m); };
function balanced(src, start) {
  const open = src.indexOf('{', start); assert(open >= 0, 'missing body');
  let depth = 0, quote = '', esc = false, line = false, block = false;
  for (let i = open; i < src.length; i++) {
    const c = src[i], n = src[i + 1];
    if (line) { if (c === '\n') line = false; continue; }
    if (block) { if (c === '*' && n === '/') { block = false; i++; } continue; }
    if (quote) { if (esc) esc = false; else if (c === '\\') esc = true; else if (c === quote) quote = ''; continue; }
    if (c === '/' && n === '/') { line = true; i++; continue; }
    if (c === '/' && n === '*') { block = true; i++; continue; }
    if (c === '"' || c === "'" || c === '`') { quote = c; continue; }
    if (c === '{') depth++;
    if (c === '}' && --depth === 0) return src.slice(start, i + 1);
  }
  throw new Error('unbalanced body');
}
function stripComments(s) {
 let out='', quote='', escaped=false;
 for (let i=0;i<s.length;i++) {
  const c=s[i], n=s[i+1];
  if (quote) { out+=c; if (escaped) escaped=false; else if (c==='\\') escaped=true; else if (c===quote) quote=''; continue; }
  if (c==='"' || c==="'" || c==='`') { quote=c; out+=c; continue; }
  if (c==='/' && n==='*') { i+=2; while(i<s.length && !(s[i]==='*' && s[i+1]==='/')) i++; i++; continue; }
  if (c==='/' && n==='/') { while(i<s.length && s[i]!=='\n') i++; out+='\n'; continue; }
  out+=c;
 }
 return out;
}
function fn(name) { const i = source.indexOf('function ' + name + '('); assert(i >= 0, name + ' missing'); return balanced(source, i); }
const helperStart = source.indexOf('var MAX_MONEY =');
const helperEnd = source.indexOf('\n    function esc(', helperStart);
assert(helperStart >= 0 && helperEnd > helperStart, 'validation helper region missing');
const sandbox = { Number, String, parseInt };
vm.createContext(sandbox);
vm.runInContext(source.slice(helperStart, helperEnd), sandbox);
function money(s, opts) { return sandbox.validateMoneyAmount(s, opts || {required:true,min:1}); }
function qty(s) { return sandbox.validateQuantity(s); }
assert(!sandbox.validateRefundAmount('').ok, 'blank refund must fail');
assert(!sandbox.validateRefundAmount('   ').ok, 'whitespace-only refund must fail');
assert(sandbox.validateRefundAmount('۳۰۰۰۰۰').value === 300000, 'refund Persian digits normalize');
assert(!sandbox.validateRefundAmount('\uFEFF300000').ok, 'refund BOM input must fail');
for (const s of ['1','300000','9999999999','007','300.0','300.00','۳۰۰۰۰۰','١٢٣',' 300 \t']) assert(money(s).ok, 'money should accept ' + JSON.stringify(s));
for (const s of ['', '300,000','300.5','1e2','-1','+1','10000000000','３００','\uFEFF300','300\uFEFF','\uFEFF','\u00a0300','300\u2009','3\u200b00','3\u200c00','3\u200d00','3\u061c00','3\u00ad00','3\u180e00','3\u202e00']) assert(!money(s).ok, 'money should reject ' + JSON.stringify(s));
for (const s of ['0.01','1','1.2','1.20','100',' ۱٫']) { if (s === ' ۱٫') continue; assert(qty(s).ok, 'quantity should accept ' + JSON.stringify(s)); }
for (const s of ['0','100.01','1e2','1.234','1.200','100.000','1.005','1000','\uFEFF1','1\u200b']) assert(!qty(s).ok, 'quantity should reject ' + JSON.stringify(s));
assert(money('0', {required:true,min:0,max:9999999999}).ok, 'zero allowed for zero-min field');
assert(!money(' ', {required:true,min:1}).ok, 'blank rejected');
assert(money(' ', {required:false,min:0}).blank, 'optional blank accepted');

// STRUCTURAL: each handler's brace-matched body has executable guards after comments are removed.
function blockAt(marker) { const i = source.indexOf(marker); assert(i >= 0, 'handler marker missing: '+marker); return stripComments(balanced(source, i)); }
// STRUCTURAL negative controls: comment-only decoys disappear and a check in a different body is not credited.
const commentDecoy = stripComments('{ /* hasBadInput(el); */ // validateMoneyAmount(value);\n return true; }');
assert(!commentDecoy.includes('hasBadInput(') && !commentDecoy.includes('validateMoneyAmount('), 'comment-only decoy control must be RED');
const movedCheckSource = 'function first(){ return true; } function second(){ if (hasBadInput(el)) {} }';
const firstBody = stripComments(balanced(movedCheckSource, movedCheckSource.indexOf('function first')));
assert(!firstBody.includes('hasBadInput('), 'cross-handler moved-check control must be RED');
const lexicalSample = [
 'function lexical(){',
 ' const a = "//";',
 ' const b = "/*";',
 ' const c = "escaped \\" quote";',
 ' const re = /a\\/b/;',
 ' // actual line comment',
 ' return a+b+c+re;',
'}'
].join('\n');
const lexicalBlock=balanced(lexicalSample,lexicalSample.indexOf('function lexical'));
const lexicalCode=stripComments(lexicalBlock);
assert(lexicalCode.includes('const a = "//"') && lexicalCode.includes('const b = "/*"'),'string comment-like text must be retained');
assert(lexicalCode.includes('escaped '+String.fromCharCode(92)+'\" quote'),'escaped quote must be retained');
assert(lexicalCode.includes('/a'+String.fromCharCode(92)+'/b/'),'regex-like text must be retained');
assert(lexicalCode.includes('return a+b+c+re;') && !lexicalCode.includes('actual line comment'),'line comment must stop at real newline');
const handlers = [
 ['service price','svcSave.addEventListener','hasBadInput(','validateMoneyAmount('],
 ['item quantity','function submitIssue','hasBadInput(qtyEl)','validateQuantity('],
 ['item discount','function submitIssue','hasBadInput(discEl)','validateMoneyAmount('],
 ['item unit price','function submitIssue','hasBadInput(priceEl)','validateMoneyAmount('],
 ['invoice discount','function submitIssue','hasBadInput(invDiscEl)','validateMoneyAmount('],
 ['invoice tax','function submitIssue','hasBadInput(invTaxEl)','validateMoneyAmount('],
 ['payment amount','payBtn.addEventListener','hasBadInput(payAmountEl)','validateMoneyAmount('],
 ['adjustment amount','adjBtn.addEventListener','hasBadInput(adjAmountEl)','validateMoneyAmount(']
];
const submit = balanced(source, source.indexOf('function submitIssue'));
const bind = balanced(source, source.indexOf('function bindInvoiceActions'));
for (const [name, marker, bad, validator] of handlers) {
 let body;
 if (marker === 'function submitIssue') {
  const pos = submit.indexOf(bad);
  assert(pos >= 0, name+' guard not found');
  const before = submit.lastIndexOf('\n', pos);
  const nextGuard = submit.indexOf('if (hasBadInput(', pos + bad.length);
  const nextValidation = submit.indexOf(validator, pos + bad.length);
  const end = nextGuard >= 0 ? nextGuard : (nextValidation >= 0 ? submit.indexOf(';', nextValidation) + 1 : -1);
  body = stripComments(submit.slice(before, end > 0 ? end : submit.length));
 } else {
  const sub = marker.includes('Btn') ? bind : source;
  body = blockAt.call(null, marker);
  if (marker.includes('Btn')) { const i=sub.indexOf(marker); body=stripComments(balanced(sub,i)); }
 }
 assert(body.includes(bad), name+' missing badInput guard');
 assert(body.includes(validator), name+' missing validator');
}
const dialog = read('assets/js/cpms-dialog.js');
assert(dialog.includes('settings.raw === true'), 'raw mode support missing');
assert(source.includes("askPrompt(amtMsg, { inputMode: 'numeric', raw: true })"), 'refund raw option missing');
const refund = bind.slice(bind.indexOf("button[data-pact]"));
assert(refund.includes('validateRefundAmount(amt)'), 'refund validator missing');
assert(refund.includes('if (!refundRes.ok)'), 'refund failure guard missing');
assert(refund.includes('amount: refundRes.value'), 'normalized amount not sent');
// Caller compatibility: default path trims, raw path preserves; required check remains trim-aware.
assert(dialog.includes("settings.raw === true ? rawValue : rawValue.trim()"), 'default prompt trimming changed');
assert(dialog.includes("rawValue.trim() === ''"), 'prompt required behavior changed');
// BEHAVIORAL: execute shipped service-price, payment and adjustment click callbacks.
function eventFunction(marker) {
 const at=source.indexOf(marker); assert(at>=0,'event marker missing '+marker);
 const start=source.indexOf('function () {',at); assert(start>=0,'callback missing '+marker);
 return balanced(source,start);
}
async function runHandler(code, env) {
 const ctx=Object.assign({Number,parseInt,Promise,MAX_MONEY:9999999999,MAX_INPUT_LEN:40,INVISIBLE_RE:/[\\uFEFF\\u200B]/,
  hasBadInput:e=>!!(e&&e.validity&&e.validity.badInput),trimAsciiWhitespace:s=>s.replace(/^[ \\t\\r\\n]+|[ \\t\\r\\n]+$/g,''),normalizeDigits:s=>s.replace(/[\\u06F0-\\u06F9]/g,c=>String.fromCharCode(c.charCodeAt(0)-0x06f0+48)).replace(/[\\u0660-\\u0669]/g,c=>String.fromCharCode(c.charCodeAt(0)-0x0660+48)),
  notice:(m,t)=>{ctx.lastNotice={message:m,type:t};},api:(m,u,b)=>{ctx.request={method:m,url:u,body:b};return Promise.resolve({__status:200});},refreshAll(){},openInvoice(){},errMessage:r=>r.message||'error',loadServices:()=>Promise.resolve(),resetSvcForm(){},
  document:{getElementById:id=>ctx.elements[id]||{value:''}},
  elements:{'cpms-fin-svc-price':{value:'300000',validity:{badInput:false}},'cpms-fin-svc-id':{value:''},'cpms-fin-svc-code':{value:'S1'},'cpms-fin-svc-name':{value:'Test'},'cpms-fin-pay-amount':{value:'۳۰۰۰۰۰',validity:{badInput:false}},'cpms-fin-adj-amount':{value:'۳۰۰۰۰۰',validity:{badInput:false}},'cpms-fin-adj-reason':{value:'test reason'},'cpms-fin-adj-type':{value:'debit'},'cpms-fin-pay-method':{value:'cash'},'cpms-fin-pay-ref':{value:''}}
 });
 if (env && env.elements) ctx.elements=Object.assign(ctx.elements,env.elements);
 vm.createContext(ctx); const helper=source.slice(helperStart,helperEnd); vm.runInContext(helper+'; var inv={id:77}; var window={crypto:{randomUUID:function(){return "test-key";}}}; var crypto=window.crypto; var handler='+code+'; handler();',ctx); await new Promise(r=>setImmediate(r)); return ctx;
}
(async function () { for (const [label,marker,inputId] of [['service price',"svcSave.addEventListener('click'",'cpms-fin-svc-price'],['payment amount',"payBtn.addEventListener('click'",'cpms-fin-pay-amount'],['adjustment amount',"adjBtn.addEventListener('click'",'cpms-fin-adj-amount']]) {
 const code=eventFunction(marker);
 for (const [value,bad,shouldRequest] of [['300,000',false,false],['300000',true,false],['۳۰۰۰۰۰',false,true]]) {
  const ctx=await runHandler(code,{elements:{[inputId]:{value,validity:{badInput:bad}}}});
  assert(Boolean(ctx.request)===shouldRequest,label+' request mismatch '+JSON.stringify(value));
  if (!shouldRequest) assert(ctx.lastNotice&&/[\u0600-\u06FF]/.test(ctx.lastNotice.message),label+' must show Persian error ('+JSON.stringify(ctx.lastNotice)+')');
  if (shouldRequest) assert(ctx.request.body.price===300000||ctx.request.body.amount===300000,label+' must normalize digits in request');
 }
}
// BEHAVIORAL: run the shipped submitIssue callback with stubbed row/DOM/request.
const submitIssueFn=fn('submitIssue');
function extractIfBlock(body, marker) { const at=body.indexOf(marker); assert(at>=0,'missing '+marker); return stripComments(balanced(body,at)); }
const issueBranches=[['quantity','hasBadInput(qtyEl)','if (!qtyRes.ok)'],['discount','hasBadInput(discEl)','if (!itemDiscRes.ok)'],['unit price','hasBadInput(priceEl)','if (!itemPriceRes.ok)'],['invoice discount','hasBadInput(invDiscEl)','if (!invDiscRes.ok)'],['invoice tax','hasBadInput(invTaxEl)','if (!invTaxRes.ok)']];
for(const [label,badGuard,invalidGuard] of issueBranches){
 for(const marker of ['if ('+badGuard+')',invalidGuard]) assert(extractIfBlock(submitIssueFn,marker).includes('return;'),label+' failure branch requires return');
}
async function runIssue(changes) {
 const values={qty:'1',disc:'0',price:'300000',invDisc:'0',invTax:'0'};
 const bad={qty:false,disc:false,price:false,invDisc:false,invTax:false};
 Object.assign(values,changes.values||{});Object.assign(bad,changes.bad||{});
 const els={qtyEl:{value:values.qty,validity:{badInput:bad.qty}},discEl:{value:values.disc,validity:{badInput:bad.disc}},priceEl:{value:values.price,validity:{badInput:bad.price}},invDiscEl:{value:values.invDisc,validity:{badInput:bad.invDisc}},invTaxEl:{value:values.invTax,validity:{badInput:bad.invTax}},submit:{disabled:false}};
 const row={querySelector(sel){return {'.cpms-fin-i-svc':null,'.cpms-fin-i-desc':{value:'Manual item'},'.cpms-fin-i-qty':els.qtyEl,'.cpms-fin-i-disc':els.discEl,'.cpms-fin-i-price':els.priceEl}[sel]||null;}};
 const ctx={Number,String,parseInt,parseFloat,Promise,MAX_MONEY:9999999999,MAX_QUANTITY_HUNDREDTHS:10000,MAX_INPUT_LEN:40,
  document:{querySelectorAll:()=>[row],getElementById:id=>id==='cpms-fin-inv-discount'?els.invDiscEl:id==='cpms-fin-inv-tax'?els.invTaxEl:id==='cpms-fin-issue-submit'?els.submit:{value:''}},
  state:{issueVisit:71},lastNotice:null,request:null,notice:(m,t)=>{ctx.lastNotice={message:m,type:t};},api:(m,u,b)=>{ctx.request={method:m,url:u,body:b};return Promise.resolve({__status:201,body:{data:{invoice_number:'INV-TEST',id:9}}});},closeDrawers(){},refreshAll(){},openInvoice(){},errMessage:r=>r.message||'error'};
 vm.createContext(ctx);vm.runInContext(source.slice(helperStart,helperEnd)+';'+submitIssueFn+'; submitIssue();',ctx);await new Promise(r=>setImmediate(r));return ctx;
}
const issueFields=[
 ['quantity','qty','۱.۲۰',c=>c.request.body.items[0].quantity===1.2],
 ['discount','disc','۳۰۰۰۰۰',c=>c.request.body.items[0].discount===300000],
 ['unit price','price','۳۰۰۰۰۰',c=>c.request.body.items[0].unit_price===300000],
 ['invoice discount','invDisc','۳۰۰۰۰۰',c=>c.request.body.discount===300000],
 ['invoice tax','invTax','۳۰۰۰۰۰',c=>c.request.body.tax===300000]
];
for(const [label,key,valid,bodyCheck] of issueFields){
 const invalids=[{value:'300000',bad:true},...['300,000','1e2','\uFEFF300000'].map(value=>({value,bad:false}))];
 if(key==='qty') invalids.push({value:'0',bad:false});
 for(const item of invalids){const ctx=await runIssue({values:{[key]:item.value},bad:{[key]:item.bad}});assert(!ctx.request,label+' invalid '+JSON.stringify(item.value)+' sent request');assert(ctx.lastNotice&&/[\u0600-\u06FF]/.test(ctx.lastNotice.message),label+' invalid must show Persian error');}
 const ctx=await runIssue({values:{[key]:valid}});assert(ctx.request&&bodyCheck(ctx),label+' valid Persian digits not normalized in payload');
}

const pactAt=source.indexOf("body.querySelectorAll('button[data-pact]')");
const pactCallback=eventFunction("btn.addEventListener('click'");
async function runRefundClick(amount) {
 const ctx=Object.assign({Number,parseInt,Promise,MAX_MONEY:9999999999,MAX_INPUT_LEN:40,INVISIBLE_RE:/[\\uFEFF\\u200B]/,
  hasBadInput:e=>!!(e&&e.validity&&e.validity.badInput),trimAsciiWhitespace:s=>s.replace(/^[ \\t\\r\\n]+|[ \\t\\r\\n]+$/g,''),normalizeDigits:s=>s.replace(/[\\u06F0-\\u06F9]/g,c=>String.fromCharCode(c.charCodeAt(0)-0x06f0+48)).replace(/[\\u0660-\\u0669]/g,c=>String.fromCharCode(c.charCodeAt(0)-0x0660+48)),
  btn:{getAttribute:n=>n==='data-id'?'123':'refund'},askPrompt:(m,o)=>{ctx.promptOptions.push(o||{});return Promise.resolve(ctx.promptOptions.length===1?'test reason':amount);},promptOptions:[],api:(m,u,b)=>{ctx.request={method:m,url:u,body:b};return Promise.resolve({__status:200});},notice:(m,t)=>{ctx.lastNotice={message:m,type:t};},refreshAll(){},openInvoice(){},errMessage:r=>r.message||'error',inv:{id:44}
 },{});
 vm.createContext(ctx); const helper=source.slice(helperStart,helperEnd);vm.runInContext(helper+'; var handler='+pactCallback+'; handler();',ctx);await new Promise(r=>setImmediate(r));return ctx;
}
for(const [amount,expected] of [['',false],['\uFEFF300000',false],['۳۰۰۰۰۰',true]]){
 const ctx=await runRefundClick(amount);assert(Boolean(ctx.request)===expected,'refund click request mismatch '+JSON.stringify(amount));
 if(!expected)assert(ctx.lastNotice&&ctx.lastNotice.type==='error','invalid refund click must show error');
 else assert(ctx.request.body.amount===300000,'refund click must send normalized exact amount');
 assert(ctx.promptOptions.length===2,'refund click must request reason and amount');
 if(ctx.promptOptions.length===2)assert(ctx.promptOptions[1].raw===true,'refund amount prompt must preserve raw value');
}
// BEHAVIORAL: execute shipped CpmsDialog.prompt against a small DOM stub.
function fakeNode(tag){return {tagName:tag.toUpperCase(),children:[],listeners:{},attributes:{},style:{},className:'',value:'',hidden:false,parentNode:null,setAttribute(k,v){this.attributes[k]=v;},getAttribute(k){return this.attributes[k];},appendChild(n){n.parentNode=this;this.children.push(n);},removeChild(n){this.children=this.children.filter(x=>x!==n);n.parentNode=null;},addEventListener(k,f){(this.listeners[k]||(this.listeners[k]=[])).push(f);},removeEventListener(){},focus(){fakeDoc.activeElement=this;},select(){}};}
const fakeDoc={head:fakeNode('head'),body:fakeNode('body'),documentElement:fakeNode('html'),activeElement:{focus(){}},listeners:{},createElement:fakeNode,getElementById(){return null;},addEventListener(k,f){(this.listeners[k]||(this.listeners[k]=[])).push(f);},removeEventListener(){}};
const dialogContext={window:{},document:fakeDoc,String,Promise,console};vm.createContext(dialogContext);vm.runInContext(read('assets/js/cpms-dialog.js'),dialogContext);
function findClass(node,name){if((' '+node.className+' ').includes(' '+name+' '))return node;for(const child of node.children||[]){const found=findClass(child,name);if(found)return found;}return null;}
async function realPrompt(value,options){const promise=dialogContext.window.CpmsDialog.prompt('Amount',options||{});const input=findClass(fakeDoc.body,'cpms-dialog__input'),confirm=findClass(fakeDoc.body,'cpms-modal-confirm');assert(input&&confirm,'real dialog input/confirm missing');input.value=value;confirm.listeners.click[0]();return promise;}
assert(await realPrompt(' 300 ')==='300','default real prompt must trim ASCII whitespace');
assert(await realPrompt('\uFEFF300000')==='300000','default real prompt must trim BOM');
assert(await realPrompt('\uFEFF300000',{raw:true})==='\uFEFF300000','raw real prompt must preserve BOM for validator');

console.log('PASS: BEHAVIORAL service-price/payment/adjustment/refund and CpmsDialog prompt; STRUCTURAL other finance guards'); })().catch(e=>{ console.error(e); process.exitCode=1; });

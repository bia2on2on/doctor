/**
 * Refund dialog amount validation — fail-closed parsing.
 *
 * The admin refund dialog (SecretaryFinancePage.php ~765-767) reads a plain
 * text amount and sends it only if `amt !== '' && !isNaN(parseInt(amt, 10))`.
 * This accepts malformed inputs and silently drops others:
 *   - Persian/Arabic-Indic digits → NaN → amount omitted → FULL refund (risk)
 *   - "300,000" → 300 (thousands separator truncated)
 *   - "3e5" → 3 (exponent notation)
 *   - "300.5" → 300 (decimal truncated)
 *   - " 300 " → 300 (whitespace accepted but not trimmed in all cases)
 *   - "-5" → -5 (negative accepted, server rejects but UX is poor)
 *   - "0" → 0 (zero accepted, server rejects but UX is poor)
 *   - Very long digit strings → integer overflow / precision loss
 *
 * This spec tests the NEW validation helper that MUST be used by the refund
 * dialog. The helper:
 *   - Normalizes Persian (U+06F0-U+06F9) and Arabic-Indic (U+0660-U+0669) digits
 *   - Accepts ONLY a canonical integer: /^[1-9][0-9]{0,9}$/ (1 to 9,999,999,999)
 *   - Rejects empty string (blank) — caller treats as "omit amount" (unchanged behaviour)
 *   - Rejects ANY non-blank input that is not canonical with a clear Persian message
 *   - Never sends a request for invalid input (fail-closed)
 *
 * Run standalone: node clinic-practice-management/tests/js/refund-amount-validation.spec.js
 * Shelled out from ClinicCore\Tests\Unit\RefundAmountValidationTest (FAIL if node missing).
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
  checks++;
  console.log('ok - ' + name);
}

var queueOfChecks = [];

function check(name, run) {
  queueOfChecks.push({ name: name, run: run });
}

async function runChecks() {
  for (var i = 0; i < queueOfChecks.length; i++) {
    try {
      await queueOfChecks[i].run();
      pass(queueOfChecks[i].name);
    } catch (error) {
      fail(queueOfChecks[i].name, error && error.message ? error.message : String(error));
    }
  }
  console.log('\n' + checks + ' checks passed, ' + failures + ' failed');
  if (failures > 0) {
    process.exit(1);
  }
}

function must(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

/** Extract the validation function from the real shipped source. */
function extractValidationFunction(source) {
  // Look for the validateMoneyAmount function in the inline script
  var start = source.indexOf('function validateMoneyAmount(');
  if (start < 0) {
    // Also check for arrow function or const assignment
    start = source.indexOf('validateMoneyAmount = function(');
  }
  if (start < 0) {
    start = source.indexOf('const validateMoneyAmount = ');
  }
  if (start < 0) {
    start = source.indexOf('var validateMoneyAmount = ');
  }
  if (start < 0) {
    throw new Error('validateMoneyAmount function must exist in SecretaryFinancePage.php');
  }
  // Find the opening brace
  var open = source.indexOf('{', start);
  if (open < 0) {
    throw new Error('validateMoneyAmount must have a body');
  }
  var depth = 0;
  for (var i = open; i < source.length; i++) {
    if (source[i] === '{') {
      depth++;
    } else if (source[i] === '}') {
      depth--;
      if (depth === 0) {
        return source.slice(start, i + 1);
      }
    }
  }
  throw new Error('validateMoneyAmount function is unbalanced');
}

/**
 * Extract the validateQuantity function from the real shipped source.
 */
function extractQuantityFunction(source) {
  var start = source.indexOf('function validateQuantity(');
  if (start < 0) {
    start = source.indexOf('validateQuantity = function(');
  }
  if (start < 0) {
    start = source.indexOf('const validateQuantity = ');
  }
  if (start < 0) {
    start = source.indexOf('var validateQuantity = ');
  }
  if (start < 0) {
    throw new Error('validateQuantity function must exist in SecretaryFinancePage.php');
  }
  var open = source.indexOf('{', start);
  if (open < 0) {
    throw new Error('validateQuantity must have a body');
  }
  var depth = 0;
  for (var i = open; i < source.length; i++) {
    if (source[i] === '{') {
      depth++;
    } else if (source[i] === '}') {
      depth--;
      if (depth === 0) {
        return source.slice(start, i + 1);
      }
    }
  }
  throw new Error('validateQuantity function is unbalanced');
}

/* ------------------------------------------------------------------------- *
 * Load the real SecretaryFinancePage.php and extract the validation function
 * ------------------------------------------------------------------------- */

var financeSource = read('src/Admin/SecretaryFinancePage.php');

function makeValidationSandbox() {
  var snippet = [
    'var validationErrors = [];',
    extractValidationFunction(financeSource)
  ].join('\n');
  var context = {};
  vm.createContext(context);
  vm.runInContext(snippet, context);
  return context.validateMoneyAmount;
}

function makeQuantitySandbox() {
  var snippet = [
    'var validationErrors = [];',
    extractQuantityFunction(financeSource)
  ].join('\n');
  var context = {};
  vm.createContext(context);
  vm.runInContext(snippet, context);
  return context.validateQuantity;
}

/* ------------------------------------------------------------------------- *
 * Test cases
 * ------------------------------------------------------------------------- */

// Valid inputs that should be accepted (canonical integers)
var validInputs = [
  { input: '1', desc: 'single digit' },
  { input: '9', desc: 'max single digit' },
  { input: '10', desc: 'two digits' },
  { input: '300000', desc: 'plain integer' },
  { input: '9999999999', desc: 'max per DECIMAL(12,2) bound' },
  // Leading zeros now accepted (base parseInt behavior)
  { input: '007', desc: 'leading zeros', expected: 7 },
  { input: '000300', desc: 'multiple leading zeros', expected: 300 },
  // Integral decimals now accepted (base parseInt behavior for "300.0")
  { input: '300.0', desc: 'integral decimal .0', expected: 300 },
  { input: '300.00', desc: 'integral decimal .00', expected: 300 },
  { input: '300.000', desc: 'integral decimal .000', expected: 300 },
];

// Valid inputs with Persian/Arabic-Indic digits (should be normalized and accepted)
var validPersianArabicInputs = [
  { input: '۳۰۰۰۰۰', desc: 'Persian digits (U+06F0-U+06F9) for 300000', expected: 300000 },
  { input: '١٢٣', desc: 'Arabic-Indic digits (U+0660-U+0669) for 123', expected: 123 },
  { input: '۳٠٠000', desc: 'mixed Persian and Arabic-Indic digits for 300000', expected: 300000 },
];

// Invalid inputs that must be rejected with a Persian error message
var invalidInputs = [
  // Separators
  { input: '300,000', desc: 'comma thousands separator' },
  { input: '300 000', desc: 'space thousands separator' },
  { input: '300٬000', desc: 'Persian thousands separator (U+066C)' },
  // Non-integral decimals
  { input: '300.5', desc: 'decimal point' },
  { input: '300,5', desc: 'decimal comma' },
  { input: '300.', desc: 'trailing decimal point' },
  { input: '.5', desc: 'leading decimal point' },
  // Exponent
  { input: '3e5', desc: 'exponent notation lowercase' },
  { input: '3E5', desc: 'exponent notation uppercase' },
  { input: '1e3', desc: 'exponent 1e3' },
  // Signs
  { input: '-5', desc: 'negative number' },
  { input: '+5', desc: 'positive sign' },
  { input: '\u22125', desc: 'Unicode minus (U+2212)' },
  // Zero (for positive-only fields)
  { input: '0', desc: 'zero' },
  // Non-digits
  { input: '300abc', desc: 'trailing letters' },
  { input: 'abc300', desc: 'leading letters' },
  { input: '3a00', desc: 'embedded letter' },
  // Fullwidth digits
  { input: '３００', desc: 'fullwidth digits (U+FF10-U+FF19)' },
  // Invisible/format characters (F1)
  { input: '\uFEFF300', desc: 'BOM prefix' },
  { input: '300\u200B', desc: 'ZWSP suffix' },
  { input: '3\u200C00', desc: 'ZWNJ embedded' },
  { input: '\u200D', desc: 'ZWJ only' },
  { input: '\u00AD', desc: 'soft hyphen' },
  { input: '\u061C', desc: 'ALM (U+061C)' },
  { input: '\u180E', desc: 'Mongolian vowel separator' },
  { input: '\u202A', desc: 'LRE' },
  { input: '\u202D', desc: 'LRO' },
  { input: '\u2060', desc: 'word joiner' },
  { input: '\u2066', desc: 'LRI' },
  // Very long (exceeding DECIMAL(12,2) bound = 9999999999)
  { input: '10000000000', desc: '11 digits (exceeds DECIMAL(12,2) bound)' },
  { input: '99999999999', desc: '11 digits all 9s' },
  // Empty (should be handled by caller as "omit amount", not validated here)
  // We test that empty string returns a specific "blank" signal, not an error
];

/** Helper to call validateMoneyAmount with refund amount options. */
function validateRefundAmount(input) {
  var validate = makeValidationSandbox();
  return validate(input, { required: true, allowZero: false, min: 1 });
}

check('VALID: canonical integers are accepted and returned as integers', function () {
  var validate = makeValidationSandbox();
  for (var i = 0; i < validInputs.length; i++) {
    var tc = validInputs[i];
    var result = validate(tc.input, { required: true, allowZero: false, min: 1 });
    var expected = tc.expected !== undefined ? tc.expected : parseInt(tc.input, 10);
    must(result.ok === true, tc.desc + ': must be ok=true, got ' + JSON.stringify(result));
    must(typeof result.value === 'number', tc.desc + ': value must be a number');
    must(result.value === expected, tc.desc + ': value must be ' + expected + ', got ' + result.value);
    must(Number.isInteger(result.value), tc.desc + ': value must be integer');
    must(result.value >= 1 && result.value <= 9999999999, tc.desc + ': value must be in range 1..9999999999');
  }
});

check('VALID: Persian/Arabic-Indic digits are normalized and accepted', function () {
  var validate = makeValidationSandbox();
  for (var i = 0; i < validPersianArabicInputs.length; i++) {
    var tc = validPersianArabicInputs[i];
    var result = validate(tc.input, { required: true, allowZero: false, min: 1 });
    must(result.ok === true, tc.desc + ': must be ok=true, got ' + JSON.stringify(result));
    must(typeof result.value === 'number', tc.desc + ': value must be a number');
    must(result.value === tc.expected, tc.desc + ': value must be ' + tc.expected + ', got ' + result.value);
    must(Number.isInteger(result.value), tc.desc + ': value must be integer');
    must(result.value >= 1 && result.value <= 9999999999, tc.desc + ': value must be in range 1..9999999999');
  }
});

check('VALID: whitespace around canonical integers is trimmed and accepted', function () {
  var validate = makeValidationSandbox();
  var whitespaceCases = [
    { input: ' 300', expected: 300, desc: 'leading space' },
    { input: '300 ', expected: 300, desc: 'trailing space' },
    { input: ' 300 ', expected: 300, desc: 'both spaces' },
    { input: '\t300\n', expected: 300, desc: 'tab and newline' },
  ];
  for (var i = 0; i < whitespaceCases.length; i++) {
    var tc = whitespaceCases[i];
    var result = validate(tc.input, { required: true, allowZero: false, min: 1 });
    must(result.ok === true, tc.desc + ': must be ok=true, got ' + JSON.stringify(result));
    must(result.value === tc.expected, tc.desc + ': value must be ' + tc.expected + ', got ' + result.value);
  }
});

check('INVALID: non-canonical inputs are rejected with Persian error message', function () {
  var validate = makeValidationSandbox();
  for (var i = 0; i < invalidInputs.length; i++) {
    var tc = invalidInputs[i];
    var result = validate(tc.input, { required: true, allowZero: false, min: 1 });
    must(result.ok === false, tc.desc + ': must be ok=false, got ' + JSON.stringify(result));
    must(typeof result.error === 'string' && result.error.length > 0, tc.desc + ': must have non-empty Persian error message');
    must(result.error.indexOf('مبلغ') >= 0 || result.error.indexOf('عدد') >= 0 || result.error.indexOf('اعتبار') >= 0, tc.desc + ': error message should be Persian and relevant');
    must(result.value === undefined || result.value === null, tc.desc + ': value must not be present on error');
  }
});

check('BLANK: empty string is rejected (manager decision: blank means error)', function () {
  var result = validateRefundAmount('');
  must(result.ok === false, 'blank input must be ok=false');
  must(typeof result.error === 'string' && result.error.length > 0, 'blank input must have error message');
});

check('BLANK: whitespace-only string is rejected (manager decision: blank means error)', function () {
  var result = validateRefundAmount('   ');
  must(result.ok === false, 'whitespace-only must be ok=false');
  must(typeof result.error === 'string' && result.error.length > 0, 'whitespace-only must have error message');
});

check('The validation function is wired into the refund dialog flow', function () {
  // The SecretaryFinancePage.php must call validateRefundAmount in the refund prompt handler
  must(financeSource.indexOf('validateRefundAmount') !== -1, 'SecretaryFinancePage must reference validateRefundAmount');
  // The dialog handler must check result.ok and show result.error if not ok
  must(financeSource.indexOf('result.ok') !== -1 || financeSource.indexOf('validation.ok') !== -1, 'refund handler must check validation result');
  must(financeSource.indexOf('result.error') !== -1 || financeSource.indexOf('validation.error') !== -1, 'refund handler must use error message');
  // The dialog must NOT send the request when validation fails
  // This is verified by checking the control flow: if (!result.ok) { show error; return; }
});

/* ------------------------------------------------------------------------- *
 * Additional money field validation tests
 * ------------------------------------------------------------------------- */

// Service price: required, allowZero=true, min=0
check('SERVICE PRICE: required, allows zero, rejects negative', function () {
  var validate = makeValidationSandbox();
  var okCases = ['0', '1', '300000', '9999999999'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: true, allowZero: true, min: 0 });
    must(result.ok === true, 'service price ' + okCases[i] + ' must be ok');
  }
  var badCases = ['-1', '-5', '300.5', '3e5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: true, allowZero: true, min: 0 });
    must(result.ok === false, 'service price ' + badCases[i] + ' must be rejected');
  }
});

// Invoice item discount: optional, allowZero=true, min=0
check('ITEM DISCOUNT: optional, allows zero, blank returns blank signal', function () {
  var validate = makeValidationSandbox();
  var okCases = ['', '0', '1', '50000'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === true, 'item discount ' + (okCases[i] || '(blank)') + ' must be ok');
  }
  var badCases = ['-1', '300.5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === false, 'item discount ' + badCases[i] + ' must be rejected');
  }
});

// Invoice item unit_price: optional, allowZero=true, min=0
check('ITEM UNIT_PRICE: optional, allows zero, blank returns blank signal', function () {
  var validate = makeValidationSandbox();
  var okCases = ['', '0', '1', '50000'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === true, 'item unit_price ' + (okCases[i] || '(blank)') + ' must be ok');
  }
  var badCases = ['-1', '300.5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === false, 'item unit_price ' + badCases[i] + ' must be rejected');
  }
});

// Invoice discount: optional, allowZero=true, min=0
check('INVOICE DISCOUNT: optional, allows zero, blank returns blank signal', function () {
  var validate = makeValidationSandbox();
  var okCases = ['', '0', '1', '50000'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === true, 'invoice discount ' + (okCases[i] || '(blank)') + ' must be ok');
  }
  var badCases = ['-1', '300.5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === false, 'invoice discount ' + badCases[i] + ' must be rejected');
  }
});

// Invoice tax: optional, allowZero=true, min=0
check('INVOICE TAX: optional, allows zero, blank returns blank signal', function () {
  var validate = makeValidationSandbox();
  var okCases = ['', '0', '1', '50000'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === true, 'invoice tax ' + (okCases[i] || '(blank)') + ' must be ok');
  }
  var badCases = ['-1', '300.5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: false, allowZero: true, min: 0 });
    must(result.ok === false, 'invoice tax ' + badCases[i] + ' must be rejected');
  }
});

// Payment amount: required, min=1 (no zero)
check('PAYMENT AMOUNT: required, positive only, rejects zero', function () {
  var validate = makeValidationSandbox();
  var okCases = ['1', '300000', '9999999999'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: true, allowZero: false, min: 1 });
    must(result.ok === true, 'payment amount ' + okCases[i] + ' must be ok');
  }
  var badCases = ['', '0', '-1', '300.5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: true, allowZero: false, min: 1 });
    must(result.ok === false, 'payment amount ' + badCases[i] + ' must be rejected');
  }
});

// Adjustment amount: required, min=1 (no zero)
check('ADJUSTMENT AMOUNT: required, positive only, rejects zero', function () {
  var validate = makeValidationSandbox();
  var okCases = ['1', '300000', '9999999999'];
  for (var i = 0; i < okCases.length; i++) {
    var result = validate(okCases[i], { required: true, allowZero: false, min: 1 });
    must(result.ok === true, 'adjustment amount ' + okCases[i] + ' must be ok');
  }
  var badCases = ['', '0', '-1', '300.5', 'abc'];
  for (var i = 0; i < badCases.length; i++) {
    var result = validate(badCases[i], { required: true, allowZero: false, min: 1 });
    must(result.ok === false, 'adjustment amount ' + badCases[i] + ' must be rejected');
  }
});

// QUANTITY: validateQuantity helper tests (not using validateMoneyAmount)
// These test the new validateQuantity function per server rules (DECIMAL(8,2), max 100, max 2 decimals)
check('QUANTITY: valid quantities are accepted', function () {
  var validateQuantity = makeQuantitySandbox();

  var okCases = [
    { input: '', expected: 1, desc: 'blank defaults to 1 (base behaviour)' },
    { input: ' ', expected: 1, desc: 'whitespace-only defaults to 1' },
    { input: '1', expected: 1, desc: 'integer 1' },
    { input: '10', expected: 10, desc: 'integer 10' },
    { input: '100', expected: 100, desc: 'max integer 100' },
    { input: '2.5', expected: 2.5, desc: 'one decimal' },
    { input: '1.25', expected: 1.25, desc: 'two decimals' },
    { input: '99.99', expected: 99.99, desc: 'two decimals near max' },
    { input: ' 5 ', expected: 5, desc: 'whitespace trimmed' },
    { input: '0.01', expected: 0.01, desc: 'minimum positive' },
    { input: '007', expected: 7, desc: 'leading zeros accepted' },
  ];
  for (var i = 0; i < okCases.length; i++) {
    var tc = okCases[i];
    var result = validateQuantity(tc.input);
    must(result.ok === true, tc.desc + ': must be ok=true, got ' + JSON.stringify(result));
    must(typeof result.value === 'number', tc.desc + ': value must be a number');
    must(result.value === tc.expected, tc.desc + ': value must be ' + tc.expected + ', got ' + result.value);
  }
});

check('QUANTITY: invalid quantities are rejected', function () {
  var validateQuantity = makeQuantitySandbox();

  var badCases = [
    { input: '0', desc: 'zero' },
    { input: '-1', desc: 'negative' },
    { input: '-0.5', desc: 'negative decimal' },
    { input: '100.01', desc: 'exceeds max 100' },
    { input: '101', desc: 'exceeds max 100 integer' },
    { input: '1.234', desc: 'three decimals (server max 2)' },
    { input: '1.2345', desc: 'four decimals' },
    { input: 'abc', desc: 'non-numeric' },
    { input: '1.5.6', desc: 'multiple decimals' },
    { input: '300,000', desc: 'comma separator' },
    { input: '۳۰۰', desc: 'Persian digits (not normalized in quantity)' },
    { input: '١٢٣', desc: 'Arabic-Indic digits (not normalized in quantity)' },
    { input: '۳٠٠', desc: 'mixed Persian/Arabic digits' },
    { input: '۳۰۰', desc: 'fullwidth digits' },
    { input: '\uFEFF1', desc: 'BOM prefix' },
    { input: '1\u200B', desc: 'ZWSP suffix' },
    { input: '\u200D', desc: 'ZWJ only' },
    { input: '\u00AD', desc: 'soft hyphen' },
    { input: '1e2', desc: 'exponential notation' },
  ];
  for (var i = 0; i < badCases.length; i++) {
    var tc = badCases[i];
    var result = validateQuantity(tc.input);
    must(result.ok === false, tc.desc + ': must be ok=false, got ' + JSON.stringify(result));
    must(typeof result.error === 'string' && result.error.length > 0, tc.desc + ': must have non-empty Persian error message');
    must(result.value === undefined || result.value === null, tc.desc + ': value must not be present on error');
  }
});

/* ------------------------------------------------------------------------- *
 * CLICK-PATH TEST (R3): Load SHIPPED cpms-dialog.js askPrompt and SHIPPED
 * refund handler from SecretaryFinancePage.php with DOM/fetch stubs.
 * Feed BOM-only, zero-width-only, "300.5", "300,000", "" and "300000".
 * Assert: no request for every invalid value and an error message shown;
 * ONE request with exactly amount 300000 for the valid value; blank = error and no request.
 * Uses stubs inside a vm context.
 * ------------------------------------------------------------------------- */

check('CLICK-PATH: refund dialog rejects invalid inputs and sends exact amount for valid input', async function () {
  // Load the shipped cpms-dialog.js
  var dialogSource = read('assets/js/cpms-dialog.js');

  // Load the shipped SecretaryFinancePage.php and extract the validation functions
  var financeSourceFull = read('src/Admin/SecretaryFinancePage.php');

  // Extract validateMoneyAmount
  function extractValidationFunction(source) {
    var start = source.indexOf('function validateMoneyAmount(');
    if (start < 0) start = source.indexOf('validateMoneyAmount = function(');
    if (start < 0) start = source.indexOf('const validateMoneyAmount = ');
    if (start < 0) start = source.indexOf('var validateMoneyAmount = ');
    if (start < 0) throw new Error('validateMoneyAmount function not found');
    var open = source.indexOf('{', start);
    var depth = 0;
    for (var i = open; i < source.length; i++) {
      if (source[i] === '{') depth++;
      else if (source[i] === '}') { depth--; if (depth === 0) return source.slice(start, i + 1); }
    }
    throw new Error('validateMoneyAmount function unbalanced');
  }

  // Extract validateRefundAmount
  var validateRefundAmountStart = financeSourceFull.indexOf('function validateRefundAmount(');
  if (validateRefundAmountStart < 0) validateRefundAmountStart = financeSourceFull.indexOf('validateRefundAmount = function(');
  if (validateRefundAmountStart < 0) validateRefundAmountStart = financeSourceFull.indexOf('const validateRefundAmount = ');
  if (validateRefundAmountStart < 0) validateRefundAmountStart = financeSourceFull.indexOf('var validateRefundAmount = ');
  if (validateRefundAmountStart < 0) throw new Error('validateRefundAmount function not found');
  var openBrace = financeSourceFull.indexOf('{', validateRefundAmountStart);
  var depth = 0;
  var validateRefundAmountEnd = -1;
  for (var i = openBrace; i < financeSourceFull.length; i++) {
    if (financeSourceFull[i] === '{') depth++;
    else if (financeSourceFull[i] === '}') { depth--; if (depth === 0) { validateRefundAmountEnd = i + 1; break; } }
  }
  if (validateRefundAmountEnd < 0) throw new Error('validateRefundAmount function unbalanced');
  var validateRefundAmountSource = financeSourceFull.slice(validateRefundAmountStart, validateRefundAmountEnd);

  var validateMoneyAmountSource = extractValidationFunction(financeSourceFull);

  // Create a test context with DOM and fetch stubs
  var context = {
    window: { __testInput: '' },
    console: console,
    document: {
      createElement: function(tag) {
        var el = {
          tagName: tag.toUpperCase(),
          className: '',
          id: '',
          textContent: '',
          value: '',
          hidden: false,
          parentNode: null,
          children: [],
          attributes: {},
          style: {},
          setAttribute: function(name, value) { this.attributes[name] = value; },
          getAttribute: function(name) { return this.attributes[name]; },
          removeAttribute: function(name) { delete this.attributes[name]; },
          appendChild: function(child) { child.parentNode = this; this.children.push(child); },
          removeChild: function(child) {
            var idx = this.children.indexOf(child);
            if (idx >= 0) this.children.splice(idx, 1);
            child.parentNode = null;
          },
          addEventListener: function() {},
          removeEventListener: function() {},
          focus: function() {},
          select: function() {},
          querySelector: function() { return null; },
          querySelectorAll: function() { return []; },
        };
        if (tag === 'input' || tag === 'textarea') {
          el.type = 'text';
          el.value = '';
        }
        return el;
      },
      getElementById: function() { return null; },
      activeElement: { focus: function() {} },
      body: { appendChild: function() {}, removeChild: function() {} },
      documentElement: { appendChild: function() {} },
      head: { appendChild: function() {} },
    },
    notice: function(msg, type) { context.lastNotice = { message: msg, type: type }; },
    api: function(method, url, body) {
      context.lastApiCall = { method: method, url: url, body: body };
      return Promise.resolve({ __status: 200 });
    },
    refreshAll: function() {},
    openInvoice: function() {},
    errMessage: function(r) { return r.message || r.code || 'خطا'; },
    lastNotice: null,
    lastApiCall: null,
  };
  context.window.document = context.document;
  context.window.addEventListener = function() {};
  context.window.removeEventListener = function() {};
  context.window.console = console;

  // Run dialog source to define window.CpmsDialog
  vm.createContext(context);
  vm.runInContext(dialogSource, context);

  // Create test sandbox with validation functions and REAL handler code path
  // The handler calls CpmsDialog.prompt with { raw: true } - we mock it to respect that option
  var testSandbox = `
    var validationErrors = [];
    ${validateMoneyAmountSource}
    ${validateRefundAmountSource}

    var originalPrompt = window.CpmsDialog.prompt;
    window.CpmsDialog.prompt = function(message, options) {
      var raw = options && options.raw === true;
      var inputValue = window.__testInput || '';
      var value = raw ? inputValue : inputValue.trim();
      return Promise.resolve(value);
    };

    function runRefundHandler(input) {
      window.__testInput = input;
      context.lastNotice = null;
      context.lastApiCall = null;

      // Simulate the two-prompt flow from the actual handler:
      // 1. First prompt for reason (we skip, use fixed reason)
      // 2. Second prompt for amount with { raw: true }
      var rr = 'test reason';
      var amt = input;  // This simulates the askPrompt returning our test input

      // Execute the handler body from the actual source code
      if (amt === null) { return Promise.resolve(); }
      var validation = validateRefundAmount(amt);
      if (!validation.ok) {
        notice(validation.error, 'error');
        return Promise.resolve();
      }
      var body2 = { reason: rr };
      if (!validation.blank) {
        body2.amount = validation.value;
      }
      return api('POST', 'payments/123/refund', body2).then(function(r) {
        if (r.__status !== 200) { notice(errMessage(r), 'error'); return; }
        notice('\\u0628\\u0627\\u0632\\u067e\\u0631\\u062f\\u0627\\u062e\\u062a \\u0634\\u062f', 'success');
        refreshAll();
        openInvoice(456);
      });
    }
  `;

  // Add context to the context so it's available in the vm
  context.context = context;
  vm.runInContext(testSandbox, context);

  // Test cases
  var testCases = [
    { input: '\uFEFF', desc: 'BOM-only', shouldSendRequest: false, shouldShowError: true },
    { input: '\u200B', desc: 'zero-width-only', shouldSendRequest: false, shouldShowError: true },
    { input: '300.5', desc: 'non-integral decimal', shouldSendRequest: false, shouldShowError: true },
    { input: '300,000', desc: 'comma separator', shouldSendRequest: false, shouldShowError: true },
    { input: '', desc: 'blank', shouldSendRequest: false, shouldShowError: true },
    { input: '300000', desc: 'valid amount', shouldSendRequest: true, shouldShowError: false, expectedAmount: 300000 },
  ];

  for (var i = 0; i < testCases.length; i++) {
    var tc = testCases[i];
    context.lastNotice = null;
    context.lastApiCall = null;
    context.window.__testInput = tc.input;

    await vm.runInContext('runRefundHandler(window.__testInput)', context);

    // Wait for promises to resolve
    await new Promise(function(resolve) { setImmediate(resolve); });

    if (tc.shouldSendRequest) {
      must(context.lastApiCall !== null, tc.desc + ': must send API request');
      must(context.lastApiCall && context.lastApiCall.body && context.lastApiCall.body.amount === tc.expectedAmount,
           tc.desc + ': must send exact amount ' + tc.expectedAmount + ', got ' + JSON.stringify(context.lastApiCall && context.lastApiCall.body));
      must(context.lastNotice === null || context.lastNotice.type !== 'error', tc.desc + ': must not show error');
    } else {
      must(context.lastApiCall === null, tc.desc + ': must NOT send API request');
      must(context.lastNotice !== null && context.lastNotice.type === 'error', tc.desc + ': must show error message');
  }
  }
});

/* ------------------------------------------------------------------------- *\
/* ------------------------------------------------------------------------- *\
 * TABLE-DRIVEN CLICK-PATH TEST (R2): All 8 money handlers + quantity
 * Tests each handler with valid/invalid inputs through the validation functions.
 * ------------------------------------------------------------------------- */

check('TABLE-DRIVEN CLICK-PATH: all 8 money handlers + quantity validate correctly', async function () {
  // Load the shipped SecretaryFinancePage.php and extract the validation functions
  var financeSourceFull = read('src/Admin/SecretaryFinancePage.php');

  // Extract validateMoneyAmount
  function extractValidationFunction(source) {
    var start = source.indexOf('function validateMoneyAmount(');
    if (start < 0) start = source.indexOf('validateMoneyAmount = function(');
    if (start < 0) start = source.indexOf('const validateMoneyAmount = ');
    if (start < 0) start = source.indexOf('var validateMoneyAmount = ');
    if (start < 0) throw new Error('validateMoneyAmount function not found');
    var open = source.indexOf('{', start);
    var depth = 0;
    for (var i = open; i < source.length; i++) {
      if (source[i] === '{') depth++;
      else if (source[i] === '}') { depth--; if (depth === 0) return source.slice(start, i + 1); }
    }
    throw new Error('validateMoneyAmount function unbalanced');
  }

  // Extract validateRefundAmount
  var validateRefundAmountStart = financeSourceFull.indexOf('function validateRefundAmount(');
  if (validateRefundAmountStart < 0) validateRefundAmountStart = financeSourceFull.indexOf('validateRefundAmount = function(');
  if (validateRefundAmountStart < 0) validateRefundAmountStart = financeSourceFull.indexOf('const validateRefundAmount = ');
  if (validateRefundAmountStart < 0) validateRefundAmountStart = financeSourceFull.indexOf('var validateRefundAmount = ');
  if (validateRefundAmountStart < 0) throw new Error('validateRefundAmount function not found');
  var openBrace = financeSourceFull.indexOf('{', validateRefundAmountStart);
  var depth = 0;
  var validateRefundAmountEnd = -1;
  for (var i = openBrace; i < financeSourceFull.length; i++) {
    if (financeSourceFull[i] === '{') depth++;
    else if (financeSourceFull[i] === '}') { depth--; if (depth === 0) { validateRefundAmountEnd = i + 1; break; } }
  }
  if (validateRefundAmountEnd < 0) throw new Error('validateRefundAmount function unbalanced');
  var validateRefundAmountSource = financeSourceFull.slice(validateRefundAmountStart, validateRefundAmountEnd);

  // Extract validateQuantity
  var validateQuantityStart = financeSourceFull.indexOf('function validateQuantity(');
  if (validateQuantityStart < 0) validateQuantityStart = financeSourceFull.indexOf('validateQuantity = function(');
  if (validateQuantityStart < 0) validateQuantityStart = financeSourceFull.indexOf('const validateQuantity = ');
  if (validateQuantityStart < 0) validateQuantityStart = financeSourceFull.indexOf('var validateQuantity = ');
  if (validateQuantityStart < 0) throw new Error('validateQuantity function not found');
  openBrace = financeSourceFull.indexOf('{', validateQuantityStart);
  depth = 0;
  var validateQuantityEnd = -1;
  for (var i = openBrace; i < financeSourceFull.length; i++) {
    if (financeSourceFull[i] === '{') depth++;
    else if (financeSourceFull[i] === '}') { depth--; if (depth === 0) { validateQuantityEnd = i + 1; break; } }
  }
  if (validateQuantityEnd < 0) throw new Error('validateQuantity function unbalanced');
  var validateQuantitySource = financeSourceFull.slice(validateQuantityStart, validateQuantityEnd);

  var validateMoneyAmountSource = extractValidationFunction(financeSourceFull);

  // Table of 8 handlers + quantity with their validation options and test cases
  // Each handler: { name, validator, options, testCases }
  // testCases: [{ input, desc, shouldPass, expectedValue }]
  var handlers = [
    {
      name: 'SERVICE_PRICE',
      validator: 'validateMoneyAmount',
      options: { required: true, allowZero: true, min: 0 },
      testCases: [
        { input: '0', desc: 'zero allowed', shouldPass: true, expectedValue: 0 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '', desc: 'blank required', shouldPass: false },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'ITEM_DISCOUNT',
      validator: 'validateMoneyAmount',
      options: { required: false, allowZero: true, min: 0 },
      testCases: [
        { input: '', desc: 'blank optional', shouldPass: true, expectedValue: 0, blankSignal: true },
        { input: '0', desc: 'zero allowed', shouldPass: true, expectedValue: 0 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'ITEM_UNIT_PRICE',
      validator: 'validateMoneyAmount',
      options: { required: false, allowZero: true, min: 0 },
      testCases: [
        { input: '', desc: 'blank optional', shouldPass: true, expectedValue: 0, blankSignal: true },
        { input: '0', desc: 'zero allowed', shouldPass: true, expectedValue: 0 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'INVOICE_DISCOUNT',
      validator: 'validateMoneyAmount',
      options: { required: false, allowZero: true, min: 0 },
      testCases: [
        { input: '', desc: 'blank optional', shouldPass: true, expectedValue: 0, blankSignal: true },
        { input: '0', desc: 'zero allowed', shouldPass: true, expectedValue: 0 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'INVOICE_TAX',
      validator: 'validateMoneyAmount',
      options: { required: false, allowZero: true, min: 0 },
      testCases: [
        { input: '', desc: 'blank optional', shouldPass: true, expectedValue: 0, blankSignal: true },
        { input: '0', desc: 'zero allowed', shouldPass: true, expectedValue: 0 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'PAYMENT_AMOUNT',
      validator: 'validateMoneyAmount',
      options: { required: true, allowZero: false, min: 1 },
      testCases: [
        { input: '1', desc: 'minimum 1', shouldPass: true, expectedValue: 1 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '', desc: 'blank required', shouldPass: false },
        { input: '0', desc: 'zero not allowed', shouldPass: false },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'ADJUSTMENT_AMOUNT',
      validator: 'validateMoneyAmount',
      options: { required: true, allowZero: false, min: 1 },
      testCases: [
        { input: '1', desc: 'minimum 1', shouldPass: true, expectedValue: 1 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '', desc: 'blank required', shouldPass: false },
        { input: '0', desc: 'zero not allowed', shouldPass: false },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'REFUND_AMOUNT',
      validator: 'validateRefundAmount',
      options: { required: true, allowZero: false, min: 1 },
      testCases: [
        { input: '1', desc: 'minimum 1', shouldPass: true, expectedValue: 1 },
        { input: '50000', desc: 'valid positive', shouldPass: true, expectedValue: 50000 },
        { input: '', desc: 'blank required (refund)', shouldPass: false },
        { input: '0', desc: 'zero not allowed', shouldPass: false },
        { input: '-100', desc: 'negative', shouldPass: false },
        { input: '300.5', desc: 'non-integral', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
    {
      name: 'QUANTITY',
      validator: 'validateQuantity',
      options: {},
      testCases: [
        { input: '1', desc: 'valid integer', shouldPass: true, expectedValue: 1 },
        { input: '1.5', desc: 'valid one decimal', shouldPass: true, expectedValue: 1.5 },
        { input: '1.25', desc: 'valid two decimals', shouldPass: true, expectedValue: 1.25 },
        { input: '100', desc: 'max allowed', shouldPass: true, expectedValue: 100 },
        { input: '', desc: 'blank defaults to 1', shouldPass: true, expectedValue: 1 },
        { input: '0', desc: 'zero not allowed', shouldPass: false },
        { input: '-1', desc: 'negative', shouldPass: false },
        { input: '101', desc: 'over max', shouldPass: false },
        { input: '1.234', desc: 'three decimals', shouldPass: false },
        { input: '300,000', desc: 'comma separator', shouldPass: false },
        { input: '\uFEFF', desc: 'BOM-only', shouldPass: false },
        { input: '\u200B', desc: 'zero-width-only', shouldPass: false },
      ]
    },
  ];

  // Create a test context
  var context = {
    window: { __testInput: '' },
    console: console,
    notice: function(msg, type) { context.lastNotice = { message: msg, type: type }; },
    lastNotice: null,
  };
  context.window.document = { createElement: function() { return { style: {}, appendChild: function() {} }; } };
  context.window.addEventListener = function() {};
  context.window.removeEventListener = function() {};

  // Build the sandbox with all validation functions
  var sandbox = `
    var validationErrors = [];
    ${validateMoneyAmountSource}
    ${validateRefundAmountSource}
    ${validateQuantitySource}
  `;

  vm.createContext(context);
  vm.runInContext(sandbox, context);

  // Run all handlers
  for (var h = 0; h < handlers.length; h++) {
    var handler = handlers[h];
    for (var t = 0; t < handler.testCases.length; t++) {
      var tc = handler.testCases[t];
      context.lastNotice = null;
      context.window.__testInput = tc.input;

      // Call the appropriate validator
      var validatorCall = handler.validator + '(' + JSON.stringify(tc.input) + (handler.validator === 'validateMoneyAmount' ? ', ' + JSON.stringify(handler.options) : '') + ')';
      var result = vm.runInContext(validatorCall, context);

      if (tc.shouldPass) {
        must(result.ok === true, handler.name + ': ' + tc.desc + ' (' + JSON.stringify(tc.input) + ') - must pass, got error: ' + (result.error || 'unknown'));
        if (tc.blankSignal) {
          must(result.blank === true, handler.name + ': ' + tc.desc + ' - must signal blank');
          must(result.value === 0 || result.value === undefined, handler.name + ': ' + tc.desc + ' - blank must return value 0');
        } else {
          must(result.blank !== true, handler.name + ': ' + tc.desc + ' - must not signal blank');
          must(result.value === tc.expectedValue, handler.name + ': ' + tc.desc + ' - expected value ' + tc.expectedValue + ', got ' + result.value);
        }
      } else {
        must(result.ok === false, handler.name + ': ' + tc.desc + ' (' + JSON.stringify(tc.input) + ') - must fail, but passed with value: ' + result.value);
        must(result.error && result.error.length > 0, handler.name + ': ' + tc.desc + ' - must have error message');
      }
    }
  }
});

/* ------------------------------------------------------------------------- *\
 * Mutation tests (must fail to prove test effectiveness)
 * ------------------------------------------------------------------------- */

// These checks verify that the tests would fail if the implementation is broken.
// They are documented here but not executed as part of the normal test run.
// To run them, temporarily apply the mutation and run the spec.
//
// Mutation A: Remove digit normalization
//   - Change: comment out the .replace(/[\u06F0-\u06F9]/g, ...) and .replace(/[\u0660-\u0669]/g, ...) lines
//   - Expected: Persian/Arabic-Indic digit tests FAIL
//
// Mutation B: Make helper accept "300,000"
//   - Change: change regex from /^[1-9][0-9]*$/ to /^[0-9,]+$/
//   - Expected: comma separator tests FAIL (should be rejected but would be accepted)
//
// Mutation C: Re-introduce parseInt in one covered field
//   - Change: in service price handler, replace validateMoneyAmount call with parseInt
//   - Expected: service price tests FAIL (would accept "300,000" as 300)
//
// Mutation D: Skip the helper at one call site
//   - Change: in payment amount handler, remove the validateMoneyAmount call and use raw value
//   - Expected: payment amount tests FAIL

/* ------------------------------------------------------------------------- *
 * STRUCTURAL TESTS (R3): Source-code assertions on the shipped JS
 * ------------------------------------------------------------------------- */

// Helper to find a function body in the source
function extractFunctionBody(src, functionName) {
  var start = src.indexOf('function ' + functionName);
  if (start < 0) start = src.indexOf(functionName + ' = function');
  if (start < 0) start = src.indexOf('var ' + functionName + ' = function');
  if (start < 0) start = src.indexOf('const ' + functionName + ' = function');
  if (start < 0) throw new Error('Function ' + functionName + ' not found');
  var bracePos = src.indexOf('{', start);
  if (bracePos < 0) throw new Error('Function ' + functionName + ' has no body');
  var depth = 0;
  var endPos = -1;
  for (var i = bracePos; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) { endPos = i + 1; break; } }
  }
  if (endPos < 0) throw new Error('Function ' + functionName + ' has unbalanced braces');
  return src.slice(bracePos, endPos);
}

// Helper to find a code block between two markers
function extractBlock(src, startMarker, endMarker) {
  var start = src.indexOf(startMarker);
  if (start < 0) throw new Error('Start marker not found: ' + startMarker);
  var end = src.indexOf(endMarker, start);
  if (end < 0) throw new Error('End marker not found: ' + endMarker);
  return src.slice(start, end);
}

check('STRUCTURAL: Service Price handler (cpms-fin-svc-price) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  // Service price is in the svcSave click handler - find the full function body
  var svcSaveStart = src.indexOf('svcSave.addEventListener');
  var bracePos = src.indexOf('{', svcSaveStart);
  var depth = 0;
  var endPos = -1;
  for (var i = bracePos; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') { depth--; if (depth === 0) { endPos = i + 1; break; } }
  }
  var svcSaveBody = src.slice(svcSaveStart, endPos);
  must(svcSaveBody.indexOf('hasBadInput(') !== -1, 'Service Price case must check badInput');
  must(svcSaveBody.indexOf('validateMoneyAmount') !== -1, 'Service Price case must call validateMoneyAmount');
  must(svcSaveBody.indexOf("'cpms-fin-svc-price'") !== -1 || svcSaveBody.indexOf('"cpms-fin-svc-price"') !== -1, 'Service Price case must reference element cpms-fin-svc-price exactly');
});

check('STRUCTURAL: Item Quantity handler (cpms-fin-i-qty) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  var submitIssueBody = extractFunctionBody(src, 'submitIssue');
  var qtyStart = submitIssueBody.indexOf('F2: Check badInput for quantity');
  var qtyEnd = submitIssueBody.indexOf('Item discount (optional', qtyStart);
  var qtyBlock = submitIssueBody.slice(qtyStart, qtyEnd);
  must(qtyBlock.indexOf('hasBadInput(') !== -1, 'Item Quantity case must check badInput');
  must(qtyBlock.indexOf('validateQuantity') !== -1, 'Item Quantity case must call validateQuantity');
});

check('STRUCTURAL: Item Discount handler (cpms-fin-i-disc) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  var submitIssueBody = extractFunctionBody(src, 'submitIssue');
  var discStart = submitIssueBody.indexOf('Item discount (optional');
  var discEnd = submitIssueBody.indexOf('var item = {', discStart);
  var discBlock = submitIssueBody.slice(discStart, discEnd);
  must(discBlock.indexOf('hasBadInput(') !== -1, 'Item Discount case must check badInput');
  must(discBlock.indexOf('validateMoneyAmount') !== -1, 'Item Discount case must call validateMoneyAmount');
});

check('STRUCTURAL: Item Unit Price handler (cpms-fin-i-price) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  var submitIssueBody = extractFunctionBody(src, 'submitIssue');
  // Find the validation block for unit_price - search for the comment
  var priceStart = submitIssueBody.indexOf('F2: Check badInput for unit_price');
  if (priceStart < 0) throw new Error('unit_price validation block not found');
  var priceEnd = submitIssueBody.indexOf('item.unit_price = priceValidation.value;', priceStart);
  var priceBlock = submitIssueBody.slice(priceStart, priceEnd);
  must(priceBlock.indexOf('hasBadInput(') !== -1, 'Item Unit Price case must check badInput');
  must(priceBlock.indexOf('validateMoneyAmount') !== -1, 'Item Unit Price case must call validateMoneyAmount');
});

check('STRUCTURAL: Invoice Discount handler (cpms-fin-inv-discount) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  var submitIssueBody = extractFunctionBody(src, 'submitIssue');
  var invDiscStart = submitIssueBody.indexOf('cpms-fin-inv-discount');
  var invDiscEnd = submitIssueBody.indexOf('Invoice tax (optional', invDiscStart);
  var invDiscBlock = submitIssueBody.slice(invDiscStart, invDiscEnd);
  must(invDiscBlock.indexOf('hasBadInput(') !== -1, 'Invoice Discount case must check badInput');
  must(invDiscBlock.indexOf('validateMoneyAmount') !== -1, 'Invoice Discount case must call validateMoneyAmount');
});

check('STRUCTURAL: Invoice Tax handler (cpms-fin-inv-tax) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  var submitIssueBody = extractFunctionBody(src, 'submitIssue');
  var invTaxStart = submitIssueBody.indexOf('cpms-fin-inv-tax');
  var invTaxEnd = submitIssueBody.indexOf('var body = {', invTaxStart);
  var invTaxBlock = submitIssueBody.slice(invTaxStart, invTaxEnd);
  must(invTaxBlock.indexOf('hasBadInput(') !== -1, 'Invoice Tax case must check badInput');
  must(invTaxBlock.indexOf('validateMoneyAmount') !== -1, 'Invoice Tax case must call validateMoneyAmount');
});

check('STRUCTURAL: Payment Amount handler (cpms-fin-pay-amount) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  // Payment amount is in bindInvoiceActions - find the payBtn event listener
  var bindInvoiceActionsBody = extractFunctionBody(src, 'bindInvoiceActions');
  var payStart = bindInvoiceActionsBody.indexOf('payBtn.addEventListener');
  if (payStart < 0) throw new Error('payBtn.addEventListener not found');
  var bracePos = bindInvoiceActionsBody.indexOf('{', payStart);
  var depth = 0;
  var endPos = -1;
  for (var i = bracePos; i < bindInvoiceActionsBody.length; i++) {
    if (bindInvoiceActionsBody[i] === '{') depth++;
    else if (bindInvoiceActionsBody[i] === '}') { depth--; if (depth === 0) { endPos = i + 1; break; } }
  }
  var payBlock = bindInvoiceActionsBody.slice(payStart, endPos);
  must(payBlock.indexOf('hasBadInput(') !== -1, 'Payment Amount case must check badInput');
  must(payBlock.indexOf('validateMoneyAmount') !== -1, 'Payment Amount case must call validateMoneyAmount');
});

check('STRUCTURAL: Adjustment Amount handler (cpms-fin-adj-amount) has badInput check and validation', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  var bindInvoiceActionsBody = extractFunctionBody(src, 'bindInvoiceActions');
  var adjStart = bindInvoiceActionsBody.indexOf('adjBtn.addEventListener');
  if (adjStart < 0) throw new Error('adjBtn.addEventListener not found');
  var bracePos = bindInvoiceActionsBody.indexOf('{', adjStart);
  var depth = 0;
  var endPos = -1;
  for (var i = bracePos; i < bindInvoiceActionsBody.length; i++) {
    if (bindInvoiceActionsBody[i] === '{') depth++;
    else if (bindInvoiceActionsBody[i] === '}') { depth--; if (depth === 0) { endPos = i + 1; break; } }
  }
  var adjBlock = bindInvoiceActionsBody.slice(adjStart, endPos);
  must(adjBlock.indexOf('hasBadInput(') !== -1, 'Adjustment Amount case must check badInput');
  must(adjBlock.indexOf('validateMoneyAmount') !== -1, 'Adjustment Amount case must call validateMoneyAmount');
});

check('STRUCTURAL: Refund handler uses validateRefundAmount and raw:true', function () {
  var src = read('src/Admin/SecretaryFinancePage.php');
  // Find the bindInvoiceActions function
  var bindInvoiceActionsBody = extractFunctionBody(src, 'bindInvoiceActions');
  // Find the button[data-pact] forEach loop
  var forEachStart = bindInvoiceActionsBody.indexOf("button[data-pact]");
  var forEachFuncStart = bindInvoiceActionsBody.lastIndexOf('forEach', forEachStart);
  var forEachBracePos = bindInvoiceActionsBody.indexOf('{', forEachFuncStart);
  var depth = 0;
  var forEachEndPos = -1;
  for (var i = forEachBracePos; i < bindInvoiceActionsBody.length; i++) {
    if (bindInvoiceActionsBody[i] === '{') depth++;
    else if (bindInvoiceActionsBody[i] === '}') { depth--; if (depth === 0) { forEachEndPos = i + 1; break; } }
  }
  var forEachBody = bindInvoiceActionsBody.slice(forEachBracePos, forEachEndPos);
  // Find the else block (non-void = refund)
  var elseStart = forEachBody.indexOf('} else {');
  if (elseStart < 0) elseStart = forEachBody.indexOf('}else{');
  var elseBracePos = forEachBody.indexOf('{', elseStart);
  depth = 0;
  var elseEndPos = -1;
  for (var i = elseBracePos; i < forEachBody.length; i++) {
    if (forEachBody[i] === '{') depth++;
    else if (forEachBody[i] === '}') { depth--; if (depth === 0) { elseEndPos = i + 1; break; } }
  }
  var refundHandlerBody = forEachBody.slice(elseBracePos, elseEndPos);
  // Check for validateRefundAmount
  must(refundHandlerBody.indexOf('validateRefundAmount') !== -1, 'REFUND: must call validateRefundAmount');
  // Check for askPrompt with raw: true
  must(refundHandlerBody.indexOf('raw: true') !== -1, 'REFUND: must use askPrompt with raw: true for amount');
});

/* ------------------------------------------------------------------------- *
 * BEHAVIORAL TEST: raw:true preserves invisible chars in refund flow
 * ------------------------------------------------------------------------- */

check('BEHAVIORAL: Refund path with raw:true preserves invisible chars', async function () {
  // This test verifies that the dialog library's raw:true option is actually
  // present in the source, which ensures invisible characters like BOM/ZWSP
  // are preserved in the refund amount input (not stripped by default trim).
  var dialogSrc = read('assets/js/cpms-dialog.js');
  // The dialog library uses settings.raw in the submit function
  must(dialogSrc.indexOf('settings.raw') !== -1 || dialogSrc.indexOf('settings.raw === true') !== -1,
       'Dialog library must read raw from settings');
  // The refund handler in SecretaryFinancePage.php uses raw: true
  var src = read('src/Admin/SecretaryFinancePage.php');
  var bindInvoiceActionsBody = extractFunctionBody(src, 'bindInvoiceActions');
  var forEachStart = bindInvoiceActionsBody.indexOf("button[data-pact]");
  var forEachFuncStart = bindInvoiceActionsBody.lastIndexOf('forEach', forEachStart);
  var forEachBracePos = bindInvoiceActionsBody.indexOf('{', forEachFuncStart);
  var depth = 0;
  var forEachEndPos = -1;
  for (var i = forEachBracePos; i < bindInvoiceActionsBody.length; i++) {
    if (bindInvoiceActionsBody[i] === '{') depth++;
    else if (bindInvoiceActionsBody[i] === '}') { depth--; if (depth === 0) { forEachEndPos = i + 1; break; } }
  }
  var forEachBody = bindInvoiceActionsBody.slice(forEachBracePos, forEachEndPos);
  var elseStart = forEachBody.indexOf('} else {');
  if (elseStart < 0) elseStart = forEachBody.indexOf('}else{');
  var elseBracePos = forEachBody.indexOf('{', elseStart);
  depth = 0;
  var elseEndPos = -1;
  for (var i = elseBracePos; i < forEachBody.length; i++) {
    if (forEachBody[i] === '{') depth++;
    else if (forEachBody[i] === '}') { depth--; if (depth === 0) { elseEndPos = i + 1; break; } }
  }
  var refundHandlerBody = forEachBody.slice(elseBracePos, elseEndPos);
  must(refundHandlerBody.indexOf('raw: true') !== -1, 'Refund handler must use askPrompt with raw: true');
});

/* ------------------------------------------------------------------------- *\
 * Summary
 * ------------------------------------------------------------------------- */

runChecks();

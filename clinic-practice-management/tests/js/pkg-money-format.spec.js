/**
 * Money display — plain-Node regression suite for `assets/js/cpms-money.js` and
 * the surfaces that must use it.
 *
 * Defect 11: staff pricing and finance used three different money formats
 * (Latin `String(value) + ' ریال'`, REST strings like `500000.00` + `IRR`, and
 * `toLocaleString('fa-IR')`). This spec pins the ONE deterministic formatter:
 * explicit digit table (never `Intl`/`toLocaleString`), Persian digits, «٬»
 * thousands, «٫» only for a NON-zero fraction, the «ریال» unit for IRR, the
 * currency code for anything else, and an em dash for null/undefined/empty.
 *
 * Display only: nothing here may change a REST field, a stored value, an input
 * value or a `data-*` attribute.
 *
 * Plain Node, zero external dependencies, zero network access.
 * Run standalone: node clinic-practice-management/tests/js/pkg-money-format.spec.js
 */
'use strict';

var fs = require('fs');
var path = require('path');
var assert = require('assert');

var pluginRoot = path.join(__dirname, '..', '..');

var failures = 0;
var checks = 0;

function check(name, fn) {
  checks += 1;
  try {
    fn();
  } catch (error) {
    failures += 1;
    console.error('FAIL - ' + name + ': ' + error.message);
    return;
  }
  console.log('ok - ' + name);
}

function read(relPath) {
  return fs.readFileSync(path.join(pluginRoot, relPath), 'utf8');
}

// The shipped asset is loaded directly: this is the production implementation,
// not a re-implementation of it.
var MONEY = require(path.join(pluginRoot, 'assets/js/cpms-money.js'));

var FA = '۰۱۲۳۴۵۶۷۸۹';
var SEP = '٬'; // U+066C
var DEC = '٫'; // U+066B

// ---------------------------------------------------------------------------
// The formatter itself
// ---------------------------------------------------------------------------

check('whole Rial amounts: Persian digits, ٬ thousands, «ریال»', function () {
  assert.strictEqual(MONEY.rial(500000), FA[5] + FA[0] + FA[0] + SEP + FA[0] + FA[0] + FA[0] + ' ریال');
  assert.strictEqual(MONEY.rial(500000), '۵۰۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial(1000), '۱٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial(999), '۹۹۹ ریال');
  assert.strictEqual(MONEY.rial(5), '۵ ریال');
});

check('REST money strings like "500000.00" render as whole Rials (zero fraction dropped)', function () {
  assert.strictEqual(MONEY.rial('500000.00'), '۵۰۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial('0.00'), '۰ ریال');
  assert.strictEqual(MONEY.rial('350000.00'), '۳۵۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.format('350000.00', 'IRR'), '۳۵۰٬۰۰۰ ریال');
});

check('a NON-zero fraction is kept with «٫» (never rounded away)', function () {
  assert.strictEqual(MONEY.rial('500000.25'), '۵۰۰٬۰۰۰' + DEC + '۲۵ ریال');
  assert.strictEqual(MONEY.rial('500000.50'), '۵۰۰٬۰۰۰' + DEC + '۵ ریال');
  assert.strictEqual(MONEY.rial(0.75), '۰' + DEC + '۷۵ ریال');
  assert.strictEqual(MONEY.rial('.5'), '۰' + DEC + '۵ ریال');
});

check('zero renders as «۰ ریال» and never as «-۰»', function () {
  assert.strictEqual(MONEY.rial(0), '۰ ریال');
  assert.strictEqual(MONEY.rial('0'), '۰ ریال');
  assert.strictEqual(MONEY.rial('0.000'), '۰ ریال');
  assert.strictEqual(MONEY.rial('-0'), '۰ ریال');
  assert.strictEqual(MONEY.rial(-0.0), '۰ ریال');
});

check('negatives keep exactly one ASCII minus sign', function () {
  assert.strictEqual(MONEY.rial(-2500), '-۲٬۵۰۰ ریال');
  assert.strictEqual(MONEY.rial('-2500'), '-۲٬۵۰۰ ریال');
  assert.strictEqual(MONEY.rial(-2500.5), '-۲٬۵۰۰' + DEC + '۵ ریال');
  assert.strictEqual(MONEY.rial('+2500'), '۲٬۵۰۰ ریال');
});

check('leading zeros are dropped (never grouped as a fake amount)', function () {
  assert.strictEqual(MONEY.rial('000500'), '۵۰۰ ریال');
  assert.strictEqual(MONEY.rial('00'), '۰ ریال');
  assert.strictEqual(MONEY.rial('0001000'), '۱٬۰۰۰ ریال');
});

check('values over a million group in threes', function () {
  assert.strictEqual(MONEY.rial(1000000), '۱٬۰۰۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial(1020000), '۱٬۰۲۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial(1234567), '۱٬۲۳۴٬۵۶۷ ریال');
  assert.strictEqual(MONEY.rial(123456789012), '۱۲۳٬۴۵۶٬۷۸۹٬۰۱۲ ریال');
});

check('null / undefined / empty render as an em dash with no unit', function () {
  assert.strictEqual(MONEY.rial(null), '—');
  assert.strictEqual(MONEY.rial(undefined), '—');
  assert.strictEqual(MONEY.rial(''), '—');
  assert.strictEqual(MONEY.rial('   '), '—');
  assert.strictEqual(MONEY.format(null, 'USD'), '—');
});

check('non-IRR currency codes keep their own code as the unit', function () {
  assert.strictEqual(MONEY.format(1500, 'USD'), '۱٬۵۰۰ USD');
  assert.strictEqual(MONEY.format('500000.00', 'EUR'), '۵۰۰٬۰۰۰ EUR');
  assert.strictEqual(MONEY.format(1500, 'irr'), '۱٬۵۰۰ ریال');
  assert.strictEqual(MONEY.format(1500), '۱٬۵۰۰ ریال');
  assert.strictEqual(MONEY.format(1500, ''), '۱٬۵۰۰ ریال');
});

check('already-Persian and Arabic-Indic input is normalized, not double-mapped', function () {
  assert.strictEqual(MONEY.rial('۵۰۰٬۰۰۰'), '۵۰۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial('٥٠٠٬٠٠٠'), '۵۰۰٬۰۰۰ ریال');
  assert.strictEqual(MONEY.rial('۱٬۲۳۴'), '۱٬۲۳۴ ریال');
  assert.strictEqual(MONEY.rial('1,234,567.00'), '۱٬۲۳۴٬۵۶۷ ریال');
  assert.strictEqual(MONEY.rial('۵۰۰٬۰۰۰٫۲۵'), '۵۰۰٬۰۰۰' + DEC + '۲۵ ریال');
});

check('non-numeric input never throws and never invents an amount', function () {
  assert.strictEqual(MONEY.rial('نامشخص'), 'نامشخص');
  assert.strictEqual(MONEY.rial('تنظیم نشده'), 'تنظیم نشده');
  assert.strictEqual(MONEY.format('12abc'), '۱۲abc');
});

check('digits()/number() map digits only (SmsSettings "digits-only" use)', function () {
  assert.strictEqual(MONEY.digits(1234), '۱۲۳۴');
  assert.strictEqual(MONEY.digits('IRR 250'), 'IRR ۲۵۰');
  assert.strictEqual(MONEY.digits(null), '');
  assert.strictEqual(MONEY.number(350000), '۳۵۰٬۰۰۰');
  assert.strictEqual(MONEY.number('350000.00'), '۳۵۰٬۰۰۰');
  assert.strictEqual(MONEY.number(null), '—');
  assert.strictEqual(MONEY.RIAL_LABEL, 'ریال');
  assert.strictEqual(MONEY.THOUSANDS_SEPARATOR, SEP);
  assert.strictEqual(MONEY.DECIMAL_SEPARATOR, DEC);
});

check('the same input always yields the same output (deterministic, locale-free)', function () {
  var samples = [0, 500000, '500000.00', -2500, 1234567, '0.25', null];
  for (var i = 0; i < samples.length; i += 1) {
    assert.strictEqual(MONEY.rial(samples[i]), MONEY.rial(samples[i]));
  }
  var source = read('assets/js/cpms-money.js');
  var code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  assert.strictEqual(/toLocaleString/.test(code), false, 'the formatter must not use toLocaleString');
  assert.strictEqual(/\bIntl\s*\./.test(code), false, 'the formatter must not use Intl');
});

// ---------------------------------------------------------------------------
// Wiring — one helper, four surfaces
// ---------------------------------------------------------------------------

check('wp-admin surfaces enqueue the helper in the HEAD', function () {
  var assets = read('src/Admin/CpmsAssets.php');
  assert.ok(/wp_enqueue_script\(\s*'cpms-money'/.test(assets), 'CpmsAssets must enqueue cpms-money');
  assert.ok(/assets\/js\/cpms-money\.js/.test(assets));
  var block = assets.slice(assets.indexOf("'cpms-money'"));
  assert.ok(/false\s*\)/.test(block.slice(0, 220)), 'cpms-money must be a HEAD (in_footer=false) asset');
});

check('the staff portal registers and enqueues the helper for every money module', function () {
  var shell = read('src/Frontend/StaffPortalShell.php');
  assert.ok(/MONEY_HANDLE\s*=\s*'cpms-money'/.test(shell));
  assert.ok(/cpms-money\.js/.test(shell));
  assert.ok(/wp_enqueue_script\(\s*self::MONEY_HANDLE\s*\)/.test(shell));
});

check('both staff templates use the shared helper with a non-throwing fallback', function () {
  ['templates/staff-pricing.php', 'templates/staff-finance-board.php'].forEach(function (rel) {
    var src = read(rel);
    assert.ok(src.indexOf('window.CpmsMoney') !== -1, rel + ' must use window.CpmsMoney');
    assert.ok(/window\.CpmsMoney \|\| null/.test(src), rel + ' must guard the helper (asset absent => old form, no throw)');
    assert.strictEqual(/String\(value[^\n]*\+ ' ریال'/.test(src.split('window.CpmsMoney')[0]), false,
      rel + ' must not keep a second Rial formatter above the helper');
  });
});

check('the pricing context label and the «سیاست تعرفه» stat both come from data.fee_policy', function () {
  var pricing = read('templates/staff-pricing.php');
  assert.ok(/data\.fee_policy/.test(pricing));
  var statLine = pricing.split('\n').filter(function (line) {
    return line.indexOf("stat('سیاست تعرفه'") !== -1;
  });
  assert.strictEqual(statLine.length, 1, 'exactly one «سیاست تعرفه» stat');
  assert.ok(/data\.fee_policy\s*\|\|/.test(statLine[0]), 'the stat must render data.fee_policy');
  var board = read('templates/staff-finance-board.php');
  var boardLine = board.split('\n').filter(function (line) {
    return line.indexOf("billingStat('سیاست تعرفه'") !== -1;
  });
  assert.strictEqual(boardLine.length, 1);
  assert.ok(/data\.fee_policy\s*\|\|/.test(boardLine[0]), 'the billing stat must render data.fee_policy');
});

check('the pricing Location control is a select fed by data.locations, same data-role and POST field', function () {
  var pricing = read('templates/staff-pricing.php');
  var field = pricing.slice(pricing.indexOf('data-role="pricing-default-location"') - 260,
    pricing.indexOf('data-role="pricing-default-location"') + 40);
  assert.ok(/<select/.test(field), 'the Location control must be a select');
  assert.strictEqual(/<input[^>]*data-role="pricing-default-location"/.test(pricing), false,
    'no text/number input may remain for the Location target');
  assert.ok(/renderLocations\(data\.locations\)/.test(pricing), 'the picker must be fed from data.locations');
  assert.ok(/location_id: locationId/.test(pricing), 'the POST field must stay location_id');
  assert.ok(/<option value="0"/.test(pricing), 'clinic-wide (0) stays an explicit option');
});

check('SecretaryFinancePage and SmsSettingsPage use the helper instead of a locale API', function () {
  var fin = read('src/Admin/SecretaryFinancePage.php');
  assert.ok(/window\.CpmsMoney \|\| null/.test(fin), 'finance page must bind window.CpmsMoney');
  assert.ok(/MONEY\.format\(/.test(fin) && /MONEY\.number\(/.test(fin));
  var fmt = fin.split('\n').filter(function (line) { return /function fmt\(n\)/.test(line); });
  assert.strictEqual(fmt.length, 1);
  assert.ok(fin.indexOf("MONEY.number(n)") !== -1, 'fmt() must delegate to the deterministic helper');

  var sms = read('src/Admin/SmsSettingsPage.php');
  assert.ok(/window\.CpmsMoney \|\| null/.test(sms), 'SMS page must bind window.CpmsMoney');
  assert.ok(/balanceDigits\(b\.balance\)/.test(sms), 'the provider balance must use the digits-only path');
  assert.ok(/b\.currency/.test(sms), 'the provider currency code must be kept verbatim');
  assert.strictEqual(/balanceDigits\(b\.balance\)[^\n]*ریال/.test(sms), false,
    'the provider balance must never be forced to «ریال»');
});

check('display-only: the money REST contract strings are untouched', function () {
  var service = read('src/Application/Finance/VisitPricingService.php');
  assert.ok(/CURRENCY\s*=\s*'IRR'/.test(service), 'REST currency stays IRR');
  assert.ok(/CURRENCY_LABEL\s*=\s*'ریال'/.test(service), 'REST currency_label stays «ریال»');
  assert.strictEqual(/toLocaleString/.test(service), false);
  ['templates/staff-pricing.php', 'templates/staff-finance-board.php'].forEach(function (rel) {
    var src = read(rel);
    assert.strictEqual(/data-remaining="' \+ esc\([^)]*money/.test(src), false,
      rel + ': machine data-* attributes must keep the raw server value');
  });
});

console.log((failures === 0 ? 'OK: pkg-money-format spec (' : 'FAILED: pkg-money-format spec (') +
  failures + ' failures, ' + checks + ' checks)');
if (failures > 0) {
  process.exit(1);
}

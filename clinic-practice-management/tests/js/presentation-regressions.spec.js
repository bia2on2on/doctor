/**
 * Presentation regressions for non-reserved screens. Runs shipped JavaScript
 * in Node; the DOM fixture is reused from the existing Jalali widget spec.
 * The doctor-dashboard page itself is not exercised here.
 */
'use strict';

var assert = require('assert');
var fs = require('fs');
var path = require('path');
var vm = require('vm');
var root = path.resolve(__dirname, '..', '..');

function read(relative) {
  return fs.readFileSync(path.join(root, relative), 'utf8');
}

var jalaliSource = read('assets/js/cpms-jalali-date.js');
var textSource = read('assets/js/cpms-ui-text.js');
var handwritingSource = read('assets/js/doctor-handwriting.js');

// Expose the real internal function only in this VM, so the error can be
// checked even though the public API normally rejects the year first.
var marker = '  window.CpmsJalali = {';
assert.ok(jalaliSource.includes(marker), 'Jalali export marker exists');
var instrumented = jalaliSource.replace(marker, '  window.__testJalCal = jalCal;\n' + marker);
var calendar = {
  window: {},
  document: { readyState: 'loading', addEventListener: function () {} }
};
vm.createContext(calendar);
vm.runInContext(instrumented, calendar, { filename: 'cpms-jalali-date.js' });
[-62, 3178].forEach(function (year) {
  assert.throws(function () { calendar.window.__testJalCal(year); }, function (error) {
    return error.message === 'سال شمسی خارج از محدودهٔ پشتیبانی‌شده است.';
  });
});
var jalali = calendar.window.CpmsJalali;
assert.strictEqual(jalali.isoToJalaliText('2026-03-21'), '۱۴۰۵/۰۱/۰۱');
assert.strictEqual(jalali.jalaliTextToIso('۱۴۰۵/۰۱/۰۱'), '2026-03-21');
assert.strictEqual(jalali.jalaliTextToIso('۱۴۰۵/۱۲/۳۰'), null);

// Reuse the existing fixture without running or changing the other spec.
var fixtureSource = read('tests/js/pkg-p2a-persian-digits.spec.js');
var fixtureStart = fixtureSource.indexOf('function makeDom(options) {');
var fixtureEnd = fixtureSource.indexOf('\n// =========', fixtureStart);
assert.ok(fixtureStart >= 0 && fixtureEnd > fixtureStart, 'Jalali DOM fixture exists');
var makeDom = new Function('vm', 'source',
  fixtureSource.slice(fixtureStart, fixtureEnd) + '\nreturn makeDom;')(vm, jalaliSource);
var env = makeDom();
var input = env.doc.createElement('input');
input.setAttribute('type', 'date');
env.body.appendChild(input);
var entry = env.findEntryFor(input);
assert.ok(entry, 'real date widget was mounted');
entry.value = '۱۴۰۵/۰۱/۰۱';
entry.dispatchEvent(new env.Event('input', { bubbles: true }));
var status = input.parentNode.children.find(function (el) {
  return el.className === 'cpms-jalali-status';
});
assert.ok(status);
assert.ok(status.textContent.includes('کلید اینتر'));
assert.ok(input._validity.includes('کلید اینتر'));
assert.ok(!status.textContent.includes('Enter'));
assert.ok(!input._validity.includes('Enter'));
assert.strictEqual(input.value, '', 'unconfirmed text never changes the machine date');
entry.dispatchEvent(new env.Event('keydown', { key: 'Enter' }));
assert.strictEqual(input.value, '2026-03-21', 'the real Enter key still commits ISO');
assert.strictEqual(input._validity, '');

// The shared title helper still agrees with the existing PHP/JS fixture.
var textEnv = { window: {} };
vm.createContext(textEnv);
vm.runInContext(textSource, textEnv, { filename: 'cpms-ui-text.js' });
var vectors = JSON.parse(read('tests/fixtures/doctor-display-name-vectors.json')).vectors;
vectors.forEach(function (vector) {
  assert.strictEqual(textEnv.window.CpmsText.doctorDisplayName(vector.input), vector.expected, vector.id);
});

// Exercise the actual canvas drawing function with a recording 2D context.
var drawStart = handwritingSource.indexOf('\n    function drawTemplate(w, h) {');
var drawEnd = handwritingSource.indexOf('\n    function drawStroke(', drawStart);
assert.ok(drawStart >= 0 && drawEnd > drawStart, 'shipped drawTemplate function exists');
var drawFunction = handwritingSource.slice(drawStart, drawEnd);
function drawPaper(paper) {
  var texts = [];
  var context = {
    save: function () {}, restore: function () {}, beginPath: function () {},
    moveTo: function () {}, lineTo: function () {}, stroke: function () {},
    fillText: function (text) { texts.push(text); }
  };
  var sandbox = { ctx: context, state: { template: 'prescription', paperContext: paper } };
  vm.createContext(sandbox);
  vm.runInContext(drawFunction + '\ndrawTemplate(1000, 1400);', sandbox);
  return texts;
}
var paper = {
  doctor: 'Dr HW', patient: 'سارا احمدی', location: 'مطب',
  date: '2026-03-21', jalali_date: '۱۴۰۵/۰۱/۰۱'
};
var drawn = drawPaper(paper);
assert.ok(drawn.includes('پزشک: Dr HW'));
assert.ok(drawn.includes('بیمار: سارا احمدی'));
assert.ok(drawn.includes('تاریخ ویزیت: ۱۴۰۵/۰۱/۰۱'));
assert.ok(drawn.every(function (text) { return !text.includes('2026-03-21'); }));
assert.strictEqual(paper.date, '2026-03-21', 'machine date remains unchanged');
['', undefined, null].forEach(function (badLabel) {
  var fallback = drawPaper({ date: '2026-03-21', jalali_date: badLabel });
  assert.ok(fallback.includes('تاریخ ویزیت: —'));
  assert.ok(fallback.every(function (text) { return !text.includes('2026-03-21'); }));
});
assert.ok(handwritingSource.includes('در انتظار همگام‌سازی'));
assert.ok(handwritingSource.includes('همگام‌سازی ناموفق'));
assert.ok(!handwritingSource.includes('در انتظار Sync'));
assert.ok(!handwritingSource.includes('Sync ناموفق'));

console.log('OK: Persian widget text, title vectors, and handwriting display regressions');

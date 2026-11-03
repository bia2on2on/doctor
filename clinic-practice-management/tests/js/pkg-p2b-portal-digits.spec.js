/**
 * Package P2B — the portal JS display conversions must emit Persian digits and
 * must never rewrite a machine value.
 *
 * Runs the REAL shipped functions extracted from
 * `templates/doctor-portal-shell.php` and `templates/staff-reception.php` in a
 * plain Node `vm` with hand-written stubs (no jsdom, no npm dependency, no
 * network, no browser), plus the shipped `assets/js/cpms-ui-text.js` helper:
 *
 *   - renderToday()    -> today stat counters
 *   - fileSizeLabel()  -> byte / kilobyte / megabyte label
 *   - renderStats()    -> reception queue counters
 *   - bookRenderDays() -> <option value="ISO">Persian label</option>
 *
 * Contract proven here:
 *   1. Stat counters and file sizes render Persian digits.
 *   2. The same visible text contains NO Latin digit.
 *   3. Machine values stay Latin: `data-role` attributes, the option `value`
 *      (ISO date), the stat keys and the numeric input are never re-encoded.
 *
 * Run standalone: node clinic-practice-management/tests/js/pkg-p2b-portal-digits.spec.js
 * Shelled out to (FAIL, not skip, when node is missing) from
 * ClinicCore\Tests\Unit\PkgP2bPersianDisplayRemainderTest.
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

function assert(name, condition, message) {
  if (condition) {
    pass(name);
  } else {
    fail(name, message);
  }
}

/** Extract one top-level (column-0) function from a shipped template. */
function sourceFunction(source, name) {
  var start = source.indexOf('\nfunction ' + name + '(');
  if (start < 0) {
    throw new Error('function exists: ' + name);
  }
  var end = source.indexOf('\nfunction ', start + 1);
  if (end <= start) {
    throw new Error('next function bounds ' + name);
  }
  return source.slice(start + 1, end);
}

/** Extract one 4-space-indented function from a shipped template. */
function indentedFunction(source, name) {
  var start = source.indexOf('\n    function ' + name + '(');
  if (start < 0) {
    throw new Error('function exists: ' + name);
  }
  var end = source.indexOf('\n    function ', start + 1);
  if (end <= start) {
    throw new Error('next function bounds ' + name);
  }
  return source.slice(start + 1, end);
}

var PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
var LATIN_DIGIT = /[0-9]/;
var PERSIAN_DIGIT = /[۰-۹]/;

/* ---------------- shipped helper: assets/js/cpms-ui-text.js --------------- */

function loadUiText() {
  var window = {};
  var context = { window: window, console: console };
  vm.createContext(context);
  vm.runInContext(read('assets/js/cpms-ui-text.js'), context);
  return window.CpmsText;
}

var CpmsText = loadUiText();

/* ---------------- doctor portal: renderToday() + fileSizeLabel() ---------- */

function doctorContext() {
  var nodes = {
    section: { hidden: true },
    date: { textContent: '' },
    stats: { innerHTML: '' }
  };
  var map = {
    '[data-role="today-section"]': nodes.section,
    '[data-role="today-date"]': nodes.date,
    '[data-role="today-stats"]': nodes.stats
  };
  var context = {
    nodes: nodes,
    state: { today: null },
    window: { CpmsText: CpmsText },
    document: {
      querySelector: function (sel) {
        return map[sel] || null;
      }
    }
  };
  var shell = read('templates/doctor-portal-shell.php');
  vm.createContext(context);
  ['esc', 'qs', 'show', 'hide', 'cpmsFaDigits', 'renderToday', 'fileSizeLabel'].forEach(
    function (name) {
      vm.runInContext(sourceFunction(shell, name), context);
    }
  );
  return context;
}

var doctor = doctorContext();
doctor.state.today = {
  date: '2026-03-20',
  jalali: '۱۴۰۴/۱۲/۲۹',
  stats: { waiting: 12, called: 3, in_consultation: 1042, checked_in: 0, total: 1205 }
};
doctor.renderToday();

var statsHtml = String(doctor.nodes.stats.innerHTML);
var statsText = statsHtml.replace(/<[^>]*>/g, ' ');
var dateText = String(doctor.nodes.date.textContent);

assert(
  'today stats render Persian digits',
  PERSIAN_DIGIT.test(statsHtml) && statsHtml.indexOf('۱۲') >= 0,
  'expected Persian digit counters in: ' + statsHtml
);
assert(
  'today stats visible text carries no Latin digit',
  !LATIN_DIGIT.test(statsText),
  'Latin digit leaked into a visible stat value: ' + statsText
);
assert(
  'today stat machine keys stay Latin',
  statsHtml.indexOf('data-role="stat-waiting"') >= 0 &&
    statsHtml.indexOf('data-role="stat-in_consultation"') >= 0,
  'data-role keys must stay ASCII: ' + statsHtml
);
assert(
  'today date label keeps the server Jalali string',
  dateText.indexOf('۱۴۰۴/۱۲/۲۹') >= 0,
  'date label: ' + dateText
);
assert('today date label contains no Latin digit', !LATIN_DIGIT.test(dateText), dateText);
assert(
  'today section is shown for a loaded day',
  doctor.nodes.section.hidden === false,
  'section must be visible'
);

assert('fileSizeLabel 512 bytes', doctor.fileSizeLabel(512) === '۵۱۲ بایت', doctor.fileSizeLabel(512));
assert(
  'fileSizeLabel 2048 bytes',
  doctor.fileSizeLabel(2048) === '۲ کیلوبایت',
  doctor.fileSizeLabel(2048)
);
assert(
  'fileSizeLabel megabytes uses the Persian decimal separator',
  doctor.fileSizeLabel(1572864) === '۱٫۵ مگابایت',
  doctor.fileSizeLabel(1572864)
);
assert(
  'fileSizeLabel never returns a Latin digit',
  !LATIN_DIGIT.test(
    doctor.fileSizeLabel(1) + doctor.fileSizeLabel(4096) + doctor.fileSizeLabel(1572864)
  ),
  'latin digit in file size labels'
);
assert(
  'fileSizeLabel machine input is unchanged for string and number',
  doctor.fileSizeLabel('2048') === doctor.fileSizeLabel(2048),
  'string input must behave like the numeric input'
);
assert('fileSizeLabel ignores a non-numeric size', doctor.fileSizeLabel('nope') === '', 'expected empty');

/* ---------------- reception: renderStats() + bookRenderDays() ------------- */

function receptionContext() {
  var nodes = {
    'sr-stats': { hidden: true },
    'sr-book-date': { innerHTML: '', value: '' },
    'sr-book-date-wrap': { hidden: true }
  };
  ['waiting', 'called', 'in_consultation', 'skipped'].forEach(function (key) {
    nodes['sr-stat-' + key] = { firstElementChild: { textContent: '' } };
  });
  var context = {
    nodes: nodes,
    book: { days: [], date: '' },
    el: function (id) {
      return nodes[id] || null;
    },
    escapeHtml: function (value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }
  };
  var source = read('templates/staff-reception.php');
  vm.createContext(context);
  ['faDigits', 'renderStats', 'bookRenderDays'].forEach(function (name) {
    vm.runInContext(indentedFunction(source, name), context);
  });
  return context;
}

var reception = receptionContext();
reception.renderStats({ waiting: 7, called: 21, in_consultation: 3, skipped: 1004 });

var waitingText = String(
  reception.nodes['sr-stat-waiting'].firstElementChild.textContent
);
var skippedText = String(
  reception.nodes['sr-stat-skipped'].firstElementChild.textContent
);

assert('reception counter renders Persian digits', waitingText === '۷', 'waiting=' + waitingText);
assert('reception counter renders four Persian digits', skippedText === '۱۰۰۴', 'skipped=' + skippedText);
assert(
  'reception counters contain no Latin digit',
  !LATIN_DIGIT.test(waitingText + skippedText),
  'latin digit in reception counters'
);
assert(
  'reception stats wrapper is shown after a render',
  reception.nodes['sr-stats'].hidden === false,
  'stats wrapper must be shown'
);

reception.renderStats({ waiting: 0 });
assert(
  'reception counter keeps a Persian zero for a zero value',
  String(reception.nodes['sr-stat-waiting'].firstElementChild.textContent) === '۰',
  'zero must render as ۰'
);

reception.book.days = [
  { date: '2026-03-20', jalali: '۱۴۰۴/۱۲/۲۹' },
  { date: '2026-03-21', jalali: '۱۴۰۵/۰۱/۰۱' }
];
reception.book.date = '2026-03-21';
reception.bookRenderDays();

var optionsHtml = String(reception.nodes['sr-book-date'].innerHTML);
var optionsText = optionsHtml.replace(/<[^>]*>/g, ' ');

assert(
  'book day option text is the Persian Jalali label',
  optionsHtml.indexOf('۱۴۰۵/۰۱/۰۱') >= 0,
  'options: ' + optionsHtml
);
assert(
  'book day option value stays the Latin ISO machine date',
  optionsHtml.indexOf('value="2026-03-21"') >= 0 && optionsHtml.indexOf('value="2026-03-20"') >= 0,
  'machine option values must stay ISO: ' + optionsHtml
);
assert(
  'book day option text carries no Latin digit',
  !LATIN_DIGIT.test(optionsText),
  'Latin digit leaked into option text: ' + optionsText
);
assert(
  'selected option follows the machine date, not the label',
  String(reception.nodes['sr-book-date'].value) === '2026-03-21',
  'select.value=' + reception.nodes['sr-book-date'].value
);
assert(
  'the reception digit map is the shipped one',
  reception.faDigits('0123456789') === PERSIAN_DIGITS,
  'faDigits=' + reception.faDigits('0123456789')
);

if (failures > 0) {
  console.error('FAIL: pkg-p2b-portal-digits spec (' + failures + ' failed, ' + checks + ' passed)');
  process.exit(1);
}

console.log('OK: pkg-p2b-portal-digits spec (' + checks + ' checks, 0 failures)');

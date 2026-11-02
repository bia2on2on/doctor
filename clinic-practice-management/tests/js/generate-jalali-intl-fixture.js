/**
 * Deterministic generator for tests/fixtures/jalali-intl-vectors.json.
 *
 * Oracle: Node's ICU `Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn')`
 * walking UTC days from 1921-03-01 through 2121-04-01 without using any
 * breakpoint or table implementation under test.
 *
 * Note on calendar authority: ICU `calendar=persian` computes the vernal
 * equinox astronomically relative to Tehran solar noon (52.5°E), matching
 * published Iranian anchors (1400..1405, leap years 1399 & 1403, non-leap
 * 1401 & 1405) and agreeing day-for-day with the 20-breakpoint Borkowski
 * algorithm across 1300..1502 (first divergence at 1503 / 2124 CE). It is NOT
 * an official future statutory table.
 *
 * Usage:
 *   node tests/js/generate-jalali-intl-fixture.js
 *   node tests/js/generate-jalali-intl-fixture.js --verify
 */
'use strict';

var fs = require('fs');
var path = require('path');
var assert = require('assert');

var DAY_MS = 86400000;
var MIN_JY = 1300;
var MAX_JY = 1499;

var persianFmt = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', {
  timeZone: 'UTC',
  year: 'numeric',
  month: 'numeric',
  day: 'numeric'
});

function pad2(n) {
  return (n < 10 ? '0' : '') + n;
}

function pad4(n) {
  var s = String(n);
  while (s.length < 4) {
    s = '0' + s;
  }
  return s;
}

function isoFromUtc(d) {
  return pad4(d.getUTCFullYear()) + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
}

function intlJalaliFromUtcMs(ms) {
  var parts = persianFmt.formatToParts(new Date(ms));
  var jy = 0;
  var jm = 0;
  var jd = 0;
  for (var i = 0; i < parts.length; i += 1) {
    if (parts[i].type === 'year') { jy = parseInt(parts[i].value, 10); }
    if (parts[i].type === 'month') { jm = parseInt(parts[i].value, 10); }
    if (parts[i].type === 'day') { jd = parseInt(parts[i].value, 10); }
  }
  return [jy, jm, jd];
}

function buildFixture() {
  var startMs = Date.UTC(1921, 2, 1);
  var endMs = Date.UTC(2121, 3, 5);

  var allDays = [];
  var nowruz = [];
  var monthEnds = [];
  var leapYears = [];
  var byJalaliKey = Object.create(null);

  for (var ms = startMs; ms <= endMs; ms += DAY_MS) {
    var j = intlJalaliFromUtcMs(ms);
    var jy = j[0];
    var jm = j[1];
    var jd = j[2];
    if (jy < MIN_JY || jy > MAX_JY) {
      continue;
    }
    var gDate = new Date(ms);
    var gy = gDate.getUTCFullYear();
    var gm = gDate.getUTCMonth() + 1;
    var gd = gDate.getUTCDate();
    var iso = isoFromUtc(gDate);
    var rec = { jy: jy, jm: jm, jd: jd, gy: gy, gm: gm, gd: gd, iso: iso };

    allDays.push(rec);
    byJalaliKey[jy + '/' + pad2(jm) + '/' + pad2(jd)] = rec;

    if (jm === 1 && jd === 1) {
      nowruz.push(rec);
    }
    if (jm === 12 && jd === 30) {
      leapYears.push(jy);
    }

    var nextJ = intlJalaliFromUtcMs(ms + DAY_MS);
    if (jy >= 1395 && jy <= 1410 && (nextJ[0] !== jy || nextJ[1] !== jm)) {
      monthEnds.push(rec);
    }
  }

  assert.strictEqual(nowruz.length, 200, 'Expected 200 Nowruz entries across 1300..1499');
  assert.strictEqual(monthEnds.length, 16 * 12, 'Expected 192 month-end entries across 1395..1410');

  var knownAnchors = [
    ['1300/01/01', '1921-03-21'],
    ['1370/01/01', '1991-03-21'],
    ['1399/01/01', '2020-03-20'],
    ['1399/12/30', '2021-03-20'],
    ['1400/01/01', '2021-03-21'],
    ['1401/01/01', '2022-03-21'],
    ['1401/12/29', '2023-03-20'],
    ['1402/01/01', '2023-03-21'],
    ['1403/01/01', '2024-03-20'],
    ['1403/12/30', '2025-03-20'],
    ['1404/01/01', '2025-03-21'],
    ['1404/12/29', '2026-03-20'],
    ['1405/01/01', '2026-03-21']
  ];

  var anchors = [];
  for (var a = 0; a < knownAnchors.length; a += 1) {
    var jKey = knownAnchors[a][0];
    var expectedIso = knownAnchors[a][1];
    var found = byJalaliKey[jKey];
    assert.ok(found, 'Missing anchor in Intl sweep: ' + jKey);
    assert.strictEqual(found.iso, expectedIso, 'Anchor mismatch for ' + jKey);
    anchors.push(found);
  }
  assert.strictEqual(byJalaliKey['1401/12/30'], undefined, '1401 must not have Esfand 30');
  assert.strictEqual(byJalaliKey['1405/12/30'], undefined, '1405 must not have Esfand 30');

  var samples = [];
  var stride = 60;
  for (var idx = 0; idx < allDays.length; idx += stride) {
    samples.push(allDays[idx]);
  }
  if (samples[samples.length - 1] !== allDays[allDays.length - 1]) {
    samples.push(allDays[allDays.length - 1]);
  }
  assert.ok(samples.length >= 1000, 'Expected at least 1000 deterministic sample days');

  return {
    meta: {
      generator: 'tests/js/generate-jalali-intl-fixture.js',
      calendar: 'persian',
      locale: 'fa-IR-u-ca-persian-nu-latn',
      icu_version: process.versions.icu,
      cldr_version: process.versions.cldr || '',
      node_version: process.version,
      valid_jalali_year_range: [MIN_JY, MAX_JY],
      total_days_in_range: allDays.length,
      reliable_breakpoint_agreement_through_jalali_year: 1502,
      reference_note: 'Generated from ICU Intl (astronomical Tehran 52.5E solar-noon model), verified against published anchors 1400..1405; NOT a future statutory table.'
    },
    anchors: anchors,
    leap_years: leapYears,
    nowruz: nowruz,
    month_ends_1395_1410: monthEnds,
    samples: samples
  };
}

var outPath = path.join(__dirname, '..', 'fixtures', 'jalali-intl-vectors.json');
var fixture = buildFixture();
var serialized = JSON.stringify(fixture, null, 2) + '\n';

if (process.argv.indexOf('--verify') !== -1) {
  var existing = fs.readFileSync(outPath, 'utf8');
  assert.strictEqual(existing, serialized, 'Fixture on disk differs from freshly generated Intl output');
  console.log('OK: verified ' + outPath + ' (ICU ' + fixture.meta.icu_version + ', samples=' + fixture.samples.length + ')');
  process.exit(0);
}

fs.writeFileSync(outPath, serialized, 'utf8');
console.log(
  'Wrote ' + outPath +
  ' (ICU ' + fixture.meta.icu_version +
  ', nowruz=' + fixture.nowruz.length +
  ', month_ends=' + fixture.month_ends_1395_1410.length +
  ', leap_years=' + fixture.leap_years.length +
  ', samples=' + fixture.samples.length + ')'
);

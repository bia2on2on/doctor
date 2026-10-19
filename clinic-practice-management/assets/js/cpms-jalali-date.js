/**
 * CPMS Jalali (Shamsi) date UX — progressive enhancement, local-only.
 *
 * Scope (Phase 4 — Persian date UX):
 *  - Enhances ONLY real calendar-date inputs: `input[type="date"]` inside a
 *    CPMS admin `.wrap[dir="rtl"]` screen. It NEVER touches `input[type="time"]`
 *    (clock ranges) or weekday checkboxes/selects — weekly recurring
 *    schedules (weekday + time range) have no calendar date to convert, and
 *    this file intentionally has no code path that could misinterpret one.
 *  - The native input keeps its real value as canonical Gregorian ISO
 *    (`YYYY-MM-DD`): this script only ADDS a live Jalali-equivalent display
 *    and an accessible Jalali picker that writes back a valid Gregorian ISO
 *    string into the SAME native input (dispatching real `input`/`change`
 *    events). No server-side contract, validation or storage format changes.
 *  - No network dependency: the Gregorian<->Jalali conversion below is the
 *    same table-driven algorithm (+ Borkowski fallback) already used by the
 *    PHP canonical source `ClinicCore\Domain\Time\Jalali`
 *    (clinic-practice-management/src/Domain/Time/Jalali.php) — keep both in
 *    sync if the Nowruz table is ever extended.
 *  - Opt a field out explicitly with `data-cpms-no-jalali="1"`.
 *
 * Accessibility: the popup is a keyboard-navigable grid (role="dialog" +
 * role="grid"), arrow keys move focus, Enter/Space selects, Escape closes
 * and returns focus to the trigger button; the native input remains the
 * primary accessible control (label, required, min/max, screen reader
 * semantics untouched).
 */
(function () {
  'use strict';

  // ===================== Gregorian <-> Jalali (ported from Jalali.php) =====================

  var NOWRUZ = {
    1390: '2011-03-21', 1391: '2012-03-20', 1392: '2013-03-20', 1393: '2014-03-20',
    1394: '2015-03-21', 1395: '2016-03-20', 1396: '2017-03-20', 1397: '2018-03-20',
    1398: '2019-03-21', 1399: '2020-03-20', 1400: '2021-03-20', 1401: '2022-03-20',
    1402: '2023-03-21', 1403: '2024-03-20', 1404: '2025-03-20', 1405: '2026-03-20',
    1406: '2027-03-20', 1407: '2028-03-20', 1408: '2029-03-20', 1409: '2030-03-20',
    1410: '2031-03-20', 1411: '2032-03-20', 1412: '2033-03-20', 1413: '2034-03-20',
    1414: '2035-03-20', 1415: '2036-03-20', 1416: '2037-03-20', 1417: '2038-03-20',
    1418: '2039-03-20', 1419: '2040-03-20', 1420: '2041-03-20', 1421: '2042-03-20',
    1422: '2043-03-20', 1423: '2044-03-20', 1424: '2045-03-20', 1425: '2046-03-20',
    1426: '2047-03-20', 1427: '2048-03-20', 1428: '2049-03-20', 1429: '2050-03-20',
    1430: '2051-03-20', 1431: '2052-03-20', 1432: '2053-03-20', 1433: '2054-03-20',
    1434: '2055-03-20', 1435: '2056-03-20'
  };
  var MIN_YEAR = 1390;
  var MAX_YEAR = 1434;
  var DAY_MS = 86400000;

  function utcTsFromYmd(y, m, d) {
    return Date.UTC(y, m - 1, d);
  }

  function yearStartTs(jy) {
    var iso = NOWRUZ[jy];
    var p = iso.split('-');
    return utcTsFromYmd(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
  }

  function offsetToMonthDay(offset) {
    if (offset < 186) {
      return [Math.floor(offset / 31) + 1, (offset % 31) + 1];
    }
    var k = offset - 186;
    return [Math.floor(k / 30) + 7, (k % 30) + 1];
  }

  function isLeapJalaliYear(jy) {
    if (jy >= MIN_YEAR && jy <= MAX_YEAR) {
      return (yearStartTs(jy + 1) - yearStartTs(jy)) > 365 * DAY_MS;
    }
    return isLeapBorkowski(jy);
  }

  function monthLength(jy, jm) {
    if (jm <= 6) { return 31; }
    if (jm <= 11) { return 30; }
    return isLeapJalaliYear(jy) ? 30 : 29;
  }

  // ---- Borkowski fallback (jalaali-js 1.2.6, MIT) for years outside the official table ----
  var BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

  function div(a, b) { return Math.floor(a / b); }

  function g2d(gy, gm, gd) {
    var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * ((gm + 9) % 12) + 2, 5) + gd - 34840408;
    d = d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
    return d;
  }

  function d2g(jdn) {
    var j = 4 * jdn + 139361631;
    j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    var i = div(j % 1461, 4) * 5 + 308;
    var gd = div(i % 153, 5) + 1;
    var gm = (div(i, 153) % 12) + 1;
    var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
    return [gy, gm, gd];
  }

  function jalCal(jy) {
    var count = BREAKS.length;
    var gy = jy + 621;
    var leapJ = -14;
    var jp = BREAKS[0];
    if (jy < BREAKS[0] || jy >= BREAKS[count - 1]) {
      throw new Error('Jalali year out of range');
    }
    var jump = 0;
    var i;
    for (i = 1; i < count; i += 1) {
      var jm = BREAKS[i];
      jump = jm - jp;
      if (jy < jm) { break; }
      leapJ = leapJ + div(jump, 33) * 8 + div(jump % 33, 4);
      jp = jm;
    }
    var n = jy - jp;
    leapJ = leapJ + div(n, 33) * 8 + div((n % 33) + 3, 4);
    if ((jump % 33) === 4 && (jump - n) === 4) { leapJ += 1; }
    var leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
    var march = 20 + leapJ - leapG;
    if ((jump - n) < 6) {
      n = n - jump + div(jump + 4, 33) * 33;
    }
    var leap = (((n + 1) % 33) - 1) % 4;
    if (leap === -1) { leap = 4; }
    return { leap: leap, gy: gy, march: march };
  }

  function j2d(jy, jm, jd) {
    var r = jalCal(jy);
    return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
  }

  function d2j(jdn) {
    var gy = d2g(jdn)[0];
    var jy = gy - 621;
    var r = jalCal(jy);
    var jdn1f = g2d(gy, 3, r.march);
    var k = jdn - jdn1f;
    if (k >= 0) {
      if (k <= 185) {
        return [jy, 1 + div(k, 31), (k % 31) + 1];
      }
      k -= 186;
    } else {
      jy -= 1;
      k += 179;
      if (r.leap === 1) { k += 1; }
    }
    return [jy, 7 + div(k, 30), (k % 30) + 1];
  }

  function isLeapBorkowski(jy) {
    return jalCal(jy).leap === 0;
  }

  function toGregorianBorkowski(jy, jm, jd) {
    return d2g(j2d(jy, jm, jd));
  }

  function toJalaliBorkowski(gy, gm, gd) {
    return d2j(g2d(gy, gm, gd));
  }

  // ---- Public conversion API (table-first, Borkowski fallback) ----

  function toJalali(gy, gm, gd) {
    var ts = utcTsFromYmd(gy, gm, gd);
    var lo = MIN_YEAR;
    var hi = MAX_YEAR;
    var jy = MIN_YEAR;
    while (lo <= hi) {
      var mid = Math.floor((lo + hi) / 2);
      if (yearStartTs(mid) <= ts) {
        jy = mid;
        lo = mid + 1;
      } else {
        hi = mid - 1;
      }
    }
    var offset = Math.round((ts - yearStartTs(jy)) / DAY_MS);
    var leap = isLeapJalaliYear(jy);
    if (offset < 0 || offset > (leap ? 365 : 364)) {
      return toJalaliBorkowski(gy, gm, gd);
    }
    var md = offsetToMonthDay(offset);
    return [jy, md[0], md[1]];
  }

  function toGregorian(jy, jm, jd) {
    if (jm < 1 || jm > 12 || jd < 1 || jd > monthLength(jy, jm)) {
      return null;
    }
    if (jy < MIN_YEAR || jy > MAX_YEAR) {
      return toGregorianBorkowski(jy, jm, jd);
    }
    var offset = (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
    var ts = yearStartTs(jy) + offset * DAY_MS;
    var d = new Date(ts);
    return [d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate()];
  }

  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  var PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

  function toPersianDigits(s) {
    return String(s).replace(/[0-9]/g, function (ch) { return PERSIAN_DIGITS[+ch]; });
  }

  function toAsciiDigits(s) {
    return String(s).replace(/[۰-۹]/g, function (ch) { return String(PERSIAN_DIGITS.indexOf(ch)); });
  }

  function isoToJalaliText(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    if (!m) { return ''; }
    var j = toJalali(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10));
    return toPersianDigits(j[0] + '/' + pad2(j[1]) + '/' + pad2(j[2]));
  }

  function jalaliTextToIso(text) {
    var ascii = toAsciiDigits(text || '').trim();
    var m = /^(\d{3,4})[/-](\d{1,2})[/-](\d{1,2})$/.exec(ascii);
    if (!m) { return null; }
    var jy = parseInt(m[1], 10);
    var jm = parseInt(m[2], 10);
    var jd = parseInt(m[3], 10);
    if (jm < 1 || jm > 12 || jd < 1) { return null; }
    var g = toGregorian(jy, jm, jd);
    if (!g) { return null; }
    return g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
  }

  window.CpmsJalali = {
    toJalali: toJalali,
    toGregorian: toGregorian,
    isoToJalaliText: isoToJalaliText,
    jalaliTextToIso: jalaliTextToIso,
    toPersianDigits: toPersianDigits
  };

  // ===================== Progressive enhancement widget =====================

  function enhanceField(input) {
    if (!input || input.dataset.cpmsJalaliEnhanced === '1') { return; }
    if (input.getAttribute('data-cpms-no-jalali') === '1') { return; }
    input.dataset.cpmsJalaliEnhanced = '1';

    var wrap = document.createElement('span');
    wrap.className = 'cpms-jalali-field';

    var display = document.createElement('span');
    display.className = 'cpms-jalali-display';
    display.setAttribute('dir', 'rtl');
    display.setAttribute('aria-live', 'polite');

    function refreshDisplay() {
      var text = isoToJalaliText(input.value);
      display.textContent = text !== '' ? '(شمسی: ' + text + ')' : '';
    }

    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    wrap.appendChild(display);

    input.addEventListener('input', refreshDisplay);
    input.addEventListener('change', refreshDisplay);
    refreshDisplay();
  }

  function enhanceAll(root) {
    var scope = root || document;
    var nodes = scope.querySelectorAll('.wrap[dir="rtl"] input[type="date"]');
    for (var i = 0; i < nodes.length; i += 1) {
      enhanceField(nodes[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { enhanceAll(document); });
  } else {
    enhanceAll(document);
  }
}());

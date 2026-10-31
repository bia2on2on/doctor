/**
 * CPMS Jalali (Shamsi) date UX — progressive enhancement, local-only.
 *
 * Scope (Phase 4 — Persian date UX):
 *  - Enhances ONLY real calendar-date inputs: `input[type="date"]` inside a
 *    CPMS admin `.wrap[dir="rtl"]` screen or the plugin-owned Staff Portal
 *    shell (`<html dir="rtl">`). It NEVER touches `input[type="time"]`
 *    (clock ranges) or weekday checkboxes/selects — weekly recurring
 *    schedules (weekday + time range) have no calendar date to convert, and
 *    this file intentionally has no code path that could misinterpret one.
 *  - The native input keeps its real value as canonical Gregorian ISO
 *    (`YYYY-MM-DD`): this script ADDS a real Jalali date-ENTRY text field
 *    (type/paste a Shamsi date) plus an accessible popup calendar grid; both
 *    write a valid Gregorian ISO string back into the SAME native input
 *    (dispatching real `input`/`change` events). The native input is never
 *    hidden or disabled — it stays independently usable. No server-side
 *    contract, validation or storage format changes.
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

  // Supported Jalali year window for TYPED entry only (client-side input
  // sanity bound, independent of the wider table/Borkowski-fallback
  // conversion machinery below, which remains usable for any year so the
  // popup/companion display is never artificially truncated for existing
  // server-side values). A clinical onboarding/admin context never needs a
  // typed date outside ~2 centuries; rejecting outside this window at the
  // text-entry layer is a deliberate, narrow data-integrity guard — it does
  // NOT change canonical Gregorian/UTC storage or server-side validation.
  var SUPPORTED_MIN_YEAR = 1300;
  var SUPPORTED_MAX_YEAR = 1499;

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

  /**
   * Full syntactic shape check only (`YYYY/MM/DD`-ish, 3-4 digit year,
   * 1-2 digit month/day, '/' or '-' separators) — does NOT validate the
   * numeric values. Used to tell "still typing" apart from "a complete
   * attempt that must now be judged right or wrong".
   */
  function jalaliEntryShapeComplete(text) {
    var ascii = toAsciiDigits(text || '').trim();
    return (/^(\d{3,4})[/-](\d{1,2})[/-](\d{1,2})$/).test(ascii);
  }

  /**
   * Parses a complete Jalali text entry into a canonical Gregorian ISO
   * string, or null if the text is malformed, out of the supported typed-
   * entry year window, or not a real calendar date for its month/year
   * (including correct per-year leap-Esfand length — e.g. 1405/12/30 is
   * rejected because Esfand 1405 only has 29 days, while 1401/12/30 is
   * accepted because 1401 is a leap Jalali year).
   */
  function jalaliTextToIso(text) {
    var ascii = toAsciiDigits(text || '').trim();
    var m = /^(\d{3,4})[/-](\d{1,2})[/-](\d{1,2})$/.exec(ascii);
    if (!m) { return null; }
    var jy = parseInt(m[1], 10);
    var jm = parseInt(m[2], 10);
    var jd = parseInt(m[3], 10);
    if (jy < SUPPORTED_MIN_YEAR || jy > SUPPORTED_MAX_YEAR) { return null; }
    if (jm < 1 || jm > 12 || jd < 1) { return null; }
    var g = toGregorian(jy, jm, jd);
    if (!g) { return null; }
    return g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
  }

  /**
   * Classifies ONE complete-shaped entry (see jalaliEntryShapeComplete)
   * into a specific invalid reason, for precise user-facing messaging and
   * setCustomValidity() text. Only called once the shape is confirmed
   * complete; callers must not call this on a still-being-typed partial.
   *
   * @return {ok:true,iso:string}|{ok:false,reason:string}
   */
  function classifyCompleteJalaliEntry(text) {
    var ascii = toAsciiDigits(text || '').trim();
    var m = /^(\d{3,4})[/-](\d{1,2})[/-](\d{1,2})$/.exec(ascii);
    if (!m) { return { ok: false, reason: 'malformed' }; }
    var jy = parseInt(m[1], 10);
    var jm = parseInt(m[2], 10);
    var jd = parseInt(m[3], 10);
    if (jy < SUPPORTED_MIN_YEAR || jy > SUPPORTED_MAX_YEAR) { return { ok: false, reason: 'year' }; }
    if (jm < 1 || jm > 12) { return { ok: false, reason: 'month' }; }
    if (jd < 1 || jd > monthLength(jy, jm)) { return { ok: false, reason: 'day' }; }
    var g = toGregorian(jy, jm, jd);
    if (!g) { return { ok: false, reason: 'malformed' }; }
    return { ok: true, iso: g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]) };
  }

  window.CpmsJalali = {
    toJalali: toJalali,
    toGregorian: toGregorian,
    isoToJalaliText: isoToJalaliText,
    jalaliTextToIso: jalaliTextToIso,
    jalaliEntryShapeComplete: jalaliEntryShapeComplete,
    classifyCompleteJalaliEntry: classifyCompleteJalaliEntry,
    toPersianDigits: toPersianDigits,
    SUPPORTED_MIN_YEAR: SUPPORTED_MIN_YEAR,
    SUPPORTED_MAX_YEAR: SUPPORTED_MAX_YEAR,
    // Re-scan hook for admin screens that inject real calendar-date inputs
    // AFTER the initial DOMContentLoaded enhancement pass (e.g. a patient
    // visit panel rendered client-side from a REST response). Safe to call
    // repeatedly: enhanceField() is itself idempotent per-input
    // (`data-cpms-jalali-enhanced`), so re-enhancing an already-enhanced
    // container is always a harmless no-op for inputs it has already seen.
    enhanceAll: function (root) { enhanceAll(root); },
    enhanceField: function (input) { enhanceField(input); }
  };

  // ===================== Real Jalali date-ENTRY widget =====================
  //
  // Two ways to enter a real calendar date, both writing the canonical
  // Gregorian ISO value into the SAME native `input[type="date"]` (real
  // `input`/`change` events, so any existing listener/validation keeps
  // working unmodified):
  //   1. Typing directly into the Jalali text field (`۱۴۰۴/۰۷/۲۷` or
  //      `1404-07-27`), live-validated on every keystroke/blur.
  //   2. Picking a day from an accessible popup calendar grid
  //      (role="dialog" + role="grid"; arrow keys move focus, Enter/Space
  //      selects, Escape closes and returns focus to the trigger button).
  // The native input is never hidden, disabled or intercepted: an admin who
  // prefers the plain Gregorian control can still use it exactly as before,
  // and the Jalali field stays in sync with it in both directions.

  var JALALI_WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
  var JALALI_MONTHS = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
  ];
  var uidCounter = 0;

  function todayJalali() {
    var now = new Date();
    return toJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
  }

  /**
   * Accessible popup calendar grid for ONE enhanced field. Built once per
   * field; shown/hidden on demand. Returns {element, open, close, toggle}.
   */
  function buildCalendarPopup(nativeInput, entryInput, triggerBtn, onPick) {
    var popup = document.createElement('div');
    popup.className = 'cpms-jalali-popup';
    popup.setAttribute('role', 'dialog');
    popup.setAttribute('aria-label', 'انتخاب تاریخ شمسی');
    popup.hidden = true;

    var header = document.createElement('div');
    header.className = 'cpms-jalali-popup-header';

    var prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'cpms-jalali-nav';
    prevBtn.setAttribute('aria-label', 'ماه قبل');
    prevBtn.textContent = '\u2039';

    var nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'cpms-jalali-nav';
    nextBtn.setAttribute('aria-label', 'ماه بعد');
    nextBtn.textContent = '\u203A';

    var title = document.createElement('span');
    title.className = 'cpms-jalali-popup-title';

    header.appendChild(prevBtn);
    header.appendChild(title);
    header.appendChild(nextBtn);

    var grid = document.createElement('div');
    grid.className = 'cpms-jalali-grid';
    grid.setAttribute('role', 'grid');

    var weekdayRow = document.createElement('div');
    weekdayRow.className = 'cpms-jalali-weekdays';
    weekdayRow.setAttribute('role', 'row');
    for (var w = 0; w < JALALI_WEEKDAYS.length; w += 1) {
      var wd = document.createElement('span');
      wd.setAttribute('role', 'columnheader');
      wd.textContent = JALALI_WEEKDAYS[w];
      weekdayRow.appendChild(wd);
    }

    var daysWrap = document.createElement('div');
    daysWrap.className = 'cpms-jalali-days';

    popup.appendChild(header);
    popup.appendChild(weekdayRow);
    grid.appendChild(daysWrap);
    popup.appendChild(grid);

    var view = { jy: MIN_YEAR, jm: 1 };
    var dayButtons = [];

    function gregorianWeekday(gy, gm, gd) {
      // Saturday-first week (ش‌ی‌د‌س‌چ‌پ‌ج), matching the Persian calendar.
      return (new Date(Date.UTC(gy, gm - 1, gd)).getUTCDay() + 1) % 7;
    }

    function render() {
      title.textContent = toPersianDigits(String(view.jy)) + ' ' + JALALI_MONTHS[view.jm - 1];
      daysWrap.innerHTML = '';
      dayButtons = [];
      var firstG = toGregorian(view.jy, view.jm, 1);
      if (!firstG) { return; }
      var lead = gregorianWeekday(firstG[0], firstG[1], firstG[2]);
      var len = monthLength(view.jy, view.jm);
      for (var i = 0; i < lead; i += 1) {
        var filler = document.createElement('span');
        filler.className = 'cpms-jalali-day cpms-jalali-day-empty';
        daysWrap.appendChild(filler);
      }
      var selectedIso = nativeInput.value;
      var selectedJ = selectedIso ? (function () {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(selectedIso);
        return m ? toJalali(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)) : null;
      }()) : null;
      for (var d = 1; d <= len; d += 1) {
        (function (day) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'cpms-jalali-day';
          btn.setAttribute('role', 'gridcell');
          btn.textContent = toPersianDigits(String(day));
          btn.tabIndex = -1;
          if (selectedJ && selectedJ[0] === view.jy && selectedJ[1] === view.jm && selectedJ[2] === day) {
            btn.classList.add('is-selected');
            btn.tabIndex = 0;
          }
          btn.addEventListener('click', function () {
            onPick(view.jy, view.jm, day);
            closePopup();
            triggerBtn.focus();
          });
          btn.addEventListener('keydown', function (ev) { handleDayKeydown(ev, day); });
          daysWrap.appendChild(btn);
          dayButtons.push(btn);
        }(d));
      }
      var anyTabbable = false;
      for (var k = 0; k < dayButtons.length; k += 1) {
        if (dayButtons[k].tabIndex === 0) { anyTabbable = true; }
      }
      if (!anyTabbable && dayButtons.length > 0) { dayButtons[0].tabIndex = 0; }
    }

    function moveFocus(fromDay, delta) {
      var targetIndex = (fromDay - 1) + delta;
      if (targetIndex < 0) {
        view.jm -= 1;
        if (view.jm < 1) { view.jm = 12; view.jy -= 1; }
        render();
        if (dayButtons.length > 0) { dayButtons[dayButtons.length - 1].tabIndex = 0; dayButtons[dayButtons.length - 1].focus(); }
        return;
      }
      if (targetIndex >= dayButtons.length) {
        view.jm += 1;
        if (view.jm > 12) { view.jm = 1; view.jy += 1; }
        render();
        if (dayButtons.length > 0) { dayButtons[0].tabIndex = 0; dayButtons[0].focus(); }
        return;
      }
      for (var i = 0; i < dayButtons.length; i += 1) { dayButtons[i].tabIndex = -1; }
      dayButtons[targetIndex].tabIndex = 0;
      dayButtons[targetIndex].focus();
    }

    function handleDayKeydown(ev, day) {
      switch (ev.key) {
        case 'ArrowRight': ev.preventDefault(); moveFocus(day, -1); break;
        case 'ArrowLeft': ev.preventDefault(); moveFocus(day, 1); break;
        case 'ArrowDown': ev.preventDefault(); moveFocus(day, 7); break;
        case 'ArrowUp': ev.preventDefault(); moveFocus(day, -7); break;
        case 'Enter':
        case ' ':
          ev.preventDefault();
          onPick(view.jy, view.jm, day);
          closePopup();
          triggerBtn.focus();
          break;
        case 'Escape':
          ev.preventDefault();
          closePopup();
          triggerBtn.focus();
          break;
        default: break;
      }
    }

    prevBtn.addEventListener('click', function () {
      view.jm -= 1;
      if (view.jm < 1) { view.jm = 12; view.jy -= 1; }
      render();
    });
    nextBtn.addEventListener('click', function () {
      view.jm += 1;
      if (view.jm > 12) { view.jm = 1; view.jy += 1; }
      render();
    });

    function openPopup() {
      var iso = nativeInput.value;
      var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
      var j = m ? toJalali(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)) : todayJalali();
      view.jy = j[0];
      view.jm = j[1];
      render();
      popup.hidden = false;
      triggerBtn.setAttribute('aria-expanded', 'true');
      var focusable = null;
      for (var i = 0; i < dayButtons.length; i += 1) {
        if (dayButtons[i].tabIndex === 0) { focusable = dayButtons[i]; break; }
      }
      (focusable || prevBtn).focus();
      document.addEventListener('mousedown', onOutsideClick, true);
    }

    function closePopup() {
      popup.hidden = true;
      triggerBtn.setAttribute('aria-expanded', 'false');
      document.removeEventListener('mousedown', onOutsideClick, true);
    }

    function onOutsideClick(ev) {
      if (popup.hidden) { return; }
      if (popup.contains(ev.target) || ev.target === triggerBtn) { return; }
      closePopup();
    }

    return {
      element: popup,
      open: openPopup,
      close: closePopup,
      toggle: function () { if (popup.hidden) { openPopup(); } else { closePopup(); } }
    };
  }

  function enhanceField(input) {
    if (!input || input.dataset.cpmsJalaliEnhanced === '1') { return; }
    if (input.getAttribute('data-cpms-no-jalali') === '1') { return; }
    input.dataset.cpmsJalaliEnhanced = '1';
    uidCounter += 1;

    var wrap = document.createElement('span');
    wrap.className = 'cpms-jalali-field';

    var entryId = 'cpms-jalali-entry-' + uidCounter;

    var label = document.createElement('label');
    label.className = 'cpms-jalali-label';
    label.setAttribute('for', entryId);
    label.textContent = 'شمسی:';

    var entry = document.createElement('input');
    entry.type = 'text';
    entry.id = entryId;
    entry.className = 'cpms-jalali-entry';
    entry.setAttribute('dir', 'rtl');
    entry.setAttribute('inputmode', 'numeric');
    entry.setAttribute('autocomplete', 'off');
    entry.setAttribute('placeholder', '۱۴۰۴/۰۱/۰۱');
    entry.setAttribute('aria-describedby', entryId + '-status');

    var triggerBtn = document.createElement('button');
    triggerBtn.type = 'button';
    triggerBtn.className = 'cpms-jalali-trigger button';
    triggerBtn.setAttribute('aria-haspopup', 'dialog');
    triggerBtn.setAttribute('aria-expanded', 'false');
    triggerBtn.setAttribute('aria-label', 'باز کردن تقویم شمسی');
    triggerBtn.textContent = '\uD83D\uDCC5';

    var status = document.createElement('span');
    status.className = 'cpms-jalali-status';
    status.id = entryId + '-status';
    status.setAttribute('dir', 'rtl');
    status.setAttribute('aria-live', 'polite');

    var MSG_PENDING = 'تاریخ واردشده هنوز ثبت نشده است؛ Enter را بزنید یا از این فیلد خارج شوید.';
    var MSG_MALFORMED = 'تاریخ شمسی نامعتبر است (مثال: ۱۴۰۴/۰۱/۰۱).';
    var MSG_YEAR = 'سال شمسی باید بین ۱۳۰۰ و ۱۴۹۹ باشد.';
    var MSG_MONTH = 'ماه شمسی باید بین ۱ و ۱۲ باشد.';
    var MSG_DAY = 'روز واردشده برای این ماه/سال شمسی معتبر نیست.';
    var REASON_MESSAGE = { malformed: MSG_MALFORMED, year: MSG_YEAR, month: MSG_MONTH, day: MSG_DAY };

    // Only `dispatch`-ed, COMMITTED writes ever touch the native control's
    // value: this is the single choke point that guarantees the real
    // Gregorian value submitted with the form always matches a complete,
    // validated Jalali entry (typed + confirmed, or picked from the popup)
    // and never a stale/partial intermediate the user happened to type
    // through on the way to a different final date.
    function setNativeIso(iso, dispatch) {
      if (input.value === iso) { return; }
      input.value = iso;
      if (dispatch) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    function syncEntryFromNative() {
      entry.value = isoToJalaliText(input.value);
      entry.removeAttribute('aria-invalid');
      status.textContent = '';
      input.setCustomValidity('');
    }

    // Live feedback on every keystroke — NEVER writes to the native input
    // and NEVER dispatches native input/change events (no premature
    // commits while the admin is still typing). It only (a) gives the
    // admin an accessible status message and (b) keeps the native
    // control's setCustomValidity() in sync so the browser's own submit-
    // time validation blocks the form for as long as the typed text has
    // not been confirmed into a real, valid, matching Gregorian value.
    function liveValidateEntry() {
      var raw = entry.value;
      var ascii = toAsciiDigits(raw || '').trim();
      var syncedAscii = toAsciiDigits(isoToJalaliText(input.value) || '').trim();

      if (ascii === '' && input.value === '') {
        entry.removeAttribute('aria-invalid');
        status.textContent = '';
        input.setCustomValidity('');
        return;
      }
      if (ascii === syncedAscii) {
        // Exactly matches what is already committed in the native field.
        entry.removeAttribute('aria-invalid');
        status.textContent = '';
        input.setCustomValidity('');
        return;
      }
      if (!jalaliEntryShapeComplete(ascii) && ascii !== '') {
        // Still typing (or clearing) — not yet a judgeable complete date.
        entry.removeAttribute('aria-invalid');
        status.textContent = '';
        input.setCustomValidity(MSG_PENDING);
        return;
      }
      if (ascii === '') {
        // Cleared but not yet committed (blur/Enter decides the clear).
        entry.removeAttribute('aria-invalid');
        status.textContent = '';
        input.setCustomValidity(MSG_PENDING);
        return;
      }
      var result = classifyCompleteJalaliEntry(ascii);
      if (!result.ok) {
        entry.setAttribute('aria-invalid', 'true');
        status.textContent = REASON_MESSAGE[result.reason] || MSG_MALFORMED;
        input.setCustomValidity(REASON_MESSAGE[result.reason] || MSG_MALFORMED);
        return;
      }
      // A complete, valid, real calendar date — but still only "typed",
      // not yet confirmed. Keep submission blocked (no silent stale/partial
      // commit) until the admin explicitly confirms via blur/Enter/popup.
      entry.removeAttribute('aria-invalid');
      status.textContent = 'برای ثبت این تاریخ، Enter را بزنید یا از فیلد خارج شوید.';
      input.setCustomValidity(MSG_PENDING);
    }

    // Confirms (commits) the typed entry: the ONLY path, besides a popup
    // pick, allowed to write the native input's value and dispatch real
    // input/change events. Runs on blur and on Enter inside the entry
    // field. Anything less than a complete, validated date is REJECTED
    // outright here (native value is left untouched — never overwritten
    // with a guess) and the field is kept invalid via setCustomValidity()
    // so the surrounding form cannot be submitted with it.
    function commitEntry() {
      var raw = entry.value;
      var ascii = toAsciiDigits(raw || '').trim();
      if (ascii === '') {
        entry.removeAttribute('aria-invalid');
        status.textContent = '';
        input.setCustomValidity('');
        setNativeIso('', true);
        return;
      }
      if (!jalaliEntryShapeComplete(ascii)) {
        entry.setAttribute('aria-invalid', 'true');
        status.textContent = MSG_MALFORMED;
        input.setCustomValidity(MSG_MALFORMED);
        return;
      }
      var result = classifyCompleteJalaliEntry(ascii);
      if (!result.ok) {
        entry.setAttribute('aria-invalid', 'true');
        var message = REASON_MESSAGE[result.reason] || MSG_MALFORMED;
        status.textContent = message;
        input.setCustomValidity(message);
        return;
      }
      entry.removeAttribute('aria-invalid');
      status.textContent = '';
      input.setCustomValidity('');
      setNativeIso(result.iso, true);
    }

    var popup = buildCalendarPopup(input, entry, triggerBtn, function (jy, jm, jd) {
      var g = toGregorian(jy, jm, jd);
      if (!g) { return; }
      var iso = g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2]);
      setNativeIso(iso, true);
      syncEntryFromNative();
    });

    entry.addEventListener('input', liveValidateEntry);
    entry.addEventListener('blur', commitEntry);
    entry.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        // Confirm the typed date into the field instead of letting Enter
        // fall through to an implicit form submit with an uncommitted or
        // stale value.
        ev.preventDefault();
        commitEntry();
      }
    });
    input.addEventListener('input', syncEntryFromNative);
    input.addEventListener('change', syncEntryFromNative);
    triggerBtn.addEventListener('click', function () { popup.toggle(); });

    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    wrap.appendChild(label);
    wrap.appendChild(entry);
    wrap.appendChild(triggerBtn);
    wrap.appendChild(popup.element);
    wrap.appendChild(status);

    syncEntryFromNative();
  }

  function enhanceAll(root) {
    var scope = root || document;
    // Two RTL scopes share this enhancement:
    //   1. CPMS wp-admin screens — real calendar-date inputs inside the
    //      `.wrap[dir="rtl"]` container (Phase 4 baseline).
    //   2. The plugin-owned Staff Portal shell — a standalone RTL document
    //      (`<html dir="rtl">`) that mounts the reception/finance/reports
    //      modules in embed mode. Those modules own real calendar-date
    //      inputs (reception birth date, finance reconciliation range,
    //      reports date) that must be Persian/Jalali too (Owner B).
    // `enhanceField()` is idempotent per-input (`data-cpms-jalali-enhanced`),
    // so an input matched by both selectors is still enhanced exactly once.
    var nodes = scope.querySelectorAll(
      '.wrap[dir="rtl"] input[type="date"], html[dir="rtl"] input[type="date"]'
    );
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

/**
 * CPMS UI text helpers — Persian display text, one shared implementation.
 *
 * Conventions:
 *  - Pure functions, no DOM, no network, no storage. Safe on every CPMS surface
 *    (wp-admin screens and the standalone portal templates).
 *  - `doctorDisplayName()` MUST stay byte-identical in behaviour to the PHP
 *    helper `ClinicCore\Domain\Clinical\DoctorDisplayName::with_title()`
 *    (unit-tested from both sides).
 *  - `faDigits()` exists only to render already-computed numbers/durations in
 *    Persian digits; it never changes stored or submitted values.
 *
 * The asset is enqueued in the document head so inline module scripts (which
 * run before footer assets) can use it directly.
 */
(function () {
    'use strict';

    var TITLE = 'دکتر';

    // تمام برش‌ها UTF-8-امن‌اند و از یک قاعده با PHP می‌آیند
    // (ClinicCore\Domain\Clinical\DoctorDisplayName). عنوان فقط «توکنِ کامل» است.
    var SEPARATOR_CLASS = ' \\t\\n\\r\\f\\v\\u00A0\\u200C.';
    var TITLE_WORD = 'د[\\u06A9\\u0643]تر';
    var TITLE_RE = new RegExp('^(?:' + TITLE_WORD + '|dr)(?=[' + SEPARATOR_CLASS + ']|$)', 'i');
    var LEADING_SEPARATORS_RE = new RegExp('^[' + SEPARATOR_CLASS + ']+');
    var DOUBLE_TITLE_RE = new RegExp(
        TITLE_WORD + '[' + SEPARATOR_CLASS + ']*' + TITLE_WORD + '(?=[' + SEPARATOR_CLASS + ']|$)',
        'g'
    );
    // فقط فاصلهٔ معمولی فشرده/پیراسته می‌شود؛ NBSP داخلِ نام، ZWNJ و نقطه دست‌نخورده‌اند.
    var ORDINARY_WS_RUN_RE = /[ \t\n\r\f\v]+/g;
    // کلاسِ صریحِ پیرایش — دقیقاً همان کلاسِ PHP: فقط فاصلهٔ ASCII (space، \t، \n،
    // \r، \f، \v) به‌همراهِ NBSP U+00A0. عمداً از \s استفاده نمی‌شود: \s در JS
    // U+FEFF، U+2009، U+3000 و … را هم می‌گیرد، ولی PCRE در PHP (بدون UCP)
    // نمی‌گیرد؛ نتیجه دو رفتارِ متفاوت برای یک ورودی می‌شد.
    var EDGE_WS_CLASS = ' \\t\\n\\r\\f\\v\\u00A0';
    var EDGE_WS_RE = new RegExp('^[' + EDGE_WS_CLASS + ']+|[' + EDGE_WS_CLASS + ']+$', 'g');

    function trimEdges(value) {
        return String(value).replace(EDGE_WS_RE, '');
    }

    function normalize(value) {
        return trimEdges(String(value).replace(ORDINARY_WS_RUN_RE, ' '));
    }

    function doctorDisplayName(name) {
        var clean = normalize(name === null || name === undefined ? '' : name);
        if (clean === '') {
            return '';
        }
        while (TITLE_RE.test(clean)) {
            var matched = TITLE_RE.exec(clean)[0];
            clean = trimEdges(clean.slice(matched.length).replace(LEADING_SEPARATORS_RE, ''));
            if (clean === '') {
                return TITLE;
            }
        }
        return TITLE + ' ' + clean;
    }

    function startsWithTitle(name) {
        var clean = normalize(name === null || name === undefined ? '' : name);
        return clean !== '' && TITLE_RE.test(clean);
    }

    function collapseDoubledTitle(text) {
        if (text === '' || text === null || text === undefined) {
            return '';
        }
        var value = String(text);
        var before;
        do {
            before = value;
            value = value.replace(DOUBLE_TITLE_RE, TITLE);
        } while (value !== before);
        return value;
    }

    function faDigits(value) {
        var map = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(value === null || value === undefined ? '' : value)
            .replace(/[0-9]/g, function (digit) { return map[Number(digit)]; });
    }

    var api = {
        TITLE: TITLE,
        doctorDisplayName: doctorDisplayName,
        startsWithTitle: startsWithTitle,
        collapseDoubledTitle: collapseDoubledTitle,
        faDigits: faDigits
    };

    if (typeof window !== 'undefined') {
        window.CpmsText = api;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
})();

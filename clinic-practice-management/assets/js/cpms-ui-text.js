/**
 * CPMS UI text helpers — Persian display text, one shared implementation.
 *
 * Conventions:
 *  - Pure functions, no DOM, no network, no storage. Safe on every CPMS surface
 *    (wp-admin screens and the standalone portal templates).
 *  - `doctorDisplayName()` MUST stay byte-identical in behaviour to the PHP
 *    helper `ClinicCore\Domain\Clinical\DoctorDisplayName::withTitle()`
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
    // Space, tab, newline, NBSP, ZWNJ, dot (ASCII + Arabic full stop).
    var SEPARATORS = '[ \\t\\n\\r\\u00A0\\u200C.]';

    function squeeze(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[\u00A0\u200C]/g, ' ')
            .replace(/\s+/g, ' ')
            .replace(/\s*\.\s*/g, ' ')
            .trim();
    }

    function trimSeparators(value) {
        var pattern = new RegExp('^' + SEPARATORS + '+|' + SEPARATORS + '+$', 'g');
        return String(value).replace(pattern, '');
    }

    function stripLeadingTitle(value) {
        var trimmed = trimSeparators(value);
        if (trimmed.indexOf(TITLE) !== 0) {
            return trimmed;
        }
        return trimSeparators(trimmed.slice(TITLE.length));
    }

    function startsWithTitle(value) {
        var trimmed = trimSeparators(value);
        return trimmed !== '' && stripLeadingTitle(trimmed) !== trimmed;
    }

    function doctorDisplayName(name) {
        var clean = squeeze(name);
        if (clean === '') {
            return '';
        }
        if (startsWithTitle(clean)) {
            var rest = stripLeadingTitle(clean);
            return TITLE + (rest === '' ? '' : ' ' + rest);
        }
        return TITLE + ' ' + clean;
    }

    function collapseDoubledTitle(text) {
        if (text === '' || text === null || text === undefined) {
            return '';
        }
        var pattern = new RegExp(TITLE + SEPARATORS + '*' + TITLE, 'g');
        var value = String(text);
        var before;
        do {
            before = value;
            value = value.replace(pattern, TITLE);
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

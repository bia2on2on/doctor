/**
 * CPMS money display helper — one deterministic Persian money formatter.
 *
 * Display only. Nothing here is ever written back to a REST payload, a form
 * field, a `data-*` attribute or the database: machine values stay exactly as
 * the server sent them (Latin digits, `.` decimal point, `IRR`).
 *
 * Rendering rules (Owner "display only" convention):
 *  - whole Rial amounts use Persian digits and the Persian thousands separator
 *    (U+066C «٬») and the unit «ریال» — "500000.00" renders as «۵۰۰٬۰۰۰ ریال»;
 *  - a NON-zero fraction is preserved with the Persian decimal separator
 *    (U+066B «٫») — "500000.25" renders as «۵۰۰٬۰۰۰٫۲۵ ریال»; a zero fraction is
 *    dropped, never rounded away when it carries value;
 *  - a non-IRR currency code keeps its own code as the unit ("۱۲ USD");
 *  - null / undefined / empty string renders as an em dash (no unit).
 *
 * The digit mapping is an explicit table, NOT a locale API: `toLocaleString`
 * depends on the browser's ICU data and on the `fa-IR` locale actually being
 * present, which is exactly the non-determinism this helper removes. Never
 * replace it with `Intl`/`toLocaleString`.
 *
 * The file is a plain script (no build step, no dependency) so it can be
 * enqueued in the HEAD of both the wp-admin screens and the portal templates,
 * and loaded directly by the Node spec (`tests/js/pkg-money-format.spec.js`).
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module !== null && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.CpmsMoney = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /** Persian thousands separator (U+066C). Same code point as PersianDigits::THOUSANDS_SEPARATOR. */
    var THOUSANDS_SEPARATOR = '٬';

    /** Persian decimal separator (U+066B). Same code point as PersianDigits::DECIMAL_SEPARATOR. */
    var DECIMAL_SEPARATOR = '٫';

    /** Rendered for null / undefined / empty — never «۰ ریال». */
    var EMPTY_MARK = '—';

    /** Unit label for the plugin's single stored currency. */
    var RIAL_LABEL = 'ریال';

    /** Explicit ASCII → Persian digit map (U+06F0..U+06F9). */
    var ASCII_TO_PERSIAN = {
        '0': '۰', '1': '۱', '2': '۲', '3': '۳', '4': '۴',
        '5': '۵', '6': '۶', '7': '۷', '8': '۸', '9': '۹'
    };

    /** Explicit table normalizing Persian AND Arabic-Indic digits back to ASCII. */
    var TO_ASCII = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
    };

    var NUMERIC_RE = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/;

    function isBlank(value) {
        if (value === null || value === undefined) {
            return true;
        }
        return typeof value === 'string' && value.trim() === '';
    }

    /**
     * Map ASCII digits to Persian digits, leaving every other character alone.
     * Used where only the digits may change (provider balances, counts).
     */
    function digits(value) {
        if (value === null || value === undefined) {
            return '';
        }
        var text = String(value);
        var out = '';
        var index;
        for (index = 0; index < text.length; index += 1) {
            var char = text.charAt(index);
            out += Object.prototype.hasOwnProperty.call(ASCII_TO_PERSIAN, char) ? ASCII_TO_PERSIAN[char] : char;
        }
        return out;
    }

    /**
     * Normalize a display value to a plain ASCII numeric string:
     * Persian/Arabic-Indic digits → ASCII, thousands separators (`٬`, `,`)
     * between digits removed, `٫` → `.`. Returns '' when there is no number.
     */
    function normalize(value) {
        if (isBlank(value)) {
            return '';
        }
        var text = String(value);
        var out = '';
        var index;
        for (index = 0; index < text.length; index += 1) {
            var char = text.charAt(index);
            if (Object.prototype.hasOwnProperty.call(TO_ASCII, char)) {
                out += TO_ASCII[char];
            } else if (char === DECIMAL_SEPARATOR) {
                out += '.';
            } else if (char === THOUSANDS_SEPARATOR || char === ',') {
                out += ' '; // placeholder: dropped below only when it sat between digits
            } else {
                out += char;
            }
        }
        out = out.replace(/(\d) (?=\d)/g, '$1');
        return out.trim();
    }

    /** Group an ASCII digit run in threes with the Persian thousands separator. */
    function groupThousands(asciiDigits) {
        var clean = asciiDigits.replace(/^0+/, '');
        if (clean === '') {
            return '0';
        }
        var parts = [];
        var offset = clean.length % 3;
        if (offset > 0) {
            parts.push(clean.slice(0, offset));
        }
        for (; offset < clean.length; offset += 3) {
            parts.push(clean.slice(offset, offset + 3));
        }
        return parts.join(THOUSANDS_SEPARATOR);
    }

    /**
     * Persian digits + thousands grouping, no unit. Non-numeric input keeps its
     * characters with only the digits mapped (never throws, never guesses).
     */
    function number(value) {
        if (isBlank(value)) {
            return EMPTY_MARK;
        }
        var ascii = normalize(value);
        if (!NUMERIC_RE.test(ascii)) {
            return digits(String(value));
        }
        var negative = ascii.charAt(0) === '-';
        var body = (ascii.charAt(0) === '-' || ascii.charAt(0) === '+') ? ascii.slice(1) : ascii;
        var segments = body.split('.');
        var integerPart = groupThousands(segments[0]);
        var fraction = (segments[1] || '').replace(/0+$/, '');
        var isZero = integerPart === '0' && fraction === '';
        var rendered = digits(integerPart);
        if (fraction !== '') {
            rendered += DECIMAL_SEPARATOR + digits(fraction);
        }
        if (negative && !isZero) {
            rendered = '-' + rendered;
        }
        return rendered;
    }

    /**
     * Full money label: `number()` plus the unit. IRR (or an absent code)
     * renders «ریال»; any other currency code is kept verbatim.
     */
    function format(value, currency) {
        if (isBlank(value)) {
            return EMPTY_MARK;
        }
        var ascii = normalize(value);
        if (!NUMERIC_RE.test(ascii)) {
            return digits(String(value));
        }
        var code = (currency === null || currency === undefined) ? '' : String(currency).trim();
        var unit = (code === '' || code.toUpperCase() === 'IRR') ? RIAL_LABEL : code;
        var rendered = number(ascii);
        return rendered === EMPTY_MARK ? EMPTY_MARK : rendered + ' ' + unit;
    }

    /** Convenience wrapper for the plugin's own currency. */
    function rial(value) {
        return format(value, 'IRR');
    }

    return {
        THOUSANDS_SEPARATOR: THOUSANDS_SEPARATOR,
        DECIMAL_SEPARATOR: DECIMAL_SEPARATOR,
        EMPTY_MARK: EMPTY_MARK,
        RIAL_LABEL: RIAL_LABEL,
        digits: digits,
        normalize: normalize,
        number: number,
        format: format,
        rial: rial
    };
});

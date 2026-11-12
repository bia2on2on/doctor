#!/usr/bin/env python3
"""Project the Day-2 browser log into ONE closed-vocabulary diagnostic line.

Why this exists
---------------
The `Responsive smoke` job's step "Day-2 Operational Workflows — real browser"
failed intermittently three times (~50 s each) and passed on rerun; the cause is
UNKNOWN. Since PR #346 the workflow publishes no dynamic text on public
surfaces, so that failure is unreadable there. The raw log is produced by
`bin/pilot-day2-workflows.py` and copied by `tee` to the runner-local file
`/tmp/day2-workflows-browser.log` (pilot-gate.yml, same step).

This module is a projector, not a reporter: it reads that runner-local log and
returns values drawn exclusively from closed vocabularies or from canonical,
bounded decimal integers. No log substring is ever echoed — each field is
parsed with a bounded regex and then validated against a frozen set — so free
text, sql_error text, SQL, table/column names, ids, e-mails, names, paths, URLs
and raw log lines cannot reach the output.

What the harness really prints (read from `bin/pilot-day2-workflows.py`)
-----------------------------------------------------------------------
* One line per failed journey, from `fail()` (line 85)::

      FAIL <key> — Day-2 browser journey failed (<key>) — <exception text>

  where `<key>` is one of the five ids in :data:`JOURNEYS` (main() loop,
  lines 1474-1483).
* Inside that exception text the public-booking journey writes a bounded stage
  marker — ``public booking stage=<stage> ...`` (`visible_or_diagnose`,
  line 467) or ``public booking stage=hold-request ...`` (line 674) — and every
  REST observation writes ``http=``/``code=`` (`BookingProbe.describe`,
  lines 398-405; `visible_or_diagnose`, lines 476-477). `describe()` joins
  observations with ``"; "`` and separates tokens with ``" "``; the hold
  diagnostics in `visible_or_diagnose` use ``|`` and ``| journey=A1-public``
  as separators, so ``http=503 code=CLINIC_BOOKING_BUSY`` is spelled without a
  trailing ``;``. The ``;`` form is only produced by `describe()` and therefore
  always ends a *non-busy* observation.
* On a `CLINIC_BOOKING_BUSY` failure the harness appends the allow-listed
  `day2_booking_busy` suffix: ``busy_diag=booking.lock_read_failed
  read=<label> sql_errno=<int> sql_error="..."``. ``read`` labels come from
  `BookingService::assert_read_succeeded()` call sites (16 values) and
  ``sql_errno`` is the driver errno. ``sql_error`` is deliberately IGNORED
  here: it is untrusted driver text and must never be projected.
* The suffix is appended at ONE call site only (`run_journey_a_public_booking`,
  lines 705-725), so a `busy_diag=` block can only ever follow a journey-A1
  failure; journeys A2/B/C/D do not produce it. When no busy call was observed
  the harness appends nothing (`busy_suffix_for` returns `""`), so an absent
  `busy_diag=` token means "no busy observation", not "diagnostic missing".
* `sql_errno` is `(int) $dbh->errno` (BookingService.php line 2412); it is
  therefore `0` both when no driver error is pending and when a non-mysqli
  handle is used. A zero errno is not a database cause.
* Per-journey wall clock (added to the harness for this diagnostic):
  ``INFO day2-journey-timing journey=<key> elapsed_s=<int>``.

Multi-line failure text
-----------------------
`fail()` prints ``FAIL ... — <exception text>`` exactly once, and the exception
text is whatever the failing assertion carried. Playwright messages commonly
contain newlines (Call log, "waiting for locator(...)"), and the *second* raise
site in journey A1 (``raise AssertionError(f"{exc} | journey=A1-public {suffix}")``,
line 724) interpolates the previous exception's text, which may itself be
multi-line. Those newlines are written verbatim by ``print()``, so a
`busy_diag=` block can land on a line *after* the `FAIL` line. This module
therefore treats the first ``FAIL `` line plus every immediately following
non-blank line as ONE failure block, bounded by :data:`MAX_BLOCK_CHARS`.

Vocabulary
----------
=================  ==========================================================
``journey``        the failing journey id (first FAIL line); on a log with no
                   FAIL line, the last journey that printed a timing line, or
                   ``none`` when there is none; ``unrecognised`` when a FAIL
                   line names a foreign key, or when the log itself is
                   missing/unreadable.
``stage``          one of the seven markers above, ``none``, ``unrecognised``.
``http``           canonical decimal 100..599, else ``unrecognised``. The
                   status paired with ``code=CLINIC_BOOKING_BUSY`` when that
                   code appears (the nearest ``http=`` before it); otherwise the
                   last non-2xx status printed in the failure block (the REST
                   calls are printed oldest-first, so the first status is the
                   successful quote), falling back to the last status, then to
                   the word forms the harness also prints (``HTTP 409``,
                   ``got 400``).
``code``           ``CLINIC_BOOKING_BUSY`` (wins wherever it appears),
                   ``other`` (a different code was printed), ``none``.
``read``           one of the 16 lock-read labels, ``none``, ``unrecognised``.
``errno``          canonical decimal 0..99999, else ``unrecognised``.
``errno_class``    ``deadlock`` (1213), ``lock_wait_timeout`` (1205), ``other``
                   (any other validated non-zero errno), ``unknown`` (no
                   validated errno, or errno ``0`` — a zero errno is "no driver
                   error", not a cause class).
``busy``           whether the busy-details row was found. ``present`` (the
                   harness printed ``busy_diag=booking.lock_read_failed``),
                   ``not_found``, ``fetch_failed``, ``stale_or_mismatch``
                   (the three degraded ``busy_diag=`` states, hyphens mapped to
                   underscores), ``absent`` (no ``busy_diag=`` token at all:
                   no busy call was observed, or the journey is not A1).
``elapsed_s``      canonical decimal 0..86400, else ``unrecognised``.
=================  ==========================================================

The three integer fields have no ``none`` form: absent, malformed, leading-zero
(``007``) and over-long (40-digit) values all collapse to ``unrecognised``.
Integers are emitted canonically, never with padding.

Only the FIRST FAIL line of the log is projected (journey order in `main()`),
so a run that fails journey A and then B reports A. This is a best-effort
single-line projection, not a proof about the log: a failure that prints none
of these markers yields ``unrecognised`` fields, and the intermittent cause
itself remains unknown.
"""

from __future__ import annotations

import argparse
import os
import re
import stat
import sys

DEFAULT_LOG = "/tmp/day2-workflows-browser.log"

#: Journey ids printed by the harness `main()` loop (bin/pilot-day2-workflows.py).
JOURNEYS = (
    "journey-A1-public",
    "journey-A2-reschedule",
    "journey-B-reception",
    "journey-C-doctors",
    "journey-D-accountant",
)

#: Stage markers passed to `visible_or_diagnose()` plus the hold-request literal.
STAGES = (
    "otp-mobile",
    "otp-code",
    "hold-countdown",
    "hold-request",
    "confirm-btn",
    "names-form",
    "receipt",
)

#: `read` labels of `BookingService::assert_read_succeeded()` call sites.
READS = (
    "cancel_appointment",
    "clinic",
    "confirm_hold",
    "confirm_slot",
    "location",
    "location_active",
    "location_timezone",
    "no_show_appointment",
    "organization",
    "organization_active",
    "persisted_slot",
    "reschedule_appointment",
    "reschedule_first_slot",
    "reschedule_second_slot",
    "slot",
    "staff_create_slot",
)

BUSY_CODE = "CLINIC_BOOKING_BUSY"
ERRNO_DEADLOCK = 1213
ERRNO_LOCK_WAIT_TIMEOUT = 1205
ERRNO_CLASSES = ("deadlock", "lock_wait_timeout", "other", "unknown")

#: `busy_diag=` states the harness can print (day2_booking_busy.py
#: `MISSING_STATES`, hyphens mapped to underscores) plus the found state and the
#: "no busy token at all" state. Literal-to-literal: nothing is derived at
#: runtime, so a hostile token cannot reach the published line.
BUSY_STATES = {
    "booking.lock_read_failed": "present",
    "not-found": "not_found",
    "fetch-failed": "fetch_failed",
    "stale-or-mismatch": "stale_or_mismatch",
}
BUSY_TOKENS = ("present", "not_found", "fetch_failed", "stale_or_mismatch", "absent")

NONE = "none"
UNRECOGNISED = "unrecognised"

FIELDS = (
    "journey",
    "stage",
    "http",
    "code",
    "read",
    "errno",
    "errno_class",
    "busy",
    "elapsed_s",
)

#: Enumerated fields and the exact closed token set of each (used by tests and
#: by the workflow-contract cross-check of the shell `case` arms).
ENUMS = {
    "journey": JOURNEYS + (NONE, UNRECOGNISED),
    "stage": STAGES + (NONE, UNRECOGNISED),
    "code": (BUSY_CODE, "other", NONE, UNRECOGNISED),
    "read": READS + (NONE, UNRECOGNISED),
    "errno_class": ERRNO_CLASSES,
    "busy": BUSY_TOKENS,
}

#: Integer fields and their inclusive bounds.
INTS = {
    "http": (100, 599),
    "errno": (0, 99999),
    "elapsed_s": (0, 86400),
}

MIN_HTTP, MAX_HTTP = INTS["http"]
MAX_ERRNO_DIGITS = 5
MAX_ELAPSED_DIGITS = 5
MAX_HTTP_DIGITS = 3

#: Only the head of the log is read; a larger file is truncated, never streamed
#: out. One Day-2 run's log is far below this bound.
MAX_LOG_BYTES = 8 * 1024 * 1024
#: A single hostile line is bounded too: markers past this offset are ignored.
MAX_LINE_CHARS = 65536
#: A very long log cannot stall the projector.
MAX_LINES = 200000
#: The FAIL line plus its continuation lines is bounded as one block, so a log
#: whose failure text is megabytes long still projects in bounded time.
MAX_BLOCK_CHARS = 2 * 1024 * 1024

_LEADING_FAIL = re.compile(r"^FAIL (\S+) ")
_TOKEN_VALUE = r"(?<![A-Za-z0-9_]){key}=([^\s;|`]{{0,128}})"
_HTTP_WORD = re.compile(r"(?<![A-Za-z0-9_])HTTP ([0-9]{1,6})(?![0-9])")
_GOT_WORD = re.compile(r"(?<![A-Za-z0-9_])got ([0-9]{1,6})(?![0-9])")
_CANONICAL_INT = re.compile(r"^(?:0|[1-9][0-9]*)$")
_LINE_BREAK = re.compile(r"\r\n|\r|\n")
#: A `key=value` token ends at whitespace, `;`, `|` or a backtick — the
#: separators the harness and `day2_booking_busy` actually use around tokens.
_CODE_TOKEN = re.compile(r"(?<![A-Za-z0-9_])code=([^\s;|`]{0,128})")
_HTTP_TOKEN = re.compile(r"(?<![A-Za-z0-9_])http=([^\s;|`]{0,128})")
_TIMING = re.compile(r"^INFO day2-journey-timing journey=(\S{1,64}) elapsed_s=([0-9]{1,12})$")


def _load_log(path):
    """Return the bounded log text, or None when it cannot be read.

    Only a regular file is opened. A FIFO, socket or device at the log path would
    make `open()`/`read()` block indefinitely (the runner writes the log with
    `tee`, so a FIFO is never the real producer); such a path is treated exactly
    like a missing log and projects to `unrecognised` without stalling the step.
    """
    try:
        if not stat.S_ISREG(os.stat(path).st_mode):
            return None
        with open(path, "rb") as handle:
            raw = handle.read(MAX_LOG_BYTES + 1)
    except (OSError, ValueError):
        return None
    return raw[:MAX_LOG_BYTES].decode("utf-8", errors="replace")


def _lines(text):
    """Split on LF, CRLF and lone CR, bounded by MAX_LINES."""
    for index, line in enumerate(_LINE_BREAK.split(text)):
        if index >= MAX_LINES:
            return
        yield line


def _value_of(text, key):
    """The token after `key=`, or None when absent.

    The lookbehind keeps `sql_errno=` from matching `errno=`. The value ends at
    whitespace, `;`, `|` or a backtick: `describe()` joins observations with
    `"; "` and the hold diagnostics use `|`, so a token glued to a separator
    (`code=CLINIC_BOOKING_BUSY;`) must still be read as the bare value.
    """
    match = re.search(_TOKEN_VALUE.format(key=re.escape(key)), text)
    return match.group(1) if match else None


def int_token(raw, minimum, maximum):
    """Canonical bounded decimal, or `unrecognised`.

    ``None`` (absent), non-digits, leading zeros and out-of-range values are not
    validated integers and collapse to the fixed token.
    """
    if not isinstance(raw, str) or not _CANONICAL_INT.match(raw):
        return UNRECOGNISED
    if len(raw) > len(str(maximum)):
        return UNRECOGNISED
    value = int(raw)
    if value < minimum or value > maximum:
        return UNRECOGNISED
    return str(value)


def enum_token(raw, vocabulary):
    """`none` when absent, the vocabulary member when it matches, else `unrecognised`."""
    if raw is None:
        return NONE
    return raw if raw in vocabulary else UNRECOGNISED


def errno_class_for(errno):
    """Map the projected errno token to the closed class vocabulary.

    A zero errno is `unknown`, not `other`: `(int) $dbh->errno` is `0` when no
    driver error is pending (and on a non-mysqli handle), so it carries no
    cause class. Everything else that is a validated non-zero integer is
    `other`.
    """
    if errno == str(ERRNO_DEADLOCK):
        return "deadlock"
    if errno == str(ERRNO_LOCK_WAIT_TIMEOUT):
        return "lock_wait_timeout"
    if errno == UNRECOGNISED or errno == "0":
        return "unknown"
    return "other"


def busy_token_for(text):
    """`busy` field: which `busy_diag=` state the harness printed, if any.

    Literal-to-literal through :data:`BUSY_STATES`; any other value (including a
    hostile one) is `absent`, and a token-free failure text is `absent` too.
    """
    return BUSY_STATES.get(_value_of(text, "busy_diag"), "absent")


#: Harness status prefixes that always start a NEW top-level line (`ok`, `fail`,
#: `info` in bin/pilot-day2-workflows.py). A continuation line never starts with
#: one of these, because `fail()` prefixes only the first line of the text it
#: prints.
_TOP_LEVEL_PREFIXES = ("FAIL ", "PASS ", "INFO ", "WARN ", "NOT_RUN ")


def _failure_block(text):
    """The first `FAIL ` line plus its continuation lines, bounded.

    `fail()` prints `FAIL <key> — <title> — <exception text>` once, but the
    exception text may itself contain newlines (a Playwright timeout message
    carries a `Call log:`, and the second raise site in journey A1 appends its
    `busy_diag=` suffix to text that may already end with one). Continuation
    lines are every immediately following non-blank line that does not start a
    new harness record; a blank line, another `FAIL `/`PASS `/`INFO ` line, the
    :data:`MAX_LINES` bound or the :data:`MAX_BLOCK_CHARS` bound ends the block.
    """
    block, size, started = [], 0, False
    for line in _lines(text):
        clipped = line[:MAX_LINE_CHARS]
        if not started:
            if line.startswith("FAIL "):
                block.append(clipped)
                size += len(clipped)
                started = True
            continue
        if not line.strip() or line.startswith(_TOP_LEVEL_PREFIXES):
            break
        block.append(clipped)
        size += len(clipped)
        if size > MAX_BLOCK_CHARS:
            break
    return "\n".join(block) if block else None


def _unobserved(journey):
    """Every field in its unobserved state (PASS run, or no readable log)."""
    return {
        "journey": journey,
        "stage": NONE,
        "http": UNRECOGNISED,
        "code": NONE,
        "read": NONE,
        "errno": UNRECOGNISED,
        "errno_class": "unknown",
        "busy": "absent",
        "elapsed_s": UNRECOGNISED,
    }


def _elapsed_for(text, journey):
    """This journey's harness timing line, if the log carries one."""
    if journey not in JOURNEYS:
        return UNRECOGNISED
    for line in _lines(text):
        match = _TIMING.match(line[:MAX_LINE_CHARS])
        if match and match.group(1) == journey:
            return int_token(match.group(2), *INTS["elapsed_s"])
    return UNRECOGNISED


def project(text):
    """Project log text to the nine closed-vocabulary fields."""
    if text is None:
        # The log was missing or unreadable: nothing was observed at all.
        return _unobserved(UNRECOGNISED)

    failure = _failure_block(text)

    if failure is None:
        # The step produced a log but no journey failed. Report the journey the
        # log last timed, if any: `journey=none` then means "no failure at all".
        fields = _unobserved(NONE)
        timed = [
            match.group(1)
            for match in (
                _TIMING.match(line[:MAX_LINE_CHARS]) for line in _lines(text)
            )
            if match and match.group(1) in JOURNEYS
        ]
        if timed:
            fields["journey"] = timed[-1]
            fields["elapsed_s"] = _elapsed_for(text, timed[-1])
        return fields

    key_match = _LEADING_FAIL.match(failure)
    key = key_match.group(1) if key_match else ""
    journey = key if key in JOURNEYS else UNRECOGNISED

    stage = enum_token(_value_of(failure, "stage"), STAGES)

    # `code` precedence: the busy code is the only one this diagnostic exists
    # for, so it wins wherever it appears in the block; any other printed code is
    # `other`; no code at all is `none`. A value is bounded by the separators the
    # harness really uses, so `code=CLINIC_BOOKING_BUSY;` (a `;` from
    # `describe()`) and `code=CLINIC_BOOKING_BUSY |` (a `|` from
    # `visible_or_diagnose`) both read as the bare code.
    code_spans = [
        (match.start(), match.group(1))
        for match in re.finditer(_CODE_TOKEN, failure)
    ]
    busy_at = -1
    for start, value in reversed(code_spans):
        if value == BUSY_CODE:
            busy_at = start
            break
    if busy_at >= 0:
        code = BUSY_CODE
    elif code_spans:
        code = "other"
    else:
        code = NONE

    # `http` precedence: the status that belongs to this failure. When the busy
    # code is present, that is the nearest `http=` before it (the probe prints
    # `... http=<status> code=<code>` per observation); otherwise the first
    # printed status. Both forms are documented above.
    http_spans = [
        (match.start(), match.group(1))
        for match in re.finditer(_HTTP_TOKEN, failure)
    ]
    if busy_at >= 0:
        before = [value for start, value in http_spans if start < busy_at]
        http_raw = before[-1] if before else None
    elif http_spans:
        # `describe()` prints the REST calls in order, oldest first. The status
        # that belongs to the failure is the last NON-2xx one: taking the first
        # `http=` would report the successful quote (`http=200`) for a hold that
        # actually failed with `http=409`.
        failing = [
            value for _, value in http_spans
            if int_token(value, *INTS["http"]) != UNRECOGNISED
            and not 200 <= int(value) <= 299
        ]
        http_raw = failing[-1] if failing else http_spans[-1][1]
    else:
        http_raw = None
    if http_raw is None:
        # Word forms the harness also prints, all without `key=`: `HTTP 409`
        # ("booking/confirm HTTP {status}"), `got 400`, `got 503`.
        word = _HTTP_WORD.search(failure) or _GOT_WORD.search(failure)
        http_raw = word.group(1) if word else None
    http = int_token(http_raw, *INTS["http"])

    read = enum_token(_value_of(failure, "read"), READS)
    errno = int_token(_value_of(failure, "sql_errno"), *INTS["errno"])

    return {
        "journey": journey,
        "stage": stage,
        "http": http,
        "code": code,
        "read": read,
        "errno": errno,
        "errno_class": errno_class_for(errno),
        "busy": busy_token_for(failure),
        "elapsed_s": _elapsed_for(text, journey),
    }


def is_closed(fields):
    """True only when every field is a member of its own closed vocabulary."""
    if not isinstance(fields, dict) or set(fields) != set(FIELDS):
        return False
    for name, vocabulary in ENUMS.items():
        if fields[name] not in vocabulary:
            return False
    for name, (minimum, maximum) in INTS.items():
        token = fields[name]
        if token != UNRECOGNISED and int_token(token, minimum, maximum) != token:
            return False
    return True


def render(fields):
    """ONE fixed-format line; a non-closed mapping degrades to the fallback line."""
    if not is_closed(fields):
        fields = _unobserved(UNRECOGNISED)
    return " ".join(f"{name}={fields[name]}" for name in FIELDS)


_SELFTEST_CASES = (
    (
        "PASS day2-A1-otp-identity-confirm — ok\n",
        {"journey": NONE, "stage": NONE, "http": UNRECOGNISED, "code": NONE,
         "read": NONE, "errno": UNRECOGNISED, "errno_class": "unknown",
         "busy": "absent", "elapsed_s": UNRECOGNISED},
    ),
    (
        "INFO day2-journey-timing journey=journey-A1-public elapsed_s=51\n"
        "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
        "public booking stage=hold-countdown root_state=bookable visible=0 "
        "observed=hold/hold http=503 code=CLINIC_BOOKING_BUSY | journey=A1-public "
        "busy_diag=booking.lock_read_failed read=slot sql_errno=1213 "
        'sql_error="Deadlock found when trying to get lock"\n',
        {"journey": "journey-A1-public", "stage": "hold-countdown", "http": "503",
         "code": BUSY_CODE, "read": "slot", "errno": "1213",
         "errno_class": "deadlock", "busy": "present", "elapsed_s": "51"},
    ),
    (
        "FAIL journey-B-reception — Day-2 browser journey failed "
        "(journey-B-reception) — reception board load failed\n",
        {"journey": "journey-B-reception", "stage": NONE, "http": UNRECOGNISED,
         "code": NONE, "read": NONE, "errno": UNRECOGNISED,
         "errno_class": "unknown", "busy": "absent", "elapsed_s": UNRECOGNISED},
    ),
)


def run_selftest():
    for index, (text, expected) in enumerate(_SELFTEST_CASES, 1):
        got = project(text)
        if got != expected:
            print(f"FAIL day2-failure-report-selftest case={index} got={render(got)}")
            return 1
        if not is_closed(got):
            print(f"FAIL day2-failure-report-selftest case={index} not-closed")
            return 1
    print("PASS day2-failure-report-selftest — closed vocabulary, errno classes, busy states, unobserved fields")
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--log", default=DEFAULT_LOG,
                        help="runner-local Day-2 browser log")
    parser.add_argument("--field", choices=FIELDS,
                        help="print one field token only")
    parser.add_argument("--test", action="store_true",
                        help="run the offline self-tests")
    args = parser.parse_args(argv)

    if args.test:
        return run_selftest()

    try:
        fields = project(_load_log(args.log))
        output = fields[args.field] if args.field else render(fields)
    except Exception:  # noqa: BLE001 - the projector must never leak a traceback
        fallback = _unobserved(UNRECOGNISED)
        output = fallback[args.field] if args.field else render(fallback)
    sys.stdout.write(output + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

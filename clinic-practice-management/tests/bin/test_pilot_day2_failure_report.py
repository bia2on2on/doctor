"""Offline, non-vacuous tests for the Day-2 closed-vocabulary failure projector.

The helper under test (bin/pilot-day2-failure-report.py) turns the runner-local
Day-2 browser log into ONE line whose every value is a frozen token or a
canonical bounded integer. These tests pin that policy in three layers:

1. every field of a well-formed harness failure is projected correctly, and the
   vocabulary is *derived from* the harness/BookingService sources rather than
   restated by hand (so drift fails closed);
2. hostile log input — workflow commands, `##[`, raw SQL, an e-mail, a URL, a
   path, ANSI escapes, CR/LF, a very long line, a 40-digit number, a
   leading-zero number — collapses to a fixed token and never survives;
3. the CLI is total: it exits 0 and prints a closed line for a missing log, an
   unreadable log, and a log made only of hostile bytes.

They are deliberately not tautologies: each test would fail if the projector
started echoing log text, if it stopped validating tokens, or if its vocabulary
drifted from what the harness/BookingService really print.
"""
import ast
import importlib.util
import io
import os
import re
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

BIN_DIR = Path(__file__).resolve().parents[2] / "bin"
CPMS_ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(BIN_DIR))

import importlib  # noqa: E402

# The projector's filename is hyphenated (it is a CLI tool); load it by path.
_spec = importlib.util.spec_from_file_location(
    "pilot_day2_failure_report", BIN_DIR / "pilot-day2-failure-report.py"
)
projector = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(projector)

HARNESS = BIN_DIR / "pilot-day2-workflows.py"
BOOKING_SERVICE = (
    CPMS_ROOT
    / "clinic-practice-management"
    / "src"
    / "Application"
    / "Booking"
    / "BookingService.php"
)

CANARY_EMAIL = "patient.canary@example.com"
CANARY_URL = "https://example.invalid/wp-admin/admin-ajax.php?token=SECRET"
CANARY_PATH = "/home/runner/www/wp-content/plugins/clinic-practice-management/x.php"
CANARY_SQL = "SELECT patient_id FROM cpmswp_cpms_slot_holds WHERE mobile = 09128887701"
CANARY_TOKEN = "Bearer SECRET-TOKEN-VALUE"
ANSI = "\x1b[31m"
LONG_LINE = "A" * 200000


def failure_line(extra=""):
    return (
        "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
        "public booking stage=hold-countdown root_state=bookable visible=0 "
        "observed=hold/hold http=503 code=CLINIC_BOOKING_BUSY | journey=A1-public "
        "busy_diag=booking.lock_read_failed read=slot sql_errno=1213 "
        'sql_error="Deadlock found when trying to get lock" ' + extra
    )



def _run_subprocess(case, argv, timeout=None):
    """subprocess.run with fail-closed error handling.

    A timeout or OS error becomes a FIXED-message test failure: the
    exceptions caught here embed the command line in their message
    (TimeoutExpired above all), and one call site passes an inline
    driver whose source contains a synthetic secret marker, so the
    exception itself must never reach unittest output.
    """
    try:
        return subprocess.run(
            argv, capture_output=True, text=True, errors="replace",
            check=False, timeout=timeout,
        )
    except (subprocess.TimeoutExpired, OSError, UnicodeError):
        # `from None`: the caught exception's message embeds the
        # command line, and a chained traceback would print it.
        raise case.failureException(
            "subprocess driver did not complete (details withheld)"
        ) from None


class _RedactedAssertions:
    """Mixin: assertion FAILURE TEXT is structurally redacted.

    unittest builds the standard failure message for assertEqual /
    assertIn / assertNotIn / assertRegex / assertIs / assertTrue and
    friends (repr of both operands) and passes it to the private
    method TestCase._formatMessage(msg, standardMsg) — private, i.e.
    not part of the public unittest API
    (https://docs.python.org/3/library/unittest.html); this override
    is written against CPython 3.x behaviour and is itself covered by
    AssertionOutputRedactionTest below / in the failure-report suite.
    It returns the explicit msg when one is given (callers pass fixed
    strings only) and a fixed placeholder otherwise.

    QUALIFIED SCOPE (rounds 14-15): this covers only assertion
    methods that route their failure message through _formatMessage.
    On the CPython 3.11 unittest/case.py read for this change, the
    methods used by these suites — assertTrue, assertFalse,
    assertEqual, assertNotEqual, assertIn, assertNotIn, assertRegex,
    assertNotRegex, assertLess, assertLessEqual, assertGreater,
    assertGreaterEqual, assertRaises — all build their message via
    _formatMessage (case.py lines: assertTrue/False ~705-715,
    _baseAssertEqual ~861-866, assertIn/NotIn/Is/IsNot ~1133-1160,
    assertLess/LessEqual/Greater/GreaterEqual/IsNone ~1255-1290,
    assertRegex/NotRegex ~1339-1365; assertRaises context failures
    go via this class's fail()). assertEqual's stock TYPE DISPATCH
    is overridden below (_getAssertEqualityFunc) because the
    type-specific helpers it selects can bypass _formatMessage
    (assertSetEqual ~1108-1117 fails with exception text;
    assertSequenceEqual ~981-984 raises failureException directly
    with the operand's repr on a type mismatch). Calling those
    helpers DIRECTLY remains forbidden; an AST test
    (AssertionOutputRedactionTest) enforces the allowlist. This is
    defence in depth ON TOP of the internal-comparison assertions,
    not a replacement for them.
    """

    def _formatMessage(self, msg, standardMsg):
        return msg if msg is not None else "assertion failed (details withheld)"

    def fail(self, msg=None):
        raise self.failureException(
            msg if msg is not None else "assertion failed (details withheld)"
        )

    def _getAssertEqualityFunc(self, first, second):
        # Private CPython API (unittest/case.py in CPython 3.11, read
        # for this change; only the public surface is documented at
        # https://docs.python.org/3/library/unittest.html): the stock
        # dispatch routes set/frozenset/dict/list/tuple/str operands
        # to type-specific helpers whose failure paths can embed
        # exception or operand text WITHOUT _formatMessage — e.g.
        # assertSetEqual calls fail('invalid type when attempting
        # set difference: %s' % e) when a set difference raises
        # TypeError. Always returning _baseAssertEqual (verified in
        # case.py: its only message is built through _formatMessage)
        # removes that path. Equality semantics are unchanged (the
        # same == comparison); only the failure TEXT differs.
        return self._baseAssertEqual


class VocabularyDerivationTest(_RedactedAssertions, unittest.TestCase):
    """The projector's vocabularies must be the ones the real code prints."""

    def test_journeys_are_exactly_the_harness_main_loop_ids(self):
        source = HARNESS.read_text(encoding="utf-8")
        printed = re.findall(r'\("(journey-[A-Za-z0-9-]+)", lambda: run_journey_', source)
        self.assertTrue(sorted(printed) == sorted(projector.JOURNEYS), "derived value mismatch (content withheld)")
        self.assertTrue(len(printed) == 5, "derived value mismatch (content withheld)")

    def test_stages_are_exactly_the_harness_visible_or_diagnose_markers(self):
        source = HARNESS.read_text(encoding="utf-8")
        markers = set(
            re.findall(r"visible_or_diagnose\(\s*[^,]+,\s*[^,]+,\s*\"([a-z0-9-]+)\"", source)
        )
        markers |= set(re.findall(r'public booking stage=([a-z0-9-]+)', source))
        self.assertTrue(len(markers) == 7, "derived value mismatch (content withheld)")
        self.assertTrue("hold-request" in markers, "derived output missing expected fragment (content withheld)")
        self.assertTrue(sorted(markers) == sorted(projector.STAGES), "derived value mismatch (content withheld)")

    def test_reads_are_exactly_the_bookingservice_assertion_labels(self):
        source = BOOKING_SERVICE.read_text(encoding="utf-8")
        labels = set(
            re.findall(r"assert_read_succeeded\(\s*'([a-z0-9_]+)'", source)
        )
        self.assertTrue(sorted(labels) == sorted(projector.READS), "derived value mismatch (content withheld)")
        self.assertTrue(len(labels) == 16, "derived value mismatch (content withheld)")

    def test_errno_classes_are_the_documented_closed_set(self):
        self.assertTrue(projector.ERRNO_CLASSES == ("deadlock", "lock_wait_timeout", "other", "unknown"), "derived value mismatch (content withheld)")
        self.assertTrue(projector.errno_class_for("1213") == "deadlock", "derived value mismatch (content withheld)")
        self.assertTrue(projector.errno_class_for("1205") == "lock_wait_timeout", "derived value mismatch (content withheld)")
        self.assertTrue(projector.errno_class_for("1062") == "other", "derived value mismatch (content withheld)")
        # `(int) $dbh->errno` is 0 when no driver error is pending (and on a
        # non-mysqli handle), so a zero errno is "no cause class", not `other`.
        self.assertTrue(projector.errno_class_for("0") == "unknown", "derived value mismatch (content withheld)")
        self.assertTrue(projector.errno_class_for(projector.UNRECOGNISED) == "unknown", "derived value mismatch (content withheld)")


class ProjectionTableTest(_RedactedAssertions, unittest.TestCase):
    """A table of realistic and hostile inputs, each with its expected line."""

    def project(self, text):
        return projector.render(projector.project(text))

    def test_real_busy_deadlock_failure(self):
        text = "INFO day2-journey-timing journey=journey-A1-public elapsed_s=51\n" + failure_line()
        self.assertTrue(self.project(text) == "journey=journey-A1-public stage=hold-countdown http=503 "
            "code=CLINIC_BOOKING_BUSY read=slot errno=1213 errno_class=deadlock "
            "busy=present elapsed_s=51", "derived value mismatch (content withheld)")

    def test_lock_wait_timeout_is_its_own_class(self):
        line = failure_line().replace("sql_errno=1213", "sql_errno=1205")
        fields = projector.project(line)
        self.assertTrue(fields["errno"] == "1205", "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno_class"] == "lock_wait_timeout", "derived value mismatch (content withheld)")

    def test_other_errno_is_other_and_still_digits_only(self):
        line = failure_line().replace("sql_errno=1213", "sql_errno=1062")
        fields = projector.project(line)
        self.assertTrue((fields["errno"], fields["errno_class"]) == ("1062", "other"), "derived value mismatch (content withheld)")

    def test_absent_errno_is_unknown_not_zero(self):
        line = (
            "FAIL journey-B-reception — Day-2 browser journey failed "
            "(journey-B-reception) — reception board load failed"
        )
        fields = projector.project(line)
        self.assertTrue((fields["errno"], fields["errno_class"]) == ("unrecognised", "unknown"), "derived value mismatch (content withheld)")
        self.assertTrue(fields["stage"] == "none", "derived value mismatch (content withheld)")
        self.assertTrue(fields["code"] == "none", "derived value mismatch (content withheld)")
        self.assertTrue(fields["http"] == "unrecognised", "derived value mismatch (content withheld)")

    def test_busy_http_followed_by_hold_http_reports_the_first(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-request — hold rejected for the fixture slot "
            "slot=42 http=503 code=CLINIC_BOOKING_BUSY | journey=A1-public "
            "busy_diag=booking.lock_read_failed read=persisted_slot sql_errno=1213"
        )
        fields = projector.project(line)
        self.assertTrue((fields["stage"], fields["http"]) == ("hold-request", "503"), "derived value mismatch (content withheld)")
        self.assertTrue(fields["read"] == "persisted_slot", "derived value mismatch (content withheld)")

    def test_http_word_form_is_accepted(self):
        line = (
            "FAIL journey-A2-reschedule — Day-2 browser journey failed "
            "(journey-A2-reschedule) — booking/confirm HTTP 409 then retry"
        )
        self.assertTrue(projector.project(line)["http"] == "409", "derived value mismatch (content withheld)")

    def test_pass_run_reports_no_journey_but_keeps_the_timing(self):
        """H3: a journey is named only by a FAIL line, never by a timing line.

        Every completed journey prints a timing line, so naming the last timed
        journey would assert that it failed when the log records no failure for
        any journey. `journey=none` is the honest token; `elapsed_s` is still
        reported because the log really does state it.
        """
        text = (
            "PASS day2-A1-otp-identity-confirm — ok\n"
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=12\n"
        )
        self.assertTrue(self.project(text) == "journey=none stage=none http=unrecognised code=none "
            "read=none errno=unrecognised errno_class=unknown busy=absent "
            "elapsed_s=12", "derived value mismatch (content withheld)")

    def test_failing_run_without_a_fail_line_does_not_name_a_journey(self):
        """H3: the dangerous case — a nonzero exit with no FAIL line at all."""
        text = (
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=12\n"
            "INFO day2-journey-timing journey=journey-B-reception elapsed_s=31\n"
            "Traceback (most recent call last):\nSystemExit: 1\n"
        )
        fields = projector.project(text)
        self.assertTrue(fields["journey"] == "none", "derived value mismatch (content withheld)")
        # The last timing line is still the source of elapsed_s.
        self.assertTrue(fields["elapsed_s"] == "31", "derived value mismatch (content withheld)")

    def test_log_with_no_timing_and_no_failure_is_none_journey(self):
        self.assertTrue(self.project("python3: start\n") == "journey=none stage=none http=unrecognised code=none read=none "
            "errno=unrecognised errno_class=unknown busy=absent "
            "elapsed_s=unrecognised", "derived value mismatch (content withheld)")

    def test_http_is_the_status_paired_with_the_busy_code(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown root_state=bookable visible=0 "
            "observed=hold/quote http=403 code=rest_cookie_invalid_nonce; "
            "hold/hold http=503 code=CLINIC_BOOKING_BUSY | journey=A1-public "
            "busy_diag=booking.lock_read_failed read=slot sql_errno=1205"
        )
        fields = projector.project(line)
        self.assertTrue(fields["http"] == "503", "derived value mismatch (content withheld)")
        self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno_class"] == "lock_wait_timeout", "derived value mismatch (content withheld)")

    def test_non_busy_code_is_other_and_keeps_its_own_status(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=otp-code observed=otp/verify http=429 "
            "code=rest_too_many_requests"
        )
        fields = projector.project(line)
        self.assertTrue((fields["http"], fields["code"]) == ("429", "other"), "derived value mismatch (content withheld)")

    def test_busy_code_elsewhere_in_the_line_still_wins(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "observed=hold/hold http=500 code=internal_error; http=503 "
            "code=CLINIC_BOOKING_BUSY"
        )
        fields = projector.project(line)
        self.assertTrue(fields["http"] == "503", "derived value mismatch (content withheld)")
        self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")

    def test_missing_log_is_unrecognised_not_none(self):
        self.assertTrue(self.project(None) == "journey=unrecognised stage=none http=unrecognised code=none read=none "
            "errno=unrecognised errno_class=unknown busy=absent "
            "elapsed_s=unrecognised", "derived value mismatch (content withheld)")

    def test_foreign_fail_key_is_unrecognised(self):
        line = "FAIL day2-A1-otp-identity-confirm — something else entirely"
        fields = projector.project(line)
        self.assertTrue(fields["journey"] == projector.UNRECOGNISED, "derived value mismatch (content withheld)")

    def test_first_fail_line_wins(self):
        text = (
            "FAIL journey-C-doctors — Day-2 browser journey failed (journey-C-doctors) — b\n"
            "FAIL journey-D-accountant — Day-2 browser journey failed (journey-D-accountant) — c\n"
        )
        self.assertTrue(projector.project(text)["journey"] == "journey-C-doctors", "derived value mismatch (content withheld)")


class HostileInputTest(_RedactedAssertions, unittest.TestCase):
    """Hostile content must vanish, not be sanitised into something similar."""

    HOSTILE = {
        "workflow error command": "::error::leaked secret " + CANARY_TOKEN,
        "warning with params": "::warning file=/etc/passwd,line=1::boom",
        "hash bracket": "##[error]" + ANSI + CANARY_TOKEN,
        "vso bracket": "##vso[task.setvariable variable=x]" + CANARY_TOKEN,
        "newline injection": "line1\njourney=admin http=200 code=CLINIC_BOOKING_BUSY",
        "cr injection": "line1\rjourney=admin",
        "crlf injection": "line1\r\njourney=admin",
        "ansi escape": ANSI + "31m" + CANARY_TOKEN,
        "sql text": CANARY_SQL,
        "email": CANARY_EMAIL,
        "url": CANARY_URL,
        "absolute path": CANARY_PATH,
        "leading zero number": "007",
        "forty digit number": "1" * 40,
        "negative number": "-1213",
        "float": "1213.9",
        "hex": "0x4BD",
        "unicode digits": "\u0661\u0662\u0661\u0663",
        "very long line": LONG_LINE,
        "token attempt": CANARY_TOKEN,
    }

    def render_text(self, text):
        return projector.render(projector.project(text))

    def assert_closed(self, rendered):
        fields = dict(part.split("=", 1) for part in rendered.split(" "))
        self.assertTrue(tuple(fields) == projector.FIELDS,
                        "projection field set mismatch (content withheld)")
        self.assertTrue(projector.is_closed(fields), "projection is not closed-vocabulary (content withheld)")
        # Canonical integers only: no padding, no sign, no separator.
        for name in ("http", "errno", "elapsed_s"):
            self.assertTrue(re.search(r"^0[0-9]", fields[name]) is None,
                            "projection integer is not canonical (content withheld)")

    def test_hostile_payload_in_extra_position_never_survives(self):
        for label, payload in self.HOSTILE.items():
            with self.subTest(label="case"):
                text = failure_line(payload)
                rendered = self.render_text(text)
                self.assert_closed(rendered)
                for canary in (
                    CANARY_TOKEN, CANARY_EMAIL, CANARY_URL, CANARY_PATH,
                    "SELECT", "09128887701", "example.invalid", "wp-content",
                    "admin", "::", "##[", ANSI, "\x1b",
                ):
                    self.assertTrue(canary not in rendered, "projected output carried forbidden text (content withheld)")
                self.assertTrue("\n" not in rendered, "projected output carried forbidden text (content withheld)")
                self.assertTrue("\r" not in rendered, "projected output carried forbidden text (content withheld)")

    def test_hostile_field_values_collapse_to_fixed_tokens(self):
        cases = {
            "stage": "stage=" + CANARY_TOKEN,
            "http": "http=" + CANARY_TOKEN,
            "code": "code=" + CANARY_EMAIL,
            "read": "read=" + CANARY_SQL.split()[0],
            "sql_errno": "sql_errno=" + "1" * 40,
        }
        # `sql_errno=` is projected into the output field named `errno`.
        output_field = {"sql_errno": "errno"}
        for field, injected in cases.items():
            with self.subTest(field="case"):
                text = failure_line().replace(
                    {"stage": "stage=hold-countdown", "http": "http=503",
                     "code": "code=CLINIC_BOOKING_BUSY", "read": "read=slot",
                     "sql_errno": "sql_errno=1213"}[field],
                    injected,
                    1,
                )
                fields = projector.project(text)
                self.assertTrue(projector.is_closed(fields))
                self.assertTrue(fields[output_field.get(field, field)] in (projector.UNRECOGNISED, "other", "unknown"), "derived output missing expected fragment (content withheld)")
                rendered = projector.render(fields)
                self.assertTrue(CANARY_TOKEN not in rendered, "projected output carried forbidden text (content withheld)")
                self.assertTrue(CANARY_EMAIL not in rendered, "projected output carried forbidden text (content withheld)")

    def test_leading_zero_and_long_numbers_are_not_integers(self):
        for raw in ("007", "000000", "099999", "1" * 40, "-1", "+1", "1e3", " 1213", "1213 "):
            with self.subTest(raw="case"):
                self.assertTrue(projector.int_token(raw, 0, 99999) == projector.UNRECOGNISED, "derived value mismatch (content withheld)")
        self.assertTrue(projector.int_token("1213", 0, 99999) == "1213", "derived value mismatch (content withheld)")

    def test_integer_bounds_are_enforced(self):
        self.assertTrue(projector.int_token("99", 100, 599) == projector.UNRECOGNISED, "derived value mismatch (content withheld)")
        self.assertTrue(projector.int_token("600", 100, 599) == projector.UNRECOGNISED, "derived value mismatch (content withheld)")
        self.assertTrue(projector.int_token("100", 100, 599) == "100", "derived value mismatch (content withheld)")
        self.assertTrue(projector.int_token("599", 100, 599) == "599", "derived value mismatch (content withheld)")
        self.assertTrue(projector.int_token("999999", 0, 99999) == projector.UNRECOGNISED, "derived value mismatch (content withheld)")

    def test_all_hostile_log_is_still_one_closed_line(self):
        text = "\n".join(
            ["FAIL " + CANARY_TOKEN + " " + payload for payload in self.HOSTILE.values()]
            + [payload for payload in self.HOSTILE.values()]
        )
        rendered = self.render_text(text)
        self.assert_closed(rendered)
        self.assertTrue(len(rendered.splitlines()) == 1, "derived value mismatch (content withheld)")
        self.assertTrue(rendered.split(" ")[0] == "journey=" + projector.UNRECOGNISED, "derived value mismatch (content withheld)")

    def test_render_rejects_a_hand_built_dirty_mapping(self):
        dirty = projector.project(failure_line())
        dirty["stage"] = CANARY_TOKEN
        rendered = projector.render(dirty)
        self.assertTrue(CANARY_TOKEN not in rendered, "projected output carried forbidden text (content withheld)")
        self.assertTrue("journey=unrecognised" in rendered, "projected output missing expected fragment (content withheld)")

    def test_is_closed_rejects_extra_missing_and_wrong_types(self):
        good = projector.project(failure_line())
        self.assertTrue(projector.is_closed(good))
        for mutation in (
            dict(good, extra="x"),
            {key: value for key, value in good.items() if key != "errno"},
            dict(good, http="0503"),
            dict(good, http=503),
            dict(good, errno_class="busy"),
            dict(good, journey=CANARY_EMAIL),
            dict(good, code="SOME_OTHER_CODE"),
        ):
            with self.subTest(mutation="case"):
                self.assertFalse(projector.is_closed(mutation))


class CommandLineTest(_RedactedAssertions, unittest.TestCase):
    """The CLI must be total and must never leak a traceback or raw text."""

    SCRIPT = BIN_DIR / "pilot-day2-failure-report.py"

    def run_script(self, *args):
        return _run_subprocess(self, [sys.executable, str(self.SCRIPT), *args])

    def test_missing_log_exits_zero_with_one_closed_line(self):
        result = self.run_script("--log", "/nonexistent/definitely-absent.log")
        self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
        self.assertTrue(result.stdout.count("\n") == 1, "derived value mismatch (content withheld)")
        self.assertTrue(result.stdout.startswith("journey=unrecognised "))
        self.assertTrue(result.stderr == "", "subprocess stderr must be empty (content withheld)")

    def test_directory_as_log_is_unreadable_but_total(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = self.run_script("--log", tmp)
        self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
        self.assertTrue(result.stdout.startswith("journey=unrecognised "))

    def test_hostile_log_file_prints_only_closed_tokens(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "day2.log"
            path.write_bytes(
                (
                    failure_line()
                    + "\n::error::"
                    + CANARY_TOKEN
                    + "\n"
                    + ANSI
                    + CANARY_SQL
                    + "\n"
                    + CANARY_EMAIL
                    + "\n"
                    + CANARY_URL
                    + "\n##[error]"
                    + CANARY_PATH
                    + "\n"
                ).encode("utf-8")
            )
            result = self.run_script("--log", str(path))
        self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
        for canary in (CANARY_TOKEN, CANARY_EMAIL, CANARY_URL, CANARY_PATH, "SELECT", "::"):
            self.assertTrue(canary not in result.stdout, "captured output carried forbidden text (content withheld)")
        self.assertTrue("journey=journey-A1-public stage=hold-countdown http=503" in result.stdout, "captured output missing expected fragment (content withheld)")

    def test_invalid_utf8_and_nul_bytes_are_tolerated(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "day2.log"
            path.write_bytes(b"\xff\xfe\x00FAIL journey-A1-public \x00\xff" + failure_line().encode())
            result = self.run_script("--log", str(path))
        self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
        self.assertTrue(result.stdout.count("\n") == 1, "derived value mismatch (content withheld)")

    def test_field_mode_prints_exactly_one_token(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "day2.log"
            path.write_text(failure_line(), encoding="utf-8")
            for field in projector.FIELDS:
                with self.subTest(field="case"):
                    result = self.run_script("--log", str(path), "--field", field)
                    self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
                    token = result.stdout.rstrip("\n")
                    self.assertTrue(" " not in token, "derived output carried forbidden text (content withheld)")
                    self.assertTrue("=" not in token, "derived output carried forbidden text (content withheld)")
                    self.assertLessEqual(len(token), 64)

    def test_selftest_passes_and_is_not_a_placeholder(self):
        result = self.run_script("--test")
        self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
        self.assertTrue("PASS day2-failure-report-selftest" in result.stdout, "captured output missing expected fragment (content withheld)")

    def test_selftest_detects_a_neutralised_errno_mapping(self):
        original = projector.errno_class_for
        try:
            projector.errno_class_for = lambda errno: "unknown"
            buffer = io.StringIO()
            with redirect_stdout(buffer):
                code = projector.run_selftest()
        finally:
            projector.errno_class_for = original
        self.assertTrue(code == 1, "derived value mismatch (content withheld)")
        self.assertTrue("FAIL day2-failure-report-selftest" in buffer.getvalue(), "captured output missing expected fragment (content withheld)")

    def test_usage_error_is_a_nonzero_exit_but_prints_nothing_raw(self):
        result = self.run_script("--field", "not-a-field")
        self.assertTrue(result.returncode != 0, "derived values unexpectedly equal (content withheld)")
        self.assertTrue(CANARY_TOKEN not in result.stdout + result.stderr, "captured output carried forbidden text (content withheld)")


class RealHarnessFormatTest(_RedactedAssertions, unittest.TestCase):
    """Fidelity against the formats the harness ACTUALLY prints.

    Every sample here is produced by calling the real harness / `day2_booking_busy`
    code (imported, not restated) or by a literal copied from the line that prints
    it, so a test cannot drift into "a format we invented".
    """

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness", HARNESS)
        harness = importlib.util.module_from_spec(spec)
        sys.path.insert(0, str(BIN_DIR))
        spec.loader.exec_module(harness)
        cls.harness = harness
        import day2_booking_busy
        cls.busy = day2_booking_busy

    def describe(self, entries):
        probe = self.harness.BookingProbe()
        probe.entries = entries
        return probe.describe(0, limit=12)

    def test_non_busy_hold_failure_reports_the_failing_status(self):
        """(a) A hold that fails with a non-busy code must report ITS status.

        `describe()` joins observations with `"; "`, so an older `http=200`
        (the quote) precedes the failing `http=409`. Taking the first `http=`
        would report 200 for a 409 failure.
        """
        observed = self.describe([
            {"route": "/wp-json/clinic/v1/booking/quote", "status": 200, "code": ""},
            {"route": "/wp-json/clinic/v1/booking/hold", "status": 409,
             "code": "rest_slot_unavailable"},
            {"route": "/wp-json/clinic/v1/booking/hold", "status": 200, "code": ""},
        ])
        self.assertTrue(observed == "booking/quote http=200; booking/hold http=409 "
            "code=rest_slot_unavailable; booking/hold http=200", "derived value mismatch (content withheld)")
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown root_state=bookable visible=0 "
            "observed=" + observed
        )
        fields = projector.project(line)
        self.assertTrue((fields["http"], fields["code"]) == ("409", "other"), "derived value mismatch (content withheld)")

    def test_confirm_and_identity_gate_lines_carry_no_code(self):
        """(b) The confirm/gate raise sites print `http=` with no `code=`.

        Verbatim shapes from pilot-day2-workflows.py: `booking/confirm HTTP
        {status}` (line 690) and `expected 400 identity gate, got {status}`
        (line 683). Neither prints `code=`, so a busy failure on those paths
        projects `code=none` — disclosed as a limitation, not guessed at.
        """
        for text, expected in (
            ("FAIL journey-A1-public — Day-2 browser journey failed "
             "(journey-A1-public) — booking/confirm HTTP 503", ("503", "none")),
            ("FAIL journey-A1-public — Day-2 browser journey failed "
             "(journey-A1-public) — expected 400 identity gate, got 503", ("503", "none")),
        ):
            fields = projector.project(text)
            self.assertTrue((fields["http"], fields["code"]) == expected, "derived value mismatch (content withheld)")

    def test_busy_code_followed_by_a_semicolon_is_still_the_busy_code(self):
        """(c) `describe()` terminates every observation with `;` but the last.

        A busy observation that is not the final entry is therefore printed as
        `code=CLINIC_BOOKING_BUSY;` — the bare code plus a separator, never
        `code=other`.
        """
        observed = self.describe([
            {"route": "/wp-json/clinic/v1/booking/hold", "status": 503,
             "code": "CLINIC_BOOKING_BUSY"},
            {"route": "/wp-json/clinic/v1/booking/hold", "status": 200, "code": ""},
        ])
        self.assertTrue("code=CLINIC_BOOKING_BUSY;" in observed, "derived output missing expected fragment (content withheld)")
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown observed=" + observed
        )
        fields = projector.project(line)
        self.assertTrue((fields["code"], fields["http"]) == ("CLINIC_BOOKING_BUSY", "503"), "derived value mismatch (content withheld)")

    def test_zero_errno_has_no_class(self):
        """(d) A stored `sql_errno=0` must not be classed `other`.

        Specificity: this asserts the WHOLE classification for the input, not just
        the pair, so an unrelated change to another mapping (e.g. `1213` no longer
        meaning `deadlock`, or `0` drifting to `other`) cannot slip through. The
        companion assertions below pin the neighbours that a deadlock-mapping
        mutation would move.
        """
        line = failure_line().replace("sql_errno=1213", "sql_errno=0")
        fields = projector.project(line)
        self.assertTrue((fields["errno"], fields["errno_class"]) == ("0", "unknown"), "derived value mismatch (content withheld)")
        # The unchanged line still maps 1213 -> deadlock: a mutation that broke
        # the deadlock mapping would change this and fail here.
        same = projector.project(failure_line())  # carries sql_errno=1213
        self.assertTrue((same["errno"], same["errno_class"]) == ("1213", "deadlock"), "derived value mismatch (content withheld)")
        # And the two are genuinely different classifications, not both-unknown.
        self.assertTrue(fields["errno_class"] != same["errno_class"], "derived values unexpectedly equal (content withheld)")

    def test_busy_details_on_a_continuation_line_are_found(self):
        """(e) The harness can emit the busy suffix on a later physical line.

        The second raise site in journey A1 interpolates the ORIGINAL exception
        text, which for a Playwright timeout already ends with newlines. `print()`
        writes those newlines verbatim, so the line after `FAIL ...` can carry
        `busy_diag=`/`sql_errno=`.
        """
        text = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown root_state=bookable visible=0\n"
            "Call log:\n"
            "  - waiting for locator(\"[data-role=hold-countdown]\")\n"
            " | journey=A1-public busy_diag=booking.lock_read_failed read=slot "
            "sql_errno=1205 route=hold/hold http=503 viewport=390x844\n"
        )
        fields = projector.project(text)
        self.assertTrue(fields["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")
        self.assertTrue((fields["errno"], fields["errno_class"]) == ("1205", "lock_wait_timeout"), "derived value mismatch (content withheld)")
        self.assertTrue((fields["read"], fields["http"]) == ("slot", "503"), "derived value mismatch (content withheld)")
        self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")

    def test_busy_block_is_absent_when_the_harness_appended_nothing(self):
        """(f) `busy_suffix_for` returns "" when no busy call was observed."""
        line = (
            "FAIL journey-B-reception — Day-2 browser journey failed "
            "(journey-B-reception) — reception board load failed"
        )
        self.assertTrue(projector.project(line)["busy"] == "absent", "derived value mismatch (content withheld)")

    def test_every_busy_diag_state_maps_to_its_token(self):
        produced = {
            self.busy.format_busy_suffix(
                {"event": self.busy.EVENT, "read": "slot", "sql_errno": 1213},
                route="hold/hold", http_status=503, viewport="390x844",
            ): "present",
            self.busy.format_busy_suffix(
                None, route="hold/hold", http_status=503, viewport="390x844",
                missing="not-found",
            ): "not_found",
            self.busy.format_busy_suffix(
                None, route="hold/hold", http_status=503, missing="fetch-failed",
            ): "fetch_failed",
            self.busy.format_busy_suffix(None, missing="stale-or-mismatch"): "stale_or_mismatch",
        }
        for suffix, expected in produced.items():
            with self.subTest(expected="case"):
                line = (
                    "FAIL journey-A1-public — Day-2 browser journey failed "
                    "(journey-A1-public) — x | journey=A1-public " + suffix
                )
                self.assertTrue(projector.project(line)["busy"] == expected, "derived value mismatch (content withheld)")
        self.assertTrue(projector.project(failure_line())["busy"] == "present", "derived value mismatch (content withheld)")
        self.assertTrue(projector.project(None)["busy"] == "absent", "derived value mismatch (content withheld)")
        self.assertTrue(set(projector.BUSY_TOKENS) == set(projector.ENUMS["busy"]))


class BoundsAndCliHardeningTest(_RedactedAssertions, unittest.TestCase):
    """The bounds and the CLI paths that the workflow actually depends on.

    Each test injects a SMALL limit so the bound itself is exercised (not the
    machine's patience), and each is written so that deleting the bound makes it
    fail — see the mutation list in the PR body.
    """

    def load_cli(self):
        spec = importlib.util.spec_from_file_location(
            "day2_projector_cli", BIN_DIR / "pilot-day2-failure-report.py"
        )
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_log_size_bound_is_enforced(self):
        cli = self.load_cli()
        cli.MAX_LOG_BYTES = 32
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "big.log"
            path.write_text("x" * 31 + "\n" + failure_line(), encoding="utf-8")
            fields = cli.project(cli._load_log(str(path)))
        # The FAIL line starts past the bound, so it was never read: a log that
        # was cut off mid-content has no failure in it, and the projector says
        # `journey=none` ("no failure in what I read") rather than inventing one.
        self.assertTrue(fields["journey"] == projector.NONE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["stage"] == projector.NONE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["http"] == projector.UNRECOGNISED, "derived value mismatch (content withheld)")

    def test_log_size_bound_still_reads_up_to_the_bound(self):
        cli = self.load_cli()
        cli.MAX_LOG_BYTES = 4096
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "ok.log"
            path.write_text(failure_line() + "y" * 20000, encoding="utf-8")
            fields = cli.project(cli._load_log(str(path)))
        self.assertTrue(fields["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")

    def test_line_length_bound_is_enforced(self):
        cli = self.load_cli()
        cli.MAX_LINE_CHARS = 40
        fields = cli.project(failure_line())
        # The FAIL line keeps its first 40 characters: the key survives, every
        # marker past the bound is gone.
        self.assertTrue(fields["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")
        self.assertTrue(fields["stage"] == projector.NONE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["code"] == projector.NONE, "derived value mismatch (content withheld)")

    def test_line_length_bound_is_enforced_independently_of_the_block(self):
        """The per-line clip must hold even when the block bound is generous."""
        cli = self.load_cli()
        cli.MAX_BLOCK_CHARS = 1 << 30
        cli.MAX_LINE_CHARS = 40
        fields = cli.project(failure_line("busy_diag=not-found"))
        self.assertTrue(fields["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")
        self.assertTrue(fields["busy"] == "absent", "derived value mismatch (content withheld)")

    def test_line_count_bound_stops_scanning_before_the_fail_line(self):
        cli = self.load_cli()
        cli.MAX_LINES = 3
        text = "noise\n" * 10 + failure_line()
        self.assertTrue(cli.project(text)["journey"] == projector.NONE, "derived value mismatch (content withheld)")
        # With one more line allowed the same log does report the failure, so the
        # test above fails for the bound and not for another reason.
        cli.MAX_LINES = 12
        self.assertTrue(cli.project(text)["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")

    def test_line_count_bound_is_enforced(self):
        cli = self.load_cli()
        cli.MAX_LINES = 3
        text = "noise\n" * 10 + failure_line()
        self.assertTrue(cli.project(text)["journey"] == projector.NONE, "derived value mismatch (content withheld)")

    def test_line_count_bound_is_not_off_by_one(self):
        cli = self.load_cli()
        cli.MAX_LINES = 4
        text = "noise\n" * 3 + failure_line()
        self.assertTrue(cli.project(text)["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")

    def test_block_size_bound_is_enforced(self):
        """A multi-megabyte failure block must not be scanned without a bound."""
        cli = self.load_cli()
        head = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown|\n"
        )
        tail = "   filler line\n" * 200 + " | journey=A1-public busy_diag=not-found\n"
        # Bounded: the `busy_diag=` token sits past the bound and is not read.
        cli.MAX_BLOCK_CHARS = 200
        self.assertTrue(cli.project(head + tail)["busy"] == "absent", "derived value mismatch (content withheld)")
        # Unbounded (same text, generous bound): the token is found.
        cli.MAX_BLOCK_CHARS = 1 << 20
        self.assertTrue(cli.project(head + tail)["busy"] == "not_found", "derived value mismatch (content withheld)")

    def test_non_regular_log_path_is_refused_without_blocking(self):
        with tempfile.TemporaryDirectory() as tmp:
            fifo = Path(tmp) / "fifo.log"
            os.mkfifo(fifo)
            started = time.monotonic()
            result = _run_subprocess(self,
                [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"),
                 "--log", str(fifo)],
                timeout=30,
            )
            elapsed = time.monotonic() - started
        self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
        self.assertTrue(result.stdout.startswith("journey=unrecognised "))
        self.assertLess(elapsed, 10)

    def test_field_mode_never_prints_a_raw_value(self):
        """`--field` is the ONLY interface the workflow uses, so it must be closed."""
        hostile = "x9; ::error::canary ##[error] $(id) `id` " + CANARY_EMAIL
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "day2.log"
            path.write_text(
                "FAIL journey-A9-foreign — t — stage=" + hostile
                + " http=" + hostile + " code=" + hostile + " read=" + hostile
                + " sql_errno=" + hostile + " busy_diag=" + hostile
                + " elapsed_s=" + hostile + "\n",
                encoding="utf-8",
            )
            for field in projector.FIELDS:
                with self.subTest(field="case"):
                    result = _run_subprocess(self,
                        [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"),
                         "--log", str(path), "--field", field],
                                            )
                    self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
                    token = result.stdout.rstrip("\n")
                    if field in projector.ENUMS:
                        self.assertTrue(token in projector.ENUMS[field],
                                        "field token outside its enum (content withheld)")
                    else:
                        self.assertTrue(token == projector.UNRECOGNISED,
                                        "field token is not the unrecognised token (content withheld)")
                    self.assertTrue(token.count("\n") == 0, "derived value mismatch (content withheld)")
                    for canary in ("canary", "::", "##[", "$(id)", "`id`", CANARY_EMAIL):
                        self.assertTrue(canary not in token,
                                        "projected token carried a canary (content withheld)")

    def test_cli_errors_are_nonzero_and_print_no_raw_text(self):
        for args in (
            ("--field", "not-a-field"),
            ("--log",),
            ("--unknown-option",),
            ("--test", "--field", "journey", "--bogus"),
        ):
            with self.subTest(args="case"):
                result = _run_subprocess(self,
                    [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"), *args],
                                    )
                self.assertTrue(result.returncode != 0, "derived values unexpectedly equal (content withheld)")
                for canary in (CANARY_TOKEN, CANARY_EMAIL, CANARY_SQL, "Traceback"):
                    self.assertTrue(canary not in result.stdout + result.stderr, "captured output carried forbidden text (content withheld)")

    def test_cli_fallback_is_total_when_projection_raises(self):
        """The `except Exception` fallback must print a closed token, not a traceback."""
        cli = self.load_cli()
        original = cli.project
        cli.project = lambda text: (_ for _ in ()).throw(RuntimeError("boom"))
        buffer = io.StringIO()
        try:
            with redirect_stdout(buffer):
                for field in cli.FIELDS:
                    code = cli.main(["--log", "/nonexistent/x.log", "--field", field])
                    self.assertTrue(code == 0, "derived value mismatch (content withheld)")
        finally:
            cli.project = original
        lines = buffer.getvalue().splitlines()
        self.assertTrue(len(lines) == len(cli.FIELDS), "derived value mismatch (content withheld)")
        for line in lines:
            self.assertTrue("Traceback" not in line, "projected output carried forbidden text (content withheld)")
            self.assertTrue("boom" not in line, "projected output carried forbidden text (content withheld)")
            self.assertTrue(line in cli.UNRECOGNISED or line == "none" or line == "absent"
                            or line in cli.ENUMS.get("errno_class", ()),
                            "projected line outside the closed vocabulary (content withheld)")

    def test_directory_and_device_paths_are_total(self):
        for path in ("/dev/null", "/proc/self/cmdline", "/", "/nonexistent/x.log"):
            with self.subTest(path="case"):
                result = _run_subprocess(self,
                    [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"),
                     "--log", path],
                                    )
                self.assertTrue(result.returncode == 0, "derived value mismatch (content withheld)")
                self.assertTrue(result.stdout.count("\n") == 1, "derived value mismatch (content withheld)")
                self.assertTrue(
                    result.stdout.startswith("journey=unrecognised ")
                    or result.stdout.startswith("journey=none "), "captured output has unexpected shape (content withheld)"
                )


class RoundTwoFidelityTest(_RedactedAssertions, unittest.TestCase):
    """H1–H5: the reviewer findings, each pinned by a test built from real code.

    Failure text is produced by CALLING the harness (`visible_or_diagnose` with a
    stub page, `BookingProbe.describe`, `fail`, `day2_booking_busy`); nothing here
    is invented text. Playwright's block shape comes from its pinned formatter
    (`format_call_log` in playwright 1.64.1, which prefixes the call log with a
    newline), reproduced literally below and labelled as such.
    """

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_h", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)
        import day2_booking_busy
        cls.busy = day2_booking_busy

    # --- helpers that call the real harness -------------------------------
    def busy_probe(self, code="CLINIC_BOOKING_BUSY", status=503):
        probe = self.harness.BookingProbe()
        probe.entries = [
            {"route": "/wp-json/clinic/v1/booking/quote", "status": 200, "code": ""},
            {"route": "/wp-json/clinic/v1/booking/hold", "status": status,
             "code": code, "slot_id": 42},
        ]
        return probe

    def suffix(self, probe):
        """The harness's own suffix, from a DB row with no untrusted text."""
        row = ('{"read": "slot", "sql_errno": 1213}',)
        return self.busy.busy_suffix_for(
            probe.entries, lambda sql: [row], "cpmswp_cpms_operational_logs",
            "390x844", since="2026-11-13 10:00:00",
        )

    def fail_log(self, exc):
        """The REAL producer output: stdout of the harness's own `fail()`.

        Producer-to-projector tests must consume what `fail()` actually
        prints today, not a hand-built historical shape: `fail()` prints a
        fixed schema (closed error kind, plus the validated busy diagnosis
        of a `BusyJourneyFailure`) and never prints exception text.
        """
        if isinstance(exc, str):
            exc = AssertionError(exc)
        self.harness.results.clear()
        self.harness.failures.clear()
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            self.harness.fail(
                "journey-A1-public",
                "Day-2 browser journey failed (journey-A1-public)",
                exc,
            )
        return buffer.getvalue()

    def projector_log(self, exc_text):
        """A FAIL line as projector INPUT (literal fixture, NOT producer output).

        For tests of the projector's own robustness against log text in the
        historical shape; producer-fidelity tests use `fail_log` instead.
        """
        return (
            "FAIL journey-A1-public — Day-2 browser journey failed "
            f"(journey-A1-public) — {exc_text}\n"
        )

    def reraises(self, exc_text, probe):
        """The A1 handler's real re-raise for an origin with this `exc_text`.

        Mirrors the handler's own construction: the probe-derived suffix of
        `busy_suffix_for` (or nothing, when nothing was busy) is carried by a
        `BusyJourneyFailure`; the origin text itself is never carried, only
        its closed stage token is read.
        """
        suffix = self.suffix(probe)
        if suffix:
            return self.harness.BusyJourneyFailure(suffix, origin_text=exc_text)
        return AssertionError(exc_text)

    # --- H1 ---------------------------------------------------------------
    def test_busy_suffix_never_carries_a_code_token(self):
        """H1 root cause: the structured suffix has no `code=` of its own."""
        probe = self.busy_probe()
        suffix = self.suffix(probe)
        self.assertTrue("busy_diag=booking.lock_read_failed" in suffix, "derived output missing expected fragment (content withheld)")
        self.assertTrue("sql_errno=1213" in suffix, "derived output missing expected fragment (content withheld)")
        self.assertTrue("code=" not in suffix, "derived output carried forbidden text (content withheld)")

    def test_code_comes_from_the_validated_busy_diagnosis_for_every_origin(self):
        """H1: the busy code now rides the validated diagnosis, not origin text.

        Historically only 2 of the 22 origins could carry a `code=` (the
        `hold-countdown` stage and the line-673 raise). Since the FAIL line
        stopped printing exception text, `fail()` publishes the busy code
        itself — the validated suffix exists only for a busy attempt — so
        EVERY origin with a busy diagnosis projects the busy code, and the
        origin templates below (2 carry-capable, a representative sample of
        the 15 bare asserts) all reach the projector through the REAL
        `fail()` output. This pins the categories, not an exhaustive
        inventory of the 22 origins.
        """
        probe = self.busy_probe()
        # Paths whose own message DOES carry `code=`: describe()'s summary and
        # visible_or_diagnose's hold_attempt field (both real harness output).
        with_code = self.harness.BookingProbe()
        with_code.entries = probe.entries
        describe_text = (
            "public booking stage=hold-countdown root_state=bookable visible=0 "
            "observed=" + with_code.describe(0)
        )
        hold_attempt = probe.hold_attempts(0)[0]
        hold_text = (
            "public booking stage=hold-countdown root_state=bookable visible=0 "
            f"hold_attempt=slot={hold_attempt['slot_id']} http={hold_attempt['status']}"
            f"{' code=' + hold_attempt['code']}"
        )
        for text in (describe_text, hold_text):
            with self.subTest(kind="case"):
                fields = projector.project(self.fail_log(self.reraises(text, probe)))
                self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
                self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")

        # A representative sample of the 15 bare-literal / status-word origins:
        # these print no `code=` of their own, but the busy diagnosis the
        # handler attaches is producer-validated, so the projected code is
        # the busy code for every one of them — with the busy fields intact.
        # The full inventory is 15 asserts + the 5 non-probe visible_or_diagnose
        # stages; they all share this property, which the sample pins.
        bare_literal_origins = (
            "public booking root missing",
            "booking/quote failed",
            "expected 400 identity gate, got 503",
            "booking/confirm HTTP 503",
            "otp/request HTTP 503",
            "otp/verify HTTP 503",
            "receipt reference_code format invalid",
        )
        for text in bare_literal_origins:
            with self.subTest(kind="case"):
                fields = projector.project(self.fail_log(self.reraises(text, probe)))
                self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")
                self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
                self.assertTrue(fields["read"] == "slot", "derived value mismatch (content withheld)")
                self.assertTrue(fields["errno"] == "1213", "derived value mismatch (content withheld)")
                self.assertTrue(fields["http"] == "503", "derived value mismatch (content withheld)")

    def test_a1_failure_origin_counts_match_the_documented_inventory(self):
        """H1: the documented counts are measured from the real source, not guessed.

        Parses `run_journey_a_public_booking` and asserts the three category
        counts the docstring states (6 `visible_or_diagnose`, 15 bare asserts,
        1 explicit raise => 22 origins). If the harness gains or loses a failure
        site, this fails and the documented inventory must be re-measured.
        """
        import ast as _ast
        src = (BIN_DIR / "pilot-day2-workflows.py").read_text(encoding="utf-8")
        tree = _ast.parse(src)
        fn = next(
            n for n in _ast.walk(tree)
            if isinstance(n, _ast.FunctionDef) and n.name == "run_journey_a_public_booking"
        )
        vd, asserts, raises = [], [], []
        for n in _ast.walk(fn):
            if isinstance(n, _ast.Call) and isinstance(n.func, _ast.Name) \
                    and n.func.id == "visible_or_diagnose":
                vd.append(n.lineno)
            elif isinstance(n, _ast.Assert):
                asserts.append(n.lineno)
            elif isinstance(n, _ast.Raise):
                raises.append(n)
        # Two raises in the handler are NOT their own origin, identified by AST
        # shape rather than by line number: the bare re-raise (`raise`, no
        # exc) and the busy-diagnosis re-raise (`raise BusyJourneyFailure(...)
        # from None`, the handler's structured republish of the origin).
        # Only `raise AssertionError(...)` at line 673 is a distinct origin.
        explicit = [
            r for r in raises
            if r.exc is not None
            and not (
                isinstance(r.exc, _ast.Call)
                and isinstance(r.exc.func, _ast.Name)
                and r.exc.func.id == "BusyJourneyFailure"
            )
        ]
        self.assertTrue(len(vd) == 6, "derived value mismatch (content withheld)")
        self.assertTrue(len(asserts) == 15, "derived value mismatch (content withheld)")
        self.assertTrue(len(explicit) == 1, "derived value mismatch (content withheld)")
        self.assertTrue(len(vd) + len(asserts) + len(explicit) == 22, "derived value mismatch (content withheld)")
        # Exactly one visible_or_diagnose call passes a probe (carry-capable).
        carry = [
            n for n in _ast.walk(fn)
            if isinstance(n, _ast.Call) and isinstance(n.func, _ast.Name)
            and n.func.id == "visible_or_diagnose"
            and len(n.args) > 3 and not isinstance(n.args[3], _ast.Constant)
        ]
        self.assertTrue(len(carry) == 1, "derived value mismatch (content withheld)")

    def test_only_the_two_carry_capable_origins_emit_a_code(self):
        """H1: 2 of 22 origins have a `code=` TEMPLATE of their own; 20 do not.

        The 22 counts selected failure origins in journey A1 -- 6 helper calls,
        15 explicit asserts and 1 explicit raise -- not every incidental
        exception a library might throw inside those calls. The handler's
        re-raises at lines 724/725 are not separate origins.

        "20 do not" is a statement about the source text of those origins, NOT a
        guarantee that they project `code=none`. `code` is scanned over the whole
        failure block, so a `code=` spelling in appended text (untrusted
        `sql_error=` included) projects `other` on any origin. This test asserts
        only the source-level split and the two carry-capable shapes.

        Inputs are built from the real producers' output shapes. The carry-capable
        COUNT is asserted from the source first, so adding or removing a
        probe-passing `visible_or_diagnose` call fails here.
        """
        import ast as _ast
        src = (BIN_DIR / "pilot-day2-workflows.py").read_text(encoding="utf-8")
        tree = _ast.parse(src)
        fn = next(
            n for n in _ast.walk(tree)
            if isinstance(n, _ast.FunctionDef) and n.name == "run_journey_a_public_booking"
        )
        carrying = [
            n for n in _ast.walk(fn)
            if isinstance(n, _ast.Call) and isinstance(n.func, _ast.Name)
            and n.func.id == "visible_or_diagnose"
            and len(n.args) > 3 and not isinstance(n.args[3], _ast.Constant)
        ]
        # Exactly ONE visible_or_diagnose call passes a probe (hold-countdown);
        # combined with the line-673 raise that makes 2 carry-capable origins.
        self.assertTrue(len(carrying) == 1, "derived value mismatch (content withheld)")
        self.assertTrue(carrying[0].args[2].value == "hold-countdown", "derived value mismatch (content withheld)")

        # 1) hold-countdown via describe() only.
        probe = self.busy_probe()
        describe_text = (
            "public booking stage=hold-countdown root_state=bookable visible=0 observed="
            + probe.describe(0)
        )
        self.assertTrue(projector.project(self.fail_log(self.reraises(describe_text, probe)))["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
        # 2) the line-673 explicit raise.
        hold = probe.hold_attempts(0)[0]
        explicit = (
            "public booking stage=hold-request \u2014 hold rejected for the fixture slot "
            f"slot={hold['slot_id']} http={hold['status']} code={hold['code']}"
        )
        self.assertTrue(projector.project(self.fail_log(self.reraises(explicit, probe)))["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
        # 3) all five non-probe visible_or_diagnose stages: their own templates
        # carry no `code=`, but the attached validated diagnosis does, so the
        # projected code is the busy code (producer contract since the FAIL
        # line stopped printing exception text) — stage still from the origin.
        for stage in ("otp-mobile", "otp-code", "confirm-btn", "names-form", "receipt"):
            msg = f"public booking stage={stage} root_state=bookable visible=0"
            fields = projector.project(self.fail_log(self.reraises(msg, probe)))
            self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
            self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")
            self.assertTrue(fields["stage"] == stage, "derived value mismatch (content withheld)")

    def test_h5_free_text_markers_are_a_disclosed_limitation(self):
        """H5 residual, pinned as a KNOWN limitation (class ii: crafted text).

        `read`/`errno` are anchored to the last `busy_diag=` block onward, and
        `busy` to the first `busy_diag=` anywhere. Text that spells those tokens
        AFTER the real block still wins. This test documents the behaviour so a
        future fix has a failing baseline; it is not a claim of correctness.
        """
        real = ('busy_diag=booking.lock_read_failed read=slot sql_errno=1213 '
                'route=hold/hold http=503')
        injected = (
            "public booking stage=hold-countdown visible=0"
            " | journey=A1-public " + real +
            "\nCall log:\n  - retry said busy_diag=fetch-failed read=clinic sql_errno=99999"
        )
        fields = projector.project(self.projector_log(injected))
        # Documented current behaviour: the later free text wins for read/errno.
        self.assertTrue(fields["read"] == "clinic", "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno"] == "99999", "derived value mismatch (content withheld)")
        # ... while a purely natural message does not move:
        natural = (
            "public booking stage=hold-countdown visible=0"
            " | journey=A1-public " + real +
            "\nCall log:\n  - waiting for locator(\"[data-role=hold-countdown]\")"
        )
        nf = projector.project(self.projector_log(natural))
        self.assertTrue((nf["read"], nf["errno"]) == ("slot", "1213"), "derived value mismatch (content withheld)")

    def test_h4_word_form_is_ignored_once_a_2xx_token_is_present(self):
        """H4 boundary, measured: `none` wins over a word-form-only status.

        `saw_only_success` is computed from `http=` TOKENS alone, so once it is
        true the `HTTP 409` / `got 422` word fallback is skipped and the result is
        `none`. This test pins that boundary so the documented wording cannot
        drift into claiming more than the code does. It also shows the same text
        WITHOUT a 2xx token resolving correctly, which proves the difference is
        the token and not the text.
        """
        head = ("FAIL day2-A1-public — Day-2 browser journey failed "
                "(journey-A1-public) — public booking stage=hold-countdown ")
        # 2xx token present -> word form ignored -> none
        self.assertTrue(projector.project(head + "observed=q http=200 booking/confirm HTTP 409\n")["http"] == projector.NONE, "derived value mismatch (content withheld)")
        self.assertTrue(projector.project(head + "observed=q http=200 expected 400 identity gate, "
                                    "got 422\n")["http"] == projector.NONE, "derived value mismatch (content withheld)")
        # same text, no 2xx token -> the word form is used
        self.assertTrue(projector.project(head + "booking/confirm HTTP 503\n")["http"] == "503", "derived value mismatch (content withheld)")
        self.assertTrue(projector.project(head + "expected 400 identity gate, got 503\n")["http"] == "503", "derived value mismatch (content withheld)")
        # and a real non-2xx token also beats the word form
        self.assertTrue(projector.project(head + "observed=q http=200; h http=409\n")["http"] == "409", "derived value mismatch (content withheld)")

    def test_real_message_shapes_do_not_move_read_or_errno(self):
        """H5: 18 real message shapes, before and after the block, all correct.

        The shapes are text PHP, WordPress, PDO/mysqli, MySQL/MariaDB, curl or
        Playwright can actually emit. This is a finite set: it shows that none of
        these shapes produced a wrong attribution, it does not prove absence.
        Attribution remains best effort -- a `read=`/`sql_errno=` token anywhere
        in the busy window, including in untrusted `sql_error=` text, can still
        supply them. The counterexamples are listed in the module docstring.
        """
        real = ('busy_diag=booking.lock_read_failed read=slot sql_errno=1213 '
                'sql_error="Deadlock found" route=hold/hold http=503 viewport=390x844')
        head = "FAIL day2-A1-public — Day-2 browser journey failed (journey-A1-public) — "
        body = "public booking stage=hold-countdown visible=0 | journey=A1-public "
        shapes = [
            "  - PHP Warning: fread(): SSL: Connection reset by peer (errno=104)",
            "  - mysqli_sql_exception: Lost connection to MySQL server during query",
            "  - Error reading result set's header (errno: 2013)",
            "  - SQLSTATE[40001]: Serialization failure: 1213 Deadlock found",
            "  - SQLSTATE[HY000]: General error: 2006 MySQL server has gone away",
            "  - Error establishing a database connection",
            "  - cURL error 28: Operation timed out (errno 28)",
            '  - waiting for locator("[data-role=confirm-btn]")',
            "  - Request URL: https://clinic.test/wp-json/x?read=clinic&slot=3",
            '  - locator resolved to <div data-read="clinic">',
        ]
        for shape in shapes:
            with self.subTest(after="case"):
                fields = projector.project(head + body + real + "\n" + shape + "\n")
                self.assertTrue((fields["read"], fields["errno"]) == ("slot", "1213"), "derived value mismatch (content withheld)")
            with self.subTest(before="case"):
                fields = projector.project(head + body.replace("| journey", "\n| journey")
                                           + shape + "\n | journey=A1-public " + real + "\n")
                self.assertTrue((fields["read"], fields["errno"]) == ("slot", "1213"), "derived value mismatch (content withheld)")

    def test_a_bare_read_token_after_the_block_does_not_move_attribution(self):
        """H5 boundary, narrowly: a COMPLETE block's own tokens win.

        When the structured block already printed its own `read=`/`sql_errno=`,
        a bare `read=clinic sql_errno=99999` appended after it is ignored,
        because the lookup takes the FIRST match in the window. A second marker
        does re-anchor the window, so the appended values then win.

        CAUTION: this is NOT a general statement that bare tokens are ignored.
        With an INCOMPLETE block (for example `busy_diag=not-found`) the same
        bare text DOES supply `read`/`errno`. Attribution is best effort; see
        the module docstring's counterexample list.
        """
        real = ('busy_diag=booking.lock_read_failed read=slot sql_errno=1213 '
                'route=hold/hold http=503')
        head = ("FAIL day2-A1-public — Day-2 browser journey failed (journey-A1-public) — "
                "public booking stage=hold-countdown visible=0 | journey=A1-public ")
        bare = projector.project(head + real + "\n  - read=clinic sql_errno=99999\n")
        self.assertTrue((bare["read"], bare["errno"]) == ("slot", "1213"), "derived value mismatch (content withheld)")
        anchored = projector.project(
            head + real + "\n  - busy_diag=fetch-failed read=clinic sql_errno=99999\n")
        self.assertTrue((anchored["read"], anchored["errno"]) == ("clinic", "99999"), "derived value mismatch (content withheld)")

    # --- H2 ---------------------------------------------------------------
    def test_blank_line_does_not_end_the_block(self):
        """H2: Playwright's formatter prefixes the call log with a newline.

        `format_call_log` (playwright 1.64.1) returns
        "\\nCall log:\\n" + "\\n".join(log) + "\\n", so a blank line separates the
        title from the log. The busy suffix is appended AFTER all of it, so the
        block must survive the blank line.
        """
        exc = (
            "Locator.wait_for: Timeout 25000ms exceeded.\n"
            "\n"
            "Call log:\n"
            '  - waiting for locator("[data-role=confirm-btn]")\n'
        )
        probe = self.busy_probe()
        # Projector-input shape (the producer no longer prints exception
        # text): the historical FAIL line with the suffix appended after
        # the Playwright call log.
        log = self.projector_log(exc + " | journey=A1-public " + self.suffix(probe))
        self.assertTrue("\n\n" in log, "derived output missing expected fragment (content withheld)")  # the blank line really is in the log
        fields = projector.project(log)
        self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno"] == "1213", "derived value mismatch (content withheld)")
        self.assertTrue(fields["read"] == "slot", "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno_class"] == "deadlock", "derived value mismatch (content withheld)")

    def test_many_blank_lines_inside_the_block_are_harmless(self):
        exc = "Title.\n\n\n\nCall log:\n\n  - waiting\n\n"
        probe = self.busy_probe()
        fields = projector.project(self.fail_log(self.reraises(exc, probe)))
        self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")

    def test_a_new_harness_record_still_ends_the_block(self):
        """The block DOES end at the next station line, blank lines or not."""
        probe = self.busy_probe()
        exc = "Title.\n\nCall log:\n  - waiting\n"
        log = self.fail_log(self.reraises(exc, probe))
        log += "INFO day2-journey-timing journey=journey-A1-public elapsed_s=9\n"
        # A second FAIL record must NOT be folded into the first block.
        log += ("FAIL journey-B-reception — Day-2 browser journey failed "
                "(journey-B-reception) — busy_diag=not-found read=clinic\n")
        fields = projector.project(log)
        # The suffix belongs to the FIRST FAIL text; the later record's
        # `read=clinic` must not leak into it.
        self.assertTrue(fields["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")
        self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")
        self.assertTrue(fields["read"] == "slot", "derived value mismatch (content withheld)")
        self.assertTrue(fields["elapsed_s"] == "9", "derived value mismatch (content withheld)")

    # --- H4 ---------------------------------------------------------------
    def test_only_successful_statuses_project_none(self):
        """H4: no failing request was observed — a fact, not `unrecognised`."""
        text = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown observed=booking/quote http=200; "
            "booking/hold http=200\n"
        )
        fields = projector.project(text)
        self.assertTrue(fields["http"] == projector.NONE, "derived value mismatch (content withheld)")
        self.assertTrue(projector.is_closed(fields))

    def test_a_non_2xx_status_still_wins_over_none(self):
        probe = self.busy_probe(code="rest_slot_unavailable", status=409)
        text = ("public booking stage=hold-countdown observed=" + probe.describe(0))
        fields = projector.project(self.projector_log(text))
        self.assertTrue(fields["http"] == "409", "derived value mismatch (content withheld)")
        self.assertTrue(fields["http"] != projector.NONE, "derived values unexpectedly equal (content withheld)")

    def test_none_http_is_only_for_observed_success_never_for_silence(self):
        fields = projector.project(
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) "
            "— public booking root missing\n"
        )
        self.assertTrue(fields["http"] == projector.UNRECOGNISED, "derived value mismatch (content withheld)")

    # --- H5 ---------------------------------------------------------------
    def test_free_text_cannot_supply_read_or_errno(self):
        """H5: a `msg=` free-text field (historical log shape) is not ours."""
        probe = self.busy_probe()
        host = self.busy_probe()
        host.entries[1]["message"] = "read=clinic sql_errno=99999"
        base = self.suffix(probe)
        text = (
            "public booking stage=hold-countdown hold_attempt=slot=42 http=503 "
            "code=CLINIC_BOOKING_BUSY msg=" + host.entries[1]["message"]
            + " | journey=A1-public " + base
        )
        fields = projector.project(self.projector_log(text))
        self.assertTrue(fields["read"] == "slot", "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno"] == "1213", "derived value mismatch (content withheld)")

    def test_last_busy_block_wins(self):
        """The structured block is the LAST `busy_diag=`; earlier ones are stale."""
        base = self.suffix(self.busy_probe())
        text = (
            "FAIL journey-A1-public — x — busy_diag=booking.lock_read_failed "
            "read=clinic sql_errno=1062 | journey=A1-public " + base + "\n"
        )
        fields = projector.project(text)
        self.assertTrue((fields["read"], fields["errno"]) == ("slot", "1213"), "derived value mismatch (content withheld)")

    def test_an_unknown_busy_value_is_absent(self):
        text = (
            "FAIL journey-A1-public — x — busy_diag=totally-unknown read=slot "
            "sql_errno=1213\n"
        )
        fields = projector.project(text)
        self.assertTrue(fields["busy"] == "absent", "derived value mismatch (content withheld)")
        self.assertTrue(projector.is_closed(fields))


class RoundTwoGapTest(_RedactedAssertions, unittest.TestCase):
    """H6: the specific gaps the readers named, one test each."""

    def test_line_count_off_by_one(self):
        """The bound is `index >= MAX_LINES`, so line MAX_LINES-1 is still read."""
        cli = BoundsAndCliHardeningTest.load_cli(self)
        cli.MAX_LINES = 4
        # Lines 0..3 are the noise; the FAIL line is index 4 -> past the bound.
        self.assertTrue(cli.project("n\nn\nn\nn\nFAIL journey-A1-public — x — y\n")["journey"] == projector.NONE, "derived value mismatch (content withheld)")
        # With the FAIL line at index 3 (the LAST readable line) it is read.
        self.assertTrue(cli.project("n\nn\nn\nFAIL journey-A1-public — x — y\n")["journey"] == "journey-A1-public", "derived value mismatch (content withheld)")

    def test_which_non_2xx_status_wins(self):
        """The LAST non-2xx in the block wins: describe() is oldest-first."""
        text = (
            "FAIL journey-A1-public — x — observed=quote http=200; hold http=409; "
            "confirm http=422\n"
        )
        self.assertTrue(projector.project(text)["http"] == "422", "derived value mismatch (content withheld)")

    def test_separators_are_honoured_on_read_and_errno(self):
        """`;` and `|` bound every token, not just `code`."""
        text = (
            "FAIL journey-A1-public — x — busy_diag=booking.lock_read_failed; "
            "read=slot; sql_errno=1205;\n"
        )
        fields = projector.project(text)
        self.assertTrue((fields["read"], fields["errno"]) == ("slot", "1205"), "derived value mismatch (content withheld)")

    def test_unknown_busy_value_is_absent_not_unrecognised(self):
        fields = projector.project(
            "FAIL journey-A1-public — x — busy_diag=nonsense read=slot\n"
        )
        self.assertTrue(fields["busy"] == "absent", "derived value mismatch (content withheld)")

    def test_first_busy_code_wins_over_a_later_one(self):
        """`code` reports the busy code whenever it appears, and the http that
        belongs to it is the nearest preceding `http=` — not a later one."""
        text = (
            "FAIL journey-A1-public — x — hold http=503 code=CLINIC_BOOKING_BUSY; "
            "confirm http=500 code=internal_error\n"
        )
        fields = projector.project(text)
        self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["http"] == "503", "derived value mismatch (content withheld)")

    def test_a_journey_prints_exactly_one_timing_line(self):
        """Why first-vs-last is moot in practice, stated as a checked fact.

        The harness's `main()` prints the timing line once per journey in a
        `finally:` block (line ~1490), so a real log never contains two for the
        same key. `_elapsed_for` would take the first; this test pins BOTH the
        harness property and the projector's first-match rule so a future change
        to either is visible.
        """
        text = (
            "FAIL journey-A1-public — x — y\n"
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=7\n"
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=9\n"
        )
        self.assertTrue(projector.project(text)["elapsed_s"] == "7", "derived value mismatch (content withheld)")
        # The harness emits the timing line exactly once, in one `finally:`.
        source = HARNESS.read_text(encoding="utf-8")
        self.assertTrue(source.count("day2-journey-timing journey={key}") == 1, "derived value mismatch (content withheld)")

    def test_first_timing_line_for_a_journey_wins_for_that_journey(self):
        """`_elapsed_for` scans forward and returns the FIRST match of the key."""
        text = (
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=7\n"
            "FAIL journey-A1-public — x — y\n"
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=9\n"
        )
        self.assertTrue(projector.project(text)["elapsed_s"] == "7", "derived value mismatch (content withheld)")


class Day2HarnessDbStderrAdversarialTest(_RedactedAssertions, unittest.TestCase):
    """Fake database stderr must never reach stdout, results or projection.

    Executes the real production path — `pilot-day2-workflows.db()` with a
    fake `mysql` on PATH whose stderr carries a valid-looking token, SQL-like
    text and lock-like text, then `fail()` exactly as `main()` calls it in
    its journey exception handler — and asserts none of the injected text
    appears in the raised exception, in stdout, in the stored results
    detail, or in the failure-report projection of that stdout. A failing
    journey must still be recorded as a failure.
    """

    TOKENS = (
        "SYNTH_TOKEN_9f3",
        "SYNTH_MOBILE_123",
        "SYNTH_LOCK_77",
        "Deadlock found",
        "SELECT id FROM",
    )

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_adv", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)

    def test_fake_db_stderr_never_reaches_any_surface(self):
        harness = self.harness
        with tempfile.TemporaryDirectory() as tmp:
            fake = Path(tmp) / "mysql"
            fake.write_text(
                "#!/bin/sh\n"
                "echo \"ERROR 1213 (40001): Deadlock found SYNTH_TOKEN_9f3 "
                "SQLLIKE SELECT id FROM cpms_patients WHERE mobile='SYNTH_MOBILE_123' "
                "LOCKLIKE lock_data=SYNTH_LOCK_77\" >&2\n"
                "exit 1\n",
                encoding="utf-8",
            )
            fake.chmod(0o755)
            old_path = os.environ.get("PATH", "")
            os.environ["PATH"] = tmp + os.pathsep + old_path
            try:
                with self.assertRaises(RuntimeError) as raised:
                    harness.db("SELECT 1")
            finally:
                os.environ["PATH"] = old_path

        raised_text = str(raised.exception)
        self.assertTrue("db_failed rc=1" in raised_text, "derived output missing expected fragment (content withheld)")
        for token in self.TOKENS:
            self.assertTrue(token not in raised_text, "derived output carried forbidden text (content withheld)")

        harness.results.clear()
        harness.failures.clear()
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            harness.fail(
                "journey-A1-public",
                "Day-2 browser journey failed (journey-A1-public)",
                raised.exception,
            )
        stdout = buffer.getvalue()
        self.assertTrue(stdout.startswith("FAIL journey-A1-public"))
        self.assertTrue("error_kind=runtime" in stdout, "captured output missing expected fragment (content withheld)")
        # A failing test must still fail: the journey is recorded as failed.
        self.assertTrue(harness.failures == ["journey-A1-public"], "derived value mismatch (content withheld)")
        self.assertTrue(harness.results[-1]["status"] == "FAIL", "derived value mismatch (content withheld)")
        results_detail = harness.results[-1]["detail"]
        for token in self.TOKENS:
            self.assertTrue(token not in stdout, "captured output carried forbidden text (content withheld)")
            self.assertTrue(token not in results_detail, "derived output carried forbidden text (content withheld)")
            self.assertTrue(token not in projector.render(projector.project(stdout)), "captured output carried forbidden text (content withheld)")

    def test_response_message_never_reaches_info_stdout(self):
        """Claim A: a hostile REST `message` must not survive the probe.

        Drives the real production path — `BookingProbe._on_response` with
        a fake busy response whose envelope `message` (and nested
        `data.message`) carries hostile text, then the journey's own
        `info(f"day2-A1-resume hold_attempts={probe.hold_attempts(...)}")`
        line — and asserts the text appears in neither the stored entries,
        stdout, nor the failure-report projection of that stdout, while
        the closed fields (status, allowlisted code, posted ids) remain.
        """
        harness = self.harness
        hostile = "SYNTH_MSG_TOKEN Deadlock found SELECT mobile FROM t"

        class _Request:
            post_data_json = {"slot_id": 42, "slot_date": "2026-11-14",
                              "slot_time": "09:00"}

        class _Response:
            url = "http://fixture.test/wp-json/clinic/v1/booking/hold"
            status = 503
            request = _Request()

            def json(self):
                return {"code": "CLINIC_BOOKING_BUSY", "message": hostile,
                        "data": {"message": hostile, "status": 503}}

        probe = harness.BookingProbe()
        probe._on_response(_Response())
        self.assertTrue(len(probe.entries) == 1, "derived value mismatch (content withheld)")
        entry = probe.entries[0]
        self.assertTrue("message" not in entry, "derived output carried forbidden text (content withheld)")
        self.assertTrue(entry["status"] == 503, "derived value mismatch (content withheld)")
        self.assertTrue(entry["code"] == "CLINIC_BOOKING_BUSY", "derived value mismatch (content withheld)")
        self.assertTrue(entry["slot_id"] == 42, "derived value mismatch (content withheld)")

        buffer = io.StringIO()
        with redirect_stdout(buffer):
            harness.info(
                f"day2-A1-resume hold_attempts={probe.hold_attempts(0)}"
            )
        stdout = buffer.getvalue()
        self.assertTrue("day2-A1-resume hold_attempts=" in stdout, "captured output missing expected fragment (content withheld)")
        self.assertTrue("CLINIC_BOOKING_BUSY" in stdout, "captured output missing expected fragment (content withheld)")
        for token in ("SYNTH_MSG_TOKEN", "Deadlock found", "SELECT mobile"):
            self.assertTrue(token not in stdout, "captured output carried forbidden text (content withheld)")
            self.assertTrue(token not in repr(probe.entries), "derived output carried forbidden text (content withheld)")
            self.assertTrue(token not in projector.render(projector.project(stdout)), "captured output carried forbidden text (content withheld)")

    def test_hostile_busy_suffix_text_never_reaches_any_surface(self):
        """Claim B adversarial: hostile text inside the busy suffix.

        Through the real handler construction (`BusyJourneyFailure`) and
        the real `fail()`: text appended to the producer suffix must not
        appear in stdout, the stored results detail or the projection,
        while the closed diagnosis (stage, http, busy code, read, errno,
        busy state) still arrives intact.
        """
        harness = self.harness
        import day2_booking_busy as busy_mod
        entries = [
            {"route": "/wp-json/clinic/v1/booking/quote", "status": 200, "code": ""},
            {"route": "/wp-json/clinic/v1/booking/hold", "status": 503,
             "code": "CLINIC_BOOKING_BUSY", "slot_id": 42},
        ]
        row = ('{"read": "slot", "sql_errno": 1213}',)
        suffix = busy_mod.busy_suffix_for(
            entries, lambda sql: [row], "cpmswp_cpms_operational_logs",
            "390x844", since="2026-11-13 10:00:00",
        )
        hostile = "SYNTH_SUFFIX_TOKEN Deadlock found SELECT mobile"
        exc = harness.BusyJourneyFailure(
            suffix + " " + hostile + " msg=" + hostile,
            origin_text=(
                "public booking stage=hold-request — hold rejected "
                + hostile
            ),
        )
        harness.results.clear()
        harness.failures.clear()
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            harness.fail(
                "journey-A1-public",
                "Day-2 browser journey failed (journey-A1-public)",
                exc,
            )
        stdout = buffer.getvalue()
        detail = harness.results[-1]["detail"]
        fields = projector.project(stdout)
        self.assertTrue(fields["stage"] == "hold-request", "derived value mismatch (content withheld)")
        self.assertTrue(fields["http"] == "503", "derived value mismatch (content withheld)")
        self.assertTrue(fields["code"] == projector.BUSY_CODE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["read"] == "slot", "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno"] == "1213", "derived value mismatch (content withheld)")
        self.assertTrue(fields["busy"] == "present", "derived value mismatch (content withheld)")
        self.assertTrue(harness.failures == ["journey-A1-public"], "derived value mismatch (content withheld)")
        for token in ("SYNTH_SUFFIX_TOKEN", "Deadlock found", "SELECT mobile"):
            self.assertTrue(token not in stdout, "captured output carried forbidden text (content withheld)")
            self.assertTrue(token not in detail, "derived output carried forbidden text (content withheld)")
            self.assertTrue(token not in projector.render(fields), "derived output carried forbidden text (content withheld)")


class Day2ProbeClosedFieldsTest(_RedactedAssertions, unittest.TestCase):
    """C1/C2/C3/C4/C6 adversarial tests for the probe and busy attribution.

    Each test drives the REAL production code (BookingProbe._on_response,
    hold_attempts/describe/info, busy_suffix_for, BusyJourneyFailure,
    fail(), _validated_printable) with synthetic hostile input and asserts
    the hostile value reaches neither stdout, nor the stored results, nor
    the failure-report projection.
    """

    HOSTILE_CODES = (
        "CLINIC_VALIDATION_FAILED\n",
        "CLINIC_BOOKING_BUSY\nINJECTED",
        "09128887701",
        "SELECT_FROM_PATIENTS",
        "کد آزمایشی",
        "A" * 200,
        "clinic_validation_failed",
    )

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_c", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)
        import day2_booking_busy
        cls.busy = day2_booking_busy

    def _probe_with(self, payload, posted=None, status=503):
        class _Request:
            post_data_json = posted or {}

        class _Response:
            url = "http://fixture.test/wp-json/clinic/v1/booking/hold"
            request = _Request()

            def json(self):
                return payload

        _Response.status = status
        probe = self.harness.BookingProbe()
        probe._on_response(_Response())
        return probe

    def test_hostile_codes_become_other_and_never_print(self):
        """C1: only exact finite-set literals survive; the rest is `other`."""
        harness = self.harness
        for hostile in self.HOSTILE_CODES:
            with self.subTest(code="case"):
                probe = self._probe_with(
                    {"code": hostile, "data": {"code": hostile}},
                    posted={"slot_id": 7},
                )
                self.assertTrue(probe.entries[0]["code"] == "other", "derived value mismatch (content withheld)")
                buffer = io.StringIO()
                with redirect_stdout(buffer):
                    harness.info(
                        f"day2-A1-resume hold_attempts={probe.hold_attempts(0)}"
                    )
                    harness.info(f"day2-A1-rest {probe.describe(0)}")
                stdout = buffer.getvalue()
                self.assertTrue("code=other" in stdout, "captured output missing expected fragment (content withheld)")
                self.assertTrue(hostile not in stdout, "captured output carried forbidden text (content withheld)")
                self.assertTrue(hostile not in repr(probe.entries), "derived output carried forbidden text (content withheld)")
                self.assertTrue(hostile not in projector.render(projector.project(stdout)), "derived output carried forbidden text (content withheld)")
        nested = self._probe_with({"data": {"code": "SELECT_FROM_PATIENTS"}})
        self.assertTrue(nested.entries[0]["code"] == "other", "derived value mismatch (content withheld)")
        valid = self._probe_with({"code": "CLINIC_VALIDATION_FAILED"})
        self.assertTrue(valid.entries[0]["code"] == "CLINIC_VALIDATION_FAILED", "derived value mismatch (content withheld)")
        wp = self._probe_with({"code": "rest_cookie_invalid_nonce"}, status=403)
        self.assertTrue(wp.entries[0]["code"] == "rest_cookie_invalid_nonce", "derived value mismatch (content withheld)")
        absent = self._probe_with({"data": {"status": 503}})
        self.assertTrue(absent.entries[0]["code"] == "", "derived value mismatch (content withheld)")

    def test_phone_shaped_and_unbounded_posted_ids_are_omitted(self):
        """C3: posted ids above 2**31 - 1 never print.

        Corrected (round 14): this does NOT cover every phone-shaped
        value — a phone-shaped number at or below the bound (e.g. a
        10-digit 912,345,678) is retained and printed; the cases here
        use above-bound phone-shaped values (9,128,887,701 and its
        11-digit string form). See "Known limits" in the PR body.
        """
        probe = self._probe_with(
            {"code": "CLINIC_BOOKING_BUSY"},
            posted={"slot_id": 9128887701, "location_id": "09128887701",
                    "clinician_id": 42},
        )
        entry = probe.entries[0]
        self.assertTrue("slot_id" not in entry, "derived output carried forbidden text (content withheld)")
        self.assertTrue("location_id" not in entry, "derived output carried forbidden text (content withheld)")
        self.assertTrue(entry["clinician_id"] == 42, "derived value mismatch (content withheld)")
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            self.harness.info(
                f"day2-A1-resume hold_attempts={probe.hold_attempts(0)}"
            )
        self.assertTrue("9128887701" not in buffer.getvalue(), "captured output carried forbidden text (content withheld)")
        self.assertTrue("09128887701" not in buffer.getvalue(), "captured output carried forbidden text (content withheld)")
        at_bound = self._probe_with(
            {"code": "CLINIC_BOOKING_BUSY"}, posted={"slot_id": 2147483647}
        )
        self.assertTrue(at_bound.entries[0]["slot_id"] == 2147483647, "derived value mismatch (content withheld)")
        over_bound = self._probe_with(
            {"code": "CLINIC_BOOKING_BUSY"}, posted={"slot_id": 2147483648}
        )
        self.assertTrue("slot_id" not in over_bound.entries[0], "derived output carried forbidden text (content withheld)")

    def test_hostile_route_is_omitted_from_suffix_and_fail_line(self):
        """C2: only exact booking-route literals may carry a route= token."""
        busy = self.busy
        harness = self.harness
        for hostile in ("evil/route", "booking/hold\nINJECTED",
                        "booking/hold/extra", "SYNTH_ROUTE"):
            with self.subTest(route="case"):
                out = busy.validate_busy_suffix(
                    "busy_diag=booking.lock_read_failed read=slot "
                    f"sql_errno=1213 route={hostile} http=503"
                )
                # No hostile text survives; a route token may appear only
                # as the exact literal the whitespace split isolates.
                self.assertTrue(hostile not in out, "derived output carried forbidden text (content withheld)")
                self.assertTrue("INJECTED" not in out, "derived output carried forbidden text (content withheld)")
                for token in out.split():
                    if token.startswith("route="):
                        self.assertTrue(token in {
                            "route=v1/availability", "route=booking/quote",
                            "route=booking/hold", "route=booking/confirm",
                            "route=otp/request", "route=otp/verify",
                        }, "derived output missing expected fragment (content withheld)")
                direct = busy.format_busy_suffix(
                    {"read": "slot", "sql_errno": 1213},
                    route=hostile, http_status=503,
                )
                self.assertTrue("route=" not in direct, "derived output carried forbidden text (content withheld)")
        good = busy.validate_busy_suffix(
            "busy_diag=booking.lock_read_failed read=slot sql_errno=1213 "
            "route=booking/hold http=503 unknown_field=SYNTH_ROUTE_X"
        )
        self.assertTrue("route=booking/hold" in good, "derived output missing expected fragment (content withheld)")
        self.assertTrue("SYNTH_ROUTE_X" not in good, "derived output carried forbidden text (content withheld)")
        exc = harness.BusyJourneyFailure(good + " route=evil/route")
        harness.results.clear()
        harness.failures.clear()
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            harness.fail("journey-A1-public", "t", exc)
        self.assertTrue("route=booking/hold" in buffer.getvalue(), "captured output missing expected fragment (content withheld)")
        self.assertTrue("evil/route" not in buffer.getvalue(), "captured output carried forbidden text (content withheld)")

    def test_startup_info_values_reject_trailing_newlines(self):
        """C4: strict full-match or the fixed `invalid` token."""
        printable = self.harness._validated_printable
        time_pat = r"[0-9]{2}:[0-9]{2}:[0-9]{2}"
        self.assertTrue(printable("09:15:00", time_pat) == "09:15:00", "derived value mismatch (content withheld)")
        self.assertTrue(printable("09:15:00\n", time_pat) == "invalid", "derived value mismatch (content withheld)")
        self.assertTrue(printable("09:15:00\nINJECTED", time_pat) == "invalid", "derived value mismatch (content withheld)")
        self.assertTrue(printable("60", r"[0-9]{1,4}") == "60", "derived value mismatch (content withheld)")
        self.assertTrue(printable("60\n", r"[0-9]{1,4}") == "invalid", "derived value mismatch (content withheld)")
        version_pat = r"[0-9]{1,3}(\.[0-9]{1,6}){1,3}"
        self.assertTrue(printable("141.0.7390.65", version_pat) == "141.0.7390.65", "derived value mismatch (content withheld)")
        self.assertTrue(printable("141.0.7390.65\n", version_pat) == "invalid", "derived value mismatch (content withheld)")
        self.assertTrue(printable("not-a-version", version_pat) == "invalid", "derived value mismatch (content withheld)")
        self.assertTrue(printable(None, version_pat) == "invalid", "derived value mismatch (content withheld)")

    def test_busy_superseded_by_successful_retry_is_not_attributed(self):
        """C6: busy 503 -> same-route retry 200 -> confirm 200 -> later failure.

        The busy suffix must NOT be attached, so the projection must not
        claim busy; a genuine busy failure keeps the full expected line.
        """
        busy = self.busy
        harness = self.harness
        row = ('{"read": "slot", "sql_errno": 1213}',)
        query = lambda sql: [row]  # noqa: E731 - fixture row supplier
        superseded = [
            {"route": "/clinic/v1/booking/hold", "status": 503,
             "code": "CLINIC_BOOKING_BUSY", "slot_id": 42},
            {"route": "/clinic/v1/booking/hold", "status": 200,
             "code": "", "slot_id": 42},
            {"route": "/clinic/v1/booking/confirm", "status": 200, "code": ""},
        ]
        suffix = busy.busy_suffix_for(
            superseded, query, "cpmswp_cpms_operational_logs", "390x844",
            since="2026-11-13 10:00:00",
        )
        self.assertTrue(suffix == "", "derived value mismatch (content withheld)")
        harness.results.clear()
        harness.failures.clear()
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            harness.fail(
                "journey-A1-public",
                "Day-2 browser journey failed (journey-A1-public)",
                AssertionError("receipt reference_code format invalid"),
            )
        fields = projector.project(buffer.getvalue())
        self.assertTrue(fields["busy"] == "absent", "derived value mismatch (content withheld)")
        self.assertTrue(fields["code"] == projector.NONE, "derived value mismatch (content withheld)")
        self.assertTrue(fields["errno"] == projector.UNRECOGNISED, "derived value mismatch (content withheld)")

        genuine = [
            {"route": "/clinic/v1/booking/quote", "status": 200, "code": ""},
            {"route": "/clinic/v1/booking/hold", "status": 503,
             "code": "CLINIC_BOOKING_BUSY", "slot_id": 42},
        ]
        good_suffix = busy.busy_suffix_for(
            genuine, query, "cpmswp_cpms_operational_logs", "390x844",
            since="2026-11-13 10:00:00",
        )
        self.assertTrue("busy_diag=booking.lock_read_failed" in good_suffix, "derived output missing expected fragment (content withheld)")
        exc = harness.BusyJourneyFailure(
            good_suffix,
            origin_text="public booking stage=hold-request — hold rejected",
        )
        harness.results.clear()
        harness.failures.clear()
        buffer = io.StringIO()
        with redirect_stdout(buffer):
            harness.fail(
                "journey-A1-public",
                "Day-2 browser journey failed (journey-A1-public)",
                exc,
            )
        self.assertTrue(projector.render(projector.project(buffer.getvalue())) == "journey=journey-A1-public stage=hold-request http=503 "
            "code=CLINIC_BOOKING_BUSY read=slot errno=1213 "
            "errno_class=deadlock busy=present elapsed_s=unrecognised", "derived value mismatch (content withheld)")


class RoundFourProbeFieldsTest(_RedactedAssertions, unittest.TestCase):
    """D3/D5 adversarial tests for the probe's code and posted fields."""

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_r4", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)

    def _probe_with(self, payload, posted=None, status=503):
        class _Request:
            post_data_json = posted or {}

        class _Response:
            url = "http://fixture.test/wp-json/clinic/v1/booking/hold"
            request = _Request()

            def json(self):
                return payload

        _Response.status = status
        probe = self.harness.BookingProbe()
        probe._on_response(_Response())
        return probe

    def test_newly_derived_codes_are_retained_exactly(self):
        """D3: RestBase nonce + RuntimeSchemaGate codes are in the set."""
        for code, status in (
            ("CLINIC_INVALID_NONCE", 403),
            ("CLINIC_SERVICE_UNAVAILABLE", 503),
            ("CLINIC_DB_SCHEMA_PENDING_UNSUPPORTED_ENGINE", 503),
        ):
            with self.subTest(code="case"):
                probe = self._probe_with({"code": code}, status=status)
                self.assertTrue(probe.entries[0]["code"] == code, "derived value mismatch (content withheld)")

    def test_mixed_envelope_never_hides_a_busy_code(self):
        """D3: empty/unknown top-level code + nested CLINIC_BOOKING_BUSY."""
        empty_top = self._probe_with(
            {"code": "", "data": {"code": "CLINIC_BOOKING_BUSY"}})
        self.assertTrue(empty_top.entries[0]["code"] == "CLINIC_BOOKING_BUSY", "derived value mismatch (content withheld)")
        unknown_top = self._probe_with(
            {"code": "SOME_UNKNOWN_CODE", "data": {"code": "CLINIC_BOOKING_BUSY"}})
        self.assertTrue(unknown_top.entries[0]["code"] == "CLINIC_BOOKING_BUSY", "derived value mismatch (content withheld)")
        busy_top = self._probe_with(
            {"code": "CLINIC_BOOKING_BUSY", "data": {"code": "CLINIC_NOT_FOUND"}})
        self.assertTrue(busy_top.entries[0]["code"] == "CLINIC_BOOKING_BUSY", "derived value mismatch (content withheld)")
        # No busy anywhere: the first present field still decides.
        other = self._probe_with(
            {"code": "SOME_UNKNOWN_CODE", "data": {"code": "CLINIC_NOT_FOUND"}})
        self.assertTrue(other.entries[0]["code"] == "other", "derived value mismatch (content withheld)")

    def test_hostile_posted_ids_never_raise_and_are_omitted(self):
        """D5: non-ASCII digits / newline / very long ids are dropped
        inside the response callback — no exception escapes with the
        hostile value in a traceback."""
        hostile = {
            "superscript": "²",
            "arabic_indic": "٤٢",
            "persian": "۴۲",
            "trailing_newline": "42\n",
            "very_long": "9" * 30,
        }
        for label, value in hostile.items():
            with self.subTest(shape="case"):
                probe = self._probe_with(
                    {"code": "CLINIC_BOOKING_BUSY"},
                    posted={"slot_id": value, "clinician_id": 42},
                )
                entry = probe.entries[0]
                self.assertTrue("slot_id" not in entry, "derived output carried forbidden text (content withheld)")
                self.assertTrue(entry["clinician_id"] == 42, "derived value mismatch (content withheld)")
                self.assertTrue(value not in repr(entry), "derived output carried forbidden text (content withheld)")
        ascii_ok = self._probe_with(
            {"code": "CLINIC_BOOKING_BUSY"}, posted={"slot_id": "42"})
        self.assertTrue(ascii_ok.entries[0]["slot_id"] == 42, "derived value mismatch (content withheld)")

    def test_slot_coordinates_require_ascii_fullmatch(self):
        """D5: slot_date/slot_time accept ASCII full-matches only."""
        bad = self._probe_with(
            {"code": ""},
            posted={"slot_date": "٢٠٢٦-١١-١٤", "slot_time": "09:15:00\n"},
        )
        self.assertTrue("slot_date" not in bad.entries[0], "derived output carried forbidden text (content withheld)")
        self.assertTrue("slot_time" not in bad.entries[0], "derived output carried forbidden text (content withheld)")
        nl_date = self._probe_with(
            {"code": ""}, posted={"slot_date": "2026-11-14\n"})
        self.assertTrue("slot_date" not in nl_date.entries[0], "derived output carried forbidden text (content withheld)")
        good = self._probe_with(
            {"code": ""},
            posted={"slot_date": "2026-11-14", "slot_time": "09:15:00"},
        )
        self.assertTrue(good.entries[0]["slot_date"] == "2026-11-14", "derived value mismatch (content withheld)")
        self.assertTrue(good.entries[0]["slot_time"] == "09:15:00", "derived value mismatch (content withheld)")


class ConsolidatedRoundFieldsTest(_RedactedAssertions, unittest.TestCase):
    """Item 3: canonical dates/times, WP-core codes, viewport canon."""

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_r5", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)
        import day2_booking_busy
        cls.busy = day2_booking_busy

    def _probe_with(self, payload, posted=None, status=503):
        class _Request:
            post_data_json = posted or {}

        class _Response:
            url = "http://fixture.test/wp-json/clinic/v1/booking/hold"
            request = _Request()

            def json(self):
                return payload

        _Response.status = status
        probe = self.harness.BookingProbe()
        probe._on_response(_Response())
        return probe

    def test_invalid_and_hostile_dates_times_are_omitted(self):
        hostile = [
            {"slot_date": "2026-02-30"},
            {"slot_date": "2026-13-01"},
            {"slot_date": "٢٠٢٦-١١-١٤"},
            {"slot_date": "2026-11-14\n"},
            {"slot_date": "2026-11-14" + "0" * 500},
            {"slot_time": "24:00:00"},
            {"slot_time": "09:60:00"},
            {"slot_time": "09:15:60"},
            {"slot_time": "۰۹:۱۵:۰۰"},
        ]
        for posted in hostile:
            with self.subTest(posted="case"):
                entry = self._probe_with({"code": ""}, posted=posted).entries[0]
                self.assertTrue("slot_date" not in entry, "derived output carried forbidden text (content withheld)")
                self.assertTrue("slot_time" not in entry, "derived output carried forbidden text (content withheld)")

    def test_valid_date_time_are_stored_canonically(self):
        entry = self._probe_with(
            {"code": ""},
            posted={"slot_date": "2026-11-14", "slot_time": "09:15"},
        ).entries[0]
        self.assertTrue(entry["slot_date"] == "2026-11-14", "derived value mismatch (content withheld)")
        self.assertTrue(entry["slot_time"] == "09:15:00", "derived value mismatch (content withheld)")
        self.assertTrue(self.harness._canonical_date("2026-02-29") is None, "derived value mismatch (content withheld)")
        self.assertTrue(self.harness._canonical_date("2024-02-29") == "2024-02-29", "derived value mismatch (content withheld)")
        self.assertTrue(self.harness._canonical_time("23:59:59") == "23:59:59", "derived value mismatch (content withheld)")
        self.assertTrue(self.harness._canonical_time("99:99:99") is None, "derived value mismatch (content withheld)")

    def test_reachable_wordpress_core_codes_are_retained(self):
        for code in ("rest_cookie_invalid_nonce", "rest_forbidden",
                     "rest_invalid_json", "rest_invalid_param",
                     "rest_invalid_params", "rest_missing_callback_param",
                     "rest_no_route"):
            with self.subTest(code="case"):
                probe = self._probe_with({"code": code}, status=403)
                self.assertTrue(probe.entries[0]["code"] == code, "derived value mismatch (content withheld)")
        unknown = self._probe_with({"code": "rest_made_up_code"}, status=403)
        self.assertTrue(unknown.entries[0]["code"] == "other", "derived value mismatch (content withheld)")

    def test_viewport_is_canonical_ascii_or_omitted(self):
        busy = self.busy
        unicode_vp = busy.validate_busy_suffix(
            "busy_diag=booking.lock_read_failed read=slot sql_errno=1213 "
            "route=booking/hold http=503 viewport=٣٩٠x٨٤٤"
        )
        self.assertTrue("viewport=" not in unicode_vp, "derived output carried forbidden text (content withheld)")
        good = busy.format_busy_suffix(
            {"read": "slot", "sql_errno": 1213},
            route="booking/hold", http_status=503, viewport="0390x0844",
        )
        self.assertTrue("viewport=390x844" in good, "derived output missing expected fragment (content withheld)")


class RoundSevenBoardAndStartupTest(_RedactedAssertions, unittest.TestCase):
    """F4 (canonical board date) + F5 (fixed startup output)."""

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_r7", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)

    def test_board_date_must_be_a_real_canonical_date(self):
        parse = self.harness.parse_board_envelope
        good = parse({"data": {"date": "2026-11-14", "appointments": []}})
        self.assertTrue(good["date"] == "2026-11-14", "derived value mismatch (content withheld)")
        for bad in ("2026-02-30", "2026-13-01", "٢٠٢٦-١١-١٤",
                    "2026-11-14\n", "2026-11-14 "):
            with self.subTest(date="case"):
                with self.assertRaises(AssertionError):
                    parse({"data": {"date": bad, "appointments": []}})

    def test_startup_failure_prints_only_the_fixed_token(self):
        """F5: a pre-journey failure (import/config/browser startup)
        must not traceback with values to stderr."""
        harness = self.harness
        original = harness.main
        harness.main = lambda: (_ for _ in ()).throw(  # noqa: E731
            ValueError("invalid literal: SYNTH_STARTUP_TOKEN"))
        try:
            stderr = io.StringIO()
            with redirect_stderr(stderr):
                code = harness._entry()
        finally:
            harness.main = original
        self.assertTrue(code == 1, "derived value mismatch (content withheld)")
        # Boolean compares with fixed messages: the captured stream is
        # never an assertion operand, so a failure diff cannot
        # republish captured text.
        self.assertTrue(
            stderr.getvalue() == "DAY2_HARNESS_ERROR stage=startup detail_withheld=1\n",
            "startup failure must print only the fixed token")
        self.assertTrue("SYNTH_STARTUP_TOKEN" not in stderr.getvalue(),
                        "startup token must not carry exception text")


class RoundEightStartupGuardTest(_RedactedAssertions, unittest.TestCase):
    """D: _entry handles BaseException with fixed-token output only."""

    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("day2_harness_r8", HARNESS)
        sys.path.insert(0, str(BIN_DIR))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)

    def _run_with_main(self, main):
        harness = self.harness
        original = harness.main
        harness.main = main
        try:
            stderr = io.StringIO()
            with redirect_stderr(stderr):
                code = harness._entry()
        finally:
            harness.main = original
        return code, stderr.getvalue()

    def test_systemexit_with_hostile_text_prints_only_token(self):
        def main():
            raise SystemExit("Missing required environment variable: SYNTH_SECRET_R8")

        code, err = self._run_with_main(main)
        self.assertTrue(code == 1, "derived value mismatch (content withheld)")
        self.assertTrue(
            err == "DAY2_HARNESS_ERROR stage=startup detail_withheld=1\n",
            "SystemExit text must be replaced by the fixed token")
        self.assertTrue("SYNTH_SECRET_R8" not in err,
                        "SystemExit text must never reach stderr")

    def test_systemexit_zero_and_none_stay_silent_success(self):
        for exc in (SystemExit(0), SystemExit(None), SystemExit()):
            def main(exc=exc):
                raise exc

            code, err = self._run_with_main(main)
            self.assertTrue(code == 0, "derived value mismatch (content withheld)")
            self.assertTrue(err == "", "successful SystemExit must stay silent")

    def test_systemexit_nonzero_int_is_preserved_silently(self):
        # Item A classification: a genuine int status in 1..255 is
        # preserved exactly and silently (no payload text exists to
        # leak; only non-int / out-of-range payloads get the token).
        def main():
            raise SystemExit(3)

        code, err = self._run_with_main(main)
        self.assertTrue(code == 3, "derived value mismatch (content withheld)")
        self.assertTrue(err == "", "int status in 1..255 must be preserved silently")

    def test_keyboard_interrupt_prints_only_token(self):
        def main():
            raise KeyboardInterrupt("SYNTH_SECRET_R8")

        code, err = self._run_with_main(main)
        self.assertTrue(code != 0, "derived values unexpectedly equal (content withheld)")
        self.assertTrue(
            err == "DAY2_HARNESS_ERROR stage=startup detail_withheld=1\n",
            "KeyboardInterrupt must print only the fixed token")
        self.assertTrue("SYNTH_SECRET_R8" not in err,
                        "KeyboardInterrupt text must never reach stderr")

    def test_token_print_failure_does_not_raise(self):
        class BrokenStderr:
            def write(self, _text):
                raise BrokenPipeError("closed")

            def flush(self):
                raise BrokenPipeError("closed")

        harness = self.harness
        original_main, original_stderr = harness.main, sys.stderr
        harness.main = lambda: (_ for _ in ()).throw(  # noqa: E731
            ValueError("SYNTH_SECRET_R8"))
        try:
            sys.stderr = BrokenStderr()
            code = harness._entry()
        finally:
            harness.main, sys.stderr = original_main, original_stderr
        self.assertTrue(code == 1, "derived value mismatch (content withheld)")


class RoundTenExitStatusTest(_RedactedAssertions, unittest.TestCase):
    """D: the REAL process exit status of the _entry SystemExit mapping
    (verified via subprocess, not just _entry's return value): a
    nonzero multiple of 256 must not truncate to status 0."""

    DRIVER = (
        "import importlib.util, sys\n"
        "sys.path.insert(0, {bin_dir!r})\n"
        "spec = importlib.util.spec_from_file_location('h', {harness!r})\n"
        "m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)\n"
        "payload = sys.argv[1]\n"
        "class EqBoom:\n"
        "    def __eq__(self, other): raise RuntimeError('SYNTH_SECRET_R11')\n"
        "    def __ne__(self, other): raise RuntimeError('SYNTH_SECRET_R11')\n"
        "class StrBoom:\n"
        "    def __str__(self): return 'SYNTH_SECRET_R11'\n"
        "    def __repr__(self): return 'SYNTH_SECRET_R11'\n"
        "def main():\n"
        "    if payload == 'NONE': raise SystemExit(None)\n"
        "    if payload == 'TEXT': raise SystemExit('SYNTH_SECRET_R11')\n"
        "    if payload == 'KI': raise KeyboardInterrupt\n"
        "    if payload == 'FALSE': raise SystemExit(False)\n"
        "    if payload == 'TRUE': raise SystemExit(True)\n"
        "    if payload == 'FLOAT0': raise SystemExit(0.0)\n"
        "    if payload == 'FLOAT1': raise SystemExit(1.0)\n"
        "    if payload == 'BYTES': raise SystemExit(b'x')\n"
        "    if payload == 'EQOBJ': raise SystemExit(EqBoom())\n"
        "    if payload == 'STROBJ': raise SystemExit(StrBoom())\n"
        "    if payload == 'BIG': raise SystemExit(2**70)\n"
        "    raise SystemExit(int(payload))\n"
        "m.main = main\n"
        "raise SystemExit(m._entry())\n"
    )

    def _run(self, payload):
        driver = self.DRIVER.format(bin_dir=str(BIN_DIR), harness=str(HARNESS))
        result = _run_subprocess(self,
            [sys.executable, "-c", driver, payload],
            timeout=60,
        )
        return result.returncode, result.stderr

    def test_real_exit_statuses(self):
        token = "DAY2_HARNESS_ERROR stage=startup detail_withheld=1\n"
        cases = {
            # Exact-type classification (Item A): None and int 0 are
            # silent success; int 1..255 are preserved silently;
            # EVERYTHING else is the fixed token + 1 (bool, float,
            # bytes, hostile objects, out-of-range ints); KI -> 130.
            "0": (0, ""), "NONE": (0, ""), "1": (1, ""), "255": (255, ""),
            "256": (1, token), "-256": (1, token), "512": (1, token),
            "-1": (1, token), "257": (1, token), "511": (1, token),
            "BIG": (1, token), "FALSE": (1, token), "TRUE": (1, token),
            "FLOAT0": (1, token), "FLOAT1": (1, token),
            "BYTES": (1, token), "EQOBJ": (1, token),
            "STROBJ": (1, token), "TEXT": (1, token), "KI": (130, token),
        }
        for payload, (want_code, want_err) in cases.items():
            with self.subTest(payload="case"):
                code, err = self._run(payload)
                self.assertTrue(code == want_code,
                                "real exit status mismatch (value withheld)")
                self.assertTrue(err == want_err,
                                "stderr must be empty or only the fixed token")
                self.assertTrue("SYNTH_SECRET_R11" not in err,
                                "SystemExit payload must never reach stderr")



#: Assertion methods verified (CPython 3.11 unittest/case.py) to
#: route their failure message through _formatMessage. Anything else
#: (assertSequenceEqual & friends raise failureException directly
#: with operand reprs) is forbidden in the two Day-2 suites.
_SAFE_ASSERTION_METHODS = frozenset({
    "assertTrue", "assertFalse", "assertEqual", "assertNotEqual",
    "assertIn", "assertNotIn", "assertRegex", "assertNotRegex",
    "assertLess", "assertLessEqual", "assertGreater",
    "assertGreaterEqual", "assertRaises",
})


_BYPASSING_ASSERTION_METHODS = frozenset({
    "assertSequenceEqual", "assertListEqual", "assertTupleEqual",
    "assertSetEqual", "assertDictEqual", "assertMultiLineEqual",
    "assertCountEqual", "assertDictContainsSubset",
    "assertAlmostEqual", "assertNotAlmostEqual", "assertWarns",
    "assertWarnsRegex", "assertRaisesRegex", "assertLogs",
    "assertNoLogs", "assertIsInstance", "assertNotIsInstance",
})


def _unsafe_assertion_calls(source):
    """Flag any use that could reach a bypassing assertion helper.

    Scans every attribute/name REFERENCE (not only calls): aliased
    references (``f = self.assertSequenceEqual``), bare assert*/fail*
    names, and dynamic access (``getattr``/``setattr``/
    ``__getattribute__``) with a non-literal or assert*/fail* target
    are all flagged, so the allowlist cannot be dodged by those\n    indirection forms (eval/exec of strings is out of scope).
    """
    bad = []
    for node in ast.walk(ast.parse(source)):
        # unittest assertion helpers are camelCase after the prefix
        # (assertEqual, assertSetEqual, ...); snake_case attributes
        # (assert_closed, assert_no_values, .asserts) are this
        # suite's own helpers, not unittest assertions.
        if isinstance(node, ast.Attribute) and (
            (node.attr.startswith("assert") and len(node.attr) > 6 and node.attr[6].isupper())
            or node.attr in _BYPASSING_ASSERTION_METHODS
        ):
            if node.attr not in _SAFE_ASSERTION_METHODS:
                bad.append(node.attr)
        elif isinstance(node, ast.Name) and (
            (node.id.startswith("assert") and len(node.id) > 6 and node.id[6].isupper())
            or node.id in _BYPASSING_ASSERTION_METHODS
        ):
            bad.append(node.id)
        elif (
            isinstance(node, ast.Call)
            and isinstance(node.func, ast.Name)
            and node.func.id in ("getattr", "setattr", "__getattribute__")
        ):
            target = node.args[1] if len(node.args) >= 2 else None
            if not (isinstance(target, ast.Constant)
                    and isinstance(target.value, str)):
                bad.append(node.func.id + "(dynamic)")
            elif target.value.startswith(("assert", "fail")):
                bad.append("getattr(assert*)")
    return bad


class AssertionOutputRedactionTest(_RedactedAssertions, unittest.TestCase):
    """A3: the _RedactedAssertions mixin structurally withholds operands.

    A throwaway case fails six ways with a synthetic secret marker as
    (or inside) the operand; with the mixin the runner output must not
    contain the marker, while every probe case still runs and fails.
    (Pre-fix evidence, observed by the author with a plain
    unittest.TestCase in-process: the marker DID appear in the
    TextTestRunner output for the identical six probes.)
    """

    def test_assertion_failure_output_never_contains_operands(self):
        secret = "SYNTH_SECRET_R12_MARKER"

        class _SecretObject:
            def __bool__(self):
                return False

            def __repr__(self):
                return secret

        def make_probes(mixin):
            class _Throwaway(mixin, unittest.TestCase):
                def test_probe_equal(self):
                    self.assertEqual(secret, "other")

                def test_probe_in(self):
                    self.assertIn(secret, "abc")

                def test_probe_not_in(self):
                    self.assertNotIn("MARKER", secret)

                def test_probe_regex(self):
                    self.assertRegex(secret, r"ZZZ-NOMATCH")

                def test_probe_true(self):
                    self.assertTrue(_SecretObject())

                def test_probe_raises(self):
                    with self.assertRaises(ValueError):
                        pass

            return _Throwaway

        class _RecordingResult(unittest.TextTestResult):
            def __init__(self, *args, **kwargs):
                super().__init__(*args, **kwargs)
                self.exc_types = []

            def addFailure(self, test, err):
                self.exc_types.append(err[0])
                super().addFailure(test, err)

            def addError(self, test, err):
                self.exc_types.append(err[0])
                super().addError(test, err)

        def run_probes(mixin):
            suite = unittest.TestLoader().loadTestsFromTestCase(make_probes(mixin))
            stream = io.StringIO()
            runner = unittest.TextTestRunner(
                stream=stream, verbosity=2, resultclass=_RecordingResult
            )
            return runner.run(suite), stream.getvalue()

        def meta_ok(result, output):
            # Strict: exactly six assertion FAILURES (each recorded as
            # an AssertionError), ZERO errors, marker absent,
            # placeholder present. A mixin that raises instead of
            # formatting produces errors and must NOT satisfy this.
            return (
                result.testsRun == 6
                and len(result.failures) == 6
                and len(result.errors) == 0
                and all(exc is AssertionError for exc in result.exc_types)
                and secret not in output
                and "assertion failed (details withheld)" in output
            )

        result, output = run_probes(_RedactedAssertions)
        self.assertTrue(all(exc is AssertionError for exc in result.exc_types),
                        "every redaction probe failure must be an AssertionError")
        self.assertTrue(meta_ok(result, output),
                        "redacted mixin must yield six AssertionError failures, "
                        "zero errors, no operands, fixed placeholder")

        class _BrokenMixin:
            # Raises with the placeholder TEXT as the exception
            # message: marker absent AND placeholder present in the
            # runner output, but as ERRORS, not failures — the case
            # that distinguishes the strict predicate (round 14).
            def _formatMessage(self, msg, standardMsg):
                raise RuntimeError("assertion failed (details withheld)")

            def fail(self, msg=None):
                raise RuntimeError("assertion failed (details withheld)")

        def old_predicate(result, output):
            # The round-13 predicate: errors counted as failures.
            return (
                len(result.failures) + len(result.errors) == 6
                and secret not in output
                and "assertion failed (details withheld)" in output
            )

        broken_result, broken_output = run_probes(_BrokenMixin)
        self.assertTrue(len(broken_result.errors) > 0,
                        "broken mixin must produce errors, not clean failures")
        self.assertTrue(old_predicate(broken_result, broken_output),
                        "old predicate must (wrongly) accept the broken mixin — "
                        "this is the gap the strict predicate closes")
        self.assertTrue(not meta_ok(broken_result, broken_output),
                        "meta-condition must reject a broken mixin (errors are not failures)")

    def test_set_equality_cannot_reach_the_bypassing_helper(self):
        secret = "SYNTH_SECRET_R15_SET"

        class _Hostile:
            def __hash__(self):
                return 42

            def __eq__(self, other):
                if other is self:
                    return True
                raise TypeError(secret)

        # Size mismatch makes `set1 == set2` False WITHOUT calling
        # the hostile __eq__ (so _baseAssertEqual records a clean
        # failure), while assertSetEqual's difference raises
        # TypeError(secret) first (its fail path embeds the text).
        set1 = {_Hostile(), "filler"}
        set2 = {_Hostile()}

        def run(case_mixin):
            class _Probe(case_mixin, unittest.TestCase):
                def test_probe(self):
                    self.assertEqual(set1, set2)

            suite = unittest.TestLoader().loadTestsFromTestCase(_Probe)
            stream = io.StringIO()
            result = unittest.TextTestRunner(stream=stream, verbosity=0).run(suite)
            return result, stream.getvalue()

        class _WithoutOverride(_RedactedAssertions, unittest.TestCase):
            _getAssertEqualityFunc = unittest.TestCase._getAssertEqualityFunc

        bare_result, bare_output = run(_WithoutOverride)
        self.assertTrue(len(bare_result.failures) + len(bare_result.errors) == 1,
                        "set probe without the override must fail exactly once")
        self.assertTrue(secret in bare_output,
                        "without the dispatch override the set-difference "
                        "TypeError text reaches runner output (probe must detect it)")

        fixed_result, fixed_output = run(_RedactedAssertions)
        self.assertTrue(len(fixed_result.failures) == 1 and len(fixed_result.errors) == 0,
                        "set probe with the override must be one clean failure")
        self.assertTrue(secret not in fixed_output,
                        "set operands must never reach runner output (content withheld)")

    def test_assertion_allowlist_guard_catches_indirection(self):
        snippets = {
            "alias": "f = self.assertSequenceEqual\nf(a, b)\n",
            "getattr": "getattr(self, 'assertListEqual')(a, b)\n",
            "dynamic getattr": "getattr(self, name)(a, b)\n",
            "wrapper": "def w(a, b):\n    return self.assertSetEqual(a, b)\n",
            "bare name": "g = assertDictEqual\n",
        }
        for label, snippet in snippets.items():
            self.assertTrue(_unsafe_assertion_calls(snippet) != [],
                            "allowlist guard must flag indirection: " + label)
        self.assertTrue(
            _unsafe_assertion_calls("self.assertTrue(a == b)\nself.fail('fixed')\n") == [],
            "allowlist guard must accept routed assertions and fixed fail()",
        )

    def test_subprocess_timeout_cannot_print_the_driver(self):
        marker = "SYNTH_SECRET_R14_TIMEOUT"

        class _TimeoutCase(_RedactedAssertions, unittest.TestCase):
            def test_probe(self):
                original_run = subprocess.run

                def _raising_run(*args, **kwargs):
                    raise subprocess.TimeoutExpired(
                        cmd=["python", "-c", marker], timeout=1
                    )

                subprocess.run = _raising_run
                try:
                    _run_subprocess(self, ["python", "-c", marker], timeout=1)
                finally:
                    subprocess.run = original_run

        suite = unittest.TestLoader().loadTestsFromTestCase(_TimeoutCase)
        stream = io.StringIO()
        result = unittest.TextTestRunner(stream=stream, verbosity=2).run(suite)
        output = stream.getvalue()
        self.assertTrue(len(result.failures) == 1 and len(result.errors) == 0,
                        "timeout probe must surface as one fixed failure, not an error")
        self.assertTrue(marker not in output,
                        "timeout failure must not contain the command text (content withheld)")
        self.assertTrue("subprocess driver did not complete (details withheld)" in output,
                        "timeout failure must carry the fixed message (content withheld)")

    def test_only_format_message_routed_assertions_are_used(self):
        here = Path(__file__).resolve().parent
        for name in ("test_pilot_day2_failure_report.py",
                     "test_day2_booking_busy.py"):
            bad = _unsafe_assertion_calls((here / name).read_text(encoding="utf-8"))
            self.assertTrue(bad == [],
                            "assertion methods outside the _formatMessage-routed "
                            "allowlist are forbidden (content withheld)")
        # The checker itself must be able to fail:
        self.assertTrue(
            _unsafe_assertion_calls("self.assertSequenceEqual(a, b)\n")
            == ["assertSequenceEqual"],
            "allowlist checker must flag a bypassing assertion method",
        )
        self.assertTrue(
            _unsafe_assertion_calls("self.assertTrue(a == b)\n") == [],
            "allowlist checker must accept routed assertion methods",
        )

if __name__ == "__main__":
    unittest.main()

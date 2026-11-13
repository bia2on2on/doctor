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
import importlib.util
import io
import os
import re
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import redirect_stdout
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


class VocabularyDerivationTest(unittest.TestCase):
    """The projector's vocabularies must be the ones the real code prints."""

    def test_journeys_are_exactly_the_harness_main_loop_ids(self):
        source = HARNESS.read_text(encoding="utf-8")
        printed = re.findall(r'\("(journey-[A-Za-z0-9-]+)", lambda: run_journey_', source)
        self.assertEqual(sorted(printed), sorted(projector.JOURNEYS))
        self.assertEqual(len(printed), 5)

    def test_stages_are_exactly_the_harness_visible_or_diagnose_markers(self):
        source = HARNESS.read_text(encoding="utf-8")
        markers = set(
            re.findall(r"visible_or_diagnose\(\s*[^,]+,\s*[^,]+,\s*\"([a-z0-9-]+)\"", source)
        )
        markers |= set(re.findall(r'public booking stage=([a-z0-9-]+)', source))
        self.assertEqual(len(markers), 7)
        self.assertIn("hold-request", markers)
        self.assertEqual(sorted(markers), sorted(projector.STAGES))

    def test_reads_are_exactly_the_bookingservice_assertion_labels(self):
        source = BOOKING_SERVICE.read_text(encoding="utf-8")
        labels = set(
            re.findall(r"assert_read_succeeded\(\s*'([a-z0-9_]+)'", source)
        )
        self.assertEqual(sorted(labels), sorted(projector.READS))
        self.assertEqual(len(labels), 16)

    def test_errno_classes_are_the_documented_closed_set(self):
        self.assertEqual(
            projector.ERRNO_CLASSES, ("deadlock", "lock_wait_timeout", "other", "unknown")
        )
        self.assertEqual(projector.errno_class_for("1213"), "deadlock")
        self.assertEqual(projector.errno_class_for("1205"), "lock_wait_timeout")
        self.assertEqual(projector.errno_class_for("1062"), "other")
        # `(int) $dbh->errno` is 0 when no driver error is pending (and on a
        # non-mysqli handle), so a zero errno is "no cause class", not `other`.
        self.assertEqual(projector.errno_class_for("0"), "unknown")
        self.assertEqual(projector.errno_class_for(projector.UNRECOGNISED), "unknown")


class ProjectionTableTest(unittest.TestCase):
    """A table of realistic and hostile inputs, each with its expected line."""

    def project(self, text):
        return projector.render(projector.project(text))

    def test_real_busy_deadlock_failure(self):
        text = "INFO day2-journey-timing journey=journey-A1-public elapsed_s=51\n" + failure_line()
        self.assertEqual(
            self.project(text),
            "journey=journey-A1-public stage=hold-countdown http=503 "
            "code=CLINIC_BOOKING_BUSY read=slot errno=1213 errno_class=deadlock "
            "busy=present elapsed_s=51",
        )

    def test_lock_wait_timeout_is_its_own_class(self):
        line = failure_line().replace("sql_errno=1213", "sql_errno=1205")
        fields = projector.project(line)
        self.assertEqual(fields["errno"], "1205")
        self.assertEqual(fields["errno_class"], "lock_wait_timeout")

    def test_other_errno_is_other_and_still_digits_only(self):
        line = failure_line().replace("sql_errno=1213", "sql_errno=1062")
        fields = projector.project(line)
        self.assertEqual((fields["errno"], fields["errno_class"]), ("1062", "other"))

    def test_absent_errno_is_unknown_not_zero(self):
        line = (
            "FAIL journey-B-reception — Day-2 browser journey failed "
            "(journey-B-reception) — reception board load failed"
        )
        fields = projector.project(line)
        self.assertEqual((fields["errno"], fields["errno_class"]), ("unrecognised", "unknown"))
        self.assertEqual(fields["stage"], "none")
        self.assertEqual(fields["code"], "none")
        self.assertEqual(fields["http"], "unrecognised")

    def test_busy_http_followed_by_hold_http_reports_the_first(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-request — hold rejected for the fixture slot "
            "slot=42 http=503 code=CLINIC_BOOKING_BUSY | journey=A1-public "
            "busy_diag=booking.lock_read_failed read=persisted_slot sql_errno=1213"
        )
        fields = projector.project(line)
        self.assertEqual((fields["stage"], fields["http"]), ("hold-request", "503"))
        self.assertEqual(fields["read"], "persisted_slot")

    def test_http_word_form_is_accepted(self):
        line = (
            "FAIL journey-A2-reschedule — Day-2 browser journey failed "
            "(journey-A2-reschedule) — booking/confirm HTTP 409 then retry"
        )
        self.assertEqual(projector.project(line)["http"], "409")

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
        self.assertEqual(
            self.project(text),
            "journey=none stage=none http=unrecognised code=none "
            "read=none errno=unrecognised errno_class=unknown busy=absent "
            "elapsed_s=12",
        )

    def test_failing_run_without_a_fail_line_does_not_name_a_journey(self):
        """H3: the dangerous case — a nonzero exit with no FAIL line at all."""
        text = (
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=12\n"
            "INFO day2-journey-timing journey=journey-B-reception elapsed_s=31\n"
            "Traceback (most recent call last):\nSystemExit: 1\n"
        )
        fields = projector.project(text)
        self.assertEqual(fields["journey"], "none")
        # The last timing line is still the source of elapsed_s.
        self.assertEqual(fields["elapsed_s"], "31")

    def test_log_with_no_timing_and_no_failure_is_none_journey(self):
        self.assertEqual(
            self.project("python3: start\n"),
            "journey=none stage=none http=unrecognised code=none read=none "
            "errno=unrecognised errno_class=unknown busy=absent "
            "elapsed_s=unrecognised",
        )

    def test_http_is_the_status_paired_with_the_busy_code(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown root_state=bookable visible=0 "
            "observed=hold/quote http=403 code=rest_cookie_invalid_nonce; "
            "hold/hold http=503 code=CLINIC_BOOKING_BUSY | journey=A1-public "
            "busy_diag=booking.lock_read_failed read=slot sql_errno=1205"
        )
        fields = projector.project(line)
        self.assertEqual(fields["http"], "503")
        self.assertEqual(fields["code"], projector.BUSY_CODE)
        self.assertEqual(fields["errno_class"], "lock_wait_timeout")

    def test_non_busy_code_is_other_and_keeps_its_own_status(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=otp-code observed=otp/verify http=429 "
            "code=rest_too_many_requests"
        )
        fields = projector.project(line)
        self.assertEqual((fields["http"], fields["code"]), ("429", "other"))

    def test_busy_code_elsewhere_in_the_line_still_wins(self):
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "observed=hold/hold http=500 code=internal_error; http=503 "
            "code=CLINIC_BOOKING_BUSY"
        )
        fields = projector.project(line)
        self.assertEqual(fields["http"], "503")
        self.assertEqual(fields["code"], projector.BUSY_CODE)

    def test_missing_log_is_unrecognised_not_none(self):
        self.assertEqual(
            self.project(None),
            "journey=unrecognised stage=none http=unrecognised code=none read=none "
            "errno=unrecognised errno_class=unknown busy=absent "
            "elapsed_s=unrecognised",
        )

    def test_foreign_fail_key_is_unrecognised(self):
        line = "FAIL day2-A1-otp-identity-confirm — something else entirely"
        fields = projector.project(line)
        self.assertEqual(fields["journey"], projector.UNRECOGNISED)

    def test_first_fail_line_wins(self):
        text = (
            "FAIL journey-C-doctors — Day-2 browser journey failed (journey-C-doctors) — b\n"
            "FAIL journey-D-accountant — Day-2 browser journey failed (journey-D-accountant) — c\n"
        )
        self.assertEqual(projector.project(text)["journey"], "journey-C-doctors")


class HostileInputTest(unittest.TestCase):
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
        self.assertEqual(tuple(fields), projector.FIELDS)
        self.assertTrue(projector.is_closed(fields), rendered)
        # Canonical integers only: no padding, no sign, no separator.
        for name in ("http", "errno", "elapsed_s"):
            self.assertNotRegex(fields[name], r"^0[0-9]")

    def test_hostile_payload_in_extra_position_never_survives(self):
        for label, payload in self.HOSTILE.items():
            with self.subTest(label=label):
                text = failure_line(payload)
                rendered = self.render_text(text)
                self.assert_closed(rendered)
                for canary in (
                    CANARY_TOKEN, CANARY_EMAIL, CANARY_URL, CANARY_PATH,
                    "SELECT", "09128887701", "example.invalid", "wp-content",
                    "admin", "::", "##[", ANSI, "\x1b",
                ):
                    self.assertNotIn(canary, rendered)
                self.assertNotIn("\n", rendered)
                self.assertNotIn("\r", rendered)

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
            with self.subTest(field=field):
                text = failure_line().replace(
                    {"stage": "stage=hold-countdown", "http": "http=503",
                     "code": "code=CLINIC_BOOKING_BUSY", "read": "read=slot",
                     "sql_errno": "sql_errno=1213"}[field],
                    injected,
                    1,
                )
                fields = projector.project(text)
                self.assertTrue(projector.is_closed(fields))
                self.assertIn(
                    fields[output_field.get(field, field)],
                    (projector.UNRECOGNISED, "other", "unknown"),
                )
                rendered = projector.render(fields)
                self.assertNotIn(CANARY_TOKEN, rendered)
                self.assertNotIn(CANARY_EMAIL, rendered)

    def test_leading_zero_and_long_numbers_are_not_integers(self):
        for raw in ("007", "000000", "099999", "1" * 40, "-1", "+1", "1e3", " 1213", "1213 "):
            with self.subTest(raw=raw[:12]):
                self.assertEqual(
                    projector.int_token(raw, 0, 99999), projector.UNRECOGNISED
                )
        self.assertEqual(projector.int_token("1213", 0, 99999), "1213")

    def test_integer_bounds_are_enforced(self):
        self.assertEqual(projector.int_token("99", 100, 599), projector.UNRECOGNISED)
        self.assertEqual(projector.int_token("600", 100, 599), projector.UNRECOGNISED)
        self.assertEqual(projector.int_token("100", 100, 599), "100")
        self.assertEqual(projector.int_token("599", 100, 599), "599")
        self.assertEqual(projector.int_token("999999", 0, 99999), projector.UNRECOGNISED)

    def test_all_hostile_log_is_still_one_closed_line(self):
        text = "\n".join(
            ["FAIL " + CANARY_TOKEN + " " + payload for payload in self.HOSTILE.values()]
            + [payload for payload in self.HOSTILE.values()]
        )
        rendered = self.render_text(text)
        self.assert_closed(rendered)
        self.assertEqual(len(rendered.splitlines()), 1)
        self.assertEqual(
            rendered.split(" ")[0], "journey=" + projector.UNRECOGNISED
        )

    def test_render_rejects_a_hand_built_dirty_mapping(self):
        dirty = projector.project(failure_line())
        dirty["stage"] = CANARY_TOKEN
        rendered = projector.render(dirty)
        self.assertNotIn(CANARY_TOKEN, rendered)
        self.assertIn("journey=unrecognised", rendered)

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
            with self.subTest(mutation=str(mutation)[:80]):
                self.assertFalse(projector.is_closed(mutation))


class CommandLineTest(unittest.TestCase):
    """The CLI must be total and must never leak a traceback or raw text."""

    SCRIPT = BIN_DIR / "pilot-day2-failure-report.py"

    def run_script(self, *args):
        return subprocess.run(
            [sys.executable, str(self.SCRIPT), *args],
            capture_output=True,
            text=True,
            check=False,
        )

    def test_missing_log_exits_zero_with_one_closed_line(self):
        result = self.run_script("--log", "/nonexistent/definitely-absent.log")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.count("\n"), 1)
        self.assertTrue(result.stdout.startswith("journey=unrecognised "))
        self.assertEqual(result.stderr, "")

    def test_directory_as_log_is_unreadable_but_total(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = self.run_script("--log", tmp)
        self.assertEqual(result.returncode, 0, result.stderr)
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
        self.assertEqual(result.returncode, 0, result.stderr)
        for canary in (CANARY_TOKEN, CANARY_EMAIL, CANARY_URL, CANARY_PATH, "SELECT", "::"):
            self.assertNotIn(canary, result.stdout)
        self.assertIn("journey=journey-A1-public stage=hold-countdown http=503", result.stdout)

    def test_invalid_utf8_and_nul_bytes_are_tolerated(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "day2.log"
            path.write_bytes(b"\xff\xfe\x00FAIL journey-A1-public \x00\xff" + failure_line().encode())
            result = self.run_script("--log", str(path))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.count("\n"), 1)

    def test_field_mode_prints_exactly_one_token(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "day2.log"
            path.write_text(failure_line(), encoding="utf-8")
            for field in projector.FIELDS:
                with self.subTest(field=field):
                    result = self.run_script("--log", str(path), "--field", field)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    token = result.stdout.rstrip("\n")
                    self.assertNotIn(" ", token)
                    self.assertNotIn("=", token)
                    self.assertLessEqual(len(token), 64)

    def test_selftest_passes_and_is_not_a_placeholder(self):
        result = self.run_script("--test")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("PASS day2-failure-report-selftest", result.stdout)

    def test_selftest_detects_a_neutralised_errno_mapping(self):
        original = projector.errno_class_for
        try:
            projector.errno_class_for = lambda errno: "unknown"
            buffer = io.StringIO()
            with redirect_stdout(buffer):
                code = projector.run_selftest()
        finally:
            projector.errno_class_for = original
        self.assertEqual(code, 1)
        self.assertIn("FAIL day2-failure-report-selftest", buffer.getvalue())

    def test_usage_error_is_a_nonzero_exit_but_prints_nothing_raw(self):
        result = self.run_script("--field", "not-a-field")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn(CANARY_TOKEN, result.stdout + result.stderr)


class RealHarnessFormatTest(unittest.TestCase):
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
        self.assertEqual(
            observed,
            "booking/quote http=200; booking/hold http=409 "
            "code=rest_slot_unavailable; booking/hold http=200",
        )
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown root_state=bookable visible=0 "
            "observed=" + observed
        )
        fields = projector.project(line)
        self.assertEqual((fields["http"], fields["code"]), ("409", "other"))

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
            self.assertEqual((fields["http"], fields["code"]), expected)

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
        self.assertIn("code=CLINIC_BOOKING_BUSY;", observed)
        line = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown observed=" + observed
        )
        fields = projector.project(line)
        self.assertEqual((fields["code"], fields["http"]), ("CLINIC_BOOKING_BUSY", "503"))

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
        self.assertEqual((fields["errno"], fields["errno_class"]), ("0", "unknown"))
        # The unchanged line still maps 1213 -> deadlock: a mutation that broke
        # the deadlock mapping would change this and fail here.
        same = projector.project(failure_line())  # carries sql_errno=1213
        self.assertEqual((same["errno"], same["errno_class"]), ("1213", "deadlock"))
        # And the two are genuinely different classifications, not both-unknown.
        self.assertNotEqual(fields["errno_class"], same["errno_class"])

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
        self.assertEqual(fields["journey"], "journey-A1-public")
        self.assertEqual((fields["errno"], fields["errno_class"]), ("1205", "lock_wait_timeout"))
        self.assertEqual((fields["read"], fields["http"]), ("slot", "503"))
        self.assertEqual(fields["busy"], "present")

    def test_busy_block_is_absent_when_the_harness_appended_nothing(self):
        """(f) `busy_suffix_for` returns "" when no busy call was observed."""
        line = (
            "FAIL journey-B-reception — Day-2 browser journey failed "
            "(journey-B-reception) — reception board load failed"
        )
        self.assertEqual(projector.project(line)["busy"], "absent")

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
            with self.subTest(expected=expected):
                line = (
                    "FAIL journey-A1-public — Day-2 browser journey failed "
                    "(journey-A1-public) — x | journey=A1-public " + suffix
                )
                self.assertEqual(projector.project(line)["busy"], expected)
        self.assertEqual(projector.project(failure_line())["busy"], "present")
        self.assertEqual(projector.project(None)["busy"], "absent")
        self.assertTrue(set(projector.BUSY_TOKENS) == set(projector.ENUMS["busy"]))


class BoundsAndCliHardeningTest(unittest.TestCase):
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
        self.assertEqual(fields["journey"], projector.NONE)
        self.assertEqual(fields["stage"], projector.NONE)
        self.assertEqual(fields["http"], projector.UNRECOGNISED)

    def test_log_size_bound_still_reads_up_to_the_bound(self):
        cli = self.load_cli()
        cli.MAX_LOG_BYTES = 4096
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "ok.log"
            path.write_text(failure_line() + "y" * 20000, encoding="utf-8")
            fields = cli.project(cli._load_log(str(path)))
        self.assertEqual(fields["journey"], "journey-A1-public")

    def test_line_length_bound_is_enforced(self):
        cli = self.load_cli()
        cli.MAX_LINE_CHARS = 40
        fields = cli.project(failure_line())
        # The FAIL line keeps its first 40 characters: the key survives, every
        # marker past the bound is gone.
        self.assertEqual(fields["journey"], "journey-A1-public")
        self.assertEqual(fields["stage"], projector.NONE)
        self.assertEqual(fields["code"], projector.NONE)

    def test_line_length_bound_is_enforced_independently_of_the_block(self):
        """The per-line clip must hold even when the block bound is generous."""
        cli = self.load_cli()
        cli.MAX_BLOCK_CHARS = 1 << 30
        cli.MAX_LINE_CHARS = 40
        fields = cli.project(failure_line("busy_diag=not-found"))
        self.assertEqual(fields["journey"], "journey-A1-public")
        self.assertEqual(fields["busy"], "absent")

    def test_line_count_bound_stops_scanning_before_the_fail_line(self):
        cli = self.load_cli()
        cli.MAX_LINES = 3
        text = "noise\n" * 10 + failure_line()
        self.assertEqual(cli.project(text)["journey"], projector.NONE)
        # With one more line allowed the same log does report the failure, so the
        # test above fails for the bound and not for another reason.
        cli.MAX_LINES = 12
        self.assertEqual(cli.project(text)["journey"], "journey-A1-public")

    def test_line_count_bound_is_enforced(self):
        cli = self.load_cli()
        cli.MAX_LINES = 3
        text = "noise\n" * 10 + failure_line()
        self.assertEqual(cli.project(text)["journey"], projector.NONE)

    def test_line_count_bound_is_not_off_by_one(self):
        cli = self.load_cli()
        cli.MAX_LINES = 4
        text = "noise\n" * 3 + failure_line()
        self.assertEqual(cli.project(text)["journey"], "journey-A1-public")

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
        self.assertEqual(cli.project(head + tail)["busy"], "absent")
        # Unbounded (same text, generous bound): the token is found.
        cli.MAX_BLOCK_CHARS = 1 << 20
        self.assertEqual(cli.project(head + tail)["busy"], "not_found")

    def test_non_regular_log_path_is_refused_without_blocking(self):
        with tempfile.TemporaryDirectory() as tmp:
            fifo = Path(tmp) / "fifo.log"
            os.mkfifo(fifo)
            started = time.monotonic()
            result = subprocess.run(
                [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"),
                 "--log", str(fifo)],
                capture_output=True, text=True, check=False, timeout=30,
            )
            elapsed = time.monotonic() - started
        self.assertEqual(result.returncode, 0, result.stderr)
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
                with self.subTest(field=field):
                    result = subprocess.run(
                        [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"),
                         "--log", str(path), "--field", field],
                        capture_output=True, text=True, check=False,
                    )
                    self.assertEqual(result.returncode, 0, result.stderr)
                    token = result.stdout.rstrip("\n")
                    if field in projector.ENUMS:
                        self.assertIn(token, projector.ENUMS[field])
                    else:
                        self.assertEqual(token, projector.UNRECOGNISED)
                    self.assertEqual(token.count("\n"), 0)
                    for canary in ("canary", "::", "##[", "$(id)", "`id`", CANARY_EMAIL):
                        self.assertNotIn(canary, token)

    def test_cli_errors_are_nonzero_and_print_no_raw_text(self):
        for args in (
            ("--field", "not-a-field"),
            ("--log",),
            ("--unknown-option",),
            ("--test", "--field", "journey", "--bogus"),
        ):
            with self.subTest(args=args):
                result = subprocess.run(
                    [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"), *args],
                    capture_output=True, text=True, check=False,
                )
                self.assertNotEqual(result.returncode, 0)
                for canary in (CANARY_TOKEN, CANARY_EMAIL, CANARY_SQL, "Traceback"):
                    self.assertNotIn(canary, result.stdout + result.stderr)

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
                    self.assertEqual(code, 0)
        finally:
            cli.project = original
        lines = buffer.getvalue().splitlines()
        self.assertEqual(len(lines), len(cli.FIELDS))
        for line in lines:
            self.assertNotIn("Traceback", line)
            self.assertNotIn("boom", line)
            self.assertTrue(line in cli.UNRECOGNISED or line == "none" or line == "absent"
                            or line in cli.ENUMS.get("errno_class", ()), line)

    def test_directory_and_device_paths_are_total(self):
        for path in ("/dev/null", "/proc/self/cmdline", "/", "/nonexistent/x.log"):
            with self.subTest(path=path):
                result = subprocess.run(
                    [sys.executable, str(BIN_DIR / "pilot-day2-failure-report.py"),
                     "--log", path],
                    capture_output=True, text=True, check=False,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.count("\n"), 1)
                self.assertTrue(
                    result.stdout.startswith("journey=unrecognised ")
                    or result.stdout.startswith("journey=none "),
                    result.stdout,
                )


class RoundTwoFidelityTest(unittest.TestCase):
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

    def fail_log(self, exc_text):
        """A FAIL log line as projector INPUT (fixture, not `fail()` bytes).

        `fail()` no longer prints exception text (see
        Day2HarnessDbStderrAdversarialTest); the projector must still handle
        log text in this historical shape safely, so these projector tests
        construct that shape literally instead of via `fail()`.
        """
        return (
            "FAIL journey-A1-public — Day-2 browser journey failed "
            f"(journey-A1-public) — {exc_text}\n"
        )

    def reraises(self, exc_text, probe):
        """Origin text for a projector fixture (simulated, not harness output)."""
        suffix = self.suffix(probe)
        if suffix:
            return f"{exc_text} | journey=A1-public {suffix}"
        return exc_text

    # --- H1 ---------------------------------------------------------------
    def test_busy_suffix_never_carries_a_code_token(self):
        """H1 root cause: the structured suffix has no `code=` of its own."""
        probe = self.busy_probe()
        suffix = self.suffix(probe)
        self.assertIn("busy_diag=booking.lock_read_failed", suffix)
        self.assertIn("sql_errno=1213", suffix)
        self.assertNotIn("code=", suffix)

    def test_code_is_busy_only_where_the_harness_also_prints_the_code(self):
        """H1: the two carry-capable origins print the code; the rest cannot.

        This pins the CATEGORIES, not an exhaustive inventory: journey A1 has 22
        failure origins (6 `visible_or_diagnose` calls, 15 bare asserts, 1
        explicit raise), of which only 2 can carry a `code=` -- the
        `hold-countdown` stage and the line-673 raise. The list below is a
        representative sample of the 15 fixed assert literals, not all of them.
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
            with self.subTest(kind=text[:40]):
                fields = projector.project(self.fail_log(self.reraises(text, probe)))
                self.assertEqual(fields["code"], projector.BUSY_CODE)
                self.assertEqual(fields["busy"], "present")

        # A representative sample of the 15 bare-literal / status-word origins:
        # these print no `code=` anywhere, so the projector must NOT invent one.
        # The full inventory is 15 asserts + the 5 non-probe visible_or_diagnose
        # stages; they all share this property, which the sample pins.
        without_code = (
            "public booking root missing",
            "booking/quote failed",
            "expected 400 identity gate, got 503",
            "booking/confirm HTTP 503",
            "otp/request HTTP 503",
            "otp/verify HTTP 503",
            "receipt reference_code format invalid",
        )
        for text in without_code:
            with self.subTest(kind=text[:40]):
                fields = projector.project(self.fail_log(self.reraises(text, probe)))
                self.assertEqual(fields["busy"], "present")
                self.assertEqual(fields["code"], projector.NONE)

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
        # exc) and the suffix re-raise (`raise ... from exc` that interpolates
        # `| journey=A1-public`). Only `raise AssertionError(...)` at line 673
        # is a distinct origin.
        explicit = [
            r for r in raises
            if r.exc is not None
            and "| journey=A1-public" not in (_ast.get_source_segment(src, r) or "")
        ]
        self.assertEqual(len(vd), 6)
        self.assertEqual(len(asserts), 15)
        self.assertEqual(len(explicit), 1)
        self.assertEqual(len(vd) + len(asserts) + len(explicit), 22)
        # Exactly one visible_or_diagnose call passes a probe (carry-capable).
        carry = [
            n for n in _ast.walk(fn)
            if isinstance(n, _ast.Call) and isinstance(n.func, _ast.Name)
            and n.func.id == "visible_or_diagnose"
            and len(n.args) > 3 and not isinstance(n.args[3], _ast.Constant)
        ]
        self.assertEqual(len(carry), 1, "only hold-countdown passes a probe")

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
        self.assertEqual(len(carrying), 1,
                         "exactly one visible_or_diagnose call may pass a probe")
        self.assertEqual(carrying[0].args[2].value, "hold-countdown")

        # 1) hold-countdown via describe() only.
        probe = self.busy_probe()
        describe_text = (
            "public booking stage=hold-countdown root_state=bookable visible=0 observed="
            + probe.describe(0)
        )
        self.assertEqual(
            projector.project(self.fail_log(self.reraises(describe_text, probe)))["code"],
            projector.BUSY_CODE,
        )
        # 2) the line-673 explicit raise.
        hold = probe.hold_attempts(0)[0]
        explicit = (
            "public booking stage=hold-request \u2014 hold rejected for the fixture slot "
            f"slot={hold['slot_id']} http={hold['status']} code={hold['code']}"
        )
        self.assertEqual(
            projector.project(self.fail_log(self.reraises(explicit, probe)))["code"],
            projector.BUSY_CODE,
        )
        # 3) all five non-probe visible_or_diagnose stages -> code=none.
        for stage in ("otp-mobile", "otp-code", "confirm-btn", "names-form", "receipt"):
            msg = f"public booking stage={stage} root_state=bookable visible=0"
            fields = projector.project(self.fail_log(self.reraises(msg, probe)))
            self.assertEqual(fields["code"], projector.NONE, stage)
            self.assertEqual(fields["busy"], "present", stage)
            self.assertEqual(fields["stage"], stage, stage)

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
        fields = projector.project(self.fail_log(injected))
        # Documented current behaviour: the later free text wins for read/errno.
        self.assertEqual(fields["read"], "clinic")
        self.assertEqual(fields["errno"], "99999")
        # ... while a purely natural message does not move:
        natural = (
            "public booking stage=hold-countdown visible=0"
            " | journey=A1-public " + real +
            "\nCall log:\n  - waiting for locator(\"[data-role=hold-countdown]\")"
        )
        nf = projector.project(self.fail_log(natural))
        self.assertEqual((nf["read"], nf["errno"]), ("slot", "1213"))

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
        self.assertEqual(
            projector.project(head + "observed=q http=200 booking/confirm HTTP 409\n")["http"],
            projector.NONE)
        self.assertEqual(
            projector.project(head + "observed=q http=200 expected 400 identity gate, "
                                    "got 422\n")["http"],
            projector.NONE)
        # same text, no 2xx token -> the word form is used
        self.assertEqual(
            projector.project(head + "booking/confirm HTTP 503\n")["http"], "503")
        self.assertEqual(
            projector.project(head + "expected 400 identity gate, got 503\n")["http"], "503")
        # and a real non-2xx token also beats the word form
        self.assertEqual(
            projector.project(head + "observed=q http=200; h http=409\n")["http"], "409")

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
            with self.subTest(after=shape[:40]):
                fields = projector.project(head + body + real + "\n" + shape + "\n")
                self.assertEqual((fields["read"], fields["errno"]), ("slot", "1213"))
            with self.subTest(before=shape[:40]):
                fields = projector.project(head + body.replace("| journey", "\n| journey")
                                           + shape + "\n | journey=A1-public " + real + "\n")
                self.assertEqual((fields["read"], fields["errno"]), ("slot", "1213"))

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
        self.assertEqual((bare["read"], bare["errno"]), ("slot", "1213"))
        anchored = projector.project(
            head + real + "\n  - busy_diag=fetch-failed read=clinic sql_errno=99999\n")
        self.assertEqual((anchored["read"], anchored["errno"]), ("clinic", "99999"))

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
        log = self.fail_log(self.reraises(exc, probe))
        self.assertIn("\n\n", log)  # the blank line really is in the log
        fields = projector.project(log)
        self.assertEqual(fields["busy"], "present")
        self.assertEqual(fields["errno"], "1213")
        self.assertEqual(fields["read"], "slot")
        self.assertEqual(fields["errno_class"], "deadlock")

    def test_many_blank_lines_inside_the_block_are_harmless(self):
        exc = "Title.\n\n\n\nCall log:\n\n  - waiting\n\n"
        probe = self.busy_probe()
        fields = projector.project(self.fail_log(self.reraises(exc, probe)))
        self.assertEqual(fields["busy"], "present")

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
        self.assertEqual(fields["journey"], "journey-A1-public")
        self.assertEqual(fields["busy"], "present")
        self.assertEqual(fields["read"], "slot")
        self.assertEqual(fields["elapsed_s"], "9")

    # --- H4 ---------------------------------------------------------------
    def test_only_successful_statuses_project_none(self):
        """H4: no failing request was observed — a fact, not `unrecognised`."""
        text = (
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) — "
            "public booking stage=hold-countdown observed=booking/quote http=200; "
            "booking/hold http=200\n"
        )
        fields = projector.project(text)
        self.assertEqual(fields["http"], projector.NONE)
        self.assertTrue(projector.is_closed(fields))

    def test_a_non_2xx_status_still_wins_over_none(self):
        probe = self.busy_probe(code="rest_slot_unavailable", status=409)
        text = ("public booking stage=hold-countdown observed=" + probe.describe(0))
        fields = projector.project(self.fail_log(self.reraises(text, probe)))
        self.assertEqual(fields["http"], "409")
        self.assertNotEqual(fields["http"], projector.NONE)

    def test_none_http_is_only_for_observed_success_never_for_silence(self):
        fields = projector.project(
            "FAIL journey-A1-public — Day-2 browser journey failed (journey-A1-public) "
            "— public booking root missing\n"
        )
        self.assertEqual(fields["http"], projector.UNRECOGNISED)

    # --- H5 ---------------------------------------------------------------
    def test_free_text_cannot_supply_read_or_errno(self):
        """H5: `msg=` is the server's own message (harness line 478), not ours."""
        probe = self.busy_probe()
        host = self.busy_probe()
        host.entries[1]["message"] = "read=clinic sql_errno=99999"
        base = self.suffix(probe)
        text = (
            "public booking stage=hold-countdown hold_attempt=slot=42 http=503 "
            "code=CLINIC_BOOKING_BUSY msg=" + host.entries[1]["message"]
            + " | journey=A1-public " + base
        )
        fields = projector.project(self.fail_log(text))
        self.assertEqual(fields["read"], "slot")
        self.assertEqual(fields["errno"], "1213")

    def test_last_busy_block_wins(self):
        """The structured block is the LAST `busy_diag=`; earlier ones are stale."""
        base = self.suffix(self.busy_probe())
        text = (
            "FAIL journey-A1-public — x — busy_diag=booking.lock_read_failed "
            "read=clinic sql_errno=1062 | journey=A1-public " + base + "\n"
        )
        fields = projector.project(text)
        self.assertEqual((fields["read"], fields["errno"]), ("slot", "1213"))

    def test_an_unknown_busy_value_is_absent(self):
        text = (
            "FAIL journey-A1-public — x — busy_diag=totally-unknown read=slot "
            "sql_errno=1213\n"
        )
        fields = projector.project(text)
        self.assertEqual(fields["busy"], "absent")
        self.assertTrue(projector.is_closed(fields))


class RoundTwoGapTest(unittest.TestCase):
    """H6: the specific gaps the readers named, one test each."""

    def test_line_count_off_by_one(self):
        """The bound is `index >= MAX_LINES`, so line MAX_LINES-1 is still read."""
        cli = BoundsAndCliHardeningTest.load_cli(self)
        cli.MAX_LINES = 4
        # Lines 0..3 are the noise; the FAIL line is index 4 -> past the bound.
        self.assertEqual(
            cli.project("n\nn\nn\nn\nFAIL journey-A1-public — x — y\n")["journey"],
            projector.NONE,
        )
        # With the FAIL line at index 3 (the LAST readable line) it is read.
        self.assertEqual(
            cli.project("n\nn\nn\nFAIL journey-A1-public — x — y\n")["journey"],
            "journey-A1-public",
        )

    def test_which_non_2xx_status_wins(self):
        """The LAST non-2xx in the block wins: describe() is oldest-first."""
        text = (
            "FAIL journey-A1-public — x — observed=quote http=200; hold http=409; "
            "confirm http=422\n"
        )
        self.assertEqual(projector.project(text)["http"], "422")

    def test_separators_are_honoured_on_read_and_errno(self):
        """`;` and `|` bound every token, not just `code`."""
        text = (
            "FAIL journey-A1-public — x — busy_diag=booking.lock_read_failed; "
            "read=slot; sql_errno=1205;\n"
        )
        fields = projector.project(text)
        self.assertEqual((fields["read"], fields["errno"]), ("slot", "1205"))

    def test_unknown_busy_value_is_absent_not_unrecognised(self):
        fields = projector.project(
            "FAIL journey-A1-public — x — busy_diag=nonsense read=slot\n"
        )
        self.assertEqual(fields["busy"], "absent")

    def test_first_busy_code_wins_over_a_later_one(self):
        """`code` reports the busy code whenever it appears, and the http that
        belongs to it is the nearest preceding `http=` — not a later one."""
        text = (
            "FAIL journey-A1-public — x — hold http=503 code=CLINIC_BOOKING_BUSY; "
            "confirm http=500 code=internal_error\n"
        )
        fields = projector.project(text)
        self.assertEqual(fields["code"], projector.BUSY_CODE)
        self.assertEqual(fields["http"], "503")

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
        self.assertEqual(projector.project(text)["elapsed_s"], "7")
        # The harness emits the timing line exactly once, in one `finally:`.
        source = HARNESS.read_text(encoding="utf-8")
        self.assertEqual(source.count("day2-journey-timing journey={key}"), 1)

    def test_first_timing_line_for_a_journey_wins_for_that_journey(self):
        """`_elapsed_for` scans forward and returns the FIRST match of the key."""
        text = (
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=7\n"
            "FAIL journey-A1-public — x — y\n"
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=9\n"
        )
        self.assertEqual(projector.project(text)["elapsed_s"], "7")


class Day2HarnessDbStderrAdversarialTest(unittest.TestCase):
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
        self.assertIn("db_failed rc=1", raised_text)
        for token in self.TOKENS:
            self.assertNotIn(token, raised_text)

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
        self.assertIn("error_kind=runtime", stdout)
        # A failing test must still fail: the journey is recorded as failed.
        self.assertEqual(harness.failures, ["journey-A1-public"])
        self.assertEqual(harness.results[-1]["status"], "FAIL")
        results_detail = harness.results[-1]["detail"]
        for token in self.TOKENS:
            self.assertNotIn(token, stdout)
            self.assertNotIn(token, results_detail)
            self.assertNotIn(token, projector.render(projector.project(stdout)))


if __name__ == "__main__":
    unittest.main()

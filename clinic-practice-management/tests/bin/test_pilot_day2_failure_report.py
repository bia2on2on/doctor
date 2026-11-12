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
import re
import subprocess
import sys
import tempfile
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
        self.assertEqual(projector.errno_class_for("0"), "other")
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
            "code=CLINIC_BOOKING_BUSY read=slot errno=1213 errno_class=deadlock elapsed_s=51",
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

    def test_pass_run_reports_the_last_timed_journey_and_no_codes(self):
        text = (
            "PASS day2-A1-otp-identity-confirm — ok\n"
            "INFO day2-journey-timing journey=journey-A1-public elapsed_s=12\n"
        )
        self.assertEqual(
            self.project(text),
            "journey=journey-A1-public stage=none http=unrecognised code=none "
            "read=none errno=unrecognised errno_class=unknown elapsed_s=12",
        )

    def test_log_with_no_timing_and_no_failure_is_none_journey(self):
        self.assertEqual(
            self.project("python3: start\n"),
            "journey=none stage=none http=unrecognised code=none read=none "
            "errno=unrecognised errno_class=unknown elapsed_s=unrecognised",
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
            "errno=unrecognised errno_class=unknown elapsed_s=unrecognised",
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


if __name__ == "__main__":
    unittest.main()

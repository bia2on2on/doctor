"""Offline privacy/allow-list tests for the Day-2 booking-busy diagnostic.

The helper under test (bin/day2_booking_busy.py) turns the latest
`booking.lock_read_failed` operational-log context into a bounded failure-line
suffix. These tests pin the publication policy: only the event name, a fixed
read label, an integer errno, an errno-derived category and bounded integer
*_id fields may survive. Driver text and unknown fields are always dropped,
including in legacy rows and when a caller bypasses the extractor.
"""
from contextlib import redirect_stdout
import importlib.util
import io
import json
from pathlib import Path
import re
import sys
import unittest

BIN_DIR = Path(__file__).resolve().parents[2] / "bin"
sys.path.insert(0, str(BIN_DIR))

import day2_booking_busy as busy  # noqa: E402

PHONE = "09128887701"
TOKEN = "tok_SECRETVALUE_9f8e7d6c"
# Synthetic values only. Short names/numbers deliberately defeat pattern redaction.
DRIVER_VALUES = (
    "Li", "Ana", "42", "123456", PHONE,
    "synthetic.person@example.test", "آزمایشی", "李",
    "Duplicate entry 'Li-42' for key 'name'",
    'Duplicate entry "Li-42" for key "name"',
    "Duplicate entry `Li-42` for key `name`",
    "Duplicate entry Li-42 for key name",
    "failed for Li\n42\r\nآزمایشی\t" + TOKEN,
    "WHERE first_name='Li' AND national_id=123456",
)
ALLOWED_KEYS = {"event", "read", "sql_errno", "error_category", *busy.ID_KEYS}

def legacy_context(value):
    return {
        "read": "location", "sql_errno": 1213, "sql_error": value,
        "location_id": 3, "patient_name": "Li", "national_id": "123456",
        "error_category": value, "sql": value, "nested": {"value": value},
    }

def assert_no_values(test, output):
    text = output if isinstance(output, str) else json.dumps(output, ensure_ascii=False)
    for value in DRIVER_VALUES:
        test.assertTrue(value not in text, "captured output carried a driver value (content withheld)")
    test.assertTrue("sql_error" not in text, "captured output carried a forbidden key (content withheld)")
    test.assertTrue("patient_name" not in text, "captured output carried a forbidden key (content withheld)")
    test.assertTrue("national_id" not in text, "captured output carried a forbidden key (content withheld)")



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
    strings only) and a fixed placeholder otherwise, so operand values
    can never be printed regardless of which assertion failed. This is
    defence in depth ON TOP of the internal-comparison assertions used
    throughout this file, not a replacement for them.
    """

    def _formatMessage(self, msg, standardMsg):
        return msg if msg is not None else "assertion failed (details withheld)"

    def fail(self, msg=None):
        raise self.failureException(
            msg if msg is not None else "assertion failed (details withheld)"
        )


class DriverTextExclusionTest(_RedactedAssertions, unittest.TestCase):
    def test_every_legacy_driver_value_is_dropped_by_every_projection_path(self):
        for value in DRIVER_VALUES:
            with self.subTest(value="case"):
                context = legacy_context(value)
                diag = busy.extract_busy_diagnostic(context)
                self.assertTrue({"event": busy.EVENT, "read": "location",
                                  "sql_errno": 1213, "error_category": "deadlock",
                                  "location_id": 3} == diag,
                                 "derived value mismatch (content withheld)")
                self.assertLessEqual(set(diag), ALLOWED_KEYS)
                assert_no_values(self, diag)
                row = [(json.dumps(context, ensure_ascii=False),)]
                parsed = busy.parse_busy_rows(row)
                self.assertTrue(diag == parsed, "derived value mismatch (content withheld)")
                direct = busy.format_busy_suffix(context)
                via_reader = busy.busy_suffix_for(
                    BusySuffixForTest.BUSY_ENTRIES, lambda sql: row,
                    "cpmswp_cpms_operational_logs", "390x844",
                    since="2026-11-04 10:00:00",
                )
                for out in (direct, via_reader):
                    assert_no_values(self, out)
                    self.assertTrue("error_category=deadlock" in out,
                                    "busy suffix missing its category (content withheld)")
                    self.assertTrue("\n" not in out,
                                    "busy suffix must be a single line (content withheld)")
                    self.assertTrue("\r" not in out,
                                    "busy suffix must be a single line (content withheld)")
                    self.assertLessEqual(len(out), busy.MAX_SUFFIX)

    def test_short_names_cannot_masquerade_as_read_labels(self):
        for value in ("li", "ana", "patient_name", "آزمایشی", "location\nLi"):
            with self.subTest(value="case"):
                diag = busy.extract_busy_diagnostic({"read": value, "sql_errno": 1213})
                self.assertTrue("read" not in diag, "derived output carried forbidden text (content withheld)")
                assert_no_values(self, busy.format_busy_suffix({"read": value}))

    def test_fixed_read_labels_match_the_production_call_sites(self):
        source = (BIN_DIR.parent / "src" / "Application" / "Booking" /
                  "BookingService.php").read_text(encoding="utf-8")
        labels = set(re.findall(r"assert_read_succeeded\(\s*'([a-z0-9_]+)'", source))
        self.assertTrue(len(labels) > 0, "no read labels found in production source (content withheld)")
        self.assertTrue(labels == busy.READ_LABELS, "derived value mismatch (content withheld)")

    def test_categories_come_only_from_errno_not_stored_text(self):
        for errno, category in ((1213, "deadlock"), (1205, "lock_wait_timeout"),
                                (0, "unknown"), (1062, "driver_error")):
            diag = busy.extract_busy_diagnostic({
                "sql_errno": errno, "error_category": "Li", "sql_error": "Ana",
            })
            self.assertTrue(category == diag["error_category"], "derived value mismatch (content withheld)")
            self.assertTrue(f"error_category={category}" in busy.format_busy_suffix(diag), "derived output missing expected fragment (content withheld)")


class ExtractBusyDiagnosticTest(_RedactedAssertions, unittest.TestCase):
    def test_allowlist_projection_drops_everything_else(self):
        context = {
            "read": "persisted_slot",
            "sql_errno": 1213,
            "sql_error": "Deadlock found when trying to get lock",
            "slot_id": 42,
            "clinician_id": "7",
            "mobile": PHONE,
            "token": TOKEN,
            "patient_name": "Should Never Survive",
            "sql": "SELECT * FROM t WHERE mobile=" + PHONE,
        }
        diag = busy.extract_busy_diagnostic(context)
        self.assertTrue({
                "event": "booking.lock_read_failed",
                "read": "persisted_slot",
                "sql_errno": 1213,
                "error_category": "deadlock",
                "slot_id": 42,
                "clinician_id": 7,
            } == diag, "derived value mismatch (content withheld)")

    def test_rejects_bad_field_shapes(self):
        diag = busy.extract_busy_diagnostic(
            {
                "read": "Has Spaces",
                "sql_errno": "12x3",
                "sql_error": 999,
                "error_category": "Li",
                "slot_id": True,
                "clinic_id": 0,
                "location_id": -5,
            }
        )
        self.assertTrue({"event": "booking.lock_read_failed"} == diag, "derived value mismatch (content withheld)")

    def test_errno_digit_string_and_zero_are_kept(self):
        diag = busy.extract_busy_diagnostic({"sql_errno": "1205"})
        self.assertTrue(1205 == diag["sql_errno"], "derived value mismatch (content withheld)")
        diag_zero = busy.extract_busy_diagnostic({"sql_errno": 0})
        self.assertTrue(0 == diag_zero["sql_errno"], "derived value mismatch (content withheld)")

    def test_malformed_unknown_and_out_of_range_fields_are_dropped(self):
        diag = busy.extract_busy_diagnostic({
            "read": [], "sql_errno": True, "error_category": {"x": "Li"},
            "slot_id": "42\nLi", "clinic_id": -1, "location_id": busy.MAX_ID + 1,
            "organization_id": 1.5, "clinician_id": None, "unknown": "Ana",
        })
        self.assertTrue({"event": busy.EVENT} == diag, "derived value mismatch (content withheld)")
        for value in (-1, 100000, "1213\n", "۱۲۱۳"):
            self.assertTrue("sql_errno" not in busy.extract_busy_diagnostic({"sql_errno": value}), "derived output carried forbidden text (content withheld)")

    def test_non_object_context_is_none(self):
        self.assertTrue(busy.extract_busy_diagnostic(None) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.extract_busy_diagnostic("booking.lock_read_failed") is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.extract_busy_diagnostic([{"sql_errno": 1}]) is None, "derived value mismatch (content withheld)")


class ParseBusyRowsTest(_RedactedAssertions, unittest.TestCase):
    def test_missing_or_unparseable_rows_are_none(self):
        self.assertTrue(busy.parse_busy_rows([]) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.parse_busy_rows(None) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.parse_busy_rows([("NULL",)]) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.parse_busy_rows([("not json",)]) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.parse_busy_rows([('["a"]',)]) is None, "derived value mismatch (content withheld)")
        for cell in (None, 42, b'{"sql_errno":1213}', "", "null", "true", "[]"):
            self.assertTrue(busy.parse_busy_rows([(cell,)]) is None, "derived value mismatch (content withheld)")
        self.assertTrue({"event": busy.EVENT} == busy.parse_busy_rows([("{}",)]), "derived value mismatch (content withheld)")

    def test_valid_row_parses_and_extra_columns_are_ignored(self):
        row = ('{"read": "slot", "sql_errno": 1146, "slot_id": 5}', "ignored")
        diag = busy.parse_busy_rows([row])
        self.assertTrue(1146 == diag["sql_errno"], "derived value mismatch (content withheld)")
        self.assertTrue(5 == diag["slot_id"], "derived value mismatch (content withheld)")


class LatestBusySqlTest(_RedactedAssertions, unittest.TestCase):
    def test_exact_sql_for_bare_identifier(self):
        self.assertTrue("SELECT context_json FROM cpmswp_cpms_operational_logs "
            "WHERE message='booking.lock_read_failed' ORDER BY id DESC LIMIT 1" == busy.latest_busy_sql("cpmswp_cpms_operational_logs"), "derived value mismatch (content withheld)")

    def test_unsafe_table_identifier_raises(self):
        for bad in ("", "a b", "t; DROP TABLE x", "t`", None, 5):
            with self.assertRaises(ValueError):
                busy.latest_busy_sql(bad)


class FindBusyAttemptTest(_RedactedAssertions, unittest.TestCase):
    def test_picks_last_busy_entry_only(self):
        entries = [
            {"route": "/clinic/v1/booking/hold", "status": 503, "code": "CLINIC_BOOKING_BUSY"},
            {"route": "/clinic/v1/booking/quote", "status": 200, "code": ""},
            {"route": "/clinic/v1/booking/confirm", "status": 503, "code": "CLINIC_BOOKING_BUSY"},
        ]
        found = busy.find_busy_attempt(entries)
        self.assertTrue("/clinic/v1/booking/confirm" == found["route"], "derived value mismatch (content withheld)")

    def test_no_busy_entry_is_none(self):
        self.assertTrue(busy.find_busy_attempt([]) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.find_busy_attempt(None) is None, "derived value mismatch (content withheld)")
        self.assertTrue(busy.find_busy_attempt([{"code": "CLINIC_NOT_FOUND"}, {"code": ""}, "junk"]) is None, "derived value mismatch (content withheld)")


class FormatBusySuffixTest(_RedactedAssertions, unittest.TestCase):
    def test_full_suffix_single_line_within_cap(self):
        diag = busy.extract_busy_diagnostic(
            {
                "read": "persisted_slot",
                "sql_errno": 1213,
                "sql_error": "Deadlock found " + ("x" * 400),
                "slot_id": 42,
            }
        )
        out = busy.format_busy_suffix(
            diag, route="booking/hold", http_status=503, viewport="390x844"
        )
        self.assertTrue("busy_diag=booking.lock_read_failed" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("read=persisted_slot" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("sql_errno=1213" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("slot_id=42" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("route=booking/hold" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("http=503" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("viewport=390x844" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("\n" not in out, "derived output carried forbidden text (content withheld)")
        self.assertLessEqual(len(out), busy.MAX_SUFFIX)

    def test_direct_formatter_cannot_bypass_projection(self):
        context = legacy_context("Li 42 synthetic.person@example.test آزمایشی")
        context.update(sql_errno="1213", slot_id="7",
                       route="booking/hold\nLi", viewport="390x844\nLi")
        out = busy.format_busy_suffix(
            context, route="booking/hold\nLi", http_status="503",
            viewport="390x844\nLi", missing="Li",
        )
        assert_no_values(self, out)
        self.assertTrue("read=location" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("error_category=deadlock" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("slot_id=7" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("route=" not in out, "derived output carried forbidden text (content withheld)")
        self.assertTrue("viewport=" not in out, "derived output carried forbidden text (content withheld)")
        self.assertTrue("\n" not in out, "derived output carried forbidden text (content withheld)")

    def test_missing_states_and_invalid_parts(self):
        out = busy.format_busy_suffix(None, missing="fetch-failed", viewport="bogus")
        self.assertTrue("busy_diag=fetch-failed" == out, "derived value mismatch (content withheld)")
        out_bad_state = busy.format_busy_suffix(None, missing="whatever")
        self.assertTrue("busy_diag=not-found" == out_bad_state, "derived value mismatch (content withheld)")


class BusySuffixForTest(_RedactedAssertions, unittest.TestCase):
    BUSY_ENTRIES = [
        {"route": "/clinic/v1/booking/hold", "status": 503, "code": "CLINIC_BOOKING_BUSY"}
    ]

    def test_no_busy_attempt_never_queries(self):
        calls = []

        def fake_query(sql):
            calls.append(sql)
            return []

        out = busy.busy_suffix_for(
            [{"route": "/clinic/v1/booking/hold", "status": 200, "code": ""}],
            fake_query,
            "cpmswp_cpms_operational_logs",
        )
        self.assertTrue("" == out, "derived value mismatch (content withheld)")
        self.assertTrue([] == calls, "derived value mismatch (content withheld)")

    def test_busy_attempt_with_row_yields_cause_without_pii(self):
        stored = (
            '{"read": "location", "sql_errno": 1205,'
            f' "sql_error": "Lock wait timeout for {PHONE}",'
            f' "location_id": 3, "mobile": "{PHONE}", "token": "{TOKEN}"}}'
        )

        def fake_query(sql):
            self.assertTrue("cpmswp_cpms_operational_logs" in sql, "derived output missing expected fragment (content withheld)")
            return [(stored,)]

        out = busy.busy_suffix_for(
            self.BUSY_ENTRIES, fake_query, "cpmswp_cpms_operational_logs", "390x844"
        )
        self.assertTrue("busy_diag=booking.lock_read_failed" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("read=location" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("sql_errno=1205" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("location_id=3" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("route=booking/hold" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("viewport=390x844" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue(PHONE not in out, "derived output carried forbidden text (content withheld)")
        self.assertTrue(TOKEN not in out, "derived output carried forbidden text (content withheld)")
        self.assertTrue("mobile" not in out, "derived output carried forbidden text (content withheld)")

    def test_busy_attempt_without_row_is_not_found(self):
        out = busy.busy_suffix_for(
            self.BUSY_ENTRIES, lambda sql: [], "cpmswp_cpms_operational_logs"
        )
        self.assertTrue("busy_diag=not-found" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("route=booking/hold" in out, "derived output missing expected fragment (content withheld)")

    def test_query_failure_degrades_without_raising(self):
        def exploding_query(sql):
            raise RuntimeError("db query failed: synthetic")

        out = busy.busy_suffix_for(
            self.BUSY_ENTRIES, exploding_query, "cpmswp_cpms_operational_logs"
        )
        self.assertTrue("busy_diag=fetch-failed" in out, "derived output missing expected fragment (content withheld)")

    def test_unsafe_table_degrades_without_raising(self):
        out = busy.busy_suffix_for(self.BUSY_ENTRIES, lambda sql: [], "bad table")
        self.assertTrue("busy_diag=fetch-failed" in out, "derived output missing expected fragment (content withheld)")


class JourneyStartBoundTest(_RedactedAssertions, unittest.TestCase):
    """NEW: the busy row must come from THIS journey, not an earlier run."""

    SINCE = "2026-11-04 10:00:00"
    TABLE = "cpmswp_cpms_operational_logs"
    ENTRIES = BusySuffixForTest.BUSY_ENTRIES

    def test_since_adds_inclusive_created_at_bound(self):
        sql = busy.latest_busy_sql(self.TABLE, self.SINCE)
        self.assertTrue("AND created_at >= '2026-11-04 10:00:00'" in sql, "derived output missing expected fragment (content withheld)")
        self.assertTrue("message='booking.lock_read_failed'" in sql, "derived output missing expected fragment (content withheld)")
        self.assertTrue("ORDER BY id DESC LIMIT 1" in sql, "derived output missing expected fragment (content withheld)")

    def test_no_since_keeps_exact_legacy_unbounded_sql(self):
        self.assertTrue("SELECT context_json FROM cpmswp_cpms_operational_logs "
            "WHERE message='booking.lock_read_failed' ORDER BY id DESC LIMIT 1" == busy.latest_busy_sql(self.TABLE), "derived value mismatch (content withheld)")

    def test_invalid_or_injected_since_raises_valueerror(self):
        for bad in ("", "2026-11-04T10:00:00", "2026-11-04 10:00",
                    "x'; DROP TABLE t--", 5, [], "2026-11-04 10:00:00 "):
            with self.assertRaises(ValueError):
                busy.latest_busy_sql(self.TABLE, bad)

    def test_unsafe_table_still_raises_with_a_valid_since(self):
        with self.assertRaises(ValueError):
            busy.latest_busy_sql("bad table", self.SINCE)

    def test_busy_suffix_forwards_since_into_the_query(self):
        seen = []

        def fake_query(sql):
            seen.append(sql)
            return []

        out = busy.busy_suffix_for(
            self.ENTRIES, fake_query, self.TABLE, since=self.SINCE
        )
        self.assertTrue(1 == len(seen), "derived value mismatch (content withheld)")
        self.assertTrue("created_at >= '2026-11-04 10:00:00'" in seen[0], "derived output missing expected fragment (content withheld)")
        self.assertTrue("busy_diag=not-found" in out, "derived output missing expected fragment (content withheld)")

    def test_invalid_since_degrades_to_fetch_failed_never_raises(self):
        out = busy.busy_suffix_for(
            self.ENTRIES, lambda sql: [], self.TABLE, since="not-a-timestamp"
        )
        self.assertTrue("busy_diag=fetch-failed" in out, "derived output missing expected fragment (content withheld)")

    def test_query_failure_with_since_still_fetch_failed(self):
        def boom(sql):
            raise RuntimeError("db query failed: synthetic")

        out = busy.busy_suffix_for(
            self.ENTRIES, boom, self.TABLE, since=self.SINCE
        )
        self.assertTrue("busy_diag=fetch-failed" in out, "derived output missing expected fragment (content withheld)")


class StaleOrMismatchTest(_RedactedAssertions, unittest.TestCase):
    """NEW: a row whose ids disagree with the attempt is never its cause."""

    SINCE = "2026-11-04 10:00:00"
    TABLE = "cpmswp_cpms_operational_logs"

    def attempt(self, slot_id=42):
        return {"route": "/clinic/v1/booking/hold", "status": 503,
                "code": "CLINIC_BOOKING_BUSY", "slot_id": slot_id,
                "clinic_id": 1}

    @staticmethod
    def row(slot_id):
        return ('{"read": "slot", "sql_errno": 1213, '
                '"sql_error": "Deadlock found", "slot_id": %d, '
                '"clinic_id": 1}' % slot_id,)

    def test_matching_ids_still_surface_the_cause(self):
        out = busy.busy_suffix_for(
            [self.attempt()], lambda sql: [self.row(42)], self.TABLE,
            since=self.SINCE
        )
        self.assertTrue("busy_diag=booking.lock_read_failed" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("read=slot" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("slot_id=42" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("stale-or-mismatch" not in out, "derived output carried forbidden text (content withheld)")

    def test_conflicting_slot_id_is_stale_or_mismatch(self):
        out = busy.busy_suffix_for(
            [self.attempt()], lambda sql: [self.row(999)], self.TABLE,
            since=self.SINCE
        )
        self.assertTrue("busy_diag=stale-or-mismatch" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("read=slot" not in out, "derived output carried forbidden text (content withheld)")
        self.assertTrue("sql_errno=1213" not in out, "derived output carried forbidden text (content withheld)")
        self.assertTrue("route=booking/hold" in out, "derived output missing expected fragment (content withheld)")

    def test_conflicting_clinic_id_is_stale_or_mismatch(self):
        row = ('{"read": "organization", "sql_errno": 1205, "clinic_id": 9}',)
        out = busy.busy_suffix_for(
            [self.attempt()], lambda sql: [row], self.TABLE, since=self.SINCE
        )
        self.assertTrue("busy_diag=stale-or-mismatch" in out, "derived output missing expected fragment (content withheld)")

    def test_no_shared_id_keeps_the_row_rather_than_guessing(self):
        row = ('{"read": "location", "sql_errno": 1205, "location_id": 3}',)
        attempt = {"route": "/clinic/v1/booking/hold", "status": 503,
                   "code": "CLINIC_BOOKING_BUSY"}
        out = busy.busy_suffix_for(
            [attempt], lambda sql: [row], self.TABLE, since=self.SINCE
        )
        self.assertTrue("busy_diag=booking.lock_read_failed" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("stale-or-mismatch" not in out, "derived output carried forbidden text (content withheld)")

    def test_formatter_accepts_stale_state(self):
        self.assertTrue("busy_diag=stale-or-mismatch" == busy.format_busy_suffix(None, missing="stale-or-mismatch"), "derived value mismatch (content withheld)")


class PublicationPathTest(_RedactedAssertions, unittest.TestCase):
    def test_day2_fail_stdout_and_results_json_carry_only_the_projection(self):
        path = BIN_DIR / "pilot-day2-workflows.py"
        old_argv = sys.argv
        try:
            sys.argv = [str(path), "--test"]
            spec = importlib.util.spec_from_file_location("day2_publication_test", path)
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
        finally:
            sys.argv = old_argv
        row = [(json.dumps(legacy_context("Li 42 آزمایشی"), ensure_ascii=False),)]
        suffix = busy.busy_suffix_for(
            BusySuffixForTest.BUSY_ENTRIES, lambda sql: row,
            "cpmswp_cpms_operational_logs", "390x844",
            since="2026-11-04 10:00:00",
        )
        module.results.clear()
        module.failures.clear()
        stream = io.StringIO()
        with redirect_stdout(stream):
            # The real producer path: the A1 handler's structured re-raise,
            # then `fail()` exactly as the journey dispatcher calls it.
            module.fail(
                "journey-A1-public", "Day-2 browser journey failed",
                module.BusyJourneyFailure(
                    suffix,
                    origin_text="public booking stage=hold-request — hold rejected",
                ),
            )
        # These are the two shapes consumed by the CI log/annotation grep,
        # PR evidence comment, debug tail and results JSON writer.
        stdout = stream.getvalue()
        results_json = json.dumps({"results": module.results,
                                   "failures": module.failures}, ensure_ascii=False)
        for output in (stdout, results_json):
            assert_no_values(self, output)
            # Fixed schema: closed error kind, the closed stage token, and
            # the VALIDATED busy diagnosis (busy category, read label,
            # errno and its derived category, busy code) from the legacy
            # row — driver text and stored values can never ride it.
            self.assertTrue("error_kind=assertion" in output, "captured output missing expected fragment (content withheld)")
            self.assertTrue("stage=hold-request" in output, "captured output missing expected fragment (content withheld)")
            self.assertTrue("busy_diag=booking.lock_read_failed" in output, "captured output missing expected fragment (content withheld)")
            self.assertTrue("read=location" in output, "captured output missing expected fragment (content withheld)")
            self.assertTrue("sql_errno=1213" in output, "captured output missing expected fragment (content withheld)")
            self.assertTrue("error_category=deadlock" in output, "captured output missing expected fragment (content withheld)")
            self.assertTrue("code=CLINIC_BOOKING_BUSY" in output, "captured output missing expected fragment (content withheld)")
        self.assertTrue(["journey-A1-public"] == module.failures, "derived value mismatch (content withheld)")
        self.assertTrue(stdout.startswith("FAIL journey-A1-public"))


class FixtureFailureContractTest(_RedactedAssertions, unittest.TestCase):
    """C5: the Day-2 PHP fixture must never print driver text to stderr.

    Static contract over `tests/fixtures/pilot-day2-workflows-fixture.php`
    (the fixture needs WordPress, so it cannot execute here; the same
    functions were executed under PHP-WASM against a stub $wpdb during
    development). Pins: no `last_error` read anywhere outside comments,
    no digit-run masking, `d2w_fail` prints only a fixed step token, and
    every `d2w_fail(...)` call site passes an allowlisted literal.
    """

    FIXTURE = (
        Path(__file__).resolve().parents[1]
        / "fixtures" / "pilot-day2-workflows-fixture.php"
    )

    @classmethod
    def setUpClass(cls):
        cls.source = cls.FIXTURE.read_text(encoding="utf-8")
        stripped = re.sub(r"/\*.*?\*/", "", cls.source, flags=re.S)
        cls.code = "\n".join(
            line.split("//", 1)[0] for line in stripped.splitlines()
        )

    def test_wpdb_error_printing_is_suppressed_before_any_query(self):
        """D1: wpdb::print_error() error_log()s driver text + full SQL on
        a failed query unless suppress_errors is set (WordPress 7.1.3
        class-wpdb.php:1811-1823, reached from query() :2304); the gate
        tees this fixture's stderr to the public log. The fixture must
        suppress it right after wp-load, before the first insert."""
        self.assertTrue("$wpdb->suppress_errors(true);" in self.code, "derived output missing expected fragment (content withheld)")
        self.assertTrue("suppress_errors(false" not in self.code, "derived output carried forbidden text (content withheld)")
        load_at = self.code.index("wp-load.php")
        suppress_at = self.code.index("$wpdb->suppress_errors(true);")
        first_insert_at = self.code.index("d2w_insert(")
        self.assertLess(load_at, suppress_at)
        self.assertLess(suppress_at, first_insert_at)

    SUPERVISOR = (
        Path(__file__).resolve().parents[1]
        / "fixtures" / "day2-fixture-supervisor.php"
    )

    def test_supervisor_captures_child_output_from_process_start(self):
        """F1/F2: the fixture re-executes itself under d2w_supervise()
        (tests/fixtures/day2-fixture-supervisor.php) so raw child
        stdout/stderr (wp-load output, warnings, fatals, uncaught
        exceptions) are captured privately from process start. Success
        requires exit 0 + exactly one nonce completion line + an env
        file created by this run; child mode requires BOTH the argv
        flag AND the supervised marker AND the nonce env, failing
        closed with the fixed token BEFORE wp-load otherwise; the
        supervisor installs its handler before its first fallible call,
        uses a mode-verified private dir/files, and cleans up on all
        return paths (real-PHP behaviour is pinned by the PHPUnit
        Day2FixtureSupervisorTest in the Unit suite, run by CI)."""
        code = self.code
        unit = self.SUPERVISOR.read_text(encoding="utf-8")
        self.assertTrue("day2-fixture-supervisor.php" in code, "derived output missing expected fragment (content withheld)")
        self.assertTrue("d2w_supervise(" in code, "derived output missing expected fragment (content withheld)")
        self.assertTrue("d2w_is_supervised_child($argv ?? [])" in code, "derived output missing expected fragment (content withheld)")
        self.assertTrue("DAY2_FIXTURE_COMPLETE nonce=" in code, "derived output missing expected fragment (content withheld)")
        supervisor_at = code.index("d2w_supervise(")
        load_at = code.index("wp-load.php")
        self.assertLess(supervisor_at, load_at)
        # unit: handlers first, private modes verified, nonce via env,
        # stale env removed, completion counted exactly once
        for marker in ("set_error_handler", "register_shutdown_function",
                       "ini_set('display_errors', '0')", "ini_set('log_errors', '0')",
                       "proc_open(",
                       "random_bytes(16)", "DAY2_FIXTURE_NONCE",
                       "0700", "0600", "fileperms", "unlink($envFile)",
                       "DAY2_FIXTURE_OK status=ok", "catch (Throwable)",
                       # A/E: checked cleanup, dir registered at mkdir,
                       # completion is the LAST stdout line, nonce scope
                       "$state->tempDir = $dir;", "$lastOutLine",
                       "D2W_CAPTURE_LINE_MAX", "D2W_CAPTURE_TOTAL_MAX",
                       "malicious or compromised child"):
            self.assertTrue(marker in unit, "derived output missing expected fragment (content withheld)")
        handler_at = unit.index("set_error_handler")
        proc_at = unit.index("proc_open(")
        self.assertLess(handler_at, proc_at)

    def test_seed_writes_are_checked_and_positively_verified(self):
        """Item 2: repository/service seed writes must yield positive
        ids, and the rows behind the journeys' negative assertions
        (voided + foreign payments among them) are re-read with
        exactly-one-match before the env file is written."""
        code = self.code
        for token in ("'membership'", "'patient_link'", "'note_insert'",
                      "'followup_insert'", "'payment_insert'", "'seed_verify'"):
            self.assertTrue(token in code, "derived output missing expected fragment (content withheld)")
        self.assertGreaterEqual(code.count("d2w_require_id("), 15)
        # definition + payments loop (x5 rows) + notes loop (x5) + link
        self.assertGreaterEqual(code.count("d2w_verify_one("), 4)
        self.assertGreaterEqual(code.count("'payment_insert'"), 5)
        self.assertGreaterEqual(code.count("'note_insert'"), 5)
        for marker in ("$payNoA1Void", "$payNoB1Pos", "'voided'",
                       "cpms_payments", "cpms_clinical_notes",
                       "cpms_patient_user_links", "cpms_clinic_memberships",
                       # F3: full note identity, DENY rows, payment and
                       # assignment locations, settings read-back
                       "d2w_verify_note(", "hash_equals", "is_archived",
                       "d2w_verify_prescription(", "cpms_prescription_items",
                       "cpms_membership_capabilities", "'deny'",
                       "cpms_clinician_locations", "location_id = %d",
                       "role_key = %s",
                       "booking.max_future_days"):
            self.assertTrue(marker in code, "derived output missing expected fragment (content withheld)")
        verify_at = code.index("d2w_verify_one(")
        env_at = code.index("$envPayload = [")
        self.assertLess(verify_at, env_at)

    def test_no_driver_text_or_masking_in_fixture_code(self):
        self.assertTrue("last_error" not in self.code, "derived output carried forbidden text (content withheld)")
        self.assertTrue("preg_replace" not in self.code, "derived output carried forbidden text (content withheld)")
        self.assertTrue("[redacted]" not in self.code, "derived output carried forbidden text (content withheld)")

    def test_d2w_fail_prints_only_a_fixed_step_token(self):
        self.assertTrue("DAY2_FIXTURE_ERROR step=" in self.code, "derived output missing expected fragment (content withheld)")
        self.assertTrue("in_array($step, D2W_FAIL_STEPS, true)" in self.code, "derived output missing expected fragment (content withheld)")
        body = self.code.split("function d2w_fail", 1)[1].split("}", 1)[0]
        self.assertTrue("$msg" not in body, "derived output carried forbidden text (content withheld)")

    def test_every_d2w_fail_call_site_passes_a_fixed_literal(self):
        steps = set(re.findall(
            r"'([a-z_]+)'",
            self.code.split("D2W_FAIL_STEPS = [", 1)[1].split("]", 1)[0],
        ))
        self.assertTrue(steps == {"insert", "insert_id", "user_create", "clinic_ids", "public_page",
             "public_permalink", "reception_grace", "reception_row", "env_write",
             "membership", "patient_link", "note_insert", "followup_insert",
             "payment_insert", "seed_verify", "supervisor"}, "derived value mismatch (content withheld)")
        calls = re.findall(r"d2w_fail\(([^)]*)\)", self.code)
        self.assertGreaterEqual(len(calls), 10)
        for arg in calls:
            arg = arg.strip()
            if arg in ("string $step", "$step"):
                # declaration, and the d2w_require_id() helper forwarding
                # its (caller-validated) step argument
                continue
            literal = re.fullmatch(r"'([a-z_]+)'", arg)
            self.assertTrue(literal is not None, "derived value mismatch (content withheld)")
            self.assertTrue(literal.group(1) in steps, "derived output missing expected fragment (content withheld)")


class BusyAttributionRuleTest(_RedactedAssertions, unittest.TestCase):
    """D4: busy is claimed only for the operation that actually failed.

    Rule (see `find_busy_attempt`): with a known failing stage, only
    busy entries for that stage's route count, and a later non-busy
    FAILURE on that route displaces them (a later success does not);
    without a stage, busy counts only when it is the last observed
    response overall.
    """

    ROW = ('{"read": "slot", "sql_errno": 1213}',)
    TABLE = "cpmswp_cpms_operational_logs"
    HOLD = "/clinic/v1/booking/hold"
    CONFIRM = "/clinic/v1/booking/confirm"
    QUOTE = "/clinic/v1/booking/quote"

    def suffix(self, entries, stage=""):
        return busy.busy_suffix_for(
            entries, lambda sql: [self.ROW], self.TABLE, "390x844",
            since="2026-11-13 10:00:00", stage=stage,
        )

    @staticmethod
    def entry(route, status, code):
        return {"route": route, "status": status, "code": code}

    def test_genuine_busy_is_attributed(self):
        out = self.suffix([
            self.entry(self.QUOTE, 200, ""),
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
        ], stage="hold-request")
        self.assertTrue("busy_diag=booking.lock_read_failed" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("route=booking/hold" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("http=503" in out, "derived output missing expected fragment (content withheld)")

    def test_superseded_busy_is_not_attributed(self):
        """busy -> hold 200 -> confirm 200 -> unrelated failure."""
        out = self.suffix([
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.HOLD, 200, ""),
            self.entry(self.CONFIRM, 200, ""),
        ])
        self.assertTrue("" == out, "derived value mismatch (content withheld)")

    def test_case_a_busy_then_failure_on_another_route(self):
        """(a) busy on hold, then a 409 on confirm: no busy claim."""
        entries = [
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.CONFIRM, 409, "CLINIC_VALIDATION_FAILED"),
        ]
        self.assertTrue("" == self.suffix(entries, stage="names-form"), "derived value mismatch (content withheld)")
        self.assertTrue("" == self.suffix(entries), "derived value mismatch (content withheld)")

    def test_case_b_later_route_failure_displaces_earlier_busy(self):
        """(b) busy on A, busy on B, non-busy failure on B: no claim."""
        entries = [
            self.entry(self.QUOTE, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.HOLD, 409, "CLINIC_VALIDATION_FAILED"),
        ]
        self.assertTrue("" == self.suffix(entries, stage="hold-request"), "derived value mismatch (content withheld)")
        self.assertTrue("" == self.suffix(entries), "derived value mismatch (content withheld)")

    def test_case_c_stage_failure_keeps_busy_despite_later_success(self):
        """(c) journey failed AT the busy hold; a later same-route 200
        (retry that landed after the stage had already failed) must not
        erase the busy diagnosis."""
        out = self.suffix([
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.HOLD, 200, ""),
        ], stage="hold-request")
        self.assertTrue("busy_diag=booking.lock_read_failed" in out, "derived output missing expected fragment (content withheld)")
        self.assertTrue("http=503" in out, "derived output missing expected fragment (content withheld)")

    def test_case_d_later_same_route_failure_displaces_busy(self):
        """(d) busy, then a non-busy failure on the SAME route: busy is
        not claimed (the later response is the failing one)."""
        entries = [
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.HOLD, 409, "CLINIC_VALIDATION_FAILED"),
        ]
        self.assertTrue("" == self.suffix(entries, stage="hold-request"), "derived value mismatch (content withheld)")

    def test_multiple_busy_attempts_on_one_route_pick_the_last(self):
        entries = [
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
            self.entry(self.HOLD, 503, "CLINIC_BOOKING_BUSY"),
        ]
        found = busy.find_busy_attempt(entries, stage="hold-request")
        self.assertTrue(found is entries[-1], "derived value mismatch (content withheld)")
        self.assertTrue("busy_diag=booking.lock_read_failed" in self.suffix(entries), "derived output missing expected fragment (content withheld)")


class MainGuardPlacementTest(_RedactedAssertions, unittest.TestCase):
    """D2: no module-level class may be defined after the __main__ guard
    in the Day-2 python suites — `unittest.main()` exits at the guard,
    so such classes would silently never run via `python3 <file>` (the
    exact CI invocation).

    Scope, stated precisely: this is an AST check over MODULE-LEVEL
    class definitions in these two files only (it replaced a line-based
    check). It is not a general guard for other files, nested classes,
    or classes imported from elsewhere.
    """

    FILES = ("test_day2_booking_busy.py", "test_pilot_day2_failure_report.py")

    def test_no_class_after_main_guard(self):
        import ast

        here = Path(__file__).resolve().parent
        for name in self.FILES:
            with self.subTest(file="case"):
                tree = ast.parse((here / name).read_text(encoding="utf-8"))
                guards = [
                    node for node in tree.body
                    if isinstance(node, ast.If)
                    and isinstance(node.test, ast.Compare)
                    and isinstance(node.test.left, ast.Name)
                    and node.test.left.id == "__name__"
                ]
                self.assertTrue(len(guards) == 1, "derived value mismatch (content withheld)")
                guard_at = tree.body.index(guards[0])
                offenders = [
                    node.name for node in tree.body[guard_at + 1:]
                    if isinstance(node, ast.ClassDef)
                ]
                self.assertTrue(offenders == [], "derived value mismatch (content withheld)")


if __name__ == "__main__":
    unittest.main(verbosity=2)

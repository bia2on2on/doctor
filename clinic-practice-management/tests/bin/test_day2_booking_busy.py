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
        test.assertNotIn(value, text)
    test.assertNotIn("sql_error", text)
    test.assertNotIn("patient_name", text)
    test.assertNotIn("national_id", text)


class DriverTextExclusionTest(unittest.TestCase):
    def test_every_legacy_driver_value_is_dropped_by_every_projection_path(self):
        for value in DRIVER_VALUES:
            with self.subTest(value=repr(value)):
                context = legacy_context(value)
                diag = busy.extract_busy_diagnostic(context)
                self.assertEqual({"event": busy.EVENT, "read": "location",
                                  "sql_errno": 1213, "error_category": "deadlock",
                                  "location_id": 3}, diag)
                self.assertLessEqual(set(diag), ALLOWED_KEYS)
                assert_no_values(self, diag)
                row = [(json.dumps(context, ensure_ascii=False),)]
                parsed = busy.parse_busy_rows(row)
                self.assertEqual(diag, parsed)
                direct = busy.format_busy_suffix(context)
                via_reader = busy.busy_suffix_for(
                    BusySuffixForTest.BUSY_ENTRIES, lambda sql: row,
                    "cpmswp_cpms_operational_logs", "390x844",
                    since="2026-11-04 10:00:00",
                )
                for out in (direct, via_reader):
                    assert_no_values(self, out)
                    self.assertIn("error_category=deadlock", out)
                    self.assertNotIn("\n", out)
                    self.assertNotIn("\r", out)
                    self.assertLessEqual(len(out), busy.MAX_SUFFIX)

    def test_short_names_cannot_masquerade_as_read_labels(self):
        for value in ("li", "ana", "patient_name", "آزمایشی", "location\nLi"):
            with self.subTest(value=value):
                diag = busy.extract_busy_diagnostic({"read": value, "sql_errno": 1213})
                self.assertNotIn("read", diag)
                assert_no_values(self, busy.format_busy_suffix({"read": value}))

    def test_fixed_read_labels_match_the_production_call_sites(self):
        source = (BIN_DIR.parent / "src" / "Application" / "Booking" /
                  "BookingService.php").read_text(encoding="utf-8")
        labels = set(re.findall(r"assert_read_succeeded\(\s*'([a-z0-9_]+)'", source))
        self.assertTrue(labels)
        self.assertEqual(labels, busy.READ_LABELS)

    def test_categories_come_only_from_errno_not_stored_text(self):
        for errno, category in ((1213, "deadlock"), (1205, "lock_wait_timeout"),
                                (0, "unknown"), (1062, "driver_error")):
            diag = busy.extract_busy_diagnostic({
                "sql_errno": errno, "error_category": "Li", "sql_error": "Ana",
            })
            self.assertEqual(category, diag["error_category"])
            self.assertIn(f"error_category={category}", busy.format_busy_suffix(diag))


class ExtractBusyDiagnosticTest(unittest.TestCase):
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
        self.assertEqual(
            {
                "event": "booking.lock_read_failed",
                "read": "persisted_slot",
                "sql_errno": 1213,
                "error_category": "deadlock",
                "slot_id": 42,
                "clinician_id": 7,
            },
            diag,
        )

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
        self.assertEqual({"event": "booking.lock_read_failed"}, diag)

    def test_errno_digit_string_and_zero_are_kept(self):
        diag = busy.extract_busy_diagnostic({"sql_errno": "1205"})
        self.assertEqual(1205, diag["sql_errno"])
        diag_zero = busy.extract_busy_diagnostic({"sql_errno": 0})
        self.assertEqual(0, diag_zero["sql_errno"])

    def test_malformed_unknown_and_out_of_range_fields_are_dropped(self):
        diag = busy.extract_busy_diagnostic({
            "read": [], "sql_errno": True, "error_category": {"x": "Li"},
            "slot_id": "42\nLi", "clinic_id": -1, "location_id": busy.MAX_ID + 1,
            "organization_id": 1.5, "clinician_id": None, "unknown": "Ana",
        })
        self.assertEqual({"event": busy.EVENT}, diag)
        for value in (-1, 100000, "1213\n", "۱۲۱۳"):
            self.assertNotIn("sql_errno", busy.extract_busy_diagnostic({"sql_errno": value}))

    def test_non_object_context_is_none(self):
        self.assertIsNone(busy.extract_busy_diagnostic(None))
        self.assertIsNone(busy.extract_busy_diagnostic("booking.lock_read_failed"))
        self.assertIsNone(busy.extract_busy_diagnostic([{"sql_errno": 1}]))


class ParseBusyRowsTest(unittest.TestCase):
    def test_missing_or_unparseable_rows_are_none(self):
        self.assertIsNone(busy.parse_busy_rows([]))
        self.assertIsNone(busy.parse_busy_rows(None))
        self.assertIsNone(busy.parse_busy_rows([("NULL",)]))
        self.assertIsNone(busy.parse_busy_rows([("not json",)]))
        self.assertIsNone(busy.parse_busy_rows([('["a"]',)]))
        for cell in (None, 42, b'{"sql_errno":1213}', "", "null", "true", "[]"):
            self.assertIsNone(busy.parse_busy_rows([(cell,)]))
        self.assertEqual({"event": busy.EVENT}, busy.parse_busy_rows([("{}",)]))

    def test_valid_row_parses_and_extra_columns_are_ignored(self):
        row = ('{"read": "slot", "sql_errno": 1146, "slot_id": 5}', "ignored")
        diag = busy.parse_busy_rows([row])
        self.assertEqual(1146, diag["sql_errno"])
        self.assertEqual(5, diag["slot_id"])


class LatestBusySqlTest(unittest.TestCase):
    def test_exact_sql_for_bare_identifier(self):
        self.assertEqual(
            "SELECT context_json FROM cpmswp_cpms_operational_logs "
            "WHERE message='booking.lock_read_failed' ORDER BY id DESC LIMIT 1",
            busy.latest_busy_sql("cpmswp_cpms_operational_logs"),
        )

    def test_unsafe_table_identifier_raises(self):
        for bad in ("", "a b", "t; DROP TABLE x", "t`", None, 5):
            with self.assertRaises(ValueError):
                busy.latest_busy_sql(bad)


class FindBusyAttemptTest(unittest.TestCase):
    def test_picks_last_busy_entry_only(self):
        entries = [
            {"route": "/clinic/v1/booking/hold", "status": 503, "code": "CLINIC_BOOKING_BUSY"},
            {"route": "/clinic/v1/booking/quote", "status": 200, "code": ""},
            {"route": "/clinic/v1/booking/confirm", "status": 503, "code": "CLINIC_BOOKING_BUSY"},
        ]
        found = busy.find_busy_attempt(entries)
        self.assertEqual("/clinic/v1/booking/confirm", found["route"])

    def test_no_busy_entry_is_none(self):
        self.assertIsNone(busy.find_busy_attempt([]))
        self.assertIsNone(busy.find_busy_attempt(None))
        self.assertIsNone(
            busy.find_busy_attempt([{"code": "CLINIC_NOT_FOUND"}, {"code": ""}, "junk"])
        )


class FormatBusySuffixTest(unittest.TestCase):
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
        self.assertIn("busy_diag=booking.lock_read_failed", out)
        self.assertIn("read=persisted_slot", out)
        self.assertIn("sql_errno=1213", out)
        self.assertIn("slot_id=42", out)
        self.assertIn("route=booking/hold", out)
        self.assertIn("http=503", out)
        self.assertIn("viewport=390x844", out)
        self.assertNotIn("\n", out)
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
        self.assertIn("read=location", out)
        self.assertIn("error_category=deadlock", out)
        self.assertIn("slot_id=7", out)
        self.assertNotIn("route=", out)
        self.assertNotIn("viewport=", out)
        self.assertNotIn("\n", out)

    def test_missing_states_and_invalid_parts(self):
        out = busy.format_busy_suffix(None, missing="fetch-failed", viewport="bogus")
        self.assertEqual("busy_diag=fetch-failed", out)
        out_bad_state = busy.format_busy_suffix(None, missing="whatever")
        self.assertEqual("busy_diag=not-found", out_bad_state)


class BusySuffixForTest(unittest.TestCase):
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
        self.assertEqual("", out)
        self.assertEqual([], calls)

    def test_busy_attempt_with_row_yields_cause_without_pii(self):
        stored = (
            '{"read": "location", "sql_errno": 1205,'
            f' "sql_error": "Lock wait timeout for {PHONE}",'
            f' "location_id": 3, "mobile": "{PHONE}", "token": "{TOKEN}"}}'
        )

        def fake_query(sql):
            self.assertIn("cpmswp_cpms_operational_logs", sql)
            return [(stored,)]

        out = busy.busy_suffix_for(
            self.BUSY_ENTRIES, fake_query, "cpmswp_cpms_operational_logs", "390x844"
        )
        self.assertIn("busy_diag=booking.lock_read_failed", out)
        self.assertIn("read=location", out)
        self.assertIn("sql_errno=1205", out)
        self.assertIn("location_id=3", out)
        self.assertIn("route=booking/hold", out)
        self.assertIn("viewport=390x844", out)
        self.assertNotIn(PHONE, out)
        self.assertNotIn(TOKEN, out)
        self.assertNotIn("mobile", out)

    def test_busy_attempt_without_row_is_not_found(self):
        out = busy.busy_suffix_for(
            self.BUSY_ENTRIES, lambda sql: [], "cpmswp_cpms_operational_logs"
        )
        self.assertIn("busy_diag=not-found", out)
        self.assertIn("route=booking/hold", out)

    def test_query_failure_degrades_without_raising(self):
        def exploding_query(sql):
            raise RuntimeError("db query failed: synthetic")

        out = busy.busy_suffix_for(
            self.BUSY_ENTRIES, exploding_query, "cpmswp_cpms_operational_logs"
        )
        self.assertIn("busy_diag=fetch-failed", out)

    def test_unsafe_table_degrades_without_raising(self):
        out = busy.busy_suffix_for(self.BUSY_ENTRIES, lambda sql: [], "bad table")
        self.assertIn("busy_diag=fetch-failed", out)


class JourneyStartBoundTest(unittest.TestCase):
    """NEW: the busy row must come from THIS journey, not an earlier run."""

    SINCE = "2026-11-04 10:00:00"
    TABLE = "cpmswp_cpms_operational_logs"
    ENTRIES = BusySuffixForTest.BUSY_ENTRIES

    def test_since_adds_inclusive_created_at_bound(self):
        sql = busy.latest_busy_sql(self.TABLE, self.SINCE)
        self.assertIn("AND created_at >= '2026-11-04 10:00:00'", sql)
        self.assertIn("message='booking.lock_read_failed'", sql)
        self.assertIn("ORDER BY id DESC LIMIT 1", sql)

    def test_no_since_keeps_exact_legacy_unbounded_sql(self):
        self.assertEqual(
            "SELECT context_json FROM cpmswp_cpms_operational_logs "
            "WHERE message='booking.lock_read_failed' ORDER BY id DESC LIMIT 1",
            busy.latest_busy_sql(self.TABLE),
        )

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
        self.assertEqual(1, len(seen))
        self.assertIn("created_at >= '2026-11-04 10:00:00'", seen[0])
        self.assertIn("busy_diag=not-found", out)

    def test_invalid_since_degrades_to_fetch_failed_never_raises(self):
        out = busy.busy_suffix_for(
            self.ENTRIES, lambda sql: [], self.TABLE, since="not-a-timestamp"
        )
        self.assertIn("busy_diag=fetch-failed", out)

    def test_query_failure_with_since_still_fetch_failed(self):
        def boom(sql):
            raise RuntimeError("db query failed: synthetic")

        out = busy.busy_suffix_for(
            self.ENTRIES, boom, self.TABLE, since=self.SINCE
        )
        self.assertIn("busy_diag=fetch-failed", out)


class StaleOrMismatchTest(unittest.TestCase):
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
        self.assertIn("busy_diag=booking.lock_read_failed", out)
        self.assertIn("read=slot", out)
        self.assertIn("slot_id=42", out)
        self.assertNotIn("stale-or-mismatch", out)

    def test_conflicting_slot_id_is_stale_or_mismatch(self):
        out = busy.busy_suffix_for(
            [self.attempt()], lambda sql: [self.row(999)], self.TABLE,
            since=self.SINCE
        )
        self.assertIn("busy_diag=stale-or-mismatch", out)
        self.assertNotIn("read=slot", out)
        self.assertNotIn("sql_errno=1213", out)
        self.assertIn("route=booking/hold", out)

    def test_conflicting_clinic_id_is_stale_or_mismatch(self):
        row = ('{"read": "organization", "sql_errno": 1205, "clinic_id": 9}',)
        out = busy.busy_suffix_for(
            [self.attempt()], lambda sql: [row], self.TABLE, since=self.SINCE
        )
        self.assertIn("busy_diag=stale-or-mismatch", out)

    def test_no_shared_id_keeps_the_row_rather_than_guessing(self):
        row = ('{"read": "location", "sql_errno": 1205, "location_id": 3}',)
        attempt = {"route": "/clinic/v1/booking/hold", "status": 503,
                   "code": "CLINIC_BOOKING_BUSY"}
        out = busy.busy_suffix_for(
            [attempt], lambda sql: [row], self.TABLE, since=self.SINCE
        )
        self.assertIn("busy_diag=booking.lock_read_failed", out)
        self.assertNotIn("stale-or-mismatch", out)

    def test_formatter_accepts_stale_state(self):
        self.assertEqual(
            "busy_diag=stale-or-mismatch",
            busy.format_busy_suffix(None, missing="stale-or-mismatch"),
        )


class PublicationPathTest(unittest.TestCase):
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
            self.assertIn("error_kind=assertion", output)
            self.assertIn("stage=hold-request", output)
            self.assertIn("busy_diag=booking.lock_read_failed", output)
            self.assertIn("read=location", output)
            self.assertIn("sql_errno=1213", output)
            self.assertIn("error_category=deadlock", output)
            self.assertIn("code=CLINIC_BOOKING_BUSY", output)
        self.assertEqual(["journey-A1-public"], module.failures)
        self.assertTrue(stdout.startswith("FAIL journey-A1-public"))


if __name__ == "__main__":
    unittest.main(verbosity=2)

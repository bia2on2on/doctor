"""Offline privacy/allow-list tests for the Day-2 booking-busy diagnostic.

The helper under test (bin/day2_booking_busy.py) turns the latest
`booking.lock_read_failed` operational-log context into a bounded failure-line
suffix. These tests pin the publication policy: only the event name, the read
label, an integer errno, a sanitized/truncated sql_error, and integer *_id
fields may survive — never PII, tokens, phone-like digit runs, or SQL text.
"""
from pathlib import Path
import sys
import unittest

BIN_DIR = Path(__file__).resolve().parents[2] / "bin"
sys.path.insert(0, str(BIN_DIR))

import day2_booking_busy as busy  # noqa: E402

PHONE = "09128887701"
TOKEN = "tok_SECRETVALUE_9f8e7d6c"


class SanitizeSqlErrorTest(unittest.TestCase):
    def test_collapses_whitespace_to_one_line(self):
        out = busy.sanitize_sql_error("Lock wait\ttimeout\n  exceeded\r\nretry")
        self.assertEqual("Lock wait timeout exceeded retry", out)
        self.assertNotIn("\n", out)

    def test_truncates_to_200_chars(self):
        out = busy.sanitize_sql_error("x" * 500)
        self.assertEqual(200, len(out))

    def test_redacts_phone_like_digit_runs_but_keeps_short_numbers(self):
        out = busy.sanitize_sql_error(f"error 1205 for mobile {PHONE} retry")
        self.assertNotIn(PHONE, out)
        self.assertIn("[digits]", out)
        self.assertIn("1205", out)

    def test_keeps_identifier_quote_but_redacts_fragment_quote(self):
        kept = busy.sanitize_sql_error(
            "Table 'cpms_main.cpmswp_cpms_slots' doesn't exist"
        )
        self.assertIn("'cpms_main.cpmswp_cpms_slots'", kept)

        fragment = busy.sanitize_sql_error(
            f"syntax error near 'WHERE mobile = {PHONE} AND x' at line 1"
        )
        self.assertNotIn("WHERE", fragment)
        self.assertNotIn(PHONE, fragment)
        self.assertIn("'…'", fragment)

    def test_replaces_double_quotes_and_backticks(self):
        out = busy.sanitize_sql_error('bad "value" in `col`')
        self.assertNotIn('"', out)
        self.assertNotIn("`", out)

    def test_non_string_input_is_empty(self):
        self.assertEqual("", busy.sanitize_sql_error(None))
        self.assertEqual("", busy.sanitize_sql_error(1213))
        self.assertEqual("", busy.sanitize_sql_error(["Deadlock"]))


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
                "sql_error": "Deadlock found when trying to get lock",
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


class EmailRedactionTest(unittest.TestCase):
    """NEW: e-mail-like tokens in untrusted driver text are redacted."""

    def test_plain_email_is_redacted(self):
        out = busy.sanitize_sql_error("lock failed for ali.reza@clinic.example.com retry")
        self.assertNotIn("ali.reza@clinic.example.com", out)
        self.assertIn("[email]", out)

    def test_phone_like_local_part_is_fully_redacted(self):
        out = busy.sanitize_sql_error("contact 09128887701@clinic.example.com now")
        self.assertNotIn("09128887701", out)
        self.assertNotIn("clinic.example.com", out)
        self.assertIn("[email]", out)

    def test_quoted_email_redacted_identifier_quote_kept(self):
        out = busy.sanitize_sql_error("Table 'cpms_main.wp_slots' failed for 'a@b.co'")
        self.assertIn("'cpms_main.wp_slots'", out)
        self.assertNotIn("a@b.co", out)
        self.assertIn("[email]", out)

    def test_no_email_leaves_digit_and_truncation_rules_intact(self):
        out = busy.sanitize_sql_error("error 1205 for mobile 09128887701 retry")
        self.assertIn("1205", out)
        self.assertIn("[digits]", out)

    def test_email_in_sql_error_never_reaches_failure_line(self):
        stored = ('{"read": "slot", "sql_errno": 1205, '
                  '"sql_error": "deadlock for a@b.example.com", "slot_id": 7}',)
        out = busy.busy_suffix_for(
            [{"route": "/clinic/v1/booking/hold", "status": 503,
              "code": "CLINIC_BOOKING_BUSY", "slot_id": 7}],
            lambda sql: [stored], "cpmswp_cpms_operational_logs",
            since="2026-11-04 10:00:00"
        )
        self.assertNotIn("a@b.example.com", out)
        self.assertIn("[email]", out)
        self.assertIn("busy_diag=booking.lock_read_failed", out)


if __name__ == "__main__":
    unittest.main(verbosity=2)

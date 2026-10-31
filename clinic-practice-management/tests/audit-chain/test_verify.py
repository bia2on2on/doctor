import io
import json
import unittest

import verify


def make_row(identifier, previous, **overrides):
    row = {key: None for key in verify.INT_FIELDS + verify.STR_FIELDS}
    row.update(id=str(identifier), clinic_id="1", actor_role="system", action="TEST",
               resource_type="test", created_at="2026-10-31 12:00:00.000",
               prev_hash=previous)
    row.update(overrides)
    row["row_hash"] = verify.row_hash(row["prev_hash"], row)
    return row


def export(rows, footer=True):
    summary = {"count": len(rows), "max_id": int(rows[-1]["id"]) if rows else 0}
    records = [{"type": "header", "version": 1, **summary}]
    records += [{"type": "row", "data": row} for row in rows]
    if footer:
        records.append({"type": "end", **summary})
    return io.StringIO("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in records))


class VerifyTests(unittest.TestCase):
    def test_valid_and_empty(self):
        first = make_row(1, verify.GENESIS)
        second = make_row(3, first["row_hash"], action="نسخه/دارو\u2028")
        self.assertEqual(verify.verify(export([first, second]))["checked"], 2)
        self.assertTrue(verify.verify(export([]))["ok"])

    def test_stored_prev_hash_and_multiple_independent_failures(self):
        first = make_row(1, verify.GENESIS)
        # Correctly hashed against the wrong predecessor: linkage is still broken.
        second = make_row(2, "a" * 64)
        third = make_row(3, second["row_hash"])
        third["action"] = "tampered"
        fourth = make_row(4, third["row_hash"])
        result = verify.verify(export([first, second, third, fourth]))
        self.assertEqual(result["broken"], [{"id": 2, "errors": ["prev_hash"]},
                                            {"id": 3, "errors": ["row_hash"]}])

    def test_missing_footer_and_mismatched_count_fail_closed(self):
        row = make_row(1, verify.GENESIS)
        with self.assertRaises(ValueError):
            verify.verify(export([row], footer=False))
        text = export([row]).getvalue().replace('"count": 1', '"count": 2', 1)
        with self.assertRaises(ValueError):
            verify.verify(io.StringIO(text))

    def test_out_of_order_and_malformed_hash_rejected(self):
        first = make_row(2, verify.GENESIS)
        second = make_row(1, first["row_hash"])
        with self.assertRaises(ValueError):
            verify.verify(export([first, second]))
        first["prev_hash"] = "garbage"
        with self.assertRaises(ValueError):
            verify.verify(export([first]))

    def test_canonical_php_json_line_separators_and_numeric_ids(self):
        row = make_row(1, verify.GENESIS, action='الف/ب\u2028\u2029', resource_id="42")
        fields = '{"action":"الف/ب\\u2028\\u2029","actor_role":"system","actor_wp_user_id":null,"clinic_id":1,"created_at":"2026-10-31 12:00:00.000","ip_hash":null,"patient_id":null,"request_id":null,"resource_id":42,"resource_type":"test","session_id":null}'
        import hashlib
        self.assertEqual(row["row_hash"], hashlib.sha256((verify.GENESIS + fields).encode()).hexdigest())
        # Golden result cross-checked with production HashChain::computeRowHash
        # under PHP 8.5.10 WASM; this is not a database integration test.
        self.assertEqual(row["row_hash"], "748a92446006d3ad8125a5c055e28de79274b280e493df6b2557360f355af8dd")


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Independently verify a complete snapshot from export.php; never alters data."""
import hashlib
import json
import re
import sys

GENESIS = "0" * 64
INT_FIELDS = ("clinic_id", "actor_wp_user_id", "resource_id", "patient_id")
STR_FIELDS = ("actor_role", "action", "resource_type", "ip_hash", "session_id", "request_id", "created_at")
HEX = re.compile(r"[0-9a-f]{64}\Z")


def php_int(value):
    if value is None or value == "":
        return None
    if isinstance(value, bool) or not re.fullmatch(r"[0-9]+", str(value)):
        raise ValueError("invalid integer field")
    n = int(value)
    if n > 2**63 - 1:
        raise ValueError("integer outside PHP signed 64-bit range")
    return n


def row_hash(prev, row):
    fields = {key: php_int(row[key]) for key in INT_FIELDS}
    for key in STR_FIELDS:
        value = row[key]
        if value is not None and not isinstance(value, str):
            raise ValueError("invalid string field")
        fields[key] = value if value not in (None, "") else None
    # PHP JSON_UNESCAPED_UNICODE still escapes U+2028/U+2029 without
    # JSON_UNESCAPED_LINE_TERMINATORS. PHP also leaves slash unescaped here.
    canonical = json.dumps(fields, sort_keys=True, ensure_ascii=False, separators=(",", ":"))
    canonical = canonical.replace("\u2028", "\\u2028").replace("\u2029", "\\u2029")
    return hashlib.sha256((prev + canonical).encode("utf-8")).hexdigest()


def verify(stream):
    header = None
    ended = False
    count = 0
    last_id = 0
    previous = GENESIS
    failures = []
    for line in stream:
        item = json.loads(line)
        if not isinstance(item, dict) or ended:
            raise ValueError("invalid record or trailing data")
        kind = item.get("type")
        if header is None:
            if kind != "header" or item.get("version") != 1:
                raise ValueError("missing snapshot header")
            if type(item.get("count")) is not int or type(item.get("max_id")) is not int or item["count"] < 0 or item["max_id"] < 0:
                raise ValueError("invalid snapshot summary")
            header = item
            continue
        if kind == "end":
            if type(item.get("count")) is not int or type(item.get("max_id")) is not int or item["count"] != count or item["max_id"] != last_id or header["count"] != count or header["max_id"] != last_id:
                raise ValueError("incomplete or inconsistent snapshot")
            ended = True
            continue
        if kind != "row" or not isinstance(item.get("data"), dict):
            raise ValueError("invalid row record")
        row = item["data"]
        rid = php_int(row["id"])
        if rid is None or rid <= last_id:
            raise ValueError("row IDs not strictly increasing")
        prev = row["prev_hash"]
        actual = row["row_hash"]
        if not isinstance(prev, str) or not HEX.fullmatch(prev) or not isinstance(actual, str) or not HEX.fullmatch(actual):
            raise ValueError("malformed hash")
        reasons = []
        if prev != previous:
            reasons.append("prev_hash")
        if row_hash(prev, row) != actual:
            reasons.append("row_hash")
        if reasons:
            failures.append({"id": rid, "errors": reasons})
        previous = actual
        last_id = rid
        count += 1
    if header is None or not ended:
        raise ValueError("missing header or end marker")
    return {"ok": not failures, "checked": count, "max_id": last_id, "broken": failures}


def main():
    try:
        result = verify(sys.stdin)
    except (ValueError, KeyError, TypeError, UnicodeError, OverflowError) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}), file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())

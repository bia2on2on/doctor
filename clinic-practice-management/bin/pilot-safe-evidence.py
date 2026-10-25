#!/usr/bin/env python3
"""Render status-only, allowlisted evidence for sensitive Pilot browser fixtures.

Fixture environment values and per-check detail are intentionally never copied
into the returned projection. The only public fields are a fixed suite label,
a fixture-ready boolean, an overall result, and PASS/FAIL totals.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys


SUITES = {
    "slice3": ("SLICE3_PAGE=", 10, "Slice-3 N>1 chooser"),
    "portal-cancel": ("PORTAL_CANCEL=", 11, "Patient portal cancellation"),
}


def project(suite: str, env_path: Path, results_path: Path) -> str:
    env_prefix, expected_parts, label = SUITES[suite]
    raw_env = env_path.read_text(encoding="utf-8").strip()
    parts = raw_env.split("|")
    if len(parts) != expected_parts or not parts[0].startswith(env_prefix):
        raise ValueError("fixture schema mismatch")
    if any(not part for part in parts):
        raise ValueError("fixture field missing")

    payload = json.loads(results_path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict) or set(payload) != {"summary", "results"}:
        raise ValueError("result schema mismatch")
    summary = payload["summary"]
    rows = payload["results"]
    if not isinstance(summary, dict) or set(summary) != {"ok", "runs", "failed"}:
        raise ValueError("summary schema mismatch")
    if not isinstance(summary["ok"], bool):
        raise ValueError("summary status mismatch")
    if not isinstance(summary["runs"], int) or not 1 <= summary["runs"] <= 8:
        raise ValueError("run count mismatch")
    if not isinstance(summary["failed"], list):
        raise ValueError("failure list mismatch")
    if not isinstance(rows, list) or not rows or len(rows) > 256:
        raise ValueError("check list mismatch")

    passed = failed = 0
    for row in rows:
        # Accept only the runner's intentionally minimal schema. No values in
        # details, identifiers, or fixture environment are interpolated below.
        if not isinstance(row, dict) or set(row) != {"key", "status"}:
            raise ValueError("check schema mismatch")
        if not isinstance(row["key"], str) or not row["key"] or len(row["key"]) > 128:
            raise ValueError("check key mismatch")
        if row["status"] == "PASS":
            passed += 1
        elif row["status"] == "FAIL":
            failed += 1
        else:
            raise ValueError("check status mismatch")

    if len(summary["failed"]) != failed or summary["ok"] != (failed == 0):
        raise ValueError("summary/check mismatch")

    outcome = "PASS" if failed == 0 else "FAIL"
    return (
        f"Suite: {label}\n"
        "Fixture: READY (credential and identifier values withheld)\n"
        f"Result: {outcome}\n"
        f"Checks: {passed} passed; {failed} failed\n"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--suite", choices=sorted(SUITES), required=True)
    parser.add_argument("--env-file", type=Path, required=True)
    parser.add_argument("--results-file", type=Path, required=True)
    args = parser.parse_args()
    try:
        sys.stdout.write(project(args.suite, args.env_file, args.results_file))
    except (OSError, UnicodeError, json.JSONDecodeError, ValueError):
        # Fail closed without echoing untrusted input or exception text.
        print(
            "Evidence unavailable: fixture/results failed the safe schema; contents withheld",
            file=sys.stdout,
        )
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

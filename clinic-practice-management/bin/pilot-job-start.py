#!/usr/bin/env python3
"""Phase 17 queue start-latency evidence parser (measurement only).

The producer writes only bounded queue-state/timestamp columns for one random,
non-sensitive correlation token. This parser drops the token before producing
an aggregate; it does not query WordPress or change queue behavior.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
import sys
import tempfile
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List, Tuple

RAW_SCHEMA = "cpms.pilot-job-start-raw/1"
JOB_START_SCHEMA = "cpms.pilot-job-start/1"
JOB_TYPE = "backup.run"
SAMPLE_COUNT = 100
MAX_LATENCY_MS = 3_600_000
TIMESTAMP_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.000$")
MEASUREMENT_MODES = ("explicit_tick", "autonomous")
RAW_FIELDS = ("schema", "status", "measurement_mode", "job_type", "sample_count", "samples")
SAMPLE_FIELDS = ("created_at", "started_at", "status", "attempts", "max_attempts")
OUTPUT_FIELDS = (
    "schema",
    "status",
    "measurement_mode",
    "job_type",
    "sample_count",
    "p50_ms",
    "p95_ms",
    "p99_ms",
    "max_ms",
    "not_started_count",
    "processing_failure_count",
)


class JobStartRefused(ValueError):
    """Input cannot support a privacy-safe, exact first-start measurement."""


# Explicit-tick control: the production CLI prints exactly one of these lines
# (bin/cpms `jobs tick`). Only the processed form is ever a success; a skipped lock
# is NOT explicit processing, whatever its numeric meaning.
TICK_PROCESSED_PATTERN = re.compile(r"Processed ([0-9]{1,6}) job\(s\)")
TICK_SKIPPED_HELD = "Another tick is running; skipped."
TICK_SKIPPED_UNAVAILABLE = "Tick skipped (lock unavailable)."


def _exact_fields(value: Any, fields: Tuple[str, ...], label: str) -> Dict[str, Any]:
    if not isinstance(value, dict) or set(value) != set(fields):
        raise JobStartRefused(label + "_field_set")
    return value


def _timestamp(value: Any, label: str) -> datetime:
    if not isinstance(value, str) or TIMESTAMP_PATTERN.fullmatch(value) is None:
        raise JobStartRefused(label + "_timestamp")
    try:
        return datetime.strptime(value, "%Y-%m-%d %H:%M:%S.%f")
    except ValueError as exc:
        raise JobStartRefused(label + "_timestamp") from exc


def _plain_int(value: Any, low: int, high: int, label: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < low or value > high:
        raise JobStartRefused(label + "_integer")
    return value


def _nearest_rank(values: List[int], percentile: int) -> int:
    if not values:
        raise JobStartRefused("empty_latency_set")
    return sorted(values)[math.ceil(percentile * len(values) / 100) - 1]


def parse_tick_output(text: str, returncode: int) -> int:
    """Classify the captured explicit `jobs tick` output; return its processed count.

    Fail closed: a non-zero exit, a skipped-lock line, an unexpected line or any
    extra output is refused. Returns the numeric count only for the exact
    single-line ``Processed N job(s)`` form. The raw text is never echoed.
    """
    if isinstance(returncode, bool) or not isinstance(returncode, int) or returncode != 0:
        raise JobStartRefused("tick_exit_nonzero")
    lines = text.splitlines()
    if len(lines) != 1:
        raise JobStartRefused("tick_output_not_single_line")
    line = lines[0].strip()
    if line == TICK_SKIPPED_HELD:
        raise JobStartRefused("tick_skipped_lock_held")
    if line == TICK_SKIPPED_UNAVAILABLE:
        raise JobStartRefused("tick_skipped_lock_unavailable")
    match = TICK_PROCESSED_PATTERN.fullmatch(line)
    if match is None:
        raise JobStartRefused("tick_output_unexpected")
    return int(match.group(1))


def aggregate_raw_job_start(raw: Any, tick_processed: Any = None) -> Dict[str, Any]:
    """Validate the bounded producer file and return only allowlisted aggregates.

    Start latency is persisted ``started_at - created_at`` for each exact
    ``backup.run`` sample. ``attempts == max_attempts == 1`` proves that
    ``started_at`` is the first and only claim timestamp, not a retry overwrite.
    All timestamps must retain the queue's current UTC, whole-second format.

    ``tick_processed`` (explicit control only) is the processed count parsed from
    the explicit tick. The JobsDispatcher counts successful completions, so every
    batch row that succeeded must be covered by that tick's count; otherwise the
    batch was not shown to be processed by the explicit tick and nothing is published.
    """
    raw_obj = _exact_fields(raw, RAW_FIELDS, "raw")
    if raw_obj["schema"] != RAW_SCHEMA or raw_obj["status"] != "ok":
        raise JobStartRefused("raw_schema_or_status")
    mode = raw_obj["measurement_mode"]
    if not isinstance(mode, str) or mode not in MEASUREMENT_MODES:
        raise JobStartRefused("raw_measurement_mode")
    if raw_obj["job_type"] != JOB_TYPE:
        raise JobStartRefused("raw_job_type")
    declared = _plain_int(raw_obj["sample_count"], SAMPLE_COUNT, SAMPLE_COUNT, "sample_count")
    samples = raw_obj["samples"]
    if not isinstance(samples, list) or len(samples) != declared:
        raise JobStartRefused("sample_list_count")

    latencies: List[int] = []
    not_started_count = 0
    processing_failure_count = 0
    success_count = 0
    for index, value in enumerate(samples, start=1):
        sample = _exact_fields(value, SAMPLE_FIELDS, "sample_" + str(index))
        created_at = _timestamp(sample["created_at"], "created_at")
        started_raw = sample["started_at"]
        status = sample["status"]
        attempts = _plain_int(sample["attempts"], 0, 1, "attempts")
        max_attempts = _plain_int(sample["max_attempts"], 1, 1, "max_attempts")

        if started_raw is None:
            if status != "queued" or attempts != 0:
                raise JobStartRefused("unstarted_state_mismatch")
            not_started_count += 1
            continue

        started_at = _timestamp(started_raw, "started_at")
        if attempts != 1:
            raise JobStartRefused("not_first_attempt")
        if status not in ("success", "failed"):
            # A processing/unknown state has a start but no terminal outcome; do not
            # guess it into either the latency-success or processing-failure bucket.
            raise JobStartRefused("processing_outcome_incomplete")
        delta_ms = int((started_at - created_at).total_seconds() * 1000)
        if delta_ms < 0 or delta_ms > MAX_LATENCY_MS:
            raise JobStartRefused("latency_out_of_range")
        latencies.append(delta_ms)
        if status == "failed":
            processing_failure_count += 1
        else:
            success_count += 1

    if not_started_count:
        raise JobStartRefused("not_started")
    if len(latencies) != declared:
        raise JobStartRefused("started_sample_count")
    if tick_processed is not None:
        if isinstance(tick_processed, bool) or not isinstance(tick_processed, int) or tick_processed < 0:
            raise JobStartRefused("tick_processed_integer")
        if mode != "explicit_tick":
            raise JobStartRefused("tick_output_on_non_explicit_mode")
        if tick_processed < success_count:
            raise JobStartRefused("tick_processed_below_batch")

    ordered = sorted(latencies)
    result = {
        "schema": JOB_START_SCHEMA,
        "status": "ok",
        "measurement_mode": mode,
        "job_type": JOB_TYPE,
        "sample_count": declared,
        "p50_ms": _nearest_rank(ordered, 50),
        "p95_ms": _nearest_rank(ordered, 95),
        "p99_ms": _nearest_rank(ordered, 99),
        "max_ms": ordered[-1],
        "not_started_count": not_started_count,
        "processing_failure_count": processing_failure_count,
    }
    _exact_fields(result, OUTPUT_FIELDS, "aggregate")
    return result


def _reject_duplicate_pairs(pairs: List[Tuple[str, Any]]) -> Dict[str, Any]:
    obj: Dict[str, Any] = {}
    for key, value in pairs:
        if key in obj:
            raise JobStartRefused("duplicate_json_key")
        obj[key] = value
    return obj


def _reject_nonfinite(value: str) -> None:
    raise JobStartRefused("nonfinite_json_number")


def read_raw_file(path: Path) -> Dict[str, Any]:
    if not path.is_file() or path.stat().st_size > 2_000_000:
        raise JobStartRefused("raw_file_missing_or_oversize")
    try:
        raw = json.loads(
            path.read_text(encoding="utf-8"),
            object_pairs_hook=_reject_duplicate_pairs,
            parse_constant=_reject_nonfinite,
        )
    except (UnicodeError, json.JSONDecodeError, OSError, RecursionError) as exc:
        raise JobStartRefused("raw_json_invalid") from exc
    return _exact_fields(raw, RAW_FIELDS, "raw")


def write_aggregate(path: Path, aggregate: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(aggregate, ensure_ascii=True, allow_nan=False, indent=2) + "\n"
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as output:
        os.fchmod(output.fileno(), 0o600)
        output.write(payload)


def _fixture_raw(mode: str = "explicit_tick") -> Dict[str, Any]:
    base = datetime(2026, 1, 1, 0, 0, 0)
    samples = []
    for index in range(SAMPLE_COUNT):
        samples.append(
            {
                "created_at": base.strftime("%Y-%m-%d %H:%M:%S.000"),
                "started_at": (base + timedelta(seconds=index)).strftime("%Y-%m-%d %H:%M:%S.000"),
                "status": "success",
                "attempts": 1,
                "max_attempts": 1,
            }
        )
    return {
        "schema": RAW_SCHEMA,
        "status": "ok",
        "measurement_mode": mode,
        "job_type": JOB_TYPE,
        "sample_count": SAMPLE_COUNT,
        "samples": samples,
    }


def _selftests() -> int:
    failures: List[str] = []
    checks = 0

    def check(name: str, condition: bool) -> None:
        nonlocal checks
        checks += 1
        if not condition:
            failures.append(name)

    def refuses(name: str, mutate) -> None:
        fixture = _fixture_raw()
        mutate(fixture)
        try:
            aggregate_raw_job_start(fixture)
        except JobStartRefused:
            check(name, True)
            return
        check(name, False)

    valid = aggregate_raw_job_start(_fixture_raw())
    check("exact-nearest-rank-and-whole-second-latencies",
          valid["p50_ms"] == 49_000
          and valid["p95_ms"] == 94_000
          and valid["p99_ms"] == 98_000
          and valid["max_ms"] == 99_000)
    check("latency-above-five-seconds-is-data-not-a-gate",
          valid["p95_ms"] > 5_000 and valid["processing_failure_count"] == 0)

    failed_fixture = _fixture_raw()
    failed_fixture["samples"][0]["status"] = "failed"
    failed = aggregate_raw_job_start(failed_fixture)
    check("processing-failure-separate-from-start-latency",
          failed["processing_failure_count"] == 1 and failed["p50_ms"] == valid["p50_ms"])

    autonomous_fixture = _fixture_raw("autonomous")
    autonomous = aggregate_raw_job_start(autonomous_fixture)
    check("autonomous-mode-is-carried-not-relabelled",
          autonomous["measurement_mode"] == "autonomous"
          and autonomous["p95_ms"] == valid["p95_ms"])

    refuses("refuses-top-level-extra-payload", lambda raw: raw.update({"payload": {"phi": "sentinel"}}))
    refuses("refuses-unknown-measurement-mode",
            lambda raw: raw.update({"measurement_mode": "forced_tick"}))
    refuses("refuses-missing-measurement-mode",
            lambda raw: raw.pop("measurement_mode"))
    refuses("refuses-unknown-job-type", lambda raw: raw.update({"job_type": "patient.export"}))
    refuses("refuses-count-mismatch", lambda raw: raw.update({"sample_count": SAMPLE_COUNT - 1}))
    refuses("refuses-row-extra-id", lambda raw: raw["samples"][0].update({"id": 123}))
    refuses("refuses-retry-start-overwrite", lambda raw: raw["samples"][0].update({"attempts": 2}))
    refuses("refuses-negative-latency", lambda raw: raw["samples"][0].update({
        "created_at": "2026-01-01 01:00:00.000"
    }))
    refuses("refuses-unstarted-job", lambda raw: raw["samples"][0].update({
        "started_at": None, "status": "queued", "attempts": 0
    }))
    refuses("refuses-nonterminal-processing-outcome", lambda raw: raw["samples"][0].update({
        "status": "processing"
    }))
    refuses("refuses-subsecond-timestamp-drift", lambda raw: raw["samples"][0].update({
        "created_at": "2026-01-01 00:00:00.123"
    }))
    refuses("refuses-extra-correlation-material", lambda raw: raw["samples"][0].update({
        "correlation": "sensitive-token"
    }))

    # Explicit-tick control: the captured CLI output must be the exact processed form.
    def tick_refuses(name: str, text: str, rc: int = 0) -> None:
        try:
            parse_tick_output(text, rc)
        except JobStartRefused:
            check(name, True)
            return
        check(name, False)

    check("tick-processed-batch-parses", parse_tick_output("Processed 100 job(s)\n", 0) == 100)
    tick_refuses("tick-skipped-lock-held-is-never-success", "Another tick is running; skipped.\n")
    tick_refuses("tick-skipped-lock-unavailable-is-never-success", "Tick skipped (lock unavailable).\n")
    tick_refuses("tick-nonzero-exit-refused", "Processed 100 job(s)\n", 1)
    tick_refuses("tick-boolean-exit-refused", "Processed 100 job(s)\n", True)
    tick_refuses("tick-extra-output-line-refused", "Processed 100 job(s)\nPHP Warning: sentinel\n")
    tick_refuses("tick-empty-output-refused", "")
    tick_refuses("tick-negative-count-refused", "Processed -1 job(s)\n")
    tick_refuses("tick-unexpected-wording-refused", "Processed 100 jobs\n")

    def aggregate_with_tick_refuses(name: str, mode: str, processed: int) -> None:
        try:
            aggregate_raw_job_start(_fixture_raw(mode), processed)
        except JobStartRefused:
            check(name, True)
            return
        check(name, False)

    covered = aggregate_raw_job_start(_fixture_raw(), 100)
    check("explicit-tick-covering-batch-publishes", covered["p50_ms"] == valid["p50_ms"])
    aggregate_with_tick_refuses("explicit-tick-processed-zero-refused-when-batch-succeeded",
                                "explicit_tick", 0)
    aggregate_with_tick_refuses("explicit-tick-processed-below-batch-refused", "explicit_tick", 99)
    aggregate_with_tick_refuses("tick-count-never-attaches-to-autonomous-mode", "autonomous", 100)

    with tempfile.TemporaryDirectory() as temporary_directory:
        raw_path = Path(temporary_directory) / "raw.json"
        raw_path.write_text(json.dumps(_fixture_raw()), encoding="utf-8")
        check("strict-json-reader-roundtrip",
              len(read_raw_file(raw_path)["samples"]) == SAMPLE_COUNT)
        raw_path.write_text('{"schema":"one","schema":"two"}', encoding="utf-8")
        try:
            read_raw_file(raw_path)
        except JobStartRefused:
            check("strict-json-reader-refuses-duplicate-keys", True)
        else:
            check("strict-json-reader-refuses-duplicate-keys", False)

    print("SELF-TESTS: %s (%d checks)%s" % (
        "FAILED" if failures else "OK",
        checks,
        " — " + ", ".join(failures) if failures else " (pilot-job-start — first-claim boundary, retry refusal, privacy, stats)",
    ))
    return 1 if failures else 0


def main(argv: List[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate and aggregate Pilot queue first-start samples.")
    parser.add_argument("--test", action="store_true", help="run deterministic self-tests")
    parser.add_argument("--raw", default="", help="private raw queue sample JSON path")
    parser.add_argument("--out", default="", help="write the safe aggregate JSON path")
    parser.add_argument("--tick-output", default="", help="captured stdout of the explicit tick (explicit control)")
    parser.add_argument("--tick-rc", type=int, default=0, help="exit status of the explicit tick")
    args = parser.parse_args(argv)

    if args.test:
        return _selftests()
    if not args.raw or not args.out:
        parser.error("--raw and --out are required unless --test is used")
    try:
        tick_processed = None
        if args.tick_output:
            try:
                tick_text = Path(args.tick_output).read_text(encoding="utf-8")
            except (OSError, UnicodeError) as exc:
                raise JobStartRefused("tick_output_unreadable") from exc
            tick_processed = parse_tick_output(tick_text, args.tick_rc)
            print("DIAG tick.outcome=processed")
            print("DIAG tick.processed=%d" % min(tick_processed, 999999))
        aggregate = aggregate_raw_job_start(read_raw_file(Path(args.raw)), tick_processed)
        write_aggregate(Path(args.out), aggregate)
    except JobStartRefused as refused:
        # Fixed reason code only (never free-form text); the explicit control prints it.
        print("DIAG refusal_reason=%s" % refused.args[0])
        print("JOB_START: evidence refused; no aggregate was published", file=sys.stderr)
        return 1
    except (OSError, ValueError):
        print("DIAG refusal_reason=io_or_value")
        print("JOB_START: evidence refused; no aggregate was published", file=sys.stderr)
        return 1
    print("JOB_START: 100 {mode} samples aggregated; no latency threshold applied".format(
        mode=aggregate["measurement_mode"]))
    return 0


if __name__ == "__main__":
    sys.exit(main())

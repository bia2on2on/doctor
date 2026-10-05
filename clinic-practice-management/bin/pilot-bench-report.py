#!/usr/bin/env python3
"""
pilot-bench-report.py — Phase 17 bounded capacity diagnosis: deterministic parse/report layer for the
existing Pilot/Staging `ab` benchmark step (MEASUREMENT ONLY).

What this tool is:
    A pure parsing + reporting layer over ApacheBench (`ab`) text dumps produced by the
    `staging-gate` job of `.github/workflows/pilot-gate.yml`. It performs no HTTP request,
    starts no server, changes no product behavior, and enforces no latency threshold.

What this tool is NOT:
    It is NOT an NFR-PERF adjudicator. Shared GitHub-runner latency is not evidence of
    reference-server NFR compliance in either direction; that adjudication stays with the
    reference-server benchmark Runbook (`docs/phase-reports/report-pilot-gate.md` §12.3 —
    BLOCKED_BY_ENVIRONMENT) and the approved methodology
    (`docs/performance/performance-baseline.md` §3/§5).

Interface (repository-native harness convention — same shape as bin/tenant-tripwire.py and
bin/wpcs-changed-lines.py, both of which expose a `--test` self-test mode used by CI):
    --test                 run the built-in deterministic self-tests and exit
    --scan-dir DIR         scan raw ab dumps before upload; exit 1 on any identity/PHI content
    --manifest FILE        JSONL manifest, one measurement per line (written by the workflow)
    --raw-dir DIR          directory holding the raw `ab` dumps named by the manifest
    --out-prefix PATH      writes PATH.json, PATH.md and PATH.txt
    --evidence-prefix PATH writes the allowlisted REST-visible safe evidence projection
                           (PATH.json + PATH.md comment body) from the post-scan structured
                           report — refused fail-closed on malformed/unexpected structure
    --verify-evidence FILE re-validate an existing projection file against the allowlist
                           (no network, no writes) — used before publication
    --diagnostics-dir DIR read bounded Apache/host diagnostic JSON produced by
                           pilot-bench-diagnostics.py; measurement failure is fail-closed and
                           reported separately from ab/product correctness
    --meta KEY=VALUE       repeatable run/environment metadata recorded verbatim in JSON+MD
    --fail-file PATH       optional: also write the human-readable failure reason here

Exit codes:
    0 — parsed and reported successfully (no latency judgement is made)
    1 — a measurement could not be parsed / its dump is missing / it produced Non-2xx
        responses — i.e. exactly the two correctness failures the pre-existing inline
        shell parser already had, and nothing else — or the safe evidence projection was
        REFUSED because the structured report did not match the allowlist/value contract
        (fail closed: nothing is written, so nothing can be published)
    2 — configuration/usage error (never a green no-op)

Safe evidence projection: the ONLY benchmark payload allowed to leave the runner through a
REST-visible PR/commit comment. It publishes an explicit allowlist per measurement
(seq, phase cold|warm, fixed endpoint label, concurrency, request counts, p50/p95/p99,
requests/sec, failed and non-2xx counts) plus the run binding (run_id, run_attempt,
event_name, head_sha, ref). This slice additionally publishes only the bounded diagnostic
schema: runner CPU count, active MPM, five effective worker-capacity values, and per-level
summary fields for host CPU/memory, Apache/PHP server CPU/memory/process count, and `ab`
CPU/RSS. Raw `ab` bytes, response/header/body content, environment metadata, free-form
manifest text, URLs/paths and file contents can never enter it.

Privacy invariant (contract §9): the generated JSON/MD/TXT are scanned before being written;
Cookie/nonce/credential/PHI-shaped content aborts the run with rc=1. Only numbers, fixed
labels and the relative `Document Path` echoed by `ab` ever reach the output.
"""

from __future__ import annotations

import argparse
import json
import math
import re
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

# ---------------------------------------------------------------------------
# ab output vocabulary — (json key, ab line label, value kind)
# ---------------------------------------------------------------------------

SCALARS: Tuple[Tuple[str, str, str], ...] = (
    ("server_software", "Server Software", "str"),
    ("document_path", "Document Path", "str"),
    ("document_length_bytes", "Document Length", "num"),
    ("concurrency_level", "Concurrency Level", "num"),
    ("time_taken_seconds", "Time taken for tests", "num"),
    ("complete_requests", "Complete requests", "num"),
    ("failed_requests", "Failed requests", "num"),
    ("non_2xx_responses", "Non-2xx responses", "num"),
    ("requests_per_second", "Requests per second", "num"),
    ("transfer_rate_kbytes_sec", "Transfer rate", "num"),
)

# `Time per request:` appears twice — (mean) then (mean, across all concurrent requests).
TIME_PER_REQUEST_MEAN = re.compile(
    r"^Time per request:\s+([0-9.]+)\s+\[ms\]\s+\(mean\)\s*$", re.MULTILINE
)
TIME_PER_REQUEST_ACROSS = re.compile(
    r"^Time per request:\s+([0-9.]+)\s+\[ms\]\s+\(mean, across all concurrent requests\)\s*$",
    re.MULTILINE,
)
PERCENTILE_LINE = re.compile(r"^\s*([0-9]+)%\s+([0-9]+(?:\.[0-9]+)?)(?=\s|$)", re.MULTILINE)
PERCENTILES = (50, 66, 75, 80, 90, 95, 98, 99, 100)

# The required-metric gate mirrors the pre-existing shell gate exactly: RPS + p95.
REQUIRED_METRICS = ("requests_per_second", "p95_ms")

# ---------------------------------------------------------------------------
# Privacy / security scan of the bytes we are about to persist
# ---------------------------------------------------------------------------

FORBIDDEN_PATTERNS: Tuple[Tuple[str, str], ...] = (
    ("cookie", r"(?i)\bset-cookie\b|\bcookie\s*:"),
    ("authorization", r"(?i)\bauthorization\b|x-powered-by"),
    ("nonce", r"(?i)\b_?wp_?nonce\b|\bnonce\b"),
    (
        "credential",
        r"(?i)\b(password|passwd|dbpass|secret|api[_-]?key|access[_-]?token|client[_-]?secret|private[_-]?key)\b",
    ),
    ("auth_token", r"(?i)\bbearer\s+[a-z0-9._-]{8,}"),
    ("synthetic_phi", r"SYN-[0-9]{3,}|SYNAP-[0-9]{3,}|0912[0-9]{6,}|بیمار آزمایشی"),
    ("php_tag", r"<\?php"),
    ("abs_host", r"(?i)https?://(?!localhost)"),
)


def scan_forbidden(text: str) -> List[str]:
    """Return the names of forbidden categories found in `text` (empty list = clean)."""
    return [name for name, pattern in FORBIDDEN_PATTERNS if re.search(pattern, text)]


# Raw `ab` dumps are not uploaded blind: they are scanned with the same identity rules minus
# `abs_host`/`php_tag`, which `ab` itself always trips on (its banner carries the upstream
# project URLs). This keeps "artifacts contain no PHI/cookies/credentials/nonces" a *checked*
# property instead of an assertion.
RAW_FORBIDDEN_PATTERNS: Tuple[Tuple[str, str], ...] = tuple(
    (name, pattern)
    for name, pattern in FORBIDDEN_PATTERNS
    if name not in ("abs_host", "php_tag")
)


def scan_raw_tree(directory: Path) -> List[str]:
    """Scan every file under `directory`; return '<file>: <category>' problems (empty = clean)."""
    problems: List[str] = []
    if not directory.is_dir():
        return [f"{directory}: not a directory — refusing to certify an unscanned upload"]
    for path in sorted(p for p in directory.rglob("*") if p.is_file()):
        text = path.read_text(encoding="utf-8", errors="replace")
        for name, pattern in RAW_FORBIDDEN_PATTERNS:
            if re.search(pattern, text):
                problems.append(f"{path.name}: {name}")
    if not problems and not any(p.is_file() for p in directory.rglob("*")):
        problems.append(f"{directory}: no dumps found")
    return problems


# ---------------------------------------------------------------------------
# Parsing
# ---------------------------------------------------------------------------


def _number(rest: str) -> Optional[float]:
    """First numeric token of an `ab` value tail, e.g. '132 bytes' -> 132, '33.33 [#/sec]' -> 33.33."""
    match = re.search(r"[0-9]+(?:\.[0-9]+)?", rest)
    if not match:
        return None
    value = float(match.group(0))
    return int(value) if "." not in match.group(0) else value


def parse_ab_output(raw: str, expected_path: Optional[str] = None) -> Tuple[Dict[str, Any], List[str]]:
    """Parse one ApacheBench dump.

    Returns `(metrics, problems)`; `problems` is empty when every required metric is present.
    Optional lines are deliberately tolerated as null (mirroring the pre-existing inline
    parser); only the two metrics that parser required — RPS and p95 — are mandatory here.
    """
    metrics: Dict[str, Any] = {}
    problems: List[str] = []

    for key, label, kind in SCALARS:
        match = re.search(
            r"^" + re.escape(label) + r":\s+(?P<rest>.*\S)\s*$", raw, re.MULTILINE
        )
        if not match:
            metrics[key] = None
            continue
        rest = match.group("rest")
        metrics[key] = rest.strip() if kind == "str" else _number(rest)

    mean_match = TIME_PER_REQUEST_MEAN.search(raw)
    across_match = TIME_PER_REQUEST_ACROSS.search(raw)
    metrics["time_per_request_mean_ms"] = float(mean_match.group(1)) if mean_match else None
    metrics["time_per_request_across_ms"] = (
        float(across_match.group(1)) if across_match else None
    )

    percentiles = {int(p): float(v) for p, v in PERCENTILE_LINE.findall(raw)}
    for pct in PERCENTILES:
        metrics[f"p{pct}_ms"] = percentiles.get(pct)

    for field in REQUIRED_METRICS:
        if metrics.get(field) is None:
            problems.append(f"missing required metric {field}")
    if metrics.get("complete_requests") in (None, 0):
        problems.append("ab reported no completed requests")
    if expected_path is not None:
        # Loud guard against a measurement silently hitting a different route than declared.
        # ab prints `Document Path:` as requested, query string included, so only the path
        # part is compared.
        reported = (metrics.get("document_path") or "").split("?", 1)[0]
        if reported != expected_path:
            problems.append(
                "document path mismatch: ab=%r manifest=%r" % (reported, expected_path)
            )

    return metrics, problems


# ---------------------------------------------------------------------------
# Bounded native diagnostics
# ---------------------------------------------------------------------------

DIAGNOSTIC_SCHEMA = "cpms.pilot-bench-diagnostics/1"
DIAGNOSTIC_STATIC_FIELDS: Tuple[str, ...] = (
    "schema",
    "status",
    "runner_cpu_count",
    "active_mpm",
    "php_execution_mode",
    "server_limit",
    "thread_limit",
    "threads_per_child",
    "max_request_workers",
    "max_connections_per_child",
)
DIAGNOSTIC_ROW_FIELDS: Tuple[str, ...] = (
    "schema",
    "seq",
    "phase",
    "concurrency",
    "status",
    "command_exit_code",
    "sample_count",
    "sample_interval_ms",
    "elapsed_ms",
    "runner_cpu_count",
    "host_cpu_avg_pct",
    "host_cpu_max_pct",
    "host_mem_available_min_mb",
    "host_mem_used_max_pct",
    "ab_cpu_avg_pct",
    "ab_cpu_max_pct",
    "ab_rss_max_mb",
    "server_cpu_avg_pct",
    "server_cpu_max_pct",
    "server_rss_max_mb",
    "server_process_count_max",
)
DIAGNOSTIC_RESOURCE_FIELDS: Tuple[str, ...] = (
    "seq",
    "sample_count",
    "sample_interval_ms",
    "elapsed_ms",
    "host_cpu_avg_pct",
    "host_cpu_max_pct",
    "host_mem_available_min_mb",
    "host_mem_used_max_pct",
    "ab_cpu_avg_pct",
    "ab_cpu_max_pct",
    "ab_rss_max_mb",
    "server_cpu_avg_pct",
    "server_cpu_max_pct",
    "server_rss_max_mb",
    "server_process_count_max",
)
DIAGNOSTIC_MPMS: Tuple[str, ...] = ("event", "worker", "prefork")
DIAGNOSTIC_PHP_MODES: Tuple[str, ...] = ("mod_php", "php_fpm")
DIAGNOSTIC_MAX_CPU = 256
DIAGNOSTIC_MAX_COUNT = 10_000
DIAGNOSTIC_MAX_MB = 10_000_000.0
DIAGNOSTIC_MAX_PROCESS_CPU = 25_600.0


class DiagnosticInputRefused(ValueError):
    """The native diagnostic evidence is absent, malformed, or not measurement-safe."""


def _diagnostic_int(value: Any, field: str, low: int, high: int) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or not low <= value <= high:
        raise DiagnosticInputRefused(f"{field}: bounded integer invariant failed")
    return value


def _diagnostic_number(value: Any, field: str, low: float, high: float) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise DiagnosticInputRefused(f"{field}: bounded number invariant failed")
    number = float(value)
    if not math.isfinite(number) or not low <= number <= high:
        raise DiagnosticInputRefused(f"{field}: bounded number invariant failed")
    return number


def _load_json_file(path: Path) -> Dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        raise DiagnosticInputRefused("diagnostic_file_invalid")
    if not isinstance(value, dict):
        raise DiagnosticInputRefused("diagnostic_object_expected")
    return value


def _validate_diagnostic_static(raw: Any) -> Dict[str, Any]:
    if not isinstance(raw, dict) or set(raw) != set(DIAGNOSTIC_STATIC_FIELDS):
        raise DiagnosticInputRefused("diagnostic_static_field_set_invalid")
    if raw["schema"] != DIAGNOSTIC_SCHEMA or raw["status"] != "ok":
        raise DiagnosticInputRefused("diagnostic_static_not_ok")
    result = dict(raw)
    result["runner_cpu_count"] = _diagnostic_int(raw["runner_cpu_count"], "runner_cpu_count", 1, DIAGNOSTIC_MAX_CPU)
    if raw["active_mpm"] not in DIAGNOSTIC_MPMS:
        raise DiagnosticInputRefused("active_mpm_not_allowlisted")
    if raw["php_execution_mode"] not in DIAGNOSTIC_PHP_MODES:
        raise DiagnosticInputRefused("php_execution_mode_not_allowlisted")
    for field in ("server_limit", "thread_limit", "threads_per_child", "max_request_workers", "max_connections_per_child"):
        result[field] = _diagnostic_int(raw[field], field, 0, 1_000_000)
    if result["threads_per_child"] < 1 or result["max_request_workers"] < 1:
        raise DiagnosticInputRefused("worker_capacity_not_bounded")
    return result


def _validate_diagnostic_row(raw: Any, expected_seq: int) -> Dict[str, Any]:
    if not isinstance(raw, dict) or set(raw) != set(DIAGNOSTIC_ROW_FIELDS):
        raise DiagnosticInputRefused("diagnostic_row_field_set_invalid")
    if raw["schema"] != DIAGNOSTIC_SCHEMA or raw["status"] != "ok":
        raise DiagnosticInputRefused("diagnostic_row_not_ok")
    seq = _diagnostic_int(raw["seq"], "diagnostic.seq", 1, 9999)
    if seq != expected_seq:
        raise DiagnosticInputRefused("diagnostic_seq_not_contiguous")
    phase = raw["phase"]
    if phase not in ("cold", "warm"):
        raise DiagnosticInputRefused("diagnostic_phase_invalid")
    _diagnostic_int(raw["concurrency"], "diagnostic.concurrency", 1, 1000)
    _diagnostic_int(raw["command_exit_code"], "diagnostic.command_exit_code", 0, 255)
    _diagnostic_int(raw["sample_count"], "diagnostic.sample_count", 1, DIAGNOSTIC_MAX_COUNT)
    _diagnostic_number(raw["sample_interval_ms"], "diagnostic.sample_interval_ms", 1.0, 10_000.0)
    _diagnostic_number(raw["elapsed_ms"], "diagnostic.elapsed_ms", 0.0, 3_600_000.0)
    _diagnostic_int(raw["runner_cpu_count"], "diagnostic.runner_cpu_count", 1, DIAGNOSTIC_MAX_CPU)
    for field in ("host_cpu_avg_pct", "host_cpu_max_pct", "host_mem_used_max_pct"):
        _diagnostic_number(raw[field], "diagnostic." + field, 0.0, 100.0)
    for field in ("ab_cpu_avg_pct", "ab_cpu_max_pct", "server_cpu_avg_pct", "server_cpu_max_pct"):
        _diagnostic_number(raw[field], "diagnostic." + field, 0.0, DIAGNOSTIC_MAX_PROCESS_CPU)
    for field in ("host_mem_available_min_mb", "ab_rss_max_mb", "server_rss_max_mb"):
        _diagnostic_number(raw[field], "diagnostic." + field, 0.0, DIAGNOSTIC_MAX_MB)
    _diagnostic_int(raw["server_process_count_max"], "diagnostic.server_process_count_max", 1, DIAGNOSTIC_MAX_COUNT)
    if raw["host_cpu_avg_pct"] > raw["host_cpu_max_pct"]:
        raise DiagnosticInputRefused("diagnostic_host_cpu_not_monotonic")
    return dict(raw)


def read_diagnostics(directory: Path, expected_count: int) -> Dict[str, Any]:
    """Read only the helper's exact bounded JSON shape; never read raw command output."""
    if not directory.is_dir():
        raise DiagnosticInputRefused("diagnostic_directory_missing")
    static = _validate_diagnostic_static(_load_json_file(directory / "static.json"))
    files = sorted(path for path in directory.glob("level-*.json") if path.is_file())
    if len(files) != expected_count:
        raise DiagnosticInputRefused("diagnostic_level_count_mismatch")
    rows = [_validate_diagnostic_row(_load_json_file(path), index) for index, path in enumerate(files, start=1)]
    if [row["seq"] for row in rows] != list(range(1, expected_count + 1)):
        raise DiagnosticInputRefused("diagnostic_level_sequence_mismatch")
    for row in rows:
        if row["runner_cpu_count"] != static["runner_cpu_count"]:
            raise DiagnosticInputRefused("diagnostic_runner_cpu_count_mismatch")
    return {"schema": DIAGNOSTIC_SCHEMA, "status": "ok", "static": static, "measurements": rows}


# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------

COLD_DEFINITION = (
    "cold = the first HTTP requests served after this step restarted the Apache/mod_php "
    "process group (fresh PHP processes, empty PHP opcode cache), with NO warm-up request "
    "before the run; readiness was awaited at TCP level only, so no HTTP traffic preceded it. "
    "This is NOT an infrastructure-level cold-cache state: the MySQL InnoDB buffer pool and the "
    "OS page cache were already warmed by earlier gate steps and this harness neither flushes "
    "them nor can guarantee them cold. Only the first endpoint in the cold sequence is fully "
    "process-cold; later ones share already-compiled core/plugin bytecode."
)
WARM_DEFINITION = (
    "warm = the existing measurement set, executed after the cold phase on the already-served "
    "stack, each run preceded by the two pre-existing `curl` warm-up requests (PHP opcode cache "
    "+ MySQL buffer pool warm). Concurrency 10/50/100 are the pre-existing levels; c=1 is newly "
    "added. No latency threshold is applied to warm numbers."
)
ADJUDICATION = (
    "COLLECTION ONLY — no NFR-PERF pass/fail is claimed and none may be inferred from this "
    "artifact. Shared GitHub-runner latency is not reference-server evidence in either "
    "direction. Reference-environment adjudication stays in "
    "docs/phase-reports/report-pilot-gate.md §12.3 (BLOCKED_BY_ENVIRONMENT); methodology in "
    "docs/performance/performance-baseline.md §3/§5."
)


def build_report(measurements: List[Dict[str, Any]], meta: Dict[str, Any],
                 diagnostics: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    if diagnostics is not None and diagnostics.get("status") == "ok":
        by_seq = {row["seq"]: row for row in diagnostics["measurements"]}
        for measurement in measurements:
            if measurement.get("seq") in by_seq:
                measurement["diagnostics"] = by_seq[measurement["seq"]]
    return {
        "schema": "cpms.pilot-benchmark/2",        "purpose": (
            "Phase 17 bounded capacity diagnosis — measurement only; no product optimization, "
            "no latency gate, no NFR adjudication, no committed numbers"
        ),
        "run": meta,
        "endpoints": [
            {
                "key": "health",
                "path": "/wp-json/clinic/v1/health",
                "note": "public plugin health endpoint — unauthenticated, no PHI",
            },
            {
                "key": "availability",
                "path": "/wp-json/clinic/v1/availability",
                "query_params": ["clinician_id", "from", "to"],
                "note": (
                    "calendar read; the endpoint the historical NFR-PERF-1 label referred to. "
                    "Query values are the pilot clinician id and a UTC date window — recorded by "
                    "name only here, never as PHI"
                ),
            },
            {
                "key": "wp-json-root",
                "path": "/wp-json/",
                "note": "WordPress core reference for the same environment",
            },
        ],
        "cold_definition": COLD_DEFINITION,
        "warm_definition": WARM_DEFINITION,
        "adjudication": ADJUDICATION,
        "latency_threshold_enforced": False,
        "nfr_perf_pass_claim": False,
        "diagnostics": diagnostics,
        "measurement_count": len(measurements),
        "measurements": measurements,
    }


def render_markdown(report: Dict[str, Any]) -> str:
    run_meta = report.get("run", {})
    lines: List[str] = [
        "# Pilot/Staging Benchmark — cold/warm baseline (MEASUREMENT ONLY)",
        "",
        "| phase | endpoint | c | n (planned) | complete | p50 ms | p95 ms | p99 ms | RPS | failed | non2xx |",
        "|---|---|---|---|---|---|---|---|---|---|---|",
    ]
    for m in report["measurements"]:
        metrics = m.get("metrics") or {}
        lines.append(
            "| {phase} | {label} | {c} | {n} | {complete} | {p50} | {p95} | {p99} | {rps} | "
            "{failed} | {non2xx} |".format(
                phase=m.get("phase"),
                label=m.get("label"),
                c=m.get("concurrency"),
                n=m.get("requests_planned"),
                complete=metrics.get("complete_requests"),
                p50=metrics.get("p50_ms"),
                p95=metrics.get("p95_ms"),
                p99=metrics.get("p99_ms"),
                rps=metrics.get("requests_per_second"),
                failed=metrics.get("failed_requests"),
                non2xx=metrics.get("non_2xx_responses") or 0,
            )
        )
    lines += [
        "",
        "## What the harness actually did",
        "",
        f"- **cold**: {report['cold_definition']}",
        "",
        f"- **warm**: {report['warm_definition']}",
        "",
        f"- **adjudication**: {report['adjudication']}",
        "",
        "- **latency threshold enforced by this run**: "
        + ("yes" if report["latency_threshold_enforced"] else "no"),
        "- **NFR-PERF pass/fail claimed here**: no",
        "",
        "## Endpoints (fixed set — unchanged by this slice)",
        "",
    ]
    for endpoint in report["endpoints"]:
        lines.append(f"- `{endpoint['path']}` — {endpoint['note']}")
    lines += [
        "",
        "## Run binding (exact workflow run / head SHA)",
        "",
        "```",
    ]
    for key in sorted(run_meta):
        lines.append(f"{key}={run_meta[key]}")
    lines += [
        "```",
        "",
        "Per-run numbers are deliberately NOT committed to repository history; this artifact is "
        "the per-run record for this exact run/head SHA.",
        "",
    ]
    return "\n".join(lines)


def render_legacy_lines(report: Dict[str, Any]) -> List[str]:
    """Pre-existing `/tmp/bench.txt` line shape, preserved for gate-logs + failure dumps."""
    out: List[str] = []
    for m in report["measurements"]:
        metrics = m.get("metrics") or {}
        out.append(
            "{label} | phase={phase} | c={c} | p50={p50}ms p95={p95}ms p99={p99}ms | rps={rps} "
            "| failed={failed} non2xx={non2xx} | n={complete}/{planned}".format(
                label=m.get("label"),
                phase=m.get("phase"),
                c=m.get("concurrency"),
                p50=metrics.get("p50_ms"),
                p95=metrics.get("p95_ms"),
                p99=metrics.get("p99_ms"),
                rps=metrics.get("requests_per_second"),
                failed=metrics.get("failed_requests"),
                non2xx=metrics.get("non_2xx_responses") or 0,
                complete=metrics.get("complete_requests"),
                planned=m.get("requests_planned"),
            )
        )
    return out


# ---------------------------------------------------------------------------
# Safe evidence projection — the ONLY benchmark payload allowed to leave the
# runner through a REST-visible PR/commit comment.
#
# Rationale: the run-bound artifact (`pilot-benchmark-<run_id>`, 14-day
# retention) is not retrievable from a sandbox/agent that has no Azure Blob or
# artifact-download access, and the full report JSON deliberately carries
# environment metadata (runner/PHP/Apache/MySQL/WP versions, dataset line) that
# must not be published. This projection is therefore built from the ALREADY
# PARSED + PRIVACY-SCANNED structured measurements and emits an explicit
# allowlist of fields — never raw `ab` bytes, never free-form text, never the
# manifest `label` (which is human prose), never a URL/path, never environment
# metadata except the explicitly allowlisted diagnostic summaries. Every emitted field is
# validated (type/range/allowlist) before the file is written; malformed or unexpected structure REFUSES the projection
# (fail closed) and nothing is published.
# ---------------------------------------------------------------------------

SAFE_EVIDENCE_SCHEMA = "cpms.pilot-bench-evidence/2"
SAFE_EVIDENCE_PURPOSE = (
    "Phase 17 — allowlisted privacy-safe projection of one Pilot/Staging benchmark run; "
    "measurement only, no NFR-PERF adjudication, no raw benchmark bytes, no free-form text, "
    "no unallowlisted environment metadata"
)

# The endpoint LABEL is a constant mapped from the endpoint KEY. Free-form
# manifest/report text (human labels, query strings, paths, `ab` banner echo)
# can never enter the projection.
SAFE_ENDPOINT_LABELS: Dict[str, str] = {
    "health": "GET /health",
    "availability": "GET /availability",
    "wp-json-root": "GET /wp-json/",
}
SAFE_PHASES: Tuple[str, ...] = ("cold", "warm")
SAFE_EVENTS: Tuple[str, ...] = ("push", "schedule", "workflow_dispatch")

# Exact field sets — equality is required, so an unexpected/extra field is a
# refusal rather than a silent pass-through.
SAFE_TOP_LEVEL_FIELDS: Tuple[str, ...] = (
    "schema",
    "purpose",
    "binding",
    "measurement_count",
    "diagnostics",
    "measurements",
)
SAFE_BINDING_KEYS: Tuple[str, ...] = ("run_id", "run_attempt", "event_name", "head_sha", "ref")
SAFE_MEASUREMENT_FIELDS: Tuple[str, ...] = (
    "seq",
    "phase",
    "endpoint",
    "endpoint_label",
    "concurrency",
    "requests_planned",
    "requests_complete",
    "p50_ms",
    "p95_ms",
    "p99_ms",
    "requests_per_second",
    "failed_requests",
    "non_2xx_responses",
)
SAFE_DIAGNOSTIC_FIELDS: Tuple[str, ...] = (
    "runner_cpu_count",
    "active_mpm",
    "php_execution_mode",
    "server_limit",
    "thread_limit",
    "threads_per_child",
    "max_request_workers",
    "max_connections_per_child",
    "measurements",
)
SAFE_DIAGNOSTIC_ROW_FIELDS: Tuple[str, ...] = ("seq", "resources")
SAFE_DIAGNOSTIC_RESOURCE_FIELDS: Tuple[str, ...] = (
    "sample_count",
    "sample_interval_ms",
    "elapsed_ms",
    "host_cpu_avg_pct",
    "host_cpu_max_pct",
    "host_mem_available_min_mb",
    "host_mem_used_max_pct",
    "ab_cpu_avg_pct",
    "ab_cpu_max_pct",
    "ab_rss_max_mb",
    "server_cpu_avg_pct",
    "server_cpu_max_pct",
    "server_rss_max_mb",
    "server_process_count_max",
)

MAX_LATENCY_MS = 3_600_000.0  # 1 hour — far above any runner measurement, still bounded
MAX_RPS = 1_000_000.0
MAX_REQUESTS = 10_000_000
MAX_CONCURRENCY = 1_000
SAFE_REF_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$")
SAFE_SHA_RE = re.compile(r"^[0-9a-f]{40}$")
SAFE_EVIDENCE_JSON_FENCE_RE = re.compile(r"^```json\n(?P<body>\{.*?\n\})\n```$", re.MULTILINE | re.DOTALL)


class EvidenceRefused(ValueError):
    """The safe projection cannot be validated — nothing may be published (fail closed)."""


def _describe(value: Any) -> str:
    """Echo a value only when it is a short inert token; never free-form text."""
    if isinstance(value, bool) or value is None or isinstance(value, (int, float)):
        return repr(value)
    if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9._/-]{1,40}", value):
        return repr(value)
    return "<non-allowlisted value>"


def _safe_int(value: Any, field: str, low: int, high: int) -> int:
    if isinstance(value, bool) or not isinstance(value, int):
        raise EvidenceRefused(f"{field}: expected integer, got {type(value).__name__} ({_describe(value)})")
    if not low <= value <= high:
        raise EvidenceRefused(f"{field}: integer out of range {low}..{high}: {_describe(value)}")
    return value


def _safe_number(value: Any, field: str, low: float, high: float) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise EvidenceRefused(f"{field}: expected number, got {type(value).__name__} ({_describe(value)})")
    number = float(value)
    if not math.isfinite(number):
        raise EvidenceRefused(f"{field}: not a finite number")
    if not low <= number <= high:
        raise EvidenceRefused(f"{field}: number out of range {low}..{high}: {_describe(value)}")
    return number


def _safe_digits(value: Any, field: str, max_len: int, minimum: int) -> str:
    if not isinstance(value, str) or not re.fullmatch(r"[0-9]{1,%d}" % max_len, value):
        raise EvidenceRefused(f"{field}: expected a decimal identifier, got {_describe(value)}")
    if int(value) < minimum:
        raise EvidenceRefused(f"{field}: value below {minimum}: {_describe(value)}")
    return value


def _validate_binding(raw: Any) -> Dict[str, str]:
    if not isinstance(raw, dict):
        raise EvidenceRefused("binding: expected object")
    if set(raw) != set(SAFE_BINDING_KEYS):
        raise EvidenceRefused(
            "binding: unexpected field set (expected exactly %s)" % ", ".join(SAFE_BINDING_KEYS)
        )
    for key in SAFE_BINDING_KEYS:
        if not isinstance(raw[key], str) or raw[key] == "":
            raise EvidenceRefused(f"binding.{key}: missing or not a non-empty string")
    binding = {
        "run_id": _safe_digits(raw["run_id"], "binding.run_id", 20, 1),
        "run_attempt": _safe_digits(raw["run_attempt"], "binding.run_attempt", 10, 1),
        "event_name": raw["event_name"],
        "head_sha": raw["head_sha"],
        "ref": raw["ref"],
    }
    if binding["event_name"] not in SAFE_EVENTS:
        raise EvidenceRefused(
            "binding.event_name: not an allowlisted trigger (%s)" % ", ".join(SAFE_EVENTS)
        )
    if not SAFE_SHA_RE.fullmatch(binding["head_sha"]):
        raise EvidenceRefused("binding.head_sha: expected a lowercase 40-hex SHA")
    if not SAFE_REF_RE.fullmatch(binding["ref"]) or ".." in binding["ref"] or "//" in binding["ref"]:
        raise EvidenceRefused(f"binding.ref: not a safe ref token ({_describe(binding['ref'])})")
    return binding


def _validate_measurement_row(raw: Any, expected_seq: int) -> Dict[str, Any]:
    if not isinstance(raw, dict):
        raise EvidenceRefused(f"measurement[seq={expected_seq}]: expected object")
    if set(raw) != set(SAFE_MEASUREMENT_FIELDS):
        raise EvidenceRefused(
            f"measurement[seq={expected_seq}]: unexpected field set (expected exactly "
            + ", ".join(SAFE_MEASUREMENT_FIELDS)
            + ")"
        )
    phase = raw["phase"]
    if not isinstance(phase, str) or phase not in SAFE_PHASES:
        raise EvidenceRefused(
            f"measurement[seq={expected_seq}].phase: only {', '.join(SAFE_PHASES)} are allowed "
            f"({_describe(phase)})"
        )
    endpoint = raw["endpoint"]
    if not isinstance(endpoint, str) or endpoint not in SAFE_ENDPOINT_LABELS:
        raise EvidenceRefused(
            f"measurement[seq={expected_seq}].endpoint: not an allowlisted endpoint key "
            f"({_describe(endpoint)})"
        )
    seq = _safe_int(raw["seq"], f"measurement[{expected_seq}].seq", 1, 9999)
    if seq != expected_seq:
        raise EvidenceRefused(
            f"measurement.seq: expected {expected_seq}, got {seq} (non-contiguous sequence)"
        )
    concurrency = _safe_int(
        raw["concurrency"], f"measurement[seq={seq}].concurrency", 1, MAX_CONCURRENCY
    )
    planned = _safe_int(
        raw["requests_planned"], f"measurement[seq={seq}].requests_planned", 1, MAX_REQUESTS
    )
    complete = _safe_int(
        raw["requests_complete"], f"measurement[seq={seq}].requests_complete", 1, planned
    )
    p50 = _safe_number(raw["p50_ms"], f"measurement[seq={seq}].p50_ms", 0.0, MAX_LATENCY_MS)
    p95 = _safe_number(raw["p95_ms"], f"measurement[seq={seq}].p95_ms", 0.0, MAX_LATENCY_MS)
    p99 = _safe_number(raw["p99_ms"], f"measurement[seq={seq}].p99_ms", 0.0, MAX_LATENCY_MS)
    if not p50 <= p95 <= p99:
        raise EvidenceRefused(
            f"measurement[seq={seq}]: percentiles are not monotonic (p50 <= p95 <= p99)"
        )
    rps = _safe_number(
        raw["requests_per_second"], f"measurement[seq={seq}].requests_per_second", 0.0, MAX_RPS
    )
    failed = _safe_int(
        raw["failed_requests"], f"measurement[seq={seq}].failed_requests", 0, complete
    )
    non_2xx = _safe_int(
        raw["non_2xx_responses"], f"measurement[seq={seq}].non_2xx_responses", 0, complete
    )
    return {
        "seq": seq,
        "phase": phase,
        "endpoint": endpoint,
        "endpoint_label": SAFE_ENDPOINT_LABELS[endpoint],  # constant, never input text
        "concurrency": concurrency,
        "requests_planned": planned,
        "requests_complete": complete,
        "p50_ms": p50,
        "p95_ms": p95,
        "p99_ms": p99,
        "requests_per_second": rps,
        "failed_requests": failed,
        "non_2xx_responses": non_2xx,
    }


def _validate_safe_resource(raw: Any, field_prefix: str = "diagnostic.resources") -> Dict[str, Any]:
    if not isinstance(raw, dict) or set(raw) != set(SAFE_DIAGNOSTIC_RESOURCE_FIELDS):
        raise EvidenceRefused(f"{field_prefix}: unexpected field set")
    result = dict(raw)
    result["sample_count"] = _safe_int(result["sample_count"], field_prefix + ".sample_count", 1, DIAGNOSTIC_MAX_COUNT)
    for field in ("sample_interval_ms",):
        result[field] = _safe_number(result[field], field_prefix + "." + field, 1.0, 10_000.0)
    result["elapsed_ms"] = _safe_number(result["elapsed_ms"], field_prefix + ".elapsed_ms", 0.0, 3_600_000.0)
    for field in ("host_cpu_avg_pct", "host_cpu_max_pct", "host_mem_used_max_pct"):
        result[field] = _safe_number(result[field], field_prefix + "." + field, 0.0, 100.0)
    for field in ("ab_cpu_avg_pct", "ab_cpu_max_pct", "server_cpu_avg_pct", "server_cpu_max_pct"):
        result[field] = _safe_number(result[field], field_prefix + "." + field, 0.0, DIAGNOSTIC_MAX_PROCESS_CPU)
    for field in ("host_mem_available_min_mb", "ab_rss_max_mb", "server_rss_max_mb"):
        result[field] = _safe_number(result[field], field_prefix + "." + field, 0.0, DIAGNOSTIC_MAX_MB)
    result["server_process_count_max"] = _safe_int(
        result["server_process_count_max"], field_prefix + ".server_process_count_max", 1, DIAGNOSTIC_MAX_COUNT
    )
    if result["host_cpu_avg_pct"] > result["host_cpu_max_pct"]:
        raise EvidenceRefused(f"{field_prefix}: host CPU average exceeds maximum")
    return result


def _project_safe_diagnostics(raw: Any) -> Dict[str, Any]:
    if not isinstance(raw, dict) or raw.get("schema") != DIAGNOSTIC_SCHEMA or raw.get("status") != "ok":
        raise EvidenceRefused("report.diagnostics: missing or not-ok")
    try:
        static = _validate_diagnostic_static(raw.get("static"))
        static_projection = {field: static[field] for field in DIAGNOSTIC_STATIC_FIELDS if field not in ("schema", "status")}
        rows_in = raw.get("measurements")
        if not isinstance(rows_in, list) or not rows_in:
            raise EvidenceRefused("report.diagnostics.measurements: expected a non-empty list")
        rows: List[Dict[str, Any]] = []
        for index, row in enumerate(rows_in, start=1):
            checked = _validate_diagnostic_row(row, index)
            resources = {field: checked[field] for field in SAFE_DIAGNOSTIC_RESOURCE_FIELDS}
            rows.append({"seq": checked["seq"], "resources": _validate_safe_resource(resources)})
        return {
            **static_projection,
            "measurements": rows,
        }
    except DiagnosticInputRefused as exc:
        raise EvidenceRefused(str(exc))


def _validate_safe_diagnostics(raw: Any) -> Dict[str, Any]:
    if not isinstance(raw, dict) or set(raw) != set(SAFE_DIAGNOSTIC_FIELDS):
        raise EvidenceRefused("evidence.diagnostics: unexpected field set")
    result = dict(raw)
    result["runner_cpu_count"] = _safe_int(result["runner_cpu_count"], "diagnostics.runner_cpu_count", 1, DIAGNOSTIC_MAX_CPU)
    if result["active_mpm"] not in DIAGNOSTIC_MPMS:
        raise EvidenceRefused("evidence.diagnostics.active_mpm: not allowlisted")
    if result["php_execution_mode"] not in DIAGNOSTIC_PHP_MODES:
        raise EvidenceRefused("evidence.diagnostics.php_execution_mode: not allowlisted")
    for field in ("server_limit", "thread_limit", "threads_per_child", "max_request_workers", "max_connections_per_child"):
        result[field] = _safe_int(result[field], "diagnostics." + field, 0, 1_000_000)
    if result["threads_per_child"] < 1 or result["max_request_workers"] < 1:
        raise EvidenceRefused("evidence.diagnostics: worker capacity is not bounded")
    rows = result["measurements"]
    if not isinstance(rows, list) or not rows:
        raise EvidenceRefused("evidence.diagnostics.measurements: expected a non-empty list")
    normalized_rows: List[Dict[str, Any]] = []
    for index, row in enumerate(rows, start=1):
        if not isinstance(row, dict) or set(row) != set(SAFE_DIAGNOSTIC_ROW_FIELDS):
            raise EvidenceRefused("evidence.diagnostics.measurement: unexpected field set")
        seq = _safe_int(row["seq"], "diagnostics.measurement.seq", index, index)
        normalized_rows.append({"seq": seq, "resources": _validate_safe_resource(row["resources"])})
    result["measurements"] = normalized_rows
    return result


def build_safe_evidence(report: Dict[str, Any]) -> Dict[str, Any]:
    """Project a parsed+scanned benchmark report onto the allowlisted evidence schema.

    Only the five binding keys and the numeric measurement fields listed in
    `SAFE_MEASUREMENT_FIELDS` are ever copied; everything else in the report
    (environment metadata, human labels, paths, raw-dump references) is dropped.
    Raises `EvidenceRefused` on any malformed/unexpected structure or value.
    """
    if not isinstance(report, dict):
        raise EvidenceRefused("report: expected object")
    run = report.get("run")
    if not isinstance(run, dict):
        raise EvidenceRefused("report.run: expected object")
    missing = [key for key in SAFE_BINDING_KEYS if not isinstance(run.get(key), str) or run.get(key) == ""]
    if missing:
        raise EvidenceRefused("report.run: missing required binding field(s): " + ", ".join(missing))
    binding = _validate_binding({key: run[key] for key in SAFE_BINDING_KEYS})

    measurements_in = report.get("measurements")
    if not isinstance(measurements_in, list) or not measurements_in:
        raise EvidenceRefused("report.measurements: expected a non-empty list")
    declared = report.get("measurement_count")
    if isinstance(declared, bool) or not isinstance(declared, int) or declared != len(measurements_in):
        raise EvidenceRefused("report.measurement_count: does not match the measurement list length")
    diagnostics = _project_safe_diagnostics(report.get("diagnostics"))
    if len(diagnostics["measurements"]) != len(measurements_in):
        raise EvidenceRefused("report.diagnostics.measurements: does not match benchmark row count")

    rows: List[Dict[str, Any]] = []
    for index, raw in enumerate(measurements_in, start=1):
        if not isinstance(raw, dict):
            raise EvidenceRefused(f"measurement[{index}]: expected object")
        metrics = raw.get("metrics")
        if not isinstance(metrics, dict):
            raise EvidenceRefused(f"measurement[seq={index}]: missing structured metrics object")
        non_2xx = metrics.get("non_2xx_responses")
        if non_2xx is None:
            # `ab` omits the line entirely when there were no Non-2xx responses.
            non_2xx = 0
        candidate = {
            "seq": raw.get("seq"),
            "phase": raw.get("phase"),
            "endpoint": raw.get("endpoint"),
            "endpoint_label": SAFE_ENDPOINT_LABELS.get(raw.get("endpoint"), ""),
            "concurrency": raw.get("concurrency"),
            "requests_planned": raw.get("requests_planned"),
            "requests_complete": metrics.get("complete_requests"),
            "p50_ms": metrics.get("p50_ms"),
            "p95_ms": metrics.get("p95_ms"),
            "p99_ms": metrics.get("p99_ms"),
            "requests_per_second": metrics.get("requests_per_second"),
            "failed_requests": metrics.get("failed_requests"),
            "non_2xx_responses": non_2xx,
        }
        rows.append(_validate_measurement_row(candidate, expected_seq=index))

    return {
        "schema": SAFE_EVIDENCE_SCHEMA,
        "purpose": SAFE_EVIDENCE_PURPOSE,
        "binding": binding,
        "measurement_count": len(rows),
        "diagnostics": diagnostics,
        "measurements": rows,
    }


def validate_safe_evidence(evidence: Any) -> Dict[str, Any]:
    """Re-validate an already-built projection object against the same rules (read-only)."""
    if not isinstance(evidence, dict):
        raise EvidenceRefused("evidence: expected object")
    if set(evidence) != set(SAFE_TOP_LEVEL_FIELDS):
        raise EvidenceRefused(
            "evidence: unexpected top-level field set (expected exactly "
            + ", ".join(SAFE_TOP_LEVEL_FIELDS)
            + ")"
        )
    if evidence["schema"] != SAFE_EVIDENCE_SCHEMA:
        raise EvidenceRefused("evidence.schema: unexpected value")
    if evidence["purpose"] != SAFE_EVIDENCE_PURPOSE:
        raise EvidenceRefused("evidence.purpose: unexpected value")
    binding = _validate_binding(evidence["binding"])
    diagnostics = _validate_safe_diagnostics(evidence["diagnostics"])
    measurements = evidence["measurements"]
    if not isinstance(measurements, list) or not measurements:
        raise EvidenceRefused("evidence.measurements: expected a non-empty list")
    declared = evidence["measurement_count"]
    if isinstance(declared, bool) or not isinstance(declared, int) or declared != len(measurements):
        raise EvidenceRefused("evidence.measurement_count: does not match the measurement list length")
    rows = [_validate_measurement_row(raw, expected_seq=index) for index, raw in enumerate(measurements, start=1)]
    return {
        "schema": SAFE_EVIDENCE_SCHEMA,
        "purpose": SAFE_EVIDENCE_PURPOSE,
        "binding": binding,
        "measurement_count": len(rows),
        "diagnostics": diagnostics,
        "measurements": rows,
    }


def render_safe_evidence_markdown(evidence: Dict[str, Any]) -> str:
    """Render the exact REST comment body from a VALIDATED projection (numbers only)."""
    binding = evidence["binding"]
    lines: List[str] = [
        "### Phase 17 — Pilot/Staging benchmark evidence (allowlisted projection)",
        "",
        "Run binding: "
        + " · ".join(f"`{key}={binding[key]}`" for key in SAFE_BINDING_KEYS),
        "",
        "| seq | phase | endpoint | concurrency | requests | complete | p50 ms | p95 ms | p99 ms | req/s | failed | non-2xx |",
        "|---|---|---|---|---|---|---|---|---|---|---|---|",
    ]
    for row in evidence["measurements"]:
        lines.append(
            "| {seq} | {phase} | `{endpoint_label}` | {concurrency} | {requests_planned} | "
            "{requests_complete} | {p50_ms} | {p95_ms} | {p99_ms} | {requests_per_second} | "
            "{failed_requests} | {non_2xx_responses} |".format(**row)
        )
    diagnostic = evidence["diagnostics"]
    lines += [
        "",
        "### Bounded capacity diagnostics (allowlisted)",
        "",
        "MPM: `{active_mpm}` · PHP mode: `{php_execution_mode}` · runner CPUs: {runner_cpu_count} · "
        "ServerLimit: {server_limit} · ThreadLimit: {thread_limit} · ThreadsPerChild: {threads_per_child} · "
        "MaxRequestWorkers: {max_request_workers} · MaxConnectionsPerChild: {max_connections_per_child}".format(**diagnostic),
        "",
        "| seq | samples | host CPU avg/max % | host available min MB | host used max % | ab CPU avg/max % | server CPU avg/max % | server RSS max MB | server processes max |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for diagnostic_row in diagnostic["measurements"]:
        resource = diagnostic_row["resources"]
        lines.append(
            "| {seq} | {sample_count} | {host_cpu_avg_pct}/{host_cpu_max_pct} | "
            "{host_mem_available_min_mb} | {host_mem_used_max_pct} | "
            "{ab_cpu_avg_pct}/{ab_cpu_max_pct} | {server_cpu_avg_pct}/{server_cpu_max_pct} | "
            "{server_rss_max_mb} | {server_process_count_max} |".format(
                seq=diagnostic_row["seq"], **resource
            )
        )
    lines += [
        "",
        "Measurement-only semantics (unchanged): no latency threshold is applied and no NFR-PERF "
        "pass/fail is claimed; shared-runner numbers are NOT reference-server adjudication "
        "(Pilot Gate Runbook section 12.3 — BLOCKED_BY_ENVIRONMENT). Per-run numbers are not "
        "committed to Git history.",
        "",
        "This comment is a validated allowlist projection of the already privacy-scanned structured "
        "report (measurement index, phase, fixed endpoint label, concurrency, request counts, "
        "p50/p95/p99, requests-per-second, failed and non-2xx counts, plus run binding). It carries "
        "no raw benchmark bytes, no response/header/body content, no environment metadata and no "
        "free-form text; malformed or unexpected structure is refused before publication.",
        "",
        "```json",
        json.dumps(evidence, ensure_ascii=False, indent=2),
        "```",
        "",
    ]
    return "\n".join(lines)


def _load_evidence_text(path: Path) -> Tuple[Optional[Dict[str, Any]], str]:
    """Read a projection file (`.json` or a comment body `.md` with a fenced json block)."""
    text = path.read_text(encoding="utf-8", errors="replace")
    if path.suffix == ".md":
        match = SAFE_EVIDENCE_JSON_FENCE_RE.search(text)
        if not match:
            return None, text
        try:
            return json.loads(match.group("body")), text
        except json.JSONDecodeError:
            return None, text
    try:
        return json.loads(text), text
    except json.JSONDecodeError:
        return None, text


def verify_evidence_file(path: Path) -> int:
    """Validate an existing projection file (no network, no writes) — used before publication."""
    if not path.is_file():
        print(f"EVIDENCE VERIFY: file not found: {path.name}", file=sys.stderr)
        return 2
    data, text = _load_evidence_text(path)
    if data is None:
        print("EVIDENCE VERIFY: no parsable allowlist projection found in the file", file=sys.stderr)
        return 1
    hits = scan_forbidden(text)
    if hits:
        print(
            "EVIDENCE VERIFY: projection text contains forbidden content: %s" % ", ".join(hits),
            file=sys.stderr,
        )
        return 1
    try:
        evidence = validate_safe_evidence(data)
    except EvidenceRefused as exc:
        print(f"EVIDENCE VERIFY: REFUSED (fail closed): {exc}", file=sys.stderr)
        return 1
    print(
        "EVIDENCE VERIFY: OK — allowlisted projection, %d measurement row(s), binding "
        "run_id=%s run_attempt=%s event_name=%s head_sha=%s ref=%s"
        % (
            evidence["measurement_count"],
            evidence["binding"]["run_id"],
            evidence["binding"]["run_attempt"],
            evidence["binding"]["event_name"],
            evidence["binding"]["head_sha"],
            evidence["binding"]["ref"],
        )
    )
    return 0


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------


def read_manifest(path: Path) -> List[Dict[str, Any]]:
    entries: List[Dict[str, Any]] = []
    for lineno, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        if not line.strip():
            continue
        try:
            entry = json.loads(line)
        except json.JSONDecodeError as exc:
            raise ValueError(f"manifest line {lineno} is not JSON: {exc}")
        if not isinstance(entry, dict):
            raise ValueError(f"manifest line {lineno} is not an object")
        for key in ("phase", "label", "raw"):
            if not isinstance(entry.get(key), str) or not entry.get(key):
                raise ValueError(f"manifest line {lineno} lacks a non-empty string '{key}'")
        entries.append(entry)
    if not entries:
        raise ValueError("manifest is empty — refusing to report a green no-op")
    return entries


def parse_meta(pairs: List[str]) -> Dict[str, Any]:
    meta: Dict[str, Any] = {}
    for pair in pairs or []:
        if "=" not in pair:
            raise ValueError(f"--meta expects KEY=VALUE, got {pair!r}")
        key, value = pair.split("=", 1)
        key = key.strip()
        if not re.fullmatch(r"[a-z][a-z0-9_]*", key):
            raise ValueError(f"--meta key {key!r} is not snake_case")
        meta[key] = value.strip()
    return meta


def run(args: argparse.Namespace) -> int:
    manifest = Path(args.manifest)
    if not manifest.is_file():
        print(f"CONFIG: manifest not found: {manifest}", file=sys.stderr)
        return 2
    try:
        entries = read_manifest(manifest)
        meta = parse_meta(args.meta)
    except ValueError as exc:
        print(f"CONFIG: {exc}", file=sys.stderr)
        return 2

    raw_dir = Path(args.raw_dir)
    measurements: List[Dict[str, Any]] = []
    failures: List[str] = []
    diagnostics: Optional[Dict[str, Any]] = None
    diagnostics_dir = getattr(args, "diagnostics_dir", "") or ""
    if diagnostics_dir:
        try:
            diagnostics = read_diagnostics(Path(diagnostics_dir), len(entries))
        except DiagnosticInputRefused as exc:
            # This is deliberately a distinct collection failure. It must not be
            # reported as an ab/product failure and cannot produce publishable evidence.
            diagnostics = {
                "schema": DIAGNOSTIC_SCHEMA,
                "status": "measurement_failed",
                "error_code": str(exc),
            }
            failures.append("DIAGNOSTIC MEASUREMENT FAILURE: %s" % exc)

    for entry in entries:
        raw_path = raw_dir / entry["raw"]
        if not raw_path.is_file():
            failures.append(
                "AB PARSE FAIL (%s c=%s): dump missing: %s"
                % (entry["label"], entry.get("concurrency"), entry["raw"])
            )
            continue
        raw = raw_path.read_text(encoding="utf-8", errors="replace")
        expected = entry.get("path")
        metrics, problems = parse_ab_output(raw, expected if isinstance(expected, str) else None)
        non_2xx = metrics.get("non_2xx_responses") or 0
        if problems:
            failures.append(
                "AB PARSE FAIL (%s c=%s): %s"
                % (entry["label"], entry.get("concurrency"), "; ".join(problems))
            )
        if non_2xx:
            # Preserved pre-existing HTTP-correctness failure (correctness, not latency).
            failures.append(
                "NON-2xx RESPONSES: %s c=%s (%s)"
                % (entry["label"], entry.get("concurrency"), non_2xx)
            )
        measurements.append(
            {
                "seq": entry.get("seq"),
                "phase": entry["phase"],
                "label": entry["label"],
                "endpoint": entry.get("endpoint"),
                "path": entry.get("path"),
                "concurrency": entry.get("concurrency"),
                "requests_planned": entry.get("requests"),
                "warmup_requests": entry.get("warmup_requests", 0),
                "metrics": metrics,
            }
        )

    report = build_report(measurements, meta, diagnostics)
    markdown = render_markdown(report)
    legacy = render_legacy_lines(report)
    payload = json.dumps(report, ensure_ascii=False, indent=2) + "\n"

    for kind, text in (("json", payload), ("md", markdown), ("txt", "\n".join(legacy))):
        hits = scan_forbidden(text)
        if hits:
            print(
                "PRIVACY: generated %s output contains forbidden content: %s"
                % (kind, ", ".join(hits)),
                file=sys.stderr,
            )
            failures.append("PRIVACY: forbidden content in generated %s output" % kind)

    out_prefix = Path(args.out_prefix)
    if out_prefix.parent and str(out_prefix.parent) not in ("", "."):
        out_prefix.parent.mkdir(parents=True, exist_ok=True)
    out_prefix.with_suffix(".json").write_text(payload, encoding="utf-8")
    out_prefix.with_suffix(".md").write_text(markdown + "\n", encoding="utf-8")
    out_prefix.with_suffix(".txt").write_text(
        "\n".join(legacy) + ("\n" if legacy else ""), encoding="utf-8"
    )

    for line in legacy:
        print(line)

    # ---- safe evidence projection (post-scan structured data only) --------------------
    # Built from the SAME in-memory structured report that was just privacy-scanned, never
    # from raw `ab` bytes and never via shell text extraction. Written only when the whole
    # report is clean; a refusal is loud (fail closed) and publishes nothing.
    evidence_prefix = getattr(args, "evidence_prefix", "") or ""
    if evidence_prefix:
        try:
            evidence = build_safe_evidence(report)
            evidence_json = json.dumps(evidence, ensure_ascii=False, indent=2) + "\n"
            evidence_md = render_safe_evidence_markdown(evidence)
            for kind, text in (("evidence.json", evidence_json), ("evidence.md", evidence_md)):
                hits = scan_forbidden(text)
                if hits:
                    print(
                        "PRIVACY: generated %s output contains forbidden content: %s"
                        % (kind, ", ".join(hits)),
                        file=sys.stderr,
                    )
                    failures.append("PRIVACY: forbidden content in generated %s output" % kind)
            if not failures:
                evidence_path = Path(evidence_prefix + ".json")
                if evidence_path.parent and str(evidence_path.parent) not in ("", "."):
                    evidence_path.parent.mkdir(parents=True, exist_ok=True)
                evidence_path.write_text(evidence_json, encoding="utf-8")
                evidence_path.with_name(evidence_path.name[: -len(".json")] + ".md").write_text(
                    evidence_md, encoding="utf-8"
                )
                print(
                    "EVIDENCE PROJECTION: allowlisted, validated — %d measurement row(s) ready for "
                    "REST-visible publication" % evidence["measurement_count"]
                )
        except EvidenceRefused as exc:
            print(
                "EVIDENCE PROJECTION REFUSED (fail closed) — no evidence file written and nothing "
                "may be published: %s" % exc,
                file=sys.stderr,
            )
            failures.append("EVIDENCE: safe projection refused: %s" % exc)

    if failures:
        message = "\n".join(failures)
        print(message, file=sys.stderr)
        print(
            "NOTE: these are collection/correctness failures only (unparsable ab output, a "
            "missing dump, Non-2xx responses, or a privacy trip). No latency threshold exists "
            "in this step by design.",
            file=sys.stderr,
        )
        if args.fail_file:
            Path(args.fail_file).write_text(message + "\n", encoding="utf-8")
        return 1

    print(
        "BENCHMARK COLLECTED: %s measurement(s) — no latency threshold applied, no NFR verdict "
        "(adjudication stays with report-pilot-gate.md §12.3)" % len(measurements)
    )
    return 0


# ---------------------------------------------------------------------------
# Self-tests — deterministic: no network, no WordPress, no `ab` binary
# ---------------------------------------------------------------------------

FIXTURE_AB = """
This is ApacheBench, Version 2.3 <$Revision: 1901537 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking localhost (be patient)
Finished 200 requests


Server Software:        Apache/2.4.58
Server Hostname:        localhost
Server Port:            8080

Document Path:          /wp-json/clinic/v1/health
Document Length:        132 bytes

Concurrency Level:      1
Time taken for tests:   6.000 seconds
Complete requests:      200
Failed requests:        0
Total transferred:      36800 bytes
HTML transferred:       26400 bytes
Requests per second:    33.33 [#/sec] (mean)
Time per request:       30.000 [ms] (mean)
Time per request:       30.000 [ms] (mean, across all concurrent requests)
Transfer rate:          5.99 [Kbytes/sec] received

Connection times (ms):
              min    mean   max
Connect:     0.0    0.1   1.0
 Processing:  8.0   30.0 200.0
 Waiting:     8.0   30.0 200.0

Percentage of the requests served within a certain time (ms)
  50%     28
  66%     30
  75%     32
  80%     34
  90%     40
  95%     55
  98%     70
  99%     90
 100%    200 (longest request)
"""

FIXTURE_NON2XX = FIXTURE_AB.replace(
    "Failed requests:        0",
    "Failed requests:        12\n(Connect: 0, Receive: 0, Length: 12, Exceptions: 0)\n"
    "Non-2xx responses:      12",
)


def _entry(seq: int, phase: str, label: str, endpoint: str, path: str, concurrency: int,
            requests: int, warmup: int, raw: str) -> Dict[str, Any]:
    return {
        "seq": seq,
        "phase": phase,
        "label": label,
        "endpoint": endpoint,
        "path": path,
        "concurrency": concurrency,
        "requests": requests,
        "warmup_requests": warmup,
        "raw": raw,
    }


def _selftests() -> int:
    import tempfile

    failures: List[str] = []

    def check(name: str, condition: bool, detail: str = "") -> None:
        if not condition:
            failures.append(("SELF-TEST FAIL: %s %s" % (name, detail)).strip())

    # 1) scalars + percentile table
    metrics, problems = parse_ab_output(FIXTURE_AB, "/wp-json/clinic/v1/health")
    check("parse-clean", problems == [], str(problems))
    check("p50", metrics["p50_ms"] == 28.0, str(metrics.get("p50_ms")))
    check("p95", metrics["p95_ms"] == 55.0, str(metrics.get("p95_ms")))
    check("p99", metrics["p99_ms"] == 90.0, str(metrics.get("p99_ms")))
    check("p100-trailing-text-tolerated", metrics["p100_ms"] == 200.0, str(metrics.get("p100_ms")))
    check("rps", metrics["requests_per_second"] == 33.33, str(metrics.get("requests_per_second")))
    check("mean-vs-across-not-swapped",
          metrics["time_per_request_mean_ms"] == 30.0 and metrics["time_per_request_across_ms"] == 30.0)
    check("complete-requests", metrics["complete_requests"] == 200)
    check("failed-requests", metrics["failed_requests"] == 0)
    check("non2xx-absent-is-null", metrics["non_2xx_responses"] is None)
    check("document-path", metrics["document_path"] == "/wp-json/clinic/v1/health")
    check("server-software", metrics["server_software"] == "Apache/2.4.58")
    check("document-length", metrics["document_length_bytes"] == 132)
    check("time-taken", metrics["time_taken_seconds"] == 6.0)
    check("transfer-rate", metrics["transfer_rate_kbytes_sec"] == 5.99)
    check("mid-percentiles", metrics["p90_ms"] == 40.0 and metrics["p98_ms"] == 70.0)
    check("no-host-in-metrics", "zeustech" not in json.dumps(metrics))

    # 2) wrong route must fail loudly, never silently report another endpoint
    _, wrong_route = parse_ab_output(FIXTURE_AB, "/wp-json/clinic/v1/availability")
    check("path-mismatch-detected", any("document path mismatch" in p for p in wrong_route), str(wrong_route))
    #    ...while a query string on the same route is the same route (ab echoes it back)
    with_query = FIXTURE_AB.replace(
        "Document Path:          /wp-json/clinic/v1/health",
        "Document Path:          /wp-json/clinic/v1/health?from=2026-10-05&to=2026-11-03",
    )
    _, query_route_problems = parse_ab_output(with_query, "/wp-json/clinic/v1/health")
    check("query-string-same-route-ok", query_route_problems == [], str(query_route_problems))

    # 3) unparsable/truncated output = required-metric failure (never a green row)
    broken_metrics, broken_problems = parse_ab_output(
        "Benchmarking localhost (be patient)\nComplete requests:      0\n", None
    )
    check("broken-parse-fails", broken_problems != [], str(broken_problems))
    check("broken-reports-missing-rps", any("requests_per_second" in p for p in broken_problems))
    check("broken-reports-zero-complete", any("no completed requests" in p for p in broken_problems))
    check("broken-metrics-are-null", broken_metrics["p50_ms"] is None)

    # 4) Non-2xx is parsed as the preserved HTTP-correctness signal
    metrics_n, problems_n = parse_ab_output(FIXTURE_NON2XX, None)
    check("non2xx-parsed", metrics_n["non_2xx_responses"] == 12, str(metrics_n.get("non_2xx_responses")))
    check("non2xx-metrics-still-parse", problems_n == [], str(problems_n))

    # 5) report honesty fields
    report = build_report(
        [{
            "seq": 1, "phase": "cold", "label": "GET /health (public)", "endpoint": "health",
            "path": "/wp-json/clinic/v1/health", "concurrency": 1, "requests_planned": 200,
            "warmup_requests": 0, "metrics": metrics,
        }],
        {"head_sha": "0" * 40, "run_id": "1"},
    )
    check("schema", report["schema"] == "cpms.pilot-benchmark/2")
    check("no-latency-gate", report["latency_threshold_enforced"] is False)
    check("no-nfr-pass-claim", report["nfr_perf_pass_claim"] is False)
    check("cold-not-infra-cold", "NOT an infrastructure-level cold-cache" in report["cold_definition"])
    check("cold-mentions-tcp-readiness", "TCP level" in report["cold_definition"])
    check("warm-two-curl-preserved", "two pre-existing `curl` warm-up requests" in report["warm_definition"])
    check("warm-mentions-new-c1", "c=1 is newly added" in report["warm_definition"])
    check("adjudication-deferred-to-12-3", "§12.3" in report["adjudication"])
    check("measurement-count", report["measurement_count"] == 1)

    markdown = render_markdown(report)
    check("md-row", "| cold | GET /health (public) | 1 | 200 | 200 | 28.0 | 55.0 | 90.0 | 33.33 | 0 | 0 |" in markdown,
          markdown[:400])
    check("md-binds-sha", "head_sha=" + "0" * 40 in markdown)
    check("md-no-commit-policy", "NOT committed to repository history" in markdown)
    check("md-endpoints-listed", "`/wp-json/clinic/v1/availability`" in markdown)
    legacy_lines = render_legacy_lines(report)
    check("legacy-line-shape-preserved",
          legacy_lines[0].startswith("GET /health (public) | phase=cold | c=1 | p50=28.0ms p95=55.0ms"),
          legacy_lines[0])
    check("legacy-line-has-non2xx", "non2xx=0" in legacy_lines[0], legacy_lines[0])

    # 6) end-to-end over a temp tree
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        raw_dir = root / "raw"
        raw_dir.mkdir()
        (raw_dir / "001.ab.txt").write_text(FIXTURE_AB, encoding="utf-8")
        (raw_dir / "002.ab.txt").write_text(FIXTURE_NON2XX, encoding="utf-8")
        manifest = root / "manifest.jsonl"
        manifest.write_text(
            json.dumps(_entry(1, "cold", "GET /health (public)", "health",
                              "/wp-json/clinic/v1/health", 1, 200, 0, "001.ab.txt")) + "\n"
            + json.dumps(_entry(2, "warm", "GET /health (public)", "health",
                                "/wp-json/clinic/v1/health", 10, 3000, 2, "001.ab.txt")) + "\n",
            encoding="utf-8",
        )
        out = root / "out" / "bench"
        rc = run(argparse.Namespace(
            manifest=str(manifest), raw_dir=str(raw_dir), out_prefix=str(out),
            meta=["run_id=1", "head_sha=" + "0" * 40, "event_name=workflow_dispatch", "wp_url=http://localhost:8080"],
            fail_file=str(root / "fail.txt"),
        ))
        check("e2e-rc-zero", rc == 0, "rc=%s" % rc)
        check("e2e-json", out.with_suffix(".json").is_file())
        check("e2e-md", out.with_suffix(".md").is_file())
        check("e2e-txt", out.with_suffix(".txt").is_file())
        payload_text = out.with_suffix(".json").read_text(encoding="utf-8")
        payload = json.loads(payload_text)
        check("e2e-meta-bound", payload["run"]["event_name"] == "workflow_dispatch", str(payload.get("run")))
        check("e2e-two-measurements", payload["measurement_count"] == 2)
        check("e2e-phases", [m["phase"] for m in payload["measurements"]] == ["cold", "warm"])
        check("e2e-warmup-recorded", [m["warmup_requests"] for m in payload["measurements"]] == [0, 2])
        check("e2e-privacy-clean", scan_forbidden(payload_text) == [], str(scan_forbidden(payload_text)))
        check("e2e-no-failfile", not (root / "fail.txt").exists())

        # Non-2xx end-to-end must return rc=1 with the preserved message.
        manifest2 = root / "manifest2.jsonl"
        manifest2.write_text(
            json.dumps(_entry(1, "warm", "GET /availability (calendar)", "availability",
                              "/wp-json/clinic/v1/health", 1, 200, 2, "002.ab.txt")) + "\n",
            encoding="utf-8",
        )
        rc2 = run(argparse.Namespace(
            manifest=str(manifest2), raw_dir=str(raw_dir),
            out_prefix=str(root / "out2" / "bench"), meta=[], fail_file=str(root / "fail2.txt"),
        ))
        check("e2e-non2xx-fails", rc2 == 1, "rc=%s" % rc2)
        check("e2e-non2xx-message", "NON-2xx RESPONSES" in (root / "fail2.txt").read_text(encoding="utf-8"))

        # A missing dump must be rc=1 (loud), never a green no-op.
        manifest3 = root / "manifest3.jsonl"
        manifest3.write_text(
            json.dumps(_entry(1, "warm", "GET /wp-json/ (WP core reference)", "wp-json-root",
                              "/wp-json/", 50, 1000, 2, "missing.ab.txt")) + "\n",
            encoding="utf-8",
        )
        rc3 = run(argparse.Namespace(
            manifest=str(manifest3), raw_dir=str(raw_dir),
            out_prefix=str(root / "out3" / "bench"), meta=[], fail_file="",
        ))
        check("e2e-missing-dump-fails", rc3 == 1, "rc=%s" % rc3)

        # An empty manifest is a configuration error, not a pass.
        manifest4 = root / "empty.jsonl"
        manifest4.write_text("\n", encoding="utf-8")
        rc4 = run(argparse.Namespace(
            manifest=str(manifest4), raw_dir=str(raw_dir),
            out_prefix=str(root / "out4" / "bench"), meta=[], fail_file="",
        ))
        check("e2e-empty-manifest-config-error", rc4 == 2, "rc=%s" % rc4)

        # Privacy by construction: un-copied raw regions (headers/cookies/banners) never reach
        # the artifact at all — only the whitelisted scalar fields are copied.
        (raw_dir / "003.ab.txt").write_text(
            FIXTURE_AB + "\nSet-Cookie: sid=deadbeefdeadbeef\n\nX-Powered-By: PHP/8.2\n",
            encoding="utf-8",
        )
        manifest5 = root / "manifest5.jsonl"
        manifest5.write_text(
            json.dumps(_entry(1, "cold", "GET /health (public)", "health",
                              "/wp-json/clinic/v1/health", 1, 200, 0, "003.ab.txt")) + "\n",
            encoding="utf-8",
        )
        out5 = root / "out5" / "bench"
        rc5 = run(argparse.Namespace(
            manifest=str(manifest5), raw_dir=str(raw_dir),
            out_prefix=str(out5), meta=[], fail_file="",
        ))
        check("e2e-uncopied-raw-never-leaks", rc5 == 0, "rc=%s" % rc5)
        leaked = out5.with_suffix(".json").read_text(encoding="utf-8")
        check("e2e-artifact-has-no-cookie", "Set-Cookie" not in leaked and "sid=deadbeef" not in leaked)
        check("e2e-artifact-has-no-banner", "zeustech" not in leaked and "X-Powered-By" not in leaked)

        # Privacy by scan: forbidden content in a field that IS copied must abort with rc=1.
        (raw_dir / "004.ab.txt").write_text(
            FIXTURE_AB.replace(
                "Document Path:          /wp-json/clinic/v1/health",
                "Document Path:          /wp-json/clinic/v1/health?nonce=abc123",
            ),
            encoding="utf-8",
        )
        manifest6 = root / "manifest6.jsonl"
        manifest6.write_text(
            json.dumps(_entry(1, "cold", "GET /health (public)", "health",
                              "/wp-json/clinic/v1/health", 1, 200, 0, "004.ab.txt")) + "\n",
            encoding="utf-8",
        )
        out6 = root / "out6" / "bench"
        rc6 = run(argparse.Namespace(
            manifest=str(manifest6), raw_dir=str(raw_dir),
            out_prefix=str(out6), meta=[], fail_file="",
        ))
        check("e2e-privacy-trip-fails", rc6 == 1, "rc=%s" % rc6)

        # 6b) the raw-dump upload gate: clean dumps pass, identity-bearing dumps do not
        raw2 = root / "raw2"
        raw2.mkdir()
        (raw2 / "001.ab.txt").write_text(FIXTURE_AB, encoding="utf-8")
        (raw2 / "002.ab.txt").write_text(FIXTURE_NON2XX, encoding="utf-8")
        check("raw-scan-clean-dumps-pass", scan_raw_tree(raw2) == [], str(scan_raw_tree(raw2)))
        (raw2 / "005.ab.txt").write_text(
            FIXTURE_AB.replace(
                "Server Software:        Apache/2.4.58",
                "Server Software:        Apache/2.4.58\nSet-Cookie: sid=deadbeefdeadbeef",
            ),
            encoding="utf-8",
        )
        raw_problems2 = scan_raw_tree(raw2)
        check("raw-scan-trips-on-cookie",
              any(p.startswith("005.ab.txt: cookie") for p in raw_problems2), str(raw_problems2))
        check("raw-scan-ignores-upstream-banner-url",
              not any("001.ab.txt" in p for p in raw_problems2), str(raw_problems2))
        raw_problems3 = scan_raw_tree(root / "does-not-exist")
        check("raw-scan-missing-dir-fails-loud", raw_problems3 != [], str(raw_problems3))
        (raw2 / "006.ab.txt").write_text("Nonce: 12345\n" + FIXTURE_AB, encoding="utf-8")
        check("raw-scan-trips-on-nonce",
              any(p.startswith("006.ab.txt: nonce") for p in scan_raw_tree(raw2)))
        (raw2 / "007.ab.txt").write_text("بیمار آزمایشی 12\n", encoding="utf-8")
        check("raw-scan-trips-on-phi",
              any(p.startswith("007.ab.txt: synthetic_phi") for p in scan_raw_tree(raw2)))

    # 8) safe evidence projection — exact allowlist, fail-closed validation, privacy
    MATRIX = (
        (1, "cold", "health", 1, 200),
        (2, "cold", "availability", 1, 200),
        (3, "cold", "wp-json-root", 1, 200),
        (4, "warm", "health", 1, 200),
        (5, "warm", "health", 10, 3000),
        (6, "warm", "health", 50, 3000),
        (7, "warm", "health", 100, 3000),
        (8, "warm", "availability", 1, 200),
        (9, "warm", "availability", 10, 1500),
        (10, "warm", "availability", 50, 1500),
        (11, "warm", "availability", 100, 1500),
        (12, "warm", "wp-json-root", 1, 200),
        (13, "warm", "wp-json-root", 50, 1000),
    )
    ENDPOINT_PATHS = {
        "health": "/wp-json/clinic/v1/health",
        "availability": "/wp-json/clinic/v1/availability",
        "wp-json-root": "/wp-json/",
    }
    SENTINELS = (
        "SYN-0042",                    # synthetic PHI marker
        "SYNAP-00420",                 # synthetic appointment code
        "بیمار آزمایشی",                # synthetic patient label
        "09120001234",                 # synthetic mobile
        "Set-Cookie: sid=deadbeef",    # cookie material (also trips the privacy scanner)
        "_wpnonce=deadbeef",           # nonce material
        "Authorization: Bearer abcdefgh",   # auth material
        "dbpass=root",                 # credential material
        "api_key=AKIALIVE123456",      # secret material
        "zeustech",                    # raw `ab` banner host
        "X-Powered-By: PHP/8.2",       # header material
        "/home/runner/www",            # filesystem path / environment metadata
        "php_cli=8.1",                 # environment metadata
        "runner=GitHub Actions 24.04", # environment metadata
        "dataset=10k patients",        # dataset content
    )

    # Benign free-form prose marker: proves the manifest/report `label` (human text) is never
    # projected, without tripping the report's own privacy scanner.
    FREE_FORM_MARKER = "FREE-FORM-LABEL-MARKER تقویم"

    def _metrics(**overrides: Any) -> Dict[str, Any]:
        base = {
            "server_software": "Apache/2.4.58",
            "document_path": "/wp-json/clinic/v1/health",
            "document_length_bytes": 132,
            "concurrency_level": 1,
            "time_taken_seconds": 6.0,
            "complete_requests": 200,
            "failed_requests": 0,
            "non_2xx_responses": None,
            "requests_per_second": 33.33,
            "transfer_rate_kbytes_sec": 5.99,
            "time_per_request_mean_ms": 30.0,
            "time_per_request_across_ms": 30.0,
            "p50_ms": 28.0,
            "p66_ms": 30.0, "p75_ms": 32.0, "p80_ms": 34.0, "p90_ms": 40.0,
            "p95_ms": 55.0, "p98_ms": 70.0, "p99_ms": 90.0, "p100_ms": 200.0,
        }
        base.update(overrides)
        return base

    def _run_meta(**overrides: Any) -> Dict[str, Any]:
        base = {
            "run_id": "37361708008",
            "run_attempt": "1",
            "head_sha": "a" * 40,
            "event_name": "push",
            "ref": "arena/1ffa1d19-doctor",
            # environment metadata that must NEVER reach the projection:
            "runner": "GitHub Actions 24.04", "php_cli": "8.1", "apache": "Apache/2.4.58",
            "dataset": "10k patients", "wp_url": "http://localhost:8080",
        }
        base.update(overrides)
        return base

    def _measurement(seq: int, phase: str, endpoint: str, concurrency: int, planned: int,
                     metrics: Optional[Dict[str, Any]] = None, label: str = "") -> Dict[str, Any]:
        return {
            "seq": seq,
            "phase": phase,
            "label": label or "GET %s (برچسب آزاد — free-form)" % ENDPOINT_PATHS[endpoint],
            "endpoint": endpoint,
            "path": ENDPOINT_PATHS.get(endpoint, "/unexpected"),
            "concurrency": concurrency,
            "requests_planned": planned,
            "warmup_requests": 2,
            "metrics": metrics if metrics is not None else _metrics(),
        }

    def _diagnostic_row(seq: int, phase: str, concurrency: int) -> Dict[str, Any]:
        return {
            "schema": DIAGNOSTIC_SCHEMA, "seq": seq, "phase": phase, "concurrency": concurrency,
            "status": "ok", "command_exit_code": 0, "sample_count": 10,
            "sample_interval_ms": 200.0, "elapsed_ms": 2000.0, "runner_cpu_count": 2,
            "host_cpu_avg_pct": 40.0, "host_cpu_max_pct": 60.0,
            "host_mem_available_min_mb": 512.0, "host_mem_used_max_pct": 75.0,
            "ab_cpu_avg_pct": 20.0, "ab_cpu_max_pct": 30.0, "ab_rss_max_mb": 10.0,
            "server_cpu_avg_pct": 45.0, "server_cpu_max_pct": 70.0,
            "server_rss_max_mb": 100.0, "server_process_count_max": 5,
        }

    def _diagnostic_for(measurements: List[Dict[str, Any]]) -> Dict[str, Any]:
        return {
            "schema": DIAGNOSTIC_SCHEMA, "status": "ok",
            "static": {
                "schema": DIAGNOSTIC_SCHEMA, "status": "ok", "runner_cpu_count": 2,
                "active_mpm": "event", "php_execution_mode": "mod_php",
                "server_limit": 16, "thread_limit": 64, "threads_per_child": 25,
                "max_request_workers": 400, "max_connections_per_child": 0,
            },
            "measurements": ([_diagnostic_row(index, m["phase"], m["concurrency"])
                              for index, m in enumerate(measurements, start=1)]
                             if isinstance(measurements, list) else []),
        }

    def _report(measurements, run=None, **extra):
        report = {
            "schema": "cpms.pilot-benchmark/2",
            "run": run if run is not None else _run_meta(),
            "measurement_count": len(measurements),
            "measurements": measurements,
        }
        report.update(extra)
        if "diagnostics" not in report:
            report["diagnostics"] = _diagnostic_for(measurements)
        return report

    def _refuses(name: str, report: Any) -> None:
        try:
            build_safe_evidence(report)
        except EvidenceRefused:
            return
        check("evidence-refuses-" + name, False, "unexpectedly accepted")

    FIXTURE_METRICS = _metrics()
    base_report = _report([_measurement(1, "cold", "health", 1, 200)])
    evidence = build_safe_evidence(base_report)
    evidence_json = json.dumps(evidence, ensure_ascii=False, indent=2)
    evidence_md = render_safe_evidence_markdown(evidence)

    # 8a) exactly the intended allowlisted fields are emitted — set equality, not filtering
    check("evidence-top-level-field-set",
          set(evidence) == set(SAFE_TOP_LEVEL_FIELDS), str(sorted(evidence)))
    check("evidence-binding-field-set",
          set(evidence["binding"]) == set(SAFE_BINDING_KEYS), str(sorted(evidence["binding"])))
    check("evidence-row-field-set",
          set(evidence["measurements"][0]) == set(SAFE_MEASUREMENT_FIELDS),
          str(sorted(evidence["measurements"][0])))
    check("evidence-row-values",
          evidence["measurements"][0] == {
              "seq": 1, "phase": "cold", "endpoint": "health", "endpoint_label": "GET /health",
              "concurrency": 1, "requests_planned": 200, "requests_complete": 200,
              "p50_ms": 28.0, "p95_ms": 55.0, "p99_ms": 90.0, "requests_per_second": 33.33,
              "failed_requests": 0, "non_2xx_responses": 0,
          }, str(evidence["measurements"][0]))
    check("evidence-binding-values",
          evidence["binding"] == {
              "run_id": "37361708008", "run_attempt": "1", "event_name": "push",
              "head_sha": "a" * 40, "ref": "arena/1ffa1d19-doctor",
          }, str(evidence["binding"]))
    check("evidence-count", evidence["measurement_count"] == 1)
    check("diagnostic-field-set",
          set(evidence["diagnostics"]) == set(SAFE_DIAGNOSTIC_FIELDS))
    check("diagnostic-static-values",
          evidence["diagnostics"]["active_mpm"] == "event"
          and evidence["diagnostics"]["php_execution_mode"] == "mod_php"
          and evidence["diagnostics"]["runner_cpu_count"] == 2
          and evidence["diagnostics"]["max_request_workers"] == 400)
    check("diagnostic-row-field-set",
          set(evidence["diagnostics"]["measurements"][0]) == set(SAFE_DIAGNOSTIC_ROW_FIELDS))
    check("diagnostic-resource-field-set",
          set(evidence["diagnostics"]["measurements"][0]["resources"])
          == set(SAFE_DIAGNOSTIC_RESOURCE_FIELDS))
    for field in SAFE_DIAGNOSTIC_FIELDS:
        tampered_diagnostic = json.loads(evidence_json)
        tampered_diagnostic["diagnostics"].pop(field)
        try:
            validate_safe_evidence(tampered_diagnostic)
        except EvidenceRefused:
            pass
        else:
            check("diagnostic-field-removal-refused-" + field, False)
    for field in SAFE_DIAGNOSTIC_RESOURCE_FIELDS:
        tampered_resource = json.loads(evidence_json)
        tampered_resource["diagnostics"]["measurements"][0]["resources"].pop(field)
        try:
            validate_safe_evidence(tampered_resource)
        except EvidenceRefused:
            pass
        else:
            check("diagnostic-resource-removal-refused-" + field, False)
    check("evidence-no-verbatim-label-in-output", "برچسب آزاد" not in evidence_json + evidence_md)
    check("evidence-endpoint-label-is-constant-set",
          [build_safe_evidence(_report([_measurement(1, "cold", key, 1, 200)]))[
              "measurements"][0]["endpoint_label"]
           for key in ("health", "availability", "wp-json-root")]
          == ["GET /health", "GET /availability", "GET /wp-json/"])

    # 8b) only the fixed endpoint label set is accepted; free-form names are refused
    for bad_endpoint in ("https://example.invalid/health", "/wp-json/clinic/v1/health", "HEALTH", ""):
        _refuses("endpoint-" + (bad_endpoint or "empty"),
                 _report([dict(_measurement(1, "cold", "health", 1, 200),
                               endpoint=bad_endpoint)]))
    # 8c) phase accepts only cold|warm (case-sensitive, no padding, no synonyms)
    for bad_phase in ("COLD", "cold ", " lukewarm", "hot", "", None):
        _refuses("phase-%r" % bad_phase,
                 _report([dict(_measurement(1, "cold", "health", 1, 200), phase=bad_phase)]))

    # 8d) malformed / nonnumeric / out-of-range / unexpected values fail closed
    _refuses("p95-string", _report([_measurement(1, "cold", "health", 1, 200,
                                                 _metrics(p95_ms="55.0"))]))
    _refuses("p95-missing", _report([_measurement(1, "cold", "health", 1, 200,
                                                  _metrics(p95_ms=None))]))
    _refuses("p99-nan", _report([_measurement(1, "cold", "health", 1, 200,
                                              _metrics(p99_ms=float("nan")))]))
    _refuses("rps-negative", _report([_measurement(1, "cold", "health", 1, 200,
                                                   _metrics(requests_per_second=-1.0))]))
    _refuses("latency-absurd", _report([_measurement(1, "cold", "health", 1, 200,
                                                     _metrics(p50_ms=MAX_LATENCY_MS + 1.0))]))
    _refuses("complete-over-planned", _report([_measurement(1, "cold", "health", 1, 200,
                                                            _metrics(complete_requests=201))]))
    _refuses("complete-zero", _report([_measurement(1, "cold", "health", 1, 200,
                                                    _metrics(complete_requests=0))]))
    _refuses("failed-over-complete", _report([_measurement(1, "cold", "health", 1, 200,
                                                            _metrics(failed_requests=201))]))
    _refuses("non2xx-negative", _report([_measurement(1, "cold", "health", 1, 200,
                                                      _metrics(non_2xx_responses=-1))]))
    _refuses("percentiles-not-monotonic", _report([_measurement(1, "cold", "health", 1, 200,
                                                                _metrics(p50_ms=90.0, p95_ms=55.0))]))
    _refuses("concurrency-string", _report([dict(_measurement(1, "cold", "health", 1, 200),
                                                 concurrency="10")]))
    _refuses("concurrency-bool", _report([dict(_measurement(1, "cold", "health", 1, 200),
                                               concurrency=True)]))
    _refuses("planned-zero", _report([dict(_measurement(1, "cold", "health", 1, 200),
                                           requests_planned=0)]))
    _refuses("metrics-not-object", _report([dict(_measurement(1, "cold", "health", 1, 200),
                                                 metrics=[1, 2, 3])]))
    _refuses("metrics-missing", _report([dict(_measurement(1, "cold", "health", 1, 200),
                                              metrics=None)]))
    _refuses("seq-non-contiguous",
             _report([_measurement(1, "cold", "health", 1, 200),
                      _measurement(3, "cold", "health", 1, 200)]))
    _refuses("seq-string", _report([dict(_measurement(1, "cold", "health", 1, 200), seq="1")]))
    _refuses("count-mismatch", _report([_measurement(1, "cold", "health", 1, 200)],
                                       measurement_count=2))
    _refuses("measurements-empty", _report([]))
    _refuses("measurements-not-list", _report({"nope": 1}))
    _refuses("report-not-object", ["not", "an", "object"])

    # 8e) binding fields must be present and validated
    for missing in SAFE_BINDING_KEYS:
        incomplete_meta = _run_meta()
        incomplete_meta.pop(missing)
        _refuses("binding-missing-" + missing,
                 _report([_measurement(1, "cold", "health", 1, 200)], run=incomplete_meta))
    _refuses("binding-sha-uppercase",
             _report([_measurement(1, "cold", "health", 1, 200)], run=_run_meta(head_sha="A" * 40)))
    _refuses("binding-sha-short",
             _report([_measurement(1, "cold", "health", 1, 200)], run=_run_meta(head_sha="abc")))
    _refuses("binding-run-id-nondigit",
             _report([_measurement(1, "cold", "health", 1, 200)], run=_run_meta(run_id="run-1")))
    _refuses("binding-run-attempt-zero",
             _report([_measurement(1, "cold", "health", 1, 200)], run=_run_meta(run_attempt="0")))
    _refuses("binding-event-not-allowlisted",
             _report([_measurement(1, "cold", "health", 1, 200)],
                     run=_run_meta(event_name="repository_dispatch")))
    _refuses("binding-ref-traversal",
             _report([_measurement(1, "cold", "health", 1, 200)],
                     run=_run_meta(ref="refs/heads/../../etc")))
    _refuses("binding-ref-whitespace",
             _report([_measurement(1, "cold", "health", 1, 200)],
                     run=_run_meta(ref="main branch")))
    check("evidence-accepts-real-event-set",
          [build_safe_evidence(_report([_measurement(1, "cold", "health", 1, 200)],
                                       run=_run_meta(event_name=event)))["binding"]["event_name"]
           for event in ("push", "schedule", "workflow_dispatch")] == ["push", "schedule", "workflow_dispatch"])

    # 8f) raw dump / banner / header / body / free-form / environment metadata cannot enter
    polluted = _report(
        [_measurement(1, "cold", "health", 1, 200,
                      _metrics(**{"raw_dump": "zeustech Set-Cookie: sid=deadbeef",
                                  "banner": "Server Software: Apache/2.4.58",
                                  "body": "<html>SYN-0042</html>",
                                  "free_text": "Authorization: Bearer abcdefgh"}),
                      label="FREE FORM " + " ".join(SENTINELS))],
        run=_run_meta(),
        notes="raw tail: " + " ".join(SENTINELS),
    )
    polluted_evidence = build_safe_evidence(polluted)
    polluted_json = json.dumps(polluted_evidence, ensure_ascii=False, indent=2)
    polluted_md = render_safe_evidence_markdown(polluted_evidence)
    for sentinel in SENTINELS:
        check("evidence-blocks-sentinel-%s" % sentinel[:12],
              sentinel not in polluted_json and sentinel not in polluted_md,
              sentinel)
    for forbidden_token in ("zeustech", "X-Powered-By", "Server Software", "Document Path",
                            "Apache/2.4.58", "/home/runner", "php_cli", "runner=", "dataset=",
                            "wp_url", "localhost", "/wp-json/clinic/v1/health", "<html>"):
        check("evidence-blocks-token-%s" % forbidden_token,
              forbidden_token not in (polluted_json + polluted_md), forbidden_token)
    check("evidence-generated-text-passes-privacy-scan",
          scan_forbidden(polluted_json) == [] and scan_forbidden(polluted_md) == [],
          str(scan_forbidden(polluted_json) + scan_forbidden(polluted_md)))
    check("evidence-md-has-binding-and-fence",
          "run_id=37361708008" in evidence_md and "```json" in evidence_md
          and SAFE_EVIDENCE_SCHEMA in evidence_md)
    check("evidence-md-is-measurement-only",
          "no NFR-PERF pass/fail is claimed" in evidence_md and "BLOCKED_BY_ENVIRONMENT" in evidence_md)

    # 8g) round-trip: the written files re-validate; tampering is refused
    with tempfile.TemporaryDirectory() as tmp2:
        root2 = Path(tmp2)
        ev_json_path = root2 / "bench.evidence.json"
        ev_md_path = root2 / "bench.evidence.md"
        ev_json_path.write_text(evidence_json + "\n", encoding="utf-8")
        ev_md_path.write_text(evidence_md, encoding="utf-8")
        check("verify-evidence-json-ok", verify_evidence_file(ev_json_path) == 0)
        check("verify-evidence-md-ok", verify_evidence_file(ev_md_path) == 0)
        check("verify-evidence-cli-ok", main(["--verify-evidence", str(ev_json_path)]) == 0)
        (root2 / "missing.json").write_text("not json at all", encoding="utf-8")
        check("verify-evidence-not-json-refused", verify_evidence_file(root2 / "missing.json") == 1)
        check("verify-evidence-absent-file", verify_evidence_file(root2 / "nope.json") == 2)
        tampered = json.loads(evidence_json)
        tampered["measurements"][0]["phase"] = "frozen"
        (root2 / "tampered.json").write_text(json.dumps(tampered), encoding="utf-8")
        check("verify-evidence-tampered-phase-refused",
              verify_evidence_file(root2 / "tampered.json") == 1)
        tampered2 = json.loads(evidence_json)
        tampered2["measurements"][0]["extra_leak"] = "Set-Cookie: sid=deadbeef"
        (root2 / "tampered2.json").write_text(json.dumps(tampered2), encoding="utf-8")
        check("verify-evidence-extra-field-refused",
              verify_evidence_file(root2 / "tampered2.json") == 1)
        (root2 / "tampered3.md").write_text(evidence_md + "\nSet-Cookie: sid=deadbeef\n",
                                            encoding="utf-8")
        check("verify-evidence-md-sentinel-refused", verify_evidence_file(root2 / "tampered3.md") == 1)
        (root2 / "truncated.md").write_text(evidence_md.split("```json")[0], encoding="utf-8")
        check("verify-evidence-md-truncated-refused",
              verify_evidence_file(root2 / "truncated.md") == 1)

    # 8h) end-to-end: the real 13-measurement matrix produces a validated projection,
    #     and a failing run produces NO evidence file at all (fail closed)
    with tempfile.TemporaryDirectory() as tmp3:
        root3 = Path(tmp3)
        raw3 = root3 / "raw"
        raw3.mkdir()
        manifest3 = root3 / "manifest.jsonl"
        lines: List[str] = []
        for seq, phase, endpoint, concurrency, planned in MATRIX:
            dump_name = "%03d-%s-%s.ab.txt" % (seq, phase, endpoint)
            (raw3 / dump_name).write_text(
                FIXTURE_AB.replace(
                    "Document Path:          /wp-json/clinic/v1/health",
                    "Document Path:          " + ENDPOINT_PATHS[endpoint],
                ),
                encoding="utf-8",
            )
            # the human label stays free-form prose in the report; it must never be projected
            lines.append(json.dumps(_entry(seq, phase, FREE_FORM_MARKER + " " + endpoint, endpoint,
                                           ENDPOINT_PATHS[endpoint], concurrency, planned,
                                           2 if phase == "warm" else 0, dump_name)))
        manifest3.write_text("\n".join(lines) + "\n", encoding="utf-8")
        diag3 = root3 / "diagnostics"
        diag3.mkdir()
        diagnostic_fixture = _diagnostic_for([_measurement(seq, phase, endpoint, concurrency, planned)
                                              for seq, phase, endpoint, concurrency, planned in MATRIX])
        (diag3 / "static.json").write_text(json.dumps(diagnostic_fixture["static"]), encoding="utf-8")
        for diagnostic_row in diagnostic_fixture["measurements"]:
            (diag3 / ("level-%03d.json" % diagnostic_row["seq"])).write_text(
                json.dumps(diagnostic_row), encoding="utf-8"
            )
        out3 = root3 / "out" / "bench"
        ev_prefix = root3 / "out" / "bench.evidence"
        rc7 = run(argparse.Namespace(
            manifest=str(manifest3), raw_dir=str(raw3), out_prefix=str(out3),
            meta=["run_id=37361708008", "run_attempt=1", "head_sha=" + "b" * 40,
                  "event_name=push", "ref=arena/1ffa1d19-doctor",
                  "runner=GitHub Actions 24.04", "php_cli=8.1"],
            diagnostics_dir=str(diag3), fail_file="", evidence_prefix=str(ev_prefix),
        ))
        check("evidence-e2e-rc-zero", rc7 == 0, "rc=%s" % rc7)
        ev3 = json.loads(Path(str(ev_prefix) + ".json").read_text(encoding="utf-8"))
        check("evidence-e2e-13-rows", ev3["measurement_count"] == 13 == len(ev3["measurements"]))
        check("evidence-e2e-matrix",
              [(m["seq"], m["phase"], m["endpoint"], m["concurrency"], m["requests_planned"])
               for m in ev3["measurements"]] == [tuple(row) for row in MATRIX],
              str([(m["seq"], m["phase"], m["endpoint"], m["concurrency"])
                   for m in ev3["measurements"]]))
        check("evidence-e2e-labels-fixed",
              [m["endpoint_label"] for m in ev3["measurements"]]
              == [[SAFE_ENDPOINT_LABELS[row[2]] for row in MATRIX][i] for i in range(13)])
        check("evidence-e2e-metrics-present",
              all(m["p50_ms"] is not None and m["p95_ms"] is not None and m["p99_ms"] is not None
                  and m["requests_per_second"] is not None for m in ev3["measurements"]))
        md3 = Path(str(ev_prefix) + ".md").read_text(encoding="utf-8")
        check("evidence-e2e-md-rows",
              sum(1 for line in md3.splitlines()
                  if line.startswith("| ") and "`GET " in line) == 13,
              str([line for line in md3.splitlines() if line.startswith("| ")][:3]))
        check("evidence-e2e-md-verified", verify_evidence_file(Path(str(ev_prefix) + ".md")) == 0)
        check("evidence-e2e-clean-scan",
              scan_forbidden(Path(str(ev_prefix) + ".json").read_text(encoding="utf-8")) == [])
        check("evidence-e2e-no-free-form-label",
              FREE_FORM_MARKER not in md3 and "FREE-FORM-LABEL-MARKER" not in
              Path(str(ev_prefix) + ".json").read_text(encoding="utf-8"))
        check("evidence-e2e-no-env-metadata",
              "runner" not in ev3 and "php_cli" not in ev3
              and "GitHub Actions" not in md3 and "8.1" not in md3)

        # a non-2xx measurement keeps rc=1 and must NOT produce a publishable projection
        (raw3 / "900-non2xx.ab.txt").write_text(FIXTURE_NON2XX, encoding="utf-8")
        manifest4 = root3 / "manifest4.jsonl"
        manifest4.write_text(
            json.dumps(_entry(1, "warm", "label", "health", "/wp-json/clinic/v1/health", 10, 3000, 2,
                              "900-non2xx.ab.txt")) + "\n",
            encoding="utf-8",
        )
        ev_prefix2 = root3 / "out2" / "bench.evidence"
        rc8 = run(argparse.Namespace(
            manifest=str(manifest4), raw_dir=str(raw3), out_prefix=str(root3 / "out2" / "bench"),
            meta=["run_id=1", "run_attempt=1", "head_sha=" + "b" * 40, "event_name=push",
                  "ref=main"],
            fail_file="", evidence_prefix=str(ev_prefix2),
        ))
        check("evidence-e2e-failing-run-rc1", rc8 == 1, "rc=%s" % rc8)
        check("evidence-e2e-failing-run-no-evidence",
              not Path(str(ev_prefix2) + ".json").exists()
              and not Path(str(ev_prefix2) + ".md").exists())

    # 7) the scanner itself
    check("privacy-trips-cookie", "cookie" in scan_forbidden("Set-Cookie: sid=abc"))
    check("privacy-trips-nonce", "nonce" in scan_forbidden("_wpnonce=deadbeef"))
    check("privacy-trips-phi-name", "synthetic_phi" in scan_forbidden("بیمار آزمایشی 7 / 09120001234"))
    check("privacy-trips-mrn", "synthetic_phi" in scan_forbidden("SYN-0042"))
    check("privacy-trips-appointment-code", "synthetic_phi" in scan_forbidden("SYNAP-00420"))
    check("privacy-trips-credential", "credential" in scan_forbidden("dbpass=root"))
    check("privacy-trips-absolute-host", "abs_host" in scan_forbidden("http://example.invalid/x"))
    check("privacy-allows-localhost", scan_forbidden("http://localhost:8080/wp-json/") == [])
    check("privacy-allows-numbers", scan_forbidden(legacy_lines[0]) == [])

    if failures:
        for line in failures:
            print(line, file=sys.stderr)
        print("SELF-TESTS: %s FAILED" % len(failures), file=sys.stderr)
        return 1
    print("SELF-TESTS: OK (pilot-bench-report — parse, report, honesty fields, privacy gate)")
    return 0


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(
        description="Pilot/Staging ab benchmark parser + artifact reporter (measurement only)."
    )
    parser.add_argument("--test", action="store_true", help="run the deterministic self-tests")
    parser.add_argument(
        "--scan-dir",
        default="",
        help="scan a directory of raw ab dumps for PHI/credential/cookie/nonce content and exit",
    )
    parser.add_argument("--manifest", default="/tmp/cpms-bench/manifest.jsonl")
    parser.add_argument("--raw-dir", default="/tmp/cpms-bench/raw")
    parser.add_argument(
        "--diagnostics-dir",
        default="",
        help="read static.json and one level-*.json per benchmark row from the bounded native diagnostic helper",
    )
    parser.add_argument("--out-prefix", default="/tmp/cpms-bench/cpms-pilot-benchmark")
    parser.add_argument(
        "--evidence-prefix",
        default="",
        help=(
            "write the allowlisted REST-visible safe evidence projection (JSON + Markdown comment "
            "body) to this path prefix; refused fail-closed on any malformed/unexpected structure"
        ),
    )
    parser.add_argument(
        "--verify-evidence",
        default="",
        help=(
            "validate an existing safe evidence projection file (.json, or a comment body .md with "
            "a fenced json block) against the allowlist and value rules, then exit"
        ),
    )
    parser.add_argument("--meta", action="append", default=[])
    parser.add_argument("--fail-file", default="")
    args = parser.parse_args(argv)

    if args.test:
        return _selftests()
    if args.verify_evidence:
        return verify_evidence_file(Path(args.verify_evidence))
    if args.scan_dir:
        problems = scan_raw_tree(Path(args.scan_dir))
        if problems:
            print(
                "PRIVACY: raw benchmark dumps contain forbidden content — they must not be "
                "uploaded:",
                file=sys.stderr,
            )
            for problem in problems:
                print(f"  - {problem}", file=sys.stderr)
            return 1
        print("PRIVACY SCAN: raw dumps clean (no cookie/authorization/nonce/credential/PHI)")
        return 0
    return run(args)


if __name__ == "__main__":
    sys.exit(main())

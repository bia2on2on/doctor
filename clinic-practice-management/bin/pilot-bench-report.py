#!/usr/bin/env python3
"""
pilot-bench-report.py — Phase 17 Slice 0: deterministic parse/report layer for the
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
    --meta KEY=VALUE       repeatable run/environment metadata recorded verbatim in JSON+MD
    --fail-file PATH       optional: also write the human-readable failure reason here

Exit codes:
    0 — parsed and reported successfully (no latency judgement is made)
    1 — a measurement could not be parsed / its dump is missing / it produced Non-2xx
        responses — i.e. exactly the two correctness failures the pre-existing inline
        shell parser already had, and nothing else
    2 — configuration/usage error (never a green no-op)

Privacy invariant (contract §9): the generated JSON/MD/TXT are scanned before being written;
Cookie/nonce/credential/PHI-shaped content aborts the run with rc=1. Only numbers, fixed
labels and the relative `Document Path` echoed by `ab` ever reach the output.
"""

from __future__ import annotations

import argparse
import json
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


def build_report(measurements: List[Dict[str, Any]], meta: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "schema": "cpms.pilot-benchmark/1",
        "purpose": (
            "Phase 17 Slice 0 — baseline measurement only; no product optimization, no latency "
            "gate, no NFR adjudication, no committed numbers"
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

    report = build_report(measurements, meta)
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
    check("schema", report["schema"] == "cpms.pilot-benchmark/1")
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
    parser.add_argument("--out-prefix", default="/tmp/cpms-bench/cpms-pilot-benchmark")
    parser.add_argument("--meta", action="append", default=[])
    parser.add_argument("--fail-file", default="")
    args = parser.parse_args(argv)

    if args.test:
        return _selftests()
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

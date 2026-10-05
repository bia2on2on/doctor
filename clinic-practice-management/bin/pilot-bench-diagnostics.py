#!/usr/bin/env python3
"""
Bounded, measurement-only diagnostics for the existing Pilot/Staging ApacheBench step.

This helper deliberately records summaries, not process listings, command lines, config
text, environment variables, HTTP data, or paths. It uses only Python's standard library
and Linux /proc plus the existing apache2ctl commands on the runner.

Commands:
  --test
  collect-static --out FILE
  measure --seq N --phase cold|warm --concurrency N --raw-out FILE --out FILE -- COMMAND ...

The measure command owns the ApacheBench child so it can sample that load generator
separately from the Apache/mod_php process group while the request level is running.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
import statistics
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict, Iterable, List, Mapping, Optional, Sequence, Tuple

SCHEMA = "cpms.pilot-bench-diagnostics/1"
SAMPLE_INTERVAL_SECONDS = 0.20
MAX_CPU_COUNT = 256
MAX_SAMPLES = 10000
MAX_MB = 10_000_000.0
MAX_PROCESS_CPU_PCT = 25_600.0
KNOWN_MPMS = ("event", "worker", "prefork")
KNOWN_PHP_MODES = ("mod_php", "php_fpm", "unknown")
DIRECTIVES = (
    "server_limit",
    "thread_limit",
    "threads_per_child",
    "max_request_workers",
    "max_connections_per_child",
)
DIRECTIVE_NAMES = {
    "server_limit": "ServerLimit",
    "thread_limit": "ThreadLimit",
    "threads_per_child": "ThreadsPerChild",
    "max_request_workers": "MaxRequestWorkers",
    "max_connections_per_child": "MaxConnectionsPerChild",
}
DEFAULTS = {
    "event": {
        "server_limit": 16,
        "thread_limit": 64,
        "threads_per_child": 25,
        "max_request_workers": 400,
        "max_connections_per_child": 0,
    },
    "worker": {
        "server_limit": 16,
        "thread_limit": 64,
        "threads_per_child": 25,
        "max_request_workers": 400,
        "max_connections_per_child": 0,
    },
    "prefork": {
        "server_limit": 256,
        "thread_limit": 0,
        "threads_per_child": 1,
        "max_request_workers": 256,
        "max_connections_per_child": 0,
    },
}


class DiagnosticFailure(RuntimeError):
    pass


def _bounded_int(value: Any, low: int, high: int) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or not low <= value <= high:
        raise DiagnosticFailure("bounded integer invariant failed")
    return value


def _bounded_number(value: Any, low: float, high: float) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise DiagnosticFailure("bounded number invariant failed")
    number = float(value)
    if not math.isfinite(number) or not low <= number <= high:
        raise DiagnosticFailure("bounded number invariant failed")
    return number


def _round(value: float) -> float:
    return round(float(value), 2)


def _run_capture(command: Sequence[str], timeout: float = 10.0) -> Tuple[int, str]:
    try:
        result = subprocess.run(
            list(command),
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return 127, ""
    # The output is parsed in memory and only bounded fields are returned. It is never
    # written to a diagnostic artifact or displayed.
    return result.returncode, result.stdout + "\n" + result.stderr


def _extract_directives(text: str) -> Dict[str, int]:
    found: Dict[str, int] = {}
    for key, directive in DIRECTIVE_NAMES.items():
        match = re.findall(r"^\s*" + re.escape(directive) + r"\s+(\d+)\s*(?:#.*)?$", text, re.MULTILINE)
        if match:
            found[key] = int(match[-1])
    return found


def _active_config_values(mpm: str) -> Dict[str, int]:
    """Read only known MPM directive lines from likely active config files.

    This is a fallback for Apache versions whose DUMP_RUN_CFG output omits these
    values. No config text is returned or persisted.
    """
    files: List[Path] = []
    roots = (Path("/etc/apache2/mods-enabled"), Path("/etc/apache2/conf-enabled"))
    for root in roots:
        active = root / ("mpm_" + mpm + ".conf")
        if active.is_file():
            files.append(active)
    apache_conf = Path("/etc/apache2/apache2.conf")
    if apache_conf.is_file():
        files.append(apache_conf)
    values: Dict[str, int] = {}
    for path in files:
        try:
            values.update(_extract_directives(path.read_text(encoding="utf-8", errors="replace")))
        except OSError:
            continue
    return values


def _parse_static_outputs(modules: str, version: str, run_cfg: str, cpu_count: int,
                          config_values: Optional[Mapping[str, int]] = None) -> Dict[str, Any]:
    mpm_matches = re.findall(r"\bmpm_(event|worker|prefork)_module\b", modules + "\n" + version)
    unique_mpm = list(dict.fromkeys(mpm_matches))
    if len(unique_mpm) != 1:
        raise DiagnosticFailure("apache_mpm_unavailable")
    active_mpm = unique_mpm[0]

    has_mod_php = bool(re.search(r"^\s*php[0-9.]*_module\b|^\s*php_module\b", modules, re.MULTILINE))
    has_php_fpm = bool(re.search(r"\bproxy_fcgi_module\b|\bphp-fpm\b", modules, re.IGNORECASE))
    php_mode = "mod_php" if has_mod_php else "php_fpm" if has_php_fpm else "unknown"
    if php_mode == "unknown":
        raise DiagnosticFailure("php_handler_unavailable")

    values = dict(DEFAULTS[active_mpm])
    values.update(config_values or {})
    values.update(_extract_directives(run_cfg))
    # Some Apache builds describe the effective values with spaced labels rather
    # than directive syntax. Prefer those values when present.
    labels = {
        "server_limit": r"Server\s+Limit",
        "thread_limit": r"Thread\s+Limit",
        "threads_per_child": r"Threads\s+Per\s+Child",
        "max_request_workers": r"(?:Max\s+Request\s+Workers|Max\s+Workers)",
        "max_connections_per_child": r"Max\s+Connections\s+Per\s+Child",
    }
    for key, label in labels.items():
        matches = re.findall(r"^\s*" + label + r"\s*:?\s+(\d+)\s*$", run_cfg, re.MULTILINE | re.IGNORECASE)
        if matches:
            values[key] = int(matches[-1])

    for key in DIRECTIVES:
        _bounded_int(values.get(key), 0, 1_000_000)
    if values["max_request_workers"] < 1 or values["threads_per_child"] < 1:
        raise DiagnosticFailure("worker_capacity_unavailable")
    return {
        "schema": SCHEMA,
        "status": "ok",
        "runner_cpu_count": _bounded_int(cpu_count, 1, MAX_CPU_COUNT),
        "active_mpm": active_mpm,
        "php_execution_mode": php_mode,
        "server_limit": values["server_limit"],
        "thread_limit": values["thread_limit"],
        "threads_per_child": values["threads_per_child"],
        "max_request_workers": values["max_request_workers"],
        "max_connections_per_child": values["max_connections_per_child"],
    }


def collect_static() -> Dict[str, Any]:
    cpu_count = os.cpu_count() or 0
    if not 1 <= cpu_count <= MAX_CPU_COUNT:
        return {"schema": SCHEMA, "status": "measurement_failed", "error_code": "runner_cpu_unavailable"}
    mpm_rc, modules = _run_capture(("apache2ctl", "-M"))
    version_rc, version = _run_capture(("apache2ctl", "-V"))
    cfg_rc, run_cfg = _run_capture(("apache2ctl", "-t", "-D", "DUMP_RUN_CFG"))
    if mpm_rc != 0 or version_rc != 0 or cfg_rc != 0:
        return {"schema": SCHEMA, "status": "measurement_failed", "error_code": "apache_command_failed"}
    try:
        mpm_match = re.search(r"\bmpm_(event|worker|prefork)_module\b", modules + "\n" + version)
        config_values = _active_config_values(mpm_match.group(1)) if mpm_match else {}
        return _parse_static_outputs(modules, version, run_cfg, cpu_count, config_values)
    except DiagnosticFailure as exc:
        return {"schema": SCHEMA, "status": "measurement_failed", "error_code": str(exc)}


def _read_system_cpu() -> Tuple[int, int]:
    line = Path("/proc/stat").read_text(encoding="ascii").splitlines()[0]
    fields = line.split()
    if not fields or fields[0] != "cpu" or len(fields) < 5:
        raise DiagnosticFailure("proc_cpu_unavailable")
    values = [int(value) for value in fields[1:]]
    total = sum(values)
    idle = values[3] + (values[4] if len(values) > 4 else 0)
    return total, total - idle


def _read_memory() -> Tuple[float, float]:
    fields: Dict[str, int] = {}
    for line in Path("/proc/meminfo").read_text(encoding="ascii").splitlines():
        if ":" not in line:
            continue
        key, rest = line.split(":", 1)
        match = re.match(r"\s*(\d+)\s+kB", rest)
        if match:
            fields[key] = int(match.group(1))
    total = fields.get("MemTotal", 0) * 1024
    available = fields.get("MemAvailable", fields.get("MemFree", 0)) * 1024
    if total <= 0 or available < 0:
        raise DiagnosticFailure("proc_memory_unavailable")
    available_mb = available / (1024 * 1024)
    used_pct = 100.0 * max(0, total - available) / total
    return available_mb, used_pct


def _read_processes() -> Dict[int, Dict[str, Any]]:
    result: Dict[int, Dict[str, Any]] = {}
    for path in Path("/proc").glob("[0-9]*"):
        try:
            raw = (path / "stat").read_text(encoding="ascii")
            close = raw.rfind(") ")
            if close < 0:
                continue
            comm = raw[raw.find("(") + 1:close]
            fields = raw[close + 2:].split()
            # fields[0] is stat field 3 (state); utime/stime are fields 14/15;
            # rss is field 24. All indices below are relative to fields.
            pid = int(path.name)
            ticks = int(fields[11]) + int(fields[12])
            rss_pages = max(0, int(fields[21]))
            result[pid] = {"comm": comm, "ticks": ticks, "rss_mb": rss_pages * os.sysconf("SC_PAGE_SIZE") / (1024 * 1024)}
        except (OSError, ValueError, IndexError):
            continue
    return result


def _group_totals(processes: Mapping[int, Mapping[str, Any]], ab_pid: int) -> Dict[str, Any]:
    apache = [p for p in processes.values() if p["comm"] in ("apache2", "httpd")]
    php_fpm = [p for p in processes.values() if str(p["comm"]).startswith("php-fpm")]
    ab = [processes[ab_pid]] if ab_pid in processes else []

    def totals(items: Iterable[Mapping[str, Any]]) -> Tuple[int, float, int]:
        rows = list(items)
        return sum(int(row["ticks"]) for row in rows), sum(float(row["rss_mb"]) for row in rows), len(rows)

    apache_ticks, apache_rss, apache_count = totals(apache)
    fpm_ticks, fpm_rss, fpm_count = totals(php_fpm)
    ab_ticks, ab_rss, ab_count = totals(ab)
    return {
        "apache": (apache_ticks, apache_rss, apache_count),
        "php_fpm": (fpm_ticks, fpm_rss, fpm_count),
        "server": (apache_ticks + fpm_ticks, apache_rss + fpm_rss, apache_count + fpm_count),
        "ab": (ab_ticks, ab_rss, ab_count),
    }


def _summary(samples: List[Dict[str, Any]], elapsed_ms: float, command_exit_code: int,
             runner_cpu_count: int, seq: int, phase: str, concurrency: int) -> Dict[str, Any]:
    if not samples:
        raise DiagnosticFailure("no_valid_samples")

    def values(name: str) -> List[float]:
        return [float(sample[name]) for sample in samples]

    def avg(name: str) -> float:
        return _round(statistics.fmean(values(name)))

    def maxv(name: str) -> float:
        return _round(max(values(name)))

    server_counts = [int(sample["server_process_count"]) for sample in samples]
    if max(server_counts) < 1:
        raise DiagnosticFailure("server_process_unobserved")
    _bounded_int(len(samples), 1, MAX_SAMPLES)
    return {
        "schema": SCHEMA,
        "seq": _bounded_int(seq, 1, 9999),
        "phase": phase,
        "concurrency": _bounded_int(concurrency, 1, 1000),
        "status": "ok",
        "command_exit_code": _bounded_int(command_exit_code, 0, 255),
        "sample_count": len(samples),
        "sample_interval_ms": _round(SAMPLE_INTERVAL_SECONDS * 1000),
        "elapsed_ms": _round(max(0.0, elapsed_ms)),
        "runner_cpu_count": _bounded_int(runner_cpu_count, 1, MAX_CPU_COUNT),
        "host_cpu_avg_pct": avg("host_cpu_pct"),
        "host_cpu_max_pct": maxv("host_cpu_pct"),
        "host_mem_available_min_mb": _round(min(values("host_mem_available_mb"))),
        "host_mem_used_max_pct": maxv("host_mem_used_pct"),
        "ab_cpu_avg_pct": avg("ab_cpu_pct"),
        "ab_cpu_max_pct": maxv("ab_cpu_pct"),
        "ab_rss_max_mb": maxv("ab_rss_mb"),
        "server_cpu_avg_pct": avg("server_cpu_pct"),
        "server_cpu_max_pct": maxv("server_cpu_pct"),
        "server_rss_max_mb": maxv("server_rss_mb"),
        "server_process_count_max": max(server_counts),
    }


def measure(args: argparse.Namespace) -> int:
    runner_cpu_count = os.cpu_count() or 0
    raw_out = Path(args.raw_out)
    diag_out = Path(args.out)
    raw_out.parent.mkdir(parents=True, exist_ok=True)
    diag_out.parent.mkdir(parents=True, exist_ok=True)
    command = list(args.command)
    if not command:
        diag_out.write_text(json.dumps({"schema": SCHEMA, "status": "measurement_failed", "error_code": "command_missing"}) + "\n", encoding="utf-8")
        return 2
    samples: List[Dict[str, Any]] = []
    previous_system: Optional[Tuple[int, int]] = None
    previous_processes: Dict[int, Dict[str, Any]] = {}
    start = time.monotonic()
    command_exit = 255
    collection_error: Optional[str] = None
    try:
        with raw_out.open("w", encoding="utf-8") as raw_handle:
            process = subprocess.Popen(command, stdout=raw_handle, stderr=subprocess.STDOUT, close_fds=True)
            while True:
                now = time.monotonic()
                try:
                    system = _read_system_cpu()
                    available_mb, used_pct = _read_memory()
                    processes = _read_processes()
                    groups = _group_totals(processes, process.pid)
                    if previous_system is not None:
                        total_delta = system[0] - previous_system[0]
                        if total_delta > 0:
                            host_pct = 100.0 * (system[1] - previous_system[1]) / total_delta
                            denom = total_delta / runner_cpu_count
                            row: Dict[str, Any] = {
                                "host_cpu_pct": max(0.0, min(100.0, host_pct)),
                                "host_mem_available_mb": available_mb,
                                "host_mem_used_pct": max(0.0, min(100.0, used_pct)),
                            }
                            for label in ("ab", "server"):
                                current = groups[label]
                                previous = previous_processes
                                current_ticks = 0
                                for pid, item in processes.items():
                                    if label == "ab" and pid != process.pid:
                                        continue
                                    if label == "server" and item["comm"] not in ("apache2", "httpd") and not str(item["comm"]).startswith("php-fpm"):
                                        continue
                                    current_ticks += max(0, int(item["ticks"]) - int(previous.get(pid, {"ticks": item["ticks"]})["ticks"]))
                                row[label + "_cpu_pct"] = max(0.0, min(MAX_PROCESS_CPU_PCT, 100.0 * current_ticks / denom))
                                row[label + "_rss_mb"] = max(0.0, min(MAX_MB, float(current[1])))
                                if label == "server":
                                    row["server_process_count"] = int(current[2])
                            samples.append(row)
                            if len(samples) > MAX_SAMPLES:
                                collection_error = "sample_limit_exceeded"
                                break
                    previous_system = system
                    previous_processes = processes
                except (DiagnosticFailure, OSError, ValueError, IndexError) as exc:
                    collection_error = str(exc)
                    break
                command_exit = process.poll()
                if command_exit is not None:
                    # One final sample keeps the resource window aligned with the last
                    # request even when the process exits between ticks.
                    break
                time.sleep(SAMPLE_INTERVAL_SECONDS)
                if time.monotonic() - start > 1800:
                    process.kill()
                    collection_error = "measurement_timeout"
                    command_exit = process.wait()
                    break
            if command_exit is None:
                command_exit = process.wait()
    except (OSError, subprocess.SubprocessError):
        collection_error = collection_error or "process_launch_failed"

    elapsed_ms = (time.monotonic() - start) * 1000
    if collection_error or not 1 <= runner_cpu_count <= MAX_CPU_COUNT:
        row = {
            "schema": SCHEMA,
            "seq": args.seq,
            "phase": args.phase,
            "concurrency": args.concurrency,
            "status": "measurement_failed",
            "error_code": collection_error or "runner_cpu_unavailable",
            "command_exit_code": max(0, min(255, int(command_exit))),
        }
        diag_out.write_text(json.dumps(row, sort_keys=True) + "\n", encoding="utf-8")
        return 1
    try:
        summary = _summary(samples, elapsed_ms, int(command_exit), runner_cpu_count, args.seq, args.phase, args.concurrency)
    except DiagnosticFailure as exc:
        summary = {
            "schema": SCHEMA,
            "seq": args.seq,
            "phase": args.phase,
            "concurrency": args.concurrency,
            "status": "measurement_failed",
            "error_code": str(exc),
            "command_exit_code": max(0, min(255, int(command_exit))),
        }
        diag_out.write_text(json.dumps(summary, sort_keys=True) + "\n", encoding="utf-8")
        return 1
    diag_out.write_text(json.dumps(summary, sort_keys=True) + "\n", encoding="utf-8")
    return 0


def collect_static_command(out: Path) -> int:
    out.parent.mkdir(parents=True, exist_ok=True)
    payload = collect_static()
    out.write_text(json.dumps(payload, sort_keys=True) + "\n", encoding="utf-8")
    return 0 if payload.get("status") == "ok" else 1


def _selftests() -> int:
    failures: List[str] = []

    def check(name: str, condition: bool) -> None:
        if not condition:
            failures.append(name)

    modules = """
 mpm_event_module (shared)
 php_module (shared)
    """
    run_cfg = """
Server Limit: 16
Thread Limit: 64
Threads Per Child: 25
Max Request Workers: 400
Max Connections Per Child: 0
    """
    static = _parse_static_outputs(modules, "Server MPM: event", run_cfg, 2, {})
    check("static-status", static["status"] == "ok")
    check("static-mpm", static["active_mpm"] == "event")
    check("static-php", static["php_execution_mode"] == "mod_php")
    check("static-capacity", [static[key] for key in DIRECTIVES] == [16, 64, 25, 400, 0])
    check("static-runner-cpu", static["runner_cpu_count"] == 2)

    fake_samples = [
        {
            "host_cpu_pct": 42.0, "host_mem_available_mb": 512.0, "host_mem_used_pct": 75.0,
            "ab_cpu_pct": 30.0, "ab_rss_mb": 10.0, "server_cpu_pct": 80.0,
            "server_rss_mb": 100.0, "server_process_count": 5,
        },
        {
            "host_cpu_pct": 90.0, "host_mem_available_mb": 500.0, "host_mem_used_pct": 76.0,
            "ab_cpu_pct": 35.0, "ab_rss_mb": 11.0, "server_cpu_pct": 95.0,
            "server_rss_mb": 105.0, "server_process_count": 6,
        },
    ]
    summary = _summary(fake_samples, 500.0, 0, 2, 1, "warm", 50)
    check("summary-status", summary["status"] == "ok")
    check("summary-samples", summary["sample_count"] == 2)
    check("summary-bounded-host", 0 <= summary["host_cpu_max_pct"] <= 100)
    check("summary-server-count", summary["server_process_count_max"] == 6)
    check("summary-no-process-identifiers", all(key not in summary for key in ("pid", "command", "path", "environment")))

    bad = dict(static)
    bad["max_request_workers"] = 0
    try:
        _parse_static_outputs(modules, "Server MPM: event", "", 2, {"max_request_workers": 0})
    except DiagnosticFailure:
        pass
    else:
        check("invalid-capacity-refused", False)
    check("bad-shape-not-used", "path" not in bad)

    if failures:
        for failure in failures:
            print("SELF-TEST FAIL: " + failure, file=sys.stderr)
        print("SELF-TESTS: %d FAILED" % len(failures), file=sys.stderr)
        return 1
    print("SELF-TESTS: OK (pilot-bench-diagnostics — bounded schema and native summaries)")
    return 0


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="bounded Pilot/Staging host/Apache benchmark diagnostics")
    parser.add_argument("--test", action="store_true")
    subparsers = parser.add_subparsers(dest="operation")
    static_parser = subparsers.add_parser("collect-static")
    static_parser.add_argument("--out", required=True)
    measure_parser = subparsers.add_parser("measure")
    measure_parser.add_argument("--seq", type=int, required=True)
    measure_parser.add_argument("--phase", choices=("cold", "warm"), required=True)
    measure_parser.add_argument("--concurrency", type=int, required=True)
    measure_parser.add_argument("--raw-out", required=True)
    measure_parser.add_argument("--out", required=True)
    measure_parser.add_argument("benchmark_command", nargs=argparse.REMAINDER)
    args = parser.parse_args(argv)
    if args.test:
        return _selftests()
    if args.operation == "collect-static":
        return collect_static_command(Path(args.out))
    if args.operation == "measure":
        command = list(args.benchmark_command)
        if command and command[0] == "--":
            command = command[1:]
        args.command = command
        return measure(args)
    parser.error("a command is required")
    return 2


if __name__ == "__main__":
    sys.exit(main())

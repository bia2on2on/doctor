#!/usr/bin/env python3
"""
AuthorizationService focused line-coverage guard (Phase 19 / Slice 19-1).

Fail-closed parser for ONE PHPUnit Clover report. It answers a single question:
how many executable statement lines does src/Application/Authorization/
AuthorizationService.php have, and how many of them were executed by the
existing Integration test tests/Integration/AuthorizationServiceTest.php?

Any of the following yields FAIL (exit 1), never PASS and never a silent skip:
  - driver unavailable (PCOV not loaded / pcov.enabled not effective / no version)
  - PHPUnit exit code missing, non-zero, or no terminal PHPUnit summary line
  - PHPUnit version identity not recorded
  - Clover report absent, unreadable, or not well-formed XML
  - target file absent from the report, or matched more than once
  - target with zero executable (type="stmt") lines
  - file-level metrics inconsistent with the per-line data

This is a measurement, not a security verdict. A coverage percentage says which
lines executed; it does not establish that authorization decisions are correct.

Usage:
  python3 bin/authorization-coverage-guard.py \
      --clover FILE --target RELPATH --source FILE \
      --driver-json FILE --phpunit-version-file FILE \
      --phpunit-rc-file FILE --phpunit-log FILE --out-dir DIR \
      --commit SHA --run-id ID --run-attempt N
  python3 bin/authorization-coverage-guard.py --test

Outputs (in --out-dir): authz-coverage-summary.json, authz-coverage-summary.md
Exit: 0 PASS / 1 FAIL / 2 usage error.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

TARGET = "src/Application/Authorization/AuthorizationService.php"
SUMMARY_NAME = "authz-coverage-summary.json"
MARKDOWN_NAME = "authz-coverage-summary.md"
TERMINAL_RE = re.compile(r"^(OK \(|OK, but|FAILURES!|ERRORS!|WARNINGS!)", re.MULTILINE)
PHPUNIT_VERSION_RE = re.compile(r"PHPUnit (\d+\.\d+\.\d+)")


class GuardFailure(Exception):
    """Raised for any condition that must produce FAIL."""


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def read_text(path: Path, label: str) -> str:
    if not path.is_file():
        raise GuardFailure(f"{label} is absent: {path}")
    try:
        return path.read_text(encoding="utf-8", errors="strict")
    except (OSError, UnicodeDecodeError) as exc:
        raise GuardFailure(f"{label} is unreadable: {exc}") from exc


def check_driver(driver_json: Path) -> dict:
    try:
        info = json.loads(read_text(driver_json, "driver probe"))
    except json.JSONDecodeError as exc:
        raise GuardFailure(f"driver probe is not valid JSON: {exc}") from exc
    if info.get("pcov_loaded") is not True:
        raise GuardFailure("coverage driver unavailable: PCOV extension not loaded")
    if info.get("pcov_enabled") is not True:
        raise GuardFailure("coverage driver unavailable: pcov.enabled is not effective")
    if not info.get("pcov_version"):
        raise GuardFailure("coverage driver identity missing: PCOV version not recorded")
    if not info.get("php_version"):
        raise GuardFailure("PHP version not recorded")
    return info


def check_phpunit(rc_file: Path, log_file: Path, version_file: Path) -> dict:
    rc_text = read_text(rc_file, "PHPUnit exit-code record").strip()
    if not re.fullmatch(r"-?\d+", rc_text):
        raise GuardFailure(f"PHPUnit exit-code record is malformed: {rc_text!r}")
    rc = int(rc_text)
    log = read_text(log_file, "PHPUnit log")
    m = TERMINAL_RE.search(log)
    if rc != 0:
        raise GuardFailure(f"test execution failed: PHPUnit exit code {rc}")
    if m is None:
        raise GuardFailure("PHPUnit terminated without a terminal summary (not a completed run)")
    version_text = read_text(version_file, "PHPUnit version record")
    vm = PHPUNIT_VERSION_RE.search(version_text)
    if vm is None:
        raise GuardFailure("PHPUnit version identity not recorded")
    summary_line = next((ln for ln in log.splitlines() if TERMINAL_RE.match(ln)), "")
    return {"exit_code": rc, "terminal_summary": summary_line.strip(), "version": vm.group(1)}


def parse_clover(clover_file: Path, target: str) -> dict:
    raw = read_text(clover_file, "Clover report")
    try:
        root = ET.fromstring(raw)
    except ET.ParseError as exc:
        raise GuardFailure(f"Clover report is malformed XML: {exc}") from exc
    if root.tag != "coverage":
        raise GuardFailure("Clover report root element is not <coverage>")
    project = root.find("project")
    if project is None:
        raise GuardFailure("Clover report has no <project> element")

    needle = "/" + target
    matches = [f for f in project.iter("file")
               if (f.get("name") or "").replace("\\", "/").endswith(needle)]
    if not matches:
        raise GuardFailure(f"target absent from Clover report: {target}")
    if len(matches) != 1:
        raise GuardFailure(f"target matched {len(matches)} times in Clover report (expected 1)")
    file_el = matches[0]

    stmt_lines = []
    for line in file_el.findall("line"):
        if line.get("type") != "stmt":
            continue
        try:
            num = int(line.get("num") or "")
            count = int(line.get("count") or "")
        except ValueError as exc:
            raise GuardFailure(f"malformed statement line record: {exc}") from exc
        if count < 0:
            raise GuardFailure(f"negative hit count on line {num}")
        stmt_lines.append((num, count))

    metrics = file_el.find("metrics")
    if metrics is None:
        raise GuardFailure("target has no file-level <metrics> element")
    try:
        m_statements = int(metrics.get("statements") or "")
        m_covered = int(metrics.get("coveredstatements") or "")
    except ValueError as exc:
        raise GuardFailure(f"malformed file-level metrics: {exc}") from exc

    executable = len(stmt_lines)
    if executable == 0:
        raise GuardFailure("target contains no measurable executable lines")
    covered = sum(1 for _, c in stmt_lines if c > 0)
    if (executable, covered) != (m_statements, m_covered):
        raise GuardFailure(
            "file metrics inconsistent with per-line data: "
            f"lines {covered}/{executable} vs metrics {m_covered}/{m_statements}"
        )
    uncovered = sorted(num for num, c in stmt_lines if c == 0)
    return {
        "report_path_suffix": needle,
        "executable_lines": executable,
        "covered_lines": covered,
        "uncovered_lines": uncovered,
    }


def compress_ranges(nums: list[int]) -> str:
    if not nums:
        return "none"
    parts, start, prev = [], nums[0], nums[0]
    for n in nums[1:]:
        if n == prev + 1:
            prev = n
            continue
        parts.append(f"{start}" if start == prev else f"{start}-{prev}")
        start = prev = n
    parts.append(f"{start}" if start == prev else f"{start}-{prev}")
    return ", ".join(parts)


def build_summary(args: argparse.Namespace) -> tuple[dict, list[str]]:
    failures: list[str] = []
    result: dict = {
        "schema": "cpms.authz-coverage.v1",
        "target": args.target,
        "measurement_scope": "line coverage of one production class by one existing Integration test file",
        "not_a_security_verdict": True,
        "commit": args.commit,
        "event_sha": args.event_sha,
        "run_id": args.run_id,
        "run_attempt": args.run_attempt,
        "status": "FAIL",
        "failures": failures,
    }
    try:
        driver = check_driver(Path(args.driver_json))
        result["php_version"] = driver.get("php_version")
        result["pcov_version"] = driver.get("pcov_version")
    except GuardFailure as exc:
        failures.append(str(exc))
    try:
        phpunit = check_phpunit(Path(args.phpunit_rc_file), Path(args.phpunit_log),
                                Path(args.phpunit_version_file))
        result["phpunit_version"] = phpunit["version"]
        result["phpunit_exit_code"] = phpunit["exit_code"]
        result["phpunit_terminal_summary"] = phpunit["terminal_summary"]
    except GuardFailure as exc:
        failures.append(str(exc))
    try:
        cov = parse_clover(Path(args.clover), args.target)
        executable = cov["executable_lines"]
        covered = cov["covered_lines"]
        result.update({
            "executable_lines": executable,
            "covered_lines": covered,
            "uncovered_line_count": executable - covered,
            "line_coverage_percent": round(100.0 * covered / executable, 2),
            "uncovered_lines": cov["uncovered_lines"],
            "uncovered_line_ranges": compress_ranges(cov["uncovered_lines"]),
        })
    except GuardFailure as exc:
        failures.append(str(exc))
    if args.source:
        try:
            result["target_source_sha256"] = sha256_file(Path(args.source))
        except OSError as exc:
            failures.append(f"target source unreadable for hash binding: {exc}")
    try:
        result["clover_sha256"] = sha256_file(Path(args.clover))
    except OSError:
        result["clover_sha256"] = None
    if not failures:
        result["status"] = "PASS"
    return result, failures


def render_markdown(result: dict) -> str:
    lines = [
        f"### AuthorizationService focused line coverage — **{result['status']}**",
        "",
        f"- Target: `{result['target']}`",
        f"- Head commit: `{result['commit']}` (event SHA `{result.get('event_sha')}`)",
        f"- Workflow run `{result['run_id']}` attempt `{result['run_attempt']}`",
        f"- PHP `{result.get('php_version')}` · PHPUnit `{result.get('phpunit_version')}` · PCOV `{result.get('pcov_version')}`",
    ]
    if "executable_lines" in result:
        lines += [
            f"- Executable lines (denominator): **{result['executable_lines']}**",
            f"- Covered lines (numerator): **{result['covered_lines']}**",
            f"- Line coverage: **{result['line_coverage_percent']}%** (measured line coverage only; not a security-correctness claim)",
            f"- Uncovered lines: {result['uncovered_line_ranges']}",
        ]
    if result.get("failures"):
        lines += ["", "**Failures (fail-closed):**"] + [f"- {f}" for f in result["failures"]]
    lines += ["", "_Privacy: aggregate line numbers and hashes only; no request data, credentials, or patient data._", ""]
    return "\n".join(lines)


def run_measurement(args: argparse.Namespace) -> int:
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    result, failures = build_summary(args)
    (out_dir / SUMMARY_NAME).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (out_dir / MARKDOWN_NAME).write_text(render_markdown(result), encoding="utf-8")
    print(render_markdown(result))
    if failures:
        for f in failures:
            print(f"::error::AuthorizationService coverage guard: {f}")
        return 1
    return 0


# ---------------------------------------------------------------------------
# Negative-control self-test (deterministic; no PHP, no network, no database).
# ---------------------------------------------------------------------------

GOOD_CLOVER = """<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="0">
  <project timestamp="0" name="t">
    <file name="/w/clinic-practice-management/src/Application/Authorization/AuthorizationService.php">
      <class name="AuthorizationService" namespace="ClinicCore\\\\Application\\\\Authorization">
        <metrics methods="2" coveredmethods="1" statements="4" coveredstatements="2" elements="6" coveredelements="3"/>
      </class>
      <line num="10" type="stmt" count="3"/>
      <line num="11" type="stmt" count="0"/>
      <line num="12" type="method" count="3"/>
      <line num="13" type="stmt" count="1"/>
      <line num="14" type="stmt" count="0"/>
      <metrics methods="2" coveredmethods="1" statements="4" coveredstatements="2" elements="6" coveredelements="3"/>
    </file>
  </project>
</coverage>
"""

GOOD_DRIVER = json.dumps({"pcov_loaded": True, "pcov_enabled": True,
                          "pcov_version": "1.0.12", "php_version": "8.2.99"})
GOOD_VERSION = "PHPUnit 9.6.38 by Sebastian Bergmann and contributors."
GOOD_LOG = "...\n\nOK (11 tests, 40 assertions)\n"


def _case(tmp: Path, name: str, *, clover: str | None = GOOD_CLOVER, driver: str | None = GOOD_DRIVER,
          rc: str | None = "0", log: str | None = GOOD_LOG, version: str | None = GOOD_VERSION,
          target: str = TARGET) -> tuple[argparse.Namespace, Path]:
    d = tmp / name
    d.mkdir(parents=True)
    paths = {}
    for key, content in (("clover.xml", clover), ("driver.json", driver), ("rc.txt", rc),
                         ("phpunit.log", log), ("version.txt", version)):
        if content is not None:
            (d / key).write_text(content, encoding="utf-8")
    source = d / "AuthorizationService.php"
    source.write_text("<?php\n", encoding="utf-8")
    ns = argparse.Namespace(
        clover=str(d / "clover.xml"), target=target, source=str(source),
        driver_json=str(d / "driver.json"), phpunit_version_file=str(d / "version.txt"),
        phpunit_rc_file=str(d / "rc.txt"), phpunit_log=str(d / "phpunit.log"),
        out_dir=str(d / "out"), commit="0" * 40, event_sha="0" * 40, run_id="selftest", run_attempt="1",
    )
    return ns, d


def run_self_test() -> int:
    results: list[tuple[str, bool, str]] = []
    with tempfile.TemporaryDirectory(prefix="authz-cov-selftest-") as td:
        tmp = Path(td)

        # Positive control: a well-formed report with real line data must PASS and compute numbers.
        ns, _ = _case(tmp, "positive")
        res, failures = build_summary(ns)
        ok = (not failures and res["status"] == "PASS" and res["executable_lines"] == 4
              and res["covered_lines"] == 2 and res["uncovered_lines"] == [11, 14]
              and res["line_coverage_percent"] == 50.0 and res["phpunit_version"] == "9.6.38")
        results.append(("positive control: well-formed report passes with exact numbers", ok, str(failures)))

        negatives = [
            ("missing Clover report", dict(clover=None)),
            ("malformed Clover XML", dict(clover="<coverage><project>")),
            ("target absent from report", dict(target="src/Application/Authorization/Missing.php")),
            ("zero executable lines", dict(clover=GOOD_CLOVER.replace('type="stmt"', 'type="method"')
                                           .replace('coveredstatements="2"', 'coveredstatements="0"')
                                           .replace('statements="4"', 'statements="0"'))),
            ("file metrics inconsistent with line data", dict(clover=GOOD_CLOVER.replace(
                'statements="4" coveredstatements="2" elements="6" coveredelements="3"/>\n    </file>',
                'statements="5" coveredstatements="2" elements="6" coveredelements="3"/>\n    </file>'))),
            ("PCOV driver unavailable", dict(driver=json.dumps({"pcov_loaded": False, "pcov_enabled": False,
                                                                "pcov_version": None, "php_version": "8.2.99"}))),
            ("pcov loaded but not enabled", dict(driver=json.dumps({"pcov_loaded": True, "pcov_enabled": False,
                                                                    "pcov_version": "1.0.12", "php_version": "8.2.99"}))),
            ("driver probe missing", dict(driver=None)),
            ("PHPUnit exit code non-zero", dict(rc="2", log="FAILURES!\nTests: 11\n")),
            ("PHPUnit exit-code record missing", dict(rc=None)),
            ("no terminal PHPUnit summary (premature exit rc=0)", dict(log="partial output only\n")),
            ("PHPUnit version not recorded", dict(version=None)),
        ]
        for name, overrides in negatives:
            ns, _ = _case(tmp, name.replace(" ", "_").replace("(", "").replace(")", "").replace("=", ""), **overrides)
            res, failures = build_summary(ns)
            results.append((f"negative control: {name} fails closed", res["status"] == "FAIL" and bool(failures),
                            failures[0] if failures else "NO FAILURE RAISED"))

        # Rendering must not claim PASS for a failed run.
        ns, _ = _case(tmp, "render_fail", clover=None)
        res, _ = build_summary(ns)
        results.append(("failed run renders FAIL status", "**FAIL**" in render_markdown(res), ""))

    passed = 0
    for name, ok, detail in results:
        print(("PASS  " if ok else "FAIL  ") + name + ("" if ok else f" :: {detail}"))
        passed += bool(ok)
    total = len(results)
    print(f"authorization-coverage-guard self-test: {passed}/{total} checks passed")
    return 0 if passed == total else 1


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--test", action="store_true", help="run negative-control self-test")
    parser.add_argument("--clover")
    parser.add_argument("--target", default=TARGET)
    parser.add_argument("--source")
    parser.add_argument("--driver-json")
    parser.add_argument("--phpunit-version-file")
    parser.add_argument("--phpunit-rc-file")
    parser.add_argument("--phpunit-log")
    parser.add_argument("--out-dir")
    parser.add_argument("--commit", default="UNKNOWN", help="PR head SHA (or pushed SHA)")
    parser.add_argument("--event-sha", default="UNKNOWN", help="GITHUB_SHA of the workflow event")
    parser.add_argument("--run-id", default="UNKNOWN")
    parser.add_argument("--run-attempt", default="UNKNOWN")
    args = parser.parse_args(argv)
    if args.test:
        return run_self_test()
    required = ["clover", "driver_json", "phpunit_version_file", "phpunit_rc_file", "phpunit_log", "out_dir"]
    missing = [r for r in required if not getattr(args, r)]
    if missing:
        parser.error("missing required arguments: " + ", ".join("--" + m.replace("_", "-") for m in missing))
    return run_measurement(args)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

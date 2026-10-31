#!/usr/bin/env python3
"""Publish a bounded, non-secret PHP fatal signature from an isolated WP run.

Raw PHP errors can contain SQL values, request parameters, credentials, or OTPs.
Never copy arbitrary exception text or stack frames to the job log, summary, or
artifact. Known structural PHP errors are reported verbatim; unknown messages are
explicitly redacted. This helper is not a substitute for the failing command's exit
status, and a detected fatal returns nonzero when --check is specified.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import re
import sys
from typing import Iterable, Iterator

MAX_FINDINGS = 10
FATAL = re.compile(
    r"(?:PHP\s+)?(?:Fatal|Parse) error:|\bUncaught\b|"
    r"There has been a critical error|"
    r"Call to undefined function add_submenu_page\(\)", re.IGNORECASE
)
SYMBOL = r"[A-Za-z_][A-Za-z_0-9\\]*"
UNDEFINED_FUNCTION = re.compile(r"Call to undefined function (" + SYMBOL + r")\(\)")
UNDEFINED_METHOD = re.compile(r"Call to undefined method (" + SYMBOL + r")::([A-Za-z_][A-Za-z_0-9]*)\(\)")
MISSING_CLASS = re.compile(r"(?:Class|Interface|Trait) [\"']?(" + SYMBOL + r")[\"']? not found")
EXCEPTION = re.compile(r"\bUncaught\s+(Error|TypeError|ParseError|ArgumentCountError|RuntimeException):")
PLUGIN_FILE = re.compile(
    r"clinic-practice-management/(?P<path>(?:src/[A-Za-z0-9_./-]+|clinic-practice-management)\.php)"
    r"(?::(?P<colon>\d+)|\s+on line\s+(?P<line>\d+))"
)


def read_lines(paths: list[str]) -> Iterator[str]:
    # Scan the whole file so a fatal near its beginning cannot be missed. Only
    # the output is bounded; raw lines are never printed or archived by this tool.
    for path in paths:
        try:
            with open(path, encoding="utf-8", errors="replace") as stream:
                yield from stream
        except OSError:
            continue


def source_location(line: str, plugin_root: Path) -> str:
    match = PLUGIN_FILE.search(line)
    if not match:
        return ""
    rel = match.group("path")
    if ".." in Path(rel).parts or not (plugin_root / rel).is_file():
        return ""
    number = match.group("colon") or match.group("line")
    return f" in clinic-practice-management/{rel} on line {number}"


def source_symbols(plugin_root: Path) -> set[str]:
    # Only identifiers already present in checked-out source may be published;
    # this prevents exception text from turning dynamic values into CI evidence.
    symbols: set[str] = set()
    for source in (plugin_root / "src").rglob("*.php"):
        try:
            symbols.update(re.findall(r"\b[A-Za-z_][A-Za-z_0-9]*\b", source.read_text(encoding="utf-8")))
        except (OSError, UnicodeError):
            continue
    return symbols


def known(symbol: str, source_tokens: set[str]) -> bool:
    parts = symbol.split("\\")
    return bool(parts) and all(part and part in source_tokens for part in parts)


def signatures(lines: Iterable[str], plugin_root: Path) -> list[str]:
    findings: list[str] = []
    tokens = source_symbols(plugin_root)
    for line in lines:
        if not FATAL.search(line):
            continue
        loc = source_location(line, plugin_root)
        kind = EXCEPTION.search(line)
        label = kind.group(1) if kind else "fatal or critical error"
        function = UNDEFINED_FUNCTION.search(line)
        method = UNDEFINED_METHOD.search(line)
        missing = MISSING_CLASS.search(line)
        if function and known(function.group(1), tokens):
            report = "PHP Fatal error: Uncaught Error: Call to undefined function " + function.group(1) + "()" + loc
        elif method and known(method.group(1), tokens) and known(method.group(2), tokens):
            report = "PHP Fatal error: Uncaught Error: Call to undefined method " + method.group(1) + "::" + method.group(2) + "()" + loc
        elif missing and known(missing.group(1), tokens):
            report = "PHP Fatal error: Uncaught " + label + ": Class " + missing.group(1) + " not found" + loc
        else:
            report = f"PHP {label}{loc}; exception message and stack redacted"
        if report not in findings:
            findings.append(report)
            if len(findings) >= MAX_FINDINGS:
                return findings
    return findings


def annotation_value(value: str) -> str:
    return value.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("paths", nargs="+", help="private raw logs; only sanitized signatures are printed")
    parser.add_argument("--check", action="store_true", help="return 1 when a fatal is detected")
    parser.add_argument("--summary", help="append sanitized findings to GitHub job summary")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    findings = signatures(read_lines(args.paths), root)
    if findings:
        for finding in findings:
            print("::error title=CPMS PHP fatal::" + annotation_value(finding))
        if args.summary:
            with open(args.summary, "a", encoding="utf-8") as summary:
                summary.write("### Sanitized PHP fatal diagnostic\n\n")
                for finding in findings:
                    summary.write(f"- `{finding}`\n")
                summary.write("\nArbitrary exception messages, raw logs and stack arguments are withheld.\n")
    else:
        print("PHP fatal diagnostic: no recognizable fatal in the available logs")
    return 1 if args.check and findings else 0


if __name__ == "__main__":
    sys.exit(main())

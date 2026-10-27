#!/usr/bin/env python3
"""Isolation decision for the disposable-demo companion lane.

Why this exists
---------------
The companion (`tools/disposable-demo-installer/**`, built by
`bin/build-demo-installer.sh`) is a *disposable demo* tool that installs,
provisions and tears down fixtures inside a real WordPress site. The
acceptance lane that proves it must never be the reason a change to the
production plugin ships unreviewed, and a change to the companion must never
be smuggled in together with production code that the lane then exercises.

The rule is therefore fail-closed **per change set**, not per workflow run:

  PRODUCTION changed, COMPANION unchanged   -> PASS   (production-only work)
  PRODUCTION unchanged                      -> PASS   (nothing to isolate)
  neither changed (workflow/docs only)      -> PASS   (lane plumbing)
  PRODUCTION changed **and** COMPANION      -> FAIL   (mixed change)
  changed both, or an unreadable diff       -> FAIL   (fail closed)

A mixed change is refused because the lane would otherwise validate newly
modified production code with newly modified companion code at the same time
and report a single green result for a change nobody reviewed as a pair.

Workflow-only edits stay allowed: the lane's own contract and security checks
(`.github/workflows/test_workflow_contracts.py` plus the companion unit suite)
still run in the lane's static job, so changing this workflow cannot silently
disable its own controls.

Usage
-----
    git diff --name-only BASE HEAD | python3 .github/scripts/companion-isolation.py

Exit codes: 0 = isolation holds, 1 = mixed change (fail closed),
2 = usage/IO error (fail closed).
"""

from __future__ import annotations

import argparse
import sys
from typing import Iterable, Sequence

COMPANION_PREFIXES: tuple[str, ...] = (
    "tools/disposable-demo-installer/",
    "bin/build-demo-installer.sh",
)

PRODUCTION_PREFIXES: tuple[str, ...] = (
    "clinic-practice-management/",
)

# Lane plumbing. Changing it is allowed, but never as a way to touch either
# side of the boundary above; the static job still enforces the contracts.
WORKFLOW_PREFIXES: tuple[str, ...] = (
    ".github/workflows/disposable-demo-installer.yml",
    ".github/scripts/companion-isolation.py",
)

PASS = "PASS"
FAIL = "FAIL"


def _matches(path: str, prefixes: Sequence[str]) -> bool:
    return any(path == prefix or path.startswith(prefix) for prefix in prefixes)


def classify(paths: Iterable[str]) -> dict[str, list[str]]:
    """Split changed paths into companion / production / workflow-only."""

    companion: list[str] = []
    production: list[str] = []
    workflow: list[str] = []

    for raw in paths:
        path = raw.strip()
        if not path:
            continue
        if _matches(path, COMPANION_PREFIXES):
            companion.append(path)
        elif _matches(path, PRODUCTION_PREFIXES):
            production.append(path)
        elif _matches(path, WORKFLOW_PREFIXES):
            workflow.append(path)
        # Anything else (docs, other workflows, tests) is out of scope for the
        # isolation rule and is deliberately ignored rather than classified.

    return {"companion": companion, "production": production, "workflow": workflow}


def decide(buckets: dict[str, list[str]]) -> tuple[str, str]:
    """Return (verdict, human readable reason)."""

    companion = buckets["companion"]
    production = buckets["production"]

    if not production:
        return PASS, "production subtree unchanged by this change set"

    if companion:
        return (
            FAIL,
            "mixed change: {0} companion file(s) and {1} production file(s) "
            "changed together; split them into separate pull requests".format(
                len(companion), len(production)
            ),
        )

    return PASS, "production-only change ({0} file(s)); companion untouched".format(len(production))


def render_verdict(verdict: str, reason: str, buckets: dict[str, list[str]]) -> str:
    lines = [
        "companion-isolation: {0}".format(verdict),
        "reason: {0}".format(reason),
        "production files: {0}".format(len(buckets["production"])),
        "companion files: {0}".format(len(buckets["companion"])),
        "lane files: {0}".format(len(buckets["workflow"])),
    ]
    for group in ("production", "companion", "workflow"):
        for path in buckets[group][:20]:
            lines.append("  {0}: {1}".format(group, path))
        if len(buckets[group]) > 20:
            lines.append("  {0}: … {1} more".format(group, len(buckets[group]) - 20))
    return "\n".join(lines)


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--changed-files",
        metavar="PATH",
        help="file containing one changed path per line (default: stdin)",
    )
    args = parser.parse_args(argv)

    try:
        if args.changed_files:
            with open(args.changed_files, "r", encoding="utf-8") as handle:
                raw_paths = handle.readlines()
        else:
            raw_paths = sys.stdin.readlines()
    except OSError as error:  # fail closed: an unreadable diff proves nothing
        print("companion-isolation: FAIL (cannot read changed paths: {0})".format(error))
        return 2

    buckets = classify(raw_paths)
    verdict, reason = decide(buckets)
    print(render_verdict(verdict, reason, buckets))

    return 0 if verdict == PASS else 1


if __name__ == "__main__":
    sys.exit(main())

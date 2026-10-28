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
  PRODUCTION changed **and** COMPATIBILITY  -> FAIL   (mixed change)
  an unclassified path under tools/ or      -> FAIL   (fail closed: an
  .github/scripts/                                     unclassified lane file
                                                       could be either side)
  changed both, or an unreadable diff       -> FAIL   (fail closed)

Three lane surfaces are classified, not two:

  COMPANION       the demo plugin itself (`tools/disposable-demo-installer/**`,
                  `bin/build-demo-installer.sh`);
  COMPATIBILITY   the automation that *proves* the companion matches production
                  (`.github/scripts/companion-compat/**`, `tests/companion-compat/**`,
                  `tests/scoped-demo/**`, `tests/disposable-site/**`), plus the
                  lane workflows it drives;
  DOCUMENTATION   the scoped-demo design and evidence docs (`docs/scoped-demo/**`).

Compatibility automation is classified explicitly because it is executable
evidence about the same boundary: a change set that edits both production code
and the gate that judges it would be validated against a judge it also rewrote.

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
    ".github/workflows/companion-compat.yml",
    ".github/workflows/companion-sync.yml",
    ".github/workflows/scoped-demo.yml",
    ".github/scripts/companion-isolation.py",
    ".github/scripts/post-pr-evidence.sh",
)

# Executable evidence about the companion/production boundary. A change set may
# touch this surface on its own, but never together with production code: the
# gate would then be judging new production code with a rewritten gate.
COMPATIBILITY_PREFIXES: tuple[str, ...] = (
    ".github/scripts/companion-compat/",
    "tests/companion-compat/",
    "tests/scoped-demo/",
    "tests/disposable-site/",
)

DOCUMENTATION_PREFIXES: tuple[str, ...] = (
    "docs/scoped-demo/",
)

# Directories that only ever contain lane code. An unclassified path here means
# a new file was added to a lane directory without being classified above, which
# is exactly the case that must not be guessed at.
LANE_SENSITIVE_PREFIXES: tuple[str, ...] = (
    "tools/",
    ".github/scripts/",
)

PASS = "PASS"
FAIL = "FAIL"


def _matches(path: str, prefixes: Sequence[str]) -> bool:
    return any(path == prefix or path.startswith(prefix) for prefix in prefixes)


def classify(paths: Iterable[str]) -> dict[str, list[str]]:
    """Split changed paths into companion / compatibility / production / lane / docs.

    Order matters: COMPANION is matched before COMPATIBILITY because
    `tools/disposable-demo-installer/**` is the companion side of the boundary.
    Anything left over is reported as `other` (never silently dropped) so a
    reader can see what the decision did not look at.
    """

    companion: list[str] = []
    compatibility: list[str] = []
    production: list[str] = []
    workflow: list[str] = []
    documentation: list[str] = []
    other: list[str] = []

    for raw in paths:
        path = raw.strip()
        if not path:
            continue
        if _matches(path, COMPANION_PREFIXES):
            companion.append(path)
        elif _matches(path, COMPATIBILITY_PREFIXES):
            compatibility.append(path)
        elif _matches(path, PRODUCTION_PREFIXES):
            production.append(path)
        elif _matches(path, WORKFLOW_PREFIXES):
            workflow.append(path)
        elif _matches(path, DOCUMENTATION_PREFIXES):
            documentation.append(path)
        else:
            other.append(path)

    return {
        "companion": companion,
        "compatibility": compatibility,
        "production": production,
        "workflow": workflow,
        "documentation": documentation,
        "other": other,
    }


def decide(buckets: dict[str, list[str]]) -> tuple[str, str]:
    """Return (verdict, human readable reason)."""

    companion = buckets["companion"]
    compatibility = buckets["compatibility"]
    production = buckets["production"]
    unclassified = [path for path in buckets.get("other", []) if _matches(path, LANE_SENSITIVE_PREFIXES)]

    if not production:
        if unclassified:
            return (
                FAIL,
                "unclassified lane file(s) in a change set that does not touch production: {0}; "
                "classify them in companion-isolation.py before landing".format(
                    ", ".join(unclassified[:3])
                ),
            )
        return PASS, "production subtree unchanged by this change set"

    if companion:
        return (
            FAIL,
            "mixed change: {0} companion file(s) and {1} production file(s) "
            "changed together; split them into separate pull requests".format(
                len(companion), len(production)
            ),
        )

    if compatibility:
        return (
            FAIL,
            "mixed change: {0} compatibility-automation file(s) and {1} production file(s) "
            "changed together; the gate would judge new production code with a rewritten "
            "gate".format(len(compatibility), len(production)),
        )

    if unclassified:
        return (
            FAIL,
            "unclassified lane file(s) changed together with production code: {0}".format(
                ", ".join(unclassified[:3])
            ),
        )

    return PASS, "production-only change ({0} file(s)); companion untouched".format(len(production))


def render_verdict(verdict: str, reason: str, buckets: dict[str, list[str]]) -> str:
    lines = [
        "companion-isolation: {0}".format(verdict),
        "reason: {0}".format(reason),
        "production files: {0}".format(len(buckets["production"])),
        "companion files: {0}".format(len(buckets["companion"])),
        "compatibility files: {0}".format(len(buckets.get("compatibility", []))),
        "lane files: {0}".format(len(buckets["workflow"])),
        "documentation files: {0}".format(len(buckets.get("documentation", []))),
        "other files: {0}".format(len(buckets.get("other", []))),
    ]
    for group in ("production", "companion", "compatibility", "workflow", "documentation"):
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

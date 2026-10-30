#!/usr/bin/env python3
"""Prove the standalone demo ZIP carries the production core of THIS revision.

WHY THIS EXISTS
---------------
The standalone demo is the Owner's private evaluation environment for CPMS
Classic V1. It must always show the *production* product: the same application
code, the same interfaces, the same authorization, the same scheduling, finance,
messaging and portals. There is therefore exactly one source of truth
(`clinic-practice-management/`) and exactly one copy path (`bin/build-demo-installer.sh`
assembles it into `core/` at build time). Nothing is copied into the repository
by hand.

That design removes the drift a duplicated implementation would create, but it
does not remove the *staleness* question: is the archive in my hand really built
from the production tree at this revision, or from something older, partial or
edited? This verifier answers that by enumeration, not by trust:

  * it derives the expected `core/` inventory from the production tree using the
    build's documented inclusion and exclusion rules,
  * it compares every file byte-for-byte against the archive,
  * it allows exactly one transformation - the single WordPress-discoverable
    header key of the embedded bootstrap is renamed so WordPress cannot offer the
    embedded core as a second plugin - and proves that no other byte differs.

Anything else is a failure: a missing file (incomplete/stale), an extra file
(something that is not production code), or a differing byte (edited or stale).

Exit codes: 0 in sync, 1 out of sync, 2 unusable invocation. The JSON summary on
stdout (`EMBEDDED_CORE_SYNC=...`) is the artifact a reviewer reads.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PRODUCTION = ROOT / "clinic-practice-management"
BUILD = ROOT / "bin" / "build-demo-installer.sh"
PACKAGE = "cpms-disposable-demo"
CORE = f"{PACKAGE}/core"
BOOTSTRAP = "clinic-practice-management.php"

# The only bytes the build is allowed to change. Keep this in step with the
# build script; if the build ever changes another byte, this verifier must fail
# rather than be taught to ignore it.
HEADER_KEY = re.compile(rb"(?m)^(?P<prefix>[ \t]*\*[ \t]*)Plugin Name:(?P<space>[ \t]*)")
NEUTRAL_KEY = b"CPMS Embedded Core:"

# What the build copies, in the order the build copies it.
COPIED_FILES = ("clinic-practice-management.php", "uninstall.php", "CHANGELOG.md")
COPIED_TREES = ("src", "templates", "assets")
VENDOR_TREE = "vendor"

# What the build prunes out of the copied tree afterwards.
PRUNED_DIRS = {"tests", ".git", "node_modules"}
PRUNED_FILE_SUFFIXES = (".dist",)
PRUNED_FILE_NAMES = {"phpunit.phar", "composer.json", "composer.lock"}
# Removed on purpose: only the demo's own uninstall.php may run, and a second
# handler inside `core/` would be dead code that looks live.
ALWAYS_REMOVED = {"uninstall.php"}


def is_pruned(relative: Path) -> bool:
    if any(part in PRUNED_DIRS for part in relative.parts):
        return True
    if relative.name in PRUNED_FILE_NAMES:
        return True
    return any(relative.name.endswith(suffix) for suffix in PRUNED_FILE_SUFFIXES)


def expected_core(source: Path) -> dict[str, Path]:
    """Every path the build should put inside `core/`, derived from the tree."""
    expected: dict[str, Path] = {}
    for name in COPIED_FILES:
        candidate = source / name
        if not candidate.is_file():
            raise SystemExit(f"production source is missing {name}: the demo cannot embed it")
        expected[name] = candidate
    trees = list(COPIED_TREES)
    if (source / VENDOR_TREE).is_dir():
        trees.append(VENDOR_TREE)
    for tree in trees:
        base = source / tree
        if not base.is_dir():
            raise SystemExit(f"production source is missing the {tree}/ tree: the demo cannot embed it")
        for path in sorted(base.rglob("*")):
            if not path.is_file():
                continue
            relative = path.relative_to(source)
            if is_pruned(relative):
                continue
            expected[relative.as_posix()] = path
    for removed in ALWAYS_REMOVED:
        expected.pop(removed, None)
    return expected


def neutralize(source: bytes) -> tuple[bytes, bool]:
    """The staged bootstrap: one header key renamed, every other byte identical."""
    matches = list(HEADER_KEY.finditer(source[:8192]))
    if len(matches) != 1:
        raise SystemExit("the production bootstrap must contain exactly one Plugin Name header")
    match = matches[0]
    staged = source[: match.start()] + match.group("prefix") + NEUTRAL_KEY + match.group("space") + source[match.end():]
    return staged, staged != source


def archive_core(zip_path: Path) -> dict[str, bytes]:
    with zipfile.ZipFile(zip_path) as archive:
        corrupt = archive.testzip()
        if corrupt is not None:
            raise SystemExit(f"the archive is corrupt at {corrupt}")
        return {
            name[len(CORE) + 1:]: archive.read(name)
            for name in archive.namelist()
            if name.startswith(CORE + "/") and not name.endswith("/")
        }


def digest(files: dict[str, bytes]) -> str:
    accumulator = hashlib.sha256()
    for name in sorted(files):
        accumulator.update(name.encode("utf-8"))
        accumulator.update(b"\0")
        accumulator.update(hashlib.sha256(files[name]).hexdigest().encode("ascii"))
        accumulator.update(b"\n")
    return accumulator.hexdigest()


def git(*args: str) -> str:
    result = subprocess.run(["git", "-C", str(ROOT), *args], capture_output=True, text=True, check=False)
    return result.stdout.strip() if result.returncode == 0 else ""


def verify(zip_path: Path, source: Path) -> dict[str, object]:
    expected = expected_core(source)
    staged: dict[str, bytes] = {}
    for relative, path in expected.items():
        raw = path.read_bytes()
        if relative == BOOTSTRAP:
            raw, changed = neutralize(raw)
            if not changed:
                raise SystemExit("header neutralization produced identical bytes")
        staged[relative] = raw
    actual = archive_core(zip_path)

    missing = sorted(set(staged) - set(actual))
    extra = sorted(set(actual) - set(staged))
    different = sorted(name for name in sorted(set(staged) & set(actual)) if staged[name] != actual[name])

    # A second, independent statement of the same fact: the archive must not
    # contain the production source verbatim anywhere outside `core/`.
    with zipfile.ZipFile(zip_path) as archive:
        names = set(archive.namelist())
    leaked_paths = sorted(
        name for name in names
        if name.startswith("clinic-practice-management/") or "/src/" in name and f"{CORE}/" not in name
    )

    dirty = bool(git("status", "--porcelain", "--", "clinic-practice-management"))

    summary: dict[str, object] = {
        "zip": str(zip_path),
        "zip_bytes": zip_path.stat().st_size,
        "zip_sha256": hashlib.sha256(zip_path.read_bytes()).hexdigest(),
        "production_revision": git("rev-parse", "HEAD") or "unknown",
        "production_tree_dirty": dirty,
        "files_expected": len(staged),
        "files_in_archive": len(actual),
        "missing": missing,
        "extra": extra,
        "different": different,
        "leaked_production_paths": leaked_paths,
        "embedded_core_digest": digest(actual),
        "source_core_digest": digest(staged),
        "ok": not (missing or extra or different or leaked_paths),
    }
    return summary


def build(out_dir: Path) -> Path:
    environment = {**os.environ, "OUT_DIR": str(out_dir)}
    result = subprocess.run(
        ["bash", str(BUILD), "--standalone"],
        cwd=str(ROOT), env=environment, capture_output=True, text=True, check=False,
    )
    print(result.stdout, end="")
    if result.returncode != 0:
        sys.stderr.write(result.stderr)
        raise SystemExit("the standalone build failed, so there is nothing to verify")
    version = ""
    for line in result.stdout.splitlines():
        if line.startswith("version="):
            version = line.split("=", 1)[1].strip()
    archives = sorted(out_dir.glob(f"{PACKAGE}-*.zip"))
    if not archives:
        raise SystemExit("the build produced no standalone archive")
    return archives[-1]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--zip", help="verify this archive instead of building one")
    parser.add_argument("--json", help="also write the summary to this path")
    parser.add_argument("--source", default=str(PRODUCTION), help="production source tree")
    arguments = parser.parse_args()

    source = Path(arguments.source).resolve()
    if not source.is_dir():
        raise SystemExit(f"no production source at {source}")
    if arguments.zip:
        zip_path = Path(arguments.zip).resolve()
        if not zip_path.is_file():
            raise SystemExit(f"no archive at {zip_path}")
    else:
        import tempfile

        zip_path = build(Path(tempfile.mkdtemp(prefix="cpsd-sync-")))

    summary = verify(zip_path, source)
    encoded = json.dumps(summary, ensure_ascii=False, sort_keys=True)
    print("EMBEDDED_CORE_SYNC=" + encoded)
    if arguments.json:
        Path(arguments.json).write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    if not summary["ok"]:
        for key in ("missing", "extra", "different", "leaked_production_paths"):
            if summary[key]:
                print(f"::error title=EMBEDDED_CORE_STALE::{key}: {summary[key][:10]}", file=sys.stderr)
        print(
            "FAIL: the embedded core is not the production tree at this revision "
            f"({len(summary['missing'])} missing, {len(summary['extra'])} extra, "
            f"{len(summary['different'])} differing)",
            file=sys.stderr,
        )
        return 1
    print(
        f"EMBEDDED_CORE_OK files={summary['files_expected']} "
        f"revision={summary['production_revision']} digest={summary['embedded_core_digest']}",
        flush=True,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

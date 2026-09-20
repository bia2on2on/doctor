#!/usr/bin/env python3
"""
WPCS changed-lines collector (CI infrastructure).

Usage:
    python3 bin/wpcs-changed-lines.py --baseline <sha> [--files-out PATH] [--added-out PATH]
    python3 bin/wpcs-changed-lines.py --test

Exit codes:
    0 — collection completed and is provably consistent (including the
        legitimate "nothing in scope" / "no added lines" outcomes)
    1 — collector failed closed (it could not prove what it inspected)
    2 — configuration error (missing baseline, unknown argument)

Contract
--------
Run with the CI job's working directory (the `phpcs` invocation domain — in
`.github/workflows/ci.yml` that is `clinic-practice-management/`), this script
writes the two artifacts the WPCS job consumes:

  --files-out  (default /tmp/wpcs-files.txt)
      changed in-scope PHP files since `--baseline`, expressed **relative to
      the current working directory** — consumed by `vendor/bin/phpcs <files>`.

  --added-out  (default /tmp/wpcs-added.txt)
      `<path>:<line>` for every line ADDED since `--baseline`, in that same
      path domain — consumed by the added-lines filter that intersects phpcs
      violations with the changed lines.

Why this exists (WPCS class-D test-infrastructure defect)
--------------------------------------------------------
`git diff` resolves pathspecs relative to the *current working directory*,
while `git diff --name-only ... -- .` prints paths relative to the
*repository top level*. The previous inline shell collector mixed the two path
domains: it stripped the `clinic-practice-management/` prefix for phpcs and
then re-added that prefix while still running inside `clinic-practice-management/`,
so git looked for `clinic-practice-management/clinic-practice-management/...`,
matched nothing, and — because a non-matching pathspec is not an error for
`git diff` (rc=0, no output) and the shell pipeline's status came from `awk` —
contributed nothing. The added-lines filter then intersected phpcs violations
with an empty set, so any number of added violations passed: **silent PASS**.

This collector runs every git command at the repository top level against
repo-root-relative paths, maps the two domains explicitly and exactly once,
and never lets a collection failure look like "nothing to check":

  * the baseline must resolve to a commit and the discovery diff must exit 0
    (a missing/shallow baseline used to become an empty -> green no-op);
  * every file the discovery step reports must actually be inspected: the
    per-file diff must exit 0 and must contain that file's own diff header,
    so an unresolved pathspec cannot silently contribute zero lines;
  * the parsed `@@` ranges must agree with `git diff --numstat`'s
    independently measured added-line count for that file.

An empty added-lines set is therefore only produced when it is *proven*: every
inspected file either contributed no added line at all (deletion-only /
metadata-only / rename-only changes) or contributed exactly what numstat
declares. A collector failure exits 1 with an `::error::` annotation and can
never be reported as a green no-op.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, List, Optional, Sequence

EXIT_OK = 0
EXIT_COLLECTOR_FAILURE = 1
EXIT_CONFIG_ERROR = 2

DEFAULT_FILES_OUT = "/tmp/wpcs-files.txt"
DEFAULT_ADDED_OUT = "/tmp/wpcs-added.txt"

# In-scope file filter — WPCS policy unchanged: PHP files outside vendor/,
# tests/ and node_modules/.
PHP_RE = re.compile(r"\.php$")
EXCLUDED_DIR_RE = re.compile(r"(^|/)(vendor|tests|node_modules)/")

# `git diff -U0` hunk header: the new-side range is exactly the added lines
# (unified format with zero context lines).
HUNK_RE = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@")

DIFF_HEADER_PREFIX = "diff --git "

GIT_QUOTE_SAFE = ["-c", "core.quotePath=false"]


class CollectorError(RuntimeError):
    """Raised whenever the collector cannot prove what it inspected."""


def git(args: Sequence[str], cwd: Optional[str] = None, check: bool = True,
        env: Optional[dict] = None) -> subprocess.CompletedProcess:
    """Run git with a stable configuration and capture its output."""
    cmd = ["git", *GIT_QUOTE_SAFE, *args]
    proc = subprocess.run(
        cmd, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    if check and proc.returncode != 0:
        detail = (proc.stderr or proc.stdout).strip().splitlines()
        detail = detail[0][:300] if detail else ""
        raise CollectorError(f"`git {' '.join(args)}` failed (rc={proc.returncode}) {detail}".strip())
    return proc


def diff_header_b_paths(diff_text: str) -> List[str]:
    """New-side ('b/') paths of every `diff --git` header in a diff."""
    paths: List[str] = []
    for line in diff_text.splitlines():
        if not line.startswith(DIFF_HEADER_PREFIX):
            continue
        rest = line[len(DIFF_HEADER_PREFIX):].rstrip()
        if rest.endswith('"'):
            idx = rest.rfind('"b/')
            if idx == -1:
                raise CollectorError(f"cannot parse quoted diff header: {line!r}")
            paths.append(rest[idx + 3:-1])
            continue
        idx = rest.rfind(" b/")
        if idx == -1:
            raise CollectorError(f"cannot parse diff header: {line!r}")
        paths.append(rest[idx + 3:])
    return paths


def parse_added_lines(diff_text: str) -> List[int]:
    """New-side line numbers added by a `git diff -U0` (hunk ranges only)."""
    lines: List[int] = []
    for line in diff_text.splitlines():
        m = HUNK_RE.match(line)
        if not m:
            continue
        start = int(m.group(1))
        length = 1 if m.group(2) is None else int(m.group(2))
        lines.extend(range(start, start + length))
    return lines


def numstat_added(root: str, baseline: str, repo_path: str) -> Optional[int]:
    """Independently measured added-line count for one file (None = binary)."""
    proc = git(["diff", "--numstat", "--diff-filter=ACMRT", f"{baseline}...HEAD",
                "--", repo_path], cwd=root)
    rows = [row for row in proc.stdout.splitlines() if row.strip()]
    if len(rows) != 1:
        raise CollectorError(
            f"expected exactly one --numstat row for {repo_path!r}, got {len(rows)}"
        )
    added = rows[0].split("\t")[0]
    if added == "-":
        return None
    try:
        return int(added)
    except ValueError as exc:  # pragma: no cover - defensive
        raise CollectorError(f"unparsable --numstat added count {added!r}") from exc


def verify_inspection_consistency(repo_path: str, declared_added: Optional[int],
                                 parsed_lines: Sequence[int]) -> None:
    """
    The collector's fail-closed invariant.

    `git --numstat` measures the added lines independently of the `@@` hunk
    ranges, so the two must agree. This is what distinguishes

      * "no added PHP lines legitimately exist" (numstat 0, parsed 0) — e.g. a
        deletion-only change, which must NOT fail, from
      * "the collector failed to inspect the file/pathspec" (numstat > 0 but
        parsed 0, or any other disagreement), which is a hard failure.

    A naive `test -s /tmp/wpcs-added.txt` would conflate the two and break
    every deletion-only PHP change.
    """
    if declared_added is None:
        return
    if declared_added != len(parsed_lines):
        raise CollectorError(
            f"added-line collection is inconsistent for {repo_path!r}: "
            f"`git diff --numstat` reports {declared_added} added line(s) but the "
            f"parsed -U0 hunk ranges yielded {len(parsed_lines)} — the collector "
            f"did not see the changed file's added lines"
        )


def verify_inspection_output(repo_path: str, diff_text: str) -> List[int]:
    """
    Fail-closed guard for one inspected file.

    `git diff` reports a non-matching pathspec as success with no output, so an
    empty diff for a file the discovery step listed means the pathspec did not
    resolve to that file — never "no added lines".
    """
    if not diff_text.strip():
        raise CollectorError(
            f"{repo_path!r} is listed as changed by the discovery diff but its "
            f"per-file diff is empty — the pathspec did not resolve to the file"
        )
    b_paths = diff_header_b_paths(diff_text)
    if repo_path not in b_paths:
        raise CollectorError(
            f"per-file diff header {b_paths!r} does not name the inspected file "
            f"{repo_path!r} — refusing to attribute another path's lines to it"
        )
    return parse_added_lines(diff_text)


@dataclass
class FileInspection:
    repo_path: str          # relative to the repository top level
    phpcs_path: str         # relative to the job working directory (phpcs domain)
    numstat_added: Optional[int] = None
    added_lines: List[int] = field(default_factory=list)


@dataclass
class Collected:
    repo_root: str
    prefix: str
    inspections: List[FileInspection] = field(default_factory=list)
    added_keys: List[str] = field(default_factory=list)

    @property
    def phpcs_paths(self) -> List[str]:
        return [insp.phpcs_path for insp in self.inspections]

    @property
    def declared_added_total(self) -> int:
        return sum(insp.numstat_added or 0 for insp in self.inspections)


def repo_root_of(cwd: str) -> str:
    return git(["rev-parse", "--show-toplevel"], cwd=cwd).stdout.strip()


def cwd_prefix_of(cwd: str) -> str:
    """Path from the repository top level to `cwd` — '' or 'dir/' (git form)."""
    return git(["rev-parse", "--show-prefix"], cwd=cwd).stdout.strip()


def collect(baseline: str, files_out: str, added_out: str,
            cwd: Optional[str] = None, log: Callable[..., None] = print) -> Collected:
    """Collect the two WPCS artifacts, or raise CollectorError (fail closed)."""
    cwd = cwd or os.getcwd()
    root = repo_root_of(cwd)
    prefix = cwd_prefix_of(cwd)

    if git(["cat-file", "-e", f"{baseline}^{{commit}}"], cwd=root, check=False).returncode != 0:
        raise CollectorError(
            f"baseline {baseline!r} is not a commit in this repository — refusing to "
            f"treat an unresolvable baseline as 'nothing changed'"
        )

    # Discovery — always at the top level, so the printed paths are
    # repo-root-relative no matter which directory the job runs in.
    discovery = git(["diff", "--name-only", "--diff-filter=ACMRT",
                     f"{baseline}...HEAD", "--", "."], cwd=root)
    repo_paths = [
        line for line in discovery.stdout.splitlines()
        if PHP_RE.search(line) and not EXCLUDED_DIR_RE.search(line)
    ]

    result = Collected(repo_root=root, prefix=prefix)
    for repo_path in repo_paths:
        if prefix:
            if not repo_path.startswith(prefix):
                raise CollectorError(
                    f"changed PHP file {repo_path!r} lies outside the job working "
                    f"directory ({prefix!r}); it cannot be inspected in the phpcs path "
                    f"domain — refusing to silently skip it (extend the WPCS scope "
                    f"explicitly instead)"
                )
            phpcs_path = repo_path[len(prefix):]
        else:
            phpcs_path = repo_path
        if not phpcs_path:
            raise CollectorError(f"cannot map {repo_path!r} into the phpcs path domain")
        result.inspections.append(FileInspection(repo_path=repo_path, phpcs_path=phpcs_path))

    for insp in result.inspections:
        proc = git(["diff", "-U0", "--diff-filter=ACMRT", f"{baseline}...HEAD",
                    "--", insp.repo_path], cwd=root)
        insp.added_lines = verify_inspection_output(insp.repo_path, proc.stdout)
        insp.numstat_added = numstat_added(root, baseline, insp.repo_path)
        verify_inspection_consistency(insp.repo_path, insp.numstat_added, insp.added_lines)
        result.added_keys.extend(f"{insp.phpcs_path}:{line}" for line in insp.added_lines)

    result.added_keys = sorted(set(result.added_keys))

    # Final guard: numstat says added lines exist, so the collected set must not
    # be empty. (Deletion-only changes make declared_added_total == 0 and are
    # therefore legitimately allowed to produce an empty set.)
    if result.inspections and result.declared_added_total > 0 and not result.added_keys:
        raise CollectorError(
            f"{len(result.inspections)} changed PHP file(s) with "
            f"{result.declared_added_total} added line(s) per --numstat produced an empty "
            f"added-line set"
        )

    _write_lines(files_out, result.phpcs_paths)
    _write_lines(added_out, result.added_keys)

    if not result.inspections:
        log("WPCS: no in-scope PHP files changed since baseline — nothing to check.")
        return result

    log(f"WPCS will check {len(result.inspections)} changed file(s):")
    for insp in result.inspections:
        log(f"  {insp.phpcs_path}")
    log(f"Added lines in scope: {len(result.added_keys)} "
        f"(across {len(result.inspections)} file(s); "
        f"{result.declared_added_total} added line(s) declared by --numstat)")
    if not result.added_keys:
        log("WPCS: no added lines — legitimate for deletion-only/metadata-only changes "
            "(every file was inspected and --numstat declares 0 added lines). "
            "This is not a collector failure.")
    for insp in result.inspections:
        log(f"  inspected {insp.repo_path}: numstat_added={insp.numstat_added} "
            f"collected={len(insp.added_lines)}")
    return result


def _write_lines(path: str, lines: Sequence[str]) -> None:
    with open(path, "w", encoding="utf-8") as handle:
        for line in lines:
            handle.write(f"{line}\n")


# ---------------------------------------------------------------------------
# Deterministic regression proof — synthetic repositories, no network, no PHP.
# ---------------------------------------------------------------------------

SELFTEST_ENV = {
    "GIT_AUTHOR_NAME": "wpcs-collector-selftest",
    "GIT_AUTHOR_EMAIL": "wpcs-collector-selftest@example.invalid",
    "GIT_COMMITTER_NAME": "wpcs-collector-selftest",
    "GIT_COMMITTER_EMAIL": "wpcs-collector-selftest@example.invalid",
    "GIT_AUTHOR_DATE": "2001-02-03T04:05:06+00:00",
    "GIT_COMMITTER_DATE": "2001-02-03T04:05:06+00:00",
    "GIT_CONFIG_GLOBAL": os.devnull,
    "GIT_CONFIG_SYSTEM": os.devnull,
}

# Added line that WordPress-Core would flag (space indentation instead of tabs)
# — the fixture's "added violating line".
VIOLATING_LINE = "  $violation = 1;"


class SelfTestFailure(AssertionError):
    pass


def _check(condition: bool, message: str) -> None:
    if not condition:
        raise SelfTestFailure(message)


def _fixture_git(cwd: Path, args: Sequence[str]) -> subprocess.CompletedProcess:
    env = dict(os.environ)
    env.update(SELFTEST_ENV)
    return subprocess.run(["git", *args], cwd=str(cwd), env=env,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


def _fixture_init(base: Path, name: str) -> Path:
    repo = base / name
    (repo / "plugin" / "src").mkdir(parents=True, exist_ok=True)
    proc = _fixture_git(repo, ["-c", "init.defaultBranch=main", "init", "-q"])
    _check(proc.returncode == 0, f"fixture git init failed: {proc.stderr.strip()}")
    return repo


def _fixture_write(repo: Path, rel: str, text: str) -> None:
    target = repo / rel
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text, encoding="utf-8")


def _fixture_commit(repo: Path, message: str) -> str:
    for args in (["add", "-A"], ["-c", "commit.gpgsign=false", "commit", "-q", "-m", message]):
        proc = _fixture_git(repo, args)
        _check(proc.returncode == 0, f"fixture git {args[0]} failed: {proc.stderr.strip()}")
    return _fixture_git(repo, ["rev-parse", "HEAD"]).stdout.strip()


def _read_lines(path: Path) -> List[str]:
    return path.read_text(encoding="utf-8").split()


def _silent(*_args, **_kwargs) -> None:
    """Swallow collector logging during assertions."""


def test_added_violating_line_is_collected(base: Path) -> None:
    """
    Defect reproduction + corrected path handling.

    A changed PHP file carrying an added violating line must (a) be present in
    the diff the collector inspects, (b) yield non-empty added-line metadata
    under the corrected path handling, and (c) reproduce the historical defect
    (empty collection) when the removed ci.yml pathspec form is executed.
    """
    repo = _fixture_init(base, "added-line")
    _fixture_write(repo, "plugin/src/Thing.php", "<?php\n\n$x = 1;\n")
    baseline = _fixture_commit(repo, "baseline")

    _fixture_write(repo, "plugin/src/Thing.php",
                   f"<?php\n\n$x = 1;\n{VIOLATING_LINE}\n")
    _fixture_commit(repo, "add violating line")

    files_out, added_out = base / "added-line-files.txt", base / "added-line-added.txt"
    result = collect(baseline, str(files_out), str(added_out),
                     cwd=str(repo / "plugin"), log=_silent)

    _check(_read_lines(files_out) == ["src/Thing.php"],
           f"phpcs-domain file list is not the plugin-relative changed file: {_read_lines(files_out)}")
    _check(len(result.inspections) == 1, "collector did not inspect exactly one changed file")

    inspected = result.inspections[0]
    _check(inspected.repo_path == "plugin/src/Thing.php",
           f"collector inspected {inspected.repo_path!r} instead of the changed file")
    _check(inspected.numstat_added == 1,
           f"--numstat reported {inspected.numstat_added} added lines, expected 1")

    on_disk = (repo / "plugin/src/Thing.php").read_text(encoding="utf-8").splitlines()
    violating_line_no = on_disk.index(VIOLATING_LINE) + 1    # 0-based index -> line number
    _check(inspected.added_lines == [violating_line_no],
           f"collected added lines {inspected.added_lines} != the added violating "
           f"line {violating_line_no}")

    # The inspected diff is the one that carries the added violating line.
    raw = git(["diff", "-U0", "--diff-filter=ACMRT", f"{baseline}...HEAD",
               "--", "plugin/src/Thing.php"], cwd=str(repo)).stdout
    _check(f"+{VIOLATING_LINE}" in raw,
           "the inspected diff does not contain the added violating line")

    keys = _read_lines(added_out)
    _check(keys == [f"src/Thing.php:{violating_line_no}"],
           f"added-line metadata {keys} does not address the added violating line "
           f"src/Thing.php:{violating_line_no}")

    # Historical form, executed verbatim: cwd = the job working directory and the
    # plugin prefix re-applied (`.github/workflows/ci.yml` before this fix).
    legacy = _fixture_git(repo / "plugin", ["diff", "-U0", "--diff-filter=ACMRT",
                                            f"{baseline}...HEAD", "--",
                                            "plugin/src/Thing.php"])
    _check(legacy.returncode == 0,
           f"historical form unexpectedly failed (rc={legacy.returncode})")
    _check(legacy.stdout.strip() == "",
           "historical form produced output — the defect was not reproduced")
    _check(parse_added_lines(legacy.stdout) == [],
           "historical form produced added lines — the defect was not reproduced")

    # Same collector, invoked from the repository root: identical line numbers,
    # path domain adapted to the new working directory (no CWD trap either way).
    root_result = collect(baseline, str(base / "added-line-files-root.txt"),
                          str(base / "added-line-added-root.txt"), cwd=str(repo), log=_silent)
    _check(root_result.added_keys == [f"plugin/src/Thing.php:{violating_line_no}"],
           f"root-invocation added keys {root_result.added_keys} are not the repo-relative form")


def test_deletion_only_change_does_not_fail(base: Path) -> None:
    """
    Deletion-only / no-added-line PHP changes must not be treated as a
    standards failure: the added-line set is legitimately empty, yet every file
    was really inspected.
    """
    repo = _fixture_init(base, "deletion-only")
    _fixture_write(repo, "plugin/src/Deleted.php", "<?php\n$a = 1;\n$b = 2;\n$c = 3;\n")
    baseline = _fixture_commit(repo, "baseline")
    _fixture_write(repo, "plugin/src/Deleted.php", "<?php\n")     # deletions only
    _fixture_commit(repo, "delete lines only")

    files_out, added_out = base / "del-files.txt", base / "del-added.txt"
    result = collect(baseline, str(files_out), str(added_out),
                     cwd=str(repo / "plugin"), log=_silent)

    _check(_read_lines(files_out) == ["src/Deleted.php"],
           f"deletion-only file missing from the inspected scope: {_read_lines(files_out)}")
    _check(result.inspections[0].numstat_added == 0,
           "fixture is not deletion-only (numstat reports added lines)")
    _check(result.inspections[0].added_lines == [],
           "deletion-only file produced added lines")
    _check(_read_lines(added_out) == [],
           f"added-line metadata is not empty for a deletion-only change: {_read_lines(added_out)}")
    _check(result.inspections and result.declared_added_total == 0,
           "collector declared added lines for a deletion-only change")

    # Mixed change set: the deletion-only file stays green while a sibling file
    # with genuine additions still contributes exactly its own added lines.
    _fixture_write(repo, "plugin/src/Deleted.php", "<?php\n$a = 1;\n")
    _fixture_write(repo, "plugin/src/AddedToo.php", "<?php\n\n$added = 1;\n")
    baseline2 = _fixture_commit(repo, "mixed baseline: deletion-only + sibling additions")
    _fixture_write(repo, "plugin/src/Deleted.php", "<?php\n")           # deletions only
    _fixture_write(repo, "plugin/src/AddedToo.php", "<?php\n\n$added = 1;\n$more = 2;\n")
    _fixture_commit(repo, "mixed: deletion-only + one added line")

    mixed = collect(baseline2, str(base / "mix-files.txt"), str(base / "mix-added.txt"),
                    cwd=str(repo / "plugin"), log=_silent)
    by_path = {insp.repo_path: insp for insp in mixed.inspections}
    _check(set(by_path) == {"plugin/src/AddedToo.php", "plugin/src/Deleted.php"},
           f"mixed change set scope wrong: {sorted(by_path)}")
    _check(by_path["plugin/src/Deleted.php"].added_lines == [],
           "deletion-only file contributed added lines in the mixed change set")
    _check(by_path["plugin/src/AddedToo.php"].numstat_added ==
           len(by_path["plugin/src/AddedToo.php"].added_lines) == 1,
           "sibling file's added lines were not collected exactly once")
    _check(all(key.startswith("src/AddedToo.php:") for key in mixed.added_keys),
           f"mixed added-line metadata leaked from the deletion-only file: {mixed.added_keys}")


def test_collector_failure_cannot_silently_pass(base: Path) -> None:
    """
    Every collector/path failure must exit non-zero: it can never degrade into
    the "nothing to check — green no-op" state.
    """
    repo = _fixture_init(base, "fail-closed")
    _fixture_write(repo, "plugin/src/Thing.php", "<?php\n\n$x = 1;\n")
    baseline = _fixture_commit(repo, "baseline")
    _fixture_write(repo, "plugin/src/Thing.php", "<?php\n\n$x = 1;\n$y = 2;\n")
    _fixture_commit(repo, "modify")

    # Positive control: the same fixture and baseline collect cleanly, so the
    # failures below are caused by the injected failure and not the fixture.
    rc0, _ = _run_cli(["--baseline", baseline,
                       "--files-out", str(base / "control-files.txt"),
                       "--added-out", str(base / "control-added.txt")],
                      cwd=str(repo / "plugin"))
    _check(rc0 == EXIT_OK, f"fixture positive control exited {rc0}")
    _check(_read_lines(base / "control-added.txt") == ["src/Thing.php:4"],
           f"fixture positive control collected {_read_lines(base / 'control-added.txt')}")

    # (a) unresolvable baseline (e.g. a shallow checkout) — previously the
    #     `|| true` discovery became an empty file list and a green no-op.
    rc, out = _run_cli(["--baseline", "0" * 40,
                        "--files-out", str(base / "bad-files.txt"),
                        "--added-out", str(base / "bad-added.txt")],
                       cwd=str(repo / "plugin"))
    _check(rc == EXIT_COLLECTOR_FAILURE,
           f"unresolvable baseline exited {rc}, expected {EXIT_COLLECTOR_FAILURE}")
    _check("::error::" in out, "unresolvable baseline did not emit a ::error:: annotation")

    # (b) the fail-closed invariant itself: numstat>0 with nothing parsed is a
    #     collector failure, 0/0 (deletion-only) is legitimate.
    verify_inspection_consistency("src/Ok.php", 0, [])
    verify_inspection_consistency("src/Ok.php", 2, [4, 5])
    verify_inspection_consistency("src/Binary.php", None, [])
    for declared, parsed in ((3, []), (2, [1])):
        try:
            verify_inspection_consistency("src/Broken.php", declared, parsed)
        except CollectorError:
            pass
        else:
            raise SelfTestFailure(
                f"invariant accepted declared_added={declared} parsed={parsed} "
                f"— an empty/partial added-line set could become a valid PASS")

    # (c) path-domain failure: a changed in-scope PHP file that the job working
    #     directory cannot address must stop the run instead of being skipped.
    repo2 = _fixture_init(base, "outside-working-directory")
    _fixture_write(repo2, "plugin/src/Thing.php", "<?php\n\n$x = 1;\n")
    _fixture_write(repo2, "tools/Root.php", "<?php\n\n$root = 1;\n")
    baseline2 = _fixture_commit(repo2, "baseline")
    _fixture_write(repo2, "tools/Root.php", "<?php\n\n$root = 1;\n$more = 2;\n")
    _fixture_commit(repo2, "modify a PHP file outside the job working directory")
    rc2, out2 = _run_cli(["--baseline", baseline2,
                          "--files-out", str(base / "outside-files.txt"),
                          "--added-out", str(base / "outside-added.txt")],
                         cwd=str(repo2 / "plugin"))
    _check(rc2 == EXIT_COLLECTOR_FAILURE,
           f"out-of-domain changed PHP file exited {rc2}, expected {EXIT_COLLECTOR_FAILURE}")
    _check("::error::" in out2, "out-of-domain changed PHP file did not emit a ::error:: annotation")
    _check("outside the job working directory" in out2,
           f"out-of-domain failure was not the path-domain guard: {out2.strip()[:300]}")

    # (d) the guard that turns "pathspec did not resolve" into a hard failure:
    #     `git diff` itself reports that case as success with no output.
    for bad, why in (("", "empty diff (unresolved pathspec)"),
                     ("diff --git a/plugin/src/Other.php b/plugin/src/Other.php\n", "foreign header")):
        try:
            verify_inspection_output("plugin/src/Thing.php", bad)
        except CollectorError:
            pass
        else:
            raise SelfTestFailure(f"guard accepted {why} — it could become a valid PASS")
    good = ("diff --git a/plugin/src/Thing.php b/plugin/src/Thing.php\n"
            "--- a/plugin/src/Thing.php\n+++ b/plugin/src/Thing.php\n"
            "@@ -3,0 +4 @@\n+$more = 2;\n")
    _check(verify_inspection_output("plugin/src/Thing.php", good) == [4],
           "guard rejected the correctly resolved diff")

    # (e) positive control: the same fixture succeeds when the changed PHP file
    #     is addressable from the job working directory, so (c) fails because of
    #     the path domain and not because of the fixture or git.
    repo3 = _fixture_init(base, "path-domain-control")
    _fixture_write(repo3, "plugin/src/Plain.php", "<?php\n\n$x = 1;\n")
    baseline3 = _fixture_commit(repo3, "baseline")
    _fixture_write(repo3, "plugin/src/Plain.php", "<?php\n\n$x = 1;\n$y = 2;\n")
    _fixture_commit(repo3, "modify plain file")
    rc3, _ = _run_cli(["--baseline", baseline3,
                       "--files-out", str(base / "plain-files.txt"),
                       "--added-out", str(base / "plain-added.txt")],
                      cwd=str(repo3 / "plugin"))
    _check(rc3 == EXIT_OK,
           f"positive control exited {rc3} — the fail-closed checks are not path-specific")
    _check(_read_lines(base / "plain-added.txt") == ["src/Plain.php:4"],
           f"positive control added-line metadata wrong: {_read_lines(base / 'plain-added.txt')}")


def test_nothing_in_scope_is_a_green_no_op(base: Path) -> None:
    """Unchanged policy: no in-scope PHP change is a green no-op, not a failure."""
    repo = _fixture_init(base, "empty-scope")
    _fixture_write(repo, "plugin/src/Thing.php", "<?php\n\n$x = 1;\n")
    baseline = _fixture_commit(repo, "baseline")
    _fixture_write(repo, "plugin/README.md", "docs only\n")
    _fixture_commit(repo, "docs only")

    rc, out = _run_cli(["--baseline", baseline,
                        "--files-out", str(base / "empty-files.txt"),
                        "--added-out", str(base / "empty-added.txt")],
                       cwd=str(repo / "plugin"))
    _check(rc == EXIT_OK, f"docs-only change exited {rc}, expected a green no-op")
    _check(_read_lines(base / "empty-files.txt") == [], "docs-only change produced PHP files")
    _check(_read_lines(base / "empty-added.txt") == [], "docs-only change produced added lines")
    _check("nothing to check" in out, "docs-only change did not report a green no-op")


def _run_cli(args: Sequence[str], cwd: str) -> tuple:
    """Invoke this script as the workflow invokes it."""
    proc = subprocess.run([sys.executable, str(Path(__file__).resolve()), *args],
                          cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    return proc.returncode, proc.stdout


SELF_TESTS = (
    ("corrected path handling collects the added violating line (defect reproduced in legacy form)",
     test_added_violating_line_is_collected),
    ("deletion-only / no-added-line changes never falsely fail", test_deletion_only_change_does_not_fail),
    ("collector/path failure cannot silently become a valid PASS", test_collector_failure_cannot_silently_pass),
    ("no in-scope PHP change stays a green no-op", test_nothing_in_scope_is_a_green_no_op),
)


def run_self_test() -> int:
    print("[wpcs-changed-lines] deterministic regression proof — synthetic repositories")
    failures = 0
    with tempfile.TemporaryDirectory(prefix="wpcs-changed-lines-selftest-") as tmp:
        base = Path(tmp)
        for index, (name, func) in enumerate(SELF_TESTS, start=1):
            try:
                func(base)
            except Exception as exc:  # noqa: BLE001 - report any failure as a self-test failure
                failures += 1
                print(f"[wpcs-changed-lines] self-test {index}/{len(SELF_TESTS)} FAIL — {name}")
                print(f"[wpcs-changed-lines]   {type(exc).__name__}: {exc}")
            else:
                print(f"[wpcs-changed-lines] self-test {index}/{len(SELF_TESTS)} PASS — {name}")
    if failures:
        print(f"[wpcs-changed-lines] self-tests FAILED: {failures}/{len(SELF_TESTS)}")
        return EXIT_COLLECTOR_FAILURE
    print(f"[wpcs-changed-lines] self-tests PASS: {len(SELF_TESTS)}/{len(SELF_TESTS)}")
    return EXIT_OK


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main(argv: Optional[Sequence[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="WPCS changed-lines collector")
    parser.add_argument("--baseline", default=os.environ.get("WPCS_BASELINE"),
                        help="baseline commit (default: $WPCS_BASELINE)")
    parser.add_argument("--files-out", default=DEFAULT_FILES_OUT,
                        help=f"changed in-scope PHP files, in the current working directory's path domain "
                             f"(default: {DEFAULT_FILES_OUT})")
    parser.add_argument("--added-out", default=DEFAULT_ADDED_OUT,
                        help=f"<path>:<line> of added lines, same path domain (default: {DEFAULT_ADDED_OUT})")
    parser.add_argument("--test", action="store_true", help="run the deterministic regression proof")
    args = parser.parse_args(argv)

    if args.test:
        return run_self_test()

    if not args.baseline:
        print("::error::WPCS collector: --baseline is required (or set $WPCS_BASELINE)")
        return EXIT_CONFIG_ERROR

    try:
        collect(args.baseline, args.files_out, args.added_out)
    except CollectorError as exc:
        print(f"::error::WPCS added-lines collector failed closed: {exc}")
        return EXIT_COLLECTOR_FAILURE
    return EXIT_OK


if __name__ == "__main__":
    sys.exit(main())

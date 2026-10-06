#!/usr/bin/env python3
"""Public-page CPMS plugin overhead collector — Phase 17 (MEASUREMENT ONLY).

WHAT THIS MEASURES
------------------
The previous Phase 17 comparative-profiling slice established only that there
is no material incremental CPMS *endpoint-processing* cost above an
active-plugin WordPress route baseline. It could NOT measure the cost of
**loading CPMS itself**, because `RequestProfiler` lives inside CPMS and
therefore cannot emit data while the plugin is deactivated.

This collector measures that gap by timing the SAME representative public page
in the SAME disposable Pilot/Staging WordPress instance:

    A) CPMS plugin ACTIVE
    B) CPMS plugin DEACTIVATED

BOUNDARY (why this is not a profiler)
-------------------------------------
No profiler data is synthesised for the DEACTIVATED state — faking an
equivalent would be asymmetrical evidence. The comparison therefore uses an
EXTERNAL, request-level timing boundary that is identical in both states: one
`curl` GET per sample, timed by the client (`%{time_total}`), sequential c=1,
same URL, same runner, same WordPress install, same database, same warm policy.

Query counts are deliberately NOT MEASURED. A fair per-request count in the
DEACTIVATED state would require a CPMS-owned observer (absent by definition) or
global query logging (unsafe, and a new dependency). A missing honest number is
better than an asymmetrical one.

DEACTIVATION SAFETY
-------------------
This may run ONLY inside the disposable Pilot/Staging WordPress instance created
by the workflow. Deactivation is WordPress-native (`wp plugin deactivate` /
`wp plugin activate`); nothing is uninstalled, deleted or dropped, no
destructive hook runs, and no CPMS table, setting or row is altered.

Reactivation is guaranteed twice over:
  * a `finally:` block in `collect()` (the Python equivalent of a shell trap), and
  * a shell `trap ... EXIT` in the calling workflow step, driven by a marker
    file that exists only while the plugin is deactivated.

Reactivation is then PROVEN before the step may continue: plugin active, public
CPMS health endpoint 200, `cpms_jobs_tick` scheduled, migration version
unchanged, and the CPMS table/column fingerprint unchanged. Any failure raises
`OverheadRefused`, which fails the step loudly rather than leaving a
deactivated environment for later gate steps.

PRIVACY
-------
Only numbers, the fixed page token, the fixed ordering and the two allowlisted
state tokens are written. No URL, header, body, filesystem path, plugin list,
SQL text/value, secret, nonce, cookie, environment dump or PHI can reach the
written files (enforced by `assert_output_clean()` and re-checked by
`bin/pilot-bench-report.py`'s privacy scan before publication).

Stdlib only — no new dependency. Deterministic self-tests: `--test`.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, Sequence, Tuple

SCHEMA = "cpms.page-overhead/1"

# Fixed allowlist: a page token is never a URL.
PAGE_TOKENS: Dict[str, str] = {"home": "Public front page"}

# The sandwich: ACTIVE -> DEACTIVATED -> ACTIVE. Rounds 1 and 3 bracket round 2
# so shared-runner temporal drift is observable instead of assumed away.
# Active-first is the deterministic policy: the install starts active and every
# later gate step needs it active.
ORDERING = "active_deactivated_active"
ROUND_STATES: Tuple[str, ...] = ("active", "deactivated", "active")

PLUGIN_SLUG = "clinic-practice-management"
CRON_HOOK = "cpms_jobs_tick"
PLUGIN_TABLE_PREFIX_MARKER = "cpms_"

PARAMS_NAME = "page-overhead.params.json"
SAMPLES_NAME = "page-overhead.samples.tsv"

MAX_SAMPLES = 10_000
MAX_SECONDS = 3600.0
VERSION_RE = re.compile(r"^20[0-9]{2}_[0-9]{2}_[0-9]{2}_[0-9]{4}$")
FINGERPRINT_RE = re.compile(r"^[0-9]{1,9}/[0-9]{1,9}$")
# The only text allowed to appear in a written file: the page token, the
# ordering, the two state tokens, and numbers.
CLEAN_TEXT_RE = re.compile(r"^[A-Za-z0-9_./\-]*$")


class OverheadRefused(Exception):
    """The overhead pass cannot be completed safely — refuse, never partial."""


# ---------------------------------------------------------------------------
# Command execution (injectable so the whole pass is deterministically testable)
# ---------------------------------------------------------------------------

Runner = Callable[[List[str]], Tuple[int, str]]


def subprocess_runner(args: List[str]) -> Tuple[int, str]:
    try:
        proc = subprocess.run(
            args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False
        )
    except OSError as exc:
        raise OverheadRefused("command_unavailable:%s" % (args[0] if args else "?")) from exc
    return proc.returncode, proc.stdout.decode("utf-8", "replace")


def _wp(runner: Runner, wp_path: str, args: Sequence[str]) -> Tuple[int, str]:
    return runner(["wp"] + list(args) + ["--path=%s" % wp_path, "--allow-root"])


def _curl(runner: Runner, url: str, write_out: Optional[str]) -> str:
    args = ["curl", "-s", "-o", "/dev/null"]
    if write_out is not None:
        args += ["-w", write_out]
    args.append(url)
    rc, out = runner(args)
    if rc != 0:
        raise OverheadRefused("curl_failed")
    return out.strip()


def _mysql_scalar(runner: Runner, db: str, sql: str) -> str:
    rc, out = runner(
        ["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-proot",
         "-N", "-B", db, "-e", sql]
    )
    if rc != 0:
        raise OverheadRefused("mysql_failed")
    return out.strip()


SQL_TABLES = (
    "SELECT COUNT(*) FROM information_schema.tables "
    "WHERE table_schema='{db}' AND table_name LIKE '{prefix}cpms_%'"
)
SQL_COLUMNS = (
    "SELECT COUNT(*) FROM information_schema.columns "
    "WHERE table_schema='{db}' AND table_name LIKE '{prefix}cpms_%'"
)


# ---------------------------------------------------------------------------
# Parsing helpers
# ---------------------------------------------------------------------------

def parse_sample(text: str) -> Tuple[int, float]:
    """Parse `curl -w '%{http_code} %{time_total}'` output into (code, seconds)."""
    parts = text.split()
    if len(parts) != 2:
        raise OverheadRefused("curl_output_unparsable")
    try:
        code = int(parts[0])
        seconds = float(parts[1])
    except ValueError as exc:
        raise OverheadRefused("curl_output_unparsable") from exc
    if not 100 <= code <= 599:
        raise OverheadRefused("http_code_out_of_range")
    if not math.isfinite(seconds) or not 0.0 < seconds <= MAX_SECONDS:
        raise OverheadRefused("seconds_out_of_range")
    return code, seconds


def percentile_nearest_rank(values: Sequence[float], percent: float) -> float:
    """Nearest-rank percentile: index = ceil(p/100 * n) - 1 (clamped, 1-based)."""
    ordered = sorted(values)
    index = int(math.ceil(percent / 100.0 * len(ordered))) - 1
    if index < 0:
        index = 0
    if index >= len(ordered):
        index = len(ordered) - 1
    return ordered[index]


def _ms(seconds: float) -> float:
    return round(seconds * 1000.0, 3)


# ---------------------------------------------------------------------------
# Collection
# ---------------------------------------------------------------------------

def sample_round(runner: Runner, round_index: int, state: str, url: str,
                 samples: int, warmup: int) -> List[Dict[str, Any]]:
    """Warm up, then take `samples` sequential c=1 client-timed samples."""
    probe = _curl(runner, url, "%{http_code}")
    if probe != "200":
        raise OverheadRefused(
            "page_probe_round_%d_state_%s_http_%s" % (round_index, state, probe or "empty")
        )
    for _ in range(warmup):
        _curl(runner, url, None)
    rows: List[Dict[str, Any]] = []
    for _ in range(samples):
        code, seconds = parse_sample(_curl(runner, url, "%{http_code} %{time_total}"))
        rows.append({"round": round_index, "state": state,
                     "http_code": code, "seconds": seconds})
    return rows


def schema_fingerprint(runner: Runner, db: str, prefix: str) -> str:
    tables = _mysql_scalar(runner, db, SQL_TABLES.format(db=db, prefix=prefix))
    columns = _mysql_scalar(runner, db, SQL_COLUMNS.format(db=db, prefix=prefix))
    if not re.fullmatch(r"[0-9]{1,9}", tables) or not re.fullmatch(r"[0-9]{1,9}", columns):
        raise OverheadRefused("schema_fingerprint_unavailable")
    return "%s/%s" % (tables, columns)


def schema_version(runner: Runner, wp_path: str) -> str:
    rc, out = _wp(runner, wp_path, [
        "eval", r"echo \ClinicCore\Bootstrap\App::migrations()->currentVersion();"
    ])
    value = out.strip()
    if rc != 0 or not VERSION_RE.fullmatch(value):
        raise OverheadRefused("schema_version_unavailable")
    return value


def _plugin_is_active(runner: Runner, wp_path: str) -> bool:
    rc, _ = _wp(runner, wp_path, ["plugin", "is-active", PLUGIN_SLUG])
    return rc == 0


def _activate(runner: Runner, wp_path: str) -> None:
    rc, _ = _wp(runner, wp_path, ["plugin", "activate", PLUGIN_SLUG])
    if rc != 0:
        raise OverheadRefused("reactivation_command_failed")


def prove_restored(runner: Runner, wp_path: str, health_url: str, db: str,
                   prefix: str, version_before: str, fingerprint_before: str) -> None:
    """Prove the disposable environment is fully back in its pre-slice state."""
    if not _plugin_is_active(runner, wp_path):
        raise OverheadRefused("reactivation_proof_plugin_not_active")
    if _curl(runner, health_url, "%{http_code}") != "200":
        raise OverheadRefused("reactivation_proof_health_not_200")
    rc, out = _wp(runner, wp_path, ["cron", "event", "list"])
    if rc != 0 or CRON_HOOK not in out:
        raise OverheadRefused("reactivation_proof_cron_hook_absent")
    if schema_version(runner, wp_path) != version_before:
        raise OverheadRefused("reactivation_proof_schema_version_changed")
    if schema_fingerprint(runner, db, prefix) != fingerprint_before:
        raise OverheadRefused("reactivation_proof_schema_fingerprint_changed")


def collect(out_dir: str, page_url: str, health_url: str, wp_path: str, db: str,
            db_prefix: str, samples: int, warmup: int, page: str = "home",
            marker: str = "", runner: Runner = subprocess_runner,
            emit: Callable[[str], None] = print) -> int:
    """Run the ACTIVE -> DEACTIVATED -> ACTIVE pass and write its input files."""
    if page not in PAGE_TOKENS:
        raise OverheadRefused("page_not_allowlisted")
    if not 1 <= samples <= MAX_SAMPLES:
        raise OverheadRefused("samples_out_of_range")
    if not 0 <= warmup <= MAX_SAMPLES:
        raise OverheadRefused("warmup_out_of_range")

    target = Path(out_dir)
    target.mkdir(parents=True, exist_ok=True)

    if not _plugin_is_active(runner, wp_path):
        raise OverheadRefused("cpms_not_active_before_active_round")
    version_before = schema_version(runner, wp_path)
    fingerprint_before = schema_fingerprint(runner, db, db_prefix)
    if not FINGERPRINT_RE.fullmatch(fingerprint_before):
        raise OverheadRefused("schema_fingerprint_unavailable")

    rows: List[Dict[str, Any]] = []
    emit("::notice::public-page overhead — round=1 state=active (%d sequential c=1 "
         "samples after %d warm-ups)" % (samples, warmup))
    rows.extend(sample_round(runner, 1, "active", page_url, samples, warmup))

    marker_path = Path(marker) if marker else None
    try:
        if marker_path is not None:
            marker_path.write_text("deactivated\n", encoding="utf-8")
        _wp(runner, wp_path, ["plugin", "deactivate", PLUGIN_SLUG])
        if _plugin_is_active(runner, wp_path):
            raise OverheadRefused("deactivation_did_not_take_effect")
        emit("::notice::public-page overhead — round=2 state=deactivated (%d "
             "sequential c=1 samples after %d warm-ups)" % (samples, warmup))
        rows.extend(sample_round(runner, 2, "deactivated", page_url, samples, warmup))
    finally:
        # Recovery: unconditional, and proven before the pass may continue.
        _activate(runner, wp_path)
        prove_restored(runner, wp_path, health_url, db, db_prefix,
                       version_before, fingerprint_before)
        if marker_path is not None and marker_path.exists():
            marker_path.unlink()
    emit("::notice::public-page overhead — CPMS reactivated and proven: plugin active, "
         "public health 200, jobs tick scheduled, schema version %s and table/column "
         "fingerprint %s unchanged" % (version_before, fingerprint_before))

    emit("::notice::public-page overhead — round=3 state=active (%d sequential c=1 "
         "samples after %d warm-ups)" % (samples, warmup))
    rows.extend(sample_round(runner, 3, "active", page_url, samples, warmup))

    params = {
        "schema": SCHEMA,
        "page": page,
        "ordering": ORDERING,
        "samples_per_round": samples,
        "warmup_per_round": warmup,
        "concurrency": 1,
    }
    (target / PARAMS_NAME).write_text(json.dumps(params) + "\n", encoding="utf-8")
    lines = [
        "%d\t%s\t%d\t%.6f" % (row["round"], row["state"], row["http_code"], row["seconds"])
        for row in rows
    ]
    (target / SAMPLES_NAME).write_text("\n".join(lines) + "\n", encoding="utf-8")
    assert_output_clean([target / PARAMS_NAME, target / SAMPLES_NAME])
    emit("public-page overhead collected: page=%s ordering=%s — 3 rounds x %d samples "
         "(c=1, %d warm-ups discarded each)" % (page, ORDERING, samples, warmup))
    return 0


def assert_output_clean(paths: Sequence[Path]) -> None:
    """Refuse to leave anything but allowlisted tokens and numbers on disk.

    The written files are the ONLY material that can reach the privacy scan and
    the REST-visible projection, so this is the last deterministic gate before
    an accidental URL/path/header/body/secret could enter the evidence chain.
    """
    for path in paths:
        if not path.is_file():
            raise OverheadRefused("output_missing:%s" % path.name)
        text = path.read_text(encoding="utf-8")
        if "\r" in text or "\x00" in text:
            raise OverheadRefused("output_contains_control_bytes:%s" % path.name)
        if path.name == PARAMS_NAME:
            payload = json.loads(text)
            if not isinstance(payload, dict):
                raise OverheadRefused("params_not_an_object")
            if payload.get("schema") != SCHEMA or payload.get("page") not in PAGE_TOKENS \
                    or payload.get("ordering") != ORDERING:
                raise OverheadRefused("params_not_allowlisted")
            for key, value in payload.items():
                if not CLEAN_TEXT_RE.fullmatch(str(key)):
                    raise OverheadRefused("params_key_not_allowlisted")
                if isinstance(value, str):
                    if not CLEAN_TEXT_RE.fullmatch(value):
                        raise OverheadRefused("params_value_not_allowlisted")
                elif isinstance(value, bool) or not isinstance(value, int):
                    raise OverheadRefused("params_value_not_an_integer")
            continue
        for line in text.splitlines():
            if line.strip() == "":
                continue
            for token in line.replace("\t", " ").split(" "):
                if token == "":
                    continue
                if not CLEAN_TEXT_RE.fullmatch(token):
                    raise OverheadRefused("output_token_not_allowlisted:%s" % path.name)


# ---------------------------------------------------------------------------
# Self-tests — deterministic: no network, no WordPress, no curl binary
# ---------------------------------------------------------------------------

def _selftests() -> int:
    failures: List[str] = []

    def check(name: str, condition: bool, detail: str = "") -> None:
        if not condition:
            failures.append("SELF-TEST FAIL: %s %s" % (name, detail))

    # 1) percentile definition (nearest rank, documented and pinned)
    values = [float(index) for index in range(1, 101)]  # 1..100
    check("p50-of-100", percentile_nearest_rank(values, 50.0) == 50.0)
    check("p95-of-100", percentile_nearest_rank(values, 95.0) == 95.0)
    check("p99-of-100", percentile_nearest_rank(values, 99.0) == 99.0)
    check("p95-of-10", percentile_nearest_rank([float(i) for i in range(1, 11)], 95.0) == 10.0)
    check("p50-of-1", percentile_nearest_rank([7.0], 50.0) == 7.0)
    check("percentile-order-invariant",
          percentile_nearest_rank([5.0, 1.0, 3.0], 95.0)
          == percentile_nearest_rank([1.0, 3.0, 5.0], 95.0))

    # 2) curl output parsing
    check("parse-valid", parse_sample("200 0.123456") == (200, 0.123456))
    for bad in ("", "200", "200 abc", "abc 0.1", "200 0.0", "200 -1.0",
                "200 nan", "200 inf", "99 0.1", "600 0.1"):
        try:
            parse_sample(bad)
        except OverheadRefused:
            pass
        else:
            check("parse-refuses-%r" % bad, False, "unexpectedly accepted")

    # 3) a stubbed runner: full pass, happy path and two failure paths
    class Stub:
        def __init__(self, active_seconds: float = 0.060,
                     deactivated_seconds: float = 0.048,
                     fail_round: Optional[int] = None,
                     health_after_restore: str = "200") -> None:
            self.active = True
            self.active_seconds = active_seconds
            self.deactivated_seconds = deactivated_seconds
            self.fail_round = fail_round
            self.health_after_restore = health_after_restore
            self.calls: List[str] = []
            self.current_round = 0

        def run(self, args: List[str]) -> Tuple[int, str]:
            head = " ".join(args[:2])
            self.calls.append(" ".join(args))
            if head.startswith("wp"):
                joined = " ".join(args)
                if "is-active" in joined:
                    return (0, "active") if self.active else (1, "inactive")
                if " deactivate " in joined or joined.endswith(" deactivate"):
                    self.active = False
                    return 0, "Plugin deactivated."
                if " activate " in joined or joined.endswith(" activate"):
                    self.active = True
                    return 0, "Plugin activated."
                if "cron" in joined:
                    return 0, "%s  2026-10-06 10:00:00  1 minute" % CRON_HOOK
                if "eval" in joined:
                    return 0, "2026_09_26_0023"
                return 0, ""
            if head.startswith("mysql"):
                return 0, "34" if "tables" in " ".join(args) else "517"
            if head.startswith("curl"):
                url = args[-1]
                fmt = args[args.index("-w") + 1] if "-w" in args else None
                if "health" in url:
                    return 0, (self.health_after_restore if self.active
                               else "404")
                seconds = self.active_seconds if self.active else self.deactivated_seconds
                if self.fail_round is not None and self.current_round == self.fail_round:
                    return 0, ("500" if fmt == "%{http_code}"
                               else "500 %.6f" % seconds)
                if fmt is None:
                    return 0, ""
                if fmt == "%{http_code}":
                    return 0, "200"
                return 0, "200 %.6f" % seconds
            return 1, ""

        def mark_round(self, round_index: int) -> None:
            self.current_round = round_index

    def _collect_with(stub: Stub, tmp: Path, **kwargs: Any) -> Any:
        # A tiny wrapper that lets the stub observe the current round index by
        # intercepting the emit() notice lines.
        def _emit(message: str) -> None:
            match = re.search(r"round=(\d+) state=(\w+)", message)
            if match:
                stub.mark_round(int(match.group(1)))

        return collect(
            out_dir=str(tmp / "overhead"),
            page_url="http://localhost:8080/",
            health_url="http://localhost:8080/wp-json/clinic/v1/health",
            wp_path="/tmp/www", db="cpms_main", db_prefix="cpmswp_",
            samples=kwargs.pop("samples", 6), warmup=kwargs.pop("warmup", 2),
            marker=str(tmp / "marker"), runner=stub.run, emit=_emit, **kwargs
        )

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        stub = Stub()
        rc = _collect_with(stub, root)
        check("stub-collect-rc", rc == 0, "rc=%s" % rc)
        out = root / "overhead"
        params = json.loads((out / PARAMS_NAME).read_text(encoding="utf-8"))
        check("stub-params", params == {"schema": SCHEMA, "page": "home",
                                        "ordering": ORDERING, "samples_per_round": 6,
                                        "warmup_per_round": 2, "concurrency": 1},
              str(params))
        lines = [line for line in (out / SAMPLES_NAME).read_text(encoding="utf-8")
                 .splitlines() if line.strip()]
        check("stub-sample-count", len(lines) == 18, str(len(lines)))
        states = [line.split("\t")[1] for line in lines]
        check("stub-states", states == ["active"] * 6 + ["deactivated"] * 6 + ["active"] * 6,
              str(states[:3]))
        seconds = [float(line.split("\t")[3]) for line in lines]
        check("stub-active-slower", seconds[0] == 0.060 and seconds[6] == 0.048
              and seconds[12] == 0.060, str(seconds[:1] + seconds[6:7]))
        joined = "\n".join(stub.calls)
        check("stub-deactivated-then-activated",
              joined.index(" deactivate ") < joined.index(" activate "), "order")
        check("stub-activated-exactly-once", joined.count(" activate ") == 1,
              str(joined.count(" activate ")))
        # round 3 must be measured with the plugin ACTIVE again (active latency)
        check("stub-round3-is-active", stub.active and seconds[12] == 0.060,
              str(seconds[12:13]))
        check("stub-marker-removed", not (root / "marker").exists())
        check("stub-plugin-active-at-end", stub.active)
        assert_output_clean([out / PARAMS_NAME, out / SAMPLES_NAME])

        # failure during the DEACTIVATED round: must still reactivate + prove
        stub2 = Stub(fail_round=2)
        try:
            _collect_with(stub2, root / "fail2")
        except OverheadRefused as exc:
            check("stub-fail-round-2-refused", "page_probe_round_2_state_deactivated" in str(exc),
                  str(exc))
        else:
            check("stub-fail-round-2-refused", False, "unexpectedly accepted")
        check("stub-fail-round-2-reactivated", stub2.active)
        check("stub-fail-round-2-marker-cleared", not (root / "fail2" / "marker").exists())
        check("stub-fail-round-2-no-output",
              not (root / "fail2" / "overhead" / PARAMS_NAME).exists())

        # a broken reactivation proof must fail loudly, not be skipped
        stub3 = Stub(health_after_restore="404")
        try:
            _collect_with(stub3, root / "fail3")
        except OverheadRefused as exc:
            check("stub-health-proof-refused", "reactivation_proof_health_not_200" in str(exc),
                  str(exc))
        else:
            check("stub-health-proof-refused", False, "unexpectedly accepted")

        # 4) input validation
        for bad in ({"page": "/?p=2"}, {"samples": 0}, {"samples": MAX_SAMPLES + 1},
                    {"warmup": -1}):
            stub4 = Stub()
            try:
                _collect_with(stub4, root / "bad", **bad)
            except OverheadRefused:
                pass
            else:
                check("collect-refuses-%s" % sorted(bad), False, "unexpectedly accepted")

        # 5) output cleaner rejects leaked material
        leak = root / "leak"
        leak.mkdir()
        (leak / PARAMS_NAME).write_text(
            json.dumps({"schema": SCHEMA, "page": "home", "ordering": ORDERING,
                        "samples_per_round": 6, "warmup_per_round": 2, "concurrency": 1}),
            encoding="utf-8")
        (leak / SAMPLES_NAME).write_text(
            "1\tactive\t200\t0.060000\n2\tdeactivated\t200\tSet-Cookie: sid=x\n",
            encoding="utf-8")
        try:
            assert_output_clean([leak / PARAMS_NAME, leak / SAMPLES_NAME])
        except OverheadRefused:
            pass
        else:
            check("output-cleaner-refuses-leak", False, "unexpectedly accepted")

    if failures:
        for line in failures:
            print(line, file=sys.stderr)
        print("SELF-TESTS: %s FAILED" % len(failures), file=sys.stderr)
        return 1
    print("SELF-TESTS: OK (pilot-page-overhead — sampling, safety recovery, "
          "output allowlist)")
    return 0


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(
        description="Public-page CPMS plugin overhead collector (ACTIVE vs DEACTIVATED; "
                    "measurement only)."
    )
    parser.add_argument("--test", action="store_true",
                        help="run the deterministic self-tests")
    parser.add_argument("mode", nargs="?", choices=["collect"],
                        help="operating mode")
    parser.add_argument("--out-dir", default="", help="directory for the two input files")
    parser.add_argument("--page-url", default="", help="the fixed public page to time")
    parser.add_argument("--health-url", default="",
                        help="the public CPMS health endpoint, used only as a "
                             "post-reactivation bootstrap proof")
    parser.add_argument("--wp-path", default="", help="WordPress install path for wp-cli")
    parser.add_argument("--db", default="", help="database name (schema fingerprint only)")
    parser.add_argument("--db-prefix", default="", help="WordPress table prefix")
    parser.add_argument("--page", default="home", choices=sorted(PAGE_TOKENS),
                        help="fixed allowlisted page token")
    parser.add_argument("--samples", type=int, default=100,
                        help="sequential c=1 samples per round")
    parser.add_argument("--warmup", type=int, default=5,
                        help="warm-up requests discarded per round")
    parser.add_argument("--marker", default="",
                        help="marker file that exists only while CPMS is deactivated "
                             "(drives the shell trap recovery in the workflow step)")
    args = parser.parse_args(argv)

    if args.test:
        return _selftests()
    if args.mode != "collect":
        parser.error("a mode is required (collect), or --test")
        return 2
    for field in ("out_dir", "page_url", "health_url", "wp_path", "db", "db_prefix"):
        if not getattr(args, field):
            parser.error("--%s is required for collect" % field.replace("_", "-"))
            return 2
    try:
        return collect(
            out_dir=args.out_dir, page_url=args.page_url, health_url=args.health_url,
            wp_path=args.wp_path, db=args.db, db_prefix=args.db_prefix,
            samples=args.samples, warmup=args.warmup, page=args.page,
            marker=args.marker,
        )
    except OverheadRefused as exc:
        print("::error::PAGE OVERHEAD MEASUREMENT FAILURE — %s" % exc, file=sys.stderr)
        print("::error::public-page overhead — the disposable environment MUST NOT be "
              "left deactivated; the shell trap will force reactivation", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())

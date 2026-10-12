#!/usr/bin/env python3
"""Phase 18 third-party compatibility BASELINE harness — evidence aggregation.

TEST-ONLY CI tooling.  Merges every per-subject ``subject-result.json`` produced
by ``probe.py finalize`` into one machine-readable ``summary.json`` plus one
human-readable ``summary.md`` artifact.

Nothing here re-adjudicates a subject: the per-subject state and taxonomy were
already derived by ``probe.py`` from recorded evidence.  This module collects,
orders and renders, and it fails loudly on malformed or missing input rather
than quietly presenting an incomplete campaign as complete.

RESULT PROPAGATION: this aggregate exits non-zero when any subject result says
``FAIL`` (a material failure, an environment failure or a missing evidence phase
must never be aggregated away) and when the delivered evidence is unreadable,
malformed or empty.  ``PARTIAL`` and ``NOT RUN`` are non-blocking: they are
rendered as themselves and never as ``PASS``.

The single owner-approved diagnostic exception is honoured, and only it: a
``FAIL`` subject is treated as non-blocking only when the delivered evidence
re-derives that exception (identity, accepted failure identifiers and positive
probe-execution evidence), never because the artifact asserts it.  The raw
``FAIL``, its counts and its taxonomy stay in the summary; the exception is
reported as an accepted diagnostic disposition, never as a compatibility pass.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path
from typing import Any, Dict, List

# The diagnostic policy lives in probe.py, beside the checks it is written
# against. It is re-applied here so the aggregate's exit status does not depend
# on a producer-supplied boolean.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from probe import diagnostic_exception  # noqa: E402

STATE_ORDER = ["FAIL", "PARTIAL", "PASS", "NOT RUN"]
REQUIRED_KEYS = ("schema", "subject", "state", "checks", "environment")


def expected_subjects(workflow_path: str) -> List[str]:
    """The subject ids this workflow's matrix declares — the authoritative set.

    Read from the workflow itself, so the expected campaign cannot drift from a
    second hand-maintained list. Deliberately a line scan and not a YAML parse:
    the harness has no YAML dependency, the matrix is the only place these ids
    exist, and the scan fails closed — a file that yields no ids is an error for
    the caller, never an empty expectation.
    """
    ids: List[str] = []
    include_indent: int | None = None
    for line in Path(workflow_path).read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#"):
            continue
        indent = len(line) - len(line.lstrip())
        if include_indent is None:
            if stripped == "include:":
                include_indent = indent
            continue
        if indent <= include_indent and not stripped.startswith("-"):
            break  # the matrix block ended
        found = re.match(r"-\s*id:\s*(\S+)\s*$", stripped)
        if found:
            ids.append(found.group(1).strip("'\""))
    return ids


def load_results(root: str) -> tuple[List[Dict[str, Any]], List[str], List[str]]:
    """Load every subject result.

    Returns ``(results, fatal_problems, delivered_ids)``. Each delivered id is
    the artifact-directory identity, and it must equal the identity recorded
    inside the result: a mismatch means the delivered evidence is inconsistent,
    which is a blocking problem rather than a subject to report.
    """
    results: List[Dict[str, Any]] = []
    problems: List[str] = []
    delivered: List[str] = []
    for dirpath, _dirs, files in os.walk(root):
        for name in files:
            if name != "subject-result.json":
                continue
            path = os.path.join(dirpath, name)
            try:
                with open(path, encoding="utf-8") as handle:
                    data = json.load(handle)
            except (json.JSONDecodeError, OSError) as exc:
                problems.append(f"unreadable subject result {path}: {type(exc).__name__}: {exc}")
                continue
            missing = [key for key in REQUIRED_KEYS if key not in data]
            if missing:
                problems.append(f"{path} is missing required keys: {missing}")
                continue
            if not isinstance(data.get("subject"), dict):
                problems.append(f"{path} has no subject record")
                continue
            if data.get("state") not in STATE_ORDER:
                problems.append(f"{path} has an out-of-contract state: {data.get('state')!r}")
                continue

            relative = os.path.relpath(dirpath, root)
            if relative in (".", ""):
                problems.append(f"{path} is not inside a per-subject artifact directory")
                continue
            directory = relative.split(os.sep)[0]
            subject_id = directory[4:] if directory.startswith("tpb-") else directory
            recorded_id = data["subject"].get("id")
            if subject_id != recorded_id:
                problems.append(
                    f"{path} identity mismatch: artifact directory says {subject_id!r} but the "
                    f"result records {recorded_id!r}")
                continue
            if subject_id in delivered:
                problems.append(f"duplicate subject result for {subject_id!r} ({path})")
                continue
            delivered.append(subject_id)
            results.append(data)

    results.sort(key=lambda item: (STATE_ORDER.index(item["state"]),
                                   str(item["subject"].get("id", ""))))
    return results, problems, delivered


def absent_flag(phase: Dict[str, Any] | None) -> str:
    if not phase:
        return "n/a"
    clean = (not phase.get("plugin_dir_present")
             and not phase.get("plugin_files_found")
             and not phase.get("plugin_entries_found")
             and phase.get("table_count") == 0
             and phase.get("cpms_option_count") == 0)
    return "yes" if clean else "NO"


def disposition(result: Dict[str, Any]) -> Dict[str, Any]:
    """Re-derive the gate disposition of one delivered subject result.

    A ``FAIL`` subject blocks unless the delivered evidence itself satisfies the
    single approved diagnostic exception.
    """
    if result["state"] != "FAIL":
        return {"blocking": False, "diagnostic": None}
    diagnostic = diagnostic_exception(result.get("subject") or {},
                                      result.get("facts") or {},
                                      result.get("checks") or [])
    return {"blocking": not diagnostic["applies"], "diagnostic": diagnostic}


def gate_line(results: List[Dict[str, Any]], totals: Dict[str, int]) -> str:
    blocking = [r for r in results if disposition(r)["blocking"]]
    accepted = [r for r in results if r["state"] == "FAIL" and not disposition(r)["blocking"]]
    if blocking:
        return (f"**RED — {len(blocking)} genuinely blocking subject FAIL: this aggregate "
                f"concludes `failure` too.**")
    if accepted:
        return (f"**no blocking subject FAIL. {len(accepted)} material FAIL subject(s) "
                f"recorded and accepted as diagnostic-only exceptions — NOT compatibility "
                f"PASSes. PARTIAL is recorded evidence, never a PASS.**")
    return ("**no material subject FAIL. PARTIAL is recorded evidence below, "
            "never a PASS.**")


def render_markdown(results: List[Dict[str, Any]], meta: Dict[str, Any],
                    totals: Dict[str, int]) -> str:
    lines: List[str] = [
        "# Phase 18 third-party compatibility baseline — Lane A",
        "",
        "Each subject ran in its own GitHub Actions job: fresh runner, fresh MySQL "
        "container and database, fresh WordPress filesystem. CPMS was never installed "
        "in any job, and its absence was asserted before and after the subject under "
        "test was applied — a pinned plugin for the product legs, the locale fixture "
        "for the Persian baseline leg.",
        "",
        f"- lane: **{meta['lane']}**",
        f"- head SHA: `{meta['head_sha']}`",
        f"- run: {meta['run_url']}",
        "- subjects: " + " · ".join(f"{state} {totals.get(state, 0)}" for state in STATE_ORDER),
        f"- campaign: {meta['delivered_subjects']}/{meta['expected_subjects']} expected "
        "matrix subject results delivered",
        "- gate: " + gate_line(results, totals),
        "",
        "## Results",
        "",
        "| State | Subject | Pin | Locale / TZ | WP | PHP (web) | MySQL | Tables before → after | CPMS absent |",
        "| --- | --- | --- | --- | --- | --- | --- | --- | --- |",
    ]
    for result in results:
        subject, environment = result["subject"], result["environment"]
        tables, absence = result.get("table_counts", {}), result.get("cpms_absence", {})
        locale = environment.get("locale") or ""
        timezone = environment.get("timezone") or ""
        locale_cell = " · ".join(p for p in (locale, timezone) if p) or "–"
        lines.append(
            "| {state} | `{sid}` | {version} | {locale} | {wp} | {php} | {mysql} "
            "| {before} → {after} | {cpms} |"
            .format(state=result["state"], sid=subject.get("id", ""),
                    version=subject.get("version_requested", ""), locale=locale_cell,
                    wp=environment.get("wordpress") or "", php=environment.get("php_web") or "",
                    mysql=environment.get("mysql") or "",
                    before=tables.get("before_activation"), after=tables.get("after_activation"),
                    cpms=f"{absent_flag(absence.get('before'))}/{absent_flag(absence.get('after'))}"))
    lines.append("")

    lines += ["## Material failures, evidence class and taxonomy", ""]
    failures = [r for r in results if r["state"] == "FAIL"]
    if not failures:
        lines.append("No material failure. Nothing to attribute.")
    else:
        lines += [
            "CPMS is absent from every job, so no result can be category A "
            "or B. Category E does not exist. A genuine third-party failure is reported "
            "as `THIRD-PARTY BASELINE FAILURE - no CPMS classification applicable`; the "
            "Persian baseline leg installs no third-party product, so its product-class "
            "failures are reported as `LOCALE BASELINE FAILURE - no CPMS classification "
            "applicable` rather than being attributed to a product it never ran.",
            "",
        ]
        for result in failures:
            gate = disposition(result)
            label = ("BLOCKING" if gate["blocking"]
                     else "diagnostic-only, accepted, non-blocking — NOT a compatibility PASS")
            lines.append(f"### `{result['subject'].get('id')}` — FAIL ({label})")
            lines.append("")
            for check in result["checks"]:
                if check.get("material") and check.get("status") == "FAIL":
                    observed = json.dumps(check.get("observed"), ensure_ascii=False)[:220]
                    lines.append(f"- `{check['name']}` — class **{check['klass']}** — "
                                 f"observed `{observed}`")
            lines += ["", f"- taxonomy: {', '.join(result.get('taxonomy') or ['unclassified'])}"]
            if gate["blocking"]:
                lines += ["",
                          "**Repeat once in a fresh environment before causal attribution:** one "
                          "subject is one job, so a fresh `workflow_dispatch` run of this "
                          "workflow reproduces it on a fresh runner, container and WordPress.",
                          "", "```", "gh workflow run third-party-baseline.yml", "```"]
            else:
                lines += ["",
                          "This subject is the single owner-approved diagnostic exception: its "
                          "raw FAIL state, counts, taxonomy and artifacts are preserved, and its "
                          "gate disposition is non-blocking. It is not a compatibility PASS.",
                          f"- accepted policy: {gate['diagnostic']['policy']}",
                          "- accepted material failures: "
                          + ", ".join(f"`{name}`" for name in
                                      gate["diagnostic"]["accepted_material_failures"])]
            lines.append("")

    partial = [r for r in results if r["state"] == "PARTIAL"]
    if partial:
        lines += ["## PARTIAL — recorded, non-blocking, never rendered as PASS", ""]
        lines += [f"- `{r['subject'].get('id')}` — {r.get('reason') or 'recorded findings'}"
                  for r in partial]
        lines += ["",
                  "PARTIAL means the subject was measured and no material check failed, "
                  "while at least one finding was recorded (a recorded-only FAIL, a "
                  "FEATURE UNAVAILABLE, or an UNEXECUTED check). It is not a PASS and it "
                  "does not fail the gate.", ""]

    not_run = [r for r in results if r["state"] == "NOT RUN"]
    if not_run:
        lines += ["## NOT RUN", ""]
        lines += [f"- `{r['subject'].get('id')}` — **NOT RUN** — {r.get('reason', '')}"
                  for r in not_run]
        lines.append("")

    classes: Dict[str, int] = {}
    unexecuted: List[str] = []
    unavailable: List[str] = []
    for result in results:
        sid = result["subject"].get("id")
        for check in result["checks"]:
            if check.get("status") == "FAIL":
                classes[check.get("klass", "?")] = classes.get(check.get("klass", "?"), 0) + 1
            elif check.get("status") == "UNEXECUTED":
                unexecuted.append(f"{sid}::{check['name']}")
            elif check.get("status") == "FEATURE UNAVAILABLE":
                unavailable.append(f"{sid}::{check['name']}")

    lines += ["## Evidence classes present", ""]
    lines += [f"- {name}: {count} recorded FAIL" for name, count in sorted(classes.items())] or ["- none"]
    lines += ["",
              "Unavailable features (marked for that feature only, never turned into PASS "
              "and never allowed to fail the whole plugin):", ""]
    lines += [f"- `{entry}`" for entry in unavailable] or ["- none"]
    lines += ["", "Unexecuted checks (explicitly recorded, never silently skipped):", ""]
    lines += [f"- `{entry}`" for entry in unexecuted] or ["- none"]
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Aggregate Phase 18 third-party baseline subject results.")
    parser.add_argument("--results-dir", required=True)
    parser.add_argument("--out-dir", required=True)
    # The authoritative expected campaign. Required: aggregating without the
    # workflow's own matrix would silently accept an incomplete set.
    parser.add_argument("--expect-matrix", required=True,
                        help="workflow file whose matrix declares the expected subjects")
    parser.add_argument("--lane", default="A")
    parser.add_argument("--head-sha", default="")
    parser.add_argument("--run-url", default="")
    args = parser.parse_args()

    results, problems, delivered = load_results(args.results_dir)
    # Campaign completeness: exact set equality against the workflow matrix. A
    # matrix job that never ran, never uploaded or was cancelled leaves no
    # artifact, and a successful matrix conclusion does not prove otherwise.
    expected = expected_subjects(args.expect_matrix)
    if not expected:
        problems.append(f"{args.expect_matrix} declares no matrix subject ids; refusing to "
                        "reconcile the campaign against an empty expectation")
    missing = sorted(set(expected) - set(delivered))
    unexpected = sorted(set(delivered) - set(expected))
    for subject_id in missing:
        problems.append(f"missing expected subject result: {subject_id!r} is a matrix leg but "
                        "no consistent result was delivered for it")
    for subject_id in unexpected:
        problems.append(f"unexpected subject result: {subject_id!r} is not a matrix leg")
    for problem in problems:
        print(f"::error::{problem}")
    if not results:
        print("::error::no readable subject result found; refusing to publish an empty summary")
        return 1

    meta = {"lane": args.lane, "head_sha": args.head_sha, "run_url": args.run_url,
            "expected_subjects": len(expected), "delivered_subjects": len(delivered)}
    totals: Dict[str, int] = {state: 0 for state in STATE_ORDER}
    for result in results:
        totals[result["state"]] += 1

    # A subject whose derived state is FAIL was not measured, or measured a
    # material failure. The aggregate must go red with it: a green summary over a
    # red subject is exactly the propagation defect this check exists to prevent.
    # The only FAIL that does not block is the one approved diagnostic exception,
    # and it is re-derived here from the delivered evidence, not trusted.
    failed = [r for r in results if r["state"] == "FAIL"]
    blocking = [r for r in failed if disposition(r)["blocking"]]
    accepted = [r for r in failed if not disposition(r)["blocking"]]
    for result in failed:
        blockers = sorted({f"{c['name']}={c['status']}" for c in result["checks"]
                           if c.get("material") and c.get("status") == "FAIL"})
        reason = ("diagnostic-only accepted exception (non-blocking, NOT a compatibility "
                  "PASS)" if not disposition(result)["blocking"]
                  else "blocking: " + "; ".join(disposition(result)["diagnostic"]["reasons"]))
        print(f"::{'warning' if not disposition(result)['blocking'] else 'error'}::subject "
              f"{result['subject'].get('id')} STATE=FAIL ({reason}) "
              f"taxonomy={json.dumps(result.get('taxonomy') or [])} "
              f"material failures: {json.dumps(blockers)}")

    markdown = render_markdown(results, meta, totals)
    os.makedirs(args.out_dir, exist_ok=True)
    json_path = os.path.join(args.out_dir, "summary.json")
    with open(json_path, "w", encoding="utf-8") as handle:
        json.dump({"schema": "cpms-phase18-third-party-baseline-summary/v1", "meta": meta,
                   "totals": totals, "problems": problems,
                   # Raw FAIL subjects are never merged into PARTIAL: the summary
                   # carries the actual FAIL subjects, the accepted diagnostic
                   # exceptions and the genuinely blocking failures separately.
                   "dispositions": {
                       "fail_subjects": [r["subject"].get("id") for r in failed],
                       "accepted_diagnostic_exceptions": [r["subject"].get("id") for r in accepted],
                       "blocking_failures": [r["subject"].get("id") for r in blocking],
                       "partial_subjects": [r["subject"].get("id") for r in results
                                            if r["state"] == "PARTIAL"],
                   },
                   # Campaign reconciliation against the workflow matrix itself.
                   "campaign": {
                       "expected_subjects": sorted(expected),
                       "delivered_subjects": sorted(delivered),
                       "missing_subjects": missing,
                       "unexpected_subjects": unexpected,
                   },
                   "subjects": results},
                  handle, indent=2)
        handle.write("\n")
    with open(os.path.join(args.out_dir, "summary.md"), "w", encoding="utf-8") as handle:
        handle.write(markdown)

    step_summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if step_summary:
        with open(step_summary, "a", encoding="utf-8") as handle:
            handle.write(markdown)

    print(f"[report] subjects={len(results)} totals={totals} problems={len(problems)} "
          f"fail={len(failed)} blocking={len(blocking)} "
          f"accepted_diagnostic={[r['subject'].get('id') for r in accepted]}")
    print(f"[report] wrote {json_path}")
    # A malformed subject result is a class D harness defect, and a blocking FAIL
    # subject is a material failure or an unmeasured subject: in both cases the
    # campaign is incomplete or red and must not be presented as a clean aggregate.
    # Only the explicitly re-derived diagnostic exception is non-blocking; the
    # summary above is still written before this exit so the evidence survives.
    return 1 if (problems or blocking) else 0


if __name__ == "__main__":
    sys.exit(main())

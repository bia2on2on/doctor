#!/usr/bin/env python3
"""Phase 18 third-party compatibility BASELINE harness — evidence aggregation.

TEST-ONLY CI tooling.  Merges every per-subject ``subject-result.json`` produced
by ``probe.py finalize`` into one machine-readable ``summary.json`` plus one
human-readable ``summary.md`` artifact.

Nothing here re-adjudicates a subject: the per-subject state and taxonomy were
already derived by ``probe.py`` from recorded evidence.  This module collects,
orders and renders, and it fails loudly on malformed or missing input rather
than quietly presenting an incomplete campaign as complete.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from typing import Any, Dict, List

STATE_ORDER = ["FAIL", "PARTIAL", "PASS", "NOT RUN"]
REQUIRED_KEYS = ("schema", "subject", "state", "checks", "environment")


def load_results(root: str) -> tuple[List[Dict[str, Any]], List[str]]:
    """Load every subject result, returning (results, fatal_problems)."""
    results: List[Dict[str, Any]] = []
    problems: List[str] = []
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
            if data.get("state") not in STATE_ORDER:
                problems.append(f"{path} has an out-of-contract state: {data.get('state')!r}")
                continue
            results.append(data)

    results.sort(key=lambda item: (STATE_ORDER.index(item["state"]),
                                   str(item["subject"].get("id", ""))))
    return results, problems


def absent_flag(phase: Dict[str, Any] | None) -> str:
    if not phase:
        return "n/a"
    clean = (not phase.get("plugin_dir_present")
             and not phase.get("plugin_files_found")
             and not phase.get("plugin_entries_found")
             and phase.get("table_count") == 0
             and phase.get("cpms_option_count") == 0)
    return "yes" if clean else "NO"


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
            lines.append(f"### `{result['subject'].get('id')}` — FAIL")
            lines.append("")
            for check in result["checks"]:
                if check.get("material") and check.get("status") == "FAIL":
                    observed = json.dumps(check.get("observed"), ensure_ascii=False)[:220]
                    lines.append(f"- `{check['name']}` — class **{check['klass']}** — "
                                 f"observed `{observed}`")
            lines += ["", f"- taxonomy: {', '.join(result.get('taxonomy') or ['unclassified'])}",
                      "",
                      "**Repeat once in a fresh environment before causal attribution:** one "
                      "subject is one job, so a fresh `workflow_dispatch` run of this "
                      "workflow reproduces it on a fresh runner, container and WordPress.",
                      "", "```", "gh workflow run third-party-baseline.yml", "```", ""]

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
    parser.add_argument("--lane", default="A")
    parser.add_argument("--head-sha", default="")
    parser.add_argument("--run-url", default="")
    args = parser.parse_args()

    results, problems = load_results(args.results_dir)
    for problem in problems:
        print(f"::error::{problem}")
    if not results:
        print("::error::no readable subject result found; refusing to publish an empty summary")
        return 1

    meta = {"lane": args.lane, "head_sha": args.head_sha, "run_url": args.run_url}
    totals: Dict[str, int] = {state: 0 for state in STATE_ORDER}
    for result in results:
        totals[result["state"]] += 1

    markdown = render_markdown(results, meta, totals)
    os.makedirs(args.out_dir, exist_ok=True)
    json_path = os.path.join(args.out_dir, "summary.json")
    with open(json_path, "w", encoding="utf-8") as handle:
        json.dump({"schema": "cpms-phase18-third-party-baseline-summary/v1", "meta": meta,
                   "totals": totals, "problems": problems, "subjects": results},
                  handle, indent=2)
        handle.write("\n")
    with open(os.path.join(args.out_dir, "summary.md"), "w", encoding="utf-8") as handle:
        handle.write(markdown)

    step_summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if step_summary:
        with open(step_summary, "a", encoding="utf-8") as handle:
            handle.write(markdown)

    print(f"[report] subjects={len(results)} totals={totals} problems={len(problems)}")
    print(f"[report] wrote {json_path}")
    # A malformed subject result is a class D harness defect: the campaign is
    # incomplete and must not be presented as a clean aggregate.
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())

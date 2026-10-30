#!/usr/bin/env python3
"""Parse every workflow file with a real YAML parser, before GitHub does.

WHY THIS EXISTS
---------------
A workflow file that is not valid YAML does not fail a step: it fails to *start*.
GitHub reports a run with zero jobs and the conclusion `failure`, the reason
appears only in the Actions UI, and every gate inside that workflow silently did
not run - including the ones this project depends on for its evidence. That is
exactly how a step name such as `- name: Chromium: one Persian action ...` (a
colon followed by a space inside an unquoted plain scalar) took the whole scoped
lane down while every local suite stayed green.

The check is deliberately dumb and complete: parse every file under
`.github/workflows/`, then assert the structural facts a workflow needs (a `name`,
an `on`/`jobs` mapping, and every job with `runs-on` and `steps`). It never
interprets expressions; it only proves the document is loadable and shaped like a
workflow.

Exit codes: 0 all parsed, 1 something did not, 2 no parser available.
"""

from __future__ import annotations

import sys
from pathlib import Path

WORKFLOWS = Path(__file__).resolve().parents[1] / ".github" / "workflows"


def load_parser():
    try:
        import yaml  # noqa: PLC0415 - optional dependency, imported lazily on purpose.
    except ImportError:
        return None
    return yaml


def main() -> int:
    yaml = load_parser()
    if yaml is None:
        print(
            "FAIL: no YAML parser is available. Install one (`python3 -m pip install pyyaml`); "
            "a check that cannot parse is worse than no check, so this is not skipped silently.",
            file=sys.stderr,
        )
        return 2

    files = sorted([*WORKFLOWS.glob("*.yml"), *WORKFLOWS.glob("*.yaml")])
    if not files:
        print(f"FAIL: no workflow files found under {WORKFLOWS}", file=sys.stderr)
        return 1

    problems: list[str] = []
    for path in files:
        try:
            document = yaml.safe_load(path.read_text(encoding="utf-8"))
        except yaml.YAMLError as error:
            first = str(error).splitlines()[0]
            problems.append(f"{path.name}: not valid YAML: {first}")
            continue
        if not isinstance(document, dict):
            problems.append(f"{path.name}: the document is not a mapping")
            continue
        if not document.get("name"):
            problems.append(f"{path.name}: no workflow name")
        # PyYAML resolves the unquoted `on:` key to the boolean True.
        triggers = document.get("on", document.get(True))
        if not triggers:
            problems.append(f"{path.name}: no trigger (`on:`)")
        jobs = document.get("jobs")
        if not isinstance(jobs, dict) or not jobs:
            problems.append(f"{path.name}: no jobs")
            continue
        for job_name, job in jobs.items():
            if not isinstance(job, dict):
                problems.append(f"{path.name}: job {job_name} is not a mapping")
                continue
            if "uses" in job:
                continue
            if not job.get("runs-on"):
                problems.append(f"{path.name}: job {job_name} has no `runs-on`")
            steps = job.get("steps")
            if not isinstance(steps, list) or not steps:
                problems.append(f"{path.name}: job {job_name} has no steps")
                continue
            for index, step in enumerate(steps, start=1):
                if not isinstance(step, dict):
                    problems.append(f"{path.name}: job {job_name} step {index} is not a mapping")
                    continue
                if not ({"run", "uses"} & set(step)):
                    problems.append(f"{path.name}: job {job_name} step {index} has neither `run` nor `uses`")

    if problems:
        for problem in problems:
            print(f"::error title=WORKFLOW_YAML::{problem}", file=sys.stderr)
        print(f"FAIL: {len(problems)} workflow problem(s) in {len(files)} file(s)", file=sys.stderr)
        return 1

    print(f"WORKFLOW_YAML_OK files={len(files)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

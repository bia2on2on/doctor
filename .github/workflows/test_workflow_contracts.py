"""Static and executable contracts for acceptance workflow routing (stdlib only)."""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"
PUBLISHER = ROOT / ".github" / "scripts" / "post-pr-evidence.sh"
COMMENTER = ROOT / ".github" / "scripts" / "pr-evidence-comment.sh"
SAFE_PILOT_EVIDENCE = ROOT / "clinic-practice-management" / "bin" / "pilot-safe-evidence.py"


def workflow(name):
    return (WORKFLOWS / name).read_text(encoding="utf-8")


def ci_job_block(text, job_key):
    """Return the YAML block of one ci.yml job (top-level `  <job>:` section)."""
    lines = text.splitlines()
    start = None
    for index, line in enumerate(lines):
        if line == "  {0}:".format(job_key):
            start = index
            break
    if start is None:
        raise AssertionError("job not found in ci.yml: {0}".format(job_key))
    end = len(lines)
    for index in range(start + 1, len(lines)):
        if re.match(r"^  [A-Za-z0-9_-]+:$", lines[index]):
            end = index
            break
    return "\n".join(lines[start:end])


def _logical_lines(text):
    """Join shell line-continuations (trailing backslash) into logical lines.

    Yields (first_line_number, logical_line). Multi-line commands in run:
    blocks would otherwise look like unredirected writers on their first
    physical line.
    """
    buffer = None
    start = 1
    for index, line in enumerate(text.splitlines()):
        if buffer is None:
            buffer = line
            start = index + 1
        else:
            buffer += " " + line.strip()
        stripped_right = line.rstrip()
        if stripped_right.endswith("\\"):
            buffer = buffer.rstrip()[:-1].rstrip() + " "
            continue
        yield start, buffer
        buffer = None
    if buffer is not None:
        yield start, buffer


def _yaml_parser():
    global _YAML_PARSER
    parser = globals().get("_YAML_PARSER")
    if parser is None:
        try:
            import yaml  # noqa: PLC0415 - optional dependency, imported lazily.
        except ImportError as exc:
            raise AssertionError(
                "PyYAML is required for the Integration raw-output guard; "
                "install it (`python3 -m pip install pyyaml`)."
            ) from exc
        _YAML_PARSER = yaml
        return yaml
    return parser


def _ci_document(text):
    document = _yaml_parser().safe_load(text)
    if not isinstance(document, dict):
        raise AssertionError("ci.yml did not parse to a mapping")
    return document


def integration_job_document(text):
    """Return the parsed `integration` job mapping from ci.yml text."""
    jobs = _ci_document(text).get("jobs") or {}
    job = jobs.get("integration")
    if not isinstance(job, dict):
        raise AssertionError("job not found in ci.yml: integration")
    return job


# --- Integration raw-output guard -------------------------------------------
#
# Scope: the ci.yml `integration` job ONLY. Unit/build/WPCS/release jobs are
# known, pre-existing raw-output surfaces and are deliberately out of scope
# for this guard (they are reported as open security debt, not fixed here).
#
# The guard parses ci.yml as YAML first: every `run` string — inline or
# block scalar — is dedented by the parser, so block-scalar lines, bash -c /
# sh -c bodies and python -c / heredoc bodies are all examined as real
# command text (the previous line-oriented guard missed them whenever the
# YAML key prefixing was absent).
#
# A static guard cannot prove absence of all leaks. Known limitations,
# deliberately accepted and disclosed:
#   * a variable holding raw content (`LOG=/tmp/x.log; cat "$LOG"`) — taint is
#     not tracked across expansions or logical lines;
#   * a python file read through a variable path (`open(path)`) — only raw
#     literals are matched;
#   * `ET.parse(...)`/counts-only gates cannot be distinguished from
#     publishers by a regex and are allowed (they print fixed counts only, by
#     construction and by review; this guard does not verify it);
#   * a python expression without `print`/`write` emits nothing and is not a
#     publication, so the rules key on read/print primitives;
#   * quoted bodies cannot contain the enclosing quote character, so chunk
#     boundaries are quote-bounded;
#   * wrapper commands (sudo/command/exec/env/nice/time) are unwrapped with
#     a bounded skip of their option words — nested/value-taking long
#     options outside the known set are not tracked;
#   * since Round 51 also detected: `$(<file)` reads, encoders/transformers
#     of raw paths (base64/gzip/zcat/tar/sort/uniq/cut/diff; xxd/od/strings
#     were already dump utilities) and cp/mv/ln of a raw path.
#
# Anything not enumerated below is not claimed to be checked. Violations are
# (rule, step_name, excerpt); an empty list means the job is clean under the
# enumerated rules only.

_RAW_LITERAL_RE = re.compile(
    r"/tmp/[^\s'\";|()]*(?:\.(?:log|out|err)\b|"
    r"[^\s'\";|()]*junit[^\s'\";|()]*\.xml\b)")
# Command position: line start, after a shell separator, after a reserved
# word (then/do/else/if/!/while/until), after `$(`, after `-exec`, after
# `xargs`. Deliberately does NOT treat every whitespace as command position
# (that would flag `echo cat x` arguments).
_CMD_POS = r"(?:^|[|;(&]\s*|\b(?:then|do|else|if|while|until)\s+!?\s*|!\s*|\$\(\s*|-exec\s+|xargs\s+)"
_DUMP_UTIL_RE = re.compile(
    _CMD_POS + r"(cat|tail|head|less|more|od|xxd|strings|nl|tac)(?:\s|$)")
_GREP_RAW_RE = re.compile(_CMD_POS + r"grep\b")
_SED_RAW_RE = re.compile(_CMD_POS + r"sed\b")
_AWK_RAW_RE = re.compile(_CMD_POS + r"awk\b")
_QUIET_RE = re.compile(r"(?:^|\s)(?:-[A-Za-z]*q[A-Za-z]*|--quiet)(?=\s|$)")
_REDIRECT_TMP_RE = re.compile(r'>\s*"?/tmp/')
_PY_READ_PRIMITIVE_RE = re.compile(r"\.read\s*\(|read_text\s*\(|readlines\s*\(|writelines\s*\(")
_PY_ITER_OPEN_RE = re.compile(r"(?m)^\s*for\s+\w+\s+in\s+open\s*\(")
_PY_PRINT_RE = re.compile(r"print\s*\(|sys\.stdout\.write\s*\(|sys\.stderr\.write\s*\(")
# A shell/python option word: short or long, with an optional value either
# attached via '=' or as the following non-dash word (e.g. `-o pipefail`).
_OPT = r"--?[A-Za-z][A-Za-z0-9_-]*(?:\s+(?!-)[^\s'\"]+|=[^\s'\"]+)?"
_BASH_C_RE = re.compile(
    r"(?:bash|sh)(?:\s+" + _OPT + r")*\s+-[A-Za-z0-9]*c[A-Za-z0-9]*\s*(['\"])(.*?)\1",
    re.DOTALL)
_PY_C_RE = re.compile(
    r"python[0-9.]*(?:\s+" + _OPT + r")*\s+-c\s*(['\"])(.*?)\1",
    re.DOTALL)
# Encoders/transformers that read their raw file argument and emit transformed
# bytes (base64 of a raw capture is still raw content). od/xxd/strings are
# already dump utilities.
_TRANSFORM_UTIL_RE = re.compile(
    _CMD_POS + r"(base64|gzip|zcat|tar|sort|uniq|cut|diff)(?:\s|$)")
# cp/mv/ln of a raw capture path to another path defeats the raw-class
# naming boundary (the copy is no longer matched by _RAW_LITERAL_RE).
_RAW_PATH_CMD_RE = re.compile(
    _CMD_POS + r"(cp|mv|ln)(?:\s|$)")
# bash $(<file) is a read of the file's full contents.
_RAW_SUBSHELL_READ_RE = re.compile(
    r"\$\(\s*<\s*" + _RAW_LITERAL_RE.pattern)
_PY_HEREDOC_RE = re.compile(
    r"python[0-9.]*\s+(?:-[A-Za-z]+\s+)*-\s*<<\s*(?:-\s*)?(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1")


# Wrapper commands that exec their arguments transparently: the wrapped
# command is where publication happens, so the wrappers are unwrapped before
# the per-command rules are applied (bounded: at most 4 nesting levels).
_WRAP_HEAD_RE = re.compile(r"^\s*(sudo|command|exec|env|nice|time)\b")
_WRAP_OPT_WITH_VALUE = frozenset(
    ['-n', '-u', '-g', '-C', '-D', '-T', '-o', '-a',
     '--user', '--group', '--chdir', '--adjustment', '--unset'])
_WRAP_ASSIGN_RE = re.compile(r"^\s+[A-Za-z_][A-Za-z0-9_]*=[^\s]*")
_WRAP_WORD_RE = re.compile(r"^\s+(-{1,2}[A-Za-z0-9][^\s]*)")
_WRAP_VALUE_RE = re.compile(r"^\s+(?!-)[^\s]+")
_WRAP_DDASH_RE = re.compile(r"^\s+--(?:\s|$)")


def _unwrap_wrapped_command(cmd):
    """Strip leading wrapper commands (sudo/command/exec/env/nice/time).

    Skips each wrapper's option words and (for env/sudo) VAR=value words,
    and a bare `--` end-of-options word. Returns the innermost command.
    Blind spots retained deliberately: `env` reading a script file,
    `sudo -E`-style flags are skipped fine, but long options whose separate
    value is not in _WRAP_OPT_WITH_VALUE leave the value word in place
    (which simply fails to match any raw rule) — disclosed, not chased.
    """
    for _ in range(4):
        head = _WRAP_HEAD_RE.match(cmd)
        if head is None:
            return cmd
        rest = cmd[head.end():]
        while True:
            dash = _WRAP_DDASH_RE.match(rest)
            if dash:
                rest = rest[dash.end():]
                continue
            word = _WRAP_WORD_RE.match(rest)
            if word:
                rest = rest[word.end():]
                if word.group(1) in _WRAP_OPT_WITH_VALUE:
                    value = _WRAP_VALUE_RE.match(rest)
                    if value:
                        rest = rest[value.end():]
                continue
            if head.group(1) in ('env', 'sudo'):
                assign = _WRAP_ASSIGN_RE.match(rest)
                if assign:
                    rest = rest[assign.end():]
                    continue
            break
        cmd = rest.strip()
    return cmd.strip()


def _python_chunks(run_text):
    """Yield the bodies of python -c scripts and python heredocs in a run
    string, as single multi-line chunks."""
    for match in _PY_C_RE.finditer(run_text):
        yield match.group(2)
    for match in _PY_HEREDOC_RE.finditer(run_text):
        tag = match.group(2)
        closing = re.compile(r"(?m)^[ \t]*" + re.escape(tag) + r"[ \t]*$")
        end = closing.search(run_text, match.end())
        if end is not None:
            yield run_text[match.end():end.start()]


def _shell_chunks(run_text):
    """Yield the bodies of bash -c / sh -c scripts as shell text."""
    for match in _BASH_C_RE.finditer(run_text):
        yield match.group(2)


def _split_quote_aware(text, pipelines):
    """Split shell text on top-level separators (outside quotes).

    pipelines=True splits on `;`, `&&`, `||` and newlines (pipeline
    boundaries). pipelines=False splits only on `|` (command boundaries
    inside one pipeline).
    """
    parts, buf, quote = [], "", None
    index = 0
    while index < len(text):
        char = text[index]
        if quote is not None:
            buf += char
            if char == quote:
                quote = None
            index += 1
            continue
        if char in "'\"":
            quote = char
            buf += char
            index += 1
            continue
        two = text[index:index + 2]
        if pipelines and two in ("&&", "||"):
            parts.append(buf)
            buf = ""
            index += 2
            continue
        if pipelines and char in ";\n":
            parts.append(buf)
            buf = ""
            index += 1
            continue
        if not pipelines and char == "|":
            parts.append(buf)
            buf = ""
            index += 1
            continue
        buf += char
        index += 1
    parts.append(buf)
    return [part for part in parts if part.strip()]


def _cmd_violations(command_text, step_name):
    """Apply every shell rule to one run string or subshell body.

    Raw-capture content flows down a pipeline (`grep ... /tmp/x.log | tail`),
    so tool rules are evaluated per pipeline: a raw literal anywhere in the
    pipeline reaches every consumer in it. Redirection and quiet exemptions
    are evaluated on the individual command, never on a neighbour.
    """
    violations = []
    for line_no, line in _logical_lines(command_text):
        stripped = line.strip()
        if stripped.startswith("#"):
            continue
        if re.search(r"(^|\s)set\s+-[A-Za-z]*x(\s|$)", line):
            violations.append(("set-x", step_name, stripped[:160]))
        if _RAW_SUBSHELL_READ_RE.search(line):
            violations.append(("raw-subshell-read", step_name, stripped[:160]))
        # (E) Piping stderr into another stdout producer without a file
        # (`2>&1 | cmd`). `2>&1 ||` (the boolean-OR idiom) is NOT flagged.
        if re.search(r"2>&1\s*\|(?!\|)", line):
            violations.append(("stderr-pipe-to-stdout", step_name, stripped[:160]))
        for pipeline in _split_quote_aware(line, pipelines=True):
            commands = _split_quote_aware(pipeline, pipelines=False)
            pipe_raw = _RAW_LITERAL_RE.search(pipeline) is not None
            for index, command in enumerate(commands):
                cmd = _unwrap_wrapped_command(command.strip())
                excerpt = cmd[:160]
                raw_in_cmd = _RAW_LITERAL_RE.search(cmd) is not None
                redirect = _REDIRECT_TMP_RE.search(cmd) is not None
                downstream = commands[index + 1:]
                piped_to_python = any(
                    re.match(r"python[0-9.]*\b", later.strip()) for later in downstream
                )
                # (A) `tee` (any use) — raw stdout fan-out is the failure
                # mode this contract exists to forbid. No exceptions.
                if re.search(r"(^|[\s|;(])tee(\s|$)", cmd):
                    violations.append(("tee", step_name, excerpt))
                # (A2) Encoders/transformers of a raw capture path: the
                # output still contains raw bytes (base64 is encoding, not
                # redaction). No exceptions.
                if _TRANSFORM_UTIL_RE.search(cmd) and pipe_raw:
                    violations.append(("raw-transform", step_name, excerpt))
                # (A3) cp/mv/ln of a raw capture path: the copy leaves the
                # raw-class naming boundary. No exceptions.
                if _RAW_PATH_CMD_RE.search(cmd) and pipe_raw:
                    violations.append(("raw-path-duplicate", step_name, excerpt))
                # (B) Dump utilities on raw capture paths (.log/.out/.err or
                # junit-named .xml). No exceptions.
                if _DUMP_UTIL_RE.search(cmd) and pipe_raw:
                    violations.append(("raw-log-dump", step_name, excerpt))
                # (B2) grep/sed/awk reading a raw path. Allowed only when the
                # output is suppressed (grep -q / --quiet) or redirected to a
                # file — never when it reaches stdout.
                if _GREP_RAW_RE.search(cmd) and pipe_raw:
                    if not _QUIET_RE.search(cmd) and not redirect:
                        violations.append(("grep-raw", step_name, excerpt))
                if _SED_RAW_RE.search(cmd) and pipe_raw:
                    if not redirect:
                        violations.append(("sed-raw", step_name, excerpt))
                if _AWK_RAW_RE.search(cmd) and pipe_raw:
                    if not redirect:
                        violations.append(("awk-raw", step_name, excerpt))
                # (D) Raw writers that must be redirected to a /tmp capture
                # file or piped into a boundary projector.
                if re.match(r"php\b", cmd) and "vendor/bin/phpunit" in cmd \
                        and not redirect and not piped_to_python:
                    violations.append(("phpunit-raw", step_name, excerpt))
                if re.match(r"composer\b", cmd) and not redirect:
                    violations.append(("composer-raw", step_name, excerpt))
                if "install-wp-tests.sh" in cmd and not redirect \
                        and not cmd.startswith("echo"):
                    violations.append(("install-wp-tests-raw", step_name, excerpt))
                if re.match(r"php\b", cmd) \
                        and "multi-clinic-rest-bootstrap-probe.php" in cmd \
                        and not redirect:
                    violations.append(("red1-probe-raw", step_name, excerpt))
                # (D2) php -r scripts that open a raw capture path print
                # captured output to stdout; no bounded-projection exception.
                if re.match(r"php\s+-r\b", cmd) and raw_in_cmd:
                    violations.append(("php-r-raw", step_name, excerpt))
    return violations


def _python_violations(chunk, step_name):
    """Python-specific rules: no printing captured raw content."""
    violations = []
    raw = _RAW_LITERAL_RE.search(chunk)
    if raw is None:
        return violations
    excerpt = " ".join(chunk.split())[:160]
    # (P1) Full-content read of a raw capture path (open(...).read(),
    # read_text(), readlines(), writelines()) — never publishable.
    if _PY_READ_PRIMITIVE_RE.search(chunk):
        violations.append(("python-raw-read", step_name, excerpt))
    # (P2) Line-iteration over an open() raw literal with a print of what was
    # iterated — the classic full-dump shape.
    if _PY_ITER_OPEN_RE.search(chunk) and _PY_PRINT_RE.search(chunk):
        violations.append(("python-raw-iter-print", step_name, excerpt))
    return violations


def integration_raw_output_violations(ci_yml_text):
    """Raw-log-write violations inside the ci.yml `integration` job.

    Parses the workflow as YAML and examines every `run` script in every
    step of the Integration job: block scalars and inline `run:` commands are
    both dedented by the parser; bash -c / sh -c bodies are analyzed as
    shell; python -c bodies and python heredocs are analyzed as python.
    The Integration job is the only scope — Unit/build/WPCS/release jobs carry
    known pre-existing debt that is deliberately out of scope here.

    Returns a list of (rule, step_name, excerpt) tuples; an empty list means
    the job is clean under the enumerated rules (not proof of no leaks).
    """
    job = integration_job_document(ci_yml_text)
    violations = []
    for step in job.get("steps") or []:
        if not isinstance(step, dict):
            continue
        run = step.get("run")
        if not isinstance(run, str):
            continue
        step_name = str(step.get("name") or "<unnamed>")
        violations.extend(_cmd_violations(run, step_name))
        for chunk in _python_chunks(run):
            violations.extend(_python_violations(chunk, step_name))
        for sub in _shell_chunks(run):
            violations.extend(_cmd_violations(sub, step_name))
            for chunk in _python_chunks(sub):
                violations.extend(_python_violations(chunk, step_name))
    return violations


_BOUNDED_PROJECTION_TOKENS = (
    'integration_evidence.py" junit',
    'integration_evidence.py" suites',
    'integration_evidence.py" evidence',
    'integration_evidence.py" terminal',
    "python3 - <<",
)
_FAILURE_EXIT_RE = re.compile(r'exit\s+"?\$?(?:\{)?(?:phpunit_status|TEST_RC|bin_tests_rc|ledger_status|result|PROBE_RC)')


def junit_projection_before_failure_exit_violations(slice_text):
    """A step that runs PHPUnit and exits nonzero must print a bounded JUnit
    failure summary BEFORE the exit — never after, never raw.

    Tokens that count as a bounded JUnit failure summary (fixed schema, counts
    and source-verified identifiers only):
      bin/integration_evidence.py junit|suites|evidence|terminal ...
      an inline python heredoc printing JUnit counts (python3 - <<...)
    Checks, per step block: every failure-path `exit` AFTER the first
    `vendor/bin/phpunit` occurrence must be preceded (same step) by one of
    those tokens.
    """
    violations = []
    step_blocks = re.split(r"(?m)^      - name: ", slice_text)[1:]
    for block in step_blocks:
        name = block.splitlines()[0].strip() if block.splitlines() else "<unknown>"
        phpunit_at = block.find("vendor/bin/phpunit")
        if phpunit_at == -1:
            continue
        for match in _FAILURE_EXIT_RE.finditer(block):
            if match.start() < phpunit_at:
                continue
            prefix = block[: match.start()]
            if not any(token in prefix for token in _BOUNDED_PROJECTION_TOKENS):
                line_no = prefix.count("\n") + 1
                violations.append((
                    "junit-projection-missing",
                    line_no,
                    "{0}: failure exit without a preceding bounded JUnit summary".format(name),
                ))
    return violations


def ci_step_block(job_text, step_name):
    """Return the YAML block of one named step inside a ci.yml job."""
    lines = job_text.splitlines()
    start = None
    needle = "- name: {0}".format(step_name)
    for index, line in enumerate(lines):
        if line.strip() == needle:
            start = index
            break
    if start is None:
        raise AssertionError("step not found: {0}".format(step_name))
    end = len(lines)
    for index in range(start + 1, len(lines)):
        if re.match(r"^\s{6}- ", lines[index]):
            end = index
            break
    return "\n".join(lines[start:end])


class WorkflowContractTests(unittest.TestCase):
    def test_facility_matrix_runs_on_main_push_and_all_supported_databases(self):
        text = workflow("facility-matrix-acceptance.yml")
        events = text.split("on:\n", 1)[1].split("\nconcurrency:", 1)[0]
        self.assertRegex(events, r"(?m)^  pull_request:\n    branches: \[main\]$")
        self.assertRegex(events, r"(?m)^  push:\n    branches: \[main\]$")
        matrix = text.split("      matrix:\n", 1)[1].split("\n\n    services:", 1)[0]
        self.assertIn("database: mariadb", matrix)
        self.assertIn("image: mariadb:11.4.13", matrix)
        self.assertIn("database: mysql", matrix)
        self.assertIn("image: mysql:8", matrix)
        self.assertIn("name: Facility matrix (${{ matrix.database }})", text)
        self.assertIn("cancel-in-progress: ${{ github.ref != 'refs/heads/main' }}", text)
        self.assertIn("main-run-{0}', github.run_id)", text)

    def test_closure_and_pilot_gates_keep_branch_independent_pr_triggers(self):
        for filename in ("closure-gate.yml", "pilot-gate.yml"):
            with self.subTest(filename=filename):
                text = workflow(filename)
                events = text.split("on:\n", 1)[1].split("\nconcurrency:", 1)[0]
                self.assertRegex(events, r"(?m)^  pull_request:\n    branches: \[main\]$")
                self.assertRegex(events, r"(?m)^  push:\n    branches: \[main\]$")
                self.assertNotIn("arena/**", events)
                self.assertIn("cancel-in-progress: ${{ github.ref != 'refs/heads/main' }}", text)
        self.assertIn("name: Closure Gate (GO-LIVE evidence closure)", workflow("closure-gate.yml"))
        self.assertIn("name: Pilot/Staging Readiness Gate", workflow("pilot-gate.yml"))

    def test_disposable_demo_lane_is_path_scoped_to_its_own_files(self):
        """The lane must not act as a global gate: it runs only for changes to
        its own files, and its isolation decision is made by an executable,
        unit-tested script rather than an inline conditional."""
        text = workflow("disposable-demo-installer.yml")
        events = text.split("on:\n", 1)[1].split("\npermissions:", 1)[0]

        self.assertEqual(events.count("    paths:\n"), 2, events)
        for path in (
            "'.github/workflows/disposable-demo-installer.yml'",
            "'.github/scripts/companion-isolation.py'",
            "'tools/disposable-demo-installer/**'",
            "'bin/build-demo-installer.sh'",
            "'.github/actions/playwright-browsers/**'",
            "'docs/demo-setup-fa.md'",
        ):
            self.assertEqual(events.count(path), 2, path)
        self.assertIn("workflow_dispatch:", events)

        # The old inline guard let mixed changes through as NOT-APPLICABLE.
        self.assertNotIn("NOT-APPLICABLE", text)
        self.assertIn("Companion/production isolation (fail closed on mixed changes)", text)
        self.assertIn(
            ".github/scripts/companion-isolation.py", text,
            "the isolation decision must come from the unit-tested script",
        )

    def test_admin_narrow_acceptance_is_real_chromium_at_320_and_390(self):
        """B-3 must be proven in a real browser at 320px and 390px for the ten
        named slugs — not asserted from markup."""
        text = workflow("pilot-gate.yml")
        self.assertIn("Admin narrow RTL acceptance (Chromium — 320/390 × 10 slugs)", text)
        responsive_job = text.split("  responsive:\n", 1)[1].split("\n  # ====", 1)[0]
        self.assertIn("python3 bin/pilot-admin-narrow.py | tee /tmp/admin-narrow.log", responsive_job)
        self.assertIn('test "${PIPESTATUS[0]}" -eq 0', responsive_job)

        script = (ROOT / "clinic-practice-management" / "bin" / "pilot-admin-narrow.py").read_text(encoding="utf-8")
        # Two narrow widths, exactly.
        self.assertIn('("narrow-320", 320, 800)', script)
        self.assertIn('("narrow-390", 390, 844)', script)
        for slug in (
            "cpms-dashboard",
            "cpms-settings",
            "cpms-system",
            "cpms-sms",
            "cpms-custom-roles",
            "cpms-finance-delegation",
            "cpms-organizations",
            "cpms-organization-managers",
            "cpms-organization-manager-bootstrap",
            "cpms-shortcodes",
        ):
            self.assertIn(f'("{slug}"', script, slug)
        # Persian RTL, overflow, keyboard and role-denial assertions must exist.
        for needle in ('rtl', "scrollWidth", "keyboard-reaches-section-nav", "ROLE_MATRIX"):
            self.assertIn(needle, script)
        self.assertIn("FAIL admin narrow RTL acceptance", script)

    def test_org_manager_narrow_screen_stylesheet_keeps_the_assets_contract(self):
        """The two organization-manager pages must be fixed with their own
        scoped stylesheet, not by widening CpmsAssets::PAGES."""
        assets = (ROOT / "clinic-practice-management" / "src" / "Admin" / "CpmsAssets.php").read_text(encoding="utf-8")
        allowlist = assets.split("private const PAGES = [", 1)[1].split("];", 1)[0]
        self.assertNotIn("'cpms-organization-managers'", allowlist)
        self.assertNotIn("'cpms-organization-manager-bootstrap'", allowlist)

        css = (ROOT / "clinic-practice-management" / "assets" / "css" / "cpms-org-manager.css").read_text(encoding="utf-8")
        self.assertIn("body.cpms-org-manager", css)
        self.assertIn("@media (max-width: 782px)", css)
        self.assertIn("width: 100%", css)
        self.assertIn("overflow-x: auto", css)
        self.assertNotIn("overflow: hidden", css)

    def test_lane_runs_its_own_contract_and_security_suite(self):
        """A workflow-only edit must not be able to disable the lane's controls."""
        text = workflow("disposable-demo-installer.yml")
        static_job = text.split("  static:\n", 1)[1].split("\n  real-wordpress:", 1)[0]
        self.assertIn(
            "python3 -m unittest discover -s .github/workflows -p 'test_workflow_contracts.py' -v",
            static_job,
        )


    def test_tripwire_production_scan_still_runs_after_an_earlier_failing_step(self):
        """Fail-closed diagnostics ordering.

        The tripwire job runs contract/self-test steps BEFORE the production
        tenant-hardcode scan. A step failure (a genuinely red contract) must fail
        the job AND must not erase the production scan: with the default
        `if: success()` the scan is skipped and a red tripwire job carries no
        evidence about the production runtime.
        """
        text = workflow("ci.yml")
        job = ci_job_block(text, "tripwire")

        contract_step = ci_step_block(job, "Workflow evidence and acceptance contract tests")
        scan_step = ci_step_block(job, "Scan production for tenant hardcodes")

        # Ordering that makes the risk real: the contract suite runs first.
        self.assertLess(
            job.index("Workflow evidence and acceptance contract tests"),
            job.index("Scan production for tenant hardcodes"),
        )
        self.assertIn("test_workflow_contracts.py", contract_step)
        self.assertIn("bin/tenant-tripwire.py --allowlist bin/tenant-tripwire-allowlist.json", scan_step)

        # The scan must keep its real work and must not be success()-gated.
        self.assertNotRegex(scan_step, r"(?m)^\s*if: success\(\)\s*$")
        self.assertNotIn("continue-on-error", scan_step)
        self.assertRegex(
            scan_step,
            r"(?m)^        (if: \$\{\{ !cancelled\(\) \}\}|if: always\(\))$",
            "the production tenant-hardcode scan must run after an earlier step failure",
        )

    def test_authz_coverage_measurement_and_fail_closed_guard_survive_an_earlier_failure(self):
        """Fail-closed diagnostics ordering for the PCOV coverage guard.

        When the full Integration step failed, the focused measurement was
        SKIPPED and the fail-closed guard then reported absent artifacts, which
        reads like missing coverage instead of "the measurement never ran".
        The measurement must run whenever the job is still running, and the guard
        must stay fail-closed, learn the measurement outcome, and say NOT RUN.
        """
        text = workflow("ci.yml")
        job = ci_job_block(text, "integration")

        measure = ci_step_block(job, "AuthorizationService focused coverage run (PCOV; existing Integration test)")
        guard = ci_step_block(job, "AuthorizationService coverage guard (fail-closed; includes negative-control self-test)")

        self.assertIn("id: authz_coverage_run", measure)
        self.assertNotRegex(measure, r"(?m)^\s*if: success\(\)\s*$")
        self.assertRegex(
            measure,
            r"(?m)^        (if: \$\{\{ !cancelled\(\) \}\}|if: always\(\))$",
            "the focused coverage measurement must run after an earlier integration failure",
        )
        # The measurement itself must stay a real PCOV run on the existing test.
        self.assertIn("-d pcov.enabled=1", measure)
        self.assertIn("tests/Integration/AuthorizationServiceTest.php", measure)
        self.assertIn("echo $? > /tmp/authz-coverage/phpunit.rc", measure)

        # The guard keeps running unconditionally and stays fail-closed.
        self.assertIn("if: always()", guard)
        self.assertIn("AUTHZ_COVERAGE_RUN_OUTCOME: ${{ steps.authz_coverage_run.outcome }}", guard)
        self.assertIn("NOT RUN", guard)
        self.assertIn("bin/authorization-coverage-guard.py --test", guard)
        for required in (
            "--clover /tmp/authz-coverage/clover.xml",
            "--driver-json /tmp/authz-coverage/driver.json",
            "--phpunit-rc-file /tmp/authz-coverage/phpunit.rc",
        ):
            self.assertIn(required, guard)
        self.assertIn('exit "$GUARD_RC"', guard)
        # NOT RUN / SKIPPED is never converted into PASS.
        self.assertNotIn("exit 0", guard)
        self.assertNotIn("continue-on-error", guard)


class StandaloneAdminAcceptanceTests(unittest.TestCase):
    def test_standalone_lane_uploads_the_inner_zip_through_real_wp_admin_on_both_engines(self):
        text = workflow("standalone-demo.yml")
        job = text.split("  real-wordpress:\n", 1)[1].split("  teardown-safety:\n", 1)[0]
        self.assertIn("db_image: mysql:8", job)
        self.assertIn("db_image: mariadb:11.4.13", job)
        self.assertIn("python3 tests/scoped-demo/browser_install.py", job)
        self.assertIn("plugin-install.php?tab=upload", (ROOT / "tests" / "scoped-demo" / "browser_install.py").read_text())
        self.assertIn("Plugin_Upgrader", (ROOT / "tests" / "scoped-demo" / "ci-observer.php").read_text())
        # The acceptance install/activate must not be a WP-CLI substitution.
        install = job.split("Real wp-admin ZIP upload and Activate Plugin", 1)[1].split("Enable and provision", 1)[0]
        self.assertNotIn("wp plugin install /tmp/cpsa-ci/cpms-disposable-demo", install)
        self.assertNotIn("wp plugin activate cpms-disposable-demo", install)
        self.assertIn("wp plugin is-active cpms-disposable-demo", install)
        # Non-empty WordPress is seeded BEFORE the admin upload.
        self.assertLess(job.index("Seed a genuinely non-empty WordPress BEFORE"), job.index("Real wp-admin ZIP upload"))

    def test_ci_observer_rotates_only_acceptance_capture_and_refreshes_plugin_cache(self):
        observer = (ROOT / "tests" / "scoped-demo" / "ci-observer.php").read_text()
        self.assertIn("getenv( 'CPSD_CI_CAPTURE' )", observer)
        self.assertIn("wp_clean_plugins_cache", observer)
        self.assertIn("$upgrader->result = $result;", observer)
        self.assertIn("wp-admin/plugins.php", (ROOT / "tests" / "scoped-demo" / "browser_install.py").read_text())
        text = workflow("standalone-demo.yml")
        job = text.split("  real-wordpress:\n", 1)[1].split("  teardown-safety:\n", 1)[0]
        server = job.split("Start PHP built-in WordPress server", 1)[1].split("Real wp-admin ZIP upload", 1)[0]
        self.assertIn("CPSD_CI_CAPTURE: '1'", server)
        provision = job.split("Provision the scoped demo on the non-empty site", 1)[1].split("Export the read-only portal identifiers", 1)[0]
        self.assertIn("CPSD_CI_CAPTURE: '1'", provision)
        integration = job.split("Enable and provision the scoped demo", 1)[1].split("Provision the scoped demo on the non-empty site", 1)[0]
        self.assertNotIn("CPSD_CI_CAPTURE", integration)

    def test_standalone_evidence_never_uploads_one_time_credentials_or_otp_codes(self):
        text = workflow("standalone-demo.yml")
        job = text.split("  real-wordpress:\n", 1)[1].split("  teardown-safety:\n", 1)[0]
        upload = job.split("actions/upload-artifact@v4", 1)[1]
        self.assertNotIn("persona-credentials.json", upload)
        self.assertNotIn("synthetic-otp.json", upload)
        self.assertNotIn("/tmp/cpsa-ci/private", upload)
        self.assertIn("recovery-browser.json", upload)
        self.assertIn("admin-install-browser.json", upload)

    def test_standalone_lane_runs_eight_personas_and_wrong_mobile_recovery(self):
        text = workflow("standalone-demo.yml")
        self.assertIn("python3 tests/scoped-demo/browser_scoped.py journeys", text)
        self.assertIn("python3 tests/scoped-demo/browser_recovery.py", text)
        self.assertIn("tests/scoped-demo/recovery-acceptance.php", text)
        recovery = (ROOT / "tests" / "scoped-demo" / "browser_recovery.py").read_text()
        self.assertIn("09009999999", recovery)
        self.assertIn("SCOPED_FOREIGN_TENANT_ROW", recovery)


    def test_scoped_lane_stages_and_repairs_the_owner_incident_in_one_cycle(self):
        """The Owner's failure (sealed fixture alive, baseline gone) must be
        reproduced, repaired and proven inside the SAME lane, on both databases.
        Splitting it across workflows would let the repair be certified on a
        fixture that was never in the incident state."""
        text = workflow("scoped-demo.yml")
        self.assertIn("tests/scoped-demo/owner-incident.php", text)
        self.assertIn("CPSD_INCIDENT_MODE=stage", text)
        self.assertIn("CPSD_INCIDENT_MODE=finish", text)
        self.assertIn("python3 tests/scoped-demo/browser_scoped.py repair", text)
        # Stage must run before the repair phase, and the row-effect proof after it.
        self.assertLess(text.index("CPSD_INCIDENT_MODE=stage"),
                        text.index("browser_scoped.py repair"))
        self.assertLess(text.index("browser_scoped.py repair"),
                        text.index("CPSD_INCIDENT_MODE=finish"))
        # Both supported databases, not one.
        matrix = text.split("      matrix:\n", 1)[1].split("\n\n    services:", 1)[0]
        self.assertIn("mysql-8", matrix)
        self.assertIn("mariadb-11.4.13", matrix)

    def test_scoped_lane_proves_eight_passwordless_sessions_not_just_markup(self):
        text = workflow("scoped-demo.yml")
        self.assertIn("python3 tests/scoped-demo/browser_scoped.py sessions", text)
        phase = text.split("sessions phase", 1)[1].split("Aggregate", 1)[0]
        self.assertIn("CPSD_EVIDENCE_DIR", phase)
        # The phase results are aggregated and can fail the job.
        aggregate = text.split("Aggregate the Owner-incident and persona-session", 1)[1].split("- name:", 1)[0]
        self.assertIn("('repair', 'sessions')", aggregate)
        self.assertIn("sys.exit(1)", aggregate)
        # The one-time ticket and the one-time passwords are never uploaded as
        # evidence, directly or by glob. Only path lines count: a comment that
        # names the file is documentation, an uploaded path is a leak.
        upload = text.split("actions/upload-artifact@v4", 1)[-1]
        paths = [line.strip() for line in upload.splitlines() if line.strip().startswith("/tmp/")]
        self.assertTrue(paths, "the upload block should list evidence paths")
        for secret in ("persona-credentials.json", "cpsd_persona_entry", "synthetic-otp.json"):
            self.assertFalse([p for p in paths if secret in p],
                             f"{secret} must never be uploaded as CI evidence")

    def test_the_session_drill_isolates_contexts_and_tries_the_abuse_cases(self):
        """A drill that reused one cookie jar would 'pass' while proving nothing:
        same-browser transitions overwrite the administrator session. The phase
        must therefore open separate browser contexts and attempt replay,
        anonymous and cross-persona abuse."""
        source = (ROOT / "tests" / "scoped-demo" / "browser_scoped.py").read_text(encoding="utf-8")
        body = source.split("def sessions_phase(", 1)[1].split("\ndef ", 1)[0]
        self.assertIn("browser.new_context(", body)
        for attempt in ("replay", "unauthenticated", "mint a ticket for another persona"):
            self.assertIn(attempt, body, attempt)
        # Every persona is exercised, not a sample of them.
        self.assertIn("for persona in PERSONAS:", body)
        # The honest limitation is asserted to be on the page, not assumed.
        self.assertIn("data-cpsd-cookie-note", body)
        # No claim of per-tab isolation anywhere in the shipped page or the drill.
        for text in (body,
                     (ROOT / "tools" / "disposable-demo-installer" / "includes"
                      / "class-cpms-demo-scoped-admin.php").read_text(encoding="utf-8")):
            self.assertNotIn("جداسازی زبانه", text)


class CompanionIsolationTests(unittest.TestCase):
    """Executable tests for the companion/production isolation rule."""

    SCRIPT = ROOT / ".github" / "scripts" / "companion-isolation.py"

    def _run(self, paths):
        import subprocess
        import tempfile

        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as handle:
            handle.write("\n".join(paths) + "\n")
            listing = handle.name
        try:
            return subprocess.run(
                [sys.executable, str(self.SCRIPT), "--changed-files", listing],
                capture_output=True, text=True, check=False,
            )
        finally:
            os.unlink(listing)

    def test_production_only_change_is_allowed(self):
        result = self._run(["clinic-practice-management/src/Admin/SystemPage.php"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("companion-isolation: PASS", result.stdout)

    def test_companion_only_change_is_allowed_when_production_is_untouched(self):
        result = self._run(["tools/disposable-demo-installer/uninstall.php"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("companion-isolation: PASS", result.stdout)

    def test_mixed_companion_and_production_change_fails_closed(self):
        result = self._run([
            "tools/disposable-demo-installer/uninstall.php",
            "clinic-practice-management/src/Admin/SystemPage.php",
        ])
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("companion-isolation: FAIL", result.stdout)
        self.assertIn("mixed change", result.stdout)

    def test_build_script_counts_as_companion(self):
        result = self._run([
            "bin/build-demo-installer.sh",
            "clinic-practice-management/src/Bootstrap/App.php",
        ])
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)

    def test_a_new_shared_evidence_script_is_lane_plumbing_not_a_boundary_change(self):
        """A new .github/scripts/ file is unclassified until it is named here.

        That fail-closed rule is right, and it is why adding the shared PR
        comment transport had to be classified explicitly rather than silently
        landing as an "other" path.
        """
        result = self._run([".github/scripts/pr-evidence-comment.sh"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("lane files: 1", result.stdout)

    def test_workflow_only_change_is_allowed(self):
        result = self._run([".github/workflows/disposable-demo-installer.yml"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_unreadable_change_list_fails_closed(self):
        import subprocess

        result = subprocess.run(
            [sys.executable, str(self.SCRIPT), "--changed-files", "/nonexistent/changed.txt"],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 2, result.stdout + result.stderr)

    def test_docs_only_change_is_out_of_scope(self):
        result = self._run(["docs/demo-setup-fa.md"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_compatibility_automation_counts_as_the_lane_side(self):
        """A gate that is rewritten together with production proves nothing.

        The companion-compat scripts, the scoped-demo tests and the lane
        workflows are executable evidence about the same boundary, so they are
        classified rather than silently ignored.
        """
        for path in (
            ".github/scripts/companion-compat/evaluate.py",
            "tests/companion-compat/test_compatibility_gate.py",
            "tests/scoped-demo/test_scoped_safety_regressions.py",
            "tests/disposable-site/test_x.py",
        ):
            result = self._run([path, "clinic-practice-management/src/Bootstrap/App.php"])
            self.assertEqual(result.returncode, 1, path + ": " + result.stdout)
            self.assertIn("mixed change", result.stdout)

    def test_compatibility_automation_alone_is_allowed(self):
        result = self._run([
            ".github/scripts/companion-compat/evaluate.py",
            "tests/scoped-demo/test_scoped_safety_regressions.py",
        ])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("compatibility files: 2", result.stdout)

    def test_lane_workflows_are_classified(self):
        result = self._run([".github/workflows/scoped-demo.yml", ".github/workflows/companion-compat.yml"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("lane files: 2", result.stdout)

    def test_scoped_demo_documentation_is_classified(self):
        result = self._run(["docs/scoped-demo/ownership-map.md"])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("documentation files: 1", result.stdout)

    def test_unclassified_lane_file_fails_closed(self):
        result = self._run(["tools/some-new-lane-tool.sh"])
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("unclassified lane file", result.stdout)
        mixed = self._run([
            "tools/some-new-lane-tool.sh",
            "clinic-practice-management/src/Bootstrap/App.php",
        ])
        self.assertEqual(mixed.returncode, 1, mixed.stdout + mixed.stderr)

    def test_persian_wc_diagnosis_is_named_and_acceptance_requires_stage_b(self):
        text = workflow("persian-coexistence.yml")
        self.assertIn(
            "name: Persian WooCommerce 10.0.7 Stage-A-only diagnosis (CPMS intentionally absent)",
            text,
        )
        acceptance = text.split("  coexistence-persian-woocommerce-acceptance:\n", 1)[1]
        acceptance = acceptance.split("\n  coexistence-persian-woocommerce-sms:", 1)[0]
        self.assertIn("coexistence_group: persian-woocommerce-acceptance", acceptance)
        self.assertIn("coexistence_stage_b: true", acceptance)
        self.assertRegex(acceptance, r"(?m)^    uses: \.\/\.github\/workflows\/real-wp-acceptance\.yml$")
        self.assertRegex(acceptance, r"(?m)^    name: Persian WooCommerce 10\.0\.7 \+ CPMS Stage A/B acceptance$")
        reusable = workflow("real-wp-acceptance.yml")
        self.assertIn("run.py begin-b", reusable)
        self.assertIn("id: cpms_install", reusable)
        self.assertIn("run.py finalize", reusable)

    def test_workflow_contract_suite_is_run_by_ci(self):
        text = workflow("ci.yml")
        self.assertIn("Workflow evidence and acceptance contract tests", text)
        self.assertIn("python3 -m unittest discover -s .github/workflows -p 'test_workflow_contracts.py' -v", text)
        self.assertIn("working-directory: ${{ github.workspace }}", text)
        self.assertIn("name: Pilot benchmark report safety self-tests", text)
        self.assertIn("python3 bin/pilot-bench-report.py --test", text)
        self.assertIn("working-directory: ${{ github.workspace }}/clinic-practice-management", text)

    def test_all_fifteen_evidence_publishers_use_shared_fail_loud_router(self):
        for filename, expected_count in (("closure-gate.yml", 2), ("pilot-gate.yml", 13)):
            with self.subTest(filename=filename):
                text = workflow(filename)
                self.assertEqual(text.count(".github/scripts/post-pr-evidence.sh"), expected_count)
                self.assertNotIn('gh pr list --head "${{ github.ref_name }}"', text)
                self.assertNotRegex(text, r'gh pr comment "\$PR"[^\n]*\|\|')
                self.assertIn("CPMS_PR_NUMBER: ${{ github.event.pull_request.number }}", text)
        helper = PUBLISHER.read_text(encoding="utf-8")
        self.assertIn('case "$EVENT" in', helper)
        self.assertIn('PR_NUMBER=${CPMS_PR_NUMBER:-}', helper)
        self.assertIn("Required evidence comment to PR", helper)
        self.assertIn("cat \"$EVIDENCE\" >> \"$GITHUB_STEP_SUMMARY\"", helper)
        # The publisher may only touch PR comments: `pull-requests: write` is the
        # permission the workflows already grant, and no endpoint beyond the
        # issue-comment API is used (no broadened commit-comment scope).
        logical = helper.replace("\\\n", " ")
        for segment in logical.split("gh api")[1:]:
            command = re.split(r"\s\|\||\s&&|;", segment)[0]
            self.assertIn(
                "repos/$REPOSITORY/issues",
                command,
                f"publisher must stay inside the PR comment API, got: {command.strip()}",
            )
        # Publication is delegated to ONE shared transport, so the ceiling is
        # resolved identically everywhere. The publisher keeps PR-target
        # resolution and the run-summary fallback, and it must still fail loudly
        # when publication really fails.
        self.assertIn("pr-evidence-comment.sh", helper, "publication is shared, not duplicated")
        self.assertIn("--family \"$EVIDENCE_FAMILY\"", helper)
        self.assertIn('EVIDENCE_FAMILY=${EVIDENCE_FAMILY:-${STREAM_FAMILY:-$(basename "$EVIDENCE" .md)}}', helper,
                      "each evidence stream owns its own comment, so steps cannot overwrite each other")
        self.assertLess(
            helper.index("EDIT") if "EDIT" in helper else helper.index("--family"),
            helper.index("::error::Required evidence comment to PR"),
            "publication must be attempted before any failure classification",
        )

    def test_every_evidence_stream_names_its_own_comment(self):
        """Two steps in one job must never overwrite each other's evidence.

        The sticky transport keys a comment by family, so a family has to be
        unique per evidence STREAM, not per workflow.
        """
        for filename in ("closure-gate.yml", "pilot-gate.yml", "disposable-site-acceptance.yml"):
            with self.subTest(filename=filename):
                text = (WORKFLOWS / filename).read_text(encoding="utf-8")
                invocations = [
                    line for line in text.splitlines()
                    if "post-pr-evidence.sh" in line and "call below publishes" not in line
                ]
                self.assertTrue(invocations)
                families = []
                for line in invocations:
                    match = re.search(r'post-pr-evidence\.sh"?\s+(\S+)\s+"([^"]+)"\s*$', line)
                    self.assertIsNotNone(match, f"evidence stream must name its family: {line.strip()}")
                    families.append(match.group(2))
                self.assertEqual(sorted(families), sorted(set(families)), "families must be unique")

    def test_pilot_gate_compatibility_gate_runs_from_the_repository_root(self):
        """The job's default working directory is the plugin, not the repo root.

        `.github/scripts/companion-compat/evaluate.py` therefore could not be
        opened at all: python exited 2 and the gate had never actually run.
        """
        text = workflow("pilot-gate.yml")
        block = text.split("- name: Companion compatibility gate (blocks publication on mismatch)", 1)[1]
        block = block.split("\n      - name:", 1)[0]
        self.assertIn("working-directory: ${{ github.workspace }}", block)
        self.assertIn("test -f .github/scripts/companion-compat/evaluate.py", block)

    def test_pilot_gate_compatibility_gate_reports_the_real_exit_status(self):
        """`$?` inside `if ! cmd; then` is the NEGATION's status, i.e. always 0.

        The previous form therefore printed "failed with exit 0" and executed
        `exit 0`, so a failed compatibility gate made the release gate green.
        """
        text = workflow("pilot-gate.yml")
        block = text.split("- name: Companion compatibility gate (blocks publication on mismatch)", 1)[1]
        block = block.split("\n      - name:", 1)[0]
        self.assertIn("GATE_EXIT=$?", block, block)
        self.assertIn('if [ "${GATE_EXIT}" -ne 0 ]; then', block, block)
        self.assertNotIn('exit "${code}"', block, block)
        self.assertNotIn("if ! python3 .github/scripts/companion-compat/evaluate.py", block, block)

    def test_failure_dump_guards_and_bounds_are_preserved(self):
        text = workflow("pilot-gate.yml")
        self.assertEqual(text.count("- name: Debug dump به PR"), 3)
        self.assertEqual(text.count("if: failure()"), 3)
        self.assertEqual(text.count('tail -50 "$f"'), 3)
        self.assertEqual(text.count("bash \"$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh\" /tmp/debug.md"), 3)

    def test_closure_evidence_redacts_full_and_masked_install_identifiers(self):
        text = workflow("closure-gate.yml")
        self.assertIn("$installIdValid = isset($meta['install_id'])", text)
        self.assertIn("unset($meta['install_id'], $meta['install_id_masked']);", text)
        self.assertIn("json_encode($meta)", text)

    def test_benchmark_runs_on_pr_and_retains_other_events_and_measurement_matrix(self):
        text = workflow("pilot-gate.yml")
        self.assertIn("CPMS_PR_NUMBER: ${{ github.event.pull_request.number }}", text)
        self.assertIn(
            "if: ${{ github.event_name == 'pull_request' || github.event_name == 'workflow_dispatch' || github.event_name == 'schedule' || github.event_name == 'push' }}",
            text,
        )
        self.assertIn("- name: Performance benchmark — ab (cold + warm, c=1/10/50/100) → JSON/MD artifact", text)
        self.assertIn("measure cold 'GET /health", text)
        self.assertIn("measure cold 'GET /availability", text)
        self.assertIn("measure cold 'GET /wp-json/", text)
        self.assertEqual(text.count("for C in 10 50 100; do measure warm"), 2)
        self.assertIn("3000 \"$C\" 2", text)
        self.assertIn("1500 \"$C\" 2", text)
        self.assertIn("1000 50 2", text)
        self.assertIn("NO latency threshold is asserted here", text)


class SensitiveEvidenceProjectionTests(unittest.TestCase):
    def render(self, suite, fixture, results):
        with tempfile.TemporaryDirectory(prefix="pilot-safe-evidence-") as tmp:
            tmp_path = Path(tmp)
            env_file = tmp_path / "fixture.env"
            results_file = tmp_path / "results.json"
            env_file.write_text(fixture, encoding="utf-8")
            results_file.write_text(json.dumps(results), encoding="utf-8")
            return subprocess.run(
                [
                    "python3",
                    str(SAFE_PILOT_EVIDENCE),
                    "--suite",
                    suite,
                    "--env-file",
                    str(env_file),
                    "--results-file",
                    str(results_file),
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
                check=False,
            )

    def test_slice3_fixture_fields_nine_and_ten_and_result_details_are_never_projected(self):
        login = "SYNTHETIC_LOGIN_SENTINEL_DO_NOT_PUBLISH"
        password = "SYNTHETIC_PASSWORD_SENTINEL_DO_NOT_PUBLISH"
        fixture = (
            "SLICE3_PAGE=https://example.invalid/pilot|1|2|3|4|5|6|7|"
            f"{login}|{password}\n"
        )
        safe_results = {
            "summary": {"ok": True, "runs": 2, "failed": []},
            "results": [{"key": "slice3-mobile-390-01", "status": "PASS"}],
        }
        result = self.render("slice3", fixture, safe_results)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Checks: 1 passed; 0 failed", result.stdout)
        self.assertNotIn(login, result.stdout)
        self.assertNotIn(password, result.stdout)

        # Even if a future runner accidentally adds an arbitrary detail field,
        # the renderer rejects the schema and emits only a fixed safe fallback.
        unsafe_results = {
            "summary": {"ok": True, "runs": 2, "failed": []},
            "results": [
                {
                    "key": "slice3-mobile-390-01",
                    "status": "PASS",
                    "detail": f"{login} {password} otp nonce cookie session token",
                }
            ],
        }
        rejected = self.render("slice3", fixture, unsafe_results)
        self.assertNotEqual(rejected.returncode, 0)
        self.assertNotIn(login, rejected.stdout)
        self.assertNotIn(password, rejected.stdout)
        self.assertIn("contents withheld", rejected.stdout)

    def test_portal_cancel_projection_withholds_login_password_and_patient_ids(self):
        login = "PORTAL_LOGIN_SENTINEL_DO_NOT_PUBLISH"
        password = "PORTAL_PASSWORD_SENTINEL_DO_NOT_PUBLISH"
        fixture = (
            "PORTAL_CANCEL=" + login + "|" + password
            + "|12|PATIENT_ID_SENTINEL|21|22|23|24|25|26,27,28|29\n"
        )
        results = {
            "summary": {"ok": True, "runs": 2, "failed": []},
            "results": [{"key": "portal-cancel-mobile-390", "status": "PASS"}],
        }
        result = self.render("portal-cancel", fixture, results)
        self.assertEqual(result.returncode, 0, result.stderr)
        for sentinel in (login, password, "PATIENT_ID_SENTINEL"):
            self.assertNotIn(sentinel, result.stdout)
        self.assertIn("Result: PASS", result.stdout)

    def test_workflow_uses_safe_projection_and_removes_confirmed_raw_identifier_rows(self):
        text = workflow("pilot-gate.yml")
        self.assertIn("pilot-safe-evidence.py --suite slice3", text)
        self.assertIn("pilot-safe-evidence.py --suite portal-cancel", text)
        self.assertNotIn("cat /tmp/slice3.env 2>/dev/null", text)
        self.assertIn('cat /tmp/slice3.env >> "$GITHUB_ENV"', text)
        self.assertNotIn("cut -d'|' -f1,3- /tmp/portal-cancel.env", text)
        self.assertNotIn("PROFILE_PUBLIC", text)
        self.assertNotIn("DOCTOR_PORTAL_PUBLIC", text)
        self.assertIn("PROFILE_FIXTURE_OK", text)
        self.assertIn("DOCTOR_PORTAL_FIXTURE_OK", text)


class FixtureEnvironmentContractTests(unittest.TestCase):
    def test_doctor_portal_internal_finance_handoff_is_restored_without_public_projection(self):
        doctor_fixture = (
            ROOT / "clinic-practice-management" / "tests" / "fixtures" / "pilot-doctor-portal-fixture.php"
        ).read_text(encoding="utf-8")
        finance_fixture = (
            ROOT / "clinic-practice-management" / "tests" / "fixtures" / "pilot-finance-board-fixture.php"
        ).read_text(encoding="utf-8")
        finance_browser = (
            ROOT / "clinic-practice-management" / "bin" / "pilot-finance-board.py"
        ).read_text(encoding="utf-8")
        pilot = workflow("pilot-gate.yml")

        self.assertRegex(
            doctor_fixture,
            r"\$financeContext\s*=\s*implode\(\s*'\|'\s*,\s*\[\s*\$url\s*,\s*"
            r"\(string\)\s*\$oneClinic\s*,\s*\(string\)\s*\$oneLoc\s*\]\s*\)\s*;",
        )
        self.assertIn("'DOCTOR_PORTAL_PUBLIC=' . $financeContext", doctor_fixture)
        self.assertIn("getenv('DOCTOR_PORTAL_PUBLIC')", finance_fixture)
        self.assertIn("$clinicId = (int) $portalFixture[1]", finance_fixture)
        self.assertIn("$locationId = (int) $portalFixture[2]", finance_fixture)

        doctor_setup = pilot.split("- name: Doctor Portal fixture", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn('cat /tmp/doctor-portal.env >> "$GITHUB_ENV"', doctor_setup)
        self.assertIn("grep '^DOCTOR_PORTAL_FIXTURE_OK '", doctor_setup)
        self.assertNotIn("log_tail=", doctor_setup)
        self.assertNotIn("tail -c", doctor_setup)
        self.assertNotIn("DOCTOR_PORTAL_PUBLIC", doctor_setup)

        finance_setup = pilot.split("- name: Phase 12 Finance board fixture", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn('cat /tmp/finance-board.env >> "$GITHUB_ENV"', finance_setup)
        self.assertIn("Finance board fixture environment loaded; values withheld.", finance_setup)
        self.assertNotIn("grep '^FINANCE_BOARD_URL='", finance_setup)
        self.assertIn("fixture values and raw diagnostics withheld", finance_setup)
        self.assertNotIn("log_tail=", finance_setup)
        self.assertNotIn("tail -c", finance_setup)

        finance_browser_step = pilot.split("- name: Phase 12 Finance board — real browser", 1)[1].split("\n      - name:", 1)[0]
        self.assertIn("dynamic diagnostics withheld", finance_browser_step)
        self.assertNotIn("fail_lines=", finance_browser_step)

        evidence = pilot.split("- name: Post Doctor Portal evidence to PR", 1)[1].split("\n      - name:", 1)[0]
        self.assertNotIn("DOCTOR_PORTAL_PUBLIC", evidence)
        self.assertNotIn("doctor-portal.env", evidence)
        self.assertIn("DOCTOR_PORTAL_FIXTURE_OK", evidence)

        self.assertNotIn("FINANCE_BOARD_ACCOUNTANT_PUBLIC", finance_fixture)
        self.assertIn("FINANCE_BOARD_ACCOUNTANT_FIXTURE_OK", finance_fixture)
        self.assertIn("FINANCE_BOARD_FIXTURE_OK", finance_fixture)
        self.assertNotIn('print("INFO browser error: " + error', finance_browser)
        self.assertNotIn('print("INFO request failed: " + url', finance_browser)
        self.assertIn("browser diagnostics:", finance_browser)


class SharedCommentTransportTests(unittest.TestCase):
    """Every workflow family publishes through ONE ceiling-aware helper.

    The inline `gh pr comment` calls each knew nothing about GitHub's 2,500
    comment ceiling: on PR #282 half of them failed their step and the rest
    reported "permissions?", which was both wrong and a silent evidence loss.
    """

    def test_no_workflow_publishes_a_pr_comment_directly_any_more(self):
        offenders = []
        for path in sorted(WORKFLOWS.glob("*.yml")):
            text = path.read_text(encoding="utf-8")
            if re.search(r"(?m)^\s*gh pr comment ", text):
                offenders.append(path.name)
        self.assertEqual(offenders, [], "route PR evidence through pr-evidence-comment.sh")

    def test_every_family_uses_a_distinct_marker(self):
        families = []
        for path in sorted(WORKFLOWS.glob("*.yml")):
            text = path.read_text(encoding="utf-8").replace("\\\n", " ")
            families += re.findall(r"--family (\S+)", text)
        unique = set(families)
        self.assertEqual(sorted(families), sorted(unique), "each family appears once")
        self.assertGreaterEqual(len(unique), 13, sorted(unique))

    def test_helper_is_inside_the_pr_comment_api_and_announces_the_ceiling(self):
        helper = COMMENTER.read_text(encoding="utf-8")
        for call in helper.replace("\\\n", " ").split("gh api")[1:]:
            command = re.split(r"\s\|\||\s&&|;", call)[0]
            self.assertIn("repos/$REPOSITORY/issues", command, command.strip())
        self.assertIn("COMMENT_CEILING=2500", helper)
        self.assertIn("EVIDENCE_COMMENT_CEILING", helper)
        self.assertIn("cpms:evidence-sticky:", helper)
        self.assertLess(
            helper.index("--method PATCH"),
            helper.index("--method POST"),
            "update in place before creating anything",
        )
        self.assertIn("exit 1", helper, "a real publication failure still fails")
        self.assertLess(
            helper.index("EVIDENCE_COMMENT_CEILING"),
            helper.index("::error title=EVIDENCE_COMMENT_FAILED"),
            "the ceiling must be classified before the generic failure",
        )
        self.assertNotRegex(helper, r"(?m)^\s*gh pr comment", "the helper is the transport")

    def run_helper(self, **kwargs):
        with tempfile.TemporaryDirectory(prefix="comment-transport-test-") as tmp:
            tmp_path = Path(tmp)
            fake_bin = tmp_path / "bin"
            fake_bin.mkdir()
            gh = fake_bin / "gh"
            gh.write_text(
                "#!/usr/bin/env python3\n"
                "import json, os, shutil, sys\n"
                "args = sys.argv[1:]\n"
                "env = os.environ.get\n"
                "with open(os.environ['GH_TEST_CALLS'], 'a', encoding='utf-8') as f:\n"
                "    f.write(json.dumps(args) + '\\n')\n"
                "for arg in args:\n"
                "    if arg.startswith('body=@') and env('GH_TEST_BODY_COPY'):\n"
                "        shutil.copyfile(arg[6:], env('GH_TEST_BODY_COPY'))\n"
                "method = 'GET'\n"
                "if '--method' in args:\n"
                "    method = args[args.index('--method') + 1]\n"
                "path = [a for a in args if a.startswith('repos/')][0]\n"
                "if method == 'GET' and 'comments?per_page=100' in path:\n"
                "    if env('GH_TEST_FIND_FAIL') == '1':\n"
                "        sys.exit(30)\n"
                "    if env('GH_TEST_STICKY_ID'):\n"
                "        print(env('GH_TEST_STICKY_ID'))\n"
                "elif method == 'GET':\n"
                "    print(env('GH_TEST_COMMENT_COUNT', '0'))\n"
                "elif method == 'PATCH':\n"
                "    if env('GH_TEST_EDIT_FAIL') == '1':\n"
                "        sys.exit(31)\n"
                "    print('{}')\n"
                "elif method == 'POST':\n"
                "    if env('GH_TEST_CREATE_FAIL') == '1':\n"
                "        print('GraphQL: Comment limit exceeded for this issue', file=sys.stderr)\n"
                "        sys.exit(32)\n"
                "    print('{}')\n"
                "else:\n"
                "    sys.exit(33)\n",
                encoding="utf-8",
            )
            gh.chmod(0o755)
            body = tmp_path / "body.md"
            body.write_text("synthetic family evidence\n", encoding="utf-8")
            calls_file = tmp_path / "calls.jsonl"
            summary = tmp_path / "summary.md"
            summary.touch()
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{fake_bin}{os.pathsep}{env.get('PATH', '')}",
                    "GH_TEST_CALLS": str(calls_file),
                    "GH_TEST_STICKY_ID": kwargs.get("sticky_id", ""),
                    "GH_TEST_FIND_FAIL": "1" if kwargs.get("find_fail") else "0",
                    "GH_TEST_EDIT_FAIL": "1" if kwargs.get("edit_fail") else "0",
                    "GH_TEST_CREATE_FAIL": "1" if kwargs.get("create_fail") else "0",
                    "GH_TEST_COMMENT_COUNT": kwargs.get("comment_count", "0"),
                    "GH_TEST_BODY_COPY": str(tmp_path / "published-body.md"),
                    "GITHUB_REPOSITORY": "bia2on2on/doctor",
                    "GITHUB_STEP_SUMMARY": str(summary),
                    "GITHUB_RUN_ID": "999",
                    "GITHUB_SHA": "deadbeef",
                }
            )
            env.pop("CPMS_PR_NUMBER", None)
            env.pop("PR_NUMBER", None)
            args = ["bash", str(COMMENTER), "--family", "ci-unit-tests", "--body-file", str(body)]
            if kwargs.get("pr", "282"):
                args += ["--pr", kwargs.get("pr", "282")]
            result = subprocess.run(args, cwd=ROOT, env=env, text=True, capture_output=True, check=False)
            calls = [json.loads(l) for l in calls_file.read_text().splitlines()] if calls_file.exists() else []
            published = (tmp_path / "published-body.md")
            return result, calls, summary.read_text(encoding="utf-8"), (
                published.read_text(encoding="utf-8") if published.exists() else ""
            )

    def test_existing_family_comment_is_updated_in_place(self):
        result, calls, summary, body = self.run_helper(sticky_id="7777")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[1][:3], ["api", "--method", "PATCH"])
        self.assertIn("repos/bia2on2on/doctor/issues/comments/7777", " ".join(calls[1]))
        self.assertTrue(body.startswith("<!-- cpms:evidence-sticky:ci-unit-tests -->"), body[:80])
        self.assertIn("synthetic family evidence", body)
        self.assertIn("PR_EVIDENCE_PUBLICATION=ci-unit-tests#comment:7777", result.stdout)
        self.assertEqual(summary, "")

    def test_first_run_creates_the_family_comment_once(self):
        result, calls, summary, _ = self.run_helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls[1][:3], ["api", "--method", "POST"])
        self.assertIn("#comment (created)", result.stdout)

    def test_the_ceiling_is_announced_and_never_fails_the_step(self):
        result, calls, summary, _ = self.run_helper(create_fail=True, comment_count="2500")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("EVIDENCE_COMMENT_CEILING", result.stderr)
        self.assertIn("2500", result.stderr)
        self.assertIn("#run-summary", result.stdout)
        self.assertIn("durable copy", summary)

    def test_a_real_failure_below_the_ceiling_is_never_hidden(self):
        result, calls, summary, _ = self.run_helper(create_fail=True, comment_count="12")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("EVIDENCE_COMMENT_FAILED", result.stderr)
        self.assertNotIn("EVIDENCE_COMMENT_CEILING", result.stderr)
        self.assertIn("durable copy", summary)

    def test_a_lookup_failure_is_announced_then_classified(self):
        result, calls, summary, _ = self.run_helper(find_fail=True, create_fail=True, comment_count="12")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("EVIDENCE_COMMENT_LOOKUP", result.stderr)
        self.assertIn("EVIDENCE_COMMENT_FAILED", result.stderr)

    def test_no_pull_request_is_skipped_explicitly_not_silently(self):
        result, calls, summary, _ = self.run_helper(pr="")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls, [])
        self.assertIn("EVIDENCE_COMMENT_SKIPPED", result.stderr)


class EvidenceTargetRuntimeTests(unittest.TestCase):
    """Execute the real publisher with a deterministic fake gh executable."""

    def run_publisher(self, event, ref_name, *, pr_number="", candidates=None, fail_list=False,
                      sticky_id="", find_fail=False, edit_fail=False, create_fail=False,
                      comment_count="0"):
        """Execute the real publisher against a fake `gh`.

        The fake models the three endpoints the publisher is allowed to use:
        list PR comments (paginated), PATCH one comment, POST one comment.
        """
        with tempfile.TemporaryDirectory(prefix="evidence-target-test-") as tmp:
            tmp_path = Path(tmp)
            fake_bin = tmp_path / "bin"
            fake_bin.mkdir()
            gh = fake_bin / "gh"
            gh.write_text(
                "#!/usr/bin/env python3\n"
                "import json, os, sys\n"
                "args = sys.argv[1:]\n"
                "with open(os.environ['GH_TEST_CALLS'], 'a', encoding='utf-8') as f:\n"
                "    f.write(json.dumps(args) + '\\n')\n"
                "def env(name, default=''):\n"
                "    return os.environ.get(name, default)\n"
                "for arg in args:\n"
                "    if arg.startswith('body=@'):\n"
                "        import os as _os, shutil\n"
                "        shutil.copyfile(arg[len('body=@'):],\n"
                "                        _os.path.join(_os.path.dirname(arg[len('body=@'):]), 'published-body.md'))\n"
                "if args[:2] == ['pr', 'list'] and env('GH_TEST_FAIL_LIST') == '1':\n"
                "    print('simulated PR lookup failure', file=sys.stderr)\n"
                "    sys.exit(22)\n"
                "elif args[:2] == ['pr', 'list']:\n"
                "    print(env('GH_TEST_CANDIDATES', '[]'))\n"
                "elif args[0] != 'api':\n"
                "    print('unexpected gh command: ' + repr(args), file=sys.stderr)\n"
                "    sys.exit(24)\n"
                "else:\n"
                "    args = args[1:]\n"
                "    method = 'GET'\n"
                "    if '--method' in args:\n"
                "        method = args[args.index('--method') + 1]\n"
                "    path = [a for a in args if a.startswith('repos/')][0]\n"
                "    if method == 'GET' and path.endswith('/comments?per_page=100'):\n"
                "        if env('GH_TEST_FIND_FAIL') == '1':\n"
                "            print('simulated comment listing failure', file=sys.stderr)\n"
                "            sys.exit(30)\n"
                "        sticky = env('GH_TEST_STICKY_ID')\n"
                "        if sticky:\n"
                "            print(sticky)\n"
                "    elif method == 'GET' and not path.endswith('/comments?per_page=100'):\n"
                "        print(env('GH_TEST_COMMENT_COUNT', '0'))\n"
                "    elif method == 'PATCH':\n"
                "        if env('GH_TEST_EDIT_FAIL') == '1':\n"
                "            print('simulated edit failure', file=sys.stderr)\n"
                "            sys.exit(31)\n"
                "        print('{}')\n"
                "    elif method == 'POST':\n"
                "        if env('GH_TEST_CREATE_FAIL') == '1':\n"
                "            print('GraphQL: Comment limit exceeded for this issue', file=sys.stderr)\n"
                "            sys.exit(32)\n"
                "        print('{}')\n"
                "    else:\n"
                "        print('unexpected api call: ' + repr(args), file=sys.stderr)\n"
                "        sys.exit(33)\n",
                encoding="utf-8",
            )
            gh.chmod(0o755)
            evidence = tmp_path / "evidence.md"
            evidence.write_text("synthetic safe evidence\n", encoding="utf-8")
            calls_file = tmp_path / "gh-calls.jsonl"
            bodies_dir = tmp_path / "bodies"
            bodies_dir.mkdir()
            summary = tmp_path / "summary.md"
            summary.touch()
            env = os.environ.copy()
            env.update(
                {
                    "PATH": f"{fake_bin}{os.pathsep}{env.get('PATH', '')}",
                    "GH_TEST_CALLS": str(calls_file),
                    "GH_TEST_CANDIDATES": json.dumps(candidates or []),
                    "GH_TEST_FAIL_LIST": "1" if fail_list else "0",
                    "GH_TEST_STICKY_ID": sticky_id,
                    "GH_TEST_FIND_FAIL": "1" if find_fail else "0",
                    "GH_TEST_EDIT_FAIL": "1" if edit_fail else "0",
                    "GH_TEST_CREATE_FAIL": "1" if create_fail else "0",
                    "GH_TEST_COMMENT_COUNT": comment_count,
                    "GITHUB_EVENT_NAME": event,
                    "GITHUB_REPOSITORY": "bia2on2on/doctor",
                    "GITHUB_REPOSITORY_OWNER": "bia2on2on",
                    "GITHUB_REF_NAME": ref_name,
                    "GITHUB_STEP_SUMMARY": str(summary),
                    "CPMS_PR_NUMBER": pr_number,
                }
            )
            result = subprocess.run(
                ["bash", str(PUBLISHER), str(evidence)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                check=False,
            )
            calls = [json.loads(line) for line in calls_file.read_text().splitlines()] if calls_file.exists() else []
            return result, calls, summary.read_text(encoding="utf-8")

    @staticmethod
    def body_of(call):
        if "--body-file" in call:
            return call[call.index("--body-file") + 1]
        if "-F" in call:
            return call[call.index("-F") + 1]
        return ""

    def test_pull_request_uses_event_number_not_merge_ref_lookup(self):
        result, calls, summary = self.run_publisher("pull_request", "269/merge", pr_number="269")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0][:2], ["api", "--paginate"])
        self.assertIn("repos/bia2on2on/doctor/issues/269/comments?per_page=100", " ".join(calls[0]))
        self.assertEqual(calls[1][:3], ["api", "--method", "POST"])
        self.assertFalse(any(call[:2] == ["pr", "list"] for call in calls))
        self.assertEqual(summary, "")

    def test_an_existing_sticky_comment_is_updated_in_place(self):
        """A sticky comment, not an append: PR #282 hit GitHub's 2,500 ceiling."""
        result, calls, summary = self.run_publisher(
            "pull_request", "269/merge", pr_number="269", sticky_id="4242"
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 2, "editing in place must not also create a comment")
        self.assertEqual(calls[1][:3], ["api", "--method", "PATCH"])
        self.assertIn("repos/bia2on2on/doctor/issues/comments/4242", " ".join(calls[1]))
        self.assertTrue(self.body_of(calls[1]).startswith("body=@"))
        self.assertIn("PR_EVIDENCE_PUBLICATION=evidence#comment:4242", result.stdout)
        self.assertEqual(summary, "")

    def test_the_published_body_carries_the_marker_and_the_evidence(self):
        result, calls, _ = self.run_publisher(
            "pull_request", "269/merge", pr_number="269", sticky_id="4242"
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        body_path = self.body_of(calls[1]).split("@", 1)[1]

        body = (Path(body_path).parent / "published-body.md").read_text(encoding="utf-8")
        self.assertTrue(body.startswith("<!-- cpms:evidence-sticky"))
        self.assertIn("synthetic safe evidence", body)
        self.assertIn("issues/comments/4242", " ".join(calls[1]))
        # The marker is what makes the comment findable on the next run.
        self.assertIn("cpms:evidence-sticky", calls[0][calls[0].index("--jq") + 1])

    def test_first_run_creates_the_sticky_exactly_once(self):
        result, calls, summary = self.run_publisher("pull_request", "269/merge", pr_number="269")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[1][:3], ["api", "--method", "POST"])
        self.assertIn("PR_EVIDENCE_PUBLICATION=evidence#comment (created)", result.stdout)

    def test_a_failed_in_place_update_falls_back_to_creating_one(self):
        result, calls, summary = self.run_publisher(
            "pull_request", "269/merge", pr_number="269", sticky_id="4242", edit_fail=True
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 3)
        self.assertEqual(calls[2][:3], ["api", "--method", "POST"])
        self.assertIn("EVIDENCE_COMMENT_UPDATE", result.stderr)
        self.assertEqual(summary, "")

    def test_comment_ceiling_is_reported_explicitly_and_keeps_evidence(self):
        """The ceiling is not a product defect, and it is never silent.

        Both write paths are unavailable, so the evidence goes to the durable run
        summary and the condition is annotated; the step does not pretend the PR
        comment was published.
        """
        result, calls, summary = self.run_publisher(
            "pull_request", "269/merge", pr_number="269", create_fail=True, comment_count="2500"
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("repos/bia2on2on/doctor/issues/269", " ".join(calls[-1]))
        self.assertIn(".comments", " ".join(calls[-1]))
        self.assertIn("synthetic safe evidence", summary)
        self.assertIn("EVIDENCE_COMMENT_CEILING", result.stderr)
        self.assertIn("2500", result.stderr)
        self.assertIn("PR_EVIDENCE_PUBLICATION=evidence#run-summary", result.stdout)
        self.assertNotIn("#comment", result.stdout)

    def test_a_publication_failure_below_the_ceiling_is_a_hard_failure(self):
        """Never turn a genuine publication failure into a green step."""
        result, calls, summary = self.run_publisher(
            "pull_request", "269/merge", pr_number="269", create_fail=True, comment_count="17"
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("::error::Required evidence comment to PR", result.stderr)
        self.assertIn("synthetic safe evidence", summary)
        self.assertIn("durable copy", summary)

    def test_a_failed_lookup_is_announced_and_still_classified_honestly(self):
        result, calls, summary = self.run_publisher(
            "pull_request", "269/merge", pr_number="269", find_fail=True, create_fail=True, comment_count="17"
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("EVIDENCE_COMMENT_LOOKUP", result.stderr)
        self.assertIn("::error::Required evidence comment to PR", result.stderr)

    def test_main_push_schedule_and_main_dispatch_use_summary_not_an_inferred_pr(self):
        for event in ("push", "schedule", "workflow_dispatch"):
            with self.subTest(event=event):
                result, calls, summary = self.run_publisher(event, "main")
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(calls, [])
                self.assertEqual(summary, "synthetic safe evidence\n")

    def test_manual_source_branch_resolves_only_one_exact_same_repository_target(self):
        candidates = [
            {"number": 269, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}},
            {"number": 991, "headRefName": "other", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}},
            {"number": 992, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "fork-owner"}},
            {"number": 993, "headRefName": "arena/topic", "baseRefName": "develop", "headRepositoryOwner": {"login": "bia2on2on"}},
        ]
        result, calls, summary = self.run_publisher("workflow_dispatch", "arena/topic", candidates=candidates)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls[0][:2], ["pr", "list"])
        self.assertIn("--head", calls[0])
        self.assertEqual(calls[0][calls[0].index("--head") + 1], "arena/topic")
        self.assertEqual(calls[1][:1], ["api"])
        self.assertIn("issues/269/comments", " ".join(calls[1]))
        self.assertEqual(summary, "")

    def test_unrelated_or_ambiguous_pr_is_never_commented(self):
        unrelated = [
            {"number": 991, "headRefName": "different-branch", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}},
            {"number": 992, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "other-owner"}},
        ]
        result, calls, summary = self.run_publisher("workflow_dispatch", "arena/topic", candidates=unrelated)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][:2], ["pr", "list"])
        self.assertNotIn("comment", [call[1] for call in calls if len(call) > 1])
        self.assertEqual(summary, "synthetic safe evidence\n")
        self.assertIn("no PR comment will be attempted", result.stdout)

        duplicate = [
            {"number": n, "headRefName": "arena/topic", "baseRefName": "main", "headRepositoryOwner": {"login": "bia2on2on"}}
            for n in (269, 270)
        ]
        result, calls, _ = self.run_publisher("workflow_dispatch", "arena/topic", candidates=duplicate)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(calls), 1)

    def test_manual_target_lookup_failure_is_not_silenced(self):
        result, calls, summary = self.run_publisher("workflow_dispatch", "arena/topic", fail_list=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][:2], ["pr", "list"])
        self.assertNotIn("comment", [call[1] for call in calls if len(call) > 1])
        self.assertEqual(summary, "")
        self.assertIn("simulated PR lookup failure", result.stderr)

    def test_invalid_pull_request_number_fails_closed_without_any_comment(self):
        result, calls, summary = self.run_publisher("pull_request", "269/merge", pr_number="")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(calls, [])
        self.assertEqual(summary, "")


_MIGRATION_VERSION_RE = re.compile(r"^(\d{4}_\d{2}_\d{2}_\d{4})_")


class IntegrationPublicationBoundaryTests(unittest.TestCase):
    """Static contracts pinning the Integration job's publication boundary.

    These tests pin the SHAPES of the publication boundary after the
    adversarial review: raw probe output is never echoed or mirrored, idents
    reach PR annotations only through the source-verified projector, coverage
    guard summaries carry fixed tokens only, and no artifact path ships a raw
    log. They intentionally do not re-derive behavior the Python contract
    suite (bin/test_integration_evidence.py) already executes.
    """

    def setUp(self):
        self.ci = workflow("ci.yml")
        self.integration = ci_job_block(self.ci, "integration")

    # --- RED-1 probe publication boundary ---

    def test_red1_step_never_echoes_raw_probe_output(self):
        step = ci_step_block(self.integration, "Process-fresh multi-Clinic REST bootstrap probe (RED 1)")
        self.assertNotIn("cat /tmp/red1-probe.log", step)
        self.assertNotIn("tee /tmp/red1-probe.log", step)
        # The fixed-schema projection is the only thing published.
        self.assertIn("red1-probe /tmp/red1-probe.log", step)

    def test_red1_probe_source_emits_fixed_schema_lines_and_fatal_handler(self):
        probe = (ROOT / "clinic-practice-management" / "tests" / "bin"
                 / "multi-clinic-rest-bootstrap-probe.php").read_text(encoding="utf-8")
        self.assertIn("RED1_PROBE v1 ", probe)
        self.assertIn("register_shutdown_function", probe)
        self.assertIn("event=fatal", probe)
        # INVALID-exit paths carry a fixed reason token.
        for token in ("plugin_file", "autoload", "test_library", "core", "database",
                      "bootstrap", "migration", "precondition"):
            self.assertIn("'reason' => '{0}'".format(token), probe)

    def test_red1_projection_is_the_only_pr_mirror_of_the_probe(self):
        step = ci_step_block(self.integration, "Process-fresh multi-Clinic REST bootstrap probe (RED 1)")
        body = step.split("red1-probe-comment.md")[0]
        self.assertIn("red1-probe /tmp/red1-probe.log", body)
        self.assertNotIn("cat ", body.split("echo '```'", 1)[-1])

    # --- GitHub annotations: source-verified idents only ---

    def test_failure_annotations_use_the_source_verified_projector(self):
        part1 = ci_step_block(self.integration, "Surface test failures (part 1)")
        part2 = ci_step_block(self.integration, "Surface test failures (part 2)")
        for block in (part1, part2):
            self.assertIn("annotate /tmp/phpunit-int-junit.xml", block)
        self.assertNotIn("grep -oE", part1)
        self.assertNotIn("grep -oE", part2)

    # --- Coverage guard: mandatory JUnit cross-check, fixed tokens only ---

    def test_coverage_guard_invocation_passes_the_junit_cross_check(self):
        step = ci_step_block(self.integration,
                             "AuthorizationService coverage guard (fail-closed; includes negative-control self-test)")
        self.assertIn("--phpunit-junit /tmp/authz-coverage/junit.xml", step)

    def test_coverage_guard_publishes_no_raw_summary_token(self):
        guard = (ROOT / "clinic-practice-management" / "bin"
                 / "authorization-coverage-guard.py").read_text(encoding="utf-8")
        # Dict-key emission of the raw summary line must not exist; the
        # positive control already asserts the key is absent from results.
        self.assertNotIn('"terminal_summary":', guard)
        self.assertIn("terminal_verdict", guard)
        self.assertIn("check_junit", guard)

    # --- No raw logs in artifacts ---

    def test_no_uploaded_artifact_path_is_a_raw_log(self):
        raw_paths = ("/tmp/phpunit-int.log", "/tmp/red1-probe.log",
                     "/tmp/cpms-conn-diag.jsonl", "/tmp/custom-role-race.log",
                     "/tmp/custom-role-archive-race.log", "/tmp/heartbeat-recovery-mysql.log",
                     "/tmp/authz-coverage/phpunit.log", "/tmp/authz-coverage/clover.xml",
                     "/tmp/custom-role-mariadb.log", "/tmp/custom-role-archive-migration.log",
                     "/tmp/custom-role-archive.log", "/tmp/first-practice-mariadb.log")
        # Split the job into step blocks and inspect upload-artifact steps only.
        step_blocks = re.split(r"(?m)^      - name: ", self.integration)[1:]
        uploads = [b for b in step_blocks if "uses: actions/upload-artifact" in b]
        self.assertTrue(uploads, "expected at least one upload-artifact step in the Integration job")
        for block in uploads:
            for raw in raw_paths:
                self.assertNotIn(raw, block, raw + " must never be uploaded")
            # Inspect only the `path: |` payload lines, not comments.
            lines = block.splitlines()
            for index, line in enumerate(lines):
                if line.strip() == "path: |":
                    indent = len(line) - len(line.lstrip())
                    for payload in lines[index + 1:]:
                        payload_indent = len(payload) - len(payload.lstrip())
                        if payload.strip() and payload_indent <= indent:
                            break
                        self.assertNotIn(".log", payload)
                        self.assertNotIn(".jsonl", payload)
                        self.assertNotIn("clover.xml", payload)
                        self.assertNotIn(".junit", payload)

    # --- Source emitters: hashing removed, fixed vocabularies ---

    def test_bounded_evidence_publishes_no_hashes_and_exact_vocab(self):
        text = (ROOT / "clinic-practice-management" / "tests" / "Support"
                / "BoundedEvidence.php").read_text(encoding="utf-8")
        for line in text.splitlines():
            if "sha256" in line.lower() or "sha-256" in line.lower():
                self.assertIn("NOT published", line)
        self.assertNotIn("hash(", text)
        # FD vocabulary is exactly the four categories.
        self.assertIn("'dev-null', 'pipe', 'socket', 'other'", text)
        # Race outcome vocabulary is source-exact: the 16 reason codes only.
        self.assertNotIn("CUSTOM_ROLE_MUTATION_FAILED", text)
        self.assertNotIn("CUSTOM_ROLE_ARCHIVED", text)
        self.assertNotIn("CUSTOM_ROLE_DEACTIVATED", text)
        self.assertNotIn("CUSTOM_ROLE_REACTIVATED", text)
        self.assertIn("CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE", text)
        self.assertIn("CUSTOM_ROLE_MEMBERSHIP_REFERENCES", text)

    def test_projector_has_no_hashing_and_no_sha256_fields(self):
        text = (ROOT / "bin" / "integration_evidence.py").read_text(encoding="utf-8")
        self.assertNotIn("hashlib", text)
        self.assertNotIn("statement_sha256'", text)
        self.assertNotIn("assignment_lock_query_sha256'", text)
        self.assertNotIn("waiter_lock_query_sha256'", text)

    def test_connection_diagnostics_emits_only_bounded_chain_shapes(self):
        text = (ROOT / "clinic-practice-management" / "tests" / "Support"
                / "ConnectionDiagnostics.php").read_text(encoding="utf-8")
        self.assertIn("is_int($priority)", text)
        self.assertIn("closure#", text)
        self.assertNotIn("get_debug_type($fn)", text)

    # --- Table-facts test lives on the real database ---

    def test_table_facts_test_is_integration_not_unit(self):
        integration = ROOT / "clinic-practice-management" / "tests" / "Integration" / "WpSqlFailureLedgerIntegrationTest.php"
        unit = ROOT / "clinic-practice-management" / "tests" / "Unit" / "Support" / "WpSqlFailureLedgerTest.php"
        self.assertTrue(integration.is_file())
        self.assertIn("WP_UnitTestCase", integration.read_text(encoding="utf-8"))
        self.assertNotIn("127.0.0.1", unit.read_text(encoding="utf-8"))
        self.assertNotIn("wordpress_test", unit.read_text(encoding="utf-8"))

    # --- Verdict semantics preserved (fail-closed, no gate weakening) ---

    def test_red1_step_still_exits_with_the_probe_rc(self):
        step = ci_step_block(self.integration, "Process-fresh multi-Clinic REST bootstrap probe (RED 1)")
        self.assertIn('exit "${PROBE_RC:-1}"', step)

    def test_full_suite_step_still_requires_terminal_summary(self):
        step = ci_step_block(self.integration, "Run integration tests")
        self.assertIn("terminal summary", step)
        self.assertIn("phpunit_status", step)


def link_production_at_baseline(source: Path, target: Path, baseline: str) -> None:
    """Symlink a production tree with its migrations truncated at `baseline`.

    The evaluator derives the schema head from ``src/Migrations/**``. Linking the
    live tree would make the fixture describe whatever ``main`` has advanced to
    rather than the baseline the companion manifest declares, so the migrations
    directory is materialised with only the versions up to (and including)
    ``baseline``; every other entry is symlinked unchanged.
    """
    target.mkdir(parents=True, exist_ok=True)
    for entry in source.iterdir():
        if entry.name == "src":
            continue
        os.symlink(entry, target / entry.name)
    src = target / "src"
    src.mkdir(exist_ok=True)
    for entry in (source / "src").iterdir():
        if entry.name == "Migrations":
            continue
        os.symlink(entry, src / entry.name)
    migrations = src / "Migrations"
    migrations.mkdir(exist_ok=True)
    for entry in (source / "src" / "Migrations").iterdir():
        if not entry.name.endswith(".php"):
            continue
        match = _MIGRATION_VERSION_RE.match(entry.name[:-4])
        if match is None or match.group(1) <= baseline:
            os.symlink(entry, migrations / entry.name)


class CompanionCompatibilityGateContractTests(unittest.TestCase):
    """The demo-coverage gate must be a REQUIRED, fail-closed check.

    The defect these tests pin: the `--gate all` step carried
    `continue-on-error: true` and a shell `|| true`, so a declared demo GAP - the
    exact state `compatibility_complete=false` describes - left the required pull
    request check green. A release could then ship from an incomplete companion
    while every check looked healthy.
    """

    FILE = "companion-compat.yml"

    def step_containing(self, text: str, needle: str) -> str:
        """Return the YAML step block that contains `needle`."""
        lines = text.splitlines()
        start = None
        for index, line in enumerate(lines):
            if needle in line:
                start = index
                break
        self.assertIsNotNone(start, f"no step contains {needle!r}")
        begin = start
        while begin > 0 and not lines[begin].lstrip().startswith("- name:"):
            begin -= 1
        end = start + 1
        while end < len(lines):
            stripped = lines[end]
            if stripped.lstrip().startswith("- name:") or (
                stripped.strip() and not stripped.startswith(" " * 7) and not stripped.startswith(" " * 6)
            ):
                break
            end += 1
        return "\n".join(lines[begin:end])

    def test_demo_coverage_step_cannot_be_ignored(self):
        text = workflow(self.FILE)
        self.assertNotRegex(
            text,
            r"(?m)^\s*continue-on-error:\s*true\s*$",
            "a required gate must not be allowed to fail quietly",
        )
        block = self.step_containing(text, "--gate all")
        self.assertNotIn("|| true", block, block)
        self.assertIn("status=$?", block, block)  # the code is captured, then re-raised below
        self.assertIn('if [ "$status" -ne 0 ]', block, block)
        self.assertIn('exit "$status"', block, block)
        self.assertIn("::error title=COMPANION_COMPAT", block, block)

    def test_gate_all_step_rechecks_the_report_itself(self):
        """Defence in depth: even a regressed exit code cannot turn a GAP green."""
        block = self.step_containing(workflow(self.FILE), "--gate all")
        self.assertIn("report['counts']['blocker']", block, block)
        self.assertIn("report['counts']['gap']", block, block)
        self.assertIn("compatibility_complete", block, block)
        self.assertIn("sys.exit(1)", block, block)

    def test_manifest_sync_check_is_not_swallowed(self):
        text = workflow(self.FILE)
        block = self.step_containing(text, "generate.py --root . --check")
        self.assertNotIn("|| true", block, block)
        self.assertIn("--check", block, block)

    def test_the_gate_command_fails_on_an_injected_gap(self):
        """Executable negative contract: the gate the workflow runs fails on a GAP.

        A temporary root is assembled at the schema baseline the companion
        manifest declares: the production tree is linked with its migrations
        directory materialised only up to that baseline, and the manifest is
        copied with one demo-required feature stripped of its journeys. The same
        evaluator the workflow calls must then exit non-zero for `--gate all`
        while `--gate compatibility` still passes - so the failure really is the
        demo-coverage gap, not a broken tree or a schema the companion never
        declared.
        """
        evaluator = ROOT / ".github" / "scripts" / "companion-compat" / "evaluate.py"
        manifest_path = ROOT / "tools" / "disposable-demo-installer" / "compatibility.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        baseline = str(manifest["compatibility"]["companion_schema_pin"])
        feature = next(f for f in manifest["features"] if f.get("journeys"))
        stripped = dict(feature)
        stripped["journeys"] = []
        manifest["features"] = [stripped if f is feature else f for f in manifest["features"]]

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "repo"
            root.mkdir()
            # The heavy production tree is only READ, so it is symlinked; only the
            # migrations directory is materialised, truncated at the manifest's
            # declared baseline so the derived schema head is the one the
            # companion actually declares. The companion tree is copied so its
            # manifest can be mutated.
            link_production_at_baseline(
                ROOT / "clinic-practice-management", root / "clinic-practice-management", baseline
            )
            for name in (".github", "tests", "docs"):
                target = ROOT / name
                if target.exists():
                    os.symlink(target, root / name)
            shutil.copytree(ROOT / "tools", root / "tools", symlinks=False)
            (root / "tools" / "disposable-demo-installer" / "compatibility.json").write_text(
                json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )

            report = Path(tmp) / "report.json"
            all_result = subprocess.run(
                [sys.executable, str(evaluator), "--root", str(root), "--gate", "all", "--report", str(report)],
                capture_output=True, text=True, timeout=300,
            )
            self.assertEqual(1, all_result.returncode, all_result.stdout + all_result.stderr)
            self.assertRegex(all_result.stdout, r"compatibility_complete: (false|False)")
            data = json.loads(report.read_text(encoding="utf-8"))
            self.assertFalse(data["compatibility_complete"])
            self.assertGreaterEqual(data["counts"]["gap"], 1)
            compat = subprocess.run(
                [sys.executable, str(evaluator), "--root", str(root), "--gate", "compatibility", "--quiet"],
                capture_output=True, text=True, timeout=300,
            )
            self.assertEqual(0, compat.returncode, compat.stdout + compat.stderr)

    def test_the_standalone_artifact_is_rebuilt_when_its_embedded_source_changes(self):
        # The standalone ZIP embeds clinic-practice-management/ at build time. A
        # production change therefore changes the artifact, and a lane that does
        # not trigger on it would certify a package it never rebuilt.
        for filename in ("standalone-demo.yml", "scoped-demo.yml"):
            with self.subTest(filename=filename):
                text = workflow(filename)
                events = text.split("on:\n", 1)[1].split("\npermissions:", 1)[0]
                blocks = {}
                for chunk in re.split(r"(?m)^(?=  [a-z_]+:)", events):
                    head = chunk.split(":", 1)[0].strip()
                    if head:
                        blocks[head] = chunk
                for trigger in ("pull_request", "push"):
                    self.assertIn(trigger, blocks)
                    self.assertIn("      - 'clinic-practice-management/**'", blocks[trigger], trigger)
                    self.assertIn("      - 'tools/disposable-demo-installer/**'", blocks[trigger], trigger)
                # The build really does assemble the core from that source.
                build = (ROOT / "bin" / "build-demo-installer.sh").read_text(encoding="utf-8")
                self.assertIn("clinic-practice-management", build)
                self.assertIn("core", build)

    def test_every_workflow_is_parsed_and_the_parser_gate_is_wired(self):
        """A workflow that is not valid YAML does not fail a step, it fails to start.

        GitHub then reports a run with zero jobs, and every gate inside the workflow
        silently did not run. A step name containing an unquoted `: ` (for example
        `- name: Chromium: one Persian action ...`) is enough to do that. So the
        parser gate is mandatory in the lanes that carry this project's evidence, and
        the checker itself must exist and must fail on that exact defect.
        """
        checker = ROOT / "bin" / "check-workflow-yaml.py"
        self.assertTrue(checker.is_file(), "the workflow YAML checker is missing")
        for filename in ("scoped-demo.yml", "standalone-demo.yml"):
            with self.subTest(filename=filename):
                text = workflow(filename)
                self.assertIn("python3 bin/check-workflow-yaml.py", text)
                self.assertIn("pip install --quiet --disable-pip-version-check pyyaml", text)

    def test_no_workflow_declares_an_unquoted_colon_in_a_step_name(self):
        # The cheap, parser-independent version of the same rule, so this fails with
        # a precise message even where no YAML library is installed.
        offenders = []
        for path in sorted(WORKFLOWS.glob("*.yml")):
            for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
                stripped = line.strip()
                if not stripped.startswith("- name: ") and not stripped.startswith("- uses: "):
                    continue
                value = stripped.split(": ", 1)[1]
                if value[:1] in ("'", '"'):
                    continue
                if ": " in value:
                    offenders.append(f"{path.name}:{number}: {value[:60]}")
        self.assertEqual([], offenders, "quote these step names")

    def test_the_workflow_yaml_checker_rejects_a_colon_in_a_step_name(self):
        """Positive control for the two checks above, without needing PyYAML."""
        import tempfile as _tempfile

        source = workflow("scoped-demo.yml")
        self.assertNotIn("- name: Chromium: one", source)
        probe = ROOT / "bin" / "check-workflow-yaml.py"
        self.assertIn("not valid YAML", probe.read_text(encoding="utf-8"))
        self.assertIn("WORKFLOW_YAML_OK", probe.read_text(encoding="utf-8"))
        with _tempfile.TemporaryDirectory() as directory:
            candidate = Path(directory) / "probe.yml"
            candidate.write_text("name: probe\non:\n  push:\njobs:\n  j:\n    runs-on: ubuntu-latest\n"
                                 "    steps:\n      - name: Chromium: one thing\n        run: true\n",
                                 encoding="utf-8")
            try:
                import yaml as _yaml
            except ImportError:
                self.skipTest("no YAML library in this interpreter; CI installs pyyaml")
            with self.assertRaises(_yaml.YAMLError):
                _yaml.safe_load(candidate.read_text(encoding="utf-8"))

    def test_every_lane_that_ships_the_artifact_proves_the_embedded_core_is_current(self):
        """The demo is the Owner's evaluation copy of the production product.

        A duplicated implementation is impossible by design (the core is embedded
        at build time), but a *stale* archive is still possible: built before a
        production change, built from a partial tree, or edited afterwards. Every
        lane that produces or ships that archive must therefore compare it against
        the production tree at the same revision, and the verifier must exist.
        """
        verifier = ROOT / "tests" / "scoped-demo" / "verify_embedded_core.py"
        self.assertTrue(verifier.is_file(), "the embedded-core verifier is missing")
        self.assertIn("EMBEDDED_CORE_SYNC=", verifier.read_text(encoding="utf-8"))
        for filename in ("standalone-demo.yml", "scoped-demo.yml"):
            with self.subTest(filename=filename):
                text = workflow(filename)
                self.assertIn("python3 tests/scoped-demo/verify_embedded_core.py", text)
                step = text.split("embeds the production core of THIS revision", 1)[1]
                step = step.split("- name:", 1)[0]
                self.assertIn("--zip", step)
                self.assertIn("--json", step)
        # The lane that publishes the Owner's download verifies BOTH builds, so a
        # reproducibility failure and a staleness failure cannot hide behind each
        # other.
        standalone = workflow("standalone-demo.yml")
        self.assertEqual(2, standalone.count("verify_embedded_core.py \\"))
        self.assertIn("/tmp/cpsa-sync-proof.json", standalone)

    def test_the_build_is_the_only_path_production_code_takes_into_the_demo(self):
        # A checked-in copy would be a fork that rots silently, and no CI check can
        # make two copies stay equal.
        tracked = subprocess.run(
            ["git", "-C", str(ROOT), "ls-files",
             "tools/disposable-demo-installer/core", "tools/disposable-demo-installer/src"],
            capture_output=True, text=True, check=False,
        ).stdout.strip()
        self.assertEqual("", tracked, "production code must never be committed inside the demo")
        build = (ROOT / "bin" / "build-demo-installer.sh").read_text(encoding="utf-8")
        self.assertIn('cp -R "$PRODUCTION_SRC/src"', build)
        # Determinism and the single-discoverable-header fix stay part of the build.
        self.assertIn('find "$STAGE" -exec touch -h -d "2026-01-01T00:00:00Z"', build)
        self.assertIn("unexpected WordPress-discoverable plugin headers", build)
        self.assertIn("CPMS Embedded Core:", build)

    def test_every_runner_pin_records_an_expiry_and_the_cache_key_separates_os_releases(self):
        """Temporary `ubuntu-24.04` pins must say when they expire, and the browser
        cache key must be able to tell two runner OS *releases* apart.

        Why: a pin that nobody revisits silently becomes permanent, and a browser
        cache keyed on `runner.os` alone ("Linux") lets a binary linked against one
        Ubuntu release be restored on another. Both are statement-level checks with
        no clock in them: the test reads that an expiry date is *recorded* next to
        the pin and that the key contains the OS version and the architecture. It
        deliberately does not compare against today's date, so it cannot start
        failing because time passed.
        """
        # A pin is any `runs-on:` value that NAMES ubuntu-24.04, whether literally
        # or inside a conditional expression (the seven coexistence lanes are
        # pinned through `${{ inputs.persian_coexistence && 'ubuntu-24.04' || ... }}`
        # in real-wp-acceptance.yml, which a literal-line match would miss).
        expiry = re.compile(r"EXPIRY\s+\d{4}-\d{2}-\d{2}")
        pinned_locations = {}
        for path in sorted(WORKFLOWS.glob("*.yml")):
            lines = path.read_text(encoding="utf-8").splitlines()
            for index, line in enumerate(lines):
                stripped = line.strip()
                if not stripped.startswith("runs-on:"):
                    continue
                if "ubuntu-24.04" not in stripped:
                    continue
                # The expiry is recorded in the comment block belonging to this
                # pin: the pin's own line or the contiguous comment lines above it.
                block = [line]
                cursor = index - 1
                while cursor >= 0 and lines[cursor].strip().startswith("#"):
                    block.insert(0, lines[cursor])
                    cursor -= 1
                label = f"{path.name}:{index + 1}"
                pinned_locations[label] = stripped
                self.assertRegex(
                    "\n".join(block),
                    expiry,
                    f"{label}: a temporary runner pin must carry an "
                    f"'EXPIRY YYYY-MM-DD' comment next to the runs-on expression "
                    f"(found: {block[:1]})",
                )
        self.assertIn(
            "disposable-demo-installer.yml",
            {label.split(":")[0] for label in pinned_locations},
            "the demo lane's experimental runner pin is not in place",
        )
        # The conditional pin must be discovered, not silently skipped: without
        # it this test would pass while the seven coexistence lanes stayed
        # unpinned in the place it cannot see.
        conditional = [
            (label, value)
            for label, value in pinned_locations.items()
            if "inputs." in value and "ubuntu-24.04" in value
        ]
        self.assertEqual(
            1,
            len(conditional),
            "the conditional coexistence pin was not found exactly once: "
            f"{sorted(pinned_locations)}",
        )
        self.assertIn("real-wp-acceptance.yml", conditional[0][0])
        self.assertIn("persian_coexistence", conditional[0][1])

        action = (ROOT / ".github" / "actions" / "playwright-browsers" / "action.yml").read_text(
            encoding="utf-8"
        )
        # The key itself must carry an OS-release token and the architecture, both
        # fed from the runner rather than hard-coded, so two Ubuntu releases never
        # share an entry.
        self.assertRegex(
            action,
            r'(?m)^\s*key="pw-browsers-v\d+-\$\{PW_OS\}-\$\{os_release_id\}-\$\{PW_ARCH\}-',
            "the browser cache key must include the runner OS release and the architecture",
        )
        self.assertIn("os_release_id=", action)
        self.assertIn("/etc/os-release", action)
        # Fail closed: an undeterminable release must not fall back to runner.os.
        self.assertIn(
            "refusing to build a cache key that cannot distinguish OS releases", action
        )

    def test_auto_sync_opens_a_reviewable_pr_and_never_merges(self):
        text = workflow("companion-sync.yml")
        self.assertIn("gh pr create", text)
        for forbidden in ("gh pr merge", "--auto", "--admin", "git tag", "gh release create"):
            self.assertNotIn(forbidden, text, forbidden)
        self.assertIn("not** auto-merged", text)
        events = text.split("on:\n", 1)[1].split("\npermissions:", 1)[0]
        self.assertNotIn("pull_request_target", events)
        self.assertRegex(events, r"(?m)^  push:\n    branches: \[main\]$")



class IntegrationJobLogHygieneContractTest(unittest.TestCase):
    """Non-vacuous static contract for the ci.yml Integration job's stdout.

    The job's raw logs must never reach stdout/stderr/PR surfaces. Every raw
    writer is redirected to /tmp; only bounded fixed-text projections may be
    printed. These tests fail on deliberately violating fixtures, proving the
    contract is not vacuous, and on the real workflow slice (which must stay
    clean).
    """

    def _integration_slice(self):
        return ci_job_block(workflow("ci.yml"), "integration")

    def test_integration_job_has_no_raw_log_writes(self):
        violations = integration_raw_output_violations(workflow("ci.yml"))
        self.assertEqual(
            [],
            violations,
            "Integration job raw-log violations:\n"
            + "\n".join("{0}:{1}: {2}".format(*v) for v in violations),
        )

    def test_integration_job_prints_bounded_junit_summary_before_failure_exit(self):
        violations = junit_projection_before_failure_exit_violations(self._integration_slice())
        self.assertEqual(
            [],
            violations,
            "Integration job failure paths without preceding bounded JUnit summary:\n"
            + "\n".join("{0}:{1}: {2}".format(*v) for v in violations),
        )

    def test_contract_fails_on_deliberately_violating_fixtures(self):
        # Deliberate violations are injected into a PARSED COPY of the real
        # ci.yml — never into the real workflow file. Each class of violation
        # must be detected, proving the contract is not vacuous. The injected
        # steps exercise the shapes a line-oriented guard misses: indented
        # block-scalar lines, bash -c bodies and python -c / heredoc bodies.
        yaml = _yaml_parser()
        real_document = yaml.safe_load(workflow("ci.yml"))

        def violating_text(name, run):
            document = json.loads(json.dumps(real_document))
            document["jobs"]["integration"]["steps"].append(
                {"name": name, "run": run})
            return yaml.safe_dump(document)

        fixtures = {
            "tee": ("tee", 'php vendor/bin/phpunit > /tmp/x.log 2>&1 | tee /tmp/y.log'),
            "raw-log-dump-inline": ("raw-log-dump", 'cat /tmp/x.log'),
            "raw-log-dump-tail": ("raw-log-dump", 'tail -n 50 /tmp/x.log'),
            "raw-log-dump-block-scalar": ("raw-log-dump",
                'cat /tmp/x.log\n'),
            "raw-log-dump-indented": ("raw-log-dump",
                'tail -n 240 /tmp/x.log\ngrep -E "^Tests:" /tmp/x.log\n'),
            "raw-log-dump-after-then": ("raw-log-dump",
                'if [ -s /tmp/x.log ]; then cat /tmp/x.log; fi'),
            "bash-c-raw-dump": ("raw-log-dump",
                "bash -c 'cat /tmp/x.log'"),
            "bash-c-tail": ("raw-log-dump",
                'bash -ec "tail -n 20 /tmp/x.log"'),
            "sh-c-raw-dump": ("raw-log-dump",
                "sh -c 'head -5 /tmp/x.log'"),
            "grep-raw": ("grep-raw", 'grep -E "^Tests:" /tmp/x.log'),
            "grep-raw-after-semicolon": ("grep-raw",
                'php vendor/bin/phpunit > /tmp/x.log 2>&1; grep -E "^OK" /tmp/x.log'),
            "sed-raw": ("sed-raw", "sed -n '1,40p' /tmp/x.log"),
            "awk-raw": ("awk-raw", "awk '{print}' /tmp/x.log"),
            "set-x": ("set-x", 'set -x'),
            "phpunit-raw": ("phpunit-raw", 'php vendor/bin/phpunit'),
            "composer-raw": ("composer-raw", 'composer install --prefer-dist'),
            "install-wp-tests-raw": ("install-wp-tests-raw",
                'bash tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 7.1.3'),
            "red1-probe-raw": ("red1-probe-raw",
                'php tests/bin/multi-clinic-rest-bootstrap-probe.php'),
            "stderr-pipe-to-stdout": ("stderr-pipe-to-stdout",
                'php vendor/bin/phpunit 2>&1 | grep -E FAIL'),
            "python-c-raw-dump": ("python-raw-read",
                'python3 -c "print(open(\'/tmp/x.log\').read())"'),
            "python-c-multiline-raw-dump": ("python-raw-read",
                "python3 -c 'import sys\ndata = open(\"/tmp/x.out\").read()\nprint(data)'"),
            "python-c-raw-iter-print": ("python-raw-iter-print",
                "python3 -c 'for line in open(\"/tmp/x.log\"):\n    print(line)'"),
            "python-heredoc-raw-dump": ("python-raw-read",
                "python3 - <<'EOF'\ndata = open('/tmp/x.err').read()\nprint(data)\nEOF"),
            "python-heredoc-raw-iter-print": ("python-raw-iter-print",
                "python3 - <<'EOF'\nfor line in open('/tmp/x.log'):\n    print(line)\nEOF"),
            "php-r-raw": ("php-r-raw",
                'php -r "var_export(file_get_contents(\'/tmp/x.log\'));"'),
        }
        for label, (expected_rule, run) in fixtures.items():
            with self.subTest(violation=label):
                violations = integration_raw_output_violations(
                    violating_text("DeliberatelyBad", run))
                rules = {v[0] for v in violations}
                self.assertIn(
                    expected_rule,
                    rules,
                    "fixture {0} was not rejected with rule {1} (got {2})".format(
                        label, expected_rule, sorted(rules)),
                )

    def test_round51_guard_additions_fail_on_deliberately_violating_fixtures(self):
        # Round 51 reviewer LOW 4b: options before -c, wrapper commands
        # (sudo/command/exec/env/nice/time) and the encoder/duplicate raw
        # classes. Each fixture must be rejected; the real workflow file is
        # never modified. Not a completeness claim: the guard is static and
        # cannot prove absence of all leaks.
        yaml = _yaml_parser()
        real_document = yaml.safe_load(workflow("ci.yml"))

        def violating_text(name, run):
            document = json.loads(json.dumps(real_document))
            document["jobs"]["integration"]["steps"].append(
                {"name": name, "run": run})
            return yaml.safe_dump(document)

        fixtures = {
            "bash-o-pipefail-c": ("raw-log-dump",
                "bash -o pipefail -c 'cat /tmp/x.log'"),
            "bash-long-opt-c": ("raw-log-dump",
                "bash --noprofile -c 'cat /tmp/x.log'"),
            "sh-o-pipefail-c": ("raw-log-dump",
                "sh -o pipefail -c 'tail /tmp/a.log'"),
            "python-dash-I-c": ("python-raw-read",
                'python3 -I -c "print(open(\'/tmp/y.log\').read())"'),
            "python-dash-W-c": ("python-raw-read",
                'python3 -W ignore -c "print(open(\'/tmp/y.log\').read())"'),
            "sudo-cat": ("raw-log-dump", "sudo cat /tmp/x.log"),
            "sudo-ddash-cat": ("raw-log-dump", "sudo -- cat /tmp/x.log"),
            "sudo-user-cat": ("raw-log-dump", "sudo --user deploy cat /tmp/x.log"),
            "command-cat": ("raw-log-dump", "command cat /tmp/x.log"),
            "exec-cat": ("raw-log-dump", "exec cat /tmp/x.log"),
            "env-cat": ("raw-log-dump", "env cat /tmp/x.log"),
            "env-assign-grep": ("grep-raw", "env FOO=bar grep pat /tmp/x.log"),
            "env-i-cat": ("raw-log-dump", "env -i cat /tmp/x.log"),
            "nice-cat": ("raw-log-dump", "nice cat /tmp/x.log"),
            "nice-n-cat": ("raw-log-dump", "nice -n 5 cat /tmp/x.log"),
            "nice-adjustment-cat": ("raw-log-dump",
                "nice --adjustment 5 cat /tmp/x.log"),
            "time-cat": ("raw-log-dump", "time cat /tmp/x.log"),
            "time-p-cat": ("raw-log-dump", "time -p cat /tmp/x.log"),
            "nested-wrap-cat": ("raw-log-dump", "sudo nice -n 5 cat /tmp/x.log"),
            "raw-subshell-read": ("raw-subshell-read",
                'leak=$(</tmp/x.log); echo "$leak"'),
            "base64-raw": ("raw-transform", "base64 /tmp/x.log"),
            "gzip-raw": ("raw-transform", "gzip -dc /tmp/x.log"),
            "zcat-raw": ("raw-transform", "zcat /tmp/x.log"),
            "tar-raw": ("raw-transform", "tar -cf - /tmp/x.log"),
            "sort-raw": ("raw-transform", "sort /tmp/x.log"),
            "uniq-raw": ("raw-transform", "uniq /tmp/x.log"),
            "cut-raw": ("raw-transform", "cut -d: -f1 /tmp/x.log"),
            "diff-raw": ("raw-transform", "diff /tmp/x.log /tmp/y.log"),
            "cp-raw": ("raw-path-duplicate", "cp /tmp/x.log /tmp/copy.txt"),
            "mv-raw": ("raw-path-duplicate", "mv /tmp/x.log /tmp/moved.txt"),
            "ln-raw": ("raw-path-duplicate", "ln -s /tmp/x.log /tmp/link.txt"),
        }
        for label, (expected_rule, run) in fixtures.items():
            with self.subTest(violation=label):
                violations = integration_raw_output_violations(
                    violating_text("DeliberatelyBad", run))
                rules = {v[0] for v in violations}
                self.assertIn(
                    expected_rule,
                    rules,
                    "fixture {0} was not rejected with rule {1} (got {2})".format(
                        label, expected_rule, sorted(rules)),
                )

    def test_junit_projection_contract_fails_on_deliberately_violating_fixture(self):
        fixture = (
            '  integration:\n'
            '    steps:\n'
            '      - name: Run thing\n'
            '        run: |\n'
            '          phpunit_status=0\n'
            '          php -d memory_limit=1G vendor/bin/phpunit --no-configuration \\\n'
            '            --bootstrap tests/integration-bootstrap.php tests/Integration \\\n'
            '            > /tmp/phpunit-int.log 2>&1 || phpunit_status=$?\n'
            '          if [ "$phpunit_status" -ne 0 ]; then\n'
            '            exit "$phpunit_status"\n'
            '          fi\n'
        )
        violations = junit_projection_before_failure_exit_violations(fixture)
        self.assertEqual(1, len(violations))
        self.assertEqual("junit-projection-missing", violations[0][0])

if __name__ == "__main__":
    unittest.main()

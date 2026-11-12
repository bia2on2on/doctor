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
#     were already dump utilities) and cp/mv/ln of a raw path;
#   * since Round 52 also detected: install of a raw path, dd with a raw
#     if=/of= operand, and openssl base64/enc with a raw path.
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
# cp/mv/ln/install of a raw capture path to another path defeats the
# raw-class naming boundary (the copy is no longer matched by
# _RAW_LITERAL_RE). dd with a raw if=/of= operand and openssl base64/enc
# with a raw path are the same class: bytes of the raw capture end up in
# a new file. Conservative flagging; no completeness claim.
_RAW_PATH_CMD_RE = re.compile(
    _CMD_POS + r"(cp|mv|ln|install)(?:\s|$)")
_DD_CMD_RE = re.compile(_CMD_POS + r"dd(?:\s|$)")
_DD_OPERAND_RE = re.compile(r"(?:if|of)=" + _RAW_LITERAL_RE.pattern)
_OPENSSL_RAW_RE = re.compile(
    _CMD_POS + r"openssl\s+(?:base64|enc)(?:\s|$)")
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
                # (A3) cp/mv/ln/install of a raw capture path: the copy
                # leaves the raw-class naming boundary. No exceptions.
                if _RAW_PATH_CMD_RE.search(cmd) and pipe_raw:
                    violations.append(("raw-path-duplicate", step_name, excerpt))
                # (A3b) dd with a raw if=/of= operand: raw bytes are copied.
                # if= read side leaks the raw capture; of= of a raw path is
                # flagged conservatively (it names a raw-class file).
                if _DD_CMD_RE.search(cmd) and _DD_OPERAND_RE.search(cmd):
                    violations.append(("raw-path-duplicate", step_name, excerpt))
                # (A3c) openssl base64/enc with a raw path: encoding is not
                # redaction; the output still carries (encoded or encrypted)
                # copies of the raw bytes. No exceptions.
                if _OPENSSL_RAW_RE.search(cmd) and pipe_raw:
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

    def test_all_twelve_evidence_publishers_use_shared_fail_loud_router(self):
        for filename, expected_count in (("closure-gate.yml", 2), ("pilot-gate.yml", 10)):
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

    def test_failure_dump_publication_is_removed(self):
        text = workflow("pilot-gate.yml")
        self.assertEqual(text.count("- name: Debug dump به PR"), 0)
        self.assertEqual(text.count("if: failure()"), 0)
        self.assertEqual(text.count('tail -50 "$f"'), 0)
        self.assertEqual(text.count("bash \"$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh\" /tmp/debug.md"), 0)

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
        self.assertIn("grep -q '^DOCTOR_PORTAL_FIXTURE_OK '", doctor_setup)
        self.assertIn("Doctor Portal fixture environment loaded; values withheld.", doctor_setup)
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
        self.assertNotIn("doctor-portal-fixture.log", evidence)
        self.assertNotIn("doctor-portal.log", evidence)
        self.assertIn("pilot-doctor-portal:", evidence)

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

    def test_no_workflow_posts_commit_comments_directly(self):
        """No workflow may POST to the commit-comments endpoint.

        P1/P2 published raw acceptance output (acceptance log, shell proof,
        fixture users, login JSON, management-portal diagnostics) as commit
        comments on a public repository. Re-adding that endpoint anywhere
        re-opens the raw-output publication path.
        """
        offenders = []
        for path in sorted(WORKFLOWS.glob("*.yml")):
            text = path.read_text(encoding="utf-8")
            if "commits/$GITHUB_SHA/comments" in text:
                offenders.append(path.name)
        self.assertEqual(offenders, [], "commit comments are a public raw-output channel")

    def test_real_wp_acceptance_publishes_no_raw_evidence_comment(self):
        """P1/P2 must stay removed from real-wp-acceptance.yml.

        The removed steps embedded the full acceptance log, shell proof,
        fixture users (wp user list), login JSON and management-portal
        diagnostics into commit comments. This test fails if any of those
        publication markers return.
        """
        text = workflow("real-wp-acceptance.yml")
        for marker in (
            "Publish evidence as commit comment",
            "Publish Management Portal browser diagnostics",
            "mp-diag.md",
            "evidence-comment.md",
            "commits/$GITHUB_SHA/comments",
        ):
            self.assertNotIn(marker, text, f"removed publication marker returned: {marker}")

    def test_pilot_gate_publishes_no_debug_dump(self):
        """P3/P4 must stay removed from pilot-gate.yml.

        The three removed Debug dump steps published tail -50 of about 25
        raw logs (including /tmp/leak.txt and /tmp/leak2.txt, which list
        PHI-match hits) to PR comments. This test fails if any of those
        publication markers return.
        """
        text = workflow("pilot-gate.yml")
        for marker in (
            "Debug dump به PR",
            'tail -50 "$f"',
            "/tmp/debug.md",
            "pilot-gate-debug",
            "pilot-responsive-debug",
            "pilot-debug-dump",
        ):
            self.assertNotIn(marker, text, f"removed publication marker returned: {marker}")

    def test_real_wp_acceptance_uses_least_privilege_contents(self):
        """contents: write existed only for the removed commit comments."""
        text = workflow("real-wp-acceptance.yml")
        self.assertNotIn("contents: write", text)
        self.assertIn("contents: read", text)

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
        # No conditional pin may remain: every Tooling job is now a literal
        # `runs-on` (see the Tooling runner contract below). A conditional pin
        # would let a Tooling lane silently resolve to `ubuntu-latest` again.
        conditional = [
            (label, value)
            for label, value in pinned_locations.items()
            if "inputs." in value and "ubuntu-24.04" in value
        ]
        self.assertEqual(
            0,
            len(conditional),
            "no conditional pin may remain: "
            f"{sorted(conditional)}",
        )

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

    def test_round52_raw_path_duplicate_additions_fail_on_deliberately_violating_fixtures(self):
        # Round 52 LOW-5.1: install, dd (raw if=/of= operands) and openssl
        # base64/enc extend the raw-path-duplicate class. Each fixture must
        # be rejected; the real workflow file is never modified. Not a
        # completeness claim.
        yaml = _yaml_parser()
        real_document = yaml.safe_load(workflow("ci.yml"))

        def violating_text(name, run):
            document = json.loads(json.dumps(real_document))
            document["jobs"]["integration"]["steps"].append(
                {"name": name, "run": run})
            return yaml.safe_dump(document)

        fixtures = {
            "install-raw": ("raw-path-duplicate",
                "install /tmp/x.log /tmp/installed.txt"),
            "install-flags-raw": ("raw-path-duplicate",
                "install -m 644 /tmp/x.log /tmp/installed.txt"),
            "install-d-raw": ("raw-path-duplicate",
                "install -D /tmp/a.log /dest/x.log"),
            "dd-if-raw": ("raw-path-duplicate",
                "dd if=/tmp/x.log of=/tmp/blob.bin"),
            "dd-if-raw-bs": ("raw-path-duplicate",
                "dd if=/tmp/x.log of=/tmp/blob.bin bs=4k"),
            "dd-of-raw-conservative": ("raw-path-duplicate",
                "dd if=/dev/zero of=/tmp/x.log bs=1M count=1"),
            "openssl-base64-raw": ("raw-path-duplicate",
                "openssl base64 -in /tmp/x.log"),
            "openssl-base64-out-raw": ("raw-path-duplicate",
                "openssl base64 -in /tmp/x.log -out /tmp/enc.txt"),
            "openssl-enc-raw": ("raw-path-duplicate",
                "openssl enc -aes-256-cbc -in /tmp/x.log -out /tmp/enc.bin -pass pass:dummy"),
            "openssl-enc-decode-raw": ("raw-path-duplicate",
                "openssl enc -d -in /tmp/enc.bin -out /tmp/y.log -pass pass:dummy"),
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
CLASSIC_V1 = "classic-v1-remediation.yml"
PROJECTION_MARKER = "# cpms-fixed-schema-projection"
CANARY = "CANARY9f8a87af"
CANARY_SQL = "SELECT password FROM wp_users FOR UPDATE"
CANARY_TOKEN = "ghp_1234567890abcdefghij"


def classic_v1_projection_blocks(text):
    """Extract the executable fixed-schema projection heredocs from the workflow."""
    blocks = []
    for match in re.finditer(r"<<'PY'(?:\s*\|\|\s*true)?\n(.*?)\n\s*PY\b", text, re.S):
        block = match.group(1)
        if PROJECTION_MARKER in block:
            import textwrap

            blocks.append(textwrap.dedent(block))
    return blocks


def run_projection(block, args, env_extra=None):
    with tempfile.TemporaryDirectory() as tmp:
        script = Path(tmp) / "projection.py"
        script.write_text(block, encoding="utf-8")
        env = dict(os.environ)
        env.pop("GITHUB_STEP_SUMMARY", None)
        if env_extra:
            env.update(env_extra)
        return subprocess.run(
            [sys.executable, str(script), *args],
            capture_output=True,
            text=True,
            env=env,
            timeout=60,
        )


class ClassicV1RemediationPublicationContractTests(unittest.TestCase):
    """classic-v1-remediation.yml must never publish raw test output.

    Public surfaces (annotations, step summary, artifacts) may carry ONLY
    strict integers and verdict words from closed sets. Raw JUnit text,
    test names, log lines, exception text and free-form JSON derived from
    test/perf output must stay in local files that are neither printed
    nor uploaded. The projection heredocs are executed against crafted
    adversarial inputs to prove this with the real code path.
    """

    maxDiff = None

    def test_no_raw_junit_or_log_extraction_patterns(self):
        text = workflow(CLASSIC_V1)
        for forbidden in (
            "case.get('name'",
            'case.get("name"',
            "node.text",
            ".get('message')",
            '[:900]',
            "iter('testcase')",
            'tail -',
            "| tee",
            "tee -a",
            "cat /tmp/classic",
            "$line",
            "str(e)",
            "json.dumps(summary",
            "::notice::BASELINE ",
            "::error::CANDIDATE ",
        ):
            self.assertNotIn(forbidden, text, forbidden)

    def test_artifacts_upload_only_fixed_schema_summaries(self):
        text = workflow(CLASSIC_V1)
        uploads = re.findall(r"uses: actions/upload-artifact@v4\n(.*?)(?=\n  \w|\n  - uses:|\Z)", text, re.S)
        self.assertGreaterEqual(len(uploads), 2, "both jobs must keep an evidence artifact")
        for block in uploads:
            paths = re.findall(r"^\s+(/tmp/\S+)$", block, re.M)
            self.assertTrue(paths, block)
            for path in paths:
                self.assertIn("summary", path, path)
                self.assertNotIn(".log", path, path)
                self.assertNotIn(".xml", path, path)
                self.assertNotIn(".json", path, path)

    def test_annotations_match_fixed_schema_only(self):
        text = workflow(CLASSIC_V1)
        fixed_literals = {
            "::notice::HTTP seed fixture-ok",
            "::notice::HTTP seed env-ok",
            "::notice::HTTP seed source-ok",
        }
        schema = re.compile(
            r"::(notice|error)::(TESTS|PERF|EXIT|HTTP_PROFILE|BASELINE_PREPARE|FIXTURE|SEED|PROFILE)\b"
        )
        evidence_printer = "print(line if line.startswith('###') else '::notice::' + line)"
        seen = 0
        for line in text.splitlines():
            if "::notice::" not in line and "::error::" not in line and "::warning::" not in line:
                continue
            seen += 1
            if any(literal in line for literal in fixed_literals):
                continue
            if evidence_printer in line:
                continue
            self.assertRegex(line, schema, line)
            for forbidden in ("json.dumps", "str(e)", "case.get", "$line", "{", "message"):
                self.assertNotIn(forbidden, line, line)
        self.assertGreater(seen, 5, "the scan must actually observe annotation sites")

    def test_verdict_vocabulary_is_closed(self):
        text = workflow(CLASSIC_V1)
        verdicts = set(re.findall(r"verdict=([A-Z]+)", text))
        self.assertTrue(verdicts, "verdict publications must exist")
        self.assertLessEqual(verdicts, {"PASS", "FAIL", "OK", "UNAVAILABLE"}, verdicts)

    def test_projection_blocks_exist(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        self.assertGreaterEqual(len(blocks), 3, "tests, evidence and HTTP projections must exist")

    def test_candidate_job_still_fails_and_selection_is_unchanged(self):
        text = workflow(CLASSIC_V1)
        self.assertNotIn("continue-on-error", text)
        for line in text.splitlines():
            if "vendor/bin/phpunit" in line and "candidate-" in line:
                self.assertNotIn("|| true", line, line)
                self.assertNotIn("|| rc", line, line)
        self.assertIn("--log-junit /tmp/classic-v1/candidate-", text)
        self.assertIn("--log-junit /tmp/classic-v1/baseline-", text)
        self.assertIn("ClassicV1CriticalRemediationTest.php", text)
        self.assertIn("if: failure()", text)

    # ---------------------------------------------------------------
    # Adversarial: execute the real projection code extracted from the
    # workflow against JUnit/JSON carrying canary secrets and SQL.
    # ---------------------------------------------------------------

    def _canary_junit(self):
        # Internally consistent: declared totals equal the actual testcase
        # children (one failure, one error, one skipped), so the expected
        # verdict below is FAIL on the counts, not UNAVAILABLE on mismatch.
        return (
            '<?xml version="1.0" encoding="UTF-8"?>\n'
            '<testsuites><testsuite name="classic" tests="3" failures="1" errors="1" skipped="1">'
            '<testcase name="testLeakCanaryMethod" classname="CanaryClass">'
            '<failure message="' + CANARY + " " + CANARY_TOKEN + " " + CANARY_SQL + '">'
            "raw body " + CANARY + " " + CANARY_SQL + "</failure>"
            "</testcase>"
            '<testcase name="testCanaryError" classname="CanaryClass">'
            '<error message="' + CANARY + '">' + CANARY_SQL + "</error>"
            "</testcase>"
            '<testcase name="testCanarySkipped" classname="CanaryClass"><skipped/></testcase>'
            "</testsuite></testsuites>\n"
        )

    def test_adversarial_junit_projection_leaks_nothing(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        tests_blocks = [b for b in blocks if "project(sys.argv[1], sys.argv[2])" in b]
        self.assertGreaterEqual(len(tests_blocks), 2, "notice and error TESTS projections must exist")
        for block in tests_blocks:
            is_error = "::error::TESTS" in block
            prefix = "::error::" if is_error else "::notice::"
            label = "CANDIDATE" if is_error else "BASELINE"
            with tempfile.TemporaryDirectory() as tmp:
                xml_path = Path(tmp) / "result.xml"
                xml_path.write_text(self._canary_junit(), encoding="utf-8")
                result = run_projection(block, [str(xml_path), label])
            self.assertEqual(result.returncode, 0, result.stderr)
            expected = (
                prefix + "TESTS label=" + label
                + " tests=3 failures=1 errors=1 skipped=1 verdict=FAIL"
            )
            self.assertEqual(result.stdout.strip(), expected, result.stdout)
            for canary in (CANARY, CANARY_TOKEN, CANARY_SQL, "testLeakCanaryMethod", "CanaryClass"):
                self.assertNotIn(canary, result.stdout)
                self.assertNotIn(canary, result.stderr)

    def test_adversarial_projection_missing_and_malformed_inputs(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        block = [b for b in blocks if "project(sys.argv[1], sys.argv[2])" in b][0]
        result = run_projection(block, ["/nonexistent/result.xml", "BASELINE"])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            result.stdout.strip(),
            "::notice::TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE",
        )
        with tempfile.TemporaryDirectory() as tmp:
            bad = Path(tmp) / "bad.xml"
            bad.write_text(
                '<testsuites><testsuite tests="notanint" failures="1" errors="0" skipped="0"/></testsuites>',
                encoding="utf-8",
            )
            result = run_projection(block, [str(bad), "BASELINE"])
        self.assertIn("verdict=UNAVAILABLE", result.stdout)
        self.assertNotIn("notanint", result.stdout)

    def test_adversarial_evidence_projection_leaks_nothing(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        evidence = [b for b in blocks if "baseline-exit.txt" in b]
        self.assertEqual(len(evidence), 1)
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            (base / "baseline-mysql.xml").write_text(self._canary_junit(), encoding="utf-8")
            (base / "candidate-mysql.xml").write_text(
                '<testsuites><testsuite tests="5" failures="0" errors="0" skipped="2">'
                "<testcase/><testcase/><testcase/>"
                "<testcase><skipped/></testcase><testcase><skipped/></testcase>"
                "</testsuite></testsuites>",
                encoding="utf-8",
            )
            (base / "baseline-mysql.json").write_text(
                json.dumps({"samples": {CANARY: [{"ms": 1, "queries": 2}], "op": [{"ms": 2, "queries": 1}, {"ms": 3, "queries": 1}]}}),
                encoding="utf-8",
            )
            (base / "candidate-mysql.json").write_text(
                json.dumps({"samples": {"op": [{"ms": 1, "queries": 1}]}}), encoding="utf-8"
            )
            (base / "baseline-exit.txt").write_text("1\n", encoding="utf-8")
            summary = base / "summary-mysql.txt"
            step_summary = base / "step-summary.md"
            result = run_projection(
                evidence[0],
                [str(base), "mysql", str(summary)],
                env_extra={"GITHUB_STEP_SUMMARY": str(step_summary)},
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            published = result.stdout + summary.read_text(encoding="utf-8") + step_summary.read_text(encoding="utf-8")
        for canary in (CANARY, CANARY_TOKEN, CANARY_SQL, "testLeakCanaryMethod"):
            self.assertNotIn(canary, published)
        self.assertIn("TESTS label=BASELINE tests=3 failures=1 errors=1 skipped=1 verdict=FAIL", published)
        self.assertIn("TESTS label=CANDIDATE tests=5 failures=0 errors=0 skipped=2 verdict=PASS", published)
        self.assertIn("PERF label=BASELINE samples=3 verdict=OK", published)
        self.assertIn("EXIT label=BASELINE code=1 verdict=FAIL", published)

    # ---------------------------------------------------------------
    # Round 2: canonical bounded counts, total projection, recursive
    # consistency, producer-shape validation. All run the REAL blocks
    # extracted from the workflow, never copies.
    # ---------------------------------------------------------------

    def _tests_block(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        return [b for b in blocks if "project(sys.argv[1], sys.argv[2])" in b][0]

    def _project_xml(self, block, xml_text, label="BASELINE"):
        with tempfile.TemporaryDirectory() as tmp:
            xml_path = Path(tmp) / "result.xml"
            xml_path.write_text(xml_text, encoding="utf-8")
            return run_projection(block, [str(xml_path), label])

    def test_canonical_count_validation_hostile_inputs(self):
        block = self._tests_block()
        template = (
            '<testsuites><testsuite tests="{v}" failures="0" errors="0" skipped="0">'
            "<testcase/></testsuite></testsuites>"
        )
        hostile = [
            " 1", "1 ", "1&#10;", "1&#13;", "+1", "-1", "01", "00", "1.0", "1e3",
            "0x1", "", " ", "\u0661", "\uff11", "9" * 5000, "1000001", "9999999",
            CANARY + "1234567890",
        ]
        for value in hostile:
            with self.subTest(value=value[:24]):
                result = self._project_xml(block, template.format(v=value))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn("Traceback", result.stderr)
                self.assertEqual(
                    result.stdout.strip(),
                    "::notice::TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE",
                    value[:24],
                )
        # Missing attribute is malformed too.
        result = self._project_xml(
            block,
            '<testsuites><testsuite failures="0" errors="0" skipped="0"><testcase/></testsuite></testsuites>',
        )
        self.assertIn("verdict=UNAVAILABLE", result.stdout)
        # Canonical zero is a valid COUNT, but zero executed tests can no
        # longer be reported as PASS (round 3): the verdict is FAIL.
        result = self._project_xml(
            block,
            '<testsuites><testsuite tests="0" failures="0" errors="0" skipped="0"/></testsuites>',
        )
        self.assertEqual(
            result.stdout.strip(),
            "::notice::TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=FAIL",
        )

    def test_nested_suites_use_outermost_totals_and_recursive_cases(self):
        block = self._tests_block()
        xml = (
            '<testsuite tests="3" failures="1" errors="0" skipped="0">'
            '<testsuite tests="1" failures="1" errors="0" skipped="0">'
            "<testcase><failure/></testcase>"
            "</testsuite>"
            "<testcase/><testcase/>"
            "</testsuite>"
        )
        result = self._project_xml(block, xml)
        self.assertEqual(
            result.stdout.strip(),
            "::notice::TESTS label=BASELINE tests=3 failures=1 errors=0 skipped=0 verdict=FAIL",
        )

    def test_declared_totals_mismatch_is_unavailable_never_pass(self):
        block = self._tests_block()
        cases = [
            # Declares no failure but a failure child exists.
            '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0"><testcase><failure/></testcase></testsuite></testsuites>',
            # Declares 2 tests, only 1 testcase.
            '<testsuites><testsuite tests="2" failures="0" errors="0" skipped="0"><testcase/></testsuite></testsuites>',
            # Declares a skip that has no skipped child.
            '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="1"><testcase/></testsuite></testsuites>',
            # Declares no error but an error child exists.
            '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0"><testcase><error/></testcase></testsuite></testsuites>',
        ]
        for xml in cases:
            with self.subTest(xml=xml[:60]):
                result = self._project_xml(block, xml)
                self.assertIn("verdict=UNAVAILABLE", result.stdout)
                self.assertNotIn("verdict=PASS", result.stdout)

    def test_projector_exception_paths_are_total(self):
        block = self._tests_block()
        # 5000-digit attribute crashed the previous lenient parser; the
        # length check must reject it before int() and still emit a line.
        result = self._project_xml(
            block,
            '<testsuites><testsuite tests="' + "9" * 5000 + '" failures="0" errors="0" skipped="0"/></testsuites>',
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("Traceback", result.stderr)
        self.assertIn("verdict=UNAVAILABLE", result.stdout)
        # Oversized file (over the 8 MiB bound) is rejected before parsing.
        with tempfile.TemporaryDirectory() as tmp:
            big = Path(tmp) / "big.xml"
            big.write_bytes(b"<testsuites>" + b" " * (8388608 + 1))
            result = run_projection(block, [str(big), "BASELINE"])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("verdict=UNAVAILABLE", result.stdout)
        # Truncated XML still yields the fixed line, never parse text.
        result = self._project_xml(block, "<testsuites><testsuite tests='1'")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("Traceback", result.stderr)
        self.assertIn("verdict=UNAVAILABLE", result.stdout)
        # A DOCTYPE (entity-expansion surface) is rejected outright.
        result = self._project_xml(
            block,
            '<?xml version="1.0"?><!DOCTYPE testsuites [<!ENTITY x "' + CANARY + '">]>'
            '<testsuites><testsuite tests="0" failures="0" errors="0" skipped="0"/></testsuites>',
        )
        self.assertIn("verdict=UNAVAILABLE", result.stdout)
        self.assertNotIn(CANARY, result.stdout)

    # ---------------------------------------------------------------
    # Round 3: encoding-independent XML hardening (parser-level DTD /
    # entity rejection, strict UTF-8) and per-suite consistency with
    # stricter PASS conditions. Same real extracted blocks.
    # ---------------------------------------------------------------

    def _project_xml_bytes(self, block, data, label="BASELINE"):
        with tempfile.TemporaryDirectory() as tmp:
            xml_path = Path(tmp) / "result.xml"
            xml_path.write_bytes(data)
            return run_projection(block, [str(xml_path), label])

    def test_non_utf8_and_dtd_junit_is_unavailable(self):
        block = self._tests_block()
        unavailable = (
            "::notice::TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE"
        )
        inner = (
            '<?xml version="1.0" encoding="{enc}"?>'
            '<!DOCTYPE testsuites [<!ENTITY x "' + CANARY + '">]>'
            '<testsuites><testsuite tests="1" failures="1" errors="0" skipped="0">'
            "<testcase><failure>&x;</failure></testcase></testsuite></testsuites>"
        )
        cases = {
            "utf16-with-bom": inner.format(enc="UTF-16").encode("utf-16"),
            "utf16le-no-bom": inner.format(enc="UTF-16").encode("utf-16le"),
            "utf16be-with-bom": b"\xfe\xff" + inner.format(enc="UTF-16").encode("utf-16be"),
            "utf32-with-bom": inner.format(enc="UTF-32").encode("utf-32"),
            "utf32be-no-bom": inner.format(enc="UTF-32").encode("utf-32be"),
        }
        for name, data in cases.items():
            with self.subTest(name=name):
                result = self._project_xml_bytes(block, data)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn("Traceback", result.stderr)
                self.assertEqual(result.stdout.strip(), unavailable)
                self.assertNotIn(CANARY, result.stdout)
                self.assertNotIn(CANARY, result.stderr)
        valid_body = (
            "<testsuites><testsuite tests=\"1\" failures=\"0\" errors=\"0\" skipped=\"0\">"
            "<testcase/></testsuite></testsuites>"
        )
        more = {
            # UTF-8 bytes declaring a different encoding.
            "foreign-encoding-decl": (
                '<?xml version="1.0" encoding="ISO-8859-1"?>' + valid_body
            ).encode("utf-8"),
            "utf16-encoding-decl": (
                '<?xml version="1.0" encoding="UTF-16"?>' + valid_body
            ).encode("utf-8"),
            # UTF-8 BOM is never emitted by PHPUnit's JUnit logger.
            "utf8-bom": b"\xef\xbb\xbf" + valid_body.encode("utf-8"),
            # NUL byte anywhere rejects the input.
            "nul-byte": (valid_body + "\x00").encode("utf-8"),
            # Undecodable bytes.
            "undecodable": b"<?xml version=\"1.0\"?><testsuites>\xc3\x28</testsuites>",
        }
        for name, data in more.items():
            with self.subTest(name=name):
                result = self._project_xml_bytes(block, data)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn("Traceback", result.stderr)
                self.assertEqual(result.stdout.strip(), unavailable)
        # An honest UTF-8 declaration with the same body still projects.
        result = self._project_xml_bytes(
            block, ('<?xml version="1.0" encoding="UTF-8"?>' + valid_body).encode("utf-8")
        )
        self.assertIn("verdict=PASS", result.stdout)

    def test_per_suite_consistency_cannot_cancel_across_suites(self):
        block = self._tests_block()
        cases = [
            # Skipped counts compensate across suites: aggregates match
            # (tests=2, skipped=1) but each suite is individually wrong.
            '<testsuites>'
            '<testsuite tests="1" failures="0" errors="0" skipped="1"><testcase/></testsuite>'
            '<testsuite tests="1" failures="0" errors="0" skipped="0"><testcase><skipped/></testcase></testsuite>'
            "</testsuites>",
            # Failure counts compensate across suites: aggregate failures
            # declared (1) equals aggregate actual (1), per-suite it is not.
            '<testsuites>'
            '<testsuite tests="1" failures="0" errors="0" skipped="0"><testcase><failure/></testcase></testsuite>'
            '<testsuite tests="1" failures="1" errors="0" skipped="0"><testcase/></testsuite>'
            "</testsuites>",
            # A nested suite that under-declares its own failure is caught
            # even though the outer suite's aggregate is correct.
            '<testsuite tests="1" failures="1" errors="0" skipped="0">'
            '<testsuite tests="1" failures="0" errors="0" skipped="0">'
            "<testcase><failure/></testcase>"
            "</testsuite></testsuite>",
        ]
        for xml in cases:
            with self.subTest(xml=xml[:60]):
                result = self._project_xml(block, xml)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn("verdict=UNAVAILABLE", result.stdout)
                self.assertNotIn("verdict=PASS", result.stdout)

    def test_zero_all_skipped_and_warning_only_are_not_pass(self):
        block = self._tests_block()
        prefix = "::notice::TESTS label=BASELINE "
        cases = [
            (
                '<testsuites><testsuite tests="0" failures="0" errors="0" skipped="0"/></testsuites>',
                prefix + "tests=0 failures=0 errors=0 skipped=0 verdict=FAIL",
            ),
            (
                '<testsuites><testsuite tests="2" failures="0" errors="0" skipped="2">'
                "<testcase><skipped/></testcase><testcase><skipped/></testcase>"
                "</testsuite></testsuites>",
                prefix + "tests=2 failures=0 errors=0 skipped=2 verdict=FAIL",
            ),
            # Warning-only, with a declared warnings count (PHPUnit 9.6 emits both).
            (
                '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0" warnings="1">'
                "<testcase><warning/></testcase></testsuite></testsuites>",
                prefix + "tests=1 failures=0 errors=0 skipped=0 verdict=FAIL",
            ),
            # Warning-only without a declared warnings attribute.
            (
                '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0">'
                "<testcase><warning/></testcase></testsuite></testsuites>",
                prefix + "tests=1 failures=0 errors=0 skipped=0 verdict=FAIL",
            ),
            # A declared warnings count that contradicts the children is
            # inconsistent data, not a verdict.
            (
                '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0" warnings="0">'
                "<testcase><warning/></testcase></testsuite></testsuites>",
                prefix + "tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE",
            ),
        ]
        for xml, expected in cases:
            with self.subTest(expected=expected[-30:]):
                result = self._project_xml(block, xml)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.strip(), expected)
        # Partial skips with real executions still PASS.
        result = self._project_xml(
            block,
            '<testsuites><testsuite tests="2" failures="0" errors="0" skipped="1">'
            "<testcase/><testcase><skipped/></testcase></testsuite></testsuites>",
        )
        self.assertEqual(
            result.stdout.strip(),
            prefix + "tests=2 failures=0 errors=0 skipped=1 verdict=PASS",
        )

    def test_evidence_block_applies_same_encoding_and_pass_rules(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        evidence = [b for b in blocks if "baseline-exit.txt" in b][0]
        valid = (
            '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0">'
            "<testcase/></testsuite></testsuites>"
        )
        utf16_candidate = (
            '<?xml version="1.0" encoding="UTF-16"?>'
            '<!DOCTYPE testsuites [<!ENTITY x "' + CANARY + '">]>' + valid
        ).encode("utf-16")
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            for db_file in ("baseline-mysql.json", "candidate-mysql.json"):
                (base / db_file).write_text(
                    json.dumps({"samples": {"op": [{"ms": 1, "queries": 1}]}}), encoding="utf-8"
                )
            (base / "baseline-exit.txt").write_text("1\n", encoding="utf-8")
            summary = base / "summary-mysql.txt"
            step_summary = base / "step-summary.md"
            env = {"GITHUB_STEP_SUMMARY": str(step_summary)}
            # UTF-16 candidate: UNAVAILABLE, the step fails, no canary
            # anywhere it publishes (stdout, artifact, step summary).
            (base / "baseline-mysql.xml").write_text(valid, encoding="utf-8")
            (base / "candidate-mysql.xml").write_bytes(utf16_candidate)
            bad = run_projection(evidence, [str(base), "mysql", str(summary)], env_extra=env)
            self.assertEqual(bad.returncode, 1, bad.stdout + bad.stderr)
            published = bad.stdout + summary.read_text(encoding="utf-8") + step_summary.read_text(encoding="utf-8")
            self.assertIn("TESTS label=CANDIDATE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE", published)
            self.assertNotIn(CANARY, published)
            self.assertNotIn(CANARY, bad.stderr)
            # Zero-test candidate: FAIL diagnostic, but the evidence step
            # itself still exits 0 — only UNAVAILABLE (and PHPUnit's own
            # exit code, which is unchanged) can fail the job.
            (base / "candidate-mysql.xml").write_text(
                '<testsuites><testsuite tests="0" failures="0" errors="0" skipped="0"/></testsuites>',
                encoding="utf-8",
            )
            zero = run_projection(evidence, [str(base), "mysql", str(summary)], env_extra=env)
            self.assertEqual(zero.returncode, 0, zero.stdout + zero.stderr)
            self.assertIn("TESTS label=CANDIDATE tests=0 failures=0 errors=0 skipped=0 verdict=FAIL", zero.stdout)
            # UTF-16 baseline: visible UNAVAILABLE, baseline semantics
            # (no step failure) unchanged with a healthy candidate.
            (base / "baseline-mysql.xml").write_bytes(utf16_candidate)
            (base / "candidate-mysql.xml").write_text(valid, encoding="utf-8")
            base_bad = run_projection(evidence, [str(base), "mysql", str(summary)], env_extra=env)
            self.assertEqual(base_bad.returncode, 0, base_bad.stdout + base_bad.stderr)
            self.assertIn("TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE", base_bad.stdout)
            self.assertNotIn(CANARY, base_bad.stdout)

    # ---------------------------------------------------------------
    # Round 4: whole-document consistency — no testcase or result
    # element may live outside a counted testsuite. Same real blocks.
    # ---------------------------------------------------------------

    _VALID_SUITE = (
        '<testsuite tests="1" failures="0" errors="0" skipped="0">'
        "<testcase/></testsuite>"
    )

    def test_orphan_result_elements_are_unavailable(self):
        block = self._tests_block()
        unavailable = (
            "::notice::TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE"
        )
        cases = {}
        for tag in ("failure", "error", "skipped", "warning"):
            cases["orphan-" + tag] = (
                "<testsuites>" + self._VALID_SUITE
                + "<testcase><" + tag + "/></testcase></testsuites>"
            )
        cases["testcase-directly-under-root"] = "<testsuites><testcase/></testsuites>"
        cases["bare-testcase-next-to-suite"] = (
            "<testsuites>" + self._VALID_SUITE + "<testcase/></testsuites>"
        )
        cases["result-element-directly-under-root"] = (
            "<testsuites>" + self._VALID_SUITE + "<failure/></testsuites>"
        )
        cases["valid-nested-plus-orphan"] = (
            "<testsuites>"
            '<testsuite tests="1" failures="0" errors="0" skipped="0">'
            '<testsuite tests="1" failures="0" errors="0" skipped="0">'
            "<testcase/>"
            "</testsuite></testsuite>"
            "<testcase><error/></testcase>"
            "</testsuites>"
        )
        cases["orphan-canary"] = (
            "<testsuites>" + self._VALID_SUITE
            + '<testcase><failure message="' + CANARY + '">' + CANARY + "</failure></testcase></testsuites>"
        )
        for name, xml in cases.items():
            with self.subTest(name=name):
                result = self._project_xml(block, xml)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn("Traceback", result.stderr)
                self.assertEqual(result.stdout.strip(), unavailable)
                self.assertNotIn(CANARY, result.stdout)
                self.assertNotIn(CANARY, result.stderr)

    def test_healthy_producer_shaped_nested_documents_still_project(self):
        # Shaped like the resolved PHPUnit 9.6 JUnit logger: nested suites
        # whose declared counts (including warnings) are propagated to the
        # parents, testcase children only failure/error/skipped/warning.
        block = self._tests_block()
        prefix = "::notice::TESTS label=BASELINE "
        healthy = (
            '<?xml version="1.0" encoding="UTF-8"?>\n'
            "<testsuites>"
            '<testsuite name="classic" tests="3" assertions="9" errors="0" warnings="0" failures="1" skipped="1" time="1.5">'
            '<testsuite name="SomeTest" file="/tmp/SomeTest.php" tests="3" assertions="9" errors="0" warnings="0" failures="1" skipped="1" time="1.5">'
            '<testcase name="testA" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="1" assertions="3" time="0.5"/>'
            '<testcase name="testB" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="2" assertions="3" time="0.5"><failure type="AssertionFailedError">x</failure></testcase>'
            '<testcase name="testC" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="3" assertions="3" time="0.5"><skipped/></testcase>'
            "</testsuite></testsuite></testsuites>"
        )
        result = self._project_xml(block, healthy)
        self.assertEqual(
            result.stdout.strip(),
            prefix + "tests=3 failures=1 errors=0 skipped=1 verdict=FAIL",
        )
        # Same shape with all tests passing: PASS.
        passing = healthy.replace('failures="1"', 'failures="0"').replace(
            '<testcase name="testB" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="2" assertions="3" time="0.5"><failure type="AssertionFailedError">x</failure></testcase>',
            '<testcase name="testB" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="2" assertions="3" time="0.5"/>',
        )
        result = self._project_xml(block, passing)
        self.assertEqual(
            result.stdout.strip(),
            prefix + "tests=3 failures=0 errors=0 skipped=1 verdict=PASS",
        )
        # Producer-shaped with a declared, matching warning: consistent,
        # so FAIL — never UNAVAILABLE from the consistency checks alone.
        warned = passing.replace('warnings="0"', 'warnings="1"').replace(
            '<testcase name="testB" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="2" assertions="3" time="0.5"/>',
            '<testcase name="testB" class="SomeTest" classname="SomeTest" file="/tmp/SomeTest.php" line="2" assertions="3" time="0.5"><warning type="Warning">x</warning></testcase>',
        )
        result = self._project_xml(block, warned)
        self.assertEqual(
            result.stdout.strip(),
            prefix + "tests=3 failures=0 errors=0 skipped=1 verdict=FAIL",
        )

    def test_evidence_block_rejects_orphans_identically(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        evidence = [b for b in blocks if "baseline-exit.txt" in b][0]
        valid = "<testsuites>" + self._VALID_SUITE + "</testsuites>"
        orphan_candidate = (
            "<testsuites>" + self._VALID_SUITE
            + '<testcase><failure message="' + CANARY + '"/></testcase></testsuites>'
        )
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            for db_file in ("baseline-mysql.json", "candidate-mysql.json"):
                (base / db_file).write_text(
                    json.dumps({"samples": {"op": [{"ms": 1, "queries": 1}]}}), encoding="utf-8"
                )
            (base / "baseline-exit.txt").write_text("1\n", encoding="utf-8")
            summary = base / "summary-mysql.txt"
            step_summary = base / "step-summary.md"
            env = {"GITHUB_STEP_SUMMARY": str(step_summary)}
            # Orphan in the candidate: UNAVAILABLE, evidence step fails,
            # canary reaches no surface (stdout, artifact, step summary).
            (base / "baseline-mysql.xml").write_text(valid, encoding="utf-8")
            (base / "candidate-mysql.xml").write_text(orphan_candidate, encoding="utf-8")
            bad = run_projection(evidence, [str(base), "mysql", str(summary)], env_extra=env)
            self.assertEqual(bad.returncode, 1, bad.stdout + bad.stderr)
            published = bad.stdout + summary.read_text(encoding="utf-8") + step_summary.read_text(encoding="utf-8")
            self.assertIn("TESTS label=CANDIDATE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE", published)
            self.assertNotIn(CANARY, published)
            self.assertNotIn(CANARY, bad.stderr)
            # Orphan in the baseline only: visible UNAVAILABLE, step ok.
            (base / "baseline-mysql.xml").write_text(orphan_candidate, encoding="utf-8")
            (base / "candidate-mysql.xml").write_text(valid, encoding="utf-8")
            base_bad = run_projection(evidence, [str(base), "mysql", str(summary)], env_extra=env)
            self.assertEqual(base_bad.returncode, 0, base_bad.stdout + base_bad.stderr)
            self.assertIn("TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE", base_bad.stdout)
            self.assertNotIn(CANARY, base_bad.stdout)

    def test_evidence_candidate_unavailable_fails_but_baseline_does_not(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        evidence = [b for b in blocks if "baseline-exit.txt" in b][0]
        valid_candidate = (
            '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0">'
            "<testcase/></testsuite></testsuites>"
        )
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            (base / "baseline-mysql.xml").write_text("<broken", encoding="utf-8")
            (base / "candidate-mysql.xml").write_text(valid_candidate, encoding="utf-8")
            (base / "baseline-mysql.json").write_text("{broken", encoding="utf-8")
            (base / "candidate-mysql.json").write_text("{broken", encoding="utf-8")
            (base / "baseline-exit.txt").write_text("300\n", encoding="utf-8")
            summary = base / "summary-mysql.txt"
            ok = run_projection(evidence, [str(base), "mysql", str(summary)])
            self.assertEqual(ok.returncode, 0, ok.stderr)
            self.assertIn("TESTS label=BASELINE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE", ok.stdout)
            self.assertIn("EXIT label=BASELINE verdict=UNAVAILABLE", ok.stdout)
            self.assertNotIn("Traceback", ok.stderr)
            # Now the candidate is the malformed one: the step must fail
            # (exit 1) while still publishing the fixed-schema summary.
            (base / "baseline-mysql.xml").write_text(valid_candidate, encoding="utf-8")
            (base / "candidate-mysql.xml").write_text("<broken", encoding="utf-8")
            bad = run_projection(evidence, [str(base), "mysql", str(summary)])
            self.assertEqual(bad.returncode, 1, bad.stdout + bad.stderr)
            self.assertIn("TESTS label=CANDIDATE tests=0 failures=0 errors=0 skipped=0 verdict=UNAVAILABLE", bad.stdout)
            self.assertIn("verdict=UNAVAILABLE", summary.read_text(encoding="utf-8"))

    def test_http_summary_shape_validation(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        http = [b for b in blocks if "HTTP_PROFILE" in b][0]

        def profile(payload):
            return {"schema": 1, "kind": "authenticated_real_http", "summary": payload}

        cases = [
            (profile({"op": {"n": 3, "median_ms": 1.5, "median_queries": 2}}), "operations=1 verdict=OK"),
            (profile({"op": {"n": 1000000, "median_ms": 1, "median_queries": 1}}), "operations=1 verdict=OK"),
            (profile({"op": {"n": 1000001, "median_ms": 1, "median_queries": 1}}), "verdict=UNAVAILABLE"),
            (profile({"op": {"n": True, "median_ms": 1, "median_queries": 1}}), "verdict=UNAVAILABLE"),
            (profile({"op": {"n": 3}}), "verdict=UNAVAILABLE"),
            (profile({"op": {"n": 3, "median_ms": "fast", "median_queries": 1}}), "verdict=UNAVAILABLE"),
            (profile([{"n": 3}]), "verdict=UNAVAILABLE"),
            (profile({}), "verdict=UNAVAILABLE"),
            ({"summary": {"op": {"n": 3, "median_ms": 1, "median_queries": 1}}}, "verdict=UNAVAILABLE"),
            ({"schema": 1, "kind": "other", "summary": {"op": {"n": 3, "median_ms": 1, "median_queries": 1}}}, "verdict=UNAVAILABLE"),
        ]
        for payload, expected in cases:
            with self.subTest(expected=expected, payload=str(payload)[:60]):
                with tempfile.TemporaryDirectory() as tmp:
                    base = Path(tmp)
                    good = base / "baseline.json"
                    good.write_text(json.dumps(payload), encoding="utf-8")
                    summary = base / "summary.txt"
                    result = run_projection(http, [str(good), str(good), str(summary)])
                    published = result.stdout + summary.read_text(encoding="utf-8")
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(expected, published)
                self.assertNotIn(CANARY, published)
        # Canary operation key with a fully valid shape: count only.
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            good = base / "baseline.json"
            good.write_text(json.dumps(profile({CANARY: {"n": 12, "median_ms": 1, "median_queries": 1}})), encoding="utf-8")
            summary = base / "summary.txt"
            result = run_projection(http, [str(good), str(good), str(summary)])
            published = result.stdout + summary.read_text(encoding="utf-8")
        self.assertIn("operations=1 verdict=OK", published)
        self.assertNotIn(CANARY, published)

    def test_adversarial_http_projection_leaks_nothing(self):
        blocks = classic_v1_projection_blocks(workflow(CLASSIC_V1))
        http = [b for b in blocks if "HTTP_PROFILE" in b]
        self.assertEqual(len(http), 1)
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            good = base / "baseline.json"
            good.write_text(json.dumps({"schema": 1, "kind": "authenticated_real_http", "summary": {CANARY: {"n": 3, "median_ms": 1, "median_queries": 2}, "op2": {"n": 12, "median_ms": 2, "median_queries": 4}}}), encoding="utf-8")
            bad = base / "candidate.json"
            bad.write_text("{not json " + CANARY, encoding="utf-8")
            summary = base / "summary.txt"
            result = run_projection(http[0], [str(good), str(bad), str(summary)])
            self.assertEqual(result.returncode, 0, result.stderr)
            published = result.stdout + summary.read_text(encoding="utf-8")
        self.assertNotIn(CANARY, published)
        self.assertIn("HTTP_PROFILE label=BASELINE operations=2 verdict=OK", published)
        self.assertIn("HTTP_PROFILE label=CANDIDATE operations=0 verdict=UNAVAILABLE", published)


_PILOT_EXPECTED_JOBS = {
    "release-artifact": set(),
    "staging-gate": {"release-artifact"},
    "upgrade-test": {"release-artifact"},
    "responsive": {"release-artifact"},
}

_PILOT_EXPECTED_EVIDENCE_BODY_TO_FAMILY = {
    "/tmp/cpms-inner-zip-evidence.md": "pilot-inner-zip",
    "/tmp/pilot-benchmark-status.md": "pilot-benchmark",
    "/tmp/resp-evidence.md": "pilot-responsive-evidence",
    "/tmp/journey-evidence.md": "pilot-otp-journey",
    "/tmp/slice3-evidence.md": "pilot-slice3-chooser",
    "/tmp/portal-cancel-evidence.md": "pilot-portal-cancel",
    "/tmp/portal-profile-evidence.md": "pilot-portal-profile",
    "/tmp/doctor-portal-evidence.md": "pilot-doctor-portal",
    "/tmp/reception-evidence.md": "pilot-reception",
    "/tmp/day2-workflows-evidence.md": "pilot-day2-workflows",
}

_PILOT_EVIDENCE_FAMILIES = frozenset(_PILOT_EXPECTED_EVIDENCE_BODY_TO_FAMILY.values())
_PILOT_EVIDENCE_BODY_BASENAME_TO_PATH = {
    path.rsplit("/", 1)[-1]: path for path in _PILOT_EXPECTED_EVIDENCE_BODY_TO_FAMILY
}

_PILOT_ALLOWED_GITHUB_ENV_FILES = frozenset({
    "/tmp/resp-public-page.env",
    "/tmp/otp-journey.env",
    "/tmp/slice3.env",
    "/tmp/portal-cancel.env",
    "/tmp/portal-profile.env",
    "/tmp/doctor-portal.env",
    "/tmp/finance-board.env",
    "/tmp/reception.env",
    "/tmp/reports.env",
    "/tmp/day2-workflows.env",
})

_PILOT_ALLOWED_LOCAL_REDIRECT_VARS = {
    "MANIFEST": '"$BENCH/manifest.jsonl"',
    "log": '"/tmp/resp-${engine}.log"',
}

_PILOT_EVIDENCE_CLOSED_TOKENS = frozenset({
    "PASS",
    "FAIL",
    "WARN",
    "NOT_RUN",
    "UNAVAILABLE",
    "unavailable",
})

_PILOT_SUMMARY_CLOSED_TOKENS = _PILOT_EVIDENCE_CLOSED_TOKENS | frozenset({
    "CLEAN",
    "OK",
    "READY",
    "ok",
    "refused",
    "not_probed",
    "not_run",
    "present_denied_403",
    "absent",
    "quiescence_not_reached",
    "enqueue_refused",
    "precheck_refused",
    "collect_refused",
    "aggregate_refused",
    "unknown_refusal",
})

_PILOT_POST_CALL_RE = re.compile(
    r'^bash "\$GITHUB_WORKSPACE/\.github/scripts/post-pr-evidence\.sh" '
    r'(/tmp/[A-Za-z0-9._-]+\.md) "([a-z0-9-]+)"$'
)
_PILOT_DIRECT_EVIDENCE_COMMENT_RE = re.compile(r"pr-evidence-comment(?:\.sh)?\b")
_PILOT_EVIDENCE_READER_RE = re.compile(
    _CMD_POS + r"(cat|grep|egrep|fgrep|tail|head|sed|awk|cut|paste|sort|uniq|jq|curl)(?:\s|$)"
)
_PILOT_PRINTF_CALL_RE = re.compile(
    r"^printf '([^'\r\n]+)'((?:\s+\"\$[A-Za-z_][A-Za-z0-9_]*\")*)\s*>\s*(/tmp/[A-Za-z0-9._-]+\.md)$"
)
_PILOT_ECHO_CALL_RE = re.compile(
    r"^echo '([^'\r\n]+)'\s*>\s*(/tmp/[A-Za-z0-9._-]+\.md)$"
)
_PILOT_SUMMARY_ECHO_RE = re.compile(
    r"^echo '([^'\r\n]+)'\s*>>\s*\"\$GITHUB_STEP_SUMMARY\"$"
)
_PILOT_SUMMARY_PRINTF_RE = re.compile(
    r"^printf '([^'\r\n]+)'((?:\s+\"\$[A-Za-z_][A-Za-z0-9_]*\")*)\s*>>\s*\"\$GITHUB_STEP_SUMMARY\"$"
)
_PILOT_WORKFLOW_CMD_NAMES = (
    "error",
    "warning",
    "notice",
    "debug",
    "group",
    "endgroup",
    "add-mask",
    "set-output",
    "save-state",
    "stop-commands",
)
_PILOT_WORKFLOW_CMD_RAW_RE = re.compile(
    r"::(" + "|".join(_PILOT_WORKFLOW_CMD_NAMES) + r")\b",
    re.IGNORECASE,
)
# Intentional friction: only lowercase standalone fixed single-quoted
# ::(error|warning|notice):: literals (matching the 43 existing annotations in
# pilot-gate.yml, plus newline-terminated single-quoted printf) are exempted;
# uppercase/mixed-case fixed literals (e.g. echo '::ERROR::fixed') are rejected.
_PILOT_STANDALONE_FIXED_ECHO_PRINTF_RE = re.compile(
    r"(?:echo[ \t]+'(::(?:error|warning|notice)::[^'\r\n]+)'"
    r"|printf[ \t]+'(::(?:error|warning|notice)::[^'%\r\n\\]+\\n)')"
    r"(?=[ \t]*(?:$|&&|\|\||[;\}])|[ \t]+#)"
)
_PILOT_SHELL_CONTROL_KW_RE = re.compile(
    r"(?:then|do|else|elif|if|while|until)(?=[ \t])"
)
_PILOT_SHELL_WORD_RE = re.compile(r"[A-Za-z_][A-Za-z0-9_]*")
_PILOT_ANSIC_ESCAPES = {
    "a": "\a",
    "b": "\b",
    "e": "\x1b",
    "E": "\x1b",
    "f": "\f",
    "n": "\n",
    "r": "\r",
    "t": "\t",
    "v": "\v",
    "\\": "\\",
    "'": "'",
    '"': '"',
}
# Runner internal ##[command] syntax (ActionCommand.TryParse matches IndexOf("##[")
# anywhere on a line, dispatching commands with OrdinalIgnoreCase) and Azure-inherited
# ##vso[...] syntax, rejected conservatively (including fixed ##[group] markers).
_PILOT_HASH_BRACKET_CMD_RE = re.compile(
    r"##(?:vso\[|\[)",
    re.IGNORECASE,
)
_PILOT_WORKFLOW_CMD_NEUTRALISE_RES = (
    re.compile(r"\$'"),
    re.compile(
        r"\\+(?:[xX][0-9A-Fa-f]{1,2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|[0-7]{1,3}|[abefnrtv\\'\"?])(?![A-Za-z0-9_])"
    ),
    re.compile(
        r"%(?:\d+\$)?[-+ #0]*(?:\d+|\*(?:\d+\$)?)?(?:\.(?:\d+|\*(?:\d+\$)?))?[hljztL]*[diouxXeEfFgGaAcCsSpnbB%]"
    ),
    re.compile(r"\$\{[^{}\r\n]*\}|\$\([^()\r\n]*\)|\$[A-Za-z_][A-Za-z0-9_]*|\$[0-9@#?*!$-]"),
)
_PILOT_PHP_CLASS_LHS_RE = re.compile(
    r"(?<![A-Za-z0-9_$%\\])\\?[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*$"
)
_PILOT_WORKFLOW_CMD_RHS_RE = re.compile(r"^(?:::|\s+[^:;\r\n()]*::)")


def _iter_yaml_strings(node):
    """Yield every string key and string value inside a parsed YAML node."""
    if isinstance(node, dict):
        for key, value in node.items():
            if isinstance(key, str):
                yield key
            yield from _iter_yaml_strings(value)
    elif isinstance(node, list):
        for item in node:
            yield from _iter_yaml_strings(item)
    elif isinstance(node, str):
        yield node


def _is_shell_word_boundary(line, start_idx, end_idx):
    before_ok = start_idx == 0 or line[start_idx - 1] in " \t|&;(){}"
    after_ok = end_idx == len(line) or line[end_idx] in " \t|&;()#"
    return before_ok and after_ok


def _parse_heredoc_delimiter(line, start_idx):
    """Parse a Bash heredoc delimiter word starting at `start_idx`, applying Bash quote removal."""
    idx = start_idx
    length = len(line)
    while idx < length and line[idx] in " \t":
        idx += 1
    if idx >= length or line[idx] in "|&;()<>#":
        return None
    word_start = idx
    chars = []
    while idx < length:
        ch = line[idx]
        if ch in " \t|&;()<>":
            break
        if ch == "$" and idx + 1 < length and line[idx + 1] == "'":
            idx += 2
            while idx < length and line[idx] != "'":
                if line[idx] == "\\" and idx + 1 < length:
                    esc = line[idx + 1]
                    if esc in _PILOT_ANSIC_ESCAPES:
                        chars.append(_PILOT_ANSIC_ESCAPES[esc])
                        idx += 2
                    elif (
                        esc == "x"
                        and idx + 3 < length
                        and all(c in "0123456789abcdefABCDEF" for c in line[idx + 2 : idx + 4])
                    ):
                        chars.append(chr(int(line[idx + 2 : idx + 4], 16)))
                        idx += 4
                    else:
                        chars.append(esc)
                        idx += 2
                else:
                    chars.append(line[idx])
                    idx += 1
            if idx < length and line[idx] == "'":
                idx += 1
            continue
        if ch == "'":
            idx += 1
            while idx < length and line[idx] != "'":
                chars.append(line[idx])
                idx += 1
            if idx < length and line[idx] == "'":
                idx += 1
            continue
        if ch == '"':
            idx += 1
            while idx < length and line[idx] != '"':
                if line[idx] == "\\" and idx + 1 < length and line[idx + 1] in '"\\$`':
                    chars.append(line[idx + 1])
                    idx += 2
                else:
                    chars.append(line[idx])
                    idx += 1
            if idx < length and line[idx] == '"':
                idx += 1
            continue
        if ch == "\\":
            if idx + 1 < length:
                chars.append(line[idx + 1])
                idx += 2
            else:
                idx += 1
            continue
        chars.append(ch)
        idx += 1
    if idx == word_start:
        return None
    return "".join(chars), idx


def _advance_shell_line(raw_line, ctx_stack, pending_heredocs):
    """Advance `ctx_stack` (`[kind, case_depth]`) and `pending_heredocs` across `raw_line`."""
    idx = 0
    length = len(raw_line)
    while idx < length:
        top = ctx_stack[-1][0] if ctx_stack else None
        ch = raw_line[idx]
        if top == "'":
            if ch == "'":
                ctx_stack.pop()
            idx += 1
        elif top == "$'":
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if ch == "'":
                ctx_stack.pop()
            idx += 1
        elif top == '"':
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if raw_line[idx : idx + 3] == "$((":
                ctx_stack.append(["$((", 0])
                idx += 3
                continue
            if raw_line[idx : idx + 2] == "$(":
                ctx_stack.append(["$(", 0])
                idx += 2
                continue
            if ch == "`":
                ctx_stack.append(["`", 0])
                idx += 1
                continue
            if ch == '"':
                ctx_stack.pop()
            idx += 1
        elif top == "`":
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if ch == "`":
                ctx_stack.pop()
            idx += 1
        elif top in ("$((", "arith_("):
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if ch == "(":
                ctx_stack.append(["arith_(", 0])
                idx += 1
                continue
            if raw_line[idx : idx + 2] == "))" and top == "$((":
                ctx_stack.pop()
                idx += 2
                continue
            if ch == ")" and top == "arith_(":
                ctx_stack.pop()
            idx += 1
        else:
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if raw_line[idx : idx + 2] == "$'":
                ctx_stack.append(["$'", 0])
                idx += 2
                continue
            if ch in ("'", '"'):
                ctx_stack.append([ch, 0])
                idx += 1
                continue
            if ch == "`":
                ctx_stack.append(["`", 0])
                idx += 1
                continue
            if ch == "#" and (idx == 0 or raw_line[idx - 1] in " \t|&;()"):
                return True
            if raw_line[idx : idx + 3] == "$((":
                ctx_stack.append(["$((", 0])
                idx += 3
                continue
            if raw_line[idx : idx + 2] in ("$(", "<(", ">("):
                ctx_stack.append([raw_line[idx : idx + 2], 0])
                idx += 2
                continue
            if ch == "(":
                ctx_stack.append(["(", 0])
                idx += 1
                continue
            if ch == ")" and top in ("$(", "<(", ">(", "("):
                if ctx_stack[-1][1] == 0:
                    ctx_stack.pop()
                idx += 1
                continue
            if (
                ch == "<"
                and raw_line[idx : idx + 2] == "<<"
                and raw_line[idx : idx + 3] != "<<<"
                and (idx == 0 or raw_line[idx - 1] != "<")
            ):
                strip_tabs = raw_line[idx : idx + 3] == "<<-"
                op_end = idx + (3 if strip_tabs else 2)
                parsed = _parse_heredoc_delimiter(raw_line, op_end)
                if parsed is not None:
                    delim, next_idx = parsed
                    pending_heredocs.append((delim, strip_tabs))
                    idx = next_idx
                    continue
            if top in ("$(", "<(", ">(", "("):
                word_m = _PILOT_SHELL_WORD_RE.match(raw_line, idx)
                if word_m and _is_shell_word_boundary(raw_line, idx, word_m.end()):
                    word = word_m.group(0)
                    if word == "case":
                        ctx_stack[-1][1] += 1
                    elif word == "esac" and ctx_stack[-1][1] > 0:
                        ctx_stack[-1][1] -= 1
                    idx = word_m.end()
                    continue
            idx += 1
    return False


def _scan_run_units(run_text):
    """Classify LF-delimited lines of `run_text` into `('payload' | 'code_top' | 'code_nested', line)`.

    Splits on `\\n` (preserving `\\r` so `EOF\\r` does not match `EOF`) and matches
    heredoc terminators with exact Bash semantics (`raw_line == delim` for `<<`,
    `raw_line.lstrip('\\t') == delim` for `<<-`). Unterminated heredocs leave
    remaining lines classified as `'payload'` so they are scanned with
    `allow_fixed_echo=False` rather than skipped.
    """
    if not run_text:
        return []
    body = run_text[:-1] if run_text.endswith("\n") else run_text
    raw_lines = body.split("\n")
    ctx_stack = []
    pending_heredocs = []
    active_heredocs = []
    units = []
    prev_continued_nested = False
    prev_continued_after_pipe = False

    for raw_line in raw_lines:
        if active_heredocs:
            delim, strip_tabs = active_heredocs[0]
            candidate = raw_line.lstrip("\t") if strip_tabs else raw_line
            if candidate == delim:
                active_heredocs.pop(0)
            else:
                units.append(("payload", raw_line))
            continue

        entry_top = (
            len(ctx_stack) == 0
            and not prev_continued_nested
            and not prev_continued_after_pipe
        )
        stripped = raw_line.strip()
        if len(ctx_stack) == 0 and (not stripped or stripped.startswith("#")) and not prev_continued_nested:
            if pending_heredocs:
                active_heredocs.extend(pending_heredocs)
                pending_heredocs.clear()
            prev_continued_after_pipe = False
            continue

        if entry_top:
            units.append(("code_top", stripped))
        elif stripped:
            if ctx_stack and ctx_stack[-1][0] in ("$(", "<(", ">(", "(") and stripped.startswith("#"):
                continue
            units.append(("code_nested", stripped))

        ended_in_comment = _advance_shell_line(raw_line, ctx_stack, pending_heredocs)
        trailing_bs = len(raw_line) - len(raw_line.rstrip("\\"))
        top_after = ctx_stack[-1][0] if ctx_stack else None
        continues = (
            not ended_in_comment
            and top_after not in ("'", "$'")
            and trailing_bs % 2 == 1
        )
        if continues:
            before_bs = raw_line[:-1].rstrip(" \t")
            prev_continued_after_pipe = (
                before_bs.endswith("|") and not before_bs.endswith("||")
            ) or before_bs.endswith("|&")
            prev_continued_nested = len(ctx_stack) > 0
        else:
            prev_continued_after_pipe = False
            prev_continued_nested = False
            if pending_heredocs:
                active_heredocs.extend(pending_heredocs)
                pending_heredocs.clear()
    return units


def _strip_standalone_fixed_workflow_commands(line):
    """Mask approved standalone top-level fixed single-quoted `echo`/`printf` notices only.

    Never masks inside quotes (`'...'`, `"..."`, `$'...'`), command substitutions
    (`$(...)`, backticks), process substitutions (`<(...)`, `>(...)`), subshells
    (`(...)`), pipelines (`|`, `|&`), redirections (`>`, `<`), or piped/redirected
    `{ ... }` brace groups.
    """
    idx = 0
    length = len(line)
    ctx_stack = []
    brace_stack = []
    piped_or_redirected_braces = set()
    next_brace_id = 0
    candidate_spans = []
    at_cmd_start = True

    while idx < length:
        top = ctx_stack[-1][0] if ctx_stack else None
        ch = line[idx]
        if top == "'":
            if ch == "'":
                ctx_stack.pop()
            idx += 1
            continue
        if top == "$'":
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if ch == "'":
                ctx_stack.pop()
            idx += 1
            continue
        if top == '"':
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if line[idx : idx + 3] == "$((":
                ctx_stack.append(["$((", 0])
                idx += 3
                continue
            if line[idx : idx + 2] == "$(":
                ctx_stack.append(["$(", 0])
                idx += 2
                continue
            if ch == "`":
                ctx_stack.append(["`", 0])
                idx += 1
                continue
            if ch == '"':
                ctx_stack.pop()
            idx += 1
            continue
        if top == "`":
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if ch == "`":
                ctx_stack.pop()
            idx += 1
            continue
        if top in ("$((", "arith_("):
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if ch == "(":
                ctx_stack.append(["arith_(", 0])
                idx += 1
                continue
            if line[idx : idx + 2] == "))" and top == "$((":
                ctx_stack.pop()
                idx += 2
                continue
            if ch == ")" and top == "arith_(":
                ctx_stack.pop()
            idx += 1
            continue
        if top in ("$(", "<(", ">(", "("):
            if ch == "\\" and idx + 1 < length:
                idx += 2
                continue
            if line[idx : idx + 2] == "$'":
                ctx_stack.append(["$'", 0])
                idx += 2
                continue
            if ch in ("'", '"'):
                ctx_stack.append([ch, 0])
                idx += 1
                continue
            if ch == "`":
                ctx_stack.append(["`", 0])
                idx += 1
                continue
            if line[idx : idx + 3] == "$((":
                ctx_stack.append(["$((", 0])
                idx += 3
                continue
            if line[idx : idx + 2] in ("$(", "<(", ">("):
                ctx_stack.append([line[idx : idx + 2], 0])
                idx += 2
                continue
            if ch == "(":
                ctx_stack.append(["(", 0])
                idx += 1
                continue
            if ch == ")":
                if ctx_stack[-1][1] == 0:
                    ctx_stack.pop()
                idx += 1
                continue
            word_m = _PILOT_SHELL_WORD_RE.match(line, idx)
            if word_m and _is_shell_word_boundary(line, idx, word_m.end()):
                word = word_m.group(0)
                if word == "case":
                    ctx_stack[-1][1] += 1
                elif word == "esac" and ctx_stack[-1][1] > 0:
                    ctx_stack[-1][1] -= 1
                idx = word_m.end()
                continue
            idx += 1
            continue

        if ch in " \t":
            idx += 1
            continue
        if ch == "#" and (idx == 0 or line[idx - 1] in " \t|&;()"):
            break
        if ch == ";":
            at_cmd_start = True
            idx += 1
            continue
        if line[idx : idx + 2] in ("&&", "||"):
            at_cmd_start = True
            idx += 2
            continue
        if ch == "|":
            at_cmd_start = False
            idx += 2 if line[idx : idx + 2] == "|&" else 1
            continue
        if ch == "&":
            at_cmd_start = False
            idx += 1
            continue
        if ch == "{" and at_cmd_start and (idx + 1 == length or line[idx + 1] in " \t"):
            brace_stack.append(next_brace_id)
            next_brace_id += 1
            at_cmd_start = True
            idx += 1
            continue
        if ch == "}" and brace_stack:
            bid = brace_stack.pop()
            rest = line[idx + 1 :].lstrip(" \t")
            if (rest.startswith("|") and not rest.startswith("||")) or re.match(r"^(?:\d*[<>]|&>)", rest):
                piped_or_redirected_braces.add(bid)
            at_cmd_start = False
            idx += 1
            continue

        if at_cmd_start:
            kw_m = _PILOT_SHELL_CONTROL_KW_RE.match(line, idx)
            if kw_m and (idx == 0 or not (line[idx - 1].isalnum() or line[idx - 1] == "_")):
                idx = kw_m.end()
                at_cmd_start = True
                continue
            fixed_m = _PILOT_STANDALONE_FIXED_ECHO_PRINTF_RE.match(line, idx)
            if fixed_m:
                literal = fixed_m.group(1) or fixed_m.group(2)
                body = literal.split("::", 2)[2]
                if not (_PILOT_WORKFLOW_CMD_RAW_RE.search(body) or _PILOT_HASH_BRACKET_CMD_RE.search(body)):
                    candidate_spans.append((idx, fixed_m.end(), tuple(brace_stack)))
                    idx = fixed_m.end()
                    at_cmd_start = False
                    continue

        at_cmd_start = False
        if ch == "\\" and idx + 1 < length:
            idx += 2
            continue
        if line[idx : idx + 2] == "$'":
            ctx_stack.append(["$'", 0])
            idx += 2
            continue
        if ch in ("'", '"'):
            ctx_stack.append([ch, 0])
            idx += 1
            continue
        if ch == "`":
            ctx_stack.append(["`", 0])
            idx += 1
            continue
        if line[idx : idx + 3] == "$((":
            ctx_stack.append(["$((", 0])
            idx += 3
            continue
        if line[idx : idx + 2] in ("$(", "<(", ">("):
            ctx_stack.append([line[idx : idx + 2], 0])
            idx += 2
            continue
        if ch == "(":
            ctx_stack.append(["(", 0])
            idx += 1
            continue
        idx += 1

    chars = list(line)
    for start, end, enclosing_braces in candidate_spans:
        if any(bid in piped_or_redirected_braces for bid in enclosing_braces):
            continue
        for pos in range(start, end):
            chars[pos] = " "
    return "".join(chars)


def _has_piped_compound_or_captured_fn_workflow_cmd(run_text, units):
    """Return offending snippet if a workflow-command token sits inside a piped/redirected compound block or captured/piped function."""
    if not (_PILOT_WORKFLOW_CMD_RAW_RE.search(run_text) or _PILOT_HASH_BRACKET_CMD_RE.search(run_text)):
        return None

    open_blocks = []
    close_for_open = {"{": "}", "if": "fi", "for": "done", "while": "done", "until": "done", "case": "esac"}
    token_re = re.compile(r"(?:\{|\}|\b(?:if|fi|for|while|until|done|case|esac)\b)")
    for kind, text_line in units:
        line_has_cmd = bool(
            _PILOT_WORKFLOW_CMD_RAW_RE.search(text_line)
            or _PILOT_HASH_BRACKET_CMD_RE.search(text_line)
        )
        if kind != "code_top":
            if line_has_cmd:
                for blk in open_blocks:
                    blk[1] = True
            continue
        masked = re.sub(r"'[^']*'|\"(?:\\.|[^\"\\])*\"", lambda m: " " * len(m.group(0)), text_line)
        masked = re.sub(r"(?:^|[ \t|&;()])#.*$", "", masked)
        for tm in token_re.finditer(masked):
            tok = tm.group(0)
            if tok in close_for_open:
                if tok == "{":
                    after = masked[tm.end() :]
                    if after and after[0] not in " \t":
                        continue
                open_blocks.append([close_for_open[tok], line_has_cmd])
            elif tok in ("}", "fi", "done", "esac"):
                for b_idx in range(len(open_blocks) - 1, -1, -1):
                    if open_blocks[b_idx][0] == tok:
                        _, blk_has_cmd = open_blocks.pop(b_idx)
                        has_any = blk_has_cmd or line_has_cmd
                        rest = masked[tm.end() :].lstrip(" \t")
                        if has_any and (
                            (rest.startswith("|") and not rest.startswith("||"))
                            or re.match(r"^(?:\d*[<>]|&>)", rest)
                        ):
                            return text_line[:160]
                        break
        if line_has_cmd:
            for blk in open_blocks:
                blk[1] = True

    fn_def_re = re.compile(
        r"(?:^|[;\n{])[ \t]*(?:function[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*(?:\(\))?[ \t]*\{",
        re.MULTILINE,
    )
    fn_bodies = {}
    fn_spans = {}
    for fm in fn_def_re.finditer(run_text):
        fname = fm.group(1)
        brace_idx = fm.end() - 1
        depth = 0
        end_idx = len(run_text)
        pos = brace_idx
        while pos < len(run_text):
            if run_text[pos] == "'":
                end_q = run_text.find("'", pos + 1)
                pos = (end_q + 1) if end_q != -1 else len(run_text)
                continue
            if run_text[pos] == "{":
                depth += 1
            elif run_text[pos] == "}":
                depth -= 1
                if depth == 0:
                    end_idx = pos + 1
                    break
            pos += 1
        fn_bodies[fname] = run_text[brace_idx:end_idx]
        fn_spans[fname] = (fm.start(), end_idx)

    tainted_fns = {
        fname
        for fname, body in fn_bodies.items()
        if _PILOT_WORKFLOW_CMD_RAW_RE.search(body) or _PILOT_HASH_BRACKET_CMD_RE.search(body)
    }
    changed = True
    while changed and tainted_fns:
        changed = False
        for fname, body in fn_bodies.items():
            if fname in tainted_fns:
                continue
            if any(re.search(r"\b" + re.escape(tf) + r"\b", body) for tf in tainted_fns):
                tainted_fns.add(fname)
                changed = True

    nested_lines = [line for kind, line in units if kind == "code_nested"]
    for fname in tainted_fns:
        span_start, span_end = fn_spans[fname]
        outside = run_text[:span_start] + "\n" + run_text[span_end:]
        fn_call_pat = re.compile(
            r"(?:\$\([^)\n]*\b" + re.escape(fname) + r"\b"
            r"|`[^`\n]*\b" + re.escape(fname) + r"\b"
            r"|[<>]\([^)\n]*\b" + re.escape(fname) + r"\b"
            r"|\b" + re.escape(fname) + r"\b[^\n;|&]*?(?:\|(?!\|)|\d*>>?|&>))"
        )
        m_call = fn_call_pat.search(outside)
        if m_call:
            return m_call.group(0)[:160]
        for nline in nested_lines:
            if re.search(r"\b" + re.escape(fname) + r"\b", nline):
                return nline[:160]
    return None


def _has_unapproved_workflow_command(line, *, allow_fixed_echo=True):
    """Return True if `line` contains any unapproved GitHub Actions workflow command token."""
    if _PILOT_HASH_BRACKET_CMD_RE.search(line):
        return True
    if not _PILOT_WORKFLOW_CMD_RAW_RE.search(line):
        return False
    if "${{" in line:
        return True

    remainder = _strip_standalone_fixed_workflow_commands(line) if allow_fixed_echo else line
    if not _PILOT_WORKFLOW_CMD_RAW_RE.search(remainder):
        return False

    neutralised = remainder
    for pattern in _PILOT_WORKFLOW_CMD_NEUTRALISE_RES:
        neutralised = pattern.sub(lambda m: " " * len(m.group(0)), neutralised)

    for match in _PILOT_WORKFLOW_CMD_RAW_RE.finditer(remainder):
        cmd_name = match.group(1)
        if "-" in cmd_name:
            return True
        lhs = neutralised[:match.start()]
        rhs = remainder[match.end():]
        if not _PILOT_PHP_CLASS_LHS_RE.search(lhs):
            return True
        if _PILOT_WORKFLOW_CMD_RHS_RE.match(rhs):
            return True
    return False
_PILOT_SHELL_EVAL_RE = re.compile(
    _CMD_POS
    + r"(?:(?:command|builtin|env|sudo|exec)\s+)*(?:[A-Za-z_][A-Za-z0-9_]*=[^\s;|&]+\s+)*eval(?:\s|$)"
)
_PILOT_VAR_INDIRECTION_RE = re.compile(
    r"(?:\$\{![A-Za-z_][A-Za-z0-9_]*|"
    r"\b(?:declare|typeset|local|export|readonly)\s+(?:-[A-Za-z]+\s+)*-[A-Za-z]*n\b|"
    r"\bprintf\s+(?:-[A-Za-z]+\s+)*-v\b)"
)
_PILOT_GITHUB_FORBIDDEN_FILE_ENV_RE = re.compile(
    r"\bGITHUB_(?:OUTPUT|STATE|PATH)\b"
)
_PILOT_GITHUB_ALIAS_ENV_RE = re.compile(
    r"\bGITHUB_(?:STEP_SUMMARY|ENV|OUTPUT|STATE|PATH)\b"
)
_PILOT_BARE_VAR_REDIRECT_RE = re.compile(
    r'(?<![=-])(?:\d*>>?)\s*(?:"\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?"|\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?)(?=\s|$|[;|&)])'
)
_PILOT_CMD_SUB_REDIRECT_RE = re.compile(
    r'(?<![=-])(?:\d*>>?)\s*["\']?(?:\$\(|`)'
)
_PILOT_BARE_VAR_TEE_RE = re.compile(
    r'\btee\b(?:\s+-[A-Za-z]+)*\s+(?:"\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?"|\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?)(?=\s|$|[;|&)])'
)
_PILOT_GITHUB_ENV_APPEND_RE = re.compile(
    r"^cat (/tmp/[A-Za-z0-9._-]+\.env) >> \"\$GITHUB_ENV\"$"
)
_PILOT_TMP_ENV_PATH_RE = re.compile(r"/tmp/[A-Za-z0-9._-]+\.env\b")
_PILOT_FIXTURE_LOG_READ_RE = re.compile(
    _CMD_POS
    + r"(?:(?:cat|tail|head|sed|awk|cut)\b[^\n;|]*?/tmp/[A-Za-z0-9._-]*fixture[A-Za-z0-9._-]*\.log\b|"
    + r"grep\b(?![^\n;|]*?(?:^|\s)-[A-Za-z]*q)[^\n;|]*?/tmp/[A-Za-z0-9._-]*fixture[A-Za-z0-9._-]*\.log\b)"
)


def _mask_shell_comments_and_single_quotes(run_text):
    """Mask comment lines and single-line single-quoted strings with equal-length spaces."""
    no_comments = re.sub(r"(?m)^\s*#[^\n]*", lambda m: " " * len(m.group(0)), run_text)
    return re.sub(r"'[^'\n]*'", lambda m: "'" + (" " * (len(m.group(0)) - 2)) + "'", no_comments)


def _extract_var_assignments_with_spans(var_name, run_text):
    """Return `(assign_start, rhs_end, rhs_str)` for every assignment to `var_name` in `run_text`."""
    assignments = []
    masked = _mask_shell_comments_and_single_quotes(run_text)
    pattern = re.compile(
        r"(?:^|[;|&\n{]|\)\s*|\b(?:then|else|do)\s+)\s*"
        r"(?:(?:export|declare|typeset|local|readonly)(?:\s+-[A-Za-z]+)*\s+)?"
        + re.escape(var_name)
        + r"=(?!=)",
        re.MULTILINE,
    )
    for match in pattern.finditer(masked):
        start = match.end()
        tail_real = run_text[start:]
        rhs_match = re.match(
            r"(\$\(\([^)\n]*\)\)|'[^'\n]*'|\"[^\"\n]*\"|[^\s;|&)\n]+)",
            tail_real,
        )
        if rhs_match:
            assignments.append((match.start(), start + rhs_match.end(), rhs_match.group(1).strip()))
    return assignments


def _extract_var_assignments(var_name, run_text):
    """Return every RHS assigned to `var_name` at command position in `run_text`."""
    return [rhs for _, _, rhs in _extract_var_assignments_with_spans(var_name, run_text)]


def _has_non_simple_var_mutation(var_name, run_text):
    """Detect `+=`, `read`, `for`, `let`, or `((...))` mutations of `var_name`."""
    masked = _mask_shell_comments_and_single_quotes(run_text)
    esc = re.escape(var_name)
    if re.search(rf"(?<![A-Za-z0-9_]){esc}\+=", masked):
        return True
    if re.search(rf"\b(?:read\s+(?:-[A-Za-z]+\s+)*|for\s+){esc}\b", masked):
        return True
    if re.search(rf"\(\([^)]*\b{esc}\s*(?:[+\-*/%&|^]?=|\+\+|--)", masked):
        return True
    if re.search(rf"\blet\b[^\n;]*\b{esc}\b", masked):
        return True
    return False


def _is_strictly_digit_guarded_copy(src_var, copy_start, copy_end, run_text, inherited_env):
    """Verify `target="$src_var"` lies inside the post-digit-check arm of an immutable `src_var`."""
    if src_var in inherited_env or _has_non_simple_var_mutation(src_var, run_text):
        return False
    src_assignments = _extract_var_assignments_with_spans(src_var, run_text)
    if len(src_assignments) != 1:
        return False
    _, src_assign_end, _ = src_assignments[0]

    masked = _mask_shell_comments_and_single_quotes(run_text)
    case_re = re.compile(
        r'case\s+"\$' + re.escape(src_var) + r'"\s+in\b(.*?)\besac\b',
        re.DOTALL,
    )
    case_matches = list(case_re.finditer(masked))
    if len(case_matches) != 1:
        return False
    case_match = case_matches[0]
    if src_assign_end >= case_match.start():
        return False

    body_start, body_end = case_match.span(1)
    real_body = run_text[body_start:body_end]
    masked_body = masked[body_start:body_end]
    if re.search(r"\bcase\b", masked_body):
        return False

    first_terminator = masked_body.find(";;")
    if first_terminator < 0:
        return False
    first_arm_real = real_body[:first_terminator]
    if not re.match(r"^\s*''\|\*\[!0-9\]\*\)", first_arm_real):
        return False
    if re.search(rf"\$\{{?{re.escape(src_var)}\b", first_arm_real):
        return False

    validated_region_start = body_start + first_terminator + 2
    return validated_region_start <= copy_start and copy_end <= body_end


def _verify_pilot_var_allowlist(var_name, run_text, inherited_env, closed_tokens, allow_int_arith):
    """Verify every assignment to `var_name` in `run_text` is allow-listed.

    Returns a list of reason strings (empty when safe).
    """
    reasons = []
    if var_name in inherited_env:
        reasons.append(f"variable ${var_name} comes from workflow/job/step env without closed mapping")
    if _has_non_simple_var_mutation(var_name, run_text):
        reasons.append(f"variable ${var_name} mutated via +=, read, for, let, or ((...))")
    assignments = _extract_var_assignments_with_spans(var_name, run_text)
    if not assignments:
        reasons.append(f"variable ${var_name} has no local allowlisted assignment")
        return reasons
    for assign_start, rhs_end, rhs in assignments:
        unquoted = rhs[1:-1] if (len(rhs) >= 2 and rhs[0] == rhs[-1] == "'") else rhs
        if unquoted in closed_tokens and "$" not in rhs and "`" not in rhs:
            continue
        if allow_int_arith and re.fullmatch(r"\$\(\([A-Za-z0-9_ +*/%-]+\)\)", rhs):
            continue
        src_match = re.fullmatch(r'"\$([A-Za-z_][A-Za-z0-9_]*)"', rhs)
        if src_match and _is_strictly_digit_guarded_copy(
            src_match.group(1), assign_start, rhs_end, run_text, inherited_env
        ):
            continue
        reasons.append(f"variable ${var_name} assigned non-allowlisted RHS: {rhs}")
    return reasons


def pilot_gate_public_output_violations(text):
    r"""Static regression tripwire for public output surfaces in `.github/workflows/pilot-gate.yml`.

    IMPORTANT SCOPE NOTE: This guard is a static REGRESSION tripwire against
    accidental reintroduction of untrusted/dynamic data on public GitHub output
    surfaces in `pilot-gate.yml`. It is NOT a security boundary and NOT a proof
    of absence of leaks.

    Surfaces checked after parsing YAML (`yaml.safe_load`):
      1. PR evidence comments (`post-pr-evidence.sh` & `pr-evidence-comment.sh`):
         zero direct references to `pr-evidence-comment.sh` anywhere; exact 10
         `(body_path, family)` pairs; each evidence body file may appear ONLY
         inside its owning `post-pr-evidence.sh` step on exactly two lines (one
         single-line single-quoted truncating `printf`/`echo > <body_path>`
         with closed-token or strictly digit-guarded variables, followed by one
         `post-pr-evidence.sh <body_path> "<family>"` call); no `cat`/`grep`/
         `tail`/`head`/`sed`/`awk`/`cut` inside any `post-pr-evidence.sh` step.
      2. `$GITHUB_STEP_SUMMARY`: no compound `{ ... } >> "$GITHUB_STEP_SUMMARY"`;
         every write is a single-line single-quoted `echo` or `printf` with
         closed-token, arithmetic-integer, or strictly digit-guarded variables.
      3. Workflow commands (`::error`, `::warning`, `::notice`, `::debug`,
         `::group`, `::endgroup`, `::add-mask`, `::set-output`, `::save-state`,
         `::stop-commands`, case-insensitively, plus `##[...]` and `##vso[...]`):
         detected across `run:` steps and non-`run` YAML string keys/values
         (including workflow/job/step `env:`, `name:`, `with:`), excluding only
         real PHP `Class::member` / `\Ns\Class::member` scope-resolution tokens
         whose left side is a genuine class identifier path (after neutralising
         escapes, `printf` formats, and `$` expansions) and whose right side has
         no workflow-command `::` delimiter. Fixed-literal exemption applies
         ONLY to standalone top-level simple-command single-quoted lowercase
         `echo '::(error|warning|notice)::...'` or newline-terminated
         `printf '::(error|warning|notice)::...\n'` at top-level statement
         boundaries (`;`, `&&`, `||`, unpiped/unredirected `{ `, or shell
         control keywords `if`/`then`/`else`/`elif`/`do`/`while`/`until`) —
         NEVER inside `$(...)`, backticks, `<(...)`, `>(...)`, subshells `(...)`,
         enclosing quotes, pipelines (`|`/`|&`), redirections (`>`/`<`),
         piped/redirected compound blocks, functions captured in substitutions
         or pipelines, heredoc bodies, or non-`run` YAML strings. Heredocs use
         Bash delimiter quote removal (`<<EOF`, `<<'EOF'`, `<<"EOF"`, `<<\EOF`,
         `<<$'EOF'`, mixed quotes, multiple heredocs per line, ignoring `<<` in
         quotes and `<<<` here-strings) and exact LF-line terminator matching
         (`raw_line == delim` for `<<`, `raw_line.lstrip('\t') == delim` for
         `<<-`, preserving `\r` so `EOF `, `EOF\r`, `EOF\u00a0`, leading spaces
         on `<<` or `<<-`, and case mismatches do not terminate); heredoc
         payload lines (including `#`-leading lines and unterminated heredoc
         bodies) are scanned with `allow_fixed_echo=False`.
      4. `$GITHUB_ENV`, `$GITHUB_OUTPUT`, dynamic indirection & fixture echoes:
         `GITHUB_OUTPUT`/`GITHUB_STATE`/`GITHUB_PATH` forbidden; `eval`,
         `${!var}`, `declare -n`, `printf -v`, `env:` aliases of GitHub command
         files, command-substitution redirects, bare-variable `tee`, and
         unallowlisted bare-variable shell redirects rejected; `GITHUB_ENV`
         receives only `cat /tmp/<allowed>.env >> "$GITHUB_ENV"`; no stdout
         dump or non-quiet `grep` of `/tmp/*.env` or `/tmp/*fixture*.log`.

    Known static-analysis blind spots (disclosed, not claimed covered):
      - Indirect scripts invoked from `run:` (`php ...`, `python3 ...`,
        `bash ...`, `wp eval-file ...`, composite actions) inherit runner
        environment variables and can emit workflow commands or write command
        files internally without being parsed by this YAML guard.
      - Split-string or computed constructions that never spell `::<command>`,
        `##[`, `##vso[`, or `GITHUB_STEP_SUMMARY`/`GITHUB_ENV`/`GITHUB_OUTPUT`
        contiguously in the YAML text and do not redirect through shell
        `>`/`>>`/`tee` (for example `printf ':%s' ':ERROR::...'`,
        `echo ':'':error::...'`, `python3 -c 'print("##" + "[error]" + x)'`,
        character-code assembly, or
        `open(os.environ["GITHUB_" + "STEP_SUMMARY"], "a")` in inline
        Python/PHP), as well as whole-command execution via a shell variable
        (`$CMD`) without `eval`, are not statically detectable here.
      - Multi-statement file-descriptor plumbing (`exec > /tmp/file` / `exec 3>&1`
        or background FIFO co-processes) that diverts stdout of a standalone
        fixed `echo '::error::...'` across separate statements and later
        concatenates it with dynamic data via `paste`/`tr` is not tracked across
        statements (steps such as `Restore Drill` legitimately redirect whole
        step logs via `exec > >(tee -i /tmp/restore.log) 2>&1`).
      - Intentional friction: fixed single-quoted uppercase/mixed-case
        `::ERROR::`/`::Error::` literals, fixed annotations with `file=/line=/title=`
        parameters, fixed `##[group]`/`##[...]` markers, an 11th evidence family,
        or a 5th job are rejected unless explicitly reviewed and updated.
      - Raw workflow job logs remain public on a public repository (including
        `tee` / `cat` / `head` stdout lines and runner-rendered step `env:`
        headers populated via `$GITHUB_ENV`).
    """
    violations = []
    doc = _yaml_parser().safe_load(text)
    if not isinstance(doc, dict):
        return [("YAML_ROOT", "<document>", "pilot-gate.yml did not parse to a mapping")]

    workflow_env = doc.get("env") if isinstance(doc.get("env"), dict) else {}
    workflow_env_dump = _yaml_parser().safe_dump(workflow_env, allow_unicode=True)
    if _PILOT_DIRECT_EVIDENCE_COMMENT_RE.search(workflow_env_dump):
        violations.append(("DIRECT_PR_EVIDENCE_COMMENT_CALL", "<workflow-env>", workflow_env_dump[:160]))
    if _PILOT_GITHUB_ALIAS_ENV_RE.search(workflow_env_dump):
        violations.append(("DYNAMIC_GITHUB_FILE_ALIAS", "<workflow-env>", workflow_env_dump[:160]))
    for wf_str in _iter_yaml_strings(workflow_env):
        for wf_line in wf_str.splitlines() or [wf_str]:
            if _has_unapproved_workflow_command(wf_line, allow_fixed_echo=False):
                violations.append(("ANNOTATION_NON_LITERAL", "<workflow-env>", wf_line[:160]))

    jobs = doc.get("jobs") or {}
    if set(jobs.keys()) != set(_PILOT_EXPECTED_JOBS.keys()):
        violations.append((
            "JOB_SET",
            "<jobs>",
            f"expected jobs {sorted(_PILOT_EXPECTED_JOBS)}, got {sorted(jobs.keys())}",
        ))
    for job_name, expected_needs in _PILOT_EXPECTED_JOBS.items():
        job_obj = jobs.get(job_name)
        if not isinstance(job_obj, dict):
            continue
        raw_needs = job_obj.get("needs")
        if raw_needs is None:
            needs_set = set()
        elif isinstance(raw_needs, str):
            needs_set = {raw_needs}
        else:
            needs_set = set(raw_needs)
        if needs_set != expected_needs:
            violations.append((
                "JOB_NEEDS",
                job_name,
                f"expected needs={sorted(expected_needs)}, got {sorted(needs_set)}",
            ))

    seen_families = []

    for job_name, job_obj in jobs.items():
        if not isinstance(job_obj, dict):
            continue
        job_env = job_obj.get("env") if isinstance(job_obj.get("env"), dict) else {}
        job_env_dump = _yaml_parser().safe_dump(job_env, allow_unicode=True)
        if _PILOT_DIRECT_EVIDENCE_COMMENT_RE.search(job_env_dump):
            violations.append(("DIRECT_PR_EVIDENCE_COMMENT_CALL", job_name, job_env_dump[:160]))
        if _PILOT_GITHUB_ALIAS_ENV_RE.search(job_env_dump):
            violations.append(("DYNAMIC_GITHUB_FILE_ALIAS", job_name, job_env_dump[:160]))
        for jb_str in _iter_yaml_strings(job_env):
            for jb_line in jb_str.splitlines() or [jb_str]:
                if _has_unapproved_workflow_command(jb_line, allow_fixed_echo=False):
                    violations.append(("ANNOTATION_NON_LITERAL", job_name, jb_line[:160]))

        steps = job_obj.get("steps") or []
        for index, step in enumerate(steps):
            if not isinstance(step, dict):
                continue
            step_label = f"{job_name}:{step.get('name') or f'step#{index}'}"
            step_env = step.get("env") if isinstance(step.get("env"), dict) else {}
            step_meta = {k: v for k, v in step.items() if k != "run"}
            step_meta_dump = _yaml_parser().safe_dump(step_meta, allow_unicode=True)
            if _PILOT_DIRECT_EVIDENCE_COMMENT_RE.search(step_meta_dump):
                violations.append(("DIRECT_PR_EVIDENCE_COMMENT_CALL", step_label, step_meta_dump[:160]))
            if _PILOT_GITHUB_FORBIDDEN_FILE_ENV_RE.search(step_meta_dump):
                violations.append(("GITHUB_OUTPUT_FORBIDDEN", step_label, step_meta_dump[:160]))
            elif _PILOT_GITHUB_ALIAS_ENV_RE.search(step_meta_dump):
                violations.append(("DYNAMIC_GITHUB_FILE_ALIAS", step_label, step_meta_dump[:160]))
            for meta_key in ("name", "env", "with"):
                if meta_key not in step:
                    continue
                for meta_str in _iter_yaml_strings(step.get(meta_key)):
                    for meta_line in meta_str.splitlines() or [meta_str]:
                        if _has_unapproved_workflow_command(meta_line, allow_fixed_echo=False):
                            violations.append(("ANNOTATION_NON_LITERAL", step_label, meta_line[:160]))

            for basename, owned_path in _PILOT_EVIDENCE_BODY_BASENAME_TO_PATH.items():
                if owned_path in step_meta_dump or basename in step_meta_dump:
                    violations.append(("EVIDENCE_BODY_FOREIGN_STEP", step_label, owned_path))

            run_text = step.get("run")
            if not isinstance(run_text, str):
                continue

            inherited_env = {}
            inherited_env.update(workflow_env)
            inherited_env.update(job_env)
            inherited_env.update(step_env)

            logical = [(ln, line.strip()) for ln, line in _logical_lines(run_text) if line.strip()]
            owned_body_path = None

            # --- Surface 0: direct pr-evidence-comment.sh & dynamic indirection
            for _, line in logical:
                if line.startswith("#"):
                    continue
                if _PILOT_DIRECT_EVIDENCE_COMMENT_RE.search(line):
                    violations.append(("DIRECT_PR_EVIDENCE_COMMENT_CALL", step_label, line[:160]))
                if _PILOT_SHELL_EVAL_RE.search(line):
                    violations.append(("DYNAMIC_SHELL_EVAL", step_label, line[:160]))
                if _PILOT_VAR_INDIRECTION_RE.search(line):
                    violations.append(("DYNAMIC_VAR_INDIRECTION", step_label, line[:160]))
                if _PILOT_GITHUB_FORBIDDEN_FILE_ENV_RE.search(line):
                    violations.append(("GITHUB_OUTPUT_FORBIDDEN", step_label, line[:160]))
                if "${{" in line and _PILOT_GITHUB_ALIAS_ENV_RE.search(line):
                    violations.append(("DYNAMIC_GITHUB_FILE_ALIAS", step_label, line[:160]))
                if _PILOT_CMD_SUB_REDIRECT_RE.search(line):
                    violations.append(("DYNAMIC_REDIRECT_TARGET", step_label, line[:160]))
                if _PILOT_BARE_VAR_TEE_RE.search(line):
                    violations.append(("DYNAMIC_REDIRECT_TARGET", step_label, line[:160]))
                for redir_match in _PILOT_BARE_VAR_REDIRECT_RE.finditer(line):
                    redir_var = redir_match.group(1) or redir_match.group(2)
                    if redir_var in ("GITHUB_STEP_SUMMARY", "GITHUB_ENV"):
                        continue
                    expected_rhs = _PILOT_ALLOWED_LOCAL_REDIRECT_VARS.get(redir_var)
                    if expected_rhs is None or redir_var in inherited_env or _has_non_simple_var_mutation(redir_var, run_text):
                        violations.append(("DYNAMIC_REDIRECT_TARGET", step_label, line[:160]))
                        continue
                    local_assigns = _extract_var_assignments(redir_var, run_text)
                    if local_assigns != [expected_rhs]:
                        violations.append(("DYNAMIC_REDIRECT_TARGET", step_label, line[:160]))

            # --- Surface 1: post-pr-evidence.sh steps ------------------------
            if "post-pr-evidence.sh" in run_text:
                for _, line in logical:
                    if not line.startswith("#") and _PILOT_EVIDENCE_READER_RE.search(line):
                        violations.append((
                            "EVIDENCE_FORBIDDEN_READER",
                            step_label,
                            line[:160],
                        ))
                post_indexed = [
                    (idx, line)
                    for idx, (_, line) in enumerate(logical)
                    if not line.startswith("#") and "post-pr-evidence.sh" in line
                ]
                if len(post_indexed) != 1:
                    violations.append((
                        "EVIDENCE_CALL_COUNT",
                        step_label,
                        f"expected 1 post-pr-evidence.sh logical line, got {len(post_indexed)}",
                    ))
                else:
                    post_idx, post_line = post_indexed[0]
                    call_match = _PILOT_POST_CALL_RE.fullmatch(post_line)
                    if not call_match:
                        violations.append((
                            "EVIDENCE_CALL_SHAPE",
                            step_label,
                            post_line[:160],
                        ))
                    else:
                        body_path, family = call_match.group(1), call_match.group(2)
                        seen_families.append(family)
                        if _PILOT_EXPECTED_EVIDENCE_BODY_TO_FAMILY.get(body_path) != family:
                            violations.append((
                                "EVIDENCE_BODY_FAMILY_MISMATCH",
                                step_label,
                                f"{body_path} -> {family}",
                            ))
                        else:
                            owned_body_path = body_path
                        body_basename = body_path.rsplit("/", 1)[-1]
                        if re.search(r"[};)]\s*>>?\s*" + re.escape(body_path) + r"\b", run_text):
                            violations.append((
                                "EVIDENCE_COMPOUND_REDIRECT",
                                step_label,
                                body_path,
                            ))
                        body_refs = [
                            (idx, line)
                            for idx, (_, line) in enumerate(logical)
                            if not line.startswith("#")
                            and idx != post_idx
                            and (body_path in line or body_basename in line)
                        ]
                        if not body_refs:
                            violations.append((
                                "EVIDENCE_BODY_MISSING_WRITER",
                                step_label,
                                body_path,
                            ))
                        elif len(body_refs) > 1:
                            violations.append((
                                "EVIDENCE_BODY_WRITER",
                                step_label,
                                f"expected exactly 1 writer line for {body_path}, got {len(body_refs)}",
                            ))
                        for ref_idx, ref_line in body_refs:
                            if ref_idx > post_idx:
                                violations.append((
                                    "EVIDENCE_BODY_WRITER",
                                    step_label,
                                    f"writer/reference after post-pr-evidence.sh: {ref_line[:120]}",
                                ))
                            p_match = _PILOT_PRINTF_CALL_RE.fullmatch(ref_line)
                            e_match = _PILOT_ECHO_CALL_RE.fullmatch(ref_line)
                            if p_match and p_match.group(3) == body_path:
                                fmt_text = p_match.group(1)
                                if "\\n" in fmt_text[:-2]:
                                    violations.append((
                                        "EVIDENCE_BODY_WRITER",
                                        step_label,
                                        f"multi-line printf format forbidden: {ref_line[:120]}",
                                    ))
                                var_names = re.findall(r'"\$([A-Za-z_][A-Za-z0-9_]*)"', p_match.group(2))
                                for var_name in var_names:
                                    for reason in _verify_pilot_var_allowlist(
                                        var_name,
                                        run_text,
                                        inherited_env,
                                        _PILOT_EVIDENCE_CLOSED_TOKENS,
                                        allow_int_arith=False,
                                    ):
                                        violations.append((
                                            "EVIDENCE_VAR_TAINTED",
                                            step_label,
                                            reason,
                                        ))
                            elif e_match and e_match.group(2) == body_path:
                                pass
                            else:
                                violations.append((
                                    "EVIDENCE_BODY_WRITER",
                                    step_label,
                                    ref_line[:160],
                                ))

            # Exclusive evidence-body ownership across ALL steps: no step may
            # reference any evidence body file other than its own owned_body_path.
            for _, line in logical:
                if line.startswith("#"):
                    continue
                for basename, candidate_path in _PILOT_EVIDENCE_BODY_BASENAME_TO_PATH.items():
                    if candidate_path == owned_body_path:
                        continue
                    if candidate_path in line or basename in line:
                        violations.append((
                            "EVIDENCE_BODY_FOREIGN_STEP",
                            step_label,
                            line[:160],
                        ))

            # --- Surface 2: $GITHUB_STEP_SUMMARY -----------------------------
            if "GITHUB_STEP_SUMMARY" in run_text:
                if re.search(r"[};)]\s*>>?\s*\"?\$GITHUB_STEP_SUMMARY\"?", run_text):
                    violations.append((
                        "SUMMARY_COMPOUND_REDIRECT",
                        step_label,
                        "compound block redirect to $GITHUB_STEP_SUMMARY",
                    ))
                for _, line in logical:
                    if line.startswith("#") or "GITHUB_STEP_SUMMARY" not in line:
                        continue
                    s_echo = _PILOT_SUMMARY_ECHO_RE.fullmatch(line)
                    s_printf = _PILOT_SUMMARY_PRINTF_RE.fullmatch(line)
                    if s_echo:
                        continue
                    if s_printf:
                        fmt_text = s_printf.group(1)
                        if "\\n" in fmt_text[:-2]:
                            violations.append((
                                "SUMMARY_WRITER_SHAPE",
                                step_label,
                                f"multi-line summary printf forbidden: {line[:120]}",
                            ))
                        var_names = re.findall(r'"\$([A-Za-z_][A-Za-z0-9_]*)"', s_printf.group(2))
                        for var_name in var_names:
                            for reason in _verify_pilot_var_allowlist(
                                var_name,
                                run_text,
                                inherited_env,
                                _PILOT_SUMMARY_CLOSED_TOKENS,
                                allow_int_arith=True,
                            ):
                                violations.append((
                                    "SUMMARY_VAR_TAINTED",
                                    step_label,
                                    reason,
                                ))
                    else:
                        violations.append((
                            "SUMMARY_WRITER_SHAPE",
                            step_label,
                            line[:160],
                        ))

            # --- Surface 3: all workflow commands (::name::, ##[, ##vso[) ----
            units = _scan_run_units(run_text)
            surface3_candidates = []
            elided_buf = ""
            elided_allow = True
            spaced_buf = ""
            spaced_allow = True
            for kind, unit_line in units:
                allow_fixed = kind == "code_top"
                surface3_candidates.append((unit_line, allow_fixed))
                if unit_line.endswith("\\"):
                    before_bs = unit_line[:-1]
                    elided_buf += before_bs
                    elided_allow = (
                        elided_allow
                        and allow_fixed
                        and before_bs.endswith((" ", "\t"))
                    )
                    piece = before_bs.rstrip()
                    spaced_buf = f"{spaced_buf} {piece}".strip() if spaced_buf else piece
                    spaced_allow = spaced_allow and allow_fixed
                else:
                    if elided_buf:
                        elided_buf += unit_line
                        surface3_candidates.append((elided_buf, elided_allow and allow_fixed))
                        elided_buf = ""
                        elided_allow = True
                    if spaced_buf:
                        joined_sp = f"{spaced_buf} {unit_line}".strip()
                        surface3_candidates.append((joined_sp, spaced_allow and allow_fixed))
                        spaced_buf = ""
                        spaced_allow = True
            if elided_buf:
                surface3_candidates.append((elided_buf, False))
            if spaced_buf:
                surface3_candidates.append((spaced_buf, False))

            dedup_surface3 = {}
            for cand_line, cand_allow in surface3_candidates:
                if cand_line:
                    dedup_surface3[cand_line] = dedup_surface3.get(cand_line, True) and cand_allow
            for cand_line, cand_allow in dedup_surface3.items():
                if _has_unapproved_workflow_command(cand_line, allow_fixed_echo=cand_allow):
                    violations.append((
                        "ANNOTATION_NON_LITERAL",
                        step_label,
                        cand_line[:160],
                    ))
            wrapped_hit = _has_piped_compound_or_captured_fn_workflow_cmd(run_text, units)
            if wrapped_hit is not None:
                violations.append((
                    "ANNOTATION_NON_LITERAL",
                    step_label,
                    wrapped_hit,
                ))

            # --- Surface 4: $GITHUB_ENV & fixture .env / fixture.log echoes --
            for _, line in logical:
                if line.startswith("#"):
                    continue
                if "GITHUB_ENV" in line:
                    env_match = _PILOT_GITHUB_ENV_APPEND_RE.fullmatch(line)
                    if not env_match or env_match.group(1) not in _PILOT_ALLOWED_GITHUB_ENV_FILES:
                        violations.append((
                            "GITHUB_ENV_WRITER_SHAPE",
                            step_label,
                            line[:160],
                        ))
                    continue

                for env_path in _PILOT_TMP_ENV_PATH_RE.findall(line):
                    allowed_non_echo = (
                        line == f"test -s {env_path}"
                        or re.fullmatch(r"echo '[^']*'", line) is not None
                        or re.search(r"file_put_contents\('" + re.escape(env_path) + r"'", line) is not None
                        or re.search(r"\[(?: !)? -[fs] " + re.escape(env_path) + r"\b", line) is not None
                        or re.fullmatch(r"grep -q '[^']+' " + re.escape(env_path), line) is not None
                        or (
                            f"--env-file {env_path}" in line
                            and "pilot-safe-evidence.py" in line
                            and ">/dev/null" in line
                        )
                    )
                    if not allowed_non_echo:
                        violations.append((
                            "FIXTURE_ENV_STDOUT_ECHO",
                            step_label,
                            line[:160],
                        ))

            for _, line in logical:
                if not line.startswith("#") and _PILOT_FIXTURE_LOG_READ_RE.search(line):
                    violations.append((
                        "FIXTURE_LOG_STDOUT_ECHO",
                        step_label,
                        line[:160],
                    ))

    if set(seen_families) != _PILOT_EVIDENCE_FAMILIES or len(seen_families) != len(_PILOT_EVIDENCE_FAMILIES):
        violations.append((
            "EVIDENCE_FAMILY_SET",
            "<pilot-gate>",
            f"expected {sorted(_PILOT_EVIDENCE_FAMILIES)}, got {seen_families}",
        ))

    return violations


class PilotGatePublicOutputStructuralGuardTests(unittest.TestCase):
    """YAML-parsed structural guard + non-vacuity checks for pilot-gate.yml."""

    def test_pilot_gate_passes_structural_public_output_guard(self):
        violations = pilot_gate_public_output_violations(workflow("pilot-gate.yml"))
        self.assertEqual(violations, [])

    def test_guard_rejects_in_memory_cat_raw_log_in_evidence_body(self):
        base = workflow("pilot-gate.yml")
        anchor = '          bash "$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh" /tmp/doctor-portal-evidence.md "pilot-doctor-portal"'
        self.assertIn(anchor, base)
        mutated_cat = base.replace(
            anchor,
            "          cat /tmp/raw.log >> /tmp/doctor-portal-evidence.md\n" + anchor,
            1,
        )
        violations_cat = pilot_gate_public_output_violations(mutated_cat)
        codes_cat = {code for code, _, _ in violations_cat}
        self.assertEqual(
            {"EVIDENCE_FORBIDDEN_READER", "EVIDENCE_BODY_WRITER"},
            {"EVIDENCE_FORBIDDEN_READER", "EVIDENCE_BODY_WRITER"} & codes_cat,
            f"expected both reader and writer violations, got {violations_cat}",
        )

        # Also verify variable-tainted printf into an evidence body is rejected.
        var_anchor = "            verdict=PASS\n"
        self.assertIn(var_anchor, base)
        mutated_var = base.replace(
            var_anchor,
            '            verdict="$(head -n1 /tmp/doctor-portal.log)"\n',
            1,
        )
        codes_var = {code for code, _, _ in pilot_gate_public_output_violations(mutated_var)}
        self.assertIn("EVIDENCE_VAR_TAINTED", codes_var)

    def test_guard_rejects_in_memory_grep_smoke_log_in_step_summary(self):
        base = workflow("pilot-gate.yml")
        anchor = '          printf \'workflow-smokes: %s\\n\' "$smoke_verdict" >> "$GITHUB_STEP_SUMMARY"'
        self.assertIn(anchor, base)
        mutated = base.replace(
            anchor,
            '          grep -E \'^(PASS|FAIL)\' /tmp/smoke.log >> "$GITHUB_STEP_SUMMARY"',
            1,
        )
        violations = pilot_gate_public_output_violations(mutated)
        codes = {code for code, _, _ in violations}
        self.assertIn("SUMMARY_WRITER_SHAPE", codes, f"expected summary violation, got {violations}")

    def test_guard_rejects_in_memory_raw_error_annotation(self):
        base = workflow("pilot-gate.yml")
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)
        mutated = base.replace(
            anchor,
            '            echo "::error::doctor-portal-browser failed: fail_lines=$(grep \'^FAIL\' /tmp/doctor-portal.log)"',
            1,
        )
        violations = pilot_gate_public_output_violations(mutated)
        codes = {code for code, _, _ in violations}
        self.assertIn("ANNOTATION_NON_LITERAL", codes, f"expected annotation violation, got {violations}")

    def test_guard_rejects_in_memory_github_env_and_fixture_env_echoes(self):
        base = workflow("pilot-gate.yml")
        anchor = '          cat /tmp/resp-public-page.env >> "$GITHUB_ENV"'
        self.assertIn(anchor, base)

        mutated_stdout_echo = base.replace(
            anchor,
            anchor + "\n          cat /tmp/resp-public-page.env",
            1,
        )
        codes_stdout = {code for code, _, _ in pilot_gate_public_output_violations(mutated_stdout_echo)}
        self.assertIn("FIXTURE_ENV_STDOUT_ECHO", codes_stdout)

        mutated_env_echo = base.replace(
            anchor,
            anchor + '\n          echo "RESP_PUBLIC_PAGE=$respPermalink" >> "$GITHUB_ENV"',
            1,
        )
        codes_env = {code for code, _, _ in pilot_gate_public_output_violations(mutated_env_echo)}
        self.assertIn("GITHUB_ENV_WRITER_SHAPE", codes_env)

    def test_guard_workflow_commands_reject_concat_params_and_non_annotation_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)

        # Single-quoted fixed printf literal (no % format, no dynamic text) is accepted.
        accepted_printf = base.replace(
            anchor,
            "            printf '::notice::doctor-portal-browser: fixed literal notice\\n'",
            1,
        )
        self.assertEqual(pilot_gate_public_output_violations(accepted_printf), [])

        rejected_mutations = [
            "            echo '::error::fixed-prefix'\"$SECRET\"",
            "            echo '::error::fixed-prefix'$SECRET",
            "            echo '::error::fixed'; echo \"::error::$SECRET\"",
            "            printf '::error::%s\\n' \"$SECRET\"",
            "            echo '::error::${{ github.actor }}'",
            "            echo \"::error file=foo.php,line=1::$SECRET\"",
            "            echo '::error file=foo.php,line=1::fixed message'",
            "            echo \"::warning title=leak::$SECRET\"",
            "            echo \"::notice file=bar.php::$SECRET\"",
            "            echo \"::debug::$SECRET\"",
            "            echo \"::group::$SECRET\"",
            "            echo \"::endgroup::$SECRET\"",
            "            echo \"::add-mask::$SECRET\"",
            "            echo \"::set-output name=leak::$SECRET\"",
            "            echo \"::save-state name=leak::$SECRET\"",
            "            echo \"::stop-commands::$SECRET\"",
        ]
        for replacement in rejected_mutations:
            with self.subTest(replacement=replacement.strip()):
                mutated = base.replace(anchor, replacement, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

    def test_guard_rejects_direct_pr_evidence_comment_calls_anywhere(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        # 1. Direct call added in an arbitrary non-evidence step.
        non_evidence_anchor = "          echo 'synthetic-seed: PASS' >> \"$GITHUB_STEP_SUMMARY\""
        self.assertIn(non_evidence_anchor, base)
        mutated_run = base.replace(
            non_evidence_anchor,
            non_evidence_anchor
            + '\n          bash "$GITHUB_WORKSPACE/.github/scripts/pr-evidence-comment.sh" --marker pilot-extra --body-file /tmp/raw.md',
            1,
        )
        codes_run = {code for code, _, _ in pilot_gate_public_output_violations(mutated_run)}
        self.assertIn("DIRECT_PR_EVIDENCE_COMMENT_CALL", codes_run)

        # 2. Direct call replacing post-pr-evidence.sh inside an evidence step.
        evidence_anchor = '          bash "$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh" /tmp/doctor-portal-evidence.md "pilot-doctor-portal"'
        self.assertIn(evidence_anchor, base)
        mutated_replace = base.replace(
            evidence_anchor,
            '          bash "$GITHUB_WORKSPACE/.github/scripts/pr-evidence-comment.sh" --marker pilot-doctor-portal --body-file /tmp/doctor-portal-evidence.md',
            1,
        )
        codes_replace = {code for code, _, _ in pilot_gate_public_output_violations(mutated_replace)}
        self.assertIn("DIRECT_PR_EVIDENCE_COMMENT_CALL", codes_replace)

        # 3. Reference hidden in step env metadata.
        env_anchor = "          FIXTURE_OUTCOME: ${{ steps.doctor_portal_fixture.outcome }}"
        self.assertIn(env_anchor, base)
        mutated_env = base.replace(
            env_anchor,
            env_anchor + "\n          COMMENT_HELPER: .github/scripts/pr-evidence-comment.sh",
            1,
        )
        codes_env = {code for code, _, _ in pilot_gate_public_output_violations(mutated_env)}
        self.assertIn("DIRECT_PR_EVIDENCE_COMMENT_CALL", codes_env)

    def test_guard_rejects_foreign_step_or_extra_line_writing_evidence_body(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        # 1. Foreign step writing or appending to an evidence body path.
        foreign_anchor = "          python3 bin/pilot-doctor-portal.py | tee /tmp/doctor-portal.log"
        self.assertIn(foreign_anchor, base)
        for foreign_write in (
            "          echo 'pilot-doctor-portal: PASS' > /tmp/doctor-portal-evidence.md",
            "          printf 'extra\\n' >> /tmp/resp-evidence.md",
        ):
            with self.subTest(foreign_write=foreign_write.strip()):
                mutated_foreign = base.replace(foreign_anchor, foreign_anchor + "\n" + foreign_write, 1)
                codes_foreign = {code for code, _, _ in pilot_gate_public_output_violations(mutated_foreign)}
                self.assertIn("EVIDENCE_BODY_FOREIGN_STEP", codes_foreign)

        # 2. Owning step appending or writing a second line to its own body path.
        post_anchor = '          bash "$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh" /tmp/doctor-portal-evidence.md "pilot-doctor-portal"'
        self.assertIn(post_anchor, base)
        mutated_extra_before = base.replace(
            post_anchor,
            "          echo 'pilot-doctor-portal: PASS' >> /tmp/doctor-portal-evidence.md\n" + post_anchor,
            1,
        )
        codes_before = {code for code, _, _ in pilot_gate_public_output_violations(mutated_extra_before)}
        self.assertIn("EVIDENCE_BODY_WRITER", codes_before)

        mutated_after = base.replace(
            post_anchor,
            post_anchor + "\n          echo 'pilot-doctor-portal: PASS' > /tmp/doctor-portal-evidence.md",
            1,
        )
        codes_after = {code for code, _, _ in pilot_gate_public_output_violations(mutated_after)}
        self.assertIn("EVIDENCE_BODY_WRITER", codes_after)

    def test_guard_rejects_eval_and_detectable_dynamic_github_file_redirection(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "          echo 'synthetic-seed: PASS' >> \"$GITHUB_STEP_SUMMARY\""
        self.assertIn(anchor, base)

        cases = [
            ('          eval \'cat /tmp/seed.json >> "$GITHUB_STEP_SUMMARY"\'', "DYNAMIC_SHELL_EVAL"),
            ('          target_var=GITHUB_STEP_SUMMARY\n          echo \'ok\' >> "${!target_var}"', "DYNAMIC_VAR_INDIRECTION"),
            ('          declare -n out_ref=GITHUB_ENV\n          echo "X=1" >> "$out_ref"', "DYNAMIC_VAR_INDIRECTION"),
            ('          printf -v dyn_target \'%s\' "/tmp/out"\n          echo \'ok\' >> "$dyn_target"', "DYNAMIC_VAR_INDIRECTION"),
            ('          echo "leak=1" >> "$GITHUB_OUTPUT"', "GITHUB_OUTPUT_FORBIDDEN"),
            ('          OUT=/tmp/alias\n          echo \'ok\' >> "$OUT"', "DYNAMIC_REDIRECT_TARGET"),
            ('          echo \'ok\' >> "$(printf /tmp/alias)"', "DYNAMIC_REDIRECT_TARGET"),
            ('          OUT=/tmp/alias\n          echo \'ok\' | tee -a "$OUT"', "DYNAMIC_REDIRECT_TARGET"),
        ]
        for snippet, expected_code in cases:
            with self.subTest(expected_code=expected_code, snippet=snippet.strip()):
                mutated = base.replace(anchor, anchor + "\n" + snippet, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn(expected_code, codes)

        env_anchor = "          SMOKE_OUTCOME: ${{ steps.workflow_smokes.outcome }}"
        self.assertIn(env_anchor, base)
        mutated_step_env_alias = base.replace(
            env_anchor,
            env_anchor + "\n          SUMMARY_ALIAS: ${{ env.GITHUB_STEP_SUMMARY }}",
            1,
        )
        codes_alias = {code for code, _, _ in pilot_gate_public_output_violations(mutated_step_env_alias)}
        self.assertIn("DYNAMIC_GITHUB_FILE_ALIAS", codes_alias)

    def test_guard_rejects_numeric_case_reassignment_after_digit_check(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        # Reproduce the exact case-branch reassignment bypass on `bytes` -> `safe_bytes`.
        case_arm_anchor = (
            "            *)\n"
            "              if [ \"${#bytes}\" -le 10 ]; then\n"
            "                safe_bytes=\"$bytes\""
        )
        self.assertIn(case_arm_anchor, base)
        for reassign_stmt in (
            '              bytes="$(head -n1 /tmp/raw.log)"',
            '              export bytes="$(head -n1 /tmp/raw.log)"',
            '              { bytes="$(head -n1 /tmp/raw.log)"; }',
            '              bytes+="-leak"',
        ):
            with self.subTest(reassign_stmt=reassign_stmt.strip()):
                mutated_reassign = base.replace(
                    case_arm_anchor,
                    "            *)\n"
                    f"{reassign_stmt}\n"
                    "              if [ \"${#bytes}\" -le 10 ]; then\n"
                    "                safe_bytes=\"$bytes\"",
                    1,
                )
                codes_reassign = {code for code, _, _ in pilot_gate_public_output_violations(mutated_reassign)}
                self.assertIn("EVIDENCE_VAR_TAINTED", codes_reassign)
                self.assertIn("SUMMARY_VAR_TAINTED", codes_reassign)

        # Reject copying `$bytes` inside the non-digit rejection arm `''|*[!0-9]*)`.
        reject_arm_anchor = (
            "            ''|*[!0-9]*)\n"
            "              safe_bytes=unavailable"
        )
        self.assertIn(reject_arm_anchor, base)
        mutated_wrong_arm = base.replace(
            reject_arm_anchor,
            "            ''|*[!0-9]*)\n"
            "              safe_bytes=\"$bytes\"",
            1,
        )
        codes_wrong_arm = {code for code, _, _ in pilot_gate_public_output_violations(mutated_wrong_arm)}
        self.assertIn("EVIDENCE_VAR_TAINTED", codes_wrong_arm)
        self.assertIn("SUMMARY_VAR_TAINTED", codes_wrong_arm)

        # Reject copying `$N` outside `case "$N" in ... esac`.
        audit_anchor = "          printf 'audit-verification: PASS chain_verify=OK rows=%s\\n' \"$audit_rows\" >> \"$GITHUB_STEP_SUMMARY\""
        self.assertIn(audit_anchor, base)
        mutated_outside_case = base.replace(
            audit_anchor,
            "          audit_rows=\"$N\"\n" + audit_anchor,
            1,
        )
        codes_outside = {code for code, _, _ in pilot_gate_public_output_violations(mutated_outside_case)}
        self.assertIn("SUMMARY_VAR_TAINTED", codes_outside)

    def test_guard_conscious_friction_rejects_extra_evidence_family_and_extra_job(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        # Conscious friction: an 11th evidence family requires explicit guard review.
        day2_anchor = '          bash "$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh" /tmp/day2-workflows-evidence.md "pilot-day2-workflows"'
        self.assertIn(day2_anchor, base)
        extra_family_step = (
            day2_anchor
            + "\n      - name: Unreviewed 11th evidence family\n"
            + "        run: |\n"
            + "          echo 'pilot-eleventh: PASS' > /tmp/eleventh-evidence.md\n"
            + '          bash "$GITHUB_WORKSPACE/.github/scripts/post-pr-evidence.sh" /tmp/eleventh-evidence.md "pilot-eleventh"'
        )
        codes_family = {
            code for code, _, _ in pilot_gate_public_output_violations(base.replace(day2_anchor, extra_family_step, 1))
        }
        self.assertIn("EVIDENCE_BODY_FAMILY_MISMATCH", codes_family)
        self.assertIn("EVIDENCE_FAMILY_SET", codes_family)

        # Conscious friction: a 5th job in pilot-gate.yml requires explicit guard review.
        extra_job_yaml = (
            base
            + "\n  extra-unreviewed-job:\n"
            + "    runs-on: ubuntu-24.04\n"
            + "    steps:\n"
            + "      - run: echo ok\n"
        )
        codes_job = {code for code, _, _ in pilot_gate_public_output_violations(extra_job_yaml)}
        self.assertIn("JOB_SET", codes_job)

    def test_guard_rejects_escaped_newline_and_printf_format_workflow_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)

        rejected_mutations = [
            "            printf 'x\\n::error::%s\\n' \"$VAR\"",
            "            printf 'x\\r::error::%s\\n' \"$VAR\"",
            "            printf 'x\\t::warning::%s\\n' \"$VAR\"",
            "            printf '%s::error::%s' \"$A\" \"$B\"",
            "            printf '%d::warning::%s\\n' 1 \"$B\"",
            "            printf '%b::notice::%s\\n' '\\n' \"$B\"",
            "            printf 'x\\n::add-mask::%s\\n' \"$VAR\"",
            "            printf 'x\\n::stop-commands::%s\\n' \"$VAR\"",
            "            echo $'a\\n::add-mask::'\"$VAR\"",
            "            echo $'a\\n::stop-commands::'\"$VAR\"",
        ]
        for replacement in rejected_mutations:
            with self.subTest(replacement=replacement.strip()):
                mutated = base.replace(anchor, replacement, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

    def test_guard_rejects_var_prefix_ansic_herestring_and_heredoc_workflow_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)

        single_line_mutations = [
            "            echo \"${NL}::error::$VAR\"",
            "            echo \"$NL::error::$VAR\"",
            "            echo \"$(printf '\\n')::error::$VAR\"",
            "            echo $'a\\n::error::'\"$VAR\"",
            "            cat <<< \"::error::$VAR\"",
        ]
        for replacement in single_line_mutations:
            with self.subTest(replacement=replacement.strip()):
                mutated = base.replace(anchor, replacement, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

        heredoc_mutation = (
            "            cat <<EOF\n"
            "            preamble\n"
            "            ::error::$VAR\n"
            "            EOF"
        )
        codes_heredoc = {
            code for code, _, _ in pilot_gate_public_output_violations(base.replace(anchor, heredoc_mutation, 1))
        }
        self.assertIn("ANNOTATION_NON_LITERAL", codes_heredoc)

    def test_guard_rejects_inline_cat_tee_sed_awk_python_and_php_workflow_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)

        inline_emitters = [
            "            cat /tmp/prefix.txt - <<< \"::error::$VAR\"",
            "            printf 'x\\n::error::%s\\n' \"$VAR\" | tee /tmp/out.log",
            "            sed \"s/^/::error::/\" /tmp/doctor-portal.log",
            "            awk '{print \"::error::\" $0}' /tmp/doctor-portal.log",
            "            python3 -c 'import os; print(\"::error::\" + os.environ.get(\"VAR\", \"\"))'",
            "            python3 -c \"import os; print('x\\\\n::error::' + os.environ.get('VAR', ''))\"",
            "            php -r 'echo \"::error::\" . getenv(\"VAR\") . \"\\n\";'",
            "            php -r 'echo \"x\\n::error::\" . getenv(\"VAR\");'",
        ]
        for replacement in inline_emitters:
            with self.subTest(replacement=replacement.strip()):
                mutated = base.replace(anchor, replacement, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

    def test_guard_accepts_namespaced_php_scope_resolution_comments_and_fixed_printf(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        wp_eval_anchor = "          wp eval-file bin/pilot-smoke.php --path=\"$WP_DIR\" --allow-root 2>&1 | tee /tmp/smoke.log"
        self.assertIn(wp_eval_anchor, base)

        accepted_edits = [
            wp_eval_anchor + "\n          wp eval 'echo Foo::bar(), \\Foo\\Bar::baz(), \\Foo\\Bar::error(\"ok\");' --path=\"$WP_DIR\" --allow-root",
            wp_eval_anchor + "\n          php -r 'echo Foo::bar() . \\Foo\\Bar::baz() . \\Foo\\Bar::notice(\"ok\");'",
            wp_eval_anchor + "\n          # Guard regression comment: printf 'x\\n::error::%s\\n' \"$VAR\" must never execute",
            wp_eval_anchor + "\n          printf '::notice::pilot-smoke: fixed literal notice\\n'",
        ]
        for edit_text in accepted_edits:
            with self.subTest(edit_text=edit_text.splitlines()[-1].strip()):
                mutated = base.replace(wp_eval_anchor, edit_text, 1)
                self.assertEqual(pilot_gate_public_output_violations(mutated), [])

    def test_guard_rejects_case_variant_workflow_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)

        rejected_mutations = [
            "            printf 'x\\n::ERROR::%s\\n' \"$VAR\"",
            "            echo \"::Error::$VAR\"",
            "            echo \"::NOTICE::$VAR\"",
            "            echo \"::WaRnInG::$VAR\"",
            "            echo \"::DEBUG::$VAR\"",
            "            echo \"::GROUP::$VAR\"",
            "            echo \"::ENDGROUP::$VAR\"",
            "            echo \"::Add-Mask::$VAR\"",
            "            echo \"::ADD-MASK::$VAR\"",
            "            echo \"::SET-OUTPUT name=x::$VAR\"",
            "            echo \"::Save-State name=x::$VAR\"",
            "            printf 'x\\n::Stop-Commands::%s\\n' \"$VAR\"",
            "            php -r 'echo \"::ERROR::\" . getenv(\"V\");'",
            "            python3 -c 'import os; print(\"::ERROR::\" + os.environ.get(\"V\", \"\"))'",
            "            cat <<< \"::ERROR::$VAR\"",
            "            echo '::ERROR::doctor-portal-browser: fixed uppercase literal'",
            "            echo '::Error::doctor-portal-browser: fixed mixed-case literal'",
            "            printf '::NOTICE::doctor-portal-browser: fixed uppercase notice\\n'",
            "            echo \"::ER\\\n            ROR::$VAR\"",
        ]
        for replacement in rejected_mutations:
            with self.subTest(replacement=replacement.strip()):
                mutated = base.replace(anchor, replacement, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

        heredoc_uppercase = (
            "            cat <<EOF\n"
            "            preamble\n"
            "            ::ERROR::$VAR\n"
            "            EOF"
        )
        with self.subTest(replacement="heredoc_uppercase"):
            codes_heredoc = {
                code for code, _, _ in pilot_gate_public_output_violations(base.replace(anchor, heredoc_uppercase, 1))
            }
            self.assertIn("ANNOTATION_NON_LITERAL", codes_heredoc)

    def test_guard_rejects_hash_bracket_and_vso_workflow_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])
        anchor = "            echo '::error::doctor-portal-browser: Doctor Portal browser acceptance failed; dynamic diagnostics withheld'"
        self.assertIn(anchor, base)

        rejected_mutations = [
            "            echo \"##[error]$VAR\"",
            "            echo \"##[ERROR]$VAR\"",
            "            printf 'x\\n##[error]%s\\n' \"$VAR\"",
            "            echo \"##[warning]$VAR\"",
            "            echo \"##[notice]$VAR\"",
            "            echo \"##[debug]$VAR\"",
            "            echo \"##[group]$VAR\"",
            "            echo \"##[endgroup]$VAR\"",
            "            echo \"##[command]$VAR\"",
            "            echo \"##[section]$VAR\"",
            "            echo \"##[add-matcher]$VAR\"",
            "            echo \"##[remove-matcher]$VAR\"",
            "            echo \"##[stop-commands]$VAR\"",
            "            echo '##[group]fixed group header'",
            "            echo \"##vso[task.logissue type=error]$VAR\"",
            "            echo \"##VSO[task.setvariable variable=x]$VAR\"",
            "            python3 -c 'print(\"##[error]\" + \"x\")'",
            "            php -r 'echo \"##[error]\" . getenv(\"V\");'",
            "            cat <<< \"##[error]$VAR\"",
            "            cat <<< \"##vso[task.logissue type=error]$VAR\"",
            "            echo \"##[\\\n            error]$VAR\"",
        ]
        for replacement in rejected_mutations:
            with self.subTest(replacement=replacement.strip()):
                mutated = base.replace(anchor, replacement, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

        heredoc_hash_bracket = (
            "            cat <<EOF\n"
            "            preamble\n"
            "            ##[error]$VAR\n"
            "            EOF"
        )
        with self.subTest(replacement="heredoc_hash_bracket"):
            codes_heredoc = {
                code for code, _, _ in pilot_gate_public_output_violations(base.replace(anchor, heredoc_hash_bracket, 1))
            }
            self.assertIn("ANNOTATION_NON_LITERAL", codes_heredoc)

        multiline_quote_hash_bracket = (
            "            echo \"preamble\n"
            "            ##[error]$VAR\""
        )
        with self.subTest(replacement="multiline_quote_hash_bracket"):
            codes_multiline_quote = {
                code
                for code, _, _ in pilot_gate_public_output_violations(
                    base.replace(anchor, multiline_quote_hash_bracket, 1)
                )
            }
            self.assertIn("ANNOTATION_NON_LITERAL", codes_multiline_quote)

    def test_guard_rejects_hash_bracket_and_vso_in_step_and_env_metadata(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        step_env_anchor = "          SMOKE_OUTCOME: ${{ steps.workflow_smokes.outcome }}"
        self.assertIn(step_env_anchor, base)
        for env_addition in (
            "\n          MARKER_PREFIX: '##[error]'",
            "\n          VSO_PREFIX: '##vso[task.logissue type=error]'",
            "\n          UPPER_PREFIX: '::ERROR::'",
        ):
            with self.subTest(scope="step.env", addition=env_addition.strip()):
                mutated = base.replace(step_env_anchor, step_env_anchor + env_addition, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

        step_name_anchor = "      - name: Post Doctor Portal evidence to PR"
        self.assertIn(step_name_anchor, base)
        with self.subTest(scope="step.name"):
            mutated_name = base.replace(
                step_name_anchor,
                "      - name: 'Post Doctor Portal evidence to PR ##[error]'",
                1,
            )
            codes_name = {code for code, _, _ in pilot_gate_public_output_violations(mutated_name)}
            self.assertIn("ANNOTATION_NON_LITERAL", codes_name)

        step_with_anchor = "          apt-log-dir: /tmp"
        self.assertIn(step_with_anchor, base)
        with self.subTest(scope="step.with"):
            mutated_with = base.replace(
                step_with_anchor,
                "          apt-log-dir: '/tmp/##[error]'",
                1,
            )
            codes_with = {code for code, _, _ in pilot_gate_public_output_violations(mutated_with)}
            self.assertIn("ANNOTATION_NON_LITERAL", codes_with)

        wf_env_anchor = "  DB_MAIN: cpms_main"
        self.assertIn(wf_env_anchor, base)
        with self.subTest(scope="workflow.env"):
            mutated_wf_env = base.replace(
                wf_env_anchor,
                wf_env_anchor + "\n  WF_MARKER: '##[error]'",
                1,
            )
            codes_wf_env = {code for code, _, _ in pilot_gate_public_output_violations(mutated_wf_env)}
            self.assertIn("ANNOTATION_NON_LITERAL", codes_wf_env)

        job_env_anchor = "      DB_MAIN: cpms_main"
        self.assertIn(job_env_anchor, base)
        with self.subTest(scope="job.env"):
            mutated_job_env = base.replace(
                job_env_anchor,
                job_env_anchor + "\n      JOB_MARKER: '##vso[task.logissue type=error]'",
                1,
            )
            codes_job_env = {code for code, _, _ in pilot_gate_public_output_violations(mutated_job_env)}
            self.assertIn("ANNOTATION_NON_LITERAL", codes_job_env)

    def test_guard_accepts_mixed_case_php_scope_resolution_and_hash_bracket_comments(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        wp_eval_anchor = "          wp eval-file bin/pilot-smoke.php --path=\"$WP_DIR\" --allow-root 2>&1 | tee /tmp/smoke.log"
        self.assertIn(wp_eval_anchor, base)

        accepted_run_edits = [
            wp_eval_anchor + "\n          wp eval 'echo \\Foo\\Bar::Error(\"ok\"); echo Foo::NOTICE;'",
            wp_eval_anchor + "\n          wp eval 'echo Foo::bar(), \\Foo\\Bar::baz(), \\Foo\\Bar::Error(\"ok\"), Foo::WARNING;' --path=\"$WP_DIR\" --allow-root",
            wp_eval_anchor + "\n          # Shell comment: ##[error] and ##vso[task.logissue type=error] and ::ERROR:: are not executed",
        ]
        for edit_text in accepted_run_edits:
            with self.subTest(edit_text=edit_text.splitlines()[-1].strip()):
                mutated = base.replace(wp_eval_anchor, edit_text, 1)
                self.assertEqual(pilot_gate_public_output_violations(mutated), [])

        yaml_comment_mutated = "# YAML comment mentioning ##[error] and ##vso[task.logissue type=error]\n" + base
        self.assertEqual(pilot_gate_public_output_violations(yaml_comment_mutated), [])

    def test_guard_rejects_nested_and_quoted_fixed_workflow_commands(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        anchor = (
            "      - name: Runtime SDK contract (built ZIP, no network)\n"
            "        run: python3 tests/bin/release-runtime-contract.py\n"
        )
        anchor_block = (
            "      - name: Runtime SDK contract (built ZIP, no network)\n"
            "        run: |\n"
            "          python3 tests/bin/release-runtime-contract.py\n"
        )
        self.assertIn(anchor, base)

        accepted_standalone = [
            "          printf '::notice::fixed notice line\\n'\n",
            "          printf '::warning::fixed warning line\\n'\n",
            "          printf '::error::fixed error line\\n'\n",
            "          if true; then echo '::notice::fixed conditional notice'; fi\n",
        ]
        for snippet in accepted_standalone:
            with self.subTest(scope="accepted_standalone", snippet=snippet.strip()):
                mutated = base.replace(anchor, anchor_block + snippet, 1)
                self.assertEqual(pilot_gate_public_output_violations(mutated), [])

        nested_forms = [
            ("cmdsub_echo", 'echo "$(echo \'::error::fixed\')$VAR"'),
            ("backtick_echo", 'echo "`echo \'::error::fixed\'`$VAR"'),
            ("printf_cmdsub_echo", 'printf \'%s\\n\' "$(echo \'::error::fixed\')"'),
            ("var_capture_echo", 'x=$(echo \'::error::fixed\'); echo "$x$VAR"'),
            ("cmdsub_printf_notice", 'echo "$(printf \'::notice::fixed\\n\')$VAR"'),
            ("cmdsub_hash_bracket", 'echo "x$(echo \'##[error]fixed\')"'),
        ]

        for label, expr in nested_forms:
            with self.subTest(scope="run", case=label):
                mutated_run = base.replace(anchor, anchor_block + f"          {expr}\n", 1)
                codes_run = {code for code, _, _ in pilot_gate_public_output_violations(mutated_run)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes_run)

            with self.subTest(scope="heredoc", case=label):
                heredoc_block = (
                    "          cat <<EOF >/dev/null\n"
                    f"          {expr}\n"
                    "          EOF\n"
                )
                mutated_hd = base.replace(anchor, anchor_block + heredoc_block, 1)
                codes_hd = {code for code, _, _ in pilot_gate_public_output_violations(mutated_hd)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes_hd)

        for label, expr in nested_forms:
            with self.subTest(scope="step.env", case=label):
                yaml_safe = expr.replace("\\", "\\\\").replace('"', '\\"')
                mutated_env = base.replace(
                    anchor,
                    "      - name: Runtime SDK contract (built ZIP, no network)\n"
                    f'        env:\n          STEP_NESTED: "{yaml_safe}"\n'
                    "        run: python3 tests/bin/release-runtime-contract.py\n",
                    1,
                )
                codes_env = {code for code, _, _ in pilot_gate_public_output_violations(mutated_env)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes_env)

    def test_guard_heredoc_exact_bash_terminators_and_quotes(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        anchor = (
            "      - name: Runtime SDK contract (built ZIP, no network)\n"
            "        run: python3 tests/bin/release-runtime-contract.py\n"
        )
        anchor_block = (
            "      - name: Runtime SDK contract (built ZIP, no network)\n"
            "        run: |\n"
            "          python3 tests/bin/release-runtime-contract.py\n"
        )
        self.assertIn(anchor, base)

        rejected_blocks = [
            (
                "trailing_space_hash_bracket",
                "cat <<EOF\nEOF \n##[error]$VAR\nEOF\n",
            ),
            (
                "trailing_space_hash_comment_payload",
                "cat <<EOF\nEOF \n# ::error::$VAR\nEOF\n",
            ),
            (
                "leading_spaces_on_no_dash_heredoc",
                "cat <<EOF\n  EOF\n::error::$VAR\nEOF\n",
            ),
            (
                "leading_spaces_hash_payload_on_no_dash_heredoc",
                "cat <<EOF\n  EOF\n# ::error::$VAR\nEOF\n",
            ),
            (
                "single_quoted_delim_trailing_space",
                "cat <<'EOF'\nEOF \n# ::error::$VAR\nEOF\n",
            ),
            (
                "double_quoted_delim_trailing_space",
                'cat <<"EOF"\nEOF \n# ::error::$VAR\nEOF\n',
            ),
            (
                "backslash_delim_trailing_space",
                "cat <<\\EOF\nEOF \n# ::error::$VAR\nEOF\n",
            ),
            (
                "ansic_delim_trailing_space",
                "cat <<$'EOF'\nEOF \n# ::error::$VAR\nEOF\n",
            ),
            (
                "two_heredocs_one_line_second_payload",
                "cat <<EOF1 >/dev/null; cat <<'EOF2'\nok\nEOF1\n# ::error::$VAR\nEOF2\n",
            ),
            (
                "unterminated_heredoc_uppercase_error",
                "cat <<EOF\n::ERROR::$VAR\n",
            ),
        ]
        for label, block_lines in rejected_blocks:
            indented = "".join(
                f"          {line}" for line in block_lines.splitlines(keepends=True)
            )
            with self.subTest(kind="rejected", case=label):
                mutated = base.replace(anchor, anchor_block + indented, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

        for cr_label, cr_payload in (
            ("trailing_cr_hash_bracket", "##[error]$VAR"),
            ("trailing_cr_hash_prefixed_v2", "# ::error::$VAR"),
        ):
            with self.subTest(kind="rejected", case=cr_label):
                cr_step_new = (
                    "      - name: Runtime SDK contract (built ZIP, no network)\n"
                    '        run: "python3 tests/bin/release-runtime-contract.py\\n'
                    f'cat <<EOF\\nEOF\\r\\n{cr_payload}\\nEOF\\n"\n'
                )
                mutated_cr = base.replace(anchor, cr_step_new, 1)
                codes_cr = {code for code, _, _ in pilot_gate_public_output_violations(mutated_cr)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes_cr)

        accepted_blocks = [
            (
                "dash_heredoc_tab_indented_terminator",
                "          cat <<-EOF >/dev/null\n"
                "          \thello\n"
                "          \tEOF\n"
                "          # real shell comment after terminator: ##[error]$VAR\n",
            ),
            (
                "single_quoted_delim_exact",
                "          cat <<'EOF' >/dev/null\n"
                "          plain payload\n"
                "          EOF\n"
                "          # real shell comment: ##[error]$VAR\n",
            ),
            (
                "double_quoted_delim_exact",
                '          cat <<"EOF" >/dev/null\n'
                "          plain payload\n"
                "          EOF\n"
                "          # real shell comment: ##[error]$VAR\n",
            ),
            (
                "backslash_delim_exact",
                "          cat <<\\EOF >/dev/null\n"
                "          plain payload\n"
                "          EOF\n"
                "          # real shell comment: ##[error]$VAR\n",
            ),
            (
                "ansic_delim_exact",
                "          cat <<$'EOF' >/dev/null\n"
                "          plain payload\n"
                "          EOF\n"
                "          # real shell comment: ##[error]$VAR\n",
            ),
            (
                "two_heredocs_one_line_clean",
                "          cat <<EOF1 >/dev/null; cat <<'EOF2' >/dev/null\n"
                "          first body\n"
                "          EOF1\n"
                "          second body\n"
                "          EOF2\n"
                "          # real shell comment after both: ##[error]$VAR\n",
            ),
            (
                "double_lt_inside_quotes_not_heredoc",
                '          echo "bitshift <<EOF inside quotes" >/dev/null\n'
                "          # real shell comment: ##[error]$VAR\n",
            ),
            (
                "real_shell_comment_hash_bracket",
                "          # shell comment with ##[error] and ::ERROR:: markers\n",
            ),
        ]
        for label, block_text in accepted_blocks:
            with self.subTest(kind="accepted", case=label):
                mutated_ok = base.replace(anchor, anchor_block + block_text, 1)
                self.assertEqual(pilot_gate_public_output_violations(mutated_ok), [])

        with self.subTest(kind="accepted", case="yaml_comment_hash_bracket"):
            yaml_comment_doc = "# YAML comment with ##[error] marker\n" + base
            self.assertEqual(pilot_gate_public_output_violations(yaml_comment_doc), [])

    def test_guard_round5_red_team_nesting_wrappers_and_heredoc_edge_cases(self):
        base = workflow("pilot-gate.yml")
        self.assertEqual(pilot_gate_public_output_violations(base), [])

        anchor = (
            "      - name: Runtime SDK contract (built ZIP, no network)\n"
            "        run: python3 tests/bin/release-runtime-contract.py\n"
        )
        anchor_block = (
            "      - name: Runtime SDK contract (built ZIP, no network)\n"
            "        run: |\n"
            "          python3 tests/bin/release-runtime-contract.py\n"
        )
        self.assertIn(anchor, base)

        red_team_probes = [
            ("RT01_procsub_in", 'cat <(echo \'::error::fixed\') <(echo "$VAR") | tr -d \'\\n\''),
            ("RT02_procsub_out", 'echo \'::error::fixed\' > >(sed "s/fixed/$VAR/")'),
            ("RT03_subshell_pipe", '(echo \'::error::fixed\'; echo "$VAR") | tr -d \'\\n\''),
            ("RT04_brace_pipe_single_line", '{ echo \'::error::fixed\'; echo "$VAR"; } | tr -d \'\\n\''),
            ("RT05_brace_pipe_multiline", "{\n  echo '::error::fixed'\n  echo \"$VAR\"\n} | tr -d '\\n'"),
            ("RT06_if_pipe_multiline", "if true; then\n  echo '::error::fixed'\nfi | tr -d '\\n'"),
            ("RT07_pipe_xargs", 'echo \'::error::fixed\' | xargs -I{} echo "{}$VAR"'),
            ("RT08_pipe_tee", "echo '::error::fixed' | tee /tmp/p.txt"),
            ("RT09_pipe_cat", "echo '::error::fixed' | cat - /tmp/s.txt"),
            ("RT10_printf_no_newline_concat", 'printf \'::error::fixed\'; echo "$VAR"'),
            ("RT11_echo_minus_n_concat", 'echo -n \'::error::fixed\'; echo "$VAR"'),
            ("RT12_fn_captured_in_cmdsub", "emit_p() {\n  echo '::error::fixed'\n}\necho \"$(emit_p)$VAR\""),
            ("RT13_fn_piped", "emit_p() {\n  echo '::error::fixed'\n}\nemit_p | tr -d '\\n'"),
            ("RT14_multiline_cmdsub", "x=$(\n  echo '::error::fixed'\n)\necho \"$x$VAR\""),
            ("RT15_cmdsub_case_arm_paren", "x=$(case a in a) true; echo '::error::fixed';; esac)\necho \"$x$VAR\""),
            ("RT16_adjacent_hash_not_comment", 'echo \'::error::fixed\'#"$VAR"'),
            ("RT17_heredoc_after_and_list", "true && cat <<EOF\nEOF \n##[error]$VAR\nEOF"),
            ("RT18_heredoc_before_pipe", "cat <<EOF | cat\nEOF \n##[error]$VAR\nEOF"),
            ("RT19_heredoc_inside_cmdsub", 'x="$(cat <<EOF\nEOF \n##[error]$VAR\nEOF\n)"\necho "$x"'),
            ("RT20_heredoc_nbsp_terminator", "cat <<EOF\nEOF\u00a0\n##[error]$VAR\nEOF"),
            ("RT21_dash_heredoc_trailing_tab", "cat <<-EOF\n\tEOF\t\n##[error]$VAR\nEOF"),
            ("RT22_dash_heredoc_leading_spaces", "cat <<-EOF\n  EOF\n##[error]$VAR\nEOF"),
            ("RT23_heredoc_case_mismatch_terminator", "cat <<EOF\neof\n##[error]$VAR\nEOF"),
            ("RT24_heredoc_punct_delim", "cat <<'END-MARK.1!'\nEND-MARK.1! \n##[error]$VAR\nEND-MARK.1!"),
            ("RT25_unquoted_heredoc_bs_split", "cat <<EOF\n::ER\\\nROR::$VAR\nEOF"),
            ("RT26_heredoc_bs_joined_fixed_echo", "tr '\\n\\\\' '  ' <<'EOF' | sed \"s/^ *echo *'//;s/' *$/$VAR/\"\necho \\\n  '::error::fixed'\nEOF"),
            ("RT27_transitive_fn_capture", "f2() {\n  echo '::error::fixed'\n}\nf1() {\n  f2\n}\necho \"$(f1)$VAR\""),
            ("RT28_backslash_split_echo_word", "ech\\\no '::error::fixed'"),
        ]
        for probe_id, snippet in red_team_probes:
            indented = "".join(f"          {ln}\n" for ln in snippet.split("\n"))
            with self.subTest(probe=probe_id):
                mutated = base.replace(anchor, anchor_block + indented, 1)
                codes = {code for code, _, _ in pilot_gate_public_output_violations(mutated)}
                self.assertIn("ANNOTATION_NON_LITERAL", codes)

        accepted_controls = [
            ("brace_redirect_without_workflow_cmd", '{ echo "INFO clean"; } > /tmp/clean-check.log'),
            ("top_level_fn_invocation_only", "local_notice() {\n  echo '::notice::fixed helper'\n}\nlocal_notice"),
            ("here_string_not_heredoc", 'cat <<<"plain text" >/dev/null\n# comment after here-string: ##[error]$VAR'),
        ]
        for ctrl_id, snippet in accepted_controls:
            indented = "".join(f"          {ln}\n" for ln in snippet.split("\n"))
            with self.subTest(control=ctrl_id):
                mutated_ctrl = base.replace(anchor, anchor_block + indented, 1)
                self.assertEqual(pilot_gate_public_output_violations(mutated_ctrl), [])


# --- Tooling runner pin contract (runner-pin) ---------------------------------
#
# Every job that executes the shared Tooling action must run on a literal,
# pinned runner label. A `*-latest` label or an unresolved expression would
# let the job silently move to a new OS image again. This group is kept
# separate from the other contract groups so a future diagnostics change
# elsewhere in this file does not need to touch it.


class ToolingRunnerPinContractTests(unittest.TestCase):
    def _tooling_jobs(self):
        yaml = _yaml_parser()
        found = []
        for path in sorted(WORKFLOWS.glob("*.yml")):
            document = yaml.safe_load(path.read_text(encoding="utf-8"))
            if not isinstance(document, dict):
                continue
            jobs = document.get("jobs") or {}
            if not isinstance(jobs, dict):
                continue
            for job_id, job in jobs.items():
                if not isinstance(job, dict):
                    continue
                steps = job.get("steps") or []
                uses_list = [
                    step.get("uses")
                    for step in steps
                    if isinstance(step, dict) and "uses" in step
                ]
                if any(
                    "wp-stack-tooling" in str(value) for value in uses_list
                ):
                    found.append((path.name, job_id, job.get("runs-on")))
        return found

    def test_tooling_jobs_use_literal_pinned_runners(self):
        found = self._tooling_jobs()
        self.assertTrue(found, "no Tooling jobs found; inventory is empty")
        self.assertEqual(
            17,
            len(found),
            "Tooling job inventory changed; expected 17 jobs, found: "
            f"{sorted((name, job_id) for name, job_id, _ in found)}",
        )
        for workflow_name, job_id, runs_on in found:
            label = f"{workflow_name}:{job_id}"
            if isinstance(runs_on, list):
                values = runs_on
            else:
                values = [runs_on]
            for value in values:
                self.assertIsInstance(
                    value,
                    str,
                    f"{label}: runs-on must be a literal string, got {value!r}",
                )
                self.assertNotIn(
                    "${{",
                    value,
                    f"{label}: runs-on must be literal, not an expression: {value!r}",
                )
                self.assertNotIn(
                    "inputs.",
                    value,
                    f"{label}: runs-on must be literal, not an expression: {value!r}",
                )
                self.assertNotIn(
                    "matrix.",
                    value,
                    f"{label}: runs-on must be literal, not an expression: {value!r}",
                )
                self.assertFalse(
                    value.endswith("-latest"),
                    f"{label}: runs-on must not be a *-latest label: {value!r}",
                )

    def test_persian_coexistence_callers_have_no_runs_on(self):
        yaml = _yaml_parser()
        document = yaml.safe_load(
            (WORKFLOWS / "persian-coexistence.yml").read_text(encoding="utf-8")
        )
        jobs = document.get("jobs") or {}
        callers = [
            (job_id, job)
            for job_id, job in jobs.items()
            if isinstance(job, dict)
            and job.get("uses") == "./.github/workflows/real-wp-acceptance.yml"
        ]
        self.assertEqual(
            7,
            len(callers),
            "expected 7 reusable-workflow callers, found: "
            f"{sorted(job_id for job_id, _ in callers)}",
        )
        for job_id, job in callers:
            self.assertNotIn(
                "runs-on",
                job,
                f"persian-coexistence.yml:{job_id}: reusable caller must "
                "not define its own runs-on",
            )


if __name__ == "__main__":
    unittest.main()

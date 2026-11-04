#!/usr/bin/env python3
"""Offline drift lock for the staff-pricing fee-policy projection.

PR #313 made production render `fee_policy` from
`VisitPricingService::FEE_SNAPSHOT_POLICY` instead of the template's old
hard-coded fallback. The companion browser harness still asserted the old
fallback text, and the lane is path-filtered, so the mismatch stayed invisible
until a companion-only PR ran the lane. This test reads the checked-out
production constant, the fee-policy stat label, and the harness assertion, and
FAILS (never skips) if they diverge. Pure Python; no browser, network or PHP.
"""

from __future__ import annotations

import ast
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / "clinic-practice-management/src/Application/Finance/VisitPricingService.php"
TEMPLATE = ROOT / "clinic-practice-management/templates/staff-pricing.php"
HARNESS = ROOT / "tools/disposable-demo-installer/tests/browser-acceptance.py"
CHECK_NAME = "the projection shows the check-in snapshot fee and states the no-reprice policy"
OLD_LITERAL = "تغییر تعرفه پیشفرض"


def php_string_constant(path: Path, name: str) -> str:
    """Read one PHP `public const NAME = '...' . '...';` value without PHP."""
    source = path.read_text(encoding="utf-8")
    marker = f"public const {name}"
    if marker not in source:
        raise AssertionError(f"{name} not found in {path}")
    start = source.index(marker)
    end = source.index(";", start)
    parts = re.findall(r"'((?:[^'\\]|\\.)*)'", source[start:end])
    if not parts:
        raise AssertionError(f"no PHP string literals in {name} at {path}")
    return "".join(part.replace("\\'", "'") for part in parts)


def fee_policy_stat_label(path: Path) -> str:
    """Read the label of `stat('<label>', data.fee_policy || ...)`."""
    source = path.read_text(encoding="utf-8")
    match = re.search(r"stat\(\s*'([^']+)'\s*,\s*data\.fee_policy", source)
    if match is None:
        raise AssertionError(f"fee_policy stat() not found in {path}")
    return match.group(1)


def policy_check_call(path: Path) -> ast.Call:
    """Locate the stage-15c check() call by its stable name (not by line)."""
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    for node in ast.walk(tree):
        if (
            isinstance(node, ast.Call)
            and isinstance(node.func, ast.Name)
            and node.func.id == "check"
            and len(node.args) >= 2
            and isinstance(node.args[0], ast.Constant)
            and node.args[0].value == CHECK_NAME
        ):
            return node
    raise AssertionError(f"{CHECK_NAME!r} check() not found in {path}")


def string_literals(node: ast.AST) -> list[str]:
    return [
        child.value
        for child in ast.walk(node)
        if isinstance(child, ast.Constant) and isinstance(child.value, str)
    ]


class PricingPolicyDrift(unittest.TestCase):
    """Fails on divergence; no skips, no external services."""

    def test_harness_asserts_the_current_production_policy_text(self):
        policy = php_string_constant(SERVICE, "FEE_SNAPSHOT_POLICY")
        label = fee_policy_stat_label(TEMPLATE)
        call = policy_check_call(HARNESS)
        conjuncts = string_literals(call.args[1])

        self.assertTrue(policy, "production FEE_SNAPSHOT_POLICY must not be empty")
        self.assertIn("تعرفه ثبتشده هنگام پذیرش", conjuncts)
        self.assertIn(label, conjuncts, "the stat label must be asserted verbatim")
        self.assertIn(policy, conjuncts, "the harness must assert the exact current policy text")
        self.assertNotIn(
            OLD_LITERAL,
            conjuncts,
            "the stale fallback literal must not replace the current policy text",
        )
        self.assertTrue(
            any(item == policy for item in conjuncts),
            "the policy conjunct must be the whole text, not a shorter fragment",
        )

    def test_only_this_check_publishes_a_600_char_bounded_detail(self):
        tree = ast.parse(HARNESS.read_text(encoding="utf-8"), filename=str(HARNESS))
        checks = [
            node
            for node in ast.walk(tree)
            if isinstance(node, ast.Call)
            and isinstance(node.func, ast.Name)
            and node.func.id == "check"
        ]
        with_cap = [
            node
            for node in checks
            if any(keyword.arg == "detail_cap" for keyword in node.keywords)
        ]
        self.assertEqual(1, len(with_cap), "no other check may widen its detail cap")
        call = with_cap[0]
        keywords = {keyword.arg: keyword.value for keyword in call.keywords}
        self.assertEqual(600, ast.literal_eval(keywords["detail_cap"]))
        self.assertIsInstance(keywords["detail_cap"], ast.Constant)
        detail = call.args[2]
        self.assertIsInstance(detail, ast.Subscript)
        self.assertIsInstance(detail.slice, ast.Slice)
        self.assertEqual(600, ast.literal_eval(detail.slice.upper))
        self.assertEqual(CHECK_NAME, call.args[0].value)

        definition = next(
            node
            for node in ast.walk(tree)
            if isinstance(node, ast.FunctionDef) and node.name == "check"
        )
        defaults = {
            arg.arg: ast.literal_eval(default)
            for arg, default in zip(definition.args.kwonlyargs, definition.args.kw_defaults)
            if default is not None
        }
        self.assertEqual(300, defaults.get("detail_cap"), "every other check stays capped at 300")


if __name__ == "__main__":
    unittest.main(verbosity=2)

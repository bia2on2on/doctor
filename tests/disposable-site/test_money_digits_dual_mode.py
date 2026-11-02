#!/usr/bin/env python3
"""Offline proof for DUAL-MODE money and number checks in the companion harnesses.

Defect 11 converts staff-template money and pricing number display from raw
Latin digits ("500000 ریال") to Persian digits with thousands separators
("۵۰۰٬۰۰۰ ریال"). Product still prints Latin digits on main today, so real
Chromium runs exercise only the Latin branch until Defect 11 lands; the
Persian-digit branch is proven here offline in pure Python, with no browser and
no network.

Scope of this file (every edited companion harness):
  tests/disposable-site/browser-acceptance.py          -> iran_v1_messaging_pricing
  tests/scoped-demo/browser_scoped.py                  -> iran_v1_phase
  tools/disposable-demo-installer/tests/browser-acceptance.py -> journey_visit_pricing_invoice

Placement follows tests/disposable-site/test_dialog_dual_mode.py: the
disposable-site workflow runs
`python3 -m unittest discover -s tests/disposable-site -p 'test_*.py' -v`, so
this file runs automatically in the Offline gates step.
"""

from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest

ROOT = Path(__file__).resolve().parents[2]

HARNESSES = {
    "disposable-site": ROOT / "tests/disposable-site/browser-acceptance.py",
    "scoped-demo": ROOT / "tests/scoped-demo/browser_scoped.py",
    "installer": ROOT / "tools/disposable-demo-installer/tests/browser-acceptance.py",
}


def _install_playwright_stub() -> None:
    """Import-time stub: the harnesses import Playwright but never call it here."""
    if "playwright.sync_api" in sys.modules:
        return
    package = types.ModuleType("playwright")
    sync_api = types.ModuleType("playwright.sync_api")

    class TimeoutError(Exception):  # noqa: A001 - mirrors playwright.sync_api.TimeoutError
        pass

    sync_api.TimeoutError = TimeoutError
    sync_api.sync_playwright = lambda *args, **kwargs: None
    package.sync_api = sync_api
    sys.modules["playwright"] = package
    sys.modules["playwright.sync_api"] = sync_api


def load_harness(name: str, path: Path, evidence_dir: Path):
    """Load one harness module by path without side effects outside temp dir."""
    _install_playwright_stub()
    overrides = {
        "ADMIN_PASS": "offline-dual-mode-test-not-a-secret",
        "CPSD_EVIDENCE_DIR": str(evidence_dir),
    }
    previous = {key: os.environ.get(key) for key in overrides}
    os.environ.update(overrides)
    try:
        spec = importlib.util.spec_from_file_location(f"cpms_money_dual_mode_{name}", path)
        if spec is None or spec.loader is None:  # pragma: no cover - defensive
            raise RuntimeError(f"cannot load harness {path}")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    finally:
        for key, value in previous.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value


class MoneyDigitsDualModeTest(unittest.TestCase):
    """Every edited companion harness accepts Latin and Persian money/number forms."""

    @classmethod
    def setUpClass(cls) -> None:
        cls._tmpdir = tempfile.TemporaryDirectory()
        evidence = Path(cls._tmpdir.name)
        cls.modules = {
            name: load_harness(name, path, evidence / name)
            for name, path in HARNESSES.items()
        }

    @classmethod
    def tearDownClass(cls) -> None:
        cls._tmpdir.cleanup()

    def test_latin_money_form_passes_in_every_harness(self) -> None:
        stats_latin = (
            "تعرفه ثبتشده هنگام پذیرش\n500000 ریال\n"
            "تعرفه پیشفرض فعلی پزشک\n750000 ریال\n"
            "سیاست تعرفه\nتعرفه هنگام پذیرش ثبت میشود"
        )
        context_latin = (
            "پزشک: دکتر نمونه — تعرفه پیشفرض فعلی: 350000 ریال — "
            "سیاست: تعرفه هر ویزیت هنگام پذیرش ثبت میشود"
        )
        for name, mod in self.modules.items():
            with self.subTest(harness=name):
                self.assertTrue(mod.has_money_text(stats_latin, "500000 ریال"))
                self.assertTrue(mod.has_money_text(stats_latin, "750000 ریال"))
                self.assertTrue(mod.has_money_text(context_latin, "350000 ریال"))

    def test_persian_and_arabic_digits_with_separators_pass_in_every_harness(self) -> None:
        stats_persian = (
            "تعرفه ثبتشده هنگام پذیرش\n۵۰۰٬۰۰۰ ریال\n"
            "تعرفه پیشفرض فعلی پزشک\n۷۵۰٬۰۰۰ ریال\n"
            "سیاست تعرفه\nتعرفه هنگام پذیرش ثبت میشود"
        )
        context_persian = (
            "پزشک: دکتر نمونه — تعرفه پیشفرض فعلی: ۳۵۰٬۰۰۰ ریال — "
            "سیاست: تعرفه هر ویزیت هنگام پذیرش ثبت میشود"
        )
        for name, mod in self.modules.items():
            with self.subTest(harness=name):
                self.assertTrue(mod.has_money_text(stats_persian, "500000 ریال"))
                self.assertTrue(mod.has_money_text(stats_persian, "750000 ریال"))
                self.assertTrue(mod.has_money_text(context_persian, "350000 ریال"))
                self.assertTrue(mod.has_money_text("۵۰۰,۰۰۰ ریال", "500000 ریال"))
                self.assertTrue(mod.has_money_text("٥٠٠٬٠٠٠ ریال", "500000 ریال"))
                self.assertTrue(mod.has_money_text("۱٬۰۲۰٬۰۰۰ ریال", "1020000 ریال"))
                self.assertTrue(mod.has_money_text("۵۰۰٬۰۰۰٫۲۵ ریال", "500000.25 ریال"))
                self.assertEqual(
                    mod.normalize_money_digits("واحد, آزمایشی ۱٬۲٬۳٫۵ ریال"),
                    "واحد, آزمایشی 123.5 ریال",
                )

    def test_wrong_amount_fails_in_every_harness(self) -> None:
        wrong_samples = (
            "400000 ریال",
            "۴۰۰٬۰۰۰ ریال",
            "50000 ریال",
            "۵۰٬۰۰۰ ریال",
            "5000000 ریال",
            "۵٬۰۰۰٬۰۰۰ ریال",
            "1500000 ریال",
            "۱٬۵۰۰٬۰۰۰ ریال",
            "500000.25 ریال",
            "۵۰۰٬۰۰۰٫۲۵ ریال",
            "تنظیم نشده",
        )
        for name, mod in self.modules.items():
            for sample in wrong_samples:
                with self.subTest(harness=name, sample=sample):
                    self.assertFalse(mod.has_money_text(sample, "500000 ریال"))
            with self.subTest(harness=name, sample="empty_expected"):
                with self.assertRaises(ValueError):
                    mod.has_money_text("500000 ریال", "")

    def test_missing_rial_unit_fails_in_every_harness(self) -> None:
        unitless_or_wrong_unit = (
            "500000",
            "۵۰۰٬۰۰۰",
            "350000",
            "۳۵۰٬۰۰۰",
            "500000 تومان",
            "۵۰۰٬۰۰۰ تومان",
            "پزشک: دکتر نمونه — تعرفه پیشفرض فعلی: ۳۵۰٬۰۰۰ — سیاست: ...",
            "",
            None,
        )
        for name, mod in self.modules.items():
            for sample in unitless_or_wrong_unit:
                with self.subTest(harness=name, sample=sample):
                    self.assertFalse(mod.has_money_text(sample, "500000 ریال"))
                    self.assertFalse(mod.has_money_text(sample, "350000 ریال"))

    def test_visit_suffix_pattern_and_js_helper_parity(self) -> None:
        js_snippets = {name: mod.MONEY_NORMALIZE_JS for name, mod in self.modules.items()}
        self.assertEqual(len(set(js_snippets.values())), 1)
        for name, mod in self.modules.items():
            with self.subTest(harness=name):
                pattern = mod.digit_agnostic_suffix_pattern("ویزیت ", 1234)
                self.assertRegex("بیمار آزمایشی — ویزیت 1234", pattern)
                self.assertRegex("بیمار آزمایشی — ویزیت ۱۲۳۴", pattern)
                self.assertRegex("بیمار آزمایشی — ویزیت ۱٬۲۳۴", pattern)
                self.assertRegex("بیمار آزمایشی — ویزیت ١٬٢٣٤  ", pattern)
                self.assertNotRegex("بیمار آزمایشی — ویزیت 1235", pattern)
                self.assertNotRegex("بیمار آزمایشی — ویزیت 12345", pattern)
                self.assertNotRegex("بیمار آزمایشی — ویزیت 91234", pattern)
                self.assertNotRegex("ویزیت ۱۲۳۴ متن اضافی", pattern)

    def test_edited_call_sites_stay_wired_to_dual_mode_helpers(self) -> None:
        scoped_src = HARNESSES["scoped-demo"].read_text(encoding="utf-8")
        self.assertIn('has_money_text(stats, "500000 ریال")', scoped_src)
        self.assertIn('has_money_text(stats, "750000 ریال")', scoped_src)
        self.assertNotIn('"500000 ریال" in stats', scoped_src)
        self.assertNotIn('"750000 ریال" in stats', scoped_src)
        self.assertNotIn("cells[2].textContent.trim() === '2'", scoped_src)
        self.assertEqual(scoped_src.count('digit_agnostic_suffix_pattern("ویزیت ", pricing_visit)'), 2)

        disposable_src = HARNESSES["disposable-site"].read_text(encoding="utf-8")
        self.assertIn('"350000 ریال"', disposable_src)
        self.assertIn("has_money_text(", disposable_src)
        self.assertIn("MONEY_NORMALIZE_JS", disposable_src)
        self.assertNotIn('.textContent.includes("350000")', disposable_src)

        installer_src = HARNESSES["installer"].read_text(encoding="utf-8")
        self.assertNotIn("cells[2].textContent.trim() === '2'", installer_src)
        self.assertEqual(installer_src.count('digit_agnostic_suffix_pattern("ویزیت ", pricing_visit)'), 2)


if __name__ == "__main__":
    unittest.main()

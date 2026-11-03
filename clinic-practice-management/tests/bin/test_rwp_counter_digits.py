#!/usr/bin/env python3
"""Offline proof for digit-agnostic facility-plan counter checks.

The management shell prints the facility-plan counters («— ساختمان‌ها: N» and
«— درمانگاه‌ها: N») with PersianDigits, so the digits are Persian (with a
U+066C thousands separator at 1000+); older builds printed raw Latin digits.
The three rwp-*-journey harnesses must accept the same NUMBER in Latin,
Persian or Arabic-Indic digits and keep failing on every other number.

Scope (every harness carrying has_numbered_text):
  bin/rwp-facility-matrix-journey.py   {tag}.auto_site_seeded, {tag}.sites_count
  bin/rwp-portal-hospital-journey.py   org.two_clinics_listed
  bin/rwp-portal-delegated-journey.py  orgA.two_clinics

Pure Python: no browser, no network, Playwright stubbed at import time.
Runnable directly (`python3 tests/bin/test_rwp_counter_digits.py`) and via
`python3 -m unittest discover -s tests/bin -p 'test_rwp_counter_digits.py'`
(the PHPUnit wrapper ManagementShellPersianDigitsTest runs the discover form).
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
    "facility-matrix": ROOT / "bin/rwp-facility-matrix-journey.py",
    "portal-hospital": ROOT / "bin/rwp-portal-hospital-journey.py",
    "portal-delegated": ROOT / "bin/rwp-portal-delegated-journey.py",
}

REQUIRED_ENV = (
    "ORG_MANAGER_USER",
    "ORG_MANAGER_PASS",
    "MATRIX_MANAGER_PASS",
    "CLINIC_MANAGER_A_USER",
    "CLINIC_MANAGER_A_PASS",
    "CLINIC_MANAGER_B_USER",
    "CLINIC_MANAGER_B_PASS",
)


def _install_playwright_stub() -> None:
    """Import-time stub: the harnesses import Playwright but never call it here."""
    if "playwright.sync_api" in sys.modules:
        return
    package = types.ModuleType("playwright")
    sync_api = types.ModuleType("playwright.sync_api")

    class TimeoutError(Exception):  # noqa: A001 - mirrors playwright's own name
        pass

    sync_api.TimeoutError = TimeoutError
    sync_api.sync_playwright = lambda *args, **kwargs: None
    package.sync_api = sync_api
    sys.modules["playwright"] = package
    sys.modules["playwright.sync_api"] = sync_api


def load_harness(name: str, path: Path, out_dir: Path):
    """Load one harness module by path without side effects outside temp dir."""
    _install_playwright_stub()
    overrides = {key: "offline-counter-test" for key in REQUIRED_ENV}
    overrides["OUT"] = str(out_dir)
    previous = {key: os.environ.get(key) for key in overrides}
    os.environ.update(overrides)
    try:
        spec = importlib.util.spec_from_file_location(f"cpms_rwp_counter_{name}", path)
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


class CounterDigitsTest(unittest.TestCase):
    """Every edited harness accepts the counter in all three digit sets only."""

    @classmethod
    def setUpClass(cls) -> None:
        cls._tmpdir = tempfile.TemporaryDirectory()
        out = Path(cls._tmpdir.name)
        cls.modules = {
            name: load_harness(name, path, out / name)
            for name, path in HARNESSES.items()
        }

    @classmethod
    def tearDownClass(cls) -> None:
        cls._tmpdir.cleanup()

    def test_same_number_passes_in_latin_persian_and_arabic_indic(self) -> None:
        passing = (
            ("— ساختمان‌ها: 1", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ۱", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ١", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: 12", "— ساختمان‌ها: ", 12),
            ("— ساختمان‌ها: ۱۲", "— ساختمان‌ها: ", 12),
            ("— ساختمان‌ها: ١٢", "— ساختمان‌ها: ", 12),
            ("— درمانگاه‌ها: 2", "درمانگاه‌ها: ", 2),
            ("— درمانگاه‌ها: ۲", "درمانگاه‌ها: ", 2),
            ("— درمانگاه‌ها: ٢", "درمانگاه‌ها: ", 2),
            ("نوع: X — ساختمان‌ها: ۳ — درمانگاه‌ها: ۲", "— ساختمان‌ها: ", 3),
            ("— ساختمان‌ها: ۱٬۲۳۴", "— ساختمان‌ها: ", 1234),
        )
        for name, mod in self.modules.items():
            for text, prefix, number in passing:
                with self.subTest(harness=name, text=text, number=number):
                    self.assertTrue(mod.has_numbered_text(text, prefix, number))

    def test_wrong_number_fails_in_every_digit_set(self) -> None:
        failing = (
            ("— ساختمان‌ها: 2", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ۲", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ٢", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: 0", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ۰", "— ساختمان‌ها: ", 1),
            ("— درمانگاه‌ها: 1", "درمانگاه‌ها: ", 2),
            ("— درمانگاه‌ها: ۳", "درمانگاه‌ها: ", 2),
            ("— درمانگاه‌ها: ٣", "درمانگاه‌ها: ", 2),
            ("— ساختمان‌ها: ۱۳", "— ساختمان‌ها: ", 12),
        )
        for name, mod in self.modules.items():
            for text, prefix, number in failing:
                with self.subTest(harness=name, text=text, number=number):
                    self.assertFalse(mod.has_numbered_text(text, prefix, number))

    def test_number_inside_a_larger_number_fails(self) -> None:
        embedded = (
            ("— ساختمان‌ها: 12", "— ساختمان‌ها: ", 1),   # trailing digit
            ("— ساختمان‌ها: ۱۲", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ١٢", "— ساختمان‌ها: ", 1),
            ("— درمانگاه‌ها: 21", "درمانگاه‌ها: ", 2),  # leading-digit prefix
            ("— درمانگاه‌ها: ۲۱", "درمانگاه‌ها: ", 2),
            ("— درمانگاه‌ها: 12", "درمانگاه‌ها: ", 2),  # digit after another digit
            ("— ساختمان‌ها: ۱٬۲۳۴", "— ساختمان‌ها: ", 1),  # separator continuation
        )
        for name, mod in self.modules.items():
            for text, prefix, number in embedded:
                with self.subTest(harness=name, text=text, number=number):
                    self.assertFalse(mod.has_numbered_text(text, prefix, number))

    def test_missing_counter_text_fails(self) -> None:
        missing = (
            "",
            None,
            "— ساختمان‌ها:",
            "— ساختمان‌ها: ",
            "درمانگاه",
            "— ساختمان‌ها: یک",
            "ساختمان‌ها: ۱",  # the em-dash anchor is part of the sites prefix
        )
        for name, mod in self.modules.items():
            for text in missing:
                with self.subTest(harness=name, text=text):
                    self.assertFalse(mod.has_numbered_text(text, "— ساختمان‌ها: ", 1))
                    self.assertFalse(mod.has_numbered_text(text, "درمانگاه‌ها: ", 2))

    def test_separator_only_at_thousands_group_boundaries(self) -> None:
        passing = (
            ("— ساختمان‌ها: 1,234", "— ساختمان‌ها: ", 1234),
            ("— ساختمان‌ها: ۱٬۲۳۴", "— ساختمان‌ها: ", 1234),
            ("— ساختمان‌ها: 1234", "— ساختمان‌ها: ", 1234),
            ("— ساختمان‌ها: 123,456", "— ساختمان‌ها: ", 123456),
            ("— ساختمان‌ها: 123456", "— ساختمان‌ها: ", 123456),
        )
        failing = (
            ("— ساختمان‌ها: 1,2", "— ساختمان‌ها: ", 12),
            ("— ساختمان‌ها: ۱٬۲", "— ساختمان‌ها: ", 12),
            ("— ساختمان‌ها: 12,34", "— ساختمان‌ها: ", 1234),
            ("— ساختمان‌ها: 1,23,4", "— ساختمان‌ها: ", 1234),
            ("— ساختمان‌ها: 12", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: ۱۲", "— ساختمان‌ها: ", 1),
            ("— ساختمان‌ها: 1,234", "— ساختمان‌ها: ", 1),
            ("— درمانگاه‌ها: 20", "درمانگاه‌ها: ", 2),
        )
        for name, mod in self.modules.items():
            for text, prefix, number in passing:
                with self.subTest(harness=name, text=text, number=number):
                    self.assertTrue(mod.has_numbered_text(text, prefix, number))
            for text, prefix, number in failing:
                with self.subTest(harness=name, text=text, number=number):
                    self.assertFalse(mod.has_numbered_text(text, prefix, number))

    def test_helper_parity_and_rejected_inputs(self) -> None:
        fragments = {name: mod.digit_agnostic_number(1204) for name, mod in self.modules.items()}
        self.assertEqual(len(set(fragments.values())), 1, fragments)
        for name, mod in self.modules.items():
            with self.subTest(harness=name):
                self.assertEqual(mod.FA_DIGITS, "۰۱۲۳۴۵۶۷۸۹")
                self.assertEqual(mod.AR_DIGITS, "٠١٢٣٤٥٦٧٨٩")
                with self.assertRaises(ValueError):
                    mod.digit_agnostic_number(-1)

    def test_edited_call_sites_stay_wired(self) -> None:
        facility = HARNESSES["facility-matrix"].read_text(encoding="utf-8")
        self.assertIn('f"{tag}.auto_site_seeded"', facility)
        self.assertIn('f"{tag}.sites_count"', facility)
        self.assertIn('has_numbered_text(org.content(), "— ساختمان‌ها: ", 1)', facility)
        self.assertIn('has_numbered_text(org.content(), "— ساختمان‌ها: ", expected)', facility)
        self.assertNotIn('"— ساختمان‌ها: 1" in org.content()', facility)
        self.assertNotIn('f"— ساختمان‌ها: {expected}" in org.content()', facility)

        hospital = HARNESSES["portal-hospital"].read_text(encoding="utf-8")
        self.assertIn('"org.two_clinics_listed"', hospital)
        self.assertIn('has_numbered_text(body, "درمانگاه‌ها: ", 2)', hospital)
        self.assertNotIn('"درمانگاه‌ها: 2" in body', hospital)

        delegated = HARNESSES["portal-delegated"].read_text(encoding="utf-8")
        self.assertIn('"orgA.two_clinics"', delegated)
        self.assertIn('has_numbered_text(body, "درمانگاه‌ها: ", 2)', delegated)
        self.assertNotIn('"درمانگاه‌ها: 2" in body', delegated)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Offline proof that every companion harness cancels BOTH dialog markups.

The shared in-page dialog (assets/js/cpms-dialog.js) renders its cancel button
with only `cpms-dialog__btn`; the patient-portal dialog uses `.cpms-modal-cancel`.
Each harness's DIALOG_CANCEL must name both forms, and answer_shared_dialogs()
must click whichever one the open dialog root actually renders. Pure Python:
no browser, network, WordPress or PHP is needed.
"""

from __future__ import annotations

import ast
import importlib.util
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

HARNESSES = {
    "disposable-site": ROOT / "tests/disposable-site/browser-acceptance.py",
    "scoped-demo": ROOT / "tests/scoped-demo/browser_scoped.py",
    "installer": ROOT / "tools/disposable-demo-installer/tests/browser-acceptance.py",
}

LEGACY_CANCEL = ".cpms-modal-cancel"
SHARED_CANCEL = ".cpms-dialog__btn:not(.cpms-dialog__btn--primary):not(.cpms-modal-confirm)"


def assigned_string(source: str, name: str) -> str:
    """Read one module-level string constant without importing the harness."""
    for node in ast.parse(source).body:
        if isinstance(node, ast.Assign) and any(
            isinstance(target, ast.Name) and target.id == name for target in node.targets
        ):
            value = ast.literal_eval(node.value)
            if not isinstance(value, str):  # pragma: no cover - defensive
                raise AssertionError(f"{name} is not a string literal")
            return value
    raise AssertionError(f"{name} is not assigned at module level")


def install_playwright_stub() -> None:
    """The harnesses import Playwright at import time; none of it is used here."""
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
    """Import one harness by path with throwaway, non-secret environment values."""
    install_playwright_stub()
    overrides = {
        "ADMIN_PASS": "offline-cancel-selector-test-not-a-secret",
        "CPSD_EVIDENCE_DIR": str(evidence_dir),
    }
    previous = {key: os.environ.get(key) for key in overrides}
    os.environ.update(overrides)
    try:
        spec = importlib.util.spec_from_file_location(f"cpms_cancel_{name}", path)
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


class CancelDialogPage:
    """Fake page with one OPEN dialog exposing exactly one cancel button form."""

    def __init__(self, cancel_selector):
        self.cancel_selector = cancel_selector
        self.open_dialog = True
        self.clicked = []
        self.waits = []

    def evaluate(self, script):
        return True

    def wait_for_timeout(self, ms):
        self.waits.append(int(ms))

    def locator(self, selector):
        return CancelLocator(self, selector)


class CancelLocator:
    def __init__(self, page, selector):
        self.page = page
        self.selector = selector

    @property
    def first(self):
        return self

    def locator(self, selector):
        return CancelLocator(self.page, selector)

    def count(self):
        if not self.page.open_dialog:
            return 0
        if self.selector in (".cpms-modal-overlay", ".cpms-modal[role='dialog']"):
            return 1
        return 1 if self.selector == self.page.cancel_selector else 0

    def is_visible(self):
        return self.count() > 0

    def click(self):
        if not self.page.open_dialog:
            raise RuntimeError("no open dialog to cancel")
        self.page.clicked.append(self.selector)
        self.page.open_dialog = False

    def fill(self, value):
        raise RuntimeError("the cancel path must not fill a prompt input")


class DialogCancelSelectorContract(unittest.TestCase):
    """One DOM, three harnesses: both cancel markups must be reachable."""

    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        evidence_dir = Path(cls.temp.name) / "evidence"
        cls.modules = {
            name: load_harness(name, path, evidence_dir)
            for name, path in HARNESSES.items()
        }
        for name, path in HARNESSES.items():
            if not path.is_file():  # pragma: no cover - defensive
                raise AssertionError(f"missing harness {name}: {path}")

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def test_each_selector_string_contains_both_cancel_forms(self):
        for name, path in HARNESSES.items():
            with self.subTest(harness=name):
                selector = assigned_string(path.read_text(encoding="utf-8"), "DIALOG_CANCEL")
                self.assertIn(LEGACY_CANCEL, selector, name)
                self.assertIn(SHARED_CANCEL, selector, name)
                self.assertEqual(
                    [LEGACY_CANCEL, SHARED_CANCEL],
                    [part.strip() for part in selector.split(",")],
                    name,
                )
                self.assertIn(".cpms-dialog__btn", SHARED_CANCEL)
                self.assertIn(":not(.cpms-dialog__btn--primary)", SHARED_CANCEL)
                self.assertIn(":not(.cpms-modal-confirm)", SHARED_CANCEL)

    def test_the_cancel_lookup_stays_inside_the_open_dialog_root(self):
        for name, path in HARNESSES.items():
            with self.subTest(harness=name):
                source = path.read_text(encoding="utf-8")
                root = assigned_string(source, "DIALOG_ROOT")
                self.assertIn("cpms-modal", root, name)
                self.assertIn("role='dialog'", root, name)
                self.assertIn("root = overlay.locator(DIALOG_ROOT).first", source, name)
                self.assertIn("root.locator(cancel_selector.strip())", source, name)
                self.assertIn("DIALOG_CANCEL.split(\",\")", source, name)

    def test_legacy_cancel_form_is_clicked(self):
        for name, module in self.modules.items():
            with self.subTest(harness=name):
                page = CancelDialogPage(LEGACY_CANCEL)
                form = module.answer_shared_dialogs(
                    page,
                    [{"action": "cancel"}],
                    shared_expected=True,
                    timeout_ms=1000,
                )
                self.assertEqual("shared", form, name)
                self.assertEqual([LEGACY_CANCEL], page.clicked, name)
                self.assertFalse(page.open_dialog, name)

    def test_shared_dialog_cancel_form_is_clicked(self):
        for name, module in self.modules.items():
            with self.subTest(harness=name):
                page = CancelDialogPage(SHARED_CANCEL)
                form = module.answer_shared_dialogs(
                    page,
                    [{"action": "cancel"}],
                    shared_expected=True,
                    timeout_ms=1000,
                )
                self.assertEqual("shared", form, name)
                self.assertEqual([SHARED_CANCEL], page.clicked, name)
                self.assertFalse(page.open_dialog, name)

    def test_no_cancel_form_fails_closed(self):
        for name, module in self.modules.items():
            with self.subTest(harness=name):
                page = CancelDialogPage(None)
                with self.assertRaises(RuntimeError, msg=name) as caught:
                    module.answer_shared_dialogs(
                        page,
                        [{"action": "cancel"}],
                        shared_expected=True,
                        timeout_ms=1000,
                    )
                self.assertIn("neither cancel button form", str(caught.exception), name)
                self.assertEqual([], page.clicked, name)


if __name__ == "__main__":
    unittest.main(verbosity=2)

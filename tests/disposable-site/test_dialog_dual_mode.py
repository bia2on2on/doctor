#!/usr/bin/env python3
"""Offline proof for the DUAL-MODE dialog answering in the three companion harnesses.

The next product round converts the remaining native window.confirm()/prompt()
call sites to the shared in-page dialog (assets/js/cpms-dialog.js). Until then the
harnesses must accept BOTH forms so their lanes stay green before and after that
round. That in-page branch cannot run in CI yet, so it is proven here, offline,
against a fake page object — pure Python, no browser, no network.

Scope of this file (one test case per harness, all three parameterised):
  tests/disposable-site/browser-acceptance.py          -> doctor journey (visit complete)
  tests/scoped-demo/browser_scoped.py                  -> pricing editCharge (3 prompts)
  tools/disposable-demo-installer/tests/browser-acceptance.py -> pricing editCharge + visit complete

Placement follows tests/disposable-site/test_tooling.py: the disposable-site
workflow runs `python3 -m unittest discover -s tests/disposable-site -p 'test_*.py'`,
so a `test_*.py` file here is executed the same way the existing guard is.

What is asserted, per harness:
  1. a native answer already taken by the existing page.on("dialog") handler is
     never re-answered, and nothing in the page is clicked;
  2. when the page did not ship the shared dialog the helper stays out of the way
     (today's shipped behaviour is untouched);
  3. the shared confirm is clicked, the overlay is waited out, and the step
     reports "shared";
  4. the shared prompt fills each value in order and submits each one;
  5. "cancel" clicks the cancel button (used where a journey currently dismisses);
  6. NEITHER form appearing raises instead of passing silently, and the wait never
     exceeds the caller's own timeout (no timeout is widened).
"""

from __future__ import annotations

import importlib.util
import os
import sys
import tempfile
import types
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]

HARNESSES = {
    "disposable-site": ROOT / "tests/disposable-site/browser-acceptance.py",
    "scoped-demo": ROOT / "tests/scoped-demo/browser_scoped.py",
    "installer": ROOT / "tools/disposable-demo-installer/tests/browser-acceptance.py",
}


def _install_playwright_stub() -> None:
    """Import-time stub: the harnesses import Playwright but never use it here."""
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
    """Load one harness module by path (the files are not importable by name).

    `browser_scoped.py` requires ADMIN_PASS at import time and creates its evidence
    directory, so both are satisfied locally with throwaway values and restored
    afterwards: nothing secret is read and nothing outside the temp dir is written.
    """
    _install_playwright_stub()
    overrides = {
        "ADMIN_PASS": "offline-dual-mode-test-not-a-secret",
        "CPSD_EVIDENCE_DIR": str(evidence_dir),
    }
    previous = {key: os.environ.get(key) for key in overrides}
    os.environ.update(overrides)
    try:
        spec = importlib.util.spec_from_file_location(f"cpms_dual_mode_{name}", path)
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


class FakePage:
    """Minimal stand-in for a Playwright Page — only what the helper touches.

    `dialogs` is the queue of dialogs the page will open, one per answered step;
    each entry is {"kind": "confirm"|"prompt"}. An empty queue models a page that
    opens NOTHING, which is the fail-if-neither case.
    """

    def __init__(self, dialogs=None, dialog_available=True):
        self.dialogs = list(dialogs or [])
        self.dialog_available = dialog_available
        self.open_dialog = None
        self.opened = 0
        self.clicks = []
        self.fills = []
        self.waits = []
        self._open_next()

    # -- page API ---------------------------------------------------------
    def evaluate(self, script):
        return self.dialog_available

    def wait_for_timeout(self, ms):
        self.waits.append(int(ms))

    def locator(self, selector):
        return FakeLocator(self, selector)

    def _open_next(self):
        self.open_dialog = self.dialogs.pop(0) if self.dialogs else None
        if self.open_dialog is not None:
            self.opened += 1

    def submit(self):
        if self.open_dialog is None:
            raise RuntimeError("no open dialog to confirm")
        self.clicks.append("confirm")
        self._open_next()

    def cancel(self):
        if self.open_dialog is None:
            raise RuntimeError("no open dialog to cancel")
        self.clicks.append("cancel")
        self.open_dialog = None


class FakeLocator:
    def __init__(self, page, selector):
        self.page = page
        self.selector = selector

    @property
    def first(self):
        return self

    def locator(self, selector):
        return FakeLocator(self.page, selector)

    def _matches(self):
        dialog = self.page.open_dialog
        if self.selector == ".cpms-modal-overlay":
            return 1 if dialog is not None else 0
        if self.selector == ".cpms-modal[role='dialog']":
            return 1 if dialog is not None else 0
        if self.selector == ".cpms-dialog__input":
            return 1 if dialog is not None and dialog.get("kind") == "prompt" else 0
        if self.selector in (".cpms-modal-confirm", ".cpms-modal-cancel"):
            return 1 if dialog is not None else 0
        return 0

    def count(self):
        return self._matches()

    def is_visible(self):
        return self._matches() > 0

    def click(self):
        if self.selector == ".cpms-modal-confirm":
            self.page.submit()
            return
        if self.selector == ".cpms-modal-cancel":
            self.page.cancel()
            return
        raise RuntimeError(f"unexpected click on {self.selector}")

    def fill(self, value):
        if self.selector != ".cpms-dialog__input":
            raise RuntimeError(f"unexpected fill on {self.selector}")
        self.page.fills.append(str(value))


class DualModeHarnessContract(unittest.TestCase):
    """The same contract must hold in all three companion harnesses."""

    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        evidence_dir = Path(cls.temp.name) / "evidence"
        cls.modules = {
            name: load_harness(name, path, evidence_dir) for name, path in HARNESSES.items()
        }
        for name, path in HARNESSES.items():
            if not path.is_file():  # pragma: no cover - defensive
                raise AssertionError(f"missing harness {name}: {path}")

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def _helpers(self):
        """[(name, module)] after asserting every harness exports the same surface."""
        pairs = []
        for name, module in self.modules.items():
            for attribute in (
                "shared_dialog_available",
                "answer_shared_dialogs",
                "DIALOG_OVERLAY",
                "DIALOG_ROOT",
                "DIALOG_CONFIRM",
                "DIALOG_CANCEL",
                "DIALOG_INPUT",
            ):
                self.assertTrue(hasattr(module, attribute), f"{name} is missing {attribute}")
            pairs.append((name, module))
        return pairs

    def test_the_three_harnesses_agree_on_the_shared_dialog_selectors(self):
        """One DOM, three harnesses: drift here silently stops matching the dialog."""
        selectors = set()
        for name, module in self.modules.items():
            with self.subTest(harness=name):
                selectors.add(
                    (
                        module.DIALOG_OVERLAY,
                        module.DIALOG_ROOT,
                        module.DIALOG_CONFIRM,
                        module.DIALOG_CANCEL,
                        module.DIALOG_INPUT,
                    )
                )
        self.assertEqual(1, len(selectors), f"selectors drifted between harnesses: {selectors}")
        overlay, root, confirm, cancel, field = next(iter(selectors))
        self.assertIn("cpms-modal-overlay", overlay)
        self.assertIn("cpms-modal", root)
        self.assertIn('role=\'dialog\'', root)
        self.assertIn("cpms-modal-confirm", confirm)
        self.assertIn("cpms-modal-cancel", cancel)
        self.assertIn("cpms-dialog__input", field)

    def test_a_native_answer_is_never_re_answered(self):
        """The existing page.on("dialog") handler keeps ownership of the native form."""
        for name, module in self._helpers():
            page = FakePage(dialogs=[{"kind": "confirm"}])
            form = module.answer_shared_dialogs(
                page,
                [{"action": "accept"}],
                shared_expected=True,
                timeout_ms=35000,
                native_seen=["confirm"],
            )
            self.assertEqual("native", form, name)
            self.assertEqual([], page.clicks, f"{name} must not click a dialog the native handler owned")
            self.assertEqual([], page.waits, f"{name} must not wait when the native handler answered")

    def test_without_the_shared_dialog_the_helper_stays_out_of_the_way(self):
        """Today's shipped behaviour: no CpmsDialog on the page, nothing new happens."""
        for name, module in self._helpers():
            page = FakePage(dialogs=[{"kind": "confirm"}], dialog_available=False)
            form = module.answer_shared_dialogs(
                page,
                [{"action": "accept"}],
                shared_expected=module.shared_dialog_available(page),
                timeout_ms=35000,
            )
            self.assertEqual("native", form, name)
            self.assertEqual([], page.clicks, f"{name} must keep today's behaviour")
            self.assertEqual([], page.waits, name)

    def test_the_shared_confirm_is_clicked_and_waited_out(self):
        for name, module in self._helpers():
            page = FakePage(dialogs=[{"kind": "confirm"}])
            form = module.answer_shared_dialogs(
                page,
                [{"action": "accept"}],
                shared_expected=True,
                timeout_ms=35000,
            )
            self.assertEqual("shared", form, name)
            self.assertEqual(["confirm"], page.clicks, name)
            self.assertIsNone(page.open_dialog, f"{name} must wait until the overlay is gone")
            self.assertLessEqual(sum(page.waits), 35000, f"{name} widened its own timeout")

    def test_each_shared_prompt_fills_its_own_value_in_order(self):
        values = ["خدمت اصلاح‌شده", "200000", "2"]
        for name, module in self._helpers():
            page = FakePage(dialogs=[{"kind": "prompt"} for _ in values])
            form = module.answer_shared_dialogs(
                page,
                [{"action": "prompt", "value": value} for value in values],
                shared_expected=True,
                timeout_ms=45000,
            )
            self.assertEqual("shared", form, name)
            self.assertEqual(values, page.fills, f"{name} must fill one value per prompt")
            self.assertEqual(["confirm", "confirm", "confirm"], page.clicks, name)
            self.assertEqual(3, page.opened, f"{name} must answer exactly three dialogs")

    def test_cancel_action_clicks_the_cancel_button(self):
        """Where a journey currently dismisses, the in-page form must cancel."""
        for name, module in self._helpers():
            page = FakePage(dialogs=[{"kind": "confirm"}])
            form = module.answer_shared_dialogs(
                page,
                [{"action": "cancel"}],
                shared_expected=True,
                timeout_ms=35000,
            )
            self.assertEqual("shared", form, name)
            self.assertEqual(["cancel"], page.clicks, name)

    def test_neither_form_appearing_fails_instead_of_passing_silently(self):
        for name, module in self._helpers():
            page = FakePage(dialogs=[], dialog_available=True)
            with self.assertRaises(RuntimeError, msg=name) as caught:
                module.answer_shared_dialogs(
                    page,
                    [{"action": "accept"}],
                    shared_expected=True,
                    timeout_ms=45000,
                )
            message = str(caught.exception)
            self.assertIn("neither the native nor the shared in-page dialog", message, name)
            self.assertIn("45000ms", message, name)
            self.assertEqual([], page.clicks, name)

    def test_the_callers_timeout_is_never_widened(self):
        """The helper's total wait stays inside the timeout the caller already used."""
        for name, module in self._helpers():
            page = FakePage(dialogs=[], dialog_available=True)
            with self.assertRaises(RuntimeError, msg=name):
                module.answer_shared_dialogs(
                    page,
                    [{"action": "accept"}],
                    shared_expected=True,
                    timeout_ms=35000,
                )
            self.assertLessEqual(sum(page.waits), 35000, f"{name} widened its own timeout")
            self.assertTrue(page.waits, f"{name} must poll until its own timeout expires")

    def test_the_helper_is_wired_into_each_harness_call_site(self):
        """A helper nobody calls cannot keep the lane green in either world."""
        for name, path in HARNESSES.items():
            with self.subTest(harness=name):
                source = path.read_text(encoding="utf-8")
                self.assertIn("def answer_shared_dialogs(", source)
                self.assertIn("shared_dialog_available(", source)
                self.assertGreater(
                    source.count("answer_shared_dialogs("),
                    1,
                    f"{name} defines the helper but never calls it",
                )
                # The native handler must still be installed: the dual-mode branch
                # answers in addition to it, never instead of it.
                self.assertIn('page.on("dialog"', source.replace("doctor_page", "page").replace("doc_page", "page"))


if __name__ == "__main__":
    unittest.main(verbosity=2)

"""Offline unit tests for bin/cpms_page_error_watch.py (owner defect 12).

No browser, no network, no WordPress: a fake page/message doubles for
Playwright's objects. The suite locks the two properties the production gate
depends on:

  * the listener is UNFILTERED — no message is dropped, and nothing is decided
    by the word "serialize";
  * only an error whose source frame is served by the plugin fails the step;
    WordPress core and third-party sources stay INFO lines.
"""

import sys
import unittest
from pathlib import Path

BIN = Path(__file__).resolve().parents[2] / "bin"
sys.path.insert(0, str(BIN))

import cpms_page_error_watch as watch  # noqa: E402  (path must be set first)

PLUGIN_JS = (
    "http://localhost:8080/wp-content/plugins/clinic-practice-management/"
    "assets/js/cpms-admin.js?ver=1.2.3"
)
CORE_JS = "http://localhost:8080/wp-admin/js/user-profile.min.js?ver=6.6.2"


class FakePage:
    """Minimal stand-in for playwright.sync_api.Page."""

    def __init__(self, url):
        self.url = url
        self.handlers = {}

    def on(self, name, callback):
        self.handlers.setdefault(name, []).append(callback)

    def error(self, message, stack=""):
        for handler in self.handlers.get("pageerror", []):
            handler(FakeError(message, stack))

    def console(self, text, location_url="", kind="error"):
        for handler in self.handlers.get("console", []):
            handler(FakeConsole(text, kind, {"url": location_url}))


class FakeError:
    def __init__(self, message, stack=""):
        self.name = "TypeError"
        self.message = message
        self.stack = stack


class FakeConsole:
    def __init__(self, text, kind, location):
        self.text = text
        self.type = kind
        self.location = location


def failing_lines(lines):
    return [line for line in lines if line.startswith("FAIL ")]


class SanitizationTest(unittest.TestCase):
    def test_query_string_and_fragment_are_stripped(self):
        self.assertEqual(
            "http://localhost:8080/wp-admin/js/user-profile.min.js",
            watch.sanitize_url(CORE_JS + "#top"),
        )

    def test_token_like_path_segment_is_redacted(self):
        cleaned = watch.sanitize_url(
            "http://localhost:8080/wp-admin/profile.php/0123456789abcdef0123456789abcdef/"
        )
        self.assertNotIn("0123456789abcdef0123456789abcdef", cleaned)

    def test_page_path_keeps_only_the_path(self):
        self.assertEqual("/wp-admin/profile.php", watch.page_path("http://host/wp-admin/profile.php?a=1"))
        self.assertEqual("", watch.page_path(""))

    def test_message_secrets_are_redacted(self):
        cleaned = watch.sanitize_message("fetch failed nonce=abc123 token: xyz789")
        self.assertNotIn("abc123", cleaned)
        self.assertNotIn("xyz789", cleaned)
        self.assertIn("[redacted]", cleaned)


class ClassificationTest(unittest.TestCase):
    def test_plugin_asset_source_is_plugin_owned(self):
        item = watch.record("pageerror", "boom", [PLUGIN_JS], "http://host/wp-admin/admin.php?page=cpms-finance")
        self.assertTrue(item["plugin"])
        self.assertEqual(
            "http://localhost:8080/wp-content/plugins/clinic-practice-management/assets/js/cpms-admin.js",
            item["source"],
        )
        self.assertEqual("/wp-admin/admin.php", item["page"])

    def test_core_source_is_not_plugin_owned(self):
        item = watch.record("pageerror", "Cannot read properties of undefined", [CORE_JS], "http://host/wp-admin/profile.php")
        self.assertFalse(item["plugin"])

    def test_unknown_source_is_not_plugin_owned(self):
        self.assertFalse(watch.record("console", "x", [], "http://host/wp-admin/profile.php")["plugin"])

    def test_one_plugin_frame_anywhere_makes_it_plugin_owned(self):
        item = watch.record("pageerror", "boom", [CORE_JS, PLUGIN_JS], "http://host/x")
        self.assertTrue(item["plugin"])


class ReportTest(unittest.TestCase):
    def test_plugin_error_fails_and_core_error_only_informs(self):
        records = [
            watch.record("pageerror", "plugin boom", [PLUGIN_JS], "http://host/wp-admin/admin.php?page=cpms-finance"),
            watch.record("pageerror", "core boom", [CORE_JS], "http://host/wp-admin/profile.php"),
        ]
        printed = []
        lines = watch.report(records, "journey", emit=printed.append)

        self.assertEqual(1, len(failing_lines(lines)))
        self.assertIn("plugin boom", failing_lines(lines)[0])
        self.assertIn("source=", failing_lines(lines)[0])
        self.assertIn("page=/wp-admin/admin.php", failing_lines(lines)[0])
        self.assertEqual(1, len(printed))
        self.assertTrue(printed[0].startswith("INFO journey"))
        self.assertIn("core boom", printed[0])

    def test_no_records_means_no_failures(self):
        self.assertEqual([], watch.report([], "journey", emit=lambda line: None))


class AttachTest(unittest.TestCase):
    def test_pageerror_source_comes_from_the_stack(self):
        page = FakePage("http://host/wp-admin/profile.php")
        records = watch.attach(page, [])
        page.error("Cannot read properties of null (reading 'serialize')", f"TypeError\n    at {CORE_JS}:2:10")

        self.assertEqual(1, len(records))
        self.assertEqual("pageerror", records[0]["kind"])
        self.assertEqual("/wp-admin/profile.php", records[0]["page"])
        self.assertIn("serialize", records[0]["message"])
        self.assertFalse(records[0]["plugin"])

    def test_console_error_carries_its_location(self):
        page = FakePage("http://host/wp-admin/admin.php?page=cpms-finance")
        records = watch.attach(page, [])
        page.console("Uncaught TypeError", PLUGIN_JS)

        self.assertEqual(1, len(records))
        self.assertEqual("console", records[0]["kind"])
        self.assertTrue(records[0]["plugin"])

    def test_non_error_console_entries_are_ignored(self):
        page = FakePage("http://host/wp-admin/profile.php")
        records = watch.attach(page, [])
        page.console("a warning", CORE_JS, kind="warning")
        self.assertEqual([], records)

    def test_every_error_is_recorded_regardless_of_wording(self):
        page = FakePage("http://host/wp-admin/profile.php")
        records = watch.attach(page, [])
        page.error("serialize failed", "")
        page.error("completely unrelated text", "")
        page.console("another message", "")

        self.assertEqual(3, len(records))
        self.assertEqual(
            {
                "TypeError serialize failed",
                "another message",
                "TypeError completely unrelated text",
            },
            {item["message"] for item in records},
        )
        self.assertEqual([], failing_lines(watch.report(records, "j", emit=lambda line: None)))


if __name__ == "__main__":
    unittest.main(verbosity=2)

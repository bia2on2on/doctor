#!/usr/bin/env python3
"""Prove that the requested Playwright browsers actually launch on this runner.

Why this exists: a warm browser-binary cache skips the download, but it says
nothing about the *system* libraries those binaries need. The composite action
therefore (a) asks `playwright install-deps --dry-run` for the whole requested
browser set and provisions exactly that list with bounded apt options, and
(b) launches every requested browser headless against a blank page here. This
launch is the independent second signal: it proves the binaries really run on
this runner instead of trusting the package list. Every requested browser is
launched; the first failure is reported verbatim and the run fails.

Exit code 0 = every requested browser launched and closed cleanly.
Exit code 1 = at least one browser failed (reason printed, never swallowed).
"""

import sys

from playwright.sync_api import sync_playwright

REQUESTED = sys.argv[1:]


def main() -> int:
    if not REQUESTED:
        print("verify_launch: no browsers requested", file=sys.stderr)
        return 1
    unknown = [name for name in REQUESTED if name not in {"chromium", "firefox", "webkit"}]
    if unknown:
        print(f"verify_launch: unsupported browser name(s): {unknown}", file=sys.stderr)
        return 1

    failures = []
    with sync_playwright() as playwright:
        for name in REQUESTED:
            launcher = getattr(playwright, name)
            try:
                browser = launcher.launch(headless=True)
                try:
                    page = browser.new_page()
                    page.goto("about:blank")
                    page.close()
                finally:
                    browser.close()
                print(f"launch_ok={name}")
            except Exception as exc:  # noqa: BLE001 - the reason must be reported verbatim
                print(f"launch_fail={name}: {type(exc).__name__}: {exc}", file=sys.stderr)
                failures.append(name)

    if failures:
        print(f"verify_launch: failed browsers: {failures}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())

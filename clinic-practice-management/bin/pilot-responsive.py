#!/usr/bin/env python3
"""pilot-responsive.py — Responsive smoke (NFR-UI-3/5) روی محیط واقعی با Chromium.

اجرا (Workflow):  BASE=http://localhost:8080 ADMIN_PASS=... python3 bin/pilot-responsive.py
خروجی: اسکرین‌شات‌ها در pilot-screenshots/ + خطوط PASS/FAIL + JSON summary؛ exit≠0 در شکست.

Viewportها: 390×844 (موبایل) / 360×800 (موبایل کوچک) / 768×1024 (تبلت عمودی) /
1024×768 (تبلت افقی) / 1366×768 (لپ‌تاپ) / 1440×900 (دسکتاپ)
معیار: بدون Overflow افقی (scrollWidth ≤ innerWidth+1) + رندر موفق (بدون HTTP 5xx) +
بدون خطای Console (type=error) + بدون Uncaught Exception + بدون Request شکست‌خورده.
Payload فرانت (JS/CSS bytes + تعداد منابع) به‌صورت INFO گزارش می‌شود (NFR-PERF-2/3).
"""

import json
import os
import sys
import time

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("RESP_USER", "pilot_secretary")
ADMIN_PASS = os.environ["RESP_PASS"]
DOCTOR_USER = os.environ.get("RESP_DOCTOR", "pilot_doctor")
DOCTOR_PASS = os.environ["RESP_DOCTOR_PASS"]
OUT = "pilot-screenshots"

VIEWPORTS = [
    ("mobile-small-360", 360, 800),
    ("mobile-390", 390, 844),
    ("tablet-portrait-768", 768, 1024),
    ("tablet-landscape-1024", 1024, 768),
    ("laptop-1366", 1366, 768),
    ("desktop-1440", 1440, 900),
]

# (کاربر, slug/URL, عنوان)
PAGES = [
    ("secretary", "/wp-admin/admin.php?page=cpms-queue", "صف امروز (منشی)"),
    ("secretary", "/wp-admin/admin.php?page=cpms-finance", "مالی و تسویه (منشی)"),
    ("doctor", "/wp-admin/admin.php?page=cpms-doctor", "امروز پزشک"),
    ("doctor", "/wp-admin/admin.php?page=cpms-handwriting", "دست‌خط (Canvas)"),
]

results = []
failures = []


def login(page, user, password):
    page.goto(f"{BASE}/wp-login.php", wait_until="networkidle")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("networkidle")
    if "wp-login.php" in page.url and "loggedout" not in page.url:
        raise RuntimeError(f"login failed for {user}: {page.url}")


with sync_playwright() as p:
    browser = p.chromium.launch()
    for user, url, title in PAGES:
        username = ADMIN_USER if user == "secretary" else DOCTOR_USER
        password = ADMIN_PASS if user == "secretary" else DOCTOR_PASS
        for vp_name, width, height in VIEWPORTS:
            ctx = browser.new_context(viewport={"width": width, "height": height})
            page = ctx.new_page()
            key = f"{user}-{vp_name}"
            issues = {"pageerrors": [], "console_errors": [], "request_failed": [], "http5xx": []}

            def _skip_benign(url: str) -> bool:
                return any(s in url for s in ("favicon", ".map")) or url.endswith(".map")

            def _console_signal(m) -> None:
                if m.type != "error":
                    return
                text = m.text or ""
                # شکست‌های سطح شبکه جداگانه (requestfailed/response) رصد می‌شوند؛
                # این متنِ عمومیِ مرورگر فقط نویز است (مثل favicon 404).
                if text.startswith("Failed to load resource") or "favicon" in text:
                    return
                issues["console_errors"].append(text)

            page.on("pageerror", lambda e: issues["pageerrors"].append(str(e)))
            page.on("console", _console_signal)
            page.on("requestfailed", lambda r: None if _skip_benign(r.url) else issues["request_failed"].append(r.url))
            page.on("response", lambda r: None if r.status < 500 or _skip_benign(r.url) else issues["http5xx"].append(f"{r.status} {r.url}"))
            try:
                login(page, username, password)
                page.goto(f"{BASE}{url}", wait_until="networkidle")
                time.sleep(1.2)  # رندر صف/داشبورد (poll اولیه)
                sw = page.evaluate("document.documentElement.scrollWidth")
                iw = page.evaluate("window.innerWidth")
                overflow = sw > iw + 1
                shot = f"{OUT}/{key}.png"
                os.makedirs(OUT, exist_ok=True)
                page.screenshot(path=shot, full_page=False)
                perf = page.evaluate(
                    """() => {
                      const res = performance.getEntriesByType('resource');
                      const same = res.filter(r => r.name.indexOf(location.origin) === 0);
                      const bytes = a => a.reduce((s, r) => s + (r.transferSize || 0), 0);
                      const js = same.filter(r => /\\.js(\\?|$)/.test(r.name));
                      const css = same.filter(r => /\\.css(\\?|$)/.test(r.name));
                      return {count: same.length, jsCount: js.length, cssCount: css.length,
                              jsBytes: bytes(js), cssBytes: bytes(css), totalBytes: bytes(same)};
                    }"""
                )
                err = []
                if issues["pageerrors"]:
                    err.append(f"pageerror: {issues['pageerrors'][0]}")
                if issues["console_errors"]:
                    err.append(f"console.error: {issues['console_errors'][0]}")
                if issues["request_failed"]:
                    err.append(f"requestfailed: {issues['request_failed'][0]}")
                if issues["http5xx"]:
                    err.append(f"http>=500: {issues['http5xx'][0]}")
                if overflow:
                    err.append(f"horizontal overflow: scrollWidth={sw} > innerWidth={iw}")
                if err:
                    raise RuntimeError("; ".join(err))
                results.append({"key": key, "title": title, "status": "PASS",
                                "scrollWidth": sw, "innerWidth": iw, "screenshot": shot,
                                "payload": perf})
                print(f"PASS {key} — {title} (sw={sw}, iw={iw} | res={perf['count']} js={perf['jsCount']}({perf['jsBytes']}B) css={perf['cssCount']}({perf['cssBytes']}B) total={perf['totalBytes']}B)")
            except Exception as e:  # noqa: BLE001
                failures.append(key)
                os.makedirs(OUT, exist_ok=True)
                try:
                    page.screenshot(path=f"{OUT}/{key}-FAIL.png", full_page=False)
                except Exception:
                    pass
                results.append({"key": key, "title": title, "status": "FAIL", "detail": str(e)})
                print(f"FAIL {key} — {title} — {e}")
            finally:
                ctx.close()
    browser.close()

with open("pilot-responsive-results.json", "w", encoding="utf-8") as f:
    json.dump({"ok": not failures, "results": results}, f, ensure_ascii=False, indent=1)
print(json.dumps({"ok": not failures, "count": len(results), "failed": failures}, ensure_ascii=False))
sys.exit(1 if failures else 0)

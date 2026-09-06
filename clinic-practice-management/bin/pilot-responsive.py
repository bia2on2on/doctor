#!/usr/bin/env python3
"""pilot-responsive.py — Responsive smoke (NFR-UI-3/5) روی محیط واقعی با Chromium.

اجرا (Workflow):  BASE=http://localhost:8080 ADMIN_PASS=... python3 bin/pilot-responsive.py
خروجی: اسکرین‌شات‌ها در pilot-screenshots/ + خطوط PASS/FAIL + JSON summary؛ exit≠0 در شکست.

Viewportها: 390×844 (موبایل) / 768×1024 (تبلت عمودی) / 1024×768 (تبلت افقی) / 1440×900 (دسکتاپ)
معیار: صفحه بدون Overflow افقی (scrollWidth ≤ innerWidth+1) + رندر موفق (بدون HTTP 5xx).
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
    ("mobile-390", 390, 844),
    ("tablet-portrait-768", 768, 1024),
    ("tablet-landscape-1024", 1024, 768),
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
                if overflow:
                    raise RuntimeError(f"horizontal overflow: scrollWidth={sw} > innerWidth={iw}")
                results.append({"key": key, "title": title, "status": "PASS",
                                "scrollWidth": sw, "innerWidth": iw, "screenshot": shot})
                print(f"PASS {key} — {title} (sw={sw}, iw={iw})")
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

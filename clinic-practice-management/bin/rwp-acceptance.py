#!/usr/bin/env python3
"""rwp-acceptance.py — Real WordPress Acceptance (مرورگر واقعی Chromium) برای CPMS.

هدف: اثبات نصب/اجرای واقعی افزونه از ZIP رسمی روی WordPress تمیز — بدون میان‌بر.

  اجرا (Workflow):
    BASE=http://localhost:8080 ADMIN_USER=... ADMIN_PASS=... \
    DOCTOR_USER=... DOCTOR_PASS=... SECRETARY_USER=... SECRETARY_PASS=... \
    ACTUAL_COUNT_FILE=/tmp/acc/actual_count.txt OUT=/tmp/acc \
    python3 bin/rwp-acceptance.py

خروجی: اسکرین‌شات + console/pageerror logs + results.json در OUT؛ exit≠0 در هر شکست.
هیچ تستی برای سبز شدن تضعیف نمی‌شود: Critical Error / هر Count نامساوی / هر منوی
غلط / هر خطای Console مرورگر = FAIL.
"""

import json
import os
import re
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ["ADMIN_USER"]
ADMIN_PASS = os.environ["ADMIN_PASS"]
DOCTOR_USER = os.environ["DOCTOR_USER"]
DOCTOR_PASS = os.environ["DOCTOR_PASS"]
SECRETARY_USER = os.environ["SECRETARY_USER"]
SECRETARY_PASS = os.environ["SECRETARY_PASS"]
OUT = os.environ.get("OUT", "rwp-acceptance-out")
ACTUAL_COUNT_FILE = os.environ.get("ACTUAL_COUNT_FILE", "")

os.makedirs(f"{OUT}/screenshots", exist_ok=True)
os.makedirs(f"{OUT}/logs", exist_ok=True)

results = []   # (name, ok, detail)
console_errors = []  # (page_tag, text)
page_errors = []     # (page_tag, text)

CRITICAL_RE = re.compile(r"critical error|خطای بحرانی|wp-die-message", re.IGNORECASE)


def check(name, ok, detail=""):
    results.append((name, bool(ok), str(detail)[:300]))
    print(("PASS " if ok else "FAIL ") + name + (" — " + str(detail)[:300] if detail else ""), flush=True)


def attach_watchers(page, tag):
    page.on("console", lambda m: console_errors.append((tag, m.text)) if m.type == "error" else None)
    page.on("pageerror", lambda e: page_errors.append((tag, str(e))))


def login(page, user, password, tag):
    attach_watchers(page, tag)
    resp = page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    ok = "wp-login.php" not in page.url or "loggedout" in page.url
    check(f"{tag}.login", ok, page.url)
    return ok


def excerpt(body, limit=400):
    # متن قابل‌خواندن از HTML برای Evidence (بدون تگ)
    text = re.sub(r"<script.*?</script>", " ", body or "", flags=re.S)
    text = re.sub(r"<style.*?</style>", " ", text, flags=re.S)
    text = re.sub(r"<[^>]+>", " ", text)
    return re.sub(r"\s+", " ", text).strip()[:limit]


def goto_admin(page, tag, path, shot_name):
    resp = page.goto(f"{BASE}/wp-admin/{path}", wait_until="domcontentloaded")
    page.wait_for_timeout(1200)  # settle کوتاه برای رندر/JS (بدون مکث طولانی)
    status = resp.status if resp else 0
    body = page.content()
    page.screenshot(path=f"{OUT}/screenshots/{shot_name}.png", full_page=True)
    with open(f"{OUT}/logs/{tag}-{shot_name}.html", "w") as f:
        f.write(body or "")
    check(f"{tag}.{shot_name}.http200", status == 200, f"HTTP {status}")
    crit = CRITICAL_RE.search(body or "")
    check(f"{tag}.{shot_name}.no_critical_error", not crit, excerpt(body) if crit else path)
    return status, body


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 1366, "height": 768}, locale="fa-IR")

    # ---------- Admin (Administrator فنی — P-3) ----------
    page = ctx.new_page()
    if login(page, ADMIN_USER, ADMIN_PASS, "admin"):
        # CPMS (سیستم) — مجوز/بکاپ/Health
        status, body = goto_admin(page, "admin", "tools.php?page=cpms-system", "cpms-system")
        check("admin.cpms-system.health_rendered", "وضعیت Health" in (body or ""), "بخش Health باید رندر شود")

        # CPMS (فنی و لاگ) — شمارش جداول
        status, body = goto_admin(page, "admin", "tools.php?page=cpms-settings", "cpms-settings")
        ui_count = None
        for tr in page.query_selector_all("table.form-table tr"):
            th = tr.query_selector("th")
            td = tr.query_selector("td")
            if th and td and "تعداد جداول" in (th.inner_text() or ""):
                try:
                    ui_count = int((td.inner_text() or "").strip())
                except ValueError:
                    ui_count = None
                break
        with open(f"{OUT}/ui_count.txt", "w") as f:
            f.write("" if ui_count is None else str(ui_count))
        check("admin.cpms-settings.table_count_present", ui_count is not None, f"UI count={ui_count}")
        if ui_count is not None and ACTUAL_COUNT_FILE and os.path.isfile(ACTUAL_COUNT_FILE):
            actual = int(open(ACTUAL_COUNT_FILE).read().strip() or "0")
            check(
                "admin.cpms-settings.table_count_equals_db",
                ui_count == actual,
                f"UI={ui_count} DB={actual} (باید برابر باشند)",
            )

        # منوی Administrator فنی (سایدبار همان صفحهٔ سیستم — بدون رفتن به /wp-admin/ خالی)
        menu = page.content()
        check("admin.menu.has_cpms_system", "page=cpms-system" in menu, "Tools → CPMS (سیستم)")
        check("admin.menu.has_cpms_settings", "page=cpms-settings" in menu, "Tools → CPMS (فنی و لاگ)")
        check("admin.menu.no_doctor_topmenu", "admin.php?page=cpms-doctor" not in menu, "P-3: منوی پزشک برای Administrator پنهان است")
        check("admin.menu.no_queue_topmenu", "admin.php?page=cpms-queue" not in menu, "P-3: منوی صف برای Administrator پنهان است")

        # دسترسی مستقیم مدیر به صفحهٔ عملیاتی = Deny (نه Render)
        resp = page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-queue", wait_until="domcontentloaded")
        body_q = page.content()
        denied = (resp is not None and resp.status in (403,)) or "not allowed to access this page" in (body_q or "").lower() or "دسترسی ندارید" in (body_q or "")
        check("admin.business_page_denied", denied, f"HTTP {resp.status if resp else 0}")
        page.screenshot(path=f"{OUT}/screenshots/admin-denied-business.png", full_page=True)
    page.close()

    # ---------- Doctor (نقش cpms_doctor) ----------
    page = ctx.new_page()
    if login(page, DOCTOR_USER, DOCTOR_PASS, "doctor"):
        status, body = goto_admin(page, "doctor", "admin.php?page=cpms-doctor", "cpms-doctor")
        check("doctor.menu.has_cpms_doctor", "admin.php?page=cpms-doctor" in (body or ""), "منوی «امروز پزشک» باید دیده شود")
    page.close()

    # ---------- Secretary (نقش cpms_secretary) ----------
    page = ctx.new_page()
    if login(page, SECRETARY_USER, SECRETARY_PASS, "secretary"):
        status, body = goto_admin(page, "secretary", "admin.php?page=cpms-queue", "cpms-queue")
        check("secretary.menu.has_cpms_queue", "admin.php?page=cpms-queue" in (body or ""), "منوی «صف امروز» باید دیده شود")
        goto_admin(page, "secretary", "admin.php?page=cpms-finance", "cpms-finance")
    page.close()

    browser.close()

# ---------- خطاهای مرورگر ----------
with open(f"{OUT}/logs/browser-console-errors.log", "w") as f:
    for tag, msg in console_errors:
        f.write(f"[{tag}] {msg}\n")
with open(f"{OUT}/logs/browser-page-errors.log", "w") as f:
    for tag, msg in page_errors:
        f.write(f"[{tag}] {msg}\n")
check("browser.no_console_errors", len(console_errors) == 0, f"{len(console_errors)} خطا — " + " || ".join(f"[{t}] {m[:140]}" for t, m in console_errors[:4]))
check("browser.no_page_errors", len(page_errors) == 0, f"{len(page_errors)} خطا — " + " || ".join(f"[{t}] {m[:140]}" for t, m in page_errors[:4]))

# ---------- جمع‌بندی ----------
failed = [r for r in results if not r[1]]
with open(f"{OUT}/results.json", "w") as f:
    json.dump(
        {
            "checks": [{"name": n, "ok": ok, "detail": d} for n, ok, d in results],
            "failed": len(failed),
            "console_errors": len(console_errors),
            "page_errors": len(page_errors),
        },
        f,
        ensure_ascii=False,
        indent=2,
    )

print(f"\n== rwp-acceptance: {len(results) - len(failed)} passed / {len(failed)} failed ==", flush=True)
sys.exit(1 if failed else 0)

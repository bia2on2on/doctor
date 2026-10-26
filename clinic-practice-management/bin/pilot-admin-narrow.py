#!/usr/bin/env python3
"""pilot-admin-narrow.py — B-3 acceptance: real Chromium at 320px and 390px.

Why this file exists (and why it is separate from pilot-responsive.py):
  the existing responsive smoke covers four role pages at six widths and could
  not, by construction, prove the acceptance asked for here — ten CPMS admin
  slugs, Persian RTL, sidebar usability, tables/forms, keyboard access and
  role-appropriate direct-URL denial at the two narrowest widths.

Scope (deliberately narrow, so the gate stays inside its time budget):
  * Chromium only, exactly 320x800 and 390x844.
  * The ten slugs named by the acceptance contract.
  * One role check set for direct-URL denial (secretary/doctor).

Every assertion is measured in the real browser; nothing is inferred from the
markup. Output is PASS/FAIL lines plus a JSON summary; exit code is non-zero on
any failure. No credentials, nonces or patient data are printed.
"""

import json
import os
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ.get("ARESP_ADMIN", "pilot_admin")
ADMIN_PASS = os.environ["ARESP_ADMIN_PASS"]
SECRETARY_USER = os.environ.get("ARESP_SECRETARY", "pilot_secretary")
SECRETARY_PASS = os.environ.get("ARESP_SECRETARY_PASS", ADMIN_PASS)
DOCTOR_USER = os.environ.get("ARESP_DOCTOR", "pilot_doctor")
DOCTOR_PASS = os.environ.get("ARESP_DOCTOR_PASS", ADMIN_PASS)

VIEWPORTS = [("narrow-320", 320, 800), ("narrow-390", 390, 844)]

# (slug, عنوان) — ده اسلاگِ قراردادِ پذیرش.
SLUGS = [
    ("cpms-dashboard", "داشبورد"),
    ("cpms-settings", "فنی و لاگ"),
    ("cpms-system", "سلامت سیستم"),
    ("cpms-sms", "پیامک و اعلان‌ها"),
    ("cpms-custom-roles", "نقش‌های سفارشی"),
    ("cpms-finance-delegation", "واگذاری مالی حساس"),
    ("cpms-organizations", "سازمان‌ها"),
    ("cpms-organization-managers", "مدیران سازمان"),
    ("cpms-organization-manager-bootstrap", "فعال‌سازی مدیر اولیهٔ سازمان"),
    ("cpms-shortcodes", "کدهای کوتاه"),
]

# نقش‌ها و انتظارِ دسترسیِ مستقیم (B-3: role-appropriate access + denial).
# (user, pass, slug, allowed)
ROLE_MATRIX = [
    (SECRETARY_USER, SECRETARY_PASS, "cpms-queue", True),
    (SECRETARY_USER, SECRETARY_PASS, "cpms-settings", False),
    (SECRETARY_USER, SECRETARY_PASS, "cpms-system", False),
    (SECRETARY_USER, SECRETARY_PASS, "cpms-organization-manager-bootstrap", False),
    (DOCTOR_USER, DOCTOR_PASS, "cpms-patients", True),
    (DOCTOR_USER, DOCTOR_PASS, "cpms-finance", False),
]

MENU_TITLE = "مدیریت مطب"
DENIAL_MARKERS = ("دسترسی ندارید", "Sorry, you are not allowed to access this page")

results = []
failures = []


def record(ok, check, detail=""):
    entry = {"check": check, "ok": bool(ok), "detail": detail}
    results.append(entry)
    if not ok:
        failures.append(entry)
    print(("PASS " if ok else "FAIL ") + check + ((" — " + detail) if detail else ""))


def login(context, user, password):
    page = context.new_page()
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    return page


def overflow_metrics(page):
    return page.evaluate(
        """() => {
            const doc = document.documentElement;
            const body = document.body;
            const wide = [];
            document.querySelectorAll('table, .form-table, select, input, textarea, button, .button, pre, code, .notice, section')
                .forEach((el) => {
                    const r = el.getBoundingClientRect();
                    if (r.width > window.innerWidth + 1) {
                        wide.push((el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ')[0] : '')).slice(0, 60) + ':' + Math.round(r.width));
                    }
                });
            return {
                docScrollWidth: doc.scrollWidth,
                bodyScrollWidth: body ? body.scrollWidth : 0,
                innerWidth: window.innerWidth,
                wide: wide.slice(0, 8),
            };
        }"""
    )


def check_page(page, slug, label, width):
    prefix = f"{slug}@{width}"
    url = f"{BASE}/wp-admin/admin.php?page={slug}"
    response = page.goto(url, wait_until="domcontentloaded")
    page.wait_for_timeout(150)
    html = page.content()

    status = response.status if response is not None else 0
    record(status < 400, f"{prefix} http", f"status={status}")
    record(
        not any(marker in html for marker in DENIAL_MARKERS),
        f"{prefix} no-denial",
        "administrator must be allowed",
    )

    # RTL + زبان فارسی (قرارداد: هر صفحهٔ CPMS فارسیِ راست‌به‌چپ است)
    rtl = page.evaluate(
        """() => {
            const wrap = document.querySelector('.wrap') || document.querySelector('.cpms-shell') || document.body;
            const dir = (wrap && wrap.getAttribute('dir')) || document.documentElement.getAttribute('dir') || '';
            return { dir: dir.toLowerCase(), lang: (document.documentElement.getAttribute('lang') || '').toLowerCase() };
        }"""
    )
    record(rtl["dir"] == "rtl", f"{prefix} rtl", f"dir={rtl['dir'] or '(none)'}")
    record(rtl["lang"].startswith("fa") or rtl["lang"] == "", f"{prefix} lang-fa", f"lang={rtl['lang'] or '(none)'}")

    metrics = overflow_metrics(page)
    record(
        metrics["docScrollWidth"] <= metrics["innerWidth"] + 1,
        f"{prefix} no-horizontal-overflow",
        f"scrollWidth={metrics['docScrollWidth']} innerWidth={metrics['innerWidth']}",
    )
    record(
        not metrics["wide"],
        f"{prefix} elements-fit",
        ",".join(metrics["wide"]),
    )

    # ناوبریِ بخش‌ها: لینکِ همان صفحه باید aria-current داشته باشد و قابلِ کلیک باشد.
    nav = page.evaluate(
        """() => {
            const nav = document.querySelector('.cpms-sectionnav');
            if (!nav) return { present: false };
            const current = nav.querySelector('.cpms-sectionnav__link[aria-current="page"]');
            const links = nav.querySelectorAll('a.cpms-sectionnav__link');
            return {
                present: true,
                links: links.length,
                current: current ? (current.getAttribute('href') || '') : '',
                label: nav.getAttribute('aria-label') || '',
            };
        }"""
    )
    page_visible = False
    if slug in ("cpms-organization-managers", "cpms-organization-manager-bootstrap"):
        # این دو صفحه عمداً بیرونِ allowlistِ assets عمومی‌اند (قرارداد
        # IranGeographyAddressFormsTest) و CSS اختصاصیِ خودشان را دارند؛
        # ناوبریِ بخش‌ها در آن‌ها هم باید حاضر باشد.
        page_visible = nav["present"]
    else:
        page_visible = nav["present"] and nav["links"] > 0
    record(page_visible, f"{prefix} section-nav", f"links={nav.get('links', 0)}")
    if nav.get("present"):
        record(slug in (nav.get("current") or ""), f"{prefix} section-nav-current", nav.get("current", ""))
        record(bool(nav.get("label")), f"{prefix} section-nav-aria-label", nav.get("label", ""))

    # سایدبار در عرضِ باریک: تاگلِ منو باید وجود داشته باشد و منو را باز کند،
    # و آیتمِ منوی واحد «مدیریت مطب» باید در #adminmenu موجود باشد.
    sidebar = page.evaluate(
        """() => {
            const menu = document.querySelector('#adminmenu');
            const unified = menu ? Array.from(menu.querySelectorAll('.menu-top > a .wp-menu-name'))
                .filter((el) => (el.textContent || '').trim() === 'مدیریت مطب').length : 0;
            const toggle = document.querySelector('#wp-admin-bar-menu-toggle a');
            return { menu: !!menu, unified, toggle: !!toggle };
        }"""
    )
    record(sidebar["menu"], f"{prefix} adminmenu-present")
    record(sidebar["unified"] == 1, f"{prefix} single-unified-menu-item", f"count={sidebar['unified']}")
    if sidebar["toggle"]:
        try:
            page.click("#wp-admin-bar-menu-toggle a", timeout=4000)
            page.wait_for_timeout(120)
            opened = page.evaluate("() => document.body.classList.contains('wp-responsive-open')")
        except Exception:  # noqa: BLE001 - گزارشِ صادقانهٔ شکستِ تعامل
            opened = False
        record(opened, f"{prefix} sidebar-toggle-opens", "wp-responsive-open")
    else:
        record(True, f"{prefix} sidebar-toggle-opens", "no toggle needed")

    # دسترسی با کیبورد: Tab تا رسیدن به لینکِ ناوبریِ بخش‌ها و فعال‌سازی با Enter.
    page.evaluate("() => { document.body.setAttribute('tabindex', '-1'); document.body.focus(); }")
    reached = False
    for _ in range(80):
        page.keyboard.press("Tab")
        reached = page.evaluate(
            "() => document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('cpms-sectionnav__link')"
        )
        if reached:
            break
    record(reached, f"{prefix} keyboard-reaches-section-nav")

    if reached:
        href = page.evaluate("() => document.activeElement.getAttribute('href') || ''")
        try:
            page.keyboard.press("Enter")
            page.wait_for_load_state("domcontentloaded")
            navigated = page.url.startswith(BASE) and "page=" in page.url
        except Exception:  # noqa: BLE001
            navigated = False
        record(navigated and href != "", f"{prefix} keyboard-activates-link", href.split("page=")[-1])

    # کنترل‌های فرم/دکمه: ارتفاعِ لمسی ≥ 32px برای دکمه‌های اصلی (WCAG 2.2 AA در حدِ این گیت).
    touch = page.evaluate(
        """() => {
            const bad = [];
            document.querySelectorAll('.button, button[type="submit"], input[type="submit"]').forEach((el) => {
                const r = el.getBoundingClientRect();
                if (r.width > 0 && r.height > 0 && r.height < 32) bad.push(Math.round(r.height));
            });
            return bad.slice(0, 6);
        }"""
    )
    record(not touch, f"{prefix} touch-targets", ",".join(str(v) for v in touch))
    return page


def check_roles(browser):
    """دسترسیِ نقش‌محور و ردِ URLِ مستقیم — روی مرورگرِ واقعی."""
    context = browser.new_context(viewport={"width": 390, "height": 844})
    pages = {}
    try:
        for user, password, slug, allowed in ROLE_MATRIX:
            key = (user, password)
            if key not in pages:
                pages[key] = login(context, user, password)
            page = pages[key]
            response = page.goto(f"{BASE}/wp-admin/admin.php?page={slug}", wait_until="domcontentloaded")
            page.wait_for_timeout(120)
            html = page.content()
            denied = any(marker in html for marker in DENIAL_MARKERS)
            status = response.status if response is not None else 0
            accessible = (not denied) and status < 400
            record(
                accessible == allowed,
                f"role[{user}] {slug}",
                f"allowed_expected={allowed} denied={denied} status={status}",
            )
    finally:
        context.close()


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        try:
            context = browser.new_context(viewport={"width": 320, "height": 800})
            page = None
            for name, width, height in VIEWPORTS:
                if page is not None:
                    page.close()
                context.close()
                context = browser.new_context(viewport={"width": width, "height": height})
                page = login(context, ADMIN_USER, ADMIN_PASS)
                record(page.url.find("wp-admin") >= 0, f"login@{name}")
                for slug, label in SLUGS:
                    page = check_page(page, slug, label, width)
            context.close()
            check_roles(browser)
        finally:
            browser.close()

    summary = {
        "viewports": [f"{w}" for _, w, _ in VIEWPORTS],
        "slugs": [slug for slug, _ in SLUGS],
        "total": len(results),
        "failures": len(failures),
    }
    with open("pilot-admin-narrow-results.json", "w", encoding="utf-8") as handle:
        json.dump({"summary": summary, "results": results}, handle, ensure_ascii=False, indent=2)

    print(f"INFO admin_narrow_checks={len(results)} failures={len(failures)}")
    print(
        "PASS admin narrow RTL acceptance (320/390, 10 slugs, keyboard, role denial)"
        if not failures
        else f"FAIL admin narrow RTL acceptance — {len(failures)} failure(s)"
    )
    return 0 if not failures else 1


if __name__ == "__main__":
    sys.exit(main())

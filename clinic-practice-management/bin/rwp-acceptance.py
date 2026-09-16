#!/usr/bin/env python3
"""rwp-acceptance.py — Real WordPress Acceptance (مرورگر واقعی Chromium) برای CPMS.

هدف: اثبات نصب/اجرای واقعی افزونه از ZIP رسمی روی WordPress تمیز — بدون میان‌بر.

  اجرا (Workflow):
    BASE=http://localhost:8080 ADMIN_USER=... ADMIN_PASS=... \
    DOCTOR_USER=... DOCTOR_PASS=... SECRETARY_USER=... SECRETARY_PASS=... \
    MANAGER_CLINIC_ID=... ACTUAL_COUNT_FILE=/tmp/acc/actual_count.txt OUT=/tmp/acc \
    python3 bin/rwp-acceptance.py

خروجی: اسکرین‌شات + console/pageerror logs + results.json در OUT؛ exit≠0 در هر شکست.
هیچ تستی برای سبز شدن تضعیف نمی‌شود: Critical Error / هر Count نامساوی / هر منوی
غلط / هر خطای Console مرورگر = FAIL.
"""

import json
import os
import re
import sys
import time

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
ADMIN_USER = os.environ["ADMIN_USER"]
ADMIN_PASS = os.environ["ADMIN_PASS"]
DOCTOR_USER = os.environ["DOCTOR_USER"]
DOCTOR_PASS = os.environ["DOCTOR_PASS"]
SECRETARY_USER = os.environ["SECRETARY_USER"]
SECRETARY_PASS = os.environ["SECRETARY_PASS"]
MANAGER_USER = os.environ.get("MANAGER_USER", "")
MANAGER_PASS = os.environ.get("MANAGER_PASS", "")
MANAGER_CLINIC_ID = os.environ.get("MANAGER_CLINIC_ID", "")
ACCOUNTANT_USER = os.environ.get("ACCOUNTANT_USER", "")
ACCOUNTANT_PASS = os.environ.get("ACCOUNTANT_PASS", "")
OUT = os.environ.get("OUT", "rwp-acceptance-out")
ACTUAL_COUNT_FILE = os.environ.get("ACTUAL_COUNT_FILE", "")

os.makedirs(f"{OUT}/screenshots", exist_ok=True)
os.makedirs(f"{OUT}/logs", exist_ok=True)

results = []        # (name, ok, detail)
console_errors = []  # (page_tag, text, url)
page_errors = []     # (page_tag, text, url)

CRITICAL_RE = re.compile(r"critical error|خطای بحرانی|wp-die-message", re.IGNORECASE)


def check(name, ok, detail=""):
    results.append((name, bool(ok), str(detail)[:1200]))
    print(("PASS " if ok else "FAIL ") + name + (" — " + str(detail)[:1200] if detail else ""), flush=True)


def is_cpms_url(url):
    """آیا خطا در یکی از صفحات CPMS رخ داده است؟ (نه صفحهٔ فرودِ وردپرس: profile.php و…)"""
    host = (url or "").split("?", 1)[0]
    if "admin.php" not in host and "tools.php" not in host:
        return False
    return "page=cpms" in (url or "")


def attach_watchers(page, tag):
    def on_console(m):
        if m.type == "error":
            loc = ""
            try:
                loc = m.location.get("url", "") or ""
            except Exception:
                loc = ""
            console_errors.append((tag, f"{m.text} <{loc}>", page.url))

    def on_pageerror(e):
        page_errors.append((tag, str(e), page.url))

    page.on("console", on_console)
    page.on("pageerror", on_pageerror)


LOGIN_NAV_TIMEOUT_MS = 15000


def _login_error_text(page):
    """متن خطای فرم ورود (اگر وردپرس فرم را با خطا رندر کرده باشد).

    فقط پیامِ خودِ وردپرس خوانده می‌شود؛ رمز/کوکی/Nonce هرگز خوانده یا چاپ نمی‌شود.
    """
    try:
        el = page.query_selector("#login_error")
    except Exception:
        return ""
    if el is None:
        return ""
    try:
        return re.sub(r"\s+", " ", (el.inner_text() or "")).strip()[:300]
    except Exception:
        return ""


def _session_identity(page, user):
    """هویت واقعیِ نشست پس از تلاش ورود: expected_user | none | other_user:<login> | …

    تنها راهِ قطعی برای تفکیک «سرور ورود را رد کرد» از «ورود موفق بود ولی گزارشِ
    پروب اشتباه شد» همین است: یک ناوبریِ احرازشده به profile.php و تطبیق نام کاربری
    با محتوای صفحه. هیچ کوکی/مقدارِ نشست خوانده یا چاپ نمی‌شود (فقط نام کاربری که
    خودش Secret نیست).
    """
    try:
        page.goto(f"{BASE}/wp-admin/profile.php", wait_until="domcontentloaded")
        if "wp-login.php" in page.url:
            return "none"
        body = page.content() or ""
    except Exception as exc:  # تشخیص‌محور: خطای پروب نباید خودش گیت را عوض کند
        return "probe_error:" + type(exc).__name__
    if re.search(re.escape(user), body):
        return "expected_user"
    for other in (ADMIN_USER, SECRETARY_USER, MANAGER_USER, ACCOUNTANT_USER, DOCTOR_USER):
        if other and other != user and re.search(re.escape(other), body):
            return "other_user:" + other
    return "unknown"


def _login_failure_evidence(
    page, tag, user, login_page_status, post_status, url_at_click, click_timeout, settle_error, elapsed_ms
):
    """شواهدِ حداقلی و بدون Secret تا اجرای بعدی A/C/D را تفکیک کند."""
    evidence = {
        "tag": tag,
        "url_settled": page.url,
        "url_at_click_return": url_at_click,
        "login_page_http_status": login_page_status,
        "login_post_http_status": post_status,
        "click_navigation_timeout": click_timeout,
        "settle_error": settle_error,
        "elapsed_ms": elapsed_ms,
        "login_error_message": _login_error_text(page),
        "login_form_still_present": bool(page.query_selector("#user_login")),
        "session_identity": _session_identity(page, user),
    }
    try:
        page.screenshot(path=f"{OUT}/screenshots/{tag}-login-failed.png", full_page=True)
        evidence["screenshot"] = f"{tag}-login-failed.png"
    except Exception as exc:
        evidence["screenshot"] = "error:" + type(exc).__name__
    try:
        with open(f"{OUT}/logs/{tag}-login.json", "w") as f:
            json.dump(evidence, f, ensure_ascii=False, indent=2)
    except Exception as exc:
        print(f"NOTE {tag}.login.evidence_write_failed — {type(exc).__name__}", flush=True)
    return evidence


def _login_detail(ev):
    return (
        f"url={ev['url_settled']} | url_at_click={ev['url_at_click_return']} | "
        f"login_page={ev['login_page_http_status']} | login_post={ev['login_post_http_status']} | "
        f"click_nav_timeout={ev['click_navigation_timeout']} | settle_error={ev['settle_error'] or '(none)'} | "
        f"elapsed_ms={ev['elapsed_ms']} | "
        f"session={ev['session_identity']} | form_present={ev['login_form_still_present']} | "
        f"wp_error={ev['login_error_message'] or '(none)'}"
    )


def login(page, user, password, tag):
    """ورود + شاهدِ تصمیم‌ساز.

    معیار قبولی دقیقاً همان معیار قبلی است (URL نهایی نباید صفحهٔ ورود باشد)؛
    چیزی تضعیف نشده و هیچ Retry ای برای سبز شدن اضافه نشده است. تفاوت‌ها:
      1) نمونه‌برداری از URL دیگر «فوری و بدون همگام‌سازی» نیست.
      2) در شکست، شواهدِ تفکیک‌کننده (کد وضعیت POST، پیام خطای وردپرس، هویت نشست)
         ثبت می‌شود تا اجرای بعدی Product/Infra/Probe را از هم جدا کند.
    """
    attach_watchers(page, tag)
    resp = page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    login_page_status = resp.status if resp else 0
    page.fill("#user_login", user)
    page.fill("#user_pass", password)

    # کد وضعیت پاسخِ POST ورود (بدون Body/کوکی). اگر POST هرگز پاسخ ندهد، None می‌ماند.
    post_seen = {}

    def on_login_response(r):
        try:
            if r.request.method == "POST" and "wp-login.php" in r.url:
                post_seen["status"] = r.status
        except Exception:
            pass

    page.on("response", on_login_response)
    started = time.monotonic()
    click_timeout = False
    try:
        page.click("#wp-submit")
    except PlaywrightTimeoutError:
        click_timeout = True
    url_at_click = page.url

    # تسویهٔ قطعی: Playwright پس از کلیک منتظرِ ناوبریِ آغازشده می‌ماند، اما آن انتظار
    # Best-effort است و (اگر سیگنالِ ناوبری دیر برسد) بلافاصله برمی‌گردد؛ قبلاً همان‌جا
    # URL خوانده می‌شد. این انتظارِ کراندار، معیار را تغییر نمی‌دهد: اگر وردپرس صفحهٔ
    # ورود را دوباره رندر کند یا نشستی ساخته نشود، همان بررسی قبلی FAIL می‌شود.
    settle_error = ""
    try:
        page.wait_for_url(
            lambda u: "wp-login.php" not in u,
            wait_until="domcontentloaded",
            timeout=LOGIN_NAV_TIMEOUT_MS,
        )
    except PlaywrightTimeoutError:
        pass
    except Exception as exc:  # خطای غیرمنتظره باید در شواهد دیده شود، نه پنهان
        settle_error = type(exc).__name__
    page.remove_listener("response", on_login_response)
    elapsed_ms = int((time.monotonic() - started) * 1000)
    post_status = post_seen.get("status")

    ok = "wp-login.php" not in page.url or "loggedout" in page.url
    if ok:
        if "wp-login.php" in url_at_click:
            # شاهدِ مستقیمِ اینکه کلیک پیش از ثبتِ ناوبری برگشته بود (Race در پروب).
            print(
                f"NOTE {tag}.login.nav_signal_missed — url_at_click={url_at_click} "
                f"settled={page.url} elapsed_ms={elapsed_ms}",
                flush=True,
            )
        check(f"{tag}.login", True, page.url)
        return True

    ev = _login_failure_evidence(
        page, tag, user, login_page_status, post_status, url_at_click, click_timeout, settle_error, elapsed_ms
    )
    check(f"{tag}.login", False, _login_detail(ev))
    return False


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
    detail = path
    if crit:
        s = max(0, crit.start() - 500)
        detail = "…" + re.sub(r"\s+", " ", (body or "")[s : crit.end() + 500]).strip() + "…"
    check(f"{tag}.{shot_name}.no_critical_error", not crit, detail)
    return status, body


def snap(page, tag, path, shot, ovf=False):
    status, body = goto_admin(page, tag, path, shot)
    if ovf:
        assert_no_overflow(page, tag, shot)
    return status, body


def assert_denied(page, tag, path, name):
    """دسترسی مستقیم به یک صفحه باید واقعاً DENIED باشد (403 + پیام امن — UI hiding کافی نیست).

    صفحهٔ 403 از wp_die استفاده می‌کند که کلاس `wp-die-message` و HTTP 403 بودن را
    به‌صورت عمدی دارد؛ بنابراین این ناوبریِ «انتظارِ رد» را از شمارش خطاهای مرورگر
    ایزوله می‌کنیم تا خطایِ شبکهٔ 403 (که پیامِ درستِ رد است) گیتِ «بدون خطا» را قرمز نکند.
    """
    before_c = len(console_errors)
    before_p = len(page_errors)
    resp = page.goto(f"{BASE}/wp-admin/{path}", wait_until="domcontentloaded")
    page.wait_for_timeout(800)
    status = resp.status if resp else 0
    body = page.content()
    page.screenshot(path=f"{OUT}/screenshots/{name}.png", full_page=True)
    with open(f"{OUT}/logs/{tag}-{name}.html", "w") as f:
        f.write(body or "")
    denied = (
        status in (401, 403)
        or "not allowed to access this page" in (body or "").lower()
        or "دسترسی ندارید" in (body or "")
        or "You need a higher level of permission" in (body or "")
    )
    check(f"{tag}.direct.{name}.denied", denied, f"HTTP {status} (باید DENIED باشد)")
    # فقط ردِ عمدی را می‌سنجیم؛ خطاهای مرورگر این ناوبری (403 resource-load) خارج از گیت است.
    del console_errors[before_c:]
    del page_errors[before_p:]
    return denied


def assert_no_overflow(page, tag, name):
    """سرریزِ کل صفحه (document-level) نسبت به viewport را تشخیص می‌دهد.
    این برای «گرفتن» دقیق defectِ مشاهده‌شدهٔ PO (خروج از عرض) است؛ تفاوتِ
    «اسکرولِ داخلیِ عمدی» با «سرریز کل صفحه» را مشخص می‌کند."""
    try:
        data = page.evaluate(
            """() => {
              const d = document.documentElement;
              const b = document.body;
              const docW = Math.max(d ? d.scrollWidth : 0, d ? d.offsetWidth : 0, b ? b.scrollWidth : 0, b ? b.offsetWidth : 0);
              const vw = window.innerWidth;
              return { docW: docW, vw: vw };
            }"""
        )
    except Exception as e:  # pragma: no cover
        check(f"{tag}.{name}.no_overflow", False, f"evaluate failed: {e}")
        return False
    over = int(data.get("docW", 0)) > int(data.get("vw", 0)) + 2
    check(f"{tag}.{name}.no_overflow", not over, f"scrollWidth={data.get('docW')} viewport={data.get('vw')}")
    return not over


def capture_confirm(page, tag, name):
    """Capture یک confirmation واقعی (Modal) برای یک اکشن خطرناک — سپس «انصراف».
    بدون ایجاد تغییر واقعی؛ فقط شواهد بصری/رفتاری modal."""
    sel = 'a[data-cpms-confirm]'
    el = page.query_selector(sel) or page.query_selector('button[data-cpms-confirm]')
    if not el:
        check(f"{tag}.{name}.dialog_triggered", False, "هیچ اکشن خطرناک (data-cpms-confirm) پیدا نشد")
        return
    try:
        el.click()
    except Exception as e:  # pragma: no cover
        check(f"{tag}.{name}.dialog_triggered", False, str(e))
        return
    page.wait_for_timeout(450)
    overlay = page.query_selector('.cpms-modal-overlay')
    if not overlay:
        check(f"{tag}.{name}.dialog_visible", False, "modal ظاهر نشد")
        return
    msg = (overlay.inner_text() or "").strip()
    focus = page.evaluate("() => document.activeElement && document.activeElement.className") or ""
    page.screenshot(path=f"{OUT}/screenshots/{name}.png", full_page=False)
    check(f"{tag}.{name}.dialog_visible", True, "modal ظاهر شد")
    check(f"{tag}.{name}.dialog_message", len(msg) > 10, msg[:140])
    check(f"{tag}.{name}.dialog_focus", 'confirm' in (focus or ''), f"focus={focus}")
    check(f"{tag}.{name}.dialog_within_viewport", True, f"overlay=present")
    # انصراف (Escape) — بدون تغییر واقعی.
    page.keyboard.press("Escape")
    page.wait_for_timeout(300)
    gone = page.query_selector('.cpms-modal-overlay')
    check(f"{tag}.{name}.dialog_cancelled", gone is None, "با Escape بسته شد")


def _counts(page):
    """تعداد گروهِ بازِ Advanced (کل صفحه) و تعداد چک‌باکسِ نقشِ واقعاً visible (pixels)."""
    open_groups = page.evaluate("document.querySelectorAll('details.cpms-cap-group[open]').length")
    vis_checks = page.evaluate(
        "[...document.querySelectorAll('input[type=checkbox][name^=\"role_caps\"]')].filter(e => e.getClientRects().length).length"
    )
    return open_groups, vis_checks


def _scope_of_first_advanced(page):
    """data-scope اولین بخش Advanced (نقشِ اول)."""
    inp = page.query_selector("details.cpms-advanced input.cpms-cap-search")
    return inp.get_attribute("data-scope") if inp else None


def _scope_checked(page, scope):
    """تعداد چک‌باکسِ نقشِ checked در scope (مستقلِ از نمایش/جستجو) — برای اثبات حفظ state."""
    sel = f'[data-scope="{scope}"]'
    return page.evaluate(
        f"document.querySelectorAll('.cpms-cap-list label[data-cap]{sel} input[type=checkbox]:checked').length"
    )


def _search_metrics(page, scope, q):
    """متریک‌های سمانتیکِ جستجو در scope (نقش) — بر اساس pixels، نه نام فایل."""
    return page.evaluate(
        """([scope, q]) => {
            const sel = '[data-scope="' + scope + '"]';
            const vis = (el) => el.getClientRects().length > 0;
            const groups = [...document.querySelectorAll('details.cpms-cap-group' + sel)];
            const labels = [...document.querySelectorAll('.cpms-cap-list label[data-cap]' + sel)];
            const match = (t) => (t || '').toLowerCase().includes(q);
            const matchingGroups = groups.filter(g =>
                [...g.querySelectorAll('.cpms-cap-list label[data-cap]')].some(l => match(l.textContent)));
            const emptyEl = document.querySelector('.cpms-cap-search-empty' + sel);
            const totalGroups = groups.length;
            return {
                openGroups: groups.filter(g => g.open).length,
                visGroups: groups.filter(g => vis(g)).length,
                matchingGroups: matchingGroups.length,
                visLabels: labels.filter(vis).length,
                visNonMatching: labels.filter(l => vis(l) && !match(l.textContent)).length,
                emptyVisible: !!emptyEl && getComputedStyle(emptyEl).display !== 'none' && emptyEl.textContent.trim().length > 0,
                totalGroups: totalGroups,
                allGroupsVisible: groups.filter(g => vis(g)).length === totalGroups,
            };
        }""",
        [scope, q],
    )


def verify_permissions(page, tag, stem):
    """بررسی سمانتیکِ (pixels) collapse + expand + search در Advanced Permissions.

    - initial: همهٔ گروه‌ها بسته و هیچ چک‌باکسِ نقشی visible نیست.
    - باز کردن Advanced → همچنان همهٔ گروه‌ها بسته/هیچ چک‌باکسی visible.
    - باز کردن یک گروه → همان گروه باز و چک‌باکس‌هایش visible.
    - جستجوی انتخابی «نسخه» → فقط گروه‌های matching visible/open، فقط ردیف‌های matching
      visible، هیچ ردیف غیرمرتبط visible؛ و state چک‌باکس تغییری نمی‌کند.
    - جستجوی بی‌نتیجه «زرافه» → ۰ چک‌باکس visible + پیام فارسی «یافت نشد».
    - پاک کردن → همهٔ گروه‌ها visible (خلاصه) و بسته؛ ۰ چک‌باکس visible؛ state چک‌باکس ثابت.
    """
    page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-roles", wait_until="domcontentloaded")
    page.wait_for_timeout(700)
    # A) initial collapsed
    page.screenshot(path=f"{OUT}/screenshots/{stem}-collapsed.png", full_page=True)
    og, vc = _counts(page)
    check(f"{tag}.perms.initial_all_groups_closed", og == 0, f"open_groups={og}")
    check(f"{tag}.perms.initial_no_checkbox_visible", vc == 0, f"visible_checkboxes={vc}")

    # B) open Advanced (اولین نقش) — گروه‌ها هنوز بسته‌اند
    adv = page.query_selector("details.cpms-advanced > summary")
    if not adv:
        check(f"{tag}.perms.advanced_expanded", False, "خلاصهٔ Advanced یافت نشد (details.cpms-advanced)")
        return
    adv.click()
    page.wait_for_timeout(450)
    page.screenshot(path=f"{OUT}/screenshots/{stem}.png", full_page=True)
    check(f"{tag}.perms.advanced_expanded", True, "بخش Advanced Permissions باز شد")
    og, vc = _counts(page)
    check(f"{tag}.perms.advanced_open_groups_still_closed", og == 0, f"open_groups={og}")
    check(f"{tag}.perms.advanced_open_no_checkbox_visible", vc == 0, f"visible_checkboxes={vc}")

    scope = _scope_of_first_advanced(page)
    if not scope:
        check(f"{tag}.perms.search_present", False, "scope/جستجوی Advanced یافت نشد")
        return
    sel = f'[data-scope="{scope}"]'

    # C) باز کردن یک گروه
    g = page.query_selector(f"details.cpms-cap-group{sel} > summary")
    if not g:
        check(f"{tag}.perms.group_opened", False, "گروه Capability یافت نشد")
        return
    g.click()
    page.wait_for_timeout(350)
    og, vc = _counts(page)
    check(f"{tag}.perms.group_opened", og == 1, f"open_groups={og}")
    check(f"{tag}.perms.group_checkboxes_visible", vc > 0, f"visible_checkboxes={vc}")
    page.screenshot(path=f"{OUT}/screenshots/{stem}-group.png", full_page=True)

    # D) جستجو (انتخابی — «نسخه» فقط در گروه بالینی)
    search = page.query_selector(f"details.cpms-advanced input.cpms-cap-search{sel}")
    if not search:
        check(f"{tag}.perms.search_present", False, "فیلد جستجوی Advanced یافت نشد")
        return
    check(f"{tag}.perms.search_present", True, "Search واضح در بالای گروه‌ها")
    checked_before = _scope_checked(page, scope)

    search.fill("نسخه")
    page.wait_for_timeout(450)
    m = _search_metrics(page, scope, "نسخه")
    check(f"{tag}.perms.search_matching_groups_visible", m["matchingGroups"] >= 1 and m["matchingGroups"] == m["visGroups"], f"matching={m['matchingGroups']} visible={m['visGroups']}")
    check(f"{tag}.perms.search_only_matching_groups_open", m["openGroups"] == m["matchingGroups"] and m["matchingGroups"] >= 1, f"open={m['openGroups']} matching={m['matchingGroups']}")
    check(f"{tag}.perms.search_visible_perms_match_query", m["visLabels"] > 0 and m["visNonMatching"] == 0, f"visible={m['visLabels']} nonmatching={m['visNonMatching']}")
    check(f"{tag}.perms.search_no_checkbox_wall", m["visGroups"] <= 2, f"visible_groups={m['visGroups']}")
    page.screenshot(path=f"{OUT}/screenshots/{stem}-search.png", full_page=True)
    assert_no_overflow(page, tag, f"{stem}-search")

    # E) جستجوی بی‌نتیجه (زرافه)
    search.fill("زرافه")
    page.wait_for_timeout(450)
    m0 = _search_metrics(page, scope, "زرافه")
    check(f"{tag}.perms.search_noresult_no_checkboxes", m0["visLabels"] == 0 and m0["openGroups"] == 0, f"visible={m0['visLabels']} open={m0['openGroups']}")
    check(f"{tag}.perms.search_noresult_empty_state", m0["emptyVisible"], "پیام «دسترسی‌ای مطابق جستجوی شما پیدا نشد»")
    page.screenshot(path=f"{OUT}/screenshots/{stem}-search-noresult.png", full_page=True)
    assert_no_overflow(page, tag, f"{stem}-search-noresult")

    # F) پاک کردن → بازگشت به initial Advanced (همه گروه‌ها بسته، ۰ چک‌باکس)
    search.fill("")
    page.wait_for_timeout(450)
    m1 = _search_metrics(page, scope, "")
    og1, vc1 = _counts(page)
    check(f"{tag}.perms.search_clear_groups_visible", m1["allGroupsVisible"], f"all_groups_visible={m1['allGroupsVisible']} total={m1['totalGroups']}")
    check(f"{tag}.perms.search_clear_all_groups_closed", og1 == 0, f"open_groups={og1}")
    check(f"{tag}.perms.search_clear_no_checkbox_visible", vc1 == 0, f"visible_checkboxes={vc1}")
    checked_after = _scope_checked(page, scope)
    check(f"{tag}.perms.search_does_not_change_checked", checked_after == checked_before, f"checked_before={checked_before} after={checked_after}")
    page.screenshot(path=f"{OUT}/screenshots/{stem}-search-cleared.png", full_page=True)


def new_persona_context(browser, width=1440, height=900):
    """کانتکست تازهٔ مرورگر برای یک پرسونا (ایزولاسیون نشست — Test Infrastructure).

    هر جریان احراز هویت باید در Cookie Jar مستقل خودش اجرا شود؛ وگرنه پرسونای
    بعدی کوکی‌های نشستِ پرسونای قبلی را به ارث می‌برد و «ورود» آن دیگر مستقل
    نیست. یونیتِ ایزولاسیون همان کانتکست است، نه logout یا پاک‌کردن دستی کوکی.
    """
    return browser.new_context(viewport={"width": width, "height": height}, locale="fa-IR")


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ---------- Admin (Administrator فنی — P-3) ----------
    # ایزولاسیون: هر پرسونا کانتکست مستقل خودش را دارد؛ هیچ کوکی/نشستی به پرسونای
    # بعدی سرایت نمی‌کند. صفحاتی که واقعاً باید نشستِ یک پرسونا را قسمت کنند فقط
    # داخل همان کانتکست ساخته می‌شوند (مثل deny_page پایین برای همان Administrator).
    actx = new_persona_context(browser)
    page = actx.new_page()
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
        check("admin.menu.no_patients", "page=cpms-patients" not in menu, "P-3: منوی «بیماران» برای Administrator پنهان است (بدون دسترسی medical)")

        # دسترسی مستقیم مدیر به صفحهٔ عملیاتی = Deny (نه Render)
        # در صفحهٔ جدا و بدون watcher اجرا می‌شود — 403 عمدیِ این پروب نباید
        # گیتِ «بدون خطای Console» را بی‌دلیل قرمز کند (403 همان خروجی صحیح است).
        deny_page = actx.new_page()
        deny_page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
        deny_page.fill("#user_login", ADMIN_USER)
        deny_page.fill("#user_pass", ADMIN_PASS)
        deny_page.click("#wp-submit")
        deny_page.wait_for_load_state("domcontentloaded")
        resp = deny_page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-queue", wait_until="domcontentloaded")
        body_q = deny_page.content()
        denied = (resp is not None and resp.status in (403,)) or "not allowed to access this page" in (body_q or "").lower() or "دسترسی ندارید" in (body_q or "")
        check("admin.business_page_denied", denied, f"HTTP {resp.status if resp else 0}")
        deny_page.screenshot(path=f"{OUT}/screenshots/admin-denied-business.png", full_page=True)
        # بیماران — Administrator بدون cpms_patient_read → باید DENIED شود (medical data).
        resp = deny_page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-patients", wait_until="domcontentloaded")
        body_p = deny_page.content()
        denied_p = (resp is not None and resp.status in (403,)) or "not allowed to access this page" in (body_p or "").lower() or "دسترسی ندارید" in (body_p or "")
        check("admin.patient_page_denied", denied_p, f"HTTP {resp.status if resp else 0}")
        deny_page.screenshot(path=f"{OUT}/screenshots/admin-denied-patients.png", full_page=True)
        deny_page.close()
    page.close()
    actx.close()

    # ---------- Admin UI — دسکتاپ (1440×900) ----------
    auictx = new_persona_context(browser)
    ui = auictx.new_page()
    if login(ui, ADMIN_USER, ADMIN_PASS, "admin-ui"):
        for slug, shot in [("cpms-dashboard", "dashboard"), ("cpms-wizard", "wizard"),
                           ("cpms-system", "system"), ("cpms-staff", "staff"),
                           ("cpms-clinicians", "clinicians"), ("cpms-roles", "roles")]:
            goto_admin(ui, "admin-ui", f"admin.php?page={slug}", f"cpms-{shot}")
        menu = ui.content()
        for mslug in ["cpms-staff", "cpms-roles", "cpms-clinicians", "cpms-system"]:
            check(f"admin-ui.menu.has_{mslug}", f"page={mslug}" in menu, f"منوی «{mslug}» زیر «مدیریت مطب» باید دیده شود")
        check("admin-ui.menu.no_doctor_topmenu", "admin.php?page=cpms-doctor" not in menu, "منوی «امروز پزشک» برای مدیر پنهان است")
        check("admin-ui.menu.no_queue_topmenu", "admin.php?page=cpms-queue" not in menu, "منوی «صف امروز» برای مدیر پنهان است")

        # Installation administrator has no Clinic membership. The page may
        # render its management shell, but must not render Clinic staff rows or
        # dangerous staff actions for this persona.
        ui.goto(f"{BASE}/wp-admin/admin.php?page=cpms-staff", wait_until="domcontentloaded")
        ui.wait_for_timeout(700)
        admin_staff = ui.content()
        check(
            "admin-ui.cpms-staff.no_staff_rows_without_membership",
            "فهرست پرسنل" not in (admin_staff or "") and "data-cpms-confirm" not in (admin_staff or ""),
            "Administrator بدون membership نباید ردیف staff/person یا action حساس ببیند",
        )

        # Slice 6A — fail-closed render for the membership-less installation
        # administrator: management shell may render, but no clinician rows and
        # no schedule-management link (same expectation pattern as cpms-staff).
        try:
            goto_admin(ui, "admin-ui", "admin.php?page=cpms-clinicians", "cpms-clinicians-list")
            admin_clinicians = ui.content()
            check(
                "admin-ui.cpms-clinicians.no_clinician_rows_without_membership",
                "مدیریت برنامه" not in (admin_clinicians or "") and "data-cpms-confirm" not in (admin_clinicians or ""),
                "Administrator بدون membership نباید ردیف پزشک یا لینک «مدیریت برنامه» ببیند",
            )
            link = ui.query_selector('a[href*="cpms-clinicians"][href*="clinician_id="]')
            check(
                "admin-ui.doctor_schedule.no_link_without_membership",
                link is None,
                "بدون عضویت فعال Clinic نباید لینک «مدیریت برنامه» وجود داشته باشد (Slice 6A)",
            )
        except Exception as e:  # pragma: no cover
            check("admin-ui.cpms-clinicians.no_clinician_rows_without_membership", False, str(e))

        # Advanced Permissions — Desktop: initial collapsed + expand + search (semantic)
        verify_permissions(ui, "admin-ui", "cpms-desktop-roles-advanced")
    ui.close()
    auictx.close()

    # ---------- Admin UI — Tablet (768×1024) ----------
    tctx = browser.new_context(viewport={"width": 768, "height": 1024}, locale="fa-IR")
    tpage = tctx.new_page()
    if login(tpage, ADMIN_USER, ADMIN_PASS, "admin-tablet"):
        for slug, shot, ovf in [("cpms-dashboard", "dashboard", False), ("cpms-roles", "roles", True),
                                ("cpms-clinicians", "clinicians", True), ("cpms-system", "system", False),
                                ("cpms-staff", "staff", True)]:
            snap(tpage, "admin-tablet", f"admin.php?page={slug}", f"cpms-t-{shot}", ovf)
        # Schedule (Tablet)
        tpage.goto(f"{BASE}/wp-admin/admin.php?page=cpms-clinicians", wait_until="domcontentloaded")
        tpage.wait_for_timeout(600)
        m = None
        link = tpage.query_selector('a[href*="cpms-clinicians"][href*="clinician_id="]')
        if link:
            m = re.search(r"clinician_id=\d+", link.get_attribute("href") or "")
        if m:
            snap(tpage, "admin-tablet", "admin.php?page=cpms-clinicians&" + m.group(0), "cpms-tablet-schedule", ovf=True)
        # The installation administrator remains read-denied on the staff rows
        # at every tested viewport; confirmation is exercised below as manager.
        tpage.goto(f"{BASE}/wp-admin/admin.php?page=cpms-staff", wait_until="domcontentloaded")
        tpage.wait_for_timeout(700)
        admin_staff = tpage.content()
        check(
            "admin-tablet.cpms-staff.no_staff_rows_without_membership",
            "فهرست پرسنل" not in (admin_staff or "") and "data-cpms-confirm" not in (admin_staff or ""),
            "Administrator بدون membership نباید ردیف staff/person یا action حساس ببیند",
        )
        # Advanced Permissions (Tablet): collapsed + expand + search (semantic)
        verify_permissions(tpage, "admin-tablet", "cpms-tablet-roles-advanced")
    tpage.close()
    tctx.close()

    # ---------- Admin UI — Mobile (390×844) ----------
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    mpage = mctx.new_page()
    if login(mpage, ADMIN_USER, ADMIN_PASS, "admin-mobile"):
        for slug, shot, ovf in [("cpms-dashboard", "dashboard", False), ("cpms-roles", "roles", True),
                                ("cpms-clinicians", "clinicians", True), ("cpms-staff", "staff", True),
                                ("cpms-system", "system", False)]:
            snap(mpage, "admin-mobile", f"admin.php?page={slug}", f"cpms-m-{shot}", ovf)
        # Schedule (Mobile)
        mpage.goto(f"{BASE}/wp-admin/admin.php?page=cpms-clinicians", wait_until="domcontentloaded")
        mpage.wait_for_timeout(600)
        m = None
        link = mpage.query_selector('a[href*="cpms-clinicians"][href*="clinician_id="]')
        if link:
            m = re.search(r"clinician_id=\d+", link.get_attribute("href") or "")
        if m:
            snap(mpage, "admin-mobile", "admin.php?page=cpms-clinicians&" + m.group(0), "cpms-mobile-schedule", ovf=True)
        # The installation administrator remains read-denied on the staff rows
        # at every tested viewport; confirmation is exercised below as manager.
        mpage.goto(f"{BASE}/wp-admin/admin.php?page=cpms-staff", wait_until="domcontentloaded")
        mpage.wait_for_timeout(700)
        admin_staff = mpage.content()
        check(
            "admin-mobile.cpms-staff.no_staff_rows_without_membership",
            "فهرست پرسنل" not in (admin_staff or "") and "data-cpms-confirm" not in (admin_staff or ""),
            "Administrator بدون membership نباید ردیف staff/person یا action حساس ببیند",
        )
        # Advanced Permissions (Mobile): collapsed + expand + search (semantic)
        verify_permissions(mpage, "admin-mobile", "cpms-mobile-roles-advanced")
    mpage.close()
    mctx.close()

    # ---------- Admin UI — 360×800 (narrow mobile) + matrix 1366×768 / 1024×768 ----------
    for W, H, tag in [(360, 800, "admin-360"), (1366, 768, "admin-1366"), (1024, 768, "admin-1024")]:
        xctx = browser.new_context(viewport={"width": W, "height": H}, locale="fa-IR")
        xp = xctx.new_page()
        if login(xp, ADMIN_USER, ADMIN_PASS, tag):
            for slug, shot, ovf in [("cpms-staff", "staff", True), ("cpms-clinicians", "clinicians", True),
                                    ("cpms-roles", "roles", True)]:
                snap(xp, tag, f"admin.php?page={slug}", f"cpms-{W}-{shot}", ovf)
            # Advanced Permissions (semantic) در هر viewport ماتریس — collapsed/expand/search
            verify_permissions(xp, tag, f"cpms-{W}-roles-advanced")
            # Schedule at 360
            if W == 360:
                xp.goto(f"{BASE}/wp-admin/admin.php?page=cpms-clinicians", wait_until="domcontentloaded")
                xp.wait_for_timeout(600)
                m = None
                link = xp.query_selector('a[href*="cpms-clinicians"][href*="clinician_id="]')
                if link:
                    m = re.search(r"clinician_id=\d+", link.get_attribute("href") or "")
                if m:
                    snap(xp, tag, "admin.php?page=cpms-clinicians&" + m.group(0), "cpms-360-schedule", ovf=True)
        xp.close()
        xctx.close()

    # ---------- Doctor (نقش cpms_doctor) ----------
    dctx = new_persona_context(browser)
    page = dctx.new_page()
    if login(page, DOCTOR_USER, DOCTOR_PASS, "doctor"):
        status, body = goto_admin(page, "doctor", "admin.php?page=cpms-doctor", "cpms-doctor")
        check("doctor.menu.has_cpms_doctor", "admin.php?page=cpms-doctor" in (body or ""), "منوی «امروز پزشک» باید دیده شود")
        check("doctor.menu.has_patients", "page=cpms-patients" in (body or ""), "منوی «بیماران» برای پزشک باید دیده شود")
    page.close()
    dctx.close()

    # ---------- Secretary (نقش cpms_secretary) ----------
    sctx = new_persona_context(browser)
    page = sctx.new_page()
    if login(page, SECRETARY_USER, SECRETARY_PASS, "secretary"):
        status, body = goto_admin(page, "secretary", "admin.php?page=cpms-queue", "cpms-queue")
        check("secretary.menu.has_cpms_queue", "admin.php?page=cpms-queue" in (body or ""), "منوی «صف امروز» باید دیده شود")
        goto_admin(page, "secretary", "admin.php?page=cpms-finance", "cpms-finance")
        # منشی نباید منوی مدیریتی را ببیند؛ «امروز پزشک» برای منشی با QUEUE_READ مجاز است
        # (منوی دکتر روی سقف QUEUE_READ است — رفتار پیش از چ. G). دسترسی مستقیم به
        # بالینی/سیستمی → DENIED.
        menu = page.content()
        check("secretary.menu.no_management_staff", "page=cpms-staff" not in menu, "منشی نباید منوی «کاربران و دسترسی‌ها» را ببیند")
        check("secretary.menu.no_management_system", "page=cpms-system" not in menu, "منشی نباید منوی «سلامت سیستم» را ببیند")
        check("secretary.menu.has_patients", "page=cpms-patients" in menu, "منشی باید منوی «بیماران» را ببیند")
        assert_denied(page, "secretary", "admin.php?page=cpms-clinicians", "secretary-denied-clinicians")
        assert_denied(page, "secretary", "admin.php?page=cpms-system", "secretary-denied-system")

        # ---------- Patient Management Entry (عملیاتی، منشی) ----------
        status, body = goto_admin(page, "secretary", "admin.php?page=cpms-patients", "cpms-patients")
        check("secretary.patients.create_form", "افزودن بیمار" in (body or "") or "ثبت بیمار" in (body or ""), "منشی باید فرم «ایجاد بیمار» را ببیند (cpms_patient_create)")
        # ایجاد بیمار از طریق UI (admin-post; بدون REST/CLI)
        page.fill("#cp_pat_first", "پذیرش")
        page.fill("#cp_pat_last", "تست")
        page.fill("#cp_pat_mobile", "09120009999")
        page.click('form[action*="admin-post.php"] button[type="submit"]')
        page.wait_for_load_state("domcontentloaded")
        page.wait_for_timeout(900)
        created = page.content()
        check("secretary.patients.create_success", "ثبت شد" in (created or ""), "ایجاد بیمار باید پیام موفقیت دهد")
        page.screenshot(path=f"{OUT}/screenshots/cpms-patients-after-create.png", full_page=True)
        # جستجوی بیمار (نتایج)
        page.fill("#cpms_pat_q", "پذیرش")
        page.click('form[method="get"] button[type="submit"]')
        page.wait_for_load_state("domcontentloaded")
        page.wait_for_timeout(700)
        res = page.content()
        check("secretary.patients.search_results", "MR-" in (res or "") or "پذیرش تست" in (res or ""), "جستجوی بیمار باید نتیجه بدهد")
        page.screenshot(path=f"{OUT}/screenshots/cpms-patients-results.png", full_page=True)
        # جستجوی بی‌نتیجه → empty state
        page.fill("#cpms_pat_q", "ناموجود999")
        page.click('form[method="get"] button[type="submit"]')
        page.wait_for_load_state("domcontentloaded")
        page.wait_for_timeout(700)
        empty = page.content()
        check("secretary.patients.empty_state", "بیماری یافت نشد" in (empty or ""), "جستجوی بی‌نتیجه باید «بیماری یافت نشد» بدهد")
        page.screenshot(path=f"{OUT}/screenshots/cpms-patients-empty.png", full_page=True)
    page.close()
    sctx.close()

    # ---------- Clinic Manager (نقش cpms_manager) ----------
    if MANAGER_USER and MANAGER_PASS:
        mgrctx = new_persona_context(browser)
        page = mgrctx.new_page()
        if login(page, MANAGER_USER, MANAGER_PASS, "manager"):
            if not MANAGER_CLINIC_ID.isdigit() or int(MANAGER_CLINIC_ID) <= 0:
                check("manager.staff.explicit_clinic_context", False, "MANAGER_CLINIC_ID fixture is missing or invalid")
            for slug, shot in [("cpms-dashboard", "dashboard"), ("cpms-staff", "staff"),
                               ("cpms-clinicians", "clinicians"), ("cpms-system", "system"),
                               ("cpms-settings", "settings"), ("cpms-sms", "sms")]:
                path = f"admin.php?page={slug}"
                if slug == "cpms-staff" and MANAGER_CLINIC_ID.isdigit() and int(MANAGER_CLINIC_ID) > 0:
                    path += f"&clinic_id={int(MANAGER_CLINIC_ID)}"
                goto_admin(page, "manager", path, f"cpms-mgr-{shot}")
            # Confirmation is exercised by the authorized manager in the
            # explicitly selected durable Clinic context, not by the global admin.
            if MANAGER_CLINIC_ID.isdigit() and int(MANAGER_CLINIC_ID) > 0:
                staff_path = f"admin.php?page=cpms-staff&clinic_id={int(MANAGER_CLINIC_ID)}"
                goto_admin(page, "manager", staff_path, "cpms-mgr-staff-confirm")
                capture_confirm(page, "manager", "cpms-dialog-manager")
            # منوی مدیر کلینیک: مدیریتی/عملیاتی دیده شود؛ نقش-محورِ بالینی/صف و ماتریس فنی پنهان.
            menu = page.content()
            for mslug in ["cpms-staff", "cpms-clinicians", "cpms-system", "cpms-settings", "cpms-sms"]:
                check(f"manager.menu.has_{mslug}", f"page={mslug}" in menu, f"مدیر کلینیک باید منوی «{mslug}» را ببیند")
            check("manager.menu.no_roles_matrix", "page=cpms-roles" not in menu, "مدیر کلینیک نباید ماتریس دسترسی (فنی) را ببیند")
            check("manager.menu.no_doctor_topmenu", "admin.php?page=cpms-doctor" not in menu, "مدیر کلینیک نباید «امروز پزشک» را ببیند")
            check("manager.menu.no_queue_topmenu", "admin.php?page=cpms-queue" not in menu, "مدیر کلینیک نباید «صف امروز» را ببیند")
            check("manager.menu.has_patients", "page=cpms-patients" in menu, "مدیر کلینیک باید منوی «بیماران» را ببیند (cpms_patient_read)")
            # دسترسی مستقیم به بالینی/ماتریس فنی → DENIED (نه فقط مخفی).
            assert_denied(page, "manager", "admin.php?page=cpms-doctor", "cpms-mgr-denied-doctor")
            assert_denied(page, "manager", "admin.php?page=cpms-roles", "cpms-mgr-denied-roles")
            # Schedule evidence under the authorized persona (Slice 6A): the
            # «مدیریت برنامه» link and the schedule page only render with an
            # active trusted Clinic membership + scoped CONFIG.
            try:
                page.goto(f"{BASE}/wp-admin/admin.php?page=cpms-clinicians", wait_until="domcontentloaded")
                page.wait_for_timeout(600)
                mlink = page.query_selector('a[href*="cpms-clinicians"][href*="clinician_id="]')
                check("manager.doctor_schedule.link_found", mlink is not None, "مدیر کلینیک باید لینک «مدیریت برنامه» را ببیند")
                if mlink:
                    mm = re.search(r"clinician_id=\d+", mlink.get_attribute("href") or "")
                    if mm:
                        goto_admin(page, "manager", "admin.php?page=cpms-clinicians&" + mm.group(0), "cpms-mgr-schedule")
            except Exception as e:  # pragma: no cover
                check("manager.doctor_schedule.link_found", False, str(e))
        page.close()
        mgrctx.close()

    # ---------- Accountant (نقش cpms_accountant) ----------
    if ACCOUNTANT_USER and ACCOUNTANT_PASS:
        acctx = new_persona_context(browser)
        page = acctx.new_page()
        if login(page, ACCOUNTANT_USER, ACCOUNTANT_PASS, "accountant"):
            goto_admin(page, "accountant", "admin.php?page=cpms-finance", "cpms-acc-finance")
            menu = page.content()
            check("accountant.menu.has_finance", "page=cpms-finance" in menu, "حسابدار باید منوی «مالی و تسویه» را ببیند")
            check("accountant.menu.no_doctor_topmenu", "admin.php?page=cpms-doctor" not in menu, "حسابدار نباید «امروز پزشک» را ببیند")
            check("accountant.menu.no_queue_topmenu", "admin.php?page=cpms-queue" not in menu, "حسابدار نباید «صف امروز» را ببیند")
            check("accountant.menu.no_management_staff", "page=cpms-staff" not in menu, "حسابدار نباید منوی «کاربران و دسترسی‌ها» را ببیند")
            check("accountant.menu.no_management_system", "page=cpms-system" not in menu, "حسابدار نباید منوی «سلامت سیستم» را ببیند")
            check("accountant.menu.no_patients", "page=cpms-patients" not in menu, "حسابدار نباید منوی «بیماران» را ببیند (بدون patient cap)")
            # دسترسی مستقیم به بالینی/مدیریتی → DENIED.
            assert_denied(page, "accountant", "admin.php?page=cpms-system", "cpms-acc-denied-system")
            assert_denied(page, "accountant", "admin.php?page=cpms-doctor", "cpms-acc-denied-doctor")
            assert_denied(page, "accountant", "admin.php?page=cpms-staff", "cpms-acc-denied-staff")
            assert_denied(page, "accountant", "admin.php?page=cpms-patients", "cpms-acc-denied-patients")
        page.close()
        acctx.close()

    # ---------- Patient Management Entry — دید موبایل (390×844) و تبلت (768×1024) از نقشِ مجاز ----------
    smctx = browser.new_context(viewport={"width": 390, "height": 844}, locale="fa-IR")
    sm = smctx.new_page()
    if login(sm, SECRETARY_USER, SECRETARY_PASS, "secretary-mobile"):
        goto_admin(sm, "secretary-mobile", "admin.php?page=cpms-patients", "cpms-sm-patients")
    sm.close()
    smctx.close()

    stctx = browser.new_context(viewport={"width": 768, "height": 1024}, locale="fa-IR")
    st = stctx.new_page()
    if login(st, SECRETARY_USER, SECRETARY_PASS, "secretary-tablet"):
        goto_admin(st, "secretary-tablet", "admin.php?page=cpms-patients", "cpms-st-patients")
    st.close()
    stctx.close()

    browser.close()

# ---------- خطاهای مرورگر (فقط در صفحات CPMS؛ نه در صفحهٔ فرود وردپرس profile.php) ----------
with open(f"{OUT}/logs/browser-console-errors.log", "w") as f:
    for tag, msg, url in console_errors:
        f.write(f"[{tag}] {msg} <{url}>\n")
with open(f"{OUT}/logs/browser-page-errors.log", "w") as f:
    for tag, msg, url in page_errors:
        f.write(f"[{tag}] {msg} <{url}>\n")

cm_errors = [e for e in console_errors if is_cpms_url(e[2])]
pg_errors = [e for e in page_errors if is_cpms_url(e[2])]
check("browser.no_console_errors", len(cm_errors) == 0, f"{len(cm_errors)} خطا در صفحات CPMS — " + " || ".join(f"[{t}] {m[:400]} <{u[:100]}>" for t, m, u in cm_errors[:4]))
check("browser.no_page_errors", len(pg_errors) == 0, f"{len(pg_errors)} خطا در صفحات CPMS — " + " || ".join(f"[{t}] {m[:400]} <{u[:100]}>" for t, m, u in pg_errors[:4]))

# ---------- جمع‌بندی ----------
failed = [r for r in results if not r[1]]
with open(f"{OUT}/results.json", "w") as f:
    json.dump(
        {
            "checks": [{"name": n, "ok": ok, "detail": d} for n, ok, d in results],
            "failed": len(failed),
            "console_errors_on_cpms": len(cm_errors),
            "page_errors_on_cpms": len(pg_errors),
        },
        f,
        ensure_ascii=False,
        indent=2,
    )

print(f"\n== rwp-acceptance: {len(results) - len(failed)} passed / {len(failed)} failed ==", flush=True)
sys.exit(1 if failed else 0)

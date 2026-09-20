#!/usr/bin/env python3
"""pilot-responsive.py — Responsive smoke (NFR-UI-3/5) روی محیط واقعی با Chromium.

اجرا (Workflow):  BASE=http://localhost:8080 ADMIN_PASS=... python3 bin/pilot-responsive.py
خروجی: اسکرین‌شات‌ها در pilot-screenshots/ + خطوط PASS/FAIL + JSON summary؛ exit≠0 در شکست.

Viewportها: 390×844 (موبایل) / 360×800 (موبایل کوچک) / 768×1024 (تبلت عمودی) /
1024×768 (تبلت افقی) / 1366×768 (لپ‌تاپ) / 1440×900 (دسکتاپ)
معیار: بدون Overflow افقی (scrollWidth ≤ innerWidth+1) + رندر موفق (بدون HTTP 5xx) +
بدون خطای Console (type=error) + بدون Uncaught Exception + بدون Request شکست‌خورده.
Payload فرانت (JS/CSS bytes + تعداد منابع) به‌صورت INFO گزارش می‌شود (NFR-PERF-2/3).

------------------------------------------------------------------------------
Phase 8 Slice 1 — پشتیبانیِ حداقلی از «سطح عمومی رزرو» (test-infrastructure)
------------------------------------------------------------------------------
هارنسِ پیشین فقط صفحاتِ wp-admin را می‌دید (هر چهار ورودی PAGES پیش از goto
Login می‌کردند)، پس ساختاراً نمی‌توانست به یک صفحهٔ shortcode عمومیِ آنونیم
برسد. تنها تغییرِ این فایل افزودنِ یک ورودیِ **آنونیمِ** اختیاری است که با
متغیرِ محیطی `RESP_PUBLIC_PAGE` فعال می‌شود:

    RESP_PUBLIC_PAGE="<url>|<clinic_id>|<clinician_id>"

وقتی این متغیر تنظیم **نباشد**، رفتار فایل دقیقاً همان رفتارِ پیشین است
(همان چهار صفحه، همان Login، همان معیارها) — یعنی هیچ ریسکی برای گیتِ موجود
ساخته نمی‌شود. وقتی تنظیم باشد، یک صفحهٔ عمومی به همان شش Viewport اضافه
می‌شود و علاوه بر معیارهای عمومیِ بالا، قراردادِ Phase 8 Slice 1 هم روی
مرورگرِ واقعی بررسی می‌شود: رندرِ آنونیم بدون redirect به Login، پیوندِ صریحِ
Clinic، RTL/fa، موفقیتِ A1 موجود، انتخابِ نوبت ⇒ A4 موجود، گذارِ وضعیتِ
قابل‌مشاهده، نبودِ PHI در زیردرختِ سطح، و بارِ شرطیِ assetها.

دو نکتهٔ دامنه‌ای (عمدی و مستند):
  * برای صفحهٔ عمومی، انتسابِ شکستِ شبکه/5xx/خطای Console به منابعِ
    **same-origin** محدود است و منابعِ cross-origin به‌صورت INFO گزارش
    می‌شوند. دلیل: قراردادِ این سطح، assetهای محلیِ خودش و A1/A4 روی همان
    origin است؛ font/منبعِ شخصِ ثالثِ theme نه در کنترلِ این افزونه است و نه
    در دامنهٔ این گیت. سخت‌گیریِ same-origin کامل باقی است.
  * بررسیِ «صفحاتِ بی‌ربط assetهای این سطح را بار نمی‌کنند» یک‌بار (نه به
    ازای هر Viewport) اجرا می‌شود تا هزینهٔ گیت چندبرابر نشود.
"""

import json
import os
import re
import sys
import time
from urllib.parse import parse_qs, urlparse

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

# ---------------------------------------------------------------------------
# Phase 8 Slice 1 — صفحهٔ عمومیِ اختیاری (بدون RESP_PUBLIC_PAGE ⇒ غیرفعال)
# ---------------------------------------------------------------------------
SURFACE = ".cpms-public-booking"
PANEL = ".cpms-public-booking__panel"
CLINICIAN = ".cpms-public-booking__clinician"
SLOT = ".cpms-public-booking__slot"
ASSET_MARK = "cpms-public-booking."

PUBLIC_PAGE = None
_raw_public = os.environ.get("RESP_PUBLIC_PAGE", "").strip()
if _raw_public:
    _parts = _raw_public.split("|")
    PUBLIC_PAGE = {
        "url": _parts[0].strip(),
        "clinic_id": _parts[1].strip() if len(_parts) > 1 else "",
        "clinician_id": _parts[2].strip() if len(_parts) > 2 else "",
    }
    if not PUBLIC_PAGE["url"]:
        raise SystemExit("RESP_PUBLIC_PAGE must carry a page URL")
    PAGES = PAGES + [("anonymous", PUBLIC_PAGE["url"], "سطح عمومی رزرو (shortcode)")]

# قراردادِ FR-3.5 / Slice 1 + Slice 2: این رشته‌ها هرگز نباید در زیردرختِ
# سطح عمومی باشند.
#
# Phase 8 Slice 2 (تصمیم مالک): سطح عمومی اکنون «ادامهٔ احراز OTP» را دارد —
# markerهای `data-role="otp-mobile"`/`otp-code` و مسیرهای A2/A3 به‌صورت
# slash-escape در قرارداد runtime منتشر می‌شوند، پس:
#  - توکنِ عمومیِ «mobile» به «پیشوندِ خانوادهٔ شماره‌های تستِ seed» تغییر
#    کرد: هیچ شمارهٔ بیمارِ seedشده (PHI واقعی) نباید در سطح ظاهر شود؛
#    واژهٔ عامِ mobile دیگر معیار PHI نیست چون نامِ نقشِ UI است.
#  - مسیرهای /otp/* و hold/confirm به‌صورت literal در outerHTML ظاهر
#    نمی‌شوند (JSON با \/ escape منتشر می‌شود) — گارد همچنان معنادار است.
PUBLIC_FORBIDDEN = [
    "patient_id", "mrn", "0912000", "national_id", "first_name", "last_name",
    "/booking/hold", "/booking/confirm", "/booking/resume", "/appointments/mine",
    "/otp/request", "/otp/verify", "wp-login.php",
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


def _state(page):
    return page.locator(PANEL).first.get_attribute("data-state")


def _wait_not_loading(page):
    page.wait_for_function(
        f'document.querySelector("{PANEL}").getAttribute("data-state") !== "loading"',
        timeout=20000,
    )


def a1_url_qmarks(url):
    """تعدادِ `?` در URL — بیش از یکی یعنی query داخلِ مقدارِ param نشت کرده."""
    return url.count("?")


def wp_route_and_params(url):
    """route/param یک URL را دقیقاً همان‌طور که وردپرس حل می‌کند برمی‌گرداند.

    `rest_api_loaded()` route را از query var عمومی `rest_route` می‌گیرد (حالتِ
    Plain permalinks) و `WP_REST_Server::serve_request()` بقیهٔ `$_GET` را با
    `set_query_params()` به Request می‌دهد؛ در Permalink زیبا route از مسیرِ
    `/wp-json/...` می‌آید. این تابع همان قاعده است تا contractِ URL به‌جای
    شکلِ رشته سنجیده شود.
    """
    parsed = urlparse(url)
    flat = {k: v[0] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
    route = flat.get("rest_route")
    if route is None:
        m = re.search(r"/wp-json(/.*)?$", parsed.path)
        route = m.group(1) if (m and m.group(1)) else parsed.path
    return (route.rstrip("/") or "/"), flat


def check_public_surface(page, cfg):
    """قراردادِ Phase 8 Slice 1 روی مرورگرِ واقعی — آنونیم، بدون Login."""
    if "wp-login.php" in page.url:
        raise RuntimeError(f"anonymous visitor was redirected to Login: {page.url}")

    root = page.locator(SURFACE).first
    if page.locator(SURFACE).count() == 0:
        raise RuntimeError("the public booking surface root did not render at all")

    if root.get_attribute("dir") != "rtl":
        raise RuntimeError("surface root is not dir=rtl")
    if root.get_attribute("lang") != "fa":
        raise RuntimeError("surface root is not lang=fa")

    bound = root.get_attribute("data-clinic-id") or ""
    if cfg["clinic_id"] and str(bound) != str(cfg["clinic_id"]):
        raise RuntimeError(
            f"surface is bound to clinic_id={bound!r} but the shortcode configured "
            f"{cfg['clinic_id']!r} (implicit fallback / mis-binding)"
        )

    # لیست پزشکان server-rendered است — پیش از هر درخواستی باید حاضر باشد.
    clinicians = page.locator(CLINICIAN)
    if clinicians.count() < 1:
        raise RuntimeError("no clinician was server-rendered on the public surface")

    # assetهای اختصاصیِ سطح باید واقعاً بار شده باشند (same-origin).
    loaded = page.evaluate(
        "performance.getEntriesByType('resource').map(function (r) { return r.name; })"
    )
    css_loaded = any(ASSET_MARK + "css" in n for n in loaded)
    js_loaded = any(ASSET_MARK + "js" in n for n in loaded)
    if not css_loaded or not js_loaded:
        raise RuntimeError(f"surface assets not loaded (css={css_loaded}, js={js_loaded})")

    # ---- گام ۱: انتخاب پزشک ⇒ A1 موجود ----
    with page.expect_response(lambda r: "/clinic/v1/availability" in r.url, timeout=25000) as a1_info:
        clinicians.first.click()
    a1 = a1_info.value
    if a1.status != 200:
        raise RuntimeError(f"A1 GET /clinic/v1/availability returned HTTP {a1.status}")
    # contractِ URLِ A1 — هم در Pretty و هم در Plain permalinks باید دقیقاً به
    # route موجودِ `/clinic/v1/availability` برسد و `clinician_id` یک پارامترِ
    # query واقعی باشد (نه بخشی از مقدارِ `rest_route`).
    a1_route, a1_params = wp_route_and_params(a1.url)
    if a1_url_qmarks(a1.url) != 1:
        raise RuntimeError(f"A1 URL must carry exactly one '?': {a1.url}")
    if a1_route != "/clinic/v1/availability":
        raise RuntimeError(f"A1 URL does not route to the existing A1 route: route={a1_route!r} url={a1.url}")
    if cfg["clinician_id"] and a1_params.get("clinician_id") != str(cfg["clinician_id"]):
        raise RuntimeError(
            f"A1 clinician_id is not a real query parameter: got {a1_params.get('clinician_id')!r}, "
            f"expected {cfg['clinician_id']!r} (url={a1.url})"
        )
    _wait_not_loading(page)

    state = _state(page)
    if state not in ("selectable", "empty"):
        raise RuntimeError(f"unexpected panel state after A1: {state!r}")

    day_labels = page.evaluate(
        "Array.from(document.querySelectorAll('.cpms-public-booking__day-label'))"
        ".map(function (n) { return n.textContent.trim(); })"
    )
    slot_times = page.evaluate(
        "Array.from(document.querySelectorAll('.cpms-public-booking__slot-time'))"
        ".map(function (n) { return n.textContent.trim(); })"
    )

    detail = {
        "a1_status": a1.status,
        "a1_url": a1.url,
        "a1_route": a1_route,
        "a1_clinician_id": a1_params.get("clinician_id"),
        "state_after_a1": state,
        "days": len(day_labels),
        "jalali_labels": day_labels[:4],
        "slot_count": len(slot_times),
        "slot_times": slot_times[:6],
    }

    # ---- گام ۲: انتخاب نوبت ⇒ A4 موجود + گذارِ وضعیت ----
    if state == "selectable":
        slots = page.locator(SLOT)
        if slots.count() < 1:
            raise RuntimeError("selectable state but no slot button rendered")
        with page.expect_response(lambda r: "/clinic/v1/booking/quote" in r.url, timeout=25000) as a4_info:
            slots.first.click()
        a4 = a4_info.value
        if a4.status not in (200, 404, 409):
            raise RuntimeError(f"A4 POST /clinic/v1/booking/quote returned unexpected HTTP {a4.status}")
        _wait_not_loading(page)

        final = _state(page)
        if final not in ("bookable", "policy_rejected", "unavailable", "error"):
            raise RuntimeError(f"unexpected panel state after A4: {final!r}")
        a4_route, _a4_params = wp_route_and_params(a4.url)
        if a4_route != "/clinic/v1/booking/quote":
            raise RuntimeError(f"A4 URL does not route to the existing A4 route: route={a4_route!r} url={a4.url}")
        detail.update({"a4_status": a4.status, "a4_url": a4.url, "a4_route": a4_route,
                       "state_after_a4": final})
    else:
        detail.update({"a4_status": None, "state_after_a4": None})

    # ---- نبودِ PHI / نبودِ affordance خارج از دامنه در زیردرختِ سطح ----
    subtree = page.evaluate(
        "(function () { var n = document.querySelector('" + SURFACE + "');"
        " return n ? n.outerHTML : ''; })()"
    )
    if not subtree:
        raise RuntimeError("could not read the surface subtree for the PHI/affordance check")
    leaked = [token for token in PUBLIC_FORBIDDEN if token in subtree]
    if leaked:
        raise RuntimeError(f"public surface subtree leaks out-of-scope tokens: {leaked}")

    return detail


def check_assets_are_conditional(browser):
    """صفحهٔ بی‌ربطِ فرانت‌اند هرگز assetهای این سطح را بار نمی‌کند (یک‌بار)."""
    ctx = browser.new_context(viewport={"width": 1366, "height": 768})
    page = ctx.new_page()
    key = "anonymous-conditional-assets"
    title = "assetهای سطح عمومی فقط روی صفحهٔ خودِ سطح بار می‌شوند"
    try:
        page.goto(f"{BASE}/", wait_until="networkidle")
        time.sleep(0.5)
        loaded = page.evaluate(
            "performance.getEntriesByType('resource').map(function (r) { return r.name; })"
        )
        offenders = [n for n in loaded if ASSET_MARK in n]
        if offenders:
            raise RuntimeError(f"an unrelated frontend page loaded surface assets: {offenders}")
        if page.locator(SURFACE).count() != 0:
            raise RuntimeError("an unrelated frontend page rendered the surface")
        results.append({"key": key, "title": title, "status": "PASS",
                        "resources": len(loaded)})
        print(f"PASS {key} — {title} (resources={len(loaded)}, surface assets=0)")
    except Exception as e:  # noqa: BLE001
        failures.append(key)
        results.append({"key": key, "title": title, "status": "FAIL", "detail": str(e)})
        print(f"FAIL {key} — {title} — {e}")
    finally:
        ctx.close()


with sync_playwright() as p:
    browser = p.chromium.launch()
    for user, url, title in PAGES:
        is_public = user == "anonymous"
        if is_public:
            username = password = None
        else:
            username = ADMIN_USER if user == "secretary" else DOCTOR_USER
            password = ADMIN_PASS if user == "secretary" else DOCTOR_PASS
        for vp_name, width, height in VIEWPORTS:
            ctx = browser.new_context(viewport={"width": width, "height": height})
            page = ctx.new_page()
            key = f"{user}-{vp_name}"
            issues = {"pageerrors": [], "console_errors": [], "request_failed": [], "http5xx": []}
            cross_origin_info = []

            def _skip_benign(url: str) -> bool:
                return any(s in url for s in ("favicon", ".map")) or url.endswith(".map")

            def _in_scope(url: str) -> bool:
                # برای صفحاتِ مدیریتیِ موجود: همان سخت‌گیریِ پیشین (همهٔ منابع).
                # برای صفحهٔ عمومیِ جدید: فقط منابعِ same-origin (منابعِ شخصِ
                # ثالثِ theme به‌صورت INFO گزارش می‌شوند، نه شکست).
                if not is_public:
                    return True
                return str(url).startswith(BASE)

            def _console_signal(m) -> None:
                if m.type != "error":
                    return
                text = m.text or ""
                # شکست‌های سطح شبکه جداگانه (requestfailed/response) رصد می‌شوند؛
                # این متنِ عمومیِ مرورگر فقط نویز است (مثل favicon 404).
                if text.startswith("Failed to load resource") or "favicon" in text:
                    return
                if _in_scope(text):
                    issues["console_errors"].append(text)
                else:
                    cross_origin_info.append(f"console.error: {text}")

            page.on("pageerror", lambda e: issues["pageerrors"].append(str(e)))
            page.on("console", _console_signal)
            page.on("requestfailed", lambda r: None if _skip_benign(r.url) or not _in_scope(r.url)
                    else issues["request_failed"].append(r.url))
            page.on("response", lambda r: None if r.status < 500 or _skip_benign(r.url) or not _in_scope(r.url)
                    else issues["http5xx"].append(f"{r.status} {r.url}"))
            try:
                if not is_public:
                    login(page, username, password)
                target = url if str(url).startswith("http") else f"{BASE}{url}"
                page.goto(target, wait_until="networkidle")
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

                public_detail = None
                if is_public and not err:
                    # قراردادِ Phase 8 Slice 1 روی مرورگر واقعی (A1 ⇒ انتخاب ⇒ A4).
                    public_detail = check_public_surface(page, PUBLIC_PAGE)
                    sw2 = page.evaluate("document.documentElement.scrollWidth")
                    iw2 = page.evaluate("window.innerWidth")
                    if sw2 > iw2 + 1:
                        err.append(
                            f"horizontal overflow AFTER interaction: scrollWidth={sw2} > innerWidth={iw2}"
                        )
                    public_detail["scrollWidth_after"] = sw2
                    public_detail["innerWidth_after"] = iw2
                    page.screenshot(path=f"{OUT}/{key}-after.png", full_page=False)

                if err:
                    raise RuntimeError("; ".join(err))
                entry = {"key": key, "title": title, "status": "PASS",
                         "scrollWidth": sw, "innerWidth": iw, "screenshot": shot,
                         "payload": perf}
                if public_detail:
                    entry["public_surface"] = public_detail
                if cross_origin_info:
                    entry["cross_origin_info"] = cross_origin_info
                results.append(entry)
                line = (f"PASS {key} — {title} (sw={sw}, iw={iw} | res={perf['count']} "
                        f"js={perf['jsCount']}({perf['jsBytes']}B) css={perf['cssCount']}({perf['cssBytes']}B) "
                        f"total={perf['totalBytes']}B)")
                if public_detail:
                    line += (f" | A1={public_detail['a1_status']} days={public_detail['days']} "
                             f"slots={public_detail['slot_count']} "
                             f"state={public_detail['state_after_a1']}->{public_detail['state_after_a4']} "
                             f"A4={public_detail['a4_status']}")
                print(line)
                if public_detail:
                    print(f"PASS {key}-a1-url — permalink-safe A1/A4 URL contract: "
                          f"a1_route={public_detail.get('a1_route')} "
                          f"clinician_id={public_detail.get('a1_clinician_id')} "
                          f"a4_route={public_detail.get('a4_route')} "
                          f"a1_url={public_detail.get('a1_url')} "
                          f"a4_url={public_detail.get('a4_url')}")
                if cross_origin_info:
                    print(f"INFO {key} — cross-origin (خارج از دامنهٔ این گیت): {cross_origin_info[:3]}")
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

    if PUBLIC_PAGE:
        check_assets_are_conditional(browser)

    browser.close()

with open("pilot-responsive-results.json", "w", encoding="utf-8") as f:
    json.dump({"ok": not failures, "results": results}, f, ensure_ascii=False, indent=1)
print(json.dumps({"ok": not failures, "count": len(results), "failed": failures}, ensure_ascii=False))
sys.exit(1 if failures else 0)

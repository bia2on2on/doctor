#!/usr/bin/env python3
"""rwp-shell-proof.py — اثبات فنی Technical Proof: پوستهٔ مستقلِ CPMS (Phase 9 Slice 3).

NON-PRODUCTION / فقط proof — نه پیاده‌سازی Patient Portal. فیکسچرهای زیر باید
قبل از اجرا نصب شده باشند (workflow آن‌ها را کپی می‌کند — هرگز داخل release ZIP):
  - mu-plugins/cpms-standalone-shell-proof.php{,-template.php}   (رهگیرِ template_include)
  - themes/cpms-proof-theme-alpha, themes/cpms-proof-theme-beta   (دو تمِ کنترلیِ متفاوت)
  - صفحهٔ منتشرشدهٔ `cpms-shell-proof` و کاربرِ `SHELLPROOF_USER` (role cpms_patient)

ماتریس اجرا (با `wp` CLI بین سناریوها سوئیچ می‌شود):
  permalink ∈ {Plain, Pretty}  ×  active theme ∈ {alpha, beta}

در هر خانه:
  * positive-control صفحهٔ معمولی/خانه → نشانگرهای تمِ فعال دیده می‌شوند (vacuous نبودنِ ادعاها)
  * پوستهٔ proof (anonim) → نشانگر CPMS کل سند را می‌سازد؛ نشانگرهای هر دو تم و
    chromeِ wp-admin غایب‌اند؛ کاربر = anonymous؛ حالتِ permalink از خودِ rest_root
    و شکلِ URL استنباط و assert می‌شود (self-authenticating).
  * پوستهٔ proof (بیمارِ واردشده با wp-login.php) → نشانِ همان کاربرِ وردپرس +
    nonce `wp_rest` + فراخوانیِ REST موجودِ `wp/v2/users/me` با همان کوکی/نشست
    (۲۰۰ + همان هویت) و کنترلِ منفیِ بدون nonce (۴۰۱) — یعنی نشست «WordPress-backed» است.
  * logout واقعی (لینکِ wp_logout_url) → پوسته دوباره anonymous.
در پایان: قراردادِ پوسته (fingerprint) باید در هر ۴ خانه یکسان باشد (ثابتِ G/H).

اجرا (Workflow — Real WordPress Acceptance):
  BASE=http://localhost:8080 WP_DIR=/home/runner/rwp OUT=/tmp/acc \\
  SHELLPROOF_USER=... SHELLPROOF_PASS=... python3 bin/rwp-shell-proof.py

خروجی: screenshots + html dumps + shell-proof-results.json در OUT؛ exit≠0 در هر شکست.
قرارداد ایزولاسیونِ کانتکست (همان acceptance-isolation-check.py): هر persona
کانتکست/کوکیِ مستقلِ خودش را دارد؛ این ابزار مستقیماً کوکی دستکاری نمی‌کند.
"""

import json
import os
import re
import subprocess
import sys

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8080").rstrip("/")
WP_DIR = os.environ.get("WP_DIR", "")
OUT = os.environ.get("OUT", "rwp-shell-proof-out")
SHELLPROOF_USER = os.environ.get("SHELLPROOF_USER", "")
SHELLPROOF_PASS = os.environ.get("SHELLPROOF_PASS", "")

PAGE_SLUG = "cpms-shell-proof"
PAGE_TITLE_HINT = "cpms-shell-proof"

# قواعدِ استانداردِ WordPress (معادلِ Save Permalinks) — همان الگویِ اثبات‌شدهٔ
# pilot-gate: `wp rewrite flush --hard` گاهی .htaccess نمی‌نویسد (درسِ گیتِ سوم)،
# پس در حالتِ Pretty این بلاک مستقیم نوشته می‌شود (AllowOverride All + mod_rewrite
# در vhostِ همین job از قبل فعال‌اند).
HTACCESS_BLOCK = """# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
"""

# دو تمِ کنترلیِ «مادتاً متفاوت» + نشانگرهایشان (assertion از جنس grep نیست —
# positive-control در همان خانه، رندرِ واقعیِ نشانگرها را اثبات می‌کند).
FIXTURE_THEMES = ("cpms-proof-theme-alpha", "cpms-proof-theme-beta")
THEME_MARKERS = {
    "cpms-proof-theme-alpha": ("CPMS-PROOF-THEME-ALPHA-HEADER", "CPMS-PROOF-THEME-ALPHA-FOOTER"),
    "cpms-proof-theme-beta": ("CPMS-PROOF-THEME-BETA-HEADER", "CPMS-PROOF-THEME-BETA-FOOTER"),
}
ALL_THEME_MARKERS = tuple(m for pair in THEME_MARKERS.values() for m in pair)

# chromeِ wp-admin — هیچ‌کدام نباید در سندِ پوسته باشند.
ADMIN_CHROME_MARKERS = ('id="wpadminbar"', "wp-admin-bar-", 'id="adminmenu"', 'id="wpfooter"')

results = []
page_errors = []  # (tag, message, url) — همه نگه داشته می‌شوند؛ گیت فقط روی صفحاتِ shell


def check(name, ok, detail=""):
    results.append((name, bool(ok), str(detail)[:1200]))
    print(("PASS " if ok else "FAIL ") + name + (" — " + str(detail)[:1200] if detail else ""), flush=True)


def wp(*args, tolerate_failure=False):
    """فرخوانیِ wp-cli (همان محیطِ نصب‌شدهٔ Acceptance) — شکست = RuntimeError."""
    if not WP_DIR:
        raise RuntimeError("WP_DIR تنظیم نشده است")
    proc = subprocess.run(
        ["wp", *args, "--path=" + WP_DIR, "--allow-root"],
        capture_output=True, text=True, timeout=120,
    )
    if proc.returncode != 0:
        if tolerate_failure:
            return ""
        raise RuntimeError("wp " + " ".join(args) + f" failed rc={proc.returncode}: {proc.stderr[:400]}")
    return (proc.stdout or "").strip()


def is_shell_url(url):
    """صفحاتِ ادعایِ این proof (پوستهٔ standalone) — همان انضباطِ `is_cpms_url` در
    rwp-acceptance: خطا/نویزِ صفحاتِ وردپرسِ میزبان (wp-login/wp-admin landing)
    خارج از قراردادِ پوسته است؛ همه خطاها log می‌شوند ولی فقط خطای صفحاتِ shell
    گیتِ `no_page_errors_on_shell_pages` را قرمز می‌کند (کلاسِ چک مطابقِ ادعا)."""
    u = url or ""
    return ("wp-login.php" not in u) and ("page_id=" in u or PAGE_SLUG in u)


def htaccess_path():
    return WP_DIR.rstrip("/") + "/.htaccess"


def read_htaccess_backup():
    try:
        with open(htaccess_path(), "r") as f:
            return f.read()
    except OSError:
        return None


def set_htaccess_rules(backup):
    """Pretty: بلاکِ استانداردِ WP را مستقیم بنویس (Save Permalinks معادل).
    بازگشت: وضعیتِ اولیهٔ فایل (محتوا یا نبودِ فایل) عیناً بازسازی می‌شود."""
    if backup is None:
        subprocess.run(["sudo", "rm", "-f", htaccess_path()], capture_output=True, text=True)
        return
    proc = subprocess.run(["sudo", "tee", htaccess_path()], input=backup, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError("htaccess restore failed: " + proc.stderr[:300])


def set_htaccess_pretty():
    proc = subprocess.run(["sudo", "tee", htaccess_path()], input=HTACCESS_BLOCK, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError("htaccess write failed: " + proc.stderr[:300])


def new_persona_context(browser, width=1280, height=900):
    """کانتکستِ مستقلِ هر persona (factory تأییدشده — acceptance-isolation-check)."""
    return browser.new_context(viewport={"width": width, "height": height}, locale="fa-IR")


def login(page, user, password, tag):
    """ورودِ واقعی از wp-login.php (نشستِ وردپرس — نه state محلی).

    شناسه‌های فیلد دقیقاً همان الگویِ `rwp-acceptance.py` است: `#user_login` +
    `#user_pass` + `#wp-submit` (درسِ گیتِ دوم: `#user_password` وجود خارجی ندارد —
    خطای کلاس D در خودِ probe، نه محصول).
    """
    page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    try:
        page.wait_for_url(
            lambda u: "wp-login.php" not in u,
            wait_until="domcontentloaded",
            timeout=20000,
        )
    except PlaywrightTimeoutError:
        pass
    ok = "wp-login.php" not in (page.url or "")
    check(f"login.{tag}", ok, f"final={page.url}")
    return ok


def parse_config(body):
    m = re.search(r'class="cpms-shell-proof__config"[^>]*>(.*?)</script>', body or "", re.S)
    if not m:
        return {}
    try:
        return json.loads(m.group(1))
    except ValueError:
        return {}


def contract_fingerprint(body, doctype_ok):
    """قراردادِ پوسته — presence/absence ثابت (بدون مقادیرِ وابسته به نشست)."""
    body = body or ""
    return {
        "doctype_document_start": bool(doctype_ok) and "</html>" in body,
        "document_end": "</html>" in body,
        "shell_marker_present": "CPMS-STANDALONE-SHELL-PROOF" in body,
        "shell_owned_attr": 'data-cpms-standalone-shell="proof-fixture"' in body,
        "config_published": "cpms-shell-proof__config" in body,
        "theme_alpha_absent": "CPMS-PROOF-THEME-ALPHA" not in body,
        "theme_beta_absent": "CPMS-PROOF-THEME-BETA" not in body,
        "admin_chrome_absent": all(m not in body for m in ADMIN_CHROME_MARKERS),
    }


def assert_fingerprint(name, body, doctype_ok):
    fp = contract_fingerprint(body, doctype_ok)
    for claim, holds in fp.items():
        check(f"{name}.{claim}", holds, "" if holds else "قراردادِ پوسته شکسته شد")
    return fp


def rest_users_me(page, cfg, with_nonce):
    """فراخوانیِ REST موجود از داخلِ همان صفحه (کوکیِ همان نشست)."""
    return page.evaluate(
        """async ({ url, nonce, withNonce }) => {
            const headers = withNonce ? { 'X-WP-Nonce': nonce } : {};
            const resp = await fetch(url, { credentials: 'same-origin', headers });
            let bodyText = '';
            try { bodyText = await resp.text(); } catch (e) { bodyText = ''; }
            return { status: resp.status, body: bodyText };
        }""",
        {"url": cfg.get("users_me_url", ""), "nonce": cfg.get("nonce", ""), "withNonce": with_nonce},
    )


def run_shell_scenario(browser, mode, theme, page_id, expected_uid):
    tag = f"{mode}-{theme}"

    # ---------- positive control: خانه (تم فعال واقعاً رندر می‌شود) ----------
    homectx = new_persona_context(browser)
    home = homectx.new_page()
    home.on("pageerror", lambda e: page_errors.append((f"home-{tag}", str(e), home.url)))
    resp = home.goto(f"{BASE}/", wait_until="domcontentloaded")
    home_body = home.content()
    home.screenshot(path=f"{OUT}/screenshots/shell-proof-home-{tag}.png", full_page=True)
    check(f"shellproof.{tag}.home_http200", resp is not None and resp.status == 200, f"HTTP {resp.status if resp else 0}")
    for marker in THEME_MARKERS[theme]:
        check(f"shellproof.{tag}.home_positive_control.{marker}", marker in home_body,
              "positive control: تمِ فعال باید نشانگر خود را رندر کند")
    check(f"shellproof.{tag}.home_no_fixture_leak",
          "CPMS-STANDALONE-SHELL-PROOF" not in home_body and "data-cpms-standalone-shell" not in home_body,
          "فیکسچرِ پوسته نباید روی صفحات غیرمرتبط اثری بگذارد")
    homectx.close()

    # ---------- URL را از خودِ وردپرس بگیر (شکلِ URL = شاهدِ self-authenticating) ----------
    if mode == "plain":
        shell_url = f"{BASE}/?page_id={page_id}"
    else:
        shell_url = f"{BASE}/{PAGE_SLUG}/"

    # ---------- پوسته — anonymous (کانتکستِ مستقلِ anon) ----------
    anonctx = new_persona_context(browser)
    anon = anonctx.new_page()
    anon.on("pageerror", lambda e: page_errors.append((f"anon-{tag}", str(e), anon.url)))
    resp = anon.goto(shell_url, wait_until="domcontentloaded")
    anon.wait_for_timeout(300)
    body = anon.content()
    doctype_ok = anon.evaluate("() => !!(document.doctype && document.doctype.name === 'html')")
    anon.screenshot(path=f"{OUT}/screenshots/shell-proof-{tag}-anon.png", full_page=True)
    with open(f"{OUT}/logs/shell-proof-{tag}-anon.html", "w") as f:
        f.write(body or "")
    check(f"shellproof.{tag}.http200", resp is not None and resp.status == 200, f"HTTP {resp.status if resp else 0} @ {anon.url}")
    check(f"shellproof.{tag}.no_login_redirect", "wp-login.php" not in (anon.url or ""), f"final={anon.url}")
    anon_fp = assert_fingerprint(f"shellproof.{tag}.anon", body, doctype_ok)
    check(f"shellproof.{tag}.user_anonymous", "CPMS-SHELL-PROOF-USER:anonymous" in body,
          "بدون نشستِ وردپرس = anonymous")

    cfg = parse_config(body)
    rest_root = str(cfg.get("rest_root", ""))
    users_me_url = str(cfg.get("users_me_url", ""))
    # استنباطِ حالتِ permalink از خودِ URLهای منتشرشده (self-authenticating) —
    # شکلِ `?rest_route=` فقط در Plain ساخته می‌شود؛ `/wp-json/` فقط در Pretty.
    plain_shape = ("rest_route=" in users_me_url) or ("rest_route=" in rest_root)
    if mode == "plain":
        check(f"shellproof.{tag}.plain_permalink_self_authenticated",
              bool(users_me_url) and plain_shape and "page_id=" in (anon.url or ""),
              f"users_me_url={users_me_url} rest_root={rest_root} url={anon.url}")
    else:
        # سخت‌گیرانه: پیکربندی باید واقعاً منتشر شده باشد (خالی ≠ PASS) و شکلِ
        # `/wp-json/` را تأیید کند — کنترلِ منفی در برابرِ PASS کاذب روی صفحهٔ ۴۰۴.
        check(f"shellproof.{tag}.pretty_permalink_self_authenticated",
              bool(users_me_url) and (not plain_shape) and "/wp-json" in users_me_url
              and PAGE_SLUG in (anon.url or ""),
              f"users_me_url={users_me_url} rest_root={rest_root} url={anon.url}")
    check(f"shellproof.{tag}.cpms_runtime_loaded", "CPMS-SHELL-PROOF-RUNTIME-LOADED" in body,
          "runtime افزونهٔ CPMS باید روی همین درخواستِ standalone حاضر باشد")
    anonctx.close()

    # ---------- پوسته — بیمارِ واردشده با نشستِ واقعیِ وردپرس ----------
    patctx = new_persona_context(browser)
    pat = patctx.new_page()
    pat.on("pageerror", lambda e: page_errors.append((f"pat-{tag}", str(e), pat.url)))
    if not login(pat, SHELLPROOF_USER, SHELLPROOF_PASS, "shellproof"):
        patctx.close()
        return anon_fp

    pat.goto(shell_url, wait_until="domcontentloaded")
    pat.wait_for_timeout(300)
    body = pat.content()
    doctype_ok = pat.evaluate("() => !!(document.doctype && document.doctype.name === 'html')")
    pat.screenshot(path=f"{OUT}/screenshots/shell-proof-{tag}-auth.png", full_page=True)
    with open(f"{OUT}/logs/shell-proof-{tag}-auth.html", "w") as f:
        f.write(body or "")
    auth_fp = assert_fingerprint(f"shellproof.{tag}.auth", body, doctype_ok)
    check(f"shellproof.{tag}.wp_session_user_visible", f"CPMS-SHELL-PROOF-USER:{SHELLPROOF_USER}" in body,
          "نشستِ وردپرس باید روی درخواستِ پوسته در دسترس باشد")
    cfg = parse_config(body)
    check(f"shellproof.{tag}.wp_rest_nonce_published", bool(re.fullmatch(r"[0-9a-f]+", str(cfg.get("nonce", "")))),
          f"nonce={cfg.get('nonce', '')}")
    check(f"shellproof.{tag}.logout_url_published", "action=logout" in str(cfg.get("logout_url", "")),
          "wp_logout_url() باید مثل هر پوستهٔ وردپرسی در دسترس باشد")

    # REST موجود (wp/v2/users/me) — کوکیِ همان نشست + همان nonce = «WordPress-backed».
    ok_resp = rest_users_me(pat, cfg, True)
    check(f"shellproof.{tag}.rest_users_me_with_nonce_200", ok_resp.get("status") == 200,
          f"status={ok_resp.get('status')} body={str(ok_resp.get('body', ''))[:200]}")
    try:
        me = json.loads(ok_resp.get("body") or "{}")
    except ValueError:
        me = {}
    check(f"shellproof.{tag}.rest_users_me_same_identity", int(me.get("id") or 0) == int(expected_uid),
          f"id={me.get('id')} expected={expected_uid}")
    bad_resp = rest_users_me(pat, cfg, False)
    check(f"shellproof.{tag}.rest_users_me_without_nonce_401", bad_resp.get("status") == 401,
          f"status={bad_resp.get('status')} — بدون nonce باید ۴۰۱ شود (کنترلِ منفی)")

    # logout واقعی (همان لینکِ wp_logout_url — نه دستکاریِ کوکی). nonce معتبرِ
    # خودِ لینک مستقیماً logout می‌کند؛ اگر صفحهٔ تأیید آمد، همان لینک را کلیک کن.
    logout_url = str(cfg.get("logout_url", ""))
    if logout_url:
        pat.goto(logout_url, wait_until="domcontentloaded")
        retry = pat.query_selector('a[href*="action=logout"]')
        if retry is not None:
            retry.click()
            pat.wait_for_load_state("domcontentloaded")
    pat.goto(shell_url, wait_until="domcontentloaded")
    body = pat.content()
    check(f"shellproof.{tag}.real_logout_shows_anonymous", "CPMS-SHELL-PROOF-USER:anonymous" in body,
          f"after logout final={pat.url}")
    patctx.close()

    # قراردادِ پوسته زیر login/logout هم ثابت است.
    check(f"shellproof.{tag}.contract_stable_across_session", auth_fp == anon_fp,
          f"anon={anon_fp} auth={auth_fp}")
    return anon_fp


def main():
    if not SHELLPROOF_USER or not SHELLPROOF_PASS:
        check("shellproof.fixture_persona", False, "SHELLPROOF_USER/SHELLPROOF_PASS تنظیم نشده‌اند")
        return finish()
    os.makedirs(f"{OUT}/screenshots", exist_ok=True)
    os.makedirs(f"{OUT}/logs", exist_ok=True)

    original_theme = ""
    original_structure = ""
    htaccess_backup = "UNSET"
    try:
        active = wp("theme", "list", "--status=active", "--field=name").splitlines()
        original_theme = active[0].strip() if active else ""
        # گزینهٔ `permalink_structure` ممکن است مقدارِ خالی داشته باشد (Plain) — tolerate.
        original_structure = wp("option", "get", "permalink_structure", tolerate_failure=True)
        htaccess_backup = read_htaccess_backup()
        page_ids = wp("post", "list", "--post_type=page", f"--name={PAGE_SLUG}", "--field=ID").split()
        if page_ids:
            page_id = page_ids[0]
        else:
            page_id = wp("post", "create", "--post_type=page", "--post_title=CPMS Standalone Shell Proof",
                         f"--post_name={PAGE_SLUG}", "--post_status=publish", "--porcelain")
        expected_uid = wp("user", "get", SHELLPROOF_USER, "--field=ID")
        check("shellproof.fixture_ready", bool(page_id and expected_uid),
              f"page_id={page_id} user_id={expected_uid} original_theme={original_theme}")

        with sync_playwright() as p:
            browser = p.chromium.launch()
            fingerprints = {}
            for mode, structure in (("plain", ""), ("pretty", "/%postname%/")):
                wp("rewrite", "structure", structure)
                wp("rewrite", "flush", "--hard")
                if mode == "pretty":
                    # `--hard` قابل‌اتکا نیست — قواعدِ استانداردِ WP مستقیم (الگوی pilot-gate).
                    set_htaccess_pretty()
                else:
                    set_htaccess_rules(htaccess_backup)
                structure_now = wp("option", "get", "permalink_structure", tolerate_failure=True)
                check(f"shellproof.{mode}.permalink_structure_expected", structure_now == structure,
                      f"structure={structure_now!r} expected={structure!r}")
                for theme in FIXTURE_THEMES:
                    wp("theme", "activate", theme)
                    fingerprints[(mode, theme)] = run_shell_scenario(browser, mode, theme, page_id, expected_uid)
            browser.close()

        # G + H: قراردادِ پوسته در هر ۴ خانه (Plain/Pretty × alpha/beta) یکسان است.
        distinct = {json.dumps(fp, sort_keys=True) for fp in fingerprints.values()}
        check("shellproof.contract_equal_across_modes_and_themes", len(distinct) == 1,
              f"{len(distinct)} fingerprint(های) متفاوت از {len(fingerprints)} خانه")
    except Exception as e:  # noqa: BLE001 — هر خطای پوشش‌داده‌نشده = FAILِ صادق (نه crash بی‌صدا)
        check("shellproof.unhandled_error", False, repr(e))
    finally:
        try:
            if original_theme:
                wp("theme", "activate", original_theme)
            wp("rewrite", "structure", original_structure)
            wp("rewrite", "flush", "--hard")
            if htaccess_backup != "UNSET":
                set_htaccess_rules(htaccess_backup)
        except Exception as e:  # noqa: BLE001 — بازگشت به حالت اولیه نباید شواهد را پنهان کند
            check("shellproof.restore_initial_state", False, str(e))
    return finish()


def finish():
    shell_errs = [e for e in page_errors if is_shell_url(e[2])]
    other_errs = [e for e in page_errors if not is_shell_url(e[2])]
    with open(f"{OUT}/logs/shell-proof-page-errors.log", "w") as f:
        for tag, msg, url in page_errors:
            f.write(f"[{tag}] {msg} <{url}>\n")
    # گیتِ صریحِ قراردادِ پوسته: هیچ خطای JS روی صفحاتِ shell.
    check("shellproof.no_page_errors_on_shell_pages", len(shell_errs) == 0,
          " || ".join(f"[{t}] {m[:200]} <{u[:80]}>" for t, m, u in shell_errs[:4]))
    # اطلاعاتی (غیرگیت — افشا کامل): نویزِ صفحاتِ میزبان (wp-login/wp-admin) — در
    # صورتِ مشاهده با منبعِ NOT RETRIEVED ثبت می‌شود؛ نه نقصِ محصول ادعا می‌شود و نه
    # پنهان می‌ماند (همان حدِّ rwp-acceptance: فقط صفحاتِ CPMS گیت‌اند).
    check("shellproof.non_shell_page_errors_informational", True,
          f"{len(other_errs)} non-shell page error(s): "
          + " || ".join(f"[{t}] {m[:160]} <{u[:80]}>" for t, m, u in other_errs[:4]))
    failed = [r for r in results if not r[1]]
    with open(f"{OUT}/shell-proof-results.json", "w") as f:
        json.dump(
            {
                "checks": [{"name": n, "ok": ok, "detail": d} for n, ok, d in results],
                "failed": len(failed),
                "page_errors_on_shell_pages": len(shell_errs),
                "page_errors_elsewhere_informational": len(other_errs),
            },
            f,
            ensure_ascii=False,
            indent=2,
        )
    print(f"\n== rwp-shell-proof: {len(results) - len(failed)} passed / {len(failed)} failed ==", flush=True)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

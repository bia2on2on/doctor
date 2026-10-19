#!/usr/bin/env python3
"""Install the official ZIP through a real WordPress administrator browser UI.

Disposable Actions site only. No wp-cli plugin install/activate fallback.
"""
import json
import os
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

base = os.environ['WP_URL'].rstrip('/')
out = Path(os.environ.get('OUT', '/tmp/fp'))
out.mkdir(parents=True, exist_ok=True)
results = []


def check(name, ok):
    results.append({'name': name, 'ok': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise RuntimeError(name)


try:
    if base != 'http://localhost:8080' or os.environ['WP_DIR'] != '/home/runner/rwp-fp':
        raise RuntimeError('isolated_actions_site_required')
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, args=['--no-sandbox'])
        context = browser.new_context(locale='fa-IR')
        page = context.new_page()
        page.goto(base + '/wp-login.php')
        page.fill('#user_login', os.environ['ADMIN_USER'])
        page.fill('#user_pass', os.environ['ADMIN_PASS'])
        page.click('#wp-submit')
        page.wait_for_url(lambda u: 'wp-login.php' not in u)
        check('install.administrator_login', '/wp-admin/' in page.url)
        page.goto(base + '/wp-admin/plugin-install.php?tab=upload')
        page.locator('input[name="pluginzip"]').set_input_files(os.environ['ZIP_PATH'])
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.locator('#install-plugin-submit').click()
        activate = page.locator('a[href*="action=activate"][href*="clinic-practice-management"]')
        check('install.official_zip_uploaded', activate.count() == 1)
        page.screenshot(path=str(out / 'zip-uploaded.png'), full_page=True)
        with page.expect_navigation(wait_until='domcontentloaded'):
            activate.click()
        # Real-browser evidence of the automatic first-run wizard open: the
        # one-shot CpmsActivationRedirect fires on the literal post-activation
        # landing (plugins.php?activate=true) and sends THIS SAME navigation
        # straight to the setup wizard -- a dismissible notice alone is not
        # this requirement; the wizard must actually be the page the browser
        # lands on, with zero further clicks.
        check('install.auto_redirect_to_wizard', 'page=cpms-wizard' in page.url)
        page.screenshot(path=str(out / 'zip-activated-auto-wizard.png'), full_page=True)
        page.goto(base + '/wp-admin/plugins.php')
        # WordPress derives data-slug from the human plugin Name for an
        # uploaded ZIP. data-plugin is the authoritative installed basename.
        check('install.browser_activated', page.locator('tr[data-plugin="clinic-practice-management/clinic-practice-management.php"].active').count() == 1)
        page.screenshot(path=str(out / 'zip-active.png'), full_page=True)
        browser.close()
except Exception as exc:
    # Do not print URLs, cookies, request payloads, or exception strings.
    print('INSTALL_EXCEPTION=' + type(exc).__name__, flush=True)
    results.append({'name': 'install.completed_without_exception', 'ok': False})
finally:
    (out / 'browser-install-results.json').write_text(json.dumps(results, indent=2))
sys.exit(0 if results and all(r['ok'] for r in results) else 1)

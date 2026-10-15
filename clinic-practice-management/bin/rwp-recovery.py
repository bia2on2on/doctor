#!/usr/bin/env python3
"""Actual browser recovery acceptance, synthetic fixtures on a disposable Actions DB.

Restores a pristine SQL snapshot before each case; never targets an Owner site.
CLI is used only for fixture construction and persistence assertions. Recovery
itself uses a logged-in browser, session-bound nonce and real admin-post route.
"""
import json
import os
import subprocess
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = os.environ['WP_URL'].rstrip('/')
WP_DIR = os.environ['WP_DIR']
OUT = Path(os.environ.get('OUT', '/tmp/fp')) / 'recovery'
OUT.mkdir(parents=True, exist_ok=True)
results = []


def check(name, ok, detail=''):
    results.append({'name': name, 'ok': bool(ok), 'detail': detail})
    print(('PASS ' if ok else 'FAIL ') + name + (' — ' + detail if detail else ''), flush=True)


def wp(*args, env=None):
    proc = subprocess.run(['wp', *args, '--path=' + WP_DIR, '--allow-root'],
                          capture_output=True, text=True, timeout=60,
                          env={**os.environ, **(env or {})})
    if proc.returncode:
        raise RuntimeError('fixture_cli_failed')
    return proc.stdout


def php(code, env=None):
    path = OUT / 'fixture-eval.php'
    path.write_text('<?php\n' + code)
    text = wp('eval-file', str(path), env=env)
    return json.loads(next(line[7:] for line in text.splitlines() if line.startswith('REC_DB=')))


def fixture(kind):
    return php(r'''
if (DB_NAME !== 'cpms_fp') { throw new RuntimeException('isolated DB required'); }
$db = \ClinicCore\Bootstrap\App::db();
$kind = getenv('REC_KIND');
$a = (int) get_user_by('login', getenv('ADMIN_USER'))->ID;
$b = (int) get_user_by('login', getenv('SECOND_ADMIN_USER'))->ID;
$c = (int) $db->fetchValue('SELECT id FROM ' . $db->table('cpms_clinics') . ' ORDER BY id LIMIT 1');
$writer = $a;
if ($kind === 'deleted') {
    $writer = wp_create_user('deleted_history', 'Synthetic-Only-2026!', 'deleted@acc.local');
    (new WP_User($writer))->set_role('administrator');
}
$keys = $kind === 'legacy' || str_starts_with($kind, 'legacy_')
    ? ['setup.started_at', 'setup.clinic.name', 'setup.clinic.address', 'setup.clinic.phone', 'setup.clinic.timezone']
    : ['setup.started_at', 'setup.current_step'];
$values = ['setup.started_at' => '2026-10-01T00:00:00+00:00', 'setup.current_step' => 'booking',
    'setup.clinic.name' => 'مطب تاریخی', 'setup.clinic.address' => 'نشانی تاریخی',
    'setup.clinic.phone' => '021-11111111', 'setup.clinic.timezone' => 'Asia/Tehran'];
foreach ($keys as $i => $key) {
    $actor = ($kind === 'mixed' || $kind === 'legacy_mixed') && $i === 1 ? $b : $writer;
    \ClinicCore\Bootstrap\App::audit()->log('SETTING_UPDATE', ['wp_user_id' => $actor, 'role' => 'administrator'],
        'setting', null, null, ['setting' => $key, 'value' => ''], ['setting' => $key, 'value' => $values[$key]],
        ['op' => 'config_update', 'clinic_id' => $c], $c);
    $db->wpdb()->insert($db->table('cpms_settings'), ['clinic_id' => $c, 'key' => $key,
        'value_json' => wp_json_encode($values[$key]), 'updated_by_wp_user_id' => $actor, 'updated_at' => $db->nowUtcSql()]);
}
if ($kind === 'deleted') {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($writer);
}
if ($kind === 'membership' || $kind === 'suspended') {
    $id = \ClinicCore\Bootstrap\App::membership_service()->create_membership($c, $b, 'cpms_manager');
    if ($kind === 'suspended') { \ClinicCore\Bootstrap\App::membership_service()->suspend_membership($id); }
}
if ($kind === 'clinical') {
    $db->wpdb()->insert($db->table('cpms_clinicians'), ['clinic_id' => $c, 'wp_user_id' => null,
        'full_name' => 'پزشک تاریخی', 'is_active' => 1, 'created_at' => $db->nowUtcSql(), 'updated_at' => $db->nowUtcSql()]);
}
if ($kind === 'legacy_unknown') {
    $db->wpdb()->insert($db->table('cpms_settings'), ['clinic_id' => $c, 'key' => 'setup.clinic.owner',
        'value_json' => '"unknown"', 'updated_by_wp_user_id' => $a, 'updated_at' => $db->nowUtcSql()]);
}
if ($kind === 'legacy_writer') {
    $db->wpdb()->update($db->table('cpms_settings'), ['updated_by_wp_user_id' => $b], ['clinic_id' => $c, 'key' => 'setup.clinic.name']);
}
$db->query('UPDATE ' . $db->table('cpms_clinics') . ' SET first_setup_claimed_at = CURRENT_TIMESTAMP(3) WHERE id = %d', [$c]);
echo 'REC_DB=' . wp_json_encode(['clinic' => $c, 'actor' => $a]);
''', {'REC_KIND': kind})


def snapshot():
    return php(r'''
$db = \ClinicCore\Bootstrap\App::db();
$out = [];
foreach (['cpms_clinics', 'cpms_clinic_memberships', 'cpms_settings', 'cpms_audit_logs', 'cpms_clinicians'] as $table) {
    $out[$table] = $db->fetchAll('SELECT * FROM ' . $db->table($table) . ' ORDER BY id');
}
echo 'REC_DB=' . wp_json_encode($out);
''')


def login(page, second):
    page.goto(BASE + '/wp-login.php')
    page.fill('#user_login', os.environ['SECOND_ADMIN_USER' if second else 'ADMIN_USER'])
    page.fill('#user_pass', os.environ['SECOND_ADMIN_PASS' if second else 'ADMIN_PASS'])
    page.click('#wp-submit')
    page.wait_for_url(lambda u: 'wp-login.php' not in u)


def submit(page, form):
    with page.expect_navigation(wait_until='domcontentloaded', timeout=20000):
        form.locator('button[type="submit"]').first.click()


def crafted_post(page, context, clinic, second):
    # A nonce generated without the browser's session token would only test
    # CSRF denial, not actor continuity. Bind to the actual logged-in cookie.
    cookie = next(c['value'] for c in context.cookies() if c['name'].startswith('wordpress_logged_in_'))
    nonce = php(r'''
$user = get_user_by('login', getenv('REC_LOGIN'));
wp_set_current_user((int) $user->ID);
// PHP normally URL-decodes incoming cookies before populating $_COOKIE.
$_COOKIE[LOGGED_IN_COOKIE] = urldecode(getenv('REC_COOKIE'));
echo 'REC_DB=' . wp_json_encode(wp_create_nonce('cpms_first_practice_recovery'));
''', {'REC_LOGIN': os.environ['SECOND_ADMIN_USER' if second else 'ADMIN_USER'], 'REC_COOKIE': cookie})
    with page.expect_navigation(wait_until='domcontentloaded', timeout=20000):
        page.evaluate('''([url, nonce, clinic]) => {
            const f = document.createElement('form'); f.method = 'post'; f.action = url;
            for (const [k,v] of Object.entries({action:'cpms_first_practice_recovery', _wpnonce:nonce,
                clinic_id:String(clinic), confirm_recovery:'1', practice_name:'مطب بازیابی آزمایشی'})) {
                const i = document.createElement('input'); i.name=k; i.value=v; f.appendChild(i);
            } document.body.appendChild(f); f.submit();
        }''', [BASE + '/wp-admin/admin-post.php', nonce, clinic])


def run_case(browser, kind, second, allowed):
    tag = kind + ('_other_admin' if second else '_same_admin')
    wp('db', 'import', '/tmp/fp/pristine.sql')
    data = fixture(kind)
    before = snapshot()
    context = browser.new_context(viewport={'width': 390, 'height': 844}, locale='fa-IR')
    page = context.new_page()
    page_errors = []
    page.on('pageerror', lambda e: page_errors.append(type(e).__name__))
    try:
        login(page, second)
        resp = page.goto(BASE + '/wp-admin/admin.php?page=cpms-wizard')
        # Facility selection is presentation-only user meta, not a Clinic write.
        facility = page.locator('form:has(input[name="action"][value="cpms_wizard_facility"])')
        if facility.count():
            facility.locator('input[name="facility_type"][value="doctor_practice"]').check()
            submit(page, facility)
        check(tag + '.wizard_reachable', resp.status == 200 and 'page=cpms-wizard' in page.url)
        form = page.locator('form:has(input[name="action"][value="cpms_first_practice_recovery"])')
        check(tag + '.authorized_form_only', form.count() == (1 if allowed else 0))
        check(tag + '.get_preserves_history', before == snapshot())
        check(tag + '.persian_guidance', 'راه‌اندازی' in page.inner_text('body'))
        check(tag + '.rtl', page.locator('[dir="rtl"]').count() > 0)
        page.screenshot(path=str(OUT / (tag + '-before.png')), full_page=True)
        if tag == 'legacy_same_admin':
            page.screenshot(path=str(OUT / (tag + '-viewport.jpg')), type='jpeg', quality=65)
        if allowed and form.count() == 1:
            form.locator('input[name="confirm_recovery"]').check()
            form.locator('input[name="practice_name"]').fill('مطب بازیابی آزمایشی')
            submit(page, form)
        else:
            crafted_post(page, context, data['clinic'], second)
        after = snapshot()
        if allowed:
            members = after['cpms_clinic_memberships']
            check(tag + '.manager_persisted', len(members) == 1 and int(members[0]['wp_user_id']) == data['actor']
                  and members[0]['role_key'] == 'cpms_manager' and members[0]['status'] == 'active')
            old_audit = before['cpms_audit_logs']
            check(tag + '.historical_audit_immutable', after['cpms_audit_logs'][:len(old_audit)] == old_audit)
            check(tag + '.historical_settings_immutable', after['cpms_settings'] == before['cpms_settings'])
            check(tag + '.marker_monotonic', before['cpms_clinics'][0]['first_setup_claimed_at'] is not None
                  and after['cpms_clinics'][0]['first_setup_claimed_at'] == before['cpms_clinics'][0]['first_setup_claimed_at'])
            check(tag + '.one_recovery_audit', sum(a['action'] == 'PRACTICE_FIRST_SETUP_RECOVERY' for a in after['cpms_audit_logs']) == 1)
            page.goto(BASE + '/wp-admin/admin.php?page=cpms-wizard')
            check(tag + '.no_replay_form', page.locator('input[name="confirm_recovery"]').count() == 0)
            replay_before = snapshot()
            crafted_post(page, context, data['clinic'], second)
            check(tag + '.replay_no_writes', snapshot() == replay_before)
        else:
            check(tag + '.valid_nonce_denial_not_csrf', 'کامل‌کردن راه‌اندازی متوقف‌شده برای این مطب ممکن نیست' in page.inner_text('body'))
            check(tag + '.denial_zero_writes', before == after)
        text = page.inner_text('body')
        check(tag + '.no_raw_exception', not any(x in text for x in ['Uncaught', 'Stack trace:', 'Fatal error', 'SQLSTATE', 'RuntimeException']))
        check(tag + '.no_page_error', page_errors == [])
        check(tag + '.no_redirect_loop', 'wp-login.php' not in page.url)
        page.screenshot(path=str(OUT / (tag + '-after.png')), full_page=True)
        # Do not persist cookies, nonces or auth tokens in browser evidence.
        (OUT / (tag + '-db.json')).write_text(json.dumps({'before': before, 'after': after}, ensure_ascii=False, indent=2))
    finally:
        context.close()


try:
    if BASE != 'http://localhost:8080' or WP_DIR != '/home/runner/rwp-fp' or os.environ.get('DB_NAME') != 'cpms_fp':
        raise RuntimeError('isolated_actions_site_required')
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, args=['--no-sandbox'])
        for kind, second, allowed in [
            ('same', False, True), ('same', True, False),
            ('mixed', False, False), ('mixed', True, False), ('deleted', False, False),
            ('legacy', False, True), ('legacy', True, False), ('membership', False, False),
            ('suspended', False, False), ('clinical', False, False),
            ('legacy_mixed', False, False), ('legacy_unknown', False, False), ('legacy_writer', False, False),
        ]:
            try:
                run_case(browser, kind, second, allowed)
            except Exception as exc:
                check(kind + '.completed', False, type(exc).__name__)
        browser.close()
except Exception as exc:
    check('recovery.harness_completed', False, type(exc).__name__)
finally:
    (OUT / 'results.json').write_text(json.dumps(results, ensure_ascii=False, indent=2))
    print('RECOVERY_SUMMARY=' + json.dumps({'checks': len(results), 'failed': sum(not r['ok'] for r in results)}), flush=True)
sys.exit(0 if results and all(r['ok'] for r in results) else 1)

"""Real-browser Classic submission & security acceptance suite for CPMS.

Covers:
  1. Patient cross-Clinic self-enrollment (`PatientEnrollmentSection` -> `POST /clinic/v1/patient/enroll`):
     unverified-state privacy, explicit destination Clinic selection, no auto-selection,
     missing nonce / forged ID / suspended Clinic / cross-Organization 404 parity,
     real browser enrollment submission, non-import of source Clinic medical history,
     profile record selector refresh, duplicate-enrollment denial, and hash-chained audit.
  2. WordPress Administrator bootstrap of Organization Manager (`cpms-organization-manager-bootstrap`
     -> `admin-post.php` `cpms_bootstrap_organization_manager`) and B4 pure `manage_options`
     no-silent-grant boundary across `cpms-clinic-management`, `cpms-organizations`, and `cpms-locations`.
  3. Post-onboarding Clinic & Location management forms and lifecycle (`cpms-organizations`,
     `cpms-clinic-management`, `cpms-locations`) with offline Iranian 31-province/city selectors
     (B1, B2, B3): Clinic provisioning, Clinic profile edit, Clinic suspend/reactivate + operational
     block while suspended, Location create/edit, primary Location immutability, and non-primary
     Location deactivate/reactivate with audit chain verification.
"""

import json
from urllib.parse import parse_qs, urlparse


def setup_classic_submission_fixtures(wp_eval_php, check):
    """Create isolated, deterministic fixtures for the Classic browser submission suites."""
    raw = wp_eval_php(
        r"""
$db = \ClinicCore\Bootstrap\App::db();
$now = $db->nowUtcSql();
$pass = 'Acc-Classic-2026!#9';

$ensureUser = static function (string $login, string $email, string $role, bool $wooBypass = false) use ($pass): int {
    $u = get_user_by('login', $login);
    if ($u === false) {
        $uid = (int) wp_create_user($login, $pass, $email);
        $u = new WP_User($uid);
        $u->set_role($role);
    } else {
        wp_set_password($pass, (int) $u->ID);
        $u->set_role($role);
    }
    if ($wooBypass) {
        // WooCommerce 10.3.8 WC_Admin::prevent_admin_access redirects non-edit_posts
        // users on admin-post.php unless view_admin_dashboard is present. Granting
        // view_admin_dashboard keeps WP role (subscriber / cpms_manager) and CPMS
        // capabilities completely unchanged while allowing admin-post.php requests
        // to reach CPMS handlers in the WooCommerce coexistence lane.
        $u->add_cap('view_admin_dashboard');
    }
    return (int) $u->ID;
};

// 1. Cross-Clinic Patient Enrollment fixtures
$db->insert('cpms_organizations', [
    'name' => 'سازمان پذیرش بیمار بین‌مرکزی',
    'slug' => 'acc-enroll-org',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$enrollOrgId = (int) $db->wpdb_last_insert_id();

$db->insert('cpms_clinics', [
    'organization_id' => $enrollOrgId,
    'name' => 'کلینیک مبدأ پذیرش',
    'slug' => 'acc-enroll-source',
    'status' => 'active',
    'timezone' => 'Asia/Tehran',
    'created_at' => $now,
    'updated_at' => $now,
]);
$sourceClinicId = (int) $db->wpdb_last_insert_id();
$db->insert('cpms_locations', [
    'clinic_id' => $sourceClinicId,
    'name' => 'شعبه مبدأ',
    'slug' => 'acc-enroll-source-loc',
    'timezone' => 'Asia/Tehran',
    'is_primary' => 1,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$db->insert('cpms_clinics', [
    'organization_id' => $enrollOrgId,
    'name' => 'کلینیک مقصد پذیرش',
    'slug' => 'acc-enroll-dest',
    'status' => 'active',
    'timezone' => 'Asia/Tehran',
    'created_at' => $now,
    'updated_at' => $now,
]);
$destClinicId = (int) $db->wpdb_last_insert_id();
$db->insert('cpms_locations', [
    'clinic_id' => $destClinicId,
    'name' => 'شعبه مقصد',
    'slug' => 'acc-enroll-dest-loc',
    'timezone' => 'Asia/Tehran',
    'is_primary' => 1,
    'is_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
]);

$db->insert('cpms_clinics', [
    'organization_id' => $enrollOrgId,
    'name' => 'کلینیک معلق هم‌سازمان',
    'slug' => 'acc-enroll-suspended',
    'status' => 'suspended',
    'timezone' => 'Asia/Tehran',
    'created_at' => $now,
    'updated_at' => $now,
]);
$suspendedClinicId = (int) $db->wpdb_last_insert_id();

$db->insert('cpms_organizations', [
    'name' => 'سازمان بیگانه پذیرش',
    'slug' => 'acc-enroll-foreign-org',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$foreignEnrollOrgId = (int) $db->wpdb_last_insert_id();
$db->insert('cpms_clinics', [
    'organization_id' => $foreignEnrollOrgId,
    'name' => 'کلینیک سازمان بیگانه',
    'slug' => 'acc-enroll-foreign-clinic',
    'status' => 'active',
    'timezone' => 'Asia/Tehran',
    'created_at' => $now,
    'updated_at' => $now,
]);
$foreignEnrollClinicId = (int) $db->wpdb_last_insert_id();

$verifiedPatientUid = $ensureUser('acc_enroll_patient', 'enroll_pat@acc.local', \ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT, false);
$unverifiedPatientUid = $ensureUser('acc_unverified_patient', 'unv_pat@acc.local', \ClinicCore\Auth\RolesAndCapabilities::ROLE_PATIENT, false);

$identityId = \ClinicCore\Bootstrap\App::patient_identity_service()->create_identity($enrollOrgId, '09128887766');
\ClinicCore\Bootstrap\App::patient_identity_service()->link_user($enrollOrgId, $identityId, $verifiedPatientUid, '09128887766');

$db->insert('cpms_patients', [
    'clinic_id' => $sourceClinicId,
    'identity_id' => $identityId,
    'mrn' => 'ACC-ENROLL-SRC-1',
    'first_name' => 'سارا',
    'last_name' => 'محمدی',
    'mobile' => '09128887766',
    'medical_history' => 'PRIVATE-SOURCE-HISTORY-DO-NOT-COPY',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$sourcePatientId = (int) $db->wpdb_last_insert_id();
$db->insert('cpms_patient_user_links', [
    'clinic_id' => $sourceClinicId,
    'patient_id' => $sourcePatientId,
    'wp_user_id' => $verifiedPatientUid,
    'mobile_at_link' => '09128887766',
    'is_primary' => 1,
    'linked_at' => $now,
]);

// 2. Organization Manager Bootstrap & Clinic/Location Management fixtures
$db->insert('cpms_organizations', [
    'name' => 'سازمان پذیرش مرورگری',
    'slug' => 'acc-bootstrap-org',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bootstrapOrgId = (int) $db->wpdb_last_insert_id();

$db->insert('cpms_organizations', [
    'name' => 'سازمان غیرمجاز مرورگری',
    'slug' => 'acc-foreign-mgmt-org',
    'status' => 'active',
    'created_at' => $now,
    'updated_at' => $now,
]);
$foreignMgmtOrgId = (int) $db->wpdb_last_insert_id();
$db->insert('cpms_clinics', [
    'organization_id' => $foreignMgmtOrgId,
    'name' => 'درمانگاه سازمان غیرمجاز',
    'slug' => 'acc-foreign-mgmt-clinic',
    'status' => 'active',
    'timezone' => 'Asia/Tehran',
    'created_at' => $now,
    'updated_at' => $now,
]);
$foreignMgmtClinicId = (int) $db->wpdb_last_insert_id();

$pureAdminUid = $ensureUser('acc_pure_admin', 'pure_admin@acc.local', 'administrator', false);
$nonAdminUid = $ensureUser('acc_non_admin', 'non_admin@acc.local', 'subscriber', true);
$orgManagerUid = $ensureUser('acc_org_mgr', 'org_mgr@acc.local', 'subscriber', true);
$locManagerUid = $ensureUser('acc_loc_mgr', 'loc_mgr@acc.local', \ClinicCore\Auth\RolesAndCapabilities::ROLE_MANAGER, true);

$portalUrl = \ClinicCore\Admin\PatientPortalPage::page_url();

echo 'CLASSIC_FX=' . wp_json_encode([
    'pass' => $pass,
    'portal_url' => $portalUrl,
    'enroll_org_id' => $enrollOrgId,
    'source_clinic_id' => $sourceClinicId,
    'dest_clinic_id' => $destClinicId,
    'suspended_clinic_id' => $suspendedClinicId,
    'foreign_enroll_clinic_id' => $foreignEnrollClinicId,
    'verified_patient_uid' => $verifiedPatientUid,
    'verified_patient_login' => 'acc_enroll_patient',
    'verified_identity_id' => $identityId,
    'unverified_patient_uid' => $unverifiedPatientUid,
    'unverified_patient_login' => 'acc_unverified_patient',
    'bootstrap_org_id' => $bootstrapOrgId,
    'foreign_mgmt_org_id' => $foreignMgmtOrgId,
    'foreign_mgmt_clinic_id' => $foreignMgmtClinicId,
    'pure_admin_uid' => $pureAdminUid,
    'pure_admin_login' => 'acc_pure_admin',
    'non_admin_uid' => $nonAdminUid,
    'non_admin_login' => 'acc_non_admin',
    'org_manager_uid' => $orgManagerUid,
    'org_manager_login' => 'acc_org_mgr',
    'loc_manager_uid' => $locManagerUid,
    'loc_manager_login' => 'acc_loc_mgr',
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    line = next((l for l in raw.splitlines() if l.startswith("CLASSIC_FX=")), "")
    fx = json.loads(line.split("=", 1)[1]) if line else {}
    check(
        "classic.fixtures.seeded",
        bool(fx.get("dest_clinic_id")) and bool(fx.get("bootstrap_org_id")) and bool(fx.get("portal_url")),
        f"dest_clinic={fx.get('dest_clinic_id')} bootstrap_org={fx.get('bootstrap_org_id')}",
    )
    return fx


def _post_form_in_browser(page, action_url, fields):
    """Submit a real HTML form POST from the active browser session and wait for navigation."""
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        page.evaluate(
            """([url, entries]) => {
                const f = document.createElement('form');
                f.method = 'post';
                f.action = url;
                for (const [k, v] of entries) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = k;
                    input.value = String(v);
                    f.appendChild(input);
                }
                document.body.appendChild(f);
                f.submit();
            }""",
            [action_url, list(fields.items())],
        )


def _submit_form_bypassing_confirm(page, form):
    """Click submit button on a form, removing data-cpms-confirm so it submits directly."""
    form.evaluate(
        """f => {
            f.removeAttribute('data-cpms-confirm');
            f.querySelectorAll('[data-cpms-confirm]').forEach(el => el.removeAttribute('data-cpms-confirm'));
        }"""
    )
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        form.locator("button").first.click()


def run_unverified_patient_enrollment(page, check, wp_eval_php, base_url, out_dir, fx):
    """1a. Unverified patient sees no destination Clinic names and cannot self-enroll."""
    resp = page.goto(fx["portal_url"], wait_until="domcontentloaded")
    page.screenshot(path=f"{out_dir}/screenshots/classic-patient-unverified.png", full_page=True)
    body = page.content()
    check(
        "classic.enrollment.unverified.http200",
        resp is not None and resp.status == 200,
        f"HTTP {resp.status if resp else 0}",
    )
    check(
        "classic.enrollment.unverified.state_rendered",
        page.locator('[data-role="enrollment-section"][data-state="unverified"]').count() == 1
        and page.locator('[data-role="patient-enrollment-form"]').count() == 0
        and page.locator('[data-role="enrollment-clinic"]').count() == 0,
        "unverified patient sees reception guidance and zero Clinic selector",
    )
    check(
        "classic.enrollment.unverified.no_clinic_names_leaked",
        "کلینیک مقصد پذیرش" not in body and "کلینیک سازمان بیگانه" not in body,
        "neither same-Organization nor foreign Clinic names are published to unverified subject",
    )

    # Direct REST POST attempt by unverified patient with valid REST nonce -> 404 parity
    rest_probe = page.evaluate(
        """async (clinicId) => {
            const cfgEl = document.querySelector('.cpms-patient-portal__config');
            const cfg = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};
            const root = String(cfg.rest_root || '').replace(/\\/+$/, '');
            const sep = root.includes('?') ? '&' : '?';
            const url = root + '/patient/enroll';
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': String(cfg.nonce || ''),
                },
                body: JSON.stringify({
                    clinic_id: clinicId,
                    first_name: 'کاربر',
                    last_name: 'تأییدنشده',
                    mobile: '09120001122',
                }),
            });
            return { status: res.status };
        }""",
        int(fx["dest_clinic_id"]),
    )
    check(
        "classic.enrollment.unverified.post_denied_404",
        rest_probe.get("status") == 404,
        f"HTTP {rest_probe.get('status')}",
    )


def run_verified_patient_enrollment(page, check, wp_eval_php, base_url, out_dir, fx):
    """1b. Verified patient cross-Clinic self-enrollment browser submission & security boundaries."""
    resp = page.goto(fx["portal_url"], wait_until="domcontentloaded")
    check(
        "classic.enrollment.verified.http200",
        resp is not None and resp.status == 200,
        f"HTTP {resp.status if resp else 0}",
    )
    section = page.locator('[data-role="enrollment-section"][data-state="eligible"]')
    form = page.locator('[data-role="patient-enrollment-form"]')
    clinic_select = form.locator('[data-role="enrollment-clinic"]')
    check(
        "classic.enrollment.verified.eligible_form_rendered",
        section.count() == 1 and form.count() == 1 and clinic_select.count() == 1,
        "eligible cross-Clinic enrollment form rendered in Classic Patient Portal",
    )

    offered_values = clinic_select.locator("option").evaluate_all(
        "els => els.map(e => e.getAttribute('value') || '')"
    )
    check(
        "classic.enrollment.verified.no_default_clinic_selected",
        clinic_select.input_value() == "",
        f"initial value={clinic_select.input_value()!r}",
    )
    check(
        "classic.enrollment.verified.only_active_unenrolled_same_org_offered",
        offered_values == ["", str(fx["dest_clinic_id"])],
        f"offered={offered_values} (excludes already-enrolled {fx['source_clinic_id']}, suspended {fx['suspended_clinic_id']}, foreign {fx['foreign_enroll_clinic_id']})",
    )

    # Submitting without selecting a destination Clinic shows client-side error and sends no write
    form.locator('input[name="first_name"]').fill("سارا")
    form.locator('input[name="last_name"]').fill("محمدی")
    form.locator('input[name="mobile"]').fill("09128887766")
    form.evaluate("el => el.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}))")
    err_box = form.locator('[data-role="enrollment-error"]')
    check(
        "classic.enrollment.verified.missing_clinic_blocked",
        not err_box.is_hidden() and "یک مرکز را از فهرست انتخاب کنید" in err_box.inner_text(),
        err_box.inner_text().strip(),
    )

    # Security probes against POST /clinic/v1/patient/enroll from the real browser session:
    # (1) missing nonce -> 401/403, (2) forged ID -> 404, (3) foreign Org Clinic -> 404, (4) suspended Clinic -> 404
    probes = page.evaluate(
        """async ({ destId, foreignId, suspendedId }) => {
            const cfg = JSON.parse(document.querySelector('.cpms-patient-portal__config').textContent || '{}');
            const url = String(cfg.rest_root || '').replace(/\\/+$/, '') + '/patient/enroll';
            const post = async (headers, body) => {
                const r = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
                    body: JSON.stringify(body),
                });
                return r.status;
            };
            const payload = (cid) => ({ clinic_id: cid, first_name: 'سارا', last_name: 'محمدی', mobile: '09128887766' });
            const noNonce = await post({}, payload(destId));
            const badNonce = await post({ 'X-WP-Nonce': 'forged-nonce-value' }, payload(destId));
            const forgedId = await post({ 'X-WP-Nonce': cfg.nonce }, payload(2147483647));
            const foreignClinic = await post({ 'X-WP-Nonce': cfg.nonce }, payload(foreignId));
            const suspendedClinic = await post({ 'X-WP-Nonce': cfg.nonce }, payload(suspendedId));
            return { noNonce, badNonce, forgedId, foreignClinic, suspendedClinic };
        }""",
        {
            "destId": int(fx["dest_clinic_id"]),
            "foreignId": int(fx["foreign_enroll_clinic_id"]),
            "suspendedId": int(fx["suspended_clinic_id"]),
        },
    )
    check(
        "classic.enrollment.verified.nonce_enforced",
        probes["noNonce"] in (401, 403) and probes["badNonce"] in (401, 403),
        f"noNonce={probes['noNonce']} badNonce={probes['badNonce']}",
    )
    check(
        "classic.enrollment.verified.forged_and_cross_tenant_404_parity",
        probes["forgedId"] == 404 and probes["foreignClinic"] == 404 and probes["suspendedClinic"] == 404,
        f"forged={probes['forgedId']} foreign={probes['foreignClinic']} suspended={probes['suspendedClinic']}",
    )

    # Real browser submission of the enrollment form for the authorized destination Clinic
    clinic_select.select_option(str(fx["dest_clinic_id"]))
    form.locator('input[name="first_name"]').fill("سارا")
    form.locator('input[name="last_name"]').fill("محمدی")
    form.locator('input[name="mobile"]').fill("09128887766")
    form.locator('[data-role="enrollment-submit"]').click()

    page.wait_for_selector('[data-role="enrollment-success"]:not([hidden])', timeout=15000)
    page.screenshot(path=f"{out_dir}/screenshots/classic-patient-enrolled.png", full_page=True)
    success_text = page.locator('[data-role="enrollment-success"]').inner_text().strip()
    mrn_text = page.locator('[data-role="enrollment-mrn"]').inner_text().strip()
    check(
        "classic.enrollment.verified.browser_submit_succeeded",
        "پروندهٔ محلی شما ثبت شد" in success_text and mrn_text != "",
        f"mrn={mrn_text}",
    )

    # Reload the portal and verify enrollment state transitions to no_destination and multi-record profile selector is rendered
    page.reload(wait_until="domcontentloaded")
    check(
        "classic.enrollment.verified.profile_selector_refreshed",
        page.locator('[data-role="enrollment-section"][data-state="no_destination"]').count() == 1
        and page.locator('[data-role="profile-record-select"]').count() == 1,
        "after reload, enrollment state is no_destination and multi-record profile selector is rendered",
    )

    # Verify DB persistence, non-import of source medical_history, and audit hash chain
    db_verify = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$destId = {int(fx['dest_clinic_id'])};
$foreignId = {int(fx['foreign_enroll_clinic_id'])};
$suspendedId = {int(fx['suspended_clinic_id'])};
$uid = {int(fx['verified_patient_uid'])};
$expectedIdentity = {int(fx['verified_identity_id'])};
$destRows = $db->fetchAll('SELECT id, identity_id, first_name, last_name, mobile, medical_history FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', [$destId]);
$foreignCount = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', [$foreignId]);
$suspendedCount = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_patients') . ' WHERE clinic_id = %d', [$suspendedId]);
$linkCount = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_patient_user_links') . ' WHERE clinic_id = %d AND wp_user_id = %d', [$destId, $uid]);
$auditRow = $db->fetchRow('SELECT id, action, actor_wp_user_id, clinic_id FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d AND action = %s ORDER BY id DESC LIMIT 1', [$destId, 'PATIENT_CREATED']);
$chain = \\ClinicCore\\Bootstrap\\App::audit()->verifyChain(1000);
echo 'ENROLL_DB=' . wp_json_encode([
    'dest_rows' => $destRows,
    'foreign_count' => $foreignCount,
    'suspended_count' => $suspendedCount,
    'link_count' => $linkCount,
    'audit' => $auditRow,
    'chain_ok' => (bool) ($chain['ok'] ?? false),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    enroll_db = json.loads(next(l.split("=", 1)[1] for l in db_verify.splitlines() if l.startswith("ENROLL_DB=")))
    dest_rows = enroll_db.get("dest_rows") or []
    check(
        "classic.enrollment.verified.db_record_and_isolation",
        len(dest_rows) == 1
        and int(dest_rows[0]["identity_id"]) == int(fx["verified_identity_id"])
        and dest_rows[0]["first_name"] == "سارا"
        and dest_rows[0]["last_name"] == "محمدی"
        and dest_rows[0]["medical_history"] is None
        and enroll_db.get("foreign_count") == 0
        and enroll_db.get("suspended_count") == 0
        and enroll_db.get("link_count") == 1,
        f"dest_rows={len(dest_rows)} medical_history={dest_rows[0].get('medical_history') if dest_rows else 'none'}",
    )
    check(
        "classic.enrollment.verified.audit_and_chain",
        bool(enroll_db.get("audit"))
        and int(enroll_db["audit"]["actor_wp_user_id"]) == int(fx["verified_patient_uid"])
        and int(enroll_db["audit"]["clinic_id"]) == int(fx["dest_clinic_id"])
        and enroll_db.get("chain_ok") is True,
        f"audit={enroll_db.get('audit')} chain_ok={enroll_db.get('chain_ok')}",
    )

    # Duplicate enrollment attempt for the same destination Clinic -> 400
    dup_status = page.evaluate(
        """async (destId) => {
            const cfg = JSON.parse(document.querySelector('.cpms-patient-portal__config').textContent || '{}');
            const url = String(cfg.rest_root || '').replace(/\\/+$/, '') + '/patient/enroll';
            const r = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ clinic_id: destId, first_name: 'سارا', last_name: 'محمدی', mobile: '09128887766' }),
            });
            return r.status;
        }""",
        int(fx["dest_clinic_id"]),
    )
    check("classic.enrollment.verified.duplicate_rejected_400", dup_status == 400, f"HTTP {dup_status}")


def run_non_admin_bootstrap_denial(page, check, wp_eval_php, base_url, out_dir, fx):
    """2a. Non-Administrator is denied on Organization Manager bootstrap GET and POST."""
    resp = page.goto(
        f"{base_url}/wp-admin/options-general.php?page=cpms-organization-manager-bootstrap",
        wait_until="domcontentloaded",
    )
    body = page.content()
    check(
        "classic.bootstrap.non_admin_get_denied",
        (resp is not None and resp.status in (403, 500))
        or "دسترسی ندارید" in body
        or 'value="cpms_bootstrap_organization_manager"' not in body,
        f"HTTP {resp.status if resp else 0}",
    )

    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_bootstrap_organization_manager",
            "_wpnonce": "forged",
            "organization_id": int(fx["bootstrap_org_id"]),
            "target_user_id": int(fx["org_manager_uid"]),
            "confirm_bootstrap": "1",
        },
    )
    cnt_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$n = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_organization_memberships') . ' WHERE organization_id = %d', [{int(fx['bootstrap_org_id'])}]);
echo 'BOOT_CNT=' . $n . PHP_EOL;
"""
    )
    cnt = int(next(l.split("=", 1)[1] for l in cnt_raw.splitlines() if l.startswith("BOOT_CNT=")))
    check("classic.bootstrap.non_admin_post_zero_mutation", cnt == 0, f"memberships={cnt}")


def run_admin_bootstrap_and_b4_boundary(page, check, wp_eval_php, base_url, out_dir, fx):
    """2b. Pure WP Administrator B4 boundary + real-browser Organization Manager bootstrap."""
    org_id = int(fx["bootstrap_org_id"])
    target_uid = int(fx["org_manager_uid"])

    # --- B4: Pure manage_options WP Administrator has no silent Organization or Clinic grant ---
    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-clinic-management", wait_until="domcontentloaded")
    cm_body = page.content()
    check(
        "classic.b4.pure_admin_no_silent_org_grant_on_clinic_management",
        "عضویت فعال مدیر سازمان با مجوز مدیریت درمانگاه ثبت نشده است" in cm_body
        and page.locator('form:has(input[name="action"][value="cpms_update_organization_clinic"])').count() == 0
        and page.locator('form:has(input[name="action"][value="cpms_clinic_lifecycle"])').count() == 0,
        "pure WP Administrator sees no-silent-grant notice and zero Clinic write forms on cpms-clinic-management",
    )

    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-organizations", wait_until="domcontentloaded")
    org_body = page.content()
    check(
        "classic.b4.pure_admin_no_silent_grant_on_organizations",
        "دسترسی مدیر وردپرس به‌تنهایی مجوز ایجاد درمانگاه نیست" in org_body
        and page.locator('form:has(input[name="action"][value="cpms_provision_organization_clinic"])').count() == 0,
        "pure WP Administrator sees no-silent-grant notice and zero provisioning form on cpms-organizations",
    )

    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-locations", wait_until="domcontentloaded")
    loc_body = page.content()
    check(
        "classic.b4.pure_admin_no_silent_grant_on_locations",
        "برای مدیریت شعبه‌ها، عضویت فعال و مجوز Clinic-scoped در یک Clinic لازم است" in loc_body
        and page.locator('form:has(input[name="action"][value="cpms_location_save"])').count() == 0,
        "pure WP Administrator sees no-silent-grant notice and zero Location write forms on cpms-locations",
    )

    # --- WP Administrator Bootstrap of Organization Manager (`cpms-organization-manager-bootstrap`) ---
    page.goto(f"{base_url}/wp-admin/options-general.php?page=cpms-organization-manager-bootstrap", wait_until="domcontentloaded")
    boot_form = page.locator('form:has(input[name="action"][value="cpms_bootstrap_organization_manager"])')
    org_select = boot_form.locator("#cpms-bootstrap-organization")
    user_select = boot_form.locator("#cpms-bootstrap-user")
    check(
        "classic.bootstrap.explicit_org_and_user_selectors_no_auto_select",
        boot_form.count() == 1
        and org_select.count() == 1
        and org_select.input_value() == ""
        and user_select.count() == 1
        and user_select.input_value() == "0",
        "Organization selector starts blank ('') and user selector starts at '0' (no auto-selection)",
    )
    valid_nonce = boot_form.locator('input[name="_wpnonce"]').input_value()

    # Negative POST probes:
    # (a) forged nonce -> 403 / wp_die
    # (b) missing confirmation -> 403 / wp_die ("درخواست معتبر نیست یا تأیید صریح انجام نشده است.")
    # (c) forged organization_id -> error notice ("خطا: فعال‌سازی انجام نشد")
    # (d) forged target_user_id -> error notice ("خطا: فعال‌سازی انجام نشد")
    for probe_name, fields, expected_marker in [
        (
            "forged_nonce",
            {
                "action": "cpms_bootstrap_organization_manager",
                "_wpnonce": "invalid-nonce",
                "organization_id": org_id,
                "target_user_id": target_uid,
                "confirm_bootstrap": "1",
            },
            None,
        ),
        (
            "missing_confirmation",
            {
                "action": "cpms_bootstrap_organization_manager",
                "_wpnonce": valid_nonce,
                "organization_id": org_id,
                "target_user_id": target_uid,
            },
            "درخواست معتبر نیست یا تأیید صریح انجام نشده است",
        ),
        (
            "forged_organization_id",
            {
                "action": "cpms_bootstrap_organization_manager",
                "_wpnonce": valid_nonce,
                "organization_id": 2147483647,
                "target_user_id": target_uid,
                "confirm_bootstrap": "1",
            },
            "خطا: فعال‌سازی انجام نشد",
        ),
        (
            "forged_target_user_id",
            {
                "action": "cpms_bootstrap_organization_manager",
                "_wpnonce": valid_nonce,
                "organization_id": org_id,
                "target_user_id": 2147483647,
                "confirm_bootstrap": "1",
            },
            "خطا: فعال‌سازی انجام نشد",
        ),
    ]:
        _post_form_in_browser(page, f"{base_url}/wp-admin/admin-post.php", fields)
        probe_body = page.content()
        ok = (
            ("مدیر اولیهٔ سازمان با موفقیت فعال شد" not in probe_body)
            and (expected_marker is None or expected_marker in probe_body)
        )
        check(
            f"classic.bootstrap.negative.{probe_name}_denied",
            ok,
            f"url={page.url}",
        )

    # Verify zero memberships were created by any negative probe
    pre_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
echo 'PRE_BOOT=' . (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_organization_memberships') . ' WHERE organization_id = %d', [{org_id}]) . PHP_EOL;
"""
    )
    pre_cnt = int(next(l.split("=", 1)[1] for l in pre_raw.splitlines() if l.startswith("PRE_BOOT=")))
    check("classic.bootstrap.negative_probes_zero_memberships", pre_cnt == 0, f"count={pre_cnt}")

    # Real browser form submission for valid bootstrap
    page.goto(
        f"{base_url}/wp-admin/options-general.php?page=cpms-organization-manager-bootstrap",
        wait_until="domcontentloaded",
    )
    boot_form = page.locator('form:has(input[name="action"][value="cpms_bootstrap_organization_manager"])')
    boot_form.locator("#cpms-bootstrap-organization").select_option(str(org_id))
    boot_form.locator("#cpms-bootstrap-user").select_option(str(target_uid))
    boot_form.locator('input[name="confirm_bootstrap"]').check()
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        boot_form.locator('button[type="submit"]').click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-org-manager-bootstrapped.png", full_page=True)

    check(
        "classic.bootstrap.real_submit_created",
        "مدیر اولیهٔ سازمان با موفقیت فعال شد." in page.content(),
        f"url={page.url}",
    )

    # Verify DB membership, role non-mutation, zero Clinic membership, and audit chain
    post_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$orgId = {org_id};
$targetId = {target_uid};
$adminId = {int(fx['pure_admin_uid'])};
$rows = $db->fetchAll('SELECT * FROM ' . $db->table('cpms_organization_memberships') . ' WHERE organization_id = %d', [$orgId]);
$clinicMemCnt = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE wp_user_id = %d', [$targetId]);
$overrideCnt = !empty($rows) ? (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_organization_membership_capabilities') . ' WHERE organization_membership_id = %d', [(int) $rows[0]['id']]) : -1;
$roles = array_values((array) get_userdata($targetId)->roles);
$audits = $db->fetchAll('SELECT id, action, resource_type, resource_id, clinic_id, actor_wp_user_id FROM ' . $db->table('cpms_audit_logs') . ' WHERE action = %s AND resource_id = %d', ['ORGANIZATION_MANAGER_BOOTSTRAPPED', $orgId]);
$chain = \\ClinicCore\\Bootstrap\\App::audit()->verifyChain(1000);
echo 'POST_BOOT=' . wp_json_encode([
    'rows' => $rows,
    'clinic_mem_cnt' => $clinicMemCnt,
    'override_cnt' => $overrideCnt,
    'roles' => $roles,
    'audits' => $audits,
    'chain_ok' => (bool) ($chain['ok'] ?? false),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    post_boot = json.loads(next(l.split("=", 1)[1] for l in post_raw.splitlines() if l.startswith("POST_BOOT=")))
    rows = post_boot.get("rows") or []
    audits = post_boot.get("audits") or []
    check(
        "classic.bootstrap.membership_and_role_invariants",
        len(rows) == 1
        and int(rows[0]["wp_user_id"]) == target_uid
        and rows[0]["role_key"] == "organization_manager"
        and rows[0]["status"] == "active"
        and int(rows[0]["created_by_wp_user_id"]) == int(fx["pure_admin_uid"])
        and post_boot.get("clinic_mem_cnt") == 0
        and post_boot.get("override_cnt") == 0
        and post_boot.get("roles") == ["subscriber"],
        f"rows={len(rows)} roles={post_boot.get('roles')} clinic_mem={post_boot.get('clinic_mem_cnt')}",
    )
    check(
        "classic.bootstrap.audit_and_hash_chain",
        len(audits) == 1
        and audits[0]["resource_type"] == "organization"
        and int(audits[0]["resource_id"]) == org_id
        and audits[0]["clinic_id"] is None
        and int(audits[0]["actor_wp_user_id"]) == int(fx["pure_admin_uid"])
        and post_boot.get("chain_ok") is True,
        f"audits={audits} chain_ok={post_boot.get('chain_ok')}",
    )

    # Idempotent repeat of identical bootstrap -> noop notice, no duplicate audit
    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_bootstrap_organization_manager",
            "_wpnonce": valid_nonce,
            "organization_id": org_id,
            "target_user_id": target_uid,
            "confirm_bootstrap": "1",
        },
    )
    check(
        "classic.bootstrap.repeat_is_idempotent_existing",
        "این حساب از قبل مدیر فعالِ یکتای همین سازمان است؛ تغییری انجام نشد." in page.content(),
        f"url={page.url}",
    )

    # Competing target user after active manager exists -> error notice, no replacement
    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_bootstrap_organization_manager",
            "_wpnonce": valid_nonce,
            "organization_id": org_id,
            "target_user_id": int(fx["non_admin_uid"]),
            "confirm_bootstrap": "1",
        },
    )
    check(
        "classic.bootstrap.competing_target_denied",
        "خطا: فعال‌سازی انجام نشد" in page.content(),
        f"url={page.url}",
    )


def run_org_manager_clinic_lifecycle(page, check, wp_eval_php, base_url, out_dir, fx):
    """3a. Bootstrapped Organization Manager provisions Clinic + edits profile (Iran province/city) + suspends/reactivates (B1 + B2)."""
    org_id = int(fx["bootstrap_org_id"])
    foreign_org_id = int(fx["foreign_mgmt_org_id"])

    # --- Step 1: Provision a new Clinic + primary Location on `cpms-organizations` ---
    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-organizations", wait_until="domcontentloaded")
    prov_form = page.locator('form:has(input[name="action"][value="cpms_provision_organization_clinic"])')
    check("classic.org_mgr.provision_form_rendered", prov_form.count() == 1, f"url={page.url}")

    org_select = prov_form.locator("select#org")
    org_options = org_select.locator("option").evaluate_all("els => els.map(e => e.getAttribute('value') || '')")
    check(
        "classic.org_mgr.only_authorized_organization_offered",
        org_select.input_value() == "" and org_options == ["", str(org_id)],
        f"options={org_options} (excludes foreign org {foreign_org_id})",
    )

    # Verify offline Iranian province/city selectors on provisioning form
    prov_select = prov_form.locator('select[name="address_province"]')
    city_select = prov_form.locator('select[name="address_city"]')
    prov_values = prov_select.locator("option").evaluate_all("els => els.map(e => e.getAttribute('value') || '').filter(Boolean)")
    check(
        "classic.geography.provision_form_has_31_offline_provinces_no_tehran_default",
        prov_select.count() == 1
        and city_select.count() == 1
        and prov_select.input_value() == ""
        and len(prov_values) == 31
        and "اصفهان" in prov_values
        and "خراسان رضوی" in prov_values,
        f"province_count={len(prov_values)} initial={prov_select.input_value()!r}",
    )

    prov_nonce = prov_form.locator('input[name="_wpnonce"]').input_value()

    # Cross-Organization provisioning attempt -> 403 denied, zero clinics created in foreign_org_id
    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_provision_organization_clinic",
            "_wpnonce": prov_nonce,
            "organization_id": foreign_org_id,
            "name": "درمانگاه غیرمجاز",
            "slug": "forged-foreign-clinic",
            "timezone": "Asia/Tehran",
        },
    )
    check(
        "classic.org_mgr.cross_org_provision_denied",
        "ایجاد درمانگاه انجام نشد" in page.content(),
        "cross-Organization Clinic provisioning rejected fail-closed",
    )

    # Forged cross-province city on provisioning -> 403 denied
    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_provision_organization_clinic",
            "_wpnonce": prov_nonce,
            "organization_id": org_id,
            "name": "درمانگاه شهر نامعتبر",
            "slug": "bad-city-clinic",
            "timezone": "Asia/Tehran",
            "address_province": "تهران",
            "address_city": "شیراز",
            "address": "خیابان اول",
        },
    )
    check(
        "classic.geography.mismatched_province_city_provision_denied",
        "ایجاد درمانگاه انجام نشد" in page.content(),
        "mismatched province/city (تهران + شیراز) rejected fail-closed",
    )

    # Valid browser submission on `cpms-organizations` with province=اصفهان, city=کاشان
    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-organizations", wait_until="domcontentloaded")
    prov_form = page.locator('form:has(input[name="action"][value="cpms_provision_organization_clinic"])')
    prov_form.locator("select#org").select_option(str(org_id))
    prov_form.locator('select[name="address_province"]').select_option("اصفهان")
    # Progressive enhancement check: optgroup for اصفهان is active while تهران optgroup is disabled
    isfahan_enabled = prov_form.locator('select[name="address_city"] optgroup[data-province="اصفهان"]').evaluate("el => !el.disabled")
    tehran_disabled = prov_form.locator('select[name="address_city"] optgroup[data-province="تهران"]').evaluate("el => el.disabled")
    check(
        "classic.geography.progressive_city_filtering_offline",
        bool(isfahan_enabled) and bool(tehran_disabled),
        f"isfahan_enabled={isfahan_enabled} tehran_disabled={tehran_disabled}",
    )
    prov_form.locator('select[name="address_city"]').select_option("کاشان")
    prov_form.locator('input[name="name"]').fill("درمانگاه پذیرش کاشان")
    prov_form.locator('input[name="slug"]').fill("acc-kashan-clinic")
    prov_form.locator('input[name="timezone"]').fill("Asia/Tehran")
    prov_form.locator('input[name="location_name"]').fill("شعبه مرکزی کاشان")
    prov_form.locator('input[name="address"]').fill("خیابان امیرکبیر، پلاک ۱۰")
    prov_form.locator('input[name="phone"]').fill("03155551234")
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        prov_form.locator('button.button-primary').click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-clinic-provisioned.png", full_page=True)

    check(
        "classic.org_mgr.clinic_provisioned_redirect",
        parse_qs(urlparse(page.url).query).get("created") == ["1"],
        f"url={page.url}",
    )

    # Inspect DB for provisioned Clinic + primary Location + composed Persian address + grant loc_manager membership
    prov_db_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$clinic = $db->fetchRow('SELECT * FROM ' . $db->table('cpms_clinics') . ' WHERE slug = %s LIMIT 1', ['acc-kashan-clinic']);
$loc = $clinic ? $db->fetchRow('SELECT * FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d AND is_primary = 1 LIMIT 1', [(int) $clinic['id']]) : null;
$foreignCnt = (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('cpms_clinics') . ' WHERE slug IN (%s, %s)', ['forged-foreign-clinic', 'bad-city-clinic']);
if ($clinic) {{
    $locMgrUid = {int(fx['loc_manager_uid'])};
    $existingMem = $db->fetchRow('SELECT id FROM ' . $db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1', [(int) $clinic['id'], $locMgrUid]);
    if (!$existingMem) {{
        $now = $db->nowUtcSql();
        $db->insert('cpms_clinic_memberships', [
            'clinic_id' => (int) $clinic['id'],
            'wp_user_id' => $locMgrUid,
            'role_key' => \\ClinicCore\\Auth\\RolesAndCapabilities::ROLE_MANAGER,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }}
}}
echo 'PROV_DB=' . wp_json_encode([
    'clinic' => $clinic,
    'location' => $loc,
    'rejected_count' => $foreignCnt,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    prov_db = json.loads(next(l.split("=", 1)[1] for l in prov_db_raw.splitlines() if l.startswith("PROV_DB=")))
    clinic_row = prov_db.get("clinic") or {}
    loc_row = prov_db.get("location") or {}
    clinic_id = int(clinic_row.get("id") or 0)
    primary_loc_id = int(loc_row.get("id") or 0)
    fx["provisioned_clinic_id"] = clinic_id
    fx["provisioned_primary_loc_id"] = primary_loc_id

    expected_addr = "اصفهان، کاشان، خیابان امیرکبیر، پلاک ۱۰"
    check(
        "classic.org_mgr.provisioned_clinic_and_primary_location_persisted",
        clinic_id > 0
        and primary_loc_id > 0
        and int(clinic_row.get("organization_id", 0)) == org_id
        and clinic_row.get("status") == "active"
        and clinic_row.get("address") == expected_addr
        and loc_row.get("address") == expected_addr
        and int(loc_row.get("is_primary", 0)) == 1
        and int(loc_row.get("is_active", 0)) == 1
        and prov_db.get("rejected_count") == 0,
        f"clinic_id={clinic_id} addr={clinic_row.get('address')!r}",
    )

    # --- Step 2: Post-onboarding Clinic Management (`cpms-clinic-management` — B1 & B2) ---
    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-clinic-management", wait_until="domcontentloaded")
    check(
        "classic.clinic_mgmt.requires_explicit_org_selection",
        "لطفاً ابتدا سازمان را به‌صورت صریح انتخاب کنید" in page.content()
        and page.locator("select#cpms_cm_org").input_value() == "",
        "no Organization is auto-selected on cpms-clinic-management",
    )

    # Forged Organization ID in URL -> rejected
    page.goto(
        f"{base_url}/wp-admin/admin.php?page=cpms-clinic-management&organization_id={foreign_org_id}",
        wait_until="domcontentloaded",
    )
    check(
        "classic.clinic_mgmt.cross_org_url_denied",
        "سازمان انتخاب‌شده برای این حساب مجاز نیست." in page.content()
        and page.locator('form:has(input[name="action"][value="cpms_clinic_lifecycle"])').count() == 0,
        "foreign Organization ID is denied on cpms-clinic-management",
    )

    # Select authorized Organization explicitly
    page.locator("select#cpms_cm_org").select_option(str(org_id))
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        page.locator('form:has(select#cpms_cm_org) button').first.click()

    # Open the Clinic settings edit form
    edit_link = page.locator(f'a[href*="page=cpms-clinic-management"][href*="clinic_id={clinic_id}"]')
    check("classic.clinic_mgmt.edit_link_present", edit_link.count() == 1, f"clinic_id={clinic_id}")
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        edit_link.first.click()

    edit_form = page.locator('form:has(input[name="action"][value="cpms_update_organization_clinic"])')
    pre_prov = edit_form.locator('select[name="address_province"]').input_value() if edit_form.count() else ""
    pre_city = edit_form.locator('select[name="address_city"]').input_value() if edit_form.count() else ""
    check(
        "classic.geography.clinic_edit_preselects_saved_province_and_city",
        edit_form.count() == 1
        and pre_prov == "اصفهان"
        and pre_city == "کاشان"
        and edit_form.locator("input#cm_address").input_value() == expected_addr,
        f"province={pre_prov!r} city={pre_city!r}",
    )

    # Update Clinic profile to خراسان رضوی / مشهد via the real browser form
    edit_form.locator("input#cm_name").fill("درمانگاه تخصصی مشهد")
    edit_form.locator('select[name="address_province"]').select_option("خراسان رضوی")
    edit_form.locator('select[name="address_city"]').select_option("مشهد")
    edit_form.locator("input#cm_address").fill("بلوار وکیل‌آباد، پلاک ۱۵")
    edit_form.locator("input#cm_phone").fill("05138889900")
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        edit_form.locator("button.button-primary").click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-clinic-updated.png", full_page=True)

    check(
        "classic.clinic_mgmt.profile_update_notice",
        "تنظیمات درمانگاه با موفقیت به‌روز شد." in page.content(),
        "Clinic profile update succeeded",
    )

    # Forged cross-province city on Clinic update -> error notice and no mutation
    edit_form = page.locator('form:has(input[name="action"][value="cpms_update_organization_clinic"])')
    update_nonce = edit_form.locator('input[name="_wpnonce"]').input_value()
    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_update_organization_clinic",
            "_wpnonce": update_nonce,
            "organization_id": org_id,
            "clinic_id": clinic_id,
            "name": "نام دستکاری‌شده",
            "address_province": "یزد",
            "address_city": "تبریز",
            "address": "خیابان نمونه",
            "phone": "05138889900",
        },
    )
    check(
        "classic.geography.clinic_update_mismatched_city_rejected",
        "خطا: به‌روزرسانی تنظیمات انجام نشد" in page.content(),
        "mismatched province/city (یزد + تبریز) rejected on cpms-clinic-management",
    )

    # --- Step 3: Clinic Lifecycle Suspend & Reactivate (B2) ---
    life_form = page.locator(
        f'form:has(input[name="action"][value="cpms_clinic_lifecycle"]):has(input[name="clinic_id"][value="{clinic_id}"])'
    )
    check(
        "classic.clinic_mgmt.lifecycle_suspend_form_present",
        life_form.count() == 1 and life_form.locator('input[name="active"]').input_value() == "0",
        "suspend form present for active Clinic",
    )
    _submit_form_bypassing_confirm(page, life_form.first)
    page.screenshot(path=f"{out_dir}/screenshots/classic-clinic-suspended.png", full_page=True)

    check(
        "classic.clinic_mgmt.suspended_notice_and_badge",
        "درمانگاه غیرفعال شد" in page.content()
        and page.locator(".cpms-badge.cpms-danger").count() >= 1,
        "Clinic status badge updated to غیرفعال",
    )

    # Verify DB status=='suspended', CLINIC_SUSPENDED audit, AND that new-demand operational booking is blocked while suspended
    susp_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$cid = {clinic_id};
$locMgr = {int(fx['loc_manager_uid'])};
$row = $db->fetchRow('SELECT name, address, phone, status FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$cid]);
$suspAudit = $db->fetchRow('SELECT id, action FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d AND action = %s ORDER BY id DESC LIMIT 1', [$cid, 'CLINIC_SUSPENDED']);
$blockedCode = '';
\\ClinicCore\\Application\\Scope\\ScopeContext::set(\\ClinicCore\\Application\\Scope\\ClinicScope::forClinic($cid));
try {{
    \\ClinicCore\\Bootstrap\\App::bookingService()->createByStaff($locMgr, 1, 1, '2026-10-25', '09:00', null);
}} catch (\\ClinicCore\\Domain\\Booking\\BookingException $e) {{
    $blockedCode = (string) $e->errorCode;
}} finally {{
    \\ClinicCore\\Application\\Scope\\ScopeContext::clear();
}}
echo 'SUSP_DB=' . wp_json_encode([
    'row' => $row,
    'susp_audit' => $suspAudit,
    'blocked_code' => $blockedCode,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    susp_db = json.loads(next(l.split("=", 1)[1] for l in susp_raw.splitlines() if l.startswith("SUSP_DB=")))
    check(
        "classic.clinic_mgmt.suspended_db_and_operational_block",
        susp_db["row"]["status"] == "suspended"
        and susp_db["row"]["name"] == "درمانگاه تخصصی مشهد"
        and susp_db["row"]["address"] == "خراسان رضوی، مشهد، بلوار وکیل‌آباد، پلاک ۱۵"
        and bool(susp_db.get("susp_audit"))
        and susp_db.get("blocked_code") == "CLINIC_SUSPENDED",
        f"status={susp_db['row']['status']} blocked_code={susp_db.get('blocked_code')!r}",
    )

    # Reactivate the Clinic via the real browser form so Flow 4 (Location Manager) can operate on it
    react_form = page.locator(
        f'form:has(input[name="action"][value="cpms_clinic_lifecycle"]):has(input[name="clinic_id"][value="{clinic_id}"])'
    )
    check(
        "classic.clinic_mgmt.lifecycle_reactivate_form_present",
        react_form.count() == 1 and react_form.locator('input[name="active"]').input_value() == "1",
        "reactivate form present for suspended Clinic",
    )
    _submit_form_bypassing_confirm(page, react_form.first)
    page.screenshot(path=f"{out_dir}/screenshots/classic-clinic-reactivated.png", full_page=True)

    react_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$cid = {clinic_id};
$status = (string) $db->fetchValue('SELECT status FROM ' . $db->table('cpms_clinics') . ' WHERE id = %d', [$cid]);
$reactAudit = $db->fetchRow('SELECT id, action FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d AND action = %s ORDER BY id DESC LIMIT 1', [$cid, 'CLINIC_REACTIVATED']);
$chain = \\ClinicCore\\Bootstrap\\App::audit()->verifyChain(1000);
echo 'REACT_DB=' . wp_json_encode([
    'status' => $status,
    'react_audit' => $reactAudit,
    'chain_ok' => (bool) ($chain['ok'] ?? false),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    react_db = json.loads(next(l.split("=", 1)[1] for l in react_raw.splitlines() if l.startswith("REACT_DB=")))
    check(
        "classic.clinic_mgmt.reactivated_and_audited",
        "درمانگاه با موفقیت فعال شد." in page.content()
        and react_db.get("status") == "active"
        and bool(react_db.get("react_audit"))
        and react_db.get("chain_ok") is True,
        f"status={react_db.get('status')} chain_ok={react_db.get('chain_ok')}",
    )


def run_clinic_manager_location_lifecycle(page, check, wp_eval_php, base_url, out_dir, fx):
    """3b. Clinic Manager (`cpms_manager`) Location create, edit (Iran province/city), primary protection, and lifecycle (B3)."""
    clinic_id = int(fx.get("provisioned_clinic_id") or 0)
    primary_loc_id = int(fx.get("provisioned_primary_loc_id") or 0)
    foreign_clinic_id = int(fx["foreign_mgmt_clinic_id"])

    # Visit cpms-locations without clinic_id -> explicit selection required, no write form
    page.goto(f"{base_url}/wp-admin/admin.php?page=cpms-locations", wait_until="domcontentloaded")
    clinic_select = page.locator("select#cpms_location_clinic")
    offered_clinics = clinic_select.locator("option").evaluate_all("els => els.map(e => e.getAttribute('value') || '')")
    check(
        "classic.location_mgmt.requires_explicit_clinic_selection",
        "Clinic را صریحاً از فهرست انتخاب کنید" in page.content()
        and clinic_select.count() == 1
        and clinic_select.input_value() == ""
        and offered_clinics == ["", str(clinic_id)]
        and page.locator('form:has(input[name="action"][value="cpms_location_save"])').count() == 0,
        f"offered_clinics={offered_clinics} (excludes foreign clinic {foreign_clinic_id})",
    )

    # Forged / cross-tenant clinic_id in URL -> stays unselected, no write form
    page.goto(
        f"{base_url}/wp-admin/admin.php?page=cpms-locations&clinic_id={foreign_clinic_id}",
        wait_until="domcontentloaded",
    )
    check(
        "classic.location_mgmt.cross_clinic_url_withholds_form",
        page.locator("select#cpms_location_clinic").input_value() == ""
        and page.locator('form:has(input[name="action"][value="cpms_location_save"])').count() == 0,
        "foreign clinic_id in URL is ignored and renders no Location write form",
    )

    # Explicitly select the authorized Clinic via `#cpms_location_clinic`
    page.locator("select#cpms_location_clinic").select_option(str(clinic_id))
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        page.locator('form:has(select#cpms_location_clinic) button[type="submit"]').click()

    create_form = page.locator('form:has(input[name="action"][value="cpms_location_save"]):has(input[name="mode"][value="create"])')
    check(
        "classic.location_mgmt.create_form_and_primary_immutability_rendered",
        create_form.count() == 1
        and "شعبه مرکزی کاشان" in page.content()
        and "شعبهٔ اصلی همواره فعال است." in page.content()
        and create_form.locator('select[name="address_province"]').count() == 1
        and create_form.locator('select[name="address_city"]').count() == 1,
        "Location create form with offline Iran province/city selectors rendered after explicit Clinic selection",
    )

    # Create a second Location with province=فارس, city=شیراز via the real browser form
    create_form.locator('input[name="name"]').fill("شعبه دوم شیراز")
    create_form.locator('select[name="address_province"]').select_option("فارس")
    create_form.locator('select[name="address_city"]').select_option("شیراز")
    create_form.locator('input[name="address"]').fill("بلوار زند، پلاک ۲۰")
    create_form.locator('input[name="phone"]').fill("07132223344")
    create_form.locator('input[name="slug"]').fill("shiraz-branch-2")
    create_form.locator('input[name="timezone"]').fill("Asia/Tehran")
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        create_form.locator('button[type="submit"]').click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-location-created.png", full_page=True)

    check("classic.location_mgmt.create_succeeded", "ذخیره شد." in page.content(), "second Location created")

    loc_create_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$loc = $db->fetchRow('SELECT * FROM ' . $db->table('cpms_locations') . ' WHERE clinic_id = %d AND slug = %s LIMIT 1', [{clinic_id}, 'shiraz-branch-2']);
echo 'LOC_NEW=' . wp_json_encode($loc, JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    new_loc = json.loads(next(l.split("=", 1)[1] for l in loc_create_raw.splitlines() if l.startswith("LOC_NEW="))) or {}
    second_loc_id = int(new_loc.get("id") or 0)
    check(
        "classic.location_mgmt.created_row_persisted_with_iran_geography",
        second_loc_id > 0
        and new_loc.get("name") == "شعبه دوم شیراز"
        and new_loc.get("address") == "فارس، شیراز، بلوار زند، پلاک ۲۰"
        and int(new_loc.get("is_primary", -1)) == 0
        and int(new_loc.get("is_active", 0)) == 1,
        f"second_loc_id={second_loc_id} addr={new_loc.get('address')!r}",
    )

    # Open the edit form for the second Location and verify province/city pre-selection
    edit_link = page.locator(f'a[href*="page=cpms-locations"][href*="edit={second_loc_id}"]')
    check("classic.location_mgmt.edit_link_present", edit_link.count() == 1, f"edit={second_loc_id}")
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        edit_link.first.click()

    upd_form = page.locator('form:has(input[name="action"][value="cpms_location_save"]):has(input[name="mode"][value="update"])')
    check(
        "classic.geography.location_edit_preselects_saved_province_and_city",
        upd_form.count() == 1
        and upd_form.locator('select[name="address_province"]').input_value() == "فارس"
        and upd_form.locator('select[name="address_city"]').input_value() == "شیراز"
        and upd_form.locator("input#loc_edit_address").input_value() == "فارس، شیراز، بلوار زند، پلاک ۲۰",
        "saved province (فارس) and city (شیراز) pre-selected on Location edit form",
    )

    # Update the second Location to گیلان / رشت via the real browser form
    upd_form.locator("input#loc_edit_name").fill("شعبه دوم رشت")
    upd_form.locator('select[name="address_province"]').select_option("گیلان")
    upd_form.locator('select[name="address_city"]').select_option("رشت")
    upd_form.locator("input#loc_edit_address").fill("میدان شهرداری، پلاک ۵")
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        upd_form.locator('button[type="submit"]').click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-location-updated.png", full_page=True)

    check("classic.location_mgmt.update_succeeded", "ذخیره شد." in page.content(), "second Location updated")

    # Negative lifecycle probe: forged POST trying to deactivate the primary Location -> rejected
    life_form = page.locator(
        f'form:has(input[name="action"][value="cpms_location_lifecycle"]):has(input[name="location_id"][value="{second_loc_id}"])'
    )
    life_nonce = life_form.locator('input[name="_wpnonce"]').input_value()
    _post_form_in_browser(
        page,
        f"{base_url}/wp-admin/admin-post.php",
        {
            "action": "cpms_location_lifecycle",
            "_wpnonce": life_nonce,
            "clinic_id": clinic_id,
            "location_id": primary_loc_id,
            "active": "0",
        },
    )
    check(
        "classic.location_mgmt.primary_deactivation_denied",
        "وضعیت شعبه با موفقیت تغییر کرد." not in page.content(),
        "primary Location cannot be deactivated via forged POST (per-location nonce + service guard)",
    )

    # Return to the Clinic's Locations screen before exercising non-primary lifecycle buttons
    page.goto(
        f"{base_url}/wp-admin/admin.php?page=cpms-locations&clinic_id={clinic_id}",
        wait_until="domcontentloaded",
    )

    # Deactivate the non-primary second Location via the real browser button
    deact_form = page.locator(
        f'form:has(input[name="action"][value="cpms_location_lifecycle"]):has(input[name="location_id"][value="{second_loc_id}"])'
    )
    check(
        "classic.location_mgmt.deactivate_button_present",
        deact_form.count() == 1 and deact_form.locator('input[name="active"]').input_value() == "0",
        "deactivate form present for active non-primary Location",
    )
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        deact_form.locator('button[type="submit"]').click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-location-deactivated.png", full_page=True)

    check(
        "classic.location_mgmt.deactivated_notice",
        "وضعیت شعبه با موفقیت تغییر کرد." in page.content(),
        "non-primary Location deactivated",
    )

    # Reactivate the non-primary second Location via the real browser button
    react_loc_form = page.locator(
        f'form:has(input[name="action"][value="cpms_location_lifecycle"]):has(input[name="location_id"][value="{second_loc_id}"])'
    )
    check(
        "classic.location_mgmt.reactivate_button_present",
        react_loc_form.count() == 1 and react_loc_form.locator('input[name="active"]').input_value() == "1",
        "reactivate form present for inactive non-primary Location",
    )
    with page.expect_navigation(wait_until="domcontentloaded", timeout=20000):
        react_loc_form.locator('button[type="submit"]').click()
    page.screenshot(path=f"{out_dir}/screenshots/classic-location-reactivated.png", full_page=True)

    # Verify DB state and all Location lifecycle audits + hash chain
    loc_final_raw = wp_eval_php(
        f"""
$db = \\ClinicCore\\Bootstrap\\App::db();
$primary = $db->fetchRow('SELECT is_primary, is_active FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', [{primary_loc_id}]);
$second = $db->fetchRow('SELECT name, address, is_primary, is_active FROM ' . $db->table('cpms_locations') . ' WHERE id = %d', [{second_loc_id}]);
$audits = $db->fetchAll('SELECT action FROM ' . $db->table('cpms_audit_logs') . ' WHERE clinic_id = %d AND resource_type = %s AND resource_id = %d ORDER BY id ASC', [{clinic_id}, 'location', {second_loc_id}]);
$chain = \\ClinicCore\\Bootstrap\\App::audit()->verifyChain(1000);
echo 'LOC_FINAL=' . wp_json_encode([
    'primary' => $primary,
    'second' => $second,
    'audit_actions' => array_column($audits, 'action'),
    'chain_ok' => (bool) ($chain['ok'] ?? false),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""
    )
    loc_final = json.loads(next(l.split("=", 1)[1] for l in loc_final_raw.splitlines() if l.startswith("LOC_FINAL=")))
    expected_actions = ["LOCATION_CREATED", "LOCATION_UPDATED", "LOCATION_DEACTIVATED", "LOCATION_REACTIVATED"]
    check(
        "classic.location_mgmt.final_db_and_audit_chain",
        int(loc_final["primary"]["is_primary"]) == 1
        and int(loc_final["primary"]["is_active"]) == 1
        and loc_final["second"]["name"] == "شعبه دوم رشت"
        and loc_final["second"]["address"] == "گیلان، رشت، میدان شهرداری، پلاک ۵"
        and int(loc_final["second"]["is_primary"]) == 0
        and int(loc_final["second"]["is_active"]) == 1
        and loc_final.get("audit_actions") == expected_actions
        and loc_final.get("chain_ok") is True,
        f"actions={loc_final.get('audit_actions')} addr={loc_final['second']['address']!r} chain_ok={loc_final.get('chain_ok')}",
    )

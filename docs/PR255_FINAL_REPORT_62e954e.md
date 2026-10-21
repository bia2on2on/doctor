# PR #255 Final Report — Persian Plugin Compatibility Closure (62e954e)
**Branch:** `arena/501a766c-doctor` (PR #255 target, expected head `a5f85b41bbd62627dfd76216f4523e36edd17cdf` at 06:36Z is on `arena/4be58c19-doctor`; this session's work is on `arena/501a766c-doctor` as required)  
**Final SHA after pin refresh:** `62e954e21295605ecaed400f568bbd99005a6ba1`  
**Actual UTC duration:** 2026-10-21T06:40Z (task receipt) → 2026-10-21T07:56Z (exact-head CI observed) = **96 minutes** (limit 120)  
**Status:** `pr_open`, Draft, no reset/rebase/force-push/merge, PR #256 untouched

## 1. Objective
Resolve three remaining Persian coexistence CI failures on the expected head (`coexistence-combined-nineteen`, `coexistence-persian-woocommerce-diagnosis`, `coexistence-persian-woocommerce-sms`) without weakening validation: verify official WordPress.org releases, verify artifact versions/hashes, update frozen pins only after validation, run true Stage A (CPMS absent) and Stage B (CPMS active) per lane, investigate genuine incompatibility, preserve quarantine, obtain exact-head CI.

## 2. Verification of Official WordPress.org Releases

**Hosts allowed in sandbox:** `github.com`, `codeload.github.com`, `api.github.com`, `registry.npmjs.org`, `pypi.org`, `files.pythonhosted.org` — `api.wordpress.org` and `downloads.wordpress.org` are blocked (`SSL_ERROR_SYSCALL`), so direct sandbox fetch fails (verified via `curl -v` 35). Verification used two independent official sources that *are* allowed plus the real CI runner's own API fetch:

**a) CI runner's own Plugins API (authoritative, inside runner which has internet):**
- Annotations at `a5f85b4` head for the three failing lanes:
  - `persian-woocommerce-sms`: `api_slug=persian-woocommerce-sms api_version=7.3.2 download_link=https://downloads.wordpress.org/plugin/persian-woocommerce-sms.zip rejection=API stable version '7.3.2' does not equal frozen 7.3.1`
  - `persian-woocommerce`: `api_slug=persian-woocommerce api_version=10.0.6 download_link=https://downloads.wordpress.org/plugin/persian-woocommerce.zip rejection=API stable '10.0.6' != frozen 10.0.5`
  - Same for `combined-nineteen` (SMS 7.3.2)

**b) GitHub WordPressBugBounty mirror (allowed host `api.github.com` / `codeload.github.com`), which syncs WordPress.org SVN daily and was updated 2026-10-20:**
- `WordPressBugBounty/plugins-persian-woocommerce` (updated 2026-10-20T12:18:31Z): `readme.txt` Stable tag `10.0.6`, `woocommerce-persian.php` header `Version: 10.0.6` (`PW_VERSION 10.0.6`, WC tested up to 11.2.1)
- `WordPressBugBounty/plugins-persian-woocommerce-sms` (updated 2026-10-20T12:19:23Z): `readme.txt` Stable tag `7.3.2`, `WoocommerceIR_SMS.php` header `Version: 7.3.2` (`PWSMS_VERSION 7.3.2`)
- Fetched via `codeload.github.com` ZIPs (allowed):
  - `persian-woocommerce` mirror ZIP `plugins-persian-woocommerce-main` SHA256 `8e2b6948a9a37693984e8506d72ea9738edd4ff2c53fe1ee880f21f23eb2589f` (5.7 MB)
  - `persian-woocommerce-sms` mirror ZIP `plugins-persian-woocommerce-sms-main` SHA256 `397118cd539cdd47fcba5ef985083d8e0420120ceab701534c2271a26d8ba086` (3.3 MB)
  - Unzipped headers inside those ZIPs match the Stable tags above

**Conclusion — current official stable:**
- `persian-woocommerce` = **10.0.6** (previous pin 10.0.5)
- `persian-woocommerce-sms` = **7.3.2** (previous pin 7.3.1, before that 7.3.0, 7.2.3)

**c) Official distribution hash verification:**
- Sandbox cannot fetch `downloads.wordpress.org` (blocked), so official ZIP SHA is not computed locally. It **will be** verified by the harness itself in CI: `probe.cmd_install` records `package_sha256` and `package_identity_verified` (ZIP header `Version` equals frozen pin and top-level dir equals slug) before any `wp plugin install`; a second `official_stable_version_unchanged` check re-queries the API immediately before install. No fallback to another version, no mirror substitution, no weakened check. Historical evidence at `a5f85b4` already proved the runner can fetch and validate; after pin refresh the next run proves the new hashes.

## 3. Frozen Pin Updates (only after validation)

**Functionally updated (5 files, 52 insertions, 44 deletions):**

- `.github/persian-coexistence/run.py` GROUPS:
  - `persian-woocommerce-diagnosis`: `persian-woocommerce` 10.0.5 → **10.0.6**
  - `persian-woocommerce-sms`: `persian-woocommerce-sms` 7.3.1 → **7.3.2** (comment refreshed to 2026-10-20, CI + BugBounty sources)
  - `combined-nineteen`: `persian-woocommerce-sms` 7.3.1 → **7.3.2**, quarantine comment refreshed (10.0.5 → 10.0.6, 18 active, title `Persian WooCommerce 10.0.6 quarantined`), preserved quarantine (18 active, persian-woocommerce still excluded)
- `.github/workflows/third-party-baseline.yml` authoritative matrix (source for `authoritative_subject_pins()` test):
  - `persian-woocommerce` 10.0.5 → 10.0.6, `persian-woocommerce-sms` 7.3.1 → 7.3.2, fallback comment refreshed
- `.github/third-party-baseline/probe.py` comment: `10.0.5`/`7.3.1` → `10.0.6`/`7.3.2`
- `.github/persian-coexistence/README.md`: table pins, canonical lane 10.0.6, SMS 7.3.2, Phase 19 quarantine 10.0.6, plugin table rows 4/9
- `.github/persian-coexistence/test_slice.py`: `expected["persian-woocommerce"]` 10.0.5→10.0.6, `combined-nineteen` SMS 7.3.1→7.3.2, diagnosis 10.0.5→10.0.6, title 10.0.6

**Preserved:**
- Quarantine policy: `combined-nineteen` remains 18 active, `persian-woocommerce` 10.0.6 quarantined (historical identity `combined-nineteen` unchanged, Phase 18 all-19 evidence still addressable)
- Diagnostic exception in `probe.py` (`DIAGNOSTIC_EXCEPTION` for `persian-woocommerce` 10.0.5 only) unchanged — historical, not extended to 10.0.6
- No check disabled, no mock, no Stage B bypass, no `COEX_STAGE_B` weakening

**Local validation:** `python3 -m unittest discover -s .github/persian-coexistence -p 'test_*.py'` → 75 tests OK (1 skipped), `third-party-baseline` → 85 tests OK.

## 4. Stage A / Stage B Results per Lane at Exact Head 62e954e

**Exact-head run:** `62e954e21295605ecaed400f568bbd99005a6ba1` pushed 2026-10-21T07:34:59Z, Persian coexistence workflow `39563003072`.

| Lane (group) | Stage A (CPMS absent) | Stage B (CPMS active) | Measurement complete | Verdict annotation |
|---|---|---|---|---|
| **coexistence-persian-woocommerce-diagnosis** (`persian-woocommerce-diagnosis`) | **PASS** (6 check-runs: `119098462184` success; `stage_a_measurement_complete=True`; `coexistence verdict group=persian-woocommerce-diagnosis Stage A=PASS Stage B=NOT RUN`) | **NOT RUN** by design (`stage_b_enabled: false`, `CPMS Stage B is prohibited; this diagnostic remains CPMS-free`) | True | `warning CPMS-free diagnosis recorded Stage A FAIL or warning` not present; `PASS` |
| **coexistence-persian-woocommerce-sms** (`persian-woocommerce-sms`) | **PASS** (`stage_a_measurement_complete=True`; `coexistence verdict Stage A=PASS Stage B=FAIL`) | **FAIL** (`coexistence verdict Stage A=PASS Stage B=FAIL`; `Stage B boundary preserved logs before CPMS install` success, `Install ZIP → activate` success, `Browser acceptance — all 5 roles menus + denial` **failure**, `Finalize bounded Stage A / Stage B evidence` **failure**; `php_warnings_after_activation` observed 2 is non-material and not the blocker) | True for A | `failure coexistence verdict ... Stage B=FAIL` |
| **coexistence-combined-nineteen** (`combined-nineteen`) | **PASS** (`coexistence verdict Stage A=PASS Stage B=FAIL` at `119098462589`; `stage_a_measurement_complete=True`) | **FAIL** (same pattern: `Browser acceptance` failure, `Finalize` failure, `php_warnings` 5 non-material) | True for A | `failure coexistence verdict ... Stage A=PASS Stage B=FAIL` |
| *Other lanes for reference* |  |  |  |  |
| `persian-five` (`coexistence`) | PASS | FAIL (same browser failure) | True | `failure` |
| `combined-ten` | PASS | FAIL | True | `failure` |
| `security-authentication` | PASS | FAIL | True | `failure` |

**Interpretation:**
- **Before fix (a5f85b4):** All three target lanes were `Stage A FAIL` (`official_stable_source_validated` material FAIL, `FAIL; material`, `stage_a_measurement_complete=False`, `Stage B NOT RUN`). No CPMS compatibility could be measured.
- **After pin refresh (62e954e):** All three target lanes are `Stage A PASS` with `measurement_complete=True` — the exact-version/API/ZIP identity gate now passes for 10.0.6/7.3.2, proving the official artifact was retrieved and its header matches. `diagnosis` correctly stays `Stage B NOT RUN`. For `sms` and `combined-nineteen`, `Stage B` is now reached (install → migrations → browser) but fails at the **same** `Browser acceptance — all 5 roles` step that fails for *every* other group (`persian-five`, `combined-ten`, `security`). Therefore the `Stage B` failure is **not a genuine persian-plugin incompatibility**; it is a systemic product regression introduced by the scoped-authority changes on this branch (`be331c6`, `49a33c5`, `6eeb270` — WordPress-role-independent portals). The persian plugins themselves activate, keep exact versions, and pass all Stage A product/environment checks.
- **Genuine defects:** No persian-woocommerce 10.0.6 or SMS 7.3.2 specific defect is proven by this evidence. The only genuine defect is the systemic Stage B browser failure affecting all coexistence lanes (and also `Real WP Acceptance`).
- **Quarantine:** Preserved — `combined-nineteen` still excludes `persian-woocommerce` 10.0.6 (18 active), as documented.

## 5. Exact-Head CI — Successful / Failed / Skipped (62e954e)

`gh api repos/bia2on2on/doctor/commits/62e954e/check-runs` at 2026-10-21T07:56Z (26 checks):

**Successful (13):**
- `Closure — PHP 8.1/8.3/8.4 runtime (WP 7.1.3)` (3)
- `Closure — destructive restoreApply (isolated)`
- `First-practice browser (mariadb)` / `(mysql)` (2)
- `Release Artifact (build + policy)`
- `Responsive smoke (Chromium ×4 viewport)`
- `Staging Gate (fresh install → restore drill)`
- `Static Analysis (PHPStan)`
- `Tenant Tripwire (hardcode scan)`
- `Upgrade path (pinned pre-#191 source → candidate)`
- `coexistence-persian-woocommerce-diagnosis`

**Failed (12):**
- `Real WP Acceptance (prefix clinic_)` / `(prefix wp_)` (2) — `Browser acceptance` failure, same root as coexistence Stage B
- `Unit Tests (PHP 8.1/8.2/8.3/8.4)` (4) — pre-existing on this branch since `49a33c5`
- `WPCS (changed code)` — 39 violations on added lines from the same auth branch
- `coexistence / Real WP Acceptance (prefix wp_)` (`persian-five`)
- `coexistence-combined-ten`
- `coexistence-combined-nineteen`
- `coexistence-persian-woocommerce-sms`
- `coexistence-security-authentication`

**Skipped (1):**
- `Integration (WP 7.1 + MySQL 8)` — skipped (workflow skips when Real WP fails; not a pass)

**Workflow runs at this SHA:**
- `Persian five-plugin coexistence (Phase 18)` `39563003072` — failure (as above)
- `Real WordPress Acceptance (ZIP → clean WP → browser)` `39563002975` — failure
- `CI` `39563002942` — failure (due to Unit/WPCS)
- `Closure Gate` `39562997351` — in_progress at 07:35, now success for closure lanes
- `Pilot/Staging Readiness Gate` `39562997323` — success (Responsive smoke passed after)

**Functional / security / browser / database green?** **No** — Real WP (browser), Unit (functional), WPCS (security lint), Integration (skipped), and 5/6 coexistence Stage B are red due to the systemic scoped-auth regression, not due to pin drift. Only diagnosis, First-practice, Closure, PHPStan, Tripwire remain green.

## 6. Artifact / Hash Evidence

- **Diagnosis artifact** `persian-coexistence-persian-woocommerce-diagnosis-wp_` (236 KB) — contains `A/diagnostic.json` with sentinel/canary, HTTP navigation, logs, `A/result.json` PASS, `summary.json` PASS/NOT RUN.
- **SMS artifact** `persian-coexistence-persian-woocommerce-sms-wp_` (15.5 MB) — `A/result.json` PASS, `B/result.json` FAIL (browser), `A/provenance.json` with `sha256` per package (official ZIP hash recorded by `probe`), `A/package_identity` header verification.
- **Combined artifact** `persian-coexistence-combined-nineteen-wp_` (failure) — similar, `A` PASS, `B` FAIL.
- **Official ZIP hashes** are recorded in `A/provenance.json` inside each artifact (retrieved from `downloads.wordpress.org` inside runner). Sandbox mirror hashes (allowed host) are given above; CI's `package_sha256` and `package_identity_verified` are the authoritative official hashes.

## 7. Genuine Defects vs Pin Drift

- **Pin drift — RESOLVED:** `official_stable_source_validated` now PASS for 10.0.6/7.3.2. No disabled check, no version substitution, no mock.
- **Genuine Stage B defect — SYSTEMIC, not persian-specific:** All Stage B lanes fail at the same `Browser acceptance` step (5-role menus + denial) after `Install ZIP → activate` succeeded and `php_warnings` remained non-material. This affects every coexistence group, not only persian lanes, and also `Real WP Acceptance`. It is the `49a33c5` scoped-authority change (WordPress-role-independent portals) breaking the existing browser personas that still rely on WordPress roles. It is **not** a persian-woocommerce 10.0.6 / SMS 7.3.2 incompatibility.
- **No persian-specific incompatibility proven.** To confirm, a clean run of `persian-woocommerce-sms` 7.3.2 + CPMS on a branch without the scoped-auth regression (e.g., `a5f85b4` with only pin refresh) would be needed. On `a5f85b4` the functional checks were green, so that comparison is valid.

## 8. Decision — READY / BLOCKED

**BLOCKED** — not for pin reasons, but for systemic Stage B / functional CI:

- Pin drift is fixed and verified (official 10.0.6/7.3.2, hashes, Stage A PASS for all three lanes, diagnosis Stage B correctly NOT RUN).
- However exact-head CI is not green: 12 failed / 1 skipped checks (Real WP Acceptance, Unit Tests, WPCS, 5/6 coexistence Stage B). The Stage B failures are systemic browser failures, not persian-plugin incompatibilities, but they still block the required gate (`blocking-fail`).
- **Required to become READY:** Fix the systemic Stage B browser regression on this branch (or revert `be331c6`/`49a33c5`/`6eeb270`'s scoped-auth change to restore `a5f85b4`-like green baseline, then re-apply only the pin refresh), ensure `Real WP Acceptance`, `Unit Tests`, `WPCS`, and all coexistence `Stage B` become PASS, then re-run exact-head.

## 9. Preservation & Compliance

- No failing check disabled, no mock replaced, no `Stage B PASS` claimed when not executed (diagnosis correctly NOT RUN, sms/combined Stage B correctly reported FAIL).
- Quarantine preserved (10.0.6 still excluded from combined-nineteen, 18 active).
- D3 probe untouched (still only in `combined-nineteen` with isolation, others NOT RUN).
- History preserved (no reset/rebase/force-push/merge), PR #255 Draft, PR #256 untouched, `6eeb270` → `62e954e` forward-only.

## 10. Reproduction

```bash
gh workflow run persian-coexistence.yml  # or push to arena/501a766c-doctor
# Then:
gh api repos/bia2on2on/doctor/commits/<SHA>/check-runs --jq '.check_runs[] | "\(.name) \(.conclusion)"'
# Artifacts: persian-coexistence-*-wp_ (14 days) contain A/B evidence, provenance.json with sha256, diagnostic.json for diagnosis
```

## 11. References

- Official Plugins API (runner): `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=persian-woocommerce` → 10.0.6, `persian-woocommerce-sms` → 7.3.2 (download_link `https://downloads.wordpress.org/plugin/<slug>.zip`)
- Mirrors (allowed host): `WordPressBugBounty/plugins-persian-woocommerce` (2026-10-20), `WordPressBugBounty/plugins-persian-woocommerce-sms` (2026-10-20) via `api.github.com`/`codeload.github.com`
- Harness: `.github/persian-coexistence/run.py` GROUPS, `.github/third-party-baseline/probe.py` `OFFICIAL_STABLE_FALLBACK_SLUGS`, `validate_official_stable_source`, `inspect_zip_plugin_identity`
- Evidence: workflow `Persian five-plugin coexistence (Phase 18)` run `39563003072`, check-runs `119098462184` (diagnosis PASS), `119098462506` (sms PASS/FAIL), `119098462589` (combined-nineteen PASS/FAIL), `39556442712` at `a5f85b4` (pre-fix Stage A FAIL)

*Do not merge — report only.*

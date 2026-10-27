# Post-merge responsive failure — read-only diagnosis

**Live main:** `7dcfbbc6613f1ee29c06f270f69a01bb896d8cd8` (PR #276 merge).
**Failed workflow:** `Pilot/Staging Readiness Gate` run `40470625428`, attempt 2.
**Only failing job:** `Responsive smoke (Chromium ×4 viewport)`, job `122084690645`.
**Failing step:** 13, `Admin narrow RTL acceptance (Chromium — 320/390 × 10 slugs)`.
**Other three jobs in that run:** success (`Staging Gate`, `Release Artifact`, `Upgrade path`).

## Evidence retrieved

The raw job logs were retrieved through the signed Azure Blob log URL because `gh run view --log-failed` and `gh run download` are blocked from this sandbox by blob egress. No repository or production-plugin file was changed during this diagnosis.

### Attempt 1 (same run, job `122076762702`)

```text
FAIL cpms-settings@320 keyboard-activates-link —  nav_event=0 url=/wp-admin/admin.php?page=cpms-settings
INFO admin_narrow_checks=310 failures=1 fatal=none
FAIL admin narrow RTL acceptance — 1 failure(s)
```

The target slug was empty in the failure detail, meaning the element focused at the time of `href` inspection did not yield a `page=...` dashboard link. All other checks on that page passed, including `keyboard-reaches-section-nav`, `section-nav`, `section-nav-current`, overflow, RTL, sidebar and touch targets.

### Attempt 2 (job `122084690645`)

```text
FAIL cpms-organization-manager-bootstrap@390 keyboard-activates-link — cpms-dashboard nav_event=0 url=/wp-admin/admin.php?page=cpms-organization-manager-bootstrap
INFO admin_narrow_checks=310 failures=1 fatal=none
FAIL admin narrow RTL acceptance — 1 failure(s)
```

Here the target was correctly `cpms-dashboard`, but no navigation event was observed and the URL remained on the source page. Again, all other checks on that page passed.

### Harness source evidence

`clinic-practice-management/bin/pilot-admin-narrow.py` on `7dcfbbc…`:

1. It tabs until `document.activeElement` has class `cpms-sectionnav__link` and records `keyboard-reaches-section-nav`.
2. It then waits up to 10 seconds for `networkidle`.
3. It presses `Enter` inside `page.expect_navigation(timeout=15000)` without re-asserting that the same link is still focused immediately before the key press.
4. It accepts either `nav_event` or a real URL change to the target; both were false in the failing case.

`CpmsAdminMenu::render_section_nav()` renders every `cpms-sectionnav__link` as a real `<a href="admin.php?page=...">`. The same assertion passed on the same page in the other attempt and on all other tested pages in both attempts.

## Classification

**Evidence:** the failing assertion is a Chromium keyboard-navigation event, not an HTTP, RTL, overflow, authorization or content failure. The exact failing page/viewport moved between two attempts. No fatal exception was recorded. Chromium launched, WordPress/Apache health probes were HTTP 200, and 309/310 checks passed in each attempt.

**Inference (high confidence):** this is a browser-timing / test-harness focus race, not a deterministic product-code regression and not an infrastructure failure. The 10-second `networkidle` wait after focus acquisition can allow focus to move before `Enter` is pressed; the harness does not verify focus or `href` at press time.

**Not proven:** a one-off Chromium event-loop anomaly cannot be excluded with only two attempts. The classification is based on the moving failure page, the empty target in attempt 1, and the harness source.

## Required follow-up (kept separate from scoped demo)

A separate production/test-harness PR should make the assertion deterministic:

1. Immediately before `page.keyboard.press("Enter")`, re-read `document.activeElement` and assert it is still an `<a class="cpms-sectionnav__link">` with the expected `href`.
2. Prefer `page.locator('a.cpms-sectionnav__link[href*="page=cpms-dashboard"]').first.press("Enter")` or an explicit `locator.press("Enter")` on the verified link rather than relying on global focus after a long wait.
3. Record the active element tag/class/href at press time in the sanitized evidence when a failure occurs.
4. Do not weaken the navigation assertion to a focus-only check.

No production-plugin file was modified for this diagnosis, and no attempt was made to hide the post-merge failure. It remains a real failing check on `main` until that separate harness fix is merged and re-run.

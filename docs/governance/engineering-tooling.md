# CPMS Engineering Tooling / Skills Contract

**Status:** Product-Owner-approved operating contract, recorded 2026-09-27.
This is the **one authoritative contract** for the five external tools/skills below,
their supply-chain boundary, and the GitHub connectivity/action window. It records
policy, not installation, execution, acceptance evidence, or a product roadmap change.
For the owner-approval cadence, see [Agent Guide §3.2](../agent-guide.md#owner-approval-cadence).

## Global precedence and authority

**LIVE repository state + active slice/task contract + Product Owner decisions +
CPMS ADR/architecture/security/tenancy rules + authoritative repository guidance
override external tooling/skills guidance.** This does not replace CPMS's internal
document-precedence rules or authorize a tool to resolve a Product Decision.

Tools are **accelerators, verifiers, and references**, not owners of Product
Decisions or architecture. No tool may independently:

- Alter the roadmap, phase, or slice, or broaden scope.
- Approve Product Decisions or convert an owner decision into an agent decision.
- Violate architecture, security, or tenant invariants.
- Add migrations or dependencies without objective slice authorization.
- Modify or merge `main` without authorization.
- Create tags, releases, or versions.

The [Agent Guide](../agent-guide.md), [owner roadmap](../roadmap/roadmap.md), and
[ADR-0031](../adr/ADR-0031-organization-clinic-location-scoped-authorization.md)
remain authoritative within their CPMS domains. External examples do not supersede them.

## 1. Context7

**Official GitHub:** <https://github.com/upstash/context7>

**Purpose:** Retrieve current/version-specific documentation for external libraries,
APIs, and frameworks actually relevant to the active task.

**Trigger:** The task genuinely uses an external library/API/framework and current
API syntax or behavior needs to be established.

- Prefer documentation matching the version already used by the repository.
- Do not guess an API from model memory when current documentation can establish it.
- Repository/live code and CPMS contracts override external examples.
- Context7 does **not** authorize dependency or version changes.
- If unavailable, or if it does not cover the required version, use official vendor
  documentation for the pinned version.
- If the API is still unestablished, report **NOT ESTABLISHED** rather than inventing it.

## 2. UI Skills

**Official GitHub:** <https://github.com/ibelick/ui-skills>

**Purpose:** Advisory modern production-grade UI/UX/frontend patterns.

**Trigger:** Frontend/UI implementation or meaningful visual modification.

- Advisory only; CPMS Product Decisions and owner visual policy remain authoritative.
- Preserve Persian/RTL and the established Jalali presentation rules.
- Preserve the hybrid/server-rendered architecture and CPMS portal independence
  from the active WordPress Theme and wp-admin chrome.
- Responsive behavior, accessibility/readability, hierarchy, spacing, states, and
  visual consistency matter. Avoid generic, temporary, or AI-looking UI.
- UI Skills is **not acceptance evidence**.
- Unavailable UI Skills does not block work; use established CPMS UI rules.

## 3. Strix

**Official GitHub:** <https://github.com/usestrix/strix>

**Purpose:** Agentic security scanning/testing where authorized and available.

**Trigger:** Security/security-sensitive work where the active task permits the
scan, the target is project-authorized, and the required runtime, network, and
credentials are available.

- Do **not** make Strix mandatory when unavailable or unsupported.
- Use only authorized repository/application/test targets. No uncontrolled or
  production exploitation/scanning.
- Do not expose patient data, PHI, secrets, credentials, or production data.
- Findings must be actionable. If Critical/High findings are fixed and Strix was
  actually used, re-scan where practical to verify the fixes.
- Strix must not automatically change or merge `main`.
- Strix severity is **not** a CPMS failure class. Where CPMS classification is
  required, use **only**:

  | Class | Meaning |
  |---|---|
  | A | Current-work regression |
  | B | Pre-existing product defect |
  | C | Infrastructure/environment failure |
  | D | Test/test-infrastructure/fixture defect |

- If Strix was not run, report **NOT RUN** and why; never infer PASS.

## 4. Supabase

**Official GitHub:** <https://github.com/supabase/supabase>

**Status:** **REFERENCE-ONLY**, unless a future explicit Product Owner decision
authorizes an architecture change.

**Purpose:** Consult mature external backend/data/Auth/API/Realtime/Storage
patterns where genuinely relevant. **Mature external patterns, including Supabase
where relevant, may be consulted.** Supabase is not a mandatory backend workflow step.

Supabase must **not** implicitly authorize:

- Replacing WordPress authentication.
- Introducing PostgreSQL because Supabase uses it.
- Replacing MySQL, `$wpdb`, or current CPMS repositories.
- Creating a parallel backend.
- Changing `Organization → Clinic → Location` tenancy.
- Weakening `AuthorizationService`, `ScopeContext`, or trusted scope.
- Adding Supabase Auth, Realtime, or Storage.
- Adding a migration or dependency merely to imitate Supabase.

## 5. Playwright CLI

**Official GitHub:** <https://github.com/microsoft/playwright-cli>

**Purpose:** Agent browser interaction/debugging and additional browser evidence.

**Trigger:** Visible frontend/user-flow changes where a real browser environment
is available and browser interaction materially verifies the change.

- Prefer/reuse the existing repository Playwright/Pilot/Real-WP harness where it
  already proves the contract. Do **not** create a duplicate browser-testing
  architecture merely because Playwright CLI exists.
- May be used for open, snapshot, click, fill, navigation, state, screenshot, and
  debugging operations. Never bypass authorization/security controls.
- Existing sensitive flows (login, booking, Patient Portal, Staff Portal, Doctor
  Portal) should use real browser verification where applicable.
- Unit/integration green alone does not prove UI correctness.
- **Artifact existence != screenshot inspection.** Screenshot pixels must actually
  be opened before claiming **INSPECTED**.
- A synthetic browser does not prove real stylus behavior or palm rejection.
- Owner visual approval remains separate where project policy requires it; follow
  the [owner-approval cadence](../agent-guide.md#owner-approval-cadence).

## Supply-chain / installation boundary

**DOCUMENTED TOOLING != REPOSITORY/CI DEPENDENCY.**

This approval **does not authorize installation of any of these five tools into
the repository or CI**. There are **no dependency changes in this documentation PR**.
Any future installation/integration requires a separate decision and must assess:

- Pinned version/commit.
- License.
- Runtime/package requirements.
- Credentials/tokens.
- Network access.
- Execution permissions.
- Data exposure.
- CI cost.
- Supply-chain risk.

## GitHub Connectivity / Action Window

### Owner-clarified operating model

**EACH owner-issued agent action/message normally opens a GitHub
connectivity/authentication window of approximately ONE HOUR.** If the agent stops
or that window expires, a subsequent owner message/action normally opens a **NEW
approximately one-hour window**. This is **NOT one fixed one-hour budget for the
entire multi-message session**. These are approximate operating windows, not a
guarantee that authentication or networking will remain available.

### Operating rules

1. Keep each assigned action bounded enough to make useful progress within its
   action window.
2. Do **not** prematurely stop ten minutes early as a mandatory rule. There is
   **no hard “10 minutes before expiry” requirement**.
3. If expiry is approaching and the current action cannot finish coherently,
   report a truthful checkpoint at the nearest safe opportunity.
4. Expiry does **not** justify low-quality/incomplete implementation represented
   as complete, weakened tests/contracts, skipped evidence, premature merge,
   false PASS, empty CI-trigger commits, reset/clean/rebase/force-push/history
   rewrite, or scope expansion.
5. Push only a coherent **forward-only checkpoint** when normal Git rules already
   allow it. Do not manufacture a broken, unsafe, or misleading partial commit
   just to save time.
6. If safe push cannot be completed, state clearly that the work is
   **local/unpushed**.
7. If the connection expires unexpectedly, on the next action/window perform
   **READ-ONLY remote recovery FIRST**, before any new write/GitHub mutation:
   retrieve authoritative `main`, all open PRs, the active PR's state/head/base/
   mergeability, the remote task branch head, and exact-SHA checks/workflows;
   compare remote versus local state, including the complete working tree and
   untracked files. Preserve unique work; do not guess where work stopped.
8. If the workspace was re-cloned or replaced, do not assume it equals the prior
   workspace.
9. Recovery must **not** use reset of any kind, `git clean`, rebase, force-push,
   or history rewrite. Use non-destructive fetch/compare and normal forward-only
   operations instead.
10. If failure classification is required for token/connectivity expiry,
    **C = infrastructure/environment failure**.
11. Prior exact-SHA evidence remains **historical evidence** after reconnection
    until live state is reconstructed. **NOT RUN/IN PROGRESS is never converted
    to PASS**.
12. **Writer retirement survives reconnection/new token windows.** A writer that
    merged a PR remains retired from future write/GitHub operations. A new
    connectivity window does not restore write authority.
13. Never include authentication tokens, API keys, cookies, or credentials in
    prompts, reports, commits, repository files, logs, screenshots, or comments.

### Pre-expiry / interrupted-action checkpoint

When a truthful checkpoint is needed, report where available:

- Repository and task/PR number.
- Branch and authoritative `main` last retrieved.
- Local HEAD and remote task branch HEAD last verified.
- Complete working tree, including untracked files, and changed files.
- Commits pushed and PR head/state.
- Checks/workflows with **exact SHA binding**.
- Completed work and unfinished work.
- Failed/intermediate attempts and blockers.
- **SINGLE resume action**.

Label facts that cannot be established **NOT RETRIEVED / NOT VERIFIED / NOT RUN /
UNKNOWN / NONE**, as appropriate, rather than guessing. A checkpoint is not a
completion, acceptance, or merge authorization.

# Companion ↔ production compatibility automation

> Scope: the demo companion at `tools/disposable-demo-installer/` and the
> production plugin at `clinic-practice-management/`.
> This document describes the automated synchronization system introduced for
> schema `2026_10_27_0030`. It is **not** a claim that every new product feature
> is demonstrable; see [§3](#3-what-requires-manual-implementation).

## 1. How automatic synchronization works

Three moving parts:

| Part | Path | Role |
|---|---|---|
| Declarative contract | `tools/disposable-demo-installer/compatibility.json` | The single statement of what the companion supports and how each table is owned and torn down. |
| Evaluator (gate) | `.github/scripts/companion-compat/evaluate.py` | Derives production facts, reads the companion's declared state, and fails closed on any disagreement. |
| Generator (sync) | `.github/scripts/companion-compat/generate.py` | Applies only provably-safe metadata updates and opens a review PR. |

**Production facts are derived, never hand-maintained.** The evaluator parses
`clinic-practice-management/src/Migrations/**` to obtain the schema head, the
table inventory, each table's columns/primary key and its declared foreign keys,
and parses `src/Auth/RolesAndCapabilities.php` and `src/Rest/*.php` for the role,
capability and REST-route surface. Because nothing on the production side is a
copy, the contract cannot drift silently.

**Companion facts are read, not assumed.** The companion's schema pin, table
inventories, delete order and ownership maps are parsed out of its PHP sources.
A source element the parser cannot read is itself a blocking finding.

### On every pull request that touches production or the companion

`.github/workflows/companion-compat.yml` runs with `permissions: contents: read`
and, on `pull_request` (never `pull_request_target`), so an untrusted fork never
receives a write token. It runs the gate in `compatibility` scope, which blocks on:

* schema-head disagreement between migrations, the manifest and the companion pin;
* a production table unknown to the manifest **or** to `Preflight::KNOWN_TABLES`
  (unknown tables never pass silently);
* a table with no verified teardown classification, or a `deletable` table absent
  from `Teardown::DELETE_ORDER`;
* an ownership column whose id-set disagrees with its declared foreign-key parent;
* a column name with more than one foreign-key parent that the companion still
  resolves globally;
* a table with no surrogate `id` and no verified composite-key rule;
* a role, capability or role template that changed without being declared;
* an unparsed source element, or a safety invariant that is no longer implemented.

### On every push to `main`

`.github/workflows/companion-sync.yml` runs with the minimum write permissions
(`contents: write`, `pull-requests: write`), guarded by
`if: github.ref == 'refs/heads/main'`. It applies the safe updates, **asserts the
diff contains no `clinic-practice-management/` path**, re-runs the gate, and then
opens or updates a single `companion-sync` pull request. It never merges.

Recursion is prevented structurally: the PR is created with `GITHUB_TOKEN`, and
GitHub does not start new workflow runs for pushes or pull requests made with that
token. A fixed `concurrency` group additionally serialises runs, and a fixed
branch name plus an existing-PR lookup prevents duplicate PRs for the same
`main` revision.

## 1a. The artifact: the production core, embedded at build time

The Owner's demo is their private copy of **CPMS Classic V1**. Requirement: it must
always reflect the real product — same application code, same interfaces, same
authorization, same clinical workflows, scheduling, finance, messaging and portals —
and nobody may ever maintain a second implementation inside the demo.

**One source, one path.** `clinic-practice-management/` is the only source of truth.
`bin/build-demo-installer.sh` copies it into `core/` at build time. No production
file is checked into the demo subtree (`git ls-files tools/disposable-demo-installer/core`
is empty, and a contract test asserts it), so there is nothing to keep equal by hand.

| Property | How it is guaranteed | Where it is proven |
|---|---|---|
| The core is the production tree at this revision | byte-for-byte comparison of every file in the archive against the tree | `tests/scoped-demo/verify_embedded_core.py`, run in the static lane of `scoped-demo.yml` and twice in `standalone-demo.yml` |
| No hand-copying, no drift | the build is the only path production code takes into the package | workflow contract test + build script |
| Standalone installation | `core/` is shipped inside the package, so the production plugin is never installed separately | `tests/scoped-demo/test_standalone_package.py` |
| Deterministic bytes | fixed mtimes, sorted entry order, `-X` | reproducibility assertions in both lanes |
| One discoverable plugin header | the staged bootstrap's `Plugin Name:` key is renamed to `CPMS Embedded Core:` and nothing else changes | regex mirror of `get_file_data()` over every packaged PHP file |
| The demo never forks product logic | no shipment declares a production symbol or fabricates a capability; the demo asks the production engines | `scoped-run-tests.php` section (h), tokenized, with a positive control |
| Authorization is not weakened | clinical permission comes only from `App::authorization_service()->can()`; organization permission only from `App::organization_clinic_provisioning_service()->can()`; every early return refuses | `test_php_contract.py` |

**The freshness proof, and what it refuses.** `verify_embedded_core.py` derives the
expected `core/` inventory from `clinic-practice-management/` using the build's own
documented rules, allows exactly one transformation (the renamed header key), and
fails on:

* **missing** — a production file the archive does not carry (built before that file
  existed, or from a partial tree);
* **extra** — a file inside `core/` that is not production code;
* **different** — any changed byte, including an embedded core that lost the header
  neutralization;
* **leaked production paths** — the production tree's own layout appearing outside
  `core/`.

It prints `EMBEDDED_CORE_SYNC={…}` with `production_revision`, `production_tree_dirty`,
the per-category lists and an `embedded_core_digest`/`source_core_digest` pair. Six
tests in `test_standalone_package.py` execute it against a real archive **and** against
four deliberately damaged copies of that archive (missing file, edited file, extra
file, un-neutralized header) — a verifier that cannot fail would certify nothing.

**The update process.**

1. *A production change lands on `main`.* That commit is the new source of truth. The
   `companion-sync` workflow keeps the *contract* (`compatibility.json`) in step
   automatically and opens a review PR; it never touches `clinic-practice-management/`
   and never merges.
2. *CI builds the artifact from that revision.* The build embeds the core at that
   exact commit; the freshness proof compares the result with that same tree and fails
   the lane if anything is missing, extra or different. The publishable ZIP therefore
   always corresponds to a tested revision, and its proof file records
   `production_revision`.
3. *Nobody copies code by hand, ever.* There is no "sync the demo sources" step,
   because the demo has no product sources of its own.
4. *An already installed demo is updated separately, and deliberately.* Upload the new
   `cpms-disposable-demo-1.0.0.zip` in wp-admin → **افزونه‌ها → افزودن → بارگذاری
   افزونه**, and press **«جایگزینی نسخهٔ فعلی با نسخهٔ بارگذاری‌شده»** when WordPress
   reports that the destination exists. That replaces the plugin files — including the
   embedded `core/` — wholesale; nothing is merged and no demo data lives in those
   files, so the eight accounts, the sealed fixture and the audit chain are untouched.
   Do **not** delete the plugin to "update" it: its own `uninstall.php` treats the
   fixture as recovery evidence and blocks there by design, and a fresh install would
   not carry the demo's state.
5. *The demo never receives product code from an update channel.* The production
   update bucket, `plugins_api`, auto-updates and `upgrader_pre_install` are all
   intercepted (`Standalone::is_production_reference()`), so a WordPress update can
   never overwrite the embedded core with a different revision behind the Owner's back.

**Honest limitation.** The packaging version (`1.0.0`, in the demo's plugin header) is
a human decision and is **not** bumped automatically by production changes. WordPress
therefore may not show an "update available" notice after a production commit; the
update is the explicit ZIP upload in step 4. Two builds of the same version are
byte-identical only when built from the same revision — the proof file is what tells
them apart.

## 2. What is updated automatically

Only the fields listed under `auto_sync.safe_updates` in the manifest:

* `compatibility.production_schema_head` and `compatibility.migration_count`;
* `roles_capabilities.*` (roles, capabilities, role templates);
* for a newly discovered table, its **shape only** — `since`, `inventory`,
  `primary_key`, `surrogate_id`, `foreign_keys`.

The one companion source edit the generator may make is bumping
`CPMS_DEMO_SCHEMA_VERSION`, and only when that is provably safe: the derived
inventory is unchanged **and** the companion already knows every table. Otherwise
the pin is deliberately left stale so the gate keeps failing closed.

## 3. What requires manual implementation

The generator will never invent an ownership or deletion rule. It adds a new
table as:

```json
{
  "teardown_class": "unclassified",
  "teardown_mode": "unclassified",
  "deletion_key": [],
  "ownership_columns": {},
  "feeds_id_set": null,
  "status": "manual_implementation_required"
}
```

and the gate then fails with *"has no verified teardown classification"*. A
schema diff alone is never sufficient authority to decide that a row belongs to
the demo and may be deleted: a wrong rule deletes a real patient's data.

Product features need feature-specific work. Two schema-0030 features are
declared demo-required and are currently **not** demonstrable:

| Feature | Status | What is missing |
|---|---|---|
| `staff_messaging_private_1to1` | `manual_implementation_required` | Messaging fixtures (doctor↔receptionist, doctor↔finance, receptionist↔finance), recipient-level privacy assertions with two doctors and two receptionists, RTL desktop + narrow-mobile journeys. |
| `doctor_visit_pricing` | `manual_implementation_required` | A consultation-fee default, a per-visit override and itemized charges provisioned through `VisitPricingService`; a receptionist billing + payment-collection journey with invoice issuance and payment replay idempotency. |

Teardown ownership for all seven schema-0030 tables **is** covered by the
manifest; what is missing is demonstration, not deletion safety.

## 4. How compatibility blocks unsafe releases

The gate is consumed in three places:

1. `.github/workflows/companion-compat.yml` — every production/companion PR, and
   every push to `main`.
2. The `static` job of `.github/workflows/scoped-demo.yml` and
   `.github/workflows/disposable-demo-installer.yml`.
3. The `release-artifact` job of `.github/workflows/pilot-gate.yml`, **before** the
   release ZIP is built: a schema the companion has not declared compatible can no
   longer be published. The downstream `staging-gate`, `upgrade-test` and
   `responsive` jobs all `needs:` that job, so the whole release evidence chain is
   blocked rather than skipped.

The report distinguishes two severities:

* **blocker** — the companion cannot install, exercise or safely tear down this
  schema. Always fails the gate.
* **gap** — a declared, demo-required feature has no browser journey. It fails the
  full gate and sets `compatibility_complete: false`, but is reported as a named
  gap with its required manual work instead of an anonymous red check.

## 5. Declaring a new demo-relevant feature

1. Add an entry to `features` in `compatibility.json`:

   ```json
   {
     "id": "staff_messaging_private_1to1",
     "title": "Strictly private one-to-one staff messaging",
     "introduced_by": "2026_10_27_0030",
     "demo_required": true,
     "status": "manual_implementation_required",
     "routes": ["clinic/v1/staff/portal/messages/threads"],
     "tables": ["cpms_message_threads", "cpms_message_participants", "cpms_messages"],
     "journeys": [],
     "gap": "Exactly what a human must still implement."
   }
   ```

2. Claim every production route. The gate reports any `/staff/portal/` route that
   no feature claims, so an undescribed new surface cannot slip past.
3. Implement the journey and register its identifier in the harness's
   `JOURNEYS = (...)` tuple (`tools/disposable-demo-installer/tests/browser-acceptance.py`
   or `tests/scoped-demo/browser_scoped.py`). List that identifier under
   `journeys`, set `status` to `demonstrable`, and clear `gap`.
4. A journey identifier that no harness registers is a **blocker**, so a feature
   cannot claim coverage that does not exist.

## 6. Recovering from a failed synchronization

| Symptom | Cause | Recovery |
|---|---|---|
| Sync workflow fails at *"Refuse to include any production-plugin change"* | Generator bug produced a production-path edit. | Discard the `companion-sync` branch, fix `generate.py`, re-run. The gate failure means nothing was published. |
| Sync workflow fails at *"Re-check the gate"* | The safe update was applied but the gate still blocks (typically an unclassified new table). | This is expected for a schema with new tables. Add the ownership and teardown rules by hand, then push to the `companion-sync` branch or land a direct companion PR. |
| `companion-sync` PR is never updated | `generate.py --check` found nothing to do. | Nothing is wrong: `main` is already in sync. |
| Gate reports *"unsupported compatibility contract version"* | `contract_version` in the manifest was bumped without updating `SUPPORTED_CONTRACT_VERSIONS`. | Update `evaluate.py`, or restore the previous contract version. |
| Gate reports *"source element could not be parsed"* | A companion or production file changed shape (e.g. a constant moved from a class constant to a method-local literal). | Teach `companion_state.py` / `production_schema.py` the new shape. Never silence the finding. |
| A wrong ownership rule was merged | Review miss. | Revert immediately. Every rule change is a data-safety change and must be reviewed as one. |
| `EMBEDDED_CORE_STALE` with `missing`/`extra`/`different` | The archive was built from a different tree than the one being verified (stale build, partial checkout, or an edited ZIP). | Rebuild with `bash bin/build-demo-installer.sh --standalone` at the revision under test, then verify again. Never relax the verifier: a passing check on a stale core is exactly the failure this requirement exists to prevent. |

Regenerate the manifest locally with:

```bash
python3 .github/scripts/companion-compat/author_manifest.py   # rewrites compatibility.json
python3 .github/scripts/companion-compat/evaluate.py --gate compatibility
python3 .github/scripts/companion-compat/generate.py --check
```

## 7. Companion-only PR compliance with repository isolation

`.github/scripts/companion-isolation.py` fails closed when a change set touches
both the production subtree and the companion subtree. This system respects that
rule by construction:

* The manifest lives at `tools/disposable-demo-installer/compatibility.json`,
  which is inside the companion prefix.
* The generated PR therefore touches only
  `tools/disposable-demo-installer/…` — the sync workflow asserts this and runs
  `companion-isolation.py` against the generated diff before publishing.
* The evaluator and generator live in `.github/scripts/companion-compat/`, which
  the classifier treats as out-of-scope lane plumbing (like `docs/`), so tooling
  additions never make a change set "mixed".
* This pull request is companion-only: it changes
  `tools/disposable-demo-installer/`, `.github/`, `tests/` and `docs/`, and **no**
  `clinic-practice-management/` file.

## 8. Known limitations

* The gate reasons about **shape** (tables, keys, routes). It cannot prove that a
  service still authorizes correctly; that remains the job of the PHP suites and
  the real-WordPress lanes.
* `production_schema.py` reports dynamic, loop-built DDL as *unresolved*
  (`INFO`). It does not attempt to model it.
* Deleting a table's rows is verified structurally and by the PHP suites. No
  static check can replace the real-database teardown drills.

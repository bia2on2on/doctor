#!/usr/bin/env python3
"""Fail-closed compatibility gate between the CPMS plugin and its demo companion.

The gate answers one question with evidence: *can the companion demo still
install, exercise and safely tear down the production schema that is checked out
right now?*

Every fact about production is DERIVED (see production_schema / production_features).
Every fact about the companion is READ from its PHP sources (companion_state).
The manifest in between holds the human-verified ownership and deletion rules.

Fail-closed rules
-----------------
* a schema-head mismatch blocks;
* a production table unknown to the manifest or to the companion blocks
  (unknown tables never pass silently);
* a table with no verified teardown coverage blocks;
* an ownership column whose id-set disagrees with its declared foreign-key parent
  blocks (this is the invariant whose violation let a clinic-membership id delete
  foreign organization-membership capability rows);
* a table without a surrogate id and without a composite-key rule blocks;
* a source element the parsers could not read blocks;
* a safety invariant that is no longer present in the companion source blocks;
* a declared demo-required feature without journeys marks compatibility
  incomplete and blocks the demo-coverage gate.

Usage
-----
    python3 evaluate.py --root . [--report out.json] [--gate compatibility|all]

Exit codes: 0 = compatible, 1 = blocking findings, 2 = usage/IO error.
"""

from __future__ import annotations

import argparse
import ast
import json
import re
import sys
import traceback
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, List, Optional, Set

sys.path.insert(0, str(Path(__file__).resolve().parent))

import companion_state as cs  # noqa: E402
import production_features as pf  # noqa: E402
import production_schema as ps  # noqa: E402

MANIFEST_RELPATH = "tools/disposable-demo-installer/compatibility.json"
SUPPORTED_CONTRACT_VERSIONS = (1,)

BLOCKER = "blocker"
GAP = "gap"
INFO = "info"


@dataclass
class Finding:
    """One gate finding."""

    check: str
    severity: str
    message: str
    detail: str = ""

    def as_dict(self) -> Dict[str, str]:
        return {
            "check": self.check,
            "severity": self.severity,
            "message": self.message,
            "detail": self.detail,
        }


@dataclass
class Report:
    """Full gate report."""

    findings: List[Finding] = field(default_factory=list)
    summary: Dict[str, object] = field(default_factory=dict)

    def add(self, check: str, severity: str, message: str, detail: str = "") -> None:
        self.findings.append(Finding(check, severity, message, detail))

    def blockers(self) -> List[Finding]:
        return [f for f in self.findings if f.severity == BLOCKER]

    def gaps(self) -> List[Finding]:
        return [f for f in self.findings if f.severity == GAP]

    def as_dict(self, gate: str) -> Dict[str, object]:
        blockers = self.blockers()
        gaps = self.gaps()
        blocks_gate = bool(blockers) or (gate == "all" and bool(gaps))
        return {
            "gate": gate,
            "ok": not blocks_gate,
            "compatibility_complete": not gaps,
            "summary": self.summary,
            "findings": [f.as_dict() for f in self.findings],
            "counts": {
                "blocker": len(blockers),
                "gap": len(gaps),
                "info": len([f for f in self.findings if f.severity == INFO]),
            },
        }


def _load_manifest(root: Path, report: Report) -> Optional[dict]:
    path = root / MANIFEST_RELPATH
    if not path.is_file():
        report.add("manifest", BLOCKER, f"compatibility manifest is missing: {MANIFEST_RELPATH}")
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        report.add("manifest", BLOCKER, f"compatibility manifest is unreadable: {error}")
        return None


def check_contract_version(manifest: dict, report: Report) -> None:
    version = manifest.get("contract_version")
    if version not in SUPPORTED_CONTRACT_VERSIONS:
        report.add(
            "contract_version",
            BLOCKER,
            f"unsupported compatibility contract version {version!r}; supported: {list(SUPPORTED_CONTRACT_VERSIONS)}",
        )


def check_schema_head(schema: ps.ProductionSchema, state: cs.CompanionState, manifest: dict, report: Report) -> None:
    compatibility = manifest.get("compatibility") or {}
    declared = str(compatibility.get("production_schema_head", ""))
    if declared != schema.head:
        report.add(
            "schema_head",
            BLOCKER,
            f"manifest declares schema head {declared!r} but production migrations derive {schema.head!r}",
            "regenerate the manifest and re-verify the ownership rules",
        )
    if state.schema_version != schema.head:
        report.add(
            "schema_head",
            BLOCKER,
            f"companion pins CPMS_DEMO_SCHEMA_VERSION={state.schema_version!r} but production head is {schema.head!r}",
            "the demo preflight refuses to provision against this schema",
        )
    pin = str(compatibility.get("companion_schema_pin", ""))
    if pin and pin != state.schema_version:
        report.add(
            "schema_head",
            BLOCKER,
            f"manifest records companion pin {pin!r} but the companion source declares {state.schema_version!r}",
        )


def check_table_inventory(schema: ps.ProductionSchema, state: cs.CompanionState, manifest: dict, report: Report) -> None:
    derived = schema.live_tables()
    manifest_tables: Dict[str, dict] = manifest.get("tables") or {}
    declared = set(manifest_tables)
    companion_known = set(state.known_tables)

    for table in sorted(derived - declared):
        report.add(
            "table_inventory",
            BLOCKER,
            f"production table `{table}` is absent from the compatibility manifest",
            "unknown tables must never pass silently; classify it and state its ownership rules",
        )
    for table in sorted(declared - derived):
        report.add(
            "table_inventory",
            BLOCKER,
            f"manifest declares table `{table}` which no production migration creates",
            "stale entry: the table was removed or renamed",
        )
    for table in sorted(derived - companion_known):
        report.add(
            "table_inventory",
            BLOCKER,
            f"production table `{table}` is missing from Preflight::KNOWN_TABLES",
            "the companion cannot baseline, tear down or detect residue in this table",
        )
    for table in sorted(companion_known - derived):
        report.add(
            "table_inventory",
            BLOCKER,
            f"Preflight::KNOWN_TABLES lists `{table}` which production does not create",
        )

    operational = set(state.operational_tables)
    system = set(state.system_tables)
    if operational | system != companion_known:
        report.add(
            "inventory_partition",
            BLOCKER,
            "OPERATIONAL_TABLES | SYSTEM_TABLES != KNOWN_TABLES",
            f"only-operational={sorted(operational - companion_known)[:5]} "
            f"only-system={sorted(system - companion_known)[:5]} "
            f"unpartitioned={sorted(companion_known - (operational | system))[:5]}",
        )
    if operational & system:
        report.add(
            "inventory_partition",
            BLOCKER,
            "a table is declared both operational and system",
            str(sorted(operational & system)),
        )
    for table in sorted(companion_known):
        expected = (manifest_tables.get(table) or {}).get("inventory")
        actual = "system" if table in system else "operational"
        if expected and expected != actual:
            report.add(
                "inventory_partition",
                BLOCKER,
                f"`{table}` manifest inventory {expected!r} disagrees with the companion's {actual!r}",
            )


def check_teardown_coverage(schema: ps.ProductionSchema, state: cs.CompanionState, manifest: dict, report: Report) -> None:
    """Verify teardown coverage for BOTH lanes.

    ``Teardown::DELETE_ORDER`` is the STRICT lane's order and intentionally
    includes the parent tables (a clean disposable site is wiped completely).
    Scoped teardown deletes ``DELETE_ORDER - (GLOBAL_TABLES | PRESERVED_PARENT_TABLES)``
    and handles the global tables through dedicated ownership allowlists. The
    checks therefore distinguish strict membership from scoped behaviour.
    """
    manifest_tables: Dict[str, dict] = manifest.get("tables") or {}
    delete_order = list(state.delete_order)
    delete_set = set(delete_order)
    scoped_global = set(state.scoped_constants.get("GLOBAL_TABLES", []))
    scoped_preserved = set(state.scoped_constants.get("PRESERVED_PARENT_TABLES", []))
    new_table_policy = str((manifest.get("auto_sync") or {}).get("new_table_policy", ""))

    if not delete_order:
        report.add("teardown_coverage", BLOCKER, "Teardown::DELETE_ORDER could not be read")

    for name in sorted(schema.live_tables()):
        entry = manifest_tables.get(name) or {}
        teardown_class = entry.get("teardown_class", "unclassified")
        if teardown_class == "unclassified":
            report.add(
                "teardown_coverage",
                BLOCKER,
                f"`{name}` has no verified teardown classification",
                new_table_policy,
            )
            continue

        if teardown_class == "deletable":
            if name not in delete_set:
                report.add(
                    "teardown_coverage",
                    BLOCKER,
                    f"`{name}` is classified deletable but is absent from Teardown::DELETE_ORDER",
                    "scoped teardown iterates DELETE_ORDER, so its rows would never be removed",
                )
            for shared, label in ((scoped_global, "GLOBAL_TABLES"), (scoped_preserved, "PRESERVED_PARENT_TABLES")):
                if name in shared:
                    report.add(
                        "teardown_coverage",
                        BLOCKER,
                        f"`{name}` is classified deletable but is subtracted via {label}",
                    )
        elif teardown_class == "runner_managed":
            if name in delete_set or name in scoped_global or name in scoped_preserved:
                report.add(
                    "teardown_coverage",
                    BLOCKER,
                    f"`{name}` is runner-managed but is reachable by teardown",
                )
        elif teardown_class == "preserved_anchor":
            if name not in scoped_preserved:
                report.add(
                    "teardown_coverage",
                    BLOCKER,
                    f"`{name}` is a preserved audit anchor but is absent from PRESERVED_PARENT_TABLES",
                )
        elif teardown_class == "global_never":
            # audit_logs is subtracted via GLOBAL_TABLES; install-level licence
            # tables are simply never named by any deletion path.
            if name in delete_set and name not in scoped_global:
                report.add(
                    "teardown_coverage",
                    BLOCKER,
                    f"`{name}` must never be deleted but appears in Teardown::DELETE_ORDER",
                )
        elif teardown_class == "global_selective":
            if name not in scoped_global:
                report.add(
                    "teardown_coverage",
                    BLOCKER,
                    f"`{name}` is a selectively-shared global table but is absent from GLOBAL_TABLES",
                )

    if scoped_preserved and scoped_preserved != {
        t for t, e in manifest_tables.items() if e.get("teardown_class") == "preserved_anchor"
    }:
        report.add(
            "teardown_coverage",
            BLOCKER,
            "PRESERVED_PARENT_TABLES does not match the manifest's preserved anchors",
            f"preserved={sorted(scoped_preserved)}",
        )

    for name in sorted(delete_order):
        if name not in schema.live_tables():
            report.add(
                "teardown_coverage",
                BLOCKER,
                f"Teardown::DELETE_ORDER lists `{name}` which production does not create",
            )

    # Within the strict order, a child row must go before the parent it references.
    position = {name: index for index, name in enumerate(delete_order)}
    for name in sorted(schema.live_tables()):
        if name not in position:
            continue
        for fk in schema.tables[name].foreign_keys:
            parent = fk.references_table
            if parent not in position:
                continue
            if position[name] > position[parent]:
                report.add(
                    "delete_order",
                    BLOCKER,
                    f"`{name}` (child) is deleted after `{parent}` (parent) in DELETE_ORDER",
                    f"foreign key {name}.{fk.column} -> {parent}.{fk.references_column}",
                )


def _declared_set_for(manifest_tables: Dict[str, dict], table_name: str, column: str) -> Optional[str]:
    entry = manifest_tables.get(table_name) or {}
    rule = (entry.get("ownership_columns") or {}).get(column)
    return rule.get("set") if isinstance(rule, dict) else None


def _companion_composite_tables(state: cs.CompanionState) -> Set[str]:
    """Tables for which the companion implements an explicit composite-key rule."""
    return set(state.composite_key_tables)


def check_ownership_rules(schema: ps.ProductionSchema, state: cs.CompanionState, manifest: dict, report: Report) -> None:
    """Verify the companion's ownership maps against the declared FK topology."""
    id_sets: Dict[str, dict] = manifest.get("id_sets") or {}
    manifest_tables: Dict[str, dict] = manifest.get("tables") or {}

    for set_name, definition in sorted(id_sets.items()):
        parent = str(definition.get("parent_table", ""))
        if parent.startswith("cpms_") and parent not in schema.live_tables():
            report.add(
                "id_sets",
                BLOCKER,
                f"id-set `{set_name}` names parent `{parent}` which production does not create",
            )

    # 1. A column name with more than one FK parent must never be resolved globally.
    fk_parents: Dict[str, Set[str]] = {}
    for name in schema.live_tables():
        for fk in schema.tables[name].foreign_keys:
            fk_parents.setdefault(fk.column, set()).add(fk.references_table)
    for column, parents in sorted(fk_parents.items()):
        if len(parents) > 1 and column in state.scoped_column_map:
            report.add(
                "ownership_ambiguity",
                BLOCKER,
                f"column `{column}` has {len(parents)} distinct foreign-key parents "
                f"({sorted(parents)}) but the companion resolves it globally to "
                f"`{state.scoped_column_map[column]}`",
                "rows of one parent would be selected by another parent's ids; declare it per table",
            )

    # 2. Every ownership mapping must agree with the declared FK parent.
    parent_to_set = {
        str(definition.get("parent_table")): set_name for set_name, definition in id_sets.items()
    }
    for table_name in sorted(schema.live_tables()):
        for fk in schema.tables[table_name].foreign_keys:
            expected_set = parent_to_set.get(fk.references_table)
            if expected_set is None:
                continue
            declared_set = _declared_set_for(manifest_tables, table_name, fk.column)
            if declared_set is None or declared_set == expected_set:
                continue
            report.add(
                "ownership_fk_mismatch",
                BLOCKER,
                f"`{table_name}.{fk.column}` is mapped to id-set `{declared_set}` but its foreign key "
                f"references `{fk.references_table}.{fk.references_column}` (id-set `{expected_set}`)",
                "this is the exact shape of the clinic/organization membership defect",
            )
            companion_set = state.scoped_column_map.get(fk.column)
            if companion_set == declared_set:
                report.add(
                    "ownership_fk_mismatch",
                    BLOCKER,
                    f"the companion's global column map reproduces the same wrong mapping for "
                    f"`{table_name}.{fk.column}` -> `{companion_set}`",
                )

    # 3. A table may only feed the id-set whose parent it is.
    for table_name in sorted(schema.live_tables()):
        feeds = (manifest_tables.get(table_name) or {}).get("feeds_id_set")
        if not feeds:
            continue
        parent = str((id_sets.get(feeds) or {}).get("parent_table", ""))
        if parent != table_name:
            report.add(
                "ownership_feeds",
                BLOCKER,
                f"`{table_name}` feeds id-set `{feeds}` whose declared parent is `{parent}`",
            )

    # 4. The companion's own table->set map must match the manifest exactly.
    for table_name, set_name in sorted(state.scoped_table_to_set.items()):
        expected = (manifest_tables.get(table_name) or {}).get("feeds_id_set")
        if expected != set_name:
            report.add(
                "ownership_feeds",
                BLOCKER,
                f"companion maps `{table_name}` -> `{set_name}` but the manifest declares `{expected}`",
            )
    for table_name in sorted(schema.live_tables()):
        expected = (manifest_tables.get(table_name) or {}).get("feeds_id_set")
        if expected and table_name not in state.scoped_table_to_set:
            report.add(
                "ownership_feeds",
                BLOCKER,
                f"manifest requires `{table_name}` to feed `{expected}` but the companion has no such mapping",
            )

    # 5. Composite-key coverage for every table without a surrogate id.
    implemented = _companion_composite_tables(state)
    for table_name in sorted(schema.live_tables()):
        table = schema.tables[table_name]
        if table.surrogate_id:
            continue
        entry = manifest_tables.get(table_name) or {}
        mode = entry.get("teardown_mode")
        if mode in ("never", "dedicated_allowlist"):
            continue
        if mode != "composite_key":
            report.add(
                "composite_key",
                BLOCKER,
                f"`{table_name}` has no surrogate `id` (primary key {table.primary_key}) but its "
                f"teardown mode is {mode!r}",
                "a table without an id column must be torn down by an explicit composite key",
            )
            continue
        key = list(entry.get("deletion_key") or [])
        if key != table.primary_key:
            report.add(
                "composite_key",
                BLOCKER,
                f"`{table_name}` deletion key {key} does not equal its primary key {table.primary_key}",
            )
        if table_name not in implemented:
            report.add(
                "composite_key",
                BLOCKER,
                f"`{table_name}` has a verified composite-key rule in the manifest but the companion "
                "does not implement it",
            )

    # 6. Every ownership column the manifest declares must exist in production.
    for table_name, entry in sorted(manifest_tables.items()):
        if table_name not in schema.live_tables():
            continue
        columns = schema.tables[table_name].column_set()
        for column in sorted((entry.get("ownership_columns") or {})):
            if column not in columns:
                report.add(
                    "ownership_column",
                    BLOCKER,
                    f"manifest declares ownership column `{table_name}.{column}` which the table does not have",
                )


def check_roles_capabilities(surface: pf.FeatureSurface, manifest: dict, report: Report) -> None:
    declared = manifest.get("roles_capabilities") or {}
    for role in sorted(set(surface.roles) - set(declared.get("production_roles") or [])):
        report.add(
            "roles",
            BLOCKER,
            f"production role `{role}` is not declared in the compatibility manifest",
        )
    for role in sorted(set(declared.get("production_roles") or []) - set(surface.roles)):
        report.add(
            "roles",
            BLOCKER,
            f"manifest declares role `{role}` which production no longer defines",
        )
    declared_caps = set(declared.get("capabilities") or [])
    for cap in sorted(set(surface.capabilities) - declared_caps):
        report.add(
            "capabilities",
            BLOCKER,
            f"production capability `{cap}` is not declared in the compatibility manifest",
            "a new capability may need a demo persona to hold it",
        )
    for cap in sorted(declared_caps - set(surface.capabilities)):
        report.add(
            "capabilities",
            BLOCKER,
            f"manifest declares capability `{cap}` which production no longer defines",
        )
    for template, caps in sorted(surface.role_caps.items()):
        declared_template = (declared.get("role_capability_templates") or {}).get(template)
        if declared_template is None:
            report.add("capabilities", BLOCKER, f"role template `{template}` is not declared in the manifest")
        elif sorted(declared_template) != sorted(caps):
            report.add(
                "capabilities",
                BLOCKER,
                f"role template `{template}` changed",
                f"added={sorted(set(caps) - set(declared_template))} "
                f"removed={sorted(set(declared_template) - set(caps))}",
            )


def _declared_journeys(source: str, relpath: str, report: Report) -> Set[str]:
    """Read the machine-readable ``JOURNEYS = (...)`` registry from a harness.

    The registry is an explicit declaration of what the harness implements. It is
    parsed as a Python literal so a malformed registry fails closed instead of
    silently matching nothing.
    """
    match = re.search(r"^JOURNEYS\s*=\s*(\()", source, re.MULTILINE)
    if match is None:
        report.add("features", BLOCKER, f"browser harness {relpath} declares no JOURNEYS registry")
        return set()
    start = match.start(1)
    depth = 0
    for index in range(start, len(source)):
        if source[index] == "(":
            depth += 1
        elif source[index] == ")":
            depth -= 1
            if depth == 0:
                try:
                    value = ast.literal_eval(source[start : index + 1])
                except (ValueError, SyntaxError) as error:
                    report.add("features", BLOCKER, f"cannot parse JOURNEYS registry in {relpath}: {error}")
                    return set()
                return {str(v) for v in value}
    report.add("features", BLOCKER, f"unterminated JOURNEYS registry in {relpath}")
    return set()


def check_features(root: Path, surface: pf.FeatureSurface, manifest: dict, report: Report) -> None:
    features = manifest.get("features") or []
    if not features:
        report.add("features", BLOCKER, "the manifest declares no features; demo coverage cannot be evaluated")
        return

    harness_ids: Set[str] = set()
    for relpath in (manifest.get("sources") or {}).get("browser_journeys") or []:
        path = root / str(relpath)
        if not path.is_file():
            report.add("features", BLOCKER, f"declared browser journey harness is missing: {relpath}")
            continue
        try:
            source = path.read_text(encoding="utf-8")
        except OSError as error:
            report.add("features", BLOCKER, f"cannot read journey harness {relpath}: {error}")
            continue
        harness_ids.update(_declared_journeys(source, str(relpath), report))

    route_paths = surface.route_paths()
    for feature in features:
        feature_id = str(feature.get("id", ""))
        if not feature_id:
            report.add("features", BLOCKER, "a manifest feature has no id")
            continue
        for route in feature.get("routes") or []:
            if route not in route_paths:
                report.add(
                    "features",
                    BLOCKER,
                    f"feature `{feature_id}` declares route `{route}` which production does not register",
                )
        for table in feature.get("tables") or []:
            entry = (manifest.get("tables") or {}).get(table)
            if entry is None:
                report.add("features", BLOCKER, f"feature `{feature_id}` references unknown table `{table}`")
        journeys = list(feature.get("journeys") or [])
        if not feature.get("demo_required"):
            continue
        missing = [j for j in journeys if j not in harness_ids]
        if missing:
            report.add(
                "demo_coverage",
                BLOCKER,
                f"feature `{feature_id}` declares journeys {missing} that no browser harness implements",
            )
        if not journeys:
            report.add(
                "demo_coverage",
                GAP,
                f"demo-required feature `{feature_id}` has no browser journey "
                f"(status={feature.get('status')!r})",
                str(feature.get("gap", "")),
            )

    # Production grew a route surface no feature claims: report it, do not guess.
    claimed: Set[str] = set()
    for feature in features:
        claimed.update(feature.get("routes") or [])
    for route in sorted(surface.route_paths()):
        if route in claimed:
            continue
        # Only the demo-relevant staff-portal surface is required to be claimed;
        # the rest is reported as informational so the manifest stays honest
        # without demanding a journey for every administrative endpoint.
        if any(part in route for part in ("/staff/portal/",)):
            report.add(
                "feature_surface",
                GAP,
                f"production route `{route}` is not claimed by any manifest feature",
                "declare it under a feature so demo coverage can be evaluated",
            )
        else:
            report.add("feature_surface", INFO, f"production route `{route}` is not claimed by a feature")


def check_volatile_options(state: cs.CompanionState, manifest: dict, report: Report) -> None:
    """Every normalised volatile option must be declared, justified and narrow."""
    declared = manifest.get("volatile_options") or {}
    for option, reason in sorted(declared.items()):
        if not str(reason).strip():
            report.add("volatile_options", BLOCKER, f"volatile option `{option}` has no justification")

    implemented = state.baseline_volatile_options
    for option in sorted(set(implemented) - set(declared)):
        report.add(
            "volatile_options",
            BLOCKER,
            f"the baseline normalises option `{option}` which the manifest does not declare as volatile",
            "an undeclared exemption would hide unauthorised changes to preexisting records",
        )
    for option in sorted(set(declared) - set(implemented)):
        report.add(
            "volatile_options",
            BLOCKER,
            f"the manifest declares volatile option `{option}` but the baseline does not normalise it",
        )


_SCHEMA_LITERAL = re.compile(r"\b(20\d{2}_\d{2}_\d{2}_\d{4})\b")


def check_stale_workflow_pins(schema: ps.ProductionSchema, manifest: dict, report: Report, root: Path) -> None:
    """CI workflows must not assert a schema head other than the derived one.

    A shell `test "$SCHEMA" = '…'` inside a lane is a hard assertion about
    production. Lanes whose path filters do not include the production subtree
    only run when the companion changes, so a stale assertion is invisible until
    an unrelated companion PR turns red.
    """
    scan = manifest.get("schema_pin_scan") or {}
    files = list(scan.get("files") or [])
    allowed = set(scan.get("allowed_historical_versions") or [])
    if not files:
        return
    for relpath in files:
        path = root / str(relpath)
        if not path.is_file():
            report.add("schema_pin", BLOCKER, f"schema pin scan target is missing: {relpath}")
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except OSError as error:
            report.add("schema_pin", BLOCKER, f"cannot read {relpath}: {error}")
            continue
        for found in sorted(set(_SCHEMA_LITERAL.findall(text))):
            if found == schema.head or found in allowed:
                continue
            report.add(
                "schema_pin",
                BLOCKER,
                f"{relpath} asserts schema head `{found}` but production head is `{schema.head}`",
                str(scan.get("why", "")),
            )


def check_ownership_map_agreement(state: cs.CompanionState, manifest: dict, report: Report) -> None:
    """The companion's ownership maps must equal the manifest's verified rules."""
    ownership = manifest.get("ownership_columns") or {}
    manifest_global: Dict[str, str] = dict(ownership.get("global") or {})
    manifest_per_table: Dict[str, Dict[str, str]] = {
        str(table): dict(columns or {}) for table, columns in (ownership.get("per_table") or {}).items()
    }

    for column in sorted(set(manifest_global) - set(state.scoped_column_map)):
        report.add(
            "ownership_columns",
            BLOCKER,
            f"manifest maps ownership column `{column}` to `{manifest_global[column]}` "
            "but the companion declares no such global rule",
            "columns the manifest lists must actually be used, or the manifest is overstating coverage",
        )
    for column, set_name in sorted(state.scoped_column_map.items()):
        expected = manifest_global.get(column)
        if expected is None:
            report.add(
                "ownership_columns",
                BLOCKER,
                f"the companion resolves ownership column `{column}` -> `{set_name}` "
                "but the manifest does not declare that global rule",
            )
        elif expected != set_name:
            report.add(
                "ownership_columns",
                BLOCKER,
                f"`{column}` -> companion `{set_name}` vs manifest `{expected}`",
            )

    ambiguous = set((manifest.get("ownership_columns") or {}).get("ambiguous_column_names") or [])
    for column in sorted(ambiguous & set(state.scoped_column_map)):
        report.add(
            "ownership_columns",
            BLOCKER,
            f"`{column}` is declared ambiguous but the companion still resolves it globally to "
            f"`{state.scoped_column_map[column]}`",
            "an ambiguous column name must be declared per table",
        )

    for table, columns in sorted(manifest_per_table.items()):
        actual = state.scoped_table_ownership.get(table) or {}
        for column, set_name in sorted(columns.items()):
            if actual.get(column) != set_name:
                report.add(
                    "ownership_columns",
                    BLOCKER,
                    f"`{table}.{column}` should map to `{set_name}` but the companion has "
                    f"{actual.get(column)!r}",
                )


def check_safety_invariants(root: Path, manifest: dict, report: Report) -> None:
    for invariant in manifest.get("safety_invariants") or []:
        invariant_id = str(invariant.get("id", ""))
        required = list(invariant.get("requires") or [])
        forbidden = list(invariant.get("forbids") or [])
        files = list(invariant.get("files") or [])
        if not invariant_id or not files:
            report.add("safety_invariants", BLOCKER, "a safety invariant has no id or no files")
            continue
        blob_parts: List[str] = []
        for relpath in files:
            path = root / relpath
            if not path.is_file():
                report.add("safety_invariants", BLOCKER, f"invariant `{invariant_id}`: missing file {relpath}")
                continue
            blob_parts.append(path.read_text(encoding="utf-8"))
        blob = "\n".join(blob_parts)
        for token in required:
            if token not in blob:
                report.add(
                    "safety_invariants",
                    BLOCKER,
                    f"safety invariant `{invariant_id}` is not satisfied: `{token}` is absent",
                    str(invariant.get("why", "")),
                )
        for token in forbidden:
            if token in blob:
                report.add(
                    "safety_invariants",
                    BLOCKER,
                    f"safety invariant `{invariant_id}` is violated: forbidden `{token}` is present",
                    str(invariant.get("why", "")),
                )
        for spec in invariant.get("order") or []:
            path = root / str(spec.get("file", ""))
            before = str(spec.get("before", ""))
            after = str(spec.get("after", ""))
            if not path.is_file():
                report.add("safety_invariants", BLOCKER, f"invariant `{invariant_id}`: missing file {spec.get('file')}")
                continue
            text = path.read_text(encoding="utf-8")
            # `before` is the durable-write call, `after` is the evidence-discarding
            # call. A file may legitimately discard the evidence on an early refusal
            # path, so the property is: the LAST discard must still happen after the
            # FIRST durable write.
            first_before = text.find(before)
            last_after = text.rfind(after)
            first_after = last_after
            if first_before < 0 or first_after < 0:
                report.add(
                    "safety_invariants",
                    BLOCKER,
                    f"invariant `{invariant_id}`: cannot find `{before if first_before < 0 else after}` "
                    f"in {spec.get('file')}",
                )
            elif first_before > first_after:
                report.add(
                    "safety_invariants",
                    BLOCKER,
                    f"invariant `{invariant_id}` is violated: `{after}` appears before `{before}` "
                    f"in {spec.get('file')}",
                    "the recovery evidence must survive until the replacement record is durable",
                )


def check_personas(state: cs.CompanionState, manifest: dict, report: Report) -> None:
    declared = manifest.get("personas") or {}
    required = set(declared.get("required") or [])
    actual = set(state.personas)
    if required - actual:
        report.add("personas", BLOCKER, f"required demo personas are missing: {sorted(required - actual)}")
    if actual - required:
        report.add("personas", INFO, f"companion declares extra personas: {sorted(actual - required)}")


def check_unparsed(schema: ps.ProductionSchema, state: cs.CompanionState, surface: pf.FeatureSurface, report: Report) -> None:
    for item in state.unparsed:
        report.add("parser", BLOCKER, f"companion source element could not be parsed: {item}")
    for item in surface.unparsed:
        report.add("parser", BLOCKER, f"production source element could not be parsed: {item}")
    for item in sorted(set(schema.unresolved)):
        # Dynamic (loop-built) DDL cannot be resolved statically. It is reported
        # so a reviewer can confirm it is intentional rather than silently ignored.
        report.add("parser", INFO, f"production DDL target is dynamic and was not resolved: {item}")


def evaluate(root: Path, gate: str = "all") -> Dict[str, object]:
    """Run every check and return the serialisable report."""
    report = Report()
    manifest = _load_manifest(root, report)
    if manifest is None:
        return report.as_dict(gate)

    check_contract_version(manifest, report)
    if manifest.get("contract_version") not in SUPPORTED_CONTRACT_VERSIONS:
        return report.as_dict(gate)

    plugin_dir = root / str((manifest.get("sources") or {}).get("production_plugin", "clinic-practice-management"))
    companion_dir = root / str((manifest.get("sources") or {}).get("companion", "tools/disposable-demo-installer"))
    if not plugin_dir.is_dir():
        report.add("sources", BLOCKER, f"production plugin directory is missing: {plugin_dir}")
        return report.as_dict(gate)
    if not companion_dir.is_dir():
        report.add("sources", BLOCKER, f"companion directory is missing: {companion_dir}")
        return report.as_dict(gate)

    schema = ps.load(plugin_dir)
    state = cs.load(companion_dir)
    surface = pf.load(plugin_dir)

    check_schema_head(schema, state, manifest, report)
    check_table_inventory(schema, state, manifest, report)
    check_teardown_coverage(schema, state, manifest, report)
    check_ownership_rules(schema, state, manifest, report)
    check_roles_capabilities(surface, manifest, report)
    check_features(root, surface, manifest, report)
    check_stale_workflow_pins(schema, manifest, report, root)
    check_ownership_map_agreement(state, manifest, report)
    check_volatile_options(state, manifest, report)
    check_safety_invariants(root, manifest, report)
    check_personas(state, manifest, report)
    check_unparsed(schema, state, surface, report)

    report.summary = {
        "production_schema_head": schema.head,
        "companion_schema_pin": state.schema_version,
        "production_tables": len(schema.live_tables()),
        "companion_known_tables": len(state.known_tables),
        "companion_operational_tables": len(state.operational_tables),
        "companion_system_tables": len(state.system_tables),
        "delete_order_entries": len(state.delete_order),
        "production_capabilities": len(surface.capabilities),
        "production_roles": len(surface.roles),
        "production_routes": len(surface.routes),
        "manifest_tables": len(manifest.get("tables") or {}),
        "manifest_features": len(manifest.get("features") or []),
        "demo_required_features_without_journeys": sorted(
            str(f.get("id"))
            for f in (manifest.get("features") or [])
            if f.get("demo_required") and not (f.get("journeys") or [])
        ),
    }
    return report.as_dict(gate)


def render(result: Dict[str, object]) -> str:
    lines = [
        f"companion-compat: {'PASS' if result['ok'] else 'FAIL'} (gate={result['gate']})",
        f"compatibility_complete: {result['compatibility_complete']}",
    ]
    summary = result.get("summary") or {}
    for key in sorted(summary):
        value = summary[key]
        lines.append(f"  {key}: {value if not isinstance(value, list) else ', '.join(map(str, value)) or '[]'}")
    counts = result.get("counts") or {}
    lines.append(f"findings: blocker={counts.get('blocker')} gap={counts.get('gap')} info={counts.get('info')}")
    for finding in result.get("findings") or []:
        if finding["severity"] == INFO:
            continue
        lines.append(f"[{finding['severity'].upper()}] {finding['check']}: {finding['message']}")
        if finding.get("detail"):
            lines.append(f"    -> {finding['detail']}")
    return "\n".join(lines)


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root", default=".", help="repository root (default: cwd)")
    parser.add_argument("--gate", choices=("compatibility", "all"), default="all")
    parser.add_argument("--report", help="write the full JSON report to this path")
    parser.add_argument("--quiet", action="store_true", help="print only the verdict line")
    args = parser.parse_args(argv)

    root = Path(args.root).resolve()
    try:
        result = evaluate(root, gate=args.gate)
    except OSError as error:
        # A gate that cannot read its inputs must fail, and must say why.
        traceback.print_exc()
        print(f"companion-compat: FAIL (cannot read sources: {type(error).__name__}: {error})")
        return 2
    except Exception as error:  # noqa: BLE001 - never fail silently, never hide the cause
        traceback.print_exc()
        print(f"companion-compat: FAIL (unexpected {type(error).__name__}: {error})")
        return 2

    if args.report:
        try:
            Path(args.report).write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        except OSError as error:
            print(f"companion-compat: FAIL (cannot write report: {error})")
            return 2

    if not args.quiet:
        print(render(result))
    else:
        print(f"companion-compat: {'PASS' if result['ok'] else 'FAIL'}")
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())

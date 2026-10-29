#!/usr/bin/env python3
"""Deterministic, reviewable companion metadata updates.

What this tool MAY change
-------------------------
Only the fields listed under ``auto_sync.safe_updates`` in the manifest:
derived facts about the production plugin (schema head, migration count, role and
capability surface) and, for a newly discovered table, its *shape* (columns, keys,
foreign keys, inventory class).

What this tool MUST NOT change
------------------------------
Ownership and deletion rules. A new production table is added with
``teardown_class: "unclassified"`` and ``teardown_mode: "unclassified"``, which
makes the compatibility gate fail until a human states how the demo may prove
ownership of its rows and how it may remove them. Generated schema metadata is
never allowed to author a deletion rule: a wrong rule here deletes a real
patient's data.

The one companion source edit it may make is bumping
``CPMS_DEMO_SCHEMA_VERSION``, and only when that is provably safe: the derived
table inventory is unchanged and the companion already knows every table. In
every other case the pin is left stale so the gate keeps failing closed and the
sync pull request describes the manual work.

Usage
-----
    python3 generate.py --root . --check     # exit 1 if updates are needed
    python3 generate.py --root . --write     # apply the safe updates
"""

from __future__ import annotations

import argparse
import copy
import json
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, List, Optional

sys.path.insert(0, str(Path(__file__).resolve().parent))

import companion_state as cs  # noqa: E402
import production_features as pf  # noqa: E402
import production_schema as ps  # noqa: E402

MANIFEST_RELPATH = "tools/disposable-demo-installer/compatibility.json"
BOOTSTRAP_RELPATH = "tools/disposable-demo-installer/cpms-disposable-demo.php"

_UNCLASSIFIED = "unclassified"


@dataclass
class Plan:
    """Safe updates that would be applied."""

    manifest: Optional[dict] = None
    bootstrap_version: Optional[str] = None
    added_tables: List[str] = field(default_factory=list)
    removed_tables: List[str] = field(default_factory=list)
    changed: List[str] = field(default_factory=list)

    @property
    def has_changes(self) -> bool:
        return bool(self.changed or self.bootstrap_version)

    def as_dict(self) -> Dict[str, object]:
        return {
            "changed": list(self.changed),
            "added_tables": list(self.added_tables),
            "removed_tables": list(self.removed_tables),
            "bootstrap_version": self.bootstrap_version,
        }


def _table_entry(schema: ps.ProductionSchema, name: str) -> Dict[str, object]:
    """Shape-only entry for a table whose rules a human has not written yet."""
    table = schema.tables[name]
    return {
        "since": table.introduced_by,
        "inventory": "operational",
        "teardown_class": _UNCLASSIFIED,
        "teardown_mode": _UNCLASSIFIED,
        "deletion_key": [],
        "primary_key": list(table.primary_key),
        "surrogate_id": table.surrogate_id,
        "feeds_id_set": None,
        "ownership_columns": {},
        "foreign_keys": [
            {
                "column": fk.column,
                "references_table": fk.references_table,
                "references_column": fk.references_column,
                "on_delete": fk.on_delete,
            }
            for fk in table.foreign_keys
        ],
        "status": "manual_implementation_required",
        "rationale": (
            "Discovered by companion-sync. Ownership and deletion rules must be "
            "written and verified by a human before this table may be torn down."
        ),
    }


def plan(root: Path) -> Plan:
    """Compute the safe-update plan without touching the working tree."""
    manifest_path = root / MANIFEST_RELPATH
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    updated = copy.deepcopy(manifest)

    plugin_dir = root / str((manifest.get("sources") or {}).get("production_plugin", "clinic-practice-management"))
    companion_dir = root / str((manifest.get("sources") or {}).get("companion", "tools/disposable-demo-installer"))
    schema = ps.load(plugin_dir)
    surface = pf.load(plugin_dir)
    state = cs.load(companion_dir)

    result = Plan()
    compatibility = updated.setdefault("compatibility", {})
    if compatibility.get("production_schema_head") != schema.head:
        compatibility["production_schema_head"] = schema.head
        result.changed.append("compatibility.production_schema_head")
    if compatibility.get("migration_count") != len(schema.versions):
        compatibility["migration_count"] = len(schema.versions)
        result.changed.append("compatibility.migration_count")

    roles = updated.setdefault("roles_capabilities", {})
    if sorted(roles.get("production_roles") or []) != sorted(surface.roles):
        roles["production_roles"] = sorted(surface.roles)
        result.changed.append("roles_capabilities.production_roles")
    if sorted(roles.get("capabilities") or []) != sorted(surface.capabilities):
        roles["capabilities"] = sorted(surface.capabilities)
        roles["production_capability_count"] = len(surface.capabilities)
        result.changed.append("roles_capabilities.capabilities")
    templates = {k: sorted(v) for k, v in sorted(surface.role_caps.items())}
    if {k: sorted(v) for k, v in (roles.get("role_capability_templates") or {}).items()} != templates:
        roles["role_capability_templates"] = templates
        result.changed.append("roles_capabilities.role_capability_templates")

    tables = updated.setdefault("tables", {})
    derived = schema.live_tables()
    for name in sorted(derived - set(tables)):
        tables[name] = _table_entry(schema, name)
        result.added_tables.append(name)
    for name in sorted(set(tables) - derived):
        del tables[name]
        result.removed_tables.append(name)
    if result.added_tables:
        result.changed.append("tables.added")
    if result.removed_tables:
        result.changed.append("tables.removed")

    # Bump the companion pin only when it is provably safe to do so.
    if state.schema_version != schema.head:
        inventory_unchanged = not result.added_tables and not result.removed_tables
        companion_knows_all = set(state.known_tables) == derived
        if inventory_unchanged and companion_knows_all:
            result.bootstrap_version = schema.head
            updated["compatibility"]["companion_schema_pin"] = schema.head

    result.manifest = updated
    return result


def render(manifest: dict) -> str:
    return json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n"


def apply(root: Path, result: Plan) -> None:
    if result.manifest is not None and result.changed:
        (root / MANIFEST_RELPATH).write_text(render(result.manifest), encoding="utf-8")
    if result.bootstrap_version:
        path = root / BOOTSTRAP_RELPATH
        source = path.read_text(encoding="utf-8")
        bumped = re.sub(
            r"(define\(\s*'CPMS_DEMO_SCHEMA_VERSION',\s*')([^']+)('\s*\);)",
            lambda m: m.group(1) + str(result.bootstrap_version) + m.group(3),
            source,
            count=1,
        )
        path.write_text(bumped, encoding="utf-8")


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--root", default=".")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true", help="report whether updates are needed")
    group.add_argument("--write", action="store_true", help="apply the safe updates")
    parser.add_argument("--report", help="write the plan as JSON")
    args = parser.parse_args(argv)

    root = Path(args.root).resolve()
    result = plan(root)

    if args.report:
        Path(args.report).write_text(json.dumps(result.as_dict(), indent=2, sort_keys=True) + "\n", encoding="utf-8")

    if args.write:
        apply(root, result)
        print("companion-sync: applied " + (", ".join(result.changed) or "nothing"))
        if result.added_tables:
            print("companion-sync: UNCLASSIFIED new tables require manual rules: " + ", ".join(result.added_tables))
        return 0

    if result.has_changes:
        print("companion-sync: updates needed -> " + (", ".join(result.changed) or "schema pin"))
        for name in result.added_tables:
            print(f"  new table without verified rules: {name}")
        for name in result.removed_tables:
            print(f"  table no longer created by production: {name}")
        return 1

    print("companion-sync: up to date")
    return 0


if __name__ == "__main__":
    sys.exit(main())

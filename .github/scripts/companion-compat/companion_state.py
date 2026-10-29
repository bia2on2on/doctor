#!/usr/bin/env python3
"""Read the companion demo plugin's *declared* state out of its PHP sources.

The companion declares its compatibility in PHP constants (schema pin, table
inventories, delete order) and its ownership logic in PHP array literals
(column -> id-set map, table -> id-set map). This module parses those literals
with a small PHP-literal parser so the evaluator can compare them against the
derived production schema without executing PHP.

Only literals built from ``array()``/``[`` ``]``, single-quoted strings, integers
and ``true``/``false``/``null`` are understood. Anything else is reported through
``CompanionState.unparsed`` so the gate fails closed instead of silently
assuming coverage.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

BOOTSTRAP = "cpms-disposable-demo.php"
PREFLIGHT = "includes/class-cpms-demo-preflight.php"
TEARDOWN = "includes/class-cpms-demo-teardown.php"
SCOPED_TEARDOWN = "includes/class-cpms-demo-scoped-teardown.php"
SCOPED_BASELINE = "includes/class-cpms-demo-scoped-baseline.php"
CREDENTIALS = "includes/class-cpms-demo-credentials.php"

_DEFINE = re.compile(r"define\(\s*'(?P<name>[A-Z0-9_]+)',\s*'(?P<value>[^']*)'\s*\)")

# ---------------------------------------------------------------------------
# Minimal PHP literal parser
# ---------------------------------------------------------------------------


class LiteralError(ValueError):
    """Raised when a PHP literal uses a construct this parser does not model."""


class _Cursor:
    def __init__(self, text: str, pos: int = 0) -> None:
        self.text = text
        self.pos = pos

    def skip_ws(self) -> None:
        while self.pos < len(self.text) and self.text[self.pos].isspace():
            self.pos += 1

    def peek(self) -> str:
        return self.text[self.pos] if self.pos < len(self.text) else ""

    def startswith(self, token: str) -> bool:
        return self.text.startswith(token, self.pos)


def _parse_string(cur: _Cursor) -> str:
    quote = cur.text[cur.pos]
    cur.pos += 1
    out: List[str] = []
    while cur.pos < len(cur.text):
        char = cur.text[cur.pos]
        if char == "\\" and cur.pos + 1 < len(cur.text):
            out.append(cur.text[cur.pos + 1])
            cur.pos += 2
            continue
        if char == quote:
            cur.pos += 1
            return "".join(out)
        out.append(char)
        cur.pos += 1
    raise LiteralError("unterminated string literal")


def _parse_value(cur: _Cursor) -> Any:
    cur.skip_ws()
    if cur.peek() in ("'", '"'):
        return _parse_string(cur)
    if cur.startswith("array"):
        cur.pos += len("array")
        cur.skip_ws()
        if cur.peek() != "(":
            raise LiteralError("expected ( after array")
        return _parse_sequence(cur, "(", ")")
    if cur.peek() == "[":
        return _parse_sequence(cur, "[", "]")
    match = re.match(r"-?\d+", cur.text[cur.pos :])
    if match:
        cur.pos += len(match.group(0))
        return int(match.group(0))
    for word, value in (("true", True), ("false", False), ("null", None)):
        if cur.startswith(word):
            cur.pos += len(word)
            return value
    raise LiteralError(f"unsupported literal near {cur.text[cur.pos:cur.pos + 24]!r}")


def _parse_sequence(cur: _Cursor, open_token: str, close_token: str) -> Any:
    if cur.peek() != open_token:
        raise LiteralError(f"expected {open_token}")
    cur.pos += 1
    items: List[Tuple[Any, Any]] = []
    while True:
        cur.skip_ws()
        if cur.peek() == close_token:
            cur.pos += 1
            break
        if cur.peek() == "":
            raise LiteralError("unterminated array literal")
        first = _parse_value(cur)
        cur.skip_ws()
        if cur.startswith("=>"):
            cur.pos += 2
            second = _parse_value(cur)
            items.append((first, second))
        else:
            items.append((None, first))
        cur.skip_ws()
        if cur.peek() == ",":
            cur.pos += 1
            continue
        cur.skip_ws()
        if cur.peek() == close_token:
            cur.pos += 1
            break
        raise LiteralError(f"unexpected token in array near {cur.text[cur.pos:cur.pos + 24]!r}")

    if all(key is None for key, _ in items):
        return [value for _, value in items]
    return {str(key): value for key, value in items}


_CONST_HEAD = re.compile(r"const\s+(?P<name>[A-Z0-9_]+)\s*=\s*")


def const_value(source: str, name: str) -> Tuple[Any, Optional[str]]:
    """Parse ``const NAME = <literal>;`` and return ``(value, error)``."""
    for match in _CONST_HEAD.finditer(source):
        if match.group("name") != name:
            continue
        cur = _Cursor(source, match.end())
        try:
            value = _parse_value(cur)
        except LiteralError as error:
            return None, str(error)
        return value, None
    return None, f"const {name} not found"


def _list_of_strings(value: Any) -> List[str]:
    if isinstance(value, list):
        return [str(v) for v in value if isinstance(v, str)]
    return []


def _map_of_strings(value: Any) -> Dict[str, str]:
    if isinstance(value, dict):
        return {str(k): str(v) for k, v in value.items() if isinstance(v, str)}
    return {}


def _map_of_maps(value: Any) -> Dict[str, Dict[str, str]]:
    if not isinstance(value, dict):
        return {}
    out: Dict[str, Dict[str, str]] = {}
    for key, item in value.items():
        if isinstance(item, dict):
            out[str(key)] = {str(k): str(v) for k, v in item.items() if isinstance(v, str)}
    return out


def _map_of_lists(value: Any) -> Dict[str, List[str]]:
    if not isinstance(value, dict):
        return {}
    out: Dict[str, List[str]] = {}
    for key, item in value.items():
        if isinstance(item, list):
            out[str(key)] = [str(v) for v in item if isinstance(v, str)]
    return out


@dataclass
class CompanionState:
    """Declared state of the companion demo plugin."""

    root: Path
    schema_version: str = ""
    demo_version: str = ""
    core_plugin: str = ""
    operational_tables: List[str] = field(default_factory=list)
    system_tables: List[str] = field(default_factory=list)
    known_tables: List[str] = field(default_factory=list)
    delete_order: List[str] = field(default_factory=list)
    #: Global ownership column -> id-set name (unambiguous columns only).
    scoped_column_map: Dict[str, str] = field(default_factory=dict)
    #: Per-table ownership column -> id-set name (for ambiguous column names).
    scoped_table_ownership: Dict[str, Dict[str, str]] = field(default_factory=dict)
    #: Table -> id-set name that receives its collected ids.
    scoped_table_to_set: Dict[str, str] = field(default_factory=dict)
    #: Tables torn down by an explicit composite key -> key columns.
    composite_key_tables: Dict[str, List[str]] = field(default_factory=dict)
    #: Table -> MANDATORY tenant column (clinic_id / organization_id).
    scoped_tenant_ownership: Dict[str, str] = field(default_factory=dict)
    #: Tenant-less table -> the parent column that proves ownership.
    scoped_parent_ownership: Dict[str, str] = field(default_factory=dict)
    #: Composite-key table -> the parent column that proves ownership.
    scoped_composite_parent: Dict[str, str] = field(default_factory=dict)
    #: Every column that stores a WordPress user id (actor references only).
    scoped_user_references: List[str] = field(default_factory=list)
    #: Tables that can never be owned, with the reason.
    scoped_never_owned: Dict[str, str] = field(default_factory=dict)
    #: Legitimate volatile wp_options normalised by the baseline, with reason.
    baseline_volatile_options: Dict[str, str] = field(default_factory=dict)
    scoped_constants: Dict[str, List[str]] = field(default_factory=dict)
    teardown_constants: Dict[str, List[str]] = field(default_factory=dict)
    personas: List[str] = field(default_factory=list)
    unparsed: List[str] = field(default_factory=list)

    def known_set(self) -> set:
        return set(self.known_tables)


def _legacy_inline_maps(source: str) -> Tuple[Dict[str, str], Dict[str, str], Optional[str]]:
    """Parse the pre-refactor method-local ``$column_map`` / ``$table_to_set``.

    Kept so the gate can evaluate a checkout that still has the old shape (the
    companion-sync workflow must be able to *describe* an incompatibility before
    anyone has fixed it).
    """
    errors: List[str] = []

    def grab(name: str) -> Dict[str, str]:
        pattern = re.compile(r"\$" + re.escape(name) + r"\s*=\s*array\(", re.DOTALL)
        match = pattern.search(source)
        if match is None:
            return {}
        cur = _Cursor(source, match.end() - 1)
        try:
            return _map_of_strings(_parse_sequence(cur, "(", ")"))
        except LiteralError as error:
            errors.append(f"{name}: {error}")
            return {}

    column_map = grab("column_map")
    table_to_set = grab("table_to_set")
    return column_map, table_to_set, ("; ".join(errors) if errors else None)


def load(companion_dir: Path) -> CompanionState:
    """Parse the companion plugin directory into its declared state."""
    state = CompanionState(root=companion_dir)

    def read(relative: str) -> str:
        path = companion_dir / relative
        return path.read_text(encoding="utf-8") if path.is_file() else ""

    bootstrap = read(BOOTSTRAP)
    defines = {m.group("name"): m.group("value") for m in _DEFINE.finditer(bootstrap)}
    state.schema_version = defines.get("CPMS_DEMO_SCHEMA_VERSION", "")
    state.demo_version = defines.get("CPMS_DEMO_VERSION", "")
    state.core_plugin = defines.get("CPMS_DEMO_CORE_PLUGIN", "")
    if not state.schema_version:
        state.unparsed.append(f"{BOOTSTRAP}:CPMS_DEMO_SCHEMA_VERSION")

    preflight = read(PREFLIGHT)
    for name, attribute in (
        ("OPERATIONAL_TABLES", "operational_tables"),
        ("SYSTEM_TABLES", "system_tables"),
        ("KNOWN_TABLES", "known_tables"),
    ):
        value, error = const_value(preflight, name)
        if error:
            state.unparsed.append(f"{PREFLIGHT}:{name} ({error})")
            continue
        setattr(state, attribute, _list_of_strings(value))

    teardown = read(TEARDOWN)
    value, error = const_value(teardown, "DELETE_ORDER")
    if error:
        state.unparsed.append(f"{TEARDOWN}:DELETE_ORDER ({error})")
    else:
        state.delete_order = _list_of_strings(value)
    for name in ("GLOBAL_TABLES", "PRESERVED_PARENT_TABLES"):
        value, error = const_value(teardown, name)
        if error is None:
            state.teardown_constants[name] = _list_of_strings(value)

    scoped = read(SCOPED_TEARDOWN)
    for name in ("GLOBAL_TABLES", "PRESERVED_PARENT_TABLES"):
        value, error = const_value(scoped, name)
        if error is None:
            state.scoped_constants[name] = _list_of_strings(value)

    value, error = const_value(scoped, "OWNERSHIP_COLUMNS")
    if error is None:
        state.scoped_column_map = _map_of_strings(value)
    value, error = const_value(scoped, "TABLE_OWNERSHIP_COLUMNS")
    if error is None:
        state.scoped_table_ownership = _map_of_maps(value)
    value, error = const_value(scoped, "TABLE_FEEDS_SET")
    if error is None:
        state.scoped_table_to_set = _map_of_strings(value)
    value, error = const_value(scoped, "COMPOSITE_KEY_TABLES")
    if error is None:
        state.composite_key_tables = _map_of_lists(value)

    # Declarative ownership rules. These four constants are what makes the
    # teardown auditable: tenant boundary, declared parent, composite parent and
    # the actor-reference surface are all readable, so the gate can prove that
    # no WordPress-user column is treated as ownership.
    for name, attribute in (
        ("TENANT_OWNERSHIP", "scoped_tenant_ownership"),
        ("PARENT_OWNERSHIP", "scoped_parent_ownership"),
        ("COMPOSITE_PARENT_COLUMN", "scoped_composite_parent"),
        ("NEVER_OWNED_TABLES", "scoped_never_owned"),
    ):
        value, error = const_value(scoped, name)
        if error is None:
            setattr(state, attribute, _map_of_strings(value))
        else:
            state.unparsed.append(f"{SCOPED_TEARDOWN}:{name} ({error})")
    value, error = const_value(scoped, "USER_REFERENCE_COLUMNS")
    if error is None:
        state.scoped_user_references = _list_of_strings(value)
    else:
        state.unparsed.append(f"{SCOPED_TEARDOWN}:USER_REFERENCE_COLUMNS ({error})")

    # Pre-refactor shape: the maps lived inside collect_scope(). Read them only
    # when the declarative constants are absent, and say so explicitly.
    if not state.scoped_column_map and not state.scoped_table_to_set:
        column_map, table_to_set, error = _legacy_inline_maps(scoped)
        state.scoped_column_map = column_map
        state.scoped_table_to_set = table_to_set
        if error:
            state.unparsed.append(f"{SCOPED_TEARDOWN}:column_map/table_to_set ({error})")
        if not column_map or not table_to_set:
            state.unparsed.append(f"{SCOPED_TEARDOWN}:column_map/table_to_set")

    baseline = read(SCOPED_BASELINE)
    value, error = const_value(baseline, "VOLATILE_OPTIONS")
    if error is not None:
        state.unparsed.append(f"{SCOPED_BASELINE}:VOLATILE_OPTIONS ({error})")
    else:
        state.baseline_volatile_options = _map_of_strings(value)

    credentials = read(CREDENTIALS)
    value, error = const_value(credentials, "PERSONA_ORDER")
    if error:
        state.unparsed.append(f"{CREDENTIALS}:PERSONA_ORDER ({error})")
    else:
        state.personas = _list_of_strings(value)

    return state


def describe(state: CompanionState) -> Dict[str, object]:
    """Serialisable summary used by the evaluator report."""
    return {
        "schema_version": state.schema_version,
        "demo_version": state.demo_version,
        "core_plugin": state.core_plugin,
        "counts": {
            "operational_tables": len(state.operational_tables),
            "system_tables": len(state.system_tables),
            "known_tables": len(state.known_tables),
            "delete_order": len(state.delete_order),
            "scoped_column_map": len(state.scoped_column_map),
            "scoped_table_ownership": len(state.scoped_table_ownership),
            "scoped_table_to_set": len(state.scoped_table_to_set),
            "composite_key_tables": len(state.composite_key_tables),
            "baseline_volatile_options": len(state.baseline_volatile_options),
        },
        "personas": list(state.personas),
        "composite_key_tables": {k: list(v) for k, v in sorted(state.composite_key_tables.items())},
        "baseline_volatile_options": sorted(state.baseline_volatile_options),
        "unparsed": list(state.unparsed),
    }


if __name__ == "__main__":  # pragma: no cover - manual inspection helper
    import sys

    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    companion = root / "tools" / "disposable-demo-installer"
    print(describe(load(companion)))

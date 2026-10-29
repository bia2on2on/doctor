#!/usr/bin/env python3
"""Derive CPMS production schema facts from the plugin's migration sources.

Everything in this module is *derived*, never hand-maintained: the migration
directory is the only authority for the schema head, the table inventory, each
table's columns/primary key and its declared foreign keys. The compatibility
evaluator compares these derived facts against the companion's declared state
and against the hand-authored ownership rules in ``compatibility.json``.

Security note
-------------
Derived facts are deliberately limited to *shape* (names, columns, keys).
Ownership and deletion rules are NOT derivable from DDL and are never inferred
here; they must be stated explicitly in the manifest and are treated as missing
(fail closed) when they are not.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, List, Optional, Set, Tuple

#: ``$var = $db->table( 'cpms_name' );`` — migrations bind a table to a local.
_TABLE_VAR = re.compile(
    r"\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$(?:this->)?db->table\(\s*'(?P<name>[a-z0-9_]+)'\s*\)",
)

#: ``$var = $this->db->table( self::SOME_CONST );`` plus the constant itself.
_TABLE_CONST_VAR = re.compile(
    r"\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$(?:this->)?db->table\(\s*self::(?P<const>[A-Z0-9_]+)\s*\)",
)
_CLASS_CONST = re.compile(r"const\s+(?P<const>[A-Z0-9_]+)\s*=\s*'(?P<name>[a-z0-9_]+)'\s*;")

#: Every DDL statement this parser understands, with the text that follows it.
_DDL = re.compile(
    r"(?P<kind>CREATE|ALTER|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?P<tail>.{0,160})",
    re.IGNORECASE | re.DOTALL,
)

#: Ways a table name can appear right after ``CREATE TABLE``.
_TARGET_FORMS: Tuple[re.Pattern, ...] = (
    # Interpolated: CREATE TABLE IF NOT EXISTS {$threads} (
    re.compile(r"^\{\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\}"),
    # Concatenated literal: ' . $db->table('cpms_clinics') . ' (
    re.compile(r"^'?\s*\.?\s*\$db->table\(\s*'(?P<name>[a-z0-9_]+)'\s*\)"),
    # Bare interpolated variable: ALTER TABLE ' . $t . ' ADD ...
    re.compile(r"^'?\s*\.?\s*\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*\.?\s*'"),
    # Plain literal (used by some DROP statements).
    re.compile(r"^'(?P<name>[a-z0-9_]+)'"),
)

_COLUMN_LINE = re.compile(r"^\s*`(?P<name>[a-z0-9_]+)`\s+(?P<rest>\S.*)$", re.DOTALL)
_PRIMARY_KEY = re.compile(r"PRIMARY\s+KEY\s*\(([^)]*)\)", re.IGNORECASE)
_ADD_COLUMN = re.compile(r"ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?`(?P<name>[a-z0-9_]+)`", re.IGNORECASE)
_DROP_COLUMN = re.compile(r"DROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?`(?P<name>[a-z0-9_]+)`", re.IGNORECASE)
_FOREIGN_KEY = re.compile(
    r"FOREIGN\s+KEY\s*\(`(?P<column>[a-z0-9_]+)`\)\s*REFERENCES\s*(?P<target>.{0,120}?)\s*\(`(?P<ref>[a-z0-9_]+)`\)"
    r"(?P<ondelete>\s+ON\s+DELETE\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?",
    re.IGNORECASE | re.DOTALL,
)
_MIGRATION_VERSION = re.compile(r"^(\d{4}_\d{2}_\d{2}_\d{4})_")


@dataclass
class ForeignKey:
    """One declared foreign key inside a table body."""

    column: str
    references_table: str
    references_column: str
    on_delete: str = ""


@dataclass
class TableSchema:
    """Shape of one CPMS table as declared by the migrations."""

    name: str
    columns: List[str] = field(default_factory=list)
    primary_key: List[str] = field(default_factory=list)
    foreign_keys: List[ForeignKey] = field(default_factory=list)
    introduced_by: str = ""
    dropped_by: str = ""
    exists: bool = True

    @property
    def surrogate_id(self) -> bool:
        """True when the table's primary key is a single ``id`` column."""
        return self.primary_key == ["id"]

    def column_set(self) -> Set[str]:
        return set(self.columns)

    def referenced_tables(self) -> Set[str]:
        return {fk.references_table for fk in self.foreign_keys if fk.references_table}


@dataclass
class ProductionSchema:
    """Derived view of the production plugin's schema."""

    migrations_dir: Path
    versions: List[str] = field(default_factory=list)
    tables: Dict[str, TableSchema] = field(default_factory=dict)
    created_by_version: Dict[str, List[str]] = field(default_factory=dict)
    #: Statements whose table target could not be resolved statically.
    unresolved: List[str] = field(default_factory=list)

    @property
    def head(self) -> str:
        return self.versions[-1] if self.versions else ""

    def live_tables(self) -> Set[str]:
        return {n for n, t in self.tables.items() if t.exists and not t.dropped_by}

    def tables_added_in(self, version: str) -> List[str]:
        return list(self.created_by_version.get(version, []))


def _mask_comments(source: str) -> str:
    """Blank out PHP comments while preserving length and newline offsets.

    Migration docblocks talk about ``CREATE TABLE``/``DROP TABLE`` in prose.
    Scanning raw text would report those as unresolved DDL, so comments are
    replaced by spaces. Offsets into the returned string map 1:1 onto the
    original source, which the body extractor relies on.
    """
    out: List[str] = []
    index = 0
    length = len(source)
    quote = ""
    while index < length:
        char = source[index]
        if quote:
            out.append(char)
            if char == "\\" and index + 1 < length:
                out.append(source[index + 1])
                index += 2
                continue
            if char == quote:
                quote = ""
            index += 1
            continue
        if char in ("'", '"'):
            quote = char
            out.append(char)
            index += 1
            continue
        if source.startswith("/*", index):
            end = source.find("*/", index + 2)
            end = length if end < 0 else end + 2
            out.extend(c if c == "\n" else " " for c in source[index:end])
            index = end
            continue
        if source.startswith("//", index) or char == "#":
            end = source.find("\n", index)
            end = length if end < 0 else end
            out.extend(" " * (end - index))
            index = end
            continue
        out.append(char)
        index += 1
    return "".join(out)


def _split_top_level(body: str) -> List[str]:
    """Split a CREATE TABLE body on commas that are not inside parentheses."""
    parts: List[str] = []
    depth = 0
    current = ""
    for char in body:
        if char == "(":
            depth += 1
        elif char == ")":
            depth -= 1
        if char == "," and depth == 0:
            parts.append(current)
            current = ""
        else:
            current += char
    if current.strip():
        parts.append(current)
    return parts


def _balanced(source: str, open_index: int) -> str:
    """Return the parenthesised body that starts at ``open_index`` (the '(')."""
    depth = 0
    for offset in range(open_index, len(source)):
        char = source[offset]
        if char == "(":
            depth += 1
        elif char == ")":
            depth -= 1
            if depth == 0:
                return source[open_index + 1 : offset]
    return source[open_index + 1 :]


def _resolve_target(tail: str, var_to_name: Dict[str, str]) -> Optional[str]:
    """Resolve the table name that follows a ``CREATE/ALTER/DROP TABLE``."""
    resolved = _resolve_target_span(tail, var_to_name)
    return resolved[0] if resolved else None


def _resolve_target_span(tail: str, var_to_name: Dict[str, str]) -> Optional[Tuple[str, int]]:
    """Resolve the table name *and* how much of ``tail`` it consumed.

    The consumed length matters: the CREATE TABLE body starts at the first
    parenthesis *after* the target, and the concatenated form
    ``' . $db->table('cpms_x') . ' (`` contains a parenthesis of its own that
    must not be mistaken for the start of the column list.
    """
    lead = len(tail) - len(tail.lstrip())
    stripped = tail.lstrip()
    for form in _TARGET_FORMS:
        match = form.match(stripped)
        if match is None:
            continue
        groups = match.groupdict()
        name = groups.get("name")
        if not name:
            var = groups.get("var")
            name = var_to_name.get(var) if var else None
        if name:
            return name, lead + match.end()
    return None


def _primary_key_of(body: str) -> List[str]:
    match = _PRIMARY_KEY.search(body)
    if match is None:
        return []
    return [c.strip().strip("`") for c in match.group(1).split(",") if c.strip()]


def _columns_of(body: str) -> List[str]:
    columns: List[str] = []
    for part in _split_top_level(body):
        match = _COLUMN_LINE.match(part)
        if match is not None:
            columns.append(match.group("name"))
    return columns


def _foreign_keys_of(body: str, var_to_name: Dict[str, str]) -> List[ForeignKey]:
    keys: List[ForeignKey] = []
    for match in _FOREIGN_KEY.finditer(body):
        target_text = match.group("target")
        resolved = _resolve_target(target_text, var_to_name)
        if resolved is None:
            literal = re.search(r"'([a-z0-9_]+)'", target_text)
            resolved = literal.group(1) if literal else ""
        on_delete = (match.group("ondelete") or "").strip().upper()
        keys.append(
            ForeignKey(
                column=match.group("column"),
                references_table=resolved,
                references_column=match.group("ref"),
                on_delete=re.sub(r"\s+", " ", on_delete.replace("ON DELETE", "")).strip(),
            )
        )
    return keys


def _down_offset(source: str) -> int:
    index = source.find("'down'")
    return index if index >= 0 else len(source)


def parse_migrations(migrations_dir: Path) -> ProductionSchema:
    """Parse every migration file and derive the resulting schema shape.

    Migrations are applied in version order, so a later ``ALTER``/``DROP``
    refines or removes what an earlier ``CREATE`` declared. ``DROP TABLE``
    statements inside a migration's development-only ``down`` callback never
    retire a table from the forward schema.
    """
    schema = ProductionSchema(migrations_dir=migrations_dir)
    for path in sorted(migrations_dir.glob("*.php")):
        version_match = _MIGRATION_VERSION.match(path.stem)
        if version_match is None:
            continue  # MigrationRunner.php and friends are not versioned.
        version = version_match.group(1)
        schema.versions.append(version)
        source = _mask_comments(path.read_text(encoding="utf-8"))
        var_to_name = {m.group("var"): m.group("name") for m in _TABLE_VAR.finditer(source)}
        down_at = _down_offset(source)
        created: List[str] = []

        for ddl in _DDL.finditer(source):
            kind = ddl.group("kind").upper()
            tail = ddl.group("tail")
            resolved = _resolve_target_span(tail, var_to_name)
            name = resolved[0] if resolved else None
            in_down = ddl.start() >= down_at

            if name is None or not name.startswith("cpms_"):
                if name is None and not in_down:
                    schema.unresolved.append(f"{path.name}:{kind}")
                continue

            if kind == "CREATE":
                tail_start = ddl.end("tail") - len(tail)
                open_index = source.find("(", tail_start + resolved[1])
                body = _balanced(source, open_index) if open_index >= 0 else ""
                existing = schema.tables.get(name)
                if existing is None:
                    schema.tables[name] = TableSchema(
                        name=name,
                        columns=_columns_of(body),
                        primary_key=_primary_key_of(body),
                        foreign_keys=_foreign_keys_of(body, var_to_name),
                        introduced_by=version,
                    )
                    created.append(name)
                else:
                    # CREATE TABLE IF NOT EXISTS re-run after a rollback.
                    existing.exists = True
                    existing.dropped_by = ""
                    if not existing.columns:
                        existing.columns = _columns_of(body)
                        existing.primary_key = _primary_key_of(body)
                        existing.foreign_keys = _foreign_keys_of(body, var_to_name)
                continue

            if in_down:
                continue  # development-only rollback: not part of the forward schema

            table = schema.tables.get(name)
            if kind == "ALTER":
                if table is None:
                    continue
                for added in _ADD_COLUMN.finditer(tail):
                    if added.group("name") not in table.columns:
                        table.columns.append(added.group("name"))
                for dropped in _DROP_COLUMN.finditer(tail):
                    table.columns = [c for c in table.columns if c != dropped.group("name")]
            elif kind == "DROP":
                if table is not None and table.introduced_by != version:
                    table.exists = False
                    table.dropped_by = version

        if created:
            schema.created_by_version[version] = created

    _parse_infrastructure_tables(schema)
    return schema


def _parse_infrastructure_tables(schema: ProductionSchema) -> None:
    """Pick up tables created outside the versioned migrations.

    ``MigrationRunner`` creates the ``cpms_schema_migrations`` bookkeeping table
    itself (it must exist before any version can be recorded), so it is not
    declared by a versioned migration. Scanning the non-versioned files in the
    migrations directory keeps the derived inventory complete without hardcoding
    a table name here.
    """
    for path in sorted(schema.migrations_dir.glob("*.php")):
        if _MIGRATION_VERSION.match(path.stem) is not None:
            continue
        source = _mask_comments(path.read_text(encoding="utf-8"))
        constants = {m.group("const"): m.group("name") for m in _CLASS_CONST.finditer(source)}
        var_to_name = {m.group("var"): m.group("name") for m in _TABLE_VAR.finditer(source)}
        for match in _TABLE_CONST_VAR.finditer(source):
            name = constants.get(match.group("const"))
            if name:
                var_to_name[match.group("var")] = name

        for ddl in _DDL.finditer(source):
            if ddl.group("kind").upper() != "CREATE":
                continue
            tail = ddl.group("tail")
            resolved = _resolve_target_span(tail, var_to_name)
            if resolved is None or not resolved[0].startswith("cpms_"):
                continue
            name = resolved[0]
            if name in schema.tables:
                continue
            tail_start = ddl.end("tail") - len(tail)
            open_index = source.find("(", tail_start + resolved[1])
            body = _balanced(source, open_index) if open_index >= 0 else ""
            schema.tables[name] = TableSchema(
                name=name,
                columns=_columns_of(body),
                primary_key=_primary_key_of(body),
                foreign_keys=_foreign_keys_of(body, var_to_name),
                introduced_by=path.name,
            )


def load(plugin_dir: Path) -> ProductionSchema:
    """Derive the production schema from a plugin directory."""
    return parse_migrations(plugin_dir / "src" / "Migrations")


def describe(schema: ProductionSchema) -> Dict[str, object]:
    """Serialisable summary used by the evaluator report and the generator."""
    return {
        "schema_head": schema.head,
        "migration_count": len(schema.versions),
        "table_count": len(schema.live_tables()),
        "unresolved_statements": sorted(set(schema.unresolved)),
        "tables": {
            name: {
                "columns": list(table.columns),
                "primary_key": list(table.primary_key),
                "surrogate_id": table.surrogate_id,
                "introduced_by": table.introduced_by,
                "foreign_keys": [
                    {
                        "column": fk.column,
                        "references_table": fk.references_table,
                        "references_column": fk.references_column,
                        "on_delete": fk.on_delete,
                    }
                    for fk in table.foreign_keys
                ],
            }
            for name, table in sorted(schema.tables.items())
            if table.exists and not table.dropped_by
        },
    }


if __name__ == "__main__":  # pragma: no cover - manual inspection helper
    import json
    import sys

    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    print(json.dumps(describe(load(root / "clinic-practice-management")), indent=2, sort_keys=True))

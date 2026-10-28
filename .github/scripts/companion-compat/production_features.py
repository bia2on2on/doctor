#!/usr/bin/env python3
"""Derive the production plugin's role, capability and REST feature surface.

Used by the compatibility evaluator to answer two questions objectively:

* did the set of CPMS roles/capabilities change relative to the manifest?
* did production grow a REST route surface that the demo has not declared a
  journey for?

Like :mod:`production_schema`, this module only *derives shape*. Whether a
feature is demo-required, and which browser journey demonstrates it, are
hand-authored manifest facts.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, List, Optional, Set, Tuple

from production_schema import _mask_comments

_ROLE_CONST = re.compile(r"const\s+ROLE_(?P<key>[A-Z0-9_]+)\s*=\s*'(?P<name>cpms_[a-z0-9_]+)'")
_CAP_CONST = re.compile(r"const\s+(?P<key>[A-Z0-9_]+)\s*=\s*'(?P<name>cpms_[a-z0-9_]+)'")
_SELF_REF = re.compile(r"self::(?P<key>[A-Z0-9_]+)")
_REST_NS = re.compile(r"const\s+NS\s*=\s*'(?P<ns>[^']+)'")
_CLASS_EXTENDS = re.compile(
    r"(?:final\s+|abstract\s+)?class\s+(?P<name>[A-Za-z0-9_]+)\s+extends\s+(?P<parent>[A-Za-z0-9_]+)"
)
_REST_ROUTE = re.compile(
    r"register_rest_route\(\s*(?P<ns>self::NS|'[^']*')\s*,\s*'(?P<path>[^']*)'\s*,\s*\[(?P<body>.*?)\n\s*\]\s*\)",
    re.DOTALL,
)
_METHODS = re.compile(r"'methods'\s*=>\s*(?P<value>[^,\n]+)")
_WP_METHOD = re.compile(r"WP_REST_Server::(?P<name>[A-Z_]+)")


@dataclass
class RestRoute:
    """One registered REST route."""

    controller: str
    namespace: str
    path: str
    methods: List[str] = field(default_factory=list)

    @property
    def full_path(self) -> str:
        return f"{self.namespace}{self.path}"


@dataclass
class FeatureSurface:
    """Derived role/capability/route surface of the production plugin."""

    roles: List[str] = field(default_factory=list)
    capabilities: List[str] = field(default_factory=list)
    role_caps: Dict[str, List[str]] = field(default_factory=dict)
    routes: List[RestRoute] = field(default_factory=list)
    unparsed: List[str] = field(default_factory=list)

    def route_paths(self) -> Set[str]:
        return {r.full_path for r in self.routes}

    def controllers(self) -> Set[str]:
        return {r.controller for r in self.routes}


_METHOD_EXPANSION = {
    "READABLE": ["GET"],
    "CREATABLE": ["POST"],
    "EDITABLE": ["POST", "PUT", "PATCH"],
    "DELETABLE": ["DELETE"],
    "ALLMETHODS": ["GET", "POST", "PUT", "PATCH", "DELETE"],
}


def _expand_methods(value: str) -> List[str]:
    """Turn a ``'methods' =>`` expression into a sorted list of HTTP verbs."""
    out: Set[str] = set()
    for match in _WP_METHOD.finditer(value):
        out.update(_METHOD_EXPANSION.get(match.group("name"), []))
    literal = re.findall(r"'(GET|POST|PUT|PATCH|DELETE)'", value)
    out.update(literal)
    if not out:
        # A bare concatenation such as 'GET, POST' still lists the verbs.
        out.update(re.findall(r"\b(GET|POST|PUT|PATCH|DELETE)\b", value))
    return sorted(out)


def _capability_constants(source: str) -> Dict[str, str]:
    """Map ``CONST_KEY`` -> ``cpms_capability`` for capability-looking constants."""
    out: Dict[str, str] = {}
    for match in _CAP_CONST.finditer(source):
        key = match.group("key")
        name = match.group("name")
        if key.startswith("ROLE_") or key.startswith("OPTION_"):
            continue
        out[key] = name
    return out


def _resolve_cap_list(body: str, constants: Dict[str, str]) -> Tuple[List[str], bool]:
    """Resolve ``self::X`` references inside a capability list literal."""
    resolved: List[str] = []
    ok = True
    for match in _SELF_REF.finditer(body):
        name = constants.get(match.group("key"))
        if name is None:
            ok = False
            continue
        resolved.append(name)
    # Anything that is not a self:: reference must be a literal string.
    for literal in re.findall(r"'(cpms_[a-z0-9_]+)'", body):
        resolved.append(literal)
    stripped = _SELF_REF.sub("", body)
    stripped = re.sub(r"'cpms_[a-z0-9_]+'", "", stripped)
    if re.search(r"[A-Za-z_]{3,}", stripped):
        ok = False  # an unrecognised token: fail closed rather than guess
    return resolved, ok



def load(plugin_dir: Path) -> FeatureSurface:
    """Derive roles, capabilities and REST routes from the plugin sources."""
    surface = FeatureSurface()

    auth_path = plugin_dir / "src" / "Auth" / "RolesAndCapabilities.php"
    if auth_path.is_file():
        source = _mask_comments(auth_path.read_text(encoding="utf-8"))
        surface.roles = sorted({m.group("name") for m in _ROLE_CONST.finditer(source)})
        constants = _capability_constants(source)
        match = re.search(r"const\s+ALL_CAPS\s*=\s*\[(?P<body>[^\]]*)\]", source, re.DOTALL)
        if match is None:
            surface.unparsed.append("RolesAndCapabilities.php:ALL_CAPS")
        else:
            caps, ok = _resolve_cap_list(match.group("body"), constants)
            surface.capabilities = sorted(set(caps))
            if not ok:
                surface.unparsed.append("RolesAndCapabilities.php:ALL_CAPS(unresolved)")
            for role_list in ("SECRETARY_CAPS", "DOCTOR_CAPS", "ACCOUNTANT_CAPS", "MANAGER_CAPS"):
                role_match = re.search(
                    r"const\s+" + role_list + r"\s*=\s*\[(?P<body>[^\]]*)\]", source, re.DOTALL
                )
                if role_match is None:
                    surface.unparsed.append(f"RolesAndCapabilities.php:{role_list}")
                    continue
                caps, ok = _resolve_cap_list(role_match.group("body"), constants)
                surface.role_caps[role_list.replace("_CAPS", "").lower()] = sorted(set(caps))
                if not ok:
                    surface.unparsed.append(f"RolesAndCapabilities.php:{role_list}(unresolved)")
    else:
        surface.unparsed.append("RolesAndCapabilities.php:missing")

    rest_dir = plugin_dir / "src" / "Rest"
    if rest_dir.is_dir():
        files = {path.stem: _mask_comments(path.read_text(encoding="utf-8")) for path in sorted(rest_dir.glob("*.php"))}
        # `NS` is declared once on RestBase and inherited by every controller, so
        # namespaces are resolved through the extends chain before routes are read.
        own_ns = {name: m.group("ns") for name, src in files.items() if (m := _REST_NS.search(src))}
        parents = {name: m.group("parent") for name, src in files.items() if (m := _CLASS_EXTENDS.search(src))}

        def resolve_ns(name: str, depth: int = 0) -> str:
            if name in own_ns:
                return own_ns[name]
            parent = parents.get(name)
            if not parent or depth > 8:
                return ""
            return resolve_ns(parent, depth + 1)

        for name, source in files.items():
            default_ns = resolve_ns(name)
            for route in _REST_ROUTE.finditer(source):
                ns = route.group("ns")
                namespace = default_ns if ns == "self::NS" else ns.strip("'")
                methods = _expand_methods(route.group("body"))
                surface.routes.append(
                    RestRoute(
                        controller=name,
                        namespace=namespace,
                        path=route.group("path"),
                        methods=methods,
                    )
                )
            if not default_ns and "register_rest_route" in source:
                surface.unparsed.append(f"{name}.php:NS")
    else:
        surface.unparsed.append("src/Rest:missing")

    return surface


def describe(surface: FeatureSurface) -> Dict[str, object]:
    """Serialisable summary used by the evaluator report."""
    return {
        "roles": list(surface.roles),
        "capability_count": len(surface.capabilities),
        "capabilities": list(surface.capabilities),
        "role_caps": {k: list(v) for k, v in sorted(surface.role_caps.items())},
        "route_count": len(surface.routes),
        "routes": sorted(surface.route_paths()),
        "controllers": sorted(surface.controllers()),
        "unparsed": list(surface.unparsed),
    }


if __name__ == "__main__":  # pragma: no cover - manual inspection helper
    import json
    import sys

    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    print(json.dumps(describe(load(root / "clinic-practice-management")), indent=2, sort_keys=True))

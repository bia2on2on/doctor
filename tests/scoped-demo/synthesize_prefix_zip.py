#!/usr/bin/env python3
"""Synthesize the PRE-FIX (two-header) standalone ZIP for the RED control.

The CI lane must prove that its real-WordPress installer drill actually
*observes* the defect that bin/build-demo-installer.sh now prevents — otherwise
a GREEN run would only prove that the drill is silent, not that the fix matters.
Rebuilding the old script is not an option (it no longer exists on the branch),
so the RED artifact is derived from the fixed ZIP by one deterministic edit:
line 3 of the embedded production bootstrap is restored to the production
`Plugin Name:` header — exactly the byte layout the broken package shipped.

Usage:
    python3 tests/scoped-demo/synthesize_prefix_zip.py <fixed.zip> <red.zip>

The output is deterministic (fixed timestamps, same member order, same
compression), so two runs on the same input produce byte-identical files.
"""

from __future__ import annotations

import sys
import zipfile
from pathlib import Path

EMBEDDED = "cpms-disposable-demo/core/clinic-practice-management.php"
PRODUCTION_HEADER = " * Plugin Name:       Clinic Practice Management (CPMS)"
FIXED_TIME = (1980, 1, 1, 0, 0, 0)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print(__doc__, file=sys.stderr)
        return 2
    src, dst = Path(argv[1]), Path(argv[2])
    with zipfile.ZipFile(src) as zin:
        names = zin.namelist()
        if EMBEDDED not in names:
            print(f"ERROR: {EMBEDDED} missing from {src}", file=sys.stderr)
            return 1
        original = zin.read(EMBEDDED).decode("utf-8")
        lines = original.split("\n")
        if len(lines) < 3 or not lines[2].startswith(" * Embedded Core:"):
            print("ERROR: the fixed package does not carry the neutralized header on line 3", file=sys.stderr)
            return 1
        lines[2] = PRODUCTION_HEADER
        patched = "\n".join(lines).encode("utf-8")
        dst.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(dst, "w", compression=zipfile.ZIP_DEFLATED) as zout:
            for name in names:
                info = zipfile.ZipInfo(name, date_time=FIXED_TIME)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = zin.getinfo(name).external_attr
                payload = patched if name == EMBEDDED else zin.read(name)
                zout.writestr(info, payload)
    headers = 0
    with zipfile.ZipFile(dst) as check:
        for name in check.namelist():
            if name.endswith(".php") and name.count("/") <= 2:
                head = check.read(name)[:8192].decode("utf-8", "replace")
                if "Plugin Name:" in head:
                    headers += 1
    print(f"synthesized RED package {dst} plugin_headers={headers}")
    return 0 if headers == 2 else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv))

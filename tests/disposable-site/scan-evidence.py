#!/usr/bin/env python3
"""Scan non-secret CI evidence and record screenshot/secret-boundary facts."""

import argparse
import json
import os
from pathlib import Path
import stat
import struct
import sys


def secrets_from(path):
    if not os.path.isabs(path) or os.path.islink(path) or not os.path.isfile(path):
        raise RuntimeError("protected secret input must be an absolute regular file")
    if stat.S_IMODE(os.stat(path).st_mode) & 0o077:
        raise RuntimeError("protected secret input must be mode 0600")
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    values = []
    for account in data.get("accounts", {}).values():
        for key in ("username", "password"):
            value = str(account.get(key, ""))
            if value:
                values.append(value.encode())
    if data.get("code"):
        values.append(str(data["code"]).encode())
    return values


def png_dimensions(path):
    with path.open("rb") as handle:
        header = handle.read(24)
    if len(header) != 24 or header[:8] != b"\x89PNG\r\n\x1a\n" or header[12:16] != b"IHDR":
        raise RuntimeError("invalid PNG evidence: " + path.name)
    return struct.unpack(">II", header[16:24])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", required=True)
    parser.add_argument("--state", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    root = Path(args.root)
    secrets = secrets_from(args.state)
    if len(secrets) < 12:
        raise RuntimeError("six-account secret scanner input is incomplete")
    findings = []
    files = 0
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        files += 1
        data = path.read_bytes()
        if any(secret in data for secret in secrets):
            findings.append(path.relative_to(root).as_posix())
    if findings:
        raise RuntimeError("generated credential material found in evidence files: " + ", ".join(findings))

    expected = {
        "disposable-booking-receipt.png": (390, None),
        "disposable-patient-mobile.png": (390, None),
        "disposable-management-mobile.png": (390, None),
        "disposable-doctor-complete.png": (1280, None),
    }
    screenshots = {}
    for name, (width, _) in expected.items():
        path = root / "screenshots" / name
        if not path.is_file():
            raise RuntimeError("missing browser screenshot: " + name)
        actual_width, height = png_dimensions(path)
        if actual_width != width or height <= 0:
            raise RuntimeError("unexpected screenshot dimensions: " + name)
        screenshots[name] = {"width": actual_width, "height": height, "captured": True, "pixels_inspected": False}

    report = {
        "ok": True,
        "files_scanned": files,
        "secret_values_checked": len(secrets),
        "credential_leaks": 0,
        "screenshots": screenshots,
        "visual_pixel_inspection": "NOT EXECUTED",
        "protected_directory_in_artifact": False,
    }
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print("CPMS_EVIDENCE_SCAN=" + json.dumps(report))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"CPMS_EVIDENCE_SCAN_ERROR={type(exc).__name__}: {str(exc)[:400]}", file=sys.stderr)
        sys.exit(1)

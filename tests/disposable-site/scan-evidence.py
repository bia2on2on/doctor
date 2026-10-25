#!/usr/bin/env python3
"""Scan non-secret CI evidence and exact committed source for generated credentials."""

import argparse
import json
import os
from pathlib import Path
import re
import stat
import struct
import subprocess
import sys
import tarfile


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
    for key in ("admin_password", "code"):
        value = data.get(key)
        if isinstance(value, str) and value:
            values.append(value.encode())
    if "accounts" in data and len(values) < 12:
        raise RuntimeError("six-account secret scanner input is incomplete")
    if not values:
        raise RuntimeError("secret scanner input contains no generated credentials")
    return values


def contains_secret(data, secret):
    # Avoid pretending that an arbitrary six-digit substring in compressed or
    # binary data is a credential finding. Numeric OTPs use digit boundaries;
    # longer CSPRNG credentials use exact byte matching.
    if secret.isdigit() and len(secret) <= 8:
        return re.search(rb"(?<!\d)" + re.escape(secret) + rb"(?!\d)", data) is not None
    return secret in data


def find_secrets(data, secrets):
    return any(contains_secret(data, secret) for secret in secrets)


def scan_evidence(root, secrets):
    findings = []
    files = 0
    for path in root.rglob("*"):
        if path.is_symlink():
            raise RuntimeError("symbolic link in evidence root: " + path.relative_to(root).as_posix())
        if not path.is_file():
            continue
        files += 1
        data = path.read_bytes()
        if find_secrets(data, secrets):
            findings.append(path.relative_to(root).as_posix())
    if findings:
        raise RuntimeError("generated credential material found in evidence files: " + ", ".join(findings))
    return files


def scan_committed_source(ref, secrets):
    """Scan git archive bytes, not the mutable working tree or .git directory."""
    resolved = subprocess.run(
        ["git", "rev-parse", "--verify", ref + "^{commit}"],
        capture_output=True, text=True, timeout=30,
    )
    if resolved.returncode != 0:
        raise RuntimeError("cannot resolve the requested committed source ref")
    sha = resolved.stdout.strip()
    process = subprocess.Popen(
        ["git", "archive", "--format=tar", sha],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    findings = []
    files = 0
    try:
        with tarfile.open(fileobj=process.stdout, mode="r|") as archive:
            for member in archive:
                if member.isdir():
                    continue
                stream = archive.extractfile(member)
                if stream is None:
                    continue
                files += 1
                data = stream.read()
                if find_secrets(data, secrets):
                    findings.append(member.name)
    finally:
        if process.stdout is not None:
            process.stdout.close()
        stderr = process.stderr.read().decode("utf-8", "replace") if process.stderr is not None else ""
        return_code = process.wait(timeout=30)
    if return_code != 0:
        raise RuntimeError("git archive failed while scanning committed source: " + stderr[:160])
    if findings:
        raise RuntimeError("generated credential material found in committed source: " + ", ".join(findings))
    return sha, files


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
    parser.add_argument("--secret-file", action="append", default=[])
    parser.add_argument("--source-ref", help="exact commit whose tracked blobs must be scanned")
    parser.add_argument("--require-secret-count", type=int, default=0)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    root = Path(args.root)
    secrets = secrets_from(args.state)
    for path in args.secret_file:
        secrets.extend(secrets_from(path))
    if len(secrets) < args.require_secret_count:
        raise RuntimeError("generated-secret proof does not include every required credential")
    if not root.is_dir():
        raise RuntimeError("evidence root is required")

    evidence_files = scan_evidence(root, secrets)
    source_sha = None
    source_files = 0
    if args.source_ref:
        source_sha, source_files = scan_committed_source(args.source_ref, secrets)

    expected = {
        "disposable-booking-receipt.png": (390, None),
        "disposable-patient-mobile.png": (390, None),
        "disposable-management-mobile.png": (390, None),
        "disposable-doctor-complete.png": (1280, None),
        "disposable-finance-diagnostics.png": (1280, None),
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
        "files_scanned": evidence_files,
        "secret_values_checked": len(secrets),
        "unique_secret_values_checked": len(set(secrets)),
        "required_secret_count": args.require_secret_count,
        "credential_leaks": 0,
        "generated_secrets_absent": True,
        "evidence_scan": "PASS",
        "source_ref": source_sha,
        "source_files_scanned": source_files,
        "source_credential_leaks": 0 if args.source_ref else None,
        "source_scan": "PASS" if args.source_ref else "NOT EXECUTED",
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

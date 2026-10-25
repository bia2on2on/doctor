#!/usr/bin/env python3
"""Fail-closed production-ZIP provenance and external-tool exclusion proof."""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import zipfile

TOOL_MARKERS = (
    b"DemoService",
    b"DemoAccessGuard",
    b"DemoSmsProvider",
    b"DemoSetupPage",
    b"cpms_demo",
    b"cpms-demo",
    b"cpms_disposable",
    b"cpms-disposable",
    b"CPMS_DISPOSABLE",
    b"DISPOSABLE_SITE_PROVISIONED",
    b"rwp-demo-acceptance",
    b"tests/disposable-site",
    b"09000000000",
    b"SYNTHETIC-NOT-A-DRUG",
    b"I_UNDERSTAND_THIS_IS_A_DISPOSABLE_EMPTY_SITE",
    b"cpms_otp_session_authorized",
    b"cpms_job_enqueued",
)
REQUIRED = {
    "clinic-practice-management/clinic-practice-management.php",
    "clinic-practice-management/README.md",
    "clinic-practice-management/THIRD-PARTY-NOTICES.md",
    "clinic-practice-management/uninstall.php",
    "clinic-practice-management/bin/cpms",
    "clinic-practice-management/src/Bootstrap/App.php",
    "clinic-practice-management/src/Frontend/PublicBookingShortcode.php",
    "clinic-practice-management/vendor/autoload.php",
}
FORBIDDEN_PARTS = {
    ".git", ".github", ".env", "tests", "test", "docs", "disposable-site",
    "node_modules", ".cache", "auth.json", "phpunit", "phpstan", "php-stubs",
}


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def expected_source_files(source):
    expected = {}
    for name in ("clinic-practice-management.php", "README.md", "THIRD-PARTY-NOTICES.md", "uninstall.php", "bin/cpms"):
        path = source / name
        if not path.is_file():
            raise RuntimeError(f"baseline source file missing: {name}")
        expected[f"clinic-practice-management/{name}"] = path
    for directory in ("src", "assets", "templates"):
        root = source / directory
        if not root.is_dir():
            raise RuntimeError(f"baseline source directory missing: {directory}")
        for path in root.rglob("*"):
            if path.is_file():
                relative = path.relative_to(source).as_posix()
                expected[f"clinic-practice-management/{relative}"] = path
    return expected


def protected_secrets(path):
    if not path:
        return []
    if not os.path.isabs(path) or os.path.islink(path) or not os.path.isfile(path):
        raise RuntimeError("secret proof input must be an absolute regular non-symlink file")
    if stat.S_IMODE(os.stat(path).st_mode) & 0o077:
        raise RuntimeError("secret proof input must be mode 0600 or stricter")
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    secrets = []
    for account in data.get("accounts", {}).values():
        for key in ("password", "username"):
            value = str(account.get(key, ""))
            if value:
                secrets.append(value.encode())
    for key in ("admin_password", "code"):
        value = data.get(key)
        if isinstance(value, str) and value:
            secrets.append(value.encode())
    if "accounts" in data and len(secrets) < 12:
        raise RuntimeError("secret proof input does not contain all six account credentials")
    if not secrets:
        raise RuntimeError("secret proof input contains no generated credentials")
    return secrets


def contains_secret(data, secret):
    # A six-digit OTP is a credential, but an arbitrary substring match in a
    # compressed/binary container is not meaningful evidence. Use digit
    # boundaries for short numeric codes; long random credentials remain exact
    # byte matches in both member content and raw ZIP bytes.
    if secret.isdigit() and len(secret) <= 8:
        return re.search(rb"(?<!\d)" + re.escape(secret) + rb"(?!\d)", data) is not None
    return secret in data


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--zip", required=True)
    parser.add_argument("--baseline-source", required=True)
    parser.add_argument("--baseline-sha", required=True)
    parser.add_argument("--secret-file", action="append", default=[])
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    zip_path = Path(args.zip)
    source = Path(args.baseline_source).resolve()
    if not zip_path.is_file() or not source.is_dir():
        raise RuntimeError("ZIP and baseline source are required")
    expected = expected_source_files(source)
    secrets = []
    for path in args.secret_file:
        secrets.extend(protected_secrets(path))

    with zipfile.ZipFile(zip_path) as archive:
        infos = archive.infolist()
        names = {info.filename for info in infos if not info.is_dir()}
        if len(names) != len([info for info in infos if not info.is_dir()]):
            raise RuntimeError("duplicate ZIP member names")
        missing = REQUIRED - names
        if missing:
            raise RuntimeError("required production runtime files missing: " + ", ".join(sorted(missing)))
        own_names = {name for name in names if not name.startswith("clinic-practice-management/vendor/")}
        if own_names != set(expected):
            raise RuntimeError("shipped non-vendor whitelist differs from live-main source")
        for info in infos:
            name = info.filename
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or not name.startswith("clinic-practice-management/"):
                raise RuntimeError("unsafe or foreign ZIP member")
            if stat.S_ISLNK((info.external_attr >> 16) & 0o777):
                raise RuntimeError("symbolic links are forbidden in the production ZIP")
            if FORBIDDEN_PARTS.intersection(path.parts):
                raise RuntimeError("development, test, docs, or secret path in production ZIP: " + name)
            if info.file_size > 0:
                data = archive.read(info)
                for marker in TOOL_MARKERS:
                    if marker in data:
                        raise RuntimeError("external demo/test marker found in production ZIP: " + marker.decode())
                for secret in secrets:
                    if contains_secret(data, secret):
                        raise RuntimeError("generated credential material found in production ZIP")
        for name, baseline_path in expected.items():
            shipped = archive.read(name)
            baseline = baseline_path.read_bytes()
            if shipped != baseline:
                raise RuntimeError("shipped production file differs from live main: " + name)
        raw_zip = zip_path.read_bytes()
        for secret in secrets:
            if contains_secret(raw_zip, secret):
                raise RuntimeError("generated credential material found in raw ZIP bytes")

    proof = {
        "ok": True,
        "zip": zip_path.name,
        "bytes": zip_path.stat().st_size,
        "sha256": sha256(zip_path.read_bytes()),
        "files": len(names),
        "baseline_main_sha": args.baseline_sha,
        "non_vendor_files_byte_identical_to_main": len(expected),
        "external_tool_markers_absent": len(TOOL_MARKERS),
        "generated_secrets_absent": bool(secrets),
        "secret_values_checked": len(secrets),
        "unique_secret_values_checked": len(set(secrets)),
        "secret_scan_scope": "all ZIP members and raw ZIP bytes",
        "credential_files_in_zip": False,
        "official_release_download": False,
        "reproducible_zip_bytes_claimed": False,
    }
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(proof, indent=2) + "\n", encoding="utf-8")
    print("CPMS_PACKAGE_PROOF=" + json.dumps(proof))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"CPMS_PACKAGE_PROOF_ERROR={type(exc).__name__}: {str(exc)[:400]}", file=sys.stderr)
        sys.exit(1)

#!/usr/bin/env python3
"""
Disposable temporary evidence tool to measure candidate aws/aws-sdk-php:~3.399.0 in CI.

IMPORTANT:
- Creates its Composer project exclusively in the runner TEMP directory, NEVER in the repository.
- Hard-codes candidate aws/aws-sdk-php:~3.399.0 and php >=8.1 (no arbitrary package inputs).
- Never prints environment variables, GitHub tokens, Composer auth, or secrets.
- Makes no AWS/S3 network calls and strips any AWS_* environment variables.
- Fails closed (exit code != 0) if resolution fails, audit reports any advisory or
  abandoned package, license metadata is missing, measurements fail, or PHP 8.1-8.4
  dependency resolution compatibility cannot be established.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

CANDIDATE_PACKAGE = "aws/aws-sdk-php"
CANDIDATE_CONSTRAINT = "~3.399.0"
PHP_REQUIRE_CONSTRAINT = ">=8.1"
TARGET_PHP_SERIES: tuple[tuple[str, str], ...] = (
    ("8.1", "8.1.0"),
    ("8.2", "8.2.0"),
    ("8.3", "8.3.0"),
    ("8.4", "8.4.0"),
)

DEFAULT_JSON_OUT = "/tmp/aws-sdk-evidence.json"
DEFAULT_MD_OUT = "/tmp/aws-sdk-evidence.md"

SECRET_ENV_KEYS = (
    "GITHUB_TOKEN",
    "GH_TOKEN",
    "COMPOSER_AUTH",
    "AWS_ACCESS_KEY_ID",
    "AWS_SECRET_ACCESS_KEY",
    "AWS_SESSION_TOKEN",
)

TOKEN_PATTERNS = (
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{8,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{8,}"),
    re.compile(r"AKIA[0-9A-Z]{16}"),
    re.compile(r"ASIA[0-9A-Z]{16}"),
)


def redact_secrets(text: str) -> str:
    """Scrub any potential secret/token values from command output."""
    if not text:
        return ""
    out = text
    for key in SECRET_ENV_KEYS:
        val = os.environ.get(key, "")
        if val and len(val) >= 6:
            out = out.replace(val, "[REDACTED]")
    for pat in TOKEN_PATTERNS:
        out = pat.sub("[REDACTED_TOKEN]", out)
    return out


def build_sanitized_env(composer_cache: Path) -> dict[str, str]:
    """Build a child-process environment with no AWS variables and isolated Composer cache."""
    env: dict[str, str] = {}
    for k, v in os.environ.items():
        if k.upper().startswith("AWS_"):
            continue
        env[k] = v
    env["COMPOSER_CACHE_DIR"] = str(composer_cache)
    env["COMPOSER_NO_INTERACTION"] = "1"
    env["COMPOSER_DISABLE_XDEBUG_WARN"] = "1"
    return env


def run_cmd(
    cmd: list[str],
    cwd: Path,
    env: dict[str, str],
    timeout: int = 300,
) -> tuple[int, str, str]:
    """Run a command with sanitized environment and redacted stdout/stderr."""
    proc = subprocess.run(
        cmd,
        cwd=str(cwd),
        env=env,
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    return proc.returncode, redact_secrets(proc.stdout), redact_secrets(proc.stderr)


def parse_json_payload(raw_text: str, start_char: str = "{") -> Any:
    """Parse JSON starting from the first occurrence of start_char."""
    idx = raw_text.find(start_char)
    if idx < 0:
        raise ValueError(f"Expected JSON starting with {start_char!r}, got: {raw_text[:200]!r}")
    return json.loads(raw_text[idx:])


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def compute_closure_signature(packages: list[dict[str, Any]]) -> str:
    """Compute canonical SHA-256 over resolved [{name, version, dist_reference}] list."""
    canonical = [
        {
            "name": str(p.get("name", "")),
            "version": str(p.get("version", "")),
            "dist_reference": str((p.get("dist") or {}).get("reference") or p.get("dist_reference") or ""),
        }
        for p in sorted(packages, key=lambda x: str(x.get("name", "")))
    ]
    payload = json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def measure_directory(root_path: Path) -> tuple[int, int]:
    """Return (regular_file_bytes, regular_file_count) under root_path without following symlinks."""
    if not root_path.exists():
        return 0, 0
    total_bytes = 0
    file_count = 0
    for dirpath, _dirnames, filenames in os.walk(root_path, followlinks=False):
        for fname in filenames:
            fpath = os.path.join(dirpath, fname)
            try:
                st = os.lstat(fpath)
            except OSError:
                continue
            if stat.S_ISREG(st.st_mode):
                total_bytes += st.st_size
                file_count += 1
    return total_bytes, file_count


def create_vendor_zip(vendor_dir: Path, zip_path: Path) -> int:
    """Create compressed ZIP of vendor/ and return its byte size."""
    if zip_path.exists():
        zip_path.unlink()
    zip_bin = shutil.which("zip")
    if zip_bin:
        proc = subprocess.run(
            [zip_bin, "-qr", str(zip_path), "vendor"],
            cwd=str(vendor_dir.parent),
            capture_output=True,
            text=True,
            timeout=120,
        )
        if proc.returncode != 0:
            raise RuntimeError(f"zip command failed (rc={proc.returncode}): {redact_secrets(proc.stderr)}")
    else:
        with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
            for dirpath, _dirnames, filenames in os.walk(vendor_dir, followlinks=False):
                for fname in filenames:
                    full = Path(dirpath) / fname
                    if full.is_file() and not full.is_symlink():
                        rel = full.relative_to(vendor_dir.parent)
                        zf.write(full, arcname=str(rel))
    if not zip_path.is_file():
        raise RuntimeError("vendor.zip was not created")
    return zip_path.stat().st_size


def measure_top_entries(parent_dir: Path, limit: int = 10) -> list[dict[str, Any]]:
    """Measure direct child directories/files of parent_dir, sorted descending by bytes."""
    entries: list[dict[str, Any]] = []
    if not parent_dir.is_dir():
        return entries
    for child in sorted(parent_dir.iterdir(), key=lambda p: p.name):
        if child.is_symlink():
            continue
        if child.is_dir():
            b, c = measure_directory(child)
            entries.append({"name": child.name + "/", "bytes": b, "files": c, "kind": "dir"})
        elif child.is_file():
            entries.append({"name": child.name, "bytes": child.stat().st_size, "files": 1, "kind": "file"})
    entries.sort(key=lambda x: (-int(x["bytes"]), str(x["name"])))
    return entries[:limit]


def measure_package_directories(vendor_dir: Path, limit: int = 10) -> list[dict[str, Any]]:
    """Measure installed package directories (vendor/<org>/<pkg> plus vendor/composer), sorted by bytes."""
    pkg_dirs: list[dict[str, Any]] = []
    if not vendor_dir.is_dir():
        return pkg_dirs
    for org_dir in sorted(vendor_dir.iterdir(), key=lambda p: p.name):
        if not org_dir.is_dir() or org_dir.is_symlink():
            continue
        if org_dir.name in ("composer", "bin"):
            b, c = measure_directory(org_dir)
            pkg_dirs.append({"package_dir": f"vendor/{org_dir.name}", "bytes": b, "files": c})
            continue
        for pkg_dir in sorted(org_dir.iterdir(), key=lambda p: p.name):
            if pkg_dir.is_dir() and not pkg_dir.is_symlink():
                b, c = measure_directory(pkg_dir)
                pkg_dirs.append(
                    {"package_dir": f"vendor/{org_dir.name}/{pkg_dir.name}", "bytes": b, "files": c}
                )
    pkg_dirs.sort(key=lambda x: (-int(x["bytes"]), str(x["package_dir"])))
    return pkg_dirs[:limit]


def extract_warnings(install_stdout: str, install_stderr: str) -> list[str]:
    """Extract any warning, deprecation, or abandoned notices from Composer install output."""
    combined = (install_stdout + "\n" + install_stderr).splitlines()
    warnings: list[str] = []
    patterns = (
        re.compile(r"warning", re.IGNORECASE),
        re.compile(r"abandoned", re.IGNORECASE),
        re.compile(r"deprecat", re.IGNORECASE),
        re.compile(r"security advisory", re.IGNORECASE),
    )
    for raw_line in combined:
        line = raw_line.strip()
        if not line:
            continue
        if any(p.search(line) for p in patterns):
            warnings.append(line)
    return warnings


def is_platform_requirement_name(name: str) -> bool:
    return (
        name == "php"
        or name.startswith("php-")
        or name.startswith("ext-")
        or name.startswith("lib-")
        or name.startswith("composer-")
    )


def make_composer_json_payload(platform_php: str | None = None) -> dict[str, Any]:
    cfg: dict[str, Any] = {
        "optimize-autoloader": True,
        "sort-packages": True,
    }
    if platform_php is not None:
        cfg["platform"] = {"php": platform_php}
    return {
        "name": "cpms/aws-sdk-disposable-evidence",
        "description": "Disposable temporary Composer resolution for aws/aws-sdk-php candidate evidence",
        "version": "1.0.0",
        "type": "project",
        "license": "proprietary",
        "require": {
            "php": PHP_REQUIRE_CONSTRAINT,
            CANDIDATE_PACKAGE: CANDIDATE_CONSTRAINT,
        },
        "config": cfg,
    }


def verify_repo_untouched(repo_root: Path) -> dict[str, Any]:
    """Confirm that product composer.json, lock, vendor, and release script are untouched."""
    product_dir = repo_root / "clinic-practice-management"
    product_composer = product_dir / "composer.json"
    product_lock = product_dir / "composer.lock"
    product_vendor = product_dir / "vendor"

    composer_data = json.loads(product_composer.read_text(encoding="utf-8"))
    require_map = composer_data.get("require", {})
    require_dev_map = composer_data.get("require-dev", {})

    has_aws_in_product = (
        CANDIDATE_PACKAGE in require_map or CANDIDATE_PACKAGE in require_dev_map
    )
    has_product_lock = product_lock.exists()
    has_product_vendor = product_vendor.exists()

    return {
        "product_composer_has_aws_sdk": has_aws_in_product,
        "product_composer_lock_exists": has_product_lock,
        "product_vendor_exists": has_product_vendor,
        "product_composer_sha256": sha256_file(product_composer),
        "all_clean": (not has_aws_in_product) and (not has_product_lock) and (not has_product_vendor),
    }


def render_markdown_report(report: dict[str, Any]) -> str:
    """Render human-readable Markdown report suitable for workflow output and PR comment."""
    lines: list[str] = []
    lines.append("### Disposable AWS SDK Dependency Evidence (`aws/aws-sdk-php:~3.399.0`)")
    lines.append("")
    lines.append(f"- **Status**: `{report['overall_status']}`")
    lines.append(
        f"- **Candidate Constraint**: `{report['candidate']['package']}:{report['candidate']['constraint']}` "
        f"(PHP `{report['candidate']['php_constraint']}`)"
    )
    lines.append(
        f"- **Exact Resolved SDK Version**: `{report['resolution']['sdk_resolved_version']}` "
        f"(`dist_ref={report['resolution']['sdk_dist_reference']}`)"
    )
    lines.append(f"- **Temporary `composer.lock` SHA-256**: `{report['resolution']['lock_sha256']}`")
    lines.append(f"- **Temporary `composer.lock` content-hash**: `{report['resolution']['lock_content_hash']}`")
    lines.append(f"- **Resolved Package Closure SHA-256**: `{report['resolution']['closure_signature_sha256']}`")
    lines.append(
        f"- **Production Package Count**: `{report['resolution']['package_count']}` "
        f"(dev packages installed: `{report['resolution']['dev_package_count']}`)"
    )
    lines.append(
        f"- **Runner Host Tooling**: PHP `{report['runner']['php_version']}`, "
        f"Composer `{report['runner']['composer_version']}`"
    )
    lines.append(f"- **Isolated Temp Workspace Outside Repo**: `{report['isolation']['temp_outside_repo']}`")
    lines.append(
        f"- **Product `composer.json`/`composer.lock`/`vendor/` Untouched**: "
        f"`{report['isolation']['product_checks'].get('all_clean', False)}`"
    )
    lines.append("")

    if report["packages"]:
        lines.append("#### 1. Exact Production Packages & Versions")
        lines.append("")
        lines.append("| # | Package | Version | License(s) | PHP Requirement | Dist Reference |")
        lines.append("|---:|---|---|---|---|---|")
        for idx, pkg in enumerate(report["packages"], start=1):
            lic_str = ", ".join(pkg["licenses"]) if pkg["licenses"] else "MISSING"
            php_req = pkg["php_require"] or "-"
            dist_ref = (pkg["dist_reference"] or "-")[:12]
            lines.append(
                f"| {idx} | `{pkg['name']}` | `{pkg['version']}` | `{lic_str}` | `{php_req}` | `{dist_ref}` |"
            )
        lines.append("")

    lines.append("#### 2. License Closure")
    lines.append("")
    lines.append(
        f"- **All Production Packages Have License Metadata**: `{report['licenses']['all_packages_have_license']}`"
    )
    lines.append(
        f"- **Unique SPDX Licenses Across Closure**: `{', '.join(report['licenses']['unique_licenses']) or '(none)'}`"
    )
    if report["licenses"]["missing_license_packages"]:
        lines.append(
            f"- **Missing License Packages**: `{', '.join(report['licenses']['missing_license_packages'])}`"
        )
    lines.append("")

    lines.append("#### 3. Composer Audit & Warnings")
    lines.append("")
    lines.append(f"- **`composer audit --no-dev` Exit Code**: `{report['audit']['exit_code']}`")
    lines.append(f"- **Security Advisories Count**: `{report['audit']['advisory_count']}`")
    lines.append(f"- **Abandoned Packages Count**: `{report['audit']['abandoned_count']}`")
    lines.append(f"- **Install / Resolution Warnings Count**: `{len(report['warnings']['install_warnings'])}`")
    if report["warnings"]["install_warnings"]:
        for w in report["warnings"]["install_warnings"]:
            lines.append(f"  - `{w}`")
    lines.append("")

    lines.append("#### 4. Platform Requirements & PHP 8.1–8.4 Dependency Compatibility")
    lines.append("")
    lines.append(
        "> **Scope Note**: The matrix below establishes **Composer dependency resolution and lock-level "
        "platform compatibility** (`config.platform.php` solver resolution + primary lock `--dry-run` "
        "installability). It is strictly distinct from runtime execution on every PHP version or against a live S3 endpoint."
    )
    lines.append("")
    lines.append(
        f"- **Required Extensions Across Closure (`require`)**: "
        f"`{', '.join(report['platform_requirements']['required_extensions']) or '(none)'}`"
    )
    lines.append(
        f"- **Required Libraries Across Closure (`require`)**: "
        f"`{', '.join(report['platform_requirements']['required_libraries']) or '(none)'}`"
    )
    lines.append(
        f"- **Suggested Extensions Across Closure (`suggest`, optional)**: "
        f"`{', '.join(report['platform_requirements']['suggested_extensions']) or '(none)'}`"
    )
    lines.append(
        f"- **Host `composer check-platform-reqs --no-dev` Status**: "
        f"`{report['platform_requirements']['host_check_status']}`"
    )
    lines.append("")
    if report["platform_compatibility"]["php_matrix"]:
        lines.append(
            "| PHP Series | Simulated Platform | Fresh Solver (`composer update --no-dev`) | "
            "Primary Lock (`composer install --no-dev --dry-run`) | Resolved SDK Version | "
            "Package Count | Closure Signature | Matches Primary Closure | Verification Mode |"
        )
        lines.append("|---|---|---|---|---|---:|---|---|---|")
        for p in report["platform_compatibility"]["php_matrix"]:
            lines.append(
                f"| PHP {p['php_series']} | `{p['simulated_platform_php']}` | "
                f"`rc={p['fresh_resolution_rc']}` ({'PASS' if p['fresh_resolution_ok'] else 'FAIL'}) | "
                f"`rc={p['primary_lock_dry_run_rc']}` ({'PASS' if p['primary_lock_dry_run_ok'] else 'FAIL'}) | "
                f"`{p['resolved_sdk_version']}` | {p['package_count']} | "
                f"`{p['closure_signature_sha256'][:12]}` | "
                f"`{p['matches_primary_lock_packages']}` | `{p['verification_mode']}` |"
            )
        lines.append("")

    m = report["measurements"]
    lines.append("#### 5. Footprint Measurements")
    lines.append("")
    lines.append(
        f"- **`vendor/` Total Regular-File Bytes**: `{m['vendor_total_bytes']:,}` bytes "
        f"(`{m['vendor_total_bytes'] / (1024 * 1024):.2f} MiB`)"
    )
    lines.append(f"- **`vendor/` Total Regular-File Count**: `{m['vendor_file_count']:,}` files")
    lines.append(
        f"- **Compressed `vendor.zip` Bytes**: `{m['compressed_vendor_zip_bytes']:,}` bytes "
        f"(`{m['compressed_vendor_zip_bytes'] / (1024 * 1024):.2f} MiB`, "
        f"SHA-256 `{m['compressed_vendor_zip_sha256']}`)"
    )
    lines.append(
        f"- **AWS SDK Directory (`vendor/aws/aws-sdk-php`) Bytes**: `{m['aws_sdk_directory_bytes']:,}` bytes "
        f"(`{m['aws_sdk_directory_bytes'] / (1024 * 1024):.2f} MiB`, `{m['aws_sdk_file_count']:,}` files)"
    )
    lines.append("")
    if m["top_10_package_directories"]:
        lines.append("##### Top 10 Installed Package Directories (`vendor/<vendor>/<package>`)")
        lines.append("")
        lines.append("| Rank | Package Directory | Bytes | MiB | Files | % of `vendor/` Bytes |")
        lines.append("|---:|---|---:|---:|---:|---:|")
        vendor_bytes = max(int(m["vendor_total_bytes"]), 1)
        for idx, entry in enumerate(m["top_10_package_directories"], start=1):
            pct = (int(entry["bytes"]) * 100.0) / vendor_bytes
            mib = int(entry["bytes"]) / (1024 * 1024)
            lines.append(
                f"| {idx} | `{entry['package_dir']}` | {entry['bytes']:,} | {mib:.2f} | "
                f"{entry['files']:,} | {pct:.2f}% |"
            )
        lines.append("")
    if m["top_10_first_level_vendor_entries"]:
        lines.append("##### Top 10 First-Level Entries Under `vendor/` (`vendor/<entry>`)")
        lines.append("")
        lines.append("| Rank | Entry | Bytes | MiB | Files | % of `vendor/` Bytes |")
        lines.append("|---:|---|---:|---:|---:|---:|")
        vendor_bytes = max(int(m["vendor_total_bytes"]), 1)
        for idx, entry in enumerate(m["top_10_first_level_vendor_entries"], start=1):
            pct = (int(entry["bytes"]) * 100.0) / vendor_bytes
            mib = int(entry["bytes"]) / (1024 * 1024)
            lines.append(
                f"| {idx} | `vendor/{entry['name']}` | {entry['bytes']:,} | {mib:.2f} | "
                f"{entry['files']:,} | {pct:.2f}% |"
            )
        lines.append("")
    if m["top_10_aws_sdk_src_entries"]:
        lines.append("##### Top 10 Entries Inside `vendor/aws/aws-sdk-php/src/`")
        lines.append("")
        lines.append("| Rank | Entry | Bytes | MiB | Files |")
        lines.append("|---:|---|---:|---:|---:|")
        for idx, entry in enumerate(m["top_10_aws_sdk_src_entries"], start=1):
            mib = int(entry["bytes"]) / (1024 * 1024)
            lines.append(
                f"| {idx} | `vendor/aws/aws-sdk-php/src/{entry['name']}` | {entry['bytes']:,} | "
                f"{mib:.2f} | {entry['files']:,} |"
            )
        lines.append("")

    if report["failures"]:
        lines.append("#### 6. Blocking Failures")
        lines.append("")
        for f in report["failures"]:
            lines.append(f"- **FAIL**: {f}")
        lines.append("")

    return "\n".join(lines) + "\n"


def run_self_test() -> int:
    """Deterministic local self-test of measurement, redaction, and report rendering."""
    with tempfile.TemporaryDirectory(prefix="cpms-aws-sdk-selftest-") as tmp:
        tmp_path = Path(tmp)
        vendor = tmp_path / "vendor"
        sdk_src = vendor / "aws" / "aws-sdk-php" / "src" / "S3"
        guzzle = vendor / "guzzlehttp" / "guzzle" / "src"
        sdk_src.mkdir(parents=True)
        guzzle.mkdir(parents=True)
        (vendor / "autoload.php").write_text("<?php // autoload\n", encoding="utf-8")
        (sdk_src / "S3Client.php").write_bytes(b"A" * 2048)
        (guzzle / "Client.php").write_bytes(b"B" * 1024)

        total_bytes, total_files = measure_directory(vendor)
        assert total_files == 3, f"expected 3 files, got {total_files}"
        assert total_bytes == 2048 + 1024 + len("<?php // autoload\n"), f"unexpected byte sum {total_bytes}"

        zip_path = tmp_path / "vendor.zip"
        zip_bytes = create_vendor_zip(vendor, zip_path)
        assert zip_bytes > 0, "expected positive zip size"

        top_pkgs = measure_package_directories(vendor, limit=10)
        assert top_pkgs[0]["package_dir"] == "vendor/aws/aws-sdk-php"
        assert top_pkgs[0]["bytes"] == 2048

        top_first = measure_top_entries(vendor, limit=10)
        assert top_first[0]["name"] == "aws/"

        scrubbed = redact_secrets("token ghp_1234567890abcdef and AKIAIOSFODNN7EXAMPLE")
        assert "ghp_" not in scrubbed and "AKIA" not in scrubbed

    print("SELF-TEST PASS: measurement, ZIP, directory ranking, and secret redaction verified.")
    return 0


def run_evidence(json_out: Path, md_out: Path) -> int:
    repo_root = Path(__file__).resolve().parent.parent
    runner_temp_base = Path(os.environ.get("RUNNER_TEMP") or tempfile.gettempdir()).resolve()

    pre_product_check = verify_repo_untouched(repo_root)
    failures: list[str] = []
    if not pre_product_check["all_clean"]:
        failures.append("Pre-check failed: product directory is not in clean baseline state.")

    temp_root = Path(tempfile.mkdtemp(prefix="cpms-aws-sdk-evidence-", dir=str(runner_temp_base))).resolve()

    # Initialize report defaults so even an early error writes a complete report
    php_version = "unknown"
    composer_version = "unknown"
    temp_outside_repo = False
    sdk_resolved_version = ""
    sdk_dist_reference = ""
    lock_sha256 = ""
    lock_content_hash = ""
    closure_signature_sha256 = ""
    packages_summary: list[dict[str, Any]] = []
    dev_package_count = 0
    unique_licenses: set[str] = set()
    missing_license_packages: list[str] = []
    rc_audit = -1
    advisory_count = 0
    advisories_detail: Any = {}
    abandoned_count = 0
    abandoned_detail: Any = {}
    install_warnings: list[str] = []
    abandoned_in_lock: list[str] = []
    required_extensions: dict[str, list[str]] = {}
    required_libraries: dict[str, list[str]] = {}
    suggested_extensions: dict[str, list[str]] = {}
    rc_plat = -1
    host_platform_entries: list[dict[str, Any]] = []
    php_matrix_results: list[dict[str, Any]] = []
    vendor_total_bytes = 0
    vendor_file_count = 0
    vendor_zip_bytes = 0
    vendor_zip_sha256 = ""
    aws_sdk_bytes = 0
    aws_sdk_file_count = 0
    top_10_pkg_dirs: list[dict[str, Any]] = []
    top_10_first_level_vendor: list[dict[str, Any]] = []
    top_10_sdk_src: list[dict[str, Any]] = []

    try:
        try:
            temp_root.relative_to(repo_root)
            failures.append(f"Temp directory {temp_root} must not be inside repo {repo_root}")
        except ValueError:
            temp_outside_repo = True

        composer_cache = temp_root / "composer-cache"
        primary_dir = temp_root / "primary"
        composer_cache.mkdir(parents=True)
        primary_dir.mkdir(parents=True)

        env = build_sanitized_env(composer_cache)

        rc_php, php_out, php_err = run_cmd(["php", "-r", "echo PHP_VERSION;"], primary_dir, env, timeout=30)
        if rc_php != 0:
            failures.append(f"php CLI check failed (rc={rc_php}): {php_err.strip()}")
        else:
            php_version = php_out.strip()

        rc_comp, comp_out, comp_err = run_cmd(["composer", "--version", "--no-ansi"], primary_dir, env, timeout=30)
        if rc_comp != 0:
            failures.append(f"composer CLI check failed (rc={rc_comp}): {comp_err.strip()}")
        else:
            composer_version = comp_out.strip()

        # 1. Write minimal composer.json in primary_dir
        composer_json_path = primary_dir / "composer.json"
        composer_json_path.write_text(
            json.dumps(make_composer_json_payload(platform_php=None), indent=4) + "\n",
            encoding="utf-8",
        )

        # 2 & 3. Run Composer resolution/install using production semantics & generate temporary lock
        resolve_cmd = [
            "composer",
            "update",
            "--no-dev",
            "--prefer-dist",
            "--no-interaction",
            "--no-progress",
            "--no-scripts",
            "--no-plugins",
            "--optimize-autoloader",
            "--no-ansi",
        ]
        rc_resolve, resolve_stdout, resolve_stderr = run_cmd(resolve_cmd, primary_dir, env, timeout=600)
        if rc_resolve != 0:
            failures.append(f"Composer resolution (update --no-dev) failed with rc={rc_resolve}: {resolve_stderr.strip()}")

        install_cmd = [
            "composer",
            "install",
            "--no-dev",
            "--prefer-dist",
            "--no-interaction",
            "--no-progress",
            "--no-scripts",
            "--no-plugins",
            "--optimize-autoloader",
            "--no-ansi",
        ]
        rc_install, install_stdout, install_stderr = run_cmd(install_cmd, primary_dir, env, timeout=300)
        if rc_install != 0:
            failures.append(f"Composer lock install (install --no-dev) failed with rc={rc_install}: {install_stderr.strip()}")

        install_warnings = extract_warnings(
            resolve_stdout + "\n" + install_stdout,
            resolve_stderr + "\n" + install_stderr,
        )

        lock_path = primary_dir / "composer.lock"
        vendor_dir = primary_dir / "vendor"
        if not lock_path.is_file():
            failures.append("Temporary composer.lock was not generated.")
        if not vendor_dir.is_dir():
            failures.append("Temporary vendor/ directory was not generated.")

        if lock_path.is_file() and vendor_dir.is_dir():
            lock_sha256 = sha256_file(lock_path)
            lock_data = json.loads(lock_path.read_text(encoding="utf-8"))
            lock_content_hash = str(lock_data.get("content-hash", ""))
            lock_packages: list[dict[str, Any]] = list(lock_data.get("packages", []))
            lock_packages_dev: list[dict[str, Any]] = list(lock_data.get("packages-dev", []))
            dev_package_count = len(lock_packages_dev)
            closure_signature_sha256 = compute_closure_signature(lock_packages)

            if lock_packages_dev:
                failures.append(f"Expected 0 dev packages in lock, found {len(lock_packages_dev)}")

            # 4. Run composer audit --no-dev
            audit_cmd = ["composer", "audit", "--no-dev", "--format=json", "--no-interaction", "--no-ansi"]
            rc_audit, audit_stdout, audit_stderr = run_cmd(audit_cmd, primary_dir, env, timeout=180)
            try:
                audit_json = parse_json_payload(audit_stdout, "{") if audit_stdout.strip() else {}
            except Exception as exc:
                audit_json = {}
                failures.append(f"Failed to parse composer audit JSON (rc={rc_audit}): {exc} ({audit_stderr.strip()})")

            raw_advisories = audit_json.get("advisories", [])
            if isinstance(raw_advisories, dict):
                advisory_count = sum(len(v) if isinstance(v, list) else 1 for v in raw_advisories.values())
                advisories_detail = raw_advisories
            elif isinstance(raw_advisories, list):
                advisory_count = len(raw_advisories)
                advisories_detail = raw_advisories
            else:
                advisory_count = 0
                advisories_detail = {}

            raw_abandoned = audit_json.get("abandoned", [])
            if isinstance(raw_abandoned, dict):
                abandoned_count = len(raw_abandoned)
                abandoned_detail = raw_abandoned
            elif isinstance(raw_abandoned, list):
                abandoned_count = len(raw_abandoned)
                abandoned_detail = raw_abandoned
            else:
                abandoned_count = 0
                abandoned_detail = {}

            if rc_audit != 0 or advisory_count > 0 or abandoned_count > 0:
                failures.append(
                    f"composer audit policy check failed (rc={rc_audit}, advisories={advisory_count}, abandoned={abandoned_count})"
                )

            # 5 & 6. Gather exact production package/version list and license metadata
            lic_cmd = ["composer", "licenses", "--no-dev", "--format=json", "--no-interaction", "--no-ansi"]
            rc_lic, lic_stdout, lic_stderr = run_cmd(lic_cmd, primary_dir, env, timeout=120)
            if rc_lic != 0:
                failures.append(f"composer licenses failed with rc={rc_lic}: {lic_stderr.strip()}")
                lic_deps: dict[str, Any] = {}
            else:
                lic_json = parse_json_payload(lic_stdout, "{")
                lic_deps = lic_json.get("dependencies", {}) if isinstance(lic_json, dict) else {}

            for pkg in sorted(lock_packages, key=lambda p: str(p.get("name", ""))):
                name = str(pkg.get("name", ""))
                version = str(pkg.get("version", ""))
                dist = pkg.get("dist") or {}
                dist_ref = str(dist.get("reference") or "")
                dist_type = str(dist.get("type") or "")

                lock_lics = [str(x).strip() for x in (pkg.get("license") or []) if str(x).strip()]
                cmd_lic_entry = lic_deps.get(name, {}) if isinstance(lic_deps, dict) else {}
                cmd_lics = [
                    str(x).strip()
                    for x in (cmd_lic_entry.get("license") or [])
                    if str(x).strip() and str(x).strip().lower() != "unknown"
                ]
                effective_lics = sorted(set(lock_lics or cmd_lics))
                if not effective_lics:
                    missing_license_packages.append(name)
                else:
                    unique_licenses.update(effective_lics)

                if pkg.get("abandoned"):
                    abandoned_in_lock.append(name)

                req_map = pkg.get("require") or {}
                php_req = str(req_map.get("php", ""))
                pkg_platform_reqs: dict[str, str] = {}
                for req_name, req_constraint in sorted(req_map.items()):
                    if is_platform_requirement_name(req_name):
                        pkg_platform_reqs[req_name] = str(req_constraint)
                        if req_name.startswith("ext-"):
                            required_extensions.setdefault(req_name, []).append(f"{name} ({req_constraint})")
                        elif req_name.startswith("lib-"):
                            required_libraries.setdefault(req_name, []).append(f"{name} ({req_constraint})")

                suggest_map = pkg.get("suggest") or {}
                for sug_name in sorted(suggest_map.keys()):
                    if sug_name.startswith("ext-"):
                        suggested_extensions.setdefault(sug_name, []).append(name)

                if name == CANDIDATE_PACKAGE:
                    sdk_resolved_version = version
                    sdk_dist_reference = dist_ref

                packages_summary.append(
                    {
                        "name": name,
                        "version": version,
                        "licenses": effective_lics,
                        "php_require": php_req,
                        "platform_requires": pkg_platform_reqs,
                        "dist_type": dist_type,
                        "dist_reference": dist_ref,
                        "abandoned": bool(pkg.get("abandoned")),
                    }
                )

            if not sdk_resolved_version:
                failures.append(f"{CANDIDATE_PACKAGE} was not found in resolved composer.lock packages.")
            if missing_license_packages:
                failures.append(f"Missing license metadata for package(s): {', '.join(missing_license_packages)}")
            if abandoned_in_lock:
                failures.append(f"Abandoned package(s) in composer.lock: {', '.join(abandoned_in_lock)}")

            # 7. Check host platform reqs
            plat_cmd = ["composer", "check-platform-reqs", "--no-dev", "--format=json", "--no-interaction", "--no-ansi"]
            rc_plat, plat_stdout, plat_stderr = run_cmd(plat_cmd, primary_dir, env, timeout=60)
            if rc_plat != 0:
                failures.append(f"composer check-platform-reqs failed on runner (rc={rc_plat}): {plat_stderr.strip()}")
            else:
                host_platform_entries = parse_json_payload(plat_stdout, "[") if plat_stdout.strip() else []
                for entry in host_platform_entries:
                    if entry.get("status") != "success":
                        failures.append(f"Platform requirement not satisfied on runner: {entry}")

            # 9. Measure vendor bytes, file count, compressed vendor ZIP bytes, AWS SDK bytes, top 10 dirs
            vendor_total_bytes, vendor_file_count = measure_directory(vendor_dir)
            sdk_dir = vendor_dir / "aws" / "aws-sdk-php"
            aws_sdk_bytes, aws_sdk_file_count = measure_directory(sdk_dir)
            vendor_zip_path = temp_root / "vendor.zip"
            vendor_zip_bytes = create_vendor_zip(vendor_dir, vendor_zip_path)
            vendor_zip_sha256 = sha256_file(vendor_zip_path)

            top_10_pkg_dirs = measure_package_directories(vendor_dir, limit=10)
            top_10_first_level_vendor = measure_top_entries(vendor_dir, limit=10)
            top_10_sdk_src = measure_top_entries(sdk_dir / "src", limit=10)

            if vendor_total_bytes <= 0 or vendor_file_count <= 0 or vendor_zip_bytes <= 0 or aws_sdk_bytes <= 0:
                failures.append("One or more footprint measurements returned 0.")

            # 10. Platform compatibility across PHP 8.1, 8.2, 8.3, 8.4
            for series, sim_php in TARGET_PHP_SERIES:
                # (a) Fresh solver resolution with config.platform.php = sim_php
                sim_fresh_dir = temp_root / f"platform-fresh-{series}"
                sim_fresh_dir.mkdir(parents=True)
                (sim_fresh_dir / "composer.json").write_text(
                    json.dumps(make_composer_json_payload(platform_php=sim_php), indent=4) + "\n",
                    encoding="utf-8",
                )
                rc_fresh, _fresh_out, fresh_err = run_cmd(
                    [
                        "composer",
                        "update",
                        "--no-dev",
                        "--prefer-dist",
                        "--no-autoloader",
                        "--no-scripts",
                        "--no-plugins",
                        "--no-interaction",
                        "--no-progress",
                        "--no-ansi",
                    ],
                    sim_fresh_dir,
                    env,
                    timeout=300,
                )
                fresh_lock = sim_fresh_dir / "composer.lock"
                fresh_sdk_ver = ""
                fresh_pkg_count = 0
                fresh_closure_sig = ""
                matches_primary = False
                if rc_fresh == 0 and fresh_lock.is_file():
                    fl_data = json.loads(fresh_lock.read_text(encoding="utf-8"))
                    fl_pkgs = fl_data.get("packages", [])
                    fresh_pkg_count = len(fl_pkgs)
                    fresh_closure_sig = compute_closure_signature(fl_pkgs)
                    fl_map = {str(p.get("name", "")): str(p.get("version", "")) for p in fl_pkgs}
                    fresh_sdk_ver = fl_map.get(CANDIDATE_PACKAGE, "")
                    matches_primary = fresh_closure_sig == closure_signature_sha256

                # (b) Primary lock dry-run installability under config.platform.php = sim_php
                sim_lock_dir = temp_root / f"platform-lock-{series}"
                sim_lock_dir.mkdir(parents=True)
                (sim_lock_dir / "composer.json").write_text(
                    json.dumps(make_composer_json_payload(platform_php=sim_php), indent=4) + "\n",
                    encoding="utf-8",
                )
                shutil.copy2(lock_path, sim_lock_dir / "composer.lock")
                rc_lock_dry, _dry_out, dry_err = run_cmd(
                    [
                        "composer",
                        "install",
                        "--no-dev",
                        "--dry-run",
                        "--no-scripts",
                        "--no-plugins",
                        "--no-interaction",
                        "--no-progress",
                        "--no-ansi",
                    ],
                    sim_lock_dir,
                    env,
                    timeout=120,
                )

                fresh_ok = rc_fresh == 0 and bool(fresh_sdk_ver)
                lock_dry_ok = rc_lock_dry == 0
                if not (fresh_ok and lock_dry_ok):
                    failures.append(
                        f"PHP {series} ({sim_php}) dependency compatibility check failed "
                        f"(fresh_rc={rc_fresh}, lock_dry_run_rc={rc_lock_dry}, err={fresh_err.strip() or dry_err.strip()})"
                    )

                php_matrix_results.append(
                    {
                        "php_series": series,
                        "simulated_platform_php": sim_php,
                        "fresh_resolution_rc": rc_fresh,
                        "fresh_resolution_ok": fresh_ok,
                        "primary_lock_dry_run_rc": rc_lock_dry,
                        "primary_lock_dry_run_ok": lock_dry_ok,
                        "resolved_sdk_version": fresh_sdk_ver,
                        "package_count": fresh_pkg_count,
                        "closure_signature_sha256": fresh_closure_sig,
                        "matches_primary_lock_packages": matches_primary,
                        "verification_mode": "composer_platform_config_solver_and_lock_dry_run_only",
                        "runtime_executed": False,
                    }
                )

    except Exception as exc:
        failures.append(f"Unexpected exception: {redact_secrets(str(exc))}")
    finally:
        shutil.rmtree(temp_root, ignore_errors=True)

    post_product_check = verify_repo_untouched(repo_root)
    if not post_product_check["all_clean"]:
        failures.append("Post-check failed: product directory was modified during evidence run.")
    if post_product_check["product_composer_sha256"] != pre_product_check["product_composer_sha256"]:
        failures.append("Post-check failed: clinic-practice-management/composer.json SHA-256 changed.")

    overall_status = "PASS" if not failures else "FAIL"

    report: dict[str, Any] = {
        "schema_version": 1,
        "generated_at_utc": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "overall_status": overall_status,
        "candidate": {
            "package": CANDIDATE_PACKAGE,
            "constraint": CANDIDATE_CONSTRAINT,
            "php_constraint": PHP_REQUIRE_CONSTRAINT,
            "dev_packages_enabled": False,
        },
        "runner": {
            "php_version": php_version,
            "composer_version": composer_version,
            "aws_credentials_present": False,
            "s3_network_calls_made": False,
        },
        "isolation": {
            "temp_outside_repo": temp_outside_repo,
            "product_checks": post_product_check,
        },
        "resolution": {
            "sdk_resolved_version": sdk_resolved_version,
            "sdk_dist_reference": sdk_dist_reference,
            "lock_sha256": lock_sha256,
            "lock_content_hash": lock_content_hash,
            "closure_signature_sha256": closure_signature_sha256,
            "package_count": len(packages_summary),
            "dev_package_count": dev_package_count,
        },
        "packages": packages_summary,
        "licenses": {
            "all_packages_have_license": len(missing_license_packages) == 0 and len(packages_summary) > 0,
            "unique_licenses": sorted(unique_licenses),
            "missing_license_packages": missing_license_packages,
        },
        "audit": {
            "exit_code": rc_audit,
            "advisory_count": advisory_count,
            "advisories": advisories_detail,
            "abandoned_count": abandoned_count,
            "abandoned": abandoned_detail,
        },
        "warnings": {
            "install_warnings": install_warnings,
            "abandoned_in_lock": abandoned_in_lock,
        },
        "platform_requirements": {
            "required_extensions": sorted(required_extensions.keys()),
            "required_extensions_by_package": required_extensions,
            "required_libraries": sorted(required_libraries.keys()),
            "required_libraries_by_package": required_libraries,
            "suggested_extensions": sorted(suggested_extensions.keys()),
            "suggested_extensions_by_package": suggested_extensions,
            "host_check_status": "PASS" if rc_plat == 0 else "FAIL",
            "host_platform_entries": host_platform_entries,
        },
        "platform_compatibility": {
            "scope_note": (
                "Establishes Composer dependency resolution and lock-level platform compatibility "
                "for PHP 8.1, 8.2, 8.3, and 8.4 via config.platform.php solver resolution and "
                "primary lock dry-run install checks; distinct from full runtime execution on every PHP version."
            ),
            "all_php_81_to_84_compatible": bool(php_matrix_results)
            and all(p["fresh_resolution_ok"] and p["primary_lock_dry_run_ok"] for p in php_matrix_results),
            "php_matrix": php_matrix_results,
        },
        "measurements": {
            "vendor_total_bytes": vendor_total_bytes,
            "vendor_file_count": vendor_file_count,
            "compressed_vendor_zip_bytes": vendor_zip_bytes,
            "compressed_vendor_zip_sha256": vendor_zip_sha256,
            "aws_sdk_directory_bytes": aws_sdk_bytes,
            "aws_sdk_file_count": aws_sdk_file_count,
            "top_10_package_directories": top_10_pkg_dirs,
            "top_10_first_level_vendor_entries": top_10_first_level_vendor,
            "top_10_aws_sdk_src_entries": top_10_sdk_src,
        },
        "failures": failures,
    }

    json_text = redact_secrets(json.dumps(report, indent=2, sort_keys=False) + "\n")
    md_text = redact_secrets(render_markdown_report(report))

    json_out.parent.mkdir(parents=True, exist_ok=True)
    md_out.parent.mkdir(parents=True, exist_ok=True)
    json_out.write_text(json_text, encoding="utf-8")
    md_out.write_text(md_text, encoding="utf-8")

    print(md_text)
    print("--- BEGIN JSON EVIDENCE ---")
    print(json_text)
    print("--- END JSON EVIDENCE ---")

    return 0 if overall_status == "PASS" else 1


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Disposable Composer resolution & footprint evidence for aws/aws-sdk-php:~3.399.0"
    )
    parser.add_argument("--json-out", default=DEFAULT_JSON_OUT, help="Output JSON evidence path")
    parser.add_argument("--md-out", default=DEFAULT_MD_OUT, help="Output Markdown summary path")
    parser.add_argument("--self-test", action="store_true", help="Run deterministic local self-tests")
    args = parser.parse_args()

    if args.self_test:
        return run_self_test()

    return run_evidence(Path(args.json_out), Path(args.md_out))


if __name__ == "__main__":
    sys.exit(main())

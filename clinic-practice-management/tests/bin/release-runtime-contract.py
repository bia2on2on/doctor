#!/usr/bin/env python3
"""Phase 15 Slice 2A: inspect the official ZIP, then probe it in fresh PHP processes."""

import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile


PLUGIN = Path(__file__).resolve().parents[2]
NAME = "clinic-practice-management"


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def main():
    archive = Path(sys.argv[1]) if len(sys.argv) > 1 else PLUGIN / "dist" / f"{NAME}-1.0.0.zip"
    with tempfile.TemporaryDirectory(prefix="cpms-release-contract-") as scratch:
        scratch = Path(scratch)
        with zipfile.ZipFile(archive) as zipped:
            require(zipped.testzip() is None, "Invalid ZIP (not a dependency-contract RED)")
            names = zipped.namelist()
            require(f"{NAME}/{NAME}.php" in names, "Plugin entry point missing (invalid RED)")
            # This MUST be the first contract assertion: the baseline builds normally,
            # but its whitelist omits runtime dependencies. No PHP/Composer is needed
            # to reach this valid RED; a missing local interpreter is NOT the RED.
            require(f"{NAME}/vendor/autoload.php" in names,
                    "Runtime dependency contract: built ZIP lacks vendor/autoload.php")
            require(f"{NAME}/vendor/aws/aws-sdk-php/src/S3/S3Client.php" in names,
                    "Built ZIP lacks the official AWS S3Client")
            for name in names:
                parts = Path(name).parts
                require(parts[0] == NAME and ".." not in parts, f"Unsafe ZIP path: {name}")
                for part in parts:
                    lower = part.lower()
                    require(not (lower.startswith((".git", ".env", "phpunit", "pilot-"))
                                 or lower in {"tests", "test", "docs", "node_modules", ".cache", "cache",
                                              "auth.json", "credentials", "composer.json", "composer.lock",
                                              "installed.json", "phpstan", "php-stubs", "yoast", "szepeviktor"}
                                 or lower.endswith(".log")), f"Forbidden release content: {name}")
            zipped.extractall(scratch)

        manifest = json.loads((PLUGIN / "composer.json").read_text())
        lock_bytes = (PLUGIN / "composer.lock").read_bytes()
        lock = json.loads(lock_bytes)
        require(manifest["require"]["php"] == ">=8.1", "Project PHP floor changed")
        require(manifest["require"]["aws/aws-sdk-php"] == "~3.399.0", "SDK constraint changed")
        require(manifest["config"]["platform"]["php"] == "8.1.0", "Resolution is not pinned to PHP 8.1")
        require(lock["platform-overrides"]["php"] == "8.1.0", "Lock was not resolved at the PHP floor")
        require(lock["platform"]["php"] == ">=8.1", "Lock PHP policy differs from the plugin")
        packages = {package["name"]: package for package in lock["packages"]}
        require(len(packages) == 14, "Reviewed production closure must contain exactly 14 packages")
        require(packages["aws/aws-sdk-php"]["version"] == "3.399.0", "Resolved SDK differs from approved evidence")
        require(not any(package.get("abandoned") for package in packages.values()), "Abandoned runtime package")
        licenses = {license_id for package in packages.values() for license_id in package["license"]}
        require(licenses == {"Apache-2.0", "MIT"}, f"Unreviewed license closure: {licenses}")

        root = scratch / NAME
        vendor = root / "vendor"
        package_dirs = {f"{owner.name}/{package.name}" for owner in vendor.iterdir()
                        if owner.is_dir() and owner.name != "composer"
                        for package in owner.iterdir() if package.is_dir()}
        require(package_dirs == set(packages), "Vendor is not exactly the locked production package set")
        for package in packages:
            license_files = list((vendor / package).glob("[Ll][Ii][Cc][Ee][Nn][Ss][Ee]*"))
            require(any(path.is_file() for path in license_files), f"Missing upstream license: {package}")
        require((vendor / "aws/aws-sdk-php/NOTICE").is_file(), "Missing AWS upstream NOTICE")
        notice = (root / "THIRD-PARTY-NOTICES.md").read_text()
        require(all(package in notice for package in packages), "Third-party notice omits a runtime package")
        require("Apache-2.0" in notice and "MIT" in notice, "Third-party notice omits license closure")
        for filename in ("ClassLoader.php", "autoload_real.php", "autoload_static.php", "autoload_files.php",
                         "platform_check.php", "InstalledVersions.php", "installed.php"):
            require((vendor / "composer" / filename).is_file(), f"Composer runtime file missing: {filename}")

        probe = str(PLUGIN / "tests/bin/release-s3-client-smoke.php")
        subprocess.run(["php", probe, str(root), "release"], check=True)
        # Exercise the actual entry point without vendor, not a broken Composer
        # environment. App::boot is a no-op fixture; real WP boot has existing gates.
        source = scratch / "source-without-vendor"
        source.mkdir()
        shutil.copy2(PLUGIN / f"{NAME}.php", source)
        shutil.copytree(PLUGIN / "src", source / "src")
        subprocess.run(["php", probe, str(source), "source"], check=True)

        files = [path for path in vendor.rglob("*") if path.is_file()]
        print("PASS: exact locked production vendor, runtime autoload, licenses and release exclusions")
        print(f"LOCK_SHA256: {hashlib.sha256(lock_bytes).hexdigest()}")
        print(f"SDK: 3.399.0; production packages: {len(packages)}; licenses: {', '.join(sorted(licenses))}")
        print(f"VENDOR: {sum(path.stat().st_size for path in files)} bytes / {len(files)} files")
        print(f"ZIP: {archive.stat().st_size} bytes; SHA256: {hashlib.sha256(archive.read_bytes()).hexdigest()}")
        print("PASS: no dev packages, tests, secrets/config files, Composer auth/cache or temporary metadata")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, KeyError, OSError, subprocess.CalledProcessError) as error:
        sys.exit(f"FAIL: {error}")

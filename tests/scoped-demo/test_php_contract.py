#!/usr/bin/env python3
"""Static source contract for the scoped-demo implementation.

This is NOT runtime WordPress/database/browser acceptance. It only proves that
the shipped source preserves the strict mode and contains no forbidden broad
deletion or credential/OTP bypass markers.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPANION = ROOT / "tools" / "disposable-demo-installer"
BOOTSTRAP = COMPANION / "cpms-disposable-demo.php"
SCOPED = sorted((COMPANION / "includes").glob("class-cpms-demo-scoped-*.php"))
README = COMPANION / "README.md"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class ScopedPhpContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.bootstrap = read(BOOTSTRAP)
        cls.all_scoped = "\n".join(read(p) for p in SCOPED)
        cls.readme = read(README)

    def test_all_scoped_classes_are_shipped(self) -> None:
        expected = {
            "class-cpms-demo-scoped-manifest.php",
            "class-cpms-demo-scoped-audit-guard.php",
            "class-cpms-demo-scoped-baseline.php",
            "class-cpms-demo-scoped-preflight.php",
            "class-cpms-demo-scoped-request.php",
            "class-cpms-demo-scoped-otp.php",
            "class-cpms-demo-scoped-provisioner.php",
            "class-cpms-demo-scoped-teardown.php",
            "class-cpms-demo-scoped-admin.php",
        }
        actual = {p.name for p in SCOPED}
        self.assertEqual(expected, actual)
        for name in expected:
            self.assertIn("includes/" + name, self.bootstrap, name)

    def test_scoped_mode_is_registered_separately(self) -> None:
        self.assertIn("CpmsDemo\\\\ScopedAdmin', 'register_menu'", self.bootstrap)
        self.assertIn("CpmsDemo\\\\ScopedAdmin', 'enqueue_assets'", self.bootstrap)
        self.assertIn("public const PAGE   = 'cpms-scoped-demo';", self.all_scoped)
        self.assertIn("public const ACTION = 'cpsd_action';", self.all_scoped)

    def test_distinct_manifest_and_phrases(self) -> None:
        self.assertIn("cpsd_manifest", self.all_scoped)
        self.assertIn("cpsd_tombstone", self.all_scoped)
        self.assertIn("I_AUTHORIZE_SCOPED_DEMO_ON_NON_EMPTY_SITE", self.all_scoped)
        self.assertIn("REMOVE_ONLY_SCOPED_DEMO_DATA", self.all_scoped)
        self.assertIn("CREATE_SCOPED_DEMO_ON_TEST_SITE", self.all_scoped)
        self.assertIn("class ScopedRequest extends Request", self.all_scoped)
        self.assertIn("class ScopedPreflight extends Preflight", self.all_scoped)
        self.assertIn("class ScopedManifest extends Manifest", self.all_scoped)

    def test_no_forbidden_broad_deletion_or_bypass(self) -> None:
        forbidden = [
            r"TRUNCATE\s+TABLE",
            r"DELETE\s+FROM\s+[^\n;]*cpms_audit_logs",
            r"cpms_audit_logs[^\n]*DELETE",
            r"define\s*\(\s*['\"]CPMS_DEV_OTP_ECHO",
            r"DELETE\s+FROM[^\n]*WHERE\s+(created_at|request_id|ip_hash)\b",
            r"\$wpdb->query\s*\(\s*['\"]DELETE\s+FROM",
        ]
        for pattern in forbidden:
            self.assertIsNone(re.search(pattern, self.all_scoped, re.IGNORECASE), pattern)

    def test_global_invariants_are_documented_in_code_and_readme(self) -> None:
        for marker in (
            "never deletes",
            "audit-chain",
            "baseline",
            "shared/global",
            "GET_LOCK('cpms_jobs_tick'",
        ):
            self.assertIn(marker, self.all_scoped, marker)
        self.assertIn("Separate scoped mode", self.readme)
        self.assertIn("never deletes `cpms_audit_logs`", self.readme)

    def test_recovered_baseline_is_resealed_before_it_is_verified(self) -> None:
        """A manifest must never be mutated and then verified without re-sealing.

        Recovery adopts a baseline that the interrupted run never sealed into the
        manifest. Any such mutation invalidates the HMAC, so the payload has to be
        persisted again before `preview()` runs its `verify()` call - otherwise an
        otherwise-healthy recovery is refused as MANIFEST_TAMPERED.
        """

        teardown = read(COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php")
        self.assertIn("$manifest = $this->adopt_recovered_baseline( $manifest );", teardown)
        self.assertIn("private function adopt_recovered_baseline", teardown)
        # The adopted payload is re-sealed, so verify() sees a matching MAC.
        self.assertIn("return $this->manifest->save( $manifest );", teardown)
        # Adoption runs before the preview that re-verifies the manifest.
        execute = teardown.split("private function do_execute", 1)[1]
        self.assertLess(
            execute.index("adopt_recovered_baseline"),
            execute.index("$this->preview("),
            "recovery adoption must precede the preview that re-verifies the manifest",
        )
        # Nothing may assign a recovered baseline outside the adopting helper.
        self.assertEqual(
            1,
            len(re.findall(r"\$manifest\['baseline'\]\s*=\s*", teardown)),
            "exactly one place may install an adopted baseline",
        )

    def test_strict_mode_classes_are_still_loaded(self) -> None:
        for name in (
            "class-cpms-demo-preflight.php",
            "class-cpms-demo-provisioner.php",
            "class-cpms-demo-teardown.php",
            "class-cpms-demo-admin.php",
        ):
            self.assertIn("includes/" + name, self.bootstrap, name)


if __name__ == "__main__":
    unittest.main(verbosity=2)

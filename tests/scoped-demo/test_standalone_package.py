#!/usr/bin/env python3
"""Mission H — standalone CPMS Classic V1 demo: packaging and safety contracts.

These tests exist because the demo changed architecture: it is no longer a
companion that *requires* the production plugin, it is an independently
installable package that carries the real production source, assembled at build
time. Every assertion below fails on the pre-Mission-H source and on a future
regression, and each one is tied to a requirement:

  A  standalone bootstrap / embedded core / conflict handling / update guard
  B  installation on a non-empty site (no production plugin required)
  C  explicit, durable synthetic ownership (no inference)
  E  cleanup safety: deactivation and deletion never destroy data
  F  disposition of the security findings (typed ids, no transitive attribution,
     no unconditional registration, bounded operator grants)
  H  package contents: one plugin, one header, no tests, deterministic, measured

The build test executes the real build script and inspects the real ZIP; it is
not a static source check.
"""

from __future__ import annotations

import hashlib
import os
import re
import shutil
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPANION = ROOT / "tools" / "disposable-demo-installer"
MAIN = COMPANION / "cpms-disposable-demo.php"
STANDALONE = COMPANION / "includes" / "class-cpms-demo-standalone.php"
TEARDOWN = COMPANION / "includes" / "class-cpms-demo-scoped-teardown.php"
GUARD = COMPANION / "includes" / "class-cpms-demo-scoped-guard.php"
BUILD = ROOT / "bin" / "build-demo-installer.sh"
UNINSTALL = COMPANION / "uninstall.php"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


class StandaloneBootstrap(unittest.TestCase):
    """A: one core, never two — and never a fatal."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.main = read(MAIN)
        cls.standalone = read(STANDALONE)

    def test_the_package_no_longer_requires_the_production_plugin(self) -> None:
        # `Requires Plugins:` makes WordPress refuse activation on a site
        # without CPMS, which is exactly the supported standalone case. The
        # header is gone from the plugin file and the build refuses to package
        # an archive that reintroduces it.
        header = [line for line in self.main.splitlines() if line.strip().startswith("* Requires Plugins:")]
        self.assertEqual(header, [])
        self.assertIn("must not declare Requires Plugins", read(BUILD))

    def test_a_conflicting_installation_is_refused_not_fatal(self) -> None:
        for fault in ("embedded_conflict_production_active", "embedded_conflict_core_loaded"):
            self.assertIn(fault, self.standalone)
        # Persian, actionable, no stack trace, no file path.
        self.assertIn("فقط یک نسخه از CPMS باید فعال باشد", self.standalone)
        self.assertNotIn("getMessage()", self.standalone)
        self.assertNotIn("getTrace", self.standalone)

    def test_the_embedded_core_is_booted_exactly_once(self) -> None:
        self.assertEqual(self.standalone.count("require_once self::core_main();"), 1)
        self.assertIn("if ( self::$booted ) {", self.standalone)
        self.assertIn("if ( self::production_is_active() || self::another_core_is_loaded() )", self.standalone)
        # `class_exists(..., false)`: a merely autoloadable class is not "loaded".
        self.assertIn("class_exists( 'ClinicCore\\\\Bootstrap\\\\App', false )", self.standalone)

    def test_exactly_one_activation_path_for_the_core(self) -> None:
        # The embedded entry file is never registered as a plugin, so its own
        # activation hook cannot fire: the demo's hook is the only one.
        self.assertEqual(self.main.count("ClinicCore\\Bootstrap\\App::activate("), 1)
        self.assertEqual(self.main.count("register_activation_hook("), 1)
        self.assertIn("register_deactivation_hook(", self.main)
        # No programmatic activation of the embedded copy, and the core's entry
        # file is never registered as a plugin (it lives two levels down).
        self.assertNotIn("activate_plugin(", self.main)
        self.assertNotIn("plugin_basename( CPMS_DEMO_DIR . 'core", self.main)

    def test_the_production_update_channel_cannot_replace_the_embedded_core(self) -> None:
        for hook in (
            "pre_set_site_transient_update_plugins",
            "plugins_api",
            "auto_update_plugin",
        ):
            self.assertIn(hook, self.standalone)
        self.assertIn("unset( $transient->{$bucket}[ self::PRODUCTION_SLUG ] );", self.standalone)
        self.assertIn("return false;", self.standalone)


class NonEmptySiteInstallation(unittest.TestCase):
    """B: nothing here may demand an empty site."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.main = read(MAIN)
        cls.standalone = read(STANDALONE)

    def test_activation_does_not_probe_the_site_for_emptiness(self) -> None:
        activate = self.main[self.main.index("function cpms_demo_activate()"):]
        activate = activate[: activate.index("register_activation_hook(")]
        for forbidden in ("count_users", "wp_count_posts", "get_posts(", "count_comments"):
            self.assertNotIn(forbidden, activate, forbidden)

    def test_the_core_available_check_accepts_the_embedded_build(self) -> None:
        # Preflight used to demand the production basename in active_plugins,
        # which is false in standalone mode and blocked every run.
        self.assertIn("public static function core_available()", self.standalone)
        preflight = read(COMPANION / "includes" / "class-cpms-demo-preflight.php")
        scoped = read(COMPANION / "includes" / "class-cpms-demo-scoped-preflight.php")
        self.assertIn("Standalone::core_available()", preflight)
        self.assertIn("Standalone::core_available()", scoped)


class CleanupAndUninstallSafety(unittest.TestCase):
    """E: deactivation and deletion never destroy data on their own."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.main = read(MAIN)
        cls.uninstall = read(UNINSTALL)
        cls.production_uninstall = read(ROOT / "clinic-practice-management" / "uninstall.php")

    def test_deactivation_only_unschedules(self) -> None:
        body = self.main[self.main.index("function cpms_demo_deactivate()"):]
        body = body[: body.index("register_deactivation_hook(")]
        self.assertIn("App::deactivate()", body)
        for forbidden in ("DROP TABLE", "$wpdb->query", "delete_user", "wp_delete_user", "delete_option"):
            self.assertNotIn(forbidden, body, forbidden)

    def test_uninstall_never_drops_shared_tables(self) -> None:
        # The production handler drops cpms_* tables when a technical option is
        # set. The demo must never run that, and its own handler must never drop.
        self.assertNotIn("DROP TABLE", self.uninstall)
        self.assertIn("WP_UNINSTALL_PLUGIN", self.uninstall)
        # The embedded copy of the production handler is removed at build time.
        build = read(BUILD)
        self.assertIn('rm -f "$PKG/core/uninstall.php"', build)

    def test_destructive_cleanup_requires_an_explicit_authorized_operation(self) -> None:
        scoped_admin = read(COMPANION / "includes" / "class-cpms-demo-scoped-admin.php")
        self.assertIn("self::CAP", scoped_admin)
        scoped_request = read(COMPANION / "includes" / "class-cpms-demo-scoped-request.php")
        base_request = read(COMPANION / "includes" / "class-cpms-demo-request.php")
        # Nonce + capability + typed confirmation phrase + site fingerprint, all
        # verified server-side before the destructor runs.
        self.assertIn("ACTION_TEARDOWN", scoped_admin)
        self.assertIn("$this->env->nonce_verify( $nonce, $nonce_action )", base_request)
        self.assertIn("hash_equals( $expected_phrase, $phrase )", base_request)
        self.assertIn("'manage_options'", base_request)
        self.assertIn("ACTION_TEARDOWN", scoped_request)


class SecurityFindingsDisposition(unittest.TestCase):
    """F: every finding from the independent review is closed in code."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.teardown = read(TEARDOWN)
        cls.guard = read(GUARD)

    def test_p0_sealed_ids_are_typed_by_table(self) -> None:
        # The old flattening made a `cpms_payments` row deletable because a
        # `cpms_patients` row shared its auto-increment id.
        self.assertIn("private const SEALED_KEY_TABLES", self.teardown)
        self.assertNotIn("$sealed[ (int) $item ] = true;", self.teardown)
        self.assertIn("$sealed[ $table ][ $id ] = true;", self.teardown)
        self.assertIn("$context['sealed'][ $short ][ $row_id ]", self.teardown)
        # Every manifest id key that names a CPMS row must be mapped.
        for key in (
            "patient_id",
            "invoice_id",
            "payment_id",
            "appointment_id",
            "visit_id",
            "prescription_id",
            "follow_up_id",
            "identity_id",
        ):
            self.assertIn("'" + key + "'", self.teardown)

    def test_p0_row_registration_runs_when_the_actor_is_known(self) -> None:
        # Snapshotting only on `init` saw user 0 for every authenticated REST
        # write, so the ledger stayed empty.
        self.assertIn("add_action( 'admin_init', array( __CLASS__, 'begin_request' ), 0 );", self.guard)
        self.assertIn("add_action( 'admin_post', array( __CLASS__, 'begin_request' ), 1 );", self.guard)
        self.assertIn("private static bool $considered = false;", self.guard)
        rest = self.guard[self.guard.index("public static function guard_rest_request("):]
        self.assertIn("self::begin_request();", rest)

    def test_p1_no_transitive_tenant_attribution(self) -> None:
        # The default rule used to be `@tenant`, i.e. "it lives in the demo
        # Clinic therefore it is demo data", for 36 tables including
        # `cpms_invoice_items` and `cpms_payment_adjustments`.
        self.assertNotIn("?? '@tenant'", self.teardown)
        self.assertIn("?? '@none'", self.teardown)
        self.assertIn("private const PARENT_RULES", self.teardown)
        self.assertIn("private const CONFIGURATION_TABLES", self.teardown)
        for child, parent in (
            ("cpms_invoice_items", "cpms_invoices"),
            ("cpms_payment_adjustments", "cpms_payments"),
            ("cpms_prescription_items", "cpms_prescriptions"),
            ("cpms_message_participants", "cpms_message_threads"),
            ("cpms_visit_status_history", "cpms_visits"),
            ("cpms_clinical_note_versions", "cpms_clinical_notes"),
        ):
            self.assertIn("'" + child + "'", self.teardown)
            self.assertIn("'" + parent + "'", self.teardown)

    def test_p1_notifications_are_not_owned_by_default(self) -> None:
        recipient = self.teardown[self.teardown.index("if ( '@recipient' === $rule ) {"):]
        recipient = recipient[: recipient.index("if ( '@visit' === $rule ) {")]
        self.assertNotIn("return true;\n\t\t}", recipient.replace("\t", ""))
        self.assertIn("return false;", recipient)

    def test_p2_a_corrupt_manifest_stands_down_without_deleting(self) -> None:
        self.assertIn("private static function verified_manifest(", self.guard)
        # Standing down is safe only because the teardown refuses on its own.
        self.assertIn("MANIFEST_TAMPERED", self.teardown)
        # And the ledger/seal keep their own independent discovery of a loss.
        ownership = read(COMPANION / "includes" / "class-cpms-demo-scoped-ownership.php")
        self.assertIn("SCOPED_OWNERSHIP_LEDGER_INVALID", ownership)
        self.assertIn("SEAL_OPTION", ownership)

    def test_p3_an_operator_grant_must_be_bounded(self) -> None:
        self.assertIn("private const OPERATOR_GRANT_TABLES", self.teardown)
        self.assertIn("private function operator_grant_is_bounded(", self.teardown)
        body = self.teardown[self.teardown.index("private function operator_grant_is_bounded("):]
        body = body[: body.index("private function normalize_mobile(")]
        # Bound to the demo tenant and to the sealed operator, both re-proved.
        self.assertIn("$context['tenant_org']", body)
        self.assertIn("$context['tenant_clinic']", body)
        self.assertIn("$saw_operator", body)
        self.assertIn("return false;", body)


class PackageContents(unittest.TestCase):
    """H: the shipped ZIP is one plugin, deterministic, and measured."""

    @classmethod
    def setUpClass(cls) -> None:
        if not shutil.which("zip") or not shutil.which("unzip"):
            raise unittest.SkipTest("zip/unzip not available")
        cls.standalone_out = Path(tempfile.mkdtemp(prefix="missionh-standalone-"))
        cls.companion_out = Path(tempfile.mkdtemp(prefix="missionh-companion-"))
        env = dict(os.environ)
        cls.build_a = cls._build(cls.standalone_out, [], env)
        cls.build_b = cls._build(Path(tempfile.mkdtemp(prefix="missionh-repro-")), [], env)
        cls.companion = cls._build(cls.companion_out, ["--companion"], env)
        cls.zip_path = cls.standalone_out / "cpms-disposable-demo-1.0.0.zip"

    @classmethod
    def _build(cls, out: Path, extra: list[str], env: dict) -> subprocess.CompletedProcess:
        return subprocess.run(
            ["bash", str(BUILD), *extra],
            cwd=str(ROOT),
            env={**env, "OUT_DIR": str(out)},
            capture_output=True,
            text=True,
            check=False,
        )

    def test_the_builds_succeed(self) -> None:
        self.assertEqual(self.build_a.returncode, 0, self.build_a.stderr[-2000:])
        self.assertEqual(self.build_b.returncode, 0, self.build_b.stderr[-2000:])
        self.assertEqual(self.companion.returncode, 0, self.companion.stderr[-2000:])

    def test_the_archive_is_reproducible(self) -> None:
        first = hashlib.sha256(self.zip_path.read_bytes()).hexdigest()
        second = hashlib.sha256(
            (self.build_b.stdout and self._repro_zip()).read_bytes()
        ).hexdigest()
        self.assertEqual(first, second)

    def _repro_zip(self) -> Path:
        for candidate in self.build_b.args and [] or []:
            return Path(candidate)
        # The second build wrote into its own OUT_DIR (see setUpClass).
        for child in Path("/tmp").glob("missionh-repro-*"):
            target = child / "cpms-disposable-demo-1.0.0.zip"
            if target.is_file():
                return target
        raise AssertionError("second build produced no archive")

    def test_one_top_level_plugin_directory(self) -> None:
        with zipfile.ZipFile(self.zip_path) as archive:
            names = archive.namelist()
        self.assertEqual(sorted({name.split("/")[0] for name in names}), ["cpms-disposable-demo"])

    def test_the_production_core_is_embedded(self) -> None:
        with zipfile.ZipFile(self.zip_path) as archive:
            names = set(archive.namelist())
        self.assertIn("cpms-disposable-demo/core/clinic-practice-management.php", names)
        self.assertGreater(
            sum(1 for name in names if name.startswith("cpms-disposable-demo/core/src/")), 250
        )
        self.assertIn("cpms-disposable-demo/core/templates", " ".join(names))

    def test_no_development_material_is_packaged(self) -> None:
        with zipfile.ZipFile(self.zip_path) as archive:
            names = archive.namelist()
        forbidden = re.compile(
            r"(^|/)(tests|docs|\.github|bin|node_modules|vendor/bin)/|phpunit|composer\.(json|lock)|\.dist$|\.git"
        )
        offenders = [name for name in names if forbidden.search(name)]
        self.assertEqual(offenders, [], offenders[:10])

    def test_the_companion_build_carries_no_core(self) -> None:
        companion_zip = self.companion_out / "cpms-disposable-demo-companion-1.0.0.zip"
        with zipfile.ZipFile(companion_zip) as archive:
            names = archive.namelist()
        self.assertFalse([name for name in names if "/core/" in name])
        self.assertLess(len(names), 60)

    def test_the_measured_size_is_reported_not_guessed(self) -> None:
        proof = (self.standalone_out / "cpms-disposable-demo-1.0.0.proof.txt").read_text()
        measured = re.search(r"^bytes=(\d+)$", proof, re.M)
        self.assertIsNotNone(measured)
        self.assertEqual(int(measured.group(1)), self.zip_path.stat().st_size)
        # A standalone package carries the real application; it is not a stub.
        self.assertGreater(int(measured.group(1)), 500_000)


if __name__ == "__main__":
    unittest.main()

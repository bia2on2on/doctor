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

    def test_our_own_core_is_never_mistaken_for_a_conflict(self) -> None:
        """A second include of the plugin file in one process is not a conflict.

        WordPress includes an inactive plugin's file to activate it, and the
        embedded core is then already loaded. Discriminating by "a core class
        exists" refused every real activation; the discriminator has to ask
        WHERE the class came from.
        """
        body = self.standalone[self.standalone.index("public static function another_core_is_loaded"):]
        body = body[: body.index("public static function evaluate(")]
        self.assertIn("ReflectionClass(", body)
        self.assertIn("->getFileName()", body)
        self.assertIn("realpath( self::core_main() )", body)
        self.assertIn("realpath( $loaded ) !== $embedded", body)
        # And a request that already booted the core is the owner of it.
        evaluate = self.standalone[self.standalone.index("public static function evaluate("):]
        evaluate = evaluate[: evaluate.index("public static function fault_message(")]
        self.assertIn("if ( self::$booted ) {", evaluate)
        self.assertIn("return '';", evaluate)

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
            # The channel WordPress offers outside the transient: a manual upload,
            # a WP-CLI install, or an auto-update that already passed the filters.
            "upgrader_pre_install",
        ):
            self.assertIn(hook, self.standalone)
        # Recognised by basename AND slug AND item, in every bucket - not only by
        # the slug key, which is why the basename-keyed `response` survived before.
        self.assertIn("public static function is_production_reference( string $reference ): bool {", self.standalone)
        self.assertIn("private static function is_production_item( $item ): bool {", self.standalone)
        strip = self.standalone[self.standalone.index("public static function strip_production_update("):]
        strip = strip[: strip.index("public static function refuse_production_info(")]
        self.assertIn("foreach ( array_keys( get_object_vars( $transient ) ) as $bucket ) {", strip)
        self.assertIn("self::is_production_item( $transient->{$bucket}[ $key ] )", strip)
        self.assertNotIn("[ self::PRODUCTION_SLUG ]", strip)
        auto = self.standalone[self.standalone.index("public static function disable_production_auto_update("):]
        auto = auto[: auto.index("public static function refuse_production_package(")]
        self.assertIn("if ( self::is_production_item( $item ) ) {", auto)
        self.assertNotIn("(string) ( $item->plugin ?? '' )", auto)
        # And the behavioural proof lives in the PHP suite.
        php = read(COMPANION / "tests" / "run-tests.php")
        for probe in (
            "update-guard: basename-keyed production response removed",
            "update-guard: unrelated plugin keeps its update",
            "'package url'",
            "update-guard: an unrelated package URL installs normally",
            "update-guard: auto-update still enabled for an unrelated plugin",
            "activation-order: production is stripped from active_plugins",
            "activation-order: the plugins screen is intercepted before the include",
        ):
            self.assertIn(probe, php)

    def test_the_production_plugin_cannot_be_activated_beside_the_demo(self) -> None:
        # Both activation orders, through real WordPress mechanisms.
        self.assertIn("public static function register_activation_barriers(): void {", self.standalone)
        self.assertIn("add_action( 'load-plugins.php', array( __CLASS__, 'refuse_activation_request' ), 0 );", self.standalone)
        self.assertIn("add_filter( 'pre_update_option_active_plugins', array( __CLASS__, 'keep_single_core' ), PHP_INT_MAX, 2 );", self.standalone)
        self.assertIn("'before_invoke:plugin activate'", self.standalone)
        # Registered for an embedded build only, and wired from the plugin file.
        barriers = self.standalone[self.standalone.index("public static function register_activation_barriers(): void {"):]
        barriers = barriers[: barriers.index("public static function refuse_activation_request()")]
        self.assertIn("if ( ! self::is_embedded() ) {", barriers)
        self.assertIn("CpmsDemo\\Standalone::register_activation_barriers();", self.main)
        # The Persian conflict report is surfaced where the owner will see it.
        self.assertIn("data-cpdd-code=\"embedded_activation_blocked\"", read(COMPANION / "includes" / "class-cpms-demo-admin.php"))


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
        self.assertIn("public static function core_available( ?array $active_plugins = null ): bool", self.standalone)
        preflight = read(COMPANION / "includes" / "class-cpms-demo-preflight.php")
        scoped = read(COMPANION / "includes" / "class-cpms-demo-scoped-preflight.php")
        # Both preflights ask the same question through the build-aware helper,
        # and hand it the active-plugin list the environment already exposes.
        self.assertIn("Standalone::core_available( $this->env->active_plugins() )", preflight)
        self.assertIn("Standalone::core_available( $this->env->active_plugins() )", scoped)


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
        # write, so the ledger stayed empty. The single "considered" flag made it
        # worse: the `init` pass consumed it and every later pass returned early.
        self.assertIn("add_action( 'wp_loaded', array( __CLASS__, 'guard_request' ), 0 );", self.guard)
        self.assertIn("add_action( 'admin_init', array( __CLASS__, 'guard_request' ), 0 );", self.guard)
        self.assertIn("add_action( 'admin_post', array( __CLASS__, 'begin_request' ), 1 );", self.guard)
        self.assertNotIn("$considered", self.guard)
        # A decision is only taken when it cannot change: not-a-write and
        # no-verified-fixture stand down, an unknown actor does not.
        begin = self.guard[self.guard.index("public static function begin_request("):]
        begin = begin[: begin.index("public static function finish_request(")]
        self.assertIn("self::$standdown = true;", begin)
        actor = begin[begin.index("if ( ! self::is_fixture_actor( $loaded ) ) {"):]
        actor = actor[: actor.index("self::$fixture  = $fixture;")]
        self.assertNotIn("standdown", actor)
        self.assertNotIn("$considered", actor)
        # Every surface is covered from one place, and REST keeps its own filter.
        guard = self.guard[self.guard.index("public static function guard_request("):]
        self.assertIn("self::begin_request();", guard)
        self.assertIn("self::write_verdict( $method, $params )", guard)
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
        # Bound to a VERIFIED demo tenant and to the sealed operator, both re-proved.
        # Every verified demo Clinic counts (a demo may own more than one).
        self.assertIn("$context['tenant_org']", body)
        self.assertIn("$context['demo_clinics']", body)
        self.assertIn("in_array( $tenant, $clinics, true )", body)
        # The grant's OWN principal must be synthetic; a synthetic inviter is not
        # evidence of ownership over a real person's membership.
        self.assertIn("$principal = (int) ( $row['wp_user_id'] ?? 0 );", body)
        # The principal must be resolved to a synthetic account or the SEALED
        # operator, and a real non-operator principal must be refused outright.
        self.assertIn("$principal_is_synthetic = in_array( $principal, $users, true );", body)
        self.assertIn("$principal_is_operator  = in_array( $principal, $operators, true );", body)
        self.assertIn("if ( ! $principal_is_synthetic && ! $principal_is_operator ) {", body)
        # Registration is evidence about a ROW, never about a person: it can only
        # ever be the operator exception, never a standalone success.
        self.assertIn("if ( $principal_is_operator && ! $registered ) {", body)
        self.assertNotIn("if ( $registered ) {\n\t\t\treturn true;", body)
        self.assertNotIn("$saw_operator", body)
        self.assertIn("return false;", body)
        # And a membership row never falls through to a generic rule, so a
        # synthetic inviter can never make a real person's membership deletable.
        attributed = self.teardown[self.teardown.index("private function row_is_attributed("):]
        attributed = attributed[: attributed.index("private function normalize_mobile(")]
        self.assertIn("if ( in_array( $short, self::OPERATOR_GRANT_TABLES, true ) ) {", attributed)
        guard = attributed[attributed.index("self::OPERATOR_GRANT_TABLES"):]
        guard = guard[: guard.index("return $this->operator_grant_is_bounded")]
        # The branch decides on its own and never falls through to a generic rule.
        self.assertIn("return $this->operator_grant_is_bounded( $short, $row, $context );", guard + attributed[attributed.index("return $this->operator_grant_is_bounded"):][:80])
        self.assertNotIn("@user", guard)


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
        cls.repro_out = Path(tempfile.mkdtemp(prefix="missionh-repro-"))
        cls.build_b = cls._build(cls.repro_out, [], env)
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
        # Compare the two archives THIS test run produced. Globbing /tmp for an
        # older build would happily compare against a stale artefact and report
        # determinism that does not exist.
        repro_zip = self.repro_out / "cpms-disposable-demo-1.0.0.zip"
        self.assertTrue(repro_zip.is_file(), "the second build produced no archive")
        first = hashlib.sha256(self.zip_path.read_bytes()).hexdigest()
        second = hashlib.sha256(repro_zip.read_bytes()).hexdigest()
        self.assertEqual(first, second)

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


# WordPress's own header regex (wp-includes/functions.php, get_file_data):
#   ^(?:[ \t]*<\?php)?[ \t/*#@]*Plugin Name:(.*)$   (flags: m, i)
# It is applied to the first 8 KiB of every candidate file.
WP_PLUGIN_NAME_RE = re.compile(r"^(?:[ \t]*<\?php)?[ \t/*#@]*Plugin Name:(.*)$", re.M | re.I)
WP_HEADER_WINDOW = 8 * 1024


def wp_plugin_name(data: bytes) -> str:
    """Mirror of get_file_data()['Name'] for one file's bytes."""
    head = data[:WP_HEADER_WINDOW].decode("utf-8", errors="replace").replace("\r", "\n")
    match = WP_PLUGIN_NAME_RE.search(head)
    if not match:
        return ""
    return re.sub(r"\s*(?:\*\/|\?>).*", "", match.group(1)).strip()


def wp_get_plugins(archive: zipfile.ZipFile, folder: str) -> dict[str, str]:
    """Mirror of get_plugins('/<folder>') on the archive contents.

    WordPress scans the plugin folder itself and ONE level of sub-directories
    for *.php files, keeps the ones whose header has a non-empty Name, and
    sorts them by Name (uasort + strnatcasecmp). The key is the path relative
    to the plugin folder, which is exactly what Plugin_Upgrader::plugin_info()
    prefixes with the destination name.
    """
    found: dict[str, str] = {}
    prefix = folder + "/"
    for name in archive.namelist():
        if not name.startswith(prefix) or not name.endswith(".php"):
            continue
        rel = name[len(prefix):]
        if rel.count("/") > 1:
            continue  # deeper than one sub-directory: never scanned by get_plugins()
        plugin_name = wp_plugin_name(archive.read(name))
        if plugin_name:
            found[rel] = plugin_name
    return dict(sorted(found.items(), key=lambda item: item[1].casefold()))


class OneDiscoverablePluginHeader(unittest.TestCase):
    """Mission A: the standalone ZIP has exactly ONE WordPress-discoverable header.

    Root cause of "The plugin does not have a valid header" on wp-admin upload:
    the embedded production bootstrap kept its own `Plugin Name:` header, so
    get_plugins('/cpms-disposable-demo') returned two plugins, sorted by name,
    with "Clinic Practice Management (CPMS)" first. Plugin_Upgrader::plugin_info()
    returned `cpms-disposable-demo/core/clinic-practice-management.php`, and the
    Activate link failed validate_plugin() with `no_plugin_header` because
    get_plugins() never scans two levels deep. These tests build the real ZIP
    and apply WordPress's own discovery rules to it.
    """

    @classmethod
    def setUpClass(cls) -> None:
        if not shutil.which("zip") or not shutil.which("unzip"):
            raise unittest.SkipTest("zip/unzip not available")
        cls.out = Path(tempfile.mkdtemp(prefix="missiona-header-"))
        cls.source_before = PRODUCTION_MAIN.read_bytes()
        cls.build = subprocess.run(
            ["bash", str(BUILD)],
            cwd=str(ROOT),
            env={**os.environ, "OUT_DIR": str(cls.out)},
            capture_output=True,
            text=True,
            check=False,
        )
        cls.zip_path = cls.out / "cpms-disposable-demo-1.0.0.zip"
        cls.proof = (cls.out / "cpms-disposable-demo-1.0.0.proof.txt").read_text() if cls.zip_path.is_file() else ""

    def test_the_build_succeeds(self) -> None:
        self.assertEqual(self.build.returncode, 0, self.build.stderr[-2000:])
        self.assertTrue(self.zip_path.is_file())

    def test_exactly_one_header_anywhere_in_the_archive(self) -> None:
        with zipfile.ZipFile(self.zip_path) as archive:
            carriers = [
                name for name in archive.namelist()
                if name.endswith(".php") and wp_plugin_name(archive.read(name)) != ""
            ]
        self.assertEqual(carriers, ["cpms-disposable-demo/cpms-disposable-demo.php"])

    def test_wordpress_discovery_sees_only_the_demo_plugin(self) -> None:
        # get_plugins('/cpms-disposable-demo') — the call plugin_info() makes.
        with zipfile.ZipFile(self.zip_path) as archive:
            plugins = wp_get_plugins(archive, "cpms-disposable-demo")
        self.assertEqual(list(plugins), ["cpms-disposable-demo.php"])
        self.assertTrue(plugins["cpms-disposable-demo.php"].startswith("CPMS Classic"))
        # Plugin_Upgrader::plugin_info() = destination_name . '/' . first key.
        self.assertEqual(
            "cpms-disposable-demo/" + next(iter(plugins)),
            "cpms-disposable-demo/cpms-disposable-demo.php",
        )

    def test_the_embedded_bootstrap_has_no_header_but_all_of_its_code(self) -> None:
        with zipfile.ZipFile(self.zip_path) as archive:
            staged = archive.read("cpms-disposable-demo/core/clinic-practice-management.php")
        self.assertEqual(wp_plugin_name(staged), "")
        source_lines = PRODUCTION_MAIN.read_bytes().splitlines()
        staged_lines = staged.splitlines()
        self.assertEqual(len(source_lines), len(staged_lines), "the rewrite must not add or drop lines")
        changed = [index for index, pair in enumerate(zip(source_lines, staged_lines)) if pair[0] != pair[1]]
        self.assertEqual(len(changed), 1, f"exactly one line may differ, got {changed}")
        self.assertIn(b"Plugin Name:", source_lines[changed[0]])
        self.assertNotIn(b"Plugin Name:", staged_lines[changed[0]])
        self.assertTrue(staged_lines[changed[0]].startswith(b" * "), "the replacement stays inside the docblock")
        # The executable part of the production entry file is byte-identical.
        for anchor in (
            b"define('CPMS_VERSION', '1.0.0');",
            b"define('CPMS_PLUGIN_FILE', __FILE__);",
            b"defined('ABSPATH') || exit;",
        ):
            self.assertIn(anchor, staged)
        self.assertNotIn(b"Requires Plugins:", staged)

    def test_the_production_source_is_never_modified_by_the_build(self) -> None:
        self.assertEqual(PRODUCTION_MAIN.read_bytes(), self.source_before)
        self.assertIn(b"Plugin Name:       Clinic Practice Management (CPMS)", self.source_before)
        tracked = subprocess.run(
            ["git", "status", "--porcelain", "--", "clinic-practice-management/clinic-practice-management.php"],
            cwd=str(ROOT), capture_output=True, text=True, check=False,
        )
        self.assertEqual(tracked.stdout.strip(), "", "the build must leave the production entry file untouched")

    def test_the_production_release_packaging_is_not_part_of_this_build(self) -> None:
        # The demo build never calls into the production release build, and the
        # production ZIP contract is unchanged: this repository ships it from
        # its own script with the real header intact.
        script = read(BUILD)
        self.assertNotIn("build-release.sh", script)
        self.assertNotIn("bin/release", script)

    def test_the_proof_records_the_single_header_and_the_neutralized_core(self) -> None:
        self.assertIn("plugin_headers=1\n", self.proof)
        self.assertIn("plugin_header_file=cpms-disposable-demo/cpms-disposable-demo.php\n", self.proof)
        self.assertIn("embedded_core_header=neutralized\n", self.proof)
        self.assertRegex(self.proof, r"(?m)^embedded_core_bootstrap_sha256=[0-9a-f]{64}$")
        self.assertRegex(self.proof, r"(?m)^production_bootstrap_sha256=[0-9a-f]{64}$")
        staged = re.search(r"(?m)^embedded_core_bootstrap_sha256=([0-9a-f]{64})$", self.proof).group(1)
        source = re.search(r"(?m)^production_bootstrap_sha256=([0-9a-f]{64})$", self.proof).group(1)
        self.assertNotEqual(staged, source, "the staged bootstrap is the neutralized copy, not the source")
        self.assertEqual(source, hashlib.sha256(PRODUCTION_MAIN.read_bytes()).hexdigest())

    def test_the_pre_fix_shape_is_detected_as_two_plugins(self) -> None:
        """RED control: with the production header restored inside core/, the
        same WordPress rules rank the embedded bootstrap first — which is the
        defect. This proves the discovery mirror can see the bug it guards."""
        with zipfile.ZipFile(self.zip_path) as archive:
            staged = archive.read("cpms-disposable-demo/core/clinic-practice-management.php")
            demo_main = archive.read("cpms-disposable-demo/cpms-disposable-demo.php")
        restored = re.sub(
            rb"^ \* Embedded Core:[^\n]*$",
            b" * Plugin Name:       Clinic Practice Management (CPMS)",
            staged,
            count=1,
            flags=re.M,
        )
        self.assertNotEqual(restored, staged)
        red = Path(tempfile.mkdtemp(prefix="missiona-red-")) / "red.zip"
        with zipfile.ZipFile(red, "w") as archive:
            archive.writestr("cpms-disposable-demo/cpms-disposable-demo.php", demo_main)
            archive.writestr("cpms-disposable-demo/core/clinic-practice-management.php", restored)
        with zipfile.ZipFile(red) as archive:
            plugins = wp_get_plugins(archive, "cpms-disposable-demo")
        self.assertEqual(list(plugins), ["core/clinic-practice-management.php", "cpms-disposable-demo.php"])
        # plugin_info() would point at the nested bootstrap, which get_plugins()
        # (no folder argument, used by validate_plugin) never lists -> no_plugin_header.
        self.assertEqual("cpms-disposable-demo/" + next(iter(plugins)), "cpms-disposable-demo/core/clinic-practice-management.php")

    def test_the_build_refuses_a_second_header(self) -> None:
        """The build script itself is a gate: a second header anywhere in the
        staged package aborts the build instead of shipping it."""
        script = read(BUILD)
        self.assertIn("grep -rl --include='*.php' -E '^[[:space:]/*#@]*Plugin Name:'", script)
        self.assertIn('EXPECTED="$PKG/cpms-disposable-demo.php"', script)
        self.assertNotIn("core/clinic-practice-management.php\" | LC_ALL=C sort)", script)
        self.assertIn("embedded_core_header=neutralized", script)


PRODUCTION_MAIN = ROOT / "clinic-practice-management" / "clinic-practice-management.php"


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Static source contract for the scoped-demo preparation design.

This is NOT runtime WordPress acceptance. It only proves that the ownership
design still matches the pinned companion/production source constants.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPANION = ROOT / "tools" / "disposable-demo-installer"
PREFLIGHT = COMPANION / "includes" / "class-cpms-demo-preflight.php"
TEARDOWN = COMPANION / "includes" / "class-cpms-demo-teardown.php"
BOOTSTRAP = COMPANION / "cpms-disposable-demo.php"
MIGRATIONS = ROOT / "clinic-practice-management" / "src" / "Migrations"
HASH_CHAIN = ROOT / "clinic-practice-management" / "src" / "Infrastructure" / "Audit" / "HashChain.php"
AUDIT_LOGGER = ROOT / "clinic-practice-management" / "src" / "Infrastructure" / "Audit" / "AuditLogger.php"
OWNERSHIP_MAP = ROOT / "docs" / "scoped-demo" / "ownership-map.md"
ACCEPTANCE_PLAN = ROOT / "docs" / "scoped-demo" / "acceptance-plan.md"

GLOBAL_TABLES = {
    "cpms_audit_logs",
    "cpms_operational_logs",
    "cpms_jobs",
    "cpms_rate_limits",
    "cpms_schema_migrations",
    "cpms_license_install",
    "cpms_license_state",
    "cpms_organizations",
    "cpms_clinics",
    "cpms_locations",
    "cpms_settings",
}

STRICT_OPERATIONAL_FINGERPRINT = (
    "cpms_clinicians",
    "cpms_clinic_memberships",
    "cpms_organization_memberships",
    "cpms_organization_membership_capabilities",
    "cpms_membership_capabilities",
    "cpms_membership_locations",
    "cpms_clinician_locations",
    "cpms_custom_role_defs",
    "cpms_custom_role_capabilities",
    "cpms_schedule",
    "cpms_schedule_exceptions",
    "cpms_schedule_slots",
    "cpms_slot_holds",
    "cpms_patients",
    "cpms_patient_identities",
    "cpms_patient_identity_links",
    "cpms_patient_user_links",
    "cpms_patient_merges",
    "cpms_appointments",
    "cpms_visits",
    "cpms_visit_status_history",
    "cpms_clinical_notes",
    "cpms_clinical_note_versions",
    "cpms_prescriptions",
    "cpms_prescription_items",
    "cpms_recommendations",
    "cpms_follow_ups",
    "cpms_medical_attachments",
    "cpms_handwriting_documents",
    "cpms_handwriting_pages",
    "cpms_handwriting_page_versions",
    "cpms_ocr_jobs",
    "cpms_services",
    "cpms_invoices",
    "cpms_invoice_items",
    "cpms_payments",
    "cpms_payment_adjustments",
    "cpms_sms_messages",
    "cpms_otp_tokens",
    "cpms_idempotency_keys",
    "cpms_notifications",
    "cpms_audit_logs",
    "cpms_drug_reference",
)


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def php_const_array(source: str, name: str) -> list[str]:
    match = re.search(
        r"public const " + re.escape(name) + r" = array\((.*?)\n\t\);",
        source,
        re.DOTALL,
    )
    if match is None:
        raise AssertionError(f"PHP constant array {name} not found")
    return re.findall(r"'(cpms_[a-z0-9_]+)'", match.group(1))


class OwnershipContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.preflight = read(PREFLIGHT)
        cls.teardown = read(TEARDOWN)
        cls.bootstrap = read(BOOTSTRAP)
        cls.ownership_map = read(OWNERSHIP_MAP)
        cls.acceptance_plan = read(ACCEPTANCE_PLAN)
        cls.operational = php_const_array(cls.preflight, "OPERATIONAL_TABLES")
        cls.system = php_const_array(cls.preflight, "SYSTEM_TABLES")
        cls.known = php_const_array(cls.preflight, "KNOWN_TABLES")
        cls.delete_order = php_const_array(cls.teardown, "DELETE_ORDER")

    def test_pinned_schema_table_counts_and_union(self) -> None:
        self.assertEqual(43, len(self.operational))
        self.assertEqual(10, len(self.system))
        self.assertEqual(53, len(self.known))
        self.assertEqual(50, len(self.delete_order))
        self.assertEqual(set(self.known), set(self.operational) | set(self.system))
        self.assertTrue(set(self.delete_order).issubset(set(self.known)))
        self.assertEqual(set(), set(self.operational) & set(self.system))

    def test_strict_operational_table_list_is_unchanged(self) -> None:
        # The strict disposable-site preflight must not be weakened by scoped work.
        self.assertEqual(list(STRICT_OPERATIONAL_FINGERPRINT), self.operational)

    def test_global_surfaces_are_identified(self) -> None:
        self.assertTrue(GLOBAL_TABLES.issubset(set(self.known)))
        for table in sorted(GLOBAL_TABLES):
            self.assertIn(f"`{table}`", self.ownership_map)
        self.assertIn("Never delete, update, or selectively remove any row", self.ownership_map)
        self.assertIn("GET_LOCK('cpms_jobs_tick', 0)", self.ownership_map)
        self.assertIn("Never match by timestamp, request ID, IP", self.ownership_map)

    def test_design_documents_cover_all_known_tables(self) -> None:
        for table in self.known:
            self.assertIn(f"`{table}`", self.ownership_map, table)
        self.assertIn("each of the 53", self.acceptance_plan)
        self.assertIn("DESIGNED / NOT RUN", self.acceptance_plan)

    def test_pinned_schema_and_audit_chain_contract_exist(self) -> None:
        version_match = re.search(
            r"define\(\s*'CPMS_DEMO_SCHEMA_VERSION',\s*'([^']+)'\s*\)",
            self.bootstrap,
        )
        self.assertIsNotNone(version_match)
        version = version_match.group(1) if version_match else ""
        self.assertTrue((MIGRATIONS / f"{version}_clinic_lifecycle.php").is_file())
        latest_versions = sorted(
            "_".join(p.stem.split("_")[:4])
            for p in MIGRATIONS.glob("*.php")
            if re.match(r"^\d{4}_\d{2}_\d{2}_\d{4}_", p.stem)
        )
        self.assertEqual(version, latest_versions[-1])
        hash_chain = read(HASH_CHAIN)
        audit_logger = read(AUDIT_LOGGER)
        self.assertIn("public static function verify(array $rows)", hash_chain)
        self.assertIn("row_hash = SHA-256(prev_hash + canonical(fields))", hash_chain)
        self.assertIn("Append-only + Hash Chain", audit_logger)
        self.assertIn("public function log(", audit_logger)


if __name__ == "__main__":
    unittest.main(verbosity=2)

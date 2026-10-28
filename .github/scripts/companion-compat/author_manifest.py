#!/usr/bin/env python3
"""Author tools/disposable-demo-installer/compatibility.json from verified rules.

This is a *manifest authoring* helper, not the runtime generator. It combines:

  * facts DERIVED from the production plugin (schema head, table inventory,
    columns, primary keys, declared foreign keys, roles, capabilities, routes), and
  * ownership/teardown rules that a human engineer VERIFIED against those facts.

The committed artefact is the JSON, so the rules are reviewable in a diff. The
runtime generator in ``generate.py`` may only touch the metadata fields listed in
``auto_sync.safe_updates``; it never authors an ownership or deletion rule.
"""

from __future__ import annotations

import json
import pathlib
import sys

HERE = pathlib.Path(__file__).resolve()
ROOT = HERE.parents[3]
sys.path.insert(0, str(ROOT / ".github" / "scripts" / "companion-compat"))

import companion_state as cs  # noqa: E402
import production_features as pf  # noqa: E402
import production_schema as ps  # noqa: E402

CONTRACT_VERSION = 1

# ---------------------------------------------------------------------------
# Verified id-set namespace.
#
# Each set is fed by exactly one parent table's primary key and may only be used
# to prove ownership of a column whose declared foreign key (or, where the
# production DDL deliberately declares none, whose manifest-stated relation)
# points at that same parent. This is the invariant whose violation let a CLINIC
# membership id select ORGANIZATION membership capability rows.
# ---------------------------------------------------------------------------
ID_SETS = {
    "org_ids": {"parent_table": "cpms_organizations", "parent_key": "id"},
    "clinic_ids": {"parent_table": "cpms_clinics", "parent_key": "id"},
    "location_ids": {"parent_table": "cpms_locations", "parent_key": "id"},
    "patient_ids": {"parent_table": "cpms_patients", "parent_key": "id"},
    "identity_ids": {"parent_table": "cpms_patient_identities", "parent_key": "id"},
    "clinician_ids": {"parent_table": "cpms_clinicians", "parent_key": "id"},
    "schedule_ids": {"parent_table": "cpms_schedule", "parent_key": "id"},
    "slot_ids": {"parent_table": "cpms_schedule_slots", "parent_key": "id"},
    "appointment_ids": {"parent_table": "cpms_appointments", "parent_key": "id"},
    "visit_ids": {"parent_table": "cpms_visits", "parent_key": "id"},
    "note_ids": {"parent_table": "cpms_clinical_notes", "parent_key": "id"},
    "prescription_ids": {"parent_table": "cpms_prescriptions", "parent_key": "id"},
    "invoice_ids": {"parent_table": "cpms_invoices", "parent_key": "id"},
    "payment_ids": {"parent_table": "cpms_payments", "parent_key": "id"},
    "service_ids": {"parent_table": "cpms_services", "parent_key": "id"},
    "follow_up_ids": {"parent_table": "cpms_follow_ups", "parent_key": "id"},
    # `membership_id` is NOT a single relation: it names two different parents.
    # The sets are therefore split and the column is resolved per table.
    "clinic_membership_ids": {"parent_table": "cpms_clinic_memberships", "parent_key": "id"},
    "org_membership_ids": {"parent_table": "cpms_organization_memberships", "parent_key": "id"},
    "role_def_ids": {"parent_table": "cpms_custom_role_defs", "parent_key": "id"},
    "document_ids": {"parent_table": "cpms_handwriting_documents", "parent_key": "id"},
    "page_ids": {"parent_table": "cpms_handwriting_pages", "parent_key": "id"},
    "sms_message_ids": {"parent_table": "cpms_sms_messages", "parent_key": "id"},
    "otp_token_ids": {"parent_table": "cpms_otp_tokens", "parent_key": "id"},
    "drug_ref_ids": {"parent_table": "cpms_drug_reference", "parent_key": "id"},
    "attachment_ids": {"parent_table": "cpms_medical_attachments", "parent_key": "id"},
    "ocr_job_ids": {"parent_table": "cpms_ocr_jobs", "parent_key": "id"},
    "hold_ids": {"parent_table": "cpms_slot_holds", "parent_key": "id"},
    "exception_ids": {"parent_table": "cpms_schedule_exceptions", "parent_key": "id"},
    "thread_ids": {"parent_table": "cpms_message_threads", "parent_key": "id"},
    "staff_message_ids": {"parent_table": "cpms_messages", "parent_key": "id"},
    "charge_item_ids": {"parent_table": "cpms_visit_charge_items", "parent_key": "id"},
    "doctor_fee_ids": {"parent_table": "cpms_doctor_consultation_fees", "parent_key": "id"},
    # WordPress core identity, not a CPMS table.
    "user_ids": {"parent_table": "wp_users", "parent_key": "ID"},
}

# Columns whose relation is unambiguous across the whole schema, so one global
# rule is safe. Verified: each has at most one distinct foreign-key parent.
GLOBAL_OWNERSHIP_COLUMNS = {
    "clinic_id": "clinic_ids",
    "organization_id": "org_ids",
    "location_id": "location_ids",
    "patient_id": "patient_ids",
    "identity_id": "identity_ids",
    "clinician_id": "clinician_ids",
    "schedule_id": "schedule_ids",
    "slot_id": "slot_ids",
    "appointment_id": "appointment_ids",
    "linked_appointment_id": "appointment_ids",
    "rescheduled_from": "appointment_ids",
    "rescheduled_to": "appointment_ids",
    "visit_id": "visit_ids",
    "note_id": "note_ids",
    "prescription_id": "prescription_ids",
    "invoice_id": "invoice_ids",
    "invoiced_invoice_id": "invoice_ids",
    "payment_id": "payment_ids",
    "service_id": "service_ids",
    "follow_up_id": "follow_up_ids",
    "role_def_id": "role_def_ids",
    "document_id": "document_ids",
    "page_id": "page_ids",
    "source_page_id": "page_ids",
    "token_id": "otp_token_ids",
    "drug_ref_id": "drug_ref_ids",
    "background_attachment_id": "attachment_ids",
    "ocr_job_id": "ocr_job_ids",
    "hold_id": "hold_ids",
    "exception_id": "exception_ids",
    "surviving_patient_id": "patient_ids",
    "merged_patient_id": "patient_ids",
    "thread_id": "thread_ids",
    # WordPress actor columns.
    "wp_user_id": "user_ids",
    "actor_wp_user_id": "user_ids",
    "created_by_wp_user_id": "user_ids",
    "updated_by_wp_user_id": "user_ids",
    "approved_by_wp_user_id": "user_ids",
    "merged_by_wp_user_id": "user_ids",
    "invited_by_wp_user_id": "user_ids",
    "holder_wp_user_id": "user_ids",
    "recipient_wp_user_id": "user_ids",
    "status_updated_by_wp_user_id": "user_ids",
    "sender_wp_user_id": "user_ids",
    "set_by_wp_user_id": "user_ids",
    "removed_by_wp_user_id": "user_ids",
}

# Per-table overrides for column names that are NOT globally unambiguous, plus
# the tables that own a relation the global map must not guess at.
#
#   cpms_membership_capabilities.membership_id              -> cpms_clinic_memberships.id
#   cpms_membership_locations.membership_id                 -> cpms_clinic_memberships.id
#   cpms_organization_membership_capabilities.membership_id -> cpms_organization_memberships.id
#
# `membership_id` is deliberately absent from GLOBAL_OWNERSHIP_COLUMNS: an
# ambiguous column name must be declared per table or not used at all.
TABLE_OWNERSHIP_COLUMNS = {
    "cpms_membership_capabilities": {"membership_id": "clinic_membership_ids"},
    "cpms_membership_locations": {"membership_id": "clinic_membership_ids"},
    "cpms_organization_membership_capabilities": {"membership_id": "org_membership_ids"},
}

# Tables that feed a set with their own primary key.
TABLE_FEEDS_SET = {
    "cpms_organizations": "org_ids",
    "cpms_clinics": "clinic_ids",
    "cpms_locations": "location_ids",
    "cpms_patients": "patient_ids",
    "cpms_patient_identities": "identity_ids",
    "cpms_clinicians": "clinician_ids",
    "cpms_schedule": "schedule_ids",
    "cpms_schedule_slots": "slot_ids",
    "cpms_appointments": "appointment_ids",
    "cpms_visits": "visit_ids",
    "cpms_clinical_notes": "note_ids",
    "cpms_prescriptions": "prescription_ids",
    "cpms_invoices": "invoice_ids",
    "cpms_payments": "payment_ids",
    "cpms_services": "service_ids",
    "cpms_follow_ups": "follow_up_ids",
    "cpms_clinic_memberships": "clinic_membership_ids",
    "cpms_organization_memberships": "org_membership_ids",
    "cpms_custom_role_defs": "role_def_ids",
    "cpms_handwriting_documents": "document_ids",
    "cpms_handwriting_pages": "page_ids",
    "cpms_sms_messages": "sms_message_ids",
    "cpms_otp_tokens": "otp_token_ids",
    "cpms_drug_reference": "drug_ref_ids",
    "cpms_medical_attachments": "attachment_ids",
    "cpms_ocr_jobs": "ocr_job_ids",
    "cpms_slot_holds": "hold_ids",
    "cpms_schedule_exceptions": "exception_ids",
    "cpms_message_threads": "thread_ids",
    "cpms_messages": "staff_message_ids",
    "cpms_visit_charge_items": "charge_item_ids",
    "cpms_doctor_consultation_fees": "doctor_fee_ids",
}

# Shared tables: never deleted, or deleted only through a dedicated allowlist.
GLOBAL_NEVER = {
    "cpms_audit_logs": "Append-only audit hash chain; teardown verifies it, never deletes it.",
    "cpms_license_install": "Install-level licence identity, not tenant data.",
    "cpms_license_state": "Install-level licence runtime state, not tenant data.",
}
GLOBAL_SELECTIVE = {
    "cpms_jobs": "Shared queue; only fixture-owned job ids from the closed allowlist are deleted.",
    "cpms_operational_logs": "Shared log; only rows whose context matches the fixture are deleted.",
    "cpms_rate_limits": "Shared keyed counters; only fixture-derived window keys are deleted.",
}
PRESERVED_ANCHORS = {
    "cpms_organizations": "Audit-log FK target: archived in place as an inert anchor.",
    "cpms_clinics": "Audit-log FK target: archived in place as an inert anchor.",
    "cpms_locations": "Audit-log FK target: deactivated in place as an inert anchor.",
}
RUNNER_MANAGED = {
    "cpms_schema_migrations": "Migration bookkeeping written by MigrationRunner; always preserved.",
}

# Verified deletion-key rules for tables that have NO surrogate `id` column.
# A row is deleted by its full primary key, never by a numeric coincidence.
COMPOSITE_KEY_TABLES = {
    "cpms_message_participants": ["thread_id", "wp_user_id"],
    "cpms_visit_consultation_fees": ["visit_id"],
    "cpms_visit_fee_snapshot_failures": ["visit_id"],
    "cpms_rate_limits": ["window_key", "window_id"],
}

# Legitimate runtime churn in wp_options. Narrow and justified: every entry names
# one option and the reason its value changes without any operator action. This
# is NOT a blanket exemption for shared options, and it does not exempt any
# preexisting row in any other table.
VOLATILE_OPTIONS = {
    "cpms_jobs_last_tick_at": "Queue heartbeat rewritten by every successful queue tick (autoload=no).",
    "cpms_backup_last_run_at": "Backup heartbeat rewritten by each scheduled backup run.",
}

# Critical companion writes whose persistence MUST be read back and verified.
CRITICAL_OPTIONS = {
    "cpsd_baseline_pending": "Sealed pre-provisioning baseline; the only recovery evidence for an interrupted run.",
    "cpsd_manifest": "Sealed ownership manifest; without it teardown cannot prove what it owns.",
    "cpsd_mode": "Scoped-mode activation marker.",
    "cpsd_tombstone": "Post-teardown evidence record.",
    "cpdd_manifest": "Strict-mode sealed ownership manifest.",
    "cpdd_tombstone": "Strict-mode post-teardown evidence record.",
}


def build(root: pathlib.Path) -> dict:
    schema = ps.load(root / "clinic-practice-management")
    state = cs.load(root / "tools" / "disposable-demo-installer")
    surface = pf.load(root / "clinic-practice-management")

    tables = {}
    for name in sorted(schema.live_tables()):
        table = schema.tables[name]
        if name in RUNNER_MANAGED:
            teardown_class, rationale = "runner_managed", RUNNER_MANAGED[name]
        elif name in PRESERVED_ANCHORS:
            teardown_class, rationale = "preserved_anchor", PRESERVED_ANCHORS[name]
        elif name in GLOBAL_NEVER:
            teardown_class, rationale = "global_never", GLOBAL_NEVER[name]
        elif name in GLOBAL_SELECTIVE:
            teardown_class, rationale = "global_selective", GLOBAL_SELECTIVE[name]
        else:
            teardown_class, rationale = "deletable", ""

        key = COMPOSITE_KEY_TABLES.get(name)
        if key is None:
            key = ["id"] if table.surrogate_id else list(table.primary_key)
            mode = "surrogate_id" if table.surrogate_id else "primary_key"
        else:
            mode = "composite_key"
        if teardown_class in ("global_never", "runner_managed", "preserved_anchor"):
            mode = "never"
        elif teardown_class == "global_selective":
            mode = "dedicated_allowlist"

        ownership = {}
        for column in table.columns:
            per_table = TABLE_OWNERSHIP_COLUMNS.get(name, {})
            if column in per_table:
                ownership[column] = {"set": per_table[column], "via": "declared"}
            elif column in GLOBAL_OWNERSHIP_COLUMNS:
                ownership[column] = {"set": GLOBAL_OWNERSHIP_COLUMNS[column], "via": "global"}

        tables[name] = {
            "since": table.introduced_by,
            "inventory": "system" if name in set(state.system_tables) else "operational",
            "teardown_class": teardown_class,
            "teardown_mode": mode,
            "deletion_key": key,
            "primary_key": list(table.primary_key),
            "surrogate_id": table.surrogate_id,
            "feeds_id_set": TABLE_FEEDS_SET.get(name),
            "ownership_columns": ownership,
            "foreign_keys": [
                {
                    "column": fk.column,
                    "references_table": fk.references_table,
                    "references_column": fk.references_column,
                    "on_delete": fk.on_delete,
                }
                for fk in table.foreign_keys
            ],
            **({"rationale": rationale} if rationale else {}),
        }

    features = [
        {
            "id": "staff_messaging_private_1to1",
            "title": "Strictly private one-to-one staff messaging",
            "introduced_by": "2026_10_27_0030",
            "demo_required": True,
            "status": "demonstrable",
            "routes": [
                "clinic/v1/staff/portal/messages/context",
                "clinic/v1/staff/portal/messages/threads",
                "clinic/v1/staff/portal/messages/threads/(?P<id>\\d+)/messages",
                "clinic/v1/staff/portal/messages/threads/(?P<id>\\d+)/read",
            ],
            "tables": [
                "cpms_message_threads",
                "cpms_message_participants",
                "cpms_messages",
            ],
            "journeys": [
                "cpdd_staff_messaging_privacy",
                "cpsd_staff_messaging_privacy",
            ],
            "gap": "",
        },
        {
            "id": "doctor_visit_pricing",
            "title": "Consultation-fee defaults, per-visit overrides and itemized charges",
            "introduced_by": "2026_10_27_0030",
            "demo_required": True,
            "status": "demonstrable",
            "routes": [
                "clinic/v1/staff/portal/pricing/context",
                "clinic/v1/staff/portal/pricing/default-fee",
                "clinic/v1/staff/portal/pricing/visits",
                "clinic/v1/staff/portal/pricing/visits/(?P<id>\\d+)/billing",
                "clinic/v1/staff/portal/pricing/visits/(?P<id>\\d+)/consultation-fee",
                "clinic/v1/staff/portal/pricing/visits/(?P<id>\\d+)/charges",
                "clinic/v1/staff/portal/pricing/visits/(?P<id>\\d+)/charges/(?P<charge_id>\\d+)",
            ],
            "tables": [
                "cpms_doctor_consultation_fees",
                "cpms_visit_consultation_fees",
                "cpms_visit_charge_items",
                "cpms_visit_fee_snapshot_failures",
            ],
            "journeys": [
                "cpdd_visit_pricing_invoice",
                "cpsd_visit_pricing_invoice",
            ],
            "gap": "",
        },
        {
            "id": "core_six_persona_demo",
            "title": "Booking/visit/invoice demo across every demo persona (pre-0030 surface)",
            "introduced_by": "2026_09_05_0001",
            "demo_required": True,
            "status": "demonstrable",
            "routes": [],
            "tables": [],
            "journeys": [
                "cpdd_install_and_preflight",
                "cpdd_provision_and_credentials",
                "cpdd_persona_logins",
                "cpdd_booking_and_otp",
                "cpdd_reception_arrival",
                "cpdd_doctor_clinical_workflow",
                "cpdd_finance_invoice_payment",
                "cpdd_staff_messaging_privacy",
                "cpdd_visit_pricing_invoice",
                "cpdd_rtl_and_mobile",
                "cpdd_teardown_and_verify",
                "cpsd_admin_provision",
                "cpsd_manifest_tamper_refused",
                "cpsd_persona_receptionist",
                "cpsd_persona_doctor",
                "cpsd_persona_accountant",
                "cpsd_persona_clinic_manager",
                "cpsd_persona_organization_manager",
                "cpsd_patient_otp_booking",
                "cpsd_staff_messaging_privacy",
                "cpsd_visit_pricing_invoice",
                "cpsd_admin_teardown",
            ],
            "gap": "",
        },
    ]

    # Every production route must be claimed by exactly one feature, otherwise the
    # gate cannot tell an undescribed new surface from a described one. The
    # pre-0030 staff-portal surface is covered by the existing persona journeys,
    # so it is claimed here rather than left as an unattributed route.
    claimed = {route for feature in features for route in feature["routes"]}
    features[-1]["routes"] = sorted(surface.route_paths() - claimed)

    return {
        "contract_version": CONTRACT_VERSION,
        "generated_by": ".github/scripts/companion-compat/author_manifest.py",
        "compatibility": {
            "production_schema_head": schema.head,
            "companion_schema_pin": state.schema_version,
            "companion_plugin_version": state.demo_version,
            "core_plugin": state.core_plugin,
            "migration_count": len(schema.versions),
        },
        "sources": {
            "production_plugin": "clinic-practice-management",
            "migrations": "clinic-practice-management/src/Migrations",
            "roles_and_capabilities": "clinic-practice-management/src/Auth/RolesAndCapabilities.php",
            "rest_controllers": "clinic-practice-management/src/Rest",
            "companion": "tools/disposable-demo-installer",
            "companion_bootstrap": "tools/disposable-demo-installer/cpms-disposable-demo.php",
            "companion_preflight": "tools/disposable-demo-installer/includes/class-cpms-demo-preflight.php",
            "companion_teardown": "tools/disposable-demo-installer/includes/class-cpms-demo-teardown.php",
            "companion_scoped_teardown": "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php",
            "browser_journeys": [
                "tools/disposable-demo-installer/tests/browser-acceptance.py",
                "tests/scoped-demo/browser_scoped.py",
            ],
        },
        "personas": {
            "required": [
                "organization_manager",
                "clinic_manager",
                "doctor",
                "doctor_second",
                "receptionist",
                "receptionist_second",
                "accountant",
                "patient",
            ],
            "declared": list(state.personas),
        },
        "roles_capabilities": {
            "production_roles": list(surface.roles),
            "production_capability_count": len(surface.capabilities),
            "demo_supported_roles": list(surface.roles),
            "capabilities": list(surface.capabilities),
            "role_capability_templates": {k: list(v) for k, v in sorted(surface.role_caps.items())},
        },
        "id_sets": ID_SETS,
        "ownership_columns": {
            "global": GLOBAL_OWNERSHIP_COLUMNS,
            "per_table": TABLE_OWNERSHIP_COLUMNS,
            "ambiguous_column_names": ["membership_id"],
        },
        "tables": tables,
        "volatile_options": VOLATILE_OPTIONS,
        "critical_options": CRITICAL_OPTIONS,
        "features": features,
        "safety_invariants": [
            {
                "id": "verified_option_persistence",
                "why": (
                    "Defect D: save()/write_tombstone()/save_pending_baseline() ignored the "
                    "return value of update_option(), and teardown cleared the sealed pending "
                    "baseline before the tombstone was durably stored. A failed write left "
                    "synthetic rows with no recoverable ownership state."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-manifest.php",
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-manifest.php",
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php",
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-provisioner.php",
                ],
                "requires": ["persist_verified", "OPTION_WRITE_FAILED"],
            },
            {
                "id": "pending_baseline_cleared_last",
                "why": (
                    "Defect D: the pending baseline must survive until the replacement "
                    "manifest is durably stored AND read back verified."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-provisioner.php",
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php",
                ],
                "requires": ["clear_pending_baseline"],
                "order": [
                    {
                        "file": "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php",
                        "before": "write_tombstone",
                        "after": "clear_pending_baseline",
                    },
                    {
                        "file": "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-provisioner.php",
                        "before": "->save( $manifest )",
                        "after": "clear_pending_baseline",
                    },
                ],
            },
            {
                "id": "narrow_volatile_option_normalisation",
                "why": (
                    "Defect E: only the two justified heartbeat options may be normalised. "
                    "A blanket exemption for shared options would hide unauthorised changes "
                    "to preexisting records."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-baseline.php"
                ],
                "requires": ["VOLATILE_OPTIONS"],
            },
            {
                "id": "foreign_identity_link_guard",
                "why": (
                    "Defect F: a synthetic WordPress user must not be deleted while a "
                    "foreign-owned patient-user link or OTP record still depends on it."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php"
                ],
                "requires": ["FOREIGN_IDENTITY_LINK", "cpms_patient_user_links"],
            },
            {
                "id": "audit_chain_preserved",
                "why": (
                    "Defect G: the append-only audit hash chain is verified end to end and "
                    "never deleted; only the three inert parent anchors are retained."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php",
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-audit-guard.php",
                ],
                "requires": ["verify_chain", "PRESERVED_PARENT_TABLES"],
            },
            {
                "id": "composite_key_teardown",
                "why": (
                    "Defect B: tables without an `id` column were skipped entirely, so their "
                    "fixture rows were neither collected, deleted, nor detected as remaining."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php"
                ],
                "requires": ["COMPOSITE_KEY_TABLES", "delete_composite_rows"],
            },
            {
                "id": "membership_id_namespaced",
                "why": (
                    "Defect A: one shared membership_ids set let a clinic-membership id delete "
                    "foreign organization-membership capability rows on auto-increment overlap."
                ),
                "files": [
                    "tools/disposable-demo-installer/includes/class-cpms-demo-scoped-teardown.php"
                ],
                "requires": ["clinic_membership_ids", "org_membership_ids", "TABLE_OWNERSHIP_COLUMNS"],
                "forbids": ["'membership_id'                 => 'membership_ids'"],
            },
        ],
        "auto_sync": {
            "safe_updates": [
                "compatibility.production_schema_head",
                "compatibility.companion_schema_pin",
                "compatibility.migration_count",
                "roles_capabilities.production_roles",
                "roles_capabilities.capabilities",
                "roles_capabilities.production_capability_count",
                "roles_capabilities.role_capability_templates",
                "tables.<new>.since",
                "tables.<new>.primary_key",
                "tables.<new>.surrogate_id",
                "tables.<new>.foreign_keys",
                "tables.<new>.inventory",
            ],
            "manual_required": [
                "tables.<new>.teardown_class",
                "tables.<new>.teardown_mode",
                "tables.<new>.deletion_key",
                "tables.<new>.ownership_columns",
                "tables.<new>.feeds_id_set",
                "id_sets.<new>",
                "ownership_columns.per_table",
                "volatile_options",
                "critical_options",
                "features",
                "safety_invariants",
            ],
            "new_table_policy": (
                "A table discovered in production but absent from this manifest is added with "
                "teardown_class='unclassified' and status='manual_implementation_required'. "
                "The gate then FAILS until a human states the ownership and deletion rules. "
                "Generated schema metadata is never allowed to author a deletion rule."
            ),
        },
        "schema_pin_scan": {
            "files": [
                ".github/workflows/scoped-demo.yml",
                ".github/workflows/disposable-demo-installer.yml",
            ],
            "why": (
                "Both companion lanes assert the production schema head in a shell "
                "test. When production advanced to 2026_10_27_0030 those assertions "
                "went stale, and because the lanes' path filters exclude "
                "clinic-practice-management/** they failed only on the next companion "
                "PR. Scanning them here turns that latent breakage into an evaluated fact."
            ),
            "allowed_historical_versions": [],
        },
        "rest_routes": {
            "count": len(surface.routes),
            "namespace": "clinic/v1",
            "paths": sorted(surface.route_paths()),
        },
    }


def main() -> int:
    manifest = build(ROOT)
    out = ROOT / "tools" / "disposable-demo-installer" / "compatibility.json"
    out.write_text(json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {out} ({len(manifest['tables'])} tables, {len(manifest['features'])} features)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

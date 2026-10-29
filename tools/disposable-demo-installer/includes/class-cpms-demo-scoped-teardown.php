<?php
/**
 * Exact-ID, fail-closed teardown for the separate scoped demo mode.
 *
 * Unlike the original disposable teardown, this never deletes audit-log rows.
 * It verifies a sealed baseline, collects exact fixture-owned IDs from live
 * rows, preserves shared/global counters and unrelated queue jobs, and refuses
 * to claim success while any owned clinical or financial row remains.
 *
 * @package CpmsDisposableDemo
 */

namespace CpmsDemo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Logical grouping is clearer than column alignment in these ownership maps.

/**
 * Scoped teardown and read-only ownership preview.
 */
class ScopedTeardown {

	private const GLOBAL_TABLES = array(
		'cpms_jobs',
		'cpms_operational_logs',
		'cpms_rate_limits',
		'cpms_audit_logs',
	);

	/**
	 * The append-only audit log has a foreign key to cpms_clinics. Because the
	 * scoped mode must preserve every audit row and cannot change the production
	 * schema, these three synthetic parent rows are archived in place as inert
	 * audit anchors rather than physically deleted.
	 */
	private const PRESERVED_PARENT_TABLES = array(
		'cpms_organizations',
		'cpms_clinics',
		'cpms_locations',
	);

	/**
	 * Global ownership columns whose relation is unambiguous across the schema.
	 *
	 * Each name below resolves to exactly one foreign-key parent, so a single
	 * rule is safe for every table that carries it.
	 *
	 * EVERY WordPress-user column is deliberately ABSENT, and that is the Iran V1
	 * fix: a synthetic user id is an ACTOR reference, never proof that the row
	 * belongs to the fixture. Post-baseline a real administrator can enroll a
	 * synthetic account as a clinician/member of a FOREIGN Clinic, and an
	 * OR-connected ownership predicate then claimed that foreign row, fed its id
	 * into the clinician/membership sets, and transitively deleted unrelated
	 * foreign appointments, visits, invoices, payments and clinical records.
	 * User references are now used ONLY to detect foreign dependencies
	 * (USER_REFERENCE_COLUMNS) and to refuse the teardown.
	 *
	 * `membership_id` is deliberately ABSENT. It names two different parents
	 * (`cpms_clinic_memberships` and `cpms_organization_memberships`), so one
	 * global rule let a clinic-membership id select foreign organization
	 * membership capability rows whenever the two auto-increment sequences
	 * overlapped, and the teardown deleted them. Ambiguous names are resolved
	 * per table in TABLE_OWNERSHIP_COLUMNS.
	 *
	 * @var array<string,string>
	 */
	private const OWNERSHIP_COLUMNS = array(
		'appointment_id' => 'appointment_ids',
		'background_attachment_id' => 'attachment_ids',
		'clinic_id' => 'clinic_ids',
		'clinician_id' => 'clinician_ids',
		'document_id' => 'document_ids',
		'drug_ref_id' => 'drug_ref_ids',
		'exception_id' => 'exception_ids',
		'follow_up_id' => 'follow_up_ids',
		'hold_id' => 'hold_ids',
		'identity_id' => 'identity_ids',
		'invoice_id' => 'invoice_ids',
		'invoiced_invoice_id' => 'invoice_ids',
		'linked_appointment_id' => 'appointment_ids',
		'location_id' => 'location_ids',
		'merged_patient_id' => 'patient_ids',
		'note_id' => 'note_ids',
		'ocr_job_id' => 'ocr_job_ids',
		'organization_id' => 'org_ids',
		'page_id' => 'page_ids',
		'patient_id' => 'patient_ids',
		'payment_id' => 'payment_ids',
		'prescription_id' => 'prescription_ids',
		'rescheduled_from' => 'appointment_ids',
		'rescheduled_to' => 'appointment_ids',
		'role_def_id' => 'role_def_ids',
		'schedule_id' => 'schedule_ids',
		'service_id' => 'service_ids',
		'slot_id' => 'slot_ids',
		'source_page_id' => 'page_ids',
		'surviving_patient_id' => 'patient_ids',
		'thread_id' => 'thread_ids',
		'token_id' => 'otp_token_ids',
		'visit_id' => 'visit_ids',
	);

	/**
	 * Authoritative per-table resolution for column names that are not globally
	 * unambiguous. Verified against the production schema's declared foreign keys:
	 *
	 *   cpms_membership_capabilities.membership_id              -> cpms_clinic_memberships.id
	 *   cpms_membership_locations.membership_id                 -> cpms_clinic_memberships.id
	 *   cpms_organization_membership_capabilities.membership_id -> cpms_organization_memberships.id
	 *
	 * @var array<string,array<string,string>>
	 */
	private const TABLE_OWNERSHIP_COLUMNS = array(
		'cpms_membership_capabilities' => array( 'membership_id' => 'clinic_membership_ids' ),
		'cpms_membership_locations' => array( 'membership_id' => 'clinic_membership_ids' ),
		'cpms_organization_membership_capabilities' => array( 'membership_id' => 'org_membership_ids' ),
	);

	/**
	 * Tables whose ownership must NOT be inferred from the global column map.
	 *
	 * Both link tables are tenant-scoped, so a FOREIGN row can reference one of
	 * the demo's synthetic WordPress users. Matching on `wp_user_id` alone would
	 * delete that foreign link; only the tenant columns prove ownership here.
	 *
	 * @var array<string,list<string>>
	 */
	private const TABLE_OWNERSHIP_ONLY = array(
		'cpms_patient_user_links'     => array( 'clinic_id', 'patient_id' ),
		'cpms_patient_identity_links' => array( 'organization_id', 'identity_id' ),
	);


	/**
	 * MANDATORY tenant boundary per deletable table.
	 *
	 * A row of one of these tables belongs to the fixture only when its tenant
	 * column equals the demo Clinic/Organization. This is the whole rule: no
	 * OR-alternative, no parent id, and above all no WordPress-user reference
	 * can widen it. Because the demo tenant is created by provisioning, every
	 * row inside it is fixture-created; a row in ANY other tenant is never ours,
	 * whatever ids it happens to reference.
	 *
	 * `cpms_locations` is listed here even though it is preserved rather than
	 * deleted: the anchor is archived in place, so it still has to be proven owned
	 * through the demo Clinic's boundary before anything is written to it.
	 *
	 * Clinic-scoped tables come first (the demo Clinic is the boundary), then the
	 * organization-scoped tables that have no clinic_id. The classification is
	 * explicit and complete on purpose. A production table
	 * that gains a tenant column without being listed here fails the teardown
	 * closed (OWNERSHIP_RULE_MISSING) instead of being silently ignored.
	 *
	 * @var array<string,string>
	 */
	private const TENANT_OWNERSHIP = array(
		'cpms_appointments' => 'clinic_id',
		'cpms_clinic_memberships' => 'clinic_id',
		'cpms_clinical_notes' => 'clinic_id',
		'cpms_locations' => 'clinic_id',
		'cpms_clinicians' => 'clinic_id',
		'cpms_custom_role_defs' => 'clinic_id',
		'cpms_doctor_consultation_fees' => 'clinic_id',
		'cpms_drug_reference' => 'clinic_id',
		'cpms_follow_ups' => 'clinic_id',
		'cpms_handwriting_documents' => 'clinic_id',
		'cpms_idempotency_keys' => 'clinic_id',
		'cpms_invoices' => 'clinic_id',
		'cpms_medical_attachments' => 'clinic_id',
		'cpms_message_threads' => 'clinic_id',
		'cpms_messages' => 'clinic_id',
		'cpms_notifications' => 'clinic_id',
		'cpms_ocr_jobs' => 'clinic_id',
		'cpms_otp_tokens' => 'clinic_id',
		'cpms_patient_merges' => 'clinic_id',
		'cpms_patient_user_links' => 'clinic_id',
		'cpms_patients' => 'clinic_id',
		'cpms_payments' => 'clinic_id',
		'cpms_prescriptions' => 'clinic_id',
		'cpms_recommendations' => 'clinic_id',
		'cpms_schedule' => 'clinic_id',
		'cpms_schedule_exceptions' => 'clinic_id',
		'cpms_schedule_slots' => 'clinic_id',
		'cpms_services' => 'clinic_id',
		'cpms_settings' => 'clinic_id',
		'cpms_slot_holds' => 'clinic_id',
		'cpms_sms_messages' => 'clinic_id',
		'cpms_visit_charge_items' => 'clinic_id',
		'cpms_visits' => 'clinic_id',
		'cpms_organization_memberships' => 'organization_id',
		'cpms_patient_identities' => 'organization_id',
		'cpms_patient_identity_links' => 'organization_id',
	);

	/**
	 * Parent-based ownership for tenant-less tables.
	 *
	 * These tables carry no clinic_id/organization_id, so ownership is proven by
	 * their DECLARED parent key only, and that parent must itself have been
	 * proven owned inside the demo tenant. The value is the parent column; the
	 * id-set is resolved from the column maps above, so the two can never drift.
	 * Any other column of the table (including every WordPress-user reference) is
	 * ignored for ownership.
	 *
	 * @var array<string,string>
	 */
	private const PARENT_OWNERSHIP = array(
		'cpms_clinical_note_versions' => 'note_id',
		'cpms_clinician_locations' => 'clinician_id',
		'cpms_custom_role_capabilities' => 'role_def_id',
		'cpms_handwriting_page_versions' => 'page_id',
		'cpms_handwriting_pages' => 'document_id',
		'cpms_invoice_items' => 'invoice_id',
		'cpms_membership_capabilities' => 'membership_id',
		'cpms_membership_locations' => 'membership_id',
		'cpms_organization_membership_capabilities' => 'membership_id',
		'cpms_payment_adjustments' => 'invoice_id',
		'cpms_prescription_items' => 'prescription_id',
		'cpms_visit_status_history' => 'visit_id',
	);

	/**
	 * The parent key of each composite-key table (no surrogate `id`).
	 *
	 * Attendance/consultation rows are owned when their THREAD/VISIT is owned.
	 * `cpms_message_participants.wp_user_id` is a participant reference, not
	 * ownership: a foreign thread that merely names a synthetic user must never
	 * be selected, which is exactly what the previous OR-predicate did.
	 *
	 * @var array<string,string>
	 */
	private const COMPOSITE_PARENT_COLUMN = array(
		'cpms_message_participants' => 'thread_id',
		'cpms_visit_consultation_fees' => 'visit_id',
		'cpms_visit_fee_snapshot_failures' => 'visit_id',
	);

	/**
	 * Every column that stores a WordPress user id.
	 *
	 * These are ACTOR/PARTICIPANT references. They never prove ownership; they
	 * are the dependency surface scanned before a synthetic user is deleted, so
	 * a foreign tenant that references a demo account fails the teardown closed
	 * (SCOPED_FOREIGN_DEPENDENCY) instead of losing the user underneath it.
	 *
	 * @var list<string>
	 */
	private const USER_REFERENCE_COLUMNS = array(
		'actor_wp_user_id',
		'approved_by_wp_user_id',
		'cancelled_by_wp_user_id',
		'changed_by_wp_user_id',
		'created_by_wp_user_id',
		'holder_wp_user_id',
		'invited_by_wp_user_id',
		'issued_by_wp_user_id',
		'merged_by_wp_user_id',
		'received_by_wp_user_id',
		'recipient_wp_user_id',
		'removed_by_wp_user_id',
		'reviewed_by_wp_user_id',
		'sender_wp_user_id',
		'set_by_wp_user_id',
		'status_updated_by_wp_user_id',
		'updated_by_wp_user_id',
		'uploaded_by_wp_user_id',
		'voided_by_wp_user_id',
		'wp_user_id',
	);

	/**
	 * POSITIVE ATTRIBUTION for every deletable row.
	 *
	 * The tenant boundary says which rows are CANDIDATES; it does not, by itself,
	 * prove that a post-baseline row was created by the demo. A real patient can
	 * book into the demo Clinic and a real member of staff can be given access to
	 * it, and neither of them has anything to do with the fixture. Every candidate
	 * row is therefore attributed to a fixture artifact before it may be deleted:
	 *
	 *   @patient   the row names a patient that is itself attributable
	 *   @visit     the row names a visit that is itself attributable
	 *   @thread    the row names a demo message thread
	 *   @mobile    the row carries the fixture's sealed mobile number
	 *   @user      the row names one of the fixture's synthetic accounts
	 *   @fixture   the row carries the fixture id in one of its text columns
	 *   @identity  the row is the fixture patient's organization identity
	 *   @tenant    the row can only exist through a fixture actor: it is pure
	 *              configuration of the demo Clinic (settings, services, opening
	 *              hours, generated slots, taxonomy rows). The write barrier makes
	 *              this a positive claim rather than an inference: a real actor
	 *              cannot write into a demo tenant at all.
	 *
	 * A row that is registered in the sealed ownership ledger is always attributed
	 * (that is what the ledger is for: the demo registries its own writes).
	 * A row that matches NO rule - including every row that names a real
	 * WordPress user - is refused: SCOPED_FOREIGN_TENANT_ROW, before anything is
	 * deleted. Refusing is always safe; guessing is not.
	 *
	 * @var array<string,string>
	 */
	private const ATTRIBUTION = array(
		'cpms_appointments' => '@patient',
		'cpms_medical_attachments' => '@patient',
		'cpms_clinical_notes' => '@patient',
		'cpms_follow_ups' => '@patient',
		'cpms_handwriting_documents' => '@patient',
		'cpms_invoices' => '@patient',
		'cpms_patient_identity_links' => '@user',
		'cpms_patient_identities' => '@mobile',
		'cpms_patient_user_links' => '@user',
		'cpms_clinicians' => '@user',
		'cpms_patients' => '@patient',
		'cpms_payments' => '@patient',
		'cpms_prescriptions' => '@patient',
		'cpms_recommendations' => '@patient',
		'cpms_visits' => '@patient',
		'cpms_message_threads' => '@thread',
		'cpms_messages' => '@thread',
		'cpms_visit_charge_items' => '@visit',
		'cpms_otp_tokens' => '@mobile',
		'cpms_slot_holds' => '@mobile',
		'cpms_sms_messages' => '@mobile',
		'cpms_notifications' => '@recipient',
		// Membership rows are NEVER resolved by a generic rule: they can name a
		// real person, so `row_is_attributed()` resolves OPERATOR_GRANT_TABLES
		// before anything else (registered + operator-bounded, or refused).
		'cpms_idempotency_keys' => '@user',
	);

	/**
	 * Sealed ids are explicit evidence too.
	 *
	 * The manifest names the exact rows provisioning created (patient, identity,
	 * visit, invoice, payment, appointment, slots, ...). A row whose id is sealed
	 * there needs no further artifact: the demo asserted it at seal time.
	 *
	 * @var list<string>
	 */
	private const SEALED_ID_COLUMNS = array( 'id' );

	/**
	 * Child tables: attribute through an EXPLICIT proven parent row.
	 *
	 * These 17 tables previously fell through to the implicit `@tenant` default,
	 * which meant "it lives in the demo Clinic, therefore it is demo data". That
	 * is transitive attribution: a financial or clinical row created by a real
	 * process inside the demo tenant became deletable for no better reason than
	 * its location. Each row now has to name a parent row that is itself part of
	 * the attributed set, and the parent column is fixed here - never guessed.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const PARENT_RULES = array(
		'cpms_invoice_items'                    => array( 'cpms_invoices', 'invoice_id' ),
		'cpms_payment_adjustments'              => array( 'cpms_payments', 'payment_id' ),
		'cpms_prescription_items'               => array( 'cpms_prescriptions', 'prescription_id' ),
		'cpms_message_participants'             => array( 'cpms_message_threads', 'thread_id' ),
		'cpms_visit_status_history'             => array( 'cpms_visits', 'visit_id' ),
		'cpms_visit_consultation_fees'          => array( 'cpms_visits', 'visit_id' ),
		'cpms_visit_fee_snapshot_failures'      => array( 'cpms_visits', 'visit_id' ),
		'cpms_clinical_note_versions'           => array( 'cpms_clinical_notes', 'note_id' ),
		'cpms_handwriting_pages'                => array( 'cpms_handwriting_documents', 'document_id' ),
		'cpms_handwriting_page_versions'        => array( 'cpms_handwriting_pages', 'page_id' ),
		'cpms_ocr_jobs'                         => array( 'cpms_handwriting_pages', 'source_page_id' ),
		'cpms_membership_capabilities'          => array( 'cpms_clinic_memberships', 'membership_id' ),
		'cpms_membership_locations'             => array( 'cpms_clinic_memberships', 'membership_id' ),
		'cpms_organization_membership_capabilities' => array( 'cpms_organization_memberships', 'membership_id' ),
		'cpms_clinician_locations'              => array( 'cpms_clinicians', 'clinician_id' ),
		'cpms_custom_role_capabilities'         => array( 'cpms_custom_role_defs', 'role_def_id' ),
		'cpms_patient_merges'                   => array( 'cpms_patients', 'surviving_patient_id' ),
	);

	/**
	 * The ONLY tables where "it configures the demo Clinic" is accepted as
	 * ownership, and only because a real actor cannot write into a demo tenant
	 * (server-side barrier). Every entry is pure configuration: no diagnosis, no
	 * clinical content, no money movement, no actor identity. Anything that is
	 * not listed here and not covered by `ATTRIBUTION`/`PARENT_RULES` is REFUSED
	 * - the default is `@none`, never `@tenant`.
	 *
	 * @var list<string>
	 */
	/**
	 * Tables where the demo deliberately grants the OPERATOR access.
	 *
	 * The scoped fixture adds the real administrator to the synthetic
	 * Organization so the owner can walk through the demo. That membership row
	 * names a real account, so it must not be deleted merely because some
	 * synthetic user id appears in the ledger: a registration on one of these
	 * tables counts ONLY when the row points at the demo tenant AND names the
	 * operator recorded in the sealed manifest. Anything else is refused - a
	 * synthetic actor can never talk the teardown into deleting a real
	 * administrator's membership, and a membership in a foreign Organization is
	 * never touched.
	 *
	 * @var list<string>
	 */
	private const OPERATOR_GRANT_TABLES = array(
		'cpms_organization_memberships',
		'cpms_clinic_memberships',
	);

	private const CONFIGURATION_TABLES = array(
		'cpms_services',
		'cpms_settings',
		'cpms_schedule',
		'cpms_schedule_exceptions',
		'cpms_schedule_slots',
		'cpms_custom_role_defs',
		'cpms_drug_reference',
		'cpms_doctor_consultation_fees',
	);

	/**
	 * `cpms_idempotency_keys` is deliberately NOT configuration.
	 *
	 * It used to be, which made every row in that table - including one a real
	 * accountant created while a real payment was captured for a real patient
	 * inside the demo Clinic - claimed unconditionally. An idempotency row carries
	 * `wp_user_id`, so it is attributed like every other actor-scoped row: the
	 * sealed ledger registration (the demo's own request recorder) or a synthetic
	 * fixture account. A row an ANONYMOUS request created is refused rather than
	 * assumed: production replay protection is untouched (this table is never
	 * rewritten by the demo), and refusing to delete is always safe.
	 */

	/**
	 * Maximum fixed-point passes over the candidate rows.
	 *
	 * Attribution is monotone: a row is claimed only through a parent that is
	 * already proven, so the deepest legitimate chain (identity -> patient ->
	 * appointment -> visit -> invoice -> payment -> adjustment) settles well
	 * inside this bound. A chain that cannot settle - an unknown or circular
	 * dependency - leaves its rows unproven, which refuses the teardown instead
	 * of deleting them.
	 */
	private const ATTRIBUTION_PASSES = 14;

	/**
	 * Which table each sealed manifest id belongs to.
	 *
	 * A sealed id is evidence ONLY for the table it was sealed for. Before this
	 * map existed, `ids` was flattened into one id set and any row in any table
	 * whose `id` matched a sealed number was treated as demo-owned - so a real
	 * `cpms_payments` row #42 was deletable because the demo had sealed
	 * `cpms_patients` #42. That is an auto-increment coincidence, not ownership.
	 *
	 * Keys absent from this map (WordPress users, the booking page post) are not
	 * CPMS rows and are never used as row authority.
	 *
	 * @var array<string,string>
	 */
	private const SEALED_KEY_TABLES = array(
		'organization_id'  => 'cpms_organizations',
		'clinic_id'        => 'cpms_clinics',
		'location_ids'     => 'cpms_locations',
		'clinician_id'     => 'cpms_clinicians',
		'clinician_id_second' => 'cpms_clinicians',
		'patient_id'       => 'cpms_patients',
		'identity_id'      => 'cpms_patient_identities',
		'slot_ids'         => 'cpms_schedule_slots',
		'appointment_id'   => 'cpms_appointments',
		'service_id'       => 'cpms_services',
		'visit_id'         => 'cpms_visits',
		'visit_id_pricing' => 'cpms_visits',
		'note_ids'         => 'cpms_clinical_notes',
		'pricing_note_id'  => 'cpms_clinical_notes',
		'prescription_id'  => 'cpms_prescriptions',
		'follow_up_id'     => 'cpms_follow_ups',
		'invoice_id'       => 'cpms_invoices',
		'payment_id'       => 'cpms_payments',
		'message_thread_ids' => 'cpms_message_threads',
		'message_ids'      => 'cpms_messages',
	);

	/**
	 * Tables that must never be claimed or deleted, with the reason.
	 *
	 * License bookkeeping is install-level and migration bookkeeping is written
	 * by MigrationRunner; none of it is tenant data, so none of it can be owned.
	 *
	 * @var array<string,string>
	 */
	private const NEVER_OWNED_TABLES = array(
		'cpms_license_install'   => 'install-level licence identity',
		'cpms_license_state'     => 'install-level licence runtime state',
		'cpms_schema_migrations' => 'runner-owned migration bookkeeping',
	);

	/**
	 * Table -> id-set that receives the ids collected for it. A table may only
	 * feed the set whose parent it is, which is what keeps clinic-membership ids
	 * out of the organization-membership namespace.
	 *
	 * @var array<string,string>
	 */
	private const TABLE_FEEDS_SET = array(
		'cpms_appointments' => 'appointment_ids',
		'cpms_clinic_memberships' => 'clinic_membership_ids',
		'cpms_clinical_notes' => 'note_ids',
		'cpms_clinicians' => 'clinician_ids',
		'cpms_clinics' => 'clinic_ids',
		'cpms_custom_role_defs' => 'role_def_ids',
		'cpms_doctor_consultation_fees' => 'doctor_fee_ids',
		'cpms_drug_reference' => 'drug_ref_ids',
		'cpms_follow_ups' => 'follow_up_ids',
		'cpms_handwriting_documents' => 'document_ids',
		'cpms_handwriting_pages' => 'page_ids',
		'cpms_invoices' => 'invoice_ids',
		'cpms_locations' => 'location_ids',
		'cpms_medical_attachments' => 'attachment_ids',
		'cpms_message_threads' => 'thread_ids',
		'cpms_messages' => 'staff_message_ids',
		'cpms_ocr_jobs' => 'ocr_job_ids',
		'cpms_organization_memberships' => 'org_membership_ids',
		'cpms_organizations' => 'org_ids',
		'cpms_otp_tokens' => 'otp_token_ids',
		'cpms_patient_identities' => 'identity_ids',
		'cpms_patients' => 'patient_ids',
		'cpms_payments' => 'payment_ids',
		'cpms_prescriptions' => 'prescription_ids',
		'cpms_schedule' => 'schedule_ids',
		'cpms_schedule_exceptions' => 'exception_ids',
		'cpms_schedule_slots' => 'slot_ids',
		'cpms_services' => 'service_ids',
		'cpms_slot_holds' => 'hold_ids',
		'cpms_sms_messages' => 'sms_message_ids',
		'cpms_visit_charge_items' => 'charge_item_ids',
		'cpms_visits' => 'visit_ids',
	);

	/**
	 * Tables with NO surrogate `id` column, deleted by an explicit verified key.
	 *
	 * These tables were previously skipped entirely (`id` was required), so their
	 * fixture rows were never collected, never deleted, and never detected as
	 * residue: the teardown could report a clean result while leaving orphans.
	 * A row is now matched and removed by this key only; a numeric coincidence is
	 * never treated as ownership.
	 *
	 * @var array<string,list<string>>
	 */
	private const COMPOSITE_KEY_TABLES = array(
		'cpms_message_participants' => array( 'thread_id', 'wp_user_id' ),
		'cpms_visit_consultation_fees' => array( 'visit_id' ),
		'cpms_visit_fee_snapshot_failures' => array( 'visit_id' ),
	);

	/** @var Env */
	private Env $env;

	/** @var Platform */
	private Platform $platform;

	/** @var ScopedManifest */
	private ScopedManifest $manifest;

	/** @var ScopedAuditGuard */
	private ScopedAuditGuard $audit_guard;

	/** @var ScopedBaseline */
	private ScopedBaseline $baseline;

	/**
	 * Sealed append-only ownership ledger.
	 *
	 * Additional Clinics are demo-owned only when this record names them (or
	 * pre-committed their exact slug before the row could exist). Nothing about
	 * an Organization reference, an auto-increment id or a timestamp proves
	 * ownership, so this object - not an inference - decides.
	 *
	 * @var ScopedOwnership
	 */
	private ScopedOwnership $ownership;

	/**
	 * Id-sets proven owned during the CURRENT collection pass.
	 *
	 * Parent-based rules read from here and nowhere else: they see only ids that
	 * a tenant boundary already proved, so no foreign id can be laundered into a
	 * transitive set.
	 *
	 * @var array<string,list<int>>
	 */
	private array $current_sets = array();

	public function __construct( Env $env, Platform $platform, ScopedManifest $manifest, ScopedAuditGuard $audit_guard, ScopedBaseline $baseline ) {
		$this->env         = $env;
		$this->platform    = $platform;
		$this->manifest    = $manifest;
		$this->audit_guard = $audit_guard;
		$this->baseline    = $baseline;
		$this->ownership   = new ScopedOwnership( $env, $manifest );
		$this->platform->set_env( $env );
	}

	/**
	 * Collect exact owned IDs from live rows, with baseline max ID as a
	 * secondary guard. Ownership is proven by verified parent IDs, never by
	 * timestamp, request ID, IP, or masked mobile suffix.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @return array<string,mixed>
	 */
	public function collect_scope( array $manifest, array $baseline ): array {
		$ids       = (array) ( $manifest['ids'] ?? array() );
		$fixture   = (string) ( $manifest['fixture_id'] ?? '' );
		$clinic_id = (int) ( $ids['clinic_id'] ?? 0 );
		$org_id    = (int) ( $ids['organization_id'] ?? 0 );
		$mobile    = (string) ( $manifest['patient_mobile'] ?? '' );
		$user_ids  = array_values( array_filter( array_map( 'intval', (array) ( $ids['user_ids'] ?? array() ) ) ) );
		// Ownership of the tenant roots is decided ONCE, by the same rules the
		// dependency scan and the anchor verification use, so preview and execution
		// can never disagree about what the fixture owns.
		$tenants = $this->owned_tenants( $manifest, $baseline );

		$sets = array(
			'clinic_ids'        => $tenants['clinic_ids'],
			'org_ids'           => $org_id > 0 ? array( $org_id ) : array(),
			'location_ids'      => $tenants['location_ids'],
			'user_ids'          => $user_ids,
			'patient_ids'       => isset( $ids['patient_id'] ) ? array( (int) $ids['patient_id'] ) : array(),
			'identity_ids'      => isset( $ids['identity_id'] ) ? array( (int) $ids['identity_id'] ) : array(),
			'clinician_ids'     => isset( $ids['clinician_id'] ) ? array( (int) $ids['clinician_id'] ) : array(),
			'schedule_ids'      => array(),
			'slot_ids'          => array_values( array_filter( array_map( 'intval', (array) ( $ids['slot_ids'] ?? array() ) ) ) ),
			'appointment_ids'   => isset( $ids['appointment_id'] ) ? array( (int) $ids['appointment_id'] ) : array(),
			'visit_ids'         => isset( $ids['visit_id'] ) ? array( (int) $ids['visit_id'] ) : array(),
			'note_ids'          => array_values( array_filter( array_map( 'intval', (array) ( $ids['note_ids'] ?? array() ) ) ) ),
			'prescription_ids'  => isset( $ids['prescription_id'] ) ? array( (int) $ids['prescription_id'] ) : array(),
			'invoice_ids'       => isset( $ids['invoice_id'] ) ? array( (int) $ids['invoice_id'] ) : array(),
			'payment_ids'       => isset( $ids['payment_id'] ) ? array( (int) $ids['payment_id'] ) : array(),
			'service_ids'       => isset( $ids['service_id'] ) ? array( (int) $ids['service_id'] ) : array(),
			'follow_up_ids'     => isset( $ids['follow_up_id'] ) ? array( (int) $ids['follow_up_id'] ) : array(),
			'clinic_membership_ids' => array(),
			'org_membership_ids'    => array(),
			'role_def_ids'      => array(),
			'document_ids'      => array(),
			'page_ids'          => array(),
			'sms_message_ids'   => array(),
			'otp_token_ids'     => array(),
			'drug_ref_ids'      => array(),
			'attachment_ids'    => array(),
			'ocr_job_ids'       => array(),
			'hold_ids'          => array(),
			'exception_ids'     => array(),
			'thread_ids'        => array(),
			'staff_message_ids' => array(),
			'charge_item_ids'   => array(),
			'doctor_fee_ids'    => array(),
		);

		$baseline_max = array();
		foreach ( (array) ( $baseline['cpms'] ?? array() ) as $short => $info ) {
			$baseline_max[ $short ] = (int) ( $info['max_id'] ?? 0 );
		}

		$owned_tables    = array();
		$owned_composite = array();
		// Alias, not copy: parent-based rules must see ids collected a moment ago
		// in the SAME pass, and the alias keeps the view live.
		$this->current_sets = &$sets;
		for ( $pass = 0; $pass < 7; $pass++ ) {
			$changed = false;
			foreach ( Preflight::KNOWN_TABLES as $short ) {
				if ( in_array( $short, self::GLOBAL_TABLES, true ) || ! $this->platform->table_exists( $short ) ) {
					continue;
				}
				$full = $this->platform->table( $short );
				$cols = $this->platform->columns( $full );

				// Tables with no surrogate `id` are torn down by an explicit verified
				// composite key. They used to be skipped here, which is why their
				// fixture rows were never collected, deleted, or detected as residue.
				$composite_key = self::COMPOSITE_KEY_TABLES[ $short ] ?? array();
				if ( array() !== $composite_key ) {
					if ( $this->collect_composite_rows( $short, $full, $cols, $composite_key, $sets, $owned_composite ) ) {
						$changed = true;
					}
					continue;
				}

				if ( ! in_array( 'id', $cols, true ) ) {
					continue;
				}
				// FAIL-CLOSED OWNERSHIP: exactly ONE mandatory rule per table.
				//
				// The previous version OR-ed every resolvable column of the row, so a
				// post-baseline FOREIGN-clinic row that merely named a synthetic
				// WordPress user was claimed as demo-owned. Its id then entered the
				// clinician/membership id-sets, and the next pass used those ids to
				// claim foreign visits, appointments, invoices, payments and clinical
				// records. Ownership is now a tenant boundary (or the declared parent
				// of a tenant-less table); user ids are never ownership.
				$rule = $this->ownership_rule( $short, $cols, $clinic_id, $org_id );
				if ( null === $rule ) {
					continue;
				}
				$max      = (int) ( $baseline_max[ $short ] ?? 0 );
				$params   = $rule['params'];
				$params[] = $max;
				$sql = 'SELECT id FROM `' . $full . '` WHERE (' . $rule['sql'] . ') AND id > %d ORDER BY id ASC';
				$rows = $this->platform->fetch_all( $sql, $params );
				foreach ( $rows as $row ) {
					$row_id = (int) ( $row['id'] ?? 0 );
					if ( $row_id <= 0 || isset( $owned_tables[ $short ][ $row_id ] ) ) {
						continue;
					}
					$owned_tables[ $short ][ $row_id ] = true;
					$changed = true;
					$set_key = self::TABLE_FEEDS_SET[ $short ] ?? '';
					if ( '' !== $set_key ) {
						$sets[ $set_key ][] = $row_id;
						$sets[ $set_key ] = array_values( array_unique( $sets[ $set_key ] ) );
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
		}

		$tables = array();
		foreach ( $owned_tables as $short => $map ) {
			$tables[ $short ] = array_map( 'intval', array_keys( $map ) );
			sort( $tables[ $short ] );
		}

		$entity_ids = array();
		foreach ( $sets as $values ) {
			foreach ( $values as $value ) {
				$entity_ids[ (int) $value ] = true;
			}
		}
		foreach ( $tables as $ids_list ) {
			foreach ( $ids_list as $value ) {
				$entity_ids[ (int) $value ] = true;
			}
		}

		$jobs              = array();
		$processing_jobs   = array();
		$job_baseline      = (int) ( $baseline_max['cpms_jobs'] ?? 0 );
		$rows              = $this->platform->fetch_all( 'SELECT * FROM `' . $this->platform->table( 'cpms_jobs' ) . '` WHERE id > %d ORDER BY id ASC', array( $job_baseline ) );
		foreach ( $rows as $row ) {
			$payload_raw = (string) ( $row['payload_json'] ?? '' );
			$payload     = json_decode( $payload_raw, true );
			$is_owned    = false;
			if ( is_array( $payload ) ) {
				if ( isset( $payload['clinic_id'] ) && (int) $payload['clinic_id'] === $clinic_id ) {
					$is_owned = true;
				}
				if ( isset( $payload['message_id'] ) && in_array( (int) $payload['message_id'], $sets['sms_message_ids'], true ) ) {
					$is_owned = true;
				}
				if ( '' !== $fixture && false !== strpos( $payload_raw, $fixture ) ) {
					$is_owned = true;
				}
			}
			if ( ! $is_owned ) {
				continue;
			}
			$job_id = (int) ( $row['id'] ?? 0 );
			if ( 'processing' === (string) ( $row['status'] ?? '' ) ) {
				$processing_jobs[] = $job_id;
			} else {
				$jobs[] = $job_id;
			}
		}

		$oplogs         = array();
		$oplog_baseline = (int) ( $baseline_max['cpms_operational_logs'] ?? 0 );
		$rows           = $this->platform->fetch_all( 'SELECT * FROM `' . $this->platform->table( 'cpms_operational_logs' ) . '` WHERE id > %d ORDER BY id ASC', array( $oplog_baseline ) );
		foreach ( $rows as $row ) {
			$context = json_decode( (string) ( $row['context_json'] ?? '' ), true );
			if ( is_array( $context ) && $this->context_matches( $context, $fixture, $entity_ids, $mobile ) ) {
				$oplogs[] = (int) ( $row['id'] ?? 0 );
			}
		}

		$audit_logs    = array();
		$audit_baseline = (int) ( $baseline_max['cpms_audit_logs'] ?? 0 );
		$rows          = $this->platform->fetch_all( 'SELECT * FROM `' . $this->platform->table( 'cpms_audit_logs' ) . '` WHERE id > %d ORDER BY id ASC', array( $audit_baseline ) );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['clinic_id'] ?? 0 ) !== $clinic_id ) {
				continue;
			}
			$json = (string) ( $row['meta_json'] ?? '' ) . (string) ( $row['after_json'] ?? '' ) . (string) ( $row['before_json'] ?? '' );
			if ( '' !== $fixture && false !== strpos( $json, $fixture ) ) {
				$audit_logs[] = (int) ( $row['id'] ?? 0 );
				continue;
			}
			if ( isset( $entity_ids[ (int) ( $row['resource_id'] ?? 0 ) ] ) || in_array( (int) ( $row['actor_wp_user_id'] ?? 0 ), $user_ids, true ) ) {
				$audit_logs[] = (int) ( $row['id'] ?? 0 );
			}
		}

		$rate_keys    = $this->allowed_rate_limit_keys( $fixture, $mobile, $user_ids, $manifest, $baseline );
		$rate_keys    = array_values( array_unique( array_filter( $rate_keys ) ) );
		$shared_keys  = array();
		foreach ( $rate_keys as $key ) {
			if ( 0 === strpos( $key, 'otp-ip:' ) || 0 === strpos( $key, 'otp-verify-ip:' ) || 0 === strpos( $key, 'login-ip:' ) ) {
				$shared_keys[] = $key;
			}
		}

		// Preserved audit anchors and the tenant boundary, by identity. These rows
		// outlive the teardown by design, so they must be recognised as demo-owned
		// in BOTH the preview and the post-delete dependency scan. `??` is not a
		// fallback here: an existing-but-empty set must still resolve by identity.
		$clinic_anchors = array_values( array_filter( array_map( 'intval', (array) ( $tables['cpms_clinics'] ?? array() ) ) ) );
		if ( array() === $clinic_anchors ) {
			$clinic_anchors = array_values( array_filter( array_map( 'intval', (array) $tenants['clinic_ids'] ) ) );
		}
		$location_anchors = array_values( array_filter( array_map( 'intval', (array) ( $tables['cpms_locations'] ?? array() ) ) ) );
		if ( array() === $location_anchors ) {
			$location_anchors = array_values( array_filter( array_map( 'intval', (array) $tenants['location_ids'] ) ) );
		}

		return array(
			'fixture_id'       => $fixture,
			'clinic_id'        => $clinic_id,
			'organization_id'  => $org_id,
			// Preserved audit anchors and the tenant boundary, by identity. These
			// rows outlive the teardown by design, so they must be recognised as
			// demo-owned in BOTH the preview and the post-delete dependency scan.
			'anchors'          => array(
				'cpms_organizations' => $org_id > 1 ? array( $org_id ) : array(),
				'cpms_clinics'       => $clinic_anchors,
				'cpms_locations'     => $location_anchors,
			),
			'unproven_clinics' => $tenants['unproven'],
			// Sealed ownership ledger state, so preview and execution read the SAME
			// decision: an invalid ledger or a pending (interrupted) registration
			// blocks the destructive stages before anything is deleted.
			'ownership_ledger' => array(
				'code'    => $tenants['ledger_code'],
				'detail'  => $tenants['ledger_detail'],
				'pending' => $tenants['pending'],
				'rows'    => $tenants['row_keys'],
			),
			'user_ids'         => $user_ids,
			'tables'           => $tables,
			'composite'        => $owned_composite,
			'jobs'             => $jobs,
			'processing_jobs'  => $processing_jobs,
			'operational_logs' => $oplogs,
			'audit_logs'       => $audit_logs,
			'rate_limit_keys'  => $rate_keys,
			'shared_keys'      => $shared_keys,
			'entity_ids'       => array_map( 'intval', array_keys( $entity_ids ) ),
		);
	}

	/**
	 * Prove which Clinics and Locations belong to this fixture.
	 *
	 * THE RULE (Stage 1 of the ownership work): a Clinic is demo-owned ONLY when
	 * it is named explicitly by sealed evidence -
	 *
	 *   1. the sealed manifest names it as the fixture Clinic, or
	 *   2. the sealed append-only ownership ledger names its exact id, or
	 *   3. a sealed ledger intent pre-committed its exact slug BEFORE the row
	 *      could exist (an interrupted run; it is PENDING until an administrator
	 *      resolves it, and a pending intent blocks the teardown).
	 *
	 * A Clinic is NEVER claimed because it names the demo Organization, because
	 * its id or creation timestamp postdates the seal, or because a synthetic
	 * user id appears anywhere in it. Those were the old inferences, and each of
	 * them can belong to a genuine non-demo Clinic that a real Organization
	 * manager created under the same Organization after the baseline was taken.
	 *
	 * Any other Clinic that names the demo Organization is reported as unproven,
	 * so the teardown refuses BEFORE any destructive work instead of guessing.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $baseline Verified baseline.
	 * @return array{clinic_ids:list<int>,unproven:list<int>,location_ids:list<int>,pending:list<array{token:string,slug:string,organization_id:int}>,row_keys:array<string,array<int,bool>>,ledger_code:string,ledger_detail:string}
	 */
	private function owned_tenants( array $manifest, array $baseline ): array {
		$ids     = (array) ( $manifest['ids'] ?? array() );
		$org     = (int) ( $ids['organization_id'] ?? 0 );
		$demo    = (int) ( $ids['clinic_id'] ?? 0 );
		$fixture = (string) ( $manifest['fixture_id'] ?? '' );
		$clinic_ids   = $demo > 1 ? array( $demo ) : array();
		$unproven     = array();
		$declared_loc = array_values( array_filter( array_map( 'intval', (array) ( $ids['location_ids'] ?? array() ) ) ) );
		$baseline_org_max = (int) ( $baseline['cpms']['cpms_organizations']['max_id'] ?? 0 );
		// The demo Organization must itself postdate the seal. If the manifest names
		// an Organization that pre-dates it, no additional Clinic under that
		// Organization can be attributed to this session at all.
		$org_owned = $org > 1 && $org > $baseline_org_max;

		// The ledger is mandatory for a SEALED, STILL-LIVE fixture: losing it loses
		// ownership. Three states are deliberately exempt, because none of them can
		// own anything any more:
		//   - an in-progress/failed run (status is not `active`), covered by the
		//     pending intents instead;
		//   - a fixture that has already been torn down (the stored manifest is
		//     gone), which is what makes a repeated teardown idempotent;
		//   - an unreadable/tampered manifest, which is reported as
		//     MANIFEST_TAMPERED by the caller and never as a ledger problem.
		$stored = null;
		try {
			$stored = $this->manifest->load();
		} catch ( \Throwable $unreadable ) {
			$stored = null;
		}
		$require_ledger = 'active' === (string) ( $manifest['status'] ?? '' ) && is_array( $stored );
		$ledger         = $this->ownership->summary( $fixture, $require_ledger );
		$ledger_ids    = $ledger['ok'] ? (array) $ledger['clinic_ids'] : array();
		$ledger_detail = (string) ( $ledger['detail'] ?? '' );
		$ledger_code   = (string) ( $ledger['code'] ?? '' );

		if ( $org > 1 && $this->platform->table_exists( 'cpms_clinics' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id FROM `' . $this->platform->table( 'cpms_clinics' ) . '` WHERE organization_id = %d ORDER BY id ASC',
				array( $org )
			);
			foreach ( $rows as $row ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id <= 1 || $id === $demo ) {
					continue;
				}
				// Explicit sealed record AND a provably demo-created Organization.
				// The live row must still name the demo Organization (the SELECT
				// above), so a forged ledger id cannot drag in a foreign Clinic.
				if ( $ledger['ok'] && $org_owned && in_array( $id, $ledger_ids, true ) ) {
					$clinic_ids[] = $id;
				} else {
					$unproven[] = $id;
				}
			}
		}

		$location_ids = $declared_loc;
		if ( array() !== $clinic_ids && $this->platform->table_exists( 'cpms_locations' ) ) {
			$rows = $this->platform->fetch_all(
				'SELECT id FROM `' . $this->platform->table( 'cpms_locations' ) . '` WHERE clinic_id IN ('
				. implode( ',', array_fill( 0, count( $clinic_ids ), '%d' ) ) . ') ORDER BY id ASC',
				array_values( $clinic_ids )
			);
			foreach ( $rows as $row ) {
				$location_ids[] = (int) ( $row['id'] ?? 0 );
			}
		}

		return array(
			'clinic_ids'    => array_values( array_unique( array_filter( $clinic_ids ) ) ),
			'unproven'      => array_values( array_unique( array_filter( $unproven ) ) ),
			'location_ids'  => array_values( array_unique( array_filter( array_map( 'intval', $location_ids ) ) ) ),
			'pending'       => $ledger['ok'] ? (array) $ledger['pending'] : array(),
			'row_keys'      => $ledger['ok'] ? (array) ( $ledger['row_keys'] ?? array() ) : array(),
			'ledger_code'   => $ledger_code,
			'ledger_detail' => $ledger_detail,
		);
	}

	/**
	 * The ONE mandatory ownership rule of a table, or null (never owned).
	 *
	 * Resolution order is a safety property:
	 *   1. the three audit anchors are owned by their own id;
	 *   2. a table with a tenant column is owned ONLY inside the demo tenant —
	 *      this is the boundary that stops foreign-tenant rows from being claimed
	 *      through a synthetic-user or parent reference;
	 *   3. a tenant-less table is owned ONLY by its declared parent key, and that
	 *      parent set is itself tenant-derived;
	 *   4. anything else is not owned. Never a WordPress-user reference.
	 *
	 * @param string        $short     Short table name.
	 * @param list<string>  $cols      Table columns.
	 * @param int           $clinic_id Demo Clinic.
	 * @param int           $org_id    Demo Organization.
	 * @return array{sql:string,params:list<int>}|null
	 */
	private function ownership_rule( string $short, array $cols, int $clinic_id, int $org_id ): ?array {
		if ( 'cpms_clinics' === $short ) {
			// Bootstrap Clinic 1 is never owned, whatever the manifest claims.
			$owned = array_values( array_filter( $this->owned_parent_ids( 'clinic_ids' ), static fn ( int $id ): bool => $id > 1 ) );
			if ( array() === $owned ) {
				return null;
			}

			return array(
				'sql'    => 'id IN (' . implode( ',', array_fill( 0, count( $owned ), '%d' ) ) . ')',
				'params' => $owned,
			);
		}
		if ( 'cpms_organizations' === $short ) {
			return $org_id > 1 ? array( 'sql' => 'id = %d', 'params' => array( $org_id ) ) : null;
		}

		$tenant_column = self::TENANT_OWNERSHIP[ $short ] ?? '';
		if ( '' !== $tenant_column ) {
			if ( ! in_array( $tenant_column, $cols, true ) ) {
				// The declared tenant column is gone: the rule cannot be proven.
				return null;
			}
			if ( 'organization_id' === $tenant_column ) {
				if ( $org_id <= 1 ) {
					// Tenant 1 is the pre-existing bootstrap tenant, never demo-owned.
					return null;
				}

				return array( 'sql' => '`organization_id` = %d', 'params' => array( $org_id ) );
			}
			// Every Clinic proven to belong to this fixture is a boundary; no Clinic
			// that was not proven can ever enter this set.
			$owned = array_values( array_filter( $this->owned_parent_ids( 'clinic_ids' ), static fn ( int $id ): bool => $id > 1 ) );
			if ( array() === $owned ) {
				return null;
			}

			return array(
				'sql'    => '`' . $tenant_column . '` IN (' . implode( ',', array_fill( 0, count( $owned ), '%d' ) ) . ')',
				'params' => $owned,
			);
		}

		$parent_column = self::PARENT_OWNERSHIP[ $short ] ?? '';
		if ( '' !== $parent_column ) {
			return $this->parent_rule( $short, $parent_column, $cols );
		}

		return null;
	}

	/**
	 * Ownership of a tenant-less table: its declared parent key must be owned.
	 *
	 * The parent id-set is resolved from the same column maps the manifest
	 * declares, so a table can never be pointed at the wrong namespace, and a
	 * WordPress-user column can never resolve to a set (those are removed from
	 * the maps entirely).
	 *
	 * @param string       $short  Short table name.
	 * @param string       $column Declared parent column.
	 * @param list<string> $cols   Table columns.
	 * @return array{sql:string,params:list<int>}|null
	 */
	private function parent_rule( string $short, string $column, array $cols, ?array $sets = null ): ?array {
		if ( ! in_array( $column, $cols, true ) ) {
			return null;
		}
		$set_key = $this->declared_set( $short, $column );
		if ( '' === $set_key || 'user_ids' === $set_key ) {
			return null;
		}
		$ids = $this->owned_parent_ids( $set_key, $sets );
		if ( array() === $ids ) {
			return null;
		}

		return array(
			'sql'    => '`' . $column . '` IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			'params' => $ids,
		);
	}

	/**
	 * The id-set a column of a table feeds, from the declared maps only.
	 *
	 * @param string $short  Short table name.
	 * @param string $column Column.
	 * @return string
	 */
	private function declared_set( string $short, string $column ): string {
		$only = self::TABLE_OWNERSHIP_ONLY[ $short ] ?? array();
		if ( array() !== $only && ! in_array( $column, $only, true ) ) {
			return '';
		}
		$per_table = self::TABLE_OWNERSHIP_COLUMNS[ $short ] ?? array();
		if ( isset( $per_table[ $column ] ) ) {
			return (string) $per_table[ $column ];
		}

		return (string) ( self::OWNERSHIP_COLUMNS[ $column ] ?? '' );
	}

	/**
	 * Parent ids proven owned so far (tenant-derived, never user-derived).
	 *
	 * @param string $set_key Id-set name.
	 * @return list<int>
	 */
	private function owned_parent_ids( string $set_key, ?array $sets = null ): array {
		$values = (array) ( ( $sets ?? $this->current_sets )[ $set_key ] ?? array() );

		return array_values( array_unique( array_filter( array_map( 'intval', $values ) ) ) );
	}

	/**
	 * Fail-closed classification gate: every existing table must have an
	 * explicit, reviewable ownership rule (or be explicitly never-owned).
	 *
	 * This is what makes the new rule set self-defending. If production adds a
	 * tenant column to a table that this class does not classify, the teardown
	 * refuses (OWNERSHIP_RULE_MISSING) instead of silently ignoring rows it
	 * cannot prove - and silently ignoring is how residue appears.
	 *
	 * @return list<string>
	 */
	public function ownership_rule_problems(): array {
		$problems = array();
		foreach ( Preflight::KNOWN_TABLES as $short ) {
			if ( in_array( $short, self::GLOBAL_TABLES, true ) ) {
				continue;
			}
			if ( isset( self::NEVER_OWNED_TABLES[ $short ] ) ) {
				continue;
			}
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$cols = $this->platform->columns( $this->platform->table( $short ) );
			$classified = isset( self::TENANT_OWNERSHIP[ $short ] )
				|| isset( self::PARENT_OWNERSHIP[ $short ] )
				|| isset( self::COMPOSITE_PARENT_COLUMN[ $short ] )
				|| in_array( $short, self::PRESERVED_PARENT_TABLES, true );
			if ( ! $classified ) {
				$problems[] = 'OWNERSHIP_RULE_MISSING:' . $short;
				continue;
			}
			$tenant_column = self::TENANT_OWNERSHIP[ $short ] ?? '';
			if ( '' !== $tenant_column && ! in_array( $tenant_column, $cols, true ) ) {
				$problems[] = 'OWNERSHIP_RULE_STALE:' . $short;
				continue;
			}
			$parent_column = self::PARENT_OWNERSHIP[ $short ] ?? self::COMPOSITE_PARENT_COLUMN[ $short ] ?? '';
			if ( '' !== $parent_column && ! in_array( $parent_column, $cols, true ) ) {
				$problems[] = 'OWNERSHIP_RULE_STALE:' . $short;
			}
		}

		return $problems;
	}

	/**
	 * Foreign-tenant rows that depend on a synthetic WordPress user.
	 *
	 * A synthetic account is deleted at the end of a teardown. If a row that the
	 * fixture does NOT own still references it - a clinician/membership row in a
	 * real Clinic, an invoice issued_by, a note created_by, a participant row of
	 * a real thread - deleting the account would silently break that tenant's
	 * history. The teardown therefore refuses with SCOPED_FOREIGN_DEPENDENCY.
	 *
	 * @param array<string,mixed> $scope       Ownership scope (proven-owned rows).
	 * @param int                 $only_user   Restrict to one synthetic user (0 = all).
	 * @param bool                $live_only   True when every surviving reference is foreign
	 *                                         (used after the fixture rows are already gone).
	 * @return list<string> Human-readable `table.column=user` descriptors.
	 */
	private function foreign_user_dependencies( array $scope, int $only_user = 0, bool $live_only = false ): array {
		$user_ids = (array) ( $scope['user_ids'] ?? array() );
		if ( $only_user > 0 ) {
			$user_ids = array( $only_user );
		}
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( array() === $user_ids ) {
			return array();
		}

		$clinic_id = (int) ( $scope['clinic_id'] ?? 0 );
		$found     = array();
		foreach ( Preflight::KNOWN_TABLES as $short ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			// The append-only audit log is never deleted (it is preserved by
			// contract) and it has no foreign key to wp_users, so a historical actor
			// reference there is evidence, not a dependency: deleting the account
			// cannot damage it. Scanning it would permanently block a teardown that
			// has nothing left to remove.
			if ( 'cpms_audit_logs' === $short ) {
				continue;
			}
			$full       = $this->platform->table( $short );
			$cols       = $this->platform->columns( $full );
			$user_cols  = array_values( array_intersect( self::USER_REFERENCE_COLUMNS, $cols ) );
			if ( array() === $user_cols ) {
				continue;
			}
			$clauses = array();
			$params  = array();
			foreach ( $user_cols as $column ) {
				$clauses[] = '`' . $column . '` IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
				$params    = array_merge( $params, $user_ids );
			}
			$rows = $this->platform->fetch_all(
				'SELECT * FROM `' . $full . '` WHERE (' . implode( ' OR ', $clauses ) . ')',
				$params
			);
			foreach ( $rows as $row ) {
				if ( $this->is_demo_owned_row( $short, $row, $scope, $clinic_id, $live_only ) ) {
					continue;
				}
				foreach ( $user_cols as $column ) {
					$value = (int) ( $row[ $column ] ?? 0 );
					if ( in_array( $value, $user_ids, true ) ) {
						$found[] = $short . '.' . $column . '=' . $value;
					}
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Attribute every candidate row to a fixture artifact, or refuse.
	 *
	 * This is the data-safety gate of Stage 1: a candidate row (one that sits
	 * inside the demo tenant) is only deletable when a POSITIVE fixture artifact
	 * explains it. A real patient's booking, a real member of staff's write, or any
	 * row that names a real WordPress user matches no artifact and refuses the
	 * whole teardown before a single row is touched.
	 *
	 * @param array<string,mixed> $scope    Collected ownership scope.
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $ledger   Verified ownership ledger summary.
	 * @return array{problems:list<string>,counts:array<string,int>}
	 */
	private function attribution_problems( array $scope, array $manifest, array $ledger ): array {
		$ids        = (array) ( $manifest['ids'] ?? array() );
		$fixture    = (string) ( $manifest['fixture_id'] ?? '' );
		$mobile     = $this->normalize_mobile( (string) ( $manifest['patient_mobile'] ?? '' ) );
		$users      = array_map( 'intval', (array) ( $ids['user_ids'] ?? array() ) );
		// The THIRD argument is the verified ledger summary, and its registrations
		// are the durable ownership evidence a demo workflow or the demo's request
		// recorder wrote. Reading `$ledger['row_keys']` off an argument that was
		// ALREADY the row-keys map unwrapped the structure twice, so the map was
		// always empty and a registered row was never attributed - the exact
		// "registered rows do not participate" defect.
		$registered = (array) ( $ledger['row_keys'] ?? array() );
		$sealed     = array();
		foreach ( $ids as $key => $value ) {
			$table = (string) ( self::SEALED_KEY_TABLES[ (string) $key ] ?? '' );
			if ( '' === $table ) {
				// Not a CPMS row id (WordPress users, the booking page post):
				// it can never make a CPMS row deletable.
				continue;
			}
			foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
				$id = (int) $item;
				if ( $id > 0 ) {
					$sealed[ $table ][ $id ] = true;
				}
			}
		}
		$operators = array_map( 'intval', (array) ( $manifest['admin_id'] ?? 0 ) );
		// Every VERIFIED demo Clinic: the fixture's own plus the ones the sealed
		// ledger registered. Nothing else is ever a demo tenant.
		$demo_clinics = array_values(
			array_unique(
				array_filter(
					array_merge(
						array( (int) ( $ids['clinic_id'] ?? 0 ) ),
						array_map( 'intval', (array) ( $ledger['clinic_ids'] ?? array() ) )
					)
				)
			)
		);
		$base = array(
			'mobile'        => $mobile,
			'users'         => $users,
			// The administrator who created the demo is part of its setup: the
			// provisioner stamps that account as the creator of several synthetic
			// rows. It is allowed as an ACTOR reference, but it is never a tenant and
			// never proof of ownership on its own.
			'operators'     => array_values( array_filter( $operators ) ),
			'fixture'       => $fixture,
			'tenant_org'    => (int) ( $ids['organization_id'] ?? 0 ),
			'tenant_clinic' => (int) ( $ids['clinic_id'] ?? 0 ),
			'demo_clinics'  => $demo_clinics,
			'sealed'        => $sealed,
			'registered'    => $registered,
			// Parent sets are filled in per pass from PROVEN rows only.
			'patients'      => array(),
			'identities'    => array(),
			'visits'        => array(),
			'threads'       => array(),
			'parents'       => array(),
		);

		// Load every candidate row once. Attribution then runs in memory, so a
		// child is only ever claimed through a parent that is itself proven.
		$candidates = array();
		foreach ( (array) ( $scope['tables'] ?? array() ) as $short => $row_ids ) {
			$short = (string) $short;
			if ( in_array( $short, self::GLOBAL_TABLES, true ) || in_array( $short, self::PRESERVED_PARENT_TABLES, true ) ) {
				continue;
			}
			$row_ids = array_values( array_filter( array_map( 'intval', (array) $row_ids ) ) );
			if ( array() === $row_ids ) {
				continue;
			}
			$full = $this->platform->table( $short );
			$cols = $this->platform->columns( $full );
			if ( ! in_array( 'id', $cols, true ) ) {
				// Composite-key tables are loaded below, by their verified key.
				continue;
			}
			$rows = $this->platform->fetch_all(
				'SELECT * FROM `' . $full . '` WHERE id IN (' . implode( ',', array_fill( 0, count( $row_ids ), '%d' ) ) . ')',
				$row_ids
			);
			foreach ( $rows as $row ) {
				$row_id = (int) ( $row['id'] ?? 0 );
				if ( $row_id > 0 ) {
					$candidates[ $short ][ (string) $row_id ] = $row;
				}
			}
		}
		// Composite-key candidates carry no surrogate id. They used to be skipped
		// by attribution altogether - collected, then deleted without being proven.
		foreach ( (array) ( $scope['composite'] ?? array() ) as $short => $entries ) {
			$short = (string) $short;
			$key   = (array) ( self::COMPOSITE_KEY_TABLES[ $short ] ?? array() );
			if ( array() === $key || array() === (array) $entries ) {
				continue;
			}
			$full = $this->platform->table( $short );
			$cols = $this->platform->columns( $full );
			$ok   = true;
			$where = array();
			foreach ( $key as $column ) {
				if ( ! in_array( (string) $column, $cols, true ) ) {
					$ok = false;
					break;
				}
				$where[] = '`' . $column . '` = %s';
			}
			if ( ! $ok ) {
				continue;
			}
			foreach ( (array) $entries as $fingerprint => $tuple ) {
				$rows = $this->platform->fetch_all(
					'SELECT * FROM `' . $full . '` WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1',
					array_map( 'strval', (array) $tuple )
				);
				if ( array() !== $rows ) {
					$candidates[ $short ][ (string) $fingerprint ] = $rows[0];
				}
			}
		}

		// Dependency-ordered, monotone fixed point. `$proven` only ever grows and
		// every entry rests on a rule that was evaluated against ALREADY proven
		// parents, so nothing circular can bootstrap itself into ownership.
		$proven = array();
		$passes = 0;
		do {
			$changed = false;
			$context = $this->attribution_context( $base, $proven );
			$order   = Preflight::KNOWN_TABLES;
			foreach ( array_keys( $candidates ) as $extra ) {
				if ( ! in_array( (string) $extra, $order, true ) ) {
					$order[] = (string) $extra;
				}
			}
			foreach ( $order as $short ) {
				if ( ! isset( $candidates[ $short ] ) ) {
					continue;
				}
				foreach ( $candidates[ $short ] as $row_key => $row ) {
					if ( isset( $proven[ $short ][ $row_key ] ) ) {
						continue;
					}
					if ( ! $this->row_is_attributed( (string) $short, $row, $context ) ) {
						continue;
					}
					$proven[ $short ][ $row_key ] = true;
					$changed                      = true;
				}
			}
			++$passes;
		} while ( $changed && $passes < self::ATTRIBUTION_PASSES );

		$problems = array();
		$counts   = array();
		$limit    = 5;
		foreach ( $candidates as $short => $rows ) {
			foreach ( $rows as $row_key => $row ) {
				if ( isset( $proven[ $short ][ $row_key ] ) ) {
					continue;
				}
				$counts[ (string) $short ] = (int) ( $counts[ (string) $short ] ?? 0 ) + 1;
				if ( $limit > 0 ) {
					$problems[] = 'SCOPED_FOREIGN_TENANT_ROW:' . $short . ':' . $row_key;
					--$limit;
				}
			}
		}
		if ( array() !== $counts ) {
			$summary = array();
			foreach ( $counts as $short => $count ) {
				$summary[] = $short . '=' . $count;
			}
			$problems[] = 'SCOPED_FOREIGN_TENANT_ROW_TOTAL: ' . implode( ', ', $summary );
		}

		return array( 'problems' => $problems, 'counts' => $counts, 'proven' => $proven );
	}

	/**
	 * Attribution context for one pass: parent sets come from PROVEN rows only.
	 *
	 * Copying the candidate sets here was the transitive-attribution defect: a
	 * real patient who booked into the demo Clinic is a *candidate* (the tenant
	 * boundary collects them), so every candidate visit/invoice/payment that named
	 * that patient was "attributed" - and deleted. Proven sets are disjoint from
	 * candidates: a row enters one only after a rule grounded in independent
	 * evidence accepted it.
	 *
	 * @param array<string,mixed> $base   Context without parent sets.
	 * @param array<string,mixed> $proven table => (row key => true).
	 * @return array<string,mixed>
	 */
	private function attribution_context( array $base, array $proven ): array {
		$context                = $base;
		$context['patients']    = (array) ( $proven['cpms_patients'] ?? array() );
		$context['identities']  = (array) ( $proven['cpms_patient_identities'] ?? array() );
		$context['visits']      = (array) ( $proven['cpms_visits'] ?? array() );
		$context['threads']     = (array) ( $proven['cpms_message_threads'] ?? array() );
		$context['parents']     = array();
		foreach ( self::PARENT_RULES as $parent_rule ) {
			$parent_table = (string) $parent_rule[0];
			$context['parents'][ $parent_table ] = (array) ( $proven[ $parent_table ] ?? array() );
		}

		return $context;
	}

	/**
	 * Does a fixture artifact explain this concrete row?
	 *
	 * @param string              $short   Short table name.
	 * @param array<string,mixed> $row     Row.
	 * @param array<string,mixed> $context Attribution context.
	 * @return bool
	 */
	private function row_is_attributed( string $short, array $row, array $context ): bool {
		$row_id = (int) ( $row['id'] ?? 0 );
		// Membership rows are the one family that can name a REAL person, so they
		// are decided before every generic rule and never fall through to one.
		//
		// The hole this closes: a synthetic manager who invited a real
		// administrator into the demo Clinic produced a membership row whose
		// `wp_user_id` is that administrator and whose `invited_by_wp_user_id` is
		// the synthetic manager. The generic `@user` rule matched the INVITER and
		// called the row demo-owned - so a real person's membership was deleted
		// because a synthetic account had created it. Ownership of a grant is now
		// only ever explicit registration plus a re-proved operator binding.
		if ( in_array( $short, self::OPERATOR_GRANT_TABLES, true ) ) {
			// The grant's OWN principal must be a synthetic fixture account and the
			// row must live in a verified demo tenant; who INVITED or CREATED it is
			// never evidence. That keeps a real administrator's membership out of
			// the deletable set no matter which synthetic account produced it,
			// while the provisioner's own persona memberships stay provable without
			// depending on a ledger that only the request recorder writes.
			return $this->operator_grant_is_bounded( $short, $row, $context );
		}
		// A row the demo explicitly registered is owned by definition: that is the
		// durable ownership registration a controlled demo workflow writes.
		if ( isset( $context['registered'][ $short ][ $row_id ] ) ) {
			return true;
		}
		// A row whose exact (table, id) the sealed manifest names was created by
		// the demo at provisioning time. The TABLE is part of the identity: a
		// numeric coincidence across tables is never ownership.
		if ( isset( $context['sealed'][ $short ][ $row_id ] ) ) {
			return true;
		}
		// A row that names a WordPress user OTHER than a synthetic fixture account
		// was touched by a real person; no amount of tenant or parent evidence
		// makes deleting it safe.
		foreach ( self::USER_REFERENCE_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}
			$value = (int) ( $row[ $column ] ?? 0 );
			if ( $value > 0 && ! in_array( $value, (array) $context['users'], true ) && ! in_array( $value, (array) $context['operators'], true ) ) {
				return false;
			}
		}
		// Parent rules first: a child row is owned only through an owned parent.
		if ( isset( self::PARENT_RULES[ $short ] ) ) {
			$parent_rule = self::PARENT_RULES[ $short ];
			$column      = (string) $parent_rule[1];
			$parent_id   = (int) ( $row[ $column ] ?? 0 );

			return $parent_id > 0
				&& isset( $context['parents'][ (string) $parent_rule[0] ][ $parent_id ] );
		}
		// Configuration is a positive claim ONLY for the allowlisted tables.
		if ( in_array( $short, self::CONFIGURATION_TABLES, true ) ) {
			return true;
		}
		$rule = self::ATTRIBUTION[ $short ] ?? '@none';
		if ( '@none' === $rule ) {
			// No artifact explains this row: refuse. Guessing is what deletes
			// real data; a retained row can still be handled by hand.
			return false;
		}
		if ( '@user' === $rule ) {
			foreach ( self::USER_REFERENCE_COLUMNS as $column ) {
				if ( array_key_exists( $column, $row ) && in_array( (int) ( $row[ $column ] ?? 0 ), (array) $context['users'], true ) ) {
					return true;
				}
			}

			return false;
		}
		if ( '@fixture' === $rule ) {
			foreach ( $row as $value ) {
				if ( is_string( $value ) && '' !== (string) $context['fixture'] && false !== strpos( $value, (string) $context['fixture'] ) ) {
					return true;
				}
			}

			return false;
		}
		if ( '@mobile' === $rule ) {
			$candidates = array( 'mobile', 'holder_mobile', 'recipient', 'mobile_at_link', 'normalized_mobile' );
			foreach ( $candidates as $column ) {
				if ( array_key_exists( $column, $row ) && '' !== (string) $context['mobile'] && $this->normalize_mobile( (string) $row[ $column ] ) === (string) $context['mobile'] ) {
					return true;
				}
			}
			foreach ( $row as $value ) {
				if ( is_string( $value ) && '' !== (string) $context['fixture'] && false !== strpos( $value, (string) $context['fixture'] ) ) {
					return true;
				}
			}

			return false;
		}
		if ( '@patient' === $rule ) {
			if ( 'cpms_patients' === $short ) {
				if ( isset( $context['sealed'][ $short ][ $row_id ] ) ) {
					return true;
				}
				if ( array_key_exists( 'identity_id', $row ) && isset( $context['identities'][ (int) ( $row['identity_id'] ?? 0 ) ] ) ) {
					return true;
				}
				foreach ( array( 'mobile', 'phone' ) as $column ) {
					if ( array_key_exists( $column, $row ) && '' !== (string) $context['mobile'] && $this->normalize_mobile( (string) $row[ $column ] ) === (string) $context['mobile'] ) {
						return true;
					}
				}
				foreach ( array( 'mrn', 'first_name', 'last_name' ) as $column ) {
					if ( array_key_exists( $column, $row ) && '' !== (string) $context['fixture'] && false !== strpos( (string) $row[ $column ], (string) $context['fixture'] ) ) {
						return true;
					}
				}

				return false;
			}

			return array_key_exists( 'patient_id', $row ) && isset( $context['patients'][ (int) ( $row['patient_id'] ?? 0 ) ] );
		}
		if ( '@recipient' === $rule ) {
			if ( array_key_exists( 'recipient_patient_id', $row ) && (int) ( $row['recipient_patient_id'] ?? 0 ) > 0 ) {
				return isset( $context['patients'][ (int) $row['recipient_patient_id'] ] );
			}
			foreach ( $row as $value ) {
				if ( is_string( $value ) && '' !== (string) $context['fixture'] && false !== strpos( $value, (string) $context['fixture'] ) ) {
					return true;
				}
			}

			// A queue row with no patient binding carries no proof of its origin.
			// It used to be accepted unconditionally; a notification created by a
			// real actor inside the demo tenant is not demo data, so the row is
			// refused unless the ledger registered it (checked above).
			return false;
		}
		if ( '@visit' === $rule ) {
			return array_key_exists( 'visit_id', $row ) && isset( $context['visits'][ (int) ( $row['visit_id'] ?? 0 ) ] );
		}
		if ( '@thread' === $rule ) {
			if ( 'cpms_message_threads' === $short ) {
				return ( array_key_exists( 'patient_id', $row ) && isset( $context['patients'][ (int) ( $row['patient_id'] ?? 0 ) ] ) )
					|| in_array( (int) ( $row['created_by_wp_user_id'] ?? 0 ), (array) $context['users'], true );
			}

			return array_key_exists( 'thread_id', $row ) && isset( $context['threads'][ (int) ( $row['thread_id'] ?? 0 ) ] );
		}

		return false;
	}

	/**
	 * A membership the demo granted to the operator: bounded or refused.
	 *
	 * Requires all of: the row belongs to the demo Organization (or, for a
	 * Clinic membership, the demo Clinic); every WordPress user it names is
	 * either a fixture account or the operator recorded in the sealed manifest;
	 * and at least one of them really is that operator. A membership that names
	 * a different real person is never owner-registered, whatever the ledger says.
	 *
	 * @param string              $short   Table.
	 * @param array<string,mixed> $row     Row.
	 * @param array<string,mixed> $context Attribution context.
	 * @return bool
	 */
	private function operator_grant_is_bounded( string $short, array $row, array $context ): bool {
		// 1. The row must belong to a VERIFIED demo tenant. Every verified demo
		//    Clinic counts, not only the fixture's first one: a demo may own more
		//    than one Clinic (`ScopedClinics::create()`), and refusing those would
		//    block a legitimate teardown instead of protecting anybody.
		if ( 'cpms_organization_memberships' === $short ) {
			$tenant = (int) ( $row['organization_id'] ?? 0 );
			if ( $tenant <= 0 || $tenant !== (int) ( $context['tenant_org'] ?? 0 ) ) {
				return false;
			}
		} else {
			$tenant  = (int) ( $row['clinic_id'] ?? 0 );
			$clinics = array_map( 'intval', (array) ( $context['demo_clinics'] ?? array() ) );
			if ( $tenant <= 1 || ! in_array( $tenant, $clinics, true ) ) {
				return false;
			}
		}
		$users     = array_map( 'intval', (array) ( $context['users'] ?? array() ) );
		$operators = array_map( 'intval', (array) ( $context['operators'] ?? array() ) );
		// 2. The grant's own principal must be a synthetic fixture account. A real
		//    person's membership is refused HERE, whatever else the row says - the
		//    defect this replaces accepted the synthetic INVITER as evidence and
		//    deleted a real administrator's membership.
		$principal = (int) ( $row['wp_user_id'] ?? 0 );
		if ( $principal <= 0 || ! in_array( $principal, $users, true ) ) {
			return false;
		}
		// 3. No OTHER user reference may name a person outside the fixture. A
		//    synthetic inviter is acceptable (it is the demo acting), a real one is
		//    not.
		foreach ( self::USER_REFERENCE_COLUMNS as $column ) {
			if ( 'wp_user_id' === $column || ! array_key_exists( $column, $row ) ) {
				continue;
			}
			$value = (int) ( $row[ $column ] ?? 0 );
			if ( $value <= 0 ) {
				continue;
			}
			if ( in_array( $value, $users, true ) || in_array( $value, $operators, true ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Digits-only mobile, with the Iranian country prefix folded away.
	 *
	 * @param string $mobile Raw mobile.
	 * @return string
	 */
	private function normalize_mobile( string $mobile ): string {
		$digits = preg_replace( '/\D+/', '', $mobile );
		$digits = is_string( $digits ) ? $digits : '';
		$digits = (string) preg_replace( '/^(?:0098|98)/', '0', $digits );
		if ( 10 === strlen( $digits ) && '9' === substr( $digits, 0, 1 ) ) {
			$digits = '0' . $digits;
		}

		return $digits;
	}

	/**
	 * Is this concrete row inside the proven demo scope?
	 *
	 * @param string              $short     Short table name.
	 * @param array<string,mixed> $row       Row.
	 * @param array<string,mixed> $scope     Scope.
	 * @param int                 $clinic_id Demo Clinic.
	 * @return bool
	 */
	private function is_demo_owned_row( string $short, array $row, array $scope, int $clinic_id, bool $live_only = false ): bool {
		// Preserved anchors survive the teardown by design (the append-only audit
		// log has foreign keys to them), so they must be recognised as demo-owned
		// in the preview AND in the post-delete per-user scan. Identity is the
		// proof - the demo Clinic(s), Organization and Locations - never a WordPress
		// user reference, which is exactly what the live-only scan used to confuse
		// after `set_clinic_status()` stamped `status_updated_by_wp_user_id`.
		$anchors = (array) ( $scope['anchors'][ $short ] ?? array() );
		if ( array() !== $anchors ) {
			return in_array( (int) ( $row['id'] ?? 0 ), array_map( 'intval', $anchors ), true );
		}
		if ( $live_only ) {
			// Every deletable demo row is already gone by this point, so a row we do
			// not hold as a preserved anchor belongs to someone else.
			return false;
		}
		$key = self::COMPOSITE_KEY_TABLES[ $short ] ?? array();
		if ( array() !== $key ) {
			$signature = array();
			foreach ( $key as $column ) {
				$signature[] = (string) ( $row[ $column ] ?? '' );
			}

			return isset( $scope['composite'][ $short ][ implode( '|', $signature ) ] );
		}
		if ( 'cpms_jobs' === $short ) {
			return in_array( (int) ( $row['id'] ?? 0 ), (array) ( $scope['jobs'] ?? array() ), true );
		}
		if ( 'cpms_operational_logs' === $short ) {
			return in_array( (int) ( $row['id'] ?? 0 ), (array) ( $scope['operational_logs'] ?? array() ), true );
		}
		if ( 'cpms_audit_logs' === $short ) {
			// Audit rows are preserved, never deleted; a demo-Clinic audit row that
			// names a synthetic actor is part of the fixture's own history.
			$owned_clinics = (array) ( $scope['anchors']['cpms_clinics'] ?? array() );
			if ( array() === $owned_clinics ) {
				$owned_clinics = $clinic_id > 1 ? array( $clinic_id ) : array();
			}

			return in_array( (int) ( $row['clinic_id'] ?? 0 ), array_map( 'intval', $owned_clinics ), true );
		}
		if ( 'cpms_rate_limits' === $short ) {
			return in_array( (string) ( $row['window_key'] ?? '' ), (array) ( $scope['rate_limit_keys'] ?? array() ), true );
		}
		$id = (int) ( $row['id'] ?? 0 );

		return $id > 0 && in_array( $id, (array) ( $scope['tables'][ $short ] ?? array() ), true );
	}

	/**
	 * Collect rows of a table that has no surrogate `id`, by its verified key.
	 *
	 * Ownership is proven by the table's DECLARED PARENT only (a thread or a
	 * visit that is already proven owned through its tenant). The other key
	 * columns are used to ADDRESS the row for deletion, never to select it: an
	 * OR-predicate over `wp_user_id` selected a foreign thread's participant row
	 * for a synthetic user and then deleted it.
	 *
	 * @param string             $short  Short table name.
	 * @param string             $full   Full table name.
	 * @param list<string>       $cols   Table columns.
	 * @param list<string>       $key    Verified key columns.
	 * @param array<string,list<int>> $sets Owned id-sets (read-only).
	 * @param array<string,array<string,list<int|string>>> $owned Collected keys.
	 * @return bool Whether anything new was collected.
	 */
	private function collect_composite_rows( string $short, string $full, array $cols, array $key, array $sets, array &$owned ): bool {
		foreach ( $key as $column ) {
			if ( ! in_array( $column, $cols, true ) ) {
				// Fail closed: a key column that does not exist proves nothing.
				return false;
			}
		}
		$parent_column      = self::COMPOSITE_PARENT_COLUMN[ $short ] ?? '';
		$rule               = '' === $parent_column ? null : $this->parent_rule( $short, $parent_column, $cols, $sets );
		if ( null === $rule ) {
			return false;
		}
		$select = implode( ',', array_map( static fn ( string $c ): string => '`' . $c . '`', $key ) );
		$rows   = $this->platform->fetch_all(
			'SELECT ' . $select . ' FROM `' . $full . '` WHERE (' . $rule['sql'] . ')',
			$rule['params']
		);
		$changed = false;
		foreach ( $rows as $row ) {
			$tuple     = array();
			$signature = array();
			foreach ( $key as $column ) {
				$value       = $row[ $column ] ?? '';
				$signature[] = (string) $value;
				$tuple[]     = ctype_digit( (string) $value ) ? (int) $value : (string) $value;
			}
			$fingerprint = implode( '|', $signature );
			if ( '' === $fingerprint || isset( $owned[ $short ][ $fingerprint ] ) ) {
				continue;
			}
			$owned[ $short ][ $fingerprint ] = $tuple;
			$changed                         = true;
		}

		return $changed;
	}

	/**
	 * Read-only teardown preview.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array{ok:bool,problems:list<string>,plan:list<array<string,mixed>>,scope:array<string,mixed>,baseline:array<string,mixed>,audit:array<string,mixed>}
	 */
	public function preview( array $manifest ): array {
		$problems = array();
		if ( ! $this->manifest->verify( $manifest ) ) {
			return $this->failed_preview( array( 'MANIFEST_TAMPERED' ) );
		}
		// Fail closed when the rule set no longer describes the live schema. An
		// unclassified table is never "probably fine": it is residue we cannot
		// prove, and proving is the whole contract of this teardown.
		$rule_problems = $this->ownership_rule_problems();
		if ( array() !== $rule_problems ) {
			return $this->failed_preview( $rule_problems );
		}
		$baseline = $this->resolve_baseline( $manifest );
		if ( array() === $baseline ) {
			return $this->failed_preview( array( 'BASELINE_MISSING' ) );
		}
		$baseline_result = $this->baseline->verify( $baseline );
		if ( ! $baseline_result['ok'] ) {
			$problems = array_merge( $problems, $baseline_result['problems'] );
		}
		// A Clinic that names the demo Organization but predates the sealed
		// baseline cannot be proven demo-created, so it is neither claimed nor left
		// behind silently: refuse before any destructive work and say what to do.
		$tenants = $this->owned_tenants( $manifest, $baseline );
		if ( '' !== (string) $tenants['ledger_code'] ) {
			$problems[] = $tenants['ledger_code'] . ': ' . (string) $tenants['ledger_detail'];
		}
		// A pending intent is reported BEFORE the unproven set it explains: an
		// interrupted registration leaves a Clinic that no sealed record names yet,
		// so both conditions hold, and the actionable one is the repair - not the
		// symptom. The refusal therefore surfaces as SCOPED_OWNERSHIP_PENDING.
		if ( array() !== $tenants['pending'] ) {
			$tokens = array();
			foreach ( $tenants['pending'] as $intent ) {
				$tokens[] = (string) ( $intent['token'] ?? '' );
			}
			$problems[] = 'SCOPED_OWNERSHIP_PENDING: ' . implode( ',', array_filter( $tokens ) );
		}
		if ( array() !== $tenants['unproven'] ) {
			$problems[] = 'SCOPED_OWNERSHIP_UNPROVEN: cpms_clinics=' . implode( ',', $tenants['unproven'] );
		}
		$audit = $this->audit_guard->verify_chain();
		if ( ! $audit['ok'] ) {
			$problems[] = $audit['error'];
		}
		$scope = $this->collect_scope( $manifest, $baseline );
		// Stage 1 gate: every candidate row must be attributable to a fixture
		// artifact. This is what stops a real patient's booking, or a real member
		// of staff's write, from ever being deleted as demo data.
		$attribution = $this->attribution_problems( $scope, $manifest, $tenants );
		if ( array() !== $scope['processing_jobs'] ) {
			$problems[] = 'JOBS_ACTIVE: ' . implode( ',', $scope['processing_jobs'] );
		}
		if ( array() !== $scope['shared_keys'] ) {
			$problems[] = 'SHARED_RATE_LIMIT_KEY';
		}
		global $wpdb;
		$free = $wpdb->get_var( "SELECT IS_FREE_LOCK('cpms_jobs_tick')" );
		if ( '1' !== (string) $free && 1 !== $free ) {
			$problems[] = 'QUEUE_RUNNER_ACTIVE';
		}
		$problems = array_merge( $problems, $this->scan_untracked_fixture_rows( $manifest, $baseline, $scope, false ) );
		$problems = array_merge( $problems, (array) $attribution['problems'] );

		// Nothing is deleted while a row we do NOT own still references a synthetic
		// account. This is the whole point of the fix: a post-baseline row that
		// names a demo user is evidence of a foreign tenant, not evidence of
		// ownership.
		$foreign = $this->foreign_user_dependencies( $scope );
		if ( array() !== $foreign ) {
			$problems[] = 'SCOPED_FOREIGN_DEPENDENCY: ' . implode( ', ', array_slice( $foreign, 0, 5 ) );
		}

		$plan = array();
		foreach ( $scope['tables'] as $short => $ids ) {
			if ( in_array( $short, self::PRESERVED_PARENT_TABLES, true ) ) {
				continue;
			}
			$plan[] = array( 'table' => $short, 'rows' => count( $ids ) );
		}
		foreach ( $scope['composite'] as $short => $keys ) {
			$plan[] = array( 'table' => $short . ' (composite key)', 'rows' => count( $keys ) );
		}
		foreach ( self::PRESERVED_PARENT_TABLES as $short ) {
			$plan[] = array( 'table' => $short . ' (preserved audit anchor)', 'rows' => count( (array) ( $scope['tables'][ $short ] ?? array() ) ) );
		}
		$plan[] = array( 'table' => 'cpms_jobs', 'rows' => count( $scope['jobs'] ) );
		$plan[] = array( 'table' => 'cpms_operational_logs', 'rows' => count( $scope['operational_logs'] ) );
		$plan[] = array( 'table' => 'cpms_rate_limits', 'rows' => count( $scope['rate_limit_keys'] ) );
		$plan[] = array( 'table' => 'cpms_audit_logs (preserved)', 'rows' => count( $scope['audit_logs'] ) );
		$plan[] = array( 'table' => 'wp_users', 'rows' => count( $scope['user_ids'] ) );
		$plan[] = array( 'table' => 'wp_posts', 'rows' => isset( $manifest['ids']['booking_page_id'] ) ? 1 : 0 );

		return array(
			'ok'       => array() === $problems,
			'problems' => $problems,
			'plan'     => $plan,
			'scope'    => $scope,
			'baseline' => $baseline_result,
			'audit'    => $audit,
		);
	}

	/**
	 * Execute verified teardown under both concurrency guards.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int $actor_id Acting Administrator.
	 * @return array<string,mixed>
	 */
	public function execute( array $manifest, int $actor_id ): array {
		return $this->env->with_lock(
			'scoped_teardown',
			function () use ( $manifest, $actor_id ): array {
				return $this->do_execute( $manifest, $actor_id );
			}
		);
	}

	/**
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int $actor_id Acting Administrator.
	 * @return array<string,mixed>
	 */
	private function do_execute( array $manifest, int $actor_id ): array {
		$manifest = $this->adopt_recovered_baseline( $manifest );
		$preview  = $this->preview( $manifest );
		if ( ! $preview['ok'] ) {
			foreach ( (array) $preview['problems'] as $problem ) {
				if ( 0 === strpos( (string) $problem, 'SCOPED_FOREIGN_DEPENDENCY' ) ) {
					throw new Exception(
						'SCOPED_FOREIGN_DEPENDENCY',
						'داده‌ای بیرون از محدودهٔ دمو به یک کاربر ساختگی وابسته است؛ پاک‌سازی متوقف شد: ' . $problem
					);
				}
				if ( 0 === strpos( (string) $problem, 'SCOPED_FOREIGN_TENANT_ROW' ) ) {
					throw new Exception(
						'SCOPED_FOREIGN_TENANT_ROW',
						'درمانگاه دمو رکوردی دارد که به هیچ اثر ساختگی این دمو نسبت داده نمی‌شود (احتمالاً دادهٔ کاربر واقعی)؛ '
						. 'هیچ داده‌ای حذف نشد: ' . $problem
					);
				}
				if ( 0 === strpos( (string) $problem, 'SCOPED_OWNERSHIP_LEDGER_INVALID' ) ) {
					throw new Exception(
						'SCOPED_OWNERSHIP_LEDGER_INVALID',
						'دفتر مالکیت دمو معتبر نیست؛ هیچ داده‌ای حذف نشد. ' . $problem
					);
				}
				if ( 0 === strpos( (string) $problem, 'SCOPED_OWNERSHIP_PENDING' ) ) {
					throw new Exception(
						'SCOPED_OWNERSHIP_PENDING',
						'یک ثبت درمانگاه نمایشی نیمه‌تمام باقی مانده است؛ هیچ داده‌ای حذف نشد. '
						. 'آن را در صفحهٔ دمو تعیین تکلیف کنید: ' . $problem
					);
				}
				if ( 0 === strpos( (string) $problem, 'SCOPED_OWNERSHIP_UNPROVEN' ) ) {
					throw new Exception(
						'SCOPED_OWNERSHIP_UNPROVEN',
						'درمانگاهی به سازمان دمو اشاره می‌کند ولی ساخت آن در این نشست اثبات نمی‌شود؛ هیچ داده‌ای حذف نشد. '
						. 'آن را از سازمان دمو جدا کنید یا پیش از ادامه بررسی کنید: ' . $problem
					);
				}
			}
			throw new Exception( 'TEARDOWN_BLOCKED', 'پاکسازی ایمن متوقف شد: ' . implode( ' | ', array_slice( $preview['problems'], 0, 3 ) ) );
		}
		global $wpdb;
		$tick = $wpdb->get_var( "SELECT GET_LOCK('cpms_jobs_tick', 0)" );
		if ( '1' !== (string) $tick && 1 !== $tick ) {
			throw new Exception( 'JOBS_ACTIVE', 'قفل صف CPMS در اختیار اجراکننده دیگری است.' );
		}
		try {
			$manifest['status']         = 'tearing_down';
			$manifest['scope_snapshot'] = $preview['scope'];
			$manifest                   = $this->manifest->save( $manifest );
			$deleted                    = array();
			$stage                      = 'start';
			try {
				$stage   = 'sessions';
				$user_ids = (array) ( $preview['scope']['user_ids'] ?? array() );
				foreach ( $user_ids as $user_id ) {
					$this->env->destroy_sessions( (int) $user_id );
					$this->env->set_user_password( (int) $user_id, Credentials::generate_password() );
				}
				$stage = 'suspend-clinic';
				$this->suspend_clinic( $manifest, $preview['scope'] );
				$stage = 'global';
				$deleted = array_merge( $deleted, $this->delete_global_rows( $preview['scope'] ) );
				$this->env->maybe_inject_fault( 'scoped_teardown:after-global', array( 'stage' => $stage ) );
				$stage = 'cpms-tables';
				$deleted = array_merge( $deleted, $this->delete_table_rows( $preview['scope'] ) );
				$this->env->maybe_inject_fault( 'scoped_teardown:after-tables', array( 'stage' => $stage ) );
				$stage = 'wp-core';
				$deleted = array_merge( $deleted, $this->delete_wp_records( $manifest, $user_ids ) );
				$this->env->maybe_inject_fault( 'scoped_teardown:after-wp', array( 'stage' => $stage ) );
				$stage = 'post-verify';
				$this->env->maybe_inject_fault( 'scoped_teardown:before-post-verify', array( 'stage' => $stage ) );
				$remaining = $this->post_verify( $manifest );
				if ( array() !== $remaining ) {
					throw new Exception( 'TEARDOWN_INCOMPLETE', 'حذف کامل اثبات نشد: ' . implode( ', ', array_slice( $remaining, 0, 5 ) ) );
				}
			} catch ( \Throwable $error ) {
				$manifest['status']          = 'teardown_failed';
				$manifest['failed_stage']    = $stage;
				$manifest['failure_code']    = $error instanceof Exception ? $error->demo_code() : 'TEARDOWN_FAILED';
				$manifest['failure_message'] = $error->getMessage();
				$manifest['partial_deleted'] = $deleted;
				$this->manifest->save( $manifest );
				throw $error;
			}

			$tombstone = array(
				'fixture_id'       => (string) $manifest['fixture_id'],
				'removed_at'       => $this->env->now_sql(),
				'at'               => $this->env->now_sql(),
				'actor_id'         => $actor_id,
				'deleted'          => $deleted,
				'preserved_audit'  => count( (array) ( $preview['scope']['audit_logs'] ?? array() ) ),
				'preserved_parent_anchors' => self::PRESERVED_PARENT_TABLES,
				'baseline'         => $preview['baseline'],
				'audit_chain'      => $preview['audit'],
				'host_before'      => (array) ( $manifest['host_before'] ?? array() ),
				'host_after'       => $this->host_evidence(),
				'remaining'        => array(),
				'clean'            => true,
				'ok'               => true,
			);
			// Ordering is a safety property, not a style choice.
			//
			// The tombstone is the durable proof that this fixture was torn down
			// completely. It is written through a verified helper FIRST, so a lost
			// write aborts before any recovery evidence is discarded. Only then is
			// the sealed pending baseline cleared: that record is the sole way an
			// interrupted run can still prove what pre-existed, so clearing it
			// before the replacement record is durable would destroy the ability to
			// recover safely.
			$this->manifest->write_tombstone( $tombstone );
			$this->manifest->clear_pending_baseline();
			// The ledger is recovery evidence for a run that could still be
			// resumed, so it is discarded only after the tombstone is durable and
			// the pending baseline is gone: every step that loses evidence loses it
			// in the order that keeps the remaining evidence sufficient.
			$this->ownership->clear();
			$this->manifest->disable_mode();
			$this->env->option_delete( ScopedManifest::LOCK_OPTION );
			$this->env->option_delete( 'cpsd_had_clean_teardown' );
			$this->manifest->delete();

			return array(
				'ok'        => true,
				'deleted'   => $deleted,
				'remaining' => array(),
				'tombstone' => $tombstone,
			);
		} finally {
			$wpdb->get_var( "SELECT RELEASE_LOCK('cpms_jobs_tick')" );
		}
	}

	/**
	 * Adopt the sealed pre-provisioning baseline into a manifest that never got one.
	 *
	 * A run interrupted during provisioning stops before the baseline is sealed
	 * into the manifest, so the recovery teardown has to take it from the sealed
	 * pending record. Any change to the payload invalidates the HMAC, so the
	 * adopted manifest is re-sealed (and persisted) before it is verified again.
	 *
	 * @param array<string,mixed> $manifest Manifest as loaded.
	 * @return array<string,mixed> Manifest with a sealed baseline.
	 */
	private function adopt_recovered_baseline( array $manifest ): array {
		if ( array() !== (array) ( $manifest['baseline'] ?? array() ) ) {
			return $manifest;
		}
		$recovered = $this->resolve_baseline( $manifest );
		if ( array() === $recovered ) {
			return $manifest;
		}
		$manifest['baseline'] = $recovered;
		if ( array() === (array) ( $manifest['host_before'] ?? array() ) ) {
			$pending = $this->manifest->load_pending_baseline( (string) ( $manifest['fixture_id'] ?? '' ) );
			if ( is_array( $pending ) ) {
				$manifest['host_before'] = (array) ( $pending['host_before'] ?? array() );
			}
		}

		return $this->manifest->save( $manifest );
	}

	/**
	 * Baseline for this fixture: the manifest's sealed copy, or — for a run that
	 * was interrupted during provisioning — the sealed pre-provisioning record.
	 *
	 * An absent, tampered, cross-site or cross-fixture record yields an empty
	 * array, which every caller treats as a refusal.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return array<string,mixed>
	 */
	private function resolve_baseline( array $manifest ): array {
		$baseline = (array) ( $manifest['baseline'] ?? array() );
		if ( array() !== $baseline ) {
			return $baseline;
		}
		$pending = $this->manifest->load_pending_baseline( (string) ( $manifest['fixture_id'] ?? '' ) );

		return is_array( $pending ) ? (array) ( $pending['baseline'] ?? array() ) : array();
	}

	/**
	 * Read-only host counters recorded in the tombstone as before/after evidence.
	 *
	 * @return array<string,int>
	 */
	private function host_evidence(): array {
		return array(
			'clinic_count'        => $this->platform->count_all( 'cpms_clinics' ),
			'organization_count'  => $this->platform->count_all( 'cpms_organizations' ),
			'location_count'      => $this->platform->count_all( 'cpms_locations' ),
			'active_clinic_count' => $this->platform->count_where( $this->platform->table( 'cpms_clinics' ), 'status = %s', array( 'active' ) ),
			'audit_max_id'        => $this->platform->max_id( 'cpms_audit_logs' ),
		);
	}

	/**
	 * @param array<string,mixed> $manifest Manifest.
	 * @return void
	 */
	private function suspend_clinic( array $manifest, array $scope ): void {
		$org_id = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$slug   = (string) ( $manifest['slugs']['clinic'] ?? '' );
		$clinics = array_values( array_filter( array_map( 'intval', (array) ( $scope['anchors']['cpms_clinics'] ?? array() ) ) ) );
		if ( array() === $clinics || $org_id <= 1 || '' === $slug ) {
			return;
		}
		global $wpdb;
		$table = $this->platform->table( 'cpms_clinics' );
		foreach ( $clinics as $clinic_id ) {
			if ( $clinic_id <= 1 ) {
				continue;
			}
			// Identity, not inference: the id comes from the sealed anchor set (the
			// manifest and/or the sealed ownership ledger) AND the row must still
			// name the demo Organization. A Clinic that fails either test is never
			// touched, and `$slug` is only a sanity guard that the fixture really has
			// a Clinic identity.
			$wpdb->update(
				$table,
				array( 'status' => 'suspended' ),
				array( 'id' => $clinic_id, 'organization_id' => $org_id ),
				array( '%s' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * @param array<string,mixed> $scope Ownership scope.
	 * @return array<string,int>
	 */
	private function delete_global_rows( array $scope ): array {
		$deleted = array();
		$deleted['cpms_jobs'] = $this->delete_ids( 'cpms_jobs', (array) ( $scope['jobs'] ?? array() ) );
		$deleted['cpms_operational_logs'] = $this->delete_ids( 'cpms_operational_logs', (array) ( $scope['operational_logs'] ?? array() ) );
		$deleted['cpms_rate_limits'] = $this->delete_rate_keys( (array) ( $scope['rate_limit_keys'] ?? array() ) );
		$deleted['cpms_audit_logs'] = 0;
		return $deleted;
	}

	/**
	 * @param array<string,mixed> $scope Ownership scope.
	 * @return array<string,int>
	 */
	private function delete_table_rows( array $scope ): array {
		$tables  = (array) ( $scope['tables'] ?? array() );
		$deleted = array();
		$skip    = array_merge( self::GLOBAL_TABLES, self::PRESERVED_PARENT_TABLES );
		$order   = array_values( array_diff( Teardown::DELETE_ORDER, $skip ) );
		foreach ( $order as $short ) {
			if ( isset( self::COMPOSITE_KEY_TABLES[ $short ] ) ) {
				continue; // handled below, by verified composite key
			}
			$ids = (array) ( $tables[ $short ] ?? array() );
			$deleted[ $short ] = $this->delete_ids( $short, $ids );
		}
		foreach ( self::COMPOSITE_KEY_TABLES as $short => $key ) {
			$deleted[ $short ] = $this->delete_composite_rows(
				$short,
				(array) $key,
				(array) ( $scope['composite'][ $short ] ?? array() )
			);
		}
		$this->archive_parent_anchors( $tables );
		foreach ( self::PRESERVED_PARENT_TABLES as $short ) {
			$deleted[ $short . ' (preserved audit anchor)' ] = 0;
		}
		return $deleted;
	}

	/**
	 * Mark the synthetic parent rows inert without deleting audit FK targets.
	 *
	 * @param array<string,list<int>> $tables Owned table IDs.
	 * @return void
	 */
	private function archive_parent_anchors( array $tables ): void {
		global $wpdb;
		$org_ids     = (array) ( $tables['cpms_organizations'] ?? array() );
		$clinic_ids  = (array) ( $tables['cpms_clinics'] ?? array() );
		$location_ids = (array) ( $tables['cpms_locations'] ?? array() );
		foreach ( $org_ids as $id ) {
			$wpdb->update( $this->platform->table( 'cpms_organizations' ), array( 'status' => 'suspended' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		}
		foreach ( $clinic_ids as $id ) {
			$wpdb->update( $this->platform->table( 'cpms_clinics' ), array( 'status' => 'suspended' ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		}
		foreach ( $location_ids as $id ) {
			$wpdb->update( $this->platform->table( 'cpms_locations' ), array( 'is_active' => 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
		}
	}

	/**
	 * @param string $short Short table name.
	 * @param list<int> $ids Exact IDs.
	 * @return int
	 */
	private function delete_ids( string $short, array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids || ! $this->platform->table_exists( $short ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $this->platform->delete_where( $this->platform->table( $short ), 'id IN (' . $ph . ')', $ids );
	}

	/**
	 * Delete exactly the rows whose verified composite key was collected.
	 *
	 * Each row is addressed by an explicit ``col = value AND col = value``
	 * predicate built from the key columns that were proven owned. Row
	 * constructors are avoided deliberately: an equality predicate per proven
	 * tuple is the same set of rows, is portable across MySQL 8 and MariaDB, and
	 * cannot degrade into a partial match the way an unqualified `IN` could.
	 *
	 * @param string                         $short Short table name.
	 * @param list<string>                   $key   Verified key columns.
	 * @param array<string,list<int|string>> $keys  Collected key tuples, keyed by signature.
	 * @return int Rows deleted.
	 */
	private function delete_composite_rows( string $short, array $key, array $keys ): int {
		if ( array() === $keys || ! $this->platform->table_exists( $short ) ) {
			return 0;
		}
		$table   = $this->platform->table( $short );
		$deleted = 0;
		foreach ( $keys as $tuple ) {
			$clauses = array();
			$params  = array();
			foreach ( $key as $index => $column ) {
				$value     = $tuple[ $index ] ?? '';
				$clauses[] = '`' . $column . '` = ' . ( is_int( $value ) ? '%d' : '%s' );
				$params[]  = $value;
			}
			$deleted += $this->platform->delete_where( $table, implode( ' AND ', $clauses ), $params );
		}

		return $deleted;
	}

	/**
	 * @param list<string> $keys Exact fixture-owned rate-limit keys.
	 * @return int
	 */
	private function delete_rate_keys( array $keys ): int {
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
		$filter = static function ( string $key ): bool {
			return 0 !== strpos( $key, 'otp-ip:' ) && 0 !== strpos( $key, 'otp-verify-ip:' ) && 0 !== strpos( $key, 'login-ip:' );
		};
		$keys = array_values( array_filter( $keys, $filter ) );
		if ( array() === $keys || ! $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return $this->platform->delete_where( $this->platform->table( 'cpms_rate_limits' ), 'window_key IN (' . $ph . ')', $keys );
	}

	/**
	 * Refuse to delete a synthetic WordPress user while a FOREIGN identity link
	 * still depends on it.
	 *
	 * `cpms_patient_user_links` is Clinic-scoped and `cpms_patient_identity_links`
	 * is Organization-scoped. Either can hold a row that points at one of the
	 * demo's synthetic users while belonging to a real Clinic/Organization that
	 * pre-existed. Deleting the user would silently break that patient's access
	 * path and any OTP flow built on it, so the teardown fails closed instead.
	 *
	 * Demo-owned link rows are already removed by this point: the tenant columns
	 * (clinic_id / patient_id / organization_id / identity_id) prove ownership,
	 * and `wp_user_id` alone is deliberately NOT accepted as proof for these two
	 * tables (see TABLE_OWNERSHIP_ONLY).
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $user_id  Synthetic user under consideration.
	 * @return void
	 * @throws Exception When a foreign link depends on the user.
	 */
	private function assert_user_not_foreign_linked( array $manifest, int $user_id ): void {
		$ids       = (array) ( $manifest['ids'] ?? array() );
		$clinic_id = (int) ( $ids['clinic_id'] ?? 0 );
		$org_id    = (int) ( $ids['organization_id'] ?? 0 );

		$checks = array(
			'cpms_patient_user_links'     => array( 'tenant' => 'clinic_id', 'owned' => $clinic_id ),
			'cpms_patient_identity_links' => array( 'tenant' => 'organization_id', 'owned' => $org_id ),
		);
		foreach ( $checks as $short => $spec ) {
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$full = $this->platform->table( $short );
			$cols = $this->platform->columns( $full );
			if ( ! in_array( 'wp_user_id', $cols, true ) ) {
				continue;
			}
			$tenant = (string) $spec['tenant'];
			$owned  = (int) $spec['owned'];
			$rows   = in_array( $tenant, $cols, true )
				? $this->platform->fetch_all(
					'SELECT `' . $tenant . '` FROM `' . $full . '` WHERE `wp_user_id` = %d',
					array( $user_id )
				)
				: array();
			foreach ( $rows as $row ) {
				if ( (int) ( $row[ $tenant ] ?? 0 ) !== $owned ) {
					throw new Exception(
						'FOREIGN_IDENTITY_LINK',
						'پیوند هویت متعلق به درمانگاه/سازمان دیگری به کاربر ساختگی وابسته است؛ حذف کاربر متوقف شد.'
					);
				}
			}
		}
	}

	/**
	 * Refuse to delete a synthetic account that a foreign-tenant row references.
	 *
	 * Runs immediately before the WordPress user is deleted, when every fixture
	 * row has already been removed. Any surviving row that names this account -
	 * in ANY of the twenty user-reference columns, on ANY table - therefore
	 * belongs to something other than this fixture, and deleting the account
	 * would break a real tenant's clinical, financial or access history.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param int                 $user_id  Synthetic user under consideration.
	 * @return void
	 * @throws Exception When a foreign row references the user.
	 */
	private function assert_user_not_foreign_dependent( array $manifest, int $user_id, array $anchors = array() ): void {
		$ids   = (array) ( $manifest['ids'] ?? array() );
		$scope = array(
			'clinic_id' => (int) ( $ids['clinic_id'] ?? 0 ),
			'user_ids'  => array( (int) $user_id ),
			'anchors'   => $anchors,
		);
		$found = $this->foreign_user_dependencies( $scope, (int) $user_id, true );
		if ( array() === $found ) {
			return;
		}
		throw new Exception(
			'SCOPED_FOREIGN_DEPENDENCY',
			'رکوردهای بیرون از محدودهٔ دمو به این کاربر ساختگی وابسته‌اند؛ حذف کاربر متوقف شد: ' . implode( ', ', array_slice( $found, 0, 5 ) )
		);
	}

	/**
	 * @param array<string,mixed> $manifest Manifest.
	 * @param list<int> $user_ids Persona user IDs.
	 * @return array<string,int>
	 */
	private function delete_wp_records( array $manifest, array $user_ids ): array {
		$deleted = array( 'wp_users' => 0, 'wp_posts' => 0 );
		// Anchor identities are resolved ONCE, with the same rules the preview used,
		// and reused for every account: the preserved demo Clinic/Organization/
		// Locations are demo-owned even after their dependent rows are gone.
		$tenants = $this->owned_tenants( $manifest, $this->resolve_baseline( $manifest ) );
		$anchors = array(
			'cpms_organizations' => array( (int) ( $manifest['ids']['organization_id'] ?? 0 ) ),
			'cpms_clinics'       => $tenants['clinic_ids'],
			'cpms_locations'     => $tenants['location_ids'],
		);
		global $wpdb;
		$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		if ( $page_id > 0 ) {
			$page = $this->env->post( $page_id );
			if ( is_array( $page ) && 'page' === (string) ( $page['type'] ?? '' ) && (string) ( $page['slug'] ?? '' ) === (string) ( $manifest['slugs']['page'] ?? '' ) ) {
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $page_id ), array( '%d' ) );
				if ( $this->env->delete_post( $page_id ) ) {
					$deleted['wp_posts'] = 1;
				}
			}
		}
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$account = $this->env->user( $user_id );
			if ( ! is_array( $account ) ) {
				continue;
			}
			$login = (string) ( $account['login'] ?? '' );
			if ( 0 !== strpos( $login, 'cpsd-' ) || false === strpos( $login, (string) $manifest['fixture_id'] ) ) {
				throw new Exception( 'OWNERSHIP_UNPROVEN', 'یک حساب هدف با شناسه دمو همخوان نیست.' );
			}
			$this->assert_user_not_foreign_linked( $manifest, $user_id );
			$this->assert_user_not_foreign_dependent( $manifest, $user_id, $anchors );
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id ), array( '%d' ) );
			if ( $this->env->delete_user( $user_id ) ) {
				++$deleted['wp_users'];
			}
		}
		return $deleted;
	}

	/**
	 * @param array<string,mixed> $manifest Manifest.
	 * @return list<string>
	 */
	private function post_verify( array $manifest ): array {
		$remaining = array();
		$baseline  = $this->resolve_baseline( $manifest );
		$verify    = $this->baseline->verify( $baseline );
		if ( ! $verify['ok'] ) {
			$remaining = array_merge( $remaining, $verify['problems'] );
		}
		$chain = $this->audit_guard->verify_chain();
		if ( ! $chain['ok'] ) {
			$remaining[] = 'AUDIT_CHAIN_BROKEN';
		}
		$scope = $this->collect_scope( $manifest, $baseline );
		foreach ( $scope['tables'] as $short => $ids ) {
			if ( in_array( $short, self::PRESERVED_PARENT_TABLES, true ) ) {
				continue;
			}
			if ( array() !== $ids ) {
				$remaining[] = $short . ':' . count( $ids );
			}
		}
		foreach ( $scope['composite'] as $short => $keys ) {
			if ( array() !== $keys ) {
				$remaining[] = $short . ':' . count( $keys );
			}
		}
		if ( array() !== $scope['jobs'] || array() !== $scope['processing_jobs'] ) {
			$remaining[] = 'cpms_jobs';
		}
		if ( array() !== $scope['operational_logs'] ) {
			$remaining[] = 'cpms_operational_logs';
		}
		if ( array() !== $scope['rate_limit_keys'] ) {
			$remaining[] = 'cpms_rate_limits';
		}
		foreach ( $scope['user_ids'] as $user_id ) {
			if ( null !== $this->env->user( (int) $user_id ) ) {
				$remaining[] = 'wp_user:' . $user_id;
			}
		}
		$page_id = (int) ( $manifest['ids']['booking_page_id'] ?? 0 );
		if ( $page_id > 0 && null !== $this->env->post( $page_id ) ) {
			$remaining[] = 'wp_post:' . $page_id;
		}
		$remaining = array_merge( $remaining, $this->scan_untracked_fixture_rows( $manifest, $baseline, $scope, true ) );
		$remaining = array_merge( $remaining, $this->verify_preserved_anchors( $manifest, (array) ( $scope['anchors'] ?? array() ) ) );
		return array_values( array_unique( $remaining ) );
	}

	/**
	 * Prove the three retained parent rows are inert and that the host site's
	 * implicit-Clinic scope invariant still holds.
	 *
	 * The audit-log foreign key makes physical deletion impossible without
	 * deleting audit rows, so teardown must be able to prove the leftovers are
	 * unusable: Organization and Clinic `status` must be `suspended` (which is
	 * what every production lifecycle gate reads), locations must be inactive,
	 * and `COUNT(*)` on `cpms_clinics` must still be multi-Clinic so no
	 * `=== 1` implicit-scope decision changed.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return list<string>
	 */
	private function verify_preserved_anchors( array $manifest, array $anchors = array() ): array {
		global $wpdb;
		$problems   = array();
		$org_id     = (int) ( $manifest['ids']['organization_id'] ?? 0 );
		$clinic_id  = (int) ( $manifest['ids']['clinic_id'] ?? 0 );
		$location   = (array) ( $anchors['cpms_locations'] ?? (array) ( $manifest['ids']['location_ids'] ?? array() ) );
		$clinics    = (array) ( $anchors['cpms_clinics'] ?? array() );
		if ( $org_id > 0 ) {
			$table      = $this->platform->table( 'cpms_organizations' );
			$org_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $table . ' WHERE id = %d', $org_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CPMS table name from a fixed internal map; the value is prepared.
			if ( 'suspended' !== (string) $org_status ) {
				$problems[] = 'ANCHOR_ORGANIZATION_NOT_INERT';
			}
		}
		if ( $clinic_id > 0 && array() === $clinics ) {
			$clinics = array( $clinic_id );
		}
		foreach ( $clinics as $owned_clinic ) {
			$table         = $this->platform->table( 'cpms_clinics' );
			$clinic_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $table . ' WHERE id = %d', (int) $owned_clinic ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CPMS table name from a fixed internal map; the value is prepared.
			if ( null !== $clinic_status && 'suspended' !== (string) $clinic_status ) {
				$problems[] = 'ANCHOR_CLINIC_NOT_INERT:' . (int) $owned_clinic;
			}
		}
		foreach ( $location as $location_id ) {
			$table  = $this->platform->table( 'cpms_locations' );
			$active = $wpdb->get_var( $wpdb->prepare( 'SELECT is_active FROM ' . $table . ' WHERE id = %d', (int) $location_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- CPMS table name from a fixed internal map; the value is prepared.
			if ( null !== $active && 0 !== (int) $active ) {
				$problems[] = 'ANCHOR_LOCATION_STILL_ACTIVE';
			}
		}
		if ( $this->platform->count_all( 'cpms_clinics' ) < 2 ) {
			// The preflight refuses a one-Clinic host, so reaching this means the
			// host changed underneath us (rows deleted elsewhere). Report it rather
			// than silently accepting a changed implicit-scope install.
			$problems[] = 'HOST_SINGLE_CLINIC_SEMANTICS';
		}

		return $problems;
	}

	/**
	 * Find rows carrying the fixture marker that are not in the proven scope.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @param array<string,mixed> $baseline Baseline snapshot.
	 * @param array<string,mixed> $scope Ownership scope.
	 * @param bool $after_teardown Whether audit-only remnants are allowed.
	 * @return list<string>
	 */
	private function scan_untracked_fixture_rows( array $manifest, array $baseline, array $scope, bool $after_teardown ): array {
		$fixture   = (string) ( $manifest['fixture_id'] ?? '' );
		$problems  = array();
		if ( '' === $fixture ) {
			return array( 'FIXTURE_ID_MISSING' );
		}
		$baseline_max = array();
		foreach ( (array) ( $baseline['cpms'] ?? array() ) as $short => $info ) {
			$baseline_max[ $short ] = (int) ( $info['max_id'] ?? 0 );
		}
		$owned = array();
		foreach ( (array) ( $scope['tables'] ?? array() ) as $short => $ids ) {
			$owned[ $short ] = array_fill_keys( array_map( 'intval', (array) $ids ), true );
		}
		// Composite-key tables are tracked by their key fingerprint, not by an id.
		// Mixing the two key spaces made every proven composite row look untracked.
		foreach ( (array) ( $scope['composite'] ?? array() ) as $short => $keys ) {
			foreach ( (array) $keys as $fingerprint => $tuple ) {
				$owned[ $short ][ is_int( $fingerprint ) ? implode( '|', array_map( 'strval', (array) $tuple ) ) : (string) $fingerprint ] = true;
			}
		}
		foreach ( Preflight::KNOWN_TABLES as $short ) {
			if ( 'cpms_audit_logs' === $short && $after_teardown ) {
				continue;
			}
			if ( ! $this->platform->table_exists( $short ) ) {
				continue;
			}
			$full = $this->platform->table( $short );
			$cols = $this->platform->columns( $full );
			if ( array() !== ( self::COMPOSITE_KEY_TABLES[ $short ] ?? array() ) ) {
				// No surrogate id to bound by: scan the whole (small) table for the
				// fixture marker. Proven ownership is already excluded via $owned.
				$rows = $this->platform->fetch_all( 'SELECT * FROM `' . $full . '`' );
				foreach ( $rows as $row ) {
					$signature = array();
					foreach ( self::COMPOSITE_KEY_TABLES[ $short ] as $column ) {
						$signature[] = (string) ( $row[ $column ] ?? '' );
					}
					if ( isset( $owned[ $short ][ implode( '|', $signature ) ] ) ) {
						continue;
					}
					foreach ( $row as $column => $value ) {
						if ( is_string( $value ) && '' !== $fixture && false !== strpos( $value, $fixture ) ) {
							$problems[] = 'UNTRACKED_FIXTURE_ROW:' . $short;
							break 2;
						}
					}
				}
				continue;
			}
			if ( ! in_array( 'id', $cols, true ) ) {
				continue;
			}
			$max = (int) ( $baseline_max[ $short ] ?? 0 );
			$rows = $this->platform->fetch_all( 'SELECT * FROM `' . $full . '` WHERE id > %d ORDER BY id ASC', array( $max ) );
			foreach ( $rows as $row ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( isset( $owned[ $short ][ $id ] ) ) {
					continue;
				}
				if ( 'cpms_jobs' === $short && in_array( $id, (array) ( $scope['jobs'] ?? array() ), true ) ) {
					continue;
				}
				if ( 'cpms_operational_logs' === $short && in_array( $id, (array) ( $scope['operational_logs'] ?? array() ), true ) ) {
					continue;
				}
				if ( 'cpms_audit_logs' === $short && in_array( $id, (array) ( $scope['audit_logs'] ?? array() ), true ) ) {
					continue;
				}
				foreach ( $row as $column => $value ) {
					if ( is_string( $value ) && false !== strpos( $value, $fixture ) ) {
						$problems[] = 'UNTRACKED_FIXTURE_ROW:' . $short . ':' . $id;
						break;
					}
				}
			}
		}
		return $problems;
	}

	/**
	 * @param mixed $value Context value.
	 * @param string $fixture Fixture ID.
	 * @param array<int,bool> $entities Entity IDs.
	 * @param string $mobile Synthetic mobile.
	 * @param string $key Current key.
	 * @return bool
	 */
	private function context_matches( $value, string $fixture, array $entities, string $mobile, string $key = '' ): bool {
		$ignored = array( 'request_id', 'ip', 'ip_hash', 'created_at', 'timestamp', 'time', 'date' );
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child_value ) {
				if ( $this->context_matches( $child_value, $fixture, $entities, $mobile, (string) $child_key ) ) {
					return true;
				}
			}
			return false;
		}
		if ( in_array( strtolower( $key ), $ignored, true ) ) {
			return false;
		}
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			$string = (string) $value;
			if ( '' !== $fixture && false !== strpos( $string, $fixture ) ) {
				return true;
			}
			if ( '' !== $mobile && $string === $mobile ) {
				return true;
			}
			if ( ctype_digit( $string ) && isset( $entities[ (int) $string ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Closed allowlist of fixture-derived rate-limit keys, proven absent from baseline.
	 *
	 * @param string $fixture Fixture ID.
	 * @param string $mobile Synthetic mobile.
	 * @param list<int> $user_ids Persona user IDs.
	 * @param array<string,mixed> $manifest Manifest.
	 * @param array<string,mixed> $baseline Baseline.
	 * @return list<string>
	 */
	private function allowed_rate_limit_keys( string $fixture, string $mobile, array $user_ids, array $manifest, array $baseline ): array {
		$keys = array();
		if ( 1 === preg_match( '/^09\d{9}$/D', $mobile ) ) {
			$keys[] = 'otp-day:' . $mobile;
			$keys[] = 'otp-hour:' . $mobile;
		}
		foreach ( $user_ids as $user_id ) {
			$keys[] = 'booking-' . $user_id;
			$keys[] = 'files:upload:' . $user_id;
			$keys[] = 'rt_notif_' . $user_id;
			$keys[] = 'rt_queue_' . $user_id;
			$keys[] = 'rt_queue_portal_' . $user_id;
			$keys[] = 'sms-test-' . $user_id;
			$keys[] = 'sms-send-' . $user_id;
			$keys[] = 'sms-tmpl-' . $user_id;
		}
		foreach ( Credentials::PERSONA_ORDER as $persona ) {
			$login = $this->manifest->fixture_prefix() . $persona . '-' . $fixture;
			$hash  = substr( hash( 'sha256', strtolower( trim( $login ) ) ), 0, 32 );
			$keys[] = 'login-user:' . $hash;
		}
		if ( '' !== $fixture && $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			$rows = $this->platform->fetch_all( 'SELECT window_key FROM `' . $this->platform->table( 'cpms_rate_limits' ) . '` WHERE window_key LIKE %s', array( '%' . $fixture . '%' ) );
			foreach ( $rows as $row ) {
				$keys[] = (string) ( $row['window_key'] ?? '' );
			}
		}
		$keys = array_values( array_unique( array_filter( $keys ) ) );
		if ( array() !== $keys && $this->platform->table_exists( 'cpms_rate_limits' ) ) {
			$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$rows = $this->platform->fetch_all( 'SELECT window_key FROM `' . $this->platform->table( 'cpms_rate_limits' ) . '` WHERE window_key IN (' . $ph . ')', $keys );
			$live = array();
			foreach ( $rows as $row ) {
				$live[ (string) ( $row['window_key'] ?? '' ) ] = true;
			}
			$live_filter = static function ( string $key ) use ( $live ): bool {
				return isset( $live[ $key ] );
			};
			$keys = array_values( array_filter( $keys, $live_filter ) );
		}
		$baseline_keys = array_keys( (array) ( $baseline['cpms']['cpms_rate_limits']['keys'] ?? array() ) );
		$baseline_filter = static function ( string $key ) use ( $baseline_keys ): bool {
			if ( '' === $key || 0 === strpos( $key, 'otp-ip:' ) || 0 === strpos( $key, 'otp-verify-ip:' ) || 0 === strpos( $key, 'login-ip:' ) ) {
				return false;
			}
			foreach ( $baseline_keys as $baseline_key ) {
				if ( 0 === strpos( (string) $baseline_key, $key . '|' ) ) {
					return false;
				}
			}
			return true;
		};
		$keys = array_values( array_filter( $keys, $baseline_filter ) );
		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param list<string> $problems Problems.
	 * @return array{ok:bool,problems:list<string>,plan:list<array<string,mixed>>,scope:array<string,mixed>,baseline:array<string,mixed>,audit:array<string,mixed>}
	 */
	private function failed_preview( array $problems ): array {
		return array(
			'ok'       => false,
			'problems' => $problems,
			'plan'     => array(),
			'scope'    => array(),
			'baseline' => array(),
			'audit'    => array(),
		);
	}
}

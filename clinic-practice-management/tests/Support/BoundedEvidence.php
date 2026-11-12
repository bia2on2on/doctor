<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * Bounded projection of diagnostic evidence records for Integration runs.
 *
 * WHY THIS EXISTS
 *   Evidence records (CUSTOM_ROLE_*_EVIDENCE, HEARTBEAT_PERSISTENCE) are written
 *   to STDERR/STDOUT by the tests themselves. That output lands in the raw job
 *   log and in any workflow step that mirrors the log. This projector is the
 *   SOURCE-LEVEL publication boundary: everything it drops never leaves the test
 *   process at all.
 *
 * FAIL-CLOSED CONTRACT
 *   Fixed allow-listed keys only. Every dynamic value is either an int, a bool,
 *   an exact member of a fixed vocabulary taken from the product source, or a
 *   known table short name. Anything else — free text, SQL fragments,
 *   `lock_data`, fixture ids, exception messages, pipe/socket inodes — is
 *   DROPPED, never rewritten. There is no "message" field and no redaction
 *   pass. The workflow applies an independent second projection
 *   (bin/integration_evidence.py) before anything reaches a PR mirror.
 *
 *   Query fingerprints (SHA-256) are deliberately NOT published: hashing is not
 *   a privacy boundary and known-statement dictionaries can confirm a digest.
 *
 * Fixed vocabularies (must match bin/integration_evidence.py):
 *   table            — KNOWN_TABLES (from src/Migrations), exact short name or a
 *                      prefixed variant; anything else dropped.
 *   trx_state        — RUNNING | LOCK WAIT | ROLLING BACK | COMMITTING.
 *   lock_status      — WAITING | GRANTED (information_schema.innodb_locks).
 *   lock_mode        — fixed set of InnoDB lock_mode strings.
 *   definition_status — active | inactive | archived (CustomRoleRepository).
 *   outcome          — 'assigned' | 'denied:' + one of the exactly-16 CUSTOM_ROLE_*
 *                      reason codes raised by CustomRole{Assignment,Management}Exception
 *                      (the only codes reason_code() can return).
 *   archival case    — archive|assign|rename|capabilities _before_ counterpart
 *                      (the four races exercised).
 *   sql_mode         — every member must be a known MySQL 8 / MariaDB 11.4
 *                      sql_mode token, otherwise the whole field is dropped.
 */
final class BoundedEvidence
{
    private const DROP = '__DROP__';

    /** Tables created by the plugin migrations (short, unprefixed names). */
    private const KNOWN_TABLES = [
        'cpms_appointments', 'cpms_audit_logs', 'cpms_audit_seq',
        'cpms_clinic_memberships', 'cpms_clinical_note_versions',
        'cpms_clinical_notes', 'cpms_clinician_locations', 'cpms_clinicians',
        'cpms_clinics', 'cpms_custom_role_capabilities', 'cpms_custom_role_defs',
        'cpms_doctor_consultation_fees', 'cpms_drug_reference', 'cpms_follow_ups',
        'cpms_handwriting_documents', 'cpms_handwriting_page_versions',
        'cpms_handwriting_pages', 'cpms_idempotency_keys', 'cpms_invoice_items',
        'cpms_invoices', 'cpms_jobs', 'cpms_license_install', 'cpms_license_state',
        'cpms_locations', 'cpms_medical_attachments', 'cpms_membership_capabilities',
        'cpms_membership_locations', 'cpms_message_participants',
        'cpms_message_threads', 'cpms_messages', 'cpms_notifications',
        'cpms_ocr_jobs', 'cpms_operational_logs',
        'cpms_organization_membership_capabilities', 'cpms_organization_memberships',
        'cpms_organizations', 'cpms_otp_tokens', 'cpms_patient_identities',
        'cpms_patient_identity_links', 'cpms_patient_merges',
        'cpms_patient_user_links', 'cpms_patients',
        'cpms_payment_adjustments', 'cpms_payments', 'cpms_prescription_items',
        'cpms_prescriptions', 'cpms_rate_limits', 'cpms_recommendations',
        'cpms_schedule', 'cpms_schedule_exceptions', 'cpms_schedule_slots',
        'cpms_schema_migrations', 'cpms_services', 'cpms_settings',
        'cpms_slot_holds', 'cpms_sms_messages',
        'cpms_visit_charge_items', 'cpms_visit_consultation_fees',
        'cpms_visit_fee_snapshot_failures', 'cpms_visit_status_history',
        'cpms_visits',
    ];

    private const TRX_STATES = ['RUNNING', 'LOCK WAIT', 'ROLLING BACK', 'COMMITTING'];
    private const LOCK_STATUSES = ['WAITING', 'GRANTED'];
    private const LOCK_MODES = [
        'S', 'X', 'IS', 'IX',
        'S,GAP', 'X,GAP',
        'S,REC_NOT_GAP', 'X,REC_NOT_GAP',
        'S,INSERT_INTENTION', 'X,INSERT_INTENTION',
        'X,REC_NOT_GAP,INSERT_INTENTION',
        'AUTO_INC',
    ];
    private const DEFINITION_STATUSES = ['active', 'inactive', 'archived'];
    private const DENIAL_CODES = [
        'CUSTOM_ROLE_ASSIGNMENT_INVALID_ROLE_KEY',
        'CUSTOM_ROLE_ASSIGNMENT_TARGET_MEMBERSHIP_REQUIRED',
        'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_FOUND',
        'CUSTOM_ROLE_ASSIGNMENT_DEFINITION_NOT_ACTIVE',
        'CUSTOM_ROLE_ASSIGNMENT_CAPABILITY_NOT_DELEGABLE',
        'CUSTOM_ROLE_ASSIGNMENT_MEMBERSHIP_UPDATE_FAILED',
        'CUSTOM_ROLE_INVALID_ROLE_KEY',
        'CUSTOM_ROLE_INVALID_DISPLAY_NAME',
        'CUSTOM_ROLE_CAPABILITY_NOT_ALLOWED',
        'CUSTOM_ROLE_CAPABILITY_NOT_DELEGABLE',
        'CUSTOM_ROLE_NOT_FOUND',
        'CUSTOM_ROLE_KEY_CONFLICT',
        'CUSTOM_ROLE_STALE_VERSION',
        'CUSTOM_ROLE_TERMINAL_STATE',
        'CUSTOM_ROLE_INVALID_STATE',
        'CUSTOM_ROLE_MEMBERSHIP_REFERENCES',
    ];
    private const ARCHIVAL_CASES = [
        'archive_before_assign', 'assign_before_archive',
        'archive_before_rename', 'archive_before_capabilities',
    ];
    private const WORKER_MECHANISMS = ['proc_open-independent-process'];
    private const WORKER_BOOTSTRAPS = ['wp-config constants + wp-settings.php (no test installer)'];
    private const FD_KINDS = ['dev-null', 'pipe', 'socket', 'other'];
    private const INDEX_NAMES = ['PRIMARY'];
    private const SQL_MODES = [
        'REAL_AS_FLOAT', 'PIPES_AS_CONCAT', 'ANSI_QUOTES', 'IGNORE_SPACE',
        'ONLY_FULL_GROUP_BY', 'ANSI', 'NO_ZERO_IN_DATE', 'NO_ZERO_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO', 'TRADITIONAL', 'NO_AUTO_CREATE_USER',
        'NO_ENGINE_SUBSTITUTION', 'ORACLE', 'STRICT_TRANS_TABLES',
        'STRICT_ALL_TABLES', 'NO_DIR_IN_CREATE', 'NO_FIELD_OPTIONS',
        'NO_KEY_OPTIONS', 'NO_TABLE_OPTIONS', 'PAD_CHAR_TO_FULL_LENGTH',
        'NO_UNSIGNED_SUBTRACTION', 'POSTGRESQL', 'DB2', 'MAXDB', 'MSSQL',
        'NO_BACKSLASH_ESCAPES', 'HIGH_NOT_PRECEDENCE', 'ALLOW_INVALID_DATES',
        'TIME_TRUNCATE_FRACTIONAL', 'SIMULTANEOUS_ASSIGNMENT',
    ];
    private const PHP_TYPES = ['NULL', 'integer', 'string', 'boolean', 'double'];
    private const JSON_VALUE_TYPES = [
        'NULL', 'BOOLEAN', 'INTEGER', 'DOUBLE', 'DECIMAL', 'STRING',
        'DATETIME', 'OBJECT', 'ARRAY',
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function lifecycle(array $row): array
    {
        $out = [];
        self::kv($out, 'mysql_version', self::version($row['mysql_version'] ?? null));

        $parent = $row['parent'] ?? null;
        if (is_array($parent)) {
            $p = [];
            self::kv($p, 'connection_id', self::intv($parent['connection_id'] ?? null));
            self::kv($p, 'transaction_id', self::intv($parent['transaction_id'] ?? null));
            self::kv($p, 'definition_lock_id', self::intNull($parent['definition_lock_id'] ?? null));
            self::kv($p, 'lock_after_deactivation_before_commit', self::intNull($parent['lock_after_deactivation_before_commit'] ?? null));
            self::kv($p, 'transaction_state_while_child_waited', self::oneOf($parent['transaction_state_while_child_waited'] ?? null, self::TRX_STATES));
            self::kv($p, 'transaction_state_after_deactivation_before_commit', self::oneOf($parent['transaction_state_after_deactivation_before_commit'] ?? null, self::TRX_STATES));
            $out['parent'] = $p;
        }

        $child = $row['child'] ?? null;
        if (is_array($child)) {
            $c = [];
            self::kv($c, 'connection_id', self::intv($child['connection_id'] ?? null));
            self::kv($c, 'transaction_id', self::intNull($child['transaction_id'] ?? null));
            // assignment_lock_query_sha256 deliberately NOT published.
            self::kv($c, 'requesting_lock_id', self::intNull($child['requesting_lock_id'] ?? null));
            self::kv($c, 'transaction_state_while_waiting', self::oneOf($child['transaction_state_while_waiting'] ?? null, self::TRX_STATES));
            self::kv($c, 'transaction_state_after_deactivation_before_commit', self::oneOf($child['transaction_state_after_deactivation_before_commit'] ?? null, self::TRX_STATES));
            self::kv($c, 'statement_lock_timeout_errno', self::intNull($child['statement_lock_timeout_errno'] ?? null));
            $out['child'] = $c;
        }

        $source = $row['blocking_evidence_source'] ?? null;
        if ($source === 'innodb_view' || $source === 'statement_timeout') {
            $out['blocking_evidence_source'] = $source;
        }

        $edge = self::edge($row['wait_edge'] ?? null);
        if ($edge !== self::DROP) {
            $out['wait_edge'] = $edge;
        }

        $proof = self::proof($row['statement_block_proof'] ?? null);
        if ($proof !== self::DROP) {
            $out['statement_block_proof'] = $proof;
        }

        self::kv($out, 'same_wait_edge_after_deactivation_before_commit', self::boolv($row['same_wait_edge_after_deactivation_before_commit'] ?? null));
        self::kv($out, 'child_outcome_after_parent_commit', self::outcome($row['child_outcome_after_parent_commit'] ?? null));
        self::kv($out, 'definition_status_after_parent_commit', self::oneOf($row['definition_status_after_parent_commit'] ?? null, self::DEFINITION_STATUSES));
        self::kv($out, 'assignment_audit_count_after', self::intv($row['assignment_audit_count_after'] ?? null));
        self::kv($out, 'deactivation_audit_count_after', self::intv($row['deactivation_audit_count_after'] ?? null));

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function archival(array $row): array
    {
        $out = [];
        self::kv($out, 'case', self::oneOf($row['case'] ?? null, self::ARCHIVAL_CASES));
        self::kv($out, 'worker_mechanism', self::oneOf($row['worker_mechanism'] ?? null, self::WORKER_MECHANISMS));
        self::kv($out, 'worker_bootstrap', self::oneOf($row['worker_bootstrap'] ?? null, self::WORKER_BOOTSTRAPS));

        $probe = $row['startup_fixture_probe'] ?? null;
        if (is_array($probe)) {
            $pp = [];
            foreach (['wp_user_rows', 'membership_rows', 'definition_rows'] as $key) {
                self::kv($pp, $key, self::intv($probe[$key] ?? null));
            }
            $out['startup_fixture_probe'] = $pp;
        }

        self::kv($out, 'worker_pid', self::intv($row['worker_pid'] ?? null));
        self::kv($out, 'worker_exit_code', self::intv($row['worker_exit_code'] ?? null));
        self::kv($out, 'parent_connection_id', self::intv($row['parent_connection_id'] ?? null));
        self::kv($out, 'child_connection_id', self::intv($row['child_connection_id'] ?? null));
        self::kv($out, 'parent_connection_id_live_on_own_session', self::boolv($row['parent_connection_id_live_on_own_session'] ?? null));
        self::kv($out, 'parent_transaction_visible_on_own_session', self::boolv($row['parent_transaction_visible_on_own_session'] ?? null));
        self::kv($out, 'parent_transaction_state', self::oneOf($row['parent_transaction_state'] ?? null, self::TRX_STATES));
        self::kv($out, 'child_transaction_state', self::oneOf($row['child_transaction_state'] ?? null, self::TRX_STATES));
        // waiter_lock_query_sha256 deliberately NOT published.

        $edge = self::edge($row['wait_edge'] ?? null);
        if ($edge !== self::DROP) {
            $out['wait_edge'] = $edge;
        }

        self::kv($out, 'same_wait_edge_through_mutation_and_audit_before_commit', self::boolv($row['same_wait_edge_through_mutation_and_audit_before_commit'] ?? null));
        self::kv($out, 'child_outcome_after_parent_commit', self::outcome($row['child_outcome_after_parent_commit'] ?? null));
        self::kv($out, 'definition_status_after_commit', self::oneOf($row['definition_status_after_commit'] ?? null, self::DEFINITION_STATUSES));
        self::kv($out, 'definition_version_after_commit', self::intv($row['definition_version_after_commit'] ?? null));
        self::kv($out, 'archival_audit_count', self::intv($row['archival_audit_count'] ?? null));
        self::kv($out, 'assignment_audit_count', self::intv($row['assignment_audit_count'] ?? null));

        $isolation = $row['descriptor_isolation'] ?? null;
        if (is_array($isolation)) {
            $di = [];
            self::kv($di, 'proc_fd', self::boolv($isolation['proc_fd'] ?? null));
            self::kv($di, 'pinned_fd_count', self::intv($isolation['pinned_fd_count'] ?? null));
            $pinned = $isolation['pinned_fds'] ?? null;
            if (is_array($pinned)) {
                $fds = [];
                foreach (array_slice($pinned, 0, 32) as $fd) {
                    $value = self::smallInt($fd);
                    if ($value !== self::DROP && $value !== null) {
                        $fds[] = $value;
                    }
                }
                $di['pinned_fds'] = $fds;
            }
            self::kv($di, 'pinned_isolation_proven', self::boolv($isolation['pinned_isolation_proven'] ?? null));
            $fdsBeyond = $isolation['worker_fds_beyond_stdio'] ?? null;
            if (is_array($fdsBeyond)) {
                $fdOut = [];
                foreach (array_slice($fdsBeyond, 0, 32, true) as $fdKey => $fdValue) {
                    // Keys may be ints (JSON round-trip) or numeric strings;
                    // both reduce to the same bounded 1–3 digit fd literal.
                    $fdKey = (string) $fdKey;
                    if (preg_match('/\A[0-9]{1,3}\z/D', $fdKey) !== 1) {
                        continue;
                    }
                    $kind = self::fdKind($fdValue);
                    if ($kind === self::DROP) {
                        continue;
                    }
                    $fdOut[$fdKey] = $kind;
                }
                $di['worker_fds_beyond_stdio'] = $fdOut;
            }
            self::kv($di, 'worker_socket_count', self::intNull($isolation['worker_socket_count'] ?? null));
            self::kv($di, 'parent_observed_fd_count', self::intv($isolation['parent_observed_fd_count'] ?? null));
            $out['descriptor_isolation'] = $di;
        }

        self::kv($out, 'environment_allowlist_verified', self::boolv($row['environment_allowlist_verified'] ?? null));
        self::kv($out, 'privacy_marker_absent', self::boolv($row['privacy_marker_absent'] ?? null));

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function heartbeat(array $row): array
    {
        $out = [];
        self::kv($out, 'sql_mode', self::sqlMode($row['sql_mode'] ?? null));
        foreach (['insert_writer', 'update_writer'] as $key) {
            $value = $row[$key] ?? null;
            if ($value === null) {
                $out[$key] = null;
                continue;
            }
            $int = self::intv($value);
            if ($int !== self::DROP) {
                $out[$key] = $int;
            }
        }
        foreach (['insert_php_type', 'update_php_type'] as $key) {
            self::kv($out, $key, self::oneOf($row[$key] ?? null, self::PHP_TYPES));
        }
        self::kv($out, 'json_type', self::oneOf($row['json_type'] ?? null, self::JSON_VALUE_TYPES));

        return $out;
    }

    /**
     * @param mixed $edge
     * @return array<string, mixed>|null|string DROP-constant when invalid
     */
    private static function edge($edge)
    {
        if ($edge === null) {
            return null;
        }
        if (!is_array($edge)) {
            return self::DROP;
        }
        $out = [];
        self::kv($out, 'requesting_connection_id', self::intv($edge['requesting_connection_id'] ?? null));
        self::kv($out, 'blocking_connection_id', self::intv($edge['blocking_connection_id'] ?? null));
        self::kv($out, 'requesting_lock_id', self::intNull($edge['requesting_lock_id'] ?? null));
        self::kv($out, 'blocking_lock_id', self::intNull($edge['blocking_lock_id'] ?? null));
        self::kv($out, 'requesting_lock_mode', self::oneOf($edge['requesting_lock_mode'] ?? null, self::LOCK_MODES));
        self::kv($out, 'blocking_lock_mode', self::oneOf($edge['blocking_lock_mode'] ?? null, self::LOCK_MODES));
        self::kv($out, 'requesting_lock_status', self::oneOf($edge['requesting_lock_status'] ?? null, self::LOCK_STATUSES));
        self::kv($out, 'blocking_lock_status', self::oneOf($edge['blocking_lock_status'] ?? null, self::LOCK_STATUSES));
        self::kv($out, 'table', self::tableName($edge['table'] ?? null));
        self::kv($out, 'index', self::oneOf($edge['index'] ?? null, self::INDEX_NAMES));

        return $out;
    }

    /**
     * @param mixed $proof
     * @return array<string, mixed>|null|string DROP-constant when invalid
     */
    private static function proof($proof)
    {
        if ($proof === null) {
            return null;
        }
        if (!is_array($proof)) {
            return self::DROP;
        }
        $out = [];
        self::kv($out, 'errno', self::intv($proof['errno'] ?? null));
        // statement_sha256 deliberately NOT published (hashing is not a boundary).

        return $out;
    }

    /**
     * @param array<string, mixed> $out
     * @param mixed $value
     */
    private static function kv(array &$out, string $key, $value): void
    {
        if ($value === self::DROP) {
            return;
        }
        $out[$key] = $value;
    }

    /**
     * @param mixed $value
     * @return int|string DROP-constant
     */
    private static function intv($value)
    {
        if (is_bool($value) || $value === null) {
            return self::DROP;
        }
        if (is_int($value)) {
            return (abs($value) <= 10 ** 15) ? $value : self::DROP;
        }
        if (is_string($value) && preg_match('/\A-?[0-9]{1,15}\z/D', $value) === 1) {
            return (int) $value;
        }

        return self::DROP;
    }

    /**
     * Small int for file descriptors (0..65535).
     *
     * @param mixed $value
     * @return int|null|string DROP-constant
     */
    private static function smallInt($value)
    {
        $int = self::intv($value);
        if ($int === self::DROP) {
            return self::DROP;
        }

        return ($int >= 0 && $int <= 65535) ? $int : self::DROP;
    }

    /**
     * Nullable int: explicit null stays null, invalid drops the key.
     *
     * @param mixed $value
     * @return int|null|string DROP-constant
     */
    private static function intNull($value)
    {
        if ($value === null) {
            return null;
        }

        return self::intv($value);
    }

    /**
     * @param mixed $value
     * @return bool|string DROP-constant
     */
    private static function boolv($value)
    {
        return is_bool($value) ? $value : self::DROP;
    }

    /**
     * Exact member of a fixed vocabulary. Never a pattern.
     *
     * @param mixed $value
     * @param list<string> $vocabulary
     * @return string DROP-constant
     */
    private static function oneOf($value, array $vocabulary): string
    {
        if (!is_string($value) || !in_array($value, $vocabulary, true)) {
            return self::DROP;
        }

        return $value;
    }

    /**
     * Server version: dotted bounded integers <1-3 digits>.<1-3 digits>.<1-3 digits>
     * and NOTHING else. A suffix can never be validated as more than a character
     * pattern, so it is omitted fail-closed (MariaDB build metadata, distro
     * suffixes and any valid-shaped canary call-signs all drop out).
     *
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function version($value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\z/D', $value) !== 1) {
            return self::DROP;
        }

        return $value;
    }

    /**
     * sql_mode: every member must be a known MySQL 8 / MariaDB 11.4 token;
     * otherwise the WHOLE field is dropped.
     *
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function sqlMode($value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 200) {
            return self::DROP;
        }
        foreach (explode(',', $value) as $member) {
            if (!in_array($member, self::SQL_MODES, true)) {
                return self::DROP;
            }
        }

        return $value;
    }

    /**
     * Race outcome: 'assigned' or 'denied:<CUSTOM_ROLE_* code from source>'.
     *
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function outcome($value): string
    {
        if (!is_string($value)) {
            return self::DROP;
        }
        if ($value === 'assigned') {
            return $value;
        }
        if (strncmp($value, 'denied:', 7) !== 0) {
            return self::DROP;
        }
        $code = substr($value, 7);
        if (!in_array($code, self::DENIAL_CODES, true)) {
            return self::DROP;
        }

        return $value;
    }

    /**
     * File descriptor targets as fixed categories. pipe:[inode] → 'pipe',
     * socket:[inode] → 'socket'; inodes themselves are never published.
     *
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function fdKind($value): string
    {
        if (!is_string($value)) {
            return self::DROP;
        }
        if (in_array($value, self::FD_KINDS, true)) {
            return $value;
        }
        if (preg_match('/\Apipe:\[[0-9]{1,20}\]\z/D', $value) === 1) {
            return 'pipe';
        }
        if (preg_match('/\Asocket:\[[0-9]{1,20}\]\z/D', $value) === 1) {
            return 'socket';
        }

        return self::DROP;
    }

    /**
     * Known table short name (optionally with a prefix ending '_'); anything
     * else is dropped. Never a free identifier.
     *
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function tableName($value): string
    {
        if (!is_string($value)) {
            return self::DROP;
        }
        $token = trim($value);
        if ($token === '' || strlen($token) > 120) {
            return self::DROP;
        }
        if ($token !== strtolower($token)) {
            // Fixed vocabulary is lowercase; anything else is not a known table.
            return self::DROP;
        }
        foreach (self::KNOWN_TABLES as $short) {
            if ($token === $short || (strlen($token) > strlen($short) + 1 && substr($token, -strlen($short) - 1) === '_' . $short)) {
                return $short;
            }
        }

        return self::DROP;
    }
}

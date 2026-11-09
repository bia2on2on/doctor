<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Support;

/**
 * Bounded projection of diagnostic evidence records for Integration runs.
 *
 * WHY THIS EXISTS
 *   Evidence records (CUSTOM_ROLE_*_EVIDENCE, HEARTBEAT_PERSISTENCE) are written
 *   to STDERR/STDOUT by the tests themselves. That output lands in the raw job
 *   log, in tee'd files and in any workflow step that mirrors the log. This
 *   projector is therefore the SOURCE-LEVEL publication boundary: everything it
 *   drops never reaches a log line at all.
 *
 * FAIL-CLOSED CONTRACT
 *   Fixed allow-listed keys only. Values must be ints, bools, nulls or tokens
 *   matching anchored ASCII patterns. Anything else — including free text,
 *   SQL fragments, `lock_data`, fixture ids and exception messages — is
 *   DROPPED, never rewritten. There is no message field and no redaction
 *   pass. The workflow applies an independent second projection
 *   (bin/integration_evidence.py) before anything reaches a PR mirror.
 *
 * Deliberately NOT published: raw SQL (assignment_lock_query / waiter_lock_query),
 * performance_schema lock_data, schema/database names, ids of fixture rows,
 * WP-global role lists, role keys of fixture memberships, env var NAMES,
 * query fingerprints are kept (SHA-256 only).
 */
final class BoundedEvidence
{
    private const DROP = '__DROP__';

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function lifecycle(array $row): array
    {
        $out = [];
        self::kv($out, 'mysql_version', self::reToken($row['mysql_version'] ?? null, '/\A[0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.-]{0,32}\z/D', 64));

        $parent = $row['parent'] ?? null;
        if (is_array($parent)) {
            $p = [];
            self::kv($p, 'connection_id', self::intv($parent['connection_id'] ?? null));
            self::kv($p, 'transaction_id', self::intv($parent['transaction_id'] ?? null));
            self::kv($p, 'definition_lock_id', self::intNull($parent['definition_lock_id'] ?? null));
            self::kv($p, 'lock_after_deactivation_before_commit', self::intNull($parent['lock_after_deactivation_before_commit'] ?? null));
            self::kv($p, 'transaction_state_while_child_waited', self::trx($parent['transaction_state_while_child_waited'] ?? null));
            self::kv($p, 'transaction_state_after_deactivation_before_commit', self::trx($parent['transaction_state_after_deactivation_before_commit'] ?? null));
            $out['parent'] = $p;
        }

        $child = $row['child'] ?? null;
        if (is_array($child)) {
            $c = [];
            self::kv($c, 'connection_id', self::intv($child['connection_id'] ?? null));
            self::kv($c, 'transaction_id', self::intNull($child['transaction_id'] ?? null));
            self::kv($c, 'assignment_lock_query_sha256', self::hex64($child['assignment_lock_query_sha256'] ?? null));
            self::kv($c, 'requesting_lock_id', self::intNull($child['requesting_lock_id'] ?? null));
            self::kv($c, 'transaction_state_while_waiting', self::trx($child['transaction_state_while_waiting'] ?? null));
            self::kv($c, 'transaction_state_after_deactivation_before_commit', self::trx($child['transaction_state_after_deactivation_before_commit'] ?? null));
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
        self::kv($out, 'child_outcome_after_parent_commit', self::denied($row['child_outcome_after_parent_commit'] ?? null));
        self::kv($out, 'definition_status_after_parent_commit', self::enumWord($row['definition_status_after_parent_commit'] ?? null));
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
        self::kv($out, 'case', self::reToken($row['case'] ?? null, '/\A[a-z_]{1,64}\z/D', 64));
        self::kv($out, 'worker_mechanism', self::reToken($row['worker_mechanism'] ?? null, '/\A[a-z0-9 .+()\/_-]{1,120}\z/D', 120));
        self::kv($out, 'worker_bootstrap', self::reToken($row['worker_bootstrap'] ?? null, '/\A[a-z0-9 .+()\/_-]{1,120}\z/D', 120));

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
        self::kv($out, 'parent_transaction_state', self::trx($row['parent_transaction_state'] ?? null));
        self::kv($out, 'child_transaction_state', self::trx($row['child_transaction_state'] ?? null));
        self::kv($out, 'waiter_lock_query_sha256', self::hex64($row['waiter_lock_query_sha256'] ?? null));

        $edge = self::edge($row['wait_edge'] ?? null);
        if ($edge !== self::DROP) {
            $out['wait_edge'] = $edge;
        }

        self::kv($out, 'same_wait_edge_through_mutation_and_audit_before_commit', self::boolv($row['same_wait_edge_through_mutation_and_audit_before_commit'] ?? null));
        self::kv($out, 'child_outcome_after_parent_commit', self::denied($row['child_outcome_after_parent_commit'] ?? null));
        self::kv($out, 'definition_status_after_commit', self::enumWord($row['definition_status_after_commit'] ?? null));
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
                    $value = self::intv($fd);
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
                    if (!is_string($fdKey) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $fdKey) !== 1) {
                        continue;
                    }
                    $normalized = self::reToken($fdValue, '/\A(dev-null|pipe:\[[0-9]{1,20}\]|socket:\[[0-9]{1,20}\]|other)\z/D', 32);
                    if ($normalized === self::DROP) {
                        continue;
                    }
                    $fdOut[$fdKey] = $normalized;
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
        self::kv($out, 'sql_mode', self::reToken($row['sql_mode'] ?? null, '/\A[A-Z][A-Z0-9_]{1,40}(,[A-Z][A-Z0-9_]{1,40})*\z/D', 200));
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
            self::kv($out, $key, self::reToken($row[$key] ?? null, '/\A(NULL|integer|string|boolean|double)\z/D', 8));
        }
        self::kv($out, 'json_type', self::reToken($row['json_type'] ?? null, '/\A[A-Z]{1,16}\z/D', 16));

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
        self::kv($out, 'requesting_lock_mode', self::reToken($edge['requesting_lock_mode'] ?? null, '/\A[A-Z]{1,4}(,[A-Z0-9_]{1,24}){0,3}\z/D', 110));
        self::kv($out, 'blocking_lock_mode', self::reToken($edge['blocking_lock_mode'] ?? null, '/\A[A-Z]{1,4}(,[A-Z0-9_]{1,24}){0,3}\z/D', 110));
        self::kv($out, 'requesting_lock_status', self::reToken($edge['requesting_lock_status'] ?? null, '/\A[A-Z]{1,10}\z/D', 10));
        self::kv($out, 'blocking_lock_status', self::reToken($edge['blocking_lock_status'] ?? null, '/\A[A-Z]{1,10}\z/D', 10));
        self::kv($out, 'table', self::tableName($edge['table'] ?? null));
        self::kv($out, 'index', self::reToken($edge['index'] ?? null, '/\A[A-Za-z0-9_]{1,32}\z/D', 32));

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
        self::kv($out, 'statement_sha256', self::hex64($proof['statement_sha256'] ?? null));

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
     * Strict int (or digit string) or self::DROP.
     *
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
     * Strict bool or self::DROP.
     *
     * @param mixed $value
     * @return bool|string DROP-constant
     */
    private static function boolv($value)
    {
        return is_bool($value) ? $value : self::DROP;
    }

    /**
     * Anchored ASCII token or self::DROP.
     *
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function reToken($value, string $pattern, int $maxLen): string
    {
        if (!is_string($value) || strlen($value) > $maxLen || preg_match('/\A[\x20-\x7E]*\z/D', $value) !== 1) {
            return self::DROP;
        }

        return preg_match($pattern, $value) === 1 ? $value : self::DROP;
    }

    /**
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function hex64($value): string
    {
        return self::reToken($value, '/\A[a-f0-9]{64}\z/D', 64);
    }

    /**
     * InnoDB trx_state: fixed uppercase vocabulary or null.
     *
     * @param mixed $value
     * @return string|null DROP-constant
     */
    private static function trx($value)
    {
        if ($value === null) {
            return null;
        }

        return self::reToken($value, '/\A[A-Z ]{1,30}\z/D', 30);
    }

    /**
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function denied($value): string
    {
        return self::reToken($value, '/\Adenied:[A-Za-z0-9_]{1,64}\z/D', 71);
    }

    /**
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function enumWord($value): string
    {
        return self::reToken($value, '/\A[a-z_]{1,32}\z/D', 32);
    }

    /**
     * @param mixed $value
     * @return string DROP-constant
     */
    private static function tableName($value): string
    {
        $token = self::reToken($value, '/\A[A-Za-z0-9_]{1,80}\z/D', 80);
        if ($token === self::DROP) {
            return self::DROP;
        }
        $lower = strtolower($token);
        foreach (['cpms_custom_role_defs', 'cpms_custom_role_capabilities', 'cpms_clinics'] as $short) {
            if ($lower === $short || (strlen($lower) > strlen($short) && substr($lower, -strlen('_' . $short)) === '_' . $short)) {
                return $short;
            }
        }

        return self::DROP;
    }
}

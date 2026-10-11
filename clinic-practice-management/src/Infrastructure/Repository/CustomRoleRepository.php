<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Infrastructure\Db\CpmsDb;

// Repository — Clinic-local custom-role definitions (Slice 1; Product Decision
// record: docs/decisions/2026-10-10-custom-role-permission-management-model.md).
//
// Tables (Migration 2026_10_10_0024):
//  - cpms_custom_role_defs: UNIQUE(clinic_id, role_key), status active/inactive,
//    monotonic `version` (forward-only: updates only advance the version).
//  - cpms_custom_role_capabilities: UNIQUE(role_def_id, capability).
//
// Boundary rules (security contract):
//  - EVERY write path validates role keys and capabilities through
//    CustomRolePolicy and rejects the whole write on any violation (all-or-
//    nothing). Future writers must go through these methods; raw SQL bypass is
//    additionally neutralized by the read path.
//  - The authorization read path (active_capabilities_for) re-filters stored
//    rows through CustomRolePolicy: a definition row that is missing, inactive,
//    malformed, foreign-Clinic, or contains a non-allowlisted capability can
//    never supply that capability — fail closed, no first-row-wins, no
//    cross-Clinic fallback.
//  - This repository stores and resolves NOTHING by itself: permission
//    precedence stays exclusively in AuthorizationService.
final class CustomRoleRepository {

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * Effective capability set of an ACTIVE custom-role definition for the exact
     * Clinic. Fail closed: [] for missing/inactive/malformed/built-in-collision
     * keys, invalid Clinic, or any stored capability outside the approved set.
     *
     * @return list<string>
     */
    public function active_capabilities_for( int $clinic_id, string $role_key ): array {
        if ( $clinic_id <= 0 ) {
            return [];
        }
        // Malformed keys and built-in-key collisions never resolve here.
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            return [];
        }

        $def = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            " WHERE clinic_id = %d AND role_key = %s AND status = 'active' LIMIT 1",
            [ $clinic_id, $role_key ]
        );
        if ( $def === null ) {
            return [];
        }
        $def_id = (int) ( $def['id'] ?? 0 );
        if ( $def_id <= 0 ) {
            return [];
        }

        // Defense in depth: even a raw-SQL writer cannot make a non-allowlisted
        // capability operational.
        return CustomRolePolicy::sanitize_capability_set( $this->stored_capabilities( $def_id ) );
    }

    /**
     * Clinic-scoped list of definitions for a management surface. Fail closed:
     * an invalid Clinic yields [], and a stored row that cannot be a definable
     * custom-role key is omitted rather than displayed as manageable. Stored
     * capabilities are re-filtered through CustomRolePolicy (a raw-SQL writer
     * can never surface a sensitive/unknown capability as granted).
     *
     * @return list<array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, updated_at:string, capabilities:list<string>}>
     */
    public function list_definitions( int $clinic_id ): array {
        if ( $clinic_id <= 0 ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, role_key, display_name, status, version, updated_at FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE clinic_id = %d ORDER BY role_key ASC',
            [ $clinic_id ]
        );

        $definitions = [];
        foreach ( ( is_array( $rows ) ? $rows : [] ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id  = (int) ( $row['id'] ?? 0 );
            $key = (string) ( $row['role_key'] ?? '' );
            if ( $id <= 0 || ! CustomRolePolicy::is_definable_role_key( $key ) ) {
                continue;
            }
            $status        = (string) ( $row['status'] ?? '' );
            $definitions[] = [
                'clinic_id'    => $clinic_id,
                'id'           => $id,
                'role_key'     => $key,
                'display_name' => self::normalize_stored_display_name( $row['display_name'] ?? null ),
                'status'       => self::STATUS_INACTIVE === $status ? self::STATUS_INACTIVE : self::STATUS_ACTIVE,
                'version'      => (int) ( $row['version'] ?? 0 ),
                'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
                'capabilities' => CustomRolePolicy::sanitize_capability_set( $this->stored_capabilities( $id ) ),
            ];
        }

        return $definitions;
    }

    /**
     * Durable, Clinic-scoped definition record — the object-ownership source for
     * management writes. Returns null when the (clinic_id, role_key) pair does
     * not exist, so a key that lives in another Clinic can never be addressed
     * through this Clinic (no cross-Clinic fallback, no first-row selection).
     *
     * @return array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, capabilities:list<string>}|null
     */
    public function definition_for( int $clinic_id, string $role_key ): ?array {
        if ( $clinic_id <= 0 || ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            return null;
        }

        $row = $this->db->fetchRow(
            'SELECT id, clinic_id, role_key, display_name, status, version FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        if ( $row === null ) {
            return null;
        }

        $id           = (int) ( $row['id'] ?? 0 );
        $owner_clinic = (int) ( $row['clinic_id'] ?? 0 );
        if ( $id <= 0 || $owner_clinic !== $clinic_id ) {
            // Defensive: the SELECT is already Clinic-scoped; a mismatching
            // durable owner is treated as "not addressable here" (fail closed).
            return null;
        }
        $status = (string) ( $row['status'] ?? '' );

        return [
            'clinic_id'    => $owner_clinic,
            'id'           => $id,
            'role_key'     => (string) ( $row['role_key'] ?? '' ),
            'display_name' => self::normalize_stored_display_name( $row['display_name'] ?? null ),
            'status'       => self::STATUS_INACTIVE === $status ? self::STATUS_INACTIVE : self::STATUS_ACTIVE,
            'version'      => (int) ( $row['version'] ?? 0 ),
            'capabilities' => CustomRolePolicy::sanitize_capability_set( $this->stored_capabilities( $id ) ),
        ];
    }

    /**
     * Set/refresh the human-facing display name of an existing definition.
     *
     * The technical `role_key` and the capability set are untouched: a rename
     * must never change identity or permissions. Versioned, forward-only, atomic
     * (a rejected name leaves the stored value and version unchanged).
     *
     * @throws \InvalidArgumentException invalid Clinic, role key, or display name
     *
     * @return bool false when no such definition exists
     */
    public function set_display_name( int $clinic_id, string $role_key, string $display_name ): bool {
        $def_id = $this->require_definition( $clinic_id, $role_key );
        if ( $def_id <= 0 ) {
            return false;
        }
        $clean = self::require_display_name( $display_name );

        $now = $this->db->nowUtcSql();
        $this->db->transactional(
            function () use ( $def_id, $clean, $now ): void {
                $updated = $this->db->execute(
                    'UPDATE ' . $this->db->table( 'cpms_custom_role_defs' ) .
                    ' SET display_name = %s, version = version + 1, updated_at = %s WHERE id = %d',
                    [ $clean, $now, $def_id ]
                );
                if ( $updated !== 1 ) {
                    throw new \RuntimeException( 'custom role display name update failed for definition: ' . $def_id );
                }
            }
        );

        return true;
    }

    /**
     * Define a new Clinic-local custom role (define-once: the key is immutable
     * and a second define() on the same (clinic, key) fails loudly).
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws \InvalidArgumentException invalid Clinic, role key, capability set, or display name
     * @throws \RuntimeException definition already exists
     *
     * @return int new definition id
     */
    public function define( int $clinic_id, string $role_key, array $capabilities, ?string $display_name = null ): int {
        if ( $clinic_id <= 0 ) {
            throw new \InvalidArgumentException( 'clinic_id must be > 0 for a custom role definition' );
        }
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw new \InvalidArgumentException( 'role key is not definable (format or built-in collision): ' . $role_key );
        }
        $clean              = self::require_editable_capabilities( $capabilities );
        $clean_display_name = self::optional_display_name( $display_name );

        $existing = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        if ( $existing !== null ) {
            throw new \RuntimeException( 'custom role key already defined for this clinic: ' . $role_key );
        }

        $now = $this->db->nowUtcSql();
        $this->db->transactional(
            function () use ( $clinic_id, $role_key, $clean, $clean_display_name, $now ): void {
                $ok = $this->db->insert(
                    'cpms_custom_role_defs',
                    [
                        'clinic_id'    => $clinic_id,
                        'role_key'     => $role_key,
                        'display_name' => $clean_display_name,
                        'status'       => self::STATUS_ACTIVE,
                        'version'      => 1,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]
                );
                if ( ! $ok ) {
                    // UNIQUE backstop (race): define-once semantics.
                    throw new \RuntimeException( 'custom role key already defined for this clinic: ' . $role_key );
                }
                $new_def_id = (int) $this->db->wpdb_last_insert_id();
                if ( $new_def_id <= 0 ) {
                    throw new \RuntimeException( 'custom role definition insert produced no row id: ' . $role_key );
                }
                $this->insert_capability_rows( $new_def_id, $clean, $now );
            }
        );

        $id = (int) $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        if ( $id <= 0 ) {
            throw new \RuntimeException( 'custom role definition insert failed for key: ' . $role_key );
        }

        return $id;
    }

    /**
     * Replace the capability set of an existing definition. Versioned and
     * forward-only: the durable `version` only ever advances. Validated exactly
     * like define() — a rejected set leaves the stored definition untouched.
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws \InvalidArgumentException invalid Clinic, role key, or capability set
     *
     * @return bool false when no such definition exists
     */
    public function replace_capabilities( int $clinic_id, string $role_key, array $capabilities ): bool {
        $def_id = $this->require_definition( $clinic_id, $role_key );
        if ( $def_id <= 0 ) {
            return false;
        }
        $clean = self::require_editable_capabilities( $capabilities );

        $now = $this->db->nowUtcSql();
        $this->db->transactional(
            function () use ( $def_id, $clean, $now ): void {
                // Every write is checked; a failure throws inside the
                // transaction so the previous capability set and version are
                // preserved and a partial replacement can never persist.
                $cleared = $this->db->query(
                    'DELETE FROM ' . $this->db->table( 'cpms_custom_role_capabilities' ) .
                    ' WHERE role_def_id = %d',
                    [ $def_id ]
                );
                if ( ! $cleared ) {
                    throw new \RuntimeException( 'custom role capability reset failed for definition: ' . $def_id );
                }
                $this->insert_capability_rows( $def_id, $clean, $now );
                $advanced = $this->db->execute(
                    'UPDATE ' . $this->db->table( 'cpms_custom_role_defs' ) .
                    ' SET version = version + 1, updated_at = %s WHERE id = %d',
                    [ $now, $def_id ]
                );
                if ( $advanced !== 1 ) {
                    throw new \RuntimeException( 'custom role version advance failed for definition: ' . $def_id );
                }
            }
        );

        return true;
    }

    /**
     * Activate/deactivate a definition (lifecycle primitive for future
     * management slices; NOT an archive operation). Inactive definitions supply
     * no permissions. Versioned, forward-only.
     *
     * @throws \InvalidArgumentException invalid Clinic, role key, or status
     *
     * @return bool false when no such definition exists
     */
    public function set_status( int $clinic_id, string $role_key, string $status ): bool {
        $def_id = $this->require_definition( $clinic_id, $role_key );
        if ( $def_id <= 0 ) {
            return false;
        }
        if ( ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_INACTIVE ], true ) ) {
            throw new \InvalidArgumentException( 'status must be active or inactive: ' . $status );
        }

        $now = $this->db->nowUtcSql();
        $this->db->transactional(
            function () use ( $def_id, $status, $now ): void {
                $updated = $this->db->execute(
                    'UPDATE ' . $this->db->table( 'cpms_custom_role_defs' ) .
                    ' SET status = %s, version = version + 1, updated_at = %s WHERE id = %d',
                    [ $status, $now, $def_id ]
                );
                if ( $updated !== 1 ) {
                    throw new \RuntimeException( 'custom role status update failed for definition: ' . $def_id );
                }
            }
        );

        return true;
    }

    /**
     * @return list<string> raw stored capability strings (NOT allowlist-filtered)
     */
    private function stored_capabilities( int $role_def_id ): array {
        if ( $role_def_id <= 0 ) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT capability FROM ' . $this->db->table( 'cpms_custom_role_capabilities' ) .
            ' WHERE role_def_id = %d ORDER BY capability',
            [ $role_def_id ]
        );

        $stored = [];
        foreach ( ( is_array( $rows ) ? $rows : [] ) as $row ) {
            if ( is_array( $row ) ) {
                $stored[] = (string) ( $row['capability'] ?? '' );
            }
        }

        return $stored;
    }

    /**
     * Read-side normalization of a stored display name: an unset, malformed or
     * unacceptable stored value is reported as null (never as a misleading
     * label), so the UI falls back to the immutable technical key.
     */
    private static function normalize_stored_display_name( mixed $stored ): ?string {
        if ( ! is_string( $stored ) ) {
            return null;
        }
        if ( ! CustomRolePolicy::is_acceptable_display_name( $stored ) ) {
            return null;
        }

        return CustomRolePolicy::sanitize_display_name( $stored );
    }

    /**
     * Optional display name for the define() path (legacy/Slice-1 callers may
     * omit it). When provided it must satisfy the bounded Unicode contract.
     *
     * @throws \InvalidArgumentException
     */
    private static function optional_display_name( ?string $display_name ): ?string {
        if ( $display_name === null ) {
            return null;
        }

        return self::require_display_name( $display_name );
    }

    /**
     * Strict display-name validation for write paths (all-or-nothing, fail
     * closed). Deliberately script-agnostic: it accepts any Unicode text that is
     * non-empty, within the code-point bound and stable under normalization —
     * there is no Persian-only (or any single-language) rule.
     *
     * @throws \InvalidArgumentException
     */
    private static function require_display_name( string $display_name ): string {
        if ( ! CustomRolePolicy::is_acceptable_display_name( $display_name ) ) {
            throw new \InvalidArgumentException(
                'display name is not acceptable (must be non-empty, at most '
                . CustomRolePolicy::DISPLAY_NAME_MAX_CODE_POINTS
                . ' characters, and contain at least one letter or number)'
            );
        }

        return CustomRolePolicy::sanitize_display_name( $display_name );
    }

    /**
     * @return int definition id, 0 when absent
     */
    private function require_definition( int $clinic_id, string $role_key ): int {
        if ( $clinic_id <= 0 ) {
            throw new \InvalidArgumentException( 'clinic_id must be > 0 for a custom role definition' );
        }
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw new \InvalidArgumentException( 'role key is not definable (format or built-in collision): ' . $role_key );
        }

        $id = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );

        return (int) ( $id ?? 0 );
    }

    /**
     * Strict capability validation for write paths: the ENTIRE set must be
     * allowlisted strings (all-or-nothing). Silent filtering is reserved for
     * the read path; writers must be told what they attempted is not allowed.
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws \InvalidArgumentException
     *
     * @return list<string> unique, sorted
     */
    private static function require_editable_capabilities( array $capabilities ): array {
        $clean = [];
        foreach ( $capabilities as $capability ) {
            if ( ! is_string( $capability ) || ! CustomRolePolicy::is_editable_capability( $capability ) ) {
                throw new \InvalidArgumentException(
                    'capability not allowed in a custom role definition: ' . ( is_string( $capability ) ? $capability : gettype( $capability ) )
                );
            }
            $clean[ $capability ] = true;
        }
        $list = array_keys( $clean );
        sort( $list );

        return $list;
    }

    /**
     * @param list<string> $capabilities
     */
    private function insert_capability_rows( int $role_def_id, array $capabilities, string $now ): void {
        if ( $role_def_id <= 0 ) {
            throw new \InvalidArgumentException( 'role_def_id must be > 0 for capability persistence' );
        }
        foreach ( $capabilities as $capability ) {
            $written = $this->db->insert(
                'cpms_custom_role_capabilities',
                [
                    'role_def_id' => $role_def_id,
                    'capability'  => $capability,
                    'created_at'  => $now,
                ]
            );
            if ( ! $written ) {
                // Fail loud inside the surrounding transaction: no partial
                // capability set may ever persist.
                throw new \RuntimeException( 'custom role capability insert failed for definition: ' . $role_def_id );
            }
        }
    }
}

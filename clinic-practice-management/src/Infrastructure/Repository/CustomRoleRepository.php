<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Infrastructure\Db\CpmsDb;

// Repository — Clinic-local custom-role definitions (Slice 1; Product Decision
// record: docs/decisions/2026-10-10-custom-role-permission-management-model.md).
//
// Tables (Migration 2026_10_10_0024):
//  - cpms_custom_role_defs: UNIQUE(clinic_id, role_key), status active/inactive/archived,
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
    public const STATUS_ARCHIVED = 'archived';

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * Effective capability set of an ACTIVE custom-role definition for the exact
     * Clinic. Fail closed: [] for missing/inactive/malformed/built-in-collision
     * keys, invalid Clinic, or any stored capability outside the approved set.
     *
     * @return list<string>
     */
    public function active_capabilities_for( int $clinic_id, string $role_key, bool $for_update = false ): array {
        if ( $clinic_id <= 0 ) {
            return [];
        }
        // Malformed keys and built-in-key collisions never resolve here.
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            return [];
        }

        $read = $for_update ? 'fetchRowForUpdate' : 'fetchRow';
        $def  = $this->db->$read(
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
        return CustomRolePolicy::sanitize_capability_set( $this->stored_capabilities( $def_id, $for_update ) );
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
                'status'       => $status,
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
            'status'       => $status,
            'version'      => (int) ( $row['version'] ?? 0 ),
            'capabilities' => CustomRolePolicy::sanitize_capability_set( $this->stored_capabilities( $id ) ),
        ];
    }

    /**
     * Current definition and permitted capabilities under an InnoDB row lock.
     * Callers must hold this read inside a transaction and retain the lock
     * through the lifecycle or assignment decision and write.
     *
     * @return array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, capabilities:list<string>}|null
     */
    public function definition_for_update( int $clinic_id, string $role_key ): ?array {
        if ( $clinic_id <= 0 || ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            return null;
        }

        $definition_id = (int) $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        $this->require_successful_read();
        if ( $definition_id <= 0 ) {
            return null;
        }

        // Resolve the immutable key first, then take the locking read by primary
        // key. A waiting transaction reads the current committed status/version
        // after the lock is granted, not the earlier consistent-read snapshot.
        $row = $this->db->fetchRowForUpdate(
            'SELECT id, clinic_id, role_key, display_name, status, version FROM ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [ $definition_id, $clinic_id ]
        );
        $this->require_successful_read();
        if ( $row === null ) {
            return null;
        }

        $id           = (int) ( $row['id'] ?? 0 );
        $owner_clinic = (int) ( $row['clinic_id'] ?? 0 );
        if ( $id <= 0 || $id !== $definition_id || $owner_clinic !== $clinic_id
            || (string) ( $row['role_key'] ?? '' ) !== $role_key ) {
            return null;
        }

        return [
            'clinic_id'    => $owner_clinic,
            'id'           => $id,
            'role_key'     => (string) ( $row['role_key'] ?? '' ),
            'display_name' => self::normalize_stored_display_name( $row['display_name'] ?? null ),
            'status'       => (string) ( $row['status'] ?? '' ),
            'version'      => (int) ( $row['version'] ?? 0 ),
            'capabilities' => CustomRolePolicy::sanitize_capability_set( $this->stored_capabilities( $id, true ) ),
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
        $clean = self::require_display_name( $display_name );

        return $this->db->transactional(
            function () use ( $clinic_id, $role_key, $clean ): bool {
                $definition = $this->mutable_definition_for_update( $clinic_id, $role_key );
                if ( $definition === null ) {
                    return false;
                }
                $updated = $this->db->execute(
                    'UPDATE ' . $this->db->table( 'cpms_custom_role_defs' ) .
                    ' SET display_name = %s, version = version + 1, updated_at = %s WHERE id = %d AND version = %d',
                    [ $clean, $this->db->nowUtcSql(), (int) $definition['id'], (int) $definition['version'] ]
                );
                if ( $updated !== 1 ) {
                    throw new \RuntimeException( 'custom role display name update failed' );
                }

                return true;
            }
        );
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
        $clean = self::require_editable_capabilities( $capabilities );

        return $this->db->transactional(
            function () use ( $clinic_id, $role_key, $clean ): bool {
                $definition = $this->mutable_definition_for_update( $clinic_id, $role_key );
                if ( $definition === null ) {
                    return false;
                }
                $def_id  = (int) $definition['id'];
                $now     = $this->db->nowUtcSql();
                $cleared = $this->db->query(
                    'DELETE FROM ' . $this->db->table( 'cpms_custom_role_capabilities' ) . ' WHERE role_def_id = %d',
                    [ $def_id ]
                );
                if ( ! $cleared ) {
                    throw new \RuntimeException( 'custom role capability reset failed for definition: ' . $def_id );
                }
                $this->insert_capability_rows( $def_id, $clean, $now );
                $advanced = $this->db->execute(
                    'UPDATE ' . $this->db->table( 'cpms_custom_role_defs' ) .
                    ' SET version = version + 1, updated_at = %s WHERE id = %d AND version = %d',
                    [ $now, $def_id, (int) $definition['version'] ]
                );
                if ( $advanced !== 1 ) {
                    throw new \RuntimeException( 'custom role version advance failed for definition: ' . $def_id );
                }

                return true;
            }
        );
    }

    /**
     * Version-checked status transition. The authorized application service
     * owns the surrounding transaction, row lock, revalidation and audit event.
     * This repository primitive never deletes definitions or capability rows.
     *
     * @throws \InvalidArgumentException invalid Clinic, key, or status
     */
    public function transition_status(
        int $clinic_id,
        string $role_key,
        string $expected_status,
        string $status,
        int $expected_version
    ): bool {
        if ( $clinic_id <= 0 || ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw new \InvalidArgumentException( 'invalid Clinic or custom role key for status transition' );
        }
        if ( ! in_array( $expected_status, [ self::STATUS_ACTIVE, self::STATUS_INACTIVE ], true )
            || ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED ], true ) ) {
            throw new \InvalidArgumentException( 'only mutable definitions may transition to a recognized status' );
        }
        if ( $expected_version <= 0 ) {
            return false;
        }

        $updated = $this->db->execute(
            'UPDATE ' . $this->db->table( 'cpms_custom_role_defs' ) .
            ' SET status = %s, version = version + 1, updated_at = %s' .
            ' WHERE clinic_id = %d AND role_key = %s AND status = %s AND version = %d',
            [ $status, $this->db->nowUtcSql(), $clinic_id, $role_key, $expected_status, $expected_version ]
        );

        return $updated === 1;
    }

    /**
     * Legacy repository primitive retained for existing internal fixtures.
     * Production management transitions use the version-checked service path.
     *
     * @throws \InvalidArgumentException invalid Clinic, key, or status
     * @return bool false when no such definition exists
     */
    public function set_status( int $clinic_id, string $role_key, string $status ): bool {
        if ( ! self::is_mutable_status( $status ) ) {
            throw new \InvalidArgumentException( 'legacy status writes accept only active or inactive' );
        }

        return $this->db->transactional(
            function () use ( $clinic_id, $role_key, $status ): bool {
                $definition = $this->mutable_definition_for_update( $clinic_id, $role_key );
                if ( $definition === null ) {
                    return false;
                }
                if ( ! $this->transition_status( $clinic_id, $role_key, (string) $definition['status'], $status, (int) $definition['version'] ) ) {
                    throw new \RuntimeException( 'custom role status update failed' );
                }

                return true;
            }
        );
    }

    /** Only explicitly recognized reversible states may be edited. */
    public static function is_mutable_status( string $status ): bool {
        return in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_INACTIVE ], true );
    }

    /**
     * Current locking reference check, deliberately WITHOUT a status filter.
     * Definition lock must already be held. A failed read is never zero refs.
     */
    public function has_membership_references_for_update( int $clinic_id, string $role_key ): bool {
        if ( $clinic_id <= 0 || ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw new \InvalidArgumentException( 'invalid Clinic or role reference selector' );
        }
        $reference = $this->db->fetchRowForUpdate(
            'SELECT id FROM ' . $this->db->table( 'cpms_clinic_memberships' ) .
            ' WHERE clinic_id = %d AND role_key = %s ORDER BY id LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        $this->require_successful_read();

        return $reference !== null;
    }

    /**
     * @return array{clinic_id:int,id:int,role_key:string,display_name:string|null,status:string,version:int,capabilities:list<string>}|null
     */
    private function mutable_definition_for_update( int $clinic_id, string $role_key ): ?array {
        if ( $clinic_id <= 0 || ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw new \InvalidArgumentException( 'invalid Clinic or custom role key' );
        }
        $definition = $this->definition_for_update( $clinic_id, $role_key );
        if ( $definition !== null && ! self::is_mutable_status( (string) $definition['status'] ) ) {
            throw new \InvalidArgumentException( 'archived or unknown custom role status is immutable' );
        }

        return $definition;
    }

    private function require_successful_read(): void {
        if ( $this->db->wpdb()->last_error !== '' ) {
            throw new \RuntimeException( 'custom role locking read failed' );
        }
    }

    /**
     * @return list<string> raw stored capability strings (NOT allowlist-filtered)
     */
    private function stored_capabilities( int $role_def_id, bool $for_update = false ): array {
        if ( $role_def_id <= 0 ) {
            return [];
        }
        $read = $for_update ? 'fetchAllForUpdate' : 'fetchAll';
        $rows = $this->db->$read(
            'SELECT capability FROM ' . $this->db->table( 'cpms_custom_role_capabilities' ) .
            ' WHERE role_def_id = %d ORDER BY capability',
            [ $role_def_id ]
        );

        if ( $for_update ) {
            $this->require_successful_read();
        }

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

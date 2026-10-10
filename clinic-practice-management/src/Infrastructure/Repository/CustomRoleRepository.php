<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Repository — Clinic-local custom-role definitions (Slice 1; Product Decision
 * record: docs/decisions/2026-10-10-custom-role-permission-management-model.md).
 *
 * Tables (Migration 2026_10_10_0024):
 *  - cpms_custom_role_defs: UNIQUE(clinic_id, role_key), status active/inactive,
 *    monotonic `version` (forward-only: updates only advance the version).
 *  - cpms_custom_role_capabilities: UNIQUE(role_def_id, capability).
 *
 * Boundary rules (security contract):
 *  - EVERY write path validates role keys and capabilities through
 *    CustomRolePolicy and rejects the whole write on any violation (all-or-
 *    nothing). Future writers must go through these methods; raw SQL bypass is
 *    additionally neutralized by the read path.
 *  - The authorization read path (active_capabilities_for) re-filters stored
 *    rows through CustomRolePolicy: a definition row that is missing, inactive,
 *    malformed, foreign-Clinic, or contains a non-allowlisted capability can
 *    never supply that capability — fail closed, no first-row-wins, no
 *    cross-Clinic fallback.
 *  - This repository stores and resolves NOTHING by itself: permission
 *    precedence stays exclusively in AuthorizationService.
 */
final class CustomRoleRepository
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public function __construct(private readonly CpmsDb $db)
    {
    }

    /**
     * Effective capability set of an ACTIVE custom-role definition for the exact
     * Clinic. Fail closed: [] for missing/inactive/malformed/built-in-collision
     * keys, invalid Clinic, or any stored capability outside the approved set.
     *
     * @return list<string>
     */
    public function active_capabilities_for(int $clinic_id, string $role_key): array
    {
        if ($clinic_id <= 0) {
            return [];
        }
        // Malformed keys and built-in-key collisions never resolve here.
        if (!CustomRolePolicy::isDefinableRoleKey($role_key)) {
            return [];
        }

        $def = $this->db->fetchRow(
            'SELECT id FROM ' . $this->db->table('cpms_custom_role_defs') .
            " WHERE clinic_id = %d AND role_key = %s AND status = 'active' LIMIT 1",
            [ $clinic_id, $role_key ]
        );
        if ($def === null) {
            return [];
        }
        $defId = (int) ( $def['id'] ?? 0 );
        if ($defId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT capability FROM ' . $this->db->table('cpms_custom_role_capabilities') .
            ' WHERE role_def_id = %d ORDER BY capability',
            [ $defId ]
        );

        $stored = [];
        foreach ( ( is_array( $rows ) ? $rows : [] ) as $row ) {
            if (is_array($row)) {
                $stored[] = (string) ( $row['capability'] ?? '' );
            }
        }

        // Defense in depth: even a raw-SQL writer cannot make a non-allowlisted
        // capability operational.
        return CustomRolePolicy::sanitizeCapabilitySet($stored);
    }

    /**
     * Define a new Clinic-local custom role (define-once: the key is immutable
     * and a second define() on the same (clinic, key) fails loudly).
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws \InvalidArgumentException invalid Clinic, role key, or capability set
     * @throws \RuntimeException definition already exists
     *
     * @return int new definition id
     */
    public function define(int $clinic_id, string $role_key, array $capabilities): int
    {
        if ($clinic_id <= 0) {
            throw new \InvalidArgumentException('clinic_id must be > 0 for a custom role definition');
        }
        if (!CustomRolePolicy::isDefinableRoleKey($role_key)) {
            throw new \InvalidArgumentException('role key is not definable (format or built-in collision): ' . $role_key);
        }
        $clean = self::requireEditableCapabilities($capabilities);

        $existing = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_custom_role_defs') .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        if ($existing !== null) {
            throw new \RuntimeException('custom role key already defined for this clinic: ' . $role_key);
        }

        $now = $this->db->nowUtcSql();
        $this->db->transactional(function () use ( $clinic_id, $role_key, $clean, $now ): void {
            $ok = $this->db->insert(
                'cpms_custom_role_defs',
                [
                    'clinic_id'  => $clinic_id,
                    'role_key'   => $role_key,
                    'status'     => self::STATUS_ACTIVE,
                    'version'    => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            if (!$ok) {
                // UNIQUE backstop (race): define-once semantics.
                throw new \RuntimeException('custom role key already defined for this clinic: ' . $role_key);
            }
            $this->insertCapabilityRows((int) $this->db->wpdb_last_insert_id(), $clean, $now);
        });

        $id = (int) $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_custom_role_defs') .
            ' WHERE clinic_id = %d AND role_key = %s LIMIT 1',
            [ $clinic_id, $role_key ]
        );
        if ($id <= 0) {
            throw new \RuntimeException('custom role definition insert failed for key: ' . $role_key);
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
    public function replace_capabilities(int $clinic_id, string $role_key, array $capabilities): bool
    {
        $defId = $this->requireDefinition($clinic_id, $role_key);
        if ($defId <= 0) {
            return false;
        }
        $clean = self::requireEditableCapabilities($capabilities);

        $now = $this->db->nowUtcSql();
        $this->db->transactional(function () use ( $defId, $clean, $now ): void {
            $this->db->delete('cpms_custom_role_capabilities', [ 'role_def_id' => $defId ]);
            $this->insertCapabilityRows($defId, $clean, $now);
            $this->db->query(
                'UPDATE ' . $this->db->table('cpms_custom_role_defs') .
                ' SET version = version + 1, updated_at = %s WHERE id = %d',
                [ $now, $defId ]
            );
        });

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
    public function set_status(int $clinic_id, string $role_key, string $status): bool
    {
        $defId = $this->requireDefinition($clinic_id, $role_key);
        if ($defId <= 0) {
            return false;
        }
        if (!in_array($status, [ self::STATUS_ACTIVE, self::STATUS_INACTIVE ], true)) {
            throw new \InvalidArgumentException('status must be active or inactive: ' . $status);
        }

        $this->db->query(
            'UPDATE ' . $this->db->table('cpms_custom_role_defs') .
            ' SET status = %s, version = version + 1, updated_at = %s WHERE id = %d',
            [ $status, $this->db->nowUtcSql(), $defId ]
        );

        return true;
    }

    /**
     * @return int definition id, 0 when absent
     */
    private function requireDefinition(int $clinic_id, string $role_key): int
    {
        if ($clinic_id <= 0) {
            throw new \InvalidArgumentException('clinic_id must be > 0 for a custom role definition');
        }
        if (!CustomRolePolicy::isDefinableRoleKey($role_key)) {
            throw new \InvalidArgumentException('role key is not definable (format or built-in collision): ' . $role_key);
        }

        $id = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table('cpms_custom_role_defs') .
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
    private static function requireEditableCapabilities(array $capabilities): array
    {
        $clean = [];
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !CustomRolePolicy::isEditableCapability($capability)) {
                throw new \InvalidArgumentException(
                    'capability not allowed in a custom role definition: ' . ( is_string($capability) ? $capability : gettype($capability) )
                );
            }
            $clean[$capability] = true;
        }
        $list = array_keys($clean);
        sort($list);

        return $list;
    }

    /**
     * @param list<string> $capabilities
     */
    private function insertCapabilityRows(int $role_def_id, array $capabilities, string $now): void
    {
        foreach ($capabilities as $capability) {
            $this->db->insert(
                'cpms_custom_role_capabilities',
                [
                    'role_def_id' => $role_def_id,
                    'capability'  => $capability,
                    'created_at'  => $now,
                ]
            );
        }
    }
}

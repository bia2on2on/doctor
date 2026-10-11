<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * Custom Role & Permission Management — Slice 3A: first-practice onboarding.
 *
 * The smallest complete and secure bootstrap that establishes authorized
 * management for a provably eligible first-setup Clinic, then hands the
 * administrator to the existing staff management surface.
 *
 * Security contract (product contract 1..13):
 *  - Entry gate is a WordPress administrator (`manage_options`) + explicit,
 *    confirmed Clinic identity. WP administrator status ALONE never grants
 *    clinical-record access or authority across all Clinics: it only opens
 *    this ONE-shot, per-installation, per-Clinic bootstrap window.
 *  - Eligibility is a durable, positive fact on the locked Clinic row
 *    (`first_setup_claimed_at IS NULL`) — never "no active manager", never a
 *    seeded id, never the first Clinic row, never an empty active membership
 *    set. Any historical administration evidence (including suspended
 *    managers) is conservatively marked consumed at migration time, and
 *    consumption is monotonic: previously administered Clinics can never
 *    become claimable again.
 *  - The bootstrap is available only while the installation is in its
 *    first-setup state (exactly one Clinic row). Multi-Clinic installations
 *    are NEVER bootstrap-claimable (no first-row fallback; expansion must use
 *    established management authority).
 *  - Provisioning is atomic: consume marker + create the built-in
 *    `cpms_manager` membership + mandatory hash-chained audit row commit or
 *    roll back together inside one narrow transaction that first locks the
 *    selected durable Clinic row (consistent lock ordering: Clinic row →
 *    membership insert → audit chain tail). No installation-wide locks.
 *  - Existing MembershipService / AuthorizationService remain the only
 *    membership and authorization engines. The administrator is provisioned
 *    as `cpms_manager` (built-in preset — no additional management role is
 *    created, and no doctor professional identity is auto-created).
 */
final class FirstPracticeSetupService
{
    public const AUDIT_ACTION   = 'PRACTICE_FIRST_SETUP';
    public const RESOURCE_TYPE  = 'clinic';

    public const MANAGER_ROLE_KEY = RolesAndCapabilities::ROLE_MANAGER;

    /** Practice display name bound in Unicode code points (schema allows 190). */
    private const NAME_MAX_CODE_POINTS = 120;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly MembershipRepository $memberships,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Server-derived display candidate for the confirmation step (display only;
     * the write path re-validates everything under the Clinic row lock).
     *
     * @return array{clinic_id:int, clinic_name:string, clinic_slug:string, organization_name:string}|null
     */
    public function first_setup_candidate(): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.id, c.name, c.slug, o.name AS organization_name
               FROM ' . $this->db->table('cpms_clinics') . ' c
               INNER JOIN ' . $this->db->table('cpms_organizations') . ' o ON o.id = c.organization_id
              WHERE o.status = %s
              ORDER BY c.id ASC',
            ['active']
        );
        if (!is_array($rows) || count($rows) !== 1) {
            return null; // multi-Clinic (or no Clinic): first-setup never uses a first-row fallback
        }

        $row = $rows[0];
        $clinicId = (int) ($row['id'] ?? 0);
        if ($clinicId <= 0) {
            return null;
        }

        $claimRow = $this->db->fetchRow(
            'SELECT first_setup_claimed_at FROM ' . $this->db->table('cpms_clinics') . ' WHERE id = %d',
            [$clinicId]
        );
        if ($claimRow === null || $claimRow['first_setup_claimed_at'] !== null) {
            return null;
        }

        $memberCount = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
            [$clinicId]
        ) ?? 0 );
        if ($memberCount !== 0) {
            return null;
        }

        return [
            'clinic_id'         => $clinicId,
            'clinic_name'       => (string) ($row['name'] ?? ''),
            'clinic_slug'       => (string) ($row['slug'] ?? ''),
            'organization_name' => (string) ($row['organization_name'] ?? ''),
        ];
    }

    /**
     * Claim the first-practice bootstrap: confirm identity, record essential
     * practice information, and establish authorized management atomically.
     *
     * @param string      $practice_name Unicode practice display name (required)
     * @param string|null $phone         optional, ≤32 chars (schema bound)
     * @param string|null $address       optional, ≤255 chars (schema bound)
     *
     * @return array{clinic_id:int, membership_id:int, audit_id:int}
     *
     * @throws FirstPracticeSetupException
     */
    public function claim(
        int $actor_wp_user_id,
        int $clinic_id,
        string $practice_name,
        ?string $phone = null,
        ?string $address = null
    ): array {
        // Entry gate: WordPress administrator. This is NOT Clinic authority —
        // it only permits initiating the one-shot first-practice bootstrap.
        if ($actor_wp_user_id <= 0) {
            throw FirstPracticeSetupException::not_administrator();
        }
        $actor = get_userdata($actor_wp_user_id);
        if ($actor === false || !user_can($actor, 'manage_options')) {
            throw FirstPracticeSetupException::not_administrator();
        }

        // Explicit, confirmed Clinic selector — forged/missing/zero is denied
        // outright (never resolved to a default or first row).
        if ($clinic_id <= 0) {
            throw FirstPracticeSetupException::invalid_clinic();
        }

        $clean_name = $this->normalize_name($practice_name);
        $clean_phone   = $this->bounded_optional($phone, 32, 'phone');
        $clean_address = $this->bounded_optional($address, 255, 'address');

        return $this->db->transactional(function () use (
            $actor_wp_user_id,
            $clinic_id,
            $clean_name,
            $clean_phone,
            $clean_address
        ): array {
            // Narrow lock on the selected durable Clinic row first (consistent
            // lock ordering). Eligibility is checked ONLY against this locked row.
            $clinic = $this->db->fetchRowForUpdate(
                'SELECT id, name, organization_id, first_setup_claimed_at
                   FROM ' . $this->db->table('cpms_clinics') . '
                  WHERE id = %d
                  LIMIT 1',
                [$clinic_id]
            );
            if ($clinic === null) {
                // Non-enumerating: a forged id shares the invalid-selector denial.
                throw FirstPracticeSetupException::invalid_clinic();
            }
            if ($clinic['first_setup_claimed_at'] !== null) {
                // Replay / previously claimed — forever, regardless of manager status.
                throw FirstPracticeSetupException::already_claimed();
            }

            // First-setup state: the installation must still be single-Clinic.
            // Multi-Clinic expansion uses established management authority only.
            $clinic_count = (int) ( $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table('cpms_clinics')
            ) ?? 0 );
            if ($clinic_count !== 1) {
                throw FirstPracticeSetupException::not_available();
            }

            // Corroboration (fail-closed, stricter than the marker): any durable
            // membership row — active OR suspended — proves prior administration.
            $member_count = (int) ( $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table('cpms_clinic_memberships') . ' WHERE clinic_id = %d',
                [$clinic_id]
            ) ?? 0 );
            if ($member_count !== 0) {
                throw FirstPracticeSetupException::not_available();
            }

            // Tenancy (Organization → Clinic → Location): the Clinic must sit
            // under an ACTIVE Organization.
            $org_status = $this->db->fetchValue(
                'SELECT status FROM ' . $this->db->table('cpms_organizations') . ' WHERE id = %d',
                [(int) ($clinic['organization_id'] ?? 0)]
            );
            if ((string) $org_status !== 'active') {
                throw FirstPracticeSetupException::not_available();
            }

            // One-shot consumption + essential practice information in a single
            // guarded UPDATE; the `first_setup_claimed_at IS NULL` guard is a
            // second, independent replay barrier. The affected-row count must
            // be exactly 1 or the claim fails closed.
            $now    = $this->db->nowUtcSql();
            $wpdb   = $this->db->wpdb();
            $sql    = 'UPDATE ' . $this->db->table('cpms_clinics') .
                ' SET first_setup_claimed_at = %s, name = %s, updated_at = %s';
            $params = [$now, $clean_name, $now];
            if ($clean_phone !== null) {
                $sql    .= ', phone = %s';
                $params[] = $clean_phone;
            }
            if ($clean_address !== null) {
                $sql    .= ', address = %s';
                $params[] = $clean_address;
            }
            $sql     .= ' WHERE id = %d AND first_setup_claimed_at IS NULL';
            $params[] = $clinic_id;
            $affected = $wpdb->query($wpdb->prepare($sql, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- single guarded claim UPDATE; affected-row gate below is authoritative
            if ((int) $affected !== 1) {
                throw FirstPracticeSetupException::already_claimed();
            }

            // Authorized management = the existing built-in cpms_manager
            // membership (the existing membership layer is the only membership
            // engine). No additional management role, no WP role mutation, and
            // no doctor professional identity is created here.
            $membership_ok = $this->memberships->insert(
                [
                    'clinic_id'             => $clinic_id,
                    'wp_user_id'            => $actor_wp_user_id,
                    'role_key'              => self::MANAGER_ROLE_KEY,
                    'scope_mode'            => 'clinic',
                    'status'                => 'active',
                    'is_primary'            => 1,
                    'invited_by_wp_user_id' => $actor_wp_user_id,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]
            );
            $membership_id = $membership_ok ? (int) $this->db->wpdb_last_insert_id() : 0;
            if ($membership_id <= 0) {
                throw FirstPracticeSetupException::not_available();
            }

            // Mandatory audit in the SAME transaction: a failed audit insert
            // rolls back marker consumption AND the membership (no partial
            // provisioning, no mutation without audit).
            $audit_id = $this->audit->log(
                self::AUDIT_ACTION,
                ['wp_user_id' => $actor_wp_user_id, 'role' => 'wp_administrator'],
                self::RESOURCE_TYPE,
                $clinic_id,
                null,
                ['name' => (string) ($clinic['name'] ?? '')],
                [
                    'name'    => $clean_name,
                    'phone'   => $clean_phone,
                    'address' => $clean_address,
                ],
                [
                    'membership_id' => $membership_id,
                    'role_key'      => self::MANAGER_ROLE_KEY,
                ],
                $clinic_id
            );

            return [
                'clinic_id'    => $clinic_id,
                'membership_id' => $membership_id,
                'audit_id'     => (int) $audit_id,
            ];
        });
    }

    /**
     * Unicode-general practice naming: valid UTF-8 required (fail closed),
     * control characters stripped (ZWNJ and other format characters preserved),
     * non-empty, bounded by code points. No single-language rule.
     */
    private function normalize_name(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw FirstPracticeSetupException::invalid_input('name');
        }
        // Strip Unicode control characters (Cc) only — format characters such
        // as ZWNJ (U+200C) are preserved byte-for-byte.
        $clean = preg_replace('/\p{Cc}+/u', '', $name) ?? '';
        $clean = trim($clean);
        if ($clean === '') {
            throw FirstPracticeSetupException::invalid_input('name');
        }
        if (mb_strlen($clean, 'UTF-8') > self::NAME_MAX_CODE_POINTS) {
            throw FirstPracticeSetupException::invalid_input('name');
        }

        return $clean;
    }

    private function bounded_optional(?string $value, int $max_chars, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw FirstPracticeSetupException::invalid_input($field);
        }
        $clean = preg_replace('/\p{Cc}+/u', '', $value) ?? '';
        $clean = trim($clean);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean, 'UTF-8') > $max_chars) {
            throw FirstPracticeSetupException::invalid_input($field);
        }

        return $clean;
    }
}

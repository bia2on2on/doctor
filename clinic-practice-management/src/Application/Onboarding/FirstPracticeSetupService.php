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
final class FirstPracticeSetupService {

    public const AUDIT_ACTION  = 'PRACTICE_FIRST_SETUP';
    public const RESOURCE_TYPE = 'clinic';

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
    public function first_setup_candidate(): ?array {
        $clinic_count = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinics' )
        ) ?? 0 );
        if ( $clinic_count !== 1 ) {
            return null;
        }

        $rows = $this->db->fetchAll(
            'SELECT c.id, c.name, c.slug, o.name AS organization_name
               FROM ' . $this->db->table( 'cpms_clinics' ) . ' c
               INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id
              WHERE o.status = %s
              ORDER BY c.id ASC',
            ['active']
        );
        if ( ! is_array( $rows ) || count( $rows ) !== 1 ) {
            return null; // multi-Clinic (or no Clinic): first-setup never uses a first-row fallback
        }

        $row       = $rows[0];
        $clinic_id = (int) ( $row['id'] ?? 0 );
        if ( $clinic_id <= 0 ) {
            return null;
        }

        $claim_row = $this->db->fetchRow(
            'SELECT first_setup_claimed_at FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [$clinic_id]
        );
        if ( $claim_row === null || $claim_row['first_setup_claimed_at'] !== null ) {
            return null;
        }

        $member_count = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
            [$clinic_id]
        ) ?? 0 );
        if ( $member_count !== 0 ) {
            return null;
        }

        return [
            'clinic_id'         => $clinic_id,
            'clinic_name'       => (string) ( $row['name'] ?? '' ),
            'clinic_slug'       => (string) ( $row['slug'] ?? '' ),
            'organization_name' => (string) ( $row['organization_name'] ?? '' ),
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
        if ( $actor_wp_user_id <= 0 ) {
            throw FirstPracticeSetupException::not_administrator();
        }
        $actor = get_userdata( $actor_wp_user_id );
        if ( $actor === false || ! user_can( $actor, 'manage_options' ) ) {
            throw FirstPracticeSetupException::not_administrator();
        }

        // Explicit, confirmed Clinic selector — forged/missing/zero is denied
        // outright (never resolved to a default or first row).
        if ( $clinic_id <= 0 ) {
            throw FirstPracticeSetupException::invalid_clinic();
        }

        $clean_name    = $this->normalize_name( $practice_name );
        $clean_phone   = $this->bounded_optional( $phone, 32, 'phone' );
        $clean_address = $this->bounded_optional( $address, 255, 'address' );

        return $this->db->transactional(
            function () use (
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
                   FROM ' . $this->db->table( 'cpms_clinics' ) . '
                  WHERE id = %d
                  LIMIT 1',
                    [$clinic_id]
				);
				if ( $clinic === null ) {
					// Non-enumerating: a forged id shares the invalid-selector denial.
					throw FirstPracticeSetupException::invalid_clinic();
				}
				if ( $clinic['first_setup_claimed_at'] !== null ) {
					// Replay / previously claimed — forever, regardless of manager status.
					throw FirstPracticeSetupException::already_claimed();
				}

				// First-setup state: the installation must still be single-Clinic.
				// Multi-Clinic expansion uses established management authority only.
				$clinic_count = (int) ( $this->db->fetchValue(
                    'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinics' )
				) ?? 0 );
				if ( $clinic_count !== 1 ) {
					throw FirstPracticeSetupException::not_available();
				}

				// Corroboration (fail-closed, stricter than the marker): any durable
				// membership row — active OR suspended — proves prior administration.
				$member_count = (int) ( $this->db->fetchValue(
                    'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
                    [$clinic_id]
				) ?? 0 );
				if ( $member_count !== 0 ) {
					throw FirstPracticeSetupException::not_available();
				}

				// Tenancy (Organization → Clinic → Location): the Clinic must sit
				// under an ACTIVE Organization.
				$org_status = $this->db->fetchValue(
                    'SELECT status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d',
                    [ (int) ( $clinic['organization_id'] ?? 0 )]
				);
				if ( (string) $org_status !== 'active' ) {
					throw FirstPracticeSetupException::not_available();
				}

				// One-shot consumption + essential practice information in a single
				// guarded UPDATE; the `first_setup_claimed_at IS NULL` guard is a
				// second, independent replay barrier. The affected-row count must
				// be exactly 1 or the claim fails closed.
				$now    = $this->db->nowUtcSql();
				$wpdb   = $this->db->wpdb();
				$sql    = 'UPDATE ' . $this->db->table( 'cpms_clinics' ) .
                ' SET first_setup_claimed_at = %s, name = %s, updated_at = %s';
				$params = [$now, $clean_name, $now];
				if ( $clean_phone !== null ) {
					$sql     .= ', phone = %s';
					$params[] = $clean_phone;
				}
				if ( $clean_address !== null ) {
					$sql     .= ', address = %s';
					$params[] = $clean_address;
				}
				$sql     .= ' WHERE id = %d AND first_setup_claimed_at IS NULL';
				$params[] = $clinic_id;
				$affected = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- single guarded claim UPDATE; affected-row gate below is authoritative
				if ( (int) $affected !== 1 ) {
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
				if ( $membership_id <= 0 ) {
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
                    ['name' => (string) ( $clinic['name'] ?? '' )],
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
					'clinic_id'     => $clinic_id,
					'membership_id' => $membership_id,
					'audit_id'      => (int) $audit_id,
				];
			}
        );
    }


    // ==================================================================
    // Interrupted-setup recovery (migration 0026 historical backfill).
    //
    // Trust model: eligibility is NEVER inferred from timestamps or from
    // the mere absence of members. It requires positive, persisted,
    // strictly-recognized setup-only evidence:
    //  - exactly one Clinic under an ACTIVE Organization;
    //  - the durable marker is consumed (first_setup_claimed_at NOT NULL);
    //  - zero membership rows of ANY status (active or suspended);
    //  - no PRACTICE_FIRST_SETUP / recovery audit (no prior bootstrap);
    //  - at least one audit row, and EVERY audit row for the Clinic is a
    //    strictly-shaped wizard setup SETTING_UPDATE (resource_type
    //    'setting', NULL resource/patient, WP-administrator actor, exact
    //    {setting,value} before/after shape, allowlisted wizard key,
    //    config_update meta bound to this Clinic);
    //  - no persisted Clinic setting outside the wizard setup allowlist;
    //  - no clinical, patient, financial, staffing or custom-role rows.
    // Any unknown or ambiguous history fails closed. The marker is never
    // reset, no audit/setting row is ever deleted, and recovery itself is
    // one-shot (afterwards a membership exists and the marker stays
    // consumed, so eligibility can never re-open).
    // ==================================================================

    public const RECOVERY_AUDIT_ACTION = 'PRACTICE_FIRST_SETUP_RECOVERY';

    /**
     * Setting keys the setup wizard could legitimately have written before
     * the bootstrap completed (GET progress marker, step progress,
     * restart/finish flags, booking defaults, SMS basics, legacy profile).
     * Other audit keys are unknown history. The persisted system heartbeat
     * is handled separately and never counts as ownership evidence.
     *
     * @var list<string>
     */
    private const SETUP_ONLY_SETTING_KEYS = [
        'setup.started_at',
        'setup.current_step',
        'setup.completed',
        // Exact keys written by the pre-Phase4 wizard's saveClinic().
        // Historical evidence only: never restore a second writable profile,
        // accept a wildcard, or relax the single-administrator continuity gate.
        'setup.clinic.name',
        'setup.clinic.address',
        'setup.clinic.phone',
        'setup.clinic.timezone',
        'booking.duration_default_min',
        'booking.slot_capacity_default',
        'booking.min_lead_hours',
        'booking.max_future_days',
        'booking.cancel_deadline_hours',
        'sms.provider',
        'sms.sender',
    ];

    /** Tables whose rows prove prior clinical/financial/staffing activity. */
    private const ACTIVITY_TABLES = [
        'cpms_patients',
        'cpms_clinicians',
        'cpms_appointments',
        'cpms_visits',
        'cpms_invoices',
        'cpms_payments',
        'cpms_services',
        'cpms_custom_role_defs',
    ];

    /**
     * Display candidate for interrupted-setup recovery (read-only).
     *
     * @return array{clinic_id:int, clinic_name:string, clinic_slug:string, organization_name:string}|null
     */
    public function recovery_candidate( ?int $actor_wp_user_id = null ): ?array {
        $row = $this->single_clinic_display_row( true );
        if ( $row === null ) {
            return null;
        }
        if ( $this->evaluate_recovery( (int) $row['clinic_id'], $actor_wp_user_id ) !== '' ) {
            return null;
        }

        return $row;
    }

    /**
     * Non-null when the installation looks like an interrupted setup
     * (single Clinic, active Organization, zero memberships, consumed
     * marker) but recovery eligibility cannot be established from
     * trustworthy persisted evidence — the caller must show a blocked
     * message and NO recovery form.
     *
     * @return array{clinic_id:int, clinic_name:string, clinic_slug:string, organization_name:string}|null
     */
    public function interrupted_setup_blocked( ?int $actor_wp_user_id = null ): ?array {
        $row = $this->single_clinic_display_row( true );
        if ( $row === null ) {
            return null;
        }
        if ( $this->evaluate_recovery( (int) $row['clinic_id'], $actor_wp_user_id ) === '' ) {
            return null; // eligible for this actor: recovery path, not blocked
        }

        return $row;
    }

    /**
     * Recover an interrupted first setup: same one-shot provisioning as
     * claim() (manager membership + practice information + mandatory
     * audit, atomically under the Clinic row lock), but the historical
     * consumption marker is left untouched — it is never reset.
     *
     * @return array{clinic_id:int, membership_id:int, audit_id:int}
     *
     * @throws FirstPracticeSetupException
     */
    public function recover(
        int $actor_wp_user_id,
        int $clinic_id,
        string $practice_name,
        ?string $phone = null,
        ?string $address = null
    ): array {
        if ( $actor_wp_user_id <= 0 ) {
            throw FirstPracticeSetupException::not_administrator();
        }
        $actor = get_userdata( $actor_wp_user_id );
        if ( $actor === false || ! user_can( $actor, 'manage_options' ) ) {
            throw FirstPracticeSetupException::not_administrator();
        }
        if ( $clinic_id <= 0 ) {
            throw FirstPracticeSetupException::recovery_not_eligible();
        }

        $clean_name    = $this->normalize_name( $practice_name );
        $clean_phone   = $this->bounded_optional( $phone, 32, 'phone' );
        $clean_address = $this->bounded_optional( $address, 255, 'address' );

        return $this->db->transactional(
            function () use ( $actor_wp_user_id, $clinic_id, $clean_name, $clean_phone, $clean_address ): array {
                $clinic = $this->db->fetchRowForUpdate(
                    'SELECT id, name, organization_id, first_setup_claimed_at
                       FROM ' . $this->db->table( 'cpms_clinics' ) . '
                      WHERE id = %d
                      LIMIT 1',
                    [$clinic_id]
                );
                if ( $clinic === null ) {
                    throw FirstPracticeSetupException::recovery_not_eligible();
                }

                // Full eligibility re-evaluated against locked state,
                // including actor continuity: the recovering administrator
                // must be the same administrator who started the setup.
                if ( $this->evaluate_recovery( $clinic_id, $actor_wp_user_id ) !== '' ) {
                    throw FirstPracticeSetupException::recovery_not_eligible();
                }

                $now    = $this->db->nowUtcSql();
                $wpdb   = $this->db->wpdb();
                $sql    = 'UPDATE ' . $this->db->table( 'cpms_clinics' ) . ' SET name = %s, updated_at = %s';
                $params = [$clean_name, $now];
                if ( $clean_phone !== null ) {
                    $sql     .= ', phone = %s';
                    $params[] = $clean_phone;
                }
                if ( $clean_address !== null ) {
                    $sql     .= ', address = %s';
                    $params[] = $clean_address;
                }
                // Guarded on the consumed marker: recovery can never run on
                // an unclaimed Clinic (that is the normal claim path) and
                // the marker itself is never modified here.
                $sql     .= ' WHERE id = %d AND first_setup_claimed_at IS NOT NULL';
                $params[] = $clinic_id;
                $affected = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- guarded recovery UPDATE; affected-row gate below is authoritative
                if ( (int) $affected !== 1 ) {
                    throw FirstPracticeSetupException::recovery_not_eligible();
                }

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
                if ( $membership_id <= 0 ) {
                    throw FirstPracticeSetupException::recovery_not_eligible();
                }

                // Mandatory audit in the SAME transaction; failure rolls
                // back the membership and the practice-info update.
                $audit_id = $this->audit->log(
                    self::RECOVERY_AUDIT_ACTION,
                    ['wp_user_id' => $actor_wp_user_id, 'role' => 'wp_administrator'],
                    self::RESOURCE_TYPE,
                    $clinic_id,
                    null,
                    ['name' => (string) ( $clinic['name'] ?? '' )],
                    [
                        'name'    => $clean_name,
                        'phone'   => $clean_phone,
                        'address' => $clean_address,
                    ],
                    [
                        'membership_id'               => $membership_id,
                        'role_key'                    => self::MANAGER_ROLE_KEY,
                        'recovered_interrupted_setup' => true,
                    ],
                    $clinic_id
                );
                if ( (int) $audit_id <= 0 ) {
                    throw FirstPracticeSetupException::recovery_not_eligible();
                }

                return [
                    'clinic_id'     => $clinic_id,
                    'membership_id' => $membership_id,
                    'audit_id'      => (int) $audit_id,
                ];
            }
        );
    }

    /**
     * Strict recovery eligibility. Returns '' when eligible, otherwise a
     * narrow internal reason code (never exposed to end users verbatim).
     */
    private function evaluate_recovery( int $clinic_id, ?int $expected_actor_id = null ): string {
        $clinic_count = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinics' )
        ) ?? 0 );
        if ( $clinic_count !== 1 ) {
            return 'multiple_clinics';
        }

        $clinic = $this->db->fetchRow(
            'SELECT organization_id, first_setup_claimed_at FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [$clinic_id]
        );
        if ( $clinic === null ) {
            return 'clinic_missing';
        }
        if ( $clinic['first_setup_claimed_at'] === null ) {
            return 'not_claimed'; // normal claim() path, not recovery
        }

        $org_status = $this->db->fetchValue(
            'SELECT status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d',
            [ (int) ( $clinic['organization_id'] ?? 0 )]
        );
        if ( (string) $org_status !== 'active' ) {
            return 'organization_inactive';
        }

        // Zero membership references, including suspended.
        $member_count = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
            [$clinic_id]
        ) ?? 0 );
        if ( $member_count !== 0 ) {
            return 'membership_exists';
        }

        // Audit history: at least one row, every row strictly recognized.
        $audit_rows = $this->db->fetchAll(
            'SELECT action, actor_wp_user_id, actor_role, resource_type, resource_id, patient_id, before_json, after_json, meta_json
               FROM ' . $this->db->table( 'cpms_audit_logs' ) . '
              WHERE clinic_id = %d
              ORDER BY id ASC',
            [$clinic_id]
        );
        if ( ! is_array( $audit_rows ) || $audit_rows === [] ) {
            return 'no_setup_evidence';
        }
        // Actor continuity: every recognized setup row must be attributed
        // to ONE historical administrator. Mixed, unattributed or invalid
        // ownership is ambiguous and fails closed.
        $history_actor_id = null;
        foreach ( $audit_rows as $row ) {
            $action = (string) ( $row['action'] ?? '' );
            if ( $action === self::AUDIT_ACTION || $action === self::RECOVERY_AUDIT_ACTION ) {
                return 'prior_bootstrap';
            }
            if ( ! $this->is_recognized_setup_audit( $row, $clinic_id ) ) {
                return 'unknown_audit_history';
            }
            $row_actor_id = (int) ( $row['actor_wp_user_id'] ?? 0 );
            if ( $history_actor_id === null ) {
                $history_actor_id = $row_actor_id;
            } elseif ( $row_actor_id !== $history_actor_id ) {
                return 'mixed_historical_actors';
            }
        }
        if ( $history_actor_id === null || $history_actor_id <= 0 ) {
            return 'no_setup_evidence';
        }

        // The historical actor must still be a real account that currently
        // holds installation-administrator authority. Deleted accounts,
        // invalid ids and demoted administrators cannot be "continued",
        // and nobody else may take over their interrupted setup.
        $history_actor = get_userdata( $history_actor_id );
        if ( $history_actor === false || ! user_can( $history_actor, 'manage_options' ) ) {
            return 'historical_actor_invalid';
        }
        if ( $expected_actor_id !== null && $expected_actor_id !== $history_actor_id ) {
            return 'actor_continuity';
        }

        // Persisted settings: only wizard history or the exact inert system
        // heartbeat; the recorded writer of every setup setting must be
        // the same historical administrator as the audit history.
        $setting_keys = $this->db->fetchAll(
            'SELECT `key`, value_json, updated_by_wp_user_id FROM ' . $this->db->table( 'cpms_settings' ) . ' WHERE clinic_id = %d',
            [$clinic_id]
        );
        foreach ( is_array( $setting_keys ) ? $setting_keys : [] as $row ) {
            // App::recordTick() writes this unaudited runtime heartbeat even
            // before setup. It is not administrative/ownership evidence.
            // Only the exact system-written integer shape is inert; it can
            // never replace the positive, single-actor audit evidence above.
            if ( (string) ( $row['key'] ?? '' ) === 'jobs.last_tick_at'
                && $row['updated_by_wp_user_id'] === null
            ) {
                $heartbeat = json_decode( (string) ( $row['value_json'] ?? '' ), true );
                if ( is_int( $heartbeat ) && $heartbeat > 0 ) {
                    continue;
                }
            }
            if ( ! in_array( (string) ( $row['key'] ?? '' ), self::SETUP_ONLY_SETTING_KEYS, true ) ) {
                return 'unknown_setting_history';
            }
            if ( (int) ( $row['updated_by_wp_user_id'] ?? 0 ) !== $history_actor_id ) {
                return 'setting_actor_contradiction';
            }
        }

        // No clinical, patient, financial, staffing or custom-role activity.
        foreach ( self::ACTIVITY_TABLES as $table ) {
            $count = (int) ( $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table( $table ) . ' WHERE clinic_id = %d',
                [$clinic_id]
            ) ?? 0 );
            if ( $count !== 0 ) {
                return 'prior_activity';
            }
        }

        return '';
    }

    /**
     * Exact shape written by Settings::auditChange() for a wizard setup
     * write. Arbitrary SETTING_UPDATE rows are deliberately NOT accepted.
     *
     * @param array<string, mixed> $row
     */
    private function is_recognized_setup_audit( array $row, int $clinic_id ): bool {
        if ( (string) ( $row['action'] ?? '' ) !== 'SETTING_UPDATE' ) {
            return false;
        }
        if ( (string) ( $row['resource_type'] ?? '' ) !== 'setting' ) {
            return false;
        }
        if ( $row['resource_id'] !== null || $row['patient_id'] !== null ) {
            return false;
        }
        if ( (int) ( $row['actor_wp_user_id'] ?? 0 ) <= 0 ) {
            return false;
        }
        if ( (string) ( $row['actor_role'] ?? '' ) !== 'administrator' ) {
            return false;
        }

        $before = json_decode( (string) ( $row['before_json'] ?? '' ), true );
        $after  = json_decode( (string) ( $row['after_json'] ?? '' ), true );
        $meta   = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
        if ( ! is_array( $before ) || ! is_array( $after ) || ! is_array( $meta ) ) {
            return false;
        }
        foreach ( [$before, $after] as $payload ) {
            $keys = array_keys( $payload );
            sort( $keys );
            if ( $keys !== ['setting', 'value'] ) {
                return false;
            }
            if ( ! in_array( (string) $payload['setting'], self::SETUP_ONLY_SETTING_KEYS, true ) ) {
                return false;
            }
        }
        if ( (string) $before['setting'] !== (string) $after['setting'] ) {
            return false;
        }
        if ( (string) ( $meta['op'] ?? '' ) !== 'config_update' ) {
            return false;
        }
        if ( (int) ( $meta['clinic_id'] ?? 0 ) !== $clinic_id ) {
            return false;
        }

        return true;
    }

    /**
     * Single-Clinic display row shared by recovery display paths.
     *
     * @return array{clinic_id:int, clinic_name:string, clinic_slug:string, organization_name:string}|null
     */
    private function single_clinic_display_row( bool $require_claimed ): ?array {
        $clinic_count = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinics' )
        ) ?? 0 );
        if ( $clinic_count !== 1 ) {
            return null;
        }

        $rows = $this->db->fetchAll(
            'SELECT c.id, c.name, c.slug, c.first_setup_claimed_at, o.name AS organization_name
               FROM ' . $this->db->table( 'cpms_clinics' ) . ' c
               INNER JOIN ' . $this->db->table( 'cpms_organizations' ) . ' o ON o.id = c.organization_id
              WHERE o.status = %s
              ORDER BY c.id ASC',
            ['active']
        );
        if ( ! is_array( $rows ) || count( $rows ) !== 1 ) {
            return null;
        }

        $row       = $rows[0];
        $clinic_id = (int) ( $row['id'] ?? 0 );
        if ( $clinic_id <= 0 ) {
            return null;
        }
        $claimed = ( $row['first_setup_claimed_at'] ?? null ) !== null;
        if ( $claimed !== $require_claimed ) {
            return null;
        }

        $member_count = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d',
            [$clinic_id]
        ) ?? 0 );
        if ( $member_count !== 0 ) {
            return null;
        }

        return [
            'clinic_id'         => $clinic_id,
            'clinic_name'       => (string) ( $row['name'] ?? '' ),
            'clinic_slug'       => (string) ( $row['slug'] ?? '' ),
            'organization_name' => (string) ( $row['organization_name'] ?? '' ),
        ];
    }

    /**
     * Unicode-general practice naming: valid UTF-8 required (fail closed),
     * control characters stripped (ZWNJ and other format characters preserved),
     * non-empty, bounded by code points. No single-language rule.
     */
    private function normalize_name( string $name ): string {
        if ( ! mb_check_encoding( $name, 'UTF-8' ) ) {
            throw FirstPracticeSetupException::invalid_input( 'name' );
        }
        // Strip Unicode control characters (Cc) only — format characters such
        // as ZWNJ (U+200C) are preserved byte-for-byte.
        $clean = preg_replace( '/\p{Cc}+/u', '', $name ) ?? '';
        $clean = trim( $clean );
        if ( $clean === '' ) {
            throw FirstPracticeSetupException::invalid_input( 'name' );
        }
        if ( mb_strlen( $clean, 'UTF-8' ) > self::NAME_MAX_CODE_POINTS ) {
            throw FirstPracticeSetupException::invalid_input( 'name' );
        }

        return $clean;
    }

    private function bounded_optional( ?string $value, int $max_chars, string $field ): ?string {
        if ( $value === null ) {
            return null;
        }
        if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
            throw FirstPracticeSetupException::invalid_input( $field );
        }
        $clean = preg_replace( '/\p{Cc}+/u', '', $value ) ?? '';
        $clean = trim( $clean );
        if ( $clean === '' ) {
            return null;
        }
        if ( mb_strlen( $clean, 'UTF-8' ) > $max_chars ) {
            throw FirstPracticeSetupException::invalid_input( $field );
        }

        return $clean;
    }
}

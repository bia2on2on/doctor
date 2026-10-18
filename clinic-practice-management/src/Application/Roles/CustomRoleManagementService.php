<?php

declare(strict_types=1);

namespace ClinicCore\Application\Roles;

use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\CustomRoleRepository;

/**
 * Custom Role & Permission Management — Slice 2: the authorized, Clinic-scoped
 * management surface for custom-role DEFINITIONS (list / create / rename /
 * replace permitted capability set).
 *
 * Product Decision record: docs/decisions/2026-10-10-custom-role-permission-
 * management-model.md (decisions 3, 4, 5, 6, 7).
 *
 * Authority model (no parallel authorization engine):
 *  1. The requested Clinic is a *selector*, never a trust fact. It is resolved
 *     through the existing TrustedClinicEstablisher (durable ACTIVE membership +
 *     real Clinic + ACTIVE Organization), exactly like StaffManagementPage.
 *  2. Effective management authority = AuthorizationService::can(
 *     actor, trusted Clinic, cpms_config ). WordPress `manage_options`, the WP
 *     `administrator` role and any global WP capability are NOT consulted and
 *     therefore never grant Clinic authority. An administrator with no durable
 *     membership in that Clinic is denied, and there is no "first Clinic",
 *     implicit global Clinic or clinic_id=0 fallback anywhere.
 *  3. Delegation is bounded (decision 4): an editor may only place capabilities
 *     the editor themselves effectively holds in that Clinic — and only from the
 *     V1-editable catalogue. Sensitive capabilities (CustomRolePolicy::
 *     SENSITIVE_CAPS, which includes cpms_config/cpms_sms_config themselves) are
 *     never delegable, so the management authority cannot be replicated into a
 *     custom role.
 *  4. Only Clinic-local definition objects are addressable: every mutation first
 *     reads the durable definition row scoped to the trusted Clinic (object
 *     ownership), then re-checks it through AuthorizationService::
 *     authorizeForObject. A key that lives in another Clinic is simply not
 *     addressable here.
 *  5. Every successful mutation is audited (hash-chained AuditLogger) with the
 *     explicit Clinic, actor, role key, before/after change set and version.
 *     There is no PHI in a custom-role definition or in the audit payload.
 *
 * Slice 4B adds terminal archival alongside reversible active/inactive changes.
 * Definitions and keys survive. No deletion, automatic reassignment, clinical
 * write or WordPress-global role registration is performed.
 */
final class CustomRoleManagementService {

    public const AUDIT_DEFINED              = 'CUSTOM_ROLE_DEFINED';
    public const AUDIT_DISPLAY_NAME_CHANGED = 'CUSTOM_ROLE_DISPLAY_NAME_CHANGED';
    public const AUDIT_CAPABILITIES_CHANGED = 'CUSTOM_ROLE_CAPABILITIES_CHANGED';
    public const AUDIT_DEACTIVATED          = 'CUSTOM_ROLE_DEACTIVATED';
    public const AUDIT_REACTIVATED          = 'CUSTOM_ROLE_REACTIVATED';
    public const AUDIT_ARCHIVED             = 'CUSTOM_ROLE_ARCHIVED';

    public const RESOURCE_TYPE = 'custom_role_definition';

    public function __construct(
        private readonly TrustedClinicEstablisher $scope,
        private readonly AuthorizationService $authorization,
        private readonly CustomRoleRepository $roles,
        private readonly AuditLogger $audit,
        private readonly CpmsDb $db
    ) {
    }

    /**
     * Display/entry gate for a management surface: effective Clinic-scoped
     * management authority only. Never true for a WP administrator who holds no
     * durable membership in that Clinic.
     */
    public function can_manage( int $actor_wp_user_id, int $clinic_id ): bool {
        if ( $actor_wp_user_id <= 0 || $clinic_id <= 0 ) {
            return false;
        }

        return $this->authorization->can( $actor_wp_user_id, $clinic_id, RolesAndCapabilities::CONFIG );
    }

    /**
     * The capability set the actor may actually place into a definition of the
     * trusted Clinic: V1-editable catalogue ∩ the actor's own effective
     * capabilities. Fail closed — anything the actor does not hold is absent.
     *
     * @throws ScopeRequiredException  no trusted Clinic context for this actor
     * @throws \ClinicCore\Application\Authorization\AuthorizationException no management authority
     *
     * @return list<string>
     */
    public function delegable_capabilities( int $actor_wp_user_id, ?int $requested_clinic_id ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        $effective = [];
        foreach ( CustomRolePolicy::editable_capabilities() as $capability ) {
            if ( $this->authorization->can( $actor_wp_user_id, $clinic_id, $capability ) ) {
                $effective[] = $capability;
            }
        }

        return CustomRolePolicy::delegable_capabilities( $effective );
    }

    /**
     * Clinic-local definition list for the authorized management surface.
     *
     * @throws ScopeRequiredException
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     *
     * @return array{clinic_id:int, definitions:list<array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, updated_at:string, capabilities:list<string>}>}
     */
    public function list_definitions( int $actor_wp_user_id, ?int $requested_clinic_id ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        return [
            'clinic_id'   => $clinic_id,
            'definitions' => $this->roles->list_definitions( $clinic_id ),
        ];
    }

    /**
     * Define a new Clinic-local custom role. The technical key is immutable: it
     * is fixed at creation time and never derived from, or renamed with, the
     * display name.
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws ScopeRequiredException
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     * @throws CustomRoleManagementException
     *
     * @return array{clinic_id:int, id:int, role_key:string, display_name:string, version:int, capabilities:list<string>}
     */
    public function create(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        string $role_key,
        string $display_name,
        array $capabilities
    ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw CustomRoleManagementException::invalid_role_key();
        }
        $clean_display_name = $this->require_display_name( $display_name );
        $clean_capabilities = $this->require_delegable_capabilities( $actor_wp_user_id, $clinic_id, $capabilities );

        if ( $this->roles->definition_for( $clinic_id, $role_key ) !== null ) {
            // Explicit conflict error instead of relying on the UNIQUE backstop.
            throw CustomRoleManagementException::key_conflict( $role_key );
        }

        // Atomicidade (F2-1): تعریف نقش و رویداد Audit اجباری آن در یک مرزِ
        // تراکنش. شکست ذخیره‌سازی Audit کل تعریف (و ردیف‌های capability)
        // را عقب می‌برد؛ هیچ نقشِ بدونِ Audit و هیچ «موفقیتِ کاذب» نمی‌ماند.
        return $this->db->transactional(
            function () use ( $actor_wp_user_id, $clinic_id, $role_key, $clean_display_name, $clean_capabilities ): array {
                $definition_id = $this->roles->define( $clinic_id, $role_key, $clean_capabilities, $clean_display_name );

                $this->audit->log(
                    self::AUDIT_DEFINED,
                    [ 'wp_user_id' => $actor_wp_user_id, 'role' => 'clinic_manager' ],
                    self::RESOURCE_TYPE,
                    $definition_id,
                    null,
                    null,
                    [
                        'role_key'     => $role_key,
                        'display_name' => $clean_display_name,
                        'version'      => 1,
                        'capabilities' => $clean_capabilities,
                    ],
                    [ 'clinic_id' => $clinic_id ],
                    $clinic_id
                );

                return [
                    'clinic_id'    => $clinic_id,
                    'id'           => $definition_id,
                    'role_key'     => $role_key,
                    'display_name' => $clean_display_name,
                    'version'      => 1,
                    'capabilities' => $clean_capabilities,
                ];
            }
        );
    }

    /**
     * Edit the display name of an existing definition. Capabilities, status and
     * the technical key are untouched; the durable version still advances.
     *
     * @throws ScopeRequiredException
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     * @throws CustomRoleManagementException
     *
     * @return array{clinic_id:int, id:int, role_key:string, display_name:string, version:int, capabilities:list<string>}
     */
    public function rename( int $actor_wp_user_id, ?int $requested_clinic_id, string $role_key, string $display_name, ?int $expected_version = null ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        $clean_display_name = $this->require_display_name( $display_name );

        // Atomicidade (F2-1): نام نمایشی و رویداد Audit اجباری آن در یک مرز.
        return $this->db->transactional(
            function () use ( $actor_wp_user_id, $clinic_id, $role_key, $clean_display_name, $expected_version ): array {
                $existing = $this->require_mutable_definition_for_update( $actor_wp_user_id, $clinic_id, $role_key, $expected_version );
                $updated  = $this->roles->set_display_name( $clinic_id, $role_key, $clean_display_name );
                if ( ! $updated ) {
                    throw CustomRoleManagementException::not_found( $role_key );
                }

                $this->audit->log(
                    self::AUDIT_DISPLAY_NAME_CHANGED,
                    [ 'wp_user_id' => $actor_wp_user_id, 'role' => 'clinic_manager' ],
                    self::RESOURCE_TYPE,
                    (int) $existing['id'],
                    null,
                    [ 'role_key' => $role_key, 'display_name' => $existing['display_name'], 'version' => (int) $existing['version'] ],
                    [ 'role_key' => $role_key, 'display_name' => $clean_display_name, 'version' => (int) $existing['version'] + 1 ],
                    [ 'clinic_id' => $clinic_id ],
                    $clinic_id
                );

                return [
                    'clinic_id'    => $clinic_id,
                    'id'           => (int) $existing['id'],
                    'role_key'     => $role_key,
                    'display_name' => $clean_display_name,
                    'version'      => (int) $existing['version'] + 1,
                    'capabilities' => $existing['capabilities'],
                ];
            }
        );
    }

    /**
     * Replace the permitted capability set of an existing definition.
     *
     * All-or-nothing: every requested capability must be editable AND within the
     * actor's own effective Clinic authority, otherwise nothing is written and
     * the previous capability set plus version stay exactly as they were.
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws ScopeRequiredException
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     * @throws CustomRoleManagementException
     *
     * @return array{clinic_id:int, id:int, role_key:string, display_name:string, version:int, capabilities:list<string>}
     */
    public function replace_capabilities(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        string $role_key,
        array $capabilities,
        ?int $expected_version = null
    ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );

        $clean_capabilities = $this->require_delegable_capabilities( $actor_wp_user_id, $clinic_id, $capabilities );

        // Atomicidade (F2-1): جایگزینی capability و رویداد Audit اجباری آن در
        // یک مرز — شکست Audit، مجموعهٔ قبلی و version را دست‌نخورده نگه می‌دارد.
        return $this->db->transactional(
            function () use ( $actor_wp_user_id, $clinic_id, $role_key, $clean_capabilities, $expected_version ): array {
                $existing = $this->require_mutable_definition_for_update( $actor_wp_user_id, $clinic_id, $role_key, $expected_version );
                $this->require_delegable_capabilities( $actor_wp_user_id, $clinic_id, $clean_capabilities, true );
                $replaced = $this->roles->replace_capabilities( $clinic_id, $role_key, $clean_capabilities );
                if ( ! $replaced ) {
                    throw CustomRoleManagementException::not_found( $role_key );
                }

                $this->audit->log(
                    self::AUDIT_CAPABILITIES_CHANGED,
                    [ 'wp_user_id' => $actor_wp_user_id, 'role' => 'clinic_manager' ],
                    self::RESOURCE_TYPE,
                    (int) $existing['id'],
                    null,
                    [ 'role_key' => $role_key, 'capabilities' => $existing['capabilities'], 'version' => (int) $existing['version'] ],
                    [ 'role_key' => $role_key, 'capabilities' => $clean_capabilities, 'version' => (int) $existing['version'] + 1 ],
                    [ 'clinic_id' => $clinic_id ],
                    $clinic_id
                );

                return [
                    'clinic_id'    => $clinic_id,
                    'id'           => (int) $existing['id'],
                    'role_key'     => $role_key,
                    'display_name' => $existing['display_name'],
                    'version'      => (int) $existing['version'] + 1,
                    'capabilities' => $clean_capabilities,
                ];
            }
        );
    }

    /**
     * Deactivate a custom definition without deleting or rewriting its
     * definition, capability rows, memberships, overrides or audit history.
     *
     * @throws ScopeRequiredException
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     * @throws CustomRoleManagementException
     *
     * @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool}
     */
    public function deactivate(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        string $role_key,
        int $expected_version
    ): array {
        return $this->transition_status(
            $actor_wp_user_id,
            $requested_clinic_id,
            $role_key,
            $expected_version,
            CustomRoleRepository::STATUS_INACTIVE,
            self::AUDIT_DEACTIVATED
        );
    }

    /**
     * Reactivate an existing definition after rechecking the actor's current
     * management authority and per-capability delegation limits.
     *
     * @throws ScopeRequiredException
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     * @throws CustomRoleManagementException
     *
     * @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool}
     */
    public function reactivate(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        string $role_key,
        int $expected_version
    ): array {
        return $this->transition_status(
            $actor_wp_user_id,
            $requested_clinic_id,
            $role_key,
            $expected_version,
            CustomRoleRepository::STATUS_ACTIVE,
            self::AUDIT_REACTIVATED
        );
    }

    /**
     * Terminal archival; zero references of any membership status are required.
     * A current-version repeat is a no-op, never another mutation or audit.
     *
     * @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool}
     */
    public function archive(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        string $role_key,
        int $expected_version
    ): array {
        return $this->transition_status(
            $actor_wp_user_id,
            $requested_clinic_id,
            $role_key,
            $expected_version,
            CustomRoleRepository::STATUS_ARCHIVED,
            self::AUDIT_ARCHIVED
        );
    }

    /**
     * Serialize lifecycle changes on the exact definition row. The versioned
     * status write and required append-only audit event share one transaction;
     * callers never delete a definition or its capability/assignment metadata.
     *
     * @return array{clinic_id:int,id:int,role_key:string,status:string,version:int,changed:bool}
     */
    private function transition_status(
        int $actor_wp_user_id,
        ?int $requested_clinic_id,
        string $role_key,
        int $expected_version,
        string $new_status,
        string $audit_action
    ): array {
        $clinic_id = $this->require_management_authority( $actor_wp_user_id, $requested_clinic_id );
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw CustomRoleManagementException::invalid_role_key();
        }

        return $this->db->transactional(
            function () use ( $actor_wp_user_id, $clinic_id, $role_key, $expected_version, $new_status, $audit_action ): array {
                $existing = $this->roles->definition_for_update( $clinic_id, $role_key );
                if ( $existing === null ) {
                    throw CustomRoleManagementException::not_found( $role_key );
                }

                // The initial check establishes the trusted Clinic before the
                // transaction. Repeat it after acquiring the row lock so a
                // manager whose membership/authority changed while waiting is
                // not allowed to mutate the definition.
                $this->require_management_authority( $actor_wp_user_id, $clinic_id, true );
                $this->authorization->authorizeForObject(
                    $actor_wp_user_id,
                    $clinic_id,
                    RolesAndCapabilities::CONFIG,
                    (int) $existing['clinic_id'],
                    true
                );

                $current_version = (int) $existing['version'];
                if ( $expected_version <= 0 || $current_version !== $expected_version ) {
                    throw CustomRoleManagementException::stale_version();
                }

                $result = [
                    'clinic_id' => $clinic_id,
                    'id'        => (int) $existing['id'],
                    'role_key'  => (string) $existing['role_key'],
                    'status'    => $new_status,
                    'version'   => $current_version,
                    'changed'   => false,
                ];
                if ( (string) $existing['status'] === $new_status ) {
                    return $result;
                }

                $current_status = (string) $existing['status'];
                if ( $current_status === CustomRoleRepository::STATUS_ARCHIVED ) {
                    throw CustomRoleManagementException::terminal_state();
                }
                if ( ! CustomRoleRepository::is_mutable_status( $current_status ) ) {
                    throw CustomRoleManagementException::invalid_state();
                }
                if ( $new_status === CustomRoleRepository::STATUS_ARCHIVED
                    && $this->roles->has_membership_references_for_update( $clinic_id, $role_key ) ) {
                    throw CustomRoleManagementException::membership_references();
                }

                if ( $new_status === CustomRoleRepository::STATUS_ACTIVE ) {
                    // Restoring role-derived authority is a fresh delegation:
                    // the manager must still hold each stored capability now.
                    $this->require_delegable_capabilities(
                        $actor_wp_user_id,
                        $clinic_id,
                        $existing['capabilities'],
                        true
                    );
                }

                $updated = $this->roles->transition_status(
                    $clinic_id,
                    (string) $existing['role_key'],
                    (string) $existing['status'],
                    $new_status,
                    $expected_version
                );
                if ( ! $updated ) {
                    if ( $this->db->wpdb()->last_error !== '' ) {
                        throw new \RuntimeException( 'custom role status update failed' );
                    }
                    throw CustomRoleManagementException::stale_version();
                }

                $next_version     = $current_version + 1;
                $before           = [
                    'role_key'     => (string) $existing['role_key'],
                    'display_name' => $existing['display_name'],
                    'status'       => (string) $existing['status'],
                    'version'      => $current_version,
                    'capabilities' => $existing['capabilities'],
                ];
                $after            = $before;
                $after['status']  = $new_status;
                $after['version'] = $next_version;

                $this->audit->log(
                    $audit_action,
                    [ 'wp_user_id' => $actor_wp_user_id, 'role' => 'clinic_manager' ],
                    self::RESOURCE_TYPE,
                    (int) $existing['id'],
                    null,
                    $before,
                    $after,
                    [ 'clinic_id' => $clinic_id ],
                    $clinic_id
                );

                $result['version'] = $next_version;
                $result['changed'] = true;

                return $result;
            }
        );
    }

    // ================= internals =================

    /**
     * Resolve the explicitly selected trusted Clinic and require effective management authority.
     * No sole-membership or first-Clinic fallback is permitted for role management.
     *
     * @throws ScopeRequiredException unauthenticated / missing Clinic selector / no durable membership / unknown Clinic / inactive Organization
     * @throws \ClinicCore\Application\Authorization\AuthorizationException suspended membership or denied permission
     */
    private function require_management_authority( int $actor_wp_user_id, ?int $requested_clinic_id, bool $for_update = false ): int {
        if ( $actor_wp_user_id <= 0 ) {
            throw new ScopeRequiredException( 'CLINIC_SCOPE_UNAVAILABLE', 'Trusted clinic context is not available.', [ 'reason' => 'unauthenticated' ], 403 );
        }
        if ( $requested_clinic_id === null || $requested_clinic_id <= 0 ) {
            throw new ScopeRequiredException( 'CLINIC_SCOPE_REQUIRED', 'An explicit Clinic selection is required.', [ 'reason' => 'explicit_clinic_required' ], 400 );
        }

        // The selector is validated against durable persistence; it is never
        // trusted by itself, and a forged value cannot conjure a membership.
        $scope = $this->scope->establish( $actor_wp_user_id, $requested_clinic_id, null, $for_update );

        $this->authorization->authorize( $actor_wp_user_id, $scope->clinicId, RolesAndCapabilities::CONFIG, $for_update ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.

        return $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ClinicScope::$clinicId is the existing published scope API.
    }

    /**
     * Durable object ownership: the definition must exist in the trusted Clinic,
     * and its persisted owner Clinic must agree with the authorization Clinic.
     *
     * @throws \ClinicCore\Application\Authorization\AuthorizationException
     * @throws CustomRoleManagementException
     *
     * @return array{clinic_id:int, id:int, role_key:string, display_name:string|null, status:string, version:int, capabilities:list<string>}
     */
    private function require_mutable_definition_for_update( int $actor_wp_user_id, int $clinic_id, string $role_key, ?int $expected_version ): array {
        if ( ! CustomRolePolicy::is_definable_role_key( $role_key ) ) {
            throw CustomRoleManagementException::invalid_role_key();
        }

        $definition = $this->roles->definition_for_update( $clinic_id, $role_key );
        if ( $definition === null ) {
            // Either the key does not exist at all, or it belongs to another
            // Clinic — both are non-addressable here (no cross-Clinic lookup).
            throw CustomRoleManagementException::not_found( $role_key );
        }

        // The owner Clinic value came from durable storage, not from the
        // request; equality alone never established trust.
        $this->authorization->authorizeForObject(
            $actor_wp_user_id,
            $clinic_id,
            RolesAndCapabilities::CONFIG,
            (int) $definition['clinic_id'],
            true
        );
        $this->require_management_authority( $actor_wp_user_id, $clinic_id, true );
        if ( $expected_version !== null && ( $expected_version <= 0 || $expected_version !== (int) $definition['version'] ) ) {
            throw CustomRoleManagementException::stale_version();
        }
        if ( (string) $definition['status'] === CustomRoleRepository::STATUS_ARCHIVED ) {
            throw CustomRoleManagementException::terminal_state();
        }
        if ( ! CustomRoleRepository::is_mutable_status( (string) $definition['status'] ) ) {
            throw CustomRoleManagementException::invalid_state();
        }

        return $definition;
    }

    /**
     * @throws CustomRoleManagementException
     */
    private function require_display_name( string $display_name ): string {
        if ( ! CustomRolePolicy::is_acceptable_display_name( $display_name ) ) {
            throw CustomRoleManagementException::invalid_display_name();
        }

        return CustomRolePolicy::sanitize_display_name( $display_name );
    }

    /**
     * Per-capability delegation gate (all-or-nothing). Two independent checks:
     *  (a) the capability must be an editable CPMS capability — unknown,
     *      WordPress, WooCommerce and sensitive capabilities fail here;
     *  (b) the capability must be within the editor's own effective Clinic
     *      authority — decided exclusively by AuthorizationService, so a
     *      membership DENY can never be bypassed by writing a custom role.
     *
     * @param array<array-key, mixed> $capabilities
     *
     * @throws CustomRoleManagementException
     *
     * @return list<string> unique, sorted, validated
     */
    private function require_delegable_capabilities( int $actor_wp_user_id, int $clinic_id, array $capabilities, bool $for_update = false ): array {
        $not_allowed   = [];
        $not_delegable = [];
        $clean         = [];

        foreach ( $capabilities as $capability ) {
            if ( ! is_string( $capability ) || ! CustomRolePolicy::is_editable_capability( $capability ) ) {
                $not_allowed[] = $capability;

                continue;
            }
            if ( ! $this->authorization->can( $actor_wp_user_id, $clinic_id, $capability, $for_update ) ) {
                $not_delegable[] = $capability;

                continue;
            }
            $clean[ $capability ] = true;
        }

        if ( $not_allowed !== [] ) {
            throw CustomRoleManagementException::capability_not_allowed( $not_allowed );
        }
        if ( $not_delegable !== [] ) {
            throw CustomRoleManagementException::capability_not_delegable( $not_delegable );
        }

        $list = array_keys( $clean );
        sort( $list );

        return $list;
    }
}

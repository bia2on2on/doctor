<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use InvalidArgumentException;
use RuntimeException;

/** Organization-scoped Clinic creation; this authority never grants Clinic/PHI access. */
final class OrganizationClinicProvisioningService {

    public const CAPABILITY = 'cpms_org_clinic_create';

    /**
     * Organization-scoped capability: Clinic profile updates (name/address/phone).
     *
     * Least privilege: separate from Clinic creation and from lifecycle transitions,
     * and each is evaluated with its own DENY > GRANT > Organization Manager preset
     * precedence in authorized() — a DENY on one never leaks into the others.
     */
    public const CAPABILITY_PROFILE_UPDATE = 'cpms_org_clinic_update';

    /**
     * Organization-scoped capability: Clinic lifecycle transitions
     * (suspend / reactivate). Suspension never grants broader access: it only stops
     * genuinely new demand at the booking/enrollment/slot-generation boundaries.
     */
    public const CAPABILITY_LIFECYCLE  = 'cpms_org_clinic_lifecycle';
    public const MEMBERSHIP_CAPABILITY = 'cpms_org_membership_manage';
    public const AUDIT_ACTION          = 'ORGANIZATION_CLINIC_PROVISIONED';

    /**
     * Organization-scoped capability: explicitly designate a Clinic manager (the
     * built-in cpms_manager Clinic membership) for a Clinic of this Organization.
     *
     * Least privilege: separate from create/update/lifecycle and resolved with its
     * own DENY > GRANT > Organization Manager preset precedence. It is the only way
     * Organization authority touches a Clinic membership, it is never implicit, and
     * it never re-activates or re-roles an existing (historical) membership.
     */
    public const CAPABILITY_CLINIC_MANAGER = 'cpms_org_clinic_manager_assign';

    public const MANAGER_AUDIT_ACTION = 'ORGANIZATION_CLINIC_MANAGER_DESIGNATED';

    public const CLINIC_MANAGER_ROLE_KEY = 'cpms_manager';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly AuditLogger $audit
    ) {
    }

    /** @return list<array{id:int,name:string}> */
    /**
     * Organizations reachable by the Clinic management screen: the UNION of the three
     * independent Clinic capabilities. Each capability is resolved with its own
     * DENY > GRANT > Organization-Manager preset, so an explicit DENY on one (for example
     * create) never erases a GRANT on another (update / lifecycle). This widens READ
     * reachability of the management screen only - every write re-authorizes itself per
     * operation - and it grants no WordPress Administrator implicit Clinic authority.
     *
     * @return list<array<string, mixed>>
     */
    public function management_organizations( int $actor_id ): array {
        $seen = [];
        $out  = [];
        foreach ( [ self::CAPABILITY, self::CAPABILITY_PROFILE_UPDATE, self::CAPABILITY_LIFECYCLE ] as $permission ) {
            foreach ( $this->organizations_for_manager( $actor_id, $permission ) as $organization ) {
                $organization_id = (int) ( $organization['id'] ?? 0 );
                if ( $organization_id <= 0 || isset( $seen[ $organization_id ] ) ) {
                    continue;
                }
                $seen[ $organization_id ] = true;
                $out[]                    = $organization;
            }
        }

        return $out;
    }

    /**
     * Read-only UI probe: is this actor authorized for ONE specific Clinic capability in
     * this Organization? DENY > GRANT > preset stays the resolver's, per capability, so a
     * DENY is never masked by another capability's GRANT. Used only to hide controls the
     * actor may not use; it never widens a write path (each write re-authorizes itself)
     * and confers no WordPress Administrator shortcut.
     */
    public function can( int $actor_id, int $organization_id, string $permission ): bool {
        if ( $actor_id <= 0 || $organization_id <= 0 || '' === $permission ) {
            return false;
        }

        return $this->authorized( $actor_id, $organization_id, false, $permission );
    }

    public function organizations_for_manager( int $actor_id, string $permission = self::CAPABILITY ): array {

        if ( $actor_id <= 0 ) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT o.id, o.name FROM ' . $this->db->table( 'cpms_organizations' ) . ' o'
            . ' JOIN ' . $this->db->table( 'cpms_organization_memberships' ) . ' m ON m.organization_id = o.id'
            . ' WHERE m.wp_user_id = %d AND m.status = %s AND m.role_key = %s AND o.status = %s'
            . ' ORDER BY o.name, o.id',
            [$actor_id, 'active', 'organization_manager', 'active']
        );

        $out = [];
        foreach ( $rows as $row ) {
            $organization_id = (int) $row['id'];
            if ( $this->authorized( $actor_id, $organization_id, false, $permission ) ) {
                $out[] = [
                    'id'   => $organization_id,
                    'name' => (string) $row['name'],
                ];
            }
        }

        return $out;
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function clinics_for_manager( int $actor_id ): array {

        if ( $actor_id <= 0 ) {
            return [];
        }

        $members = $this->db->fetchAll(
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_organization_memberships' )
            . ' WHERE wp_user_id = %d AND status = %s AND role_key = %s ORDER BY organization_id',
            [$actor_id, 'active', 'organization_manager']
        );
        $out     = [];

        foreach ( $members as $member ) {
            $organization_id = (int) $member['organization_id'];
            if ( ! $this->authorized( $actor_id, $organization_id, false ) ) {
                continue;
            }

            $clinics = $this->db->fetchAll(
                'SELECT id, name, slug FROM ' . $this->db->table( 'cpms_clinics' )
                . ' WHERE organization_id = %d ORDER BY name, id',
                [$organization_id]
            );
            foreach ( $clinics as $clinic ) {
                $out[] = [
                    'id'   => (int) $clinic['id'],
                    'name' => (string) $clinic['name'],
                    'slug' => (string) $clinic['slug'],
                ];
            }
        }

        return $out;
    }

    /**
     * Post-onboarding Clinic provisioning (Organizations page): the new Clinic is
     * active immediately, exactly as before.
     *
     * @return array{clinic_id:int,location_id:int,audit_id:int}
     */
    public function provision( int $actor_id, int $organization_id, string $name, string $slug, string $timezone, ?string $location_name = null, ?string $address = null, ?string $phone = null ): array {
        return $this->provision_clinic( false, $actor_id, $organization_id, $name, $slug, $timezone, $location_name, $address, $phone );
    }

    /**
     * Facility-setup provisioning: the Clinic row is INSERTED with status
     * 'suspended' (held: no new demand), so it is never active — not even inside
     * the creating transaction — until an explicit, authorized activation through
     * set_clinic_status(). Requires both cpms_org_clinic_create and
     * cpms_org_clinic_lifecycle (DENY > GRANT > preset) under the Organization row
     * lock; the held status is read back before the transaction can commit and is
     * recorded in the provisioning audit row.
     *
     * @return array{clinic_id:int,location_id:int,audit_id:int}
     */
    public function provision_held( int $actor_id, int $organization_id, string $name, string $slug, string $timezone, ?string $location_name = null, ?string $address = null, ?string $phone = null ): array {
        return $this->provision_clinic( true, $actor_id, $organization_id, $name, $slug, $timezone, $location_name, $address, $phone );
    }

    /** @return array{clinic_id:int,location_id:int,audit_id:int} */
    private function provision_clinic(
        bool $held,
        int $actor_id,
        int $organization_id,
        string $name,
        string $slug,
        string $timezone,
        ?string $location_name = null,
        ?string $address = null,
        ?string $phone = null
    ): array {
        if ( $actor_id <= 0 || $organization_id <= 0 ) {
            throw new InvalidArgumentException( 'invalid authorization context' );
        }

        $name = trim( $name );
        $slug = strtolower( trim( $slug ) );
        if ( $name === '' || mb_strlen( $name ) > 190 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) || strlen( $slug ) > 190 ) {
            throw new InvalidArgumentException( 'invalid clinic attributes' );
        }
        if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            throw new InvalidArgumentException( 'invalid timezone' );
        }

        $location_name = trim( (string) ( $location_name ?? $name ) );
        if ( $location_name === '' || mb_strlen( $location_name ) > 190 ) {
            throw new InvalidArgumentException( 'invalid location name' );
        }
        foreach ( ['address' => $address, 'phone' => $phone] as $key => $value ) {
            $max_length = $key === 'address' ? 255 : 32;
            if ( $value !== null && mb_strlen( $value ) > $max_length ) {
                throw new InvalidArgumentException( 'invalid ' . $key );
            }
        }

        return $this->db->transactional(
            function () use (
                $held,
                $actor_id,
                $organization_id,
                $name,
                $slug,
                $timezone,
                $location_name,
                $address,
                $phone
            ): array {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [$organization_id]
                );
                if (
                    $organization === null
                    || (string) $organization['status'] !== 'active'
                    || ! $this->authorized( $actor_id, $organization_id, true )
                    || ( $held && ! $this->authorized( $actor_id, $organization_id, true, self::CAPABILITY_LIFECYCLE ) )
                ) {
                    throw new RuntimeException( 'organization provisioning denied' );
                }

                if ( $this->db->fetchValue(
                    'SELECT id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE slug = %s LIMIT 1',
                    [$slug]
                ) !== null ) {
                    throw new RuntimeException( 'clinic slug unavailable' );
                }

                $now        = $this->db->nowUtcSql();
                $clinic_row = [
                    'organization_id' => $organization_id,
                    'name'            => $name,
                    'slug'            => $slug,
                    'timezone'        => $timezone,
                    'address'         => $address,
                    'phone'           => $phone,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
                if ( $held ) {
                    // Created held: the row never exists with status 'active'.
                    $clinic_row['status']                       = 'suspended';
                    $clinic_row['status_updated_at']            = $now;
                    $clinic_row['status_updated_by_wp_user_id'] = $actor_id;
                }
                if ( ! $this->db->insert( 'cpms_clinics', $clinic_row ) ) {
                    throw new RuntimeException( 'clinic creation failed' );
                }
                $clinic_id = $this->db->wpdb_last_insert_id();
                if ( $clinic_id <= 0 ) {
                    throw new RuntimeException( 'clinic id unavailable' );
                }
                if ( $held && 'suspended' !== (string) $this->db->fetchValue(
                    'SELECT status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d AND organization_id = %d LIMIT 1',
                    [ $clinic_id, $organization_id ]
                ) ) {
                    throw new RuntimeException( 'held clinic readback failed' );
                }

                if ( ! $this->db->insert(
                    'cpms_locations',
                    [
                        'clinic_id'  => $clinic_id,
                        'name'       => $location_name,
                        'slug'       => 'main',
                        'address'    => $address,
                        'phone'      => $phone,
                        'timezone'   => $timezone,
                        'is_primary' => 1,
                        'is_active'  => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                ) ) {
                    throw new RuntimeException( 'primary location creation failed' );
                }
                $location_id = $this->db->wpdb_last_insert_id();
                if ( $location_id <= 0 ) {
                    throw new RuntimeException( 'location id unavailable' );
                }

                $audit_after = [
                    'organization_id' => $organization_id,
                    'slug'            => $slug,
                    'timezone'        => $timezone,
                ];
                $audit_meta  = [ 'location_id' => $location_id ];
                if ( $held ) {
                    $audit_after['status'] = 'suspended';
                    $audit_meta['held']    = true;
                }
                $audit_id = $this->audit->log(
                    self::AUDIT_ACTION,
                    ['wp_user_id' => $actor_id, 'role' => 'organization_manager'],
                    'clinic',
                    (int) $clinic_id,
                    null,
                    null,
                    $audit_after,
                    $audit_meta,
                    (int) $clinic_id
                );

                return [
                    'clinic_id'   => (int) $clinic_id,
                    'location_id' => (int) $location_id,
                    'audit_id'    => $audit_id,
                ];
            }
        );
    }

    /** Explicit organization-scoped manager grant/revocation; last active manager is protected. */
    public function set_manager( int $actor_id, int $organization_id, int $target_user_id, bool $active ): int {

        if ( $actor_id <= 0 || $organization_id <= 0 || $target_user_id <= 0 || get_userdata( $target_user_id ) === false ) {
            throw new InvalidArgumentException( 'invalid membership subject' );
        }

        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $target_user_id, $active ): int {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [$organization_id]
                );
                if (
                    $organization === null
                    || $organization['status'] !== 'active'
                    || ! $this->authorized( $actor_id, $organization_id, true, self::MEMBERSHIP_CAPABILITY )
                ) {
                    throw new RuntimeException( 'organization membership management denied' );
                }

                $membership_table = $this->db->table( 'cpms_organization_memberships' );
                $target           = $this->db->fetchRowForUpdate(
                    'SELECT id, status, role_key FROM ' . $membership_table
                    . ' WHERE organization_id = %d AND wp_user_id = %d LIMIT 1',
                    [$organization_id, $target_user_id]
                );
                if ( $target !== null && $target['role_key'] !== 'organization_manager' ) {
                    throw new RuntimeException( 'organization membership role conflict' );
                }
                if ( $target === null && ! $active ) {
                    throw new RuntimeException( 'organization membership not found' );
                }
                if ( $target !== null && $active && $target['status'] === 'active' ) {
                    return (int) $target['id'];
                }
                if ( $target !== null && ! $active && $target['status'] === 'suspended' ) {
                    return (int) $target['id'];
                }

                if ( ! $active && $target !== null && $target['status'] === 'active' && $target['role_key'] === 'organization_manager' ) {
                    $manager_count = (int) $this->db->fetchValue(
                        'SELECT COUNT(*) FROM ' . $membership_table
                        . ' WHERE organization_id = %d AND status = %s AND role_key = %s',
                        [$organization_id, 'active', 'organization_manager']
                    );
                    if ( $manager_count <= 1 ) {
                        throw new RuntimeException( 'cannot remove last active organization manager' );
                    }
                }

                $now = $this->db->nowUtcSql();
                if ( $active ) {
                    if ( $target === null ) {
                        if ( ! $this->db->insert(
                            'cpms_organization_memberships',
                            [
                                'organization_id'       => $organization_id,
                                'wp_user_id'            => $target_user_id,
                                'role_key'              => 'organization_manager',
                                'status'                => 'active',
                                'created_by_wp_user_id' => $actor_id,
                                'created_at'            => $now,
                                'updated_at'            => $now,
                            ]
                        ) ) {
                            throw new RuntimeException( 'organization membership grant failed' );
                        }
                        $membership_id = $this->db->wpdb_last_insert_id();
                        if ( $membership_id <= 0 ) {
                            throw new RuntimeException( 'organization membership id unavailable' );
                        }
                    } else {
                        $membership_id = (int) $target['id'];
                        $updated       = $this->db->update(
                            'cpms_organization_memberships',
                            [
                                'role_key'   => 'organization_manager',
                                'status'     => 'active',
                                'updated_at' => $now,
                            ],
                            ['id' => $membership_id],
                            true
                        );
                        if ( $updated !== 1 ) {
                            throw new RuntimeException( 'organization membership grant failed' );
                        }
                    }
                } else {
                    if ( $target === null ) {
                        throw new RuntimeException( 'organization membership not found' );
                    }
                    $membership_id = (int) $target['id'];
                    $updated       = $this->db->update(
                        'cpms_organization_memberships',
                        [
                            'status'     => 'suspended',
                            'updated_at' => $now,
                        ],
                        ['id' => $membership_id],
                        true
                    );
                    if ( $updated !== 1 ) {
                        throw new RuntimeException( 'organization membership revoke failed' );
                    }
                }

                $this->audit->log(
                    $active ? 'ORGANIZATION_MANAGER_GRANTED' : 'ORGANIZATION_MANAGER_REVOKED',
                    ['wp_user_id' => $actor_id, 'role' => 'organization_manager'],
                    'organization',
                    $organization_id,
                    null,
                    null,
                    null,
                    ['target_wp_user_id' => $target_user_id, 'active' => $active],
                    null,
                    true
                );

                return (int) $membership_id;
            }
        );
    }

    /**
     * Explicitly activate the first Organization Manager by Owner-approved WP admin bootstrap.
     * This authority is limited to this bootstrap operation and creates no Clinic capabilities.
     *
     * @return array{membership_id:int,noop:bool,audit_id:int|null}
     */
    public function bootstrap_owner( int $actor_id, int $organization_id, int $target_user_id ): array {

        if (
            $actor_id <= 0
            || $actor_id !== (int) get_current_user_id()
            || ! user_can( $actor_id, 'manage_options' )
            || $organization_id <= 0
            || $target_user_id <= 0
            || get_userdata( $target_user_id ) === false
        ) {
            throw new RuntimeException( 'organization manager bootstrap denied' );
        }

        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $target_user_id ): array {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [$organization_id]
                );
                if ( $organization === null || $organization['status'] !== 'active' ) {
                    throw new RuntimeException( 'organization manager bootstrap denied' );
                }

                // The Organization lock serializes competing first-manager claims.
                // Lock every assignment: bootstrap never silently coexists with an
                // ambiguous Organization-level role or replaces a prior manager.
                $assignments = $this->db->fetchAllForUpdate(
                    'SELECT id, wp_user_id, status, role_key FROM ' . $this->db->table( 'cpms_organization_memberships' )
                    . ' WHERE organization_id = %d ORDER BY id',
                    [$organization_id]
                );
                if (
                    count( $assignments ) === 1
                    && (int) $assignments[0]['wp_user_id'] === $target_user_id
                    && $assignments[0]['role_key'] === 'organization_manager'
                    && $assignments[0]['status'] === 'active'
                ) {
                    return [
                        'membership_id' => (int) $assignments[0]['id'],
                        'noop'          => true,
                        'audit_id'      => null,
                    ];
                }
                if ( $assignments !== [] ) {
                    throw new RuntimeException( 'organization manager bootstrap conflict' );
                }

                $now = $this->db->nowUtcSql();
                if ( ! $this->db->insert(
                    'cpms_organization_memberships',
                    [
                        'organization_id'       => $organization_id,
                        'wp_user_id'            => $target_user_id,
                        'role_key'              => 'organization_manager',
                        'status'                => 'active',
                        'created_by_wp_user_id' => $actor_id,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ]
                ) ) {
                    throw new RuntimeException( 'organization manager bootstrap membership failed' );
                }
                $membership_id = $this->db->wpdb_last_insert_id();
                if ( $membership_id <= 0 ) {
                    throw new RuntimeException( 'organization manager bootstrap membership failed' );
                }

                $audit_id = $this->audit->log(
                    'ORGANIZATION_MANAGER_BOOTSTRAPPED',
                    ['wp_user_id' => $actor_id, 'role' => 'wordpress_administrator'],
                    'organization',
                    $organization_id,
                    null,
                    null,
                    ['target_wp_user_id' => $target_user_id, 'role_key' => 'organization_manager', 'status' => 'active'],
                    ['authority' => 'owner_approved_wordpress_administrator_bootstrap'],
                    null,
                    true
                );

                return [
                    'membership_id' => (int) $membership_id,
                    'noop'          => false,
                    'audit_id'      => $audit_id,
                ];
            }
        );
    }

    private function authorized( int $actor_id, int $organization_id, bool $lock, string $permission = self::CAPABILITY ): bool {

        $read       = $lock ? 'fetchRowForUpdate' : 'fetchRow';
        $membership = $this->db->$read(
            'SELECT id, role_key, status FROM ' . $this->db->table( 'cpms_organization_memberships' )
            . ' WHERE organization_id = %d AND wp_user_id = %d LIMIT 1',
            [$organization_id, $actor_id]
        );
        if (
            $membership === null
            || $membership['status'] !== 'active'
            || $membership['role_key'] !== 'organization_manager'
        ) {
            return false;
        }

        $read_all  = $lock ? 'fetchAllForUpdate' : 'fetchAll';
        $overrides = $this->db->$read_all(
            'SELECT capability, effect FROM ' . $this->db->table( 'cpms_organization_membership_capabilities' )
            . ' WHERE membership_id = %d',
            [ (int) $membership['id'] ]
        );
        foreach ( $overrides as $override ) {
            if ( $override['capability'] === $permission && $override['effect'] === 'deny' ) {
                return false;
            }
        }
        foreach ( $overrides as $override ) {
            if ( $override['capability'] === $permission && $override['effect'] === 'grant' ) {
                return true;
            }
        }

        // Organization Manager preset is Organization-scoped only; Clinic AuthorizationService is untouched.
        return true;
    }

    /**
     * Post-onboarding Clinic settings update under explicit Organization authority.
     *
     * Only name/address/phone/updated_at may mutate; organization_id/slug/timezone/created_at/status are preserved.
     * Uses Organization row lock, validates ownership, fail-closed on validation/auth, audit on real change only.
     *
     * @param array<string,mixed> $input {name, address, phone}
     * @return array{clinic_id:int, name:string, address:?string, phone:?string, updated_at:string, noop:bool, audit_id:int|null}
     */
    public function update_clinic_profile( int $actor_id, int $organization_id, int $clinic_id, array $input ): array {

        if ( $actor_id <= 0 || $organization_id <= 0 || $clinic_id <= 0 ) {
            throw new InvalidArgumentException( 'invalid clinic update context' );
        }

        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $clinic_id, $input ): array {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [$organization_id]
                );
                if ( $organization === null || (string) $organization['status'] !== 'active' || ! $this->authorized( $actor_id, $organization_id, true, self::CAPABILITY_PROFILE_UPDATE ) ) {
                    throw new RuntimeException( 'organization clinic update denied' );
                }

                $current = $this->db->fetchRowForUpdate(
                    'SELECT id, organization_id, slug, timezone, created_at, updated_at, name, address, phone, status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [$clinic_id]
                );
                if ( $current === null || (int) $current['organization_id'] !== $organization_id ) {
                    throw new RuntimeException( 'clinic not found in organization' );
                }

                // Authorization precedes validation of tenant-specific input: an
                // unauthorized or cross-Organization actor receives the same generic
                // denial regardless of payload shape, so validation messages never
                // become a cross-tenant attribute oracle.
                $raw_name    = $input['name'] ?? null;
                $raw_address = $input['address'] ?? null;
                $raw_phone   = $input['phone'] ?? null;

                $name    = is_string( $raw_name ) ? trim( $raw_name ) : ( is_scalar( $raw_name ) ? trim( (string) $raw_name ) : '' );
                $address = $raw_address === null ? null : trim( (string) $raw_address );
                $phone   = $raw_phone === null ? null : trim( (string) $raw_phone );
                if ( $address === '' ) {
                    $address = null;
                }
                if ( $phone === '' ) {
                    $phone = null;
                }

                $errors = [];
                if ( $name === '' ) {
                    $errors['name'] = 'نام کلینیک الزامی است';
                } elseif ( mb_strlen( $name ) > 190 ) {
                    $errors['name'] = 'نام کلینیک حداکثر ۱۹۰ کاراکتر است';
                }
                if ( $address !== null && mb_strlen( $address ) > 255 ) {
                    $errors['address'] = 'آدرس حداکثر ۲۵۵ کاراکتر است';
                }
                if ( $phone !== null && mb_strlen( $phone ) > 32 ) {
                    $errors['phone'] = 'تلفن حداکثر ۳۲ کاراکتر است';
                }
                if ( $errors !== [] ) {
                    throw new InvalidArgumentException( 'invalid clinic profile attributes: ' . wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ) );
                }

                $old_name    = (string) ( $current['name'] ?? '' );
                $old_address = $current['address'] !== null ? (string) $current['address'] : null;
                $old_phone   = $current['phone'] !== null ? (string) $current['phone'] : null;
                if ( $old_address === '' ) {
                    $old_address = null;
                }
                if ( $old_phone === '' ) {
                    $old_phone = null;
                }

                if ( $old_name === $name && $old_address === $address && $old_phone === $phone ) {
                    return [
                        'clinic_id'  => $clinic_id,
                        'name'       => $old_name,
                        'address'    => $old_address,
                        'phone'      => $old_phone,
                        'updated_at' => (string) ( $current['updated_at'] ?? '' ),
                        'noop'       => true,
                        'audit_id'   => null,
                    ];
                }

                $now     = $this->db->nowUtcSql();
                $updated = $this->db->update(
                    'cpms_clinics',
                    [
                        'name'       => $name,
                        'address'    => $address,
                        'phone'      => $phone,
                        'updated_at' => $now,
                    ],
                    ['id' => $clinic_id],
                    true
                );
                if ( $updated !== 1 ) {
                    throw new RuntimeException( 'clinic profile update failed' );
                }

                $after = $this->db->fetchRow(
                    'SELECT updated_at, organization_id, slug, timezone, created_at, status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [$clinic_id]
                );
                if ( $after === null || (int) $after['organization_id'] !== $organization_id || (string) $after['slug'] !== (string) $current['slug'] || (string) $after['timezone'] !== (string) $current['timezone'] || (string) $after['created_at'] !== (string) $current['created_at'] ) {
                    throw new RuntimeException( 'clinic protected columns mutated' );
                }

                $audit_id = $this->audit->log(
                    'CLINIC_PROFILE_UPDATED',
                    ['wp_user_id' => $actor_id, 'role' => 'organization_manager'],
                    'clinic',
                    $clinic_id,
                    null,
                    ['clinic.name' => $old_name, 'clinic.address' => $old_address, 'clinic.phone' => $old_phone],
                    ['clinic.name' => $name, 'clinic.address' => $address, 'clinic.phone' => $phone, 'clinic.updated_at' => $now],
                    ['op' => 'clinic_profile_update', 'organization_id' => $organization_id],
                    $clinic_id
                );

                return [
                    'clinic_id'  => $clinic_id,
                    'name'       => $name,
                    'address'    => $address,
                    'phone'      => $phone,
                    'updated_at' => $now,
                    'noop'       => false,
                    'audit_id'   => $audit_id,
                ];
            }
        );
    }

    /**
     * Secure Clinic activation/deactivation with history preservation.
     *
     * Uses Organization row lock to serialize lifecycle writes. Clinic must belong to
     * Organization and Organization must be active. No medical/financial/audit/backup rows are removed.
     * Idempotent when already in desired state (no second audit event). Concurrency is serialized via
     * Clinic row lock; audit failure rolls back the status mutation.
     *
     * @return array{clinic_id:int, status:string, noop:bool, audit_id:int|null}
     */
    public function set_clinic_status( int $actor_id, int $organization_id, int $clinic_id, bool $active ): array {

        if ( $actor_id <= 0 || $organization_id <= 0 || $clinic_id <= 0 ) {
            throw new InvalidArgumentException( 'invalid clinic lifecycle context' );
        }
        $desired = $active ? 'active' : 'suspended';

        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $clinic_id, $active, $desired ): array {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [$organization_id]
                );
                if ( $organization === null || (string) $organization['status'] !== 'active' || ! $this->authorized( $actor_id, $organization_id, true, self::CAPABILITY_LIFECYCLE ) ) {
                    throw new RuntimeException( 'organization clinic lifecycle denied' );
                }

                $current = $this->db->fetchRowForUpdate(
                    'SELECT id, organization_id, status, name, slug FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [$clinic_id]
                );
                if ( $current === null || (int) $current['organization_id'] !== $organization_id ) {
                    throw new RuntimeException( 'clinic not found in organization' );
                }

                $before = (string) ( $current['status'] ?? 'active' );
                // Backward compatibility: older rows without status column default to active inside migration; here treat null as active.
                if ( $before === '' ) {
                    $before = 'active';
                }
                if ( $before === $desired ) {
                    return [
                        'clinic_id' => $clinic_id,
                        'status'    => $desired,
                        'noop'      => true,
                        'audit_id'  => null,
                    ];
                }

                $now     = $this->db->nowUtcSql();
                $updated = $this->db->update(
                    'cpms_clinics',
                    [
                        'status'                       => $desired,
                        'status_updated_at'            => $now,
                        'status_updated_by_wp_user_id' => $actor_id,
                        'updated_at'                   => $now,
                    ],
                    ['id' => $clinic_id, 'organization_id' => $organization_id],
                    true
                );
                if ( $updated !== 1 ) {
                    throw new RuntimeException( 'clinic lifecycle update failed' );
                }

                $audit_id = $this->audit->log(
                    $active ? 'CLINIC_REACTIVATED' : 'CLINIC_DEACTIVATED',
                    ['wp_user_id' => $actor_id, 'role' => 'organization_manager'],
                    'clinic',
                    $clinic_id,
                    null,
                    ['clinic.status' => $before],
                    ['clinic.status' => $desired, 'clinic.status_updated_at' => $now],
                    ['op' => $active ? 'clinic_reactivate' : 'clinic_deactivate', 'organization_id' => $organization_id],
                    $clinic_id
                );

                // Verify readback preserves organization/clinic invariants after commit
                $readback = $this->db->fetchRow(
                    'SELECT organization_id, status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [$clinic_id]
                );
                if ( $readback === null || (int) $readback['organization_id'] !== $organization_id || (string) $readback['status'] !== $desired ) {
                    throw new RuntimeException( 'clinic lifecycle readback failed' );
                }

                return [
                    'clinic_id' => $clinic_id,
                    'status'    => $desired,
                    'noop'      => false,
                    'audit_id'  => $audit_id,
                ];
            }
        );
    }

    /**
     * Explicit Clinic manager designation under Organization authority.
     *
     * Lock order matches the other management writes: Organization row, then the
     * Clinic row. The Clinic must belong to the Organization (active or suspended:
     * suspension is a new-demand control, administration continues). The target must
     * be an existing WordPress user; WordPress Administrator status is irrelevant in
     * both directions. An existing ACTIVE cpms_manager membership is an idempotent
     * no-op (no second audit row); any other existing membership (suspended or a
     * different role) is refused so historical membership state is never overwritten.
     * The first-setup marker of the Clinic is consumed (monotonic) because the Clinic
     * now has administration evidence. Membership + marker + audit commit together.
     *
     * Authorize before identifying: only the caller's own context is validated up
     * front. Nothing about the TARGET user (id shape or existence) is evaluated
     * until the exact cpms_org_clinic_manager_assign authority (DENY > GRANT >
     * preset) and the Clinic's ownership are established under the Organization row
     * lock, so a caller without that authority receives the same refusal for an
     * existing, a nonexistent and an invalid user id (no account enumeration).
     *
     * @return array{clinic_id:int, membership_id:int, noop:bool, audit_id:int|null}
     */
    public function designate_clinic_manager( int $actor_id, int $organization_id, int $clinic_id, int $target_user_id ): array {
        if ( $actor_id <= 0 || $organization_id <= 0 || $clinic_id <= 0 ) {
            throw new InvalidArgumentException( 'invalid clinic manager designation context' );
        }

        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $clinic_id, $target_user_id ): array {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [ $organization_id ]
                );
                if (
                    null === $organization
                    || 'active' !== (string) $organization['status']
                    || ! $this->authorized( $actor_id, $organization_id, true, self::CAPABILITY_CLINIC_MANAGER )
                ) {
                    throw new RuntimeException( 'organization clinic manager designation denied' );
                }

                $clinic = $this->db->fetchRowForUpdate(
                    'SELECT id, organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [ $clinic_id ]
                );
                if ( null === $clinic || (int) $clinic['organization_id'] !== $organization_id ) {
                    throw new RuntimeException( 'clinic not found in organization' );
                }

                // Only an authorized caller reaches the target identity (read-only
                // lookup; no WordPress write happens inside this transaction).
                if ( $target_user_id <= 0 || false === get_userdata( $target_user_id ) ) {
                    throw new InvalidArgumentException( 'clinic manager user not found' );
                }

                $existing = $this->db->fetchRowForUpdate(
                    'SELECT id, role_key, status FROM ' . $this->db->table( 'cpms_clinic_memberships' )
                    . ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
                    [ $clinic_id, $target_user_id ]
                );
                if ( null !== $existing ) {
                    if ( 'active' === (string) $existing['status'] && self::CLINIC_MANAGER_ROLE_KEY === (string) $existing['role_key'] ) {
                        return [
                            'clinic_id'     => $clinic_id,
                            'membership_id' => (int) $existing['id'],
                            'noop'          => true,
                            'audit_id'      => null,
                        ];
                    }
                    throw new RuntimeException( 'clinic membership already exists' );
                }

                $now = $this->db->nowUtcSql();
                if ( ! $this->db->insert(
                    'cpms_clinic_memberships',
                    [
                        'clinic_id'             => $clinic_id,
                        'wp_user_id'            => $target_user_id,
                        'role_key'              => self::CLINIC_MANAGER_ROLE_KEY,
                        'scope_mode'            => 'clinic',
                        'status'                => 'active',
                        'is_primary'            => 0,
                        'invited_by_wp_user_id' => $actor_id,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ]
                ) ) {
                    throw new RuntimeException( 'clinic manager membership insert failed' );
                }
                $membership_id = $this->db->wpdb_last_insert_id();
                if ( $membership_id <= 0 ) {
                    throw new RuntimeException( 'clinic manager membership id unavailable' );
                }

                $this->db->query(
                    'UPDATE ' . $this->db->table( 'cpms_clinics' ) . ' SET first_setup_claimed_at = %s'
                    . ' WHERE id = %d AND first_setup_claimed_at IS NULL',
                    [ $now, $clinic_id ]
                );

                $audit_id = $this->audit->log(
                    self::MANAGER_AUDIT_ACTION,
                    [
                        'wp_user_id' => $actor_id,
                        'role'       => 'organization_manager',
                    ],
                    'clinic',
                    $clinic_id,
                    null,
                    null,
                    [
                        'membership_id' => (int) $membership_id,
                        'wp_user_id'    => $target_user_id,
                        'role_key'      => self::CLINIC_MANAGER_ROLE_KEY,
                    ],
                    [
                        'op'              => 'clinic_manager_designate',
                        'organization_id' => $organization_id,
                    ],
                    $clinic_id
                );

                return [
                    'clinic_id'     => $clinic_id,
                    'membership_id' => (int) $membership_id,
                    'noop'          => false,
                    'audit_id'      => (int) $audit_id,
                ];
            }
        );
    }

    /**
     * List Clinics directly via Organization authority (no Clinic membership needed).
     *
     * @return list<array{id:int, organization_id:int, name:string, slug:string, timezone:string, status:string}>
     */
    public function clinics_for_organization( int $actor_id, int $organization_id ): array {

        if ( $actor_id <= 0 || $organization_id <= 0 ) {
            return [];
        }
        // Read for the management UI: authorized by ANY of the three separate
        // Organization Clinic capabilities, each still subject to its own DENY.
        $can_read = $this->authorized( $actor_id, $organization_id, false, self::CAPABILITY )
            || $this->authorized( $actor_id, $organization_id, false, self::CAPABILITY_PROFILE_UPDATE )
            || $this->authorized( $actor_id, $organization_id, false, self::CAPABILITY_LIFECYCLE );
        if ( ! $can_read ) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, organization_id, name, slug, timezone, COALESCE(status, %s) AS status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE organization_id = %d ORDER BY status, name, id',
            ['active', $organization_id]
        );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'id'              => (int) $row['id'],
                'organization_id' => (int) $row['organization_id'],
                'name'            => (string) $row['name'],
                'slug'            => (string) $row['slug'],
                'timezone'        => (string) $row['timezone'],
                'status'          => (string) ( $row['status'] ?? 'active' ),
            ];
        }

        return $out;
    }
}

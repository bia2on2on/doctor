<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Authorization\AuthorizationService;
use ClinicCore\Application\Booking\ScheduleService;
use ClinicCore\Application\Location\LocationException;
use ClinicCore\Application\Location\LocationService;
use ClinicCore\Application\Membership\MembershipService;
use ClinicCore\Application\Scope\ClinicScope;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\LocationRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\SettingsFactory;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Top-down, facility-adaptive Organization setup for multi-Clinic facilities
 * (hospital outpatient network, medical building).
 *
 * Tenant model (unchanged): Organization → Clinic → Location. This service adds
 * no tenant layer and no table. The durable setup plan is wizard state stored in
 * one installation option per Organization; it records only identifiers of real
 * entities created through the existing services and descriptive metadata:
 *  - a physical site/building is descriptive metadata (label + address). It is
 *    never a Clinic, never a Location and never schedulable by itself; a Clinic's
 *    Locations copy the chosen site's address;
 *  - Clinic + primary Location creation goes through
 *    OrganizationClinicProvisioningService::provision() (Organization authority);
 *  - each Clinic's manager is designated explicitly (Organization authority,
 *    cpms_org_clinic_manager_assign) — never inferred, never implicit;
 *  - consultation rooms, clinicians, staff roles (explicit Clinic-wide or
 *    Location-scoped access, DENY-only overrides), weekly schedules and the
 *    booking policy are Clinic-scoped writes. They require the actor's own
 *    ACTIVE membership + CONFIG in that Clinic (AuthorizationService, DENY >
 *    GRANT > preset); Organization authority alone never configures a Clinic
 *    and never reads its patients.
 *
 * Reliability: every create is idempotent per server-issued request key (a
 * deterministic slug is derived from it, so a retry after a lost response adopts
 * the already-created row instead of duplicating it). Clinics created here are
 * held suspended (no new patient demand) until the consolidated review passes and
 * the actor explicitly activates the facility.
 */
final class FacilitySetupService {

    public const OPTION_PREFIX = 'cpms_facility_setup_plan_';

    /** @var list<string> */
    public const FLOWS = [ 'hospital_network', 'medical_building' ];

    /**
     * Built-in staff roles assignable by the staff step (reception, finance).
     * Clinic managers come from the Organization designation step, clinicians
     * from the clinician step; custom roles stay in the existing management page.
     *
     * @var list<string>
     */
    public const STAFF_ROLES = [ RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::ROLE_ACCOUNTANT ];

    public const STAFF_AUDIT_ACTION = 'FACILITY_STAFF_ROLE_ASSIGNED';

    /** Organization-level plan change (identity, outpatient scope, sites, Clinic/room records, activation). */
    public const PLAN_AUDIT_ACTION = 'FACILITY_SETUP_PLAN_CHANGED';

    /**
     * WordPress capability that opens each built-in role's CPMS screens.
     *
     * Admin ENTRY only: the Clinic membership stays the sole source of Clinic
     * authority, and no facility step changes anyone's WordPress role — an
     * account without the matching WordPress role is reported, never promoted.
     */
    public const ENTRY_CAPABILITIES = [
        RolesAndCapabilities::ROLE_MANAGER    => RolesAndCapabilities::CONFIG,
        RolesAndCapabilities::ROLE_DOCTOR     => RolesAndCapabilities::QUEUE_READ,
        RolesAndCapabilities::ROLE_SECRETARY  => RolesAndCapabilities::QUEUE_READ,
        RolesAndCapabilities::ROLE_ACCOUNTANT => RolesAndCapabilities::FINANCE_READ,
    ];

    private const MAX_SITES   = 20;
    private const MAX_CLINICS = 50;
    private const MAX_ROOMS   = 50;

    private const REQUEST_KEY_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly OrganizationClinicProvisioningService $provisioning,
        private readonly LocationService $locations,
        private readonly LocationRepository $location_rows,
        private readonly AuthorizationService $authz,
        private readonly MembershipService $membership_service,
        private readonly MembershipRepository $membership_rows,
        private readonly ClinicianRepository $clinicians,
        private readonly ScheduleService $schedules,
        private readonly SettingsFactory $settings,
        private readonly ?AuditLogger $audit = null
    ) {
    }

    /**
     * Whether a WordPress account can open the CPMS screens of one built-in role
     * (admin entry only — never Clinic authority, which stays with the membership).
     */
    public static function has_admin_entry( int $wp_user_id, string $role_key ): bool {
        $capability = self::ENTRY_CAPABILITIES[ $role_key ] ?? '';

        return '' !== $capability && $wp_user_id > 0 && user_can( $wp_user_id, $capability );
    }

    /**
     * Fresh server-issued idempotency key for one create form.
     */
    public static function new_request_key(): string {
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * Organizations in which the actor may run this setup (explicit choice list).
     *
     * @return list<array<string, mixed>>
     */
    public function organizations( int $actor_id ): array {
        return $this->provisioning->organizations_for_manager( $actor_id, OrganizationClinicProvisioningService::CAPABILITY );
    }

    /**
     * Stored plan for an Organization the actor is authorized for, or null.
     *
     * @return array<string, mixed>|null
     */
    public function plan( int $actor_id, int $organization_id ): ?array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );

        return $this->read_plan( $organization_id );
    }

    /**
     * Step 1 — facility type and Organization/hospital identity.
     *
     * @return array<string, mixed>
     */
    public function start( int $actor_id, int $organization_id, string $facility_type, string $structure, string $display_name ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY_LIFECYCLE );

        if ( ! FacilityTypeCatalog::is_valid( $facility_type ) ) {
            throw new FacilitySetupException( 'invalid_facility', 'نوع مرکز معتبر نیست.' );
        }
        if ( ! FacilityTypeCatalog::structure_is_compatible( $facility_type, $structure ) ) {
            throw new FacilitySetupException( 'incompatible_structure', FacilityTypeCatalog::structure_incompatibility_message( $facility_type, $structure ) );
        }
        $flow = FacilityTypeCatalog::resolve_flow( $facility_type, $structure );
        if ( ! in_array( $flow, self::FLOWS, true ) ) {
            throw new FacilitySetupException( 'not_multi_clinic', 'این نوع مرکز چنددرمانگاهی نیست؛ مطب و درمانگاه تک‌واحدی از «راه‌اندازی» همان مرکز پیکربندی می‌شود.' );
        }
        $display_name = trim( $display_name );
        if ( '' === $display_name || mb_strlen( $display_name ) > 190 ) {
            throw new FacilitySetupException( 'invalid_identity', 'نام نمایشی مرکز الزامی است (حداکثر ۱۹۰ نویسه).' );
        }

        return $this->mutate(
            $actor_id,
            $organization_id,
            static function ( ?array $plan ) use ( $actor_id, $organization_id, $facility_type, $flow, $display_name ): array {
                if ( null === $plan ) {
                    return [
                        'version'           => 1,
                        'organization_id'   => $organization_id,
                        'facility_type'     => $facility_type,
                        'flow'              => $flow,
                        'display_name'      => $display_name,
                        'outpatient_ack_by' => 0,
                        'outpatient_ack_at' => '',
                        'sites'             => [],
                        'clinics'           => [],
                        'activated_at'      => '',
                        'activated_by'      => 0,
                        'started_by'        => $actor_id,
                    ];
                }
                if ( (string) $plan['flow'] !== $flow && [] !== $plan['clinics'] ) {
                    throw new FacilitySetupException( 'flow_locked', 'پس از ایجاد نخستین درمانگاه، نوع جریان این سازمان تغییر نمی‌کند؛ تاریخچهٔ پیکربندی بازنویسی نمی‌شود.' );
                }
                if ( (string) $plan['flow'] !== $flow ) {
                    $plan['outpatient_ack_by'] = 0;
                    $plan['outpatient_ack_at'] = '';
                }
                $plan['facility_type'] = $facility_type;
                $plan['flow']          = $flow;
                $plan['display_name']  = $display_name;

                return $plan;
            }
        );
    }

    /**
     * Hospital only — explicit outpatient-only acknowledgment (no inpatient/HIS).
     *
     * @return array<string, mixed>
     */
    public function acknowledge_outpatient( int $actor_id, int $organization_id, bool $confirmed ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        if ( ! $confirmed ) {
            throw new FacilitySetupException( 'ack_required', 'برای ادامه باید صریحاً تأیید کنید که فقط درمانگاه‌ها و فرایندهای سرپایی پشتیبانی می‌شود.' );
        }

        return $this->mutate(
            $actor_id,
            $organization_id,
            static function ( ?array $plan ) use ( $actor_id ): array {
                $plan = self::require_plan( $plan );
                if ( 'hospital_network' !== (string) $plan['flow'] ) {
                    throw new FacilitySetupException( 'not_hospital', 'تأیید محدودهٔ سرپایی فقط برای بیمارستان است.' );
                }
                if ( (int) $plan['outpatient_ack_by'] <= 0 ) {
                    $plan['outpatient_ack_by'] = $actor_id;
                    $plan['outpatient_ack_at'] = gmdate( 'Y-m-d H:i:s' );
                }

                return $plan;
            }
        );
    }

    /**
     * Step 2 — physical site/building (descriptive metadata only).
     *
     * @return array<string, mixed>
     */
    public function add_site( int $actor_id, int $organization_id, string $label, string $address ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $label   = trim( $label );
        $address = trim( $address );
        if ( '' === $label || mb_strlen( $label ) > 120 ) {
            throw new FacilitySetupException( 'invalid_site', 'نام ساختمان/سایت الزامی است (حداکثر ۱۲۰ نویسه).' );
        }
        if ( mb_strlen( $address ) > 255 ) {
            throw new FacilitySetupException( 'invalid_site', 'نشانی حداکثر ۲۵۵ نویسه است.' );
        }
        $key = 's' . substr( hash( 'sha256', mb_strtolower( $label ) ), 0, 10 );

        return $this->mutate(
            $actor_id,
            $organization_id,
            static function ( ?array $plan ) use ( $key, $label, $address ): array {
                $plan = self::require_plan( $plan );
                foreach ( $plan['sites'] as $site ) {
                    if ( (string) $site['key'] === $key ) {
                        return $plan;
                    }
                }
                if ( count( $plan['sites'] ) >= self::MAX_SITES ) {
                    throw new FacilitySetupException( 'limit', 'حداکثر ۲۰ ساختمان/سایت در یک راه‌اندازی.' );
                }
                $plan['sites'][] = [
                    'key'     => $key,
                    'label'   => $label,
                    'address' => $address,
                ];

                return $plan;
            }
        );
    }

    /**
     * Step 3 — repeatable Clinic creation (Clinic + primary Location at a site).
     *
     * @return array<string, mixed> The plan entry of the Clinic.
     */
    public function add_clinic( int $actor_id, int $organization_id, string $request_key, string $name, string $site_key, string $timezone ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY_LIFECYCLE );
        $this->assert_request_key( $request_key );
        $name = trim( $name );
        if ( '' === $name || mb_strlen( $name ) > 150 ) {
            throw new FacilitySetupException( 'invalid_clinic', 'نام درمانگاه الزامی است (حداکثر ۱۵۰ نویسه).' );
        }
        if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            throw new FacilitySetupException( 'invalid_clinic', 'منطقهٔ زمانی باید شناسهٔ معتبر IANA باشد.' );
        }

        $plan = self::require_plan( $this->read_plan( $organization_id ) );
        if ( 'hospital_network' === (string) $plan['flow'] && (int) $plan['outpatient_ack_by'] <= 0 ) {
            throw new FacilitySetupException( 'ack_required', 'پیش از ایجاد درمانگاه، محدودهٔ فقط سرپایی بیمارستان را صریحاً تأیید کنید.' );
        }
        $existing = self::clinic_entry_by_request( $plan, $request_key );
        if ( null !== $existing ) {
            return $existing;
        }
        self::assert_unique_clinic_name( $plan, $name );
        if ( count( $plan['clinics'] ) >= self::MAX_CLINICS ) {
            throw new FacilitySetupException( 'limit', 'حداکثر ۵۰ درمانگاه در یک راه‌اندازی.' );
        }
        $site = self::site( $plan, $site_key );

        $slug          = 'org' . $organization_id . '-' . substr( hash( 'sha256', $organization_id . ':' . $request_key ), 0, 12 );
        $location_name = mb_substr( $name . ' — ' . (string) $site['label'], 0, 190 );
        $address       = '' !== (string) $site['address'] ? (string) $site['address'] : null;

        $clinic_id   = 0;
        $location_id = 0;
        try {
            $created     = $this->provisioning->provision( $actor_id, $organization_id, $name, $slug, $timezone, $location_name, $address, null );
            $clinic_id   = (int) $created['clinic_id'];
            $location_id = (int) $created['location_id'];
        } catch ( InvalidArgumentException $e ) {
            throw new FacilitySetupException( 'invalid_clinic', 'مشخصات درمانگاه معتبر نیست؛ چیزی ایجاد نشد.' );
        } catch ( RuntimeException $e ) {
            if ( 'clinic slug unavailable' !== $e->getMessage() ) {
                throw new FacilitySetupException( 'denied', 'ایجاد درمانگاه انجام نشد؛ دسترسی سازمانی یا وضعیت سازمان معتبر نیست و تغییری ثبت نشد.' );
            }
            // Retry after a lost response: the deterministic slug already exists.
            // Adopt it only when it belongs to this same Organization.
            $row = $this->db->fetchRow(
                'SELECT id, organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE slug = %s LIMIT 1',
                [ $slug ]
            );
            if ( null === $row || (int) $row['organization_id'] !== $organization_id ) {
                throw new FacilitySetupException( 'conflict', 'شناسهٔ این درمانگاه در دسترس نیست؛ چیزی ایجاد نشد.' );
            }
            $clinic_id   = (int) $row['id'];
            $location_id = (int) ( $this->db->fetchValue(
                'SELECT id FROM ' . $this->db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_primary = 1 ORDER BY id LIMIT 1',
                [ $clinic_id ]
            ) ?? 0 );
        }

        // Hold new demand until the consolidated review + explicit activation.
        $held = false;
        try {
            $this->provisioning->set_clinic_status( $actor_id, $organization_id, $clinic_id, false );
            $held = true;
        } catch ( Throwable $e ) {
            $held = false;
        }

        $entry = [
            'key'         => 'c' . substr( $request_key, 0, 12 ),
            'request_key' => $request_key,
            'name'        => $name,
            'slug'        => $slug,
            'site_key'    => (string) $site['key'],
            'clinic_id'   => $clinic_id,
            'location_id' => $location_id,
            'held'        => $held,
            'rooms'       => [],
        ];

        $plan = $this->mutate(
            $actor_id,
            $organization_id,
            static function ( ?array $plan ) use ( $entry, $request_key ): array {
                $plan = self::require_plan( $plan );
                if ( null !== self::clinic_entry_by_request( $plan, $request_key ) ) {
                    return $plan;
                }
                $plan['clinics'][]    = $entry;
                $plan['activated_at'] = '';

                return $plan;
            }
        );

        return (array) self::clinic_entry_by_request( $plan, $request_key );
    }

    /**
     * Step 4 — explicit Clinic manager designation (Organization authority).
     *
     * @return array{clinic_id:int, membership_id:int, noop:bool, audit_id:int|null}
     */
    public function designate_manager( int $actor_id, int $organization_id, string $clinic_key, int $target_user_id ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry = $this->clinic_entry( $organization_id, $clinic_key );

        try {
            return $this->provisioning->designate_clinic_manager( $actor_id, $organization_id, (int) $entry['clinic_id'], $target_user_id );
        } catch ( InvalidArgumentException $e ) {
            throw new FacilitySetupException( 'user_not_found', 'کاربر انتخاب‌شده یافت نشد؛ عضویتی ایجاد نشد.' );
        } catch ( RuntimeException $e ) {
            if ( 'clinic membership already exists' === $e->getMessage() ) {
                throw new FacilitySetupException( 'membership_exists', 'این کاربر از قبل عضویتی (با نقش یا وضعیت دیگر) در این درمانگاه دارد؛ عضویت موجود بازنویسی نمی‌شود و باید در «کاربران و دسترسی‌ها» همان درمانگاه بررسی شود.' );
            }
            throw new FacilitySetupException( 'denied', 'تعیین مدیر درمانگاه مجاز نیست (اختیار سازمانی cpms_org_clinic_manager_assign لازم است؛ DENY صریح اولویت دارد).' );
        }
    }

    /**
     * Step 5 — consultation room / Location inside one Clinic (Clinic authority).
     *
     * @return array<string, mixed> The room entry.
     */
    public function add_room( int $actor_id, int $organization_id, string $clinic_key, string $request_key, string $name, string $site_key ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $this->assert_request_key( $request_key );
        $name = trim( $name );
        if ( '' === $name || mb_strlen( $name ) > 190 ) {
            throw new FacilitySetupException( 'invalid_room', 'نام اتاق/مکان الزامی است (حداکثر ۱۹۰ نویسه).' );
        }
        $plan  = self::require_plan( $this->read_plan( $organization_id ) );
        $entry = $this->clinic_entry( $organization_id, $clinic_key );
        foreach ( $entry['rooms'] as $room ) {
            if ( (string) $room['request_key'] === $request_key ) {
                return $room;
            }
            if ( mb_strtolower( (string) $room['name'] ) === mb_strtolower( $name ) ) {
                throw new FacilitySetupException( 'duplicate_room', 'اتاقی با همین نام در این درمانگاه ثبت شده است؛ اتاق تکراری ساخته نشد.' );
            }
        }
        if ( count( $entry['rooms'] ) >= self::MAX_ROOMS ) {
            throw new FacilitySetupException( 'limit', 'حداکثر ۵۰ اتاق برای هر درمانگاه.' );
        }
        $site      = self::site( $plan, $site_key );
        $clinic_id = (int) $entry['clinic_id'];
        $timezone  = (string) ( $this->db->fetchValue(
            'SELECT timezone FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d AND organization_id = %d LIMIT 1',
            [ $clinic_id, $organization_id ]
        ) ?? '' );
        $slug      = 'room-' . substr( hash( 'sha256', $clinic_id . ':' . $request_key ), 0, 12 );

        $location_id = 0;
        try {
            $created     = $this->locations->createLocation(
                $actor_id,
                $clinic_id,
                [
                    'name'     => $name,
                    'slug'     => $slug,
                    'address'  => (string) $site['address'],
                    'phone'    => null,
                    'timezone' => $timezone,
                ]
            );
            $location_id = (int) $created['location_id'];
        } catch ( LocationException $e ) {
            if ( LocationException::CONFLICT !== $e->getErrorCode() ) {
                throw $this->room_refusal( $e );
            }
            $row = $this->location_rows->findBySlug( $clinic_id, $slug );
            if ( null === $row ) {
                throw new FacilitySetupException( 'conflict', 'شناسهٔ این اتاق در دسترس نیست؛ چیزی ایجاد نشد.' );
            }
            $location_id = (int) $row['id'];
        }

        $room = [
            'key'         => 'r' . substr( $request_key, 0, 12 ),
            'request_key' => $request_key,
            'name'        => $name,
            'slug'        => $slug,
            'site_key'    => (string) $site['key'],
            'location_id' => $location_id,
        ];

        $plan = $this->mutate(
            $actor_id,
            $organization_id,
            static function ( ?array $plan ) use ( $clinic_key, $room, $request_key ): array {
                $plan = self::require_plan( $plan );
                foreach ( $plan['clinics'] as $index => $clinic ) {
                    if ( (string) $clinic['key'] !== $clinic_key ) {
                        continue;
                    }
                    foreach ( $clinic['rooms'] as $existing ) {
                        if ( (string) $existing['request_key'] === $request_key ) {
                            return $plan;
                        }
                    }
                    $plan['clinics'][ $index ]['rooms'][] = $room;
                }

                return $plan;
            }
        );

        $entry = self::find_clinic_entry( $plan, $clinic_key );
        foreach ( (array) ( $entry['rooms'] ?? [] ) as $stored ) {
            if ( (string) $stored['request_key'] === $request_key ) {
                return $stored;
            }
        }

        return $room;
    }

    /**
     * Step 6 — clinician assignment to one Clinic and its rooms (Clinic authority).
     *
     * One WordPress user keeps ONE professional profile (u_clinician_user). The first
     * Clinic creates it (home); any further Clinic of the SAME Organization adds an
     * explicit ACTIVE membership (participation), never a second identity. Existing
     * memberships are never re-roled or re-activated here. Location assignment is
     * additive: assignments in other Clinics are preserved.
     *
     * @param list<string> $room_keys Plan room keys; 'primary' = the Clinic's primary Location.
     * @return array{clinician_id:int, profile_created:bool, membership_created:bool, location_ids:list<int>}
     */
    public function assign_clinician( int $actor_id, int $organization_id, string $clinic_key, int $wp_user_id, string $full_name, array $room_keys ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry     = $this->clinic_entry( $organization_id, $clinic_key );
        $clinic_id = (int) $entry['clinic_id'];
        $this->clinic_scope( $actor_id, $clinic_id );
        if ( $wp_user_id <= 0 || false === get_userdata( $wp_user_id ) ) {
            throw new FacilitySetupException( 'user_not_found', 'کاربر انتخاب‌شده یافت نشد.' );
        }
        $location_ids = $this->room_location_ids( $entry, $room_keys );

        $profile         = $this->db->fetchRow(
            'SELECT id, clinic_id FROM ' . $this->db->table( 'cpms_clinicians' ) . ' WHERE wp_user_id = %d LIMIT 1',
            [ $wp_user_id ]
        );
        $profile_created = false;
        if ( null === $profile ) {
            $full_name = trim( $full_name );
            if ( '' === $full_name || mb_strlen( $full_name ) > 190 ) {
                throw new FacilitySetupException( 'invalid_clinician', 'نام کامل پزشک برای ساخت پروفایل حرفه‌ای الزامی است.' );
            }
            $clinician_id    = $this->clinicians->create(
                $clinic_id,
                [
                    'full_name'  => $full_name,
                    'wp_user_id' => $wp_user_id,
                    'is_active'  => 1,
                ]
            );
            $profile_created = true;
        } else {
            $clinician_id = (int) $profile['id'];
            $home_org     = (int) ( $this->db->fetchValue(
                'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                [ (int) $profile['clinic_id'] ]
            ) ?? 0 );
            if ( $home_org !== $organization_id ) {
                throw new FacilitySetupException( 'cross_organization', 'پروفایل حرفه‌ای این کاربر به سازمان دیگری تعلق دارد؛ اشتراک میان سازمان‌ها پشتیبانی نمی‌شود.' );
            }
        }
        if ( $clinician_id <= 0 ) {
            throw new FacilitySetupException( 'failed', 'ساخت پروفایل پزشک انجام نشد.' );
        }

        $membership         = $this->membership_rows->find( $clinic_id, $wp_user_id );
        $membership_created = false;
        if ( null === $membership ) {
            try {
                $this->membership_service->create_membership( $clinic_id, $wp_user_id, RolesAndCapabilities::ROLE_DOCTOR, 'clinic', $actor_id );
                $membership_created = true;
            } catch ( MembershipException $e ) {
                throw new FacilitySetupException( 'membership_failed', 'عضویت پزشک در این درمانگاه ایجاد نشد.' );
            }
        } elseif ( 'active' !== (string) $membership['status'] ) {
            throw new FacilitySetupException( 'membership_suspended', 'عضویت این کاربر در این درمانگاه تعلیق است؛ فعال‌سازی دوباره فقط در «کاربران و دسترسی‌ها» و به‌صورت صریح انجام می‌شود.' );
        }

        $merged  = array_values( array_unique( array_merge( $this->membership_service->clinician_location_ids( $clinician_id ), $location_ids ) ) );
        $primary = $this->membership_service->primary_clinician_location_id( $clinician_id );
        if ( null === $primary || ! in_array( $primary, $merged, true ) ) {
            $primary = $location_ids[0];
        }
        try {
            $this->membership_service->assign_clinician_locations( $clinician_id, $merged, $primary );
        } catch ( MembershipException $e ) {
            throw new FacilitySetupException( 'assignment_conflict', 'تخصیص مکان فقط برای درمانگاه‌هایی ممکن است که پزشک در آن‌ها عضویت فعال دارد.' );
        }

        return [
            'clinician_id'       => $clinician_id,
            'profile_created'    => $profile_created,
            'membership_created' => $membership_created,
            'location_ids'       => $location_ids,
        ];
    }

    /**
     * Step 6b — staff role with explicit Clinic/Location permissions (Clinic authority).
     *
     * Only the built-in staff roles (reception, finance) are assignable here. The
     * access scope is an explicit choice: the whole Clinic, or an explicit subset of
     * that Clinic's ACTIVE Locations (location-scoped membership). Optional explicit
     * DENY rows may only narrow the role preset (DENY > GRANT > preset); this step
     * never writes a GRANT, so it can never widen access beyond the preset. The
     * membership, its Location scope and its DENY rows are written in ONE
     * transaction, so a failure never leaves a Clinic-wide membership behind. An
     * existing membership is never overwritten: an identical retry is a no-op and
     * any other existing row (role, status, scope or overrides differ) is refused.
     *
     * @param list<string> $room_keys Plan room keys ('primary' = primary Location); only with $access = 'locations'.
     * @param list<string> $deny_caps Capabilities of the role preset to DENY explicitly.
     * @return array{membership_id:int, noop:bool, scope_mode:string, location_ids:list<int>, denied:list<string>}
     */
    public function assign_staff( int $actor_id, int $organization_id, string $clinic_key, int $wp_user_id, string $role_key, string $access, array $room_keys, array $deny_caps ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry     = $this->clinic_entry( $organization_id, $clinic_key );
        $clinic_id = (int) $entry['clinic_id'];
        $this->clinic_scope( $actor_id, $clinic_id );
        if ( ! in_array( $role_key, self::STAFF_ROLES, true ) ) {
            throw new FacilitySetupException( 'invalid_role', 'نقش کارکنان باید صریحاً از نقش‌های پذیرش یا مالی انتخاب شود؛ مدیر و پزشک در گام‌های خودشان تعیین می‌شوند.' );
        }
        $user = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;
        if ( false === $user || in_array( 'administrator', (array) $user->roles, true ) ) {
            throw new FacilitySetupException( 'user_not_eligible', 'کاربر انتخاب‌شده یافت نشد یا برای نقش کارکنان این درمانگاه قابل انتخاب نیست؛ چیزی ثبت نشد.' );
        }

        if ( 'clinic' === $access ) {
            if ( [] !== $room_keys ) {
                throw new FacilitySetupException( 'invalid_access', 'برای دسترسی به کل درمانگاه اتاقی انتخاب نکنید؛ برای محدودسازی، «فقط مکان‌های انتخاب‌شده» را برگزینید.' );
            }
            $location_ids = [];
        } elseif ( 'locations' === $access ) {
            $location_ids = $this->room_location_ids( $entry, $room_keys );
            sort( $location_ids );
        } else {
            throw new FacilitySetupException( 'invalid_access', 'محدودهٔ دسترسی (کل درمانگاه یا مکان‌های مشخص) باید صریحاً انتخاب شود.' );
        }

        $preset = RolesAndCapabilities::capsMap( $role_key );
        $denied = [];
        foreach ( $deny_caps as $capability ) {
            $capability = (string) $capability;
            if ( true !== ( $preset[ $capability ] ?? null ) ) {
                throw new FacilitySetupException( 'invalid_capability', 'فقط مجوزهای موجود در همان نقش را می‌توان صریحاً منع (DENY) کرد؛ چیزی ثبت نشد.' );
            }
            $denied[] = $capability;
        }
        $denied = array_values( array_unique( $denied ) );
        sort( $denied );
        $scope_mode = [] === $location_ids ? 'clinic' : 'location';

        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $clinic_id, $wp_user_id, $role_key, $scope_mode, $location_ids, $denied ): array {
                $owner = $this->db->fetchRowForUpdate(
                    'SELECT id, organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                    [ $clinic_id ]
                );
                if ( null === $owner || (int) $owner['organization_id'] !== $organization_id ) {
                    throw new FacilitySetupException( 'not_found', 'درمانگاه انتخاب‌شده در راه‌اندازی این سازمان نیست.' );
                }
                $existing = $this->db->fetchRowForUpdate(
                    'SELECT id, role_key, scope_mode, status FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' WHERE clinic_id = %d AND wp_user_id = %d LIMIT 1',
                    [ $clinic_id, $wp_user_id ]
                );
                if ( null !== $existing ) {
                    $membership_id = (int) $existing['id'];
                    if ( $this->staff_membership_matches( $existing, $role_key, $scope_mode, $location_ids, $denied ) ) {
                        return [
                            'membership_id' => $membership_id,
                            'noop'          => true,
                            'scope_mode'    => $scope_mode,
                            'location_ids'  => $location_ids,
                            'denied'        => $denied,
                        ];
                    }
                    throw new FacilitySetupException( 'membership_exists', 'این کاربر از قبل عضویتی (با نقش، وضعیت یا محدودهٔ دیگر) در این درمانگاه دارد؛ عضویت موجود بازنویسی نمی‌شود و باید در «کاربران و دسترسی‌ها» همان درمانگاه بررسی شود.' );
                }

                try {
                    $membership_id = $this->membership_service->create_membership( $clinic_id, $wp_user_id, $role_key, 'clinic', $actor_id );
                    if ( 'location' === $scope_mode ) {
                        $this->membership_service->set_scope_mode( $membership_id, 'location', $location_ids );
                    }
                    foreach ( $denied as $capability ) {
                        $this->membership_service->set_capability( $membership_id, $capability, 'deny' );
                    }
                } catch ( MembershipException $e ) {
                    throw new FacilitySetupException( 'membership_failed', 'عضویت کارکنان ایجاد نشد؛ هیچ بخشی از آن ثبت نشد.' );
                }

                if ( null !== $this->audit ) {
                    $this->audit->log(
                        self::STAFF_AUDIT_ACTION,
                        [
                            'wp_user_id' => $actor_id,
                            'role'       => 'clinic_config',
                        ],
                        'clinic_membership',
                        $membership_id,
                        null,
                        null,
                        [
                            'wp_user_id'   => $wp_user_id,
                            'role_key'     => $role_key,
                            'scope_mode'   => $scope_mode,
                            'location_ids' => $location_ids,
                            'denied'       => $denied,
                        ],
                        [
                            'op'              => 'facility_staff_assign',
                            'organization_id' => $organization_id,
                        ],
                        $clinic_id
                    );
                }

                return [
                    'membership_id' => $membership_id,
                    'noop'          => false,
                    'scope_mode'    => $scope_mode,
                    'location_ids'  => $location_ids,
                    'denied'        => $denied,
                ];
            }
        );
    }

    /**
     * Staff memberships of one plan Clinic (actor's own Clinic-authorized view only).
     *
     * @return list<array{wp_user_id:int, login:string, role_key:string, status:string, scope_mode:string, locations:int, denied:int, entry:bool}>
     */
    public function clinic_staff( int $actor_id, int $organization_id, string $clinic_key ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry     = $this->clinic_entry( $organization_id, $clinic_key );
        $clinic_id = (int) $entry['clinic_id'];
        $this->clinic_scope( $actor_id, $clinic_id );

        $rows = $this->db->fetchAll(
            'SELECT m.id, m.wp_user_id, m.role_key, m.status, m.scope_mode,'
            . ' (SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_membership_locations' ) . ' ml WHERE ml.membership_id = m.id) AS locations,'
            . ' (SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_membership_capabilities' ) . ' mc WHERE mc.membership_id = m.id AND mc.effect = %s) AS denied'
            . ' FROM ' . $this->db->table( 'cpms_clinic_memberships' ) . ' m'
            . ' WHERE m.clinic_id = %d AND m.role_key IN (%s, %s)'
            . ' ORDER BY m.id LIMIT 200',
            [ 'deny', $clinic_id, RolesAndCapabilities::ROLE_SECRETARY, RolesAndCapabilities::ROLE_ACCOUNTANT ]
        );
        $out  = [];
        foreach ( $rows as $row ) {
            $user  = get_userdata( (int) $row['wp_user_id'] );
            $out[] = [
                'wp_user_id' => (int) $row['wp_user_id'],
                'login'      => false === $user ? '' : (string) $user->user_login,
                'role_key'   => (string) $row['role_key'],
                'status'     => (string) $row['status'],
                'scope_mode' => (string) $row['scope_mode'],
                'locations'  => (int) $row['locations'],
                'denied'     => (int) $row['denied'],
                'entry'      => self::has_admin_entry( (int) $row['wp_user_id'], (string) $row['role_key'] ),
            ];
        }

        return $out;
    }

    /**
     * Step 7 — one weekly schedule row for a clinician at one room of one Clinic.
     *
     * The Clinic scope is established for THIS actor (active membership + CONFIG)
     * and the existing ScheduleService enforces clinician participation, Location
     * ownership/activity and shift conflicts. An identical existing row is a no-op.
     *
     * @param array<string, mixed> $input {day_of_week, start_time, end_time, appointment_duration_min, slot_capacity}
     * @return array{schedule_id:int, noop:bool}
     */
    public function add_weekly_schedule( int $actor_id, int $organization_id, string $clinic_key, int $clinician_id, string $room_key, array $input ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry       = $this->clinic_entry( $organization_id, $clinic_key );
        $clinic_id   = (int) $entry['clinic_id'];
        $scope       = $this->clinic_scope( $actor_id, $clinic_id );
        $location_id = $this->room_location_ids( $entry, [ $room_key ] )[0];
        $day         = (int) ( $input['day_of_week'] ?? -1 );
        $start       = substr( trim( (string) ( $input['start_time'] ?? '' ) ), 0, 5 );
        $end         = substr( trim( (string) ( $input['end_time'] ?? '' ) ), 0, 5 );

        if ( 1 !== preg_match( '/^\d{2}:\d{2}$/', $start ) || 1 !== preg_match( '/^\d{2}:\d{2}$/', $end ) ) {
            throw new FacilitySetupException( 'invalid_schedule', 'ساعت شروع و پایان باید به‌صورت HH:MM باشد.' );
        }
        $existing = $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_schedule' )
            . ' WHERE clinician_id = %d AND clinic_id = %d AND location_id = %d AND day_of_week = %d AND start_time = %s AND end_time = %s LIMIT 1',
            [ $clinician_id, $clinic_id, $location_id, $day, $start . ':00', $end . ':00' ]
        );
        if ( null !== $existing ) {
            return [
                'schedule_id' => (int) $existing,
                'noop'        => true,
            ];
        }

        $view     = [];
        $previous = ScopeContext::tryGet();
        ScopeContext::set( $scope );
        try {
            $view = $this->schedules->create(
                $actor_id,
                [
                    'clinician_id'             => $clinician_id,
                    'location_id'              => $location_id,
                    'day_of_week'              => $day,
                    'start_time'               => $start,
                    'end_time'                 => $end,
                    'appointment_duration_min' => (int) ( $input['appointment_duration_min'] ?? 20 ),
                    'slot_capacity'            => (int) ( $input['slot_capacity'] ?? 1 ),
                    'is_active'                => 1,
                ]
            );
        } catch ( Throwable $e ) {
            throw new FacilitySetupException( 'invalid_schedule', 'برنامهٔ هفتگی ثبت نشد: ' . $e->getMessage() );
        } finally {
            if ( null !== $previous ) {
                ScopeContext::set( $previous );
            } else {
                ScopeContext::clear();
            }
        }

        return [
            'schedule_id' => (int) ( $view['id'] ?? 0 ),
            'noop'        => false,
        ];
    }

    /**
     * Step 8 — booking policy and capacity of one Clinic (Clinic authority).
     *
     * Same keys and bounds as the Classic wizard booking step; the per-Clinic
     * wizard progress records the booking step as completed for that Clinic only.
     *
     * @param array<string, mixed> $input {duration, capacity, lead, future, cancel}
     * @return array<string, int>
     */
    public function save_booking_policy( int $actor_id, int $organization_id, string $clinic_key, array $input ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry     = $this->clinic_entry( $organization_id, $clinic_key );
        $clinic_id = (int) $entry['clinic_id'];
        $this->clinic_scope( $actor_id, $clinic_id );

        foreach ( [ 'duration', 'capacity', 'lead', 'future', 'cancel' ] as $required ) {
            if ( ! isset( $input[ $required ] ) || ! is_scalar( $input[ $required ] ) || '' === trim( (string) $input[ $required ] ) ) {
                throw new FacilitySetupException( 'invalid_booking', 'تمام گزینه‌های سیاست رزرو الزامی هستند.' );
            }
        }
        $policy = [
            'booking.duration_default_min'  => max( 5, min( 240, (int) $input['duration'] ) ),
            'booking.slot_capacity_default' => max( 1, min( 20, (int) $input['capacity'] ) ),
            'booking.min_lead_hours'        => max( 1, min( 72, (int) $input['lead'] ) ),
            'booking.max_future_days'       => max( 1, min( 365, (int) $input['future'] ) ),
            'booking.cancel_deadline_hours' => max( 0, min( 168, (int) $input['cancel'] ) ),
        ];

        $settings = $this->settings->forClinic( $clinic_id );
        foreach ( $policy as $key => $value ) {
            $settings->set( $key, $value, $actor_id );
        }
        $completed = array_filter( array_map( 'trim', explode( ',', (string) ( $settings->get( 'setup.completed_steps', '' ) ?? '' ) ) ) );
        if ( ! in_array( 'booking', $completed, true ) ) {
            $completed[] = 'booking';
            $settings->set( 'setup.completed_steps', implode( ',', $completed ), $actor_id );
        }

        return $policy;
    }

    /**
     * Step 9a — consolidated review computed from live rows (never from counts typed in).
     *
     * @return array{plan: array<string, mixed>, clinics: list<array<string, mixed>>, blocking: list<string>, ready: bool}
     */
    public function review( int $actor_id, int $organization_id ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $plan     = self::require_plan( $this->read_plan( $organization_id ) );
        $hospital = 'hospital_network' === (string) $plan['flow'];
        $blocking = [];
        if ( $hospital && (int) $plan['outpatient_ack_by'] <= 0 ) {
            $blocking[] = 'تأیید صریح محدودهٔ فقط سرپایی ثبت نشده است.';
        }
        if ( [] === $plan['sites'] ) {
            $blocking[] = 'هیچ ساختمان/سایتی ثبت نشده است.';
        }
        if ( [] === $plan['clinics'] ) {
            $blocking[] = 'هیچ درمانگاهی ایجاد نشده است.';
        }

        $clinics = [];
        foreach ( $plan['clinics'] as $entry ) {
            $clinic_id = (int) $entry['clinic_id'];
            $row       = $this->db->fetchRow(
                'SELECT id, organization_id, name, COALESCE(status, %s) AS status FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
                [ 'active', $clinic_id ]
            );
            if ( null === $row || (int) $row['organization_id'] !== $organization_id ) {
                $blocking[] = 'یکی از درمانگاه‌های این راه‌اندازی دیگر به این سازمان تعلق ندارد.';
                continue;
            }
            $summary   = $this->clinic_summary( $clinic_id );
            $label     = (string) $row['name'];
            $clinics[] = $summary + [
                'key'           => (string) $entry['key'],
                'clinic_id'     => $clinic_id,
                'name'          => $label,
                'status'        => (string) $row['status'],
                'site_key'      => (string) $entry['site_key'],
                'rooms'         => $entry['rooms'],
                'location_id'   => (int) $entry['location_id'],
                'can_configure' => $this->authz->can( $actor_id, $clinic_id, RolesAndCapabilities::CONFIG ),
            ];
            if ( $summary['managers'] < 1 ) {
                $blocking[] = '«' . $label . '»: مدیر درمانگاه به‌صورت صریح تعیین نشده است.';
            }
            if ( $summary['active_locations'] < 1 ) {
                $blocking[] = '«' . $label . '»: هیچ مکان فعالی ندارد.';
            }
            if ( $hospital ) {
                if ( $summary['scheduled_clinicians'] < 1 ) {
                    $blocking[] = '«' . $label . '»: دست‌کم یک پزشک با برنامهٔ هفتگی لازم است.';
                }
                if ( ! $summary['booking_policy'] ) {
                    $blocking[] = '«' . $label . '»: سیاست رزرو و ظرفیت ثبت نشده است.';
                }
            }
        }

        return [
            'plan'     => $plan,
            'clinics'  => $clinics,
            'blocking' => $blocking,
            'ready'    => [] === $blocking,
        ];
    }

    /**
     * Step 9b — explicit activation: re-activates every held Clinic of the plan.
     *
     * @return array{activated: list<int>, plan: array<string, mixed>}
     */
    public function activate( int $actor_id, int $organization_id, bool $confirmed ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY_LIFECYCLE );
        if ( ! $confirmed ) {
            throw new FacilitySetupException( 'confirm_required', 'برای فعال‌سازی، بازبینی را صریحاً تأیید کنید.' );
        }
        $review = $this->review( $actor_id, $organization_id );
        if ( ! $review['ready'] ) {
            throw new FacilitySetupException( 'not_ready', 'فعال‌سازی انجام نشد: ' . implode( ' ', $review['blocking'] ) );
        }

        $activated = [];
        foreach ( $review['clinics'] as $clinic ) {
            try {
                $this->provisioning->set_clinic_status( $actor_id, $organization_id, (int) $clinic['clinic_id'], true );
            } catch ( Throwable $e ) {
                throw new FacilitySetupException( 'denied', 'فعال‌سازی درمانگاه مجاز نیست (اختیار سازمانی چرخهٔ عمر لازم است).' );
            }
            $activated[] = (int) $clinic['clinic_id'];
        }

        $plan = $this->mutate(
            $actor_id,
            $organization_id,
            static function ( ?array $plan ) use ( $actor_id ): array {
                $plan                 = self::require_plan( $plan );
                $plan['activated_at'] = gmdate( 'Y-m-d H:i:s' );
                $plan['activated_by'] = $actor_id;

                return $plan;
            }
        );

        return [
            'activated' => $activated,
            'plan'      => $plan,
        ];
    }

    /**
     * Clinicians participating in one plan Clinic (home or ACTIVE membership), for
     * the actor's own Clinic-authorized view only.
     *
     * @return list<array{id:int, full_name:string, schedule_rows:int}>
     */
    public function clinic_clinicians( int $actor_id, int $organization_id, string $clinic_key ): array {
        $this->assert_organization( $actor_id, $organization_id, OrganizationClinicProvisioningService::CAPABILITY );
        $entry     = $this->clinic_entry( $organization_id, $clinic_key );
        $clinic_id = (int) $entry['clinic_id'];
        $this->clinic_scope( $actor_id, $clinic_id );

        $rows = $this->db->fetchAll(
            'SELECT c.id, c.full_name,'
            . ' (SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_schedule' ) . ' s WHERE s.clinician_id = c.id AND s.clinic_id = %d AND s.is_active = 1) AS schedule_rows'
            . ' FROM ' . $this->db->table( 'cpms_clinicians' ) . ' c'
            . ' LEFT JOIN ' . $this->db->table( 'cpms_clinic_memberships' ) . ' m ON m.wp_user_id = c.wp_user_id AND m.clinic_id = %d AND m.status = %s'
            . ' WHERE c.is_active = 1 AND ( c.clinic_id = %d OR m.id IS NOT NULL )'
            . ' ORDER BY c.full_name, c.id LIMIT 200',
            [ $clinic_id, $clinic_id, 'active', $clinic_id ]
        );
        $out  = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'id'            => (int) $row['id'],
                'full_name'     => (string) $row['full_name'],
                'schedule_rows' => (int) $row['schedule_rows'],
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Live, non-PHI readiness facts of one Clinic.
     *
     * @return array{managers:int, active_locations:int, scheduled_clinicians:int, schedule_rows:int, staff:int, staff_location_scope:int, booking_policy:bool, entry_missing:int, managers_no_entry:int}
     */
    private function clinic_summary( int $clinic_id ): array {
        $memberships = $this->db->table( 'cpms_clinic_memberships' );
        $managers    = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $memberships . ' WHERE clinic_id = %d AND status = %s AND role_key = %s',
            [ $clinic_id, 'active', OrganizationClinicProvisioningService::CLINIC_MANAGER_ROLE_KEY ]
        ) ?? 0 );
        $staff       = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $memberships . ' WHERE clinic_id = %d AND status = %s AND role_key NOT IN (%s, %s)',
            [ $clinic_id, 'active', OrganizationClinicProvisioningService::CLINIC_MANAGER_ROLE_KEY, RolesAndCapabilities::ROLE_DOCTOR ]
        ) ?? 0 );
        $scoped      = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $memberships . ' WHERE clinic_id = %d AND status = %s AND scope_mode = %s AND role_key NOT IN (%s, %s)',
            [ $clinic_id, 'active', 'location', OrganizationClinicProvisioningService::CLINIC_MANAGER_ROLE_KEY, RolesAndCapabilities::ROLE_DOCTOR ]
        ) ?? 0 );
        $locations   = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_locations' ) . ' WHERE clinic_id = %d AND is_active = 1',
            [ $clinic_id ]
        ) ?? 0 );
        $scheduled   = (int) ( $this->db->fetchValue(
            'SELECT COUNT(DISTINCT clinician_id) FROM ' . $this->db->table( 'cpms_schedule' ) . ' WHERE clinic_id = %d AND is_active = 1',
            [ $clinic_id ]
        ) ?? 0 );
        $rows        = (int) ( $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_schedule' ) . ' WHERE clinic_id = %d AND is_active = 1',
            [ $clinic_id ]
        ) ?? 0 );
        $booking     = null !== $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_settings' ) . ' WHERE clinic_id = %d AND `key` = %s LIMIT 1',
            [ $clinic_id, 'booking.duration_default_min' ]
        );

        // Admin-entry readiness (WordPress role), reported per Clinic as counts only.
        $entry_missing    = 0;
        $managers_missing = 0;
        $members          = $this->db->fetchAll(
            'SELECT wp_user_id, role_key FROM ' . $memberships . ' WHERE clinic_id = %d AND status = %s AND role_key IN (%s, %s, %s, %s)',
            array_merge( [ $clinic_id, 'active' ], array_keys( self::ENTRY_CAPABILITIES ) )
        );
        foreach ( $members as $member ) {
            if ( self::has_admin_entry( (int) $member['wp_user_id'], (string) $member['role_key'] ) ) {
                continue;
            }
            ++$entry_missing;
            if ( RolesAndCapabilities::ROLE_MANAGER === (string) $member['role_key'] ) {
                ++$managers_missing;
            }
        }

        return [
            'managers'             => $managers,
            'active_locations'     => $locations,
            'scheduled_clinicians' => $scheduled,
            'schedule_rows'        => $rows,
            'staff'                => $staff,
            'staff_location_scope' => $scoped,
            'booking_policy'       => $booking,
            'entry_missing'        => $entry_missing,
            'managers_no_entry'    => $managers_missing,
        ];
    }

    /**
     * Organization authority for one capability (DENY > GRANT > preset) + active Organization.
     */
    private function assert_organization( int $actor_id, int $organization_id, string $permission ): void {
        if ( $actor_id <= 0 || $organization_id <= 0 ) {
            throw new FacilitySetupException( 'denied', 'سازمان باید صریحاً انتخاب شود.' );
        }
        $status = $this->db->fetchValue(
            'SELECT status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
            [ $organization_id ]
        );
        if ( 'active' !== (string) $status || ! $this->provisioning->can( $actor_id, $organization_id, $permission ) ) {
            throw new FacilitySetupException( 'denied', 'برای این سازمان اختیار سازمانی لازم را ندارید؛ نقش مدیر وردپرس به‌تنهایی کافی نیست.' );
        }
    }

    /**
     * Trusted Clinic scope for the actor: ACTIVE membership + CONFIG in that Clinic.
     */
    private function clinic_scope( int $actor_id, int $clinic_id ): ClinicScope {
        try {
            $scope = ( new TrustedClinicEstablisher( $this->db, $this->membership_rows ) )->establish( $actor_id, $clinic_id );
            $this->authz->authorize( $actor_id, $clinic_id, RolesAndCapabilities::CONFIG );
        } catch ( ScopeRequiredException | AuthorizationException $e ) {
            throw new FacilitySetupException( 'clinic_denied', 'این بخش فقط با عضویت فعال و مجوز پیکربندی در همین درمانگاه انجام می‌شود (مثلاً مدیر تعیین‌شدهٔ همان درمانگاه). اختیار سازمانی به‌تنهایی دسترسی درمانگاه نمی‌دهد.' );
        }

        return $scope;
    }

    /**
     * Whether an existing membership row is exactly the requested staff state
     * (identical retry). Anything else is a different membership and is refused.
     *
     * @param array<string, mixed> $existing
     * @param list<int>            $location_ids
     * @param list<string>         $denied
     */
    private function staff_membership_matches( array $existing, string $role_key, string $scope_mode, array $location_ids, array $denied ): bool {
        if ( 'active' !== (string) $existing['status'] || (string) $existing['role_key'] !== $role_key || (string) $existing['scope_mode'] !== $scope_mode ) {
            return false;
        }
        $membership_id = (int) $existing['id'];
        $current       = array_map( 'intval', $this->membership_service->membership_location_ids( $membership_id ) );
        sort( $current );
        if ( $current !== $location_ids ) {
            return false;
        }
        $overrides = [];
        foreach ( $this->membership_service->capabilities_for( $membership_id ) as $row ) {
            if ( 'deny' !== (string) $row['effect'] ) {
                return false;
            }
            $overrides[] = (string) $row['capability'];
        }
        sort( $overrides );

        return $overrides === $denied;
    }

    private function room_refusal( LocationException $e ): FacilitySetupException {
        if ( in_array( $e->getErrorCode(), [ LocationException::PERMISSION_DENIED, LocationException::AUTH_REQUIRED, LocationException::SCOPE_REQUIRED ], true ) ) {
            return new FacilitySetupException( 'clinic_denied', 'ساخت اتاق/مکان فقط با عضویت فعال و مجوز پیکربندی در همین درمانگاه ممکن است؛ اختیار سازمانی به‌تنهایی کافی نیست.' );
        }
        if ( LocationException::VALIDATION === $e->getErrorCode() ) {
            return new FacilitySetupException( 'invalid_room', 'مشخصات اتاق/مکان معتبر نیست؛ چیزی ایجاد نشد.' );
        }

        return new FacilitySetupException( 'failed', 'ساخت اتاق/مکان انجام نشد؛ چیزی ثبت نشد.' );
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $room_keys
     * @return list<int>
     */
    private function room_location_ids( array $entry, array $room_keys ): array {
        $clinic_id = (int) $entry['clinic_id'];
        $map       = [ 'primary' => (int) $entry['location_id'] ];
        foreach ( $entry['rooms'] as $room ) {
            $map[ (string) $room['key'] ] = (int) $room['location_id'];
        }
        $ids = [];
        foreach ( $room_keys as $room_key ) {
            $room_key = (string) $room_key;
            if ( ! isset( $map[ $room_key ] ) ) {
                throw new FacilitySetupException( 'invalid_room', 'اتاق/مکان انتخاب‌شده متعلق به این درمانگاه نیست.' );
            }
            $location_id = $map[ $room_key ];
            if ( null === $this->location_rows->findActiveForClinic( $clinic_id, $location_id ) ) {
                throw new FacilitySetupException( 'inactive_location', 'اتاق/مکان انتخاب‌شده فعال نیست یا به این درمانگاه تعلق ندارد.' );
            }
            $ids[] = $location_id;
        }
        $ids = array_values( array_unique( $ids ) );
        if ( [] === $ids ) {
            throw new FacilitySetupException( 'invalid_room', 'دست‌کم یک اتاق/مکان باید صریحاً انتخاب شود.' );
        }

        return $ids;
    }

    /**
     * Plan entry of a Clinic, re-verified against the live Clinic row.
     *
     * @return array<string, mixed>
     */
    private function clinic_entry( int $organization_id, string $clinic_key ): array {
        $plan  = self::require_plan( $this->read_plan( $organization_id ) );
        $entry = self::find_clinic_entry( $plan, $clinic_key );
        if ( null === $entry ) {
            throw new FacilitySetupException( 'not_found', 'درمانگاه انتخاب‌شده در راه‌اندازی این سازمان نیست.' );
        }
        $owner = $this->db->fetchValue(
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d LIMIT 1',
            [ (int) $entry['clinic_id'] ]
        );
        if ( (int) $owner !== $organization_id ) {
            throw new FacilitySetupException( 'not_found', 'درمانگاه انتخاب‌شده در راه‌اندازی این سازمان نیست.' );
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|null
     */
    private static function find_clinic_entry( array $plan, string $clinic_key ): ?array {
        if ( '' === $clinic_key ) {
            return null;
        }
        foreach ( $plan['clinics'] as $entry ) {
            if ( (string) $entry['key'] === $clinic_key ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>|null
     */
    private static function clinic_entry_by_request( array $plan, string $request_key ): ?array {
        foreach ( $plan['clinics'] as $entry ) {
            if ( (string) $entry['request_key'] === $request_key ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function assert_unique_clinic_name( array $plan, string $name ): void {
        foreach ( $plan['clinics'] as $entry ) {
            if ( mb_strtolower( (string) $entry['name'] ) === mb_strtolower( $name ) ) {
                throw new FacilitySetupException( 'duplicate_clinic', 'درمانگاهی با همین نام در این راه‌اندازی ایجاد شده است؛ درمانگاه تکراری ساخته نشد.' );
            }
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private static function site( array $plan, string $site_key ): array {
        foreach ( $plan['sites'] as $site ) {
            if ( (string) $site['key'] === $site_key && '' !== $site_key ) {
                return $site;
            }
        }
        throw new FacilitySetupException( 'invalid_site', 'ساختمان/سایت باید صریحاً از فهرست همین راه‌اندازی انتخاب شود.' );
    }

    /**
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private static function require_plan( ?array $plan ): array {
        if ( null === $plan ) {
            throw new FacilitySetupException( 'no_plan', 'ابتدا نوع مرکز و هویت سازمان را در گام نخست ثبت کنید.' );
        }

        return $plan;
    }

    private function assert_request_key( string $request_key ): void {
        if ( 1 !== preg_match( self::REQUEST_KEY_PATTERN, $request_key ) ) {
            throw new FacilitySetupException( 'invalid_request', 'درخواست نامعتبر است؛ فرم را دوباره باز کنید.' );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read_plan( int $organization_id ): ?array {
        $option = self::OPTION_PREFIX . $organization_id;
        wp_cache_delete( $option, 'options' );
        $value = get_option( $option, null );
        if ( ! is_array( $value ) || (int) ( $value['organization_id'] ?? 0 ) !== $organization_id ) {
            return null;
        }
        $value['sites']   = is_array( $value['sites'] ?? null ) ? array_values( $value['sites'] ) : [];
        $value['clinics'] = is_array( $value['clinics'] ?? null ) ? array_values( $value['clinics'] ) : [];
        foreach ( $value['clinics'] as $index => $clinic ) {
            $value['clinics'][ $index ]['rooms'] = is_array( $clinic['rooms'] ?? null ) ? array_values( $clinic['rooms'] ) : [];
        }

        return $value;
    }

    /**
     * Serialized plan write under the Organization row lock.
     *
     * @param callable(array<string, mixed>|null): array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function mutate( int $actor_id, int $organization_id, callable $change ): array {
        return $this->db->transactional(
            function () use ( $actor_id, $organization_id, $change ): array {
                $organization = $this->db->fetchRowForUpdate(
                    'SELECT id, status FROM ' . $this->db->table( 'cpms_organizations' ) . ' WHERE id = %d LIMIT 1',
                    [ $organization_id ]
                );
                if ( null === $organization || 'active' !== (string) $organization['status'] ) {
                    throw new FacilitySetupException( 'denied', 'سازمان فعال نیست؛ تغییری ثبت نشد.' );
                }
                $before             = $this->read_plan( $organization_id );
                $plan               = $change( $before );
                $plan['updated_at'] = gmdate( 'Y-m-d H:i:s' );
                $plan['updated_by'] = $actor_id;
                update_option( self::OPTION_PREFIX . $organization_id, $plan, false );
                $this->audit_plan_change( $actor_id, $organization_id, $before, $plan );

                return $plan;
            }
        );
    }

    /**
     * Audits a real plan change inside the plan-write transaction (an identical
     * retry changes nothing and is not re-audited). Organization-level event:
     * no Clinic scope and no patient data — only which plan sections changed.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     */
    private function audit_plan_change( int $actor_id, int $organization_id, ?array $before, array $after ): void {
        if ( null === $this->audit ) {
            return;
        }
        $changed = [];
        foreach ( $after as $section => $value ) {
            if ( 'updated_at' === $section || 'updated_by' === $section ) {
                continue;
            }
            if ( null === $before || ! array_key_exists( $section, $before ) || $before[ $section ] !== $value ) {
                $changed[] = (string) $section;
            }
        }
        if ( [] === $changed ) {
            return;
        }
        $this->audit->log(
            self::PLAN_AUDIT_ACTION,
            [
                'wp_user_id' => $actor_id,
                'role'       => 'organization_manager',
            ],
            'organization',
            $organization_id,
            null,
            null,
            [
                'changed' => $changed,
                'sites'   => count( (array) ( $after['sites'] ?? [] ) ),
                'clinics' => count( (array) ( $after['clinics'] ?? [] ) ),
            ],
            [ 'op' => 'facility_setup_plan' ],
            null,
            true
        );
    }
}

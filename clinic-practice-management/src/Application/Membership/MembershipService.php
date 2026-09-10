<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Membership;

use ClinicCore\Domain\Membership\MembershipException;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\MembershipRepository;

/**
 * سرویس عضویت — Phase 2 primitives (C4 / P2-D1 / ADR-0031).
 *
 * مدل مصوب:
 *   WP User → حداکثر یک Clinician Profile (u_clinician_user)
 *   User ↔ چند Clinic از طریق Membership (رابطهٔ واقعی — SoT)
 *   Doctor ↔ چند Location از طریق تخصیص صریح (مبنای اعتبارسنجی: عضویتِ فعال)
 *
 * این کلاس فقط «primitive» است؛ Policy Engine / Role Builder فاز ۳ است و
 * current_user_can('cpms_*') جدیدی معرفی نمی‌شود.
 *
 * قواعد fail-closed:
 *  - همهٔ scopeها صریح؛ هیچ fallback/فرضی وجود ندارد.
 *  - عضویتِ suspend شده = بدون دسترسی (active_membership_for → null).
 *  - تخصیص Location فقط به Clinicِ همان عضویت/کلینیکِ عضویتِ فعال پزشک.
 */
final class MembershipService {

    private const STATUS_ACTIVE    = 'active';
    private const STATUS_SUSPENDED = 'suspended';
    private const SCOPE_CLINIC     = 'clinic';
    private const SCOPE_LOCATION   = 'location';

    public function __construct(
        private readonly CpmsDb $db,
        private readonly MembershipRepository $memberships
    ) {
    }

    // ================= Membership =================

    /**
     * ساخت عضویت — تکراری بودن (clinic,user) با خطای صریح رد می‌شود (نه silent update).
     *
     * @param int|null $invited_by_wp_user_id
     */
    public function create_membership(
        int $clinic_id,
        int $wp_user_id,
        string $role_key,
        string $scope_mode = self::SCOPE_CLINIC,
        ?int $invited_by_wp_user_id = null
    ): int {
        if ( $wp_user_id <= 0 || get_userdata( $wp_user_id ) === false ) {
            throw MembershipException::not_found( __( 'کاربر وردپرس', 'cpms' ), $wp_user_id );
        }
        if ( $this->db->fetchValue(
            'SELECT id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [ $clinic_id ]
        ) === null ) {
            throw MembershipException::not_found( __( 'کلینیک', 'cpms' ), $clinic_id );
        }
        $this->assert_role_key( $role_key );
        if ( ! in_array( $scope_mode, [ self::SCOPE_CLINIC, self::SCOPE_LOCATION ], true ) ) {
            throw MembershipException::invalid_value( 'scope_mode', __( 'باید clinic یا location باشد.', 'cpms' ) );
        }
        if ( $scope_mode === self::SCOPE_LOCATION ) {
            // عضویت location-scoped باید با فهرست Location ساخته شود؛ آن مسیر
            // set_scope_mode است. ساخت مستقیم (بدون Location = دسترسی به هیچ‌جا)
            // صریحاً رد می‌شود — fail-closed.
            throw MembershipException::invalid_value(
                'scope_mode',
                __( 'عضویت location-scoped از طریق set_scope_mode همراه با فهرست Location ساخته می‌شود.', 'cpms' )
            );
        }
        if ( $this->memberships->find( $clinic_id, $wp_user_id ) !== null ) {
            throw MembershipException::duplicate( $clinic_id, $wp_user_id );
        }

        $now = $this->db->nowUtcSql();
        $ok  = $this->memberships->insert(
            [
                'clinic_id'             => $clinic_id,
                'wp_user_id'            => $wp_user_id,
                'role_key'              => $role_key,
                'scope_mode'            => $scope_mode,
                'status'                => self::STATUS_ACTIVE,
                'is_primary'            => 0,
                'invited_by_wp_user_id' => $invited_by_wp_user_id,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]
        );
        if ( ! $ok ) {
            // UNIQUE backstop (نظری race) — رفتار همان duplicate است.
            throw MembershipException::duplicate( $clinic_id, $wp_user_id );
        }

        return (int) $this->db->wpdb_last_insert_id();
    }

    /**
     * عضویت (هر وضعیت) — برای نمایش/ادمین.
     *
     * @return array<string, mixed>|null
     */
    public function membership_for( int $clinic_id, int $wp_user_id ): ?array {
        return $this->memberships->find( $clinic_id, $wp_user_id );
    }

    /**
     * عضویت فعال — مبنای دسترسی. suspend ⇒ null (fail-closed).
     *
     * @return array<string, mixed>|null
     */
    public function active_membership_for( int $clinic_id, int $wp_user_id ): ?array {
        return $this->memberships->find_active( $clinic_id, $wp_user_id );
    }

    /**
     * همهٔ Clinicهای دارای عضویت فعال برای کاربر.
     *
     * @return list<array<string, mixed>>
     */
    public function active_memberships_for_user( int $wp_user_id ): array {
        return $this->memberships->active_for_user( $wp_user_id );
    }

    /**
     * @return list<int>
     */
    public function active_clinic_ids_for_user( int $wp_user_id ): array {
        return $this->memberships->active_clinic_ids_for_user( $wp_user_id );
    }

    /**
     * تعلیق عضویت — دسترسی فوراً قطع می‌شود؛ Locationها/capabilityها حفظ می‌شوند.
     */
    public function suspend_membership( int $membership_id ): void {
        $this->transition_status( $membership_id, self::STATUS_SUSPENDED );
    }

    public function reactivate_membership( int $membership_id ): void {
        $this->transition_status( $membership_id, self::STATUS_ACTIVE );
    }

    /**
     * علامت‌گذاری «کلینیک اصلی» کاربر (ترجیح، نه مفهوم امنیتی — P2-D1).
     * فقط یکی از عضویت‌های همان کاربر primary می‌ماند.
     */
    public function set_primary_membership( int $clinic_id, int $wp_user_id ): void {
        $membership = $this->require_membership( $clinic_id, $wp_user_id );
        $now        = $this->db->nowUtcSql();
        $this->db->transactional(
            function () use ( $membership, $wp_user_id, $now ): void {
                $this->memberships->update(
                    [ 'is_primary' => 0, 'updated_at' => $now ],
                    [ 'wp_user_id' => $wp_user_id ]
                );
                $this->memberships->update(
                    [ 'is_primary' => 1, 'updated_at' => $now ],
                    [ 'id' => (int) $membership['id'] ]
                );
            }
        );
    }

    /**
     * تغییر role_key (primitive رشته‌ای — سیاست نقش‌ها فاز ۳).
     */
    public function set_role_key( int $membership_id, string $role_key ): void {
        $this->assert_role_key( $role_key );
        $membership = $this->memberships->find_by_id( $membership_id );
        if ( $membership === null ) {
            throw MembershipException::not_found( __( 'عضویت', 'cpms' ), $membership_id );
        }
        $this->memberships->update(
            [ 'role_key' => $role_key, 'updated_at' => $this->db->nowUtcSql() ],
            [ 'id' => $membership_id ]
        );
    }

    // ================= Membership ↔ Location =================

    /**
     * تخصیص Locationها به عضویت — فقط Locationهای همان Clinic (fail-closed).
     * عضویت باید location-scoped باشد (scope_mode='clinic' = همهٔ Clinic).
     *
     * @param list<int> $location_ids
     */
    public function sync_membership_locations( int $membership_id, array $location_ids ): void {
        $membership = $this->require_membership_by_id( $membership_id );
        if ( (string) $membership['scope_mode'] !== self::SCOPE_LOCATION ) {
            throw MembershipException::scope_mode( self::SCOPE_LOCATION );
        }
        if ( $location_ids === [] ) {
            // خالی‌کردن یعنی عضویتی فعال بدون هیچ Location — رد (fail-closed).
            throw MembershipException::location_mismatch( $membership_id, [] );
        }

        $this->assert_locations_belong_to_clinic( $membership_id, $location_ids, (int) $membership['clinic_id'] );

        $this->db->transactional(
            function () use ( $membership_id, $location_ids ): void {
                $this->memberships->replace_locations( $membership_id, $location_ids );
            }
        );
    }

    /**
     * تغییر حالت دسترسی: به 'clinic' فقط با خالی‌کردن Locationها؛
     * به 'location' فقط همراه با فهرست Location.
     *
     * @param list<int> $location_ids
     */
    public function set_scope_mode( int $membership_id, string $mode, array $location_ids = [] ): void {
        if ( ! in_array( $mode, [ self::SCOPE_CLINIC, self::SCOPE_LOCATION ], true ) ) {
            throw MembershipException::invalid_value( 'scope_mode', __( 'باید clinic یا location باشد.', 'cpms' ) );
        }
        $membership = $this->require_membership_by_id( $membership_id );

        if ( $mode === self::SCOPE_CLINIC ) {
            // Clinic-wide یعنی جدول Location برای این عضویت خالی است (مدل 0012).
            $this->db->transactional(
                function () use ( $membership_id ): void {
                    $this->memberships->replace_locations( $membership_id, [] );
                    $this->memberships->update(
                        [ 'scope_mode' => self::SCOPE_CLINIC, 'updated_at' => $this->db->nowUtcSql() ],
                        [ 'id' => $membership_id ]
                    );
                }
            );

            return;
        }

        if ( $location_ids === [] ) {
            throw MembershipException::location_mismatch( $membership_id, [] );
        }
        $this->assert_locations_belong_to_clinic( $membership_id, $location_ids, (int) $membership['clinic_id'] );
        $this->db->transactional(
            function () use ( $membership_id, $location_ids ): void {
                $this->memberships->replace_locations( $membership_id, $location_ids );
                $this->memberships->update(
                    [ 'scope_mode' => self::SCOPE_LOCATION, 'updated_at' => $this->db->nowUtcSql() ],
                    [ 'id' => $membership_id ]
                );
            }
        );
    }

    /**
     * @return list<int>
     */
    public function membership_location_ids( int $membership_id ): array {
        return $this->memberships->location_ids_for( $membership_id );
    }

    // ================= Capability primitives (metadata فاز ۳) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilities_for( int $membership_id ): array {
        return $this->memberships->capabilities_for( $membership_id );
    }

    public function set_capability( int $membership_id, string $capability, string $effect ): void {
        $this->require_membership_by_id( $membership_id );
        if ( ! preg_match( '/^[a-z][a-z0-9_.]{1,63}$/', $capability ) ) {
            throw MembershipException::invalid_value( 'capability', __( 'قالب مجاز: حروف کوچک/عدد/نقطه/زیرخط.', 'cpms' ) );
        }
        if ( ! in_array( $effect, [ 'grant', 'deny' ], true ) ) {
            throw MembershipException::invalid_value( 'effect', __( 'باید grant یا deny باشد.', 'cpms' ) );
        }

        $this->memberships->set_capability( $membership_id, $capability, $effect );
    }

    public function remove_capability( int $membership_id, string $capability ): void {
        $this->memberships->remove_capability( $membership_id, $capability );
    }

    // ================= Clinician ↔ Location (P2-D1) =================

    /**
     * تخصیص Locationهای کاری به پزشک — اعتبارسنجی از روی «عضویت فعال» کاربرِ
     * متصل (نه clinicians.clinic_id که legacy است).
     *
     * @param list<int> $location_ids
     */
    public function assign_clinician_locations( int $clinician_id, array $location_ids, ?int $primary_location_id = null ): void {
        if ( $location_ids === [] ) {
            throw MembershipException::clinician_location_mismatch( $clinician_id, [] );
        }

        $wp_user_id = $this->memberships->clinician_wp_user_id( $clinician_id );
        if ( $wp_user_id === null || $wp_user_id <= 0 ) {
            throw MembershipException::not_found( __( 'پزشک', 'cpms' ), $clinician_id );
        }

        $allowed_clinic_ids = $this->memberships->active_clinic_ids_for_clinician( $clinician_id );
        if ( $allowed_clinic_ids === [] ) {
            throw MembershipException::clinician_location_mismatch( $clinician_id, $location_ids );
        }

        $map = $this->memberships->location_clinic_map( $location_ids );
        foreach ( array_map( 'intval', $location_ids ) as $location_id ) {
            $clinic_id = $map[ $location_id ] ?? null;
            if ( $clinic_id === null || ! in_array( $clinic_id, $allowed_clinic_ids, true ) ) {
                throw MembershipException::clinician_location_mismatch( $clinician_id, [ $location_id ] );
            }
        }

        if ( $primary_location_id !== null && ! in_array( (int) $primary_location_id, array_map( 'intval', $location_ids ), true ) ) {
            throw MembershipException::invalid_value(
                'primary_location_id',
                __( 'باید یکی از Locationهای تخصیص‌یافته باشد.', 'cpms' )
            );
        }

        $this->db->transactional(
            function () use ( $clinician_id, $location_ids, $primary_location_id ): void {
                $this->memberships->replace_clinician_locations( $clinician_id, $location_ids, $primary_location_id );
            }
        );
    }

    /**
     * @return list<int>
     */
    public function clinician_location_ids( int $clinician_id ): array {
        return $this->memberships->clinician_location_ids( $clinician_id );
    }

    public function primary_clinician_location_id( int $clinician_id ): ?int {
        return $this->memberships->primary_clinician_location_id( $clinician_id );
    }

    // ================= helpers =================

    private function transition_status( int $membership_id, string $to ): void {
        $membership = $this->require_membership_by_id( $membership_id );
        $from       = (string) $membership['status'];
        if ( $from === $to ) {
            throw MembershipException::invalid_transition( $from, $to );
        }
        if ( ! in_array(
            [ $from, $to ],
            [
                [ self::STATUS_ACTIVE, self::STATUS_SUSPENDED ],
                [ self::STATUS_SUSPENDED, self::STATUS_ACTIVE ],
            ],
            true
        ) ) {
            throw MembershipException::invalid_transition( $from, $to );
        }

        $this->memberships->update(
            [ 'status' => $to, 'updated_at' => $this->db->nowUtcSql() ],
            [ 'id' => $membership_id ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function require_membership( int $clinic_id, int $wp_user_id ): array {
        $membership = $this->memberships->find( $clinic_id, $wp_user_id );
        if ( $membership === null ) {
            throw MembershipException::not_found(
                sprintf(
                    /* translators: 1: clinic id, 2: user id */
                    __( 'عضویت (کلینیک %1$s، کاربر %2$s)', 'cpms' ),
                    $clinic_id,
                    $wp_user_id
                ),
                $clinic_id
            );
        }

        return $membership;
    }

    /**
     * @return array<string, mixed>
     */
    private function require_membership_by_id( int $membership_id ): array {
        $membership = $this->memberships->find_by_id( $membership_id );
        if ( $membership === null ) {
            throw MembershipException::not_found( __( 'عضویت', 'cpms' ), $membership_id );
        }

        return $membership;
    }

    /**
     * @param list<int> $location_ids
     */
    private function assert_locations_belong_to_clinic( int $membership_id, array $location_ids, int $clinic_id ): void {
        $map = $this->memberships->location_clinic_map( $location_ids );
        $bad = [];
        foreach ( array_map( 'intval', $location_ids ) as $location_id ) {
            if ( ( $map[ $location_id ] ?? null ) !== $clinic_id ) {
                $bad[] = $location_id;
            }
        }
        if ( $bad !== [] ) {
            throw MembershipException::location_mismatch( $membership_id, $bad );
        }
    }

    private function assert_role_key( string $role_key ): void {
        if ( ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $role_key ) ) {
            throw MembershipException::invalid_value(
                'role_key',
                __( 'قالب مجاز: حروف کوچک/عدد/زیرخط، حداقل ۲ نویسه.', 'cpms' )
            );
        }
    }
}

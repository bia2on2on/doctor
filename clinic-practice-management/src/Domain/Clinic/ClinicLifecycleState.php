<?php

declare( strict_types=1 );

namespace ClinicCore\Domain\Clinic;

/**
 * Clinic lifecycle state — the pure decision table for NEW participation.
 *
 * `cpms_clinics.status` carries two different meanings and they are byte-identical:
 * a Clinic created HELD pending its first activation is inserted with
 * `status='suspended'` (OrganizationClinicProvisioningService::provision_held), and a
 * Clinic that WAS operational and was later suspended by an Organization Manager also
 * carries `status='suspended'`. Authorized initial setup must stay possible for the
 * first; new staff participation must be refused for the second.
 *
 * The decision is made from POSITIVE evidence and it fails CLOSED. The absence of a
 * record is never treated as authorization:
 *
 *  - ACTIVE — `status='active'` (or a pre-0029 row with no status value at all, which
 *    migration 2026_10_18_0029 defines as NOT NULL DEFAULT 'active').
 *  - HELD — `status='suspended'` AND the Clinic's most recent lifecycle record is the
 *    provisioning record that positively asserts `held=true` together with the recorded
 *    resulting status `suspended`.
 *  - SUSPENDED_OPERATIONAL — `status='suspended'` AND the most recent lifecycle record is
 *    a real deactivation, or the Clinic was provisioned WITHOUT the held assertion.
 *  - UNVERIFIED — `status='suspended'` and the held assertion cannot be positively
 *    established: no lifecycle record, unreadable/corrupt JSON, an inconsistent pair (a
 *    reactivation is the latest record while the row is suspended), or an unknown status
 *    value.
 *
 * Legacy rows are never rewritten and never silently reinterpreted: a Clinic suspended
 * before this policy existed carries no held assertion, so it is UNVERIFIED and refuses
 * NEW participation only. Existing memberships, clinical history, finance and recovery
 * paths are reads and continuity operations, which this decision table does not touch.
 * The safe resolution is an explicit, authorized, audited reactivation.
 */
final class ClinicLifecycleState {

    /** `status='active'` — new participation permitted. */
    public const ACTIVE = 'active';

    /** `status='suspended'` with a positive durable held assertion — authorized initial setup permitted. */
    public const HELD = 'held';

    /** `status='suspended'` after having been operational — new participation refused. */
    public const SUSPENDED_OPERATIONAL = 'suspended_operational';

    /** `status='suspended'` without positively verifiable held evidence — refused (fail-closed). */
    public const UNVERIFIED = 'unverified';

    /** The lifecycle record vocabulary, in the writers' own constants. */
    public const ACTION_PROVISIONED = 'ORGANIZATION_CLINIC_PROVISIONED';
    public const ACTION_REACTIVATED = 'CLINIC_REACTIVATED';
    public const ACTION_DEACTIVATED = 'CLINIC_DEACTIVATED';

    private const STATUS_ACTIVE    = 'active';
    private const STATUS_SUSPENDED = 'suspended';

    private function __construct() {
    }

    /**
     * @return list<string>
     */
    public static function lifecycle_actions(): array {
        return [ self::ACTION_PROVISIONED, self::ACTION_REACTIVATED, self::ACTION_DEACTIVATED ];
    }

    /**
     * Pure classification — no DB, no WordPress, no request input.
     *
     * `$latest` is the Clinic's most recent lifecycle record (highest id among
     * lifecycle_actions()), or null when there is none. Only `action`, `meta_json` and
     * `after_json` are consulted; both JSON values may be a raw string or an array.
     *
     * @param array<string, mixed>|null $latest
     */
    public static function classify( ?string $status, ?array $latest ): string {
        $status = $status === null ? '' : trim( $status );

        if ( $status === '' ) {
            return self::ACTIVE;
        }

        if ( $status === self::STATUS_ACTIVE ) {
            return self::ACTIVE;
        }

        if ( $status !== self::STATUS_SUSPENDED ) {
            // Unknown/garbage status value: never a permissive reading.
            return self::UNVERIFIED;
        }

        if ( $latest === null ) {
            // Suspended with no durable lifecycle record: ambiguous legacy evidence or a
            // pruned/tampered trail. Absence is NOT held authorization.
            return self::UNVERIFIED;
        }

        $action = (string) ( $latest['action'] ?? '' );

        if ( $action === self::ACTION_DEACTIVATED ) {
            return self::SUSPENDED_OPERATIONAL;
        }

        if ( $action === self::ACTION_REACTIVATED ) {
            // The latest lifecycle fact says the Clinic was activated, yet the row is
            // suspended: the evidence is inconsistent (a suspension happened outside the
            // only writer, or a record is missing). Refuse.
            return self::UNVERIFIED;
        }

        if ( $action !== self::ACTION_PROVISIONED ) {
            return self::UNVERIFIED;
        }

        $meta  = self::decode( $latest['meta_json'] ?? null );
        $after = self::decode( $latest['after_json'] ?? null );

        if ( $meta === null || $after === null ) {
            // Unreadable/corrupt evidence: fail closed.
            return self::UNVERIFIED;
        }

        $held = $meta['held'] ?? null;
        if ( self::is_true( $held ) && (string) ( $after['status'] ?? '' ) === self::STATUS_SUSPENDED ) {
            return self::HELD;
        }

        // Provisioned WITHOUT the held assertion and now suspended: either the Clinic was
        // operational (its deactivation record is missing) or the evidence was altered.
        return self::SUSPENDED_OPERATIONAL;
    }

    /**
     * New participation (a NEW membership, or reactivating an existing one) is permitted
     * only for an ACTIVE Clinic and for a positively verified HELD Clinic completing its
     * authorized initial setup.
     */
    public static function accepts_new_membership( string $state ): bool {
        return $state === self::ACTIVE || $state === self::HELD;
    }

    private static function is_true( mixed $value ): bool {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode( mixed $json ): ?array {
        if ( is_array( $json ) ) {
            return $json;
        }
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return null;
        }
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        // The writers always record a JSON object. A list (or a scalar wrapped in one) is
        // not the shape this evidence can legitimately have, so it is unreadable here.
        return array_is_list( $decoded ) ? null : $decoded;
    }
}

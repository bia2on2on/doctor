<?php

declare( strict_types=1 );

namespace ClinicCore\Domain\Patients;

use DomainException;

/**
 * خطای حوزهٔ هویت بیمار (Phase 2 — C5 / AD-14) — کد `CLINIC_*` (ADR-0019) + HTTP + Data.
 *
 * الگوی MembershipException: Controller این Exception را به WP_Error نگاشت
 * می‌کند. پیام‌های پیش‌فرض translation-ready (text domain `cpms`).
 *
 * Anti-enumeration: برای هویتِ موجود در Organization دیگر همان خطای
 * NOT_FOUND برمی‌گردد (وجود/عدم وجود افشا نمی‌شود).
 */
final class PatientIdentityException extends DomainException {

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $error_code,
        string $message,
        public readonly int $http_status = 400,
        public readonly array $data = []
    ) {
        parent::__construct( $message );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function of( string $code, string $message, int $http = 400, array $data = [] ): self {
        return new self( $code, $message, $http, $data );
    }

    /** هویت/موجودیت یافت نشد (یا به این Organization تعلق ندارد). */
    public static function not_found( string $what, int $id ): self {
        return self::of(
            'CLINIC_PATIENT_IDENTITY_NOT_FOUND',
            sprintf(
                /* translators: 1: entity label, 2: id */
                __( '%1$s با شناسهٔ %2$s یافت نشد.', 'cpms' ),
                $what,
                $id
            ),
            404,
            [ 'entity' => $what, 'id' => $id ]
        );
    }

    /** موبایل نامعتبر (فرمت ایران — خروجی normalize معتبر نیست). */
    public static function invalid_mobile(): self {
        return self::of(
            'CLINIC_PATIENT_IDENTITY_INVALID_MOBILE',
            __( 'شماره موبایل نامعتبر است.', 'cpms' ),
            422,
            [ 'field' => 'mobile' ]
        );
    }

    /** Clinic به این Organization تعلق ندارد. */
    public static function clinic_not_in_organization( int $clinic_id, int $organization_id ): self {
        return self::of(
            'CLINIC_PATIENT_IDENTITY_ORG_MISMATCH',
            __( 'کلینیک به این سازمان تعلق ندارد.', 'cpms' ),
            422,
            [
                'clinic_id'       => $clinic_id,
                'organization_id' => $organization_id,
            ]
        );
    }

    /** رکورد بالینی به این Clinic تعلق ندارد. */
    public static function patient_not_in_clinic( int $patient_id, int $clinic_id ): self {
        return self::of(
            'CLINIC_PATIENT_IDENTITY_CLINIC_MISMATCH',
            __( 'بیمار به این کلینیک تعلق ندارد.', 'cpms' ),
            422,
            [
                'patient_id' => $patient_id,
                'clinic_id'  => $clinic_id,
            ]
        );
    }

    /** رکورد بالینی قبلاً به هویت دیگری متصل است. */
    public static function record_already_linked( int $patient_id, int $existing_identity_id ): self {
        return self::of(
            'CLINIC_PATIENT_IDENTITY_RECORD_LINKED',
            __( 'این رکورد بالینی قبلاً به هویت دیگری متصل است.', 'cpms' ),
            409,
            [
                'patient_id'           => $patient_id,
                'existing_identity_id' => $existing_identity_id,
            ]
        );
    }

    /** لینک WP User ↔ Identity از قبل وجود دارد. */
    public static function user_link_exists( int $identity_id, int $wp_user_id ): self {
        return self::of(
            'CLINIC_PATIENT_IDENTITY_USER_LINK_EXISTS',
            __( 'این کاربر قبلاً به این هویت متصل است.', 'cpms' ),
            409,
            [
                'identity_id' => $identity_id,
                'wp_user_id'  => $wp_user_id,
            ]
        );
    }
}

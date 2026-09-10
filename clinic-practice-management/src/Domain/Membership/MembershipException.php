<?php

declare( strict_types=1 );

namespace ClinicCore\Domain\Membership;

use DomainException;

/**
 * خطای حوزهٔ عضویت (Phase 2 — C4 primitives) — کد `CLINIC_*` (ADR-0019) + HTTP + Data.
 *
 * Controller این Exception را مستقیم به WP_Error نگاشت می‌کند (الگوی BookingException).
 * پیام‌های پیش‌فرض translation-ready هستند (text domain رسمی `cpms`).
 */
final class MembershipException extends DomainException {

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

    /** عضویت تکراری (clinic_id, wp_user_id) — UNIQUE u_membership. */
    public static function duplicate( int $clinic_id, int $wp_user_id ): self {
        return self::of(
            'CLINIC_MEMBERSHIP_DUPLICATE',
            sprintf(
                /* translators: 1: clinic id, 2: user id */
                __( 'این کاربر از قبل در این کلینیک عضویت دارد (کلینیک %1$s، کاربر %2$s).', 'cpms' ),
                $clinic_id,
                $wp_user_id
            ),
            409,
            [
                'clinic_id'  => $clinic_id,
                'wp_user_id' => $wp_user_id,
            ]
        );
    }

    /** عضویت/موجودیت یافت نشد. */
    public static function not_found( string $what, int $id ): self {
        return self::of(
            'CLINIC_MEMBERSHIP_NOT_FOUND',
            sprintf(
                /* translators: 1: entity label, 2: id */
                __( '%1$s با شناسهٔ %2$s یافت نشد.', 'cpms' ),
                $what,
                $id
            ),
            404,
            [
                'entity' => $what,
                'id'     => $id,
            ]
        );
    }

    /** Location به کلینیکِ عضویت تعلق ندارد — fail-closed. */
    public static function location_mismatch( int $membership_id, array $location_ids ): self {
        return self::of(
            'CLINIC_MEMBERSHIP_LOCATION_MISMATCH',
            __( 'Locationهای انتخابی به کلینیکِ این عضویت تعلق ندارند.', 'cpms' ),
            422,
            [
                'membership_id' => $membership_id,
                'location_ids'  => array_values( $location_ids ),
            ]
        );
    }

    /** عملیات با scope_mode فعلی عضویت سازگار نیست. */
    public static function scope_mode( string $expected_mode ): self {
        return self::of(
            'CLINIC_MEMBERSHIP_SCOPE_MODE',
            sprintf(
                /* translators: %s: required scope mode */
                __( 'این عملیات فقط با حالت دسترسی «%s» معتبر است.', 'cpms' ),
                $expected_mode
            ),
            422,
            [ 'expected_scope_mode' => $expected_mode ]
        );
    }

    /** انتقال وضعیت نامعتبر (مثلاً تعلیقِ عضویتِ تعلیق‌شده). */
    public static function invalid_transition( string $from, string $to ): self {
        return self::of(
            'CLINIC_MEMBERSHIP_INVALID_TRANSITION',
            sprintf(
                /* translators: 1: current status, 2: requested status */
                __( 'انتقال وضعیت عضویت از «%1$s» به «%2$s» مجاز نیست.', 'cpms' ),
                $from,
                $to
            ),
            422,
            [
                'from' => $from,
                'to'   => $to,
            ]
        );
    }

    /** Location به هیچ کلینیکی که این Clinician در آن عضویت فعال دارد تعلق ندارد. */
    public static function clinician_location_mismatch( int $clinician_id, array $location_ids ): self {
        return self::of(
            'CLINIC_CLINICIAN_LOCATION_MISMATCH',
            __( 'Locationهای انتخابی به کلینیکی که این پزشک در آن عضویت فعال دارد تعلق ندارند.', 'cpms' ),
            422,
            [
                'clinician_id' => $clinician_id,
                'location_ids' => array_values( $location_ids ),
            ]
        );
    }

    /** مقدار ورودی نامعتبر (role key / scope mode / capability). */
    public static function invalid_value( string $field, string $reason ): self {
        return self::of(
            'CLINIC_MEMBERSHIP_INVALID_VALUE',
            sprintf(
                /* translators: 1: field name, 2: reason */
                __( 'مقدار «%1$s» نامعتبر است: %2$s', 'cpms' ),
                $field,
                $reason
            ),
            422,
            [
                'field'  => $field,
                'reason' => $reason,
            ]
        );
    }
}

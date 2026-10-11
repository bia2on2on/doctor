<?php

declare(strict_types=1);

namespace ClinicCore\Auth;

// Custom Role & Permission Management — Slice 1: key/capability policy for
// Clinic-local custom-role definitions (Product Decision record:
// docs/decisions/2026-10-10-custom-role-permission-management-model.md).
//
// This class is a pure validation/classification policy — NOT an authorization
// engine. Authorization resolution stays exclusively in AuthorizationService
// (membership DENY > membership GRANT > role capabilities > default DENY).
//
// Security boundaries enforced here (fail closed):
//  - Custom role keys are strict-format, non-numeric strings that can never
//    collide with a built-in CPMS role key. The leading-letter format rule also
//    makes PHP array-key coercion impossible (numeric strings would coerce to
//    integer array keys; custom keys can never be numeric).
//  - V1-editable capabilities are exactly the approved CPMS catalogue
//    (RolesAndCapabilities::ALL_CAPS) minus every explicitly classified
//    sensitive capability (permission-matrix P-11 / ADR-0026 plus the matrix's
//    sensitive annotations, e.g. cpms_rx_void).
//  - No WordPress core capability, no WooCommerce capability, no unknown
//    string, and no sensitive capability can enter a custom-role definition.
//  - No new permission vocabulary is introduced: every allowed string is an
//    existing registered CPMS capability slug.
final class CustomRolePolicy {

    /**
     * Capabilities explicitly classified as sensitive (P-11 / ADR-0026 +
     * permission-matrix annotations). They are excluded from ordinary V1
     * custom-role editing and can never be granted through a custom role.
     */
    public const SENSITIVE_CAPS = [
        'cpms_patient_archive',
        'cpms_patient_merge',
        'cpms_consult_reopen',
        'cpms_private_note_read',
        'cpms_private_note_create',
        'cpms_private_note_update',
        'cpms_rx_void',
        'cpms_invoice_adjust',
        'cpms_invoice_void',
        'cpms_payment_void',
        'cpms_payment_refund',
        'cpms_export',
        'cpms_audit_read',
        'cpms_config',
        'cpms_sms_config',
    ];

    /**
     * Strict key format: 2..64 chars, lowercase letter first, then lowercase
     * letters/digits/underscores. Identical to the membership role_key format
     * (MembershipService::assert_role_key) with strict `\z` anchoring so a
     * trailing newline can never slip through. The leading letter guarantees
     * the key is never a numeric string, so it can never be coerced by PHP to
     * an integer array key.
     */
    private const ROLE_KEY_PATTERN = '/\A[a-z][a-z0-9_]{1,63}\z/';

    public static function is_well_formed_role_key( string $role_key ): bool {
        return preg_match( self::ROLE_KEY_PATTERN, $role_key ) === 1;
    }

    /**
     * Built-in CPMS role keys are the registered preset keys only. Custom
     * definitions can never use them (immutable keys; no collision).
     */
    public static function is_built_in_role_key( string $role_key ): bool {
        return in_array(
            $role_key,
            [
                RolesAndCapabilities::ROLE_PATIENT,
                RolesAndCapabilities::ROLE_SECRETARY,
                RolesAndCapabilities::ROLE_DOCTOR,
                RolesAndCapabilities::ROLE_ACCOUNTANT,
                RolesAndCapabilities::ROLE_MANAGER,
            ],
            true
        );
    }

    public static function is_definable_role_key( string $role_key ): bool {
        return self::is_well_formed_role_key( $role_key )
            && ! self::is_built_in_role_key( $role_key );
    }

    /**
     * V1-editable capability set: approved catalogue minus sensitive set.
     * Sorted, duplicate-free.
     *
     * @return list<string>
     */
    public static function editable_capabilities(): array {
        $editable = array_values( array_diff( RolesAndCapabilities::ALL_CAPS, self::SENSITIVE_CAPS ) );
        sort( $editable );

        return $editable;
    }

    public static function is_editable_capability( string $capability ): bool {
        return in_array( $capability, RolesAndCapabilities::ALL_CAPS, true )
            && ! in_array( $capability, self::SENSITIVE_CAPS, true );
    }

    /**
     * Upper bound (in Unicode code points) of a display name. Bounded validation
     * is required; the bound is script-agnostic (no Persian-only rule, no
     * locale-dependent counting — mb_strlen counts code points, so a combining
     * script is not punished for being encoded in several code units).
     */
    public const DISPLAY_NAME_MAX_CODE_POINTS = 120;

    /**
     * Fail-closed normalization of a custom-role display name. Purely
     * script-agnostic: it never assumes a language, alphabet or direction.
     *
     * - line breaks and tabs become spaces;
     * - C0/C1/DEL controls are removed (invalid UTF-8 collapses to '' => rejected
     *   by is_acceptable_display_name(), i.e. fail closed);
     * - whitespace runs collapse to one space and the result is trimmed;
     * - Unicode format characters such as ZWNJ (U+200C) are deliberately KEPT:
     *   they are orthographically required in Persian/Arabic text, so stripping
     *   them would corrupt legitimate display names.
     */
    public static function sanitize_display_name( string $display_name ): string {
        if ( str_contains( $display_name, "\0" ) ) {
            return '';
        }
        $value = str_replace( [ "\r\n", "\r", "\n", "\t" ], ' ', $display_name );

        $stripped = preg_replace( '/[\x00-\x1F\x7F-\x9F]/u', '', $value );
        if ( $stripped === null ) {
            return ''; // invalid UTF-8 — fail closed, never partially normalize
        }

        $collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $stripped );
        if ( $collapsed === null ) {
            return '';
        }

        return trim( $collapsed );
    }

    /**
     * Bounded, Unicode-general acceptance rule for display names. Accepts any
     * script (Latin, Persian, Arabic, CJK, Cyrillic, Hebrew, …) as long as the
     * name is non-empty, within the code-point bound, stable under
     * normalization, and contains at least one letter or number of any script.
     */
    public static function is_acceptable_display_name( string $display_name ): bool {
        $normalized = self::sanitize_display_name( $display_name );
        if ( $normalized === '' ) {
            return false;
        }
        if ( mb_strlen( $normalized ) > self::DISPLAY_NAME_MAX_CODE_POINTS ) {
            return false;
        }
        if ( $normalized !== self::sanitize_display_name( $normalized ) ) {
            return false; // must be a fixed point: no hidden re-normalization
        }

        return preg_match( '/[\p{L}\p{N}]/u', $normalized ) === 1;
    }

    /**
     * Capabilities an editor may delegate: the intersection of the V1-editable
     * catalogue and the editor's own effective Clinic-scoped capability set
     * (Product Decision 4 of the approved model — "a role editor must never
     * grant capabilities exceeding the editor's own effective authority").
     *
     * Fail closed: an unknown/blank effective entry adds nothing, and a
     * sensitive capability is never delegable even if the editor happens to
     * hold it (sensitive capabilities are non-delegable by decision).
     *
     * @param array<array-key, mixed> $effective_capabilities
     * @return list<string>
     */
    public static function delegable_capabilities( array $effective_capabilities ): array {
        $effective = [];
        foreach ( $effective_capabilities as $capability ) {
            if ( is_string( $capability ) && $capability !== '' ) {
                $effective[ $capability ] = true;
            }
        }

        $delegable = [];
        foreach ( self::editable_capabilities() as $capability ) {
            if ( isset( $effective[ $capability ] ) ) {
                $delegable[] = $capability;
            }
        }
        sort( $delegable );

        return $delegable;
    }

    /**
     * Fail-closed normalization used at both persistence-write boundaries and
     * the authorization read path: only strings that are editable capabilities
     * survive; everything else (unknown, WordPress, WooCommerce, sensitive,
     * non-string values) is dropped. Array keys are ignored entirely, so
     * PHP array-key coercion can never smuggle a value.
     *
     * @param array<array-key, mixed> $capabilities
     * @return list<string>
     */
    public static function sanitize_capability_set( array $capabilities ): array {
        $clean = [];
        foreach ( $capabilities as $capability ) {
            if ( is_string( $capability ) && self::is_editable_capability( $capability ) ) {
                $clean[ $capability ] = true;
            }
        }
        $list = array_keys( $clean );
        sort( $list );

        return $list;
    }
}

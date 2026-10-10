<?php

declare(strict_types=1);

namespace ClinicCore\Auth;

/**
 * Custom Role & Permission Management — Slice 1: key/capability policy for
 * Clinic-local custom-role definitions (Product Decision record:
 * docs/decisions/2026-10-10-custom-role-permission-management-model.md).
 *
 * This class is a pure validation/classification policy — NOT an authorization
 * engine. Authorization resolution stays exclusively in AuthorizationService
 * (membership DENY > membership GRANT > role capabilities > default DENY).
 *
 * Security boundaries enforced here (fail closed):
 *  - Custom role keys are strict-format, non-numeric strings that can never
 *    collide with a built-in CPMS role key. The leading-letter format rule also
 *    makes PHP array-key coercion impossible (numeric strings would coerce to
 *    integer array keys; custom keys can never be numeric).
 *  - V1-editable capabilities are exactly the approved CPMS catalogue
 *    (RolesAndCapabilities::ALL_CAPS) minus every explicitly classified
 *    sensitive capability (permission-matrix P-11 / ADR-0026 plus the matrix's
 *    sensitive annotations, e.g. cpms_rx_void).
 *  - No WordPress core capability, no WooCommerce capability, no unknown
 *    string, and no sensitive capability can enter a custom-role definition.
 *  - No new permission vocabulary is introduced: every allowed string is an
 *    existing registered CPMS capability slug.
 */
final class CustomRolePolicy
{
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
     * (MembershipService::assert_role_key). The leading letter guarantees the
     * key is never a numeric string, so it can never be coerced by PHP to an
     * integer array key.
     */
    private const ROLE_KEY_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    public static function isWellFormedRoleKey(string $roleKey): bool
    {
        return preg_match(self::ROLE_KEY_PATTERN, $roleKey) === 1;
    }

    /**
     * Built-in CPMS role keys are the registered preset keys only. Custom
     * definitions can never use them (immutable keys; no collision).
     */
    public static function isBuiltInRoleKey(string $roleKey): bool
    {
        return in_array(
            $roleKey,
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

    public static function isDefinableRoleKey(string $roleKey): bool
    {
        return self::isWellFormedRoleKey($roleKey) && !self::isBuiltInRoleKey($roleKey);
    }

    /**
     * V1-editable capability set: approved catalogue minus sensitive set.
     * Sorted, duplicate-free.
     *
     * @return list<string>
     */
    public static function editableCapabilities(): array
    {
        $editable = array_values(array_diff(RolesAndCapabilities::ALL_CAPS, self::SENSITIVE_CAPS));
        sort($editable);

        return $editable;
    }

    public static function isEditableCapability(string $capability): bool
    {
        return in_array($capability, RolesAndCapabilities::ALL_CAPS, true)
            && !in_array($capability, self::SENSITIVE_CAPS, true);
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
    public static function sanitizeCapabilitySet(array $capabilities): array
    {
        $clean = [];
        foreach ($capabilities as $capability) {
            if (is_string($capability) && self::isEditableCapability($capability)) {
                $clean[$capability] = true;
            }
        }
        $list = array_keys($clean);
        sort($list);

        return $list;
    }
}

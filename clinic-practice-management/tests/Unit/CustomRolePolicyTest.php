<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Custom Role & Permission Management — Slice 1 (TEST-ONLY RED): unit contracts
 * for the Clinic-local custom-role key/capability policy.
 *
 * Contracts (fail closed; no new permission vocabulary):
 *  - Custom role keys are well-formed, non-numeric strings that never collide with
 *    built-in CPMS role keys (immutable keys; PHP array-key coercion safe).
 *  - V1-editable capabilities are exactly the approved CPMS catalogue minus every
 *    explicitly classified sensitive capability (P-11 / ADR-0026 + permission
 *    matrix sensitive annotations).
 *  - WordPress core capabilities, WooCommerce capabilities, unknown strings and
 *    sensitive capabilities can never enter a custom-role capability set.
 */
final class CustomRolePolicyTest extends TestCase
{
    private const ALL_SENSITIVE = [
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

    private const ALL_BUILT_IN_ROLE_KEYS = [
        'cpms_patient',
        'cpms_secretary',
        'cpms_doctor',
        'cpms_accountant',
        'cpms_manager',
    ];

    public function testEditableCapabilitiesCoverCatalogueExactly(): void
    {
        $editable   = CustomRolePolicy::editableCapabilities();
        $sensitive  = self::ALL_SENSITIVE;
        $catalogue  = RolesAndCapabilities::ALL_CAPS;

        // No invented vocabulary: every editable capability is an approved catalogue entry.
        foreach ($editable as $cap) {
            self::assertIsString($cap);
            self::assertContains($cap, $catalogue, 'editable set must stay inside ALL_CAPS');
            self::assertNotContains($cap, $sensitive, 'sensitive capability leaked into editable set');
        }

        // No capability falls through the cracks: catalogue = editable ∪ sensitive.
        $union = array_merge($editable, $sensitive);
        sort($union);
        $catalogueSorted = $catalogue;
        sort($catalogueSorted);
        self::assertSame($catalogueSorted, $union, 'editable ∪ sensitive must cover ALL_CAPS exactly');

        // Deterministic, duplicate-free output.
        self::assertSame($editable, array_values(array_unique($editable)));
        $sorted = $editable;
        sort($sorted);
        self::assertSame($sorted, $editable, 'editable set must be sorted');
    }

    public function testSensitivePolicyExcludesEveryExplicitlyClassifiedSensitiveCapability(): void
    {
        foreach (self::ALL_SENSITIVE as $cap) {
            self::assertFalse(
                CustomRolePolicy::isEditableCapability($cap),
                "sensitive capability {$cap} must not be editable in V1"
            );
        }

        // The policy constant itself must cover the classified sensitive set.
        $declared = CustomRolePolicy::SENSITIVE_CAPS;
        sort($declared);
        $expected = self::ALL_SENSITIVE;
        sort($expected);
        self::assertSame($expected, $declared, 'SENSITIVE_CAPS must equal the classified sensitive set');
    }

    public function testNonCatalogueCapabilitiesAreRejected(): void
    {
        $forbidden = [
            '',
            ' ',
            'read',
            'manage_options',
            'edit_posts',
            'list_users',
            'activate_plugins',
            'manage_woocommerce',
            'woocommerce_manage_shop_orders',
            'woocommerce_refund',
            'cpms_manage',
            'cpms',
            'cpms_unknown_capability',
            'cpms_patient_delete',
            'CPMS_PATIENT_READ',
            'cpms patient read',
        ];

        foreach ($forbidden as $cap) {
            self::assertFalse(
                CustomRolePolicy::isEditableCapability($cap),
                'non-catalogue capability must not be editable: ' . var_export($cap, true)
            );
        }
    }

    public function testSanitizeCapabilitySetDropsEverythingNotEditable(): void
    {
        $input = [
            'cpms_patient_read',
            'cpms_export',
            'read',
            'manage_woocommerce',
            'cpms_unknown_capability',
            'cpms_private_note_read',
            'cpms_patient_read', // duplicate
            'cpms_appt_read',
            'cpms_config',
        ];

        $clean = CustomRolePolicy::sanitizeCapabilitySet($input);

        self::assertSame(['cpms_appt_read', 'cpms_patient_read'], $clean, 'only unique editable capabilities survive, sorted');
    }

    public function testSanitizeCapabilitySetIgnoresNonStringValuesAndArrayKeys(): void
    {
        $clean = CustomRolePolicy::sanitizeCapabilitySet([
            'cpms_patient_read',
            'cpms_fake_cap',
            null,
            42,
            true,
            ['cpms_export'],
            'read',
        ]);

        self::assertSame(['cpms_patient_read'], $clean);
    }

    public function testBuiltInRoleKeysAreExactlyTheRegisteredPresetKeys(): void
    {
        foreach (self::ALL_BUILT_IN_ROLE_KEYS as $key) {
            self::assertTrue(CustomRolePolicy::isBuiltInRoleKey($key), "built-in key {$key} must be recognized");
            self::assertFalse(CustomRolePolicy::isDefinableRoleKey($key), "built-in key {$key} must not be definable");
        }

        self::assertSame(
            self::ALL_BUILT_IN_ROLE_KEYS,
            [
                RolesAndCapabilities::ROLE_PATIENT,
                RolesAndCapabilities::ROLE_SECRETARY,
                RolesAndCapabilities::ROLE_DOCTOR,
                RolesAndCapabilities::ROLE_ACCOUNTANT,
                RolesAndCapabilities::ROLE_MANAGER,
            ],
            'built-in set must match the registered CPMS roles'
        );

        self::assertFalse(CustomRolePolicy::isBuiltInRoleKey('cx_frontdesk'));
    }

    public function testRoleKeyFormatIsStrictAndArrayKeyCoercionSafe(): void
    {
        $valid = ['cx_frontdesk', 'role_a1', 'a_b', 'my_custom_role', 'x9'];
        foreach ($valid as $key) {
            self::assertTrue(CustomRolePolicy::isWellFormedRoleKey($key), "key must be well-formed: {$key}");
            self::assertTrue(CustomRolePolicy::isDefinableRoleKey($key), "key must be definable: {$key}");
        }

        $invalid = [
            '',
            'a',
            '1',
            '123',
            '0123',
            '9lives',
            '_leading_underscore',
            '-dash',
            'space key',
            'UPPER',
            'MixedCase',
            'dot.key',
            'cpms doctor',
            "trailing\n",
            str_repeat('k', 65),
        ];
        foreach ($invalid as $key) {
            self::assertFalse(CustomRolePolicy::isWellFormedRoleKey($key), 'key must be rejected: ' . var_export($key, true));
            self::assertFalse(CustomRolePolicy::isDefinableRoleKey($key), 'key must not be definable: ' . var_export($key, true));
        }

        // PHP array-key coercion guard: numeric strings would become integer array
        // keys; the format must reject them so role keys are never coerced.
        foreach (['123', '0123', '7', '0'] as $numeric) {
            self::assertFalse(CustomRolePolicy::isWellFormedRoleKey($numeric));
            self::assertTrue(ctype_digit($numeric));
        }
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Auth\CustomRolePolicy;
use ClinicCore\Auth\RolesAndCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Custom Role & Permission Management — Slice 2 (pure policy contract):
 * Unicode-general display-name validation and the delegable-capability
 * intersection. No WordPress, no database.
 *
 * The point of this suite is that display names are NOT Persian-only and NOT
 * language-specific: every assertion uses a different script or a deliberately
 * hostile input.
 */
final class CustomRoleDisplayNamePolicyTest extends TestCase
{
    public function testAcceptsDisplayNamesFromSeveralScripts(): void
    {
        foreach ([
            'Room 3 Lead' => 'Latin',
            'پذیرش ارشد' => 'Persian',
            'ممرضة مساعدة' => 'Arabic',
            '受付主任' => 'CJK',
            'Старшая сестра' => 'Cyrillic',
            'אחות ראשית' => 'Hebrew',
            'Επιστάτης' => 'Greek',
            'Promotor Saúde' => 'Latin+Latin-1 supplement',
        ] as $name => $script) {
            self::assertTrue(
                CustomRolePolicy::is_acceptable_display_name($name),
                "a {$script} display name must be acceptable"
            );
        }
    }

    public function testKeepsZeroWidthNonJoinerRequiredByPersianText(): void
    {
        $withZwnj = "پزشک\u{200C}یار";

        self::assertTrue(CustomRolePolicy::is_acceptable_display_name($withZwnj));
        self::assertSame(
            $withZwnj,
            CustomRolePolicy::sanitize_display_name($withZwnj),
            'ZWNJ is orthographically required and must survive normalization'
        );
    }

    public function testRejectsEmptyWhitespaceOnlyAndControlOnlyNames(): void
    {
        foreach (['', '   ', "\t\n", "\u{200B}", '---', '!!!'] as $candidate) {
            self::assertFalse(
                CustomRolePolicy::is_acceptable_display_name($candidate),
                'a name without any letter or number must be rejected'
            );
        }
    }

    public function testStripsControlCharactersAndCollapsesWhitespace(): void
    {
        $hostile = "  Lead\u{0000}Role\n\t  Two   ";

        self::assertSame('LeadRole Two', CustomRolePolicy::sanitize_display_name($hostile));
        self::assertTrue(CustomRolePolicy::is_acceptable_display_name($hostile));
    }

    public function testRejectsInvalidUtf8FailClosed(): void
    {
        self::assertSame('', CustomRolePolicy::sanitize_display_name("Lead\xB1\x31Role"));
        self::assertFalse(CustomRolePolicy::is_acceptable_display_name("Lead\xB1\x31Role"));
    }

    public function testBoundsLengthInCodePointsNotBytes(): void
    {
        $max = CustomRolePolicy::DISPLAY_NAME_MAX_CODE_POINTS;

        $atLimit = str_repeat('پ', $max); // 2-byte code points: far above the limit in bytes
        self::assertTrue(CustomRolePolicy::is_acceptable_display_name($atLimit), 'the bound counts code points, not bytes');
        self::assertGreaterThan($max, strlen($atLimit));

        self::assertFalse(
            CustomRolePolicy::is_acceptable_display_name(str_repeat('a', $max + 1)),
            'one code point over the bound is rejected'
        );
    }

    public function testDelegableCapabilitiesNeverExceedEffectiveAuthority(): void
    {
        $delegable = CustomRolePolicy::delegable_capabilities(['cpms_patient_read', 'cpms_queue_read']);

        self::assertSame(['cpms_patient_read', 'cpms_queue_read'], $delegable);
    }

    public function testDelegableSetExcludesSensitiveAndUnknownCapabilities(): void
    {
        $everything = array_merge(
            RolesAndCapabilities::ALL_CAPS,
            ['manage_options', 'woocommerce_manage_orders', 'unknown_capability', '', 42]
        );

        $delegable = CustomRolePolicy::delegable_capabilities($everything);

        self::assertSame(CustomRolePolicy::editable_capabilities(), $delegable, 'the full effective set yields exactly the editable catalogue');
        foreach (CustomRolePolicy::SENSITIVE_CAPS as $sensitive) {
            self::assertNotContains($sensitive, $delegable, "sensitive capability {$sensitive} must never be delegable");
        }
        self::assertNotContains('manage_options', $delegable);
        self::assertNotContains('woocommerce_manage_orders', $delegable);
        self::assertNotContains('unknown_capability', $delegable);
    }

    public function testDelegableSetFailsClosedForNonStringInput(): void
    {
        self::assertSame([], CustomRolePolicy::delegable_capabilities([null, 7, 3.5, [], new \stdClass()]));
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Validators\VisibleText;
use PHPUnit\Framework\TestCase;

/**
 * VisibleText + ASCII control characters (U+0000–U+001F).
 *
 * Owner-reported failing case from PR #323's review:
 * PatientNameValidatorTest::testControlCharactersStrippedBeforeCheck with
 * input "\x00\x01" — expected false, got true. This class proves the fixed
 * behavior of VisibleText::has_visible_content() against that exact input.
 *
 * The helper decides visibility of the STRING ITSELF; it never rewrites or
 * corrupts the caller's value (it returns a bool only).
 */
final class VisibleTextControlCharsTest extends TestCase
{
    /**
     * The exact owner-reported input: NUL + SOH, i.e. pure ASCII controls.
     */
    public function testControlCharactersStrippedBeforeCheck(): void
    {
        self::assertFalse(VisibleText::has_visible_content("\x00\x01"));
    }

    public function testControlOnlyCombinedWithWhitespaceAndInvisibles(): void
    {
        // Each control class alone, and combined with whitespace/invisibles.
        self::assertFalse(VisibleText::has_visible_content("\x00"));
        self::assertFalse(VisibleText::has_visible_content("\x1F"));
        self::assertFalse(VisibleText::has_visible_content("\x00 \x01\t\x1F"));
        self::assertFalse(VisibleText::has_visible_content("\x00\u{200C} \x1F"));
    }

    public function testVisibleNamesStayVisibleEvenWithControlBytes(): void
    {
        // A control byte flanking a real name does not make it invisible.
        self::assertTrue(VisibleText::has_visible_content("\x00علی"));
        self::assertTrue(VisibleText::has_visible_content("علی\x01"));

        // Persian letters and an internal ZWNJ remain valid and unchanged.
        self::assertTrue(VisibleText::has_visible_content('می‌روم'));
        self::assertTrue(VisibleText::has_visible_content('علی‌رضا'));
        self::assertTrue(VisibleText::has_visible_content('Zahra Ahmadi'));
    }

    public function testInvalidUtf8StillFailsClosed(): void
    {
        self::assertFalse(VisibleText::has_visible_content("\xC3\x28"));
        self::assertFalse(VisibleText::has_visible_content("\xFF"));
    }

    public function testHelperNeverMutatesItsInput(): void
    {
        $input = "علی\x01رضا";
        VisibleText::has_visible_content($input);
        self::assertSame("علی\x01رضا", $input, 'the helper must pass by value');
    }
}

<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Validators\VisibleText;
use PHPUnit\Framework\TestCase;

/**
 * VisibleText + Unicode separator/format characters.
 *
 * Independent-review concern on PR #324: an invisible-only name such as one
 * built solely from U+2009 THIN SPACE still passed `has_visible_content()`
 * (the ASCII-control round did not cover it). The decision is now taken on
 * the standard Unicode categories: Z (all separators) and Cf (all format
 * characters) replace the hand-picked list, so every not-yet-born separator
 * or format character is covered as well.
 *
 * Scope stays bounded to separators/format characters: Hangul fillers
 * (U+115F/U+1160/U+3164/U+FFA0, category Lo) remain outside it by design.
 * The helper never rewrites its input (bool-only), keeps rejecting invalid
 * UTF-8 (fail-closed) and never treats a real letter as invisible.
 */
final class VisibleTextUnicodeSeparatorsTest extends TestCase
{
    /**
     * The exact review case: a "name" that is just one THIN SPACE.
     */
    public function testThinSpaceOnlyNameIsInvisible(): void
    {
        self::assertFalse(VisibleText::has_visible_content("\u{2009}"));
    }

    public function testEveryUnicodeSpaceSeparatorAloneIsInvisible(): void
    {
        foreach (['1680', '2000', '2001', '2002', '2003', '2004', '2005', '2006',
                  '2007', '2008', '2009', '200A', '202F', '205F', '3000', '00A0'] as $hex) {
            $only = html_entity_decode("&#x{$hex};", ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertFalse(
                VisibleText::has_visible_content($only),
                "U+{$hex} alone must not count as visible content"
            );
        }
    }

    public function testLineAndParagraphSeparatorsAloneAreInvisible(): void
    {
        self::assertFalse(VisibleText::has_visible_content("\u{2028}"));
        self::assertFalse(VisibleText::has_visible_content("\u{2029}"));
    }

    public function testFormatCharactersAloneAreInvisible(): void
    {
        foreach (['00AD', '061C', '180E', '202B', '202E', '2061', '2064', '2066',
                  '2069', '206E', 'E0001', 'E0052'] as $hex) {
            $only = html_entity_decode("&#x{$hex};", ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertFalse(
                VisibleText::has_visible_content($only),
                "U+{$hex} alone must not count as visible content"
            );
        }
    }

    public function testPreviouslyCoveredInvisiblesStillCountAsInvisible(): void
    {
        foreach (['200B', '200C', '200D', '200E', '200F', '2060', 'FEFF'] as $hex) {
            $only = html_entity_decode("&#x{$hex};", ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertFalse(
                VisibleText::has_visible_content($only),
                "regression anchor: U+{$hex} kept invisible after the rewrite"
            );
        }
    }

    public function testMixedInvisibleOnlyCombinationIsInvisible(): void
    {
        // Separator + format + ASCII whitespace/control — still no content.
        self::assertFalse(
            VisibleText::has_visible_content("\u{2009}\u{00AD}\u{2028}\u{200C} \u{3000}\x00")
        );
    }

    public function testValidNamesStayVisibleAcrossTheWidenedSet(): void
    {
        // Persian with internal ZWNJ; NBSP/thin-space flanked names; Latin.
        self::assertTrue(VisibleText::has_visible_content('می‌روم'));
        self::assertTrue(VisibleText::has_visible_content('علی رضایی'));
        self::assertTrue(VisibleText::has_visible_content("علی\u{00A0}رضایی"));
        self::assertTrue(VisibleText::has_visible_content("\u{2009}زهرا"));
        self::assertTrue(VisibleText::has_visible_content("Amir\u{2009}Reza"));
        self::assertTrue(VisibleText::has_visible_content('کاظمی'));
    }

    public function testHelperStillFailsClosedOnInvalidUtf8(): void
    {
        self::assertFalse(VisibleText::has_visible_content("\xC3\x28"));
    }
}

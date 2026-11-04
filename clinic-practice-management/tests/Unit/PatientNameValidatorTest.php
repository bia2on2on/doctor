<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Validators\PatientNameValidator;
use PHPUnit\Framework\TestCase;

/**
 * PatientNameValidator — helper مشترکِ «نام دارد محتوای دیدشدنی؟» (ITEM 3).
 *
 * خالص (بدون وردپرس/دیتابیس) → PHPUnit\Framework\TestCase خالص.
 */
final class PatientNameValidatorTest extends TestCase
{
    public function testVisiblePersianNameIsValid(): void
    {
        self::assertTrue(PatientNameValidator::hasVisibleContent('رضا'));
        self::assertTrue(PatientNameValidator::hasVisibleContent('مریم احمدی'));
        self::assertTrue(PatientNameValidator::hasVisibleContent('س‌ام')); // ZWNJ dentroِ کلمه — معتبر است
    }

    public function testInvisibleOnlyNameIsRejected(): void
    {
        self::assertFalse(PatientNameValidator::hasVisibleContent('')); // خالی
        self::assertFalse(PatientNameValidator::hasVisibleContent('   ')); // فقط فاصله
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{200B}")); // ZWSP
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{200C}")); // ZWNJ تنها
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{200D}")); // ZWJ
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{200E}")); // LRM
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{200F}")); // RLM
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{2060}")); // WJ
        self::assertFalse(PatientNameValidator::hasVisibleContent("\u{FEFF}")); // BOM
        self::assertFalse(PatientNameValidator::hasVisibleContent(" \u{200C}\t ")); // فاصله + ZWNJ
    }

    public function testMixedInvisibleAndVisibleIsValid(): void
    {
        self::assertTrue(PatientNameValidator::hasVisibleContent("\u{200B}رضا\u{200C}"));
        self::assertTrue(PatientNameValidator::hasVisibleContent("رضا \u{FEFF} احمدی"));
    }

    public function testControlCharactersStrippedBeforeCheck(): void
    {
        self::assertTrue(PatientNameValidator::hasVisibleContent("رضا\n"));
        self::assertFalse(PatientNameValidator::hasVisibleContent("\x00\x01"));
    }
}

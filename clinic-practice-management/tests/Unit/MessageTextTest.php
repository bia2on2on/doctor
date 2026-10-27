<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Messaging\MessageText;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * Iran V1 — pure message text boundary: XSS tags, control characters and
 * message-size limits are enforced without WordPress.
 */
final class MessageTextTest extends TestCase
{
    public function testPersianTextIsPreservedAndWhitespaceIsTrimmed(): void
    {
        self::assertSame('سلام، بیمار آماده است.', MessageText::sanitize("  سلام، بیمار آماده است.\n\n"));
    }

    public function testHtmlAndScriptTagsAreStripped(): void
    {
        $raw = '<script>alert(1)</script><b>پیام</b><img src=x onerror=alert(2)>';
        $clean = MessageText::sanitize($raw);
        self::assertStringNotContainsString('<', $clean);
        self::assertStringNotContainsString('>', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringContainsString('پیام', $clean);
    }

    public function testControlCharactersAreRemovedButNewlinesSurvive(): void
    {
        $clean = MessageText::sanitize("پیام\x00\x07قبلی\r\nپیام بعدی");
        self::assertSame("پیامقبلی\nپیام بعدی", $clean);
    }

    public function testEmptyAndNonStringAreRejected(): void
    {
        foreach (['', '   ', "\x00", null, 123, []] as $raw) {
            try {
                MessageText::sanitize($raw);
                self::fail('Expected an empty-message rejection');
            } catch (DomainException $exception) {
                self::assertSame(MessageText::EMPTY, $exception->getMessage());
            }
        }
    }

    public function testOversizedMessageIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(MessageText::TOO_LONG);
        MessageText::sanitize(str_repeat('الف', MessageText::MAX_LENGTH + 1));
    }

    public function testMaximumLengthIsAllowedAndPreviewIsSingleLine(): void
    {
        $body = str_repeat('ب', MessageText::MAX_LENGTH);
        self::assertSame($body, MessageText::sanitize($body));
        self::assertSame('پیام کوتاه', MessageText::preview("پیام\nکوتاه"));
    }
}

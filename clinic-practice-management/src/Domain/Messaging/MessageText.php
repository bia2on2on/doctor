<?php

declare(strict_types=1);

namespace ClinicCore\Domain\Messaging;

use DomainException;

/**
 * Plain-text boundary for internal staff messages — pure PHP, no WordPress.
 *
 * Defense in depth against stored XSS: tags and control characters are removed
 * on WRITE (the REST/UI boundary still renders with textContent/esc_html), and
 * message size is bounded so a single message cannot flood storage or polling.
 */
final class MessageText
{
    public const MAX_LENGTH = 2000;

    public const EMPTY = 'CLINIC_MESSAGE_EMPTY';
    public const TOO_LONG = 'CLINIC_MESSAGE_TOO_LONG';

    private function __construct()
    {
    }

    /**
     * @throws DomainException when the message is empty or exceeds the bound.
     */
    public static function sanitize(mixed $raw): string
    {
        if (!is_string($raw)) {
            throw new DomainException(self::EMPTY);
        }

        // Strip NUL and C0/C1 control characters except tab/newline, then all
        // HTML/PHP tags. Unicode (Persian) is preserved byte-for-byte.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $raw) ?? '';
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        if ($text === '') {
            throw new DomainException(self::EMPTY);
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            throw new DomainException(self::TOO_LONG);
        }

        return $text;
    }

    /**
     * Short single-line preview for the thread list (never HTML).
     */
    public static function preview(string $body, int $max = 90): string
    {
        $one_line = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_substr($one_line, 0, max(1, $max), 'UTF-8');
    }
}

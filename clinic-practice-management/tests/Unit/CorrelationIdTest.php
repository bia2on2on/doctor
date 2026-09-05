<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Infrastructure\Logging\CorrelationId;
use PHPUnit\Framework\TestCase;

/**
 * Correlation ID (M10) — Whitelist + Server-Generated + بدون نشت داده حساس.
 */
final class CorrelationIdTest extends TestCase
{
    public function testAcceptsValidClientHeader(): void
    {
        $this->assertSame('abc-1234.5678', CorrelationId::fromHeader('abc-1234.5678'));
        $this->assertSame(str_repeat('a', 64), CorrelationId::fromHeader(str_repeat('a', 64)));
        $this->assertSame('abcdefgh', CorrelationId::fromHeader('abcdefgh'));
    }

    public function testRejectsInvalidOrMissingHeaderAndGenerates(): void
    {
        $cases = [
            null,
            '',
            'short',                       // < 8
            str_repeat('a', 65),           // > 64
            "abc\nxyz12345",               // خط جدید → Log Injection
            'abc def12345',                // فاصله
            'café-12345678',               // غیر-ASCII (PHI/داده آزاد)
            'abc%d%e12345',                // کاراکتر غیرمجاز
        ];
        foreach ($cases as $i => $case) {
            $id = CorrelationId::fromHeader($case);
            $this->assertNotSame($case, $id, "case {$i} باید رد شود و ID جدید ساخته شود");
            $this->assertTrue(CorrelationId::isValid($id), "ID ساخته‌شده باید Whitelist را بگذراند (case {$i})");
            $this->assertStringStartsWith('cpms-', $id);
        }
    }

    public function testGeneratedIdsAreUniqueAndStableFormat(): void
    {
        $a = CorrelationId::generate();
        $b = CorrelationId::generate();
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^cpms-[0-9a-f]{16}$/', $a);
        $this->assertMatchesRegularExpression('/^cpms-[0-9a-f]{16}$/', $b);
    }
}

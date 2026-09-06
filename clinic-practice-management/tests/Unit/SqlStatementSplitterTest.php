<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Unit;

use ClinicCore\Domain\Backup\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

/**
 * F10 — جداسازی امن Statementهای SQL برای Restore (spec §25).
 */
final class SqlStatementSplitterTest extends TestCase
{
    public function testSimpleStatements(): void
    {
        $this->assertSame(
            ['SET NAMES utf8mb4', 'SELECT 1'],
            SqlStatementSplitter::split("SET NAMES utf8mb4;\nSELECT 1;")
        );
    }

    public function testSemicolonInsideSingleQuotedString(): void
    {
        $sql = "INSERT INTO t (v) VALUES ('a;b;c');SELECT 2;";
        $this->assertSame(
            ["INSERT INTO t (v) VALUES ('a;b;c')", 'SELECT 2'],
            SqlStatementSplitter::split($sql)
        );
    }

    public function testEscapedQuotesAndDoubledQuotes(): void
    {
        $sql = "INSERT INTO t VALUES ('it\\'s; ok', 'say \"hi\";', 'doubled ''quote'';');";
        $parts = SqlStatementSplitter::split($sql);
        $this->assertCount(1, $parts);
    }

    public function testLineCommentsDoNotSplit(): void
    {
        $sql = "CREATE TABLE x (id INT); -- comment with ; semicolon\nINSERT INTO x VALUES (1);";
        $parts = SqlStatementSplitter::split($sql);
        $this->assertCount(2, $parts);
        $this->assertSame('INSERT INTO x VALUES (1)', $parts[1]);
    }

    public function testBlockCommentIgnored(): void
    {
        $sql = "/* multi\nline ; comment */ SELECT 1;";
        $this->assertSame(['SELECT 1'], SqlStatementSplitter::split($sql));
    }

    public function testTrailingSqlWithoutSemicolon(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlStatementSplitter::split('SELECT 1;SELECT 2'));
    }

    public function testEmptyInputAndNoise(): void
    {
        $this->assertSame([], SqlStatementSplitter::split(''));
        $this->assertSame([], SqlStatementSplitter::split("  ;\n;  "));
    }
}
